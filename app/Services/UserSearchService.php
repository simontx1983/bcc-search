<?php

namespace BCC\Search\Services;

use BCC\Search\Repositories\UserSearchRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * UserSearchService
 *
 * Orchestrates UserSearchRepository and projects each UserDTO into the
 * public response shape. Lives between repository and controller so
 * avatar resolution (which depends on PeepSo/WP APIs, not DB) is kept
 * out of both layers.
 *
 * Response shape returned by search():
 *   [
 *     'results' => [
 *       [
 *         'id' => int,
 *         'username' => string,        // §B6 canonical handle
 *         'display_name' => string,
 *         'avatar_url' => string|null,
 *         'profile_url' => string,     // relative Next.js route, /u/{handle}
 *       ],
 *       ...
 *     ],
 *     'meta' => [
 *       'count' => int,
 *       'query' => string,
 *     ],
 *   ]
 */
final class UserSearchService
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 50;

    /**
     * @return array{
     *   results: list<array{id:int, username:string, display_name:string, avatar_url:string|null, profile_url:string}>,
     *   meta: array{count:int, query:string}
     * }
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT, int $viewerId = 0): array
    {
        $query = trim($query);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // $viewerId is forwarded so the repository's PeepSoUserSearch pass
        // can apply the viewer-scoped block filter (0 = anonymous).
        $users = UserSearchRepository::search($query, $limit, $viewerId);

        // Resolve every row's avatar in ONE bulk pass rather than per row.
        // avatarUrl() constructs a PeepSoUser per call on a cache miss (raw
        // `SELECT * FROM peepso_users` + a file stat); on a cold cache that
        // was up to `limit` such round trips per keystroke. avatarUrlBulk()
        // does one wp_cache_get_multiple for the cached entries and only
        // computes the misses.
        $userIds = array_map(static fn($u): int => $u->id, $users);
        $avatars = \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrlBulk($userIds);

        $results = [];
        foreach ($users as $u) {
            // §B6 canonical handle, NOT user_login and NOT user_nicename:
            // this endpoint is public (anonymous callers allowed) and BCC
            // signup derives both login (`u_<handle>`) and nicename from
            // the credential name — returning either hands out
            // credential-stuffing lists AND renders as "@u_<handle>" in
            // the FE. Nicename remains the fallback for accounts that
            // predate handles (never the login).
            $handle    = $u->handle !== '' ? $u->handle : $u->userNicename;
            $avatarUrl = $avatars[$u->id] ?? '';
            $results[] = [
                'id'           => $u->id,
                'username'     => $handle,
                'display_name' => $u->displayName,
                'avatar_url'   => $avatarUrl !== '' ? esc_url_raw($avatarUrl) : null,
                // Relative Next.js member route per contract §4
                // (`/u/{handle}`) — the previous PeepSoUser-resolved WP
                // permalink navigated users OFF the headless frontend and
                // cost a PeepSoUser instantiation per row.
                'profile_url'  => '/u/' . rawurlencode($handle),
            ];
        }

        return [
            'results' => $results,
            'meta'    => [
                'count' => count($results),
                'query' => $query,
            ],
        ];
    }
}
