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
 * Regression tests for the group-search URL projection.
 *
 * ## Why this exists
 *
 * /search/groups feeds the headless Next.js frontend, which renders each
 * result with `href={toInternalHref(row.group_url)}`. toInternalHref
 * REQUIRES a relative in-app route — a dev-only tripwire warns when the
 * URL is absolute, because an absolute WP/PeepSo URL makes <Link>
 * navigate the user OFF the headless app. GroupSearchService::search()
 * is the single seam that projects each GroupDTO into the public shape.
 * It used to emit an absolute PeepSo groups-page URL (a latent contract
 * violation); it now emits the RELATIVE canonical community route
 * /communities/{slug}. bcc-search has no hall awareness (GroupDTO carries
 * no type), and /communities/[slug] renders every group kind — halls
 * included — so search always uses the single cross-kind route. These
 * tests pin the relative shape so a refactor can't re-send users off-app.
 *
 * ## Isolation strategy
 *
 * Each test runs in its own subprocess. setUp() pulls in
 * tests/Stubs/group-search-repo-stub.php which defines a fake
 * GroupSearchRepository at the production FQN, then requires the real
 * GroupSearchService — so the service reads fixture GroupDTOs without
 * touching the DB. The stub file is never loaded in the main process, so
 * other tests see the real classes.
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

    public function testGroupUrlIsRelativeCommunityRouteFromSlug(): void
    {
        $slug = 'alpha-crew';
        \BCC\Search\Repositories\GroupSearchRepository::$return = [
            new GroupDTO(5, 'Alpha Crew', $slug, null, null),
        ];

        $row = (new GroupSearchService())->search('a', 20)['results'][0];

        // Contract: group_url is the RELATIVE Next.js community route
        // (/communities/{slug}), never an absolute WP/PeepSo URL — an
        // absolute URL here navigates users off the headless frontend.
        self::assertSame('/communities/' . $slug, $row['group_url']);
    }

    public function testGroupUrlIsRelativeForEveryResult(): void
    {
        \BCC\Search\Repositories\GroupSearchRepository::$return = [
            new GroupDTO(5, 'Alpha Crew', 'alpha-crew', null, null),
            new GroupDTO(6, 'Beta Hall', 'beta-hall', null, 'A hall'),
        ];

        $rows = (new GroupSearchService())->search('a', 20)['results'];

        self::assertCount(2, $rows);
        // Cross-kind route: a hall projects the SAME /communities/ route —
        // search has no type, and /communities/[slug] renders halls too.
        self::assertSame('/communities/alpha-crew', $rows[0]['group_url']);
        self::assertSame('/communities/beta-hall', $rows[1]['group_url']);

        // Hard regression guard: no result may carry an absolute origin.
        foreach ($rows as $row) {
            self::assertStringStartsWith('/communities/', $row['group_url']);
            self::assertStringNotContainsString('http://', $row['group_url']);
            self::assertStringNotContainsString('https://', $row['group_url']);
        }
    }

    public function testResultKeysAreExactlyThePublicShape(): void
    {
        \BCC\Search\Repositories\GroupSearchRepository::$return = [
            new GroupDTO(7, 'Seven', 'seven', null, null),
        ];

        $row = (new GroupSearchService())->search('s', 20)['results'][0];

        self::assertSame(
            ['id', 'name', 'slug', 'description', 'avatar_url', 'group_url'],
            array_keys($row),
        );
    }

    public function testMetaReflectsTrimmedQueryAndCount(): void
    {
        \BCC\Search\Repositories\GroupSearchRepository::$return = [
            new GroupDTO(1, 'D', 'd', null, null),
        ];

        $out = (new GroupSearchService())->search('  hello  ', 20);

        self::assertSame(1, $out['meta']['count']);
        self::assertSame('hello', $out['meta']['query']);
    }
}
