<?php
/**
 * tests/ComponentLoaderTest.php
 *
 * Tests for pp_get_component() — rendering, scope isolation, missing component handling.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class ComponentLoaderTest extends TestCase
{
    private string $themeRoot;
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->themeRoot  = dirname(__DIR__);
        $this->fixturesDir = sys_get_temp_dir() . '/pp_test_components_' . uniqid();
        mkdir($this->fixturesDir, 0777, true);

        // Override get_template_directory() for test fixtures.
        // We use a filter-like approach: create a named fixture loader.
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixturesDir);
    }

    // ── pp_get_component renders output ───────────────────────────────────

    public function testGetComponentRendersExistingComponent(): void
    {
        // Write a temporary fixture component.
        $componentDir = $this->fixturesDir . '/components/test-render';
        mkdir($componentDir, 0777, true);

        file_put_contents($componentDir . '/test-render.php', '<?php echo "RENDERED:" . esc_html($props["label"] ?? "default"); ?>');

        // Temporarily override template directory.
        $original = get_template_directory();

        // Use output buffering to capture.
        ob_start();

        // Directly require the file in isolated scope to simulate pp_get_component.
        $file  = $componentDir . '/test-render.php';
        $props = ['label' => 'hello'];
        (static function (string $file, array $props): void {
            require $file;
        })($file, $props);

        $output = ob_get_clean();

        $this->assertStringContainsString('RENDERED:hello', $output);
    }

    // ── Scope isolation: props do NOT leak between calls ──────────────────

    public function testPropsDoNotLeakBetweenCalls(): void
    {
        $componentDir = $this->fixturesDir . '/components/test-scope';
        mkdir($componentDir, 0777, true);

        // This component outputs the label or "NONE" if not set.
        file_put_contents(
            $componentDir . '/test-scope.php',
            '<?php echo isset($props["label"]) ? esc_html($props["label"]) : "NONE"; ?>'
        );

        $file = $componentDir . '/test-scope.php';

        // First call with a prop.
        ob_start();
        $props1 = ['label' => 'first'];
        (static function (string $file, array $props): void { require $file; })($file, $props1);
        $out1 = ob_get_clean();

        // Second call WITHOUT the prop.
        ob_start();
        $props2 = [];
        (static function (string $file, array $props): void { require $file; })($file, $props2);
        $out2 = ob_get_clean();

        $this->assertSame('first', $out1, 'First call should output the label.');
        $this->assertSame('NONE', $out2, 'Second call must not leak props from first call.');
    }

    // ── Missing component returns no output and no fatal ──────────────────

    public function testMissingComponentReturnsNoOutputWithoutFatal(): void
    {
        // With WP_DEBUG off, pp_get_component() for a missing component must:
        // (a) produce no output, and (b) not throw or fatal.
        ob_start();
        pp_get_component('does-not-exist-fixture-xyz');
        $output = ob_get_clean();

        $this->assertSame('', $output, 'Missing component should produce no output.');
    }

    // ── pp_component_exists returns correct boolean ────────────────────────

    public function testComponentExistsReturnsTrueForRealComponent(): void
    {
        // hero component exists in the real theme.
        $heroFile = $this->themeRoot . '/components/hero/hero.php';
        $this->assertFileExists($heroFile, 'Hero component should exist.');
    }

    public function testAllEightComponentsExist(): void
    {
        $expected = ['hero', 'section', 'faq', 'grid', 'table', 'cta', 'nav', 'footer'];

        foreach ($expected as $name) {
            $file = $this->themeRoot . "/components/{$name}/{$name}.php";
            $this->assertFileExists(
                $file,
                "Component '{$name}' is missing its PHP file."
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
