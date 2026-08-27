<?php
/**
 * tests/CompositionHistoryRawPreservationTest.php — a repair write can no longer destroy
 * the only recoverable copy of a corrupt page's stored bytes (#818).
 *
 * THE BUG THIS PINS. `pp_update_composition()` (lib/wp.php) is the ONE writer of
 * `_pp_composition`, and it pushes the PRIOR stored state onto the bounded per-post
 * history ring before overwriting — so the state a write replaces stays restorable. The
 * push was gated on `is_array($prior_items)`, the decode of the prior bytes:
 *
 *     $prior_json  = _pp_read_composition_json_locked($wpdb, $post_id);
 *     $prior_items = json_decode($prior_json, true);
 *     if (is_array($prior_items)) { ...push... }      // <-- the gate
 *     update_post_meta($post_id, '_pp_composition', wp_slash($json));
 *
 * For a `decode_error` page — stored bytes that are not decodable JSON — `json_decode()`
 * returns null, the gate is false, NOTHING is pushed, and the very next statement
 * overwrites the bytes. They are gone with nothing to restore from.
 *
 * That is the wrong way round for the whole pipeline around it. #144 classifies those
 * bytes, #725 makes the read path say "treat as corrupted, not empty", #749 refuses a
 * batch rather than roll a snapshot over them, #748 stopped six action surfaces from
 * telling an agent to populate over them — and the documented repair (`one full
 * update_composition write`, per ai-instructions/playbook-inspect-fix.md and
 * pp_inspect_composition()'s own message) then discarded the copy all of that protects.
 *
 * THE FIX (mechanism: PRESERVE, the filed issue's shape 1). The gate no longer decides
 * WHETHER to push, only WHICH ENTRY SHAPE to push:
 *
 *   prior bytes decode to a LIST    ─►  {timestamp, version, hash, composition: [...]}
 *   prior bytes do NOT              ─►  {timestamp, version, hash, raw: "<exact bytes>"}
 *
 * (#818 wrote "decode to an ARRAY" there; #841 corrected it to LIST and the three-row
 * table further down is the authoritative version. See the #841 section below.)
 *
 * ON DISK the raw half is base64 (`raw_b64`); pp_get_composition_history() hands callers
 * the decoded bytes. Not cosmetic: JSON is not a byte container, and malformed UTF-8 is
 * one of the corruptions that produces decode_error. Stored verbatim, those bytes break
 * the ring encode two different ways — `json_encode()` returns FALSE, which would persist
 * over the WHOLE ring and take every good snapshot with it, while WP's `wp_json_encode()`
 * catches that false and re-encodes through _wp_json_sanity_check(), SUCCEEDING with the
 * bytes silently coerced. Failing loudly loses ten entries; succeeding quietly returns a
 * lossy copy of the one thing the entry exists to preserve. base64 avoids both, because
 * no encoder has to touch pure ASCII. Pinned by
 * testInvalidUtf8BytesSurviveTheRingAndDoNotTakeTheRestOfItWithThem() — note that
 * tests/bootstrap.php stubs wp_json_encode as a bare json_encode, so the harness sees the
 * FALSE path; production sees the coercion path. The fix is required for both.
 *
 * A RAW entry is a preserved-bytes record, not a snapshot: it occupies a ring slot, it is
 * addressable by `history_index` / `steps_back`, `wp pp operate composition-history`
 * prints its bytes, and `restore_composition` REFUSES to replay it
 * (`history_entry_not_restorable`) instead of pretending it is a composition.
 *
 * #233 INTERACTION, STATED. #233's contract is "restore is never blocked by CURRENT
 * VALIDATION RULES — it restores verbatim and REPORTS findings". The refusal here is on a
 * different axis and does not touch that: a raw entry holds no composition to replay, so
 * the refusal is a PRECONDITION failure of the same species as the existing `no_history`
 * and `history_out_of_bounds`, not a validation-rule block. Every entry that carries a
 * `composition` still restores verbatim and still reports. #233 is unmoved.
 *
 * THE UNEXPECTED_SHAPE ASYMMETRY, AND THE HOLE THE FILED ISSUE DID NOT ENUMERATE. The
 * #818 issue states `unexpected_shape` is safe because a JSON OBJECT decodes to a PHP array
 * and so passes the gate. But `pp_get_composition_result()` also classifies as
 * `unexpected_shape` the case "valid JSON, non-list SCALAR" (`null`, `5`, `"text"`), and
 * those decode to a non-array, fail the SAME gate, and were destroyed by the SAME line.
 * The #818 fix keys on the gate itself ("the prior bytes did not decode to an array")
 * rather than on a re-run of the classifier, so both lossy sub-cases are covered by one
 * branch. Both are pinned below.
 *
 * ═══ #841 — AND "SAFE" WAS THE WRONG WORD FOR THE OBJECT HALF ═══════════════════════
 *
 * "Passes the gate" is true and was mistaken for "is handled". A JSON OBJECT decodes to a
 * PHP ASSOCIATIVE array, so `is_array()` filed it as a replayable `composition` snapshot:
 * `wp pp operate composition-history` reported `restorable: true` with a component count
 * taken off the object's KEYS, and selecting that slot drove pp_update_composition()'s
 * id-injection loop onto a string band — an uncaught TypeError (lib/wp.php:3997) on the
 * CLI action surface AND the chat batch executor, reached by following the repair route
 * the theme's own corruption message recommends. Measured by the v1.17.0 release smoke,
 * finding F1, on dev page 250.
 *
 * The composition contract is a LIST (pp_validate_composition_errors() refuses a non-list
 * container, #724), so both ends now ask the contract's own question, pp_is_list():
 *
 *   push (pp_update_composition)        decodes to a LIST      → composition snapshot
 *                                       anything else          → raw, exact bytes
 *   read (_pp_normalize_history_ring)   composition IS a list  → composition snapshot
 *                                       raw_b64                → raw, exact bytes
 *                                       composition NOT a list → raw, RE-ENCODED  (#841)
 *
 * That last row is the migration: rings written before #841 already hold mis-filed object
 * entries, and the ring meta is raw-writable besides. Reclassifying at the READER — the
 * one place that decides an entry's form from arbitrary stored bytes — is what makes the
 * refusal, the `restorable: false` listing and the three byte views fall out of the #818
 * machinery unchanged, instead of teaching a second opinion to the restore resolver and
 * leaving the CLI still calling the row restorable. STATED, because a test file that
 * asserts byte-identity everywhere else must not imply it here: a reclassified row's
 * `raw` is the ring's decoded copy re-encoded. The page's own bytes for that class were
 * never kept, so no reader can hand them back.
 *
 * NOT CLOSED BY THIS, AND DELIBERATELY SO: a prior whose CONTAINER is a valid list but
 * whose ELEMENTS are not components (`["a","b"]`, or a band whose `props` is a string)
 * still files as restorable and still fatals the same loop. Different class — the
 * classifier does not even call those pages corrupt — filed as #842.
 *
 * Coverage:
 *   the filed repro end to end — seed, corrupt, repair, recover the bytes
 *   the authoring path (Section 14.1): the repair arrives through the real
 *     `update_composition` ACTION, not a raw meta write
 *   every ring reader's behavior on a raw entry: pp_get_composition_history,
 *     pp_update_composition's own append (the round-trip that would otherwise drop it),
 *     restore_composition validate / preview / execute, and the
 *     `wp pp operate composition-history` CLI listing
 *   the unexpected_shape SCALAR sub-case, which the same gate also lost
 *   the unexpected_shape OBJECT sub-case (#841): preserved on push, quarantined on read
 *     for a legacy ring, refused on both channels, and re-persisted by the next write
 *   the list-shaped paths that must NOT move, byte-identical
 *   ring bounding with a raw entry in it
 *   the accepted counterpart: a healthy page's ring is untouched by all of this
 */

