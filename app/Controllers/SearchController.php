<?php

namespace BCC\Search\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\ServiceLocator;

class SearchController
{
    const NAMESPACE    = 'bcc/v1';
    const ROUTE        = '/search';
    const LIMIT            = 12;
    const CAT_CACHE_KEY    = 'bcc_search_categories';
    const CAT_CACHE_TTL    = 43200; // 12 hours
    const SEARCH_CACHE_TTL    = 60;    // seconds
    const TRENDING_CACHE_TTL  = 300;   // 5 minutes
    const SEARCH_VERSION_KEY = 'bcc_search_cache_version';
    const CACHE_GROUP        = 'bcc_search';
    const RATE_LIMIT         = 10;  // max requests
    const RATE_WINDOW        = 5;   // seconds

    /**
     * Resolve the PeepSo page-to-category relation table.
     *
     * PeepSo does not expose a service API, so we must reference its
     * table directly. This helper centralises that knowledge and caches
     * a table-existence check so the search degrades gracefully when
     * PeepSo is absent.
     *
     * @return array{table: string, page_col: string, cat_col: string}|null
     */
    private static function peepso_category_table(): ?array
    {
        static $result = null;
        static $checked = false;

        if ($checked) {
            return $result;
        }
        $checked = true;

        global $wpdb;
        $table = $wpdb->prefix . 'peepso_page_categories';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return null;
        }

        $result = [
            'table'    => $table,
            'page_col' => 'pm_page_id',
            'cat_col'  => 'pm_cat_id',
        ];

