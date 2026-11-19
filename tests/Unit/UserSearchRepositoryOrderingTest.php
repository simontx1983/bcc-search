<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Repositories\UserSearchRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-431 — member search cuts to the limit only AFTER sorting by the shown
 * name.
 *
 * bcc-trust's matcher returns every candidate (up to its own cap). They used
 * to go through PeepSoUserSearch's privacy pass with `number` = limit × 3 and
 * `orderby` username, so PeepSo cut them by user_login BEFORE the repository
 * sorted by the shown name. A member whose login sorted late (a "zz_…" login,
 * or a rotated u_<old> one) fell out, and the top N by name could miss
 * matches.
 *
 * The PeepSo stub now cuts the way the real WP_User_Query does (login order,
 * then `number`), so these tests see the bug the way production did.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserSearchRepositoryOrderingTest extends TestCase
{
    /**
     * @param array<string, string|list<string>> $meta
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

    private static function load(): void
    {
        require_once __DIR__ . '/../Stubs/peepso-user-search-stub.php';
        require_once __DIR__ . '/../Stubs/member-search-candidates-stub.php';
        \PeepSoUserSearch::reset();
        $GLOBALS['__bcc_userdata'] = [];
        $GLOBALS['__bcc_usermeta'] = [];
    }

    /**
     * Eight members whose logins sort FIRST but whose names sort last, and
     * two ("Aaron", "Abby") whose logins sort LAST but whose names sort
     * first. All ten match "op" by handle.
     *
     * @return list<int>
     */
    private static function rosterWithLateLogins(): array
    {
        $ids = [];
        for ($i = 1; $i <= 8; $i++) {
            self::user($i, "a_member{$i}", "Mallory {$i}", ['bcc_handle' => "op-mallory{$i}"]);
            $ids[] = $i;
        }
        self::user(101, 'zz_late_one', 'Aaron', ['bcc_handle' => 'op-aaron']);
        self::user(102, 'zz_late_two', 'Abby', ['bcc_handle' => 'op-abby']);
        $ids[] = 101;
        $ids[] = 102;
        return $ids;
    }

    public function testTopNByShownNameIncludesMembersWhoseLoginsSortLast(): void
    {
        self::load();
        $GLOBALS['__bcc_member_candidates'] = self::rosterWithLateLogins();

        // limit 2: the old privacy pass kept 6 ids by login (all a_member*),
        // so Aaron and Abby never reached the name sort.
        $dtos = UserSearchRepository::search('op', 2, 0);

        self::assertSame(['Aaron', 'Abby'], array_map(static fn ($d) => $d->displayName, $dtos));
        self::assertSame([101, 102], array_map(static fn ($d) => $d->id, $dtos));
    }

    public function testThePrivacyPassIsAskedForEveryCandidateSoItNeverCuts(): void
    {
        self::load();
        $candidates = self::rosterWithLateLogins();
        $GLOBALS['__bcc_member_candidates'] = $candidates;

        UserSearchRepository::search('op', 2, 0);

        self::assertNotNull(\PeepSoUserSearch::$lastCtor);
        $args = \PeepSoUserSearch::$lastCtor['args'];
        self::assertSame($candidates, $args['include']);
        self::assertSame(count($candidates), $args['number']);
    }

    public function testTheCutToTheLimitStillHappensAfterTheSort(): void
    {
        self::load();
        $GLOBALS['__bcc_member_candidates'] = self::rosterWithLateLogins();

        $dtos = UserSearchRepository::search('op', 3, 0);

        self::assertSame(
            ['Aaron', 'Abby', 'Mallory 1'],
            array_map(static fn ($d) => $d->displayName, $dtos)
        );
    }

    public function testShownNamesComeFromTwoNarrowReadsNotAFullUserLoad(): void
    {
        self::load();
        $GLOBALS['__bcc_member_candidates'] = self::rosterWithLateLogins();
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->queries = [];

        UserSearchRepository::search('op', 2, 0);

        self::assertCount(2, $wpdb->queries);
        [$users, $meta] = $wpdb->queries;
        self::assertMatchesRegularExpression(
            '/^SELECT ID, user_login, user_nicename, display_name\s+FROM wp_users\s+WHERE ID IN \([0-9,]+\)$/',
            trim($users)
        );
        self::assertStringContainsString("meta_key IN ('bcc_handle', 'bcc_privacy_real_name_hidden')", $meta);
        self::assertStringContainsString('ORDER BY umeta_id ASC', $meta);
        self::assertStringNotContainsString('*', $users . $meta);
        // Every privacy-passing candidate is read, not a login-ordered slice.
        self::assertStringContainsString('101,102', $users);
    }

    public function testAMetaKeyStoredTwiceReadsAsItsFirstRowLikeGetUserMeta(): void
    {
        self::load();
        self::user(7, 'u_seven', 'Opal', ['bcc_handle' => ['op-first', 'op-second']]);
        $GLOBALS['__bcc_member_candidates'] = [7];

        $dtos = UserSearchRepository::search('op', 5, 0);

        self::assertCount(1, $dtos);
        self::assertSame('op-first', $dtos[0]->handle);
    }

    public function testAFailedUsersReadThrowsInsteadOfAnsweringNobody(): void
    {
        self::load();
        $GLOBALS['__bcc_member_candidates'] = self::rosterWithLateLogins();
        $GLOBALS['wpdb']->failOn = 'users';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publicIdentities users');
        UserSearchRepository::search('op', 2, 0);
    }

    public function testAFailedMetaReadThrowsInsteadOfAnsweringNobody(): void
    {
        self::load();
        $GLOBALS['__bcc_member_candidates'] = self::rosterWithLateLogins();
        $GLOBALS['wpdb']->failOn = 'usermeta';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publicIdentities usermeta');
        UserSearchRepository::search('op', 2, 0);
    }

    public function testPrivacyStillFiltersWhenNothingIsCut(): void
    {
        self::load();
        $GLOBALS['__bcc_member_candidates'] = self::rosterWithLateLogins();
        // Aaron is hidden from this viewer (blocked, private, opted out…).
        \PeepSoUserSearch::$visible = [1, 2, 3, 4, 5, 6, 7, 8, 102];

        $dtos = UserSearchRepository::search('op', 2, 42);

        self::assertSame(['Abby', 'Mallory 1'], array_map(static fn ($d) => $d->displayName, $dtos));
        self::assertNotContains(101, array_map(static fn ($d) => $d->id, $dtos));
    }
}
