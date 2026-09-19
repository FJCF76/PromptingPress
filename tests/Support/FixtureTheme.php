<?php
/**
 * tests/support/FixtureTheme.php
 *
 * THE #1025 SEAM, USED RATHER THAN BUILT. `pp_get_registered_components()` caches keyed
 * by theme root and derives that root from `get_template_directory()`, which
 * tests/bootstrap.php stubs to read `$GLOBALS['_pp_test_template_dir']`. So repointing
 * the registry needs no production change at all - eight suites already set the
 * cache-buster and PreflightTest already swaps the root. What was missing was a fixture
 * component and this helper.
 *
 * WHAT IT DOES: builds (once per process) a theme root whose components/ directory holds
 * every REAL component plus `ppfixture`, then lets a suite opt in during setUp and drop
 * back out during tearDown.
 *
 * WHY THE REAL COMPONENTS COME ALONG: the slot-engine suites do not test the fixture in
 * isolation. They build compositions, validate them, and read cross-component error
 * context - `FriendlyErrorSlotContextTest` explicitly scans OTHER components to tell an
 * author "that slot belongs to grid, not to this band". A root holding only the fixture
 * would make those assertions pass for the wrong reason.
 *
 * WHY SYMLINKS RATHER THAN COPIES: a copy goes stale the moment a component's schema
 * changes, and a stale copy is the worst possible fixture - it passes while the real
 * schema has moved. Symlinks cannot drift. Where the filesystem refuses a symlink the
 * helper falls back to a recursive copy and says so, because a silently-degraded fixture
 * is the failure this whole file exists to end.
 *
 * DELETION: this file dies with `ppfixture` when grid is rebuilt. See
 * tests/fixtures/components/ppfixture/README.md.
 */

namespace PromptingPress\Tests\Support;

final class FixtureTheme
{
    /** Built once per PHP process; hundreds of setUp calls must not rebuild it. */
    private static ?string $root = null;

    /** What get_template_directory() returned before the most recent activate(). */
    private static array $stack = [];

    /** The component this fixture adds. Named once, so no suite hardcodes the string. */
    public const COMPONENT = 'ppfixture';

