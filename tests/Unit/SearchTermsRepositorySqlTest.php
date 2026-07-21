<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Repositories\SearchTermsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * SQL + guard behaviour for the search-analytics store.
 *
 * Pins the load-bearing shapes: the aggregate UPSERT records the
 * NORMALIZED+truncated term (never raw), zero-result restriction adds the
 * MAX(result_count)=0 HAVING, the prune bounds by the retention window +
 * a batch LIMIT, and every write is a no-op when the table isn't installed
 * (so analytics can never break a search). Uses the subprocess env stub —
 * option/dbDelta fakes + a query-recording $wpdb double, no MySQL.
 */
#[CoversClass(SearchTermsRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SearchTermsRepositorySqlTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/search-terms-env-stub.php';
        $GLOBALS['__bcc_terms_opts']    = ['bcc_search_terms_installed' => 1];
        $GLOBALS['__bcc_terms_dbdelta'] = [];
        $this->wpdb      = new \SearchTermsFakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /** @return array{sql: string, args: list<mixed>} */
    private function lastPrepared(): array
    {
        $all = $this->wpdb->prepared;
        self::assertNotEmpty($all, 'expected a prepared query');
        return $all[count($all) - 1];
    }

    public function testRecordUpsertsTheNormalizedTruncatedTerm(): void
    {
        SearchTermsRepository::record('projects', 'cosmos validator', 7);

        $p = $this->lastPrepared();
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $p['sql']);
        self::assertStringContainsString('hits = hits + 1', $p['sql']);
        // Term + vertical + count are bound args, not interpolated.
        self::assertContains('cosmos validator', $p['args']);
        self::assertContains('projects', $p['args']);
        self::assertContains(7, $p['args']);
    }

    public function testRecordTruncatesAnOverlongTermToTheColumnWidth(): void
    {
        $long = str_repeat('a', SearchTermsRepository::TERM_MAXLEN + 50);
        SearchTermsRepository::record('users', $long, 0);

        $p = $this->lastPrepared();
        $termArg = $p['args'][0];
        self::assertIsString($termArg);
        self::assertSame(SearchTermsRepository::TERM_MAXLEN, mb_strlen($termArg));
    }

    public function testRecordSkipsEmptyTerm(): void
    {
        SearchTermsRepository::record('trending', '   ', 3);
        self::assertSame([], $this->wpdb->prepared, 'empty term must not write');
    }

    public function testRecordIsNoOpWhenTableNotInstalled(): void
    {
        $GLOBALS['__bcc_terms_opts'] = []; // installed flag absent
        SearchTermsRepository::record('projects', 'cosmos', 5);
        self::assertSame([], $this->wpdb->prepared, 'must not write before install');
    }

    public function testTopTermsZeroOnlyRestrictsToNoResultTerms(): void
    {
        SearchTermsRepository::topTerms(30, 25, true);

        $p = $this->lastPrepared();
        self::assertStringContainsString('HAVING MAX(result_count) = 0', $p['sql']);
        self::assertStringContainsString('GROUP BY norm_term, vertical', $p['sql']);
    }

    public function testTopTermsWithoutZeroOnlyOmitsTheHaving(): void
    {
        SearchTermsRepository::topTerms(30, 25, false);

        $p = $this->lastPrepared();
        self::assertStringNotContainsString('HAVING', $p['sql']);
        self::assertStringContainsString('ORDER BY SUM(hits) DESC', $p['sql']);
    }

    public function testPruneBoundsByRetentionWindowAndBatchLimit(): void
    {
        SearchTermsRepository::prune();

        $p = $this->lastPrepared();
        self::assertStringContainsString('DELETE FROM', $p['sql']);
        self::assertStringContainsString('WHERE day < %s', $p['sql']);
        self::assertStringContainsString('LIMIT %d', $p['sql']);
        // The batch size is a bound arg (bounded delete, no table lock).
        self::assertContains(1000, $p['args']);
    }
}
