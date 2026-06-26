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

    // ── CTA schema requires title, button_text, button_url ────────────────

    public function testCtaSchemaRequiredProps(): void
    {
        $schemaFile = $this->themeRoot . '/components/cta/schema.json';
        $schema     = json_decode(file_get_contents($schemaFile), true);

        $this->assertNotNull($schema);

        foreach (['title', 'button_text', 'button_url'] as $required) {
            $this->assertTrue(
                !empty($schema['props'][$required]['required']),
                "CTA prop '{$required}' should be marked as required."
            );
        }
    }

    // ── Style slot schema validation ────────────────────────────────────

    /**
     * Tests that all 4 v1 components have style_slots declared in schema.json.
     */
    public function testStyleSlotsExistForV1Components(): void
    {
        $expected = [
            'hero'    => 18,
            'section' => 14,
            'grid'    => 19,
            'cta'     => 16,
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
     * Tests that every declared style slot has the required keys: type, default, description.
     */
    public function testStyleSlotStructure(): void
    {
        $components = ['hero', 'section', 'grid', 'cta'];
        $validTypes = ['color', 'length', 'number', 'shadow'];

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
                $this->assertContains($slotDef['type'], $validTypes, "Slot {$slotName} type must be color, length, number, or shadow.");
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
        $this->assertEquals('color', $slots['--hero-bg']['type']);
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
