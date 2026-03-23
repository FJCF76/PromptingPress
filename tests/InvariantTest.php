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

    // ── CLAUDE.md and AI_CONTEXT.md exist ─────────────────────────────────

    public function testClaudeMdExists(): void
    {
        $this->assertFileExists(
            $this->themeRoot . '/CLAUDE.md',
            'CLAUDE.md is missing from theme root.'
        );
    }

    public function testAiContextMdExists(): void
    {
        $this->assertFileExists(
            $this->themeRoot . '/AI_CONTEXT.md',
            'AI_CONTEXT.md is missing from theme root.'
        );
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
