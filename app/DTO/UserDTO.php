<?php

namespace BCC\Search\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only DTO for a user-search row.
 *
 * Fields map 1:1 to the columns projected by UserSearchRepository::search()
 * (ID, user_login, user_nicename, display_name). Email is deliberately
 * absent — user search must not leak PII. Profile URL and avatar URL
 * are resolved downstream by UserSearchService using PeepSo / WP APIs,
 * not stored here.
 */
final class UserDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $userLogin,
        public readonly string $userNicename,
        public readonly string $displayName,
    ) {
    }
}
