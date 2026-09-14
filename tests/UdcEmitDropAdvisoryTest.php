<?php
/**
 * tests/UdcEmitDropAdvisoryTest.php
 *
 * D3 — a stored `udc` value the emitter discards at render is no longer silent.
 *
 * THE DEFECT, STATED ONCE. `_pp_udc_place()` and `pp_udc_compile_band()` discard an
 * authored declaration at sixteen branches: an unresolvable `@token`, a value that
 * no longer satisfies its parameter's grammar, a parameter or group the vocabulary
 * no longer declares, an unknown breakpoint, a single-valued parameter holding a
 * map, an unusable band id or role selector. Exactly ONE of those had a consumer
 * (a deleted background attachment, Check 8c). The other fifteen reached no
 * channel at all — no envelope finding, no advisory, not even an error_log — so
 * the author's value sat in storage, the page did not paint it, and nothing
 * anywhere said so. That is the reported-success-without-effect class I35 forbids.
 *
 * WHY THE AUTHOR CAN BE IN THIS STATE, given the write gate refuses most of these
 * shapes: the write gate is not the only door. Raw meta writes, compositions
 * written before a rule existed, a renamed parameter, and restore_composition
 * (which reports findings without blocking, #233) all reach the emitter directly.
 * Every fixture below is therefore seeded as STORED data, because stored-only is
 * the whole population this advisory exists for.
 *
 * WHAT THIS FILE HAS TO PROVE:
 *
 *   1. Each drop class is named, with a reason an operator can act on.
 *   2. A healthy composition reports NOTHING. An advisory that cries wolf gets
 *      acknowledged into silence, and then the real one is invisible too.
 *   3. The emitter and the advisory cannot disagree — the advisory reads the
 *      emitter's own ledger rather than re-deriving the conditions, so a value it
 *      names is a value the emitted CSS really lacks.
 *   4. It is bounded on both axes, and says so when it truncates.
 *   5. It never reports its own failure as health.
 */

use PHPUnit\Framework\TestCase;

class UdcEmitDropAdvisoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 'next_id' is part of the shared store contract: a suite that replaces the
        // store without it leaves the next post-creating test with a null id, which
        // is an order-dependent failure rather than a real one (I40).
        $GLOBALS['_pp_test_store'] = [
            'options'   => [],
            'posts'     => [],
            'post_meta' => [],
            'next_id'   => 100,
        ];
    }

    /** One stored testimonials band carrying whatever udc map the test needs. */
    private function band(array $udc, string $id = 'pp-3f9a1c2e'): array
    {
        return [
            'component' => 'testimonials',
            'id'        => $id,
            'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
            'udc'       => $udc,
        ];
    }

    /** The drop ledger the emitter fills in for one band. */
    private function drops(array $udc): array
    {
        $drops = [];
        pp_udc_compile_band($this->band($udc), 'authored', $drops);
        return $drops;
    }

    private function reasons(array $drops): string
    {
        return implode(' | ', array_map(static fn(array $d): string => $d['where'] . ': ' . $d['reason'], $drops));
    }

    // ── 1. Each drop class is named ──────────────────────────────────────────

    /** THE RED PROOF. An unresolvable @token reference painted nothing, silently. */
    public function testAnUnresolvableTokenReferenceIsNamed(): void
    {
        $drops = $this->drops(['quote' => ['typography' => ['size' => '@no-such-token']]]);

        $this->assertNotEmpty($drops, 'an unresolvable reference must not be discarded silently');
        $this->assertStringContainsString('no-such-token', $this->reasons($drops));
        $this->assertStringContainsString('quote', $this->reasons($drops), 'the role must be named');
    }

    /** A stored value that no longer satisfies its grammar. */
    public function testAStoredValueThatNoLongerValidatesIsNamed(): void
    {
        $drops = $this->drops(['quote' => ['typography' => ['size' => 'not-a-length']]]);

        $this->assertNotEmpty($drops);
        $this->assertStringContainsString('grammar', $this->reasons($drops));
        $this->assertStringContainsString('not-a-length', $this->reasons($drops), 'the refused value must be quoted back');
    }

    /** A parameter the group no longer declares — the renamed-param case. */
    public function testAnUnknownParameterIsNamed(): void
    {
        $drops = $this->drops(['quote' => ['typography' => ['no-such-param' => '19px']]]);

        $this->assertNotEmpty($drops);
        $this->assertStringContainsString('no such parameter', $this->reasons($drops));
    }

    /** A group the vocabulary no longer declares. */
    public function testAnUnknownGroupIsNamed(): void
    {
        $drops = $this->drops(['quote' => ['nosuchgroup' => ['size' => '19px']]]);

        $this->assertNotEmpty($drops);
        $this->assertStringContainsString('no such group', $this->reasons($drops));
    }

    /** An unknown breakpoint key inside an otherwise valid responsive map. */
    public function testAnUnknownBreakpointIsNamed(): void
    {
        $drops = $this->drops(['quote' => ['typography' => ['size' => ['d' => '19px', 'zz' => '11px']]]]);

        $this->assertNotEmpty($drops);
        $this->assertStringContainsString('breakpoint', $this->reasons($drops));
    }

    /** A band with no usable id cannot be addressed at all, and that is one row. */
    public function testABandWithNoUsableIdIsNamedAsAWhole(): void
    {
        $drops = [];
        pp_udc_compile_band(
            $this->band(['quote' => ['typography' => ['size' => '19px']]], ''),
            'authored',
            $drops
        );

        $this->assertNotEmpty($drops);
        $this->assertStringContainsString('whole band', $this->reasons($drops));
    }

    // ── 2. No false positives ────────────────────────────────────────────────

    /**
     * A HEALTHY BAND REPORTS NOTHING. This is the pin that keeps the advisory
     * worth reading: a checker that fires on correct pages is one every operator
     * learns to acknowledge blind.
     */
    public function testAHealthyBandProducesNoDrops(): void
    {
        $this->assertSame([], $this->drops([
            'quote'  => ['typography' => ['size' => ['d' => '19px', 'p' => '17px'], 'style' => 'italic']],
            '_band'  => ['spacing' => ['padding-top' => '18px']],
        ]));
    }

    /** A role default that is NOT emitted band-scoped is not a drop the author owns. */
    public function testRoleDefaultsDoNotProduceOperatorFacingDrops(): void
    {
        $this->assertSame(
            [],
            $this->drops([]),
            'a band that authors nothing has nothing the operator can fix'
        );
    }

    // ── 3. The advisory and the emitter cannot disagree ──────────────────────

    /**
     * ONE PREDICATE. The value the advisory names is genuinely absent from the CSS,
     * and its siblings still paint — checked against the emitter's real output
     * rather than against a second copy of its rules.
     */
    public function testANamedValueIsReallyAbsentFromTheEmittedCssWhileSiblingsPaint(): void
    {
        $udc = ['quote' => ['typography' => ['size' => '@no-such-token', 'style' => 'italic']]];

        $this->assertNotEmpty($this->drops($udc));

        $css = pp_udc_band_css($this->band($udc));
        $this->assertStringNotContainsString('font-size', $css, 'the named declaration must really be missing');
        $this->assertStringContainsString('font-style:italic', $css, 'its siblings must still paint');
    }

    // ── 4. The readiness surface ─────────────────────────────────────────────

    /** Chrome is checked unconditionally, because it is the only channel chrome has. */
    public function testChromeIsCheckedWithNoPageInContext(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = wp_json_encode([
            '_version' => 1,
            'nav'      => ['link' => ['typography' => ['color' => '@no-such-token']]],
        ]);

        $checks = pp_check_udc_emit_drops(null);

        $this->assertNotEmpty($checks, 'chrome must be checked with no post_id, as 8c is');
        $this->assertSame('udc_value_cannot_take_effect', $checks[0]['check']);
        $this->assertSame('warning', $checks[0]['severity'], 'advisory, never blocking');
        $this->assertTrue($checks[0]['acknowledgeable']);
        $this->assertStringContainsString('site nav', $checks[0]['message']);
    }

    /** A clean site produces no rows at all. */
    public function testACleanSiteProducesNoReadinessRows(): void
    {
        $this->assertSame([], pp_check_udc_emit_drops(null));
    }

    /** Each row carries a distinct acknowledgement key, so acknowledging one is not acknowledging all. */
    public function testEachRowCarriesItsOwnFindingKey(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = wp_json_encode([
            '_version' => 1,
            'nav'      => [
                'link'  => ['typography' => ['color' => '@nope-one']],
                '_band' => ['background' => ['fill' => '@nope-two']],
            ],
        ]);

        $checks = pp_check_udc_emit_drops(null);
        $keys   = array_column($checks, 'finding_key');

        $this->assertGreaterThan(1, count($keys));
        $this->assertSame($keys, array_unique($keys), 'two different drops must not share one acknowledgement');
    }
}
