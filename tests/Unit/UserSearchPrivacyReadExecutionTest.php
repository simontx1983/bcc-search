<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\DTO\UserDTO;
use BCC\Search\Repositories\UserSearchRepository;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The privacy pass must have RUN, not merely reported no error (F-472).
 *
 * ## Why a different technique from the rest of the sweep
 *
 * Everywhere else, F-472 replaced a read with one that checks `query()`'s
 * return value. This read is issued inside `WP_User_Query`, so there is no
 * return value of ours to check — and `last_error` alone cannot police it,
 * because `wpdb::query()` returns false ABOVE `flush()` on a failed
 * reconnect and on an emptied `query` filter, recording no error at all.
 *
 * ## What is being pinned
 *
 * That the ids this path returns are the privacy filter's own answer, or
 * that the caller gets an exception instead of ids.
 *
 * `fields => 'ID'` makes `WP_User_Query` read through `$wpdb->get_col()`,
 * which never returns null, so SOME value is always available to hand back
 * whether or not the statement behind it executed. A value that did not
 * come from this statement has not passed this statement's conditions,
 * whatever it happens to contain.
 *
 * ⛔ Inspecting the returned ids cannot establish that, which is why the
 * tests below assert on the READ rather than on its output.
 *
 * ## The two facts, and why each is necessary
 *
 * The repository requires BOTH that `num_queries` moved and that
 * `last_query` is this read's own SQL. Two tests here exist only to prove
 * neither half would do on its own:
 *
 *   - `testANumQueriesDeltaAloneCannotDetectABail` — PeepSo reads a
 *     display-name setting before the pass when `system_override_name` is
 *     on, so the counter moves even when the read never ran;
 *   - `testAnIdenticalPreviousStatementAloneCannotDetectABail` — a second
 *     identical search leaves the same SQL in `last_query`, so the SQL
 *     comparison passes even when the read never ran.
 *
 * ## Why not reimplement the predicate instead
 *
 * It depends on two PeepSo config options, the viewer, an `is_admin()`
 * branch, PeepSo's schema, and TWO PUBLIC extension points
 * (`peepso_user_search_args`, `peepso_pre_user_query`). Owning it would
 * silently drop anything a PeepSo add-on or a future core version adds
 * through those hooks — a privacy regression introduced by a privacy fix.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserSearchPrivacyReadExecutionTest extends TestCase
{
    /** Identifies the privacy pass's statement, via PeepSo's own join. */
    private const PRIVACY_PASS = 'wp_peepso_users acc';

    /** @var list<int> */
    private const CANDIDATES = [1, 2, 3];

    public function testAPrivacyPassThatDidNotRunIsRefused(): void
    {
        self::load();
        $this->leaveTheCandidateColumnBehind();
        $GLOBALS['wpdb']->failOnSqlContaining = self::PRIVACY_PASS;

        $before = (int) $GLOBALS['wpdb']->num_queries;

        try {
            UserSearchRepository::search('op', 10, 0);
            self::fail('a privacy pass that never ran was treated as a filtered result');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('privacy-filtered read did not run', $e->getMessage());
        }

        // The premise, and the reason no `last_error` check could work here.
        self::assertSame('', $GLOBALS['wpdb']->last_error, 'premise: the bail records no error');
        self::assertSame(
            $before,
            (int) $GLOBALS['wpdb']->num_queries,
            'premise: a bail never reaches _do_query(), so the counter must not move'
        );

        // ⭐ And the refusal is not academic: a readable value WAS available
        // at that moment, left by another statement, so without the check
        // there was something to hand back rather than nothing.
        self::assertSame(
            self::CANDIDATES,
            array_map('intval', array_column(
                array_map('get_object_vars', $GLOBALS['wpdb']->last_result),
                'ID'
            )),
            'premise: a value from another statement was readable, so the refusal matters'
        );
    }

    /** ⭐ (1) alone is not enough: something else queried in between. */
    public function testANumQueriesDeltaAloneCannotDetectABail(): void
    {
        self::load();
        $this->leaveTheCandidateColumnBehind();
        \PeepSoUserSearch::$queryBeforeRead   = true;
        $GLOBALS['wpdb']->failOnSqlContaining = self::PRIVACY_PASS;

        $before = (int) $GLOBALS['wpdb']->num_queries;

        try {
            UserSearchRepository::search('op', 10, 0);
            self::fail('an unrelated statement masked a privacy pass that never ran');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('privacy-filtered read did not run', $e->getMessage());
        }

        // The counter DID move — and the refusal still happened, which is
        // the whole point of pairing it with the statement comparison.
        self::assertGreaterThan(
            $before,
            (int) $GLOBALS['wpdb']->num_queries,
            'the premise of this test: an unrelated statement ran'
        );
    }

    /** ⭐ (2) alone is not enough: the previous statement was identical. */
    public function testAnIdenticalPreviousStatementAloneCannotDetectABail(): void
    {
        self::load();

        // ⚠ Built by running the privacy pass ON ITS OWN, not by letting a
        // whole search() finish. Measuring showed a completed search leaves
        // publicIdentities' USERMETA read in `last_query`, not this
        // statement — the first version of this test asserted otherwise and
        // failed. The scenario needs the identical privacy SQL to be the
        // immediately preceding statement.
        new \PeepSoUserSearch(
            [
                'number'        => count(self::CANDIDATES),
                'orderby'       => 'username',
                'order'         => 'ASC',
                'include'       => self::CANDIDATES,
                'count_total'   => false,
                'cache_results' => false,
            ],
            null,
            ''
        );
        $priorQuery = (string) $GLOBALS['wpdb']->last_query;
        self::assertStringContainsString(
            self::PRIVACY_PASS,
            $priorQuery,
            'the premise: the identical privacy statement ran just before'
        );

        // Now it bails. `last_query` still holds that identical SQL, so the
        // statement comparison ALONE would read as success.
        $GLOBALS['wpdb']->failOnSqlContaining = self::PRIVACY_PASS;
        $before = (int) $GLOBALS['wpdb']->num_queries;

        try {
            UserSearchRepository::search('op', 10, 0);
            self::fail('an identical previous statement masked a privacy pass that never ran');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('privacy-filtered read did not run', $e->getMessage());
        }

        self::assertSame(
            $priorQuery,
            (string) $GLOBALS['wpdb']->last_query,
            'the premise of this test: last_query still holds the identical earlier SQL'
        );
        self::assertSame(
            $before,
            (int) $GLOBALS['wpdb']->num_queries,
            'the premise of this test: the counter did NOT move, which is what caught it'
        );
    }

    /**
     * ⭐⭐ A `users_pre_query` answer is REFUSED, and that is the decision,
     * not an oversight.
     *
     * `WP_User_Query::query()` applies that filter (WP 5.1+) BEFORE it
     * assembles `request` — class-wp-user-query.php:820. A plugin answering
     * it supplies the results itself and no statement runs. PeepSo's privacy
     * predicate is added in `pre_user_query` (:772), and `request` is the
     * only place `query_where` / `query_from` are ever used — so on that
     * path the predicate is built and then DISCARDED, and the plugin's
     * answer replaces it wholesale.
     *
     * Accepting it would reopen exactly what this check closes, by a
     * different route than a read that failed. So it fails closed.
     *
     * ⚠ The cost is availability, accepted deliberately: if anything ever
     * answers this query, member search returns 503 until someone decides
     * what should filter instead. A 503 is discoverable and gets fixed; ids
     * that skipped the filter are not discoverable at all. This test exists
     * so that trade is something a future change has to argue with, rather
     * than a line that looks like a missing branch.
     */
    public function testAPluginAnsweringTheQueryIsRefusedNotAccepted(): void
    {
        self::load();
        // A plugin answers with ids that never passed the privacy predicate.
        \PeepSoUserSearch::$preQueryAnswer = self::CANDIDATES;

        try {
            UserSearchRepository::search('op', 10, 0);
            self::fail('ids that never passed the privacy predicate were accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('users_pre_query', $e->getMessage());
            self::assertStringContainsString('this read was built: no', $e->getMessage());
        }

        // The premise: no privacy statement was sent at all.
        self::assertSame(
            [],
            $GLOBALS['wpdb']->privacyQueries,
            'the premise: no privacy statement was sent'
        );
    }

    /**
     * ⭐ The same refusal when SOMETHING ELSE QUERIED, which is the case
     * that isolates the built-ness check.
     *
     * This test exists because a mutation control told me it was missing. In
     * the test above nothing else queries, so the counter never moves and
     * the refusal comes from THAT half — meaning a mutant which accepted an
     * unbuilt read still refused, and survived.
     *
     * With an unrelated statement in between the counter DOES move, so the
     * only thing left refusing is that this read was never built. Accepting
     * here — the shape of "just tolerate users_pre_query" — now fails.
     */
    public function testAPluginAnsweringIsStillRefusedWhenSomethingElseQueried(): void
    {
        self::load();
        \PeepSoUserSearch::$preQueryAnswer  = self::CANDIDATES;
        \PeepSoUserSearch::$queryBeforeRead = true;

        $before = (int) $GLOBALS['wpdb']->num_queries;

        try {
            UserSearchRepository::search('op', 10, 0);
            self::fail('ids that never passed the privacy predicate were accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('this read was built: no', $e->getMessage());
        }

        // The premise of this test: a statement DID run, so the counter is
        // no longer what refuses — the unbuilt request is.
        self::assertGreaterThan(
            $before,
            (int) $GLOBALS['wpdb']->num_queries,
            'the premise: an unrelated statement ran'
        );
        self::assertSame(
            [],
            $GLOBALS['wpdb']->privacyQueries,
            'the premise: still no privacy statement'
        );
    }

    /**
     * The control that keeps all of the above honest: a healthy pass still
     * returns the privacy-FILTERED ids, and fewer than were offered. A
     * change that refused unconditionally would pass every test above.
     */
    public function testAHealthyPrivacyPassStillFiltersAndAnswers(): void
    {
        self::load();
        // PeepSo hides member 2.
        \PeepSoUserSearch::$visible = [1, 3];

        $handles = array_map(
            static fn (UserDTO $dto): string => $dto->handle,
            UserSearchRepository::search('op', 10, 0)
        );

        self::assertSame(['op-one', 'op-three'], $handles, 'the privacy filter was not applied');
    }

    /** No candidates means PeepSo is never asked, and nothing is refused. */
    public function testAnEmptyCandidateSetNeverReachesThePrivacyPass(): void
    {
        self::load();
        $GLOBALS['__bcc_member_candidates'] = [];

        self::assertSame([], UserSearchRepository::search('op', 10, 0));
        self::assertSame([], $GLOBALS['wpdb']->privacyQueries, 'PeepSo was asked with no candidates');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private static function load(): void
    {
        require_once __DIR__ . '/../Stubs/peepso-user-search-stub.php';
        // F-472: the verified-read trait and the core meta repository, in
        // dependency order, BY PATH — bcc-core is not autoloaded here.
        \BCC\Search\Tests\Support\CoreSibling::requireVerifiedReads();
        require_once __DIR__ . '/../Stubs/member-search-candidates-stub.php';
        \PeepSoUserSearch::reset();

        $GLOBALS['__bcc_userdata'] = [];
        $GLOBALS['__bcc_usermeta'] = [];
        foreach (['one', 'two', 'three'] as $i => $word) {
            $id                                   = $i + 1;
            $user                                 = new \WP_User();
            $user->ID                             = $id;
            $user->user_login                     = 'login' . $id;
            $user->user_nicename                  = 'login' . $id;
            $user->display_name                   = 'Member ' . $word;
            $GLOBALS['__bcc_userdata'][$id]       = $user;
            $GLOBALS['__bcc_usermeta'][$id]       = ['bcc_handle' => 'op-' . $word];
        }
        $GLOBALS['__bcc_member_candidates'] = self::CANDIDATES;
    }

    /**
     * Leave a readable value behind from an earlier statement.
     *
     * Assigned directly, because that IS the premise: a bail leaves
     * `last_result` exactly as the preceding statement left it, so the
     * question the check answers is whether THIS statement produced the
     * value — not whether a value exists.
     */
    private function leaveTheCandidateColumnBehind(): void
    {
        $GLOBALS['wpdb']->last_result = array_map(
            static fn (int $id): object => (object) ['ID' => (string) $id],
            self::CANDIDATES
        );
    }
}
