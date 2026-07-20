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

        $results = [];
        foreach ($users as $u) {
            // §B6 canonical handle, NOT user_login and NOT user_nicename:
            // this endpoint is public (anonymous callers allowed) and BCC
            // signup derives both login (`u_<handle>`) and nicename from
            // the credential name — returning either hands out
            // credential-stuffing lists AND renders as "@u_<handle>" in
            // the FE. Nicename remains the fallback for accounts that
            // predate handles (never the login).
            $handle = $u->handle !== '' ? $u->handle : $u->userNicename;
            $results[] = [
                'id'           => $u->id,
                'username'     => $handle,
                'display_name' => $u->displayName,
                'avatar_url'   => $this->resolveAvatarUrl($u->id),
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

    /**
     * Avatar URL resolution.
     *
     * Routes through bcc-core's PeepSoMediaCache — the canonical cached
     * seam for PeepSo-resolved media URLs (§11; feed, card, and profile
     * view-models already resolve through it). The previous per-row
     * get_avatar_url() call went through PeepSo's filter, which
     * constructs a PeepSoUser per result (raw `SELECT * FROM
     * peepso_users` + file stat + possible lazy meta write); the cache
     * removes that on warm hits and is busted on the avatar user-meta
     * keys with a 1h TTL backstop. PeepSoMediaCache resolves PeepSo's
     * 'full' variant rather than a sized WP avatar — identical asset on
     * PeepSo installs (the frontend sizes via CSS) — and falls back to
     * get_avatar_url() internally when PeepSo is absent.
     */
    private function resolveAvatarUrl(int $userId): ?string
    {
        $url = \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrl($userId);
        if ($url === '') {
            return null;
        }
        return esc_url_raw($url);
    }
}
