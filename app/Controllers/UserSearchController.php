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
    //
    // ⚠ Until F-430 this TTL was the ONLY bound on staleness: nothing
    // invalidated this cache. A member who hid themselves, renamed
    // themselves, got banned, or blocked someone stayed findable exactly
    // as before for up to 45 seconds. Privacy is a binary invisibility
    // guarantee, not a freshness budget — the same principle the pages and
    // groups verticals already applied — so the generation counter below
    // makes prior entries unreachable at once.
    private const CACHE_TTL      = 45;

    /**
     * Generation counter folded into the cache key (F-430). Bumped on any
     * change to what this vertical serves, which is a wider set than it
     * looks: results are filtered by PeepSo's `pre_user_query` (ban /
     * unvalidated role, `usr_profile_acc`, members-only-when-anonymous,
     * blocking in both directions, hide-from-listing) AND matched on
     * display name + current `bcc_handle`, so a RENAME changes the answer
     * just as much as a privacy flip does. The groups vertical learned
     * that at v1.70, where a renamed community lagged the TTL.
     *
     * The users vertical has no version key or LKG of its own, so this plus
     * the TTL is the whole invalidation mechanism.
     */
    private const GEN_KEY = 'bcc_user_search_generation';

    /**
     * User-meta keys that change what the users vertical serves.
     *
     * ⭐ `peepso_is_hide_profile_from_user_listing` is ONE key doing two
     * jobs. Per F-257 it is the single source of truth for "hidden from
     * member search": bcc-trust's `discovery_optout` (/me/privacy) is an
     * ALIAS that reads and writes it, and search excludes on it alone. The
     * legacy `bcc_privacy_discovery_optout` rows are deliberately NOT
     * listed here — nothing reads them since the fold migration, so
     * bumping on them would imply a dependency that no longer exists.
     *
     * `bcc_privacy_real_name_hidden` (F-28) changes the NAME this endpoint
     * projects, not whether the row appears. `bcc_handle` is half the
     * matcher, so a handle change alters which queries find the member.
     */
    private const BUST_USER_META_KEYS = [
        'peepso_is_hide_profile_from_user_listing',
        'bcc_privacy_real_name_hidden',
        'bcc_handle',
    ];

    /**
     * PeepSo's site-wide switch for the hide-from-listing filter. While it
     * is off, PeepSo does not apply the filter at all, so flipping it
     * changes every result for every query — a bigger change than any one
     * member's flag.
     */
    private const BUST_OPTION = 'allow_hide_user_from_user_listing';

    private const DEFAULT_LIMIT  = 20;
    private const MAX_LIMIT      = 50;

    // Same shape as project search rate limiting: 10 req / 5s per
    // Throttle::allow() bucket (user_id for logged-in, /24 subnet for
    // anonymous). Independent bucket name so concurrent project +
    // user queries from the same client don't compete.
    private const RATE_LIMIT     = 10;
    private const RATE_WINDOW    = 5;

    /**
     * Register the cache-bust hooks. Wired on `init` from bcc-search.php
     * (like the other two verticals') so it fires on admin, AJAX and cron
     * writes, not only on REST requests.
     *
     * ⚠ `usr_profile_acc` — the profile privacy level — is the one filter
     * with NO hook of its own. PeepSo writes it straight to its own table:
     * `PeepSoUser::update_peepso_user()` runs `$wpdb->update` and fires no
     * action, and the `usr_profile_acc` branch of
     * `PeepSoProfilePreferencesAjax::savepreference()` deliberately
     * bypasses `update_user_meta`, so no meta hook sees it either.
     *
     * It is covered anyway for the path members actually use:
     * bcc-trust's own `/me/profile-prefs` route fires
     * `bcc_profile_visibility_changed` after writing it, and only when the
     * value really changed (`MyProfilePrefsEndpoint`), so this listens to
     * that. What stays uncovered is a write that goes through PeepSo's own
     * preferences AJAX, PeepSo's admin screens, or straight into the
     * database — those still fall back to the 45-second TTL. Said here
     * rather than left for someone to discover: a counter whose remaining
     * gap is written down is worth more than one that looks complete.
     *
     * The ban case DOES have a hook, which is easy to miss because PeepSo
     * fires nothing named for banning: a ban is a role change, and
     * `PeepSoUser::set_user_role()` fires `peepso_action_user_role_change`.
     * Search excludes `usr_role IN ('register','verified','ban')`, so that
     * one hook covers banning and verification alike.
     *
     * ⚠ BCC SUSPENSION IS A SEPARATE FILTER FROM PEEPSO'S BAN, and it is
     * not visible from this repository at all. bcc-trust registers
     * `ProfileAccess::filterSuspendedFromListing` on `pre_user_query`
     * (from its own bootstrap), narrowed to PeepSo-built queries by the
     * `_peepso_user_id` query var that `PeepSoUserSearch` always injects.
     * It joins `user_info.is_suspended` — the same column the profile gate
     * reads — and exempts admins and the viewer themselves (F-182). So a
     * suspended member is excluded from these results even though PeepSo
     * knows nothing about it: `suspendUser()` writes `is_suspended` and
     * never touches `usr_role`.
     *
     * That is why `bcc_user_suspension_changed` is in the list below. It
     * is easy to miss, because the filter is enumerated in no docblock on
     * this side and a grep of this plugin finds nothing.
     */
    public static function register_cache_hooks(): void
    {
        $bumpMeta = static function ($meta_id, $user_id, string $meta_key): void {
            if (in_array($meta_key, self::BUST_USER_META_KEYS, true)) {
                self::bustGeneration();
            }
        };
        add_action('added_user_meta', $bumpMeta, 10, 3);
        add_action('updated_user_meta', $bumpMeta, 10, 3);
        add_action('deleted_user_meta', $bumpMeta, 10, 3);

        $bump = static function (): void {
            self::bustGeneration();
        };

        // A rename: display_name is half the matcher, and `profile_update`
        // is the only hook that sees a wp_users row change.
        add_action('profile_update', $bump);
        // A new member becomes findable; a deleted one must stop being so.
        add_action('user_register', $bump);
        add_action('deleted_user', $bump);

        // Blocking is viewer-scoped and filtered in BOTH directions, so
        // either party's cached results change.
        add_action('peepso_user_blocked', $bump);
        add_action('peepso_user_unblocked', $bump);

        // Ban / unban / verification — see the docblock above.
        add_action('peepso_action_user_role_change', $bump);

        // The profile privacy level, via bcc-trust's own route. PeepSo's
        // table write fires nothing, so this is the only hook there is for
        // it — and bcc-trust fires it only on a real change, which is what
        // keeps a settings form resubmitting the same value from bumping.
        add_action('bcc_profile_visibility_changed', $bump);

        // BCC suspension — a filter PeepSo knows nothing about; see above.
        // Fired by suspendUser(), unsuspendUser() and ModerationService
        // after its commit. FeedRankingService already consumes this hook
        // for the same reason, so this follows an established precedent
        // rather than inventing one.
        add_action('bcc_user_suspension_changed', $bump);

        // The site-wide hide-from-listing switch.
        add_action('add_option_' . self::BUST_OPTION, $bump);
        add_action('update_option_' . self::BUST_OPTION, $bump);
        add_action('delete_option_' . self::BUST_OPTION, $bump);
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

        // ⚠ STRICTLY INCREASING, not simply time(). The groups and pages
        // verticals both store `time()`, which leaves a one-second hole: a
        // bump landing in the same second as the current generation
        // produces the SAME value, so the cache key does not change and
        // the entry written just before the privacy flip stays reachable
        // for the rest of that second. 45 seconds down to one is a large
        // improvement, but "binary invisibility guarantee, not a freshness
        // budget" does not have a one-second exemption, and a member
        // hiding themselves immediately after someone searched for them is
        // exactly the sub-second case. Taking max(now, current + 1) costs
        // one comparison and closes it.
        //
        // The value stays a seconds-based integer so it is still readable
        // as "when did this vertical last change", and a clock that jumps
        // backwards cannot stall the counter.
        // ⚠ F-442: read inline, NOT through getGeneration(). F-430 called the
        // reader here, which was already wasteful and became wrong once that
        // reader gained its add()-on-miss write: the call would populate the
        // cache with the OLD generation and the write below would then
        // replace it. One read, no side effects.
        $current = wp_cache_get(self::GEN_KEY, self::CACHE_GROUP);
        $current = (is_int($current) || (is_string($current) && ctype_digit($current)))
            ? (int) $current
            : (int) get_option(self::GEN_KEY, 1);

        $gen = max(time(), max(1, $current) + 1);

        update_option(self::GEN_KEY, $gen, false);
        wp_cache_set(self::GEN_KEY, $gen, self::CACHE_GROUP, self::CACHE_TTL);
    }

    /** Current generation: cache first, option fallback, safe floor of 1. */
    private static function getGeneration(): int
    {
        $found  = null;
        $cached = wp_cache_get(self::GEN_KEY, self::CACHE_GROUP, false, $found);
        if (is_int($cached) || (is_string($cached) && ctype_digit($cached))) {
            return max(1, (int) $cached);
        }
        $opt = get_option(self::GEN_KEY, 1);
        $gen = (is_int($opt) || (is_string($opt) && ctype_digit($opt))) ? max(1, (int) $opt) : 1;

        // ⚠ F-442: WHICH WRITE depends on WHY we are here, and getting it
        // wrong either way is a bug.
        //
        // A true MISS populates with add(). This read is not atomic with
        // the option read above, so a bump can land in between; with set()
        // we would then cache the stale value we had already read and keep
        // serving the pre-bump generation for the whole TTL — with a
        // persistent object cache, exactly the invalidation this counter
        // exists to guarantee. add() fails when the bump got there first,
        // and the bump survives.
        //
        // A POISONED entry (present, but not a valid generation) must be
        // OVERWRITTEN with set(). add() would fail — the key exists — and
        // leave the bad value cached for the TTL with no path out. We
        // accept that this heal can overwrite a concurrent bump: a rare
        // lost bump costs one TTL of staleness, where stuck poison costs
        // every request until it expires.
        if ($found === true) {
            wp_cache_set(self::GEN_KEY, $gen, self::CACHE_GROUP, self::CACHE_TTL);
        } else {
            wp_cache_add(self::GEN_KEY, $gen, self::CACHE_GROUP, self::CACHE_TTL);
        }

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
        // Rate limit — same Throttle contract as /search but a distinct
        // bucket name so the two verticals don't share quota.
        if (!Throttle::allow('search_users', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new \WP_REST_Response([
                'code'    => 'bcc_rate_limited',
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

        // Viewer is folded into the cache key: results are privacy-filtered
        // per viewer (the block filter is viewer-scoped), so an anonymous
        // (v0) bucket must never be served to a signed-in viewer or vice
        // versa. Anonymous callers all share the v0 bucket.
        //
        // F-430: the generation is folded in too, so a bump makes every
        // prior entry unreachable at once — including the shared anonymous
        // bucket, which is the one many callers read.
        $viewerId  = get_current_user_id();
        $cache_key = 'user_search_' . self::getGeneration()
            . '_' . md5(mb_strtolower($q) . '|' . $limit . '|v' . $viewerId);
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return new \WP_REST_Response($cached);
        }

        try {
            $response = (new UserSearchService())->search($q, $limit, $viewerId);
        } catch (\RuntimeException $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-search] user search failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return new \WP_REST_Response(
                [
                    'code'    => 'bcc_upstream_unavailable',
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
                    'code'    => 'bcc_upstream_unavailable',
                    'message' => 'User search is temporarily unavailable. Please retry shortly.',
                    'data'    => ['status' => 503],
                ],
                503,
                ['Retry-After' => '5']
            );
        }

        // F-53: the users vertical is deliberately NOT recorded in search
        // analytics — the term is the name of the person being looked up.

        wp_cache_set($cache_key, $response, self::CACHE_GROUP, self::CACHE_TTL);

        return new \WP_REST_Response($response);
    }
}
