<?php
/**
 * tests/BatchGateAuthoritativeClassifyTest.php — the #749 batch refusal classifies its
 * targets from the DATABASE ROW and fails closed when it cannot read one (#833).
 *
 * THE BUG THIS PINS. The refusal and the #756 carve-out are two halves of one gate, and
 * they read two different things. The carve-out asked the row
 * (pp_get_composition_result_authoritative, #767); the refusal's three classify sites —
 * `_pp_batch_unreadable_targets()`, `_pp_snapshot_batch_targets()` and the rollback's live
 * re-classify — asked the OBJECT CACHE. WordPress warms a post's whole meta row in one
 * query (#823), so any earlier get_post_meta() on the page populates `_pp_composition` in
 * the request-local cache, and a concurrent write landing after that warm-up leaves the
 * cached copy stale. In the direction that hurts — cache healthy, row corrupt — every
 * cached classifier reported "readable":
 *
 *   1. the detector reported nothing, so the refusal never fired;
 *   2. the snapshotter captured the stale composition as the rollback baseline;
 *   3. a later step failed, the rollback re-classified through the same stale cache, saw
 *      "readable", and wrote the captured composition over the corrupt row.
 *
 * That is the class #749 exists to prevent, reached around the front of the gate. #818
 * reduces the damage (the undecodable bytes survive on the history ring) but the refusal
 * itself was skippable, which is what this file makes impossible.
 *
 * THE RULING (#833, 2026-08-27): the refusal is a GATE, and gates classify through the
 * AUTHORITATIVE read, failing CLOSED when no authoritative read is possible — the
 * asymmetry #767 recorded on pp_composition_db_handle(). The carve-out already read the
 * row; this is its other half learning to.
 *
 * WHAT DID NOT CHANGE, and is pinned here so a later reader does not "simplify" it away:
 *
 *   the cached check stays, as an added REQUIREMENT rather than an alternative source. A
 *     target must read clean BOTH ways. That keeps the cache-corrupt/row-healthy refusal
 *     #756 pinned, and it is what lets _pp_snapshot_batch_targets() keep capturing its
 *     rollback baseline from the CACHED read — the state the batch's own steps execute
 *     against. If a healthy verdict could be reached over a corrupt cache, the captured
 *     value would be the degrading accessor's `[]` under a map that says fine, and the
 *     rollback would write that `[]` over a readable row: #749's data loss, rebuilt.
 *   the captured VALUE is still the cached one, for the same reason.
 *   a batch that names no existing post still classifies nothing, so it is never refused
 *     for a database it never needed.
 *
 * Coverage:
 *   the filed interleaving end to end through the REAL chat surface (Section 14.1), and
 *     through the executor's own copy of the gate
 *   the lone repair still admitted through that same divergence (the carve-out's half is
 *     in tests/ChatBatchCorruptRepairCarveOutTest.php)
 *   fail closed with no handle: the chat gate, the executor gate, the rollback withhold
 *   fail closed on a FAILED row read too, which is the half of that reachable in production
 *   the unverifiable refusal says nothing about corruption, prescribes no repair, and earns
 *     the model no note (#704: a site fault is not the model's to answer)
 *   a no-post batch is unaffected by the absence of a handle
 *   the cache-corrupt/row-healthy direction still refuses, with the code it always used
 *   the rollback's live re-classify reads the row too
 *   healthy pages: the batch runs and its envelope carries no refusal at all
 *   the cost: one composition SELECT per distinct named page per gate evaluation
 */

use PHPUnit\Framework\TestCase;

/**
 * Counts the composition point-lookups the gate issues, so the per-batch cost is a pinned
 * number rather than a claim in a changelog. Grants the lock like every other batch harness.
 */
class PP_GateCost_Wpdb extends PP_Lockable_Wpdb
{
    /** @var string[] Every query get_var() was asked, in order. */
    public array $queries = [];

    public function get_var(string $query)
    {
        $this->queries[] = $query;
        return parent::get_var($query);
    }

