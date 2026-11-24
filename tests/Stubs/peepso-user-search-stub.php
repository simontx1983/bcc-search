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

            /**
             * ⚠ One UNRELATED statement runs before the privacy read,
             * inside the constructor — modelling PeepSo resolving its
             * display-name setting through `PeepSoUser::get_instance()`
             * when `system_override_name` is on.
             *
             * It exists so a test can prove that a `num_queries` delta
             * ALONE cannot detect a bail: with this on, the counter moves
             * and the read still never ran.
             */
            public static bool $queryBeforeRead = false;

            /**
             * ⚠ When set, a plugin ANSWERS the lookup through
             * `users_pre_query` (WP 5.1+): these ids become the result,
             * `request` is never assigned and NO statement runs.
             *
             * Faithful to class-wp-user-query.php:820, where that filter
             * is applied before `request` is assembled — so PeepSo's
             * privacy predicate, added in `pre_user_query` at :772, has
             * been built and is then discarded unused.
             *
             * @var list<int>|null
             */
            public static ?array $preQueryAnswer = null;

            /** @var list<int|numeric-string> */
            public array $results = [];

            /**
             * The WP_User_Query the real class wraps. Only `request` is
             * modelled: it is the SQL WP assigns BEFORE running the read,
             * which is what makes it readable after a bail.
             */
            public ?object $query = null;

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

                    // ⭐ The ids are still decided in PHP (that is what
                    // `$visible` is for), but they are DELIVERED through
                    // $wpdb, so a pre-flush bail on this pass behaves as
                    // it does in production instead of being imagined.
                    //
                    // `request` is assigned BEFORE the read, exactly as
                    // WP_User_Query does — which is why it is still
                    // readable when the read did not run.
                    if (self::$queryBeforeRead) {
                        $GLOBALS['wpdb']->query(
                            'SELECT usr_display_name_as FROM wp_peepso_user_settings WHERE usr_id = 0'
                        );
                    }

                    // The `users_pre_query` short-circuit, which comes AFTER
                    // the lookup above because that is core's order: the
                    // display-name read happens in prepare_query() where
                    // pre_user_query fires (:772), and this filter fires
                    // later, inside query() (:820). No request, no
                    // statement, and the predicate discarded.
                    if (self::$preQueryAnswer !== null) {
                        $this->query   = (object) ['request' => ''];
                        $this->results = array_map('intval', self::$preQueryAnswer);
                        return;
                    }

                    $request     = self::includeRequest($args);
                    $this->query = (object) ['request' => $request];
                    $GLOBALS['wpdb']->primeColumn($request, $ids);
                    $this->results = $GLOBALS['wpdb']->get_col($request);
                    return;
                }
                $this->results  = self::$nextResults;
            }

            /**
             * The statement WP_User_Query builds for the include pass,
             * carrying the shape PeepSo's `pre_user_query` adds: the
             * `peepso_users` join its privacy predicate reads, and the
             * GROUP BY it appends. Recognisable enough for a test to bail
             * this statement and nothing else.
             *
             * @param array<string,mixed> $args
             */
            private static function includeRequest(array $args): string
            {
                $include = array_map('intval', (array) ($args['include'] ?? []));

                return 'SELECT wp_users.ID FROM wp_users'
                    . ' LEFT JOIN wp_peepso_users acc ON acc.usr_id = wp_users.ID'
                    . ' WHERE 1=1 AND wp_users.ID IN (' . implode(',', $include) . ')'
                    . " AND acc.usr_role NOT IN ('register', 'verified', 'ban')"
                    . ' AND acc.usr_profile_acc <> 4'
                    . ' GROUP BY wp_users.ID ORDER BY wp_users.user_login ASC';
            }

            public static function reset(): void
            {
                self::$lastCtor    = null;
                self::$nextResults = [];
                self::$calls       = [];
                self::$visible     = null;
                self::$failIncludeWith = null;
                self::$queryBeforeRead = false;
                self::$preQueryAnswer  = null;
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
             * Bail the ONE statement containing this fragment, whatever
             * table it reads. `failOn` classifies by table, and the
             * privacy pass reads `wp_users` like the repository's own
             * row read — so bailing one without the other needs this.
             */
            public ?string $failOnSqlContaining = null;

            /**
             * ⚠ MAINTAINED ONLY ON THE SUCCESS PATH, because that is
             * where real wpdb maintains them: `++$this->num_queries` is
             * inside the private `_do_query()`, and `last_query` is
             * assigned below it — both BELOW the two pre-flush bails. A
             * bail therefore leaves the counter where it was and the
             * PREVIOUS statement's SQL in `last_query`, which is the only
             * pair of facts that can detect a read that did not run.
             */
            public int $num_queries = 0;

            public string $last_query = '';

            /**
             * Rows a given statement answers with, keyed by its exact
             * SQL. Used by the privacy pass, whose result the
             * PeepSoUserSearch stub decides.
             *
             * @var array<string, list<object>>
             */
            public array $primed = [];

            /**
             * Seed what one statement answers. The rows carry a single
             * `ID` column, which is what `fields => 'ID'` selects.
             *
             * @param list<int> $ids
             */
            public function primeColumn(string $sql, array $ids): void
            {
                $this->primed[$sql] = array_map(
                    static fn (int $id): object => (object) ['ID' => (string) $id],
                    array_values($ids)
                );
            }

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

            /**
             * ⚠ THE PRIVACY PASS IS LEDGERED SEPARATELY, and is exempt
             * from `failOn`. It reads `wp_users` exactly as
             * publicIdentities' own row read does, so sharing either would
             * silently change what every existing test in this harness
             * observes: `failOn = 'users'` would fail TWO statements
             * instead of one, and the "two narrow reads, not a full user
             * load" count would become three. Both happened on the first
             * attempt at this file.
             *
             * @var list<string>
             */
            public array $privacyQueries = [];

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
                $isPrivacyPass = str_contains($sql, 'wp_peepso_users acc');
                if ($isPrivacyPass) {
                    $this->privacyQueries[] = $sql;
                } else {
                    $this->queries[] = $sql;
                }
                $table = str_contains($sql, 'FROM ' . $this->usermeta) ? 'usermeta' : 'users';

                // The pre-flush bail, scoped to one statement. Nothing is
                // touched: no error, no last_result, no last_query, and
                // the counter does not move.
                if ($this->failOnSqlContaining !== null
                    && str_contains($sql, $this->failOnSqlContaining)
                ) {
                    return false;
                }

                if (!$isPrivacyPass && $this->failOn === $table) {
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
                $this->last_result = $this->primed[$sql] ?? array_map(
                    static fn (array $row): object => (object) $row,
                    $this->rowsFor($table, $sql)
                );

                // Below the bails, as in wpdb.
                $this->num_queries++;
                $this->last_query = $sql;

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
             * ⚠ FAITHFUL, and the reason this harness can model the
             * defect at all: it calls `query()`, DISCARDS the return
             * value and plucks the column out of `last_result`. On a
             * pre-flush bail that is the PREVIOUS statement's column, and
             * `get_col()` never returns null — so the caller receives
             * another query's ids as though they were its own.
             *
             * @return list<string>
             */
            public function get_col(string $sql, int $x = 0): array
            {
                $this->query($sql); // return deliberately ignored, as wpdb does

                $rows = is_array($this->last_result) ? $this->last_result : [];

                return array_values(array_map(
                    static function (object $row) use ($x): string {
                        $values = array_values(get_object_vars($row));

                        return (string) ($values[$x] ?? '');
                    },
                    $rows
                ));
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
