<?php
/**
 * tests/UdcLayoutGroupTest.php
 *
 * THE LAYOUT GROUP, as executable evidence (#1084, docs/v2/LAYOUT-GROUP-CONTRACT.md).
 *
 * The group is an OVERLAY: five layout properties stay in the stylesheet, where
 * ~35 of their shipped declarations are variant- or tier-scoped mechanism no role
 * address can express, and the group emits AUTHORED values above them. That shape
 * makes three claims this file has to keep honest, because nothing else can:
 *
 *   1. NO ROLE DECLARES A LAYOUT DEFAULT. A default emits unlayered, so it would
 *      not replace `.cta--inline .cta__inner { flex-direction: row }` — it would
 *      OUTRANK it, and the variant would die silently on every page carrying it.
 *      The test is derived from the registry, so a sixth layout parameter added
 *      tomorrow is covered without anyone remembering this file exists.
 *   2. AN AUTHORED COLUMN COUNT IS HONEST AT EVERY TIER. `grid-template-columns`
 *      does nothing to a flex container, and `.section__grid` is flex below 768px,
 *      so the engine emits `display: grid` beside it. Without that the value
 *      validates, stores and paints nothing on phones — and PHP cannot read the
 *      stylesheet, so not even the drop ledger could say so (I19/I35).
 *   3. THE COUNT SURVIVES MINTING. A responsive value is rewritten into band
 *      tokens at write, so the synthesis has to decide from the author's LITERAL
 *      while emitting the reference. Deciding from the CSS text would see
 *      `var(--pp-…)`, skip the synthesis, and ship a bare custom property as a
 *      track list — on exactly the maps most likely to be responsive.
 *
 * TIERS ARE READ FROM THE ENGINE, never hardcoded (#1052): the three shipped
 * RoleDefaultsEmit harnesses each hardcode the media text, so a moved breakpoint
 * asserts the wrong tier and still passes. This file asks pp_udc_breakpoints().
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;

class UdcLayoutGroupTest extends TestCase
{
    /** A section band, the component whose own CSS switches display across tiers. */
    private function band(array $udc, string $id = 'pp-1a2b3c4d'): array
    {
        return [
            'component' => 'section',
            'id'        => $id,
            'props'     => ['title' => 'Tracks', 'body' => 'Copy.'],
            'udc'       => $udc,
        ];
    }

    /** The emitted CSS for one authored band. */
    private function css(array $udc): string
    {
        $css = pp_udc_band_css($this->band($udc));
        $this->assertNotSame('', $css, 'the band emitted nothing at all');
        return $css;
    }

    /** The slice of an emitted block for one breakpoint key, media text from the ENGINE. */
    private function tier(string $css, string $bp): string
    {
        $breakpoints = pp_udc_breakpoints();
        $this->assertArrayHasKey($bp, $breakpoints, 'unknown breakpoint key in the test itself');
        $media = $breakpoints[$bp]['media'];
        if ($media === null) {
            $at = strpos($css, '@media');
            return $at === false ? $css : substr($css, 0, $at);
        }
        $quoted = preg_quote('@media ' . $media . '{', '/');
        if (!preg_match('/' . $quoted . '(.*?)\}\s*(?:@media|$)/s', $css, $m)) {
            $this->fail(sprintf('no %s tier emitted; css was: %s', $bp, $css));
        }
        return $m[1];
    }

    // ── 1. The overlay's non-negotiable: no defaults ─────────────────────────

    /**
     * REGISTRY-DERIVED, and non-vacuous by its own assertions: it proves it found
     * the layout parameters, found real components, and found real defaults of
     * OTHER groups — so a walk that silently stopped matching cannot pass.
     */
    public function testNoRoleAnywhereDeclaresALayoutDefault(): void
    {
        $groups       = pp_udc_groups();
        $layoutParams = array_keys($groups['layout']['params']);
        // SIX PROPERTIES, NOT FIVE. `align-self` lives in `sizing` (a box placing
        // ITSELF is that group's subject) and is in exactly the same position as the
        // layout five: structural in the stylesheet, owned by the registry, defaulted
        // nowhere. A `sizing.align-self` default would emit unlayered and outrank
        // `.hero--centered .hero__eyebrow { align-self: center }`, so it is guarded
        // here rather than left out because of which group happens to carry it.
        $layoutProperties = array_merge(
            array_column($groups['layout']['params'], 'property'),
            [$groups['sizing']['params']['align-self']['property']]
        );
        $this->assertNotEmpty($layoutParams, 'the registry has no layout group to check');
        $this->assertContains('grid-template-columns', $layoutProperties, 'the property list is not the one this test thinks it is');

        $componentsSeen = 0;
        $rolesExposing  = 0;
        $otherDefaults  = 0;

        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            if (!is_array($schema) || empty($schema['roles'])) {
                continue;
            }
            $componentsSeen++;
            foreach ($schema['roles'] as $role => $definition) {
                if (in_array('layout', $definition['groups'] ?? [], true)) {
                    $rolesExposing++;
                }
                $defaults = $definition['defaults'] ?? [];
                $otherDefaults += count($defaults);
                $this->assertArrayNotHasKey('layout', $defaults, sprintf(
                    '%s role "%s" declares a layout default. A role default emits UNLAYERED, so it '
                    . 'would outrank the variant-scoped structural rule it appears to replace '
                    . '(.cta--inline, .hero--split, .section--text-panel) and kill that layout on '
                    . 'every band using it. The Layout group is authored-only by ruling.',
                    basename(dirname($file)),
                    $role
                ));
                // The same rule one level down, and it is keyed on the emitted
                // PROPERTY rather than the parameter name — because names are
                // group-scoped and two groups legitimately share one. `typography
                // .align` emits `text-align` and has defaulted on `logos.label`
                // since that rebuild; `layout.align` emits `align-items`. A
                // name-keyed check calls the first one a smuggled layout default,
                // which is how this guard was found to be asking the wrong
                // question. What must never appear in a default is a
                // layout PROPERTY, whatever group carries it.
                $hits = $this->layoutPropertyDefaultsIn($defaults, $groups, $layoutProperties);
                $this->assertSame([], $hits, sprintf(
                    '%s role "%s" defaults %s, which emits a layout property',
                    basename(dirname($file)),
                    $role,
                    implode(', ', $hits)
                ));
            }
        }

        $this->assertGreaterThanOrEqual(9, $componentsSeen, 'the schema walk found almost no components — it is asserting on an empty set');
        $this->assertGreaterThan(20, $rolesExposing, 'almost no role exposes layout — the roster never landed, so this test proves nothing');
        $this->assertGreaterThan(50, $otherDefaults, 'no role defaults were read at all, so the absence above is not evidence');
    }

    /**
     * Every layout PROPERTY a defaults map declares, including through a state
     * bucket — the level the first version of this walk stepped straight over.
     *
     * A role's defaults nest a state exactly as an authored map does
     * (`typography: {":hover": {color: …}}` ships on cta, nav and footer today), so
     * a walk that read one level under a group would have accepted
     * `sizing: {":hover": {"align-self": "center"}}` — an unlayered default on a
     * state, which is the same cascade hazard as the base one and is writable
     * today (probed: that shape validates at the write gate). Keyed on the emitted
     * property rather than the parameter name, because `typography.align` and
     * `layout.align` are different properties sharing a name.
     *
     * @return array<int, string> Human-readable locators, empty when clean.
     */
    private function layoutPropertyDefaultsIn(array $defaults, array $groups, array $layoutProperties): array
    {
        $hits = [];
        foreach ($defaults as $group => $values) {
            if (!is_array($values) || !isset($groups[$group]['params'])) {
                continue;
            }
            foreach ($values as $key => $inner) {
                $isState = is_string($key) && $key !== '' && $key[0] === ':' && is_array($inner);
                foreach ($isState ? array_keys($inner) : [$key] as $param) {
                    $property = $groups[$group]['params'][$param]['property'] ?? null;
                    if ($property !== null && in_array($property, $layoutProperties, true)) {
                        $hits[] = $isState
                            ? sprintf('%s.%s.%s', $group, $key, $param)
                            : sprintf('%s.%s', $group, $param);
                    }
                }
            }
        }
        return $hits;
    }

    /**
     * THE WALK'S OWN RED PROOF. A guard whose assertion count does not move when
     * you break its subject is asserting on an empty set, and the shipped schemas
     * are (correctly) clean — so the only way to prove the state arm fires is to
     * feed it the shape it exists for.
     */
    public function testTheNoDefaultsWalkSeesThroughAStateBucket(): void
    {
        $groups     = pp_udc_groups();
        $properties = array_merge(
            array_column($groups['layout']['params'], 'property'),
            [$groups['sizing']['params']['align-self']['property']]
        );

        $this->assertSame(
            ['sizing.:hover.align-self'],
            $this->layoutPropertyDefaultsIn(['sizing' => [':hover' => ['align-self' => 'center']]], $groups, $properties),
            'a state-nested layout default must be caught, not stepped over'
        );
        $this->assertSame(
            ['layout.columns'],
            $this->layoutPropertyDefaultsIn(['layout' => ['columns' => 3]], $groups, $properties),
            'and the plain arm still fires'
        );
        // The near miss: a name a layout param shares with another group's, whose
        // property is NOT a layout one, must stay clean.
        $this->assertSame(
            [],
            $this->layoutPropertyDefaultsIn(['typography' => ['align' => 'center', ':hover' => ['align' => 'left']]], $groups, $properties),
            'typography.align emits text-align and is a legitimate default, in a state or out of one'
        );
    }

    /**
     * THE TWO ENDS OF THE AMENDMENT CANNOT DRIFT APART.
     *
     * The css-lint amendment's condition 1 is "the registry owns it", and the lint
     * expresses that as a hand-written literal in JavaScript
     * (`REGISTRY_OWNED_STRUCTURAL`). Nothing compared it to the registry, so a
     * seventh layout parameter claiming a new property — or `align-self` moving
     * group — would leave the JS list stale and the amendment's own condition
     * unasserted for the new property. That is the opposite of the registry-derived
     * posture the no-defaults test takes for the same rule, and the pre-landing
     * testing pass flagged the asymmetry.
     *
     * PHP owns the registry, so PHP is where the comparison belongs: it reads the
     * lint's literal and asserts the two sets are the same. A new layout parameter
     * now fails here until the lint is updated in the same commit.
     */
    public function testTheLintsDualHomeSetIsExactlyWhatTheRegistryOwns(): void
    {
        $lint = (string) file_get_contents(dirname(__DIR__) . '/tests/js/css-lint.test.js');
        $this->assertNotSame('', $lint, 'the lint file must be readable, or this test proves nothing');

        $ok = preg_match(
            '/const REGISTRY_OWNED_STRUCTURAL = new Set\(\[(.*?)\]\);/s',
            $lint,
            $m
        );
        $this->assertSame(1, $ok, 'REGISTRY_OWNED_STRUCTURAL was renamed or removed — update this pin with it');

        preg_match_all("/'([a-z-]+)'/", $m[1], $found);
        $declared = $found[1];
        sort($declared);

        $groups   = pp_udc_groups();
        $expected = array_merge(
            array_column($groups['layout']['params'], 'property'),
            [$groups['sizing']['params']['align-self']['property']]
        );
        sort($expected);

        $this->assertSame($expected, $declared,
            'the css-lint amendment names a different set of properties than the registry owns. Both ends '
            . 'of the exception move together, or the amendment stops being true for the property that drifted.');
    }

    // ── 2. Exposure is declared, and the write gate honours it ───────────────

    public function testAContainerRoleAcceptsLayoutAndANonContainerRoleRefusesItByName(): void
    {
        $this->assertNull(
            pp_udc_validate_map(['columns' => ['layout' => ['columns' => 3]]], 'section'),
            'section\'s `columns` role is the two-column track; it must accept the group'
        );

        $error = pp_udc_validate_map(['heading' => ['layout' => ['justify' => 'center']]], 'section');
        $this->assertInstanceOf(WP_Error::class, $error, 'a heading arranges no children; the group must be refused there');
        $this->assertStringContainsString('layout', $error->get_error_message());
    }

    /**
     * `align-self` is a SIZING parameter, and this is the test that says why: it
     * has to reach a role whose box is a CHILD of a container and not a container
     * itself. `section.panel` is exactly that, and it is #658's subject.
     */
    public function testAlignSelfIsASizingParameterSoItReachesAChildRole(): void
    {
        $this->assertArrayHasKey('align-self', pp_udc_groups()['sizing']['params']);
        $this->assertArrayNotHasKey('align-self', pp_udc_groups()['layout']['params']);

        $this->assertNull(
            pp_udc_validate_map(['panel' => ['sizing' => ['align-self' => 'center']]], 'section'),
            '#658: the panel column must be able to place itself in the row its parent lays out'
        );
        $css = $this->css(['panel' => ['sizing' => ['align-self' => 'center']]]);
        $this->assertStringContainsString('.section__panel', $css);
        $this->assertStringContainsString('align-self:center', $css);
    }

    /**
     * THE ROSTER IS A GATE AT EMIT, NOT ONLY AT WRITE (found by the pre-landing
     * security pass, with a probe rather than a reading).
     *
     * The write gate refuses a group a role does not permit, and the emitter used
     * to place it anyway — so stored bytes that never passed a gate painted. On
     * `nav.menu` that meant an unlayered `display: grid` over the UA stylesheet's
     * `[hidden]` rule: an open mobile menu, pinned open, by a raw meta write. The
     * drop ledger was empty, so no channel said anything.
     *
     * Raw meta is the point of this fixture. A composition written before a rule
     * existed, a restore (#233, which reports without blocking) and a hand-edited
     * row all arrive at the emitter the same way.
     */
    public function testAGroupTheRoleDoesNotPermitIsRefusedAtEmitAndReported(): void
    {
        $band = [
            'component' => 'nav',
            'id'        => 'pp-1a2b3c4d',
            'props'     => [],
            'udc'       => ['menu' => ['layout' => ['columns' => '2']]],
        ];

        // The write gate's answer, for the record: this never validated.
        $this->assertInstanceOf(WP_Error::class, pp_udc_validate_map($band['udc'], 'nav'));

        $this->assertSame('', pp_udc_band_css($band),
            'a group the role does not permit must not paint, however the bytes got into storage');

        $drops = [];
        pp_udc_compile_band($band, 'authored', $drops);
        $this->assertNotEmpty($drops, 'and the drop has to reach the pre-mutation channel, not vanish');
        $this->assertStringContainsString('layout', $drops[0]['where']);
        $this->assertStringContainsString('does not permit', $drops[0]['reason']);

        // The legitimate route is untouched: same group, a role that permits it.
        $this->assertStringContainsString(
            'grid-template-columns',
            $this->css(['columns' => ['layout' => ['columns' => 3]]]),
            'the gate must refuse the unpermitted role, not the group'
        );
    }

    // ── 3. columns: the synthesis and the companion ──────────────────────────

    public function testAColumnCountBecomesATrackListWithTheOverflowSafeMinimum(): void
    {
        $css = $this->css(['columns' => ['layout' => ['columns' => 4]]]);
        $this->assertStringContainsString('grid-template-columns:repeat(4, minmax(0, 1fr))', $css);
        // `minmax(0, …)` rather than a bare 1fr: a 1fr track has an `auto` minimum,
        // so one unbroken token widens the track and scrolls the page (#1043/#1067).
        $this->assertStringNotContainsString('repeat(4, 1fr)', $css);
    }

    public function testAnAuthoredTrackListIsEmittedAsWritten(): void
    {
        $css = $this->css(['columns' => ['layout' => ['columns' => 'repeat(auto-fit, minmax(20rem, 1fr))']]]);
        $this->assertStringContainsString('grid-template-columns:repeat(auto-fit, minmax(20rem, 1fr))', $css);
        // No second repeat() wrapped around an explicit list.
        $this->assertSame(1, substr_count($css, 'repeat('), 'an explicit track list must not be re-wrapped');
    }

    public function testTheCompanionMakesAnAuthoredCountHonestOnABoxTheStylesheetLaysOutWithFlex(): void
    {
        $css = $this->css(['columns' => ['layout' => ['columns' => 3]]]);
        $this->assertStringContainsString('display:grid', $css,
            'without the companion an authored columns value paints nothing below 768px, where '
            . '.section__grid is display:flex — and no channel could report it');
    }

    public function testTheCompanionYieldsToAnAuthoredDisplayWhateverWroteIt(): void
    {
        $css = $this->css(['columns' => [
            'layout' => ['columns' => 3],
            '_css'   => ['display' => 'inline-grid'],
        ]]);
        $this->assertStringContainsString('display:inline-grid', $css);
        $this->assertStringNotContainsString('display:grid;', $css);
    }

    /**
     * THE NEGATIVE HALF OF THIS TEST USED TO ASSERT ON AN EMPTY STRING, and the
     * pre-landing testing pass caught it. With `columns` authored at `p` only, the
     * whole emitted block is one `@media (max-width: 767px)` wrapper, so the base
     * slice was `substr($css, 0, 0)` — and "the empty string does not contain
     * display:grid" is a claim no implementation could ever fail. The `d` tier now
     * carries a second layout value, which makes the base slice real and the
     * negative assertion a real one.
     */
    public function testTheCompanionRidesOnlyTheBucketThatCarriesTheColumns(): void
    {
        $css = $this->css(['columns' => ['layout' => ['columns' => ['p' => 1], 'justify' => 'center']]]);
        $base = $this->tier($css, 'd');
        $this->assertStringContainsString('justify-content:center', $base,
            'the base tier must actually be emitted, or the negative below asserts on nothing');
        $this->assertStringNotContainsString('display:grid', $base);
        $this->assertStringContainsString('display:grid', $this->tier($css, 'p'));
    }

    /**
     * A NARROWER TIER BORROWS THE BASE TIER'S COMPANION instead of repeating it.
     *
     * The `d` bucket emits unlayered with no media query, so its `display: grid`
     * already applies at every width. Measured by the pre-landing performance pass:
     * the repeats were 5.2% of the emitted CSS on a layout-heavy 50-band page, in a
     * file where emitted size is already a live concern (#1062/#1054).
     */
    public function testTheCompanionIsNotRepeatedInEveryTier(): void
    {
        $responsive = $this->css(['columns' => ['layout' => ['columns' => ['d' => 3, 't' => 2, 'p' => 1]]]]);
        $this->assertSame(1, substr_count($responsive, 'display:grid'),
            'the base tier already applies at every width; a copy per tier is bytes that change nothing');
        $this->assertStringContainsString('repeat(1, minmax(0, 1fr))', $this->tier($responsive, 'p'),
            'the per-tier TRACKS still differ, which is the whole point of a responsive map');

        // The author who sets columns only at one narrow tier still needs it there:
        // there is no base declaration to inherit.
        $phoneOnly = $this->css(['columns' => ['layout' => ['columns' => ['p' => 1]]]]);
        $this->assertStringContainsString('display:grid', $this->tier($phoneOnly, 'p'));
    }

    /** The other half of the bucket claim: a state is a bucket too. */
    public function testTheCompanionRidesAStateBucketAsWell(): void
    {
        $css = $this->css(['columns' => ['layout' => [':hover' => ['columns' => 2]]]]);
        $this->assertStringContainsString('.section__grid:hover{', $css);
        $this->assertStringContainsString('display:grid', $css);
    }

    /**
     * THE EXCLUSION HAS TO HOLD ON EVERY DOOR, not just the one the roster gates.
     *
     * `nav.menu` is out of the layout roster because its `display` is a visibility
     * switch. But `_css` reaches every role regardless of the roster, and the
     * companion used to key on the PROPERTY appearing in the bucket — so a raw
     * `grid-template-columns` there emitted an unlayered `display: grid`, outranked
     * the UA stylesheet's `[hidden]` rule, and pinned an open mobile menu open. A
     * styling write taking out a keyboard and screen-reader affordance is not a
     * thing to document; the companion is scoped to the parameter now, and this is
     * the test that says so.
     */
    public function testTheCompanionNeverReachesARoleTheRosterExcluded(): void
    {
        $css = pp_udc_band_css([
            'component' => 'nav',
            'id'        => 'pp-1a2b3c4d',
            'props'     => [],
            'udc'       => ['menu' => ['_css' => ['grid-template-columns' => '2']]],
        ]);

        $this->assertStringContainsString('grid-template-columns', $css, 'the raw declaration still emits');
        $this->assertStringNotContainsString('display:grid', $css,
            'an unlayered display:grid on .nav__menu outranks the UA [hidden] rule and pins the mobile menu open');
    }

    /**
     * The raw route keeps the parameter's GRAMMAR (a count is still a count, per
     * the Layer-2 typed-property rule) and does NOT keep its companion. Both halves
     * are pinned because both are surprising, and an untested surprise is a bug
     * waiting to be "fixed" in either direction.
     */
    public function testTheRawRouteKeepsTheGrammarAndDropsTheCompanion(): void
    {
        $css = $this->css(['columns' => ['_css' => ['grid-template-columns' => '3']]]);
        $this->assertStringContainsString('grid-template-columns:repeat(3, minmax(0, 1fr))', $css,
            'a claimed property keeps its parameter\'s meaning through _css — the count is still a count');
        $this->assertStringNotContainsString('display:grid', $css,
            'the raw valve checks safety, not meaning; companions belong to the designed parameter');
    }

    /**
     * BOTH IN PLACE: the raw declaration wins the property (and the envelope says
     * so), but it must not un-declare the display the group value implied — an
     * author who adds a second value should not watch the box stop being a grid.
     */
    public function testARawDeclarationOverAGroupValueKeepsTheCompanionAndIsDisclosed(): void
    {
        $udc = ['columns' => [
            'layout' => ['columns' => 3],
            '_css'   => ['grid-template-columns' => '1fr 2fr'],
        ]];

        $css = $this->css($udc);
        $this->assertStringContainsString('grid-template-columns:1fr 2fr', $css, 'the raw value wins the property');
        $this->assertStringContainsString('display:grid', $css, 'and the group value\'s companion survives it');

        $findings = pp_udc_composition_findings([$this->band($udc)]);
        $types    = array_column($findings, 'type');
        $this->assertContains('udc_css_overrides_group_value', $types,
            'the author set two values for one property; the envelope has to say which one lost');
    }

    /**
     * THE MINTING CASE — the one a synthesis written against the CSS text would
     * fail. A responsive value is stored as band-token references, so the emitted
     * track list is `repeat(var(--pp-…), minmax(0, 1fr))`. Chromium resolves that
     * (verified at 375/768/1280 before this was built, rule 14.3), and the
     * per-tier values differ.
     */
    public function testAResponsiveCountSurvivesMintingAndStillSynthesises(): void
    {
        $band       = $this->band(['columns' => ['layout' => ['columns' => ['d' => 3, 'p' => 1]]]]);
        $normalized = pp_udc_normalize_band($band);

        $stored = $normalized['udc']['columns']['layout']['columns'];
        $this->assertSame('@columns-layout-columns-d', $stored['d'], 'the responsive value must mint');
        $this->assertSame('3', (string) $normalized['udc']['_tokens']['columns-layout-columns-d']);

        $css = pp_udc_band_css($normalized);
        foreach (['d', 'p'] as $bp) {
            $this->assertMatchesRegularExpression(
                '/grid-template-columns:repeat\(var\(--pp-columns-layout-columns-' . $bp . '\), minmax\(0, 1fr\)\)/',
                $this->tier($css, $bp),
                sprintf('the %s tier must synthesise around the minted reference, not around its text', $bp)
            );
        }
    }

    /**
     * THE TWO HALVES OF `layout.columns` COME FROM ONE TABLE.
     *
     * The synthesis (a count becomes a track list) and the companion (a count
     * brings `display: grid`) were derived two different ways — one from the
     * registry's type, one from a hardcoded parameter name — and the count's SHAPE
     * was a regex copied into two files. Each seam is a silent divergence waiting
     * to happen: a literal the grammar accepts but the emitter declines to
     * synthesise emits a bare `grid-template-columns: 100`, which the browser drops
     * while KEEPING the companion. This pins that both halves follow the registry,
     * and that every count the grammar accepts survives the round trip.
     */
    public function testBothHalvesOfTheColumnsBehaviourComeFromTheRegistry(): void
    {
        $param = pp_udc_groups()['layout']['params']['columns'];
        $this->assertSame('grid', $param['companion'] ?? null,
            'the companion is a registry fact, not a parameter-name check in the emitter');

        // Every literal the grammar accepts as a count must round-trip to a track
        // list AND carry the companion — no literal may fall between the two.
        foreach (['1', '2', '4', '12'] as $count) {
            $css = $this->css(['columns' => ['layout' => ['columns' => $count]]]);
            $this->assertStringContainsString(
                sprintf('grid-template-columns:repeat(%s, minmax(0, 1fr))', $count),
                $css,
                sprintf('the count "%s" validates, so it must also synthesise', $count)
            );
            $this->assertStringContainsString('display:grid', $css);
        }
    }

    // ── 4. The Layer-2 interaction ───────────────────────────────────────────

    /**
     * Claiming a property types every `_css` write of it. The grammar is therefore
     * wide enough that a value the browser accepts still validates: a refusal here
     * would not fail its own edit alone — update_composition validates the WHOLE
     * composition, so one stored value would lock an unrelated band (#1007).
     */
    public function testAClaimedPropertyIsTypedThroughRawCssWithoutNarrowingWhatCssAccepts(): void
    {
        $this->assertNull(
            pp_udc_validate_map(['columns' => ['_css' => ['align-items' => 'safe center']]], 'section'),
            'safe center is valid CSS and _css accepted it before the registry claimed the property'
        );
        $this->assertNull(
            pp_udc_validate_map(['columns' => ['_css' => ['grid-template-columns' => 'repeat(auto-fit, minmax(20rem, 1fr))']]], 'section'),
            '#905\'s own value must keep working through the raw path it works through today'
        );

        $error = pp_udc_validate_map(['columns' => ['_css' => ['align-items' => 'centre']]], 'section');
        $this->assertInstanceOf(WP_Error::class, $error, 'a claimed property is typed now, so a misspelling is caught at write');
    }

    // ── 5. Scope ─────────────────────────────────────────────────────────────

    /**
     * THE AUTHORING PATH (rule 14.1), not the engine's front door.
     *
     * Every assertion above calls pp_udc_validate_map() directly. That is the
     * engine's own gate, and a group can satisfy it while being unreachable
     * through the surface an author actually writes: #488 is the recorded case
     * where raw-meta seeding hid a schema contract the real write path refused.
     * So the group is written once the way the chat and the CLI write it.
     */
    public function testTheGroupIsWritableThroughTheRealAuthoringSurface(): void
    {
        $valid = pp_validate_action('create_page', [
            'title'       => 'Four across',
            'composition' => [[
                'component' => 'section',
                'props'     => ['title' => 'Process', 'body' => 'Copy.'],
                'udc'       => [
                    // #905's shape: a four-across band that the grammar refused to
                    // express until this group existed.
                    'columns' => ['layout' => ['columns' => ['d' => 4, 'p' => 1], 'align' => 'start']],
                    // #658's shape: the panel side placing itself.
                    'panel'   => ['sizing' => ['align-self' => 'center']],
                ],
            ]],
        ]);
        $this->assertTrue($valid, 'the Layout group must be reachable through the write path an author uses');

        $refused = pp_validate_action('create_page', [
            'title'       => 'Bad tracks',
            'composition' => [[
                'component' => 'section',
                // The band has to satisfy section's content requirement, or the
                // composition is refused for THAT before the udc map is read and
                // this test would pass on the wrong refusal.
                'props'     => ['title' => 'Process', 'body' => 'Copy.'],
                'udc'       => ['columns' => ['layout' => ['columns' => 'repeat(2, repeat(2, 1fr))']]],
            ]],
        ]);
        $this->assertInstanceOf(WP_Error::class, $refused, 'a nested repeat() must be refused at the authoring surface too');
        $this->assertStringContainsString('repeat', $refused->get_error_message());
    }

    public function testALayoutValueEmitsOnItsOwnRoleSelectorAndScopesToTheBand(): void
    {
        $css = $this->css(['inline-items' => ['layout' => ['justify' => 'center', 'wrap' => 'wrap-reverse']]]);
        $this->assertStringContainsString('[data-pp-band="pp-1a2b3c4d"] .section__inline-items', $css);
        $this->assertStringContainsString('justify-content:center', $css);
        $this->assertStringContainsString('flex-wrap:wrap-reverse', $css);
        $this->assertStringNotContainsString('.section__grid', $css, 'a value must not leak to a sibling role');
    }
}
