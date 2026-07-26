<?php
/**
 * tests/StatsNumberTypographyTest.php
 *
 * Issue 472 — the stats band's display numbers are heading-typography
 * controllable per instance.
 *
 * Before 472 the number's typography was half-authorable: `--stats-number-size`
 * and `--stats-number-color` existed, but the family was never declared (so the
 * number silently took the page BODY font) and the weight was the literal 700.
 * A site on a serif heading system could not make its biggest figures match its
 * headings without editing components.css.
 *
 *   .stats__item                 (li, flex column)
 *     ├── .stats__number   <span>  ← --stats-number-font / -weight / -size / -color
 *     └── .stats__label    <span>  ← SIBLING, not a child: it keeps the body font
 *                                    no matter what the number's family is set to.
 *
 * Three layers are pinned here:
 *   1. Schema — the two new slots are declared with the types the shared
 *      validation engine already owns (`font-family` -> _pp_validate_font_family,
 *      `number` -> _pp_validate_number). No new value grammar was invented.
 *   2. Authoring path — real writes through pp_validate_composition() with the
 *      top-level `style` key are accepted for good values and rejected for bad
 *      ones (Section 14.1: raw _pp_composition meta seeding would bypass this).
 *   3. CSS contract — the base rule routes both properties through the slots with
 *      fallbacks that reproduce the pre-472 render EXACTLY (`inherit` and `700`),
 *      and nothing in the stats block re-declares either property literally.
 *
 * Why the fallbacks are literals and not the heading tokens: see the
 * `.stats__number` rule in assets/css/components.css, which carries the full
 * rationale next to the code. testUnsetStatsRenderIsByteIdentical pins the
 * consequence here.
 *
 * The generic "every declared slot is consumed on a type-compatible property and
 * is not defeated by a literal re-declaration" proof is owned by
 * StyleSlotContractTest (#305), which auto-discovers these two slots from
 * schema.json; the exact fallback literals are pinned there too
 * (testIssue472StatsNumberTypographySlotFallbacks), alongside the sibling
 * byte-identical-unset pins for issues 293/296/514. The rendered paint
 * (computed weight, family swap at 375/1280) is pinned in
 * tests/e2e/style-render.spec.ts.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class StatsNumberTypographyTest extends TestCase
{
    private string $themeRoot;
    private string $componentsCss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot     = dirname(__DIR__);
        $this->componentsCss = file_get_contents($this->themeRoot . '/assets/css/components.css');
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
    }

    private function render(array $props): string
    {
        ob_start();
        pp_get_component('stats', $props);
        return ob_get_clean();
    }

    private function statsProps(array $overrides = []): array
    {
        return array_merge([
            'title' => 'By the numbers',
            'items' => [
                ['number' => '+30', 'label' => 'Years of experience'],
                ['number' => '100+', 'label' => 'Clients served'],
            ],
        ], $overrides);
    }

    /** The COMPONENT: stats block through the next COMPONENT header. */
    private function statsBlock(): string
    {
        preg_match(
            '/COMPONENT:\s*stats\b(.*?)(?=\/\*\s*={5,}[^*]*?COMPONENT:|\z)/s',
            $this->componentsCss,
            $m
        );
        $block = $m[1] ?? '';
        $this->assertNotEmpty($block, 'The COMPONENT: stats block must be locatable in components.css.');
        return $block;
    }

    // ── 1. Schema ─────────────────────────────────────────────────────────

    public function testSchemaDeclaresBothNumberTypographySlots(): void
    {
        $schema = json_decode(
            file_get_contents($this->themeRoot . '/components/stats/schema.json'),
            true
        );
        $slots = $schema['styling']['style_slots'];

        $expected = [
            '--stats-number-font'   => ['font-family', 'inherit'],
            '--stats-number-weight' => ['number', '700'],
        ];
        foreach ($expected as $name => [$type, $default]) {
            $this->assertArrayHasKey($name, $slots, "stats must declare {$name}.");
            $this->assertSame($type, $slots[$name]['type'], "{$name} must be type {$type}.");
            $this->assertSame(
                $default,
                $slots[$name]['default'],
                "{$name}'s documented default must be the pre-472 rendered value ({$default})."
            );
            $this->assertIsString(
                $slots[$name]['default'],
                "{$name}'s default must be a STRING like every other slot default — a JSON number would be a different shape for the schema-derived surfaces to render."
            );
            $this->assertNotEmpty($slots[$name]['description']);
        }
    }

    public function testNewSlotsReuseExistingValidationFamilies(): void
    {
        // No new value grammar: `font-family` is the same type --section-panel-font
        // uses and `number` is the same type --hero-title-weight uses. If either
        // type disappears from the shared engine, the slots silently become
        // unvalidated — pin the engine's acceptance instead of trusting the name.
        $this->assertTrue(_pp_validate_token_value('var(--font-heading)', 'font-family'));
        $this->assertTrue(_pp_validate_token_value('Fraunces, Georgia, serif', 'font-family'));
        $this->assertTrue(_pp_validate_token_value('600', 'number'));

        $this->assertInstanceOf(\WP_Error::class, _pp_validate_token_value('bold', 'number'));
        $this->assertInstanceOf(\WP_Error::class, _pp_validate_token_value('600px', 'number'));
    }

    // ── 2. Authoring path (Section 14.1 — through the real write surface) ──

    public function testHeadingSystemTypographyValidatesThroughTheAuthoringSurface(): void
    {
        // The exact site-level ask in #472: a serif heading face at weight 600 on
        // the display figures, authored as a composition write (create_page /
        // update_composition), not a raw _pp_composition meta seed.
        $composition = [[
            'component' => 'stats',
            'props'     => $this->statsProps(),
            'style'     => [
                '--stats-number-font'   => 'var(--font-heading)',
                '--stats-number-weight' => '600',
            ],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'A serif-heading stats band must validate through the shared style-slot engine.'
        );
    }

    public function testLiteralFontStackValidatesThroughTheAuthoringSurface(): void
    {
        $composition = [[
            'component' => 'stats',
            'props'     => $this->statsProps(),
            'style'     => ['--stats-number-font' => 'Fraunces, Georgia, serif'],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'A comma-separated literal font stack must validate.'
        );
    }

    public function testQuotedFontStackSurvivesValidationAndRender(): void
    {
        // A family name with spaces is quoted in real CSS, and the slot-type table
        // in ai-instructions/style-component.md advertises exactly that shape
        // (`"Inter", sans-serif`). Quotes clear the injection guard, so the only
        // question is whether they survive the render boundary and esc_attr —
        // they must arrive in the attribute as an entity the browser decodes back
        // to a quote, not get dropped as an unrenderable value.
        $value       = '"Fraunces", Georgia, serif';
        $composition = [[
            'component' => 'stats',
            'props'     => $this->statsProps(),
            'style'     => ['--stats-number-font' => $value],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'A quoted font stack must validate.'
        );
        $this->assertTrue(
            pp_render_style_value_allowed($value, 'font-family'),
            'A quoted font stack must survive the #330 render boundary, not be silently dropped.'
        );
        $html = $this->render($this->statsProps(['__pp_style' => ['--stats-number-font' => $value]]));
        $this->assertStringContainsString('--stats-number-font: &quot;Fraunces&quot;, Georgia, serif', $html);
    }

    public function testEitherSlotIsIndependentlySettable(): void
    {
        foreach ([
            ['--stats-number-weight' => '600'],
            ['--stats-number-font' => 'var(--font-heading)'],
        ] as $style) {
            $composition = [[
                'component' => 'stats',
                'props'     => $this->statsProps(),
                'style'     => $style,
            ]];
            $this->assertTrue(
                pp_validate_composition($composition),
                'Each slot must be settable on its own, not only as a pair: ' . key($style)
            );
        }
    }

    public function testKeywordWeightIsRejectedByTheAuthoringSurface(): void
    {
        // `bold` is a legal CSS font-weight but not a unitless number, so the
        // `number` type rejects it — the same bound --hero-title-weight carries.
        // The write is refused at the authoring boundary; nothing persists.
        $composition = [[
            'component' => 'stats',
            'props'     => $this->statsProps(),
            'style'     => ['--stats-number-weight' => 'bold'],
        ]];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_value', $result->get_error_code());
    }

    public function testInjectionInEitherSlotIsRejectedByTheAuthoringSurface(): void
    {
        foreach ([
            ['--stats-number-font'   => 'serif} .x{color:red'],
            ['--stats-number-weight' => '600; color:red'],
        ] as $style) {
            $composition = [[
                'component' => 'stats',
                'props'     => $this->statsProps(),
                'style'     => $style,
            ]];
            $result = pp_validate_composition($composition);
            $this->assertInstanceOf(
                \WP_Error::class,
                $result,
                'A CSS breakout payload must be rejected on ' . key($style)
            );
        }
    }

    // ── 3. Render ─────────────────────────────────────────────────────────

    public function testBothSlotsRenderAsInlineCustomProperties(): void
    {
        $overrides = [
            '--stats-number-font'   => 'var(--font-heading)',
            '--stats-number-weight' => '600',
        ];
        $html = $this->render($this->statsProps(['__pp_style' => $overrides]));
        foreach ($overrides as $slot => $value) {
            $this->assertStringContainsString("{$slot}: {$value}", $html, "{$slot} did not render.");
        }
    }

    public function testUnsetStatsRenderIsByteIdentical(): void
    {
        // The compatibility guarantee, asserted on the actual bytes: a stats band
        // with no style map must not gain a single new inline custom property
        // from #472. (Schema `default` is documentation for the AI-facing
        // surfaces, never a value materialized into the rendered style map.)
        $html = $this->render($this->statsProps());
        $this->assertStringNotContainsString('--stats-number-font', $html);
        $this->assertStringNotContainsString('--stats-number-weight', $html);
    }

    // ── 4. CSS contract ───────────────────────────────────────────────────

    // The exact fallback literals (`inherit` / `700`) are pinned in
    // StyleSlotContractTest::testIssue472StatsNumberTypographySlotFallbacks, where
    // every other byte-identical-unset fallback pin lives. What this file owns is
    // the complementary proof below: that no OTHER declaration anywhere in the
    // stylesheet competes for those two properties on this element.

    public function testNoLiteralFontWeightSurvivesOnTheNumber(): void
    {
        // The #302 dead-slot class: a slot is declared and consumed, but a literal
        // re-declaration elsewhere on the same element wins, so style_component
        // reports Success and the page does not move. Scan every rule in the WHOLE
        // stylesheet whose selector targets .stats__number and require any
        // font-family/font-weight declaration on it to consume the slot.
        $css = preg_replace('!/\*.*?\*/!s', '', $this->componentsCss);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

        $seen = 0;
        foreach ($rules as [, $selector, $body]) {
            if (!preg_match('/\.stats__number(?![-\w])/', $selector)) {
                continue;
            }
            foreach (['font-family', 'font-weight'] as $property) {
                if (!preg_match_all('/(?<![-a-z])' . $property . '\s*:\s*([^;}]+)/i', $body, $decls)) {
                    continue;
                }
                foreach ($decls[1] as $value) {
                    $seen++;
                    $this->assertMatchesRegularExpression(
                        '/var\(\s*--stats-number-(font|weight)\b/',
                        $value,
                        "A literal {$property} on `" . trim($selector) . "` would defeat the slot."
                    );
                }
            }
        }
        $this->assertSame(
            2,
            $seen,
            'Exactly the two slotted declarations (family + weight) should exist on .stats__number.'
        );
    }

    public function testLabelTypographyIsUntouchedByTheNumberSlots(): void
    {
        // .stats__label is a SIBLING of .stats__number, so setting a display face
        // on the number can never inherit into the label. Pin that no rule wires a
        // --stats-number-* slot onto the label (the "slot leaks to the neighbour"
        // failure mode this element layout rules out).
        $block = $this->statsBlock();
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $block, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $selector, $body]) {
            if (!preg_match('/\.stats__label(?![-\w])/', $selector)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/var\(\s*--stats-number-(font|weight)\b/',
                $body,
                'The label must not consume the number typography slots.'
            );
        }
    }
}
