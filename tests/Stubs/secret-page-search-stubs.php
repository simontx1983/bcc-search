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
            // Seedable via $GLOBALS['__bcc_options'], defaulting to the
            // caller's default when unseeded, so existing tests are
            // unaffected. Needed because the FULLTEXT branch is gated on
            // get_option('bcc_ft_index_v2_installed') -- with a stub that
            // always returned the default, that branch was UNREACHABLE and
            // any test claiming to exercise it proved nothing.
            return $GLOBALS['__bcc_options'][$key] ?? $default;
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

        /** @var mixed rows of the last query, as OBJECTS like the real wpdb */
        public $last_result = [];

        /**
         * When true, a query containing MATCH( bails the way wpdb::query()
         * does ABOVE flush(): returns false, leaves `last_error` EMPTY and
         * `last_result` UNTOUCHED — so the previous query's rows are still
         * sitting there.
         *
         * ⚠ Scoped to the FULLTEXT query on purpose. Bailing every read
         * would make the title-prefix fallback throw too, and then a test
         * could not tell "the stale rows were discarded" from "something
         * threw before we looked".
         */
        public bool $bailOnMatch = false;

        /**
         * Rows a successful query answers with, as OBJECTS. Default empty,
         * so every existing test keeps its "no rows" behaviour; a test that
         * needs rows seeds this instead of writing last_result directly,
         * which query() would clear.
         *
         * @var list<object>
         */
        public array $resultRows = [];

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

        /**
         * FAITHFUL, AND THE FIDELITY IS LOAD-BEARING. The real
         * `get_results()` calls `query()` and DISCARDS its return value,
         * then reads `last_result`. An earlier version of this double
         * returned `[]` unconditionally and touched neither -- which made
         * it blind to the exact defect the seam exists to remove: a mutant
         * reverting a seam call back to `get_results()` SURVIVED, because
         * the double answered `[]` either way.
         *
         * @return list<array<string, mixed>>
         */
        public function get_results(string $query, string $output = 'OBJECT'): array
        {
            $this->query($query); // return deliberately ignored, as wpdb does

            $rows = is_array($this->last_result) ? $this->last_result : [];
            if ($output === 'OBJECT') {
                return array_values($rows);
            }

            return array_map(
                static fn (object $row): array => array_map('strval', get_object_vars($row)),
                array_values($rows)
            );
        }

        /**
         * The real entry point, which ProbesReads calls explicitly (F-472).
         *
         * ⚠ Still RECORDS the statement, because that is what these tests
         * assert on: the privacy clause is checked by reading `sent`, and a
         * read that stopped recording would make those assertions pass over
         * nothing.
         *
         * Returns 0 rows, matching get_results() above.
         *
         * @return int|false
         */
        public function query(string $query)
        {
            $this->sent[] = $query;

            if ($this->bailOnMatch && str_contains($query, 'MATCH(')) {
                // last_result DELIBERATELY untouched, last_error EMPTY.
                $this->last_error = '';
                return false;
            }

            $this->last_error  = '';
            $this->last_result = $this->resultRows;

            return count($this->resultRows);
        }
    }
}

namespace BCC\Core\Observability {
    /**
     * Recording double for bcc-core's DegradationMetrics.
     *
     * The FULLTEXT fall-through records `search_ft_index /
     * title_only_fallback` when the index is absent, which this harness's
     * index check reports. Without this, exercising the fall-through dies
     * with "Class not found" — a harness gap, not a defect in the code.
     *
     * ⚠⚠ `class_exists($x, false)` — THE SECOND ARGUMENT IS
     * LOAD-BEARING. A bare `class_exists()` asks the autoloader, which
     * would load the REAL class and make the guard always true, so this
     * double would never be defined and the gap would return disguised.
     *
     * Only this suite loads this file, so the double cannot leak.
     */
    if (!\class_exists('BCC\Core\Observability\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            /** @var list<array{0: string, 1: string}> */
            public static array $recorded = [];

            public static function record(string $subsystem, string $event): void
            {
                self::$recorded[] = [$subsystem, $event];
            }
        }
    }
}
