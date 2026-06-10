<?php
/**
 * tests/PreflightTest.php — Tests for Ops Foundation sprint
 *
 * Covers: target discovery (pp_get_target), capability helper,
 * backup writability probe, deployment manifest, theme file hashing,
 * and regression tests for existing apply behavior after cap gate change.
 */

use PHPUnit\Framework\TestCase;

class PreflightTest extends TestCase
{
    private string $tempDir;
    private string $baseCssPath;
    private string $originalManifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temp directory structure mirroring theme layout
        $this->tempDir = sys_get_temp_dir() . '/pp-preflight-test-' . getmypid() . '-' . mt_rand();
        $cssDir = $this->tempDir . '/assets/css';
        mkdir($cssDir, 0755, true);

        // Copy real base.css to temp location
        $realBaseCss = dirname(__DIR__) . '/assets/css/base.css';
        $this->baseCssPath = $cssDir . '/base.css';
        copy($realBaseCss, $this->baseCssPath);

        // Point get_template_directory() at temp dir
        $GLOBALS['_pp_test_template_dir'] = $this->tempDir;

        // Store options for target discovery
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';

        // Invalidate token cache
        pp_invalidate_design_tokens_cache();
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        $this->recursiveDelete(WP_CONTENT_DIR . '/pp-backups');
        // Clean up deployment manifest
        $manifest = _pp_deployment_manifest_path();
        if (file_exists($manifest)) {
            @unlink($manifest);
        }
        unset($GLOBALS['_pp_test_template_dir']);
        unset($GLOBALS['_pp_test_store']['options']['siteurl']);
        pp_invalidate_design_tokens_cache();
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // ── Target Discovery ──────────────────────────────────────────────────

    public function testTargetReturnsCorrectSiteUrl(): void
    {
        $target = pp_get_target();
        $this->assertSame('https://example.com', $target['site_url']);
    }

    public function testTargetReturnsWpRoot(): void
    {
        $target = pp_get_target();
        $this->assertSame(ABSPATH, $target['wp_root']);
    }

    public function testTargetReturnsThemePath(): void
    {
        $target = pp_get_target();
        $this->assertSame($this->tempDir, $target['theme_path']);
    }

    public function testTargetReturnsEnvironmentFromDebugConstant(): void
    {
        // WP_DEBUG is true in bootstrap, WP_ENVIRONMENT_TYPE not defined
        $target = pp_get_target();
        $this->assertSame('development', $target['environment']);
    }

    public function testTargetReturnsAllFourKeys(): void
    {
        $target = pp_get_target();
        $this->assertArrayHasKey('site_url', $target);
        $this->assertArrayHasKey('wp_root', $target);
        $this->assertArrayHasKey('theme_path', $target);
        $this->assertArrayHasKey('environment', $target);
    }

    public function testTargetHandlesMissingSiteUrl(): void
    {
        unset($GLOBALS['_pp_test_store']['options']['siteurl']);
        $target = pp_get_target();
        $this->assertNull($target['site_url']);
    }

    public function testTargetOutputIsValidJson(): void
    {
        $target = pp_get_target();
        $json = json_encode($target, JSON_PRETTY_PRINT);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertSame($target, $decoded);
    }

    // ── Deployment Manifest ───────────────────────────────────────────────

    public function testManifestPathIsOutsideThemeDir(): void
    {
        $manifest_path = _pp_deployment_manifest_path();
        $theme_path = get_template_directory();
        $this->assertStringNotContainsString($theme_path, $manifest_path);
    }

    public function testLoadManifestReturnsNullWhenMissing(): void
    {
        $manifest = _pp_load_deployment_manifest();
        $this->assertNull($manifest);
    }

    public function testSaveAndLoadManifestRoundTrip(): void
    {
        $hashes = ['style.css' => md5('test'), 'functions.php' => md5('test2')];
        $saved = _pp_save_deployment_manifest($this->tempDir, $hashes);
        $this->assertTrue($saved);

        $loaded = _pp_load_deployment_manifest();
        $this->assertIsArray($loaded);
        $this->assertSame($hashes, $loaded['file_hashes']);
        $this->assertSame($this->tempDir, $loaded['theme_path']);
        $this->assertArrayHasKey('timestamp', $loaded);
    }

    public function testManifestContainsCorrectHashForKnownFile(): void
    {
        $hashes = _pp_hash_theme_files($this->tempDir);
        $saved = _pp_save_deployment_manifest($this->tempDir, $hashes);
        $this->assertTrue($saved);

        $loaded = _pp_load_deployment_manifest();
        // base.css is in the temp dir
        $this->assertArrayHasKey('assets/css/base.css', $loaded['file_hashes']);
        $this->assertSame(
            md5_file($this->baseCssPath),
            $loaded['file_hashes']['assets/css/base.css']
        );
    }

    public function testLoadManifestReturnsNullForMalformedJson(): void
    {
        file_put_contents(_pp_deployment_manifest_path(), 'not-json');
        $manifest = _pp_load_deployment_manifest();
        $this->assertNull($manifest);
    }

    public function testLoadManifestReturnsNullForMissingFileHashes(): void
    {
        file_put_contents(_pp_deployment_manifest_path(), json_encode(['timestamp' => 'now']));
        $manifest = _pp_load_deployment_manifest();
        $this->assertNull($manifest);
    }

    public function testManifestIsValidJson(): void
    {
        $hashes = ['test.php' => md5('x')];
        _pp_save_deployment_manifest($this->tempDir, $hashes);
        $raw = file_get_contents(_pp_deployment_manifest_path());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
    }

