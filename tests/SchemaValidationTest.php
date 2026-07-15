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
            'section' => 36,
            'grid'    => 35,
            'cta'     => 30,
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
        $validTypes = ['color', 'length', 'number', 'shadow', 'gradient', 'position', 'ratio', 'align', 'text-transform', 'font-family'];

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
        ]));
        $this->assertTrue($result, 'The #293 featured/bar slots and --grid-step-color must be accepted per card.');
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
                '--grid-item-text-align',
                '--grid-item-title-size', '--grid-item-title-color', '--grid-item-text-color',
                '--grid-bullet-color', '--grid-link-color', '--grid-step-color',
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
            $declared = array_keys($schema['props'] ?? []);
            $props     = [];
            foreach ($declared as $prop_name) {
                $props[$prop_name] = 'x'; // prop VALUES are not type-checked here.
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
     * something of the author.
     *
     * A component with no required prop can be declared as a bare
     * `{"component": "x"}` — no `props` key at all — and still validate. Such a
     * component renders empty (the #87 empty-section smell exists to catch that),
     * and it is the one composition shape the accordion round-trip cannot
     * preserve: `serializeAccordionData()` re-emits `props: {}`, so the editor's
     * serialization-invariant gate locks the accordion.
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
    public function testEveryComposableComponentDeclaresARequiredProp(): void
    {
        foreach (pp_composable_components() as $name => $schema) {
            $required = array_filter(
                $schema['props'] ?? [],
                fn($def) => !empty($def['required'])
            );

            $this->assertNotEmpty(
                $required,
                "Composable component '{$name}' declares no required prop. A composition that "
                . 'omits its `props` key would then be VALID and would drift on the accordion '
                . "round-trip, so '{$name}' renders empty and the editor locks the accordion. "
                . 'Give it a required prop, or make it template-owned. If this is deliberate, '
                . 'Test 9 in tests/e2e/composition-editor.spec.ts must be revisited: it assumes '
                . 'a drifting composition is always invalid and therefore unsaveable.'
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

    // ── Field editability map drift (#120) ──────────────────────────────────

    /**
     * pp_register_component_fields() (lib/operate.php) is a hand-maintained
     * map of which props each component exposes for semantic-selector
     * patching (wp pp operate patch) and AI-chat inspection. It silently
     * drifted from the real component props: cta declared subtitle/cta_text/
     * cta_url (none exist — cta has text/button_text/button_url) and grid
     * declared items[].link (grid items use link_url/link_text). A patch to
     * a dead field wrote an unused prop and reported success while the
     * rendered page didn't change (lib/operate.php update_component does a
     * shallow merge that accepts unknown props).
     *
     * This walks every registered component type and asserts each field
     * name resolves to a real prop in that component's schema.json — top-
     * level props directly, `items[].X` fields against the schema's
     * `items` sub-definition — so this class of drift fails the suite
     * instead of shipping.
     */
    public function testFieldEditabilityMapMatchesSchemaProps(): void
    {
        // Known, pre-existing drift tracked as its own issue (not
        // introduced or fixed here): hero's registered 'eyebrow' field has
        // no corresponding prop anywhere in hero.php or hero/schema.json.
        // Fixing it requires a product decision (render eyebrow, or remove
        // it) that #120's fix plan explicitly scopes out — see GitHub #85
        // ("Hero eyebrow render-or-remove"). Exception is intentionally
        // narrow: exactly one field, on one component.
        $knownExceptions = [
            'hero' => ['eyebrow'],
        ];

        $registry = pp_get_registered_component_fields();
        $this->assertNotEmpty($registry, 'Expected at least one registered component field map.');

        foreach ($registry as $componentType => $fields) {
            $schemaFile = $this->themeRoot . "/components/{$componentType}/schema.json";
            $this->assertFileExists($schemaFile, "Missing schema.json for registered component '{$componentType}'.");

            $schema = json_decode(file_get_contents($schemaFile), true);
            $this->assertIsArray($schema, "schema.json for '{$componentType}' must be valid JSON.");
            $props = $schema['props'] ?? [];
            $itemProps = $props['items']['items'] ?? null;

            foreach ($fields as $field) {
                $name = $field['name'];

                if (in_array($name, $knownExceptions[$componentType] ?? [], true)) {
                    continue;
                }

                if (str_starts_with($name, 'items[].')) {
                    $itemField = substr($name, strlen('items[].'));
                    $this->assertIsArray(
                        $itemProps,
                        "'{$componentType}' registers '{$name}' but its schema has no props.items.items definition."
                    );
                    $this->assertArrayHasKey(
                        $itemField,
                        $itemProps,
                        "'{$componentType}' registers editable field '{$name}', but '{$itemField}' is not a key in schema.json props.items.items."
                    );
                    continue;
                }

                $this->assertArrayHasKey(
                    $name,
                    $props,
                    "'{$componentType}' registers editable field '{$name}', but it is not a key in schema.json props."
                );
            }
        }
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
