<?php
/**
 * BCC Search Bar template (shortcode)
 *
 * Variables available:
 *   $atts['placeholder']  -- input placeholder text
 *   $atts['show_type']    -- '1' or '0'
 */
if (!defined('ABSPATH')) {
    exit;
}

$show_type   = ($atts['show_type'] ?? '1') !== '0';
$placeholder = $atts['placeholder'] ?? 'Search projects…';
$results_id  = 'bcc-search-results-' . uniqid();

include __DIR__ . '/search-bar-partial.php';
