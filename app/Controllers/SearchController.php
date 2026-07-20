<?php

namespace BCC\Search\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Core\Security\Throttle;
use BCC\Core\ServiceLocator;
use BCC\Search\Repositories\SearchRepository;
use BCC\Search\Support\QueryQualityGate;

class SearchController
{
    const NAMESPACE    = 'bcc/v1';
    const ROUTE        = '/search';
    const LIMIT            = 12;

    /**
     * `_bcc_page_type` meta value → frontend card-route prefix. The
     * result `page_url` MUST be the relative Next.js route the headless
     * frontend links to (`/v/{slug}` etc.), never a WP-origin permalink —
     * an absolute WP URL navigates the user off the app (the same defect
     * fixed for the users vertical in v1.46).
     *
     * This mirrors bcc-trust's canonical mapping — `PageTypeMap`
     * (PAGE_TYPE_TO_KIND: validator→validator, builder→project, nft→creator)
     * composed with `CardsSearchEndpoint::KIND_TO_ROUTE_PREFIX`
     * (validator→/v/, project→/p/, creator→/c/). bcc-search can't import
     * those (it depends only on bcc-core), so the collapsed page_type→prefix
     * map is duplicated here. Adding a card kind means updating this map in
     * lockstep with those two constants.
     *
     * @var array<string, string>
     */
    private const PAGE_TYPE_ROUTE_PREFIX = [
        'validator' => '/v/',
        'builder'   => '/p/',
        'nft'       => '/c/',
    ];

    /**
     * Default card type for a peepso-page whose `_bcc_page_type` meta is
     * empty/missing. Mirrors bcc-trust's canonical `?: 'builder'` default
     * so an untyped page still resolves to an in-app route rather than a
     * WP permalink. Keep in lockstep with PageReadModelRepository /
     * PageCardPrefetcher / CardViewService.
     */
    private const PAGE_TYPE_DEFAULT = 'builder';

    const SEARCH_CACHE_TTL    = 60;    // seconds
    const TRENDING_CACHE_TTL  = 300;   // 5 minutes
    const SEARCH_VERSION_KEY = 'bcc_search_cache_version';
    // LKG generation. Unlike SEARCH_VERSION_KEY, the LKG mirror is
    // deliberately NOT version-scoped (it must survive version bumps to
    // serve cold-miss losers). That makes it blind to a page becoming
    // invisible: a normal version bump can't reach it. This generation is
    // folded into every LKG key and bumped ONLY when page visibility
    // changes (peepso_page_privacy), so an ordinary content edit leaves
    // LKG intact while a privacy flip makes every prior LKG entry
    // unreachable — closing the "secret page served from LKG for up to
    // an hour on a degraded path" gap without weakening LKG otherwise.
    const SEARCH_LKG_GEN_KEY = 'bcc_search_lkg_generation';
    const CACHE_GROUP        = 'bcc_search';
    const RATE_LIMIT         = 10;  // max requests
    const RATE_WINDOW        = 5;   // seconds
    // LKG (last-known-good) mirror TTL. LKG entries are NOT version-scoped
    // so they survive cache_version bumps. A cold-miss stampede loser or a
    // trust-engine enrichment failure returns the LKG payload as a 200
    // rather than a 503 Retry-After. 1h balances "still useful during
    // recovery" vs "doesn't pin fossilised data forever".
    const LKG_CACHE_TTL      = 3600;
    // Pre-rank cap: keep top-K candidates by text relevance before calling
    // trust-engine enrichment. Enriching all 100 candidates just to pick
    // 12 winners wasted ~4× the enrichment work per search.
    const PRERANK_TOP_K      = 24;

    // Query-quality gate: TTL for the short-circuit cache on rejected
    // junk queries (aaaa, 1111, ----, pure-stopword-only). Kept short so
    // a legitimate stopword-list update flushes the gate quickly; long
    // enough to absorb burst spam of the same query. No LKG is written
    // for junk — they never reach the good-data mirror.
    const JUNK_CACHE_TTL     = 30;

    // Verified-empty LKG: how many consecutive error-free empty rebuilds
    // are required before we promote an empty response into LKG. Below
    // the threshold, empty responses are still cached at the version-
    // scoped key (to suppress re-query stampede) but not mirrored to
    // LKG — the rule is "LKG is good-data-only" and a single empty
    // response could still mask a transient upstream issue. After the
    // threshold, losers serve the empty LKG as 200 instead of cascading
    // to rebuild.
    const EMPTY_STABILITY_THRESHOLD = 2;
    const EMPTY_STABILITY_TTL       = 3600;

    // ── Circuit breaker: endpoint-level overload protection ────────────
    //
    // The stampede lock is per-(q, type, version). Under a sudden
    // cache-wide version bump (save_post fan-out, multi-node cache
    // flush), hundreds of DISTINCT cache_keys can all be cold at once.
    // Each one's lock winner legitimately proceeds to rebuild — so N
    // unique queries means N concurrent rebuilds, each doing a full
    // searchCandidates + enrichScoresIfAvailable + hydrate cycle.
    //
    // The breaker caps global rebuild concurrency per node by counting
    // rebuild *starts* in a 10s window. Above BREAKER_REBUILD_THRESHOLD
    // rebuilds in-window, the breaker trips for BREAKER_TRIP_TTL and
    // every new would-be rebuilder serves LKG (200) or 503 instead of
    // starting another expensive pipeline. Cache hits remain unaffected.
    const BREAKER_WINDOW_SEC        = 10;
    const BREAKER_REBUILD_THRESHOLD = 50;
    const BREAKER_TRIP_TTL          = 30;
    const BREAKER_TRIPPED_KEY       = 'bcc_search_breaker_tripped';

    /**
     * Resolve the concurrent-rebuilds cap via filter. Defaults to
     * MAX_CONCURRENT_REBUILDS. Ops can tune capacity per-node without
     * redeploying code:
     *
     *     add_filter('bcc_search_max_concurrent_rebuilds', fn() => 40);
     *
     * Floored at 1 so a bad filter value can't disable the gate.
     */
    private static function resolveMaxConcurrentRebuilds(): int
    {
        $v = (int) apply_filters('bcc_search_max_concurrent_rebuilds', self::MAX_CONCURRENT_REBUILDS);
        return max(1, $v);
    }

    /**
     * Resolve the breaker-trip threshold via filter.
     *
     *     add_filter('bcc_search_breaker_rebuild_threshold', fn() => 100);
     */
    private static function resolveBreakerRebuildThreshold(): int
    {
        $v = (int) apply_filters('bcc_search_breaker_rebuild_threshold', self::BREAKER_REBUILD_THRESHOLD);
        return max(1, $v);
    }
    // Rebuild lock TTL. Bumped from 20→45 after observing that a degraded
    // trust engine can push enrichment latency into the 5–15s range, and
    // a 20s TTL would then let the lock auto-expire mid-rebuild, allowing
    // a second rebuilder to start for the same key and pile more load
    // onto the already-struggling read model. 45s is the worst-case
    // budget for (searchCandidates + enrichScoresIfAvailable + hydrate +
    // cache writes) under observed tail latencies, plus margin. Post-
    // SIGKILL degradation is bounded at 45s per key, which LKG fallback
    // covers cleanly.
    const REBUILD_LOCK_TTL   = 45;

    // Concurrency gate: even with the breaker, we cap the number of
    // in-flight rebuilds globally. Rebuilders that would push us past
    // this cap serve LKG/503 without starting heavy work. Counter lives
    // in the object cache with a TTL safety net so a SIGKILL'd worker's
    // un-decremented slot self-expires.
    const MAX_CONCURRENT_REBUILDS = 20;
    const ACTIVE_REBUILDS_KEY     = 'bcc_search_active_rebuilds';
    // TTL > REBUILD_LOCK_TTL so a stuck worker's slot outlives its own
    // lock (the lock will be released first via TTL expiry; the gauge
    // will recover on the next natural reset).
    const ACTIVE_REBUILDS_TTL     = 90;

