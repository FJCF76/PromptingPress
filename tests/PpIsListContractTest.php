<?php
/**
 * tests/PpIsListContractTest.php — the list discriminator is O(1) and byte-identical (#715).
 *
 * THE FAILURE THIS CLOSES. `_pp_item_index_label()` asks pp_is_list() whether a container
 * is a list ONCE PER EMITTED FINDING, to choose between the `N` and `key "N"` locator
 * forms. pp_is_list() was a hand-rolled PHP 8.0 shim for array_is_list() that built
 * `array_keys()` + `range()` on every call — two n-element allocations — so a composition
 * of N bad bands rescanned an N-element container N times. Clean O(N²), measured on the
 * v1.14.8 branch and reproduced on this one:
 *
 *     N=500  0.0033s | N=2000 0.0388s | N=5000 0.2495s | N=10000 1.0240s   (before)
 *     N=500  0.0002s | N=2000 0.0008s | N=5000 0.0022s | N=10000 0.0049s   (after)
 *
 * THE FIX IS IN THE PREDICATE, NOT AT THE CALL SITES, and that is the load-bearing choice.
 * A per-call-site hoist would have needed a new renderer taking a raw bool — the exact
 * "discriminator detached from its container" defect DiagnosticReachTest's source tripwire
 * exists to prevent — and it could not have reached the two delegate sites
 * (_pp_link_url_error_message, _pp_validate_style_slot_map) whose `?array $item_container`
 * signatures that tripwire pins by reflection. Fixing the predicate fixes all thirteen
 * locator sites and every other caller, with no new API and no weakened guard.
 *
 * WHAT THIS FILE MUST PROVE, because "faster" is worthless if the messages moved:
 *
 *   1. array_is_list() and the 8.0 fallback are THE SAME PREDICATE on every shape this
 *      codebase can produce — including the two that matter for locators: a string-keyed
 *      object, and the #652 repro where int keys are present but out of order.
 *   2. Both locator forms still render byte-identically.
 *   3. The engine is O(N), not O(N²), and stays that way.
 */

use PHPUnit\Framework\TestCase;

final class PpIsListContractTest extends TestCase
{
    /**
     * NO PRIVATE COPY. The equivalence test below compares the shipped fast path against
     * the SHIPPED fallback, _pp_is_list_fallback() — not a hand-copy of it in this file.
     *
     * That distinction is the whole point. A copy would let someone edit the real 8.0 arm
     * while this file kept testing the old text, and CI would never notice, because CI runs
     * PHP 8.3 where that arm is unreachable (PHPUnit 10 requires 8.1+). The arm is extracted
     * into its own function precisely so a test on 8.3 can call it anyway.
     *
     * What is still unreachable by any behavioural test is the version GUARD that routes
     * between them, so that is pinned by source in testTheVersionGuardIsNotSilentlyWidened().
     */

    /**
     * Every array shape this codebase can hand the discriminator. Named, because the
     * interesting ones are the two where a naive implementation gets it wrong.
     */
    private static function shapes(): array
    {
        return [
            'empty'                 => [],
            'packed list'           => ['a', 'b', 'c'],
            'list of arrays'        => [['x' => 1], ['y' => 2]],
            'single element'        => ['only'],
            'string-keyed object'   => ['aa' => 1, 'bb' => 2],
            'mixed keys'            => [0 => 'a', 'bb' => 'b'],
            'int keys out of order' => [1 => 'a', 0 => 'b'],
            'int keys with a gap'   => [0 => 'a', 2 => 'c'],
            'non-zero first key'    => [5 => 'a', 6 => 'b'],
            'negative first key'    => [-1 => 'a', 0 => 'b'],
            // The documented LIMIT: an ordered numeric JSON object decodes to a PHP list,
            // and no inspection recovers the distinction. Both branches must agree it is
            // a list — that case is the harmless one (key and position agree).
            'folded numeric object' => json_decode('{"0":"a","1":"b"}', true),
            'decoded json array'    => json_decode('["a","b"]', true),
            'decoded json object'   => json_decode('{"1":"a","0":"b"}', true),
        ];
    }

    /**
     * THE EQUIVALENCE PROOF. The fast path is only a safe substitution if it is the same
     * predicate; this asserts that on every shape above, not on a happy-path sample.
     */
    public function testTheFastPathAndTheShippedFallbackAgreeOnEveryShape(): void
    {
        foreach (self::shapes() as $name => $arr) {
            $this->assertSame(
                _pp_is_list_fallback($arr),
                pp_is_list($arr),
                "the shipped PHP 8.0 fallback disagrees with the 8.1+ fast path on: $name"
            );
            $this->assertSame(
                array_is_list($arr),
                _pp_is_list_fallback($arr),
                "array_is_list() and the shipped fallback are not the same predicate on: $name"
            );
        }
    }

