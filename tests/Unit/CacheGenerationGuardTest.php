<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Controllers\GroupSearchController;
use BCC\Search\Controllers\SearchController;
use BCC\Search\Controllers\UserSearchController;
use BCC\Search\Support\CacheGeneration;
use BCC\Search\Tests\Stubs\GenStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-445 — a heal or poison publish must never overwrite a newer bump.
 *
 * The fallback reads the option and then writes the cache. A bump in another
 * request can land between those two steps, writing both a newer option and
 * a newer cache entry — and the plain `wp_cache_set` that used to finish the
 * fallback put the older value back for the whole TTL. In SearchController
 * the 2-second heal lock does not help: it orders healers against each
 * other, not a heal against a bump.
 *
 * The race is injected through the stub's `onOptionRead` hook, which fires
 * in exactly that window.
 *
 * ⛔ THE INVARIANT THAT MOST NEEDS PINNING IS THE OPPOSITE ONE: a BUMP must
 * publish UNCONDITIONALLY. A bump is the authority on the newest value, so
 * routing it through the guard would let a stale-high cached value make it
 * lose — the same failure inverted. "Use the helper everywhere" is the
 * obvious tidy-up that would reintroduce it, so
 * {@see testABumpPublishesEvenWhenTheCacheHoldsAHigherValue} slips a
 * concurrent bump into a flush through `onOptionWrite` and proves the flush
 * still wins.
 */
