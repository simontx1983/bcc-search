<?php
/**
 * UserSearchController cache-generation test environment (F-430).
 *
 * Loaded ONLY from inside a #[RunTestsInSeparateProcesses] subprocess (see
 * UserSearchCacheGenerationTest) so the main PHPUnit process never sees
 * these fake definitions. A file-level stub that escaped would redefine
 * add_action and the cache shims for the whole unit run.
 *
 * `add_action` here RECORDS instead of returning true: the point of F-430 is
 * which hooks are registered, so a stub that discarded them would make every
 * assertion about them vacuous.
 */

declare(strict_types=1);

namespace BCC\Search\Tests\Stubs {

    /** Backing store the shims below read and write; tests inspect it. */
    final class UserSearchGenStore
    {
        /** @var array<string, mixed> group:key => value */
        public static array $cache = [];

        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var list<array{hook: string, callback: callable, priority: int, args: int}> */
        public static array $actions = [];

        /** @var list<string> cache keys passed to wp_cache_set, in order */
        public static array $setKeys = [];

        /** @var list<string> cache keys passed to wp_cache_get, in order */
        public static array $getKeys = [];

        public static int $viewerId = 0;

        /** F-432: when set, UserSearchService::search() throws a RuntimeException with this message. */
        public static ?string $serviceThrows = null;

        public static function reset(): void
        {
            self::$cache   = [];
            self::$options = [];
            self::$actions = [];
            self::$setKeys = [];
            self::$getKeys = [];
            self::$viewerId = 0;
            self::$serviceThrows = null;
        }

        /** Every callback registered for $hook. */
        public static function callbacksFor(string $hook): array
        {
            $out = [];
            foreach (self::$actions as $a) {
                if ($a['hook'] === $hook) {
                    $out[] = $a['callback'];
                }
            }
            return $out;
        }

        /** @return list<string> every distinct hook name registered, sorted */
        public static function hooks(): array
        {
            $names = array_values(array_unique(array_column(self::$actions, 'hook')));
            sort($names);
            return $names;
        }

        /** Fire every callback registered for $hook with $args. */
        public static function fire(string $hook, mixed ...$args): void
        {
            foreach (self::callbacksFor($hook) as $cb) {
                $cb(...$args);
            }
        }
    }
}

namespace BCC\Core\Security {

    if (!class_exists('BCC\Core\Security\Throttle', false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit, int $window): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Search\Services {

    use BCC\Search\Tests\Stubs\UserSearchGenStore;

    if (!class_exists('BCC\Search\Services\UserSearchService', false)) {
        final class UserSearchService
        {
            /** @return array<string, mixed> */
            public function search(string $q, int $limit, int $viewerId): array
            {
                if (UserSearchGenStore::$serviceThrows !== null) {
                    throw new \RuntimeException(UserSearchGenStore::$serviceThrows);
                }
                return [
                    'results' => [['id' => 1, 'handle' => 'alice']],
                    'meta'    => ['count' => 1, 'query' => $q],
                ];
            }
        }
    }
}

namespace {

    use BCC\Search\Tests\Stubs\UserSearchGenStore;

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
            /** @param array<string, string> $headers */
            public function __construct(
                private mixed $data = null,
                private int $status = 200,
                private array $headers = []
            ) {
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

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            UserSearchGenStore::$actions[] = [
                'hook'     => $hook,
                'callback' => $callback,
                'priority' => $priority,
                'args'     => $accepted_args,
            ];
            return true;
        }
    }

    if (!function_exists('wp_cache_get')) {
        function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
        {
            UserSearchGenStore::$getKeys[] = $key;
            $found = array_key_exists($group . ':' . $key, UserSearchGenStore::$cache);
            return UserSearchGenStore::$cache[$group . ':' . $key] ?? false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, mixed $value, string $group = '', int $ttl = 0): bool
        {
            UserSearchGenStore::$setKeys[] = $key;
            UserSearchGenStore::$cache[$group . ':' . $key] = $value;
            return true;
        }
    }

    /**
     * ⚠ FAITHFUL: writes ONLY when the key is absent. F-442 made the
     * generation reader populate a true cache MISS with add() rather than
     * set(), so that a bump landing between the cache read and the write
     * survives. A permissive stub behaving like set() would hide exactly the
     * behaviour that change exists for.
     */
    if (!function_exists('wp_cache_add')) {
        function wp_cache_add(string $key, mixed $value, string $group = '', int $ttl = 0): bool
        {
            $k = $group . ':' . $key;
            if (array_key_exists($k, UserSearchGenStore::$cache)) {
                return false;
            }
            UserSearchGenStore::$setKeys[] = $key;
            UserSearchGenStore::$cache[$k] = $value;
            return true;
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return UserSearchGenStore::$options[$name] ?? $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option(string $name, mixed $value, mixed $autoload = null): bool
        {
            UserSearchGenStore::$options[$name] = $value;
            return true;
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return UserSearchGenStore::$viewerId;
        }
    }
}
