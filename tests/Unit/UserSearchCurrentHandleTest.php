<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Repositories\UserSearchRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-302: global search could not find a member by their CURRENT handle, and
 * (review) its PeepSo term pass let an anonymous caller confirm logins and
 * hidden real names.
 *
 * PeepSo's own search matches user_login and first/last name, never the BCC
 * @handle. The login is u_<the handle a member signed up with> and never
 * changes. So the repository no longer hands the query to PeepSo at all:
 * MATCHING is bcc-trust's MemberSearchCandidates (display name or current
 * @handle, never the login), and the candidates go through PeepSoUserSearch
 * as `include` with an EMPTY term, so the privacy filter set still decides
 * who is visible. Without bcc-trust there is no matcher and nothing is
 * returned.
 *
 * Each method runs in its own process; the matcher double
 * (member-search-candidates-stub.php) is loaded only where bcc-trust is
 * modelled as active.
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

    /** bcc-trust active; its matcher answers $ids. */
    private static function matcherReturns(array $ids): void
    {
        require_once __DIR__ . '/../Stubs/member-search-candidates-stub.php';
        $GLOBALS['__bcc_member_candidates'] = $ids;
    }

    public function testARenamedMemberIsFoundByTheirCurrentHandle(): void
    {
        self::matcherReturns([7]);

        $dtos = UserSearchRepository::search('bob', 20, 0);

        self::assertCount(1, $dtos);
        self::assertSame(7, $dtos[0]->id);
        self::assertSame('bob', $dtos[0]->handle);
        self::assertSame('bob', $GLOBALS['__bcc_member_candidates_needle']);
    }

    public function testTheCandidatesGoThroughThePrivacyFilterWithAnEmptyTerm(): void
    {
        self::matcherReturns([7]);

        UserSearchRepository::search('bob', 20, 555);

        self::assertCount(1, \PeepSoUserSearch::$calls, 'one privacy-filtered pass, no term pass');
        $scoped = \PeepSoUserSearch::$calls[0];
        self::assertSame('', $scoped['needle']);
        self::assertSame([7], $scoped['args']['include']);
        self::assertSame(555, $scoped['viewerId'], 'the viewer\'s block filter applies');
    }

    public function testTheRawQueryNeverReachesPeepSo(): void
    {
        // A login probe. The real matcher never matches a login, so it
        // answers nothing — and PeepSo, which WOULD match u_alice, is never
        // asked with that string.
        self::matcherReturns([]);
        \PeepSoUserSearch::$nextResults = [7];

        self::assertSame([], UserSearchRepository::search('u_alice', 20, 0));
        foreach (\PeepSoUserSearch::$calls as $call) {
            self::assertNotSame('u_alice', $call['needle']);
        }
    }

    public function testAHiddenRealNameReturnsNothing(): void
    {
        // Member 8 hides the real name "Margaret". The matcher matches a
        // hidden real name by handle only, so "marg" finds nobody — and
        // PeepSo's first/last-name search is never asked.
        $u = new \WP_User();
        $u->ID = 8;
        $u->user_login = 'u_mags';
        $u->user_nicename = 'u_mags';
        $u->display_name = 'Margaret';
        $GLOBALS['__bcc_userdata'][8] = $u;
        $GLOBALS['__bcc_usermeta'][8] = ['bcc_handle' => 'mags', 'first_name' => 'Margaret', 'bcc_privacy_real_name_hidden' => '1'];
        self::matcherReturns([]);
        \PeepSoUserSearch::$nextResults = [8];

        self::assertSame([], UserSearchRepository::search('marg', 20, 0));
        self::assertSame([], \PeepSoUserSearch::$calls, 'no candidates, so no PeepSo call at all');
    }

    public function testACandidateThePrivacyFilterHidesIsNotReturned(): void
    {
        \PeepSoUserSearch::$visible = [];   // e.g. hidden from listings
        self::matcherReturns([7]);

        self::assertSame([], UserSearchRepository::search('bob', 20, 0));
    }

    public function testWithoutBccTrustNothingIsReturnedAndPeepSoIsNotAsked(): void
    {
        \PeepSoUserSearch::$nextResults = [7];

        self::assertFalse(class_exists('\\BCC\\Trust\\Core\\Support\\MemberSearchCandidates', false));
        self::assertSame([], UserSearchRepository::search('bob', 20, 0));
        self::assertSame([], \PeepSoUserSearch::$calls);
    }
}
