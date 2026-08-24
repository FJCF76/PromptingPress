<?php
/**
 * tests/BatchRollbackCorruptSnapshotTest.php — a batch rollback can no longer erase a
 * corrupt page's recoverable bytes (#749).
 *
 * THE BUG THIS PINS. `_pp_snapshot_batch_targets()` captured every named page's
 * composition through `pp_get_composition()`, the accessor that DEGRADES a corrupt row
 * to `[]`, and `_pp_restore_batch_snapshot()` wrote that capture back unconditionally.
 * A batch that merely NAMED a corrupt-but-recoverable page and then failed for any
 * reason rolled back by writing `[]` over the only recoverable copy of those bytes, and
 * reported `rolled_back: true` with no error. The page went from "corrupt but
 * restorable" to genuinely empty, silently.
 *
 * THE FIX (mechanism: REFUSE, per the recorded ruling's second sanctioned shape).
 *
 * TWO gates, one detector, one wording. The chat gate is the one production hits;
 * the executor gate is the backstop that makes the fix hold for every caller.
 *
 *   _pp_ai_execute_batch_response(POST)          [lib/ai-chat.php — the chat surface]
 *      │
 *      ├─ _pp_batch_unreadable_targets(steps) non-empty
 *      │      └─► REFUSE: ok=false + {error, error_code} via wp_send_json_error,
 *      │                  rendered on the client's !resp.success branch
 *      │
 *      └─ all readable
 *           │
 *           ▼
 *   pp_ai_execute_batch(steps)                    [lib/actions.php — every caller]
 *      │
 *      ├─ _pp_snapshot_batch_targets(steps)  ── classifies from its own capture read,
 *      │                                        recording snapshot['unreadable']
 *      │
 *      ├─ snapshot['unreadable'] non-empty ─► REFUSE before step 1
 *      │        ok=false, steps=[], failed_at=null, rolled_back=false,
 *      │        error_code = the classification itself, ZERO writes
 *      │
 *      ├─ all readable ───────────────────► run the batch exactly as before
 *      │
 *      └─ on a step failure: _pp_restore_batch_snapshot()
 *             └─ re-classifies each target against LIVE state before writing;
 *                a row that went unreadable mid-batch keeps its bytes and the
 *                withholding is reported through rollback_errors.
 *
 * Coverage:
 *   both classifications (unexpected_shape, decode_error) × both storage channels
 *     (raw JSON string, already-decoded non-list array) + the bare-scalar value path
 *   zero writes on refusal — the WHOLE post-meta store is byte-identical, so the
 *     version marker, the content hash and the history ring are all pinned too
 *   no step runs on refusal (asserted through an observable side effect, not just meta)
 *   determinism: which page a multi-corrupt batch names
 *   the accepted counterpart: a healthy page still batches and still rolls back
 *   the chat entry point refuses through the error branch with the same code + message
 *   the mid-batch TOCTOU guard in the restore path, including the menu-error merge
 */

use PHPUnit\Framework\TestCase;

class BatchRollbackCorruptSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'connectors' => [],
            'next_id'    => 100,
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_user_caps']);
        parent::tearDown();
    }

    /** A healthy page with a real, readable composition. */
    private function healthyPage(string $title = 'Healthy'): int
    {
        $id = pp_create_page($title, 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Original']]]);
        return $id;
    }

    /**
     * A page whose stored `_pp_composition` is corrupt but RECOVERABLE.
     *
     * AUTHORED FIRST, then corrupted — not corrupted from birth. Two real writes
     * through pp_update_composition() build the version marker, the content hash
     * and the history ring before the raw write breaks the composition, which is
     * both the realistic shape of this state (a page that WORKED and later went
     * wrong) and the thing that makes "recoverable" mean something: the ring is
     * what restore_composition replays. A fixture corrupted from birth has none of
     * that meta, so a byte-identical assertion over it would prove far less than it
     * appears to.
     *
     * The corruption itself is seeded through raw meta on purpose: since #724 the
     * action layer refuses to CREATE this state, so raw meta (and the history ring,
     * which admits an object snapshot behind an is_array() gate) is the only way it
     * arises — and the only way a fixture can reproduce a page that carries it.
     */
    private function corruptPage(string $title, $storedValue): int
    {
        $id = pp_create_page($title, 'draft');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'First draft']]]);
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Second draft']]]);
        update_post_meta($id, '_pp_composition', $storedValue);
        return $id;
    }

    /** Deep snapshot of the whole post-meta store for byte-identical assertions. */
    private function metaSnapshot(): array
    {
        return $GLOBALS['_pp_test_store']['post_meta'];
    }

    /** A batch that names $id with a step that never touches the composition. */
    private function publishBatchFor(int $id): array
    {
        return [
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $id]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ];
    }

    // ── Refusal: every corrupt classification, through every storage channel ──

    /**
     * THE HEADLINE CASE. A JSON object where a list belongs — the shape #724 now
     * refuses to write but that pre-#724 content, raw meta writes and replayed
     * history snapshots all still carry.
     */
    public function testBatchNamingAnObjectShapedPageIsRefusedAndWritesNothing(): void
    {
        $id     = $this->corruptPage('Object shaped', '{"1":{"component":"hero"}}');
        $before = $this->metaSnapshot();

        $batch = pp_ai_execute_batch($this->publishBatchFor($id));

        $this->assertFalse($batch['ok']);
        $this->assertSame('unexpected_shape', $batch['error_code']);
        $this->assertSame([], $batch['steps'], 'refused before step 1 — no step result exists');
        $this->assertNull($batch['failed_at'], 'no step ran, so no step index failed');
        $this->assertFalse($batch['rolled_back'], 'nothing ran, so there was nothing to roll back');
        $this->assertSame([], $batch['rollback_errors']);
        $this->assertSame([], $batch['versions'], 'no page survived a batch that never ran');

        // ZERO WRITES. The whole meta store is byte-identical, which pins the
        // composition bytes, the version marker, the content hash and the history
        // ring in one assertion.
        $this->assertSame($before, $this->metaSnapshot(), 'a refused batch must not write any meta');

        // Spelled out for the ring specifically, because it is the recovery source
        // the word "recoverable" is about: a refusal that preserved the corrupt
        // composition but ate the history would still have destroyed the page.
        $history = pp_get_composition_history($id);
        $this->assertNotEmpty($history, 'precondition: the fixture authored a real history ring');
        $this->assertSame(
            'First draft',
            $history[0]['composition'][0]['props']['title'],
            'the prior version restore_composition would replay is still there'
        );

        // And the bytes are still recoverable: the classifier still reports the
        // exact error and still hands back the raw payload.
        $stored = pp_get_composition_result($id);
        $this->assertFalse($stored['ok']);
        $this->assertSame('unexpected_shape', $stored['error']);
        $this->assertSame('{"1":{"component":"hero"}}', $stored['raw']);
    }

    public function testBatchNamingAnUndecodablePageIsRefusedWithItsOwnClassification(): void
    {
        // Truncated write / encoding bug: undecodable, so decode_error — a DIFFERENT
        // code from the object case, and the refusal must say which one it is.
        $id     = $this->corruptPage('Truncated', '[{"component":"hero","props":{"title":"Half');
        $before = $this->metaSnapshot();

        $batch = pp_ai_execute_batch($this->publishBatchFor($id));

        $this->assertFalse($batch['ok']);
        $this->assertSame('decode_error', $batch['error_code']);
        $this->assertSame($before, $this->metaSnapshot());
        $this->assertSame('[{"component":"hero","props":{"title":"Half', pp_get_composition_result($id)['raw']);
    }

    /**
     * THE SECOND STORAGE CHANNEL. WordPress serializes an array written through
     * update_post_meta() and hands the array back on read, so a non-list PHP array
     * is a real stored shape, not a fixture artifact — pp_get_composition_result()
     * classifies it explicitly (lib/wp.php) and tests/CliGateTest.php already seeds
     * one. It must refuse exactly like the JSON-string channel.
     */
    public function testBatchNamingAnAlreadyDecodedNonListPageIsRefused(): void
    {
        $id     = $this->corruptPage('Decoded object', ['component' => 'hero', 'props' => []]);
        $before = $this->metaSnapshot();

        $batch = pp_ai_execute_batch($this->publishBatchFor($id));

        $this->assertFalse($batch['ok']);
        $this->assertSame('unexpected_shape', $batch['error_code']);
        $this->assertSame($before, $this->metaSnapshot(), 'the decoded-array channel must not be written over either');
        $this->assertSame(['component' => 'hero', 'props' => []], get_post_meta($id, '_pp_composition', true));
    }

    public function testBatchNamingABareScalarPageIsRefused(): void
    {
        // Valid JSON, but a scalar — a distinct value-type path into the same
        // classification. It decodes, so nothing about it looks broken to a
        // list-blind reader; it must still refuse.
        $id = $this->corruptPage('Bare scalar', '42');

        $batch = pp_ai_execute_batch($this->publishBatchFor($id));

        $this->assertFalse($batch['ok']);
        $this->assertSame('unexpected_shape', $batch['error_code']);
        $this->assertSame('42', get_post_meta($id, '_pp_composition', true));
    }

    /**
     * THE ORIGINAL DESTRUCTIVE SHAPE. Every other refusal test here names the corrupt
     * page with a step that never touches the composition (publish_page,
     * update_page_title) — the deliberately WIDE half of the gate. This is the narrow
     * half: a proposal that edits the corrupt page's components, which is what a real
     * batch that erased a page looked like. If the gate is ever narrowed to
     * composition-mutating steps only, the wide tests go red and this one must not.
     */
    public function testBatchEditingComponentsOnACorruptPageIsRefused(): void
    {
        $id     = $this->corruptPage('Edited while corrupt', '{"1":{"component":"hero"}}');
        $before = $this->metaSnapshot();

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'New'],
            ]],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame('unexpected_shape', $batch['error_code']);
        $this->assertSame([], $batch['steps']);
        $this->assertSame($before, $this->metaSnapshot(), 'the bytes an edit would have replaced are untouched');
    }

    public function testBatchAddingAComponentToACorruptPageIsRefused(): void
    {
        $id = $this->corruptPage('Appended while corrupt', '{"component":"hero"}');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_component', 'params' => [
                'post_id' => $id, 'component' => 'hero', 'props' => ['title' => 'Tacked on'],
            ]],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame('unexpected_shape', $batch['error_code']);
        $this->assertSame('{"component":"hero"}', get_post_meta($id, '_pp_composition', true));
    }

    // ── The refusal is honest about what it refused and how to fix it ────────

    public function testTheRefusalCarriesTheSharedIntegritySentenceAndARepairPath(): void
    {
        $id = $this->corruptPage('Diagnosed', '{"component":"hero"}');

        $batch = pp_ai_execute_batch($this->publishBatchFor($id));

        // The diagnosis is the SINGLE-OWNED sentence every surface uses (#725), not a
        // fifth spelling invented here.
        $this->assertStringContainsString(
            pp_composition_integrity_message($id, 'unexpected_shape'),
            $batch['error']
        );
        // Plus this surface's own next action: what happened, and how to get unstuck.
        $this->assertStringContainsString('refused before any step ran', $batch['error']);
        $this->assertStringContainsString('update_composition', $batch['error']);
        $this->assertStringContainsString('restore_composition', $batch['error']);
    }

    // ── No step runs — asserted through a side effect, not just through meta ──

    public function testARefusedBatchRunsNoStepsEvenWhenTheFirstStepWouldHaveSucceeded(): void
    {
        // Step 1 targets a HEALTHY page and would succeed on its own; step 2 names the
        // corrupt one. The refusal is a preflight, so step 1 must never run either.
        $healthy = $this->healthyPage('Would have been renamed');
        $corrupt = $this->corruptPage('Corrupt straggler', '{"component":"hero"}');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $healthy, 'title' => 'Renamed']],
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $corrupt]],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame([], $batch['steps']);
        $this->assertSame(
            'Would have been renamed',
            get_post($healthy)->post_title,
            'the healthy page keeps its title — step 1 never executed'
        );
        $this->assertSame('draft', get_post($corrupt)->post_status, 'and the corrupt page was never published');
    }

    public function testTheRefusalNamesTheFirstUnreadableTargetInStepOrder(): void
    {
        // Two corrupt pages with DIFFERENT classifications. The reported one must be
        // the first in step order, deterministically — not whichever the map happens
        // to iterate first.
        $second = $this->corruptPage('Named second', '{"component":"hero"}');   // unexpected_shape
        $first  = $this->corruptPage('Named first', '[{"component":"her');      // decode_error

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $first]],
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $second]],
        ]);

        $this->assertSame('decode_error', $batch['error_code'], 'the FIRST step\'s page decides the code');
        $this->assertStringContainsString("Page {$first}:", $batch['error']);
        $this->assertStringNotContainsString("Page {$second}:", $batch['error']);
    }

    // ── The accepted counterpart: healthy pages are completely unaffected ─────

    public function testAHealthyPageStillBatchesAndStillRollsBackItsComposition(): void
    {
        $id = $this->healthyPage('Still works');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'Changed'],
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        // The ordinary rollback contract is untouched: a real step ran, failed_at is an
        // integer, and the composition came back.
        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame(1, $batch['failed_at']);
        $this->assertCount(2, $batch['steps']);
        $this->assertSame([], $batch['rollback_errors'], 'a readable target restores cleanly');
        $this->assertSame('Original', pp_get_composition($id)[0]['props']['title']);
    }

    public function testAnEmptyButReadableCompositionIsNotTreatedAsCorrupt(): void
    {
        // The distinction the whole issue turns on: a stored "[]" is a deliberate
        // authored blank page, NOT an unreadable one. It must batch normally.
        $id = pp_create_page('Deliberately blank', 'draft');
        update_post_meta($id, '_pp_composition', '[]');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $id, 'title' => 'Renamed']],
        ]);

        $this->assertTrue($batch['ok'], 'an empty list is readable — no refusal');
        $this->assertSame('Renamed', get_post($id)->post_title);
    }

    public function testAPageWithNoCompositionAtAllIsNotTreatedAsCorrupt(): void
    {
        // Absent meta is a genuinely new page, not a corrupt one.
        $id = pp_create_page('Never authored', 'draft');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $id, 'title' => 'Renamed']],
        ]);

        $this->assertTrue($batch['ok']);
        $this->assertSame('Renamed', get_post($id)->post_title);
    }

    public function testAStepNamingAPostThatDoesNotExistIsNotARefusal(): void
    {
        // Never snapshotted, so never restored over — and therefore never a reason to
        // refuse. The step fails on its own terms at its own turn.
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => 99999]],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame(0, $batch['failed_at'], 'the step ran and failed — this is not the preflight refusal');
        $this->assertCount(1, $batch['steps']);
    }

    /**
     * ORPHAN META — the state the `get_post()` guard actually exists for.
     *
     * A post_id whose post row is gone but whose `_pp_composition` row survives (a
     * direct-DB delete, a purged page, an id pointing at something else). The meta
     * classifies as corrupt, but the snapshotter skips the page entirely because
     * get_post() is false — so there is no captured composition, nothing a rollback
     * could write over, and therefore nothing to refuse. Without the guard this
     * would refuse every batch touching such an id forever.
     */
    public function testCorruptOrphanMetaOnADeletedPostIsNotARefusal(): void
    {
        $id = $this->corruptPage('About to be purged', '{"component":"hero"}');
        unset($GLOBALS['_pp_test_store']['posts'][$id]); // post row gone, meta left behind
        $this->assertNotSame('', get_post_meta($id, '_pp_composition', true), 'precondition: meta survives');

        $steps = [['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $id]]];

        $batch = pp_ai_execute_batch($steps);
        $this->assertFalse($batch['ok']);
        $this->assertSame(0, $batch['failed_at'], 'the step ran and failed on its own terms');
        $this->assertSame('', $batch['error_code'] ?? '', 'not the preflight refusal');

        // BOTH gates, because they own separate copies of the post gate: the executor
        // refuses from the snapshotter's map, the chat surface from the detector
        // helper. Drop the guard from either one and orphan meta starts refusing
        // batches over a page that cannot be snapshotted and so cannot be harmed.
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => true, 'publish_pages' => true];
        $resp = _pp_ai_execute_batch_response([
            'steps' => json_encode($steps), 'baselines' => json_encode([]),
        ]);
        $this->assertTrue($resp['ok'], 'the chat gate must not refuse an unsnapshottable page either');
        $this->assertSame([], _pp_batch_unreadable_targets($steps));
    }

    /**
     * SCOPE OF THE GATE. A corrupt page nobody named must not refuse anything. Pins
     * that the gate reads the batch's own steps rather than the state of the site.
     */
    public function testACorruptPageTheBatchNeverNamesDoesNotRefuseIt(): void
    {
        $this->corruptPage('Bystander', '{"component":"hero"}');
        $healthy = $this->healthyPage('The one being edited');

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $healthy, 'title' => 'Renamed']],
        ]);

        $this->assertTrue($batch['ok'], 'an unnamed corrupt page is none of this batch\'s business');
        $this->assertSame('Renamed', get_post($healthy)->post_title);
    }

    /**
     * THE #719 INTERPLAY, at batch level. create_page is the one step whose side
     * effect is tracked separately (snapshot['created_posts']) and the one whose
     * leak-a-page failure mode tests/CreatePageWriteFailureTest.php exists to pin.
     * A refusal that fired AFTER step 1 instead of before it would strand a page,
     * and every other assertion in this file would stay green.
     */
    public function testARefusedBatchWithACreatePageStepStrandsNoPage(): void
    {
        $corrupt = $this->corruptPage('Blocks the batch', '{"component":"hero"}');
        $before  = count($GLOBALS['_pp_test_store']['posts']);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_page', 'params' => [
                'title' => 'Never created', 'composition' => [['component' => 'hero', 'props' => ['title' => 'x']]],
            ]],
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $corrupt]],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertSame([], $batch['steps']);
        $this->assertCount($before, $GLOBALS['_pp_test_store']['posts'], 'no page outlived a batch that never ran');
    }

    // ── The chat entry point refuses through the ERROR branch ────────────────

    /**
     * The chat surface must answer through wp_send_json_error with the structured
     * {error, error_code} payload the client already renders (assets/js/pp-ai-chat.js
     * keys on resp.data.error there), NOT through the success branch with a step-less
     * batch envelope — the client's failure renderer indexes steps[failed_at].
     *
     * Driven through the REAL handler with JSON-encoded $_POST input, so the refusal
     * is proven on the path an operator actually takes.
     */
    public function testTheChatHandlerRefusesACorruptTargetThroughTheErrorBranch(): void
    {
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => true, 'publish_pages' => true];
        $id = $this->corruptPage('Chat corrupt', '{"component":"hero"}');

        $resp = _pp_ai_execute_batch_response([
            'steps' => json_encode([
                ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $id]],
            ]),
            'baselines' => json_encode([]),
        ]);

        $this->assertFalse($resp['ok'], 'the handler must use the error branch, not the success branch');
        $this->assertSame('unexpected_shape', $resp['data']['error_code']);
        $this->assertStringContainsString('refused before any step ran', $resp['data']['error']);
        $this->assertSame('draft', get_post($id)->post_status, 'nothing executed');
    }

    /**
     * Both refusal gates — the executor's backstop and the chat entry point's — read
     * the same detection and render the same wording, because both are single-owned.
     * If either ever grows its own copy, this reddens.
     */
    public function testTheExecutorAndTheChatHandlerRefuseIdentically(): void
    {
        $GLOBALS['_pp_test_user_caps'] = ['edit_posts' => true, 'publish_pages' => true];
        $id    = $this->corruptPage('Same words', '{"component":"hero"}');
        $steps = [['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $id]]];

        $viaExecutor = pp_ai_execute_batch($steps);
        $viaHandler  = _pp_ai_execute_batch_response(['steps' => json_encode($steps), 'baselines' => json_encode([])]);

        $this->assertSame($viaExecutor['error'], $viaHandler['data']['error']);
        $this->assertSame($viaExecutor['error_code'], $viaHandler['data']['error_code']);
    }

    // ── The restore path's own guard: corruption that arrives MID-batch ──────

    /**
     * The preflight proves a target was readable when the batch STARTED. It cannot
     * prove the row is still readable when the rollback runs — the two reads are
     * separated by every step the batch executed, and an external raw meta write, an
     * import, or a hand-edited row can land in between. The restore path therefore
     * re-classifies against live state and WITHHOLDS the composition write rather
     * than putting its stale snapshot over newly-unreadable bytes.
     *
     * Driven straight at _pp_restore_batch_snapshot() with a hand-built bundle,
     * because that is the only honest way to occupy the window.
     */
    public function testRollbackWithholdsTheCompositionWhenTheRowGoesUnreadableMidBatch(): void
    {
        $id = $this->healthyPage('Corrupted mid-flight');
        pp_update_page_title($id, 'Title the batch changed');

        // The bundle the snapshotter would have built at a point when the row WAS
        // readable.
        $snapshot = [
            'posts' => [$id => [
                'title'       => 'Corrupted mid-flight',
                'slug'        => get_post($id)->post_name,
                'status'      => 'draft',
                'composition' => [['component' => 'hero', 'props' => ['title' => 'Original']]],
                'seo_meta'    => pp_get_seo_meta($id),
            ]],
            'created_posts'   => [],
            'unreadable'      => [],
            'site_options'    => [],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ];

        // Someone outside this request corrupts the row while the batch is running.
        update_post_meta($id, '_pp_composition', '{"component":"hero"}');

        $errors = _pp_restore_batch_snapshot($snapshot);

        // The recoverable bytes survive the rollback.
        $this->assertSame('{"component":"hero"}', get_post_meta($id, '_pp_composition', true));
        // And the caller is told, through the channel the envelope already documents.
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Page {$id}:", $errors[0]);
        $this->assertStringContainsString('NOT rolled back', $errors[0]);
        // Every OTHER field still rolled back — the snapshot of those was honest.
        $this->assertSame('Corrupted mid-flight', get_post($id)->post_title);
    }

    public function testRollbackRestoresTheCompositionNormallyWhenTheRowIsStillReadable(): void
    {
        $id = $this->healthyPage('Untouched by outsiders');
        pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Mid-batch']]]);

        $errors = _pp_restore_batch_snapshot([
            'posts' => [$id => [
                'title'       => 'Untouched by outsiders',
                'slug'        => get_post($id)->post_name,
                'status'      => 'draft',
                'composition' => [['component' => 'hero', 'props' => ['title' => 'Original']]],
                'seo_meta'    => pp_get_seo_meta($id),
            ]],
            'created_posts'   => [],
            'unreadable'      => [],
            'site_options'    => [],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ]);

        $this->assertSame([], $errors);
        $this->assertSame('Original', pp_get_composition($id)[0]['props']['title']);
    }

    /**
     * The menu layer used to be the only producer of rollback errors and returned its
     * list directly, so a withheld composition restore had to be MERGED in rather than
     * replaced by it. Pins that a menu-carrying rollback still reports the composition
     * finding.
     */
    public function testAWithheldCompositionSurvivesTheMenuLayersReturn(): void
    {
        $id = $this->healthyPage('Menus present');
        update_post_meta($id, '_pp_composition', '{"component":"hero"}');

        $errors = _pp_restore_batch_snapshot([
            'posts' => [$id => [
                'title'       => 'Menus present',
                'slug'        => get_post($id)->post_name,
                'status'      => 'draft',
                'composition' => [['component' => 'hero', 'props' => ['title' => 'Original']]],
                'seo_meta'    => pp_get_seo_meta($id),
            ]],
            'created_posts'   => [],
            'unreadable'      => [],
            'site_options'    => [],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => ['menus' => [], 'locations' => []],
        ]);

        $composition_errors = array_values(array_filter(
            $errors,
            static fn ($e) => strpos($e, 'NOT rolled back') !== false
        ));
        $this->assertCount(1, $composition_errors, 'the menu return must not swallow the composition finding');
        $this->assertSame('{"component":"hero"}', get_post_meta($id, '_pp_composition', true));
    }

    // ── The snapshotter itself no longer reads through the degrading accessor ─

    public function testTheSnapshotBundleRecordsUnreadableTargetsAndStaysReadOnly(): void
    {
        $corrupt = $this->corruptPage('Recorded', '{"component":"hero"}');
        $healthy = $this->healthyPage('Also named');
        $before  = $this->metaSnapshot();

        $snapshot = _pp_snapshot_batch_targets([
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $corrupt]],
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $healthy]],
        ]);

        $this->assertSame([$corrupt => 'unexpected_shape'], $snapshot['unreadable']);

        // THE TWO-LAYER CONTRACT, stated rather than assumed. The bundle STILL carries
        // a degraded [] composition for the unreadable page — the classifier has no
        // honest value to offer and the bundle shape stays uniform. That value is
        // exactly what used to be written back and destroy the page. What makes it
        // safe is not the capture; it is that `unreadable` refuses the batch before
        // anything can restore, and that _pp_restore_batch_snapshot() re-classifies
        // against live state before it writes. A future reader must not "clean this
        // up" by trusting the bundle.
        $this->assertSame([], $snapshot['posts'][$corrupt]['composition'], 'still degraded — and still never written');
        $this->assertSame('Recorded', $snapshot['posts'][$corrupt]['title'], 'the non-composition fields are honest');

        $this->assertSame(
            [['component' => 'hero', 'props' => ['title' => 'Original', 'id' => pp_get_composition($healthy)[0]['props']['id']]]],
            $snapshot['posts'][$healthy]['composition'],
            'a readable target is still captured exactly as before'
        );
        $this->assertSame($before, $this->metaSnapshot(), 'the snapshotter never writes');
    }

    /**
     * THE TWO DETECTORS MUST AGREE, structurally.
     *
     * The chat gate calls _pp_batch_unreadable_targets() directly; the executor gate
     * reads the map the snapshotter built from its own capture reads. Those are two
     * pieces of code resolving "which pages does this batch name, and which of them
     * are unreadable", and the snapshotter's docblock asserts the post gate is
     * character-for-character the same. That claim is a correctness requirement — if
     * they ever diverge, the executor's refusal stops covering a page the rollback
     * can still write over — so it is pinned here rather than left to a comment.
     */
    public function testBothDetectorsResolveTheSameUnreadableSet(): void
    {
        $corruptA = $this->corruptPage('Corrupt A', '{"component":"hero"}');
        $healthy  = $this->healthyPage('Healthy middle');
        $corruptB = $this->corruptPage('Corrupt B', '[{"component":"her');

        $steps = [
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $corruptA]],
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $healthy, 'title' => 'x']],
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $corruptB]],
            ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => 99999]], // no such post
            ['type' => 'action', 'name' => 'create_page', 'params' => ['title' => 'no post_id']],
        ];

        $this->assertSame(
            _pp_batch_unreadable_targets($steps),
            _pp_snapshot_batch_targets($steps)['unreadable'],
            'the chat gate and the executor gate must see the same pages, in the same order'
        );
        // And that shared answer is the one the refusal is built from.
        $this->assertSame(
            [$corruptA => 'unexpected_shape', $corruptB => 'decode_error'],
            _pp_batch_unreadable_targets($steps)
        );
    }
}
