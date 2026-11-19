<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Controllers\GroupSearchController;
use BCC\Search\Controllers\SearchController;
use BCC\Search\Controllers\UserSearchController;
use BCC\Search\Tests\Stubs\GenStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-442 — two races in the search verticals' generation counters.
 *
 * 1. **The one-second hole.** The flush stored a bare `time()`, so a bump
 *    landing in the same second as the current value produced the SAME
 *    value: the cache key did not change and the entry written just before
 *    a privacy flip stayed reachable for the rest of that second.
 * 2. **The population race.** The option fallback wrote with
 *    `wp_cache_set`, so a stale option read could overwrite a bump that
 *    landed between the cache read and the write — caching the pre-bump
 *    generation for the whole TTL, with a persistent object cache.
 *
 * ⭐ Fix 2 is NOT a blanket `wp_cache_add`, and the two branch tests below
 * are why. On a POISONED entry (present but invalid) `add` fails and the bad
 * value would stick for the TTL. So: `add` on a true miss, `set` on poison.
 * Both branches are asserted for every reader, because a change that got
 * only one right would look finished.
 *
 * The readers and flushes are private statics, exercised by reflection; the
 * race is injected through the stub's `onOptionRead` hook, which fires in
 * exactly the window the race lives in.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GenerationRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/generation-race-stubs.php';
        GenStore::reset();
    }

    /**
     * @param class-string $class
     * @return mixed
     */
    private static function callPrivate(string $class, string $method): mixed
    {
        $rm = new \ReflectionMethod($class, $method);
        return $rm->invoke(null);
    }

    /** @return iterable<string, array{0: class-string, 1: string, 2: string, 3: string, 4: string}> */
    public static function readers(): iterable
    {
        yield 'users vertical' => [
            UserSearchController::class,
            'getGeneration',
            'bcc_user_search_generation',
            'bustGeneration',
            'flushGenBumpIfQueued',
        ];
        yield 'groups vertical' => [
            GroupSearchController::class,
            'getGeneration',
            'bcc_group_search_generation',
            'bustGeneration',
            'flushGenBumpIfQueued',
        ];
        yield 'project search LKG' => [
            SearchController::class,
            'getLkgGeneration',
            'bcc_search_lkg_generation',
            'bust_search_privacy',
            'flushPrivacyBustIfQueued',
        ];
    }

    // ── the population race ────────────────────────────────────────────

    /**
     * ⭐ THE RACE. The cache is empty, the option says 5, and a concurrent
     * request bumps it to 9 while this one is reading the option. The
     * fallback must NOT put 5 back.
     *
     * @param class-string $class
     */
    #[DataProvider('readers')]
    public function testAConcurrentBumpSurvivesAMissPopulation(
        string $class,
        string $reader,
        string $optionKey
    ): void {
        GenStore::$options[$optionKey] = 5;
        $cacheKey = $optionKey;

        // Another request's bump lands in the window between our cache read
        // and our cache write.
        GenStore::$onOptionRead = static function () use ($cacheKey): void {
            wp_cache_set($cacheKey, 9, self::groupFor($cacheKey));
            GenStore::$writes = [];   // the racing write is not ours to assert on
        };

        $got = self::callPrivate($class, $reader);

        self::assertSame(
            9,
            GenStore::$cache[GenStore::k($cacheKey, self::groupFor($cacheKey))],
            'the stale option value overwrote a concurrent bump'
        );
        $writes = GenStore::writesFor($cacheKey);
        self::assertNotSame([], $writes, 'the reader wrote nothing at all');
        self::assertSame('add', $writes[0]['op'], 'a miss must be populated with add(), not set()');
        self::assertFalse($writes[0]['wrote'], 'add() should have lost to the concurrent bump');

        // The value this caller returns is its own read — 5 — which is fine:
        // it is one stale answer for one request, not a stale CACHE for a TTL.
        self::assertSame(5, $got);
    }

    /**
     * ⭐ THE OTHER BRANCH, and the reason a blanket add() would have been
     * wrong. A poisoned entry is PRESENT, so add() would fail and leave it
     * cached for the whole TTL with no path out. It must be overwritten.
     *
     * @param class-string $class
     */
    #[DataProvider('readers')]
    public function testAPoisonedEntryIsHealedRatherThanLeftInPlace(
        string $class,
        string $reader,
        string $optionKey
    ): void {
        $group = self::groupFor($optionKey);
        GenStore::$cache[GenStore::k($optionKey, $group)] = 'not-a-generation';
        GenStore::$options[$optionKey] = 7;

        $got = self::callPrivate($class, $reader);

        self::assertSame(7, $got);
        self::assertSame(
            7,
            GenStore::$cache[GenStore::k($optionKey, $group)],
            'the poisoned value was left in the cache'
        );
        $writes = GenStore::writesFor($optionKey);
        self::assertNotSame([], $writes);
        self::assertSame('set', $writes[0]['op'], 'poison must be healed with set(), not add()');
        self::assertTrue($writes[0]['wrote']);
    }

    /**
     * Anti-vacuity: an ordinary miss with no race must still populate the
     * cache. Without this, "add() lost the race" would also be satisfied by
     * a reader that never writes anything.
     *
     * @param class-string $class
     */
    #[DataProvider('readers')]
    public function testAnUncontendedMissStillPopulatesTheCache(
        string $class,
        string $reader,
        string $optionKey
    ): void {
        GenStore::$options[$optionKey] = 4;

        self::assertSame(4, self::callPrivate($class, $reader));
        self::assertSame(
            4,
            GenStore::$cache[GenStore::k($optionKey, self::groupFor($optionKey))],
            'an uncontended miss did not populate the cache'
        );
        $writes = GenStore::writesFor($optionKey);
        self::assertSame('add', $writes[0]['op']);
        self::assertTrue($writes[0]['wrote'], 'add() should have succeeded on an empty key');
    }

    /** A valid cached value is returned without touching the option at all. */
    #[DataProvider('readers')]
    public function testAValidCachedValueShortCircuits(
        string $class,
        string $reader,
        string $optionKey
    ): void {
        GenStore::$cache[GenStore::k($optionKey, self::groupFor($optionKey))] = 11;
        GenStore::$options[$optionKey] = 999;

        self::assertSame(11, self::callPrivate($class, $reader));
        self::assertSame([], GenStore::writesFor($optionKey), 'a cache hit wrote to the cache');
    }

    // ── the one-second hole ────────────────────────────────────────────

    /**
     * ⭐ Two bumps inside one second must produce two different generations.
     * With a bare `time()` they produce the same one, and the entry written
     * before the first bump stays reachable.
     *
     * @param class-string $class
     */
    #[DataProvider('readers')]
    public function testTwoBumpsInTheSameSecondAdvanceTheGeneration(
        string $class,
        string $reader,
        string $optionKey,
        string $bust,
        string $flush
    ): void {
        $call = static function (string $name) use ($class): void {
            $rm = new \ReflectionMethod($class, $name);
            $rm->invoke(null);
        };

        $call($bust);
        $call($flush);
        $first = GenStore::$options[$optionKey] ?? null;

        $call($bust);
        $call($flush);
        $second = GenStore::$options[$optionKey] ?? null;

        self::assertIsInt($first);
        self::assertIsInt($second);
        // Anti-vacuity: a flush that wrote the floor of 1, or nothing
        // meaningful, would satisfy "second > first" just as well.
        self::assertGreaterThan(1_700_000_000, $first, 'the flush did not write a real timestamp');
        self::assertGreaterThan($first, $second, 'the generation did not advance within one second');
    }

    /**
     * The content cache version carries the same hole, and it matters
     * because a privacy flip bumps BOTH counters
     * ({@see SearchController::bust_search_privacy}). Fixing only the LKG
     * generation would leave the hot, version-scoped cache holed.
     */
    public function testTheContentVersionAlsoAdvancesWithinOneSecond(): void
    {
        $bust  = new \ReflectionMethod(SearchController::class, 'bust_search_cache');
        $flush = new \ReflectionMethod(SearchController::class, 'flushBustIfQueued');

        $bust->invoke(null);
        $flush->invoke(null);
        $first = GenStore::$options['bcc_search_cache_version'] ?? null;

        $bust->invoke(null);
        $flush->invoke(null);
        $second = GenStore::$options['bcc_search_cache_version'] ?? null;

        self::assertIsInt($first);
        self::assertIsInt($second);
        self::assertGreaterThan($first, $second, 'the content version did not advance within one second');
    }

    /** The flush must not go through the READER, which writes as a side effect. */
    #[DataProvider('readers')]
    public function testTheFlushDoesNotPopulateTheCacheWithTheOldValue(
        string $class,
        string $reader,
        string $optionKey,
        string $bust,
        string $flush
    ): void {
        GenStore::$options[$optionKey] = 3;

        (new \ReflectionMethod($class, $bust))->invoke(null);
        (new \ReflectionMethod($class, $flush))->invoke(null);

        foreach (GenStore::writesFor($optionKey) as $write) {
            self::assertNotSame(
                3,
                $write['value'],
                'the flush cached the OLD generation on its way to writing the new one'
            );
        }
    }

    /** The cache group each counter lives in. */
    private static function groupFor(string $optionKey): string
    {
        return match ($optionKey) {
            'bcc_user_search_generation'  => 'bcc_search_users',
            'bcc_group_search_generation' => 'bcc_search_groups',
            default                       => 'bcc_search',
        };
    }
}
