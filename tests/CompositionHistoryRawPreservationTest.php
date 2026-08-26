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
 *   prior bytes decode to an array  ─►  {timestamp, version, hash, composition: [...]}
 *   prior bytes do NOT              ─►  {timestamp, version, hash, raw: "<exact bytes>"}
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
 * issue states `unexpected_shape` is safe because a JSON OBJECT decodes to a PHP array
 * and so passes the gate. True — and pinned here as byte-identical. But
 * `pp_get_composition_result()` also classifies as `unexpected_shape` the case "valid
 * JSON, non-list SCALAR" (`null`, `5`, `"text"`), and those decode to a non-array, fail
 * the SAME gate, and were destroyed by the SAME line. The fix keys on the gate itself
 * ("the prior bytes did not decode to an array") rather than on a re-run of the
 * classifier, so both lossy sub-cases are covered by one branch. Both are pinned below.
 *
 * Coverage:
 *   the filed repro end to end — seed, corrupt, repair, recover the bytes
 *   the authoring path (Section 14.1): the repair arrives through the real
 *     `update_composition` ACTION, not a raw meta write
 *   every ring reader's behavior on a raw entry: pp_get_composition_history,
 *     pp_update_composition's own append (the round-trip that would otherwise drop it),
 *     restore_composition validate / preview / execute, and the
 *     `wp pp operate composition-history` CLI listing
 *   the unexpected_shape OBJECT path, byte-identical
 *   the unexpected_shape SCALAR sub-case, which the same gate also lost
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
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_user_caps']);
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
     * THE UNEXPECTED_SHAPE OBJECT PATH IS BYTE-IDENTICAL. A JSON object decodes to a PHP
     * array, passes the gate, and is snapshotted as a `composition` entry exactly as it
     * always was — the fix must not reclassify the class that was never broken.
     */
    public function testTheObjectShapedUnexpectedShapePathIsUnchanged(): void
    {
        $post_id = $this->corruptedPage('{"1":{"component":"hero","props":{"id":"obj-1"}}}');
        $this->assertSame('unexpected_shape', pp_get_composition_result($post_id)['error']);

        pp_update_composition($post_id, $this->repairBands());

        $ring  = pp_get_composition_history($post_id);
        $entry = end($ring);
        $this->assertArrayNotHasKey('raw', $entry, 'a decodable prior is still a composition snapshot, not raw bytes');
        $this->assertSame(['1' => ['component' => 'hero', 'props' => ['id' => 'obj-1']]], $entry['composition']);
        $this->assertFalse(pp_history_entry_is_raw($entry));
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
}