use PHPUnit\Framework\TestCase;

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

class CompositionHistoryRawPreservationTest extends TestCase
{
    /** The exact undecodable bytes from the filed repro. */
    private const CORRUPT_BYTES = '{"component":';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'connectors' => [],
            'next_id'    => 100,
            'custom_css' => '',
        ];
        WP_CLI::$lines     = [];
        WP_CLI::$warnings  = [];
        WP_CLI::$successes = [];
        // OWN THE NO-HANDLE PREMISE rather than inherit it. Most of this file exercises the
        // reads that degrade without a $wpdb handle, and since #833 other files install one;
        // resting on their tearDown would let a leak turn this file's premise off silently,
        // with every test still green. The two tests that need a handle install it themselves.
        unset($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_user_caps'], $GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function originalBands(): array
    {
        return [
            ['component' => 'hero',    'props' => ['id' => 'band-1', 'title' => 'Original hero']],
            ['component' => 'section', 'props' => ['id' => 'band-2', 'title' => 'Original section']],
        ];
    }

    /**
     * A page that WORKED and later went wrong — authored through the real writer first so
     * the version marker, the content hash and the history ring all exist, then corrupted
     * through raw meta.
     *
     * The corruption is seeded with `update_post_meta` and an undecodable JSON STRING on
     * purpose. Since #724 the action layer refuses to CREATE this state, so raw meta is
     * the only route to it — and it must be a STRING: the sanitize callback fatals on an
     * ARRAY-valued `_pp_composition` write (#768).
     *
     * @param string $bytes  The corrupt payload to store.
     * @return int  The post id.
     */
    private function corruptedPage(string $bytes = self::CORRUPT_BYTES): int
    {
        $post_id = pp_create_page('Repairable page', 'draft');
        pp_update_composition($post_id, $this->originalBands());
        // A SECOND real write, so the ring already holds a genuine composition snapshot
        // before the corruption arrives. "Recoverable" only means something if there is
        // an earlier state to step back to — and a first write on a fresh page pushes
        // nothing (it has no prior), which this fixture would otherwise hide.
        pp_update_composition($post_id, $this->laterBands());
        update_post_meta($post_id, '_pp_composition', $bytes);
        return $post_id;
    }

    private function laterBands(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'band-later', 'title' => 'Later hero']]];
    }

    private function repairBands(): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'repaired', 'title' => 'Repaired']]];
    }

    // ── 1. The filed repro, end to end ───────────────────────────────────────

    /**
     * THE MEASURED DEFECT. On the unfixed writer this test fails at the count assertion:
     * the ring still holds exactly the one entry the ORIGINAL authoring write pushed, and
     * the corrupt bytes are nowhere — the repair destroyed them.
     */
    public function testARepairWriteOverUndecodableBytesPreservesThemInTheRing(): void
    {
        $post_id = $this->corruptedPage();

        // Precondition: the page really is classified decode_error, and the ring holds
        // only the pre-authoring baseline (the empty state the first write replaced).
        $this->assertSame('decode_error', pp_get_composition_result($post_id)['error']);
        $ring_before    = pp_get_composition_history($post_id);
        $version_before = pp_get_composition_marker($post_id)['version'];
        $hash_before    = (string) get_post_meta($post_id, '_pp_composition_hash', true);

        $result = pp_update_composition($post_id, $this->repairBands());
        $this->assertTrue($result, 'the repair write itself must still land');

        $ring_after = pp_get_composition_history($post_id);
        $this->assertCount(
            count($ring_before) + 1,
            $ring_after,
            'the repair write must push exactly one entry — the measured defect pushed none'
        );

        $preserved = end($ring_after);
        $this->assertArrayNotHasKey(
            'composition',
            $preserved,
            'undecodable bytes are not a composition and must not be recorded as one'
        );
        $this->assertSame(
            self::CORRUPT_BYTES,
            $preserved['raw'],
            'the EXACT prior bytes, byte for byte — a lossy capture is not a recovery'
        );
        $this->assertTrue(pp_history_entry_is_raw($preserved));

        // PROVENANCE, asserted rather than only documented: `version` and `hash` are the
        // MARKER as it stood at push time, not a description of the payload. For a raw
        // entry the two deliberately diverge from the bytes — that divergence is the tell
        // that the corruption never went through this writer — so a regression pushing 0
        // or '' here would erase the signal without failing anything else.
        $this->assertSame(
            $version_before,
            $preserved['version'],
            'the ring records the version the write replaced'
        );
        $this->assertSame($hash_before, $preserved['hash']);
        $this->assertNotSame('', $preserved['hash'], 'a stale-but-real hash, not a blank one');
        $this->assertGreaterThan(0, $preserved['timestamp']);

        // And the repair itself really did land over them.
        $this->assertSame('Repaired', pp_get_composition($post_id)[0]['props']['title']);
    }

    /**
     * SECTION 14.1 — the repair arrives through the REAL action surface, which is what the
     * playbook actually tells an operator/agent to run, not a direct writer call.
     */
    public function testTheRepairActionSurfaceAlsoPreservesTheBytes(): void
    {
        $post_id = $this->corruptedPage();

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairBands(),
        ]);

        $this->assertTrue($result['ok'], 'the documented repair route must still succeed');
        $ring      = pp_get_composition_history($post_id);
        $preserved = end($ring);
        $this->assertSame(self::CORRUPT_BYTES, $preserved['raw']);
    }

    /**
     * THE DEFINITION OF RECOVERY: an operator who repaired the page can still read the
     * bytes back out of a shipped read-only surface. Preservation the operator cannot
     * reach is the same dead end as destruction.
     */
    public function testTheOperatorCanReadThePreservedBytesBackFromTheCli(): void
    {
        $post_id = $this->corruptedPage();
        pp_update_composition($post_id, $this->repairBands());

        (new PP_Operate_Command())->composition_history([], ['post_id' => (string) $post_id]);
        $payload = json_decode(implode("\n", WP_CLI::$lines), true);

        // Newest first, so the preserved-bytes entry is row 0.
        $row = $payload['entries'][0];
        $this->assertFalse($row['restorable'], 'the listing must say plainly that this row cannot be replayed');
        $this->assertNull($row['components'], 'there are no components to count in undecodable bytes');
        $this->assertSame(strlen(self::CORRUPT_BYTES), $row['raw_bytes']);
        $this->assertSame(self::CORRUPT_BYTES, $row['raw'], 'the bytes themselves — this is the recovery path');
        $this->assertSame(1, $row['steps_back'], 'and it is addressable, so its ring slot is not a silent gap');

        // The ordinary snapshot rows keep their shape and gain the honest counterpart flag.
        $older = $payload['entries'][1];
        $this->assertTrue($older['restorable']);
        $this->assertArrayNotHasKey('raw', $older);
    }

    // ── 2. Every ring reader, pinned on the new entry form ───────────────────

    /**
     * THE ROUND-TRIP THAT WOULD SILENTLY UNDO THE FIX. pp_update_composition() appends by
     * reading the ring back through pp_get_composition_history() and re-encoding the whole
     * list. If that reader dropped raw entries — as the unfixed one does, its clean loop
     * requiring is_array($entry['composition']) — the NEXT write after a repair would
     * delete the preserved bytes again, one write later and even more quietly.
     */
    public function testASubsequentWriteDoesNotDropThePreservedEntry(): void
    {
        $post_id = $this->corruptedPage();
        pp_update_composition($post_id, $this->repairBands());
        $after_repair = pp_get_composition_history($post_id);

        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'later', 'title' => 'Later edit']]]);
        $after_second = pp_get_composition_history($post_id);

        $this->assertCount(count($after_repair) + 1, $after_second);
        $raw_entries = array_values(array_filter($after_second, 'pp_history_entry_is_raw'));
        $this->assertCount(1, $raw_entries, 'the preserved bytes must survive later writes');
        $this->assertSame(self::CORRUPT_BYTES, $raw_entries[0]['raw']);
    }

    /**
     * restore_composition REFUSES a raw entry at every stage of the contract, with one
     * code, from the one resolver all three stages share.
     */
    public function testRestoreRefusesToReplayAPreservedRawEntry(): void
    {
        $post_id = $this->corruptedPage();
        pp_update_composition($post_id, $this->repairBands());

        $validation = pp_validate_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertInstanceOf(WP_Error::class, $validation);
        $this->assertSame('history_entry_not_restorable', $validation->get_error_code());
        $this->assertStringContainsString('composition-history', $validation->get_error_message());

        // preview never writes and never indexes a composition that is not there.
        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertInstanceOf(WP_Error::class, $preview);
        $this->assertSame('history_entry_not_restorable', $preview->get_error_code());

        // execute refuses through the canonical envelope, and writes NOTHING.
        $before = pp_get_composition_marker($post_id);
        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertFalse($result['ok']);
        $this->assertSame('history_entry_not_restorable', $result['error_code']);
        $this->assertSame($before, pp_get_composition_marker($post_id), 'a refused restore must not move the page');
        $this->assertSame('Repaired', pp_get_composition($post_id)[0]['props']['title']);
    }

    /**
     * THE OTHER SELECTOR. `history_index` takes precedence over `steps_back` and resolves
     * through its own branch, so it gets its own pin — one chokepoint, both ways in.
     */
    public function testTheAbsoluteIndexSelectorIsRefusedOnARawEntryToo(): void
    {
        $post_id = $this->corruptedPage();
        pp_update_composition($post_id, $this->repairBands());
        $raw_index = count(pp_get_composition_history($post_id)) - 1;

        $validation = pp_validate_action('restore_composition', [
            'post_id'       => $post_id,
            'history_index' => $raw_index,
        ]);
        $this->assertInstanceOf(WP_Error::class, $validation);
        $this->assertSame('history_entry_not_restorable', $validation->get_error_code());

        // …and the same absolute selector still restores the entry beside it.
        $result = pp_execute_action('restore_composition', [
            'post_id'       => $post_id,
            'history_index' => $raw_index - 1,
        ]);
        $this->assertTrue($result['ok']);
    }

    /**
     * THE HONEST DEAD END. When the raw entry is the ONLY thing in the ring there is
     * nothing to step back to — and the operator must still be told the bytes exist and
     * where to read them, because that message is now the whole recovery path.
     */
    public function testARingHoldingOnlyPreservedBytesStillNamesTheRecoveryCommand(): void
    {
        $post_id = pp_create_page('Corrupt from the start', 'draft');
        update_post_meta($post_id, '_pp_composition', self::CORRUPT_BYTES);
        pp_update_composition($post_id, $this->repairBands());

        $history = pp_get_composition_history($post_id);
        $this->assertCount(1, $history);
        $this->assertTrue(pp_history_entry_is_raw($history[0]));

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertFalse($result['ok']);
        $this->assertSame('history_entry_not_restorable', $result['error_code']);
        $this->assertStringContainsString((string) strlen(self::CORRUPT_BYTES) . ' bytes', $result['error']);
        $this->assertStringContainsString('wp pp operate composition-history --post_id=' . $post_id, $result['error']);
    }

    /**
     * THE RAW ENTRY IS A WALL, NOT A HOLE. It occupies its ring slot, so an operator can
     * still step PAST it to the last good composition — the state before the corruption.
     * #233 keeps holding for every entry that carries one.
     */
    public function testTheOperatorCanStillStepPastARawEntryToTheLastGoodComposition(): void
    {
        $post_id = $this->corruptedPage();
        pp_update_composition($post_id, $this->repairBands());

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 2]);

        $this->assertTrue($result['ok'], 'stepping past the preserved bytes must restore normally');
        $this->assertSame(
            ['band-1', 'band-2'],
            array_column(array_column(pp_get_composition($post_id), 'props'), 'id'),
            'the last good composition comes back verbatim (#233)'
        );
        $this->assertArrayHasKey('findings', $result, 'and restore still REPORTS (#233)');
    }

    /**
     * A PRESERVED ENTRY EVICTS IN RING ORDER — no sooner, no later.
     *
     * The first cut of this test only asserted the ring was still `$max` long after
     * enough writes to overflow it, which the UNFIXED writer satisfies too (it pushed
     * nothing, so the ring was bounded trivially) and which said nothing about the raw
     * entry at all — by the assertion point it had already been evicted. Both halves are
     * pinned now: present and byte-exact while in-window, gone once the window passes.
     */
    public function testAPreservedEntryRidesTheRingAndEvictsInOrder(): void
    {
        $post_id = $this->corruptedPage();
        $max     = pp_composition_history_max();

        // The repair pushes the raw entry; keep the total inside the window.
        pp_update_composition($post_id, $this->repairBands());
        $in_window = array_values(array_filter(pp_get_composition_history($post_id), 'pp_history_entry_is_raw'));
        $this->assertCount(1, $in_window, 'precondition: the preserved entry is on the ring');

        for ($i = 0; $i < $max - 3; $i++) {
            pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'w' . $i, 'title' => 'Write ' . $i]]]);
        }
        $still = array_values(array_filter(pp_get_composition_history($post_id), 'pp_history_entry_is_raw'));
        $this->assertCount(1, $still, 'it must not be dropped early — it is a slot like any other');
        $this->assertSame(self::CORRUPT_BYTES, $still[0]['raw'], 'and it is still byte-exact after N re-encodes');

        // Now push past the window.
        for ($i = 0; $i < $max + 2; $i++) {
            pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'x' . $i, 'title' => 'Past ' . $i]]]);
        }
        $history = pp_get_composition_history($post_id);
        $this->assertCount($max, $history, 'the ring stays bounded');
        $this->assertSame([], array_filter($history, 'pp_history_entry_is_raw'), 'and it evicts like any other slot');
    }

    /**
     * THE PRODUCTION READ BRANCH, WHICH NO OTHER TEST IN THIS FILE REACHES.
     *
     * `_pp_read_composition_json_locked()` has two branches. Every other test here runs
     * the get_post_meta fallback, because tests/bootstrap.php installs no global $wpdb.
     * WordPress runs the OTHER one — a direct SELECT — and the two used to disagree about
     * the empty string: the SELECT returns '' for an existing-but-empty `_pp_composition`
     * row, while the fallback mapped '' to null.
     *
     * That disagreement was harmless while the caller only asked "does this decode to an
     * array?" (both answers meant no push). The moment an undecodable prior started being
     * PRESERVED, it became a 0-byte raw entry minted on production installs only: it eats
     * a ring slot and makes steps_back=1 — the selector the chat's undo link uses — refuse
     * with "0 bytes". Empty rows are not exotic; the `_pp_composition` sanitize_callback
     * in lib/admin.php rewrites any non-array payload to ''.
     */
    public function testAnEmptyStoredRowIsNoPriorStateOnTheProductionReadPath(): void
    {
        $post_id = pp_create_page('Empty row', 'draft');
        pp_update_composition($post_id, $this->originalBands());
        $ring_before = pp_get_composition_history($post_id);

        // A $wpdb whose composition SELECT answers with an empty row, as MySQL does.
        $GLOBALS['wpdb'] = new class {
            public string $postmeta = 'wp_postmeta';
            public string $last_error = '';
            public function prepare($query, ...$args) { return $query . '|' . implode('|', $args); }
            public function get_var($query) {
                // The lock MUST be granted, or this test would pass for the wrong reason:
                // a refused lock skips the whole write, so "nothing was pushed" would be
                // true even with the empty-row bug present.
                if (strpos($query, 'GET_LOCK') !== false) { return '1'; }
                if (strpos($query, '_pp_composition_version') !== false) { return '1'; }
                return ''; // the _pp_composition row: present, empty
            }
            public function query($q) { return 1; }
            public function get_row($q, $o = null) { return null; }
        };
        try {
            pp_update_composition($post_id, $this->repairBands());
        } finally {
            unset($GLOBALS['wpdb']);
        }

        $this->assertSame(
            $ring_before,
            pp_get_composition_history($post_id),
            'an empty stored row carries no bytes worth preserving, so it must push NOTHING'
        );
    }

    /**
     * AN ENTRY CARRYING BOTH KEYS reads as the replayable half, as the normalizer's
     * comment claims. This writer never produces one; a hand-edit or a future writer
     * could, and the branch decides whether such a row is replayed or refused.
     */
    public function testAnEntryCarryingBothShapesReadsAsTheComposition(): void
    {
        $post_id = pp_create_page('Both keys', 'draft');
        update_post_meta($post_id, '_pp_composition_history', wp_json_encode([[
            'timestamp'   => 5,
            'version'     => 5,
            'hash'        => 'h',
            'composition' => [['component' => 'hero', 'props' => ['id' => 'both']]],
            'raw_b64'     => base64_encode('ignored'),
        ]]));

        $history = pp_get_composition_history($post_id);
        $this->assertCount(1, $history);
        $this->assertFalse(pp_history_entry_is_raw($history[0]));
        $this->assertArrayNotHasKey('raw', $history[0]);
        $this->assertTrue(pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1])['ok']);
    }

    /**
     * THE REFUSAL HAS TO REACH THE CALLER THAT ACTS ON IT (#719's rule, mirrored from
     * CreatePageWriteFailureTest::testTheRefusalContractReachesTheChatAIsActionCatalog).
     * pp_ai_system_prompt() builds the chat AI's action catalog from each action's
     * `description`; nothing at runtime reads `semantics`. The chat's undo link is the
     * surface most likely to select a preserved-bytes slot.
     */
    public function testTheRefusalContractReachesTheChatAIsActionCatalog(): void
    {
        $prompt = pp_ai_system_prompt();

        $this->assertStringContainsString('restore_composition', $prompt, 'precondition: the action is catalogued');
        $this->assertStringContainsString('history_entry_not_restorable', $prompt, 'the code a caller keys on must be teachable');
        $this->assertStringContainsString('composition-history', $prompt, 'and so must the route to the bytes');

        // #841 RIDES THE SAME RULE. The class that changed sides is the one the chat's undo
        // link is most likely to select, and pp_ai_system_prompt() renders `description`
        // (lib/ai-context.php builds each catalog line from `{$def['description']}`) while
        // NOTHING at runtime reads `semantics`. Stating the object sub-case only in
        // `semantics` would leave the one caller that hits it untaught — which is exactly
        // the #719 failure this pin exists to prevent, so the new clause is asserted here
        // rather than trusted to a code review of the right field.
        $this->assertStringContainsString(
            'JSON OBJECT',
            $prompt,
            'the newly-refused class must reach the catalog the chat AI actually reads'
        );
    }

    /**
     * THE PRESERVED BYTES REACH THE TERMINAL ESCAPED. `raw` is arbitrary
     * corruption-controlled content printed to stdout. It is safe today only because
     * _pp_cli_emit_json() is the single sink (#717) and escapes C0 controls, non-ASCII
     * and DEL. Nothing pinned that property for the first field that deliberately prints
     * undecodable bytes, so a future caller passing $raw_unicode=true would regress it
     * with every other test still green.
     */
    public function testThePreservedBytesAreEscapedOnTheWayToTheTerminal(): void
    {
        $nasty   = "\x1b[31mRED\x7f\xe2\x80\xaeoverride";
        $post_id = $this->corruptedPage($nasty);
        pp_update_composition($post_id, $this->repairBands());

        (new PP_Operate_Command())->composition_history([], ['post_id' => (string) $post_id]);
        $emitted = implode("\n", WP_CLI::$lines);

        $this->assertStringNotContainsString("\x1b", $emitted, 'no raw ESC — that is terminal injection');
        $this->assertStringNotContainsString("\x7f", $emitted, 'no raw DEL');
        $this->assertStringNotContainsString("\xe2\x80\xae", $emitted, 'no raw RLO override');
        // …and the value still round-trips intact for the operator.
        $this->assertSame($nasty, json_decode($emitted, true)['entries'][0]['raw']);
    }

    // ── 3. The paths that must NOT move ──────────────────────────────────────

    /**
     * A LIST-SHAPED PRIOR IS STILL A SNAPSHOT, BYTE-IDENTICAL — including one carrying a
     * nested JSON OBJECT inside a band. #841 moved the CONTAINER test to pp_is_list(); it
     * must not have moved anything about what a band may contain. `style` and `props` are
     * maps by design, and a fix that reached into bands would turn every styled page's
     * ring entry into preserved bytes.
     */
    public function testAListShapedPriorWithNestedObjectsIsStillSnapshottedVerbatim(): void
    {
        $nested  = [['component' => 'hero', 'props' => ['id' => 'n-1', 'title' => 'T'], 'style' => ['bg' => '#fff']]];
        $post_id = $this->corruptedPage((string) wp_json_encode($nested));

        pp_update_composition($post_id, $this->repairBands());

        $ring  = pp_get_composition_history($post_id);
        $entry = end($ring);
        $this->assertArrayNotHasKey('raw', $entry, 'a LIST prior is still a composition snapshot, not raw bytes');
        $this->assertSame($nested, $entry['composition'], 'byte-identical, nested maps and all');
        $this->assertFalse(pp_history_entry_is_raw($entry));
        $this->assertTrue(pp_execute_action('restore_composition', [
            'post_id' => $post_id, 'steps_back' => 1,
        ])['ok'], 'and it still replays');
    }

    /**
     * THE EMPTY COMPOSITION IS A LIST. `[]` passes pp_is_list() — pinned because the PHP
     * 8.0 fallback needs an explicit empty guard to agree (see _pp_is_list_fallback), and
     * a regression there would turn every cleared page's prior into preserved bytes and
     * refuse the undo that restores it.
     */
    public function testAnEmptyPriorCompositionIsStillARestorableSnapshot(): void
    {
        $post_id = $this->corruptedPage('[]');

        pp_update_composition($post_id, $this->repairBands());

        $ring  = pp_get_composition_history($post_id);
        $entry = end($ring);
        $this->assertSame([], $entry['composition'], 'an empty composition is a composition');
        $this->assertFalse(pp_history_entry_is_raw($entry));
        $this->assertTrue(pp_execute_action('restore_composition', [
            'post_id' => $post_id, 'steps_back' => 1,
        ])['ok']);
    }

    /**
     * THE SUB-CASE THE FILED ISSUE DID NOT ENUMERATE. `null` is valid JSON, classifies as
     * `unexpected_shape`, decodes to a NON-array, and so failed the very same gate — it
     * was destroyed exactly like a decode_error prior. The fix keys on the gate, so it is
     * covered; without this pin the fix would ship with a known hole at the fixed line.
     */
    public function testAValidJsonScalarPriorIsPreservedToo(): void
    {
        $post_id = $this->corruptedPage('null');
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error']);

        pp_update_composition($post_id, $this->repairBands());

        $ring  = pp_get_composition_history($post_id);
        $entry = end($ring);
        $this->assertSame('null', $entry['raw'], 'the literal bytes, preserved — not the decoded PHP null');
    }

    /**
     * THE CORRUPTION CLASS THAT NEARLY TOOK THE WHOLE RING DOWN. Surfaced by this
     * iteration's outside-voice pass, verified against PHP: `json_encode()` returns FALSE
     * on invalid UTF-8, and pp_get_composition_result() names malformed UTF-8 as a
     * decode_error cause. A first cut of this fix stored the bytes verbatim, so the
     * wp_json_encode() of the ENTIRE ring would have failed and the meta write below it
     * would have clobbered all ten entries — the fix for losing one page's bytes losing
     * every snapshot on it, for precisely the corruption it was written for.
     *
     * base64 on the stored side closes it. This pins the whole chain over bytes no JSON
     * encoder will take: preserved exactly, surviving a later write, readable back, and
     * the good snapshot beside them still restorable.
     */
    public function testInvalidUtf8BytesSurviveTheRingAndDoNotTakeTheRestOfItWithThem(): void
    {
        $invalid = "{\"component\":\"h\xC3\x28ero\"";
        $this->assertFalse(json_encode(['raw' => $invalid]), 'premise: these bytes break json_encode');

        $post_id = $this->corruptedPage($invalid);
        pp_update_composition($post_id, $this->repairBands());

        $history = pp_get_composition_history($post_id);
        $this->assertCount(2, $history, 'the good snapshot beside the preserved bytes must survive');
        $this->assertSame($invalid, $history[1]['raw'], 'byte for byte, through a JSON-encoded ring');

        // A later write re-persists the ring; the bytes must survive that round-trip too.
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'l', 'title' => 'L']]]);
        $after = pp_get_composition_history($post_id);
        $raw   = array_values(array_filter($after, 'pp_history_entry_is_raw'));
        $this->assertSame($invalid, $raw[0]['raw']);

        // The good snapshot is still restorable — the ring was not damaged.
        $this->assertTrue(pp_execute_action('restore_composition', [
            'post_id'       => $post_id,
            'history_index' => 0,
        ])['ok']);
    }

    /**
     * THE LOSSLESS RECOVERY CHANNEL. `raw` goes through the CLI's
     * JSON_INVALID_UTF8_SUBSTITUTE encoder, so for this corruption class it comes back
     * SUBSTITUTED. `raw_base64` is the field an operator actually recovers from, and
     * `raw_sha256` is how they prove what they recovered.
     */
    public function testTheCliHandsBackInvalidUtf8BytesThroughAnEncodingThatSurvives(): void
    {
        $invalid = "\xC3\x28\xA0\xA1truncated";
        $post_id = $this->corruptedPage($invalid);
        pp_update_composition($post_id, $this->repairBands());

        (new PP_Operate_Command())->composition_history([], ['post_id' => (string) $post_id]);
        $row = json_decode(implode("\n", WP_CLI::$lines), true)['entries'][0];

        $this->assertSame($invalid, base64_decode($row['raw_base64'], true), 'the recovery channel is exact');
        $this->assertSame(hash('sha256', $invalid), $row['raw_sha256']);
        $this->assertSame(strlen($invalid), $row['raw_bytes']);
    }

    /** A ring row whose stored payload is not real base64 is dropped, not half-decoded. */
    public function testARowWithAnUndecodableBase64PayloadIsDropped(): void
    {
        $post_id = pp_create_page('Junk base64', 'draft');
        update_post_meta($post_id, '_pp_composition_history', wp_json_encode([
            ['timestamp' => 1, 'version' => 1, 'hash' => 'h', 'raw_b64' => '!!!not base64!!!'],
            ['timestamp' => 2, 'version' => 2, 'hash' => 'h', 'raw_b64' => base64_encode('kept')],
        ]));

        $history = pp_get_composition_history($post_id);
        $this->assertCount(1, $history);
        $this->assertSame('kept', $history[0]['raw']);
    }

    /** A healthy page's ring is exactly what it always was. */
    public function testAHealthyPagesRingIsUntouched(): void
    {
        $post_id = pp_create_page('Healthy', 'draft');
        pp_update_composition($post_id, $this->originalBands());
        pp_update_composition($post_id, $this->repairBands());

        // Two writes on a fresh page leave ONE entry: the first write has no prior state
        // to preserve, so it pushes nothing (unchanged by this fix).
        $history = pp_get_composition_history($post_id);
        $this->assertCount(1, $history);
        foreach ($history as $entry) {
            $this->assertFalse(pp_history_entry_is_raw($entry));
            $this->assertSame(['timestamp', 'version', 'hash', 'composition'], array_keys($entry));
        }
        $this->assertSame(
            ['band-1', 'band-2'],
            array_column(array_column($history[0]['composition'], 'props'), 'id')
        );
    }

    /** A malformed ring row that is neither shape is still dropped, as it always was. */
    public function testAnEntryCarryingNeitherShapeIsStillDropped(): void
    {
        $post_id = pp_create_page('Junk ring', 'draft');
        update_post_meta($post_id, '_pp_composition_history', wp_json_encode([
            ['timestamp' => 1, 'version' => 1, 'hash' => 'h'],
            ['timestamp' => 2, 'version' => 2, 'hash' => 'h', 'raw_b64' => ['not', 'a', 'string']],
            ['timestamp' => 3, 'version' => 3, 'hash' => 'h', 'raw_b64' => base64_encode('kept')],
        ]));

        $history = pp_get_composition_history($post_id);
        $this->assertCount(1, $history);
        $this->assertSame('kept', $history[0]['raw']);
    }

    // ── 4. #841 — the OBJECT-shaped class, on both ends of the ring ──────────

    /** The exact object-shaped bytes from the release-smoke F1 repro (dev page 250). */
    private const OBJECT_BYTES = '{"component":"hero","props":{"title":"obj-shaped"}}';

    /**
     * The same shape, stored NON-CANONICALLY: extra spacing, a non-ASCII character and a
     * forward slash. Every push-side assertion uses THIS one, and the choice is the pin.
     *
     * OBJECT_BYTES happens to be byte-identical to its own re-encode, so a test asserting
     * `raw === OBJECT_BYTES` after a push cannot tell "the exact stored bytes were
     * preserved" (what the fixed push does) from "the decoded object was re-encoded" (what
     * the reader's #841 compat branch does to a row the BROKEN push filed). Both produce
     * the same string, so such a test passes with the push fix reverted — the two halves of
     * this fix would then have one pin between them. These bytes survive the round trip
     * only if the push really kept them, so the assertion discriminates.
     */
    private const OBJECT_BYTES_UNCANONICAL =
        '{"component": "hero", "props": {"title": "café", "link_url": "/about/us"}}';

    /**
     * What a LEGACY mis-filed row's decoded object must re-encode to. Carries a non-ASCII
     * character and a forward slash on purpose: without
     * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES the reclassification would hand the
     * operator `café` and `\/about\/us`, which is not what the page held and not what
     * every other composition encode in lib/wp.php produces. An all-ASCII fixture leaves
     * those flags unpinned.
     */
    private const OBJECT_BYTES_LEGACY = '{"component":"hero","props":{"title":"café","link_url":"/about/us"}}';

    /**
     * THE MEASURED DEFECT, PUSH SIDE. On the unfixed writer this fails at the FIRST
     * assertion: the entry carries `composition` holding the decoded object, which is what
     * made the listing call it restorable and the replay fatal.
     */
    public function testAnObjectShapedPriorIsPreservedAsBytesInsteadOfFiledAsASnapshot(): void
    {
        $post_id = $this->corruptedPage(self::OBJECT_BYTES_UNCANONICAL);
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error'], 'precondition');

        pp_update_composition($post_id, $this->repairBands());

        // ASSERT THE STORED ROW, NOT ONLY THE READ-BACK RING. Everything else in this
        // section reads through pp_get_composition_history(), which since #841 RECLASSIFIES
        // a mis-filed row on the way out — so a ring read alone cannot tell a fixed push
        // from a broken push whose row the reader repaired. Reverting the push key leaves
        // every normalized assertion green and only this one red, which is the difference
        // between pinning half the fix and pinning it.
        $stored = json_decode((string) get_post_meta($post_id, '_pp_composition_history', true), true);
        $row    = end($stored);
        $this->assertArrayNotHasKey('composition', $row, 'the PUSH must not file an object as a snapshot');
        $this->assertSame(self::OBJECT_BYTES_UNCANONICAL, base64_decode($row['raw_b64'], true));

        $ring  = pp_get_composition_history($post_id);
        $entry = end($ring);
        $this->assertArrayNotHasKey(
            'composition',
            $entry,
            'a JSON object is not a composition and must never be filed as one'
        );
        $this->assertTrue(pp_history_entry_is_raw($entry));
        $this->assertSame(
            self::OBJECT_BYTES_UNCANONICAL,
            $entry['raw'],
            'a CURRENT push is byte-exact for this class like every other'
        );
        // The fixture earns that assertion: these bytes are NOT their own re-encode, so
        // equality here means preservation and could not be satisfied by the compat branch.
        $this->assertNotSame(
            self::OBJECT_BYTES_UNCANONICAL,
            json_encode(json_decode(self::OBJECT_BYTES_UNCANONICAL, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'premise: the fixture distinguishes preserved bytes from a re-encode'
        );
        $this->assertSame('Repaired', pp_get_composition($post_id)[0]['props']['title'], 'the repair still landed');
    }

    /**
     * SECTION 14.1 — through the real `update_composition` ACTION, which is what the
     * corruption message actually tells an operator or agent to run.
     */
    public function testTheRepairActionPreservesAnObjectShapedPriorToo(): void
    {
        $post_id = $this->corruptedPage(self::OBJECT_BYTES_UNCANONICAL);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->repairBands(),
        ]);

        $this->assertTrue($result['ok'], 'the documented repair route must still succeed');
        $ring = pp_get_composition_history($post_id);
        $this->assertSame(self::OBJECT_BYTES_UNCANONICAL, end($ring)['raw']);
    }

    /**
     * THE FALSE REPORT THE SMOKE READ. The listing said `components: 2, restorable: true`
     * for a two-KEY object — a count of the object's keys, and an invitation to a fatal.
     * It now says what the row is, and hands back all three byte views.
     */
    public function testTheCliListingStopsCallingAnObjectShapedRowRestorable(): void
    {
        $post_id = $this->corruptedPage(self::OBJECT_BYTES_UNCANONICAL);
        pp_update_composition($post_id, $this->repairBands());

        (new PP_Operate_Command())->composition_history([], ['post_id' => (string) $post_id]);
        $row = json_decode(implode("\n", WP_CLI::$lines), true)['entries'][0];

        $this->assertFalse($row['restorable'], 'the measured defect reported true here');
        $this->assertNull($row['components'], 'the measured defect counted the object KEYS here');
        $this->assertSame(self::OBJECT_BYTES_UNCANONICAL, $row['raw']);
        $this->assertSame(self::OBJECT_BYTES_UNCANONICAL, base64_decode($row['raw_base64'], true));
        $this->assertSame(hash('sha256', self::OBJECT_BYTES_UNCANONICAL), $row['raw_sha256']);
        $this->assertSame(strlen(self::OBJECT_BYTES_UNCANONICAL), $row['raw_bytes']);
    }

    /**
     * THE DOCUMENTED REFUSAL FINALLY FIRES FOR THIS CLASS, at all three stages of the
     * restore contract, from the one resolver they share. On the unfixed tree every one of
     * these calls raises an uncaught TypeError instead of returning.
     */
    public function testRestoreRefusesAnObjectShapedRowAtEveryStage(): void
    {
        $post_id = $this->corruptedPage(self::OBJECT_BYTES_UNCANONICAL);
        pp_update_composition($post_id, $this->repairBands());
        $before = pp_get_composition_marker($post_id);

        $validation = pp_validate_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertInstanceOf(WP_Error::class, $validation);
        $this->assertSame('history_entry_not_restorable', $validation->get_error_code());

        $preview = pp_preview_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertInstanceOf(WP_Error::class, $preview);
        $this->assertSame('history_entry_not_restorable', $preview->get_error_code());

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
        $this->assertFalse($result['ok']);
        $this->assertSame('history_entry_not_restorable', $result['error_code']);
        $this->assertStringContainsString(
            'wp pp operate composition-history --post_id=' . $post_id,
            $result['error'],
            'and it still names the route to the bytes'
        );
        $this->assertSame($before, pp_get_composition_marker($post_id), 'a refused restore must not move the page');
    }

    /**
     * THE SECOND CHANNEL THE SMOKE FATALED. The chat's "Undo these changes" link runs the
     * same action through pp_ai_execute_batch(), which has no fatal handler of its own —
     * an uncaught TypeError there takes the AJAX request down, not one step of it.
     */
    public function testTheChatBatchExecutorGetsTheRefusalRatherThanAFatal(): void
    {
        // A DATABASE HANDLE, installed for this test alone rather than in setUp: the batch
        // gate reads the postmeta row and fails closed without one (#833), so with no $wpdb
        // this batch would be refused before its step ever ran and would prove nothing about
        // the fatal it exists to catch. Only this test runs a batch, and the rest of the file
        // deliberately exercises the no-handle read path.
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();

        $post_id = $this->corruptedPage(self::OBJECT_BYTES_UNCANONICAL);
        pp_update_composition($post_id, $this->repairBands());

        $batch = pp_ai_execute_batch(
            [['type' => 'action', 'name' => 'restore_composition', 'params' => [
                'post_id' => $post_id, 'steps_back' => 1,
            ]]],
            [$post_id => pp_get_composition_marker($post_id)['version']]
        );

        $this->assertFalse($batch['ok'], 'the batch reports a refused step, it does not crash');
        $this->assertSame('history_entry_not_restorable', $batch['steps'][0]['error_code']);
        $this->assertSame(0, $batch['failed_at'], 'and the batch stops on it like any refused step');
    }

    // ── 4b. Rings written BEFORE the fix ─────────────────────────────────────

    /**
     * Seeds a ring the pre-#841 writer would have produced: an object-shaped prior filed as
     * a `composition` entry, beside a genuine snapshot. Written through raw meta because the
     * fixed writer can no longer produce this row — which is the point. Rings on live
     * installs (the smoke's page 250) hold exactly this, and `_pp_composition_history` is
     * raw-writable by anything else on the site besides.
     *
     * @return int  The post id.
     */
    private function legacyMisfiledRing(): int
    {
        $post_id = pp_create_page('Legacy misfiled ring', 'draft');
        // wp_slash() like the production writer does: update_post_meta() UNSLASHES on the
        // way in (tests/bootstrap.php models WP core), so an unslashed payload silently
        // loses the backslash of every `\uXXXX` escape — which for a non-ASCII fixture
        // means the seeded row never holds what the test says it holds.
        update_post_meta($post_id, '_pp_composition_history', wp_slash((string) wp_json_encode([
            ['timestamp' => 11, 'version' => 1, 'hash' => 'h1', 'composition' => $this->originalBands()],
            ['timestamp' => 22, 'version' => 2, 'hash' => 'h2', 'composition' => json_decode(self::OBJECT_BYTES_LEGACY, true)],
        ])));
        pp_update_composition($post_id, $this->repairBands());
        return $post_id;
    }

    /**
     * THE COMPAT ANSWER, AND THE HONEST END STATE THE ISSUE ASKED FOR: selecting an
     * already-stored mis-filed row must not fatal. It is quarantined at the reader, so the
     * refusal comes from the same resolver and carries the same code as every other
     * unreplayable slot.
     */
    public function testALegacyMisfiledRowIsQuarantinedOnReadInsteadOfFataling(): void
    {
        $post_id = $this->legacyMisfiledRing();

        $history = pp_get_composition_history($post_id);
        $legacy  = $history[1];
        $this->assertTrue(pp_history_entry_is_raw($legacy), 'the reader reclassifies the mis-filed row');
        $this->assertArrayNotHasKey('composition', $legacy);
        $this->assertSame(
            self::OBJECT_BYTES_LEGACY,
            $legacy['raw'],
            'the object comes back re-encoded, with the same flags every other composition encode uses'
        );
        $this->assertSame(2, $legacy['version'], 'and the row keeps its provenance');
        $this->assertSame('h2', $legacy['hash']);
        $this->assertSame(11, $history[0]['timestamp'], 'the snapshot beside it is untouched');

        $result = pp_execute_action('restore_composition', [
            'post_id' => $post_id, 'history_index' => 1,
        ]);
        $this->assertFalse($result['ok'], 'the measured defect raised an uncaught TypeError here');
        $this->assertSame('history_entry_not_restorable', $result['error_code']);

        // The slot stays ADDRESSABLE: the good snapshot before it still replays.
        $this->assertTrue(pp_execute_action('restore_composition', [
            'post_id' => $post_id, 'history_index' => 0,
        ])['ok']);
        $this->assertSame(
            ['band-1', 'band-2'],
            array_column(array_column(pp_get_composition($post_id), 'props'), 'id')
        );
    }

    /** The legacy row reaches the operator through the listing as what it is. */
    public function testALegacyMisfiledRowListsAsNonRestorableWithItsBytes(): void
    {
        $post_id = $this->legacyMisfiledRing();

        (new PP_Operate_Command())->composition_history([], ['post_id' => (string) $post_id]);
        $entries = json_decode(implode("\n", WP_CLI::$lines), true)['entries'];

        // Newest first, and the fixture's page had no prior composition of its own, so its
        // write pushed nothing: the mis-filed row is still the newest of the two seeded.
        $row = $entries[0];
        $this->assertSame(1, $row['history_index'], 'precondition: this is the mis-filed slot');
        $this->assertFalse($row['restorable'], 'the measured defect reported true here');
        $this->assertNull($row['components']);
        $this->assertSame(self::OBJECT_BYTES_LEGACY, base64_decode($row['raw_base64'], true));
        $this->assertTrue($entries[1]['restorable'], 'the snapshot beside it is still replayable');
    }

    /**
     * QUARANTINE IS DURABLE, NOT JUST A READ-TIME VIEW. The append path reads the ring
     * through the same normalizer and re-persists it through
     * _pp_history_entries_for_storage(), so the next write on the page stores the row as
     * `raw_b64` — the form every reader agrees on. A normalizer output that could not be
     * re-stored would break the ring's own round-trip invariant.
     */
    public function testTheNextWriteRePersistsALegacyMisfiledRowAsPreservedBytes(): void
    {
        $post_id = $this->legacyMisfiledRing();

        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['id' => 'later', 'title' => 'Later']]]);

        $stored = json_decode((string) get_post_meta($post_id, '_pp_composition_history', true), true);
        $row    = $stored[1];
        $this->assertArrayNotHasKey('composition', $row, 'the defective row is gone from storage');
        $this->assertSame(self::OBJECT_BYTES_LEGACY, base64_decode($row['raw_b64'], true));
        $this->assertSame(2, $row['version'], 'provenance survives the migration');

        // And it still reads and refuses the same way after the round-trip.
        $history = pp_get_composition_history($post_id);
        $this->assertTrue(pp_history_entry_is_raw($history[1]));
        $this->assertSame(self::OBJECT_BYTES_LEGACY, $history[1]['raw']);
    }

    /**
     * THE BOTH-KEYS ROW, NARROWED. `testAnEntryCarryingBothShapesReadsAsTheComposition`
     * above pins the documented preference for the replayable half. When that half is a
     * JSON OBJECT it is not replayable, so the row reads as the preserved BYTES instead —
     * preferring the object would trade a real recovery for a refusal, and preferring it
     * while calling it restorable is the defect itself.
     */
    public function testABothKeysRowWithANonListCompositionHandsBackThePreservedBytes(): void
    {
        $post_id = pp_create_page('Both keys, object half', 'draft');
        update_post_meta($post_id, '_pp_composition_history', wp_json_encode([[
            'timestamp'   => 5,
            'version'     => 5,
            'hash'        => 'h',
            'composition' => json_decode(self::OBJECT_BYTES, true),
            'raw_b64'     => base64_encode('the real prior bytes'),
        ]]));

        $history = pp_get_composition_history($post_id);
        $this->assertCount(1, $history);
        $this->assertTrue(pp_history_entry_is_raw($history[0]));
        $this->assertSame(
            'the real prior bytes',
            $history[0]['raw'],
            'exact bytes beat a re-encode when the row carries both'
        );
    }

    /**
     * A ring row whose non-list `composition` cannot be re-encoded KEEPS ITS SLOT as a
     * zero-byte preserved-bytes entry, rather than vanishing.
     *
     * DROPPING IT WOULD NOT BE A READ-TIME DEGRADATION, WHICH IS WHY THIS IS PINNED.
     * pp_update_composition() rebuilds the STORED ring from what the normalizer returns, so
     * a row this reader drops is deleted from the database by the next composition write —
     * a silent, permanent destruction of a recorded prior state, which is the #818 behavior
     * this whole area exists to end. It would also renumber `history_index` / `steps_back`
     * for the neighbouring slots, and _pp_resolve_history_target() promises an unreplayable
     * slot "stays ADDRESSABLE ... so history_index and steps_back keep counting writes
     * truthfully" — an operator who read an index from an earlier listing would silently
     * restore a different entry.
     *
     * REACHABILITY, stated correctly because the obvious answer is wrong: NOT depth. The
     * branch encodes the payload standalone, shallower than the ring decode that accepted
     * it, so on the JSON-string path the budget is strictly larger and JSON_ERROR_DEPTH
     * cannot reach it. The live path is the other form _pp_decode_history_ring() accepts —
     * an ALREADY-DECODED meta array (get_post_meta unserializes; the locked reader calls
     * maybe_unserialize), which never passed a json_decode and can hold a value no encoder
     * will take. The fixture is exactly that: a NAN, seeded straight into the store rather
     * than through update_post_meta(), because a value that cannot be encoded cannot be
     * written as JSON in the first place.
     */
    public function testANonListCompositionThatCannotBeReEncodedKeepsItsSlot(): void
    {
        $post_id = pp_create_page('Unencodable object half', 'draft');
        $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition_history'] = [[
            'timestamp'   => 1,
            'version'     => 1,
            'hash'        => 'h',
            'composition' => ['a' => NAN],
        ], [
            'timestamp'   => 2,
            'version'     => 2,
            'hash'        => 'h',
            'raw_b64'     => base64_encode('kept'),
        ]];

        $history = pp_get_composition_history($post_id);
        $this->assertCount(2, $history, 'the slot survives so the ring does not renumber');
        $this->assertTrue(pp_history_entry_is_raw($history[0]), 'and it refuses like any raw row');
        $this->assertSame('', $history[0]['raw'], 'with no payload, because none could be rendered');
        $this->assertSame(1, $history[0]['version'], 'provenance is still readable');
        $this->assertSame('kept', $history[1]['raw'], 'the row beside it is untouched');

        // The refusal still fires and still names the recovery command.
        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'history_index' => 0]);
        $this->assertFalse($result['ok']);
        $this->assertSame('history_entry_not_restorable', $result['error_code']);
    }

    /**
     * BOTH KEYS, AND THE BYTES ARE UNREADABLE. The reclassification is ordered after
     * `raw_b64` so real preserved bytes win — but only when they are real. A strict base64
     * failure used to consume the row and yield nothing, losing BOTH halves of the one
     * subsystem whose job is losing nothing. The object is the only recovery left, so the
     * row falls through to the re-encode instead.
     */
    public function testABothKeysRowWithUnreadableBytesFallsBackToTheObject(): void
    {
        $post_id = pp_create_page('Both keys, bad base64', 'draft');
        update_post_meta($post_id, '_pp_composition_history', wp_slash((string) wp_json_encode([[
            'timestamp'   => 5,
            'version'     => 5,
            'hash'        => 'h',
            'composition' => json_decode(self::OBJECT_BYTES_LEGACY, true),
            'raw_b64'     => '!!!not base64!!!',
        ]])));

        $history = pp_get_composition_history($post_id);
        $this->assertCount(1, $history, 'the row is not dropped');
        $this->assertSame(
            self::OBJECT_BYTES_LEGACY,
            $history[0]['raw'],
            'a partial recovery beats losing both halves'
        );
    }

    /**
     * THE RECLASSIFIED PAYLOAD REACHES THE TERMINAL ESCAPED TOO. The sibling pin above
     * covers the decode_error PUSH path; this covers the #841 read path, which is where
     * escaping is most load-bearing — the re-encode runs with JSON_UNESCAPED_UNICODE, so a
     * legacy object carrying a bidi override holds those code points as raw bytes in `raw`
     * before _pp_cli_emit_json() sees them.
     */
    public function testAReclassifiedPayloadIsEscapedOnTheWayToTheTerminal(): void
    {
        $nasty   = "\x1b[31mRED\x7f\xe2\x80\xaeoverride";
        $post_id = pp_create_page('Nasty legacy object', 'draft');
        update_post_meta($post_id, '_pp_composition_history', wp_slash((string) wp_json_encode([[
            'timestamp'   => 5,
            'version'     => 5,
            'hash'        => 'h',
            'composition' => ['component' => 'hero', 'props' => ['title' => $nasty]],
        ]])));

        (new PP_Operate_Command())->composition_history([], ['post_id' => (string) $post_id]);
        $emitted = implode("\n", WP_CLI::$lines);

        $this->assertStringNotContainsString("\x1b", $emitted, 'no raw ESC — that is terminal injection');
        $this->assertStringNotContainsString("\x7f", $emitted, 'no raw DEL');
        $this->assertStringNotContainsString("\xe2\x80\xae", $emitted, 'no raw RLO override');
        // …and the value still round-trips intact for the operator.
        $row = json_decode($emitted, true)['entries'][0];
        $this->assertFalse($row['restorable']);
        $this->assertSame($nasty, json_decode($row['raw'], true)['props']['title']);
    }
}
