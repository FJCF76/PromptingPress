<?php
/**
 * tests/UnifiedCssGrammarTest.php
 *
 * THE CONSOLIDATION PIN (v2 BUILD-SPEC §3.3).
 *
 * Before v2, lib/apply.php carried SIX independent, mutually-inconsistent unit
 * lists. Each had grown on its own; none was derived from any other; and no test
 * anywhere compared them, which is precisely why they drifted apart far enough
 * that the same literal was legal in one slot type and illegal in its sibling.
 *
 * This file is the test that could not have existed before: it asserts the six
 * now share ONE unit set and ONE number body, and it enumerates, quirk by quirk,
 * the behaviour that deliberately DIED so the change is documented by executable
 * evidence rather than by a changelog sentence.
 *
 * The owner's directive is no-compat: these are not deprecations. A value that
 * this file marks dead is refused, and a value it marks newly-live is accepted,
 * on every surface that reaches the shared engine at once.
 */

use PHPUnit\Framework\TestCase;

final class UnifiedCssGrammarTest extends TestCase
{
    /**
     * The unit set is DATA, in one place, and every dimension-bearing grammar
     * reads it. If a future change adds a unit, it lands here once and reaches
     * all six; if someone re-introduces a private list, this test does not
     * notice — but every per-quirk test below does.
     */
    public function testOneUnitSetOwnsEveryDimensionBearingGrammar(): void
    {
        $units = pp_css_length_units();

        // The v1 set, still accepted — this is a widening, not a replacement.
        foreach (['rem', 'px', 'em', 'vw', 'vh'] as $legacy) {
            $this->assertContains($legacy, $units, "v1 unit $legacy must survive the consolidation");
        }
        // The units v1 rejected while the theme's own base.css used one of them:
        // `--measure-body: 70ch` shipped in the stylesheet while the validator
        // refused `ch` from any author. That contradiction is what #891 reported.
        foreach (['ch', 'ex', 'vmin', 'vmax', 'lh', 'rlh'] as $added) {
            $this->assertContains($added, $units, "v2 unit $added must be accepted");
        }

        // Every unit resolves through the ONE entry point, for the plain
        // `length` type and inside calc()/clamp() alike. v1's calc() body had a
        // SEPARATE list that omitted `%`; a value could validate bare and fail
        // inside calc(), for no reason an author could discover.
        foreach ($units as $unit) {
            $this->assertTrue(
                _pp_validate_length("4{$unit}"),
                "bare 4{$unit} must validate as a length"
            );
            $this->assertTrue(
                _pp_validate_length("calc(4{$unit} + 2{$unit})"),
                "calc() must accept {$unit} — v1's inner list was a second, divergent set"
            );
            $this->assertTrue(
                _pp_validate_length("clamp(1{$unit}, 5vw, 9{$unit})"),
                "clamp() must accept {$unit}"
            );
        }
    }

    /**
     * DEAD QUIRK 1 of 6 — box-shadow's private `px|rem` pair.
     *
     * A shadow could only ever be expressed in two of the units every other
     * grammar accepted, so `0 0 2em rgba(0,0,0,.2)` was refused on a shadow slot
     * and accepted one slot over.
     */
    public function testShadowNoLongerHasItsOwnTwoUnitList(): void
    {
        $this->assertTrue(_pp_validate_shadow('0 0 2em rgba(0,0,0,0.2)'));
        $this->assertTrue(_pp_validate_shadow('0 0.5ch 1ch #000000'));
        $this->assertTrue(_pp_validate_shadow('0 2vmin 4vmin #000000'));
        // Still px/rem, of course — a widening never costs the old spellings.
        $this->assertTrue(_pp_validate_shadow('0 4px 12px rgba(0,0,0,0.1)'));
        $this->assertTrue(_pp_validate_shadow('0 0.25rem 0.75rem #000000'));
    }

    /**
     * DEAD QUIRK 2 of 6 — `position` was the only length-bearing grammar in the
     * file WITHOUT `vw`/`vh`.
     *
     * `--hero-image-position: 20vw 30vh` was refused while a gradient stop at
     * `20vw` was fine, and both were "a length with a viewport unit".
     */
    public function testPositionNoLongerOmitsViewportUnits(): void
    {
        $this->assertTrue(_pp_validate_position('20vw 30vh'));
        $this->assertTrue(_pp_validate_position('center 10vmax'));
        $this->assertTrue(_pp_validate_position('5ch'));
        // The v1 spellings are untouched.
        $this->assertTrue(_pp_validate_position('20% 80%'));
        $this->assertTrue(_pp_validate_position('top left'));
        $this->assertTrue(_pp_validate_position('-5rem center'));
    }

