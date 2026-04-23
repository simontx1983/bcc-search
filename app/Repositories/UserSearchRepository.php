<?php

namespace BCC\Search\Repositories;

use BCC\Search\DTO\UserDTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * User Search Repository
 *
 * All DB access for the user-search vertical lives here. Controllers
 * and services must not use $wpdb directly.
 *
 * **Index discipline.** WordPress creates indexes on wp_users for:
 *   - ID (PRIMARY)
 *   - user_login (UNIQUE)
 *   - user_nicename (INDEX)
 *   - user_email (INDEX)
 *
 * display_name is NOT indexed. A LIKE on display_name is therefore a
 * full-table scan and is deliberately NOT used — we match on the two
 * indexed name columns (user_login, user_nicename), which covers the
 * vast majority of identity searches because user_nicename is
 * generated from display_name at registration. display_name is still
 * returned in the projection so the UI can render it as the label.
 *
 * Email is never matched and never returned — PII by policy.
 */
final class UserSearchRepository
{
    private const COLUMNS = 'ID, user_login, user_nicename, display_name';

    // Default LIMIT enforced at the repository boundary; callers can
    // request smaller but not larger batches.
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

    /**
     * Throw on DB error. Immediate-check contract: call on the line
     * following the wpdb accessor, nothing else between.
     */
    private static function throwOnDbError(string $context): void
    {
        global $wpdb;
        $err = (string) $wpdb->last_error;
        if ($err !== '') {
            throw new \RuntimeException("{$context}: {$err}");
        }
    }

    /**
     * Prefix-match search over indexed name columns.
     *
     * Query shape:
     *
     *     SELECT ID, user_login, user_nicename, display_name
     *     FROM wp_users
     *     WHERE user_status = 0
     *       AND (user_login     LIKE 'q%'
     *         OR user_nicename  LIKE 'q%')
     *     ORDER BY user_login ASC
     *     LIMIT %d
     *
     * - user_status = 0 excludes deactivated accounts (MU usage).
     * - Two LIKE 'prefix%' conditions both hit indexes; the optimizer
     *   resolves via index_merge(union) or sequential use.
     * - ORDER BY user_login ASC is cheap — user_login has a UNIQUE
     *   index so no filesort for small LIMIT.
     *
     * @return list<UserDTO>
     */
    public static function search(string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));

        global $wpdb;

        // Prefix LIKE — index-eligible on both user_login and user_nicename.
        // esc_like escapes %, _, \ so user-supplied literals like 'a_b'
        // can't produce a multi-row match via LIKE semantics.
        $prefix = $wpdb->esc_like($query) . '%';

        $sql = $wpdb->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM ' . $wpdb->users . '
             WHERE user_status = 0
               AND (user_login LIKE %s OR user_nicename LIKE %s)
             ORDER BY user_login ASC
             LIMIT %d',
            $prefix,
            $prefix,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        self::throwOnDbError('UserSearchRepository::search query failed');

        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            $id = $row['ID'] ?? null;
            if (!is_numeric($id)) {
                throw new \LogicException('UserSearchRepository::search: missing/invalid ID');
            }
            if (!isset($row['user_login'], $row['user_nicename'], $row['display_name'])) {
                throw new \LogicException('UserSearchRepository::search: missing projected column');
            }
            $dtos[] = new UserDTO(
                id:           (int) $id,
                userLogin:    (string) $row['user_login'],
                userNicename: (string) $row['user_nicename'],
                displayName:  (string) $row['display_name'],
            );
        }
        return $dtos;
    }
}
