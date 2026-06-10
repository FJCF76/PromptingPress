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

        // Clear token overrides for test isolation
        unset($GLOBALS['_pp_test_store']['options']['pp_token_overrides']);

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
        $this->assertEquals(['type' => 'option', 'key' => 'pp_token_overrides'], $apply['target']);
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
        $this->assertEquals('#3157f4', $result['before']['--color-accent']);
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
        $this->assertEquals('#3157f4', $result['changes'][0]['from']);
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
        $this->assertCount(1, $result['changes']);
        $this->assertEquals('#3157f4', $result['changes'][0]['from']);
        $this->assertEquals('#b45309', $result['changes'][0]['to']);

        // Verify the override is in the database
        $overrides = pp_get_token_overrides();
        $this->assertEquals('#b45309', $overrides['--color-accent']);
    }

    public function testExecuteComplexValueRgba(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--overlay-bg', 'value' => 'rgba(10, 20, 30, 0.7)']);

        $this->assertTrue($result['ok']);

        $overrides = pp_get_token_overrides();
        $this->assertEquals('rgba(10, 20, 30, 0.7)', $overrides['--overlay-bg']);
    }

    public function testExecuteFontStack(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--font-body', 'value' => 'Inter, system-ui, sans-serif']);

        $this->assertTrue($result['ok']);

        $overrides = pp_get_token_overrides();
        $this->assertEquals('Inter, system-ui, sans-serif', $overrides['--font-body']);
    }

    public function testExecuteCompoundValue(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--transition', 'value' => '200ms ease-in-out']);

        $this->assertTrue($result['ok']);

        $overrides = pp_get_token_overrides();
        $this->assertEquals('200ms ease-in-out', $overrides['--transition']);
    }

    public function testExecuteNoOpReturnsSuccessWithEmptyChanges(): void
    {
        // Setting a token to its current value is a satisfied postcondition, not an error
        $tokens = pp_design_tokens();
        $current = $tokens['--color-accent']['value'];

        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => $current]);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['changes']);
    }

    // ── Execute writes to database, not file ────────────────────────────────

    public function testExecuteWritesToDatabaseNotFile(): void
    {
        $file_before = _pp_read_tokens_from_file($this->baseCssPath);
        $original_accent = $file_before['--color-accent'];

        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        // File should be unchanged
        $file_after = _pp_read_tokens_from_file($this->baseCssPath);
        $this->assertEquals($original_accent, $file_after['--color-accent']);

        // Database should have the override
        $overrides = pp_get_token_overrides();
        $this->assertSame('#b45309', $overrides['--color-accent']);

        // Merged tokens should reflect the override
        $tokens = pp_design_tokens();
        $this->assertSame('#b45309', $tokens['--color-accent']['value']);
    }

    // ── Cache invalidation ──────────────────────────────────────────────────

    public function testCacheInvalidationReturnsFreshDataAfterWrite(): void
    {
        $tokens_before = pp_design_tokens();
        $this->assertEquals('#3157f4', $tokens_before['--color-accent']['value']);

        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);

        $tokens_after = pp_design_tokens();
        $this->assertEquals('#b45309', $tokens_after['--color-accent']['value']);
    }

    // ── Reset applies ──────────────────────────────────────────────────────

    public function testResetDesignTokenRevertsToDefault(): void
    {
        $defaults = pp_design_tokens();
        $default_accent = $defaults['--color-accent']['value'];

        // Set an override
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);
        $this->assertSame('#b45309', pp_design_tokens()['--color-accent']['value']);

        // Reset it
        $result = pp_execute_apply('reset_design_token', ['token' => '--color-accent']);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['changes']);
        $this->assertSame('#b45309', $result['changes'][0]['from']);
        $this->assertSame($default_accent, $result['changes'][0]['to']);

        // Should be back to default
        $this->assertSame($default_accent, pp_design_tokens()['--color-accent']['value']);
    }

    public function testResetDesignTokenNoOpWhenNoOverride(): void
    {
        $result = pp_execute_apply('reset_design_token', ['token' => '--color-accent']);
        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['changes']);
    }

    public function testResetDesignTokenRejectsUnknownToken(): void
    {
        $result = pp_execute_apply('reset_design_token', ['token' => '--nonexistent']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a registered', $result['error']);
    }

    public function testResetAllDesignTokensClearsAll(): void
    {
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);
        pp_execute_apply('update_design_token', ['token' => '--color-bg', 'value' => '#000000']);

        $result = pp_execute_apply('reset_all_design_tokens', []);
        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['changes']);
        $this->assertSame([], pp_get_token_overrides());
    }

    public function testResetAllDesignTokensNoOpWhenEmpty(): void
    {
        $result = pp_execute_apply('reset_all_design_tokens', []);
        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['changes']);
    }

    // ── Typed target ───────────────────────────────────────────────────────

    public function testResultContainsTypedTarget(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);
        $this->assertArrayHasKey('target', $result);
        $this->assertEquals('option', $result['target']['type']);
        $this->assertEquals('pp_token_overrides', $result['target']['key']);
        $this->assertArrayNotHasKey('target_file', $result);
    }

    public function testPreviewContainsTypedTarget(): void
    {
        $result = pp_preview_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);
        $this->assertArrayHasKey('target', $result);
        $this->assertEquals('option', $result['target']['type']);
        $this->assertArrayNotHasKey('target_file', $result);
    }

    public function testRegisteredAppliesHaveTypedTarget(): void
    {
        $applies = pp_get_registered_applies();
        foreach ($applies as $name => $def) {
            $this->assertArrayHasKey('target', $def, "Apply '$name' should have 'target' key");
            $this->assertArrayHasKey('type', $def['target'], "Apply '$name' target should have 'type' key");
            $this->assertContains($def['target']['type'], ['file', 'option'], "Apply '$name' target type should be 'file' or 'option'");
            $this->assertArrayNotHasKey('target_file', $def, "Apply '$name' should not have legacy 'target_file' key");
        }
    }

    // ── pp_design_tokens() return shape ─────────────────────────────────────

    public function testDesignTokensReturnTypeAlongsideValue(): void
    {
        $tokens = pp_design_tokens();
        $this->assertArrayHasKey('--color-bg', $tokens);
        $this->assertArrayHasKey('value', $tokens['--color-bg']);
        $this->assertArrayHasKey('type', $tokens['--color-bg']);
        $this->assertEquals('#fcfdff', $tokens['--color-bg']['value']);
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

    // ── Token Override CRUD tests ─────────────────────────────────────────────

    public function testGetTokenOverridesReturnsEmptyByDefault(): void
    {
        $this->assertSame([], pp_get_token_overrides());
    }

    public function testSetTokenOverrideStoresValue(): void
    {
        $result = pp_set_token_override('--color-accent', '#b45309');
        $this->assertTrue($result);
        $overrides = pp_get_token_overrides();
        $this->assertSame('#b45309', $overrides['--color-accent']);
    }

    public function testSetMultipleOverrides(): void
    {
        pp_set_token_override('--color-accent', '#b45309');
        pp_set_token_override('--font-heading', 'Georgia, serif');
        $overrides = pp_get_token_overrides();
        $this->assertCount(2, $overrides);
        $this->assertSame('#b45309', $overrides['--color-accent']);
        $this->assertSame('Georgia, serif', $overrides['--font-heading']);
    }

    public function testSetTokenOverrideOverwritesPrevious(): void
    {
        pp_set_token_override('--color-accent', '#b45309');
        pp_set_token_override('--color-accent', '#1e40af');
        $overrides = pp_get_token_overrides();
        $this->assertSame('#1e40af', $overrides['--color-accent']);
    }

    public function testClearTokenOverrideRemovesSingleToken(): void
    {
        pp_set_token_override('--color-accent', '#b45309');
        pp_set_token_override('--font-heading', 'Georgia, serif');
        $result = pp_clear_token_override('--color-accent');
        $this->assertTrue($result);
        $overrides = pp_get_token_overrides();
        $this->assertArrayNotHasKey('--color-accent', $overrides);
        $this->assertSame('Georgia, serif', $overrides['--font-heading']);
    }

    public function testClearTokenOverrideReturnsFalseForMissing(): void
    {
        $result = pp_clear_token_override('--nonexistent');
        $this->assertFalse($result);
    }

    public function testClearAllTokenOverrides(): void
    {
        pp_set_token_override('--color-accent', '#b45309');
        pp_set_token_override('--font-heading', 'Georgia, serif');
        $count = pp_clear_all_token_overrides();
        $this->assertSame(2, $count);
        $this->assertSame([], pp_get_token_overrides());
    }

    public function testClearAllTokenOverridesReturnsZeroWhenEmpty(): void
    {
        $count = pp_clear_all_token_overrides();
        $this->assertSame(0, $count);
    }

    // ── Merged reading tests ──────────────────────────────────────────────────

    public function testDesignTokensMergesOverrides(): void
    {
        $defaults = pp_design_tokens();
        $defaultAccent = $defaults['--color-accent']['value'];

        pp_set_token_override('--color-accent', '#b45309');
        // Cache was invalidated by pp_set_token_override
        $merged = pp_design_tokens();

        $this->assertSame('#b45309', $merged['--color-accent']['value']);
        // Type preserved from defaults
        $this->assertSame($defaults['--color-accent']['type'], $merged['--color-accent']['type']);
        // Non-overridden tokens unchanged
        $this->assertSame($defaults['--color-bg']['value'], $merged['--color-bg']['value']);
    }

    public function testDesignTokensWithNoOverridesMatchesDefaults(): void
    {
        $defaults = pp_design_tokens();
        // No overrides set — should be identical to file-only read
        $this->assertNotEmpty($defaults);
        foreach ($defaults as $token => $info) {
            $this->assertArrayHasKey('value', $info);
            $this->assertArrayHasKey('type', $info);
        }
    }

    public function testDesignTokensIgnoresOverridesForUnknownTokens(): void
    {
        pp_set_token_override('--nonexistent-token', 'red');
        $tokens = pp_design_tokens();
        // Unknown token should NOT appear in merged result
        $this->assertArrayNotHasKey('--nonexistent-token', $tokens);
    }

    public function testCacheInvalidationAfterOverrideChange(): void
    {
        $before = pp_design_tokens();
        $originalAccent = $before['--color-accent']['value'];

        pp_set_token_override('--color-accent', '#ff0000');
        $after = pp_design_tokens();

        $this->assertSame('#ff0000', $after['--color-accent']['value']);
        $this->assertNotSame($originalAccent, $after['--color-accent']['value']);
    }
}
