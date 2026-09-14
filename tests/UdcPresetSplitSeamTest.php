<?php
/**
 * tests/UdcPresetSplitSeamTest.php
 *
 * #974 — the branches the preset registry's shape left unprovable.
 *
 * WHAT THE ISSUE IS ABOUT. `pp_udc_presets()` is a hardcoded static with three
 * theme-shipped entries and no injection point, and both halves of the T2
 * write/emit split resolve a preset BY NAME. So a test cannot construct a preset
 * that exercises the branches the shipped three never reach, and the file that
 * carries the gap says so in its own words: giving the write gate a narrower
 * second copy of the split leaves the suite green.
 *
 * WHAT THIS FILE DOES ABOUT IT, AND WHAT IT DELIBERATELY DOES NOT. It does NOT add
 * a registry seam. A `apply_filters('pp_udc_presets', …)` hook would be public
 * extension surface that lets any plugin inject into a validated write path, and
 * the issue's own reasoning is that it belongs with Sprint 2's site-stored custom
 * presets, whose storage contract will decide the shape. Adding one here would
 * pre-empt that ruling to make a test convenient.
 *
 * Two cheaper things close three of the four branches with no production change:
 *
 *   1. DIRECT CALLS. Two of the branches take an ARRAY, not a name —
 *      _pp_udc_preset_fragment() at group grain, and _pp_udc_validate_group_map()'s
 *      nested-preset refusal, which takes $allow_preset as a parameter. Both are
 *      reachable today; nobody had called them.
 *
 *   2. A SOURCE INVARIANT for the one branch that genuinely needs a seam: that the
 *      intersect predicate has exactly ONE definition and that both halves call it.
 *      That is weaker than a behavioural test and stronger than the code review
 *      currently holding the property up — it kills the specific mutation the issue
 *      describes (a second, narrower copy of the split in the gate), which is
 *      clause 4 of the A3 sub-ruling: "the write gate and the emitter intersect
 *      through one predicate, so what the envelope reports as skipped is what the
 *      page omits."
 */

use PHPUnit\Framework\TestCase;

class UdcPresetSplitSeamTest extends TestCase
{
    // ── 1. The branches that take an array, not a name ───────────────────────

    /** A group-grain preset yields its `udc` fragment directly. */
    public function testAGroupGrainPresetFragmentIsReturnedAtItsOwnGrain(): void
    {
        $preset = ['grain' => 'shadow', 'udc' => ['shadow' => ['box' => '0 1px 2px #0003']]];

        $this->assertSame(
            ['shadow' => ['box' => '0 1px 2px #0003']],
            _pp_udc_preset_fragment($preset, 'group')
        );
    }

    /** And a grain mismatch yields nothing, rather than the wrong shape. */
    public function testAGrainMismatchYieldsNoFragment(): void
    {
        $preset = ['grain' => 'shadow', 'udc' => ['shadow' => ['box' => '0 1px 2px #0003']]];

        $this->assertNull(_pp_udc_preset_fragment($preset, 'role'));
    }

    /**
     * A preset referenced INSIDE a group map is refused: presets resolve one level
     * only, and the refusal reads the same at every grain.
     */
    public function testANestedPresetInsideAGroupMapIsRefused(): void
    {
        $error = _pp_udc_validate_group_map(
            'testimonials',
            'quote',
            'typography',
            [PP_UDC_PRESET_KEY => 'button', 'size' => '19px'],
            ['typography'],
            [],
            '',
            false
        );

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('one level only', $error->get_error_message());
    }

    // ── 2. The intersect predicate has ONE definition ────────────────────────

    /**
     * A3 sub-ruling clause 4, enforced rather than reviewed.
     *
     * "The write gate and the emitter intersect through one predicate, so what the
     * envelope reports as skipped is what the page omits." Nothing enforced that.
     * The mutation the issue names — give the gate its own narrower copy of the
     * split — leaves every behavioural test green, because no fixture can build a
     * preset whose groups the two copies would disagree about.
     *
     * THIS COUNTS THE INTERSECT'S SHAPE, NOT ITS NAME, and the difference is the
     * whole point. Asserting that only one function is CALLED
     * `_pp_udc_split_preset_by_permitted` proves nothing: PHP fatals on a duplicate
     * definition anyway, so that assertion can never go red. A second copy arrives
     * under a DIFFERENT name, and what gives it away is that it has to build the
     * same `['applied' => …, 'skipped' => …]` return. So the pin counts those
     * returns and expects exactly one.
     *
     * Mutation-proven: adding a second function that returns that shape turns this
     * red, and so does re-pointing the gate at it (the test below).
     *
     * Read as SOURCE because that is the only place the property is visible — it is
     * a claim about how many definitions exist, and a runtime test can only observe
     * the one it happens to call.
     */
    public function testOnlyOnePlaceBuildsTheAppliedSkippedIntersect(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);

        $this->assertSame(
            1,
            preg_match_all('/return\s*\[\s*[\x27"]applied[\x27"]\s*=>/', $source),
            'a second place building the intersect is how the gate and the emitter start disagreeing'
        );
    }

    /** And both halves of the split actually call it. */
    public function testBothTheWriteGateAndTheEmitterCallTheSharedIntersect(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);

        foreach (
            [
                '_pp_udc_validate_preset_reference' => 'the write gate',
                '_pp_udc_preset_sources'            => 'the emitter',
            ] as $function => $label
        ) {
            $start = strpos($source, 'function ' . $function . '(');
            $this->assertNotFalse($start, $function . ' must exist');

            // Bound the search at the next top-level function so a call in a LATER
            // function cannot satisfy this assertion on behalf of this one.
            $next = strpos($source, "\nfunction ", $start + 1);
            $body = substr($source, $start, $next === false ? null : $next - $start);

            $this->assertStringContainsString(
                '_pp_udc_split_preset_by_permitted(',
                $body,
                $label . ' must intersect through the shared predicate, not its own copy'
            );
        }
    }

    /**
     * The disclosure reads the same predicate too, so what the envelope names as
     * skipped is what the other two computed.
     */
    public function testTheSkippedGroupsDisclosureUsesTheSameIntersect(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);

        $start = strpos($source, 'function pp_udc_composition_findings(');
        $this->assertNotFalse($start);
        $next = strpos($source, "\nfunction ", $start + 1);
        $body = substr($source, $start, $next === false ? null : $next - $start);

        $this->assertStringContainsString('_pp_udc_split_preset_by_permitted(', $body);
    }
}