    /**
     * DEAD QUIRK 3 of 6 — gradient stops refused negatives.
     *
     * The stop-position pattern simply had no `-?`. That is not a CSS fact:
     * `linear-gradient(red -20%, blue)` is valid and is the standard way to push
     * a stop off the painted box so the visible ramp starts mid-transition.
     */
    public function testGradientStopsNoLongerRefuseNegatives(): void
    {
        $this->assertTrue(_pp_validate_gradient('linear-gradient(#ff0000 -20%, #0000ff 100%)'));
        $this->assertTrue(_pp_validate_gradient('linear-gradient(to right, #fff -2rem, #000 120%)'));
        // And the shared unit set reaches stops too.
        $this->assertTrue(_pp_validate_gradient('linear-gradient(#fff 0, #000 40ch)'));
    }

    /**
     * DEAD QUIRK 4 of 6 — the radial `at <position>` clause took percentages and
     * nothing else. Pinned in full by
     * ApplyTest::testRadialAtClauseNowAcceptsLengthsLikeEveryOtherPositionGrammar;
     * asserted here so the six-quirk ledger is complete in one file.
     */
    public function testRadialAtClauseJoinsTheSharedPositionGrammar(): void
    {
        $this->assertTrue(_pp_validate_gradient('radial-gradient(at 10px 20px, #fff, #000)'));
        $this->assertTrue(_pp_validate_gradient('radial-gradient(circle at 2rem 4rem, #fff, #000)'));
    }

    /**
     * DEAD QUIRK 5 of 6 — the loose `[\d.]+` number bodies.
     *
     * THIS IS THE ONE NARROWING THAT COSTS SOMETHING, so it is stated plainly:
     * `shadow` and `position` used a number body that `length` was hardened away
     * from in #151. The consequence was that `1.2.3px` was REFUSED as a length
     * and ACCEPTED as a shadow offset and as a position token — persisted, then
     * silently dropped by the browser. That is the accepted-but-dead class this
     * whole engine exists to reject, surviving inside the engine itself.
     *
     * Both now use the shared hardened body, so all three agree.
     */
    public function testTheLooseNumberBodiesAreGoneFromShadowAndPosition(): void
    {
        // Multiple dots — the shape #151 hardened `length` against.
        $this->assertFalse(_pp_validate_length('1.2.3px'));
        $this->assertFalse(_pp_validate_shadow('1.2.3px 2px #000000'), 'shadow used to ACCEPT this');
        $this->assertFalse(_pp_validate_position('1.2.3px'), 'position used to ACCEPT this');

        // Whitespace between number and unit — CSS forbids it.
        $this->assertFalse(_pp_validate_length('1.2 rem'));
        $this->assertFalse(_pp_validate_position('1.2 rem'));

        // A unit with no digit.
        $this->assertFalse(_pp_validate_length('.em'));
        $this->assertFalse(_pp_validate_shadow('.em 2px #000000'));
        $this->assertFalse(_pp_validate_position('.em'));

        // The leading-dot form stays legal everywhere, as it always was for length.
        $this->assertTrue(_pp_validate_length('.5rem'));
        $this->assertTrue(_pp_validate_shadow('0 .5rem 1rem #000000'));
        $this->assertTrue(_pp_validate_position('.5rem center'));
    }

    /**
     * DEAD QUIRK 6 of 6 — `duration` allowed whitespace before its unit.
     *
     * `1.5 s` validated here while `1.2 rem` had been refused as a length since
     * #151. Time units are their own closed set (a duration is not a length) but
     * the NUMBER is the same number, so it now uses the same body.
     *
     * REACH, enumerated before shipping: no component schema declares a
     * `duration`-typed slot, no design token is `duration`-typed, and no shipped
     * value carries a space before a time unit (`--transition: 150ms ease` is
     * type `raw` and never reaches this validator). Zero shipped values newly
     * rejected.
     */
    public function testDurationJoinsTheSharedNumberBody(): void
    {
        $this->assertFalse(_pp_validate_duration('1.5 s'), 'duration used to ACCEPT the space');
        $this->assertFalse(_pp_validate_duration('300 ms'));
        $this->assertFalse(_pp_validate_duration('1.2.3s'));
        $this->assertFalse(_pp_validate_duration('.s'));

        $this->assertTrue(_pp_validate_duration('300ms'));
        $this->assertTrue(_pp_validate_duration('0.3s'));
        $this->assertTrue(_pp_validate_duration('.3s'));
    }

