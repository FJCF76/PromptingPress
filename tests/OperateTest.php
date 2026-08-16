<?php
/**
 * tests/OperateTest.php — Tests for Agent Operating Framework
 *
 * Covers: loop steps, drift detection, site inspection, preflight,
 * checklists, and loop run validation.
 */

use PHPUnit\Framework\TestCase;

class OperateTest extends TestCase
{
    private string $tempDir;
    private string $baseCssPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temp directory structure mirroring theme layout
        $this->tempDir = sys_get_temp_dir() . '/pp-operate-test-' . getmypid() . '-' . mt_rand();
        $cssDir = $this->tempDir . '/assets/css';
        mkdir($cssDir, 0755, true);

        // Copy real base.css to temp location
        $realBaseCss = dirname(__DIR__) . '/assets/css/base.css';
        $this->baseCssPath = $cssDir . '/base.css';
        copy($realBaseCss, $this->baseCssPath);

        // Mirror component stubs so pp_get_registered_components() finds them
        $realComponents = dirname(__DIR__) . '/components';
        if (is_dir($realComponents)) {
            foreach (scandir($realComponents) as $name) {
                if ($name === '.' || $name === '..') continue;
                $src = $realComponents . '/' . $name;
                $dst = $this->tempDir . '/components/' . $name;
                if (is_dir($src)) {
                    mkdir($dst, 0755, true);
                    // Copy the PHP file and schema.json if they exist
                    foreach (["$name.php", 'schema.json'] as $file) {
                        if (file_exists("$src/$file")) {
                            copy("$src/$file", "$dst/$file");
                        }
                    }
                }
            }
        }

        // Point get_template_directory() at temp dir
        $GLOBALS['_pp_test_template_dir'] = $this->tempDir;
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';

        // Invalidate registered components cache so it re-scans the temp dir
        $GLOBALS['_pp_registered_components_invalidate'] = true;

        // Ensure WP_CONTENT_DIR exists (needed for manifest storage)
        if (!is_dir(WP_CONTENT_DIR)) {
            mkdir(WP_CONTENT_DIR, 0755, true);
        }

        pp_invalidate_design_tokens_cache();
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        // Clean up deployment manifest
        $manifest = _pp_deployment_manifest_path();
        if (file_exists($manifest)) {
            @unlink($manifest);
        }
        unset($GLOBALS['_pp_test_template_dir']);
        unset($GLOBALS['_pp_test_store']['options']['siteurl']);
        unset($GLOBALS['_pp_test_store']['upload_dir']);
        pp_invalidate_design_tokens_cache();
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

    // ── Loop Steps ─────────────────────────────────────────────────────────

    public function testLoopStepsReturnsAll8Steps(): void
    {
        $steps = pp_operate_loop_steps();
        $this->assertCount(8, $steps);
        $this->assertArrayHasKey('INSPECT', $steps);
        $this->assertArrayHasKey('PLAN', $steps);
        $this->assertArrayHasKey('EDIT', $steps);
        $this->assertArrayHasKey('PREFLIGHT', $steps);
        $this->assertArrayHasKey('APPLY', $steps);
        $this->assertArrayHasKey('SCREENSHOT', $steps);
        $this->assertArrayHasKey('REVIEW', $steps);
        $this->assertArrayHasKey('HANDOFF', $steps);
    }

    public function testLoopStepsHaveCorrectPhases(): void
    {
        $steps = pp_operate_loop_steps();
        $this->assertEquals('strategist', $steps['INSPECT']['phase']);
        $this->assertEquals('strategist', $steps['PLAN']['phase']);
        $this->assertEquals('implementer', $steps['EDIT']['phase']);
        $this->assertEquals('operator', $steps['PREFLIGHT']['phase']);
        $this->assertEquals('operator', $steps['APPLY']['phase']);
        $this->assertEquals('reviewer', $steps['SCREENSHOT']['phase']);
        $this->assertEquals('reviewer', $steps['REVIEW']['phase']);
        $this->assertEquals('operator', $steps['HANDOFF']['phase']);
    }

    public function testEachStepHasRequiredOutputs(): void
    {
        $steps = pp_operate_loop_steps();
        foreach ($steps as $name => $step) {
            $this->assertArrayHasKey('required_outputs', $step, "$name missing required_outputs");
            $this->assertNotEmpty($step['required_outputs'], "$name has empty required_outputs");
        }
    }

    // ── Drift Detection ────────────────────────────────────────────────────

    public function testCheckDriftReturnsCorrectShape(): void
    {
        $drift = pp_check_drift();
        $this->assertArrayHasKey('has_drift', $drift);
        $this->assertArrayHasKey('modified', $drift);
        $this->assertArrayHasKey('added', $drift);
        $this->assertArrayHasKey('deleted', $drift);
        $this->assertIsBool($drift['has_drift']);
        $this->assertIsArray($drift['modified']);
        $this->assertIsArray($drift['added']);
        $this->assertIsArray($drift['deleted']);
    }

    public function testCheckDriftReturnsNoDriftWhenNoManifest(): void
    {
        // No manifest exists — read-only, should NOT create one
        $manifest_path = _pp_deployment_manifest_path();
        if (file_exists($manifest_path)) {
            unlink($manifest_path);
        }

        $drift = pp_check_drift();

        $this->assertFalse($drift['has_drift']);
        $this->assertEmpty($drift['modified']);
        $this->assertEmpty($drift['added']);
        $this->assertEmpty($drift['deleted']);

        // Verify manifest was NOT created (read-only behavior)
        $this->assertFileDoesNotExist($manifest_path);
    }

    public function testCheckDriftDetectsModifiedFiles(): void
    {
        // Create a baseline manifest
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);

        // Modify base.css
        file_put_contents($this->baseCssPath, '/* modified */');

        $drift = pp_check_drift();

