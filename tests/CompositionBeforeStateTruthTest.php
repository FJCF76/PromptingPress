<?php
/**
 * tests/CompositionBeforeStateTruthTest.php — the before side of a whole-composition
 * write tells the truth about a corrupt page (#836).
 *
 * WHAT THIS FILE DEFENDS, and why it is the last of its family. #725, #748 and #750 each
 * removed one instance of "a corrupt page described as an empty one" (ruling R-C). This is
 * the instance that survived all three, and the one that reaches a HUMAN DECISION rather
 * than a diagnostic:
 *
 *   `update_composition` and `restore_composition` are the only two composition actions
 *   declaring `requires_composition => false`, so the #748 precondition gate — the thing
 *   that refuses every OTHER composition action on a corrupt page before it can build a
 *   `from` — never fires on them. Since #750 the chat's corruption block instructs the
 *   model to propose exactly one of those two verbs on such a page, and #756's carve-out
 *   admits it. So the operator's APPROVAL GATE, the last checkpoint before a
 *   whole-composition replacement lands over recoverable bytes, rendered
 *   "Full composition replacement: 0 -> N components" with an empty removed list.
 *
 * The pre-#836 `from` came from `pp_get_composition()`, which IS
 * `pp_get_composition_result($id)['composition']` (lib/wp.php) — and a `!ok`
 * classification always carries `composition => []`. The lie was the accessor, not the
 * renderer, which is why the fix is server-side and why BOTH surfaces that render the
 * envelope are pinned here.
 *
 * THE FOUR CALL SITES, each pinned in both directions:
 *
 *   update_composition   preview   `before` + changes[0].from
 *   update_composition   execute   changes[0].from
 *   restore_composition  preview   `before` + changes[0].from
 *   restore_composition  execute   changes[0].from
 *
 * THE LINE THAT MUST HOLD, and half these tests exist for the negative half of it:
 *
 *   absent meta      ok   -> from: []          `[]` is a blank page's TRUTH, not a degradation
 *   stored "[]"      ok   -> from: []          an authored empty list, likewise
 *   healthy list     ok   -> from: [...]       byte-identical to the pre-#836 envelope
 *   decode_error     !ok  -> the marker
 *   unexpected_shape !ok  -> the marker
 *
 * A change that made every page report the marker would be as wrong as the bug, so
 * "blank still says blank" is pinned on every one of the four sites, not once — split
 * across two methods, one per verb, so a mutation local to either verb's call sites is
 * caught rather than masked by the other's coverage. The healthy-page regression pin is
 * split the same way.
 *
 * SURFACE ENUMERATION IS PART OF THE FIX. The issue asked which surfaces render this
 * value; the answer is the chat proposal card (assets/js/pp-ai-chat.js, pinned in
 * tests/js/) and the WP-CLI envelope, which prints it as JSON. The CLI half is pinned here
 * by driving the real sink, `_pp_cli_emit_json()`, and decoding its stdout back: an
 * envelope whose marker did not survive encoding would be a lie the CLI told on its own.
 *
 * Standalone-runnable: lib/cli.php is NOT in tests/bootstrap.php's require list, so the
 * WP_CLI stubs and the require below are what keep
 * `./vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/CompositionBeforeStateTruthTest.php`
 * honest instead of order-dependent green.
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stubs (shared shape with tests/CliGateTest.php; all guard on existence) ──
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

class CompositionBeforeStateTruthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        $GLOBALS['_pp_test_store']['posts']     = [];
        // The shared bootstrap wpdb answers every GET_LOCK with NULL, which would make the
        // restore-execute cases fail on the lock rather than on anything under test.
        // PP_Lockable_Wpdb grants it and agrees with the option/meta stores otherwise.
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        WP_CLI::$lines = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        $GLOBALS['_pp_test_store']['posts']     = [];
        WP_CLI::$lines = [];
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** A valid one-band composition — the shape a repair proposal sends. */
    private function repairComposition(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'Recovered']]];
    }

    /** A page storing undecodable bytes: the `decode_error` classification. */
    private function corruptPageDecodeError(): int
    {
        $post_id = pp_create_page('Corrupt (bytes)', 'draft');
        update_post_meta($post_id, '_pp_composition', 'NOT_VALID_JSON{{{');
        $this->assertSame('decode_error', pp_get_composition_result($post_id)['error'], 'premise');
        return $post_id;
    }

    /**
     * decode_error's OTHER real-world producer: stored bytes that are not valid UTF-8
     * (lib/wp.php names the truncated write / encoding bug as the case it is for).
     * Distinct from the syntax-error fixture because it is the state where the CLI sink's
     * JSON_INVALID_UTF8_SUBSTITUTE actually does something — an ASCII-clean fixture proves
     * the encoder was never asked the hard question.
     */
    private function corruptPageInvalidUtf8(): int
    {
        $post_id = pp_create_page('Corrupt (not UTF-8)', 'draft');
        update_post_meta($post_id, '_pp_composition', "[{\"component\":\"hero\",\"props\":{\"title\":\"\xC3\x28\"}}]");
        $this->assertSame('decode_error', pp_get_composition_result($post_id)['error'], 'premise');
        return $post_id;
    }

    /** A page storing a JSON OBJECT: the `unexpected_shape` classification. */
    private function corruptPageUnexpectedShape(): int
    {
        $post_id = pp_create_page('Corrupt (object)', 'draft');
        pp_update_composition($post_id, [
            1 => ['component' => 'hero', 'props' => ['id' => 'kept-1', 'title' => 'Kept one']],
        ]);
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error'], 'premise');
        return $post_id;
    }

    /**
     * A corrupt page whose history ring still holds a restorable snapshot, so
     * `restore_composition` is reachable on it. The raw meta write does not touch the
     * ring, which is exactly the #818 property that makes the repair non-destructive.
     */
    private function corruptPageWithRestorableHistory(): int
    {
        $post_id = pp_create_page('Corrupt with history', 'draft');
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'Old']],
        ]));
        $this->assertTrue(pp_update_composition($post_id, $this->repairComposition()));
        update_post_meta($post_id, '_pp_composition', 'CORRUPT_NOW{{{');
        $this->assertSame('decode_error', pp_get_composition_result($post_id)['error'], 'premise');
        return $post_id;
    }

    /** Asserts a value is the unreadable marker for this page and classification. */
    private function assertIsMarker($value, int $post_id, string $classification, string $where): void
    {
        $this->assertIsArray($value, "{$where}: the marker is an array");
        $this->assertArrayNotHasKey(0, $value, "{$where}: and never a LIST — a list is what a composition is");
        $this->assertSame(
            ['unreadable', 'classification', 'message'],
            array_keys($value),
            "{$where}: exactly these keys. The chat predicate (assets/js/pp-ai-chat.js) reads"
            . " all three by name, and its fixtures are hand-built, so a rename here would"
            . " otherwise pass both suites and silently drop the card to the raw-JSON path"
        );
        $this->assertTrue($value['unreadable'] ?? null, "{$where}: says unreadable");
        $this->assertSame($classification, $value['classification'] ?? null, "{$where}: names the classification");
        // The sentence is asserted against its OWNER, never against a literal. A literal
        // here would let pp_composition_integrity_message() drift while this stayed green,
        // and re-spelling it in a test is the same second-spelling #650/#652 forbids in
        // shipped code.
        $this->assertSame(
            pp_composition_integrity_message($post_id, $classification),
            $value['message'] ?? null,
            "{$where}: and says it in the single owner's words"
        );
        // THE CROSS-BOUNDARY PROPERTY, pinned on the side that produces it. Owner-equality
        // above would still hold if that owner started returning '', and the JS predicate
        // REFUSES a marker whose message or classification is empty or non-string —
        // routing it back to the raw JSON blob this whole fix exists to replace. Each of
        // these assertions is one half of a contract whose other half lives in vitest.
        $this->assertIsString($value['message'], "{$where}: the message is a string");
        $this->assertNotSame('', $value['message'], "{$where}: and a non-empty one");
        $this->assertIsString($value['classification'], "{$where}: the classification is a string");
        $this->assertNotSame('', $value['classification'], "{$where}: and a non-empty one");
    }

    // ═══ THE MARKER REACHES ALL FOUR SITES ═══════════════════════════════════

    public function testUpdateCompositionPreviewNamesTheCorruptionInsteadOfShowingAnEmptyList(): void
    {
        $post_id = $this->corruptPageDecodeError();

        $preview = pp_preview_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);

        $this->assertTrue($preview['ok'], 'preview succeeds — the action is admitted on this page');
        $this->assertNotSame([], $preview['changes'][0]['from'], 'the bug, stated as the thing that must not happen');
        $this->assertIsMarker($preview['changes'][0]['from'], $post_id, 'decode_error', 'update preview changes[].from');
        // `before` is the same claim in the same envelope; a preview where the two
        // disagreed would contradict itself on one screen.
        $this->assertIsMarker($preview['before'], $post_id, 'decode_error', 'update preview before');
    }

    public function testUpdateCompositionExecuteReceiptNamesTheCorruptionItReplaced(): void
    {
        $post_id = $this->corruptPageDecodeError();

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);

        $this->assertTrue($result['ok'], 'the repair landed: ' . ($result['error'] ?? ''));
        $this->assertIsMarker($result['changes'][0]['from'], $post_id, 'decode_error', 'update execute changes[].from');
        // Read BEFORE the write, so the receipt reports the state this call replaced —
        // not the healthy list it just wrote.
        $this->assertSame('hero', $result['changes'][0]['to'][0]['component'], 'and `to` is what landed');
    }

    public function testRestoreCompositionPreviewNamesTheCorruptionInsteadOfShowingAnEmptyList(): void
    {
        $post_id = $this->corruptPageWithRestorableHistory();

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);

        $this->assertTrue($preview['ok'], 'restore previews on a corrupt page (#233 + D-1)');
        $this->assertNotSame([], $preview['changes'][0]['from']);
        $this->assertIsMarker($preview['changes'][0]['from'], $post_id, 'decode_error', 'restore preview changes[].from');
        $this->assertIsMarker($preview['before'], $post_id, 'decode_error', 'restore preview before');
    }

    public function testRestoreCompositionExecuteReceiptNamesTheCorruptionItReplaced(): void
    {
        $post_id = $this->corruptPageWithRestorableHistory();

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);

        $this->assertTrue($result['ok'], 'the restore landed: ' . ($result['error'] ?? ''));
        $this->assertIsMarker($result['changes'][0]['from'], $post_id, 'decode_error', 'restore execute changes[].from');
    }

    public function testTheOtherClassificationIsCarriedToo(): void
    {
        $post_id = $this->corruptPageUnexpectedShape();

        $preview = pp_preview_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);

        // Not a cosmetic duplicate of the decode_error case: `unexpected_shape` is the
        // classification a JSON OBJECT produces, and an object is the one corrupt shape
        // that decodes into something a careless `is_array()` would accept as a
        // composition. If the marker ever hard-coded one classification, this catches it.
        $this->assertIsMarker($preview['changes'][0]['from'], $post_id, 'unexpected_shape', 'unexpected_shape preview');
    }

    /**
     * `unexpected_shape` by a DIFFERENT branch of the classifier: a raw non-string scalar
     * (lib/wp.php's `!is_string($raw)` arm), which never reaches json_decode at all. The
     * object fixture above exercises the post-decode list-shape arm.
     */
    public function testANonStringStoredValueIsAlsoReportedAsUnreadable(): void
    {
        $post_id = pp_create_page('Corrupt (scalar)', 'draft');
        update_post_meta($post_id, '_pp_composition', 42);
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error'], 'premise');

        $preview = pp_preview_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);

        $this->assertIsMarker($preview['changes'][0]['from'], $post_id, 'unexpected_shape', 'non-string raw preview');
    }

    // ═══ THE BLANK/HEALTHY NEGATIVES, ON THE RESTORE SITES TOO ═══════════════

    /**
     * The other half of "on every one of the four sites". Without this pair, a mutation
     * LOCAL to restore's two call sites — someone emitting the marker whenever the ring is
     * non-empty, say — leaves every other test in this file green, because the positive
     * marker tests only prove restore routes through the helper at all.
     *
     * A blank page WITH restorable history is reachable and is not a contrived fixture: it
     * is what any page that was populated and then deliberately emptied looks like.
     */
    public function testAGenuinelyBlankPageStillReportsAnEmptyListOnTheRestoreSitesToo(): void
    {
        $post_id = pp_create_page('Blank with history', 'draft');
        $this->assertTrue(pp_update_composition($post_id, $this->repairComposition()));
        $this->assertTrue(pp_update_composition($post_id, []));
        $stored = pp_get_composition_result($post_id);
        $this->assertTrue($stored['ok'], 'premise: emptied, not corrupt');
        $this->assertSame([], $stored['composition'], 'premise: nothing stored now');

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);
        $this->assertTrue($preview['ok'], $preview['error'] ?? '');
        $this->assertSame([], $preview['changes'][0]['from'], 'blank restore preview from');
        $this->assertSame([], $preview['before'], 'blank restore preview before');

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([], $result['changes'][0]['from'], 'blank restore execute from');
    }

    public function testAHealthyPageReportsExactlyWhatItStoresOnTheRestoreSitesToo(): void
    {
        $post_id = pp_create_page('Healthy with history', 'draft');
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'Old']],
        ]));
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'Live']],
        ]));

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);
        $this->assertTrue($preview['ok'], $preview['error'] ?? '');
        $this->assertSame(pp_get_composition($post_id), $preview['changes'][0]['from'], 'restore preview from');
        $this->assertSame('Live', $preview['changes'][0]['from'][0]['props']['title']);
        $this->assertSame(pp_get_composition($post_id), $preview['before'], 'restore preview before');
    }

    // ═══ A BLANK PAGE IS NOT A CORRUPT PAGE ══════════════════════════════════

    /**
     * The negative half, on `update_composition`'s two sites; its restore-side sibling
     * above covers the other two. `[]` is the honest answer for a page that genuinely has
     * nothing stored, and a fix that reported the marker everywhere would have replaced one
     * lie with another.
     */
    public function testAGenuinelyBlankPageStillReportsAnEmptyListOnTheUpdateSites(): void
    {
        $post_id = pp_create_page('Blank', 'draft');
        $this->assertSame([], pp_get_composition_result($post_id)['composition'], 'premise: nothing stored');
        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise: and it is not corrupt');

        $preview = pp_preview_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);
        $this->assertSame([], $preview['changes'][0]['from'], 'blank preview from');
        $this->assertSame([], $preview['before'], 'blank preview before');

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['changes'][0]['from'], 'blank execute from');
    }

    public function testAnAuthoredEmptyListIsAlsoStillReportedAsAnEmptyList(): void
    {
        $post_id = pp_create_page('Deliberately emptied', 'draft');
        update_post_meta($post_id, '_pp_composition', '[]');
        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise: "[]" is valid, not corrupt');

        $preview = pp_preview_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);

        // Deliberately authored empty and never-populated are DIFFERENT states that share
        // one honest answer here. The distinction matters to the front-page seed
        // (pp_resolve_front_page_render) and to nothing on this surface.
        $this->assertSame([], $preview['changes'][0]['from']);
    }

    public function testAHealthyPageReportsExactlyWhatItStores(): void
    {
        $post_id = pp_create_page('Healthy', 'draft');
        $stored  = [['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'Live']]];
        $this->assertTrue(pp_update_composition($post_id, $stored));

        $preview = pp_preview_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);

        // The regression pin: on every `ok` input this envelope is byte-identical to its
        // pre-#836 form, because the helper returns the same value the old accessor did.
        $this->assertSame(pp_get_composition($post_id), $preview['changes'][0]['from'], 'from');
        $this->assertSame('Live', $preview['changes'][0]['from'][0]['props']['title']);
        $this->assertSame(pp_get_composition($post_id), $preview['before'], 'before');
    }

    // ═══ THE SURFACES THAT RENDER IT ═════════════════════════════════════════

    /**
     * The CLI half of the surface enumeration. `wp pp action execute` prints the envelope
     * through `_pp_cli_emit_json()` and nothing else, so this drives that sink for real and
     * decodes its stdout back. The v1.17.0 smoke saw `from: []` HERE as well as on the
     * chat card, which is the evidence that the root was shared rather than chat-only.
     */
    public function testTheCliEnvelopeCarriesTheMarkerThroughItsJsonSink(): void
    {
        $post_id = $this->corruptPageDecodeError();

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);
        $this->assertTrue($result['ok']);

        _pp_cli_emit_json($result);
        $printed = json_decode(implode("\n", WP_CLI::$lines), true);

        $this->assertNotNull($printed, 'the envelope encoded — an object in `from` is not a JSON hazard');
        $this->assertIsMarker($printed['changes'][0]['from'], $post_id, 'decode_error', 'CLI stdout');
    }

    /**
     * The same sink, on the input it was actually hardened for. The fixture above is
     * ASCII-clean, so it never asks _pp_cli_emit_json()'s JSON_INVALID_UTF8_SUBSTITUTE to
     * do anything — and non-UTF-8 stored bytes are precisely the page state this marker
     * describes. Asserting "an object in `from` encodes fine" against clean bytes proves
     * the easy half; this proves the half where the encoder could have answered `false` and
     * cost a landed write its whole receipt.
     */
    public function testTheCliEnvelopeStillCarriesTheMarkerWhenTheStoredBytesAreNotUtf8(): void
    {
        $post_id = $this->corruptPageInvalidUtf8();

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairComposition(),
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        _pp_cli_emit_json($result);
        $printed = json_decode(implode("\n", WP_CLI::$lines), true);

        $this->assertNotNull($printed, 'the substitute flag keeps the envelope printable');
        $this->assertIsMarker($printed['changes'][0]['from'], $post_id, 'decode_error', 'CLI stdout, non-UTF-8 source');
    }

    /**
     * ENVELOPE COMPATIBILITY, pinned rather than asserted in prose. `changes[].from` was
     * never "a composition array" envelope-wide — it is per-action shaped, and an
     * associative OBJECT already ships in it on both redirect actions. A consumer assuming
     * list-ness of an arbitrary `from` was already wrong before this change, so this test
     * is what makes that argument checkable instead of rhetorical.
     */
    public function testTheEnvelopeAlreadyCarriedNonListFromValuesBeforeThisChange(): void
    {
        $preview = pp_preview_action('create_redirect', ['from' => '/old', 'to' => '/new', 'code' => 301]);
        $this->assertTrue($preview['ok']);
        $this->assertNull(
            $preview['changes'][0]['from'],
            'null is a shipped `from` — which is exactly why null could not be the marker:'
            . ' it already means "there was no prior value"'
        );

        $this->assertTrue(pp_execute_action('create_redirect', ['from' => '/old', 'to' => '/new', 'code' => 301])['ok']);

        // THE CLAIM THIS TEST EXISTS FOR, asserted on `from` and not on a neighbouring
        // field: an associative OBJECT already ships in `changes[].from` on a shipped
        // action, so "from is always a list" was never the envelope's contract and this
        // change does not break one.
        $removal = pp_preview_action('remove_redirect', ['from' => '/old']);
        $this->assertTrue($removal['ok'], $removal['error'] ?? '');
        $before = $removal['changes'][0]['from'];
        $this->assertIsArray($before, 'remove_redirect reports the record it will drop');
        $this->assertArrayNotHasKey(0, $before, 'and it is an associative OBJECT, not a list');
        $this->assertSame('/new', $before['to'] ?? null);
    }

    /**
     * The other half of the enumeration: nothing ELSE can emit this marker, because every
     * other composition action refuses on a corrupt page before it builds a `from` at all
     * (#748).
     *
     * DERIVED FROM THE REGISTRY, NOT FROM A LIST I TYPED, and that is the whole point. A
     * hardcoded census cannot notice the thing it exists to notice: a NEW action opting out
     * of `requires_composition` is simply absent from a literal list, so the test stays
     * green while an unrouted `from` ships. Walking `pp_get_registered_actions()` makes the
     * drift-guard claim true rather than aspirational — the same idiom
     * CompositionShapeTrustTest::compositionGatedSurfaces() already uses for the sibling
     * question, and for the same reason.
     *
     * A new name appearing in the opted-out set is a new action reachable on a corrupt
     * page. It needs a #836 decision: either it never builds a composition `from` (a
     * lifecycle or metadata action) or it must read through _pp_composition_before_state().
     */
    public function testTheOptedOutCensusIsExactlyTheTwoActionsThisFixRoutes(): void
    {
        $composition_opt_outs = [];
        $composition_gated    = [];

        foreach (pp_get_registered_actions() as $name => $action) {
            if (($action['scope'] ?? '') === 'site') {
                continue; // site-scoped: no composition target, so the gate never applies
            }
            if (empty($action['mutates_composition'])) {
                continue; // page lifecycle/metadata: never builds a composition `from`
            }
            if (($action['requires_composition'] ?? true) === false) {
                $composition_opt_outs[] = $name;
            } else {
                $composition_gated[] = $name;
            }
        }
        sort($composition_opt_outs);
        sort($composition_gated);

        $this->assertSame(
            ['restore_composition', 'update_composition'],
            $composition_opt_outs,
            'a composition-mutating action that opts out of the #748 gate needs a #836 routing decision'
        );
        $this->assertNotEmpty($composition_gated, 'premise: the gated set is not empty');

        // The gated ones are refused on a corrupt page, so they never reach a `from`.
        $post_id = $this->corruptPageDecodeError();
        foreach ($composition_gated as $name) {
            $gate = pp_action_composition_precondition(pp_get_action($name) + ['name' => $name], $post_id);
            $this->assertInstanceOf(WP_Error::class, $gate, "{$name} is refused on a corrupt page");
            $this->assertSame('composition_required', $gate->get_error_code(), "{$name} error code");
        }

        // And the opted-out ones are admitted, which is why their `from` has to be honest.
        foreach ($composition_opt_outs as $name) {
            $this->assertTrue(
                pp_action_composition_precondition(pp_get_action($name) + ['name' => $name], $post_id),
                "{$name} is admitted on a corrupt page"
            );
        }
    }
}
