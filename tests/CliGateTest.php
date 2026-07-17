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
if (!class_exists('WP_CLI_Command')) {
    class WP_CLI_Command {}
}
if (!class_exists('WP_CLI')) {
    class WP_CLI {
        public static function error($message, $exit = true): void { throw new WpCliExitException((string) $message); }
        public static function add_command($name, $handler, $args = []): void {}
        public static function line($message = ''): void {}
        public static function warning($message = ''): void {}
        public static function success($message = ''): void {}
        public static function debug($message = '', $group = false): void {}
        public static function log($message = ''): void {}
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
}
