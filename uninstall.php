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
delete_option('bcc_ft_index_installed');

// Remove the FULLTEXT index from wp_posts if it exists.
// Uses information_schema check instead of DROP INDEX IF EXISTS for MySQL 5.7 compat.
global $wpdb;
$exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'bcc_ft_post_title'",
    $wpdb->posts
));
if ($exists) {
    $wpdb->query("ALTER TABLE {$wpdb->posts} DROP INDEX bcc_ft_post_title");
}
