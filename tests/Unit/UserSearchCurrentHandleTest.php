<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Repositories\UserSearchRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-302: global search could not find a member by their CURRENT handle.
 *
 * PeepSo's own search matches first/last name and user_login, never the BCC
 * @handle. The login is u_<the handle a member signed up with> and never
 * changes, so after a rename the member was unfindable by the new handle.
 *
 * The repository now unions in bcc-trust's shared matcher
 * (MemberSearchCandidates: display name or @handle, never the login), routed
 * back through PeepSoUserSearch as `include` with an EMPTY term, so the
 * privacy filter set still decides who is visible. Each method runs in its
 * own process; the matcher double is declared only where a test needs it, so
 * the "bcc-trust inactive" case sees class_exists() === false.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserSearchCurrentHandleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/peepso-user-search-stub.php';
        \PeepSoUserSearch::reset();
        $GLOBALS['__bcc_userdata'] = [];
        $GLOBALS['__bcc_usermeta'] = [];
        // Member 7 signed up as alice (login u_alice) and renamed to bob.
        $u = new \WP_User();
        $u->ID = 7;
        $u->user_login = 'u_alice';
        $u->user_nicename = 'u_alice';
        $u->display_name = 'Robert';
        $GLOBALS['__bcc_userdata'][7] = $u;
        $GLOBALS['__bcc_usermeta'][7] = ['bcc_handle' => 'bob'];
    }

    /** @param list<int> $ids */
    private static function matcherReturns(array $ids): void
    {
        $GLOBALS['__bcc_member_candidates'] = $ids;
        if (!class_exists('\\BCC\\Trust\\Core\\Support\\MemberSearchCandidates', false)) {
            eval(<<<'PHP'
                namespace BCC\Trust\Core\Support;
                final class MemberSearchCandidates
                {
                    /** @return list<int> */
                    public static function ids(string $needle, int $max = 1000): array
                    {
                        $GLOBALS['__bcc_member_candidates_needle'] = $needle;
                        return $GLOBALS['__bcc_member_candidates'];
                    }
                }
                PHP);
        }
    }

    public function testARenamedMemberIsFoundByTheirCurrentHandle(): void
    {
        // PeepSo's term search sees only the login (u_alice): no hit for bob.
        \PeepSoUserSearch::$nextResults = [];
        self::matcherReturns([7]);

        $dtos = UserSearchRepository::search('bob', 20, 0);

        self::assertCount(1, $dtos);
        self::assertSame(7, $dtos[0]->id);
        self::assertSame('bob', $dtos[0]->handle);
        self::assertSame('bob', $GLOBALS['__bcc_member_candidates_needle']);
    }

    public function testTheCandidatesGoThroughThePrivacyFilterWithAnEmptyTerm(): void
    {
        \PeepSoUserSearch::$nextResults = [];
        self::matcherReturns([7]);

        UserSearchRepository::search('bob', 20, 555);

        self::assertCount(2, \PeepSoUserSearch::$calls);
        $scoped = \PeepSoUserSearch::$calls[1];
        self::assertSame('', $scoped['needle'], 'the raw query must not reach PeepSo\'s own search again');
        self::assertSame([7], $scoped['args']['include']);
        self::assertSame(555, $scoped['viewerId'], 'the viewer\'s block filter applies');
    }

    public function testACandidateThePrivacyFilterHidesIsNotReturned(): void
    {
        \PeepSoUserSearch::$nextResults = [];
        \PeepSoUserSearch::$visible     = [];   // e.g. hidden from listings
        self::matcherReturns([7]);

        self::assertSame([], UserSearchRepository::search('bob', 20, 0));
    }

    public function testAMemberBothPassesFindIsReturnedOnce(): void
    {
        \PeepSoUserSearch::$nextResults = [7];  // e.g. first name "Robert"
        self::matcherReturns([7]);

        self::assertCount(1, UserSearchRepository::search('rob', 20, 0));
    }

    public function testWithoutBccTrustOnlyThePeepSoTermSearchRuns(): void
    {
        \PeepSoUserSearch::$nextResults = [];

        self::assertFalse(class_exists('\\BCC\\Trust\\Core\\Support\\MemberSearchCandidates', false));
        self::assertSame([], UserSearchRepository::search('bob', 20, 0));
        self::assertCount(1, \PeepSoUserSearch::$calls);
    }
}
