<?php
/**
 * tests/ApplyTest.php — PHPUnit tests for the PromptingPress Apply Layer
 *
 * Covers: registry functions, validation (structural + type-specific),
 * preview, execute (backup, write, contract verification, auto-restore),
 * restore (latest, by point, list), and cache invalidation.
 *
 * Uses real temp copies of base.css for file I/O testing.
 */

use PHPUnit\Framework\TestCase;

class ApplyTest extends TestCase
{
    private string $tempDir;
    private string $baseCssPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temp directory structure mirroring theme layout
        $this->tempDir = sys_get_temp_dir() . '/pp-apply-test-' . getmypid() . '-' . mt_rand();
        $cssDir = $this->tempDir . '/assets/css';
        mkdir($cssDir, 0755, true);

        // Copy real base.css to temp location
        $realBaseCss = dirname(__DIR__) . '/assets/css/base.css';
        $this->baseCssPath = $cssDir . '/base.css';
        copy($realBaseCss, $this->baseCssPath);

        // Point get_template_directory() at temp dir
        $GLOBALS['_pp_test_template_dir'] = $this->tempDir;

        // Invalidate token cache for fresh reads
        pp_invalidate_design_tokens_cache();
    }

    protected function tearDown(): void
    {
        // Clean up temp directory and backup directory
        $this->recursiveDelete($this->tempDir);
        $this->recursiveDelete(WP_CONTENT_DIR . '/pp-backups');
        unset($GLOBALS['_pp_test_template_dir']);
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

    // ── Registry tests ─────────────────────────────────────────────────────

    public function testRegistryReturnsRegisteredApply(): void
    {
        $applies = pp_get_registered_applies();
        $this->assertArrayHasKey('update_design_token', $applies);
    }

    public function testGetApplyReturnsDefinition(): void
    {
        $apply = pp_get_apply('update_design_token');
        $this->assertNotNull($apply);
        $this->assertEquals('design', $apply['domain']);
        $this->assertEquals('assets/css/base.css', $apply['target_file']);
        $this->assertArrayHasKey('validate', $apply);
        $this->assertArrayHasKey('preview', $apply);
        $this->assertArrayHasKey('apply', $apply);
    }

    public function testGetApplyReturnsNullForUnknown(): void
    {
        $this->assertNull(pp_get_apply('nonexistent'));
    }

    public function testValidateRejectsUnknownApply(): void
    {
        $result = pp_validate_apply('nonexistent', []);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('unknown_apply', $result->get_error_code());
    }

    // ── Structural validation ──────────────────────────────────────────────

    public function testValidateRejectsMissingRequiredParam(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('missing_param', $result->get_error_code());
    }

    public function testValidateRejectsWrongParamType(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => 123, 'value' => '#fff']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_param_type', $result->get_error_code());
    }

    // ── Semantic validation: token whitelist ────────────────────────────────

    public function testValidateAcceptsKnownToken(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-accent', 'value' => '#ff0000']);
        $this->assertTrue($result);
    }

    public function testValidateRejectsUnknownToken(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--nonexistent', 'value' => '#ff0000']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('unknown_token', $result->get_error_code());
    }

    // ── Injection prevention ───────────────────────────────────────────────

    public function testValidateRejectsOpenBrace(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => '#fff { body']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('injection', $result->get_error_code());
    }

    public function testValidateRejectsCloseBrace(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => '#fff } body']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('injection', $result->get_error_code());
    }

    public function testValidateRejectsSemicolon(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => '#fff; color: red']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('injection', $result->get_error_code());
    }

    public function testValidateAcceptsParensAndCommas(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--overlay-bg', 'value' => 'rgba(0, 0, 0, 0.5)']);
        $this->assertTrue($result);
    }

    // ── Type-specific validation: color ─────────────────────────────────────

    public function testColorValidHex3(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => '#fff']);
        $this->assertTrue($result);
    }

    public function testColorValidHex6(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => '#0055cc']);
        $this->assertTrue($result);
    }

    public function testColorValidHex8(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => '#00ff0080']);
        $this->assertTrue($result);
    }

    public function testColorValidRgb(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'rgb(255, 0, 0)']);
        $this->assertTrue($result);
    }

    public function testColorValidRgba(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'rgba(0, 0, 0, 0.55)']);
        $this->assertTrue($result);
    }

    public function testColorValidHsl(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'hsl(120, 50%, 50%)']);
        $this->assertTrue($result);
    }

    public function testColorValidHsla(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'hsla(120, 50%, 50%, 0.5)']);
        $this->assertTrue($result);
    }

    public function testColorRejectsNamedColor(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'red']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_color', $result->get_error_code());
    }

    public function testColorRejectsGarbage(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'not-a-color']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_color', $result->get_error_code());
    }

    // ── Type-specific validation: length ────────────────────────────────────

    public function testLengthValidRem(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--space-md', 'value' => '1rem']);
        $this->assertTrue($result);
    }

    public function testLengthValidPx(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--space-md', 'value' => '16px']);
        $this->assertTrue($result);
    }

    public function testLengthValidEm(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--space-md', 'value' => '4em']);
        $this->assertTrue($result);
    }

    public function testLengthRejectsMissingUnit(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--space-md', 'value' => '16']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_length', $result->get_error_code());
    }

    // ── Type-specific validation: font-family ───────────────────────────────

    public function testFontFamilyValidStack(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--font-body', 'value' => 'Inter, system-ui, sans-serif']);
        $this->assertTrue($result);
    }

    public function testFontFamilyRejectsEmpty(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--font-body', 'value' => '']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('empty_value', $result->get_error_code());
    }

    // ── Type-specific validation: duration ──────────────────────────────────

    public function testDurationValidMs(): void
    {
        // --transition is type 'raw', so we can't test duration on it directly.
        // Test the internal validator instead.
        $this->assertTrue(_pp_validate_duration('150ms'));
    }

    public function testDurationValidS(): void
    {
        $this->assertTrue(_pp_validate_duration('0.3s'));
    }

    public function testDurationInvalid(): void
    {
        $this->assertFalse(_pp_validate_duration('fast'));
    }

    // ── Type-specific validation: raw ───────────────────────────────────────

    public function testRawAcceptsCompoundValue(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--transition', 'value' => '200ms ease-in-out']);
        $this->assertTrue($result);
    }

    // ── No type metadata fallback ───────────────────────────────────────────

    public function testNoTypeMetadataAcceptsAnyNonInjectionValue(): void
    {
        $result = _pp_validate_token_value('anything goes', null);
        $this->assertTrue($result);
    }

    // ── Preview ─────────────────────────────────────────────────────────────

    public function testPreviewReturnsValidationErrorOnBadInput(): void
    {
        $result = pp_preview_apply('update_design_token', ['token' => '--nonexistent', 'value' => '#fff']);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testPreviewReturnsDiffWithoutWriting(): void
    {
        $before_css = file_get_contents($this->baseCssPath);

        $result = pp_preview_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        $this->assertTrue($result['ok']);
        $this->assertEquals('update_design_token', $result['apply']);
        $this->assertEquals('design', $result['domain']);
        $this->assertArrayHasKey('before', $result);
        $this->assertArrayHasKey('after', $result);
        $this->assertEquals('#0055cc', $result['before']['--color-accent']);
        $this->assertEquals('#b45309', $result['after']['--color-accent']);
        // Other tokens unchanged in preview
        $this->assertEquals($result['before']['--color-bg'], $result['after']['--color-bg']);

        // File should not have changed
        $after_css = file_get_contents($this->baseCssPath);
        $this->assertEquals($before_css, $after_css);
    }

    public function testPreviewChangesArray(): void
    {
        $result = pp_preview_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);
        $this->assertCount(1, $result['changes']);
        $this->assertEquals('--color-accent', $result['changes'][0]['token']);
        $this->assertEquals('#0055cc', $result['changes'][0]['from']);
        $this->assertEquals('#b45309', $result['changes'][0]['to']);
    }

    // ── Execute ─────────────────────────────────────────────────────────────

    public function testExecuteReturnsValidationErrorOnBadInput(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--nonexistent', 'value' => '#fff']);
        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    public function testExecuteWritesTokenValue(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        $this->assertTrue($result['ok']);
        $this->assertEquals('update_design_token', $result['apply']);
        $this->assertEquals('design', $result['domain']);
        $this->assertEquals(1, $result['restore_point']);
        $this->assertCount(1, $result['changes']);
        $this->assertEquals('#0055cc', $result['changes'][0]['from']);
        $this->assertEquals('#b45309', $result['changes'][0]['to']);

        // Verify the file was actually written
        $tokens = _pp_read_tokens_from_file($this->baseCssPath);
        $this->assertEquals('#b45309', $tokens['--color-accent']);
    }

    public function testExecuteCreatesBackup(): void
    {
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        $points = pp_restore_points('base.css');
        $this->assertNotEmpty($points);
        $this->assertEquals(1, $points[0]['index']);
    }

    public function testExecuteComplexValueRgba(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--overlay-bg', 'value' => 'rgba(10, 20, 30, 0.7)']);

        $this->assertTrue($result['ok']);

        $tokens = _pp_read_tokens_from_file($this->baseCssPath);
        $this->assertEquals('rgba(10, 20, 30, 0.7)', $tokens['--overlay-bg']);
    }

    public function testExecuteFontStack(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--font-body', 'value' => 'Inter, system-ui, sans-serif']);

        $this->assertTrue($result['ok']);

        $tokens = _pp_read_tokens_from_file($this->baseCssPath);
        $this->assertEquals('Inter, system-ui, sans-serif', $tokens['--font-body']);
    }

    public function testExecuteCompoundValue(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--transition', 'value' => '200ms ease-in-out']);

        $this->assertTrue($result['ok']);

        $tokens = _pp_read_tokens_from_file($this->baseCssPath);
        $this->assertEquals('200ms ease-in-out', $tokens['--transition']);
    }

    public function testExecuteNoOpReturnsSuccessWithEmptyChanges(): void
    {
        // Setting a token to its current value is a satisfied postcondition, not an error
        $tokens = pp_design_tokens();
        $current = $tokens['--color-accent']['value'];

        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => $current]);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['changes']);
        $this->assertNull($result['restore_point']);
    }

    // ── Contract verification ───────────────────────────────────────────────

    public function testContractVerificationTargetHasNewValue(): void
    {
        $before = ['--a' => '1', '--b' => '2'];
        // Write a fake file to verify against
        $css = ":root { --a: 99; --b: 2; }";
        $tmp = $this->tempDir . '/contract-test.css';
        file_put_contents($tmp, $css);

        $result = _pp_verify_contract($tmp, $before, '--a', '99');
        $this->assertTrue($result);
    }

    public function testContractVerificationTargetWrongValue(): void
    {
        $before = ['--a' => '1', '--b' => '2'];
        $css = ":root { --a: wrong; --b: 2; }";
        $tmp = $this->tempDir . '/contract-test.css';
        file_put_contents($tmp, $css);

        $result = _pp_verify_contract($tmp, $before, '--a', '99');
        $this->assertIsString($result);
        $this->assertStringContainsString('expected "99"', $result);
    }

    public function testContractVerificationNonTargetChanged(): void
    {
        $before = ['--a' => '1', '--b' => '2'];
        $css = ":root { --a: 99; --b: changed; }";
        $tmp = $this->tempDir . '/contract-test.css';
        file_put_contents($tmp, $css);

        $result = _pp_verify_contract($tmp, $before, '--a', '99');
        $this->assertIsString($result);
        $this->assertStringContainsString('--b', $result);
        $this->assertStringContainsString('should be unchanged', $result);
    }

    public function testContractVerificationTokenMissing(): void
    {
        $before = ['--a' => '1', '--b' => '2'];
        $css = ":root { --a: 99; }";
        $tmp = $this->tempDir . '/contract-test.css';
        file_put_contents($tmp, $css);

        $result = _pp_verify_contract($tmp, $before, '--a', '99');
        $this->assertIsString($result);
        $this->assertStringContainsString('--b', $result);
        $this->assertStringContainsString('missing', $result);
    }

    // ── Execute preserves non-target tokens ─────────────────────────────────

    public function testExecutePreservesAllNonTargetTokens(): void
    {
        $before_tokens = _pp_read_tokens_from_file($this->baseCssPath);

        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        $after_tokens = _pp_read_tokens_from_file($this->baseCssPath);

        foreach ($before_tokens as $name => $old_value) {
            if ($name === '--color-accent') {
                $this->assertEquals('#b45309', $after_tokens[$name]);
            } else {
                $this->assertEquals($old_value, $after_tokens[$name], "Token $name should be unchanged");
            }
        }
    }

    // ── Backup pruning ──────────────────────────────────────────────────────

    public function testBackupPruningKeepsLastFive(): void
    {
        // Create 7 backups by executing 7 times
        $colors = ['#111111', '#222222', '#333333', '#444444', '#555555', '#666666', '#777777'];
        foreach ($colors as $color) {
            pp_execute_apply('update_design_token', ['token' => '--color-bg', 'value' => $color]);
            // Small delay to ensure unique timestamps
            usleep(10000);
        }

        $points = pp_restore_points('base.css');
        $this->assertLessThanOrEqual(5, count($points));
    }

    // ── Cache invalidation ──────────────────────────────────────────────────

    public function testCacheInvalidationReturnsFreshDataAfterWrite(): void
    {
        $tokens_before = pp_design_tokens();
        $this->assertEquals('#0055cc', $tokens_before['--color-accent']['value']);

        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        $tokens_after = pp_design_tokens();
        $this->assertEquals('#b45309', $tokens_after['--color-accent']['value']);
    }

    // ── Restore ─────────────────────────────────────────────────────────────

    public function testRestoreLatest(): void
    {
        // Execute to create a backup
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        // Verify the change
        $tokens = _pp_read_tokens_from_file($this->baseCssPath);
        $this->assertEquals('#b45309', $tokens['--color-accent']);

        // Restore
        $result = pp_restore($this->baseCssPath);
        $this->assertTrue($result);

        // Verify restore
        $tokens = _pp_read_tokens_from_file($this->baseCssPath);
        $this->assertEquals('#0055cc', $tokens['--color-accent']);
    }

    public function testRestoreByPointIndex(): void
    {
        // Execute twice to create two backups
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#111111']);
        usleep(10000);
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#222222']);

        // Restore point 1 = most recent backup (state before second execute)
        $result = pp_restore($this->baseCssPath, 1);
        $this->assertTrue($result);

        $tokens = _pp_read_tokens_from_file($this->baseCssPath);
        $this->assertEquals('#111111', $tokens['--color-accent']);
    }

    public function testRestoreListReturnsPoints(): void
    {
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        $points = pp_restore_points('base.css');
        $this->assertNotEmpty($points);
        $this->assertEquals(1, $points[0]['index']);
        $this->assertArrayHasKey('timestamp', $points[0]);
    }

    public function testRestoreWithNoBackupsReturnsError(): void
    {
        $result = pp_restore($this->baseCssPath);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('no_backups', $result->get_error_code());
    }

    public function testRestoreInvalidPointReturnsError(): void
    {
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        $result = pp_restore($this->baseCssPath, 99);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_point', $result->get_error_code());
    }

    // ── pp_design_tokens() return shape ─────────────────────────────────────

    public function testDesignTokensReturnTypeAlongsideValue(): void
    {
        $tokens = pp_design_tokens();
        $this->assertArrayHasKey('--color-bg', $tokens);
        $this->assertArrayHasKey('value', $tokens['--color-bg']);
        $this->assertArrayHasKey('type', $tokens['--color-bg']);
        $this->assertEquals('#ffffff', $tokens['--color-bg']['value']);
        $this->assertEquals('color', $tokens['--color-bg']['type']);
    }

    public function testDesignTokensTypesForAllCategories(): void
    {
        $tokens = pp_design_tokens();

        // Color tokens
        $this->assertEquals('color', $tokens['--color-accent']['type']);
        $this->assertEquals('color', $tokens['--overlay-bg']['type']);

        // Length tokens
        $this->assertEquals('length', $tokens['--space-md']['type']);
        $this->assertEquals('length', $tokens['--radius']['type']);
        $this->assertEquals('length', $tokens['--max-width']['type']);

        // Font-family tokens
        $this->assertEquals('font-family', $tokens['--font-body']['type']);
        $this->assertEquals('font-family', $tokens['--font-heading']['type']);

        // Raw tokens
        $this->assertEquals('raw', $tokens['--transition']['type']);
    }

    public function testDesignTokensCacheInvalidation(): void
    {
        $tokens1 = pp_design_tokens();
        pp_invalidate_design_tokens_cache();
        $tokens2 = pp_design_tokens();

        // Both should return same data (file unchanged)
        $this->assertEquals($tokens1, $tokens2);
    }
}