    /**
     * How many times the `_pp_composition` row was read for a given post.
     *
     * The post id is matched as a WHOLE number, not as a substring: `post_id = 100` is a
     * prefix of `post_id = 1000`, so a substring test would credit one page's read to
     * another the first time a fixture used both.
     */
    public function compositionReads(int $post_id): int
    {
        $n = 0;
        foreach ($this->queries as $q) {
            if (!str_contains($q, "meta_key = '_pp_composition'")) {
                continue;
            }
            if (preg_match('/post_id = (\d+)/', $q, $m) && (int) $m[1] === $post_id) {
                $n++;
            }
        }
        return $n;
    }
}

/**
 * A handle whose `_pp_composition` SELECT reports a DATABASE FAILURE: null plus a non-empty
 * `last_error`, which is exactly what a killed query, a lock-wait timeout or a gone-away
 * connection looks like — and is indistinguishable from "no row" without the flag (#212).
 */
class PP_FailingRead_Wpdb extends PP_Lockable_Wpdb
{
    /** Real wpdb flushes this to '' at the start of every query and sets it on error. */
    public string $last_error = '';

    public function get_var(string $query)
    {
        $this->last_error = '';
        if (str_contains($query, "meta_key = '_pp_composition'")) {
            $this->last_error = 'MySQL server has gone away';
            return null;
        }
        return parent::get_var($query);
    }
}

