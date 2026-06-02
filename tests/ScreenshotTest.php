<?php
/**
 * tests/ScreenshotTest.php — Tests for screenshot capture infrastructure
 *
 * Covers: spec generation, capture error handling, directory management,
 * pruning, and output path conventions.
 */

use PHPUnit\Framework\TestCase;

class ScreenshotTest extends TestCase
{
    private string $screenshotDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->screenshotDir = sys_get_temp_dir() . '/pp-screenshot-test-' . getmypid() . '-' . mt_rand();
        mkdir($this->screenshotDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->screenshotDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // ── Spec Generation ────────────────────────────────────────────────────

    public function testScreenshotSpecGeneratesCorrectURLsAndPaths(): void
    {
        $specs = pp_screenshot_spec(42, 'create-page');

        $this->assertCount(2, $specs, 'Should generate specs for 2 viewports');

        // Desktop spec
        $desktop = $specs[0];
        $this->assertStringContainsString('page_id=42', $desktop['url']);
        $this->assertEquals(1280, $desktop['width']);
        $this->assertEquals(800, $desktop['height']);
        $this->assertStringContainsString('create-page/42/', $desktop['output']);
        $this->assertStringContainsString('-desktop.png', $desktop['output']);

        // Mobile spec
        $mobile = $specs[1];
        $this->assertEquals(375, $mobile['width']);
        $this->assertEquals(812, $mobile['height']);
        $this->assertStringContainsString('-mobile.png', $mobile['output']);
    }

    // ── Capture — No Browser ───────────────────────────────────────────────

    public function testCaptureReturnsNoBrowserErrorWhenNotConfigured(): void
    {
        // Ensure PP_BROWSER_CMD is not set
        // (It shouldn't be in the test environment, but let's be explicit)
        putenv('PP_BROWSER_CMD');

        $result = pp_screenshot_capture([
            'url'    => 'https://example.com',
            'width'  => 1280,
            'height' => 800,
            'output' => $this->screenshotDir . '/test.png',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertEquals('no_browser', $result['error']);
    }

    // ── Directory ──────────────────────────────────────────────────────────

    public function testScreenshotDirCreatesDirectory(): void
    {
        // PP_SCREENSHOT_DIR is not defined, so it falls back to WP_CONTENT_DIR
        $dir = pp_screenshot_dir();
        $this->assertIsString($dir);
        $this->assertDirectoryExists($dir);

        // Clean up the created directory
        if (is_dir($dir) && strpos($dir, 'pp-screenshots') !== false) {
            @rmdir($dir);
        }
    }

    // ── Pruning ────────────────────────────────────────────────────────────

    public function testPruneKeepsMostRecentNFiles(): void
    {
        // Create 15 fake screenshot files with different mtimes
        for ($i = 1; $i <= 15; $i++) {
            $file = $this->screenshotDir . "/screenshot-{$i}.png";
            file_put_contents($file, "fake-{$i}");
            touch($file, time() - (20 - $i)); // older files have older mtime
        }

        $deleted = pp_screenshot_prune($this->screenshotDir, 10);

        $this->assertEquals(5, $deleted);

        $remaining = glob($this->screenshotDir . '/*.png');
        $this->assertCount(10, $remaining);

        // Verify the 5 oldest were deleted (files 1-5)
        for ($i = 1; $i <= 5; $i++) {
            $this->assertFileDoesNotExist(
                $this->screenshotDir . "/screenshot-{$i}.png",
                "File screenshot-{$i}.png should have been pruned"
            );
        }
    }

    public function testPruneReturnsZeroOnEmptyDirectory(): void
    {
        $deleted = pp_screenshot_prune($this->screenshotDir, 10);
        $this->assertEquals(0, $deleted);
    }

    public function testPruneReturnsZeroWhenUnderLimit(): void
    {
        // Create 5 files (under default keep=10)
        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($this->screenshotDir . "/screenshot-{$i}.png", "fake-{$i}");
        }

        $deleted = pp_screenshot_prune($this->screenshotDir, 10);
        $this->assertEquals(0, $deleted);
    }

    public function testPruneReturnsZeroForNonExistentDirectory(): void
    {
        $deleted = pp_screenshot_prune('/nonexistent/path/' . mt_rand(), 10);
        $this->assertEquals(0, $deleted);
    }

    // ── Output Path Convention ─────────────────────────────────────────────

    public function testOutputPathFollowsConvention(): void
    {
        $specs = pp_screenshot_spec(42, 'create-page');

        foreach ($specs as $spec) {
            // Path should contain: pp-screenshots/playbook/post_id/timestamp-viewport.png
            $this->assertMatchesRegularExpression(
                '#/create-page/42/\d{8}-\d{6}-(desktop|mobile)\.png$#',
                $spec['output']
            );
        }
    }
}
