<?php

namespace BCC\Search\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Security\Throttle;
use BCC\Search\Services\GroupSearchService;
use BCC\Search\Support\QueryQualityGate;

/**
 * Group Search REST controller.
 *
 * GET /wp-json/bcc/v1/search/groups?q=...&limit=...
 *
 * Same isolation discipline as UserSearchController:
 *   - Independent cache group (bcc_search_groups).
 *   - Independent throttle bucket (search_groups).
 *   - Shared QueryQualityGate for consistent junk rejection.
 *   - No LKG / circuit breaker — workload is cheap, TTL + rate
 *     limit are the protection.
 */
final class GroupSearchController
{
    public const NAMESPACE       = 'bcc/v1';
    public const ROUTE           = '/search/groups';

    private const CACHE_GROUP    = 'bcc_search_groups';
    private const CACHE_TTL      = 45;

    private const DEFAULT_LIMIT  = 20;
    private const MAX_LIMIT      = 50;

    private const RATE_LIMIT     = 10;
    private const RATE_WINDOW    = 5;

    /**
     * Generation counter folded into the cache key. Bumped only on a
     * group-visibility change (peepso_group_privacy) so a group flipped
     * to secret can't be served from a pre-flip cache entry. The group
     * vertical has no version key / LKG of its own, so this is the whole
     * invalidation mechanism (beyond the 45s TTL).
     */
    private const GEN_KEY = 'bcc_group_search_generation';

    /**
     * Register the privacy-driven cache-bust hooks. Wired on `init` from
     * bcc-search.php (like SearchController's) so it fires on admin/AJAX
     * saves, not only REST requests. Guarded on the one meta key that
     * changes a group's visibility.
     */
    public static function register_cache_hooks(): void
    {
        $bump = static function ($meta_id, $post_id, string $meta_key): void {
            if ($meta_key === 'peepso_group_privacy') {
                self::bustGeneration();
            }
        };
        add_action('added_post_meta', $bump, 10, 3);
        add_action('updated_post_meta', $bump, 10, 3);
        add_action('deleted_post_meta', $bump, 10, 3);
    }

    /** Advance the generation (coalesced to one write per request via shutdown). */
    private static bool $genBumpQueued = false;

    public static function bustGeneration(): void
    {
        if (self::$genBumpQueued) {
            return;
        }
        self::$genBumpQueued = true;
        add_action('shutdown', [__CLASS__, 'flushGenBumpIfQueued'], 0);
    }

    public static function flushGenBumpIfQueued(): void
    {
        if (!self::$genBumpQueued) {
            return;
        }
        self::$genBumpQueued = false;
        $gen = time();
        update_option(self::GEN_KEY, $gen, false);
        wp_cache_set(self::GEN_KEY, $gen, self::CACHE_GROUP, self::CACHE_TTL);
    }

    /** Current generation: cache first, option fallback, safe floor of 1. */
    private static function getGeneration(): int
    {
        $cached = wp_cache_get(self::GEN_KEY, self::CACHE_GROUP);
        if (is_int($cached) || (is_string($cached) && ctype_digit($cached))) {
            return max(1, (int) $cached);
        }
        $opt = get_option(self::GEN_KEY, 1);
        $gen = (is_int($opt) || (is_string($opt) && ctype_digit($opt))) ? max(1, (int) $opt) : 1;
        wp_cache_set(self::GEN_KEY, $gen, self::CACHE_GROUP, self::CACHE_TTL);
        return $gen;
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [
                    'type'              => 'string',
                    // Reject oversized queries at the REST layer (400)
                    // before handler work; QueryQualityGate still enforces
                    // the searchable window as defense-in-depth. maxLength
                    // needs the explicit validate_callback to be enforced.
                    'maxLength'         => 100,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
                'limit' => [
                    'type'              => 'integer',
                    'default'           => self::DEFAULT_LIMIT,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!Throttle::allow('search_groups', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new \WP_REST_Response([
                'code'    => 'bcc_rate_limited',
                'message' => 'Too many requests. Please wait a few seconds.',
                'data'    => ['status' => 429],
            ], 429);
        }

        $q     = trim((string) $request->get_param('q'));
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        if (!QueryQualityGate::isSearchable($q)) {
            return new \WP_REST_Response([
                'results' => [],
                'meta'    => [
                    'count' => 0,
                    'query' => $q,
                ],
            ]);
        }

        // Generation-scoped so a group flipped to secret can't linger in
        // a warm cache entry (populated pre-flip) for the 45s TTL. Secret
        // groups are excluded at query time, but that only helps on a
        // miss; the generation bumps on any peepso_group_privacy change
        // (register_cache_hooks), making prior entries unreachable at once
        // — "privacy is a binary invisibility guarantee, not a freshness
        // budget," same principle the pages vertical applies.
        $cache_key = 'group_search_' . self::getGeneration() . '_' . md5(mb_strtolower($q) . '|' . $limit);
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return new \WP_REST_Response($cached);
        }

        try {
            $response = (new GroupSearchService())->search($q, $limit);
        } catch (\RuntimeException $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-search] group search failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return new \WP_REST_Response(
                [
                    'code'    => 'bcc_upstream_unavailable',
                    'message' => 'Group search is temporarily unavailable. Please retry shortly.',
                    'data'    => ['status' => 503],
                ],
                503,
                ['Retry-After' => '5']
            );
        } catch (\LogicException $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-search] group search contract drift', [
                    'error' => $e->getMessage(),
                ]);
            }
            return new \WP_REST_Response(
                [
                    'code'    => 'bcc_upstream_unavailable',
                    'message' => 'Group search is temporarily unavailable. Please retry shortly.',
                    'data'    => ['status' => 503],
                ],
                503,
                ['Retry-After' => '5']
            );
        }

        wp_cache_set($cache_key, $response, self::CACHE_GROUP, self::CACHE_TTL);

        return new \WP_REST_Response($response);
    }
}
