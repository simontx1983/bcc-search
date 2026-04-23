<?php

namespace BCC\Search\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query quality gate — shared junk / low-entropy filter.
 *
 * Extracted so the Users vertical (and any future vertical) applies the
 * exact same rejection rules as the Projects vertical. The existing
 * SearchController keeps its own private copy of this logic untouched
 * per Phase-1 hard requirement ("DO NOT refactor existing project
 * search"); a future consolidation pass can have SearchController
 * delegate here so there is a single source of truth.
 *
 * Rejection rules:
 *   - length < 2 or > 100 chars
 *   - no alphanumeric character (pure punctuation/symbol spam)
 *   - all characters identical (aaaa, 1111, -----)
 *   - every whitespace-separated token is a stopword
 *
 * Stateless. Safe to call on every request; no object allocation.
 */
final class QueryQualityGate
{
    /**
     * Minimum stopword set (superset of InnoDB FT defaults).
     *
     * Hash-indexed for O(1) containment checks. Kept internal so rules
     * can evolve without touching callers.
     *
     * @return array<string,bool>
     */
    private static function stopwordSet(): array
    {
        static $set = null;
        if ($set !== null) {
            return $set;
        }
        $words = [
            'a','about','an','and','are','as','at','be','but','by','com','de',
            'en','for','from','how','i','in','is','it','la','of','on','or',
            'so','that','the','this','to','was','we','what','when','where',
            'who','will','with','und','www',
        ];
        $set = array_fill_keys($words, true);
        return $set;
    }

    /**
     * True iff $q is worth passing to the DB layer.
     */
    public static function isSearchable(string $q): bool
    {
        $q   = trim($q);
        $len = mb_strlen($q);
        if ($len < 2 || $len > 100) {
            return false;
        }

        // No alphanumeric at all: "----", "****", punctuation-only.
        if (!preg_match('/[\p{L}\p{N}]/u', $q)) {
            return false;
        }

        // All one character (aaaa, 1111, .....).
        $first = mb_substr($q, 0, 1);
        if ($first !== '' && mb_str_split($q) === array_fill(0, $len, $first)) {
            return false;
        }

        // Stopword-only: every whitespace-separated token is a known stopword.
        $lowered = mb_strtolower($q);
        $tokens  = preg_split('/\s+/u', $lowered, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens !== []) {
            $stopwords = self::stopwordSet();
            $allStop = true;
            foreach ($tokens as $t) {
                if (!isset($stopwords[$t])) {
                    $allStop = false;
                    break;
                }
            }
            if ($allStop) {
                return false;
            }
        }

        return true;
    }
}
