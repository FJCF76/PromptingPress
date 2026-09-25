<?php
/**
 * tests/OverlayAccentOffScrimTest.php — the overlay tier's residual, disclosed (#1010 review,
 * 7A ruling 1 = A).
 *
 * THE DEFECT. The overlay tier re-lights the accent inks to the near-white on-overlay ink on
 * every band the engine marks overlaid, on the premise that the accent sits on a dark scrim.
 * Three authored shapes break that premise and the write said nothing: a light panel the
 * author set under the accent (#fafbff on #ffffff, 1.03:1), a scrim set only at some widths
 * (the accent re-lit over the unscrimmed image elsewhere), and a light scrim.
 *
 * THE RULING (1 = A). The tier stays (D4 = B); the engine discloses rather than outguesses.
 * `udc_overlay_accent_off_scrim` names the re-lit accent roles AND the triggering condition.
 * Authored wins: an accent whose ink the author set is not re-lit and not named.
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
        $this->assertStringContainsString('role "text" has a background.fill you set (#ffffff)', $found[0]['message']);
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
        $this->assertStringContainsString('the scrim is set only at the phone width, so at the desktop and tablet width', $found[0]['message']);

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
        $this->assertSame([], $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.1)']]]),
            'a thin wash is the image, not the colour');
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
            'hsl(0 0% 100%)'                => null,
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

    /** An unreadable scrim is not guessed light; an unreadable panel fill is named as unreadable. */
    public function testAnUnreadableScrimIsSilentButAnUnreadablePanelIsNamed(): void
    {
        $this->assertSame([], $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'hsl(0 0% 100% / 0.8)']]]),
            'the scrim trigger fires only on a value read as light');
        $found = $this->found(['_band' => ['background' => self::DARK_SCRIM], 'text' => ['background' => ['fill' => 'hsl(0 0% 10%)']]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('(hsl(0 0% 10%)) that is light or that the engine cannot read', $found[0]['message']);
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

    /** A re-lit accent role's own panel is not a panel "under" the accent: it is skipped. */
    public function testTheReLitRoleItselfIsNotItsOwnPanel(): void
    {
        $this->assertSame([], $this->found(['_band' => ['background' => self::DARK_SCRIM],
            'heading-accent' => ['background' => ['fill' => '#ffffff']]]));
    }

    /** Several re-lit roles are named together, in the plural. */
    public function testSeveralReLitRolesAreNamedInThePlural(): void
    {
        $found = $this->found(['_band' => ['background' => ['image' => 9001, 'overlay' => 'white']]],
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
        $this->assertStringContainsString('only at the tablet and phone width, so at the desktop width', $found[0]['message']);
    }

    /** A scrim a preset supplies is read like the map's own, band tokens included. */
    public function testAPresetSuppliedOrTokenScrimIsRead(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'light-wash', 'grain' => 'role',
            'udc' => ['background' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.85)']]])['ok']);
        $found = $this->found(['_band' => ['_preset' => 'light-wash']]);
        $this->assertCount(1, $found, 'role-grain preset scrim');
        $this->assertStringContainsString('the scrim you set is light (rgba(255,255,255,0.85))', $found[0]['message']);

        $this->assertCount(1, $this->found(['_tokens' => ['wash' => '#ffffffee'],
            '_band' => ['background' => ['image' => 9001, 'overlay' => '@wash']]]), 'band token resolved for lightness');
    }

    /** A colour keyword inside a name is part of the name, not a colour: the value stays unread. */
    public function testAColourKeywordInsideANameIsNotReadAsAColour(): void
    {
        foreach (['var(--no-such-white)', 'url(white.png)', '@not-a-black-token', 'offwhite'] as $value) {
            $this->assertNull(_pp_udc_value_is_light($value, []), $value);
        }
        $this->assertTrue(_pp_udc_value_is_light('white', []));
        $this->assertFalse(_pp_udc_value_is_light('linear-gradient(black, transparent)', []));
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
}