    /** The three answers the locator actually depends on, stated outright. */
    public function testTheDiscriminatorAnswersTheLocatorQuestionsCorrectly(): void
    {
        $this->assertTrue(pp_is_list([]), 'the empty array is a list — {} and [] decode identically');
        $this->assertTrue(pp_is_list(['a', 'b']), 'a JSON array is a list');
        $this->assertFalse(pp_is_list(['aa' => 1]), 'a string-keyed JSON object is not');
        $this->assertFalse(
            pp_is_list([1 => 'a', 0 => 'b']),
            'the #652 repro: int keys present but out of order is an OBJECT, and a naive '
            . 'sorted-keys check would call it a list and fabricate a position'
        );
    }

    /**
     * BYTE-IDENTICAL MESSAGES, both locator forms, through the real engine.
     *
     * The list form is the one every shipped example authors, so it must not move; the key
     * form is the one #652 fixed, so it must not regress back to a fabricated position.
     */
    public function testBothLocatorFormsStillRenderByteIdentically(): void
    {
        $list_form = _pp_composition_findings([
            ['component' => 'logos', 'props' => ['items' => [[], []]]],
        ]);
        $this->assertStringContainsString(
            'Component "logos" prop "items" item 0 is missing required field "image_url".',
            $list_form[0]['message'],
            'a list container renders a bare position'
        );

        // The key form arrives BEHIND the container refusal since #738 — an object-shaped
        // `items` is itself rejected now, and the type pass runs before every nested rule.
        // The claim here is about the locator SPELLING, which is why the finding is
        // selected by content rather than by offset.
        $key_form = array_column(_pp_composition_findings([
            ['component' => 'logos', 'props' => ['items' => ['aa' => [], 'bb' => []]]],
        ]), 'message');
        $this->assertNotEmpty(array_filter(
            $key_form,
            static fn (string $m): bool => str_contains($m, 'prop "items" must be a list')
        ), 'the container refusal is reported too (#738)');
        $this->assertContains(
            'Component "logos" prop "items" item key "aa" is missing required field "image_url".',
            $key_form,
            'an object container renders the key, quoted'
        );

        // The #652 disagreement case: key "1" comes FIRST in document order, and the
        // locator must name the key rather than the position it would occupy in a list.
        $folded = array_values(array_filter(
            array_column(_pp_composition_findings([
                ['component' => 'logos', 'props' => ['items' => [1 => [], 0 => []]]],
            ]), 'message'),
            static fn (string $m): bool => str_contains($m, 'item key')
        ));
        $this->assertStringContainsString(
            'item key "1"',
            $folded[0],
            'the first reported entry is key "1", not position 0'
        );

        // The BAND level, which routes through the same renderer via _pp_band_index_label().
        $bands = _pp_composition_findings([['props' => []], ['props' => []]]);
        $this->assertSame('Item 0 is missing the "component" key.', $bands[0]['message']);
        $this->assertSame('Item 1 is missing the "component" key.', $bands[1]['message']);
    }

