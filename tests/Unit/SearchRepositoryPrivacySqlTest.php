<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Core\Repositories\PeepSoPagePrivacy;
use BCC\Search\Repositories\SearchRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The page-search privacy boundary.
 *
 * ## Why this exists
 *
 * /search (pages) and trending are public discovery surfaces. PeepSo SECRET
 * pages (peepso_page_privacy = 2) are invisible to non-members, but privacy
 * is a post-META, not a post_status, and these are raw $wpdb queries that
 * PeepSo's own posts_clauses filter never sees. So the exclusion lives in
 * this SQL [audit H3]. Dropping it silently turns page search and trending
 * into "show every secret page".
 *
 * All three page-id sources apply bcc-core's shared rule,
 * PeepSoPagePrivacy::notSecretSql('p.ID'): searchCandidates() (here its
 * title-prefix path), hydratePages() (the chokepoint that also hydrates
 * primary-trending ids) and getFallbackPageIds() (trending fallback). It
 * replaced a LEFT JOIN + `<> 2` copy in each, which let a page carrying two
 * privacy rows (0 and 2) through. The SQL itself runs on MySQL 8 and MariaDB
 * in bcc-trust's SecretPageVisibilityIntegrationTest.
 *
 * The REAL bcc-core class is loaded from the checkout beside this plugin
 * (CI checks it out there); a $wpdb double records what is sent.
 */
