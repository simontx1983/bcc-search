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
        // F-472: the verified-read trait and the core meta repository,
        // in dependency order. Loaded BY PATH — bcc-core is not autoloaded
        // here — and the trait must precede anything that uses it.
        \BCC\Search\Tests\Support\CoreSibling::requireVerifiedReads();
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

    /**
     * ⭐⭐ DUPLICATE HIDE-FLAG ROWS RESOLVE STRICTEST, not first-row-wins.
     *
     * WordPress allows a meta key to be stored more than once. Reading the
     * FIRST row — what `get_user_meta($id, $key, true)` does, and what this
     * function did — publishes the real name of a member whose rows are
     * ['0', '1'], i.e. someone who HAS asked to be hidden. Any truthy row
     * wins, because hiding is the half that matters.
     *
     * ⚠ Note the asymmetry with `bcc_handle` above, which KEEPS
     * first-row-wins: a handle is an identifier and "strictest" is
     * meaningless for it. Both keys come from one read and resolve by
     * different rules on purpose.
     */
    public function testDuplicateHideFlagRowsResolveStrictestNotFirst(): void
    {
        self::load();
        self::user(7, 'u_seven', 'Opal Real-Name', [
            'bcc_handle'                   => ['op-seven'],
            // '0' FIRST: first-row-wins would read "not hidden".
            'bcc_privacy_real_name_hidden' => ['0', '1'],
        ]);
        $GLOBALS['__bcc_member_candidates'] = [7];

        $dtos = UserSearchRepository::search('op', 5, 0);

        self::assertCount(1, $dtos);
        self::assertSame('@op-seven', $dtos[0]->displayName, 'the real name was published');
    }

    /** And in the other order, so the rule is not accidentally positional. */
    public function testDuplicateHideFlagRowsHideInEitherOrder(): void
    {
        self::load();
        self::user(7, 'u_seven', 'Opal Real-Name', [
            'bcc_handle'                   => ['op-seven'],
            'bcc_privacy_real_name_hidden' => ['1', '0'],
        ]);
        $GLOBALS['__bcc_member_candidates'] = [7];

        $dtos = UserSearchRepository::search('op', 5, 0);

        self::assertSame('@op-seven', $dtos[0]->displayName);
    }

    /**
     * ⚠ The CONTROL for the two above: with no truthy row the name is still
     * SHOWN. Without it they would also pass for an implementation that hid
     * everyone, and "strictest" would be indistinguishable from "always
     * hide".
     */
    public function testDuplicateFalseFlagRowsLeaveTheNameShown(): void
    {
        self::load();
        self::user(7, 'u_seven', 'Opal Shown', [
            'bcc_handle'                   => ['op-seven'],
            'bcc_privacy_real_name_hidden' => ['0', '0'],
        ]);
        $GLOBALS['__bcc_member_candidates'] = [7];

        $dtos = UserSearchRepository::search('op', 5, 0);

        self::assertSame('Opal Shown', $dtos[0]->displayName);
    }

    /**
     * ⭐⭐ THE PRE-FLUSH BAIL ON THE USERS READ. `wpdb::query()` returns
     * false ABOVE `flush()`, so `last_result` still holds the PREVIOUS
     * query's rows and `last_error` is EMPTY. The old
     * `get_results()` + `last_error` shape read that as success, and would
     * have served another query's rows as these members' identities.
     */
    public function testAPreFlushBailOnTheUsersReadThrowsDespiteNoError(): void
    {
        self::load();
        $GLOBALS['__bcc_member_candidates'] = self::rosterWithLateLogins();
        // Someone else's rows, left behind by an earlier query.
        $GLOBALS['wpdb']->last_result = [(object) [
            'ID'            => '999',
            'user_login'    => 'someone_else',
            'user_nicename' => 'someone-else',
            'display_name'  => 'Someone Else',
        ]];
        $GLOBALS['wpdb']->failOn   = 'users';
        $GLOBALS['wpdb']->failMode = 'pre_flush_bail';

        try {
            UserSearchRepository::search('op', 2, 0);
            self::fail('another query\'s rows were served as these members\' identities');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('publicIdentities users', $e->getMessage());
        }

        self::assertSame('', $GLOBALS['wpdb']->last_error, 'the premise: no error signal was set');
    }

    /** The same bail on the META read, which bcc-core's repository owns. */
    public function testAPreFlushBailOnTheMetaReadThrowsDespiteNoError(): void
    {
        self::load();
        $GLOBALS['__bcc_member_candidates'] = self::rosterWithLateLogins();
        $GLOBALS['wpdb']->last_result = [(object) [
            'user_id'    => '999',
            'meta_key'   => 'bcc_handle',
            'meta_value' => 'not-theirs',
        ]];
        $GLOBALS['wpdb']->failOn   = 'usermeta';
        $GLOBALS['wpdb']->failMode = 'pre_flush_bail';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publicIdentities usermeta');
        UserSearchRepository::search('op', 2, 0);
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
