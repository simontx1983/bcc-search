<?php

namespace BCC\Search\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Security\Throttle;
use BCC\Core\ServiceLocator;
use BCC\Search\Repositories\SearchRepository;

class SearchController
{
    const NAMESPACE    = 'bcc/v1';
    const ROUTE        = '/search';
    const LIMIT            = 12;
    const SEARCH_CACHE_TTL    = 60;    // seconds
    const TRENDING_CACHE_TTL  = 300;   // 5 minutes
    const SEARCH_VERSION_KEY = 'bcc_search_cache_version';
    const CACHE_GROUP        = 'bcc_search';
    const RATE_LIMIT         = 10;  // max requests
    const RATE_WINDOW        = 5;   // seconds

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
            $assets['default_avatar'] = esc_url_raw(\PeepSo::get_asset('images/avatar/page.png'));
        }

        return $assets;
    }

    /**
     * Register cache-busting hooks.
     * Called on 'init' so they fire on admin saves, not just REST requests.
     */
    public static function register_cache_hooks(): void
    {
        add_action('save_post_peepso-page-cat', [SearchRepository::class, 'bustCategoryCache']);
        add_action('delete_post', function (int $post_id): void {
            if (get_post_type($post_id) === 'peepso-page-cat') {
                SearchRepository::bustCategoryCache();
            }
        });

        add_action('save_post_peepso-page', [__CLASS__, 'bust_search_cache']);
        add_action('delete_post', function (int $post_id): void {
            if (get_post_type($post_id) === 'peepso-page') {
                self::bust_search_cache();
            }
        });

        // Trust score changes: bust on endorsement and score recalculation events.
        // Individual votes rely on the 60s cache TTL for freshness — busting on
        // every single vote causes stampedes under active voting. Score recalcs
        // are batched by cron so they're safe to bust on directly.
        add_action('bcc_trust_endorsement_added', [__CLASS__, 'bust_search_cache'], 10, 0);
        add_action('bcc_trust_endorsement_removed', [__CLASS__, 'bust_search_cache'], 10, 0);
        add_action('bcc_trust_score_recalculated', [__CLASS__, 'bust_search_cache'], 10, 0);
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

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_search(\WP_REST_Request $request)
    {
        // Rate limiting by client IP.
        // Delegates to bcc-core's Throttle which tries trust-engine's atomic
        // RateLimiter first, then ext object cache, then transients.
        if (class_exists('\\BCC\\Trust\\Security\\IpResolver')) {
            $ip = \BCC\Trust\Security\IpResolver::getClientIp();
        } else {
            $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }

        $rate_key = "bcc_search_rate_{$ip}";
        if (!Throttle::allow('search', self::RATE_LIMIT, self::RATE_WINDOW, $rate_key)) {
            return new \WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please wait a few seconds.',
                ['status' => 429]
            );
        }

        // ── Trending: top-scored pages, no query needed ──────────────────
        if ($request->get_param('trending') === '1') {
            return $this->handle_trending();
        }

        $q    = trim($request->get_param('q'));
        $type = trim($request->get_param('type'));

        // Fetch categories once per request — used for validation and response.
        $categories = SearchRepository::getCategories();

        // Validate $type against known category slugs to prevent wasted
        // DB queries on nonexistent categories and limit the SQL surface.
        if ($type !== '') {
            $validSlugs = array_column($categories, 'slug');
            if (!in_array($type, $validSlugs, true)) {
                return rest_ensure_response(['results' => [], 'categories' => $categories]);
            }
        }

        // Require 2–100 chars to search
        $qLen = mb_strlen($q);
        if ($qLen < 2 || $qLen > 100) {
            return rest_ensure_response(['results' => [], 'categories' => $categories]);
        }

        // Return cached results if available.
        // The cache stores a wrapper: ['data' => ..., 'expires_at' => timestamp].
        // 'expires_at' is the SOFT expiry (when the data becomes stale).
        // The actual wp_cache TTL is longer (stale buffer) so that a
        // stale-while-revalidate pattern can serve old data while one
        // worker rebuilds the entry.
        $cache_version = get_option(self::SEARCH_VERSION_KEY, 1);
        $cache_key     = 'search_' . md5(mb_strtolower($q) . '|' . mb_strtolower($type) . '|' . $cache_version);
        $cached        = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (is_array($cached) && isset($cached['data'])) {
            $isFresh = isset($cached['expires_at']) && time() < $cached['expires_at'];

            if ($isFresh) {
                // Cache is fresh — serve immediately.
                return rest_ensure_response($cached['data']);
            }

            // Cache is stale but still in the buffer window.
            // Try to acquire the rebuild lock (atomic). If another worker
            // is already rebuilding, serve stale data instead of blocking.
            $lock_key = 'bcc_search_lock_' . md5($cache_key);
            if (!wp_cache_add($lock_key, 1, self::CACHE_GROUP, 30)) {
                // Another worker is rebuilding — serve stale data.
                return rest_ensure_response($cached['data']);
            }
            // We won the lock — fall through to rebuild below.
        } elseif (is_array($cached)) {
            // Legacy cache format (pre-upgrade) — serve as-is.
            return rest_ensure_response($cached);
        } else {
            // ── Stampede protection for cold cache (no stale entry to serve) ──
            $lock_key = 'bcc_search_lock_' . md5($cache_key);
            if (!wp_cache_add($lock_key, 1, self::CACHE_GROUP, 30)) {
                // Another worker is building this entry from scratch.
                // Return empty rather than piling onto the DB.
                return new \WP_REST_Response(['results' => [], 'total' => 0, 'cached' => false]);
            }
        }

        try {
            $cap = $this->getCandidateCap($q);

            // ── Phase 1: Lightweight candidate query (via repository) ───────
            $candidate_rows = SearchRepository::searchCandidates($q, $type, $cap);

            if (empty($candidate_rows)) {
                $response = ['results' => [], 'categories' => $categories];
                $this->cacheSearchResult($cache_key, $response);
                return rest_ensure_response($response);
            }

            $candidate_ids = [];
            $titles_by_id  = [];
            foreach ($candidate_rows as $row) {
                $id = (int) $row->ID;
                $candidate_ids[]   = $id;
                $titles_by_id[$id] = $row->post_title;
            }

            // ── Phase 2: Score, rank, then hydrate winners ──────────────────
            // Use enriched scores (same composite ranking as /discover) so
            // search and discovery produce consistent trust-based ordering.
            $scores_by_id = self::enrichScoresIfAvailable($candidate_ids);

            $rank_scores = [];
            foreach ($candidate_ids as $id) {
                $ranking = $scores_by_id[$id]['ranking_score'] ?? 0.0;
                $title   = $titles_by_id[$id] ?? '';
                $rank_scores[$id] = $this->computeRankScore($title, $q, $ranking);
            }

            usort($candidate_ids, static function (int $a, int $b) use ($rank_scores): int {
                return ($rank_scores[$b] <=> $rank_scores[$a]) ?: ($a <=> $b);
            });

            $winner_ids = array_slice($candidate_ids, 0, self::LIMIT);

            // Resolve filtered category name.
            $filtered_cat_name = null;
            $filtered_cat_slug = null;
            if ($type !== '') {
                $filtered_cat_slug = $type;
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
                'categories' => $categories,
            ];

            $this->cacheSearchResult($cache_key, $response);

            return rest_ensure_response($response);
        } finally {
            wp_cache_delete($lock_key, self::CACHE_GROUP);
        }
    }

    /**
     * Store a search result with stale-while-revalidate semantics.
     *
     * The wrapper stores a soft expiry ('expires_at') jittered by ±20% of
     * SEARCH_CACHE_TTL. The actual wp_cache TTL is SEARCH_CACHE_TTL + 30s
     * (stale buffer), allowing one worker to rebuild while others serve
     * the stale entry.
     *
     * Jitter prevents all cache entries set at the same time from expiring
     * simultaneously, which would cause a coordinated stampede.
     *
     * @param string              $cache_key
     * @param array<string,mixed> $response
     */
    private function cacheSearchResult(string $cache_key, array $response): void
    {
        $jitter    = (int) (self::SEARCH_CACHE_TTL * 0.2);                 // ±20%
        $softTtl   = self::SEARCH_CACHE_TTL + random_int(-$jitter, $jitter); // 48-72s
        $hardTtl   = $softTtl + 30;                                         // stale buffer

        $wrapper = [
            'data'       => $response,
            'expires_at' => time() + $softTtl,
        ];

        wp_cache_set($cache_key, $wrapper, self::CACHE_GROUP, $hardTtl);
    }

    /**
     * Compute a blended rank score from match relevance (40%) and trust (60%).
     *
     * The $compositeScore is the ranking_score from the trust engine's read
     * model — the same formula used by GET /bcc/v1/discover. This ensures
     * search and discovery produce consistent trust-based ordering.
     *
     * The composite score is unbounded (typically 0–80 range). We normalize
     * it to 0–1 using a soft cap at 80 before blending with text relevance.
     */
    private function computeRankScore(string $title, string $query, float $compositeScore): float
    {
        $titleLower = mb_strtolower($title);
        $queryLower = mb_strtolower($query);

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

        $lengthBonus = 1.0 - (min(mb_strlen($titleLower), 100) / 200);
        $relevance   = ($matchScore * 0.6) + ($lengthBonus * 0.4);

        // Normalize composite ranking score to 0–1 with soft cap at 80.
        $trust = min($compositeScore / 80.0, 1.0);

        return ($trust * 0.6) + ($relevance * 0.4);
    }

    /**
     * Format hydrated DB rows into API response items.
     *
     * Field names are aligned with GET /bcc/v1/discover so the frontend
     * can consume both endpoints with the same component logic.
     *
     * @param int[] $winnerIds
     * @param array<int, array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, follower_count: int}> $scoresById
     * @return list<array{page_id: int, page_name: string, page_url: string, avatar_url: string, trust_score: int|null, tier: string|null, endorsements: int, verified: bool, followers: int, category: string|null, category_slug: string|null}>
     */
    private function hydrateAndFormat(
        array $winnerIds,
        array $scoresById,
        ?string $filteredCatName = null,
        ?string $filteredCatSlug = null
    ): array {
        $rows = SearchRepository::hydratePages($winnerIds);
        $ps   = self::peepso_assets();

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
                'page_id'       => $pid,
                'page_name'     => $row->post_title,
                'page_url'      => $url,
                'avatar_url'    => $avatar,
                'trust_score'   => is_array($score) ? (int) $score['total_score'] : null,
                'tier'          => $tier,
                'endorsements'  => is_array($score) ? (int) $score['endorsement_count'] : 0,
                'verified'      => is_array($score) ? (bool) $score['is_verified'] : false,
                'followers'     => is_array($score) ? (int) $score['follower_count'] : 0,
                'category'      => $filteredCatName ?? $row->category_name ?? null,
                'category_slug' => $filteredCatSlug ?? $row->category_slug ?? null,
            ];
        }

        return $results;
    }

    /**
     * Resolve enriched trust scores for a set of page IDs.
     *
     * Wraps the ServiceLocator call with class_exists + try/catch so
     * callers get an empty array instead of a fatal when the trust
     * engine plugin is inactive or throws.
     *
     * @param int[] $pageIds
     * @return array<int, array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, follower_count: int}>
     */
    private static function enrichScoresIfAvailable(array $pageIds): array
    {
        if (!class_exists('\\BCC\\Core\\ServiceLocator')) {
            return [];
        }
        try {
            return ServiceLocator::resolveScoreReadService()->getEnrichedScoresForPageIds($pageIds);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Dynamic candidate cap — shorter queries cast a wider net.
     */
    private function getCandidateCap(string $query): int
    {
        $len = mb_strlen($query);
        if ($len >= 5) {
            return 100;
        }
        if ($len >= 3) {
            return 80;
        }
        return 50;
    }

    /**
     * Trending: top-scored published pages.
     *
     * @return \WP_REST_Response
     */
    private function handle_trending()
    {
        $cache_version = get_option(self::SEARCH_VERSION_KEY, 1);
        $cache_key     = 'trending_' . $cache_version;
        $cached        = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $winner_ids   = [];
        $scores_by_id = [];
        $categories   = SearchRepository::getCategories();

        // Fast path: trust-engine read model for trending pages.
        $rows = SearchRepository::getTrendingFromReadModel(self::LIMIT);
        if (!empty($rows)) {
            $trending_ids = array_map(fn($r) => (int) $r->ID, $rows);
            // Use enriched scores so trending response includes the same
            // fields as search results (endorsements, verified, followers).
            $scores_by_id = self::enrichScoresIfAvailable($trending_ids);
            // Fall back to basic data from the read-model rows if enriched failed.
            foreach ($rows as $row) {
                $pid = (int) $row->ID;
                $winner_ids[] = $pid;
                if (!isset($scores_by_id[$pid])) {
                    $scores_by_id[$pid] = [
                        'total_score'       => (float) $row->total_score,
                        'reputation_tier'   => $row->reputation_tier,
                        'ranking_score'     => 0.0,
                        'endorsement_count' => 0,
                        'is_verified'       => false,
                        'follower_count'    => 0,
                    ];
                }
            }
        }

        // Fallback: fetch recent IDs and sort by composite ranking score.
        if (empty($winner_ids)) {
            $candidate_ids = SearchRepository::getFallbackPageIds(100);

            if (!empty($candidate_ids)) {
                $scores_by_id = self::enrichScoresIfAvailable($candidate_ids);

                usort($candidate_ids, static function (int $a, int $b) use ($scores_by_id): int {
                    $sa = $scores_by_id[$a]['ranking_score'] ?? 0.0;
                    $sb = $scores_by_id[$b]['ranking_score'] ?? 0.0;
                    return ($sb <=> $sa) ?: ($a <=> $b);
                });

                $winner_ids = array_slice($candidate_ids, 0, self::LIMIT);
            }
        }

        if (empty($winner_ids)) {
            return rest_ensure_response(['results' => [], 'categories' => $categories]);
        }

        $results = $this->hydrateAndFormat($winner_ids, $scores_by_id);

        $response = [
            'results'    => $results,
            'categories' => $categories,
        ];

        wp_cache_set($cache_key, $response, self::CACHE_GROUP, self::TRENDING_CACHE_TTL);

        return rest_ensure_response($response);
    }

    public static function bust_search_cache(): void
    {
        update_option(self::SEARCH_VERSION_KEY, time(), false);
    }
}
