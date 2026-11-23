<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Load a bcc-core file from the checkout beside this plugin.
 *
 * ── Why this is needed at all ─────────────────────────────────────────
 *
 * bcc-search's unit bootstrap registers Composer's autoloader for
 * `BCC\Search\` only. bcc-core's autoloader is NOT in play, so any test
 * that executes code touching a bcc-core class has to require that file BY
 * PATH — and every transitive dependency too, in order.
 *
 * ⚠⚠ THE FAILURE MODE IS A FATAL, NOT AN ASSERTION. A core class that
 * gains a `use` of a core trait makes a by-path require of the class alone
 * die with `Trait "..." not found`. bcc-core F-460 did exactly that to
 * `PeepSoGroupPrivacy` and produced 9 fatals in this plugin's
 * group-privacy suite, with `main` latently red — its last green run
 * predated the core merge. So the dependency goes FIRST, always.
 *
 * ── Why a shared helper ───────────────────────────────────────────────
 *
 * ⚠ Two suites already carry a private `requireCore()` of their own
 * (GroupSearchRepositorySqlTest, SearchRepositoryPrivacySqlTest), and the
 * seam work needed it in four more. Six copies of a path-resolution rule
 * is six chances to get the CI/local asymmetry below wrong, so new callers
 * use this one. The two older copies are left for the follow-up PR that
 * touches those files anyway (§11 — reduce the duplication where the diff
 * is already open, rather than widening this one).
 *
 * Mirrors bcc-trust's `tests/Support/CoreSibling.php`, including the
 * CI-fails / locally-skips contract.
 *
 * @package BCC\Search\Tests\Support
 */
final class CoreSibling
{
    /** Absolute path of the bcc-core checkout beside this plugin. */
    public static function root(): string
    {
        return dirname(__DIR__, 3) . '/bcc-core';
    }

    /**
     * Require $relPath from bcc-core.
     *
     * ⚠ In CI a missing file FAILS: every workflow checks bcc-core out
     * beside this plugin, so an absence means the checkout step moved or
     * bcc-core moved the file — and a SKIP would be invisible in a green
     * run. Locally a bare worktree legitimately has no sibling, so it
     * skips there.
     *
     * ⚠⚠ That asymmetry is the trap worth knowing: a suite that skips
     * locally and runs in CI reports a DIFFERENT TEST COUNT in the two
     * places. A moving skip count is the only local signal that these
     * reads are not being exercised, and a summary line that says OK hides
     * it.
     */
    public static function require(string $relPath): void
    {
        $path = self::root() . '/' . ltrim($relPath, '/');

        if (is_file($path)) {
            require_once $path;
            return;
        }

        if (self::inCi()) {
            Assert::fail(
                'bcc-core file missing at ' . $path . ' while running in CI. '
                . 'Every workflow checks bcc-core out beside bcc-search; if that step moved, '
                . 'or bcc-core moved this file, this cross-repo read has stopped running and '
                . 'drift on either side would ship silently.'
            );
        }

        Assert::markTestSkipped(
            'bcc-core sibling not present at ' . $path
            . ' — expected outside CI (a bare git worktree has no sibling).'
        );
    }

    /**
     * The verified-read trait and everything that uses it, in dependency
     * order.
     *
     * ⭐ A named bundle rather than a list each caller repeats: the ORDER
     * is the load-bearing part, and a caller that gets it wrong gets a
     * fatal rather than a failure. Callers say what they need, not how to
     * load it.
     */
    public static function requireVerifiedReads(): void
    {
        self::require('src/Repositories/ProbesReads.php');

        // ⚠⚠ OPTIONAL BY DESIGN, and this line is why this helper exists.
        // bcc-core F-463 moved UserMetaRepository's read into its own
        // `ReadsMetaRows` trait. A by-path require of the repository alone
        // then fatals with `Trait "..." not found` — which is what happened:
        // 34 fatals in this plugin's CI, hours after the docblock above
        // warned about exactly this.
        //
        // It is `requireIfPresent` rather than `require` so that a bcc-core
        // predating F-463 — which has no such file and does not need one —
        // still works. Required BEFORE the repository that uses it.

        self::requireIfPresent('src/Repositories/ReadsMetaRows.php');

        self::require('src/Repositories/UserMetaRepository.php');
    }

    /**
     * Require $relPath only if bcc-core has it.
     *
     * ⚠ For a dependency that exists in NEWER bcc-core only. Unlike
     * {@see require()} this does NOT fail in CI when the file is absent,
     * because absent is a legitimate state for an older core. The class
     * that needs it will still fatal if it is genuinely required and
     * missing — which is the honest failure, and better than this helper
     * guessing.
     */
    public static function requireIfPresent(string $relPath): void
    {
        $path = self::root() . '/' . ltrim($relPath, '/');
        if (is_file($path)) {
            require_once $path;
        }
    }

    /** True on GitHub Actions (or any runner exporting CI=true). */
    private static function inCi(): bool
    {
        return (string) getenv('GITHUB_ACTIONS') !== ''
            || strtolower((string) getenv('CI')) === 'true';
    }
}
