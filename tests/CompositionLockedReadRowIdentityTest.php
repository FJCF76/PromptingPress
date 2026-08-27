<?php
/**
 * tests/CompositionLockedReadRowIdentityTest.php — every in-lock composition read names the
 * SAME postmeta row as every other surface, and the ring entry's marker comes from that row
 * rather than from the object cache (#825, #828).
 *
 * TWO DEFECTS, ONE BLOCK. `pp_update_composition()` (lib/wp.php) makes four reads inside its
 * per-post advisory lock, and after #823 they looked like this:
 *
 *     $current_version = _pp_read_composition_version_locked($wpdb, $post_id);   DB, UNORDERED  ← #825
 *     $locked_history  = _pp_read_composition_history_locked($post_id);          DB, ordered
 *     $prior_json      = _pp_read_composition_json_locked($wpdb, $post_id);      DB, UNORDERED  ← #825
 *     'hash' =>          get_post_meta($post_id, '_pp_composition_hash', true);  OBJECT CACHE   ← #828
 *
 * #825 — THE UNORDERED ROW. `wp_postmeta` carries no unique constraint on
 * (post_id, meta_key), and `add_post_meta()`, an importer, a post-duplicator or a hand-run
 * `wp db query` appends a second row happily. Every other surface reads these keys through
 * `get_post_meta($id, $k, true)`, which resolves to the FIRST row in meta_id order —
 * `update_meta_cache()` selects `ORDER BY meta_id ASC` and `get_metadata()` takes element
 * [0]. A `SELECT ... LIMIT 1` with no ORDER BY lets MySQL return ANY qualifying row, so on a
 * duplicated key these two readers could answer from a row nothing else in the system reads:
 *
 *     row 0  healthy composition        ← what `wp pp check page`, `operate inspect-composition`,
 *     row 1  corrupt bytes                the render path and the classifier all see
 *
 *     the write path (unfixed) may take row 1, decide the prior value did not decode, and
 *     push a PRESERVED-BYTES entry (#818) for bytes the rest of the system does not consider
 *     stored — a consumed ring slot and a `restorable: false` entry that steps_back=1, the
 *     chat's undo selector, then refuses.
 *
 * #828 — THE CACHED MARKER. `hash` on a ring entry is PROVENANCE, not a checksum, and
 * nothing reads it today — which is why it was carved out of #823 rather than bundled into
 * it. It still mattered: `version` on the same entry has been authoritative since #113, so a
 * stale `hash` beside a fresh `version` describes a {version, hash} pair that never existed,
 * and "three of the four in-lock reads are authoritative" reads as an oversight to whoever
 * arrives next.
 *
 * WHY THIS FILE NEEDS ITS OWN DOUBLE. The shared harness (tests/bootstrap.php) models ONE
 * postmeta row per key and cannot stage a duplicate — the sibling #823 file says so in the
 * docblock of its own ordering test, which is why that one pins the ordering as a static
 * property of the query text rather than as behaviour. `PP_RowIdentity_Wpdb` below closes
 * that gap: it models a LIST of rows per key, answers according to whether the SELECT
 * carries the ordering, AND keeps the cache bucket holding row 0 the way core's
 * update_meta_cache() would — so the assertion can be AGREEMENT with get_post_meta(),
 * which is the actual invariant, rather than "this double returned index 0".
 *
 * THIRTEEN of the eighteen tests here are red against the unfixed readers. The five that
 * pass both before and after are deliberate, and each pins something that must NOT move:
 *
 *   testAnUncontendedWriteProducesTheSameEntryItProducedBefore    #828's behaviour-neutral claim
 *   testTheEntryRecordsTheMarkerOf…NotTheStateThatReplacedIt      the read stays ahead of the
 *                                                                marker write three lines below
 *   testAFailedMarkerReadInsideARealWriteMintsTheCachedMarker…    the #212 floor: degrading is
 *                                                                no worse than the cached read
 *                                                                it replaced, which is exactly
 *                                                                why it passes before AND after
 *   testTheDoubleModelsTheQueryTailRatherThanGreppingFor…         the harness's own self-test
 *   testAPartialHandleStillReachesTheDbBranchInTheTwoOlder…       pre-existing behaviour, filed
 *                                                                as #849 rather than changed
 *
 * Keep that split honest when adding a test: a new test that passes against the unfixed
 * readers and is NOT one of these is a test that measures nothing.
 *
 * ALSO PINNED, AND DELIBERATELY NOT FIXED: neither `_pp_read_composition_version_locked()`
 * nor `_pp_read_composition_json_locked()` distinguishes a FAILED query from an absent row
 * (#212) — `$wpdb->get_var()` returns null for both. Their docblocks now name that gap and
 * it is filed separately, because every honest fix there changes a caller-visible outcome
 * (a nullable return plus a new refusal, or a posture choice between degrading and refusing)
 * rather than aligning a row selection. This file pins the ordering, not the posture.
 */

