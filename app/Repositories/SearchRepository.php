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

    // ── FULLTEXT index management ──────────────────────────────────────

    private static bool $ftIndexChecked = false;

    /**
     * Ensure a FULLTEXT index exists on wp_posts (title + content).
     *
     * Called from the activation hook (primary) and as a runtime safety
     * net from searchCandidates(). The persistent option guard ensures
     * the ALTER TABLE only runs once — during activation, not mid-request.
     *
     * v2: expanded from title-only to title+content so searches for
     * terms in page descriptions (e.g., "Cosmos validator") also match.
     */
    public static function ensureFulltextIndex(): void
    {
        if (self::$ftIndexChecked) {
            return;
        }
        self::$ftIndexChecked = true;

        // v2 flag — if only v1 (title-only) is installed, upgrade to title+content.
        if (get_option('bcc_ft_index_v2_installed')) {
            return;
        }

        global $wpdb;

        // Attempt creation with a lock to prevent thundering herd.
        $locked = (int) $wpdb->get_var("SELECT GET_LOCK('bcc_ft_index', 0)");
        if ($locked !== 1) {
            return;
        }

        try {
            // Drop the old title-only index if it exists.
            $oldExists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE()
                   AND table_name = %s
                   AND index_name = %s",
                $wpdb->posts,
                'bcc_ft_post_title'
            ));

            if ($oldExists) {
                $wpdb->query("ALTER TABLE {$wpdb->posts} DROP INDEX bcc_ft_post_title");
            }

            // Create the v2 index covering title + content.
            $v2Exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE()
                   AND table_name = %s
                   AND index_name = %s",
                $wpdb->posts,
                'bcc_ft_post_search'
            ));

            if (!$v2Exists) {
                $wpdb->query(
                    "ALTER TABLE {$wpdb->posts} ADD FULLTEXT INDEX bcc_ft_post_search (post_title, post_content)"
                );
            }

            update_option('bcc_ft_index_v2_installed', 1, false);
        } finally {
            $wpdb->get_var("SELECT RELEASE_LOCK('bcc_ft_index')");
        }
    }

    // ── Candidate search query ──────────────────────────────────────────

    /**
     * Find candidate page IDs matching a search query.
     *
     * Strategy:
     *   - Queries >= 3 chars: FULLTEXT MATCH...AGAINST in BOOLEAN MODE
     *     (uses index, no table scan). Falls back to prefix LIKE if
     *     FULLTEXT index doesn't exist yet or returns no results.
     *   - Queries < 3 chars: prefix LIKE only (2-char queries are too
     *     short for FULLTEXT minimum word length).
     *
     * The old LIKE '%query%' (leading wildcard) is removed entirely —
     * it forced full table scans and was the primary scaling bottleneck.
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

        // ── FULLTEXT path (queries >= 3 chars) ──────────────────────────
        if (mb_strlen($query) >= 3) {
            // FULLTEXT index is created on activation; LIKE fallback handles absence.
            // Do NOT call ensureFulltextIndex() here — it risks ALTER TABLE mid-request.

            // Strip FULLTEXT boolean operators and keywords to prevent
            // query-semantics injection / relevance manipulation.
            $ft_clean = preg_replace('/[+\-><~*"()@]/', ' ', $query);
            $ft_clean = preg_replace('/\b(AND|OR|NOT)\b/i', ' ', $ft_clean);
            $ft_clean = trim($ft_clean);

            // If sanitization stripped everything, skip FULLTEXT and fall
            // through to the LIKE fallback to avoid a standalone '*' term.
            if ($ft_clean === '') {
                goto like_fallback;
            }

            $ft_term = $ft_clean . '*';

            // Search title + content via FULLTEXT, also match category names
            // via LEFT JOIN so "Cosmos validator" finds pages categorised as
            // "Validators" even if the title doesn't contain that word.
            $cat_match_join = '';
            $cat_match_where = '';
            if ($cat_info) {
                $cat_match_join = "
                    LEFT JOIN {$cat_info['table']} pcm ON pcm.{$cat_info['page_col']} = p.ID
                    LEFT JOIN {$posts_table} catm ON catm.ID = pcm.{$cat_info['cat_col']}
                        AND catm.post_type = 'peepso-page-cat'
                        AND catm.post_status = 'publish'
                ";
                $cat_match_where = $wpdb->prepare(
                    "OR catm.post_title LIKE %s",
                    '%' . $wpdb->esc_like($query) . '%'
                );
            }

            $sql = $wpdb->prepare(
                "SELECT {$distinct} p.ID, p.post_title
                 FROM {$posts_table} p
                 {$cat_join}
                 {$cat_match_join}
                 WHERE {$base_where}
                   AND (MATCH(p.post_title, p.post_content) AGAINST(%s IN BOOLEAN MODE)
                        {$cat_match_where})
                   {$cat_where}
                 ORDER BY MATCH(p.post_title, p.post_content) AGAINST(%s IN BOOLEAN MODE) DESC,
                          p.post_title ASC
                 LIMIT %d",
                $ft_term,
                $ft_term,
                $cap
            );

            $rows = $wpdb->get_results($sql);

            if ($wpdb->last_error) {
                error_log('[bcc-search] FULLTEXT query error, falling back to LIKE: ' . $wpdb->last_error);
            }

            if (!$wpdb->last_error && !empty($rows)) {
                return $rows;
            }
            // Fall through to LIKE if FULLTEXT unavailable or empty.
        }

        // @phpcs:ignore Generic.PHP.DiscourageGoto -- structured fallback from FULLTEXT sanitizer
        like_fallback:
        // ── LIKE fallback (title prefix + content substring) ────────────
        $prefix_like   = $wpdb->esc_like($query) . '%';
        $content_like  = '%' . $wpdb->esc_like($query) . '%';

        $sql = $wpdb->prepare(
            "SELECT {$distinct} p.ID, p.post_title
             FROM {$posts_table} p
             {$cat_join}
             WHERE {$base_where}
               AND (p.post_title LIKE %s OR p.post_content LIKE %s)
               {$cat_where}
             ORDER BY p.post_title ASC
             LIMIT %d",
            $prefix_like,
            $content_like,
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

        $ids = array_slice($ids, 0, 50); // Hard safety cap on hydration batch size.
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
            ...array_merge($ids, $ids, [min(count($ids), 50)])
        ));
    }

    // ── Trending queries ────────────────────────────────────────────────

    /**
     * Get trending pages via the TrendingDataInterface contract.
     *
     * Uses ServiceLocator so bcc-search has no direct dependency on
     * bcc-trust-engine's internal TableRegistry or read model tables.
     *
     * @param int $limit Max results.
     * @return object[] Rows with ->ID, ->total_score, ->reputation_tier.
     */
    public static function getTrendingFromReadModel(int $limit): array
    {
        if (!class_exists('\\BCC\\Core\\ServiceLocator')) {
            return [];
        }
        return \BCC\Core\ServiceLocator::resolveTrendingData()->getTrendingPages($limit);
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
