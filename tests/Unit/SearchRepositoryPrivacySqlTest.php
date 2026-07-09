<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Repositories\SearchRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the page-search privacy boundary.
 *
 * ## Why this exists
 *
 * /search (pages) and the trending fallback are public discovery surfaces.
 * The 2026-07-09 audit fix (finding H3) added a clause that excludes PeepSo
 * SECRET pages (peepso_page_privacy = 2) — invisible to non-members — from
 * page results, mirroring the secret-GROUP exclusion that GroupSearchRepository
 * already had. Because page privacy is a post-META (not a post_status) and these
 * are raw $wpdb queries (not WP_Query), PeepSo's own posts_clauses privacy filter
 * never runs; the exclusion has to live in this SQL. Dropping it silently turns
 * page search + trending into "show every secret page."
 *
 * The identical `pm_priv` LEFT JOIN + `<> 2` exclusion is applied at all three
 * page-ID sources — searchCandidates() (FULLTEXT + title-prefix), hydratePages()
 * (the hydration chokepoint that also covers primary-trending IDs), and
 * getFallbackPageIds() (trending fallback). getFallbackPageIds is the
 * dependency-free one, so we pin the shared pattern here with a hand-rolled
 * $wpdb double (no MySQL) that captures the prepared template.
 */
#[CoversClass(SearchRepository::class)]
final class SearchRepositoryPrivacySqlTest extends TestCase
{
    private object $wpdb;
    private mixed $prevWpdb = null;
    private bool $hadWpdb = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadWpdb   = array_key_exists('wpdb', $GLOBALS);
        $this->prevWpdb  = $GLOBALS['wpdb'] ?? null;
        $this->wpdb      = $this->makeFakeWpdb();
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

    private function makeFakeWpdb(): object
    {
        return new class {
            public string $posts      = 'wp_posts';
            public string $postmeta   = 'wp_postmeta';
            public string $prefix     = 'wp_';
            public string $last_error = '';
            public ?string $lastQuery = null;
            /** @var list<mixed> */
            public array $lastArgs = [];

            public function prepare(string $query, mixed ...$args): string
            {
                $this->lastQuery = $query;
                $this->lastArgs  = $args;
                return $query; // fed straight into get_col()
            }

            /** @return list<int> */
            public function get_col(string $query): array
            {
                return [];
            }
        };
    }

    public function testTrendingFallbackExcludesSecretPages(): void
    {
        SearchRepository::getFallbackPageIds(10);

        $sql = (string) $this->wpdb->lastQuery;

        // The privacy LEFT JOIN must be present, keyed on the PeepSo meta.
        self::assertStringContainsString('peepso_page_privacy', $sql);
        // And the SECRET (2) exclusion — NULL (no meta = open) OR value <> 2.
        self::assertStringContainsString('pm_priv.meta_value IS NULL', $sql);
        self::assertStringContainsString('CAST(pm_priv.meta_value AS UNSIGNED) <> 2', $sql);
        // Sanity: still scoped to published peepso pages.
        self::assertStringContainsString("post_type = 'peepso-page'", $sql);
        self::assertStringContainsString("post_status = 'publish'", $sql);
    }

    public function testTrendingFallbackClampsLimitArg(): void
    {
        SearchRepository::getFallbackPageIds(10);
        self::assertContains(10, $this->wpdb->lastArgs, 'limit must be a bound arg');
    }
}
