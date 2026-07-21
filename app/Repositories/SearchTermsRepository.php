<?php

namespace BCC\Search\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search-terms analytics store — aggregate, privacy-conscious.
 *
 * Records ONE row per (normalized term, vertical, UTC day) with a running
 * hit count and the last-observed result count. There is deliberately NO
 * per-event row, NO user id, NO IP, NO timestamp-per-search: a search term
 * is never linked to who searched it. This is what makes "top searches"
 * and "zero-result terms" reports readable without turning the query log
 * into PII (the codebase keeps query text out of logs for exactly that
 * reason; this store is the aggregate exception, not a log).
 *
 * The term is already `mb_strtolower(trim(...))`-normalized by the caller
 * (the same normalization the cache fingerprints use); junk / low-entropy
 * queries never reach the record sites (QueryQualityGate short-circuits
 * upstream), so the table only ever holds real, searchable terms.
 *
 * Recording happens on the REBUILD path only (never a cache hit), so this
 * is a *sampled* popularity signal — roughly one sample per (term, vertical)
 * per cache-TTL window under load — which is the right trade-off for keeping
 * the hot path free (same reasoning as the LKG/breaker machinery).
 *
 * @package BCC\Search\Repositories
 */
final class SearchTermsRepository
{
    private const TABLE_SUFFIX     = 'bcc_search_terms';
    private const INSTALLED_OPTION = 'bcc_search_terms_installed';
    private const INSTALL_LOCK     = 'bcc_search_terms_install';

    /** Terms are truncated to this width (matches the column). */
    public const TERM_MAXLEN = 100;

    /** Rows older than this many days are pruned. */
    public const RETENTION_DAYS = 60;

    /** Prune batch size + per-tick iteration cap (mirrors bcc_core_rl_cleanup). */
    private const PRUNE_BATCH      = 1000;
    private const PRUNE_MAX_ITERS  = 10;

    /** "Rising" comparison window (recent vs the immediately prior window). */
    private const RISING_WINDOW_DAYS = 7;
    /** Floor on recent hits so week-over-week noise (1→2) can't surface. */
    private const RISING_MIN_HITS    = 3;

    /** Request-scoped memo so a hot path can't re-probe the install each call. */
    private static bool $installChecked = false;

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Create the analytics table if absent. Mirrors the FT-index self-heal
     * idiom: option-guarded, advisory-locked against a thundering herd, and
     * the installed flag is set ONLY after the table is confirmed present —
     * a failed dbDelta leaves the flag unset so the next call retries rather
     * than silently marking the store "ready" over a missing table.
     */
    public static function ensureTable(): void
    {
        if (self::$installChecked) {
            return;
        }
        self::$installChecked = true;

        if (get_option(self::INSTALLED_OPTION)) {
            return;
        }

        global $wpdb;

        $locked = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT GET_LOCK(%s, 0)", self::INSTALL_LOCK)
        );
        if ($locked !== 1) {
            return;
        }

