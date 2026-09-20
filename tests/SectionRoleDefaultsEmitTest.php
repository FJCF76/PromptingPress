<?php
/**
 * THE SHIPPED DEFAULTS, ASSERTED AS CSS RATHER THAN AS JSON (#1023).
 *
 * WHY THIS FILE EXISTS. Section's rebuild moved ~60 declarations out of the stylesheet
 * and into 19 role defaults, and every claim made about that move — "this is the value
 * v1 rendered", "these two are phone-only", "this one declines to default" — was pinned
 * against the schema's JSON TEXT. The ship coverage audit found that
 * `pp_udc_component_defaults_css('section')` was called exactly once in the entire
 * suite, and only to assert an ABSENCE. So the whole port was enforced one layer above
 * the thing it is a claim about.
 *
 * That gap is not theoretical. A schema key can be correct while the emission is wrong:
 * a breakpoint map could gain a base-tier fallback it should not have, a `@token` could
 * stop resolving, a role could silently emit nothing because its selector changed. Every
 * one of those passes a JSON-text assertion and ships a visibly different band.
 *
 * Three of the four defects the #1023 review caught were of exactly this shape — a
 * default that was right in the schema and wrong (or missing) on the page. `panel-heading`
 * shipped its correction with ZERO tests: the role name appeared in no test file in the
 * repo, so deleting the default again would reproduce the reported flush-heading bug with
 * the whole suite green.
 *
 * WHAT THIS FILE IS NOT. It is not a golden-file snapshot of the entire block. A test
 * that asserts the exact bytes of 60 declarations fails on every legitimate reordering
 * and teaches the next author to regenerate it without reading it. Each assertion here
 * names ONE declaration and says which claim it is defending.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class SectionRoleDefaultsEmitTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = pp_udc_component_defaults_css('section');
        $this->assertNotSame('', $this->css, 'section emitted no role defaults at all');
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
     * THE MEASURE, ON ALL FOUR ELEMENTS THE v1 WRAPPER CAPPED.
     *
     * v1 declared `.section__body { max-width: var(--section-body-measure, 40rem) }` and
     * that wrapper held the header block, the prose and the trust strip. The rebuild
     * replaced it per child. The first cut replaced ONE of the three, which is how the
     * heading and subheading came to run the full container above 640px — so these four
     * are asserted together, because splitting them is precisely how the gap opened.
     */
    public function testEveryChildOfTheRetiredWrapperCarriesTheMeasureItRendered(): void
    {
        foreach (['title', 'subheading', 'content', 'inline-items'] as $el) {
            $this->assertMatchesRegularExpression(
                '/\.section__' . preg_quote($el, '/') . '\{[^}]*max-width:40rem;/',
                $this->baseTier(),
                "the {$el} lost the 40rem measure v1's .section__body gave it"
            );
        }
    }

    /**
     * THE TWO MARGINS THE REBUILD DROPPED WITH THE RULES THAT CARRIED THEM.
     *
     * v1's `.section__panel-heading` rule set `color` AND `margin-bottom` together; the
     * first cut read the colour (now supplied by `panel` inheritance) and dropped the
     * margin with the rule. base.css zeroes every margin, so an absent emission is a
     * heading sitting flush on the panel body. Same story one element down for the CTA.
     *
     * Asserted on the EMITTED declaration and not on the schema, because the schema half
     * is already pinned elsewhere and the schema half is not what broke.
     */
    public function testThePanelKeepsTheTwoSeparationsV1sRulesGaveIt(): void
    {
        $this->assertStringContainsString(
            '.section__panel-heading{margin-bottom:var(--space-md);}',
            $this->baseTier(),
            'the panel heading renders flush against the panel body without this'
        );
        $this->assertStringContainsString(
            '.section__panel-cta{margin-top:var(--space-sm);}',
            $this->baseTier(),
            'the panel CTA loses its separation from the list above it'
        );
    }

    /**
     * THE COLUMN GUTTER. The `columns` role exists only because v1's
     * `.section__grid { gap }` is a designable value that may not stay in a v2
     * stylesheet. If the role stopped emitting, the two-column layouts would butt
     * together and nothing else in the suite would notice.
     */
    public function testTheColumnGutterSurvivedTheMoveOutOfTheStylesheet(): void
    {
        $this->assertStringContainsString('.section__grid{gap:var(--space-xl);}', $this->baseTier());
        $this->assertStringContainsString('.section__grid{gap:var(--space-lg);}', $this->phoneTier());

        // And the stylesheet must not have kept a copy — that is the boundary this move
        // exists to respect, asserted here rather than only in the JS lint.
        $sheet = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $start  = strpos($sheet, 'COMPONENT: section');
        $end    = strpos($sheet, '/* ======', $start + 10);
        $block  = substr($sheet, $start, $end - $start);
        $this->assertStringNotContainsString('gap:', $block, 'the section block re-grew a gap declaration');
    }

    /**
     * THE MEDIA ROLE'S SHIPPED DEFAULTS. The authored path is well covered; the defaults
     * were not, so `.section__image` silently losing its corner radius was green.
     * `object-position` is the parameter this rebuild added to the engine, so its default
     * emitting is the narrowest possible proof that the addition reaches a real element.
     */
    public function testTheMediaRoleShipsItsRadiusAndFocalDefaults(): void
    {
        $this->assertStringContainsString(
            '.section__image{border-radius:var(--radius);aspect-ratio:auto;object-position:center;}',
            $this->baseTier()
        );
    }

    /**
     * THE PANEL'S BORDER IS ZERO-WIDTH, AND THAT IS THE FALLBACK THE SHIPPED STARTER
     * RELIES ON. v1's rule was `border: var(--section-panel-border-width, 0) solid
     * var(--section-panel-border-color, transparent)` and the starter set only the colour,
     * so no border painted. #1023 found the first `udc` conversion carrying `1px solid`
     * across and removed it — but the test that pins that reads the SEED'S STORED MAP.
     * Changing the ROLE default from `0` to `1px` paints the border that test forbids and
     * leaves it green, so the fallback is pinned here, where it actually lives.
     */
    public function testThePanelBorderDefaultsToNothingBecauseThatIsWhatV1Painted(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__panel\{[^}]*border-width:0;[^}]*border-color:transparent;/',
            $this->baseTier()
        );
    }

    /**
     * THE PHONE-ONLY PAIR, AND THE HALF OF THAT CLAIM THAT ONLY CSS CAN SETTLE.
     *
     * v1 declared the paired-row label's size and tracking, and the value's weight, ONLY
     * inside `@media (max-width: 767px)`. The roles port them as `{"p": ...}` maps, and
     * the schema says naming `d` would reintroduce a desktop declaration v1 never had.
     * That is an EMISSION claim. Asserting the JSON has no `d` key proves the input; only
     * reading the base tier proves the output — so both directions are asserted.
     */
    public function testThePairedRowTypeStepsAreEmittedOnPhonesAndNowhereElse(): void
    {
        $phone = $this->phoneTier();
        $this->assertStringContainsString(
            '.section__panel-row-label{font-size:0.8125rem;letter-spacing:0.04em;}',
            $phone
        );
        $this->assertStringContainsString('.section__panel-row-value{font-weight:600;', $phone);

        $base = $this->baseTier();
        $this->assertStringNotContainsString('.section__panel-row-label{', $base,
            'the label gained a desktop declaration v1 never had');
        $this->assertDoesNotMatchRegularExpression(
            '/\.section__panel-row-value\{[^}]*font-weight:/', $base,
            'the value gained a desktop weight v1 never had');
    }

    /**
     * THE ROLES THAT DECLINE TO DEFAULT MUST EMIT NOTHING AT ALL.
     *
     * `inline-items` declares no typography because v1's declaration fell back to
     * `inherit` — a value a role cannot carry forward, so the faithful port is silence.
     * `body-link` declares nothing because a role block is emitted UNLAYERED and would
     * outrank the shared `.btn` rule, repainting an author's `<a class="btn">` (the #545
     * trap). Both are claims that a selector is ABSENT, which is the one thing a schema
     * assertion genuinely cannot check.
     */
    /**
     * THE PROSE COLUMN'S OWN WIDTH, and the typography that must NOT come with it (#1032).
     *
     * #1023 replaced v1's `max-width: var(--section-body-measure, 40rem)` on
     * `.section__body` with a cap on each CHILD. Right for the three widths; wrong for the
     * containing block, because `.section__inline-items` is `width: fit-content; margin: 0
     * auto` and therefore centres within the WRAPPER. Measured in Chromium with the cap
     * absent, as the drift between the strip's centre and `.section__content`'s: 224px at
     * 1600 and at 1280, 32px at 768, 0 at 375.
     *
     * THE SECOND ASSERTION IS THE ONE THAT KEEPS RULING B TRUE. The obvious fix — point
     * the existing `body` role at `.section__body` — reproduces the geometry and feeds the
     * strip `body`'s 17.04px/430, when the strip deliberately declares no type default so
     * it inherits 16px/400 from this wrapper. A separate sizing-only role is what gets the
     * containing block without the typography, and a `typography` default appearing here
     * later would silently restyle the strip on every section that has one.
     */
    public function testTheProseColumnCarriesItsMeasureAndNoTypography(): void
    {
        $this->assertStringContainsString(
            '.section__body{max-width:40rem;}',
            $this->css,
            'the body-wrap measure is what the trust strip centres within — without it the '
            . 'strip centres on the band while the prose stays 640px and left-aligned (#1032)'
        );

        $role = pp_udc_component_roles('section')['body-wrap'] ?? null;
        $this->assertNotNull($role, 'section must declare body-wrap');
        $this->assertSame(['sizing'], $role['groups'] ?? null,
            'body-wrap is geometry only: typography here feeds the trust strip, which is '
            . 'exactly what #1023 ruling B kept off this element');
        $this->assertArrayNotHasKey('typography', $role['defaults'] ?? [],
            'a typography default on the prose wrapper restyles the inline-items strip, '
            . 'which declares none precisely so it inherits 16px/400 from here');
    }

    /**
     * THE COST OF THE WRAPPER CAP, PINNED BECAUSE IT IS A TRAP AND NOT A FEATURE (#1032).
     *
     * A wrapper `max-width` is a CEILING for everything inside it, and the children carry
     * caps of their own since #1023. So the two interact in one direction only:
     *
     *     NARROWING  body-wrap alone narrows all three children  (tighter cap wins)
     *     WIDENING   body alone does NOTHING                     (the wrapper clamps it)
     *
     * Measured in Chromium at 1600 with the documented four-role widening recipe
     * (`heading`/`subheading`/`body`/`inline-items` all at 46rem): the prose renders at
     * 640px, not 736px. The write is ACCEPTED and nothing reports it — a declared
     * authoring input cancelled by another mechanism, which is the I35 shape. v1 never had
     * it, because v1 capped the wrapper and gave the children no caps at all, so one slot
     * moved everything.
     *
     * This is recorded rather than fixed: the engine has no finding for "a child's
     * authored max-width is exceeded by its wrapper's cap", and adding one is a disclosure
     * ruling rather than part of restoring a measure. Filed separately. What this test
     * does is make the default the trap depends on IMPOSSIBLE TO MOVE SILENTLY — if
     * `body-wrap`'s 40rem or any child's 40rem changes, the recipe in
     * docs/howto-migrate-a-section-band-to-v2.md stops being true and this fails.
     */
    public function testTheWrapperCapAndTheChildCapsAgreeOnTheValueTheDocsTeach(): void
    {
        $roles = pp_udc_component_roles('section');
        $this->assertSame(
            '40rem',
            $roles['body-wrap']['defaults']['sizing']['max-width'] ?? null,
            'the wrapper measure moved — the five-role widening recipe in the how-to is '
            . 'derived from this value agreeing with the children below'
        );
        foreach (['heading', 'subheading', 'body', 'inline-items'] as $child) {
            $this->assertSame(
                '40rem',
                $roles[$child]['defaults']['sizing']['max-width'] ?? null,
                "{$child}'s measure moved away from the wrapper's, so widening and narrowing "
                . 'no longer behave as the how-to describes'
            );
        }
    }

    public function testTheRolesThatDeclineToDefaultEmitNothing(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.section__inline-items\{[^}]*font-size:/', $this->css,
            'inline-items gained a type default and no longer inherits as v1 did');
        $this->assertStringNotContainsString('.section__content a{', $this->css,
            'body-link gained a default and will repaint author .btn anchors (#545)');
    }

    /**
     * THE BAND ROOT IS EMITTED INTO `pp-zero` AND NOWHERE ELSE.
     *
     * The layer is the whole reason a band's own defaults lose to the design system while
     * an authored value beats it. If these declarations ever escaped into the unlayered
     * tier they would outrank every v1 component stylesheet on the page. The
     * `background-position` beside them is deliberate and load-bearing: it is the image
     * focal-point default (v1's `--section-bg-position`), it sits under `:where()` at zero
     * specificity, and removing it would move every authored background image to top-left.
     */
    public function testTheBandRootDefaultsStayInTheZeroLayer(): void
    {
        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="section"\]\)\{[^}]*background:transparent;background-position:center;\}/',
            $this->css
        );
        $this->assertStringNotContainsString(
            '[data-pp-component="section"]{padding-top:', $this->css,
            'the band root escaped pp-zero into the unlayered tier'
        );
    }
}
