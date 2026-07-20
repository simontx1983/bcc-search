<?php
/**
 * SearchController test environment stubs.
 *
 * Loaded ONLY from inside a #[RunTestsInSeparateProcesses] subprocess
 * (see SearchControllerHandleSearchTest) so the main PHPUnit process
 * never sees these fake definitions.
 *
 * Every collaborator SearchController::handle_search() touches on its
 * pure-logic paths is defined here at its exact production FQN with a
 * class_exists() guard, so when the real controller is required below
 * its `use` statements bind to these fakes (the autoloader never loads
 * the real bcc-core classes — they live outside this plugin's PSR-4
 * prefix — and never loads the real SearchRepository because the fake
 * is already defined). QueryQualityGate is deliberately NOT stubbed:
 * the junk-gate tests exercise the real shared gate.
 *
 * Deliberately fixed environment shape:
 *   - wp_using_ext_object_cache() === false → the circuit breaker,
 *     rebuild-slot gauge and cache-version damping all short-circuit,
 *     which is exactly the regime these tests target (breaker/locks/LKG
 *     serving need a real object cache and are out of scope).
 *   - AdvisoryLock::acquire() defaults to true → every request is a
 *     rebuild "winner"; loser paths are untested here by design.
 *   - The non-persistent-cache PRODUCTION RISK warning transient is
 *     pre-seeded so warnIfNonPersistentCacheAtRebuild() stays silent
 *     (its error_log fallback would pollute test output).
 */

declare(strict_types=1);

namespace BCC\Search\Tests\Stubs {

    /**
     * Backing store for the wp_cache_* / option / transient shims below.
     * Tests inspect (and deliberately poison) entries through this class.
     */
    final class TestCacheStore
    {
        /** @var array<string, array<string, mixed>> group => key => value */
        public static array $cache = [];

        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var array<string, mixed> */
        public static array $transients = [];

        public static function reset(): void
        {
            self::$cache   = [];
            self::$options = [];
            // Keep the "no persistent object cache" hourly warning
            // permanently suppressed — otherwise every rebuild-path test
            // would emit an error_log() line.
            self::$transients = ['bcc_search_nonpersistent_warned' => '1'];
        }

        /** @return array<string, mixed> */
        public static function groupEntries(string $group): array
        {
            return self::$cache[$group] ?? [];
        }
    }

    /**
     * Fake trust-engine score read service, returned by the fake
     * ServiceLocator. Records the exact page-ID batch it was asked to
     * enrich so tests can pin the PRERANK_TOP_K cutoff.
     */
    final class FakeScoreReadService
    {
        /** @var array<int, array<string, mixed>> page_id => enriched score row */
        public static array $scoresById = [];

        /** @var list<int>|null IDs from the most recent enrichment call */
        public static ?array $lastRequestedIds = null;

        public static bool $throw = false;

        /**
         * @param int[] $pageIds
         * @return array<int, array<string, mixed>>
         */
        public function getEnrichedScoresForPageIds(array $pageIds): array
        {
            self::$lastRequestedIds = array_values($pageIds);
            if (self::$throw) {
                throw new \RuntimeException('stub: trust engine failure');
            }
            return array_intersect_key(self::$scoresById, array_flip($pageIds));
        }

        public static function reset(): void
        {
            self::$scoresById       = [];
            self::$lastRequestedIds = null;
            self::$throw            = false;
        }
    }

    TestCacheStore::reset();
}

namespace BCC\Core\Security {

    if (!class_exists(__NAMESPACE__ . '\\Throttle', false)) {
        final class Throttle
        {
            public static bool $allow = true;

            public static function allow(string $action, int $limit, int $window): bool
            {
                return self::$allow;
            }
        }
    }
}

namespace BCC\Core {

    if (!class_exists(__NAMESPACE__ . '\\ServiceLocator', false)) {
        final class ServiceLocator
        {
            public static function resolveScoreReadService(): \BCC\Search\Tests\Stubs\FakeScoreReadService
            {
                return new \BCC\Search\Tests\Stubs\FakeScoreReadService();
            }
        }
    }
}

namespace BCC\Core\DB {

    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static bool $acquire = true;

            public static function acquire(string $key, int $timeout = 0): bool
            {
                return self::$acquire;
            }

            public static function release(string $key): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Core\Observability {

    if (!class_exists(__NAMESPACE__ . '\\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            /** @var list<array{string, string}> */
            public static array $recorded = [];

            public static function record(string $subsystem, string $event): void
            {
                self::$recorded[] = [$subsystem, $event];
            }
        }
    }
}

namespace BCC\Search\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\SearchRepository', false)) {
        final class SearchRepository
        {
            /** @var array<array{slug: string, name: string}> */
            public static array $categories = [];

            public static bool $categoriesThrow = false;

            /** @var list<\BCC\Search\DTO\PageCandidateDTO> */
            public static array $candidates = [];

            public static bool $searchCalled = false;

            /** @var array{0: string, 1: string, 2: int}|null [q, type, cap] */
            public static ?array $lastSearchArgs = null;

            /** @var array<int, \BCC\Search\DTO\PageHydratedDTO> keyed by page ID */
            public static array $pagesById = [];

            /** @var list<list<int>> */
            public static array $hydrateCalls = [];

