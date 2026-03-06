<?php
/**
 * BCC Search Bar template
 *
 * Variables available:
 *   $atts['placeholder']  — input placeholder text
 *   $atts['show_type']    — '1' or '0'
 */
if (!defined('ABSPATH')) {
    exit;
}

$show_type   = ($atts['show_type'] ?? '1') !== '0';
$placeholder = esc_attr($atts['placeholder'] ?? 'Search projects…');
?>
<div class="bcc-search" role="search" aria-label="<?php esc_attr_e('Search projects', 'bcc-search'); ?>">

    <div class="bcc-search__bar">
        <?php if ($show_type) : ?>
        <div class="bcc-search__type-wrap">
            <select class="bcc-search__type" aria-label="<?php esc_attr_e('Filter by type', 'bcc-search'); ?>">
                <option value="">All Types</option>
                <option value="validators">Validators</option>
                <option value="builders">Builders</option>
                <option value="nft-creators">NFT Creators</option>
            </select>
            <span class="bcc-search__type-chevron" aria-hidden="true">
                <svg width="12" height="8" viewBox="0 0 12 8" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M1 1L6 6L11 1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </div>
        <div class="bcc-search__divider" aria-hidden="true"></div>
        <?php endif; ?>

        <div class="bcc-search__input-wrap">
            <span class="bcc-search__icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M10.5 10.5L14.5 14.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </span>
            <input
                type="search"
                class="bcc-search__input"
                placeholder="<?php echo $placeholder; ?>"
                autocomplete="off"
                spellcheck="false"
                aria-autocomplete="list"
                aria-expanded="false"
                aria-haspopup="listbox"
                aria-controls="bcc-search-results-<?php echo esc_attr(uniqid()); ?>"
            >
            <span class="bcc-search__spinner" aria-hidden="true"></span>
        </div>
    </div>

    <div class="bcc-search__dropdown" role="listbox" aria-label="<?php esc_attr_e('Search results', 'bcc-search'); ?>" hidden>
        <ul class="bcc-search__results"></ul>
        <p class="bcc-search__empty" hidden><?php esc_html_e('No projects found.', 'bcc-search'); ?></p>
    </div>

</div>
