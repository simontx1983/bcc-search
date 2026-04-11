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

        // Bust search cache when trust scores change (votes, endorsements,
        // dispute resolution). Without this, search results serve stale
        // trust scores and rankings for up to 300 seconds.
        add_action('bcc_trust_vote_changed', [__CLASS__, 'bust_search_cache'], 10, 0);
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

        // Require 2–100 chars to search
        $qLen = mb_strlen($q);
        if ($qLen < 2 || $qLen > 100) {
            return rest_ensure_response(['results' => [], 'categories' => SearchRepository::getCategories()]);
        }

        // Return cached results if available.
        $cache_version = get_option(self::SEARCH_VERSION_KEY, 1);
        $cache_key     = 'search_' . md5(mb_strtolower($q) . '|' . mb_strtolower($type) . '|' . $cache_version);
        $cached        = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $cap = $this->getCandidateCap($q);

        // ── Phase 1: Lightweight candidate query (via repository) ───────
        $candidate_rows = SearchRepository::searchCandidates($q, $type, $cap);

        if (empty($candidate_rows)) {
            $response = ['results' => [], 'categories' => SearchRepository::getCategories()];
            wp_cache_set($cache_key, $response, self::CACHE_GROUP, self::SEARCH_CACHE_TTL);
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
        // Wrapped in try/catch so search still returns results (scoreless)
        // when the trust engine is unavailable or throws.
        $scores_by_id = [];
        if (class_exists('\\BCC\\Core\\ServiceLocator')) {
            try {
                $scores_by_id = ServiceLocator::resolveScoreReadService()->getScoresForPageIds($candidate_ids);
            } catch (\Throwable $e) {
                // Degrade gracefully — return results without trust scores.
                $scores_by_id = [];
            }
        }

        $rank_scores = [];
        foreach ($candidate_ids as $id) {
            $trust = $scores_by_id[$id]['total_score'] ?? 0.0;
            $title = $titles_by_id[$id] ?? '';
            $rank_scores[$id] = $this->computeRankScore($title, $q, $trust);
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
            $categories = SearchRepository::getCategories();
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
            'categories' => SearchRepository::getCategories(),
        ];

        wp_cache_set($cache_key, $response, self::CACHE_GROUP, self::SEARCH_CACHE_TTL);

        return rest_ensure_response($response);
    }

    /**
     * Compute a blended rank score from match relevance (40%) and trust (60%).
     */
    private function computeRankScore(string $title, string $query, float $trustScore): float
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
        $trust       = min($trustScore, 100) / 100;

        return ($trust * 0.6) + ($relevance * 0.4);
    }

    /**
     * Format hydrated DB rows into API response items.
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
            return 100;
        }
        if ($len >= 3) {
            return 80;
        }
        return 50;
    }

    /**
     * Trending: top-scored published pages.
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

        // Fast path: trust-engine read model.
        $rows = SearchRepository::getTrendingFromReadModel(self::LIMIT);
        foreach ($rows as $row) {
            $pid = (int) $row->ID;
            $winner_ids[] = $pid;
            $scores_by_id[$pid] = [
                'total_score'     => (float) $row->total_score,
                'reputation_tier' => $row->reputation_tier,
            ];
        }

        // Fallback: fetch recent IDs and sort by trust score in PHP.
        if (empty($winner_ids)) {
            $candidate_ids = SearchRepository::getFallbackPageIds(100);

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
            return rest_ensure_response(['results' => [], 'categories' => SearchRepository::getCategories()]);
        }

        $results = $this->hydrateAndFormat($winner_ids, $scores_by_id);

        $response = [
            'results'    => $results,
            'categories' => SearchRepository::getCategories(),
        ];

        wp_cache_set($cache_key, $response, self::CACHE_GROUP, self::TRENDING_CACHE_TTL);

        return rest_ensure_response($response);
    }

    public static function bust_search_cache(): void
    {
        update_option(self::SEARCH_VERSION_KEY, time(), false);
    }
}
