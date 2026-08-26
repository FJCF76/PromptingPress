<?php
/**
 * tests/CorruptPageRepairCarveOutTest.php — the corrupt-page repair carve-out (#767).
 *
 * WHAT THIS FILE DEFENDS. Issue #767 measured a loop: on a page whose stored
 * `_pp_composition` is classified corrupt, `wp pp apply preflight --post_id=N` fails
 * closed ("the stored composition is <classification> ... repair it first") while
 * `wp pp action execute update_composition --post_id=N` refuses for want of preflight
 * coverage ("Run `wp pp apply preflight`"). Each instruction pointed at the other, so the
 * only pages the gates could not preflight were also the only pages the CLI could not
 * repair. Maintainer ruling D-1 approved a carve-out, quoted in
 * pp_corrupt_page_repair_carve_out()'s docblock, with three conditions that must ALL hold.
 *
 * A carve-out is a NARROWING of an enforcement gate, so the tests that matter most are the
 * ones proving what it did NOT open. They come in three families and each is named:
 *
 *   ADMITS      the exact case the ruling describes, end to end through the real gate.
 *   REFUSES     every neighbour of that case — healthy page, blank page, other verbs,
 *               other commands — still hits the gate exactly as before.
 *   HOLDS       the gates the carve-out does not touch (validation, #233, #818, the CAS)
 *               still fire on a page that IS inside the carve-out.
 *
 * TWO HAZARDS GET DEDICATED COVERAGE because both are ways the carve-out could be opened
 * by something other than the truth:
 *
 *   STALE CACHE   the classification is read from the DB, not the object cache. WordPress
 *                 warms a post's whole meta row in one query (#823), so a concurrent repair
 *                 can leave a cached copy corrupt while the row is healthy. Reading the
 *                 cache would admit a preflight-free write to a HEALTHY page. The tests
 *                 make row and cache disagree, in both directions, via the bootstrap's
 *                 `wpdb_postmeta` divergence bucket.
 *   TOCTOU        the classification is a point-in-time answer. A repair landing between
 *                 the check and the write would leave the carve-out overwriting a healthy
 *                 composition. The live CAS baseline closes it: the write is rejected with
 *                 `composition_conflict` rather than clobbering.
 *
 * Standalone-runnable: lib/cli.php is NOT in tests/bootstrap.php's require list, so the
 * WP_CLI stubs and the require below are what keep
 * `./vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/CorruptPageRepairCarveOutTest.php`
 * honest instead of order-dependent green.
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stubs (shared shape with tests/CliGateTest.php; both guard on existence) ──
if (!class_exists('WpCliExitException')) {
    class WpCliExitException extends \RuntimeException {}
}
if (!class_exists('WpCliHaltException')) {
    class WpCliHaltException extends \RuntimeException {}
}
if (!class_exists('WP_CLI_Command')) {
    class WP_CLI_Command {}
}
if (!class_exists('WP_CLI')) {
    class WP_CLI {
        public static array $lines = [];
        public static array $warnings = [];
        public static array $successes = [];
        public static function error($message, $exit = true): void { throw new WpCliExitException((string) $message); }
        public static function add_command($name, $handler, $args = []): void {}
        public static function add_hook($when, $callback): void {}
        public static function line($message = ''): void { self::$lines[] = (string) $message; }
        public static function warning($message = ''): void { self::$warnings[] = (string) $message; }
        public static function success($message = ''): void { self::$successes[] = (string) $message; }
        public static function debug($message = '', $group = false): void {}
        public static function log($message = ''): void {}
        public static function halt($code = 0): void { throw new WpCliHaltException((string) $code, (int) $code); }
    }
}

require_once dirname(__DIR__) . '/lib/cli.php';

/**
 * The bootstrap's shared `wpdb` stub answers every GET_LOCK with NULL, which
 * FrontPageSafeguardTest depends on (it scripts a failed-lock write). These tests need the
 * opposite: real writes have to land, so the advisory lock must be GRANTED. Everything else
 * — the authoritative `_pp_composition` SELECT, the guid lookup, `prepare()` — is inherited,
 * so this subclass changes exactly one behaviour and nothing about the read under test.
 */
class PP_CarveOut_Lockable_Wpdb extends wpdb
{
    public function get_var(string $query)
    {
        if (str_contains($query, 'GET_LOCK')) {
            return '1';
        }
        return parent::get_var($query);
    }

    public function query(string $query)
    {
        return 1; // RELEASE_LOCK
    }
}

class CorruptPageRepairCarveOutTest extends TestCase
{
    /** @var string[] Run tokens created during a test, cleaned up in tearDown. */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // setUp owns the reset in BOTH directions, so no test in this file depends on a
        // preceding tearDown having run (or on what an earlier file left in the store).
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';
        $GLOBALS['_pp_test_store']['post_meta']     = [];
        $GLOBALS['_pp_test_store']['posts']         = [];
        $GLOBALS['_pp_test_store']['wpdb_postmeta'] = [];
        // The carve-out's read is authoritative BY DESIGN, so these tests must exercise the
        // database branch rather than quietly proving the no-$wpdb fallback. Installing the
        // handle here is the difference between testing the gate and testing its shim.
        $GLOBALS['wpdb'] = new PP_CarveOut_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        foreach ($this->runs as $run_id) {
            pp_operate_cleanup_run($run_id);
        }
        $this->runs = [];
        unset(
            $GLOBALS['wpdb'],
            $GLOBALS['_pp_test_store']['wpdb_postmeta'],
            $GLOBALS['_pp_test_store']['options']['siteurl']
        );
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        $GLOBALS['_pp_test_store']['posts']     = [];
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function newRun(): string
    {
        $run_id = pp_operate_create_run();
        $this->assertIsString($run_id, 'run token created');
        $this->runs[] = $run_id;
        return $run_id;
    }

