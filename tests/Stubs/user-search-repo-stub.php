<?php
/**
 * UserSearchService test stubs.
 *
 * Loaded ONLY from inside a @runInSeparateProcess subprocess (see
 * UserSearchServiceTest) so the main PHPUnit process never sees this
 * fake definition of UserSearchRepository.
 *
 * The fake is defined at the exact production FQN with a class_exists()
 * guard, so when the real UserSearchService is required below its
 * `use BCC\Search\Repositories\UserSearchRepository` binds to this fake
 * (the autoloader never loads the real repository in the subprocess).
 * This lets us drive UserSearchService::search() with fixture rows whose
 * userLogin deliberately differs from userNicename — the whole point of
 * the leak regression test.
 */

declare(strict_types=1);

namespace BCC\Search\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\UserSearchRepository', false)) {
        final class UserSearchRepository
        {
            /** @var list<\BCC\Search\DTO\UserDTO> */
            public static array $return = [];

            public static ?string $lastQuery = null;
            public static ?int $lastLimit = null;
            public static ?int $lastViewerId = null;

            /** @return list<\BCC\Search\DTO\UserDTO> */
            public static function search(string $query, int $limit, int $viewerId = 0): array
            {
                self::$lastQuery    = $query;
                self::$lastLimit    = $limit;
                self::$lastViewerId = $viewerId;
                return self::$return;
            }

            public static function reset(): void
            {
                self::$return       = [];
                self::$lastQuery    = null;
                self::$lastLimit    = null;
                self::$lastViewerId = null;
            }
        }
    }
}

// ── Load the REAL service — its repository collaborator is now stubbed ──
namespace {
    require_once __DIR__ . '/../../app/Services/UserSearchService.php';
}
