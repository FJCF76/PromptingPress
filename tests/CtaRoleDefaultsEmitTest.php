<?php
/**
 * THE SHIPPED DEFAULTS, ASSERTED AS CSS RATHER THAN AS JSON (#1026).
 *
 * WHY THIS FILE EXISTS, and it is the same reason SectionRoleDefaultsEmitTest exists one
 * rebuild earlier. cta's rebuild moved ~40 declarations out of the stylesheet and into 11
 * role defaults, and every claim made about that move — "this is the value v1 rendered",
 * "these four are breakpoint maps", "this role declines to default" — is otherwise pinned
 * only against the schema's JSON TEXT. That is one layer above the thing the claim is about.
 *
 * A schema key can be correct while the emission is wrong: a breakpoint map could gain a
 * base-tier fallback it should not have, an `@token` could stop resolving, a role could
 * silently emit nothing because its selector changed. Every one of those passes a JSON-text
 * assertion and ships a visibly different band.
 *
 * WHAT THIS FILE IS NOT. It is not a golden-file snapshot. A test that asserts the exact
 * bytes of forty declarations fails on every legitimate reordering and teaches the next
 * author to regenerate it without reading it. Each assertion here names ONE declaration and
 * says which claim it defends.
 *
 * TWO CLAIMS HERE ARE ABSENCES, and they are the ones most worth pinning. `button` and
 * `body-link` declare NOTHING, deliberately — see testTheTwoRolesThatDeclareNothingEmitNothing
 * for why an absence needs a test more than a presence does.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class CtaRoleDefaultsEmitTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = pp_udc_component_defaults_css('cta');
        $this->assertNotSame('', $this->css, 'cta emitted no role defaults at all');
    }

    /** The base tier is everything before the first `@media`. */
    private function baseTier(): string
    {
        $at = strpos($this->css, '@media');
        return $at === false ? $this->css : substr($this->css, 0, $at);
    }

    private function phoneTier(): string
    {
        if (!preg_match('/@media \(max-width: 767px\)\{(.*?)\}\s*(?:@media|$)/s', $this->css, $m)) {
            $this->fail('no phone tier emitted');
        }
        return $m[1];
    }

    /**
     * The `t` tier. It had no reader at all until #1026's review: `baseTier()` stops at the
     * FIRST `@media` and `phoneTier()` reads only the 767px block, so every `t` value in a
     * breakpoint map was unasserted — four of them on `body` alone.
     */
    private function tabletTier(): string
    {
        if (!preg_match('/@media \(min-width: 768px\) and \(max-width: 1023px\)\{(.*?)\}\s*(?:@media|$)/s', $this->css, $m)) {
            $this->fail('no tablet tier emitted');
        }
        return $m[1];
    }

    /**
     * THE BAND BORDER, PER EDGE, AND THE LAYER IT RIDES.
     *
     * This is the default that made the A1 baseline narrowing necessary, so it is asserted
     * in two parts. First the VALUE: 1px top and bottom, which is what v1's `.cta--full-width`
     * drew and what Chromium measured at 375/768/1280 before the port. Section's equivalent
     * was zero-width and transparent, which is exactly why section could leave the
     * issue-332 baseline alone and cta could not.
     *
     * Second the LAYER. A `_band` default emits into `pp-zero`, strictly below `pp-v1` where
     * that baseline lives, and layer rank beats specificity outright — so if this tier ever
     * stopped being layered, the narrowing in components.css would become load-bearing for a
     * different reason and the reasoning recorded there would be wrong.
     */
    public function testTheBandDrawsTheTwoRulesV1sFullWidthLayoutDrew(): void
    {
        $base = $this->baseTier();
        $this->assertStringContainsString('border-top-width:1px;', $base, 'the band lost its top rule');
        $this->assertStringContainsString('border-bottom-width:1px;', $base, 'the band lost its bottom rule');
        $this->assertStringContainsString('border-top-style:solid;', $base);
        $this->assertStringContainsString('border-bottom-style:solid;', $base);
        $this->assertStringContainsString('border-color:var(--color-border);', $base);

        // Left and right are deliberately NOT declared: v1 drew none, and the CSS initial
        // border-style is `none`, so silence reproduces it exactly. Declaring `0` would be
        // the same render and a worse statement — it would claim a decision v1 never made.
        $this->assertStringNotContainsString('border-left-width', $base);
        $this->assertStringNotContainsString('border-right-width', $base);

        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="cta"\]\)\{[^}]*border-top-width:1px;/',
            $this->css,
            'the band tier must ride pp-zero — the issue-332 narrowing in components.css '
            . 'is reasoned on exactly that ranking'
        );
    }

    /**
     * THE BAND SURFACE AND RHYTHM, both `@token` references rather than literals.
     *
     * `@pp-band-padding` is a fluid clamp, which is why the padding needs no breakpoint map
     * even though Chromium measured three different values (53.6 / 68 / 76.8px). A literal
     * here would have frozen the band at one tier — the mistake a reader of the computed
     * values alone would make.
     */
    public function testTheBandCarriesTheSurfaceAndTheSharedRhythm(): void
    {
        $base = $this->baseTier();
        $this->assertStringContainsString('padding-top:var(--pp-band-padding);', $base);
        $this->assertStringContainsString('padding-bottom:var(--pp-band-padding);', $base);
        $this->assertStringContainsString('background:var(--color-surface);', $base);
        $this->assertStringNotContainsString('padding-top:53.6px', $base, 'the fluid token must not be frozen to a tier');
    }

    /**
     * THE FOUR BODY VALUES THAT ARE BREAKPOINT MAPS, and the one that is not.
     *
     * v1 had NO unconditional rule for this element's weight or leading: both existed only
     * inside `main > .cta .cta__body` in two media blocks, 430/1.66 above 768px and
     * 430/1.65 below. The colour and size split the same way, with the unscoped base rule
     * supplying the phone colour. Reading those declarations without their wrappers is the
     * #1023 media-scope trap exactly, and deleting them with the rules that carried them is
     * the other half of it — so all four tiers are asserted, in both directions.
     *
     * The WEIGHT is the control in this test: it is identical at every tier, so it must
     * appear in the base tier and NOT be re-emitted per breakpoint. If a future edit turns
     * every value into a map, this assertion is what notices.
     */
    public function testTheBodyKeepsBothSidesOfItsMediaScope(): void
    {
        $base  = $this->baseTier();
        $phone = $this->phoneTier();

        $this->assertMatchesRegularExpression(
            '/\.cta__body\{[^}]*font-size:1\.04rem;/',
            $base,
            'the desktop body size is gone'
        );
        $this->assertMatchesRegularExpression(
            '/\.cta__body\{[^}]*color:var\(--color-text-secondary\);/',
            $base,
            'the desktop body ink is gone'
        );
        $this->assertMatchesRegularExpression(
            '/\.cta__body\{[^}]*font-size:1rem;/',
            $phone,
            'the PHONE body size is gone — carrying the desktop literal unconditionally is '
            . 'the media-scope trap this port exists to avoid'
        );
        $this->assertMatchesRegularExpression(
            '/\.cta__body\{[^}]*color:var\(--color-muted\);/',
            $phone,
            'the phone body ink is gone'
        );

        $this->assertMatchesRegularExpression('/\.cta__body\{[^}]*font-weight:430;/', $base);
        $this->assertMatchesRegularExpression('/\.cta__body\{[^}]*line-height:1\.66;/', $base);
        $this->assertMatchesRegularExpression('/\.cta__body\{[^}]*line-height:1\.65;/', $phone);
        $this->assertDoesNotMatchRegularExpression(
            '/\.cta__body\{[^}]*font-weight/',
            $phone,
            'the weight is tier-invariant and must not be re-emitted per breakpoint'
        );

        // And NO measure: v1 declared `max-width: none` and rendered `none`.
        $this->assertDoesNotMatchRegularExpression('/\.cta__body\{[^}]*max-width/', $this->css);
    }

    /**
     * THE MEASURE, ON BOTH ELEMENTS THE ONE v1 SLOT CAPPED.
     *
     * `--cta-heading-measure` fed TWO rules: `.cta__title` on every layout, and
     * `.cta--full-width .cta__text`. Reproducing it takes two roles, and asserting them
     * together is deliberate — splitting them is precisely how a body ends up wider than
     * the title it sits under, which reads as a mistake rather than a design. Same shape as
     * `--section-body-measure` needing four roles at #1023.
     */
    public function testBothElementsTheMeasureSlotCappedStillCarryIt(): void
    {
        foreach (['cta__title', 'cta__text'] as $el) {
            $this->assertMatchesRegularExpression(
                '/\.' . $el . '\{[^}]*max-width:var\(--measure-heading\);/',
                $this->baseTier(),
                "{$el} lost the measure v1's --cta-heading-measure gave it"
            );
        }
    }

    /**
     * THE HEADING DECLARES NO COLOUR, and that absence is the whole claim.
     *
     * v1's rule was `color: var(--cta-heading-color, inherit)`, and the faithful port is
     * `currentColor` — NOT the absence of a declaration. The first draft of this test got
     * that wrong and PINNED THE DEFECT: it forbade every `color` on the role, on the reading
     * that "the fallback is the inherited value, not a value, so declare nothing." That
     * reading is false, and the light-band measurement could not tell the difference.
     *
     * `inherit` was an EXPLICIT DECLARATION doing real work. base.css gives every h1-h6 an
     * explicit `color: var(--color-text)`, and A RULE THAT MATCHES AN ELEMENT BEATS AN
     * INHERITED VALUE REGARDLESS OF LAYER. With nothing declared here the <h2> takes
     * base.css's #101828 and stops following the band, so an authored dark band renders its
     * heading at about 1.01:1 — invisible. On a LIGHT band the two are byte-identical, which
     * is exactly why measuring only the light band proved nothing.
     *
     * So the assertion below keeps its original INTENT — no literal or token colour may pin
     * the heading — while requiring the one value that restores the inheritance. footer's
     * `heading` role took this same correction at #994, where the reasoning is recorded in
     * the stylesheet.
     *
     * Its weight, leading and tracking come from base.css's shared `h2` rule via
     * `--font-weight-heading` / `--letter-spacing-heading`, so they are not restated either.
     */
    public function testTheHeadingDeclaresItsSizeAndRhythmButNotItsInk(): void
    {
        $base = $this->baseTier();
        $this->assertMatchesRegularExpression(
            '/\.cta__title\{[^}]*font-size:var\(--pp-band-heading-size\);/',
            $base
        );
        $this->assertMatchesRegularExpression(
            '/\.cta__title\{[^}]*margin-bottom:var\(--space-xs\);/',
            $base
        );
        // REQUIRED: the value that makes the heading follow the band.
        $this->assertMatchesRegularExpression(
            '/\.cta__title\{[^}]*(?<![-a-z])color:currentColor;/',
            $base,
            'the heading must default `color: currentColor`, or base.css\'s explicit h1-h6 '
            . 'colour wins by matching the element and the heading stops following the band'
        );
        // FORBIDDEN: any colour that is not currentColor — a literal or a token would pin
        // the heading against the band, which is what the original intent guarded.
        preg_match_all('/\.cta__title\{[^}]*\}/', $this->css, $blocks);
        foreach ($blocks[0] as $block) {
            preg_match_all('/(?<![-a-z])color:([^;]+);/', $block, $values);
            foreach ($values[1] as $value) {
                $this->assertSame(
                    'currentColor',
                    trim($value),
                    'the heading may declare ONLY `currentColor`: a literal or token here pins '
                    . 'it against the band it should follow (the emitted block was: ' . $block . ')'
                );
            }
        }
        $this->assertDoesNotMatchRegularExpression('/\.cta__title\{[^}]*font-weight/', $this->css);
        $this->assertDoesNotMatchRegularExpression('/\.cta__title\{[^}]*letter-spacing/', $this->css);
    }

    /**
     * THE TWO GAPS, which look like one until a second button exists.
     *
     * `inner` is the column gap (32px = `@space-lg`) and `buttons` is the gap between the
     * pair (8px = `@space-sm`). v1 disclosed that `--cta-inner-gap` did not govern the
     * space BETWEEN the buttons; the disclosure survives on the `inner` role and this is
     * what makes it checkable rather than merely written.
     */
    public function testTheTwoGapsAreDistinctAndBothSurvivedTheMoveOutOfTheStylesheet(): void
    {
        $base = $this->baseTier();
        $this->assertStringContainsString('.cta__inner{gap:var(--space-lg);}', $base);
        $this->assertStringContainsString('.cta__buttons{gap:var(--space-sm);}', $base);
    }

    /** The eyebrow pill, which measured byte-identical to section's and hero's. */
    public function testTheEyebrowPillCarriesEveryValueTheStylesheetUsedTo(): void
    {
        $base = $this->baseTier();
        foreach ([
            'font-size:0.8125rem;',
            'font-weight:600;',
            'letter-spacing:0.04em;',
            'text-transform:uppercase;',
            'color:var(--color-text);',
            'padding:0.35rem 0.85rem;',
            'margin-bottom:var(--space-sm);',
            'border-radius:3px;',
            'background:var(--color-surface-accent);',
            // GAPS CLOSED at #1026's coverage audit, which proved all three deletable in
            // silence. The eyebrow's border WIDTH and COLOR were unasserted even though
            // SectionRoleDefaultsEmitTest asserts exactly this pair on its panel — this file
            // cites section's rebuild as its model and then omitted them.
            'border-width:0;',
            'border-color:transparent;',
        ] as $decl) {
            $this->assertMatchesRegularExpression(
                '/\.cta__eyebrow\{[^}]*' . preg_quote($decl, '/') . '/',
                $base,
                "the eyebrow pill lost `{$decl}`"
            );
        }
    }

    /** The accented substring's ink, the one colour the band's text roles do declare. */
    public function testTheHeadingAccentKeepsItsInk(): void
    {
        $this->assertStringContainsString(
            '.cta__title-accent{color:var(--color-accent);}',
            $this->baseTier()
        );
    }

    /**
     * THE SECOND BUTTON, REST AND HOVER, IN ONE ASSERTION — because §1b says they move
     * together or they reproduce the #992 defect.
     *
     * A role block is emitted UNLAYERED, so a resting colour outranks every `pp-v1` hover
     * rule for the same property in EVERY state. A rest value without its state map is
     * therefore not "a button missing a hover"; it is a button that cannot respond to a
     * pointer at all. This is the only role in cta that declares colour-bearing resting
     * values, so it is the only one where the rule binds — which is why the two halves are
     * asserted in one test rather than two that could be deleted separately.
     *
     * `box-shadow:none` is the easy-to-miss one and has its own reason. With
     * `button2_variant` retired the second button renders as a bare `.btn` and therefore
     * MATCHES the shared premium filled family. `background:transparent` clears the
     * gradient (the `background` shorthand resets `background-image`), but nothing clears
     * the premium BEVEL — so without this the "outline" button ships with a filled button's
     * elevation.
     */
    public function testTheSecondButtonShipsItsHoverWithItsRest(): void
    {
        $base = $this->baseTier();
        $this->assertMatchesRegularExpression(
            '/\.cta__button--secondary\{[^}]*color:var\(--color-accent\);/',
            $base,
            'the second button lost v1\'s outline ink'
        );
        $this->assertMatchesRegularExpression(
            '/\.cta__button--secondary\{[^}]*background:transparent;/',
            $base,
            'the second button lost v1\'s transparent fill — and with it the gradient-clearing '
            . 'shorthand that keeps the premium fill off it'
        );
        $this->assertMatchesRegularExpression(
            '/\.cta__button--secondary\{[^}]*border-width:2px;[^}]*border-color:var\(--color-accent\);/',
            $base,
            'the second button must ship its 2px accent edge'
        );
        // GAP CLOSED: `border-style:solid` sat BETWEEN those two in the emitted block, so the
        // spanning regex above crossed it without ever asserting it. Deleting it from the
        // schema left 5088 PHPUnit and 1530 vitest tests green.
        $this->assertMatchesRegularExpression(
            '/\.cta__button--secondary\{[^}]*border-style:solid;/',
            $base
        );
        $this->assertMatchesRegularExpression(
            '/\.cta__button--secondary\{[^}]*border-radius:var\(--btn-radius\);/',
            $base
        );
        $this->assertMatchesRegularExpression(
            '/\.cta__button--secondary\{[^}]*box-shadow:none;/',
            $base,
            'without this the outline button keeps the premium bevel: background.fill clears '
            . 'the gradient, nothing clears the shadow'
        );

        // ...and the state, in the same emitted block set.
        $this->assertMatchesRegularExpression(
            '/\.cta__button--secondary:hover\{[^}]*background:var\(--color-accent\);/',
            $this->css,
            'the hover FILL is gone — a resting value without it strands the button, because '
            . 'the unlayered rest declaration already outranks the pp-v1 hover rule'
        );
        $this->assertMatchesRegularExpression(
            '/\.cta__button--secondary:hover\{[^}]*color:var\(--color-bg\);/',
            $this->css,
            'the hover INK is gone — accent-on-accent is what that renders'
        );
    }

    /**
     * THE TWO ROLES THAT DECLARE NOTHING, asserted as an absence.
     *
     * AN ABSENCE NEEDS A TEST MORE THAN A PRESENCE DOES, because nothing fails when it is
     * lost: adding a default here is a one-line edit that every other test in the repo goes
     * on passing through. #1023 shipped exactly that edit — `body-link` given base.css's own
     * anchor values — and it rendered a nested author button's label accent-on-accent,
     * effectively invisible, because a role block is unlayered and outranked the shared
     * premium `main .btn:not(...)` rule. That is the #545 defect, reintroduced through a
     * role selector, and it was caught by a test written for something else.
     *
     * `button` is the same shape from the other direction: role defaults outrank presets, so
     * a default here suppresses exactly the part of a `button` preset that makes it a button.
     *
     * Asserted on the SELECTOR rather than on a property list, so any declaration at all
     * fails — a narrower check would pass the next well-meaning addition.
     */
    public function testTheTwoRolesThatDeclareNothingEmitNothing(): void
    {
        // MATCH THE ROLE'S ACTUAL SELECTOR, `.cta__button--primary`. This regex read
        // `/\.cta__button\{/` while the role selected `.cta__button`; once the role gained
        // the primary modifier that pattern could no longer be produced by ANY `button`
        // declaration, so the assertion would have passed whatever the role emitted — the
        // vacuous-negative trap. The `\{` is still load-bearing: without it the pattern
        // would also match `.cta__button--secondary{`, which is a block that MUST exist.
        $this->assertDoesNotMatchRegularExpression(
            '/\.cta__button--primary\{/',
            $this->css,
            'the `button` role must emit NO block: every value it renders comes from the '
            . 'shared .btn and premium rules in pp-v1, and restating one here moves it to '
            . 'the unlayered tier where it would beat that family\'s own :hover'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.cta__body a\{/',
            $this->css,
            'the `body-link` role must emit NO block: base.css already gives every anchor '
            . 'these exact values, and restating them unlayered is the #545 defect'
        );

        // The roles EXIST — they are addressable, they just default nothing. Without this
        // the assertions above would pass just as happily on a typo that deleted them.
        $roles = pp_udc_component_roles('cta');
        $this->assertArrayHasKey('button', $roles);
        $this->assertArrayHasKey('body-link', $roles);
        $this->assertSame([], $roles['button']['defaults'] ?? null);
        $this->assertSame([], $roles['body-link']['defaults'] ?? null);
    }

    /**
     * NO DEFAULT REACHES A PSEUDO-ELEMENT OR A VARIANT CLASS.
     *
     * The rebuild's whole premise is that `.cta--inverted` and `.cta--has-bg-image` cannot
     * exist on a v2 band. If a role default ever emitted a selector carrying one, the AA
     * corrections this change deliberately retired would be back under a different name —
     * and every other test here would still pass.
     */
    public function testNoDefaultSmugglesBackAVariantScopedSelector(): void
    {
        foreach (['cta--inverted', 'cta--has-bg-image', 'cta--dark', '::before', '::after'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $this->css,
                "a role default emitted `{$forbidden}` — v2 has no variant-scoped role "
                . 'defaults, and ruling A3 defers pseudo-elements'
            );
        }
    }

    /**
     * EVERY ROLE SELECTOR IS SCOPED TO THIS COMPONENT.
     *
     * A role selector is interpolated into CSS by the engine, and the `body-link` role's
     * `.cta__body a` is the one that reaches a bare element type. An unscoped emission
     * would style every `<a>` on the page. Cheap to assert, catastrophic to miss.
     */
    public function testEveryEmittedSelectorIsScopedToTheBand(): void
    {
        preg_match_all('/(^|\})([^{}@]+)\{/', $this->css, $m);
        $selectors = array_values(array_filter(array_map('trim', $m[2])));
        $this->assertNotSame([], $selectors, 'no selectors parsed — this guard would be vacuous');

        foreach ($selectors as $selector) {
            if ($selector === '' || str_starts_with($selector, '@')) {
                continue;
            }
            $this->assertStringContainsString(
                '[data-pp-component="cta"]',
                $selector,
                "emitted an unscoped selector: {$selector}"
            );
        }
    }

    /**
     * THE DEFAULTS NOTHING ELSE IN THIS FILE READ, added after #1026's review proved all five
     * deletable: removing `_band.border.radius`, `_band.shadow.box`, `_band.background.position`,
     * `eyebrow.border.style` and `button-secondary.border[':hover'].color` from the schema left
     * 5086 PHPUnit and 1525 vitest tests green.
     *
     * Two are easy to mistake for covered. The `box-shadow:none` this file asserts elsewhere is
     * scoped to `.cta__button--secondary`, NOT the band — they are different roles and different
     * selectors. And `background-position:center` is the only thing that centres an authored
     * `_band.background.image`, so losing it silently re-frames every photo band.
     */
    public function testTheBandDefaultsThatNoOtherTestReads(): void
    {
        $base = $this->baseTier();

        // The BAND rule, which the engine builds differently from every other role: it is
        // `:where(...)` inside `@layer pp-zero` so it loses to the design system by construction.
        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="cta"\]\)\{[^}]*border-radius:0;/',
            $base,
            'the band must default border-radius:0'
        );
        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="cta"\]\)\{[^}]*box-shadow:none;/',
            $base,
            'the BAND must default box-shadow:none — distinct from the button-secondary one'
        );
        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="cta"\]\)\{[^}]*background-position:center;/',
            $base,
            'the band must default background-position:center, which is what centres an '
            . 'authored _band.background.image'
        );

        // The eyebrow's border STYLE. Without it the pill's `border-width: 0` has no style to
        // apply if an author sets only a width, and #332's baseline is not in play here.
        $this->assertMatchesRegularExpression(
            '/\.cta__eyebrow\{[^}]*border-style:solid;/',
            $base,
            'the eyebrow must default border-style:solid'
        );

        // The second button's HOVER RING. The docblock on the rest+hover test claims both
        // halves are asserted together; the hover fill and hover ink were, the ring was not.
        $this->assertMatchesRegularExpression(
            '/\.cta__button--secondary:hover\{[^}]*border-color:var\(--color-accent\);/',
            // The FULL sheet, not $base: the engine emits state blocks AFTER the breakpoint
            // tiers, and baseTier() truncates at the first @media, so it never sees them.
            $this->css,
            'the second button must ship its hover RING with its hover fill and ink'
        );
    }

    /**
     * THE TABLET TIER, which had no reader before #1026's review. `body` carries four
     * breakpoint maps and its `t` values were never asserted, so a `t` entry could be dropped
     * or changed without a single failure.
     */
    public function testTheBodyTabletTierIsEmitted(): void
    {
        $tablet = $this->tabletTier();
        $this->assertMatchesRegularExpression(
            '/\.cta__body\{[^}]*color:var\(--color-text-secondary\);/',
            $tablet,
            'the tablet body ink must match the desktop value it was measured to share'
        );
        $this->assertMatchesRegularExpression('/\.cta__body\{[^}]*font-size:1\.04rem;/', $tablet);
        $this->assertMatchesRegularExpression('/\.cta__body\{[^}]*line-height:1\.66;/', $tablet);
    }
}
