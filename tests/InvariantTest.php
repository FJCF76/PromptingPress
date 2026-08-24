<?php
/**
 * tests/InvariantTest.php
 *
 * Grep-based tests that enforce structural invariants across the theme.
 * These tests do not execute PHP — they scan file contents.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class InvariantTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        $this->themeRoot = dirname(__DIR__);
    }

    // ── No raw WP functions in templates/ ─────────────────────────────────

    /**
     * @dataProvider rawWpFunctionProvider
     */
    public function testNoRawWpFunctionsInTemplates(string $function): void
    {
        $files = $this->phpFilesIn($this->themeRoot . '/templates');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringNotContainsString(
                $function . '(',
                $content,
                "Raw WP function '{$function}' found in template: {$file}. Use pp_* wrappers from lib/wp.php."
            );
        }
    }

    /**
     * @dataProvider rawWpFunctionProvider
     */
    public function testNoRawWpFunctionsInComponents(string $function): void
    {
        $files = $this->phpFilesIn($this->themeRoot . '/components');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringNotContainsString(
                $function . '(',
                $content,
                "Raw WP function '{$function}' found in component: {$file}. Use pp_* wrappers from lib/wp.php."
            );
        }
    }

    public function rawWpFunctionProvider(): array
    {
        return [
            ['get_field'],
            ['wp_nav_menu'],
            ['get_bloginfo'],
            ['get_the_title'],
            ['get_the_content'],
            ['home_url'],
            ['get_permalink'],
            ['WP_Query'],
        ];
    }

    // ── All components have schema.json ───────────────────────────────────

    public function testAllComponentsHaveSchemaJson(): void
    {
        $componentDirs = glob($this->themeRoot . '/components/*', GLOB_ONLYDIR);
        $this->assertNotEmpty($componentDirs, 'No component directories found.');

        foreach ($componentDirs as $dir) {
            $schemaFile = $dir . '/schema.json';
            $this->assertFileExists(
                $schemaFile,
                "Missing schema.json in component: {$dir}"
            );
        }
    }

    // ── All schema.json files are valid JSON ──────────────────────────────

    public function testSchemaJsonFilesAreValidJson(): void
    {
        $schemaFiles = glob($this->themeRoot . '/components/*/schema.json');
        $this->assertNotEmpty($schemaFiles, 'No schema.json files found.');

        foreach ($schemaFiles as $file) {
            $content = file_get_contents($file);
            $decoded = json_decode($content, true);

            $this->assertNotNull(
                $decoded,
                "Invalid JSON in {$file}: " . json_last_error_msg()
            );

            // Check required keys
            $this->assertArrayHasKey('component', $decoded, "Missing 'component' key in {$file}");
            $this->assertArrayHasKey('description', $decoded, "Missing 'description' key in {$file}");
            $this->assertArrayHasKey('props', $decoded, "Missing 'props' key in {$file}");
        }
    }

    // ── No raw hex in components.css ──────────────────────────────────────

    public function testNoRawHexInComponentsCss(): void
    {
        // Use the shared guard so this local test and the CI `ai-ready` workflow
        // run the IDENTICAL detection logic (issue #289). The shared guard
        // strips `/* ... */` comments before matching, so an issue reference in
        // a comment (e.g. `(#226)`) passes while a real hex still fails.
        require_once $this->themeRoot . '/scripts/check-raw-hex.php';

        $cssFile = $this->themeRoot . '/assets/css/components.css';
        $this->assertFileExists($cssFile, 'components.css not found.');

        $hits = \pp_find_raw_hex((string) file_get_contents($cssFile));

        $message = "Raw hex colors found in components.css (use CSS variables from base.css):\n";
        foreach ($hits as $hit) {
            $message .= "  line {$hit['line']}: '{$hit['match']}' — " . trim($hit['text']) . "\n";
        }

        $this->assertSame([], $hits, $message);
    }

    // ── All components have README.md ─────────────────────────────────────

    public function testAllComponentsHaveReadme(): void
    {
        $componentDirs = glob($this->themeRoot . '/components/*', GLOB_ONLYDIR);
        $this->assertNotEmpty($componentDirs, 'No component directories found.');

        foreach ($componentDirs as $dir) {
            $readmeFile = $dir . '/README.md';
            $this->assertFileExists(
                $readmeFile,
                "Missing README.md in component: {$dir}"
            );
        }
    }

    // ── AI_RULES.md and AI_CONTEXT.md exist ────────────────────────────────

    public function testAiRulesMdExists(): void
    {
        $this->assertFileExists(
            $this->themeRoot . '/AI_RULES.md',
            'AI_RULES.md is missing from theme root.'
        );
    }

    public function testAiContextMdExists(): void
    {
        $this->assertFileExists(
            $this->themeRoot . '/AI_CONTEXT.md',
            'AI_CONTEXT.md is missing from theme root.'
        );
    }

    // ── WP-CLI docblock synopsis constraint ───────────────────────────────

    /**
     * WP-CLI folds a second consecutive "* : " line into the generated
     * synopsis and warns "invalid synopsis part: <word>" on every run —
     * each OPTIONS description must stay on ONE ": " line (plain "*   "
     * indentation is fine for continuations). Regression guard for the
     * v0.16.48 `operate patch --run-id` fix.
     */
    public function testCliOptionDescriptionsNeverContinueOnASecondColonLine(): void
    {
        $lines = file($this->themeRoot . '/lib/cli.php');
        $this->assertNotFalse($lines);

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*\* : /', $line) && preg_match('/^\s*\* : /', $lines[$i - 1] ?? '')) {
                $this->fail(sprintf(
                    'lib/cli.php:%d continues an option description on a second ": " line — '
                    . 'WP-CLI folds it into the synopsis as bogus parts. Keep each description '
                    . 'on one ": " line, or use plain "*   " indentation.',
                    $i + 1
                ));
            }
        }
        $this->addToAssertionCount(1);
    }

    // ── #685: --post_id is the only CLI page address ──────────────────────

    /**
     * Slug/URL resolution is gone from the CLI addressing path (#685).
     *
     * `--post_id` accepting a slug would be a dishonest name (the same principle
     * as the `*_id`-never-`*_url` naming rule), so the fork that ran a non-numeric
     * page argument through `url_to_postid(home_url($page))` was removed with the
     * positional form, not kept behind the flag. This pins the removal at the
     * source: lib/cli.php had no other caller of either function, so a reappearance
     * is a reappearance of page-address resolution.
     */
    public function testCliAddressingPathHasNoSlugOrUrlResolution(): void
    {
        $source = file_get_contents($this->themeRoot . '/lib/cli.php');
        $this->assertNotFalse($source);

        foreach (['url_to_postid(', 'home_url('] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                'lib/cli.php calls ' . $needle . ') — #685 removed slug/URL page resolution '
                . 'from the CLI addressing path. Pages are addressed by --post_id=<id> only.'
            );
        }
    }

    /**
     * The three page-addressed `operate` subcommands declare no positional
     * argument in their WP-CLI synopsis (#685). A `<page>` back in an OPTIONS
     * block would re-document the removed shape in `wp help` output and let
     * WP-CLI's synopsis validator accept it again.
     */
    public function testPageAddressedOperateCommandsDeclareNoPositionalArgument(): void
    {
        $source = file_get_contents($this->themeRoot . '/lib/cli.php');
        $this->assertNotFalse($source);

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\* <page>/m',
            $source,
            'lib/cli.php declares a <page> positional in a WP-CLI synopsis — '
            . '#685 made --post_id=<id> the canonical page address.'
        );
    }

    /**
     * Horizontal whitespace only. `\s` would span newlines and pair a docblock
     * line ending in `wp pp operate patch` with the leading `*` of the NEXT
     * line, failing the invariant on prose that is perfectly fine. The markdown
     * twin in tests/js/docs-lint.test.js carries the same `[ \t]+` for the same
     * reason — the two halves of one guard must not disagree.
     */
    private const POSITIONAL_PAGE_ARG_PATTERN =
        '/wp pp operate (?:inspect-composition|patch|composition-history)[ \t]+(?!--)\S/';

    /**
     * The PHP guard is not decoration: if the pattern is ever loosened into a
     * no-op, the assertions above pass vacuously. Mirrors the JS twin's
     * must-catch / must-not-catch self-check.
     */
    public function testPositionalPageArgPatternIsNotDecoration(): void
    {
        foreach ([
            'wp pp operate inspect-composition 74',
            'wp pp operate patch 19 --target=hero.subheading',
            'wp pp operate composition-history about-us',
            'wp pp operate inspect-composition <page>',
        ] as $mustCatch) {
            $this->assertMatchesRegularExpression(self::POSITIONAL_PAGE_ARG_PATTERN, $mustCatch);
        }

        foreach ([
            'wp pp operate inspect-composition --post_id=74',
            'wp pp operate patch --post_id=19 --target=hero.subheading',
            'wp pp operate composition-history --post_id=<id>',
            'wp pp operate inspect 42',
            "     *     wp pp operate patch\n     * : next docblock line",
        ] as $mustNotCatch) {
            $this->assertDoesNotMatchRegularExpression(self::POSITIONAL_PAGE_ARG_PATTERN, $mustNotCatch);
        }
    }

    /**
     * PHP self-documentation (docblock EXAMPLES and the action-registry
     * descriptions an agent reads) must never show the removed positional form.
     * The markdown half of this guard lives in tests/js/docs-lint.test.js.
     */
    public function testPhpSelfDocumentationUsesTheFlagFormOnly(): void
    {
        $sources = $this->agentFacingPhpSources();

        // Discovery must not be vacuous: an empty or broken glob would make every
        // assertion below pass without reading anything.
        $this->assertContains($this->themeRoot . '/lib/cli.php', $sources);
        $this->assertContains($this->themeRoot . '/lib/actions.php', $sources);
        $this->assertGreaterThan(5, count($sources), 'PHP self-documentation discovery found almost nothing');

        foreach ($sources as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source);

            $this->assertDoesNotMatchRegularExpression(
                self::POSITIONAL_PAGE_ARG_PATTERN,
                $source,
                $path . ' documents a positional page argument on a page-addressed '
                . 'operate command — #685 made --post_id=<id> canonical.'
            );
        }
    }

    /**
     * Discovered, never hand-listed — the same reason the markdown twin in
     * tests/js/docs-lint.test.js globs: a two-file allowlist lets a positional
     * example reappear in lib/operate.php, lib/ai-context.php (which composes the
     * chat's system prompt), or a template and never meet this guard.
     *
     * @return string[]
     */
    private function agentFacingPhpSources(): array
    {
        $paths = array_merge(
            glob($this->themeRoot . '/lib/*.php') ?: [],
            glob($this->themeRoot . '/templates/*.php') ?: []
        );
        sort($paths);
        return $paths;
    }

    // ── Operating-loop playbooks must source the run token ────────────────

    /**
     * Every operating-loop doc that instructs the agent to run
     * `wp pp apply preflight --run-id=<uuid>` must also tell the agent WHERE
     * that run token comes from: `wp pp operate inspect`. Without this, an
     * agent reads `<uuid>` as "any UUID", generates one, and it fails at
     * PREFLIGHT/EDIT because a self-minted token records no run state
     * (issue 228). This pins the doc fix so the sourcing sentence cannot be
     * dropped again.
     *
     * The provider auto-discovers every `ai-instructions/*.md` file rather
     * than a hard-coded allowlist, so a NEW playbook that adds the
     * `--run-id` instruction without the sourcing note is caught
     * automatically — the invariant covers "every operating-loop doc", not
     * just the four that existed when it was written.
     *
     * @dataProvider aiInstructionDocProvider
     */
    public function testLoopPlaybookSourcesRunIdFromInspect(string $relPath): void
    {
        $path = $this->themeRoot . '/' . $relPath;
        $this->assertFileExists($path, "Missing ai-instructions doc: {$relPath}");

        $content = file_get_contents($path);
        $this->assertNotFalse($content);

        // Only docs that actually instruct a `--run-id` preflight need the
        // sourcing statement. If a doc never pairs `apply preflight` with
        // `--run-id`, it is not in the failure class — skip it.
        if (!preg_match('/apply preflight[^\n]*--run-id/i', $content)) {
            $this->addToAssertionCount(1);
            return;
        }

        // The run-token source (`wp pp operate inspect`) must be named in the
        // same sentence as a run-id / run-token mention — not merely present
        // somewhere unrelated in the file. `[^.]` keeps the match inside one
        // sentence; the `s` flag lets a sentence span wrapped lines. Tolerant
        // of `run_id`, `run-id`, `run id`, and "run token", and of the inline
        // `operate inspect -> apply preflight --run-id` sequence form.
        $sourced = preg_match(
            '/(run[-_ ]?id|run token)[^.]{0,220}operate inspect'
            . '|operate inspect[^.]{0,220}(run[-_ ]?id|run token)/is',
            $content
        );

        $this->assertSame(
            1,
            $sourced,
            "{$relPath} instructs `apply preflight --run-id` but never states that "
            . 'the run token comes from `wp pp operate inspect`. An agent will read '
            . '`<uuid>` as "any UUID" and fail at PREFLIGHT (issue 228). Add a '
            . 'sourcing sentence tying `run_id` to `wp pp operate inspect`.'
        );
    }

    public static function aiInstructionDocProvider(): array
    {
        $dir   = dirname(__DIR__) . '/ai-instructions';
        $files = glob($dir . '/*.md') ?: [];
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = ['ai-instructions/' . basename($file)];
        }

        // Fail loudly if discovery finds nothing — an empty data provider
        // would make this invariant silently vacuous.
        if ($cases === []) {
            $cases['__none_found__'] = ['ai-instructions/__none_found__'];
        }

        return $cases;
    }

    // ── #700: composer.lock is tracked, so CI installs from the lock ──────

    /**
     * CI ran `composer install` with NO lock file, so every run re-resolved the
     * whole dependency graph and re-downloaded every zipball from codeload. A
     * transient 429/504 there took main red with ZERO tests executed — twice in
     * two days (Composer 504 on 2026-08-16, codeload 429 on 2026-08-17), each
     * time with nothing in the run to indicate the merged code was fine.
     *
     * The fix is that `composer.lock` is tracked and `.gitignore` no longer
     * ignores it. This pins both halves, and the ORDER matters: git ignore
     * rules do not affect an already-tracked file, so the regression that
     * actually restores lockless installs is `git rm --cached composer.lock`
     * (which leaves the file on disk). The tracking assertion is therefore the
     * load-bearing one; the ignore assertion stops the rule creeping back in
     * and un-tracking the file on some later fresh checkout.
     *
     * Determinism is all this buys on its own. The network resilience lives in
     * the Actions cache in .github/workflows/ai-ready-check.yml.
     */
    public function testComposerLockIsCommittedAndNotIgnored(): void
    {
        $lockPath = $this->themeRoot . '/composer.lock';

        // Ask GIT, not the filesystem. `assertFileExists()` here would be
        // vacuous: `composer install` WRITES a composer.lock when none exists,
        // and CI runs `composer install` before `composer test`, so the file is
        // always on disk by the time this test runs — the exact regression
        // ("the lock is not committed") could never turn the suite red.
        if (is_dir($this->themeRoot . '/.git')) {
            $root = escapeshellarg($this->themeRoot);

            exec("git -C {$root} ls-files --error-unmatch composer.lock 2>&1", $out, $trackedStatus);
            $this->assertSame(
                0,
                $trackedStatus,
                'composer.lock must be TRACKED by git so CI installs from the lock instead of '
                . 're-resolving dependencies over the network on every run (#700).'
            );

            // --no-index is load-bearing, not a flourish. `git check-ignore`
            // consults the INDEX by default, and git never treats a TRACKED
            // path as ignored — so without this flag the command returns
            // "not ignored" for composer.lock no matter what .gitignore says,
            // and this assertion could never go red. Verified: in a repo with
            // composer.lock both force-added and listed in .gitignore, plain
            // check-ignore exits 1 while --no-index exits 0.
            //
            // With the flag it is the authoritative check: it evaluates every
            // glob shape against every ignore source (.gitignore, a nested
            // .gitignore, .git/info/exclude, core.excludesFile), which no
            // rule-string comparison can reach.
            // Exit codes: 0 = a rule matches, 1 = no rule matches.
            exec("git -C {$root} check-ignore -q --no-index composer.lock", $ignoreOut, $ignoredStatus);
            $this->assertSame(
                1,
                $ignoredStatus,
                'No git ignore rule may match composer.lock — CI installs from the committed lock (#700).'
            );
        }

        // Positive shape: a real lock, not an empty placeholder that would
        // satisfy a bare existence check while leaving CI resolving from scratch.
        $this->assertFileExists($lockPath, 'composer.lock must be present (#700).');
        $lock = json_decode((string) file_get_contents($lockPath), true);
        $this->assertIsArray($lock, 'composer.lock must be valid JSON.');
        $this->assertArrayHasKey('content-hash', $lock, 'composer.lock must carry a content-hash.');

        $locked = array_column($lock['packages-dev'] ?? [], 'version', 'name');
        $this->assertArrayHasKey(
            'phpunit/phpunit',
            $locked,
            'composer.lock must lock the dev dependencies the test suite runs on.'
        );

        // The maintainer's ratified constraint for #700: no machine-local state
        // in the committed lock. A `config.platform` override would pin CI's
        // resolution to whatever PHP the lock happened to be generated on.
        $this->assertSame(
            [],
            $lock['platform-overrides'] ?? [],
            'composer.lock must not carry platform overrides — that is machine-local state (#700).'
        );

        // Belt-and-braces on the --no-index check-ignore assertion above, which
        // is the authority but reports only "something ignores it". This one
        // names the offending .gitignore rule verbatim — the difference between
        // a one-minute fix and a hunt — and still runs when .git is absent (a
        // packaged export). It matches by GLOB, not by an allowlist of literal
        // strings, so an equivalent rule written a different way
        // ('**/composer.lock', 'compos*.lock', '*.lock') is caught too.
        $gitignore = (string) file_get_contents($this->themeRoot . '/.gitignore');
        foreach (preg_split('/\R/', $gitignore) as $line) {
            $rule = trim($line);
            if ($rule === '' || str_starts_with($rule, '#') || str_starts_with($rule, '!')) {
                continue; // blank, comment, or a negation (which un-ignores)
            }

            // Normalize the rule to the form fnmatch() can test against the
            // bare filename: drop anchoring and directory-recursion prefixes
            // and any trailing slash.
            $pattern = rtrim($rule, '/');
            $pattern = preg_replace('#^(\*\*/|/)+#', '', $pattern) ?? $pattern;

            $this->assertFalse(
                $pattern !== '' && fnmatch($pattern, 'composer.lock'),
                "'.gitignore' must not ignore composer.lock (found rule: '{$rule}'). "
                . 'CI installs from the committed lock (#700).'
            );
        }
    }

    /**
     * #700 path B: the bounded retry around `wp-env start` is the only thing
     * standing between a transient codeload 429 and a red main, and CI is the
     * only place it ever runs. Reverting the step to a bare `npm run env:start`,
     * or fumbling a bound (`-le 3` leaves 90s of dead sleep after the final
     * failure; a misplaced `exit 0` passes the job with wp-env down), would
     * otherwise ship green.
     *
     * This executes the step's ACTUAL shell block from the workflow file
     * against stubbed `npm` and `sleep` binaries, so it pins behaviour rather
     * than text: exit codes and attempt counts for recover-on-2 and exhaust-3.
     */
    public function testWpEnvStartRetryIsBoundedAndFailsClosed(): void
    {
        $script = $this->extractRunBlock(
            $this->themeRoot . '/.github/workflows/e2e.yml',
            'Start wp-env'
        );
        $this->assertStringContainsString(
            'npm run env:start',
            (string) $script,
            'The retry must still invoke the real wp-env start script.'
        );

        $base = sys_get_temp_dir() . '/pp700-' . bin2hex(random_bytes(4));
        mkdir($base . '/bin', 0777, true);

        try {
            // `sleep` collapses so the 30s/60s backoff does not slow the suite.
            file_put_contents($base . '/bin/sleep', "#!/bin/sh\nexit 0\n");
            file_put_contents(
                $base . '/bin/npm',
                "#!/bin/sh\n"
                . "n=$(cat \"\$PP_COUNT\" 2>/dev/null || echo 0); n=\$((n+1)); echo \$n > \"\$PP_COUNT\"\n"
                . "[ \"\$n\" -ge \"\$PP_SUCCEED_ON\" ] && exit 0\nexit 1\n"
            );
            chmod($base . '/bin/sleep', 0755);
            chmod($base . '/bin/npm', 0755);
            file_put_contents($base . '/step.sh', $script);

            // `bash -e` mirrors the GitHub Actions default shell.
            $run = function (int $succeedOn) use ($base): array {
                file_put_contents($base . '/count', '0');
                $env = 'PATH=' . escapeshellarg($base . '/bin') . ':$PATH '
                    . 'PP_COUNT=' . escapeshellarg($base . '/count') . ' '
                    . 'PP_SUCCEED_ON=' . $succeedOn . ' ';
                $out = [];
                exec($env . 'bash -e ' . escapeshellarg($base . '/step.sh') . ' 2>&1', $out, $code);
                return [$code, (int) trim((string) file_get_contents($base . '/count'))];
            };

            [$code, $attempts] = $run(1);
            $this->assertSame(0, $code, 'A first-attempt success must exit 0.');
            $this->assertSame(1, $attempts, 'A first-attempt success must not retry.');

            [$code, $attempts] = $run(2);
            $this->assertSame(0, $code, 'A transient first-attempt failure must be retried and succeed.');
            $this->assertSame(2, $attempts, 'Success on attempt 2 must stop the loop immediately.');

            [$code, $attempts] = $run(99);
            $this->assertSame(1, $code, 'A sustained failure must fail the step, never pass silently.');
            $this->assertSame(3, $attempts, 'The retry must be bounded at exactly 3 attempts.');
        } finally {
            foreach ([$base . '/bin/*', $base . '/*'] as $glob) {
                foreach (glob($glob) ?: [] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
            @rmdir($base . '/bin');
            @rmdir($base);
        }
    }

    // ── The E2E merge gate runs every test (#697) ─────────────────────────

    /**
     * The three ways a spec file can take a test out of the gate from the
     * inside. Shared constants rather than inline literals so that
     * testE2eScanPatternsActuallyMatch below tests the SHIPPED regexes — a
     * fixture that tested its own private copy would prove nothing.
     *
     * `(?:\.\s*\w+\s*)*` allows intermediate members (`test.describe.serial.skip`)
     * and the `\s*` throughout allows the multi-line member form
     * (`test\n  .skip(`), which a per-line scan reads as two innocent lines.
     * Neither pattern requires a trailing `(`, because this repo's own idiom
     * passes the member itself as a value: `(cond ? test.skip : test)(...)`.
     */
    private const E2E_FOCUS_PATTERN =
        '/\btest\s*(?:\.\s*\w+\s*)*(?:\.\s*only\b|\[\s*[\'"]only[\'"]\s*\])/';

    /** `fail` belongs here with `skip`/`fixme`: `test.fail()` lets a failing body report as passed. */
    private const E2E_SKIP_PATTERN =
        '/\btest\s*(?:\.\s*\w+\s*)*(?:\.\s*(?:skip|fixme|fail)\b|\[\s*[\'"](?:skip|fixme|fail)[\'"]\s*\])/';

    /** `const t = test` is an easier alias to write than `import { test as t }`, and blinds both scans. */
    private const E2E_REBIND_PATTERN = '/(?:const|let|var)\s+\w+\s*=\s*test\s*[;\n]/';

    /**
     * #697: a non-@smoke test could be merged permanently red, because the PR
     * gate ran `--grep @smoke` and nothing else ever gated. One such test stayed
     * red for 25 consecutive nightlies across two release gates.
     *
     * The fix is that every trigger runs the whole suite. This test pins that
     * there is no narrowing left ANYWHERE on the path from `pull_request` to
     * Playwright — not in the workflow, not in the npm script, not in the
     * Playwright config. Checking only the workflow would be a half-guard: the
     * filter could simply move into `test:e2e` or into `grep`/`testIgnore` in the
     * config and this test would still pass while the blind spot returned.
     */
    public function testE2eGateRunsTheEntireSuiteOnPullRequests(): void
    {
        $workflowPath = $this->themeRoot . '/.github/workflows/e2e.yml';
        $workflow     = (string) file_get_contents($workflowPath);

        // Non-vacuity guard #1. Everything below asserts "the PR path is not
        // narrowed"; if the `pull_request` trigger were deleted, or quietly
        // qualified, every one of those assertions would still pass while PRs
        // stopped being gated. So pin the trigger block itself, as an ALLOWLIST:
        // `paths-ignore:`, `types:` and `branches-ignore:` each remove whole
        // classes of PR from the gate, and asserting the KEY is present cannot
        // see any of them. The "intentionally NO paths-ignore" note in this
        // workflow's header has been load-bearing since #82 and until now was
        // enforced by nothing.
        $this->assertSame(
            ['branches: [main]'],
            $this->yamlBlockLines($workflow, 'pull_request'),
            "e2e.yml's `pull_request:` trigger must be exactly `branches: [main]`. A "
            . '`paths-ignore:`, `types:` or `branches-ignore:` here silently removes whole '
            . 'classes of pull request from the merge gate (#697).'
        );
        foreach (['pull_request', 'push', 'schedule', 'workflow_dispatch'] as $trigger) {
            $this->assertMatchesRegularExpression(
                '/^  ' . $trigger . ':/m',
                $workflow,
                "e2e.yml must keep the `{$trigger}` trigger. The nightly `schedule` in "
                . 'particular is the only run that fires when no code changed, which is what '
                . 'attributes a new red to environment drift rather than a code change (#697).'
            );
        }

        // Non-vacuity guard #2. Everything below inspects the step named
        // "Run E2E". A second step by that name, or a suite invocation living in
        // some other step, would let the real gate move somewhere this test never
        // looks. `npm run test:e2e` is the ONLY sanctioned invocation, so a bare
        // `playwright test` anywhere in this workflow is by definition a second,
        // unguarded one — note that `npx playwright install` does not match.
        $this->assertSame(
            1,
            preg_match_all('/^\s*- name: Run E2E/m', $workflow),
            'e2e.yml must contain exactly ONE step named "Run E2E" — a second one lets '
            . 'the real invocation move to a step this test does not inspect (#697).'
        );
        $this->assertSame(
            0,
            substr_count($workflow, 'playwright test'),
            'e2e.yml must invoke Playwright only through `npm run test:e2e`. A direct '
            . '`playwright test ...` call is an invocation this guard does not police, and '
            . 'is exactly where a re-added `--grep` would hide (#697).'
        );

        // The step's own KEYS, not just its script. `if:` and `continue-on-error:`
        // are step-level siblings of `run:`, so a guard that reads only the run
        // scalar never sees them — and either one alone restores the #697 hole in
        // full: `if: github.event_name != 'pull_request'` takes the gate off pull
        // requests, and `continue-on-error: true` makes a red suite report green.
        $this->assertSame(
            ['name', 'run'],
            $this->stepKeys($workflowPath, 'Run E2E'),
            'The "Run E2E" step must declare only `name:` and `run:`. An `if:` can take the '
            . 'gate off pull requests and a `continue-on-error:` can make a red suite report '
            . 'green, neither of which touches the command this test reads (#697).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^  e2e:\R(?:\s+\w[\w-]*:.*\R)*?\s+if:/m',
            $workflow,
            'The `e2e` job must not carry a job-level `if:` — it would skip every step, '
            . 'including the gate, while each individual step still looks correct (#697).'
        );

        // ALLOWLIST, not blocklist. Asserting "does not contain --grep" is the
        // weaker test: it passes for `# npm run test:e2e`, for `echo npm run
        // test:e2e`, and for narrowing shapes nobody thought to enumerate
        // (a positional spec path, --last-failed, --only-changed). Pin the exact
        // command instead, so ANY change to how the suite is invoked has to be a
        // deliberate edit to this test.
        $script = $this->extractRunBlock($workflowPath, 'Run E2E');
        $bare   = implode("\n", array_values(array_filter(
            array_map('trim', preg_split('/\R/', $script) ?: []),
            static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#')
        )));
        $this->assertSame(
            'npm run test:e2e',
            $bare,
            "The E2E step must run exactly `npm run test:e2e` and nothing else. Got:\n"
            . "  {$bare}\n"
            . 'If the invocation legitimately needs to change, change it here too — '
            . 'deliberately. Every trigger runs every test (#697).'
        );

        // Same allowlist, one layer down: the npm script itself.
        $pkg = json_decode((string) file_get_contents($this->themeRoot . '/package.json'), true);
        $this->assertIsArray(
            $pkg,
            'package.json must be valid JSON — the E2E gate check reads its scripts (#697).'
        );
        $this->assertSame(
            'playwright test --config tests/e2e/playwright.config.ts',
            (string) ($pkg['scripts']['test:e2e'] ?? ''),
            "package.json's `test:e2e` must run the whole suite through the shared "
            . 'config. A positional spec path or a second config file narrows the gate '
            . 'without any of the flags a blocklist would look for (#697).'
        );

        // And one layer further down: the Playwright config. Comments are stripped
        // first, so that documenting this very rule ("never add grep: here") does
        // not trip the ban and teach the next reader that the guard cries wolf.
        $configPath = $this->themeRoot . '/tests/e2e/playwright.config.ts';
        $config     = (string) file_get_contents($configPath);
        $configCode = (string) preg_replace(
            ['#/\*.*?\*/#s', '#^[ \t]*//.*$#m'],
            '',
            $config
        );

        // testDir and testMatch TOGETHER decide which files exist as far as
        // Playwright is concerned. Pinning only testMatch leaves `testDir: './core'`
        // as a one-word way to drop most of the suite.
        $this->assertMatchesRegularExpression(
            "/testDir:\s*'\.'/",
            $configCode,
            "The e2e project must keep `testDir: '.'`. Narrowing testDir drops whole "
            . 'directories of specs out of the gate without touching the workflow (#697).'
        );
        $this->assertMatchesRegularExpression(
            '/testMatch:\s*\/\\\\\.spec\\\\\.ts\//',
            $configCode,
            'The e2e project must keep the broad `testMatch: /\.spec\.ts/`. A narrowed '
            . 'testMatch would drop whole spec FILES out of the gate without touching '
            . 'the workflow (#697).'
        );
        // Deliberately NOT anchored to the start of a line: project-level
        // narrowing is written inline, e.g. `{ name: 'e2e', grep: /@smoke/ }`,
        // which a `^\s*grep:` anchor would sail straight past.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(grep|grepInvert|testIgnore|shard)\s*:/',
            $configCode,
            'playwright.config.ts must not narrow the suite via grep/grepInvert/'
            . 'testIgnore/shard — that would move the #697 blind spot into the config.'
        );
    }

    /**
     * `.only` is the fastest way to turn a green gate into a lie: it silently
     * drops every OTHER test in the file while still reporting success.
     */
    public function testNoFocusedE2eTests(): void
    {
        $files = $this->e2eSpecFiles();

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                self::E2E_FOCUS_PATTERN,
                (string) file_get_contents($file),
                basename($file) . ' must not focus tests with `.only` — it silently skips '
                . 'every other test in the file while the gate still reports green (#697).'
            );
        }
    }

    /**
     * The two spec scans above and the marker scan below are regexes over
     * source, and a regex that quietly stops matching is a guard that quietly
     * stops guarding. Pin their detection power directly, the same way
     * testPositionalPageArgPatternIsNotDecoration pins its own pattern: a
     * must-match list built from every bypass shape found while writing them,
     * and a must-NOT-match list so the patterns cannot be "fixed" by making
     * them match everything.
     */
    public function testE2eScanPatternsActuallyMatch(): void
    {
        $mustFocus = [
            "test.only('a', () => {});",
            "test\n  .only('a', () => {});",
            "test['only']('a', () => {});",
            "test.describe.only('a', () => {});",
            "(cond ? test.only : test)('a', () => {});",
        ];
        foreach ($mustFocus as $sample) {
            $this->assertMatchesRegularExpression(
                self::E2E_FOCUS_PATTERN,
                $sample,
                "The focus scan must catch: {$sample}"
            );
        }

        $mustSkip = [
            "test.skip('a', () => {});",
            "test.fixme('a', () => {});",
            "test.fail();",
            "test\n  .skip('a', () => {});",
            "test['skip']('a', () => {});",
            "test.describe.skip('a', () => {});",
            "test.describe.serial.skip('a', () => {});",
            "(heading.name === 'cta' ? test.skip : test)('a', () => {});",
        ];
        foreach ($mustSkip as $sample) {
            $this->assertMatchesRegularExpression(
                self::E2E_SKIP_PATTERN,
                $sample,
                "The skip scan must catch: {$sample}"
            );
        }

        // Must NOT match. A pattern that flags these would produce false CI reds,
        // and a guard that cries wolf is the one that gets weakened.
        foreach (["testOnly('a');", "// nothing here", "expect(x).toBe(only);", "test.step('a');"] as $sample) {
            $this->assertDoesNotMatchRegularExpression(
                self::E2E_FOCUS_PATTERN,
                $sample,
                "The focus scan must NOT flag: {$sample}"
            );
        }
        foreach (["testSkip('a');", "test.step('a');", "test.setTimeout(1);", "skipThis();"] as $sample) {
            $this->assertDoesNotMatchRegularExpression(
                self::E2E_SKIP_PATTERN,
                $sample,
                "The skip scan must NOT flag: {$sample}"
            );
        }

        $this->assertMatchesRegularExpression(
            self::E2E_REBIND_PATTERN,
            "const tt = test;\ntt.only('a', () => {});",
            'The re-binding scan must catch `const tt = test`.'
        );
        $this->assertDoesNotMatchRegularExpression(
            self::E2E_REBIND_PATTERN,
            "const page = testPage;",
            'The re-binding scan must NOT flag an unrelated identifier.'
        );
    }

    /**
     * The ratified #697 invariant: no silent mergeable red tests. Running the
     * whole suite closes the workflow-shaped hole; `test.skip` / `test.fixme` is
     * the remaining way to remove a test from the gate from inside a spec file.
     *
     * Those are not banned — they are made LOUD. Every one must carry a marker:
     *   QUARANTINE(owner): reason   a real exclusion, owned, findable by grep
     *   NOT-APPLICABLE(reason)      a parametrised case that cannot apply
     * The two vocabularies are deliberately distinct so that grepping for
     * QUARANTINE returns only true exclusions (today: zero).
     */
    public function testEveryE2eSkipCarriesAnOwnedMarker(): void
    {
        $files = $this->e2eSpecFiles();

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            // Non-vacuity guard. The scan below keys on the literal name `test`,
            // so an aliased import (`import { test as t }`) would make it blind.
            // Pin that the binding exists under that name, without pinning the
            // ORDER of the named imports — `{ expect, test }` is equally valid
            // and must not fail this.
            $this->assertMatchesRegularExpression(
                '/^import \{[^}]*\btest\b[^}]*\} from \'@playwright\/test\';/m',
                $source,
                basename($file) . ' must import Playwright `test` under that exact name — '
                . 'the skip/quarantine scan below keys on it (#697).'
            );

            // Pinning the IMPORT name is only half of it: `const t = test` re-binds
            // the same object locally and blinds every scan here just as well.
            $this->assertDoesNotMatchRegularExpression(
                self::E2E_REBIND_PATTERN,
                $source,
                basename($file) . ' must not re-bind Playwright `test` to another name — '
                . 'the focus and skip scans key on `test` and an alias makes them blind (#697).'
            );

            $lines = preg_split('/\R/', $source) ?: [];

            preg_match_all(self::E2E_SKIP_PATTERN, $source, $hits, PREG_OFFSET_CAPTURE);

            foreach ($hits[0] as $hit) {
                $lineNo = substr_count(substr($source, 0, (int) $hit[1]), "\n");

                // The marker may sit ON the skip line (trailing comment) or in the
                // comment block just above it.
                $window = implode("\n", array_slice($lines, max(0, $lineNo - 8), min($lineNo, 8) + 1));

                $owned  = preg_match('/QUARANTINE\([^)\s][^)]*\)\s*:\s*\S/', $window) === 1;
                $naught = preg_match('/NOT-APPLICABLE\([^)]{10,}\)/', $window) === 1;

                $this->assertTrue(
                    $owned || $naught,
                    sprintf(
                        "%s:%d removes a test from the gate without a marker.\n"
                        . "  %s\n"
                        . 'Add `QUARANTINE(owner): reason` for a real exclusion, or '
                        . '`NOT-APPLICABLE(reason)` for a case that cannot apply. '
                        . 'No silent mergeable red tests (#697).',
                        basename($file),
                        $lineNo + 1,
                        trim($lines[$lineNo] ?? '')
                    )
                );
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * The trimmed, non-empty lines nested under a two-space-indented mapping key
     * (e.g. `pull_request`) in the `on:` block. Returns the block's CONTENT so a
     * caller can assert the whole thing as an allowlist, which is what catches a
     * qualifier nobody thought to ban.
     */
    private function yamlBlockLines(string $workflow, string $key): array
    {
        $lines = preg_split('/\R/', $workflow) ?: [];
        $out   = [];
        $in    = false;

        foreach ($lines as $line) {
            if (preg_match('/^  ' . preg_quote($key, '/') . ':\s*$/', $line)) {
                $in = true;
                continue;
            }
            if ($in) {
                // Any line at two-space indent or shallower ends this block.
                if (trim($line) === '') {
                    continue;
                }
                if (!preg_match('/^   /', $line)) {
                    break;
                }
                $out[] = trim($line);
            }
        }

        return $out;
    }

    /**
     * The KEY NAMES a named workflow step declares, in order (e.g. ['name','run']).
     * Step-level `if:` and `continue-on-error:` are siblings of `run:`, invisible
     * to any check that reads only the run scalar, and either one alone is enough
     * to un-gate or de-fang the step (#697).
     */
    private function stepKeys(string $workflowPath, string $stepNamePrefix): array
    {
        [$indent, $body] = $this->stepBody($workflowPath, $stepNamePrefix);
        $keys            = ['name'];

        foreach ($body as $line) {
            // Only keys at the step's own key indent (two past the `- `).
            if (preg_match('/^' . preg_quote($indent, '/') . '  (\w[\w-]*):/', $line, $m)) {
                $keys[] = $m[1];
            }
        }

        return $keys;
    }

    /**
     * Every Playwright spec under tests/e2e/, walked RECURSIVELY because that is
     * how Playwright itself discovers them: `testDir: '.'` with a regex
     * `testMatch` matches at any depth. A top-level `glob()` would run a spec in
     * tests/e2e/sub/ through the gate while leaving it invisible to the focus and
     * marker guards — reopening #697 exactly one directory down.
     *
     * The floor is the other half. Asserting merely "non-empty" cannot notice
     * renaming seven of eight specs to `.spec.skip.ts`, which drops them from
     * Playwright's testMatch and from this walk in the same edit, silently
     * shrinking the guarded set instead of failing.
     */
    private function e2eSpecFiles(): array
    {
        $dir = $this->themeRoot . '/tests/e2e';
        $files = [];

        if (is_dir($dir)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if (str_ends_with($file->getFilename(), '.spec.ts')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        $this->assertGreaterThanOrEqual(
            8,
            count($files),
            'Expected at least 8 tests/e2e/**/*.spec.ts files, found ' . count($files) . '. '
            . 'If specs were deliberately consolidated, lower this floor in the same commit '
            . 'so the shrink is reviewed rather than silent (#697).'
        );

        return $files;
    }

    /**
     * Pull the `run: |` script out of a named workflow step, by line structure
     * rather than by one multiline regex.
     *
     * A regex terminating on the next `- name:` silently OVER-CAPTURES: a step
     * may have no `name` at all (e2e.yml's own `- uses: actions/checkout@v4`
     * is one), so a name-less step landing after the target would be swallowed
     * into the returned script and then executed by the caller. That yields a
     * test asserting exit codes for a script it did not mean to run. Terminate
     * on the next sequence item at the SAME indent instead, which is what
     * actually ends a step, and derive the dedent from the block's own
     * indentation so a re-indent (matrix, reusable workflow) does not break it.
     */
    /**
     * Locate a named step and return [its `- ` indent, its body lines]. Shared by
     * extractRunBlock (which wants the script) and stepKeys (which wants the keys
     * the script sits beside), so the step-boundary walk is defined once.
     */
    private function stepBody(string $workflowPath, string $stepNamePrefix): array
    {
        $lines = preg_split('/\R/', (string) file_get_contents($workflowPath)) ?: [];

        $start  = null;
        $indent = '';
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\s*)- name: ' . preg_quote($stepNamePrefix, '/') . '/', $line, $m)) {
                $start  = $i;
                $indent = $m[1];
                break;
            }
        }
        $this->assertNotNull(
            $start,
            "{$workflowPath} must keep a step named '{$stepNamePrefix}' (#700, #697)."
        );

        // Walk to the end of this step: the next list item at the same indent.
        $body = [];
        for ($i = $start + 1; $i < count($lines); $i++) {
            if (preg_match('/^' . preg_quote($indent, '/') . '- /', $lines[$i])) {
                break;
            }
            $body[] = $lines[$i];
        }

        return [$indent, $body];
    }

    private function extractRunBlock(string $workflowPath, string $stepNamePrefix): string
    {
        [, $body] = $this->stepBody($workflowPath, $stepNamePrefix);

        // Find `run: |` among this step's keys, then take its block scalar.
        $runAt = null;
        foreach ($body as $i => $line) {
            if (preg_match('/^\s+run: \|\s*$/', $line)) {
                $runAt = $i;
                break;
            }
        }
        $this->assertNotNull(
            $runAt,
            "The '{$stepNamePrefix}' step must keep a `run: |` block (#700, #697)."
        );

        $block = [];
        for ($i = $runAt + 1; $i < count($body); $i++) {
            if (trim($body[$i]) === '') {
                $block[] = '';
                continue;
            }
            // A line at or left of the `run:` key's indent ends the scalar.
            if (!preg_match('/^\s+/', $body[$i], $ws)
                || strlen($ws[0]) <= strlen((string) preg_replace('/\S.*$/', '', $body[$runAt]))) {
                break;
            }
            $block[] = $body[$i];
        }

        // Dedent by the block's own minimum indentation.
        $min = PHP_INT_MAX;
        foreach ($block as $line) {
            if ($line !== '') {
                preg_match('/^ */', $line, $ws);
                $min = min($min, strlen($ws[0]));
            }
        }
        $min = $min === PHP_INT_MAX ? 0 : $min;

        $script = implode("\n", array_map(
            static fn (string $l): string => $l === '' ? '' : substr($l, $min),
            $block
        ));

        // Over-capture tripwire: if the walk ever swallows a neighbouring step,
        // fail loudly here instead of silently executing it.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*- (name|uses):/m',
            $script,
            "Extracted more than the '{$stepNamePrefix}' run block — the step walk over-captured (#700)."
        );

        return $script;
    }

    private function phpFilesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files  = [];
        $iter   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iter as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    // ── #705: every component read of background_image is guarded ─────────

    /**
     * A SOURCE-LEVEL DRIFT CATCHER for the stored-shape guard family.
     *
     * #705 guards `background_image` at three component read sites so a stored non-scalar
     * degrades instead of fataling the public page through the typed pp_esc_image_src().
     * Those three guards are hand-copied lines, and every behavioural test for them is
     * per-component: nothing fails if a FOURTH component starts reading the prop raw, or
     * if one of the three loses its guard in a refactor. The completeness argument would
     * then live only in prose, in a family that already runs to #641/#705/#706/#708/#730
     * and #733 — so the next unguarded read is a matter of when.
     *
     * This converts that prose into a failing test, deliberately narrow: it asserts only
     * that a component reading `$props['background_image']` assigns it through an
     * is_scalar guard. It does not police OTHER props (their issues own that) and it does
     * not care which components exist, so adding a new guarded background band passes
     * without touching this test.
     */
    public function testEveryComponentReadOfBackgroundImageIsScalarGuarded(): void
    {
        $readers = [];

        foreach ($this->phpFilesIn($this->themeRoot . '/components') as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, "\$props['background_image']")) {
                continue;
            }
            $readers[] = basename(dirname($file));

            // The prop is read only into the raw local...
            $this->assertMatchesRegularExpression(
                '/\$raw_background_image\s*=\s*\$props\[\'background_image\'\]/',
                $content,
                basename($file) . ' reads background_image into something other than'
                . ' $raw_background_image (#705). The guard idiom expects the raw read to land'
                . ' in that local so the guarded value is the one every gate below sees.'
            );

            // ...and the value the template actually uses comes from the guard.
            $this->assertMatchesRegularExpression(
                '/\$background_image\s*=\s*is_scalar\(\$raw_background_image\)\s*\?\s*\(string\)\s*\$raw_background_image\s*:\s*\'\'/',
                $content,
                basename($file) . ' reads background_image but does not assign it through the'
                . ' is_scalar guard (#705). A raw read reaches the typed pp_esc_image_src()'
                . ' and a stored array 500s the whole public page.'
            );

            // Exactly one read of the prop, so a second raw read cannot hide below the guard.
            $this->assertSame(
                1,
                substr_count($content, "\$props['background_image']"),
                basename($file) . ' reads background_image more than once (#705). Every gate must'
                . ' read the guarded local, not the raw prop.'
            );

            // The raw value must not reach the escaper directly, guard or no guard.
            $this->assertStringNotContainsString(
                'pp_esc_image_src($raw_background_image',
                $content,
                basename($file) . ' passes the RAW background_image to pp_esc_image_src() (#705).'
            );
        }

        // Non-vacuity: if this ever finds nothing, the regex above is silently passing.
        sort($readers);
        $this->assertSame(
            ['cta', 'section', 'stats'],
            $readers,
            'the set of components reading background_image changed — a new reader must carry'
            . ' the #705 guard (add it, then update this list)'
        );
    }

    // ── #706: every heading-helper call site uses guarded locals ──────────

    /**
     * The #705 drift catcher's sibling, for `title` / `title_accent`.
     *
     * DELIBERATELY KEYED ON THE CALL, NOT ON THE PROP READ, and that is the difference
     * that matters. #705 could key on `$props['background_image']` because only the
     * three components that reach pp_esc_image_src() read it at all. `title` is not
     * like that: logos, table and embed ALSO read `$props['title']` and pass it to
     * esc_html(), which does not fatal — it renders the literal string `Array` plus an
     * E_WARNING. A checker keyed on the prop read would demand a guard on those three
     * and quietly widen #706 past its ruling. So the admitting criterion here is the
     * one #641 stated for this family: the same TYPED CALL, not the same prop and not
     * the same file. A component that calls pp_render_heading_with_accent() must feed
     * it guarded locals; a component that merely reads a title is none of this test's
     * business.
     *
     * Narrow on purpose in the other direction too: it does not police other props
     * (their issues own those), and it does not care which components exist, so a new
     * guarded heading band passes without touching this test.
     */
    public function testEveryHeadingHelperCallSiteUsesScalarGuardedLocals(): void
    {
        $callers = [];

        foreach ($this->phpFilesIn($this->themeRoot . '/components') as $file) {
            $raw = file_get_contents($file);
            if (!str_contains($raw, 'pp_render_heading_with_accent(')) {
                continue;
            }
            $callers[] = basename(dirname($file));
            $name = basename($file);

            // EVERY assertion below runs against COMMENT-STRIPPED source, not just the
            // call-site scan. The guard blocks this method polices are long prose that
            // quotes the very identifiers being matched, so a regex over raw source can
            // be satisfied by a sentence rather than by code — and the substr_count
            // checks can be spuriously FAILED by one, which is the worse direction: a
            // red test with a misleading message. Stripping via token_get_all() is exact
            // rather than heuristic, and it keeps the checker honest the other way too:
            // a call commented OUT is correctly not a call.
            $content = $this->stripPhpComments($raw);

            // Both props are read only into their raw locals...
            $this->assertMatchesRegularExpression(
                '/\$raw_title\s*=\s*\$props\[\'title\'\]/',
                $content,
                $name . ' reads title into something other than $raw_title (#706). The guard'
                . ' idiom expects the raw read to land in that local so the guarded value is'
                . ' the one every gate below sees.'
            );
            $this->assertMatchesRegularExpression(
                '/\$raw_title_accent\s*=\s*\$props\[\'title_accent\'\]/',
                $content,
                $name . ' reads title_accent into something other than $raw_title_accent (#706).'
            );

            // ...and the values the template uses come from the guards.
            $this->assertMatchesRegularExpression(
                '/\$title\s*=\s*is_scalar\(\$raw_title\)\s*\?\s*\(string\)\s*\$raw_title\s*:\s*\'\'/',
                $content,
                $name . ' calls pp_render_heading_with_accent() but does not assign $title through'
                . ' the is_scalar guard (#706). A raw read reaches `string $title` and a stored'
                . ' array 500s the whole public page.'
            );
            $this->assertMatchesRegularExpression(
                '/\$title_accent\s*=\s*is_scalar\(\$raw_title_accent\)\s*\?\s*\(string\)\s*\$raw_title_accent\s*:\s*\'\'/',
                $content,
                $name . ' calls pp_render_heading_with_accent() but does not assign $title_accent'
                . ' through the is_scalar guard (#706). Argument #2 is typed too, and it fatals'
                . ' on its own even when the title is perfectly good.'
            );

            // The else-branch must be '' and never the component's own `??` default:
            // hero and faq default to non-empty strings, and degrading a corrupt stored
            // title into 'Default Title' would paint invented content onto a live page.
            $this->assertStringNotContainsString(
                ': $raw_title',
                str_replace(
                    ['(string) $raw_title_accent', '(string) $raw_title'],
                    '',
                    $content
                ),
                $name . ' degrades title to something other than the empty string (#706).'
            );

            // Exactly one read of each prop, so a second raw read cannot hide below the guard.
            $this->assertSame(
                1,
                substr_count($content, "\$props['title']"),
                $name . ' reads title more than once (#706). Every gate must read the guarded'
                . ' local, not the raw prop.'
            );
            $this->assertSame(
                1,
                substr_count($content, "\$props['title_accent']"),
                $name . ' reads title_accent more than once (#706).'
            );

            // EVERY call passes the guarded locals, asserted as an ALLOWLIST rather than as
            // a blocklist of known-bad spellings. A "does not contain
            // pp_render_heading_with_accent($raw_title" check is trivially bypassed by an
            // alias ($x = $raw_title; …($x, …)) or by a second call added later with a
            // different variable, and either one reintroduces the production 500 while the
            // guard above still reads correctly. Matching the exact argument pair closes
            // the whole shape: the only accepted spelling is the guarded one.
            preg_match_all('/pp_render_heading_with_accent\(([^)]*)\)/', $content, $calls);
            $this->assertNotEmpty($calls[1], $name . ' matched no heading-helper call (#706 checker drift).');
            foreach ($calls[1] as $args) {
                $this->assertMatchesRegularExpression(
                    '/^\s*\$title\s*,\s*\$title_accent\s*,/',
                    $args,
                    $name . ' calls pp_render_heading_with_accent() with something other than the'
                    . ' guarded ($title, $title_accent) pair (#706): "' . trim($args) . '". Any other'
                    . ' spelling can carry a raw stored value into the typed parameters and 500 the'
                    . ' whole public page.'
                );
            }
        }

        // Non-vacuity: if this ever finds nothing, every assertion above is silently passing.
        sort($callers);
        $this->assertSame(
            ['cta', 'faq', 'grid', 'hero', 'section', 'stats', 'testimonials'],
            $callers,
            'the set of components calling pp_render_heading_with_accent() changed — a new'
            . ' caller must carry the #706 guard (add it, then update this list)'
        );
    }

    // ── #708: every style-vars call site and every count() uses guarded locals ──

    /**
     * The third drift catcher in this family, for the `__pp_style` map into the typed
     * pp_render_style_vars(array $style, ...).
     *
     * KEYED ON THE CALL, like #706's and for the same reason: the admitting criterion this
     * family uses is the same TYPED CALL, not the same prop and not the same file. Keying
     * on `$props['__pp_style']` would be nearly equivalent today, but it would also demand
     * a guard from any future component that merely reads the map for something that
     * cannot fatal, and quietly widen #708 past its ruling.
     *
     * Two argument shapes are legal, and the allowlist below admits exactly those:
     *   - component scope: pp_render_style_vars($style, 'name')       — the #708 guard
     *   - item scope:      pp_render_style_vars($item_style, 'grid', true)
     *                      pp_render_style_vars($row_style, 'section', true)
     * The item-scope locals are guarded at their own reads (`is_array($item['style'] ?? …)`),
     * predate this issue, and never fataled — but they are asserted here rather than
     * exempted, so dropping one of those guards fails this test instead of production.
     */
    public function testEveryStyleVarsCallSiteUsesArrayGuardedLocals(): void
    {
        $callers = [];

        foreach ($this->phpFilesIn($this->themeRoot . '/components') as $file) {
            // Comment-stripped BEFORE the entry gate, not after. The reason
            // stripPhpComments() documents applies to the gate itself: these guard blocks
            // are long prose quoting the very identifiers being matched, so gating on raw
            // source would admit a file that merely NAMES the helper in a comment — and
            // this repo writes exactly such comments — which would then hard-fail every
            // assertion below and corrupt the non-vacuity list. Gate and assertions must
            // see the same input.
            $content = $this->stripPhpComments(file_get_contents($file));
            if (!str_contains($content, 'pp_render_style_vars(')) {
                continue;
            }
            $callers[] = basename(dirname($file));
            $name = basename($file);

            // The component-level map is read only into the raw local...
            $this->assertMatchesRegularExpression(
                '/\$raw_style\s*=\s*\$props\[\'__pp_style\'\]\s*\?\?\s*null/',
                $content,
                $name . ' reads __pp_style into something other than $raw_style (#708). The guard'
                . ' idiom expects the raw read to land in that local so the guarded value is the'
                . ' one every consumer below sees.'
            );

            // ...and the value the template uses comes from the is_array guard.
            $this->assertMatchesRegularExpression(
                '/\$style\s*=\s*is_array\(\$raw_style\)\s*\?\s*\$raw_style\s*:\s*\[\]/',
                $content,
                $name . ' calls pp_render_style_vars() but does not assign $style through the'
                . ' is_array guard (#708). A raw read reaches `array $style` and a stored'
                . ' non-array 500s the whole public page.'
            );

            // Exactly one read of the prop, so a second raw read cannot hide below the
            // guard. grid and section each had TWO before this issue (a second typed call
            // and an offset read); both were folded onto the guarded local.
            // Quote-INSENSITIVE, because a literal substring count is bypassed by the
            // other quote style: $props["__pp_style"] leaves the single-quoted count at
            // exactly 1 and slips a second raw read below the guard — the same alias-class
            // bypass the count()/sizeof() catcher already had to close. Fail-loud in both
            // directions: a file spelling it only with double quotes counts 0 and fails.
            $this->assertSame(
                1,
                preg_match_all('/\$props\[\s*[\'"]__pp_style[\'"]\s*\]/', $content),
                $name . ' reads __pp_style more than once (#708). Every consumer must read the'
                . ' guarded local, not the raw prop.'
            );

            // EVERY call passes a guarded local, asserted as an ALLOWLIST rather than as a
            // blocklist of known-bad spellings. A "does not contain
            // pp_render_style_vars($props[" check is trivially bypassed by an alias
            // ($x = $props['__pp_style']; …($x, …)) or by a second call added later, and
            // either one reintroduces the production 500 while the guard above still reads
            // correctly.
            preg_match_all('/pp_render_style_vars\(([^)]*)\)/', $content, $calls);
            $this->assertNotEmpty($calls[1], $name . ' matched no style-vars call (#708 checker drift).');
            foreach ($calls[1] as $args) {
                $this->assertMatchesRegularExpression(
                    '/^\s*(\$style|\$item_style|\$row_style)\s*,/',
                    $args,
                    $name . ' calls pp_render_style_vars() with something other than a guarded'
                    . ' local (#708): "' . trim($args) . '". Any other spelling can carry a raw'
                    . ' stored value into the typed parameter and 500 the whole public page.'
                );
            }
        }

        // The second typed boundary on the SAME read. It fatals identically and is
        // unreachable today only because the style-vars call above throws first — an
        // ordering accident, not a guarantee.
        $grid = $this->stripPhpComments(file_get_contents($this->themeRoot . '/components/grid/grid.php'));
        preg_match_all('/pp_grid_link_align_decl\(([^)]*)\)/', $grid, $align);
        $this->assertNotEmpty($align[1], 'grid.php matched no pp_grid_link_align_decl call (#708 checker drift).');
        foreach ($align[1] as $args) {
            $this->assertMatchesRegularExpression(
                '/^\s*(\$style|\$item_style)\s*$/',
                $args,
                'grid.php calls pp_grid_link_align_decl() with something other than a guarded'
                . ' local (#708): "' . trim($args) . '". It is typed `array $style` too.'
            );
        }

        // Non-vacuity: if this ever finds nothing, every assertion above is silently passing.
        sort($callers);
        $this->assertSame(
            ['cta', 'embed', 'faq', 'grid', 'hero', 'logos', 'section', 'stats', 'table', 'testimonials'],
            $callers,
            'the set of components calling pp_render_style_vars() changed — a new caller must'
            . ' carry the #708 guard (add it, then update this list)'
        );
    }

    /**
     * The count() half of #708. count() is typed by PHP itself
     * (Countable|array $value), so a stored scalar prop reaching it 500s the page exactly
     * as a theme helper's typed parameter does.
     *
     * Keyed on the CALL again, and deliberately covering ALL of components/ rather than
     * grid alone: grid is the only caller today, and the whole point is to fail when a
     * second component starts counting a stored prop without guarding the read first.
     *
     * MATCHES `sizeof(` TOO. It is PHP's exact alias for count() and fatals identically,
     * so a checker keyed on the `count(` spelling alone is bypassed by one synonym —
     * verified during review by adding a throwaway component calling
     * `sizeof($props['items'] ?? [])`, which passed both new catchers before this pattern
     * was widened. The non-vacuity list below only rescues the case where GRID switches
     * spelling (grid would drop out of the list); a brand-new caller never enters it.
     *
     * NOT a general "every count() in the theme" checker: the allowlist below names the
     * one guarded local that exists today, so any new caller fails loudly and a human
     * decides whether it needs the guard. That is the intended failure mode — a checker
     * that silently accepted new spellings would be the bug.
     *
     * WIDENED BY #739 TO ADMIT pp_render_faq_schema( ALONGSIDE count(). That widening is
     * the payoff of having keyed this catcher on the CALL rather than on the prop, and
     * #708's landing said so in advance: "neither new catcher matches
     * pp_render_faq_schema(", so the gap was VISIBLE rather than silently assumed covered.
     * Both calls take the same `array` contract on the same `items` prop, so they share
     * one guard, one predicate and now one checker. What made faq's instance worse than
     * grid's is not visible at this level: the schema call sits OUTSIDE faq's !empty()
     * gate, so it also fataled on falsy shapes grid survives. That behaviour is pinned in
     * tests/StoredLinkAndRichTextRenderGuardTest.php; this checker's job is only to keep
     * every such call reading the guarded local.
     */
    public function testEveryItemsTypedCallInAComponentTakesAnArrayGuardedLocal(): void
    {
        $callers = [];

        foreach ($this->phpFilesIn($this->themeRoot . '/components') as $file) {
            $content = $this->stripPhpComments(file_get_contents($file));
            preg_match_all(
                '/(?<![a-z_>$])(?:count|sizeof|pp_render_faq_schema)\s*\(([^)]*)\)/i',
                $content,
                $calls
            );
            if ($calls[1] === []) {
                continue;
            }
            $callers[] = basename(dirname($file));
            $name = basename($file);

            foreach ($calls[1] as $args) {
                $this->assertMatchesRegularExpression(
                    '/^\s*\$items\s*$/',
                    $args,
                    $name . ' calls count()/sizeof()/pp_render_faq_schema() with something other'
                    . ' than the guarded $items local (#708/#739): "' . trim($args) . '". If this'
                    . ' is a NEW stored prop, guard it at the read with the is_array idiom and'
                    . ' then widen this allowlist — a raw stored prop reaching any of these typed'
                    . ' boundaries 500s the whole public page. If it is a local that never holds'
                    . ' stored data, widen the allowlist with a note saying so.'
                );
            }

            $this->assertMatchesRegularExpression(
                '/\$raw_items\s*=\s*\$props\[\'items\'\]/',
                $content,
                $name . ' reads items into something other than $raw_items (#708/#739).'
            );
            $this->assertMatchesRegularExpression(
                '/\$items\s*=\s*is_array\(\$raw_items\)\s*\?\s*\$raw_items\s*:\s*\[\]/',
                $content,
                $name . ' reaches a typed items boundary but does not assign $items through the'
                . ' is_array guard (#708/#739). is_array and NOT is_scalar: count() accepts'
                . ' array|Countable and pp_render_faq_schema() declares `array $items`, so every'
                . ' scalar fatals and an array IS the contract at both boundaries.'
            );
            $this->assertSame(
                1,
                substr_count($content, "\$props['items']"),
                $name . ' reads items more than once (#708/#739). Every gate must read the'
                . ' guarded local.'
            );
        }

        sort($callers);
        $this->assertSame(
            ['faq', 'grid'],
            $callers,
            'the set of components reaching a typed items boundary changed — a new caller must'
            . ' guard the stored prop it passes (add the guard, then update this list)'
        );
    }

    // ── #730: every core-escaper call site uses guarded locals ───────────────

    /**
     * The fifth drift catcher in this family, for stored LINK and RICH-TEXT props into
     * WordPress CORE's own escapers.
     *
     * KEYED ON THE CALL, like #706's, #708's and #739's, and for the same reason: the
     * admitting criterion is the same TYPED CALL, not the same prop and not the same file.
     * Keying on prop names would be worse here than anywhere else in the family, because
     * `title` and `body` are read by components that DON'T reach these sinks (they reach
     * esc_html, which coerces instead of fataling — the separate, still-open #736).
     *
     * WHAT MAKES THIS ONE EASY TO GET WRONG. Both functions are UNTYPED in core:
     *   esc_url( $url, $protocols = null, $_context = 'display' )
     *   wp_kses_post( $data )
     * so nothing in either signature signals danger, and a reader checking types alone
     * concludes they are safe. They are not: each reaches a string-only builtin (ltrim,
     * str_contains, preg_replace) before any sanitization decision, and fatals there.
     * Measured on real WP 7.0 / PHP 8.3.31 during #709.
     *
     * KEYED ON THE GUARD, NOT ON THE VARIABLE NAME, and that distinction is the whole
     * strength of this checker. An earlier draft asserted only that each sink's argument
     * appeared in an allowlist of accepted spellings. That is bypassed by the exact case
     * this issue exists to prevent: a NEW component writing
     *
     *     $body = $props['body'] ?? '';        // no guard
     *     ... wp_kses_post($body) ...
     *
     * passes a name-only allowlist, because `$body` is an accepted spelling. It was proven
     * to pass, in a scratch copy, before this was rewritten. So for every non-exempt
     * argument the checker now demands that the SAME FILE actually assigns that local
     * through the is_scalar idiom. The guard is the thing enforced; the name is just how
     * the call site and the assignment are matched to each other.
     *
     * EXEMPTIONS ARE NAMED, never inferred, so each one is a decision someone took.
     */
    public function testEveryCoreEscaperCallInAComponentTakesAGuardedLocal(): void
    {
        // Arguments that are NOT stored-prop-derived, each with its warrant. Anything else
        // reaching a core escaper must carry the guard.
        $exempt = [
            // A theme function, not a prop.
            'pp_site_url()',
            // pp_resolve_logo() sources `url` from wp_get_attachment_image_url(), which
            // returns string|false — never a stored prop value.
            "\$logo['url']",
            // footer's social decode loop skips non-array entries and (string)-casts the
            // url before the escaper sees it.
            "\$social_item['url']",
            // DELIBERATELY UNGUARDED. hero's $proof_markup is `trim((string) $proof)`, so
            // what arrives here is ALREADY a string and this call site cannot fatal. That
            // prop's fatal is upstream at the cast and object-only — a language construct,
            // not a core escaper, so a different boundary. Belongs to the open #721.
            '$proof_markup',
        ];

        $sites = 0;
        foreach ($this->phpFilesIn($this->themeRoot . '/components') as $file) {
            // Comment-stripped BEFORE anything else: the #730 guard blocks are long prose
            // quoting `esc_url()` and `wp_kses_post()` by name, so a raw scan would count
            // sentences as call sites.
            $content = $this->stripPhpComments(file_get_contents($file));
            $name    = basename($file);

            foreach (['esc_url', 'wp_kses_post'] as $sink) {
                // One level of nested parens, so esc_url(pp_site_url()) captures the whole
                // inner call instead of truncating at its first ')'.
                preg_match_all(
                    '/(?<![a-z_>$])' . $sink . '\s*\(\s*([^()]*(?:\([^()]*\)[^()]*)*)\)/i',
                    $content,
                    $calls
                );
                foreach ($calls[1] as $args) {
                    $sites++;
                    $arg = trim($args);
                    if (in_array($arg, $exempt, true)) {
                        continue;
                    }

                    // Only a bare local can be guarded. Anything else (a literal, a
                    // concatenation, an inline call) is an unreviewed shape at a fataling
                    // sink and must be brought into the idiom or exempted by name.
                    $this->assertMatchesRegularExpression(
                        '/^\$[a-z_][a-z0-9_]*$/i',
                        $arg,
                        $name . ' passes "' . $arg . '" into core\'s ' . $sink . '() (#730).'
                        . ' Only a guarded local or a named exemption may reach that sink:'
                        . ' it is UNTYPED but still fatals from the inside on an array or an'
                        . ' object, and templates/composition.php has no try/catch, so a raw'
                        . ' stored value here 500s the WHOLE public page.'
                    );

                    $local = substr($arg, 1);

                    // ASSIGNED EXACTLY ONCE, asserted BEFORE the shape check, because
                    // "the guard exists somewhere in this file" is not the same claim as
                    // "this local is guarded". Proven in a scratch copy: adding
                    //     $body = $props['footnote'] ?? $body;
                    // one line BELOW section's guard reintroduces the exact whole-page
                    // fatal this issue closes (measured: str_contains() TypeError on an
                    // array), and every other check here stays green — the call-site
                    // triple is unchanged and the read-once check keys on `body`, not on
                    // the second prop. A re-assignment after the guard is the same alias
                    // class the guard-keyed rewrite was meant to close, one level down.
                    $this->assertSame(
                        1,
                        preg_match_all('/\$' . $local . '\s*=(?!=)/', $content),
                        $name . ' assigns $' . $local . ' more than once (#730). The value that'
                        . ' reaches ' . $sink . '() must be the one the is_scalar guard produced,'
                        . ' so a later re-assignment — even from another `?? $' . $local . '`'
                        . ' fallback — can carry a raw stored value straight into the sink and'
                        . ' 500 the whole public page.'
                    );

                    $this->assertMatchesRegularExpression(
                        '/\$' . $local . '\s*=\s*is_scalar\(\$raw_' . $local
                        . '\)\s*\?\s*\(string\)\s*\$raw_' . $local . '\s*:\s*\'\'/',
                        $content,
                        $name . ' passes $' . $local . ' into core\'s ' . $sink . '() but never'
                        . ' assigns it through the #730 guard. Add'
                        . ' `$raw_' . $local . ' = $props[\'…\'] ?? \'\';'
                        . ' $' . $local . ' = is_scalar($raw_' . $local . ') ? (string) $raw_'
                        . $local . ' : \'\';` at the READ. is_scalar and NOT is_string: PHP runs'
                        . ' coercive here, so only non-scalars ever fataled and is_string would'
                        . ' silently drop scalars that are already STORED. #707 closed the front'
                        . ' door (a type:"string" prop now rejects a non-string scalar at write)'
                        . ' but migrated nothing, so pre-#707 pages, restores (#233) and raw meta'
                        . ' writes still hold them and they still have to render. NEVER try/catch the'
                        . ' throw instead — it escapes between core\'s'
                        . ' remove_filter(\'pre_kses\') and the matching re-add, de-registering'
                        . ' block KSES for the rest of the request.'
                    );

                    // Where the guarded local comes from a top-level prop, that prop must be
                    // read EXACTLY ONCE, so no gate can read the raw value below the guard.
                    // Quote-INSENSITIVE for the reason the #708 catcher records: a literal
                    // single-quoted count is bypassed by $props["body"], which leaves the
                    // single-quoted count at 1 and slips a second raw read past.
                    if (preg_match('/\$raw_' . $local . '\s*=\s*\$props\[[\'"]([a-z0-9_]+)[\'"]\]/i', $content, $m)) {
                        $prop = $m[1];
                        $this->assertSame(
                            1,
                            substr_count($content, '$props[\'' . $prop . '\']')
                                + substr_count($content, '$props["' . $prop . '"]'),
                            $name . ' reads ' . $prop . ' more than once (#730). Every gate must'
                            . ' read the guarded local, not the raw prop.'
                        );
                    }
                }
            }
        }

        // Non-vacuity, as an EXACT count rather than a floor. A hand-picked lower bound is
        // the weakest link in a scanner: at 13 against a real 19, six guarded sites could
        // be deleted or renamed out of the scan and this would still pass, so the one
        // assertion standing between a silently shrinking scan and a green run would not
        // fire. The real distribution today is table 1, grid 1, footer 3, section 4, nav 2,
        // hero 4, cta 2, faq 1, embed 1.
        $this->assertSame(
            19,
            $sites,
            'the number of component call sites reaching core\'s esc_url() / wp_kses_post()'
            . ' changed (#730). If you ADDED one, it must carry the guard (or be exempted by'
            . ' name with the reason it cannot hold a stored value); if you REMOVED one,'
            . ' update this count. A silently shrinking scan is a checker that has stopped'
            . ' checking.'
        );
    }

    /**
     * Returns $source with every comment token removed, so a source-level checker can tell
     * a real call from a mention in prose. Uses PHP's own tokenizer rather than a regex,
     * because the guard blocks this file polices are long comment blocks that quote the
     * very identifiers being searched for.
     */
    private function stripPhpComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Replaced by its own newlines, not dropped outright, so line numbers
                    // survive the strip. A caller that reports `file.php:N` in a failure
                    // message would otherwise cite a line that drifts further out of true
                    // with every comment above it — and this file polices templates whose
                    // guard blocks run to forty comment lines each.
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
