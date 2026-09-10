<?php
/**
 * tests/CompositionEncodeRefusalTest.php — a composition that cannot be JSON-encoded is
 * REFUSED, not stored as an empty page under a success report (#941).
 *
 * THE SHAPE THIS CLOSES, measured on the unmodified tree before the guard was written.
 * pp_update_composition() (lib/wp.php) encoded the incoming composition and never checked
 * the result. `wp_slash(false)` is `false`, so update_post_meta() stored an EMPTY value:
 *
 *     RETURN:               true                <- the caller was told it worked
 *     STORED META:          false               <- the page's content, gone
 *     pp_get_composition:   []
 *     pp_get_composition_result:  ok, error null <- NOT decode_error, NOT unexpected_shape
 *     VERSION:              2 -> 3              <- markers bumped over nothing
 *     HASH:                 e3b0c442...         <- sha256(''), certifying the emptiness
 *     RING:                 1 -> 2              <- the prior state WAS preserved
 *
 * The last line is why this was worse than it looked rather than better: the page's real
 * content was still sitting in the history ring, and nothing anywhere said to go and get it,
 * because nothing reported a problem at all. An empty `_pp_composition` row is a legitimate
 * "no composition yet" state, so #144 did not classify it, #725 did not call it corrupt, #749
 * did not refuse a batch over it, and #687's accepted-write report truthfully found zero
 * findings on it — a documented all-clear over a page that had just lost everything.
 *
 * THE RULING (orchestrator, 2026-09-09) is fail-closed, and it is deliberately the OPPOSITE
 * of the ruling on the ring encode a screen below it (#821, "guard and disclose, do not
 * block"). Same unambiguous signal, different thing at stake: refusing a ring push costs a
 * caller its undo point, while refusing THIS write costs a caller one retry and accepting it
 * destroys the page. So:
 *
 *     pp_update_composition($post_id, $composition)
 *         │
 *         ├─ hash the canonical form            (a local; nothing written)
 *         ├─ inject props.id                    (this function's own copy)
 *         ├─ $json = wp_json_encode(...)
 *         │      │
 *         │      └─ === false ──► WP_Error('composition_not_encodable')
 *         │                        the lock is NEVER taken
 *         │                        the ring is NEVER read or pushed
 *         │                        _pp_composition / _hash / _version all UNCHANGED
 *         │                        the post's #821 skip slot is NOT drained
 *         │
 *         └─ otherwise ──► take the lock, CAS, push the ring, write the three rows
 *
 * HOW A FAILING ENCODE IS REACHED HONESTLY, because the fixture is the load-bearing half of
 * this file and the obvious fixture is the wrong one. This path uses wp_json_encode(), not
 * bare json_encode(). On a false from json_encode(), core runs _wp_json_sanity_check() —
 * which rewrites every STRING through _wp_json_convert_string() and throws only when its own
 * depth budget runs out — and re-encodes. So MALFORMED UTF-8 IS NOT A TRIGGER in production:
 * it is coerced and SUCCEEDS. It is a trigger under tests/bootstrap.php, which stubs
 * wp_json_encode() as a bare json_encode() — and a fixture that only fails because of that
 * divergence would pin a fiction. (The same fact is why #818 stores the ring's preserved
 * bytes as base64 over a bare json_encode.)
 *
 * AND THAT DIVERGENCE NOW DECIDES A VERDICT, WHICH IT DID NOT BEFORE THIS CHANGE. Until the
 * guard existed, the stub and production disagreed only about what got STORED — both returned
 * true from this writer. Now the encode's return value decides whether the write is REFUSED,
 * so under this harness an invalid-UTF-8 composition comes back composition_not_encodable
 * while the shipped writer coerces it and ACCEPTS it. Nothing in this file depends on that —
 * all three triggers below behave identically in both environments, which is the whole reason
 * they were chosen — but it is a live trap for the next fixture someone writes here, and it
 * is tracked as #950 rather than left to be rediscovered.
 *
 * The three triggers used here return false under BOTH the stub and real core, verified
 * against the installed WP 7.0 (wp-includes/functions.php:4443):
 *
 *     DEPTH        json_encode fails with JSON_ERROR_DEPTH; the sanity check throws
 *                  "Reached depth limit" before it can re-encode.
 *     NON-FINITE   INF / -INF / NAN. The sanity check returns non-array, non-object,
 *                  non-string values untouched, so the re-encode fails identically.
 *     RESOURCE     a type JSON cannot represent. Same reason as non-finite.
 *
 * A fourth, a self-referential array, also reaches false ("Recursion detected" from
 * json_encode; the sanity check runs its own depth budget down and throws). It is
 * deliberately NOT a fixture here: a recursive reference living in a PHPUnit process that
 * runs the whole suite in one pass is a hazard to every later test, and three triggers
 * already cover both routes through wp_json_encode. It is named rather than claimed.
 *
 * THE DEPTH IS PROBED, NOT HARDCODED, for the reason CompositionHistoryPushDisclosureTest
 * gives for its own band: a literal is a number that happens to be true on one PHP build,
 * and the first release that moves json's depth accounting would turn this file green over a
 * fixture that triggers nothing.
 *
 * WHAT THIS FILE DOES NOT CLAIM. It does not claim the bug was reachable from a validated
 * agent call. testTheValidatedAuthoringSurfaceRefusesBeforeTheWriterEverSeesIt pins the
 * opposite: the whole-composition action refuses these shapes earlier, so the guard is a
 * backstop over in-process callers that hand the writer a PHP array nothing validated.
 */

