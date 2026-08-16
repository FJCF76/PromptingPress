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

    // ── Helpers ───────────────────────────────────────────────────────────

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
