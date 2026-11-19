<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\DTO\GroupDTO;
use BCC\Search\Repositories\GroupSearchRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the group-search privacy boundary + projection.
 *
 * ## Why this exists
 *
 * /search/groups is a public discovery surface. The 2026-07-06 audit fix
 * (commit eeb233c) added a clause that excludes PeepSo SECRET groups
 * (peepso_group_privacy = 2) from results while keeping CLOSED groups
 * findable by name — the same browse semantics as bcc-core's
 * listBrowsableGroupIds(). Because that exclusion lives in raw SQL, we
 * cover it with a hand-rolled $wpdb double (no MySQL): the fake captures
 * the prepared template + bound args so we can assert the exclusion is
 * actually there. Dropping it would silently turn group search into
 * "show every secret group."
 *
 * ⚠ F-429 CHANGED WHAT "actually there" MEANS. This file used to assert the
 * exclusion by looking for `pm_priv.meta_value IS NULL` and a bound `2`.
 * That form was the defect: it listed a group whenever ANY of its privacy
 * rows was not 2 — so a group carrying both a '0' row and a '2' row was
 * findable — and the LEFT JOIN returned such a group once per row. The
 * exclusion is now bcc-core's PeepSoGroupPrivacy::notSecretSql(), a
 * correlated NOT EXISTS with no join and no bound sentinel, so the old
 * assertions had to be replaced rather than kept alongside: they would now
 * fail on correct code, which is the worst kind of test.
 *
 * These remain SOURCE-SHAPE assertions — a fake $wpdb cannot run SQL. The
 * behaviour they stand for (two-row group hidden, no duplicate rows) is
 * proven against a real SQL engine in the F-429 PR.
 */
