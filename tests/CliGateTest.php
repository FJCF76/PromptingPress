<?php
/**
 * tests/CliGateTest.php — Fail-closed coverage for the WP-CLI gate stack (#390).
 *
 * The gate composition in lib/cli.php (run-id validation, preflight coverage,
 * scope-consistency, composition freshness) was load-bearing but untested,
 * because each wrapper called WP_CLI::error() directly — a process exit that is
 * hostile to unit testing. #390 extracts the decision of each gate into a pure
 * predicate (message-or-null, or a discriminated result for freshness) so every
 * fail-closed branch is assertable without the exit. This file pins:
 *
 *   1. the pure predicates directly (the decision), and
 *   2. the thin WP_CLI wrappers (the composition), via a WP_CLI stub whose
 *      error() throws a catchable exception in place of exit(1) — proving the
 *      wrappers still fail closed and still emit the exact user-facing messages.
 *
 * Out of scope for the ORIGINAL #390 predicate pins: the #358 composition-
 * precondition branch — #390's pins all short-circuit BEFORE it. #387 then
 * relocated the precondition out of the CLI gate and into the shared validator
 * pp_validate_action() (lib/actions.php), so _pp_cli_require_preflight_for_action()
 * now enforces coverage + scope only. The operate-patch pins below reflect that:
 * the composition-less rejection is enforced inside pp_patch_composition() (via
 * the shared predicate), not by the CLI gate wrapper.
 *
 * #399 added a page-existence gate at the top of pp_patch_composition() (reusing
 * the shared _pp_validate_page_exists()), so the patch path now reports not_found /
 * not_a_page for a nonexistent / non-page id BEFORE the composition precondition —
 * parity with `action execute`. The pins below cover all three orderings:
 * nonexistent → not_found, non-page → not_a_page, existing + composition-less →
 * composition_required.
 */

use PHPUnit\Framework\TestCase;

