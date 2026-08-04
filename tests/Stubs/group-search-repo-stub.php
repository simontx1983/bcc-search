<?php
/**
 * GroupSearchService test stubs.
 *
 * Loaded ONLY from inside a @runInSeparateProcess subprocess (see
 * GroupSearchServiceTest) so the main PHPUnit process never sees this
 * fake definition of GroupSearchRepository.
 *
 * Same isolation pattern as user-search-repo-stub.php: the fake is
 * defined at the exact production FQN with a class_exists() guard, so
 * when the real GroupSearchService is required below its
 * `use BCC\Search\Repositories\GroupSearchRepository` binds to this
 * fake and the service projects fixture DTOs without touching the DB.
 */

declare(strict_types=1);

namespace BCC\Search\Repositories {

    if (!class_exists(__NAMESPACE__ . '\\GroupSearchRepository', false)) {
        final class GroupSearchRepository
        {
            /** @var list<\BCC\Search\DTO\GroupDTO> */
            public static array $return = [];

            public static ?string $lastQuery = null;
            public static ?int $lastLimit = null;

            /** @return list<\BCC\Search\DTO\GroupDTO> */
            public static function search(string $query, int $limit): array
            {
                self::$lastQuery = $query;
                self::$lastLimit = $limit;
                return self::$return;
            }

            public static function reset(): void
            {
                self::$return    = [];
                self::$lastQuery = null;
                self::$lastLimit = null;
            }
        }
    }
}

// ── Load the REAL service — its repository collaborator is now stubbed ──
namespace {
    require_once __DIR__ . '/../../app/Services/GroupSearchService.php';
}
