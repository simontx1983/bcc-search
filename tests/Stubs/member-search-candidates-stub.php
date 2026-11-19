<?php
/**
 * bcc-trust's MemberSearchCandidates, as UserSearchRepository sees it
 * (F-302). The real matcher (display name or current @handle, never the
 * login) is tested in bcc-trust; here it returns what a test configures:
 *   $GLOBALS['__bcc_member_candidates'] when set, else the ids the PeepSo
 *   stub was primed with (PeepSoUserSearch::$nextResults), so the privacy
 *   tests written against the old term pass keep their meaning.
 * The needle it was asked for is recorded in
 * $GLOBALS['__bcc_member_candidates_needle'], and every argument in
 * $GLOBALS['__bcc_member_candidates_args'].
 *
 * F-432: the signature is bcc-trust's own. $throwOnReadFailure is APPENDED,
 * and MAX_CANDIDATES is the constant bcc-search passes as `$max`. When
 * $GLOBALS['__bcc_member_candidates_fail'] is set, a call that PASSES the
 * flag throws a RuntimeException, as bcc-trust's RepositoryReadFailure does.
 * A call that does not pass it answers [], which is what a failed lookup
 * looked like before.
 *
 * Loaded only by tests that model bcc-trust as ACTIVE; a test that needs it
 * absent simply does not require this file (each test is its own process).
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!class_exists(MemberSearchCandidates::class, false)) {
    final class MemberSearchCandidates
    {
        public const MAX_CANDIDATES = 1000;

        /** @return list<int> */
        public static function ids(string $needle, int $max = self::MAX_CANDIDATES, bool $throwOnReadFailure = false): array
        {
            $GLOBALS['__bcc_member_candidates_needle'] = $needle;
            $GLOBALS['__bcc_member_candidates_args']   = [$needle, $max, $throwOnReadFailure];
            if (!empty($GLOBALS['__bcc_member_candidates_fail'])) {
                if ($throwOnReadFailure) {
                    throw new \RuntimeException('[MemberSearchCandidates] read failed in ids: display name lookup — simulated');
                }
                return [];
            }
            $ids = $GLOBALS['__bcc_member_candidates'] ?? \PeepSoUserSearch::$nextResults;
            return array_values(array_map('intval', $ids));
        }
    }
}