class BatchGateAuthoritativeClassifyTest extends TestCase
{
    private const CORRUPT_BYTES = 'NOT_VALID_JSON{{{';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'     => [],
            'posts'         => [],
            'options'       => ['siteurl' => 'https://example.com'],
            'connectors'    => [],
            'next_id'       => 100,
            'custom_css'    => '',
            'wpdb_postmeta' => [],
        ];
        // The gate under test reads the database BY DESIGN, so these tests must exercise
        // that branch rather than quietly proving the no-handle fallback. Tests that are
        // ABOUT the missing handle drop it themselves, which is the honest shape: the
        // absence is the state under test there.
        $GLOBALS['wpdb'] = new PP_GateCost_Wpdb();
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

    private function healthyPage(string $title = 'Healthy'): int
    {
        $post_id = pp_create_page($title, 'draft');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'Fine']]]);
        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise: readable');
        return $post_id;
    }

    /**
     * Stages the interleaving the issue describes: the ROW holds undecodable bytes while
     * this request's cached copy still holds the healthy composition it warmed earlier.
     *
     * Staged and then only READ — no write follows in these tests — so the frozen-staged-row
     * hazard tests/bootstrap.php documents (#831) cannot bite.
     */
    private function stageStaleHealthyCacheOverCorruptRow(int $post_id): void
    {
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition'] = self::CORRUPT_BYTES;

        $this->assertTrue(pp_get_composition_result($post_id)['ok'], 'premise: the cache says healthy');
        $this->assertFalse(
            pp_get_composition_result_authoritative($post_id)['ok'],
            'premise: and the row says corrupt'
        );
    }

    private function version(int $post_id): int
    {
        return pp_get_composition_marker($post_id)['version'];
    }

    private function editStep(int $post_id, string $title = 'Edited'): array
    {
        return ['type' => 'action', 'name' => 'update_composition', 'params' => [
            'post_id'     => $post_id,
            'composition' => [['component' => 'hero', 'props' => ['id' => 'edited', 'title' => $title]]],
        ]];
    }

    private function publishStep(int $post_id): array
    {
        return ['type' => 'action', 'name' => 'publish_page', 'params' => ['post_id' => $post_id]];
    }

    /** The REAL AJAX handler — capabilities, the #404 baseline mandate and coercion included. */
    private function throughChat(array $steps, array $baselines): array
    {
        return _pp_ai_execute_batch_response([
            'steps'     => json_encode($steps),
            'baselines' => json_encode($baselines),
        ]);
    }

    // ═══ THE FILED INTERLEAVING ══════════════════════════════════════════════

    /**
     * THE REGRESSION PIN, through the surface the issue is about. Two steps on a page whose
     * row is corrupt and whose cache is stale-healthy: before #833 the detector reported
     * nothing, the proposal ran, and its rollback baseline was the stale copy. Now the
     * refusal fires before step 1 and nothing is written.
     */
    public function testAStaleHealthyCacheOverACorruptRowIsRefusedThroughTheChatSurface(): void
    {
        $post_id = $this->healthyPage('Cache is stale');
        $baseline = $this->version($post_id);
        $this->stageStaleHealthyCacheOverCorruptRow($post_id);

        $resp = $this->throughChat(
            [$this->editStep($post_id), $this->publishStep($post_id)],
            [$post_id => $baseline]
        );

        $this->assertFalse($resp['ok'], 'the proposal is refused, not executed');
        $this->assertSame('decode_error', $resp['data']['error_code'], 'classified as the ROW reads');
        $this->assertStringContainsString(
            'composition data integrity error (decode_error)',
            $resp['data']['error'],
            'and it is the shared integrity sentence, not a second spelling'
        );
        $this->assertSame(
            'draft',
            get_post($post_id)->post_status,
            'the publish step never ran either — the refusal is before step 1'
        );
    }

    /**
     * THE EXECUTOR'S OWN COPY OF THE GATE, which is the backstop for every caller that is
     * not the chat entry point. It classifies from the snapshotter's capture, so this pins
     * that the snapshotter reads the row as well — fixing only the detector would have left
     * the batch refused at one gate and admitted at the other.
     */
    public function testTheExecutorGateRefusesTheSameInterleaving(): void
    {
        $post_id = $this->healthyPage('Cache is stale');
        $this->stageStaleHealthyCacheOverCorruptRow($post_id);

        $batch = pp_ai_execute_batch([$this->editStep($post_id), $this->publishStep($post_id)]);

        $this->assertFalse($batch['ok']);
        $this->assertSame([], $batch['steps'], 'no step ran');
        $this->assertNull($batch['failed_at'], 'the pre-execution refusal shape');
        $this->assertSame('decode_error', $batch['error_code']);
    }

    /**
     * THE SNAPSHOT BUNDLE ITSELF, because the map it carries is what the rollback consults
     * later. A page the row calls corrupt must be listed as unreadable even while the cached
     * read (the one the captured VALUE comes from) is perfectly happy.
     */
    public function testTheSnapshotRecordsTheRowsVerdictWhileCapturingTheCachedValue(): void
    {
        $post_id = $this->healthyPage('Cache is stale');
        $this->stageStaleHealthyCacheOverCorruptRow($post_id);

        $snapshot = _pp_snapshot_batch_targets([$this->editStep($post_id)]);

        $this->assertSame([$post_id => 'decode_error'], $snapshot['unreadable']);
        $this->assertSame(
            [['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'Fine']]],
            $snapshot['posts'][$post_id]['composition'],
            'the captured baseline is still the cached read: the state this batch would execute against'
        );
    }

    /**
     * THE THIRD SITE — the rollback's live re-classify — reads the row too. The snapshot is
     * built while everything agrees; the row then goes corrupt mid-batch while the cache
     * keeps the pre-batch copy, which is exactly what a request cannot notice through
     * get_post_meta(). The composition write must be WITHHELD rather than rolled over it.
     */
    public function testTheRollbackReClassifiesFromTheRowAndWithholdsTheWrite(): void
    {
        $post_id  = $this->healthyPage('Goes corrupt mid-batch');
        $snapshot = _pp_snapshot_batch_targets([$this->editStep($post_id)]);
        $this->assertSame([], $snapshot['unreadable'], 'premise: nothing was unreadable at capture');

        // The row goes corrupt after the capture; the cache still holds the healthy copy.
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition'] = self::CORRUPT_BYTES;

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('integrity error (decode_error)', $errors[0]);
        $this->assertStringContainsString('NOT rolled back', $errors[0]);
    }

    // ═══ FAIL CLOSED WITH NO HANDLE ══════════════════════════════════════════

    /**
     * NO AUTHORITATIVE READ, NO PASSAGE. A gate that cannot confirm the state it is gating
     * on refuses — the posture pp_composition_db_handle()'s docblock records for the
     * carve-out, now applied to the other half. Production WordPress always has a handle,
     * so this branch is a unit-context and exotic-host path, and it is stated rather than
     * hidden.
     */
    public function testWithNoDatabaseHandleTheChatGateRefusesAHealthyPage(): void
    {
        $post_id  = $this->healthyPage('Perfectly fine');
        $baseline = $this->version($post_id);
        unset($GLOBALS['wpdb']);

        $resp = $this->throughChat([$this->editStep($post_id)], [$post_id => $baseline]);

        $this->assertFalse($resp['ok']);
        $this->assertSame(PP_BATCH_TARGET_UNVERIFIABLE, $resp['data']['error_code']);
        // AND THE MODEL IS NOT TOLD (#704, applied to this reason by #833). A note is
        // present exactly when a rejection is the model's to answer, and a database the gate
        // could not reach is a site fault in the same class as a conflict or a transport
        // failure: nothing the model writes changes it. The key stays ABSENT, which also
        // keeps the client from offering the repair affordance it attaches to any note —
        // an affordance whose payload teaches a whole-composition overwrite, on a page
        // nothing has shown to be damaged.
        $this->assertArrayNotHasKey('model_note', $resp['data']);
        $this->assertSame(
            [['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'Fine']]],
            pp_get_composition($post_id),
            'and the page is untouched'
        );
    }

    /** The executor's copy of the same gate, from the same absence. */
    public function testWithNoDatabaseHandleTheExecutorGateRefusesToo(): void
    {
        $post_id = $this->healthyPage('Perfectly fine');
        unset($GLOBALS['wpdb']);

        $batch = pp_ai_execute_batch([$this->editStep($post_id)]);

        $this->assertFalse($batch['ok']);
        $this->assertSame([], $batch['steps']);
        $this->assertNull($batch['failed_at']);
        $this->assertSame(PP_BATCH_TARGET_UNVERIFIABLE, $batch['error_code']);
    }

    /**
     * THE UNVERIFIABLE REFUSAL IS NOT A CORRUPTION REPORT, and the wording is pinned because
     * it is the whole difference between "we could not check" and "your page is broken". The
     * corrupt-page advice — a whole-composition rewrite — would tell an operator to overwrite
     * a page nothing has shown to be damaged.
     */
    public function testTheUnverifiableRefusalNeitherClaimsCorruptionNorPrescribesARepair(): void
    {
        $post_id = $this->healthyPage('Perfectly fine');
        unset($GLOBALS['wpdb']);

        $batch = pp_ai_execute_batch([$this->editStep($post_id)]);

        $this->assertStringContainsString('could not be VERIFIED', $batch['error']);
        $this->assertStringNotContainsString('treat as corrupted', $batch['error']);
        $this->assertStringNotContainsString('update_composition', $batch['error']);
        $this->assertStringNotContainsString('composition-history', $batch['error']);
    }

    /**
     * A FAILED READ IS NOT A BLANK PAGE, and this is the reachable half of fail-closed
     * (#833, #212 posture). $wpdb->get_var() answers null for "no row" and for a query that
     * ERRORED, and the authoritative reader maps null to '' — a genuinely blank, perfectly
     * readable page. Trusted at a gate that would be a fail-OPEN under exactly the
     * contention that produces the race: the row read comes back "healthy", the refusal is
     * skipped, and the rollback baseline is the stale cached copy. Unlike the missing-handle
     * branch, this one is reachable in production.
     */
    public function testAFailedAuthoritativeReadBlocksInsteadOfReadingAsABlankPage(): void
    {
        $post_id = $this->healthyPage('Row read will fail');
        $GLOBALS['wpdb'] = new PP_FailingRead_Wpdb();

        $this->assertTrue(
            pp_get_composition_result_authoritative($post_id)['ok'],
            'premise: the reader itself still calls a failed read a blank, readable page'
        );

        $this->assertSame(
            PP_BATCH_TARGET_UNVERIFIABLE,
            _pp_batch_target_refusal_reason($post_id, pp_get_composition_result($post_id)),
            'but the GATE blocks: an answer it cannot trust is not an answer'
        );

        $batch = pp_ai_execute_batch([$this->editStep($post_id)]);
        $this->assertFalse($batch['ok']);
        $this->assertSame(PP_BATCH_TARGET_UNVERIFIABLE, $batch['error_code']);
        $this->assertSame([], $batch['steps'], 'no step ran');
    }

    /**
     * A BATCH THAT NAMES NO PAGE NEEDS NO DATABASE. The gate classifies TARGETS; with none,
     * there is nothing to fail closed about, and refusing anyway would turn a read-capability
     * question into a blanket outage for work that never touches a composition.
     */
    public function testWithNoDatabaseHandleABatchNamingNoPageStillRuns(): void
    {
        unset($GLOBALS['wpdb']);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_page', 'params' => ['title' => 'Brand new', 'status' => 'draft']],
        ]);

        $this->assertTrue($batch['ok'], 'nothing was named, so nothing needed verifying');
        $this->assertArrayNotHasKey('error_code', $batch);
    }

    /**
     * THE ROLLBACK FAILS CLOSED TOO. Reached only by a caller that snapshots with a handle
     * and rolls back without one, which is not a shape the executor produces — pinned
     * anyway, because the alternative to withholding is writing a captured composition over
     * a stored state nobody could read.
     */
    public function testWithNoDatabaseHandleTheRollbackWithholdsTheCompositionWrite(): void
    {
        $post_id  = $this->healthyPage('Snapshotted, then blind');
        $snapshot = _pp_snapshot_batch_targets([$this->editStep($post_id)]);
        $this->assertSame([], $snapshot['unreadable'], 'premise');
        unset($GLOBALS['wpdb']);

        $errors = _pp_restore_batch_snapshot($snapshot);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('could not be VERIFIED', $errors[0]);
        $this->assertStringContainsString('NOT rolled back', $errors[0]);
    }

    // ═══ WHAT DID NOT MOVE ═══════════════════════════════════════════════════

    /**
     * THE OTHER DIVERGENCE DIRECTION STILL REFUSES, with the code it always used. #756
     * pinned this as a fail-closed refusal and #833 did not ask for it back: the cached
     * check survives as an added requirement, never as an alternative source. It is also
     * load-bearing — the snapshotter captures from the cached read, so a healthy verdict
     * over a corrupt cache would mean capturing `[]` under a map that says fine.
     */
    public function testACorruptCacheOverAHealthyRowStillRefusesWithTheCachedCode(): void
    {
        $post_id = $this->healthyPage('Row already repaired');
        update_post_meta($post_id, '_pp_composition', self::CORRUPT_BYTES);
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition'] =
            json_encode([['component' => 'hero', 'props' => ['id' => 'fixed', 'title' => 'Repaired']]]);

        $this->assertFalse(pp_get_composition_result($post_id)['ok'], 'premise: the cache says corrupt');
        $this->assertTrue(pp_get_composition_result_authoritative($post_id)['ok'], 'premise: the row says healthy');

        $batch = pp_ai_execute_batch([$this->editStep($post_id), $this->publishStep($post_id)]);

        $this->assertFalse($batch['ok']);
        $this->assertSame('decode_error', $batch['error_code']);
    }

    /**
     * A HEALTHY PAGE BATCHES EXACTLY AS BEFORE. The common path is the one that must not
     * have moved: no refusal keys on the envelope, every step ran, the write landed.
     */
    public function testAHealthyPageBatchCarriesNoRefusalAtAll(): void
    {
        $post_id = $this->healthyPage('Nothing wrong here');

        $batch = pp_ai_execute_batch(
            [$this->editStep($post_id, 'Rewritten'), $this->publishStep($post_id)],
            [$post_id => $this->version($post_id)]
        );

        $this->assertTrue($batch['ok']);
        $this->assertArrayNotHasKey('error', $batch);
        $this->assertArrayNotHasKey('error_code', $batch);
        $this->assertCount(2, $batch['steps']);
        $this->assertSame('publish', get_post($post_id)->post_status);
        $this->assertSame(
            [['component' => 'hero', 'props' => ['id' => 'edited', 'title' => 'Rewritten']]],
            pp_get_composition($post_id)
        );
    }

    /**
     * THE COST, MEASURED. One indexed point-SELECT per distinct named page per gate
     * evaluation, and the number is pinned so a future change that starts classifying
     * per-STEP (or re-reading inside the loop) shows up as a failing test rather than as a
     * slow site. Two steps on ONE page here, and the executor evaluates the gate once.
     */
    public function testTheGateCostsOneCompositionSelectPerPagePerEvaluation(): void
    {
        $post_id = $this->healthyPage('Cost');
        $steps   = [$this->editStep($post_id), $this->publishStep($post_id)];

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->queries = [];
        $unreadable = _pp_batch_unreadable_targets($steps);

        $this->assertSame([], $unreadable);
        $this->assertSame(
            1,
            $wpdb->compositionReads($post_id),
            'one row read for the page, not one per step'
        );

        $wpdb->queries = [];
        _pp_snapshot_batch_targets($steps);
        $this->assertSame(
            1,
            $wpdb->compositionReads($post_id),
            'and one more when the executor evaluates its own copy of the gate'
        );
    }

    /**
     * A CACHED ENVELOPE THIS GATE CANNOT READ IS A STATE IT CANNOT CLASSIFY, so it blocks.
     * Unreachable through the three callers, which all pass pp_get_composition_result()'s
     * own return — pinned because the default on the other side of this branch is "proceed",
     * and a `false` ok beside a missing `error` used to fall out of the bottom as null.
     */
    public function testAnUnreadableCachedEnvelopeWithNoCodeStillBlocks(): void
    {
        $post_id = $this->healthyPage('Malformed envelope');

        $this->assertSame(
            PP_BATCH_TARGET_UNVERIFIABLE,
            _pp_batch_target_refusal_reason($post_id, ['ok' => false]),
            'no classification to report is not permission to proceed'
        );
        $this->assertNull(
            _pp_batch_target_refusal_reason($post_id, pp_get_composition_result($post_id)),
            'and a well-formed healthy envelope still passes'
        );
    }

    /**
     * THE ROW READ IS SKIPPED WHEN THE CACHE ALREADY REFUSES, which is why the added cost
     * lands on the healthy path only and never doubles a refusal.
     *
     * BOTH PAGES IN ONE BATCH, deliberately: "the corrupt page costs no row read" is also
     * true of an implementation that never reads the row at all, so on its own that
     * assertion pins nothing. Asserting the healthy page in the SAME batch does pay for one
     * is what makes this a statement about ORDER rather than about absence.
     */
    public function testACorruptCachedReadCostsNoRowReadAtAll(): void
    {
        $corrupt_id = $this->healthyPage('Already refusing');
        update_post_meta($corrupt_id, '_pp_composition', self::CORRUPT_BYTES);
        $healthy_id = $this->healthyPage('Fine, and in the same batch');

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->queries = [];
        $unreadable = _pp_batch_unreadable_targets([
            $this->editStep($corrupt_id),
            $this->editStep($healthy_id),
        ]);

        $this->assertSame([$corrupt_id => 'decode_error'], $unreadable);
        $this->assertSame(0, $wpdb->compositionReads($corrupt_id), 'the cached verdict is checked first');
        $this->assertSame(1, $wpdb->compositionReads($healthy_id), 'and the healthy page still pays for its row read');
    }
}
