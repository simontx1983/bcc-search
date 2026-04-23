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
<div class="bcc-search" role="search" aria-label="<?php esc_attr_e('Search', 'bcc-search'); ?>" data-vertical="projects">

    <!-- Vertical tabs. Projects (default) + Users. The Users vertical
         is lazy-loaded: clicking the tab is what triggers the first
         /search/users call. Tab state lives in the widget's
         data-vertical attribute so CSS can scope visibility. -->
    <div class="bcc-search__tabs" role="tablist" aria-label="<?php esc_attr_e('Search vertical', 'bcc-search'); ?>">
        <button
            class="bcc-search__tab bcc-search__tab--active"
            type="button"
            role="tab"
            aria-selected="true"
            data-vertical="projects"
        ><?php esc_html_e('Projects', 'bcc-search'); ?></button>
        <button
            class="bcc-search__tab"
            type="button"
            role="tab"
            aria-selected="false"
            data-vertical="users"
        ><?php esc_html_e('Users', 'bcc-search'); ?></button>
        <button
            class="bcc-search__tab"
            type="button"
            role="tab"
            aria-selected="false"
            data-vertical="groups"
        ><?php esc_html_e('Groups', 'bcc-search'); ?></button>
    </div>

    <?php if ($show_type) : ?>
    <!-- Category chips: only apply to the Projects vertical. Hidden
         via CSS when the Users tab is active. -->
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
        <!-- Projects vertical: existing pane. Untouched rendering. -->
        <div class="bcc-search__pane bcc-search__pane--projects">
            <div class="bcc-search__results"></div>
            <p class="bcc-search__empty" hidden><?php esc_html_e('No projects found.', 'bcc-search'); ?></p>
        </div>
        <!-- Users vertical: separate pane, rendered by its own code
             path. No cross-contamination with the projects results. -->
        <div class="bcc-search__pane bcc-search__pane--users" hidden>
            <div class="bcc-search__user-results"></div>
            <p class="bcc-search__user-empty" hidden><?php esc_html_e('No users found.', 'bcc-search'); ?></p>
        </div>
        <!-- Groups vertical: sibling of Users — fully independent
             render path, independent AbortController, independent
             in-memory last-query cache. -->
        <div class="bcc-search__pane bcc-search__pane--groups" hidden>
            <div class="bcc-search__group-results"></div>
            <p class="bcc-search__group-empty" hidden><?php esc_html_e('No groups found.', 'bcc-search'); ?></p>
        </div>
    </div>

</div>
