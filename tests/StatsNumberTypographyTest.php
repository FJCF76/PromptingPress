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
        // REPRICED AT #1066 PR2 FROM SLOTS TO A ROLE, claim intact. The two slots this
        // asserted (`--stats-number-font`, `--stats-number-weight`) retired with stats'
        // whole slot map; #472's capability did not. It is the `number` role's `typography`
        // group now, which carries family, weight, size and colour together.
        //
        // AND THE FAMILY'S ABSENCE IS THE INTERESTING HALF, carried over deliberately. v1
        // declared `font-family: var(--stats-number-font, inherit)` and this test pinned
        // `inherit` as the documented default. An explicit `inherit` IS a declaration — but
        // nothing in this theme declares font-family on a <span>, so the inherited body
        // face already lands and SILENCE IS BYTE-IDENTICAL (measured `system-ui, sans-serif`
        // either way, at all three tiers). So the role declares the GROUP but no `family`
        // default, which is the same rendered answer #472 shipped and one fewer unlayered
        // declaration competing with an author's.
        $schema = json_decode(
            file_get_contents($this->themeRoot . '/components/stats/schema.json'),
            true
        );
        $number = $schema['roles']['number'] ?? null;
        $this->assertNotNull($number, 'stats must declare a `number` role');

        $this->assertContains(
            'typography',
            $number['groups'],
            '#472 asked for the display figure to be heading-typography controllable per '
            . 'instance; the typography group is where that lives now'
        );

        $defaults = $number['defaults']['typography'] ?? [];
        $this->assertSame('700', $defaults['weight'] ?? null, "the pre-472 rendered weight, carried as a literal");
        $this->assertSame('2.5rem', $defaults['size'] ?? null);
        $this->assertArrayNotHasKey(
            'family',
            $defaults,
            'the family must NOT be defaulted: v1\'s `inherit` was a declaration that '
            . 'changed nothing, and restating it as a role default would emit an unlayered '
            . 'declaration where v1 emitted an inert one'
        );

        // The engine must still know every parameter the capability needs.
        foreach (['family', 'weight', 'size', 'color'] as $param) {
            $this->assertArrayHasKey(
                $param,
                pp_udc_groups()['typography']['params'],
                "typography.{$param} must exist, or #472's capability is not expressible"
            );
        }
    }

    public function testNewSlotsReuseExistingValidationFamilies(): void
    {
        // No new value grammar: `font-family` is the same type --section-panel-font
        // uses and `number` is the same type --hero-heading-weight uses. If either
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
        // The exact site-level ask in #472, through the v2 write surface: a serif heading
        // face at weight 600 on the display figures.
        $this->assertNull(
            pp_udc_validate_map(
                ['number' => ['typography' => ['family' => '@font-heading', 'weight' => '600']]],
                'stats'
            ),
            'the #472 ask must still be expressible: a heading face and weight on the figure'
        );
    }

    public function testLiteralFontStackValidatesThroughTheAuthoringSurface(): void
    {
        $this->assertNull(
            pp_udc_validate_map(
                ['number' => ['typography' => ['family' => 'Fraunces, Georgia, serif']]],
                'stats'
            ),
            'a literal stack must validate, not only a token reference'
        );
    }

    public function testQuotedFontStackSurvivesValidationAndRender(): void
    {
        // A quoted family name is the shape most likely to be mangled on the way to CSS.
        $map = ['number' => ['typography' => ['family' => '"Playfair Display", Georgia, serif']]];
        $this->assertNull(pp_udc_validate_map($map, 'stats'));

        $css = pp_udc_band_css([
            'component' => 'stats',
            'id'        => 'pp-1a2b3c4d',
            'props'     => [],
            'udc'       => $map,
        ]);
        $this->assertStringContainsString(
            '"Playfair Display", Georgia, serif',
            $css,
            'the quoted stack must reach the page unmangled — validation accepting it is '
            . 'not the same claim as it surviving to CSS (#1046: acceptance is not emission)'
        );
    }

    public function testEitherSlotIsIndependentlySettable(): void
    {
        // Each parameter stands alone: setting the weight must not require a family, and
        // setting a family must not force a weight.
        foreach ([['weight' => '600'], ['family' => '@font-heading']] as $one) {
            $this->assertNull(
                pp_udc_validate_map(['number' => ['typography' => $one]], 'stats'),
                'each typography parameter must be independently settable: ' . json_encode($one)
            );
        }
    }

    public function testTheWeightGrammarWidenedAtTheRebuildAndStillRefusesNonsense(): void
    {
        // THE CLAIM INVERTED AT #1066 PR2, AND THE INVERSION IS THE FINDING. #472 pinned
        // that `bold` was REFUSED, because `--stats-number-weight` was a generically
        // `number`-typed slot and a keyword is not a number. The `number` role's
        // `typography.weight` is a purpose-built `font-weight` type, which ACCEPTS the CSS
        // keywords — so the rebuild WIDENED this grammar rather than preserving it.
        //
        // That is a capability change, so it is asserted rather than quietly inherited: an
        // author writing `bold` used to get a refusal and now gets bold text. Recorded here
        // because a reader of #472 would otherwise reasonably expect the old refusal, and
        // because the widening is shared by every v2 component, not special to stats.
        foreach (['bold', 'bolder', '600', '700', '1000'] as $ok) {
            $this->assertNull(
                pp_udc_validate_map(['number' => ['typography' => ['weight' => $ok]]], 'stats'),
                "the font-weight type accepts `{$ok}`"
            );
        }

        // AND IT STILL REFUSES NONSENSE, which is what keeps the widening from being a
        // hole: a length, an invented keyword and a negative are all still errors.
        foreach (['600px', 'heavy', '-100'] as $bad) {
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_udc_validate_map(['number' => ['typography' => ['weight' => $bad]]], 'stats'),
                "`{$bad}` must still be refused — the type widened to CSS keywords, not to anything"
            );
        }
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
        // v1 emitted these as inline custom properties on the band; v2 emits them as real
        // declarations in a band-scoped block in the document head. The CLAIM is the same —
        // an authored value reaches the page — and it is asserted against the EMISSION
        // rather than against the schema, which is the #1046 rule.
        $css = pp_udc_band_css([
            'component' => 'stats',
            'id'        => 'pp-1a2b3c4d',
            'props'     => [],
            'udc'       => ['number' => ['typography' => [
                'family' => 'Fraunces, Georgia, serif',
                'weight' => '600',
            ]]],
        ]);
        $this->assertStringContainsString('font-family:Fraunces, Georgia, serif', $css);
        $this->assertStringContainsString('font-weight:600', $css);
        $this->assertStringContainsString('.stats__number', $css, 'both must land on the figure');
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
        // THE ORIGINAL CLAIM, AND IT IS STRONGER NOW. #472 required that the stylesheet
        // carry no literal font-weight on the figure, because a literal would beat the
        // slot. Under the v2 boundary the stylesheet carries no TYPOGRAPHY AT ALL on it —
        // the whole `.stats__number` rule is gone, and the fail-closed structural-CSS lint
        // makes its return a CI failure rather than a silent shadowing.
        $block = $this->statsBlock();
        foreach (['font-weight', 'font-family', 'font-size', 'color'] as $prop) {
            $this->assertStringNotContainsString(
                $prop,
                $block,
                "the stats block must declare no {$prop}: every one of those is a `number` "
                . 'role parameter now, and a stylesheet literal would shadow the author'
            );
        }
        $this->assertStringNotContainsString('.stats__number', $block, 'the rule itself is gone');
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
