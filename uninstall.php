<?php
/**
 * BCC Search – Uninstall handler.
 *
 * Runs when the plugin is deleted via the WordPress admin.
 * Cleans up options and transients.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up options.
// Must match SearchController::SEARCH_VERSION_KEY
delete_option('bcc_search_cache_version');
// Must match the option flag set in SearchRepository::ensureFulltextIndex()
delete_option('bcc_ft_index_v2_installed');

// Remove FULLTEXT indexes from wp_posts if they exist.
// Uses information_schema check instead of DROP INDEX IF EXISTS for MySQL 5.7 compat.
global $wpdb;

// v1 index (title only) — may still exist on sites that installed before the v2 upgrade.
$v1Exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'bcc_ft_post_title'",
    $wpdb->posts
));
if ($v1Exists) {
    $wpdb->query("ALTER TABLE {$wpdb->posts} DROP INDEX bcc_ft_post_title");
}

// v2 index (title + content) — current index created by SearchRepository::ensureFulltextIndex().
$v2Exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'bcc_ft_post_search'",
    $wpdb->posts
));
if ($v2Exists) {
    $wpdb->query("ALTER TABLE {$wpdb->posts} DROP INDEX bcc_ft_post_search");
}