    /** A valid one-band composition, the shape a repair sends. */
    private function healthyComposition(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'Recovered']]];
    }

    /**
     * A page storing an OBJECT-shaped composition: the `unexpected_shape` classification,
     * written through the versioned writer so the page also carries a real version marker.
     */
    private function corruptPageUnexpectedShape(string $title = 'Corrupt (object)'): int
    {
        $post_id = pp_create_page($title, 'draft');
        pp_update_composition($post_id, [
            1 => ['component' => 'hero', 'props' => ['id' => 'kept-1', 'title' => 'Kept one']],
            3 => ['component' => 'hero', 'props' => ['id' => 'kept-3', 'title' => 'Kept three']],
        ]);
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error'], 'premise');
        return $post_id;
    }

    /** A page storing undecodable bytes: the `decode_error` classification. */
    private function corruptPageDecodeError(string $title = 'Corrupt (bytes)'): int
    {
        $post_id = pp_create_page($title, 'draft');
        update_post_meta($post_id, '_pp_composition', 'NOT_VALID_JSON{{{');
        $this->assertSame('decode_error', pp_get_composition_result($post_id)['error'], 'premise');
        return $post_id;
    }

    /** A page storing a readable composition. */
    private function healthyPage(string $title = 'Healthy'): int
    {
        $post_id = pp_create_page($title, 'draft');
        $this->assertTrue(pp_update_composition($post_id, $this->healthyComposition()));
        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise');
        return $post_id;
    }

    // ═══ ADMITS ══════════════════════════════════════════════════════════════

    /**
     * The predicate answers with the CLASSIFICATION, not a bare bool, so a caller can put
     * the state's own word in whatever it says next — the #650/#652 one-vocabulary rule.
     */
    public function testThePredicateAdmitsBothVerbsOnBothCorruptClassifications(): void
    {
        $shape  = $this->corruptPageUnexpectedShape();
        $decode = $this->corruptPageDecodeError();

        foreach (['update_composition', 'restore_composition'] as $verb) {
            $action = pp_get_action($verb);
            $this->assertSame('unexpected_shape', pp_corrupt_page_repair_carve_out($action, $shape), $verb);
            $this->assertSame('decode_error', pp_corrupt_page_repair_carve_out($action, $decode), $verb);
        }
    }

    /**
     * The valid-JSON-SCALAR sub-case of `unexpected_shape` (a stored `null`, `5`, `"text"`)
     * is inside the carve-out too. It decodes fine and is therefore easy to mis-read as a
     * different state — #818 had to make the same distinction one layer down, and it is the
     * class whose only recoverable copy is the preserved-bytes ring entry.
     */
    public function testThePredicateAdmitsTheScalarSubCaseOfUnexpectedShape(): void
    {
        $post_id = pp_create_page('Corrupt (scalar)', 'draft');
        update_post_meta($post_id, '_pp_composition', '5');
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error'], 'premise');

        $this->assertSame(
            'unexpected_shape',
            pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), $post_id)
        );
    }

    /**
     * The REAL gate, not the predicate: _pp_cli_require_preflight_for_action() is what
     * `wp pp action execute` calls, and its refusal is a WP_CLI::error (an exception in the
     * harness). Returning normally IS the admission.
     */
    public function testTheCliGateAdmitsARepairWithNoPreflightAtAll(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();
        $run_id  = $this->newRun();

        foreach (['update_composition', 'restore_composition'] as $verb) {
            _pp_cli_require_preflight_for_action($run_id, pp_get_action($verb), ['post_id' => $post_id]);
            // Not `assertTrue(true)`. "It did not throw" is satisfied by a gate reduced to a
            // no-op; the claim is that the gate was WAIVED, which means the run must still
            // have no coverage for this post at the moment it let the verb through.
            $this->assertFalse(
                pp_operate_preflight_covers($run_id, $post_id),
                "{$verb} passed with the run genuinely uncovered, not because coverage existed"
            );
        }
    }

    /**
     * The freshness gate (#113) is not merely waived on this path, it is UNSATISFIABLE: its
     * baseline is recorded BY the preflight command that refuses this page. Left in place it
     * would re-close the loop the coverage carve-out just opened, via its own fail-closed
     * missing-baseline branch. The decision must be `ok` — and must carry a live CAS
     * baseline rather than null, which is the TOCTOU defence tested further down.
     */
    public function testTheFreshnessGateResolvesToOkWithALiveCasBaseline(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();
        $run_id  = $this->newRun();
        $live    = (int) pp_get_composition_marker($post_id)['version'];
        $this->assertGreaterThan(0, $live, 'premise: the corrupt write left a real version marker');

        $decision = _pp_cli_composition_fresh_decision($run_id, pp_get_action('update_composition'), $post_id);

        $this->assertSame('ok', $decision['status']);
        $this->assertSame($live, $decision['version'], 'the CAS baseline is the live version, not the (absent) preflight one');
    }

    /**
     * End to end, the thing the issue said was impossible: inspect, then repair, with no
     * preflight anywhere — and the unreadable bytes survive on the history ring (#818),
     * which is why the sequencing put #818 before this issue.
     */
    public function testACorruptPageIsRepairableEndToEndAndThePriorBytesSurvive(): void
    {
        $post_id = $this->corruptPageDecodeError();
        $run_id  = $this->newRun();

        _pp_cli_require_preflight_for_action($run_id, pp_get_action('update_composition'), ['post_id' => $post_id]);
        $decision = _pp_cli_composition_fresh_decision($run_id, pp_get_action('update_composition'), $post_id);
        $this->assertSame('ok', $decision['status']);

        $result = pp_execute_action('update_composition', [
            'post_id'          => $post_id,
            'composition'      => $this->healthyComposition(),
            'expected_version' => $decision['version'],
        ]);

        $this->assertTrue($result['ok'], 'the repair write landed: ' . ($result['error'] ?? ''));
        $stored = pp_get_composition_result($post_id);
        $this->assertTrue($stored['ok'], 'the page is no longer corrupt');
        $this->assertSame('hero', $stored['composition'][0]['component']);

        // #818: the repair preserved what it replaced instead of destroying it.
        $history = pp_get_composition_history($post_id);
        $this->assertNotEmpty($history, 'the repair write pushed a ring entry');
        $last = $history[count($history) - 1];
        $this->assertArrayHasKey('raw', $last, 'undecodable prior bytes are kept as bytes, not as a composition');
        $this->assertSame('NOT_VALID_JSON{{{', $last['raw'], 'byte-exact');
    }

    // ═══ REFUSES ═════════════════════════════════════════════════════════════

    /**
     * The single most important negative: a HEALTHY page with no preflight is refused
     * exactly as it was before this change. If this ever passes, the carve-out has become
     * the general preflight bypass the ruling says it is not.
     */
    public function testAHealthyPageWithNoPreflightIsStillRefused(): void
    {
        $post_id = $this->healthyPage();
        $run_id  = $this->newRun();

        $this->assertNull(pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), $post_id));

        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no completed PREFLIGHT covering post ' . $post_id);
        _pp_cli_require_preflight_for_action($run_id, pp_get_action('update_composition'), ['post_id' => $post_id]);
    }

    /**
     * A BLANK page is not a corrupt page. `_pp_composition` absent (or empty) classifies
     * ok=true with composition=[], so a never-populated page keeps needing a preflight —
     * the distinction #144/#725/#748 exist to preserve, applied to the gate this time.
     */
    public function testABlankPageIsNotCorruptAndIsStillRefused(): void
    {
        $post_id = pp_create_page('Blank', 'draft');
        $run_id  = $this->newRun();
        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise: blank is not corrupt');

        $this->assertNull(pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), $post_id));

        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no completed PREFLIGHT covering post ' . $post_id);
        _pp_cli_require_preflight_for_action($run_id, pp_get_action('update_composition'), ['post_id' => $post_id]);
    }

    /**
     * Condition (2) is a CLOSED allowlist. Every other composition-mutating action is
     * band-level: it edits inside a composition the reader cannot produce, so it cannot
     * repair the page and gets no hatch. Asserted across all of them rather than a
     * representative one, because "someone adds a verb to the list" is the drift this
     * carve-out is most exposed to.
     */
    public function testNoOtherActionGetsTheCarveOutOnTheSameCorruptPage(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();

        $others = ['add_component', 'remove_component', 'reorder_components',
                   'update_component', 'style_component', 'create_page', 'publish_page'];
        foreach ($others as $verb) {
            $action = pp_get_action($verb);
            $this->assertNotNull($action, "premise: {$verb} is registered");
            $this->assertNull(
                pp_corrupt_page_repair_carve_out($action, $post_id),
                "{$verb} must not inherit the whole-composition carve-out"
            );
        }
    }

    public function testABandLevelActionOnACorruptPageStillHitsThePreflightGate(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();
        $run_id  = $this->newRun();

        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no completed PREFLIGHT covering post ' . $post_id);
        _pp_cli_require_preflight_for_action($run_id, pp_get_action('update_component'), ['post_id' => $post_id]);
    }

    /** A site-scoped action has no stored composition to be corrupt. */
    public function testASiteScopedActionIsOutsideTheCarveOutEntirely(): void
    {
        $this->assertNull(pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), null));
    }

    /**
     * The scope-consistency guardrail (#390) runs BEFORE the carve-out, and the ordering is
     * load-bearing: it catches action-REGISTRATION bugs, and a misdeclared action must never
     * reach a classification read that could hand it a preflight-free write.
     */
    public function testAMisdeclaredActionIsRefusedBeforeTheCarveOutIsEvenConsidered(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();

        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('unrecognized scope');
        _pp_cli_require_preflight_for_action(
            $this->newRun(),
            ['name' => 'update_composition', 'scope' => 'galaxy', 'mutates_composition' => true],
            ['post_id' => $post_id]
        );
    }

    /**
     * NON-WIDENING, asserted on the STORE rather than only on a downstream symptom: a
     * carve-out admission must record nothing. Coverage is what unlocks every other action
     * on that page, so if admission wrote any, one repair would unlock the whole run.
     */
    public function testACarveOutAdmissionRecordsNoRunStateAtAll(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();
        $run_id  = $this->newRun();
        $before  = pp_operate_read_state($run_id);

        _pp_cli_require_preflight_for_action($run_id, pp_get_action('update_composition'), ['post_id' => $post_id]);
        _pp_cli_composition_fresh_decision($run_id, pp_get_action('update_composition'), $post_id);

        $after = pp_operate_read_state($run_id);
        $this->assertSame($before['steps_completed'], $after['steps_completed'], 'no PREFLIGHT step invented');
        $this->assertSame(
            $before['preflight_post_ids'] ?? [],
            $after['preflight_post_ids'] ?? [],
            'no coverage recorded — the gate was waived for one action, not satisfied'
        );
        $this->assertFalse(pp_operate_preflight_covers($run_id, $post_id), 'the run still has no coverage for this post');
    }

    /**
     * The downstream half of the same claim, because "no coverage recorded" only matters if
     * it still bites: after a successful carve-out repair, a band-level edit on that page in
     * the SAME run is refused until a real preflight runs.
     */
    public function testAfterACarveOutRepairTheNextActionStillNeedsAPreflight(): void
    {
        $post_id = $this->corruptPageDecodeError();
        $run_id  = $this->newRun();

        _pp_cli_require_preflight_for_action($run_id, pp_get_action('update_composition'), ['post_id' => $post_id]);
        $this->assertTrue(pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->healthyComposition(),
        ])['ok']);

        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no completed PREFLIGHT covering post ' . $post_id);
        _pp_cli_require_preflight_for_action($run_id, pp_get_action('update_component'), ['post_id' => $post_id]);
    }

    // ═══ HOLDS ═══════════════════════════════════════════════════════════════

    /**
     * Condition (3), for `update_composition`: the gate admission says nothing about the
     * PAYLOAD. `pp_validate_action()` runs before the CLI reaches any preflight gate and
     * still validates the whole incoming array, so a corrupt page cannot be used as a
     * doorway to store something invalid.
     */
    public function testAnInvalidReplacementIsStillRejectedOnACarveOutPage(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();
        $this->assertSame(
            'unexpected_shape',
            pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), $post_id),
            'premise: this page IS inside the carve-out'
        );

        $rejection = pp_validate_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'no_such_component', 'props' => []]],
        ]);

        $this->assertInstanceOf(WP_Error::class, $rejection, 'full validation still runs');
        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'no_such_component', 'props' => []]],
        ]);
        $this->assertFalse($result['ok'], 'and the executor refuses, so nothing was written');
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error'], 'the corrupt bytes are untouched');
    }

    /**
     * An OBJECT-shaped replacement is refused too — the carve-out must not become a way to
     * write the very state it exists to repair (#724's container rule).
     */
    public function testTheCarveOutCannotBeUsedToStoreAnotherCorruptComposition(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [1 => ['component' => 'hero', 'props' => ['title' => 'Nope']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error_code'] ?? null);
    }

    /**
     * Condition (3) for `restore_composition` is NOT "the snapshot passes validation" — that
     * would break #233, the invariant that restore reports and never blocks, which exists so
     * undo cannot fail exactly when a user most needs it. State it as it actually is: the
     * carve-out changes nothing about what restore accepts. Its one precondition is #818's —
     * a ring slot holding preserved BYTES has no composition to replay and is refused with
     * `history_entry_not_restorable`, on a carve-out page like anywhere else.
     */
    public function testRestoreStillRefusesAPreservedBytesSlotInsideTheCarveOut(): void
    {
        // Repair once so the ring holds the preserved bytes, then corrupt the page again so
        // the carve-out is active while that slot is the restore target.
        $post_id = $this->corruptPageDecodeError();
        $this->assertTrue(pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->healthyComposition(),
        ])['ok']);
        update_post_meta($post_id, '_pp_composition', 'STILL_NOT_JSON{{{');
        $this->assertSame(
            'decode_error',
            pp_corrupt_page_repair_carve_out(pp_get_action('restore_composition'), $post_id),
            'premise: the page is inside the carve-out again'
        );

        $history = pp_get_composition_history($post_id);
        $raw_idx = null;
        foreach ($history as $i => $entry) {
            if (array_key_exists('raw', $entry)) {
                $raw_idx = $i;
            }
        }
        $this->assertNotNull($raw_idx, 'premise: the ring holds a preserved-bytes slot');

        $rejection = pp_validate_action('restore_composition', ['post_id' => $post_id, 'history_index' => $raw_idx]);
        $this->assertInstanceOf(WP_Error::class, $rejection);
        $this->assertSame('history_entry_not_restorable', $rejection->get_error_code(),
            '#818 is a precondition, not a validation veto — the carve-out does not lift it');
    }

    /**
     * The #233 contract, positively: a snapshot current rules REJECT still restores through
     * the carve-out and reports in `findings`. If the carve-out had been implemented by
     * bolting validation onto restore, this is the test that would fail.
     */
    public function testRestoreStillReplaysARuleBreakingSnapshotVerbatimInsideTheCarveOut(): void
    {
        $post_id = pp_create_page('Restore inside carve-out', 'draft');
        // Seed the ring with a snapshot carrying a prop no schema declares.
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'Old', 'retired_prop' => 'x']],
        ]));
        $this->assertTrue(pp_update_composition($post_id, $this->healthyComposition()));
        update_post_meta($post_id, '_pp_composition', 'CORRUPT_NOW{{{');
        $this->assertSame(
            'decode_error',
            pp_corrupt_page_repair_carve_out(pp_get_action('restore_composition'), $post_id),
            'premise'
        );

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);

        $this->assertTrue($result['ok'], 'restore is never blocked by current validation (#233)');
        $stored = pp_get_composition_result($post_id);
        $this->assertTrue($stored['ok'], 'the page is repaired');
        $this->assertSame('x', $stored['composition'][0]['props']['retired_prop'], 'verbatim, not sanitized');
        $this->assertNotEmpty($result['findings'], 'and the operator is told what current rules say');
    }

    // ═══ THE REAL COMMAND, not its pieces ════════════════════════════════════

    /**
     * Everything above drives the GATE FUNCTIONS. This drives `wp pp action execute` itself,
     * because the security claim depends on the ORDER inside that method and nothing below
     * it can see the order: pp_validate_action() must run BEFORE the preflight waiver, and
     * the CAS baseline must reach the write. A refactor that moved the carve-out earlier —
     * ahead of validation — would leave every other test in this file green.
     *
     * Runs the command for real, with a run token whose only recorded step is INSPECT.
     */
    private function runActionExecute(string $name, array $params): array
    {
        $run_id = $this->newRun();
        WP_CLI::$lines = [];
        try {
            (new PP_Action_Command())->execute([$name], [
                'run-id' => $run_id,
                'params' => json_encode($params),
            ]);
        } catch (WpCliHaltException $e) {
            // A refused action halts after emitting its envelope; that IS the path we assert on.
        }
        $this->assertNotEmpty(WP_CLI::$lines, 'an envelope reached stdout');
        $decoded = json_decode(WP_CLI::$lines[0], true);
        $this->assertIsArray($decoded, 'stdout line is valid JSON');
        return $decoded;
    }

    public function testTheRealCommandRepairsACorruptPageWithNoPreflight(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();

        $envelope = $this->runActionExecute('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->healthyComposition(),
        ]);

        $this->assertTrue($envelope['ok'], 'the command completed: ' . ($envelope['error'] ?? ''));
        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'the page is repaired');
    }

    public function testTheRealCommandStillRefusesAHealthyPageWithNoPreflight(): void
    {
        $post_id = $this->healthyPage();

        // Refused by the gate, which is a bare WP_CLI::error — NOT the ok:false envelope.
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no completed PREFLIGHT covering post ' . $post_id);
        (new PP_Action_Command())->execute(['update_composition'], [
            'run-id' => $this->newRun(),
            'params' => json_encode(['post_id' => $post_id, 'composition' => $this->healthyComposition()]),
        ]);
    }

    /**
     * VALIDATION RUNS FIRST, proven at the command level. An invalid replacement on a
     * carve-out page is rejected as the ok:false ENVELOPE — the shape `pp_validate_action()`
     * produces at lib/cli.php's early-validation gate — and never reaches the write.
     */
    public function testTheRealCommandValidatesBeforeItWaivesThePreflight(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();

        $envelope = $this->runActionExecute('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'no_such_component', 'props' => []]],
        ]);

        $this->assertFalse($envelope['ok'], 'validation refused it');
        $this->assertSame(
            'unexpected_shape',
            pp_get_composition_result($post_id)['error'],
            'the corrupt bytes were left untouched'
        );
    }

    /**
     * `restore_composition` through the real command, with no preflight, on a corrupt page —
     * the second half of condition (2), which every other command-level test here would miss.
     */
    public function testTheRealCommandRestoresACorruptPageWithNoPreflight(): void
    {
        $post_id = pp_create_page('Restore via command', 'draft');
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'Prior state']],
        ]));
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'h2', 'title' => 'Later state']],
        ]));
        update_post_meta($post_id, '_pp_composition', 'CORRUPT{{{');

        $envelope = $this->runActionExecute('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($envelope['ok'], 'restore completed: ' . ($envelope['error'] ?? ''));
        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'the page is repaired');
    }

    /**
     * THE CAS BASELINE ACTUALLY REACHES THE WRITE, pinned at the command that threads it.
     *
     * Everything else about the TOCTOU defence is asserted one layer below this: the earlier
     * conflict test hand-wires `expected_version` into pp_execute_action() itself, so it
     * proves pp_update_composition()'s compare-and-swap works — not that the carve-out path
     * SUPPLIES a baseline. Mutation analysis during review confirmed the gap: disabling
     * `$params['expected_version'] = $baseline_version;` in PP_Action_Command::execute left
     * all 4163 tests green while reopening a preflight-free clobber of a healthy composition.
     *
     * Staged as a row/cache divergence rather than a real thread interleave, because that IS
     * the production shape: `pp_get_composition_marker()` reads the warmed cache while
     * `_pp_read_composition_version_locked()` reads the row, so a write landing after the
     * cache was warmed leaves the row ahead. The command must carry the lower number into the
     * write and be refused there.
     */
    public function testTheCommandThreadsTheCasBaselineIntoTheWrite(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();
        // The row moved ahead of the warmed cache: another writer landed a version.
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_version'] =
            (string) ((int) pp_get_composition_marker($post_id)['version'] + 1);

        $envelope = $this->runActionExecute('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->healthyComposition(),
        ]);

        $this->assertFalse($envelope['ok'], 'the write must be refused, not landed');
        $this->assertSame('composition_conflict', $envelope['error_code'] ?? null,
            'the carve-out path supplied a CAS baseline and the in-lock check used it');
    }

    /**
     * The same pin for the version-ZERO case, which is the archetypal corrupt page.
     *
     * A page corrupted out of band (a raw meta write, a plugin, a DB edit) has never been
     * through the versioned writer, so its marker is 0 — and 0 is FALSY. A threading guard
     * written `if ($baseline_version)` instead of `!== null` would silently drop the baseline
     * for exactly this class and leave it with no CAS at all, which the test above would not
     * catch because its page has a non-zero version.
     */
    public function testTheCommandThreadsAZeroCasBaselineToo(): void
    {
        $post_id = $this->corruptPageDecodeError();
        $this->assertSame(0, (int) pp_get_composition_marker($post_id)['version'],
            'premise: a raw-corrupted page has never been versioned');
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_version'] = '1';

        $envelope = $this->runActionExecute('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->healthyComposition(),
        ]);

        $this->assertFalse($envelope['ok'], 'version 0 is a baseline, not an absent one');
        $this->assertSame('composition_conflict', $envelope['error_code'] ?? null);
    }

    /**
     * THE ADVERTISED ROUTE IS HONEST ABOUT ITS OWN LIMIT, pinned on the page class it most
     * often addresses.
     *
     * Four operator-facing messages now name `restore_composition` beside
     * `update_composition`. But a page corrupted OUT OF BAND — the archetypal `decode_error`,
     * and the one those messages usually greet — has an empty history ring, so there is
     * nothing to restore to. That is not a hazard and the refusal is the right one; what
     * matters is that it stays a clean, named refusal (`no_history`) rather than becoming
     * something worse if the route wording is ever reworked.
     */
    public function testRestoreOnAnOutOfBandCorruptedPageRefusesCleanlyWithNoHistory(): void
    {
        $post_id = $this->corruptPageDecodeError();
        $this->assertSame([], pp_get_composition_history($post_id), 'premise: the ring is empty');

        $envelope = $this->runActionExecute('restore_composition', ['post_id' => $post_id]);

        $this->assertFalse($envelope['ok']);
        $this->assertSame('no_history', $envelope['error_code'] ?? null);
        $this->assertSame('decode_error', pp_get_composition_result($post_id)['error'],
            'the refusal left the recoverable bytes exactly where they were');
    }

    // ═══ HAZARD: stale cache ═════════════════════════════════════════════════

    /**
     * THE GATE MUST NOT BE OPENABLE BY A STALE VALUE. Cache says corrupt, row says healthy —
     * the interleaving #823 documents (a concurrent repair landing after this request warmed
     * the post's meta row). Reading the cache here would admit a preflight-free write to a
     * page that is fine, which is precisely what ruling D-1 forbids.
     */
    public function testAStaleCorruptCacheOverAHealthyRowDoesNotOpenTheCarveOut(): void
    {
        $post_id = pp_create_page('Cache stale corrupt', 'draft');
        // The cache: corrupt.
        $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'] = 'STALE_CORRUPT{{{';
        // The row: healthy.
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition'] =
            wp_json_encode($this->healthyComposition());

        $this->assertSame('decode_error', pp_get_composition_result($post_id)['error'],
            'premise: the CACHED read still says corrupt');
        $this->assertTrue(pp_get_composition_result_authoritative($post_id)['ok'],
            'premise: the AUTHORITATIVE read says healthy');

        $this->assertNull(
            pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), $post_id),
            'a stale cache must not open a gate'
        );

        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no completed PREFLIGHT covering post ' . $post_id);
        _pp_cli_require_preflight_for_action($this->newRun(), pp_get_action('update_composition'), ['post_id' => $post_id]);
    }

    /**
     * A PHP-SERIALIZED ROW IS NOT A CORRUPT ROW, and the direct read must not think it is.
     *
     * `_pp_composition` normally holds JSON, but nothing enforces it: any caller handing an
     * ARRAY to update_post_meta() — an importer, a post-duplicator plugin, `wp post meta
     * update <id> _pp_composition '[...]' --format=json` — stores a PHP-serialized row.
     * get_post_meta() unserializes on the way out, so every OTHER surface (`check page`,
     * `apply preflight`, the #749 batch gate, the composition precondition) reads a healthy
     * list. A raw column read does not, and `a:1:{...}` is not JSON.
     *
     * Without maybe_unserialize() the carve-out therefore opened on a page the coverage gate
     * itself considers perfectly preflightable — condition (1) broken in the direction that
     * ADMITS the write. Caught by the security specialist during review; this is its pin.
     */
    public function testASerializedRowIsClassifiedTheSameByBothReadersAndOpensNothing(): void
    {
        $post_id = pp_create_page('Serialized row', 'draft');
        $composition = $this->healthyComposition();
        // The cache: what get_post_meta() hands back (already unserialized).
        $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'] = $composition;
        // The row: what the column actually holds for an array value.
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition'] = serialize($composition);

        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise: the cached reader says healthy');
        $this->assertTrue(
            pp_get_composition_result_authoritative($post_id)['ok'],
            'the authoritative reader must unserialize exactly as get_post_meta() does'
        );

        $this->assertNull(
            pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), $post_id),
            'a serialized row is a healthy page and must not open the carve-out'
        );

        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no completed PREFLIGHT covering post ' . $post_id);
        _pp_cli_require_preflight_for_action($this->newRun(), pp_get_action('update_composition'), ['post_id' => $post_id]);
    }

    /**
     * The other direction, which proves the read is genuinely the row and not just "some
     * second lookup that happens to agree": cache says healthy, row says corrupt. The page
     * IS corrupt, so the carve-out applies — an operator must not be locked out of repairing
     * a page because a cached copy looks fine.
     */
    public function testAStaleHealthyCacheOverACorruptRowStillOpensTheCarveOut(): void
    {
        $post_id = pp_create_page('Cache stale healthy', 'draft');
        $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'] =
            wp_json_encode($this->healthyComposition());
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition'] = 'ROW_IS_CORRUPT{{{';

        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise: the CACHED read says healthy');

        $this->assertSame(
            'decode_error',
            pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), $post_id),
            'the stored row is what the gate answers to'
        );
    }

    /**
     * `_pp_composition` is single-valued by convention, not by schema. get_post_meta(single)
     * returns the FIRST row in meta_id order, so an unordered authoritative SELECT could
     * answer from a row no other surface reads — a duplicate could open the carve-out on a
     * page every other surface calls healthy. Pinned as a static property of the query
     * because the harness models one row per key and cannot stage a duplicate.
     */
    public function testTheAuthoritativeReadPinsTheRowOrderingItSharesWithGetPostMeta(): void
    {
        // ANCHORED TO THE FUNCTION BODY, not the file. A file-wide grep passes as long as
        // SOME line somewhere in lib/wp.php carries the ordering — including one of the two
        // sibling direct readers, which do NOT carry it (#825). Slice first, then match.
        $source = file_get_contents(dirname(__DIR__) . '/lib/wp.php');
        $start  = strpos($source, 'function pp_get_composition_result_authoritative');
        $this->assertIsInt($start, 'premise: the authoritative reader exists');
        $end    = strpos($source, "\nfunction ", $start + 1);
        $body   = substr($source, $start, $end === false ? null : $end - $start);

        $this->assertMatchesRegularExpression(
            '/SELECT meta_value FROM \{\$wpdb->postmeta\}.*ORDER BY meta_id ASC LIMIT 1/',
            $body,
            'the authoritative read must select the same row get_post_meta(single) returns'
        );
    }

    // ═══ HAZARD: TOCTOU ══════════════════════════════════════════════════════

    /**
     * The carve-out check is a point-in-time answer. If another writer repairs the page
     * between the check and the write, condition (1) has stopped being true and the write
     * must NOT land — otherwise the escape hatch overwrites a healthy composition with no
     * preflight and no conflict, which is a data-loss hole in the gate this issue narrows.
     * The live CAS baseline closes it inside pp_update_composition()'s per-post lock.
     */
    public function testAConcurrentRepairMakesTheCarveOutWriteConflictInsteadOfClobbering(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();
        $run_id  = $this->newRun();

        _pp_cli_require_preflight_for_action($run_id, pp_get_action('update_composition'), ['post_id' => $post_id]);
        $decision = _pp_cli_composition_fresh_decision($run_id, pp_get_action('update_composition'), $post_id);
        $baseline = $decision['version'];

        // Another writer repairs the page first.
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'other', 'title' => 'Repaired by someone else']],
        ]));

        // PREMISE, asserted rather than assumed. An earlier version of this test passed
        // because the harness modelled an empty postmeta table, so the in-lock version read
        // was ALWAYS 0 and every CAS "conflict" was a stub artifact rather than the
        // interleaving under test. Pin that the baseline and the post-interleave version
        // genuinely differ, so a regression to that state fails here instead of passing.
        $this->assertNotSame(
            $baseline,
            (int) pp_get_composition_marker($post_id)['version'],
            'premise: the concurrent repair actually moved the version'
        );

        $result = pp_execute_action('update_composition', [
            'post_id'          => $post_id,
            'composition'      => $this->healthyComposition(),
            'expected_version' => $baseline,
        ]);

        $this->assertFalse($result['ok'], 'the page stopped being the corrupt page the hatch was opened for');
        $this->assertSame('composition_conflict', $result['error_code'] ?? null);
        $this->assertSame(
            'Repaired by someone else',
            pp_get_composition_result($post_id)['composition'][0]['props']['title'],
            'the other writer\'s composition was not clobbered'
        );
    }

    // ═══ The refactor that made the authoritative read possible ══════════════

    /**
     * The reader/classifier split changed no classification, and BOTH readers agree.
     *
     * Asserted as ABSOLUTE expectations per state, not as `cached === classifier`. That
     * equivalence was the first shape of this test and it was a tautology:
     * pp_get_composition_result() IS pp_classify_composition_value(get_post_meta(...)), so
     * both sides ran the same code and the test would stay green with the classifier gutted
     * to return a constant. Naming the expected answer for each row is what makes the state
     * table coverage instead of decoration. The row/cache agreement is asserted here too,
     * with the handle installed and the row matching the cache — the baseline the two
     * divergence tests above deviate from.
     */
    public function testBothReadersClassifyTheWholeStateTableIdentically(): void
    {
        // state => [stored value, expected ok, expected error]
        $states = [
            'absent'            => [null,                                  true,  null],
            'empty string'      => ['',                                    true,  null],
            'valid list'        => ['[{"component":"hero","props":{}}]',   true,  null],
            'empty list'        => ['[]',                                  true,  null],
            'json object'       => ['{"1":{"component":"hero"}}',          false, 'unexpected_shape'],
            'json scalar'       => ['5',                                   false, 'unexpected_shape'],
            'json null'         => ['null',                                false, 'unexpected_shape'],
            'undecodable'       => ['NOT_JSON{{{',                         false, 'decode_error'],
            'decoded list'      => [[['component' => 'hero', 'props' => []]], true,  null],
            'decoded non-list'  => [[1 => ['component' => 'hero']],        false, 'unexpected_shape'],
            'non-string scalar' => [42,                                    false, 'unexpected_shape'],
        ];

        foreach ($states as $label => [$value, $expect_ok, $expect_error]) {
            $post_id = pp_create_page('State ' . $label, 'draft');
            if ($value !== null) {
                $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'] = $value;
            }

            $cached = pp_get_composition_result($post_id);
            $this->assertSame($expect_ok, $cached['ok'], "cached ok for: {$label}");
            $this->assertSame($expect_error, $cached['error'], "cached classification for: {$label}");

            $authoritative = pp_get_composition_result_authoritative($post_id);
            $this->assertSame($expect_ok, $authoritative['ok'], "authoritative ok for: {$label}");
            $this->assertSame($expect_error, $authoritative['error'], "authoritative classification for: {$label}");
        }
    }

    /**
     * With no usable $wpdb handle the READER degrades to the cached read — and the GATE does
     * not. The split is the whole posture: degrading is right for a reader with no database
     * to ask, and wrong for a gate, whose only safe answer is "no".
     *
     * Production WordPress always has a full handle, so both are unit-context paths; they are
     * pinned so the behaviour is stated rather than silent.
     */
    public function testWithNoDatabaseHandleTheReaderDegradesButTheCarveOutRefuses(): void
    {
        $post_id = $this->corruptPageDecodeError();
        unset($GLOBALS['wpdb']);

        $this->assertNull(pp_composition_db_handle(), 'premise: no usable handle');
        $this->assertSame(
            pp_get_composition_result($post_id),
            pp_get_composition_result_authoritative($post_id),
            'the reader degrades to the cached classification'
        );
        $this->assertNull(
            pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), $post_id),
            'the gate does NOT degrade — an unverifiable classification opens nothing'
        );
    }

    /**
     * A PARTIAL handle is not a usable one, and each clause is pinned separately.
     *
     * Mutation analysis during review reduced the four-clause guard to bare `is_object($wpdb)`
     * and produced ZERO failures across the whole suite — nothing held the inner clauses in
     * either direction. Partial doubles are a real shape here (several test files install a
     * handle implementing get_var and nothing else), and reading `$wpdb->postmeta` off one
     * yields an undefined-property warning and an empty table name: a query whose answer must
     * not be trusted, let alone allowed to open a gate.
     */
    public function testAPartialDatabaseHandleIsNotUsableAndOpensNothing(): void
    {
        $post_id = $this->corruptPageDecodeError();

        $partial = [
            'no postmeta property' => new class {
                public function get_var(string $q) { return null; }
                public function prepare(string $q, ...$a): string { return $q; }
            },
            'no prepare()'         => new class {
                public string $postmeta = 'wp_postmeta';
                public function get_var(string $q) { return null; }
            },
            'no get_var()'         => new class {
                public string $postmeta = 'wp_postmeta';
                public function prepare(string $q, ...$a): string { return $q; }
            },
            'not an object'        => 'wpdb',
        ];

        foreach ($partial as $label => $handle) {
            $GLOBALS['wpdb'] = $handle;
            $this->assertNull(pp_composition_db_handle(), "handle must be rejected: {$label}");
            $this->assertNull(
                pp_corrupt_page_repair_carve_out(pp_get_action('update_composition'), $post_id),
                "a partial handle must not open the carve-out: {$label}"
            );
        }
    }

    /**
     * An action array with no `name` key — the shape a registration bug or a careless future
     * caller produces — must not open the hatch. The fallback is fail-closed by construction
     * (an empty string never matches the allowlist), which is exactly the direction worth
     * pinning on a gate-narrowing predicate rather than leaving to inspection.
     */
    public function testAnActionArrayWithNoNameOpensNothing(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();

        $this->assertNull(pp_corrupt_page_repair_carve_out(
            ['scope' => 'page', 'mutates_composition' => true],
            $post_id
        ));
    }
}
