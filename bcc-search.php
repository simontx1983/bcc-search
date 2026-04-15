<?php
/**
 * Plugin Name: Blue Collar Crypto – Search
 * Description: Live search bar for PeepSo project pages, filterable by Validators, Builders, and NFT Creators.
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

// ── Cache invalidation (must run on every request, not just REST) ────────────
add_action('init', [\BCC\Search\Controllers\SearchController::class, 'register_cache_hooks']);

// ── REST route registration ─────────────────────────────────────────────────
add_action('rest_api_init', function () {
    (new \BCC\Search\Controllers\SearchController())->register_routes();
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

add_action('init', function () {
    register_block_type(BCC_SEARCH_PATH . 'blocks/search-bar');
});

/**
 * Shortcode: [bcc_search]
 *
 * Attributes:
 *   placeholder  — input placeholder text
 *   show_type    — 1 (default) to show the type dropdown, 0 to hide it
 */
add_shortcode('bcc_search', function ($atts) {
    $atts = shortcode_atts([
        'placeholder' => 'Search projects…',
        'show_type'   => '1',
    ], $atts, 'bcc_search');

    wp_enqueue_style(
        'bcc-search',
        BCC_SEARCH_URL . 'assets/css/bcc-search.css',
        [],
        BCC_SEARCH_VERSION
    );
    wp_enqueue_script(
        'bcc-search',
        BCC_SEARCH_URL . 'assets/js/bcc-search.js',
        [],
        BCC_SEARCH_VERSION,
        true
    );
    wp_localize_script('bcc-search', 'bccSearch', [
        'restUrl'   => esc_url_raw(rest_url('bcc/v1/search')),
        'tierCss'   => ['elite' => 'platinum', 'trusted' => 'gold', 'neutral' => 'silver', 'caution' => 'bronze', 'risky' => 'risky'],
        'tierLabel' => ['elite' => 'Elite', 'trusted' => 'Trusted', 'neutral' => 'Neutral', 'caution' => 'Caution', 'risky' => 'Risky'],
    ]);

    ob_start();
    include BCC_SEARCH_PATH . 'templates/search-bar.php';
    return ob_get_clean();
});
