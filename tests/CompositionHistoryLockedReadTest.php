<?php
/**
 * tests/CompositionHistoryLockedReadTest.php — a concurrent write can no longer silently
 * drop a history-ring entry (#823).
 *
 * THE BUG THIS PINS. `pp_update_composition()` (lib/wp.php) rebuilds the bounded per-post
 * history ring read-modify-write INSIDE its advisory lock. Two of the three metas it
 * touches were already read straight from the database there, precisely because the
 * pre-lock freshness check may have warmed a stale meta cache:
 *
 *     $current_version = _pp_read_composition_version_locked($wpdb, $post_id);   // DB
 *     $prior_json      = _pp_read_composition_json_locked($wpdb, $post_id);      // DB
 *     $history         = pp_get_composition_history($post_id);                   // CACHE  ← the bug
 *
 * The ring was the exception, and it is the one structure whose entire purpose is that
 * nothing is lost. The interleaving, with no persistent object cache required (core's
 * WP_Object_Cache is per-request, and update_metadata()'s wp_cache_delete() only clears
 * the WRITING process's copy):
 *
 *     request B          request A          `_pp_composition_history` row
 *     ─────────────      ─────────────      ─────────────────────────────
 *     read page                             [ e1 ]        B's cache: [ e1 ]
 *     (whole meta row warms — update_meta_cache() loads EVERY key for the post,
 *      so the ring is warm even though B never asked for it)
 *     block in GET_LOCK
 *                        take lock
 *                        push e2           [ e1, e2 ]     B's cache: still [ e1 ]
 *                        release
 *     acquire lock
 *     rebuild from CACHE [ e1 ], append e3
 *                                          [ e1, e3 ]     ← e2 gone, silently, forever
 *
 * Since #818 that lost entry can be the PRESERVED-BYTES entry for a corrupt page: the only
 * recoverable copy of the bytes a repair replaced, rescued at the repair write and dropped
 * one ordinary write later. That is the data loss this file measures.
 *
 * WHY THIS CLASS IS STRUCTURALLY INVISIBLE TO THE SUITE, and therefore why the verification
 * below is built the way it is. tests/bootstrap.php stubs `update_postmeta_cache()` as an
 * explicit no-op ("the harness reads meta straight out of the store with no cache layer"),
 * so every other test in this repo runs as if the meta cache did not exist and CANNOT fail
 * on a stale-cache bug. A passing suite is not evidence here. What the harness DOES model,
 * since #767/v1.16.14, is a real postmeta table with a per-key divergence:
 *
 *     $GLOBALS['_pp_test_store']['wpdb_postmeta'][$id][$key]   the DATABASE ROW
 *     $GLOBALS['_pp_test_store']['post_meta'][$id][$key]       the (possibly stale) CACHE
 *
 * with the row bucket falling through to the cache when it holds nothing for a key. Staging
 * one key's divergence is how a two-request interleaving becomes a deterministic unit test.
 *
 * HONEST ABOUT WHAT THIS IS. These are cache-divergence SIMULATIONS, not concurrency tests:
 * one process, hand-staged store state, no real lock contention. The choreography below
 * (run A for real, snapshot the row it committed, restore B's pre-A cache copy) exists so
 * the staged state is one a real pair of requests actually produces, rather than one
 * invented to make an assertion pass. The bug is a disagreement between two reads of the
 * same key; that disagreement is exactly what the store models.
 *
 * Coverage (ten of the twelve are red against the unfixed writer; the two that are not are
 * the deliberate guard pins for behavior that must NOT move):
 *   the interleaving end to end — a preserved-bytes entry committed by a concurrent writer
 *     survives a stale-cached writer's rebuild
 *   the same interleaving through the REAL action surface (Section 14.1)
 *   and through restore_composition, the one path where the stale warm-up is GUARANTEED
 *     rather than likely (lib/actions.php reads the ring immediately before the write)
 *   the opposite direction: a cache holding MORE than the row does not resurrect it
 *   eviction at the ring bound follows the authoritative ring, not the cached one
 *   the two readers agree, entry for entry, over every stored shape the normalizer sees
 *     (the parity that keeps the #823 extraction honest)
 *   a PHP-serialized ring row reads as a ring, not as opaque bytes
 *   an absent row is an empty ring, not a decode failure
 *   the ring bound still holds through the authoritative read path
 *   the degradations: no $wpdb handle (all four capability clauses), and a FAILED read —
 *     neither may wipe the ring
 *   the row ordering the authoritative read shares with get_post_meta(single), and that the
 *     read really is issued, between GET_LOCK and RELEASE_LOCK
 */

