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
 *         'group_url' => string,   // relative in-app route (v1.70)
 *         'kind' => string,        // hall|nft|validator|system|user (v1.70)
 *         'kind_label' => string,  // pre-rendered §A2 kicker (v1.70)
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
     * Raw `_bcc_group_kind` post-meta → public community-kind
     * vocabulary (contract §3.2.4 / §4.9 groups vertical).
     *
     * DELIBERATE LOCKSTEP DUPLICATE of bcc-trust
     * `GroupContextResolver::resolveType()` — bcc-search depends only
     * on bcc-core and can never import bcc-trust, so the map is
     * duplicated here exactly like the page_type→prefix map in
     * SearchController (see its header comment). The route the kind
     * selects mirrors `CardUrlMap::groupUrl` (HALL_URL_PREFIX /
     * COMMUNITY_URL_PREFIX — the locked owner of group routing since
     * the v1.68 canonicalization). Changing a kind means updating BOTH
     * sites in lockstep.
     *
     * Absent/unknown raw values resolve to 'user' (member-created
     * community) — same default as GroupContextResolver.
     */
    private const KIND_RAW_TO_PUBLIC = [
        'hall'       => 'hall',
        'holders'    => 'nft',
        'delegators' => 'validator',
        'system'     => 'system',
    ];

    /**
     * Public kind → pre-rendered display label.
     *
     * DELIBERATE LOCKSTEP DUPLICATE of bcc-trust
     * `CardViewService::COMMUNITY_KICKER_BY_TYPE` (the §3.2.4 kicker
     * vocabulary). Emitted server-side so the frontend renders the
     * word verbatim and never maps kind→copy itself (§A2) — the same
     * reason community cards ship `kicker` rather than a type slug.
     */
    private const KIND_LABEL = [
        'hall'      => 'CHAIN HALL',
        'nft'       => 'HOLDER COMMUNITY',
        'validator' => 'DELEGATOR COMMUNITY',
        'system'    => 'SYSTEM COMMUNITY',
        'user'      => 'COMMUNITY',
    ];

    /**
     * @return array{
     *   results: list<array{
     *     id:int,
     *     name:string,
     *     slug:string,
     *     description:string|null,
     *     avatar_url:string|null,
     *     group_url:string,
     *     kind:string,
     *     kind_label:string
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
            $raw  = $g->kindRaw ?? '';
            $kind = self::KIND_RAW_TO_PUBLIC[$raw] ?? 'user';

            $results[] = [
                'id'          => $g->id,
                'name'        => $g->name,
                'slug'        => $g->slug,
                'description' => $g->description,
                'avatar_url'  => $this->resolveAvatarUrl($g, $peepso),
                'group_url'   => $this->resolveGroupUrl($g, $kind),
                'kind'        => $kind,
                'kind_label'  => self::KIND_LABEL[$kind],
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
     * Avatar-only since v1.70 — group_url no longer touches PeepSo
     * (the old `url_base` member fed the retired absolute-permalink
     * URL path).
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

    /**
     * Group URL resolution — relative in-app route, kind-aware (v1.70).
     *
     * `hall` → /halls/{slug} (the hall-flavored surface); every other
     * kind → /communities/{slug} (the canonical cross-kind detail).
     * This is `CardUrlMap::groupUrl`'s exact mapping (bcc-trust — the
     * locked owner of group routing since the v1.68 canonicalization),
     * mirrored here in lockstep because bcc-search cannot import
     * bcc-trust. It supersedes the v1.68-era flat '/communities/{slug}'
     * this method briefly emitted — that shape existed only because the
     * vertical projected no kind; it now does.
     *
     * RELATIVE by contract — the previous absolute WP-origin permalink
     * implementation (PeepSo page-root / get_permalink / home_url) was
     * the THIRD occurrence of the off-app-navigation defect class
     * (v1.46 users, v1.47 projects). Do not reintroduce an absolute
     * branch here.
     *
     * Empty slug (shouldn't occur on published posts) → the
     * /communities list route rather than a broken detail link.
     */
    private function resolveGroupUrl(GroupDTO $g, string $publicKind): string
    {
        $slug = ltrim($g->slug, '/');
        if ($slug === '') {
            return '/communities';
        }
        $slug = rawurlencode($slug);
        return $publicKind === 'hall'
            ? '/halls/' . $slug
            : '/communities/' . $slug;
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