        $this->assertTrue($drift['has_drift']);
        $this->assertContains('assets/css/base.css', $drift['modified']);
    }

    public function testCheckDriftDetectsAddedFiles(): void
    {
        // Create a baseline manifest
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);

        // Add a new PHP file
        file_put_contents($this->tempDir . '/new-file.php', '<?php // new');

        $drift = pp_check_drift();

        $this->assertTrue($drift['has_drift']);
        $this->assertContains('new-file.php', $drift['added']);
    }

    public function testCheckDriftDetectsDeletedFiles(): void
    {
        // Add an extra file then create manifest
        file_put_contents($this->tempDir . '/to-delete.php', '<?php // delete me');
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);

        // Delete the file
        unlink($this->tempDir . '/to-delete.php');

        $drift = pp_check_drift();

        $this->assertTrue($drift['has_drift']);
        $this->assertContains('to-delete.php', $drift['deleted']);
    }

    // ── Site Inspection ────────────────────────────────────────────────────

    public function testInspectSiteReturnsAllRequiredKeys(): void
    {
        $result = pp_inspect_site();
        $this->assertArrayHasKey('target', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('drift', $result);
        $this->assertArrayHasKey('preflight', $result);
        $this->assertArrayHasKey('tokens', $result);
        $this->assertArrayHasKey('conflicts', $result);
        $this->assertArrayHasKey('smells', $result);
        $this->assertArrayHasKey('token_smells', $result);
    }

    public function testInspectSiteSurfacesMaskedDerivedTokenSmell(): void
    {
        // #386: a divergent derived-family override (stale orange accent-strong
        // over the default accent base) must be caught at INSPECT, not only APPLY.
        unset($GLOBALS['_pp_test_store']['options']['pp_token_overrides']);
        pp_invalidate_design_tokens_cache();
        pp_set_token_override('--color-accent-strong', '#e07b39');

        $result = pp_inspect_site();
        $this->assertNotEmpty($result['token_smells']);
        $this->assertSame('masked_derived_override', $result['token_smells'][0]['type']);
        $this->assertSame('--color-accent-strong', $result['token_smells'][0]['token']);

        unset($GLOBALS['_pp_test_store']['options']['pp_token_overrides']);
        pp_invalidate_design_tokens_cache();
    }

    public function testInspectSiteTokenSmellsEmptyWhenCoherent(): void
    {
        // No overrides → coherently themed → no token smells at INSPECT.
        unset($GLOBALS['_pp_test_store']['options']['pp_token_overrides']);
        pp_invalidate_design_tokens_cache();

        $result = pp_inspect_site();
        $this->assertSame([], $result['token_smells']);
    }

    public function testInspectSiteReturnsValidDriftShape(): void
    {
        $result = pp_inspect_site();
        $drift = $result['drift'];
        $this->assertArrayHasKey('has_drift', $drift);
        $this->assertArrayHasKey('modified', $drift);
        $this->assertArrayHasKey('added', $drift);
        $this->assertArrayHasKey('deleted', $drift);
    }

    public function testInspectSiteReturnsValidPreflightShape(): void
    {
        $result = pp_inspect_site();
        $preflight = $result['preflight'];
        $this->assertArrayHasKey('ok', $preflight);
        $this->assertArrayHasKey('checks', $preflight);
        $this->assertIsArray($preflight['checks']);
    }

    public function testInspectSiteReturnsSmellsForRealPage(): void
    {
        // Regression (#119): production stores _pp_composition as a JSON
        // STRING (pp_update_composition), not a PHP array. pp_inspect_site()
        // must read it through pp_get_composition() so smells are detected.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Smelly Page', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'hero', 'props' => ['id' => 'pp-hero1111', 'layout' => 'left']],
        ]));

        $result = pp_inspect_site($post_id);

        $this->assertNotEmpty($result['smells']);
        $this->assertSame('hero_left_no_image', $result['smells'][0]['type']);
    }

    public function testInspectSiteReturnsNoSmellsForPageWithoutComposition(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Blank Page', 'post_status' => 'publish']);

        $result = pp_inspect_site($post_id);

        $this->assertSame([], $result['smells']);
    }

    public function testInspectSiteReturnsNoSmellsWithoutPostId(): void
    {
        $result = pp_inspect_site();
        $this->assertSame([], $result['smells']);
    }

    public function testInspectSiteSurfacesCorruptCompositionDistinctly(): void
    {
        // Issue #144: a corrupt/undecodable composition must report a decode
        // error, NOT a clean smells: [] indistinguishable from a blank page.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Corrupt Page', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', '{"component":');

        $result = pp_inspect_site($post_id);

        $this->assertSame([], $result['smells']);
        $this->assertSame('decode_error', $result['composition_decode_error']);
    }

    public function testInspectSiteFlagsNonListJsonAsUnexpectedShape(): void
    {
        // A JSON object decodes to an associative array; it is a data-integrity
        // anomaly, not an absent page.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Object Page', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', '{"component":"hero"}');

        $result = pp_inspect_site($post_id);

        $this->assertSame([], $result['smells']);
        $this->assertSame('unexpected_shape', $result['composition_decode_error']);
    }

    public function testInspectSiteBlankPageHasNoDecodeError(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Blank Page', 'post_status' => 'publish']);

        $result = pp_inspect_site($post_id);

        $this->assertSame([], $result['smells']);
        $this->assertNull($result['composition_decode_error'], 'a genuinely blank page must not report a decode error');
    }

    public function testInspectSiteValidPageHasNoDecodeError(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Smelly Page', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'hero', 'props' => ['id' => 'pp-hero1111', 'layout' => 'left']],
        ]));

        $result = pp_inspect_site($post_id);

        $this->assertNotEmpty($result['smells']);
        $this->assertNull($result['composition_decode_error']);
    }

    // ── Preflight ──────────────────────────────────────────────────────────

    public function testPreflightIncludesDriftCheck(): void
    {
        $result = pp_preflight();
        $check_names = array_column($result['checks'], 'check');
        $this->assertContains('drift', $check_names);
    }

    public function testPreflightBlocksWhenDriftOverlapsPlannedFiles(): void
    {
        // Create manifest then modify base.css to create drift
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);
        file_put_contents($this->baseCssPath, '/* drifted */');

        $result = pp_preflight([
            'planned_files' => ['assets/css/base.css'],
        ]);

        $drift_check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'drift') {
                $drift_check = $c;
                break;
            }
        }

        $this->assertNotNull($drift_check);
        $this->assertFalse($drift_check['pass']);
        $this->assertStringContainsString('overlap', strtolower($drift_check['message']));
    }

    public function testPreflightWarnsNotBlocksOnNonOverlappingDrift(): void
    {
        // Create manifest then add a new PHP file (drift in unrelated file)
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);
        file_put_contents($this->tempDir . '/unrelated.php', '<?php // unrelated');

        $result = pp_preflight([
            'planned_files' => ['assets/css/base.css'],
        ]);

        $drift_check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'drift') {
                $drift_check = $c;
                break;
            }
        }

        $this->assertNotNull($drift_check);
        $this->assertTrue($drift_check['pass'], 'Non-overlapping drift should pass (warning only)');
    }

    public function testPreflightOptionBasedApplyDoesNotProducePlannedFiles(): void
    {
        // update_design_token uses target.type = 'option', not 'file'.
        // Drift in base.css should NOT trigger overlap detection for option-based applies.
        $hashes = _pp_hash_theme_files($this->tempDir);
        _pp_save_deployment_manifest($this->tempDir, $hashes);
        file_put_contents($this->baseCssPath, '/* drifted */');

        $result = pp_preflight([
            'apply_name' => 'update_design_token',
        ]);

        $drift_check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'drift') {
                $drift_check = $c;
                break;
            }
        }

        $this->assertNotNull($drift_check);
        // Drift exists but doesn't overlap with option-based apply — should be a warning, not a block
        $this->assertTrue($drift_check['pass'], 'Option-based apply should not trigger file drift overlap');
    }

    public function testPreflightThemeWritablePassesWhenWritable(): void
    {
        $result = pp_preflight();
        $check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'theme_writable') {
                $check = $c;
                break;
            }
        }
        $this->assertNotNull($check);
        $this->assertTrue($check['pass']);
    }

    public function testPreflightThemeWritableFailsWhenNotWritableWithFilePlannedFiles(): void
    {
        // Make theme dir read-only
        chmod($this->tempDir, 0555);

        $result = pp_preflight(['planned_files' => ['assets/css/base.css']]);
        $check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'theme_writable') {
                $check = $c;
                break;
            }
        }

        // Restore permissions before assertions (so tearDown cleanup works)
        chmod($this->tempDir, 0755);

        $this->assertNotNull($check);
        $this->assertFalse($check['pass']);
    }

    public function testPreflightThemeWritableSkippedForOptionBackedApply(): void
    {
        // Make theme dir read-only
        chmod($this->tempDir, 0555);

        // Option-backed apply should skip the writability check
        $result = pp_preflight(['apply_name' => 'update_design_token']);
        $check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'theme_writable') {
                $check = $c;
                break;
            }
        }

        // Restore permissions before assertions (so tearDown cleanup works)
        chmod($this->tempDir, 0755);

        $this->assertNotNull($check);
        $this->assertTrue($check['pass'], 'Option-backed applies should not require theme writability');
        $this->assertStringContainsString('database-backed', $check['message']);
    }

    public function testPreflightThemeWritableSkippedWithNoPlannedFiles(): void
    {
        // Make theme dir read-only
        chmod($this->tempDir, 0555);

        // No apply_name, no planned_files — default case should skip check
        $result = pp_preflight();
        $check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'theme_writable') {
                $check = $c;
                break;
            }
        }

        // Restore permissions before assertions (so tearDown cleanup works)
        chmod($this->tempDir, 0755);

        $this->assertNotNull($check);
        $this->assertTrue($check['pass'], 'No planned files means no filesystem requirement');
    }

    // ── Uploads writable (#229) ────────────────────────────────────────────

    private function findCheck(array $result, string $name): ?array
    {
        foreach ($result['checks'] as $c) {
            if ($c['check'] === $name) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Seeds the wp_get_upload_dir() stub. Pass 'path' for a dated subdir;
     * override with empty strings to test unresolved shapes.
     */
    private function setUploadDir(array $overrides = []): void
    {
        $GLOBALS['_pp_test_store']['upload_dir'] = array_merge([
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => $this->tempDir . '/uploads',
        ], $overrides);
    }

    public function testPreflightUploadsWritablePassesForMediaApplyWhenWritable(): void
    {
        $uploads = $this->tempDir . '/uploads';
        mkdir($uploads, 0755, true);
        $this->setUploadDir();

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        $this->assertNotNull($check, 'Media apply must emit an uploads_writable check');
        $this->assertTrue($check['pass']);
        $this->assertStringContainsString($uploads, $check['message']);
    }

    public function testPreflightUploadsWritableFailsForMediaApplyWhenNotWritable(): void
    {
        $uploads = $this->tempDir . '/uploads';
        mkdir($uploads, 0555, true);
        $this->setUploadDir();

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        // Restore permissions before assertions (so tearDown cleanup works)
        chmod($uploads, 0755);

        $this->assertNotNull($check);
        $this->assertFalse($check['pass'], 'Unwritable uploads dir must fail preflight for import_media');
        $this->assertFalse($result['ok'], 'uploads_writable is error-grade: preflight ok must be false');
    }

    public function testPreflightUploadsWritableFailsWhenDatedPathExistsUnwritable(): void
    {
        $uploads = $this->tempDir . '/uploads';
        $dated   = $uploads . '/2026/07';
        mkdir($dated, 0755, true);
        chmod($dated, 0555);
        $this->setUploadDir(['path' => $dated]);

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        chmod($dated, 0755);

        $this->assertNotNull($check);
        $this->assertFalse($check['pass'], 'Existing but unwritable dated subdir must fail even when basedir is writable');
        $this->assertFalse($result['ok']);
    }

    public function testPreflightUploadsWritablePassesWhenDatedPathExistsWritable(): void
    {
        $uploads = $this->tempDir . '/uploads';
        $dated   = $uploads . '/2026/07';
        mkdir($dated, 0755, true);
        $this->setUploadDir(['path' => $dated]);

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        $this->assertNotNull($check);
        $this->assertTrue($check['pass']);
        $this->assertStringContainsString($dated, $check['message'], 'Dated path takes precedence over basedir as the checked target');
    }

    public function testPreflightUploadsWritableFailsWhenIntermediateAncestorUnwritable(): void
    {
        // uploads/ is writable but uploads/2026 is 0555 (e.g. rsync'd with the
        // wrong perms): execute's wp_mkdir_p cannot create 2026/07, so preflight
        // must fail on the deepest existing ancestor, not pass on basedir.
        $uploads = $this->tempDir . '/uploads';
        $year    = $uploads . '/2026';
        mkdir($year, 0755, true);
        chmod($year, 0555);
        $this->setUploadDir(['path' => $year . '/07']);

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        chmod($year, 0755);

        $this->assertNotNull($check);
        $this->assertFalse($check['pass'], 'Unwritable intermediate ancestor must fail even when basedir is writable');
        $this->assertStringContainsString($year, $check['message']);
        $this->assertFalse($result['ok']);
    }

    public function testPreflightUploadsWritablePassesWhenDatedPathMissingButBasedirWritable(): void
    {
        // WordPress creates the dated YYYY/MM subdir when the parent is
        // writable, so a missing dated path must not fail the check.
        $uploads = $this->tempDir . '/uploads';
        mkdir($uploads, 0755, true);
        $this->setUploadDir(['path' => $uploads . '/2026/07']);

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        $this->assertNotNull($check);
        $this->assertTrue($check['pass']);
    }

    public function testPreflightUploadsWritablePassesOnFreshSiteWithNoUploadsDir(): void
    {
        // Fresh install: wp-content/uploads does not exist yet, but its parent
        // is writable — wp_mkdir_p at execute time creates the whole tree, so
        // preflight must not block the first-ever media import.
        $this->setUploadDir(); // basedir = tempDir/uploads, never created

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        $this->assertNotNull($check);
        $this->assertTrue($check['pass'], 'Missing uploads dir with a writable parent must pass (WP creates it)');
        $this->assertStringContainsString($this->tempDir, $check['message']);
    }

    public function testPreflightUploadsWritableFailsOnUploadDirError(): void
    {
        $this->setUploadDir([
            'baseurl' => '',
            'basedir' => '',
            'error'   => 'Unable to create directory. Is its parent directory writable by the server?',
        ]);

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        $this->assertNotNull($check);
        $this->assertFalse($check['pass']);
        $this->assertStringContainsString('Unable to create directory', $check['message']);
        $this->assertFalse($result['ok']);
    }

    public function testPreflightUploadsWritableFailsWhenFileBlocksPathSegment(): void
    {
        // A regular FILE at uploads/2026 makes wp_mkdir_p unable to create
        // 2026/07 even though uploads/ itself is writable — the walk must
        // fail on the blocking file, not pass on the writable ancestor.
        $uploads = $this->tempDir . '/uploads';
        mkdir($uploads, 0755, true);
        file_put_contents($uploads . '/2026', 'not a directory');
        $this->setUploadDir(['path' => $uploads . '/2026/07']);

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        $this->assertNotNull($check);
        $this->assertFalse($check['pass'], 'A file occupying an intermediate path segment must fail closed');
        $this->assertStringContainsString($uploads . '/2026', $check['message']);
        $this->assertFalse($result['ok']);
    }

    public function testPreflightUploadsWritableFailsWhenPathUnresolved(): void
    {
        $this->setUploadDir(['baseurl' => '', 'basedir' => '']);

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'uploads_writable');

        $this->assertNotNull($check);
        $this->assertFalse($check['pass'], 'Empty basedir with no error key must fail closed');
        $this->assertFalse($result['ok']);
    }

    public function testPreflightNoUploadsCheckForOptionBackedApply(): void
    {
        $result = pp_preflight(['apply_name' => 'update_design_token']);
        $this->assertNull(
            $this->findCheck($result, 'uploads_writable'),
            'Option-backed applies must not emit an uploads_writable check'
        );
    }

    public function testPreflightNoUploadsCheckWithNoApplyName(): void
    {
        $result = pp_preflight();
        $this->assertNull(
            $this->findCheck($result, 'uploads_writable'),
            'Preflight without a planned apply must not emit an uploads_writable check'
        );
    }

    // ── Apply name known (issue 245) ───────────────────────────────────────

    public function testPreflightFailsForUnknownApplyName(): void
    {
        // A typo'd / unregistered --apply name must fail preflight closed
        // instead of being treated as "no apply planned".
        $result = pp_preflight(['apply_name' => 'import_medai']);
        $check  = $this->findCheck($result, 'apply_known');

        $this->assertNotNull($check, 'Unknown apply must emit an apply_known check');
        $this->assertFalse($check['pass'], 'Unknown apply name must fail the apply_known check');
        $this->assertStringContainsString('Unknown apply: import_medai', $check['message']);
        $this->assertFalse($result['ok'], 'apply_known is error-grade: an unknown apply makes preflight ok false');
    }

    public function testPreflightUnknownApplySkipsApplyRoutedFilesystemChecks(): void
    {
        // Guards the false-pass path: with an unknown name, $apply_target_type is
        // null, so the theme/uploads checks fall to the "no filesystem writes"
        // skip. apply_known is what keeps the overall preflight failing.
        $result = pp_preflight(['apply_name' => 'import_medai']);

        $this->assertNull(
            $this->findCheck($result, 'uploads_writable'),
            'Unknown apply resolves no target type, so no uploads_writable check is emitted'
        );
        $this->assertFalse($result['ok'], 'Overall preflight must still fail via apply_known');
    }

    public function testPreflightFailsForFalsyStringApplyName(): void
    {
        // Regression guard: the presence test must be `!== ''`, not empty().
        // PHP's empty('0') is true, so an !empty() gate would let the literal
        // apply name "0" slip through as "no apply planned" — a provided-but-
        // unregistered value must still fail closed (Codex adversarial finding
        // on issue 245). No registered apply is named "0".
        $result = pp_preflight(['apply_name' => '0']);
        $check  = $this->findCheck($result, 'apply_known');

        $this->assertNotNull($check, 'A provided falsy-string apply name must still be validated');
        $this->assertFalse($check['pass'], 'apply name "0" is unregistered and must fail the apply_known check');
        $this->assertStringContainsString('Unknown apply: 0', $check['message']);
        $this->assertFalse($result['ok'], 'Overall preflight must fail for the unregistered name "0"');
    }

    public function testPreflightNoApplyKnownCheckForEmptyStringApplyName(): void
    {
        // An empty-string apply name is "no apply planned" (equivalent to omitting
        // the flag), NOT an unknown apply — it must not emit an apply_known failure.
        $result = pp_preflight(['apply_name' => '']);
        $this->assertNull(
            $this->findCheck($result, 'apply_known'),
            'An empty apply name is no-apply-planned, not an unknown apply'
        );
    }

    public function testPreflightNoApplyKnownCheckForRegisteredApply(): void
    {
        // A known apply name must NOT emit a failing apply_known check.
        $result = pp_preflight(['apply_name' => 'update_design_token']);
        $this->assertNull(
            $this->findCheck($result, 'apply_known'),
            'A registered apply must not emit an apply_known check'
        );
    }

    public function testPreflightNoApplyKnownCheckWithNoApplyName(): void
    {
        // No --apply at all is "no apply planned", not an unknown apply.
        $result = pp_preflight();
        $this->assertNull(
            $this->findCheck($result, 'apply_known'),
            'Preflight without a planned apply must not emit an apply_known check'
        );
    }

    public function testPreflightThemeWritableMessageDoesNotClaimNoFilesystemWritesForMediaApply(): void
    {
        $uploads = $this->tempDir . '/uploads';
        mkdir($uploads, 0755, true);
        $GLOBALS['_pp_test_store']['upload_dir'] = [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => $uploads,
        ];

        $result = pp_preflight(['apply_name' => 'import_media']);
        $check  = $this->findCheck($result, 'theme_writable');

        $this->assertNotNull($check);
        $this->assertTrue($check['pass'], 'import_media does not touch the theme dir; theme check stays skipped-pass');
        $this->assertStringNotContainsString('no filesystem writes', $check['message']);
        $this->assertStringContainsString('uploads_writable', $check['message']);
    }

    public function testPreflightTargetPagePassesForValidPost(): void
    {
        // Create a post with composition
        $post_id = wp_insert_post([
            'post_type' => 'page',
            'post_title' => 'Test Page',
            'post_status' => 'publish',
        ]);
        update_post_meta($post_id, '_pp_composition', [
            ['component' => 'hero', 'props' => ['title' => 'Hello']],
        ]);

        $result = pp_preflight(['post_id' => $post_id]);
        $check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'target_page') {
                $check = $c;
                break;
            }
        }

        $this->assertNotNull($check);
        $this->assertTrue($check['pass']);
    }

    public function testPreflightTargetPagePassesForJsonStringComposition(): void
    {
        // Regression (#96): production stores _pp_composition as a JSON STRING
        // (pp_update_composition), not a raw array. The target_page check must
        // pass for that real format — otherwise no real page could ever clear
        // preflight and page-scoped mutations would be permanently blocked.
        $post_id = wp_insert_post([
            'post_type'   => 'page',
            'post_title'  => 'JSON String Page',
            'post_status' => 'publish',
        ]);
        update_post_meta($post_id, '_pp_composition', wp_json_encode([
            ['component' => 'hero', 'props' => ['title' => 'Hello']],
        ]));

        $result = pp_preflight(['post_id' => $post_id]);
        $check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'target_page') {
                $check = $c;
                break;
            }
        }

        $this->assertNotNull($check);
        $this->assertTrue($check['pass'], 'target_page must pass for JSON-string composition');
    }

    public function testPreflightTargetPageFailsForNonExistentPost(): void
    {
        $result = pp_preflight(['post_id' => 99999]);
        $check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'target_page') {
                $check = $c;
                break;
            }
        }

        $this->assertNotNull($check);
        $this->assertFalse($check['pass']);
    }

    // ── #358: create_page must not strand a composition-less page ─────────────
    //
    // Two-sided proof. POSITIVE: a page created empty by create_page can now be
    // populated by update_composition and deleted by trash_page through the
    // operate surface (the original dead-end, RED→GREEN). NEGATIVE (the security
    // half): component-level actions STILL fail the composition precondition on a
    // composition-less page — the gate stays closed for them.

    public function testPreflightTargetPagePassesForEmptyComposition(): void
    {
        // The #358 dead-end: create_page yields a page with no _pp_composition, and
        // Check 6 used to FAIL for it ('exists but has no composition'), so its
        // preflight never passed and no page-scoped action could earn coverage.
        // An existing page is now a valid preflight target regardless of emptiness.
        $post_id = pp_execute_action('create_page', ['title' => 'Empty Page 358'])['target']['post_id'];
        $this->assertSame([], pp_get_composition($post_id), 'create_page output unchanged: composition stays empty');

        $result = pp_preflight(['post_id' => $post_id]);
        $check   = $this->findCheck($result, 'target_page');
        $this->assertNotNull($check);
        $this->assertTrue($check['pass'], 'target_page must PASS for an existing but composition-less page (#358)');
    }

    public function testPreflightTargetPageStillFailsClosedForNonExistentPost(): void
    {
        // Relaxing Check 6 must NOT open it for a non-existent post: get_post()
        // returning null is still a hard fail (fail-closed boundary preserved).
        $result = pp_preflight(['post_id' => 987654]);
        $check   = $this->findCheck($result, 'target_page');
        $this->assertNotNull($check);
        $this->assertFalse($check['pass'], 'a non-existent post must still fail target_page');
    }

    public function testCompositionPreconditionAllowsPopulateAndTrashOnEmptyPage(): void
    {
        // POSITIVE end-to-end: create empty → update_composition populates →
        // trash_page deletes, all through the real action path. Each populate/
        // lifecycle action opts out of the composition requirement, so the
        // precondition passes on the still-empty page.
        $post_id = pp_execute_action('create_page', ['title' => 'Populate 358'])['target']['post_id'];

        $this->assertTrue(
            pp_action_composition_precondition(pp_get_action('update_composition'), $post_id),
            'update_composition must clear the precondition on an empty page'
        );
        $populate = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'hero', 'props' => ['title' => 'Hello']]],
        ]);
        $this->assertTrue($populate['ok'], $populate['error'] ?? 'update_composition failed');
        $this->assertNotEmpty(pp_get_composition($post_id), 'page must now be populated');

        // trash_page also opts out — a page created empty must be deletable too.
        $empty2 = pp_execute_action('create_page', ['title' => 'Trash 358'])['target']['post_id'];
        $this->assertTrue(
            pp_action_composition_precondition(pp_get_action('trash_page'), $empty2),
            'trash_page must clear the precondition on an empty page'
        );
        $trash = pp_execute_action('trash_page', ['post_id' => $empty2]);
        $this->assertTrue($trash['ok'], $trash['error'] ?? 'trash_page failed');
    }

    public function testCompositionPreconditionBlocksComponentActionsOnEmptyPage(): void
    {
        // NEGATIVE / security: component-level actions require an existing
        // composition, so the gate stays CLOSED for them on a composition-less page.
        $post_id = pp_execute_action('create_page', ['title' => 'Blocked 358'])['target']['post_id'];

        foreach (['add_component', 'remove_component', 'reorder_components', 'update_component', 'style_component'] as $name) {
            $result = pp_action_composition_precondition(pp_get_action($name), $post_id);
            $this->assertInstanceOf(WP_Error::class, $result, "$name must be blocked on a composition-less page");
            $this->assertSame('composition_required', $result->get_error_code(), "$name must fail closed with composition_required");
        }
    }

    public function testExecutorBlocksComponentActionOnEmptyPageWithoutCliGate(): void
    {
        // #387 acceptance #1 (the core bug): a component-level action on a
        // composition-less page must fail with composition_required through
        // pp_execute_action() DIRECTLY — the path the in-admin chat AJAX handler
        // uses (lib/ai-chat.php calls pp_execute_action()) — with NO WP-CLI gate
        // involved. Before #387 the precondition lived only in the CLI gate, so this
        // exact call returned ok:true and created the first component, letting chat
        // bypass the #358 requirement. Now the shared validator enforces it.
        $post_id = pp_execute_action('create_page', ['title' => 'Chat Bypass 387'])['target']['post_id'];
        $this->assertEmpty(pp_get_composition($post_id), 'page starts with no composition');

        $result = pp_execute_action('add_component', [
            'post_id'   => $post_id,
            'component' => 'hero',
            'props'     => ['title' => 'Should be rejected'],
        ]);

        $this->assertFalse($result['ok'], 'add_component on a composition-less page must fail via the executor');
        $this->assertSame('composition_required', $result['error_code'], 'the envelope must carry the machine-readable error_code');
        $this->assertEmpty(pp_get_composition($post_id), 'the rejected action must not have written a first component');
    }

    public function testExecutorAllowsComponentActionOnceComposed(): void
    {
        // The mirror of the block above: once the page has content, the same
        // executor call succeeds — the relocated guard opens exactly when #358 says
        // it should, so we did not over-block the normal edit path.
        $post_id = pp_execute_action('create_page', ['title' => 'Composed 387'])['target']['post_id'];
        pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'hero', 'props' => ['title' => 'Seed']]],
        ]);

        $result = pp_execute_action('add_component', [
            'post_id'   => $post_id,
            'component' => 'hero',
            'props'     => ['title' => 'Second'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'add_component on a populated page must succeed');
        $this->assertCount(2, pp_get_composition($post_id), 'the second component must be appended');
    }

    public function testExecutorReturnsNotFoundNotCompositionRequiredForNonexistentPage(): void
    {
        // #387 ordering: the composition guard is gated on page existence, so a
        // component action on a NONEXISTENT page still surfaces the action's own
        // not_found (from semantic validate) rather than the misleading
        // composition_required — "populate it first" makes no sense for a page that
        // does not exist. This pins the _pp_validate_page_exists()===false branch.
        $result = pp_execute_action('add_component', [
            'post_id'   => 987654,
            'component' => 'hero',
            'props'     => ['title' => 'X'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['error_code'], 'a nonexistent page must report not_found, not composition_required');
    }

    public function testValidatorSkipsCompositionGuardForSiteScopedActionWithStrayPostId(): void
    {
        // #387 hardening: a site-scoped action inherits requires_composition=TRUE by
        // default but is NOT composition-targeted. A stray post_id in its params
        // (e.g. a malformed proposal) must NOT trip the composition guard — the shared
        // validator keys the precondition on the declared scope, not the raw param,
        // keeping acceptance #2 (site-scoped actions unaffected) true.
        $empty = pp_execute_action('create_page', ['title' => 'Site Scope 387'])['target']['post_id'];
        $this->assertEmpty(pp_get_composition($empty), 'target page is composition-less');

        pp_register_action('_test_387_site_action', [
            'scope'    => 'site',
            'params'   => [],
            'validate' => function (array $params) { return true; },
        ]);
        try {
            // requires_composition defaulted TRUE by pp_register_action; without the
            // scope gate this stray post_id at an empty page would return composition_required.
            $this->assertTrue(pp_get_action('_test_387_site_action')['requires_composition']);
            $result = pp_validate_action('_test_387_site_action', ['post_id' => $empty]);
            $this->assertTrue($result, 'a site-scoped action must validate despite a stray post_id at a composition-less page');
        } finally {
            unset($GLOBALS['_pp_actions']['_test_387_site_action']);
        }
    }

    public function testPatchPreviewOnCompositionLessPageFailsClosed(): void
    {
        // #387: pp_patch_composition() enforces the composition precondition in step
        // 2a, BEFORE the read-only preview branch, so previewing a patch on a
        // composition-less page fails closed with composition_required (a clear error)
        // instead of the confusing component_not_found — and never diffs a component
        // that cannot exist. Preview still writes nothing.
        $post_id = pp_execute_action('create_page', ['title' => 'Preview 387'])['target']['post_id'];
        $this->assertEmpty(pp_get_composition($post_id));

        $result = pp_patch_composition($post_id, 'hero.title', 'x', /* preview */ true);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('composition_required', $result->get_error_code());
        $this->assertEmpty(pp_get_composition($post_id), 'preview must not write');
    }

    public function testCompositionPreconditionAllowsComponentActionsOnPopulatedPage(): void
    {
        $post_id = pp_execute_action('create_page', ['title' => 'Populated 358'])['target']['post_id'];
        pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'hero', 'props' => ['title' => 'Hi']]],
        ]);

        foreach (['add_component', 'update_component', 'style_component'] as $name) {
            $this->assertTrue(
                pp_action_composition_precondition(pp_get_action($name), $post_id),
                "$name must clear the precondition once the composition is non-empty"
            );
        }
    }

    public function testCompositionPreconditionDefaultsToRequiredFailClosed(): void
    {
        // Declarative default-deny: an action array with NO 'requires_composition'
        // key (e.g. a hand-built fixture that skipped pp_register_action) inherits
        // "requires" — the gate must fail closed rather than open.
        $post_id = pp_execute_action('create_page', ['title' => 'Default 358'])['target']['post_id'];
        $unflagged = ['name' => '_test_unflagged_action', 'scope' => 'page'];

        $result = pp_action_composition_precondition($unflagged, $post_id);
        $this->assertInstanceOf(WP_Error::class, $result, 'an un-annotated action must be gated (fail-closed default)');
        $this->assertSame('composition_required', $result->get_error_code());
    }

    public function testRegisterActionDefaultsRequiresCompositionTrue(): void
    {
        // The default is applied at registration time, so every real action either
        // sets the flag explicitly or inherits true.
        pp_register_action('_test_358_default_action', ['scope' => 'page', 'params' => []]);
        try {
            $action = pp_get_action('_test_358_default_action');
            $this->assertTrue($action['requires_composition'], 'un-annotated actions default to requires_composition=true');
        } finally {
            unset($GLOBALS['_pp_actions']['_test_358_default_action']);
        }

        // Component-level actions inherit the default; opt-out actions set it false.
        $this->assertTrue(pp_get_action('style_component')['requires_composition']);
        $this->assertTrue(pp_get_action('add_component')['requires_composition']);
        $this->assertFalse(pp_get_action('update_composition')['requires_composition']);
        $this->assertFalse(pp_get_action('trash_page')['requires_composition']);
        // restore_composition opts out too — #233: restore is never blocked by
        // current validation; its precondition is "has history", not "has content".
        $this->assertFalse(pp_get_action('restore_composition')['requires_composition']);
    }

    public function testCompositionPreconditionIsNoopForSiteScopedAction(): void
    {
        // Site-scoped actions carry no post_id; the precondition is a no-op for them
        // even though they inherit requires_composition=true.
        $this->assertTrue(pp_action_composition_precondition(pp_get_action('create_page'), null));
        $this->assertTrue(pp_action_composition_precondition(pp_get_action('update_site_option'), null));
    }

    public function testPreflightAcceptsPreComputedDrift(): void
    {
        // Provide pre-computed drift with no issues
        $pre_drift = [
            'has_drift' => false,
            'modified'  => [],
            'added'     => [],
            'deleted'   => [],
        ];

        $result = pp_preflight([], $pre_drift);
        $drift_check = null;
        foreach ($result['checks'] as $c) {
            if ($c['check'] === 'drift') {
                $drift_check = $c;
                break;
            }
        }

        $this->assertNotNull($drift_check);
        $this->assertTrue($drift_check['pass']);
    }

    // ── Checklists ─────────────────────────────────────────────────────────

    public function testChecklistsAreWellFormed(): void
    {
        $checklists = pp_operate_checklists();
        $this->assertArrayHasKey('create-page', $checklists);
        $this->assertArrayHasKey('revise-section', $checklists);
        $this->assertArrayHasKey('inspect-fix', $checklists);

        foreach ($checklists as $playbook => $items) {
            $this->assertLessThanOrEqual(10, count($items), "$playbook exceeds 10-item cap");

            $hard_gates = 0;
            foreach ($items as $item) {
                $this->assertArrayHasKey('id', $item, "$playbook item missing 'id'");
                $this->assertArrayHasKey('description', $item, "$playbook item missing 'description'");
                $this->assertArrayHasKey('gate', $item, "$playbook item missing 'gate'");
                $this->assertArrayHasKey('viewport', $item, "$playbook item missing 'viewport'");
                $this->assertContains($item['gate'], ['hard', 'soft'], "$playbook item has invalid gate");
                $this->assertContains($item['viewport'], ['desktop', 'mobile', 'any'], "$playbook item has invalid viewport");

                if ($item['gate'] === 'hard') {
                    $hard_gates++;
                }
            }
            $this->assertLessThanOrEqual(5, $hard_gates, "$playbook exceeds 5 hard-gate cap");
        }
    }

    // ── Loop Run Validation ────────────────────────────────────────────────

    public function testValidateLoopRunRejectsRunMissingScreenshots(): void
    {
        $run = $this->makeCompleteRun();
        unset($run['SCREENSHOT']);

        $result = pp_validate_loop_run($run);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidateLoopRunRejectsRunMissingPreflight(): void
    {
        $run = $this->makeCompleteRun();
        unset($run['PREFLIGHT']);

        $result = pp_validate_loop_run($run);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidateLoopRunRejectsRunMissingHandoffStatus(): void
    {
        $run = $this->makeCompleteRun();
        unset($run['HANDOFF']['handoff_report']['status']);

        $result = pp_validate_loop_run($run);
        $this->assertFalse($result['valid']);

        $status_error = false;
        foreach ($result['errors'] as $e) {
            if (stripos($e, 'status') !== false) {
                $status_error = true;
                break;
            }
        }
        $this->assertTrue($status_error, 'Should flag missing handoff status');
    }

    public function testValidateLoopRunAcceptsCompleteRun(): void
    {
        $run = $this->makeCompleteRun();
        $result = pp_validate_loop_run($run);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    // ── Run Token State File Tests ────────────────────────────────────────

    /**
     * Reads a run's stored state directly from the options store (#409): the run-state
     * store is now a per-run non-autoloaded wp_options row, not a temp-dir file.
     */
    private function readRunState(string $run_id)
    {
        return get_option(pp_operate_run_option_name($run_id), null);
    }

    /** Writes a run's stored state directly (test-only manipulation of the store). */
    private function writeRunState(string $run_id, array $data): void
    {
        update_option(pp_operate_run_option_name($run_id), $data, false);
    }

    public function testCreateRunStoresStateOptionWithCorrectShape(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertIsString($run_id);

        // State lives in a non-autoloaded option, not a temp-dir file.
        $data = $this->readRunState($run_id);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('steps_completed', $data);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('site_id', $data);
        $this->assertContains('INSPECT', $data['steps_completed']);

        pp_operate_cleanup_run($run_id);
    }

    public function testRunOptionNameIsBoundedAndPrefixed(): void
    {
        $run_id = '00000000-0000-4000-8000-000000000000';
        $name = pp_operate_run_option_name($run_id);
        $this->assertSame('pp_operate_run_' . $run_id, $name);
        // wp_options.option_name is VARCHAR(191); the name must fit comfortably.
        $this->assertLessThanOrEqual(191, strlen($name));
    }

    public function testCreateRunReturnsValidUuid(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $run_id
        );
        pp_operate_cleanup_run($run_id);
    }

    public function testCheckStepReturnsTrueForCompletedStep(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_check_step($run_id, 'INSPECT'));
        pp_operate_cleanup_run($run_id);
    }

    public function testCheckStepReturnsFalseForMissingStep(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertFalse(pp_operate_check_step($run_id, 'PREFLIGHT'));
        pp_operate_cleanup_run($run_id);
    }

    public function testCheckStepReturnsFalseForExpiredState(): void
    {
        $run_id = pp_operate_create_run();

        // Backdate created_at to 3 hours ago (> the 2h TTL).
        $data = $this->readRunState($run_id);
        $data['created_at'] = time() - 10800;
        $this->writeRunState($run_id, $data);

        $this->assertFalse(pp_operate_check_step($run_id, 'INSPECT'));
        pp_operate_cleanup_run($run_id);
    }

    public function testReadStateDeletesExpiredRow(): void
    {
        // Auto-expire cleanup: reading an expired run drops its row (bounded store).
        $run_id = pp_operate_create_run();
        $data = $this->readRunState($run_id);
        $data['created_at'] = time() - 10800;
        $this->writeRunState($run_id, $data);

        $this->assertNull(pp_operate_read_state($run_id));
        $this->assertNull($this->readRunState($run_id), 'expired row should be deleted on read');
    }

    public function testRecordStepAppendsStep(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_record_step($run_id, 'PREFLIGHT'));
        $this->assertTrue(pp_operate_check_step($run_id, 'PREFLIGHT'));
        pp_operate_cleanup_run($run_id);
    }

    public function testCleanupRunDeletesStateOption(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertIsArray($this->readRunState($run_id));

        pp_operate_cleanup_run($run_id);
        $this->assertNull($this->readRunState($run_id));
    }

    // ── #409 two-process repro pin ────────────────────────────────────────

    public function testRunStateSurvivesTmpdirChangeBetweenInvocations(): void
    {
        // The bug: run state lived in sys_get_temp_dir(), so with one ephemeral CLI
        // container per `wp` call (each a private /tmp), `inspect` wrote the file in
        // container A's /tmp and it was gone before `preflight` ran in container B.
        // This pins that the options-table store no longer depends on the process temp
        // dir: mint the run under one TMPDIR, then flip TMPDIR (simulating a second
        // invocation with a different private /tmp) and prove the state is still found.
        $originalTmp = getenv('TMPDIR');
        $tmpA = sys_get_temp_dir() . '/pp-tmpdir-A-' . getmypid() . '-' . mt_rand();
        $tmpB = sys_get_temp_dir() . '/pp-tmpdir-B-' . getmypid() . '-' . mt_rand();
        mkdir($tmpA, 0755, true);
        mkdir($tmpB, 0755, true);

        try {
            // "Container A": mint the run.
            putenv('TMPDIR=' . $tmpA);
            $run_id = pp_operate_create_run();
            $this->assertIsString($run_id);

            // "Container B": a completely different private temp dir. The old file store
            // would look in $tmpB and find nothing here.
            putenv('TMPDIR=' . $tmpB);
            $this->assertTrue(
                pp_operate_check_step($run_id, 'INSPECT'),
                'INSPECT state must be visible from a different TMPDIR (store is not temp-dir-backed)'
            );
            $this->assertTrue(
                pp_operate_record_preflight($run_id, null, []),
                'the gated PREFLIGHT record must succeed across a TMPDIR change'
            );
            $this->assertTrue(pp_operate_check_step($run_id, 'PREFLIGHT'));

            pp_operate_cleanup_run($run_id);
        } finally {
            if ($originalTmp === false) {
                putenv('TMPDIR');
            } else {
                putenv('TMPDIR=' . $originalTmp);
            }
            rmdir($tmpA);
            rmdir($tmpB);
        }
    }

    // ── Edge Case Tests ───────────────────────────────────────────────────

    public function testCheckStepReturnsFalseForNonExistentState(): void
    {
        // Valid UUID format but no state row exists.
        $this->assertFalse(pp_operate_check_step('00000000-0000-4000-8000-000000000000', 'INSPECT'));
    }

    public function testCheckStepReturnsFalseForInvalidRunId(): void
    {
        // Path traversal attempt — should be rejected by UUID validation.
        $this->assertFalse(pp_operate_check_step('../../etc/passwd', 'INSPECT'));
        $this->assertFalse(pp_operate_check_step('not-a-uuid', 'INSPECT'));
        $this->assertFalse(pp_operate_check_step('', 'INSPECT'));
    }

    public function testCheckStepReturnsFalseForCorruptState(): void
    {
        // A row that is not a valid state array (e.g. a hand-corrupted option).
        $fake_id = wp_generate_uuid4();
        update_option(pp_operate_run_option_name($fake_id), 'NOT A STATE ARRAY', false);

        $this->assertFalse(pp_operate_check_step($fake_id, 'INSPECT'));
        pp_operate_delete_state($fake_id);
    }

    public function testRecordStepDuplicateIsIdempotent(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_step($run_id, 'PREFLIGHT');

        $data = $this->readRunState($run_id);
        $count = array_count_values($data['steps_completed']);
        $this->assertEquals(1, $count['PREFLIGHT']);

        pp_operate_cleanup_run($run_id);
    }

    public function testCleanupRunNoOpForNonExistentRun(): void
    {
        // Should not throw — valid UUID format but no row exists.
        pp_operate_cleanup_run('00000000-0000-4000-8000-000000000001');
        $this->assertTrue(true);
    }

    // ── State classification (#409) ───────────────────────────────────────

    public function testClassifyStateOk(): void
    {
        $run_id = pp_operate_create_run();
        $c = pp_operate_classify_state($run_id);
        $this->assertSame('ok', $c['status']);
        $this->assertIsArray($c['data']);
        pp_operate_cleanup_run($run_id);
    }

    public function testClassifyStateInvalidRunId(): void
    {
        $this->assertSame('invalid', pp_operate_classify_state('not-a-uuid')['status']);
    }

    public function testClassifyStateNotFound(): void
    {
        $this->assertSame('not_found', pp_operate_classify_state('00000000-0000-4000-8000-000000000000')['status']);
    }

    public function testClassifyStateCorrupt(): void
    {
        $fake_id = wp_generate_uuid4();
        update_option(pp_operate_run_option_name($fake_id), ['no_created_at' => true], false);
        $this->assertSame('corrupt', pp_operate_classify_state($fake_id)['status']);
        pp_operate_delete_state($fake_id);
    }

    public function testClassifyStateExpired(): void
    {
        $run_id = pp_operate_create_run();
        $data = $this->readRunState($run_id);
        $data['created_at'] = time() - 10800;
        $this->writeRunState($run_id, $data);
        $this->assertSame('expired', pp_operate_classify_state($run_id)['status']);
        pp_operate_cleanup_run($run_id);
    }

    public function testClassifyStateForeignSite(): void
    {
        $run_id = pp_operate_create_run();
        $data = $this->readRunState($run_id);
        $data['site_id'] = 'a-different-install-identity';
        $this->writeRunState($run_id, $data);
        $this->assertSame('foreign', pp_operate_classify_state($run_id)['status']);
        pp_operate_cleanup_run($run_id);
    }

    public function testClassifyStateDoesNotDeleteExpiredRow(): void
    {
        // Classification is side-effect free — cleanup is owned by read_state/run_status/GC.
        $run_id = pp_operate_create_run();
        $data = $this->readRunState($run_id);
        $data['created_at'] = time() - 10800;
        $this->writeRunState($run_id, $data);

        pp_operate_classify_state($run_id);
        $this->assertNotNull($this->readRunState($run_id), 'classify must not delete the row');
        pp_operate_cleanup_run($run_id);
    }

    // ── Run status (drives the split not-found/expired CLI errors, #409) ───

    public function testRunStatusReportsAndCleansTerminalStates(): void
    {
        // not_found: no row, nothing to clean.
        $this->assertSame('not_found', pp_operate_run_status('00000000-0000-4000-8000-000000000000'));

        // ok: live run, row kept.
        $run_id = pp_operate_create_run();
        $this->assertSame('ok', pp_operate_run_status($run_id));
        $this->assertNotNull($this->readRunState($run_id));

        // expired: reported AND row reaped (so the message stays precise and rows don't pile up).
        $data = $this->readRunState($run_id);
        $data['created_at'] = time() - 10800;
        $this->writeRunState($run_id, $data);
        $this->assertSame('expired', pp_operate_run_status($run_id));
        $this->assertNull($this->readRunState($run_id), 'expired row reaped by run_status');

        // corrupt: reported AND reaped.
        $fake_id = wp_generate_uuid4();
        update_option(pp_operate_run_option_name($fake_id), 'garbage', false);
        $this->assertSame('corrupt', pp_operate_run_status($fake_id));
        $this->assertNull($this->readRunState($fake_id), 'corrupt row reaped by run_status');
    }

    public function testRunStatusKeepsForeignRow(): void
    {
        // A foreign row is NOT reaped (it may be a live run on the other install).
        $run_id = pp_operate_create_run();
        $data = $this->readRunState($run_id);
        $data['site_id'] = 'other-install';
        $this->writeRunState($run_id, $data);
        $this->assertSame('foreign', pp_operate_run_status($run_id));
        $this->assertNotNull($this->readRunState($run_id));
        pp_operate_cleanup_run($run_id);
    }

    // ── Mutate fail-closed semantics (#409) ───────────────────────────────

    public function testMutateStateFailsClosedForNotFound(): void
    {
        $this->assertFalse(pp_operate_mutate_state('00000000-0000-4000-8000-000000000000', fn($d) => $d));
    }

    public function testMutateStateFailsClosedForInvalidRunId(): void
    {
        $this->assertFalse(pp_operate_mutate_state('not-a-uuid', fn($d) => $d));
    }

    public function testMutateStateFailsClosedForExpiredRun(): void
    {
        $run_id = pp_operate_create_run();
        $data = $this->readRunState($run_id);
        $data['created_at'] = time() - 10800;
        $this->writeRunState($run_id, $data);

        $called = false;
        $result = pp_operate_mutate_state($run_id, function ($d) use (&$called) {
            $called = true;
            $d['steps_completed'][] = 'PREFLIGHT';
            return $d;
        });
        $this->assertFalse($result);
        $this->assertFalse($called, 'mutator must not run for an expired run');
        // mutate must NOT delete the expired row (so run_status can still report expired).
        $this->assertNotNull($this->readRunState($run_id), 'mutate must leave the expired row for run_status');
        pp_operate_cleanup_run($run_id);
    }

    public function testMutateStateFailsClosedForForeignSite(): void
    {
        $run_id = pp_operate_create_run();
        $data = $this->readRunState($run_id);
        $data['site_id'] = 'other-install';
        $this->writeRunState($run_id, $data);

        $this->assertFalse(pp_operate_mutate_state($run_id, fn($d) => $d));
        pp_operate_cleanup_run($run_id);
    }

    public function testMutateStateMutatorAbortPreservesPriorState(): void
    {
        // Fail-closed on mutator abort: a mutator returning false (or a non-array) must
        // refuse the write and leave the prior state fully intact. This is the DB-store
        // equivalent of the old file store's encode/truncate fail-closed guarantee.
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_record_step($run_id, 'PREFLIGHT'));
        $before = $this->readRunState($run_id);

        $this->assertFalse(pp_operate_mutate_state($run_id, static fn($d) => false));
        $this->assertFalse(pp_operate_mutate_state($run_id, static fn($d) => 'not an array'));

        $after = $this->readRunState($run_id);
        $this->assertSame($before, $after, 'prior state must survive an aborted mutation byte-for-byte');
        pp_operate_cleanup_run($run_id);
    }

    public function testMutateStateIdempotentNoOpReturnsTrue(): void
    {
        // A no-op mutation (returns the state unchanged) reports success even though
        // update_option() returns false on a no-op write — the state is already durable.
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_mutate_state($run_id, static fn($d) => $d));
        pp_operate_cleanup_run($run_id);
    }

    public function testMutateStateRoundTripsMultibytePayload(): void
    {
        // A valid write of multibyte content (byte length != character length) must
        // round-trip through the option store intact.
        $run_id = pp_operate_create_run();
        $result = pp_operate_mutate_state($run_id, static function (array $data) {
            $data['note'] = 'café ☕ 日本語 déjà';
            return $data;
        });
        $this->assertTrue($result);
        $this->assertSame('café ☕ 日本語 déjà', $this->readRunState($run_id)['note']);
        pp_operate_cleanup_run($run_id);
    }

    public function testMutateStateShorterPayloadFullyReplacesPriorValue(): void
    {
        // Shrinking the state (removing a key) must fully replace the stored value with
        // no residue of the prior, larger payload.
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_mutate_state($run_id, static function (array $data) {
            $data['filler'] = str_repeat('x', 500);
            return $data;
        }));
        $this->assertArrayHasKey('filler', $this->readRunState($run_id));

        $this->assertTrue(pp_operate_mutate_state($run_id, static function (array $data) {
            unset($data['filler']);
            return $data;
        }));
        $this->assertArrayNotHasKey('filler', $this->readRunState($run_id));
        pp_operate_cleanup_run($run_id);
    }

    // ── Garbage collection of abandoned run rows (#409) ────────────────────

    public function testGcExpiredRunsNoOpsWithoutWpdb(): void
    {
        // Without a $wpdb handle (unit context) GC cannot enumerate rows; it must no-op,
        // never fatal.
        $this->assertSame(0, pp_operate_gc_expired_runs());
    }

    public function testGcExpiredRunsReapsExpiredAndCorruptButKeepsLive(): void
    {
        // Live run — must survive the sweep.
        $live = pp_operate_create_run();

        // Expired run.
        $expired = wp_generate_uuid4();
        update_option(pp_operate_run_option_name($expired), [
            'steps_completed' => ['INSPECT'],
            'created_at'      => time() - 10800,
            'site_id'         => pp_operate_site_id(),
        ], false);

        // Corrupt run row.
        $corrupt = wp_generate_uuid4();
        update_option(pp_operate_run_option_name($corrupt), 'garbage', false);

        // Minimal $wpdb enumerating the options store for the GC LIKE query.
        $store =& $GLOBALS['_pp_test_store']['options'];
        $GLOBALS['wpdb'] = new class($store) {
            public string $options = 'wp_options';
            private array $store;
            public function __construct(&$store) { $this->store =& $store; }
            public function esc_like(string $text): string { return $text; }
            public function prepare(string $query, ...$args): string { return $query; }
            public function get_col(string $query): array {
                $out = [];
                foreach (array_keys($this->store) as $name) {
                    if (strpos($name, 'pp_operate_run_') === 0) { $out[] = $name; }
                }
                return $out;
            }
        };

        try {
            $deleted = pp_operate_gc_expired_runs();
            $this->assertSame(2, $deleted, 'exactly the expired + corrupt rows are reaped');
            $this->assertNull($this->readRunState($expired));
            $this->assertNull($this->readRunState($corrupt));
            $this->assertIsArray($this->readRunState($live), 'the live run must survive GC');
        } finally {
            unset($GLOBALS['wpdb']);
            pp_operate_cleanup_run($live);
        }
    }

    // ── Validation Hardening Tests ────────────────────────────────────────

    public function testValidateLoopRunRejectsRetryCountAbove2(): void
    {
        $run = $this->makeCompleteRun();
        $run['retry_count'] = 3;
        $result = pp_validate_loop_run($run);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty(array_filter($result['errors'], fn($e) => stripos($e, 'Retry count') !== false));
    }

    public function testValidateLoopRunAcceptsRetryCount2(): void
    {
        $run = $this->makeCompleteRun();
        $run['retry_count'] = 2;
        $result = pp_validate_loop_run($run);
        $this->assertTrue($result['valid']);
    }

    public function testValidateLoopRunRejectsMissingMobileViewport(): void
    {
        $run = $this->makeCompleteRun();
        $run['playbook'] = 'create-page';
        $run['SCREENSHOT']['screenshot_result'] = ['desktop' => '/tmp/desktop.png', 'mobile' => ''];
        $run['REVIEW']['review_result'] = $this->makeFullChecklistEvaluation('create-page');
        $result = pp_validate_loop_run($run);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty(array_filter($result['errors'], fn($e) => stripos($e, 'mobile') !== false));
    }

    public function testValidateLoopRunRejectsIncompleteChecklist(): void
    {
        $run = $this->makeCompleteRun();
        $run['playbook'] = 'create-page';
        $run['SCREENSHOT']['screenshot_result'] = ['desktop' => '/tmp/d.png', 'mobile' => '/tmp/m.png'];
        // Only evaluate one item — the rest are missing.
        $run['REVIEW']['review_result'] = [['id' => 'sections_present', 'pass' => true]];
        $result = pp_validate_loop_run($run);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty(array_filter($result['errors'], fn($e) => stripos($e, 'hard-gate') !== false));
    }

    public function testValidateLoopRunHandlesMissingPlaybookGracefully(): void
    {
        $run = $this->makeCompleteRun();
        // No playbook field — viewport and checklist checks should be skipped.
        $result = pp_validate_loop_run($run);
        $this->assertTrue($result['valid']);
    }

    // ── Required --run-id Tests ───────────────────────────────────────────

    public function testActionExecuteRequiresRunId(): void
    {
        // pp_operate_check_step with empty/missing run-id should return false,
        // which is the PHP-level check the CLI command uses before proceeding.
        $this->assertFalse(pp_operate_check_step('', 'INSPECT'));
    }

    public function testApplyExecuteRequiresRunId(): void
    {
        // A blank run-id should fail the PREFLIGHT check.
        $this->assertFalse(pp_operate_check_step('', 'PREFLIGHT'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeCompleteRun(): array
    {
        return [
            'INSPECT'    => ['site_state' => ['target' => []]],
            'PLAN'       => ['mutation_plan' => ['steps' => []]],
            'EDIT'       => ['edit_result' => ['actions' => []]],
            'PREFLIGHT'  => ['preflight_result' => ['ok' => true, 'checks' => []]],
            'APPLY'      => ['apply_result' => ['ok' => true]],
            'SCREENSHOT' => ['screenshot_result' => ['paths' => []]],
            'REVIEW'     => ['review_result' => ['checklist' => []]],
            'HANDOFF'    => ['handoff_report' => ['status' => 'VERIFIED']],
        ];
    }

    private function makeFullChecklistEvaluation(string $playbook): array
    {
        $checklists = pp_operate_checklists();
        if (!isset($checklists[$playbook])) {
            return [];
        }
        $result = [];
        foreach ($checklists[$playbook] as $item) {
            $result[] = ['id' => $item['id'], 'pass' => true];
        }
        return $result;
    }

    // ── pp_resolve_component_target tests ──────────────────────────────────

    public function testResolveComponentTargetById(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabbccdd', 'title' => 'Hello']],
            ['component' => 'section', 'props' => ['id' => 'pp-11223344', 'title' => 'About']],
        ];
        $result = pp_resolve_component_target($composition, ['component_id' => 'pp-11223344']);
        $this->assertIsArray($result);
        $this->assertSame(1, $result['index']);
        $this->assertSame('section', $result['component']['component']);
    }

    public function testResolveComponentTargetByIdNotFound(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabbccdd', 'title' => 'Hello']],
        ];
        $result = pp_resolve_component_target($composition, ['component_id' => 'pp-notexist']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('component_not_found', $result->get_error_code());
    }

    public function testResolveComponentTargetByIndex(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabbccdd']],
            ['component' => 'section', 'props' => ['id' => 'pp-11223344']],
        ];
        $result = pp_resolve_component_target($composition, ['component_index' => 0]);
        $this->assertIsArray($result);
        $this->assertSame(0, $result['index']);
        $this->assertSame('hero', $result['component']['component']);
    }

    public function testResolveComponentTargetByIndexOutOfBounds(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabbccdd']],
        ];
        $result = pp_resolve_component_target($composition, ['component_index' => 5]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('index_out_of_bounds', $result->get_error_code());
    }

    public function testResolveComponentTargetEmptyTarget(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabbccdd']],
        ];
        $result = pp_resolve_component_target($composition, []);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('no_target', $result->get_error_code());
    }

    public function testResolveComponentTargetByIdAmbiguousFailsClosed(): void
    {
        // Defense-in-depth (issue 238): duplicate-id state written through a raw,
        // non-validating path must not resolve silently to the first match.
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'dup', 'title' => 'First']],
            ['component' => 'section', 'props' => ['id' => 'dup', 'title' => 'Second']],
        ];
        $result = pp_resolve_component_target($composition, ['component_id' => 'dup']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('component_ambiguous', $result->get_error_code());
        // The matched indexes travel with the error so callers/UI can surface them.
        $this->assertSame([0, 1], $result->get_error_data()['indexes']);
    }

    public function testResolveComponentTargetByUniqueIdStillResolvesAfterDuplicateGuard(): void
    {
        // A single match is unaffected by the multi-match guard.
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'alpha', 'title' => 'A']],
            ['component' => 'section', 'props' => ['id' => 'beta', 'title' => 'B']],
        ];
        $result = pp_resolve_component_target($composition, ['component_id' => 'beta']);
        $this->assertIsArray($result);
        $this->assertSame(1, $result['index']);
        $this->assertSame('section', $result['component']['component']);
    }

    // ── Selector Parser Tests ────────────────────────────────────────────────

    public function testParseCompositionSelectorSimple(): void
    {
        $result = pp_parse_composition_selector('hero.subheading');
        $this->assertIsArray($result);
        $this->assertSame('hero', $result['component_type']);
        $this->assertSame('subheading', $result['target_field']);
        $this->assertArrayNotHasKey('match_field', $result);
        $this->assertArrayNotHasKey('component_id', $result);
    }

    public function testParseCompositionSelectorWithMatch(): void
    {
        $result = pp_parse_composition_selector('section[title="About Us"].body');
        $this->assertIsArray($result);
        $this->assertSame('section', $result['component_type']);
        $this->assertSame('title', $result['match_field']);
        $this->assertSame('About Us', $result['match_value']);
        $this->assertSame('body', $result['target_field']);
    }

    public function testParseCompositionSelectorNested(): void
    {
        $result = pp_parse_composition_selector('grid[title="Features"].items[title="Speed"].text');
        $this->assertIsArray($result);
        $this->assertSame('grid', $result['component_type']);
        $this->assertSame('title', $result['match_field']);
        $this->assertSame('Features', $result['match_value']);
        $this->assertSame('title', $result['nested_match_field']);
        $this->assertSame('Speed', $result['nested_match_value']);
        $this->assertSame('text', $result['target_field']);
    }

    public function testParseCompositionSelectorIdRouting(): void
    {
        $result = pp_parse_composition_selector('hero[id="pp-a1b2c3d4"].subheading');
        $this->assertIsArray($result);
        $this->assertSame('hero', $result['component_type']);
        $this->assertSame('pp-a1b2c3d4', $result['component_id']);
        $this->assertSame('subheading', $result['target_field']);
        $this->assertArrayNotHasKey('match_field', $result);
    }

    public function testParseCompositionSelectorEscapedQuotes(): void
    {
        $result = pp_parse_composition_selector('section[title="Say \\"Hello\\""].body');
        $this->assertIsArray($result);
        $this->assertSame('section', $result['component_type']);
        $this->assertSame('Say "Hello"', $result['match_value']);
        $this->assertSame('body', $result['target_field']);
    }

    public function testParseCompositionSelectorInvalid(): void
    {
        $result = pp_parse_composition_selector('!!!invalid');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_selector', $result->get_error_code());
    }

    public function testParseCompositionSelectorEmpty(): void
    {
        $result = pp_parse_composition_selector('');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_selector', $result->get_error_code());
    }

    // ── Field Editability Map Tests ──────────────────────────────────────────

    /**
     * #509: field editability is DERIVED from schemas, not a hand-list. This
     * matrix asserts the derived field set for representative props across ALL
     * 10 composable components — including fields the retired registry never
     * covered (hero.layout, section.image_url, stats.*, table.caption, embed.*,
     * logos.*) — plus the type/format mapping (#506/#507) and that structural
     * array/object props are excluded.
     *
     * @return array<string, array<string, string|null>>  type => [field => scalar_type], format captured separately.
     */
    private function fieldMap(string $type): array
    {
        $map = [];
        foreach (pp_get_component_fields($type) as $f) {
            $map[$f['name']] = $f['type'];
        }
        return $map;
    }

    private function fieldFormat(string $type, string $name): ?string
    {
        foreach (pp_get_component_fields($type) as $f) {
            if ($f['name'] === $name) {
                return $f['format'] ?? null;
            }
        }
        return null;
    }

    public function testDeriveComponentFieldsMatrixAllComponents(): void
    {
        // hero — full coverage now (registry only had 5 of ~20).
        $hero = $this->fieldMap('hero');
        $this->assertSame('string', $hero['title']);
        $this->assertSame('string', $hero['subheading']);
        $this->assertSame('string', $hero['button_url']);            // was 'url' in the old vocabulary
        $this->assertSame('link_url', $this->fieldFormat('hero', 'button_url')); // #507 format inherited
        $this->assertSame('enum', $hero['layout']);              // never patchable before #509
        $this->assertSame('string', $hero['image_url']);
        $this->assertSame('image_url', $this->fieldFormat('hero', 'image_url'));
        $this->assertSame('number', $hero['image_id']);
        $this->assertArrayHasKey('id', $hero);                   // scalar id now exposed

        // section — body is schema type:string (retired the private 'html').
        $section = $this->fieldMap('section');
        $this->assertSame('string', $section['body']);
        $this->assertSame('enum', $section['theme']);
        $this->assertSame('string', $section['image_url']);
        $this->assertArrayNotHasKey('panel_items', $section);    // array prop, not a scalar
        $this->assertArrayNotHasKey('body_items', $section);     // string-array, not addressable

        // grid — nested item scalars + top-level number/enum.
        $grid = $this->fieldMap('grid');
        $this->assertSame('number', $grid['columns']);
        $this->assertSame('enum', $grid['card_emphasis']);
        $this->assertSame('string', $grid['items[].title']);
        $this->assertSame('link_url', $this->fieldFormat('grid', 'items[].link_url'));
        $this->assertSame('enum', $grid['items[].text_role']);
        $this->assertArrayNotHasKey('items[].bullets', $grid);   // nested array excluded
        $this->assertArrayNotHasKey('items[].style', $grid);     // nested object excluded

        // faq
        $faq = $this->fieldMap('faq');
        $this->assertSame('string', $faq['items[].question']);
        $this->assertSame('string', $faq['items[].answer']);

        // cta
        $cta = $this->fieldMap('cta');
        $this->assertSame('string', $cta['button_text']);
        $this->assertSame('string', $cta['button_url']);
        $this->assertSame('link_url', $this->fieldFormat('cta', 'button_url'));

        // testimonials
        $testi = $this->fieldMap('testimonials');
        $this->assertSame('string', $testi['items[].quote']);
        $this->assertSame('string', $testi['items[].company']);

        // stats — absent from the old registry entirely.
        $stats = $this->fieldMap('stats');
        $this->assertSame('enum', $stats['theme']);
        $this->assertSame('string', $stats['items[].number']);
        $this->assertSame('string', $stats['items[].label']);
        $this->assertArrayNotHasKey('items', $stats);            // the array itself is not a field

        // table — absent before; caption/title/headers/rows.
        $table = $this->fieldMap('table');
        $this->assertSame('string', $table['caption']);
        $this->assertSame('string', $table['title']);
        $this->assertArrayNotHasKey('headers', $table);          // array prop
        $this->assertArrayNotHasKey('rows', $table);             // array prop

        // logos — absent before; nested image_id is number.
        $logos = $this->fieldMap('logos');
        $this->assertSame('string', $logos['items[].label']);
        $this->assertSame('number', $logos['items[].image_id']);
        $this->assertSame('image_url', $this->fieldFormat('logos', 'items[].image_url'));

        // embed — absent before; content/title/theme.
        $embed = $this->fieldMap('embed');
        $this->assertSame('string', $embed['content']);
        $this->assertSame('enum', $embed['theme']);

        // Unknown/unschema'd type returns empty (composability guard intact).
        $this->assertSame([], pp_get_component_fields('nonexistent'));
    }

    public function testEveryComposableComponentHasPatchableFields(): void
    {
        // Drift guard: all 10 composable components must derive at least one
        // patchable field. If a schema loses all scalar props (or the derivation
        // regresses), this catches the silent coverage drop the old hand-list had.
        foreach (['hero', 'section', 'grid', 'faq', 'cta', 'testimonials', 'stats', 'table', 'logos', 'embed'] as $type) {
            $this->assertNotEmpty(pp_get_component_fields($type), "$type must derive patchable fields from its schema");
        }
    }

    /**
     * #509 DRIFT-CATCHER: a NEW scalar schema prop becomes patchable with ZERO
     * registry edits (the disease the retired hand-list carried — every new prop
     * silently widened the coverage gap). We inject a synthetic prop into a
     * component's schema on disk, invalidate the schema cache, and prove the
     * derived field set picks it up automatically and a patch round-trips through
     * the real surface.
     *
     * The opt-out half this test used to carry moved to its own inversion test
     * when #629 retired `patchable: false` — see
     * testARetiredPatchableDeclarationNoLongerExcludesAProp(). One test, one contract.
     */
    public function testDriftCatcherNewSchemaPropIsAutomaticallyPatchable(): void
    {
        // Rewrite the embed schema in the mirrored temp theme, adding one new
        // plain scalar prop that must become patchable with zero registry edits.
        $schemaPath = $this->tempDir . '/components/embed/schema.json';
        $schema = json_decode(file_get_contents($schemaPath), true);
        $schema['props']['synthetic_tagline'] = [
            'type'        => 'string',
            'required'    => false,
            'default'     => '',
            'description' => 'Synthetic prop added by the #509 drift-catcher test.',
        ];
        file_put_contents($schemaPath, json_encode($schema));
        $GLOBALS['_pp_registered_components_invalidate'] = true;

        // Derivation: the new scalar prop is present.
        $fields = $this->fieldMap('embed');
        $this->assertSame('string', $fields['synthetic_tagline'] ?? null, 'new scalar schema prop must derive with zero registry edits');

        // End-to-end: the new prop patches through the real update_component path.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Drift Embed', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'embed', 'props' => ['id' => 'pp-embed001', 'content' => '[shortcode]', 'synthetic_tagline' => 'Old']],
        ]));

        $ok = pp_patch_composition($post_id, 'embed.synthetic_tagline', 'New tagline');
        $this->assertTrue($ok['ok'], 'a brand-new schema prop must be patchable with zero code change');
        $this->assertSame('New tagline', pp_get_composition($post_id)[0]['props']['synthetic_tagline']);
    }

    /**
     * THE RUNTIME SURFACE of the `patchable` retirement (#629), inverted from the
     * pin it replaces. #509 shipped a `"patchable": false` opt-out here and told
     * authors to use it (AI_IMPLEMENTATION_RECIPES Recipe B/D). It was a legal
     * declaration then; when #575 closed the prop definition key set in v1.12.4
     * it left `patchable` off, so from that release a schema following those docs
     * failed CI on pp_schema_definition_errors() while the docs went on
     * instructing it. No schema in this repo ever declared it; #629 deleted the
     * readers instead of widening the key set.
     *
     * Why a synthetic schema rather than a deletion. The definition key set is a
     * repo-CI invariant, NOT a runtime gate (pp_slot_definition_keys' docblock,
     * lib/admin.php), so a schema carrying an unknown key still LOADS at runtime —
     * the key is simply unread. That is exactly the state this test drives: a
     * declaration that used to remove a prop from the patch surface is now inert.
     * Asserting on a locally built array would be a tautology that stays green
     * with the opt-out restored; this fails if either reader comes back.
     *
     * BOTH DELETED READERS are covered, including the `items`-array one, which
     * never had a test while the mechanism was live (it opted a whole nested
     * surface out and nothing pinned it). A third caller of the shared helper,
     * _pp_pick_nested_match_field(), inherits the change: an item sub-prop that
     * declared the retired key is now eligible as a match handle. That is a
     * selector-readability heuristic conferring no write authority, and with no
     * shipped schema declaring the key it has no observable effect, so it is
     * noted here rather than pinned.
     *
     * The SCHEMA surface of the retirement — that the declaration itself is an
     * unknown definition key — is a separate test in SchemaValidationTest.
     */
    public function testARetiredPatchableDeclarationNoLongerExcludesAProp(): void
    {
        // FORM 1 — top-level scalar prop. Exactly the shape Recipe B documented.
        $embedPath = $this->tempDir . '/components/embed/schema.json';
        $embed = json_decode(file_get_contents($embedPath), true);
        $embed['props']['synthetic_locked'] = [
            'type'        => 'string',
            'required'    => false,
            'default'     => '',
            'patchable'   => false,
            'description' => 'Carries a RETIRED patchable:false declaration; must patch anyway.',
        ];
        file_put_contents($embedPath, json_encode($embed));

        // FORM 2 — the `items` array opting its whole nested surface out. faq's
        // items carries question/answer, so items[].question is the observable.
        $faqPath = $this->tempDir . '/components/faq/schema.json';
        $faq = json_decode(file_get_contents($faqPath), true);
        $faq['props']['items']['patchable'] = false;
        file_put_contents($faqPath, json_encode($faq));

        $GLOBALS['_pp_registered_components_invalidate'] = true;

        // THE INVERSION, derivation half. Both were excluded before #629.
        $embedFields = $this->fieldMap('embed');
        $this->assertSame(
            'string',
            $embedFields['synthetic_locked'] ?? null,
            'a retired patchable:false declaration must not exclude a scalar prop (#629)'
        );
        $faqFields = $this->fieldMap('faq');
        $this->assertSame(
            'string',
            $faqFields['items[].question'] ?? null,
            'a retired patchable:false on an items array must not suppress its nested fields (#629)'
        );

        // THE INVERSION, end-to-end half: the previously locked prop now patches
        // through the REAL pp_patch_composition surface and persists, rather than
        // reporting field_not_editable.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Retired Optout', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'embed', 'props' => ['id' => 'pp-embed001', 'content' => '[shortcode]', 'synthetic_locked' => 'x']],
        ]));
        $patched = pp_patch_composition($post_id, 'embed.synthetic_locked', 'y');
        $this->assertTrue($patched['ok'], 'the retired opt-out must no longer report field_not_editable');
        $this->assertSame('y', pp_get_composition($post_id)[0]['props']['synthetic_locked']);
    }

    /**
     * #509 acceptance + Section 14.1 AUTHORING-PATH: patch a field on a component
     * the retired registry never covered (table.caption), authoring the page
     * through the REAL validating surface (pp_update_composition), not a raw
     * _pp_composition meta write, then round-tripping the patch through the real
     * pp_patch_composition path.
     */
    public function testPatchPreviouslyAbsentComponentTableCaptionRoundTrips(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Table Author', 'post_status' => 'publish']);

        // Author through the real VALIDATING surface (the update_composition
        // action runs pp_validate_composition), not a raw meta write (Section 14.1).
        $author = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            ['component' => 'table', 'props' => [
                'title'   => 'Pricing',
                'headers' => ['Plan', 'Price'],
                'rows'    => [['Pro', '$9'], ['Team', '$29']],
                'caption' => 'Old caption',
            ]],
        ]]);
        $this->assertTrue($author['ok'], 'authoring a table through update_composition must validate and persist');

        // caption was NEVER in the old hand-list; it is now patchable by derivation.
        $result = pp_patch_composition($post_id, 'table.caption', 'New accessible caption');
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('New accessible caption', $comp[0]['props']['caption']);
        // Structural array props stay intact through the targeted patch.
        $this->assertSame(['Plan', 'Price'], $comp[0]['props']['headers']);
    }

    /**
     * #509: round-trips on more previously-uncovered surfaces — a top-level field
     * on stats, a nested item field on logos, and a NUMBER-typed field (grid.columns)
     * the old string/url/html vocabulary could not express. Each authors through
     * the real pp_update_composition surface (Section 14.1).
     */
    public function testPatchPreviouslyUncoveredFieldsRoundTrip(): void
    {
        // stats — top-level title (whole component was absent from the registry).
        $stats_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Stats Author', 'post_status' => 'publish']);
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $stats_id, 'composition' => [
            ['component' => 'stats', 'props' => [
                'title' => 'By the numbers',
                'items' => [['number' => '+30', 'label' => 'Years'], ['number' => '100+', 'label' => 'Clients']],
            ]],
        ]])['ok']);
        $this->assertTrue(pp_patch_composition($stats_id, 'stats.title', 'The numbers')['ok']);
        $this->assertSame('The numbers', pp_get_composition($stats_id)[0]['props']['title']);
        // stats nested item field, matched by label.
        $this->assertTrue(pp_patch_composition($stats_id, 'stats.items[label="Clients"].number', '250+')['ok']);
        $this->assertSame('250+', pp_get_composition($stats_id)[0]['props']['items'][1]['number']);

        // logos — nested item label (whole component was absent).
        $logos_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Logos Author', 'post_status' => 'publish']);
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $logos_id, 'composition' => [
            ['component' => 'logos', 'props' => [
                'items' => [['image_url' => '/a.png', 'image_alt' => 'A', 'label' => 'Alpha']],
            ]],
        ]])['ok']);
        $this->assertTrue(pp_patch_composition($logos_id, 'logos.items[label="Alpha"].label', 'Alphabet')['ok']);
        $this->assertSame('Alphabet', pp_get_composition($logos_id)[0]['props']['items'][0]['label']);

        // grid.columns — a NUMBER field; the CLI value is the string "3", accepted
        // by the shared validator's numeric-string rule and persisted.
        $grid_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Grid Cols', 'post_status' => 'publish']);
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $grid_id, 'composition' => [
            ['component' => 'grid', 'props' => [
                'title' => 'Features',
                'columns' => 2,
                'items' => [['title' => 'A', 'text' => 'a'], ['title' => 'B', 'text' => 'b']],
            ]],
        ]])['ok']);
        $this->assertTrue(pp_patch_composition($grid_id, 'grid.columns', '3')['ok']);
        $this->assertSame('3', (string) pp_get_composition($grid_id)[0]['props']['columns']);

        // embed.content — a real field on a previously-absent component (the
        // drift test only exercised a synthetic embed prop).
        $embed_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Embed Author', 'post_status' => 'publish']);
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $embed_id, 'composition' => [
            ['component' => 'embed', 'props' => ['content' => '[old_shortcode]']],
        ]])['ok']);
        $this->assertTrue(pp_patch_composition($embed_id, 'embed.content', '[new_shortcode]')['ok']);
        $this->assertSame('[new_shortcode]', pp_get_composition($embed_id)[0]['props']['content']);
    }

    /**
     * Asserts a patch attempt did NOT succeed (WP_Error or an ok:false action result).
     */
    private function assertPatchRejected($result, string $message): void
    {
        if (is_wp_error($result)) {
            return; // rejected before the action ran (e.g. field_not_editable)
        }
        $this->assertIsArray($result, $message);
        $this->assertFalse($result['ok'] ?? true, $message);
    }

    /**
     * #509 + #506/#507: the derived patch surface INHERITS the shared validator's
     * type/format enforcement — it does not re-validate in the patch layer. These
     * negative cases prove a bad value on a newly-exposed typed field is rejected
     * on the real patch path (not just that the field descriptor carries a type),
     * and that the rejected write never persists.
     */
    public function testPatchInheritsSchemaTypeAndFormatEnforcement(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Enforce', 'post_status' => 'publish']);
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            ['component' => 'grid', 'props' => [
                'title' => 'Features', 'columns' => 2,
                'items' => [['title' => 'A', 'text' => 'a'], ['title' => 'B', 'text' => 'b']],
            ]],
            ['component' => 'hero', 'props' => ['title' => 'Welcome', 'button_url' => '/ok']],
        ]])['ok']);

        // number field: a non-numeric value is rejected (schema type:number).
        $bad_num = pp_patch_composition($post_id, 'grid.columns', 'not-a-number');
        $this->assertPatchRejected($bad_num, 'non-numeric value on a number field must be rejected');
        $this->assertSame(2, (int) pp_get_composition($post_id)[0]['props']['columns'], 'rejected number patch must not persist');

        // link_url format: a disallowed protocol is rejected (format:link_url, #507).
        $bad_url = pp_patch_composition($post_id, 'hero.button_url', 'javascript:alert(1)');
        $this->assertPatchRejected($bad_url, 'a javascript: URL on a link_url field must be rejected');
        $this->assertSame('/ok', pp_get_composition($post_id)[1]['props']['button_url'], 'rejected link_url patch must not persist');
    }

    /**
     * #509: a structural (array/object) prop is excluded from derivation AND
     * rejected on the write path — closing the loop between "not a derived field"
     * and "field_not_editable". table.headers is a required array prop.
     */
    public function testPatchStructuralPropRejectedAsNotEditable(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Struct Reject', 'post_status' => 'publish']);
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            ['component' => 'table', 'props' => ['title' => 'T', 'headers' => ['A'], 'rows' => [['1']]]],
        ]])['ok']);

        $result = pp_patch_composition($post_id, 'table.headers', 'not editable');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('field_not_editable', $result->get_error_code());
    }

    /**
     * #509: inspect surfaces the schema-declared `field_format` alongside
     * `field_type`, so a caller sees the link_url/image_url family the validator
     * enforces. Null for props without a format.
     */
    public function testInspectSurfacesFieldFormat(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Fmt', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'hero', 'props' => ['id' => 'pp-hero0fmt', 'title' => 'Welcome', 'button_url' => '/go']],
        ]));

        $fields = pp_inspect_composition($post_id)[0]['fields'];
        $byField = [];
        foreach ($fields as $f) {
            $byField[$f['field']] = $f;
        }
        $this->assertSame('link_url', $byField['button_url']['field_format'] ?? null);
        $this->assertNull($byField['title']['field_format'], 'a prop with no schema format reports null');
    }

    /**
     * #509: _pp_pick_nested_match_field falls back to the FIRST scalar sub-field
     * when an items schema declares none of the preferred readable handles
     * (title/question/quote/label/name/number). Exercised via a synthetic schema
     * injected into the mirrored temp theme.
     */
    public function testNestedMatchFieldFallsBackToFirstScalar(): void
    {
        // Give faq's items a sub-schema with no preferred handle: only 'heading'
        // (string) and 'detail' (string). The fallback must pick 'heading'.
        $schemaPath = $this->tempDir . '/components/faq/schema.json';
        $schema = json_decode(file_get_contents($schemaPath), true);
        $schema['props']['items']['items'] = [
            'heading' => ['type' => 'string', 'required' => true, 'description' => 'x'],
            'detail'  => ['type' => 'string', 'required' => false, 'description' => 'y'],
        ];
        file_put_contents($schemaPath, json_encode($schema));
        $GLOBALS['_pp_registered_components_invalidate'] = true;

        $this->assertSame('heading', _pp_pick_nested_match_field('faq'));

        // And inspect builds a selector keyed on that fallback field.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Fallback', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'faq', 'props' => ['id' => 'pp-faq0fb', 'items' => [['heading' => 'Q1', 'detail' => 'A1']]]],
        ]));
        $selectors = array_column(pp_inspect_composition($post_id)[0]['fields'], 'selector');
        $this->assertContains('faq.items[heading="Q1"].detail', $selectors);
    }

    public function testInspectCompositionResolvesRealCtaAndGridValues(): void
    {
        // Regression (#120): the editability map previously declared dead
        // selectors (cta.subheading/cta_text/cta_url, grid items[].link) that
        // don't exist on either component, so pp_inspect_composition()
        // always reported current_value: null for them — poisoning the AI
        // context with editable-looking fields that silently no-op on
        // patch. Assert the map now resolves to the real, populated props.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'CTA Grid Inspect', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            [
                'component' => 'cta',
                'props' => [
                    'id' => 'pp-cta0001', 'title' => 'Join now', 'body' => 'Limited spots',
                    'button_text' => 'Sign up', 'button_url' => '/signup',
                ],
            ],
            [
                'component' => 'grid',
                'props' => [
                    'id' => 'pp-grid001',
                    'items' => [
                        ['title' => 'Feature A', 'text' => 'Does a thing', 'link_url' => '/a', 'link_text' => 'Learn more'],
                    ],
                ],
            ],
        ]));

        $result = pp_inspect_composition($post_id);

        $cta = $result[0]['fields'];
        $ctaByField = array_column($cta, 'current_value', 'field');
        $this->assertSame('Join now', $ctaByField['title']);
        $this->assertSame('Limited spots', $ctaByField['body']);
        $this->assertSame('Sign up', $ctaByField['button_text']);
        $this->assertSame('/signup', $ctaByField['button_url']);
        $this->assertArrayNotHasKey('subheading', $ctaByField);
        $this->assertArrayNotHasKey('cta_text', $ctaByField);
        $this->assertArrayNotHasKey('cta_url', $ctaByField);

        $grid = $result[1]['fields'];
        $gridByValue = array_column($grid, 'current_value', 'selector');
        // Grid items are matched by 'title' (see _pp_pick_nested_match_field).
        $this->assertSame('/a', $gridByValue['grid.items[title="Feature A"].link_url']);
        $this->assertSame('Learn more', $gridByValue['grid.items[title="Feature A"].link_text']);
    }

    public function testPatchCtaRealFieldsApply(): void
    {
        // Regression (#120 write path): cta.button_text/button_url were
        // previously unpatchable (field_not_editable) because the map
        // declared cta_text/cta_url instead. Confirm the real fields apply.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'CTA Patch', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'cta', 'props' => ['id' => 'pp-cta9999', 'title' => 'Join', 'button_text' => 'Old', 'button_url' => '/old']],
        ]));

        $result = pp_patch_composition($post_id, 'cta.button_text', 'New');
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('New', $comp[0]['props']['button_text']);

        $result = pp_patch_composition($post_id, 'cta.button_url', '/new');
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('/new', $comp[0]['props']['button_url']);
    }

    public function testPatchCompositionThreadsCasBaseline(): void
    {
        // The operate patch path (wp pp operate patch --post_id=<id> --target=... --value=...) routes
        // through the update_component action. When the caller supplies the freshness
        // baseline, a stale one must conflict and a current one must apply (#13).
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Patch CAS', 'post_status' => 'publish']);
        // Seed through pp_update_composition so the freshness marker exists (→ v1).
        pp_update_composition($post_id, [
            ['component' => 'cta', 'props' => ['title' => 'Join', 'button_text' => 'Old', 'button_url' => '/old']],
        ]);
        $this->assertSame(1, pp_get_composition_marker($post_id)['version']);

        // Stale baseline (0) → composition_conflict, nothing written.
        $stale = pp_patch_composition($post_id, 'cta.button_text', 'New', false, 0);
        $this->assertFalse($stale['ok']);
        $this->assertSame('composition_conflict', $stale['error_code']);
        $this->assertSame('Old', pp_get_composition($post_id)[0]['props']['button_text'], 'Conflict must not write.');

        // Current baseline (1) → applies and bumps.
        $ok = pp_patch_composition($post_id, 'cta.button_text', 'New', false, 1);
        $this->assertTrue($ok['ok']);
        $this->assertSame('New', pp_get_composition($post_id)[0]['props']['button_text']);
        $this->assertSame(2, pp_get_composition_marker($post_id)['version']);

        // Null baseline (default) → back-compat, writes without CAS.
        $nocas = pp_patch_composition($post_id, 'cta.button_text', 'Newer');
        $this->assertTrue($nocas['ok']);
        $this->assertSame('Newer', pp_get_composition($post_id)[0]['props']['button_text']);
    }

    public function testPatchCtaDeadFieldsNowFailNotEditable(): void
    {
        // Regression (#120 write path): before the fix, patching these dead
        // selectors reported success (ok: true) while writing an unused
        // prop the render never reads. Confirm they now correctly fail.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'CTA Dead Fields', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'cta', 'props' => ['id' => 'pp-cta8888', 'title' => 'Join', 'button_text' => 'Go', 'button_url' => '/go']],
        ]));

        foreach (['cta.subheading', 'cta.cta_text', 'cta.cta_url'] as $selector) {
            $result = pp_patch_composition($post_id, $selector, 'value');
            $this->assertInstanceOf(WP_Error::class, $result, "{$selector} should be field_not_editable, not applied.");
            $this->assertSame('field_not_editable', $result->get_error_code());
        }
    }

    public function testPatchGridLinkFieldsApply(): void
    {
        // Regression (#120 write path): grid items[].link_url/link_text
        // were previously unpatchable (the map declared a bare 'link' field
        // that doesn't exist). Confirm the real fields apply.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Grid Patch', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'grid', 'props' => ['id' => 'pp-grid9999', 'items' => [
                ['title' => 'Feature A', 'link_url' => '/old', 'link_text' => 'Old label'],
            ]]],
        ]));

        $result = pp_patch_composition($post_id, 'grid.items[title="Feature A"].link_url', '/new');
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('/new', $comp[0]['props']['items'][0]['link_url']);

        $result = pp_patch_composition($post_id, 'grid.items[title="Feature A"].link_text', 'New label');
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('New label', $comp[0]['props']['items'][0]['link_text']);
    }

    public function testPatchGridDeadLinkFieldNowFailsNotEditable(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Grid Dead Field', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'grid', 'props' => ['id' => 'pp-grid8888', 'items' => [
                ['title' => 'Feature A', 'link_url' => '/a', 'link_text' => 'A'],
            ]]],
        ]));

        $result = pp_patch_composition($post_id, 'grid.items[title="Feature A"].link', 'value');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('field_not_editable', $result->get_error_code());
    }

    public function testGetRegisteredComponentFieldsMatchesPerTypeAccessor(): void
    {
        $registry = pp_get_registered_component_fields();
        $this->assertIsArray($registry);
        $this->assertArrayHasKey('cta', $registry);
        $this->assertArrayHasKey('grid', $registry);
        foreach (array_keys($registry) as $type) {
            $this->assertSame($registry[$type], pp_get_component_fields($type));
        }
    }

    // ── Inspect Composition Tests ────────────────────────────────────────────

    public function testInspectCompositionReturnsTargets(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Inspect Test', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'hero', 'props' => ['id' => 'pp-hero1111', 'title' => 'Welcome', 'subheading' => 'Sub']],
            ['component' => 'section', 'props' => ['id' => 'pp-sect2222', 'title' => 'About', 'body' => '<p>Hi</p>']],
        ]));

        $result = pp_inspect_composition($post_id);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        // Hero component
        $hero = $result[0];
        $this->assertSame('hero', $hero['component_type']);
        $this->assertSame('pp-hero1111', $hero['component_id']);
        $this->assertSame(0, $hero['index']);
        $this->assertNotEmpty($hero['fields']);
        // Check that title and subheading selectors are present
        $selectors = array_column($hero['fields'], 'selector');
        $this->assertTrue(in_array('hero.title', $selectors), 'Hero title selector present');

        // Section component
        $section = $result[1];
        $this->assertSame('section', $section['component_type']);
        $this->assertSame('pp-sect2222', $section['component_id']);
        // body field_type is the schema-native type (#509 retired the private
        // 'html'/'url' vocabulary; body is declared type:string in the schema).
        $body_field = null;
        foreach ($section['fields'] as $f) {
            if ($f['field'] === 'body') {
                $body_field = $f;
                break;
            }
        }
        $this->assertNotNull($body_field);
        $this->assertSame('string', $body_field['field_type']);
        $this->assertSame('<p>Hi</p>', $body_field['current_value']);
    }

    public function testInspectCompositionEmptyPage(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Empty Page', 'post_status' => 'publish']);
        // No composition set — pp_get_composition returns []
        $result = pp_inspect_composition($post_id);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testInspectCompositionUnmappedType(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Custom Type', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'testimonial', 'props' => ['id' => 'pp-test3333', 'quote' => 'Great!']],
        ]));

        $result = pp_inspect_composition($post_id);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('testimonial', $result[0]['component_type']);
        $this->assertSame([], $result[0]['fields']);
    }

    // ── Patch Composition Tests ──────────────────────────────────────────────

    private function createPatchTestPage(): int
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Patch Test', 'post_status' => 'publish']);
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'pp-hero0001', 'title' => 'Welcome', 'subheading' => 'Old Subtitle']],
            ['component' => 'section', 'props' => ['id' => 'pp-sect0002', 'title' => 'About', 'body' => '<p>About us</p>']],
            ['component' => 'grid', 'props' => ['id' => 'pp-grid0003', 'title' => 'Features', 'items' => [
                ['title' => 'Speed', 'text' => 'Fast performance'],
                ['title' => 'Scale', 'text' => 'Grows with you'],
            ]]],
        ];
        update_post_meta($post_id, '_pp_composition', json_encode($composition));
        return $post_id;
    }

    public function testPatchTopLevelFieldPreview(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'hero.subheading', 'New Subtitle', true);
        $this->assertIsArray($result);
        // Preview should have action name and before/after data
        $this->assertSame('update_component', $result['action']);
        $this->assertArrayHasKey('before', $result);
        $this->assertArrayHasKey('after', $result);
        // Verify the value was NOT written
        $comp = pp_get_composition($post_id);
        $this->assertSame('Old Subtitle', $comp[0]['props']['subheading']);
    }

    public function testPatchTopLevelFieldApply(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'hero.subheading', 'New Subtitle');
        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $this->assertSame('update_component', $result['action']);
        // Verify the value was written
        $comp = pp_get_composition($post_id);
        $this->assertSame('New Subtitle', $comp[0]['props']['subheading']);
        // Other props should be unchanged
        $this->assertSame('Welcome', $comp[0]['props']['title']);
    }

    public function testPatchNestedItemApply(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'grid[title="Features"].items[title="Speed"].text', 'Blazing fast');
        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        // Verify the nested item was updated
        $comp = pp_get_composition($post_id);
        $items = $comp[2]['props']['items'];
        $this->assertSame('Blazing fast', $items[0]['text']);
        // Other item should be unchanged
        $this->assertSame('Grows with you', $items[1]['text']);
    }

    public function testPatchZeroMatchesFails(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'cta.title', 'No CTA Here');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('component_not_found', $result->get_error_code());
    }

    public function testPatchMultiMatchFails(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Multi Match', 'post_status' => 'publish']);
        $composition = [
            ['component' => 'section', 'props' => ['id' => 'pp-sect0001', 'title' => 'Part 1', 'body' => 'A']],
            ['component' => 'section', 'props' => ['id' => 'pp-sect0002', 'title' => 'Part 2', 'body' => 'B']],
        ];
        update_post_meta($post_id, '_pp_composition', json_encode($composition));
        // Selector without match field should hit multiple sections
        $result = pp_patch_composition($post_id, 'section.body', 'New body');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('multiple_components', $result->get_error_code());
        $this->assertStringContainsString('pp-sect0001', $result->get_error_message());
        $this->assertStringContainsString('pp-sect0002', $result->get_error_message());
    }

    public function testPatchFieldNotEditableFails(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'hero.background_image', '/img/new.jpg');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('field_not_editable', $result->get_error_code());
        $this->assertStringContainsString('title', $result->get_error_message()); // lists editable fields
    }

    public function testPatchNestedItemNotFoundFails(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'grid[title="Features"].items[title="Nonexistent"].text', 'Value');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('nested_item_not_found', $result->get_error_code());
    }

    public function testPatchNestedItemMultiMatchFails(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Dupe Items', 'post_status' => 'publish']);
        $composition = [
            ['component' => 'grid', 'props' => ['id' => 'pp-grid0001', 'title' => 'Cards', 'items' => [
                ['title' => 'Same', 'text' => 'First'],
                ['title' => 'Same', 'text' => 'Second'],
            ]]],
        ];
        update_post_meta($post_id, '_pp_composition', json_encode($composition));
        $result = pp_patch_composition($post_id, 'grid[title="Cards"].items[title="Same"].text', 'Updated');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('nested_item_multi_match', $result->get_error_code());
        $this->assertStringContainsString('0', $result->get_error_message());
        $this->assertStringContainsString('1', $result->get_error_message());
    }

    // ── Coverage gap tests (generated by /ship Step 7) ─────────────────────

    public function testResolveComponentTargetNegativeIndex(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabbccdd']],
        ];
        $result = pp_resolve_component_target($composition, ['component_index' => -1]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('index_out_of_bounds', $result->get_error_code());
    }

    public function testParseCompositionSelectorMissingDotAfterType(): void
    {
        // 'hero' alone has no dot separator — should fail
        $result = pp_parse_composition_selector('hero');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_selector', $result->get_error_code());
    }

    public function testParseCompositionSelectorMissingDotAfterBracket(): void
    {
        // Bracket match but no dot after it
        $result = pp_parse_composition_selector('section[title="About"]');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_selector', $result->get_error_code());
    }

    public function testParseCompositionSelectorItemsWithoutBracket(): void
    {
        // items keyword but no bracket match
        $result = pp_parse_composition_selector('grid.items');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_selector', $result->get_error_code());
    }

    public function testParseCompositionSelectorMissingDotAfterNestedMatch(): void
    {
        // Nested items match but no trailing dot+field
        $result = pp_parse_composition_selector('grid.items[title="Speed"]');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_selector', $result->get_error_code());
    }

    public function testParseCompositionSelectorTrailingGarbage(): void
    {
        // Extra text after valid field
        $result = pp_parse_composition_selector('hero.subheading.extra');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_selector', $result->get_error_code());
    }

    public function testParseCompositionSelectorEscapedBackslash(): void
    {
        // Escaped backslash in value: \\
        $result = pp_parse_composition_selector('section[title="Back\\\\Slash"].body');
        $this->assertIsArray($result);
        $this->assertSame('Back\\Slash', $result['match_value']);
        $this->assertSame('body', $result['target_field']);
    }

    public function testParseBracketMatchInvalidFieldName(): void
    {
        // Field starts with a number — invalid
        $result = pp_parse_composition_selector('section[123="bad"].body');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_selector', $result->get_error_code());
    }

    public function testParseBracketMatchMissingEqualsQuote(): void
    {
        // Missing =" — just field and value without quotes
        $result = pp_parse_composition_selector('section[title].body');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_selector', $result->get_error_code());
    }

    public function testInspectCompositionGridWithNestedItems(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Grid Inspect', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'grid', 'props' => ['id' => 'pp-grid9999', 'title' => 'Features', 'items' => [
                ['title' => 'Speed', 'text' => 'Fast performance'],
                ['title' => 'Scale', 'text' => 'Grows with you'],
            ]]],
        ]));

        $result = pp_inspect_composition($post_id);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('grid', $result[0]['component_type']);

        // Should have nested item selectors
        $selectors = array_column($result[0]['fields'], 'selector');
        $this->assertTrue(
            count(array_filter($selectors, fn($s) => str_contains($s, 'items['))) >= 2,
            'Grid inspect should produce nested item selectors'
        );
        // Check a specific nested selector pattern
        $speed_fields = array_filter($result[0]['fields'], fn($f) => $f['current_value'] === 'Fast performance');
        $this->assertNotEmpty($speed_fields, 'Should find the Speed item text field');
    }

    public function testInspectCompositionComponentWithoutTitle(): void
    {
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'No Title', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'hero', 'props' => ['id' => 'pp-notitle1', 'subheading' => 'Just a subheading']],
        ]));

        $result = pp_inspect_composition($post_id);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Without title prop, should produce simple selectors (hero.subheading, not hero[title="..."].subheading)
        $selectors = array_column($result[0]['fields'], 'selector');
        foreach ($selectors as $sel) {
            $this->assertStringNotContainsString('[title=', $sel, 'No title bracket match when title prop is absent');
        }
    }

    public function testPatchWithIdBasedSelector(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'hero[id="pp-hero0001"].subheading', 'ID Patched');
        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('ID Patched', $comp[0]['props']['subheading']);
    }

    public function testPatchMatchFieldZeroMatches(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'section[title="Nonexistent"].body', 'No match');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('component_not_found', $result->get_error_code());
    }

    public function testPatchNestedItemPreview(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'grid[title="Features"].items[title="Speed"].text', 'Preview Value', true);
        $this->assertIsArray($result);
        $this->assertSame('update_component', $result['action']);
        $this->assertArrayHasKey('before', $result);
        $this->assertArrayHasKey('after', $result);
        // Verify the value was NOT written
        $comp = pp_get_composition($post_id);
        $this->assertSame('Fast performance', $comp[2]['props']['items'][0]['text']);
    }

    // ── Inspect composition: style slots ─────────────────────────────────

    public function testInspectCompositionIncludesStyleSlots(): void
    {
        $post_id = pp_create_page('Style inspect test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_inspect_composition($post_id);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('style_slots', $result[0]);
        $this->assertCount(49, $result[0]['style_slots']); // hero has 49 slots (41 + 3 primary-button fill slots, issue 514; + the hover fill slot, issue 530; + --hero-heading-measure, issue 578; + --hero-heading-margin-bottom and the two primary ring slots --hero-button-border / --hero-button-hover-border, issue 584)

        // Verify slot structure.
        $first_slot = $result[0]['style_slots'][0];
        $this->assertArrayHasKey('slot', $first_slot);
        $this->assertArrayHasKey('type', $first_slot);
        $this->assertArrayHasKey('default', $first_slot);
        $this->assertArrayHasKey('current', $first_slot);
        $this->assertNull($first_slot['current']); // no overrides set
    }

    public function testInspectCompositionShowsCurrentStyleValues(): void
    {
        $post_id = pp_create_page('Style inspect test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello'],
             'style' => ['--hero-bg' => '#1a1a2e']],
        ]);

        $result = pp_inspect_composition($post_id);
        $slots = $result[0]['style_slots'];

        // Find the --hero-bg slot.
        $bg_slot = null;
        foreach ($slots as $s) {
            if ($s['slot'] === '--hero-bg') {
                $bg_slot = $s;
                break;
            }
        }
        $this->assertNotNull($bg_slot);
        $this->assertSame('#1a1a2e', $bg_slot['current']);
        $this->assertSame('var(--color-bg)', $bg_slot['default']);
    }

    public function testInspectCompositionShowsActiveRecipe(): void
    {
        $post_id = pp_create_page('Recipe inspect test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello'],
             'style' => ['__recipe' => 'dark-spacious', '--hero-bg' => '#1a1a2e']],
        ]);

        $result = pp_inspect_composition($post_id);
        $this->assertSame('dark-spacious', $result[0]['active_recipe']);
    }

    public function testInspectCompositionNoRecipeWhenNone(): void
    {
        $post_id = pp_create_page('No recipe test');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hello']],
        ]);

        $result = pp_inspect_composition($post_id);
        $this->assertNull($result[0]['active_recipe']);
    }

    // ── Run Snapshot / Touched-Key / Site-Identity Tests (#101) ────────────

    public function testCreateRunWritesSiteIdentity(): void
    {
        $run_id = pp_operate_create_run();
        $data = get_option(pp_operate_run_option_name($run_id), null);
        $this->assertArrayHasKey('site_id', $data);
        $this->assertSame(pp_operate_site_id(), $data['site_id']);
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordTokenSnapshotFreezesOverrides(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_record_token_snapshot($run_id, ['--color-accent' => '#111111']));
        $this->assertSame(['--color-accent' => '#111111'], pp_operate_get_token_snapshot($run_id));
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordTokenSnapshotIsFirstWriteWins(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_token_snapshot($run_id, ['--color-accent' => '#111111']);
        // A second capture must NOT move the baseline.
        $this->assertTrue(pp_operate_record_token_snapshot($run_id, ['--color-accent' => '#999999']));
        $this->assertSame(['--color-accent' => '#111111'], pp_operate_get_token_snapshot($run_id));
        pp_operate_cleanup_run($run_id);
    }

    public function testGetTokenSnapshotNullWhenNeverRecorded(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertNull(pp_operate_get_token_snapshot($run_id));
        pp_operate_cleanup_run($run_id);
    }

    public function testGetTokenSnapshotReturnsEmptyArrayForValidEmptyCapture(): void
    {
        // A run started from a clean base legitimately captures []. This MUST be
        // distinguishable from "no snapshot" (null) so restore can clear-all safely.
        $run_id = pp_operate_create_run();
        pp_operate_record_token_snapshot($run_id, []);
        $this->assertSame([], pp_operate_get_token_snapshot($run_id));
        pp_operate_cleanup_run($run_id);
    }

    public function testGetTokenSnapshotNullForExpiredRun(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_token_snapshot($run_id, ['--color-accent' => '#111111']);
        $data = get_option(pp_operate_run_option_name($run_id), null);
        $data['created_at'] = time() - 10800; // 3h ago
        update_option(pp_operate_run_option_name($run_id), $data, false);
        $this->assertNull(pp_operate_get_token_snapshot($run_id));
        pp_operate_cleanup_run($run_id);
    }

    public function testGetTokenSnapshotNullForForeignSiteIdentity(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_token_snapshot($run_id, ['--color-accent' => '#111111']);
        // Simulate reading the run under a different install (foreign site identity).
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://other-install.example';
        $this->assertNull(pp_operate_get_token_snapshot($run_id));
        $this->assertFalse(pp_operate_check_step($run_id, 'INSPECT'));
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordTouchedTokensDedupsAndReturnsTrue(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_record_touched_tokens($run_id, ['--color-accent', '--color-accent-hover']));
        $this->assertTrue(pp_operate_record_touched_tokens($run_id, ['--color-accent-hover', '--color-text']));
        $this->assertSame(
            ['--color-accent', '--color-accent-hover', '--color-text'],
            pp_operate_get_touched_tokens($run_id)
        );
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordTouchedTokensReturnsFalseForMissingFile(): void
    {
        // Valid UUID, no state file.
        $this->assertFalse(pp_operate_record_touched_tokens('00000000-0000-4000-8000-000000000000', ['--color-accent']));
    }

    public function testRecordTouchedTokensReturnsFalseForForeignSiteIdentity(): void
    {
        $run_id = pp_operate_create_run();
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://other-install.example';
        $this->assertFalse(pp_operate_record_touched_tokens($run_id, ['--color-accent']));
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';
        pp_operate_cleanup_run($run_id);
    }

    public function testGetTouchedTokensNullWhenAbsentEmptyWhenRecordedEmpty(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertNull(pp_operate_get_touched_tokens($run_id));
        pp_operate_record_touched_tokens($run_id, []);
        $this->assertSame([], pp_operate_get_touched_tokens($run_id));
        pp_operate_cleanup_run($run_id);
    }

    public function testRunRollbackableTrueOnlyWithSnapshot(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertFalse(pp_operate_run_rollbackable($run_id));
        pp_operate_record_token_snapshot($run_id, []);
        $this->assertTrue(pp_operate_run_rollbackable($run_id));
        pp_operate_cleanup_run($run_id);
    }

    // ── Composition touched-post + content snapshot + run restore (#133) ────

    public function testTouchedPostIdsDedupeAndFailClosed(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertNull(pp_operate_get_touched_post_ids($run_id), 'null before any record');
        $this->assertTrue(pp_operate_record_touched_post_id($run_id, 701));
        $this->assertTrue(pp_operate_record_touched_post_id($run_id, 701), 'dedupe: same post twice is a no-op');
        $this->assertTrue(pp_operate_record_touched_post_id($run_id, 702));
        $this->assertSame([701, 702], pp_operate_get_touched_post_ids($run_id));
        // Fail-closed on an unusable run.
        $this->assertFalse(pp_operate_record_touched_post_id('00000000-0000-4000-8000-000000000000', 701));
        pp_operate_cleanup_run($run_id);
    }

    public function testCompositionContentSnapshotFirstWriteWins(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertNull(pp_operate_get_composition_content_snapshot($run_id, 701));
        $first  = [['component' => 'hero', 'props' => ['title' => 'pre-run']]];
        $second = [['component' => 'hero', 'props' => ['title' => 'later']]];
        $this->assertTrue(pp_operate_record_composition_content_snapshot($run_id, 701, $first));
        $this->assertTrue(pp_operate_record_composition_content_snapshot($run_id, 701, $second));
        $this->assertSame($first, pp_operate_get_composition_content_snapshot($run_id, 701), 'first write wins');
        // An empty pre-run composition is a legitimate baseline ([], not null).
        $this->assertTrue(pp_operate_record_composition_content_snapshot($run_id, 702, []));
        $this->assertSame([], pp_operate_get_composition_content_snapshot($run_id, 702));
        pp_operate_cleanup_run($run_id);
    }

    // ── Atomic preflight unlock + restore baseline (#241) ──────────────────

    public function testRecordPreflightCommitsCoverageAndContentSnapshotTogether(): void
    {
        // #241: the PREFLIGHT coverage (which unlocks mutating gates) and the
        // composition content snapshot (the run-scoped restore baseline) are written
        // in ONE mutate_state call, so the gate never unlocks without its baseline.
        $run_id  = pp_operate_create_run();
        $content = [['component' => 'hero', 'props' => ['title' => 'pre-run']]];
        $marker  = ['version' => 2, 'hash' => 'h241'];

        $this->assertTrue(pp_operate_record_preflight($run_id, 42, ['--color-accent' => '#111'], $marker, $content));

        // Both facets landed in the same state: coverage unlocks, baseline exists.
        $this->assertTrue(pp_operate_preflight_covers($run_id, 42), 'coverage recorded');
        $this->assertSame($content, pp_operate_get_composition_content_snapshot($run_id, 42), 'restore baseline recorded');
        $this->assertSame($marker, pp_operate_get_composition_snapshot($run_id, 42), 'freshness marker recorded');
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightContentSnapshotIsFirstWriteWins(): void
    {
        // #241: a preflight re-run in the same run must not overwrite the true pre-run
        // content baseline with a (possibly post-mutation) one — first-write-wins,
        // unlike the LAST-write-wins freshness marker recorded in the same call.
        $run_id = pp_operate_create_run();
        $first  = [['component' => 'hero', 'props' => ['title' => 'pre-run']]];
        $second = [['component' => 'hero', 'props' => ['title' => 'after-mutation']]];

        pp_operate_record_preflight($run_id, 4, [], ['version' => 1, 'hash' => 'first'], $first);
        pp_operate_record_preflight($run_id, 4, [], ['version' => 2, 'hash' => 'second'], $second);

        $this->assertSame($first, pp_operate_get_composition_content_snapshot($run_id, 4), 'content baseline is first-write-wins');
        $this->assertSame(['version' => 2, 'hash' => 'second'], pp_operate_get_composition_snapshot($run_id, 4), 'marker is last-write-wins');
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightEmptyContentSnapshotIsRecorded(): void
    {
        // An intentionally empty page freezes [] as its baseline (load-bearing: a
        // run-scoped restore reverts to the empty page, distinct from "no snapshot").
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 7, [], ['version' => 1, 'hash' => 'h'], []);
        $this->assertSame([], pp_operate_get_composition_content_snapshot($run_id, 7));
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightNoContentSnapshotWhenNull(): void
    {
        // Back-compat + fail-closed caller contract: a null content arg (site grain,
        // or a corrupt composition the caller already failed the preflight on) records
        // no content snapshot at all.
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 8, [], ['version' => 1, 'hash' => 'h'], null);
        $this->assertNull(pp_operate_get_composition_content_snapshot($run_id, 8));
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightSiteGrainRecordsNoContentSnapshot(): void
    {
        // A no-post (site-grain) preflight has no composition target: even if a content
        // array is somehow passed, no per-post content snapshot is written.
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, null, [], null, [['component' => 'hero']]);
        $data = get_option(pp_operate_run_option_name($run_id), null);
        $this->assertArrayNotHasKey('composition_content_snapshot', $data);
        pp_operate_cleanup_run($run_id);
    }

    public function testRunScopedRestoreRevertsTouchedPostsAndLeavesOthersUntouched(): void
    {
        // Acceptance criterion #3: preflight → two composition mutations → restore-by-run
        // reverts both pages; a page mutated by a DIFFERENT run is untouched.
        $GLOBALS['_pp_test_store']['post_meta'] = [];

        // Two pages this run will touch, plus a third owned by another run.
        pp_update_composition(801, [['component' => 'hero', 'props' => ['title' => 'A0']]]);
        pp_update_composition(802, [['component' => 'hero', 'props' => ['title' => 'B0']]]);
        pp_update_composition(803, [['component' => 'hero', 'props' => ['title' => 'C0']]]);

        // This run: freeze the pre-apply content for 801 and 802 (as preflight does).
        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, 801, pp_get_composition(801));
        pp_operate_record_composition_content_snapshot($run_id, 802, pp_get_composition(802));

        // Two mutations by this run.
        pp_update_composition(801, [['component' => 'hero', 'props' => ['title' => 'A1']]]);
        pp_operate_record_touched_post_id($run_id, 801);
        pp_update_composition(802, [['component' => 'hero', 'props' => ['title' => 'B1']]]);
        pp_operate_record_touched_post_id($run_id, 802);

        // A different run mutates 803.
        pp_update_composition(803, [['component' => 'hero', 'props' => ['title' => 'C1']]]);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertTrue($report['ok']);
        $this->assertCount(2, $report['reverted']);
        $this->assertSame([], $report['skipped']);

        // Both touched pages reverted to their pre-run content.
        $this->assertSame('A0', pp_get_composition(801)[0]['props']['title']);
        $this->assertSame('B0', pp_get_composition(802)[0]['props']['title']);
        // The page owned by a different run is untouched.
        $this->assertSame('C1', pp_get_composition(803)[0]['props']['title']);

        pp_operate_cleanup_run($run_id);
    }

    public function testRunScopedRestoreFailsClosedWithoutTouchedPosts(): void
    {
        $run_id = pp_operate_create_run();
        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertFalse($report['ok']);
        $this->assertSame('no_touched_post_ids', $report['error']);
        pp_operate_cleanup_run($run_id);
    }

    public function testRunScopedRestoreSkipsPostMissingSnapshot(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        pp_update_composition(811, [['component' => 'hero', 'props' => ['title' => 'X0']]]);
        $run_id = pp_operate_create_run();
        // Touched but no content snapshot recorded → skipped, nothing reverted.
        pp_update_composition(811, [['component' => 'hero', 'props' => ['title' => 'X1']]]);
        pp_operate_record_touched_post_id($run_id, 811);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['reverted']);
        $this->assertCount(1, $report['skipped']);
        $this->assertSame('no_snapshot', $report['skipped'][0]['reason']);
        $this->assertSame('X1', pp_get_composition(811)[0]['props']['title'], 'unchanged when snapshot missing');
        pp_operate_cleanup_run($run_id);
    }

    // ── run-scoped restore reports current-rule findings (#236) ─────────────
    // Parity with the restore_composition action (#233): the run-scoped CLI restore
    // never blocks a rollback on a rule that landed after the snapshot, but each
    // reverted post must carry current-rule findings so the CLI can warn instead of
    // reporting a bare success. Snapshots are seeded through pp_update_composition (the
    // non-validating writer) — the only way a rule-violating composition reaches a
    // preflight snapshot, exactly as a legacy row would have.

    public function testRunScopedRestoreReportsFindingsForRuleViolatingSnapshot(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        // Chrome in the composition is legal before #223; freeze it as the pre-run baseline.
        pp_update_composition(831, [
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'H0']],
        ]);
        $run_id = pp_operate_create_run();
        pp_operate_record_composition_content_snapshot($run_id, 831, pp_get_composition(831));
        // The run mutates it to a clean, chrome-free composition.
        pp_update_composition(831, [['component' => 'hero', 'props' => ['title' => 'H1']]]);
        pp_operate_record_touched_post_id($run_id, 831);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertTrue($report['ok']);
        $this->assertCount(1, $report['reverted']);
        // The chrome snapshot is restored verbatim (never stripped)...
        $this->assertSame('nav', pp_get_composition(831)[0]['component'], 'chrome survives the revert');
        // ...and the reverted entry carries the current-rule finding rather than a bare ok.
        $this->assertArrayHasKey('findings', $report['reverted'][0]);
        $this->assertContains('template_owned_component', array_column($report['reverted'][0]['findings'], 'type'));
        // The decision seam the CLI warns on counts this post.
        $this->assertSame(1, pp_operate_restore_run_finding_count($report));
        pp_operate_cleanup_run($run_id);
    }

    public function testRunScopedRestoreReportsEmptyFindingsForCleanSnapshot(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        pp_update_composition(832, [['component' => 'hero', 'props' => ['title' => 'C0']]]);
        $run_id = pp_operate_create_run();
        pp_operate_record_composition_content_snapshot($run_id, 832, pp_get_composition(832));
        pp_update_composition(832, [['component' => 'hero', 'props' => ['title' => 'C1']]]);
        pp_operate_record_touched_post_id($run_id, 832);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertTrue($report['ok']);
        $this->assertCount(1, $report['reverted']);
        $this->assertArrayHasKey('findings', $report['reverted'][0]);
        $this->assertSame([], $report['reverted'][0]['findings'], 'a clean snapshot reports no findings');
        $this->assertSame(0, pp_operate_restore_run_finding_count($report), 'clean restore → CLI does not warn');
        pp_operate_cleanup_run($run_id);
    }

    public function testRunScopedRestoreFindingsMixedCleanDirtyAndSkipped(): void
    {
        // One dirty (chrome) snapshot, one clean snapshot, one touched post with no
        // snapshot (skipped). Verifies findings are per-post, skipped posts carry no
        // findings key, and the CLI count ignores skipped entries.
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        pp_update_composition(841, [
            ['component' => 'footer', 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'D0']],
        ]);
        pp_update_composition(842, [['component' => 'hero', 'props' => ['title' => 'E0']]]);
        $run_id = pp_operate_create_run();
        pp_operate_record_composition_content_snapshot($run_id, 841, pp_get_composition(841));
        pp_operate_record_composition_content_snapshot($run_id, 842, pp_get_composition(842));
        // Mutate + touch all three; 843 has no snapshot so it will be skipped.
        pp_update_composition(841, [['component' => 'hero', 'props' => ['title' => 'D1']]]);
        pp_operate_record_touched_post_id($run_id, 841);
        pp_update_composition(842, [['component' => 'hero', 'props' => ['title' => 'E1']]]);
        pp_operate_record_touched_post_id($run_id, 842);
        pp_update_composition(843, [['component' => 'hero', 'props' => ['title' => 'F1']]]);
        pp_operate_record_touched_post_id($run_id, 843);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertCount(2, $report['reverted']);
        $this->assertCount(1, $report['skipped']);
        $this->assertSame(843, $report['skipped'][0]['post_id']);
        $this->assertArrayNotHasKey('findings', $report['skipped'][0], 'skipped posts carry no findings');

        $byPost = [];
        foreach ($report['reverted'] as $entry) {
            $byPost[$entry['post_id']] = $entry;
        }
        $this->assertContains('template_owned_component', array_column($byPost[841]['findings'], 'type'));
        $this->assertSame([], $byPost[842]['findings'], 'clean post reports no findings');
        // Only the dirty reverted post is counted — clean and skipped are excluded.
        $this->assertSame(1, pp_operate_restore_run_finding_count($report));
        pp_operate_cleanup_run($run_id);
    }

    // ── pp_operate_restore_run_finding_count() unit seam (#236) ─────────────
    // The CLI warns iff this count > 0. Pure over the report shape so the warn
    // decision is testable without a WP-CLI harness (mirrors _restore_run_complete).

    public function testFindingCountZeroWhenNoRevertedPostHasFindings(): void
    {
        $report = ['ok' => true, 'error' => null, 'skipped' => [], 'reverted' => [
            ['post_id' => 1, 'changed' => true, 'findings' => []],
            ['post_id' => 2, 'changed' => false, 'findings' => []],
        ]];
        $this->assertSame(0, pp_operate_restore_run_finding_count($report));
    }

    public function testFindingCountCountsPostsNotTotalFindings(): void
    {
        // Two posts with findings (one carries two findings) → count is 2 POSTS, not 3.
        $report = ['ok' => true, 'error' => null, 'skipped' => [], 'reverted' => [
            ['post_id' => 1, 'changed' => true, 'findings' => [
                ['type' => 'template_owned_component', 'severity' => 'warning'],
                ['type' => 'invalid_style_value', 'severity' => 'error'],
            ]],
            ['post_id' => 2, 'changed' => true, 'findings' => []],
            ['post_id' => 3, 'changed' => true, 'findings' => [
                ['type' => 'template_owned_component', 'severity' => 'warning'],
            ]],
        ]];
        $this->assertSame(2, pp_operate_restore_run_finding_count($report));
    }

    public function testFindingCountIgnoresSkippedAndMissingRevertedKey(): void
    {
        // A report with no reverted key (fail-closed shape) counts zero, and skipped
        // entries are never inspected for findings.
        $this->assertSame(0, pp_operate_restore_run_finding_count(
            ['ok' => false, 'error' => 'no_touched_post_ids', 'reverted' => [], 'skipped' => []]
        ));
        $this->assertSame(0, pp_operate_restore_run_finding_count(['ok' => true]));
    }

    // ── restore-composition completeness verdict (#242) ─────────────────────
    // The CLI fails closed on an incomplete restore (non-zero exit) so a machine
    // consumer never reads a partial restore as a full one. The verdict is the
    // decision seam pp_operate_restore_run_complete() the CLI branches on.

    public function testRestoreRunCompleteTrueWhenAllTouchedPostsReverted(): void
    {
        $report = ['ok' => true, 'error' => null,
            'reverted' => [['post_id' => 1, 'changed' => true]], 'skipped' => []];
        $this->assertTrue(pp_operate_restore_run_complete($report));
    }

    public function testRestoreRunCompleteFalseWhenAnyPostSkipped(): void
    {
        // A missing snapshot / write failure leaves a touched post in `skipped`:
        // the restore is INCOMPLETE even though some posts reverted.
        $report = ['ok' => true, 'error' => null,
            'reverted' => [['post_id' => 1, 'changed' => true]],
            'skipped'  => [['post_id' => 2, 'reason' => 'no_snapshot']]];
        $this->assertFalse(pp_operate_restore_run_complete($report));
    }

    public function testRestoreRunCompleteFalseWhenNoUsableRecord(): void
    {
        // ok:false (no usable touched-post record) is also incomplete.
        $report = ['ok' => false, 'error' => 'no_touched_post_ids',
            'reverted' => [], 'skipped' => []];
        $this->assertFalse(pp_operate_restore_run_complete($report));
    }

    public function testRestoreRunCompleteVerdictMatchesRealSkipReport(): void
    {
        // End-to-end over the real report producer: a touched post with no snapshot
        // is skipped, so the run-scoped restore is judged incomplete.
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        pp_update_composition(821, [['component' => 'hero', 'props' => ['title' => 'Y0']]]);
        $run_id = pp_operate_create_run();
        pp_update_composition(821, [['component' => 'hero', 'props' => ['title' => 'Y1']]]);
        pp_operate_record_touched_post_id($run_id, 821);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertFalse(pp_operate_restore_run_complete($report), 'skipped post → incomplete');
        pp_operate_cleanup_run($run_id);
    }

    public function testRestoreRunCompleteVerdictMatchesRealCleanReport(): void
    {
        // A run that snapshots its touched post reverts cleanly → complete.
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        pp_update_composition(822, [['component' => 'hero', 'props' => ['title' => 'Z0']]]);
        $run_id = pp_operate_create_run();
        pp_operate_record_composition_content_snapshot($run_id, 822, pp_get_composition(822));
        pp_update_composition(822, [['component' => 'hero', 'props' => ['title' => 'Z1']]]);
        pp_operate_record_touched_post_id($run_id, 822);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertTrue(pp_operate_restore_run_complete($report), 'all reverted → complete');
        $this->assertSame('Z0', pp_get_composition(822)[0]['props']['title']);
        pp_operate_cleanup_run($run_id);
    }

    /**
     * #236 findings are the same engine restore_composition reports through, so #621's
     * exhaustiveness has to arrive here too — this is the report a `wp pp apply
     * restore-composition` operator reads after a batch rollback. Section 14.1: driven
     * through the real producer (pp_operate_restore_run_compositions), not the validator.
     *
     * The snapshot band carries a retired prop name AND a dead style slot. Before #621
     * the rollback report named the prop and the operator discovered the slot only on a
     * later pass — on a rollback, where the whole point is "tell me what I just put
     * back", one-problem-at-a-time is the report failing at its job. The rollback itself
     * is still never blocked (#233/#236).
     */
    public function testRunRollbackFindingsNameEveryProblemInARestoredBand(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        pp_update_composition(823, [
            ['component' => 'cta', 'props' => [
                'title' => 'Ready?', 'button_text' => 'Go', 'button_url' => '/go', 'cta_text' => 'Go',
            ], 'style' => ['--cta-not-a-slot' => 'red']],
        ]);
        $run_id = pp_operate_create_run();
        pp_operate_record_composition_content_snapshot($run_id, 823, pp_get_composition(823));
        pp_update_composition(823, [['component' => 'hero', 'props' => ['title' => 'Overwritten']]]);
        pp_operate_record_touched_post_id($run_id, 823);

        $report = pp_operate_restore_run_compositions($run_id);

        $this->assertTrue($report['ok'], 'a rollback is never blocked by current rules');
        $this->assertSame('cta', pp_get_composition(823)[0]['component'], 'the snapshot is back');
        $types = array_column($report['reverted'][0]['findings'], 'type');
        $this->assertContains('unknown_prop', $types);
        $this->assertContains('invalid_style_slot', $types, 'the dead slot #621 unmasked');
        pp_operate_cleanup_run($run_id);
    }

    // ── apply reset rollback trail (#122) ──────────────────────────────────

    public function testApplyResetRecordsTouchedTokensRestorableViaRevert(): void
    {
        // Reproduces the fix in PP_Apply_Command::reset(): after a successful
        // reset, the cleared token names are recorded as touched the same way
        // execute() records its writes, so pp_revert_tokens() (restore's
        // underlying primitive) can bring the override back.
        pp_set_token_override('--color-accent', '#b45309');
        $run_id = pp_operate_create_run();
        pp_operate_record_token_snapshot($run_id, pp_get_token_overrides());

        $result = pp_execute_apply('reset_design_token', ['token' => '--color-accent']);
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('--color-accent', pp_get_token_overrides());

        $touched = array_column($result['changes'], 'token');
        $this->assertTrue(pp_operate_record_touched_tokens($run_id, $touched));
        $this->assertSame(['--color-accent'], pp_operate_get_touched_tokens($run_id));

        $snapshot = pp_operate_get_token_snapshot($run_id);
        $this->assertTrue(pp_revert_tokens($snapshot, pp_operate_get_touched_tokens($run_id)));
        $this->assertSame('#b45309', pp_get_token_overrides()['--color-accent']);

        pp_operate_cleanup_run($run_id);
    }

    public function testApplyResetAllRecordsEveryClearedTokenRestorableViaRevert(): void
    {
        pp_set_token_override('--color-accent', '#b45309');
        pp_set_token_override('--font-heading', 'Georgia, serif');
        $run_id = pp_operate_create_run();
        pp_operate_record_token_snapshot($run_id, pp_get_token_overrides());

        $result = pp_execute_apply('reset_all_design_tokens', []);
        $this->assertTrue($result['ok']);
        $this->assertSame([], pp_get_token_overrides());

        $touched = array_column($result['changes'], 'token');
        pp_operate_record_touched_tokens($run_id, $touched);
        $this->assertEqualsCanonicalizing(
            ['--color-accent', '--font-heading'],
            pp_operate_get_touched_tokens($run_id)
        );

        $snapshot = pp_operate_get_token_snapshot($run_id);
        $this->assertTrue(pp_revert_tokens($snapshot, pp_operate_get_touched_tokens($run_id)));
        $overrides = pp_get_token_overrides();
        $this->assertSame('#b45309', $overrides['--color-accent']);
        $this->assertSame('Georgia, serif', $overrides['--font-heading']);

        pp_operate_cleanup_run($run_id);
    }

    public function testApplyResetWithoutRollbackableSnapshotWouldBeRefused(): void
    {
        // The pre-gate added to reset() mirrors execute(): pp_operate_run_rollbackable()
        // must be true before any mutation is allowed. A run that never captured a
        // snapshot (no preflight) must fail this check.
        $run_id = pp_operate_create_run();
        $this->assertFalse(pp_operate_run_rollbackable($run_id));
        pp_operate_cleanup_run($run_id);
    }

    public function testApplyResetMergesTouchedTokensWithPriorExecuteInSameRun(): void
    {
        // If execute() already recorded touched tokens earlier in the same run,
        // reset() recording its own touched tokens must merge (union), not
        // overwrite — otherwise restore would lose the earlier execute()'s
        // footprint. pp_operate_record_touched_tokens() dedups/unions by design;
        // this pins that reset()'s call site doesn't accidentally rely on
        // replace semantics.
        $run_id = pp_operate_create_run();
        pp_operate_record_touched_tokens($run_id, ['--color-accent']);
        pp_operate_record_touched_tokens($run_id, ['--font-heading']);
        $this->assertSame(
            ['--color-accent', '--font-heading'],
            pp_operate_get_touched_tokens($run_id)
        );
        pp_operate_cleanup_run($run_id);
    }

    public function testGetTokenSnapshotLeavesCorruptStateIntact(): void
    {
        $fake_id = wp_generate_uuid4();
        $corrupt = ['not' => 'a valid state']; // missing steps_completed/created_at → corrupt
        update_option(pp_operate_run_option_name($fake_id), $corrupt, false);
        $this->assertNull(pp_operate_get_token_snapshot($fake_id));
        // The error path must not delete or rewrite the row (only expired rows auto-delete).
        $this->assertSame($corrupt, get_option(pp_operate_run_option_name($fake_id), null));
        pp_operate_delete_state($fake_id);
    }

    // ── Preflight-before-mutation coverage (#96) ──────────────────────────────

    public function testRecordPreflightWithPostIdRecordsCoverageStepAndSnapshot(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_record_preflight($run_id, 42, ['--color-accent' => '#111']));

        $data = get_option(pp_operate_run_option_name($run_id), null);
        $this->assertContains('PREFLIGHT', $data['steps_completed']);
        $this->assertSame([42], $data['preflight_post_ids']);
        $this->assertArrayNotHasKey('preflight_site', $data);
        // Snapshot committed in the same atomic write.
        $this->assertSame(['--color-accent' => '#111'], pp_operate_get_token_snapshot($run_id));
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightWithoutPostIdRecordsSiteGrain(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_record_preflight($run_id, null, []));

        $data = get_option(pp_operate_run_option_name($run_id), null);
        $this->assertContains('PREFLIGHT', $data['steps_completed']);
        $this->assertTrue($data['preflight_site']);
        $this->assertArrayNotHasKey('preflight_post_ids', $data);
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightDedupsPostId(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 7, []);
        pp_operate_record_preflight($run_id, 7, []);
        pp_operate_record_preflight($run_id, 9, []);

        $data = get_option(pp_operate_run_option_name($run_id), null);
        $this->assertSame([7, 9], $data['preflight_post_ids']);
        // PREFLIGHT step recorded once, not three times.
        $this->assertSame(['INSPECT', 'PREFLIGHT'], $data['steps_completed']);
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightSnapshotIsFirstWriteWins(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, null, ['--color-accent' => '#first']);
        pp_operate_record_preflight($run_id, 4, ['--color-accent' => '#second']);
        // Re-running preflight must never move the rollback baseline.
        $this->assertSame(['--color-accent' => '#first'], pp_operate_get_token_snapshot($run_id));
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightReturnsFalseForMissingRun(): void
    {
        // Valid UUID, no state file → fail-closed write.
        $this->assertFalse(pp_operate_record_preflight('00000000-0000-4000-8000-000000000000', 5, []));
    }

    public function testPreflightCoversMatchingPostIdReturnsTrue(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 42, []);
        $this->assertTrue(pp_operate_preflight_covers($run_id, 42));
        pp_operate_cleanup_run($run_id);
    }

    public function testPreflightCoversRejectsDifferentPostId(): void
    {
        // CRITICAL false-pass guard: a preflight for post 4 must NOT unlock post 7.
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 4, []);
        $this->assertFalse(pp_operate_preflight_covers($run_id, 7));
        pp_operate_cleanup_run($run_id);
    }

    public function testPreflightCoversPageNotCoveredBySiteOnlyPreflight(): void
    {
        // CRITICAL: a site-grain preflight must NOT cover a page mutation.
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, null, []);
        $this->assertFalse(pp_operate_preflight_covers($run_id, 42));
        pp_operate_cleanup_run($run_id);
    }

    public function testPreflightCoversSiteCoveredByNoPostPreflight(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, null, []);
        $this->assertTrue(pp_operate_preflight_covers($run_id, null));
        pp_operate_cleanup_run($run_id);
    }

    public function testPreflightCoversSiteNotCoveredByPostOnlyPreflight(): void
    {
        // A site mutation needs a site preflight; a page preflight must not cover it.
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 4, []);
        $this->assertFalse(pp_operate_preflight_covers($run_id, null));
        pp_operate_cleanup_run($run_id);
    }

    public function testPreflightCoversFalseForMissingRun(): void
    {
        $this->assertFalse(pp_operate_preflight_covers('00000000-0000-4000-8000-000000000000', 5));
        $this->assertFalse(pp_operate_preflight_covers('00000000-0000-4000-8000-000000000000', null));
    }

    public function testPreflightRecordAndCoverageFailClosedOnExpiredRun(): void
    {
        // A failed/blocked atomic write must leave the gate fail-closed: no
        // partial unlock. An expired run is the deterministic stand-in for a
        // write that cannot commit — record returns false and coverage is false.
        $run_id = pp_operate_create_run();
        $data = get_option(pp_operate_run_option_name($run_id), null);
        $data['created_at'] = time() - 10800; // 3h ago, past the 2h TTL.
        update_option(pp_operate_run_option_name($run_id), $data, false);

        $this->assertFalse(pp_operate_record_preflight($run_id, 42, ['--color-accent' => '#111']));
        $this->assertFalse(pp_operate_preflight_covers($run_id, 42));
        pp_operate_cleanup_run($run_id);
    }

    public function testLoopOrderPreflightPrecedesEdit(): void
    {
        // Regression pin (#96): PREFLIGHT must come before EDIT, and EDIT must
        // depend on preflight_result — the safety gate runs before any mutation.
        $order = array_keys(pp_operate_loop_steps());
        $this->assertLessThan(
            array_search('EDIT', $order, true),
            array_search('PREFLIGHT', $order, true),
            'PREFLIGHT must precede EDIT in the operating loop'
        );
        $steps = pp_operate_loop_steps();
        $this->assertContains('preflight_result', $steps['EDIT']['required_inputs']);
        $this->assertContains('mutation_plan', $steps['PREFLIGHT']['required_inputs']);
        $this->assertNotContains('edit_result', $steps['PREFLIGHT']['required_inputs']);
    }

    public function testAllRegisteredActionsHaveConsistentScopeAndPostIdParam(): void
    {
        // Guardrail: the preflight gate keys off post_id presence and asserts it
        // matches the action's declared scope. That assertion is only sound if
        // every registered action is consistent: page/section carry a required
        // post_id param; site actions do not.
        $actions = pp_get_registered_actions();
        $this->assertNotEmpty($actions, 'No actions registered — test would be vacuous');

        foreach ($actions as $name => $def) {
            $scope    = $def['scope'] ?? 'unknown';
            $hasParam = isset($def['params']['post_id']);
            if (in_array($scope, ['page', 'section'], true)) {
                $this->assertTrue($hasParam, "Action '$name' ($scope) must declare a post_id param");
                $this->assertTrue(!empty($def['params']['post_id']['required']), "Action '$name' post_id must be required");
            } elseif ($scope === 'site') {
                $this->assertFalse($hasParam, "Site action '$name' must not declare a post_id param");
            }
        }
    }

    // ── Preflight Composition Freshness (#113) ─────────────────────────────

    public function testRecordPreflightStoresCompositionSnapshotForPost(): void
    {
        $run_id = pp_operate_create_run();
        $marker = ['version' => 3, 'hash' => 'abc123'];
        $this->assertTrue(pp_operate_record_preflight($run_id, 42, [], $marker));
        $this->assertSame($marker, pp_operate_get_composition_snapshot($run_id, 42));
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightNoCompositionSnapshotForSiteGrain(): void
    {
        // A no-post (site-grain) preflight records no composition snapshot even if a
        // marker is somehow passed — freshness is a page/section concern.
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, null, [], ['version' => 1, 'hash' => 'x']);
        $data = get_option(pp_operate_run_option_name($run_id), null);
        $this->assertArrayNotHasKey('composition_snapshot', $data);
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordPreflightNoCompositionSnapshotWhenMarkerNull(): void
    {
        // Back-compat: the marker arg defaults to null, so existing callers record
        // coverage without a composition snapshot.
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 7, []);
        $this->assertNull(pp_operate_get_composition_snapshot($run_id, 7));
        pp_operate_cleanup_run($run_id);
    }

    public function testCompositionSnapshotIsLastWriteWins(): void
    {
        // Unlike the token rollback baseline (first-write-wins), the freshness baseline
        // moves to the latest preflight so a re-preflight after a change re-acknowledges it.
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 4, [], ['version' => 1, 'hash' => 'first']);
        pp_operate_record_preflight($run_id, 4, [], ['version' => 2, 'hash' => 'second']);
        $this->assertSame(['version' => 2, 'hash' => 'second'], pp_operate_get_composition_snapshot($run_id, 4));
        pp_operate_cleanup_run($run_id);
    }

    public function testGetCompositionSnapshotNullWhenNeverRecorded(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertNull(pp_operate_get_composition_snapshot($run_id, 99));
        pp_operate_cleanup_run($run_id);
    }

    public function testGetCompositionSnapshotNullForDifferentPost(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 4, [], ['version' => 1, 'hash' => 'h']);
        $this->assertNull(pp_operate_get_composition_snapshot($run_id, 5));
        pp_operate_cleanup_run($run_id);
    }

    public function testGetCompositionSnapshotNullForMissingRun(): void
    {
        // Fail-closed: an unusable run yields null so the execute gate blocks.
        $this->assertNull(pp_operate_get_composition_snapshot('00000000-0000-4000-8000-000000000000', 4));
    }

    public function testRecordCompositionSnapshotRefreshesBaseline(): void
    {
        // The refresh-after-write path: a run's own mutation updates the baseline.
        $run_id = pp_operate_create_run();
        pp_operate_record_preflight($run_id, 4, [], ['version' => 1, 'hash' => 'h1']);
        $this->assertTrue(pp_operate_record_composition_snapshot($run_id, 4, ['version' => 2, 'hash' => 'h2']));
        $this->assertSame(['version' => 2, 'hash' => 'h2'], pp_operate_get_composition_snapshot($run_id, 4));
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordCompositionSnapshotReturnsFalseForMissingRun(): void
    {
        $this->assertFalse(pp_operate_record_composition_snapshot('00000000-0000-4000-8000-000000000000', 4, ['version' => 1, 'hash' => 'h']));
    }

    public function testCompositionMarkerMatchesWhenIdentical(): void
    {
        $m = ['version' => 5, 'hash' => 'deadbeef'];
        $this->assertTrue(pp_composition_marker_matches($m, $m));
    }

    public function testCompositionMarkerMismatchOnVersion(): void
    {
        $this->assertFalse(pp_composition_marker_matches(
            ['version' => 5, 'hash' => 'same'],
            ['version' => 6, 'hash' => 'same']
        ));
    }

    public function testCompositionMarkerMismatchOnHash(): void
    {
        $this->assertFalse(pp_composition_marker_matches(
            ['version' => 5, 'hash' => 'aaa'],
            ['version' => 5, 'hash' => 'bbb']
        ));
    }

    public function testCompositionMarkerMatchAcrossPreflightExecuteRoundTrip(): void
    {
        // End-to-end at the function layer: preflight records the live marker; with no
        // intervening write the live marker still matches (execute would pass). A write
        // bumps the marker so it no longer matches (execute would reject).
        $post_id = pp_create_page('Freshness round trip');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'A']]]);

        $run_id = pp_operate_create_run();
        $at_preflight = pp_get_composition_marker($post_id);
        pp_operate_record_preflight($run_id, $post_id, [], $at_preflight);

        // No change → still fresh.
        $this->assertTrue(pp_composition_marker_matches(
            pp_operate_get_composition_snapshot($run_id, $post_id),
            pp_get_composition_marker($post_id)
        ));

        // External change → stale.
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'B']]]);
        $this->assertFalse(pp_composition_marker_matches(
            pp_operate_get_composition_snapshot($run_id, $post_id),
            pp_get_composition_marker($post_id)
        ));

        pp_operate_cleanup_run($run_id);
    }

    // ── Shared-lock reads (issue 274) ─────────────────────────────────────

    public function testReadStateReturnsStateForValidFileUnderSharedLock(): void
    {
        // A complete, valid state file still reads correctly now that the read
        // path takes flock(LOCK_SH).
        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');

        $data = pp_operate_read_state($run_id);
        $this->assertIsArray($data);
        $this->assertContains('INSPECT', $data['steps_completed']);
        $this->assertContains('PREFLIGHT', $data['steps_completed']);

        pp_operate_cleanup_run($run_id);
    }

    public function testReadStateReturnsNullForMissingRow(): void
    {
        // A valid UUID with no stored option row classifies as not_found → null.
        $this->assertNull(pp_operate_read_state('00000000-0000-4000-8000-000000000274'));
    }

    public function testReadStateReturnsNullForCorruptState(): void
    {
        // A corrupt (non-state) row classifies as corrupt: read_state returns null and
        // leaves the row in place (only expired rows are auto-deleted on read).
        $fake_id = wp_generate_uuid4();
        update_option(pp_operate_run_option_name($fake_id), 'NOT A STATE ARRAY', false);

        $this->assertNull(pp_operate_read_state($fake_id));
        $this->assertSame('NOT A STATE ARRAY', get_option(pp_operate_run_option_name($fake_id), null));
        pp_operate_delete_state($fake_id);
    }

    public function testReadThenMutateThenReadRoundTrips(): void
    {
        // Reads are lock-free now (a single-row option read is atomic), and the mutate
        // advisory lock must release cleanly. Proving read → mutate → read round-trips
        // confirms no lock leak and that a read sees a committed mutation.
        $run_id = pp_operate_create_run();

        $this->assertIsArray(pp_operate_read_state($run_id));
        $this->assertTrue(pp_operate_record_step($run_id, 'PREFLIGHT'));
        $after = pp_operate_read_state($run_id);
        $this->assertIsArray($after);
        $this->assertContains('PREFLIGHT', $after['steps_completed']);

        pp_operate_cleanup_run($run_id);
    }
}
