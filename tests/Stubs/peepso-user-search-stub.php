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
 *
 * F-431: the repository reads members' shown-name fields with two narrow
 * queries instead of get_userdata()/get_user_meta(). A $wpdb double answers
 * exactly those two from the SAME fixtures ($GLOBALS['__bcc_userdata'] for
 * wp_users, $GLOBALS['__bcc_usermeta'] for the two meta keys), so a test sets
 * members up once either way. It records every query in ->queries, and
 * ->failOn = 'users' | 'usermeta' makes that read fail the way a real one
 * does (null, plus last_error).
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

            /** @var list<array{args: array<string,mixed>, viewerId: int|null, needle: string}> */
            public static array $calls = [];

            /**
             * Ids the privacy filter lets through on an `include` call (F-302);
             * null = every included id is visible.
             *
             * @var list<int>|null
             */
            public static ?array $visible = null;

            /**
             * F-432: when set, the `include` pass fails the way the real
             * WP_User_Query does once count_total is off: no rows, and this
             * string left in $wpdb->last_error.
             */
            public static ?string $failIncludeWith = null;

            /** @var list<int|numeric-string> */
            public array $results = [];

            /** @param array<string,mixed> $args */
            public function __construct(array $args, ?int $viewerId, string $needle)
            {
                self::$lastCtor = ['args' => $args, 'viewerId' => $viewerId, 'needle' => $needle];
                self::$calls[]  = self::$lastCtor;
                if (isset($args['include']) && is_array($args['include']) && self::$failIncludeWith !== null) {
                    $GLOBALS['wpdb']->last_error = self::$failIncludeWith;
                    $this->results = [];
                    return;
                }
                if (isset($args['include']) && is_array($args['include'])) {
                    // The include-scoped pass: privacy-filtered candidates.
                    $ids = array_values(array_filter(
                        array_map('intval', $args['include']),
                        static fn (int $id): bool => self::$visible === null || in_array($id, self::$visible, true)
                    ));
                    // Like the real WP_User_Query under PeepSo (F-431): ordered
                    // by user_login for `orderby => username`, then cut at
                    // `number`. Without the cut, a caller passing a small
                    // `number` looked correct here and dropped rows in prod.
                    if (($args['orderby'] ?? '') === 'username') {
                        $logins = [];
                        foreach ($ids as $id) {
                            $user        = get_userdata($id);
                            $logins[$id] = $user instanceof \WP_User ? $user->user_login : '';
                        }
                        usort($ids, static fn (int $a, int $b): int => strcmp($logins[$a], $logins[$b]) ?: ($a <=> $b));
                    }
                    if (isset($args['number']) && (int) $args['number'] > 0) {
                        $ids = array_slice($ids, 0, (int) $args['number']);
                    }
                    $this->results = $ids;
                    return;
                }
                $this->results  = self::$nextResults;
            }

            public static function reset(): void
            {
                self::$lastCtor    = null;
                self::$nextResults = [];
                self::$calls       = [];
                self::$visible     = null;
                self::$failIncludeWith = null;
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

    if (!isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = new class {
            public string $users      = 'wp_users';
            public string $usermeta   = 'wp_usermeta';
            public string $last_error = '';

            /**
             * ⚠ OBJECTS, like the real thing. `wpdb::query()` fills
             * `last_result` with stdClass rows whatever output format the
             * caller later asks for; `get_results(ARRAY_A)` converts on the
             * way out. bcc-core's ProbesReads reads `last_result` directly,
             * so the representation here has to be the real one: the seam's
             * `trustedRowsAssoc()` converts to arrays, while
             * `UserMetaRepository` uses `trustedRows()` and wants objects.
             *
             * @var mixed
             */
            public $last_result = [];

            /** 'users' | 'usermeta' — which table's read should fail. */
            public ?string $failOn = null;

            /**
             * 'driver_error'   — flush() ran: last_result reset, last_error set.
             * 'pre_flush_bail' — ⚠⚠ query() returns false ABOVE flush(), so
             *                    last_result still holds the PREVIOUS query's
             *                    rows and last_error stays EMPTY. The failure
             *                    a `last_error` check cannot see, and the
             *                    reason F-472 exists.
             */
            public string $failMode = 'driver_error';

            /** @var list<string> */
            public array $queries = [];

            /** @param mixed ...$args */
            public function prepare(string $query, ...$args): string
            {
                $i = 0;
                return (string) preg_replace_callback(
                    '/%[ds]/',
                    static function (array $m) use (&$i, $args): string {
                        $value = $args[$i++] ?? null;
                        return $m[0] === '%d'
                            ? (string) (int) $value
                            : "'" . addslashes((string) $value) . "'";
                    },
                    $query
                );
            }

            /**
             * The real entry point. Returns the ROW COUNT for a SELECT, or
             * false on failure — which is the signal `get_results()` throws
             * away and `ProbesReads` checks.
             *
             * @return int|false
             */
            public function query(string $sql)
            {
                $this->queries[] = $sql;
                $table = str_contains($sql, 'FROM ' . $this->usermeta) ? 'usermeta' : 'users';

                if ($this->failOn === $table) {
                    if ($this->failMode === 'pre_flush_bail') {
                        // last_result DELIBERATELY untouched, no error set.
                        $this->last_error = '';
                        return false;
                    }
                    $this->last_result = [];
                    $this->last_error  = 'simulated read failure';
                    return false;
                }

                $this->last_error  = '';
                $this->last_result = array_map(
                    static fn (array $row): object => (object) $row,
                    $this->rowsFor($table, $sql)
                );

                return count($this->last_result);
            }

            /**
             * ⚠ FAITHFUL: calls query() and DISCARDS its return, then reads
             * last_result — which is precisely why a `last_error` check after
             * it cannot see the pre-flush bail. Kept so any remaining caller
             * of the old shape behaves as production does.
             *
             * @return list<array<string, string>>
             */
            public function get_results(string $sql, string $output = 'OBJECT'): array
            {
                $this->query($sql);

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
             * The fixture rows a query would match.
             *
             * @return list<array<string, string>>
             */
            private function rowsFor(string $table, string $sql): array
            {
                // The id list is the IN() of bare integers. The meta_key
                // IN('...') clause cannot match this, so the last numeric
                // IN() is the ids whichever order the clauses appear in.
                preg_match_all('/IN \(([0-9, ]+)\)/', $sql, $m);
                $ids = array_map('intval', explode(',', (string) end($m[1])));

                $rows = [];
                if ($table === 'users') {
                    foreach ($ids as $id) {
                        $u = $GLOBALS['__bcc_userdata'][$id] ?? null;
                        if ($u instanceof \WP_User) {
                            $rows[] = [
                                'ID'            => (string) $u->ID,
                                'user_login'    => $u->user_login,
                                'user_nicename' => $u->user_nicename,
                                'display_name'  => $u->display_name,
                            ];
                        }
                    }
                    return $rows;
                }

                preg_match_all("/'([a-z_]+)'/", $sql, $k);
                foreach ($ids as $id) {
                    foreach ($k[1] as $key) {
                        if (!isset($GLOBALS['__bcc_usermeta'][$id][$key])) {
                            continue;
                        }
                        // A list fixture is a key stored more than once: one
                        // row per value, in umeta_id order.
                        $value = $GLOBALS['__bcc_usermeta'][$id][$key];
                        foreach (is_array($value) ? $value : [$value] as $one) {
                            $rows[] = [
                                'user_id'    => (string) $id,
                                'meta_key'   => $key,
                                'meta_value' => (string) $one,
                            ];
                        }
                    }
                }
                return $rows;
            }
        };
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
