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
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BCC_SEARCH_VERSION', '1.0.0');
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

