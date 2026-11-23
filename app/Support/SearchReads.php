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
 * `$wpdb->last_error` AFTER a `get_results()`. It replaces them AS CALLERS
 * MOVE, rather than all at once.
 *
 * ⚠ ONE READ CANNOT SIMPLY MOVE HERE, and it is the one left behind:
 * `UserSearchRepository::handleOrNameMatches()` guards a PRIVACY read made
 * by `PeepSoUserSearch`, a `WP_User_Query` whose SQL this plugin never
 * holds — so there is no statement to hand to a method here. Its own
 * `throwOnDbError` stays until that is designed separately (F-432 context),
 * which is why that one private copy remains.
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
            self::fail($context);
        }

        return $rows;
    }

    /**
     * The one refusal, so every read shape fails identically.
     *
     * ⚠ The message says "could not be trusted", NOT "errored". On the
     * pre-`flush()` bail there IS no error to quote, and a message implying
     * one would send the next person looking for a driver error that was
     * never recorded. The driver error is appended only when there is one.
     *
     * @throws \RuntimeException always
     *
     * @return never
     */
    private static function fail(string $context): never
    {
        global $wpdb;
        $detail = (string) $wpdb->last_error;

        throw new \RuntimeException(
            $context . ': the read could not be trusted'
            . ($detail !== '' ? ' (' . $detail . ')' : '')
        );
    }

    /**
     * Assoc rows, or NULL when they cannot be trusted — the non-throwing
     * sibling, for a caller with a fallback path rather than a 503.
     *
     * ⚠⚠ "CANNOT THROW" IS NOT "CAN TRUST THE ROWS", and conflating
     * the two is what this method exists to stop. The FULLTEXT candidate
     * read falls through to the title-prefix LIKE on error, deliberately —
     * degraded but available. That policy is about ERRORS; it says nothing
     * about a pre-`flush()` bail, where `last_error` is EMPTY and
     * `last_result` still holds the PREVIOUS query's rows. A caller
     * checking only `last_error` would return those rows as results,
     * bypassing the visibility clause its own query carried.
     *
     * So null means "fall through", exactly as an error does, and a
     * non-empty return is rows this query actually produced. The only
     * difference from {@see rowsOrThrow()} is WHO decides what to do about
     * an untrusted read.
     *
     * ⚠ `[]` is a real answer ("no rows") and is NOT null. The FULLTEXT
     * path also falls through on an empty result, but for its own stopword
     * reason — a different decision from a read it cannot trust, and the
     * two must stay distinguishable here.
     *
     * @return list<array<string, mixed>>|null null = the read cannot be trusted
     */
    public static function rowsOrNull(string $sql): ?array
    {
        return self::trustedRowsAssoc($sql);
    }

    /**
     * The same rows as OBJECTS, or THROW.
     *
     * ⚠ A separate method rather than a flag, because the SHAPE is what
     * the caller's consumers depend on: `$row->slug` and `$row['slug']` are
     * not interchangeable, and a boolean at the call site would not say
     * which you get. Replaces a bare `$wpdb->get_results($sql)` — wpdb's
     * default output — followed by a `last_error` check.
     *
     * @return list<object>
     * @throws \RuntimeException when the read cannot be trusted
     */
    public static function objectsOrThrow(string $sql, string $context): array
    {
        $rows = self::trustedRows($sql);
        if ($rows === null) {
            self::fail($context);
        }

        return $rows;
    }

    /**
     * The FIRST column of every row, or THROW — the `get_col()` shape.
     *
     * ⚠ Why not `get_col()`: like `get_var()` and `get_results()` it calls
     * `query()` and DISCARDS the return value. It also ALWAYS returns an
     * array, so a failed read is indistinguishable from "no rows" — the
     * ambiguity this seam exists to remove.
     *
     * @return list<string>
     * @throws \RuntimeException when the read cannot be trusted
     */
    public static function columnOrThrow(string $sql, string $context): array
    {
        $out = [];
        foreach (self::objectsOrThrow($sql, $context) as $row) {
            $values = array_values(get_object_vars($row));
            if ($values !== []) {
                $out[] = (string) $values[0];
            }
        }

        return $out;
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
