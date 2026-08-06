<?php

namespace BCC\Search\Repositories;

use BCC\Search\DTO\GroupDTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Group Search Repository
 *
 * All DB access for the group-search vertical. Controllers and
 * services must not use $wpdb directly.
 *
 * **Performance discipline** — deliberately narrower than the projects
 * search:
 *   - title-substring LIKE ('%q%'), prefix matches ranked first
 *     (v1.70 — prefix-only made "hall" unable to find "Cosmos Hall").
 *     The leading wildcard is not index-eligible, but the scan is
 *     bounded by the post_type+post_status filter over a tiny
 *     cardinality: published peepso-group rows number in the dozens
 *     (prod is pre-launch). REVISIT if the site ever holds >5k
 *     groups — at that point this vertical needs a dedicated token
 *     index, not a wider LIKE.
 *   - NO FULLTEXT path. The projects FT index covers all post types,
 *     so we COULD reuse it with WHERE post_type='peepso-group', but
 *     FT scoring runs on every matching row BEFORE the post_type
 *     filter, which makes group-specific matches expensive when the
 *     site has many non-group posts.
 *   - NO content match. The request allowed description "optional,
 *     limited" — we return the post_excerpt (short, already stored)
 *     but don't search against it. Searching post_content for groups
 *     isn't worth the table-scan risk when ft index isn't a good fit.
 *   - Hard LIMIT 20 default, 50 max.
 *   - Avatar hash + `_bcc_group_kind` each joined with a single LEFT
 *     JOIN on postmeta (same meta_key pattern as projects) — kind
 *     arrives batched in the one query, never via per-row
 *     get_post_meta.
 */
final class GroupSearchRepository
{
    private const COLUMNS       = 'p.ID, p.post_title, p.post_name, p.post_excerpt';
    public  const GROUP_POST_TYPE = 'peepso-group';

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

    // PeepSo `peepso_group_privacy` post-meta: 0 = public (open),
    // 1 = closed (discoverable by name, join-by-request), 2 = secret
    // (invisible to non-members). Search is a discovery surface, so we
    // exclude SECRET only and keep CLOSED groups findable — the same
    // browse/discovery semantics as
    // bcc-core PeepSoGroupRepository::listBrowsableGroupIds(). (The
    // stricter closed+secret exclusion, getNonOpenGroupIds(), is for the
    // main-feed author gate, not name discovery.)
    private const PRIVACY_SECRET = 2;

    /**
     * Throw on DB error. Immediate-check contract: call on the line
     * following the wpdb accessor, nothing else between.
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
     * Substring search over published PeepSo groups, prefix-ranked.
     *
     * Query shape:
     *
     *     SELECT p.ID, p.post_title, p.post_name, p.post_excerpt,
     *            pm_av.meta_value AS avatar_hash,
     *            pm_kind.meta_value AS group_kind_raw
     *     FROM   wp_posts p
     *     LEFT JOIN wp_postmeta pm_av
     *              ON pm_av.post_id = p.ID
     *             AND pm_av.meta_key = 'peepso_group_avatar_hash'
     *     LEFT JOIN wp_postmeta pm_priv
     *              ON pm_priv.post_id = p.ID
     *             AND pm_priv.meta_key = 'peepso_group_privacy'
     *     LEFT JOIN wp_postmeta pm_kind
     *              ON pm_kind.post_id = p.ID
     *             AND pm_kind.meta_key = '_bcc_group_kind'
     *     WHERE  p.post_type   = 'peepso-group'
     *       AND  p.post_status = 'publish'
     *       AND  (pm_priv.meta_value IS NULL
     *             OR CAST(pm_priv.meta_value AS UNSIGNED) <> 2)  -- hide secret
     *       AND  p.post_title LIKE '%q%'
     *     ORDER BY (p.post_title LIKE 'q%') DESC, p.post_title ASC
     *     LIMIT %d
     *
     * @return list<GroupDTO>
     */
    public static function search(string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));

        global $wpdb;

        // esc_like BEFORE wrapping in wildcards — a literal % or _ in
        // the query must match itself, never widen the scan.
        $substring = '%' . $wpdb->esc_like($query) . '%';
        $prefix    = $wpdb->esc_like($query) . '%';

        $sql = $wpdb->prepare(
            'SELECT ' . self::COLUMNS . ', pm_av.meta_value AS avatar_hash,
                    pm_kind.meta_value AS group_kind_raw
             FROM ' . $wpdb->posts . ' p
             LEFT JOIN ' . $wpdb->postmeta . ' pm_av
                    ON pm_av.post_id = p.ID
                   AND pm_av.meta_key = %s
             LEFT JOIN ' . $wpdb->postmeta . ' pm_priv
                    ON pm_priv.post_id = p.ID
                   AND pm_priv.meta_key = %s
             LEFT JOIN ' . $wpdb->postmeta . ' pm_kind
                    ON pm_kind.post_id = p.ID
                   AND pm_kind.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND (pm_priv.meta_value IS NULL
                    OR CAST(pm_priv.meta_value AS UNSIGNED) <> %d)
               AND p.post_title LIKE %s
             ORDER BY (p.post_title LIKE %s) DESC, p.post_title ASC
             LIMIT %d',
            'peepso_group_avatar_hash',
            'peepso_group_privacy',
            '_bcc_group_kind',
            self::GROUP_POST_TYPE,
            'publish',
            self::PRIVACY_SECRET,
            $substring,
            $prefix,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        self::throwOnDbError('GroupSearchRepository::search query failed');

        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = $row['ID'] ?? null;
            if (!is_numeric($id)) {
                throw new \LogicException('GroupSearchRepository::search: missing/invalid ID');
            }

            // De-dup: the three LEFT JOINs fan out to N rows per group
            // if any joined meta key ever holds multiple rows (importer
            // artifacts, add_post_meta non-unique). The pages vertical
            // guards the same hazard with DISTINCT; duplicate DTOs here
            // become duplicate React keys and wasted quota slots
            // downstream.
            if (isset($seen[(int) $id])) {
                continue;
            }
            $seen[(int) $id] = true;
            if (!isset($row['post_title'], $row['post_name'])) {
                throw new \LogicException('GroupSearchRepository::search: missing projected column');
            }

            // Excerpt → short description. Hard-capped at 160 chars
            // at the repo boundary so the UI can't be swamped by a
            // group owner pasting a full essay into the excerpt.
            $excerpt = isset($row['post_excerpt']) ? (string) $row['post_excerpt'] : '';
            $excerpt = trim(wp_strip_all_tags($excerpt));
            if ($excerpt === '') {
                $desc = null;
            } else {
                $desc = mb_strlen($excerpt) > 160
                    ? rtrim(mb_substr($excerpt, 0, 157)) . '…'
                    : $excerpt;
            }

            $hash = isset($row['avatar_hash']) && $row['avatar_hash'] !== ''
                ? (string) $row['avatar_hash']
                : null;

            // Raw `_bcc_group_kind` meta ('hall' / 'holders' /
            // 'delegators' / 'system'); absent or empty → null, which
            // the service maps to the public 'user' kind.
            $kindRaw = isset($row['group_kind_raw']) && $row['group_kind_raw'] !== ''
                ? (string) $row['group_kind_raw']
                : null;

            $dtos[] = new GroupDTO(
                id:          (int) $id,
                name:        (string) $row['post_title'],
                slug:        (string) $row['post_name'],
                avatarHash:  $hash,
                description: $desc,
                kindRaw:     $kindRaw,
            );
        }
        return $dtos;
    }
}
