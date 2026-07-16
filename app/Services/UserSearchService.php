<?php

namespace BCC\Search\Services;

use BCC\Search\DTO\UserDTO;
use BCC\Search\Repositories\UserSearchRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * UserSearchService
 *
 * Orchestrates UserSearchRepository and projects each UserDTO into the
 * public response shape. Lives between repository and controller so
 * URL/avatar resolution (which depends on PeepSo/WP APIs, not DB) is
 * kept out of both layers.
 *
 * Response shape returned by search():
 *   [
 *     'results' => [
 *       [
 *         'id' => int,
 *         'username' => string,
 *         'display_name' => string,
 *         'avatar_url' => string|null,
 *         'profile_url' => string,
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
            $results[] = [
                'id'           => $u->id,
                // user_nicename, NOT user_login: this endpoint is public
                // (anonymous callers allowed) and user_login is the actual
                // credential name — returning it hands credential-stuffing
                // lists to anyone who iterates the endpoint. The nicename
                // is the URL-safe public handle and renders identically in
                // the FE's "@{username}" display.
                'username'     => $u->userNicename,
                'display_name' => $u->displayName,
                'avatar_url'   => $this->resolveAvatarUrl($u->id),
                'profile_url'  => $this->resolveProfileUrl($u),
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
     * Profile URL resolution, PeepSo-aware.
     *
     * Prefers PeepSoUser::get_profileurl() when PeepSo is available
     * (that's where members actually live on this site). Falls back to
     * the WP author archive so an install without PeepSo still returns
     * a functional URL.
     */
    private function resolveProfileUrl(UserDTO $u): string
    {
        if (class_exists('PeepSoUser')) {
            try {
                $pu = \PeepSoUser::get_instance($u->id);
                // get_instance() is documented to return an instance of
                // PeepSoUser but older versions have signatures that
                // PHPStan infers as "class-string|object". Guard with
                // is_object() so only a genuine instance calls the
                // method — defeats both the static-analysis complaint
                // and any real-world oddity where the factory returns
                // something non-instantiable under a failed lookup.
                if (is_object($pu) && method_exists($pu, 'get_profileurl')) {
                    $url = (string) $pu->get_profileurl();
                    if ($url !== '') {
                        return esc_url_raw($url);
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to WP author archive.
            }
        }
        return esc_url_raw(get_author_posts_url($u->id, $u->userNicename));
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
