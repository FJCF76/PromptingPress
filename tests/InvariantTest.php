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

    // ── Helpers ───────────────────────────────────────────────────────────

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
    private function extractRunBlock(string $workflowPath, string $stepNamePrefix): string
    {
        $lines = preg_split('/\R/', (string) file_get_contents($workflowPath)) ?: [];

        $start = null;
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
            "{$workflowPath} must keep a step named '{$stepNamePrefix}' (#700)."
        );

        // Walk to the end of this step: the next list item at the same indent.
        $body = [];
        for ($i = $start + 1; $i < count($lines); $i++) {
            if (preg_match('/^' . preg_quote($indent, '/') . '- /', $lines[$i])) {
                break;
            }
            $body[] = $lines[$i];
        }

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
            "The '{$stepNamePrefix}' step must keep a `run: |` block (#700)."
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
}
