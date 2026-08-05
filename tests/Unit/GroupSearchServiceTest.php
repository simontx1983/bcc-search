<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\DTO\GroupDTO;
use BCC\Search\Services\GroupSearchService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the group-search projection (v1.70).
 *
 * ## Why this exists
 *
 * GroupSearchService::search() is the single seam that projects each
 * GroupDTO into the public /search/groups response. v1.70 made three
 * guarantees worth pinning:
 *
 *   1. `group_url` is a RELATIVE kind-aware in-app route
 *      (hall → /halls/{slug}, else /communities/{slug}). The previous
 *      implementation emitted absolute WP-origin permalinks — the
 *      third occurrence of the off-app-URL defect class (v1.46 users,
 *      v1.47 projects). The no-`http` guard below is the hard net.
 *   2. `kind` maps the RAW `_bcc_group_kind` meta ('holders',
 *      'delegators', …) to the public §3.2.4 vocabulary — a deliberate
 *      lockstep duplicate of bcc-trust GroupContextResolver.
 *   3. `kind_label` is the server-rendered §A2 kicker string, emitted
 *      so the frontend never maps kind→copy itself.
 *
 * ## Isolation strategy
 *
 * Same pattern as UserSearchServiceTest: each test runs in its own
 * subprocess; setUp() loads tests/Stubs/group-search-repo-stub.php,
 * which defines a fake GroupSearchRepository at the production FQN and
 * then requires the real GroupSearchService. PeepSo is absent in the
 * test process, so avatar resolution degrades to null — irrelevant
 * here.
 */
#[CoversClass(GroupSearchService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupSearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/group-search-repo-stub.php';
        \BCC\Search\Repositories\GroupSearchRepository::reset();
    }

    private function searchWith(GroupDTO ...$dtos): array
    {
        \BCC\Search\Repositories\GroupSearchRepository::$return = array_values($dtos);
        return (new GroupSearchService())->search('q', 20);
    }

    public function testHallKindGetsHallRouteAndChainHallLabel(): void
    {
        $row = $this->searchWith(
            new GroupDTO(17, 'Cosmos Hall', 'cosmos-hall', null, null, 'hall'),
        )['results'][0];

        self::assertSame('/halls/cosmos-hall', $row['group_url']);
        self::assertSame('hall', $row['kind']);
        self::assertSame('CHAIN HALL', $row['kind_label']);
    }

    public function testRawKindMatrixMapsToPublicVocabulary(): void
    {
        $out = $this->searchWith(
            new GroupDTO(1, 'Holders', 'holders-g', null, null, 'holders'),
            new GroupDTO(2, 'Delegators', 'delegators-g', null, null, 'delegators'),
            new GroupDTO(3, 'System', 'system-g', null, null, 'system'),
            new GroupDTO(4, 'Plain', 'plain-g', null, null, null),
            new GroupDTO(5, 'Weird', 'weird-g', null, null, 'not-a-kind'),
        )['results'];

        // Raw meta → public §3.2.4 vocabulary; absent/unknown → 'user'.
        self::assertSame(['nft', 'validator', 'system', 'user', 'user'], array_column($out, 'kind'));
        self::assertSame(
            ['HOLDER COMMUNITY', 'DELEGATOR COMMUNITY', 'SYSTEM COMMUNITY', 'COMMUNITY', 'COMMUNITY'],
            array_column($out, 'kind_label'),
        );

        // Every non-hall kind routes to the generic community detail.
        self::assertSame(
            ['/communities/holders-g', '/communities/delegators-g', '/communities/system-g', '/communities/plain-g', '/communities/weird-g'],
            array_column($out, 'group_url'),
        );
    }

    public function testNoAbsoluteUrlEverSurvivesInGroupUrl(): void
    {
        // Hard regression guard for the off-app-URL defect class
        // (v1.46 users / v1.47 projects / v1.70 groups): serialize the
        // whole payload and require zero 'http' anywhere in group_url.
        $out = $this->searchWith(
            new GroupDTO(1, 'A', 'a', null, null, 'hall'),
            new GroupDTO(2, 'B', 'b', null, null, 'holders'),
            new GroupDTO(3, 'C', 'c', null, null, null),
        );

        foreach ($out['results'] as $row) {
            self::assertStringStartsWith('/', $row['group_url']);
            self::assertStringNotContainsString('http', $row['group_url']);
            self::assertStringNotContainsString('//', $row['group_url']);
        }
    }

    public function testSlugIsRawurlencodedAndEmptySlugFallsBackToListRoute(): void
    {
        $out = $this->searchWith(
            new GroupDTO(1, 'Spaced', 'a b', null, null, null),
            new GroupDTO(2, 'Slugless', '', null, null, 'hall'),
        )['results'];

        self::assertSame('/communities/a%20b', $out[0]['group_url']);
        // Empty slug: list route, never a broken detail link.
        self::assertSame('/communities', $out[1]['group_url']);
    }

    public function testResultKeysAreExactlyThePublicShape(): void
    {
        $row = $this->searchWith(
            new GroupDTO(9, 'Nine', 'nine', null, null, null),
        )['results'][0];

        self::assertSame(
            ['id', 'name', 'slug', 'description', 'avatar_url', 'group_url', 'kind', 'kind_label'],
            array_keys($row),
        );
    }

    public function testLeadingSlashSlugIsNormalizedNotDoubled(): void
    {
        // Absorbed from the v1.68 canonicalization tests: a dirty slug
        // with a leading slash must not produce '/communities//x' (or
        // an encoded %2F prefix).
        $row = $this->searchWith(
            new GroupDTO(3, 'Dirty', '/dirty-slug', null, null, null),
        )['results'][0];

        self::assertSame('/communities/dirty-slug', $row['group_url']);
    }

    public function testMetaReflectsTrimmedQueryAndCount(): void
    {
        // Absorbed from the v1.68 canonicalization tests.
        $out = $this->searchWith(new GroupDTO(1, 'D', 'd', null, null, null));

        self::assertSame(1, $out['meta']['count']);
        self::assertSame('q', $out['meta']['query']);
    }
}
