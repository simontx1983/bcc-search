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
        /**
         * The SHARED backing store — "Redis". What other requests can see.
         *
         * @var array<string, mixed> "group\0key" => value
         */
        public static array $cache = [];

        /**
         * ⚠⚠ THE PER-REQUEST IN-MEMORY COPY, and the reason this stub was
         * wrong. Redis Object Cache 2.8.0 (the production drop-in) answers
         * `get()` from `$this->cache` — a plain PHP array living for the
         * request — and only consults Redis when that array has no entry OR
         * `$force` is true:
         *
         *     if ( isset( $this->cache[ $derived_key ] ) && ! $force ) { ... }
         *
         * So a re-read inside one request does NOT see another request's
         * write unless it forces. The old stub had one flat store, which
         * made every re-read see everything — strictly more generous than
         * production, so a guard that re-read without `$force` passed here
         * and did nothing on the live site.
         *
         * @var array<string, mixed> "group\0key" => value
         */
        public static array $runtime = [];

        /**
         * Every wp_cache_get, with the $force it was called with. Lets a
         * test assert the ARGUMENT as well as the behaviour — the behaviour
         * is what matters, but the argument says WHY it behaves.
         *
         * @var list<array{key: string, force: bool, layer: string}>
         */
        public static array $reads = [];

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
            self::$runtime      = [];
            self::$reads        = [];
            self::$options      = [];
            self::$onOptionRead  = null;
            self::$onOptionWrite = null;
            self::$writes       = [];
        }

        public static function k(string $key, string $group): string
        {
            return $group . "\0" . $key;
        }

        /**
         * Simulate ANOTHER REQUEST's write: it lands in the shared store and
         * is invisible to this request's in-memory copy, which is exactly
         * the condition a non-forced re-read cannot see through.
         */
        public static function concurrentWrite(string $key, string $group, mixed $value): void
        {
            self::$cache[self::k($key, $group)] = $value;
        }

        /** Did any wp_cache_get for $key force a read past the runtime copy? */
        public static function forcedReadHappened(string $key): bool
        {
            foreach (self::$reads as $read) {
                if ($read['key'] === $key && $read['force'] === true) {
                    return true;
                }
            }
            return false;
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
            $k = GenStore::k($key, $group);

            // Layer 1: the per-request copy, UNLESS the caller forces past it.
            if (!$force && array_key_exists($k, GenStore::$runtime)) {
                GenStore::$reads[] = ['key' => $key, 'force' => $force, 'layer' => 'runtime'];
                $found = true;
                return GenStore::$runtime[$k];
            }

            // Layer 2: the shared store. A hit also refreshes the per-request
            // copy, as the drop-in does, so a later non-forced read in the
            // same request sees what this one fetched.
            $found = array_key_exists($k, GenStore::$cache);
            GenStore::$reads[] = ['key' => $key, 'force' => $force, 'layer' => 'shared'];
            if ($found) {
                GenStore::$runtime[$k] = GenStore::$cache[$k];
                return GenStore::$cache[$k];
            }
            return false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, mixed $value, string $group = '', int $ttl = 0): bool
        {
            $k = GenStore::k($key, $group);
            GenStore::$cache[$k]   = $value;
            GenStore::$runtime[$k] = $value;
            GenStore::$writes[] = ['op' => 'set', 'key' => $key, 'value' => $value, 'wrote' => true];
            return true;
        }
    }

    if (!function_exists('wp_cache_add')) {
        /** Faithful: writes only when the key is ABSENT. */
        function wp_cache_add(string $key, mixed $value, string $group = '', int $ttl = 0): bool
        {
            $k = GenStore::k($key, $group);
            // Presence in EITHER layer blocks an add, as the drop-in does: it
            // short-circuits on its in-memory copy and otherwise SETNXs.
            if (array_key_exists($k, GenStore::$cache) || array_key_exists($k, GenStore::$runtime)) {
                GenStore::$writes[] = ['op' => 'add', 'key' => $key, 'value' => $value, 'wrote' => false];
                return false;
            }
            GenStore::$cache[$k]   = $value;
            GenStore::$runtime[$k] = $value;
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
