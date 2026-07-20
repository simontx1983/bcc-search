<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Core\DB\AdvisoryLock;
use BCC\Core\Security\Throttle;
use BCC\Search\Controllers\SearchController;
use BCC\Search\DTO\PageCandidateDTO;
use BCC\Search\DTO\PageHydratedDTO;
use BCC\Search\Repositories\SearchRepository;
use BCC\Search\Tests\Stubs\FakeScoreReadService;
use BCC\Search\Tests\Stubs\TestCacheStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural pins for SearchController::handle_search().
 *
 * ## What this pins
 *
 *   1. Empty-result short-circuits (unknown `type`, q outside 2..100,
 *      junk-gate rejection) return `{results: [], categories: []}` —
 *      the contract v1.46 shape — while a *legitimately* empty search
 *      keeps the full category list.
 *   2. The §J anti-impersonation guarantee: a claim-verified page always
 *      outranks an unverified same-normalised-name lookalike, however
 *      high the lookalike's trust/text score — and the demotion is
 *      demote-only (different-name pages are never boosted or clamped).
 *   3. The PRERANK_TOP_K enrichment cutoff and LIMIT winner cap.
 *
 * ## Isolation strategy
 *
 * Each test runs in its own subprocess. setUp() loads
 * tests/Stubs/search-controller-env-stub.php, which defines fakes at the
 * production FQNs for every collaborator (Throttle, ServiceLocator,
 * AdvisoryLock, SearchRepository, PeepSo, WP_REST_*, wp_cache_* et al.)
 * before requiring the REAL controller — same subprocess-fake-at-FQN
 * pattern as UserSearchServiceTest. QueryQualityGate is the real class.
 *
 * The stub environment fixes wp_using_ext_object_cache() === false and
 * AdvisoryLock winners, so the circuit breaker, rebuild-slot gauge, LKG
 * serving and stale-while-revalidate loser paths are deliberately NOT
 * exercised here — they need a real object cache (see stub header).
 */
