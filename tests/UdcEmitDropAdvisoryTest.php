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

    /**
     * A ROLE DEFAULT'S DISCARD NEVER REACHES THE OPERATOR.
     *
     * Called directly, because that is the only way to reach it: no shipped schema
     * declares a default the emitter would drop, so the filter cannot be exercised
     * through a composition fixture. The previous version of this test asserted on a
     * band with an EMPTY udc map and therefore pinned nothing — removing the
     * `$source !== 'defaults'` guard left it green. The production code spends a
     * paragraph justifying that guard; this is what holds it up.
     */
    public function testADefaultsSourcedDiscardIsNotLedgered(): void
    {
        $params      = pp_udc_groups()['typography']['params'];
        $breakpoints = pp_udc_breakpoints();
        $where       = 'role "quote" group "typography"';

        $resolved = [];
        $ref      = [];
        $from_defaults = [];
        _pp_udc_place(
            $resolved, '', $params, 'no-such-param', '19px', 'defaults',
            [], $breakpoints, $ref, $from_defaults, $where
        );

        $resolved = [];
        $ref      = [];
        $from_author = [];
        _pp_udc_place(
            $resolved, '', $params, 'no-such-param', '19px', 'udc',
            [], $breakpoints, $ref, $from_author, $where
        );

        $this->assertSame([], $from_defaults, 'a repo-owned schema fault is not site misconfiguration');
        $this->assertCount(1, $from_author, 'the identical discard from author data IS reported');
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

    // ── 5. C2: a `_band` value cancelled by a role default ───────────────────

    /**
     * THE RED PROOF (C2, I35). An inherited `_band` value cancelled by role
     * defaults reported applied and painted nothing on the roles that matter.
     *
     * testimonials declares `color` defaults on seven text roles, so a band-level
     * text colour reaches the wrappers and none of the text.
     */
    public function testABandColourCancelledByRoleDefaultsIsDisclosed(): void
    {
        $findings = pp_udc_composition_findings([
            $this->band(['_band' => ['typography' => ['color' => '#ff0000']]]),
        ]);

        $types = array_column($findings, 'type');
        $this->assertContains('udc_band_value_shadowed_by_role_default', $types);

        $message = implode(' ', array_column($findings, 'message'));
        $this->assertStringContainsString('color', $message);
        $this->assertStringContainsString('quote', $message, 'the shadowing roles must be named');
    }

    /**
     * A NON-INHERITED property is not cancelled and must not be reported.
     *
     * `_band` padding and a role's padding are different boxes; both paint. This is
     * the false positive that would teach an operator to ignore the finding.
     */
    public function testABandPaddingIsNotReportedAsCancelled(): void
    {
        $findings = pp_udc_composition_findings([
            $this->band(['_band' => ['spacing' => ['padding-top' => '18px']]]),
        ]);

        $this->assertNotContains('udc_band_value_shadowed_by_role_default', array_column($findings, 'type'));
    }

    /** An inherited property NO role defaults is not cancelled either. */
    public function testABandValueNoRoleDefaultsIsNotReported(): void
    {
        $findings = pp_udc_composition_findings([
            $this->band(['_band' => ['typography' => ['align' => 'center']]]),
        ]);

        $this->assertNotContains(
            'udc_band_value_shadowed_by_role_default',
            array_column($findings, 'type'),
            'no testimonials role defaults text-align, so nothing cancels it'
        );
    }

    /**
     * DERIVED FROM STORED DATA, so it reconstructs identically on every surface.
     *
     * The same function runs over the stored composition for `wp pp check page`
     * and restore_composition. A finding computable only from a submitted map
     * would report nothing on either.
     */
    public function testTheDisclosureReconstructsIdenticallyFromStoredData(): void
    {
        $item = $this->band(['_band' => ['typography' => ['color' => '#ff0000']]]);

        $submitted = pp_udc_composition_findings([$item]);
        $stored    = pp_udc_composition_findings(json_decode((string) wp_json_encode([$item]), true));

        $this->assertSame(
            array_column($submitted, 'message'),
            array_column($stored, 'message')
        );
    }

    // ── 6. Bounds found by adversarial review (#981) ─────────────────────────

    /**
     * THE LEDGER IS BOUNDED AT THE SOURCE, not by its reader.
     *
     * Slicing the advisory's ROWS does not bound its INPUT. One stored band
     * declaring thousands of invalid parameters would fill the ledger completely
     * before the reader saw it — and preflight builds this before every mutation,
     * so an unbounded ledger is a denial-of-service on the write path from a single
     * corrupt band. Caught by the adversarial pass, which called it correctly.
     */
    public function testTheDropLedgerIsBoundedByAPathologicalBand(): void
    {
        $typography = [];
        for ($i = 0; $i < 2000; $i++) {
            $typography['no-such-param-' . $i] = '19px';
        }

        $drops = $this->drops(['quote' => ['typography' => $typography]]);

        $this->assertLessThanOrEqual(
            PP_UDC_MAX_EMIT_DROPS,
            count($drops),
            'the ledger must bound its own allocation, not rely on the advisory slicing it'
        );
        $this->assertNotEmpty($drops, 'and it must still report what it did see');
    }

    /**
     * ACKNOWLEDGING ONE DROP MUST NOT SILENCE A DIFFERENT ONE AT THE SAME PLACE.
     *
     * Unlike the background-image check beside it — where the reason is always "the
     * attachment is gone" — a value at one location can stop painting for sixteen
     * different reasons. A key built from location alone would let a harmless stale
     * value, once acknowledged, hide a later forbidden construct at the same role
     * and parameter.
     */
    public function testTwoDifferentReasonsAtOneLocationGetDifferentFindingKeys(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = wp_json_encode([
            '_version' => 1,
            'nav'      => ['link' => ['typography' => ['color' => '@nope']]],
        ]);
        $unresolved = pp_check_udc_emit_drops(null)[0]['finding_key'] ?? '';

        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = wp_json_encode([
            '_version' => 1,
            'nav'      => ['link' => ['typography' => ['color' => 'not-a-colour']]],
        ]);
        $invalid = pp_check_udc_emit_drops(null)[0]['finding_key'] ?? '';

        $this->assertNotSame('', $unresolved);
        $this->assertNotSame('', $invalid);
        $this->assertNotSame(
            $unresolved,
            $invalid,
            'same location, different reason, so acknowledging one must not silence the other'
        );
    }

    /**
     * A corrupt stored chrome row yields no rows, rather than spurious ones.
     *
     * NAMED FOR WHAT IT PINS. An earlier version of this test claimed to prove the
     * shape guard in pp_check_udc_emit_drops(), and could not: pp_udc_site_map()
     * normalises every unreadable or malformed row to `chrome => []` before this
     * function sees it, so the non-array branch is unreachable from stored data and
     * reverting the guard left the test green. The guard stays as defence for a
     * filtered or future-extended map; what is PROVEN here is the reachable
     * property, which is the one that matters for an operator: a corrupt row
     * produces silence, not invented findings.
     */
    public function testACorruptStoredChromeRowProducesNoRows(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = 'NOT_JSON{{{';

        $this->assertSame([], pp_udc_site_map()['chrome'], 'the premise: it normalises to empty');
        $this->assertSame([], pp_check_udc_emit_drops(null));
    }

    /** A reflected stored value is bounded, so one huge value cannot bloat every envelope. */
    public function testAHugeStoredValueIsBoundedInTheAdvisoryMessage(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = wp_json_encode([
            '_version' => 1,
            'nav'      => ['link' => ['typography' => ['color' => str_repeat('z', 5000)]]],
        ]);

        $checks = pp_check_udc_emit_drops(null);

        $this->assertNotEmpty($checks);
        $this->assertLessThan(
            1000,
            strlen($checks[0]['message']),
            'a stored value has no length limit of its own, so the message must impose one'
        );
    }

    // ── 7. Reflected-text bounds found by the security specialist (#981) ─────

    /**
     * EVERY FRAGMENT OF THE MESSAGE IS STORED DATA, so every one is bounded.
     *
     * The role, group, parameter, state and breakpoint in the locator are all
     * arbitrary array KEYS from the stored map, and the readiness `checks[]`
     * channel is NOT inside the carve-out that lets `findings[].message` copy
     * validator text verbatim. These rows ride the preflight envelope of every
     * mutation, so an unbounded key means megabytes into every apply result.
     */
    public function testAHugeStoredKeyIsBoundedInTheAdvisoryLocator(): void
    {
        $huge = str_repeat('k', 5000);
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = wp_json_encode([
            '_version' => 1,
            'nav'      => ['link' => ['typography' => [$huge => '19px']]],
        ]);

        $checks = pp_check_udc_emit_drops(null);

        $this->assertNotEmpty($checks);
        $this->assertLessThan(
            1000,
            strlen($checks[0]['message']),
            'a stored KEY is as unbounded as a stored value and must be cleaned the same way'
        );
    }

    /** The unresolvable-reference branch is reached BECAUSE the charset check failed, so it is unbounded by construction. */
    public function testAHugeMalformedReferenceIsBoundedToo(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = wp_json_encode([
            '_version' => 1,
            'nav'      => ['link' => ['typography' => ['color' => '@' . str_repeat('!', 5000)]]],
        ]);

        $checks = pp_check_udc_emit_drops(null);

        $this->assertNotEmpty($checks);
        $this->assertLessThan(1000, strlen($checks[0]['message']));
    }

    /**
     * The bound comes from an always-loaded file.
     *
     * lib/udc.php is loaded before lib/admin.php and _pp_udc_place() is the hottest
     * loop on every front-end request, so reaching up to lib/admin.php's constant
     * would be a layering inversion with a fatal at the bottom of it.
     */
    public function testTheReflectedBoundDoesNotReachIntoALaterLoadedFile(): void
    {
        $this->assertTrue(defined('PP_UDC_REFLECTED_MAX'));

        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);

        // TOKENIZED, NOT GREPPED. The docblock above the helper NAMES that constant
        // to explain why it is deliberately not used, and a text search cannot tell
        // the record of a decision from a violation of it. Only a real T_STRING
        // token is a reference.
        $referenced = false;
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'PP_REFLECTED_VALUE_MAX_LENGTH') {
                $referenced = true;
                break;
            }
        }

        $this->assertFalse(
            $referenced,
            'lib/udc.php must not REFERENCE a constant defined in the later-loaded lib/admin.php'
        );
    }

    // ── 8. The bounding rows themselves (#981) ───────────────────────────────

    /** More drops than the report shows are summarised by one overflow row. */
    public function testMoreDropsThanFitAreSummarisedByAnOverflowRow(): void
    {
        $typography = [];
        for ($i = 0; $i < 30; $i++) {
            $typography['no-such-param-' . $i] = '19px';
        }
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = wp_json_encode([
            '_version' => 1,
            'nav'      => ['link' => ['typography' => $typography]],
        ]);

        $checks = pp_check_udc_emit_drops(null);
        $keys   = array_column($checks, 'finding_key');

        $this->assertCount(11, $checks, 'ten rows plus one overflow');
        $this->assertContains('udc_value_cannot_take_effect:overflow', $keys);
        $this->assertStringContainsString('At least', end($checks)['message']);
    }

    /** A page with more styled bands than the walk reads says the list may be incomplete. */
    public function testAPageWithMoreStyledBandsThanTheWalkReadsSaysSo(): void
    {
        // ONE band with a drop, then enough healthy styled bands to exhaust the band
        // bound. Both conditions have to hold at once: the row budget must NOT fill
        // first (or the walk stops for the other reason), and rows must be non-empty
        // (or the caveat is about nothing and is correctly suppressed).
        $composition = [$this->band(
            ['quote' => ['typography' => ['size' => '@no-such-token']]],
            'pp-00000000'
        )];
        for ($i = 1; $i < 30; $i++) {
            $composition[] = $this->band(
                ['quote' => ['typography' => ['size' => '19px']]],
                sprintf('pp-%08x', $i)
            );
        }
        $GLOBALS['_pp_test_store']['post_meta'][7]['_pp_composition'] = wp_json_encode($composition);

        $checks = pp_check_udc_emit_drops(7);
        $keys   = array_column($checks, 'finding_key');

        $truncation = array_values(array_filter(
            $keys,
            static fn(string $k): bool => str_starts_with($k, 'udc_value_cannot_take_effect:bands_truncated')
        ));
        $this->assertNotEmpty($truncation, 'a partial walk must say it was partial');
        $this->assertSame(
            'udc_value_cannot_take_effect:bands_truncated:7',
            $truncation[0],
            'the row names one page, so its acknowledgement key must too'
        );
    }

    /**
     * A HEALTHY PAGE WITH MANY BANDS REPORTS NOTHING.
     *
     * The truncation flag is set by the band count alone, so before this was gated
     * a correct 26-band page emitted a warning saying its list might be incomplete
     * — a warning about an empty list. That is precisely the cry-wolf row that
     * teaches an operator to acknowledge this check blind. Found by mutation review.
     */
    public function testManyHealthyBandsProduceNoReadinessRowsAtAll(): void
    {
        $composition = [];
        for ($i = 0; $i < 30; $i++) {
            $composition[] = $this->band(
                ['quote' => ['typography' => ['size' => '19px']]],
                sprintf('pp-%08x', $i)
            );
        }
        $GLOBALS['_pp_test_store']['post_meta'][7]['_pp_composition'] = wp_json_encode($composition);

        $this->assertSame([], pp_check_udc_emit_drops(7), 'a correct page says nothing, however long it is');
    }

    // ── 9. The ledger costs nothing when nobody is collecting (#981) ─────────

    /**
     * THE DIAGNOSTIC MUST NOT TAX THE RENDER PATH.
     *
     * _pp_udc_place() runs once per parameter, per role, per band, per source on
     * every front-end request, and pp_udc_compile_band() builds a locator string per
     * group. A first cut of this change constructed the closure and the locator
     * unconditionally and cost a measured 13-18% on a 50-band page; both are now
     * built only when a ledger is actually passed.
     *
     * Pinned STRUCTURALLY rather than by timing, because a wall-clock assertion in a
     * unit suite is a flake generator. What is asserted is the property the timing
     * depended on: the render path allocates neither.
     */
    public function testTheLedgerIsNotBuiltWhenNobodyIsCollecting(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $this->assertIsString($source);

        $place = substr($source, strpos($source, 'function _pp_udc_place('));
        $place = substr($place, 0, strpos($place, "\nfunction "));
        $this->assertMatchesRegularExpression(
            '/\$note\s*=\s*null;\s*\n\s*if\s*\(\s*\$drops\s*!==\s*null/',
            $place,
            'the closure must be constructed behind a null check, not on every placement'
        );

        $compile = substr($source, strpos($source, 'function pp_udc_compile_band('));
        $compile = substr($compile, 0, strpos($compile, "\nfunction "));
        $this->assertStringContainsString(
            '$where = $drops === null',
            $compile,
            'the locator must not be sprintf-ed on every group of every render'
        );
    }

    /** And it still records correctly when someone IS collecting. */
    public function testTheLedgerStillRecordsWhenCollecting(): void
    {
        $drops = $this->drops(['quote' => ['typography' => ['size' => '@no-such-token']]]);

        $this->assertNotEmpty($drops);
        $this->assertStringContainsString(
            'role "quote" group "typography"',
            $drops[0]['where'],
            'the locator is still built for a real collector'
        );
    }

    // ── 10. Interaction defects found by the red team (#981) ─────────────────

    /**
     * A PRESET IS NOT A MISSING GROUP, AND IT PAINTS.
     *
     * The ledger read `_preset` as a group the vocabulary does not declare, so
     * every band using a preset — the headline authoring affordance — drew a
     * "stored but not painted" warning before every mutation, on a value that
     * renders correctly. Caught by the red team; nothing in this file mentioned
     * `_preset` until now.
     */
    public function testARoleGrainPresetIsNotReportedAsAnUnpaintedValue(): void
    {
        $udc = ['card' => [PP_UDC_PRESET_KEY => 'button']];

        $this->assertNull(
            pp_udc_validate_map($udc, 'testimonials'),
            'the premise: this is an accepted authoring shape'
        );
        $this->assertSame([], $this->drops($udc), 'a preset that paints must not be reported as dropped');
        $this->assertNotSame('', pp_udc_band_css($this->band($udc)), 'and it really does paint');
    }

    /** The same carve-out one level down, inside a permitted group. */
    public function testAGroupGrainPresetIsNotReportedEither(): void
    {
        $udc = ['quote' => ['typography' => [PP_UDC_PRESET_KEY => 'link', 'size' => '1.25rem']]];

        $this->assertNull(pp_udc_validate_map($udc, 'testimonials'));
        $this->assertSame([], $this->drops($udc));
    }

    /**
     * ONE VALUE, ONE CLASSIFIER (I25).
     *
     * A non-numeric `background.image` failed the grammar check before it ever
     * reached the carve-out, so both advisories reported the same stored value
     * with different reasons and different next actions — one saying the
     * attachment is gone, the other saying the value fails its grammar.
     */
    public function testAMalformedBackgroundImageIsReportedByExactlyOneAdvisory(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'][7]['_pp_composition'] = wp_json_encode([
            $this->band(['card' => ['background' => ['image' => 'abc']]]),
        ]);

        $emit  = pp_check_udc_emit_drops(7);
        $image = pp_check_udc_background_images(7);

        $this->assertNotEmpty($image, 'the background check owns this parameter');
        $this->assertSame(
            [],
            array_values(array_filter(
                $emit,
                static fn(array $c): bool => str_contains($c['message'], 'background')
            )),
            'and the emit-drop check must stay silent about it rather than tell a second story'
        );
    }

    /**
     * C2 MUST NOT DESCRIBE A VALUE THAT DOES NOT PAINT AT ALL.
     *
     * A `_band` value failing its own grammar is not painted anywhere, so saying
     * it "does not reach these roles" implies it reaches the others, and "set it
     * on those roles directly" is advice that would not work either. That value
     * belongs to the emit-drop advisory alone.
     */
    public function testAnUnpaintableBandValueIsNotAlsoReportedAsShadowed(): void
    {
        $findings = pp_udc_composition_findings([
            $this->band(['_band' => ['typography' => ['color' => 'not-a-real-color']]]),
        ]);

        $this->assertNotContains(
            'udc_band_value_shadowed_by_role_default',
            array_column($findings, 'type'),
            'a value that paints nowhere is the drop advisory\'s, not the shadowing disclosure\'s'
        );
    }

    /** And C2 must not fire when the author already did what it would advise. */
    public function testARoleTheAuthorAlreadySetIsNotReportedAsShadowed(): void
    {
        $findings = pp_udc_composition_findings([
            $this->band([
                '_band' => ['typography' => ['style' => 'normal']],
                'quote' => ['typography' => ['style' => 'normal']],
            ]),
        ]);

        $shadowed = array_values(array_filter(
            $findings,
            static fn(array $f): bool => $f['type'] === 'udc_band_value_shadowed_by_role_default'
        ));
        foreach ($shadowed as $finding) {
            $this->assertStringNotContainsString(
                'quote',
                $finding['message'],
                'the author already set this role, so the cancellation is moot'
            );
        }
        $this->assertTrue(true, 'asserted above, or nothing was reported at all');
    }

    /**
     * THE OVERFLOW COUNT MUST BE A REAL FLOOR, NOT A STRUCTURAL 1.
     *
     * Collection stops one past what is shown, so a remainder derived from the
     * collected rows could never exceed 1: a page with dozens of unpainted values
     * reported "At least 1 more". The check that exists to end silent
     * under-reporting was under-reporting itself.
     */
    public function testTheOverflowRowCountsWhatWasSeenNotWhatWasKept(): void
    {
        $typography = [];
        for ($i = 0; $i < 40; $i++) {
            $typography['no-such-param-' . $i] = '19px';
        }
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = wp_json_encode([
            '_version' => 1,
            'nav'      => ['link' => ['typography' => $typography]],
        ]);

        $checks   = pp_check_udc_emit_drops(null);
        $overflow = end($checks);

        $this->assertSame('udc_value_cannot_take_effect:overflow', $overflow['finding_key']);
        preg_match('/At least (\d+) more/', $overflow['message'], $m);
        $this->assertGreaterThan(
            1,
            (int) ($m[1] ?? 0),
            'the remainder must reflect what was actually seen'
        );
    }
}
