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
 * is carried on the DTO but the service projects `user_nicename`, never the
 * login (see UserSearchService + UserSearchServiceTest).
 */
final class UserSearchRepository
{
    // Default LIMIT enforced at the repository boundary; callers can
    // request smaller but not larger batches.
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

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
                'number'  => $limit,
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
                $userIds[] = $idInt;
            }
        }
        if ($userIds === []) {
            return [];
        }

        // Prime the WP user cache once so the per-id get_userdata() below is
        // a single query rather than N. Order is preserved from the search.
        // cache_users() also primes the usermeta cache, so the per-id
        // bcc_handle read below is cache-served, not a query.
        cache_users($userIds);

        $dtos = [];
        foreach ($userIds as $userId) {
            $user = get_userdata($userId);
            if (!$user instanceof \WP_User) {
                continue;
            }
            $handleRaw = get_user_meta($userId, 'bcc_handle', true);
            $dtos[] = new UserDTO(
                id:           $userId,
                userLogin:    (string) $user->user_login,
                userNicename: (string) $user->user_nicename,
                displayName:  (string) $user->display_name,
                handle:       is_string($handleRaw) ? $handleRaw : '',
            );
        }
        return $dtos;
    }
}
