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

        // Point get_template_directory() at temp dir
        $GLOBALS['_pp_test_template_dir'] = $this->tempDir;
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';

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

    public function testPreflightAutoPopulatesPlannedFilesFromApplyDefinition(): void
    {
        // The update_design_token apply has target_file => 'assets/css/base.css'
        // Create drift in that file to trigger overlap detection
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
        $this->assertFalse($drift_check['pass'], 'Should block: drift in assets/css/base.css overlaps with update_design_token target_file');
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

    public function testPreflightThemeWritableFailsWhenNotWritable(): void
    {
        // Make theme dir read-only
        chmod($this->tempDir, 0555);

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
        $this->assertFalse($check['pass']);
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
}
