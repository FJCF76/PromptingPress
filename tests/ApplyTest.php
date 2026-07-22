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

    // ── #230: transparent / currentColor keywords ───────────────────────────

    public function testColorAcceptsTransparentKeyword(): void
    {
        $this->assertTrue(pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'transparent']));
        $this->assertTrue(pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'Transparent']));
        $this->assertTrue(pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'TRANSPARENT']));
    }

    public function testColorAcceptsCurrentColorKeyword(): void
    {
        $this->assertTrue(pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'currentColor']));
        $this->assertTrue(pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'currentcolor']));
        $this->assertTrue(pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'CURRENTCOLOR']));
    }

    public function testColorRejectsWhitespacePaddedKeyword(): void
    {
        // Anchored like every other accepted form — '#fff ' fails, so does this.
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => ' transparent']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'transparent ']);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // ── #230: single bare var(--token) references ────────────────────────────

    public function testColorAcceptsVarReferenceToRegisteredToken(): void
    {
        // The issue's acceptance example: product defaults that SHIP as var()
        // (--text-meta-color: var(--color-muted)) become settable/re-settable.
        $this->assertTrue(pp_validate_apply('update_design_token', ['token' => '--text-meta-color', 'value' => 'var(--color-muted)']));
        $this->assertTrue(pp_validate_apply('update_design_token', ['token' => '--text-kicker-color', 'value' => 'var(--color-accent)']));
    }

    public function testColorRejectsVarReferenceToUnknownToken(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'var(--nonexistent-token)']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_color', $result->get_error_code());
    }

    public function testColorRejectsVarWithFallback(): void
    {
        // The fallback-smuggling vector the strict pattern exists to block.
        foreach (['var(--color-accent, #fff)', 'var(--color-accent, url(evil))'] as $value) {
            $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => $value]);
            $this->assertInstanceOf(WP_Error::class, $result, $value);
            $this->assertEquals('invalid_color', $result->get_error_code(), $value);
        }
    }

    public function testColorRejectsMultipleOrNestedVar(): void
    {
        foreach (['var(--color-accent) var(--color-muted)', 'var(var(--color-accent))'] as $value) {
            $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => $value]);
            $this->assertInstanceOf(WP_Error::class, $result, $value);
            $this->assertEquals('invalid_color', $result->get_error_code(), $value);
        }
    }

    public function testColorVarPatternIsCaseStrict(): void
    {
        // Lowercase only, matching the registry's token-name charset
        // (base.css tokens are all lowercase-kebab).
        foreach (['Var(--color-accent)', 'var(--COLOR-ACCENT)', 'var( --color-accent )'] as $value) {
            $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => $value]);
            $this->assertInstanceOf(WP_Error::class, $result, $value);
        }
    }

    public function testColorRejectsVarWithTrailingNewline(): void
    {
        // \z anchoring: PCRE's $ matches before a trailing newline; the
        // reference pattern must not.
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => "var(--color-accent)\n"]);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testColorRejectsVarReferenceToNonColorToken(): void
    {
        // Existence is not enough: a color token resolving to "0.25rem" is
        // guaranteed-invalid CSS. The referenced token must be color-typed.
        $result = pp_validate_apply('update_design_token', ['token' => '--color-accent', 'value' => 'var(--space-xs)']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_color', $result->get_error_code());
    }

    public function testColorVarInjectionGuardStillFiresFirst(): void
    {
        // The {};<> guard runs before the type switch in _pp_validate_token_value.
        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'var(--x); evil']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('injection', $result->get_error_code());
    }

    // ── #230: reference-cycle rejection (update_design_token only) ──────────

    public function testTokenSelfReferenceCycleRejected(): void
    {
        $result = pp_validate_apply('update_design_token', ['token' => '--color-accent', 'value' => 'var(--color-accent)']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('token_reference_cycle', $result->get_error_code());
    }

    public function testTokenIndirectReferenceCycleRejected(): void
    {
        // Shipped default: --text-meta-color: var(--color-muted). Pointing
        // --color-muted back at it closes a cycle through a DEFAULT value.
        $result = pp_validate_apply('update_design_token', ['token' => '--color-muted', 'value' => 'var(--text-meta-color)']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('token_reference_cycle', $result->get_error_code());
    }

    public function testTokenVarChainWithoutCycleAccepted(): void
    {
        // --text-meta-color -> var(--color-muted) -> #hex: terminates, no cycle.
        $this->assertTrue(pp_validate_apply('update_design_token', ['token' => '--color-accent', 'value' => 'var(--text-meta-color)']));
    }

    public function testTokenCycleThroughStoredOverrideRejected(): void
    {
        // The walk reads EFFECTIVE values (defaults merged with DB overrides),
        // not just shipped defaults.
        update_option('pp_token_overrides', ['--color-muted' => 'var(--color-accent)'], true);
        pp_invalidate_design_tokens_cache();

        $result = pp_validate_apply('update_design_token', ['token' => '--color-accent', 'value' => 'var(--color-muted)']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('token_reference_cycle', $result->get_error_code());
    }

    public function testVarReferenceIntoPreExistingForeignCycleRejected(): void
    {
        // Stored state can already be cyclic (scoped revert, hand-edited
        // option row). Pointing a THIRD token into that cycle would resolve
        // invalid too — the walk fails closed instead of looping to true.
        update_option('pp_token_overrides', [
            '--color-muted'  => 'var(--color-accent)',
            '--color-accent' => 'var(--color-muted)',
        ], true);
        pp_invalidate_design_tokens_cache();

        $result = pp_validate_apply('update_design_token', ['token' => '--color-bg', 'value' => 'var(--color-accent)']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('token_reference_cycle', $result->get_error_code());
    }

    public function testResetRejectedWhenDefaultWouldReintroduceCycle(): void
    {
        // reset_design_token restores the base.css default, which for
        // --text-meta-color is var(--color-muted). If --color-muted currently
        // points back at --text-meta-color, the reset would persist the exact
        // cycle update_design_token rejects — the guard is symmetric.
        $r1 = pp_execute_apply('update_design_token', ['token' => '--text-meta-color', 'value' => '#111111']);
        $this->assertTrue($r1['ok']);
        // Accepted: the chain terminates at the concrete #111111 override.
        $r2 = pp_execute_apply('update_design_token', ['token' => '--color-muted', 'value' => 'var(--text-meta-color)']);
        $this->assertTrue($r2['ok']);

        $result = pp_validate_apply('reset_design_token', ['token' => '--text-meta-color']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('token_reference_cycle', $result->get_error_code());
    }

    public function testBaseCssVarDefaultsAreBareSameTypeReferences(): void
    {
        // Invariant pin: the cycle walk treats any value that is not a bare
        // var(--token) reference as chain-terminating. That is only sound
        // while every var() default shipped in base.css IS a bare reference
        // to a registered token of the SAME type (color→color, length→length).
        // A future default written as var(--x, #888) or var( --x ) would
        // silently disable cycle detection through that token — this test
        // freezes the invariant. Values are read RAW from base.css (not the
        // merged registry) so a stray override can never mask a bad shipped
        // default; the registry supplies only type metadata.
        $defaults = _pp_read_tokens_from_file($this->baseCssPath);
        $registry = pp_design_tokens();
        $checked  = 0;
        foreach ($defaults as $name => $value) {
            if (strpos($value, 'var(') === false) {
                continue;
            }
            $ref = _pp_parse_token_reference(trim($value));
            $this->assertNotNull($ref, "base.css default for {$name} is a non-bare var() form: {$value}");
            $this->assertArrayHasKey($ref, $defaults, "base.css default for {$name} references unregistered {$ref}");
            $this->assertSame($registry[$name]['type'], $registry[$ref]['type'], "base.css default for {$name} references a token of a different type");

            // The pure-defaults reference graph must also be acyclic:
            // reset_all_design_tokens restores it unconditionally, so a
            // mutually-referencing default pair would persist a cycle no
            // user action created. Chains must reach a concrete value.
            $next  = $ref;
            $steps = count($defaults);
            while ($next !== null && $steps-- > 0) {
                $next = isset($defaults[$next]) ? _pp_parse_token_reference(trim($defaults[$next])) : null;
            }
            $this->assertNull($next, "base.css default for {$name} starts a reference chain that never terminates");
            $checked++;
        }
        $this->assertGreaterThan(0, $checked, 'expected at least one var() default in base.css');
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

    // ── Length validator: signed (negative) lengths (#467) ─────────────────
    //
    // Heading tracking is inherently negative (default -0.03em; a brand may want
    // -0.01em), so the `length` grammar accepts a single leading minus on simple
    // lengths and signed operands inside calc/clamp. Grammar-only: "-" alone and a
    // double minus stay rejected, and the injection/positive-pattern guards are intact.

    public function testLengthAcceptsNegativeEm(): void
    {
        // The token's own default value must validate through the write path.
        $this->assertTrue(_pp_validate_length('-0.03em'));
    }

    public function testLengthAcceptsNegativeRemLeadingDot(): void
    {
        // "-.5rem"-style negatives (no leading zero) must validate too.
        $this->assertTrue(_pp_validate_length('-.5rem'));
    }

    public function testLengthAcceptsNegativePx(): void
    {
        $this->assertTrue(_pp_validate_length('-2px'));
    }

    public function testLengthUpdateTokenAcceptsNegativeHeadingTracking(): void
    {
        // #467 end-to-end write path: the operator's brand value reaches the token.
        $result = pp_validate_apply(
            'update_design_token',
            ['token' => '--letter-spacing-heading', 'value' => '-0.01em']
        );
        $this->assertTrue($result);
    }

    public function testLengthRejectsBareMinus(): void
    {
        // A lone "-" has no numeric operand — must stay rejected.
        $this->assertFalse(_pp_validate_length('-'));
    }

    public function testLengthRejectsDoubleMinus(): void
    {
        // Only a SINGLE leading minus is grammar; "--0.03em" is not a length.
        $this->assertFalse(_pp_validate_length('--0.03em'));
    }

    public function testLengthRejectsMinusUnitNoOperand(): void
    {
        // "-em" is a bare unit with no operand behind the sign.
        $this->assertFalse(_pp_validate_length('-em'));
    }

    public function testLengthAcceptsNegativeTermInCalc(): void
    {
        // A signed operand inside calc() stays valid (real operand behind the unit).
        $this->assertTrue(_pp_validate_length('calc(-0.01em + 2px)'));
    }

    public function testLengthAcceptsWhitespaceAfterOpeningParen(): void
    {
        // "calc( 1rem + 2rem)" — valid CSS with a space right after the
        // opening paren. The start-of-contents check must skip leading
        // whitespace, not treat the space itself as the disqualifying
        // first character.
        $this->assertTrue(_pp_validate_length('calc( 1rem + 2rem)'));
    }

    // ── Bare-unit bypasses surfaced by adversarial review (#129) ────────────
    //
    // The simpler "must start with a digit/sign/paren" check still let a
    // unit word appear anywhere in the expression without a real numeric
    // operand attached to it, as long as SOME digit existed elsewhere in
    // the string. Each of these has an allowed unit word (rem/px) but no
    // number directly adjacent to it — exactly the "validates but persists
    // as broken CSS" failure class #129 describes, just a different shape.

    public function testLengthRejectsUnitWrappedInParens(): void
    {
        $this->assertFalse(_pp_validate_length('calc((rem) + 1px)'));
    }

    public function testLengthRejectsUnitAfterUnaryMinus(): void
    {
        $this->assertFalse(_pp_validate_length('calc(-rem + 1px)'));
    }

    public function testLengthRejectsUnitAfterUnaryPlus(): void
    {
        $this->assertFalse(_pp_validate_length('calc(+rem + 1px)'));
    }

    public function testLengthRejectsBareUnitInClampArgument(): void
    {
        $this->assertFalse(_pp_validate_length('clamp((rem), 1px, 2px)'));
    }

    // ── Length validator: malformed calc()/clamp() + simple-length body (#151) ──
    //
    // #151, recorded decision = Option C (targeted cheap fixes: paren balance +
    // calc top-level comma) + Option 4 (newline /s), PLUS the orchestrator scope
    // addition: tighten the simple-length number body (one well-formed number —
    // optional sign per #467, at most one dot, at least one digit, no whitespace
    // before the unit). No full CSS calc()/clamp() grammar parser is built; the
    // residual shapes below are DOCUMENTED as accepted (won't-fix). Every value
    // the #467 signed grammar pinned above still validates unchanged.

    // -- Simple-length number body: now-rejected malformed shapes ---------------

    public function testLengthRejectsUnitWithNoDigit(): void
    {
        // ".em" has a unit but no numeric operand at all — the loose "[\d.]+"
        // body matched a lone "." and accepted it; the tightened body requires
        // at least one digit.
        $this->assertFalse(_pp_validate_length('.em'));
    }

    public function testLengthRejectsMultipleDotsInBody(): void
    {
        // "1.2.3rem" has two dots — not a well-formed number. The old "[\d.]+"
        // body accepted any run of digits and dots.
        $this->assertFalse(_pp_validate_length('1.2.3rem'));
    }

    public function testLengthRejectsWhitespaceBeforeUnit(): void
    {
        // CSS forbids whitespace between the number and its unit; "1.2 rem" is
        // invalid. The old "\s*" between body and unit accepted it.
        $this->assertFalse(_pp_validate_length('1.2 rem'));
    }

    public function testLengthRejectsLoneDotUnitCombination(): void
    {
        // "." alone with a unit (no digits) — rejected for the same "at least one
        // digit" reason as ".em".
        $this->assertFalse(_pp_validate_length('.px'));
    }

    // -- Simple-length number body: valid shapes stay accepted ------------------

    public function testLengthAcceptsAllUnits(): void
    {
        foreach (['2.5rem', '10px', '1.5em', '50%', '100vw', '3vh'] as $v) {
            $this->assertTrue(_pp_validate_length($v), "expected $v to validate");
        }
    }

    public function testLengthAcceptsLeadingDotSimple(): void
    {
        // ".5rem" (no leading zero) is a well-formed <length> and stays valid.
        $this->assertTrue(_pp_validate_length('.5rem'));
    }

    public function testLengthAcceptsIntegerNoDot(): void
    {
        $this->assertTrue(_pp_validate_length('16px'));
    }

    public function testLengthAcceptsZeroWithUnit(): void
    {
        // "0px" (zero WITH a unit) still validates via the number body; unitless
        // "0" is handled by the earlier explicit branch.
        $this->assertTrue(_pp_validate_length('0px'));
    }

    // -- calc(): paren balance (Option C) --------------------------------------

    public function testLengthRejectsUnbalancedTrailingParen(): void
    {
        // "calc(1))" — the greedy outer extraction leaves a stray ")" in the
        // body; the paren-balance check rejects it.
        $this->assertFalse(_pp_validate_length('calc(1))'));
    }

    public function testLengthRejectsUnbalancedInnerParen(): void
    {
        // "calc((1rem + 2rem)" — an unmatched opening paren inside the body.
        $this->assertFalse(_pp_validate_length('calc((1rem + 2rem)'));
    }

    public function testLengthAcceptsBalancedNestedParens(): void
    {
        // A genuinely balanced nested expression must still validate.
        $this->assertTrue(_pp_validate_length('calc((100% - 2rem) / 2)'));
    }

    public function testLengthRejectsImproperlyNestedButCountBalanced(): void
    {
        // "calc()1,2()" -> body ")1,2(" is COUNT-balanced ("("=1, ")"=1) but a
        // ")" precedes its "(". A substr_count-only check would pass this and let
        // the top-level comma escape the split walker (which would hit negative
        // depth). The proper-nesting walk rejects it (#151, adversarial finding).
        $this->assertFalse(_pp_validate_length('calc()1,2()'));
    }

    public function testLengthRejectsCountBalancedDetachedGroups(): void
    {
        // "calc(1)+(2rem)" is count-balanced but structurally two separate
        // groups, not a nested expression — rejected by the nesting walk.
        $this->assertFalse(_pp_validate_length('calc(1)+(2rem)'));
    }

    public function testLengthRejectsLongDigitRunLinearly(): void
    {
        // Regression guard for the ReDoS surface an adversarial pass found in the
        // first-cut `\d+\.?\d*` body: an all-digit run with a bad unit backtracked
        // O(N^2). The shipped `\d+(?:\.\d*)?` body is linear, so a long input is
        // rejected effectively instantly (this test would hang under the old body).
        $this->assertFalse(_pp_validate_length(str_repeat('9', 50000) . 'x'));
    }

    // -- calc(): top-level comma (Option C); clamp comma form intact -----------

    public function testLengthRejectsCalcWithTopLevelCommas(): void
    {
        // calc() never takes comma-separated arguments — "calc(1,2,3)" is
        // malformed and is rejected.
        $this->assertFalse(_pp_validate_length('calc(1,2,3)'));
    }

    public function testLengthRejectsCalcWithSingleComma(): void
    {
        // A single top-level comma inside calc() is equally malformed.
        $this->assertFalse(_pp_validate_length('calc(1rem, 2rem)'));
    }

    public function testLengthAcceptsClampThreeArgCommaForm(): void
    {
        // clamp()'s legitimate 3-argument comma form is left intact — the
        // calc-only comma rejection must not touch it.
        $this->assertTrue(_pp_validate_length('clamp(1rem, 2vw, 3rem)'));
    }

    // -- Newline handling (Option 4, pure correctness win) ---------------------

    public function testLengthAcceptsNewlineInsideCalc(): void
    {
        // Legal newline whitespace inside calc() must extract (via the /s
        // modifier) and validate, not fail the greedy ".+" extraction outright.
        $this->assertTrue(_pp_validate_length("calc(\n1rem + 2rem)"));
    }

    public function testLengthAcceptsNewlineInsideClamp(): void
    {
        $this->assertTrue(_pp_validate_length("clamp(1rem,\n2vw, 3rem)"));
    }

    // -- Documented accepted residuals (won't-fix; only drop to a dropped decl) --
    //
    // These pin the recorded decision's EXPLICIT scope boundary: bare unitless
    // operands, doubled operators, and two-operand-no-operator are accepted as
    // residual. If a future change tightens them, that is a deliberate scope
    // extension — these tests make the current boundary explicit, not silent.

    public function testLengthAcceptsBareUnitlessCalcResidual(): void
    {
        // "calc(1)" — a lone unitless number. Accepted residual (#151).
        $this->assertTrue(_pp_validate_length('calc(1)'));
    }

    public function testLengthAcceptsBareUnitlessClampResidual(): void
    {
        // "clamp(1,2,3)" — unitless args, but correct clamp arity and commas.
        // The malformation is the bare numbers, an accepted residual (#151).
        $this->assertTrue(_pp_validate_length('clamp(1,2,3)'));
    }

    public function testLengthAcceptsDoubledOperatorResidual(): void
    {
        // "calc(1++2rem)" — doubled operator. Accepted residual (#151).
        $this->assertTrue(_pp_validate_length('calc(1++2rem)'));
    }

    public function testLengthAcceptsTwoOperandNoOperatorResidual(): void
    {
        // "calc(1 1)" — two operands, no operator. Accepted residual (#151).
        $this->assertTrue(_pp_validate_length('calc(1 1)'));
    }

    // -- Section 14.1 authoring-path: real surface, one malformed + one valid ---

    public function testLengthAuthoringPathAcceptsValidThroughUpdateToken(): void
    {
        // Exercises the real authoring surface (update_design_token ->
        // _pp_validate_token_value -> _pp_validate_length), not a raw meta write.
        $result = pp_validate_apply(
            'update_design_token',
            ['token' => '--letter-spacing-heading', 'value' => '-0.02em']
        );
        $this->assertTrue($result);
    }

    public function testLengthAuthoringPathRejectsMalformedThroughUpdateToken(): void
    {
        // A malformed length (space before unit) is rejected through the same
        // real surface with the type-specific error code.
        $result = pp_validate_apply(
            'update_design_token',
            ['token' => '--letter-spacing-heading', 'value' => '1.2 rem']
        );
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
        // 1 base change + 8 derived accent family tokens auto-updated (#437 added
        // --color-accent-on-inverted + -hover; #461 added --color-accent-on-overlay + -hover)
        $this->assertCount(9, $result['changes']);
        $this->assertEquals('#3157f4', $result['changes'][0]['from']);
        $this->assertEquals('#b45309', $result['changes'][0]['to']);

        // Verify the override and derived tokens are in the database
        $overrides = pp_get_token_overrides();
        $this->assertEquals('#b45309', $overrides['--color-accent']);
        $this->assertArrayHasKey('--color-accent-hover', $overrides);
        $this->assertArrayHasKey('--color-accent-strong', $overrides);
        $this->assertArrayHasKey('--color-border-accent', $overrides);
        $this->assertArrayHasKey('--color-surface-accent', $overrides);
        $this->assertArrayHasKey('--color-accent-on-inverted', $overrides);
        $this->assertArrayHasKey('--color-accent-on-inverted-hover', $overrides);
        $this->assertArrayHasKey('--color-accent-on-overlay', $overrides);
        $this->assertArrayHasKey('--color-accent-on-overlay-hover', $overrides);
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

    public function testOnInvertedAccentIsRegisteredDerivedColor(): void
    {
        // #437: the on-inverted accent roles must be REGISTERED derived colors so
        // (a) a retheme's new accent auto-produces matching on-inverted tints, and
        // (b) a pinned on-inverted override that diverges surfaces in the #386
        // masked-derived / stale-warning machinery like every other derived token.

        // Registered in the --color-accent family (the divergence-detection surface).
        $family = pp_token_families()['--color-accent'];
        $this->assertArrayHasKey('--color-accent-on-inverted', $family);
        $this->assertArrayHasKey('--color-accent-on-inverted-hover', $family);

        // Auto-derived as a lightened accent tint when the base changes (unpinned).
        $derived = pp_derive_family_tokens('--color-accent', '#7a4f2e');
        $this->assertArrayHasKey('--color-accent-on-inverted', $derived);
        $this->assertArrayHasKey('--color-accent-on-inverted-hover', $derived);
        // "Lightened toward white" — each channel brighter than the base accent.
        $base = _pp_hex_to_rgb('#7a4f2e');
        $onInv = _pp_hex_to_rgb($derived['--color-accent-on-inverted']);
        $onInvHover = _pp_hex_to_rgb($derived['--color-accent-on-inverted-hover']);
        $this->assertGreaterThan($base[0], $onInv[0], 'on-inverted is a light tint');
        $this->assertGreaterThan($onInv[0], $onInvHover[0], 'hover is brighter still');

        // Participates in divergence detection: a pinned on-inverted override that
        // no longer matches what the new base derives is reported as masking (#386).
        pp_set_token_override('--color-accent-on-inverted', '#123456');
        $masking = pp_masked_derived_overrides('--color-accent', '#7a4f2e');
        $this->assertContains('--color-accent-on-inverted', array_column($masking, 'token'),
            'A divergent on-inverted override must surface in the #386 masking machinery');
    }

    public function testOnOverlayAccentIsRegisteredDerivedColor(): void
    {
        // #461: the on-overlay accent roles (links/numbers on a bg-image band, which
        // sits on a dark rgba(0,0,0,.55) overlay over an ARBITRARY image) must be
        // REGISTERED derived colors, exactly like the #437 on-inverted pair, so
        // (a) a retheme's new accent auto-produces matching on-overlay tints, and
        // (b) a pinned on-overlay override that diverges surfaces in the #386
        // masked-derived / stale-warning machinery like every other derived token.

        // Registered in the --color-accent family (the divergence-detection surface).
        $family = pp_token_families()['--color-accent'];
        $this->assertArrayHasKey('--color-accent-on-overlay', $family);
        $this->assertArrayHasKey('--color-accent-on-overlay-hover', $family);

        // The shipped default derives to the exact base.css literals (#fafbff / #ffffff):
        // near-white by necessity — the overlay-over-white worst case has a 4.74:1
        // contrast ceiling, so only a near-white value clears WCAG AA (4.5:1) there.
        $defaultDerived = pp_derive_family_tokens('--color-accent', '#3157f4');
        $this->assertEquals('#fafbff', $defaultDerived['--color-accent-on-overlay'],
            'on-overlay default is the near-white value that clears AA over the worst-case overlay composite');
        $this->assertEquals('#ffffff', $defaultDerived['--color-accent-on-overlay-hover'],
            'on-overlay hover is pure white (the 4.74:1 ceiling)');

        // Auto-derived as a near-white accent tint when the base changes (unpinned):
        // every channel brighter than the base accent, hover brighter still.
        $derived = pp_derive_family_tokens('--color-accent', '#7a4f2e');
        $this->assertArrayHasKey('--color-accent-on-overlay', $derived);
        $this->assertArrayHasKey('--color-accent-on-overlay-hover', $derived);
        $base = _pp_hex_to_rgb('#7a4f2e');
        $onOv = _pp_hex_to_rgb($derived['--color-accent-on-overlay']);
        $onOvHover = _pp_hex_to_rgb($derived['--color-accent-on-overlay-hover']);
        $this->assertGreaterThan($base[0], $onOv[0], 'on-overlay is a near-white tint');
        $this->assertGreaterThanOrEqual($onOv[0], $onOvHover[0], 'hover is at least as bright');
        // Near-white: every channel is high regardless of the base accent hue.
        $this->assertGreaterThan(240, min($onOv), 'on-overlay is near-white on every channel');

        // Participates in divergence detection: a pinned on-overlay override that
        // no longer matches what the new base derives is reported as masking (#386).
        pp_set_token_override('--color-accent-on-overlay', '#123456');
        $masking = pp_masked_derived_overrides('--color-accent', '#7a4f2e');
        $this->assertContains('--color-accent-on-overlay', array_column($masking, 'token'),
            'A divergent on-overlay override must surface in the #386 masking machinery');
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
        // But should include the other 7 derived + 1 base = 8 changes (#437 grew
        // the accent family from 4 to 6 derived tokens; #461 grew it to 8)
        $this->assertCount(8, $result['changes']);
    }

    public function testDivergentDerivedOverrideReturnsStaleWarnings(): void
    {
        // #386: pre-set a blue override for a derived token, then update the base
        // to brown. The preserved override no longer matches what brown derives —
        // it masks the base change — so the apply must warn (ok:true + stale_warnings).
        pp_set_token_override('--color-accent-strong', '#2744b7');

        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#7a4f2e']);
        $this->assertTrue($result['ok'], 'The base change still succeeds; the warning is advisory');
        $this->assertArrayHasKey('stale_warnings', $result);
        $this->assertNotEmpty($result['stale_warnings']);

        // The warning names the masking override and preserves its current value.
        $this->assertContains('--color-accent-strong', array_column($result['stale_warnings'], 'token'));
        $strong_warning = null;
        foreach ($result['stale_warnings'] as $w) {
            if ($w['token'] === '--color-accent-strong') {
                $strong_warning = $w;
            }
        }
        $this->assertSame('#2744b7', $strong_warning['current'], 'The preserved (masking) value is reported unchanged');

        // No token value was mutated by the warning path: the override still holds.
        $this->assertSame('#2744b7', pp_get_token_overrides()['--color-accent-strong']);
    }

    public function testNoStaleWarningsWhenCoherent(): void
    {
        // #386: a derived override that EXACTLY equals what the base derives is
        // coherent — it isn't masking anything, so no warning. Preserving the
        // original testNoStaleWarningsWhenCoherent semantics via divergence, not
        // presence: mere presence of a derived override is not staleness.
        $base = '#7a4f2e';
        $coherent = pp_derive_family_tokens('--color-accent', $base)['--color-accent-strong'];
        pp_set_token_override('--color-accent-strong', $coherent);

        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => $base]);
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('stale_warnings', $result,
            'A derived override equal to the derivable value is coherent, not masking');
    }

    public function testCoherentOverrideCaseInsensitiveHexDoesNotWarn(): void
    {
        // #386: an override written in a different hex form (uppercase / shorthand)
        // but equal in RGB to the derivation is still coherent — no warning. Guards
        // against the exact-string-comparison footgun the design flagged.
        $base = '#7a4f2e';
        $coherent = pp_derive_family_tokens('--color-accent', $base)['--color-accent-strong'];
        pp_set_token_override('--color-accent-strong', strtoupper($coherent));

        $result = pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => $base]);
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('stale_warnings', $result,
            'Case-different but RGB-equal override must be treated as coherent');
    }

    public function testColorsEquivalentNormalizesHexForms(): void
    {
        // The shared equivalence helper backing divergence detection.
        $this->assertTrue(_pp_colors_equivalent('#ffffff', '#ffffff'));   // exact
        $this->assertTrue(_pp_colors_equivalent('#FFF', '#ffffff'));      // shorthand + case
        $this->assertTrue(_pp_colors_equivalent('#7A4F2E', '#7a4f2e'));   // case only
        $this->assertFalse(_pp_colors_equivalent('#553720', '#563820'));  // off by a hair → diverges
        // A non-hex pin can never equal the always-hex derivation → diverges.
        $this->assertFalse(_pp_colors_equivalent('rgba(0,0,0,0.5)', '#000000'));
        $this->assertFalse(_pp_colors_equivalent('var(--color-accent)', '#7a4f2e'));
    }

    public function testMaskedDerivedOverridesEmptyForNonFamilyBase(): void
    {
        // --color-bg is not a family base → no derivation → nothing can be masked.
        pp_set_token_override('--color-accent-strong', '#2744b7');
        $this->assertSame([], pp_masked_derived_overrides('--color-bg', '#123456'));
    }

    public function testMaskedDerivedOverridesEmptyForNonHexBase(): void
    {
        // A base value that isn't resolvable hex (var()/rgba()) yields no derivation,
        // so divergence can't be computed and nothing is reported — no false positive.
        pp_set_token_override('--color-accent-strong', '#2744b7');
        $this->assertSame([], pp_masked_derived_overrides('--color-accent', 'var(--color-accent)'));
        $this->assertSame([], pp_masked_derived_overrides('--color-accent', 'rgba(0,0,0,0.5)'));
    }

    public function testMaskedDerivedOverridesSkipsAbsentDerivedTokens(): void
    {
        // With no derived overrides at all, a base change auto-derives the whole
        // family — nothing is masked.
        $this->assertSame([], pp_masked_derived_overrides('--color-accent', '#7a4f2e'));
    }

    public function testDetectMaskedDerivedSmellsFiresOnDivergence(): void
    {
        // #386 INSPECT smell: base accent is its product default (blue-ish) while a
        // stale orange accent-strong override lingers → masking, surfaced at INSPECT.
        pp_set_token_override('--color-accent-strong', '#e07b39');

        $smells = pp_detect_masked_derived_smells();
        $this->assertNotEmpty($smells);
        $smell = $smells[0];
        $this->assertSame('masked_derived_override', $smell['type']);
        $this->assertSame('--color-accent', $smell['base_token']);
        $this->assertSame('--color-accent-strong', $smell['token']);
        $this->assertSame('#e07b39', $smell['current']);
    }

    public function testDetectMaskedDerivedSmellsQuietWhenCoherent(): void
    {
        // A fully coherent site (only the base overridden, family auto-derived to
        // match) produces no INSPECT token smell.
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#7a4f2e']);
        $this->assertSame([], pp_detect_masked_derived_smells());
    }

    public function testDetectMaskedDerivedSmellsQuietOnPristineSite(): void
    {
        // No overrides at all → no smell.
        $this->assertSame([], pp_detect_masked_derived_smells());
    }

    public function testMaskedDerivedOverridesToleratesCorruptOverrideValue(): void
    {
        // A corrupt pp_token_overrides option (non-string value) must not fatal the
        // advisory detector — the INSPECT surface stays chaos-tolerant. Bypass the
        // string-typed writer to simulate corruption directly in the option store.
        $GLOBALS['_pp_test_store']['options']['pp_token_overrides'] = [
            '--color-accent-strong' => ['unexpected' => 'array'],
        ];
        pp_invalidate_design_tokens_cache();

        // No TypeError; the malformed override is skipped, not reported.
        $this->assertSame([], pp_masked_derived_overrides('--color-accent', '#7a4f2e'));
        $this->assertSame([], pp_detect_masked_derived_smells());

        unset($GLOBALS['_pp_test_store']['options']['pp_token_overrides']);
        pp_invalidate_design_tokens_cache();
    }

    public function testDetectMaskedDerivedSmellsToleratesCorruptBaseValue(): void
    {
        // A corrupt BASE token value (non-string) must not fatal the INSPECT walk —
        // the is_string guard in pp_detect_masked_derived_smells() skips the family.
        $GLOBALS['_pp_test_store']['options']['pp_token_overrides'] = [
            '--color-accent' => ['unexpected' => 'array'],
        ];
        pp_invalidate_design_tokens_cache();

        $this->assertSame([], pp_detect_masked_derived_smells());

        unset($GLOBALS['_pp_test_store']['options']['pp_token_overrides']);
        pp_invalidate_design_tokens_cache();
    }

    public function testNonHexDerivedPinIsSurfacedAsMasking(): void
    {
        // A derived override that is a valid string but not hex (an intentional
        // rgba()/var() pin) can never equal the always-hex derivation, so it
        // diverges and IS reported as masking — not silently swallowed.
        pp_set_token_override('--color-accent-strong', 'rgba(0,0,0,0.5)');

        $masking = pp_masked_derived_overrides('--color-accent', '#7a4f2e');
        $this->assertCount(1, $masking);
        $this->assertSame('--color-accent-strong', $masking[0]['token']);
        $this->assertSame('rgba(0,0,0,0.5)', $masking[0]['current']);
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
        // Setting --color-accent also auto-derives 8 family tokens (#437 grew the
        // accent family from 4 to 6; #461 grew it to 8): 9 total + 1 for --color-bg = 10
        pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309']);
        pp_execute_apply('update_design_token', ['token' => '--color-bg', 'value' => '#000000']);

        $result = pp_execute_apply('reset_all_design_tokens', []);
        $this->assertTrue($result['ok']);
        $this->assertCount(10, $result['changes']);
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
            $this->assertContains($def['target']['type'], ['file', 'option', 'media'], "Apply '$name' target type should be 'file', 'option', or 'media'");
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

    // ── _pp_validate_gradient (#99) ──────────────────────────────────────────

    public function testValidateGradientAcceptsLinearWithDirection(): void
    {
        $this->assertTrue(_pp_validate_gradient('linear-gradient(135deg, #1a1a2e, #16121f)'));
        $this->assertTrue(_pp_validate_gradient('linear-gradient(to right, #fff, #000)'));
        $this->assertTrue(_pp_validate_gradient('linear-gradient(to bottom left, #fff, #000)'));
    }

    public function testValidateGradientAcceptsLinearWithoutDirection(): void
    {
        // Direction is optional, matching real CSS — the first segment is
        // treated as a color-stop when it doesn't match the direction grammar.
        $this->assertTrue(_pp_validate_gradient('linear-gradient(#f00, #00f)'));
    }

    public function testValidateGradientAcceptsStopPositions(): void
    {
        $this->assertTrue(_pp_validate_gradient('linear-gradient(180deg, #fff 0%, #000 100%)'));
        $this->assertTrue(_pp_validate_gradient('linear-gradient(180deg, #fff 0px, #000 4rem)'));
    }

    public function testValidateGradientAcceptsFunctionColorStopsWithNestedCommas(): void
    {
        // The nested commas inside rgba()/hsla() must not be mistaken for
        // top-level argument separators.
        $this->assertTrue(_pp_validate_gradient('linear-gradient(to bottom, rgba(0,0,0,0.5) 0%, rgba(0,0,0,0.9) 100%)'));
        $this->assertTrue(_pp_validate_gradient('linear-gradient(180deg, hsla(0, 0%, 0%, 0.5), hsla(0, 0%, 100%, 0.5))'));
    }

    public function testValidateGradientAcceptsTransparentKeyword(): void
    {
        $this->assertTrue(_pp_validate_gradient('linear-gradient(to bottom, transparent, rgba(0,0,0,0.7))'));
        $this->assertTrue(_pp_validate_gradient('linear-gradient(transparent, #000)'));
    }

    public function testValidateGradientAcceptsRadialDefault(): void
    {
        $this->assertTrue(_pp_validate_gradient('radial-gradient(#fff, #000)'));
    }

    public function testValidateGradientAcceptsRadialAllowlistedShapePosition(): void
    {
        $this->assertTrue(_pp_validate_gradient('radial-gradient(circle, #fff, #000)'));
        $this->assertTrue(_pp_validate_gradient('radial-gradient(ellipse, #fff, #000)'));
        $this->assertTrue(_pp_validate_gradient('radial-gradient(circle at center, #fff, #000)'));
        $this->assertTrue(_pp_validate_gradient('radial-gradient(ellipse at center, #fff, #000)'));
    }

    public function testValidateGradientAcceptsRadialAtPosition(): void
    {
        // #301: `at <position>` (keyword/percentage tokens) is valid CSS and
        // now accepted — off-center spotlight gradients are the main reason to
        // reach for radial over linear.
        $this->assertTrue(_pp_validate_gradient('radial-gradient(circle at top left, #fff, #000)'));
        $this->assertTrue(_pp_validate_gradient('radial-gradient(ellipse at bottom right, #fff, #000)'));
        $this->assertTrue(_pp_validate_gradient('radial-gradient(at top left, #fff, #000)'));
        $this->assertTrue(_pp_validate_gradient('radial-gradient(at 20% 30%, #2a2145 0%, #1a1a2e 55%)'));
        $this->assertTrue(_pp_validate_gradient('radial-gradient(circle at 20% 80%, #fff, #000)'));
        $this->assertTrue(_pp_validate_gradient('radial-gradient(ellipse at center, #fff, #000)'));
    }

    public function testValidateGradientRejectsRadialNonAllowlistedShapePosition(): void
    {
        // Radial SIZE keywords stay out of this bounded grammar — they fall
        // through to color-stop parsing and correctly fail as invalid colors.
        $this->assertFalse(_pp_validate_gradient('radial-gradient(closest-side, #fff, #000)'));
        $this->assertFalse(_pp_validate_gradient('radial-gradient(farthest-corner, #fff, #000)'));
        // A dangling shape/at with no position tokens is not a valid direction
        // segment (and not a color) — reject.
        $this->assertFalse(_pp_validate_gradient('radial-gradient(circle at, #fff, #000)'));
        $this->assertFalse(_pp_validate_gradient('radial-gradient(at, #fff, #000)'));
        // Length positions are out of #301's keyword/percentage scope.
        $this->assertFalse(_pp_validate_gradient('radial-gradient(at 10px 20px, #fff, #000)'));
    }

    public function testValidateGradientRejectsInjectionInRadialPosition(): void
    {
        // The `at <position>` clause is a security boundary: stored gradient
        // values reach inline style attributes. Nothing but keyword/percentage
        // tokens may pass — every injection shape fails closed.
        $this->assertFalse(_pp_validate_gradient('radial-gradient(at url(evil), #fff, #000)'));
        $this->assertFalse(_pp_validate_gradient('radial-gradient(circle at expression(1), #fff, #000)'));
        $this->assertFalse(_pp_validate_gradient('radial-gradient(at javascript:alert(1), #fff, #000)'));
        $this->assertFalse(_pp_validate_gradient('radial-gradient(at top left; color:red, #fff, #000)'));
        $this->assertFalse(_pp_validate_gradient('radial-gradient(at top /* x */ left, #fff, #000)'));
        $this->assertFalse(_pp_validate_gradient('radial-gradient(at var(--x), #fff, #000)'));
    }

    public function testValidateGradientRejectsConicAndRepeating(): void
    {
        $this->assertFalse(_pp_validate_gradient('conic-gradient(#fff, #000)'));
        $this->assertFalse(_pp_validate_gradient('repeating-linear-gradient(#fff, #000)'));
        $this->assertFalse(_pp_validate_gradient('repeating-radial-gradient(#fff, #000)'));
    }

    public function testValidateGradientRejectsVarUrlEnv(): void
    {
        $this->assertFalse(_pp_validate_gradient('linear-gradient(135deg, var(--evil), #000)'));
        $this->assertFalse(_pp_validate_gradient('linear-gradient(135deg, url(evil), #000)'));
        $this->assertFalse(_pp_validate_gradient('linear-gradient(135deg, env(evil), #000)'));
    }

    public function testValidateGradientRejectsSingleStop(): void
    {
        $this->assertFalse(_pp_validate_gradient('linear-gradient(135deg, #fff)'));
        $this->assertFalse(_pp_validate_gradient('linear-gradient(#fff)'));
    }

    public function testValidateGradientRejectsInvalidColorInStop(): void
    {
        $this->assertFalse(_pp_validate_gradient('linear-gradient(135deg, notacolor, #000)'));
        $this->assertFalse(_pp_validate_gradient('linear-gradient(135deg, red, #000)')); // named colors rejected, same as _pp_validate_color()
    }

    public function testValidateGradientStopAcceptsCurrentColor(): void
    {
        // #230: the color validator now covers currentColor; stops inherit it.
        $this->assertTrue(_pp_validate_gradient('linear-gradient(180deg, currentColor, #ffffff)'));
        $this->assertTrue(_pp_validate_gradient('linear-gradient(to bottom, transparent, currentColor 60%)'));
    }

    public function testValidateGradientStillRejectsVarStopAfter230(): void
    {
        // #230 accepts a BARE var(--token) as a color value, but inside a
        // gradient var() stays rejected by the upstream guard — a registered
        // token reference is no exception.
        $this->assertFalse(_pp_validate_gradient('linear-gradient(var(--color-accent), #fff)'));
        $this->assertFalse(_pp_validate_gradient('linear-gradient(135deg, var(--color-accent) 40%, #000)'));
    }

    public function testValidateGradientRejectsEmptyOrMalformed(): void
    {
        $this->assertFalse(_pp_validate_gradient(''));
        $this->assertFalse(_pp_validate_gradient('linear-gradient()'));
        $this->assertFalse(_pp_validate_gradient('linear-gradient(135deg)'));
        $this->assertFalse(_pp_validate_gradient('not-a-gradient(#fff, #000)'));
    }

    public function testValidateGradientRejectsExcessiveLength(): void
    {
        // 10 stops (under the 20-stop cap) but padded long enough to exceed
        // the 500-char value cap on its own — isolates the length bound from
        // the stop-count bound tested separately below.
        $stop = 'rgba(255,255,255,0.999999999999) 99.999999999999%';
        $stops = array_fill(0, 10, $stop);
        $value = 'linear-gradient(180deg, ' . implode(', ', $stops) . ')';
        $this->assertGreaterThan(500, strlen($value));
        $this->assertLessThanOrEqual(20, count($stops));
        $this->assertFalse(_pp_validate_gradient($value));
    }

    public function testValidateGradientRejectsExcessiveStopCount(): void
    {
        // 21 stops exceeds the bounded cap even if the total length were fine.
        $stops = array_fill(0, 21, '#fff');
        $this->assertFalse(_pp_validate_gradient('linear-gradient(180deg, ' . implode(', ', $stops) . ')'));
    }

    public function testValidateTokenValuePassesForGradientTypeUnion(): void
    {
        // A gradient-typed slot accepts a plain color too (not gradients-only).
        $this->assertTrue(_pp_validate_token_value('#1a1a2e', 'gradient'));
        $this->assertTrue(_pp_validate_token_value('linear-gradient(135deg, #1a1a2e, #16121f)', 'gradient'));
    }

    public function testValidateGradientTypeUnionInheritsColorForms230(): void
    {
        // #230: the color half of the gradient union accepts the new forms —
        // a hero bg can be transparent or follow the brand accent — while the
        // gradient grammar itself still rejects var() (pinned above).
        $this->assertTrue(_pp_validate_token_value('transparent', 'gradient'));
        $this->assertTrue(_pp_validate_token_value('var(--color-accent)', 'gradient'));

        foreach (['var(--nonexistent-token)', 'var(--space-md)'] as $value) {
            $result = _pp_validate_token_value($value, 'gradient');
            $this->assertInstanceOf(WP_Error::class, $result, $value);
            $this->assertSame('invalid_gradient', $result->get_error_code(), $value);
        }
    }

    public function testValidateTokenValueFailsForInvalidGradient(): void
    {
        $result = _pp_validate_token_value('garbage', 'gradient');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_gradient', $result->get_error_code());
    }

    public function testValidateGradientInjectionBlockedUpstream(): void
    {
        // The {};<> guard runs before the type switch in _pp_validate_token_value.
        $result = _pp_validate_token_value('linear-gradient(135deg, #fff, #000); evil', 'gradient');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('injection', $result->get_error_code());
    }

    // ── _pp_validate_position() tests (#108 — image focal point) ────────────

    public function testValidatePositionAcceptsSingleKeyword(): void
    {
        foreach (['center', 'top', 'bottom', 'left', 'right'] as $keyword) {
            $this->assertTrue(_pp_validate_position($keyword), "{$keyword} should be accepted.");
        }
    }

    public function testValidatePositionAcceptsTwoKeywords(): void
    {
        $this->assertTrue(_pp_validate_position('top left'));
        $this->assertTrue(_pp_validate_position('bottom right'));
    }

    public function testValidatePositionAcceptsLengthsAndPercentages(): void
    {
        $this->assertTrue(_pp_validate_position('20%'));
        $this->assertTrue(_pp_validate_position('20% 80%'));
        $this->assertTrue(_pp_validate_position('10px 50%'));
        $this->assertTrue(_pp_validate_position('-5rem'));
        $this->assertTrue(_pp_validate_position('0 0'));
    }

    public function testValidatePositionAcceptsMixedKeywordAndLength(): void
    {
        $this->assertTrue(_pp_validate_position('center 30%'));
    }

    public function testValidatePositionRejectsEmpty(): void
    {
        $this->assertFalse(_pp_validate_position(''));
    }

    public function testValidatePositionRejectsTooManyTokens(): void
    {
        $this->assertFalse(_pp_validate_position('center center center'));
    }

    public function testValidatePositionRejectsUnknownKeyword(): void
    {
        $this->assertFalse(_pp_validate_position('middle'));
    }

    public function testValidatePositionRejectsFunctionsAndVar(): void
    {
        $this->assertFalse(_pp_validate_position('var(--attacker)'));
        $this->assertFalse(_pp_validate_position('calc(50% + 1px)'));
        $this->assertFalse(_pp_validate_position('url(evil)'));
    }

    public function testValidateTokenValuePassesForPositionType(): void
    {
        $this->assertTrue(_pp_validate_token_value('top left', 'position'));
    }

    public function testValidateTokenValueFailsForInvalidPosition(): void
    {
        $result = _pp_validate_token_value('var(--attacker)', 'position');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_position', $result->get_error_code());
    }

    // ── _pp_validate_ratio() tests (#108 — image aspect ratio) ──────────────

    public function testValidateRatioAcceptsSingleNumber(): void
    {
        $this->assertTrue(_pp_validate_ratio('1'));
        $this->assertTrue(_pp_validate_ratio('1.6'));
    }

    public function testValidateRatioAcceptsWidthSlashHeight(): void
    {
        $this->assertTrue(_pp_validate_ratio('16/9'));
        $this->assertTrue(_pp_validate_ratio('16 / 9'));
        $this->assertTrue(_pp_validate_ratio('4/3'));
    }

    public function testValidateRatioAcceptsAutoKeyword(): void
    {
        // "auto" is the slot's own default (natural image proportions) --
        // explicitly settable, same pattern as _pp_validate_shadow()'s "none".
        $this->assertTrue(_pp_validate_ratio('auto'));
        $this->assertTrue(_pp_validate_ratio('AUTO'));
    }

    public function testValidateRatioRejectsZeroOrNegative(): void
    {
        $this->assertFalse(_pp_validate_ratio('0'));
        $this->assertFalse(_pp_validate_ratio('16/0'));
        $this->assertFalse(_pp_validate_ratio('0/9'));
        $this->assertFalse(_pp_validate_ratio('-1'));
    }

    public function testValidateRatioRejectsNonNumeric(): void
    {
        $this->assertFalse(_pp_validate_ratio('16/9/4'));
        $this->assertFalse(_pp_validate_ratio('var(--attacker)'));
    }

    public function testValidateTokenValuePassesForRatioType(): void
    {
        $this->assertTrue(_pp_validate_token_value('16/9', 'ratio'));
    }

    public function testValidateTokenValueFailsForInvalidRatio(): void
    {
        $result = _pp_validate_token_value('16/0', 'ratio');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_ratio', $result->get_error_code());
    }

    // ── _pp_validate_align() tests (#357 — card content text-align) ──────────

    public function testValidateAlignAcceptsEveryTextAlignKeyword(): void
    {
        foreach (['left', 'right', 'center', 'start', 'end', 'justify'] as $keyword) {
            $this->assertTrue(_pp_validate_align($keyword), "{$keyword} should be accepted.");
        }
    }

    public function testValidateAlignIsCaseInsensitiveAndTrims(): void
    {
        // Mirrors _pp_validate_position(), which lowercases before comparing.
        $this->assertTrue(_pp_validate_align('Center'));
        $this->assertTrue(_pp_validate_align('JUSTIFY'));
        $this->assertTrue(_pp_validate_align('  center  '));
    }

    public function testValidateAlignRejectsPositionOnlyKeywords(): void
    {
        // top/bottom are `position` keywords, not valid text-align values --
        // the align type is a tighter closed set than position.
        $this->assertFalse(_pp_validate_align('top'));
        $this->assertFalse(_pp_validate_align('bottom'));
    }

    public function testValidateAlignRejectsLengthsMultiTokenAndCssWide(): void
    {
        $this->assertFalse(_pp_validate_align('20%'));
        $this->assertFalse(_pp_validate_align('left top'));
        $this->assertFalse(_pp_validate_align('unset'));
        $this->assertFalse(_pp_validate_align('initial'));
        $this->assertFalse(_pp_validate_align('inherit'));
        $this->assertFalse(_pp_validate_align(''));
        $this->assertFalse(_pp_validate_align('middle'));
    }

    public function testValidateTokenValuePassesForAlignType(): void
    {
        $this->assertTrue(_pp_validate_token_value('center', 'align'));
        $this->assertTrue(_pp_validate_token_value('left', 'align'));
    }

    public function testValidateTokenValueFailsForInvalidAlign(): void
    {
        $result = _pp_validate_token_value('top', 'align');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_align', $result->get_error_code());
    }

    // ── text-transform slot type (#370) ──────────────────────────────────

    public function testValidateTextTransformAcceptsClosedKeywordSet(): void
    {
        $this->assertTrue(_pp_validate_text_transform('none'));
        $this->assertTrue(_pp_validate_text_transform('uppercase'));
        $this->assertTrue(_pp_validate_text_transform('lowercase'));
        $this->assertTrue(_pp_validate_text_transform('capitalize'));
    }

    public function testValidateTextTransformIsCaseInsensitiveAndTrims(): void
    {
        // Mirrors _pp_validate_align(), which lowercases before comparing.
        $this->assertTrue(_pp_validate_text_transform('None'));
        $this->assertTrue(_pp_validate_text_transform('UPPERCASE'));
        $this->assertTrue(_pp_validate_text_transform('  capitalize  '));
    }

    public function testValidateTextTransformRejectsCjkExoticAndCssWide(): void
    {
        // full-width / full-size-kana are valid CSS text-transform values but
        // CJK form-conversion, not case control -- excluded from this slot the
        // same way `align` omits match-parent/justify-all.
        $this->assertFalse(_pp_validate_text_transform('full-width'));
        $this->assertFalse(_pp_validate_text_transform('full-size-kana'));
        $this->assertFalse(_pp_validate_text_transform('math-auto'));
        // CSS-wide keywords are rejected: the type is a closed vocabulary.
        $this->assertFalse(_pp_validate_text_transform('unset'));
        $this->assertFalse(_pp_validate_text_transform('initial'));
        $this->assertFalse(_pp_validate_text_transform('inherit'));
        $this->assertFalse(_pp_validate_text_transform('revert'));
    }

    public function testValidateTextTransformRejectsAlignKeywordsJunkAndEmpty(): void
    {
        // Disjoint from the `align` keyword set -- a different closed vocabulary.
        $this->assertFalse(_pp_validate_text_transform('center'));
        $this->assertFalse(_pp_validate_text_transform('left'));
        $this->assertFalse(_pp_validate_text_transform('caps'));
        $this->assertFalse(_pp_validate_text_transform('uppercase lowercase'));
        $this->assertFalse(_pp_validate_text_transform(''));
    }

    public function testValidateTokenValuePassesForTextTransformType(): void
    {
        $this->assertTrue(_pp_validate_token_value('none', 'text-transform'));
        $this->assertTrue(_pp_validate_token_value('uppercase', 'text-transform'));
    }

    public function testValidateTokenValueFailsForInvalidTextTransform(): void
    {
        $result = _pp_validate_token_value('center', 'text-transform');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_text_transform', $result->get_error_code());
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

    public function testNewTokensLetterSpacingHeadingExists(): void
    {
        // #467: heading tracking is tokenized so a brand can set its own letter-spacing.
        // Registered in base.css's first :root block as a `length` token so it joins
        // pp_design_tokens() and the update_design_token write path like its siblings.
        $tokens = pp_design_tokens();
        $this->assertArrayHasKey('--letter-spacing-heading', $tokens);
        $this->assertSame('length', $tokens['--letter-spacing-heading']['type']);
        $this->assertSame('-0.03em', $tokens['--letter-spacing-heading']['value']);
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

    // ── --btn-radius (issue 369) ──────────────────────────────────────────
    // components.css reads `border-radius: var(--btn-radius, var(--radius))`,
    // but --btn-radius was never declared in :root, so update_design_token
    // rejected it as unregistered. Worse, the WINNING cascade rule for every
    // composed button — the premium-CTA block `main .btn { border-radius: 4px }`
    // — hardcoded 4px and ignored the token entirely, so even a registered token
    // was inert. The fix registers --btn-radius (defaulting to the composed
    // button's actual current radius, 4px, so unset output is byte-identical)
    // and routes that winning rule through var(--btn-radius, 4px). Card/panel
    // radius still reads the GLOBAL --radius, so button radius is now settable
    // on its own without pilling every card.

    public function testBtnRadiusIsRegisteredAsLengthToken(): void
    {
        $tokens = pp_design_tokens();
        $this->assertArrayHasKey('--btn-radius', $tokens);
        $this->assertSame('length', $tokens['--btn-radius']['type']);
    }

    public function testBtnRadiusDefaultsToComposedButtonRadiusForByteIdenticalUnset(): void
    {
        // Registering the token in :root DEFINES the property globally, so the
        // `var(--btn-radius, 4px)` fallback in the winning rule never fires — the
        // declared default wins. It must therefore equal the composed button's
        // actual current radius (4px), NOT var(--radius) (6px): a 6px default
        // would silently restyle every existing button 4px->6px. 4px keeps unset
        // rendering byte-identical.
        $tokens = pp_design_tokens();
        $this->assertSame('4px', $tokens['--btn-radius']['value']);
    }

    public function testUpdateDesignTokenAcceptsBtnRadiusLength(): void
    {
        // The whole point of the issue: --btn-radius must validate through the
        // SAME shared length engine (_pp_validate_token_value) so an operator
        // can pill a CTA (100px) without touching --radius.
        $result = pp_validate_apply('update_design_token', ['token' => '--btn-radius', 'value' => '100px']);
        $this->assertTrue($result);
    }

    public function testUpdateDesignTokenRejectsInvalidBtnRadius(): void
    {
        // Same length grammar as every other length token — a non-length value
        // is rejected by the shared engine, not a surface-specific validator.
        $result = pp_validate_apply('update_design_token', ['token' => '--btn-radius', 'value' => 'rounded']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_length', $result->get_error_code());
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

    // ── Font family / apply_to tests (issue 135) ────────────────────────────

    public function testEnqueueFontUrlOnlyReturnsDerivedFamilySuggestion(): void
    {
        pp_set_font_urls([]);
        $result = pp_execute_apply('enqueue_font', ['url' => 'https://fonts.googleapis.com/css2?family=Inter']);
        $this->assertTrue($result['ok']);
        $this->assertSame('Inter', $result['family']);
        $this->assertSame('derived', $result['family_source']);
        // A suggestion only — no token should have been written.
        $tokens = pp_design_tokens();
        $this->assertSame('system-ui, sans-serif', $tokens['--font-heading']['value']);
    }

    public function testEnqueueFontDerivesFamilyWithWeightAxisSuffix(): void
    {
        pp_set_font_urls([]);
        $result = pp_execute_apply('enqueue_font', ['url' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700']);
        $this->assertSame('Roboto', $result['family']);
    }

    public function testEnqueueFontDerivesFamilyWithPlusEncodedSpaces(): void
    {
        pp_set_font_urls([]);
        $result = pp_execute_apply('enqueue_font', ['url' => 'https://fonts.googleapis.com/css2?family=Open+Sans']);
        $this->assertSame('Open Sans', $result['family']);
    }

    public function testEnqueueFontNoFamilyWhenUrlHasNoFamilyParam(): void
    {
        pp_set_font_urls([]);
        $result = pp_execute_apply('enqueue_font', ['url' => 'https://example.com/custom-font.css']);
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('family', $result);
    }

    public function testEnqueueFontRejectsApplyToWithoutFamilyWhenNoneDerivable(): void
    {
        pp_set_font_urls([]);
        $result = pp_validate_apply('enqueue_font', [
            'url'      => 'https://example.com/custom-font.css',
            'apply_to' => 'heading',
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_family', $result->get_error_code());
    }

    public function testEnqueueFontRejectsInvalidApplyTo(): void
    {
        pp_set_font_urls([]);
        $result = pp_validate_apply('enqueue_font', [
            'url'      => 'https://fonts.googleapis.com/css2?family=Inter',
            'apply_to' => 'sidebar',
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_apply_to', $result->get_error_code());
    }

    public function testEnqueueFontRejectsEmptyExplicitFamily(): void
    {
        pp_set_font_urls([]);
        $result = pp_validate_apply('enqueue_font', [
            'url'    => 'https://fonts.googleapis.com/css2?family=Inter',
            'family' => '   ',
        ]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_font_family', $result->get_error_code());
    }

    public function testEnqueueFontWithFamilyAndApplyToHeadingSetsToken(): void
    {
        pp_set_font_urls([]);
        $result = pp_execute_apply('enqueue_font', [
            'url'      => 'https://fonts.googleapis.com/css2?family=Poppins',
            'family'   => 'Poppins',
            'apply_to' => 'heading',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('Poppins', $result['family']);
        $this->assertSame('explicit', $result['family_source']);

        $tokens = pp_design_tokens();
        $this->assertSame('Poppins, system-ui, sans-serif', $tokens['--font-heading']['value']);
        $this->assertSame('system-ui, sans-serif', $tokens['--font-body']['value']);
    }

    public function testEnqueueFontWithApplyToBodySetsBothTokens(): void
    {
        pp_set_font_urls([]);
        pp_execute_apply('enqueue_font', [
            'url'      => 'https://fonts.googleapis.com/css2?family=Poppins',
            'family'   => 'Poppins',
            'apply_to' => 'both',
        ]);

        $tokens = pp_design_tokens();
        $this->assertSame('Poppins, system-ui, sans-serif', $tokens['--font-heading']['value']);
        $this->assertSame('Poppins, system-ui, sans-serif', $tokens['--font-body']['value']);
    }

    public function testEnqueueFontExplicitFamilyOverridesDerivedOne(): void
    {
        pp_set_font_urls([]);
        // URL derives to "Inter", but an explicit family always wins.
        $result = pp_execute_apply('enqueue_font', [
            'url'      => 'https://fonts.googleapis.com/css2?family=Inter',
            'family'   => 'Custom Name',
            'apply_to' => 'heading',
        ]);

        $this->assertSame('Custom Name', $result['family']);
        $tokens = pp_design_tokens();
        $this->assertSame('Custom Name, system-ui, sans-serif', $tokens['--font-heading']['value']);
    }

    public function testEnqueueFontPreviewShowsTokenChangeAlongsideUrlAdd(): void
    {
        pp_set_font_urls([]);
        $preview = pp_preview_apply('enqueue_font', [
            'url'      => 'https://fonts.googleapis.com/css2?family=Poppins',
            'family'   => 'Poppins',
            'apply_to' => 'heading',
        ]);

        $this->assertIsArray($preview);
        $this->assertCount(2, $preview['changes']);
        $this->assertSame('add', $preview['changes'][0]['action']);
        $this->assertSame('--font-heading', $preview['changes'][1]['token']);
        $this->assertSame('Poppins, system-ui, sans-serif', $preview['changes'][1]['to']);

        // Preview must never write.
        $tokens = pp_design_tokens();
        $this->assertSame('system-ui, sans-serif', $tokens['--font-heading']['value']);
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

    // ── import_media apply tests (#105) ──────────────────────────────────────
    // SSRF safety itself is WordPress core's job (wp_safe_remote_get /
    // wp_http_validate_url) -- these tests prove OUR code correctly respects
    // and propagates whatever core decides, rather than re-testing core.

    private function resetImportMediaTestStore(): void
    {
        unset(
            $GLOBALS['_pp_test_store']['download_url_result'],
            $GLOBALS['_pp_test_store']['download_url_size'],
            $GLOBALS['_pp_test_store']['filetype_result'],
            $GLOBALS['_pp_test_store']['media_sideload_result'],
            $GLOBALS['_pp_test_store']['safe_remote_head_result']
        );
        $GLOBALS['_pp_test_store']['download_url_calls']       = [];
        $GLOBALS['_pp_test_store']['media_sideload_calls']     = [];
        $GLOBALS['_pp_test_store']['safe_remote_head_calls']   = [];
        // The shared in-memory store is not reset between tests, so isolate the
        // media/attachment state these tests read and write (source-URL dedupe,
        // #298, does a get_posts() lookup — a cached attachment leaking in from
        // a prior test would otherwise short-circuit a fresh-import assertion).
        $GLOBALS['_pp_test_store']['posts']                  = [];
        $GLOBALS['_pp_test_store']['post_meta']              = [];
        $GLOBALS['_pp_test_store']['attachment_urls']        = [];
        $GLOBALS['_pp_test_store']['attachment_url_missing'] = [];
        $GLOBALS['_pp_test_store']['next_id']                = 100;
    }

    public function testImportMediaValidUrl(): void
    {
        $this->resetImportMediaTestStore();
        $result = pp_validate_apply('import_media', ['url' => 'https://example.com/logo.png']);
        $this->assertTrue($result);
    }

    public function testImportMediaRejectsNonHttps(): void
    {
        $this->resetImportMediaTestStore();
        $result = pp_validate_apply('import_media', ['url' => 'http://example.com/logo.png']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_url', $result->get_error_code());
    }

    public function testImportMediaRejectsInvalidUrl(): void
    {
        $this->resetImportMediaTestStore();
        $result = pp_validate_apply('import_media', ['url' => 'not-a-url']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('invalid_url', $result->get_error_code());
    }

    public function testImportMediaRejectsUnsupportedExtension(): void
    {
        $this->resetImportMediaTestStore();
        $result = pp_validate_apply('import_media', ['url' => 'https://example.com/payload.exe']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('unsupported_type', $result->get_error_code());
    }

    public function testImportMediaRejectsNoExtension(): void
    {
        $this->resetImportMediaTestStore();
        $result = pp_validate_apply('import_media', ['url' => 'https://example.com/image']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('unsupported_type', $result->get_error_code());
    }

    public function testImportMediaExecuteSuccess(): void
    {
        $this->resetImportMediaTestStore();
        $result = pp_execute_apply('import_media', ['url' => 'https://example.com/logo.jpg', 'alt' => 'Logo']);
        $this->assertTrue($result['ok']);
        $this->assertSame('media', $result['domain']);
        $change = $result['changes'][0];
        $this->assertSame('import', $change['action']);
        $this->assertArrayHasKey('attachment_id', $change);
        $this->assertArrayHasKey('url', $change);
        $this->assertSame('https://example.com/logo.jpg', $change['source_url']);
        // alt text was persisted against the new attachment.
        $meta = $GLOBALS['_pp_test_store']['post_meta'][$change['attachment_id']]['_wp_attachment_image_alt'] ?? null;
        $this->assertSame('Logo', $meta);
        // The source URL was recorded so a later import can dedupe to it (#298).
        $sourceMeta = $GLOBALS['_pp_test_store']['post_meta'][$change['attachment_id']]['_pp_import_source_url'] ?? null;
        $this->assertSame('https://example.com/logo.jpg', $sourceMeta);
    }

    public function testImportMediaReusesExistingSourceUrl(): void
    {
        // #298: a second import of the SAME source URL must reuse the existing
        // attachment instead of downloading and creating a duplicate.
        $this->resetImportMediaTestStore();
        $url = 'https://example.com/dedupe-me.jpg';

        $first = pp_execute_apply('import_media', ['url' => $url, 'alt' => 'Logo']);
        $this->assertTrue($first['ok']);
        $this->assertSame('import', $first['changes'][0]['action']);
        $firstId = $first['changes'][0]['attachment_id'];

        // Clear the call logs so we can prove the second call did NO work.
        $GLOBALS['_pp_test_store']['download_url_calls']   = [];
        $GLOBALS['_pp_test_store']['media_sideload_calls'] = [];

        $second = pp_execute_apply('import_media', ['url' => $url]);
        $this->assertTrue($second['ok']);
        $change = $second['changes'][0];
        $this->assertSame('reused', $change['action']);
        $this->assertSame($firstId, $change['attachment_id']);
        $this->assertSame($url, $change['source_url']);
        $this->assertNotEmpty($change['url']);
        // No second download, no second sideload — genuinely no duplicate.
        $this->assertEmpty($GLOBALS['_pp_test_store']['download_url_calls']);
        $this->assertEmpty($GLOBALS['_pp_test_store']['media_sideload_calls']);
    }

    public function testImportMediaDoesNotDedupeDifferentSourceUrl(): void
    {
        // A different source URL is a genuinely new asset — must import fresh,
        // not reuse a prior import.
        $this->resetImportMediaTestStore();
        $first = pp_execute_apply('import_media', ['url' => 'https://example.com/one.jpg']);
        $firstId = $first['changes'][0]['attachment_id'];

        $GLOBALS['_pp_test_store']['media_sideload_calls'] = [];
        $second = pp_execute_apply('import_media', ['url' => 'https://example.com/two.jpg']);
        $this->assertSame('import', $second['changes'][0]['action']);
        $this->assertNotSame($firstId, $second['changes'][0]['attachment_id']);
        $this->assertNotEmpty($GLOBALS['_pp_test_store']['media_sideload_calls']);
    }

    public function testImportMediaReimportsWhenCachedFileIsMissing(): void
    {
        // #298 fall-through: if the previously-imported attachment's file is
        // gone (wp_get_attachment_url() returns false), reuse would hand back a
        // broken URL — so re-import a fresh copy instead.
        $this->resetImportMediaTestStore();
        $url = 'https://example.com/broken.jpg';

        $first = pp_execute_apply('import_media', ['url' => $url]);
        $firstId = $first['changes'][0]['attachment_id'];
        // Simulate the cached file having been deleted from disk.
        $GLOBALS['_pp_test_store']['attachment_url_missing'][$firstId] = true;

        $GLOBALS['_pp_test_store']['download_url_calls']   = [];
        $GLOBALS['_pp_test_store']['media_sideload_calls'] = [];
        $second = pp_execute_apply('import_media', ['url' => $url]);
        $this->assertTrue($second['ok']);
        $this->assertSame('import', $second['changes'][0]['action']);
        $this->assertNotSame($firstId, $second['changes'][0]['attachment_id']);
        // It actually re-downloaded and re-sideloaded rather than reusing.
        $this->assertNotEmpty($GLOBALS['_pp_test_store']['download_url_calls']);
        $this->assertNotEmpty($GLOBALS['_pp_test_store']['media_sideload_calls']);
    }

    public function testImportMediaExecuteRejectsSsrfUnsafeDestination(): void
    {
        // Simulates wp_safe_remote_get() rejecting the URL or one of its
        // redirect hops (private IP, disallowed port, non-http(s) scheme) --
        // download_url() surfaces this as a WP_Error, which our apply must
        // propagate as a failed result, not silently swallow or retry unsafely.
        $this->resetImportMediaTestStore();
        $GLOBALS['_pp_test_store']['download_url_result'] =
            new WP_Error('http_request_failed', 'A valid URL was not provided.');
        $result = pp_execute_apply('import_media', ['url' => 'https://example.com/logo.jpg']);
        $this->assertFalse($result['ok']);
        $this->assertSame('A valid URL was not provided.', $result['error']);
        $this->assertEmpty($GLOBALS['_pp_test_store']['media_sideload_calls']);
    }

    public function testImportMediaExecuteRejectsOversizedFile(): void
    {
        $this->resetImportMediaTestStore();
        $GLOBALS['_pp_test_store']['download_url_size'] = 11 * 1024 * 1024; // 11MB > 10MB cap
        $result = pp_execute_apply('import_media', ['url' => 'https://example.com/logo.jpg']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('10MB', $result['error']);
        $this->assertEmpty($GLOBALS['_pp_test_store']['media_sideload_calls']);
    }

    public function testImportMediaExecuteRejectsTypeMismatch(): void
    {
        // The URL's extension passed the pre-fetch check, but the actual
        // downloaded bytes aren't a supported image type -- must be rejected
        // on real content, not just the URL's claimed extension.
        $this->resetImportMediaTestStore();
        $GLOBALS['_pp_test_store']['filetype_result'] = ['ext' => false, 'type' => false, 'proper_filename' => false];
        $result = pp_execute_apply('import_media', ['url' => 'https://example.com/logo.jpg']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a supported image type', $result['error']);
        $this->assertEmpty($GLOBALS['_pp_test_store']['media_sideload_calls']);
    }

    public function testImportMediaExecuteRejectsDisallowedMimeEvenIfDetected(): void
    {
        $this->resetImportMediaTestStore();
        $GLOBALS['_pp_test_store']['filetype_result'] =
            ['ext' => 'pdf', 'type' => 'application/pdf', 'proper_filename' => false];
        $result = pp_execute_apply('import_media', ['url' => 'https://example.com/logo.jpg']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a supported image type', $result['error']);
    }

    public function testImportMediaExecuteRejectsSideloadFailure(): void
    {
        $this->resetImportMediaTestStore();
        $GLOBALS['_pp_test_store']['media_sideload_result'] =
            new WP_Error('upload_error', 'Could not write file to disk.');
        $result = pp_execute_apply('import_media', ['url' => 'https://example.com/logo.jpg']);
        $this->assertFalse($result['ok']);
        $this->assertSame('Could not write file to disk.', $result['error']);
    }

    public function testImportMediaPreviewSuccess(): void
    {
        $this->resetImportMediaTestStore();
        $result = pp_preview_apply('import_media', ['url' => 'https://example.com/logo.jpg', 'alt' => 'Logo']);
        $this->assertTrue($result['ok']);
        $this->assertSame('https://example.com/logo.jpg', $result['after']['url']);
        $this->assertSame('Logo', $result['after']['alt']);
        $this->assertEmpty($GLOBALS['_pp_test_store']['media_sideload_calls']);
    }

    public function testImportMediaPreviewRejectsNonImageContentType(): void
    {
        $this->resetImportMediaTestStore();
        $GLOBALS['_pp_test_store']['safe_remote_head_result'] = ['headers' => ['content-type' => 'text/html']];
        $result = pp_preview_apply('import_media', ['url' => 'https://example.com/logo.jpg']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('unsupported_type', $result->get_error_code());
    }

    public function testImportMediaPreviewRejectsSsrfUnsafeDestination(): void
    {
        // Same rejection path as execute -- a redirect to a private/internal
        // destination must be rejected during preview too, not just apply.
        $this->resetImportMediaTestStore();
        $GLOBALS['_pp_test_store']['safe_remote_head_result'] =
            new WP_Error('http_request_failed', 'A valid URL was not provided.');
        $result = pp_preview_apply('import_media', ['url' => 'https://example.com/logo.jpg']);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testImportMediaRegisteredWithMediaDomain(): void
    {
        $apply = pp_get_apply('import_media');
        $this->assertSame('media', $apply['domain']);
        $this->assertSame('media', $apply['target']['type']);
    }

    // ── _pp_relative_theme_path() tests (issue 127) ─────────────────────────
    //
    // Pure function — Windows-style backslash inputs are asserted directly
    // regardless of which OS actually runs this test suite.

    public function testRelativeThemePathNormalizesWindowsSeparators(): void
    {
        $result = _pp_relative_theme_path('C:\\wp\\wp-content\\themes\\promptingpress', 'C:\\wp\\wp-content\\themes\\promptingpress\\components\\hero\\hero.php');
        $this->assertSame('components/hero/hero.php', $result);
    }

    public function testRelativeThemePathHandlesUnixSeparatorsUnchanged(): void
    {
        $result = _pp_relative_theme_path('/var/www/theme', '/var/www/theme/components/hero/hero.php');
        $this->assertSame('components/hero/hero.php', $result);
    }

    public function testRelativeThemePathNeverLeavesALeadingSeparator(): void
    {
        $result = _pp_relative_theme_path('C:\\wp\\theme', 'C:\\wp\\theme\\functions.php');
        $this->assertSame('functions.php', $result);
        $this->assertNotSame('\\functions.php', $result);
        $this->assertStringStartsNotWith('/', $result);
        $this->assertStringStartsNotWith('\\', $result);
    }

    public function testRelativeThemePathHandlesRootLevelFile(): void
    {
        $result = _pp_relative_theme_path('C:\\wp\\theme', 'C:\\wp\\theme\\style.css');
        $this->assertSame('style.css', $result);
    }

    public function testRelativeThemePathHandlesMixedSeparators(): void
    {
        // Defensive: a path assembled from mixed sources (e.g. a manifest
        // built with '/' loaded into a Windows run) must still normalize.
        $result = _pp_relative_theme_path('C:/wp/theme', 'C:\\wp\\theme\\assets\\css\\base.css');
        $this->assertSame('assets/css/base.css', $result);
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

    public function testHashAllThemeFilesProducesForwardSlashKeysForNestedFiles(): void
    {
        // Regression for issue 127: keys for nested files must always use
        // forward slashes, matching the manifest built on Linux CI —
        // regardless of which _pp_relative_theme_path() normalization path
        // ran, on any OS.
        $dir = sys_get_temp_dir() . '/pp-hash-test-' . getmypid() . '-' . mt_rand();
        mkdir($dir . '/components/hero', 0755, true);
        file_put_contents($dir . '/components/hero/hero.php', '<?php');

        $hashes = _pp_hash_all_theme_files($dir);

        $this->assertArrayHasKey('components/hero/hero.php', $hashes);

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
