<?php

namespace BCC\Search\Repositories;

use BCC\Search\DTO\UserDTO;
use BCC\Search\Support\SearchReads;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * User Search Repository
 *
 * All data access for the user-search vertical lives here.
 *
 * ## Privacy is not optional on this endpoint
 *
 * `GET /bcc/v1/search/users` is anonymous (permission_callback =>
 * '__return_true'). A previous implementation ran a raw `wp_users` LIKE
 * filtered only by `user_status = 0`, which bypassed the entire PeepSo/BCC
 * privacy filter set — users who hid themselves / opted out of discovery,
 * banned / suspended users, and users who blocked the viewer were all
 * enumerable by iterating the prefix. That turned the endpoint into an
 * anonymous member-directory scrape.
 *
 * MATCHING is bcc-trust's `MemberSearchCandidates` (display name or current
 * @handle; never the login or email; a hidden real name by handle only).
 * Those candidate ids then go through **PeepSoUserSearch** as `include` with
 * an EMPTY term (F-302) — the same privacy-aware WP_User_Query wrapper
 * `/users/mention-search` uses
 * (`BCC\Trust\Core\Services\Mentions\MentionSearchService`). Its
 * `pre_user_query` callback applies: ban filter, `profile_acc != PRIVATE`,
 * members-only-when-anon, `peepso_user_blocked` (both directions when a
 * viewer is known), `allow_hide_user_from_user_listing`, and the
 * `bcc_privacy_discovery_optout` hook. If PeepSo is not loaded we fail
 * closed (empty result) rather than fall back to the privacy-blind query.
 *
 * Email is never matched and never returned — PII by policy. `user_login`
 * is carried on the DTO but the service projects the `bcc_handle`, never the
 * login or nicename (see UserSearchService + UserSearchServiceTest).
 */
final class UserSearchRepository
{
    // Default LIMIT enforced at the repository boundary; callers can
    // request smaller but not larger batches.
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

    // The two usermeta keys a result's shown name depends on (bcc-trust).
    private const META_HANDLE           = 'bcc_handle';
    private const META_REAL_NAME_HIDDEN = 'bcc_privacy_real_name_hidden';

    /**
     * Privacy-filtered prefix search over the PeepSo user directory.
     *
     * @param int $viewerId Authenticated viewer id, or 0 for anonymous. A
     *                      known viewer additionally activates the
     *                      block-both-directions filter inside PeepSoUserSearch.
     * @return list<UserDTO>
     */
    public static function search(string $query, int $limit = self::DEFAULT_LIMIT, int $viewerId = 0): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // Fail closed when PeepSo (and therefore the privacy filter set) is
        // unavailable — never fall back to a privacy-blind wp_users scan.
        if (!class_exists('PeepSoUserSearch')) {
            return [];
        }

        // ⛔ The raw query NEVER reaches PeepSo's own search (F-302 review).
        // PeepSo matches user_login and first/last name, and the login is
        // u_<the handle a member signed up with> (or, for a member #540
        // rotated, the old login itself): passing the query there made this
        // anonymous endpoint confirm logins and hidden real names. MATCHING
        // is bcc-trust's MemberSearchCandidates — display name or current
        // @handle, never the login, and a hidden real name by handle only —
        // the same matcher /members and the @-mention picker use. PRIVACY
        // stays PeepSo's: the candidates go back through PeepSoUserSearch as
        // `include` with an EMPTY term. Without bcc-trust there is no
        // matcher, so nothing is returned.
        //
        // F-431: EVERY privacy-passing candidate comes back, and the cut to
        // $limit happens only after the sort by what is shown, below. The
        // privacy pass used to stop at $limit × 3 in PeepSo's user_login
        // order, so a member whose login sorted late fell out before the
        // display-name sort ever saw them, and the top N by name could miss
        // matches. Bounded by the matcher's own cap
        // (MemberSearchCandidates::MAX_CANDIDATES).
        $userIds = self::handleOrNameMatches($query, $viewerId);

        if ($userIds === []) {
            return [];
        }

        // The shown name of EVERY candidate, from two narrow reads (F-431):
        // four wp_users columns and two usermeta keys. Not cache_users(),
        // which loads every meta row of every member: measured at 1000
        // candidates, that was 550-750 ms cold against 22-33 ms for these
        // reads (and today's 150-id cut was already 115-140 ms cold).
        $identities = self::publicIdentities($userIds);

