<?php
/**
 * tests/CompositionRestoreSelectorLockTest.php — a concurrent write can no longer change
 * WHICH snapshot a restore replays (#829).
 *
 * THE BUG THIS PINS, and it is a SELECTION bug rather than a durability one. #823 fixed the
 * ring the WRITER rebuilds from. This is the other ring read on the same path: the one that
 * decides which entry the operator's selector MEANS. `restore_composition`'s execute
 * (lib/actions.php) resolved it against the CACHED reader and only then called
 * pp_update_composition(), which is where the per-post advisory lock opens:
 *
 *     $history = pp_get_composition_history($post_id);      // CACHE, outside the lock
 *     $idx     = _pp_resolve_history_target($history, $params);
 *     $target  = pp_normalize_composition($history[$idx]['composition']);
 *     $result  = pp_update_composition($post_id, $target, ...);   // ← lock opens HERE
 *
 * `steps_back` counts backwards from the end of the ring and `history_index` indexes into it,
 * so a concurrent write landing in that window moves what the selector names:
 *
 *     request B                     request A         `_pp_composition_history` row
 *     ─────────────────────         ─────────────     ─────────────────────────────
 *     lists the ring, picks
 *       steps_back=1 (= e2)                           [ e1, e2 ]     B's cache: [ e1, e2 ]
 *     resolves against its
 *       warm cached copy
 *                                   pushes e3         [ e1, e2, e3 ] B's cache: still [e1,e2]
 *     writes e2 back, ok: true                        ← steps_back=1 now means e3
 *
 * Neither reading of that outcome is defensible: e2 is not what `steps_back=1` means any
 * more, and e3 is not what the operator chose. The operator got a page they did not select,
 * reported as success, from the one verb the product sanctions for repair. Not data loss —
 * the restore is itself a write, so it pushes what it replaces — but a WRONG REPLAY behind
 * an `ok: true`.
 *
 * WHY RE-RESOLVING INSIDE THE LOCK IS NOT, BY ITSELF, THE FIX, since that is the shape a
 * reader will expect and it is wrong: the selector is RELATIVE. Re-resolving `steps_back=1`
 * against the moved ring hands back e3 — literally "whichever snapshot the ring held after a
 * concurrent write". Both halves are needed, and the tests below pin both: resolve
 * AUTHORITATIVELY inside the lock, and require that resolution to name the entry the operator
 * addressed. Agreement writes. Disagreement refuses with `history_target_shifted` and writes
 * nothing.
 *
 * HOW THE INTERLEAVING IS MODELLED, and the honest limits of it. tests/bootstrap.php stubs
 * update_postmeta_cache() as a no-op, so every other test in this repo runs as if the meta
 * cache did not exist and CANNOT fail on a stale-cache bug. What the harness DOES model
 * (#767/#823) is a real postmeta table with a per-key divergence:
 *
 *     $GLOBALS['_pp_test_store']['wpdb_postmeta'][$id][$key]   the DATABASE ROW
 *     $GLOBALS['_pp_test_store']['post_meta'][$id][$key]       the (possibly stale) CACHE
 *
 * These are cache-divergence SIMULATIONS, not concurrency tests: one process, hand-staged
 * state, no real lock contention. The choreography below (run A's write for real, snapshot
 * the row it committed, restore B's pre-A cached copy) exists so the staged state is one a
 * real pair of requests actually produces. PP_HistoryRing_Lockable_Wpdb comes from
 * CompositionHistoryLockedReadTest — deliberately the SAME stub rather than a second one, so
 * the two issues on this path cannot drift on what a database is.
 *
 * WHICH CALLER SHAPE ACTUALLY REACHES THIS GATE, stated because the tests below would
 * otherwise leave it to be inferred. The CAS answers first, so a caller threading a baseline it
 * read BEFORE the concurrent write is intercepted by `composition_conflict` and never reaches
 * the confirmation (pinned below). What reaches it is the caller with a LIVE baseline read
 * after that write — the #767 corrupt-repair carve-out, and the chat batch, which reads the
 * version at proposal time — and the caller with no baseline at all. Both are modelled here.
 *
 * MIND THE FROZEN ROW (#831). The stub's update_post_meta() writes only the CACHE bucket;
 * nothing ever writes the ROW bucket, so a staged row stays frozen at its pre-write value.
 * Stage the divergence, perform ONE write, then clear the staged key — settle() below.
 *
 * Coverage:
 *   the measured defect, end to end through the REAL action (Section 14.1), for a
 *     `steps_back` selector whose meaning moved — and the same for `history_index` past an
 *     eviction
 *   the ring shifted so far the selector no longer resolves at all
 *   PRECISION: a concurrent push that does NOT move what the selector names still restores,
 *     so the refusal is on a genuine shift and not on contention
 *   uncontended restores are byte-identical: same stored bytes, same version step, same ring
 *     growth, same envelope shape
 *   nothing is written on a refusal — composition, both markers and the ring all untouched
 *   the confirmation really is issued inside the lock, before any write
 *   the CAS still answers first, so an existing contract is not reordered
 *   the refusal reaches the chat batch executor and the chat AI's action catalog (#719)
 *   the degradations, which are where this fix stops: no usable handle, and a FAILED
 *     authoritative read, both fall back to the cached ring and restore as before
 *   the writer-level precondition contract on pp_update_composition() itself
 */