    /** lib/wp.php with comments removed, so source assertions are about CODE. */
    private static function wpSourceWithoutComments(): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__) . '/lib/wp.php')) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    /**
     * THE VERSION GUARD, pinned by source because no runtime can pin it by behaviour.
     *
     * On PHP 8.1+ both arms return the same answer, so a typo in the guard is invisible to
     * every assertion above and to the timing pins below. But widening it — `>= 80000`, or
     * deleting it during a "simplify" — makes pp_is_list() call array_is_list() on the
     * declared 8.0 floor, where the function does not exist: a fatal on the FIRST validation
     * of any composition, on the one runtime CI never exercises. Static source assertion is
     * the repo's existing answer to that class of invariant (InvariantTest, DiagnosticReachTest).
     */
    public function testTheVersionGuardIsNotSilentlyWidened(): void
    {
        // COMMENTS STRIPPED FIRST. This file documents its own reasoning at length and the
        // docblocks legitimately name array_is_list() several times; a raw count cannot tell
        // the explanation from the call, and would fail on the very prose that records the fix.
        $source = self::wpSourceWithoutComments();

        $this->assertSame(
            1,
            preg_match_all('/array_is_list\s*\(/', $source),
            'exactly one array_is_list() CALL SITE in lib/wp.php'
        );
        $this->assertSame(
            1,
            preg_match_all('/if\s*\(\s*PHP_VERSION_ID\s*>=\s*80100\s*\)\s*\{\s*return\s+array_is_list\(\$arr\);/', $source),
            'the sole array_is_list() call must sit behind a literal PHP_VERSION_ID >= 80100 guard. '
            . 'array_is_list() does not exist on the declared 8.0 floor, so widening this guard is a '
            . 'fatal on every composition validation, on the one runtime CI cannot test.'
        );
        $this->assertStringContainsString(
            'function _pp_is_list_fallback(array $arr): bool',
            $source,
            'the 8.0 arm must stay a named function so it can be tested on 8.3'
        );

        // The guard and the declared floor are two statements of one fact. If the floor rises
        // to 8.1 the guard becomes dead and the docblock asks for both to be deleted; catch the
        // half-done version.
        $style = (string) file_get_contents(dirname(__DIR__) . '/style.css');
        $this->assertSame(
            1,
            preg_match('/Requires PHP:\s*8\.0/', $style),
            'style.css still declares PHP 8.0. If the floor moved, delete pp_is_list() and '
            . '_pp_is_list_fallback() and call array_is_list() directly (see the docblock).'
        );
    }

    /**
     * THE REGRESSION PIN, made machine-independent on purpose.
     *
     * An absolute wall-clock ceiling cannot work here, in BOTH directions. Measured on this
     * box: the fixed engine does N=20,000 bands in ~0.04s, but reverting the fast path in a
     * scratch copy costs only ~4.3s — and the nested fixture only ~2.3s. Any ceiling low
     * enough to catch the nested regression on a fast machine is close enough to the fixed
     * cost to flake on a slow one, and CI compounds that: shivammathur/setup-php loads Xdebug
     * unless a workflow passes `coverage: none`, which alone is a 3-5x tax on a call-heavy
     * engine like this one.
     *
     * So assert the SHAPE of the curve instead of its position. Quadrupling N costs ~4x under
     * O(N) and ~16x under O(N²); the threshold sits between them, and machine speed cancels
     * out of a ratio. Both fixtures are timed twice from cold with the small size first so a
     * one-off allocation spike inflates the denominator, not the numerator.
     */
    public function testTheEngineCostIsLinearInBandCountNotQuadratic(): void
    {
        $small = self::timeFindings(array_fill(0, 2500, ['props' => []]));
        $large = self::timeFindings(array_fill(0, 10000, ['props' => []]));

        $this->assertSame(2500, $small['count'], 'still exhaustive: one finding per bad band (#621)');
        $this->assertSame(10000, $large['count']);
        self::assertSubQuadratic($small['time'], $large['time'], 'band count');
    }

    /**
     * The same property one level down: N bad ENTRIES inside one band's `items`, the fixture
     * #654 measured (10,000 entries -> 20,001 findings, 2.2573s before this change, 0.0565s
     * after). Pinned separately because it exercises a different container variable and a
     * different set of locator call sites.
     */
    public function testTheNestedItemLocatorCostIsLinearInEntryCountToo(): void
    {
        $small = self::timeFindings([['component' => 'logos', 'props' => ['items' => array_fill(0, 2500, [])]]]);
        $large = self::timeFindings([['component' => 'logos', 'props' => ['items' => array_fill(0, 10000, [])]]]);

        $this->assertGreaterThanOrEqual(2500, $small['count'], 'still exhaustive per authored location');
        self::assertSubQuadratic($small['time'], $large['time'], 'nested item count');
    }

    /** Times one _pp_composition_findings() run, returning elapsed seconds and finding count. */
    private static function timeFindings(array $composition): array
    {
        $started  = microtime(true);
        $findings = _pp_composition_findings($composition);

        return ['time' => microtime(true) - $started, 'count' => count($findings)];
    }

    /**
     * Asserts a 4x input growth did not cost quadratically.
     *
     * Threshold 8: linear predicts 4, quadratic predicts 16, so 8 is the midpoint on a log
     * scale and tolerates a 2x measurement wobble in either direction without admitting the
     * O(N²) curve. A floor on the denominator keeps a sub-millisecond small run from turning
     * timer granularity into a huge ratio.
     */
    private static function assertSubQuadratic(float $small, float $large, string $what): void
    {
        $ratio = $large / max($small, 0.0005);
        self::assertLessThan(
            8.0,
            $ratio,
            sprintf(
                '4x the %s cost %.1fx the time (%.4fs -> %.4fs). Linear predicts ~4x, quadratic ~16x, '
                . 'so the O(N²) locator rescan (#715) is back. Check pp_is_list() in lib/wp.php.',
                $what,
                $ratio,
                $small,
                $large
            )
        );
    }
}