use PHPUnit\Framework\TestCase;

class CompositionEncodeRefusalTest extends TestCase
{
    /** The refusal class the ruling ships as. */
    private const CODE = 'composition_not_encodable';

    /** The listing command the refusal points at when the ring holds anything. */
    private const RING_COMMAND = 'wp pp operate composition-history --post_id=';

    /** @var int[] pages created here, so tearDown can drain the #821 skip register. */
    private array $pages = [];

    /** @var resource[] handles opened for the unsupported-type fixture. */
    private array $handles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'connectors' => [],
            'next_id'    => 100,
            'custom_css' => '',
        ];
        unset($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        // Same hazard CompositionHistoryPushDisclosureTest documents: the skip register is a
        // static that outlives the test while setUp() recycles post ids, and one test here
        // deliberately leaves a slot standing to prove a refusal does not drain it.
        foreach ($this->pages as $post_id) {
            _pp_take_history_push_skipped($post_id);
        }
        $this->pages = [];
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->handles = [];
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function page(string $title): int
    {
        $post_id       = pp_create_page($title, 'draft');
        $this->pages[] = $post_id;
        return $post_id;
    }

    /** A perfectly ordinary, encodable composition with an explicit (non-generated) id. */
    private function healthyBands(string $title = 'Real content'): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'band-1', 'title' => $title]]];
    }

    /** A composition whose single band carries a prop value nested $depth levels deep. */
    private function deepBands(int $depth): array
    {
        $value = 'leaf';
        for ($i = 0; $i < $depth; $i++) {
            $value = [$value];
        }
        return [['component' => 'hero', 'props' => ['id' => 'deep-1', 'title' => 'Deep', 'deep' => $value]]];
    }

    /**
     * The shallowest nesting at which THIS writer's own encode returns false.
     *
     * Deliberately a different question from CompositionHistoryPushDisclosureTest's
     * depthThatBreaksTheRingEncode(), which needs a band where the composition encode still
     * SUCCEEDS and only the ring's extra two levels of wrapping tip it over. That band sits
     * below this one, so the two files cannot collide: this guard never fires on that
     * fixture, and that file's ring-skip branch is never reached by this one.
     */
    private function depthThatBreaksTheCompositionEncode(): int
    {
        for ($depth = 480; $depth <= 560; $depth++) {
            if (wp_json_encode($this->deepBands($depth), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === false) {
                return $depth;
            }
        }
        $this->fail(
            'No nesting depth in 480..560 makes the composition encode fail. '
            . "json's depth accounting has moved; this file's trigger needs revisiting before "
            . 'any assertion below can be trusted.'
        );
    }

    /** A composition holding a non-finite float — the second route to a false encode. */
    private function nonFiniteBands(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'band-1', 'title' => 'T', 'ratio' => INF]]];
    }

    /** A composition holding a value JSON has no representation for. */
    private function unsupportedTypeBands(): array
    {
        $handle          = fopen('php://memory', 'r');
        $this->handles[] = $handle;
        return [['component' => 'hero', 'props' => ['id' => 'band-1', 'title' => 'T', 'handle' => $handle]]];
    }

    /** Every trigger, as [label => composition], each independently proven to encode false. */
    private function unencodableCompositions(): array
    {
        return [
            'depth'        => $this->deepBands($this->depthThatBreaksTheCompositionEncode()),
            'non-finite'   => $this->nonFiniteBands(),
            'unsupported'  => $this->unsupportedTypeBands(),
        ];
    }

    /** The raw stored bytes of a post's composition row, straight out of the test store. */
    private function storedBytes(int $post_id)
    {
        return $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'] ?? null;
    }

    // ── The premise ──────────────────────────────────────────────────────────

    /**
     * Every fixture below rests on "wp_json_encode returns false for this", so that is
     * asserted rather than assumed. Malformed UTF-8 is asserted the other way, because the
     * whole point of the trigger selection is that it does NOT belong in the set.
     */
    public function testEveryFixtureActuallyDefeatsTheEncoderAndUtf8DeliberatelyDoesNot(): void
    {
        foreach ($this->unencodableCompositions() as $label => $composition) {
            $this->assertFalse(
                wp_json_encode($composition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                "premise: the '$label' fixture must defeat the encoder"
            );
        }

        // NOT a trigger in production: _wp_json_sanity_check() coerces the string and the
        // re-encode succeeds. The stub in tests/bootstrap.php is a bare json_encode() and so
        // DOES return false here — which is exactly why no test in this file uses it as a
        // fixture. This assertion documents the divergence instead of hiding it.
        $this->assertFalse(
            json_encode([['component' => 'hero', 'props' => ['id' => 'b1', 'title' => "\xB1\x31"]]]),
            'premise: bare json_encode rejects malformed UTF-8 (the class real wp_json_encode coerces)'
        );
    }

    // ── The refusal ──────────────────────────────────────────────────────────

    public function testAnUnencodableCompositionIsRefusedRatherThanReportedAsAWrite(): void
    {
        foreach ($this->unencodableCompositions() as $label => $composition) {
            $post_id = $this->page('Refusal ' . $label);
            $this->assertTrue(pp_update_composition($post_id, $this->healthyBands()), 'fixture write');

            $result = pp_update_composition($post_id, $composition);

            // The pre-fix return was literally `true`. That is the assertion that matters.
            $this->assertNotTrue($result, "'$label': the write must not report success");
            $this->assertTrue(is_wp_error($result), "'$label': the write must return a WP_Error");
            $this->assertSame(self::CODE, $result->get_error_code(), "'$label': refusal class");
        }
    }

    public function testTheRefusedWriteLeavesTheStoredCompositionByteIdentical(): void
    {
        foreach ($this->unencodableCompositions() as $label => $composition) {
            $post_id = $this->page('Untouched ' . $label);
            $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('Keep me')), 'fixture write');

            $before = $this->storedBytes($post_id);
            $this->assertIsString($before, "'$label': fixture must leave real bytes to protect");

            pp_update_composition($post_id, $composition);

            // The pre-fix value here was `false` — the whole defect in one assertion.
            $this->assertSame($before, $this->storedBytes($post_id), "'$label': stored bytes must not move");
            $this->assertSame(
                $this->healthyBands('Keep me'),
                pp_get_composition($post_id),
                "'$label': the page must still read back its real composition"
            );
        }
    }

    public function testTheRefusedWriteLeavesBothFreshnessMarkersUnchanged(): void
    {
        $post_id = $this->page('Markers');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands()), 'fixture write');

        $version = get_post_meta($post_id, '_pp_composition_version', true);
        $hash    = get_post_meta($post_id, '_pp_composition_hash', true);

        pp_update_composition($post_id, $this->deepBands($this->depthThatBreaksTheCompositionEncode()));

        // Pre-fix the version bumped and the hash became sha256('') — a freshness marker
        // certifying content that was never stored.
        $this->assertSame($version, get_post_meta($post_id, '_pp_composition_version', true), 'version');
        $this->assertSame($hash, get_post_meta($post_id, '_pp_composition_hash', true), 'hash');
        $this->assertNotSame(hash('sha256', ''), $hash, 'the marker must never be sha256 of nothing');
    }

    public function testTheRefusedWriteNeitherPushesToTheRingNorClobbersIt(): void
    {
        $post_id = $this->page('Ring');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('One')), 'fixture write 1');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('Two')), 'fixture write 2');

        $ring = pp_get_composition_history($post_id);
        $this->assertCount(1, $ring, 'fixture: the second write must have pushed one entry');

        pp_update_composition($post_id, $this->deepBands($this->depthThatBreaksTheCompositionEncode()));

        $this->assertSame(
            $ring,
            pp_get_composition_history($post_id),
            'a refused write replaces nothing, so it has nothing to preserve and must not touch the ring'
        );
    }

    /**
     * The #821 interaction, and it is a real one rather than a hypothetical.
     *
     * An accepted write CLEARS the post's skip slot as the first statement of its lock body,
     * so the slot always describes the most recent write to that post (#821). A REFUSED write
     * returns before the lock and must not clear anything: it replaced no prior state, so it
     * does not own the slot, and its rejection envelope carries no `findings` and so could
     * never deliver the notice it destroyed.
     *
     * WHAT THIS DOES NOT CLAIM, because an earlier draft of this docblock claimed it and it
     * was false. Leaving the slot alone does NOT mean the pending notice gets delivered
     * later: the next ACCEPTED write clears the slot before its own envelope reads it, which
     * is #821's stated invariant and not this change's to alter — measured, that envelope
     * comes back `findings: []`. The property pinned here is narrower and is the one that
     * belongs to this guard: a refusal is not a write, so it does not touch a slot that
     * describes writes.
     */
    public function testARefusedWriteDoesNotDrainAnEarlierWritesUndisclosedSkipNotice(): void
    {
        $post_id = $this->page('Skip slot');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands()), 'fixture write');
        _pp_record_history_push_skipped($post_id);

        pp_update_composition($post_id, $this->deepBands($this->depthThatBreaksTheCompositionEncode()));

        $this->assertTrue(
            _pp_take_history_push_skipped($post_id),
            'a refused write does not own the post\'s skip slot and must not drain it'
        );
    }

    // ── The message ──────────────────────────────────────────────────────────

    public function testTheRefusalNamesTheEncoderReasonAndSaysNothingWasWritten(): void
    {
        $post_id = $this->page('Message');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands()), 'fixture write');

        $result  = pp_update_composition($post_id, $this->nonFiniteBands());
        $message = $result->get_error_message();

        // THIS STRING IS THE SAME UNDER REAL wp_json_encode(), which is worth recording
        // because it is the one assertion here that reads a value the stub produces. The
        // non-finite class fails with JSON_ERROR_INF_OR_NAN inside json_encode();
        // _wp_json_sanity_check() returns non-array, non-object, non-string values untouched,
        // so core's re-encode fails with the SAME error and json_last_error_msg() reports the
        // same PHP-core constant. The depth class never reaches a re-encode at all (the sanity
        // check throws first), so it keeps its own error too. There is no reachable trigger
        // whose reported reason differs between the stub and production.
        $this->assertStringContainsString('Inf and NaN', $message, "the encoder's own reason");
        $this->assertStringContainsString((string) $post_id, $message, 'the page it is about');
        $this->assertStringContainsString('nothing was written', $message);
        $this->assertStringContainsString('[' . self::CODE . ']', $message, 'the machine-readable tail');
    }

    public function testTheRefusalPointsAtTheRingWhenThePageActuallyHasOne(): void
    {
        $post_id = $this->page('With ring');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('One')), 'fixture write 1');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('Two')), 'fixture write 2');
        $this->assertNotEmpty(pp_get_composition_history($post_id), 'fixture: the ring must hold something');

        $result = pp_update_composition($post_id, $this->nonFiniteBands());

        $this->assertStringContainsString(
            self::RING_COMMAND . $post_id,
            $result->get_error_message(),
            'where a preserved copy exists, the refusal has to say where to find it'
        );
    }

    /**
     * BOTH HALVES OF ONE MESSAGE, READ TOGETHER, and this test exists because splitting them
     * hid a real defect through an entire implementation pass.
     *
     * The reason was asserted on a page with NO ring and the pointer on a page WITH one, so
     * nothing ever read a ring-ful page's reason — and that is the case that was broken.
     * json_last_error() is a single process-global slot; the ring read that decides whether
     * to append the pointer json_decode()s the stored ring SUCCESSFULLY on the way past,
     * which resets the slot to JSON_ERROR_NONE. The shipped sentence therefore read
     * "could not be encoded as JSON (No error)" on every page past its second accepted
     * write, which is the common case rather than an edge one.
     *
     * Two triggers, because they reach `false` by different routes and a capture-ordering
     * regression could plausibly break one and not the other: the non-finite class fails a
     * SECOND json_encode inside core's wp_json_encode(), while the depth class never reaches
     * one (the sanity check throws first).
     */
    public function testTheRefusalNamesTheEncoderReasonEvenWhenTheRingReadRunsFirst(): void
    {
        foreach ([
            'non-finite' => [$this->nonFiniteBands(), 'Inf and NaN'],
            'depth'      => [$this->deepBands($this->depthThatBreaksTheCompositionEncode()), 'Maximum stack depth'],
        ] as $label => [$composition, $expected_reason]) {
            $post_id = $this->page('Ring and reason ' . $label);
            $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('One')), 'fixture write 1');
            $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('Two')), 'fixture write 2');
            $this->assertNotEmpty(pp_get_composition_history($post_id), 'fixture: the ring must hold something');

            $message = pp_update_composition($post_id, $composition)->get_error_message();

            $this->assertStringContainsString(self::RING_COMMAND . $post_id, $message, "'$label': the pointer");
            $this->assertStringContainsString($expected_reason, $message, "'$label': the reason, beside it");
            $this->assertStringNotContainsString(
                'No error',
                $message,
                "'$label': a refusal must never report that there was no error"
            );
        }
    }

    // ── Precedence and the untaken lock ──────────────────────────────────────

    /**
     * The guard's two structural claims, pinned rather than only argued in a comment.
     *
     * PRECEDENCE. With a stale `expected_version` AND an unencodable composition, the answer
     * must be `composition_not_encodable`, not `composition_conflict`. The comment argues
     * this at length: a conflict tells the caller to re-read the page and re-apply, and a
     * caller that does exactly that will fail identically forever, because what is wrong is
     * the data it is sending and not the baseline it based it on. That ordering is one line
     * move away from silently inverting and every other pin in this file would stay green.
     *
     * THE UNTAKEN LOCK. The in-lock precondition callable is the only thing that can prove
     * from the outside that the lock body never ran. If it is never invoked, nothing inside
     * the critical section executed — no version read, no ring read, no write. That is a
     * stronger statement than "the stored bytes did not change", which a guard placed
     * anywhere inside the mutator could also satisfy.
     */
    public function testTheRefusalAnswersBeforeTheCasAndNeverEntersTheLock(): void
    {
        $post_id = $this->page('Precedence');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('One')), 'fixture write 1');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('Two')), 'fixture write 2');
        $this->assertSame(2, (int) get_post_meta($post_id, '_pp_composition_version', true), 'fixture: at v2');

        $precondition_ran = false;
        $result           = pp_update_composition(
            $post_id,
            $this->nonFiniteBands(),
            1, // deliberately STALE: the page is at version 2
            function (array $ring) use (&$precondition_ran) {
                $precondition_ran = true;
                return true;
            }
        );

        $this->assertSame(
            self::CODE,
            $result->get_error_code(),
            'the caller\'s data answers before the caller\'s baseline'
        );
        $this->assertFalse(
            $precondition_ran,
            'the lock is never taken, so nothing inside the critical section runs'
        );
    }

    /**
     * The honest half. A page's ring only exists after a SECOND accepted write — the first
     * has no prior state to preserve — so the common case for a young page is a refusal with
     * nothing to point at. The message must then say what exists (the page still holds its
     * composition) and simply not raise the ring at all: an operator told "there is no
     * preserved copy" learns nothing useful and one more thing to worry about.
     */
    public function testTheRefusalSaysNothingAboutTheRingWhenThePageHasNotGotOne(): void
    {
        $post_id = $this->page('No ring');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands()), 'fixture write');
        $this->assertSame([], pp_get_composition_history($post_id), 'fixture: a first write pushes nothing');

        $message = pp_update_composition($post_id, $this->nonFiniteBands())->get_error_message();

        $this->assertStringNotContainsString('history', $message, 'no pointer at a ring that holds nothing');
        $this->assertStringNotContainsString('composition-history', $message);
        $this->assertStringContainsString(
            'still stores the composition it stored before this call',
            $message,
            'it must still say what DOES exist'
        );
    }

    // ── The regression pin ───────────────────────────────────────────────────

    /**
     * A guard on the one composition writer is a change to a function 39 existing pins spell
     * `assertTrue(pp_update_composition(...))` against. The success path has to be untouched,
     * and "untouched" means the stored BYTES, not just the return value.
     */
    public function testAHealthyCompositionStillWritesTheExactSameBytes(): void
    {
        $post_id     = $this->page('Healthy');
        $composition = $this->healthyBands('Byte identical');

        $this->assertTrue(pp_update_composition($post_id, $composition));

        // The writer wp_slash()es on the way in and update_post_meta() unslashes on the way
        // down (tests/bootstrap.php models that round trip, #471), so the bytes that land are
        // the plain encode — which is the byte string this pin is actually about.
        $this->assertSame(
            wp_json_encode($composition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->storedBytes($post_id),
            'the accepted write must store exactly what it stored before the guard existed'
        );
        $this->assertSame($composition, pp_get_composition($post_id));
        $this->assertSame(1, (int) get_post_meta($post_id, '_pp_composition_version', true));
        $this->assertSame(
            pp_composition_content_hash($composition),
            get_post_meta($post_id, '_pp_composition_hash', true)
        );
    }

    public function testASecondHealthyWriteStillPushesTheRingAndBumpsTheVersion(): void
    {
        $post_id = $this->page('Healthy twice');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('One')));
        $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('Two')));

        $this->assertSame(2, (int) get_post_meta($post_id, '_pp_composition_version', true));
        $this->assertCount(1, pp_get_composition_history($post_id));
        $this->assertSame($this->healthyBands('Two'), pp_get_composition($post_id));
    }

    // ── The authoring path (Section 14.1) ────────────────────────────────────

    /**
     * DRIVEN THROUGH THE REAL SURFACE, and the result is the reachability claim itself.
     *
     * The assertion is deliberately about the OUTCOME rather than about which layer produced
     * it: the call is refused, and the page still holds its composition afterwards. Pinning a
     * specific validation error code here would make this test fail the day validation is
     * tightened or a schema gains a free-form prop — a change that has nothing to do with
     * #941 — while the property that actually matters (a validated authoring call can never
     * empty a page through this route) would still hold. Whichever layer says no, the answer
     * has to be no.
     */
    public function testTheValidatedAuthoringSurfaceRefusesBeforeTheWriterEverSeesIt(): void
    {
        foreach ($this->unencodableCompositions() as $label => $composition) {
            $post_id = $this->page('Authoring ' . $label);
            $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('Authored')), 'fixture write');
            $before = $this->storedBytes($post_id);

            $envelope = pp_execute_action('update_composition', [
                'post_id'     => $post_id,
                'composition' => $composition,
            ]);

            $this->assertFalse($envelope['ok'] ?? null, "'$label': the action must be refused");
            $this->assertSame($before, $this->storedBytes($post_id), "'$label': the page must be untouched");
            $this->assertSame($this->healthyBands('Authored'), pp_get_composition($post_id));
        }
    }

    /**
     * THE VALUE, NOT THE KEY NAME — and without this the test above proves nothing.
     *
     * The fixtures this file uses everywhere else hang their pathological value off an
     * UNDECLARED prop (`deep`, `ratio`, `handle`), so `pp_execute_action()` refuses each one
     * with `unknown_prop` — a check on the key NAME that never looks at the value and never
     * reaches an encoder. The authoring-path test would pass identically with
     * `'deep' => 'hi'`. It would still be pinning the property that matters (a validated call
     * cannot empty a page), but it would not be pinning it for the stated reason, and the
     * reachability claim in this file's header rests on validation judging these SHAPES.
     *
     * So the same three triggers are sent again under `title`, a DECLARED prop, where the
     * validator has to judge the value. The assertions stay at the outcome level — refused,
     * page untouched — plus one that the refusal is not `unknown_prop`, which is what makes
     * this test different from the one above rather than a copy of it.
     */
    public function testTheValidatedSurfaceJudgesTheValueAndNotJustThePropName(): void
    {
        $handle          = fopen('php://memory', 'r');
        $this->handles[] = $handle;
        $deep            = 'leaf';
        for ($i = 0; $i < $this->depthThatBreaksTheCompositionEncode(); $i++) {
            $deep = [$deep];
        }

        $cases = [
            'depth under title'      => $deep,
            'non-finite under title' => INF,
            'resource under title'   => $handle,
        ];

        foreach ($cases as $label => $value) {
            $post_id = $this->page('Declared ' . $label);
            $this->assertTrue(pp_update_composition($post_id, $this->healthyBands('Authored')), 'fixture write');
            $before = $this->storedBytes($post_id);

            $envelope = pp_execute_action('update_composition', [
                'post_id'     => $post_id,
                'composition' => [['component' => 'hero', 'props' => ['id' => 'b1', 'title' => $value]]],
            ]);

            $this->assertFalse($envelope['ok'] ?? null, "'$label': the action must be refused");
            $this->assertNotSame(
                'unknown_prop',
                $envelope['error_code'] ?? null,
                "'$label': the key is declared, so the refusal has to be about the value"
            );
            $this->assertSame($before, $this->storedBytes($post_id), "'$label': the page must be untouched");
        }
    }

    /**
     * The invariant the #941 comment on pp_composition_content_hash() asserts, made
     * enforceable instead of hoped for.
     *
     * That function ends with `(string) wp_json_encode(...)`, and `(string) false` is `''` —
     * so an unencodable composition hashes to sha256 of the empty string, a freshness marker
     * over content that was never stored. It was reachable until this change: the emptied
     * page in #941 carried exactly that digest. It is unreachable NOW only because its single
     * caller refuses on the same signal before the digest is used for anything.
     *
     * "Only because it has one caller" is an invariant, and an invariant nothing checks is a
     * comment. A second caller would reopen the marker silently, with every test in this file
     * still green, so the tripwire fails loudly at the moment one appears and says what to do
     * about it. The nullable-return question it defers to is tracked as #948.
     */
    public function testTheContentHashHasExactlyOneProductionCallerSoItsEmptyStringCastStaysUnreachable(): void
    {
        $callers = [];
        foreach (glob(dirname(__DIR__) . '/lib/*.php') as $file) {
            foreach (file($file) as $offset => $line) {
                if (strpos($line, 'pp_composition_content_hash(') === false) {
                    continue;
                }
                // The definition itself, and prose about it, are not call sites.
                if (strpos($line, 'function pp_composition_content_hash') !== false) {
                    continue;
                }
                if (preg_match('#^\s*(\*|//)#', $line)) {
                    continue;
                }
                $callers[] = basename($file) . ':' . ($offset + 1) . ' ' . trim($line);
            }
        }

        $this->assertCount(
            1,
            $callers,
            "pp_composition_content_hash() casts a failed encode to '' and returns sha256 of "
            . 'nothing (#941). That is safe only while its ONE caller — pp_update_composition() '
            . '— refuses on the same signal before using the digest. A new caller reopens the '
            . 'false-marker path: guard your own encode first, or take the nullable-return '
            . "question (#948) to the maintainer. Call sites found:\n  " . implode("\n  ", $callers)
        );
        $this->assertStringStartsWith('wp.php:', $callers[0], 'the one caller lives in lib/wp.php');
    }

    /**
     * The counterpart: the same surface still ACCEPTS an ordinary composition and the
     * accepted envelope still reports the page as clean. A guard that quietly started
     * refusing healthy writes would pass every assertion above.
     */
    public function testTheValidatedAuthoringSurfaceStillAcceptsAnOrdinaryComposition(): void
    {
        $post_id = $this->page('Authoring healthy');

        $envelope = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->healthyBands('Authored fine'),
        ]);

        $this->assertTrue($envelope['ok'] ?? false, 'an ordinary composition must still be accepted');
        $this->assertSame($this->healthyBands('Authored fine'), pp_get_composition($post_id));
        $this->assertSame([], $envelope['findings'] ?? null, 'and still report a clean page');
    }
}
