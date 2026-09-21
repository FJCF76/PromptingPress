<?php
/**
 * THE SHIPPED DEFAULTS, ASSERTED AS CSS RATHER THAN AS JSON (#1101).
 *
 * WHY THIS FILE EXISTS, and it is the same reason SectionRoleDefaultsEmitTest,
 * CtaRoleDefaultsEmitTest, FaqRoleDefaultsEmitTest, TableRoleDefaultsEmitTest,
 * EmbedRoleDefaultsEmitTest, StatsRoleDefaultsEmitTest and LogosRoleDefaultsEmitTest
 * exist one to six rebuilds earlier (section #1023 -> cta #1026 -> faq #1046 ->
 * table/embed #1066 PR1 -> stats/logos #1066 PR2 -> grid #1101, so grid is the LAST
 * and stats the nearest). grid's rebuild moved thirty-eight style slots out of the
 * stylesheet and into eighteen roles, seventeen of them carrying defaults, seventy-six
 * declarations in all — and every claim made about that move ("this is what v1
 * rendered", "the card keeps its own ink on a dark band", "the bar survived as a real
 * element", "the paragraph's colour is genuinely tier-variant") is otherwise pinned
 * only against the schema's JSON TEXT. That is one layer above the thing the claim is
 * about.
 *
 * A schema key can be correct while the emission is wrong: an `@token` could stop
 * resolving, a role could silently emit nothing because its selector changed, a
 * breakpoint map could collapse to its desktop value and take the phone tier with it.
 * Every one of those passes a JSON-text assertion and ships a visibly different band.
 *
 * WHAT THIS FILE IS NOT. It is not a golden-file snapshot. A test that asserts the
 * exact bytes of ninety-odd declarations fails on every legitimate reordering and
 * teaches the next author to regenerate it without reading it. Each assertion here
 * names ONE declaration and says which claim it defends.
 *
 * THE FOUR ASSERTIONS MOST WORTH READING ARE NOT ABOUT VALUES:
 *
 *   1. THE CARD'S FIVE-WRITE OBLIGATION. `card-title`, `card-text`, `card-bullets` and
 *      `card-link` PIN their inks rather than following the card, because v1 kept cards
 *      light even on an inverted band. That is a deliberate default and a trap in equal
 *      measure, so the schema records it as four `reached_only_by_inheritance`
 *      obligations and this file asserts BOTH halves: that the inks really are pinned in
 *      the emitted CSS, and that the obligations really are declared. Either half alone
 *      is a promise with nothing behind it.
 *   2. THE CARD BAR IS A REAL ELEMENT. v1 painted it with `.grid__item::before`, which
 *      ruling A3 puts out of reach for the whole of v2. Ten of the owner's eleven
 *      production bands author it. If the `card-bar` role ever stops emitting, a brand
 *      signature on 91% of his bands has retired silently — so the role's emission AND
 *      the production pair's acceptance are both pinned here.
 *   3. THE PARAGRAPH'S TIER-VARIANT COLOUR. v1 rendered `@color-muted` below 768px and
 *      `@color-text-secondary` from 768px up. Porting either one alone would have
 *      changed the other tier, and nothing in a JSON-text assertion can tell a
 *      breakpoint map that survived from one that collapsed.
 *   4. THE BAND'S TWO ABSENCES. v1's `.grid` with the default `theme` measured
 *      `rgba(0, 0, 0, 0)` with 0px/none on all four edges (evidence-1101/v1-measure.json,
 *      `cards-default`, all three tiers), so declaring a fill or a border here would
 *      paint a surface onto every grid band on every site that upgrades.
 *
 * An absence needs a test more than a presence does, because nothing else in the suite
 * can tell a deliberate silence from a value someone forgot.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class GridRoleDefaultsEmitTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = pp_udc_component_defaults_css('grid');
        $this->assertNotSame('', $this->css, 'grid emitted no role defaults at all');
    }

    /** One role's emitted block body, by selector, from the element tier. */
    private function roleBlock(string $selector): string
    {
        $quoted = preg_quote('[data-pp-component="grid"] ' . $selector, '/');
        if (!preg_match('/' . $quoted . '\{([^}]*)\}/', $this->css, $m)) {
            $this->fail("no emitted block for role selector '{$selector}'");
        }
        return $m[1];
    }

    /** The band tier's block body — `_band` has no selector, it IS the component root. */
    private function bandBlock(): string
    {
        preg_match('/@layer pp-zero\{:where\(\[data-pp-component="grid"\]\)\{([^}]*)\}/', $this->css, $m);
        $this->assertNotEmpty($m, 'the band tier did not emit');
        return $m[1];
    }

    /**
     * THE BAND CARRIES THE SHARED RHYTHM AND A FOCAL POINT — AND THE TWO ABSENCES
     * MATTER MORE THAN EITHER.
     *
     * faq's `_band` paints `@color-surface`; cta's draws a 1px rule top and bottom.
     * grid's v1 band with the default `theme` measured `rgba(0, 0, 0, 0)` with 0px/none
     * on all four edges at all three tiers, so declaring either would claim a decision v1
     * never made — and would repaint every existing grid band on upgrade. The framing
     * rules belonged to `.grid--dark`, the retired `theme: "muted"` variant, and they
     * retire with it.
     *
     * `@pp-band-padding` is a fluid clamp, which is why the padding needs no breakpoint
     * map even though Chromium measured three different values (53.6 / 68 / 76.8px). A
     * literal here would have frozen the band at one tier — the mistake a reader of the
     * computed values alone would make.
     */
    public function testTheBandCarriesOnlyTheSharedRhythmAndAFocalPoint(): void
    {
        $band = $this->bandBlock();

        $this->assertStringContainsString('padding-top:var(--pp-band-padding);', $band);
        $this->assertStringContainsString('padding-bottom:var(--pp-band-padding);', $band);
        $this->assertStringNotContainsString(
            'padding-top:76.8px',
            $band,
            'the fluid token must not be frozen to the desktop tier'
        );
        $this->assertStringNotContainsString('padding-top:53.6px', $band);

        $this->assertStringNotContainsString(
            'background:',
            $band,
            "the band must declare no fill: v1's default-theme grid band measured rgba(0, 0, 0, 0)"
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
                "the band must declare no {$prop}: v1's default-theme grid band drew no border on any edge"
            );
        }

        // The focal point IS declared, and it is not dead: `background.image` is newly
        // authorable here, and without this an authored image would pin top-left (#1023).
        $this->assertStringContainsString('background-position:center;', $band);

        // And the tier it rides is load-bearing for the shared adjacent-band rhythm.
        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="grid"\]\)\{[^}]*padding-top:var\(--pp-band-padding\);/',
            $this->css,
            'the band tier must ride pp-zero, or it would defeat the #430/#431 adjacent rhythm'
        );
    }

    /**
     * THE HEADING FOLLOWS THE BAND'S INK; THE ACCENT SUBSTRING DOES NOT.
     *
     * `currentColor` is the #1046 ruling, and it is NOT redundant with base.css:
     * base.css declares `color: var(--color-text)` on every `h1`–`h6` at (0,0,1), so
     * without this declaration a heading on an authored dark band would keep the
     * light-band ink and go unreadable — the cta defect #1026 shipped at 1.016:1.
     *
     * The accent deliberately does not follow, because a direct declaration always beats
     * an inherited value and the accent is meant to stay accent-coloured when the heading
     * is not. That is the whole reason `_band` -> `typography.color` re-inks the heading
     * AND NOTHING ELSE on this component, which the `_band` role's description states in
     * as many words and this pair is the emitted proof of.
     *
     * The three absences are the GLOBAL-VALUE CASCADE LESSON: family, letter-spacing and
     * the heading weight token all come from base.css's heading rule, so v1's rendered
     * values for them were never grid's decision.
     */
    public function testTheHeadingFollowsTheBandInkAndTheAccentPinsItsOwn(): void
    {
        $heading = $this->roleBlock('.grid__heading');

        $this->assertStringContainsString('color:currentColor;', $heading);
        $this->assertStringNotContainsString(
            'color:var(--color-text)',
            $heading,
            'pinning the light-band ink is the cta defect (#1026, 1.016:1 on a dark band)'
        );
        $this->assertStringContainsString('font-size:var(--pp-band-heading-size);', $heading);
        $this->assertStringContainsString('max-width:var(--measure-heading);', $heading);

        $accent = $this->roleBlock('.grid__heading-accent');
        $this->assertStringContainsString('color:var(--color-accent);', $accent);
        $this->assertStringNotContainsString(
            'currentColor',
            $accent,
            'if the accent inherited, the accent would be gone on every band the author re-inks'
        );

        foreach (['font-family', 'letter-spacing'] as $prop) {
            $this->assertStringNotContainsString(
                $prop,
                $heading,
                "{$prop} is base.css's heading rule, not grid's decision — restating it would "
                . 'freeze this band against a site-wide type change'
            );
        }
    }

    /**
     * THE FIVE-WRITE OBLIGATION, ASSERTED ON BOTH SIDES OF THE PROMISE.
     *
     * THE ONE PIN THIS FILE EXISTS FOR, and the one an author is most likely to walk
     * into. v1 kept cards LIGHT even on an inverted band — measured, `card-title`,
     * `card-text`, `card-bullets` and `card-link` rendered byte-identically on a
     * `cards-default` and a `cards-inverted` scene at every tier (evidence-1101/
     * v1-measure.json). Faithfulness therefore means those four roles PIN their inks
     * instead of following the card, and the consequence is that darkening a card fill
     * is FIVE writes rather than one. Do four of the five and you ship dark text on a
     * dark panel — exactly the 3.21:1 shape #1059 caught on faq.
     *
     * A DEFAULT AND AN OBLIGATION ARE ONE FACT, SO BOTH HALVES ARE ASSERTED HERE. The
     * emitted colours are what makes the trap real; the four `reached_only_by_inheritance`
     * records are what makes it REACHABLE — they are what the model-facing prompt composes
     * the warning from (#1087). Pinning only the colours would leave an author who never
     * reads this file with no warning at all; pinning only the obligations would leave a
     * promise about an emission nobody checked.
     */
    public function testTheCardKeepsItsOwnInkAndTheSchemaSaysSoInFourObligations(): void
    {
        // The emitted half: four inks, pinned rather than inherited.
        $this->assertStringContainsString('color:var(--color-text);', $this->roleBlock('.grid__item-title'));
        $this->assertStringContainsString(
            'color:var(--color-text-secondary);',
            $this->roleBlock('.grid__item-text')
        );
        $this->assertStringContainsString('color:var(--color-muted);', $this->roleBlock('.grid__item-bullets'));
        $this->assertStringContainsString('color:var(--color-accent);', $this->roleBlock('.grid__item-link'));

        foreach (['.grid__item-title', '.grid__item-text', '.grid__item-bullets', '.grid__item-link'] as $selector) {
            $this->assertStringNotContainsString(
                'color:currentColor',
                $this->roleBlock($selector),
                "{$selector} must PIN its ink — v1 kept cards light on an inverted band, and a "
                . 'role that followed the card would silently change that'
            );
        }

        // The declared half: the obligation record each pinned ink owes.
        $card    = pp_udc_component_roles('grid')['card'];
        $partners = [];
        foreach ($card['obligations'] ?? [] as $obligation) {
            if (($obligation['kind'] ?? '') === 'reached_only_by_inheritance') {
                $partners[] = (string) ($obligation['with'] ?? '');
            }
        }
        foreach (['card-title', 'card-text', 'card-bullets', 'card-link'] as $partner) {
            $this->assertContains(
                $partner,
                $partners,
                "`card` must record the obligation it owes `{$partner}`: darkening the fill without "
                . 'setting that role\'s colour is the five-write trap, and the record is what puts the '
                . 'warning in front of the author'
            );
        }
    }

    /**
     * THE CARD'S FILL IS A TOKEN, NOT v1's FROZEN GRADIENT — THE ONE DEFAULT THAT CHANGED.
     *
     * Stated rather than quietly ported, because it is visible. v1's fill was
     * `linear-gradient(180deg, var(--color-bg) 0%, var(--color-surface) 100%)`, a very
     * subtle top-to-bottom tint. The value grammar accepts a gradient of LITERALS and a
     * bare `@token`, but not a token INSIDE a gradient, so the two available ports were a
     * token-following flat fill or a hex gradient frozen against every retheme.
     *
     * The flat `@color-bg` won, matching what faq's `item` and testimonials' `card`
     * already default for the same visual job. This asserts the OUTCOME of that argument
     * in the emitted sheet: a later author who "restores the tint" by hardcoding hexes
     * would break every retheme, and would do it here.
     */
    public function testTheCardFillFollowsTheTokenRatherThanFreezingV1sGradient(): void
    {
        $card = $this->roleBlock('.grid__item');

        $this->assertStringContainsString('background:var(--color-bg);', $card);
        $this->assertStringNotContainsString(
            'linear-gradient',
            $card,
            'a literal gradient here would stop following --color-bg and freeze the card against '
            . 'every retheme — the argument recorded in the `card` role\'s description'
        );
        $this->assertStringContainsString('border-color:var(--color-border);', $card);
        $this->assertStringContainsString('border-radius:4px;', $card);
    }

    /**
     * THE CARD BAR SURVIVED THE REBUILD, AND THIS IS WHERE THAT IS PROVED.
     *
     * v1 painted it as `.grid__item::before`. Ruling A3 defers pseudo-elements for the
     * whole of v2, so ported literally the bar's colour and height would have had NO v2
     * address and would have retired in silence. Measured on the owner's live site before
     * deciding: 10 of his 11 production grid bands author both slots, all ten with the
     * identical pair `linear-gradient(120deg,#7B5BFF 0%,#FF5C2E 50%,#3DDFC8 100%)` at
     * `3px` — a brand signature on 91% of bands.
     *
     * So the bar became a real `<span class="grid__item-bar">` and the `card-bar` role.
     * TWO CLAIMS, BOTH NEEDED: the role emits (or the element is unstyled), and the
     * production pair is ACCEPTED by the existing grammar (or the port bought nothing and
     * the signature retired anyway, one layer further down). The second is the one a
     * schema-text assertion cannot make.
     */
    public function testTheCardBarEmitsAndTheProductionBrandPairIsAccepted(): void
    {
        $bar = $this->roleBlock('.grid__item-bar');

        $this->assertStringContainsString('background:var(--color-border);', $bar);
        $this->assertStringContainsString('height:2px;', $bar);

        // The owner's live pair, verbatim from the 2026-09-21 Chromium read of all 11
        // production bands. No grammar widening was needed for either half — that is the
        // whole finding that saved the capability, and this is where it stays true.
        $this->assertNull(
            pp_udc_validate_map([
                'card-bar' => [
                    'background' => ['fill' => 'linear-gradient(120deg,#7B5BFF 0%,#FF5C2E 50%,#3DDFC8 100%)'],
                    'sizing'     => ['height' => '3px'],
                ],
            ], 'grid'),
            "the owner's production bar pair must be accepted at band grain by the existing grammar"
        );
    }

    /**
     * THE PARAGRAPH'S COLOUR IS A REAL BREAKPOINT MAP, AND BOTH TIERS HAVE TO EMIT.
     *
     * MEASURED, NOT INVENTED. v1 rendered `rgb(94, 102, 119)` (`@color-muted`) below
     * 768px and `rgb(45, 54, 72)` (`@color-text-secondary`) from 768px up, because the
     * premium typography tier only lightened the paragraph at the wider tiers. Porting
     * one of the two would have changed the other.
     *
     * This is the assertion a JSON-text check cannot make: a breakpoint map that
     * collapsed to its desktop value still reads as a perfectly plausible schema key, and
     * the phone tier would simply stop being mentioned. The absence of a media block is
     * invisible until somebody looks at a phone.
     */
    public function testTheCardParagraphKeepsBothTiersOfItsMeasuredColour(): void
    {
        $this->assertStringContainsString(
            'color:var(--color-text-secondary);',
            $this->roleBlock('.grid__item-text'),
            'the base tier is the 768px-and-up value'
        );

        // And the phone tier is a DIFFERENT token inside the phone media block. Scoped to
        // that block on purpose: a whole-sheet search would let `card-bullets`' own
        // `@color-muted` vouch for a phone tier that had vanished.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\)\{[^@]*\[data-pp-component="grid"\] \.grid__item-text\{[^}]*color:var\(--color-muted\);/',
            $this->css,
            "the phone tier must emit `@color-muted`: v1 measured rgb(94, 102, 119) below 768px and "
            . 'rgb(45, 54, 72) above it, so a collapsed map silently restyles one of the two'
        );

        // The tablet tier restates the desktop value rather than inheriting it, because
        // the phone block below would otherwise leak upward through the cascade.
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\) and \(max-width: 1023px\)\{[^@]*\.grid__item-text\{[^}]*color:var\(--color-text-secondary\);/',
            $this->css,
            'the tablet tier must restate the wider-tier colour, or the phone value leaks into it'
        );
    }

    /**
     * THE CARD TITLE'S WEIGHT IS GENUINELY TIER-VARIANT, AND THAT IS A MEASUREMENT.
     *
     * 660 on the phone, 670 above it — read off v1 in Chromium rather than inferred, and
     * kept because it is a real difference rather than a rounding artefact (the sizes move
     * with it: 16.96px to 19.52px). A reviewer meeting `670` and `660` in a schema will
     * reasonably suspect a typo and "fix" it to one value; this is the pin that argues
     * back with the rendered evidence.
     */
    public function testTheCardTitleWeightIsTierVariantAndBothTiersEmit(): void
    {
        $this->assertStringContainsString('font-weight:670;', $this->roleBlock('.grid__item-title'));
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\)\{[^@]*\.grid__item-title\{[^}]*font-weight:660;/',
            $this->css,
            'v1 measured weight 660 at 375 and 670 at 768/1280 — one value cannot be both'
        );
    }

    /**
     * THE LINK'S REST AND HOVER HALVES ARE ONE ROLE, AND THE HOVER BLOCK PRINTS AFTER.
     *
     * This is what replaced the v1 slot TWIN discipline (#564): v1 declared a rest colour
     * and a hover colour as two independent slots, so one could drift out of sync with the
     * other and a `SchemaTruthfulnessTest` roster existed to catch it. A v2 role declares
     * both in ONE map — `card-link` -> `typography` carries `color`, `decoration` and a
     * `":hover"` block holding both — so the flip bug is unrepresentable rather than merely
     * guarded.
     *
     * SOURCE ORDER IS THE MECHANISM, and it is why this asserts position rather than
     * presence. The state block carries the same specificity as the rest block plus one
     * pseudo-class, so it wins on both counts here; but the tier's whole ordering
     * contract (§3.4, no `!important` ever) rests on later-printing blocks winning ties,
     * and a reordering that moved state blocks ahead of rest blocks would take the hover
     * with it everywhere at once.
     */
    public function testTheLinkStatePairEmitsBothHalvesAndTheHoverPrintsLast(): void
    {
        $rest = $this->roleBlock('.grid__item-link');
        $this->assertStringContainsString('color:var(--color-accent);', $rest);
        $this->assertStringContainsString('text-decoration-line:none;', $rest);

        $restAt  = strpos($this->css, '[data-pp-component="grid"] .grid__item-link{');
        $hoverAt = strpos($this->css, '[data-pp-component="grid"] .grid__item-link:hover{');
        $this->assertIsInt($restAt);
        $this->assertIsInt($hoverAt, 'the `:hover` half of the `card-link` map never emitted');
        $this->assertGreaterThan(
            $restAt,
            $hoverAt,
            'the state block must print after the rest block: ties in this tier break on source '
            . 'order and nothing else (§3.4 forbids !important)'
        );

        preg_match('/\.grid__item-link:hover\{([^}]*)\}/', $this->css, $m);
        $this->assertStringContainsString('text-decoration-line:underline;', $m[1] ?? '');
        $this->assertStringContainsString('color:var(--color-accent-hover);', $m[1] ?? '');
    }

    /**
     * THE REDUCED-MOTION GUARD COVERS EVERY ROLE THAT DECLARES A TRANSITION — DERIVED.
     *
     * Two roles default a `motion` group today (`card` and `card-link`), and both have to
     * be inside the `prefers-reduced-motion` block or a user who asked the OS to stop
     * animating still gets the transition. Derived from the schema rather than listed,
     * because a third role gaining a transition tomorrow would otherwise ship unguarded
     * with this file still green — which is the failure mode the guard exists to prevent,
     * reproduced in its own test.
     */
    public function testEveryRoleThatDefaultsMotionIsInsideTheReducedMotionGuard(): void
    {
        preg_match('/@media \(prefers-reduced-motion: reduce\)\{(.*)$/s', $this->css, $m);
        $guard = $m[1] ?? '';
        $this->assertNotSame('', $guard, 'grid emits motion defaults but no reduced-motion guard');

        $checked = 0;
        foreach (pp_udc_component_roles('grid') as $role => $definition) {
            if (!isset($definition['defaults']['motion'])) {
                continue;
            }
            $selector = (string) ($definition['selector'] ?? '');
            $this->assertStringContainsString(
                '[data-pp-component="grid"] ' . $selector,
                $guard,
                "grid.{$role} defaults a transition and is not inside the reduced-motion guard"
            );
            $checked++;
        }
        $this->assertGreaterThan(1, $checked, 'the walk stopped finding motion defaults at all');
        $this->assertStringContainsString('transition-duration:0.01ms;', $guard);
    }

    /**
     * THE STEP BADGE'S FILL AND ITS NUMERAL ARE A PAIR, EMITTED AND DECLARED.
     *
     * `@color-accent` fill with `@color-bg` ink. Change one without the other and the
     * badge gets invisible numerals — a light fill under light text — which is why the
     * schema records it as an obligation rather than leaving it to be discovered on a
     * rendered page.
     */
    public function testTheStepBadgeFillAndNumeralAreEmittedAsAPair(): void
    {
        $badge = $this->roleBlock('.grid__step-number');

        $this->assertStringContainsString('background:var(--color-accent);', $badge);
        $this->assertStringContainsString('color:var(--color-bg);', $badge);
        $this->assertStringContainsString('border-radius:50%;', $badge);

        // The circle is a square with a 50% radius, so the two dimensions travel together
        // and both are tier-variant (44px above the phone, 40px on it). A width without a
        // height is an ellipse.
        $this->assertStringContainsString('width:44px;', $badge);
        $this->assertStringContainsString('height:44px;', $badge);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\)\{[^@]*\.grid__step-number\{[^}]*width:40px;height:40px;/',
            $this->css,
            'the badge is a circle at every tier: a width that shrinks without its height is an ellipse'
        );
    }

    /**
     * THE CARD'S PADDING LIVES ON `card-body`, NOT ON `card` — AND IT IS TIER-VARIANT.
     *
     * THE SPLIT IS STRUCTURAL RATHER THAN ARBITRARY, which is why it earns its own
     * assertion instead of riding the completeness sweep. A banner image is full-bleed to
     * the card's edges while the text is inset, so the padding cannot live on the element
     * the image is a sibling of. An author told to "set the card's padding" who writes it
     * on `card` gets an inset image and a double inset on the text — accepted, stored,
     * and wrong.
     *
     * The measured values are a real breakpoint map: 1.55rem on the phone, 2rem from
     * 768px up.
     *
     * THIS TEST EXISTS BECAUSE A MUTATION PROVED THE SWEEP BLIND TO ITS ABSENCE. Emptying
     * `card-body`'s defaults wholesale left `testEveryDefaultTheSchemaDeclaresReachesItsOwnBlock`
     * green, because that walk skips roles that declare no defaults and the floor still
     * cleared. A census can only check the things that are still there; a role that stops
     * declaring anything needs a claim of its own, and the role-count floor below is the
     * general net under the same hole.
     */
    public function testTheCardBodyOwnsTheCardsPaddingAndKeepsBothTiersOfIt(): void
    {
        $body = $this->roleBlock('.grid__item-body');

        $this->assertStringContainsString('padding:2rem;', $body);
        $this->assertStringContainsString('gap:var(--space-sm);', $body);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\)\{[^@]*\.grid__item-body\{[^}]*padding:1\.55rem;/',
            $this->css,
            'v1 measured 1.55rem of card padding on the phone and 2rem above it'
        );

        // And the padding is NOT on the card, or a banner image would be inset from the
        // card's edges instead of bleeding to them.
        $this->assertStringNotContainsString(
            'padding',
            $this->roleBlock('.grid__item'),
            'padding on `card` insets the banner image; it belongs to `card-body`'
        );
    }

    /**
     * SEVENTEEN OF EIGHTEEN ROLES CARRY DEFAULTS, AND `header` IS THE PRINCIPLED ONE THAT
     * DOES NOT.
     *
     * THE NET UNDER THE COMPLETENESS SWEEP'S ONE BLIND SPOT. That walk asserts every
     * DECLARED default reaches its block; it cannot see a role whose defaults were removed
     * wholesale, because a role with no defaults is simply skipped. So a rebuild could
     * lose a whole role's shipped values with the sweep green and its floor still cleared
     * — measured, by emptying `card-body` in a copy of this tree.
     *
     * `header` declares none legitimately: `.grid__header` is an ordinary block whose only
     * designable value is the alignment v1's `title_align` prop set, and an alignment
     * DEFAULT would be a decision v1 never made (it rendered `start`, which is the initial
     * value). A second role joining it is a real event and should have to argue with a
     * test rather than slip through as an absence.
     */
    public function testEveryRoleButTheHeaderShipsDefaults(): void
    {
        $roles = pp_udc_component_roles('grid');
        $without = [];
        foreach ($roles as $role => $definition) {
            if (($definition['defaults'] ?? []) === []) {
                $without[] = (string) $role;
            }
        }

        $this->assertSame(
            ['header'],
            $without,
            'exactly one grid role ships no defaults, and it is `header` — a role that stopped '
            . 'declaring its measured values would otherwise vanish from the completeness sweep '
            . 'entirely, because that walk skips roles with nothing to walk'
        );
        $this->assertCount(18, $roles, 'grid declares eighteen roles; the trimmed T4 taxonomy is the ruled one');
    }

    /**
     * EVERY RETIRED PROP'S REPLACEMENT ACTUALLY PAINTS AT ITS NEW ADDRESS.
     *
     * `retired_props` promises a route. SchemaValidationTest checks the route names a role
     * this component declares; that is a spelling check. This checks the role can actually
     * take the write — because a route to a role whose group list excludes the group the
     * migration doc tells an author to use is a refusal dressed as help.
     *
     * Grid retires SIX props, which is more than any earlier rebuild, and two of the six
     * route to a role that did not exist in v1 at all (`card-bar`, `card-body`). Those are
     * exactly the routes most likely to be wrong.
     */
    public function testEveryRetiredPropsReplacementAcceptsTheWriteItPromises(): void
    {
        // `theme: "inverted"` -> the band fill plus the band ink, and the subheading the
        // route explicitly says does NOT follow it. Spelled exactly as `retired_props`
        // spells it: v1 measured the heading AND the subheading at `@color-bg`, and the
        // heading gets there through `currentColor` while the subheading needs its own
        // write. A route that named a token this site does not register would be a
        // refusal dressed as help, which is the whole point of checking it here.
        $this->assertNull(pp_udc_validate_map([
            '_band'      => [
                'background' => ['fill' => '@color-bg-inverted'],
                'typography' => ['color' => '@color-bg'],
            ],
            'subheading' => ['typography' => ['color' => '@color-bg']],
        ], 'grid'), 'the documented dark-band write must be accepted whole');

        // `theme: "muted"` -> the framing rule pair, not a fill.
        $this->assertNull(pp_udc_validate_map([
            '_band' => [
                'border' => [
                    'width-top' => '1px', 'width-bottom' => '1px',
                    'style'     => 'solid', 'color' => '@color-border',
                ],
            ],
        ], 'grid'), 'the `muted` framing route must be accepted');

        // `title_align: "center"` -> the header's alignment, plus the auto margins that
        // centre the two capped BOXES. `text-align` alone leaves both boxes at the left
        // edge — the #367 trio, one component further on.
        $this->assertNull(pp_udc_validate_map([
            'header'     => ['typography' => ['align' => 'center']],
            'heading'    => ['spacing' => ['margin-left' => 'auto', 'margin-right' => 'auto']],
            'subheading' => ['spacing' => ['margin-left' => 'auto', 'margin-right' => 'auto']],
        ], 'grid'), 'the documented centring route must be accepted whole');

        // `image_treatment: "icon"` -> the media box's own sizing, spelled exactly as the
        // route spells it, `aspect-ratio: "auto"` included — clearing the 16:9 default is
        // half the treatment and a route that could not express it would be useless. The
        // stated narrowing (`object-fit: contain` has no typed parameter, so the
        // un-cropped fit is `_css` only) is a documented ABSENCE and is not tested here;
        // what must hold is that the BOX half works.
        $this->assertNull(pp_udc_validate_map([
            'card-media' => ['sizing' => ['width' => '48px', 'height' => '48px', 'aspect-ratio' => 'auto']],
        ], 'grid'), 'the `icon` box route must be accepted');

        // `items[].text_role` -> the paragraph's own map, at band grain here; the item
        // grain is pinned end to end in GridItemUdcTest.
        $this->assertNull(pp_udc_validate_map([
            'card-text' => ['typography' => ['color' => '@color-muted', 'size' => '0.875rem']],
        ], 'grid'), 'the `text_role` route must be accepted');

        // And the routes are not fiction: each names a role that exists.
        $roles = pp_udc_component_roles('grid');
        foreach (
            ['_band', 'header', 'heading', 'subheading', 'card', 'card-bar', 'card-body',
                'card-media', 'card-title', 'card-text', 'card-bullets', 'card-link'] as $role
        ) {
            $this->assertArrayHasKey($role, $roles, "the retirement routes name `{$role}`");
        }
    }

    /**
     * NO ROLE RESTATES A VALUE THAT COMES FROM BASE.CSS — THE GLOBAL-VALUE SWEEP.
     *
     * The per-role tests above each name the absences that matter for that role. This is
     * the net under them: any role that started declaring a global would have to be added
     * to this list deliberately, with a reason, rather than slipping in during a later
     * "while I was here" edit.
     *
     * `letter-spacing` is the exception that proves the rule and is NOT in this list:
     * `card-title` declares `-0.03em` because v1 measured it (-0.5856px against a 19.52px
     * size), and that is grid's own decision rather than base.css's. `eyebrow` declares
     * `0.04em` for the same reason. The sweep names the properties no grid role decided.
     */
    public function testNoRoleRestatesAValueThatComesFromBaseCss(): void
    {
        foreach (['font-family:', 'font-weight:var(--font-weight-heading)', '--line-height-body'] as $global) {
            $this->assertStringNotContainsString(
                $global,
                $this->css,
                "`{$global}` is a base.css global; no grid role decided it, so no grid role may pin it"
            );
        }
    }

    /**
     * EVERY DEFAULT THE SCHEMA DECLARES ACTUALLY EMITS — THE COMPLETENESS HALF.
     *
     * Each test above names ONE declaration and defends ONE claim, which is the right
     * shape for a claim and the wrong shape for a CENSUS: a default on a role nobody
     * thought to check can be added, or stop emitting, with nothing going red. At
     * seventy-six declarations across seventeen roles, grid has by far the most room for
     * that of any component.
     *
     * DERIVED RATHER THAN COUNTED, for the reason TableRoleDefaultsEmitTest records: an
     * exact declaration count at this size is a golden file in disguise. The schema's own
     * defaults are walked, each param's CSS property is looked up from the ENGINE'S
     * taxonomy rather than restated here, and the block for that role has to carry it.
     *
     * UdcEngineTest already sweeps every v2 default for GRAMMAR — that the value is one
     * the engine would accept. This is the other half, in #1046's words: acceptance is not
     * emission.
     */
    public function testEveryDefaultTheSchemaDeclaresReachesItsOwnBlock(): void
    {
        $groups  = pp_udc_groups();
        $states  = pp_udc_states();
        $checked = 0;

        foreach (pp_udc_component_roles('grid') as $role => $definition) {
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
                        "grid.{$role} defaults {$group}.{$param}, which names no CSS property"
                    );
                    $this->assertStringContainsString(
                        $property . ':',
                        $haystack,
                        "grid.{$role} declares {$group}.{$param} and its block never carries "
                        . "`{$property}` — a default the schema advertises and the sheet does not "
                        . 'ship is the accepted-but-inert class this contract exists to end'
                    );
                    $checked++;
                }
            }
        }

        // FAIL-CLOSED, AND THE FLOOR IS SET AGAINST A COUNTED NUMBER. grid declares 76
        // defaults across seventeen of its eighteen roles today, counted from the schema
        // rather than estimated — the mistake StatsRoleDefaultsEmitTest records is setting
        // a floor from a guess and letting a third of the sweep vanish while still green.
        // 68 keeps a deliberate margin of eight for a legitimate removal while still
        // catching a walk that has stopped reaching most of them; a floor that tracked the
        // count exactly would be the golden file this file's docblock disavows, failing on
        // every legitimate addition and teaching the next author to retune it unread.
        $this->assertGreaterThan(68, $checked, 'the sweep stopped reaching the shipped defaults');
    }

    /**
     * Every emitted block belonging to one role, states included, concatenated.
     *
     * `_band`'s empty selector is the pp-zero tier rather than an element block, so a
     * per-role haystack has to admit both. Scoped per role rather than searched
     * whole-sheet on purpose: on this component SIX roles declare a colour and five
     * declare a margin or padding, so a whole-sheet search would let any one of them vouch
     * for the others.
     *
     * THE `\{` AFTER THE SELECTOR IS LOAD-BEARING AND MORE SO HERE THAN ANYWHERE ELSE:
     * grid has nine selectors that are prefixes of other grid selectors (`.grid__item` of
     * `.grid__item-bar`, `.grid__item-body`, `.grid__item-title` and five more), so
     * without the brace `card`'s haystack would absorb every card part's block and the
     * completeness sweep above would pass with `card` emitting nothing at all.
     */
    private function blocksForRole(string $selector): string
    {
        if ($selector === '') {
            return $this->bandBlock();
        }
        $pattern = '/\[data-pp-component="grid"\] ' . preg_quote($selector, '/') . '(?::[a-z-]+)?\{([^}]*)\}/';
        preg_match_all($pattern, $this->css, $m);
        return implode('', $m[1] ?? []);
    }
}