            /** @return array<array{slug: string, name: string}> */
            public static function getCategories(): array
            {
                if (self::$categoriesThrow) {
                    throw new \RuntimeException('stub: getCategories failure');
                }
                return self::$categories;
            }

            /** @return list<\BCC\Search\DTO\PageCandidateDTO> */
            public static function searchCandidates(string $query, string $type, int $cap): array
            {
                self::$searchCalled   = true;
                self::$lastSearchArgs = [$query, $type, $cap];
                // Mirror the production LIMIT: never return more than $cap.
                return array_slice(self::$candidates, 0, $cap);
            }

            /**
             * Mirrors the production ORDER BY FIELD(...) contract: rows come
             * back in the order of $ids, holes (unknown IDs) skipped, batch
             * hard-capped at 50.
             *
             * @param int[] $ids
             * @return list<\BCC\Search\DTO\PageHydratedDTO>
             */
            public static function hydratePages(array $ids): array
            {
                self::$hydrateCalls[] = array_values($ids);
                $out = [];
                foreach (array_slice($ids, 0, 50) as $id) {
                    if (isset(self::$pagesById[$id])) {
                        $out[] = self::$pagesById[$id];
                    }
                }
                return $out;
            }

            /** @return list<\BCC\Search\DTO\TrendingPageRowDTO> */
            public static function getTrendingFromReadModel(int $limit): array
            {
                return [];
            }

            /** @return int[] */
            public static function getFallbackPageIds(int $limit): array
            {
                return [];
            }

            public static function bustCategoryCache(): void
            {
            }

            public static function reset(): void
            {
                self::$categories      = [];
                self::$categoriesThrow = false;
                self::$candidates      = [];
                self::$searchCalled    = false;
                self::$lastSearchArgs  = null;
                self::$pagesById       = [];
                self::$hydrateCalls    = [];
            }
        }
    }
}

namespace {

    use BCC\Search\Tests\Stubs\TestCacheStore;

    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!class_exists('PeepSo', false)) {
        class PeepSo
        {
            public static function get_page(string $which): string
            {
                return 'https://site.test/pages';
            }

            public static function get_peepso_uri(): string
            {
                return 'https://site.test/peepso/';
            }

            public static function get_asset(string $path): string
            {
                return 'https://site.test/peepso/' . $path;
            }
        }
    }

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
            }

            public function get_param(string $key): mixed
            {
                return $this->params[$key] ?? null;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            public mixed $data;
            public int $status;
            /** @var array<string, string> */
            public array $headers;

            /** @param array<string, string> $headers */
            public function __construct(mixed $data = null, int $status = 200, array $headers = [])
            {
                $this->data    = $data;
                $this->status  = $status;
                $this->headers = $headers;
            }

            public function get_data(): mixed
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }
        }
    }

    if (!function_exists('wp_cache_get')) {
        function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
        {
            $found = isset(TestCacheStore::$cache[$group][$key]);
            return $found ? TestCacheStore::$cache[$group][$key] : false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, mixed $value, string $group = '', int $ttl = 0): bool
        {
            TestCacheStore::$cache[$group][$key] = $value;
            return true;
        }
    }

    if (!function_exists('wp_cache_add')) {
        function wp_cache_add(string $key, mixed $value, string $group = '', int $ttl = 0): bool
        {
            if (isset(TestCacheStore::$cache[$group][$key])) {
                return false;
            }
            TestCacheStore::$cache[$group][$key] = $value;
            return true;
        }
    }

    if (!function_exists('wp_cache_incr')) {
        function wp_cache_incr(string $key, int $offset = 1, string $group = ''): int|false
        {
            if (!isset(TestCacheStore::$cache[$group][$key]) || !is_int(TestCacheStore::$cache[$group][$key])) {
                return false;
            }
            TestCacheStore::$cache[$group][$key] += $offset;
            return TestCacheStore::$cache[$group][$key];
        }
    }

    if (!function_exists('wp_cache_decr')) {
        function wp_cache_decr(string $key, int $offset = 1, string $group = ''): int|false
        {
            if (!isset(TestCacheStore::$cache[$group][$key]) || !is_int(TestCacheStore::$cache[$group][$key])) {
                return false;
            }
            TestCacheStore::$cache[$group][$key] -= $offset;
            return TestCacheStore::$cache[$group][$key];
        }
    }

    if (!function_exists('wp_using_ext_object_cache')) {
        function wp_using_ext_object_cache(): bool
        {
            return false;
        }
    }

    if (!function_exists('is_user_logged_in')) {
        function is_user_logged_in(): bool
        {
            return false;
        }
    }

    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
        {
            return $value;
        }
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            return true;
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return TestCacheStore::$options[$name] ?? $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option(string $name, mixed $value, mixed $autoload = null): bool
        {
            TestCacheStore::$options[$name] = $value;
            return true;
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient(string $key): mixed
        {
            return TestCacheStore::$transients[$key] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient(string $key, mixed $value, int $ttl = 0): bool
        {
            TestCacheStore::$transients[$key] = $value;
            return true;
        }
    }

    if (!function_exists('trailingslashit')) {
        function trailingslashit(string $value): string
        {
            return rtrim($value, '/\\') . '/';
        }
    }

    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://site.test' . $path;
        }
    }

    // ── Load the REAL controller — its collaborators are now stubbed ──
    require_once __DIR__ . '/../../app/Controllers/SearchController.php';
}
