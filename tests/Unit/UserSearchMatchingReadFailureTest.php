<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Controllers\UserSearchController;
use BCC\Search\Repositories\UserSearchRepository;
use BCC\Search\Tests\Stubs\UserSearchGenStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-432: a member search whose reads did not run says so, instead of
 * answering "no members".
 *
 * The users vertical (GET /search/users, and the member rows of the nav
 * autocomplete) reads twice before it shows anyone:
 *   1. bcc-trust's MemberSearchCandidates::ids() matches the query. It is
 *      now asked to FAIL CLOSED, through its appended third argument passed
 *      positionally; a read that did not run throws instead of answering [];
 *   2. PeepSoUserSearch applies PeepSo's privacy filter set over those
 *      candidates. It is a WP_User_Query, so two of its defaults hid a
 *      failure (measured on WP 7.0.2): `count_total` runs FOUND_ROWS()
 *      after the failed SELECT and clears last_error, and `cache_results`
 *      caches the failed []. Both are now off, and its last_error is
 *      checked.
 * Either failure reaches UserSearchController as a RuntimeException, which
 * answers a retryable 503 and caches nothing.
 *
 * Doubles: member-search-candidates-stub.php (bcc-trust's signature),
 * peepso-user-search-stub.php (its $wpdb double and the privacy pass) and,
 * for the route, user-search-generation-stub.php. Each method is its own
 * process and loads only what it needs.
 */
#[CoversClass(UserSearchRepository::class)]
#[CoversClass(UserSearchController::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserSearchMatchingReadFailureTest extends TestCase
{
    private const DB_ERROR = 'Lost connection to MySQL server during query';

    /** One searchable member, Alice (#7, handle alice), and the two stubs that find her. */
    private function oneMember(): void
    {
        require_once __DIR__ . '/../Stubs/peepso-user-search-stub.php';
        require_once __DIR__ . '/../Stubs/member-search-candidates-stub.php';
        \PeepSoUserSearch::reset();
        unset($GLOBALS['__bcc_member_candidates_fail'], $GLOBALS['__bcc_member_candidates_args']);
        $GLOBALS['__bcc_member_candidates'] = [7];

        $alice               = new \WP_User();
        $alice->ID           = 7;
        $alice->user_login   = 'login7';
        $alice->user_nicename = 'alice';
        $alice->display_name = 'Alice';
        $GLOBALS['__bcc_userdata'] = [7 => $alice];
        $GLOBALS['__bcc_usermeta'] = [7 => ['bcc_handle' => 'alice']];
    }

    public function testTheMatcherIsAskedToFailClosed(): void
    {
        $this->oneMember();

        self::assertCount(1, UserSearchRepository::search('ali', 20, 0), 'the control: nothing broken, Alice is found');
        // Positional: the query, ids()' own default $max, then the flag.
        self::assertSame(['ali', 1000, true], $GLOBALS['__bcc_member_candidates_args']);
    }

    public function testAMatchingReadThatDidNotRunIsAnErrorNotNobody(): void
    {
        $this->oneMember();
        $GLOBALS['__bcc_member_candidates_fail'] = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('read failed in ids');
        UserSearchRepository::search('ali', 20, 0);
    }

    public function testThePrivacyReadTurnsOffTheTwoDefaultsThatHideAFailure(): void
    {
        $this->oneMember();

        UserSearchRepository::search('ali', 20, 0);

        self::assertNotNull(\PeepSoUserSearch::$lastCtor);
        $args = \PeepSoUserSearch::$lastCtor['args'];
        self::assertFalse($args['count_total'] ?? null, 'FOUND_ROWS() would clear the failed read\'s last_error');
        self::assertFalse($args['cache_results'] ?? null, 'a failed read\'s [] would be cached as "no members"');
        self::assertSame([7], $args['include'], 'the F-302 / F-431 shape is unchanged');
        self::assertSame(1, $args['number']);
    }

    public function testAPrivacyReadThatDidNotRunIsAnErrorNotNobody(): void
    {
        $this->oneMember();
        \PeepSoUserSearch::$failIncludeWith = self::DB_ERROR;

        try {
            UserSearchRepository::search('ali', 20, 0);
            self::fail('a privacy read that did not run answered "no members"');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('handleOrNameMatches privacy read', $e->getMessage());
            self::assertStringContainsString(self::DB_ERROR, $e->getMessage());
        }
    }

    public function testAnErrorLeftByAnEarlierQueryIsNotBlamedOnThePrivacyRead(): void
    {
        $this->oneMember();
        $GLOBALS['wpdb']->last_error = 'an unrelated query failed earlier';

        self::assertCount(1, UserSearchRepository::search('ali', 20, 0));
    }

    /** The route: a search that could not run is a retryable 503, and is never cached. */
    public function testTheRouteAnswersAFailedSearchWithARetryable503AndCachesNothing(): void
    {
        require_once dirname(__DIR__) . '/Stubs/user-search-generation-stub.php';
        UserSearchGenStore::reset();
        UserSearchGenStore::$serviceThrows = '[MemberSearchCandidates] read failed in ids: display name lookup';

        $response = (new UserSearchController())->handle(new \WP_REST_Request(['q' => 'alice', 'limit' => 20]));

        self::assertSame(503, $response->get_status());
        self::assertSame('bcc_upstream_unavailable', $response->get_data()['code']);
        self::assertSame(['Retry-After' => '5'], $response->get_headers());
        // Only result keys count: the generation counter (F-430) is seeded
        // before the search runs, whatever its outcome.
        $resultKeys = array_values(array_filter(
            UserSearchGenStore::$setKeys,
            static fn (string $key): bool => str_starts_with($key, 'user_search_')
        ));
        self::assertSame([], $resultKeys, 'a failed search must not be cached as an answer');
        self::assertContains('bcc_user_search_generation', UserSearchGenStore::$setKeys, 'the control: the harness does record cache writes');
    }
}
