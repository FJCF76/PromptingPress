<?php
/**
 * THE SHIPPED DEFAULTS, ASSERTED AS CSS RATHER THAN AS JSON (#1066).
 *
 * The sibling of StatsRoleDefaultsEmitTest, and it exists for the reason every
 * *RoleDefaultsEmitTest since section #1023 exists: a rebuild's claims about what v1
 * rendered are otherwise pinned only against the schema's JSON TEXT, one layer above the
 * thing the claim is about. A schema key can be correct while the emission is wrong — an
 * `@token` stops resolving, a selector changes and a role silently emits nothing, a
 * conditional role's selector loses its specificity edge. Every one of those passes a
 * JSON-text assertion and ships a visibly different band.
 *
 * LOGOS AND STATS LOOK LIKE TWINS AND ARE NOT, which is why they get one file each rather
 * than one parameterised file for both. Three differences are measured, deliberate, and
 * each has a test here that would fail if a later author "harmonised" them:
 *
 *   1. THE HEADING IS NOT CENTRED. Chromium measured `text-align: start` with
 *      `margin-left: 0` at 375/768/1280. stats' heading IS centred, with both auto
 *      margins. Flattening logos to match would be a visible change to every logos band on
 *      every site that upgrades, made in the name of consistency.
 *   2. THE LIST TAKES ONE `gap`, not stats' asymmetric row/column pair.
 *   3. THE BAND DECLARES NO AUTO MARGINS, where stats' does.
 *
 * AND THE ONE STRUCTURAL CHANGE THE REBUILD MADE: v1 capped the logo image with ONE rule
 * and a descendant override. A role carries at most one default per parameter, so the
 * single `--logos-image-size` knob became TWO roles — `image` (3rem) and `image-labeled`
 * (2.5rem) — and the switch survives only because the labeled selector is strictly more
 * specific. That is the assertion most worth reading in this file, because it is the one
 * whose failure mode is silent: both roles emit, both are valid, and the strip just
 * renders every logo at one size.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class LogosRoleDefaultsEmitTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = pp_udc_component_defaults_css('logos');
        $this->assertNotSame('', $this->css, 'logos emitted no role defaults at all');
    }

    /** One role's emitted block body, by selector, from the element tier. */
    private function roleBlock(string $selector): string
    {
        $quoted = preg_quote('[data-pp-component="logos"] ' . $selector, '/');
        if (!preg_match('/' . $quoted . '\{([^}]*)\}/', $this->css, $m)) {
            $this->fail("no emitted block for role selector '{$selector}'");
        }
        return $m[1];
    }

    /** The band tier's block body — `_band` has no selector, it IS the component root. */
    private function bandBlock(): string
    {
        preg_match('/@layer pp-zero\{:where\(\[data-pp-component="logos"\]\)\{([^}]*)\}/', $this->css, $m);
        $this->assertNotEmpty($m, 'the band tier did not emit');
        return $m[1];
    }

    /**
     * THE BAND'S RHYTHM AND A FOCAL POINT — AND NOTHING ELSE.
     *
     * v1's default-theme logos band measured `rgba(0, 0, 0, 0)` with 0px/none on all four
     * edges, so a fill or a border here would paint a decision v1 never made onto every
     * upgrading site. `@pp-band-padding` is a fluid clamp (53.6 / 68 / 76.8px across the
     * three tiers), so no breakpoint map is needed and a literal would freeze one tier.
     *
     * THE AUTO MARGINS ARE ABSENT, AND THAT IS THE stats ASYMMETRY. stats' band declares
     * both; logos' measured 0 and declares neither. Adding them here to match would be a
     * change disguised as tidying.
     */
    public function testTheBandCarriesOnlyTheSharedRhythmAndAFocalPoint(): void
    {
        $band = $this->bandBlock();

        $this->assertStringContainsString('padding-top:var(--pp-band-padding);', $band);
        $this->assertStringContainsString('padding-bottom:var(--pp-band-padding);', $band);
        $this->assertStringNotContainsString('padding-top:53.6px', $band, 'the fluid token must not be frozen to a tier');

        $this->assertStringContainsString('background-position:center;', $band);

        $this->assertStringNotContainsString(
            'background:',
            $band,
            "the band must declare no fill: v1's default-theme logos band measured rgba(0, 0, 0, 0)"
        );
        foreach (
            [
                'border-top-width', 'border-bottom-width', 'border-left-width', 'border-right-width',
                'border-color', 'border-style',
            ] as $prop
        ) {
            $this->assertStringNotContainsString(
                $prop,
                $band,
                "the band must declare no {$prop}: v1's default-theme logos band drew no border on any edge"
            );
        }

        $this->assertStringNotContainsString(
            'margin-left',
            $band,
            'logos\' band measured margin-left: 0 at every tier — stats\' auto margins are stats\' decision'
        );

        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="logos"\]\)\{[^}]*padding-top:var\(--pp-band-padding\);/',
            $this->css,
            'the band tier must ride pp-zero, or it would defeat the #430/#431 adjacent rhythm'
        );
    }

    /**
     * THE HEADING IS START-ALIGNED, AND ITS SILENCE IS THE ASSERTION.
     *
     * Measured at 375/768/1280: `text-align: start`, `margin-left: 0`. The role therefore
     * declares NEITHER an alignment NOR auto margins — and because a role default is a real
     * unlayered declaration, adding either would not merely restate the measurement, it
     * would outrank an author's own site-wide alignment.
     *
     * `currentColor` is the #1046 ruling, and it is NOT redundant with base.css: base.css
     * declares `color: var(--color-text)` on every `h1`–`h6`, so without this declaration
     * a heading on an authored dark band would keep the light-band ink. The four type
     * absences are the GLOBAL-VALUE CASCADE LESSON — family, weight, line-height and
     * letter-spacing all come from base.css's heading rule, so v1's rendered values for
     * them were never logos' decision.
     */
    public function testTheHeadingIsStartAlignedFollowsTheBandAndRestatesNoGlobalType(): void
    {
        $heading = $this->roleBlock('.logos__heading');

        $this->assertStringContainsString('color:currentColor;', $heading);
        $this->assertStringContainsString('font-size:var(--pp-band-heading-size);', $heading);
        $this->assertStringContainsString('margin-bottom:var(--space-lg);', $heading);
        $this->assertStringContainsString('max-width:var(--measure-heading);', $heading);

        $this->assertStringNotContainsString(
            'text-align',
            $heading,
            'logos\' heading measured `start`; declaring `center` here would recentre every '
            . 'logos band on every upgrading site in the name of matching stats'
        );
        $this->assertStringNotContainsString(
            'margin-left',
            $heading,
            'no auto margins: the capped box measured flush with the container\'s start edge'
        );

        foreach (['font-family', 'font-weight', 'line-height', 'letter-spacing'] as $prop) {
            $this->assertStringNotContainsString(
                $prop,
                $heading,
                "{$prop} is base.css's heading rule, not logos' decision"
            );
        }
    }

    /**
     * ONE GAP, NOT stats' PAIR.
     *
     * v1 measured a single `gap: var(--space-lg)` on the strip. stats' list takes a
     * different row and column value; logos' does not, and a shared "harmonised" default
     * would change the horizontal rhythm of every logo strip.
     */
    public function testTheStripTakesASingleGap(): void
    {
        $list = $this->roleBlock('.logos__list');

        $this->assertStringContainsString('gap:var(--space-lg);', $list);
        $this->assertStringNotContainsString('column-gap', $list, 'v1 measured one gap, not a pair');
        $this->assertStringNotContainsString('row-gap', $list);
    }

    /**
     * THE ITEM DECLARES NOTHING AND STILL EXISTS — AN ADDRESS WITHOUT AN OPINION.
     *
     * v1's `.logos__item` carried only layout (`display: flex`, alignment), which is
     * STRUCTURAL and stays in components.css. So the role has no default to emit. Deleting
     * it would be the tempting tidy-up and the wrong one: without it an author cannot give
     * a plain logo tile a background, a border or padding at all, and the only route left
     * would be the labeled variant — which would silently apply to half the strip.
     *
     * This is the `cell-link` shape from table's rebuild, for the same reason.
     */
    public function testThePlainItemRoleEmitsNothingButStillExists(): void
    {
        $this->assertArrayHasKey(
            'item',
            pp_udc_component_roles('logos'),
            'the plain tile must keep its address even though it defaults nothing'
        );
        $this->assertSame(
            [],
            pp_udc_component_roles('logos')['item']['defaults'] ?? null,
            'v1\'s `.logos__item` carried only structural layout — there is nothing to default'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\[data-pp-component="logos"\] \.logos__item\{/',
            $this->css,
            'a role with no defaults must emit no block, not an empty one'
        );

        // The address is real: the write is accepted, which is the whole point of keeping it.
        $this->assertNull(
            pp_udc_validate_map(['item' => ['background' => ['fill' => '@color-surface']]], 'logos'),
            'the plain tile must still be authorable, or the role is decoration'
        );
    }

    /**
     * THE TWO IMAGE ROLES, AND THE SPECIFICITY THAT KEEPS THEM APART.
     *
     * THE FAILURE MODE HERE IS SILENT, which is why it gets the longest comment in the
     * file. v1 capped the logo with one rule at 3rem and overrode it to 2.5rem inside a
     * labeled tile. A role carries at most ONE default per parameter, so the single knob
     * had to become two roles. Both emit unlayered element-tier blocks, so neither wins by
     * layer; the labeled one wins because `.logos__item--labeled .logos__image` is (0,2,0)
     * against `.logos__image`'s (0,1,0), before the shared `[data-pp-component]` attribute
     * both carry.
     *
     * If that edge were ever lost — a selector rewritten, the roles reordered by an author
     * who assumed source order decided it — both roles would still emit, both would still
     * validate, and the strip would just render every logo at one size. No other test in
     * the suite sees that; the rendered #583 mixed-strip case measures the two heights, and
     * this pins the mechanism that produces them.
     */
    public function testTheLabeledImageCapOutranksThePlainOneBySpecificity(): void
    {
        $plain   = $this->roleBlock('.logos__image');
        $labeled = $this->roleBlock('.logos__item--labeled .logos__image');

        $this->assertStringContainsString('max-height:3rem;', $plain);
        $this->assertStringContainsString('max-height:2.5rem;', $labeled);

        $roles = pp_udc_component_roles('logos');
        $plainSel   = $roles['image']['selector'];
        $labeledSel = $roles['image-labeled']['selector'];

        // The edge, stated as the structural fact it is rather than as a byte comparison:
        // the labeled selector is the plain one with a qualifying ancestor in front.
        $this->assertStringEndsWith(
            $plainSel,
            $labeledSel,
            'the labeled cap must target the SAME element, or it is a different rule rather '
            . 'than an override'
        );
        $this->assertGreaterThan(
            substr_count($plainSel, '.'),
            substr_count($labeledSel, '.'),
            'the labeled cap wins by class count; lose that and every logo renders at one size'
        );

        // NEITHER IS LAYERED. If one were, the layer would decide instead of specificity
        // and the comment above would stop being true.
        foreach ([$plainSel, $labeledSel] as $sel) {
            $this->assertDoesNotMatchRegularExpression(
                '/@layer[^{]*\{[^}]*' . preg_quote($sel, '/') . '\{/',
                $this->css,
                "`{$sel}` must ride the unlayered element tier"
            );
        }
    }

    /**
     * THE LABEL'S STATED SIZE AND ITS DE-EMPHASIS.
     *
     * `0.8125rem` is a stated default rather than a token — it is smaller than stats' label
     * (0.875rem), measured, and no `--font-size-*` token names either step. `@color-muted`
     * carries the de-emphasis on a light band, and it is a DIRECT declaration, which is why
     * a `_band` ink write does not reach it: a dark logos band is two writes, not one. v1's
     * inverted label was `@color-bg` at `opacity: 0.75`; `opacity` has no UDC group, so it
     * ports as the measured composite `rgb(192, 195, 201)` and the docs say so.
     */
    public function testTheLabelPinsItsStatedSizeAndItsMutedInk(): void
    {
        $label = $this->roleBlock('.logos__label');

        $this->assertStringContainsString('font-size:0.8125rem;', $label);
        $this->assertStringContainsString('color:var(--color-muted);', $label);
        $this->assertStringContainsString('text-align:center;', $label);

        $this->assertStringNotContainsString(
            'opacity',
            $label,
            'opacity is in no UDC group; the inverted de-emphasis ports as rgb(192, 195, 201)'
        );
    }

    /**
     * NO ROLE RESTATES A VALUE THAT COMES FROM BASE.CSS — THE GLOBAL-VALUE SWEEP.
     */
    public function testNoRoleRestatesAValueThatComesFromBaseCss(): void
    {
        foreach (['font-family:', 'letter-spacing:', 'font-weight:'] as $global) {
            $this->assertStringNotContainsString(
                $global,
                $this->css,
                "`{$global}` is a base.css global; no logos role decided it, so no logos role may pin it"
            );
        }
    }

    /**
     * THE RETIRED `theme` PROP'S REPLACEMENT ACCEPTS THE WRITE IT PROMISES.
     *
     * `retired_props` promises a route. SchemaValidationTest checks the route names a role
     * this component declares; that is a spelling check. This checks the role can actually
     * take the write — a route to a role whose group list excludes the group the migration
     * doc names is a refusal dressed as help.
     */
    public function testTheRetiredThemePropsReplacementAcceptsTheWriteItPromises(): void
    {
        // The documented inverted band, whole, exactly as docs/howto-migrate-a-logos-band-to-v2.md
        // spells it — including the label write the route says the band ink does NOT reach.
        $this->assertNull(pp_udc_validate_map([
            '_band' => [
                'background' => ['fill' => '@color-bg-inverted'],
                'typography' => ['color' => '@color-bg'],
            ],
            'label' => ['typography' => ['color' => 'rgb(192, 195, 201)']],
        ], 'logos'), 'the documented dark-band write must be accepted whole');

        // The documented `muted` framing, which is the other `theme` value.
        $this->assertNull(pp_udc_validate_map([
            '_band' => [
                'background' => ['fill' => '@color-surface'],
                'border'     => [
                    'width-top'    => '1px',
                    'width-bottom' => '1px',
                    'style-top'    => 'solid',
                    'style-bottom' => 'solid',
                    'color'        => '@color-border',
                ],
            ],
        ], 'logos'), 'the documented muted-framing write must be accepted whole');
    }

    /**
     * EVERY DEFAULT THE SCHEMA DECLARES ACTUALLY EMITS — THE COMPLETENESS HALF.
     *
     * Derived rather than counted, for the reason TableRoleDefaultsEmitTest records: an
     * exact declaration count is a golden file in disguise. Each param's CSS property is
     * looked up from the ENGINE'S taxonomy rather than restated here, and the block for
     * that role has to carry it. UdcEngineTest sweeps the same defaults for GRAMMAR; this
     * is the other half — acceptance is not emission.
     */
    public function testEveryDefaultTheSchemaDeclaresReachesItsOwnBlock(): void
    {
        $groups  = pp_udc_groups();
        $states  = pp_udc_states();
        $checked = 0;

        foreach (pp_udc_component_roles('logos') as $role => $definition) {
            $defaults = $definition['defaults'] ?? [];
            if ($defaults === []) {
                continue; // `item` defaults nothing by design — its own test pins that.
            }
            $haystack = $this->blocksForRole((string) ($definition['selector'] ?? ''));

            foreach ($defaults as $group => $params) {
                $names = [];
                foreach ($params as $key => $value) {
                    if (isset($states[$key]) && is_array($value)) {
                        foreach (array_keys($value) as $stateParam) {
                            $names[] = (string) $stateParam;
                        }
                        continue;
                    }
                    $names[] = (string) $key;
                }

                foreach ($names as $param) {
                    $property = $groups[$group]['params'][$param]['property'] ?? null;
                    $this->assertNotNull(
                        $property,
                        "logos.{$role} defaults {$group}.{$param}, which names no CSS property"
                    );
                    $this->assertStringContainsString(
                        $property . ':',
                        $haystack,
                        "logos.{$role} declares {$group}.{$param} and its block never carries "
                        . "`{$property}` — a default the schema advertises and the sheet does not ship"
                    );
                    $checked++;
                }
            }
        }

        // Fail-closed: logos declares 13 defaults across six roles today.
        $this->assertGreaterThan(9, $checked, 'the sweep stopped reaching the shipped defaults');
    }

    /**
     * Every emitted block belonging to one role, states included, concatenated.
     *
     * Scoped per role rather than searched whole-sheet on purpose: two roles declare a
     * `max-height` and two declare a `gap`, so a whole-sheet search would let either of
     * each pair vouch for the other — which is precisely the image-cap failure this file
     * exists to catch.
     */
    private function blocksForRole(string $selector): string
    {
        if ($selector === '') {
            return $this->bandBlock();
        }
        $pattern = '/\[data-pp-component="logos"\] ' . preg_quote($selector, '/') . '(?::[a-z-]+)?\{([^}]*)\}/';
        preg_match_all($pattern, $this->css, $m);
        return implode('', $m[1] ?? []);
    }
}