#[CoversClass(SearchController::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SearchControllerHandleSearchTest extends TestCase
{
    private const CATEGORIES = [
        ['slug' => 'defi', 'name' => 'DeFi'],
        ['slug' => 'validators', 'name' => 'Validators'],
    ];

    private const EMPTY_SHORT_CIRCUIT = ['results' => [], 'categories' => []];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/search-controller-env-stub.php';
        TestCacheStore::reset();
        FakeScoreReadService::reset();
        SearchRepository::reset();
        Throttle::$allow     = true;
        AdvisoryLock::$acquire = true;
        SearchRepository::$categories = self::CATEGORIES;
    }

    private function dispatch(string $q, string $type = ''): \WP_REST_Response
    {
        return (new SearchController())->handle_search(
            new \WP_REST_Request(['q' => $q, 'type' => $type])
        );
    }

    /**
     * Seed one page into both the candidate pool and the hydration map.
     */
    private function seedPage(int $id, string $title): void
    {
        SearchRepository::$candidates[]   = new PageCandidateDTO($id, $title);
        SearchRepository::$pagesById[$id] = new PageHydratedDTO(
            id:           $id,
            title:        $title,
            slug:         'page-' . $id,
            categoryName: null,
            categorySlug: null,
            avatarHash:   null,
        );
    }

    /**
     * Full enriched-score row as the trust engine's read model emits it.
     *
     * @return array<string, mixed>
     */
    private static function score(float $ranking, bool $claimVerified): array
    {
        return [
            'total_score'       => 42.0,
            'reputation_tier'   => 'steady',
            'ranking_score'     => $ranking,
            'endorsement_count' => 3,
            'is_verified'       => $claimVerified,
            'is_claim_verified' => $claimVerified,
            'follower_count'    => 7,
        ];
    }

    /** @return list<int> */
    private static function resultPageIds(\WP_REST_Response $response): array
    {
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('results', $data);
        self::assertIsArray($data['results']);
        return array_column($data['results'], 'page_id');
    }

    /** @return array<string, mixed> */
    private function cacheEntriesWithPrefix(string $prefix): array
    {
        $out = [];
        foreach (TestCacheStore::groupEntries('bcc_search') as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    // ── Guards ahead of the short-circuits ─────────────────────────────

    public function testRateLimitedRequestIsRejectedBeforeAnyRepositoryWork(): void
    {
        Throttle::$allow = false;

        $response = $this->dispatch('bitcoin');

        self::assertSame(429, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('bcc_rate_limited', $data['code']);
        self::assertFalse(SearchRepository::$searchCalled);
    }

    // ── Empty-result short-circuits (contract v1.46 shape) ─────────────

    public function testUnknownTypeShortCircuitsWithEmptyCategories(): void
    {
        $response = $this->dispatch('bitcoin', 'not-a-real-category');

        self::assertSame(200, $response->get_status());
        self::assertSame(self::EMPTY_SHORT_CIRCUIT, $response->get_data());
        self::assertFalse(SearchRepository::$searchCalled);
    }

    public function testQueryLengthOutsideTwoToHundredShortCircuits(): void
    {
        foreach (['a', str_repeat('ab', 51)] as $q) { // 1 char, 102 chars
            $response = $this->dispatch($q);
            self::assertSame(200, $response->get_status());
            self::assertSame(self::EMPTY_SHORT_CIRCUIT, $response->get_data(), "q={$q}");
        }
        self::assertFalse(SearchRepository::$searchCalled);
    }

    public function testJunkQueryShortCircuitsAndIsServedFromJunkCacheOnRepeat(): void
    {
        $response = $this->dispatch('aaaa'); // all-one-char → real QueryQualityGate rejects

        self::assertSame(200, $response->get_status());
        self::assertSame(self::EMPTY_SHORT_CIRCUIT, $response->get_data());
        self::assertFalse(SearchRepository::$searchCalled);

        // Cached under a dedicated junk key (never the version-scoped or
        // LKG keys — junk must not survive into the good-data mirror).
        $junk = $this->cacheEntriesWithPrefix('search_junk_');
        self::assertCount(1, $junk);
        self::assertSame(self::EMPTY_SHORT_CIRCUIT, reset($junk));
        self::assertSame([], $this->cacheEntriesWithPrefix('search_lkg_'));

        // Poison the cached entry: a repeat of the same junk query must be
        // served straight from the junk cache (sentinel comes back) and
        // still never reach the repository.
        $key      = array_key_first($junk);
        $sentinel = ['results' => ['sentinel'], 'categories' => []];
        TestCacheStore::$cache['bcc_search'][$key] = $sentinel;

        self::assertSame($sentinel, $this->dispatch('aaaa')->get_data());
        self::assertFalse(SearchRepository::$searchCalled);
    }

    public function testLegitimatelyEmptySearchKeepsFullCategoryList(): void
    {
        // 'ab' passes every gate (length 2, not junk); repository finds
        // nothing. Unlike the short-circuits above, the full category
        // list must survive — the frontend renders category chips on
        // real (even empty) searches.
        $response = $this->dispatch('ab');

        self::assertSame(200, $response->get_status());
        self::assertSame(
            ['results' => [], 'categories' => self::CATEGORIES],
            $response->get_data()
        );
        self::assertSame(['ab', '', 50], SearchRepository::$lastSearchArgs);

        // Version-scoped cache is written (suppresses re-query stampede)…
        self::assertNotEmpty($this->cacheEntriesWithPrefix('search_'));
        // …but an empty result is never promoted to LKG on a
        // non-persistent cache (isEmptyResultVerifiedStable short-circuits).
        self::assertSame([], $this->cacheEntriesWithPrefix('search_lkg_'));
        self::assertSame([], $this->cacheEntriesWithPrefix('search_junk_'));
    }

    // ── Ranking blend / result shape ───────────────────────────────────

    public function testTextRelevanceOrdersResultsWhenTrustEngineHasNoScores(): void
    {
        $this->seedPage(20, 'acme');            // exact match
        $this->seedPage(10, 'Acme Corp');       // prefix match
        $this->seedPage(30, 'Best acme tools'); // substring match

        $response = $this->dispatch('acme');

        self::assertSame([20, 10, 30], self::resultPageIds($response));
        // 4-char query → 80-candidate cap wired through to the repository.
        self::assertSame(['acme', '', 80], SearchRepository::$lastSearchArgs);

        // Row shape is the /cards-aligned contract; scoreless pages emit
        // null/false/0 trust fields, not fabricated values.
        $data = $response->get_data();
        self::assertIsArray($data);
        $row = $data['results'][0];
        self::assertSame(
            [
                'page_id', 'page_name', 'page_url', 'avatar_url',
                'trust_score', 'tier', 'endorsements', 'verified',
                'is_claim_verified', 'followers', 'category', 'category_slug',
            ],
            array_keys($row)
        );
        self::assertSame(20, $row['page_id']);
        self::assertSame('acme', $row['page_name']);
        self::assertSame('https://site.test/pages/page-20/', $row['page_url']);
        self::assertNull($row['trust_score']);
        self::assertFalse($row['verified']);

        // Non-empty result → LKG mirror written.
        self::assertNotEmpty($this->cacheEntriesWithPrefix('search_lkg_'));
    }

    // ── §J anti-impersonation demotion ─────────────────────────────────

    public function testClaimVerifiedPageOutranksSameNameLookalikeWithHigherTrust(): void
    {
        // Impostor: same normalised name ('ACME' → 'acme'), near-max
        // trust ranking. Canonical: claim-verified but far lower score.
        // Without the demotion clamp the impostor wins on the blend.
        $this->seedPage(1, 'ACME');
        $this->seedPage(2, 'Acme');
        FakeScoreReadService::$scoresById = [
            1 => self::score(79.0, false),
            2 => self::score(10.0, true),
        ];

        self::assertSame([2, 1], self::resultPageIds($this->dispatch('acme')));
    }

    public function testDemotionAppliesOnlyToSameNormalisedName(): void
    {
        // Demote-only guarantee: a strong unverified page with a
        // DIFFERENT name is not clamped below an unrelated verified
        // page (i.e. there is no global verified-first boost).
        $this->seedPage(5, 'Zenith'); // unverified, prefix match, strong trust
        $this->seedPage(6, 'Acme');   // claim-verified, no text match, weak trust
        FakeScoreReadService::$scoresById = [
            5 => self::score(80.0, false),
            6 => self::score(5.0, true),
        ];

        self::assertSame([5, 6], self::resultPageIds($this->dispatch('ze')));
    }

    // ── PRERANK_TOP_K / LIMIT ──────────────────────────────────────────

    public function testEnrichmentIsCappedAtPrerankTopKAndWinnersAtLimit(): void
    {
        // 30 candidates, all prefix matches whose text score strictly
        // decreases with id (longer title → smaller length bonus), so
        // the pre-rank order is exactly 1..30.
        for ($i = 1; $i <= 30; $i++) {
            $this->seedPage($i, 'acmes' . str_repeat('x', $i));
        }

        $response = $this->dispatch('acmes');

        // 5-char query → widest candidate cap.
        self::assertSame(['acmes', '', 100], SearchRepository::$lastSearchArgs);
        // Only the top PRERANK_TOP_K (24) candidates reach trust
        // enrichment — the whole point of the pre-rank phase.
        self::assertSame(range(1, 24), FakeScoreReadService::$lastRequestedIds);
        // And only LIMIT (12) winners are hydrated into the response.
        self::assertSame(range(1, 12), self::resultPageIds($response));
    }

    // ── Enrichment failure is fail-closed, never text-only ─────────────

    public function testTrustEngineFailureYields503AndPoisonsNoCache(): void
    {
        // Trust engine installed but throwing: a silent text-only ranking
        // would be a trust-manipulation surface, and caching the failure
        // would pin it. With no stale entry and no LKG, the only honest
        // answer is a retryable 503 — and the cache must stay empty.
        $this->seedPage(1, 'Acme');
        FakeScoreReadService::$throw = true;

        $response = $this->dispatch('acme');

        self::assertSame(503, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('bcc_internal', $data['code']);
        self::assertSame([], $this->cacheEntriesWithPrefix('search_'));
    }
}
