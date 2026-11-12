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
 * This repository now resolves candidate ids through **PeepSoUserSearch** —
 * the same privacy-aware WP_User_Query wrapper `/users/mention-search` uses
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

    // F-54 follow-up: the public-field filter below drops rows PeepSo matched
    // on user_login / email only, AFTER the SQL LIMIT. Fetching exactly
    // $limit let a broad query whose first N hits were login-only matches
    // return too few rows, or none. Over-fetch, filter, then trim. Bounded:
    // at most MAX_LIMIT * OVERFETCH_FACTOR ids, one cache_users() batch.
    private const OVERFETCH_FACTOR = 3;

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

        // PeepSoUserSearch force-sets fields=ID and applies the privacy
        // filter set in its pre_user_query callback. It escapes the needle
        // via $wpdb->esc_like internally; we trim upstream. Passing a
        // resolved viewer id (not null) additionally enables the
        // block-both-directions filter for signed-in callers.
        $search = new \PeepSoUserSearch(
            [
                'number'  => $limit * self::OVERFETCH_FACTOR,
                'orderby' => 'username',
                'order'   => 'ASC',
            ],
            $viewerId > 0 ? $viewerId : null,
            $query
        );

        /** @var mixed $rawResults */
        $rawResults = $search->results;
        $rawIds     = is_array($rawResults) ? $rawResults : [];

        $userIds = [];
        foreach ($rawIds as $id) {
            $idInt = (int) $id;
            if ($idInt > 0) {
                $userIds[$idInt] = $idInt;
            }
        }

        // F-302: PeepSo's own search matches first/last name and user_login,
        // never the BCC @handle or the display name. A member's login is
        // u_<the handle they SIGNED UP with> and never changes, so after a
        // rename the member could not be found by their current handle at
        // all. Union in bcc-trust's shared matcher (display name or @handle,
        // never the login; the same one /members and the @-mention picker
        // use), routed back through PeepSoUserSearch as `include` with an
        // EMPTY term, so the same privacy filter set applies to these ids.
        foreach (self::handleOrNameMatches($query, $limit * self::OVERFETCH_FACTOR, $viewerId) as $idInt) {
            $userIds[$idInt] = $idInt;
        }
        $userIds = array_values($userIds);

        if ($userIds === []) {
            return [];
        }

        // Prime the WP user cache once so the per-id get_userdata() below is
        // a single query rather than N. Order is preserved from the search.
        // cache_users() also primes the usermeta cache, so the per-id
        // bcc_handle read below is cache-served, not a query.
        cache_users($userIds);

        // F-54: PeepSoUserSearch matches first/last name OR user_login (and
        // user_email when PeepSo's members_email_searchable is on). On this
        // anonymous endpoint that made the search a substring oracle on
        // login names: probing "adm" and getting back a differently-named
        // user revealed their login. Keep a row only when the query matches
        // something PUBLIC about the person; a login-/email-only match drops.
        $needle = mb_strtolower(trim($query));

        $dtos = [];
        foreach ($userIds as $userId) {
            $user = get_userdata($userId);
            if (!$user instanceof \WP_User) {
                continue;
            }
            $handleRaw = get_user_meta($userId, 'bcc_handle', true);
            $handle    = is_string($handleRaw) ? trim($handleRaw) : '';

            // F-54: no public handle → no public identity to show. The old
            // fallback was user_nicename, which BCC signup derives from the
            // login; and the /u/{handle} profile route needs a handle anyway.
            if ($handle === '') {
                continue;
            }

            $login       = (string) $user->user_login;
            $nicename    = (string) $user->user_nicename;
            $displayName = (string) $user->display_name;
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
                && filter_var(get_user_meta($userId, 'bcc_privacy_real_name_hidden', true), FILTER_VALIDATE_BOOLEAN);
            if ($nameHidden) {
                $displayName = '@' . $handle;
            }
            $publicFields = $nameHidden
                ? [$handle]
                : [
                    $displayName,
                    $handle,
                    (string) get_user_meta($userId, 'first_name', true),
                    (string) get_user_meta($userId, 'last_name', true),
                ];
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
        // order itself does not encode login names.
        usort($dtos, static fn (UserDTO $a, UserDTO $b): int =>
            strcmp(mb_strtolower($a->displayName), mb_strtolower($b->displayName)) ?: ($a->id <=> $b->id));

        return array_slice($dtos, 0, $limit);
    }

    /**
     * Ids whose display name or current @handle contains $query, after
     * PeepSo's privacy filter set (F-302). Empty when bcc-trust is not
     * active: its MemberSearchCandidates is the one matcher for those two
     * fields, and this plugin never reimplements it.
     *
     * @return list<int>
     */
    private static function handleOrNameMatches(string $query, int $number, int $viewerId): array
    {
        $matcher = '\\BCC\\Trust\\Core\\Support\\MemberSearchCandidates';
        // Fail closed without PeepSo too, like search(): never return ids
        // the privacy filter set did not see.
        if (!class_exists($matcher) || !class_exists('PeepSoUserSearch')) {
            return [];
        }

        /** @var list<int> $candidates */
        $candidates = $matcher::ids($query);
        if ($candidates === []) {
            return [];
        }

        // ⛔ Empty search term: the raw query must not reach PeepSo's own
        // search a second time, only its privacy filter over `include`.
        $search = new \PeepSoUserSearch(
            [
                'number'  => $number,
                'orderby' => 'username',
                'order'   => 'ASC',
                'include' => $candidates,
            ],
            $viewerId > 0 ? $viewerId : null,
            ''
        );

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
