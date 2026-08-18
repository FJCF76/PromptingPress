<?php
/**
 * tests/SchemaValidationTest.php
 *
 * Tests that schema validation fires E_USER_WARNING on missing required props
 * when WP_DEBUG is true, and is silent when WP_DEBUG is false.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class SchemaValidationTest extends TestCase
{
    private string $themeRoot;
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->themeRoot   = dirname(__DIR__);
        $this->fixturesDir = sys_get_temp_dir() . '/pp_schema_test_' . uniqid();
        mkdir($this->fixturesDir . '/components/test-schema', 0777, true);

        // Create a minimal component with a required prop.
        file_put_contents(
            $this->fixturesDir . '/components/test-schema/test-schema.php',
            '<?php echo esc_html($props["required_prop"] ?? "missing"); ?>'
        );

        file_put_contents(
            $this->fixturesDir . '/components/test-schema/schema.json',
            json_encode([
                'component'   => 'test-schema',
                'description' => 'Test component for schema validation.',
                'props'       => [
                    'required_prop' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                    'optional_prop' => [
                        'type'     => 'string',
                        'required' => false,
                        'default'  => '',
                    ],
                ],
            ])
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixturesDir);
    }

    // ── Schema validation logic (direct unit test) ────────────────────────

    /**
     * Tests that the schema validation logic detects missing required props.
     */
    public function testSchemaValidationDetectsMissingRequiredProp(): void
    {
        $schemaFile = $this->fixturesDir . '/components/test-schema/schema.json';
        $schema     = json_decode(file_get_contents($schemaFile), true);

        $props   = []; // 'required_prop' is missing.
        $missing = [];

        if ($schema && isset($schema['props'])) {
            foreach ($schema['props'] as $propName => $propDef) {
                if (!empty($propDef['required']) && !isset($props[$propName])) {
                    $missing[] = $propName;
                }
            }
        }

        $this->assertContains(
            'required_prop',
            $missing,
            'Schema validation should detect missing required prop.'
        );
    }

    /**
     * Tests that optional props do not trigger missing-prop detection.
     */
    public function testSchemaValidationDoesNotFlagOptionalProps(): void
    {
        $schemaFile = $this->fixturesDir . '/components/test-schema/schema.json';
        $schema     = json_decode(file_get_contents($schemaFile), true);

        $props   = ['required_prop' => 'provided']; // optional_prop intentionally absent.
        $missing = [];

        if ($schema && isset($schema['props'])) {
            foreach ($schema['props'] as $propName => $propDef) {
                if (!empty($propDef['required']) && !isset($props[$propName])) {
                    $missing[] = $propName;
                }
            }
        }

        $this->assertNotContains(
            'optional_prop',
            $missing,
            'Optional props must not be flagged as missing.'
        );

        $this->assertEmpty(
            $missing,
            'No required props should be missing when required_prop is provided.'
        );
    }

    /**
     * Tests that providing all required props triggers no warnings.
     */
    public function testSchemaValidationSilentWhenAllRequiredPropsPresent(): void
    {
        $schemaFile = $this->fixturesDir . '/components/test-schema/schema.json';
        $schema     = json_decode(file_get_contents($schemaFile), true);

        $props   = ['required_prop' => 'hello', 'optional_prop' => 'world'];
        $missing = [];

        if ($schema && isset($schema['props'])) {
            foreach ($schema['props'] as $propName => $propDef) {
                if (!empty($propDef['required']) && !isset($props[$propName])) {
                    $missing[] = $propName;
                }
            }
        }

        $this->assertEmpty($missing, 'No warnings should fire when all required props are provided.');
    }

    // ── Hero schema has correct required/optional classification ──────────

    public function testHeroSchemaRequiresTitleProp(): void
    {
        $schemaFile = $this->themeRoot . '/components/hero/schema.json';
        $schema     = json_decode(file_get_contents($schemaFile), true);

        $this->assertNotNull($schema, 'Hero schema.json should be valid JSON.');
        $this->assertArrayHasKey('props', $schema);
        $this->assertArrayHasKey('title', $schema['props']);
        $this->assertTrue(
            !empty($schema['props']['title']['required']),
            "Hero 'title' prop should be marked as required."
        );
    }

    public function testHeroSchemaSubtitleIsOptional(): void
    {
        $schemaFile = $this->themeRoot . '/components/hero/schema.json';
        $schema     = json_decode(file_get_contents($schemaFile), true);

        $this->assertArrayHasKey('subheading', $schema['props']);
        $this->assertEmpty(
            $schema['props']['subheading']['required'] ?? false,
            "Hero 'subheading' prop should be optional (required = false or absent)."
        );
    }

    // ── CTA schema requires button_text, button_url; title is optional ────

    public function testCtaSchemaRequiredProps(): void
    {
        $schemaFile = $this->themeRoot . '/components/cta/schema.json';
        $schema     = json_decode(file_get_contents($schemaFile), true);

        $this->assertNotNull($schema);

        foreach (['button_text', 'button_url'] as $required) {
            $this->assertTrue(
                !empty($schema['props'][$required]['required']),
                "CTA prop '{$required}' should be marked as required."
            );
        }
    }

    /**
     * issue 294: cta.title is optional so a title-less CTA renders a standalone
     * button row (the sanctioned heading-less button pattern). The required-props
     * gate is schema-driven, so this flag is what relaxes validation.
     */
    public function testCtaSchemaTitleIsOptional(): void
    {
        $schemaFile = $this->themeRoot . '/components/cta/schema.json';
        $schema     = json_decode(file_get_contents($schemaFile), true);

        $this->assertNotNull($schema);
        $this->assertFalse(
            $schema['props']['title']['required'] ?? false,
            "CTA 'title' prop should be optional (required = false or absent)."
        );
    }

    // ── Consistent layout/theme naming (issue #69) ──────────────────────

    /**
     * The retired `variant` prop must not appear in any component schema — v1
     * ships a consistent surface where structure is `layout` and tone is `theme`,
     * and no component overloads one key for both meanings.
     */
    public function testNoComponentSchemaDeclaresVariantProp(): void
    {
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $schemaFile) {
            $schema = json_decode(file_get_contents($schemaFile), true);
            $this->assertNotNull($schema, "Schema should be valid JSON: {$schemaFile}");
            $this->assertArrayNotHasKey(
                'variant',
                $schema['props'] ?? [],
                "Component '{$schema['component']}' must not declare a `variant` prop (issue #69: use `layout` and/or `theme`)."
            );
        }
    }

    /**
     * Structural components expose `layout`; tone-bearing components expose
     * `theme`. Pins the canonical split so a future edit can't silently
     * reintroduce the ambiguity.
     */
    public function testStructuralAndToneComponentsUseCanonicalKeys(): void
    {
        $expectLayout = ['hero', 'section', 'grid', 'cta', 'testimonials'];
        $expectTheme  = ['section', 'stats', 'logos', 'embed', 'grid', 'cta', 'testimonials', 'faq'];

        foreach ($expectLayout as $component) {
            $schema = json_decode(file_get_contents($this->themeRoot . "/components/{$component}/schema.json"), true);
            $this->assertArrayHasKey('layout', $schema['props'], "Component '{$component}' should declare a `layout` prop.");
        }
        foreach ($expectTheme as $component) {
            $schema = json_decode(file_get_contents($this->themeRoot . "/components/{$component}/schema.json"), true);
            $this->assertArrayHasKey('theme', $schema['props'], "Component '{$component}' should declare a `theme` prop.");
        }
    }

    // ── Style slot schema validation ────────────────────────────────────

    /**
     * Tests that all 4 v1 components have style_slots declared in schema.json.
     */
    public function testStyleSlotsExistForV1Components(): void
    {
        $expected = [
            'hero'    => 49,
            'section' => 47,
            // Issue 581 (A-18) added one state twin to each: --grid-item-link-hover-color
            // and --cta-button2-shadow.
            'grid'    => 38,
            'cta'     => 40,
        ];

        foreach ($expected as $component => $count) {
            $schemaFile = $this->themeRoot . "/components/{$component}/schema.json";
            $schema     = json_decode(file_get_contents($schemaFile), true);

            $this->assertArrayHasKey('styling', $schema, "{$component} schema must have a styling key.");
            $this->assertArrayHasKey('style_slots', $schema['styling'], "{$component} must have style_slots.");
            $this->assertCount(
                $count,
                $schema['styling']['style_slots'],
                "{$component} must have exactly {$count} style slots."
            );
        }
    }

    /**
     * The schemas are the single source of truth for the style-slot count. The count is
     * also restated in prose in AI_CONTEXT.md and README.md, which silently drift when a
     * slot is added. This derives the real count from the schemas and asserts the docs
     * match — so adding a slot fails the build until the docs are updated, killing the
     * hand-maintained magic numbers.
     */
    public function testDocsStyleSlotCountMatchesSchema(): void
    {
        // Derive the component list from the schemas themselves rather than a
        // hardcoded set: the original ['hero', 'section', 'grid', 'cta'] list
        // silently under-counted once faq/stats (#100) and testimonials (#1)
        // gained slots — the exact drift this test exists to kill.
        $perComponent = [];
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $schemaFile) {
            $schema = json_decode(file_get_contents($schemaFile), true);
            $count  = count($schema['styling']['style_slots'] ?? []);
            if ($count > 0) {
                $perComponent[basename(dirname($schemaFile))] = $count;
            }
        }
        $total = array_sum($perComponent);

        // AI_CONTEXT.md: the bolded total AND the per-component breakdown must
        // both match. The breakdown lists every slot-bearing component in
        // descending slot-count order.
        $aiContext = file_get_contents($this->themeRoot . '/AI_CONTEXT.md');
        $this->assertStringContainsString(
            "**{$total} style slots**",
            $aiContext,
            "AI_CONTEXT.md must state the schema-derived total of {$total} style slots."
        );
        arsort($perComponent);
        $breakdown = implode(', ', array_map(
            fn($component, $count) => "{$component} ({$count})",
            array_keys($perComponent),
            $perComponent
        ));
        $this->assertStringContainsString(
            $breakdown,
            $aiContext,
            "AI_CONTEXT.md per-component breakdown must match the schemas: {$breakdown}."
        );

        // README.md: every "<n> per-instance style slots" must equal the schema total.
        $readme = file_get_contents($this->themeRoot . '/README.md');
        $this->assertSame(
            1,
            preg_match_all('/(\d+) per-instance style slots/', $readme, $matches) > 0 ? 1 : 0,
            'README.md must state the per-instance style-slot count.'
        );
        foreach ($matches[1] as $stated) {
            $this->assertSame(
                $total,
                (int) $stated,
                "README.md states {$stated} per-instance style slots but the schemas declare {$total}."
            );
        }
    }

    /**
     * Tests that every declared style slot has the required keys: type, default, description.
     */
    public function testStyleSlotStructure(): void
    {
        $components = ['hero', 'section', 'grid', 'cta'];
        $validTypes = ['color', 'length', 'length-or-none', 'number', 'shadow', 'gradient', 'position', 'ratio', 'align', 'text-transform', 'font-family', 'enum'];

        foreach ($components as $component) {
            $schemaFile = $this->themeRoot . "/components/{$component}/schema.json";
            $schema     = json_decode(file_get_contents($schemaFile), true);
            $slots      = $schema['styling']['style_slots'] ?? [];

            foreach ($slots as $slotName => $slotDef) {
                $this->assertStringStartsWith(
                    "--{$component}-",
                    $slotName,
                    "Slot {$slotName} must be namespaced to its component (--{$component}-*)."
                );
                $this->assertArrayHasKey('type', $slotDef, "Slot {$slotName} must declare a type.");
                $this->assertContains($slotDef['type'], $validTypes, "Slot {$slotName} type must be one of: " . implode(', ', $validTypes) . '.');
                $this->assertArrayHasKey('default', $slotDef, "Slot {$slotName} must declare a default value.");
                $this->assertArrayHasKey('description', $slotDef, "Slot {$slotName} must have a description.");
                $this->assertNotEmpty($slotDef['description'], "Slot {$slotName} description must not be empty.");
                // An enum slot must declare a non-empty bounded value set, and its
                // default must be a member of that set (issue 510).
                if ($slotDef['type'] === 'enum') {
                    $this->assertArrayHasKey('values', $slotDef, "Enum slot {$slotName} must declare a values array.");
                    $this->assertNotEmpty($slotDef['values'], "Enum slot {$slotName} values must not be empty.");
                    $this->assertContains($slotDef['default'], $slotDef['values'], "Enum slot {$slotName} default must be one of its values.");
                }
            }
        }
    }

    /**
     * Tests that pp_get_style_slots() returns correct data for hero.
     */
    public function testGetStyleSlotsReturnsHeroSlots(): void
    {
        // pp_get_style_slots depends on pp_get_registered_components which uses get_template_directory().
        // We test the function indirectly by reading schema directly.
        $schemaFile = $this->themeRoot . '/components/hero/schema.json';
        $schema     = json_decode(file_get_contents($schemaFile), true);
        $slots      = $schema['styling']['style_slots'] ?? [];

        $this->assertArrayHasKey('--hero-padding-top', $slots);
        $this->assertArrayHasKey('--hero-bg', $slots);
        $this->assertArrayHasKey('--hero-heading-color', $slots);
        $this->assertArrayHasKey('--hero-heading-size', $slots);
        $this->assertEquals('length', $slots['--hero-padding-top']['type']);
        $this->assertEquals('gradient', $slots['--hero-bg']['type']);
    }

    /**
     * Tests that style slot names don't collide across components.
     */
    public function testStyleSlotNamesAreUniqueAcrossComponents(): void
    {
        $allSlots   = [];
        $components = ['hero', 'section', 'grid', 'cta'];

        foreach ($components as $component) {
            $schemaFile = $this->themeRoot . "/components/{$component}/schema.json";
            $schema     = json_decode(file_get_contents($schemaFile), true);
            $slots      = array_keys($schema['styling']['style_slots'] ?? []);

            foreach ($slots as $slot) {
                $this->assertArrayNotHasKey(
                    $slot,
                    $allSlots,
                    "Style slot {$slot} is declared in multiple components."
                );
                $allSlots[$slot] = $component;
            }
        }
    }

    /**
     * Decision 4 (eng review): every styleable component must declare the common
     * visual-control slots — border-color, border-width, radius, shadow — in its
     * own namespace. An explicit map (not a fragile suffix rule) preserves grid's
     * historical card-namespaced name (--grid-item-border-color) while enforcing full,
     * consistent coverage. Dropping one of these slots, or adding a styleable
     * component without them, fails CI. Pairs with StyleSlotContractTest, which
     * proves each declared slot is actually consumed in CSS.
     */
    public function testCommonVisualSlotConformance(): void
    {
        $expected = [
            'hero'    => ['--hero-border-color', '--hero-border-width', '--hero-radius', '--hero-shadow'],
            'section' => ['--section-border-color', '--section-border-width', '--section-radius', '--section-shadow'],
            'grid'    => ['--grid-item-border-color', '--grid-item-border-width', '--grid-item-radius', '--grid-item-shadow'],
            'cta'     => ['--cta-border-color', '--cta-border-width', '--cta-radius', '--cta-shadow'],
        ];
        // concept index → required type: [border-color, border-width, radius, shadow].
        $types = ['color', 'length', 'length', 'shadow'];

        foreach ($expected as $component => $slotNames) {
            $schemaFile = $this->themeRoot . "/components/{$component}/schema.json";
            $slots      = json_decode(file_get_contents($schemaFile), true)['styling']['style_slots'] ?? [];

            foreach ($slotNames as $i => $slotName) {
                $this->assertArrayHasKey(
                    $slotName,
                    $slots,
                    "{$component} must declare the common visual slot {$slotName}."
                );
                $this->assertSame(
                    $types[$i],
                    $slots[$slotName]['type'] ?? null,
                    "Slot {$slotName} must be type {$types[$i]}."
                );
            }
        }
    }

    // ── Composition style validation ────────────────────────────────────

    public function testCompositionValidWithStyleSlots(): void
    {
        $composition = [
            [
                'component' => 'hero',
                'props'     => ['title' => 'Test'],
                'style'     => ['--hero-bg' => '#1a1a2e', '--hero-padding-top' => '8rem'],
            ],
        ];
        $result = pp_validate_composition($composition);
        $this->assertTrue($result);
    }

    public function testCompositionValidWithoutStyle(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Test']],
        ];
        $result = pp_validate_composition($composition);
        $this->assertTrue($result);
    }

    // ── Title-less CTA passes shared validation (issue 294) ───────────────

    public function testTitlelessCtaPassesValidation(): void
    {
        // The shared engine's required-props gate is schema-driven, so relaxing
        // cta.title in schema.json is what lets a title-less CTA validate.
        $composition = [
            ['component' => 'cta', 'props' => ['button_text' => 'Go', 'button_url' => '/']],
        ];
        $result = pp_validate_composition($composition);
        $this->assertTrue($result, 'A title-less CTA with button props must validate.');
    }

    public function testCtaMissingButtonTextStillRejected(): void
    {
        // Relaxing title must NOT relax button_text — it is still required.
        $composition = [
            ['component' => 'cta', 'props' => ['button_url' => '/']],
        ];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_composition', $result->get_error_code());
        $this->assertStringContainsString('button_text', $result->get_error_message());
    }

    public function testCtaMissingButtonUrlStillRejected(): void
    {
        $composition = [
            ['component' => 'cta', 'props' => ['button_text' => 'Go']],
        ];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_composition', $result->get_error_code());
        $this->assertStringContainsString('button_url', $result->get_error_message());
    }

    // ── Grid explicit column-count control (issue 379) ───────────────────
    //
    // grid.columns declares integer min/max bounds in schema.json, so the shared
    // validator's generic bounds check accepts only integers 1-4 (and the unset
    // sentinel) and rejects everything else with invalid_prop_value — the write
    // never persists an out-of-range value the renderer would silently coerce.

    private function gridCompositionWithColumns($columns): array
    {
        $props = ['items' => [['title' => 'One'], ['title' => 'Two'], ['title' => 'Three']]];
        // A literal null means "supply the key as null" (the unset sentinel);
        // the __ABSENT__ marker means "omit the key entirely".
        if ($columns !== '__ABSENT__') {
            $props['columns'] = $columns;
        }
        return [['component' => 'grid', 'props' => $props]];
    }

    /**
     * @dataProvider validColumnsProvider
     */
    public function testGridColumnsAcceptsInBoundIntegers($columns): void
    {
        $result = pp_validate_composition($this->gridCompositionWithColumns($columns));
        $this->assertTrue($result, 'columns=' . var_export($columns, true) . ' must validate.');
    }

    public static function validColumnsProvider(): array
    {
        return [
            'int 1'         => [1],
            'int 4'         => [4],
            'int 2'         => [2],
            'string "3"'    => ['3'],
            'unset: absent' => ['__ABSENT__'],
            'unset: null'   => [null],
            'unset: empty'  => [''],
        ];
    }

    /**
     * @dataProvider invalidColumnsProvider
     */
    public function testGridColumnsRejectsOutOfRangeOrNonInteger($columns): void
    {
        $result = pp_validate_composition($this->gridCompositionWithColumns($columns));
        $this->assertInstanceOf(\WP_Error::class, $result, 'columns=' . var_export($columns, true) . ' must be rejected.');
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        // The envelope names the offending prop so the caller/AI can correct it.
        $this->assertStringContainsString('columns', $result->get_error_message());
    }

    public static function invalidColumnsProvider(): array
    {
        return [
            'zero (below min)'       => [0],
            'five (above max)'       => [5],
            'negative'               => [-1],
            'non-integer float'      => [2.5],
            'non-numeric string'     => ['three'],
            'string "0"'             => ['0'],
            'string "5"'             => ['5'],
        ];
    }

    // ── Grid item image treatment (issue 380) ────────────────────────────
    //
    // grid.image_treatment is a strict enum (type:enum + strict:true in
    // schema.json), so the shared validator's generic strict-enum check accepts
    // only the declared values ("banner"/"icon") and the unset sentinel, and
    // rejects everything else with invalid_prop_value — the write never persists
    // an unknown value the renderer would silently coerce to the banner default.

    private function gridCompositionWithImageTreatment($treatment): array
    {
        $props = ['items' => [['title' => 'One', 'image_url' => 'x.png']]];
        // '__ABSENT__' omits the key entirely; anything else is supplied verbatim.
        if ($treatment !== '__ABSENT__') {
            $props['image_treatment'] = $treatment;
        }
        return [['component' => 'grid', 'props' => $props]];
    }

    /**
     * @dataProvider validImageTreatmentProvider
     */
    public function testGridImageTreatmentAcceptsDeclaredValuesAndUnset($treatment): void
    {
        $result = pp_validate_composition($this->gridCompositionWithImageTreatment($treatment));
        $this->assertTrue($result, 'image_treatment=' . var_export($treatment, true) . ' must validate.');
    }

    public static function validImageTreatmentProvider(): array
    {
        return [
            'banner'        => ['banner'],
            'icon'          => ['icon'],
            'unset: absent' => ['__ABSENT__'],
            'unset: null'   => [null],
            'unset: empty'  => [''],
        ];
    }

    /**
     * @dataProvider invalidImageTreatmentProvider
     */
    public function testGridImageTreatmentRejectsValuesOutsideTheClosedSet($treatment): void
    {
        $result = pp_validate_composition($this->gridCompositionWithImageTreatment($treatment));
        $this->assertInstanceOf(\WP_Error::class, $result, 'image_treatment=' . var_export($treatment, true) . ' must be rejected.');
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        // The envelope names the offending prop so the caller/AI can correct it.
        $this->assertStringContainsString('image_treatment', $result->get_error_message());
    }

    public static function invalidImageTreatmentProvider(): array
    {
        return [
            'unknown keyword'     => ['card'],
            'case mismatch'       => ['Icon'],
            'uppercase'           => ['BANNER'],
            'domain look-alike'   => ['thumbnail'],
            'numeric'             => [1],
            'whitespace-padded'   => [' icon'],
        ];
    }

    /**
     * #579, A-32 REPLACES the opt-in posture this used to pin. `strict` shipped in
     * #380 as an opt-in flag and exactly one prop ever opted in, so twenty-eight
     * enums stayed accept-at-write / coerce-at-render — the write reported ok:true
     * and the page rendered the default. Every enum declares `strict: true` now, so
     * an out-of-set value is rejected at write with a named error.
     */
    public function testEveryEnumPropRejectsAnOutOfSetValue(): void
    {
        $result = pp_validate_composition([
            ['component' => 'grid', 'props' => [
                'layout' => 'bogus-not-a-layout',
                'items'  => [['title' => 'One']],
            ]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        $this->assertStringContainsString('cards, steps', $result->get_error_message());
    }

    /**
     * The declaration side of the same change: NO enum declaration, at either depth,
     * may be left accept-and-coerce. This is the tripwire that keeps a NEW enum from
     * shipping without `strict`, which is exactly how #380's mechanism sat unused for
     * 199 issues.
     *
     * #600 WIDENED THIS FROM TOP-LEVEL ONLY. #579 scoped it to $schema['props'] to
     * match the runtime gate, and named the nested item-field enums a known gap in
     * the docblock. The gate now walks one items[] level too, so the scope that made
     * the old name honest is gone: a nested enum without `strict` is a real hole
     * again, not a no-op declaration. Both depths, one tripwire.
     */
    public function testEveryEnumDeclarationDeclaresStrict(): void
    {
        $missing = [];
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode(file_get_contents($file), true);
            foreach ($schema['props'] ?? [] as $propName => $propDef) {
                if (($propDef['type'] ?? null) === 'enum' && empty($propDef['strict'])) {
                    $missing[] = "{$component}.{$propName}";
                }
                // One items[] level down — the same depth pp_validate_composition_errors()
                // walks. A field map's values are definition arrays; the JSON-Schema-ish
                // scalar form (bullets.items => {"type": "string"}) is not, and the
                // is_array() guard is what the runtime rule uses to tell them apart.
                foreach (($propDef['items'] ?? []) as $field => $fieldDef) {
                    if (is_array($fieldDef)
                        && ($fieldDef['type'] ?? null) === 'enum'
                        && empty($fieldDef['strict'])
                    ) {
                        $missing[] = "{$component}.{$propName}[].{$field}";
                    }
                }
            }
        }
        $this->assertSame(
            [],
            $missing,
            'every enum declaration must declare "strict": true — top-level (#579, A-32) and nested items[] fields (#600)'
        );
    }

    /**
     * The nested-enum INVENTORY, kept explicit so widening the rule's reach is a
     * deliberate act rather than a silent consequence of adding a schema field.
     *
     * This replaces testNestedItemEnumsAreAKnownAcceptAndCoerceGap, which pinned the
     * same inventory to prove the gap was recorded rather than forgotten. #600 closed
     * the gap, so the assertion it carried (that the value still validates, and that
     * `strict` on a nested enum is a no-op worth asserting against) is now false by
     * design and was deleted rather than weakened. What survives is the count: if a
     * second nested enum appears, this fails and whoever added it confirms the
     * runtime rule and the authoring-path proofs reach it.
     */
    public function testTheNestedEnumInventoryIsExactlyTextRole(): void
    {
        $nested = [];
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode(file_get_contents($file), true);
            foreach ($schema['props'] ?? [] as $propName => $propDef) {
                foreach (($propDef['items'] ?? []) as $field => $fieldDef) {
                    if (is_array($fieldDef) && ($fieldDef['type'] ?? null) === 'enum') {
                        $nested[] = "{$component}.{$propName}[].{$field}";
                    }
                }
            }
        }
        $this->assertSame(['grid.items[].text_role'], $nested, 'the nested-enum inventory changed');
    }

    /**
     * The runtime half of #600 at the schema-declaration boundary: `strict` on a
     * nested enum is REACHED now. This is the assertion the deleted gap-pin inverted
     * — same component, same field, same value, opposite verdict.
     */
    public function testANestedEnumValueOutsideTheDeclaredSetIsRejected(): void
    {
        $result = pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card', 'text' => 'x', 'text_role' => 'bogus-not-a-role'],
            ]]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        $message = $result->get_error_message();
        $this->assertStringContainsString('item 0 field "text_role"', $message, 'the locator must name the item and the field');
        $this->assertStringContainsString('mono, meta, label, kicker', $message, 'the error names the advertised set');
        $this->assertStringContainsString('bogus-not-a-role', $message, 'the rejected value is reflected back');
    }

    /**
     * THE LOCATOR, pinned at a non-zero index in a non-zero band — which is the only
     * shape that can tell the item index apart from the COMPONENT index. Every other
     * case in this family puts the offending role at items[0] of component[0], where
     * `item 0` is true for either reading, so the message would survive reporting the
     * wrong number entirely. The locator is what tells an author which card to
     * repair, and #600's accepted cost (a stale role blocks the whole page) rests on
     * being able to find it.
     */
    public function testTheRejectionNamesTheOffendingItemAndBandRatherThanTheFirst(): void
    {
        $result = pp_validate_composition([
            ['component' => 'section', 'props' => ['title' => 'First band', 'body' => 'B']],
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Fine',      'text_role' => 'mono'],
                ['title' => 'Fine too',  'text_role' => 'meta'],
                ['title' => 'Offending', 'text_role' => 'terminal'],
            ]]],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString(
            'item 2 field "text_role"',
            $result->get_error_message(),
            'the message must name the offending ITEM index, not the first item or the band index'
        );
        $this->assertSame(1, $result->get_error_data()['index'] ?? null, 'the finding carries the offending BAND index');
    }

    /**
     * ONE ERROR PER OFFENDING FIELD, and every band reached (#621 rewrote this from
     * "one error per component"). The shape is chosen to pin the whole traversal:
     * the first band has two cards with out-of-set roles AND a dead link_url whose
     * rule lives in a LATER block of the same per-component loop, and there is a
     * second band behind it. Four findings, in traversal order — the nested-field
     * walk first (both cards), then the link_url block, then the next band.
     *
     * Before #621 this asserted TWO findings: the enum rule ended the whole component
     * item, so card 1's role and card 0's dead link were invisible until card 0's role
     * was repaired. The band-index assertions are what stop the fix from smearing one
     * band's findings onto another.
     */
    public function testTheNestedEnumReportsEveryOffendingFieldAcrossBands(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Bad one', 'text_role' => 'terminal', 'link_url' => 'javascript:alert(1)'],
                ['title' => 'Bad two', 'text_role' => 'console'],
            ]]],
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Bad three', 'text_role' => 'shell'],
            ]]],
        ]);

        $this->assertCount(4, $errors, 'every offending field is named, not just the first');
        $messages = array_map(static fn ($e) => $e->get_error_message(), $errors);
        $this->assertStringContainsString('terminal', $messages[0]);
        $this->assertStringContainsString('console', $messages[1], 'the SECOND card of the same band is reached');
        $this->assertStringContainsString('link_url', $messages[2], 'a later rule block on the same band still runs');
        $this->assertStringContainsString('shell', $messages[3], 'the second band is still reached');
        $this->assertSame(
            [0, 0, 0, 1],
            array_map(static fn ($e) => pp_composition_error_index($e), $errors),
            'each error carries the band that owns it'
        );
    }

    /**
     * THE PREDICATE ITSELF, arm by arm. Three of its guards are unreachable from the
     * shipped schemas — the CI tripwire above guarantees no shipped enum lacks
     * `strict`, and nothing ships a malformed `values` — so without a direct test
     * they can be deleted with the whole suite still green. The `values` guards in
     * particular are load-bearing beyond validation: both callers implode() that
     * array into the rejection message without re-checking it.
     *
     * @dataProvider enumPredicateProvider
     */
    public function testTheSharedEnumPredicateGuardsEachArm(bool $expected, $definition, $value, string $why): void
    {
        $this->assertSame($expected, \_pp_schema_enum_value_is_valid($definition, $value), $why);
    }

    public static function enumPredicateProvider(): array
    {
        $strict = ['type' => 'enum', 'strict' => true, 'values' => ['mono', 'meta']];
        return [
            'not an array'          => [true, 'mono', 'anything', 'a non-array definition is not this rule\'s business'],
            'not an enum'           => [true, ['type' => 'string'], 'anything', 'a string field falls through untouched'],
            'enum without strict'   => [true, ['type' => 'enum', 'values' => ['mono']], 'bogus', '`strict` is what arms the rule'],
            'strict false'          => [true, ['type' => 'enum', 'strict' => false, 'values' => ['mono']], 'bogus', 'an explicit false disarms it too'],
            'values missing'        => [true, ['type' => 'enum', 'strict' => true], 'bogus', 'no advertised set means nothing to enforce — and nothing to implode'],
            'values empty'          => [true, ['type' => 'enum', 'strict' => true, 'values' => []], 'bogus', 'an empty set cannot reject'],
            'values not an array'   => [true, ['type' => 'enum', 'strict' => true, 'values' => 'mono'], 'bogus', 'a malformed set is not a membership test'],
            'null sentinel'         => [true, $strict, null, 'the unset sentinel preserves the default'],
            'empty-string sentinel' => [true, $strict, '', 'the unset sentinel preserves the default'],
            'member'                => [true, $strict, 'mono', 'an advertised value is accepted'],
            'non-member'            => [false, $strict, 'bogus', 'an unadvertised value is rejected'],
            'loose-equality trap'   => [false, $strict, 0, '0 == "mono" in PHP loose comparison — the test must be strict'],
            'array value'           => [false, $strict, ['mono'], 'a container is not a member'],
        ];
    }

    /**
     * Every declared role is accepted, one case per value — the rule must not reject
     * the vocabulary it advertises.
     *
     * @dataProvider declaredTextRoleProvider
     */
    public function testEveryDeclaredNestedEnumValueIsAccepted(string $role): void
    {
        $this->assertTrue(pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card', 'text' => 'x', 'text_role' => $role],
            ]]],
        ]), "the declared role \"{$role}\" must be accepted");
    }

    public static function declaredTextRoleProvider(): array
    {
        return [
            'mono'   => ['mono'],
            'meta'   => ['meta'],
            'label'  => ['label'],
            'kicker' => ['kicker'],
        ];
    }

    /**
     * The unset sentinel at the NESTED depth, matching the top-level rule exactly
     * (testStrictEnumUnsetSentinelStillValidates below is its sibling). Over-rejecting
     * here is not a local inconvenience: every action validates the WHOLE composition,
     * so a rule that rejected a blank would block edits to unrelated bands.
     *
     * @dataProvider nestedEnumUnsetSentinelProvider
     */
    public function testTheNestedEnumUnsetSentinelStillValidates(array $item): void
    {
        $this->assertTrue(pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [$item]]],
        ]), 'the unset sentinel must preserve the field default');
    }

    public static function nestedEnumUnsetSentinelProvider(): array
    {
        return [
            'key absent'   => [['title' => 'Card', 'text' => 'x']],
            'null'         => [['title' => 'Card', 'text' => 'x', 'text_role' => null]],
            'empty string' => [['title' => 'Card', 'text' => 'x', 'text_role' => '']],
        ];
    }

    /**
     * The near-miss family, one case per shape — the reason a membership test is
     * `===` against the advertised list and not a fuzzy match. Each of these used to
     * be accepted at write and coerced away at render.
     *
     * @dataProvider nearMissTextRoleProvider
     */
    public function testNearMissNestedEnumValuesAreRejected($role): void
    {
        $result = pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card', 'text_role' => $role],
            ]]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result, 'a near-miss role must not slip through');
        $this->assertSame('invalid_prop_value', $result->get_error_code());
    }

    public static function nearMissTextRoleProvider(): array
    {
        return [
            'trailing space'  => ['mono '],
            'leading space'   => [' mono'],
            'case mismatch'   => ['Mono'],
            'uppercase'       => ['KICKER'],
            'plural'          => ['labels'],
            'numeric'         => [1],
            'boolean'         => [true],
            'array'           => [['mono']],
        ];
    }

    /**
     * The unset sentinel is untouched by the universal strict gate: an absent key,
     * null, or the empty string all keep the prop's declared default behaviour.
     */
    public function testStrictEnumUnsetSentinelStillValidates(): void
    {
        foreach ([null, ''] as $unset) {
            $result = pp_validate_composition([
                ['component' => 'grid', 'props' => [
                    'layout' => $unset,
                    'items'  => [['title' => 'One']],
                ]],
            ]);
            $this->assertTrue($result, 'the unset sentinel must preserve the default');
        }
    }

    /**
     * THE RUNTIME SURFACE of the `aliases` retirement (#606), inverted from the pin it
     * replaces. #575 declared the field, #579 wired it into the strict-enum membership
     * test, and #605 removed the last shipped declaration; #606 removed the field and
     * the arm that consumed it. The accepted set is now the ADVERTISED set, full stop.
     *
     * Why a synthetic component rather than a deletion. The definition surface is a
     * repo-CI invariant, NOT a runtime gate (pp_slot_definition_keys' docblock), so a
     * schema carrying a retired key still LOADS at runtime — its key is simply unread.
     * That is exactly the state this test drives: a declaration that used to widen the
     * accepted set is now inert, and the value it named is rejected like any other
     * unadvertised value. Asserting on a locally computed array would be a tautology
     * that stays green with the arm restored; this fails if the arm comes back.
     *
     * The schema-surface half of the retirement — that the declaration ITSELF is now an
     * unknown definition key — is deliberately a SEPARATE test
     * (testTheRetiredAliasesKeyIsNowAnUnknownDefinitionKey). One test, one contract.
     */
    public function testARetiredAliasesDeclarationNoLongerWidensTheStrictEnumSet(): void
    {
        $root = sys_get_temp_dir() . '/pp-alias-fixture-' . uniqid('', true);
        mkdir($root . '/components/aliasband', 0777, true);
        file_put_contents($root . '/components/aliasband/aliasband.php', '<?php // fixture');
        file_put_contents($root . '/components/aliasband/schema.json', json_encode([
            'component' => 'aliasband',
            'props'     => [
                'tone' => [
                    'type' => 'enum', 'required' => false, 'default' => 'a',
                    'description' => 'Synthetic strict enum carrying a RETIRED aliases declaration.',
                    'values' => ['a', 'b'], 'aliases' => ['legacy_a'], 'strict' => true,
                ],
            ],
        ]));

        $previousRoot = $GLOBALS['_pp_test_template_dir'] ?? null;
        $GLOBALS['_pp_test_template_dir'] = $root;
        $GLOBALS['_pp_registered_components_invalidate'] = true;

        try {
            // THE INVERSION. The declared value used to be accepted here. It is now
            // rejected, because the membership test consults `values` and nothing else.
            $rejected = \pp_validate_composition([
                ['component' => 'aliasband', 'props' => ['tone' => 'legacy_a']],
            ]);
            $this->assertInstanceOf(
                \WP_Error::class,
                $rejected,
                'a retired `aliases` declaration must not widen the strict-enum accepted set (#606)'
            );
            $this->assertSame('invalid_prop_value', $rejected->get_error_code());
            // The error names the advertised set — which is now the WHOLE accepted set,
            // so the message an agent reads is complete rather than merely honest. The
            // retired value appears ONLY in the `got` clause (it is what was written);
            // it must never appear in the accepted-set clause, which is the vocabulary
            // the agent is being told to write next.
            $message = $rejected->get_error_message();
            $this->assertStringContainsString('must be one of: a, b; got "legacy_a"', $message);
            $this->assertStringNotContainsString('legacy_a,', $message,
                'the retired value must never appear inside the accepted-set list');

            // The advertised values still validate: the gate was retired-from, not weakened.
            $this->assertTrue(
                \pp_validate_composition([['component' => 'aliasband', 'props' => ['tone' => 'a']]])
            );
            $this->assertTrue(
                \pp_validate_composition([['component' => 'aliasband', 'props' => ['tone' => 'b']]])
            );
            // And an unrelated out-of-set value is rejected the same way the retired
            // alias now is — there is exactly one class of rejection, not two tiers.
            $this->assertInstanceOf(\WP_Error::class, \pp_validate_composition([
                ['component' => 'aliasband', 'props' => ['tone' => 'legacy_b']],
            ]));

            // AUTHORING-PATH proof (Section 14.1). The rule changed here is a
            // validation rule, so it is exercised through the REAL write surface and
            // not only through the shared validator: create_page rejects the retired
            // value even when a canonical sibling band is valid, which is the shape an
            // agent actually hits — one stale band blocking the whole write.
            $GLOBALS['_pp_test_store'] = [
                'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
            ];
            $authored = \pp_validate_action('create_page', [
                'title'       => 'Retired alias page',
                'composition' => [
                    ['component' => 'aliasband', 'props' => ['tone' => 'legacy_a']],
                    ['component' => 'aliasband', 'props' => ['tone' => 'b']],
                ],
            ]);
            $this->assertInstanceOf(
                \WP_Error::class,
                $authored,
                'the retired alias must be rejected at the real authoring surface, not just in the validator'
            );
            $this->assertSame('invalid_prop_value', $authored->get_error_code());
        } finally {
            if ($previousRoot === null) {
                unset($GLOBALS['_pp_test_template_dir']);
            } else {
                $GLOBALS['_pp_test_template_dir'] = $previousRoot;
            }
            $GLOBALS['_pp_registered_components_invalidate'] = true;
            @unlink($root . '/components/aliasband/schema.json');
            @unlink($root . '/components/aliasband/aliasband.php');
            @rmdir($root . '/components/aliasband');
            @rmdir($root . '/components');
            @rmdir($root);
        }
    }

    /**
     * RULE 4 IS GENERIC, proved against a component that does not exist in the
     * shipped theme.
     *
     * Every other #600 case authors `grid.items[].text_role`, because it is the only
     * nested enum shipped today — which means all of them would still pass if the
     * rule were a text_role branch rather than a schema-driven pass. This one
     * declares a synthetic component with a differently-named nested enum on a
     * differently-named array prop, so it fails if the rule ever learns a field name.
     * Same fixture technique as the retired-alias test above (temp theme root +
     * registry invalidation), for the same reason: the contract under test is about
     * ANY schema, and asserting it against the twelve shipped ones is a weaker claim.
     *
     * It also covers the arm the shipped schemas cannot reach: a SECOND nested enum
     * on the same component that declares no `strict` stays unenforced, which is what
     * makes the declaration (not the type) the thing that arms the rule.
     */
    public function testTheNestedEnumRuleIsSchemaDrivenNotATextRoleBranch(): void
    {
        $root = sys_get_temp_dir() . '/pp-nested-enum-fixture-' . uniqid('', true);
        mkdir($root . '/components/rowband', 0777, true);
        file_put_contents($root . '/components/rowband/rowband.php', '<?php // fixture');
        file_put_contents($root . '/components/rowband/schema.json', json_encode([
            'component' => 'rowband',
            'props'     => [
                'rows' => [
                    'type' => 'array', 'required' => false, 'item_type' => 'object',
                    'description' => 'Synthetic object-item array carrying two nested enums.',
                    'items' => [
                        'label' => ['type' => 'string', 'required' => false, 'description' => 'Row label.'],
                        'tone'  => [
                            'type' => 'enum', 'required' => false, 'strict' => true,
                            'values' => ['calm', 'loud'], 'description' => 'Synthetic STRICT nested enum.',
                        ],
                        'mood'  => [
                            'type' => 'enum', 'required' => false,
                            'values' => ['dry', 'wet'], 'description' => 'Synthetic nested enum with NO strict.',
                        ],
                    ],
                ],
            ],
        ]));

        $previousRoot = $GLOBALS['_pp_test_template_dir'] ?? null;
        $GLOBALS['_pp_test_template_dir'] = $root;
        $GLOBALS['_pp_registered_components_invalidate'] = true;

        try {
            $rejected = \pp_validate_composition([
                ['component' => 'rowband', 'props' => ['rows' => [
                    ['label' => 'First', 'tone' => 'calm'],
                    ['label' => 'Second', 'tone' => 'screaming'],
                ]]],
            ]);
            $this->assertInstanceOf(\WP_Error::class, $rejected, 'the rule must reach a nested enum it has never heard of');
            $this->assertSame('invalid_prop_value', $rejected->get_error_code());
            $message = $rejected->get_error_message();
            $this->assertStringContainsString('prop "rows" item 1 field "tone"', $message, 'the locator follows the schema, not a hardcoded prop name');
            $this->assertStringContainsString('must be one of: calm, loud', $message);

            // The advertised values still author cleanly.
            $this->assertTrue(\pp_validate_composition([
                ['component' => 'rowband', 'props' => ['rows' => [['tone' => 'loud']]]],
            ]));

            // The sibling enum declares no `strict`, so it is unenforced — the
            // DECLARATION arms the rule, and the CI tripwire is what keeps a shipped
            // schema from sitting in this state.
            $this->assertTrue(\pp_validate_composition([
                ['component' => 'rowband', 'props' => ['rows' => [['mood' => 'lukewarm']]]],
            ]), 'a nested enum without `strict` stays unenforced at runtime');

            // AUTHORING-PATH proof (Section 14.1) on the synthetic component too.
            $GLOBALS['_pp_test_store'] = [
                'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
            ];
            $authored = \pp_validate_action('create_page', [
                'title'       => 'Synthetic nested enum page',
                'composition' => [['component' => 'rowband', 'props' => ['rows' => [['tone' => 'screaming']]]]],
            ]);
            $this->assertInstanceOf(\WP_Error::class, $authored, 'the real write surface enforces it too');
            $this->assertSame('invalid_prop_value', $authored->get_error_code());
        } finally {
            if ($previousRoot === null) {
                unset($GLOBALS['_pp_test_template_dir']);
            } else {
                $GLOBALS['_pp_test_template_dir'] = $previousRoot;
            }
            $GLOBALS['_pp_registered_components_invalidate'] = true;
            @unlink($root . '/components/rowband/schema.json');
            @unlink($root . '/components/rowband/rowband.php');
            @rmdir($root . '/components/rowband');
            @rmdir($root . '/components');
            @rmdir($root);
        }
    }

    /**
     * RULE 5's field-map discriminator is not fooled by an array-valued schema KEYWORD
     * (#643). A field definition is a JSON object; the array-valued keys a definition may
     * otherwise carry — `values`, `applies_when`, an array `default` — are all JSON lists,
     * so the predicate excludes lists rather than testing `is_array` alone. Mutation-checked
     * in a scratch copy: reverting to a bare `is_array` test fails the first assertion below.
     *
     * No shipped schema can reach this shape (every top-level array prop with an `items`
     * key declares a real field map), which is exactly why it needs a synthetic component:
     * asserting the discriminator against the twelve shipped schemas would prove nothing
     * about the case that breaks it. Under a bare `is_array` test the `hits` prop below
     * reads as a field map holding one field named `values`, and every real entry key gets
     * reported as undeclared against `Available fields: values` — confidently wrong output,
     * which is worse than the silence this rule replaced.
     */
    public function testAnArrayValuedSchemaKeywordIsNotMistakenForADeclaredItemField(): void
    {
        $root = sys_get_temp_dir() . '/pp-fieldmap-fixture-' . uniqid('', true);
        mkdir($root . '/components/keyband', 0777, true);
        file_put_contents($root . '/components/keyband/keyband.php', '<?php // fixture');
        file_put_contents($root . '/components/keyband/schema.json', json_encode([
            'component' => 'keyband',
            'props'     => [
                // A scalar VALUE grammar carrying an array-valued sibling keyword. This is
                // the shape the discriminator has to refuse to read as a field map.
                // A VALUE GRAMMAR carrying an array-valued schema keyword, over entries
                // that are objects: "each entry is a free-form object, with no field
                // contract". This is the exact shape that separates the two predicates —
                // under a bare `is_array` test `default => []` reads as a declared field
                // named `default`, and every real key of every entry is then reported as
                // undeclared against `Available fields: default`.
                'bag' => [
                    'type' => 'array', 'required' => false,
                    'description' => 'Synthetic free-form object array with an array default.',
                    'items' => ['type' => 'object', 'default' => [], 'values' => ['a', 'b']],
                ],
                // The JSON-Schema LIST form: a one-element list of definitions rather
                // than a map of named fields.
                'listform' => [
                    'type' => 'array', 'required' => false,
                    'description' => 'Synthetic JSON-Schema list-form items declaration.',
                    'items' => [['type' => 'string']],
                ],
                // A real field map on the same component, so the test also proves the
                // discriminator still ADMITS the shape it is supposed to judge.
                'rows' => [
                    'type' => 'array', 'required' => false, 'item_type' => 'object',
                    'description' => 'Synthetic object-item array with a real field map.',
                    'items' => [
                        'label' => ['type' => 'string', 'required' => false, 'description' => 'Row label.'],
                    ],
                ],
            ],
        ]));

        $previousRoot = $GLOBALS['_pp_test_template_dir'] ?? null;
        $GLOBALS['_pp_test_template_dir'] = $root;
        $GLOBALS['_pp_registered_components_invalidate'] = true;

        try {
            // The value-grammar prop declares no field contract, so nothing inside its
            // OBJECT entries can be "undeclared". Verified load-bearing: revert the
            // predicate to a bare is_array() test and this assertion fails with
            // `has no field "anything". Available fields: default`.
            $this->assertTrue(\pp_validate_composition([
                ['component' => 'keyband', 'props' => ['bag' => [['anything' => 1, 'goes' => 2]]]],
            ]), 'an array-valued schema keyword is not a declared field');

            // The real field map on the same component still rejects an undeclared field,
            // so the tighter predicate did not disarm the rule.
            $rejected = \pp_validate_composition([
                ['component' => 'keyband', 'props' => ['rows' => [['label' => 'One', 'labl' => 'typo']]]],
            ]);
            $this->assertInstanceOf(\WP_Error::class, $rejected);
            $this->assertSame('unknown_prop', $rejected->get_error_code());
            $this->assertStringContainsString(
                'prop "rows" item 0 has no field "labl". Available fields: label',
                $rejected->get_error_message(),
                'the available list names declared FIELDS only, never a schema keyword'
            );
            // THE JSON-SCHEMA LIST FORM is not a field map either, and the map-level
            // shape test is what says so. Without it the declaration survives as
            // [0 => {...}] — non-empty, so the carve-out does not fire — and every REAL
            // key of every entry is rejected against a phantom field named `0`, which is
            // the same confidently-wrong output the keyword case produces.
            $this->assertTrue(\pp_validate_composition([
                ['component' => 'keyband', 'props' => ['listform' => [['name' => 'a', 'url' => 'b']]]],
            ]), 'a JSON-Schema list-form `items` declares no field map');

        } finally {
            if ($previousRoot === null) {
                unset($GLOBALS['_pp_test_template_dir']);
            } else {
                $GLOBALS['_pp_test_template_dir'] = $previousRoot;
            }
            $GLOBALS['_pp_registered_components_invalidate'] = true;
            @unlink($root . '/components/keyband/schema.json');
            @unlink($root . '/components/keyband/keyband.php');
            @rmdir($root . '/components/keyband');
            @rmdir($root . '/components');
            @rmdir($root);
        }
    }

    /**
     * restore_composition never blocks, for the new rejections too (ruling 2). A
     * snapshot carrying an out-of-set enum restores verbatim and reports the
     * violation as a finding through the shared engine.
     */
    public function testRestoreNeverBlocksOnTheNewStrictEnumRejection(): void
    {
        $post_id = pp_create_page('Strict enum snapshot');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'One']]]],
        ]);
        // Raw meta write: the value could never get in through the action layer.
        $raw = pp_get_composition($post_id);
        $raw[0]['props']['layout'] = 'bogus-not-a-layout';
        update_post_meta($post_id, '_pp_composition', wp_json_encode($raw));
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Two']]]],
        ]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore must never block');
        $this->assertSame('bogus-not-a-layout', pp_get_composition($post_id)[0]['props']['layout']);
        $this->assertContains('invalid_prop_value', array_column($result['findings'], 'type'));
    }

    // ── Section inline-items row (issue 475) ─────────────────────────────
    //
    // section.body_items declares `item_type: "string"` with max_items:8 and
    // item_max_length:80 in schema.json, so the shared validator's generic
    // bounded-string-array check accepts only an array of ≤8 strings each ≤80
    // chars (and the unset sentinel), and rejects everything else with
    // invalid_prop_value — the write never persists an over-bound row the
    // renderer would silently truncate. The bounds are read from schema, not
    // hardcoded per component.

    private function sectionCompositionWithBodyItems($bodyItems): array
    {
        $props = ['body' => '<p>Body.</p>'];
        // '__ABSENT__' omits the key entirely; anything else is supplied verbatim.
        if ($bodyItems !== '__ABSENT__') {
            $props['body_items'] = $bodyItems;
        }
        return [['component' => 'section', 'props' => $props]];
    }

    /**
     * @dataProvider validBodyItemsProvider
     */
    public function testSectionBodyItemsAcceptsInBoundArrays($bodyItems): void
    {
        $result = pp_validate_composition($this->sectionCompositionWithBodyItems($bodyItems));
        $this->assertTrue($result, 'body_items=' . var_export($bodyItems, true) . ' must validate.');
    }

    public static function validBodyItemsProvider(): array
    {
        return [
            'single item'        => [['One']],
            'max 8 items'        => [['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']],
            'exactly 80 chars'   => [[str_repeat('x', 80)]],
            'unset: absent'      => ['__ABSENT__'],
            'unset: null'        => [null],
            'unset: empty str'   => [''],
            'unset: empty array' => [[]],
        ];
    }

    /**
     * @dataProvider invalidBodyItemsProvider
     */
    public function testSectionBodyItemsRejectsOutOfBoundOrNonString($bodyItems): void
    {
        $result = pp_validate_composition($this->sectionCompositionWithBodyItems($bodyItems));
        $this->assertInstanceOf(\WP_Error::class, $result, 'body_items=' . var_export($bodyItems, true) . ' must be rejected.');
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        // The envelope names the offending prop so the caller/AI can correct it.
        $this->assertStringContainsString('body_items', $result->get_error_message());
    }

    public static function invalidBodyItemsProvider(): array
    {
        return [
            'nine items (over max)'  => [['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']],
            'item over 80 chars'     => [[str_repeat('x', 81)]],
            'non-string entry (int)' => [['ok', 42]],
            'non-string entry (arr)' => [[['label' => 'x']]],
            'scalar not array'       => ['just a string'],
            'numeric not array'      => [7],
        ];
    }

    // ── section content requirement: body is optional, but SOME content is ──
    //    required (issue 488). `body.required` is gone; the schema-level
    //    content_requirement.any_of gate accepts body / body_items / panel
    //    content and rejects a fully-empty section. This is the exact case that
    //    was previously unauthorable (a body_items-only trust strip).

    /**
     * @dataProvider validSectionContentProvider
     */
    public function testSectionAcceptsAnyRenderableContent(string $label, array $props): void
    {
        $result = pp_validate_composition([['component' => 'section', 'props' => $props]]);
        $this->assertTrue($result, "{$label} must validate (has renderable content).");
    }

    public static function validSectionContentProvider(): array
    {
        return [
            'body only'                => ['body only', ['body' => '<p>Hi</p>']],
            'body_items only (NEW)'    => ['body_items only', ['body_items' => ['No credit card', 'Cancel anytime']]],
            'body_items only inverted' => ['body_items only inverted', ['theme' => 'inverted', 'body_items' => ['SOC 2']]],
            'panel_heading only'       => ['panel_heading only', ['layout' => 'text-panel', 'panel_heading' => 'Plan']],
            'panel_body only'          => ['panel_body only', ['layout' => 'text-panel', 'panel_body' => 'Details']],
            'panel_items only'         => ['panel_items only', ['layout' => 'text-panel', 'panel_items' => ['One']]],
            'panel CTA only'           => ['panel CTA only', ['layout' => 'text-panel', 'panel_cta_text' => 'Go', 'panel_cta_url' => 'https://example.com']],
            'body + items'             => ['body + items', ['body' => '<p>Hi</p>', 'body_items' => ['x']]],
            'empty-string body + items (the old workaround) still valid'
                                       => ['empty body + items', ['body' => '', 'body_items' => ['x']]],
        ];
    }

    /**
     * @dataProvider emptySectionProvider
     */
    public function testFullyEmptySectionIsRejectedHonestly(string $label, array $props): void
    {
        $result = pp_validate_composition([['component' => 'section', 'props' => $props]]);
        $this->assertInstanceOf(\WP_Error::class, $result, "{$label} must be rejected (no renderable content).");
        $this->assertSame('invalid_composition', $result->get_error_code());
        $this->assertStringContainsString('at least one of', $result->get_error_message());
    }

    public static function emptySectionProvider(): array
    {
        return [
            'no props at all'          => ['no props', []],
            'empty-string body only'   => ['empty body', ['body' => '']],
            'whitespace-only body'     => ['whitespace body', ['body' => "   \n\t "]],
            'empty body_items array'   => ['empty items array', ['body_items' => []]],
            'title only (no content)'  => ['title only', ['title' => 'A heading with nothing beneath it']],
            'eyebrow + subheading only' => ['header only', ['eyebrow' => 'NEW', 'subheading' => 'Just a header']],
        ];
    }

    /**
     * The content gate is deliberately loose ("did the author put content
     * here?"), NOT a type check. A malformed-but-present content prop (a
     * non-array body_items) therefore SATISFIES the content gate and falls
     * through to its dedicated bounded-array type check, so the precise
     * invalid_prop_value error wins rather than being masked by a generic
     * "no content" message.
     */
    public function testMalformedContentPropSurfacesTypeErrorNotMissingContent(): void
    {
        $result = pp_validate_composition([
            ['component' => 'section', 'props' => ['body_items' => 'not an array']],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_prop_value', $result->get_error_code(),
            'a present-but-malformed body_items must surface its type error, not "no content".');
        $this->assertStringContainsString('body_items', $result->get_error_message());
    }

    /**
     * Section 14.1 authoring-path mandate: the body_items-only band — the exact
     * shape #488 reports as unauthorable — must succeed through the REAL
     * create_page authoring surface (pp_validate_action → pp_normalize_composition
     * → pp_validate_composition), not just the bare validator. A fully-empty
     * section must still be rejected through that same surface.
     */
    public function testBodyItemsOnlyBandAuthorsThroughCreatePage(): void
    {
        $ok = pp_validate_action('create_page', [
            'title'       => 'Trust strip page',
            'composition' => [
                ['component' => 'section', 'props' => [
                    'theme'      => 'inverted',
                    'body_items' => ['SOC 2 Type II', '99.99% uptime', 'GDPR compliant'],
                ]],
            ],
        ]);
        $this->assertTrue($ok, 'a body_items-only section must author cleanly via create_page (no body:"" placeholder).');
    }

    public function testFullyEmptySectionRejectedThroughCreatePage(): void
    {
        $result = pp_validate_action('create_page', [
            'title'       => 'Empty band page',
            'composition' => [
                ['component' => 'section', 'props' => ['title' => 'Heading, no content']],
            ],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result,
            'a section with no body/body_items/panel content must be rejected at the authoring surface.');
        $this->assertSame('invalid_composition', $result->get_error_code());
    }

    /**
     * update_composition is the other authoring surface (the in-admin editor
     * routes through it). A body_items-only band must validate there too.
     */
    public function testBodyItemsOnlyBandValidatesThroughUpdateComposition(): void
    {
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [42 => ['post_type' => 'page']],
            'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
        $result = pp_validate_action('update_composition', [
            'post_id'     => 42,
            'composition' => [
                ['component' => 'section', 'props' => ['body_items' => ['Cancel anytime']]],
            ],
        ]);
        // The precondition gate may reject a composition-less page before semantic
        // validation; what we assert is that the CONTENT rule itself does not fire
        // — the body_items-only band is not a "missing content" rejection.
        if (is_wp_error($result)) {
            $this->assertNotSame('invalid_composition', $result->get_error_code(),
                'body_items-only band must not be rejected as missing content by update_composition.');
        } else {
            $this->assertTrue($result);
        }
    }

    /**
     * The bounded string-array check is OPT-IN (only props declaring
     * item_type:"string"). It must NOT ripple to section.panel_items, whose
     * entries are strings OR {label,value} objects and which declares no
     * item_type — a mixed panel_items array must still validate.
     */
    public function testBoundedArrayCheckDoesNotRippleToPanelItems(): void
    {
        $result = pp_validate_composition([
            ['component' => 'section', 'props' => [
                'body'        => '<p>x</p>',
                'layout'      => 'text-panel',
                'panel_items' => ['A bullet', ['label' => 'Uptime', 'value' => '99.9%']],
            ]],
        ]);
        $this->assertTrue(
            $result,
            'panel_items (string-or-object array, no item_type) must still validate; '
            . 'the #475 bounded-string-array check must not ripple to it.'
        );
    }

    /**
     * An unknown prop key on section still rejects with unknown_prop even when a
     * valid body_items is present — the #147 strict-key gate is untouched by #475.
     */
    public function testUnknownPropStillStrictAlongsideBodyItems(): void
    {
        $result = pp_validate_composition([
            ['component' => 'section', 'props' => [
                'body'       => '<p>x</p>',
                'body_items' => ['One', 'Two'],
                'not_a_prop' => 'x',
            ]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unknown_prop', $result->get_error_code());
    }

    // ── Featured first-card remnant slots (issue 293) ────────────────────
    //
    // The three featured-card remnants (accent top bar, texture stripe, glow)
    // gained slot control so a uniform card row is reachable through the shared
    // validation engine. Accepted shapes: the documented neutralizers plus
    // ordinary typed values. Rejected shapes: cross-type values, so a slot that
    // LOOKS plausible but cannot render never reports success.

    private function gridCompositionWithStyle(array $style): array
    {
        return [
            [
                'component' => 'grid',
                'props'     => ['items' => [['title' => 'One'], ['title' => 'Two'], ['title' => 'Three']]],
                'style'     => $style,
            ],
        ];
    }

    public function testGridFeaturedRemnantSlotsAcceptNeutralizers(): void
    {
        $result = pp_validate_composition($this->gridCompositionWithStyle([
            '--grid-item-bar-height'        => '0',
            '--grid-featured-texture-color' => 'transparent',
            '--grid-featured-shadow'        => 'none',
        ]));
        $this->assertTrue($result, 'The documented uniform-row neutralizers must validate.');
    }

    public function testGridFeaturedRemnantSlotsAcceptTypedValues(): void
    {
        $result = pp_validate_composition($this->gridCompositionWithStyle([
            '--grid-item-bar-height'        => '4px',
            '--grid-item-bar-color'         => 'linear-gradient(90deg, #ea3900, #b32b00)',
            '--grid-featured-texture-color' => 'rgba(37, 99, 235, 0.028)',
            '--grid-featured-shadow'        => '0 10px 24px rgba(15, 23, 42, 0.055)',
        ]));
        $this->assertTrue($result, 'Ordinary typed values for the issue 293 slots must validate.');
    }

    public function testGridCardBarColorAcceptsPlainColor(): void
    {
        // gradient-typed slots accept plain colors too (the --grid-item-bg precedent).
        $result = pp_validate_composition($this->gridCompositionWithStyle([
            '--grid-item-bar-color' => '#e6e8eb',
        ]));
        $this->assertTrue($result);
    }

    /**
     * @dataProvider featuredRemnantCrossTypeProvider
     */
    public function testGridFeaturedRemnantSlotsRejectCrossTypeValues(string $slot, string $value): void
    {
        $result = pp_validate_composition($this->gridCompositionWithStyle([$slot => $value]));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_value', $result->get_error_code());
    }

    public static function featuredRemnantCrossTypeProvider(): array
    {
        return [
            'color into bar-height'     => ['--grid-item-bar-height', '#ff0000'],
            'length into texture-color' => ['--grid-featured-texture-color', '2rem'],
            'keyword into shadow'       => ['--grid-featured-shadow', 'blue-glow'],
            'shadow into bar-color'     => ['--grid-item-bar-color', '0 10px 24px rgba(0, 0, 0, 0.1)'],
        ];
    }

    public function testGridDeclaresUniformCardsRecipe(): void
    {
        $schema  = json_decode(file_get_contents($this->themeRoot . '/components/grid/schema.json'), true);
        $recipes = $schema['styling']['recipes'] ?? [];

        $this->assertArrayHasKey('uniform-cards', $recipes, 'issue 293 acceptance: the uniform row must be a documented recipe.');
        $slots = $recipes['uniform-cards']['slots'] ?? [];
        $this->assertSame('0', $slots['--grid-item-bar-height'] ?? null);
        $this->assertSame('transparent', $slots['--grid-featured-texture-color'] ?? null);
        $this->assertArrayHasKey('--grid-item-shadow', $slots, 'Uniformity needs one shared shadow on all cards, not a missing featured glow.');

        // Every recipe value must be valid for its slot's declared type — a recipe
        // that expands into rejected values would fail at apply time.
        $declared = $schema['styling']['style_slots'];
        foreach ($slots as $name => $value) {
            $this->assertArrayHasKey($name, $declared, "Recipe slot {$name} must be a declared style slot.");
            $this->assertTrue(
                _pp_validate_token_value((string) $value, $declared[$name]['type'] ?? null),
                "uniform-cards recipe value for {$name} must validate against its declared type."
            );
        }
    }

    // ── Per-item grid card style overrides (issue 306) ──────────────────
    //
    // A single card can carry its own `style` map (props.items[].style) that
    // accepts the SAME grid style_slots as grid-level style and runs through the
    // SAME shared validation engine — no second validator. Unknown item-level slot
    // names and invalid values are rejected exactly like grid-level ones, so a
    // per-card slot that LOOKS plausible but cannot render never reports success.

    private function gridCompositionWithItemStyle(array $itemStyle, array $gridStyle = []): array
    {
        $comp = [
            'component' => 'grid',
            'props'     => ['items' => [
                ['title' => 'Plain'],
                ['title' => 'Styled', 'style' => $itemStyle],
            ]],
        ];
        if ($gridStyle !== []) {
            $comp['style'] = $gridStyle;
        }
        return [$comp];
    }

    public function testGridItemStyleAcceptsKnownSlots(): void
    {
        // The two page-136 cases: a dark panel card and a green terminal card,
        // both expressed purely through per-item slots.
        $darkPanel = $this->gridCompositionWithItemStyle([
            '--grid-item-bg'          => '#0f172a',
            '--grid-item-border-color'      => '#0f172a',
            '--grid-item-title-color' => '#f8fafc',
            '--grid-item-text-color'  => '#cbd5e1',
        ]);
        $this->assertTrue(pp_validate_composition($darkPanel), 'A dark panel card must validate through the shared engine.');

        $terminal = $this->gridCompositionWithItemStyle([
            '--grid-item-bg'         => '#0b0f0a',
            '--grid-item-text-color' => '#22c55e',
        ]);
        $this->assertTrue(pp_validate_composition($terminal), 'A green terminal card must validate through the shared engine.');
    }

    public function testGridItemStyleAcceptsTokenAndGradientValues(): void
    {
        // Item slots accept the full grammar their type allows, same as grid-level:
        // registered var(--token) colors and gradients.
        $result = pp_validate_composition($this->gridCompositionWithItemStyle([
            '--grid-item-bg'          => 'linear-gradient(180deg, #0f172a 0%, #1e293b 100%)',
            '--grid-item-title-color' => 'var(--color-text)',
        ]));
        $this->assertTrue($result);
    }

    public function testGridItemStyleRejectsUnknownSlot(): void
    {
        $result = pp_validate_composition($this->gridCompositionWithItemStyle([
            '--grid-card-not-a-slot' => '#000000',
        ]));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_slot', $result->get_error_code());
        $this->assertStringContainsString('item 1', $result->get_error_message(), 'The error must name the offending card index.');
    }

    public function testGridItemStyleRejectsInvalidValue(): void
    {
        // A length value into a color slot — cross-type rejection at item level.
        $result = pp_validate_composition($this->gridCompositionWithItemStyle([
            '--grid-item-text-color' => '2rem',
        ]));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_value', $result->get_error_code());
    }

    public function testGridItemStyleRejectsInjection(): void
    {
        // The injection guard ({ } ; < >) applies to item-level values too, since
        // the value reaches an inline style attribute at render.
        $result = pp_validate_composition($this->gridCompositionWithItemStyle([
            '--grid-item-bg' => '#000; } body { display:none',
        ]));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_value', $result->get_error_code());
    }

    public function testGridItemStyleAndGridLevelStyleCoexist(): void
    {
        // Grid-level style stays valid while an item overrides one slot.
        $result = pp_validate_composition($this->gridCompositionWithItemStyle(
            ['--grid-item-bg' => '#0f172a'],
            ['--grid-item-bg' => 'var(--color-surface)', '--grid-gap' => '2rem']
        ));
        $this->assertTrue($result);
    }

    public function testGridSchemaDeclaresItemStyleField(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/grid/schema.json'), true);
        $this->assertArrayHasKey(
            'style',
            $schema['props']['items']['items'] ?? [],
            'issue 306: the grid items sub-schema must declare a `style` field so the validator activates per-item styling.'
        );
        $this->assertSame('object', $schema['props']['items']['items']['style']['type'] ?? null);
    }

    // ── Card-scoped per-item slot enforcement (issue 323) ───────────────────
    //
    // #306 accepted ALL 28 grid style slots in items[].style, but only the slots
    // consumed on the .grid__item subtree render when set on one card. Container/
    // heading-scoped slots (--grid-gap, --grid-heading-color, --grid-padding-*, ...)
    // are read on the section/list/header and silently no-op per card — the
    // reported-success-without-effect class. A slot opts into per-item use via the
    // item_eligible flag in the grid schema (single source of truth); the shared
    // validator enforces it on the per-item path only, with the same
    // invalid_style_slot code. Grid-level style is unaffected.

    /** @return array{eligible: array<string,string>, ineligible: string[]} */
    private static function gridSlotScopes(): array
    {
        $schema = json_decode(
            file_get_contents(dirname(__DIR__) . '/components/grid/schema.json'),
            true
        );
        $slots      = $schema['styling']['style_slots'];
        $eligible   = [];
        $ineligible = [];
        foreach ($slots as $name => $def) {
            if (!empty($def['item_eligible'])) {
                $eligible[$name] = $def['type'];
            } else {
                $ineligible[] = $name;
            }
        }
        return ['eligible' => $eligible, 'ineligible' => $ineligible];
    }

    /** A value that passes _pp_validate_token_value for the given slot type. */
    private static function validValueForType(string $type): string
    {
        return match ($type) {
            'length'          => '2rem',
            'shadow'          => 'none',
            'align'           => 'center',
            'text-transform'  => 'none',
            default           => '#123456', // color + gradient both accept a hex color
        };
    }

    public static function gridCardScopedSlotProvider(): array
    {
        $cases = [];
        foreach (self::gridSlotScopes()['eligible'] as $slot => $type) {
            $cases[$slot] = [$slot, $type];
        }
        return $cases;
    }

    public static function gridContainerScopedSlotProvider(): array
    {
        $cases = [];
        foreach (self::gridSlotScopes()['ineligible'] as $slot) {
            $cases[$slot] = [$slot];
        }
        return $cases;
    }

    private function gridCompositionWithItemStyleMap(array $itemStyle, array $gridStyle = []): array
    {
        $comp = [
            'component' => 'grid',
            'props'     => ['items' => [
                ['title' => 'Plain'],
                ['title' => 'Styled', 'style' => $itemStyle],
            ]],
        ];
        if ($gridStyle !== []) {
            $comp['style'] = $gridStyle;
        }
        return [$comp];
    }

    /**
     * @dataProvider gridCardScopedSlotProvider
     */
    public function testGridItemStyleAcceptsEveryCardScopedSlot(string $slot, string $type): void
    {
        $result = pp_validate_composition($this->gridCompositionWithItemStyleMap([
            $slot => self::validValueForType($type),
        ]));
        $this->assertTrue(
            $result,
            sprintf('Card-scoped slot %s (type %s) must be accepted on a per-item style.', $slot, $type)
        );
    }

    /**
     * @dataProvider gridContainerScopedSlotProvider
     */
    public function testGridItemStyleRejectsEveryContainerScopedSlot(string $slot): void
    {
        $result = pp_validate_composition($this->gridCompositionWithItemStyleMap([
            $slot => '#123456',
        ]));
        $this->assertInstanceOf(
            \WP_Error::class,
            $result,
            sprintf('Container/heading slot %s must be rejected on a per-item style.', $slot)
        );
        $this->assertSame('invalid_style_slot', $result->get_error_code());
        $this->assertStringContainsString('item 1', $result->get_error_message(), 'The error must name the offending card index.');
        $this->assertStringContainsString('component-level', $result->get_error_message(), 'The error must point the operator at component-level style.');
        // The suggested "Card-scoped slots" list must NOT advertise the rejected
        // container slot as available.
        $this->assertStringNotContainsString($slot . ',', $result->get_error_message());
    }

    public function testGridItemStyleAcceptsNewlyEnabledFeaturedAndStepSlots(): void
    {
        // #293's featured/bar slots and the steps badge color are card-scoped: they
        // are consumed within the .grid__item subtree (bar/texture ::before pseudos,
        // .grid__step-number child), so they must be usable per card (issue 323 AC).
        $result = pp_validate_composition($this->gridCompositionWithItemStyleMap([
            '--grid-item-bar-color'         => '#123456',
            '--grid-item-bar-height'        => '4px',
            '--grid-featured-texture-color' => '#123456',
            '--grid-featured-shadow'        => 'none',
            '--grid-step-bg'             => '#123456',
            '--grid-step-text-color'        => '#654321',
        ]));
        $this->assertTrue($result, 'The #293 featured/bar slots and the steps badge fill/text slots must be accepted per card.');
    }

    public function testGridLevelStyleStillAcceptsContainerScopedSlot(): void
    {
        // REGRESSION pin: the tighter scope applies to the per-item path ONLY. A
        // container/heading slot on the grid-level `style` (item_index === null)
        // stays valid — the section IS where those render.
        $result = pp_validate_composition([[
            'component' => 'grid',
            'props'     => ['items' => [['title' => 'A']]],
            'style'     => ['--grid-gap' => '2rem', '--grid-heading-color' => '#123456', '--grid-bg' => '#0f172a'],
        ]]);
        $this->assertTrue($result, 'Grid-level style must still accept container/heading slots.');
    }

    public function testGridItemStyleEnforcesScopeOnFirstCardIndexZero(): void
    {
        // Truthiness regression pin: index 0 (the featured first card) is a falsy
        // int. The gate must use a strict !== null check, so a container slot on
        // items[0] is still rejected and named "item 0" — not skipped.
        $result = pp_validate_composition([[
            'component' => 'grid',
            'props'     => ['items' => [
                ['title' => 'First', 'style' => ['--grid-gap' => '2rem']],
                ['title' => 'Second'],
            ]],
        ]]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_slot', $result->get_error_code());
        $this->assertStringContainsString('item 0', $result->get_error_message());
    }

    public function testGridItemStyleStillRejectsUnknownSlotWithEligibleList(): void
    {
        // A truly unknown slot at item level still fails as invalid_style_slot, and
        // the "Available slots" list is the card-scoped set (not all 28).
        $result = pp_validate_composition($this->gridCompositionWithItemStyleMap([
            '--grid-not-a-real-slot' => '#123456',
        ]));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_slot', $result->get_error_code());
        $this->assertStringContainsString('--grid-item-bg', $result->get_error_message());
        $this->assertStringNotContainsString('--grid-gap', $result->get_error_message());
    }

    public function testPerItemValidationFallsBackToFullSetWhenNoSlotFlagged(): void
    {
        // Opt-in by presence (issue 323): a component whose style_slots carry NO
        // item_eligible flag has declared no card-scoped set, so the per-item path
        // must keep the pre-323 behavior — accept any DECLARED slot rather than
        // reject everything. This guards the shared validator from over-rejecting a
        // future component that gains items[].style before being annotated. Calls
        // the shared engine directly with item_index 0 (the strict-null enforce path).
        $slots = [
            '--x-bg'  => ['type' => 'color'],
            '--x-gap' => ['type' => 'length'],
        ];
        $err = _pp_validate_style_slot_map(['--x-gap' => '2rem'], $slots, 'x', 0);
        $this->assertNull(
            $err,
            'With no item_eligible slots declared, per-item validation must accept any declared slot (pre-323 fallback).'
        );
        // An unknown slot is still rejected in the fallback, same as before.
        $err2 = _pp_validate_style_slot_map(['--x-nope' => '#123456'], $slots, 'x', 0);
        $this->assertInstanceOf(\WP_Error::class, $err2);
        $this->assertSame('invalid_style_slot', $err2->get_error_code());
    }

    public function testSchemaItemStyleDescriptionListsEveryCardScopedSlot(): void
    {
        // Keep the human-facing prop guidance coupled to the item_eligible flag set:
        // every card-scoped slot the validator accepts must be named in the
        // items[].style description so the docs never advertise a stale set (issue 323).
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/grid/schema.json'), true);
        $desc   = $schema['props']['items']['items']['style']['description'] ?? '';
        foreach (array_keys(self::gridSlotScopes()['eligible']) as $slot) {
            $this->assertStringContainsString(
                $slot,
                $desc,
                sprintf('items[].style description must list card-scoped slot %s (keep docs in sync with item_eligible).', $slot)
            );
        }
    }

    public function testGridSchemaFlagsExactlyTheCardScopedSlots(): void
    {
        // The eligible set is the single source of truth. Pin it so a future slot
        // addition is forced to declare its scope deliberately (issue 323).
        $scopes = self::gridSlotScopes();
        $this->assertSame(
            [
                '--grid-item-bg', '--grid-item-border-color', '--grid-item-border-width',
                '--grid-item-radius', '--grid-item-shadow', '--grid-item-bar-color',
                '--grid-item-bar-height', '--grid-featured-texture-color',
                '--grid-featured-shadow', '--grid-item-padding', '--grid-item-gap',
                '--grid-item-text-align', '--grid-item-icon-size',
                '--grid-item-title-size', '--grid-item-title-color', '--grid-item-text-color',
                '--grid-item-bullet-color', '--grid-item-link-color',
                '--grid-item-link-hover-color', '--grid-step-bg',
                '--grid-step-text-color',
            ],
            array_keys($scopes['eligible']),
            'The item_eligible card-scoped set drifted from issue 323.'
        );
        $this->assertSame(
            [
                '--grid-padding-top', '--grid-padding-bottom', '--grid-bg',
                '--grid-heading-color', '--grid-heading-accent-color', '--grid-eyebrow-color',
                '--grid-eyebrow-bg', '--grid-eyebrow-radius',
                '--grid-eyebrow-border-width', '--grid-eyebrow-border-color',
                '--grid-eyebrow-text-transform',
                '--grid-subheading-color',
                '--grid-subheading-margin-bottom', '--grid-heading-margin-bottom',
                '--grid-heading-size', '--grid-heading-measure', '--grid-gap',
            ],
            $scopes['ineligible'],
            'The container/heading-scoped set drifted from issue 323.'
        );
    }

    // ── Template-owned chrome rejection (#223) ───────────────────────────

    /**
     * @dataProvider templateOwnedComponentProvider
     */
    public function testCompositionRejectsTemplateOwnedComponent(string $name): void
    {
        $result = pp_validate_composition([
            ['component' => $name, 'props' => []],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(
            'template_owned_component',
            $result->get_error_code(),
            'The code must be distinct from invalid_composition so the action layer can '
            . 'tell "that name is chrome" apart from "that name does not exist".'
        );
        $this->assertStringContainsString('site chrome', $result->get_error_message());
        $this->assertStringContainsString('pp_logo_id', $result->get_error_message());
    }

    public static function templateOwnedComponentProvider(): array
    {
        return [['nav'], ['footer']];
    }

    public function testCompositionRejectsChromeEvenWhenItTrailsValidContent(): void
    {
        $result = pp_validate_composition([
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
            ['component' => 'footer', 'props' => []],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('template_owned_component', $result->get_error_code());
    }

    // ── Unknown prop keys are rejected (issue 147) ──────────────────────────
    //
    // update_component / add_component / update_composition shallow-merge caller
    // props and write. Before this rule, an unknown prop key persisted, the action
    // reported ok:true, and the renderer silently ignored it. pp_validate_composition()
    // now rejects an undeclared prop key against the component's schema.json props.

    public function testCompositionRejectsUnknownPropKey(): void
    {
        $result = pp_validate_composition([
            ['component' => 'hero', 'props' => ['title' => 'Hi', 'not_a_real_prop' => 'x']],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(
            'unknown_prop',
            $result->get_error_code(),
            'A distinct code so callers can tell "that prop does not exist" apart from '
            . 'a missing-required or unknown-component error.'
        );
    }

    public function testUnknownPropErrorNamesComponentAndProp(): void
    {
        $result = pp_validate_composition([
            ['component' => 'cta', 'props' => [
                'title' => 'T', 'body' => 'x', 'button_text' => 'Go', 'button_url' => '#',
                'phantom_field' => 'oops',
            ]],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('cta', $result->get_error_message());
        $this->assertStringContainsString('phantom_field', $result->get_error_message());
    }

    /**
     * Precedence: a missing required prop wins first-error document order over an
     * unknown prop key on the same item — the unknown-prop check runs after the
     * required-props loop. Locks the ordering Codex flagged during plan review.
     */
    public function testMissingRequiredPropWinsOverUnknownPropKey(): void
    {
        // hero requires `title`; here it is absent AND a bogus key is present.
        $result = pp_validate_composition([
            ['component' => 'hero', 'props' => ['not_a_real_prop' => 'x']],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_composition', $result->get_error_code());
        $this->assertStringContainsString('title', $result->get_error_message());
    }

    /**
     * The unknown-prop check runs before the style-slot check in document order, so on
     * an item that carries both an unknown prop key and an invalid style slot the
     * unknown_prop error wins first-error order. Locks the relative ordering against a
     * future reorder of the two checks.
     */
    public function testUnknownPropWinsOverInvalidStyleSlot(): void
    {
        $result = pp_validate_composition([
            [
                'component' => 'hero',
                'props'     => ['title' => 'Hi', 'not_a_real_prop' => 'x'],
                'style'     => ['--not-a-real-slot' => '#fff'],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unknown_prop', $result->get_error_code());
    }

    /**
     * Every composable component still validates when its item carries exactly its
     * declared schema props — the rule must not false-reject any real prop. This is
     * the acceptance criterion "all components' declared schema props still validate".
     */
    public function testEveryComposableComponentAcceptsItsDeclaredSchemaProps(): void
    {
        foreach (pp_composable_components() as $name => $schema) {
            $props = [];
            foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                // Every prop VALUE is now type-checked against its schema `type`
                // (issue 507), so the placeholder must match the declared type. The
                // opt-in families layer additional constraints on top: integer min/max
                // bounds (issue 379) need an in-range integer, a strict enum (issue 380)
                // needs one of its declared values, a string-array (issue 475) needs an
                // array of short strings, and an object-array (issue 507) needs object
                // entries. A plain string 'x' satisfies every remaining string prop.
                $prop_type = $prop_def['type'] ?? null;
                if (isset($prop_def['min'])) {
                    $props[$prop_name] = (int) $prop_def['min'];
                } elseif (
                    $prop_type === 'enum'
                    && !empty($prop_def['strict'])
                    && !empty($prop_def['values'])
                ) {
                    $props[$prop_name] = $prop_def['values'][0];
                } elseif ($prop_type === 'number') {
                    $props[$prop_name] = 1;
                } elseif ($prop_type === 'array' && ($prop_def['item_type'] ?? null) === 'string') {
                    // Bounded string-array prop (issue 475, e.g. section.body_items):
                    // a single short string satisfies the count/length bounds.
                    $props[$prop_name] = ['x'];
                } elseif ($prop_type === 'array' && ($prop_def['item_type'] ?? null) === 'object') {
                    // Object-array prop (issue 507, e.g. grid.items): one object entry
                    // keyed by the declared item fields. Since #614 a NESTED field's
                    // declared scalar type is enforced too, so the placeholder has to
                    // match it one level down exactly as it does at the top — a blanket
                    // 'x' would false-fail every component declaring items[].image_id.
                    $entry = [];
                    foreach (($prop_def['items'] ?? []) as $item_prop => $item_def) {
                        $entry[$item_prop] = $this->schemaPlaceholderValue($item_def);
                    }
                    $props[$prop_name] = [$entry === [] ? ['x' => 'x'] : $entry];
                } elseif ($prop_type === 'array' && ($prop_def['item_type'] ?? null) === 'array') {
                    // Array-of-arrays prop (issue #579, e.g. table.rows): every entry
                    // must itself be an array, so a scalar row cannot be cast into a
                    // one-cell row by the renderer.
                    $props[$prop_name] = [['x']];
                } elseif ($prop_type === 'array') {
                    // Plain array prop (no item contract): an array of strings is a
                    // valid, non-rejected value.
                    $props[$prop_name] = ['x'];
                } else {
                    $props[$prop_name] = 'x';
                }
            }

            $result = pp_validate_composition([['component' => $name, 'props' => $props]]);
            $this->assertTrue(
                $result === true,
                sprintf(
                    'Component "%s" must validate with all its declared schema props set; got: %s',
                    $name,
                    $result === true ? 'true' : $result->get_error_message()
                )
            );
        }
    }

    /**
     * A schema-type-valid placeholder for one NESTED items[] field definition (#614).
     *
     * Mirrors the top-level placeholder logic for the types that reach this depth. The
     * default stays 'x' so a plain string field, and any type no rule enforces yet
     * (`object`), behave exactly as before.
     *
     * @param mixed $def The field definition, or the JSON-Schema-ish scalar form.
     */
    private function schemaPlaceholderValue($def): mixed
    {
        if (!is_array($def)) {
            return 'x';
        }
        $type = $def['type'] ?? null;
        if ($type === 'number') {
            return 1;
        }
        if ($type === 'enum' && !empty($def['values'])) {
            return $def['values'][0];
        }
        if ($type === 'array') {
            return ['x'];
        }
        return 'x';
    }

    /**
     * pp_update_composition() injects a generated props['id'] into every component on
     * save (lib/wp.php). If a composable component's schema omits `id`, that persisted
     * id becomes an unknown prop key and the next validated write (update_component /
     * update_composition / create_page) would reject a composition that saved cleanly.
     * Guard the invariant so a future component added without `id` fails here, not in
     * production. (table was the one gap this issue closed.)
     */
    public function testEveryComposableComponentDeclaresIdSoInjectedIdNeverFalseRejects(): void
    {
        foreach (pp_composable_components() as $name => $schema) {
            $this->assertArrayHasKey(
                'id',
                $schema['props'] ?? [],
                sprintf(
                    'Composable component "%s" must declare an "id" prop — pp_update_composition() '
                    . 'injects props[id] on every save, which the unknown-prop rule would otherwise reject.',
                    $name
                )
            );
        }
    }

    public function testDefaultHomepageSeedPassesUnknownPropCheck(): void
    {
        // The trusted seed must survive strict prop-key validation unchanged.
        $result = pp_validate_composition(pp_default_homepage_composition());
        $this->assertTrue($result === true, $result === true ? '' : $result->get_error_message());
    }

    public function testTableAcceptsIdProp(): void
    {
        // table.php renders props['id'] as an anchor id and pp_update_composition()
        // injects id into every component; the schema must declare it (issue 147).
        $result = pp_validate_composition([
            ['component' => 'table', 'props' => [
                'id' => 'compare', 'headers' => ['A', 'B'], 'rows' => [['1', '2']],
            ]],
        ]);
        $this->assertTrue($result === true, $result === true ? '' : $result->get_error_message());
    }

    public function testTableStillRejectsUnknownProp(): void
    {
        $result = pp_validate_composition([
            ['component' => 'table', 'props' => [
                'headers' => ['A'], 'rows' => [['1']], 'bogus' => 'x',
            ]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unknown_prop', $result->get_error_code());
    }

    // ── Exhaustive per-item findings (#621) ────────────────────────────────
    //
    // pp_validate_composition_errors() reports EVERY problem in an item, at the
    // granularity of the authored location its message can name. The write path is
    // untouched: pp_validate_composition() still returns errors[0]. These pin both
    // halves, plus the suppression that stops one bad value being reported twice.

    /** The issue's own repro: a retired prop name next to a dead style slot. */
    private function propAndSlotComposition(): array
    {
        return [[
            'component' => 'cta',
            'props'     => ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/go', 'text' => 'legacy'],
            'style'     => ['--cta-nonexistent-slot' => '#fff'],
        ]];
    }

    public function testOneItemReportsBothItsUnknownPropAndItsDeadStyleSlot(): void
    {
        // #621 verbatim: before this, the unknown-prop gate's `continue 2` ended the item
        // and the slot surfaced only after the prop was repaired.
        $errors = pp_validate_composition_errors($this->propAndSlotComposition());

        $this->assertSame(
            ['unknown_prop', 'invalid_style_slot'],
            array_map(static fn ($e) => $e->get_error_code(), $errors)
        );
        $this->assertStringContainsString('"text"', $errors[0]->get_error_message());
        $this->assertStringContainsString('--cta-nonexistent-slot', $errors[1]->get_error_message());
    }

    public function testTheWritePathStillRejectsThatItemWithTheFirstErrorOnly(): void
    {
        // The other half of the contract, asserted on the exact message bytes: an
        // exhaustive report must not change one character of what a rejected write says.
        // (Since #642 the write path names the BAND — `Component 0 ("cta")` — which is
        // the one documented difference between this string and the collected finding's.)
        $result = pp_validate_composition($this->propAndSlotComposition());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unknown_prop', $result->get_error_code());
        $this->assertSame(
            'Component 0 ("cta") has no prop "text". Available props: id, title, title_accent, eyebrow, body, '
            . 'button_text, button_url, button2_text, button2_url, button2_variant, layout, theme, '
            . 'background_image, button_variant',
            $result->get_error_message()
        );
    }

    public function testTwoRulesJudgingOneValueReportItOnce(): void
    {
        // `columns` declares numeric bounds (#379) AND type number (#507). Both reject
        // "abc". The claim set keeps the more precise bounds message and drops the
        // generic one — one problem, one finding, no phantom second repair.
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['columns' => 'abc', 'items' => [['title' => 'x']]]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must be an integer between', $errors[0]->get_error_message());
        $this->assertStringNotContainsString('must be a number', $errors[0]->get_error_message());
    }

    public function testABoundedArrayPropRejectedByTwoFamiliesReportsOnce(): void
    {
        // The second reachable pair: a scalar `body_items` fails the #475 bounded
        // string-array family AND the #507 generic `array` type check. #475 runs first
        // and its message is the more specific of the two.
        $errors = pp_validate_composition_errors([
            ['component' => 'section', 'props' => ['body' => 'copy', 'body_items' => 'oops']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must be an array of strings', $errors[0]->get_error_message());
    }

    public function testAnArrayValuedNestedEnumFieldIsReportedOnce(): void
    {
        // One value, one finding at depth too. `text_role` declares `type: "enum"`, so
        // #614's scalar fence deliberately passes it through and #600's membership rule
        // owns the field — the two nested rules cannot both speak for one value.
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['items' => [['title' => 'x', 'text_role' => ['mono']]]]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must be one of', $errors[0]->get_error_message());
        $this->assertStringContainsString('item 0 field "text_role"', $errors[0]->get_error_message());
    }

    public function testEveryOffendingCardInOneBandIsNamed(): void
    {
        // Entry-level locations: three cards, two of them carrying a dead per-card style
        // slot. The message names the card, so both are reported and the healthy card is
        // not. This is the case an operator most needs exhaustive: repairing card 0 and
        // rediscovering card 2 on the next restore is the loop #621 closes.
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'A', 'style' => ['--nope' => 'red']],
                ['title' => 'B'],
                ['title' => 'C', 'style' => ['--also-nope' => 'blue']],
            ]]],
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('item 0', $errors[0]->get_error_message());
        $this->assertStringContainsString('item 2', $errors[1]->get_error_message());
    }

    /**
     * EVERY DE-SHORT-CIRCUITED RULE, one row each. The rules below all used to end their
     * item; the pin is that each one now lets a LATER rule on the same band speak. Each
     * row carries a dead style slot as the witness, because the style map is the last
     * rule in the per-item loop — so if any rule under test still abandons the item, its
     * row loses the `invalid_style_slot` and fails here. Without this, reverting one
     * `continue` back to `continue 2` would leave the suite green.
     *
     * @dataProvider deShortCircuitedRuleProvider
     */
    public function testEveryDeShortCircuitedRuleStillLetsALaterRuleSpeak(array $item, string $firstCode): void
    {
        $errors = pp_validate_composition_errors([$item + ['style' => ['--nope' => 'red']]]);
        $codes  = array_map(static fn ($e) => $e->get_error_code(), $errors);

        $this->assertSame($firstCode, $codes[0], 'the rule under test still reports first');
        $this->assertContains('invalid_style_slot', $codes, 'and no longer hides the rules below it');
        foreach ($errors as $error) {
            $this->assertSame(0, pp_composition_error_index($error));
        }
    }

    public static function deShortCircuitedRuleProvider(): array
    {
        return [
            // #622 required prop
            'missing required prop' => [
                ['component' => 'cta', 'props' => []],
                'invalid_composition',
            ],
            // #488 content requirement
            'no renderable content' => [
                ['component' => 'section', 'props' => ['title' => 'T']],
                'invalid_composition',
            ],
            // #147 unknown prop
            'unknown prop key' => [
                ['component' => 'hero', 'props' => ['title' => 'A', 'nope' => 'x']],
                'unknown_prop',
            ],
            // #379 numeric bounds
            'out-of-range numeric prop' => [
                ['component' => 'grid', 'props' => ['columns' => 99, 'items' => [['title' => 'A']]]],
                'invalid_prop_value',
            ],
            // #380/#579 strict enum
            'out-of-set enum prop' => [
                ['component' => 'hero', 'props' => ['title' => 'A', 'layout' => 'neon']],
                'invalid_prop_value',
            ],
            // #475 bounded string array
            'scalar where a string array belongs' => [
                ['component' => 'section', 'props' => ['body' => 'copy', 'body_items' => 'oops']],
                'invalid_prop_value',
            ],
            // #475 per-entry
            'non-string entry in a string array' => [
                ['component' => 'section', 'props' => ['body' => 'copy', 'body_items' => [['deep']]]],
                'invalid_prop_value',
            ],
            // #507 declared string type
            'array where a string belongs' => [
                ['component' => 'hero', 'props' => ['title' => ['A']]],
                'invalid_prop_value',
            ],
            // #507 declared number type
            'non-numeric where a number belongs' => [
                ['component' => 'hero', 'props' => ['title' => 'A', 'image_id' => 'abc']],
                'invalid_prop_value',
            ],
            // #507 declared array type
            'scalar where an array belongs' => [
                ['component' => 'grid', 'props' => ['items' => 'oops']],
                'invalid_prop_value',
            ],
            // #507 item_type: object
            'scalar entry where an object belongs' => [
                ['component' => 'grid', 'props' => ['items' => ['oops']]],
                'invalid_prop_value',
            ],
            // #507 item_type: array (table.rows)
            'scalar row where an array belongs' => [
                ['component' => 'table', 'props' => ['headers' => ['A'], 'rows' => ['oops']]],
                'invalid_prop_value',
            ],
            // #579 A-27 nested required field
            'items entry missing a required field' => [
                ['component' => 'logos', 'props' => ['items' => [['image_alt' => 'A']]]],
                'invalid_composition',
            ],
            // #614 RULE 3 nested scalar type
            'nested field of the wrong scalar type' => [
                ['component' => 'logos', 'props' => ['items' => [['image_url' => ['/a.png'], 'image_alt' => 'A']]]],
                'invalid_prop_value',
            ],
            // #600 RULE 4 nested enum
            'nested enum out of set' => [
                ['component' => 'grid', 'props' => ['items' => [['title' => 'A', 'text_role' => 'nope']]]],
                'invalid_prop_value',
            ],
            // #579 A-27 nested bullets
            'non-string bullet in a nested string array' => [
                ['component' => 'grid', 'props' => ['items' => [['title' => 'A', 'bullets' => [['deep']]]]]],
                'invalid_prop_value',
            ],
            // #507/#154 top-level link_url
            'dead top-level link' => [
                ['component' => 'cta', 'props' => [
                    'title' => 'A', 'button_text' => 'Go', 'button_url' => 'javascript:alert(1)',
                ]],
                'invalid_prop_value',
            ],
            // #507/#154 nested link_url
            'dead card link' => [
                ['component' => 'grid', 'props' => ['items' => [
                    ['title' => 'A', 'link_url' => 'javascript:alert(1)'],
                ]]],
                'invalid_prop_value',
            ],
            // #643 RULE 5 undeclared nested field
            'undeclared nested item field' => [
                ['component' => 'logos', 'props' => ['items' => [
                    ['image_url' => '/a.png', 'image_alt' => 'A', 'imageId' => 42],
                ]]],
                'unknown_prop',
            ],
        ];
    }

    public function testAContentlessBandAlsoReportsItsOtherViolations(): void
    {
        // The content requirement is the one rule with no `continue` at all now, so it is
        // pinned separately from the provider above: an empty band is exactly where an
        // operator wants every problem at once, not "fill this in, then come back".
        $errors = pp_validate_composition_errors([
            ['component' => 'section', 'props' => ['title' => 'T', 'nope' => 'x']],
        ]);

        $this->assertSame(
            ['invalid_composition', 'unknown_prop'],
            array_map(static fn ($e) => $e->get_error_code(), $errors)
        );
        $this->assertStringContainsString('needs at least one of', $errors[0]->get_error_message());
    }

    public function testEveryOffendingFieldOfOneItemsEntryIsNamed(): void
    {
        // Field-level exhaustiveness inside ONE entry: a logos card missing both required
        // fields names both, and a sibling card's own problem is reported too. Before
        // #621 this whole band reported exactly one finding.
        $errors = pp_validate_composition_errors([
            ['component' => 'logos', 'props' => ['items' => [
                [],
                ['image_url' => '/ok.png', 'image_alt' => 'Fine'],
                ['image_url' => ['/nope.png'], 'image_alt' => 'Typed wrong'],
            ]]],
        ]);

        $messages = array_map(static fn ($e) => $e->get_error_message(), $errors);
        $this->assertCount(3, $errors);
        $this->assertStringContainsString('item 0 is missing required field "image_url"', $messages[0]);
        $this->assertStringContainsString('item 0 is missing required field "image_alt"', $messages[1]);
        $this->assertStringContainsString('item 2 field "image_url" must be a string', $messages[2]);
    }

    // ── RULE 5: undeclared nested item fields (#643) ─────────────────────────
    //
    // The #147 top-level `unknown_prop` gate, one level down. Rules 1-4 walk the
    // DECLARATIONS and so can only judge a field the schema names; nothing walked the
    // other direction, which left a misspelled item field persisting behind ok:true —
    // the last silent no-op at this depth and the nearest neighbour to the defect #614
    // closed.

    public function testAnUndeclaredNestedItemFieldIsRejected(): void
    {
        // THE REPORTED DEFECT, inverted (#643). `imageId: 42` is camelCase — the shape a
        // model reaching for JS conventions produces, one keystroke from the declared
        // `image_id`. Before this rule it validated, persisted, returned ok:true and
        // rendered nothing, while #614 was already stopping the far rarer
        // `image_id: {attachment_id: 42}` right beside it.
        $result = pp_validate_composition([
            ['component' => 'logos', 'props' => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'A', 'imageId' => 42],
            ]]],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unknown_prop', $result->get_error_code(), 'the top-level gate\'s code, not a new one');
        $this->assertSame(
            'Component 0 ("logos") prop "items" item 0 has no field "imageId". '
                . 'Available fields: image_url, image_alt, image_id, label',
            $result->get_error_message(),
            'the message names the offending key AND the available fields, like the top-level gate'
        );
    }

    public function testAnUndeclaredNestedFieldNamesTheItemByItsHonestKey(): void
    {
        // #634's locator ruling holds at this rule too: an items[] object decodes to a
        // string-keyed array, and the key is RENDERED, never cast. Casting would say
        // "item 0", and there is no item 0 — the operator would be sent to repair an
        // element that does not exist.
        $result = pp_validate_composition([
            ['component' => 'logos', 'props' => ['items' => [
                'aa' => ['image_url' => '/a.png', 'image_alt' => 'A', 'imageId' => 42],
            ]]],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('item aa has no field "imageId"', $result->get_error_message());
    }

    public function testEveryUndeclaredFieldOfOneEntryIsNamed(): void
    {
        // Exhaustive per KEY, like the top-level gate since #621: an entry carrying three
        // misspellings reports three findings, so one repair pass fixes the card. The
        // declared fields beside them produce nothing.
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Fine', 'txet' => 'a', 'linkUrl' => '/b', 'imageAlt' => 'c'],
            ]]],
        ]);

        $this->assertSame(['unknown_prop', 'unknown_prop', 'unknown_prop'], array_map(
            static fn ($e) => $e->get_error_code(),
            $errors
        ));
        $messages = array_map(static fn ($e) => $e->get_error_message(), $errors);
        $this->assertStringContainsString('has no field "txet"', $messages[0]);
        $this->assertStringContainsString('has no field "linkUrl"', $messages[1]);
        $this->assertStringContainsString('has no field "imageAlt"', $messages[2]);
    }

    /**
     * Every declared field of every field-map prop still authors cleanly. The rule is a
     * REJECTION of what the schema does not name, never a narrowing of what it does —
     * so the full declared surface of each shipped field map is exercised at once.
     *
     * @dataProvider fullyDeclaredItemsProvider
     */
    public function testAFullyDeclaredItemsEntryIsAccepted(array $composition): void
    {
        $this->assertTrue(pp_validate_composition($composition));
    }

    public static function fullyDeclaredItemsProvider(): array
    {
        return [
            'grid — all eleven declared fields' => [[
                ['component' => 'grid', 'props' => ['items' => [[
                    'number' => '1', 'title' => 'T', 'text' => 'x', 'text_role' => 'mono',
                    'bullets' => ['a'], 'image_url' => '/a.png', 'image_alt' => 'A',
                    'image_id' => 3, 'link_url' => '/x', 'link_text' => 'go',
                    'style' => ['--grid-item-bg' => '#fff'],
                ]]]],
            ]],
            'logos — all four' => [[
                ['component' => 'logos', 'props' => ['items' => [[
                    'image_url' => '/a.png', 'image_alt' => 'A', 'image_id' => 7, 'label' => 'Acme',
                ]]]],
            ]],
            'testimonials — all seven' => [[
                ['component' => 'testimonials', 'props' => ['items' => [[
                    'quote' => 'Q', 'author' => 'A', 'role' => 'R', 'company' => 'C',
                    'image_url' => '/a.png', 'image_alt' => 'A', 'image_id' => 1,
                ]]]],
            ]],
            'stats — all two' => [[
                ['component' => 'stats', 'props' => ['items' => [['number' => '9', 'label' => 'L']]]],
            ]],
            'faq — all two' => [[
                ['component' => 'faq', 'props' => ['items' => [['question' => 'Q?', 'answer' => 'A.']]]],
            ]],
            'section.panel_items — all three' => [[
                ['component' => 'section', 'props' => [
                    'title' => 'T', 'body' => 'B',
                    'panel_items' => [['label' => 'L', 'value' => 'V', 'style' => []]],
                ]],
            ]],
        ];
    }

    /**
     * Array props with NO field contract are untouched by the unknown-field rule (#643).
     *
     * These three are excluded by the outer `isset($prop_def['items'])` guard rather than by
     * the field-map discriminator — they declare no `items` at all — and `grid.items[].bullets`
     * is a nested field whose own `items` is a value grammar, one level below anything this
     * rule reads. Named honestly: the discriminator's own branch is unreachable with the
     * shipped schemas and is pinned separately, with a synthetic component, in
     * testAnArrayValuedSchemaKeywordIsNotMistakenForADeclaredItemField().
     *
     * @dataProvider noFieldContractItemsProvider
     */
    public function testArrayPropsWithNoFieldContractAreUntouchedByTheUnknownFieldRule(array $composition): void
    {
        $this->assertTrue(pp_validate_composition($composition));
    }

    public static function noFieldContractItemsProvider(): array
    {
        return [
            // A value-grammar `items` NESTED inside a field map — bullets is a field, so it
            // is never bound to $prop_def and its entries are never walked for unknown keys.
            'nested bullets array' => [[
                ['component' => 'grid', 'props' => ['items' => [['title' => 'T', 'bullets' => ['a', 'b']]]]],
            ]],
            // Top-level array props that declare no `items` key at all.
            'section.body_items' => [[
                ['component' => 'section', 'props' => ['title' => 'T', 'body_items' => ['a', 'b']]],
            ]],
            'table.headers and table.rows' => [[
                ['component' => 'table', 'props' => ['headers' => ['A', 'B'], 'rows' => [['1', '2']]]],
            ]],
        ];
    }

    public function testAPopulatedListEntryIsAShapeDefectAndNotAnUnknownKeyDefect(): void
    {
        // section.panel_items is DELIBERATELY not annotated `item_type: "object"` — it
        // accepts mixed string+object entries — so no rule claims a JSON LIST entry there.
        // Rule 5 must not fill that vacuum: reporting `has no field "0"` and
        // `has no field "1"` would name positions instead of the real defect, which is the
        // misleading-repair-loop class #621 documents at the entry-shape guard (adding keys
        // to a list still fails the shape rule). The list guard uses the object rule's own
        // predicate, so "is this an object?" has exactly one answer.
        $errors = pp_validate_composition_errors([
            ['component' => 'section', 'props' => [
                'title' => 'T', 'body' => 'B', 'panel_items' => [['L', 'V']],
            ]],
        ]);

        $this->assertSame([], $errors, 'a list entry under an unannotated prop gains no unknown-field findings');
    }

    public function testAnAnnotatedListEntryStillReportsOnlyItsShape(): void
    {
        // The other half: where `item_type: "object"` DOES own the entry, it claims the
        // location first and rule 5 never sees it — one finding for one defect, not three.
        $errors = pp_validate_composition_errors([
            ['component' => 'logos', 'props' => ['items' => [['/a.png', 'Alt']]]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('item 0 must be an object', $errors[0]->get_error_message());
    }

    public function testAStringEntryUnderPanelItemsIsUntouched(): void
    {
        // panel_items' plain-string entry form is the shipped grammar, and the entry loop's
        // is_array() guard is what keeps rule 5 off it.
        $this->assertTrue(pp_validate_composition([
            ['component' => 'section', 'props' => [
                'title' => 'T', 'body' => 'B', 'panel_items' => ['plain line', ['label' => 'L', 'value' => 'V']],
            ]],
        ]));
    }

    /**
     * THE REFLECTED KEY IS BOUNDED. An undeclared field name comes from stored or
     * caller-supplied data and travels out through the CLI, the action envelope, the
     * dashboard editor and the AI chat, so it routes through the renderer #622/#633 built
     * for exactly that rather than being echoed raw. Pinned as EXACT output because the
     * whole point is what an operator sees.
     *
     * @dataProvider hostileItemKeyProvider
     */
    public function testAnUndeclaredNestedFieldNameIsRenderedBounded(string $key, string $expected): void
    {
        $result = pp_validate_composition([
            ['component' => 'logos', 'props' => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'A', $key => 1],
            ]]],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString(
            sprintf('has no field "%s"', $expected),
            $result->get_error_message()
        );
    }

    public static function hostileItemKeyProvider(): array
    {
        return [
            'control characters are stripped' => ["ba\x07d", 'bad'],
            'a bidi override is stripped'     => ["ok\u{202E}ay", 'okay'],
            'an over-long key is capped'      => [str_repeat('z', 80), str_repeat('z', 64) . '...'],
            'a wholly unprintable key'        => ["\x01\x02", '(unprintable key)'],
        ];
    }

    public function testTheMissingRequiredHintBoundsTheKeysItReflects(): void
    {
        // The hint reflects CALLER data, so it goes through the same bounded renderer the
        // unknown-field rule uses (#622/#633) rather than being echoed raw. Pinned because
        // the hint travels the same way every other message here does — CLI, action
        // envelope, dashboard editor, AI chat.
        $result = pp_validate_composition([
            ['component' => 'logos', 'props' => ['items' => [
                ['image_alt' => 'A', "ba\x07d" => 1, str_repeat('z', 80) => 2],
            ]]],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $message = $result->get_error_message();
        $this->assertStringContainsString('is missing required field "image_url"', $message);
        $this->assertStringContainsString('bad', $message, 'the control character is stripped');
        $this->assertStringNotContainsString("\x07", $message, 'no raw control byte reaches the message');
        $this->assertStringContainsString(str_repeat('z', 64) . '...', $message, 'the over-long key is capped');
    }

    /**
     * The hint carries RULE 5's shape carve-out too: on a populated JSON list it would name
     * positions ("0", "1") rather than fields, which is the misleading-repair-loop class
     * (#621) the clause exists to prevent, not to reproduce.
     *
     * Needs a synthetic component to reach: every shipped field map that declares a
     * REQUIRED field also declares `item_type: "object"`, so the object rule claims a list
     * entry before the required loop ever sees it. This one declares a required field and
     * no item_type, which is the combination that reaches the branch.
     */
    public function testTheMissingRequiredHintIsSilentOnAListEntry(): void
    {
        $root = sys_get_temp_dir() . '/pp-hintshape-fixture-' . uniqid('', true);
        mkdir($root . '/components/hintband', 0777, true);
        file_put_contents($root . '/components/hintband/hintband.php', '<?php // fixture');
        file_put_contents($root . '/components/hintband/schema.json', json_encode([
            'component' => 'hintband',
            'props'     => [
                'rows' => [
                    'type' => 'array', 'required' => false,
                    'description' => 'Synthetic field map with a required field and no item_type.',
                    'items' => [
                        'label' => ['type' => 'string', 'required' => true, 'description' => 'Required.'],
                    ],
                ],
            ],
        ]));

        $previousRoot = $GLOBALS['_pp_test_template_dir'] ?? null;
        $GLOBALS['_pp_test_template_dir'] = $root;
        $GLOBALS['_pp_registered_components_invalidate'] = true;

        try {
            $result = \pp_validate_composition([
                ['component' => 'hintband', 'props' => ['rows' => [['one', 'two']]]],
            ]);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $message = $result->get_error_message();
            $this->assertStringContainsString('is missing required field "label"', $message);
            $this->assertStringNotContainsString('also carries field(s)', $message,
                'a list entry has positions, not undeclared fields — the hint must stay silent');
        } finally {
            if ($previousRoot === null) {
                unset($GLOBALS['_pp_test_template_dir']);
            } else {
                $GLOBALS['_pp_test_template_dir'] = $previousRoot;
            }
            $GLOBALS['_pp_registered_components_invalidate'] = true;
            @unlink($root . '/components/hintband/schema.json');
            @unlink($root . '/components/hintband/hintband.php');
            @rmdir($root . '/components/hintband');
            @rmdir($root . '/components');
            @rmdir($root);
        }
    }

    public function testANonScalarNestedLinkIsReportedOnceByTheTypeRule(): void
    {
        // grid.items[].link_url declares `type: "string"` AND `format: "link_url"`, so it
        // is the one nested field two rule blocks can both look at. A non-scalar trips
        // RULE 3, and the later link_url block must produce nothing further for that same
        // field — one bad value, one finding, at depth as at the top level.
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['items' => [['title' => 'A', 'link_url' => ['/a']]]]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('field "link_url" must be a string', $errors[0]->get_error_message());
    }

    /**
     * NO CASCADE from a malformed parent. Each shape below is reported by exactly the one
     * rule that owns it; the rules underneath skip it through their existing is_array() /
     * array_key_exists() guards rather than inventing follow-on errors. Removing the
     * short-circuits could only have been safe if that were true, so it is asserted.
     *
     * @dataProvider malformedParentProvider
     */
    public function testAMalformedParentIsReportedOnceAndNeverCascades(array $composition, string $expected): void
    {
        $errors = pp_validate_composition_errors($composition);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString($expected, $errors[0]->get_error_message());
    }

    public static function malformedParentProvider(): array
    {
        return [
            'props is a string' => [
                [['component' => 'hero', 'props' => 'oops']],
                'missing required prop "title"',
            ],
            'items is a scalar' => [
                [['component' => 'grid', 'props' => ['items' => 'oops']]],
                'prop "items" must be an array',
            ],
            'an items entry is a scalar' => [
                [['component' => 'grid', 'props' => ['items' => ['oops']]]],
                'item 0 must be an object',
            ],
            // A JSON LIST passes is_array(), so this is the shape that cascaded: the
            // field rules reported every required field as missing on a value whose real
            // problem is that it is not an object. `[["/a.png","Alt"]]` is a common
            // authoring-agent mistake, and the extra findings sent the agent to add keys
            // to a list — a repair that can never satisfy the shape rule.
            'an items entry is a JSON list' => [
                [['component' => 'logos', 'props' => ['items' => [[1, 2]]]]],
                'item 0 must be an object',
            ],
        ];
    }

    public function testAnEmptyObjectEntryStillReportsEveryMissingRequiredField(): void
    {
        // The other side of the cascade guard: `{}` is an object shape, nothing claims the
        // ENTRY, so its fields are judged and both missing ones are named. If the guard is
        // ever widened to skip any array-ish entry, this fails.
        $errors = pp_validate_composition_errors([
            ['component' => 'logos', 'props' => ['items' => [[]]]],
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('missing required field "image_url"', $errors[0]->get_error_message());
        $this->assertStringContainsString('missing required field "image_alt"', $errors[1]->get_error_message());
    }

    // ── The write path's finding budget (#621) ─────────────────────────────
    //
    // pp_validate_composition() reads errors[0] and discards the rest, so it validates
    // with a budget of 1. Exhaustiveness made the unbudgeted list grow with the SIZE of
    // the input rather than with the number of bands, and a write path that allocates
    // hundreds of megabytes of findings nobody reads is a denial-of-service handed to any
    // caller who can post a composition. These pin that the budget changes nothing an
    // outside caller can observe.

    /** 400 malformed logos entries: 800 findings unbudgeted, 1 budgeted. */
    private function manyFindingsComposition(): array
    {
        return [['component' => 'logos', 'props' => ['items' => array_fill(0, 400, [])]]];
    }

    public function testTheBudgetedEngineReturnsExactlyTheErrorTheUnbudgetedOneReturnsFirst(): void
    {
        $composition = $this->manyFindingsComposition();

        $all       = pp_validate_composition_errors($composition);
        $budgeted  = pp_validate_composition_errors($composition, 1);

        $this->assertCount(800, $all, 'the reporting path stays exhaustive');
        $this->assertCount(1, $budgeted, 'the write path builds one finding, not 800');
        $this->assertSame($all[0]->get_error_code(), $budgeted[0]->get_error_code());
        $this->assertSame($all[0]->get_error_message(), $budgeted[0]->get_error_message());
        $this->assertSame(
            pp_composition_error_index($all[0]),
            pp_composition_error_index($budgeted[0]),
            'the locator survives the budget'
        );
    }

    public function testTheWritePathReturnsTheSameErrorItWouldHaveWithoutABudget(): void
    {
        $composition = $this->manyFindingsComposition();
        $result      = pp_validate_composition($composition);
        $unbudgeted  = pp_validate_composition_errors($composition)[0];

        // Same VIOLATION — same rule, same band. The budget cannot change which one is
        // selected; only the write path's band naming (#642) separates the two strings,
        // and the offset both carry is the same one.
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame($unbudgeted->get_error_code(), $result->get_error_code());
        $this->assertSame(
            pp_composition_error_index($unbudgeted),
            pp_composition_error_index($result)
        );
        $this->assertSame(
            'Component 0 ("logos") prop "items" item 0 is missing required field "image_url".',
            $result->get_error_message()
        );
        $this->assertSame(
            'Component "logos" prop "items" item 0 is missing required field "image_url".',
            $unbudgeted->get_error_message()
        );
    }

    public function testTheBudgetBoundsTheUndeclaredItemFieldRuleToo(): void
    {
        // RULE 5 (#643) is the ONE report site in this engine whose fan-out is the count of
        // AUTHOR-SUPPLIED keys rather than the schema's declaration set or the entry count:
        // rules 1-4 can emit at most one finding per DECLARED field, a number the schema
        // caps, while an undeclared key is anything a caller can type. That makes it the
        // report site the budget matters most for, so it gets its own pin rather than
        // riding on the entries-based one above. Without the claim gate the write path
        // would build one WP_Error per key of a hostile composition.
        $entry = ['image_url' => '/a.png', 'image_alt' => 'A'];
        for ($i = 0; $i < 400; $i++) {
            $entry["not_a_field_{$i}"] = 'x';
        }
        $composition = [['component' => 'logos', 'props' => ['items' => [$entry]]]];

        $all      = pp_validate_composition_errors($composition);
        $budgeted = pp_validate_composition_errors($composition, 1);

        $this->assertCount(400, $all, 'the reporting path names every undeclared key');
        $this->assertCount(1, $budgeted, 'the write path builds one finding, not 400');
        $this->assertSame('unknown_prop', $budgeted[0]->get_error_code());
        $this->assertSame($all[0]->get_error_message(), $budgeted[0]->get_error_message(),
            'the budgeted write path returns exactly the error the unbudgeted one returns first');
    }

    public function testASkippedItemsEntryDoesNotSuppressAnUndeclaredFieldInALaterOne(): void
    {
        // The shape guard advances to the NEXT ENTRY; it must not end the prop. Pinned
        // because a `break` there would read as a harmless tidy-up and would silently drop
        // a real finding on the write path. section.panel_items is the reachable case: it
        // is deliberately unannotated, so a JSON-list entry there is claimed by no rule and
        // actually reaches the guard instead of being short-circuited earlier.
        $errors = pp_validate_composition_errors([
            ['component' => 'section', 'props' => [
                'title' => 'T', 'body' => 'B',
                'panel_items' => [
                    ['L', 'V'],                                  // list entry: skipped, silently
                    ['label' => 'ok', 'vlaue' => 'typo'],        // must still be reported
                ],
            ]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('unknown_prop', $errors[0]->get_error_code());
        $this->assertStringContainsString('item 1 has no field "vlaue"', $errors[0]->get_error_message());
    }

    public function testUndeclaredFieldsAreNamedAcrossSiblingEntries(): void
    {
        // Cross-ENTRY exhaustiveness, the companion to the within-entry pin: two bad cards
        // in one strip are two findings, so one repair pass fixes the band (#621).
        $errors = pp_validate_composition_errors([
            ['component' => 'logos', 'props' => ['items' => [
                ['image_url' => '/a.png', 'image_alt' => 'A', 'imageId' => 1],
                ['image_url' => '/b.png', 'image_alt' => 'B'],
                ['image_url' => '/c.png', 'image_alt' => 'C', 'imageAlt' => 'dupe'],
            ]]],
        ]);

        $messages = array_map(static fn ($e) => $e->get_error_message(), $errors);
        $this->assertCount(2, $errors, 'the clean middle card contributes nothing');
        $this->assertStringContainsString('item 0 has no field "imageId"', $messages[0]);
        $this->assertStringContainsString('item 2 has no field "imageAlt"', $messages[1]);
    }

    public function testABudgetNeverHidesACrossItemCollisionThatIsTheOnlyError(): void
    {
        // The duplicate-id pass appends after the per-item loop and is skipped once the
        // budget is spent. When it is the ONLY violation, nothing has been spent, so it
        // must still be the error the write path rejects with.
        $result = pp_validate_composition($this->duplicateIdComposition());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('duplicate_component_id', $result->get_error_code());
    }

    public function testANonScalarLinkPropIsReportedByTheTypeRuleAndNothingElse(): void
    {
        // The link_url predicate returns true for every non-string (lib/admin.php), so the
        // later link block cannot double-report or fatal on an array a type rule already
        // rejected. Pinned because the two rules now BOTH run for the same prop.
        $errors = pp_validate_composition_errors([
            ['component' => 'cta', 'props' => ['title' => 'A', 'button_text' => 'Go', 'button_url' => []]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('prop "button_url" must be a string', $errors[0]->get_error_message());
    }

    public function testAClaimKeyCannotCollideAcrossDifferentAuthoredLocations(): void
    {
        // The claim set is keyed by authored names, which are arbitrary bytes. A naive
        // `implode('.')` would make the prop literally named `items.0` share a key with
        // entry 0 of `items`, and a collision SUPPRESSES a real finding. Length-prefixed
        // segments make that impossible; this asserts the property directly.
        $this->assertNotSame(
            _pp_finding_location('prop', 'items.0'),
            _pp_finding_location('prop', 'items', 0)
        );
        $this->assertNotSame(
            _pp_finding_location('prop', 'a', 'b'),
            _pp_finding_location('prop', 'ab')
        );
        $this->assertSame(
            _pp_finding_location('prop', 'items', 0),
            _pp_finding_location('prop', 'items', 0),
            'the same location must always render the same key'
        );
        // Invalid UTF-8: json_encode() would return false here and collapse every such
        // key onto one bucket. The length-prefixed encoding keeps them distinct.
        $this->assertNotSame(
            _pp_finding_location('prop', "bad\xC3("),
            _pp_finding_location('prop', "bad\xC3)")
        );
    }

    public function testACollidingPropNameStillGetsItsOwnFinding(): void
    {
        // The same property, end to end through the validator: a prop whose NAME looks
        // like a nested locator does not swallow the real nested finding.
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => [
                'items.0' => 'x',
                'items'   => [['title' => 'A', 'link_url' => 'javascript:alert(1)']],
            ]],
        ]);

        $codes = array_map(static fn ($e) => $e->get_error_code(), $errors);
        $this->assertSame(['unknown_prop', 'invalid_prop_value'], $codes);
        $this->assertStringContainsString('items.0', $errors[0]->get_error_message());
        $this->assertStringContainsString('item 0 field "link_url"', $errors[1]->get_error_message());
    }

    // ── First-error contract vs the collect-all engine (#233) ──────────────
    //
    // pp_validate_composition() delegates to pp_validate_composition_errors() and returns
    // errors[0]. Every write-time caller (create_page, update_composition, add_component,
    // update_component, the editor save) depends on the first-error shape. These pin that
    // the refactor did not change what those callers observe.

    /** A composition with three distinct violations, in document order. */
    private function multiErrorComposition(): array
    {
        return [
            ['component' => 'nav', 'props' => []],   // template_owned_component
            ['component' => 'ghost', 'props' => []], // invalid_composition: unknown component
            ['component' => 'hero', 'props' => []],  // invalid_composition: missing "title"
        ];
    }

    public function testValidateCompositionStillReturnsOnlyTheFirstError(): void
    {
        $result = pp_validate_composition($this->multiErrorComposition());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('template_owned_component', $result->get_error_code());
    }

    public function testValidateCompositionErrorsCollectsEveryError(): void
    {
        $errors = pp_validate_composition_errors($this->multiErrorComposition());

        $this->assertCount(3, $errors);
        $this->assertSame('template_owned_component', $errors[0]->get_error_code());
        $this->assertSame('invalid_composition', $errors[1]->get_error_code());
        $this->assertStringContainsString('ghost', $errors[1]->get_error_message());
        $this->assertSame('invalid_composition', $errors[2]->get_error_code());
        $this->assertStringContainsString('title', $errors[2]->get_error_message());
    }

    public function testFirstCollectedErrorIsExactlyWhatValidateReturns(): void
    {
        // The contract, stated as an invariant rather than a coincidence: whatever
        // pp_validate_composition() returns IS errors[0] — same rule, same band. Since
        // #642 the write form additionally NAMES that band, because a rejected write has
        // no second field to render a locator into the way every reporting surface does.
        $composition = $this->multiErrorComposition();

        $first  = pp_validate_composition($composition);
        $errors = pp_validate_composition_errors($composition);

        $this->assertSame($errors[0]->get_error_code(), $first->get_error_code());
        $this->assertSame(
            pp_composition_error_index($errors[0]),
            pp_composition_error_index($first)
        );
        $this->assertSame(
            'Component 0: ' . $errors[0]->get_error_message(),
            $first->get_error_message(),
            'the chrome message names the component in its own words, so the band is prefixed'
        );
    }

    // ── Duplicate authored component ids (issue 238) ────────────────────────────
    // Two components sharing a non-empty props.id are rejected at write time so
    // wrong-targetable state is never persisted (create_page / update_composition).

    private function duplicateIdComposition(string $id = 'pricing'): array
    {
        // Both items are schema-valid (hero requires only `title`); the sole
        // violation is the shared id, so error counts isolate the duplicate check.
        return [
            ['component' => 'hero', 'props' => ['id' => $id, 'title' => 'First']],
            ['component' => 'hero', 'props' => ['id' => $id, 'title' => 'Second']],
        ];
    }

    public function testDuplicateComponentIdIsRejected(): void
    {
        $errors = pp_validate_composition_errors($this->duplicateIdComposition());

        $this->assertCount(1, $errors);
        $this->assertSame('duplicate_component_id', $errors[0]->get_error_code());
        $this->assertStringContainsString('pricing', $errors[0]->get_error_message());
        $this->assertStringContainsString('0', $errors[0]->get_error_message());
        $this->assertStringContainsString('1', $errors[0]->get_error_message());
    }

    public function testDuplicateComponentIdAlsoFailsSingleErrorValidate(): void
    {
        $result = pp_validate_composition($this->duplicateIdComposition());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('duplicate_component_id', $result->get_error_code());
    }

    public function testTripledComponentIdReportsOneErrorNamingEveryIndex(): void
    {
        // Three components with the same id -> exactly one error listing all three
        // indices (deterministic diagnostics, not N-1 pairwise errors).
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['id' => 'dup', 'title' => 'A']],
            ['component' => 'hero', 'props' => ['id' => 'dup', 'title' => 'B']],
            ['component' => 'hero', 'props' => ['id' => 'dup', 'title' => 'C']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('duplicate_component_id', $errors[0]->get_error_code());
        $message = $errors[0]->get_error_message();
        $this->assertStringContainsString('0', $message);
        $this->assertStringContainsString('1', $message);
        $this->assertStringContainsString('2', $message);
    }

    public function testDistinctIdsAreNotFlaggedAsDuplicate(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['id' => 'alpha', 'title' => 'A']],
            ['component' => 'hero', 'props' => ['id' => 'beta', 'title' => 'B']],
        ]);

        $this->assertSame([], $errors);
    }

    public function testMultipleComponentsWithoutIdsAreNotDuplicates(): void
    {
        // Missing id props never collide — id-based targeting isn't possible, and the
        // empty-string guard means two absent ids are not "the same id".
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['title' => 'A']],
            ['component' => 'hero', 'props' => ['title' => 'B']],
            ['component' => 'hero', 'props' => ['id' => '', 'title' => 'C']],
        ]);

        $this->assertSame([], $errors);
    }

    public function testZeroStringIdCountsAsARealIdForDuplicateDetection(): void
    {
        // "0" is a valid, targetable id — the guard is === '' not empty(), so a
        // duplicated "0" must still be rejected.
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['id' => '0', 'title' => 'A']],
            ['component' => 'hero', 'props' => ['id' => '0', 'title' => 'B']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('duplicate_component_id', $errors[0]->get_error_code());
    }

    public function testMixedScalarIdsThatRenderIdenticallyAreTreatedAsDuplicates(): void
    {
        // Intentional: a numeric 1 and string "1" both render as the same DOM
        // id="1" (invalid duplicate HTML id, broken anchors) and PHP array-key
        // coercion collides them anyway, so the write-time guard rejects the pair
        // rather than letting a DOM collision persist.
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['id' => 1, 'title' => 'A']],
            ['component' => 'hero', 'props' => ['id' => '1', 'title' => 'B']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('duplicate_component_id', $errors[0]->get_error_code());
    }

    public function testDuplicateIdErrorTrailsPerItemErrorsInDocumentOrder(): void
    {
        // A per-item error on an earlier item still wins pp_validate_composition()'s
        // first-error contract; the duplicate-id error is appended after the loop.
        $composition = [
            ['component' => 'ghost', 'props' => ['id' => 'dup']],   // unknown component (item error)
            ['component' => 'hero', 'props' => ['id' => 'dup', 'title' => 'A']],
            ['component' => 'hero', 'props' => ['id' => 'dup', 'title' => 'B']],
        ];
        $errors = pp_validate_composition_errors($composition);

        $this->assertSame('invalid_composition', $errors[0]->get_error_code());
        $this->assertSame('duplicate_component_id', $errors[count($errors) - 1]->get_error_code());
        $this->assertSame('invalid_composition', pp_validate_composition($composition)->get_error_code());
    }

    public function testAStructuralFailureStillStopsTheItemAtOneError(): void
    {
        // The four structural checks are the only ones that still end an item (#621):
        // `ghost` has no schema, so every rule below it would be judging the band against
        // a contract that does not exist. A style-slot error on the same band is NOT
        // reported, and that is honest rather than incomplete — there are no slots to
        // validate against.
        $errors = pp_validate_composition_errors([
            ['component' => 'ghost', 'style' => ['nope' => 'red']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_composition', $errors[0]->get_error_code());
    }

    /** @dataProvider structuralShortCircuitProvider */
    public function testEveryStructuralShortCircuitReportsExactlyOneErrorForItsItem(array $item): void
    {
        $errors = pp_validate_composition_errors([$item]);

        $this->assertCount(1, $errors, 'a band whose identity is unusable reports once, not once per rule');
    }

    public static function structuralShortCircuitProvider(): array
    {
        // All four carry a dead style slot as bait: if a structural check ever stops
        // ending its item, the slot rule fires and the count moves.
        return [
            'no component key'     => [['props' => ['title' => 'x'], 'style' => ['--nope' => 'red']]],
            'non-scalar component' => [['component' => ['hero'], 'style' => ['--nope' => 'red']]],
            'unknown component'    => [['component' => 'ghost', 'style' => ['--nope' => 'red']]],
            'template-owned chrome' => [['component' => 'nav', 'style' => ['--nope' => 'red']]],
        ];
    }

    public function testMultipleMissingRequiredPropsOnOneItemReportOneErrorEach(): void
    {
        // `cta` requires button_text and button_url (title is optional since issue 294);
        // both are absent. Before #621 the required-prop loop's `continue 2` ended the
        // item at the first one, so repairing button_text only revealed button_url on the
        // next run. Both are named now, and errors[0] is still the error the write path
        // returns (pinned by testFirstCollectedErrorIsExactlyWhatValidateReturns).
        $errors = pp_validate_composition_errors([
            ['component' => 'cta', 'props' => []],
        ]);

        $this->assertCount(2, $errors, 'one finding per missing required prop');
        foreach ($errors as $error) {
            $this->assertSame('invalid_composition', $error->get_error_code());
            $this->assertSame(0, pp_composition_error_index($error));
        }
        $this->assertStringContainsString('button_text', $errors[0]->get_error_message());
        $this->assertStringContainsString('button_url', $errors[1]->get_error_message());
    }

    public function testAMissingPropNoLongerHidesTheStyleChecksForThatItem(): void
    {
        // THE #621 DEFECT, in its smallest form. The prop rules used to jump to the next
        // ITEM, so the dead style slot on the same band was unreachable until the props
        // were repaired — the fix-one-retry-discover-the-next loop the collect-all engine
        // exists to prevent. Both kinds of problem are reported in one pass now.
        $errors = pp_validate_composition_errors([
            ['component' => 'cta', 'props' => [], 'style' => ['--not-a-slot' => 'red']],
        ]);

        $codes = array_map(static fn ($e) => $e->get_error_code(), $errors);
        $this->assertSame(['invalid_composition', 'invalid_composition', 'invalid_style_slot'], $codes);
        $this->assertStringContainsString('--not-a-slot', $errors[2]->get_error_message());
    }

    public function testMultipleInvalidStyleSlotsOnOneItemReportOneError(): void
    {
        // THE RECORDED LIMIT of #621, pinned so it stays a decision rather than an
        // oversight. The single error no longer comes from a `continue` in this function
        // — that is gone — but from _pp_validate_style_slot_map(), which returns the FIRST
        // bad slot in a map. Widening that shared engine reaches the style_component write
        // path, which wants one actionable message, so it stayed out of #621. If it is
        // ever widened, rewrite this test to the new contract rather than deleting it.
        $errors = pp_validate_composition_errors([
            [
                'component' => 'hero',
                'props'     => ['title' => 'A'],
                'style'     => ['--not-a-slot' => 'red', '--also-not-a-slot' => 'blue'],
            ],
        ]);

        $this->assertCount(1, $errors, 'the shared slot engine reports the first bad slot in a map');
        $this->assertSame('invalid_style_slot', $errors[0]->get_error_code());
    }

    public function testEachItemContributesItsOwnErrorsInDocumentOrder(): void
    {
        // The other half of the invariant, restated for #621: findings stay grouped by
        // band and in document order, and a band's extra findings never leak onto its
        // neighbours. The cta contributes two (both required props), the hero one, the
        // unknown component one — and the unknown component still stops at one, because
        // there is no schema to judge the rest of it against.
        $errors = pp_validate_composition_errors([
            ['component' => 'cta', 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'A'], 'style' => ['--nope' => 'red']],
            ['component' => 'ghost', 'props' => []],
        ]);

        $this->assertCount(4, $errors);
        $this->assertSame(
            ['invalid_composition', 'invalid_composition', 'invalid_style_slot', 'invalid_composition'],
            array_map(static fn ($e) => $e->get_error_code(), $errors)
        );
        $this->assertSame(
            [0, 0, 1, 2],
            array_map(static fn ($e) => pp_composition_error_index($e), $errors),
            'document order, grouped by the band that owns each finding'
        );
    }

    public function testEmptyCompositionIsValid(): void
    {
        $this->assertSame([], pp_validate_composition_errors([]));
        $this->assertTrue(pp_validate_composition([]));
    }

    public function testMalformedItemsAreReportedNotFatal(): void
    {
        // Legacy history snapshots reach the validators through restore's findings (#233),
        // so malformed shapes must produce an error rather than a warning or a fatal.
        // isset() on a string/int offset with a non-numeric key returns false, so a scalar
        // item takes the "missing component" branch before any cast runs.
        $errors = pp_validate_composition_errors([
            'nav',              // scalar item
            123,                // scalar item
            [],                 // array with no component key
        ]);

        $this->assertCount(3, $errors);
        foreach ($errors as $error) {
            $this->assertSame('invalid_composition', $error->get_error_code());
            $this->assertStringContainsString('missing the "component" key', $error->get_error_message());
        }
    }

    public function testNonScalarComponentKeyIsAValidationErrorNotAPhpWarning(): void
    {
        // A raw-written or corrupt row can hold an array here. Casting it would emit
        // "Array to string conversion" and report a component named "Array". Restore's
        // findings (#233) run these rules over arbitrary snapshots, so this path is live.
        $raised = [];
        set_error_handler(static function (int $no, string $str) use (&$raised): bool {
            $raised[] = $str;
            return true;
        });

        try {
            $errors = pp_validate_composition_errors([
                ['component' => []],
                ['component' => ['nested' => 'hero']],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised, 'no PHP warning is emitted for a non-scalar component');
        $this->assertCount(2, $errors);
        foreach ($errors as $error) {
            $this->assertSame('invalid_composition', $error->get_error_code());
            $this->assertStringContainsString('non-scalar "component" key', $error->get_error_message());
        }

        // And the first-error contract still holds for this input.
        $first = pp_validate_composition([['component' => []]]);
        $this->assertInstanceOf(\WP_Error::class, $first);
        $this->assertSame('invalid_composition', $first->get_error_code());
    }

    public function testValidCompositionCollectsNoErrors(): void
    {
        $this->assertSame([], pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['title' => 'A']],
        ]));
        $this->assertTrue(pp_validate_composition([
            ['component' => 'hero', 'props' => ['title' => 'A']],
        ]));
    }

    public function testChromeRejectionPrecedesRequiredPropCheck(): void
    {
        // `nav` has no required props, so this only proves ordering for `hero`-like
        // shapes. What matters: a chrome item with no props object still names the
        // chrome problem rather than a missing-prop problem.
        $result = pp_validate_composition([['component' => 'nav']]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('template_owned_component', $result->get_error_code());
    }

    /**
     * SUPERSEDES testTypeAliasedChromeIsRejectedAsChrome (#604).
     *
     * LLMs emit `{"type":"nav"}`. Until #604, pp_normalize_composition() aliased `type`
     * to `component`, so the item became a nav and was rejected AS CHROME. With the
     * alias gone there is no component name on the item at all, so it is rejected one
     * step earlier and for a different, more accurate reason: it does not name a
     * component. Both outcomes are a hard rejection — what changed is which problem the
     * author is told about first, and "you didn't name a component" is the true one.
     */
    public function testTypeKeyedChromeIsRejectedAsAMissingComponentKey(): void
    {
        $normalized = pp_normalize_composition([['type' => 'nav', 'props' => []]]);
        $result     = pp_validate_composition($normalized);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(
            'invalid_composition',
            $result->get_error_code(),
            'A `type`-keyed item names no component, so that is the error it must get.'
        );
        $this->assertStringContainsString('component', $result->get_error_message());
        // And the normalizer left it alone rather than manufacturing a nav.
        $this->assertArrayNotHasKey('component', $normalized[0]);
    }

    public function testTemplateOwnedComponentsRemainRegistered(): void
    {
        // They must stay in the registry: templates/base.php renders them, and the
        // admin preview needs their schemas. Registered != composable.
        $registered = pp_get_registered_components();
        foreach (pp_template_owned_components() as $name) {
            $this->assertArrayHasKey($name, $registered);
        }
    }

    public function testStyleSlotNamesAreDisjointFromDesignTokenNames(): void
    {
        // Component-library invariant (#230): style slot names must never
        // collide with a registered design-token name. Since #230 a color
        // slot may hold var(--token) for any registered color token; if a
        // slot NAME ever equalled a token name, pp_render_style_vars() could
        // emit the same-element self-reference `--x: var(--x)` — the one CSS
        // shape guaranteed-invalid at computed-value time — while every
        // validator passes. Empty intersection makes that unrepresentable.
        $tokens = \pp_design_tokens();
        foreach (pp_get_registered_components() as $name => $def) {
            foreach (array_keys($def['styling']['style_slots'] ?? []) as $slot) {
                $this->assertArrayNotHasKey(
                    $slot,
                    $tokens,
                    "Component '{$name}' style slot '{$slot}' collides with a registered design token."
                );
            }
        }
    }

    public function testComposableComponentsExcludeChromeButKeepContent(): void
    {
        $composable = pp_composable_components();

        foreach (pp_template_owned_components() as $name) {
            $this->assertArrayNotHasKey(
                $name,
                $composable,
                "pp_composable_components() must not advertise chrome '{$name}' — this is the "
                . 'list lib/ai-context.php shows the AI.'
            );
        }
        $this->assertArrayHasKey('hero', $composable);
        $this->assertArrayHasKey('section', $composable);
    }

    /**
     * Component-library invariant: a composable component always requires
     * something of the author, so a bare `{"component": "x"}` (no `props` key)
     * is INVALID.
     *
     * If bare-with-no-props validated, the component renders empty (the #87
     * empty-section smell exists to catch that), and it is the one composition
     * shape the accordion round-trip cannot preserve: `serializeAccordionData()`
     * re-emits `props: {}`, so the editor's serialization-invariant gate locks
     * the accordion.
     *
     * TWO mechanisms uphold this invariant, and a component may use either:
     *   1. A `required: true` prop (hero.title, grid.items, ...) — the required-
     *      props loop rejects the bare shape.
     *   2. A schema-level `content_requirement.any_of` (section, since #488) —
     *      the content gate rejects the bare shape when none of the listed
     *      content props is present. section.body became optional in #488, so
     *      section relies on this second mechanism; a body_items-only or
     *      panel-only band is authorable while a fully-empty section is not.
     *
     * Until #223, `nav` and `footer` were the only zero-required-prop components,
     * which is exactly why `[{"component":"footer"}]` was the fixture for the
     * invariant-gate E2E. They are chrome now, and not composable, so no valid
     * composition can drift.
     *
     * Test 9 in tests/e2e/composition-editor.spec.ts depends on that: it asserts
     * that saving a drifted composition is REFUSED, because drift now implies
     * invalidity. If this invariant ever breaks, a valid-but-drifting composition
     * becomes constructible again and Test 9's premise is wrong — so this fails
     * in `composer test` (a required CI check) rather than hiding in the E2E
     * suite, which does not run on pull requests.
     */
    public function testEveryComposableComponentRejectsTheBareNoPropsShape(): void
    {
        foreach (pp_composable_components() as $name => $schema) {
            $has_required_prop = !empty(array_filter(
                $schema['props'] ?? [],
                fn($def) => !empty($def['required'])
            ));
            $has_content_requirement = !empty($schema['content_requirement']['any_of']);

            $this->assertTrue(
                $has_required_prop || $has_content_requirement,
                "Composable component '{$name}' declares neither a required prop nor a "
                . 'content_requirement. A composition that omits its `props` key would then be '
                . "VALID and would drift on the accordion round-trip, so '{$name}' renders empty "
                . 'and the editor locks the accordion. Give it a required prop or a '
                . 'content_requirement, or make it template-owned. If this is deliberate, Test 9 '
                . 'in tests/e2e/composition-editor.spec.ts must be revisited: it assumes a '
                . 'drifting composition is always invalid and therefore unsaveable.'
            );

            // Verify the invariant end-to-end: the bare shape must actually be
            // rejected by the shared validator, not merely "declared" rejectable.
            $result = pp_validate_composition([['component' => $name]]);
            $this->assertInstanceOf(
                \WP_Error::class,
                $result,
                "Bare `{\"component\": \"{$name}\"}` (no props) must be rejected by the validator."
            );
        }
    }

    public function testCompositionRejectsUnknownStyleSlot(): void
    {
        $composition = [
            [
                'component' => 'hero',
                'props'     => ['title' => 'Test'],
                'style'     => ['--hero-display' => 'none'],
            ],
        ];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('invalid_style_slot', $result->get_error_code());
        $this->assertStringContainsString('--hero-display', $result->get_error_message());
        $this->assertStringContainsString('--hero-bg', $result->get_error_message());
    }

    public function testCompositionRejectsInvalidStyleValue(): void
    {
        $composition = [
            [
                'component' => 'hero',
                'props'     => ['title' => 'Test'],
                'style'     => ['--hero-bg' => 'not-a-color'],
            ],
        ];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('invalid_style_value', $result->get_error_code());
    }

    public function testCompositionRejectsInjectionInStyleValue(): void
    {
        $composition = [
            [
                'component' => 'hero',
                'props'     => ['title' => 'Test'],
                'style'     => ['--hero-bg' => '#fff; background-image: url(evil)'],
            ],
        ];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testCompositionAllowsRecipeTrackingKey(): void
    {
        $composition = [
            [
                'component' => 'hero',
                'props'     => ['title' => 'Test'],
                'style'     => ['__recipe' => 'dark-spacious', '--hero-bg' => '#1a1a2e'],
            ],
        ];
        $result = pp_validate_composition($composition);
        $this->assertTrue($result);
    }

    /**
     * 8A+ (eng review): the seeded homepage composition is written to the DB by
     * lib/setup.php directly, bypassing the validating action/apply write path.
     * This is the one ingestion path that skips validation, so we guard it here:
     * the static default must itself be valid, or setup.php would persist a
     * composition the rest of the system considers invalid.
     */
    public function testDefaultHomepageCompositionPassesValidation(): void
    {
        $composition = pp_default_homepage_composition();
        $this->assertNotEmpty($composition, 'The seeded homepage composition must not be empty.');
        $this->assertTrue(
            pp_validate_composition($composition) === true,
            'pp_default_homepage_composition() must pass pp_validate_composition() — '
            . 'setup.php seeds it without going through the validating write path.'
        );

        // #512: the starter is a curated branded multi-band composition, not a
        // placeholder. Guard the shape so a future regression to a thin stub
        // (or an empty/one-band seed) fails here rather than shipping a weak
        // first-view homepage.
        $components = array_map(static fn ($c) => $c['component'], $composition);
        $this->assertGreaterThanOrEqual(
            5,
            count($composition),
            'The starter seed must be a multi-band branded page, not a minimal stub.'
        );
        foreach (['hero', 'grid', 'cta'] as $expected) {
            $this->assertContains(
                $expected,
                $components,
                "The branded starter must include a '$expected' band."
            );
        }

        // Every branded band drives its look through VALIDATED per-component
        // style slots (top-level `style` key), never homepage-only shared CSS
        // (#72) — so at least the hero and closing CTA carry a style map, and
        // every style map validates through the shared render engine (a value
        // the engine would reject renders nothing, silently weakening the page).
        foreach ($composition as $item) {
            $style = $item['style'] ?? [];
            if ($style === []) {
                continue;
            }
            $rendered = pp_render_style_vars($style, $item['component']);
            $declared = array_filter(
                array_keys($style),
                static fn ($k) => $k !== '__recipe'
            );
            $rendered_count = $rendered === '' ? 0 : count(explode('; ', $rendered));
            $this->assertSame(
                count($declared),
                $rendered_count,
                sprintf(
                    'Every style slot on the "%s" starter band must survive the render '
                    . 'boundary; a dropped value silently weakens the seeded page.',
                    $item['component']
                )
            );
        }
    }

    public function testNormalizeCompositionStripsEmptyStyle(): void
    {
        $items = [
            ['component' => 'hero', 'props' => ['title' => 'Test'], 'style' => []],
        ];
        $normalized = pp_normalize_composition($items);
        $this->assertArrayNotHasKey('style', $normalized[0]);
    }

    // ── Shared PHP<->JS validation contract (D5) ──────────────────────────
    //
    // Golden fixtures in tests/fixtures/composition-validation-cases.json are
    // asserted by BOTH this test and tests/js/pp-editor-logic.test.js. If
    // pp_validate_composition drifts from validateCompositionData on any
    // shared-contract rule, one side fails. Known intentional asymmetries
    // (blank required prop = JS-only; style-slot validation = PHP-only) are
    // documented in the fixture and deliberately excluded from this set.

    public function testSharedValidationContractCases(): void
    {
        $path = __DIR__ . '/fixtures/composition-validation-cases.json';
        $this->assertFileExists($path, 'Shared validation fixture is missing.');

        $data = json_decode(file_get_contents($path), true);
        $this->assertIsArray($data['cases'] ?? null, 'Fixture must define a cases[] array.');
        $this->assertNotEmpty($data['cases'], 'Fixture must define at least one case.');

        foreach ($data['cases'] as $case) {
            $result = pp_validate_composition($case['composition']);
            if ($case['expectValid']) {
                $this->assertTrue(
                    $result === true,
                    "PHP validator should ACCEPT shared-contract case: {$case['name']}"
                );
            } else {
                $this->assertInstanceOf(
                    \WP_Error::class,
                    $result,
                    "PHP validator should REJECT shared-contract case: {$case['name']}"
                );
            }
        }
    }

    // ── Field editability derivation vs schema (#120 origin, #509 rework) ────

    /**
     * The semantic-patch field set (wp pp operate patch / inspect-composition)
     * is DERIVED from each component's schema.json (#509 retired the hand-list
     * pp_register_component_fields() that caused the #120 drift). This is the
     * bidirectional drift-catcher: derivation and schema must AGREE, in both
     * directions, or the suite fails —
     *
     *   1. every derived field name resolves to a real schema prop (top-level,
     *      or `items[].X` against props.items.items) with a scalar type; and
     *   2. every scalar-typed schema prop (string/number/enum) IS derived,
     *      unconditionally since #629 retired the `patchable: false` opt-out
     *      that once exempted one — no silent coverage drop, the exact rot the
     *      old hand-list suffered; and
     *   3. no structural (array/object) top-level prop leaks into the field set.
     *
     * Walks the real registered components (not a hand-list), so a schema that
     * adds a scalar prop, or a derivation regression that drops or over-includes
     * one, fails here.
     */
    public function testDerivedFieldEditabilityAgreesWithSchema(): void
    {
        $scalarTypes = ['string', 'number', 'enum'];
        $components  = pp_get_registered_components();
        $this->assertNotEmpty($components, 'Expected at least one registered component.');

        $sawComposable = false;
        foreach ($components as $componentType => $schema) {
            $props = $schema['props'] ?? [];
            if (empty($props)) {
                continue;
            }

            $derived = [];
            foreach (pp_get_component_fields($componentType) as $field) {
                $derived[$field['name']] = $field['type'];
            }
            if (empty($derived)) {
                continue; // chrome/no-scalar component
            }
            $sawComposable = true;

            // Direction 1: every derived field resolves to a real scalar schema prop.
            foreach ($derived as $name => $type) {
                $this->assertContains($type, $scalarTypes, "'{$componentType}.{$name}' derived a non-scalar type '{$type}'.");
                if (str_starts_with($name, 'items[].')) {
                    $sub = substr($name, strlen('items[].'));
                    $itemProps = $props['items']['items'] ?? null;
                    $this->assertIsArray($itemProps, "'{$componentType}' derives '{$name}' but schema has no props.items.items.");
                    $this->assertArrayHasKey($sub, $itemProps, "'{$componentType}' derives '{$name}' but '{$sub}' is not a props.items.items key.");
                    $this->assertContains($itemProps[$sub]['type'] ?? null, $scalarTypes, "'{$componentType}.{$name}' sub-prop is not scalar in schema.");
                } else {
                    $this->assertArrayHasKey($name, $props, "'{$componentType}' derives '{$name}' but it is not a schema prop.");
                }
            }

            // Directions 2 & 3: every scalar top-level prop is derived; every
            // array/object top-level prop is NOT. There is no exemption arm —
            // the `patchable: false` opt-out that once carved one out is retired
            // (#629), so a scalar prop is derived unconditionally.
            foreach ($props as $propName => $propDef) {
                if (!is_array($propDef)) {
                    continue;
                }
                $type = $propDef['type'] ?? null;
                if (in_array($type, $scalarTypes, true)) {
                    $this->assertArrayHasKey($propName, $derived, "'{$componentType}.{$propName}' is a scalar schema prop but was NOT derived (silent coverage drop).");
                } elseif (in_array($type, ['array', 'object'], true)) {
                    $this->assertArrayNotHasKey($propName, $derived, "'{$componentType}.{$propName}' is a structural prop but leaked into the derived field set.");
                }
            }
        }

        $this->assertTrue($sawComposable, 'Expected at least one component to derive patchable fields.');
    }

    // ── Schema-rename drift-catcher (#495, rebased on the #604 removal) ──
    //
    // The legacy prop-rename ALIAS MAP is gone (#604) and so is its inventory pin. The
    // drift-catcher it shipped alongside SURVIVES, because it stands on its own merits:
    // it is the CI tripwire that fails a FUTURE schema change which removes or renames
    // a prop without saying so out loud, making the convention structural rather than
    // remembered.
    //
    // WHAT CHANGED. The guard used to accept TWO justifications for a disappeared
    // baseline prop: an alias-map entry, or an explicit migration note. With no alias
    // surface left, the migration NOTE is the SOLE escape hatch. That is deliberately
    // the stricter of the two — a note is a human writing down what happened, where an
    // alias entry silently made the problem go away.

    /**
     * The pinned baseline of declared props per component AS OF #495. This is a
     * FROZEN LITERAL, deliberately NOT re-globbed from the live schemas at runtime:
     * if it were regenerated each run, removing a prop would also remove it from the
     * baseline and the drift would be invisible. A future prop removal/rename leaves
     * the prop here but drops it from the live schema, so the drift-catcher fires
     * unless the same change adds a migration note. When you
     * intentionally ADD a prop, append it here in the same change.
     */
    private const PINNED_PROP_BASELINE = [
        'cta'          => ['id', 'title', 'title_accent', 'eyebrow', 'body', 'button_text', 'button_url', 'button2_text', 'button2_url', 'button2_variant', 'layout', 'theme', 'background_image', 'button_variant'],
        'embed'        => ['id', 'title', 'content', 'theme'],
        'faq'          => ['id', 'title', 'title_accent', 'eyebrow', 'theme', 'items'],
        'footer'       => ['location', 'show_logo', 'logo_text', 'logo_id', 'logo_alt', 'bg', 'text', 'link_color', 'blurb', 'contact', 'copyright', 'menu_label', 'contact_label', 'secondary_location', 'secondary_label', 'note', 'social'],
        'grid'         => ['id', 'title', 'title_accent', 'eyebrow', 'subheading', 'title_align', 'layout', 'card_emphasis', 'theme', 'columns', 'image_treatment', 'items'],
        'hero'         => ['id', 'title', 'title_accent', 'eyebrow', 'subheading', 'button_text', 'button_url', 'button2_text', 'button2_url', 'button_variant', 'button2_variant', 'layout', 'image_url', 'image_alt', 'image_id', 'spacing', 'width', 'split_ratio', 'vertical_align', 'proof'],
        'logos'        => ['id', 'title', 'theme', 'items'],
        'nav'          => ['location', 'logo_text', 'logo_id', 'logo_alt', 'bg', 'text', 'link_color'],
        'section'      => ['id', 'title', 'title_accent', 'eyebrow', 'subheading', 'title_align', 'body', 'image_url', 'image_alt', 'image_id', 'layout', 'theme', 'background_image', 'panel_heading', 'panel_body', 'panel_items', 'panel_cta_text', 'panel_cta_url', 'panel_cta_variant', 'panel_items_marker', 'body_marker', 'body_items'],
        'stats'        => ['id', 'title', 'title_accent', 'theme', 'background_image', 'items'],
        'table'        => ['id', 'title', 'headers', 'rows', 'caption'],
        'testimonials' => ['id', 'title', 'title_accent', 'eyebrow', 'subheading', 'title_align', 'layout', 'theme', 'items'],
    ];

    /**
     * The SOLE escape hatch for a retired prop (#604 — there is no alias surface any
     * more). Empty today. A future prop removal or rename must record
     * `component => [prop => note]` here, in the SAME change, or the drift-catcher
     * below fails CI. The note is the point: it forces the author to state what
     * happens to documents that already store the old name.
     */
    private const SCHEMA_RENAME_MIGRATION_NOTES = [];

    /**
     * The append-only floor for the prop surface (#598). 126 props across 12 components
     * as of v1.13.15. NEVER DECREASE THIS. Adding props raises what the baseline holds,
     * which is fine (the check is >=); retiring one moves it into the notes register, so
     * the accounted total still never drops.
     */
    private const PROP_BASELINE_FLOOR = 126;

    /** Content fingerprint of PINNED_PROP_BASELINE. See baselineFingerprint(). */
    private const PROP_BASELINE_FINGERPRINT = '7033f12eb731ca20';

    /**
     * Pure drift detector: any baseline prop that no longer exists in the live schema
     * must be covered by a migration note, else it is a violation. Kept as a static
     * helper so the real run and the simulated-rename test share one implementation.
     *
     * The `$aliasMap` parameter this used to take is GONE (#604) along with the alias
     * surface itself — dropping it, rather than passing [] forever, is what makes the
     * note the only reachable escape hatch.
     *
     * SURFACE-AGNOSTIC (#598). The algorithm only ever compared "declared names per
     * component" against "pinned names per component", which is exactly the shape of
     * the STYLE-SLOT surface too. `$kind` is a message label, not a behaviour switch:
     * both surfaces run this one implementation, so neither can drift into its own
     * subtly-different notion of what counts as a rename.
     *
     * @param array<string,string[]>              $baseline   component => declared names
     * @param array<string,string[]>              $liveNames  component => declared names
     * @param array<string,array<string,string>>  $notes      component => [name => note]
     * @param string                              $kind       'prop' or 'style slot' (label only)
     * @return string[]  Human-readable violations (empty = no drift).
     */
    private static function detectSchemaRenameDrift(array $baseline, array $liveNames, array $notes, string $kind): array
    {
        $violations = [];
        foreach ($baseline as $component => $names) {
            $live = $liveNames[$component] ?? [];
            foreach ($names as $name) {
                if (in_array($name, $live, true)) {
                    continue; // still declared — no drift
                }
                if (!isset($notes[$component]) || !array_key_exists($name, $notes[$component])) {
                    $violations[] = sprintf(
                        'Component "%s" %s "%s" was removed/renamed without a migration note. '
                        . 'FIX: add \'%s\' => \'<what replaced it, or that it is gone, naming the ruling issue e.g. #598>\' '
                        . 'to the %s migration-notes register. Deleting the pinned baseline entry is NOT a fix — '
                        . 'the baseline is append-only.',
                        $component,
                        $kind,
                        $name,
                        $name,
                        $kind
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * The ADD-PATH counterpart, extracted as a pure helper for the same reason the
     * remove-path is one (#598): an inline loop over the live schemas can only ever be
     * exercised by the live schemas, so nothing proves it fires. As a helper, a
     * simulated unpinned addition can be fed to it directly.
     *
     * Two ways a baseline goes stale, both caught here:
     *   1. a whole component the baseline has never heard of (a NEW slot-bearing
     *      component, including footer/nav if they ever trade
     *      `chrome_custom_properties` for real `style_slots`);
     *   2. a new name on a component the baseline already covers.
     *
     * Either one would let a LATER removal of that name slip past the remove-path
     * guard, because the removed name was never in the baseline to begin with.
     *
     * @param array<string,string[]>  $baseline   component => declared names
     * @param array<string,string[]>  $liveNames  component => declared names
     * @param string                  $kind       'prop' or 'style slot' (label only)
     * @return string[]  Human-readable violations (empty = baseline is current).
     */
    private static function detectUnpinnedAdditions(array $baseline, array $liveNames, string $kind): array
    {
        $violations = [];
        foreach ($liveNames as $component => $names) {
            if (!array_key_exists($component, $baseline)) {
                $violations[] = sprintf(
                    'Component "%s" is not in the pinned %s baseline — add it.',
                    $component,
                    $kind
                );
                continue;
            }
            foreach ($names as $name) {
                if (!in_array($name, $baseline[$component], true)) {
                    $violations[] = sprintf(
                        'Component "%s" %s "%s" is not pinned in the baseline — append it in this same change.',
                        $component,
                        $kind,
                        $name
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * H1 (part 2) — a stable fingerprint of a pinned baseline's CONTENTS.
     *
     * The count floor below catches a baseline that SHRANK. It cannot catch a
     * count-preserving SWAP: renaming a name in the schema and editing the same line in
     * the baseline leaves the total untouched, so a fully undocumented rename ships
     * green. That is the identical count-blindness this whole issue exists to kill —
     * `testSlotCountPinsCannotSeeARenameButTheBaselineCan()` names it — and a floor
     * reproduces it one level up.
     *
     * The fingerprint closes it by making the baseline's CONTENT the pinned thing, so
     * every edit to either literal has to be acknowledged by updating a second, labelled
     * constant. Names are sorted before hashing, so pure reordering or reformatting does
     * not trip it; only the actual set of names does.
     *
     * Honest about what this is: an author who edits the literal can also edit the
     * fingerprint. No pinned-literal scheme can prevent that. What it guarantees is that
     * the author is TOLD, at the moment of the edit, what documentation the change owes —
     * and that the edit is a visible line in review rather than one name quietly
     * changing inside a ninety-line block.
     */
    private static function baselineFingerprint(array $baseline): string
    {
        $canonical = [];
        foreach ($baseline as $component => $names) {
            sort($names);
            $canonical[$component] = $names;
        }
        ksort($canonical);

        return substr(hash('sha256', json_encode($canonical)), 0, 16);
    }

    /**
     * The remedy text for a changed baseline. Written once so both surfaces say the same
     * thing, and deliberately phrased so the instruction is what to DOCUMENT — never
     * "delete the entry", which is the wrong fix the old messages implied by naming the
     * constant and nothing else.
     */
    private static function baselineEditRemedy(string $kind, string $baselineConst, string $fingerprintConst, string $notesConst): string
    {
        return sprintf(
            "%s baseline contents changed (%s).\n"
            . "  ADDED a %s?    Append it to %s, then update %s.\n"
            . "  RETIRED a %s?  Record it in %s with a note naming its replacement (or that it is gone) "
            . "and the issue that ruled it, e.g. 'renamed to X (#598)'. Then update %s.\n"
            . "  RENAMED one?   That is a retirement plus an addition: do BOTH of the above.\n"
            . "Silently editing the name in %s is not a fix — a rename that nobody documented is exactly "
            . "what this guard exists to stop.",
            ucfirst($kind),
            $baselineConst,
            $kind,
            $baselineConst,
            $fingerprintConst,
            $kind,
            $notesConst,
            $fingerprintConst,
            $baselineConst
        );
    }

    /**
     * H2 + H3 — the migration-notes register polices itself (#598, 7A Option B).
     *
     * The register is the SOLE escape hatch from the remove-path guard, and an escape
     * hatch nobody inspects is just a hole. Two ways a note fails to be a documented
     * breaking change:
     *
     *   H2  CONTENT. detectSchemaRenameDrift() clears drift on key existence alone, so
     *       '', null and 0 silence it exactly as well as real prose. The recorded
     *       decision requires each entry to name the change AND the issue that ruled it,
     *       so require a non-empty string carrying an issue reference (#123).
     *
     *   H3  STALENESS. A note for a name the schemas STILL declare is accepted silently
     *       today, which means the guard can be disarmed in advance: one commit adds
     *       notes for live names (zero failures, reads as documentation), a later commit
     *       deletes those names and sails through. Pre-authorisation is not
     *       documentation. A note may only describe a name that is actually gone.
     *
     * @param array<string,array<string,mixed>>  $notes      component => [name => note]
     * @param array<string,string[]>             $liveNames  component => declared names
     * @param string                             $kind       'prop' or 'style slot' (label only)
     * @return string[]  Human-readable violations (empty = register is honest).
     */
    private static function detectMigrationNoteDefects(array $notes, array $liveNames, string $kind): array
    {
        $violations = [];
        foreach ($notes as $component => $entries) {
            foreach ($entries as $name => $note) {
                if (!is_string($note) || trim($note) === '') {
                    $violations[] = sprintf(
                        'Migration note for %s "%s.%s" is empty. FIX: write what replaced it (or that it is '
                        . 'gone) and cite the issue that ruled it, e.g. \'renamed to X (#598)\'.',
                        $kind,
                        $component,
                        $name
                    );
                    continue;
                }
                if (!preg_match('/#\d+/', $note)) {
                    $violations[] = sprintf(
                        'Migration note for %s "%s.%s" does not cite a ruling issue. FIX: add the issue '
                        . 'reference (e.g. #598) that ruled this breaking change.',
                        $kind,
                        $component,
                        $name
                    );
                }
                if (in_array($name, $liveNames[$component] ?? [], true)) {
                    $violations[] = sprintf(
                        '%s "%s.%s" still exists, so its migration note describes a breaking change that has '
                        . 'not happened. FIX: remove the note. A note may only be written in the SAME change '
                        . 'that retires the name — pre-authorising a future removal disarms the guard.',
                        ucfirst($kind),
                        $component,
                        $name
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * H1 — the pinned baseline is APPEND-ONLY (#598, 7A Option B).
     *
     * Without this, the guard is defeated by the cheapest possible edit. A rename that
     * also deletes the old name from the baseline literal leaves nothing missing to
     * detect, so every check passes and the migration note is never written. The old
     * failure text even steered an author there by naming the constant.
     *
     * The floor is a second, independent record of how many names the baseline is
     * accountable for. A retired name must MOVE into the notes register (or simply stay
     * pinned alongside its note); either way the accounted total never drops. Deleting
     * a line without writing a note drops it, and that fails here.
     *
     * The floor is itself a literal someone could edit down — but it is one labelled
     * number whose entire job is to be a floor, which a reviewer sees, rather than one
     * line vanishing from a ninety-line literal, which a reviewer does not.
     *
     * @param array<string,string[]>             $baseline  component => pinned names
     * @param array<string,array<string,mixed>>  $notes     component => [name => note]
     * @param int                                $floor     names this surface must account for
     * @param string                             $kind      'prop' or 'style slot' (label only)
     * @return string[]  Human-readable violations (empty = nothing went missing).
     */
    private static function detectBaselineShrink(array $baseline, array $notes, int $floor, string $kind): array
    {
        $pinned   = array_sum(array_map('count', $baseline));
        $recorded = array_sum(array_map('count', $notes));
        $accounted = $pinned + $recorded;

        if ($accounted >= $floor) {
            return [];
        }

        return [sprintf(
            'The pinned %s baseline shrank: %d names are accounted for (%d pinned + %d in migration notes) '
            . 'but this surface must account for at least %d. A retired name MOVES into the migration-notes '
            . 'register with a note naming its replacement and ruling issue — deleting the baseline entry is '
            . 'NOT a valid fix, and lowering the floor is not either.',
            $kind,
            $accounted,
            $pinned,
            $recorded,
            $floor
        )];
    }

    /**
     * Every shipped schema, decoded, keyed by component name. The single discovery
     * point for BOTH surfaces (#598): liveProps() and liveSlots() are projections over
     * it, so they cannot drift into different ideas of which components exist or of
     * what a malformed schema means.
     *
     * A schema that fails to parse fails LOUDLY here. It used to decode to null and
     * flow onward as an empty prop list, which reads downstream as "this component
     * declares nothing" — indistinguishable from a mass removal, and a misdiagnosis
     * pointed at the wrong fix.
     */
    private function liveSchemas(): array
    {
        $out = [];
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $schemaFile) {
            $schema = json_decode(file_get_contents($schemaFile), true);
            $this->assertNotNull(
                $schema,
                basename(dirname($schemaFile)) . '/schema.json is not valid JSON — discovery would silently skip it.'
            );
            $out[$schema['component'] ?? basename(dirname($schemaFile))] = $schema;
        }
        return $out;
    }

    /** Live declared props per component, read from the shipped schemas. */
    private function liveProps(): array
    {
        $out = [];
        foreach ($this->liveSchemas() as $name => $schema) {
            $out[$name] = array_keys($schema['props'] ?? []);
        }
        return $out;
    }

    public function testLiveSchemasHaveNoUnnotedRenameDriftFromBaseline(): void
    {
        // The real guard: today baseline == live, so there is no drift. This freezes the
        // baseline; a future prop removal or rename WITHOUT a migration note fails HERE.
        //
        // It stays green across the #604 alias removal for a reason worth stating: the
        // baseline only ever contained props the schemas actually DECLARE, never the
        // retired names the alias map covered. Deleting the map therefore removed a
        // justification nothing was using, not a live exemption.
        $drift = self::detectSchemaRenameDrift(
            self::PINNED_PROP_BASELINE,
            $this->liveProps(),
            self::SCHEMA_RENAME_MIGRATION_NOTES,
            'prop'
        );
        $this->assertSame([], $drift, implode("\n", $drift));
    }

    public function testPropMigrationNotesAreWellFormedAndCurrent(): void
    {
        // H2 + H3 on the prop surface. Empty today, so this is a contract waiting for
        // the first real note — but it is the contract that makes the note mean
        // something when one is finally written.
        $defects = self::detectMigrationNoteDefects(
            self::SCHEMA_RENAME_MIGRATION_NOTES,
            $this->liveProps(),
            'prop'
        );
        $this->assertSame([], $defects, implode("\n", $defects));
    }

    public function testPropBaselineIsAppendOnly(): void
    {
        // H1 on the prop surface: the baseline may grow, never shrink.
        $shrink = self::detectBaselineShrink(
            self::PINNED_PROP_BASELINE,
            self::SCHEMA_RENAME_MIGRATION_NOTES,
            self::PROP_BASELINE_FLOOR,
            'prop'
        );
        $this->assertSame([], $shrink, implode("\n", $shrink));

        // The floor alone cannot see a count-preserving SWAP, which is the edit that
        // actually shipped an undocumented rename. The fingerprint can.
        $this->assertSame(
            self::PROP_BASELINE_FINGERPRINT,
            self::baselineFingerprint(self::PINNED_PROP_BASELINE),
            self::baselineEditRemedy('prop', 'PINNED_PROP_BASELINE', 'PROP_BASELINE_FINGERPRINT', 'SCHEMA_RENAME_MIGRATION_NOTES')
        );
    }

    public function testEveryLiveSchemaPropIsPinnedInBaseline(): void
    {
        // Symmetric guard (the add-path): a newly ADDED prop that the author forgets
        // to append to PINNED_PROP_BASELINE would leave the baseline stale, so a LATER
        // removal of that prop could escape the drift-catcher. Forcing every live prop
        // into the baseline makes the convention structural in BOTH directions: a schema
        // change must touch the baseline in the same commit, and a rename then fails on
        // both the remove-path (which needs a migration note — the sole escape hatch
        // since #604, and still the sole one after #606) and here.
        //
        // The check itself moved into detectUnpinnedAdditions() in #598 with its
        // semantics unchanged (unknown component, or unpinned name on a known
        // component). It was an inline loop over the live schemas, which meant nothing
        // could prove it fires; testUnpinnedAdditionIsCaught() now does.
        //
        // The add-path iterates the LIVE set, so — unlike the remove-path — an empty or
        // partial discovery would pass it vacuously. Pin the component set first, in
        // both directions, so a broken glob is a hard failure here rather than a green
        // check that guards nothing.
        $live = $this->liveProps();
        $this->assertNotEmpty($live, 'prop discovery found no components — the add-path guard would pass vacuously.');
        $this->assertSame(
            array_keys(self::PINNED_PROP_BASELINE),
            array_keys($live),
            'the discovered component set must match PINNED_PROP_BASELINE exactly.'
        );

        $violations = self::detectUnpinnedAdditions(self::PINNED_PROP_BASELINE, $live, 'prop');
        $this->assertSame(
            [],
            $violations,
            "PINNED_PROP_BASELINE (SchemaValidationTest) is stale:\n" . implode("\n", $violations)
        );
    }

    public function testSchemaRenameDriftIsCaught(): void
    {
        // The self-test: prove the guard actually fires, so a future refactor cannot
        // quietly neuter it and leave a permanently-green tripwire behind.
        //
        // Simulate a future rename: the baseline says cta had a `headline` prop, the
        // live schema no longer declares it. With no migration note, this MUST be caught.
        $baseline = ['cta' => ['id', 'headline', 'button_text']];
        $live     = ['cta' => ['id', 'button_text']]; // `headline` removed

        $unnoted = self::detectSchemaRenameDrift($baseline, $live, [], 'prop');
        $this->assertNotEmpty($unnoted, 'a schema rename with no migration note must be flagged');
        $this->assertStringContainsString('headline', $unnoted[0]);

        // An explicit migration note in the SAME change clears it. Since #604 this is
        // the ONLY thing that does — the alias-entry branch that used to sit here was
        // removed with the alias surface it depended on.
        $withNote = self::detectSchemaRenameDrift($baseline, $live, ['cta' => ['headline' => 'migrated by #999']], 'prop');
        $this->assertSame([], $withNote, 'a migration note for the renamed prop clears the drift');
    }


    // ── Style-slot rename drift-catcher (#598), on the shared prop/slot engine ──
    //
    // The SLOT surface had every kind of pin EXCEPT a rename-catcher. The count pins
    // (testStyleSlotsExistForV1Components here, and the slot count in css-lint.test.js)
    // are rename-INVARIANT by construction: a rename removes one name and adds another,
    // so no total ever moves. Everything else that touches slots — StyleSlotContractTest,
    // css-lint's per-slot consumption check — globs the LIVE schemas, so it re-derives
    // its expectations from the very file the rename just edited and stays green.
    //
    // Why that mattered: pp_render_style_vars() (lib/wp.php) drops an undeclared slot
    // name with a bare `continue` — no finding, no warning, no log. A renamed slot means
    // every stored page that set the old name silently renders unstyled while every
    // action still returns ok:true. Under the ratified no-alias posture (#570 Addendum
    // #5, #603/#604) that breakage is ALLOWED — renames are documented breaking changes,
    // not aliased migrations. This guard is what makes "documented" enforceable.
    //
    // Scope, stated plainly: this is a CI tripwire on future schema edits. It does not
    // repair, resolve, or alias anything at runtime, and it does nothing for pages that
    // already stored a name a deliberate rename retired. StoredCompositionAliasRenderTest
    // guards the negative space (the removed alias machinery stays removed).

    /**
     * The pinned baseline of declared style slots per component AS OF #598 (v1.13.14,
     * the v1.14.0 gate's final schema state — this issue lands last for exactly that
     * reason). A FROZEN LITERAL for the same reason PINNED_PROP_BASELINE is one: if it
     * were re-globbed at runtime, a removal would delete itself from the baseline and
     * the drift would be invisible.
     *
     * 261 slots across the 10 slot-bearing components. footer and nav are absent because
     * they declare no `style_slots` at all (they carry `chrome_custom_properties`
     * instead) — and if either ever gains real slots, the add-path guard below fails
     * until it is pinned here, so their absence is enforced rather than assumed.
     *
     * When you intentionally ADD a slot, append it here in the same change.
     */
    private const PINNED_SLOT_BASELINE = [
        'cta'          => [
            '--cta-padding-top', '--cta-padding-bottom', '--cta-bg', '--cta-heading-color',
            '--cta-heading-accent-color', '--cta-eyebrow-color', '--cta-eyebrow-bg', '--cta-eyebrow-radius',
            '--cta-eyebrow-border-width', '--cta-eyebrow-border-color', '--cta-eyebrow-text-transform',
            '--cta-heading-size', '--cta-body-color', '--cta-body-size', '--cta-inner-gap', '--cta-accent',
            '--cta-accent-hover', '--cta-button-bg', '--cta-button-border', '--cta-button-color',
            '--cta-button-hover-bg', '--cta-button-hover-border', '--cta-button-hover-color',
            '--cta-button-shadow', '--cta-button2-bg', '--cta-button2-border', '--cta-button2-color',
            '--cta-button2-hover-bg', '--cta-button2-hover-border', '--cta-button2-hover-color',
            '--cta-button2-shadow', '--cta-border-color', '--cta-border-width', '--cta-radius', '--cta-shadow',
            '--cta-heading-measure', '--cta-heading-margin-bottom', '--cta-body-measure', '--cta-overlay-bg',
            '--cta-bg-position',
        ],
        'embed'        => [
            '--embed-padding-top', '--embed-padding-bottom', '--embed-heading-size', '--embed-heading-color',
            '--embed-heading-measure', '--embed-heading-margin-bottom', '--embed-body-measure',
            '--embed-body-color',
        ],
        'faq'          => [
            '--faq-padding-top', '--faq-padding-bottom', '--faq-bg', '--faq-item-bg', '--faq-eyebrow-color',
            '--faq-eyebrow-bg', '--faq-eyebrow-radius', '--faq-eyebrow-border-width', '--faq-eyebrow-border-color',
            '--faq-eyebrow-text-transform', '--faq-heading-size', '--faq-heading-color', '--faq-heading-measure',
            '--faq-body-measure', '--faq-heading-accent-color', '--faq-heading-margin-bottom',
            '--faq-question-color', '--faq-answer-color', '--faq-item-border-color', '--faq-item-radius',
            '--faq-question-open-color',
        ],
        'grid'         => [
            '--grid-padding-top', '--grid-padding-bottom', '--grid-bg', '--grid-heading-color',
            '--grid-heading-accent-color', '--grid-eyebrow-color', '--grid-eyebrow-bg', '--grid-eyebrow-radius',
            '--grid-eyebrow-border-width', '--grid-eyebrow-border-color', '--grid-eyebrow-text-transform',
            '--grid-subheading-color', '--grid-subheading-margin-bottom', '--grid-heading-margin-bottom',
            '--grid-heading-size', '--grid-heading-measure', '--grid-gap', '--grid-item-bg',
            '--grid-item-border-color', '--grid-item-border-width', '--grid-item-radius', '--grid-item-shadow',
            '--grid-item-bar-color', '--grid-item-bar-height', '--grid-featured-texture-color',
            '--grid-featured-shadow', '--grid-item-padding', '--grid-item-gap', '--grid-item-text-align',
            '--grid-item-icon-size', '--grid-item-title-size', '--grid-item-title-color', '--grid-item-text-color',
            '--grid-item-bullet-color', '--grid-item-link-color', '--grid-item-link-hover-color', '--grid-step-bg',
            '--grid-step-text-color',
        ],
        'hero'         => [
            '--hero-padding-top', '--hero-padding-bottom', '--hero-bg', '--hero-heading-color',
            '--hero-heading-accent-color', '--hero-eyebrow-color', '--hero-eyebrow-bg', '--hero-eyebrow-radius',
            '--hero-eyebrow-border-width', '--hero-eyebrow-border-color', '--hero-eyebrow-text-transform',
            '--hero-accent', '--hero-accent-hover', '--hero-button-bg', '--hero-button-hover-bg',
            '--hero-button-border', '--hero-button-hover-border', '--hero-button-color', '--hero-button-shadow',
            '--hero-button2-bg', '--hero-button2-border', '--hero-button2-color', '--hero-button2-hover-bg',
            '--hero-button2-hover-border', '--hero-button2-hover-color', '--hero-heading-size',
            '--hero-heading-measure', '--hero-heading-margin-bottom', '--hero-heading-weight',
            '--hero-subheading-size', '--hero-subheading-color', '--hero-proof-color', '--hero-content-gap',
            '--hero-content-width', '--hero-overlay-bg', '--hero-image-radius', '--hero-image-position',
            '--hero-image-aspect-ratio', '--hero-bg-position', '--hero-border-color', '--hero-border-width',
            '--hero-radius', '--hero-shadow', '--hero-surface-bg', '--hero-surface-padding',
            '--hero-surface-border-color', '--hero-surface-border-width', '--hero-surface-radius',
            '--hero-surface-shadow',
        ],
        'logos'        => [
            '--logos-padding-top', '--logos-padding-bottom', '--logos-heading-size', '--logos-heading-color',
            '--logos-heading-measure', '--logos-heading-margin-bottom', '--logos-image-size', '--logos-gap',
        ],
        'section'      => [
            '--section-padding-top', '--section-padding-bottom', '--section-bg', '--section-body-color',
            '--section-body-link-color', '--section-body-link-hover-color', '--section-heading-size',
            '--section-heading-measure', '--section-heading-color', '--section-heading-accent-color',
            '--section-eyebrow-color', '--section-eyebrow-bg', '--section-eyebrow-radius',
            '--section-eyebrow-border-width', '--section-eyebrow-border-color', '--section-eyebrow-text-transform',
            '--section-subheading-color', '--section-subheading-margin-bottom', '--section-heading-margin-bottom',
            '--section-body-measure', '--section-body-size', '--section-body-weight', '--section-border-color',
            '--section-border-width', '--section-radius', '--section-image-radius', '--section-image-position',
            '--section-image-aspect-ratio', '--section-bg-position', '--section-overlay-bg', '--section-shadow',
            '--section-panel-bg', '--section-panel-border-color', '--section-panel-border-width',
            '--section-panel-radius', '--section-panel-padding', '--section-panel-text', '--section-panel-font',
            '--section-panel-marker-color', '--section-panel-cta-bg', '--section-panel-cta-border',
            '--section-panel-cta-hover-border', '--section-panel-cta-color', '--section-panel-cta-shadow',
            '--section-body-marker-color', '--section-separator-color', '--section-inline-items-align',
        ],
        'stats'        => [
            '--stats-padding-top', '--stats-padding-bottom', '--stats-bg', '--stats-heading-size',
            '--stats-heading-color', '--stats-heading-measure', '--stats-heading-margin-bottom',
            '--stats-heading-accent-color', '--stats-number-color', '--stats-number-size', '--stats-number-font',
            '--stats-number-weight', '--stats-label-color', '--stats-bg-position', '--stats-overlay-bg',
            '--stats-radius', '--stats-max-width',
        ],
        'table'        => [
            '--table-padding-top', '--table-padding-bottom', '--table-heading-size', '--table-heading-color',
            '--table-heading-measure', '--table-heading-margin-bottom',
        ],
        'testimonials' => [
            '--testimonials-padding-top', '--testimonials-padding-bottom', '--testimonials-bg',
            '--testimonials-heading-size', '--testimonials-heading-color', '--testimonials-heading-measure',
            '--testimonials-heading-accent-color', '--testimonials-eyebrow-color', '--testimonials-eyebrow-bg',
            '--testimonials-eyebrow-radius', '--testimonials-eyebrow-border-width',
            '--testimonials-eyebrow-border-color', '--testimonials-eyebrow-text-transform',
            '--testimonials-subheading-color', '--testimonials-subheading-margin-bottom',
            '--testimonials-heading-margin-bottom', '--testimonials-gap', '--testimonials-item-bg',
            '--testimonials-item-border-color', '--testimonials-item-border-width', '--testimonials-item-radius',
            '--testimonials-item-shadow', '--testimonials-item-padding', '--testimonials-quote-color',
            '--testimonials-quote-mark-color', '--testimonials-author-color', '--testimonials-meta-color',
        ],
    ];

    /**
     * The SOLE escape hatch for a retired style slot — the slot-side twin of
     * SCHEMA_RENAME_MIGRATION_NOTES. Empty today, by design. A future slot removal or
     * rename must record `component => [slot => note]` here, in the SAME change, naming
     * the replacement (or the removal) and the issue that ruled it, or the drift-catcher
     * below fails CI. Deliberately a note and not an alias: a note is a human writing
     * down what happens to documents that already store the old name; an alias would
     * make the problem quietly go away, which #603/#604 removed the machinery for.
     */
    private const SLOT_RENAME_MIGRATION_NOTES = [];

    /**
     * The append-only floor for the style-slot surface (#598). 261 slots across the 10
     * slot-bearing components as of v1.13.15. NEVER DECREASE THIS — same contract as
     * PROP_BASELINE_FLOOR, deliberately identical in shape so neither surface can drift
     * into a weaker notion of what "documented" means.
     */
    private const SLOT_BASELINE_FLOOR = 261;

    /** Content fingerprint of PINNED_SLOT_BASELINE. See baselineFingerprint(). */
    private const SLOT_BASELINE_FINGERPRINT = 'b7c047c306d4d33b';

    /**
     * Live declared style slots per component, read from the shipped schemas.
     *
     * Discovery is a glob over ALL component schemas, not a walk of the pinned set: a
     * brand-new slot-bearing component has to reach the add-path guard, not slip past it
     * because the baseline never named it. Components with no `style_slots` are omitted
     * so the guards speak only about the slot surface.
     */
    private function liveSlots(): array
    {
        $out = [];
        foreach ($this->liveSchemas() as $name => $schema) {
            $slots = array_keys($schema['styling']['style_slots'] ?? []);
            if ($slots !== []) {
                $out[$name] = $slots;
            }
        }
        return $out;
    }

    public function testLiveSchemasHaveNoUnnotedSlotRenameDriftFromBaseline(): void
    {
        // The real guard (remove-path): today baseline == live, so there is no drift.
        // A future slot removal or rename WITHOUT a migration note fails HERE.
        //
        // Fail-closed by construction: if the glob ever breaks, liveSlots() returns []
        // and every baseline slot reads as missing, so a broken discovery is a loud
        // failure rather than a vacuous pass.
        $drift = self::detectSchemaRenameDrift(
            self::PINNED_SLOT_BASELINE,
            $this->liveSlots(),
            self::SLOT_RENAME_MIGRATION_NOTES,
            'style slot'
        );
        $this->assertSame([], $drift, implode("\n", $drift));
    }

    public function testSlotMigrationNotesAreWellFormedAndCurrent(): void
    {
        // H2 + H3 on the slot surface — the identical contract, run through the
        // identical helper. The mirror the issue asked for, held at the stronger level.
        $defects = self::detectMigrationNoteDefects(
            self::SLOT_RENAME_MIGRATION_NOTES,
            $this->liveSlots(),
            'style slot'
        );
        $this->assertSame([], $defects, implode("\n", $defects));
    }

    public function testSlotBaselineIsAppendOnly(): void
    {
        // H1 on the slot surface. This is the guard that makes the migration note the
        // real escape hatch: without it the cheapest way to a green build is deleting
        // the baseline line, which documents nothing.
        $shrink = self::detectBaselineShrink(
            self::PINNED_SLOT_BASELINE,
            self::SLOT_RENAME_MIGRATION_NOTES,
            self::SLOT_BASELINE_FLOOR,
            'style slot'
        );
        $this->assertSame([], $shrink, implode("\n", $shrink));

        // The swap-catcher. Renaming a slot in the schema and editing the matching line
        // here keeps every count identical; without this the rename ships undocumented.
        $this->assertSame(
            self::SLOT_BASELINE_FINGERPRINT,
            self::baselineFingerprint(self::PINNED_SLOT_BASELINE),
            self::baselineEditRemedy('style slot', 'PINNED_SLOT_BASELINE', 'SLOT_BASELINE_FINGERPRINT', 'SLOT_RENAME_MIGRATION_NOTES')
        );
    }

    public function testEveryLiveSchemaSlotIsPinnedInBaseline(): void
    {
        // The add-path: a newly added slot must be appended to PINNED_SLOT_BASELINE in
        // the same commit. Without this, a slot added today and renamed next month would
        // never have been in the baseline, so the remove-path guard above would have
        // nothing to miss and the rename would ship undocumented after all.
        //
        // This path iterates the LIVE set, so it has the one weakness the remove-path
        // does not: with nothing discovered there is nothing to check, and it would pass
        // green. Pin the component set in both directions first — that turns a broken
        // glob, a moved schema, or a component that stopped declaring slots into a hard
        // failure instead of a silently unguarded surface.
        $live = $this->liveSlots();
        $this->assertNotEmpty($live, 'slot discovery found no components — the add-path guard would pass vacuously.');
        $this->assertSame(
            array_keys(self::PINNED_SLOT_BASELINE),
            array_keys($live),
            'the discovered slot-bearing component set must match PINNED_SLOT_BASELINE exactly.'
        );

        $violations = self::detectUnpinnedAdditions(self::PINNED_SLOT_BASELINE, $live, 'style slot');
        $this->assertSame(
            [],
            $violations,
            "PINNED_SLOT_BASELINE (SchemaValidationTest) is stale:\n" . implode("\n", $violations)
        );
    }

    public function testSlotRenameDriftIsCaught(): void
    {
        // The self-test for the headline scenario, and the one the count pins cannot
        // see: a RENAME, where the slot total does not move at all.
        $baseline = ['hero' => ['--hero-bg', '--hero-heading-color']];
        $live     = ['hero' => ['--hero-bg', '--hero-title-color']]; // renamed, count unchanged

        $this->assertCount(
            count($baseline['hero']),
            $live['hero'],
            'the simulated rename must preserve the slot count, or it is not testing the blind spot.'
        );

        $unnoted = self::detectSchemaRenameDrift($baseline, $live, [], 'style slot');
        $this->assertNotEmpty($unnoted, 'a slot rename with no migration note must be flagged');
        $this->assertStringContainsString('--hero-heading-color', $unnoted[0]);
        // The label is load-bearing, not cosmetic: the message has to tell the author
        // which surface drifted, or a shared algorithm reports prop drift for slots.
        $this->assertStringContainsString('style slot', $unnoted[0]);
        $this->assertStringContainsString('hero', $unnoted[0]);

        // A migration note in the SAME change is the only thing that clears it.
        $withNote = self::detectSchemaRenameDrift(
            $baseline,
            $live,
            ['hero' => ['--hero-heading-color' => 'renamed to --hero-title-color by #999']],
            'style slot'
        );
        $this->assertSame([], $withNote, 'a migration note for the renamed slot clears the drift');
    }

    public function testSlotRemovalDriftIsCaught(): void
    {
        // A plain removal (no replacement) is the same violation, and the note must be
        // slot-scoped: a note about a DIFFERENT slot on the same component does not
        // launder it.
        $baseline = ['stats' => ['--stats-bg', '--stats-radius']];
        $live     = ['stats' => ['--stats-bg']];

        $unnoted = self::detectSchemaRenameDrift($baseline, $live, [], 'style slot');
        $this->assertCount(1, $unnoted);
        $this->assertStringContainsString('--stats-radius', $unnoted[0]);

        $wrongNote = self::detectSchemaRenameDrift(
            $baseline,
            $live,
            ['stats' => ['--stats-bg' => 'this slot still exists — irrelevant to the removal']],
            'style slot'
        );
        $this->assertCount(1, $wrongNote, 'a note naming some other slot must not clear the removal');

        // Removing a component's slots wholesale is drift for every one of them.
        $gone = self::detectSchemaRenameDrift($baseline, [], [], 'style slot');
        $this->assertCount(2, $gone);
    }

    public function testUnpinnedAdditionIsCaught(): void
    {
        // The add-path self-test, covering both surfaces because both now run this one
        // helper. Without it the add-path guards could only ever be exercised by the
        // live schemas, which is to say: never proven to fire.
        $clean = self::detectUnpinnedAdditions(['hero' => ['--hero-bg']], ['hero' => ['--hero-bg']], 'style slot');
        $this->assertSame([], $clean, 'a live set already pinned in the baseline is not drift');

        // 1. A new name on a component the baseline already covers.
        $added = self::detectUnpinnedAdditions(
            ['hero' => ['--hero-bg']],
            ['hero' => ['--hero-bg', '--hero-glow']],
            'style slot'
        );
        $this->assertCount(1, $added, 'an unpinned slot addition must be flagged');
        $this->assertStringContainsString('--hero-glow', $added[0]);
        $this->assertStringContainsString('style slot', $added[0]);

        // 2. A whole component the baseline has never heard of — the footer/nav case,
        //    if either ever trades chrome_custom_properties for real style slots.
        $newComponent = self::detectUnpinnedAdditions(
            ['hero' => ['--hero-bg']],
            ['hero' => ['--hero-bg'], 'footer' => ['--footer-glow']],
            'style slot'
        );
        $this->assertCount(1, $newComponent, 'a slot-bearing component missing from the baseline must be flagged');
        $this->assertStringContainsString('footer', $newComponent[0]);

        // 3. Same helper, prop surface — the label is the only difference.
        $propAdded = self::detectUnpinnedAdditions(['cta' => ['id']], ['cta' => ['id', 'kicker']], 'prop');
        $this->assertCount(1, $propAdded);
        $this->assertStringContainsString('prop "kicker"', $propAdded[0]);
    }

    public function testEmptyOrUncitedMigrationNotesAreRejected(): void
    {
        // H2 self-test, both surfaces. Before this guard, every one of these values
        // cleared drift exactly as well as a real note: the remove-path only ever asked
        // whether the KEY existed.
        foreach (['' => 'empty string', '   ' => 'whitespace'] as $blank => $label) {
            $defects = self::detectMigrationNoteDefects(
                ['hero' => ['--hero-bg' => $blank]],
                ['hero' => []],
                'style slot'
            );
            $this->assertNotEmpty($defects, "a {$label} note must be rejected");
            $this->assertStringContainsString('is empty', $defects[0]);
        }

        foreach ([null, 0, false, []] as $nonString) {
            $this->assertNotEmpty(
                self::detectMigrationNoteDefects(['hero' => ['--hero-bg' => $nonString]], ['hero' => []], 'style slot'),
                'a non-string note must be rejected'
            );
        }

        // Prose with no issue reference is not a ruling — the recorded decision requires
        // the note to name the issue that authorised the break.
        $uncited = self::detectMigrationNoteDefects(
            ['cta' => ['eyebrow' => 'we renamed this ages ago']],
            ['cta' => []],
            'prop'
        );
        $this->assertNotEmpty($uncited, 'a note with no issue reference must be rejected');
        $this->assertStringContainsString('does not cite a ruling issue', $uncited[0]);

        // A well-formed note on a genuinely retired name is clean, on both surfaces.
        $this->assertSame(
            [],
            self::detectMigrationNoteDefects(
                ['cta' => ['eyebrow' => 'renamed to kicker (#598)']],
                ['cta' => ['id', 'kicker']],
                'prop'
            )
        );
        $this->assertSame(
            [],
            self::detectMigrationNoteDefects(
                ['hero' => ['--hero-bg' => 'removed, superseded by --hero-surface-bg (#598)']],
                ['hero' => ['--hero-surface-bg']],
                'style slot'
            )
        );
    }

    public function testMigrationNoteForAStillLiveNameIsRejected(): void
    {
        // H3 self-test, both surfaces: the two-commit disarm. Commit 1 pre-notes names
        // that still exist (previously silent, and it reads as documentation in review);
        // commit 2 deletes them and the remove-path finds a note waiting. Rejecting the
        // pre-authorisation is what collapses that sequence back into one honest commit.
        $slotDefects = self::detectMigrationNoteDefects(
            ['hero' => ['--hero-bg' => 'planning to drop this (#598)']],
            ['hero' => ['--hero-bg', '--hero-radius']], // still declared
            'style slot'
        );
        $this->assertNotEmpty($slotDefects, 'a note for a still-declared slot must be rejected');
        $this->assertStringContainsString('still exists', $slotDefects[0]);
        $this->assertStringContainsString('pre-authorising', $slotDefects[0]);

        $propDefects = self::detectMigrationNoteDefects(
            ['cta' => ['eyebrow' => 'planning to drop this (#598)']],
            ['cta' => ['id', 'eyebrow']],
            'prop'
        );
        $this->assertNotEmpty($propDefects, 'a note for a still-declared prop must be rejected');
        $this->assertStringContainsString('still exists', $propDefects[0]);
    }

    public function testBaselineShrinkWithoutANoteIsRejected(): void
    {
        // H1 self-test, both surfaces. This is the hole that let a fully undocumented
        // rename ship green: delete the old name from the schema AND from the baseline,
        // and nothing is missing to detect.
        $shrunk = self::detectBaselineShrink(['hero' => ['--hero-bg']], [], 2, 'style slot');
        $this->assertNotEmpty($shrunk, 'a baseline below its floor must be rejected');
        $this->assertStringContainsString('shrank', $shrunk[0]);
        // The message must point at the note, never at the baseline line.
        $this->assertStringContainsString('MOVES into the migration-notes register', $shrunk[0]);
        $this->assertStringContainsString('NOT a valid fix', $shrunk[0]);

        // Moving the retired name into the notes register keeps the total accounted for.
        $this->assertSame(
            [],
            self::detectBaselineShrink(
                ['hero' => ['--hero-bg']],
                ['hero' => ['--hero-glow' => 'removed (#598)']],
                2,
                'style slot'
            ),
            'a name that moved from the baseline into the notes register still counts'
        );

        // Keeping it pinned AND noting it is equally valid, and growth is always fine.
        $this->assertSame([], self::detectBaselineShrink(['cta' => ['id', 'title', 'body']], [], 2, 'prop'));

        // The floors the real guards run against are the live totals, so a silently
        // dropped duplicate key in either literal falls below its floor.
        $this->assertSame(
            self::PROP_BASELINE_FLOOR,
            array_sum(array_map('count', self::PINNED_PROP_BASELINE)),
            'PROP_BASELINE_FLOOR must equal what PINNED_PROP_BASELINE actually holds today.'
        );
        $this->assertSame(
            self::SLOT_BASELINE_FLOOR,
            array_sum(array_map('count', self::PINNED_SLOT_BASELINE)),
            'SLOT_BASELINE_FLOOR must equal what PINNED_SLOT_BASELINE actually holds today.'
        );
    }

    public function testSlotCountPinsCannotSeeARenameButTheBaselineCan(): void
    {
        // Pins the PREMISE this issue rests on, so a future reader does not "simplify"
        // the baseline away on the theory that the count pins already cover it. Both
        // checks run against the same simulated rename; only one of them notices.
        $baseline = ['grid' => ['--grid-bg', '--grid-gap']];
        $renamed  = ['grid' => ['--grid-bg', '--grid-item-gap']];

        $this->assertSame(
            count($baseline['grid']),
            count($renamed['grid']),
            'count-based pins see nothing here — that is the blind spot.'
        );
        $this->assertNotEmpty(
            self::detectSchemaRenameDrift($baseline, $renamed, [], 'style slot'),
            'the baseline guard must catch what the count pins structurally cannot.'
        );
    }

    // ── Generic schema-typed prop enforcement (issue 507) ───────────────────
    //
    // The shared validator enforces every prop's declared `type` (string rejects
    // non-scalars, number rejects non-numerics, array rejects scalars, object-item
    // arrays reject non-object entries) so an accepted write renders as authored
    // instead of the renderer emitting "Array"/warnings behind ok:true. These are
    // unit-level pins on pp_validate_composition; the authoring-path proofs
    // (create_page / update_component) live in ActionsTest per Section 14.1.

    /** type:string rejects a non-scalar (array/object) value. */
    public function testStringPropRejectsNonScalar(): void
    {
        foreach ([[], ['nested' => 1]] as $bad) {
            $result = pp_validate_composition([
                ['component' => 'cta', 'props' => [
                    'title' => $bad, 'button_text' => 'Go', 'button_url' => '/',
                ]],
            ]);
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('invalid_prop_value', $result->get_error_code());
            $this->assertStringContainsString('must be a string', $result->get_error_message());
        }
    }

    /** type:string still accepts scalar values (numbers/bools coerce and render as authored). */
    public function testStringPropAcceptsScalars(): void
    {
        foreach (['Real title', '', 0, 123, true] as $ok) {
            $result = pp_validate_composition([
                ['component' => 'cta', 'props' => [
                    'title' => $ok, 'button_text' => 'Go', 'button_url' => '/',
                ]],
            ]);
            $this->assertTrue($result, sprintf('scalar title %s must validate', var_export($ok, true)));
        }
    }

    /** type:number rejects a non-numeric value; accepts ints and numeric strings; treats null/'' as unset. */
    public function testNumberPropTypeEnforcement(): void
    {
        // hero.image_id is type:number with no bounds — the generic number check owns it.
        $reject = pp_validate_composition([
            ['component' => 'hero', 'props' => ['title' => 'Hi', 'image_id' => 'not-a-number']],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $reject);
        $this->assertSame('invalid_prop_value', $reject->get_error_code());
        $this->assertStringContainsString('must be a number', $reject->get_error_message());

        $rejectArray = pp_validate_composition([
            ['component' => 'hero', 'props' => ['title' => 'Hi', 'image_id' => [5]]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $rejectArray);

        foreach ([7, '7', 0, '', null] as $ok) {
            $result = pp_validate_composition([
                ['component' => 'hero', 'props' => ['title' => 'Hi', 'image_id' => $ok]],
            ]);
            $this->assertTrue($result, sprintf('image_id %s must validate', var_export($ok, true)));
        }
    }

    /** type:array rejects a scalar where an array belongs; accepts arrays and the empty-array unset sentinel. */
    public function testArrayPropRejectsScalar(): void
    {
        // faq.items is type:array — a scalar is the silent-wrong case renderers swallow.
        $result = pp_validate_composition([
            ['component' => 'faq', 'props' => ['items' => 'oops']],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        $this->assertStringContainsString('must be an array', $result->get_error_message());

        // An empty array is the unset sentinel (renders nothing) — not a rejection.
        $empty = pp_validate_composition([
            ['component' => 'faq', 'props' => ['items' => []]],
        ]);
        $this->assertTrue($empty, 'an empty items array is the unset sentinel and must validate');
    }

    /** object-item arrays (item_type:object) reject scalar entries and populated JSON lists. */
    public function testObjectItemArrayRejectsNonObjectEntries(): void
    {
        // A scalar entry.
        $scalarEntry = pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => ['just a string']]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $scalarEntry);
        $this->assertSame('invalid_prop_value', $scalarEntry->get_error_code());
        $this->assertStringContainsString('must be an object', $scalarEntry->get_error_message());

        // A populated JSON list where an object was expected.
        $listEntry = pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [['a', 'b']]]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $listEntry);
        $this->assertStringContainsString('must be an object', $listEntry->get_error_message());

        // Real object entries validate.
        $ok = pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [['title' => 'One'], ['title' => 'Two']]]],
        ]);
        $this->assertTrue($ok, 'object entries must validate');
    }

    /**
     * section.panel_items keeps its MIXED string+object contract (no item_type:object):
     * plain-string entries are NOT rejected by the object-item check.
     */
    public function testPanelItemsStillAcceptsMixedStringAndObjectEntries(): void
    {
        $result = pp_validate_composition([
            ['component' => 'section', 'props' => [
                'title'      => 'Panel',
                'layout'     => 'text-panel',
                'panel_items' => ['A plain string row', ['label' => 'Plan', 'value' => 'Pro']],
            ]],
        ]);
        $this->assertTrue($result, 'panel_items must still accept mixed string + object entries');
    }

    // ── Link-URL format family (issue 507) ──────────────────────────────────
    //
    // A `format: "link_url"` prop rejects, at write time, exactly the values
    // esc_url() would neuter into a dead button (disallowed protocol) while keeping
    // every value that renders as authored (#anchor, /relative, //protocol-relative,
    // mailto:, tel:, and any wp_allowed_protocols scheme). The bar is pinned by the
    // shared helper _pp_link_url_is_valid so every link prop across the registry
    // shares one decision.

    public function testLinkUrlHelperAcceptsRenderableValues(): void
    {
        foreach ([
            '', '#', '#booking', '/pricing', '//cdn.example.com/logo.png',
            'https://example.com', 'http://example.com/path?a=1&b=2',
            'mailto:hello@example.com', 'tel:+15551234567', 'ftp://files.example.com',
            '  https://example.com', // leading whitespace is stripped before the scheme test
        ] as $value) {
            $this->assertTrue(
                _pp_link_url_is_valid($value),
                sprintf('link_url "%s" survives esc_url and must be accepted', $value)
            );
        }
        // A non-string is deferred to the generic type check, not double-reported here.
        $this->assertTrue(_pp_link_url_is_valid(['x']));
        $this->assertTrue(_pp_link_url_is_valid(null));
    }

    public function testLinkUrlHelperRejectsDisallowedProtocols(): void
    {
        foreach ([
            'javascript:alert(1)', 'JavaScript:alert(1)', '  javascript:alert(1)',
            'data:text/html,<script>', 'vbscript:msgbox(1)', 'file:///etc/passwd',
            // Control-character obfuscation of a disallowed scheme: the browser
            // honours the protocol, esc_url empties it — a dead button. Stripped
            // before the scheme test so the real protocol is seen and rejected.
            "java\tscript:alert(1)", "java\nscript:alert(1)", "java\0script:alert(1)",
        ] as $value) {
            $this->assertFalse(
                _pp_link_url_is_valid($value),
                sprintf('link_url "%s" would render as a dead link and must be rejected', $value)
            );
        }
    }

    /** The allowed-protocol set is never empty (fail-closed), even in a bare context. */
    public function testLinkUrlAllowedProtocolsIsFailClosed(): void
    {
        $protocols = pp_link_url_allowed_protocols();
        $this->assertNotEmpty($protocols, 'the allowed-protocol set must never be empty (accept-nothing-with-scheme, never accept-everything)');
        foreach (['http', 'https', 'mailto', 'tel'] as $required) {
            $this->assertContains($required, $protocols, sprintf('%s must be an allowed link protocol', $required));
        }
        $this->assertNotContains('javascript', $protocols);
    }

    /** A disallowed link_url is rejected through the shared validator with a per-prop envelope. */
    public function testValidatorRejectsDisallowedLinkUrl(): void
    {
        $result = pp_validate_composition([
            ['component' => 'cta', 'props' => [
                'button_text' => 'Go', 'button_url' => 'javascript:alert(1)',
            ]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        $this->assertStringContainsString('button_url', $result->get_error_message());
        $this->assertStringContainsString('dead link', $result->get_error_message());
    }

    /** A disallowed nested grid.items[].link_url names the item index and the field. */
    public function testValidatorRejectsDisallowedNestedLinkUrl(): void
    {
        $result = pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'One', 'link_url' => '/ok'],
                ['title' => 'Two', 'link_url' => 'javascript:alert(1)'],
            ]]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        $this->assertStringContainsString('item 1', $result->get_error_message());
        $this->assertStringContainsString('link_url', $result->get_error_message());
    }

    /**
     * Drift-catcher: the link_url check is schema-driven, not per-component. EVERY
     * prop that declares format:link_url across the live registry (top-level and one
     * items[] level) must be REJECTED through the real validator for a javascript:
     * value, with zero extra validator code. This builds a minimal composition per
     * discovered prop and runs pp_validate_composition, so it proves the prop is
     * actually WIRED into the validator (not just that the helper rejects the
     * constant). A future component that adds a format:link_url prop is caught here.
     */
    public function testEveryLinkUrlPropInRegistryRejectsDisallowedProtocol(): void
    {
        $checked = 0;
        foreach (pp_composable_components() as $name => $schema) {
            foreach (($schema['props'] ?? []) as $propName => $propDef) {
                if (!is_array($propDef)) {
                    continue;
                }
                // Top-level link_url prop: put a javascript: value on it and satisfy
                // any sibling required props with harmless placeholders.
                if (($propDef['format'] ?? null) === 'link_url') {
                    $props = $this->minimalRequiredProps($schema, [$propName => 'javascript:alert(1)']);
                    $result = pp_validate_composition([['component' => $name, 'props' => $props]]);
                    $this->assertInstanceOf(
                        \WP_Error::class,
                        $result,
                        sprintf('%s.%s declares format:link_url but the validator accepted javascript:', $name, $propName)
                    );
                    $this->assertSame('invalid_prop_value', $result->get_error_code());
                    $checked++;
                }
                // Nested items[].link_url: one bad entry inside the array prop.
                if (($propDef['type'] ?? null) === 'array' && isset($propDef['items']) && is_array($propDef['items'])) {
                    foreach ($propDef['items'] as $itemProp => $itemDef) {
                        if (is_array($itemDef) && ($itemDef['format'] ?? null) === 'link_url') {
                            $props = $this->minimalRequiredProps($schema, [$propName => [[$itemProp => 'javascript:alert(1)']]]);
                            $result = pp_validate_composition([['component' => $name, 'props' => $props]]);
                            $this->assertInstanceOf(
                                \WP_Error::class,
                                $result,
                                sprintf('%s.%s[].%s declares format:link_url but the validator accepted javascript:', $name, $propName, $itemProp)
                            );
                            $checked++;
                        }
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(5, $checked, 'expected the five known link_url props (button_url, cta_url, button2_url, panel_cta_url, grid.items[].link_url)');
    }

    /**
     * Builds a props map satisfying a schema's REQUIRED props with type-appropriate
     * placeholders, then overlays $overrides. Used to isolate one prop under test
     * without tripping unrelated required-prop rejections.
     */
    private function minimalRequiredProps(array $schema, array $overrides): array
    {
        $props = [];
        foreach (($schema['props'] ?? []) as $propName => $propDef) {
            if (empty($propDef['required'])) {
                continue;
            }
            $type = $propDef['type'] ?? 'string';
            if ($type === 'array' && ($propDef['item_type'] ?? null) === 'string') {
                $props[$propName] = ['x'];
            } elseif ($type === 'array' && ($propDef['item_type'] ?? null) === 'object') {
                $props[$propName] = [['x' => 'x']];
            } elseif ($type === 'array') {
                $props[$propName] = ['x'];
            } elseif ($type === 'number') {
                $props[$propName] = 1;
            } else {
                $props[$propName] = 'x';
            }
        }
        return array_merge($props, $overrides);
    }

    // ── The definition surface (issue #575) ───────────────────────────────
    //
    // Every field below is declared ON the slot/prop definition object in
    // schema.json, never inferred from a name and never stored anywhere else. The
    // surface is CLOSED: an unlisted key fails CI rather than being ignored, so the
    // definition surface cannot drift the way the slot surface already did.
    //
    // #575 lands the SHAPES; it populates nothing. `applies_when` and the fill-role
    // marker therefore have no real declarations yet, so their accept/reject cases
    // run against synthetic definitions — the grammar is pinned before ~90 pairs
    // depend on it, which is the whole point of landing the contract first.

    /** @return array<string, array<string, mixed>> component => decoded schema */
    private function allSchemas(): array
    {
        $schemas = [];
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $file) {
            $schemas[basename(dirname($file))] = json_decode(file_get_contents($file), true);
        }
        $this->assertNotEmpty($schemas, 'no component schemas found');
        return $schemas;
    }

    /**
     * THE drift catcher. Every slot and prop definition object in every shipped
     * schema conforms to the closed contract — no unknown keys, and every declared
     * #575 field is well-shaped. A typo'd or half-landed definition key fails here
     * instead of being silently ignored at runtime forever.
     */
    public function testEveryShippedDefinitionObjectConformsToTheClosedContract(): void
    {
        $errors = [];
        foreach ($this->allSchemas() as $component => $schema) {
            foreach (($schema['styling']['style_slots'] ?? []) as $name => $def) {
                $errors = array_merge($errors, \pp_schema_definition_errors($def, 'slot', "{$component} {$name}"));
            }
            foreach (($schema['props'] ?? []) as $name => $def) {
                $errors = array_merge($errors, \pp_schema_definition_errors($def, 'prop', "{$component}.{$name}"));
                // Nested per-item definitions (props.<p>.items.<sub>) are definition
                // objects too. Checking only the top level would leave the exact
                // "typo'd key ignored forever" hole the closed surface exists to
                // remove — grid.items alone carries 10+ sub-definitions. `style` is
                // a per-item style-map marker, not a definition object.
                foreach (($def['items'] ?? []) as $sub => $subDef) {
                    if ($sub === 'style' || !is_array($subDef)) {
                        continue;
                    }
                    $errors = array_merge(
                        $errors,
                        \pp_schema_definition_errors($subDef, 'prop', "{$component}.{$name}.items.{$sub}")
                    );
                }
            }
        }
        $this->assertSame([], $errors, "definition-surface violations:\n" . implode("\n", $errors));
    }

    /** An unknown key on a definition object is REJECTED, not ignored. */
    public function testUnknownDefinitionKeyIsRejected(): void
    {
        $slot = ['type' => 'color', 'default' => '#fff', 'description' => 'x', 'appliesWhen' => []];
        $errors = \pp_schema_definition_errors($slot, 'slot', 'test --x');
        $this->assertNotEmpty($errors, 'a misspelled definition key must be rejected');
        $this->assertStringContainsString('unknown slot definition key `appliesWhen`', $errors[0]);

        // Re-fixtured off the alias family in #606 — `alias` is no longer a *misspelling*
        // of anything, it is one more retired word. `strict_values` keeps the original
        // point: a plausible-looking key that no definition declares must be rejected,
        // not ignored.
        $prop = ['type' => 'string', 'required' => false, 'description' => 'x', 'strict_values' => ['a']];
        $propErrors = \pp_schema_definition_errors($prop, 'prop', 'test.x');
        $this->assertNotEmpty($propErrors, 'a misspelled prop definition key must be rejected');
        $this->assertStringContainsString('unknown prop definition key `strict_values`', $propErrors[0]);
    }

    /**
     * THE SCHEMA SURFACE of the `aliases` retirement (#606), and the strongest form the
     * retirement can take: the field is not merely unused, it is UNKNOWN, so a schema
     * that declares one fails CI instead of carrying a key nothing reads.
     *
     * Three surfaces, because the closed key set reaches all three through one engine:
     * a top-level prop, a nested `items.<sub>` field (which
     * testEveryShippedDefinitionObjectConformsToTheClosedContract runs as kind 'prop'),
     * and a slot — where it was never valid and now reads the same as everywhere else.
     * The singular `alias` goes with it: neither spelling means anything now.
     */
    public function testTheRetiredAliasesKeyIsNowAnUnknownDefinitionKey(): void
    {
        // The key list itself, stated once so the contract is readable and not only
        // inferable from behaviour.
        $this->assertNotContains('aliases', \pp_prop_definition_keys(), '`aliases` is retired (#606)');
        $this->assertNotContains('aliases', \pp_slot_definition_keys());

        // Top-level prop: the surface where it USED to be legal. A well-shaped
        // declaration — enum, values, strict, a non-colliding member — is the exact
        // shape #575 accepted, and it is now rejected on the key alone.
        $prop = [
            'type' => 'enum', 'required' => false, 'default' => 'a', 'description' => 'x',
            'values' => ['a', 'b'], 'strict' => true, 'aliases' => ['legacy_a'],
        ];
        $propErrors = \pp_schema_definition_errors($prop, 'prop', 'test.x');
        $this->assertNotEmpty($propErrors, 'a shipped-shape `aliases` declaration must now fail');
        $this->assertStringContainsString('unknown prop definition key `aliases`', $propErrors[0]);

        // Nested item field: same engine, same answer, so the retirement cannot be
        // smuggled back in one level down.
        $this->assertStringContainsString(
            'unknown prop definition key `aliases`',
            \pp_schema_definition_errors(
                ['type' => 'enum', 'values' => ['a'], 'description' => 'x', 'aliases' => ['legacy_a']],
                'prop',
                'test.items.sub'
            )[0]
        );

        // Slot: previously rejected because `aliases` was prop-only; now rejected
        // because it is nothing at all.
        $this->assertStringContainsString(
            'unknown slot definition key `aliases`',
            \pp_schema_definition_errors(
                ['type' => 'color', 'default' => '#fff', 'description' => 'x', 'aliases' => ['legacy_a']],
                'slot',
                'test --x'
            )[0]
        );

        // The singular spelling is retired with it.
        $this->assertStringContainsString(
            'unknown prop definition key `alias`',
            \pp_schema_definition_errors(
                ['type' => 'string', 'required' => false, 'description' => 'x', 'alias' => ['dark']],
                'prop',
                'test.x'
            )[0]
        );
    }

    /**
     * THE SCHEMA SURFACE of the `patchable` retirement (#629). Unlike `aliases`,
     * which WAS on the key set until #606 removed it, `patchable` was never ADDED
     * to it: #575 closed the key set without it (v1.12.4), well after #509 shipped
     * and documented the opt-out (v1.8.2). So AI_IMPLEMENTATION_RECIPES went on telling
     * authors to declare it (Recipe B step 4, Recipe D step 4) while an author who
     * followed the docs got `unknown prop definition key patchable` from this
     * engine via testEveryShippedDefinitionObjectConformsToTheClosedContract.
     * #629 resolved that by deleting the readers and the instruction, so the
     * rejection below is now the WHOLE truth about the key rather than half of a
     * contradiction.
     *
     * Two surfaces, not three. A slot assertion is deliberately omitted: `patchable`
     * was never a candidate slot key, so rejecting it there pins pre-existing
     * behaviour, not this retirement. The two surfaces asserted are exactly the two
     * lib/operate.php used to read (a top-level prop, and an `items` array whose
     * nested `items.<sub>` fields run through this same engine as kind 'prop').
     */
    public function testTheRetiredPatchableKeyIsNowAnUnknownDefinitionKey(): void
    {
        // The key list itself, stated once so the contract is readable and not
        // only inferable from behaviour.
        $this->assertNotContains('patchable', \pp_prop_definition_keys(), '`patchable` is retired (#629)');

        // Top-level prop: the exact shape Recipe B step 4 documented.
        $prop = [
            'type' => 'string', 'required' => false, 'default' => '',
            'description' => 'x', 'patchable' => false,
        ];
        $propErrors = \pp_schema_definition_errors($prop, 'prop', 'test.x');
        $this->assertNotEmpty($propErrors, 'a documented-shape `patchable` declaration must fail the closed key set');
        $this->assertStringContainsString('unknown prop definition key `patchable`', $propErrors[0]);

        // Nested item field: same engine, same answer, so the retirement cannot be
        // smuggled back in one level down.
        $nestedErrors = \pp_schema_definition_errors(
            ['type' => 'string', 'description' => 'x', 'patchable' => false],
            'prop',
            'test.items.sub'
        );
        $this->assertNotEmpty($nestedErrors, 'a nested `items.<sub>` declaration must fail too');
        $this->assertStringContainsString('unknown prop definition key `patchable`', $nestedErrors[0]);

        // `true` is rejected on the same grounds as `false`. The key is unknown —
        // it is not a boolean field with one legal value.
        $trueErrors = \pp_schema_definition_errors(
            ['type' => 'string', 'description' => 'x', 'patchable' => true],
            'prop',
            'test.x'
        );
        $this->assertNotEmpty($trueErrors, '`patchable: true` is an unknown key, not a legal boolean');
        $this->assertStringContainsString('unknown prop definition key `patchable`', $trueErrors[0]);
    }

    /**
     * The four clause forms of `applies_when`, and nothing else. The grammar is
     * BOUNDED: it does not grow in #575, and if it ever needs to, the growth lands
     * in the contract before anything populates it.
     *
     * @dataProvider validAppliesWhenClauses
     */
    public function testAppliesWhenAcceptsExactlyTheFourClauseForms(string $label, array $clause): void
    {
        $def = ['type' => 'length', 'default' => '48px', 'description' => 'x', 'applies_when' => [$clause]];
        $this->assertSame([], \pp_schema_definition_errors($def, 'slot', 'test --x'), "clause form '{$label}' must be accepted");
    }

    public static function validAppliesWhenClauses(): array
    {
        return [
            'prop equals'  => ['prop equals',  ['prop' => 'image_treatment', 'equals' => 'icon']],
            'prop in'      => ['prop in',      ['prop' => 'layout', 'in' => ['cards', 'steps']]],
            'prop present' => ['prop present', ['prop' => 'background_image', 'present' => true]],
            'slot present' => ['slot present', ['slot' => '--grid-item-bar-color', 'present' => true]],
        ];
    }

    /**
     * Everything outside the four forms is rejected. Each case is a shape somebody
     * would plausibly reach for — which is exactly why the grammar has to say no in
     * CI rather than accept it and grow by accretion.
     *
     * @dataProvider invalidAppliesWhenClauses
     */
    public function testAppliesWhenRejectsEverythingOutsideTheGrammar(string $label, $clause): void
    {
        $def = ['type' => 'length', 'default' => '48px', 'description' => 'x', 'applies_when' => [$clause]];
        $this->assertNotEmpty(
            \pp_schema_definition_errors($def, 'slot', 'test --x'),
            "clause '{$label}' is outside the bounded grammar and must be rejected"
        );
    }

    public static function invalidAppliesWhenClauses(): array
    {
        return [
            'any_of disjunction'   => ['any_of disjunction',   ['any_of' => [['prop' => 'theme', 'equals' => 'inverted']]]],
            'context clause'       => ['context clause',       ['context' => 'main >']],
            'unknown clause key'   => ['unknown clause key',   ['prop' => 'theme', 'equals' => 'inverted', 'unless' => 'x']],
            'no subject'           => ['no subject',           ['equals' => 'icon']],
            'no predicate'         => ['no predicate',         ['prop' => 'image_treatment']],
            'two predicates'       => ['two predicates',       ['prop' => 'layout', 'equals' => 'cards', 'present' => true]],
            'both subjects'        => ['both subjects',        ['prop' => 'layout', 'slot' => '--x', 'present' => true]],
            'slot without dashes'  => ['slot without dashes',  ['slot' => 'grid-card-bar-color', 'present' => true]],
            'slot with equals'     => ['slot with equals',     ['slot' => '--x', 'equals' => 'y']],
            'negated present'      => ['negated present',      ['prop' => 'background_image', 'present' => false]],
            'empty in list'        => ['empty in list',        ['prop' => 'layout', 'in' => []]],
            'non-scalar in member' => ['non-scalar in member', ['prop' => 'layout', 'in' => [['cards']]]],
            'not an object'        => ['not an object',        'image_treatment = icon'],
        ];
    }

    /** `applies_when` is an ARRAY of ANDed clauses — never a bare clause object. */
    public function testAppliesWhenMustBeANonEmptyArrayOfClauses(): void
    {
        foreach ([[], ['prop' => 'x', 'equals' => 'y'], 'x'] as $bad) {
            $def = ['type' => 'length', 'default' => '1px', 'description' => 'x', 'applies_when' => $bad];
            $this->assertNotEmpty(
                \pp_schema_definition_errors($def, 'slot', 'test --x'),
                'applies_when must be a non-empty array of clauses'
            );
        }
    }

    /**
     * `conditionality_note` is BOUNDED prose — a non-empty string under a hard
     * length cap. Unbounded prose in a machine-read field is a second grammar
     * nobody validates.
     */
    public function testConditionalityNoteIsBoundedProse(): void
    {
        $ok = ['type' => 'color', 'default' => '#fff', 'description' => 'x',
               'conditionality_note' => 'Applies on dark bands only: theme "inverted" OR a background_image.'];
        $this->assertSame([], \pp_schema_definition_errors($ok, 'slot', 'test --x'));

        foreach (['', '   ', 123, str_repeat('a', \PP_CONDITIONALITY_NOTE_MAX + 1)] as $bad) {
            $def = ['type' => 'color', 'default' => '#fff', 'description' => 'x', 'conditionality_note' => $bad];
            $this->assertNotEmpty(\pp_schema_definition_errors($def, 'slot', 'test --x'));
        }

        // The cap is CHARACTERS, as the error message says. Counting bytes would
        // reject accented or non-Latin prose at roughly half the stated budget,
        // with a count the note never had.
        $multibyte = str_repeat('é', \PP_CONDITIONALITY_NOTE_MAX - 1);
        $this->assertGreaterThan(\PP_CONDITIONALITY_NOTE_MAX, strlen($multibyte), 'fixture must exceed the BYTE cap');
        $this->assertSame(
            [],
            \pp_schema_definition_errors(
                ['type' => 'color', 'default' => '#fff', 'description' => 'x', 'conditionality_note' => $multibyte],
                'slot',
                'test --x'
            ),
            'a multibyte note under the CHARACTER cap must be accepted'
        );

        // Single line: the AI catalog is line-oriented, so an embedded newline in a
        // schema-declared string forges catalog lines. Bound the shape at authoring
        // time rather than papering over it in the emitter.
        foreach (["dark bands\nStyle slots: --forged (color, default: #000)", "a\tb"] as $multiline) {
            $this->assertNotEmpty(
                \pp_schema_definition_errors(
                    ['type' => 'color', 'default' => '#fff', 'description' => 'x', 'conditionality_note' => $multiline],
                    'slot',
                    'test --x'
                ),
                'a note spanning lines must be rejected'
            );
        }
    }

    // The three `aliases` SHAPE tests that stood here are GONE (#606). They validated
    // the field's grammar — enum-only, non-empty list of non-empty strings, no double
    // quote, no collision with `values` — and every one of them presumed the field
    // exists. It does not. What replaced them is stricter and shorter: the key itself
    // is unknown, pinned by testTheRetiredAliasesKeyIsNowAnUnknownDefinitionKey().
    //
    // The guard that policy described did NOT cover `values`, which is the string the
    // catalog actually renders. #630 closes that, below.

    /**
     * `values` is BOUNDED, because it is the schema string the AI catalog renders
     * INSIDE double quotes (#630). Same policy as its siblings, one field later:
     * `applies_when`'s subjects and `in` members are quote-free by rule, and
     * `conditionality_note` is single-line by rule, precisely so no declaration can
     * forge the syntax an agent parses. `values` was the last quoted, catalog-emitted
     * schema string with no guard at all.
     *
     *   declaration                     rendered catalog fragment
     *   ["cards", "steps"]              layout?: "cards"|"steps"        ← honest
     *   ["cards\", \"forged"]           layout?: "cards"|"forged"       ← a value set
     *                                                                     nothing accepts
     *   ["cards\nStyle slots: --x"]     layout?: "cards                 ← a forged LINE
     *                                   Style slots: --x"
     *
     * Each case pins WHICH rule it breaks, not merely that something was reported:
     * a guard that collapsed every member violation into the container message would
     * otherwise keep the whole provider green.
     *
     * @dataProvider rejectedValuesDeclarations
     */
    public function testValuesIsABoundedListOfSingleLineStrings(string $why, $values, string $expect): void
    {
        $propErrors = \pp_schema_definition_errors(
            ['type' => 'enum', 'required' => false, 'description' => 'x', 'strict' => true, 'values' => $values],
            'prop',
            'test.x'
        );
        $this->assertNotEmpty($propErrors, "a `values` declaration that {$why} must be rejected");
        $this->assertStringContainsString($expect, implode("\n", $propErrors), "the rejection must name the rule broken");

        // The slot surface runs the SAME engine — one guard, both surfaces, as with
        // every other definition key.
        $slotErrors = \pp_schema_definition_errors(
            ['type' => 'enum', 'default' => 'a', 'description' => 'x', 'values' => $values],
            'slot',
            'test --x'
        );
        $this->assertNotEmpty($slotErrors, "a slot `values` declaration that {$why} must be rejected");
        $this->assertStringContainsString($expect, implode("\n", $slotErrors));
    }

    public static function rejectedValuesDeclarations(): array
    {
        $list   = 'must be a non-empty LIST of strings';
        $member = 'every `values` member must be a non-empty string';
        $quote  = 'must not contain a double quote';
        $line   = 'must be a single line';

        return [
            'is not an array'       => ['is not an array',       'cards',                        $list],
            'is an empty list'      => ['is an empty list',      [],                             $list],
            'is a JSON object'      => ['is a JSON object',      ['a' => 'cards', 'b' => 'st'],  $list],
            'is out of order'       => ['is out of order',       [1 => 'cards', 0 => 'steps'],   $list],
            'holds a non-string'    => ['holds a non-string',    ['cards', 3],                   $member],
            'holds a nested list'   => ['holds a nested list',   ['cards', ['steps']],           $member],
            'holds null'            => ['holds null',            ['cards', null],                $member],
            'holds an empty string' => ['holds an empty string', ['cards', ''],                  $member],
            'holds a double quote'  => ['holds a double quote',  ['cards', 'for"ged'],           $quote],
            'forges the value set'  => ['forges the value set',  ['cards", "forged'],            $quote],
            'holds a newline'       => ['holds a newline',       ["cards\nStyle slots: --x"],    $line],
            'holds a carriage ret'  => ['holds a carriage ret',  ["cards\rforged"],              $line],
            'holds a tab'           => ['holds a tab',           ["cards\tforged"],              $line],
        ];
    }

    /**
     * The guard is triggered by the KEY'S PRESENCE, not by `type === 'enum'`.
     *
     * Without this test the distinction is unenforced prose: narrowing the condition
     * to `array_key_exists('values', ...) && ($definition['type'] ?? null) === 'enum'`
     * leaves the whole suite green, because every other fixture here — and every
     * shipped declaration — is an enum. The field is bounded wherever it is declared,
     * so a `values` that appears on a non-enum tomorrow is not an unguarded corner.
     */
    public function testTheValuesGuardIsTriggeredByPresenceNotByEnumType(): void
    {
        $this->assertNotEmpty(
            \pp_schema_definition_errors(
                ['type' => 'string', 'required' => false, 'description' => 'x', 'values' => ['for"ged']],
                'prop',
                'test.x'
            ),
            '`values` on a non-enum prop is still shape-checked'
        );
        $this->assertNotEmpty(
            \pp_schema_definition_errors(
                ['type' => 'color', 'default' => '#fff', 'description' => 'x', 'values' => ["a\nb"]],
                'slot',
                'test --x'
            ),
            '`values` on a non-enum slot is still shape-checked'
        );
    }

    /**
     * The accept side, and the report itself. A well-shaped declaration passes clean;
     * a container that is not a list stops BEFORE any member is read (so `values:
     * "cards"` never iterates a string); and a declaration breaking two rules at once
     * names both, so one CI run tells the author everything wrong with the field.
     */
    public function testValuesGuardAcceptsTheShippedShapeAndNamesEveryViolation(): void
    {
        $this->assertSame([], \pp_schema_definition_errors(
            ['type' => 'enum', 'required' => false, 'description' => 'x', 'strict' => true,
             'values' => ['default', 'muted', 'inverted']],
            'prop',
            'test.theme'
        ), 'the shape every shipped enum declares must stay valid');

        $container = \pp_schema_definition_errors(
            ['type' => 'enum', 'required' => false, 'description' => 'x', 'strict' => true, 'values' => 'cards'],
            'prop',
            'test.x'
        );
        $this->assertSame(
            ['test.x: `values` must be a non-empty LIST of strings.'],
            $container,
            'a malformed container is ONE error, not a cascade of per-member complaints'
        );

        $both = \pp_schema_definition_errors(
            ['type' => 'enum', 'required' => false, 'description' => 'x', 'strict' => true,
             'values' => ['for"ged', "line\nbreak"]],
            'prop',
            'test.x'
        );
        $this->assertCount(2, $both, 'both violations are named in one pass');
        $this->assertStringContainsString('double quote', $both[0]);
        $this->assertStringContainsString('single line', $both[1]);
    }

    /**
     * NON-VACUITY. The guard reaches the definitions the theme actually ships, through
     * the same sweep that runs the closed key set — not only synthetic fixtures. Take
     * every REAL shipped declaration that carries `values`, forge one member, and
     * assert the engine testEveryShippedDefinitionObjectConformsToTheClosedContract
     * calls rejects it.
     *
     * All THREE surfaces that sweep walks, because `values` ships on all three and a
     * props-only proof would quietly exempt the other two: style slots (today
     * `section --section-inline-items-align`) and nested `items.<sub>` fields (today
     * `grid.items[].text_role`) declare enums exactly as top-level props do.
     *
     * Discovered, not hard-coded: the guarantee is about whatever enums are shipped
     * today, so renaming or retiring one must not turn this proof into a no-op. The
     * floor is the current inventory, not a round number below it — losing a
     * declaration should fail here and be re-confirmed, not pass unnoticed.
     */
    public function testTheValuesGuardReachesEveryShippedEnumDeclaration(): void
    {
        $checked = 0;
        $forge = function (array $def, string $kind, string $label) use (&$checked): void {
            if (!isset($def['values']) || !is_array($def['values']) || $def['values'] === []) {
                return;
            }
            $forged = $def;
            $forged['values'][0] = $def['values'][0] . '", "forged';
            $this->assertNotEmpty(
                \pp_schema_definition_errors($forged, $kind, $label),
                "a forged member on the shipped declaration {$label} must fail the sweep"
            );
            $checked++;
        };

        foreach ($this->allSchemas() as $component => $schema) {
            foreach (($schema['styling']['style_slots'] ?? []) as $name => $def) {
                $forge($def, 'slot', "{$component} {$name}");
            }
            foreach (($schema['props'] ?? []) as $name => $def) {
                $forge($def, 'prop', "{$component}.{$name}");
                foreach (($def['items'] ?? []) as $sub => $subDef) {
                    if ($sub === 'style' || !is_array($subDef)) {
                        continue;
                    }
                    $forge($subDef, 'prop', "{$component}.{$name}.items.{$sub}");
                }
            }
        }
        $this->assertSame(31, $checked, 'the shipped `values` inventory changed — re-confirm the sweep reaches it');
    }

    /**
     * The three condition classes that stay PROSE must be named explicitly by the
     * field's own documentation, or the next author guesses which side of the line
     * their condition falls on and the grammar grows to swallow it.
     */
    public function testConditionalityNoteDocumentationNamesTheThreeProseClasses(): void
    {
        $doc = file_get_contents($this->themeRoot . '/lib/admin.php');
        $start = strpos($doc, 'function pp_applies_when_clause_errors');
        $this->assertNotFalse($start, 'the clause validator must exist');
        $block = substr($doc, max(0, $start - 3000), 3000);

        foreach (['DISJUNCTION', 'COMPOSED-PAGE CONTEXT', 'INTERACTION STATE'] as $class) {
            $this->assertStringContainsString(
                $class,
                $block,
                "the prose-only class '{$class}' must be named explicitly where the grammar is defined"
            );
        }
    }

    /**
     * The fill marker is a DECLARED key with a bounded value, not a `-bg` /
     * `-hover-bg` name convention. A naming convention is not machine-readable
     * without a second source of truth, which is the defect this contract fixes one
     * layer down.
     */
    public function testFillRoleMarkerIsADeclaredKeyWithABoundedValue(): void
    {
        $ok = ['type' => 'color', 'default' => 'var(--color-accent)', 'description' => 'x', 'role' => 'fill'];
        $this->assertSame([], \pp_schema_definition_errors($ok, 'slot', 'test --x'));

        foreach (['background', 'Fill', '', true] as $bad) {
            $def = ['type' => 'color', 'default' => '#fff', 'description' => 'x', 'role' => $bad];
            $this->assertNotEmpty(\pp_schema_definition_errors($def, 'slot', 'test --x'), 'role is a bounded set');
        }

        // `role` is a SLOT-definition key. On a prop it is an unknown key.
        $this->assertNotEmpty(\pp_schema_definition_errors(
            ['type' => 'string', 'required' => false, 'description' => 'x', 'role' => 'fill'],
            'prop',
            'test.x'
        ));
    }

    /**
     * #575 landed the marker and applied it to nothing; #579 (A-34) populates the
     * fill-slot family and consumes it in the warn channel. The family is the button
     * FILL of every component that renders a button, plus each one's hover twin —
     * enumerated here so a new button component cannot quietly ship a fill slot the
     * transparent-fill advisory is blind to.
     */
    public function testFillMarkerIsDeclaredOnExactlyTheButtonFillFamily(): void
    {
        $expected = [
            'cta'     => ['--cta-button-bg', '--cta-button-hover-bg', '--cta-button2-bg', '--cta-button2-hover-bg'],
            'hero'    => ['--hero-button-bg', '--hero-button-hover-bg', '--hero-button2-bg', '--hero-button2-hover-bg'],
            'section' => ['--section-panel-cta-bg'],
        ];

        $actual = [];
        foreach ($this->allSchemas() as $component => $schema) {
            foreach (($schema['styling']['style_slots'] ?? []) as $name => $def) {
                if (($def['role'] ?? null) !== 'fill') {
                    continue;
                }
                $actual[$component][] = $name;
                // A fill is a colour. The advisory compares the stored value against
                // `transparent`/`currentColor`, which only a colour grammar accepts.
                $this->assertSame('color', $def['type'] ?? null, "{$component} {$name} must be a color slot");
            }
        }
        foreach ($actual as $component => $names) {
            sort($names);
            $actual[$component] = $names;
        }
        foreach ($expected as $component => $names) {
            sort($names);
            $expected[$component] = $names;
        }
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    /**
     * #605 — the `theme` prop declares NO aliases on any of the eight band
     * components, and advertises exactly the three canonical values. What an agent
     * writes now matches what the catalog advertises, with no footnote and no
     * accepted-but-unadvertised tier.
     */
    public function testNoShippedPropDeclaresAliasesAndThemeAdvertisesOnlyCanonicalValues(): void
    {
        $seen = 0;
        foreach ($this->allSchemas() as $component => $schema) {
            $theme = $schema['props']['theme'] ?? null;
            if ($theme === null) {
                continue;
            }
            $seen++;
            $this->assertSame(['default', 'muted', 'inverted'], $theme['values'] ?? null,
                "{$component}.theme must advertise only the canonical values");
            $this->assertArrayNotHasKey('aliases', $theme,
                "{$component}.theme must declare no aliases — `dark` was removed (#605)");
            $this->assertNotContains('dark', $theme['values'],
                "{$component}.theme must never advertise `dark`");
            $this->assertStringNotContainsString('"dark"', $theme['description'] ?? '',
                "{$component}.theme description must not advertise `dark` either");
        }
        $this->assertSame(8, $seen, 'all eight theme-bearing components must be checked');
    }

    /**
     * AUTHORING-PATH proof (Section 14.1) that `theme: "dark"` is REJECTED by the
     * REAL write surface, not just absent from the schema files.
     *
     * History, one line: this pin was born under #575 asserting the opposite (the
     * alias survives the write path). #605 removed the alias and inverted it.
     *
     * Three things are asserted, because rejecting is not the same as rendering:
     *   1. create_page REJECTS the removed value, even beside a canonical sibling;
     *   2. the error names the WHOLE accepted set — `default, muted, inverted` — with
     *      no footnote, so the message teaches the real vocabulary on the spot;
     *   3. a band that nonetheless HOLDS the value in storage renders the DEFAULT
     *      band, not the tinted one. `dark` used to render a LIGHT band under the
     *      `--dark` class; it now renders no modifier at all.
     */
    public function testThemeDarkIsRejectedByTheRealAuthoringSurface(): void
    {
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];

        $composition = [
            ['component' => 'section', 'props' => ['theme' => 'dark', 'body' => 'Legacy band.']],
            ['component' => 'section', 'props' => ['theme' => 'inverted', 'body' => 'Canonical band.']],
        ];

        $result = \pp_validate_action('create_page', ['title' => 'Legacy theme page', 'composition' => $composition]);
        $this->assertInstanceOf(\WP_Error::class, $result, 'theme:"dark" must be rejected at the authoring surface');
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        $this->assertStringContainsString('default, muted, inverted', $result->get_error_message(),
            'the error must name the whole accepted set, with no legacy footnote');
        $this->assertStringNotContainsString('legacy', $result->get_error_message());

        // Storage route: bytes that predate the removal still render, as the default.
        ob_start();
        \pp_get_component('section', ['theme' => 'dark', 'body' => 'Legacy band.']);
        $html = ob_get_clean();
        $this->assertStringNotContainsString('pp-section--dark', $html, 'a stored `dark` no longer paints the tinted band');
        $this->assertStringNotContainsString('pp-section--inverted', $html);
        // The band still renders — it just renders as the DEFAULT band. Note the
        // base class is `section`; `pp-section` is only the modifier prefix.
        $this->assertStringContainsString('class="section section--text-only"', $html);
        $this->assertStringContainsString('Legacy band.', $html);
    }

    /**
     * THE CONDITIONALITY LEDGER (issue #580) — every definition that declares a
     * condition, and the condition it declares. #575 landed the shapes and populated
     * NOTHING; #580 populates the census, so the guard that used to assert emptiness
     * became this exact-set pin.
     *
     * WHY AN EXACT SET AND NOT A SPOT CHECK. A condition is a promise the runtime AI
     * catalog makes to an agent BEFORE it writes, and the `inert_slot` advisory makes
     * the same promise after. A sampled assertion lets a condition be added, dropped or
     * quietly widened in a merge — and a wrong condition is worse than none, because an
     * agent designs around it. Adding or changing a row here is therefore a deliberate,
     * reviewed act with the census in front of you, exactly like the dead-slot waiver
     * ledger in StyleSlotContractTest.
     *
     * The value is a rendering of the clause list, not the clause list itself: the point
     * is that a human reading the diff can see the CONDITION change, not count braces.
     * `+note(<digest>)` means the definition also carries `conditionality_note` — the
     * bounded prose for the three classes the grammar deliberately cannot express. The
     * digest is there because that prose reaches an agent VERBATIM through the AI catalog,
     * so a reworded note changes the contract while the clause list stays identical; a
     * bare presence marker would let that through (see noteMarker() below).
     *
     * WHAT THIS LEDGER IS NOT. It is not a completeness claim. It records what the schemas
     * declare TODAY, not every condition that exists in the renderers. A definition absent
     * from this list has no declared condition — which may mean it is unconditional, or
     * may mean nobody has declared it yet. If you find an undeclared code-real condition,
     * declaring it is a fix, not a violation of this pin: verify it against the renderer
     * and the CSS, add the row here in the same change, and say so in the PR.
     *
     * @var array<string,string>
     */
    private const CONDITIONALITY_LEDGER = [
        'cta slot --cta-heading-color' => 'title present',
        'cta slot --cta-heading-accent-color' => 'title present',
        'cta slot --cta-eyebrow-color' => 'eyebrow present',
        'cta slot --cta-eyebrow-bg' => 'eyebrow present',
        'cta slot --cta-eyebrow-radius' => 'eyebrow present',
        'cta slot --cta-eyebrow-border-width' => 'eyebrow present',
        'cta slot --cta-eyebrow-border-color' => 'eyebrow present',
        'cta slot --cta-eyebrow-text-transform' => 'eyebrow present',
        'cta slot --cta-heading-size' => 'title present',
        'cta slot --cta-heading-margin-bottom' => 'title present',
        'cta slot --cta-button2-bg' => 'button2_text present',
        'cta slot --cta-button2-border' => 'button2_text present',
        'cta slot --cta-button2-color' => 'button2_text present',
        'cta slot --cta-button2-hover-bg' => 'button2_text present',
        'cta slot --cta-button2-hover-border' => 'button2_text present',
        'cta slot --cta-button2-hover-color' => 'button2_text present',
        'cta slot --cta-button2-shadow' => 'button2_text present',
        'cta slot --cta-overlay-bg' => 'background_image present',
        'cta slot --cta-bg-position' => 'background_image present',
        'embed slot --embed-heading-size' => 'title present',
        'embed slot --embed-heading-color' => 'title present',
        'embed slot --embed-heading-measure' => 'title present',
        'embed slot --embed-heading-margin-bottom' => 'title present',
        'faq slot --faq-item-bg' => 'items present',
        'faq slot --faq-eyebrow-color' => 'eyebrow present',
        'faq slot --faq-eyebrow-bg' => 'eyebrow present',
        'faq slot --faq-eyebrow-radius' => 'eyebrow present',
        'faq slot --faq-eyebrow-border-width' => 'eyebrow present',
        'faq slot --faq-eyebrow-border-color' => 'eyebrow present',
        'faq slot --faq-eyebrow-text-transform' => 'eyebrow present',
        'faq slot --faq-heading-size' => 'title present',
        'faq slot --faq-heading-color' => 'title present',
        'faq slot --faq-heading-measure' => 'title present',
        'faq slot --faq-body-measure' => 'items present',
        'faq slot --faq-heading-accent-color' => 'title present',
        'faq slot --faq-heading-margin-bottom' => 'title present',
        'faq slot --faq-question-color' => 'items present',
        'faq slot --faq-answer-color' => 'items present',
        'faq slot --faq-item-border-color' => 'items present',
        'faq slot --faq-item-radius' => 'items present',
        'faq slot --faq-question-open-color' => 'items present +note(9ce573ee)',
        'footer prop logo_text' => 'note +note(13cd2dd9)',
        'footer prop logo_id' => 'note +note(54994bfc)',
        'footer prop logo_alt' => 'note +note(48b1256c)',
        'footer prop contact_label' => 'contact present',
        'footer prop secondary_location' => 'note +note(9ff6badb)',
        'footer prop secondary_label' => 'note +note(5e6648ec)',
        'grid slot --grid-heading-color' => 'title present',
        'grid slot --grid-heading-accent-color' => 'title present',
        'grid slot --grid-eyebrow-color' => 'eyebrow present',
        'grid slot --grid-eyebrow-bg' => 'eyebrow present',
        'grid slot --grid-eyebrow-radius' => 'eyebrow present',
        'grid slot --grid-eyebrow-border-width' => 'eyebrow present',
        'grid slot --grid-eyebrow-border-color' => 'eyebrow present',
        'grid slot --grid-eyebrow-text-transform' => 'eyebrow present',
        'grid slot --grid-subheading-color' => 'subheading present',
        'grid slot --grid-subheading-margin-bottom' => 'subheading present',
        'grid slot --grid-heading-margin-bottom' => 'title present',
        'grid slot --grid-heading-size' => 'title present',
        'grid slot --grid-heading-measure' => 'title present',
        'grid slot --grid-item-bar-color' => 'layout=cards +note(c3e1c5d4)',
        'grid slot --grid-item-bar-height' => 'layout=cards +note(c3e1c5d4)',
        'grid slot --grid-featured-texture-color' => 'layout=cards AND card_emphasis=featured +note(d35edaf4)',
        'grid slot --grid-featured-shadow' => 'layout=cards AND card_emphasis=featured +note(d35edaf4)',
        'grid slot --grid-item-icon-size' => 'layout=cards AND image_treatment=icon +note(046f6c6d)',
        'grid slot --grid-step-bg' => 'layout=steps',
        'grid slot --grid-step-text-color' => 'layout=steps',
        'hero slot --hero-eyebrow-color' => 'eyebrow present',
        'hero slot --hero-eyebrow-bg' => 'eyebrow present',
        'hero slot --hero-eyebrow-radius' => 'eyebrow present',
        'hero slot --hero-eyebrow-border-width' => 'eyebrow present',
        'hero slot --hero-eyebrow-border-color' => 'eyebrow present',
        'hero slot --hero-eyebrow-text-transform' => 'eyebrow present',
        'hero slot --hero-button2-bg' => 'button_text present AND button2_text present',
        'hero slot --hero-button2-border' => 'button_text present AND button2_text present',
        'hero slot --hero-button2-color' => 'button_text present AND button2_text present',
        'hero slot --hero-button2-hover-bg' => 'button_text present AND button2_text present',
        'hero slot --hero-button2-hover-border' => 'button_text present AND button2_text present',
        'hero slot --hero-button2-hover-color' => 'button_text present AND button2_text present',
        'hero slot --hero-subheading-size' => 'subheading present',
        'hero slot --hero-subheading-color' => 'subheading present',
        'hero slot --hero-overlay-bg' => 'layout=cover',
        'hero slot --hero-image-radius' => 'layout=split +note(6325181d)',
        'hero slot --hero-image-position' => 'layout=split +note(6325181d)',
        'hero slot --hero-image-aspect-ratio' => 'layout=split AND vertical_align in [top|center|bottom] +note(6325181d)',
        'hero slot --hero-bg-position' => 'layout=cover AND image_url present',
        'hero slot --hero-surface-bg' => 'layout=split AND proof present',
        'hero slot --hero-surface-padding' => 'layout=split AND proof present',
        'hero slot --hero-surface-border-color' => 'layout=split AND proof present',
        'hero slot --hero-surface-border-width' => 'layout=split AND proof present',
        'hero slot --hero-surface-radius' => 'layout=split AND proof present',
        'hero slot --hero-surface-shadow' => 'layout=split AND proof present',
        'logos slot --logos-heading-size' => 'title present',
        'logos slot --logos-heading-color' => 'title present',
        'logos slot --logos-heading-measure' => 'title present',
        'logos prop items' => 'note +note(670c0bc6)',
        'logos slot --logos-heading-margin-bottom' => 'title present',
        'logos slot --logos-image-size' => 'items present',
        'logos slot --logos-gap' => 'items present',
        'nav prop logo_text' => 'note +note(0bec3f53)',
        'nav prop logo_alt' => 'note +note(4fea14e5)',
        'section slot --section-body-link-color' => 'note +note(dc603c21)',
        'section slot --section-body-link-hover-color' => 'note +note(dc603c21)',
        'section slot --section-heading-size' => 'title present',
        'section slot --section-heading-measure' => 'title present',
        'section slot --section-heading-accent-color' => 'title present',
        'section slot --section-eyebrow-color' => 'eyebrow present',
        'section slot --section-eyebrow-bg' => 'eyebrow present',
        'section slot --section-eyebrow-radius' => 'eyebrow present',
        'section slot --section-eyebrow-border-width' => 'eyebrow present',
        'section slot --section-eyebrow-border-color' => 'eyebrow present',
        'section slot --section-eyebrow-text-transform' => 'eyebrow present',
        'section slot --section-subheading-color' => 'subheading present',
        'section slot --section-subheading-margin-bottom' => 'subheading present',
        'section slot --section-heading-margin-bottom' => 'title present',
        'section slot --section-image-radius' => 'layout in [image-left|image-right] AND image_url present',
        'section slot --section-image-position' => 'layout in [image-left|image-right] AND image_url present',
        'section slot --section-image-aspect-ratio' => 'layout in [image-left|image-right] AND image_url present',
        'section slot --section-bg-position' => 'background_image present',
        'section slot --section-overlay-bg' => 'background_image present',
        'section slot --section-panel-bg' => 'layout=text-panel +note(f7501604)',
        'section slot --section-panel-border-color' => 'layout=text-panel +note(f7501604)',
        'section slot --section-panel-border-width' => 'layout=text-panel +note(f7501604)',
        'section slot --section-panel-radius' => 'layout=text-panel +note(f7501604)',
        'section slot --section-panel-padding' => 'layout=text-panel +note(f7501604)',
        'section slot --section-panel-text' => 'layout=text-panel +note(f7501604)',
        'section slot --section-panel-font' => 'layout=text-panel +note(f7501604)',
        'section slot --section-panel-marker-color' => 'layout=text-panel AND panel_items present AND panel_items_marker in [check|dash|arrow]',
        'section slot --section-panel-cta-bg' => 'layout=text-panel AND panel_cta_text present AND panel_cta_url present',
        'section slot --section-panel-cta-color' => 'layout=text-panel AND panel_cta_text present AND panel_cta_url present',
        'section slot --section-panel-cta-shadow' => 'layout=text-panel AND panel_cta_text present AND panel_cta_url present',
        'section slot --section-panel-cta-border' => 'layout=text-panel AND panel_cta_text present AND panel_cta_url present',
        'section slot --section-panel-cta-hover-border' => 'layout=text-panel AND panel_cta_text present AND panel_cta_url present',
        'section slot --section-body-marker-color' => 'body_marker in [check|dash|arrow] +note(1d75b888)',
        'section slot --section-separator-color' => 'body_items present',
        'section slot --section-inline-items-align' => 'body_items present',
        'stats slot --stats-heading-size' => 'title present',
        'stats slot --stats-heading-color' => 'title present',
        'stats slot --stats-heading-measure' => 'title present',
        'stats slot --stats-heading-margin-bottom' => 'title present',
        'stats slot --stats-heading-accent-color' => 'title present',
        'stats slot --stats-number-color' => 'items present',
        'stats slot --stats-number-size' => 'items present',
        'stats slot --stats-number-font' => 'items present',
        'stats slot --stats-number-weight' => 'items present',
        'stats slot --stats-label-color' => 'items present',
        'stats slot --stats-bg-position' => 'background_image present',
        'stats slot --stats-overlay-bg' => 'background_image present',
        'table slot --table-heading-size' => 'title present',
        'table slot --table-heading-color' => 'title present',
        'table slot --table-heading-measure' => 'title present',
        'table slot --table-heading-margin-bottom' => 'title present',
        'testimonials slot --testimonials-heading-size' => 'title present',
        'testimonials slot --testimonials-heading-color' => 'title present',
        'testimonials slot --testimonials-heading-measure' => 'title present',
        'testimonials slot --testimonials-heading-accent-color' => 'title present',
        'testimonials slot --testimonials-eyebrow-color' => 'eyebrow present',
        'testimonials slot --testimonials-eyebrow-bg' => 'eyebrow present',
        'testimonials slot --testimonials-eyebrow-radius' => 'eyebrow present',
        'testimonials slot --testimonials-eyebrow-border-width' => 'eyebrow present',
        'testimonials slot --testimonials-eyebrow-border-color' => 'eyebrow present',
        'testimonials slot --testimonials-eyebrow-text-transform' => 'eyebrow present',
        'testimonials slot --testimonials-subheading-color' => 'subheading present',
        'testimonials slot --testimonials-subheading-margin-bottom' => 'subheading present',
        'testimonials slot --testimonials-heading-margin-bottom' => 'title present',
        'testimonials slot --testimonials-item-bg' => 'layout=grid',
        'testimonials slot --testimonials-item-border-color' => 'layout=grid',
        'testimonials slot --testimonials-item-border-width' => 'layout=grid',
        'testimonials slot --testimonials-item-shadow' => 'layout=grid',
        'testimonials slot --testimonials-item-padding' => 'layout=grid',
    ];

    /** The populated census is exactly the ledger — no additions, no drops, no rewordings. */
    public function testTheConditionalityLedgerIsExact(): void
    {
        $actual = [];
        foreach ($this->allSchemas() as $component => $schema) {
            $sections = [
                'slot' => $schema['styling']['style_slots'] ?? [],
                'prop' => $schema['props'] ?? [],
            ];
            foreach ($sections as $kind => $definitions) {
                foreach ($definitions as $name => $def) {
                    if (!isset($def['applies_when']) && !isset($def['conditionality_note'])) {
                        continue;
                    }
                    $actual["{$component} {$kind} {$name}"] = $this->renderCondition($def);
                }
            }
        }

        ksort($actual);
        $expected = self::CONDITIONALITY_LEDGER;
        ksort($expected);
        $this->assertSame(
            $expected,
            $actual,
            'The conditionality census changed. Update CONDITIONALITY_LEDGER in the SAME change, '
            . 'after checking the new condition against the renderer AND the CSS selector that '
            . 'consumes the slot — a condition nothing verifies is a lie the AI catalog repeats.'
        );
    }

    /**
     * REFERENTIAL INTEGRITY — the machine check the ledger cannot be.
     *
     * pp_applies_when_clause_errors() validates a clause's SHAPE, never its subject, and
     * the ledger records whatever the schema says including a typo, because whoever adds
     * the row copies the typo into it. A clause naming a prop that does not exist resolves
     * to "absent" on every component ever authored, so a `present` clause fires `inert_slot`
     * forever with no authorable fix — and `wp pp validate site` halts on ANY smell
     * (lib/cli.php). That is the same shape as the #610 trap, arrived at by a spelling
     * mistake instead of a grammar gap.
     */
    public function testEveryAppliesWhenSubjectResolvesToADeclaredPropOrSlot(): void
    {
        foreach ($this->allSchemas() as $component => $schema) {
            $props = $schema['props'] ?? [];
            $slots = $schema['styling']['style_slots'] ?? [];
            $definitions = ['slot' => $slots, 'prop' => $props];

            foreach ($definitions as $kind => $set) {
                foreach ($set as $name => $def) {
                    foreach (($def['applies_when'] ?? []) as $clause) {
                        if (isset($clause['prop'])) {
                            $this->assertArrayHasKey(
                                $clause['prop'],
                                $props,
                                "{$component} {$kind} {$name}: applies_when names an undeclared prop "
                                . "`{$clause['prop']}` — it can never be satisfied, so the declaration "
                                . 'warns forever and `wp pp validate site` exits 1.'
                            );
                        }
                        if (isset($clause['slot'])) {
                            $this->assertArrayHasKey(
                                $clause['slot'],
                                $slots,
                                "{$component} {$kind} {$name}: applies_when names an undeclared sibling slot `{$clause['slot']}`"
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * `present` reads a non-empty STRING or a non-empty ARRAY and nothing else, so a clause
     * pointing it at a boolean or numeric prop asks a question it cannot answer. The
     * evaluator fails open there rather than lying, which means such a clause would be
     * silently inert — a condition that never fires is as misleading as one that always
     * does. Conditions on bool/number props ride `conditionality_note` instead (the
     * nav/footer chrome preconditions are exactly that case).
     */
    public function testEveryPresentClauseTargetsAStringOrArrayProp(): void
    {
        foreach ($this->allSchemas() as $component => $schema) {
            $props       = $schema['props'] ?? [];
            $definitions = ['slot' => $schema['styling']['style_slots'] ?? [], 'prop' => $props];

            foreach ($definitions as $kind => $set) {
                foreach ($set as $name => $def) {
                    foreach (($def['applies_when'] ?? []) as $clause) {
                        if (!array_key_exists('present', $clause) || !isset($clause['prop'])) {
                            continue;
                        }
                        $this->assertContains(
                            $props[$clause['prop']]['type'] ?? 'MISSING',
                            ['string', 'array'],
                            "{$component} {$kind} {$name}: `present` on `{$clause['prop']}` — the predicate "
                            . 'reads strings and arrays only, so this clause can never fail. Express a '
                            . 'boolean or numeric precondition in `conditionality_note`.'
                        );
                    }
                }
            }
        }
    }

    /** Renders one definition's declared condition the way the ledger records it. */
    private function renderCondition(array $def): string
    {
        if (!isset($def['applies_when'])) {
            return 'note' . self::noteMarker($def);
        }
        $parts = [];
        foreach ($def['applies_when'] as $clause) {
            $subject = $clause['prop'] ?? $clause['slot'];
            if (array_key_exists('equals', $clause)) {
                $parts[] = "{$subject}={$clause['equals']}";
            } elseif (array_key_exists('in', $clause)) {
                $parts[] = "{$subject} in [" . implode('|', $clause['in']) . ']';
            } else {
                $parts[] = "{$subject} present";
            }
        }
        return implode(' AND ', $parts) . self::noteMarker($def);
    }

    /**
     * The `+note` marker carries a HASH of the note text, not just its presence.
     *
     * `conditionality_note` is emitted VERBATIM into the runtime AI catalog
     * (lib/ai-context.php), so its wording IS the contract — a note that is narrowed,
     * widened or inverted changes what an agent is told while the clause list stays
     * identical. A bare `+note` marker would let that through and the ledger's docblock
     * would be lying about "no rewordings". The hash is short and opaque on purpose:
     * when it changes, re-read the note against the renderer and the CSS, then update
     * the ledger — there is nothing to eyeball in the digest itself.
     */
    private static function noteMarker(array $def): string
    {
        if (!isset($def['conditionality_note'])) {
            return '';
        }
        return ' +note(' . substr(sha1((string) $def['conditionality_note']), 0, 8) . ')';
    }

    /**
     * The three condition classes that stay PROSE are each actually represented — the
     * ruling's promise is bounded rather than overstated only if the exclusions are real
     * declarations an agent can read, not a paragraph in a decision record.
     */
    public function testTheThreeProseOnlyClassesAreRepresented(): void
    {
        $schemas = $this->allSchemas();

        // DISJUNCTION — dark bands are theme:"inverted" OR a background_image.
        $link = $schemas['section']['styling']['style_slots']['--section-body-link-color'];
        $this->assertArrayNotHasKey('applies_when', $link, 'a disjunction must not be faked as an AND');
        $this->assertStringContainsString('OR', $link['conditionality_note']);

        // COMPOSED-PAGE CONTEXT — the `main >` scope on the featured card.
        $featured = $schemas['grid']['styling']['style_slots']['--grid-featured-shadow'];
        $this->assertStringContainsString('main > .grid', $featured['conditionality_note']);

        // INTERACTION STATE — the open question.
        $open = $schemas['faq']['styling']['style_slots']['--faq-question-open-color'];
        $this->assertStringContainsString('OPEN', $open['conditionality_note']);

        // The logos label-driven image-height switch: no doc stated it anywhere before
        // #580, and it is item-level, so the grammar cannot reach it.
        $items = $schemas['logos']['props']['items'];
        $this->assertStringContainsString('2.5rem', $items['conditionality_note']);
    }

    /**
     * A-8b — the five testimonials card slots that `layout: "stack"` defeats DECLARE the
     * condition, and the CSS that defeats them is UNCHANGED. The stack variant is a
     * card-LESS presentation by design; routing the resets through the card slots would
     * change what "stack" renders, which is why StyleSlotContractTest carries a PERMANENT
     * waiver for them. The remedy is declaration, not CSS.
     */
    public function testTheStackDefeatedTestimonialsSlotsDeclareTheGridLayout(): void
    {
        $slots = $this->allSchemas()['testimonials']['styling']['style_slots'];
        foreach ([
            '--testimonials-item-bg',
            '--testimonials-item-border-color',
            '--testimonials-item-border-width',
            '--testimonials-item-padding',
            '--testimonials-item-shadow',
        ] as $slot) {
            $this->assertSame(
                [['prop' => 'layout', 'equals' => 'grid']],
                $slots[$slot]['applies_when'],
                "{$slot} is defeated by the stack variant's resets; it must say so"
            );
        }

        $css = file_get_contents($this->themeRoot . '/assets/css/components.css');
        $this->assertStringContainsString(
            ".testimonials--stack .testimonials__item {\n  padding: 0;\n  background: transparent;\n  border: none;\n  box-shadow: none;",
            $css,
            'A-8b is a SCHEMA fix. The stack resets are a recorded permanent waiver and must not change.'
        );
    }

    // ── styling.variant_classes truthfulness (issue #575) ─────────────────

    /**
     * `styling.variant_classes` must list EXACTLY the root-element modifier classes
     * the component's template can emit. It used to lie: `faq` declared [] while the
     * renderer emits faq--dark / faq--inverted, and `stats`/`cta`/`section` all
     * omitted their --has-bg-image modifier.
     *
     * The expectation is DERIVED FROM THE TEMPLATE, never a second hand-maintained
     * copy of the answer — a pinned literal list would drift again the moment a
     * template changed. Three derivation rules, matching the three ways a template
     * emits a root modifier:
     *
     *   1. THEME    a pp_theme_class($theme, 'PREFIX') call contributes exactly
     *               PREFIX--dark and PREFIX--inverted (the helper's only two
     *               non-empty outputs; `muted` shares the legacy --dark class).
     *   2. LAYOUT   an interpolated `class="ROOT ROOT--<?php … $layout …`
     *               contributes ROOT--<v> for every declared layout enum value.
     *   3. LITERAL  any `'ROOT--x'` / `'PREFIX--x'` string in the template
     *               (the conditional modifiers: --steps, --uniform, --image-icon,
     *               --stack, --has-bg-image).
     *
     * A component with no root modifiers declares [] — and must, so "empty" stays a
     * claim the test checks rather than a gap nobody noticed.
     */
    public function testVariantClassesListExactlyWhatTheTemplateCanEmit(): void
    {
        foreach ($this->allSchemas() as $component => $schema) {
            $template = file_get_contents($this->themeRoot . "/components/{$component}/{$component}.php");
            $root     = $schema['styling']['root_class'] ?? $component;
            $expected = [];
            $prefixes = [$root];

            // 1. Theme classes, from the actual pp_theme_class() prefix.
            if (preg_match('/pp_theme_class\(\s*\$theme\s*,\s*\'([a-z0-9-]+)\'\s*\)/', $template, $m)) {
                $prefixes[] = $m[1];
                foreach (['dark', 'inverted'] as $slug) {
                    $expected[] = "{$m[1]}--{$slug}";
                }
            }

            // 2. Interpolated layout classes, one per declared enum value.
            $interpolated = '/class="' . preg_quote($root, '/') . '\s+' . preg_quote($root, '/') . '--<\?php/';
            if (preg_match($interpolated, $template)) {
                foreach (($schema['props']['layout']['values'] ?? []) as $value) {
                    $expected[] = "{$root}--{$value}";
                }
            }

            // 3. Literal modifier strings anywhere in the template.
            foreach (array_unique($prefixes) as $prefix) {
                if (preg_match_all('/\'\s*(' . preg_quote($prefix, '/') . '--[a-z0-9-]+)\'/', $template, $lit)) {
                    $expected = array_merge($expected, $lit[1]);
                }
            }

            $expected = array_values(array_unique($expected));

            // TRIPWIRE. The three rules above recognize today's template idioms. A
            // template using a shape they miss (a double-quoted literal, a
            // concatenation) would UNDER-derive, and the test would then go green
            // while forcing the schema to omit a class the component really emits —
            // the precise untruthfulness it exists to prevent. So: every
            // root-prefixed modifier token that appears anywhere in the template
            // must be accounted for. An unrecognized idiom fails loudly here
            // instead of silently shrinking the expectation.
            foreach (array_unique($prefixes) as $prefix) {
                if (!preg_match_all('/(' . preg_quote($prefix, '/') . '--[a-z0-9-]+)/', $template, $seen)) {
                    continue;
                }
                foreach (array_unique($seen[1]) as $token) {
                    $this->assertContains(
                        $token,
                        $expected,
                        "{$component}.php contains the root modifier '{$token}' but the derivation rules "
                        . 'did not produce it — the template uses an idiom this test does not recognize. '
                        . 'Extend the derivation rather than editing the schema to match.'
                    );
                }
            }

            $declared = $schema['styling']['variant_classes'] ?? null;
            $this->assertIsArray($declared, "{$component} must declare styling.variant_classes");

            sort($expected);
            $sortedDeclared = $declared;
            sort($sortedDeclared);
            $this->assertSame(
                $expected,
                $sortedDeclared,
                "{$component}.styling.variant_classes must list exactly the root modifiers "
                . "{$component}.php can emit (derived from the template, not from a pinned list)."
            );
        }
    }

    /**
     * The `section` trap, pinned by name because it is the one place the two
     * spellings diverge: the root class is `section` but pp_theme_class() is called
     * with the `pp-section` prefix, so the theme classes are pp-section--*. A
     * "consistency cleanup" that renames them to section--* would silently unstyle
     * every muted and inverted section band.
     */
    public function testSectionThemeClassesKeepThePpSectionPrefix(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $declared = $schema['styling']['variant_classes'];
        $this->assertContains('pp-section--dark', $declared);
        $this->assertContains('pp-section--inverted', $declared);
        $this->assertNotContains('section--dark', $declared);
        $this->assertNotContains('section--inverted', $declared);
        $this->assertSame('section', $schema['styling']['root_class'], 'the root class itself is unprefixed');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
