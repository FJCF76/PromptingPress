<?php
/**
 * tests/OverlayAccentOffScrimTest.php — the overlay tier's residual, disclosed (#1010 review,
 * 7A ruling 1 = A).
 *
 * THE DEFECT. The overlay tier re-lights the accent inks to the near-white on-overlay ink on
 * every band the engine marks overlaid, on the premise that the accent sits on a dark scrim.
 * Authored shapes break that premise and the write said nothing: a light surface on a role
 * that encloses the accent, or on the accent itself (#fafbff on #ffffff, 1.03:1); a scrim set
 * only at some widths; a scrim that is light, fades to transparent, or cannot be read.
 *
 * THE RULING (1 = A). The tier stays (D4 = B); the engine discloses rather than outguesses.
 * `udc_overlay_accent_off_scrim` names the re-lit accent roles AND the triggering condition.
 * Which roles enclose an accent is schema data (`within`). Authored wins: an accent whose
 * RESTING ink the author set at every width is not re-lit and not named.
 */

use PHPUnit\Framework\TestCase;

final class OverlayAccentOffScrimTest extends TestCase
{
    private const TYPE = 'udc_overlay_accent_off_scrim';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        foreach ([9001, 9003] as $id) {
            $GLOBALS['_pp_test_store']['posts'][$id]               = ['post_type' => 'attachment'];
            $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = true;
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private const DARK_SCRIM = ['image' => 9001, 'overlay' => 'rgba(6,10,28,0.72)'];

    private function found(array $udc, string $component = 'cta', array $props = ['title' => 'C', 'title_accent' => 'A', 'button_text' => 'Go', 'button_url' => '/x']): array
    {
        return array_values(array_filter(pp_udc_composition_findings([[
            'component' => $component, 'id' => 'pp-a1b2c3d4', 'udc' => $udc, 'props' => $props,
        ]]), static fn (array $f): bool => $f['type'] === self::TYPE));
    }

    /** The negative: a full-coverage dark scrim and no other surface is the premise holding. */
    public function testAFullCoverageDarkScrimWithNoOtherSurfaceSaysNothing(): void
    {
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM]]));
        $this->assertSame([], $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => '@overlay-bg']]]),
            'the theme\'s own scrim token resolves dark');
    }

    /** Trigger 1: a light panel the author set, named with the role and its value. */
    public function testALightPanelUnderTheAccentIsNamed(): void
    {
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['fill' => '#ffffff']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "heading-accent" re-lights', $found[0]['message']);
        $this->assertStringContainsString('role "text", which encloses it, has a background you set (#ffffff)', $found[0]['message']);
        $this->assertSame(0, $found[0]['index']);

        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['fill' => '#101828']]]),
            'a dark panel keeps the premise');
        $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['fill' => '@color-bg']]]),
            'a light token resolves light');
        $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['image' => 9003]]]),
            'an image panel cannot be read, so it is named');
    }

    /** Trigger 2: a scrim at some widths only, named with the widths on each side. */
    public function testASingleBreakpointScrimIsNamedWithItsWidths(): void
    {
        $found = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => ['p' => 'rgba(0,0,0,0.6)']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('the scrim is set only at the phone width, so at the desktop and tablet widths the accent sits', $found[0]['message']);

        $this->assertSame([], $this->found(['_band' => ['background' => ['image' => 9001,
            'overlay' => ['d' => 'rgba(0,0,0,0.6)', 'p' => 'rgba(0,0,0,0.7)']]]]), 'a base-tier scrim covers every width');
    }

    /** Trigger 3: a light scrim. */
    public function testALightScrimIsNamed(): void
    {
        $found = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.8)']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('the scrim you set is light (rgba(255,255,255,0.8))', $found[0]['message']);
        $this->assertCount(1, $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => '@color-bg']]]));
        $thin = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.1)']]]);
        $this->assertCount(1, $thin, 'a thin wash is the image, not the colour: named as partly transparent, not as light');
        $this->assertStringContainsString('is transparent in part', $thin[0]['message']);
    }

    /** Authored wins: an accent whose ink the author set is neither re-lit nor named. */
    public function testOnlyAccentsStillReLitAreNamed(): void
    {
        $light = ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.8)'];
        $this->assertSame([], $this->found(['_band' => ['background' => $light], 'heading-accent' => ['typography' => ['color' => '#1d2939']]]));

        $found = $this->found(['_band' => ['background' => $light], 'heading-accent' => ['typography' => ['color' => '#1d2939']]],
            'stats', ['title' => 'S', 'title_accent' => 'A', 'items' => []]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "number" re-lights', $found[0]['message']);
        $this->assertStringNotContainsString('heading-accent', $found[0]['message']);
    }

    /** No marker, no tier, nothing to disclose: a deleted image paints no scrim. */
    public function testABandWithoutTheMarkerSaysNothing(): void
    {
        $this->assertSame([], $this->found(['_band' => ['background' => ['image' => 9002, 'overlay' => 'rgba(255,255,255,0.8)']]]));
    }

    /** The write envelope carries it, as a warning on the band. */
    public function testTheWriteEnvelopeCarriesTheFinding(): void
    {
        $id = pp_create_page('scrim', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'hero',
            'udc'       => ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.8)']]],
            'props'     => ['title' => 'T', 'title_accent' => 'A', 'layout' => 'centered'],
        ]]]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $found = array_values(array_filter($result['findings'] ?? [], static fn ($f) => ($f['type'] ?? '') === self::TYPE));
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "title-accent" re-lights', $found[0]['message']);
    }

    /**
     * The colour reader, branch by branch: every syntax it claims to read, the alpha floor on
     * each alpha-carrying form, and the unread fallthrough (null, "unknown").
     */
    public function testTheLightnessReaderClassifiesEveryColourSyntaxItReads(): void
    {
        $cases = [
            // hex, all four lengths
            '#fff'                          => true,
            '#000'                          => false,
            '#ffff'                         => true,
            '#fff0'                         => false,  // alpha 0: the image, not the colour
            '#ffffff'                       => true,
            '#101828'                       => false,
            '#ffffffcc'                     => true,
            '#ffffff33'                     => false,  // 0.2 alpha, under the 0.3 floor
            '#FFFFFF'                       => true,   // case-insensitive
            // rgb()/rgba(), comma and space/slash syntax, numeric and % alpha
            'rgb(255,255,255)'              => true,
            'rgba(255,255,255,0.3)'         => true,   // the floor is inclusive
            'rgba(255,255,255,0.29)'        => false,
            'rgba(255,255,255,80%)'         => true,
            'rgba(255,255,255,10%)'         => false,
            'rgb(255 255 255 / 0.8)'        => true,
            'rgb(255 255 255 / 20%)'        => false,
            'rgb(0 0 0)'                    => false,
            // keywords
            'white'                         => true,
            'black'                         => false,
            // gradients: any light stop over the floor makes the surface light
            'linear-gradient(#000, #fff)'   => true,
            'linear-gradient(#000, #101828)' => false,
            // tokens: site token, one level of var()
            '@color-bg'                     => true,
            'var(--color-bg)'               => true,
            '@color-bg-inverted'            => false,
            // unread syntax: unknown, never a guess
            'hsl(0 0% 100%)'                => true,
            'hsla(0, 0%, 100%, 0.7)'        => true,
            'hsl(220, 40%, 10%)'            => false,
            'hsl(220deg 40% 10% / 50%)'     => false,
            'color-mix(in srgb, white 50%, black)' => null,
            'rgb(var(--x), 1, 1)'           => null,
            'currentColor'                  => null,
            '@no-such-token'                => null,
            'var(--no-such-token)'          => null,
        ];
        foreach ($cases as $value => $expected) {
            $this->assertSame($expected, _pp_udc_value_is_light((string) $value, []), (string) $value);
        }
        $this->assertTrue(_pp_udc_value_is_light('@scrim', ['scrim' => 'rgba(255,255,255,0.9)']), 'band token first');
        $this->assertFalse(_pp_udc_value_is_light('@color-bg', ['color-bg' => '#000000']), 'a band token shadows the site token');
        $this->assertSame([[255, 255, 255, 1.0], [0, 0, 0, 1.0]], _pp_udc_value_colours('white black', []));
    }

    /**
     * The finding reads what the page paints (design ruling). A value the write gate refuses
     * and the emitter does not paint (a color-mix() scrim or fill, an unresolved reference)
     * leaves no scrim or surface to disclose, so there is nothing to name; the reader itself
     * still answers "unknown" for such a value rather than guessing.
     */
    public function testAValueThePageDoesNotPaintIsNotNamed(): void
    {
        foreach ([['_band' => ['background' => ['image' => 9001, 'overlay' => 'color-mix(in srgb, white 50%, black)']]],
            ['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['fill' => 'color-mix(in srgb, white 50%, black)']]],
            ['_band' => ['background' => ['image' => 9001, 'overlay' => 'linear-gradient(@no-such-token, rgba(0,0,0,0.7))']]]] as $udc) {
            $this->assertInstanceOf(WP_Error::class, pp_udc_validate_map($udc, 'cta'), 'premise: the gate refuses it');
            $css = pp_udc_band_css(['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => $udc]);
            $this->assertStringNotContainsString('color-mix', $css, 'premise: the page does not paint it');
            $this->assertStringNotContainsString('no-such-token', $css);
            $this->assertSame([], $this->found($udc));
        }
        $this->assertNull(_pp_udc_value_is_light('color-mix(in srgb, white 50%, black)', []));
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['fill' => 'hsl(0 0% 10%)']]]),
            'a dark hsl() panel is read as dark');
    }

    /** A transparent panel is no surface; a breakpoint-map panel is named once, on its light tier. */
    public function testPanelFillsTransparentAndPerBreakpoint(): void
    {
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['fill' => 'TRANSPARENT']]]));
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM],
            'text' => ['background' => ['fill' => ['d' => '#101828', 'p' => '#ffffff', 't' => '#fafafa']]]]);
        $this->assertCount(1, $found, 'one condition per role, not one per tier');
        $this->assertStringContainsString('(#ffffff)', $found[0]['message']);
    }

    /** A light surface on the accent itself (a highlighter behind the word) is named as such. */
    public function testALightSurfaceOnTheAccentItselfIsNamed(): void
    {
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'heading-accent' => ['background' => ['fill' => '#ffffff']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "heading-accent", the accent itself, has a background you set (#ffffff)', $found[0]['message']);
        $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM],
            'title-accent' => ['_css' => ['background' => 'linear-gradient(transparent 60%, #fde68a 60%)']]],
            'hero', ['title' => 'T', 'title_accent' => 'A', 'layout' => 'centered']), 'a highlighter gradient');
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'heading-accent' => ['background' => ['fill' => '#101828']]]),
            'a dark chip keeps the premise');
        $image = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'heading-accent' => ['background' => ['image' => 9003]]]);
        $this->assertCount(1, $image);
        $this->assertStringContainsString('role "heading-accent", the accent itself, has a background image you set', $image[0]['message']);
    }

    /** Several re-lit roles are named together, in the plural. */
    public function testSeveralReLitRolesAreNamedInThePlural(): void
    {
        $found = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.9)']]],
            'stats', ['title' => 'S', 'title_accent' => 'A', 'items' => []]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('roles "heading-accent", "number" re-light to', $found[0]['message']);
    }

    /** A two-tier scrim missing the base tier names both named widths and the missing one. */
    public function testATwoTierScrimNamesTheUncoveredWidth(): void
    {
        $found = $this->found(['_band' => ['background' => ['image' => 9001,
            'overlay' => ['t' => 'rgba(0,0,0,0.6)', 'p' => 'rgba(0,0,0,0.6)']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('only at the tablet and phone widths, so at the desktop width', $found[0]['message']);
    }

    /** A scrim a preset supplies is read like the map's own, band tokens included. */
    public function testAPresetSuppliedOrTokenScrimIsRead(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'light-wash', 'grain' => 'role',
            'udc' => ['background' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.85)']]])['ok']);
        $found = $this->found(['_band' => ['_preset' => 'light-wash']]);
        $this->assertCount(1, $found, 'role-grain preset scrim');
        $this->assertStringContainsString('the scrim from preset "light-wash" is light (rgba(255,255,255,0.85))', $found[0]['message']);

        $this->assertCount(1, $this->found(['_tokens' => ['wash' => '#ffffffee'],
            '_band' => ['background' => ['image' => 9001, 'overlay' => '@wash']]]), 'band token resolved for lightness');
    }

    /** A colour keyword inside a name is part of the name, not a colour: the value stays unread. */
    public function testAColourKeywordInsideANameIsNotReadAsAColour(): void
    {
        $this->assertNull(_pp_udc_value_is_light('linear-gradient(off-white, black)', []), 'off-white is a name, not white: the stop is unread, so the value is');
        foreach (['var(--no-such-white)', 'url(white.png)', '@not-a-black-token', 'offwhite'] as $value) {
            $this->assertNull(_pp_udc_value_is_light($value, []), $value);
        }
        $this->assertTrue(_pp_udc_value_is_light('white', []));
        $this->assertFalse(_pp_udc_value_is_light('linear-gradient(black, transparent)', []));
    }

    /** An accent inked only for :hover is still re-lit at rest, so it is still named. */
    public function testAStateOnlyAccentInkIsStillReLitAtRestAndNamed(): void
    {
        $light = ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.8)'];
        $found = $this->found(['_band' => ['background' => $light], 'heading-accent' => ['typography' => [':hover' => ['color' => '#111111']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "heading-accent" re-lights', $found[0]['message']);
    }

    /** An accent inked through the raw-CSS valve is authored: not re-lit, not named. */
    public function testARawCssAccentInkIsAuthored(): void
    {
        $this->assertSame([], $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.8)']],
            'heading-accent' => ['_css' => ['color' => '#111111']]]));
    }

    /** Only a role that ENCLOSES the accent counts: a light button beside the heading is not named. */
    public function testOnlyAnEnclosingRolesSurfaceIsNamed(): void
    {
        foreach (['button', 'button-secondary', 'eyebrow', 'body'] as $beside) {
            $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], $beside => ['background' => ['fill' => '#ffffff']]]), $beside);
        }
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'inner' => ['background' => ['fill' => '#ffffff']]]),
            '`inner` permits no background group, so the page paints no surface there');
        foreach (['text', 'heading'] as $outer) {
            $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], $outer => ['background' => ['fill' => '#ffffff']]]), $outer);
        }
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'cta-secondary' => ['background' => ['fill' => '#ffffff']]],
            'hero', ['title' => 'T', 'title_accent' => 'A', 'layout' => 'centered']), 'hero secondary CTA sits beside the title');

        // stats: the item card encloses the number, not the heading accent, so only "number" is named.
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'item' => ['background' => ['fill' => '#ffffff']]],
            'stats', ['title' => 'S', 'title_accent' => 'A', 'items' => []]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "number" re-lights', $found[0]['message']);
        $this->assertStringNotContainsString('heading-accent', $found[0]['message']);
    }

    /** A light panel a preset or the raw-CSS valve paints is read like the map's own fill. */
    public function testAPresetOrRawCssPanelIsRead(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'white-panel', 'grain' => 'background', 'udc' => ['fill' => '#ffffff']])['ok']);
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'white-role', 'grain' => 'role', 'udc' => ['background' => ['fill' => '#ffffff']]])['ok']);
        foreach ([['background' => ['_preset' => 'white-panel']], ['_preset' => 'white-role'], ['_css' => ['background-color' => '#ffffff']]] as $text) {
            $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => $text]), json_encode($text));
        }
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['_css' => ['background-color' => '#101828']]]),
            'a dark raw-CSS panel is read as dark');
    }

    /** A light scrim written as a breakpoint map is read tier by tier. */
    public function testALightScrimInABreakpointMapIsNamed(): void
    {
        $found = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => ['d' => 'rgba(0,0,0,0.6)', 't' => 'rgba(255,255,255,0.8)']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('the scrim you set is light (rgba(255,255,255,0.8))', $found[0]['message']);
    }

    /** A scrim that fades to transparent leaves part of the band unscrimmed. */
    public function testAFadingScrimIsNamedAsPartlyTransparent(): void
    {
        $found = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'linear-gradient(to bottom, rgba(0,0,0,0.7), transparent)']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('is transparent in part', $found[0]['message']);
        $this->assertSame([], $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.4))']]]),
            'a dark gradient with every stop opaque enough is the premise holding');
    }

    /** The reader gives up on oversized or amplifying values instead of expanding them. */
    public function testTheColourReaderIsBounded(): void
    {
        $this->assertSame([], _pp_udc_value_colours(str_repeat('#ffffff ', 100), []), 'over the byte bound: unread');
        $this->assertSame([], _pp_udc_value_colours(str_repeat('@x', 40), ['x' => str_repeat('a', 1000)]), 'expansion past the bound: unread');
        $before = memory_get_usage();
        $found  = pp_udc_composition_findings([['component' => 'cta', 'id' => 'pp-a1b2c3d4',
            'udc' => ['_tokens' => ['x' => str_repeat('a', 100000)], '_band' => ['background' => ['image' => 9001, 'overlay' => str_repeat('@x', 5000)]]],
            'props' => []]]);
        $this->assertIsArray($found);
        $this->assertLessThan(16 * 1048576, memory_get_usage() - $before);
    }

    /** The engine's own minted responsive scrim (`@_band-background-overlay-d`) is read, on the stored map. */
    public function testAMintedResponsiveDarkScrimIsRead(): void
    {
        $item = ['component' => 'cta', 'id' => 'pp-a1b2c3d4',
            'props' => ['title' => 'C', 'title_accent' => 'A', 'button_text' => 'Go', 'button_url' => '/x'],
            'udc'   => ['_band' => ['background' => ['image' => 9001, 'overlay' => ['d' => 'rgba(6,10,28,0.72)', 'p' => 'rgba(6,10,28,0.8)']]]]];
        $stored = pp_udc_normalize_composition([$item]);
        $this->assertStringStartsWith('@_band-', (string) ($stored[0]['udc']['_band']['background']['overlay']['d'] ?? ''), 'premise: the write mints it');
        $this->assertSame([], array_values(array_filter(pp_udc_composition_findings($stored), static fn ($f) => $f['type'] === self::TYPE)));

        $light = $item;
        $light['udc']['_band']['background']['overlay'] = ['d' => 'rgba(255,255,255,0.8)', 'p' => 'rgba(6,10,28,0.8)'];
        $found = array_values(array_filter(pp_udc_composition_findings(pp_udc_normalize_composition([$light])), static fn ($f) => $f['type'] === self::TYPE));
        $this->assertCount(1, $found, 'a minted light tier is still read as light');
        $this->assertStringContainsString('the scrim you set is light', $found[0]['message']);
    }

    /** An accent inked at some widths only is still re-lit at the others, so it is named. */
    public function testABreakpointOnlyAccentInkIsNotAResting(): void
    {
        $light = ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.8)'];
        $this->assertCount(1, $this->found(['_band' => ['background' => $light], 'heading-accent' => ['typography' => ['color' => ['t' => '#111111']]]]));
        $this->assertSame([], $this->found(['_band' => ['background' => $light], 'heading-accent' => ['typography' => ['color' => ['d' => '#111111', 't' => '#222222']]]]),
            'a map with the base tier inks every width');
    }

    /** A stop the reader cannot place makes the whole value unread, never judged by the others. */
    public function testAGradientWithAnUnreadStopIsUnread(): void
    {
        foreach (['linear-gradient(#000, ivory)', 'linear-gradient(#000, lab(98% 0 0))', 'linear-gradient(#000, rgb(255 255))', 'linear-gradient(black, hsl(0.5turn 0% 100%))',
            'linear-gradient(@no-such-token, black)'] as $value) {
            $this->assertNull(_pp_udc_value_is_light($value, []), $value);
        }
        $this->assertFalse(_pp_udc_value_is_light('linear-gradient(to bottom right, rgba(0,0,0,0.7) 0%, #000 100%)', []), 'gradient syntax around readable stops');
    }

    /** hsl(): the hue and the alpha are read, not only the lightness. */
    public function testHslHueAndAlphaAreRead(): void
    {
        $this->assertSame([[255, 255, 0, 1.0]], _pp_udc_value_colours('hsl(60 100% 50%)', []));
        $this->assertSame([[0, 0, 255, 1.0]], _pp_udc_value_colours('hsl(240, 100%, 50%)', []));
        $this->assertTrue(_pp_udc_value_is_light('hsl(60 100% 50%)', []), 'yellow is light');
        $this->assertFalse(_pp_udc_value_is_light('hsl(240 100% 50%)', []), 'blue is dark');
        $this->assertFalse(_pp_udc_value_is_light('hsla(0, 0%, 100%, 0.1)', []), 'a thin white wash is not a light colour');
    }

    /** The amplification guard holds for an input under the byte bound. */
    public function testTheAmplificationGuardHoldsUnderTheByteBound(): void
    {
        $before = memory_get_peak_usage();
        $this->assertSame([], _pp_udc_value_colours(str_repeat('@x', 256), ['x' => str_repeat('a', 100000)]));
        $this->assertLessThan(8 * 1048576, memory_get_peak_usage() - $before);
        $this->assertSame([], _pp_udc_value_colours('@x', ['x' => str_repeat('#ffffff ', 200)]), 'a colour-bearing token past the bound is unread');
    }

    /** The map's own fill beats a preset's; the _css shorthand and background-image are read; a state's fill is read. */
    public function testSurfacePrecedenceShorthandAndStateFills(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'white-role2', 'grain' => 'role', 'udc' => ['background' => ['fill' => '#ffffff']]])['ok']);
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['_preset' => 'white-role2', 'background' => ['fill' => '#101828']]]));
        $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['_css' => ['background' => '#ffffff']]]));
        // A `_css` background-image the gate refuses (it keeps the image parameter's grammar) is not painted, so not named.
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['_css' => ['background-image' => 'linear-gradient(#ffffff, #ffffff)']]]));
        $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => [':hover' => ['fill' => '#ffffff']]]]), 'a state-only fill');
        $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => [':hover' => ['fill' => ['d' => '#ffffff']]]]]), 'a per-breakpoint state fill');
    }

    /** A malformed `within` entry in a hand-edited schema is skipped, never a PHP warning. */
    public function testMalformedWithinEntriesAreSkipped(): void
    {
        // A theme root whose cta schema was hand-edited past CI (the gate runs in CI only).
        $root = sys_get_temp_dir() . '/pp-within-' . getmypid();
        exec('rm -rf ' . escapeshellarg($root));
        mkdir($root, 0777, true);
        foreach (['components', 'assets'] as $dir) {
            exec('cp -r ' . escapeshellarg(dirname(__DIR__) . '/' . $dir) . ' ' . escapeshellarg($root . '/' . $dir));
        }
        $schema_file = $root . '/components/cta/schema.json';
        $schema      = json_decode((string) file_get_contents($schema_file), true);
        $this->assertArrayHasKey('within', $schema['roles']['heading-accent'], 'premise');
        $schema['roles']['heading-accent']['within'] = [['text'], 5, '', 'heading-accent', 'text'];
        file_put_contents($schema_file, json_encode($schema));

        $errors = [];
        $GLOBALS['_pp_test_template_dir'] = $root;
        $GLOBALS['_pp_registered_components_invalidate'] = true;
        set_error_handler(static function (int $no, string $msg) use (&$errors): bool { $errors[] = $msg; return true; });
        try {
            $found = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['fill' => '#ffffff']]]);
            $self  = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'heading-accent' => ['background' => ['fill' => '#ffffff']]]);
        } finally {
            restore_error_handler();
            unset($GLOBALS['_pp_test_template_dir']);
            $GLOBALS['_pp_registered_components_invalidate'] = true;
            exec('rm -rf ' . escapeshellarg($root));
        }
        $this->assertCount(1, $found, 'the valid entry still works');
        $this->assertSame([], $errors);
        $this->assertStringContainsString('so role "heading-accent" re-lights', $found[0]['message'], 'named once, in the singular');
        $this->assertCount(1, $self);
        $this->assertStringContainsString('so role "heading-accent" re-lights', $self[0]['message'], 'a `within` naming the accent itself does not list it twice');
    }

    /** Angles and lengths the write gate accepts are read, so a dark scrim using them is not "unreadable". */
    public function testGateAcceptedAngleAndLengthUnitsAreRead(): void
    {
        foreach (['linear-gradient(0.5turn, rgba(0,0,0,.6), rgba(0,0,0,.8))', 'linear-gradient(90grad, #000, #111)', 'linear-gradient(1.57rad, #000a, #000c)',
            'linear-gradient(180deg, rgba(0,0,0,.6) 0vh, rgba(0,0,0,.8) 60vh)', 'radial-gradient(at 50vw 20vh, rgba(0,0,0,.6), rgba(0,0,0,.85))',
            'linear-gradient(#000 3ch, #111)', 'radial-gradient(at 10vmin 20%, #000, #111)'] as $scrim) {
            $this->assertFalse(_pp_udc_value_is_light($scrim, []), $scrim);
            $this->assertSame([], $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => $scrim]]]), $scrim);
        }
    }

    /** A preset's references resolve against SITE tokens, as the emitter resolves them; a band token cannot shadow them. */
    public function testPresetSuppliedValuesReadSiteTokensNotBandShadows(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'darkscrim', 'grain' => 'background', 'udc' => ['image' => 9001, 'overlay' => '@overlay-bg']])['ok']);
        $this->assertSame([], $this->found(['_tokens' => ['overlay-bg' => '#ffffff'], '_band' => ['background' => ['_preset' => 'darkscrim']]]),
            'the page paints the site --overlay-bg (dark), not the band token');

        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'lightpanel', 'grain' => 'background', 'udc' => ['fill' => '@color-bg']])['ok']);
        // The band also USES its own `color-bg` token (a dark `body` panel), so the compiled band carries
        // it: the preset's `var(--color-bg)` must still resolve to the SITE value, not this one.
        $found = $this->found(['_tokens' => ['color-bg' => '#000000'], '_band' => ['background' => self::DARK_SCRIM],
            'body' => ['background' => ['fill' => '@color-bg']],
            'heading-accent' => ['background' => ['_preset' => 'lightpanel']]]);
        $this->assertCount(1, $found, 'the page paints the site --color-bg (light) behind the re-lit accent');
        $this->assertStringContainsString('has a background from preset "lightpanel" (@color-bg)', $found[0]['message']);
    }

    /** rgb() with percentage channels and a site token that points at another token are read. */
    public function testPercentageChannelsAndNestedTokensAreRead(): void
    {
        $this->assertSame([[0, 0, 0, 0.6]], _pp_udc_value_colours('rgba(0%, 0%, 0%, 0.6)', []));
        $this->assertTrue(_pp_udc_value_is_light('rgb(100% 100% 100%)', []));
        $this->assertSame([], $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0%, 0%, 0%, 0.6)']]]));
        $this->assertFalse(_pp_udc_value_is_light('var(--text-meta-color)', []), 'a token holding var(--color-muted) resolves to it');
    }

    /**
     * Cycle 4: a preset tier the author did not override still paints (the emitter merges per
     * breakpoint tier), so the reader merges the same way, for fills and for the scrim.
     */
    public function testAPresetTierTheAuthoredFillDoesNotOverrideIsRead(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'white-panel4', 'grain' => 'background', 'udc' => ['fill' => '#ffffff']])['ok']);
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['_preset' => 'white-panel4', 'fill' => ['t' => '#101828']]]]);
        $this->assertCount(1, $found, 'the preset base tier paints #ffffff at desktop and phone');
        $this->assertStringContainsString('has a background from preset "white-panel4" (#ffffff)', $found[0]['message']);

        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'tabpanel', 'grain' => 'background', 'udc' => ['fill' => ['t' => '#ffffff']]])['ok']);
        $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], 'heading-accent' => ['background' => ['_preset' => 'tabpanel', 'fill' => '#101828']]]),
            'the preset tablet tier paints #ffffff behind the accent');
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['_preset' => 'white-panel4', 'fill' => '#101828']]]),
            'an author base tier overrides the preset base tier');
    }

    public function testAPresetScrimTierTheAuthorDidNotOverrideIsRead(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'tabwash', 'grain' => 'background', 'udc' => ['image' => 9001, 'overlay' => ['t' => 'rgba(255,255,255,0.85)']]])['ok']);
        $found = $this->found(['_band' => ['background' => ['_preset' => 'tabwash', 'overlay' => 'rgba(0,0,0,.7)']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('the scrim from preset "tabwash" is light', $found[0]['message']);

        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'basewash', 'grain' => 'background', 'udc' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.85)']])['ok']);
        $mirror = $this->found(['_band' => ['background' => ['_preset' => 'basewash', 'overlay' => ['t' => 'rgba(0,0,0,.7)']]]]);
        $this->assertCount(1, $mirror, 'the scrim covers every width (preset base + author tablet): no gap, but a light tier');
        $this->assertStringContainsString('the scrim from preset "basewash" is light', $mirror[0]['message']);
        $this->assertStringNotContainsString('unscrimmed image', $mirror[0]['message']);
    }

    public function testAPresetSuppliedStateFillIsRead(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'gh', 'grain' => 'background', 'udc' => [':hover' => ['fill' => '#ffffff']]])['ok']);
        $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], 'heading-accent' => ['background' => ['_preset' => 'gh']]]));
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'hover-white', 'grain' => 'role', 'udc' => ['background' => [':hover' => ['fill' => '#ffffff']]]])['ok']);
        $this->assertCount(1, $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['_preset' => 'hover-white']]));
    }

    /** One compile serves both disclosures: the dropped-overlay finding still speaks on an overlaid band. */
    public function testTheSharedCompileKeepsTheDroppedOverlayDisclosure(): void
    {
        $all = pp_udc_composition_findings([['component' => 'cta', 'id' => 'pp-a1b2c3d4',
            'props' => ['title' => 'C', 'title_accent' => 'A', 'button_text' => 'Go', 'button_url' => '/x'],
            'udc'   => ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(6,10,28,0.72)']],
                        'text'  => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]]]]);
        $types = array_column($all, 'type');
        $this->assertContains('udc_overlay_without_image', $types, 'the text overlay has no image: the emitter drops it and says so');
    }

    /** Post-rebuild: an overlay the emitter drops (set only inside :hover) paints no scrim, marks nothing, names nothing. */
    public function testAStateOnlyBandOverlayIsNoScrimAndNamesNothing(): void
    {
        $udc = ['_band' => ['background' => ['image' => 9001, ':hover' => ['overlay' => 'rgba(0,0,0,0.7)']]], 'text' => ['background' => ['fill' => '#ffffff']]];
        $this->assertFalse(pp_udc_band_has_overlay(['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => $udc]));
        $this->assertSame([], $this->found($udc));
    }

    /** Post-rebuild: a band tier or state that REPLACES the scrimmed image leaves the accent re-lit on the new surface. */
    public function testABandTierOrStateThatReplacesTheImageIsNamed(): void
    {
        foreach ([['fill' => ['p' => '#ffffff']], [':hover' => ['fill' => '#ffffff']]] as $extra) {
            $udc = ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)'] + $extra]];
            $css = pp_udc_band_css(['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => $udc]);
            $this->assertStringContainsString('background:#ffffff', $css, 'premise: the page repaints the band there');
            $this->assertTrue(pp_udc_band_has_overlay(['component' => 'cta', 'id' => 'pp-a1b2c3d4', 'props' => [], 'udc' => $udc]), 'premise: still marked');
            $found = $this->found($udc);
            $this->assertCount(1, $found, json_encode($extra));
        }
        $tier = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)', 'fill' => ['p' => '#ffffff']]]]);
        $this->assertStringContainsString('so at the phone width the accent sits on the unscrimmed image', $tier[0]['message']);
        $state = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)', ':hover' => ['fill' => '#ffffff']]]]);
        $this->assertStringContainsString("in the :hover state the band's own background replaces the scrimmed image", $state[0]['message']);
        $raw = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.7)'], '_css' => ['background' => ['p' => '#ffffff']]]]);
        $this->assertCount(1, $raw, 'the raw-CSS shorthand at one width');
    }

    /**
     * The #1125 finding (`udc_role_ink_over_own_surface`) was withdrawn from PR-C by the descope
     * ruling and landed on the compiled-band accessor in Sprint 3 T2. Every model-facing surface
     * that promises it must ALSO state its limit (orchestrator ruling D2 = A: a text role INSIDE
     * a filled role is not reported, #1140), so no reader takes the finding's silence as a pass
     * for a nested pair. The promising set is pinned exactly, so a new mention has to be looked at.
     */
    public function testEverySurfaceThatPromisesTheOwnSurfaceFindingStatesItsNestedRoleLimit(): void
    {
        $root  = dirname(__DIR__);
        $files = array_merge(glob($root . '/lib/*.php'), glob($root . '/ai-instructions/*.md'), glob($root . '/components/*/README.md'),
            glob($root . '/docs/*.md'), [$root . '/AI_CONTEXT.md']);
        $promising = [];
        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file), 'udc_role_ink_over_own_surface')) {
                $promising[] = substr($file, strlen($root) + 1);
            }
        }
        sort($promising);
        $this->assertSame(['AI_CONTEXT.md', 'ai-instructions/add-component.md', 'ai-instructions/build-landing-page.md', 'ai-instructions/style-component.md',
            'ai-instructions/validate-site.md', 'lib/ai-context.php', 'lib/udc.php'], $promising);
        // EVERY mention, not the file: each one must carry the limit within the same passage.
        $checked = 0;
        foreach (array_diff($promising, ['lib/udc.php']) as $doc) {
            $text   = (string) file_get_contents($root . '/' . $doc);
            $offset = 0;
            while (($at = strpos($text, 'udc_role_ink_over_own_surface', $offset)) !== false) {
                $this->assertStringContainsString('not reported', substr($text, $at, 900),
                    $doc . ' @' . $at . ': the promise carries its nested-role limit');
                $offset = $at + 1;
                $checked++;
            }
        }
        // 8 since /ship (ruling 1 = A): AI_CONTEXT's sibling paragraph names where a state-only band colour is reported.
        $this->assertSame(8, $checked, 'every promise was checked (vacuity floor)');
        $prompt = pp_ai_system_prompt();
        $mentions = 0;
        for ($at = strpos($prompt, 'udc_role_ink_over_own_surface'); $at !== false; $at = strpos($prompt, 'udc_role_ink_over_own_surface', $at + 1)) {
            $this->assertStringContainsString('not reported', substr($prompt, $at, 900), 'runtime prompt @' . $at);
            $mentions++;
        }
        $this->assertSame(2, $mentions, 'both runtime promises (dark band, chrome) were checked');
        $this->assertStringContainsString('`udc_overlay_accent_off_scrim`', pp_ai_system_prompt(), 'premise: the sibling finding is still named');
    }

    /** Bounded across the composition like its sibling arms. */
    public function testTheFindingIsBoundedAcrossTheComposition(): void
    {
        $bands = [];
        for ($b = 0; $b < 150; $b++) {
            $bands[] = ['component' => 'cta', 'id' => sprintf('pp-%08x', $b + 1),
                'udc'   => ['_band' => ['background' => ['image' => 9001, 'overlay' => ['p' => 'rgba(255,255,255,0.8)']]],
                            'text' => ['background' => ['fill' => '#ffffff']]],
                'props' => ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x']];
        }
        $count = count(array_filter(pp_udc_composition_findings($bands), static fn ($f) => $f['type'] === self::TYPE));
        $this->assertGreaterThan(150, $count, 'premise: three conditions per band would exceed the cap');
        $this->assertLessThanOrEqual(PP_UDC_MAX_EMIT_DROPS, $count);
    }

    /** #1142 item 1: a partial scrim is named through the finding, with the size, so the author knows why. */
    public function testAScrimCoveringPartOfTheBandIsNamed(): void
    {
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM + ['size' => '200px 200px', 'repeat' => 'no-repeat']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('the image and its scrim are sized 200px 200px without tiling, so part of the band shows its own background instead of the scrim', $found[0]['message']);
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM + ['size' => '200px', 'repeat' => 'repeat']]]), 'a tiling scrim covers');
    }

    /**
     * #1142 item 3 (premise corrected in its body): `initial`, `unset` and `none` paint no surface, like `transparent`
     * (the shared classifier _pp_udc_paints_surface()); `currentColor` paints the text's own colour behind the text and
     * `inherit` takes the parent's background, so both stay named, each with words that are true of it.
     */
    public function testNonSurfaceKeywordsAreNotNamedAndCurrentColorAndInheritAreWordedTruly(): void
    {
        foreach (['initial', 'unset', 'none', 'TRANSPARENT', 'rgba(255,255,255,0)'] as $value) {
            $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['_css' => ['background-color' => $value]]]), $value);
        }
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['_css' => ['background-color' => 'currentColor']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('has a background you set of currentColor, which paints its own text colour behind the text', $found[0]['message']);
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['_css' => ['background-color' => 'inherit']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('has a background you set of inherit, which takes its parent\'s background, and the engine cannot read that', $found[0]['message']);
    }

    /** #1142 item 4: the marker's catch logs like its sibling in the findings path (call shape pinned; no stored shape is known to throw). */
    public function testTheMarkerLogsACompileFailure(): void
    {
        $fn  = new ReflectionFunction('pp_udc_band_has_overlay');
        $src = implode('', array_slice(file($fn->getFileName()), $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
        $this->assertMatchesRegularExpression('/catch \(\\\\Throwable \$e\) \{\s*error_log\(\'PromptingPress: overlay marker compile failed/s', $src);
    }

    /** The partial-scrim condition names its widths when it holds only at some (PR-2 review, testing). */
    public function testAPartialScrimAtSomeWidthsNamesThem(): void
    {
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM + ['size' => ['p' => '100px']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('sized 100px without tiling at the phone width', $found[0]['message']);
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM + ['size' => 'contain', 'repeat' => 'no-repeat']]]),
            'contain fills the band: nothing to name (ruling A)');
    }

    /** The partial message speaks the author's value, never an internal token name (PR-2 review, maintainability). */
    public function testThePartialMessageShowsTheAuthorsSize(): void
    {
        $found = $this->found(['_tokens' => ['sz' => '200px'], '_band' => ['background' => self::DARK_SCRIM + ['size' => '@sz', 'repeat' => 'no-repeat']]]);
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString('var(--pp-', $found[0]['message']);
        $this->assertStringContainsString('sized 200px without tiling', $found[0]['message'], 'the value, as the neighbouring conditions show band tokens');
    }

    /** Different sizes at different widths are each named with their widths (PR-2 review, maintainability). */
    public function testEachPartialSizeIsNamedWithItsWidths(): void
    {
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM + ['size' => ['d' => '50px', 'p' => '70%'], 'repeat' => 'no-repeat']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('sized 50px without tiling at the desktop and tablet widths and 70% without tiling at the phone width', $found[0]['message']);
    }
}
