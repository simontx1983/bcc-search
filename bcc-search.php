<?php
/**
 * Plugin Name: Blue Collar Crypto – Search
 * Description: Live search bar for PeepSo Pages, filterable by Validators, Builders, and NFT Creators.
 * Version: 1.0.3
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

define('BCC_SEARCH_VERSION', '1.0.3');
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

// ── Cron-page filter contribution ───────────────────────────────────────────
// Operator OS v1 Phase 2: contribute the bcc-search canonical hook to the
// CronPage drift-detector. Conditional: bcc_search_ensure_ft_index self-
// deschedules once bcc_ft_index_v2_installed is set, so it should only
// appear as "expected" while the install is pending. Once installed,
// drift is the EXPECTED state.
add_filter('bcc_expected_cron_hooks', function (array $hooks): array {
    if (!get_option('bcc_ft_index_v2_installed')) {
        $hooks['bcc_search_ensure_ft_index'] = [
            'interval'    => 'hourly',
            'source'      => 'bcc-search',
            'description' => 'FT-index install self-heal (auto-retires once installed)',
        ];
    }
    return $hooks;
});

// ── Developer panel contribution (Operator OS v1 Phase 3) ───────────────────
// Surface FT-index install state on the Developer page plus a manual
// "rebuild" trigger that calls SearchRepository::ensureFulltextIndex().
// Idempotent — the repo method early-returns if the index is already
// installed (or re-creates it if a destructive uninstall ran).
add_filter('bcc_developer_panels', function (array $panels): array {
    $panels['bcc-search:index'] = [
        'title' => 'Search Index (bcc-search)',
        'sort'  => 20,
        'render' => function (): void {
            $installed = (bool) get_option('bcc_ft_index_v2_installed');
            $color     = $installed ? '#46b450' : '#dba617';
            $label     = $installed ? 'installed' : 'not installed';

            echo '<table class="widefat striped" style="max-width:760px;"><tbody>';
            printf(
                '<tr><th style="width:280px;">FT index v2</th><td><span style="display:inline-block;padding:2px 10px;background:%1$s;color:#fff;border-radius:3px;font-weight:bold;font-size:12px;">%2$s</span></td></tr>',
                esc_attr($color),
                esc_html($label)
            );
            printf(
                '<tr><th>Option flag (<code>bcc_ft_index_v2_installed</code>)</th><td><code>%s</code></td></tr>',
                esc_html((string) get_option('bcc_ft_index_v2_installed', '(unset)'))
            );
            echo '</tbody></table>';

            if (!$installed) {
                echo '<p style="color:#666;margin-top:8px;">'
                    . 'The <code>bcc_search_ensure_ft_index</code> hourly self-heal cron will keep retrying until the option flag is set. '
                    . 'Press Rebuild to force an immediate attempt.</p>';
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
            echo '<input type="hidden" name="action" value="bcc_dev_rebuild_ft_index">';
            wp_nonce_field('bcc_dev_rebuild_ft_index');
            echo '<button type="submit" class="button" '
                . 'onclick="return confirm(\'Run SearchRepository::ensureFulltextIndex() now? Idempotent — already-installed indexes are a no-op.\');">'
                . 'Rebuild FT index</button>';
            echo '</form>';
        },
    ];
    return $panels;
});

add_action('admin_post_bcc_dev_rebuild_ft_index', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized.'));
    }
    check_admin_referer('bcc_dev_rebuild_ft_index');

    $args = ['page' => 'bcc-developer'];
    try {
        \BCC\Search\Repositories\SearchRepository::ensureFulltextIndex();
        $args['rebuilt'] = '1';
        \BCC\Core\Log\Logger::info('[bcc-search] FT index rebuild triggered from Developer page', [
            'operator' => get_current_user_id(),
        ]);
    } catch (\Throwable $e) {
        $args['rebuild_failed'] = $e->getMessage();
        \BCC\Core\Log\Logger::warning('[bcc-search] FT index rebuild threw', [
            'operator' => get_current_user_id(),
            'error'    => $e->getMessage(),
        ]);
    }

    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
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

