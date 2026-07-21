<?php
/**
 * Fake \BCC\Core\PeepSo\PeepSoMediaCache for UserSearchService tests.
 *
 * Loaded ONLY from inside a @runInSeparateProcess subprocess (see
 * UserSearchServiceTest), same recipe as user-search-repo-stub.php.
 * bcc-core is a sibling plugin the pure-unit autoloader never maps, so
 * without this fake the service's avatar resolution fatals on the
 * missing class; the real one would hit PeepSo + wp_cache anyway.
 *
 * Behaviour mirrors the deterministic wp-stubs get_avatar_url shim
 * ('https://avatars.test/{id}') so URL assertions stay byte-stable
 * across the swap to the cached seam.
 */

declare(strict_types=1);

namespace BCC\Core\PeepSo;

if (!class_exists(__NAMESPACE__ . '\\PeepSoMediaCache', false)) {
    final class PeepSoMediaCache
    {
        public static function avatarUrl(int $userId): string
        {
            return 'https://avatars.test/' . $userId;
        }

        /**
         * @param list<int> $userIds
         * @return array<int, string>
         */
        public static function avatarUrlBulk(array $userIds): array
        {
            $out = [];
            foreach ($userIds as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $out[$id] = 'https://avatars.test/' . $id;
                }
            }
            return $out;
        }
    }
}
