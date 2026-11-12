<?php
/**
 * bcc-trust's MemberSearchCandidates, as UserSearchRepository sees it
 * (F-302). The real matcher (display name or current @handle, never the
 * login) is tested in bcc-trust; here it returns what a test configures:
 *   $GLOBALS['__bcc_member_candidates'] when set, else the ids the PeepSo
 *   stub was primed with (PeepSoUserSearch::$nextResults), so the privacy
 *   tests written against the old term pass keep their meaning.
 * The needle it was asked for is recorded in
 * $GLOBALS['__bcc_member_candidates_needle'].
 *
 * Loaded only by tests that model bcc-trust as ACTIVE; a test that needs it
 * absent simply does not require this file (each test is its own process).
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!class_exists(MemberSearchCandidates::class, false)) {
    final class MemberSearchCandidates
    {
        /** @return list<int> */
        public static function ids(string $needle, int $max = 1000): array
        {
            $GLOBALS['__bcc_member_candidates_needle'] = $needle;
            $ids = $GLOBALS['__bcc_member_candidates'] ?? \PeepSoUserSearch::$nextResults;
            return array_values(array_map('intval', $ids));
        }
    }
}
