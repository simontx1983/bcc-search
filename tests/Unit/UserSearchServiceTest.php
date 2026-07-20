<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\DTO\UserDTO;
use BCC\Search\Services\UserSearchService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the user-search PII boundary.
 *
 * ## Why this exists
 *
 * /search/users is a genuinely anonymous endpoint
 * (permission_callback => '__return_true'). UserSearchService::search()
 * is the single seam that projects each UserDTO into the public response.
 * The 2026-07-06 audit fix (commit a99857c) stopped emitting `user_login`
 * as `username`; the 2026-07-19 conformance fix (contract v1.46) went
 * further — BCC signup derives login (`u_<handle>`) AND nicename from the
 * credential name, so the projection now emits the §B6 canonical
 * `bcc_handle` (nicename fallback for handle-less legacy accounts) and a
 * relative `/u/{handle}` route as profile_url. These tests pin both
 * guarantees so a future refactor can't silently re-expose the login or
 * send users off-app.
 *
 * ## Isolation strategy
 *
 * Each test runs in its own subprocess. setUp() pulls in
 * tests/Stubs/peepso-media-cache-stub.php (fake bcc-core
 * PeepSoMediaCache — the avatar seam the service resolves through) and
 * tests/Stubs/user-search-repo-stub.php which defines a fake
 * UserSearchRepository at the production FQN, then requires the real
 * UserSearchService — so the service reads fixture rows whose userLogin
 * differs from userNicename without touching the DB. The stub files are
 * never loaded in the main process, so other tests see the real classes.
 */
#[CoversClass(UserSearchService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserSearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/peepso-media-cache-stub.php';
        require_once __DIR__ . '/../Stubs/user-search-repo-stub.php';
        \BCC\Search\Repositories\UserSearchRepository::reset();
    }

    public function testUsernameIsCanonicalHandleAndLoginNeverAppears(): void
    {
        \BCC\Search\Repositories\UserSearchRepository::$return = [
            new UserDTO(7, 'u_alice', 'u_alice', 'Alice A', 'alice'),
            new UserDTO(9, 'u_bob', 'u_bob', 'Bob B', 'bob'),
        ];

        $out = (new UserSearchService())->search('a', 20);

        self::assertCount(2, $out['results']);
        self::assertSame('alice', $out['results'][0]['username']);
        self::assertSame('bob', $out['results'][1]['username']);

        // Hard regression guard: the login-derived `u_` names survive
        // NOWHERE in the payload — BCC signup derives login AND nicename
        // from the credential name, so projecting either re-exposes it
        // (that's the bug contract v1.46 fixed).
        $flat = json_encode($out);
        self::assertIsString($flat);
        self::assertStringNotContainsString('u_alice', $flat);
        self::assertStringNotContainsString('u_bob', $flat);
    }

    public function testHandleLessAccountFallsBackToNicenameNeverLogin(): void
    {
        \BCC\Search\Repositories\UserSearchRepository::$return = [
            new UserDTO(7, 'secret_admin_login', 'legacy-nicename', 'Legacy L'),
        ];

        $row = (new UserSearchService())->search('l', 20)['results'][0];

        self::assertSame('legacy-nicename', $row['username']);
        self::assertSame('/u/legacy-nicename', $row['profile_url']);
        self::assertStringNotContainsString('secret_admin_login', (string) json_encode($row));
    }

    public function testResultKeysAreExactlyThePublicShape(): void
    {
        \BCC\Search\Repositories\UserSearchRepository::$return = [
            new UserDTO(7, 'login7', 'nice7', 'Seven'),
        ];

        $row = (new UserSearchService())->search('n', 20)['results'][0];

        self::assertSame(
            ['id', 'username', 'display_name', 'avatar_url', 'profile_url'],
            array_keys($row),
        );
        self::assertArrayNotHasKey('user_login', $row);
        self::assertArrayNotHasKey('userLogin', $row);
    }

    public function testMetaReflectsTrimmedQueryAndCount(): void
    {
        \BCC\Search\Repositories\UserSearchRepository::$return = [
            new UserDTO(1, 'l', 'n', 'D'),
        ];

        $out = (new UserSearchService())->search('  hello  ', 20);

        self::assertSame(1, $out['meta']['count']);
        self::assertSame('hello', $out['meta']['query']);
    }

    public function testProfileUrlIsRelativeMemberRouteFromHandle(): void
    {
        \BCC\Search\Repositories\UserSearchRepository::$return = [
            new UserDTO(42, 'u_charlie', 'u_charlie', 'Charlie', 'charlie'),
        ];

        $row = (new UserSearchService())->search('c', 20)['results'][0];

        // Contract §4: profile_url is the RELATIVE Next.js member route
        // (/u/{handle}), never a WP-origin permalink — an absolute URL
        // here navigates users off the headless frontend. avatar_url
        // still resolves through the PeepSoMediaCache stub.
        self::assertSame('/u/charlie', $row['profile_url']);
        self::assertSame('https://avatars.test/42', $row['avatar_url']);
    }
}
