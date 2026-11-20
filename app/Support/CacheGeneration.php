<?php

namespace BCC\Search\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one rule for publishing a cache generation or version so that a
 * concurrent BUMP is never overwritten (F-445).
 *
 * Every search vertical namespaces its cache with a counter held in an
 * option and mirrored in the object cache. All three read it the same way —
 * cache first, option fallback — and all three used to finish that fallback
 * with a plain `wp_cache_set`.
 *
 * ── The race ──────────────────────────────────────────────────────────
 *
 * The fallback reads the option and then writes the cache. A bump in
 * another request can land BETWEEN those two steps, writing both a newer
 * option and a newer cache entry; the plain `wp_cache_set` then put the
 * older value back for the whole TTL. In `SearchController` the 2-second
 * heal lock does not help, because it orders healers against each other,
 * not a heal against a bump.
 *
 * The consequence is the one these counters exist to prevent: the counter
 * namespaces the cache, so a stale counter leaves entries written before a
 * visibility change reachable — and the privacy bust bumps it precisely so
 * they are not.
 *
 * ── Why "never regress" is a sound rule ───────────────────────────────
 *
 * Only because F-442 made every flush strictly increasing
 * (`max(time(), current + 1)`). With a bare `time()` two bumps inside one
 * second could tie, and "a value >= mine means someone is ahead" would be
 * ambiguous. The two changes compose; neither is sufficient alone.
 *
 * ⚠ THIS NARROWS THE WINDOW, IT DOES NOT CLOSE IT. A bump landing between
 * this class's own read and its write still loses. WordPress's object-cache
 * API has no compare-and-set — `wp_cache_cas()` is Memcached-only and not
 * part of the API — so closing it completely would mean holding the counter
 * in `wp_cache_incr`, which cannot survive eviction, which is why an option
 * is the source of truth in the first place. What this removes is a window
 * a whole option read wide; what remains is one cache read.
 *
 * ⚠⚠ AND THAT ONE CACHE READ MUST BE FORCED. Under the production Redis
 * drop-in an unforced re-read is answered from a per-request in-memory
 * array, which already holds the value the caller just rejected — so the
 * guard would never see a concurrent bump, would write every time, and this
 * whole class would be a no-op on the live site while its tests passed.
 * See the comment at the read itself.
 *
 * ⛔ FOR HEAL AND POISON PUBLISHES ONLY. A BUMP MUST NEVER COME THROUGH
 * HERE. A bump is the authority on what the newest value is, so it writes
 * unconditionally; routing it through this guard would let a stale-high
 * cached value make the bump lose, which is the very failure this class
 * exists to prevent, inverted. There is a test pinning that the bump paths
 * do not use it — a "use the helper everywhere" tidy-up is the obvious way
 * to reintroduce the bug.
 *
 * @package BCC\Search\Support
 */
final class CacheGeneration
{
    /**
     * Is this a usable counter value?
     *
     * wp_options and the object cache both hand back strings, so an int and
     * a digit-string are equally valid; anything else is poison.
     *
     * @param mixed $value
     */
    public static function isValid($value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    /**
     * Publish $value for $key unless the cache already holds something
     * newer, and return whichever value now stands.
     *
     * Returning the STANDING value rather than `$value` matters: when a
     * bump has won, this caller should serve from the bump's namespace too,
     * not from the older one it happened to read. Otherwise the guard would
     * protect the cache and leave this one request reading stale.
     */
    public static function publishWithoutRegressing(string $key, string $group, int $ttl, int $value): int
    {
        // ⚠⚠ `$force = true` IS THE WHOLE GUARD. Without it this method is a
        // no-op on the live site. Production runs the Redis Object Cache
        // drop-in, whose `get()` answers from a per-request in-memory array
        // and only consults Redis when that array has no entry OR $force:
        //
        //     if ( isset( $this->cache[ $derived_key ] ) && ! $force ) { ... }
        //
        // Every caller reaches this method immediately after its OWN
        // `wp_cache_get` found the poison — so the key is already in that
        // array, and an unforced re-read hands back the very value the caller
        // just rejected. It would never see the concurrent bump this guard
        // exists to protect, and would write unconditionally every time.
        //
        // A forced read costs one extra round trip, on the heal and poison
        // paths only: the miss path uses `wp_cache_add` and never comes here.
        $current = wp_cache_get($key, $group, true);

        // `>=`, not `>`: an equal value needs no write at all, and
        // rewriting it would only refresh a TTL the bump has just set.
        if (self::isValid($current) && (int) $current >= $value) {
            return (int) $current;
        }

        wp_cache_set($key, $value, $group, $ttl);

        return $value;
    }
}
