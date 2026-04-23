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
        if (!Throttle::allow('search_groups', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new \WP_REST_Response([
                'code'    => 'rate_limit_exceeded',
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

        $cache_key = 'group_search_' . md5(mb_strtolower($q) . '|' . $limit);
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
                    'code'    => 'group_search_unavailable',
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
                    'code'    => 'group_search_unavailable',
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
