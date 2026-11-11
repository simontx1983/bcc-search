<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Repositories\UserSearchRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A5 privacy regression — the anonymous GET /search/users repository.
 *
 * The endpoint is unauthenticated. Before this fix the repository ran a raw
 * `wp_users` LIKE filtered only by `user_status = 0`, which bypassed the
 * PeepSo/BCC privacy filter set (hidden / discovery-opted-out, banned /
 * suspended, and blocked-viewer users were all enumerable). These tests pin
 * that the repository now:
 *   1. fails CLOSED (empty) when PeepSo is not loaded — never a privacy-blind
 *      wp_users scan; and
 *   2. routes through PeepSoUserSearch, forwarding the viewer id so the
 *      viewer-scoped block filter applies (null for anonymous).
 *
 * Each method runs in its own subprocess. The "fail closed" method does NOT
 * load the PeepSoUserSearch stub, so class_exists() is genuinely false there.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserSearchRepositoryPrivacyTest extends TestCase
{
    public function testFailsClosedWhenPeepSoUnavailable(): void
    {
        // No PeepSoUserSearch stub loaded in this process.
        self::assertFalse(class_exists('PeepSoUserSearch'));
        self::assertSame([], UserSearchRepository::search('alice', 20, 0));
    }

    public function testRoutesThroughPeepSoUserSearchAndForwardsViewerId(): void
    {
        require_once __DIR__ . '/../Stubs/peepso-user-search-stub.php';
        \PeepSoUserSearch::reset();
        \PeepSoUserSearch::$nextResults = [7, 9];

        $u7 = new \WP_User();
        $u7->ID = 7;
        $u7->user_login = 'secret_login7';
        $u7->user_nicename = 'alice';
        $u7->display_name = 'Alice';

        $u9 = new \WP_User();
        $u9->ID = 9;
        $u9->user_login = 'secret_login9';
        $u9->user_nicename = 'bob';
        $u9->display_name = 'Barb';

        $GLOBALS['__bcc_userdata'] = [7 => $u7, 9 => $u9];
        // F-54: only accounts with a public handle are returned.
        $GLOBALS['__bcc_usermeta'] = [7 => ['bcc_handle' => 'alice'], 9 => ['bcc_handle' => 'barb']];

        $dtos = UserSearchRepository::search('a', 20, 555);

        // Viewer id forwarded so PeepSoUserSearch applies the block filter.
        self::assertNotNull(\PeepSoUserSearch::$lastCtor);
        self::assertSame(555, \PeepSoUserSearch::$lastCtor['viewerId']);
        self::assertSame('a', \PeepSoUserSearch::$lastCtor['needle']);

        // Results projected in the privacy-filtered search order.
        self::assertCount(2, $dtos);
        self::assertSame(7, $dtos[0]->id);
        self::assertSame('alice', $dtos[0]->userNicename);
        self::assertSame('secret_login7', $dtos[0]->userLogin);
        self::assertSame(9, $dtos[1]->id);
        self::assertSame('bob', $dtos[1]->userNicename);
    }

    /**
     * @param array<string, string> $meta
     */
    private static function user(int $id, string $login, string $display, array $meta): void
    {
        $u = new \WP_User();
        $u->ID = $id;
        $u->user_login = $login;
        $u->user_nicename = strtolower($login);
        $u->display_name = $display;
        $GLOBALS['__bcc_userdata'][$id] = $u;
        $GLOBALS['__bcc_usermeta'][$id] = $meta;
    }

    public function testLoginOnlyMatchIsDroppedSoTheEndpointIsNoLoginOracle(): void
    {
        // F-54: PeepSo matched 'adm' on user_login; the visible fields of
        // this user ("Carol" / carol) do not contain it, so the row must drop.
        require_once __DIR__ . '/../Stubs/peepso-user-search-stub.php';
        \PeepSoUserSearch::reset();
        \PeepSoUserSearch::$nextResults = [21];
        $GLOBALS['__bcc_userdata'] = [];
        self::user(21, 'admin_real', 'Carol', ['bcc_handle' => 'carol']);

        self::assertSame([], UserSearchRepository::search('adm', 20, 0));
    }

    public function testHandlelessAccountIsNotReturned(): void
    {
        // F-54: without a handle the only public identifier would be the
        // login-derived nicename.
        require_once __DIR__ . '/../Stubs/peepso-user-search-stub.php';
        \PeepSoUserSearch::reset();
        \PeepSoUserSearch::$nextResults = [22];
        $GLOBALS['__bcc_userdata'] = [];
        self::user(22, 'legacy_dana', 'Dana', []);

        self::assertSame([], UserSearchRepository::search('dana', 20, 0));
    }

    public function testLoginShapedDisplayNameIsReplacedByTheHandle(): void
    {
        // F-54: a wp-admin-created account defaults display_name to the login.
        require_once __DIR__ . '/../Stubs/peepso-user-search-stub.php';
        \PeepSoUserSearch::reset();
        \PeepSoUserSearch::$nextResults = [23];
        $GLOBALS['__bcc_userdata'] = [];
        self::user(23, 'eve_login', 'eve_login', ['bcc_handle' => 'eve']);

        $dtos = UserSearchRepository::search('eve', 20, 0);

        self::assertCount(1, $dtos);
        self::assertSame('eve', $dtos[0]->displayName);
    }

    public function testAnonymousViewerPassesNullToWrapper(): void
    {
        require_once __DIR__ . '/../Stubs/peepso-user-search-stub.php';
        \PeepSoUserSearch::reset();
        \PeepSoUserSearch::$nextResults = [];

        UserSearchRepository::search('a', 20, 0);

        self::assertNotNull(\PeepSoUserSearch::$lastCtor);
        self::assertNull(\PeepSoUserSearch::$lastCtor['viewerId']);
    }
}