use PHPUnit\Framework\TestCase;

/**
 * The bootstrap's shared `wpdb` stub answers every GET_LOCK with NULL, which makes
 * pp_update_composition() skip the write entirely — so a test that needs real writes to
 * land must grant the lock. Everything else (the postmeta point-lookup model with its
 * row-vs-cache divergence, `prepare()`, the guid lookup) is inherited unchanged.
 *
 * Adds two things this file needs and the carve-out's sibling stub does not: a record of
 * every query asked, and a switchable DB FAILURE on one meta key so the #212 fail-path can
 * be exercised (a failed get_var() returns null and sets last_error, which is
 * indistinguishable from "no row" without the flag).
 */
class PP_HistoryRing_Lockable_Wpdb extends wpdb
{
    /** Real wpdb flushes this to '' at the start of every query and sets it on error. */
    public string $last_error = '';

    /** @var string[] Every query get_var() was asked, in order. */
    public array $queries = [];

    /** @var string|null meta_key whose SELECT must report a database failure. */
    public ?string $fail_key = null;

    public function get_var(string $query)
    {
        $this->queries[] = $query;
        if (str_contains($query, 'GET_LOCK')) {
            return '1';
        }
        $this->last_error = '';
        if ($this->fail_key !== null && str_contains($query, "'" . $this->fail_key . "'")) {
            $this->last_error = 'MySQL server has gone away';
            return null; // a failed read looks exactly like an absent row (#212)
        }
        return parent::get_var($query);
    }

    public function query(string $query)
    {
        // RECORDED TOO, so the lock has a CLOSING boundary in the log and not just an
        // opening one. RELEASE_LOCK goes through query(), not get_var(); without this the
        // in-lock assertion could only prove "after GET_LOCK", which a read hoisted past
        // the release would still satisfy.
        $this->queries[] = $query;
        return 1; // RELEASE_LOCK
    }
}

class CompositionHistoryLockedReadTest extends TestCase
{
    /** Undecodable bytes — the #818 preserved-entry trigger. */
    private const CORRUPT_BYTES = '{"component":';