    /**
     * The differences that REMAIN are per-property facts of CSS, expressed as
     * options rather than as divergent regexes. Pinning them stops a later
     * "finish the consolidation" pass from erasing a real constraint.
     */
    public function testTheRemainingDifferencesAreCssFactsNotLeftovers(): void
    {
        // box-shadow lengths are <length>, never <length-percentage>.
        $this->assertFalse(_pp_validate_shadow('0 50% 4px #000000'));
        // …while a position IS a <length-percentage>.
        $this->assertTrue(_pp_validate_position('50% 50%'));

        // Blur and spread cannot be negative; offsets can.
        $this->assertTrue(_pp_validate_shadow('-2px -4px 6px #000000'));
        $this->assertFalse(_pp_validate_shadow('2px 4px -6px #000000'));

        // calc()/clamp() stay with the `length` family. Consolidating six unit
        // lists is the directive; handing shadow and position a function grammar
        // they never had would be inventing capability under cover of a cleanup.
        $this->assertTrue(_pp_validate_length('calc(100% - 2rem)'));
        $this->assertFalse(_pp_validate_shadow('0 calc(2px + 2px) 4px #000000'));
        $this->assertFalse(_pp_validate_position('calc(50% - 10px)'));
    }

    /**
     * The injection gate is UNCHANGED by the consolidation and still runs ahead
     * of every type. A widening of the unit set must never widen the reject set,
     * so the bypass shapes are re-asserted against the new core directly.
     */
    public function testTheInjectionGateSurvivesTheConsolidationIntact(): void
    {
        foreach ([
            'var(--attacker)',
            'calc(var(--x) + 1rem)',
            'url(evil)',
            'env(safe-area-inset-top)',
            'max(1rem, 2rem)',
            'expression(1)',
        ] as $hostile) {
            $this->assertFalse(_pp_validate_length($hostile), "length must refuse $hostile");
            $this->assertFalse(_pp_validate_position($hostile), "position must refuse $hostile");
            $this->assertFalse(_pp_validate_shadow($hostile . ' #000000'), "shadow must refuse $hostile");
        }

        // The alpha-run rule is what blocks these: every alphabetic run inside
        // calc()/clamp() must be a unit word AND sit directly behind a numeric
        // operand. Adding units lengthens the allowlist; it does not loosen the
        // rule, so a bare unit word with no operand is still refused.
        $this->assertFalse(_pp_validate_length('calc(ch)'));
        $this->assertFalse(_pp_validate_length('calc((vmin) + 1px)'));
        $this->assertFalse(_pp_validate_length('clamp((rlh), 1px, 2px)'));

        // And the shared reject set still governs every type ahead of the switch.
        $this->assertNotNull(_pp_forbidden_css_construct('1rem; color:red'));
        $this->assertNotNull(_pp_forbidden_css_construct('1rem /* x'));
    }

    /**
     * THE MARKDOWN COPY IS PINNED TOO.
     *
     * This change states the unit set in `ai-instructions/style-component.md` as
     * literal prose — a markdown file cannot call pp_css_length_units() at
     * runtime. That is a FIFTH copy, added by the very change whose commentary
     * condemns v1 for keeping four of them with nothing pinning any to the
     * validator. A copy with a test is a cache; a copy without one is a rumour.
     */
    public function testTheAuthoringDocStatesExactlyTheOwnersUnitSet(): void
    {
        $doc = file_get_contents(dirname(__DIR__) . '/ai-instructions/style-component.md');

        foreach (pp_css_length_units() as $unit) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($unit, '/') . '\b/',
                $doc,
                "style-component.md must name the accepted unit {$unit}"
            );
        }

        // And it must not still be teaching a unit the grammar refuses.
        foreach (['pt', 'cm', 'in', 'mm', 'pc'] as $absent) {
            $this->assertNotContains($absent, pp_css_length_units(), "fixture guard: {$absent} is not accepted");
        }

        // The per-property exceptions have to be stated, or the "one grammar"
        // sentence over-claims: shadow takes no percentage, and only the length
        // family takes calc()/clamp().
        $this->assertStringContainsString('take NO percentage', $doc);
        $this->assertStringContainsString('`length` and `length-or-none` only', $doc);
    }

    /**
     * v1 stated the accepted unit set in four hand-maintained copies with no test
     * pinning any of them to the validator — and the runtime system prompt never
     * stated it AT ALL, so the authoring model was told what was forbidden and
     * never which units were legal (#891 is what that costs). One owner now.
     */
    public function testTheAiFacingGrammarSummaryIsDerivedFromTheOwner(): void
    {
        $summary = pp_css_grammar_summary();

        foreach (pp_css_length_units() as $unit) {
            $this->assertStringContainsString(
                $unit,
                $summary,
                "the summary the model reads must name every accepted unit ($unit)"
            );
        }
        $this->assertStringContainsString('%', $summary);

        // The refusal messages are built from the same summary, so a unit can
        // never be accepted by the grammar while the error text denies it.
        $error = _pp_validate_token_value('9zz', 'length');
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('invalid_length', $error->get_error_code());
        foreach (pp_css_length_units() as $unit) {
            $this->assertStringContainsString($unit, $error->get_error_message());
        }
    }
}
