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
 * The `?kind=` page-type filter in searchCandidates() (F-589).
 *
 * ## What this pins
 *
 * The filter is one prepared clause ANDed into the candidate reads' shared
 * WHERE, next to the published / non-secret rule, on BOTH the FULLTEXT
 * statement and the title-prefix fallback. Its placeholders come first in
 * each prepare(), because the shared WHERE comes first in the SQL: put them
 * anywhere else and the type lands in the LIKE and the search term in the
 * type comparison.
 *
 * The clause reads a page's EFFECTIVE type: the first stored
 * `_bcc_page_type` row (what get_post_meta(..., true) returns), with no row
 * or an empty one standing for UNTYPED_PAGE_TYPE. The controller links
 * untyped pages by that same constant, so a page listed under a kind links
 * to that kind's card route (SearchControllerHandleSearchTest pins that
 * end to end).
 *
 * The REAL bcc-core privacy class is loaded from the checkout beside this
 * plugin; a $wpdb double records what is sent (see the stubs file).
 */
#[CoversClass(SearchRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SearchRepositoryPageKindSqlTest extends TestCase
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

    public function testNoPageTypeAddsNoClause(): void
    {
        SearchRepository::searchCandidates('ab', '', 10);

        self::assertStringNotContainsString('_bcc_page_type', $this->onlyPageRead());
    }

    public function testThePrefixFallbackKeepsOnlyThatTypeInsideTheVisibleSet(): void
    {
        SearchRepository::searchCandidates('ab', '', 10, 'nft');

        $sql = $this->onlyPageRead();
        self::assertSame(1, substr_count($sql, self::clause('nft')), $sql);
        self::assertSame(1, substr_count($sql, PeepSoPagePrivacy::notSecretSql('p.ID')));
        self::assertStringContainsString("p.post_type = 'peepso-page' AND p.post_status = 'publish'", $sql);
        // The other arguments are still where they belong.
        self::assertStringContainsString("p.post_title LIKE 'ab%'", $sql);
        self::assertStringEndsWith('LIMIT 10', $sql);
        // In the WHERE, ahead of the search term.
        self::assertLessThan(strpos($sql, 'p.post_title LIKE'), strpos($sql, self::clause('nft')));
    }

    public function testTheFullTextReadAndItsFallbackBothCarryTheClause(): void
    {
        // The FULLTEXT branch is gated on this option. It answers no rows,
        // so the title-prefix fallback runs too: both statements are seen.
        $GLOBALS['__bcc_options']['bcc_ft_index_v2_installed'] = 1;

        SearchRepository::searchCandidates('matrix', '', 10, 'validator');

        $reads = $this->pageReads();
        self::assertCount(2, $reads, 'expected the FULLTEXT read and its fallback');

        [$ft, $prefix] = $reads;
        self::assertStringContainsString('MATCH(', $ft, 'the FULLTEXT read never ran, so this proves nothing');
        self::assertSame(1, substr_count($ft, self::clause('validator')), $ft);
        self::assertSame(2, substr_count($ft, "AGAINST('matrix*' IN BOOLEAN MODE)"), $ft);
        self::assertStringEndsWith('LIMIT 10', $ft);
        self::assertLessThan(strpos($ft, 'MATCH('), strpos($ft, self::clause('validator')));

        self::assertStringNotContainsString('MATCH(', $prefix);
        self::assertSame(1, substr_count($prefix, self::clause('validator')), $prefix);
        self::assertStringContainsString("p.post_title LIKE 'matrix%'", $prefix);
    }

    public function testTheProjectTypeIsTheUntypedDefault(): void
    {
        // Lockstep with bcc-trust's PageTypeMap::effectivePageType(), which
        // reads '' as 'builder' (the project kind).
        self::assertSame('builder', SearchRepository::UNTYPED_PAGE_TYPE);

        SearchRepository::searchCandidates('ab', '', 10, 'builder');

        self::assertSame(1, substr_count($this->onlyPageRead(), self::clause('builder')));
    }

    /**
     * The clause with its arguments filled in, whitespace collapsed: the
     * first stored row by meta_id, no row or '' read as the untyped
     * default, compared with the wanted type.
     */
    private static function clause(string $pageType): string
    {
        return "COALESCE(NULLIF(( SELECT ptm.meta_value FROM wp_postmeta ptm"
            . " WHERE ptm.post_id = p.ID AND ptm.meta_key = '_bcc_page_type'"
            . " ORDER BY ptm.meta_id ASC LIMIT 1 ), ''), '"
            . SearchRepository::UNTYPED_PAGE_TYPE . "') = '{$pageType}'";
    }

    /** @return list<string> every candidate read, whitespace collapsed */
    private function pageReads(): array
    {
        $reads = [];
        foreach ($this->wpdb->sent as $sql) {
            if (str_contains($sql, 'FROM wp_posts p')) {
                $reads[] = trim((string) preg_replace('/\s+/', ' ', $sql));
            }
        }
        return $reads;
    }

    private function onlyPageRead(): string
    {
        $reads = $this->pageReads();
        self::assertCount(1, $reads, 'exactly one page read was sent');
        return $reads[0];
    }
}
