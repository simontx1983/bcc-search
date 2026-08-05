<?php

namespace BCC\Search\Services;

use BCC\Search\DTO\GroupDTO;
use BCC\Search\Repositories\GroupSearchRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GroupSearchService
 *
 * Orchestrates GroupSearchRepository and projects each GroupDTO into
 * the public response shape. Lives between repository and controller
 * so URL/avatar resolution (which depends on PeepSo APIs, not DB) is
 * kept out of both layers.
 *
 * Response shape:
 *   [
 *     'results' => [
 *       [
 *         'id' => int,
 *         'name' => string,
 *         'slug' => string,
 *         'description' => string|null,
 *         'avatar_url' => string|null,
 *         'group_url' => string,   // relative Next.js community route: /communities/{slug}
 *       ],
 *       ...
 *     ],
 *     'meta' => [ 'count' => int, 'query' => string ],
 *   ]
 */
final class GroupSearchService
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 50;

    /**
     * group_url is a RELATIVE Next.js community route (/communities/{slug}) —
     * never an absolute WP/PeepSo URL. The FE's toInternalHref requires an
     * in-app path (an absolute URL would navigate users off the headless
     * app), and /communities/[slug] renders every group kind (halls too),
     * so search — which projects no type — always uses the community route.
     *
     * @return array{
     *   results: list<array{
     *     id:int,
     *     name:string,
     *     slug:string,
     *     description:string|null,
     *     avatar_url:string|null,
     *     group_url:string
     *   }>,
     *   meta: array{count:int, query:string}
     * }
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = trim($query);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $groups = GroupSearchRepository::search($query, $limit);

        // Resolve PeepSo asset base once per request — mirrors the
        // projects controller's peepso_assets() pattern so avatar URL
        // construction is consistent across verticals.
        $peepso = $this->peepsoGroupAssets();

        $results = [];
        foreach ($groups as $g) {
            $results[] = [
                'id'          => $g->id,
                'name'        => $g->name,
                'slug'        => $g->slug,
                'description' => $g->description,
                'avatar_url'  => $this->resolveAvatarUrl($g, $peepso),
                'group_url'   => $this->resolveGroupUrl($g),
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
     * Resolve the PeepSo group-asset base paths once per request.
     *
     * @return array{uri: string, default_avatar: string|null}
     */
    private function peepsoGroupAssets(): array
    {
        $out = ['uri' => '', 'default_avatar' => null];

        if (!class_exists('PeepSo')) {
            return $out;
        }

        try {
            $uri = \PeepSo::get_peepso_uri();
            $out['uri'] = is_string($uri) ? $uri : '';

            // PeepSo ships a default group avatar. Call directly and
            // let the outer try/catch handle any API variance (same
            // pattern as the projects controller's peepso_assets()).
            $asset = \PeepSo::get_asset('images/avatar/group.png');
            $out['default_avatar'] = is_string($asset) && $asset !== ''
                ? esc_url_raw($asset)
                : null;
        } catch (\Throwable $e) {
            // Keep defaults. UI degrades to "no avatar" rather than
            // 500-ing the endpoint on a PeepSo version mismatch.
        }

        return $out;
    }

    private function resolveGroupUrl(GroupDTO $g): string
    {
        // Canonical Next.js community route. RELATIVE by contract — the FE's
        // toInternalHref requires an in-app path (an absolute WP/PeepSo URL
        // would navigate off the headless app). Cross-kind: /communities/[slug]
        // renders every group kind, halls included, so search — which has no
        // type projected — always uses the community route (not /halls/).
        // Mirrors bcc-trust CardUrlMap::COMMUNITY_URL_PREFIX (cross-plugin).
        return '/communities/' . ltrim($g->slug, '/');
    }

    /**
     * Avatar URL resolution.
     *
     * Mirrors the projects pattern: groups/{id}/{hash}-avatar-full.jpg
     * when a hash is stored, else the PeepSo default group avatar (if
     * available). Returns null when no avatar can be resolved — the
     * frontend shows the empty-avatar placeholder for null.
     *
     * @param array{uri: string, default_avatar: string|null} $peepso
     */
    private function resolveAvatarUrl(GroupDTO $g, array $peepso): ?string
    {
        if ($g->avatarHash !== null && $peepso['uri'] !== '') {
            return esc_url_raw(
                $peepso['uri'] . 'groups/' . $g->id . '/' . $g->avatarHash . '-avatar-full.jpg'
            );
        }
        return $peepso['default_avatar'];
    }
}
