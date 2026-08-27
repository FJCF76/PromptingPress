<?php
/**
 * tests/ChatBatchCorruptRepairCarveOutTest.php — the chat half of ruling D-1 (#756).
 *
 * WHAT THIS FILE DEFENDS. #749 refuses a whole batch, before step 1, when any page it
 * names has a stored composition that cannot be read, and its refusal message prescribes
 * the repair: "one full `update_composition`, or `restore_composition`". On the chat
 * surface that instruction could not be followed — assets/js/pp-ai-chat.js routes EVERY
 * proposal, one step or many, through the batch endpoint, so the prescribed repair carried
 * the same post_id into the same gate and came back refused with the very code it was
 * meant to clear. Maintainer ruling D-1 (#767, applied here by reference) admits the
 * narrow carve-out that breaks the loop; #767 landed the CLI half and the shared predicate
 * pp_corrupt_page_repair_carve_out(), and this is the chat half.
 *
 * A carve-out NARROWS an enforcement gate, so the tests that matter most prove what it did
 * not open. Same three families as tests/CorruptPageRepairCarveOutTest.php, deliberately,
 * because the two halves of one ruling should be auditable side by side:
 *
 *   ADMITS   the exact batch shape the ruling describes, end to end through the REAL AJAX
 *            handler, not through a hand-rolled call to the predicate.
 *   REFUSES  every neighbour — a second step, another verb, an apply, a healthy page, a
 *            blank page, an unreadable read.
 *   HOLDS    the gates the carve-out does not touch (capabilities, the #404 baseline
 *            mandate, full validation, the CAS) still fire on a batch INSIDE the carve-out.
 *
 * THE ROLLBACK PAIRING GETS ITS OWN FAMILY, because it is the half of #756 that is not
 * about admission at all. The snapshot of a corrupt page is the degrading accessor's `[]`;
 * before this change _pp_restore_batch_snapshot() would write that `[]` back over a page
 * that had become readable, which on the carve-out path means over the operator's own
 * repair — silently, with rollback_errors empty. Measured on main before the fix. The
 * ERASES family below is the regression pin for it.
 *
 * WHY THE $wpdb SUBCLASS. pp_corrupt_page_repair_carve_out() reads the classification
 * authoritatively from the database and fails CLOSED without a usable handle, and the
 * bootstrap's shared stub answers every GET_LOCK with NULL (FrontPageSafeguardTest scripts
 * a failed-lock write against it). Without the subclass every "admits" test here would
 * pass for the wrong reason — or rather fail, having proved only that the shim refuses.
 */

use PHPUnit\Framework\TestCase;

/**
 * Grants the advisory lock so real composition writes land, and nothing else. Same shape
 * and same reason as PP_CarveOut_Lockable_Wpdb in tests/CorruptPageRepairCarveOutTest.php;
 * named separately so neither file's harness can drift into the other's expectations.
 */
class PP_ChatBatchCarveOut_Lockable_Wpdb extends wpdb
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

class ChatBatchCorruptRepairCarveOutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'     => [],
            'posts'         => [],
            'options'       => ['siteurl' => 'https://example.com'],
            'connectors'    => [],
            'next_id'       => 100,
            'wpdb_postmeta' => [],
        ];
        $GLOBALS['wpdb'] = new PP_ChatBatchCarveOut_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['_pp_test_user_caps']);
        $GLOBALS['_pp_test_store']['post_meta']     = [];
        $GLOBALS['_pp_test_store']['posts']         = [];
        $GLOBALS['_pp_test_store']['wpdb_postmeta'] = [];
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** The valid one-band composition a repair sends. */
    private function repairComposition(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'repaired', 'title' => 'Recovered']]];
    }

    /**
     * A page that WORKED and later went wrong: two real writes through the versioned
     * writer build the version marker, the content hash and the history ring, and only
     * then does a raw meta write break the composition. Corrupting from birth would leave
     * none of that meta, and "recoverable" would stop meaning anything — the ring is what
     * restore_composition replays.
     */
    private function corruptPage(string $title, $storedValue): int
    {
        $post_id = pp_create_page($title, 'draft');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'v1', 'title' => 'First draft']]]);
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'v2', 'title' => 'Second draft']]]);
        update_post_meta($post_id, '_pp_composition', $storedValue);
        // BOTH READERS, because the gate under test does not use the one a single
        // assertion would check. The detector classifies through the cache and the
        // admission through the row; with no divergence staged the harness makes them
        // agree, and asserting only the cached reader would leave that agreement an
        // accident of the stub rather than a stated premise.
        $this->assertFalse(pp_get_composition_result($post_id)['ok'], 'premise: corrupt to the cached reader');
        $this->assertFalse(
            pp_get_composition_result_authoritative($post_id)['ok'],
            'premise: and corrupt to the authoritative reader the carve-out uses'
        );
        return $post_id;
    }

    private function healthyPage(string $title = 'Healthy'): int
    {
        $post_id = pp_create_page($title, 'draft');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'Fine']]]);
        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise');
        return $post_id;
    }

    private function version(int $post_id): int
    {
        return pp_get_composition_marker($post_id)['version'];
    }

    /** One repair step, the shape a chat proposal carries. */
    private function repairStep(int $post_id, ?array $composition = null): array
    {
        return ['type' => 'action', 'name' => 'update_composition', 'params' => [
            'post_id' => $post_id, 'composition' => $composition ?? $this->repairComposition(),
        ]];
    }

    /**
     * Drives the REAL AJAX handler, not the executor — the 14.1 authoring-path rule. The
     * handler is where capabilities, the #404 baseline mandate and param coercion live, so
     * a carve-out proved only against pp_ai_execute_batch() would be proved against the
     * backstop rather than against the surface the issue is about.
     */
    private function throughChat(array $steps, array $baselines): array
    {
        return _pp_ai_execute_batch_response([
            'steps'     => json_encode($steps),
            'baselines' => json_encode($baselines),
        ]);
    }

    /** Deep snapshot of the whole post-meta store, for byte-identical assertions. */
    private function metaSnapshot(): array
    {
        return $GLOBALS['_pp_test_store']['post_meta'];
    }

    // ═══ ADMITS ══════════════════════════════════════════════════════════════

    /**
     * THE HEADLINE CASE, and the one the issue is named for. A chat proposal whose only
     * step is the prescribed repair now runs, on the surface that prescribed it.
     */
    public function testAOneStepUpdateCompositionRepairsAnUndecodablePageThroughTheChatHandler(): void
    {
        $post_id = $this->corruptPage('Truncated', '[{"component":"hero","props":{"title":"Half');

        $resp = $this->throughChat([$this->repairStep($post_id)], [$post_id => $this->version($post_id)]);

        $this->assertTrue($resp['ok'], 'the handler answered on its success branch');
        $this->assertTrue($resp['data']['ok'], 'the batch ran: ' . json_encode($resp['data']['error'] ?? null));
        $this->assertSame($this->repairComposition(), pp_get_composition_result($post_id)['composition']);

        // #818's guarantee is not weakened by reaching the repair from here: the bytes the
        // repair replaced are preserved as a raw ring entry, not discarded.
        $history = pp_get_composition_history($post_id);
        $this->assertTrue(
            pp_history_entry_is_raw($history[count($history) - 1]),
            'the unreadable bytes were preserved on the ring by the repair write (#818)'
        );
        $this->assertSame(
            '[{"component":"hero","props":{"title":"Half',
            $history[count($history) - 1]['raw'],
            'and they are the exact bytes, not a re-encoding'
        );
    }

    /**
     * EVERY PRODUCIBLE `unexpected_shape` IS ADMITTED, not just the headline one. The
     * classifier reaches that verdict from four different stored values, and they arrive
     * through different storage channels — a JSON object string, a valid-JSON SCALAR, a
     * non-string scalar row, and an array WordPress handed back already decoded. The
     * scalar sub-case earns its place twice over: it decodes cleanly, so nothing about it
     * looks broken to a list-blind reader, and it is the class whose only recoverable copy
     * is the preserved-bytes ring entry #818 writes.
     */
    public function testEveryUnexpectedShapeVariantIsAdmittedAndRepaired(): void
    {
        $variants = [
            'json object'            => '{"1":{"component":"hero"}}',
            'valid-JSON scalar'      => '42',
            'valid-JSON null'        => 'null',
            'already-decoded array'  => ['component' => 'hero', 'props' => []],
        ];

        foreach ($variants as $label => $stored) {
            $post_id = $this->corruptPage('Variant: ' . $label, $stored);
            $this->assertSame(
                'unexpected_shape',
                pp_get_composition_result($post_id)['error'],
                'premise for ' . $label
            );

            $resp = $this->throughChat([$this->repairStep($post_id)], [$post_id => $this->version($post_id)]);

            $this->assertTrue($resp['data']['ok'], $label . ' is inside the carve-out');
            $this->assertSame(
                $this->repairComposition(),
                pp_get_composition_result($post_id)['composition'],
                $label . ' was repaired'
            );
        }
    }

    /**
     * BOTH VERBS, because the ruling names both and because restore is the one whose
     * admission is least obvious: #233 says a restore is never blocked by current
     * validation rules, but the #749 preflight ran BEFORE any step's own semantics, so
     * restore was refused there anyway. The carve-out is what un-refuses it.
     */
    public function testAOneStepRestoreCompositionReplaysThePriorVersionThroughTheChatHandler(): void
    {
        $post_id = $this->corruptPage('Restorable', 'NOT_VALID_JSON{{{');

        $resp = $this->throughChat(
            [['type' => 'action', 'name' => 'restore_composition', 'params' => ['post_id' => $post_id]]],
            [$post_id => $this->version($post_id)]
        );

        $this->assertTrue($resp['data']['ok'], 'restore_composition is inside the carve-out');

        // "First draft", not "Second draft", and the difference is worth stating: the ring
        // records the composition each versioned write REPLACED, and the raw meta write
        // that corrupted the page pushed nothing. So the newest slot holds the state
        // before the second draft, and the second draft itself is exactly what was lost —
        // which is the shape of the real incident this fixture models.
        $this->assertSame(
            'First draft',
            pp_get_composition_result($post_id)['composition'][0]['props']['title'],
            'the newest ring entry was replayed'
        );
    }

    /**
     * THE TWO GATES AGREE. The executor carries its own copy of the refusal as the
     * backstop for every non-chat caller, and a carve-out honoured at only one of them
     * would be the original deadlock rebuilt one layer down — or, worse, an exemption the
     * chat grants that the executor does not, leaving the operator refused after their
     * capability check passed.
     */
    public function testTheExecutorBackstopAdmitsExactlyWhatTheChatHandlerAdmits(): void
    {
        $post_id = $this->corruptPage('Both gates', 'NOT_VALID_JSON{{{');
        $steps   = [$this->repairStep($post_id)];

        $this->assertNull(
            _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps)),
            'the single owner admits it'
        );

        $batch = pp_ai_execute_batch($steps, [$post_id => $this->version($post_id)]);
        $this->assertTrue($batch['ok'], 'and the executor called directly admits it too');
    }

    // ═══ REFUSES ═════════════════════════════════════════════════════════════

    /**
     * THE DECISION THIS FILE EXISTS TO PIN: single-step-only, not first-step-exemption.
     * A repair that travels with company is refused, and the reason is measured rather
     * than stylistic — see the ERASES family below for what a later step's rollback does
     * to a repair that already landed.
     */
    public function testARepairWithASecondStepBesideItIsStillRefused(): void
    {
        $post_id = $this->corruptPage('Not alone', 'NOT_VALID_JSON{{{');
        $healthy = $this->healthyPage('Innocent bystander');
        $before  = $this->metaSnapshot();

        $resp = $this->throughChat([
            $this->repairStep($post_id),
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $healthy]],
        ], [$post_id => $this->version($post_id)]);

        $this->assertFalse($resp['ok'], 'refused on the handler error branch, as #749 shaped it');
        $this->assertSame('decode_error', $resp['data']['error_code']);
        $this->assertSame($before, $this->metaSnapshot(), 'and nothing was written');
        $this->assertSame('draft', get_post($healthy)->post_status, 'step 2 never ran either');
    }

    /** Order does not buy an exemption: the repair second is refused just as the repair first is. */
    public function testARepairAsTheSECONDStepIsRefusedToo(): void
    {
        $post_id = $this->corruptPage('Second position', 'NOT_VALID_JSON{{{');
        $healthy = $this->healthyPage('First position');

        $resp = $this->throughChat([
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $healthy]],
            $this->repairStep($post_id),
        ], [$post_id => $this->version($post_id)]);

        $this->assertFalse($resp['ok']);
        $this->assertSame('decode_error', $resp['data']['error_code']);
    }

    /**
     * TWO REPAIRS ARE NOT ONE REPAIR. Two corrupt pages, one repair step each: both are
     * inside the ruling's verb and classification conditions, and the batch is still
     * refused, because the exemption is about the SHAPE of the batch, not about how
     * defensible each step is on its own.
     */
    public function testTwoRepairStepsForTwoCorruptPagesAreRefused(): void
    {
        $first  = $this->corruptPage('Corrupt A', 'NOT_VALID_JSON{{{');
        $second = $this->corruptPage('Corrupt B', '{"1":{"component":"hero"}}');
        $before = $this->metaSnapshot();

        $resp = $this->throughChat(
            [$this->repairStep($first), $this->repairStep($second)],
            [$first => $this->version($first), $second => $this->version($second)]
        );

        $this->assertFalse($resp['ok']);
        $this->assertSame($before, $this->metaSnapshot(), 'neither page was written');
    }

    /**
     * THE VERB ALLOWLIST IS CLOSED. Every other action either edits bands inside a
     * composition the reader cannot produce, or does not touch the composition at all;
     * neither can repair the page, so neither gets the hatch. Includes the deliberately
     * WIDE half of #749's gate (publish_page merely NAMES the page).
     */
    public function testEveryOtherSingleStepVerbOnACorruptPageIsStillRefused(): void
    {
        $post_id = $this->corruptPage('Other verbs', 'NOT_VALID_JSON{{{');
        $before  = $this->metaSnapshot();

        $others = [
            ['update_component', ['post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'New']]],
            ['add_component',    ['post_id' => $post_id, 'component' => 'hero', 'props' => ['title' => 'Tacked on']]],
            ['remove_component', ['post_id' => $post_id, 'component_index' => 0]],
            ['publish_page',     ['post_id' => $post_id]],
            ['update_page_title', ['post_id' => $post_id, 'title' => 'Renamed']],
        ];

        foreach ($others as [$name, $params]) {
            $resp = $this->throughChat(
                [['type' => 'action', 'name' => $name, 'params' => $params]],
                [$post_id => $this->version($post_id)]
            );
            $this->assertFalse($resp['ok'], $name . ' must stay refused');
            $this->assertSame('decode_error', $resp['data']['error_code'], $name);
        }

        $this->assertSame($before, $this->metaSnapshot(), 'and none of them wrote anything');
    }

    /**
     * THE SAME CLAIM, DERIVED FROM THE REGISTRY RATHER THAN FROM A LIST I TYPED. The test
     * above names five verbs; the registry holds far more, and a hand-written list cannot
     * fail when someone registers a sixth. The drift the allowlist exists to survive is
     * exactly "a new action appears and quietly inherits the hatch", so the sweep has to
     * ask the registry what exists.
     *
     * `restore_page` is the name that makes this worth the cycles: it is one token from
     * `restore_composition`, so any slip from exact matching toward a prefix or substring
     * test would admit a TRASH-restore on a corrupt page. Nothing else in the repo pins it.
     */
    public function testNoRegisteredVerbOutsideTheAllowlistIsEverAdmitted(): void
    {
        $post_id = $this->corruptPage('Registry sweep', 'NOT_VALID_JSON{{{');
        $swept   = 0;

        foreach (pp_get_registered_actions() as $name => $action) {
            if (in_array($name, ['update_composition', 'restore_composition'], true)) {
                continue;
            }
            $steps = [['type' => 'action', 'name' => $name, 'params' => ['post_id' => $post_id]]];
            $this->assertNull(
                _pp_batch_corrupt_repair_admitted($steps),
                $name . ' must not inherit the carve-out'
            );
            $this->assertNotNull(
                _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps)),
                $name . ' must still be refused'
            );
            $swept++;
        }

        $this->assertGreaterThan(10, $swept, 'premise: the sweep actually swept the registry');
        $this->assertSame(
            'NOT_VALID_JSON{{{',
            get_post_meta($post_id, '_pp_composition', true),
            'and a read-only sweep wrote nothing'
        );
    }

    /**
     * STEP COUNT IS THE HEADLINE CONDITION, so both sides of the boundary get a test and
     * not just the count==2 side. A count>=3 case is what would catch a rewrite toward a
     * first-step exemption or an off-by-one (`> 1`), neither of which a two-step batch
     * distinguishes; count==0 is a documented envelope shape and must be neither admitted
     * nor refused (an empty batch names no page, so there is nothing to refuse it for).
     */
    public function testOnlyABatchOfExactlyOneStepIsEverAdmitted(): void
    {
        $post_id = $this->corruptPage('Counting', 'NOT_VALID_JSON{{{');
        $repair  = $this->repairStep($post_id);
        $before  = $this->metaSnapshot();

        $this->assertNull(_pp_batch_corrupt_repair_admitted([]), 'zero steps');
        $this->assertNull(_pp_batch_unreadable_refusal([], []), 'and an empty batch has nothing to refuse');

        foreach ([2, 3, 5] as $count) {
            $steps = array_fill(0, $count, $repair);
            $this->assertNull(_pp_batch_corrupt_repair_admitted($steps), $count . ' steps');
            $this->assertNotNull(
                _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps)),
                $count . ' steps must still refuse'
            );
        }

        $this->assertSame($before, $this->metaSnapshot(), 'nothing was written by any of them');
    }

    /**
     * TYPE MATTERS. The executor's dispatcher treats every non-'apply' type as an action —
     * the right posture for a write path, where a misspelled type must not skip a guard,
     * and the wrong one for a GATE. A malformed step must not be able to fall INTO an
     * exemption, so the admission asks the narrow question.
     */
    public function testAStepWhoseTypeIsNotExactlyActionIsNotAdmitted(): void
    {
        $post_id = $this->corruptPage('Type gate', 'NOT_VALID_JSON{{{');

        foreach (['apply', ''] as $type) {
            $steps = [['type' => $type, 'name' => 'update_composition', 'params' => [
                'post_id' => $post_id, 'composition' => $this->repairComposition(),
            ]]];
            $this->assertNull(
                _pp_batch_corrupt_repair_admitted($steps),
                'type "' . $type . '" must not be admitted'
            );
            $this->assertNotNull(
                _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps)),
                'type "' . $type . '" still refuses'
            );
        }

        // AND ON THE REAL SURFACE, because the handler NORMALIZES every step before the
        // gate sees it (sanitize_text_field on type and name, pp_ai_coerce_params on
        // params). A predicate-level assertion cannot notice a normalization step that
        // rewrote 'apply' into something the gate would admit; this one can.
        $resp = $this->throughChat(
            [['type' => 'apply', 'name' => 'update_composition', 'params' => [
                'post_id' => $post_id, 'composition' => $this->repairComposition(),
            ]]],
            [$post_id => $this->version($post_id)]
        );
        $this->assertFalse($resp['ok'], 'an apply step is refused through the handler too');
        $this->assertSame('decode_error', $resp['data']['error_code']);
        $this->assertSame('NOT_VALID_JSON{{{', get_post_meta($post_id, '_pp_composition', true));
    }

    /** An unregistered name resolves to no action, so there is no verb to check — refuse. */
    public function testAnUnregisteredStepNameIsNotAdmitted(): void
    {
        $post_id = $this->corruptPage('Unknown verb', 'NOT_VALID_JSON{{{');

        $this->assertNull(_pp_batch_corrupt_repair_admitted([
            ['type' => 'action', 'name' => 'update_composition_v2', 'params' => ['post_id' => $post_id]],
        ]));
    }

    /** No target page, or a non-numeric one: nothing to classify, so nothing to exempt. */
    public function testARepairStepWithNoUsablePostIdIsNotAdmitted(): void
    {
        $this->corruptPage('Has a target', 'NOT_VALID_JSON{{{');

        foreach ([[], ['post_id' => 'not-a-number'], ['post_id' => ['nested']]] as $params) {
            $this->assertNull(_pp_batch_corrupt_repair_admitted([
                ['type' => 'action', 'name' => 'update_composition', 'params' => $params],
            ]), json_encode($params));
        }
    }

    /**
     * THE ADMITTED DIRECTION OF THE SAME COERCION, which the rejecting cases above cannot
     * prove. A numeric STRING post_id is the shape the public executor actually receives —
     * pp_ai_coerce_params() has not run there — and every other admitting test in this file
     * goes through the handler, where it already has. So without this, a tightening to
     * is_int() on either side would silently refuse every executor-path repair and the
     * suite would stay green.
     *
     * The second assertion is the point: it pins that the two sides resolve the SAME page
     * from the same step, which is the identity the shared extractor exists to guarantee.
     *
     * AND IT SHOWS WHERE THE LOOSENESS STOPS. The GATES accept a numeric string, because
     * they must agree with each other about which page a step names whether or not
     * pp_ai_coerce_params() ran. The ACTION does not: pp_validate_action() rejects a
     * string `post_id` outright. So this step is admitted by the carve-out and then fails
     * on its own validation terms — which is ruling condition (3) demonstrated rather than
     * asserted, and the reason the gate's looser coercion is safe rather than sloppy. It
     * decides only which page a refusal is ABOUT; it never decides what gets written.
     */
    public function testAnUncoercedNumericStringPostIdIsResolvedIdenticallyByBothSides(): void
    {
        $post_id = $this->corruptPage('Uncoerced', 'NOT_VALID_JSON{{{');
        $steps   = [['type' => 'action', 'name' => 'update_composition', 'params' => [
            'post_id' => (string) $post_id, 'composition' => $this->repairComposition(),
        ]]];

        $this->assertSame($post_id, _pp_batch_corrupt_repair_admitted($steps), 'the string id coerces to the int page');
        $this->assertSame(
            [$post_id],
            array_keys(_pp_batch_unreadable_targets($steps)),
            'and the detector resolves the very same page from the very same step'
        );
        $this->assertNull(
            _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps)),
            'so the exempt-set identity holds and the gate admits'
        );

        // Past the gate, the action layer applies its own, stricter contract.
        $batch = pp_ai_execute_batch($steps, [$post_id => $this->version($post_id)]);
        $this->assertFalse($batch['ok'], 'the ADMITTED step is still fully validated');
        $this->assertSame(0, $batch['failed_at']);
        $this->assertStringContainsString('must be int, got string', $batch['steps'][0]['error']);
        $this->assertSame(
            'NOT_VALID_JSON{{{',
            get_post_meta($post_id, '_pp_composition', true),
            'and the corrupt bytes are untouched'
        );
    }

    /**
     * A HEALTHY PAGE IS UNTOUCHED BY ALL OF THIS. Its batch was never refused, so the
     * carve-out has nothing to admit — and must not become a second, quieter path into a
     * page that is perfectly readable.
     */
    public function testAOneStepWriteToAHealthyPageIsUnaffected(): void
    {
        $post_id = $this->healthyPage('Still fine');
        $steps   = [$this->repairStep($post_id)];

        $this->assertNull(_pp_batch_corrupt_repair_admitted($steps), 'nothing to carve out');
        $this->assertNull(
            _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps)),
            'and nothing to refuse either'
        );

        $resp = $this->throughChat($steps, [$post_id => $this->version($post_id)]);
        $this->assertTrue($resp['data']['ok']);
        $this->assertSame($this->repairComposition(), pp_get_composition_result($post_id)['composition']);
    }

    /** Blank is not corrupt: a never-populated page keeps behaving like any healthy page. */
    public function testABlankPageIsNotTreatedAsCorruptAndIsNotAdmitted(): void
    {
        $post_id = pp_create_page('Never populated', 'draft');
        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise');

        $this->assertNull(_pp_batch_corrupt_repair_admitted([$this->repairStep($post_id)]));
    }

    /**
     * FAIL CLOSED WHEN THE READ CANNOT BE AUTHORITATIVE. The shared predicate refuses to
     * open without a usable $wpdb handle, because the cached classification is precisely
     * the value that might be stale and this answer OPENS a gate. Inherited, and pinned
     * here so the chat surface cannot later grow its own degrading read.
     */
    public function testWithNoDatabaseHandleTheCarveOutStaysShutAndTheBatchIsRefused(): void
    {
        $post_id = $this->corruptPage('No handle', 'NOT_VALID_JSON{{{');
        $steps   = [$this->repairStep($post_id)];
        unset($GLOBALS['wpdb']);

        $this->assertNull(_pp_batch_corrupt_repair_admitted($steps));

        $batch = pp_ai_execute_batch($steps);
        $this->assertFalse($batch['ok']);
        $this->assertSame('decode_error', $batch['error_code']);
        $this->assertSame('NOT_VALID_JSON{{{', get_post_meta($post_id, '_pp_composition', true));
    }

    /**
     * THE EXEMPT SET IS EXACTLY THE ADMITTED PAGE. Today one step can only ever produce
     * one unreadable target, so this is belt and braces — written anyway because the
     * alternative is a gate that widens silently if either detector ever learns to read a
     * second post_id out of one step.
     *
     * SYNTHETIC BY CONSTRUCTION, and labelled so nobody mistakes it for reachability
     * coverage: the map below is hand-built because no real caller can currently produce it,
     * which is the whole reason the guard is defensive. The second assertion pins the half
     * that IS real — what the live detector actually returns for this step — so the day a
     * detector learns to read a second post_id, that assertion is what goes red.
     */
    public function testAnAdmittedStepDoesNotExemptSomeOtherUnreadablePage(): void
    {
        $target  = $this->corruptPage('The repair target', 'NOT_VALID_JSON{{{');
        $bystander = $this->corruptPage('An unrelated corrupt page', '{"1":{"component":"hero"}}');
        $steps   = [$this->repairStep($target)];

        // A detector map naming a page the step does NOT target — the shape the guard is
        // written against. The refusal must survive it.
        $refusal = _pp_batch_unreadable_refusal(
            $steps,
            [$bystander => 'unexpected_shape', $target => 'decode_error']
        );

        $this->assertNotNull($refusal, 'an extra unreadable page defeats the exemption');
        $this->assertSame('unexpected_shape', $refusal['error_code'], 'and the refusal reports the head of the map');

        // The reachable half: the live detector resolves this step to exactly one page.
        $this->assertSame(
            [$target],
            array_keys(_pp_batch_unreadable_targets($steps)),
            'a one-step batch cannot name a second page today — this is what would have to change first'
        );
    }

    /**
     * TWO READERS MEET HERE, AND ONLY HERE, which is why this pair belongs to the batch
     * surface rather than to the CLI sibling. The DETECTOR that decides which pages the
     * refusal is about reads through the object cache (pp_get_composition_result); the
     * ADMISSION that decides which page is exempt reads the database row
     * (pp_get_composition_result_authoritative, via the shared predicate). Before #756 one
     * reader decided everything on this path and could not disagree with itself.
     *
     * THE GATE OPENS ON AGREEMENT AND NOTHING ELSE. This is the direction that would be a
     * real hole: the cache still holds a corrupt copy while the row has been repaired, so
     * the detector reports the page — and an exemption trusting only the detector would
     * hand a preflight-free whole-composition write to a perfectly healthy page. The
     * authoritative read is what refuses.
     */
    public function testAStaleCorruptCacheOverAHealthyRowDoesNotOpenTheCarveOut(): void
    {
        $post_id = $this->corruptPage('Cache says corrupt', 'NOT_VALID_JSON{{{');

        // The ROW is healthy — somebody already repaired it — while this request's cached
        // copy still holds the corrupt bytes. Staged, then only READ: no write follows, so
        // the frozen-staged-row hazard the bootstrap documents cannot bite here.
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition'] =
            json_encode([['component' => 'hero', 'props' => ['id' => 'fixed', 'title' => 'Already repaired']]]);

        $this->assertFalse(pp_get_composition_result($post_id)['ok'], 'premise: the cache still says corrupt');
        $this->assertTrue(pp_get_composition_result_authoritative($post_id)['ok'], 'premise: the row says healthy');

        $steps = [$this->repairStep($post_id)];
        $this->assertNull(
            _pp_batch_corrupt_repair_admitted($steps),
            'the authoritative read closes the hatch on a healthy row'
        );
        $this->assertNotNull(
            _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps)),
            'so the cached detector\'s report still refuses the batch — fail closed'
        );
    }

    /**
     * THE OTHER DIRECTION, WHICH WAS THE HOLE (#833). This test used to pin the opposite
     * outcome, and the behaviour it pinned is the bug: with the row corrupt and this
     * request's cache stale-healthy, the cached DETECTOR reported nothing, so there was no
     * refusal at all — a batch of any shape ran against a page whose stored bytes nobody
     * could decode, and its rollback baseline was captured from the stale copy.
     *
     * Since #833 the detector reads the row too, so this direction refuses like the other
     * one. What survives from the old test is the ASYMMETRY it was written to pin: the
     * carve-out still cannot be opened by the detector alone, and the two halves reaching
     * the same source is what makes "agreement" cheap rather than lucky. The lone repair is
     * covered by the sibling test below; here the batch is a SECOND step alongside it, the
     * shape ruling D-1 never admits.
     */
    public function testAStaleHealthyCacheOverACorruptRowNowRefusesLikeTheOtherDirection(): void
    {
        $post_id = $this->healthyPage('Cache says healthy');
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition'] = 'NOT_VALID_JSON{{{';

        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise: the cache says healthy');
        $this->assertFalse(pp_get_composition_result_authoritative($post_id)['ok'], 'premise: the row says corrupt');

        $steps = [
            $this->repairStep($post_id),
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $post_id]],
        ];
        $this->assertSame(
            [$post_id => 'decode_error'],
            _pp_batch_unreadable_targets($steps),
            'the detector reads the row, so it reports the page the cache called healthy'
        );
        $refusal = _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps));
        $this->assertNotNull($refusal, 'and a two-step batch is refused — the carve-out admits one step or none');
        $this->assertSame('decode_error', $refusal['error_code'], 'reported as the ROW classifies it');
    }

    /**
     * THE LONE REPAIR STILL GETS THROUGH THAT DIVERGENCE, which is the reason the fix could
     * not simply refuse harder (#833). Both halves of the gate now read the row: the row
     * says corrupt, so the detector reports the page AND the carve-out admits it, and the
     * exemption's "the map must name exactly the admitted page" test passes. Before #833
     * this proposal also went through, but for the wrong reason — nothing had classified
     * the page at all.
     *
     * STOPS AT THE GATE deliberately: driving the write would exercise the bootstrap's
     * frozen-staged-row hazard rather than this issue, and what is under test here is which
     * batches the gate admits.
     */
    public function testAStaleHealthyCacheOverACorruptRowStillAdmitsTheLoneRepair(): void
    {
        $post_id = $this->healthyPage('Cache says healthy, row does not');
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition'] = 'NOT_VALID_JSON{{{';

        $steps = [$this->repairStep($post_id)];
        // THE DETECTOR ASSERTION IS THE ONE THAT DISTINGUISHES THIS FROM THE BUG. Before
        // #833 the refusal below was also null — not because the exemption lifted it, but
        // because nothing had classified the page at all. Without this line the test passes
        // against the code it exists to pin against.
        $this->assertSame(
            [$post_id => 'decode_error'],
            _pp_batch_unreadable_targets($steps),
            'the detector names the page the row calls corrupt'
        );
        $this->assertSame(
            $post_id,
            _pp_batch_corrupt_repair_admitted($steps),
            'the admission reads the row and finds the page repairable'
        );
        $this->assertNull(
            _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps)),
            'and the refusal lifts for it, because both halves now name the same page'
        );
    }

    // ═══ HOLDS — the gates the carve-out does not touch ══════════════════════

    /**
     * CONDITION (3): the incoming replacement still passes full validation. Nothing about
     * what a verb accepts moved — the carve-out gates a PREFLIGHT, and pp_validate_action()
     * runs at the step's own turn exactly as before. The object-shaped payload #724
     * rejects is rejected here too, and the corrupt bytes it would have replaced survive.
     */
    public function testAnInvalidReplacementInAnAdmittedRepairIsRejectedAndWritesNothing(): void
    {
        $post_id = $this->corruptPage('Bad payload', 'NOT_VALID_JSON{{{');

        $resp = $this->throughChat(
            [$this->repairStep($post_id, ['not' => 'a list'])],
            [$post_id => $this->version($post_id)]
        );

        $this->assertTrue($resp['ok'], 'the batch was ADMITTED — the rejection is the step\'s, not the gate\'s');
        $this->assertFalse($resp['data']['ok']);
        $this->assertSame(0, $resp['data']['failed_at'], 'the admitted step itself failed');
        $this->assertStringContainsString('must be a list of components', $resp['data']['steps'][0]['error']);
        $this->assertSame(
            'NOT_VALID_JSON{{{',
            get_post_meta($post_id, '_pp_composition', true),
            'the bytes the bad repair would have replaced are untouched'
        );

        // AND THE WITHHOLD IS VISIBLE ON THIS SURFACE, not only to the executor. The
        // rollback report is what the chat client renders (#755), so a regression that
        // dropped rollback_errors from the chat envelope has to fail here — proving it
        // only at pp_ai_execute_batch() would prove it below the surface the issue is about.
        $this->assertTrue($resp['data']['rolled_back']);
        $this->assertCount(1, $resp['data']['rollback_errors']);
        $this->assertStringContainsString(
            'could not be read when this batch snapshotted them',
            $resp['data']['rollback_errors'][0]
        );
    }

    /**
     * THE #404 BASELINE MANDATE STILL BINDS. The carve-out lifts the #749 preflight and
     * nothing else, and the mandate runs BEFORE it in the handler — so a repair proposal
     * with no baseline is rejected for the ordinary reason, with the ordinary code, not
     * admitted because the page happens to be corrupt.
     */
    public function testAnAdmittedRepairStillNeedsItsCasBaseline(): void
    {
        $post_id = $this->corruptPage('No baseline', 'NOT_VALID_JSON{{{');

        $resp = $this->throughChat([$this->repairStep($post_id)], []);

        $this->assertFalse($resp['ok']);
        $this->assertSame('missing_expected_version', $resp['data']['error_code']);
        $this->assertSame('NOT_VALID_JSON{{{', get_post_meta($post_id, '_pp_composition', true));
    }

    /**
     * THE CAS STILL BINDS, which is the TOCTOU closure the shared predicate's docblock
     * promises: a carve-out admission is a point-in-time answer, and the pre-write version
     * threaded as expected_version is what stops it becoming a licence to overwrite a page
     * that stopped being corrupt underneath the proposal.
     *
     * THE ADMISSION IS ASSERTED BEFORE THE COMPETING WRITE, and that ordering is the whole
     * test. An earlier draft landed the competing repair FIRST, which made the page healthy
     * at preflight time — so the #749 gate never engaged, the carve-out was never consulted,
     * and what remained was a plain CAS test on a healthy page that passed identically
     * against the pre-#756 tree. A test for a carve-out that never enters the carve-out is
     * worse than no test: it reports coverage of the one window the design concedes is open.
     */
    public function testAnAdmittedRepairCarryingAStaleBaselineConflictsRatherThanClobbering(): void
    {
        $post_id = $this->corruptPage('Moved underneath', 'NOT_VALID_JSON{{{');
        $stale   = $this->version($post_id);
        $steps   = [$this->repairStep($post_id)];

        // PREMISE: the batch is inside the carve-out, right now, while the page is corrupt.
        $this->assertSame($post_id, _pp_batch_corrupt_repair_admitted($steps), 'premise: admitted while corrupt');
        $this->assertNull(
            _pp_batch_unreadable_refusal($steps, _pp_batch_unreadable_targets($steps)),
            'premise: and the gate would let it through'
        );

        // ONLY NOW does somebody else repair the page — the window the docblock concedes.
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'other', 'title' => 'Their repair']]]);

        $resp = $this->throughChat($steps, [$post_id => $stale]);

        $this->assertFalse($resp['data']['ok']);
        $this->assertStringContainsString('changed since you last read it', $resp['data']['steps'][0]['error']);
        $this->assertSame(
            'Their repair',
            pp_get_composition_result($post_id)['composition'][0]['props']['title'],
            'the other writer\'s repair survives'
        );
    }

    /**
     * THE RACE THE ROLLBACK PAIRING EXISTS FOR, driven end to end rather than at the
     * restorer. A competing repair lands between the SNAPSHOT and the write: the capture is
     * the corrupt page's `[]`, this batch's write then loses the compare-and-swap, and the
     * rollback runs against a page that now reads perfectly. Without the capture-based
     * withhold that rollback writes `[]` over the other writer's repair.
     *
     * The interleaving is staged by snapshotting first and handing the SAME bundle to the
     * restorer, which is what pp_ai_execute_batch() does with it — wiring a real thread race
     * would pin the scheduler rather than the guard.
     */
    public function testACompetingRepairLandingInsideAnAdmittedBatchIsNotErasedByItsRollback(): void
    {
        $post_id  = $this->corruptPage('Two repairers', 'NOT_VALID_JSON{{{');
        $steps    = [$this->repairStep($post_id)];

        $this->assertSame($post_id, _pp_batch_corrupt_repair_admitted($steps), 'premise: admitted while corrupt');
        $snapshot = _pp_snapshot_batch_targets($steps);
        $this->assertSame([$post_id => 'decode_error'], $snapshot['unreadable'], 'premise: captured as unreadable');

        // The other writer wins the page between capture and write.
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'other', 'title' => 'Their repair']]]);

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertSame(
            'Their repair',
            pp_get_composition_result($post_id)['composition'][0]['props']['title'],
            'the rollback did not write the [] stand-in over a stranger\'s repair'
        );
        $this->assertCount(1, $errors, 'and it said so');
    }

    /**
     * CAPABILITIES ARE THE SURFACE'S, NOT THE PREDICATE'S. The shared predicate knows
     * nothing about the request by design, so the handler's own capability gate has to be
     * what stops a Contributor-level user reaching the admitted write.
     */
    public function testAnAdmittedRepairStillFailsTheCapabilityGate(): void
    {
        $post_id = $this->corruptPage('No permission', 'NOT_VALID_JSON{{{');
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => true, 'edit_post' => false];

        $resp = $this->throughChat([$this->repairStep($post_id)], [$post_id => $this->version($post_id)]);

        $this->assertFalse($resp['ok']);
        $this->assertSame('Permission denied.', $resp['data']);
        $this->assertSame('NOT_VALID_JSON{{{', get_post_meta($post_id, '_pp_composition', true));
    }

    // ═══ ERASES — the rollback pairing (#756's other half) ═══════════════════

    /**
     * THE REGRESSION PIN. Measured on main before this change: with a corrupt page's
     * snapshot holding the degrading accessor's `[]`, a rollback that finds the page
     * READABLE writes that `[]` straight over whatever is there — and the one thing that
     * makes a corrupt page readable mid-batch is a repair. Silently: rollback_errors came
     * back empty. #818 does not rescue this; it preserves the CORRUPT bytes on the ring,
     * while the repair is erased and the page afterwards reads ok-and-empty, so nothing
     * flags it again.
     *
     * Driven at _pp_restore_batch_snapshot() directly because the interleaving it pins is
     * a timing window: capture while corrupt, become readable, roll back. Wiring a race
     * into the executor would pin the schedule, not the guard.
     */
    public function testARollbackNeverWritesBackACompositionCapturedFromAnUnreadablePage(): void
    {
        $post_id = $this->corruptPage('Repaired mid-batch', 'NOT_VALID_JSON{{{');

        $snapshot = _pp_snapshot_batch_targets([$this->repairStep($post_id)]);
        $this->assertSame([], $snapshot['posts'][$post_id]['composition'], 'premise: the capture is the [] stand-in');
        $this->assertSame([$post_id => 'decode_error'], $snapshot['unreadable'], 'premise: recorded as unreadable');

        // The repair lands, exactly as the carve-out now allows.
        pp_update_composition($post_id, $this->repairComposition());

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertSame(
            $this->repairComposition(),
            pp_get_composition_result($post_id)['composition'],
            'the repair survived the rollback'
        );
        $this->assertCount(1, $errors, 'and the rollback said so rather than reporting a clean revert');
        $this->assertStringContainsString('was NOT rolled back', $errors[0]);
        $this->assertStringContainsString('could not be read when this batch snapshotted them', $errors[0]);
    }

    /**
     * SAME GUARD, THE OTHER OUTCOME. When the admitted repair FAILS, the page is still
     * corrupt at rollback time — and the withheld write must be reported with wording
     * that is true of THIS case. The pre-existing message says the bytes "changed to an
     * unreadable state during this batch", which on the carve-out path is false: they
     * were unreadable from the start. Two states, two sentences.
     */
    public function testAFailedAdmittedRepairLeavesTheCorruptBytesAndSaysWhyAccurately(): void
    {
        $post_id = $this->corruptPage('Failed repair', 'NOT_VALID_JSON{{{');

        $batch = pp_ai_execute_batch(
            [$this->repairStep($post_id, ['not' => 'a list'])],
            [$post_id => $this->version($post_id)]
        );

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertCount(1, $batch['rollback_errors'], '#755: rolled_back is not clean until you check this');
        $this->assertStringContainsString('could not be read when this batch snapshotted them', $batch['rollback_errors'][0]);
        $this->assertStringNotContainsString(
            'changed to an unreadable state during this batch',
            $batch['rollback_errors'][0],
            'the mid-batch-corruption sentence does not belong on this path'
        );
        $this->assertSame('NOT_VALID_JSON{{{', get_post_meta($post_id, '_pp_composition', true));
    }

    /**
     * THE OTHER FIELDS STILL ROLL BACK. Withholding is scoped to the composition, which is
     * the only field whose snapshot is a stand-in — title, slug and status were captured
     * honestly and the batch did change them.
     */
    public function testWithholdingTheCompositionDoesNotWithholdTheOtherFields(): void
    {
        $post_id = $this->corruptPage('Other fields', 'NOT_VALID_JSON{{{');
        $snapshot = _pp_snapshot_batch_targets([$this->repairStep($post_id)]);

        wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
        pp_update_page_title($post_id, 'Renamed mid-batch');
        pp_update_composition($post_id, $this->repairComposition());

        _pp_restore_batch_snapshot($snapshot);

        $this->assertSame('draft', get_post($post_id)->post_status, 'status rolled back');
        $this->assertSame('Other fields', get_post($post_id)->post_title, 'title rolled back');
        $this->assertSame(
            $this->repairComposition(),
            pp_get_composition_result($post_id)['composition'],
            'composition did not'
        );
    }

    /**
     * THE MID-BATCH-CORRUPTION GUARD (#749) IS UNTOUCHED. A page that was READABLE at
     * snapshot time and went corrupt during the batch keeps its own branch and its own
     * sentence — the new guard is checked first, but only fires on pages the snapshot
     * recorded as unreadable, so it cannot swallow this case.
     */
    public function testAPageThatGoesCorruptMidBatchStillGetsTheOriginalWithholdAndWording(): void
    {
        $post_id  = $this->healthyPage('Corrupted mid-batch');
        $snapshot = _pp_snapshot_batch_targets([['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $post_id]]]);
        $this->assertSame([], $snapshot['unreadable'], 'premise: readable at snapshot time');

        update_post_meta($post_id, '_pp_composition', 'BROKEN{{{');

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('changed to an unreadable state during this batch', $errors[0]);
        $this->assertSame('BROKEN{{{', get_post_meta($post_id, '_pp_composition', true));
    }

    /**
     * THE NEW BRANCH DOES NOT DISTURB WHAT FOLLOWS IT. _pp_restore_batch_snapshot() runs
     * the menu layer after every post, and #749's own suite pins that a MID-BATCH withhold
     * survives the menu layer's return (the two error lists are merged, and dropping either
     * would hide the condition it exists to report). The capture-based withhold is a second
     * producer feeding that same merge, so it needs the same pin — otherwise a future
     * `continue` in this branch would skip the menu restore and nothing would say so.
     *
     * The plain healthy-page rollback is deliberately NOT re-tested here; tests/
     * BatchRollbackCorruptSnapshotTest.php owns that contract and this change does not
     * touch it.
     */
    public function testACaptureBasedWithholdSurvivesTheMenuLayersReturn(): void
    {
        $post_id  = $this->corruptPage('Withhold plus menus', 'NOT_VALID_JSON{{{');
        $snapshot = _pp_snapshot_batch_targets([$this->repairStep($post_id)]);

        // A menu baseline the restorer will fail to put back, so the menu layer
        // contributes its own error alongside the composition withhold.
        $snapshot['menus'] = ['menus' => [(object) ['term_id' => 4242, 'name' => 'Gone']], 'items' => [], 'locations' => []];

        $errors = _pp_restore_batch_snapshot($snapshot);

        $composition_errors = array_values(array_filter($errors, static function ($e) {
            return str_contains($e, 'could not be read when this batch snapshotted them');
        }));
        $this->assertCount(1, $composition_errors, 'the withhold survived the merge with the menu layer');
        $this->assertGreaterThanOrEqual(1, count($errors), 'and the merged list is at least that long');
        $this->assertSame('NOT_VALID_JSON{{{', get_post_meta($post_id, '_pp_composition', true));
    }

    // ═══ The refusal envelope is unchanged for everything still refused ══════

    /**
     * #749's envelope shape is machine-facing surface and the client keys on it
     * (batchWasRefusedUpFront). Narrowing which batches refuse must not change what a
     * refusal LOOKS like for the ones that still do.
     */
    public function testTheRefusalEnvelopeIsUnchangedForABatchThatStillRefuses(): void
    {
        $post_id = $this->corruptPage('Envelope', '{"1":{"component":"hero"}}');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $post_id]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame([], $batch['steps']);
        $this->assertNull($batch['failed_at']);
        $this->assertFalse($batch['rolled_back']);
        $this->assertSame([], $batch['rollback_errors']);
        $this->assertSame([], $batch['versions']);
        $this->assertSame('unexpected_shape', $batch['error_code']);
        $this->assertStringContainsString(
            pp_composition_integrity_message($post_id, 'unexpected_shape'),
            $batch['error']
        );
    }

    /**
     * AND THE REFUSAL NOW PRESCRIBES A ROUTE THAT RUNS FROM HERE. The old tail said
     * "with a single write and not as a step in a proposal", which on this surface was the
     * instruction that could not be followed — ruling D-1's requirement is that every
     * message prescribing a repair names one that executes.
     */
    public function testTheRefusalTellsTheOperatorToSendTheRepairAsItsOwnProposal(): void
    {
        $post_id = $this->corruptPage('Instruction', '{"1":{"component":"hero"}}');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $post_id]],
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $post_id, 'title' => 'x']],
        ]);

        $this->assertStringContainsString('in a proposal of its OWN', $batch['error']);
        $this->assertStringNotContainsString('not as a step in a proposal', $batch['error']);
        $this->assertStringContainsString('update_composition', $batch['error']);
        $this->assertStringContainsString('restore_composition', $batch['error']);
    }
}
