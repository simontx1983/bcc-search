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
 *   - title-prefix LIKE only ('q%'), index-eligible on wp_posts
 *     (post_type, post_status, post_title).
 *   - NO FULLTEXT path. The projects FT index covers all post types,
 *     so we COULD reuse it with WHERE post_type='peepso-group', but
 *     FT scoring runs on every matching row BEFORE the post_type
 *     filter, which makes group-specific matches expensive when the
 *     site has many non-group posts. Title prefix is the right tool
 *     for group name discovery and stays bounded.
 *   - NO content match. The request allowed description "optional,
 *     limited" — we return the post_excerpt (short, already stored)
 *     but don't search against it. Searching post_content for groups
 *     isn't worth the table-scan risk when ft index isn't a good fit.
 *   - Hard LIMIT 20 default, 50 max.
 *   - Avatar hash joined with a single LEFT JOIN on postmeta (same
 *     meta_key pattern as projects).
 */
final class GroupSearchRepository
{
    private const COLUMNS       = 'p.ID, p.post_title, p.post_name, p.post_excerpt';
    public  const GROUP_POST_TYPE = 'peepso-group';

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

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
     * Prefix-match search over published PeepSo groups.
     *
     * Query shape:
     *
     *     SELECT p.ID, p.post_title, p.post_name, p.post_excerpt,
     *            pm_av.meta_value AS avatar_hash
     *     FROM   wp_posts p
     *     LEFT JOIN wp_postmeta pm_av
     *              ON pm_av.post_id = p.ID
     *             AND pm_av.meta_key = 'peepso_group_avatar_hash'
     *     WHERE  p.post_type   = 'peepso-group'
     *       AND  p.post_status = 'publish'
     *       AND  p.post_title LIKE 'q%'
     *     ORDER BY p.post_title ASC
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

        $prefix = $wpdb->esc_like($query) . '%';

        $sql = $wpdb->prepare(
            'SELECT ' . self::COLUMNS . ', pm_av.meta_value AS avatar_hash
             FROM ' . $wpdb->posts . ' p
             LEFT JOIN ' . $wpdb->postmeta . ' pm_av
                    ON pm_av.post_id = p.ID
                   AND pm_av.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND p.post_title LIKE %s
             ORDER BY p.post_title ASC
             LIMIT %d',
            'peepso_group_avatar_hash',
            self::GROUP_POST_TYPE,
            'publish',
            $prefix,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        self::throwOnDbError('GroupSearchRepository::search query failed');

        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            $id = $row['ID'] ?? null;
            if (!is_numeric($id)) {
                throw new \LogicException('GroupSearchRepository::search: missing/invalid ID');
            }
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

            $dtos[] = new GroupDTO(
                id:          (int) $id,
                name:        (string) $row['post_title'],
                slug:        (string) $row['post_name'],
                avatarHash:  $hash,
                description: $desc,
            );
        }
        return $dtos;
    }
}
