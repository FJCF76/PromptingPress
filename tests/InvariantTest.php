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
        $cssFile = $this->themeRoot . '/assets/css/components.css';
        $this->assertFileExists($cssFile, 'components.css not found.');

        $content = file_get_contents($cssFile);
        $lines   = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            // Skip comment lines
            if (str_contains(ltrim($line), '*') || str_contains(ltrim($line), '//')) {
                continue;
            }

            // Match hex colors that are not part of a longer hex sequence
            if (preg_match('/#[0-9a-fA-F]{3,6}(?![0-9a-fA-F])/', $line, $matches)) {
                $this->fail(
                    "Raw hex color '{$matches[0]}' found in components.css on line " . ($lineNum + 1) . ": {$line}\n" .
                    "Use CSS variables from base.css instead."
                );
            }
        }

        $this->assertTrue(true, 'No raw hex colors found in components.css.');
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