#[CoversClass(SearchRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SearchRepositoryPrivacySqlTest extends TestCase
{
    private \BccSecretPageSearchFakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/secret-page-search-stubs.php';
        \BCC\Search\Tests\Support\CoreSibling::require('src/Repositories/ProbesReads.php');
        \BCC\Search\Tests\Support\CoreSibling::require('src/Repositories/PeepSoPagePrivacy.php');

        $this->wpdb      = new \BccSecretPageSearchFakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testTheTrendingFallbackAppliesTheSharedRule(): void
    {
        SearchRepository::getFallbackPageIds(10);

        $sql = $this->onlyPageRead();
        self::assertSame(1, substr_count($sql, self::rule()));
        self::assertStringContainsString("post_type = 'peepso-page'", $sql);
        self::assertStringContainsString("post_status = 'publish'", $sql);
        self::assertStringContainsString('LIMIT 10', $sql, 'the limit is bound');
    }

    public function testHydrationAppliesTheSharedRule(): void
    {
        SearchRepository::hydratePages([5, 6]);

        $sql = $this->onlyPageRead();
        self::assertSame(1, substr_count($sql, self::rule()));
        self::assertStringContainsString('p.ID IN (5,6)', $sql);
    }

    public function testCandidateSearchAppliesTheSharedRule(): void
    {
        SearchRepository::searchCandidates('ab', '', 10);

        $sql = $this->onlyPageRead();
        self::assertSame(1, substr_count($sql, self::rule()));
        self::assertStringContainsString("post_type = 'peepso-page'", $sql);
    }

    /**
     * ⭐⭐ A FULLTEXT read that bails must NOT return another query's
     * rows as candidates.
     *
     * That path falls through to the title-prefix LIKE on error —
     * deliberately, degraded but available. The policy is about ERRORS. A
     * pre-`flush()` bail is different: `wpdb::query()` returns false above
     * `flush()`, so `last_error` is EMPTY and `last_result` still holds the
     * PREVIOUS query's rows. The old `last_error` check read that as
     * success and handed those rows back — rows that never passed THIS
     * query's `$base_where`, i.e. past the secret-page rule every other
     * test in this file asserts.
     *
     * ⚠ The bail is scoped to the FULLTEXT query so the title-prefix
     * fallback still answers. That is what makes the assertion mean
     * something: an empty result here says the stale rows were DISCARDED
     * and the fallback ran, not that something threw before anyone looked.
     */
    public function testAFullTextBailDoesNotReturnAnotherQuerysRowsAsCandidates(): void
    {
        // Rows left behind by an unrelated query — a secret page, no less.
        // The FULLTEXT branch is gated on this option; without it the
        // branch never runs and this test would assert nothing.
        $GLOBALS['__bcc_options']['bcc_ft_index_v2_installed'] = 1;

        $this->wpdb->last_result = [
            (object) ['ID' => '999', 'post_title' => 'Someone\'s secret page', 'post_name' => 'secret'],
        ];
        $this->wpdb->bailOnMatch = true;

        // The fall-through logs. Point error_log at a file for the duration
        // rather than muting it: PHPUnit treats the output as a failure, and
        // capturing it lets the log line itself be asserted below.
        $logFile = (string) tempnam(sys_get_temp_dir(), 'bcc-ft-log');
        $prevLog = (string) ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $dtos = SearchRepository::searchCandidates('matrix', '', 10);
        } finally {
            ini_set('error_log', $prevLog);
        }

        $logged = (string) @file_get_contents($logFile);
        @unlink($logFile);

        self::assertSame([], $dtos, 'another query\'s rows came back as candidates');
        self::assertSame('', $this->wpdb->last_error, 'the premise: the bail sets no error');

        // And it really did fall through: a non-FULLTEXT read was sent.
        $prefixReads = array_values(array_filter(
            $this->wpdb->sent,
            static fn (string $sql): bool => !str_contains($sql, 'MATCH(')
                && str_contains($sql, 'FROM wp_posts p')
        ));
        self::assertNotSame([], $prefixReads, 'the fallback read never ran, so nothing was discarded');

        // ANTI-VACUITY: prove the FULLTEXT query was actually SENT. Without
        // this the test passes when the FT branch is skipped entirely (no
        // index in this harness), asserting nothing about the bail.
        $ftReads = array_values(array_filter(
            $this->wpdb->sent,
            static fn (string $sql): bool => str_contains($sql, 'MATCH(')
        ));
        self::assertNotSame([], $ftReads, 'the FULLTEXT query never ran, so this test proves nothing');

        // And it said WHY, naming the absence of a driver error -- the
        // detail that distinguishes a bail from an ordinary failure.
        self::assertStringContainsString('could not be trusted', $logged);
        self::assertStringContainsString('no driver error reported', $logged);
    }

    /**
     * getCategories had NO test at all, which is how a missing argument
     * shipped: `objectsOrThrow()` was called without its `$context`, and
     * because an ArgumentCountError is an \Error rather than a
     * RuntimeException, the controller's catch would have missed it and
     * every `type=` filter would have 500'd. The full suite passed over
     * the whole method.
     *
     * So this pins the happy path AND, by exercising the call at all, makes
     * a dropped argument fail here instead of in production.
     */
    public function testCategoriesComeBackFromATrustedRead(): void
    {
        $this->wpdb->resultRows = [
            (object) ['slug' => 'guides', 'name' => 'Guides'],
        ];

        $options = SearchRepository::getCategories();

        self::assertSame(
            [
                ['slug' => '', 'name' => 'All Types'],
                ['slug' => 'guides', 'name' => 'Guides'],
            ],
            $options
        );
    }

    /** No copy of the old form is left beside the shared rule. */
    public function testTheOldLeftJoinFormIsGone(): void
    {
        SearchRepository::getFallbackPageIds(10);
        SearchRepository::hydratePages([5]);
        SearchRepository::searchCandidates('ab', '', 10);

        foreach ($this->wpdb->sent as $sql) {
            self::assertStringNotContainsString('pm_priv', $sql);
        }
    }

    private static function rule(): string
    {
        return PeepSoPagePrivacy::notSecretSql('p.ID');
    }

    /** The one statement that reads `peepso-page` ids or rows. */
    private function onlyPageRead(): string
    {
        $reads = array_values(array_filter(
            $this->wpdb->sent,
            static fn (string $sql): bool => str_contains($sql, 'FROM wp_posts p')
        ));
        self::assertCount(1, $reads, 'exactly one page read was sent');
        return $reads[0];
    }

}