use PHPUnit\Framework\TestCase;

/**
 * A `wpdb` double that models what the shared harness cannot: MORE THAN ONE ROW for a single
 * (post_id, meta_key), and MySQL's freedom to pick among them.
 *
 * THE ROW-CHOICE RULE, which is the whole point of the class. A staged key holds a list in
 * meta_id order. An ORDERED select gets `$rows[0]` — the row `get_post_meta($id, $k, true)`
 * returns. An UNORDERED select gets the LAST row. Real MySQL is free to return any qualifying
 * row, so "the last one" is a legal answer rather than an invented one, and it is the only
 * choice that makes an unfixed reader DETERMINISTICALLY wrong — a double that happened to
 * return row 0 either way would let both the fixed and the unfixed reader pass and prove
 * nothing.
 *
 * IT MODELS THE QUERY TAIL, IT DOES NOT GREP FOR IT, and the difference is the whole value of
 * this file. The first version of this class decided by asking whether the SQL text CONTAINED
 * "ORDER BY meta_id ASC". That made every "behavioural" test a source-text pin in disguise:
 * appending ` OFFSET 1` to all five ordered SELECTs reintroduces #825 in a strictly worse form
 * (deterministically the second row, rather than at MySQL's discretion) and the whole file
 * stayed green, because the substring was still there. Measured, not hypothesised — a review
 * specialist ran exactly that mutation against a copy of the tree.
 *
 * So the tail is PARSED and APPLIED: ORDER BY chooses the ordering, OFFSET indexes into it,
 * and any postmeta point-lookup whose shape this class does not model THROWS instead of
 * quietly resolving to row 0. A double that silently accepts a query it cannot interpret is
 * how a test suite ends up asserting the fake.
 *
 * Unstaged keys fall through to the shared stub's postmeta model (row bucket, then cache
 * bucket), so installing this double changes nothing for a page with no duplicate staged.
 *
 * `last_error` IS FLUSHED AT THE TOP OF EVERY QUERY, including GET_LOCK and RELEASE_LOCK,
 * because that is what `wpdb::query()` does — it resets the property at the start of every
 * statement and sets it on error. A double that only set the flag would let one failing read
 * leak a stale error into every later read in the same write, and the #212 degradation tests
 * would then be proving the fake instead of the code.
 */
class PP_RowIdentity_Wpdb extends wpdb
{
    /** Real wpdb flushes this to '' at the start of every query and sets it on error. */
    public string $last_error = '';

    /** @var string[] Every query get_var()/query() was asked, in order. */
    public array $queries = [];

    /** @var string|null meta_key whose SELECT must report a database failure (#212). */
    public ?string $fail_key = null;

    /** @var array<int, array<string, string[]>> post_id => meta_key => rows, in meta_id order. */
    public array $rows = [];

    /**
     * Stage a duplicated (or simply divergent) key. Row 0 is the one every surface reads.
     *
     * `$prime_cache` MIRRORS ROW 0 INTO THE CACHE BUCKET, and it defaults to true because
     * without it these tests would measure the wrong thing. The invariant #825 is about is
     * AGREEMENT — "the in-lock read names the row get_post_meta(single) returns" — and an
     * assertion against a hand-written literal only proves "this double returned index 0",
     * which is a fact about the double. Core resolves a duplicated key by selecting
     * ORDER BY meta_id ASC into the cache and taking element [0], so a cache holding row 0
     * is what a real duplicated key actually produces; priming it lets the test assert the
     * two readers against EACH OTHER. Pass false to stage a row-vs-cache divergence
     * instead, which is the #828 interleaving rather than the #825 duplicate.
     */
    public function stageRows(int $post_id, string $meta_key, array $rows, bool $prime_cache = true): void
    {
        $this->rows[$post_id][$meta_key] = $rows;
        if ($prime_cache && $rows !== []) {
            $GLOBALS['_pp_test_store']['post_meta'][$post_id][$meta_key] = $rows[0];
        }
    }

    /**
     * MANDATORY AFTER THE ONE WRITE THAT FOLLOWS A stageRows(), for the reason the sibling
     * #823 file spells out on its own settle(): the harness's update_post_meta() writes to
     * the CACHE bucket and nothing ever writes these staged rows, so a staged key stays
     * frozen at its pre-write value. Production is the opposite — update_metadata() UPDATEs
     * every row for the key (which is also why a duplicate collapses on the next write) and
     * then invalidates the cache. Stage, perform ONE write, settle.
     */
    public function settle(int $post_id, string $meta_key): void
    {
        unset($this->rows[$post_id][$meta_key]);
    }