    /**
     * The fixture theme root, built on first use.
     *
     * Built under the system temp dir rather than inside the repo: the repo's own
     * components/ directory is what the registry reads in production, and a nested
     * fixture root inside it would be discovered by any tool that globs components/*.
     */
    /**
     * Remove the fixture root at process end. Symlinks are UNLINKED, never followed —
     * the tree is mostly symlinks into the real repo, and a recursive delete that
     * followed them would delete the theme.
     */
    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);
                continue;
            }
            self::removeDir($path);
        }
        @rmdir($dir);
    }

    public static function root(): string
    {
        if (self::$root !== null && is_dir(self::$root)) {
            return self::$root;
        }

        $repo = dirname(__DIR__, 2);

        // A PER-RUN PATH, NOT A PER-PID ONE, and cleaned up when the process ends.
        //
        // This was `'/pp-fixture-theme-' . getmypid()` and never removed, which is a trap
        // on a machine that recycles pids (a CI box running many short jobs is exactly
        // that). A surviving directory is ADOPTED WHOLESALE by the `file_exists($dst)`
        // skip below, so a component DELETED from the repo since the last run would still
        // be registered — a stale roster silently reintroduced into a suite whose whole
        // job is to iterate the registry. Worse, a DANGLING symlink there reads as
        // `file_exists() === false`, so the skip does not fire, `symlink()` then fails
        // because the path exists, and the copyDir() fallback fails on mkdir() — a
        // RuntimeException with a confusing message, from a directory nobody remembers.
        //
        // `uniqid()` makes collision a non-question, and the shutdown hook means the
        // temp directory does not accumulate one tree per run either. Registered once,
        // guarded by a flag, because root() is called from every activate().
        $root       = sys_get_temp_dir() . '/pp-fixture-theme-' . getmypid() . '-' . uniqid();
        $components = $root . '/components';

        // REGISTERED PER ROOT, NOT ONCE. A single guarded registration leaks on re-entry:
        // if `self::$root` is set but its directory has gone (a stray cleanup, a test that
        // removes it), root() builds a SECOND tree with a fresh uniqid while the flag is
        // already true, so nothing ever removes the second one. Found by the second-pass
        // review. register_shutdown_function() takes as many callbacks as it is given.
        register_shutdown_function(static function () use ($root): void {
            self::removeDir($root);
        });

        if (!is_dir($components) && !mkdir($components, 0777, true) && !is_dir($components)) {
            throw new \RuntimeException("FixtureTheme: could not create {$components}");
        }

        // Every REAL component, by reference so it can never drift from the shipped schema.
        foreach (scandir($repo . '/components') as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $src = $repo . '/components/' . $name;
            if (!is_dir($src)) {
                continue;
            }
            $dst = $components . '/' . $name;
            if (file_exists($dst)) {
                continue;
            }
            if (!@symlink($src, $dst)) {
                self::copyDir($src, $dst);
            }
        }

        // EVERYTHING ELSE THE THEME ROOT IS READ FOR, not just components/. The root is
        // what get_template_directory() returns, and several readers resolve paths off it:
        // pp_design_tokens() parses `assets/css/base.css` (its `:root` block IS the token
        // registry), and the render path reads templates/. A root holding only components/
        // makes those readers answer EMPTY, which surfaces as "design tokens are missing"
        // rather than as "the fixture root is incomplete" — measured, on
        // ActionsTest::testPpDesignTokensReturnsTokens.
        foreach (['assets', 'templates', 'lib', 'style.css', 'functions.php'] as $entry) {
            $src = $repo . '/' . $entry;
            $dst = $root . '/' . $entry;
            if (!file_exists($src) || file_exists($dst)) {
                continue;
            }
            if (!@symlink($src, $dst)) {
                if (is_dir($src)) {
                    self::copyDir($src, $dst);
                } else {
                    copy($src, $dst);
                }
            }
        }

        // …plus the fixture itself, which lives in the repo so it is reviewable.
        $fixtureSrc = $repo . '/tests/fixtures/components/' . self::COMPONENT;
        $fixtureDst = $components . '/' . self::COMPONENT;
        if (!file_exists($fixtureDst) && !@symlink($fixtureSrc, $fixtureDst)) {
            self::copyDir($fixtureSrc, $fixtureDst);
        }

        self::$root = $root;
        return $root;
    }

    /**
     * Point the registry at the fixture root. Opt-in, per suite, in setUp().
     *
     * Invalidates BOTH caches: the component registry keys by root but still needs the
     * buster because the previous root's scan is memoised, and the design-token cache
     * reads base.css from the same root.
     */
    public static function activate(): void
    {
        self::$stack[] = $GLOBALS['_pp_test_template_dir'] ?? null;
        $GLOBALS['_pp_test_template_dir'] = self::root();
        self::invalidate();
    }

    /** Drop back to whatever root was in force before. Always call in tearDown(). */
    public static function deactivate(): void
    {
        $previous = array_pop(self::$stack);
        if ($previous === null) {
            unset($GLOBALS['_pp_test_template_dir']);
        } else {
            $GLOBALS['_pp_test_template_dir'] = $previous;
        }
        self::invalidate();
    }

    /** Both caches, in one place, so a suite cannot invalidate half of them. */
    public static function invalidate(): void
    {
        $GLOBALS['_pp_registered_components_invalidate'] = true;
        if (function_exists('pp_invalidate_design_tokens_cache')) {
            pp_invalidate_design_tokens_cache();
        }
    }

    /** A minimal composition band on the fixture, so suites stop hand-rolling one. */
    public static function band(array $props = [], array $style = []): array
    {
        $band = [
            'component' => self::COMPONENT,
            'props'     => $props + ['items' => [['number' => '10', 'label' => 'Ten']]],
        ];
        if ($style !== []) {
            $band['style'] = $style;
        }
        return $band;
    }

    private static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst) && !mkdir($dst, 0777, true) && !is_dir($dst)) {
            throw new \RuntimeException("FixtureTheme: could not create {$dst}");
        }
        foreach (scandir($src) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $src . '/' . $entry;
            $to   = $dst . '/' . $entry;
            if (is_dir($from)) {
                self::copyDir($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }
}
