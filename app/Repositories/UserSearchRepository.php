<?php

namespace BCC\Search\Repositories;

use BCC\Search\DTO\UserDTO;

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
     * Throws on a DB error rather than answering an empty list, which would
     * read as "nobody matched" (the F-432 class); the controller turns it
     * into its 503.
     *
     * @param list<int> $userIds
     * @return array<int, array{user_login: string, user_nicename: string, display_name: string, handle: string, real_name_hidden: string}>
     */
    private static function publicIdentities(array $userIds): array
    {
        global $wpdb;

        $in = implode(',', array_fill(0, count($userIds), '%d'));

        /** @var list<array<string, mixed>>|null $users */
        $users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, user_login, user_nicename, display_name
                   FROM {$wpdb->users}
                  WHERE ID IN ({$in})",
                ...$userIds
            ),
            ARRAY_A
        );
        self::throwOnDbError('UserSearchRepository::publicIdentities users');

        /** @var list<array<string, mixed>>|null $meta */
        $meta = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_key, meta_value
                   FROM {$wpdb->usermeta}
                  WHERE meta_key IN (%s, %s)
                    AND user_id IN ({$in})
                  ORDER BY umeta_id ASC",
                self::META_HANDLE,
                self::META_REAL_NAME_HIDDEN,
                ...$userIds
            ),
            ARRAY_A
        );
        self::throwOnDbError('UserSearchRepository::publicIdentities usermeta');

        $values = [];
        foreach (is_array($meta) ? $meta : [] as $row) {
            $id  = (int) ($row['user_id'] ?? 0);
            $key = (string) ($row['meta_key'] ?? '');
            if ($id > 0 && !isset($values[$id][$key])) {
                $values[$id][$key] = (string) ($row['meta_value'] ?? '');
            }
        }

        $out = [];
        foreach (is_array($users) ? $users : [] as $row) {
            $id = (int) ($row['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = [
                'user_login'       => (string) ($row['user_login'] ?? ''),
                'user_nicename'    => (string) ($row['user_nicename'] ?? ''),
                'display_name'     => (string) ($row['display_name'] ?? ''),
                'handle'           => $values[$id][self::META_HANDLE] ?? '',
                'real_name_hidden' => $values[$id][self::META_REAL_NAME_HIDDEN] ?? '',
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
        self::throwOnDbError('UserSearchRepository::handleOrNameMatches privacy read');

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
}
