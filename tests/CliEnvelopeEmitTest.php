<?php
/**
 * tests/CliEnvelopeEmitTest.php — the CLI's machine-readable stdout SINK (#717).
 *
 * THE DEFECT, MEASURED. Every JSON document `lib/cli.php` printed went through an
 * inline `WP_CLI::line(json_encode(...))` with a hand-maintained flag list, and
 * nothing anywhere checked the return. `json_encode()` answers `false` — not a
 * string — on malformed UTF-8, on nesting past 512, on recursion. `WP_CLI::line(false)`
 * prints an EMPTY LINE, and on `action execute` the very next branch still ran
 * `WP_CLI::success('Action "..." executed.')`. The operator got a blank line plus a
 * success message, and lost `ok`, `error_code`, `composition_version`, `changes` and
 * `findings` for a mutation that HAD ALREADY PERSISTED. That is the worst shape a
 * failure can take on a write path: the data changed and the record of what changed
 * is gone.
 *
 * REACHABILITY, and why it is wider than #717 first recorded. The issue reasoned via a
 * raw `_pp_composition` meta write (which `lib/wp.php` deliberately accepts as an
 * already-decoded array, bypassing the JSON decode filter). True, but not required:
 * `update_site_option` reports `changes[].from` read straight out of the options table,
 * so ONE bad byte in a legacy, imported, or plugin-written option value destroys the
 * receipt of a write that landed. `testALandedSiteOptionWriteKeepsItsReceipt...` below
 * is exactly that path, and it needs no composition at all.
 *
 * THE SECOND DEFECT. `JSON_UNESCAPED_UNICODE` handed the terminal raw U+202E (RLO),
 * U+2066 and U+200B out of stored names and values. The read-only diagnostics path
 * already refused to do that (`_pp_cli_printable`), and `wp pp operate patch` already
 * omitted the flag — so the two surfaces #717's addendum names emitted DIFFERENT BYTES
 * for identical content. Settled here the way #717's first suggestion proposed: escaped
 * at the sink, uniformly, which is LOSSLESS (a parser recovers the exact string) where
 * stripping the characters would not be.
 *
 * WHAT THIS FILE PINS, and why each part is here rather than assumed:
 *
 *   1. THE RECEIPT SURVIVES a landed write whose envelope carries a bad byte, and the
 *      stdout line is NOT EMPTY — asserted as the emitted bytes, because the blank line
 *      is the whole regression and an assertion on the pre-encode array cannot see it.
 *   2. THE BAD BYTE IS VISIBLE, not silently dropped: U+FFFD, with the readable text
 *      around it intact. A receipt that lies about its own damage is not better.
 *   3. THE REJECTION PATH keeps its `error_code` and its non-zero exit. A defended sink
 *      must not turn a refusal into a pass.
 *   4. BOTH SURFACES #717 NAMES agree — `action execute` and `operate patch` produce the
 *      same bytes for the same content. The addendum's actual complaint.
 *   5. THE BIDI DEFENSE ON THE EMITTED BYTES, paired with proof that the DECODED string
 *      still carries the character. Escaping, not stripping.
 *   6. THE FAIL PATH THAT `JSON_INVALID_UTF8_SUBSTITUTE` CANNOT FIX (depth), reached
 *      through a REAL command rather than only by calling the helper: a minimal envelope,
 *      never a blank line.
 *   7. THE ONE DOCUMENTED DEVIATION (`wp pp schema`) still prints prose characters, and
 *      an envelope surface escapes that same character — the pair is what makes the
 *      deviation scoped rather than a hole.
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with CliGateTest/CompositionShapeTrustTest) ─────
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

class CliEnvelopeEmitTest extends TestCase
{
    /** A byte that is not valid UTF-8 on its own — a lone 0xB1 continuation. */
    private const BAD_BYTE = "\xB1";

    /** U+202E RIGHT-TO-LEFT OVERRIDE: reverses everything after it in a terminal. */
    private const RLO = "\u{202E}";

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
        WP_CLI::$lines     = [];
        WP_CLI::$warnings  = [];
        WP_CLI::$successes = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_store']);
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** A run token with INSPECT recorded and a site-grain PREFLIGHT covering it. */
    private function siteRun(): string
    {
        $run_id = pp_operate_create_run();
        $this->assertIsString($run_id, 'run token created');
        $this->assertTrue(pp_operate_record_preflight($run_id, null, []), 'site-grain preflight recorded');
        return $run_id;
    }

    /** A run token whose PREFLIGHT covers $post_id at its CURRENT composition marker. */
    private function pageRun(int $post_id): string
    {
        $run_id = pp_operate_create_run();
        $this->assertIsString($run_id, 'run token created');
        $this->assertTrue(
            pp_operate_record_preflight($run_id, $post_id, [], pp_get_composition_marker($post_id), []),
            'page preflight recorded at the live marker'
        );
        return $run_id;
    }

    /**
     * Seeds a page the way an author really would — through pp_create_page() and
     * pp_update_composition() — and only THEN raw-writes a corrupted copy of what
     * those calls stored. A fixture corrupt from birth proves less than it looks:
     * it never demonstrates that the damage survives a real authored history.
     *
     * @param  string $needle Injected into band 1's component name.
     * @return int            The post ID.
     */
    private function seedThenCorrupt(string $needle): int
    {
        $post_id = pp_create_page('Envelope sink page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'band-1', 'title' => 'One']],
            ['component' => 'hero', 'props' => ['id' => 'band-2', 'title' => 'Two']],
        ]);

        $stored = pp_get_composition($post_id);
        $this->assertCount(2, $stored, 'the authored write landed before anything is corrupted');
        $stored[1]['component'] = 'he' . $needle . 'ro';
        update_post_meta($post_id, '_pp_composition', $stored);

        return $post_id;
    }

    /** The single stdout line, failing loudly on the blank-line regression. */
    private function soleEmittedLine(): string
    {
        $this->assertCount(1, WP_CLI::$lines, 'exactly one document on stdout');
        $line = WP_CLI::$lines[0];
        $this->assertNotSame('', $line, 'the sink printed a BLANK LINE — this is the #717 regression');
        return $line;
    }

    // ── 1-2. The receipt survives, and names its own damage ──────────────────

    /**
     * The measured repro, end to end, on the worst-shaped path: the write LANDED and
     * the caller still gets its envelope. No composition, no raw meta write — just a
     * stored option value carrying one bad byte, which any legacy import can leave.
     */
    public function testALandedSiteOptionWriteKeepsItsReceiptDespiteABadByteInTheOldValue(): void
    {
        update_option('pp_footer_blurb', 'Old ' . self::BAD_BYTE . ' blurb');
        $run_id = $this->siteRun();

        (new PP_Action_Command())->execute(['update_site_option'], [
            'run-id' => $run_id,
            'params' => json_encode(['key' => 'pp_footer_blurb', 'value' => 'New blurb']),
        ]);

        // The write landed. That is the precondition that makes a lost envelope severe.
        $this->assertSame('New blurb', get_option('pp_footer_blurb'), 'the mutation persisted');

        $line     = $this->soleEmittedLine();
        $envelope = json_decode($line, true);
        $this->assertIsArray($envelope, 'stdout is parseable JSON, not a blank line');
        $this->assertTrue($envelope['ok'], 'the envelope reports the write that landed');
        $this->assertSame('update_site_option', $envelope['action']);
        $this->assertArrayHasKey('changes', $envelope, 'the caller still learns WHAT changed');
        $this->assertSame('pp_footer_blurb', $envelope['changes'][0]['path']);
        $this->assertSame('New blurb', $envelope['changes'][0]['to']);

        // The success line is emitted either way; before #717 it was emitted beside a
        // blank line, which is precisely what made the failure invisible.
        $this->assertCount(1, WP_CLI::$successes);
    }

    /**
     * The damaged span is REPLACED and reported, not dropped and not fatal. The
     * readable part of the value — which is what tells the operator which record
     * this was — survives around it.
     */
    public function testTheBadByteBecomesAVisibleReplacementCharacterRatherThanVanishing(): void
    {
        update_option('pp_footer_blurb', 'Old ' . self::BAD_BYTE . ' blurb');
        $run_id = $this->siteRun();

        (new PP_Action_Command())->execute(['update_site_option'], [
            'run-id' => $run_id,
            'params' => json_encode(['key' => 'pp_footer_blurb', 'value' => 'New blurb']),
        ]);

        $from = json_decode($this->soleEmittedLine(), true)['changes'][0]['from'];
        $this->assertSame("Old \u{FFFD} blurb", $from, 'the bad byte is substituted, the rest is verbatim');
    }

    /**
     * Direct pin on the exact pre-#717 shape, so a regression cannot hide behind a
     * decode: `json_encode` with the OLD flag list genuinely returns false on this
     * envelope, and `WP_CLI::line()` genuinely renders false as an empty string.
     * Without this, "we fixed it" rests on the fix's own output.
     */
    public function testTheOldFlagListStillFailsOnThisEnvelopeSoTheFixIsNotAssertingItself(): void
    {
        update_option('pp_footer_blurb', 'Old ' . self::BAD_BYTE . ' blurb');
        $result = pp_execute_action('update_site_option', [
            'key' => 'pp_footer_blurb', 'value' => 'New blurb',
        ]);

        $this->assertTrue($result['ok'], 'the write this envelope reports on succeeded');
        $this->assertFalse(
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'the pre-#717 flag list destroys this envelope'
        );
        // Drive the blank line through the SAME seam the sink uses, rather than asserting
        // the PHP tautology `(string) false === ''`. What matters is what WP_CLI::line()
        // puts on stdout, and this is the byte-for-byte pre-#717 output.
        WP_CLI::$lines = [];
        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertSame([''], WP_CLI::$lines, 'the old shape put an empty line on stdout');
    }

    // ── 3. The rejection path keeps its verdict ──────────────────────────────

    /**
     * A defended sink must not soften a refusal. The rejection envelope still carries its
     * machine-readable code and still exits non-zero when the ENVELOPE ITSELF holds the
     * corrupt bytes.
     *
     * That last clause is the whole test. `style_component`'s validator quotes the stored
     * component name verbatim ("Component "..." has no style slot ..." — one of the two
     * message families #717 names), so pointing it at the corrupted band puts the bad byte
     * inside `error`, and the rejection envelope becomes unencodable under the old flag
     * list. A rejection whose message happens to carry no stored bytes would pass this
     * test with the entire fix reverted, which is worth stating because the first draft of
     * this test did exactly that.
     */
    public function testARejectionWhoseOwnMessageCarriesTheBadByteKeepsItsErrorCodeAndExit(): void
    {
        $post_id = $this->seedThenCorrupt(self::BAD_BYTE);
        $run_id  = $this->pageRun($post_id);

        $halted = false;
        try {
            (new PP_Action_Command())->execute(['style_component'], [
                'run-id' => $run_id,
                'params' => json_encode([
                    'post_id'         => $post_id,
                    'component_index' => 1, // the band whose stored name holds the bad byte
                    'style'           => ['--nope' => '#ffffff'],
                ]),
            ]);
        } catch (WpCliHaltException $e) {
            $halted = true;
            $this->assertSame(1, $e->getCode(), 'the refusal still exits 1');
        }
        $this->assertTrue($halted, 'the command halted rather than reporting success');

        $line     = $this->soleEmittedLine();
        $envelope = json_decode($line, true);
        $this->assertIsArray($envelope, 'stdout is parseable JSON, not a blank line');
        $this->assertFalse($envelope['ok']);
        $this->assertSame('no_style_slots', $envelope['error_code'], 'the code survives the sink');
        $this->assertStringContainsString("\u{FFFD}", $envelope['error'], 'the message names the damaged band');

        // The premise, asserted rather than assumed: the message the validator actually
        // produces here carries the RAW stored byte (the U+FFFD above is the sink's own
        // repair, downstream of this), and the pre-#717 flag list destroys it. Without
        // this the test could silently stop testing the thing it names.
        $raw = pp_validate_action('style_component', [
            'post_id'         => $post_id,
            'component_index' => 1,
            'style'           => ['--nope' => '#ffffff'],
        ]);
        $this->assertInstanceOf(WP_Error::class, $raw);
        $this->assertStringContainsString(self::BAD_BYTE, $raw->get_error_message(), 'the raw byte reaches the message');
        $this->assertFalse(
            json_encode(['error' => $raw->get_error_message()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'an envelope carrying that message is exactly what the old flag list refused'
        );
    }

    // ── 4. The two surfaces #717 names now agree ─────────────────────────────

    /**
     * The addendum's actual complaint: `action execute` emitted literal non-ASCII while
     * `operate patch` emitted `\uXXXX` for the same content, and both are documented in
     * one paragraph as "printing the whole envelope". An agent reading one and parsing
     * the other got different bytes. Pinned on the BYTES, not on the decoded values —
     * decoding is exactly what hid the divergence.
     */
    public function testActionExecuteAndOperatePatchEmitTheSameBytesForTheSameCharacter(): void
    {
        $post_id = pp_create_page('Agreement page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'band-1', 'title' => 'One']],
        ]);

        WP_CLI::$lines = [];
        (new PP_Operate_Command())->patch([], [
            'post_id' => (string) $post_id,
            'target'  => 'hero.subheading',
            'value'   => 'Caf' . "\u{00E9}" . ' patch',
            'preview' => true,
        ]);
        $patch_line = $this->soleEmittedLine();

        update_option('pp_footer_blurb', '');
        WP_CLI::$lines = [];
        (new PP_Action_Command())->execute(['update_site_option'], [
            'run-id' => $this->siteRun(),
            'params' => json_encode(['key' => 'pp_footer_blurb', 'value' => 'Caf' . "\u{00E9}" . ' patch']),
        ]);
        $execute_line = $this->soleEmittedLine();

        // One representation, both surfaces.
        $this->assertStringContainsString('Caf\\u00e9 patch', $patch_line);
        $this->assertStringContainsString('Caf\\u00e9 patch', $execute_line);
        $this->assertStringNotContainsString("\u{00E9}", $patch_line, 'no literal non-ASCII on either surface');
        $this->assertStringNotContainsString("\u{00E9}", $execute_line, 'no literal non-ASCII on either surface');
    }

    // ── 5. The bidi defense, and that it is escaping rather than stripping ───

    /**
     * A stored component name carrying U+202E reaches the read-only report sink. The
     * BYTES on the wire must not contain it — that is the whole defense, because the
     * terminal acts on the byte, not on what a parser would make of it.
     */
    public function testAStoredOverrideCharacterNeverReachesTheTerminalAsItself(): void
    {
        $post_id = $this->seedThenCorrupt(self::RLO);

        (new PP_Operate_Command())->inspect_composition([], ['post_id' => (string) $post_id]);

        $line = $this->soleEmittedLine();
        $this->assertStringNotContainsString(self::RLO, $line, 'no raw RIGHT-TO-LEFT OVERRIDE on stdout');
        $this->assertStringNotContainsString("\u{2066}", $line, 'no raw isolate on stdout');
        $this->assertStringNotContainsString("\u{200B}", $line, 'no raw zero-width space on stdout');
        $this->assertStringContainsString('\\u202e', $line, 'it arrives as its escape');
    }

    /**
     * The other half, and the reason this issue escapes rather than strips: the content
     * is not destroyed. A consumer that decodes the document gets the exact stored
     * string back, override character included — so a diagnostic surface can still show
     * an operator what is really stored.
     */
    public function testEscapingIsLosslessSoTheDecodedNameStillCarriesTheCharacter(): void
    {
        $post_id = $this->seedThenCorrupt(self::RLO);

        (new PP_Operate_Command())->inspect_composition([], ['post_id' => (string) $post_id]);

        $decoded = json_decode($this->soleEmittedLine(), true);
        $this->assertIsArray($decoded, 'the escaped document is still valid JSON');
        $this->assertStringContainsString(
            self::RLO,
            json_encode($decoded, JSON_UNESCAPED_UNICODE),
            'the character round-trips: escaped on the wire, intact in the value'
        );
    }

    /**
     * The RLO pin on a surface this change ACTUALLY moved. The two above drive
     * `operate inspect-composition`, which never carried JSON_UNESCAPED_UNICODE — they
     * are forward-looking flag guards, and they pass against a fully reverted lib/cli.php.
     * `action execute` is the surface that used to print the character live, so this is
     * the case #717 genuinely closes, asserted on the bytes leaving the command.
     */
    public function testTheOverrideCharacterIsEscapedOnASurfaceThatUsedToPrintItLive(): void
    {
        update_option('pp_footer_blurb', 'Old ' . self::RLO . ' blurb');

        (new PP_Action_Command())->execute(['update_site_option'], [
            'run-id' => $this->siteRun(),
            'params' => json_encode(['key' => 'pp_footer_blurb', 'value' => 'New blurb']),
        ]);

        $line = $this->soleEmittedLine();
        $this->assertStringNotContainsString(self::RLO, $line, 'no raw override character on stdout');
        $this->assertStringContainsString('\u202e', $line, 'it arrives as its escape');
        $this->assertSame('Old ' . self::RLO . ' blurb', json_decode($line, true)['changes'][0]['from'], 'and decodes back intact');
    }

    /**
     * DEL (U+007F) is the one control character JSON does NOT escape on its own, and
     * dropping JSON_UNESCAPED_UNICODE does not reach it either — it sits inside ASCII.
     * The sink escapes it explicitly, so the docblock claim that the emitted bytes stay
     * printable ASCII is true rather than nearly true. Surfaced by the security pass.
     */
    public function testTheDeleteControlCharacterIsEscapedToo(): void
    {
        _pp_cli_emit_json(['ok' => true, 'note' => "a\x7fb"]);

        $line = $this->soleEmittedLine();
        $this->assertStringNotContainsString("\x7f", $line, 'no raw DEL byte on stdout');
        $this->assertStringContainsString('\u007f', $line);
        $this->assertSame("a\x7fb", json_decode($line, true)['note'], 'and it is still lossless');
    }

    // ── 6. The failure SUBSTITUTE cannot fix, reached through a real command ─

    /**
     * `JSON_INVALID_UTF8_SUBSTITUTE` repairs bad bytes and nothing else. Nesting past
     * json_encode's 512-level depth still returns false — and it is reachable through a
     * real command, because a raw `_pp_composition` write stores an already-decoded PHP
     * array that no depth check ever saw. The sink must still say something.
     */
    public function testADocumentTooDeepToEncodeStillProducesAnEnvelopeAndNotABlankLine(): void
    {
        $post_id = pp_create_page('Deep page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'band-1', 'title' => 'One']],
        ]);

        $deep = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }
        // The depth goes on a prop the inspector REPORTS but never stringifies, so this
        // pins the sink and nothing else. (A deep `title` additionally trips an unrelated
        // Array-to-string conversion inside pp_inspect_composition — a real, separate
        // defect, filed rather than absorbed here.)
        $stored = pp_get_composition($post_id);
        $stored[0]['props']['subheading'] = $deep;
        update_post_meta($post_id, '_pp_composition', $stored);

        // Confirm the premise rather than assuming it: this really is a class SUBSTITUTE
        // cannot repair, so the fallback below is the thing under test.
        $this->assertFalse(
            json_encode(pp_inspect_composition($post_id), JSON_INVALID_UTF8_SUBSTITUTE),
            'depth failure survives the substitute flag'
        );

        (new PP_Operate_Command())->inspect_composition([], ['post_id' => (string) $post_id]);

        $line     = $this->soleEmittedLine();
        $envelope = json_decode($line, true);
        $this->assertIsArray($envelope, 'the fallback is itself valid JSON');
        $this->assertArrayHasKey('envelope_error', $envelope, 'and it says why it is short');
        $this->assertStringContainsString('could not be encoded', $envelope['envelope_error']);
        // Pin the DIAGNOSTIC, not just its existence: json_last_error_msg() is the one
        // piece of information the fallback adds, and a fixed placeholder would read
        // identically to a real reason.
        $this->assertStringContainsString(
            'Maximum stack depth exceeded',
            $envelope['envelope_error'],
            "the fallback names the encoder's own reason"
        );
    }

    /**
     * The fallback keeps the field a caller most needs after a write — whether it landed
     * — and NAMES what it had to drop. Driven through the helper because no real command
     * produces a too-deep envelope that also carries `ok`; the two reach the sink from
     * different surfaces.
     */
    public function testTheFallbackKeepsTheVerdictAndNamesWhatItDropped(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }

        _pp_cli_emit_json([
            'ok'                  => true,
            'action'              => 'update_component',
            'error_code'          => '',
            'composition_version' => 7,
            'changes'             => $deep,
            'findings'            => $deep,
        ]);

        $envelope = json_decode($this->soleEmittedLine(), true);
        $this->assertIsArray($envelope);
        $this->assertTrue($envelope['ok'], 'the caller still learns the write landed');
        $this->assertSame('update_component', $envelope['action']);
        $this->assertSame(7, $envelope['composition_version'], 'and can still re-read at the right baseline');
        $this->assertArrayNotHasKey('changes', $envelope, 'the field that could not encode is omitted, not faked');

        // The honesty half. Without this, a consumer reading `findings ?? []` beside a
        // surviving `ok: true` reads a clean bill of health for diagnostics that were
        // never encoded — the trap findings_skipped exists to close on the write path.
        $this->assertContains('findings', $envelope['omitted_keys'], 'an absent findings is UNKNOWN, and says so');
        $this->assertContains('changes', $envelope['omitted_keys']);
        $this->assertStringContainsString('UNKNOWN, not empty', $envelope['envelope_error']);
    }

    /**
     * The type filter on the salvage loop is what stops the fallback re-entering the
     * failure it is reporting. Put the unencodable value on a key the salvage WOULD
     * otherwise copy, and that filter is the only thing standing between this and a lost
     * verdict. (Without this pin every other test here still passed.)
     */
    public function testTheSalvageGuardRefusesAnUnencodableValueOnASalvagedKey(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }

        _pp_cli_emit_json(['ok' => true, 'action' => $deep, 'error_code' => 'still_here']);

        $envelope = json_decode($this->soleEmittedLine(), true);
        $this->assertIsArray($envelope, 'the fallback encoded despite the poison on a salvaged key');
        $this->assertTrue($envelope['ok']);
        $this->assertSame('still_here', $envelope['error_code']);
        $this->assertArrayNotHasKey('action', $envelope);
        $this->assertContains('action', $envelope['omitted_keys']);
    }

    /**
     * Salvage is by TYPE, not from a list of known field names. Every surface in
     * lib/cli.php has its own verdict field, so a hand-maintained list would quietly stop
     * covering the next one. `valid` belongs to `wp pp operate validate`, which halts on
     * it; `status` to `wp pp integrity check`.
     */
    public function testTheFallbackSalvagesVerdictFieldsThatAreNotTheActionEnvelopes(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }

        _pp_cli_emit_json(['valid' => false, 'status' => 'drifted', 'report' => $deep]);

        $envelope = json_decode($this->soleEmittedLine(), true);
        $this->assertIsArray($envelope);
        $this->assertFalse($envelope['valid'], 'a non-action surface keeps its verdict too');
        $this->assertSame('drifted', $envelope['status']);
        $this->assertContains('report', $envelope['omitted_keys']);
    }

    /**
     * The last-resort literal. A non-array payload that cannot encode has nothing to
     * salvage, and the sink must STILL not print a blank line.
     */
    public function testANonArrayPayloadThatCannotEncodeStillPrintsSomething(): void
    {
        _pp_cli_emit_json(NAN);

        $envelope = json_decode($this->soleEmittedLine(), true);
        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('envelope_error', $envelope);
    }

    /**
     * A non-finite float must not be carried into the salvage attempt. INF/NAN are
     * `is_scalar()`, and JSON cannot encode them — copying one across would fail the
     * SECOND encode too and drop the report to the bare literal, which carries no
     * verdict. That is the "the record of the write is gone" failure this issue exists to
     * end, rebuilt inside the fix's own recovery path. Surfaced by the adversarial pass.
     */
    public function testANonFiniteValueDoesNotTakeTheVerdictDownWithIt(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }

        _pp_cli_emit_json([
            'ok'                  => true,
            'action'              => 'update_component',
            'composition_version' => NAN,
            'changes'             => $deep,
        ]);

        $envelope = json_decode($this->soleEmittedLine(), true);
        $this->assertIsArray($envelope);
        $this->assertTrue($envelope['ok'], 'the verdict survives beside an unencodable sibling');
        $this->assertSame('update_component', $envelope['action']);
        $this->assertArrayNotHasKey('composition_version', $envelope, 'the unencodable field is dropped, not faked');
        $this->assertContains('composition_version', $envelope['omitted_keys']);
    }

    // ── 7. The one documented deviation, and its scope ──────────────────────

    /**
     * `wp pp schema` prints shipped schema.json prose for an agent that cannot open the
     * file, so it keeps literal characters — the one deviation, recorded at its call
     * site. The pair matters: the same character is ESCAPED on an envelope surface, so
     * the deviation is scoped to repo-owned prose rather than being a hole in the sink.
     */
    public function testSchemaKeepsItsProseCharactersWhileAnEnvelopeSurfaceEscapesTheSameOne(): void
    {
        (new PP_Schema_Command())->__invoke(['hero'], []);
        $schema_line = $this->soleEmittedLine();
        $this->assertStringContainsString("\u{2014}", $schema_line, 'schema prose keeps its em dashes');

        update_option('pp_footer_blurb', '');
        WP_CLI::$lines = [];
        (new PP_Action_Command())->execute(['update_site_option'], [
            'run-id' => $this->siteRun(),
            'params' => json_encode(['key' => 'pp_footer_blurb', 'value' => 'a' . "\u{2014}" . 'b']),
        ]);
        $envelope_line = $this->soleEmittedLine();
        $this->assertStringNotContainsString("\u{2014}", $envelope_line, 'the envelope sink escapes it');
        $this->assertStringContainsString('\\u2014', $envelope_line);
    }

    /**
     * Even the deviating surface is defended against the failure that started #717: it
     * routes through the same helper, so it can never print a blank line either.
     */
    public function testTheDeviatingSurfaceStillCannotPrintABlankLine(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }

        _pp_cli_emit_json(['component' => 'hero', 'description' => $deep], true);

        $envelope = json_decode($this->soleEmittedLine(), true);
        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('envelope_error', $envelope);
    }
}
