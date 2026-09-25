<?php
/**
 * tests/RoleInkOverOwnSurfaceTest.php — the role's own surface under a new ink (#1125, ruling D5 = B).
 *
 * THE TRAP. Darken a band, recolour every text role as the instructions say, and a role that
 * ships its OWN background fill (the eyebrow pill, a panel, a card) keeps that light surface
 * under the new light ink: 1.82:1 measured on this sprint's probe page. Both values apply
 * correctly; they clash, and the write said `findings: []`.
 *
 * THE RULING (D5 = B). A finding, `udc_role_ink_over_own_surface`, when a role's authored
 * `typography.color` sits over that role's own default `background.fill` (not transparent)
 * with no fill or image authored or preset-supplied for it, AND ONLY when the band's own
 * `_band` background is authored (the author changed the band surface). No contrast maths:
 * the engine names the pairing, the author judges it.
 */

use PHPUnit\Framework\TestCase;

final class RoleInkOverOwnSurfaceTest extends TestCase
{
    private const TYPE = 'udc_role_ink_over_own_surface';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function write(array $udc, string $component = 'section', array $props = ['eyebrow' => 'SECTION', 'title' => 'Dark band', 'body' => 'Body']): array
    {
        $id = pp_create_page('ink', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [
            ['component' => $component, 'udc' => $udc, 'props' => $props],
        ]]);
        $this->assertTrue($result['ok'], 'premise: written: ' . ($result['error'] ?? ''));
        return [$id, array_values(array_filter($result['findings'] ?? [], static fn (array $f): bool => ($f['type'] ?? '') === self::TYPE))];
    }

    /** The issue's own shape: a dark band, the eyebrow recoloured, the pill left light. */
    public function testTheIssueShapeIsDisclosed(): void
    {
        [$id, $found] = $this->write([
            '_band'     => ['background' => ['fill' => '@color-bg-inverted']],
            'heading'   => ['typography' => ['color' => '@color-bg']],
            'eyebrow'   => ['typography' => ['color' => '@color-accent-on-inverted']],
            'body'      => ['typography' => ['color' => '@color-bg']],
            'body-link' => ['typography' => ['color' => '@color-accent-on-inverted']],
        ]);

        $this->assertCount(1, $found, 'only the eyebrow ships its own surface');
        $this->assertStringContainsString('role "eyebrow"', $found[0]['message']);
        $this->assertStringContainsString('@color-surface-accent', $found[0]['message'], 'names the surviving fill');
        $this->assertStringContainsString('background.fill', $found[0]['message']);
        $this->assertSame(0, $found[0]['index']);

        // The same fact on the stored-state channels (check page, restore): same engine.
        $stored = array_filter(pp_udc_composition_findings(pp_get_composition($id)), static fn ($f) => $f['type'] === self::TYPE);
        $this->assertCount(1, $stored);
    }

    /** D5's negative: no authored `_band` background, no finding, even with the same ink. */
    public function testNoFindingWhenTheBandBackgroundIsNotAuthored(): void
    {
        [, $found] = $this->write(['eyebrow' => ['typography' => ['color' => '@color-accent-on-inverted']]]);
        $this->assertSame([], $found);
    }

    /** The author set the role's own fill too: nothing survives underneath. */
    public function testNoFindingWhenTheRoleFillIsAuthored(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['typography' => ['color' => '@color-accent-on-inverted'], 'background' => ['fill' => 'transparent']],
        ]);
        $this->assertSame([], $found);
    }

    /** A preset that supplies the role's fill counts as covering it. */
    public function testNoFindingWhenAPresetSuppliesTheRoleFill(): void
    {
        $saved = pp_execute_action('save_preset', ['name' => 'probe-pill', 'grain' => 'role', 'udc' => ['background' => ['fill' => '#222222']]]);
        $this->assertTrue($saved['ok'], (string) ($saved['error'] ?? ''));
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['_preset' => 'probe-pill', 'typography' => ['color' => '@color-accent-on-inverted']],
        ]);
        $this->assertSame([], $found);
    }

    /** A background-grain preset that supplies the fill covers it too. */
    public function testNoFindingWhenABackgroundGrainPresetSuppliesTheRoleFill(): void
    {
        $saved = pp_execute_action('save_preset', ['name' => 'probe-bg', 'grain' => 'background', 'udc' => ['fill' => '#222222']]);
        $this->assertTrue($saved['ok'], (string) ($saved['error'] ?? ''));
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['background' => ['_preset' => 'probe-bg'], 'typography' => ['color' => '@color-accent-on-inverted']],
        ]);
        $this->assertSame([], $found);
    }

    /** An image the author puts on the role replaces its own surface as a fill would. */
    public function testNoFindingWhenTheRoleCarriesItsOwnImage(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $found] = $this->write([
            '_band' => ['background' => ['fill' => '@color-bg-inverted']],
            'panel' => ['background' => ['image' => 9001], 'typography' => ['color' => '@color-bg']],
        ]);
        $this->assertSame([], $found);
    }

    /** An ink a preset supplies paints like the map's own, at role grain and inside `typography`. */
    public function testAPresetSuppliedInkIsDisclosed(): void
    {
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'ink-role', 'grain' => 'role',
            'udc' => ['typography' => ['color' => '#ffffff']]])['ok']);
        $this->assertTrue(pp_execute_action('save_preset', ['name' => 'ink-type', 'grain' => 'typography',
            'udc' => ['color' => '#ffffff']])['ok']);
        foreach ([['_preset' => 'ink-role'], ['typography' => ['_preset' => 'ink-type']]] as $eyebrow) {
            [, $found] = $this->write(['_band' => ['background' => ['fill' => '@color-bg-inverted']], 'eyebrow' => $eyebrow]);
            $this->assertCount(1, $found, json_encode($eyebrow));
        }
    }

    /** A role image whose attachment is gone paints nothing, so the default fill still shows. */
    public function testADeletedRoleImageDoesNotCoverTheFill(): void
    {
        $found = array_values(array_filter(pp_udc_composition_findings([[
            'component' => 'section', 'id' => 'pp-a1b2c3d4',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']],
                            'panel' => ['background' => ['image' => 9002], 'typography' => ['color' => '@color-bg']]],
            'props'     => ['title' => 'T', 'body' => 'b'],
        ]]), static fn ($f) => $f['type'] === self::TYPE));
        $this->assertCount(1, $found, 'no attachment 9002 in the store');
    }

    /** An ink written through the raw-CSS valve is an authored ink too. */
    public function testARawCssInkIsDisclosed(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['_css' => ['color' => '#ffffff']],
        ]);
        $this->assertCount(1, $found);
    }

    /** A band darkened through a preset the author applied is an authored band surface. */
    public function testABandDarkenedByAPresetIsAnAuthoredBandSurface(): void
    {
        $saved = pp_execute_action('save_preset', ['name' => 'probe-dark', 'grain' => 'background', 'udc' => ['fill' => '#101828']]);
        $this->assertTrue($saved['ok'], (string) ($saved['error'] ?? ''));
        [, $found] = $this->write([
            '_band'   => ['background' => ['_preset' => 'probe-dark']],
            'eyebrow' => ['typography' => ['color' => '@color-bg']],
        ]);
        $this->assertCount(1, $found);
    }

    private function gridFindings(array $band_udc, array $item_udcs): array
    {
        $items = [];
        foreach ($item_udcs as $n => $udc) {
            $items[] = ['id' => sprintf('it-0000ab%02d', $n), 'title' => 'T' . $n] + ($udc === null ? [] : ['udc' => $udc]);
        }
        return array_values(array_filter(pp_udc_composition_findings([[
            'component' => 'grid', 'id' => 'pp-a1b2c3d4',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']]] + $band_udc,
            'props'     => ['title' => 'G', 'items' => $items],
        ]]), static fn ($f) => $f['type'] === self::TYPE));
    }

    /** One card, two grains: the band's `card` fill covers a per-card ink. */
    public function testABandGrainFillCoversAnItemGrainInk(): void
    {
        $this->assertSame([], $this->gridFindings(
            ['card' => ['background' => ['fill' => '#1d2939']]],
            [['card' => ['typography' => ['color' => '#ffffff']]]]
        ));
        $this->assertCount(1, $this->gridFindings([], [['card' => ['typography' => ['color' => '#ffffff']]]]),
            'premise: the same ink with no fill at either grain is disclosed');
    }

    /** The band's `card` ink is covered only when EVERY card sets its own fill. */
    public function testABandGrainInkIsCoveredOnlyWhenEveryCardSetsItsFill(): void
    {
        $filled = ['card' => ['background' => ['fill' => '#1d2939']]];
        $ink    = ['card' => ['typography' => ['color' => '#ffffff']]];
        $this->assertSame([], $this->gridFindings($ink, [$filled, $filled]));
        $one = $this->gridFindings($ink, [$filled, null]);
        $this->assertCount(1, $one, 'the second card keeps its own light surface under the band ink');
        $this->assertStringNotContainsString('item "', $one[0]['message'], 'a band-grain finding');
    }

    /** The raw-CSS valve paints a surface too, on the role and on the band. */
    public function testARawCssBackgroundCountsOnBothSides(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['typography' => ['color' => '@color-bg'], '_css' => ['background-color' => '#000000']],
        ]);
        $this->assertSame([], $found, 'a _css background on the role is its fill');

        [, $found] = $this->write([
            '_band'   => ['_css' => ['background-color' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '@color-bg']],
        ]);
        $this->assertCount(1, $found, 'a _css background on _band is an authored band surface');
    }

    /** A card whose id the emitter cannot use renders without its map, so its fill covers nothing. */
    public function testACardTheEmitterCannotAddressDoesNotCoverTheBandInk(): void
    {
        $found = array_values(array_filter(pp_udc_composition_findings([[
            'component' => 'grid', 'id' => 'pp-a1b2c3d4',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']], 'card' => ['typography' => ['color' => '#ffffff']]],
            'props'     => ['title' => 'G', 'items' => [
                ['id' => 'it-0000ab01', 'title' => 'A', 'udc' => ['card' => ['background' => ['fill' => '#1d2939']]]],
                ['id' => 'not an id', 'title' => 'B', 'udc' => ['card' => ['background' => ['fill' => '#1d2939']]]],
            ]],
        ]]), static fn ($f) => $f['type'] === self::TYPE));
        $this->assertCount(1, $found);
    }

    /** A surface that exists at one width only is named with that width. */
    public function testATierOnlySurfaceIsNamedWithItsBreakpoint(): void
    {
        $found = array_values(array_filter(pp_udc_composition_findings([[
            'component' => 'nav', 'id' => 'nav',
            'udc'       => ['_band' => ['background' => ['fill' => '#101828']], 'menu' => ['typography' => ['color' => '#f7f8fa']]],
            'props'     => [],
        ]]), static fn ($f) => $f['type'] === self::TYPE));
        $this->assertCount(1, $found);
        $this->assertStringContainsString('(@color-bg at the "p" breakpoint)', $found[0]['message']);
    }

    /** A role with no surface of its own (or a transparent one) is not this trap. */
    public function testNoFindingForARoleWithoutAnOwnSurface(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'heading' => ['typography' => ['color' => '@color-bg']],
            'body'    => ['typography' => ['color' => '@color-bg']],
        ]);
        $this->assertSame([], $found);
    }

    /** Item grain: a card recoloured on a darkened band keeps its own card surface. */
    public function testACardInkOverTheCardsOwnSurfaceIsDisclosedWithTheItemLocator(): void
    {
        $id = pp_create_page('ink card', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'grid',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']]],
            'props'     => ['title' => 'G', 'items' => [['title' => 'One', 'udc' => ['card' => ['typography' => ['color' => '@color-bg']]]]]],
        ]]]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $found = array_values(array_filter($result['findings'] ?? [], static fn ($f) => $f['type'] === self::TYPE));
        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];

        $this->assertCount(1, $found);
        $this->assertStringContainsString('item "' . $item_id . '"', $found[0]['message']);
        $this->assertStringContainsString('role "card"', $found[0]['message']);
    }

    /** An image counts as the band surface being authored too. */
    public function testABandImageCountsAsAnAuthoredBandBackground(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $found] = $this->write([
            '_band'   => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.6)']],
            'eyebrow' => ['typography' => ['color' => '@color-bg']],
        ]);
        $this->assertCount(1, $found);
    }

    /** A transparent default fill is no surface: the band shows through. */
    public function testATransparentOwnFillIsNotASurface(): void
    {
        [, $found] = $this->write(
            ['_band' => ['background' => ['fill' => '@color-bg-inverted']], 'button-secondary' => ['typography' => ['color' => '@color-bg']]],
            'cta',
            ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x']
        );
        $this->assertSame([], $found);
    }

    /** An ink authored only for a state still lands on the role's own surface. */
    public function testAStateOnlyInkIsDisclosed(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['typography' => [':hover' => ['color' => '@color-bg']]],
        ]);
        $this->assertCount(1, $found);
    }

    /** A card map speaks only for item roles: a band-only role there is not reported. */
    public function testABandOnlyRoleInACardMapIsNotReported(): void
    {
        $found = array_filter(pp_udc_composition_findings([[
            'component' => 'grid', 'id' => 'pp-a1b2c3d4',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']]],
            'props'     => ['title' => 'G', 'items' => [['id' => 'it-0000abcd', 'title' => 'One',
                'udc' => ['eyebrow' => ['typography' => ['color' => '@color-bg']]]]]],
        ]]), static fn ($f) => $f['type'] === self::TYPE);
        $this->assertSame([], array_values($found));
    }

    /** Every `_css` background spelling the helper lists covers the role; an unrelated property does not. */
    public function testEveryRawCssBackgroundPropertyCoversTheRole(): void
    {
        foreach (['background', 'background-image'] as $property) {
            $this->assertTrue(_pp_udc_map_covers_fill(['_css' => [$property => '#000']]), $property);
        }
        $this->assertFalse(_pp_udc_map_covers_fill(['_css' => ['color' => '#000']]), 'a raw ink is not a surface');
        $this->assertFalse(_pp_udc_map_covers_fill(['background' => ['_preset' => 'no-such-preset']]), 'an unresolved preset supplies nothing');
        $this->assertFalse(_pp_udc_map_covers_fill(['background' => 'x', '_css' => 'y']), 'malformed groups are not surfaces');
    }

    /** The own-surface reader: first non-transparent string at any tier, with that tier; nothing else. */
    public function testTheOwnSurfaceReaderSkipsNonSurfaces(): void
    {
        $role = static fn ($fill): array => ['defaults' => ['background' => ['fill' => $fill]]];
        $this->assertSame(['#fff', 'd'], _pp_udc_role_own_surface_fill($role('#fff')));
        $this->assertSame(['#fff', 'p'], _pp_udc_role_own_surface_fill($role(['d' => 'Transparent', 't' => '', 'p' => '#fff'])));
        $this->assertNull(_pp_udc_role_own_surface_fill($role('')));
        $this->assertNull(_pp_udc_role_own_surface_fill($role(['d' => 'transparent'])));
        $this->assertNull(_pp_udc_role_own_surface_fill($role(7)));
        $this->assertNull(_pp_udc_role_own_surface_fill([]));
    }

    /** A band ink on an item role with no cards at all: nothing covers the role's own surface. */
    public function testABandGrainItemRoleInkWithNoCardsIsDisclosed(): void
    {
        $this->assertCount(1, $this->gridFindingsWithItems(['card' => ['typography' => ['color' => '#ffffff']]], []));
    }

    /** A per-card ink whose own card map also sets the fill is covered at its own grain. */
    public function testAnItemMapCoveringItsOwnFillIsSilent(): void
    {
        $this->assertSame([], $this->gridFindings([], [['card' => ['typography' => ['color' => '#ffffff'],
            'background' => ['fill' => '#1d2939']]]]));
    }

    private function gridFindingsWithItems(array $band_udc, array $items): array
    {
        return array_values(array_filter(pp_udc_composition_findings([[
            'component' => 'grid', 'id' => 'pp-a1b2c3d4',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']]] + $band_udc,
            'props'     => ['title' => 'G', 'items' => $items],
        ]]), static fn ($f) => $f['type'] === self::TYPE));
    }

    /** The chrome write envelope carries it too, with no band offset. */
    public function testAChromeWriteDisclosesItWithNoIndex(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => 'pp_site_udc',
            'value' => json_encode(['nav' => ['_band' => ['background' => ['fill' => '#101828']],
                'submenu' => ['typography' => ['color' => '#f7f8fa']]]]),
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $found = array_values(array_filter($result['findings'] ?? [], static fn ($f) => ($f['type'] ?? '') === self::TYPE));
        $this->assertCount(1, $found);
        $this->assertStringContainsString('Component "nav"', $found[0]['message']);
        $this->assertStringContainsString('role "submenu"', $found[0]['message']);
        $this->assertNull($found[0]['index']);
    }

    /** The runtime prompt names both new finding types, on the band and the chrome channels. */
    public function testTheRuntimePromptNamesBothNewFindings(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertSame(2, substr_count($prompt, '`udc_role_ink_over_own_surface`'), 'dark-band paragraph and chrome paragraph');
        $this->assertStringContainsString('reported as `udc_overlay_accent_off_scrim`', $prompt);
    }

    /** Bounded across the composition like its sibling arms. */
    public function testTheFindingIsBoundedAcrossTheComposition(): void
    {
        $bands = [];
        // Two own-surface roles per band, so the cap must hold INSIDE a band too; one
        // single-role band first makes the count odd, so it reaches 199 before a two-role band.
        $bands[] = ['component' => 'section', 'id' => 'pp-0000ffff',
            'udc'   => ['_band' => ['background' => ['fill' => '@color-bg-inverted']], 'eyebrow' => ['typography' => ['color' => '@color-bg']]],
            'props' => ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b']];
        for ($b = 0; $b < 150; $b++) {
            $bands[] = ['component' => 'section', 'id' => sprintf('pp-%08x', $b + 1),
                'udc'   => ['_band' => ['background' => ['fill' => '@color-bg-inverted']],
                            'eyebrow' => ['typography' => ['color' => '@color-bg']],
                            'panel'   => ['typography' => ['color' => '@color-bg']]],
                'props' => ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b']];
        }
        $count = count(array_filter(pp_udc_composition_findings($bands), static fn ($f) => $f['type'] === self::TYPE));
        $this->assertGreaterThan(0, $count, 'premise');
        $this->assertLessThanOrEqual(PP_UDC_MAX_EMIT_DROPS, $count);
    }
}