        try {
            $table   = self::table();
            $charset = $wpdb->get_charset_collate();

            // Aggregate grain is (norm_term, vertical, day) — the UNIQUE key
            // is what the record() UPSERT collides on. No user/ip/event column.
            $sql = "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                norm_term VARCHAR(100) NOT NULL,
                vertical VARCHAR(16) NOT NULL,
                day DATE NOT NULL,
                result_count INT NOT NULL DEFAULT 0,
                hits INT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_term_vertical_day (norm_term, vertical, day),
                KEY day_idx (day),
                KEY vertical_day_hits (vertical, day, hits)
            ) {$charset};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);

            $exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $table)
            );
            if ($exists === $table) {
                update_option(self::INSTALLED_OPTION, 1, false);
            } elseif (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error(
                    '[bcc-search] search-terms table install failed — leaving flag unset so it retries',
                    ['error' => (string) $wpdb->last_error]
                );
            }
        } finally {
            $wpdb->get_var(
                $wpdb->prepare("SELECT RELEASE_LOCK(%s)", self::INSTALL_LOCK)
            );
        }
    }

    /**
     * Record one search sample. Idempotent-per-day via the UNIQUE key: the
     * first search of a (term, vertical) today inserts, every later one bumps
     * `hits` and refreshes the last-seen `result_count`.
     *
     * Callers pass the ALREADY-normalized term (`mb_strtolower(trim($q))`).
     * Empty terms (e.g. trending, which has no query) are ignored. Never
     * throws — analytics must not be able to break a search.
     */
    public static function record(string $vertical, string $normTerm, int $resultCount): void
    {
        // Analytics can NEVER break a search — the whole body is swallow-and-log.
        try {
            $normTerm = trim($normTerm);
            if ($normTerm === '' || $vertical === '') {
                return;
            }
            if (!get_option(self::INSTALLED_OPTION)) {
                return; // Table not installed yet; drop the sample silently.
            }

            $normTerm = mb_substr($normTerm, 0, self::TERM_MAXLEN);

            global $wpdb;
            $table = self::table();

            // gmdate = UTC, consistent with the rest of BCC's day/clock handling.
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (norm_term, vertical, day, result_count, hits, updated_at)
                 VALUES (%s, %s, %s, %d, 1, %s)
                 ON DUPLICATE KEY UPDATE
                    hits = hits + 1,
                    result_count = VALUES(result_count),
                    updated_at = VALUES(updated_at)",
                $normTerm,
                $vertical,
                gmdate('Y-m-d'),
                max(0, $resultCount),
                gmdate('Y-m-d H:i:s')
            ));
        } catch (\Throwable $e) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-search] search-terms record failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Top terms over the last $days, summed across days. When $zeroOnly is
     * true, restrict to terms whose MAX observed result_count is 0 (the
     * content-gap / "nothing found" signal).
     *
     * @return list<array{term: string, vertical: string, hits: int, max_results: int, last_seen: string}>
     */
    public static function topTerms(int $days, int $limit, bool $zeroOnly = false): array
    {
        if (!get_option(self::INSTALLED_OPTION)) {
            return [];
        }
        $days  = max(1, min(365, $days));
        $limit = max(1, min(200, $limit));

        global $wpdb;
        $table = self::table();

        $having = $zeroOnly ? 'HAVING MAX(result_count) = 0' : '';

        /** @var list<array<string, string|null>>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT norm_term, vertical,
                    SUM(hits)            AS hits,
                    MAX(result_count)    AS max_results,
                    MAX(updated_at)      AS last_seen
               FROM {$table}
              WHERE day >= %s
              GROUP BY norm_term, vertical
              {$having}
              ORDER BY SUM(hits) DESC, MAX(updated_at) DESC
              LIMIT %d",
            gmdate('Y-m-d', time() - ($days * DAY_IN_SECONDS)),
            $limit
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'term'        => (string) ($r['norm_term'] ?? ''),
                'vertical'    => (string) ($r['vertical'] ?? ''),
                'hits'        => (int) ($r['hits'] ?? 0),
                'max_results' => (int) ($r['max_results'] ?? 0),
                'last_seen'   => (string) ($r['last_seen'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Rising terms — biggest week-over-week increase in hits.
     *
     * Compares the most recent RISING_WINDOW_DAYS against the window
     * immediately before it and ranks by the absolute delta, so a genuine
     * spike (2 → 90) and a brand-new term (0 → 30) both surface while a
     * perennial term with only week-over-week jitter (400 → 410) sinks.
     * Floored at RISING_MIN_HITS recent hits so a 1 → 2 blip can't rank.
     * The columns (recent / prior / delta) let an operator see WHY a term
     * is here rather than trusting an opaque score.
     *
     * @return list<array{term: string, vertical: string, recent: int, prior: int, delta: int}>
     */
    public static function risingTerms(int $limit = 25): array
    {
        if (!get_option(self::INSTALLED_OPTION)) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        global $wpdb;
        $table = self::table();

        $recentStart = gmdate('Y-m-d', time() - (self::RISING_WINDOW_DAYS * DAY_IN_SECONDS));
        $priorStart  = gmdate('Y-m-d', time() - (2 * self::RISING_WINDOW_DAYS * DAY_IN_SECONDS));

        /** @var list<array<string, string|null>>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT norm_term, vertical,
                    SUM(CASE WHEN day >= %s THEN hits ELSE 0 END)                       AS recent,
                    SUM(CASE WHEN day >= %s AND day < %s THEN hits ELSE 0 END)           AS prior
               FROM {$table}
              WHERE day >= %s
              GROUP BY norm_term, vertical
             HAVING recent >= %d AND recent > prior
              ORDER BY (recent - prior) DESC, recent DESC
              LIMIT %d",
            $recentStart,
            $priorStart,
            $recentStart,
            $priorStart,
            self::RISING_MIN_HITS,
            $limit
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $recent = (int) ($r['recent'] ?? 0);
            $prior  = (int) ($r['prior'] ?? 0);
            $out[] = [
                'term'     => (string) ($r['norm_term'] ?? ''),
                'vertical' => (string) ($r['vertical'] ?? ''),
                'recent'   => $recent,
                'prior'    => $prior,
                'delta'    => $recent - $prior,
            ];
        }
        return $out;
    }

    /**
     * Delete rows older than RETENTION_DAYS in bounded batches (1000/iter,
     * capped at PRUNE_MAX_ITERS per call) so a large backlog can't table-lock.
     * Returns the number of rows deleted this call.
     */
    public static function prune(): int
    {
        if (!get_option(self::INSTALLED_OPTION)) {
            return 0;
        }

        global $wpdb;
        $table  = self::table();
        $cutoff = gmdate('Y-m-d', time() - (self::RETENTION_DAYS * DAY_IN_SECONDS));

        $total = 0;
        for ($i = 0; $i < self::PRUNE_MAX_ITERS; $i++) {
            $deleted = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE day < %s LIMIT %d",
                $cutoff,
                self::PRUNE_BATCH
            ));
            $total += $deleted;
            if ($deleted < self::PRUNE_BATCH) {
                break;
            }
        }
        return $total;
    }

    /** Drop the table + install flag (uninstall path). */
    public static function dropTable(): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option(self::INSTALLED_OPTION);
    }
}
