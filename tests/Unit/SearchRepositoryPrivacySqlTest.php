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
