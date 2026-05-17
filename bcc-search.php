<?php
/**
 * Plugin Name: Blue Collar Crypto – Search
 * Description: Live search bar for PeepSo Pages, filterable by Validators, Builders, and NFT Creators.
 * Version: 1.0.0
 * Author: Blue Collar Labs LLC
 * Text Domain: bcc-search
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: bcc-core
 *
 * Architectural seam (see docs/pattern-registry.md → "Search"):
 *
 *   This plugin is the CANONICAL search engine — owns ranking,
 *   throttling, caching (incl. LKG + version bump), the query-quality
 *   gate, and the circuit breaker. Other plugins / the headless
 *   frontend MUST NOT call this plugin's routes directly.
 *
 *   The headless frontend talks to `GET /bcc/v1/cards/search`
 *   (`BCC\Trust\Core\REST\CardsSearchEndpoint`), which is a thin §A2
 *   view-model adapter that calls back into this plugin via
 *   `rest_do_request('/bcc/v1/search')`. That wrapper translates
 *   `category_slug` → `card_kind`, `reputation_tier` → `card_tier`,
 *   and the WordPress permalink → the headless route prefix
 *   (`/v/`, `/p/`, `/c/`) so the frontend never sees ineligible
 *   identifiers.
 *
 *   Routes exposed by this plugin:
 *     GET /bcc/v1/search          — pages search (consumed via wrapper)
 *     GET /bcc/v1/search/users    — DORMANT (Phase 2 multi-vertical)
 *     GET /bcc/v1/search/groups   — DORMANT (Phase 2 multi-vertical)
 *
 *   When the multi-vertical UX lands, fan out INTERNALLY from
 *   `/bcc/v1/search` to the user / group services — keep the
 *   frontend on one contract. Do not have the frontend call the
 *   three endpoints in parallel.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BCC_SEARCH_VERSION', '1.0.0');
define('BCC_SEARCH_PATH', plugin_dir_path(__FILE__));
define('BCC_SEARCH_URL', plugin_dir_url(__FILE__));
define('BCC_SEARCH_RESULTS_SLUG', 'search');

// ── Dependency check — bcc-core must be active ──────────────────────────────
if ( ! defined( 'BCC_CORE_VERSION' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>BCC Search:</strong> '
           . 'The <strong>BCC Core</strong> plugin must be activated first. '
           . 'Please activate BCC Core, then re-activate BCC Search.'
           . '</p></div>';
    } );
    return;
}

// ── Activation hook: create FULLTEXT index during activation, not mid-request
register_activation_hook( __FILE__, function () {
    // Defer to after autoloader is available.
    $autoloader = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
    if ( file_exists( $autoloader ) ) {
        require_once $autoloader;
        \BCC\Search\Repositories\SearchRepository::ensureFulltextIndex();
    }
} );

// ── PSR-4 autoloader ────────────────────────────────────────────────────────
$bcc_search_autoloader = BCC_SEARCH_PATH . 'vendor/autoload.php';
if ( ! file_exists( $bcc_search_autoloader ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>BCC Search:</strong> '
           . 'Run <code>composer install</code> in the plugin directory to generate the autoloader.'
           . '</p></div>';
    } );
    return;
}
require_once $bcc_search_autoloader;

// ── Runtime FT-index self-healing (activation-hook skip recovery) ────────────
//
// Install paths that bypass register_activation_hook (wp-cli bulk activate,
// multisite network-activate edge cases, file-copy deployments) leave the
// FT index uninstalled. Without the FT index, searches fall through to the
// title-prefix LIKE path — correct but strictly less useful than FT scoring
// on post_content. Schedule an HOURLY retry (not daily) so the degraded
// window after a bypassed activation is short. Also run a one-shot attempt
// on admin_init for the first admin page-load after install.
add_action('init', function (): void {
    if (get_option('bcc_ft_index_v2_installed')) {
        return;
    }
    if (!wp_next_scheduled('bcc_search_ensure_ft_index')) {
        wp_schedule_event(time() + 60, 'hourly', 'bcc_search_ensure_ft_index');
    }
});
add_action('admin_init', function (): void {
    if (get_option('bcc_ft_index_v2_installed')) {
        return;
    }
    \BCC\Search\Repositories\SearchRepository::ensureFulltextIndex();
});
add_action('bcc_search_ensure_ft_index', function (): void {
    if (get_option('bcc_ft_index_v2_installed')) {
        // Already installed — deschedule this self-heal cron.
        $ts = wp_next_scheduled('bcc_search_ensure_ft_index');
        if ($ts) {
            wp_unschedule_event($ts, 'bcc_search_ensure_ft_index');
        }
        return;
    }
    \BCC\Search\Repositories\SearchRepository::ensureFulltextIndex();
});

// ── Cache invalidation (must run on every request, not just REST) ────────────
add_action('init', [\BCC\Search\Controllers\SearchController::class, 'register_cache_hooks']);

// ── System-health filter contribution ────────────────────────────────────────
// Phase 3 of the post-stabilization observability initiative (2026-05-09).
// Contributes a `search` block to the unified bcc_system_health envelope
// surfacing FT-index install state + the persistent-cache prerequisite.
// Breaker state and LKG hit counts are surfaced via the cross-plugin
// `degradation_metrics.search_lkg` block (bcc-core's filter); this block
// covers the install-time / boot-time signals that aren't event-counted.
add_filter('bcc_system_health', function (array $health): array {
    $health['search'] = [
        // FT index installed → full-text scoring on post_content. False
        // means searches fall through to the title-prefix LIKE path
        // (correct but strictly less useful). Self-heal cron retries
        // hourly until install succeeds.
        'ft_index_installed' => (bool) get_option('bcc_ft_index_v2_installed'),
        // Persistent object cache is a hard prerequisite for the
        // breaker, concurrent-rebuild slot gauge, and LKG fallback.
        // Without it, scale-out search behaviour degrades to per-request
        // memory only.
        'persistent_cache'   => function_exists('wp_using_ext_object_cache')
            ? wp_using_ext_object_cache()
            : false,
    ];
    return $health;
});

// ── Asset enqueue ────────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', function () {
    $ver = BCC_SEARCH_VERSION;

    wp_enqueue_style(
        'bcc-search',
        BCC_SEARCH_URL . 'assets/css/bcc-search.css',
        [],
        $ver
    );

    // Search bar script — on every page (the bar is in the header)
    wp_enqueue_script(
        'bcc-search-bar',
        BCC_SEARCH_URL . 'assets/js/bcc-search-bar.js',
        [],
        $ver,
        true
    );

    // Results page script — only on the designated search results page
    if (is_page(BCC_SEARCH_RESULTS_SLUG) || get_query_var('pagename') === BCC_SEARCH_RESULTS_SLUG) {
        wp_enqueue_script(
            'bcc-search-results',
            BCC_SEARCH_URL . 'assets/js/bcc-search-results.js',
            [],
            $ver,
            true
        );
    }

    // Shared JS config object
    wp_localize_script('bcc-search-bar', 'bccSearchBar', [
        'restUrl'    => esc_url_raw(rest_url('bcc/v1')),
        'resultsUrl' => esc_url_raw(home_url('/' . BCC_SEARCH_RESULTS_SLUG . '/')),
        'nonce'      => wp_create_nonce('wp_rest'),
    ]);
});

// ── REST route registration ─────────────────────────────────────────────────
add_action('rest_api_init', function () {
    (new \BCC\Search\Controllers\SearchController())->register_routes();
    // Users vertical — isolated controller, cache group, and throttle
    // bucket. No shared state with the projects controller so adding it
    // here cannot degrade the existing endpoint.
    (new \BCC\Search\Controllers\UserSearchController())->register_routes();
    // Groups vertical — same isolation pattern as Users.
    (new \BCC\Search\Controllers\GroupSearchController())->register_routes();
});

// ── Gutenberg block registration ────────────────────────────────────────────
add_filter('block_categories_all', function (array $categories): array {
    // Avoid duplicate registration if another BCC Search block adds the category.
    foreach ($categories as $cat) {
        if (($cat['slug'] ?? '') === 'bcc-search') {
            return $categories;
        }
    }
    $categories[] = [
        'slug'  => 'bcc-search',
        'title' => __('BCC Search', 'bcc-search'),
        'icon'  => null,
    ];
    return $categories;
}, 10, 1);

// ── Shortcode: [bcc_search] ──────────────────────────────────────────────────
// Renders the search bar widget (the header/inline bar)
add_shortcode('bcc_search', function (array $atts): string {
    $atts = shortcode_atts([
        'placeholder' => __('Search projects, people…', 'bcc-search'),
        'show_type'   => '1',
    ], $atts, 'bcc_search');

    ob_start(); ?>
    <div class="bcc-search-wrap" data-bcc-bar role="search" aria-label="<?php esc_attr_e('Site search', 'bcc-search'); ?>">

      <input
        type="search"
        class="bcc-search-input"
        placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
        autocomplete="off"
        aria-label="<?php esc_attr_e('Search', 'bcc-search'); ?>"
        aria-autocomplete="list"
        aria-expanded="false"
      >

      <!-- Two icon buttons: [spinner↔clear] [search↔go] -->
      <div class="bcc-search-actions" aria-hidden="true">
        <!-- Left: spinner while loading, flips to clear when done (hidden at rest) -->
        <button type="button" class="bcc-icon-btn bcc-btn-loader bcc-hidden"
                title="<?php esc_attr_e('Clear search', 'bcc-search'); ?>"
                aria-label="<?php esc_attr_e('Clear search', 'bcc-search'); ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
        </button>
        <!-- Right: search icon at rest, flips to go arrow while typing -->
        <button type="button" class="bcc-icon-btn bcc-btn-search bcc-icon-btn-main"
                title="<?php esc_attr_e('Search', 'bcc-search'); ?>"
                aria-label="<?php esc_attr_e('Search', 'bcc-search'); ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
        </button>
      </div>

      <!-- Dropdown -->
      <div class="bcc-dropdown" role="listbox" aria-label="<?php esc_attr_e('Search results', 'bcc-search'); ?>">
        <?php if ($atts['show_type'] !== '0'): ?>
        <!-- Filter tabs — hidden until JS opens dropdown -->
        <div class="bcc-filter-tabs" role="tablist" aria-label="<?php esc_attr_e('Filter results', 'bcc-search'); ?>"></div>
        <?php endif; ?>
        <div class="bcc-results-list" role="group"></div>
        <div class="bcc-dropdown-footer bcc-hidden">
          <a href="#" class="bcc-view-all-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <line x1="5" y1="12" x2="19" y2="12"/>
              <polyline points="12 5 19 12 12 19"/>
            </svg>
            <?php esc_html_e('View all results', 'bcc-search'); ?>
          </a>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
});

// ── Shortcode: [bcc_search_results] ─────────────────────────────────────────
// Place this on your /search/ page. Renders the full results page container.
add_shortcode('bcc_search_results', function (): string {
    ob_start(); ?>
    <div class="bcc-results-page" data-bcc-results>
      <div class="bcc-rp-header">
        <div class="bcc-rp-query-label"></div>
        <nav class="bcc-rp-tabs" role="tablist" aria-label="<?php esc_attr_e('Result type', 'bcc-search'); ?>"></nav>
      </div>
      <div class="bcc-rp-panels"></div>
    </div>
    <?php
    return ob_get_clean();
});

// ── Gutenberg block: Search Results ─────────────────────────────────────────
add_action('init', function () {
    // Only register if block editor is available
    if (!function_exists('register_block_type')) {
        return;
    }
    register_block_type('bcc-search/results', [
        'editor_script'   => 'bcc-search-results-block-editor',
        'render_callback' => function (): string {
            return do_shortcode('[bcc_search_results]');
        },
        'attributes'      => [],
    ]);
    wp_register_script(
        'bcc-search-results-block-editor',
        BCC_SEARCH_URL . 'assets/js/bcc-results-block-editor.js',
        ['wp-blocks', 'wp-element', 'wp-block-editor'],
        BCC_SEARCH_VERSION,
        true
    );
});