    public function get_var(string $query)
    {
        $this->queries[] = $query;
        $this->last_error = '';
        if (str_contains($query, 'GET_LOCK')) {
            return '1';
        }
        if ($this->fail_key !== null && str_contains($query, "'" . $this->fail_key . "'")) {
            $this->last_error = 'MySQL server has gone away';
            return null; // a failed read looks exactly like an absent row (#212)
        }
        if (preg_match("/meta_key = '([^']+)'/", $query, $km)
            && preg_match('/post_id = (\d+)/', $query, $pm)) {
            $staged = $this->rows[(int) $pm[1]][$km[1]] ?? null;
            if (is_array($staged) && $staged !== []) {
                return $this->applyTail($query, $staged);
            }
        }
        return parent::get_var($query);
    }

    /**
     * Resolve a postmeta point-lookup against a staged row list by MODELLING its tail.
     *
     * THE STRICT SHAPE MATCH IS THE POINT. Anything this class cannot interpret throws, so a
     * reader that grows a clause the double does not model fails loudly instead of silently
     * resolving to row 0 — which is exactly how the grep-for-the-substring version let an
     * `OFFSET 1` mutation pass every test in this file.
     *
     * Ordering, and why "no ORDER BY" means the LAST row: MySQL is free to return ANY
     * qualifying row for an unordered LIMIT 1, so any choice is legal. The last one is the
     * choice that makes an unordered reader deterministically disagree with
     * get_post_meta(single), which is the disagreement #825 is about.
     */
    private function applyTail(string $query, array $rows)
    {
        $matched = preg_match(
            "/^SELECT meta_value FROM \S+ WHERE post_id = \d+ AND meta_key = '[^']+'"
            . '(?:\s+ORDER BY (\w+) (ASC|DESC))?'
            . '\s+LIMIT (\d+)(?:\s+OFFSET (\d+))?$/i',
            trim($query),
            $m
        );
        if (!$matched) {
            throw new RuntimeException(
                'PP_RowIdentity_Wpdb cannot model this query, so it refuses to answer it '
                . 'rather than invent a row: ' . $query
            );
        }

        [, $order_col, $direction, $limit] = $m + [3 => '', 4 => ''];
        $offset = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;

        if ($order_col !== '' && strtolower($order_col) !== 'meta_id') {
            throw new RuntimeException(
                'PP_RowIdentity_Wpdb only models meta_id ordering; got: ' . $order_col
            );
        }
        if ((int) $limit < 1) {
            return null;
        }

        // Staged order IS meta_id ascending. DESC reverses it; an UNORDERED select gets the
        // same reversal, because "any qualifying row" is a legal answer and this is the one
        // that exposes the defect.
        $ordered = (strtoupper((string) $direction) === 'ASC') ? $rows : array_reverse($rows);

        return $ordered[$offset] ?? null;
    }

    public function query(string $query)
    {
        // RECORDED TOO, so the lock has a CLOSING boundary in the log and not just an opening
        // one: RELEASE_LOCK goes through query(), not get_var(), and without it an "inside
        // the lock" assertion could only prove "after GET_LOCK", which a read hoisted past
        // the release would still satisfy.
        $this->queries[] = $query;
        $this->last_error = '';
        return 1; // RELEASE_LOCK
    }
}