        // F-54: keep a row only when the query matches something PUBLIC
        // about the person. Since F-302 the matcher itself never looks at
        // the login or email, so this is defence in depth: it still drops a
        // row if a future matcher change lets a login-/email-only hit in.
        $needle = mb_strtolower(trim($query));

        $dtos = [];
        foreach ($userIds as $userId) {
            $identity = $identities[$userId] ?? null;
            if ($identity === null) {
                continue;
            }
            $handle = trim($identity['handle']);

            // F-54: no public handle → no public identity to show. The old
            // fallback was user_nicename, which BCC signup derives from the
            // login; and the /u/{handle} profile route needs a handle anyway.
            if ($handle === '') {
                continue;
            }

            $login       = $identity['user_login'];
            $nicename    = $identity['user_nicename'];
            $displayName = $identity['display_name'];
            // A wp-admin-created account defaults display_name to the login.
            if ($displayName === ''
                || strcasecmp($displayName, $login) === 0
                || strcasecmp($displayName, $nicename) === 0
            ) {
                $displayName = $handle;
            }

            // F-28: "Hide my real name" (bcc-trust §K2 real_name_hidden,
            // user meta bcc_privacy_real_name_hidden). For everyone but the
            // member: the real name is NOT a searchable field (a name search
            // would otherwise link it to the @handle) and the result shows
            // '@handle'. The response cache is keyed per viewer, so the
            // member's own view can't leak to others.
            $nameHidden = $userId !== $viewerId
                && filter_var($identity['real_name_hidden'], FILTER_VALIDATE_BOOLEAN);
            if ($nameHidden) {
                $displayName = '@' . $handle;
            }
            // Exactly what the result SHOWS: the effective display name
            // (after the login-shaped and hidden-name swaps above) and the
            // handle. Not first/last name (F-302 review): a member whose
            // display_name is still their u_<original> login became a
            // candidate through it, and a first-name match then kept them,
            // so "alice" found the renamed alice→bob.
            $publicFields = $nameHidden ? [$handle] : [$displayName, $handle];
            if ($needle !== '' && !self::matchesPublicField($needle, $publicFields)) {
                continue;
            }

            $dtos[] = new UserDTO(
                id:           $userId,
                userLogin:    $login,
                userNicename: $nicename,
                displayName:  $displayName,
                handle:       $handle,
            );
        }

        // F-54: PeepSo orders by user_login; re-order by what is shown so the
        // order itself does not encode login names. F-431: over every
        // privacy-passing candidate, so the cut to $limit below is by the
        // shown name and nothing else.
        usort($dtos, static fn (UserDTO $a, UserDTO $b): int =>
            strcmp(mb_strtolower($a->displayName), mb_strtolower($b->displayName)) ?: ($a->id <=> $b->id));

