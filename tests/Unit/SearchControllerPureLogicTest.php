<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Controllers\SearchController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic pins for SearchController's private ranking helpers.
 *
 * These three methods (getCandidateCap, computeTextScore, blendRankScore)
 * are side-effect-free math; they are exercised via reflection (same
 * pattern as bcc-trust's StokeRepositoryTest) so no WP/REST harness is
 * needed and the production file is loaded unmodified in the main
 * process. Behavioural end-to-end pins (demotion, PRERANK_TOP_K, empty
 * short-circuits) live in SearchControllerHandleSearchTest.
 *
 * Assertions here pin ORDERINGS and boundary values, not exact float
 * constants, so a deliberate re-weighting of the blend shows up as an
 * intentional test change while accidental inversions fail loudly.
 */
#[CoversClass(SearchController::class)]
final class SearchControllerPureLogicTest extends TestCase
{
    private function invoke(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(SearchController::class, $method);
        return $rm->invoke(new SearchController(), ...$args);
    }

    private function textScore(string $title, string $query): float
    {
        $score = $this->invoke('computeTextScore', $title, $query);
        self::assertIsFloat($score);
        return $score;
    }

    private function blend(float $text, float $composite): float
    {
        $score = $this->invoke('blendRankScore', $text, $composite);
        self::assertIsFloat($score);
        return $score;
    }

    // ── getCandidateCap: dynamic 50/80/100 cap by query length ─────────

    public function testCandidateCapIsFiftyForTwoCharQueries(): void
    {
        self::assertSame(50, $this->invoke('getCandidateCap', 'ab'));
    }

    public function testCandidateCapIsEightyForThreeAndFourCharQueries(): void
    {
        self::assertSame(80, $this->invoke('getCandidateCap', 'abc'));
        self::assertSame(80, $this->invoke('getCandidateCap', 'abcd'));
    }

    public function testCandidateCapIsHundredFromFiveCharsUp(): void
    {
        self::assertSame(100, $this->invoke('getCandidateCap', 'abcde'));
        self::assertSame(100, $this->invoke('getCandidateCap', str_repeat('x', 100)));
    }

    public function testCandidateCapCountsCharactersNotBytes(): void
    {
        // 'ééé' is 3 characters / 6 bytes: a strlen()-based regression
        // would jump the 5-char tier and return 100 instead of 80.
        self::assertSame(80, $this->invoke('getCandidateCap', 'ééé'));
        self::assertSame(50, $this->invoke('getCandidateCap', 'éé'));
    }

    // ── computeTextScore: match-class ordering ─────────────────────────

    public function testTextScoreOrdersExactOverPrefixOverSubstringOverMiss(): void
    {
        $exact     = $this->textScore('acme', 'acme');
        $prefix    = $this->textScore('acmex', 'acme');
        $substring = $this->textScore('my acme', 'acme');
        $miss      = $this->textScore('zzzz', 'acme');

        self::assertGreaterThan($prefix, $exact);
        self::assertGreaterThan($substring, $prefix);
        self::assertGreaterThan($miss, $substring);
    }

    public function testTextScoreIsCaseInsensitive(): void
    {
        self::assertSame(
            $this->textScore('ACME', 'acme'),
            $this->textScore('acme', 'ACME'),
        );
    }

    public function testTextScoreRanksEarlierSubstringOccurrenceHigher(): void
    {
        // Equal title lengths isolate the position term from the length bonus.
        $early = $this->textScore('xacmexxxx', 'acme');
        $late  = $this->textScore('xxxxxacme', 'acme');

        self::assertGreaterThan($late, $early);
    }

    public function testTextScorePrefersShorterTitleWithinSameMatchClass(): void
    {
        // Both are prefix matches; the length bonus must favour the
        // tighter title.
        $short = $this->textScore('acme co', 'acme');
        $long  = $this->textScore('acme co ltd international', 'acme');

        self::assertGreaterThan($long, $short);
    }

    // ── blendRankScore: 60% trust / 40% text, composite soft-capped ────

    public function testBlendWeightsSixtyTrustFortyText(): void
    {
        // Text-only signal contributes at most 0.4 …
        self::assertEqualsWithDelta(0.4, $this->blend(1.0, 0.0), 1e-12);
        // … trust-only (at the 80 soft cap) contributes at most 0.6 …
        self::assertEqualsWithDelta(0.6, $this->blend(0.0, 80.0), 1e-12);
        // … both maxed = 1.0.
        self::assertEqualsWithDelta(1.0, $this->blend(1.0, 80.0), 1e-12);
    }

    public function testBlendSoftCapsCompositeScoreAtEighty(): void
    {
        // A runaway composite score cannot buy rank beyond the cap —
        // 80 and 8000 blend identically.
        self::assertSame(
            $this->blend(0.5, 80.0),
            $this->blend(0.5, 8000.0),
        );
        // Below the cap the composite still moves the blend.
        self::assertLessThan(
            $this->blend(0.5, 80.0),
            $this->blend(0.5, 40.0),
        );
    }
}
