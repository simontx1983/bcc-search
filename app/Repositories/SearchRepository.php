<?php

namespace BCC\Search\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search Repository
 *
 * All database queries for the search plugin live here.
 * Controllers must not use $wpdb directly.
 *
 * @package BCC\Search\Repositories
 */
final class SearchRepository
{
    private const CACHE_GROUP = 'bcc_search';
    private const CAT_CACHE_KEY = 'bcc_search_categories';
    private const CAT_CACHE_TTL = 43200; // 12 hours

    // ── PeepSo table discovery (cached per-process) ─────────────────────

    /**
     * Resolve the PeepSo page-to-category relation table.
     *
     * @return array{table: string, page_col: string, cat_col: string}|null
     */
    public static function peepsoCategoryTable(): ?array
    {
        // Keyed by $wpdb->prefix so a multisite switch_to_blog() re-checks.
        static $cache = [];

        global $wpdb;
        $prefix = $wpdb->prefix;

        if (array_key_exists($prefix, $cache)) {
            return $cache[$prefix];
        }

        $table = $wpdb->prefix . 'peepso_page_categories';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            $cache[$prefix] = null;
            return null;
        }

        $cache[$prefix] = [
            'table'    => $table,
            'page_col' => 'pm_page_id',
            'cat_col'  => 'pm_cat_id',
        ];

