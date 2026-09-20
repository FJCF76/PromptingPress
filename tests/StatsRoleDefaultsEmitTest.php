<?php
/**
 * THE SHIPPED DEFAULTS, ASSERTED AS CSS RATHER THAN AS JSON (#1066).
 *
 * WHY THIS FILE EXISTS, and it is the same reason SectionRoleDefaultsEmitTest,
 * CtaRoleDefaultsEmitTest, FaqRoleDefaultsEmitTest, TableRoleDefaultsEmitTest and
 * EmbedRoleDefaultsEmitTest exist one to five rebuilds earlier (the order is section #1023
 * -> cta #1026 -> faq #1046 -> table/embed #1066 PR1 -> stats/logos #1066 PR2, so table is
 * the nearest and section the furthest). stats' rebuild moved twenty-one declarations out
 * of the stylesheet and into seven roles — all seven carrying defaults, twenty-four of them —
 * and every claim made about that move — "this is the value v1 rendered", "the centring is `auto`, not a
 * number", "the number declines a family", "the band paints nothing" — is otherwise pinned
 * only against the schema's JSON TEXT. That is one layer above the thing the claim is about.
 *
 * A schema key can be correct while the emission is wrong: an `@token` could stop
 * resolving, a role could silently emit nothing because its selector changed, a `keywords`
 * entry could be dropped and take `auto` with it. Every one of those passes a JSON-text
 * assertion and ships a visibly different band.
 *
 * WHAT THIS FILE IS NOT. It is not a golden-file snapshot. A test that asserts the exact
 * bytes of forty-three declarations fails on every legitimate reordering and teaches the
 * next author to regenerate it without reading it. Each assertion here names ONE
 * declaration and says which claim it defends.
 *
 * THE THREE ASSERTIONS MOST WORTH READING ARE NOT ABOUT VALUES:
 *
 *   1. THE AUTO MARGINS. `heading` declares `max-width`, `text-align: center` AND both
 *      `margin-*: auto`. Measured at 1280, the heading box is x=320 w=640 inside a
 *      container at x=64 w=1152 — both centred on 640 — and `margin-left` COMPUTES to
 *      224px there and to 0 at 375. Porting the computed number would have frozen the
 *      centring at one tier. The keyword must survive the engine, and this is where that
 *      is measured rather than assumed.
 *   2. THE NUMBER'S MISSING FAMILY. v1 declared `font-family: var(--stats-number-font,
 *      inherit)`. An explicit `inherit` IS a declaration — but nothing in this theme
 *      declares font-family on a <span>, so the inherited body face already lands and
 *      silence is byte-identical. The absence is the finding; a later author "fixing" it
 *      would add an unlayered declaration competing with every author's.
 *   3. THE BAND'S TWO ABSENCES. v1's `.stats-section` measured `rgba(0, 0, 0, 0)` with
 *      0px/none on all four edges, so declaring a fill would paint a surface onto every
 *      stats band on every site that upgrades.
 *
 * An absence needs a test more than a presence does, because nothing else in the suite can
 * tell a deliberate silence from a value someone forgot.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class StatsRoleDefaultsEmitTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = pp_udc_component_defaults_css('stats');
        $this->assertNotSame('', $this->css, 'stats emitted no role defaults at all');
    }

    /** One role's emitted block body, by selector, from the element tier. */
    private function roleBlock(string $selector): string
    {
        $quoted = preg_quote('[data-pp-component="stats"] ' . $selector, '/');
        if (!preg_match('/' . $quoted . '\{([^}]*)\}/', $this->css, $m)) {
            $this->fail("no emitted block for role selector '{$selector}'");
        }
        return $m[1];
    }

    /** The band tier's block body — `_band` has no selector, it IS the component root. */
    private function bandBlock(): string
    {
        preg_match('/@layer pp-zero\{:where\(\[data-pp-component="stats"\]\)\{([^}]*)\}/', $this->css, $m);
        $this->assertNotEmpty($m, 'the band tier did not emit');
        return $m[1];
    }

    /**
     * THE BAND'S RHYTHM AND ITS CENTRING — AND THE TWO ABSENCES THAT MATTER MORE.
     *
     * faq's `_band` paints `@color-surface`; cta's draws a 1px rule top and bottom. stats'
     * v1 band with the default `theme` measured `rgba(0, 0, 0, 0)` with 0px/none on all
     * four edges, so declaring either would claim a decision v1 never made.
     *
     * `@pp-band-padding` is a fluid clamp, which is why the padding needs no breakpoint map
     * even though Chromium measured three different values (53.6 / 68 / 76.8px). A literal
     * here would have frozen the band at one tier — the mistake a reader of the computed
     * values alone would make.
     */
    public function testTheBandCarriesOnlyTheSharedRhythmItsCentringAndAFocalPoint(): void
    {
        $band = $this->bandBlock();

        $this->assertStringContainsString('padding-top:var(--pp-band-padding);', $band);
        $this->assertStringContainsString('padding-bottom:var(--pp-band-padding);', $band);
        $this->assertStringNotContainsString(
            'padding-top:53.6px',
            $band,
            'the fluid token must not be frozen to a tier'
        );

        // The band's own auto margins, measured on v1 and carried as KEYWORDS for the same
        // reason the heading's are (see the heading test): a computed number is tier-bound.
        $this->assertStringContainsString('margin-left:auto;', $band);
        $this->assertStringContainsString('margin-right:auto;', $band);

        $this->assertStringNotContainsString(
            'background:',
            $band,
            "the band must declare no fill: v1's default-theme stats band measured rgba(0, 0, 0, 0)"
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
                "the band must declare no {$prop}: v1's default-theme stats band drew no border on any edge"
            );
        }

        // The focal point IS declared, and it is not dead: `background.image` is newly
        // authorable here, and without this an authored image would pin top-left (#1023).
        $this->assertStringContainsString('background-position:center;', $band);

        // And the tier it rides is load-bearing for the shared adjacent-band rhythm.
        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="stats"\]\)\{[^}]*padding-top:var\(--pp-band-padding\);/',
            $this->css,
            'the band tier must ride pp-zero, or it would defeat the #430/#431 adjacent rhythm'
        );
    }

    /**
     * THE #367 CENTRING TRIO MUST TRAVEL TOGETHER — THE ONE PIN THIS FILE EXISTS FOR.
     *
     * `text-align: center` alone centres the TEXT inside a box that stays pinned to the
     * container's left edge. What centres the BOX is the pair of auto margins, and they
     * only bite because `max-width` makes the box narrower than its container. Drop any one
     * of the three and the heading is visibly off-centre at desktop while still passing a
     * `text-align` assertion.
     *
     * AND THE KEYWORD IS THE POINT. `margin-left`/`margin-right` declare `keywords:
     * ["auto"]` in the engine's spacing group; if that entry were dropped the value would
     * be refused at compile time and the two declarations would vanish silently, taking the
     * centring with them and leaving `text-align` behind to make the loss look deliberate.
     */
    public function testTheHeadingIsCentredByAutoMarginsAndNotByTextAlignAlone(): void
    {
        $heading = $this->roleBlock('.stats__heading');

        $this->assertStringContainsString('max-width:var(--measure-heading);', $heading);
        $this->assertStringContainsString('text-align:center;', $heading);
        $this->assertStringContainsString(
            'margin-left:auto;',
            $heading,
            'without the auto margins the capped heading box sits at the container\'s left edge'
        );
        $this->assertStringContainsString('margin-right:auto;', $heading);

        // Not the computed number, at any tier. 224px is what Chromium reports at 1280 and
        // 0 is what it reports at 375 — one value cannot be both.
        $this->assertStringNotContainsString('margin-left:224px', $heading);
        $this->assertStringNotContainsString('margin-left:0px', $heading);

        // The engine has to still ACCEPT the keyword, not merely have emitted it once.
        $this->assertContains(
            'auto',
            pp_udc_groups()['spacing']['params']['margin-left']['keywords'] ?? [],
            'the centring is a keyword, and it lives or dies with this entry'
        );
    }

    /**
     * THE HEADING FOLLOWS THE BAND'S INK, AND RESTATES NONE OF BASE.CSS'S TYPE.
     *
     * `currentColor` is the #1046 ruling. It is NOT redundant with base.css: base.css
     * declares `color: var(--color-text)` on every `h1`–`h6` at (0,0,1), so without this
     * declaration a heading on an authored dark band would keep the light-band ink and go
     * unreadable. The role's (0,2,0) unlayered block is what lets a `_band`
     * `typography.color` write reach the heading at all.
     *
     * The four absences are the GLOBAL-VALUE CASCADE LESSON: family, weight, line-height
     * and letter-spacing all come from base.css's heading rule, so v1's rendered values for
     * them were never stats' decision. Restating them as role defaults would freeze this
     * component's headings against a site-wide type change — the exact failure
     * `update_design_token` exists to prevent.
     */
    public function testTheHeadingFollowsTheBandAndRestatesNoGlobalType(): void
    {
        $heading = $this->roleBlock('.stats__heading');

        $this->assertStringContainsString('color:currentColor;', $heading);
        $this->assertStringNotContainsString(
            'color:var(--color-text)',
            $heading,
            'pinning the light-band ink is the cta defect (#1026, 1.016:1 on a dark band)'
        );

        $this->assertStringContainsString('font-size:var(--pp-band-heading-size);', $heading);
        $this->assertStringContainsString('margin-bottom:var(--space-lg);', $heading);

        foreach (['font-family', 'font-weight', 'line-height', 'letter-spacing'] as $prop) {
            $this->assertStringNotContainsString(
                $prop,
                $heading,
                "{$prop} is base.css's heading rule, not stats' decision — restating it would "
                . 'freeze this band against a site-wide type change'
            );
        }
    }

    /**
     * THE ACCENT SUBSTRING PAINTS ITS OWN COLOUR, WHICH IS THE TRAP AND THE CAPABILITY.
     *
     * A direct declaration always beats an inherited value, so `heading-accent` does NOT
     * follow `heading`. That is deliberate — the accent is meant to stay accent-coloured
     * when the heading is not — but it is also why the migration docs say a dark stats band
     * is FOUR writes rather than two, and why the rendered #463 e2e block still measures the
     * authored correction on a scrim band. Pin the declaration so the doc keeps being true.
     */
    public function testTheAccentSubstringPinsItsOwnColourRatherThanFollowingTheHeading(): void
    {
        $accent = $this->roleBlock('.stats__heading-accent');

        $this->assertStringContainsString('color:var(--color-accent);', $accent);
        $this->assertStringNotContainsString(
            'currentColor',
            $accent,
            'if the accent inherited, the #463 trap would be gone — and so would the accent'
        );
    }

    /**
     * THE LIST'S TWO GAPS ARE DIFFERENT TOKENS, AND FLATTENING THEM WOULD BE A REGRESSION.
     *
     * v1 measured `row-gap: var(--space-lg)` and `column-gap: var(--space-xl)` — a wider
     * horizontal rhythm than vertical, which is what keeps a wrapped strip of figures from
     * reading as a grid. A single `gap` default would have collapsed one of the two to the
     * other and nothing else in the suite would have noticed, because both values are
     * plausible.
     */
    public function testTheListKeepsItsAsymmetricGapPair(): void
    {
        $list = $this->roleBlock('.stats__list');

        $this->assertStringContainsString('row-gap:var(--space-lg);', $list);
        $this->assertStringContainsString('column-gap:var(--space-xl);', $list);
        $this->assertStringNotContainsString(
            'gap:var(',
            str_replace(['row-gap:var(', 'column-gap:var('], '', $list),
            'a shorthand `gap` would flatten the measured asymmetry'
        );
    }

    /**
     * THE ITEM'S FLOOR IS A RATIFIED LITERAL, AND IT WITHHOLDS TYPOGRAPHY ON PURPOSE.
     *
     * `min-width: 8rem` is v1's, and it is what stops a two-character figure from
     * collapsing its column in a wrapped strip. It is a literal rather than a token because
     * no token is the right size for "wide enough for a number and its label" — that is
     * recorded in components/stats/README.md with the condition that would reopen it.
     *
     * The item declines the typography group entirely: ink on the figure belongs to
     * `number` and ink on the caption to `label`, and an author writing on the item would
     * be writing on a flex container whose children both re-declare colour — accepted and
     * inert, the class of defect this contract exists to end.
     */
    public function testTheItemPinsItsFloorAndSendsTypographyToItsChildren(): void
    {
        $item = $this->roleBlock('.stats__item');

        $this->assertStringContainsString('min-width:8rem;', $item);
        $this->assertStringContainsString('gap:var(--space-xs);', $item);

        $refused = pp_udc_validate_map(['item' => ['typography' => ['color' => '#ffffff']]], 'stats');
        $this->assertInstanceOf(
            \WP_Error::class,
            $refused,
            'a typography write on `item` would be overridden by both children on every band'
        );
        $this->assertSame('unknown_udc_group', $refused->get_error_code());

        // AND BOTH ROUTES WORK, or the refusal above is a dead end rather than a redirect.
        $this->assertNull(pp_udc_validate_map(['number' => ['typography' => ['color' => '#fff']]], 'stats'));
        $this->assertNull(pp_udc_validate_map(['label' => ['typography' => ['color' => '#fff']]], 'stats'));
    }

    /**
     * THE DISPLAY FIGURE, AND THE FAMILY IT DELIBERATELY DOES NOT DECLARE.
     *
     * #472 made the figure heading-typography controllable. The capability is the `number`
     * role's whole `typography` group now; what changed in the rebuild is only that the
     * FAMILY is no longer declared. v1's `var(--stats-number-font, inherit)` resolved to an
     * explicit `inherit`, and an explicit `inherit` IS a declaration — but nothing in this
     * theme declares font-family on a <span>, so the inherited body face already lands and
     * the omission is byte-identical (measured `system-ui, sans-serif` either way, at all
     * three tiers). Declaring it would put an unlayered declaration in every author's way
     * for no rendered gain.
     *
     * `line-height: 1` is the one value here that is NOT a global: base.css's
     * `--line-height-body` would leave a 2.5rem figure floating above its label.
     */
    public function testTheFigurePinsItsScaleAndWeightButDeclinesAFamily(): void
    {
        $number = $this->roleBlock('.stats__number');

        $this->assertStringContainsString('font-size:2.5rem;', $number);
        $this->assertStringContainsString('font-weight:700;', $number);
        $this->assertStringContainsString('line-height:1;', $number);
        $this->assertStringContainsString('color:var(--color-accent);', $number);

        $this->assertStringNotContainsString(
            'font-family',
            $number,
            "v1's `inherit` was a declaration that changed nothing; restating it as a role "
            . 'default would emit an unlayered declaration where v1 emitted an inert one'
        );

        // The address still exists even though the default does not — that is the whole
        // difference between "no default" and "not authorable".
        $this->assertNull(
            pp_udc_validate_map(['number' => ['typography' => ['family' => 'var(--font-heading)']]], 'stats'),
            '#472\'s capability is the address, not the default'
        );
    }

    /**
     * THE LABEL'S SIZE AND ITS DE-EMPHASIS, BOTH STATED.
     *
     * `0.875rem` is a stated default rather than a token: it is one step below body and no
     * `--font-size-*` token names that step. `@color-muted` is what carries the
     * de-emphasis on a light band — and it is the reason v1's `opacity: 0.75` on the
     * inverted band had to port as a measured COMPOSITE rather than as an opacity, since
     * `opacity` has no UDC group at all.
     */
    public function testTheLabelPinsItsStatedSizeAndItsMutedInk(): void
    {
        $label = $this->roleBlock('.stats__label');

        $this->assertStringContainsString('font-size:0.875rem;', $label);
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
     *
     * The per-role tests above each name the absences that matter for that role. This is
     * the net under them: any role that started declaring a global would have to be added
     * to this list deliberately, with a reason, rather than slipping in during a later
     * "while I was here" edit.
     */
    public function testNoRoleRestatesAValueThatComesFromBaseCss(): void
    {
        foreach (['font-family:', 'letter-spacing:', 'font-weight:var(--font-weight-heading)'] as $global) {
            $this->assertStringNotContainsString(
                $global,
                $this->css,
                "`{$global}` is a base.css global; no stats role decided it, so no stats role may pin it"
            );
        }
    }

    /**
     * EVERY RETIRED PROP'S REPLACEMENT ACTUALLY PAINTS AT ITS NEW ADDRESS.
     *
     * `retired_props` promises a route. SchemaValidationTest checks the route names a role
     * this component declares; that is a spelling check. This checks the role can actually
     * take the write — because a route to a role whose group list excludes the group the
     * migration doc tells an author to use is a refusal dressed as help.
     */
    public function testEveryRetiredPropsReplacementAcceptsTheWriteItPromises(): void
    {
        // `theme` -> `_band` background.fill + border pair + typography.color, and the
        // three child inks the route explicitly says it does NOT reach.
        $this->assertNull(pp_udc_validate_map([
            '_band' => [
                'background' => ['fill' => '@color-bg-inverted'],
                'typography' => ['color' => '@color-bg'],
                'border'     => ['width-top' => '1px', 'style-top' => 'solid', 'color' => '@color-border'],
            ],
            'number' => ['typography' => ['color' => '@color-accent-on-inverted']],
            'label'  => ['typography' => ['color' => 'rgb(192, 195, 201)']],
        ], 'stats'), 'the documented dark-band write must be accepted whole');

        // `background_image` -> the scrim write, exactly as the how-to spells it, MINUS the
        // attachment id: `background.image` is validated against the real Media Library, so
        // any id here would either fail (none exists in a unit run) or pin a fixture id that
        // means nothing. The id half is proved where an attachment actually exists — the
        // rendered #461/#463 e2e blocks import one and author it.
        $this->assertNull(pp_udc_validate_map([
            '_band' => [
                'background' => [
                    'overlay' => '@overlay-bg',
                    'size'    => 'cover',
                    'repeat'  => 'no-repeat',
                ],
            ],
            'heading'        => ['typography' => ['color' => '@color-bg']],
            'heading-accent' => ['typography' => ['color' => '@color-accent-on-overlay']],
            'number'         => ['typography' => ['color' => '@color-accent-on-overlay']],
            'label'          => ['typography' => ['color' => '@color-muted-on-overlay']],
        ], 'stats'), 'the documented scrim write must be accepted whole');

        // And the routes are not fiction: each names a role that exists.
        $roles = pp_udc_component_roles('stats');
        foreach (['_band', 'heading', 'heading-accent', 'number', 'label'] as $role) {
            $this->assertArrayHasKey($role, $roles, "the retirement routes name `{$role}`");
        }
    }

    /**
     * EVERY DEFAULT THE SCHEMA DECLARES ACTUALLY EMITS — THE COMPLETENESS HALF.
     *
     * Each test above names ONE declaration and defends ONE claim, which is the right shape
     * for a claim and the wrong shape for a CENSUS: a default on a role nobody thought to
     * check can be added, or stop emitting, with nothing going red.
     *
     * DERIVED RATHER THAN COUNTED, for the reason TableRoleDefaultsEmitTest records: an
     * exact declaration count at this size is a golden file in disguise. The schema's own
     * defaults are walked, each param's CSS property is looked up from the ENGINE'S
     * taxonomy rather than restated here, and the block for that role has to carry it.
     *
     * UdcEngineTest already sweeps every v2 default for GRAMMAR — that the value is one the
     * engine would accept. This is the other half, in #1046's words: acceptance is not
     * emission.
     */
    public function testEveryDefaultTheSchemaDeclaresReachesItsOwnBlock(): void
    {
        $groups  = pp_udc_groups();
        $states  = pp_udc_states();
        $checked = 0;

        foreach (pp_udc_component_roles('stats') as $role => $definition) {
            $defaults = $definition['defaults'] ?? [];
            if ($defaults === []) {
                continue;
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
                        "stats.{$role} defaults {$group}.{$param}, which names no CSS property"
                    );
                    $this->assertStringContainsString(
                        $property . ':',
                        $haystack,
                        "stats.{$role} declares {$group}.{$param} and its block never carries "
                        . "`{$property}` — a default the schema advertises and the sheet does not "
                        . 'ship is the accepted-but-inert class this contract exists to end'
                    );
                    $checked++;
                }
            }
        }

        // FAIL-CLOSED, AND THE FLOOR IS SET AGAINST A COUNTED NUMBER. stats declares 24
        // defaults across all seven of its roles today (counted from the schema, not
        // estimated — the first version of this comment said "20 across six" and set the
        // floor at 15, which would have let nine of the twenty-four vanish with the sweep
        // still green). 19 keeps a deliberate margin for a legitimate removal while still
        // catching a walk that has stopped reaching most of them: a floor that tracks the
        // count exactly would be the golden file this file's docblock disavows, failing on
        // every legitimate addition and teaching the next author to retune it unread.
        $this->assertGreaterThan(19, $checked, 'the sweep stopped reaching the shipped defaults');
    }

    /**
     * Every emitted block belonging to one role, states included, concatenated.
     *
     * `_band`'s empty selector is the pp-zero tier rather than an element block, so a
     * per-role haystack has to admit both. Scoped per role rather than searched whole-sheet
     * on purpose: three roles declare a colour and three declare a margin, so a whole-sheet
     * search would let any one of them vouch for the others.
     */
    private function blocksForRole(string $selector): string
    {
        if ($selector === '') {
            return $this->bandBlock();
        }
        // The optional state suffix, and NOTHING else: the `{` immediately after keeps
        // `.stats__heading` from matching `.stats__heading-accent`.
        $pattern = '/\[data-pp-component="stats"\] ' . preg_quote($selector, '/') . '(?::[a-z-]+)?\{([^}]*)\}/';
        preg_match_all($pattern, $this->css, $m);
        return implode('', $m[1] ?? []);
    }
}
