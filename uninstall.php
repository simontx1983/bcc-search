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
delete_option('bcc_search_cache_version');

// Clean up transients.
delete_transient('bcc_search_categories');
