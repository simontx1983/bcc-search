<?php
/**
 * Server-side render for the BCC Search Bar block.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block inner content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */
if (!defined('ABSPATH')) {
    exit;
}

$placeholder = $attributes['placeholder'] ?? 'Search projects…';
$show_type   = !empty($attributes['showType']);
$results_id  = 'bcc-search-results-' . wp_unique_id();

// Enqueue front-end assets.
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

$wrapper_attributes = get_block_wrapper_attributes();
?>
<div <?php echo $wrapper_attributes; ?>>
    <?php include BCC_SEARCH_PATH . 'templates/search-bar-partial.php'; ?>
</div>
