<?php
/**
 * tests/SchemaSinkBoundsTest.php — the two bounds riders of #1242 T2.
 *
 *   #1200  A role's `defaults` / `overlay_defaults` value is printed whole by `wp pp schema`
 *          (raw-unicode sink) and scanned on every chat prompt build. The shape check now
 *          bounds it (PP_ROLE_DEFAULT_VALUE_MAX_BYTES, measured: the longest shipped value is
 *          35 bytes) and refuses the P-15 set (bidi overrides/isolates and the tag block), the
 *          same narrow set the assistant-context sink neutralizes: ZWJ and the other format
 *          characters stay admitted. pp_udc_is_single_line() fails CLOSED on invalid UTF-8.
 *   #1122  The preset-name message sink is count-bounded like every sibling list sink, so a
 *          row assembled outside the write verbs cannot put every stored name into the
 *          runtime prompt.
 *
 * RED ON MAIN (fc815d4): the cap, the P-15 refusal, the invalid-UTF-8 case and the count bound
 * each fail there by assertion.
 */

use PHPUnit\Framework\TestCase;

class SchemaSinkBoundsTest extends TestCase
{
    // ── #1200 ────────────────────────────────────────────────────────────

    public function testTheValueCapIsAByteBoundWithItsBoundaryPinned(): void
    {
        $this->assertSame(256, PP_ROLE_DEFAULT_VALUE_MAX_BYTES);
        $this->assertTrue(_pp_role_default_value_shape_ok(str_repeat('a', 256)));
        $this->assertFalse(_pp_role_default_value_shape_ok(str_repeat('a', 257)));
        // Bytes, not characters: 129 two-byte characters are 258 bytes.
        $this->assertFalse(_pp_role_default_value_shape_ok(str_repeat('ж', 129)));
    }

    public function testTheCapReachesInsideABreakpointMap(): void
    {
        $this->assertTrue(_pp_role_default_value_shape_ok(['d' => '12px', 'p' => '10px']));
        $this->assertFalse(_pp_role_default_value_shape_ok(['d' => '12px', 'p' => str_repeat('1', 300)]));
    }

    public function testEveryShippedValueIsWellInsideTheCap(): void
    {
        $longest = 0;
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            foreach ((array) ($schema['roles'] ?? []) as $role) {
                foreach (['defaults', 'overlay_defaults'] as $key) {
                    $values = (array) ($role[$key] ?? []);
                    array_walk_recursive($values, static function ($v) use (&$longest): void {
                        if (is_string($v)) {
                            $longest = max($longest, strlen($v));
                        }
                    });
                }
            }
        }
        $this->assertGreaterThan(0, $longest, 'premise: the walk saw values');
        $this->assertLessThanOrEqual(PP_ROLE_DEFAULT_VALUE_MAX_BYTES / 4, $longest,
            'the cap is a mistype/abuse bound, never a constraint on a real value');
    }

    public function testTheP15SetIsRefusedAndNothingElseInFormatCharactersIs(): void
    {
        foreach (["\u{202A}", "\u{202E}", "\u{2066}", "\u{2069}", "\u{E0000}", "\u{E0041}", "\u{E007F}"] as $char) {
            $this->assertFalse(_pp_role_default_value_shape_ok('#fff' . $char), sprintf('U+%04X', mb_ord($char)));
        }
        // P-15's boundary: ZWNJ, ZWJ, the directional marks and other \p{Cf} stay admitted here.
        foreach (["\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{061C}", "\u{FEFF}"] as $char) {
            $this->assertTrue(_pp_role_default_value_shape_ok('Font' . $char . 'Name'), sprintf('U+%04X', mb_ord($char)));
        }
    }

    public function testSingleLineFailsClosedOnInvalidUtf8(): void
    {
        $this->assertFalse(pp_udc_is_single_line("\xff\xfe"));
        $this->assertFalse(_pp_role_default_value_shape_ok("\xff\xfe"));
        $this->assertTrue(pp_udc_is_single_line('plain'));
    }

    public function testTheDefinitionValidatorReportsAnOverCapOverlayValue(): void
    {
        $definition = [
            'selector'         => '.x',
            'description'      => 'd',
            'groups'           => ['typography'],
            'obligations'      => [],
            'overlay_defaults' => ['typography' => ['color' => str_repeat('a', 300)]],
        ];
        $errors = pp_schema_definition_errors($definition, 'role', 'x.role');
        $this->assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, '`overlay_defaults` group `typography` parameter `color`')));
    }

    // ── #1122 ────────────────────────────────────────────────────────────

    public function testThePresetNameSinkIsCountBounded(): void
    {
        $presets = [];
        for ($i = 0; $i < 600; $i++) {
            $presets[sprintf('preset-%03d-%s', $i, str_repeat('x', 50))] = ['typography' => []];
        }
        $out = pp_udc_preset_names_for_message($presets);

        $shown = PP_SITE_PRESETS_MAX + count(pp_udc_system_presets());
        $this->assertStringEndsWith(', and ' . (600 - $shown) . ' more', $out);
        $this->assertSame($shown, substr_count($out, 'preset-'));
        $this->assertLessThan(5500, strlen($out), 'bounded by the store the write verbs can produce');
    }

    /** Every store the write verbs can produce is listed in full: the bound is the store's own cap. */
    public function testAFullLegitimateStoreIsListedWhole(): void
    {
        $presets = pp_udc_system_presets();
        for ($i = 0; $i < PP_SITE_PRESETS_MAX; $i++) {
            $presets['custom-' . $i] = ['typography' => []];
        }
        $out = pp_udc_preset_names_for_message($presets);

        $this->assertStringNotContainsString(' more', $out);
        $this->assertStringContainsString('custom-' . (PP_SITE_PRESETS_MAX - 1), $out);
    }

    public function testASmallPresetSetRendersExactlyAsBefore(): void
    {
        $this->assertSame('button, button-secondary, link',
            pp_udc_preset_names_for_message(['button' => [], 'button-secondary' => [], 'link' => []]));
        $this->assertSame('', pp_udc_preset_names_for_message([]));
    }

    public function testEachNameIsStillCleaned(): void
    {
        $this->assertSame('ab', pp_udc_preset_names_for_message(["a\u{202E}b" => []]));
    }
}