class CompositionLockedReadRowIdentityTest extends TestCase
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
        // The reads under test are authoritative BY DESIGN, so these tests must exercise the
        // database branch rather than quietly proving the no-handle fallback.
        $GLOBALS['wpdb'] = new PP_RowIdentity_Wpdb();
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

    /** A page that has been through the writer once, so it has a composition and a marker. */
    private function writtenPage(): int
    {
        $post_id = pp_create_page('Row identity page', 'draft');
        $this->assertTrue(pp_update_composition($post_id, $this->originalBands()));
        return $post_id;
    }

    private function db(): PP_RowIdentity_Wpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function storedMeta(int $post_id, string $key)
    {
        return $GLOBALS['_pp_test_store']['post_meta'][$post_id][$key] ?? null;
    }

    /** The newest ring entry, normalized the way every reader sees it. */
    private function newestEntry(int $post_id): array
    {
        $ring = pp_get_composition_history($post_id);
        $this->assertNotEmpty($ring, 'premise: the write pushed an entry');
        return $ring[count($ring) - 1];
    }

    // ── 1. #825 — the row every other surface reads ──────────────────────────

    /**
     * THE MEASURED DEFECT, VERSION HALF. With two `_pp_composition_version` rows staged, the
     * unfixed reader answers from row 1 (99) while `get_post_meta($id, $k, true)` — and
     * therefore the freshness marker, the CLI, and the editor — answers from row 0 (7).
     */
    public function testTheVersionReadPinsTheFirstRowJustAsGetPostMetaDoes(): void
    {
        $post_id = $this->writtenPage();
        $this->db()->stageRows($post_id, '_pp_composition_version', ['7', '99']);

        // The invariant is AGREEMENT, so it is asserted against the cached reader rather
        // than against a literal: "7" on both sides would also be satisfied by a double
        // that always returned index 0 regardless of the query.
        $this->assertSame(
            (int) get_post_meta($post_id, '_pp_composition_version', true),
            _pp_read_composition_version_locked($this->db(), $post_id),
            'the in-lock version read must name the same row get_post_meta(single) returns'
        );
        $this->assertSame(7, _pp_read_composition_version_locked($this->db(), $post_id), 'and that row is row 0');
    }

    /**
     * AND THE CALLER'S STAKES, which is where an unordered row stops being cosmetic. The
     * compare-and-swap (#13) compares the caller's baseline against exactly this read, and
     * the next version is computed from it. On the unfixed reader a baseline of 7 — the
     * version every surface reports — collides with row 1's 99 and the write is REFUSED with
     * `composition_conflict` on a page nothing has touched.
     */
    public function testACompareAndSwapAgainstTheVisibleVersionIsNotRefusedByADuplicateRow(): void
    {
        $post_id = $this->writtenPage();
        $this->db()->stageRows($post_id, '_pp_composition_version', ['7', '99']);

        $result = pp_update_composition($post_id, $this->laterBands(), 7);
        $this->db()->settle($post_id, '_pp_composition_version');

        $this->assertTrue($result, 'a baseline matching the visible version must be accepted');
        $this->assertSame(
            8,
            (int) $this->storedMeta($post_id, '_pp_composition_version'),
            'and the counter must advance from the row every other surface reads, not from row 1'
        );
    }

    /**
     * THE MEASURED DEFECT, COMPOSITION HALF — the disagreement #825 was filed on. Row 0 is a
     * healthy composition and row 1 is corrupt bytes; the classifier, the render path and
     * `wp pp check page` all read row 0 and call the page healthy.
     */
    public function testTheCompositionReadPinsTheFirstRowJustAsGetPostMetaDoes(): void
    {
        $post_id = $this->writtenPage();
        $healthy = (string) $this->storedMeta($post_id, '_pp_composition');
        $this->db()->stageRows($post_id, '_pp_composition', [$healthy, self::CORRUPT_BYTES]);

        $this->assertSame(
            (string) get_post_meta($post_id, '_pp_composition', true),
            _pp_read_composition_json_locked($this->db(), $post_id),
            'the in-lock prior-composition read must name the row the classifier reads'
        );
        $this->assertSame($healthy, _pp_read_composition_json_locked($this->db(), $post_id), 'and that row is row 0');
    }

    /**
     * THE CONSEQUENCE THE ISSUE DESCRIBES, END TO END. On the unfixed reader the write pushes
     * a PRESERVED-BYTES entry (#818) for row 1's corrupt bytes: a ring slot consumed by a
     * record of bytes the rest of the system does not consider stored, and a
     * `restorable: false` entry that steps_back=1 — the chat's undo selector — then refuses.
     * Fixed, the same write preserves the healthy composition as a replayable snapshot.
     */
    public function testADuplicateRowDoesNotMintAPreservedBytesEntryForAHealthyPage(): void
    {
        $post_id = $this->writtenPage();
        $healthy = (string) $this->storedMeta($post_id, '_pp_composition');
        $this->assertTrue(
            pp_get_composition_result($post_id)['ok'],
            'premise: every cached surface calls this page healthy'
        );

        $this->db()->stageRows($post_id, '_pp_composition', [$healthy, self::CORRUPT_BYTES]);
        $this->assertTrue(pp_update_composition($post_id, $this->laterBands()));
        $this->db()->settle($post_id, '_pp_composition');

        $entry = $this->newestEntry($post_id);
        $this->assertFalse(
            pp_history_entry_is_raw($entry),
            'a healthy first row must be preserved as a replayable snapshot, not as rescued bytes'
        );
        $this->assertSame(
            $this->originalBands(),
            $entry['composition'],
            'and the snapshot must be the composition row 0 actually held'
        );
    }

    // ── 2. #828 — the marker comes from the row ──────────────────────────────

    /**
     * THE MEASURED DEFECT, MARKER HALF. The row and the request's warm meta cache disagree —
     * the interleaving #823 documents, where `update_meta_cache()` warmed the post's WHOLE
     * meta row before this request blocked on GET_LOCK and a concurrent writer landed after.
     * The unfixed entry records the cache's marker: the marker of the write BEFORE the one
     * this entry actually replaced.
     */
    public function testTheRingEntryMarkerComesFromTheRowAndNotTheWarmedCache(): void
    {
        $post_id = $this->writtenPage();
        $row_hash = str_repeat('a', 64);
        // prime_cache: FALSE — this is the #828 interleaving, where the row and the warmed
        // cache genuinely disagree. Priming would erase the divergence under test.
        $this->db()->stageRows($post_id, '_pp_composition_hash', [$row_hash], false);
        $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition_hash'] = str_repeat('b', 64);
        $this->assertNotSame(
            $row_hash,
            (string) get_post_meta($post_id, '_pp_composition_hash', true),
            'premise: the row and the cache genuinely disagree'
        );

        $this->assertTrue(pp_update_composition($post_id, $this->laterBands()));
        $this->db()->settle($post_id, '_pp_composition_hash');

        $this->assertSame(
            $row_hash,
            $this->newestEntry($post_id)['hash'],
            'the entry must record the marker the DATABASE held, not the one this request had warmed'
        );
    }

    /**
     * THE MARKER ROW IS CHOSEN THE SAME WAY THE OTHER THREE ARE (#825 applied to #828's new
     * reader). `_pp_composition_hash` is single-valued by convention too, so a duplicated key
     * must resolve first-row-wins here as well — otherwise the fix for one disagreement would
     * introduce another on the key it touches.
     */
    public function testTheMarkerReadPinsTheFirstRowToo(): void
    {
        $post_id = $this->writtenPage();
        $first = str_repeat('c', 64);
        $this->db()->stageRows($post_id, '_pp_composition_hash', [$first, str_repeat('d', 64)]);

        $this->assertSame(
            (string) get_post_meta($post_id, '_pp_composition_hash', true),
            _pp_read_composition_hash_locked($post_id),
            'the marker read must name the same row get_post_meta(single) returns'
        );
        $this->assertSame($first, _pp_read_composition_hash_locked($post_id), 'and that row is row 0');
    }

    /**
     * THE GUARD PIN: AN UNCONTENDED WRITE PRODUCES THE ENTRY IT ALWAYS DID. #828 is declared
     * behaviour-neutral for well-formed flows, and that claim has to be measured rather than
     * asserted — this is the regression half of the change.
     *
     * Field-for-field rather than byte-for-byte, and the distinction is honest rather than
     * weaselly: the entry carries `time()`, so literal byte identity is unobtainable without
     * freezing the clock, and freezing it would pin the harness rather than the writer. Every
     * field the writer actually decides is pinned — the key SET (so no field appears or
     * vanishes), the marker, the version, and the payload.
     */
    public function testAnUncontendedWriteProducesTheSameEntryItProducedBefore(): void
    {
        $post_id = $this->writtenPage();
        $marker_before = pp_get_composition_marker($post_id);

        $this->assertTrue(pp_update_composition($post_id, $this->laterBands()));

        $entry = $this->newestEntry($post_id);
        $this->assertSame(
            ['timestamp', 'version', 'hash', 'composition'],
            array_keys($entry),
            'no field appears or vanishes on an ordinary write'
        );
        $this->assertSame(
            $marker_before['hash'],
            $entry['hash'],
            'the marker is the one the page carried before this write — the state this entry preserves'
        );
        $this->assertSame($marker_before['version'], $entry['version']);
        $this->assertSame($this->originalBands(), $entry['composition']);
    }

    /**
     * AND IT IS THE PRIOR MARKER, NEVER THE NEW ONE. The read has to happen before the
     * `update_post_meta($post_id, '_pp_composition_hash', $hash)` three lines below it;
     * hoisted after, the entry would claim the state it preserves had the hash of the state
     * that replaced it. Asserted as an outcome rather than as a call order, because that is
     * the invariant — an ordering assertion would pass on a reader that read the right row at
     * the wrong moment.
     */
    public function testTheEntryRecordsTheMarkerOfTheStateItPreservesNotTheStateThatReplacedIt(): void
    {
        $post_id = $this->writtenPage();
        $this->assertTrue(pp_update_composition($post_id, $this->laterBands()));

        $entry = $this->newestEntry($post_id);
        $this->assertSame(
            pp_composition_content_hash($this->originalBands()),
            $entry['hash'],
            'the marker of the REPLACED state'
        );
        $this->assertNotSame(
            pp_composition_content_hash($this->laterBands()),
            $entry['hash'],
            'and never the marker of the state this write installed'
        );
    }

    /**
     * ONE MARKER READ PER WRITE, ISSUED INSIDE THE LOCK. Without this the whole file could
     * pass on a reader that never queried at all — the failure mode the harness's stubbed-out
     * cache layer makes easy to ship — and the read count guards the critical section against
     * silently gaining a second query that every concurrent writer to the post waits for.
     */
    public function testTheMarkerIsQueriedExactlyOnceAndInsideTheLock(): void
    {
        $post_id = $this->writtenPage();
        $this->db()->queries = [];

        $this->assertTrue(pp_update_composition($post_id, $this->laterBands()));

        $queries = $this->db()->queries;
        $asked = array_values(array_filter(
            $queries,
            static fn (string $q): bool => str_contains($q, "'_pp_composition_hash'")
        ));
        $this->assertCount(1, $asked, 'exactly one authoritative marker read per write');

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
        $this->assertGreaterThan($lock_at, $read_at, 'the read happens after the lock is taken');
        $this->assertLessThan($release_at, $read_at, 'and BEFORE it is released — inside the lock, not merely near it');
    }

    /**
     * THE DEGRADATIONS, NEITHER OF WHICH MAY BE WORSE THAN THE CACHED READ IT REPLACED. With
     * no usable handle there is no database to ask; with a FAILED query `get_var()` returns
     * null exactly as it does for an absent row (#212), and reading that as `''` would mint a
     * marker that never existed. Both fall back to the value this slot held before #828, so
     * the floor of the change is "no worse than before" rather than "usually better".
     *
     * The no-handle cases walk all four clauses of pp_composition_db_handle(), including the
     * missing-$postmeta shape that would otherwise interpolate an empty table name.
     */
    public function testTheMarkerReadDegradesToTheCachedValueRatherThanToAnEmptyMarker(): void
    {
        $post_id = $this->writtenPage();
        $cached  = (string) get_post_meta($post_id, '_pp_composition_hash', true);
        $this->assertNotSame('', $cached, 'premise: there is a marker to lose');

        foreach ([
            'no handle at all' => null,
            'not an object'    => 'wpdb',
            'no prepare()'     => new class { public function get_var($q) { return null; } },
            'no $postmeta'     => new class {
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
                $cached,
                _pp_read_composition_hash_locked($post_id),
                'must degrade to the cached marker, never fatal and never blank: ' . $label
            );
        }

        $GLOBALS['wpdb'] = new PP_RowIdentity_Wpdb();
        $this->db()->fail_key = '_pp_composition_hash';
        $this->assertSame(
            $cached,
            _pp_read_composition_hash_locked($post_id),
            'a FAILED read is not an absent row — it must not become an empty marker (#212)'
        );
        $this->db()->fail_key = null;
    }

    /**
     * THE SAME DEGRADATION THROUGH A REAL WRITE, which is the outcome the guard above exists
     * for. Proving it on the bare reader shows the return value; proving it here shows the
     * ring ENTRY carrying the cached marker rather than '' — a provenance value that never
     * existed. It also makes the double's per-statement `last_error` flush load-bearing:
     * this is the only test where a FAILING read is followed by succeeding ones on the same
     * instance, so a flush that stopped matching wpdb::query() would show up here.
     */
    public function testAFailedMarkerReadInsideARealWriteMintsTheCachedMarkerNotAnEmptyOne(): void
    {
        $post_id = $this->writtenPage();
        $cached  = (string) get_post_meta($post_id, '_pp_composition_hash', true);
        $this->assertNotSame('', $cached, 'premise: there is a marker to lose');

        $this->db()->fail_key = '_pp_composition_hash';
        $this->assertTrue(pp_update_composition($post_id, $this->laterBands()), 'the write still lands');
        $this->db()->fail_key = null;

        $this->assertSame(
            $cached,
            $this->newestEntry($post_id)['hash'],
            'a failed marker read degrades to the cache, never to the empty marker (#212)'
        );
        $this->assertSame(
            '',
            $this->db()->last_error,
            'and the failure does not leak past the statement that produced it'
        );
    }

    /**
     * AN ABSENT MARKER READS AS '', the counterpart that keeps the guard above honest. A
     * legacy page never written through pp_update_composition() has no marker row at all, and
     * `get_post_meta($id, $k, true)` answers '' there — so the authoritative read must too,
     * or the two would disagree on the one case that is not an error.
     */
    public function testAnAbsentMarkerRowReadsAsEmptyAndNotAsFailure(): void
    {
        $post_id = pp_create_page('No marker yet', 'draft');
        $this->assertSame('', (string) get_post_meta($post_id, '_pp_composition_hash', true), 'premise');
        $this->assertSame('', _pp_read_composition_hash_locked($post_id));
    }

    /**
     * THE DOUBLE'S OWN SELF-TEST, and it is here because without it this file's other twelve
     * tests rest on an unverified assumption about the harness.
     *
     * The first version of PP_RowIdentity_Wpdb chose its row by asking whether the SQL text
     * contained "ORDER BY meta_id ASC". Under that rule, appending ` OFFSET 1` to every
     * ordered SELECT in lib/wp.php — which makes each locked reader return the SECOND row,
     * i.e. #825 again but deterministic instead of at MySQL's discretion — left all thirteen
     * tests here green and the full suite green. The substring was still present, so the
     * grep was still satisfied, and every "behavioural" assertion in this file was really
     * one literal asserted twice.
     *
     * This pins the fix: the tail is interpreted, so OFFSET moves the answer.
     */
    public function testTheDoubleModelsTheQueryTailRatherThanGreppingForTheOrdering(): void
    {
        $post_id = $this->writtenPage();
        $db      = $this->db();
        $db->stageRows($post_id, '_pp_composition_hash', ['first', 'second']);

        $select = "SELECT meta_value FROM {$db->postmeta} WHERE post_id = %d AND meta_key = %s";
        $ask = static fn (string $tail) => $db->get_var(
            $db->prepare($select . $tail, $post_id, '_pp_composition_hash')
        );

        $this->assertSame('first', $ask(' ORDER BY meta_id ASC LIMIT 1'), 'ordered: the first row');
        $this->assertSame('second', $ask(' LIMIT 1'), 'unordered: a different row, which is the defect');
        $this->assertSame(
            'second',
            $ask(' ORDER BY meta_id ASC LIMIT 1 OFFSET 1'),
            'OFFSET must move the answer — a double that greps for the ordering text cannot see this, '
            . 'and a reader that keeps the text while losing the semantics would pass every other test here'
        );
        $this->assertSame('second', $ask(' ORDER BY meta_id DESC LIMIT 1'), 'DESC reverses');

        // And a shape it cannot interpret is refused rather than answered with a guess.
        $this->expectException(RuntimeException::class);
        $ask(' ORDER BY meta_value ASC LIMIT 1');
    }

    /**
     * maybe_unserialize() PARITY, which the new reader's docblock lists as one of three
     * explicit parity clauses with get_post_meta() and which nothing measured until now:
     * replacing the call with a bare `$raw` left this whole file and the full suite green.
     *
     * The asymmetry is real rather than theoretical — the shared harness models it on purpose
     * (its stub stores a non-scalar through maybe_serialize()), because any caller passing an
     * array to update_post_meta() writes a PHP-serialized row that get_post_meta() reads back
     * transparently and a raw column read does not.
     */
    public function testASerializedMarkerRowIsUnserializedTheWayGetPostMetaUnserializesIt(): void
    {
        $post_id = $this->writtenPage();
        $marker  = str_repeat('a', 64);

        // The cache holds something else, so a pass here cannot come from the fallback.
        $this->db()->stageRows($post_id, '_pp_composition_hash', [serialize($marker)], false);
        $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition_hash'] = 'cached-not-this';

        $this->assertSame(
            $marker,
            _pp_read_composition_hash_locked($post_id),
            'a raw column read sees serialized bytes; parity with get_post_meta() needs the unserialize step'
        );
    }

    /**
     * THE UGLY CASE, pinned because the docblock is loudest about it and nothing checked it:
     * "parity includes the ugly cases, or it is not parity". Replacing the `(string)` cast
     * with a tidy `is_string($v) ? $v : ''` — which SWALLOWS the non-scalar instead of
     * reproducing the historical behaviour — left the whole suite green.
     *
     * The error handler is installed to CAPTURE the warning rather than to silence it: the
     * warning is half the behaviour being pinned, and letting it reach PHPUnit's handler
     * would move the suite's warning baseline for a case that is asserting, not failing.
     */
    public function testANonScalarMarkerRowKeepsThePreChangeCastBehaviourWarningAndAll(): void
    {
        $post_id = $this->writtenPage();
        $this->db()->stageRows($post_id, '_pp_composition_hash', [serialize(['a', 'b'])], false);

        $seen = null;
        set_error_handler(static function (int $no, string $msg) use (&$seen): bool {
            $seen = $msg;
            return true;
        });
        try {
            $marker = _pp_read_composition_hash_locked($post_id);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('Array', $marker, 'the (string) cast is the call site\'s historical spelling');
        $this->assertStringContainsString(
            'Array to string conversion',
            (string) $seen,
            'and the warning is part of the parity, not an accident to be tidied away'
        );
    }

    /**
     * THE DOCUMENTED GAP IN THE TWO OLDER READERS, pinned so the claim is checkable (#849).
     *
     * Their docblocks and pp_composition_db_handle()'s now say those two keep a TWO-clause
     * capability check where the owner has four, so "a partial handle takes their DB branch".
     * That sentence was unverifiable: no test drove a handle with get_var() and no prepare()
     * into either reader. Here it is, as found — a fatal — which is precisely why #849 exists
     * and why its fix has to move this test rather than slip past it.
     *
     * Not a regression pin: this is pre-existing behaviour that #825 deliberately did not
     * change, because reparenting them onto the four-clause owner would turn this fatal into
     * a silent cached-branch fallback, which is a behaviour decision rather than an ordering
     * alignment.
     */
    public function testAPartialHandleStillReachesTheDbBranchInTheTwoOlderReaders(): void
    {
        $post_id = $this->writtenPage();
        $partial = new class { public function get_var($q) { return null; } };

        $this->expectException(Error::class);
        _pp_read_composition_version_locked($partial, $post_id);
    }

    // ── 3. The authoring path (Section 14.1) ─────────────────────────────────

    /**
     * SECTION 14.1 — the same divergence arriving through the REAL action surface, which is
     * what an operator or the chat AI actually runs. `update_composition` reaches the same
     * writer, but through validation, normalization and the action envelope; a fix that only
     * held for direct pp_update_composition() calls would protect nobody. Both halves at
     * once: a duplicated `_pp_composition` row AND a stale cached marker.
     */
    public function testBothFixesHoldThroughTheUpdateCompositionAction(): void
    {
        $post_id  = $this->writtenPage();
        $healthy  = (string) $this->storedMeta($post_id, '_pp_composition');
        $row_hash = str_repeat('e', 64);

        $this->db()->stageRows($post_id, '_pp_composition', [$healthy, self::CORRUPT_BYTES]);
        $this->db()->stageRows($post_id, '_pp_composition_hash', [$row_hash], false);
        $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition_hash'] = str_repeat('f', 64);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->laterBands(),
        ]);
        $this->db()->settle($post_id, '_pp_composition');
        $this->db()->settle($post_id, '_pp_composition_hash');

        $this->assertTrue($result['ok'], 'the documented write route succeeds');

        $entry = $this->newestEntry($post_id);
        $this->assertFalse(pp_history_entry_is_raw($entry), 'row 0 is what gets preserved');
        $this->assertSame($this->originalBands(), $entry['composition']);
        $this->assertSame($row_hash, $entry['hash'], 'and the marker comes from the row');
    }

    // ── 4. The query shape ───────────────────────────────────────────────────

    /**
     * THE ORDERING, PINNED AS A STATIC PROPERTY OF EACH QUERY, for the four locked COMPOSITION
     * readers at once — composition, not every in-lock authoritative reader:
     * _pp_read_token_overrides_locked_strict() is a sibling in posture but reads a wp_options
     * row, where the duplicate-key question this pin is about does not arise.
     * The behavioural tests above already measure the consequence; this pin catches the
     * case they structurally cannot — a reader whose SELECT loses the ordering while the
     * double above (which decides by reading the query text) is edited in the same commit to
     * match. One pin over four readers rather than four separate pins: the invariant is "all
     * of them carry it", and stating it once is what makes that checkable.
     *
     * THE SLICE ENDS AT THE FUNCTION'S CLOSING BRACE, not at the next `function` keyword. The
     * looser anchor would swallow the NEXT reader's docblock, and these docblocks now discuss
     * the ordering at length — so a reader that lost its own ORDER BY would still match on
     * its neighbour's prose. A pin that can pass on the wrong text is not a pin.
     */
    public function testEveryLockedReaderSelectsTheSameRowGetPostMetaWouldReturn(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/wp.php');

        foreach ([
            '_pp_read_composition_version_locked',
            '_pp_read_composition_json_locked',
            '_pp_read_composition_history_locked',
            '_pp_read_composition_hash_locked',
        ] as $reader) {
            $start = strpos($source, "\nfunction " . $reader . '(');
            $this->assertIsInt($start, 'premise: ' . $reader . ' exists');
            $end = strpos($source, "\n}\n", $start);
            $this->assertIsInt($end, 'premise: ' . $reader . ' has a closing brace');
            $body = substr($source, $start, $end - $start);

            $this->assertMatchesRegularExpression(
                '/SELECT meta_value FROM \{\$wpdb->postmeta\}.*ORDER BY meta_id ASC LIMIT 1/',
                $body,
                $reader . ' must select the same row get_post_meta(single) returns'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/SELECT meta_value FROM \{\$wpdb->postmeta\}[^"]*meta_key = %s LIMIT 1/',
                $body,
                $reader . ' must not carry an unordered LIMIT 1 alongside the ordered one'
            );
        }
    }
}