    /**
     * Gate: return a 503 if PeepSo is not loaded.
     *
     * This plugin exists to search PeepSo pages; every result's page_url
     * and avatar_url is built from PeepSo's page-root and asset URI. If
     * PeepSo is deactivated at runtime (older WP without Requires Plugins
     * enforcement, a multisite subsite with PeepSo inactive, an admin who
     * disables PeepSo before bcc-search), responding 200 would emit
     * broken relative URLs like `pages/123/xyz-avatar-full.jpg` into the
     * client. Fail visibly instead.
     *
     * Log rate-limited to once per hour per node via wp_cache_add — an
     * atomic test-and-set that cannot double-log under concurrency.
     */
    private function peepsoOrFail(): ?\WP_REST_Response
    {
        if (class_exists('PeepSo')) {
            return null;
        }
        if (class_exists('\\BCC\\Core\\Log\\Logger')
            && wp_cache_add('bcc_search_no_peepso_logged', 1, self::CACHE_GROUP, 3600)
        ) {
            \BCC\Core\Log\Logger::error('[bcc-search] PeepSo not loaded at REST time — search disabled');
        }
        return new \WP_REST_Response(
            [
                'code'    => 'dependency_unavailable',
                'message' => 'Search is temporarily unavailable.',
                'data'    => ['status' => 503],
            ],
            503,
            ['Retry-After' => '60']
        );
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
            $assets['default_avatar'] = esc_url_raw(\PeepSo::get_asset('images/avatar/page.png'));
        }

