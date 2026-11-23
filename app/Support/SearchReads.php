<?php

namespace BCC\Search\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The ONE place bcc-search runs a SELECT it needs to TRUST (F-472).
 *
 * ── What it replaces ──────────────────────────────────────────────────
 *
 * Three copies of a private `throwOnDbError()` — in GroupSearchRepository,
 * SearchRepository and UserSearchRepository — each of which checked
 * `$wpdb->last_error` AFTER a `get_results()`.
 *
 * ⚠⚠ That shape cannot see the failure that matters most.
 * `wpdb::query()` returns false on paths that run BEFORE it calls
 * `flush()` — a failed reconnect (`! $this->ready`) and an emptied `query`
 * filter. On those paths `last_result` still holds the PREVIOUS query's
 * rows, and `get_results()` discards `query()`'s return value, so it hands
 * those rows back with `last_error` EMPTY. A `last_error` check reads that
 * as success.
 *
 * For a search repository the consequence is not an empty page: it is
 * another query's rows served as search results, having never passed this
 * query's visibility clause. Fail-WRONG, not fail-open, and no amount of
 * `last_error` checking can detect it.
 *
 * ── Why a CLASS and not a trait ───────────────────────────────────────
 *
 * ⚠ Deliberate, and the reason is the test harness. A trait would make
 * every repository that used it carry `ProbesReads` at CLASS-LOAD time —
 * and bcc-search's suites load bcc-core files BY PATH (bcc-core's
 * autoloader is not in play), so each of those repositories' tests would
 * then need `ProbesReads.php` required before the repository, or fatal with
 * `Trait "..." not found`. That is precisely how a bcc-core change turned
 * bcc-search's group-privacy suite red with 9 fatals.
 *
 * A static CLASS is resolved when a method is CALLED, not when the caller
 * is loaded. So only a test that actually executes a read needs the trait
 * present, and the `use` below is the single place in this plugin that
 * depends on it.
 *
 * ⚠ bcc-core is a hard dependency of this plugin — bcc-search.php returns
 * early unless `BCC_CORE_VERSION` is defined — so the trait is always
 * available at runtime.
 *
 * @package BCC\Search\Support
 */
final class SearchReads
{
    use \BCC\Core\Repositories\ProbesReads;

    /**
     * Run a SELECT and return its rows as associative arrays, or THROW.
     *
     * Replaces `$rows = $wpdb->get_results($sql, ARRAY_A);` immediately
     * followed by a `last_error` check. The rows come back in the same
     * shape, so consumers are unchanged; what changes is that a read which
     * cannot be trusted raises instead of being believed.
     *
     * ⚠ The caller passes PREPARED sql. This runs it as given.
     *
     * @param  string $context what failed, for the exception message
     * @return list<array<string, mixed>>
     * @throws \RuntimeException when the read cannot be trusted
     */
    public static function rowsOrThrow(string $sql, string $context): array
    {
        $rows = self::trustedRowsAssoc($sql);
        if ($rows === null) {
            global $wpdb;
            $detail = (string) $wpdb->last_error;

            // ⚠ The message says "could not be trusted", not "errored".
            // On the pre-flush bail there IS no error to quote, and a
            // message implying one would send the next person looking for a
            // driver error that was never recorded.
            throw new \RuntimeException(
                $context . ': the read could not be trusted'
                . ($detail !== '' ? ' (' . $detail . ')' : '')
            );
        }

        return $rows;
    }

    /**
     * One scalar from a trusted read, or THROW. `null` is a real answer
     * meaning "no row"; it is NOT what a failed read returns.
     *
     * ⚠ Why not `get_var()`: it calls `query()` and DISCARDS the return
     * value, then reads `last_result` — so on the pre-`flush()` bail it
     * answers with a value belonging to an unrelated query, with nothing
     * set for a caller to check.
     *
     * @return string|null null = the query matched no row
     * @throws \RuntimeException when the read cannot be trusted
     */
    public static function scalarOrThrow(string $sql, string $context): ?string
    {
        $rows = self::rowsOrThrow($sql, $context);
        if ($rows === []) {
            return null;
        }

        $first = reset($rows);
        if (!is_array($first) || $first === []) {
            return null;
        }
        $value = reset($first);

        return $value === null ? null : (string) $value;
    }
}
