<?php
/**
 * Env stub for SearchTermsRepositorySqlTest.
 *
 * Loaded ONLY from inside a #[RunTestsInSeparateProcesses] subprocess so
 * these fakes never leak into the main PHPUnit process. Defines the WP
 * option/dbDelta functions the repository calls UNQUALIFIED (so they bind
 * inside the `BCC\Search\Repositories` namespace) plus the day/hour
 * constants, and installs a query-recording $wpdb double so tests can
 * assert on the exact prepared SQL + bound args.
 */

declare(strict_types=1);

namespace {
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    /** Recording $wpdb double: captures prepare() templates + args and query() text. */
    final class SearchTermsFakeWpdb
    {
        public string $prefix     = 'wp_';
        public string $last_error = '';

        /** @var list<array{sql: string, args: list<mixed>}> */
        public array $prepared = [];
        /** @var list<string> */
        public array $queries = [];

        public function prepare(string $query, mixed ...$args): string
        {
            // Flatten a single array arg (WP allows both call shapes).
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            $this->prepared[] = ['sql' => $query, 'args' => array_values($args)];
            // Return the template with a marker so query() text still shows it.
            return $query;
        }

        public function query(string $query): int
        {
            $this->queries[] = $query;
            return 1;
        }

        /** @return list<array<string,mixed>> */
        public function get_results(string $query, mixed $output = null): array
        {
            $this->queries[] = $query;
            return [];
        }

        public function get_var(string $query): string
        {
            $this->queries[] = $query;
            return '';
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARSET=utf8mb4';
        }
    }
}

namespace BCC\Search\Repositories {
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return $GLOBALS['__bcc_terms_opts'][$name] ?? $default;
        }
        function update_option(string $name, mixed $value, mixed $autoload = null): bool
        {
            $GLOBALS['__bcc_terms_opts'][$name] = $value;
            return true;
        }
        function delete_option(string $name): bool
        {
            unset($GLOBALS['__bcc_terms_opts'][$name]);
            return true;
        }
        /** @return array<mixed> */
        function dbDelta(string $sql): array
        {
            $GLOBALS['__bcc_terms_dbdelta'][] = $sql;
            return [];
        }
    }
}
