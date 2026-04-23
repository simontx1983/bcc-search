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
    public function search(string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = trim($query);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $users = UserSearchRepository::search($query, $limit);

        $results = [];
        foreach ($users as $u) {
            $results[] = [
                'id'           => $u->id,
                'username'     => $u->userLogin,
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
     * get_avatar_url() is the WordPress canonical entrypoint and is
     * already filtered by PeepSo when the plugin is active, so a
     * single call produces the right image on both PeepSo and
     * non-PeepSo installs.
     */
    private function resolveAvatarUrl(int $userId): ?string
    {
        $url = get_avatar_url($userId, ['size' => 96]);
        if (!is_string($url) || $url === '') {
            return null;
        }
        return esc_url_raw($url);
    }
}