#[CoversClass(GroupSearchRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupSearchRepositorySqlTest extends TestCase
{
    private object $wpdb;
    private mixed $prevWpdb = null;
    private bool $hadWpdb = false;

    protected function setUp(): void
    {
        parent::setUp();
        // F-429: the privacy rule now lives in bcc-core. Loaded from the
        // checkout beside this plugin, exactly as the pages twin does
        // (SearchRepositoryPrivacySqlTest) — which is also why this class
        // runs in separate processes: a required file would otherwise leak
        // into the rest of the unit run.
        self::requireCore('src/Repositories/PeepSoGroupPrivacy.php');

        $this->hadWpdb  = array_key_exists('wpdb', $GLOBALS);
        $this->prevWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb     = $this->makeFakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        if ($this->hadWpdb) {
            $GLOBALS['wpdb'] = $this->prevWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }
        parent::tearDown();
    }

    /**
     * A file of the bcc-core checkout beside this plugin. In CI a missing
     * file FAILS (a skip is invisible in a green run); locally a bare clone
     * may have no sibling, so it skips there. Same contract as
     * SearchRepositoryPrivacySqlTest::requireCore().
     */
    private static function requireCore(string $relPath): void
    {
        $path = dirname(__DIR__, 3) . '/bcc-core/' . $relPath;
        if (is_file($path)) {
            require_once $path;
            return;
        }
        $inCi = (string) getenv('GITHUB_ACTIONS') !== '' || strtolower((string) getenv('CI')) === 'true';
        if ($inCi) {
            self::fail('bcc-core file missing at ' . $path . ' while running in CI.');
        }
        self::markTestSkipped('bcc-core sibling not present at ' . $path . ' (expected outside CI).');
    }

    private function makeFakeWpdb(): object
    {
        return new class {
            public string $posts      = 'wp_posts';
            public string $postmeta   = 'wp_postmeta';
            public string $last_error = '';
            public ?string $lastQuery = null;
            /** @var list<mixed> */
            public array $lastArgs = [];
            /** @var list<array<string,mixed>> */
            public array $rows = [];

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            public function prepare(string $query, mixed ...$args): string
            {
                $this->lastQuery = $query;
                $this->lastArgs  = $args;
                // GroupSearchRepository only feeds the return value straight
                // into get_results(), so returning the template is enough.
                return $query;
            }

            /**
             * @return list<array<string,mixed>>
             */
            public function get_results(string $query, string $output): array
            {
                return $this->rows;
            }
        };
    }

    public function testQueryExcludesSecretGroupsWithTheSharedRule(): void
    {
        $this->wpdb->rows = [];

        GroupSearchRepository::search('dev', 20);

        $sql = (string) $this->wpdb->lastQuery;

        // The shared NOT EXISTS, correlated on the group id. Each part is
        // load-bearing: without the meta_key the predicate would match any
        // meta row, and without `= 2` it would hide every group that has a
        // privacy row at all.
        self::assertStringContainsString('NOT EXISTS (', $sql);
        self::assertStringContainsString("bcc_group_priv.post_id = p.ID", $sql);
        self::assertStringContainsString("meta_key = 'peepso_group_privacy'", $sql);
        self::assertStringContainsString('= 2', $sql);

        self::assertContains('_bcc_group_kind', $this->wpdb->lastArgs);
        self::assertContains('peepso-group', $this->wpdb->lastArgs);
        self::assertContains('publish', $this->wpdb->lastArgs);
    }

    /**
     * ⭐ The superseded form must not come back — and the join matters on its
     * own, not just the comparison. A future edit that re-added the join and
     * kept `NOT EXISTS` would stop leaking but would still fan out, and
     * `LIMIT` is applied before the hydration loop collapses by ID, so the
     * page would silently come back short.
     */
    public function testTheSupersededPrivacyFormIsGone(): void
    {
        $this->wpdb->rows = [];

        GroupSearchRepository::search('dev', 20);

        $sql = (string) $this->wpdb->lastQuery;

        self::assertStringNotContainsString('pm_priv', $sql, 'the privacy join is back');
        self::assertStringNotContainsString('IS NULL', $sql);
        self::assertStringNotContainsString('<> %d', $sql);
        // The sentinel and the meta key are inside the shared fragment now,
        // so neither is a bound argument. Either reappearing here means the
        // inline copy has been reinstated.
        self::assertNotContains(2, $this->wpdb->lastArgs);
        self::assertNotContains('peepso_group_privacy', $this->wpdb->lastArgs);
    }

    public function testSubstringMatchWithPrefixFirstRankingAndKindJoin(): void
    {
        $this->wpdb->rows = [];

        GroupSearchRepository::search('dev', 20);

        $sql = (string) $this->wpdb->lastQuery;

        // v1.70: substring WHERE + prefix-first ORDER BY (two separate
        // LIKE bindings) — prefix-only matching made "hall" unable to
        // find "Cosmos Hall".
        self::assertStringContainsString('AND p.post_title LIKE %s', $sql);
        self::assertStringContainsString(
            'ORDER BY (p.post_title LIKE %s) DESC, p.post_title ASC',
            $sql,
        );
        self::assertContains('%dev%', $this->wpdb->lastArgs, 'substring pattern must be bound');
        self::assertContains('dev%', $this->wpdb->lastArgs, 'prefix pattern must be bound for ranking');

        // v1.70: kind arrives batched through the third LEFT JOIN — the
        // service must never fall back to per-row get_post_meta.
        self::assertStringContainsString('pm_kind.meta_value AS group_kind_raw', $sql);
    }

    public function testEscLikeNeutralizesWildcardsBeforeWrapping(): void
    {
        $this->wpdb->rows = [];

        GroupSearchRepository::search('50%_x', 20);

        // addcslashes('50%_x', '_%\\') === '50\%\_x' — a literal % or _
        // in the query must match itself, never widen the scan.
        self::assertContains('%50\\%\\_x%', $this->wpdb->lastArgs);
        self::assertContains('50\\%\\_x%', $this->wpdb->lastArgs);
    }

    public function testLimitIsClampedToMax(): void
    {
        $this->wpdb->rows = [];

        GroupSearchRepository::search('dev', 999);

        self::assertContains(50, $this->wpdb->lastArgs, 'limit must clamp to MAX_LIMIT (50)');
    }

    public function testBlankQueryShortCircuitsWithoutHittingDb(): void
    {
        $dtos = GroupSearchRepository::search('   ', 20);

        self::assertSame([], $dtos);
        self::assertNull($this->wpdb->lastQuery, 'blank query must never reach the DB');
    }

    public function testProjectsRowsIntoDtosWithClampedDescription(): void
    {
        $this->wpdb->rows = [
            [
                'ID'             => 5,
                'post_title'     => 'Alpha Crew',
                'post_name'      => 'alpha-crew',
                'post_excerpt'   => str_repeat('x', 200),
                'avatar_hash'    => 'abc123',
                'group_kind_raw' => 'hall',
            ],
            [
                'ID'             => 6,
                'post_title'     => 'Beta Crew',
                'post_name'      => 'beta-crew',
                'post_excerpt'   => '',
                'avatar_hash'    => '',
                'group_kind_raw' => '',
            ],
            [
                // No group_kind_raw key at all (meta row absent).
                'ID'           => 7,
                'post_title'   => 'Gamma Crew',
                'post_name'    => 'gamma-crew',
                'post_excerpt' => '',
                'avatar_hash'  => '',
            ],
        ];

        $dtos = GroupSearchRepository::search('a', 20);

        self::assertCount(3, $dtos);
        self::assertInstanceOf(GroupDTO::class, $dtos[0]);
        self::assertSame(5, $dtos[0]->id);
        self::assertSame('Alpha Crew', $dtos[0]->name);
        self::assertSame('alpha-crew', $dtos[0]->slug);
        self::assertSame('abc123', $dtos[0]->avatarHash);
        self::assertSame('hall', $dtos[0]->kindRaw);

        // 160-char clamp: 157 kept + ellipsis = 158 chars.
        self::assertNotNull($dtos[0]->description);
        self::assertSame(158, mb_strlen($dtos[0]->description));
        self::assertStringEndsWith('…', $dtos[0]->description);

        // Empty excerpt → null description; empty avatar hash → null;
        // empty or absent kind meta → null kindRaw.
        self::assertNull($dtos[1]->description);
        self::assertNull($dtos[1]->avatarHash);
        self::assertNull($dtos[1]->kindRaw);
        self::assertNull($dtos[2]->kindRaw);
    }

    public function testDuplicateJoinFanoutRowsCollapseToOneDto(): void
    {
        // Postmeta LEFT-JOIN fan-out: a group with two rows of any
        // joined meta key produces N result rows for one ID — the
        // hydration loop must collapse them (first row wins) so
        // duplicate DTOs never become duplicate React keys downstream.
        $this->wpdb->rows = [
            [
                'ID'             => 5,
                'post_title'     => 'Alpha Crew',
                'post_name'      => 'alpha-crew',
                'post_excerpt'   => '',
                'avatar_hash'    => 'hash-a',
                'group_kind_raw' => 'hall',
            ],
            [
                'ID'             => 5,
                'post_title'     => 'Alpha Crew',
                'post_name'      => 'alpha-crew',
                'post_excerpt'   => '',
                'avatar_hash'    => 'hash-b',
                'group_kind_raw' => 'hall',
            ],
            [
                'ID'             => 6,
                'post_title'     => 'Beta Crew',
                'post_name'      => 'beta-crew',
                'post_excerpt'   => '',
                'avatar_hash'    => '',
                'group_kind_raw' => '',
            ],
        ];

        $dtos = GroupSearchRepository::search('a', 20);

        self::assertCount(2, $dtos);
        self::assertSame(5, $dtos[0]->id);
        self::assertSame('hash-a', $dtos[0]->avatarHash, 'first row wins');
        self::assertSame('hall', $dtos[0]->kindRaw);
        self::assertSame(6, $dtos[1]->id);
    }

    public function testThrowsOnRowMissingId(): void
    {
        $this->wpdb->rows = [
            ['post_title' => 'No ID', 'post_name' => 'no-id', 'post_excerpt' => ''],
        ];

        $this->expectException(\LogicException::class);
        GroupSearchRepository::search('n', 20);
    }
}