#[CoversClass(CacheGeneration::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CacheGenerationGuardTest extends TestCase
{
    private const GROUP = 'bcc_test_group';
    private const KEY   = 'bcc_test_counter';
    private const TTL   = 60;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/generation-race-stubs.php';
        GenStore::reset();

        // SearchController's heal and poison paths each error_log() once per
        // 60s per node, and PHPUnit turns that output into a test error. Both
        // loggers rate-limit themselves with `wp_cache_add(... '_logged')` and
        // return early when the key is already present, so pre-seeding those
        // keys silences them THROUGH the production mechanism rather than by
        // muting output. (The existing search-controller-env-stub pre-seeds a
        // transient for the same reason.)
        //
        // ⚠ Deliberately not suppressed by widening an error handler: that
        // would also hide a log line appearing where it should not.
        foreach (['_fallback_logged', '_option_poison_logged'] as $suffix) {
            GenStore::$cache[GenStore::k('bcc_search_cache_version' . $suffix, 'bcc_search')] = 1;
        }
    }

    private static function publish(int $value): int
    {
        return CacheGeneration::publishWithoutRegressing(self::KEY, self::GROUP, self::TTL, $value);
    }

    private static function cached(): mixed
    {
        return GenStore::$cache[GenStore::k(self::KEY, self::GROUP)] ?? null;
    }

    // ── the guard itself ───────────────────────────────────────────────

    public function testItPublishesWhenTheCacheIsEmpty(): void
    {
        self::assertSame(7, self::publish(7));
        self::assertSame(7, self::cached());
    }

    public function testItPublishesOverAnOlderValue(): void
    {
        GenStore::$cache[GenStore::k(self::KEY, self::GROUP)] = 5;

        self::assertSame(9, self::publish(9));
        self::assertSame(9, self::cached());
    }

    /** ⭐ The race. A newer value is adopted, not overwritten. */
    public function testItAdoptsANewerValueRatherThanOverwritingIt(): void
    {
        GenStore::$cache[GenStore::k(self::KEY, self::GROUP)] = 12;

        self::assertSame(12, self::publish(9), 'it returned its own stale value instead of adopting');
        self::assertSame(12, self::cached(), 'it overwrote a newer value');
        self::assertSame([], GenStore::writesFor(self::KEY), 'it wrote at all');
    }

    /**
     * `>=`, not `>`. An equal value needs no write, and rewriting it would
     * only refresh a TTL the bump has just set.
     */
    public function testAnEqualValueIsNotRewritten(): void
    {
        GenStore::$cache[GenStore::k(self::KEY, self::GROUP)] = 9;

        self::assertSame(9, self::publish(9));
        self::assertSame([], GenStore::writesFor(self::KEY), 'an equal value was rewritten');
    }

    /** A poisoned cache entry is not a newer value — it must be replaced. */
    #[DataProvider('poisonedCacheValues')]
    public function testAPoisonedCacheEntryIsReplaced(mixed $poison): void
    {
        GenStore::$cache[GenStore::k(self::KEY, self::GROUP)] = $poison;

        self::assertSame(4, self::publish(4));
        self::assertSame(4, self::cached());
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function poisonedCacheValues(): iterable
    {
        yield 'a word'        => ['nonsense'];
        yield 'a float'       => [1.5];
        yield 'an array'      => [['9']];
        yield 'null'          => [null];
        yield 'a bool'        => [true];
        yield 'a signed int'  => ['-5'];
    }

    /** A digit-string counts, because wp_options hands back strings. */
    public function testADigitStringCachedValueCounts(): void
    {
        GenStore::$cache[GenStore::k(self::KEY, self::GROUP)] = '20';

        self::assertSame(20, self::publish(9), 'a digit-string newer value was not honoured');
        self::assertSame('20', self::cached());
    }

    // ── the three readers ──────────────────────────────────────────────

    /** @return iterable<string, array{0: class-string, 1: string, 2: string, 3: string}> */
    public static function healAndPoisonReaders(): iterable
    {
        yield 'project search cache version' => [
            SearchController::class, 'getCacheVersion', 'bcc_search_cache_version', 'bcc_search',
        ];
        yield 'project search LKG generation' => [
            SearchController::class, 'getLkgGeneration', 'bcc_search_lkg_generation', 'bcc_search',
        ];
        yield 'users vertical' => [
            UserSearchController::class, 'getGeneration', 'bcc_user_search_generation', 'bcc_search_users',
        ];
        yield 'groups vertical' => [
            GroupSearchController::class, 'getGeneration', 'bcc_group_search_generation', 'bcc_search_groups',
        ];
    }

    /**
     * ⭐ End to end, for every reader that heals or repairs poison: the
     * cached entry is unusable, the option says 5, and a bump lands while we
     * are reading the option. The reader must come back with the bump's
     * value and leave it in place.
     *
     * @param class-string $class
     */
    #[DataProvider('healAndPoisonReaders')]
    public function testAReaderAdoptsABumpThatLandsDuringItsOptionRead(
        string $class,
        string $reader,
        string $key,
        string $group
    ): void {
        // Present but invalid, so every reader takes its repair path.
        GenStore::$cache[GenStore::k($key, $group)] = 'poisoned';
        GenStore::$options[$key] = 5;

        GenStore::$onOptionRead = static function () use ($key, $group): void {
            wp_cache_set($key, 9, $group);
            GenStore::$writes = [];
        };

        $got = (new \ReflectionMethod($class, $reader))->invoke(null);

        self::assertSame(9, $got, 'the reader returned its own stale read');
        self::assertSame(9, GenStore::$cache[GenStore::k($key, $group)], 'the bump was overwritten');
    }

    /**
     * Without a race the repair still happens — otherwise the case above
     * would also pass for a reader that never writes.
     *
     * @param class-string $class
     */
    #[DataProvider('healAndPoisonReaders')]
    public function testAReaderStillRepairsPoisonWhenNothingRaces(
        string $class,
        string $reader,
        string $key,
        string $group
    ): void {
        GenStore::$cache[GenStore::k($key, $group)] = 'poisoned';
        GenStore::$options[$key] = 5;

        self::assertSame(5, (new \ReflectionMethod($class, $reader))->invoke(null));
        self::assertSame(5, GenStore::$cache[GenStore::k($key, $group)], 'poison was left in the cache');
    }

    /**
     * ⭐ The poisoned-OPTION improvement. `readValidatedVersionOption()`
     * answers with the floor of 1 when the option is unusable, and that 1
     * used to be published over whatever the cache held. Now a valid value
     * that arrives meanwhile survives it.
     */
    public function testAPoisonedOptionNoLongerRegressesAValidCachedVersion(): void
    {
        GenStore::$cache[GenStore::k('bcc_search_cache_version', 'bcc_search')] = 'poisoned';
        GenStore::$options['bcc_search_cache_version'] = 'also-poisoned';

        GenStore::$onOptionRead = static function (): void {
            wp_cache_set('bcc_search_cache_version', 777, 'bcc_search');
        };

        $got = (new \ReflectionMethod(SearchController::class, 'getCacheVersion'))->invoke(null);

        self::assertSame(777, $got, 'the floor of 1 was returned over a valid cached version');
        self::assertSame(
            777,
            GenStore::$cache[GenStore::k('bcc_search_cache_version', 'bcc_search')],
            'the floor of 1 was published over a valid cached version'
        );
    }

    // ── and the invariant in the other direction ───────────────────────

    /** @return iterable<string, array{0: class-string, 1: string, 2: string, 3: string, 4: string}> */
    public static function bumpPaths(): iterable
    {
        yield 'users vertical' => [
            UserSearchController::class, 'bustGeneration', 'flushGenBumpIfQueued',
            'bcc_user_search_generation', 'bcc_search_users',
        ];
        yield 'groups vertical' => [
            GroupSearchController::class, 'bustGeneration', 'flushGenBumpIfQueued',
            'bcc_group_search_generation', 'bcc_search_groups',
        ];
        yield 'project search LKG' => [
            SearchController::class, 'bust_search_privacy', 'flushPrivacyBustIfQueued',
            'bcc_search_lkg_generation', 'bcc_search',
        ];
        yield 'project search content version' => [
            SearchController::class, 'bust_search_cache', 'flushBustIfQueued',
            'bcc_search_cache_version', 'bcc_search',
        ];
    }

    /**
     * ⛔ THE INVARIANT THAT PROTECTS THE FIX FROM ITSELF. A bump is the
     * authority on the newest value and must publish unconditionally. If a
     * future tidy-up routed the flushes through the guard, a stale-high
     * cached value would make the bump LOSE — the same bug inverted, and
     * worse, because the bump is the thing a privacy flip depends on.
     *
     * Behavioural, not source-shape: `onOptionWrite` fires inside the flush,
     * after it has settled on its new value and before it writes the cache,
     * and plants a cached value far higher than anything the flush could
     * choose. The flush must still win.
     *
     * @param class-string $class
     */
    #[DataProvider('bumpPaths')]
    public function testABumpPublishesEvenWhenTheCacheHoldsAHigherValue(
        string $class,
        string $bust,
        string $flush,
        string $key,
        string $group
    ): void {
        GenStore::$cache[GenStore::k($key, $group)] = 10;
        GenStore::$options[$key] = 10;

        GenStore::$onOptionWrite = static function () use ($key, $group): void {
            wp_cache_set($key, 9_999_999_999, $group);
        };

        (new \ReflectionMethod($class, $bust))->invoke(null);
        (new \ReflectionMethod($class, $flush))->invoke(null);

        $written = GenStore::$options[$key] ?? null;
        self::assertIsInt($written);
        self::assertGreaterThan(1_700_000_000, $written, 'the flush did not write a real timestamp');

        self::assertSame(
            $written,
            GenStore::$cache[GenStore::k($key, $group)],
            'the bump lost to a stale-high cached value — it must publish unconditionally'
        );
    }
}
