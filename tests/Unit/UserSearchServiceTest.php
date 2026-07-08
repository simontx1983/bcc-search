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
 * The 2026-07-06 audit fix (commit a99857c) changed that projection to
 * emit `user_nicename` as `username` instead of `user_login` — the login
 * is the actual credential name, and handing it to anonymous callers who
 * iterate the endpoint gifts them a credential-stuffing list. These tests
 * pin that guarantee so a future refactor can't silently re-expose it.
 *
 * ## Isolation strategy
 *
 * Each test runs in its own subprocess. setUp() pulls in
 * tests/Stubs/user-search-repo-stub.php which defines a fake
 * UserSearchRepository at the production FQN, then requires the real
 * UserSearchService — so the service reads fixture rows whose userLogin
 * differs from userNicename without touching the DB. The stub file is
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
        require_once __DIR__ . '/../Stubs/user-search-repo-stub.php';
        \BCC\Search\Repositories\UserSearchRepository::reset();
    }

    public function testUsernameIsNicenameAndLoginNeverAppears(): void
    {
        \BCC\Search\Repositories\UserSearchRepository::$return = [
            new UserDTO(7, 'secret_admin_login', 'alice', 'Alice A'),
            new UserDTO(9, 'bob.credential', 'bob', 'Bob B'),
        ];

        $out = (new UserSearchService())->search('a', 20);

        self::assertCount(2, $out['results']);
        self::assertSame('alice', $out['results'][0]['username']);
        self::assertSame('bob', $out['results'][1]['username']);

        // Hard regression guard: no login string survives anywhere in the payload.
        $flat = json_encode($out);
        self::assertIsString($flat);
        self::assertStringNotContainsString('secret_admin_login', $flat);
        self::assertStringNotContainsString('bob.credential', $flat);
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

    public function testProfileUrlFallsBackToAuthorArchiveKeyedOnNicename(): void
    {
        \BCC\Search\Repositories\UserSearchRepository::$return = [
            new UserDTO(42, 'login42', 'charlie', 'Charlie'),
        ];

        $row = (new UserSearchService())->search('c', 20)['results'][0];

        // No PeepSo loaded → WP author-archive fallback, keyed on nicename
        // (not login), per resolveProfileUrl(). Values come from the
        // deterministic wp-stubs shims.
        self::assertSame('https://site.test/author/charlie', $row['profile_url']);
        self::assertSame('https://avatars.test/42', $row['avatar_url']);
    }
}