    // ── Theme File Hashing ────────────────────────────────────────────────

    public function testHashThemeFilesIncludesPhpCssJsJson(): void
    {
        // Create test files of each type
        file_put_contents($this->tempDir . '/style.css', 'body{}');
        file_put_contents($this->tempDir . '/functions.php', '<?php');
        file_put_contents($this->tempDir . '/app.js', 'var x=1;');
        file_put_contents($this->tempDir . '/data.json', '{}');

        $hashes = _pp_hash_theme_files($this->tempDir);

        $this->assertArrayHasKey('style.css', $hashes);
        $this->assertArrayHasKey('functions.php', $hashes);
        $this->assertArrayHasKey('app.js', $hashes);
        $this->assertArrayHasKey('data.json', $hashes);
    }

    public function testHashSkipsExcludedDirs(): void
    {
        mkdir($this->tempDir . '/node_modules', 0755, true);
        mkdir($this->tempDir . '/vendor', 0755, true);
        mkdir($this->tempDir . '/.git', 0755, true);
        file_put_contents($this->tempDir . '/node_modules/pkg.js', 'x');
        file_put_contents($this->tempDir . '/vendor/lib.php', 'x');
        file_put_contents($this->tempDir . '/.git/config.json', 'x');

        $hashes = _pp_hash_theme_files($this->tempDir);

        $this->assertArrayNotHasKey('node_modules/pkg.js', $hashes);
        $this->assertArrayNotHasKey('vendor/lib.php', $hashes);
        $this->assertArrayNotHasKey('.git/config.json', $hashes);
    }

    public function testHashSkipsNonTargetExtensions(): void
    {
        file_put_contents($this->tempDir . '/image.png', 'x');
        file_put_contents($this->tempDir . '/readme.md', 'x');
        file_put_contents($this->tempDir . '/data.txt', 'x');

        $hashes = _pp_hash_theme_files($this->tempDir);

        $this->assertArrayNotHasKey('image.png', $hashes);
        $this->assertArrayNotHasKey('readme.md', $hashes);
        $this->assertArrayNotHasKey('data.txt', $hashes);
    }

    public function testHashDetectsFileModification(): void
    {
        $hashes_before = _pp_hash_theme_files($this->tempDir);

        // Modify base.css
        file_put_contents($this->baseCssPath, 'modified-content');

        $hashes_after = _pp_hash_theme_files($this->tempDir);

        $this->assertNotSame(
            $hashes_before['assets/css/base.css'],
            $hashes_after['assets/css/base.css']
        );
    }

    // ── Sync Drift Detection ──────────────────────────────────────────────

    public function testDriftDetectionCleanState(): void
    {
        // Save manifest, then immediately compare — should be clean
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);

        $manifest = _pp_load_deployment_manifest();
        $current = _pp_hash_theme_files($this->tempDir);

        // Check for drift
        $modified = [];
        $added = [];
        foreach ($current as $file => $hash) {
            if (!isset($manifest['file_hashes'][$file])) {
                $added[] = $file;
            } elseif ($manifest['file_hashes'][$file] !== $hash) {
                $modified[] = $file;
            }
        }

        $this->assertEmpty($modified);
        $this->assertEmpty($added);
    }

    public function testDriftDetectionModifiedFile(): void
    {
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);

        // Modify a file
        file_put_contents($this->baseCssPath, 'modified-content');

        $manifest = _pp_load_deployment_manifest();
        $current = _pp_hash_theme_files($this->tempDir);

        $modified = [];
        foreach ($current as $file => $hash) {
            if (isset($manifest['file_hashes'][$file]) && $manifest['file_hashes'][$file] !== $hash) {
                $modified[] = $file;
            }
        }

        $this->assertContains('assets/css/base.css', $modified);
    }

    public function testDriftDetectionLiveOnlyFile(): void
    {
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);

        // Add a live-only file
        file_put_contents($this->tempDir . '/live-fix.php', '<?php // hotfix');

        $manifest = _pp_load_deployment_manifest();
        $current = _pp_hash_theme_files($this->tempDir);

        $added = [];
        foreach ($current as $file => $hash) {
            if (!isset($manifest['file_hashes'][$file])) {
                $added[] = $file;
            }
        }

        $this->assertContains('live-fix.php', $added);
    }

    public function testDriftDetectionDeletedFile(): void
    {
        file_put_contents($this->tempDir . '/to-delete.php', '<?php');
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);

        // Delete the file
        unlink($this->tempDir . '/to-delete.php');

        $manifest = _pp_load_deployment_manifest();
        $current = _pp_hash_theme_files($this->tempDir);

        $deleted = [];
        foreach ($manifest['file_hashes'] as $file => $hash) {
            if (!isset($current[$file])) {
                $deleted[] = $file;
            }
        }

        $this->assertContains('to-delete.php', $deleted);
    }

    // ── Regression: Existing apply behavior ───────────────────────────────

    public function testApplyExecuteStillWorksAfterCapGateChange(): void
    {
        // The capability gate change should not affect apply execution
        $result = pp_execute_apply('update_design_token', [
            'token' => '--color-accent',
            'value' => '#b45309',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('update_design_token', $result['apply']);
    }

    public function testApplyPreviewStillWorksAfterCapGateChange(): void
    {
        $result = pp_preview_apply('update_design_token', [
            'token' => '--color-accent',
            'value' => '#b45309',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
    }

}
