<?php
/**
 * tests/RoleInkOverOwnSurfaceTest.php — the role's own surface under a new ink (#1125, ruling D5 = B),
 * landed on the compiled band (T2 of Sprint 3, #1145).
 *
 * THE TRAP. Darken a band, recolour every text role as the instructions say, and a role that
 * ships its OWN background fill (the eyebrow pill, a panel, a card) keeps that light surface
 * under the new light ink: 1.76:1 measured by the alpha.2 release smoke. Both values apply
 * correctly; they clash, and the write said `findings: []`.
 *
 * WHAT PAINTS IS READ, NEVER RE-DERIVED. The first cut of this finding read the author's map and
 * preset fragments and was withdrawn (a90a93c) because a role DEFAULT outranks a preset: it missed
 * the exact trap (author ink + a preset fill the default shadows) and contradicted the
 * shadowed-preset disclosure on the same write. This landing reads pp_udc_role_paint(), the
 * renderer-owned answer to "which ink and which surface paint on this role", so both of those
 * cases are pinned here first, and every other case follows from the compiled band.
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

    /** [post id, this finding, every finding] through the real authoring surface (rule 14.1). */
    private function write(array $udc, string $component = 'section', array $props = ['eyebrow' => 'SECTION', 'title' => 'Dark band', 'body' => 'Body']): array
    {
        $id = pp_create_page('ink', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [
            ['component' => $component, 'udc' => $udc, 'props' => $props],
        ]]);
        $this->assertTrue($result['ok'], 'premise: written: ' . ($result['error'] ?? ''));
        $all = $result['findings'] ?? [];
        return [$id, $this->only($all), $all];
    }

    private function only(array $findings): array
    {
        return array_values(array_filter($findings, static fn (array $f): bool => ($f['type'] ?? '') === self::TYPE));
    }

    private function types(array $findings): array
    {
        return array_values(array_unique(array_map(static fn (array $f): string => (string) ($f['type'] ?? ''), $findings)));
    }

    private function savePreset(string $name, string $grain, array $udc): void
    {
        $saved = pp_execute_action('save_preset', ['name' => $name, 'grain' => $grain, 'udc' => $udc]);
        $this->assertTrue($saved['ok'], 'premise: preset saved: ' . (string) ($saved['error'] ?? ''));
    }

    // ── The red team's two cases (the #1125 body's design inputs) ───────────────────────

    /**
     * THE MISSED TRAP. Author ink in the map, a dark fill through a preset: the eyebrow's DEFAULT
     * fill outranks the preset, so the emitter paints the white ink over the light default pill.
     * The withdrawn arm counted the preset fill as covering the role and stayed silent.
     */
    public function testAPresetFillTheDefaultShadowsDoesNotCoverTheRole(): void
    {
        $this->savePreset('probe-pill', 'role', ['background' => ['fill' => '#222222']]);
        $this->savePreset('probe-bg', 'background', ['fill' => '#222222']);
        foreach ([
            'role grain'       => ['_preset' => 'probe-pill', 'typography' => ['color' => '#ffffff']],
            'background grain' => ['background' => ['_preset' => 'probe-bg'], 'typography' => ['color' => '#ffffff']],
        ] as $label => $eyebrow) {
            [, $found, $all] = $this->write(['_band' => ['background' => ['fill' => '#101828']], 'eyebrow' => $eyebrow]);
            $this->assertCount(1, $found, $label . ': the ink clash is named');
            $this->assertStringContainsString('role "eyebrow"', $found[0]['message'], $label);
            $this->assertStringContainsString('@color-surface-accent', $found[0]['message'], $label . ': names the fill that paints');
            $this->assertContains('udc_preset_value_shadowed_by_role_default', $this->types($all),
                $label . ': the sibling still names the shadowed preset fill; the two agree');
        }
    }

    /**
     * THE CONTRADICTION. A preset ink the eyebrow's default colour shadows is not painted, so it is
     * not "the new ink on that surface": silent, while the shadowed-preset disclosure on the SAME
     * write says the colour was not applied. Two findings may never disagree about one value.
     */
    public function testAPresetInkTheDefaultShadowsIsNotAnInk(): void
    {
        $this->savePreset('ink-role', 'role', ['typography' => ['color' => '#ffffff']]);
        $this->savePreset('ink-type', 'typography', ['color' => '#ffffff']);
        foreach ([['_preset' => 'ink-role'], ['typography' => ['_preset' => 'ink-type']]] as $eyebrow) {
            [, $found, $all] = $this->write(['_band' => ['background' => ['fill' => '#101828']], 'eyebrow' => $eyebrow]);
            $this->assertSame([], $found, json_encode($eyebrow) . ': no painted author ink');
            $this->assertContains('udc_preset_value_shadowed_by_role_default', $this->types($all),
                'premise: the shadowed ink is disclosed by its own finding');
        }
    }

    // ── The issue's shape and the D5 gate ───────────────────────────────────────────────

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
        $this->assertStringContainsString('Component "section" role "eyebrow"', $found[0]['message']);
        $this->assertStringContainsString('(@color-surface-accent)', $found[0]['message'], 'names the surviving fill');
        $this->assertStringContainsString('background.fill', $found[0]['message'], 'names the fix');
        $this->assertStringNotContainsString(' width', $found[0]['message'], 'every width: no width qualifier');
        $this->assertSame(0, $found[0]['index']);

        // The same fact on the stored-state channels (check page, restore): same engine.
        $this->assertCount(1, $this->only(pp_udc_composition_findings(pp_get_composition($id))));
    }

    /** D5's negative: no authored `_band` background, no finding, even with the same ink. */
    public function testNoFindingWhenTheBandBackgroundIsNotAuthored(): void
    {
        [, $found] = $this->write(['eyebrow' => ['typography' => ['color' => '@color-accent-on-inverted']]]);
        $this->assertSame([], $found);
    }

    /**
     * A band darkened through a preset the author applied is an authored band surface, where the
     * preset's fill actually paints: grid's `_band` defaults no fill, so the preset reaches it.
     */
    public function testABandDarkenedByAPresetIsAnAuthoredBandSurface(): void
    {
        $this->savePreset('probe-dark', 'background', ['fill' => '#101828']);
        $this->assertCount(1, $this->gridFindings(['_band' => ['background' => ['_preset' => 'probe-dark']],
            'step-number' => ['typography' => ['color' => '#101828']]], [null], false));
    }

    /**
     * A band preset fill that the band's own DEFAULT fill shadows never darkens the band (section's
     * `_band` defaults a transparent fill), so D5's gate is not met and nothing here is named; the
     * shadowed-preset disclosure says the fill was not applied. Read off the compile, not assumed.
     */
    public function testABandPresetFillTheDefaultShadowsIsNotAnAuthoredBandSurface(): void
    {
        $this->savePreset('probe-dark', 'background', ['fill' => '#101828']);
        [, $found, $all] = $this->write([
            '_band'   => ['background' => ['_preset' => 'probe-dark']],
            'eyebrow' => ['typography' => ['color' => '@color-bg']],
        ]);
        $this->assertSame([], $found);
        $this->assertContains('udc_preset_value_shadowed_by_role_default', $this->types($all), 'premise: the band fill was not applied');
    }

    /**
     * Two spellings of one emitted page get one answer (red team, cycle 1): a band surface written
     * inside a `_css` state emits exactly what the group's state spelling emits, so the gate must see it.
     */
    public function testARawCssStateBandSurfacePassesTheGateLikeTheGroupSpelling(): void
    {
        foreach ([['_css' => [':hover' => ['background' => '#101828']]], ['background' => [':hover' => ['fill' => '#101828']]]] as $band) {
            [, $found] = $this->write(['_band' => $band, 'eyebrow' => ['typography' => ['color' => '#ffffff']]]);
            $this->assertCount(1, $found, json_encode($band));
        }
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

    /** A raw `_css` background on `_band` is an authored band surface; on the role it is the role's fill. */
    public function testARawCssBackgroundCountsOnBothSides(): void
    {
        [, $found] = $this->write([
            '_band'   => ['_css' => ['background-color' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '@color-bg']],
        ]);
        $this->assertCount(1, $found, 'a _css background on _band is an authored band surface');

        foreach (['background-color', 'background'] as $property) {
            [, $found] = $this->write([
                '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
                'eyebrow' => ['typography' => ['color' => '@color-bg'], '_css' => [$property => '#000000']],
            ]);
            $this->assertSame([], $found, 'a _css ' . $property . ' on the role is its own surface');
        }
    }

    // ── What covers the role, and what does not ──────────────────────────────────────────

    /** The author set the role's own fill too: nothing of the default survives underneath. */
    public function testNoFindingWhenTheRoleFillIsAuthored(): void
    {
        foreach (['#1d2939', 'transparent'] as $fill) {
            [, $found] = $this->write([
                '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
                'eyebrow' => ['typography' => ['color' => '@color-accent-on-inverted'], 'background' => ['fill' => $fill]],
            ]);
            $this->assertSame([], $found, $fill);
        }
    }

    /**
     * Every role that ships its own surface also DEFAULTS that fill, so no preset fill can ever
     * paint on one: the default outranks it. Pinned on an item role too (grid `step-number`), so the
     * missed-trap fix is not an eyebrow special case.
     */
    public function testAShadowedPresetFillOnAnItemRoleDoesNotCoverIt(): void
    {
        $this->savePreset('badge-dark', 'background', ['fill' => '#1d2939']);
        $found = $this->gridFindings(['step-number' => ['background' => ['_preset' => 'badge-dark'], 'typography' => ['color' => '#101828']]], [null]);
        $this->assertCount(1, $found, 'a shadowed preset fill never paints, whatever the grain');
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

    /** A role image whose attachment is gone paints nothing, so the default fill still shows. */
    public function testADeletedRoleImageDoesNotCoverTheFill(): void
    {
        $found = $this->only(pp_udc_composition_findings([[
            'component' => 'section', 'id' => 'pp-a1b2c3d4',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']],
                            'panel' => ['background' => ['image' => 9002], 'typography' => ['color' => '@color-bg']]],
            'props'     => ['title' => 'T', 'body' => 'b', 'layout' => 'text-panel', 'panel_body' => 'Panel text'],
        ]]));
        $this->assertCount(1, $found, 'no attachment 9002 in the store');
        $this->assertStringContainsString('role "panel"', $found[0]['message']);
    }

    /** A role with no surface of its own is not this trap. */
    public function testNoFindingForARoleWithoutAnOwnSurface(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'heading' => ['typography' => ['color' => '@color-bg']],
            'body'    => ['typography' => ['color' => '@color-bg']],
        ]);
        $this->assertSame([], $found);
    }

    /** A transparent default fill is no surface: the band shows through. */
    public function testATransparentOwnFillIsNotASurface(): void
    {
        [, $found] = $this->write(
            ['_band' => ['background' => ['fill' => '@color-bg-inverted']], 'button-secondary' => ['typography' => ['color' => '@color-bg']]],
            'cta',
            ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x', 'button2_text' => 'More', 'button2_url' => '/y']
        );
        $this->assertSame([], $found, 'at rest the secondary button is transparent; its :hover default also re-inks it');
    }

    // ── Inks ─────────────────────────────────────────────────────────────────────────────

    /** An ink written through the raw-CSS valve is an authored ink too. */
    public function testARawCssInkIsDisclosed(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['_css' => ['color' => '#ffffff']],
        ]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('typography.color, a preset you applied, or _css', $found[0]['message'],
            'the message names every channel an ink comes through');
    }

    /** An ink a preset supplies, on a role whose colour is NOT defaulted, paints and is disclosed (hero `surface`). */
    public function testAPaintingPresetInkIsDisclosed(): void
    {
        $this->savePreset('ink-role', 'role', ['typography' => ['color' => '#ffffff']]);
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']], 'surface' => ['_preset' => 'ink-role']],
            'hero', ['layout' => 'split', 'title' => 'H', 'proof' => '<p>Proof</p>']);
        $this->assertCount(1, $found, 'hero surface defaults a fill and no colour, so the preset ink paints');
        $this->assertStringContainsString('role "surface"', $found[0]['message']);
    }

    /** An ink authored only for a state lands on the role's own surface in that state. */
    public function testAStateOnlyInkIsDisclosedWithItsState(): void
    {
        [, $found] = $this->write([
            '_band'   => ['background' => ['fill' => '@color-bg-inverted']],
            'eyebrow' => ['typography' => [':hover' => ['color' => '@color-bg']]],
        ]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString(':hover', $found[0]['message']);
    }

    /**
     * A default that paints a surface only in a state is named with that state: cta's secondary
     * button is transparent at rest and fills `@color-accent` on `:hover`, so an author `:hover` ink
     * lands on the default hover fill (a resting ink does not: the default's own `:hover` colour
     * outranks it on specificity, which testATransparentOwnFillIsNotASurface pins).
     */
    public function testAStateOnlyDefaultSurfaceIsNamedWithItsState(): void
    {
        [, $found] = $this->write(
            ['_band' => ['background' => ['fill' => '@color-bg-inverted']],
             'button-secondary' => ['typography' => [':hover' => ['color' => '#fff5a0']]]],
            'cta',
            ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x', 'button2_text' => 'More', 'button2_url' => '/y']
        );
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "button-secondary"', $found[0]['message']);
        $this->assertStringContainsString('(@color-accent) in the :hover state, and the band background', $found[0]['message'], 'light only while hovered, at every width');
    }

    /**
     * The advice says WHERE the fill goes, and following it literally clears the finding (api-contract
     * pass, cycle 1: a resting fill cannot cover a default :hover fill, 0,2,0 against 0,3,0, and a
     * model told only "set background.fill" loops).
     */
    public function testFollowingTheStateAdviceLiterallyClearsTheFinding(): void
    {
        $props = ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x', 'button2_text' => 'More', 'button2_url' => '/y'];
        $ink   = ['typography' => ['color' => '#ffffff', ':hover' => ['color' => '#ffffff']]];
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']], 'button-secondary' => $ink], 'cta', $props);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('background: {":hover": {"fill": ...}}', $found[0]['message']);
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
            'button-secondary' => $ink + ['background' => [':hover' => ['fill' => '#222222']]]], 'cta', $props);
        $this->assertSame([], $found, 'the fill set where the message said clears it');
    }

    public function testFollowingTheWidthAdviceLiterallyClearsTheFinding(): void
    {
        $ink = ['typography' => ['color' => '#ffffff']];
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
            'eyebrow' => $ink + ['background' => ['fill' => ['d' => '@color-surface-accent', 'p' => '#101828']]]]);
        $this->assertSame([], $found, 'premise: every width authored');
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
            'eyebrow' => $ink + ['background' => ['fill' => ['p' => '#101828']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('a breakpoint map, e.g. {"d": ..., "t": ...}', $found[0]['message']);
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
            'eyebrow' => $ink + ['background' => ['fill' => ['d' => '#101828', 't' => '#101828', 'p' => '#101828']]]]);
        $this->assertSame([], $found, 'the fill set where the message said clears it');
    }

    /**
     * States COMBINE in the browser (design pass, cycle 1): a mouse press is :active AND :hover, a
     * focused control under the pointer is :focus-visible AND :hover. An author :active ink therefore
     * lands on the DEFAULT :hover fill while pressed (measured rgb(255,245,160) on rgb(49,87,244)).
     * The advice names the author's own state: an :active fill prints after the default :hover fill.
     */
    public function testAnInkInACombinedStateIsNamed(): void
    {
        $props = ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x', 'button2_text' => 'More', 'button2_url' => '/y'];
        foreach ([':active', ':focus-visible'] as $state) {
            [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
                'button-secondary' => ['typography' => ['color' => '#ffffff', $state => ['color' => '#fff5a0']]]], 'cta', $props);
            $this->assertCount(1, $found, $state);
            $this->assertStringContainsString('in the :hover and ' . $state . ' states together', $found[0]['message'], $state);
            $this->assertStringContainsString('background: {"' . $state . '": {"fill": ...}}', $found[0]['message'], $state);
            [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
                'button-secondary' => ['typography' => ['color' => '#ffffff', $state => ['color' => '#fff5a0']],
                    'background' => [$state => ['fill' => '#222222']]]], 'cta', $props);
            $this->assertSame([], $found, $state . ': the fill the advice names clears it');
        }
    }

    /** A clash only at rest says "at rest" when the element has another state the author covered. */
    public function testARestOnlyClashIsQualifiedWhenAnotherStateIsCovered(): void
    {
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '#ffffff'], 'background' => [':hover' => ['fill' => '#1d2939']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('(@color-surface-accent) at rest, and the band background', $found[0]['message']);
        $this->assertStringContainsString('If text roles inside this one set their own colour, they keep it', $found[0]['message']);
    }

    // ── An ink the role INHERITS from the band (ruling D3 = A) ────────────────────────────

    private const BAND_INK = 'the text colour you set on the whole band (_band typography.color or _band _css color) reaches this role';

    /**
     * THE RED TEAM'S CASE: `_band` darkened and coloured in one place; hero `surface` declares no colour
     * of its own, so the band's white reaches it and paints over its own light default fill (1.07:1
     * in Chromium). Named, and named as the BAND's ink, so the author knows which move caused it.
     */
    public function testAnInkInheritedFromTheBandOverTheRolesOwnFillIsNamed(): void
    {
        [, $found, $all] = $this->write(
            ['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]],
            'hero',
            ['layout' => 'split', 'title' => 'H', 'proof' => '<p>Proof text here</p>']
        );
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "surface"', $found[0]['message']);
        $this->assertStringContainsString(self::BAND_INK, $found[0]['message']);
        $this->assertStringContainsString('(@color-surface)', $found[0]['message']);
        $this->assertContains('udc_band_value_shadowed_by_role_default', $this->types($all), 'premise: the sibling speaks on the same write');
    }

    /** The role sets its own colour: it is the author's own ink, not the band's, and the wording says so. */
    public function testARoleThatSetsItsOwnColourIsNotBlamedOnTheBand(): void
    {
        [, $found] = $this->write(
            ['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']],
             'surface' => ['typography' => ['color' => '#eeeeee']]],
            'hero',
            ['layout' => 'split', 'title' => 'H', 'proof' => '<p>Proof text here</p>']
        );
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString(self::BAND_INK, $found[0]['message']);
        $this->assertStringContainsString('the text colour you set for this role', $found[0]['message']);
    }

    /** A role whose own DEFAULT colour cancels the band's (the eyebrow) keeps its ink: nothing of the author's paints there. */
    public function testABandInkARoleDefaultCancelsIsNotNamed(): void
    {
        [, $found] = $this->write(
            ['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]]
        );
        $this->assertSame([], $found, 'section: eyebrow and panel default their own colour');
    }

    /**
     * THE BAND-INK SUBJECTS, CENSUSED THROUGH THE ACCESSOR (cycle 2, testing). Every composable and
     * chrome role that renders its own text, compiled on a band whose `_band` sets a fill and a colour:
     * the cells where its own DEFAULT (or overlay) surface paints under no ink of its own, or a
     * `currentColor` one. That is exactly where the band's ink can be named. Pinned to hero `surface`
     * alone, so: a card PART becoming a subject (then write the card-root interception, with a test: its ink may be the card's, not the band's), a
     * `currentColor` default under a surface, or a header/footer role with its own fill under its own
     * text (the chrome prompt sentence) each fail here instead of passing over an unreachable branch.
     */
    public function testNoShippedItemPartYetTakesTheBandInkThroughItsCard(): void
    {
        $subjects = [];
        foreach (array_merge(array_keys(pp_composable_components()), pp_udc_chrome_names()) as $component) {
            $component = (string) $component;
            $item      = ['component' => $component, 'id' => pp_udc_is_chrome($component) ? $component : 'pp-a1b2c3d4', 'props' => [],
                'udc' => ['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]]];
            $roles     = pp_udc_component_roles($component);
            $compiled  = pp_udc_compile_band($item, 'authored');
            foreach (pp_udc_role_paint($item, $compiled, pp_udc_compile_band(['component' => $component], 'defaults'), false) as $element) {
                if (($roles[$element['role']]['text_content'] ?? false) !== true) {
                    continue;
                }
                foreach ($element['paint'] as $by_bp) {
                    foreach ($by_bp as $cell) {
                        $surface = $cell['surface'];
                        $ink     = $cell['color'];
                        if ($surface !== null && in_array($surface['tier'], ['defaults', 'overlay'], true)
                            && ($ink === null || strcasecmp(trim((string) $ink['literal']), 'currentColor') === 0)) {
                            $subjects[$component . '.' . $element['role']] = true;
                        }
                    }
                }
            }
        }
        $this->assertSame(['hero.surface'], array_keys($subjects));
    }

    /**
     * THE TWO FINDINGS NEVER CONTRADICT (D3 condition 2, extended under ruling A), asserted as an OUTCOME
     * over these shapes: a role this finding names as reached by the band's ink is never one the sibling
     * names as NOT reached, and it is always a role that renders its own text. (Today the agreement is
     * also implied by the null-ink check; the shared predicate keeps it true when a role gains a colour
     * default.)
     */
    public function testTheBandInkNeverContradictsTheSiblingDisclosure(): void
    {
        $band = ['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]];
        $shapes = [
            ['hero', ['layout' => 'split', 'title' => 'H', 'proof' => '<p>P</p>', 'eyebrow' => 'E']],
            ['section', ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b', 'layout' => 'text-panel', 'panel_body' => 'x']],
            ['faq', ['title' => 'F', 'items' => [['question' => 'Q?', 'answer' => '<p>A</p>']]]],
            ['grid', ['layout' => 'steps', 'title' => 'G', 'items' => [['number' => '1', 'title' => 'T', 'text' => 'x']]]],
            ['table', ['title' => 'T', 'headers' => ['A'], 'rows' => [['1']]]],
            ['testimonials', ['items' => [['quote' => 'Q', 'author' => 'A']]]],
            ['cta', ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x', 'eyebrow' => 'E']],
        ];
        $named = [];
        foreach ($shapes as [$component, $props]) {
            $all = pp_udc_composition_findings([['component' => $component, 'id' => 'pp-a1b2c3d4', 'udc' => $band, 'props' => $props]]);
            $not_reached = [];
            foreach ($all as $f) {
                if ($f['type'] === 'udc_band_value_shadowed_by_role_default' && str_contains($f['message'], '"color"')) {
                    $this->assertStringNotContainsString('take it on their own element', $f['message'],
                        'the sibling makes no reach claim about the roles it does not list (E2-A)');
                    preg_match_all('/\\b([a-z][a-z0-9-]*)\\b/', substr($f['message'], (int) strpos($f['message'], 'does not reach')), $m);
                    $not_reached = array_merge($not_reached, $m[1]);
                }
            }
            foreach ($this->only($all) as $f) {
                preg_match('/role "([^"]+)"/', $f['message'], $role);
                $this->assertTrue(pp_udc_component_roles($component)[$role[1]]['text_content'] ?? false, $component . ' ' . $role[1] . ' renders its own text');
                if (str_contains($f['message'], self::BAND_INK)) {
                    $named[] = $component . '.' . $role[1];
                    $this->assertNotContains($role[1], $not_reached, $component . ': the sibling says the band colour does not reach ' . $role[1]);
                }
            }
        }
        $this->assertContains('hero.surface', $named, 'vacuity floor: the one shipped band-ink subject fired');
    }

    /**
     * `text_content` IS DATA FROM A MEASUREMENT (ruling A): the flagged set is pinned exactly to the
     * Chromium sweep (evidence-t2/text-content-sweep.txt: each role inked alone, a visible glyph in its
     * ink). Changing a schema's key means re-measuring, not editing this list by reasoning. And the
     * definition gate refuses any value but `true`.
     */
    public function testTheTextContentKeyMatchesTheMeasurementAndIsGated(): void
    {
        $flagged = [];
        foreach (array_merge(array_keys(pp_composable_components()), pp_udc_chrome_names()) as $component) {
            foreach (pp_udc_component_roles((string) $component) as $role => $definition) {
                if (($definition['text_content'] ?? null) === true) {
                    $flagged[] = $component . '.' . $role;
                }
            }
        }
        sort($flagged);
        $total = 0;
        foreach (array_merge(array_keys(pp_composable_components()), pp_udc_chrome_names()) as $component) {
            $total += count(pp_udc_component_roles((string) $component)) - 1; // `_band` is not measured
        }
        $this->assertSame(131, $total, 'a role added or renamed must be measured and its key decided');
        $this->assertSame([
            'cta.body', 'cta.body-link', 'cta.button', 'cta.button-secondary', 'cta.eyebrow', 'cta.heading',
            'cta.heading-accent', 'cta.inner', 'cta.text', 'embed.content', 'embed.content-link', 'embed.heading',
            'faq.answer', 'faq.answer-link', 'faq.empty', 'faq.eyebrow', 'faq.heading', 'faq.heading-accent',
            'faq.question', 'faq.question-open', 'footer.address', 'footer.address-link', 'footer.blurb',
            'footer.brand', 'footer.columns', 'footer.contact', 'footer.copyright', 'footer.heading',
            'footer.inner', 'footer.link', 'footer.note', 'grid.card-bullet', 'grid.card-bullets',
            'grid.card-link', 'grid.card-text', 'grid.card-title', 'grid.empty', 'grid.eyebrow', 'grid.header',
            'grid.heading', 'grid.heading-accent', 'grid.step-number', 'grid.subheading', 'hero.cta',
            'hero.cta-secondary', 'hero.eyebrow', 'hero.inner', 'hero.proof', 'hero.proof-link', 'hero.subtitle',
            'hero.surface', 'hero.title', 'hero.title-accent', 'logos.heading', 'logos.label', 'nav.link',
            'nav.link-current', 'nav.logo', 'nav.toggle', 'section.body', 'section.body-link', 'section.eyebrow',
            'section.heading', 'section.heading-accent', 'section.inline-items', 'section.panel',
            'section.panel-body', 'section.panel-cta', 'section.panel-heading', 'section.panel-list',
            'section.panel-row', 'section.panel-row-label', 'section.panel-row-value', 'section.subheading',
            'stats.heading', 'stats.heading-accent', 'stats.label', 'stats.number', 'table.caption', 'table.cell',
            'table.cell-link', 'table.empty', 'table.header', 'table.heading', 'testimonials.author',
            'testimonials.eyebrow', 'testimonials.heading', 'testimonials.heading-accent', 'testimonials.meta',
            'testimonials.quote', 'testimonials.subheading',
        ], $flagged);

        $role = ['selector' => '.a', 'description' => 'd', 'groups' => ['typography'], 'defaults' => []];
        $this->assertSame([], pp_schema_definition_errors($role + ['text_content' => true], 'role', 'r'));
        foreach ([false, 'yes', 1, []] as $bad) {
            $this->assertStringContainsString('`text_content` must be true',
                implode(' | ', pp_schema_definition_errors($role + ['text_content' => $bad], 'role', 'r')), json_encode($bad));
        }
    }

    /**
     * MIXED: the role inks only :hover, the band's ink reaches it at rest (cycle 2, testing). The wording
     * names both moves, and the advice names BOTH places the fill goes, so following it clears the finding.
     */
    public function testAMixedRestAndStateClashNamesBothMovesAndBothFills(): void
    {
        $props = ['layout' => 'split', 'title' => 'H', 'proof' => '<p>Proof</p>'];
        $band  = ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']];
        [, $found] = $this->write(['_band' => $band, 'surface' => ['typography' => [':hover' => ['color' => '#eeeeee']]]], 'hero', $props);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('the text colour you set for this role (typography.color, a preset you applied, or _css), and where it sets none the one you set on the whole band', $found[0]['message']);
        $this->assertStringContainsString('at rest and inside :hover (background: {"fill": ..., ":hover": {"fill": ...}})', $found[0]['message']);
        [, $found] = $this->write(['_band' => $band, 'surface' => ['typography' => [':hover' => ['color' => '#eeeeee']],
            'background' => ['fill' => '#1d2939', ':hover' => ['fill' => '#1d2939']]]], 'hero', $props);
        $this->assertSame([], $found, 'the two fills the advice names clear it');
    }

    /** A band ink set through `_band` `_css` is credited to that spelling too (cycle 2, maintainability). */
    public function testABandInkSetThroughRawCssIsCreditedToTheBand(): void
    {
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828'], '_css' => ['color' => '#ffffff']]],
            'hero', ['layout' => 'split', 'title' => 'H', 'proof' => '<p>Proof</p>']);
        $this->assertCount(1, $found);
        $this->assertStringContainsString(self::BAND_INK, $found[0]['message']);
    }

    /**
     * The sibling's "set it on those roles directly" names which of them ship their own fill (cycle 2,
     * api-contract), so following it does not walk the author into this finding.
     */
    public function testTheSiblingAdviceNamesTheRolesThatNeedTheirFillToo(): void
    {
        [, , $all] = $this->write(['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]],
            'hero', ['layout' => 'split', 'title' => 'H', 'proof' => '<p>P</p>', 'eyebrow' => 'E']);
        $sibling = array_values(array_filter($all, static fn ($f) => $f['type'] === 'udc_band_value_shadowed_by_role_default'));
        $this->assertCount(1, $sibling);
        $this->assertStringContainsString('Set it on those roles directly (cta-secondary, eyebrow ship their own fill, so set each one\'s background.fill with the colour).', $sibling[0]['message']);
    }

    /**
     * A RESTATED DEFAULT IS NOT A CLASH (ruling, cycle 2 api-contract): an author ink that compiles to the
     * same value as the default ink it overrides, for that state and width, leaves the designed pair
     * unchanged. Read off the compiled tiers. A literal that merely equals a token's value still fires
     * (write the token), which the docs say.
     */
    public function testARestatedDefaultInkIsNotAClash(): void
    {
        $cta = ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x', 'button2_text' => 'More', 'button2_url' => '/y'];
        foreach ([
            'cta designed hover pair' => ['cta', ['button-secondary' => ['typography' => ['color' => '@color-bg', ':hover' => ['color' => '@color-bg']]]], $cta],
            'section eyebrow rest default' => ['section', ['eyebrow' => ['typography' => ['color' => '@color-text']]], ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b']],
            'grid step-number badge' => ['grid', ['step-number' => ['typography' => ['color' => '@color-bg']]], ['layout' => 'steps', 'title' => 'G', 'items' => [['number' => '1', 'title' => 'T']]]],
        ] as $label => [$component, $udc, $props]) {
            [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']]] + $udc, $component, $props);
            $this->assertSame([], $found, $label);
        }
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']], 'eyebrow' => ['typography' => ['color' => '@color-bg']]]);
        $this->assertCount(1, $found, 'a genuinely different ink still fires');
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '@color-text', ':hover' => ['color' => '#ffffff']]]]);
        $this->assertCount(1, $found, 'a restated rest ink with a changed hover ink');
        $this->assertStringContainsString('(@color-surface-accent) in the :hover state, and the band background', $found[0]['message'], 'fires on hover only');
    }

    /**
     * E2-A: the sibling makes NO reach claim about the roles it does not list. Hero `title` is an h1 that
     * base.css colours directly (`h1..h6 { color: var(--color-text) }`), so a band ink never reaches it
     * (measured 1:1 on a dark band); the sibling does not list it (it reads role defaults only, filed
     * separately) and must not describe it as taking the ink either.
     */
    public function testTheSiblingMakesNoReachClaimForAStructurallyRuledRole(): void
    {
        [, , $all] = $this->write(['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]],
            'hero', ['layout' => 'centered', 'title' => 'H', 'subheading' => 'S']);
        $sibling = array_values(array_filter($all, static fn ($f) => $f['type'] === 'udc_band_value_shadowed_by_role_default'));
        $this->assertCount(1, $sibling);
        $this->assertStringNotContainsString('take it', $sibling[0]['message']);
        $this->assertStringNotContainsString('other roles', $sibling[0]['message']);
        $this->assertMatchesRegularExpression('/directly( \([^)]*\))?\.\z/', $sibling[0]['message'], 'the message ends at its advice');
    }

    // ── On the page (ruling E1-A): only roles the band renders with these props ─────────────

    /** The ordinary dark hero (centered, no proof) renders no `surface`: the band ink there names nothing. */
    public function testACenteredHeroNamesNoSurfaceItDoesNotRender(): void
    {
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]],
            'hero', ['layout' => 'centered', 'title' => 'H', 'subheading' => 'S', 'button_text' => 'Go', 'button_url' => '/x']);
        $this->assertSame([], $found);
    }

    /** The eyebrow renders only with an `eyebrow` prop: the same ink without it names nothing. */
    public function testAnEyebrowWithoutItsPropIsNotNamed(): void
    {
        $udc = ['_band' => ['background' => ['fill' => '#101828']], 'eyebrow' => ['typography' => ['color' => '#ffffff']]];
        [, $found] = $this->write($udc, 'section', ['title' => 'T', 'body' => 'b']);
        $this->assertSame([], $found);
        [, $found] = $this->write($udc, 'section', ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b']);
        $this->assertCount(1, $found, 'premise: with the prop it is named');
    }

    /**
     * THE RENDER IS SIDE-EFFECT FREE (ruling E1-A condition): nothing is emitted, the output-buffer level
     * and the error handler are restored, no global is added, and the store is untouched.
     */
    public function testThePresenceRenderIsSideEffectFree(): void
    {
        $band = ['component' => 'hero', 'id' => 'pp-a1b2c3d4', 'props' => ['layout' => 'split', 'title' => 'H', 'proof' => '<p>P</p>'], 'udc' => []];
        $level   = ob_get_level();
        $globals = array_keys($GLOBALS);
        $store   = serialize($GLOBALS['_pp_test_store']);
        $marker  = static fn (): bool => false;
        set_error_handler($marker);
        ob_start();
        $presence = _pp_udc_rendered_roles($band, ['surface' => '.hero__surface', 'eyebrow' => '.hero__eyebrow']);
        $emitted  = ob_get_clean();
        $restored = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        restore_error_handler();
        $this->assertSame('', $emitted, 'nothing is emitted');
        $this->assertSame($level, ob_get_level(), 'the buffer level is restored');
        $this->assertSame($marker, $restored, 'the error handler is restored');
        $this->assertSame([], array_values(array_diff(array_keys($GLOBALS), $globals)), 'no global is added');
        $this->assertSame($store, serialize($GLOBALS['_pp_test_store']), 'the store is untouched');
        $this->assertTrue($presence['surface']['band']);
        $this->assertFalse($presence['eyebrow']['band']);
    }

    /**
     * THE RENDER IS BOUNDED (E1-A cost condition): at most 25 bands per call are rendered; past that,
     * presence is unknown and the finding keeps its unfiltered answer (never silence). 30 centered heroes
     * render no surface: the first 25 are silenced by the render, the last 5 are not rendered and keep firing.
     */
    public function testThePresenceRenderIsBoundedPerCall(): void
    {
        $bands = [];
        for ($b = 0; $b < 30; $b++) {
            $bands[] = ['component' => 'hero', 'id' => sprintf('pp-%08x', $b + 1), 'props' => ['layout' => 'centered', 'title' => 'H'],
                'udc' => ['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]]];
        }
        $found = $this->only(pp_udc_composition_findings($bands));
        $this->assertSame([25, 26, 27, 28, 29], array_column($found, 'index'));
    }

    /**
     * THE RENDER RUNS NO SHORTCODE (cycle 2, red team): `embed` echoes do_shortcode(), and in WordPress a
     * real shortcode (`[embed]`) fetches over HTTP and writes a cache post. The registry is emptied for the
     * render and restored after. The test bootstrap's do_shortcode() ignores the registry, so what is
     * observable here is the restoration, plus the clearing statement pinned by its call shape.
     */
    public function testThePresenceRenderRunsNoShortcodeAndRestoresTheRegistry(): void
    {
        global $shortcode_tags;
        $before         = $shortcode_tags ?? null;
        $shortcode_tags = ['probe' => static fn (): string => 'X'];
        try {
            $presence = _pp_udc_rendered_roles(['component' => 'embed', 'id' => 'pp-a1b2c3d4',
                'props' => ['title' => 'T', 'content' => '<p>[probe]</p>'], 'udc' => []], ['content' => '.embed__content']);
            $this->assertTrue($presence['content']['band']);
            $this->assertSame(['probe'], array_keys($shortcode_tags), 'the registry is restored');
        } finally {
            $shortcode_tags = $before;
        }
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertMatchesRegularExpression('/\$saved_shortcodes = \$shortcode_tags \?\? null;\n\s+\$shortcode_tags\s+= \[\];/', $source);
    }

    /** The selector-to-XPath reader covers the shipped selector grammar and refuses anything else. */
    public function testTheRoleSelectorReaderCoversTheShippedGrammar(): void
    {
        $count = 0;
        foreach (array_merge(array_keys(pp_composable_components()), pp_udc_chrome_names()) as $component) {
            foreach (pp_udc_component_roles((string) $component) as $role => $definition) {
                if ($role === '_band') {
                    continue;
                }
                $count++;
                $this->assertNotNull(_pp_udc_selector_xpath((string) $definition['selector']), $component . ' ' . $role);
            }
        }
        $this->assertSame(131, $count);
        $this->assertSame("//*[contains(concat(' ', normalize-space(@class), ' '), ' faq__item ')][@open]/*[contains(concat(' ', normalize-space(@class), ' '), ' faq__question ')]",
            _pp_udc_selector_xpath('.faq__item[open] > .faq__question'));
        $this->assertNull(_pp_udc_selector_xpath('a:hover'));
        $this->assertNull(_pp_udc_selector_xpath(''));
    }

    /** A single width left on the default is named in the singular, with its one breakpoint key. */
    public function testASingleWidthIsNamedInTheSingular(): void
    {
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '#ffffff'], 'background' => ['fill' => ['t' => '#101828', 'p' => '#101828']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('(@color-surface-accent) at the desktop width, and the band background', $found[0]['message']);
        $this->assertStringContainsString('at that width (a breakpoint map, e.g. {"d": ...})', $found[0]['message']);
    }

    // ── Widths ───────────────────────────────────────────────────────────────────────────

    /** A default surface left at some widths only is named with those widths (the author filled the phone width). */
    public function testATierOnlySurfaceIsNamedWithItsWidth(): void
    {
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '#ffffff'], 'background' => ['fill' => ['p' => '#101828']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('(@color-surface-accent) at the desktop and tablet widths, and the band background', $found[0]['message']);
    }

    /**
     * INVERTED BY RULING A (text_content): nav `menu` ships a phone-only fill, but its links set their own
     * colour, so an ink on `menu` shows on no glyph (measured). It was named; it is now correctly silent.
     */
    public function testAContainerWhoseTextSetsItsOwnColourIsNotNamed(): void
    {
        foreach ([['nav', 'nav', 'menu', []], ['grid', 'pp-a1b2c3d4', 'card', ['title' => 'G', 'items' => [['title' => 'T']]]],
                  ['faq', 'pp-a1b2c3d4', 'item', ['title' => 'F', 'items' => [['question' => 'Q?', 'answer' => '<p>A</p>']]]],
                  ['table', 'pp-a1b2c3d4', 'head', ['title' => 'T', 'headers' => ['A'], 'rows' => [['1']]]],
                  ['testimonials', 'pp-a1b2c3d4', 'card', ['items' => [['quote' => 'Q', 'author' => 'A']]]]] as [$component, $id, $role, $props]) {
            $this->assertNotTrue(pp_udc_component_roles($component)[$role]['text_content'] ?? false, 'premise: measured container');
            $this->assertSame([], $this->only(pp_udc_composition_findings([['component' => $component, 'id' => $id, 'props' => $props,
                'udc' => ['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']],
                          $role => ['typography' => ['color' => '#ffffff']]]]])), $component . ' ' . $role);
        }
    }

    /**
     * THE DOCUMENTED DARK-FAQ MIGRATION IS SILENT (ruling A, condition 3): its map, copied from
     * docs/howto-migrate-a-faq-band-to-v2.md and asserted to still be there, produces no ink finding.
     * It is a correct design (light items, dark default text); naming it advised a dark item fill
     * under that dark text.
     */
    public function testTheDocumentedDarkFaqMigrationProducesNoInkFinding(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__) . '/docs/howto-migrate-a-faq-band-to-v2.md');
        $this->assertStringContainsString('"_band": { "background": { "fill": "#0f172a" }, "typography": { "color": "#fcfdff" } }', $doc,
            'premise: the documented map is still the one this pins');
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#0f172a'], 'typography' => ['color' => '#fcfdff']]],
            'faq', ['title' => 'F', 'items' => [['question' => 'Q?', 'answer' => '<p>A</p>']]]);
        $this->assertSame([], $found);
    }

    /** An authored base fill outranks a default's narrower tier (the authored rule prints later). */
    public function testAnAuthoredBaseFillCoversADefaultNarrowTier(): void
    {
        $found = $this->only(pp_udc_composition_findings([[
            'component' => 'nav', 'id' => 'nav',
            'udc'       => ['_band' => ['background' => ['fill' => '#101828']],
                            'menu' => ['typography' => ['color' => '#f7f8fa'], 'background' => ['fill' => '#101828']]],
            'props'     => [],
        ]]));
        $this->assertSame([], $found);
    }

    /** Several states: each is named with its own widths, joined in emission order. */
    public function testSeveralStatesAreNamedTogether(): void
    {
        [, $found] = $this->write(['_band' => ['background' => ['fill' => '#101828']],
            'eyebrow' => ['typography' => ['color' => '#f7f8fa', ':hover' => ['color' => '#ffffff']], 'background' => ['fill' => ['p' => '#101828']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString(
            '(@color-surface-accent) at rest at the desktop and tablet widths, and in the :hover state at the desktop and tablet widths, and the band background',
            $found[0]['message']
        );
    }

    /**
     * On a scrimmed band the overlay tier re-lights the accents (#1010); the finding path runs with the
     * marker and still names an author ink over a default pill (hero eyebrow). The re-lit accent is
     * an ink from the overlay tier, never the author's, so it is never named here.
     */
    public function testAScrimmedBandRunsWithTheOverlayTierAndNamesOnlyAuthorInks(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        $band = ['component' => 'hero', 'id' => 'pp-a1b2c3d4', 'props' => ['title' => 'T', 'title_accent' => 'T', 'eyebrow' => 'E'],
            'udc' => ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(6,10,28,0.72)']],
                      'eyebrow' => ['typography' => ['color' => '#ffffff']]]];
        $this->assertTrue(pp_udc_band_has_overlay($band), 'premise: the band is marked');
        $found = $this->only(pp_udc_composition_findings([$band]));
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "eyebrow"', $found[0]['message']);
    }

    /**
     * The overlay tier can only supply an INK today: every `overlay_defaults` declares typography
     * alone. The arm counts an overlay-tier SURFACE as a default surface; that branch has no shipped
     * subject, so a schema that gives an overlay default a background must be looked at (and tested).
     */
    public function testNoOverlayDefaultDeclaresABackgroundYet(): void
    {
        $checked = 0;
        foreach (array_keys(pp_composable_components()) as $component) {
            foreach (pp_udc_component_roles((string) $component) as $role => $definition) {
                if (!is_array($definition['overlay_defaults'] ?? null)) {
                    continue;
                }
                $checked++;
                $this->assertArrayNotHasKey('background', $definition['overlay_defaults'], $component . ' ' . $role);
            }
        }
        $this->assertGreaterThanOrEqual(5, $checked, 'vacuity floor: the shipped overlay roles were read');
    }

    /** A malformed stored row (raw meta, restore) degrades: nothing throws, and later bands still report. */
    public function testMalformedStoredPropsDegradeWithoutLosingLaterBands(): void
    {
        foreach (['junk', ['items' => 'junk'], ['items' => [1, 'x', null]]] as $props) {
            $found = $this->only(pp_udc_composition_findings([
                ['component' => 'grid', 'id' => 'pp-a1b2c3d4', 'props' => $props,
                 'udc' => ['_band' => ['background' => ['fill' => '#101828']], 'step-number' => ['typography' => ['color' => '#101828']]]],
                ['component' => 'section', 'id' => 'pp-a1b2c3d5', 'props' => ['eyebrow' => 'E', 'title' => 'T'],
                 'udc' => ['_band' => ['background' => ['fill' => '#101828']], 'eyebrow' => ['typography' => ['color' => '#fff']]]],
            ]));
            // The malformed grid renders no step-number (no cards): nothing of it is named, and the
            // later band still reports.
            $this->assertSame([1], array_column($found, 'index'), json_encode($props));
        }
    }

    // ── Cards (item grain) ───────────────────────────────────────────────────────────────

    /**
     * A steps grid: `step-number` is the item role that ships its own surface AND renders its own text
     * (text_content, measured). The card ROOT is a container whose text roles set their own colour, so
     * item-grain behaviour is pinned on the badge, not on the card (ruling A).
     */
    private function gridFindings(array $band_udc, array $item_udcs, bool $dark = true): array
    {
        $items = [];
        foreach ($item_udcs as $n => $udc) {
            $items[] = ['id' => sprintf('it-0000ab%02d', $n), 'number' => (string) ($n + 1), 'title' => 'T' . $n] + ($udc === null ? [] : ['udc' => $udc]);
        }
        return $this->gridFindingsWithItems($band_udc, $items, $dark);
    }

    private function gridFindingsWithItems(array $band_udc, array $items, bool $dark = true): array
    {
        return $this->only(pp_udc_composition_findings([[
            'component' => 'grid', 'id' => 'pp-a1b2c3d4',
            'udc'       => ($dark ? ['_band' => ['background' => ['fill' => '@color-bg-inverted']]] : []) + $band_udc,
            'props'     => ['layout' => 'steps', 'title' => 'G', 'items' => $items],
        ]]));
    }

    /** One card, two grains: the band's `card` fill covers a per-card ink. */
    public function testABandGrainFillCoversAnItemGrainInk(): void
    {
        $this->assertSame([], $this->gridFindings(
            ['step-number' => ['background' => ['fill' => '#1d2939']]],
            [['step-number' => ['typography' => ['color' => '#ffffff']]]]
        ));
        $this->assertCount(1, $this->gridFindings([], [['step-number' => ['typography' => ['color' => '#ffffff']]]]),
            'premise: the same ink with no fill at either grain is disclosed');
    }

    /** The band's `card` ink is covered only where a card sets its own fill. */
    public function testABandGrainInkIsCoveredOnlyWhereACardSetsItsFill(): void
    {
        $filled = ['step-number' => ['background' => ['fill' => '#1d2939']]];
        $ink    = ['step-number' => ['typography' => ['color' => '#ffffff']]];
        $this->assertSame([], $this->gridFindings($ink, [$filled, $filled]));
        $one = $this->gridFindings($ink, [$filled, null]);
        $this->assertCount(1, $one, 'the second card keeps its own light surface under the band ink');
        $this->assertStringNotContainsString('item "', $one[0]['message'], 'a band-grain finding');
    }

    /** A card whose id the emitter cannot use renders without its map, so its fill covers nothing. */
    public function testACardTheEmitterCannotAddressDoesNotCoverTheBandInk(): void
    {
        $found = $this->gridFindingsWithItems(['step-number' => ['typography' => ['color' => '#ffffff']]], [
            ['id' => 'it-0000ab01', 'title' => 'A', 'udc' => ['step-number' => ['background' => ['fill' => '#1d2939']]]],
            ['id' => 'not an id', 'title' => 'B', 'udc' => ['step-number' => ['background' => ['fill' => '#1d2939']]]],
        ]);
        $this->assertCount(1, $found);
    }

    /**
     * INVERTED BY RULING E1-A: an item role with no cards at all renders no element, so there is nothing
     * to name (it was named when the finding read the schema's roles, not the rendered band).
     */
    public function testAnItemRoleWithNoCardsRendersNothingToName(): void
    {
        $this->assertSame([], $this->gridFindingsWithItems(['step-number' => ['typography' => ['color' => '#ffffff']]], []));
    }

    /** A per-card ink whose own card map also sets the fill is covered at its own grain. */
    public function testAnItemMapCoveringItsOwnFillIsSilent(): void
    {
        $this->assertSame([], $this->gridFindings([], [['step-number' => ['typography' => ['color' => '#ffffff'],
            'background' => ['fill' => '#1d2939']]]]));
    }

    /**
     * A card whose own map says nothing about the role is the SAME element as a card with no map: a
     * band-level clash is reported once, band-grain, not once per card (performance pass, cycle 1: 20
     * copies were reported, and at 200 cards one band would spend the whole shared budget).
     */
    public function testACardWithAnUnrelatedMapDoesNotCopyTheBandFinding(): void
    {
        $unrelated = array_fill(0, 20, ['card-text' => ['typography' => ['size' => '1rem']]]);
        $found = $this->gridFindings(['step-number' => ['typography' => ['color' => '#ffffff']]], $unrelated);
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString('item "', $found[0]['message'], 'a band-grain finding');
    }

    /** A card map speaks only for item roles: a band-only role there paints nothing and is not reported. */
    public function testABandOnlyRoleInACardMapIsNotReported(): void
    {
        $this->assertSame([], $this->gridFindingsWithItems([], [['id' => 'it-0000abcd', 'title' => 'One',
            'udc' => ['eyebrow' => ['typography' => ['color' => '@color-bg']]]]]));
    }

    /** Item grain through the real surface: named with the card's locator (the steps badge, measured text). */
    public function testACardInkOverTheCardsOwnSurfaceIsDisclosedWithTheItemLocator(): void
    {
        $id = pp_create_page('ink card', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'grid',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']]],
            'props'     => ['layout' => 'steps', 'title' => 'G', 'items' => [['number' => '1', 'title' => 'One',
                'udc' => ['step-number' => ['typography' => ['color' => '#101828']]]]]],
        ]]]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $found = $this->only($result['findings'] ?? []);
        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];

        $this->assertCount(1, $found);
        $this->assertStringContainsString('item "' . $item_id . '"', $found[0]['message']);
        $this->assertStringContainsString('role "step-number"', $found[0]['message']);
    }

    // ── Channels, prompt, bound ──────────────────────────────────────────────────────────

    /**
     * INVERTED BY RULING A: the chrome write runs the same arm, and no shipped header or footer role both
     * ships its own fill and renders its own text (nav `submenu` / `menu` are containers whose links set
     * their own colour, measured), so a submenu ink is not named. The channel still carries findings
     * with no band offset; the band-grain tests pin the arm itself.
     */
    public function testAChromeWriteDoesNotNameAContainerRole(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => 'pp_site_udc',
            'value' => json_encode(['nav' => ['_band' => ['background' => ['fill' => '#101828']],
                'submenu' => ['typography' => ['color' => '#f7f8fa']]]]),
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame([], $this->only($result['findings'] ?? []));
    }

    /** The runtime prompt names the finding on the band and the chrome channels. */
    public function testTheRuntimePromptNamesTheFinding(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertSame(2, substr_count($prompt, '`udc_role_ink_over_own_surface`'), 'dark-band paragraph and chrome paragraph');
    }

    /** A band without a usable id renders no band CSS, so nothing of the author's paints: no finding. */
    public function testABandWithNoUsableIdIsNotProbed(): void
    {
        $this->assertSame([], $this->only(pp_udc_composition_findings([[
            'component' => 'section', 'id' => 'not an id',
            'udc'       => ['_band' => ['background' => ['fill' => '#101828']], 'eyebrow' => ['typography' => ['color' => '#ffffff']]],
            'props'     => ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b'],
        ]])));
    }

    /**
     * LINEAR IN THE CARD COUNT (security pass, cycle 1). `items` declares no maximum and the findings
     * run on restore and check page with no size gate; ranking every card's rows for every card was
     * quadratic (15.6 s measured at 2,400 cards). 2,000 styled cards must finish well inside the bound;
     * the quadratic took about 11 s here. Generous on purpose: this catches a complexity class, not noise.
     */
    public function testTheCostIsLinearInTheCardCount(): void
    {
        $items = [];
        for ($c = 0; $c < 2000; $c++) {
            $items[] = ['id' => sprintf('it-%08x', $c + 1), 'title' => 'T', 'udc' => [
                'card-title' => ['typography' => ['color' => '#fff', ':hover' => ['color' => '#eee'], ':focus-visible' => ['color' => '#ddd']]],
                'card-link'  => ['typography' => ['color' => '#ccc']],
            ]];
        }
        $start = hrtime(true);
        pp_udc_composition_findings([['component' => 'grid', 'id' => 'pp-00000001',
            'udc' => ['_band' => ['background' => ['fill' => '#101828']]], 'props' => ['title' => 'G', 'items' => $items]]]);
        $this->assertLessThan(3.0, (hrtime(true) - $start) / 1e9, 'the own-surface walk must stay linear in the card count');
    }

    /** Bounded across the composition like its sibling arms, including inside one band. */
    public function testTheFindingIsBoundedAcrossTheComposition(): void
    {
        $bands = [];
        // Two own-surface roles per band, so the cap must hold INSIDE a band too; one
        // single-role band first makes the count odd, so it reaches 199 before a two-role band.
        $bands[] = ['component' => 'section', 'id' => 'pp-0000ffff',
            'udc'   => ['_band' => ['background' => ['fill' => '@color-bg-inverted']], 'eyebrow' => ['typography' => ['color' => '@color-bg']]],
            'props' => ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b', 'layout' => 'text-panel', 'panel_body' => 'Panel text']];
        for ($b = 0; $b < 150; $b++) {
            $bands[] = ['component' => 'section', 'id' => sprintf('pp-%08x', $b + 1),
                'udc'   => ['_band' => ['background' => ['fill' => '@color-bg-inverted']],
                            'eyebrow' => ['typography' => ['color' => '@color-bg']],
                            'panel'   => ['typography' => ['color' => '@color-bg']]],
                'props' => ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b', 'layout' => 'text-panel', 'panel_body' => 'Panel text']];
        }
        $count = count($this->only(pp_udc_composition_findings($bands)));
        $this->assertSame(PP_UDC_MAX_EMIT_DROPS, $count, 'capped exactly at the shared bound');
    }
}
