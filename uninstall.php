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
// Must match SearchTermsRepository::INSTALLED_OPTION
delete_option('bcc_search_terms_installed');
// F-53: generation counters — SearchController::SEARCH_LKG_GEN_KEY and
// GroupSearchController::GEN_KEY.
delete_option('bcc_search_lkg_generation');
delete_option('bcc_group_search_generation');
// F-53: the hourly 'no persistent object cache' warning latch, and the
// install backoffs (bcc-search #27).
delete_transient('bcc_search_nonpersistent_warned');
delete_transient('bcc_search_ft_index_backoff');
delete_transient('bcc_search_terms_install_backoff');

// Search-analytics: drop the aggregate table + retire both crons.
// (Autoloader is not loaded during uninstall, so this is inline rather than
// via SearchTermsRepository::dropTable().) F-53: the DROP was promised by
// this comment but missing, so the table and its raw terms survived uninstall.
wp_clear_scheduled_hook('bcc_search_terms_prune');
wp_clear_scheduled_hook('bcc_search_ensure_ft_index');

// Remove FULLTEXT indexes from wp_posts if they exist.
// Uses information_schema check instead of DROP INDEX IF EXISTS for MySQL 5.7 compat.
global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bcc_search_terms");

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
