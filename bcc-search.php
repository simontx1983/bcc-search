<?php
/**
 * Plugin Name: Blue Collar Crypto – Search
 * Description: Live search bar for PeepSo Pages, filterable by Validators, Builders, and NFT Creators.
 * Version: 1.0.2
 * Author: Blue Collar Labs LLC
 * Text Domain: bcc-search
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * GitHub Plugin URI: https://github.com/simontx1983/bcc-search
 * Primary Branch: main
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

define('BCC_SEARCH_VERSION', '1.0.2');
define('BCC_SEARCH_PATH', plugin_dir_path(__FILE__));
define('BCC_SEARCH_URL', plugin_dir_url(__FILE__));

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

