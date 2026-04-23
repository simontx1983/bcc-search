<?php

namespace BCC\Search\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Security\Throttle;
use BCC\Search\Services\UserSearchService;
use BCC\Search\Support\QueryQualityGate;

/**
 * User Search REST controller.
 *
 * GET /wp-json/bcc/v1/search/users?q=...&limit=...
 *
 * Deliberately isolated from SearchController:
 *   - Independent cache group (CACHE_GROUP) — no bleed into project
 *     search cache invalidation.
 *   - Independent throttle bucket name — a user-search storm doesn't
 *     starve project search and vice-versa.
 *   - No trust-engine enrichment.
 *   - No LKG / circuit breaker — the workload is cheaper and the
 *     response is much simpler, so protection is just a short TTL +
 *     rate limit.
 *
 * Reuses QueryQualityGate so junk-query rejection rules stay
 * consistent across verticals.
 */
final class UserSearchController
{
    public const NAMESPACE       = 'bcc/v1';
    public const ROUTE           = '/search/users';

    private const CACHE_GROUP    = 'bcc_search_users';
    // 45s sits inside the spec's 30–60s window; matches the typical
    // soft TTL on projects for consistent "staleness feel" to users.
    private const CACHE_TTL      = 45;

    private const DEFAULT_LIMIT  = 20;
    private const MAX_LIMIT      = 50;

    // Same shape as project search rate limiting: 10 req / 5s per
    // Throttle::allow() bucket (user_id for logged-in, /24 subnet for
    // anonymous). Independent bucket name so concurrent project +
    // user queries from the same client don't compete.
    private const RATE_LIMIT     = 10;
    private const RATE_WINDOW    = 5;

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [
                    'type'              => 'string',
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
        // Rate limit — same Throttle contract as /search but a distinct
        // bucket name so the two verticals don't share quota.
        if (!Throttle::allow('search_users', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new \WP_REST_Response([
                'code'    => 'rate_limit_exceeded',
                'message' => 'Too many requests. Please wait a few seconds.',
                'data'    => ['status' => 429],
            ], 429);
        }

        $q_raw = (string) $request->get_param('q');
        $q     = trim($q_raw);

        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // Query-quality gate — shared with project search. Junk, pure-
        // stopword, and low-entropy queries short-circuit to an empty
        // response. No DB work, no cache write.
        if (!QueryQualityGate::isSearchable($q)) {
            return new \WP_REST_Response([
                'results' => [],
                'meta'    => [
                    'count' => 0,
                    'query' => $q,
                ],
            ]);
        }

        $cache_key = 'user_search_' . md5(mb_strtolower($q) . '|' . $limit);
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return new \WP_REST_Response($cached);
        }

        try {
            $response = (new UserSearchService())->search($q, $limit);
        } catch (\RuntimeException $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-search] user search failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return new \WP_REST_Response(
                [
                    'code'    => 'user_search_unavailable',
                    'message' => 'User search is temporarily unavailable. Please retry shortly.',
                    'data'    => ['status' => 503],
                ],
                503,
                ['Retry-After' => '5']
            );
        } catch (\LogicException $e) {
            // Contract drift (missing column, etc.). Log and return 503
            // rather than 500-ing the REST stack.
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-search] user search contract drift', [
                    'error' => $e->getMessage(),
                ]);
            }
            return new \WP_REST_Response(
                [
                    'code'    => 'user_search_unavailable',
                    'message' => 'User search is temporarily unavailable. Please retry shortly.',
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
