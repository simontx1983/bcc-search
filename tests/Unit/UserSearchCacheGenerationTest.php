<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Tests\Stubs\UserSearchGenStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-430 — the member-search cache gains a generation counter.
 *
 * Before this, `/search/users` cached per viewer (plus a shared anonymous
 * bucket) for 45 seconds with NO invalidation of any kind. A member who hid
 * themselves from search, renamed themselves, was banned, or blocked someone
 * stayed findable exactly as before for the rest of the window.
 *
 * ⭐ These are BEHAVIOURAL tests, not assertions about the source. They drive
 * the real `handle()` against recording cache/option/hook shims and compare
 * the keys it actually reads and writes, because the thing that matters is
 * whether a prior entry becomes unreachable — not whether a particular
 * expression appears in the file.
 *
 * Process isolation is required, not cosmetic: the stub file defines
 * `add_action` and the cache shims in the global namespace, and those would
 * otherwise leak into every other test in the run.
 */
#[CoversClass(\BCC\Search\Controllers\UserSearchController::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserSearchCacheGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/user-search-generation-stub.php';
        UserSearchGenStore::reset();
    }

    /** @return class-string */
    private static function controller(): string
    {
        return \BCC\Search\Controllers\UserSearchController::class;
    }

    private function search(string $q = 'alice', int $limit = 20): void
    {
        $c = self::controller();
        (new $c())->handle(new \WP_REST_Request(['q' => $q, 'limit' => $limit]));
    }

    /** The key this request wrote, i.e. the one a later identical request would read. */
    private function lastResultKey(): string
    {
        $keys = array_values(array_filter(
            UserSearchGenStore::$setKeys,
            static fn (string $k): bool => str_starts_with($k, 'user_search_')
        ));
        self::assertNotSame([], $keys, 'the request wrote no result cache entry');
        return (string) end($keys);
    }

    // ── the counter actually invalidates ───────────────────────────────

    public function testTheCacheKeyCarriesTheGeneration(): void
    {
        UserSearchGenStore::$options['bcc_user_search_generation'] = 4242;

        $this->search();

        self::assertStringStartsWith('user_search_4242_', $this->lastResultKey());
    }

    /**
     * ⭐ The test this whole change exists for: after a bump, the entry
     * written before it is no longer the entry that gets read.
     */
    public function testABumpMakesThePreviousEntryUnreachable(): void
    {
        $c = self::controller();

        $this->search();
        $before = $this->lastResultKey();

        $c::bustGeneration();
        $c::flushGenBumpIfQueued();

        UserSearchGenStore::$setKeys = [];
        UserSearchGenStore::$getKeys = [];
        $this->search();
        $after = $this->lastResultKey();

        self::assertNotSame($before, $after, 'the key did not change, so the stale entry is still served');
        self::assertNotContains($before, UserSearchGenStore::$getKeys, 'the stale key was read again');
    }

    /**
     * Anti-vacuity. Without a bump the key MUST stay the same — otherwise
     * the test above would pass for a key that simply changes every time,
     * which would be a cache that never hits rather than one that
     * invalidates correctly.
     */
    public function testWithoutABumpTheSecondRequestHitsTheFirstEntry(): void
    {
        $this->search();
        $first = $this->lastResultKey();

        UserSearchGenStore::$setKeys = [];
        UserSearchGenStore::$getKeys = [];
        $this->search();

        // A hit returns before the write, so the proof of stability is the
        // READ, not another write — and the absence of a write is itself
        // the evidence that the entry was reused.
        self::assertContains($first, UserSearchGenStore::$getKeys, 'the second request did not look up the first key');
        self::assertSame([], array_values(array_filter(
            UserSearchGenStore::$setKeys,
            static fn (string $k): bool => str_starts_with($k, 'user_search_')
        )), 'the second request rebuilt instead of hitting the cache');
    }

    /** The shared anonymous bucket is generation-scoped too — it is the one most callers read. */
    public function testTheAnonymousBucketIsGenerationScopedAsWell(): void
    {
        $c = self::controller();
        UserSearchGenStore::$viewerId = 0;

        $this->search();
        $anonBefore = $this->lastResultKey();

        $c::bustGeneration();
        $c::flushGenBumpIfQueued();

        UserSearchGenStore::$setKeys = [];
        $this->search();

        self::assertNotSame($anonBefore, $this->lastResultKey());
    }

    /** A viewer-scoped key and the anonymous key must still differ within one generation. */
    public function testViewerScopingSurvivesTheChange(): void
    {
        UserSearchGenStore::$viewerId = 0;
        $this->search();
        $anon = $this->lastResultKey();

        UserSearchGenStore::$setKeys = [];
        UserSearchGenStore::$viewerId = 77;
        $this->search();

        self::assertNotSame($anon, $this->lastResultKey(), 'a signed-in viewer shares the anonymous bucket');
    }

    // ── the generation value itself ────────────────────────────────────

    /**
     * ⭐ Strictly increasing, which `time()` alone is not. Two bumps inside
     * one second must still produce two different generations, or a privacy
     * flip landing in the same second as the read it should invalidate
     * leaves the stale entry reachable for the rest of that second.
     */
    public function testTwoBumpsInTheSameSecondStillChangeTheGeneration(): void
    {
        $c = self::controller();

        $c::bustGeneration();
        $c::flushGenBumpIfQueued();
        $first = UserSearchGenStore::$options['bcc_user_search_generation'];

        $c::bustGeneration();
        $c::flushGenBumpIfQueued();
        $second = UserSearchGenStore::$options['bcc_user_search_generation'];

        self::assertIsInt($first);
        self::assertIsInt($second);
        self::assertGreaterThan($first, $second, 'the generation did not advance within one second');
    }

    public function testAGarbageStoredGenerationFallsBackToASafeFloor(): void
    {
        UserSearchGenStore::$options['bcc_user_search_generation'] = 'not-a-number';

        $this->search();

        self::assertStringStartsWith('user_search_1_', $this->lastResultKey());
    }

    /** One write per request however many triggers fire. */
    public function testManyTriggersInOneRequestCoalesceToOneWrite(): void
    {
        $c = self::controller();

        $c::bustGeneration();
        $c::bustGeneration();
        $c::bustGeneration();

        $shutdowns = UserSearchGenStore::callbacksFor('shutdown');
        self::assertCount(1, $shutdowns, 'each bump queued its own shutdown write');

        $c::flushGenBumpIfQueued();
        $c::flushGenBumpIfQueued();

        self::assertArrayHasKey('bcc_user_search_generation', UserSearchGenStore::$options);
    }

    // ── the triggers ───────────────────────────────────────────────────

    /**
     * The hooks are the contract, so they are asserted as an exact set. A
     * hook silently dropped is a filter that stops invalidating, and a hook
     * silently added is a bump nobody intended.
     */
    public function testItRegistersExactlyTheIntendedHooks(): void
    {
        $c = self::controller();
        $c::register_cache_hooks();

        self::assertSame(
            [
                'add_option_allow_hide_user_from_user_listing',
                'added_user_meta',
                'bcc_profile_visibility_changed',
                'delete_option_allow_hide_user_from_user_listing',
                'deleted_user',
                'deleted_user_meta',
                'peepso_action_user_role_change',
                'peepso_user_blocked',
                'peepso_user_unblocked',
                'profile_update',
                'update_option_allow_hide_user_from_user_listing',
                'updated_user_meta',
                'user_register',
            ],
            UserSearchGenStore::hooks()
        );
    }

    #[DataProvider('bumpingMetaKeys')]
    public function testAWatchedUserMetaKeyBumpsTheGeneration(string $metaKey): void
    {
        $c = self::controller();
        $c::register_cache_hooks();
        UserSearchGenStore::$actions = array_values(array_filter(
            UserSearchGenStore::$actions,
            static fn (array $a): bool => $a['hook'] !== 'shutdown'
        ));

        UserSearchGenStore::fire('updated_user_meta', 1, 55, $metaKey);

        self::assertCount(
            1,
            UserSearchGenStore::callbacksFor('shutdown'),
            $metaKey . ' did not queue a generation bump'
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function bumpingMetaKeys(): iterable
    {
        yield 'hidden from member search (F-257, also the discovery opt-out)' => ['peepso_is_hide_profile_from_user_listing'];
        yield 'real name hidden (F-28)'                                       => ['bcc_privacy_real_name_hidden'];
        yield 'handle rename — half the matcher'                              => ['bcc_handle'];
    }

    /**
     * ⭐ The control for the three above. An unrelated meta write must NOT
     * bump: user meta is written constantly, and a counter that advanced on
     * every write would throw the cache away on every request — a cache
     * that never hits looks exactly like a cache that invalidates well.
     */
    #[DataProvider('nonBumpingMetaKeys')]
    public function testAnUnwatchedUserMetaKeyDoesNotBump(string $metaKey): void
    {
        $c = self::controller();
        $c::register_cache_hooks();

        UserSearchGenStore::fire('updated_user_meta', 1, 55, $metaKey);

        self::assertSame([], UserSearchGenStore::callbacksFor('shutdown'), $metaKey . ' bumped the generation');
    }

    /** @return iterable<string, array{0: string}> */
    public static function nonBumpingMetaKeys(): iterable
    {
        yield 'session tokens'    => ['session_tokens'];
        yield 'last activity'     => ['peepso_last_activity'];
        yield 'an avatar path'    => ['peepso_name_based_avatar_path'];
        // Deliberate: the fold migration (F-257) left these rows in place but
        // nothing reads them, so bumping on one would imply a dependency the
        // code no longer has.
        yield 'the legacy discovery opt-out, now unread' => ['bcc_privacy_discovery_optout'];
    }

    #[DataProvider('bumpingActions')]
    public function testEachNonMetaTriggerBumpsTheGeneration(string $hook): void
    {
        $c = self::controller();
        $c::register_cache_hooks();

        UserSearchGenStore::fire($hook, 55);

        self::assertCount(1, UserSearchGenStore::callbacksFor('shutdown'), $hook . ' did not queue a bump');
    }

    /** @return iterable<string, array{0: string}> */
    public static function bumpingActions(): iterable
    {
        yield 'a rename'                     => ['profile_update'];
        yield 'a new member'                 => ['user_register'];
        yield 'a deleted member'             => ['deleted_user'];
        yield 'a block'                      => ['peepso_user_blocked'];
        yield 'an unblock'                   => ['peepso_user_unblocked'];
        yield 'a ban, via the role change'   => ['peepso_action_user_role_change'];
        yield 'the site-wide hide switch'    => ['update_option_allow_hide_user_from_user_listing'];
        // The profile privacy level has no hook of PeepSo's own; this is
        // bcc-trust's, fired by /me/profile-prefs on a real change.
        yield 'a profile-privacy change'     => ['bcc_profile_visibility_changed'];
    }
}