// ── Minimal WP-CLI stubs so lib/cli.php loads outside a real WP-CLI runtime ──
// error() throws instead of exiting, so a wrapper's fail-closed branch is
// observable as a catchable exception carrying the exact user-facing message.
if (!class_exists('WpCliExitException')) {
    class WpCliExitException extends \RuntimeException {}
}
// halt() (a clean, non-zero process exit that emits nothing itself) throws a
// distinct exception carrying the exit code, so tests can tell an envelope-on-
// stdout-then-halt(1) failure (#385) apart from a bare WP_CLI::error on stderr.
if (!class_exists('WpCliHaltException')) {
    class WpCliHaltException extends \RuntimeException {}
}
if (!class_exists('WP_CLI_Command')) {
    class WP_CLI_Command {}
}
if (!class_exists('WP_CLI')) {
    class WP_CLI {
        /** @var string[] Captured line() output — the machine-readable stdout channel. */
        public static array $lines = [];
        /** @var string[] Captured warning() output — the advisory stderr channel. */
        public static array $warnings = [];
        /** @var string[] Captured success() output — the "all clear" channel (#622 asserts its ABSENCE on a stale page). */
        public static array $successes = [];
        public static function error($message, $exit = true): void { throw new WpCliExitException((string) $message); }
        public static function add_command($name, $handler, $args = []): void {}
        // #685 registers a before_run_command hook at load time; the stub only
        // needs to accept it — the hook's decision is pinned directly instead.
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

class CliGateTest extends TestCase
{
    /** @var string[] Run tokens created during a test, cleaned up in tearDown. */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Stable site identity so a run created and read within one test matches.
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';
        $GLOBALS['_pp_test_store']['post_meta'] = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->runs as $run_id) {
            pp_operate_cleanup_run($run_id);
        }
        $this->runs = [];
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        // Drop pages this class registers so the shared posts store stays clean (#399).
        unset($GLOBALS['_pp_test_store']['posts'][501], $GLOBALS['_pp_test_store']['posts'][504]);
        parent::tearDown();
    }

    /** Creates a run token and registers it for cleanup. */
    private function newRun(): string
    {
        $run_id = pp_operate_create_run();
        $this->assertIsString($run_id, 'run token created');
        $this->runs[] = $run_id;
        return $run_id;
    }

    /** Sets the live composition marker (what pp_get_composition_marker reads). */
    private function setLiveMarker(int $post_id, int $version, string $hash): void
    {
        update_post_meta($post_id, '_pp_composition_version', $version);
        update_post_meta($post_id, '_pp_composition_hash', $hash);
    }

    // ── Gate 1: run-id validation ────────────────────────────────────────────

    public function testRunIdErrorMissing(): void
    {
        $error = _pp_cli_run_id_error([]);
        $this->assertNotNull($error);
        $this->assertStringContainsString('--run-id is required', $error);
    }

    public function testRunIdErrorEmptyString(): void
    {
        // empty('') is true — the missing branch, not the invalid branch.
        $error = _pp_cli_run_id_error(['run-id' => '']);
        $this->assertNotNull($error);
        $this->assertStringContainsString('--run-id is required', $error);
    }

    public function testRunIdErrorInvalidUuid(): void
    {
        $error = _pp_cli_run_id_error(['run-id' => 'not-a-uuid']);
        $this->assertNotNull($error);
        $this->assertStringContainsString('valid UUID v4', $error);
        $this->assertStringContainsString('not-a-uuid', $error, 'echoes the offending value');
    }

    public function testRunIdErrorValidReturnsNull(): void
    {
        $this->assertNull(_pp_cli_run_id_error(['run-id' => '123e4567-e89b-42d3-a456-426614174000']));
    }

    public function testRequireRunIdThrowsOnMissing(): void
    {
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('--run-id is required');
        _pp_cli_require_run_id([]);
    }

    public function testRequireRunIdThrowsOnInvalid(): void
    {
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('valid UUID v4');
        _pp_cli_require_run_id(['run-id' => 'garbage']);
    }

    public function testRequireRunIdReturnsValidToken(): void
    {
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        $this->assertSame($uuid, _pp_cli_require_run_id(['run-id' => $uuid]));
    }

    // ── Gate 2: scope-consistency / preflight target resolution ──────────────

    public function testPreflightTargetErrorUnrecognizedScope(): void
    {
        $error = _pp_cli_preflight_target_error(['name' => 'weird_action', 'scope' => 'galaxy'], 5);
        $this->assertNotNull($error);
        $this->assertStringContainsString('unrecognized scope "galaxy"', $error);
        $this->assertStringContainsString('weird_action', $error);
    }

    public function testPreflightTargetErrorMissingScopeKeyIsUnrecognized(): void
    {
        // No 'scope' key defaults to 'unknown', which is not page/section/site.
        $error = _pp_cli_preflight_target_error(['name' => 'no_scope'], null);
        $this->assertNotNull($error);
        $this->assertStringContainsString('unrecognized scope "unknown"', $error);
    }

    public function testPreflightTargetErrorPageWithoutPostId(): void
    {
        $error = _pp_cli_preflight_target_error(['name' => 'set_prop', 'scope' => 'page'], null);
        $this->assertNotNull($error);
        $this->assertStringContainsString('page-scoped but no post_id was provided', $error);
    }

    public function testPreflightTargetErrorSectionWithoutPostId(): void
    {
        $error = _pp_cli_preflight_target_error(['name' => 'edit_section', 'scope' => 'section'], null);
        $this->assertNotNull($error);
        $this->assertStringContainsString('section-scoped but no post_id was provided', $error);
    }

    public function testPreflightTargetErrorSiteWithPostId(): void
    {
        $error = _pp_cli_preflight_target_error(['name' => 'set_logo', 'scope' => 'site'], 5);
        $this->assertNotNull($error);
        $this->assertStringContainsString('site-scoped but a post_id', $error);
    }

    public function testPreflightTargetErrorValidPageReturnsNull(): void
    {
        $this->assertNull(_pp_cli_preflight_target_error(['name' => 'set_prop', 'scope' => 'page'], 5));
    }

    public function testPreflightTargetErrorValidSiteReturnsNull(): void
    {
        $this->assertNull(_pp_cli_preflight_target_error(['name' => 'set_logo', 'scope' => 'site'], null));
    }

    // ── Gate 3: preflight coverage ───────────────────────────────────────────

    public function testPreflightCoverageErrorWhenNotCovered(): void
    {
        // A fresh run has INSPECT only — no PREFLIGHT recorded for post 42.
        $run_id = $this->newRun();
        $error = _pp_cli_preflight_coverage_error($run_id, 42);
        $this->assertNotNull($error);
        $this->assertStringContainsString('no completed PREFLIGHT covering post 42', $error);
        $this->assertStringContainsString('--post_id=42', $error, 'hint targets the post');
    }

    public function testPreflightCoverageErrorSiteScopeWording(): void
    {
        $run_id = $this->newRun();
        $error = _pp_cli_preflight_coverage_error($run_id, null);
        $this->assertNotNull($error);
        $this->assertStringContainsString('no completed PREFLIGHT covering site-scoped changes', $error);
    }

    public function testPreflightCoverageErrorNullWhenCovered(): void
    {
        $run_id = $this->newRun();
        $this->assertTrue(pp_operate_record_preflight($run_id, 42, [], ['version' => 1, 'hash' => 'h'], []));
        $this->assertNull(_pp_cli_preflight_coverage_error($run_id, 42));
    }

    // ── Gate 2+3 composition, via the wrapper (fail-closed, pre-#358) ─────────

    public function testRequirePreflightForActionThrowsOnUnrecognizedScope(): void
    {
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('unrecognized scope');
        _pp_cli_require_preflight_for_action(
            '123e4567-e89b-42d3-a456-426614174000',
            ['name' => 'weird', 'scope' => 'galaxy'],
            ['post_id' => 5]
        );
    }

    public function testRequirePreflightForActionThrowsOnPageWithoutPostId(): void
    {
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no post_id was provided');
        _pp_cli_require_preflight_for_action(
            '123e4567-e89b-42d3-a456-426614174000',
            ['name' => 'set_prop', 'scope' => 'page'],
            []
        );
    }

    public function testRequirePreflightForActionThrowsOnSiteWithPostId(): void
    {
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('site-scoped but a post_id');
        _pp_cli_require_preflight_for_action(
            '123e4567-e89b-42d3-a456-426614174000',
            ['name' => 'set_logo', 'scope' => 'site'],
            ['post_id' => 5]
        );
    }

    public function testRequirePreflightForActionThrowsOnMissingCoverage(): void
    {
        // Scope is consistent, so the target gate passes; coverage then fails
        // (no PREFLIGHT recorded) — reached BEFORE the #358 precondition.
        $run_id = $this->newRun();
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no completed PREFLIGHT covering post 77');
        _pp_cli_require_preflight_for_action(
            $run_id,
            ['name' => 'set_prop', 'scope' => 'page'],
            ['post_id' => 77]
        );
    }

    // ── Gate 4: composition freshness ────────────────────────────────────────

    public function testFreshnessDecisionNoOpForNonMutatingAction(): void
    {
        $decision = _pp_cli_composition_fresh_decision('123e4567-e89b-42d3-a456-426614174000', ['name' => 'set_title', 'scope' => 'page'], 5);
        $this->assertSame(['status' => 'ok', 'version' => null], $decision);
    }

    public function testFreshnessDecisionNoOpForSiteScope(): void
    {
        // Even a composition-mutating action with no post_id (site grain) is a no-op.
        $decision = _pp_cli_composition_fresh_decision('123e4567-e89b-42d3-a456-426614174000', ['name' => 'x', 'scope' => 'site', 'mutates_composition' => true], null);
        $this->assertSame(['status' => 'ok', 'version' => null], $decision);
    }

    public function testFreshnessDecisionErrorOnMissingBaseline(): void
    {
        // Mutating action, valid run, but no snapshot recorded for post 7.
        $run_id = $this->newRun();
        $decision = _pp_cli_composition_fresh_decision($run_id, ['name' => 'set_prop', 'scope' => 'page', 'mutates_composition' => true], 7);
        $this->assertSame('error', $decision['status']);
        $this->assertStringContainsString('no composition freshness baseline for post 7', $decision['message']);
    }

    public function testFreshnessDecisionErrorOnStaleMarker(): void
    {
        // Recorded marker v1/a; live marker v2/b — the composition moved under us.
        $run_id = $this->newRun();
        $this->assertTrue(pp_operate_record_preflight($run_id, 9, [], ['version' => 1, 'hash' => 'a'], []));
        $this->setLiveMarker(9, 2, 'b');
        $decision = _pp_cli_composition_fresh_decision($run_id, ['name' => 'set_prop', 'scope' => 'page', 'mutates_composition' => true], 9);
        $this->assertSame('error', $decision['status']);
        $this->assertStringContainsString('[composition_conflict]', $decision['message']);
        $this->assertStringContainsString('preflight version 1, live version 2', $decision['message']);
    }

    public function testFreshnessDecisionOkReturnsBaselineVersion(): void
    {
        // Recorded marker matches the live marker → accept, returning the CAS baseline.
        $run_id = $this->newRun();
        $this->assertTrue(pp_operate_record_preflight($run_id, 11, [], ['version' => 3, 'hash' => 'match'], []));
        $this->setLiveMarker(11, 3, 'match');
        $decision = _pp_cli_composition_fresh_decision($run_id, ['name' => 'set_prop', 'scope' => 'page', 'mutates_composition' => true], 11);
        $this->assertSame(['status' => 'ok', 'version' => 3], $decision);
    }

    public function testRequireCompositionFreshThrowsOnMissingBaseline(): void
    {
        $run_id = $this->newRun();
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('no composition freshness baseline for post 7');
        _pp_cli_require_composition_fresh($run_id, ['name' => 'set_prop', 'scope' => 'page', 'mutates_composition' => true], 7);
    }

    public function testRequireCompositionFreshThrowsOnStaleMarker(): void
    {
        $run_id = $this->newRun();
        $this->assertTrue(pp_operate_record_preflight($run_id, 9, [], ['version' => 1, 'hash' => 'a'], []));
        $this->setLiveMarker(9, 2, 'b');
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('[composition_conflict]');
        _pp_cli_require_composition_fresh($run_id, ['name' => 'set_prop', 'scope' => 'page', 'mutates_composition' => true], 9);
    }

    public function testRequireCompositionFreshReturnsVersionWhenFresh(): void
    {
        $run_id = $this->newRun();
        $this->assertTrue(pp_operate_record_preflight($run_id, 11, [], ['version' => 3, 'hash' => 'match'], []));
        $this->setLiveMarker(11, 3, 'match');
        $version = _pp_cli_require_composition_fresh($run_id, ['name' => 'set_prop', 'scope' => 'page', 'mutates_composition' => true], 11);
        $this->assertSame(3, $version);
    }

    public function testRequireCompositionFreshReturnsNullForNoOp(): void
    {
        // Non-mutating action → no CAS baseline applies.
        $version = _pp_cli_require_composition_fresh('123e4567-e89b-42d3-a456-426614174000', ['name' => 'set_title', 'scope' => 'page'], 5);
        $this->assertNull($version);
    }

    // ── operate-patch unification with the shared per-action gate (#391) ──────
    // `operate patch` no longer hand-rolls a synthetic partial action array; it
    // resolves the REAL update_component registration and routes through
    // _pp_cli_require_preflight_for_action(). These pin that the routing target
    // still carries the metadata the shared gate consumes, that the newly-shared
    // #358 precondition now fires for the patch path, and — via a source tripwire —
    // that patch() actually calls the shared gate (a helper test alone can't catch
    // a patch() that stops calling it).

    public function testUpdateComponentRegistrationSupportsPatchGateRouting(): void
    {
        // The patch path keys on these three fields of the real registration:
        //   scope='section'        → _pp_cli_preflight_target_error resolves the target;
        //   mutates_composition    → freshness gate + baseline refresh engage;
        //   requires_composition   → #358 precondition engages (defaulted TRUE by
        //                            pp_register_action, since update_component omits it).
        //                            Since #387 the precondition fires in the shared
        //                            validator / pp_patch_composition(), but it still
        //                            reads THIS flag, so the assertion below still guards it.
        // If any drifts, the patch routing silently changes behavior — fail here first.
        $action = pp_get_action('update_component');
        $this->assertIsArray($action, 'update_component must be registered for patch to route through it');
        $this->assertSame('section', $action['scope']);
        $this->assertTrue($action['mutates_composition']);
        $this->assertTrue($action['requires_composition'], 'defaulted TRUE by pp_register_action → #358 gate engages for patch');
    }

    public function testPatchGateRejectsCompositionLessPageViaSharedGate(): void
    {
        // Post-#387: the CLI gate wrapper (coverage + scope) no longer enforces the
        // #358 precondition — that moved into the shared validator. On a composition-
        // less page with a covering preflight, the wrapper now PASSES (coverage +
        // scope are both satisfied); the composition_required rejection is enforced
        // one layer in, inside pp_patch_composition() via the shared predicate,
        // failing closed early with the clear error instead of a late component_not_found.
        $run_id  = $this->newRun();
        $post_id = 501;
        // The page must EXIST (#399): the composition-less rejection is only reachable
        // once the page-existence gate passes. Register 501 as a real page so this test
        // exercises "existing + composition-less → composition_required", not "not_found".
        $GLOBALS['_pp_test_store']['posts'][$post_id] = ['post_type' => 'page', 'post_title' => 'Composition-less', 'post_status' => 'publish'];
        $this->assertTrue(pp_operate_record_preflight($run_id, $post_id, [], ['version' => 0, 'hash' => 'empty'], []));
        // No _pp_composition meta set → pp_get_composition() returns [].
        $action = pp_get_action('update_component');

        // The CLI gate wrapper no longer throws for the composition-less page.
        $threw = false;
        try {
            _pp_cli_require_preflight_for_action($run_id, $action, ['post_id' => $post_id]);
        } catch (WpCliExitException $e) {
            $threw = true;
        }
        $this->assertFalse($threw, 'the CLI gate wrapper enforces coverage+scope only after #387; the #358 gate is not here');

        // The shared precondition now fires inside pp_patch_composition() itself.
        $result = pp_patch_composition($post_id, 'hero.title', 'anything');
        $this->assertInstanceOf(WP_Error::class, $result, 'patching a composition-less page must fail closed');
        $this->assertSame('composition_required', $result->get_error_code());
        $this->assertStringContainsString(
            'operates on an existing composition, but post ' . $post_id . ' has none yet',
            $result->get_error_message()
        );
    }

    public function testPatchNonexistentPageFailsWithNotFoundBeforeCompositionPrecondition(): void
    {
        // #399: a numeric-but-nonexistent post id must fail with the SAME not_found
        // class `action execute` produces (via _pp_validate_page_exists() in
        // pp_validate_action()), BEFORE the step-2a composition precondition fires.
        // Before the fix it surfaced the misleading 'composition_required'
        // ("post N has none yet") for a page that does not exist.
        $post_id = 999999;
        // Guarantee isolation from any prior test that might have registered this id
        // in the shared store, then confirm it is genuinely absent.
        unset($GLOBALS['_pp_test_store']['posts'][$post_id]);
        $this->assertNull(get_post($post_id), 'guard test requires a genuinely nonexistent id');

        $result = pp_patch_composition($post_id, 'hero.title', 'anything');
        $this->assertInstanceOf(WP_Error::class, $result, 'patching a nonexistent page must fail closed');
        $this->assertSame('not_found', $result->get_error_code(), 'nonexistent page reports not_found, not composition_required');

        // Parity holds on the read-only --preview path too (both route through the gate).
        $preview = pp_patch_composition($post_id, 'hero.title', 'anything', /* preview */ true);
        $this->assertInstanceOf(WP_Error::class, $preview);
        $this->assertSame('not_found', $preview->get_error_code());
    }

    public function testPatchNonPagePostFailsWithNotAPage(): void
    {
        // #399 parity: a post that exists but is not a 'page' reports not_a_page —
        // the same class action execute's semantic validate emits — again before the
        // composition precondition. Uses the same shared _pp_validate_page_exists().
        $post_id = 504;
        $GLOBALS['_pp_test_store']['posts'][$post_id] = ['post_type' => 'post', 'post_title' => 'Blog Post', 'post_status' => 'publish'];

        $result = pp_patch_composition($post_id, 'hero.title', 'anything');
        $this->assertInstanceOf(WP_Error::class, $result, 'patching a non-page post must fail closed');
        $this->assertSame('not_a_page', $result->get_error_code());

        // Parity holds on the read-only --preview path too.
        $preview = pp_patch_composition($post_id, 'hero.title', 'anything', /* preview */ true);
        $this->assertInstanceOf(WP_Error::class, $preview);
        $this->assertSame('not_a_page', $preview->get_error_code());
    }

    public function testPatchGateAcceptsPreflightedComponentEditViaSharedGate(): void
    {
        // Valid, preflighted component edit against a populated, UNCHANGED page:
        // the FULL non-preview patch gate sequence accepts — coverage + scope
        // (require_preflight_for_action) AND the freshness gate (#113), exactly the
        // two gates patch() runs with the real update_component action. The #358
        // precondition (relocated in #387) also passes here because the page has
        // content. "No change to patch semantics for valid, preflighted component edits."
        $run_id  = $this->newRun();
        $post_id = 502;
        $this->assertTrue(pp_operate_record_preflight($run_id, $post_id, [], ['version' => 1, 'hash' => 'h'], []));
        // Non-empty composition (already-decoded list fixture → returned as-is).
        update_post_meta($post_id, '_pp_composition', [['type' => 'hero', 'props' => []]]);
        // Live marker matches the recorded baseline → the freshness gate accepts.
        $this->setLiveMarker($post_id, 1, 'h');
        $action = pp_get_action('update_component');
        _pp_cli_require_preflight_for_action($run_id, $action, ['post_id' => $post_id]);
        $baseline = _pp_cli_require_composition_fresh($run_id, $action, $post_id);
        $this->assertSame(1, $baseline, 'freshness gate accepts and returns the CAS baseline for the real update_component action');
    }

    public function testPatchCommandRoutesThroughSharedPerActionGate(): void
    {
        // Source tripwire: the behavioral tests above exercise the shared gate, but
        // only this pins that PP_Target_Command::patch() actually CALLS it. A
        // regression that reverts patch() to the hand-rolled synthetic gate would
        // pass every helper test yet fail here. (Same idiom as InvariantTest /
        // NavReadinessTest reading source back.)
        $src = file_get_contents(dirname(__DIR__) . '/lib/cli.php');
        $this->assertNotFalse($src, 'lib/cli.php must be readable');

        $start = strpos($src, 'public function patch(');
        $this->assertNotFalse($start, 'patch() method must exist');
        // Bound the slice at the next method so we assert on patch() only.
        $next  = strpos($src, 'public function ', $start + 1);
        $patch = $next !== false ? substr($src, $start, $next - $start) : substr($src, $start);

        // Loose positive matches (call name + real-action resolution) so a benign
        // reflow or variable rename does not false-fail; the high-value guard is the
        // variable-name-agnostic negative assertion that the synthetic array is gone.
        $this->assertStringContainsString(
            '_pp_cli_require_preflight_for_action(',
            $patch,
            'patch() must route through the shared per-action gate (#391)'
        );
        $this->assertStringContainsString(
            "pp_get_action('update_component')",
            $patch,
            'patch() must gate against the real update_component registration'
        );
        $this->assertStringNotContainsString(
            "['mutates_composition' => true]",
            $patch,
            'patch() must not rebuild the synthetic partial action array (#391)'
        );
    }

    // ── #409: split not-found vs expired PREFLIGHT-record error ───────────────

    public function testPreflightRecordMessageNotFoundIsDistinctAndNamesEnvironment(): void
    {
        // The ephemeral-container case: the run was never minted on this install.
        $run_id = '00000000-0000-4000-8000-000000000000';
        $msg = _pp_cli_preflight_record_failed_message($run_id, 'not_found');
        $this->assertStringContainsString($run_id, $msg);
        $this->assertStringContainsString('No run state found', $msg);
        $this->assertStringContainsString('different environments', $msg);
        // Must NOT misattribute an absent run to TTL expiry (the old misleading message).
        $this->assertStringNotContainsString('expired', $msg);
    }

    public function testPreflightRecordMessageExpiredIsDistinct(): void
    {
        $run_id = '00000000-0000-4000-8000-000000000000';
        $msg = _pp_cli_preflight_record_failed_message($run_id, 'expired');
        $this->assertStringContainsString('expired', $msg);
        $this->assertStringContainsString('operate inspect', $msg);
        // Distinct from the not-found wording.
        $this->assertStringNotContainsString('No run state found', $msg);
    }

    public function testPreflightRecordMessageForeignAndCorruptAndOkAreDistinct(): void
    {
        $run_id = '00000000-0000-4000-8000-000000000000';
        $foreign = _pp_cli_preflight_record_failed_message($run_id, 'foreign');
        $corrupt = _pp_cli_preflight_record_failed_message($run_id, 'corrupt');
        $ok      = _pp_cli_preflight_record_failed_message($run_id, 'ok');

        $this->assertStringContainsString('different site', $foreign);
        $this->assertStringContainsString('corrupt', $corrupt);
        // status 'ok' means the run is live but the write did not land — retry, not re-inspect.
        $this->assertStringContainsString('did not complete', $ok);

        // All four causes produce different operator guidance.
        $this->assertCount(4, array_unique([
            _pp_cli_preflight_record_failed_message($run_id, 'not_found'),
            _pp_cli_preflight_record_failed_message($run_id, 'expired'),
            $foreign,
            $ok,
        ]));
    }

    public function testPreflightRecordMessageEndToEndClassifiesAbsentRunAsNotFound(): void
    {
        // Full path: a valid but never-minted run token (the container repro) must
        // resolve to the not_found message via pp_operate_run_status().
        $run_id = '11111111-1111-4111-8111-111111111111';
        $this->assertSame('not_found', pp_operate_run_status($run_id));
        $msg = _pp_cli_preflight_record_failed_message($run_id, pp_operate_run_status($run_id));
        $this->assertStringContainsString('No run state found', $msg);
    }

    // ── #385: param-type / validation failures emit the ok:false envelope ──────
    // The shared validators already return a WP_Error for every validation class,
    // and pp_execute_action already wraps it into the ok:false envelope. The bug
    // was purely at the `action execute` SURFACE: its early-validation gate routed
    // that WP_Error to a bare WP_CLI::error on STDERR (dropping error_code), so a
    // batch grepping STDOUT for the envelope missed the rejection entirely. These
    // pins drive the real command method (PP_Action_Command::execute) so a
    // regression back to the stderr path is caught, not just the engine shape.

    /**
     * Runs `action execute` for a run whose INSPECT step is recorded, capturing
     * the stdout envelope. Returns the decoded envelope. Fails the test if the
     * command took the bare-stderr (WP_CLI::error) path instead of envelope+halt.
     */
    private function runActionExecuteExpectingEnvelope(string $name, array $params): array
    {
        $run_id = $this->newRun(); // fresh run has INSPECT recorded (see operate.php)
        WP_CLI::$lines = [];
        $halted = false;
        try {
            (new PP_Action_Command())->execute([$name], [
                'run-id' => $run_id,
                'params' => json_encode($params),
            ]);
            $this->fail('execute() should have halted on a validation failure');
        } catch (WpCliExitException $e) {
            $this->fail('Validation failure went to a bare WP_CLI::error (stderr), not the ok:false envelope: ' . $e->getMessage());
        } catch (WpCliHaltException $e) {
            $halted = true;
            $this->assertSame(1, $e->getCode(), 'exit code stays non-zero (1), matching the old WP_CLI::error');
        }
        $this->assertTrue($halted, 'command halted');
        $this->assertCount(1, WP_CLI::$lines, 'exactly one envelope emitted on stdout');
        $decoded = json_decode(WP_CLI::$lines[0], true);
        $this->assertIsArray($decoded, 'stdout line is valid JSON');
        return $decoded;
    }

    public function testActionExecuteNumericValueEmitsEnvelopeNotBareError(): void
    {
        // The reported repro: update_site_option with a numeric `value` (int 164)
        // for a string-typed param. "164" (string) would pass; 164 (int) is rejected.
        $envelope = $this->runActionExecuteExpectingEnvelope(
            'update_site_option',
            ['key' => 'pp_logo_id', 'value' => 164]
        );
        $this->assertFalse($envelope['ok'], 'envelope reports failure');
        $this->assertSame('invalid_param_type', $envelope['error_code'], 'carries the machine-readable error_code (#312)');
        $this->assertStringContainsString('value', $envelope['error'], 'names the offending param');
        $this->assertStringContainsString('must be string', $envelope['error'], 'message preserved');
        $this->assertSame('update_site_option', $envelope['action']);
    }

    public function testActionExecuteValidationEnvelopeShapeIsUniform(): void
    {
        // Regression pin: a DIFFERENT validation class (missing required param)
        // must produce the SAME envelope shape as the numeric-value case, proving
        // the whole validation class flows through the one choke point, not a
        // param-type-specific patch.
        $numeric = $this->runActionExecuteExpectingEnvelope(
            'update_site_option',
            ['key' => 'pp_logo_id', 'value' => 164]
        );
        $missing = $this->runActionExecuteExpectingEnvelope(
            'update_site_option',
            ['key' => 'pp_logo_id'] // required `value` omitted
        );
        $this->assertSame('missing_param', $missing['error_code']);
        $this->assertFalse($missing['ok']);
        // Identical key set → identical envelope shape across validation classes.
        $numericKeys = array_keys($numeric);
        $missingKeys = array_keys($missing);
        sort($numericKeys);
        sort($missingKeys);
        $this->assertSame($numericKeys, $missingKeys, 'both validation failures share the envelope shape');
    }

    // ── #685: --post_id is the canonical page address ───────────────────────
    //
    // Two decisions, two pure predicates, both pinned here without WP_CLI::error()'s
    // exit — same shape as the #390 gate predicates above:
    //
    //   raw argv ──> _pp_cli_positional_page_arg_error()  (pre-dispatch, before_run_command)
    //                     │ null
    //                     v
    //   assoc_args ─> _pp_cli_post_id_arg_error()         (in-command)
    //                     │ null
    //                     v
    //                 (int) --post_id ──> pp_inspect_composition() / pp_patch_composition() / history
    //
    // The dispatcher-ordering half (that the pre-dispatch refusal beats WP-CLI's own
    // "Too many positional arguments") is not assertable against a stub — it is pinned
    // live in tests/e2e/actions.spec.ts.

    /**
     * Derived from the shipped constant, never hand-listed: adding a fourth
     * page-addressed subcommand must not leave these pins covering only three.
     *
     * @return array<string, array{0: string}>
     */
    public static function pageAddressedSubcommands(): array
    {
        $subcommands = PP_CLI_PAGE_ADDRESSED_OPERATE_SUBCOMMANDS;
        return array_combine(
            $subcommands,
            array_map(static fn (string $s): array => [$s], $subcommands)
        );
    }

    public function testTheSubcommandProviderIsNotEmpty(): void
    {
        // A constant renamed out from under the provider would make every
        // @dataProvider pin above silently vanish instead of failing.
        $this->assertGreaterThanOrEqual(3, count(self::pageAddressedSubcommands()));
    }

    /**
     * @dataProvider pageAddressedSubcommands
     */
    public function testPositionalPageArgumentIsRefusedWithTheFlagForm(string $subcommand): void
    {
        $error = _pp_cli_positional_page_arg_error(['pp', 'operate', $subcommand, '19']);
        $this->assertNotNull($error, 'a positional page argument is refused');
        $this->assertStringContainsString('takes no positional page argument', $error);
        $this->assertStringContainsString('wp pp operate ' . $subcommand . ' --post_id=N', $error, 'names the flag form');
        // Breadcrumb style (_pp_cli_positional_page_arg_error, matching
        // _pp_cli_preflight_coverage_error): compose the exact next command.
        $this->assertStringContainsString('wp pp operate ' . $subcommand . ' --post_id=19', $error);
    }

    public function testPositionalSlugRefusalSaysSlugsAreNotResolved(): void
    {
        // The removed url_to_postid path: a slug must not read as "almost worked".
        $error = _pp_cli_positional_page_arg_error(['pp', 'operate', 'patch', 'about-us']);
        $this->assertNotNull($error);
        $this->assertStringContainsString('Slugs and URLs are not resolved', $error);
        $this->assertStringContainsString('wp pp operate inspect', $error, 'points at the page map');
        $this->assertStringNotContainsString('--post_id=about-us', $error, 'never composes a non-numeric breadcrumb');
    }

    public function testSpaceSeparatedPostIdIsRefusedWithTheEqualsForm(): void
    {
        // WP-CLI parses `--post_id 19` as post_id=true PLUS a positional `19`
        // (Configurator::extract_assoc), so this lands on the positional guard.
        // The breadcrumb must name the `=` form the operator meant.
        $error = _pp_cli_positional_page_arg_error(['pp', 'operate', 'patch', '19']);
        $this->assertNotNull($error);
        $this->assertStringContainsString('--post_id=19', $error);
    }

    /** @return array<string, array{0: array<int, string>}> */
    public static function argVectorsThatMustNotTripTheGuard(): array
    {
        return [
            'flag form has no positional'   => [['pp', 'operate', 'patch']],
            'site-scoped inspect'           => [['pp', 'operate', 'inspect']],
            'inspect with a positional'     => [['pp', 'operate', 'inspect', '19']],
            'a different pp namespace'      => [['pp', 'check', 'page', '19']],
            'a foreign top-level command'   => [['post', 'operate', 'patch', '19']],
            'help for the command'          => [['help', 'pp', 'operate', 'patch']],
            'bare command path'             => [['pp', 'operate']],
            'empty argv'                    => [[]],
        ];
    }

    /**
     * @dataProvider argVectorsThatMustNotTripTheGuard
     * @param array<int, string> $args
     */
    public function testGuardIgnoresEverythingButPageAddressedOperateSubcommands(array $args): void
    {
        $this->assertNull(
            _pp_cli_positional_page_arg_error($args),
            'guard must not fire on: ' . json_encode($args)
        );
    }

    public function testPositionalGuardWrapperFailsClosedWithTheSameMessage(): void
    {
        $expected = _pp_cli_positional_page_arg_error(['pp', 'operate', 'patch', '19']);
        try {
            _pp_cli_reject_positional_page_arg(['pp', 'operate', 'patch', '19'], []);
            $this->fail('the wrapper should have refused');
        } catch (WpCliExitException $e) {
            $this->assertSame($expected, $e->getMessage(), 'wrapper emits the predicate message verbatim');
        }
        // And the accepting branch must not exit.
        _pp_cli_reject_positional_page_arg(['pp', 'operate', 'patch'], ['post_id' => '19']);
        $this->addToAssertionCount(1);
    }

    /**
     * Defense in depth: with `--post_id=<id>` required in the synopsis, WP-CLI's own
     * parameter check reports an absent/valueless flag first (verified live against
     * wp-env: "missing --post_id parameter"). This branch is what a programmatic
     * caller or a future optional-synopsis change would hit, and it must still name
     * the canonical shape rather than fall through to a bare (int) cast of null.
     */
    public function testStrayPositionalWithPostIdAlreadySetDoesNotLectureAboutAddressing(): void
    {
        // `wp pp operate patch --post_id=19 junk --target=...` — the page IS
        // addressed, so the stray token is not a page address. Composing
        // "--post_id=junk" or preaching the flag form here would misdirect triage.
        $error = _pp_cli_positional_page_arg_error(
            ['pp', 'operate', 'patch', 'junk'],
            ['post_id' => '19', 'target' => 'hero.subheading']
        );
        $this->assertNotNull($error, 'a stray positional is still refused');
        $this->assertStringContainsString('unexpected positional argument ("junk")', $error);
        $this->assertStringNotContainsString('--post_id=junk', $error, 'never composes an address from the stray token');
        $this->assertStringNotContainsString('takes no positional page argument', $error, 'not the addressing lecture');

        // Conflicting addresses: the EXPLICIT flag wins. The breadcrumb must not
        // compose `--post_id=19` from the positional when the operator typed 20.
        $conflict = _pp_cli_positional_page_arg_error(
            ['pp', 'operate', 'patch', '19'],
            ['post_id' => '20']
        );
        $this->assertStringContainsString('unexpected positional argument ("19")', $conflict);
        $this->assertStringNotContainsString('--post_id=19', $conflict, 'the positional never overrides the typed flag');

        // But a BARE --post_id (WP-CLI parses `--post_id 19` as post_id=true plus
        // a positional) is still the addressing case, not the stray-token case.
        $bare = _pp_cli_positional_page_arg_error(
            ['pp', 'operate', 'patch', '19'],
            ['post_id' => true]
        );
        $this->assertStringContainsString('--post_id=19', $bare);
    }

    public function testMissingPostIdIsRefusedWithTheFlagFormBreadcrumb(): void
    {
        foreach ([[], ['post_id' => true], ['post_id' => '']] as $assoc) {
            $error = _pp_cli_post_id_arg_error($assoc);
            $this->assertNotNull($error, 'missing --post_id refused: ' . json_encode($assoc));
            $this->assertStringContainsString('--post_id is required', $error);
            $this->assertStringContainsString('--post_id=42', $error, 'shows the shape');
            $this->assertStringContainsString('wp pp operate inspect', $error, 'points at the page map');
        }
    }

    /** @return array<string, array{0: string}> */
    public static function nonNumericPostIdValues(): array
    {
        return [
            'a slug'            => ['about-us'],
            'a path'            => ['/about-us/'],
            'a full URL'        => ['https://example.com/about-us/'],
            'zero'              => ['0'],
            'padded zero'       => ['000'],
            'negative'          => ['-1'],
            'a float'           => ['1.5'],
            'scientific'        => ['1e3'],
            'leading space'     => [' 19'],
            'numeric prefix'    => ['19abc'],
            // ctype_digit() alone accepts both of these. PHP then SATURATES the
            // over-large one to PHP_INT_MAX and canonicalizes the padded one —
            // the exact silent-coercion class this gate exists to stop.
            'overflows int'     => ['999999999999999999999999'],
            'PHP_INT_MAX + 1'   => ['9223372036854775808'],
            'leading zeros'     => ['00019'],
        ];
    }

    /**
     * @dataProvider nonNumericPostIdValues
     */
    public function testNonNumericPostIdIsRefusedRatherThanTruncated(string $value): void
    {
        // is_numeric() would have accepted 1.5 / 1e3 / -1 / " 19" and then
        // (int)-truncated them into a DIFFERENT post than the operator typed.
        $error = _pp_cli_post_id_arg_error(['post_id' => $value]);
        $this->assertNotNull($error, 'refused: ' . $value);
        $this->assertStringContainsString('Invalid --post_id "' . $value . '"', $error);
        $this->assertStringContainsString('slugs and URLs are not resolved', $error);
    }

    public function testNumericPostIdIsAccepted(): void
    {
        $this->assertNull(_pp_cli_post_id_arg_error(['post_id' => '19']));
        $this->assertNull(_pp_cli_post_id_arg_error(['post_id' => 19]));
        $this->assertNull(_pp_cli_post_id_arg_error(['post_id' => '1']));
    }

    public function testRequirePostIdWrapperFailsClosedAndReturnsTheId(): void
    {
        try {
            _pp_cli_require_post_id_arg([]);
            $this->fail('the wrapper should have refused a missing --post_id');
        } catch (WpCliExitException $e) {
            $this->assertSame(_pp_cli_post_id_arg_error([]), $e->getMessage());
        }
        $this->assertSame(19, _pp_cli_require_post_id_arg(['post_id' => '19']), 'returns an int, not a string');
    }

    /**
     * @dataProvider pageAddressedSubcommands
     */
    public function testEachPageAddressedCommandRefusesAMissingPostId(string $subcommand): void
    {
        $method = str_replace('-', '_', $subcommand);
        $command = new PP_Operate_Command();
        try {
            $command->$method([], []);
            $this->fail($subcommand . ' should have refused a missing --post_id');
        } catch (WpCliExitException $e) {
            $this->assertStringContainsString('--post_id is required', $e->getMessage());
        }
    }

    public function testCompositionHistoryAddressedByPostIdReachesTheHandler(): void
    {
        // Proves the flag form is actually wired through to the read path: the
        // envelope reports the post it was addressed with.
        WP_CLI::$lines = [];
        (new PP_Operate_Command())->composition_history([], ['post_id' => '501']);
        $this->assertCount(1, WP_CLI::$lines);
        $decoded = json_decode(WP_CLI::$lines[0], true);
        $this->assertIsArray($decoded);
        $this->assertSame(501, $decoded['post_id'], 'the --post_id value reached the handler');
    }

    /**
     * Threading pin for the other two commands. Without this, a handler that
     * dropped the resolved id (passed 0, or re-read a now-absent $args[0]) would
     * still satisfy every refusal test in this file — the refusal tests only
     * prove the gate fires, never that the accepted value is the one used.
     *
     * Both report through a WP_Error rather than a JSON envelope for an id that
     * does not resolve, so the assertion is that the message names THIS post.
     */
    public function testInspectCompositionAndPatchThreadTheAcceptedPostId(): void
    {
        $command = new PP_Operate_Command();

        // inspect-composition: seed a real page and prove the emitted targets are
        // THAT page's, not an empty list from a dropped id.
        $GLOBALS['_pp_test_store']['posts'][501] = [
            'post_type' => 'page', 'post_title' => 'Threading', 'post_status' => 'publish',
        ];
        update_post_meta(501, '_pp_composition', json_encode([
            ['component' => 'hero', 'props' => ['id' => 'threading-hero', 'title' => 'Seed', 'subheading' => 'before']],
        ]));

        WP_CLI::$lines = [];
        $command->inspect_composition([], ['post_id' => '501']);
        $this->assertCount(1, WP_CLI::$lines);
        $this->assertStringContainsString(
            'threading-hero',
            WP_CLI::$lines[0],
            'inspect-composition inspected the page named by --post_id, not an empty/zero id'
        );

        // patch: an id that resolves to nothing must be reported AS THAT ID.
        WP_CLI::$lines = [];
        $emitted = '';
        try {
            $command->patch([], ['post_id' => '424242', 'target' => 'hero.subheading', 'value' => 'x', 'preview' => true]);
            $this->fail('patch should have reported the unresolvable post');
        } catch (WpCliExitException | WpCliHaltException $e) {
            // Either exit shape is fine (stderr error or stdout envelope + halt);
            // the id in whichever channel carried it is what this pins.
            $emitted = $e->getMessage() . ' ' . implode(' ', WP_CLI::$lines);
        }
        $this->assertStringContainsString('424242', $emitted, 'patch used the --post_id value');
    }
}
