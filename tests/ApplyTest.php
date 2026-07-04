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

    public function testValidateRejectsAngleBrackets(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--font-body', 'value' => 'system-ui</style><script>alert(1)</script>']);
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

    // ── Length validator extension: clamp, calc, unitless 0 ────────────────

    public function testLengthAcceptsUnitlessZero(): void
    {
        $this->assertTrue(_pp_validate_length('0'));
    }

    public function testLengthAcceptsClamp(): void
    {
        $this->assertTrue(_pp_validate_length('clamp(2.5rem, 5vw, 4rem)'));
    }

    public function testLengthAcceptsClampWithPercent(): void
    {
        $this->assertTrue(_pp_validate_length('clamp(1rem, 50%, 3rem)'));
    }

    public function testLengthAcceptsCalc(): void
    {
        $this->assertTrue(_pp_validate_length('calc(100% - 2rem)'));
    }

    public function testLengthAcceptsCalcWithMultiplication(): void
    {
        $this->assertTrue(_pp_validate_length('calc(1.5rem * 2)'));
    }

    public function testLengthRejectsVarInsideClamp(): void
    {
        $this->assertFalse(_pp_validate_length('clamp(1rem, var(--attacker-token), 10rem)'));
    }

    public function testLengthRejectsVarInsideCalc(): void
    {
        $this->assertFalse(_pp_validate_length('calc(var(--space-md) + 1rem)'));
    }

    public function testLengthRejectsEnvInsideClamp(): void
    {
        $this->assertFalse(_pp_validate_length('clamp(0px, env(safe-area-inset-top), 100px)'));
    }

    public function testLengthRejectsUrlInsideCalc(): void
    {
        $this->assertFalse(_pp_validate_length('calc(url(evil) + 1rem)'));
    }

    public function testLengthRejectsArbitraryFunction(): void
    {
        $this->assertFalse(_pp_validate_length('clamp(1rem, max(2vw, 3rem), 5rem)'));
    }

    public function testLengthRejectsBareNumberNonZero(): void
    {
        $this->assertFalse(_pp_validate_length('16'));
    }

    public function testLengthRejectsCssKeywordNone(): void
    {
        $this->assertFalse(_pp_validate_length('none'));
    }

    public function testLengthRejectsCssKeywordAuto(): void
    {
        $this->assertFalse(_pp_validate_length('auto'));
    }

    public function testLengthRejectsCssKeywordInherit(): void
    {
        $this->assertFalse(_pp_validate_length('inherit'));
    }

    public function testLengthRejectsCssKeywordUnset(): void
    {
        $this->assertFalse(_pp_validate_length('unset'));
    }

    // ── Length validator: intended "must start with a number" check (#129) ──
    //
    // The check that rejects structurally nonsensical calc()/clamp() bodies
    // (e.g. a bare unit with no operand) used to be a dead if-body — the
    // condition was evaluated and its result discarded. These pin down the
    // now-enforced behavior against the issue's own test matrix.

    public function testLengthRejectsCalcWithNoOperand(): void
    {
        // "px" is an allowed unit word and every character is otherwise
        // permitted, so without the start-of-contents check this validated.
        $this->assertFalse(_pp_validate_length('calc(px)'));
    }

    public function testLengthRejectsClampWithAllBareUnits(): void
    {
        $this->assertFalse(_pp_validate_length('clamp(rem, rem, rem)'));
    }

    public function testLengthAcceptsCalcWithNestedParens(): void
    {
        // Starts with an opening paren, not a digit — must stay valid.
        $this->assertTrue(_pp_validate_length('calc((100% - 2rem) / 2)'));
    }

    public function testLengthAcceptsClampWithViewportUnit(): void
    {
        $this->assertTrue(_pp_validate_length('clamp(1rem, 2.5vw, 3rem)'));
    }

    public function testLengthAcceptsLeadingDotInCalc(): void
    {
        // ".5rem"-style values (no leading zero) must remain valid.
        $this->assertTrue(_pp_validate_length('calc(.5rem + 1rem)'));
    }

    public function testLengthAcceptsWhitespaceAfterOpeningParen(): void
    {
        // "calc( 1rem + 2rem)" — valid CSS with a space right after the
        // opening paren. The start-of-contents check must skip leading
        // whitespace, not treat the space itself as the disqualifying
        // first character.
        $this->assertTrue(_pp_validate_length('calc( 1rem + 2rem)'));
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
        // 1 base change + 4 derived accent family tokens auto-updated
        $this->assertCount(5, $result['changes']);
        $this->assertEquals('#3157f4', $result['changes'][0]['from']);
        $this->assertEquals('#b45309', $result['changes'][0]['to']);

        // Verify the override and derived tokens are in the database
        $overrides = pp_get_token_overrides();
        $this->assertEquals('#b45309', $overrides['--color-accent']);
        $this->assertArrayHasKey('--color-accent-hover', $overrides);
        $this->assertArrayHasKey('--color-accent-strong', $overrides);
        $this->assertArrayHasKey('--color-border-accent', $overrides);
        $this->assertArrayHasKey('--color-surface-accent', $overrides);
    }

    public function testAccentFamilyDerivation(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#7a4f2e']);

        $this->assertTrue($result['ok']);
        $overrides = pp_get_token_overrides();

        // All derived tokens should be brown-family, not blue
        $hover = _pp_hex_to_rgb($overrides['--color-accent-hover']);
        $strong = _pp_hex_to_rgb($overrides['--color-accent-strong']);
        $border = _pp_hex_to_rgb($overrides['--color-border-accent']);
        $surface = _pp_hex_to_rgb($overrides['--color-surface-accent']);

        // Hover and strong should be darker than base (lower R channel for brown)
        $base = _pp_hex_to_rgb('#7a4f2e');
        $this->assertLessThan($base[0], $hover[0], 'Hover should be darker');
        $this->assertLessThan($hover[0], $strong[0], 'Strong should be darker than hover');

        // Border-accent should be lighter (pastel)
        $this->assertGreaterThan($base[0], $border[0], 'Border-accent should be lighter');

        // Surface-accent should be very light
        $this->assertGreaterThan(200, $surface[0], 'Surface-accent should be very light');
    }

    public function testTextFamilyDerivation(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--color-text', 'value' => '#1a1208']);
        $this->assertTrue($result['ok']);

        $overrides = pp_get_token_overrides();
        $this->assertArrayHasKey('--color-text-secondary', $overrides);

        // Secondary should be lighter than text
        $text = _pp_hex_to_rgb('#1a1208');
        $secondary = _pp_hex_to_rgb($overrides['--color-text-secondary']);
        $this->assertGreaterThan($text[0], $secondary[0]);
    }

    public function testNonFamilyTokenDoesNotDerive(): void
    {
        $result = pp_execute_apply('update_design_token', ['token' => '--color-bg', 'value' => '#f0f0f0']);
        $this->assertTrue($result['ok']);
        // Only 1 change (no derived tokens for --color-bg)
        $this->assertCount(1, $result['changes']);
    }

    public function testFallbackDerivationSkipsExistingOverrides(): void
    {
        // Pre-set an explicit override for --color-accent-strong (a blue the AI chose)
        pp_set_token_override('--color-accent-strong', '#2744b7');

        // Now update the base accent to brown
        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#7a4f2e']);
        $this->assertTrue($result['ok']);

        // The explicit --color-accent-strong override should be preserved (NOT overwritten)
        $overrides = pp_get_token_overrides();
        $this->assertEquals('#2744b7', $overrides['--color-accent-strong'],
            'Existing override must not be overwritten by derivation');

        // Other derived tokens without overrides should still be auto-derived
        $this->assertArrayHasKey('--color-accent-hover', $overrides);
        $this->assertArrayHasKey('--color-border-accent', $overrides);
        $this->assertArrayHasKey('--color-surface-accent', $overrides);

        // Changes should NOT include --color-accent-strong (it was skipped)
        $changed_tokens = array_column($result['changes'], 'token');
        $this->assertNotContains('--color-accent-strong', $changed_tokens);
        // But should include the other 3 derived + 1 base = 4 changes
        $this->assertCount(4, $result['changes']);
    }

    public function testFallbackDerivationReturnsStaleWarnings(): void
    {
        // Pre-set a blue override for a derived token
        pp_set_token_override('--color-accent-strong', '#2744b7');

        // Update base to brown — hue drift should trigger a stale warning
        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#7a4f2e']);
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('stale_warnings', $result);
        $this->assertNotEmpty($result['stale_warnings']);

        // The warning should be about --color-accent-strong
        $warned_tokens = array_column($result['stale_warnings'], 'token');
        $this->assertContains('--color-accent-strong', $warned_tokens);
    }

    public function testNoStaleWarningsWhenCoherent(): void
    {
        // Pre-set a brown override that's coherent with brown base (same hue family)
        pp_set_token_override('--color-accent-strong', '#563820');

        // Update base to similar brown — hue drift should be small, no warning
        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#7a4f2e']);
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('stale_warnings', $result,
            'Coherent overrides should not produce stale warnings');
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
        // Setting --color-accent also auto-derives 4 family tokens (5 total + 1 for --color-bg = 6)
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);
        pp_execute_apply('update_design_token', ['token' => '--color-bg', 'value' => '#000000']);

        $result = pp_execute_apply('reset_all_design_tokens', []);
        $this->assertTrue($result['ok']);
        $this->assertCount(6, $result['changes']);
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

    // ── Number Type Validation ───────────────────────────────────────────

    public function testValidateNumberAcceptsInteger(): void
    {
        $this->assertTrue(_pp_validate_number('650'));
    }

    public function testValidateNumberAcceptsDecimal(): void
    {
        $this->assertTrue(_pp_validate_number('1.6'));
    }

    public function testValidateNumberAcceptsSmallDecimal(): void
    {
        $this->assertTrue(_pp_validate_number('0.85'));
    }

    public function testValidateNumberRejectsWithUnit(): void
    {
        $this->assertFalse(_pp_validate_number('650px'));
    }

    public function testValidateNumberRejectsWord(): void
    {
        $this->assertFalse(_pp_validate_number('bold'));
    }

    public function testValidateNumberRejectsEmpty(): void
    {
        $this->assertFalse(_pp_validate_number(''));
    }

    public function testValidateTokenValuePassesForNumberType(): void
    {
        $result = _pp_validate_token_value('1.6', 'number');
        $this->assertTrue($result);
    }

    public function testValidateTokenValueFailsForInvalidNumber(): void
    {
        $result = _pp_validate_token_value('bold', 'number');
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // ── Shadow Type Validation ───────────────────────────────────────────

    public function testValidateShadowAcceptsPresets(): void
    {
        foreach (['none', 'var(--shadow-none)', 'var(--shadow-sm)', 'var(--shadow-md)', 'var(--shadow-lg)'] as $preset) {
            $this->assertTrue(_pp_validate_shadow($preset), "Preset {$preset} should be accepted.");
        }
    }

    public function testValidateShadowAcceptsBoundedFreeform(): void
    {
        $this->assertTrue(_pp_validate_shadow('0 1px 2px rgba(0,0,0,0.1)'));
        $this->assertTrue(_pp_validate_shadow('0 4px 6px 0 rgba(0, 0, 0, 0.15)'));
        $this->assertTrue(_pp_validate_shadow('-2px -2px 4px hsla(0, 0%, 0%, 0.2)'));
        $this->assertTrue(_pp_validate_shadow('0 2px #000'));
    }

    public function testValidateShadowRejectsInset(): void
    {
        $this->assertFalse(_pp_validate_shadow('inset 0 1px 2px rgba(0,0,0,0.1)'));
    }

    public function testValidateShadowRejectsMultiLayer(): void
    {
        $this->assertFalse(_pp_validate_shadow('0 1px 2px #000, 0 2px 4px #000'));
    }

    public function testValidateShadowRejectsUrl(): void
    {
        $this->assertFalse(_pp_validate_shadow('0 1px 2px url(evil) rgba(0,0,0,0.1)'));
    }

    public function testValidateShadowRejectsArbitraryVar(): void
    {
        // Only the preset allowlist passes; an arbitrary var() must be rejected.
        $this->assertFalse(_pp_validate_shadow('0 1px var(--attacker) rgba(0,0,0,0.1)'));
        $this->assertFalse(_pp_validate_shadow('var(--shadow-evil)'));
    }

    public function testValidateShadowRejectsNegativeBlur(): void
    {
        // Offsets may be negative; blur (3rd value) must not.
        $this->assertFalse(_pp_validate_shadow('0 1px -2px rgba(0,0,0,0.1)'));
    }

    public function testValidateShadowRejectsTooFewOrManyLengths(): void
    {
        $this->assertFalse(_pp_validate_shadow('1px rgba(0,0,0,0.1)'));               // 1 length
        $this->assertFalse(_pp_validate_shadow('0 1px 2px 3px 4px rgba(0,0,0,0.1)')); // 5 lengths
    }

    public function testValidateShadowRejectsMissingColor(): void
    {
        $this->assertFalse(_pp_validate_shadow('0 1px 2px'));
    }

    public function testValidateTokenValuePassesForShadowType(): void
    {
        $this->assertTrue(_pp_validate_token_value('var(--shadow-md)', 'shadow'));
        $this->assertTrue(_pp_validate_token_value('0 1px 2px rgba(0,0,0,0.1)', 'shadow'));
    }

    public function testValidateTokenValueFailsForInvalidShadow(): void
    {
        $result = _pp_validate_token_value('inset 0 0 5px red', 'shadow');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_shadow', $result->get_error_code());
    }

    public function testValidateShadowInjectionBlockedUpstream(): void
    {
        // The {};<> guard runs before the type switch in _pp_validate_token_value.
        $result = _pp_validate_token_value('0 1px 2px rgba(0,0,0,0.1); evil', 'shadow');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('injection', $result->get_error_code());
    }

    // ── New Token Declarations ───────────────────────────────────────────

    public function testNewTokensFontWeightHeadingExists(): void
    {
        $tokens = pp_design_tokens();
        $this->assertArrayHasKey('--font-weight-heading', $tokens);
        $this->assertSame('number', $tokens['--font-weight-heading']['type']);
        $this->assertSame('650', $tokens['--font-weight-heading']['value']);
    }

    public function testNewTokensLineHeightBodyExists(): void
    {
        $tokens = pp_design_tokens();
        $this->assertArrayHasKey('--line-height-body', $tokens);
        $this->assertSame('number', $tokens['--line-height-body']['type']);
        $this->assertSame('1.6', $tokens['--line-height-body']['value']);
    }

    public function testNewTokensLineHeightHeadingExists(): void
    {
        $tokens = pp_design_tokens();
        $this->assertArrayHasKey('--line-height-heading', $tokens);
        $this->assertSame('number', $tokens['--line-height-heading']['type']);
        $this->assertSame('1.2', $tokens['--line-height-heading']['value']);
    }

    public function testNewTokensBtnPaddingYExists(): void
    {
        $tokens = pp_design_tokens();
        $this->assertArrayHasKey('--btn-padding-y', $tokens);
        $this->assertSame('length', $tokens['--btn-padding-y']['type']);
    }

    public function testNewTokensBtnPaddingXExists(): void
    {
        $tokens = pp_design_tokens();
        $this->assertArrayHasKey('--btn-padding-x', $tokens);
        $this->assertSame('length', $tokens['--btn-padding-x']['type']);
    }

    // ── Font apply tests ─────────────────────────────────────────────────

    public function testEnqueueFontValid(): void
    {
        // Clear any existing fonts.
        pp_set_font_urls([]);

        $result = pp_validate_apply('enqueue_font', ['url' => 'https://fonts.googleapis.com/css2?family=Inter']);
        $this->assertTrue($result);
    }

    public function testEnqueueFontRejectsNonHttps(): void
    {
        pp_set_font_urls([]);
        $result = pp_validate_apply('enqueue_font', ['url' => 'http://fonts.googleapis.com/css2?family=Inter']);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testEnqueueFontRejectsInvalidUrl(): void
    {
        pp_set_font_urls([]);
        $result = pp_validate_apply('enqueue_font', ['url' => 'not-a-url']);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testEnqueueFontRejectsDuplicate(): void
    {
        pp_set_font_urls(['https://fonts.googleapis.com/css2?family=Inter']);
        $result = pp_validate_apply('enqueue_font', ['url' => 'https://fonts.googleapis.com/css2?family=Inter']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('duplicate_font', $result->get_error_code());
    }

    public function testEnqueueFontRejectsOverLimit(): void
    {
        pp_set_font_urls([
            'https://fonts.example.com/1',
            'https://fonts.example.com/2',
            'https://fonts.example.com/3',
            'https://fonts.example.com/4',
            'https://fonts.example.com/5',
        ]);
        $result = pp_validate_apply('enqueue_font', ['url' => 'https://fonts.example.com/6']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('font_limit', $result->get_error_code());
    }

    public function testEnqueueFontExecute(): void
    {
        pp_set_font_urls([]);
        $result = pp_execute_apply('enqueue_font', ['url' => 'https://fonts.googleapis.com/css2?family=Inter']);
        $this->assertTrue($result['ok']);
        $this->assertContains('https://fonts.googleapis.com/css2?family=Inter', pp_get_font_urls());
    }

    public function testRemoveFontExecute(): void
    {
        pp_set_font_urls(['https://fonts.googleapis.com/css2?family=Inter']);
        $result = pp_execute_apply('remove_font', ['url' => 'https://fonts.googleapis.com/css2?family=Inter']);
        $this->assertTrue($result['ok']);
        $this->assertEmpty(pp_get_font_urls());
    }

    public function testRemoveFontRejectsNotFound(): void
    {
        pp_set_font_urls([]);
        $result = pp_validate_apply('remove_font', ['url' => 'https://fonts.googleapis.com/not-enqueued']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('font_not_found', $result->get_error_code());
    }

    public function testResetFontsExecute(): void
    {
        pp_set_font_urls(['https://fonts.example.com/1', 'https://fonts.example.com/2']);
        $result = pp_execute_apply('reset_fonts', []);
        $this->assertTrue($result['ok']);
        $this->assertEmpty(pp_get_font_urls());
    }

    // ── _pp_hash_all_theme_files() tests ────────────────────────────────────

    public function testHashAllThemeFilesReturnsHashesForAllFileTypes(): void
    {
        $dir = sys_get_temp_dir() . '/pp-hash-test-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0755, true);

        // Create files of various types.
        file_put_contents($dir . '/test.php', '<?php echo "hi";');
        file_put_contents($dir . '/style.css', 'body { color: red; }');
        file_put_contents($dir . '/app.js', 'console.log("hi");');
        file_put_contents($dir . '/data.json', '{"key": "val"}');
        file_put_contents($dir . '/README.md', '# Readme');
        file_put_contents($dir . '/notes.txt', 'notes');
        file_put_contents($dir . '/LICENSE', 'MIT');

        $hashes = _pp_hash_all_theme_files($dir);

        $this->assertArrayHasKey('test.php', $hashes);
        $this->assertArrayHasKey('style.css', $hashes);
        $this->assertArrayHasKey('app.js', $hashes);
        $this->assertArrayHasKey('data.json', $hashes);
        $this->assertArrayHasKey('README.md', $hashes);
        $this->assertArrayHasKey('notes.txt', $hashes);
        $this->assertArrayHasKey('LICENSE', $hashes);

        // Each hash should be a 32-char MD5.
        foreach ($hashes as $hash) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash);
        }

        $this->recursiveDelete($dir);
    }

    public function testHashAllThemeFilesSkipsDistignoreDirs(): void
    {
        $dir = sys_get_temp_dir() . '/pp-hash-test-' . getmypid() . '-' . mt_rand();
        mkdir($dir . '/scripts', 0755, true);
        mkdir($dir . '/tests', 0755, true);
        mkdir($dir . '/node_modules', 0755, true);
        mkdir($dir . '/vendor', 0755, true);
        mkdir($dir . '/.git', 0755, true);

        file_put_contents($dir . '/keep.php', '<?php');
        file_put_contents($dir . '/scripts/build.sh', '#!/bin/bash');
        file_put_contents($dir . '/tests/Test.php', '<?php');
        file_put_contents($dir . '/node_modules/pkg.js', 'module');
        file_put_contents($dir . '/vendor/lib.php', '<?php');
        file_put_contents($dir . '/.git/config', '[core]');

        $hashes = _pp_hash_all_theme_files($dir);

        $this->assertArrayHasKey('keep.php', $hashes);
        $this->assertArrayNotHasKey('scripts/build.sh', $hashes);
        $this->assertArrayNotHasKey('tests/Test.php', $hashes);
        $this->assertArrayNotHasKey('node_modules/pkg.js', $hashes);
        $this->assertArrayNotHasKey('vendor/lib.php', $hashes);
        $this->assertArrayNotHasKey('.git/config', $hashes);

        $this->recursiveDelete($dir);
    }

    public function testHashAllThemeFilesSkipsDistignoreFiles(): void
    {
        $dir = sys_get_temp_dir() . '/pp-hash-test-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/keep.php', '<?php');
        file_put_contents($dir . '/composer.json', '{}');
        file_put_contents($dir . '/package.json', '{}');
        file_put_contents($dir . '/CLAUDE.md', '# Claude');
        file_put_contents($dir . '/.distignore', '# dist');
        file_put_contents($dir . '/phpunit.xml', '<phpunit/>');

        $hashes = _pp_hash_all_theme_files($dir);

        $this->assertArrayHasKey('keep.php', $hashes);
        $this->assertArrayNotHasKey('composer.json', $hashes);
        $this->assertArrayNotHasKey('package.json', $hashes);
        $this->assertArrayNotHasKey('CLAUDE.md', $hashes);
        $this->assertArrayNotHasKey('.distignore', $hashes);
        $this->assertArrayNotHasKey('phpunit.xml', $hashes);

        $this->recursiveDelete($dir);
    }

    public function testHashAllThemeFilesSkipsIntegrityManifest(): void
    {
        $dir = sys_get_temp_dir() . '/pp-hash-test-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/keep.php', '<?php');
        file_put_contents($dir . '/integrity-manifest.json', '{"version":"0.7.0"}');

        $hashes = _pp_hash_all_theme_files($dir);

        $this->assertArrayHasKey('keep.php', $hashes);
        $this->assertArrayNotHasKey('integrity-manifest.json', $hashes);

        $this->recursiveDelete($dir);
    }

    public function testHashAllThemeFilesSkipsDotfiles(): void
    {
        $dir = sys_get_temp_dir() . '/pp-hash-test-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/keep.php', '<?php');
        file_put_contents($dir . '/.DS_Store', 'binary');
        file_put_contents($dir . '/.phpunit.result.cache', 'cache');

        $hashes = _pp_hash_all_theme_files($dir);

        $this->assertArrayHasKey('keep.php', $hashes);
        $this->assertArrayNotHasKey('.DS_Store', $hashes);
        $this->assertArrayNotHasKey('.phpunit.result.cache', $hashes);

        $this->recursiveDelete($dir);
    }

    public function testHashAllThemeFilesSkipsZipPattern(): void
    {
        $dir = sys_get_temp_dir() . '/pp-hash-test-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/keep.php', '<?php');
        file_put_contents($dir . '/promptingpress-0.7.0.zip', 'PK');

        $hashes = _pp_hash_all_theme_files($dir);

        $this->assertArrayHasKey('keep.php', $hashes);
        $this->assertArrayNotHasKey('promptingpress-0.7.0.zip', $hashes);

        $this->recursiveDelete($dir);
    }

    public function testHashAllThemeFilesReturnsSortedKeys(): void
    {
        $dir = sys_get_temp_dir() . '/pp-hash-test-' . getmypid() . '-' . mt_rand();
        mkdir($dir . '/lib', 0755, true);
        mkdir($dir . '/components', 0755, true);

        file_put_contents($dir . '/lib/wp.php', '<?php');
        file_put_contents($dir . '/components/hero.php', '<?php');
        file_put_contents($dir . '/functions.php', '<?php');

        $hashes = _pp_hash_all_theme_files($dir);
        $keys = array_keys($hashes);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);

        $this->recursiveDelete($dir);
    }

    public function testHashAllThemeFilesTreatsUnreadableAsFalse(): void
    {
        $dir = sys_get_temp_dir() . '/pp-hash-test-' . getmypid() . '-' . mt_rand();
        mkdir($dir, 0755, true);

        file_put_contents($dir . '/readable.php', '<?php');
        file_put_contents($dir . '/unreadable.php', '<?php');
        chmod($dir . '/unreadable.php', 0000);

        $hashes = _pp_hash_all_theme_files($dir);

        $this->assertArrayHasKey('readable.php', $hashes);
        $this->assertNotFalse($hashes['readable.php']);

        // If the file is truly unreadable (depends on test runner not being root),
        // md5_file returns false and we store false.
        if (!is_readable($dir . '/unreadable.php')) {
            $this->assertArrayHasKey('unreadable.php', $hashes);
            $this->assertFalse($hashes['unreadable.php']);
        }

        chmod($dir . '/unreadable.php', 0644);
        $this->recursiveDelete($dir);
    }

    // ── pp_revert_tokens — scoped per-run rollback primitive (#101) ─────────

    /** Helper: set the raw override map and refresh the token cache. */
    private function setOverrides(array $map): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_token_overrides'] = $map;
        pp_invalidate_design_tokens_cache();
    }

    public function testRevertTokensRevertsTouchedAndPreservesUntouched(): void
    {
        // Snapshot = the run's pre-apply state (accent only). Live state has the run's
        // accent change PLUS an unrelated later override (--color-text). Reverting the
        // touched key must restore accent and leave --color-text untouched.
        $snapshot = ['--color-accent' => '#b45309'];
        $this->setOverrides(['--color-accent' => '#aaaaaa', '--color-text' => '#123456']);

        $this->assertTrue(pp_revert_tokens($snapshot, ['--color-accent']));

        $after = pp_get_token_overrides();
        $this->assertSame('#b45309', $after['--color-accent']);
        $this->assertSame('#123456', $after['--color-text'], 'untouched later override must survive');
    }

    public function testRevertTokensRemovesRunCreatedKeys(): void
    {
        // The run created --color-accent (absent from snapshot); rollback removes it.
        $this->setOverrides(['--color-accent' => '#aaaaaa']);
        $this->assertTrue(pp_revert_tokens([], ['--color-accent']));
        $this->assertArrayNotHasKey('--color-accent', pp_get_token_overrides());
    }

    public function testRevertTokensCoreRegressionNoCollateralWipe(): void
    {
        // The #101 footgun: changing one token must NOT wipe unrelated overrides.
        $snapshot = [
            '--color-accent'         => '#b45309',
            '--color-text'           => '#1a1a1a',
            '--color-accent-strong'  => '#2744b7',
        ];
        // Run changed accent and derived a family member; the rest are unrelated.
        $this->setOverrides([
            '--color-accent'         => '#aaaaaa',
            '--color-text'           => '#1a1a1a',
            '--color-accent-strong'  => '#2744b7',
            '--color-accent-hover'   => '#909090',
        ]);
        $touched = ['--color-accent', '--color-accent-hover'];

        $this->assertTrue(pp_revert_tokens($snapshot, $touched));

        $after = pp_get_token_overrides();
        $this->assertSame('#b45309', $after['--color-accent'], 'accent reverted to snapshot');
        $this->assertArrayNotHasKey('--color-accent-hover', $after, 'run-created derived key removed');
        $this->assertSame('#1a1a1a', $after['--color-text'], 'unrelated override preserved');
        $this->assertSame('#2744b7', $after['--color-accent-strong'], 'unrelated override preserved');
    }

    public function testRevertTokensFamilyFullCleanup(): void
    {
        // Snapshot empty: an update_design_token that derived a full family is rolled
        // back by removing every touched key (primary + derived).
        $this->setOverrides([
            '--color-accent'         => '#aaaaaa',
            '--color-accent-hover'   => '#8f8f8f',
            '--color-accent-strong'  => '#777777',
            '--color-border-accent'  => '#cccccc',
            '--color-surface-accent' => '#eeeeee',
        ]);
        $touched = [
            '--color-accent', '--color-accent-hover', '--color-accent-strong',
            '--color-border-accent', '--color-surface-accent',
        ];
        $this->assertTrue(pp_revert_tokens([], $touched));
        $this->assertSame([], pp_get_token_overrides());
    }

    public function testRevertTokensAbortsOnInvalidSnapshotValue(): void
    {
        // Fail-closed: a corrupt snapshot value aborts the whole revert with no write.
        $this->setOverrides(['--color-accent' => '#aaaaaa']);
        $this->assertFalse(pp_revert_tokens(['--color-accent' => 'not-a-color'], ['--color-accent']));
        $this->assertSame('#aaaaaa', pp_get_token_overrides()['--color-accent']);
    }

    public function testRevertTokensAbortsOnUnregisteredToken(): void
    {
        // A token not in the registry aborts the revert; nothing is mutated.
        $this->setOverrides(['--bogus-token' => '#aaaaaa']);
        $this->assertFalse(pp_revert_tokens(['--bogus-token' => '#ffffff'], ['--bogus-token']));
        $this->assertSame('#aaaaaa', pp_get_token_overrides()['--bogus-token']);
    }

    public function testRevertTokensInvalidEntryAbortsEntireScopeNoPartialWrite(): void
    {
        // One bad scoped entry must abort the WHOLE revert — the valid sibling in the
        // same call is NOT applied. Proves no-partial-mutation on corrupt snapshots.
        $this->setOverrides(['--color-accent' => '#aaaaaa', '--color-text' => '#bbbbbb']);
        $snapshot = ['--color-accent' => '#b45309', '--color-text' => 'not-a-color'];
        $this->assertFalse(pp_revert_tokens($snapshot, ['--color-accent', '--color-text']));
        $after = pp_get_token_overrides();
        $this->assertSame('#aaaaaa', $after['--color-accent'], 'valid sibling must NOT be applied when scope aborts');
        $this->assertSame('#bbbbbb', $after['--color-text']);
    }

    public function testRevertTokensLeavesUntouchedKeysEntirely(): void
    {
        // Keys outside the touched scope are never inspected or changed.
        $this->setOverrides(['--color-accent' => '#aaaaaa', '--color-text' => '#123456']);
        $this->assertTrue(pp_revert_tokens(['--color-accent' => '#b45309', '--color-text' => '#000000'], ['--color-accent']));
        $after = pp_get_token_overrides();
        $this->assertSame('#b45309', $after['--color-accent']);
        $this->assertSame('#123456', $after['--color-text'], 'snapshot value ignored for untouched key');
    }
}