        return $result;
    }

    /**
     * Resolve PeepSo frontend asset paths.
     *
     * @return array{url_base: string|null, uri: string, default_avatar: string}
     */
    private static function peepso_assets(): array
    {
        static $assets = null;

        if ($assets !== null) {
            return $assets;
        }

        $assets = ['url_base' => null, 'uri' => '', 'default_avatar' => ''];

        if (class_exists('PeepSo')) {
            $base                    = \PeepSo::get_page('pages');
            $assets['url_base']      = $base ? trailingslashit($base) : null;
            $assets['uri']           = \PeepSo::get_peepso_uri();
            $assets['default_avatar'] = \PeepSo::get_asset('images/avatar/page.png');
        }

        return $assets;
    }

    /**
     * Register cache-busting hooks.
     * Called on 'init' so they fire on admin saves, not just REST requests.
     */
    public static function register_cache_hooks(): void
    {
        add_action('save_post_peepso-page-cat', [__CLASS__, 'bust_category_cache']);
        add_action('delete_post', function (int $post_id): void {
            if (get_post_type($post_id) === 'peepso-page-cat') {
                self::bust_category_cache();
            }
        });

        add_action('save_post_peepso-page', [__CLASS__, 'bust_search_cache']);
        add_action('delete_post', function (int $post_id): void {
            if (get_post_type($post_id) === 'peepso-page') {
                self::bust_search_cache();
            }
        });
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_search'],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
                'type' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
                'trending' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
            ],
        ]);
    }

    public function handle_search(\WP_REST_Request $request)
    {
        // Rate limiting by client IP.
        // Use trust-engine's Cloudflare-aware resolver when available;
        // otherwise fall back to REMOTE_ADDR only (never trust X-Forwarded-For).
        if (class_exists('\\BCC\\Trust\\Security\\IpResolver')) {
            $ip = \BCC\Trust\Security\IpResolver::getClientIp();
        } else {
            $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        $rate_key  = 'bcc_search_rate_' . md5($ip);
        $hits      = (int) get_transient($rate_key);

        if ($hits >= self::RATE_LIMIT) {
            return new \WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please wait a few seconds.',
                ['status' => 429]
            );
        }

        // Non-atomic: concurrent requests between get_transient and set_transient
        // can bypass the limit. Acceptable for a read-only search endpoint.
        // For atomic increment, a persistent object cache (Redis) + wp_cache_incr
        // would be needed, but that is not guaranteed on all WordPress installs.
        set_transient($rate_key, $hits + 1, self::RATE_WINDOW);

        global $wpdb;

        // ── Trending: top-scored pages, no query needed ──────────────────
        if ($request->get_param('trending') === '1') {
            return $this->handle_trending($wpdb);
        }

        $q    = trim($request->get_param('q'));
        $type = trim($request->get_param('type')); // category slug, e.g. "validators"

        // Require at least 2 chars to search
        if (mb_strlen($q) < 2) {
            return rest_ensure_response(['results' => [], 'categories' => $this->get_categories()]);
        }

        // Return cached results if available (wp_cache — no DB write for short TTL).
        $cache_version = get_option( self::SEARCH_VERSION_KEY, 1 );
        $cache_key     = 'search_' . md5( mb_strtolower($q) . '|' . $type . '|' . $cache_version );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( is_array( $cached ) ) {
            return rest_ensure_response( $cached );
        }

        $posts_table = $wpdb->posts;
        $cat_info    = self::peepso_category_table();

        $cap         = $this->getCandidateCap($q);
        $prefix_like = $wpdb->esc_like($q) . '%';
        $infix_like  = '%' . $wpdb->esc_like($q) . '%';

        // ── Phase 1: Lightweight candidate query ───────────────────────
        // Prefix matches first (can use index), then infix. UNION deduplicates.
        // Category JOIN added only when filtering (and only if PeepSo table exists).
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

        // SECURITY: $distinct, $cat_join, $cat_where, $base_where are code-controlled.
        // No user input is interpolated. Parametric values use %s/%d placeholders.
        $base_where = "p.post_type = 'peepso-page' AND p.post_status = 'publish'";

        $id_sql = $wpdb->prepare(
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

        $candidate_rows = $wpdb->get_results($id_sql);

        if ($wpdb->last_error) {
            return new \WP_REST_Response(
                ['code' => 'db_error', 'message' => 'A database error occurred. Please try again.'],
                500
            );
        }

        $candidate_ids  = [];
        $titles_by_id   = [];
        foreach ($candidate_rows as $row) {
            $id = (int) $row->ID;
            $candidate_ids[]    = $id;
            $titles_by_id[$id]  = $row->post_title;
        }

        if (empty($candidate_ids)) {
            $response = ['results' => [], 'categories' => $this->get_categories()];
            wp_cache_set($cache_key, $response, self::CACHE_GROUP, self::SEARCH_CACHE_TTL);
            return rest_ensure_response($response);
        }

        // ── Phase 2: Score, rank, then hydrate winners ───────────────────

        // Batch-fetch trust scores for ALL candidates (single query via interface).
        $scores_by_id = [];
        if (class_exists('\\BCC\\Core\\ServiceLocator')) {
            // NullScoreReadService::getScoresForPageIds() returns [] — same as default.
            $scores_by_id = ServiceLocator::resolveScoreReadService()->getScoresForPageIds($candidate_ids);
        }

        // Blended ranking: trust score (60%) + match relevance (40%).
        $rank_scores = [];
        foreach ($candidate_ids as $id) {
            $trust = $scores_by_id[$id]['total_score'] ?? 0.0;
            $title = $titles_by_id[$id] ?? '';
            $rank_scores[$id] = $this->computeRankScore($title, $q, $trust);
        }

        usort($candidate_ids, static function (int $a, int $b) use ($rank_scores): int {
            return ($rank_scores[$b] <=> $rank_scores[$a]) ?: ($a <=> $b);
        });

        // Take only the top LIMIT winners.
        $winner_ids = array_slice($candidate_ids, 0, self::LIMIT);

        // Resolve the filtered category name once (for display override).
        $filtered_cat_name = null;
        $filtered_cat_slug = null;
        if ($type !== '') {
            $filtered_cat_slug = $type;
            $categories = $this->get_categories();
            foreach ($categories as $cat) {
                if ($cat['slug'] === $type) {
                    $filtered_cat_name = $cat['name'];
                    break;
                }
            }
        }

        $results = $this->hydrateAndFormat($winner_ids, $scores_by_id, $filtered_cat_name, $filtered_cat_slug);

        $response = [
            'results'    => $results,
            'categories' => $this->get_categories(),
        ];

        wp_cache_set( $cache_key, $response, self::CACHE_GROUP, self::SEARCH_CACHE_TTL );

        return rest_ensure_response( $response );
    }

    /**
     * Compute a blended rank score from match relevance (40%) and trust (60%).
     */
    private function computeRankScore(string $title, string $query, float $trustScore): float
    {
        $titleLower = mb_strtolower($title);
        $queryLower = mb_strtolower($query);

        // Match quality: 0.0–1.0
        if ($titleLower === $queryLower) {
            $matchScore = 1.0;
        } elseif (strpos($titleLower, $queryLower) === 0) {
            $matchScore = 0.85;
        } else {
            $pos = mb_strpos($titleLower, $queryLower);
            if ($pos !== false) {
                $matchScore = 0.4 + (0.3 * (1 - min($pos, 50) / 50));
            } else {
                $matchScore = 0.0;
            }
        }

        // Length bonus: shorter titles slightly favored (0.5–1.0)
        $lengthBonus = 1.0 - (min(mb_strlen($titleLower), 100) / 200);

        $relevance = ($matchScore * 0.6) + ($lengthBonus * 0.4);
        $trust     = $trustScore / 100;

        return ($trust * 0.6) + ($relevance * 0.4);
    }

    /**
     * Hydrate winner IDs into full result rows (shared by search + trending).
     *
     * Runs a single SQL query to fetch post data, category info, and avatar hash,
     * then formats each row with trust scores and PeepSo asset URLs.
     *
     * @param int[]       $winnerIds       Ordered page IDs to hydrate.
     * @param array       $scoresById      Trust-score arrays keyed by page ID.
     * @param string|null $filteredCatName  Category name override (search filter).
     * @param string|null $filteredCatSlug  Category slug override (search filter).
     * @return array Formatted result rows.
     */
    private function hydrateAndFormat(
        array $winnerIds,
        array $scoresById,
        ?string $filteredCatName = null,
        ?string $filteredCatSlug = null
    ): array {
        global $wpdb;

        $posts_table = $wpdb->posts;
        $cat_info    = self::peepso_category_table();

        $id_placeholders = implode(',', array_fill(0, count($winnerIds), '%d'));
        // Safe: values are int-cast; FIELD() cannot be parameterized via $wpdb->prepare().
        $id_list         = implode(',', array_map('intval', $winnerIds));

        $rows = $wpdb->get_results($wpdb->prepare(
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
             ORDER BY FIELD(p.ID, {$id_list})",
            ...$winnerIds
        ));

        // Pre-compute URL base and asset paths once instead of per-row.
        $ps = self::peepso_assets();

        $results = [];
        foreach ($rows as $row) {
            $pid   = (int) $row->ID;
            $score = $scoresById[$pid] ?? null;
            $tier  = is_array($score) ? ($score['reputation_tier'] ?? null) : null;

            $hash   = $row->avatar_hash ?? '';
            $avatar = $hash
                ? esc_url_raw($ps['uri'] . 'pages/' . $pid . '/' . $hash . '-avatar-full.jpg')
                : $ps['default_avatar'];

            $url = $ps['url_base']
                ? $ps['url_base'] . $row->post_name . '/'
                : home_url('/pages/' . $row->post_name . '/');

            $results[] = [
                'id'            => $pid,
                'title'         => $row->post_title,
                'url'           => $url,
                'avatar'        => $avatar,
                'score'         => is_array($score) ? (int) $score['total_score'] : null,
                'tier'          => $tier,
                'category'      => $filteredCatName ?? $row->category_name ?? null,
                'category_slug' => $filteredCatSlug ?? $row->category_slug ?? null,
            ];
        }

        return $results;
    }

    /**
     * Dynamic candidate cap — shorter queries cast a wider net.
     */
    private function getCandidateCap(string $query): int
    {
        $len = mb_strlen($query);
        if ($len >= 5) {
            return 500;
        }
        if ($len >= 3) {
            return 1500;
        }
        return 2500;
    }

    /**
     * Trending: top-scored published pages. No query filter.
     * Cached for TRENDING_CACHE_TTL. Uses same hydration as normal search.
     */
    private function handle_trending(\wpdb $wpdb)
    {
        $cache_version = get_option(self::SEARCH_VERSION_KEY, 1);
        $cache_key     = 'trending_' . $cache_version;
        $cached        = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $posts_table = $wpdb->posts;

        // Fast path: JOIN with scores table and sort in SQL (single query).
        $winner_ids   = [];
        $scores_by_id = [];

        if (class_exists('\\BCC\\Trust\\Database\\TableRegistry')) {
            $scores_table = \BCC\Trust\Database\TableRegistry::scores();
            $limit        = self::LIMIT;

            $rows = $wpdb->get_results(
                "SELECT p.ID, s.total_score, s.reputation_tier
                 FROM {$posts_table} p
                 INNER JOIN {$scores_table} s ON s.page_id = p.ID
                 WHERE p.post_type = 'peepso-page'
                   AND p.post_status = 'publish'
                 ORDER BY s.total_score DESC, p.ID ASC
                 LIMIT {$limit}"
            );

            foreach ($rows as $row) {
                $pid = (int) $row->ID;
                $winner_ids[] = $pid;
                $scores_by_id[$pid] = [
                    'total_score'     => (float) $row->total_score,
                    'reputation_tier' => $row->reputation_tier,
                ];
            }
        }

        // Fallback: fetch 500 IDs and sort in PHP if trust-engine is unavailable.
        if (empty($winner_ids)) {
            $candidate_ids = array_map('intval', $wpdb->get_col(
                "SELECT p.ID
                 FROM {$posts_table} p
                 WHERE p.post_type = 'peepso-page'
                   AND p.post_status = 'publish'
                 ORDER BY p.ID DESC
                 LIMIT 500"
            ));

            if (!empty($candidate_ids) && class_exists('\\BCC\\Core\\ServiceLocator')) {
                $scores_by_id = ServiceLocator::resolveScoreReadService()->getScoresForPageIds($candidate_ids);

                usort($candidate_ids, static function (int $a, int $b) use ($scores_by_id): int {
                    $sa = $scores_by_id[$a]['total_score'] ?? 0.0;
                    $sb = $scores_by_id[$b]['total_score'] ?? 0.0;
                    return ($sb <=> $sa) ?: ($a <=> $b);
                });

                $winner_ids = array_slice($candidate_ids, 0, self::LIMIT);
            }
        }

        if (empty($winner_ids)) {
            $response = ['results' => [], 'categories' => $this->get_categories()];
            return rest_ensure_response($response);
        }

        $results = $this->hydrateAndFormat($winner_ids, $scores_by_id);

        $response = [
            'results'    => $results,
            'categories' => $this->get_categories(),
        ];

        wp_cache_set($cache_key, $response, self::CACHE_GROUP, self::TRENDING_CACHE_TTL);

        return rest_ensure_response($response);
    }

    /**
     * Returns available category options for the type dropdown.
     * Always includes an "All" entry at index 0.
     * Result is cached in a transient for CAT_CACHE_TTL seconds.
     */
    private function get_categories(): array
    {
        $cached = get_transient(self::CAT_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $cats = get_posts([
            'post_type'      => 'peepso-page-cat',
            'post_status'    => 'publish',
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'posts_per_page' => -1,
            'fields'         => 'all',
        ]);

        $options = [['slug' => '', 'name' => 'All Types']];
        foreach ($cats as $cat) {
            $options[] = [
                'slug' => $cat->post_name,
                'name' => $cat->post_title,
            ];
        }

        set_transient(self::CAT_CACHE_KEY, $options, self::CAT_CACHE_TTL);

        return $options;
    }

    public static function bust_category_cache(): void
    {
        delete_transient(self::CAT_CACHE_KEY);
    }

    public static function bust_search_cache(): void
    {
        update_option(self::SEARCH_VERSION_KEY, time());
    }
}
