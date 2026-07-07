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
            ['component' => 'hero', 'props' => ['id' => 'pp-hero1111', 'variant' => 'left']],
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
            ['component' => 'hero', 'props' => ['id' => 'pp-hero1111', 'variant' => 'left']],
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

    public function testCreateRunCreatesStateFileWithCorrectShape(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertIsString($run_id);

        $path = pp_operate_run_path($run_id);
        $this->assertFileExists($path);

        $data = json_decode(file_get_contents($path), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('steps_completed', $data);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertContains('INSPECT', $data['steps_completed']);

        // Cleanup
        pp_operate_cleanup_run($run_id);
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

    public function testCheckStepReturnsFalseForExpiredStateFile(): void
    {
        $run_id = pp_operate_create_run();
        $path = pp_operate_run_path($run_id);

        // Backdate the created_at to 3 hours ago.
        $data = json_decode(file_get_contents($path), true);
        $data['created_at'] = time() - 10800;
        file_put_contents($path, json_encode($data), LOCK_EX);

        $this->assertFalse(pp_operate_check_step($run_id, 'INSPECT'));
        pp_operate_cleanup_run($run_id);
    }

    public function testRecordStepAppendsStep(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_record_step($run_id, 'PREFLIGHT'));
        $this->assertTrue(pp_operate_check_step($run_id, 'PREFLIGHT'));
        pp_operate_cleanup_run($run_id);
    }

    public function testCleanupRunDeletesStateFile(): void
    {
        $run_id = pp_operate_create_run();
        $path = pp_operate_run_path($run_id);
        $this->assertFileExists($path);

        pp_operate_cleanup_run($run_id);
        $this->assertFileDoesNotExist($path);
    }

    // ── Edge Case Tests ───────────────────────────────────────────────────

    public function testCheckStepReturnsFalseForNonExistentStateFile(): void
    {
        // Valid UUID format but no state file exists.
        $this->assertFalse(pp_operate_check_step('00000000-0000-4000-8000-000000000000', 'INSPECT'));
    }

    public function testCheckStepReturnsFalseForInvalidRunId(): void
    {
        // Path traversal attempt — should be rejected by UUID validation.
        $this->assertFalse(pp_operate_check_step('../../etc/passwd', 'INSPECT'));
        $this->assertFalse(pp_operate_check_step('not-a-uuid', 'INSPECT'));
        $this->assertFalse(pp_operate_check_step('', 'INSPECT'));
    }

    public function testCheckStepReturnsFalseForCorruptJson(): void
    {
        $fake_id = wp_generate_uuid4();
        $path = pp_operate_run_path($fake_id);
        file_put_contents($path, 'NOT VALID JSON {{{', LOCK_EX);

        $this->assertFalse(pp_operate_check_step($fake_id, 'INSPECT'));
        @unlink($path);
    }

    public function testRecordStepDuplicateIsIdempotent(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_step($run_id, 'PREFLIGHT');

        $path = pp_operate_run_path($run_id);
        $data = json_decode(file_get_contents($path), true);
        $count = array_count_values($data['steps_completed']);
        $this->assertEquals(1, $count['PREFLIGHT']);

        pp_operate_cleanup_run($run_id);
    }

    public function testCleanupRunNoOpForNonExistentFile(): void
    {
        // Should not throw — valid UUID format but no file exists.
        pp_operate_cleanup_run('00000000-0000-4000-8000-000000000001');
        $this->assertTrue(true);
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

    // ── Selector Parser Tests ────────────────────────────────────────────────

    public function testParseCompositionSelectorSimple(): void
    {
        $result = pp_parse_composition_selector('hero.subtitle');
        $this->assertIsArray($result);
        $this->assertSame('hero', $result['component_type']);
        $this->assertSame('subtitle', $result['target_field']);
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
        $result = pp_parse_composition_selector('hero[id="pp-a1b2c3d4"].subtitle');
        $this->assertIsArray($result);
        $this->assertSame('hero', $result['component_type']);
        $this->assertSame('pp-a1b2c3d4', $result['component_id']);
        $this->assertSame('subtitle', $result['target_field']);
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

    public function testRegisterComponentFields(): void
    {
        // Verify built-in registrations for all 5 types.
        $hero = pp_get_component_fields('hero');
        $this->assertCount(5, $hero);
        $this->assertSame('title', $hero[0]['name']);
        $this->assertSame('string', $hero[0]['type']);
        $this->assertSame('cta_url', $hero[4]['name']);
        $this->assertSame('url', $hero[4]['type']);

        $section = pp_get_component_fields('section');
        $this->assertCount(4, $section);
        $this->assertSame('eyebrow', $section[1]['name']);
        $this->assertSame('subheading', $section[2]['name']);
        $this->assertSame('body', $section[3]['name']);
        $this->assertSame('html', $section[3]['type']);

        $grid = pp_get_component_fields('grid');
        $this->assertCount(6, $grid);
        $this->assertSame('eyebrow', $grid[0]['name']);
        $this->assertSame('subheading', $grid[1]['name']);
        $this->assertSame('items[].title', $grid[2]['name']);
        $this->assertSame('items[].link_url', $grid[4]['name']);
        $this->assertSame('items[].link_text', $grid[5]['name']);

        $faq = pp_get_component_fields('faq');
        $this->assertCount(2, $faq);
        $this->assertSame('items[].question', $faq[0]['name']);
        $this->assertSame('items[].answer', $faq[1]['name']);

        $cta = pp_get_component_fields('cta');
        $this->assertCount(5, $cta);
        $this->assertSame('title', $cta[0]['name']);
        $this->assertSame('eyebrow', $cta[1]['name']);
        $this->assertSame('text', $cta[2]['name']);
        $this->assertSame('button_text', $cta[3]['name']);
        $this->assertSame('button_url', $cta[4]['name']);

        // Unmapped type returns empty array.
        $unknown = pp_get_component_fields('nonexistent');
        $this->assertSame([], $unknown);
    }

    public function testInspectCompositionResolvesRealCtaAndGridValues(): void
    {
        // Regression (#120): the editability map previously declared dead
        // selectors (cta.subtitle/cta_text/cta_url, grid items[].link) that
        // don't exist on either component, so pp_inspect_composition()
        // always reported current_value: null for them — poisoning the AI
        // context with editable-looking fields that silently no-op on
        // patch. Assert the map now resolves to the real, populated props.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'CTA Grid Inspect', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            [
                'component' => 'cta',
                'props' => [
                    'id' => 'pp-cta0001', 'title' => 'Join now', 'text' => 'Limited spots',
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
        $this->assertSame('Limited spots', $ctaByField['text']);
        $this->assertSame('Sign up', $ctaByField['button_text']);
        $this->assertSame('/signup', $ctaByField['button_url']);
        $this->assertArrayNotHasKey('subtitle', $ctaByField);
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

    public function testPatchCtaDeadFieldsNowFailNotEditable(): void
    {
        // Regression (#120 write path): before the fix, patching these dead
        // selectors reported success (ok: true) while writing an unused
        // prop the render never reads. Confirm they now correctly fail.
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'CTA Dead Fields', 'post_status' => 'publish']);
        update_post_meta($post_id, '_pp_composition', json_encode([
            ['component' => 'cta', 'props' => ['id' => 'pp-cta8888', 'title' => 'Join', 'button_text' => 'Go', 'button_url' => '/go']],
        ]));

        foreach (['cta.subtitle', 'cta.cta_text', 'cta.cta_url'] as $selector) {
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
            ['component' => 'hero', 'props' => ['id' => 'pp-hero1111', 'title' => 'Welcome', 'subtitle' => 'Sub']],
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
        // Check that title and subtitle selectors are present
        $selectors = array_column($hero['fields'], 'selector');
        $this->assertTrue(in_array('hero.title', $selectors), 'Hero title selector present');

        // Section component
        $section = $result[1];
        $this->assertSame('section', $section['component_type']);
        $this->assertSame('pp-sect2222', $section['component_id']);
        // body field should have field_type = html
        $body_field = null;
        foreach ($section['fields'] as $f) {
            if ($f['field'] === 'body') {
                $body_field = $f;
                break;
            }
        }
        $this->assertNotNull($body_field);
        $this->assertSame('html', $body_field['field_type']);
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
            ['component' => 'hero', 'props' => ['id' => 'pp-hero0001', 'title' => 'Welcome', 'subtitle' => 'Old Subtitle']],
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
        $result = pp_patch_composition($post_id, 'hero.subtitle', 'New Subtitle', true);
        $this->assertIsArray($result);
        // Preview should have action name and before/after data
        $this->assertSame('update_component', $result['action']);
        $this->assertArrayHasKey('before', $result);
        $this->assertArrayHasKey('after', $result);
        // Verify the value was NOT written
        $comp = pp_get_composition($post_id);
        $this->assertSame('Old Subtitle', $comp[0]['props']['subtitle']);
    }

    public function testPatchTopLevelFieldApply(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'hero.subtitle', 'New Subtitle');
        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $this->assertSame('update_component', $result['action']);
        // Verify the value was written
        $comp = pp_get_composition($post_id);
        $this->assertSame('New Subtitle', $comp[0]['props']['subtitle']);
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
        $result = pp_parse_composition_selector('hero.subtitle.extra');
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
            ['component' => 'hero', 'props' => ['id' => 'pp-notitle1', 'subtitle' => 'Just a subtitle']],
        ]));

        $result = pp_inspect_composition($post_id);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Without title prop, should produce simple selectors (hero.subtitle, not hero[title="..."].subtitle)
        $selectors = array_column($result[0]['fields'], 'selector');
        foreach ($selectors as $sel) {
            $this->assertStringNotContainsString('[title=', $sel, 'No title bracket match when title prop is absent');
        }
    }

    public function testPatchWithIdBasedSelector(): void
    {
        $post_id = $this->createPatchTestPage();
        $result = pp_patch_composition($post_id, 'hero[id="pp-hero0001"].subtitle', 'ID Patched');
        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
        $comp = pp_get_composition($post_id);
        $this->assertSame('ID Patched', $comp[0]['props']['subtitle']);
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
        $this->assertCount(36, $result[0]['style_slots']); // hero has 36 slots (33 + 3 position/aspect-ratio, issue 108)

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
        $data = json_decode(file_get_contents(pp_operate_run_path($run_id)), true);
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
        $path = pp_operate_run_path($run_id);
        $data = json_decode(file_get_contents($path), true);
        $data['created_at'] = time() - 10800; // 3h ago
        file_put_contents($path, json_encode($data), LOCK_EX);
        $this->assertNull(pp_operate_get_token_snapshot($run_id));
        @unlink($path);
    }

    public function testGetTokenSnapshotNullForForeignSiteIdentity(): void
    {
        $run_id = pp_operate_create_run();
        pp_operate_record_token_snapshot($run_id, ['--color-accent' => '#111111']);
        // Simulate restoring under a different install sharing the temp dir.
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

    public function testGetTokenSnapshotLeavesCorruptFileIntact(): void
    {
        $fake_id = wp_generate_uuid4();
        $path = pp_operate_run_path($fake_id);
        $corrupt = 'NOT VALID JSON {{{';
        file_put_contents($path, $corrupt, LOCK_EX);
        $this->assertNull(pp_operate_get_token_snapshot($fake_id));
        // The error path must not truncate or recreate the file.
        $this->assertSame($corrupt, file_get_contents($path));
        @unlink($path);
    }

    // ── Preflight-before-mutation coverage (#96) ──────────────────────────────

    public function testRecordPreflightWithPostIdRecordsCoverageStepAndSnapshot(): void
    {
        $run_id = pp_operate_create_run();
        $this->assertTrue(pp_operate_record_preflight($run_id, 42, ['--color-accent' => '#111']));

        $data = json_decode(file_get_contents(pp_operate_run_path($run_id)), true);
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

        $data = json_decode(file_get_contents(pp_operate_run_path($run_id)), true);
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

        $data = json_decode(file_get_contents(pp_operate_run_path($run_id)), true);
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
        $path = pp_operate_run_path($run_id);
        $data = json_decode(file_get_contents($path), true);
        $data['created_at'] = time() - 10800; // 3h ago, past the 2h TTL.
        file_put_contents($path, json_encode($data), LOCK_EX);

        $this->assertFalse(pp_operate_record_preflight($run_id, 42, ['--color-accent' => '#111']));
        $this->assertFalse(pp_operate_preflight_covers($run_id, 42));
        @unlink($path);
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
}