use PHPUnit\Framework\TestCase;

// PP_HistoryRing_Lockable_Wpdb is defined there, and reusing it rather than declaring a second
// stub is deliberate (see the header). REQUIRED EXPLICITLY, because a testsuite run only
// happens to load that file first — `phpunit tests/CompositionRestoreSelectorLockTest.php`
// alone would fatal on the missing class, and a rename on either side would break the suite
// the moment the load order stopped being alphabetically lucky.
require_once __DIR__ . '/CompositionHistoryLockedReadTest.php';

class CompositionRestoreSelectorLockTest extends TestCase
{
    /** Undecodable bytes — the #818 preserved-entry trigger. */
    private const CORRUPT_BYTES = '{"component":';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'     => [],
            'posts'         => [],
            'options'       => [],
            'connectors'    => [],
            'next_id'       => 900,
            'custom_css'    => '',
            'wpdb_postmeta' => [],
        ];
        // The confirmation under test is authoritative BY DESIGN, so these tests must
        // exercise the database branch rather than quietly proving the no-handle fallback.
        $GLOBALS['wpdb'] = new PP_HistoryRing_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['_pp_test_store']['wpdb_postmeta']);
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        $GLOBALS['_pp_test_store']['posts']     = [];
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function firstBands(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'first', 'title' => 'First']]];
    }

    private function secondBands(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'second', 'title' => 'Second']]];
    }

    private function concurrentBands(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'concurrent', 'title' => 'Concurrent']]];
    }

    /**
     * A page with two prior states on the ring, so a selector has somewhere to move TO.
     * (A first write on a fresh page pushes nothing — it has no prior state.)
     */
    private function pageWithTwoPriorStates(): int
    {
        $post_id = pp_create_page('Concurrently restored page', 'draft');
        pp_update_composition($post_id, $this->firstBands());   // pushes nothing
        pp_update_composition($post_id, $this->secondBands());  // pushes first
        pp_update_composition($post_id, $this->concurrentBands()); // pushes second
        return $post_id;
    }

    /** The stored ring exactly as it sits in the store right now. */
    private function storedRing(int $post_id)
    {
        return $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition_history'] ?? null;
    }

    /**
     * Stage the interleaving: `$committed` is what the concurrent writer left in the ROW,
     * `$stale` is the copy this request warmed before that writer landed.
     */
    private function stageDivergence(int $post_id, $committed, $stale): void
    {
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_history'] = $committed;
        $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition_history']     = $stale;
    }

    /** Mandatory after every write that follows a stageDivergence() — see the header (#831). */
    private function settle(int $post_id): void
    {
        unset($GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_history']);
    }

    /** The component ids of the page's current composition, for readable assertions. */
    private function bandIds(int $post_id): array
    {
        return array_column(array_column(pp_get_composition($post_id), 'props'), 'id');
    }

    /**
     * The full stored state a refusal must leave untouched: composition bytes, both freshness
     * markers, and the ring row.
     */
    private function storedState(int $post_id): array
    {
        return [
            'composition' => $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'] ?? null,
            'version'     => $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition_version'] ?? null,
            'hash'        => $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition_hash'] ?? null,
            'ring'        => $this->storedRing($post_id),
        ];
    }

    /**
     * The choreography, once, because six tests below need exactly it: B warms its copy of
     * the ring, A commits a write, and the divergence is staged from what actually happened
     * rather than from an invented pair of values.
     *
     * Returns the ring A COMMITTED, because that is the only copy an assertion may reason
     * about afterwards: settle() clears the staged row, so the cached reader then answers
     * from B's stale copy again (the refusal wrote nothing that would have replaced it).
     *
     * @return array  The authoritative ring A left in the row, normalized.
     */
    private function stageConcurrentPush(int $post_id, array $a_writes): array
    {
        $b_warmed_ring = $this->storedRing($post_id);
        $this->assertNotNull($b_warmed_ring, 'premise: there is a ring to be stale about');

        // Request A holds the lock and runs to completion.
        $this->assertTrue(pp_update_composition($post_id, $a_writes));
        $a_committed_ring = $this->storedRing($post_id);

        $this->stageDivergence($post_id, $a_committed_ring, $b_warmed_ring);
        $this->assertNotSame(
            $b_warmed_ring,
            $a_committed_ring,
            'premise: the row and the cache genuinely disagree'
        );

        return _pp_normalize_history_ring($a_committed_ring);
    }

    // ── 1. The measured defect ───────────────────────────────────────────────

    /**
     * THE MEASURED DEFECT, through the REAL action surface (Section 14.1) — which is what an
     * operator and the chat's undo link actually run.
     *
     * On the unfixed resolver this returns `ok: true` having replayed the entry B's cached
     * ring held at `steps_back=1`, while `steps_back=1` against the ring that EXISTS names
     * the state A just pushed. Two different snapshots, one reported success. The fix refuses.
     */
    public function testAStepsBackSelectorWhoseMeaningMovedIsRefusedRatherThanReplayed(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        $before  = $this->bandIds($post_id);

        $this->stageConcurrentPush($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'a-wrote-this', 'title' => 'A']],
        ]);
        $state_before = $this->storedState($post_id);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->settle($post_id);

        $this->assertFalse($result['ok'], 'a selector that no longer names the addressed entry must not report success');
        $this->assertSame('history_target_shifted', $result['error_code']);
        $this->assertStringContainsString(
            'composition-history',
            $result['error'],
            'the refusal must name the route back to a current listing'
        );

        $this->assertSame($state_before, $this->storedState($post_id), 'a refused restore writes NOTHING');
        $this->assertNotSame($before, $this->bandIds($post_id), 'sanity: A did change the page');
        $this->assertSame(['a-wrote-this'], $this->bandIds($post_id), 'the page is exactly as A left it');
    }

    /**
     * THE SAME DEFECT REACHED BY THE ABSOLUTE SELECTOR, which needs the ring to be FULL to
     * move: the rebuild appends and then array_slice(-max)s, so while the ring has room every
     * existing `history_index` keeps naming the same entry, and once it is full every index
     * shifts down by one on the next push. That eviction is the shift here.
     */
    public function testAHistoryIndexMovedByAnEvictionIsRefused(): void
    {
        $post_id = pp_create_page('Full ring page', 'draft');
        $max     = pp_composition_history_max();

        // Fill the ring: max+1 writes leave max entries (the first write pushes nothing).
        for ($i = 0; $i <= $max; $i++) {
            pp_update_composition($post_id, [
                ['component' => 'hero', 'props' => ['id' => 'state-' . $i]],
            ]);
        }
        $ring = pp_get_composition_history($post_id);
        $this->assertCount($max, $ring, 'precondition: the ring is full, so the next push evicts');
        $addressed = $ring[0]['composition'][0]['props']['id'];

        $authoritative = $this->stageConcurrentPush($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'a-wrote-this']],
        ]);

        // The entry the operator addressed is the one A's push evicted, so replaying index 0
        // would have restored a state one write older than they asked for. Asserted against
        // the ring A COMMITTED — after settle() the cached reader answers from B's stale copy
        // again, which still contains it and would make this premise pass for the wrong reason.
        $this->assertNotContains(
            $addressed,
            array_map(
                static fn (array $e): string => $e['composition'][0]['props']['id'],
                $authoritative
            ),
            'premise: the addressed entry really did leave the ring'
        );

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);
        $this->settle($post_id);

        $this->assertFalse($result['ok']);
        $this->assertSame('history_target_shifted', $result['error_code']);
        $this->assertStringContainsString(
            'names a different entry',
            $result['error'],
            'the message must say the selector moved, not invent another cause'
        );
    }

    /**
     * THE PRECISION PIN, and the reason the fix is a comparison rather than a refusal on any
     * contention. While the ring has room, a concurrent push does NOT move what an absolute
     * `history_index` names — the entry at index 0 is still the entry at index 0. Refusing
     * there would turn every busy page into a page that cannot be rolled back.
     */
    public function testAConcurrentPushThatDoesNotMoveTheSelectorStillRestores(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        $ring    = pp_get_composition_history($post_id);
        $this->assertLessThan(
            pp_composition_history_max(),
            count($ring) + 1,
            'premise: the ring has room, so a push appends without evicting'
        );
        $addressed = $ring[0]['composition'];

        $this->stageConcurrentPush($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'a-wrote-this']],
        ]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);
        $this->settle($post_id);

        $this->assertTrue($result['ok'], 'index 0 still names the entry it named; there is nothing to refuse');
        $this->assertSame(
            array_column(array_column($addressed, 'props'), 'id'),
            $this->bandIds($post_id),
            'and the snapshot replayed is the one that was addressed'
        );
    }

    /**
     * THE RING MOVED SO FAR THE SELECTOR NO LONGER RESOLVES AT ALL. The in-lock re-resolution
     * fails outright (`no_history` / `history_out_of_bounds` against the authoritative ring)
     * rather than naming a different entry — still a shift, still one code, with the
     * authoritative reason carried in the message. Reporting `no_history` here would be a lie:
     * the operator's own ring listing had entries in it.
     */
    public function testARingEmptiedUnderTheRequestIsReportedAsAShiftWithTheAuthoritativeReason(): void
    {
        $post_id       = $this->pageWithTwoPriorStates();
        $b_warmed_ring = $this->storedRing($post_id);

        // The ROW says: no ring at all (a rollback, a repair, a direct meta delete).
        $this->stageDivergence($post_id, '[]', $b_warmed_ring);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->settle($post_id);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'history_target_shifted',
            $result['error_code'],
            'the operator did not send a selector against an empty ring; the ring emptied under them'
        );
        $this->assertStringContainsString(
            'no longer resolves at all',
            $result['error'],
            'and the authoritative reason must survive into the message'
        );
        $this->assertStringContainsString(
            'no_history',
            $result['error'],
            'as the authoritative CODE — the sibling refusal\'s own prose would carry a second, wrong prescription'
        );
    }

    /**
     * THE PRESERVED-BYTES SLOT, which is where the one-code decision earns itself. Against the
     * authoritative ring `steps_back=1` names the #818 entry A minted when it repaired the
     * page. Returning `history_entry_not_restorable` would tell the operator they selected a
     * preserved-bytes slot — they did not; they selected a composition and the ring moved. The
     * read path and the write path have to name the same state the same way (#650/#652/#725).
     */
    public function testAShiftOntoAPreservedBytesSlotIsNamedAsAShiftNotAsABadSelection(): void
    {
        $post_id       = $this->pageWithTwoPriorStates();
        $b_warmed_ring = $this->storedRing($post_id);

        // A finds the page corrupt and performs the documented repair, minting a raw entry.
        update_post_meta($post_id, '_pp_composition', self::CORRUPT_BYTES);
        $this->assertSame('decode_error', pp_get_composition_result($post_id)['error']);
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'repaired']],
        ]));
        $a_committed_ring = $this->storedRing($post_id);
        $this->stageDivergence($post_id, $a_committed_ring, $b_warmed_ring);

        // The premise, against the ring A COMMITTED: steps_back=1 authoritatively names the
        // preserved-bytes slot. (After settle() the cached reader answers from B's stale copy,
        // which has no raw entry at all.)
        $authoritative = _pp_normalize_history_ring($a_committed_ring);
        $this->assertTrue(
            pp_history_entry_is_raw($authoritative[count($authoritative) - 1]),
            'premise: the newest authoritative slot is the preserved-bytes entry'
        );
        $this->assertSame(self::CORRUPT_BYTES, $authoritative[count($authoritative) - 1]['raw']);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->settle($post_id);

        $this->assertFalse($result['ok']);
        // ASSERTED ON THE CODE, NOT THE PROSE. The #818 message is deliberately cause-neutral
        // and never contains its own code string, so a "message does not mention
        // history_entry_not_restorable" assertion would pass even if the raw #818 WP_Error were
        // returned verbatim — vacuous, and vacuous in exactly the direction it claims to guard.
        $this->assertNotSame(
            'history_entry_not_restorable',
            $result['error_code'],
            'the operator selected a composition; naming the other refusal would describe a state they are not in'
        );
        $this->assertSame('history_target_shifted', $result['error_code']);
        // The #818 reason was WRAPPED, not passed through: the shift message owns the
        // instruction, and carries only the sibling's CODE as the authoritative reason.
        $this->assertStringContainsString('[history_target_shifted]', $result['error']);
        $this->assertStringContainsString('history_entry_not_restorable', $result['error'], 'the authoritative reason still travels');
        $this->assertStringNotContainsString(
            'select an earlier entry',
            $result['error'],
            'the sibling\'s own prescription must not ride along — the ring moved, it was not mis-selected'
        );
    }

    // ── 2. The uncontended path must not move ────────────────────────────────

    /**
     * THE NO-BEHAVIOR-CHANGE PIN the ruling asks for, stated as bytes rather than as a
     * feeling. With a real database handle and NO divergence, a restore writes exactly what
     * it wrote before #829: the addressed snapshot's own bytes, one version step, one ring
     * entry, and the same envelope shape.
     */
    public function testAnUncontendedRestoreIsByteIdenticalAndStepsTheMarkersExactlyOnce(): void
    {
        $post_id = $this->pageWithTwoPriorStates();

        $ring      = pp_get_composition_history($post_id);
        $addressed = $ring[count($ring) - 1]['composition'];
        $version   = pp_get_composition_marker($post_id)['version'];

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], 'an uncontended restore is untouched by this change');
        $this->assertSame(
            wp_json_encode($addressed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            wp_json_encode(pp_get_composition($post_id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'byte for byte the snapshot that was addressed'
        );
        $this->assertSame($version + 1, pp_get_composition_marker($post_id)['version'], 'exactly one version step');
        $this->assertCount(count($ring) + 1, pp_get_composition_history($post_id), 'exactly one ring push');

        // The envelope shape is part of "no behavior change" — a caller keying on it must not
        // have to learn a new one.
        $this->assertArrayHasKey('findings', $result, 'restore still owns its own #233 report');
        $this->assertArrayHasKey('changes', $result);
        $this->assertSame('restore_composition', $result['action']);
    }

    /**
     * The same guarantee for the OTHER selector, because the two resolve through different
     * branches of _pp_resolve_history_target() and only one of them was exercised above.
     */
    public function testAnUncontendedHistoryIndexRestoreIsUnchangedToo(): void
    {
        $post_id   = $this->pageWithTwoPriorStates();
        $addressed = pp_get_composition_history($post_id)[0]['composition'];

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array_column(array_column($addressed, 'props'), 'id'),
            $this->bandIds($post_id)
        );
    }

    // ── 3. Where the confirmation runs ───────────────────────────────────────

    /**
     * THE CONFIRMATION IS ONLY WORTH ANYTHING INSIDE THE LOCK, so pin that it is — a read
     * hoisted before GET_LOCK or after RELEASE_LOCK would be a statement about a moment that
     * has already passed, which is the bug. The stub records every query in order, and
     * RELEASE_LOCK goes through query() rather than get_var(), so the critical section has a
     * closing boundary in the log and not just an opening one.
     *
     * NAMED FOR WHAT IT ACTUALLY PROVES: the meta WRITES go through the store, not through
     * $wpdb, so the query log cannot order this read against them. "Before anything is written"
     * is pinned by the no-write-on-refusal assertions elsewhere in this file, not here.
     */
    public function testTheAuthoritativeRingIsReadInsideTheLock(): void
    {
        $post_id = $this->pageWithTwoPriorStates();

        $GLOBALS['wpdb']->queries = [];
        $this->assertTrue(pp_execute_action('restore_composition', [
            'post_id' => $post_id, 'steps_back' => 1,
        ])['ok']);

        $queries = $GLOBALS['wpdb']->queries;
        $get     = null;
        $release = null;
        $ring    = [];
        foreach ($queries as $i => $q) {
            if ($get === null && str_contains($q, 'GET_LOCK')) {
                $get = $i;
            }
            if (str_contains($q, 'RELEASE_LOCK')) {
                $release = $i;
            }
            if (str_contains($q, "'_pp_composition_history'")) {
                $ring[] = $i;
            }
        }

        $this->assertNotNull($get, 'premise: the lock was taken');
        $this->assertNotNull($release, 'premise: and released');
        $this->assertNotEmpty($ring, 'the authoritative ring read must actually be issued');
        foreach ($ring as $at) {
            $this->assertGreaterThan($get, $at, 'the ring read must be inside the lock');
            $this->assertLessThan($release, $at, 'and must not be hoisted past the release');
        }
        // EXACTLY ONE, and that is the point. The confirmation (#829) and the ring rebuild
        // (#823) both need the authoritative ring, and nothing between them can change it
        // while this lock is held — so pp_update_composition() reads it once and shares it.
        // Two reads here would mean the row is decoded twice inside the critical section,
        // which at the composition size ceiling is real time every concurrent writer waits for.
        $this->assertCount(1, $ring, 'one authoritative ring read per lock hold, shared by both consumers');
    }

    /**
     * THE CAS STILL ANSWERS FIRST. A stale `expected_version` means "the page moved under
     * you", which subsumes a moved selector and is the error the caller already knows how to
     * retry. Reporting the narrower cause for the wider failure would be a regression in an
     * existing contract (#13), so the ordering is pinned rather than assumed.
     */
    public function testAStaleExpectedVersionStillReportsTheConflictRatherThanTheShift(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        $stale   = pp_get_composition_marker($post_id)['version'] - 1;

        $this->stageConcurrentPush($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'a-wrote-this']],
        ]);

        $result = pp_execute_action('restore_composition', [
            'post_id'          => $post_id,
            'steps_back'       => 1,
            'expected_version' => $stale,
        ]);
        $this->settle($post_id);

        $this->assertFalse($result['ok']);
        $this->assertSame('composition_conflict', $result['error_code'], 'the CAS is checked first and still wins');
    }

    // ── 4. The surfaces that act on the refusal ──────────────────────────────

    /**
     * THE CHANNEL THE CHAT'S "UNDO THESE CHANGES" LINK USES. It runs the same action through
     * pp_ai_execute_batch(), and undo-after-someone-else-edited is the single most likely way
     * to meet a shifted ring in practice.
     */
    public function testTheChatBatchExecutorGetsTheRefusalAsARefusedStep(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        $this->stageConcurrentPush($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'a-wrote-this']],
        ]);
        $version = pp_get_composition_marker($post_id)['version'];

        $batch = pp_ai_execute_batch(
            [['type' => 'action', 'name' => 'restore_composition', 'params' => [
                'post_id' => $post_id, 'steps_back' => 1,
            ]]],
            [$post_id => $version]
        );
        $this->settle($post_id);

        $this->assertFalse($batch['ok'], 'the batch reports a refused step');
        $this->assertSame('history_target_shifted', $batch['steps'][0]['error_code']);
        $this->assertSame(0, $batch['failed_at'], 'and stops on it like any refused step');
    }

    /**
     * THE #719 RULE. pp_ai_system_prompt() builds the chat AI's action catalog from each
     * action's `description`; NOTHING at runtime reads `semantics`. A refusal the one caller
     * most likely to hit it has never been taught is a refusal that reads as a bug.
     */
    public function testTheShiftRefusalReachesTheChatAIsActionCatalog(): void
    {
        $prompt = pp_ai_system_prompt();

        $this->assertStringContainsString('restore_composition', $prompt, 'precondition: the action is catalogued');
        $this->assertStringContainsString(
            'history_target_shifted',
            $prompt,
            'the code a caller keys on must be teachable'
        );
    }

    // ── 5. Where this fix stops, stated as tests ─────────────────────────────

    /**
     * NO USABLE DATABASE HANDLE — the documented unit-context path. The authoritative reader
     * falls back to the cached ring, which is the array the caller already resolved, so the
     * confirmation agrees by construction and the restore behaves exactly as it did before
     * #829. Pinned because the alternative (treating an unanswerable question as a shift)
     * would make every restore in a handle-less context fail.
     */
    public function testWithNoUsableDatabaseHandleTheRestoreProceedsAsBefore(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        $ring    = pp_get_composition_history($post_id);

        unset($GLOBALS['wpdb']);
        $this->assertNull(pp_composition_db_handle(), 'premise: there is no handle to ask');

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            array_column(array_column($ring[count($ring) - 1]['composition'], 'props'), 'id'),
            $this->bandIds($post_id)
        );
    }

    /**
     * A FAILED AUTHORITATIVE READ MUST NOT MANUFACTURE A REFUSAL. $wpdb->get_var() returns
     * null for both "no row" and a failed query (#212); the reader disambiguates and falls
     * back to the cache rather than reading a database blip as an empty ring — which on the
     * REBUILD path would wipe up to ten slots (#823). Here the consequence is milder and must
     * still be right: a transient failure degrades to the pre-#829 behavior, it does not turn
     * every restore on the site into `history_target_shifted`.
     */
    public function testAFailedAuthoritativeReadDegradesInsteadOfRefusing(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        $ring    = pp_get_composition_history($post_id);

        $GLOBALS['wpdb']->fail_key = '_pp_composition_history';

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], 'a database blip must not start refusing restores');
        $this->assertSame(
            array_column(array_column($ring[count($ring) - 1]['composition'], 'props'), 'id'),
            $this->bandIds($post_id)
        );
    }

    /**
     * THE ROW IS PRESENT BUT NOT A RING — the third degradation exit of the authoritative
     * reader, and the sibling of the failed-read test above. It routes through
     * _pp_degraded_history_ring() too, so it must degrade to the cached ring rather than be
     * read as "the ring is empty" (which would refuse every restore on the page).
     */
    public function testAnUndecodableRingRowDegradesInsteadOfRefusing(): void
    {
        $post_id       = $this->pageWithTwoPriorStates();
        $ring          = pp_get_composition_history($post_id);
        $b_warmed_ring = $this->storedRing($post_id);

        $this->stageDivergence($post_id, '[{"timestamp":1,"vers', $b_warmed_ring);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->settle($post_id);

        $this->assertTrue($result['ok'], 'a damaged ring row must not start refusing restores');
        $this->assertSame(
            array_column(array_column($ring[count($ring) - 1]['composition'], 'props'), 'id'),
            $this->bandIds($post_id)
        );
    }

    /**
     * THE AUTHORITATIVE RING SHRANK PAST THE SELECTOR — the `history_out_of_bounds` arm of the
     * in-lock re-resolution, which the other refusal tests never reach. Still one code, with
     * the authoritative reason carried in the message.
     */
    public function testARingThatShrankPastTheSelectorIsReportedAsAShift(): void
    {
        $post_id       = $this->pageWithTwoPriorStates();
        $b_warmed_ring = $this->storedRing($post_id);
        $full          = _pp_normalize_history_ring($b_warmed_ring);
        $this->assertGreaterThanOrEqual(2, count($full), 'premise: the cache holds at least two entries');

        // The ROW holds only the oldest entry, so steps_back=2 is in range for the cache and
        // out of range for the ring that actually exists.
        $short = wp_json_encode(_pp_history_entries_for_storage([$full[0]]));
        $this->stageDivergence($post_id, $short, $b_warmed_ring);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 2]);
        $this->settle($post_id);

        $this->assertFalse($result['ok']);
        $this->assertSame('history_target_shifted', $result['error_code']);
        $this->assertStringContainsString('history_out_of_bounds', $result['error'], 'the authoritative reason travels');
    }

    /**
     * THE COMPARISON IS WHOLE-ENTRY, NOT PAYLOAD-ONLY. Writing the same composition twice in a
     * row puts two ring entries on the page whose `composition` arrays are IDENTICAL and whose
     * `version` differs — the only shape that can tell a whole-entry `===` from a compare of
     * `['composition']` alone. Without this, a payload-only comparison passes the whole suite
     * while quietly accepting a selector that moved between two same-looking snapshots.
     */
    public function testTheComparisonDistinguishesTwoEntriesWithIdenticalCompositions(): void
    {
        $post_id = pp_create_page('Repeated states', 'draft');
        $same    = [['component' => 'hero', 'props' => ['id' => 'same-bands']]];
        pp_update_composition($post_id, $same);
        pp_update_composition($post_id, $same);   // pushes entry A
        pp_update_composition($post_id, $same);   // pushes entry B, same composition, later version

        $ring = pp_get_composition_history($post_id);
        $this->assertSame(
            $ring[count($ring) - 1]['composition'],
            $ring[count($ring) - 2]['composition'],
            'premise: two entries carry identical composition payloads'
        );
        $this->assertNotSame(
            $ring[count($ring) - 1]['version'],
            $ring[count($ring) - 2]['version'],
            'premise: and are told apart only by the monotonic version'
        );

        $this->stageConcurrentPush($post_id, [['component' => 'hero', 'props' => ['id' => 'a-wrote-this']]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->settle($post_id);

        $this->assertFalse($result['ok'], 'the selector moved onto a different entry, however similar it looks');
        $this->assertSame('history_target_shifted', $result['error_code']);
    }

    /**
     * VALIDATE AND PREVIEW STAY LOCK-FREE, which is a stated design decision and therefore
     * needs a pin: a refactor that supplied the confirmation from preview would serialize every
     * preview of a hot page against its writers, and would refuse previews on a shifted ring —
     * and would pass every other test in this file. Both halves are asserted: the preview
     * SUCCEEDS on a shifted ring, and it takes no lock to do it.
     */
    public function testPreviewNeitherLocksNorRefusesOnAShiftedRing(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        $this->stageConcurrentPush($post_id, [['component' => 'hero', 'props' => ['id' => 'a-wrote-this']]]);

        $GLOBALS['wpdb']->queries = [];
        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->settle($post_id);

        $this->assertFalse(is_wp_error($preview), 'preview reports what it resolved; it does not police the ring');
        $this->assertTrue($preview['ok'] ?? true);
        foreach ($GLOBALS['wpdb']->queries as $q) {
            $this->assertStringNotContainsString('GET_LOCK', $q, 'a read-only stage must not take the write lock');
        }
    }

    // ── 6. The writer-level contract ─────────────────────────────────────────

    /**
     * THE SEAM ITSELF, tested where it lives. pp_update_composition() gained an optional
     * in-lock precondition because the writer cannot know what a selector means and the action
     * cannot open the write lock. Its contract is three lines and all three matter.
     */
    public function testTheInLockPreconditionRefusesWithoutWritingAnything(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        $before  = $this->storedState($post_id);

        $refusal = pp_update_composition(
            $post_id,
            [['component' => 'hero', 'props' => ['id' => 'never-written']]],
            null,
            static function () {
                return new WP_Error('caller_precondition', 'the caller said no');
            }
        );

        $this->assertTrue(is_wp_error($refusal));
        $this->assertSame(
            'caller_precondition',
            $refusal->get_error_code(),
            'the caller owns the meaning, so the writer returns its error verbatim'
        );
        $this->assertSame($before, $this->storedState($post_id), 'composition, both markers and the ring untouched');
    }

    /**
     * THE THIRD STATE FAILS CLOSED, and this is the test that says why it must. A precondition
     * returning null or false is a caller BUG, but the dangerous part is what a lenient gate
     * would do with it: `return $precondition` hands the lock callback a falsy NON-WP_Error,
     * which every caller's `if (is_wp_error($result))` reads as SUCCESS — an ok:true over a
     * write that never happened, which is the exact shape this whole issue exists to close.
     * So anything that is not literally true refuses, and refuses loudly.
     */
    public function testAPreconditionReturningNeitherTrueNorWpErrorRefusesRatherThanReadingAsSuccess(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        $before  = $this->storedState($post_id);

        foreach ([null, false, 0, 'yes'] as $bad) {
            $result = pp_update_composition(
                $post_id,
                [['component' => 'hero', 'props' => ['id' => 'never-written']]],
                null,
                static function () use ($bad) {
                    return $bad;
                }
            );

            $this->assertTrue(
                is_wp_error($result),
                'a malformed precondition must never return a value a caller reads as success'
            );
            $this->assertSame('composition_precondition_invalid', $result->get_error_code());
        }

        $this->assertSame($before, $this->storedState($post_id), 'and nothing was written on any of them');
    }

    /**
     * THE CONFIRMATION READS ON ITS OWN ACCOUNT, not as a passenger on the ring rebuild — and
     * this is the test that can tell the two apart. On a page whose ring exists but whose
     * `_pp_composition` does not, the rebuild's push is skipped entirely (there is no prior
     * state to preserve), so it issues NO ring read. A precondition must still get its
     * authoritative ring there; and with no precondition, nothing must read the ring at all.
     *
     * The sibling in-lock test cannot make this distinction any more, deliberately: once the
     * two consumers share ONE read, a query count cannot say which of them asked for it.
     */
    public function testThePreconditionGetsItsRingEvenWhenTheRebuildWouldNotReadOneAndCostsNothingWhenAbsent(): void
    {
        $post_id = $this->pageWithTwoPriorStates();
        // Ring stays; the prior composition goes. The rebuild's push is now a no-op.
        unset($GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition']);
        $this->assertNotEmpty(pp_get_composition_history($post_id), 'premise: the ring is still there');

        $seen = null;
        $GLOBALS['wpdb']->queries = [];
        $this->assertTrue(pp_update_composition(
            $post_id,
            [['component' => 'hero', 'props' => ['id' => 'written']]],
            null,
            static function (array $locked) use (&$seen) {
                $seen = $locked;
                return true;
            }
        ));
        $this->assertNotNull($seen, 'the precondition ran');
        $this->assertNotEmpty($seen, 'and was handed the authoritative ring, not an empty stand-in');
        $this->assertCount(
            1,
            array_filter($GLOBALS['wpdb']->queries, static fn ($q) => str_contains($q, "'_pp_composition_history'")),
            'exactly one ring read, issued for the precondition'
        );

        // And the other half of the bargain: no precondition, no ring read.
        $other = pp_create_page('No precondition page', 'draft');
        pp_update_composition($other, [['component' => 'hero', 'props' => ['id' => 'first']]]);
        $GLOBALS['wpdb']->queries = [];
        $this->assertTrue(pp_update_composition($other, [['component' => 'hero', 'props' => ['id' => 'second']]]));
        $reads = array_filter($GLOBALS['wpdb']->queries, static fn ($q) => str_contains($q, "'_pp_composition_history'"));
        $this->assertCount(1, $reads, 'the rebuild still reads once; the absent precondition adds nothing');
    }

    /** A precondition that passes changes nothing about the write. */
    public function testAPassingPreconditionWritesExactlyAsAnOmittedOneDoes(): void
    {
        $with    = pp_create_page('With precondition', 'draft');
        $without = pp_create_page('Without precondition', 'draft');
        $bands   = [['component' => 'hero', 'props' => ['id' => 'same', 'title' => 'Same']]];

        $this->assertTrue(pp_update_composition($with, $bands, null, static fn () => true));
        $this->assertTrue(pp_update_composition($without, $bands));

        $this->assertSame(
            $GLOBALS['_pp_test_store']['post_meta'][$without]['_pp_composition_hash'],
            $GLOBALS['_pp_test_store']['post_meta'][$with]['_pp_composition_hash'],
            'same bytes in, same content hash out'
        );
        $this->assertSame(
            pp_get_composition_marker($without)['version'],
            pp_get_composition_marker($with)['version'],
            'and the same version step'
        );
    }
}
