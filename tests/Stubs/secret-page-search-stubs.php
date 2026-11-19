<?php
/**
 * Stubs for SearchRepositoryPrivacySqlTest ONLY.
 *
 * ⚠ Required from the test's setUp(), inside its separate process, never at
 * file level: a function declared at file level becomes THE function for the
 * whole unit run.
 *
 * Just enough WordPress for SearchRepository's three page-id reads to build
 * and send their SQL: no FULLTEXT index (title-prefix path), no PeepSo
 * category table, and a $wpdb that records every statement and finds nothing.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            return $default;
        }
    }
    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '')
        {
            return false;
        }
    }
    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            return true;
        }
    }

    final class BccSecretPageSearchFakeWpdb
    {
        public string $posts      = 'wp_posts';
        public string $postmeta   = 'wp_postmeta';
        public string $prefix     = 'wp_';
        public string $last_error = '';

        /** @var list<string> every statement sent, arguments filled in */
        public array $sent = [];

        /** @param mixed ...$args */
        public function prepare(string $query, mixed ...$args): string
        {
            $query = str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], $query);
            return vsprintf($query, $args);
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function get_var(string $query): ?string
        {
            $this->sent[] = $query;
            return null;
        }

        /** @return list<string> */
        public function get_col(string $query): array
        {
            $this->sent[] = $query;
            return [];
        }

        /** @return list<array<string, mixed>> */
        public function get_results(string $query, string $output = 'OBJECT'): array
        {
            $this->sent[] = $query;
            return [];
        }
    }
}
