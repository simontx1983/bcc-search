<?php

namespace BCC\Search\DTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only DTO for a user-search row.
 *
 * Fields map 1:1 to the values projected by UserSearchRepository::search()
 * (ID, user_login, user_nicename, display_name, bcc_handle meta). Email is
 * deliberately absent — user search must not leak PII. Avatar URL is
 * resolved downstream by UserSearchService, not stored here.
 *
 * `handle` is the §B6 canonical public handle (`bcc_handle` user meta);
 * empty string when the account predates handles — the service falls back
 * to the nicename for display in that case, never the login.
 */
final class UserDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $userLogin,
        public readonly string $userNicename,
        public readonly string $displayName,
        public readonly string $handle = '',
    ) {
    }
}