        return array_slice($dtos, 0, $limit);
    }

    /**
     * What a search result is built from, for each id: the four wp_users
     * columns and the two usermeta values the shown name depends on. Two
     * queries over a bounded IN() (≤ MemberSearchCandidates::MAX_CANDIDATES
     * ids), explicit columns. A meta key stored twice reads as its FIRST row,
     * as get_user_meta($id, $key, true) would.
     *
     * Throws when a read CANNOT BE TRUSTED rather than answering an empty
     * list, which would read as "nobody matched" (the F-432 class); the
     * controller turns it into its 503.
     *
     * ⚠⚠ "Could not be trusted" is wider than "errored", and that is the
     * point of F-472. `wpdb::query()` returns false on paths that run BEFORE
     * `flush()`, leaving the PREVIOUS query's rows in `last_result` with
     * `last_error` empty — so the old `get_results()` + `last_error` shape
     * could hand back another query's rows as these members' identities.
     * Both reads now go through a signal that sees it:
     * {@see \BCC\Search\Support\SearchReads} for the users row, and bcc-core's
     * `UserMetaRepository::valuesOrUnknown()` for the meta.
     *
     * ⛔ ON AN UNTRUSTED META READ THIS THROWS, it does not mask. There is
     * nothing to mask TO: the handle and the hidden-name flag come from the
     * SAME read, so "unknown" means the handle is unknown too, and a member
     * with no handle is dropped here by design (F-54) — which would answer
     * an empty list, the exact failure above. Masking as `@member-{id}`
     * would instead produce a result row whose profile link goes nowhere.
     * Refusing shows no real name and makes none searchable.
     *
     * ⚠ THE TWO KEYS RESOLVE DUPLICATES DIFFERENTLY, deliberately:
     *   - `bcc_handle` takes the FIRST row (lowest `umeta_id`), as
     *     `get_user_meta($id, $key, true)` would. It is an identifier;
     *     "strictest" is meaningless for it.
     *   - `bcc_privacy_real_name_hidden` takes the STRICTEST row: ANY value
     *     that reads as true means hidden. First-row-wins would show the
     *     real name of a member whose rows are ['0', '1'], and the hiding
     *     is the half that matters. Same rule as bcc-trust's
     *     PrivacySettings.
     *
     * @param list<int> $userIds
     * @return array<int, array{user_login: string, user_nicename: string, display_name: string, handle: string, real_name_hidden: string}>
     */
    private static function publicIdentities(array $userIds): array
    {
        global $wpdb;

        $in = implode(',', array_fill(0, count($userIds), '%d'));

        $users = SearchReads::rowsOrThrow(
            (string) $wpdb->prepare(
                "SELECT ID, user_login, user_nicename, display_name
                   FROM {$wpdb->users}
                  WHERE ID IN ({$in})",
                ...$userIds
            ),
            'UserSearchRepository::publicIdentities users'
        );

        // bcc-core owns this read (F-472): it clears last_error, checks
        // query()'s own return, and answers null when the rows cannot be
        // trusted. It also keeps every stored value per (user, key) in
        // umeta_id order, which is what lets the two keys below resolve
        // duplicates by different rules.
        $meta = \BCC\Core\Repositories\UserMetaRepository::valuesOrUnknown(
            $userIds,
            [self::META_HANDLE, self::META_REAL_NAME_HIDDEN]
        );
        if ($meta === null) {
            throw new \RuntimeException(
                'UserSearchRepository::publicIdentities usermeta: the read could not be trusted'
            );
        }

        $out = [];
        foreach ($users as $row) {
            $id = (int) ($row['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = [
                'user_login'       => (string) ($row['user_login'] ?? ''),
                'user_nicename'    => (string) ($row['user_nicename'] ?? ''),
                'display_name'     => (string) ($row['display_name'] ?? ''),
                'handle'           => self::firstRow($meta[$id][self::META_HANDLE] ?? []),
                'real_name_hidden' => self::strictestHiddenFlag(
                    $meta[$id][self::META_REAL_NAME_HIDDEN] ?? []
                ),
            ];
        }
        return $out;
    }

    /**
     * Throw on DB error. Immediate-check contract: call on the line
     * following the wpdb accessor, nothing else between. Same contract as
     * GroupSearchRepository's and SearchRepository's.
     */
    private static function throwOnDbError(string $context): void
    {
        global $wpdb;
        $err = (string) $wpdb->last_error;
        if ($err !== '') {
            throw new \RuntimeException("{$context}: {$err}");
        }
    }

    /**
     * Ids whose display name or current @handle contains $query, after
     * PeepSo's privacy filter set (F-302). Empty when bcc-trust is not
     * active: its MemberSearchCandidates is the one matcher for those two
     * fields, and this plugin never reimplements it.
     *
     * All of them (F-431): the caller orders by the shown name, which only
     * exists in PHP, so no cut may happen here. Not PeepSo's order either:
     * its only name order is first/last name, which would make the cut
     * depend on real names a member hid (F-54).
     *
     * @return list<int>
     */
    private static function handleOrNameMatches(string $query, int $viewerId): array
    {
        $matcher = '\\BCC\\Trust\\Core\\Support\\MemberSearchCandidates';
        // Fail closed without PeepSo too, like search(): never return ids
        // the privacy filter set did not see.
        if (!class_exists($matcher) || !class_exists('PeepSoUserSearch')) {
            return [];
        }

        // F-432: fail closed, the third argument. A matching read that did
        // not run throws (RepositoryReadFailure, a RuntimeException, which
        // UserSearchController answers 503) instead of answering "nobody
        // matched". Passed POSITIONALLY: a bcc-trust that predates the flag
        // ignores it and keeps its old answer, where a named argument would
        // be a fatal. MAX_CANDIDATES is ids()' own default for `$max`.
        /** @var list<int> $candidates */
        $candidates = $matcher::ids($query, $matcher::MAX_CANDIDATES, true);
        if ($candidates === []) {
            return [];
        }

        // ⛔ Empty search term: the raw query must not reach PeepSo's own
        // search a second time, only its privacy filter over `include`.
        // `number` = every candidate, so the privacy pass filters and never
        // cuts; `orderby` then only makes the result deterministic.
        //
        // F-432: PeepSoUserSearch is a WP_User_Query with these args merged
        // in, and two of its defaults hide a failed read. Measured on the
        // Local site (WP 7.0.2):
        //   - count_total: SELECT FOUND_ROWS() runs after the failed SELECT
        //     and CLEARS last_error;
        //   - cache_results: even the failed query's [] is cached in
        //     `user-queries`, so the same read then runs no query and answers
        //     [] again until any user changes.
        // Both off, so last_error is this read's own. Nothing here reads
        // PeepSo's total.
        global $wpdb;
        $wpdb->last_error = '';
        // Captured BEFORE the read; see throwUnlessPrivacyReadRan().
        $queriesBefore = (int) ($wpdb->num_queries ?? 0);
        $search = new \PeepSoUserSearch(
            [
                'number'        => count($candidates),
                'orderby'       => 'username',
                'order'         => 'ASC',
                'include'       => $candidates,
                'count_total'   => false,
                'cache_results' => false,
            ],
            $viewerId > 0 ? $viewerId : null,
            ''
        );
        $context = 'UserSearchRepository::handleOrNameMatches privacy read';
        self::throwOnDbError($context);

        // A REPORTED error is only half of it. The other half is a read
        // that never ran and said nothing about it.
        $request = is_object($search->query) ? (string) ($search->query->request ?? '') : '';
        self::throwUnlessPrivacyReadRan($request, $queriesBefore, $context);

        /** @var mixed $raw */
        $raw = $search->results;
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $id) {
            $idInt = (int) $id;
            if ($idInt > 0) {
                $ids[] = $idInt;
            }
        }
        return $ids;
    }

    /**
     * Refuse unless PeepSo's privacy-filtered read ACTUALLY RAN.
     *
     * ⛔ WHY THIS IS NOT {@see \BCC\Search\Support\SearchReads}
     * (F-472). The seam verifies reads WE issue: it calls `query()`
     * itself and checks the return value. This read is issued inside
     * `WP_User_Query`, so there is no return value of ours to check —
     * and `last_error` alone cannot police it, because `wpdb::query()`
     * returns false ABOVE `flush()` on a failed reconnect
     * (`! $this->ready`) and on an emptied `query` filter, recording NO
     * error at all.
     *
     * ⚠⚠ WHAT THIS GUARANTEES: the ids this method returns are the
     * privacy filter's own answer, or the caller gets an exception
     * instead of ids.
     *
     * `fields => 'ID'` makes WP_User_Query read through
     * `$wpdb->get_col()`, which never returns null — so SOME value is
     * always available to hand back, whether or not the statement behind
     * it executed. A value that did not come from this statement has not
     * passed this statement's conditions, whatever it happens to
     * contain, so it is not an answer and must not be used as one.
     *
     * ⇒ Inspecting the returned ids cannot establish that, so it is
     * established about the READ rather than about its output.
     *
     * So this verifies EXECUTION, with two facts that are each
     * necessary and together sufficient:
     *
     *   1. `num_queries` increased. It is incremented inside wpdb's
     *      private `_do_query()`, BELOW both bails, so a bail
     *      increments nothing.
     *   2. `last_query` is this read's own SQL, which WP_User_Query
     *      exposes as `$query->request`. On a bail `last_query` still
     *      holds the previous statement's SQL.
     *
     * Neither alone is enough. (1) alone passes if anything else
     * queried in between — PeepSo resolves a display-name setting
     * through `PeepSoUser::get_instance()` when `system_override_name`
     * is on, and that runs before the read. (2) alone passes if the
     * previous statement happened to be the identical SQL. Together
     * they do not.
     *
     * ⚠ Nothing queries AFTER the read inside the constructor, which is
     * what makes (2) an equality rather than a search through history:
     * with `fields => 'ID'`, `count_total => false` and
     * `cache_results => false`, `WP_User_Query::query()` returns
     * straight after `get_col()`, and PeepSo's `get_total()` answers 0
     * without a statement. If a future PeepSo adds a read there, (2)
     * fails CLOSED — a 503, never a silent pass.
     *
     * ⚠⚠ AN EMPTY `$request` IS ALSO REFUSED, AND THAT IS DELIBERATE.
     *
     * `WP_User_Query::query()` applies `users_pre_query` (WP 5.1+) BEFORE it
     * assembles `request` — class-wp-user-query.php:820. A plugin that
     * answers that filter supplies the results itself, `request` is never
     * assigned, and no statement runs.
     *
     * ⇒ PeepSo's privacy predicate is added in `pre_user_query` (:772), so
     * on that path it is BUILT AND THEN DISCARDED: `request` is the only
     * place `query_where` / `query_from` are used, and it is never built.
     * The plugin's answer replaces the filter wholesale.
     *
     * So "a plugin answered it" is NOT a success here, however legitimate
     * the filter is in general. This read IS the privacy boundary, and
     * accepting an answer that did not pass it would reopen exactly what
     * this method closes — by a different route than a failed read.
     *
     * ⚠ Both conditions take part in refusing it, in different situations,
     * and a mutation control is what established that rather than
     * inspection: with nothing else querying, the counter has not moved and
     * (1) refuses; with an unrelated statement in between the counter HAS
     * moved, and (2) refuses because an unassigned `request` can never
     * equal `last_query`. Each case has its own test, because a mutant that
     * accepted an unbuilt read survived while only the first existed.
     *
     * ⚠ The cost is availability, and it is accepted on purpose: if
     * anything ever answers this query through `users_pre_query`, member
     * search returns 503 until someone decides what the predicate should
     * be in that world. A 503 is discoverable and gets fixed; ids that
     * skipped the filter are not discoverable at all. No such hook exists
     * in this stack today (checked), and the plugin set is ours.
     *
     * @param string $request the SQL WP_User_Query reports it ran
     * @throws \RuntimeException when the privacy-filtered read did not run
     */
    private static function throwUnlessPrivacyReadRan(
        string $request,
        int $queriesBefore,
        string $context
    ): void {
        global $wpdb;

        $ran    = (int) ($wpdb->num_queries ?? 0) > $queriesBefore;
        $isOurs = $request !== '' && (string) ($wpdb->last_query ?? '') === $request;

        if ($ran && $isOurs) {
            return;
        }

        // The flags say WHICH way it failed, and the causes need different
        // responses. `built: no` is the `users_pre_query` case — something
        // answered the query, so PeepSo's predicate never ran either — and
        // it needs a decision about what should filter instead, not a
        // retry.
        throw new \RuntimeException(
            $context . ': the privacy-filtered read did not run, so no result can be trusted'
            . ' (a statement ran: ' . ($ran ? 'yes' : 'no')
            . ', this read was built: ' . ($request !== '' ? 'yes' : 'no')
            . ', and it was the last statement: ' . ($isOurs ? 'yes' : 'no') . ').'
            . ' A read that was never built means something answered the query through'
            . ' users_pre_query, in which case PeepSo\'s privacy predicate did not run either.'
        );
    }

    /**
     * @param list<string> $fields
     */
    private static function matchesPublicField(string $needle, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field !== '' && mb_stripos($field, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The FIRST stored value, as `get_user_meta($id, $key, true)` answers.
     * For an identifier such as the handle, where the oldest row is the one
     * the profile route resolves.
     *
     * @param list<string> $rows lowest umeta_id first
     */
    private static function firstRow(array $rows): string
    {
        return $rows === [] ? '' : (string) $rows[0];
    }

    /**
     * The STRICTEST stored value for a hide flag: '1' when ANY row reads as
     * true, '' when there is no row at all, '0' otherwise.
     *
     * ⚠ NOT first-row-wins. WordPress allows duplicate meta rows, and a
     * member whose rows are ['0', '1'] has asked to be hidden — reading the
     * first row would publish the real name they turned off. The one-way
     * door is the safe direction: an extra row can only ever hide more.
     *
     * Uses the same FILTER_VALIDATE_BOOLEAN reading as the consumer, so
     * 'true'/'yes'/'on' count exactly as they do downstream rather than
     * only the literal '1'.
     *
     * @param list<string> $rows
     */
    private static function strictestHiddenFlag(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        foreach ($rows as $raw) {
            if (filter_var((string) $raw, FILTER_VALIDATE_BOOLEAN)) {
                return '1';
            }
        }

        return '0';
    }

}
