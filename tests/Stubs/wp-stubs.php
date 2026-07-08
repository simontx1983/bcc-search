<?php
/**
 * Global WordPress function shims for pure-unit tests.
 *
 * bcc-search's services and repositories call a handful of WP functions
 * unqualified from inside their namespaces. In a pure-unit context there is
 * no WordPress loaded, so we provide minimal deterministic stand-ins. Each
 * is function_exists()-guarded so that if the suite ever runs under a real
 * WP bootstrap, the genuine function wins.
 *
 * Behaviour is intentionally identity / predictable so tests can assert on
 * exact output shapes without pulling in WP's escaping/formatting.
 */

declare(strict_types=1);

// WordPress output-format constant passed to $wpdb->get_results(). WP defines
// it as the literal string 'ARRAY_A'; repositories reference it unqualified.
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('esc_url_raw')) {
    /**
     * Real esc_url_raw sanitises a URL for storage. Tests only need the
     * value to pass through unchanged so they can assert on the source URL.
     */
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (!function_exists('get_avatar_url')) {
    /**
     * @param int|string          $id_or_email
     * @param array<string,mixed> $args
     */
    function get_avatar_url($id_or_email, array $args = []): string
    {
        return 'https://avatars.test/' . (string) $id_or_email;
    }
}

if (!function_exists('get_author_posts_url')) {
    function get_author_posts_url(int $author_id, string $author_nicename = ''): string
    {
        // Mirror WP: the author archive is keyed on the nicename when present.
        $slug = $author_nicename !== '' ? $author_nicename : (string) $author_id;
        return 'https://site.test/author/' . $slug;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text, bool $remove_breaks = false): string
    {
        $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
        $text = strip_tags($text);
        if ($remove_breaks) {
            $text = (string) preg_replace('/[\r\n\t ]+/', ' ', $text);
        }
        return trim($text);
    }
}
