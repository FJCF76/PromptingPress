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

        $this->assertArrayHasKey('subtitle', $schema['props']);
        $this->assertEmpty(
            $schema['props']['subtitle']['required'] ?? false,
            "Hero 'subtitle' prop should be optional (required = false or absent)."
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
            'hero'    => 41,
            'section' => 40,
            'grid'    => 37,
            'cta'     => 31,
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
        $validTypes = ['color', 'length', 'number', 'shadow', 'gradient', 'position', 'ratio', 'align', 'text-transform', 'font-family', 'enum'];

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
        $this->assertArrayHasKey('--hero-text', $slots);
        $this->assertArrayHasKey('--hero-title-size', $slots);
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
     * historical card-namespaced name (--grid-card-border) while enforcing full,
     * consistent coverage. Dropping one of these slots, or adding a styleable
     * component without them, fails CI. Pairs with StyleSlotContractTest, which
     * proves each declared slot is actually consumed in CSS.
     */
    public function testCommonVisualSlotConformance(): void
    {
        $expected = [
            'hero'    => ['--hero-border-color', '--hero-border-width', '--hero-radius', '--hero-shadow'],
            'section' => ['--section-border-color', '--section-border-width', '--section-radius', '--section-shadow'],
            'grid'    => ['--grid-card-border', '--grid-card-border-width', '--grid-card-radius', '--grid-card-shadow'],
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
     * The strict-enum check is OPT-IN (only enum props with strict:true). It must
     * NOT ripple to ordinary enum props: an invalid `layout` value keeps its
     * historical accept-and-coerce-at-render behavior and still VALIDATES here, so
     * this change did not silently tighten validation for props beyond #380's.
     */
    public function testNonStrictEnumInvalidValueStillValidates(): void
    {
        $result = pp_validate_composition([
            ['component' => 'grid', 'props' => [
                'layout' => 'bogus-not-a-layout',
                'theme'  => 'not-a-theme',
                'items'  => [['title' => 'One']],
            ]],
        ]);
        $this->assertTrue(
            $result,
            'A non-strict enum (layout/theme) with an invalid value must still validate '
            . '(render-time coercion preserved); the #380 strict check must not ripple to it.'
        );
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
            '--grid-card-bar-height'        => '0',
            '--grid-featured-texture-color' => 'transparent',
            '--grid-featured-shadow'        => 'none',
        ]));
        $this->assertTrue($result, 'The documented uniform-row neutralizers must validate.');
    }

    public function testGridFeaturedRemnantSlotsAcceptTypedValues(): void
    {
        $result = pp_validate_composition($this->gridCompositionWithStyle([
            '--grid-card-bar-height'        => '4px',
            '--grid-card-bar-color'         => 'linear-gradient(90deg, #ea3900, #b32b00)',
            '--grid-featured-texture-color' => 'rgba(37, 99, 235, 0.028)',
            '--grid-featured-shadow'        => '0 10px 24px rgba(15, 23, 42, 0.055)',
        ]));
        $this->assertTrue($result, 'Ordinary typed values for the issue 293 slots must validate.');
    }

    public function testGridCardBarColorAcceptsPlainColor(): void
    {
        // gradient-typed slots accept plain colors too (the --grid-card-bg precedent).
        $result = pp_validate_composition($this->gridCompositionWithStyle([
            '--grid-card-bar-color' => '#e6e8eb',
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
            'color into bar-height'     => ['--grid-card-bar-height', '#ff0000'],
            'length into texture-color' => ['--grid-featured-texture-color', '2rem'],
            'keyword into shadow'       => ['--grid-featured-shadow', 'blue-glow'],
            'shadow into bar-color'     => ['--grid-card-bar-color', '0 10px 24px rgba(0, 0, 0, 0.1)'],
        ];
    }

    public function testGridDeclaresUniformCardsRecipe(): void
    {
        $schema  = json_decode(file_get_contents($this->themeRoot . '/components/grid/schema.json'), true);
        $recipes = $schema['styling']['recipes'] ?? [];

        $this->assertArrayHasKey('uniform-cards', $recipes, 'issue 293 acceptance: the uniform row must be a documented recipe.');
        $slots = $recipes['uniform-cards']['slots'] ?? [];
        $this->assertSame('0', $slots['--grid-card-bar-height'] ?? null);
        $this->assertSame('transparent', $slots['--grid-featured-texture-color'] ?? null);
        $this->assertArrayHasKey('--grid-card-shadow', $slots, 'Uniformity needs one shared shadow on all cards, not a missing featured glow.');

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
            '--grid-card-bg'          => '#0f172a',
            '--grid-card-border'      => '#0f172a',
            '--grid-item-title-color' => '#f8fafc',
            '--grid-item-text-color'  => '#cbd5e1',
        ]);
        $this->assertTrue(pp_validate_composition($darkPanel), 'A dark panel card must validate through the shared engine.');

        $terminal = $this->gridCompositionWithItemStyle([
            '--grid-card-bg'         => '#0b0f0a',
            '--grid-item-text-color' => '#22c55e',
        ]);
        $this->assertTrue(pp_validate_composition($terminal), 'A green terminal card must validate through the shared engine.');
    }

    public function testGridItemStyleAcceptsTokenAndGradientValues(): void
    {
        // Item slots accept the full grammar their type allows, same as grid-level:
        // registered var(--token) colors and gradients.
        $result = pp_validate_composition($this->gridCompositionWithItemStyle([
            '--grid-card-bg'          => 'linear-gradient(180deg, #0f172a 0%, #1e293b 100%)',
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
            '--grid-card-bg' => '#000; } body { display:none',
        ]));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_value', $result->get_error_code());
    }

    public function testGridItemStyleAndGridLevelStyleCoexist(): void
    {
        // Grid-level style stays valid while an item overrides one slot.
        $result = pp_validate_composition($this->gridCompositionWithItemStyle(
            ['--grid-card-bg' => '#0f172a'],
            ['--grid-card-bg' => 'var(--color-surface)', '--grid-gap' => '2rem']
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
        // .pp-step-number child), so they must be usable per card (issue 323 AC).
        $result = pp_validate_composition($this->gridCompositionWithItemStyleMap([
            '--grid-card-bar-color'         => '#123456',
            '--grid-card-bar-height'        => '4px',
            '--grid-featured-texture-color' => '#123456',
            '--grid-featured-shadow'        => 'none',
            '--grid-step-color'             => '#123456',
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
        $this->assertStringContainsString('--grid-card-bg', $result->get_error_message());
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
                '--grid-card-bg', '--grid-card-border', '--grid-card-border-width',
                '--grid-card-radius', '--grid-card-shadow', '--grid-card-bar-color',
                '--grid-card-bar-height', '--grid-featured-texture-color',
                '--grid-featured-shadow', '--grid-card-padding', '--grid-card-gap',
                '--grid-item-text-align', '--grid-item-icon-size',
                '--grid-item-title-size', '--grid-item-title-color', '--grid-item-text-color',
                '--grid-bullet-color', '--grid-link-color', '--grid-step-color',
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
                '--grid-heading-size', '--grid-heading-max-width', '--grid-gap',
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
                'title' => 'T', 'text' => 'x', 'button_text' => 'Go', 'button_url' => '#',
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
                    // keyed by the declared item fields. Item sub-props are not
                    // value-checked by the shared validator, so 'x' placeholders suffice.
                    $entry = [];
                    foreach (($prop_def['items'] ?? []) as $item_prop => $item_def) {
                        $entry[$item_prop] = 'x';
                    }
                    $props[$prop_name] = [$entry === [] ? ['x' => 'x'] : $entry];
                } elseif ($prop_type === 'array') {
                    // Plain array prop (no item contract, e.g. table.rows/headers): an
                    // array of strings is a valid, non-rejected value.
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
        // pp_validate_composition() returns IS errors[0] — same code, same message.
        $composition = $this->multiErrorComposition();

        $first  = pp_validate_composition($composition);
        $errors = pp_validate_composition_errors($composition);

        $this->assertSame($errors[0]->get_error_code(), $first->get_error_code());
        $this->assertSame($errors[0]->get_error_message(), $first->get_error_message());
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

    public function testValidateCompositionErrorsReportsAtMostOneErrorPerItem(): void
    {
        // A single item that trips several checks must not cascade. `ghost` is unknown, so
        // the schema lookups below that check never run against it.
        $errors = pp_validate_composition_errors([
            ['component' => 'ghost', 'style' => ['nope' => 'red']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_composition', $errors[0]->get_error_code());
    }

    public function testMultipleMissingRequiredPropsOnOneItemReportOneError(): void
    {
        // Exercises the `continue 2` in the REQUIRED-PROP loop. `cta` requires
        // button_text and button_url (title is optional since issue 294); both are
        // absent. Without `continue 2` this item would emit two errors, shifting every
        // later index and breaking the invariant that errors[0] is the only error
        // pp_validate_composition() would have returned.
        $errors = pp_validate_composition_errors([
            ['component' => 'cta', 'props' => []],
        ]);

        $this->assertCount(1, $errors, 'an item stops at its first failing prop check');
        $this->assertSame('invalid_composition', $errors[0]->get_error_code());
        $this->assertStringContainsString('button_text', $errors[0]->get_error_message());
    }

    public function testMissingPropSkipsTheStyleChecksForThatItem(): void
    {
        // `continue 2` in the prop loop must jump to the next ITEM, not fall through into
        // the style-slot checks below it. A bad style slot on the same item stays unreported.
        $errors = pp_validate_composition_errors([
            ['component' => 'cta', 'props' => [], 'style' => ['--not-a-slot' => 'red']],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('button_text', $errors[0]->get_error_message());
    }

    public function testMultipleInvalidStyleSlotsOnOneItemReportOneError(): void
    {
        // Exercises the `continue 2` in the STYLE loop. Two unknown slots on one item.
        $errors = pp_validate_composition_errors([
            [
                'component' => 'hero',
                'props'     => ['title' => 'A'],
                'style'     => ['--not-a-slot' => 'red', '--also-not-a-slot' => 'blue'],
            ],
        ]);

        $this->assertCount(1, $errors, 'an item stops at its first failing style check');
        $this->assertSame('invalid_style_slot', $errors[0]->get_error_code());
    }

    public function testEachItemContributesItsOwnErrorAcrossItems(): void
    {
        // The other half of the invariant: one error PER ITEM, so three bad items give
        // three errors, in document order.
        $errors = pp_validate_composition_errors([
            ['component' => 'cta', 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'A'], 'style' => ['--nope' => 'red']],
            ['component' => 'ghost', 'props' => []],
        ]);

        $this->assertCount(3, $errors);
        $this->assertSame('invalid_composition', $errors[0]->get_error_code());
        $this->assertSame('invalid_style_slot', $errors[1]->get_error_code());
        $this->assertSame('invalid_composition', $errors[2]->get_error_code());
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
     * LLMs emit `{"type":"nav"}`. pp_normalize_composition() aliases `type` to
     * `component`, and every validating write path normalizes before validating,
     * so chrome rejection for the alias depends entirely on that ordering. Pin it:
     * if the ordering ever regresses, alias-keyed chrome gets rejected only as a
     * generic "missing component key" and this test says so out loud.
     */
    public function testTypeAliasedChromeIsRejectedAsChrome(): void
    {
        $normalized = pp_normalize_composition([['type' => 'nav', 'props' => []]]);
        $result     = pp_validate_composition($normalized);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(
            'template_owned_component',
            $result->get_error_code(),
            'A `type`-aliased chrome item must be rejected AS CHROME, not as a missing component key.'
        );
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
     *   2. every scalar-typed schema prop (string/number/enum, not opted out)
     *      IS derived — no silent coverage drop, the exact rot the old
     *      hand-list suffered; and
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

            // Directions 2 & 3: every non-opted-out scalar top-level prop is
            // derived; every array/object top-level prop is NOT.
            foreach ($props as $propName => $propDef) {
                if (!is_array($propDef)) {
                    continue;
                }
                $optedOut = array_key_exists('patchable', $propDef) && $propDef['patchable'] === false;
                $type = $propDef['type'] ?? null;
                if (in_array($type, $scalarTypes, true) && !$optedOut) {
                    $this->assertArrayHasKey($propName, $derived, "'{$componentType}.{$propName}' is a scalar schema prop but was NOT derived (silent coverage drop).");
                } elseif (in_array($type, ['array', 'object'], true)) {
                    $this->assertArrayNotHasKey($propName, $derived, "'{$componentType}.{$propName}' is a structural prop but leaked into the derived field set.");
                }
            }
        }

        $this->assertTrue($sawComposable, 'Expected at least one component to derive patchable fields.');
    }

    // ── Legacy prop-rename alias map + schema-rename drift-catcher (#495) ──
    //
    // The alias map (lib/admin.php, pp_legacy_prop_aliases) is the audited, closed
    // inventory of pre-1.0 component-scoped prop RENAMES whose old names can still
    // exist in stored compositions. Two guards keep it honest:
    //   1. An inventory PIN so the closed set cannot silently grow or shrink.
    //   2. A drift-catcher that fails a FUTURE schema change which removes/renames a
    //      prop without shipping an alias entry (or an explicit migration note) —
    //      making the alias-at-introduction convention structural, not remembered.

    /**
     * The pinned baseline of declared props per component AS OF #495. This is a
     * FROZEN LITERAL, deliberately NOT re-globbed from the live schemas at runtime:
     * if it were regenerated each run, removing a prop would also remove it from the
     * baseline and the drift would be invisible. A future prop removal/rename leaves
     * the prop here but drops it from the live schema, so the drift-catcher fires
     * unless the same change adds an alias entry or a migration note. When you
     * intentionally ADD a prop, append it here in the same change.
     */
    private const PINNED_PROP_BASELINE = [
        'cta'          => ['id', 'title', 'title_accent', 'eyebrow', 'text', 'button_text', 'button_url', 'layout', 'theme', 'background_image', 'button_variant'],
        'embed'        => ['id', 'title', 'content', 'theme'],
        'faq'          => ['id', 'title', 'title_accent', 'eyebrow', 'theme', 'items'],
        'footer'       => ['location', 'show_logo', 'logo_text', 'logo_id', 'logo_alt', 'bg', 'text', 'link_color', 'blurb', 'contact', 'copyright', 'menu_label', 'contact_label', 'secondary_location', 'secondary_label', 'note', 'social'],
        'grid'         => ['id', 'title', 'title_accent', 'eyebrow', 'subheading', 'heading_align', 'layout', 'card_emphasis', 'theme', 'columns', 'image_treatment', 'items'],
        'hero'         => ['id', 'title', 'title_accent', 'eyebrow', 'subtitle', 'cta_text', 'cta_url', 'cta2_text', 'cta2_url', 'cta_variant', 'cta2_variant', 'layout', 'image_url', 'image_alt', 'image_id', 'spacing', 'width', 'split_ratio', 'vertical_align', 'proof'],
        'logos'        => ['id', 'title', 'theme', 'items'],
        'nav'          => ['location', 'logo_text', 'logo_id', 'logo_alt', 'bg', 'text', 'link_color'],
        'section'      => ['id', 'title', 'title_accent', 'eyebrow', 'subheading', 'heading_align', 'body', 'image_url', 'image_alt', 'image_id', 'layout', 'theme', 'background_image', 'panel_heading', 'panel_body', 'panel_items', 'panel_cta_text', 'panel_cta_url', 'panel_cta_variant', 'panel_items_marker', 'body_marker', 'body_items'],
        'stats'        => ['id', 'title', 'title_accent', 'theme', 'background_image', 'items'],
        'table'        => ['id', 'title', 'headers', 'rows', 'caption'],
        'testimonials' => ['id', 'title', 'title_accent', 'eyebrow', 'subheading', 'heading_align', 'layout', 'theme', 'items'],
    ];

    /**
     * Explicit escape hatch for a prop retired WITHOUT a write-time alias (e.g. a
     * rename handled by a separate migration such as `variant`, #388). Empty today:
     * every removed prop in scope of #495 ships an alias instead. A future retirement
     * that is genuinely migrated out-of-band records `component => [prop => note]` here.
     */
    private const SCHEMA_RENAME_MIGRATION_NOTES = [];

    /**
     * Pure drift detector: any baseline prop that no longer exists in the live
     * schema must be covered by an alias-map entry OR a migration note, else it is
     * a violation. Kept as a static helper so the real run and the simulated-rename
     * test share one implementation.
     *
     * @param array<string,string[]>              $baseline   component => prop names
     * @param array<string,string[]>              $liveProps  component => prop names
     * @param array<string,array<string,string>>  $aliasMap   component => [old => canonical]
     * @param array<string,array<string,string>>  $notes      component => [prop => note]
     * @return string[]  Human-readable violations (empty = no drift).
     */
    private static function detectSchemaRenameDrift(array $baseline, array $liveProps, array $aliasMap, array $notes): array
    {
        $violations = [];
        foreach ($baseline as $component => $props) {
            $live = $liveProps[$component] ?? [];
            foreach ($props as $prop) {
                if (in_array($prop, $live, true)) {
                    continue; // still declared — no drift
                }
                $aliased = isset($aliasMap[$component]) && array_key_exists($prop, $aliasMap[$component]);
                $noted   = isset($notes[$component]) && array_key_exists($prop, $notes[$component]);
                if (!$aliased && !$noted) {
                    $violations[] = sprintf(
                        'Component "%s" prop "%s" was removed/renamed without an alias-map entry or migration note.',
                        $component,
                        $prop
                    );
                }
            }
        }
        return $violations;
    }

    /** Live declared props per component, read from the shipped schemas. */
    private function liveProps(): array
    {
        $out = [];
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $schemaFile) {
            $schema = json_decode(file_get_contents($schemaFile), true);
            $name   = $schema['component'] ?? basename(dirname($schemaFile));
            $out[$name] = array_keys($schema['props'] ?? []);
        }
        return $out;
    }

    public function testLegacyPropAliasInventoryIsPinned(): void
    {
        // The closed audit result. If a future change adds/removes a legacy alias,
        // this pin forces the change to be deliberate and reviewed.
        $this->assertSame(
            [
                'cta' => [
                    'cta_text' => 'button_text',
                    'cta_url'  => 'button_url',
                ],
            ],
            pp_legacy_prop_aliases(),
            'The legacy prop-alias inventory changed — update the audit and this pin together.'
        );
    }

    public function testLiveSchemasHaveNoUnaliasedRenameDriftFromBaseline(): void
    {
        // The real guard: today baseline == live, so there is no drift. This freezes
        // the baseline; a future prop removal/rename without an alias entry or a
        // migration note fails HERE.
        $drift = self::detectSchemaRenameDrift(
            self::PINNED_PROP_BASELINE,
            $this->liveProps(),
            pp_legacy_prop_aliases(),
            self::SCHEMA_RENAME_MIGRATION_NOTES
        );
        $this->assertSame([], $drift, implode("\n", $drift));

        // The alias map must stay coherent with the schemas: every canonical target
        // must actually exist as a live prop (no dangling alias).
        $live = $this->liveProps();
        foreach (pp_legacy_prop_aliases() as $component => $map) {
            foreach ($map as $old => $canonical) {
                $this->assertContains(
                    $canonical,
                    $live[$component] ?? [],
                    sprintf('Alias %s.%s -> %s points at a prop that no longer exists.', $component, $old, $canonical)
                );
            }
        }
    }

    public function testEveryLiveSchemaPropIsPinnedInBaseline(): void
    {
        // Symmetric guard (the add-path): a newly ADDED prop that the author forgets
        // to append to PINNED_PROP_BASELINE would leave the baseline stale, so a LATER
        // removal of that prop could escape the drift-catcher. Forcing every live prop
        // into the baseline makes the alias-at-introduction convention structural in
        // BOTH directions: a schema change must touch the baseline in the same commit,
        // and a rename then fails on both the remove-path (needs an alias) and here.
        foreach ($this->liveProps() as $component => $props) {
            $baseline = self::PINNED_PROP_BASELINE[$component] ?? null;
            $this->assertNotNull(
                $baseline,
                sprintf('Component "%s" is not in PINNED_PROP_BASELINE — add it (SchemaValidationTest).', $component)
            );
            foreach ($props as $prop) {
                $this->assertContains(
                    $prop,
                    $baseline,
                    sprintf('Prop "%s.%s" is not pinned in PINNED_PROP_BASELINE — append it in this same change.', $component, $prop)
                );
            }
        }
    }

    public function testSchemaRenameDriftIsCaught(): void
    {
        // Simulate a future rename: the baseline says cta had a `headline` prop, the
        // live schema no longer declares it. With no alias entry, this MUST be caught.
        $baseline = ['cta' => ['id', 'headline', 'button_text']];
        $live     = ['cta' => ['id', 'button_text']]; // `headline` removed

        $unaliased = self::detectSchemaRenameDrift($baseline, $live, [], []);
        $this->assertNotEmpty($unaliased, 'a schema rename with no alias entry must be flagged');
        $this->assertStringContainsString('headline', $unaliased[0]);

        // Shipping an alias entry in the SAME change clears it.
        $withAlias = self::detectSchemaRenameDrift($baseline, $live, ['cta' => ['headline' => 'button_text']], []);
        $this->assertSame([], $withAlias, 'an alias entry for the renamed prop clears the drift');

        // An explicit migration note also clears it (the out-of-band-migration escape hatch).
        $withNote = self::detectSchemaRenameDrift($baseline, $live, [], ['cta' => ['headline' => 'migrated by #999']]);
        $this->assertSame([], $withNote, 'a migration note for the renamed prop clears the drift');
    }

    public function testAliasHelperGuardsNonScalarComponentWithoutWarning(): void
    {
        // The restore path runs the alias normalizer over arbitrary history-ring
        // snapshots, which can carry a malformed (non-scalar) component key. The
        // helper must return the item untouched and emit NO "Array to string
        // conversion" warning (a raw (string) cast on an array would).
        $item = ['component' => ['not', 'scalar'], 'props' => ['cta_text' => 'x']];

        $warned = false;
        set_error_handler(function () use (&$warned) { $warned = true; return true; });
        try {
            $result = _pp_apply_legacy_prop_aliases($item);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($warned, 'a non-scalar component must not trigger a PHP warning');
        $this->assertSame($item, $result, 'a malformed-component item is returned untouched');
    }

    public function testAliasHelperLeavesItemsWithoutUsablePropsUnchanged(): void
    {
        // No props key, and a non-array props value: both early-return untouched.
        $noProps = ['component' => 'cta'];
        $this->assertSame($noProps, _pp_apply_legacy_prop_aliases($noProps));

        $scalarProps = ['component' => 'cta', 'props' => 'nope'];
        $this->assertSame($scalarProps, _pp_apply_legacy_prop_aliases($scalarProps));

        // A component with no alias map (hero's cta_text is a CURRENT prop) is untouched.
        $hero = ['component' => 'hero', 'props' => ['cta_text' => 'Get Started']];
        $this->assertSame($hero, _pp_apply_legacy_prop_aliases($hero));
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
        $this->assertGreaterThanOrEqual(5, $checked, 'expected the five known link_url props (button_url, cta_url, cta2_url, panel_cta_url, grid.items[].link_url)');
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
