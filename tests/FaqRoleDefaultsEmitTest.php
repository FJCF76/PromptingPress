<?php
/**
 * THE SHIPPED DEFAULTS, ASSERTED AS CSS RATHER THAN AS JSON (#1046).
 *
 * WHY THIS FILE EXISTS, and it is the same reason CtaRoleDefaultsEmitTest and
 * SectionRoleDefaultsEmitTest exist one and two rebuilds earlier. faq's rebuild moved
 * ~45 declarations out of the stylesheet and into ten role defaults, and every claim made
 * about that move — "this is the value v1 rendered", "these four are breakpoint maps",
 * "this role declines to default", "the open state is a role" — is otherwise pinned only
 * against the schema's JSON TEXT. That is one layer above the thing the claim is about.
 *
 * A schema key can be correct while the emission is wrong: a breakpoint map could gain a
 * base-tier fallback it should not have, an `@token` could stop resolving, a role could
 * silently emit nothing because its selector changed. faq carries a sharper version of
 * that last one than any component before it — `question-open`'s selector contains
 * brackets, and a role whose selector the gate refuses is SKIPPED IN SILENCE. Every one
 * of those passes a JSON-text assertion and ships a visibly different band.
 *
 * WHAT THIS FILE IS NOT. It is not a golden-file snapshot. A test that asserts the exact
 * bytes of forty-five declarations fails on every legitimate reordering and teaches the
 * next author to regenerate it without reading it. Each assertion here names ONE
 * declaration and says which claim it defends.
 *
 * THREE CLAIMS HERE ARE ABSENCES, and they are the ones most worth pinning: the answer
 * declares no measure, the heading declares no family or tracking, and no role restates
 * the `text-muted` grey that is NOT a role default. An absence needs a test more than a
 * presence does, because nothing else in the suite can tell a deliberate silence from a
 * value someone forgot.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class FaqRoleDefaultsEmitTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = pp_udc_component_defaults_css('faq');
        $this->assertNotSame('', $this->css, 'faq emitted no role defaults at all');
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
     * The `t` tier. It had no reader at all on cta until #1026's review found four `t`
     * values unasserted on one role, so faq's reader ships with the file.
     */
    private function tabletTier(): string
    {
        if (!preg_match('/@media \(min-width: 768px\) and \(max-width: 1023px\)\{(.*?)\}\s*(?:@media|$)/s', $this->css, $m)) {
            $this->fail('no tablet tier emitted');
        }
        return $m[1];
    }

    /**
     * THE BAND'S SURFACE AND RHYTHM — AND ITS ABSENT BORDER.
     *
     * The absence is the interesting half. cta's `_band` draws a 1px rule top and bottom,
     * which is what forced the issue-332 baseline narrowing at #1026: a `_band` default
     * rides `pp-zero`, strictly below the `pp-v1` rule that claims `border-width: 0`.
     * faq's v1 band drew NO border on any edge — the framing belonged to the retired
     * `theme: "muted"` variant — so there is nothing here for that baseline to erase, and
     * declaring `0` would claim a decision v1 never made.
     *
     * `@pp-band-padding` is a fluid clamp, which is why the padding needs no breakpoint
     * map even though Chromium measured three different values (53.6 / 68 / 76.8px). A
     * literal here would have frozen the band at one tier — the mistake a reader of the
     * computed values alone would make.
     */
    public function testTheBandCarriesTheSurfaceAndTheSharedRhythmAndNoBorder(): void
    {
        $base = $this->baseTier();
        $this->assertStringContainsString('padding-top:var(--pp-band-padding);', $base);
        $this->assertStringContainsString('padding-bottom:var(--pp-band-padding);', $base);
        $this->assertStringContainsString('background:var(--color-surface);', $base);
        $this->assertStringNotContainsString('padding-top:53.6px', $base, 'the fluid token must not be frozen to a tier');

        // SCOPED TO THE BAND'S OWN BLOCK. The eyebrow role legitimately declares a
        // zero-width transparent border, so a whole-sheet search for "border-color" finds
        // it and proves nothing about the band — the first cut of this test did exactly
        // that and failed for the wrong reason.
        preg_match('/@layer pp-zero\{:where\(\[data-pp-component="faq"\]\)\{([^}]*)\}/', $this->css, $band);
        $this->assertNotEmpty($band, 'the band tier did not emit');
        foreach (['border-top-width', 'border-bottom-width', 'border-left-width', 'border-right-width', 'border-color', 'border-style'] as $prop) {
            $this->assertStringNotContainsString(
                $prop,
                $band[1],
                "the band must declare no {$prop}: v1's unthemed faq drew no border on any edge"
            );
        }

        // And the tier it rides is load-bearing for the shared adjacent-band rhythm.
        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="faq"\]\)\{[^}]*padding-top:var\(--pp-band-padding\);/',
            $this->css,
            'the band tier must ride pp-zero, or it would defeat the #430/#431 adjacent rhythm'
        );
    }

    /**
     * THE OPEN STATE, EMITTED — the claim no other component in this repo makes.
     *
     * `question-open` selects `.faq__item[open] > .faq__question`. Before #1046 the
     * compile-time selector gate refused a bracket, and a refused selector is SKIPPED
     * SILENTLY: the role would emit nothing while `pp_udc_validate_map()` went on
     * accepting authored values for it (the I35 gap filed as #1048). So this assertion is
     * not decoration — it is the one that fails if the widening is ever reverted, and it
     * fails LOUDLY where the engine fails quietly.
     */
    public function testTheOpenSummaryEmitsItsAccentThroughTheAncestorStateSelector(): void
    {
        $this->assertStringContainsString(
            '.faq__item[open] > .faq__question{color:var(--color-accent);}',
            $this->baseTier(),
            'the open-state role emitted nothing — a role whose selector the gate refuses '
            . 'is skipped in silence, so an absence here is how that failure looks'
        );

        // It must OUTRANK the resting role, or the affordance never shows. Both are
        // emitted at component grain, so the ranking is in the selectors themselves:
        // the open one carries one more compound.
        $this->assertMatchesRegularExpression(
            '/\.faq__question\{[^}]*color:var\(--color-text\);/',
            $this->baseTier(),
            'the resting question colour is gone'
        );
    }

    /**
     * THE QUESTION'S THREE TIER-SPLIT VALUES, and the weight that is NOT one.
     *
     * v1 declared `font-weight: 600` on `.faq__question` and it never rendered: the
     * `main > .faq .faq__question` rules in BOTH media queries said 560, and the two
     * queries partition every width. Measured at 375/768/1280, 560 is the only weight the
     * summary has ever had — so it is a flat default, and the assertion that it is NOT
     * re-emitted per tier is what would notice a future edit turning everything into maps.
     */
    public function testTheQuestionKeepsBothSidesOfItsMediaScope(): void
    {
        $base   = $this->baseTier();
        $phone  = $this->phoneTier();
        $tablet = $this->tabletTier();

        $this->assertMatchesRegularExpression('/\.faq__question\{[^}]*font-size:1rem;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__question\{[^}]*line-height:1\.45;/', $base);
        $this->assertMatchesRegularExpression(
            '/\.faq__question\{[^}]*font-size:0\.98rem;/',
            $phone,
            'the PHONE question size is gone — carrying the desktop literal unconditionally '
            . 'is the media-scope trap this port exists to avoid'
        );
        $this->assertMatchesRegularExpression('/\.faq__question\{[^}]*line-height:1\.42;/', $phone);
        $this->assertMatchesRegularExpression('/\.faq__question\{[^}]*font-size:1rem;/', $tablet);

        $this->assertMatchesRegularExpression('/\.faq__question\{[^}]*font-weight:560;/', $base);
        $this->assertDoesNotMatchRegularExpression(
            '/\.faq__question\{[^}]*font-weight/',
            $phone,
            'the weight is tier-invariant and must not be re-emitted per breakpoint'
        );
        // On the QUESTION's blocks only: the eyebrow role's weight IS 600, so a
        // whole-sheet search asserts something else entirely.
        preg_match_all('/\.faq__question\{([^}]*)\}/', $this->css, $questionBlocks);
        $this->assertNotEmpty($questionBlocks[1]);
        foreach ($questionBlocks[1] as $block) {
            $this->assertStringNotContainsString(
                'font-weight:600',
                $block,
                "v1's 600 never rendered — porting it would restyle every summary in the theme"
            );
        }
    }

    /**
     * THE ANSWER'S COLOUR SPLIT, which is the #1023 trap in its exact shape, and its
     * ABSENT MEASURE.
     *
     * The phone tier's ink came from an UNSCOPED base rule and the two wider tiers from a
     * rule inside `@media (min-width: 768px)`. Read without their wrappers,
     * `@color-text-secondary` looks like the default; it is the override.
     */
    public function testTheAnswerKeepsBothInksAndDeclaresNoMeasure(): void
    {
        $base   = $this->baseTier();
        $phone  = $this->phoneTier();
        $tablet = $this->tabletTier();

        $this->assertMatchesRegularExpression('/\.faq__answer\{[^}]*color:var\(--color-text-secondary\);/', $base);
        $this->assertMatchesRegularExpression('/\.faq__answer\{[^}]*line-height:1\.68;/', $base);
        $this->assertMatchesRegularExpression(
            '/\.faq__answer\{[^}]*color:var\(--color-muted\);/',
            $phone,
            'the phone answer ink is gone'
        );
        $this->assertMatchesRegularExpression('/\.faq__answer\{[^}]*line-height:1\.65;/', $phone);
        $this->assertMatchesRegularExpression('/\.faq__answer\{[^}]*color:var\(--color-text-secondary\);/', $tablet);

        // Size and weight are tier-invariant.
        $this->assertMatchesRegularExpression('/\.faq__answer\{[^}]*font-size:1rem;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__answer\{[^}]*font-weight:430;/', $base);
        $this->assertDoesNotMatchRegularExpression('/\.faq__answer\{[^}]*font-weight/', $phone);

        // NO measure: v1 declared `max-width: var(--faq-body-measure, none)` and rendered
        // `none`, which IS the initial value. Declaring it would claim a decision v1
        // never made; the capability survives because an author can still write one.
        $this->assertDoesNotMatchRegularExpression('/\.faq__answer\{[^}]*max-width/', $this->css);
    }

    /**
     * THE HEADING: `currentColor`, the two tier-split values, and the three globals it
     * does NOT restate.
     *
     * The colour is the ruled divergence (#1046 = 7A-2 B). v1 pinned it through a theme
     * variable to `--color-text`; `currentColor` renders identically on every band v1
     * could express and differs only on a band made dark through `_band` ->
     * `background.fill`, which v1 could not author at all.
     *
     * The three absences are the global-value lesson: family, tracking and `text-wrap`
     * come from base.css's shared `h1`-`h6` rule. A role default emits UNLAYERED, so
     * restating one would beat that rule in every state — including for elements and
     * pseudo-classes nobody was thinking about.
     */
    public function testTheHeadingFollowsItsBandAndRestatesNoGlobalValue(): void
    {
        $base  = $this->baseTier();
        $phone = $this->phoneTier();

        $this->assertMatchesRegularExpression(
            '/\.faq__heading\{[^}]*color:currentColor;/',
            $base,
            'the heading must follow its band — a pinned @color-text measures 1.006:1 on '
            . 'an authored dark band, which is the defect #1026 shipped and corrected'
        );
        $this->assertStringNotContainsString(
            '.faq__heading{font-size:var(--pp-band-heading-size);font-weight:560;line-height:1.12;color:var(--color-text)',
            $this->css,
            'the pinned literal must not come back'
        );

        $this->assertMatchesRegularExpression('/\.faq__heading\{[^}]*font-size:var\(--pp-band-heading-size\);/', $base);
        $this->assertMatchesRegularExpression('/\.faq__heading\{[^}]*max-width:var\(--measure-heading\);/', $base);
        $this->assertMatchesRegularExpression('/\.faq__heading\{[^}]*font-weight:560;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__heading\{[^}]*line-height:1\.12;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__heading\{[^}]*margin-bottom:1\.65rem;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__heading\{[^}]*line-height:1\.15;/', $phone);
        $this->assertMatchesRegularExpression('/\.faq__heading\{[^}]*margin-bottom:1\.25rem;/', $phone);

        // Scoped to the HEADING's blocks: the eyebrow legitimately sets letter-spacing,
        // which v1 declared on it and base.css does not.
        preg_match_all('/\.faq__heading\{([^}]*)\}/', $this->css, $headingBlocks);
        $this->assertNotEmpty($headingBlocks[1]);
        foreach ($headingBlocks[1] as $block) {
            foreach (['font-family', 'letter-spacing', 'text-wrap'] as $global) {
                $this->assertStringNotContainsString(
                    $global,
                    $block,
                    "{$global} comes from base.css's h1-h6 rule; restating it here would move "
                    . 'it to the unlayered tier and beat that rule in every state'
                );
            }
        }

        // v1's base rule fell back to --space-lg and never rendered, because both media
        // rules overrode it. Porting it would have changed the header rhythm at every tier.
        $this->assertStringNotContainsString('margin-bottom:var(--space-lg)', $this->css);
    }

    /**
     * THE EMPTY LINE'S COLOUR IS A ROLE DEFAULT, and that is a CHANGE OF MECHANISM the
     * markup paid for.
     *
     * v1 put `text-muted` on the element, which hardcodes `--color-muted` in `pp-v1` and
     * beats an authored colour — the exact case footer met at #994, where the template
     * dropped the class so the role could govern. The measured value is unchanged; what
     * changed is that an author can now reach it.
     */
    public function testTheEmptyStateCarriesItsMeasuredGreyAsARoleDefault(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.faq__empty\{[^}]*color:var\(--color-muted\);/',
            $this->baseTier(),
            'the empty line must carry its grey as a default now that the utility class is gone'
        );
        $this->assertMatchesRegularExpression('/\.faq__empty\{[^}]*padding-top:var\(--space-md\);/', $this->baseTier());
        $this->assertMatchesRegularExpression('/\.faq__empty\{[^}]*padding-bottom:var\(--space-md\);/', $this->baseTier());

        // And its left/right padding stays absent: v1 measured 0px, which is the initial
        // value for a <p>, so declaring it would state a decision that was never made.
        $this->assertDoesNotMatchRegularExpression('/\.faq__empty\{[^}]*padding-left/', $this->css);
    }

    /**
     * THE ITEM AND THE LIST — the two roles whose values are ordinary and whose ABSENCE
     * of a shadow is not.
     *
     * v1 measured `box-shadow: none` on the item, which is the initial value. cta's
     * `button-secondary` had to declare `shadow.box: none` explicitly because a premium
     * rule painted a bevel it needed to clear; nothing paints one here, so silence is
     * both faithful and sufficient.
     */
    public function testTheItemAndListCarryTheirMeasuredGeometry(): void
    {
        $base = $this->baseTier();
        $this->assertMatchesRegularExpression('/\.faq__item\{[^}]*border-width:1px;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__item\{[^}]*border-style:solid;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__item\{[^}]*border-color:var\(--color-border\);/', $base);
        $this->assertMatchesRegularExpression('/\.faq__item\{[^}]*border-radius:4px;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__item\{[^}]*background:var\(--color-bg\);/', $base);
        $this->assertDoesNotMatchRegularExpression('/\.faq__item\{[^}]*box-shadow/', $this->css);

        $this->assertMatchesRegularExpression('/\.faq__list\{gap:var\(--space-sm\);\}/', $base);
    }

    /**
     * THE EYEBROW MEASURED BYTE-IDENTICAL TO cta's, section's AND hero's.
     *
     * Asserted against the SIBLING SCHEMAS rather than against a literal copy of the
     * values, so the claim is the one worth making: these four roles agree. A literal
     * list here would pass while the four silently diverged, which is the drift this
     * test exists to catch.
     */
    public function testTheEyebrowAgreesWithEverySiblingEyebrowRole(): void
    {
        $mine = pp_udc_component_roles('faq')['eyebrow']['defaults'] ?? null;
        $this->assertNotNull($mine, 'faq declares no eyebrow role');

        foreach (['cta', 'section', 'hero'] as $sibling) {
            $theirs = pp_udc_component_roles($sibling)['eyebrow']['defaults'] ?? null;
            $this->assertSame(
                $theirs,
                $mine,
                "faq's eyebrow defaults diverged from {$sibling}'s — they measured "
                . 'byte-identical, so a difference is drift rather than a decision'
            );
        }

        // And the values actually reach the CSS, not only the schema.
        $base = $this->baseTier();
        $this->assertMatchesRegularExpression('/\.faq__eyebrow\{[^}]*font-size:0\.8125rem;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__eyebrow\{[^}]*text-transform:uppercase;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__eyebrow\{[^}]*background:var\(--color-surface-accent\);/', $base);
    }

    /**
     * THE PADDINGS ARE PER-SIDE, AND THAT IS AN ENGINE CONSTRAINT RATHER THAN A STYLE
     * CHOICE — worth pinning because the obvious edit undoes it silently.
     *
     * v1 wrote `padding: var(--space-md) var(--space-lg)`. The shorthand cannot carry an
     * `@token` reference: `_pp_udc_validate_param()` reads the whole value as ONE
     * reference name, so `@space-md @space-lg` is refused as an unregistered token, and
     * `0 @space-lg @space-md` fails the literal-length grammar. Measured through
     * `pp_udc_validate_map()` before the schema was written.
     *
     * The alternative — inlining `16px 32px` — would paint identically today and freeze
     * the band against a `--space-*` retune, which is the drift #972 removed from the
     * button presets for the same reason. So the per-side form is the routing, and the
     * shipped `button` preset does exactly this with `@btn-padding-y` / `@btn-padding-x`.
     */
    public function testEveryPaddingRoutesATokenPerSideRatherThanFreezingAShorthand(): void
    {
        $base = $this->baseTier();
        $this->assertMatchesRegularExpression('/\.faq__question\{[^}]*padding-top:var\(--space-md\);/', $base);
        $this->assertMatchesRegularExpression('/\.faq__question\{[^}]*padding-left:var\(--space-lg\);/', $base);
        $this->assertMatchesRegularExpression('/\.faq__answer\{[^}]*padding-top:0;/', $base);
        $this->assertMatchesRegularExpression('/\.faq__answer\{[^}]*padding-bottom:var\(--space-md\);/', $base);

        $this->assertStringNotContainsString('padding:16px', $this->css, 'a frozen literal would not follow a --space-* retune');
        $this->assertStringNotContainsString('padding:32px', $this->css);
    }

    /**
     * NO ROLE RESTATES A VALUE THAT COMES FROM SOMEWHERE ELSE.
     *
     * The global-value lesson, swept rather than spot-checked: a role default is emitted
     * UNLAYERED, above the whole v1 stylesheet, so restating a global value changes its
     * CASCADE POSITION even at an identical value — and then beats that rule's `:hover`,
     * its `:focus-visible` and its media-scoped twins too. The three faq would have been
     * most likely to restate are the answer link's colour (base.css gives every anchor
     * exactly what v1 measured), the summary's focus ring, and the `text-decoration` on
     * either.
     */
    public function testNoRoleRestatesAGlobalValue(): void
    {
        foreach (['outline', 'text-decoration', 'cursor', 'list-style', 'min-height'] as $global) {
            $this->assertStringNotContainsString(
                $global,
                $this->css,
                "{$global} belongs to base.css or to faq's structural block; a role default "
                . 'would be emitted unlayered and beat it in every state'
            );
        }

        // There is no role for the answer's links at all — measured, they render exactly
        // what base.css gives every anchor, and a role block here would outrank the shared
        // premium button rules for an author-written <a class="btn"> inside an answer
        // (#545, reintroduced through a role selector — the trap cta recorded at #1026).
        $this->assertArrayNotHasKey('answer-link', pp_udc_component_roles('faq'));
        $this->assertStringNotContainsString('.faq__answer a', $this->css);
    }

    /**
     * THE ROLE ROSTER ITSELF, asserted once so a silently DROPPED role cannot hide behind
     * the per-role tests above — each of those would simply stop running.
     */
    public function testEveryDeclaredRoleEmitsSomethingExceptTheOnesThatShouldNot(): void
    {
        $roles = pp_udc_component_roles('faq');
        $this->assertSame(
            ['_band', 'eyebrow', 'heading', 'heading-accent', 'list', 'item', 'question', 'question-open', 'answer', 'empty'],
            array_keys($roles),
            'the role roster changed — every claim in this file is scoped to it'
        );

        foreach ($roles as $name => $definition) {
            $selector = (string) ($definition['selector'] ?? '');
            if ($selector === '') {
                continue; // `_band` is asserted by its own test above.
            }
            $this->assertStringContainsString(
                $selector . '{',
                $this->css,
                "{$name} declares defaults that never reach the page — check the selector "
                . 'against the compile-time gate in lib/udc.php'
            );
        }
    }
}
