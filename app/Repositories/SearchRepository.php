<?php

namespace BCC\Search\Repositories;

use BCC\Search\DTO\PageCandidateDTO;
use BCC\Search\DTO\PageHydratedDTO;
use BCC\Search\DTO\TrendingPageRowDTO;

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
    // peepso_page_categories table presence rarely changes (install/activate
    // only). Cache the result long-term to skip the information_schema-ish
    // SHOW TABLES round-trip on hot-path searches. A PeepSo install/uninstall
    // during an active request window is rare; a stale negative caches only
    // degrades to "no category filter" until next cache expiry.
    private const PEEPSO_TABLE_CACHE_KEY = 'bcc_search_peepso_cat_table';
    private const PEEPSO_TABLE_CACHE_TTL = 3600;

    // ── PeepSo table discovery (cached per-process) ─────────────────────

    /**
     * Resolve the PeepSo page-to-category relation table.
     *
     * @return array{table: string, page_col: string, cat_col: string}|null
     */
    public static function peepsoCategoryTable(): ?array
    {
        // Keyed by $wpdb->prefix so a multisite switch_to_blog() re-checks.
        /** @var array<string, array{table: string, page_col: string, cat_col: string}|null> $cache */
        static $cache = [];

        global $wpdb;
        $prefix = $wpdb->prefix;

        if (array_key_exists($prefix, $cache)) {
            return $cache[$prefix];
        }

        // Cross-request cache. Sentinel 'absent' encodes the negative
        // result without conflating with wp_cache_get's false return.
        // Shape is revalidated on read — the object cache layer is
        // shared across plugins on the node and we defend against
        // another plugin writing garbage to the same key.
        $ck = self::PEEPSO_TABLE_CACHE_KEY . '_' . md5($prefix);
        $x  = wp_cache_get($ck, self::CACHE_GROUP);
        if (is_array($x)
            && isset($x['table'], $x['page_col'], $x['cat_col'])
            && is_string($x['table'])
            && is_string($x['page_col'])
            && is_string($x['cat_col'])
        ) {
            $shape = [
                'table'    => $x['table'],
                'page_col' => $x['page_col'],
                'cat_col'  => $x['cat_col'],
            ];
            $cache[$prefix] = $shape;
            return $shape;
        }
        if ($x === 'absent') {
            $cache[$prefix] = null;
            return null;
        }

        $table = $wpdb->prefix . 'peepso_page_categories';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            $cache[$prefix] = null;
            wp_cache_set($ck, 'absent', self::CACHE_GROUP, self::PEEPSO_TABLE_CACHE_TTL);
            return null;
        }

        $cache[$prefix] = [
            'table'    => $table,
            'page_col' => 'pm_page_id',
            'cat_col'  => 'pm_cat_id',
        ];
        wp_cache_set($ck, $cache[$prefix], self::CACHE_GROUP, self::PEEPSO_TABLE_CACHE_TTL);

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

            // Ranking quality changes when FULLTEXT becomes available (MATCH
            // AGAINST replaces title-prefix fallback), so any cached results
            // built pre-install would now rank differently. Bust the search
            // cache version so stale entries are not served alongside fresh
            // ones. Runs inside the GET_LOCK region so only the installing
            // worker does this work; subsequent callers short-circuit on the
            // v2_installed option and never enter this branch.
            if (class_exists('\\BCC\\Search\\Controllers\\SearchController')) {
                \BCC\Search\Controllers\SearchController::bust_search_cache();
            }
        } finally {
            $released = $wpdb->get_var("SELECT RELEASE_LOCK('bcc_ft_index')");
            // RELEASE_LOCK returns 1 on success, 0 if not held by this thread,
            // NULL if the named lock does not exist. Anything other than 1 is
            // unexpected given we just acquired it — surface for observability.
            if ((int) $released !== 1 && class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-search] ensureFulltextIndex: RELEASE_LOCK unexpected result', [
                    'result' => var_export($released, true),
                ]);
            }
        }
    }

    /**
     * Whether the v2 FT index has been confirmed installed.
     *
     * Cheap option-backed flag — no DB roundtrip on hot paths. When false,
     * searchCandidates() degrades to the title-prefix-only LIKE path and
     * MUST NOT run a leading-wildcard scan on post_content (table-scan
     * amplifies under any concurrency and was the primary DoS surface
     * before the v2 index existed).
     */
    private static function isFulltextIndexInstalled(): bool
    {
        return (bool) get_option('bcc_ft_index_v2_installed');
    }

    // ── Candidate search query ──────────────────────────────────────────

    /**
     * Find candidate page IDs matching a search query.
     *
     * Strategy:
     *   - Queries >= 3 chars AND v2 FT index installed: FULLTEXT
     *     MATCH...AGAINST in BOOLEAN MODE (uses index, no table scan).
     *   - All other paths: title-prefix LIKE only — `post_title LIKE 'q%'`.
     *     Index-usable and bounded.
     *
     * HARD RULE: we never run a leading-wildcard LIKE on `post_content`.
     * The old `LIKE '%query%'` path was a full table scan that under
     * concurrency turned the search endpoint into a DoS amplifier on any
     * install where the FT index had not yet been created (wp-cli bulk
     * activate, multisite network activate, deploy-as-file-copy). Content
     * matching is only available through the FT index; if the index is
     * absent, we degrade to title-prefix-only and let the hourly self-heal
     * cron install the index in the background.
     *
     * @param string $query Search term (min 2 chars).
     * @param string $type  Category slug filter (empty = all).
     * @param int    $cap   Max candidates to return.
     * @return list<PageCandidateDTO>
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

        // ── FULLTEXT path ────────────────────────────────────────────────
        // Gated on BOTH length and index presence. When the v2 index has
        // not been installed yet, searchCandidates() MUST skip straight to
        // title-prefix LIKE — a MATCH AGAINST without the index is a hard
        // error from MySQL, not a silent scan, but belt-and-braces here
        // keeps the degraded path single-purpose and easy to audit.
        if (mb_strlen($query) >= 3 && self::isFulltextIndexInstalled()) {
            // Strip FULLTEXT boolean operators and keywords to prevent
            // query-semantics injection / relevance manipulation.
            // preg_replace returns null only on regex compile failure, which
            // would indicate a code bug; coalesce to '' so the sanitizer
            // degrades to the title-prefix fallback rather than blowing up.
            $ft_clean = preg_replace('/[+\-><~*"()@]/', ' ', $query) ?? '';
            $ft_clean = preg_replace('/\b(AND|OR|NOT)\b/i', ' ', $ft_clean) ?? '';
            $ft_clean = trim($ft_clean);

            // If sanitization stripped everything, fall through to title-
            // prefix LIKE below — a standalone '*' term is not useful.
            if ($ft_clean !== '') {
                $ft_term = $ft_clean . '*';

                // Search title + content via FULLTEXT, also match category
                // names so "Cosmos validator" finds pages categorised as
                // "Validators" even if the title doesn't contain that word.
                //
                // The previous implementation used a LEFT JOIN on
                // (peepso_page_categories + wp_posts-as-category). That
                // fanned out rows per category per page, forcing DISTINCT
                // + filesort on the MATCH() score — measurably expensive
                // under concurrency. Replaced with a correlated EXISTS
                // subquery: no fan-out, no DISTINCT needed (the outer
                // join on $type-filter still sets $distinct when applicable),
                // index-eligible via the category prefix LIKE.
                $cat_match_where = '';
                if ($cat_info) {
                    $cat_match_where = $wpdb->prepare(
                        "OR EXISTS (
                            SELECT 1
                            FROM {$cat_info['table']} pcm
                            INNER JOIN {$posts_table} catm
                              ON catm.ID = pcm.{$cat_info['cat_col']}
                            WHERE pcm.{$cat_info['page_col']} = p.ID
                              AND catm.post_type = 'peepso-page-cat'
                              AND catm.post_status = 'publish'
                              AND catm.post_title LIKE %s
                        )",
                        $wpdb->esc_like($query) . '%'
                    );
                }

                $sql = $wpdb->prepare(
                    "SELECT {$distinct} p.ID, p.post_title
                     FROM {$posts_table} p
                     {$cat_join}
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

                $rows = $wpdb->get_results($sql, ARRAY_A);

                // Distinguish "FT errored" from "FT returned zero rows".
                // Errors fall through to title-prefix LIKE (degraded but
                // available). Zero rows do NOT fall through — the caller
                // then runs a redundant second query for a legitimately
                // empty result set, which is pure waste under load.
                if ($wpdb->last_error) {
                    error_log('[bcc-search] FULLTEXT query error, falling back to title-prefix: ' . $wpdb->last_error);
                    // Fall through to title-prefix LIKE below.
                } elseif (is_array($rows)) {
                    return self::candidatesToDtos($rows);
                }
            }
        }

        // ── Title-prefix LIKE fallback (NO post_content, NO leading wildcard) ──
        // Used when:
        //   - query < 3 chars (too short for FULLTEXT minword), OR
        //   - v2 FT index not yet installed (self-heal window), OR
        //   - FT sanitiser reduced the term to empty, OR
        //   - FT returned zero rows / errored.
        //
        // `post_title LIKE 'q%'` is index-eligible on wp_posts. `post_content`
        // is NEVER scanned here — that was the DoS surface before v2.
        $prefix_like = $wpdb->esc_like($query) . '%';

        $sql = $wpdb->prepare(
            "SELECT {$distinct} p.ID, p.post_title
             FROM {$posts_table} p
             {$cat_join}
             WHERE {$base_where}
               AND p.post_title LIKE %s
               {$cat_where}
             ORDER BY p.post_title ASC
             LIMIT %d",
            $prefix_like,
            $cap
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if ($wpdb->last_error || !is_array($rows)) {
            return [];
        }

        return self::candidatesToDtos($rows);
    }

    /**
     * Map ARRAY_A rows from searchCandidates() SQL into PageCandidateDTOs.
     *
     * Both columns are NOT NULL in wp_posts — any missing/non-numeric value
     * signals a SQL schema drift or mapping bug and must fail fast rather
     * than silently inject a valid-looking zero ID into the candidate pool.
     *
     * @param array<int, array<string, string|null>> $rows
     * @return list<PageCandidateDTO>
     */
    private static function candidatesToDtos(array $rows): array
    {
        $dtos = [];
        foreach ($rows as $row) {
            $id = $row['ID'] ?? null;
            if (!is_numeric($id)) {
                throw new \LogicException('SearchRepository::searchCandidates: missing/invalid ID');
            }
            if (!isset($row['post_title'])) {
                throw new \LogicException('SearchRepository::searchCandidates: missing post_title');
            }
            $dtos[] = new PageCandidateDTO(
                id:    (int) $id,
                title: (string) $row['post_title'],
            );
        }
        return $dtos;
    }

    // ── Hydration query ─────────────────────────────────────────────────

    /**
     * Hydrate page IDs into full result rows (post data + category + avatar).
     *
     * @param int[] $ids Ordered page IDs to hydrate.
     * @return list<PageHydratedDTO>
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

        // Main hydrate query: page + avatar only. Primary-category resolution
        // is pulled out into a SECOND single batched query below. The old
        // shape used two correlated `ORDER BY ... LIMIT 1` subqueries in the
        // SELECT list — MySQL evaluates them independently (the optimizer
        // can't combine identically-scoped scalar subqueries), producing
        // 2N index lookups for N pages. For the hydrate batch size (max 50)
        // that's 100 lookups per request; at 500 req/s that is 50K/s of
        // avoidable work. One flat IN(...) query over page_categories
        // replaces both subqueries with a single, ORDER-BY + LIMIT-bounded
        // batch.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                p.ID,
                p.post_title,
                p.post_name,
                pm_av.meta_value AS avatar_hash
             FROM {$posts_table} p
             LEFT JOIN {$wpdb->postmeta}  pm_av ON pm_av.post_id = p.ID
                                                AND pm_av.meta_key = 'peepso_page_avatar_hash'
             WHERE p.ID IN ({$id_placeholders})
             ORDER BY FIELD(p.ID, {$field_placeholders})
             LIMIT %d",
            ...array_merge($ids, $ids, [count($ids)])
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        // Batch-fetch the primary category for each hydrated ID. Returns
        // up to (# distinct cats per page) * (# pages) rows — bounded in
        // practice at a few hundred. We iterate ORDER BY page, menu_order,
        // title and keep only the first hit per page (that is the primary
        // per the admin's intent).
        $primary_cat_by_page = [];
        if ($cat_info) {
            $cat_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pcl.{$cat_info['page_col']} AS page_id,
                        catl.post_title            AS name,
                        catl.post_name             AS slug,
                        catl.menu_order            AS menu_order
                 FROM {$cat_info['table']} pcl
                 INNER JOIN {$posts_table} catl
                         ON catl.ID = pcl.{$cat_info['cat_col']}
                        AND catl.post_type = 'peepso-page-cat'
                        AND catl.post_status = 'publish'
                 WHERE pcl.{$cat_info['page_col']} IN ({$id_placeholders})
                 ORDER BY pcl.{$cat_info['page_col']} ASC,
                          catl.menu_order ASC,
                          catl.post_title ASC",
                ...$ids
            ), ARRAY_A);

            if (is_array($cat_rows)) {
                foreach ($cat_rows as $cr) {
                    $pid = isset($cr['page_id']) ? (int) $cr['page_id'] : 0;
                    if ($pid <= 0 || isset($primary_cat_by_page[$pid])) {
                        continue; // Keep only the first (primary) row per page.
                    }
                    $primary_cat_by_page[$pid] = [
                        'name' => isset($cr['name']) ? (string) $cr['name'] : null,
                        'slug' => isset($cr['slug']) ? (string) $cr['slug'] : null,
                    ];
                }
            }
        }

        $dtos = [];
        foreach ($rows as $row) {
            $id = $row['ID'] ?? null;
            if (!is_numeric($id)) {
                throw new \LogicException('SearchRepository::hydratePages: missing/invalid ID');
            }
            if (!isset($row['post_title'])) {
                throw new \LogicException('SearchRepository::hydratePages: missing post_title');
            }
            if (!isset($row['post_name'])) {
                throw new \LogicException('SearchRepository::hydratePages: missing post_name');
            }
            $pid       = (int) $id;
            $cat       = $primary_cat_by_page[$pid] ?? null;
            $dtos[] = new PageHydratedDTO(
                id:           $pid,
                title:        (string) $row['post_title'],
                slug:         (string) $row['post_name'],
                categoryName: ($cat && $cat['name'] !== null) ? $cat['name'] : null,
                categorySlug: ($cat && $cat['slug'] !== null) ? $cat['slug'] : null,
                avatarHash:   isset($row['avatar_hash']) ? (string) $row['avatar_hash'] : null,
            );
        }
        return $dtos;
    }

    // ── Trending queries ────────────────────────────────────────────────

    /**
     * Get trending pages via the TrendingDataInterface contract.
     *
     * Uses ServiceLocator so bcc-search has no direct dependency on
     * bcc-trust-engine's internal TableRegistry or read model tables.
     *
     * The contract returns `object[]` (stdClass rows). Adapt them at the
     * repository boundary into typed DTOs so no stdClass leaks into
     * bcc-search's service/controller layer.
     *
     * @param int $limit Max results.
     * @return list<TrendingPageRowDTO>
     */
    public static function getTrendingFromReadModel(int $limit): array
    {
        if (!class_exists('\\BCC\\Core\\ServiceLocator')) {
            return [];
        }
        $rows = \BCC\Core\ServiceLocator::resolveTrendingData()->getTrendingPages($limit);

        $dtos = [];
        foreach ($rows as $row) {
            // Contract returns untyped object; convert to array for safe access.
            // Pin expected keys so a contract drift (added/removed fields
            // upstream) surfaces here instead of leaking corrupt data.
            $data = get_object_vars($row);
            foreach (['ID', 'total_score', 'reputation_tier'] as $required) {
                if (!array_key_exists($required, $data)) {
                    throw new \LogicException(
                        "TrendingDataInterface adapter missing key: {$required}"
                    );
                }
            }
            if (!is_numeric($data['ID'])) {
                throw new \LogicException('TrendingDataInterface adapter: non-numeric ID');
            }
            if (!is_numeric($data['total_score'])) {
                throw new \LogicException('TrendingDataInterface adapter: non-numeric total_score');
            }
            $dtos[] = new TrendingPageRowDTO(
                id:             (int) $data['ID'],
                totalScore:     (float) $data['total_score'],
                reputationTier: (string) $data['reputation_tier'],
            );
        }
        return $dtos;
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

        // Direct SELECT of only post_name + post_title avoids inflating full
        // WP_Post objects (which drag post_content/excerpt/meta and can reach
        // 5–10KB each). On a site with 100 categories loaded per search
        // request, the savings is up to ~1MB of PHP memory per request before
        // wp_cache absorbs it — meaningful under concurrent load.
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT post_name AS slug, post_title AS name
             FROM {$wpdb->posts}
             WHERE post_type = 'peepso-page-cat'
               AND post_status = 'publish'
             ORDER BY menu_order ASC, post_title ASC
             LIMIT 100"
        );

        $options = [['slug' => '', 'name' => 'All Types']];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $options[] = [
                    'slug' => (string) $row->slug,
                    'name' => (string) $row->name,
                ];
            }
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
