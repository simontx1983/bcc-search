<?php
/**
 * Plugin Name: Blue Collar Crypto – Search
 * Description: Live search bar for PeepSo project pages, filterable by Validators, Builders, and NFT Creators.
 * Version: 1.0.0
 * Author: Blue Collar Labs LLC
 * Text Domain: bcc-search
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BCC_SEARCH_VERSION', '1.0.0');
define('BCC_SEARCH_PATH', plugin_dir_path(__FILE__));
define('BCC_SEARCH_URL', plugin_dir_url(__FILE__));

require_once BCC_SEARCH_PATH . 'includes/class-bcc-search-api.php';

add_action('rest_api_init', function () {
    $api = new BCC_Search_API();
    $api->register_routes();
});

add_action('wp_enqueue_scripts', function () {
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
        'restUrl' => esc_url_raw(rest_url('bcc/v1/search')),
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);
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

    ob_start();
    include BCC_SEARCH_PATH . 'templates/search-bar.php';
    return ob_get_clean();
});
