<?php
/**
 * BCC Search Bar shared markup partial.
 *
 * Included by both the shortcode template (search-bar.php) and the
 * Gutenberg block render (blocks/search-bar/render.php).
 *
 * Expected variables in scope:
 *   $show_type   (bool)   — whether to render the type filter chips
 *   $placeholder (string) — input placeholder text
 *   $results_id  (string) — unique ID for the dropdown (ARIA linkage)
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="bcc-search" role="search" aria-label="<?php esc_attr_e('Search projects', 'bcc-search'); ?>">

    <?php if ($show_type) : ?>
    <div class="bcc-search__chips" role="radiogroup" aria-label="<?php esc_attr_e('Filter by type', 'bcc-search'); ?>">
        <button class="bcc-search__chip bcc-search__chip--active" type="button" data-type=""><?php esc_html_e('All Types', 'bcc-search'); ?></button>
    </div>
    <?php endif; ?>

    <div class="bcc-search__bar">
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
                placeholder="<?php echo esc_attr($placeholder); ?>"
                autocomplete="off"
                spellcheck="false"
                aria-autocomplete="list"
                aria-expanded="false"
                aria-haspopup="listbox"
                aria-controls="<?php echo esc_attr($results_id); ?>"
            >
            <button class="bcc-search__clear" type="button" aria-label="<?php esc_attr_e('Clear search', 'bcc-search'); ?>" hidden>
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 3L11 11M11 3L3 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </button>
            <span class="bcc-search__spinner" aria-hidden="true"></span>
        </div>
    </div>

    <div class="bcc-search__dropdown" id="<?php echo esc_attr($results_id); ?>" role="listbox" aria-label="<?php esc_attr_e('Search results', 'bcc-search'); ?>" hidden>
        <div class="bcc-search__results"></div>
        <p class="bcc-search__empty" hidden><?php esc_html_e('No projects found.', 'bcc-search'); ?></p>
    </div>

</div>