        return $assets;
    }

    /**
     * Register cache-busting hooks.
     * Called on 'init' so they fire on admin saves, not just REST requests.
     *
     * Trust-score events (endorsement/vote/recalc/dispute_resolved) are
     * intentionally NOT wired up. The previous policy was to bump the
     * global cache version on every score mutation; under any meaningful
     * write rate that pattern destroyed the cache faster than it could
     * be populated, forcing every incoming search into a cold-miss
     * rebuild and turning the stampede-lock loser branch into a 503
     * retry storm. SEARCH_CACHE_TTL (60s) is the freshness budget for
     * trust-score drift in search results. Only events that change
     * which pages EXIST, what category they belong to, or whether a page
     * is VISIBLE bust the cache: page save/delete, category save/delete,
     * and page-privacy changes.
     *
     * Privacy is load-bearing here: `peepso_page_privacy = 2` (secret)
     * is excluded from every read query, but PeepSo flips that value via
     * `update_post_meta` — which does NOT fire `save_post`. Without the
     * meta hooks below, a page flipped to secret kept serving from the
     * version-scoped cache until its soft TTL (~90s) AND from the
     * non-version-scoped LKG mirror for up to LKG_CACHE_TTL (1h) on any
     * degraded-path serve. Privacy is a binary invisibility guarantee,
     * not a freshness budget, so the change must bust immediately.
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

        // Page-privacy meta changes (add/update/delete) bust immediately —
        // the meta hooks carry the key, so we bust only on the one key
        // that changes page visibility. Over-busting on an unrelated key
        // never happens (guarded on meta_key); a genuine privacy flip
        // costs one rebuild, which is the correct price for a visibility
        // change. Also busts LKG (see bust_search_cache).
        $bustOnPrivacyMeta = static function ($meta_id, $post_id, string $meta_key): void {
            if ($meta_key === 'peepso_page_privacy') {
                self::bust_search_privacy();
            }
        };
        add_action('added_post_meta', $bustOnPrivacyMeta, 10, 3);
        add_action('updated_post_meta', $bustOnPrivacyMeta, 10, 3);
        add_action('deleted_post_meta', $bustOnPrivacyMeta, 10, 3);
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
                    // Reject oversized queries at the REST validation layer
                    // (400) before sanitize/handler work runs. maxLength is
                    // only enforced when validate_callback is set explicitly
                    // (WP does not auto-validate manually-registered args).
                    // The handler still enforces the 2..100 window as
                    // defense-in-depth.
                    'maxLength'         => 100,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
                'type' => [
                    'type'              => 'string',
                    'maxLength'         => 64,
                    'validate_callback' => 'rest_validate_request_arg',
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

    public function handle_search(\WP_REST_Request $request): \WP_REST_Response
    {
        // Rate limiting by client IP.
        // Delegates to bcc-core's Throttle which tries trust-engine's atomic
        // RateLimiter first, then ext object cache, then transients.
        //
        // Pass $key=null: Throttle builds the key itself using user_id for
        // logged-in users and IpResolver::resolve() + /24 IPv4 (or /64 IPv6)
        // subnet normalisation for anonymous callers. Passing an explicit
        // per-exact-IP key (the old behaviour) would bypass that subnet
        // normalisation, so rotating-IP abuse (mobile NAT, cheap VPN pools)
        // got its own bucket per request. The rest of the BCC ecosystem
        // uses the same normalised scheme.
        if (!Throttle::allow('search', self::RATE_LIMIT, self::RATE_WINDOW)) {
            // Mirror WP_Error payload shape so clients don't have to special-case
            // rate-limit responses. Return WP_REST_Response (not WP_Error) so the
            // controller has a single narrow return type.
            return new \WP_REST_Response([
                'code'    => 'bcc_rate_limited',
                'message' => 'Too many requests. Please wait a few seconds.',
                'data'    => ['status' => 429],
            ], 429);
        }

        // PeepSo must be loaded: every result's avatar_url/page_url is
        // built from PeepSo page-root config. Without PeepSo we'd emit
        // broken relative URLs and lie to the client.
        if ($response = $this->peepsoOrFail()) {
            return $response;
        }

        // ── Trending: top-scored pages, no query needed ──────────────────
        if ($request->get_param('trending') === '1') {
            return $this->handle_trending();
        }

        // get_param returns mixed; sanitize_text_field should yield string,
        // but coerce explicitly — trim(null) is a deprecation warning on 8.1+.
        $q    = trim((string) $request->get_param('q'));
        $type = trim((string) $request->get_param('type'));

        // Fetch categories once per request — used for validation and response.
        // getCategories() throws RuntimeException on DB error (so a broken
        // query can't poison the 12h-cached category list). Serve a
        // generic 503 rather than letting the exception escape — we have
        // no LKG key yet at this point.
        try {
            $categories = SearchRepository::getCategories();
        } catch (\RuntimeException $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-search] getCategories failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return new \WP_REST_Response(
                [
                    'code'    => 'bcc_upstream_unavailable',
                    'message' => 'Search is temporarily unavailable. Please retry shortly.',
                    'data'    => ['status' => 503],
                ],
                503,
                ['Retry-After' => '5']
            );
        }

        // Validate $type against known category slugs to prevent wasted
        // DB queries on nonexistent categories and limit the SQL surface.
        // Empty-result short-circuits below return `categories: []` rather
        // than the full list — autocomplete fires these per keystroke and
        // nothing in the frontend reads categories off an empty response
        // (contract v1.46). Real result responses keep the full list.
        if ($type !== '') {
            $validSlugs = array_column($categories, 'slug');
            if (!in_array($type, $validSlugs, true)) {
                return new \WP_REST_Response(['results' => [], 'categories' => []]);
            }
        }

        // Require 2–100 chars to search
        $qLen = mb_strlen($q);
        if ($qLen < 2 || $qLen > 100) {
            return new \WP_REST_Response(['results' => [], 'categories' => []]);
        }

        // Query-quality gate (shared QueryQualityGate — single source of
        // truth across verticals; the private copy this controller carried
        // through Phase 1 is gone). Runs BEFORE the version-scoped cache
        // lookup so junk never hits wp_cache OR the DB: at 500 req/s of
        // "aaaa", even a cache-hit path is wasted work. Junk gets a
        // dedicated short-TTL cache under its own key (not version-scoped
        // — so a page-save bust doesn't evict it and force re-evaluation).
        if (!QueryQualityGate::isSearchable($q)) {
            $junk_key = 'search_junk_' . md5(mb_strtolower($q) . '|' . mb_strtolower($type));
            $junk_cached = wp_cache_get($junk_key, self::CACHE_GROUP);
            if (is_array($junk_cached)) {
                return new \WP_REST_Response($junk_cached);
            }
            $response = ['results' => [], 'categories' => []];
            wp_cache_set($junk_key, $response, self::CACHE_GROUP, self::JUNK_CACHE_TTL);
            return new \WP_REST_Response($response);
        }

        // Return cached results if available.
        // The cache stores a wrapper: ['data' => ..., 'expires_at' => timestamp].
        // 'expires_at' is the SOFT expiry (when the data becomes stale).
        // The actual wp_cache TTL is longer (stale buffer) so that a
        // stale-while-revalidate pattern can serve old data while one
        // worker rebuilds the entry.
        $cache_version = self::getCacheVersion();
        // Visibility-bucket: logged-in vs logged-out. PeepSo can gate
        // certain pages/categories by login state via visibility ACL;
        // if that ACL applies, a member's primed cache would otherwise
        // leak to a non-member hitting the same $q/$type. Separating
        // buckets prevents cross-audience cache poisoning regardless of
        // whether the site currently enables closed pages.
        $visibility_bucket = is_user_logged_in() ? 'in' : 'out';
        $key_fingerprint   = mb_strtolower($q) . '|' . mb_strtolower($type) . '|' . $visibility_bucket;
        $cache_key         = 'search_' . md5($key_fingerprint . '|' . $cache_version);
        // LKG: identical fingerprint, content version omitted so it
        // survives version bumps (cold-miss losers / enrichment failures
        // serve a 200 instead of a 503). It IS scoped by the LKG
        // generation, which advances only on a visibility change, so a
        // page flipped to secret can't be served from a stale LKG entry.
        //
        // The generation read is a non-autoloaded option (a DB SELECT on a
        // non-persistent cache), so the full lkg_key is built LAZILY — only
        // on the paths that actually touch LKG (stale-lock-winner rebuild
        // and the cold branch). Warm hits and stale-lock losers return
        // without ever reading the generation. The fingerprint half is
        // cheap and precomputed here.
        $lkg_fingerprint   = md5($key_fingerprint);
        $lkg_key           = '';
        $cached            = wp_cache_get($cache_key, self::CACHE_GROUP);

        $lock_key = 'bcc_search_lock_' . md5($cache_key);

        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
            $isFresh = isset($cached['expires_at']) && time() < $cached['expires_at'];

            if ($isFresh) {
                // Cache is fresh — serve immediately (no LKG read).
                return new \WP_REST_Response($cached['data']);
            }

            // Cache is stale but still in the buffer window.
            // Try to acquire the rebuild lock (atomic via MySQL GET_LOCK).
            // If another worker is already rebuilding, serve stale data
            // instead of blocking.
            if (!\BCC\Core\DB\AdvisoryLock::acquire($lock_key, 0)) {
                // Another worker is rebuilding — serve stale data (no LKG read).
                return new \WP_REST_Response($cached['data']);
            }
            // We won the lock — build the LKG key now (the rebuild path uses it).
            $lkg_key = 'search_lkg_' . self::getLkgGeneration() . '_' . $lkg_fingerprint;
            // Fall through to rebuild below.
        } else {
            // Cold branch: every sub-path (loser serves LKG, winner rebuilds
            // and writes it) needs the LKG key.
            $lkg_key = 'search_lkg_' . self::getLkgGeneration() . '_' . $lkg_fingerprint;
            // ── Stampede protection for cold cache (no stale entry to serve) ──
            //
            // Previous implementation busy-waited up to 1.5s for the winner,
            // then fell through and built a second time if the winner hadn't
            // finished. Under 100–1000 concurrent users that held a PHP-FPM
            // worker hostage per loser and cascaded to a pool-exhaustion
            // outage of the whole site. New policy:
            //   - Try to acquire the lock (MySQL GET_LOCK — atomic across
            //     all DB sessions, regardless of cache backend).
            //   - If lost: return 503 Retry-After: 1. The frontend re-polls
            //     after ~1s, by which time the winner has populated the
            //     cache. Worker is released immediately.
            //   - Never fall through and build concurrently — that defeats
            //     the entire purpose of the lock.
            if (!\BCC\Core\DB\AdvisoryLock::acquire($lock_key, 0)) {
                // Cold-miss loser. Try LKG first — it survives version
                // bumps, so during a cache-version rotation we still
                // have a valid (slightly stale) payload to serve. Only
                // fall through to 503 when nothing is available at all
                // (truly cold endpoint or LKG-evicted query).
                $lkg = wp_cache_get($lkg_key, self::CACHE_GROUP);
                if (is_array($lkg)) {
                    $lkg['stale']           = true;
                    $lkg['system_degraded'] = true;
                    return new \WP_REST_Response($lkg);
                }
                return new \WP_REST_Response(
                    [
                        'code'    => 'bcc_upstream_unavailable',
                        'message' => 'Search is warming up. Please retry shortly.',
                        'data'    => ['status' => 503],
                    ],
                    503,
                    ['Retry-After' => '1']
                );
            }
        }

        $slot_reserved = false;
        try {
            // Loud warning (rate-limited to 1/h per node) if we're
            // entering the rebuild path without persistent cache —
            // all of breaker/gauge/LKG are inoperative in that
            // configuration.
            self::warnIfNonPersistentCacheAtRebuild();

            // Circuit breaker: if too many rebuilds have started in the
            // recent window (cache-wide version bump, trust-engine
            // latency spike pushing rebuilds past their budget), stop
            // doing heavy work and serve LKG/503 until the breaker
            // cools. Winner still releases the lock in the finally.
            if (!self::circuitBreakerAllowsRebuild()) {
                return $this->breakerTrippedResponse($lkg_key);
            }
            // Concurrency gate — bounded by resolveMaxConcurrentRebuilds()
            // so a trust-engine slowdown can't pile up N rebuilders on
            // the same struggling read model beyond the cap.
            if (!self::reserveRebuildSlot()) {
                return $this->breakerTrippedResponse($lkg_key);
            }
            $slot_reserved = true;
            self::recordRebuildAndMaybeTrip();

            $cap = $this->getCandidateCap($q);

            // ── Phase 1: Lightweight candidate query (via repository) ───────
            // RuntimeException here = repository detected $wpdb->last_error.
            // Treat as transient upstream failure: serve LKG if we have it,
            // else 503. Crucially we do NOT write ANY cache in this path —
            // an empty-due-to-DB-error payload that poisoned LKG would
            // silently pin "no results" for LKG_CACHE_TTL.
            try {
                $candidate_rows = SearchRepository::searchCandidates($q, $type, $cap);
            } catch (\RuntimeException $e) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-search] searchCandidates failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
                return $this->breakerTrippedResponse($lkg_key);
            }

            if (empty($candidate_rows)) {
                $response = ['results' => [], 'categories' => $categories];
                $this->cacheSearchResult($cache_key, $lkg_key, $response);
                return new \WP_REST_Response($response);
            }

            $titles_by_id = [];
            foreach ($candidate_rows as $row) {
                $titles_by_id[$row->id] = $row->title;
            }

            // ── Phase 2: Pre-rank by text relevance, then enrich top-K ──────
            // Previous policy enriched ALL candidates (up to 100) then picked
            // 12. That wasted ~4× the trust-engine round-trip work per search
            // and put proportional read-model load on every rebuild. Text
            // relevance is cheap and purely string-based — compute it for
            // all candidates, keep the top PRERANK_TOP_K, and enrich only
            // those.
            $text_scores = [];
            foreach ($candidate_rows as $row) {
                $text_scores[$row->id] = $this->computeTextScore($row->title, $q);
            }
            arsort($text_scores);
            $prerank_ids = array_slice(array_keys($text_scores), 0, self::PRERANK_TOP_K);

            // Use enriched scores (same composite ranking as /discover) so
            // search and discovery produce consistent trust-based ordering.
            $enrich_failed = false;
            $scores_by_id  = self::enrichScoresIfAvailable($prerank_ids, $enrich_failed);

            if ($enrich_failed) {
                // Trust-engine is active but threw. Silently returning a
                // text-only ranking would let low-trust/spam content
                // surface — a trust-manipulation surface. Prefer stale
                // cache when available, then LKG, else 503.
                if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
                    return new \WP_REST_Response($cached['data']);
                }
                $lkg = wp_cache_get($lkg_key, self::CACHE_GROUP);
                if (is_array($lkg)) {
                    $lkg['stale']           = true;
                    $lkg['system_degraded'] = true;
                    return new \WP_REST_Response($lkg);
                }
                return new \WP_REST_Response(
                    [
                        'code'    => 'bcc_internal',
                        'message' => 'Temporarily unavailable. Please retry shortly.',
                        'data'    => ['status' => 503],
                    ],
                    503,
                    ['Retry-After' => '5']
                );
            }

            $rank_scores = [];
            foreach ($prerank_ids as $id) {
                $ranking            = $scores_by_id[$id]['ranking_score'] ?? 0.0;
                $text               = $text_scores[$id] ?? 0.0;
                $rank_scores[$id]   = $this->blendRankScore($text, $ranking);
            }

            // §J anti-impersonation guarantee: when a claim-verified canonical
            // page shares its (normalised) name with one or more unverified
            // look-alikes, every same-name look-alike MUST sort strictly BELOW
            // the verified page(s). Without this, a high-text-relevance
            // impostor could outrank the real verified page for its own name.
            // Demote-only — no candidate is removed and cross-name ordering is
            // disturbed only minimally (an epsilon clamp). Bounded O(n) over the
            // ≤ PRERANK_TOP_K candidates; uses the already-enriched score map so
            // there are no new DB calls.
            $verified_min_by_name = [];   // normalised name => min verified rank
            $unverified_by_name   = [];   // normalised name => list<int> ids
            foreach ($prerank_ids as $id) {
                $name = $titles_by_id[$id] ?? '';
                $key  = strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
                $is_claim_verified = (bool) ($scores_by_id[$id]['is_claim_verified'] ?? false);
                if ($is_claim_verified) {
                    $verified_min_by_name[$key] = isset($verified_min_by_name[$key])
                        ? min($verified_min_by_name[$key], $rank_scores[$id])
                        : $rank_scores[$id];
                } else {
                    $unverified_by_name[$key][] = $id;
                }
            }
            $epsilon = 1e-6;
            foreach ($unverified_by_name as $key => $ids) {
                if (!isset($verified_min_by_name[$key])) {
                    continue;
                }
                $ceiling = $verified_min_by_name[$key] - $epsilon;
                foreach ($ids as $id) {
                    $rank_scores[$id] = min($rank_scores[$id], $ceiling);
                }
            }

            usort($prerank_ids, static function (int $a, int $b) use ($rank_scores): int {
                return ($rank_scores[$b] <=> $rank_scores[$a]) ?: ($a <=> $b);
            });

            $winner_ids = array_slice($prerank_ids, 0, self::LIMIT);

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

            try {
                $results = $this->hydrateAndFormat($winner_ids, $scores_by_id, $filtered_cat_name, $filtered_cat_slug);
            } catch (\RuntimeException $e) {
                // hydratePages DB error. Serving [] now would be truthful
                // ("we couldn't build the response") but caching [] as
                // LKG would poison subsequent losers for an hour. Same
                // LKG/503 escape hatch as the candidate-query path.
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-search] hydratePages failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
                return $this->breakerTrippedResponse($lkg_key);
            }

            $response = [
                'results'    => $results,
                'categories' => $categories,
            ];

            $this->cacheSearchResult($cache_key, $lkg_key, $response);

            return new \WP_REST_Response($response);
        } finally {
            // Losers return 503 before entering the try-block and stale-hit
            // callers return before it as well, so we always own the lock
            // by the time this block runs.
            \BCC\Core\DB\AdvisoryLock::release($lock_key);
            if ($slot_reserved) {
                self::releaseRebuildSlot();
            }
        }
    }

    /**
     * Decide whether an empty response has been confirmed stable enough
     * to be promoted into LKG.
     *
     * A successful-but-empty rebuild increments a per-fingerprint
     * counter (LKG key salted with a literal so it can't collide with
     * another LKG entry). Once the counter reaches
     * EMPTY_STABILITY_THRESHOLD the verdict flips to "true" and the
     * caller writes the empty payload into LKG. Without this promotion,
     * a query that is legitimately empty most of the time would force
     * every cold-miss loser into a rebuild — exactly the workload the
     * LKG mirror is supposed to absorb.
     *
     * Returns false on non-atomic-incr backends (we'd rather under-
     * promote than risk a race-to-write producing false stability).
     */
    private static function isEmptyResultVerifiedStable(string $lkg_key): bool
    {
        if (!wp_using_ext_object_cache()) {
            return false;
        }
        $counter_key = 'bcc_search_empty_count_' . md5($lkg_key);
        wp_cache_add($counter_key, 0, self::CACHE_GROUP, self::EMPTY_STABILITY_TTL);
        $count = wp_cache_incr($counter_key, 1, self::CACHE_GROUP);
        if (!is_int($count)) {
            return false;
        }
        return $count >= self::EMPTY_STABILITY_THRESHOLD;
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
     * Also writes an LKG (last-known-good) mirror that is NOT version-
     * scoped, so losers on a post-version-bump cold miss and enrichment
     * failures can serve a 200 rather than a 503.
     *
     * @param string              $cache_key
     * @param string              $lkg_key
     * @param array<string,mixed> $response
     */
    private function cacheSearchResult(string $cache_key, string $lkg_key, array $response): void
    {
        $jitter    = (int) (self::SEARCH_CACHE_TTL * 0.2);                 // ±20%
        $softTtl   = self::SEARCH_CACHE_TTL + random_int(-$jitter, $jitter); // 48-72s
        $hardTtl   = $softTtl + 30;                                         // stale buffer

        $wrapper = [
            'data'       => $response,
            'expires_at' => time() + $softTtl,
        ];

        // Version-scoped cache: always written. Suppresses re-query
        // stampedes for legitimately empty queries until the next
        // version bump.
        wp_cache_set($cache_key, $wrapper, self::CACHE_GROUP, $hardTtl);

        // LKG mirror policy:
        //   - Non-empty: always write (standard last-known-good).
        //   - Empty + verified stable: write too (see
        //     isEmptyResultVerifiedStable). Queries that are legitimately
        //     empty most of the time still need LKG so cold-miss losers
        //     don't cascade to rebuild on every hit.
        //   - Empty + not-yet-verified: skip. A single empty rebuild
        //     could be masking a transient upstream failure that
        //     returned "no rows" — promotion after N consecutive
        //     error-free empties avoids that poisoning surface.
        if (!empty($response['results'])) {
            wp_cache_set($lkg_key, $response, self::CACHE_GROUP, self::LKG_CACHE_TTL);
        } elseif (self::isEmptyResultVerifiedStable($lkg_key)) {
            wp_cache_set($lkg_key, $response, self::CACHE_GROUP, self::LKG_CACHE_TTL);
        }
    }

    /**
     * Cheap, purely string-based text-relevance score in [0,1].
     *
     * Computed for every candidate (up to the candidate cap) before trust
     * enrichment so we can pre-rank and only enrich the top-K. Isolated
     * from trust blending so it is safe to call without a trust-engine
     * present.
     */
    private function computeTextScore(string $title, string $query): float
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
        return ($matchScore * 0.6) + ($lengthBonus * 0.4);
    }

    /**
     * Blend pre-computed text-relevance score with trust ranking.
     *
     * $textScore is the [0,1] output of computeTextScore().
     * $compositeScore is ranking_score from the trust engine read model
     * (same composite formula PageDiscoveryService uses for the /cards
     * listing). Unbounded (typically 0–80); normalised via soft cap at
     * 80 before blending.
     *
     * Output weighting is 60% trust / 40% text relevance.
     */
    private function blendRankScore(float $textScore, float $compositeScore): float
    {
        $trust = min($compositeScore / 80.0, 1.0);
        return ($trust * 0.6) + ($textScore * 0.4);
    }

    /**
     * Format hydrated DB rows into API response items.
     *
     * Field names are aligned with the /cards listing shape so the
     * frontend can consume both endpoints with the same component logic.
     *
     * @param int[] $winnerIds
     * @param array<int, array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, is_claim_verified?: bool, follower_count: int}> $scoresById
     * @return list<array{page_id: int, page_name: string, page_url: string, avatar_url: string, trust_score: int|null, tier: string|null, endorsements: int, verified: bool, is_claim_verified: bool, followers: int, category: string|null, category_slug: string|null}>
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
            $pid   = $row->id;
            $score = $scoresById[$pid] ?? null;
            $tier  = is_array($score) ? ($score['reputation_tier'] ?? null) : null;

            $hash   = $row->avatarHash ?? '';
            $avatar = $hash
                ? esc_url_raw($ps['uri'] . 'pages/' . $pid . '/' . $hash . '-avatar-full.jpg')
                : $ps['default_avatar'];

            // Relative Next.js card route (/v//p//c/{slug}) resolved from
            // the page's _bcc_page_type. The headless frontend links to
            // this verbatim; a WP-origin permalink here would navigate the
            // user off the app (the very bug this composition closes).
            //
            // An empty/missing type defaults to 'builder' → /p/, mirroring
            // bcc-trust's canonical `_bcc_page_type ?: 'builder'` default
            // (PageReadModelRepository / PageCardPrefetcher / CardViewService)
            // — a legacy or externally-minted page without the meta must
            // still get an IN-APP route, not fall off to a WP permalink.
            // The permalink branch survives only for a type that is set but
            // genuinely outside the card taxonomy (none in the current
            // catalogue — every peepso-page is validator/builder/nft).
            $effectiveType = ($row->pageType !== null && $row->pageType !== '')
                ? $row->pageType
                : self::PAGE_TYPE_DEFAULT;
            $routePrefix = self::PAGE_TYPE_ROUTE_PREFIX[$effectiveType] ?? null;
            if ($routePrefix !== null) {
                $url = $routePrefix . $row->slug;
            } else {
                $url = $ps['url_base']
                    ? $ps['url_base'] . $row->slug . '/'
                    : home_url('/pages/' . $row->slug . '/');
            }

            $results[] = [
                'page_id'       => $pid,
                'page_name'     => $row->title,
                'page_url'      => $url,
                'avatar_url'    => $avatar,
                'trust_score'   => is_array($score) ? (int) $score['total_score'] : null,
                'tier'          => $tier,
                'endorsements'  => is_array($score) ? (int) $score['endorsement_count'] : 0,
                'verified'      => is_array($score) ? (bool) $score['is_verified'] : false,
                'is_claim_verified' => is_array($score) ? (bool) ($score['is_claim_verified'] ?? false) : false,
                'followers'     => is_array($score) ? (int) $score['follower_count'] : 0,
                'category'      => $filteredCatName ?? $row->categoryName ?? null,
                'category_slug' => $filteredCatSlug ?? $row->categorySlug ?? null,
            ];
        }

        return $results;
    }

    /**
     * Resolve enriched trust scores for a set of page IDs.
     *
     * Distinguishes two failure modes:
     *   - Trust-engine not installed (class_exists() false): $failed stays
     *     false and we return []. Callers can fall back to text-only
     *     ranking because there is no trust system to lie about.
     *   - Trust-engine installed but threw: $failed is set true and we
     *     return []. Callers MUST NOT return a text-only ranking in this
     *     case — low-trust/spam content can bubble to the top, which is
     *     a trust-manipulation surface. Serve stale cache or a 503.
     *
     * @param int[] $pageIds
     * @param-out bool $failed
     * @return array<int, array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, is_claim_verified?: bool, follower_count: int}>
     */
    private static function enrichScoresIfAvailable(array $pageIds, ?bool &$failed = null): array
    {
        $failed = false;
        if (!class_exists('\\BCC\\Core\\ServiceLocator')) {
            return [];
        }
        try {
            return ServiceLocator::resolveScoreReadService()->getEnrichedScoresForPageIds($pageIds);
        } catch (\Throwable $e) {
            $failed = true;
            // Log with rate-limited dedup so ops sees sustained trust-engine
            // outages without the per-request spam a hot search endpoint
            // would otherwise produce. wp_cache_add is an atomic
            // test-and-set: at most one log line per 60s wins across all
            // concurrent workers. The prior get-then-set pattern raced
            // under concurrency and produced N duplicate lines per outage.
            if (class_exists('\\BCC\\Core\\Log\\Logger')
                && wp_cache_add('bcc_search_enrich_fail_logged', 1, self::CACHE_GROUP, 60)
            ) {
                \BCC\Core\Log\Logger::error('[bcc-search] score_enrichment_failed', [
                    'error'    => $e->getMessage(),
                    'page_cnt' => count($pageIds),
                ]);
            }
            return [];
        }
    }

    /**
     * Minimum InnoDB FT stopwords — superset of the default stopword list,
     * small enough to check in O(1) via hash lookup. Used by the query-
     * quality gate to reject queries that consist solely of stopwords
     * (which always resolve to title-prefix LIKE and are a cheap abuse
     * surface even with the rate limiter in place).
     *
     * @return array<string,bool>
     */
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
        // Same runtime-dependency gate as handle_search — results would
        // emit broken URLs without PeepSo.
        if ($response = $this->peepsoOrFail()) {
            return $response;
        }

        $cache_version = self::getCacheVersion();
        // Visibility bucket: mirrors the search path. PeepSo ACLs can hide
        // pages from logged-out callers, so a member priming this cache
        // must not leak member-only pages to a non-member (or vice versa).
        $visibility_bucket = is_user_logged_in() ? 'in' : 'out';
        $cache_key         = 'trending_' . $visibility_bucket . '_' . $cache_version;
        // LKG generation-scoped so a page flipped to secret can't linger
        // in the trending LKG mirror across a visibility change (mirrors
        // the search path). Built LAZILY — the generation read is a
        // non-autoloaded option, so warm hits and stale-lock losers (the
        // common case on a 5-min trending cache) return without paying it.
        $lkg_key           = '';
        $cached            = wp_cache_get($cache_key, self::CACHE_GROUP);

        $lock_key = 'bcc_trending_lock_' . md5($cache_key);

        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
            $isFresh = isset($cached['expires_at']) && time() < $cached['expires_at'];

            if ($isFresh) {
                return new \WP_REST_Response($cached['data']);
            }

            // Stale — try to acquire rebuild lock (atomic via MySQL GET_LOCK).
            if (!\BCC\Core\DB\AdvisoryLock::acquire($lock_key, 0)) {
                // Another worker is rebuilding — serve stale data.
                return new \WP_REST_Response($cached['data']);
            }
            // Won the lock — rebuild path uses the LKG key.
            $lkg_key = 'trending_lkg_' . self::getLkgGeneration() . '_' . $visibility_bucket;
        } else {
            // Cold branch: loser serves LKG, winner rebuilds and writes it.
            $lkg_key = 'trending_lkg_' . self::getLkgGeneration() . '_' . $visibility_bucket;
            // Cold miss — try to acquire lock.
            //
            // Previous behaviour was to return `['results' => [], 'categories' => []]`
            // to every loser, which is a silent lie: the UI rendered "no
            // trending" to real users on every cache flush/bust. Serve LKG
            // (survives version bumps) first; only 503 when even LKG is
            // unavailable.
            if (!\BCC\Core\DB\AdvisoryLock::acquire($lock_key, 0)) {
                $lkg = wp_cache_get($lkg_key, self::CACHE_GROUP);
                if (is_array($lkg)) {
                    $lkg['stale']           = true;
                    $lkg['system_degraded'] = true;
                    return new \WP_REST_Response($lkg);
                }
                return new \WP_REST_Response(
                    [
                        'code'    => 'bcc_upstream_unavailable',
                        'message' => 'Trending is warming up. Please retry shortly.',
                        'data'    => ['status' => 503],
                    ],
                    503,
                    ['Retry-After' => '1']
                );
            }
        }

        $slot_reserved = false;
        try {
            self::warnIfNonPersistentCacheAtRebuild();

            // Circuit breaker: same protection as handle_search.
            if (!self::circuitBreakerAllowsRebuild()) {
                return $this->breakerTrippedResponse($lkg_key);
            }
            if (!self::reserveRebuildSlot()) {
                return $this->breakerTrippedResponse($lkg_key);
            }
            $slot_reserved = true;
            self::recordRebuildAndMaybeTrip();

            $winner_ids   = [];
            $scores_by_id = [];
            try {
                $categories = SearchRepository::getCategories();
            } catch (\RuntimeException $e) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-search] trending getCategories failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
                return $this->breakerTrippedResponse($lkg_key);
            }

            // Fast path: trust-engine read model for trending pages.
            // LogicException from a contract drift would otherwise crash
            // the whole endpoint; downgrade to the fallback path instead.
            try {
                $rows = SearchRepository::getTrendingFromReadModel(self::LIMIT);
            } catch (\LogicException $e) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-search] trending read-model contract drift', [
                        'error' => $e->getMessage(),
                    ]);
                }
                $rows = [];
            }
            if (!empty($rows)) {
                $trending_ids = array_map(static fn($r) => $r->id, $rows);
                // Use enriched scores so trending response includes the same
                // fields as search results (endorsements, verified, followers).
                // A THROWING trust engine (vs. one that isn't installed) must
                // not be papered over with zeroed display fields and then
                // cached into LKG for an hour — mirror handle_search and
                // serve stale/LKG/503 instead. `enrich_failed` stays false
                // when there simply is no trust engine (text/read-model data
                // is a legitimate answer then).
                $trending_enrich_failed = false;
                $scores_by_id = self::enrichScoresIfAvailable($trending_ids, $trending_enrich_failed);
                if ($trending_enrich_failed) {
                    return $this->degradedEnrichResponse($cached, $lkg_key);
                }
                // Fall back to basic data from the read-model rows if enriched failed.
                foreach ($rows as $row) {
                    $pid = $row->id;
                    $winner_ids[] = $pid;
                    if (!isset($scores_by_id[$pid])) {
                        $scores_by_id[$pid] = [
                            'total_score'       => $row->totalScore,
                            'reputation_tier'   => $row->reputationTier,
                            'ranking_score'     => 0.0,
                            'endorsement_count' => 0,
                            'is_verified'       => false,
                            'is_claim_verified' => false,
                            'follower_count'    => 0,
                        ];
                    }
                }
            }

            // Fallback: fetch recent IDs and sort by composite ranking score.
            // Cap at PRERANK_TOP_K (24) rather than 100 — we only need LIMIT
            // winners and the old 100-candidate fan-out put 8× the
            // enrichment load on the trust engine for no ranking benefit
            // (candidate pool was already "most recent", not "best scored").
            if (empty($winner_ids)) {
                try {
                    $candidate_ids = SearchRepository::getFallbackPageIds(self::PRERANK_TOP_K);
                } catch (\RuntimeException $e) {
                    if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                        \BCC\Core\Log\Logger::error('[bcc-search] trending fallback query failed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                    return $this->breakerTrippedResponse($lkg_key);
                }

                if (!empty($candidate_ids)) {
                    // Same rule as the read-model path: a throwing engine
                    // here would collapse every ranking_score to 0.0 and the
                    // usort tie-break would reorder the recency-sorted pool
                    // into ascending-id (oldest-first) order — then cache
                    // that into LKG. Serve degraded instead.
                    $fallback_enrich_failed = false;
                    $scores_by_id = self::enrichScoresIfAvailable($candidate_ids, $fallback_enrich_failed);
                    if ($fallback_enrich_failed) {
                        return $this->degradedEnrichResponse($cached, $lkg_key);
                    }

                    usort($candidate_ids, static function (int $a, int $b) use ($scores_by_id): int {
                        $sa = $scores_by_id[$a]['ranking_score'] ?? 0.0;
                        $sb = $scores_by_id[$b]['ranking_score'] ?? 0.0;
                        return ($sb <=> $sa) ?: ($a <=> $b);
                    });

                    $winner_ids = array_slice($candidate_ids, 0, self::LIMIT);
                }
            }

            if (empty($winner_ids)) {
                $response = ['results' => [], 'categories' => $categories];
                $this->cacheTrendingResult($cache_key, $lkg_key, $response);
                return new \WP_REST_Response($response);
            }

            try {
                $results = $this->hydrateAndFormat($winner_ids, $scores_by_id);
            } catch (\RuntimeException $e) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-search] trending hydratePages failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
                return $this->breakerTrippedResponse($lkg_key);
            }

            $response = [
                'results'    => $results,
                'categories' => $categories,
            ];

            $this->cacheTrendingResult($cache_key, $lkg_key, $response);

            return new \WP_REST_Response($response);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock_key);
            if ($slot_reserved) {
                self::releaseRebuildSlot();
            }
        }
    }

    /**
     * Store a trending result with stale-while-revalidate semantics.
     *
     * Also writes an LKG mirror (no version scoping) so that cold-miss
     * losers during a cache-version bump serve a 200 rather than a 503.
     *
     * @param string              $cache_key
     * @param string              $lkg_key
     * @param array<string,mixed> $response
     */
    private function cacheTrendingResult(string $cache_key, string $lkg_key, array $response): void
    {
        $jitter  = (int) (self::TRENDING_CACHE_TTL * 0.2);
        $softTtl = self::TRENDING_CACHE_TTL + random_int(-$jitter, $jitter);
        $hardTtl = $softTtl + 30;

        $wrapper = [
            'data'       => $response,
            'expires_at' => time() + $softTtl,
        ];

        wp_cache_set($cache_key, $wrapper, self::CACHE_GROUP, $hardTtl);

        // LKG policy mirrors cacheSearchResult: non-empty always writes,
        // empty writes only after the stability counter confirms the
        // "no trending" state. Prevents a trust-engine outage + fallback
        // failure from pinning "no trending" into LKG for an hour while
        // still supporting the legitimately-empty-trending edge case
        // (e.g., fresh install with no pages yet).
        if (!empty($response['results'])) {
            wp_cache_set($lkg_key, $response, self::CACHE_GROUP, self::LKG_CACHE_TTL);
        } elseif (self::isEmptyResultVerifiedStable($lkg_key)) {
            wp_cache_set($lkg_key, $response, self::CACHE_GROUP, self::LKG_CACHE_TTL);
        }
    }

    /** Cache TTL used uniformly for both bust and heal writes (see getCacheVersion). */
    private const VERSION_CACHE_TTL = 60;

    /** Soft-lock window that gates the heal path so concurrent cold requests do not all hit the DB. */
    private const VERSION_HEAL_LOCK_TTL = 2;

    /**
     * Get the cache version, backed by object cache to avoid a DB hit per request.
     *
     * Defends against three distinct corruption vectors:
     *   1. Cache entry poisoned (wrong type written by another plugin, a crashed
     *      write, or cache-layer data corruption)
     *   2. Option itself polluted (bad migration, a stray pre_option filter, or
     *      admin miswrite) — the "authoritative" source can lie too
     *   3. Cold-start dogpile — many concurrent requests racing to read the
     *      option and write cache simultaneously
     *
     * Strategy:
     *   - Accept only is_int OR decimal-digit strings. is_numeric would let
     *     "1e3" through and coerce to 1000, producing plausible-looking but
     *     wrong versions.
     *   - Both the bust path and heal path write with the SAME TTL so no "forever"
     *     cache entries can accumulate in multi-node setups and defeat damping.
     *   - Heal path acquires a 2s soft-lock. Losers back off 5ms and re-read the
     *     cache; only the winner does the option read + cache set. Bounded
     *     fallback: if the winner didn't finish within 5ms, losers read options
     *     directly (correct but more DB load — converges on next tick).
     *   - On non-persistent object caches, short-circuit all damping: wp_cache_*
     *     is per-request memory and cross-request poisoning is impossible.
     */
    private static function getCacheVersion(): int
    {
        // Without a persistent object cache, damping is moot and the lock
        // gate would always succeed trivially. Just validate the option.
        if (!wp_using_ext_object_cache()) {
            return self::readValidatedVersionOption();
        }

        $cached = wp_cache_get(self::SEARCH_VERSION_KEY, self::CACHE_GROUP);
        if (self::isValidVersionValue($cached)) {
            return (int) $cached;
        }

        // Cache miss OR poisoned. Soft-lock: only one request per 2s window
        // does the option read + cache set.
        $lockKey = self::SEARCH_VERSION_KEY . '_heal_lock';

        if (wp_cache_add($lockKey, 1, self::CACHE_GROUP, self::VERSION_HEAL_LOCK_TTL)) {
            // Winner of the heal race.
            $version = self::readValidatedVersionOption();
            wp_cache_set(self::SEARCH_VERSION_KEY, $version, self::CACHE_GROUP, self::VERSION_CACHE_TTL);
            self::logCacheVersionFallbackRateLimited($cached, $version);
            return $version;
        }

        // Loser: bounded exponential backoff with a small random jitter.
        // Each step re-reads the cache; most losers find the winner's write
        // within the first 5ms step. Jitter prevents N losers from all
        // resuming on the same tick and stampeding the DB when the winner
        // is still a few ms away. Total worst-case wait ≈ 36ms, which is
        // negligible versus the wp_options read this replaces.
        foreach ([5000, 10000, 20000] as $waitUs) {
            usleep($waitUs + random_int(0, 2000));
            $cached = wp_cache_get(self::SEARCH_VERSION_KEY, self::CACHE_GROUP);
            if (self::isValidVersionValue($cached)) {
                return (int) $cached;
            }
        }

        // Winner not done within backoff window — last resort, do our own
        // option read. Do NOT write the cache: the active heal will.
        return self::readValidatedVersionOption();
    }

    /**
     * Strict version validator: int, or a string containing only decimal digits.
     *
     * @param mixed $value
     */
    private static function isValidVersionValue($value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    /**
     * Read the cache-version option and validate it strictly.
     *
     * Closes the "authoritative source is dirty" hole: if a filter, a bad
     * migration, or an admin miswrite ever pollutes the option, return a
     * known-safe floor (1) rather than silently coercing garbage.
     *
     * Also enforces a floor of 1 on validly-typed-but-zero values. No code
     * path writes 0 legitimately (bust_search_cache uses time()), so 0 is
     * always pollution. max() preserves the original value when it's a
     * valid positive int and only lifts genuinely low/bad values to 1.
     */
    private static function readValidatedVersionOption(): int
    {
        $opt = get_option(self::SEARCH_VERSION_KEY, 1);

        if (self::isValidVersionValue($opt)) {
            return max(1, (int) $opt);
        }

        // Option polluted. Log once per 60s per node, return safe floor.
        self::logOptionPoisonRateLimited($opt);
        return 1;
    }

    /** Rate-limited: at most one line per 60s per node per key. */
    private static function logCacheVersionFallbackRateLimited(mixed $cached, int $version): void
    {
        if (!wp_cache_add(self::SEARCH_VERSION_KEY . '_fallback_logged', 1, self::CACHE_GROUP, 60)) {
            return;
        }
        error_log(sprintf(
            '[bcc-search] cache_version_fallback key=%s group=%s type=%s fallback=%d node=%s ext_cache=%s',
            self::SEARCH_VERSION_KEY,
            self::CACHE_GROUP,
            gettype($cached),
            $version,
            gethostname() ?: 'unknown',
            wp_using_ext_object_cache() ? 'yes' : 'no'
        ));
    }

    /** Rate-limited: at most one line per 60s per node per option. */
    private static function logOptionPoisonRateLimited(mixed $opt): void
    {
        if (!wp_cache_add(self::SEARCH_VERSION_KEY . '_option_poison_logged', 1, self::CACHE_GROUP, 60)) {
            return;
        }
        error_log(sprintf(
            '[bcc-search] cache_version_option_poisoned key=%s type=%s fallback=1 node=%s',
            self::SEARCH_VERSION_KEY,
            gettype($opt),
            gethostname() ?: 'unknown'
        ));
    }

    /**
     * Loud warning when the rebuild path runs without a persistent
     * object cache. Without one:
     *   - The circuit breaker is inoperative (per-request memory
     *     can't gauge cross-request state).
     *   - The concurrent-rebuilds slot counter is inoperative (same).
     *   - The cache-version damping window is ineffective.
     *   - LKG is per-request only — losers across requests get no
     *     fallback.
     *
     * Rate-limited to one warning per hour per node via a WP transient
     * (options-backed; survives request boundaries even without a
     * persistent cache, which is exactly when this warning needs to
     * fire).
     */
    private static function warnIfNonPersistentCacheAtRebuild(): void
    {
        if (wp_using_ext_object_cache()) {
            return;
        }
        if (get_transient('bcc_search_nonpersistent_warned')) {
            return;
        }
        set_transient('bcc_search_nonpersistent_warned', '1', HOUR_IN_SECONDS);

        $msg = '[bcc-search] PRODUCTION RISK: rebuild path entered without a persistent object cache. Circuit breaker, concurrent-rebuild slot gauge, and LKG fallback are all inoperative. Deploy Redis/Memcached/Object Cache Pro before running at scale.';
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning($msg, [
                'node' => gethostname() ?: 'unknown',
            ]);
        } else {
            error_log($msg . ' node=' . (gethostname() ?: 'unknown'));
        }
    }

    /**
     * Circuit breaker check. True when rebuilds are allowed.
     *
     * On non-persistent caches this always returns true: wp_cache_* is
     * per-request memory there, so there is no cross-request gauge to
     * read — but installs without a persistent object cache are also
     * not the high-traffic topology the breaker exists to protect.
     */
    private static function circuitBreakerAllowsRebuild(): bool
    {
        if (!wp_using_ext_object_cache()) {
            return true;
        }
        return !wp_cache_get(self::BREAKER_TRIPPED_KEY, self::CACHE_GROUP);
    }

    /**
     * Record a rebuild start; trip the breaker if the per-window
     * count exceeds BREAKER_REBUILD_THRESHOLD.
     *
     * Uses wp_cache_incr for atomicity — concurrent workers on the
     * same node increment the same counter without racing. Bucket
     * key rotates every BREAKER_WINDOW_SEC seconds so the counter
     * self-clears without an eviction step.
     */
    private static function recordRebuildAndMaybeTrip(): void
    {
        if (!wp_using_ext_object_cache()) {
            return;
        }
        $bucket = (int) floor(time() / self::BREAKER_WINDOW_SEC);
        $key    = 'bcc_search_rebuilds_' . $bucket;
        wp_cache_add($key, 0, self::CACHE_GROUP, self::BREAKER_WINDOW_SEC * 2);
        $count = wp_cache_incr($key, 1, self::CACHE_GROUP);
        if (!is_int($count)) {
            // wp_cache_incr returns false on backends that don't
            // support atomic incr; skip the gauge rather than race
            // on a get-then-set.
            return;
        }
        $threshold = self::resolveBreakerRebuildThreshold();
        if ($count === $threshold + 1) {
            wp_cache_set(self::BREAKER_TRIPPED_KEY, 1, self::CACHE_GROUP, self::BREAKER_TRIP_TTL);
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-search] circuit breaker tripped', [
                    'rebuilds_in_window' => $count,
                    'threshold'          => $threshold,
                    'window_sec'         => self::BREAKER_WINDOW_SEC,
                    'trip_ttl_sec'       => self::BREAKER_TRIP_TTL,
                    'node'               => gethostname() ?: 'unknown',
                ]);
            }
        }
    }

    /**
     * Reserve a slot in the global concurrent-rebuilds gauge.
     *
     * Atomic increment-then-compare via wp_cache_incr. If the increment
     * pushes us past MAX_CONCURRENT_REBUILDS, we decrement back out and
     * return false — the caller should serve LKG/503 instead of starting
     * heavy work.
     *
     * The gauge key is TTL'd at ACTIVE_REBUILDS_TTL so a SIGKILL'd
     * worker's un-decremented slot self-clears. TTL is intentionally
     * longer than REBUILD_LOCK_TTL so lock expiry resolves first.
     *
     * On non-persistent caches this returns true unconditionally — the
     * counter is per-request memory there and can't reflect global state.
     * Same rationale as the circuit breaker.
     */
    private static function reserveRebuildSlot(): bool
    {
        if (!wp_using_ext_object_cache()) {
            return true;
        }
        wp_cache_add(self::ACTIVE_REBUILDS_KEY, 0, self::CACHE_GROUP, self::ACTIVE_REBUILDS_TTL);
        $count = wp_cache_incr(self::ACTIVE_REBUILDS_KEY, 1, self::CACHE_GROUP);
        if (!is_int($count)) {
            // Backend does not support atomic incr — skip the gauge
            // rather than race on a non-atomic get-then-set.
            return true;
        }
        $max = self::resolveMaxConcurrentRebuilds();
        if ($count > $max) {
            wp_cache_decr(self::ACTIVE_REBUILDS_KEY, 1, self::CACHE_GROUP);
            if (class_exists('\\BCC\\Core\\Log\\Logger')
                && wp_cache_add('bcc_search_slot_exhausted_logged', 1, self::CACHE_GROUP, 30)
            ) {
                \BCC\Core\Log\Logger::warning('[bcc-search] rebuild slot exhausted', [
                    'max'  => $max,
                    'node' => gethostname() ?: 'unknown',
                ]);
            }
            return false;
        }
        return true;
    }

    /**
     * Release a previously-reserved slot.
     *
     * Decrement with underflow clamp. A negative value after decrement
     * means the counter TTL expired while the slot was held (e.g., a
     * rebuild took longer than ACTIVE_REBUILDS_TTL); snap back to 0 so
     * the gauge is self-healing.
     */
    private static function releaseRebuildSlot(): void
    {
        if (!wp_using_ext_object_cache()) {
            return;
        }
        $count = wp_cache_decr(self::ACTIVE_REBUILDS_KEY, 1, self::CACHE_GROUP);
        if (is_int($count) && $count < 0) {
            wp_cache_set(self::ACTIVE_REBUILDS_KEY, 0, self::CACHE_GROUP, self::ACTIVE_REBUILDS_TTL);
        }
    }

    /**
     * Build the "breaker tripped — serve LKG or 503" response.
     *
     * Shared by the search and trending paths so both endpoints respond
     * identically under sustained overload.
     *
     * @param string $lkg_key
     */
    private function breakerTrippedResponse(string $lkg_key): \WP_REST_Response
    {
        $lkg = wp_cache_get($lkg_key, self::CACHE_GROUP);
        if (is_array($lkg)) {
            // LKG can be up to LKG_CACHE_TTL old (1 h default). Tag the
            // payload so the frontend can surface "showing cached results"
            // UI instead of presenting stale rankings as authoritative.
            $lkg['stale']            = true;
            $lkg['system_degraded']  = true;
            // Observability counter: LKG served because the live path
            // tripped (breaker open / rebuild lock contention / explicit
            // overload). Useful for "search has been serving stale results
            // for N minutes" before users notice ranking quality drop.
            \BCC\Core\Observability\DegradationMetrics::record('search_lkg', 'served');
            return new \WP_REST_Response($lkg);
        }
        // No LKG available either — full 503. Worse outcome than LKG-served.
        \BCC\Core\Observability\DegradationMetrics::record('search_lkg', 'unavailable_503');
        return new \WP_REST_Response(
            [
                'code'    => 'bcc_upstream_unavailable',
                'message' => 'Search is temporarily rate-limited. Please retry shortly.',
                'data'    => ['status' => 503],
            ],
            503,
            ['Retry-After' => '5']
        );
    }

    /**
     * Degraded response for a trust-engine enrichment FAILURE (the engine
     * is installed but threw). Mirrors the handle_search enrich-fail block
     * so trending degrades identically: a score-less/mis-ordered ranking
     * must never be hydrated, returned, or cached (it would poison LKG for
     * an hour AND let low-trust content surface). Prefer the same-
     * fingerprint stale cache, then LKG, then 503 — never fresh bad data.
     *
     * @param mixed $cached the version-scoped cache wrapper read earlier
     *                      this request (may be a stale-but-valid entry).
     */
    private function degradedEnrichResponse(mixed $cached, string $lkg_key): \WP_REST_Response
    {
        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
            return new \WP_REST_Response($cached['data']);
        }
        $lkg = wp_cache_get($lkg_key, self::CACHE_GROUP);
        if (is_array($lkg)) {
            $lkg['stale']           = true;
            $lkg['system_degraded'] = true;
            \BCC\Core\Observability\DegradationMetrics::record('search_lkg', 'served');
            return new \WP_REST_Response($lkg);
        }
        \BCC\Core\Observability\DegradationMetrics::record('search_lkg', 'unavailable_503');
        return new \WP_REST_Response(
            [
                'code'    => 'bcc_internal',
                'message' => 'Temporarily unavailable. Please retry shortly.',
                'data'    => ['status' => 503],
            ],
            503,
            ['Retry-After' => '5']
        );
    }

    /**
     * Per-request flag: a bust has been requested but the actual write is
     * deferred to 'shutdown'. Coalesces multiple hooks (e.g. save_post +
     * delete_post + meta updates within one request) into a single
     * update_option() + wp_cache_set() pair.
     */
    private static bool $bustQueued = false;

    /** Same one-write-per-request coalescing as $bustQueued, for the LKG generation. */
    private static bool $privacyBustQueued = false;

    public static function bust_search_cache(): void
    {
        if (self::$bustQueued) {
            return;
        }
        self::$bustQueued = true;
        // Defer to 'shutdown' so a single user action that fires multiple
        // hooks produces exactly one DB write. Priority 0 so we run before
        // other shutdown work that might inspect the option.
        add_action('shutdown', [__CLASS__, 'flushBustIfQueued'], 0);
    }

    /**
     * Shutdown handler: write the version bump if one was queued.
     *
     * Public because WP needs to invoke it as a hook callback; not part
     * of the supported API surface.
     */
    public static function flushBustIfQueued(): void
    {
        if (!self::$bustQueued) {
            return;
        }
        self::$bustQueued = false;
        $version = time();
        update_option(self::SEARCH_VERSION_KEY, $version, false);
        // Uniform TTL with the heal path — no "forever" keys in multi-node
        // setups that would defeat getCacheVersion()'s damping window.
        wp_cache_set(self::SEARCH_VERSION_KEY, $version, self::CACHE_GROUP, self::VERSION_CACHE_TTL);
    }

    /**
     * Visibility-change bust: flush the version-scoped cache (via the
     * normal version bump) AND advance the LKG generation so no prior
     * LKG entry — which the version bump alone can't reach — can serve a
     * now-secret page on a degraded path. Bumps both in one shutdown
     * write path via the same queue as bust_search_cache().
     */
    public static function bust_search_privacy(): void
    {
        if (!self::$privacyBustQueued) {
            self::$privacyBustQueued = true;
            add_action('shutdown', [__CLASS__, 'flushPrivacyBustIfQueued'], 0);
        }
        // Also bump the content version so the hot (version-scoped) cache
        // drops the stale-visibility entry immediately, not after its TTL.
        self::bust_search_cache();
    }

    /** Shutdown handler: advance the LKG generation if a privacy bust was queued. */
    public static function flushPrivacyBustIfQueued(): void
    {
        if (!self::$privacyBustQueued) {
            return;
        }
        self::$privacyBustQueued = false;
        $gen = time();
        update_option(self::SEARCH_LKG_GEN_KEY, $gen, false);
        wp_cache_set(self::SEARCH_LKG_GEN_KEY, $gen, self::CACHE_GROUP, self::VERSION_CACHE_TTL);
    }

    /**
     * Current LKG generation. Read the same way as the cache version —
     * cache first, option fallback, safe floor of 1 — but with no heal
     * race: LKG-gen changes are rare (privacy flips only), so a plain
     * cache-miss option read is fine.
     */
    private static function getLkgGeneration(): int
    {
        $cached = wp_cache_get(self::SEARCH_LKG_GEN_KEY, self::CACHE_GROUP);
        if (self::isValidVersionValue($cached)) {
            return (int) $cached;
        }
        $opt = get_option(self::SEARCH_LKG_GEN_KEY, 1);
        $gen = self::isValidVersionValue($opt) ? max(1, (int) $opt) : 1;
        wp_cache_set(self::SEARCH_LKG_GEN_KEY, $gen, self::CACHE_GROUP, self::VERSION_CACHE_TTL);
        return $gen;
    }
}