        return $cache[$prefix];
    }

    // ── Candidate search query ──────────────────────────────────────────

    /**
     * Find candidate page IDs matching a search query.
     * Returns array of objects with ->ID and ->post_title.
     *
     * @param string $query Search term (min 2 chars).
     * @param string $type  Category slug filter (empty = all).
     * @param int    $cap   Max candidates to return.
     * @return object[]
     */
    public static function searchCandidates(string $query, string $type, int $cap): array
    {
        global $wpdb;

        $posts_table = $wpdb->posts;
        $cat_info    = self::peepsoCategoryTable();

        $prefix_like = $wpdb->esc_like($query) . '%';
        $infix_like  = '%' . $wpdb->esc_like($query) . '%';

        // If a category filter was requested but the category table doesn't
        // exist, return empty rather than silently dropping the filter and
        // returning mislabelled results.
        if ($type !== '' && !$cat_info) {
            return [];
        }

        $cat_join  = '';
        $cat_where = '';
        $distinct  = '';
        if ($type !== '' && $cat_info) {
            $distinct = 'DISTINCT';
            $cat_join = "
                INNER JOIN {$cat_info['table']} pc  ON pc.{$cat_info['page_col']} = p.ID
                INNER JOIN {$posts_table}       cat ON cat.ID = pc.{$cat_info['cat_col']}
                                                    AND cat.post_type = 'peepso-page-cat'
                                                    AND cat.post_status = 'publish'
            ";
            $cat_where = $wpdb->prepare("AND cat.post_name = %s", $type);
        }

        $base_where = "p.post_type = 'peepso-page' AND p.post_status = 'publish'";

        $sql = $wpdb->prepare(
            "(SELECT {$distinct} p.ID, p.post_title
              FROM {$posts_table} p
              {$cat_join}
              WHERE {$base_where}
                AND p.post_title LIKE %s
                {$cat_where}
              ORDER BY p.post_title ASC
              LIMIT %d)
             UNION
             (SELECT {$distinct} p.ID, p.post_title
              FROM {$posts_table} p
              {$cat_join}
              WHERE {$base_where}
                AND p.post_title LIKE %s
                {$cat_where}
              ORDER BY p.post_title ASC
              LIMIT %d)
             LIMIT %d",
            $prefix_like,
            $cap,
            $infix_like,
            $cap,
            $cap
        );

        $rows = $wpdb->get_results($sql);

        if ($wpdb->last_error) {
            return [];
        }

        return $rows;
    }

    // ── Hydration query ─────────────────────────────────────────────────

    /**
     * Hydrate page IDs into full result rows (post data + category + avatar).
     *
     * @param int[] $ids Ordered page IDs to hydrate.
     * @return object[]
     */
    public static function hydratePages(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        global $wpdb;

        $posts_table = $wpdb->posts;
        $cat_info    = self::peepsoCategoryTable();

        $id_placeholders    = implode(',', array_fill(0, count($ids), '%d'));
        $field_placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // IDs are passed twice to prepare(): once for WHERE IN, once for
        // ORDER BY FIELD — so every value goes through proper parameterisation.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                p.ID,
                p.post_title,
                p.post_name,
                " . ($cat_info
                    ? "MIN(catl.post_title) AS category_name,
                       MIN(catl.post_name)  AS category_slug,"
                    : "NULL AS category_name,
                       NULL AS category_slug,") . "
                pm_av.meta_value     AS avatar_hash
             FROM {$posts_table} p
             " . ($cat_info
                ? "LEFT JOIN {$cat_info['table']}  pcl   ON pcl.{$cat_info['page_col']} = p.ID
                   LEFT JOIN {$posts_table}        catl  ON catl.ID = pcl.{$cat_info['cat_col']}
                                                         AND catl.post_type = 'peepso-page-cat'
                                                         AND catl.post_status = 'publish'"
                : "") . "
             LEFT JOIN {$wpdb->postmeta}  pm_av ON pm_av.post_id = p.ID
                                                AND pm_av.meta_key = 'peepso_page_avatar_hash'
             WHERE p.ID IN ({$id_placeholders})
             GROUP BY p.ID
             ORDER BY FIELD(p.ID, {$field_placeholders})
             LIMIT %d",
            ...array_merge($ids, $ids, [count($ids)])
        ));
    }

    // ── Trending queries ────────────────────────────────────────────────

    /**
     * Get trending pages from the trust-engine read model.
     *
     * @param int $limit Max results.
     * @return object[] Rows with ->ID, ->total_score, ->reputation_tier.
     */
    public static function getTrendingFromReadModel(int $limit): array
    {
        if (!class_exists('\\BCC\\Trust\\Database\\TableRegistry')) {
            return [];
        }

        global $wpdb;
        $rm_table    = \BCC\Trust\Database\TableRegistry::pageReadModel();
        $posts_table = $wpdb->posts;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT rm.page_id AS ID, rm.trust_score AS total_score, rm.reputation_tier
             FROM {$rm_table} rm
             INNER JOIN {$posts_table} p ON p.ID = rm.page_id
                                         AND p.post_type   = 'peepso-page'
                                         AND p.post_status = 'publish'
             ORDER BY rm.trust_score DESC, rm.page_id ASC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Fallback: get recent page IDs when trust-engine is unavailable.
     *
     * @param int $limit Max results.
     * @return int[] Page IDs.
     */
    public static function getFallbackPageIds(int $limit): array
    {
        global $wpdb;
        $posts_table = $wpdb->posts;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$posts_table} p
             WHERE p.post_type = 'peepso-page'
               AND p.post_status = 'publish'
             ORDER BY p.ID DESC
             LIMIT %d",
            $limit
        )));
    }

    // ── Categories ──────────────────────────────────────────────────────

    /**
     * Get page category options for the search filter dropdown.
     * Cached via wp_cache for CAT_CACHE_TTL seconds.
     *
     * @return array<array{slug: string, name: string}>
     */
    public static function getCategories(): array
    {
        $cached = wp_cache_get(self::CAT_CACHE_KEY, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $cats = get_posts([
            'post_type'      => 'peepso-page-cat',
            'post_status'    => 'publish',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'posts_per_page' => 100,
            'fields'         => 'all',
        ]);

        $options = [['slug' => '', 'name' => 'All Types']];
        foreach ($cats as $cat) {
            $options[] = [
                'slug' => $cat->post_name,
                'name' => $cat->post_title,
            ];
        }

        wp_cache_set(self::CAT_CACHE_KEY, $options, self::CACHE_GROUP, self::CAT_CACHE_TTL);

        return $options;
    }

    /**
     * Bust the category cache.
     */
    public static function bustCategoryCache(): void
    {
        wp_cache_delete(self::CAT_CACHE_KEY, self::CACHE_GROUP);
    }
}