    protected function setUp(): void
    {
        parent::setUp();
        // setUp owns the reset in BOTH directions, so no test here depends on a preceding
        // tearDown having run (or on what an earlier file left in the store).
        $GLOBALS['_pp_test_store'] = [
            'post_meta'     => [],
            'posts'         => [],
            'options'       => [],
            'connectors'    => [],
            'next_id'       => 700,
            'custom_css'    => '',
            'wpdb_postmeta' => [],
        ];
        // The read under test is authoritative BY DESIGN, so these tests must exercise the
        // database branch rather than quietly proving the no-handle fallback.
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

    private function originalBands(): array
    {
        return [
            ['component' => 'hero',    'props' => ['id' => 'band-1', 'title' => 'Original hero']],
            ['component' => 'section', 'props' => ['id' => 'band-2', 'title' => 'Original section']],
        ];
    }

    private function laterBands(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'band-later', 'title' => 'Later hero']]];
    }

    private function repairBands(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'repaired', 'title' => 'Repaired']]];
    }

    /**
     * A page with real history: two authored writes, so the ring already holds one genuine
     * snapshot. (A first write on a fresh page pushes nothing — it has no prior state.)
     */
    private function pageWithHistory(): int
    {
        $post_id = pp_create_page('Concurrently edited page', 'draft');
        pp_update_composition($post_id, $this->originalBands());
        pp_update_composition($post_id, $this->laterBands());
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

    /**
     * MANDATORY AFTER EVERY WRITE THAT FOLLOWS A stageDivergence(), and the reason is a
     * harness/production divergence rather than tidiness. The stub's update_post_meta()
     * writes only to the CACHE bucket; nothing ever writes the ROW bucket, so a staged row
     * stays frozen at its pre-write value for the rest of the test. Production does the
     * opposite (update_metadata() UPDATEs the row, then invalidates the cache), so a second
     * write against a frozen row would rebuild from a state production would never hand it —
     * manufacturing exactly #823's symptom against a correct writer.
     *
     * Clearing the staged key models what actually happens next: the row now holds what the
     * write committed, and the following request reads it with a cold cache. One write per
     * staged divergence, then settle.
     */
    private function settle(int $post_id): void
    {
        unset($GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_history']);
    }

    /** @return array The raw (preserved-bytes) entries currently on the ring. */
    private function rawEntries(int $post_id): array
    {
        return array_values(array_filter(pp_get_composition_history($post_id), 'pp_history_entry_is_raw'));
    }

    // ── 1. The measured defect ───────────────────────────────────────────────

    /**
     * THE MEASURED DEFECT. On the unfixed writer this fails at the raw-entry assertion:
     * request B rebuilds the ring from its stale cached copy, and the preserved-bytes entry
     * request A committed one moment earlier is simply not in what B writes back. Nothing
     * errors; the ring is a slot shorter than the two writes that produced it.
     */
    public function testAConcurrentWritersPreservedEntrySurvivesAStaleCachedRebuild(): void
    {
        $post_id = $this->pageWithHistory();

        // Request B reads the page. update_meta_cache() warms the WHOLE row, so B now holds
        // a copy of the ring as it stands before A does anything — then B blocks in GET_LOCK.
        $b_warmed_ring = $this->storedRing($post_id);
        $this->assertNotNull($b_warmed_ring, 'premise: there is a ring to be stale about');

        // Request A holds the lock and runs to completion: the page went corrupt, and A
        // performs the documented repair, which mints the #818 preserved-bytes entry.
        update_post_meta($post_id, '_pp_composition', self::CORRUPT_BYTES);
        $this->assertSame('decode_error', pp_get_composition_result($post_id)['error']);
        $this->assertTrue(pp_update_composition($post_id, $this->repairBands()));
        $a_committed_ring = $this->storedRing($post_id);
        $this->assertCount(1, $this->rawEntries($post_id), 'precondition: A preserved the bytes (#818)');

        // The divergence itself: the ROW carries what A committed; B's request-local cache
        // still holds the pre-A copy, because A's wp_cache_delete() only cleared A's process.
        // (Only this key is staged stale. B's `_pp_composition` copy would be stale too, but
        // that read is already authoritative — it is the ring that this issue is about.)
        $this->stageDivergence($post_id, $a_committed_ring, $b_warmed_ring);

        // THE PREMISE, ASSERTED. Everything below is about which of two disagreeing copies
        // the rebuild reads, so a harness that stopped modelling the disagreement would make
        // this test pass without testing anything. Pin that the staged state is the
        // interesting one, and that the difference is exactly the preserved-bytes entry.
        $this->assertNotSame($b_warmed_ring, $a_committed_ring, 'premise: the row and the cache genuinely disagree');
        $this->assertStringContainsString('raw_b64', (string) $a_committed_ring, 'premise: the row holds the preserved entry');
        $this->assertStringNotContainsString('raw_b64', (string) $b_warmed_ring, 'premise: the stale cache does not');

        // B acquires the lock and writes.
        $this->assertTrue(pp_update_composition($post_id, $this->laterBands()));
        $this->settle($post_id);

        $ring = pp_get_composition_history($post_id);
        $raw  = $this->rawEntries($post_id);
        $this->assertCount(
            1,
            $raw,
            'the preserved-bytes entry a concurrent writer committed must survive the next rebuild'
        );
        $this->assertSame(self::CORRUPT_BYTES, $raw[0]['raw'], 'byte for byte — a lossy survivor is not a recovery');
        $this->assertCount(
            3,
            $ring,
            'one snapshot from the authoring writes, A\'s preserved bytes, and B\'s own push'
        );
        // And B's own entry really did land on top of A's, rather than replacing it.
        $newest = end($ring);
        $this->assertSame('repaired', $newest['composition'][0]['props']['id']);
    }

    /**
     * SECTION 14.1 — the same interleaving arriving through the REAL action surface, which
     * is what an operator or the chat AI actually runs. `update_composition` reaches the
     * same writer, but through validation, normalization and the action envelope; a fix that
     * only held for direct pp_update_composition() calls would protect nobody.
     */
    public function testTheSameInterleavingHoldsThroughTheUpdateCompositionAction(): void
    {
        $post_id = $this->pageWithHistory();
        $b_warmed_ring = $this->storedRing($post_id);

        update_post_meta($post_id, '_pp_composition', self::CORRUPT_BYTES);
        $repair = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairBands(),
        ]);
        $this->assertTrue($repair['ok'], 'precondition: the documented repair route succeeds');
        $a_committed_ring = $this->storedRing($post_id);

        $this->stageDivergence($post_id, $a_committed_ring, $b_warmed_ring);
        $this->assertNotSame($b_warmed_ring, $a_committed_ring, 'premise: the row and the cache genuinely disagree');

        $second = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->laterBands(),
        ]);
        $this->assertTrue($second['ok']);
        $this->settle($post_id);

        $raw = $this->rawEntries($post_id);
        $this->assertCount(1, $raw, 'the action surface must preserve it too');
        $this->assertSame(self::CORRUPT_BYTES, $raw[0]['raw']);
    }

    /**
     * THE PATH WHERE THE STALE WARM-UP IS GUARANTEED RATHER THAN LIKELY. Everywhere else the
     * cache is warm because SOMETHING read the post first; on restore it is warm by
     * construction — lib/actions.php reads the ring through pp_get_composition_history() to
     * resolve the selector, and then calls pp_update_composition() on the next lines. If any
     * single surface had to be covered, it is this one.
     *
     * STATED LIMIT, and it is a different axis from this issue: the SELECTOR still resolves
     * against the cached ring, so `steps_back=1` here means the newest entry B could see, not
     * the newest entry that exists. That is why the restored composition below is the
     * ORIGINAL bands and not A's repair. #823 is about the ring the write REBUILDS, and after
     * the fix a restore can no longer destroy the preserved bytes on its way through — which
     * is the part that was unrecoverable.
     */
    public function testTheRestorePathPreservesTheConcurrentEntryToo(): void
    {
        $post_id       = $this->pageWithHistory();
        $b_warmed_ring = $this->storedRing($post_id);

        update_post_meta($post_id, '_pp_composition', self::CORRUPT_BYTES);
        $this->assertTrue(pp_update_composition($post_id, $this->repairBands()), 'A repairs the page');
        $a_committed_ring = $this->storedRing($post_id);
        $this->assertCount(1, $this->rawEntries($post_id), 'precondition: A preserved the bytes');

        $this->stageDivergence($post_id, $a_committed_ring, $b_warmed_ring);
        $this->assertNotSame($b_warmed_ring, $a_committed_ring, 'premise: the row and the cache genuinely disagree');

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertTrue($result['ok'], 'the restore itself must still land');
        $this->settle($post_id);

        $raw = $this->rawEntries($post_id);
        $this->assertCount(1, $raw, 'a restore must not erase the preserved bytes it stepped over');
        $this->assertSame(self::CORRUPT_BYTES, $raw[0]['raw']);

        // The selector resolved against the CACHED ring — see the stated limit above.
        $this->assertSame(
            ['band-1', 'band-2'],
            array_column(array_column(pp_get_composition($post_id), 'props'), 'id'),
            'restore replayed the snapshot its selector saw'
        );
    }

    /**
     * EVICTION AT THE BOUND, over a ring that genuinely differs from the cached one. The
     * other bounding test lets the row fall through to the cache, so it proves the bound but
     * not WHOSE ring got bounded. Here the row is full (oldest slot = preserved bytes) while
     * the cache holds a short pre-A copy, so the two rings evict different entries — and
     * eviction is the one place a preserved-bytes entry may legitimately disappear, which
     * makes "evicted correctly" and "lost" easy to confuse if nothing pins them apart.
     */
    public function testEvictionAtTheBoundFollowsTheAuthoritativeRingNotTheCachedOne(): void
    {
        $post_id = $this->pageWithHistory();
        $max     = pp_composition_history_max();

        // The row: a FULL ring whose oldest slot is the preserved-bytes entry.
        $db_entries = [['timestamp' => 1, 'version' => 1, 'hash' => 'h0', 'raw' => self::CORRUPT_BYTES]];
        for ($i = 1; $i < $max; $i++) {
            $db_entries[] = [
                'timestamp'   => $i + 1,
                'version'     => $i + 1,
                'hash'        => 'h' . $i,
                'composition' => [['component' => 'hero', 'props' => ['id' => 'db-' . $i]]],
            ];
        }
        $db_ring = wp_json_encode(_pp_history_entries_for_storage($db_entries));

        // The cache: a short pre-A copy that shares nothing with it.
        $stale_ring = wp_json_encode([[
            'timestamp'   => 99,
            'version'     => 99,
            'hash'        => 'stale',
            'composition' => [['component' => 'hero', 'props' => ['id' => 'stale-1']]],
        ]]);

        $this->stageDivergence($post_id, $db_ring, $stale_ring);
        $this->assertTrue(pp_update_composition($post_id, $this->repairBands()));
        $this->settle($post_id);

        $ring = pp_get_composition_history($post_id);
        $ids  = array_map(
            static fn (array $e): string => pp_history_entry_is_raw($e) ? 'RAW' : $e['composition'][0]['props']['id'],
            $ring
        );

        $this->assertCount($max, $ring, 'the ring stays bounded');
        $this->assertNotContains('RAW', $ids, 'the row\'s OLDEST slot evicted — legitimately, in ring order');
        $this->assertNotContains('stale-1', $ids, 'and the stale cached entry was never in the ring the write rebuilt');
        $this->assertSame('db-' . ($max - 1), $ids[$max - 2], 'the row\'s newest entries survived, in order');
        $this->assertSame('band-later', $ring[$max - 1]['composition'][0]['props']['id'], 'with this write\'s own push last');
    }

    /**
     * THE OTHER DIRECTION, so the test cannot pass by reading "some second copy that happens
     * to agree": the CACHE holds entries the ROW does not. The row is the truth — a ring
     * deleted or rolled back underneath this request is genuinely gone — so the rebuild must
     * answer to the row and NOT resurrect the cached entries.
     */
    public function testTheRowIsTheAuthorityEvenWhenTheCacheHoldsMore(): void
    {
        $post_id = $this->pageWithHistory();

        // The row says: no ring at all. The cache still shows one.
        $this->assertNotNull($this->storedRing($post_id), 'premise: the cache holds a ring');
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_history'] = null;

        $this->assertTrue(pp_update_composition($post_id, $this->repairBands()));
        $this->settle($post_id);

        $ring = pp_get_composition_history($post_id);
        $this->assertCount(1, $ring, 'an absent row is an empty ring, not a decode failure and not the cached copy');
        $this->assertSame('band-later', $ring[0]['composition'][0]['props']['id'], 'and the push itself still landed');
    }

    // ── 2. The two readers must agree ────────────────────────────────────────

    /**
     * THE PARITY THAT KEEPS THE EXTRACTION HONEST. #823 gave the ring a second reader, so
     * the normalization moved into _pp_normalize_history_ring() and both call it. If the two
     * ever disagreed about which entries are readable, the disagreement itself would be a
     * silent loss — the rebuild would drop exactly the entries only the cached reader can
     * see. Every stored shape the normalizer distinguishes is staged in BOTH places and the
     * two answers compared.
     */
    public function testBothReadersReturnTheSameRingForEveryStoredShape(): void
    {
        $composition = [['component' => 'hero', 'props' => ['id' => 'snap']]];
        $shapes = [
            'absent'              => null,
            'empty string'        => '',
            'not json'            => 'not json at all',
            'json object'         => '{"a":1}',
            'json scalar'         => '5',
            'empty list'          => '[]',
            'snapshot entry'      => wp_json_encode([['timestamp' => 1, 'version' => 2, 'hash' => 'h', 'composition' => $composition]]),
            'raw entry'           => wp_json_encode([['timestamp' => 1, 'version' => 2, 'hash' => 'h', 'raw_b64' => base64_encode(self::CORRUPT_BYTES)]]),
            'unreadable base64'   => wp_json_encode([['timestamp' => 1, 'version' => 2, 'hash' => 'h', 'raw_b64' => 'not!base64!']]),
            'entry matching none' => wp_json_encode([['timestamp' => 1, 'version' => 2, 'hash' => 'h']]),
            // #841: a `composition` that is an array but NOT a list — the row a pre-#841
            // push filed for an object-shaped prior. The normalizer reclassifies it to a
            // raw entry, and BOTH readers have to do that identically: the locked reader is
            // the one whose answer gets PERSISTED, so a cached reader that reclassified
            // while it did not (or the reverse) would migrate the ring one way and store it
            // the other.
            'misfiled object'     => wp_json_encode([['timestamp' => 1, 'version' => 2, 'hash' => 'h', 'composition' => ['component' => 'hero', 'props' => ['id' => 'obj']]]]),
            'entry not an array'  => wp_json_encode(['just a string']),
            'mixed list'          => wp_json_encode([
                ['timestamp' => 1, 'version' => 1, 'hash' => 'a', 'composition' => $composition],
                'junk',
                ['timestamp' => 2, 'version' => 2, 'hash' => 'b', 'raw_b64' => base64_encode('bytes')],
            ]),
        ];

        foreach ($shapes as $label => $stored) {
            $post_id = pp_create_page('Parity ' . $label, 'draft');
            if ($stored === null) {
                unset($GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition_history']);
            } else {
                update_post_meta($post_id, '_pp_composition_history', $stored);
            }
            $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_history'] = $stored;

            $this->assertSame(
                pp_get_composition_history($post_id),
                _pp_read_composition_history_locked($post_id),
                'the cached and authoritative readers must agree on: ' . $label
            );
        }
    }

    /**
     * A PHP-SERIALIZED ROW IS STILL A RING. get_post_meta() unserializes on the way out and
     * a raw column read does not, so without maybe_unserialize() a row written as an ARRAY
     * (an importer, a post-duplicator, `wp post meta update ... --format=json`) would read as
     * a ring to every other surface and as opaque bytes here — and the next write would
     * silently replace all of it with a single entry.
     */
    public function testASerializedRingRowIsReadAsARingAndNotAsOpaqueBytes(): void
    {
        $post_id = $this->pageWithHistory();
        $entries = json_decode((string) $this->storedRing($post_id), true);
        $this->assertIsArray($entries, 'premise: there is a ring to re-store as an array');

        // The ROW holds the same ring, serialized the way update_metadata() stores an array.
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_history'] = serialize($entries);

        $this->assertSame(
            pp_get_composition_history($post_id),
            _pp_read_composition_history_locked($post_id),
            'a serialized row is the same ring, read through the same normalizer'
        );

        $this->assertTrue(pp_update_composition($post_id, $this->repairBands()));
        $this->settle($post_id);
        $this->assertCount(2, pp_get_composition_history($post_id), 'and the push appends to it rather than replacing it');
    }

    /**
     * THE RING STAYS BOUNDED through the authoritative path. Every existing eviction pin
     * runs the cached branch (no global $wpdb), so nothing else proves that the bound still
     * applies to a ring assembled from the database.
     */
    public function testTheRingStaysBoundedThroughTheAuthoritativeRead(): void
    {
        $post_id = $this->pageWithHistory();
        $max     = pp_composition_history_max();

        for ($i = 0; $i < $max + 3; $i++) {
            $this->assertTrue(pp_update_composition($post_id, [
                ['component' => 'hero', 'props' => ['id' => 'w' . $i, 'title' => 'Write ' . $i]],
            ]));
        }

        $ring = pp_get_composition_history($post_id);
        $this->assertCount($max, $ring, 'the ring stays bounded');
        $this->assertSame('w' . ($max + 1), end($ring)['composition'][0]['props']['id'], 'newest last, oldest evicted');
    }

    // ── 3. The degradations, neither of which may wipe the ring ──────────────

    /**
     * A FAILED READ MUST NOT READ AS AN EMPTY RING. $wpdb->get_var() returns null both for
     * "no row" and for a query that FAILED (#212). Treating the failure as an empty ring
     * would persist a one-entry ring over the previous ten — a fresh data-loss path opened
     * by the fix for a data-loss bug. The fallback leaves a failed read behaving exactly as
     * this code behaved before #823: the cached ring, which is stale at worst.
     */
    public function testAFailedAuthoritativeReadFallsBackInsteadOfWipingTheRing(): void
    {
        $post_id = $this->pageWithHistory();
        $before  = pp_get_composition_history($post_id);
        $this->assertNotEmpty($before, 'premise: there is a ring to lose');

        $GLOBALS['wpdb']->fail_key = '_pp_composition_history';
        $this->assertTrue(pp_update_composition($post_id, $this->repairBands()));
        $GLOBALS['wpdb']->fail_key = null;

        $after = pp_get_composition_history($post_id);
        $this->assertCount(count($before) + 1, $after, 'the previous entries survive a failed read');
        $this->assertSame($before[0], $after[0], 'and they survive unchanged, not rebuilt from nothing');
    }

    /**
     * A PRESENT-BUT-UNREADABLE ROW IS DAMAGE, NOT ABSENCE — and the difference is nine ring
     * slots. Surfaced by this iteration's adversarial pass and reproduced before it was
     * fixed: with the row staged as a truncated write, one ordinary composition write took
     * the ring from its full length down to the single entry that write pushed.
     *
     * The failed-read branch already refuses that wipe on the reasoning that a DB error must
     * not read as "no history". This is the same wipe arriving through a read that SUCCEEDED,
     * so it gets the same answer: the cached ring, and a line in the log.
     */
    public function testAnUnreadableRingRowFallsBackInsteadOfCollapsingTheRing(): void
    {
        $post_id = $this->pageWithHistory();
        $before  = pp_get_composition_history($post_id);
        $this->assertNotEmpty($before, 'premise: there is a ring to lose');

        // A truncated write: present, non-empty, and not a list.
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_history'] = '[{"timestamp":1,"vers';

        $this->assertTrue(pp_update_composition($post_id, $this->repairBands()));
        $this->settle($post_id);

        $after = pp_get_composition_history($post_id);
        $this->assertCount(count($before) + 1, $after, 'the readable entries survive a damaged row');
        $this->assertSame($before[0], $after[0], 'unchanged, not rebuilt from nothing');
    }

    /**
     * THE COUNTERPART THAT KEEPS THE GUARD HONEST: an EMPTY ring is perfectly readable. A
     * guard that treated `[]` as damage would fall back to the cache on every page whose ring
     * row exists but holds nothing, quietly reintroducing the cached read this issue removes
     * — a fix that fires on the healthy case is a fix that does not fire.
     */
    public function testAnEmptyRingRowIsReadAsEmptyAndNotAsDamage(): void
    {
        $post_id = $this->pageWithHistory();

        // The row: a real, readable, empty ring. The cache: entries the row does not have.
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_history'] = '[]';
        $this->assertNotEmpty(pp_get_composition_history($post_id), 'premise: the cache still shows a ring');

        $this->assertTrue(pp_update_composition($post_id, $this->repairBands()));
        $this->settle($post_id);

        $this->assertCount(
            1,
            pp_get_composition_history($post_id),
            'an empty row is an empty ring — the write appends to it rather than to the cached copy'
        );
    }

    /**
     * NO USABLE HANDLE, NO DATABASE TO ASK. The reader degrades to the cached read, the same
     * way both of its siblings do — a unit-context and misconfiguration path, never a live
     * one (production WordPress always satisfies every clause of pp_composition_db_handle()).
     * Pinned so the degradation stays a degradation and not a fatal on a partial handle.
     */
    public function testTheReaderDegradesToTheCachedRingWithNoUsableHandle(): void
    {
        $post_id = $this->pageWithHistory();
        $expected = pp_get_composition_history($post_id);

        foreach ([
            'no handle at all'  => null,
            'not an object'     => 'wpdb',
            'no prepare()'      => new class { public function get_var($q) { return null; } },
            // THE CLAUSE THE DOCBLOCK ACTUALLY CITES. A double with both methods but no
            // $postmeta property is the shape that would interpolate an EMPTY table name
            // into the SELECT — a query that cannot be trusted, which is why
            // pp_composition_db_handle() requires the property rather than just the methods.
            'no $postmeta'      => new class {
                public function get_var($q) { return 'SHOULD NEVER BE READ'; }
                public function prepare($q, ...$a) { return $q; }
            },
        ] as $label => $handle) {
            if ($handle === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $handle;
            }
            $this->assertSame(
                $expected,
                _pp_read_composition_history_locked($post_id),
                'must degrade to the cached ring, never fatal: ' . $label
            );
        }

        // …and a write with no handle at all still pushes exactly one entry.
        unset($GLOBALS['wpdb']);
        $this->assertTrue(pp_update_composition($post_id, $this->repairBands()));
        $this->assertCount(count($expected) + 1, pp_get_composition_history($post_id));

        $GLOBALS['wpdb'] = new PP_HistoryRing_Lockable_Wpdb();
    }

    // ── 4. The query shape ───────────────────────────────────────────────────

    /**
     * THE ROW ORDERING, pinned as a static property of the query because the harness models
     * one row per key and cannot stage a duplicate. `_pp_composition_history` is single-valued
     * by convention, not by schema: get_post_meta($id, $k, true) returns the FIRST row in
     * meta_id order (update_meta_cache selects ORDER BY meta_id ASC, get_metadata takes [0]),
     * so an unordered `LIMIT 1` could rebuild the ring from a row no other surface reads.
     * The ordering is what makes this reader agree with the cached one — dropping it is the
     * behavior change, not carrying it.
     *
     * Anchored to the FUNCTION BODY rather than the file: a file-wide grep passes as long as
     * some other line in lib/wp.php carries the ordering, and two of the direct readers in
     * this file deliberately do not (#825).
     */
    public function testTheAuthoritativeRingReadPinsTheRowOrderingItSharesWithGetPostMeta(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/wp.php');
        $start  = strpos($source, 'function _pp_read_composition_history_locked');
        $this->assertIsInt($start, 'premise: the authoritative ring reader exists');
        $end  = strpos($source, "\nfunction ", $start + 1);
        $body = substr($source, $start, $end === false ? null : $end - $start);

        $this->assertMatchesRegularExpression(
            '/SELECT meta_value FROM \{\$wpdb->postmeta\}.*ORDER BY meta_id ASC LIMIT 1/',
            $body,
            'the authoritative ring read must select the same row get_post_meta(single) returns'
        );
    }

    /**
     * AND IT REALLY IS THE DATABASE BEING ASKED, inside the lock. Without this the whole
     * file could pass on a reader that never issued a query at all — the failure mode the
     * harness's stubbed-out cache layer makes easy to ship.
     */
    public function testTheRebuildActuallyQueriesTheRingRowInsideTheLock(): void
    {
        $post_id = $this->pageWithHistory();
        $GLOBALS['wpdb']->queries = [];

        pp_update_composition($post_id, $this->repairBands());

        $queries = $GLOBALS['wpdb']->queries;
        $asked   = array_values(array_filter(
            $queries,
            static fn (string $q): bool => str_contains($q, "'_pp_composition_history'")
        ));
        $this->assertCount(1, $asked, 'exactly one authoritative ring read per write');

        $index_of = static function (array $queries, string $needle) {
            foreach ($queries as $i => $q) {
                if (str_contains($q, $needle)) {
                    return $i;
                }
            }
            return null;
        };
        $lock_at    = $index_of($queries, 'GET_LOCK');
        $release_at = $index_of($queries, 'RELEASE_LOCK');
        $read_at    = array_search($asked[0], $queries, true);

        $this->assertIsInt($lock_at, 'premise: the lock was taken');
        $this->assertIsInt($release_at, 'premise: the lock was released');
        // BOTH boundaries, because only the closing one catches the refactor that matters:
        // hoisting the read out of the mutator closure would still be "after GET_LOCK".
        $this->assertGreaterThan($lock_at, $read_at, 'the read happens after the lock is taken');
        $this->assertLessThan($release_at, $read_at, 'and BEFORE it is released — inside the lock, not merely near it');
    }
}
