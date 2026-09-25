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
        $found = $this->only(pp_udc_composition_findings([[
            'component' => 'grid', 'id' => 'pp-a1b2c3d4',
            'udc'       => ['_band' => ['background' => ['_preset' => 'probe-dark']], 'card' => ['typography' => ['color' => '#ffffff']]],
            'props'     => ['title' => 'G', 'items' => [['id' => 'it-0000ab01', 'title' => 'A']]],
        ]]));
        $this->assertCount(1, $found);
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
     * paint on one: the default outranks it. Pinned on a card at band grain too, so the
     * missed-trap fix is not an eyebrow special case.
     */
    public function testAShadowedPresetFillOnACardDoesNotCoverIt(): void
    {
        $this->savePreset('card-dark', 'background', ['fill' => '#1d2939']);
        $found = $this->gridFindings(['card' => ['background' => ['_preset' => 'card-dark'], 'typography' => ['color' => '#ffffff']]], [null]);
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
            'props'     => ['title' => 'T', 'body' => 'b'],
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
            ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x']
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

    /** An ink a preset supplies, on a role whose colour is NOT defaulted, paints and is disclosed. */
    public function testAPaintingPresetInkIsDisclosed(): void
    {
        $this->savePreset('ink-role', 'role', ['typography' => ['color' => '#ffffff']]);
        $found = $this->gridFindings(['card' => ['_preset' => 'ink-role']], [null]);
        $this->assertCount(1, $found, 'grid card defaults a fill and no colour, so the preset ink paints');
        $this->assertStringContainsString('role "card"', $found[0]['message']);
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
             'button-secondary' => ['typography' => [':hover' => ['color' => '@color-bg']]]],
            'cta',
            ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x']
        );
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "button-secondary"', $found[0]['message']);
        $this->assertStringContainsString('(@color-accent) in the :hover state, on any text', $found[0]['message'], 'light only while hovered, at every width');
    }

    /**
     * The advice says WHERE the fill goes, and following it literally clears the finding (api-contract
     * pass, cycle 1: a resting fill cannot cover a default :hover fill, 0,2,0 against 0,3,0, and a
     * model told only "set background.fill" loops).
     */
    public function testFollowingTheStateAdviceLiterallyClearsTheFinding(): void
    {
        $props = ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x'];
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
        $nav = static fn (array $menu): array => [[
            'component' => 'nav', 'id' => 'nav', 'props' => [],
            'udc' => ['_band' => ['background' => ['fill' => '#101828']], 'menu' => $menu],
        ]];
        $found = $this->only(pp_udc_composition_findings($nav(['typography' => ['color' => '#f7f8fa']])));
        $this->assertCount(1, $found);
        $this->assertStringContainsString('a breakpoint map, e.g. {"p": ...}', $found[0]['message']);
        $this->assertSame([], $this->only(pp_udc_composition_findings($nav(['typography' => ['color' => '#f7f8fa'],
            'background' => ['fill' => ['d' => 'transparent', 'p' => '#101828']]]))));
    }

    /**
     * States COMBINE in the browser (design pass, cycle 1): a mouse press is :active AND :hover, a
     * focused control under the pointer is :focus-visible AND :hover. An author :active ink therefore
     * lands on the DEFAULT :hover fill while pressed (measured rgb(255,245,160) on rgb(49,87,244)).
     * The advice names the author's own state: an :active fill prints after the default :hover fill.
     */
    public function testAnInkInACombinedStateIsNamed(): void
    {
        $props = ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x'];
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
        $found = $this->gridFindings(['card' => ['background' => [':hover' => ['fill' => '#1d2939']]]],
            [['card' => ['typography' => ['color' => '#ffffff']]]]);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('(@color-bg) at rest, on any text', $found[0]['message']);
        $this->assertStringContainsString('Text roles inside this one that set their own colour keep it', $found[0]['message'],
            'a container role: its own ink does not reach text that sets its own colour');
    }

    // ── An ink the role INHERITS from the band (ruling D3 = A) ────────────────────────────

    private const BAND_INK = 'the text colour you set on the whole band (_band typography.color) reaches this role';

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

    /** A card part under a card root that sets its own colour takes the CARD's ink, not the band's: not blamed on the band. */
    public function testACardPartUnderAnInkedCardRootIsNotBlamedOnTheBand(): void
    {
        $found = $this->only(pp_udc_composition_findings([[
            'component' => 'grid', 'id' => 'pp-a1b2c3d4',
            'udc'       => ['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]],
            'props'     => ['title' => 'G', 'items' => [['id' => 'it-0000ab01', 'title' => 'A', 'bar' => true,
                'udc' => ['card' => ['typography' => ['color' => '#f7f8fa']]]]]],
        ]]));
        foreach ($found as $f) {
            $this->assertFalse(str_contains($f['message'], 'role "card-bar"') && str_contains($f['message'], self::BAND_INK),
                'card-bar sits in a card that sets its own colour');
        }
    }

    /**
     * THE TWO FINDINGS NEVER CONTRADICT (D3 condition 2), asserted over every shape here: a role this
     * finding names as reached by the band's ink is never one the sibling names as NOT reached.
     */
    public function testTheBandInkNeverContradictsTheSiblingDisclosure(): void
    {
        $band = ['_band' => ['background' => ['fill' => '#101828'], 'typography' => ['color' => '#ffffff']]];
        $shapes = [
            ['hero', ['layout' => 'split', 'title' => 'H', 'proof' => '<p>P</p>', 'eyebrow' => 'E']],
            ['section', ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b']],
            ['faq', ['title' => 'F', 'items' => [['question' => 'Q?', 'answer' => '<p>A</p>']]]],
            ['grid', ['title' => 'G', 'items' => [['title' => 'T', 'text' => 'x', 'bar' => true]]]],
            ['table', ['title' => 'T', 'headers' => ['A'], 'rows' => [['1']]]],
            ['testimonials', ['items' => [['quote' => 'Q', 'author' => 'A']]]],
            ['cta', ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x', 'eyebrow' => 'E']],
        ];
        $named = 0;
        foreach ($shapes as [$component, $props]) {
            $all = pp_udc_composition_findings([['component' => $component, 'id' => 'pp-a1b2c3d4', 'udc' => $band, 'props' => $props]]);
            $not_reached = [];
            foreach ($all as $f) {
                if ($f['type'] === 'udc_band_value_shadowed_by_role_default' && str_contains($f['message'], '"color"')) {
                    preg_match_all('/\b([a-z][a-z0-9-]*)\b/', substr($f['message'], (int) strpos($f['message'], 'does not reach')), $m);
                    $not_reached = array_merge($not_reached, $m[1]);
                }
            }
            foreach ($this->only($all) as $f) {
                if (!str_contains($f['message'], self::BAND_INK)) {
                    continue;
                }
                $named++;
                preg_match('/role "([^"]+)"/', $f['message'], $role);
                $this->assertNotContains($role[1], $not_reached, $component . ': the sibling says the band colour does not reach ' . $role[1]);
            }
        }
        $this->assertGreaterThanOrEqual(5, $named, 'vacuity floor: the band-ink wording fired across the shapes');
    }

    // ── Widths ───────────────────────────────────────────────────────────────────────────

    /** A surface that exists at one width only is named with that width (nav `menu`: phone only). */
    public function testATierOnlySurfaceIsNamedWithItsWidth(): void
    {
        $found = $this->only(pp_udc_composition_findings([[
            'component' => 'nav', 'id' => 'nav',
            'udc'       => ['_band' => ['background' => ['fill' => '#101828']], 'menu' => ['typography' => ['color' => '#f7f8fa']]],
            'props'     => [],
        ]]));
        $this->assertCount(1, $found);
        $this->assertStringContainsString('(@color-bg)', $found[0]['message']);
        $this->assertStringContainsString('(@color-bg) at the phone width, on any text', $found[0]['message']);
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

    /** Several states: each is named with its own widths, joined in emission order, and the surface named is the first that fires. */
    public function testSeveralStatesAreNamedTogether(): void
    {
        $found = $this->only(pp_udc_composition_findings([[
            'component' => 'nav', 'id' => 'nav',
            'udc'       => ['_band' => ['background' => ['fill' => '#101828']],
                            'menu' => ['typography' => ['color' => '#f7f8fa', ':hover' => ['color' => '#ffffff']]]],
            'props'     => [],
        ]]));
        $this->assertCount(1, $found);
        $this->assertStringContainsString(
            '(@color-bg) at rest at the phone width, and in the :hover state at the phone width, on any text',
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
        $band = ['component' => 'hero', 'id' => 'pp-a1b2c3d4', 'props' => ['title' => 'T', 'title_accent' => 'T'],
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
                 'udc' => ['_band' => ['background' => ['fill' => '#101828']], 'card' => ['typography' => ['color' => '#fff']]]],
                ['component' => 'section', 'id' => 'pp-a1b2c3d5', 'props' => [],
                 'udc' => ['_band' => ['background' => ['fill' => '#101828']], 'eyebrow' => ['typography' => ['color' => '#fff']]]],
            ]));
            $this->assertSame([0, 1], array_column($found, 'index'), json_encode($props));
        }
    }

    // ── Cards (item grain) ───────────────────────────────────────────────────────────────

    private function gridFindings(array $band_udc, array $item_udcs): array
    {
        $items = [];
        foreach ($item_udcs as $n => $udc) {
            $items[] = ['id' => sprintf('it-0000ab%02d', $n), 'title' => 'T' . $n] + ($udc === null ? [] : ['udc' => $udc]);
        }
        return $this->gridFindingsWithItems($band_udc, $items);
    }

    private function gridFindingsWithItems(array $band_udc, array $items): array
    {
        return $this->only(pp_udc_composition_findings([[
            'component' => 'grid', 'id' => 'pp-a1b2c3d4',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']]] + $band_udc,
            'props'     => ['title' => 'G', 'items' => $items],
        ]]));
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

    /** The band's `card` ink is covered only where a card sets its own fill. */
    public function testABandGrainInkIsCoveredOnlyWhereACardSetsItsFill(): void
    {
        $filled = ['card' => ['background' => ['fill' => '#1d2939']]];
        $ink    = ['card' => ['typography' => ['color' => '#ffffff']]];
        $this->assertSame([], $this->gridFindings($ink, [$filled, $filled]));
        $one = $this->gridFindings($ink, [$filled, null]);
        $this->assertCount(1, $one, 'the second card keeps its own light surface under the band ink');
        $this->assertStringNotContainsString('item "', $one[0]['message'], 'a band-grain finding');
    }

    /** A card whose id the emitter cannot use renders without its map, so its fill covers nothing. */
    public function testACardTheEmitterCannotAddressDoesNotCoverTheBandInk(): void
    {
        $found = $this->gridFindingsWithItems(['card' => ['typography' => ['color' => '#ffffff']]], [
            ['id' => 'it-0000ab01', 'title' => 'A', 'udc' => ['card' => ['background' => ['fill' => '#1d2939']]]],
            ['id' => 'not an id', 'title' => 'B', 'udc' => ['card' => ['background' => ['fill' => '#1d2939']]]],
        ]);
        $this->assertCount(1, $found);
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

    /**
     * A card whose own map says nothing about the role is the SAME element as a card with no map: a
     * band-level clash is reported once, band-grain, not once per card (performance pass, cycle 1: 20
     * copies were reported, and at 200 cards one band would spend the whole shared budget).
     */
    public function testACardWithAnUnrelatedMapDoesNotCopyTheBandFinding(): void
    {
        $unrelated = array_fill(0, 20, ['card-text' => ['typography' => ['size' => '1rem']]]);
        $found = $this->gridFindings(['card' => ['typography' => ['color' => '#ffffff']]], $unrelated);
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString('item "', $found[0]['message'], 'a band-grain finding');
    }

    /** A card map speaks only for item roles: a band-only role there paints nothing and is not reported. */
    public function testABandOnlyRoleInACardMapIsNotReported(): void
    {
        $this->assertSame([], $this->gridFindingsWithItems([], [['id' => 'it-0000abcd', 'title' => 'One',
            'udc' => ['eyebrow' => ['typography' => ['color' => '@color-bg']]]]]));
    }

    /** Item grain through the real surface: named with the card's locator. */
    public function testACardInkOverTheCardsOwnSurfaceIsDisclosedWithTheItemLocator(): void
    {
        $id = pp_create_page('ink card', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'grid',
            'udc'       => ['_band' => ['background' => ['fill' => '@color-bg-inverted']]],
            'props'     => ['title' => 'G', 'items' => [['title' => 'One', 'udc' => ['card' => ['typography' => ['color' => '@color-bg']]]]]],
        ]]]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $found = $this->only($result['findings'] ?? []);
        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];

        $this->assertCount(1, $found);
        $this->assertStringContainsString('item "' . $item_id . '"', $found[0]['message']);
        $this->assertStringContainsString('role "card"', $found[0]['message']);
    }

    // ── Channels, prompt, bound ──────────────────────────────────────────────────────────

    /** The chrome write envelope carries it too, with no band offset. */
    public function testAChromeWriteDisclosesItWithNoIndex(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => 'pp_site_udc',
            'value' => json_encode(['nav' => ['_band' => ['background' => ['fill' => '#101828']],
                'submenu' => ['typography' => ['color' => '#f7f8fa']]]]),
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $found = $this->only($result['findings'] ?? []);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('Component "nav"', $found[0]['message']);
        $this->assertStringContainsString('role "submenu"', $found[0]['message']);
        $this->assertStringContainsString('(@color-surface) at the desktop and tablet widths, on any text', $found[0]['message'], 'submenu is transparent on phones');
        $this->assertNull($found[0]['index']);
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
            'props' => ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b']];
        for ($b = 0; $b < 150; $b++) {
            $bands[] = ['component' => 'section', 'id' => sprintf('pp-%08x', $b + 1),
                'udc'   => ['_band' => ['background' => ['fill' => '@color-bg-inverted']],
                            'eyebrow' => ['typography' => ['color' => '@color-bg']],
                            'panel'   => ['typography' => ['color' => '@color-bg']]],
                'props' => ['eyebrow' => 'E', 'title' => 'T', 'body' => 'b']];
        }
        $count = count($this->only(pp_udc_composition_findings($bands)));
        $this->assertSame(PP_UDC_MAX_EMIT_DROPS, $count, 'capped exactly at the shared bound');
    }
}
