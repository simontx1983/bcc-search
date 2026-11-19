<?php
/**
 * Cache/option environment for the F-442 generation-race tests.
 *
 * Loaded ONLY from inside a #[RunTestsInSeparateProcesses] subprocess (see
 * GenerationRaceTest) so these global shims never reach the rest of the run.
 *
 * ⭐ `onOptionRead` is the whole point. The race these tests are about opens
 * BETWEEN the cache read and the cache write, and the option read sits in
 * that window — so a hook there is how a concurrent bump from another
 * request gets simulated deterministically instead of being hoped for.
 *
 * `wp_cache_add` is implemented faithfully: it writes ONLY when the key is
 * absent, and reports whether it did. A permissive stub that behaved like
 * `set` would make every assertion in that file pass and prove nothing.
 */

declare(strict_types=1);

namespace BCC\Search\Tests\Stubs {

    final class GenStore
    {
        /** @var array<string, mixed> "group\0key" => value */
        public static array $cache = [];

        /** @var array<string, mixed> */
        public static array $options = [];

        /** Invoked inside get_option(), to stand in for a concurrent bump. */
        public static ?\Closure $onOptionRead = null;

        /**
         * Invoked inside update_option(), i.e. DURING a flush, after it has
         * decided its new value and before it writes the cache. The only
         * point where a test can slip a concurrent bump into a flush — which
         * is what makes "a bump publishes unconditionally" a behavioural
         * assertion instead of a source-shape one (F-445).
         */
        public static ?\Closure $onOptionWrite = null;

        /** @var list<array{op: string, key: string, value: mixed, wrote: bool}> */
        public static array $writes = [];

        public static function reset(): void
        {
            self::$cache        = [];
            self::$options      = [];
            self::$onOptionRead  = null;
            self::$onOptionWrite = null;
            self::$writes       = [];
        }

        public static function k(string $key, string $group): string
        {
            return $group . "\0" . $key;
        }

        /** @return list<array{op: string, key: string, value: mixed, wrote: bool}> */
        public static function writesFor(string $key): array
        {
            return array_values(array_filter(
                self::$writes,
                static fn (array $w): bool => $w['key'] === $key
            ));
        }
    }
}

namespace {

    use BCC\Search\Tests\Stubs\GenStore;

    if (!function_exists('wp_cache_get')) {
        function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
        {
            $k     = GenStore::k($key, $group);
            $found = array_key_exists($k, GenStore::$cache);
            return $found ? GenStore::$cache[$k] : false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, mixed $value, string $group = '', int $ttl = 0): bool
        {
            GenStore::$cache[GenStore::k($key, $group)] = $value;
            GenStore::$writes[] = ['op' => 'set', 'key' => $key, 'value' => $value, 'wrote' => true];
            return true;
        }
    }

    if (!function_exists('wp_cache_add')) {
        /** Faithful: writes only when the key is ABSENT. */
        function wp_cache_add(string $key, mixed $value, string $group = '', int $ttl = 0): bool
        {
            $k = GenStore::k($key, $group);
            if (array_key_exists($k, GenStore::$cache)) {
                GenStore::$writes[] = ['op' => 'add', 'key' => $key, 'value' => $value, 'wrote' => false];
                return false;
            }
            GenStore::$cache[$k] = $value;
            GenStore::$writes[] = ['op' => 'add', 'key' => $key, 'value' => $value, 'wrote' => true];
            return true;
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            $value = GenStore::$options[$name] ?? $default;
            if (GenStore::$onOptionRead !== null) {
                $hook = GenStore::$onOptionRead;
                // One-shot: a race fires once, not on every read inside a test.
                GenStore::$onOptionRead = null;
                $hook($name);
            }
            return $value;
        }
    }

    if (!function_exists('update_option')) {
        function update_option(string $name, mixed $value, mixed $autoload = null): bool
        {
            GenStore::$options[$name] = $value;
            if (GenStore::$onOptionWrite !== null) {
                $hook = GenStore::$onOptionWrite;
                // One-shot, like onOptionRead.
                GenStore::$onOptionWrite = null;
                $hook($name, $value);
            }
            return true;
        }
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            return true;
        }
    }

    if (!function_exists('wp_using_ext_object_cache')) {
        function wp_using_ext_object_cache(): bool
        {
            return true;
        }
    }
}
