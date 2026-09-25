<?php
/**
 * tests/UdcRolePaintTest.php — what paints ON A ROLE (#1125, landed on the compiled band).
 *
 * pp_udc_role_paint() is the renderer's answer to "which ink and which surface paint on this
 * role's element, per card, state and width". The own-surface finding reads it, so these tests
 * pin it against what the renderer EMITS: each case asserts the emitted CSS as its premise (the
 * UdcEffectiveBackgroundTest pattern), then the accessor's ATTRIBUTION — which tier won — because a
 * computed style in a browser proves the final value but not which rule supplied it. The Chromium
 * read of the same cases lives in tests/e2e/role-own-surface.spec.ts.
 */

use PHPUnit\Framework\TestCase;

final class UdcRolePaintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** [paint by "item|role", defaults css, band css] for one band, read the way the findings walk reads it. */
    private function read(array $item, ?bool $marked = null): array
    {
        $component = (string) $item['component'];
        $authored  = pp_udc_compile_band($item, 'authored');
        $defaults  = pp_udc_compile_band(['component' => $component], 'defaults');
        $marked    = $marked ?? (!pp_udc_is_chrome($component) && pp_udc_band_paints_scrim($authored));
        $by        = [];
        foreach (pp_udc_role_paint($item, $authored, $defaults, $marked) as $element) {
            $by[$element['item'] . '|' . $element['role']] = $element['paint'];
        }
        $defaults_css = pp_udc_is_chrome($component) ? pp_udc_chrome_css($component, 'defaults') : pp_udc_component_defaults_css($component);
        return [$by, $defaults_css, pp_udc_band_css($item)];
    }

    private function band(string $component, array $udc, array $props = []): array
    {
        return ['component' => $component, 'id' => 'pp-a1b2c3d4', 'props' => $props, 'udc' => $udc];
    }

    /** The #1125 trap: the ink is the author's (band tier), the surface is the eyebrow's default pill. */
    public function testTheTrapAttributesTheInkToTheBandAndTheSurfaceToTheDefaults(): void
    {
        [$by, $defaults_css, $band_css] = $this->read($this->band('hero', [
            '_band'   => ['background' => ['fill' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '#ffffff']],
        ]));
        $this->assertMatchesRegularExpression('/\[data-pp-component="hero"\] \.hero__eyebrow\{[^}]*background:var\(--color-surface-accent\)/', $defaults_css, 'premise: the default pill');
        $this->assertStringContainsString('[data-pp-band="pp-a1b2c3d4"] .hero__eyebrow{color:#ffffff;}', $band_css, 'premise: the author ink');

        foreach (['d', 't', 'p'] as $bp) {
            $cell = $by['|eyebrow']['']['d' === $bp ? 'd' : $bp];
            $this->assertSame('band', $cell['color']['tier'], $bp);
            $this->assertSame('#ffffff', $cell['color']['css'], $bp);
            $this->assertSame('defaults', $cell['surface']['tier'], $bp);
            $this->assertSame('background-color', $cell['surface']['property'], $bp);
            $this->assertSame('var(--color-surface-accent)', $cell['surface']['css'], $bp);
        }
    }

    /** An authored fill prints after the default at equal specificity: it wins the surface. */
    public function testAnAuthoredFillOutranksTheDefaultFill(): void
    {
        [$by, , $band_css] = $this->read($this->band('hero', [
            '_band'   => ['background' => ['fill' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '#ffffff'], 'background' => ['fill' => '#1d2939']],
        ]));
        $this->assertStringContainsString('background:#1d2939', $band_css, 'premise');
        $this->assertSame('band', $by['|eyebrow']['']['d']['surface']['tier']);
        $this->assertSame('#1d2939', $by['|eyebrow']['']['d']['surface']['css']);
    }

    /**
     * A DEFAULT's state rule outranks an AUTHORED resting rule on specificity (0,3,0 over 0,2,0), so in
     * `:hover` the secondary button's default hover ink and hover fill paint, whatever the author set
     * at rest. Order alone would have said the opposite.
     */
    public function testADefaultStateRuleOutranksAnAuthoredRestingRule(): void
    {
        [$by, $defaults_css] = $this->read($this->band('cta', [
            '_band'            => ['background' => ['fill' => '#101828']],
            'button-secondary' => ['typography' => ['color' => '#ffffff']],
        ], ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x']));
        $this->assertStringContainsString('.cta__button--secondary:hover{', $defaults_css, 'premise: a default :hover rule exists');

        $rest  = $by['|button-secondary']['']['d'];
        $hover = $by['|button-secondary'][':hover']['d'];
        $this->assertSame('band', $rest['color']['tier']);
        $this->assertNull($rest['surface'], 'transparent at rest: no surface');
        $this->assertSame('defaults', $hover['color']['tier'], 'the default hover ink wins in :hover');
        $this->assertSame('defaults', $hover['surface']['tier']);
    }

    /** An AUTHORED base rule prints after the DEFAULT's @media rule at equal specificity: it wins at that width. */
    public function testAnAuthoredBaseRuleOutranksADefaultNarrowTier(): void
    {
        $nav = ['component' => 'nav', 'id' => 'nav', 'props' => [], 'udc' => [
            '_band' => ['background' => ['fill' => '#101828']],
            'menu'  => ['typography' => ['color' => '#f7f8fa'], 'background' => ['fill' => '#101828']],
        ]];
        [$by, $defaults_css] = $this->read($nav);
        $this->assertMatchesRegularExpression('/@media \(max-width: 767px\)\{[^@]*\.nav__menu[^}]*background:var\(--color-bg\)/', $defaults_css, 'premise: the phone-only default fill');
        $this->assertSame('band', $by['|menu']['']['p']['surface']['tier']);
        $this->assertSame('#101828', $by['|menu']['']['p']['surface']['css']);
    }

    /** A default narrow tier applies at its own width only (disjoint breakpoint ranges). */
    public function testADefaultNarrowTierAppliesOnlyAtItsWidth(): void
    {
        [$by] = $this->read(['component' => 'nav', 'id' => 'nav', 'props' => [], 'udc' => [
            '_band' => ['background' => ['fill' => '#101828']],
            'menu'  => ['typography' => ['color' => '#f7f8fa']],
        ]]);
        $this->assertNull($by['|menu']['']['d']['surface'], 'transparent on desktop');
        $this->assertNull($by['|menu']['']['t']['surface'], 'the tablet width inherits desktop, not phone');
        $this->assertSame('defaults', $by['|menu']['']['p']['surface']['tier']);
    }

    /** An item rule outranks the band rule for the same role on ITS card, and only there. */
    public function testAnItemRuleOutranksABandRuleOnItsOwnCardOnly(): void
    {
        $grid = $this->band('grid', [
            '_band' => ['background' => ['fill' => '#101828']],
            'card'  => ['background' => ['fill' => '#1d2939']],
        ], ['title' => 'G', 'items' => [
            ['id' => 'it-0000ab01', 'title' => 'A', 'udc' => ['card' => ['background' => ['fill' => '#334155']]]],
            ['id' => 'it-0000ab02', 'title' => 'B'],
        ]]);
        [$by, , $band_css] = $this->read($grid);
        $this->assertStringContainsString('[data-pp-item="it-0000ab01"]{background:#334155;}', $band_css, 'premise: the card rule');
        $this->assertSame('item', $by['it-0000ab01|card']['']['d']['surface']['tier']);
        $this->assertSame('#334155', $by['it-0000ab01|card']['']['d']['surface']['css']);
        $this->assertSame('band', $by['|card']['']['d']['surface']['tier'], 'the card with no map of its own');
        $this->assertArrayNotHasKey('it-0000ab02|card', $by, 'a card with no map is the generic element');
    }

    /** Every card has its own map: there is no generic element left to answer for. */
    public function testNoGenericCardWhenEveryCardHasItsOwnMap(): void
    {
        [$by] = $this->read($this->band('grid', ['_band' => ['background' => ['fill' => '#101828']]], ['title' => 'G', 'items' => [
            ['id' => 'it-0000ab01', 'title' => 'A', 'udc' => ['card' => ['typography' => ['color' => '#ffffff']]]],
        ]]));
        $this->assertArrayHasKey('it-0000ab01|card', $by);
        $this->assertArrayNotHasKey('|card', $by);
    }

    /** The overlay tier is read exactly when the band is marked, and ranks between defaults and the author. */
    public function testTheOverlayTierIsReadOnlyOnAMarkedBand(): void
    {
        $scrim = ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(6,10,28,0.72)']]];
        [$by] = $this->read($this->band('hero', $scrim));
        $this->assertSame('overlay', $by['|title-accent']['']['d']['color']['tier']);
        $this->assertSame('var(--color-accent-on-overlay)', $by['|title-accent']['']['d']['color']['css']);

        [$by] = $this->read($this->band('hero', $scrim), false);
        $this->assertSame('defaults', $by['|title-accent']['']['d']['color']['tier'], 'unmarked: no overlay tier');

        [$by] = $this->read($this->band('hero', $scrim + ['title-accent' => ['typography' => ['color' => '#8fd0ff']]]));
        $this->assertSame('band', $by['|title-accent']['']['d']['color']['tier'], 'the author still wins');
    }

    /** A `background` shorthand is read as both longhands, in declaration order. */
    public function testAShorthandSetsBothLonghands(): void
    {
        $this->assertSame(['background-color' => ['#fff', '#fff'], 'background-image' => ['none', 'none']],
            _pp_udc_paint_longhands('background', ['css' => '#fff', 'literal' => '#fff']));
        $this->assertSame(['background-image' => ['linear-gradient(#000,#111)', 'linear-gradient(#000,#111)'], 'background-color' => ['transparent', 'transparent']],
            _pp_udc_paint_longhands('background', ['css' => 'linear-gradient(#000,#111)']));
        $this->assertSame(['color' => ['var(--x)', '#123']], _pp_udc_paint_longhands('color', ['css' => 'var(--x)', 'literal' => '#123']));
        $this->assertSame([], _pp_udc_paint_longhands('padding', ['css' => '1rem']));

        // An author `_css` background-image of `none` removes nothing the colour paints: the colour is the surface.
        [$by] = $this->read($this->band('hero', [
            '_band'   => ['background' => ['fill' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '#ffffff'], '_css' => ['background-image' => 'none']],
        ]));
        $this->assertSame('defaults', $by['|eyebrow']['']['d']['surface']['tier']);
    }

    /** The surface classifier: non-surfaces are named; anything unread is a surface, never a silence. */
    public function testTheSurfaceClassifier(): void
    {
        foreach (['', 'transparent', ' Transparent ', 'none', 'initial', 'unset', 'rgba(255,255,255,0)', '#ffffff00'] as $value) {
            $this->assertFalse(_pp_udc_paints_surface($value, 'background-color'), $value);
        }
        foreach (['#fff', 'currentColor', 'inherit', 'var(--unknown)', 'color-mix(in srgb, red, blue)', 'rgba(0,0,0,0.5)'] as $value) {
            $this->assertTrue(_pp_udc_paints_surface($value, 'background-color'), $value);
        }
        $this->assertFalse(_pp_udc_paints_surface('none', 'background-image'));
        $this->assertFalse(_pp_udc_paints_surface('transparent', 'background-image'), 'the keyword list alone decides for an image');
        $this->assertTrue(_pp_udc_paints_surface('linear-gradient(rgba(0,0,0,0),rgba(0,0,0,0))', 'background-image'), 'a gradient is an image');
    }

    /** Specificity of the selectors the renderer prints. */
    public function testSpecificityOfRendererSelectors(): void
    {
        $this->assertSame([0, 2, 0], _pp_udc_selector_specificity('[data-pp-band="pp-a"] .hero__eyebrow'));
        $this->assertSame([0, 3, 0], _pp_udc_selector_specificity('[data-pp-band="pp-a"] .hero__eyebrow:hover'));
        $this->assertSame([0, 2, 0], _pp_udc_selector_specificity(':where([data-pp-component="hero"])[data-pp-band-overlay] .hero__title-accent'));
        $this->assertSame([0, 0, 0], _pp_udc_selector_specificity(':where([data-pp-component="hero"])'));
        $this->assertSame([0, 2, 3], _pp_udc_selector_specificity('[data-pp-chrome="nav"] .nav__menu ul li a'));
        $this->assertSame([0, 4, 0], _pp_udc_selector_specificity('[data-pp-band="b"] .faq__item[open] > .faq__question'));
        $this->assertSame([0, 3, 0], _pp_udc_selector_specificity('[data-pp-band="b"] [data-pp-item="it-1"] .x'));
    }

    /** No usable id: the renderer emits no band CSS, so nothing here is answered. */
    public function testABandWithNoUsableIdReadsNothing(): void
    {
        $item = ['component' => 'hero', 'id' => 'not an id', 'udc' => ['eyebrow' => ['typography' => ['color' => '#fff']]]];
        $this->assertSame([], pp_udc_role_paint($item, pp_udc_compile_band($item, 'authored'), pp_udc_compile_band(['component' => 'hero'], 'defaults'), false));
    }
}
