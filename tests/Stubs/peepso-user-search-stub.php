<?php
/**
 * PeepSoUserSearch + WP user-lookup stubs for UserSearchRepositoryPrivacyTest.
 *
 * Loaded ONLY inside a @runInSeparateProcess subprocess, and only by the
 * test methods that exercise the privacy-routed path — so the "fail closed
 * when PeepSo is absent" method (which must see class_exists('PeepSoUserSearch')
 * === false) runs in a process where this file is never required.
 *
 * The fake PeepSoUserSearch captures its constructor args (so the test can
 * assert the viewer id is forwarded) and returns a configurable id list.
 * get_userdata() reads from $GLOBALS['__bcc_userdata']. All guarded.
 */

declare(strict_types=1);

namespace {

    if (!class_exists('PeepSoUserSearch', false)) {
        final class PeepSoUserSearch
        {
            /** @var array{args: array<string,mixed>, viewerId: int|null, needle: string}|null */
            public static ?array $lastCtor = null;

            /** @var list<int|numeric-string> */
            public static array $nextResults = [];

            /** @var list<int|numeric-string> */
            public array $results = [];

            /** @param array<string,mixed> $args */
            public function __construct(array $args, ?int $viewerId, string $needle)
            {
                self::$lastCtor = ['args' => $args, 'viewerId' => $viewerId, 'needle' => $needle];
                $this->results  = self::$nextResults;
            }

            public static function reset(): void
            {
                self::$lastCtor    = null;
                self::$nextResults = [];
            }
        }
    }

    if (!class_exists('WP_User', false)) {
        final class WP_User
        {
            public int $ID = 0;
            public string $user_login = '';
            public string $user_nicename = '';
            public string $display_name = '';
        }
    }

    if (!function_exists('cache_users')) {
        /** @param list<int> $ids */
        function cache_users(array $ids): void {}
    }

    if (!function_exists('get_userdata')) {
        /** @return \WP_User|false */
        function get_userdata($userId)
        {
            $map = $GLOBALS['__bcc_userdata'] ?? [];
            return $map[(int) $userId] ?? false;
        }
    }

    if (!function_exists('get_user_meta')) {
        /**
         * Reads from $GLOBALS['__bcc_usermeta'][$userId][$key] — used by the
         * repository's bcc_handle projection. Empty string (single) / empty
         * array mirrors WP's missing-meta return shape.
         *
         * @return mixed
         */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            $map = $GLOBALS['__bcc_usermeta'] ?? [];
            if (isset($map[$userId][$key])) {
                return $map[$userId][$key];
            }
            return $single ? '' : [];
        }
    }
}
