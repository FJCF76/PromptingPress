<?php
/**
 * tests/ReflectedTextInventoryTest.php — the server-side reflected-text inventory (#647).
 *
 * ONE IDIOM, STATED AS A RULE RATHER THAN A LIST. Every server sink that renders text the
 * theme did not author — caller argv, or stored site data — routes it through the EXISTING
 * owner for that surface, at the sink:
 *
 *     lib/cli.php human channel  ──► _pp_cli_printable()             (Cc/Cf ──► ' ')
 *     lib/admin.php validator    ──► _pp_schema_value_for_message()  (quote + strip + bound)
 *     every server response      ──► _pp_clean_reflected_text()      (strip + bound + repair)
 *                                    _pp_clean_reflected_report()    (its one nested shape)
 *
 * THE THIRD OWNER MOVED TO lib/wp.php IN #864 (ruling T3) and is no longer "the chat side's".
 * It was defined in lib/ai-chat.php, which functions.php loads only under is_admin(), so the
 * editor's own AJAX sinks — in always-loaded lib/admin.php — could not reach it without an
 * always-loaded file depending on a conditionally-loaded one, the coupling #649 rejected for
 * _pp_item_index_label(). That is why this row now names a CHANNEL rather than a file.
 *
 * No new sanitizer, no new constant, no second definition of "safe to echo back". This file
 * pins the CONVERSIONS — one test per surface — plus the two properties that make the change
 * safe to ship: well-formed text is BYTE-IDENTICAL through every one of them, and the sites
 * deliberately left alone stay legible.
 *
 * WHAT THIS FILE DOES *NOT* CLAIM. The rule above is stated for the surfaces #647/#649/#864
 * inventoried, not for every sink in the theme, and reading it as universal would let a
 * future reader mistake an unguarded site for an audited one. Known-unguarded, on purpose:
 *
 *   - `Unknown component: "%s"` at lib/admin.php, whose stored name is still interpolated
 *     verbatim AT THE SOURCE. Every route it takes to a reader cleans it at the SINK — the
 *     three editor endpoints in section E, the chat's two payloads in section D, the
 *     terminal's _pp_cli_printable() — so nothing reaches anyone unguarded. Cleaning it at
 *     the source as well would additionally bound it on the `findings` channel, which
 *     _pp_bounded_findings() (lib/actions.php) records as a separate ruling (#687's
 *     addendum); half-landing that ruling for one message is why #864 left it. The site
 *     carries the same note;
 *   - `findings[].message` on every envelope, for that same reason;
 *   - the ~23 sibling `Component "%s"` messages, which reflect a stored component name
 *     verbatim. #649 treats that family's spelling as the reference point, not the target;
 *   - the `rollback_errors` channel (`_pp_restore_batch_snapshot()`, lib/actions.php), whose
 *     producers reflect stored site data verbatim: a menu item's title, and since #854 a
 *     redirect's normalized source path. _pp_normalize_redirect_path() strips scheme, host,
 *     query and trailing slash — it is a URL normalizer, not a text guard, so it removes no
 *     \p{Cc}\p{Cf}. Registered here so the new producer is not mistaken for an audited sink:
 *     it joined an already-unguarded channel rather than opening one. The exposure is
 *     bounded — the chat renders these rows through textContent, so the escape is the DOM's.
 *     #864 answered the OWNER question this used to wait on (there is now a shared cleaner,
 *     in lib/wp.php) but did not carry the conversion, which was not in its enumerated
 *     scope;
 *   - the AI provider's own error text (`pp_ai_completion()` -> lib/ai-chat.php's chat
 *     fallback handler), which is a third source class beside caller argv and stored site
 *     data. Also outside #864's enumerated scope;
 *   - QUOTING grammar — a key containing a double quote still renders `key "a"b"`;
 *   - U+2028/U+2029 and homoglyphs, which are not \p{Cc}\p{Cf}.
 *
 * The CLI owner's own invalid-UTF-8 path was corrected as part of this change — it used to
 * fall back to a narrower byte-wise strip, so one malformed byte downgraded the guard on
 * every sink here. It now repairs and re-runs the same pattern, like both siblings, and the
 * two vectors that demonstrated the bypass are pinned verbatim below.
 *
 * WHY THE HOSTILE FIXTURES LOOK LIKE THAT. `\x1b[31m` is the escape that repaints a
 * terminal; `\n` fakes a second line of output, which is how a refusal can be made to look
 * like it came from the tool rather than from the data; `\u{202E}` is the bidi override that
 * renders as nothing while reversing everything after it. All three are invisible-by-design,
 * which is exactly why an assertion is the only way to know they are gone.
 *
 * SECTION 14.1: the CLI cases drive the REAL command objects (PP_Check_Command::page(),
 * PP_Operate_Command::patch(), ...) rather than the helpers underneath them, so the pins
 * cover the wiring as well as the decision.
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with CliGateTest/DiagnosticReachTest) ───────────
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

// The table paths were the one CLI sink no test could observe (see the stub's own file for
// why it cannot live here). Must be included BEFORE lib/cli.php.
require_once __DIR__ . '/WpCliFormatItemsStub.php';
require_once dirname(__DIR__) . '/lib/cli.php';

class ReflectedTextInventoryTest extends TestCase
{
    /** An escape byte, a newline, and a bidi override — the three shapes the owners exist for. */
    private const HOSTILE = "aa\x1b[31m\nbb\u{202E}cc";

    /** What HOSTILE looks like once the CLI owner has run: control/format runs collapse to one space. */
    private const HOSTILE_CLI = 'aa [31m bb cc';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
        $GLOBALS['_pp_test_format_items'] = [];
        WP_CLI::$lines     = [];
        WP_CLI::$warnings  = [];
        WP_CLI::$successes = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_pp_test_format_items'] = [];
        parent::tearDown();
    }

    private function seedPage(int $id, array $composition, string $title = 'A Page'): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'ID' => $id, 'post_title' => $title, 'post_type' => 'page', 'post_status' => 'publish',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_wp_page_template'] = 'composition.php';
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_pp_composition']   = wp_json_encode($composition);
    }

    /** Everything the command printed, on one string, for containment assertions. */
    private function printed(): string
    {
        // Joined with a visible separator, never a newline: these assertions are about
        // whether a REFLECTED value smuggled a line break in, so the joiner must not
        // contribute one of its own.
        return implode(' | ', array_merge(WP_CLI::$warnings, WP_CLI::$lines, WP_CLI::$successes));
    }

    /** Every cell of every format_items table the command emitted. */
    private function tableCells(): string
    {
        $cells = [];
        foreach ($GLOBALS['_pp_test_format_items'] as $table) {
            foreach ($table['items'] as $row) {
                foreach ($row as $value) {
                    $cells[] = (string) $value;
                }
            }
        }
        return implode(' | ', $cells);
    }

    /** The assertion every conversion in this file shares. */
    private function assertDefanged(string $haystack, string $context): void
    {
        $this->assertStringNotContainsString("\x1b", $haystack, "$context: the escape byte must not reach the terminal");
        $this->assertStringNotContainsString("\n", $haystack, "$context: the reflected text must not fake a second line");
        $this->assertStringNotContainsString("\u{202E}", $haystack, "$context: the bidi override must not reach the terminal");
    }

    // ── A. Caller argv (lib/cli.php) ──────────────────────────────────────────

    /**
     * The `--post_id` value gate quotes back what it READ, which is raw argv.
     *
     * Recorded in #647's site inventory as lib/cli.php:189 of the v1.15.13 tree.
     */
    public function testTheInvalidPostIdRefusalStripsTheValueItQuotesBack(): void
    {
        $error = _pp_cli_post_id_arg_error(['post_id' => self::HOSTILE], 'pp check page');

        $this->assertNotNull($error);
        $this->assertDefanged($error, 'invalid --post_id');
        $this->assertStringContainsString('Invalid --post_id "' . self::HOSTILE_CLI . '"', $error);
    }

    /** A real id is byte-identical through the owner — the refusal still reads as it always did. */
    public function testAWellFormedButUnusablePostIdIsUnchanged(): void
    {
        $error = _pp_cli_post_id_arg_error(['post_id' => 'about-us'], 'pp check page');

        $this->assertStringContainsString('Invalid --post_id "about-us" for `wp pp check page`', $error);
    }

    /**
     * The positional guard echoes the stray token TWICE and ends on an instruction the
     * operator is meant to act on — the inventory's highest-value conversion
     * (lib/cli.php:299/301 of the v1.15.13 tree).
     */
    public function testTheAlreadyAddressedPositionalRefusalStripsBothEchoes(): void
    {
        $error = _pp_cli_positional_page_arg_error(
            ['pp', 'check', 'page', self::HOSTILE],
            ['post_id' => '19']
        );

        $this->assertNotNull($error);
        $this->assertDefanged($error, 'unexpected positional');
        $this->assertSame(
            2,
            substr_count($error, '"' . self::HOSTILE_CLI . '"'),
            'both the "got" echo and the "Remove ... and re-run" instruction carry the cleaned form'
        );
    }

    /** The un-addressed branch (lib/cli.php:304 of the v1.15.13 tree). */
    public function testTheUnaddressedPositionalRefusalStripsItsEcho(): void
    {
        $error = _pp_cli_positional_page_arg_error(['pp', 'check', 'page', self::HOSTILE], []);

        $this->assertNotNull($error);
        $this->assertDefanged($error, 'positional page argument');
        $this->assertStringContainsString('(got "' . self::HOSTILE_CLI . '")', $error);
    }

    /**
     * THE ONE SITE THE INVENTORY RECORDS AS NEEDING NO GUARD, pinned so the reason survives.
     *
     * The composed corrected command is the only refusal that hands the operator a line to
     * RUN, and it is gated on _pp_cli_is_canonical_post_id() — decimal digits only. The
     * predicate is what makes it safe; adding a strip there would suggest otherwise.
     */
    public function testTheComposedCorrectedCommandIsUnchangedForACanonicalId(): void
    {
        $error = _pp_cli_positional_page_arg_error(['pp', 'check', 'page', '234'], []);

        $this->assertStringContainsString('the page part is `wp pp check page --post_id=234`', $error);
    }

    /** `operate checklist` quotes the playbook name BEFORE any membership test vouches for it. */
    public function testTheUnknownPlaybookRefusalStripsTheName(): void
    {
        try {
            (new PP_Operate_Command())->checklist([], ['playbook' => self::HOSTILE]);
            $this->fail('an unknown playbook must be refused');
        } catch (WpCliExitException $e) {
            $this->assertDefanged($e->getMessage(), 'unknown playbook');
            $this->assertStringContainsString("Unknown playbook '" . self::HOSTILE_CLI . "'", $e->getMessage());
        }
    }

    /** `readiness acknowledge` quotes a finding key that matched nothing. */
    public function testTheUnacknowledgeableFindingRefusalStripsTheKey(): void
    {
        try {
            (new PP_Readiness_Command())->acknowledge([self::HOSTILE], []);
            $this->fail('a key that is not currently present must be refused');
        } catch (WpCliExitException $e) {
            $this->assertDefanged($e->getMessage(), 'acknowledge');
            $this->assertStringContainsString('finding: "' . self::HOSTILE_CLI . '"', $e->getMessage());
        }
    }

    /** And its reverse. */
    public function testTheUnacknowledgedFindingRefusalStripsTheKey(): void
    {
        try {
            (new PP_Readiness_Command())->unacknowledge([self::HOSTILE], []);
            $this->fail('an un-acknowledged key must be refused');
        } catch (WpCliExitException $e) {
            $this->assertDefanged($e->getMessage(), 'unacknowledge');
            $this->assertStringContainsString('Finding "' . self::HOSTILE_CLI . '"', $e->getMessage());
        }
    }

    /**
     * `--run-id` quotes back a token that FAILED the UUID test, so the echo is raw argv.
     *
     * Re-derived addition to the recorded inventory: every other `$run_id` echo in the
     * file runs after pp_operate_valid_run_id() passed (hex and hyphens only) — this one
     * exists because it did not.
     */
    public function testTheInvalidRunIdRefusalStripsTheValueItQuotesBack(): void
    {
        $error = _pp_cli_run_id_error(['run-id' => self::HOSTILE]);

        $this->assertNotNull($error);
        $this->assertDefanged($error, 'invalid --run-id');
        $this->assertStringContainsString('Got: "' . self::HOSTILE_CLI . '"', $error);
    }

    /** And a well-formed-but-wrong run id still reads exactly as it did. */
    public function testAnOrdinaryInvalidRunIdIsUnchanged(): void
    {
        $this->assertStringContainsString('Got: "not-a-uuid"', _pp_cli_run_id_error(['run-id' => 'not-a-uuid']));
    }

    /**
     * `wp pp schema <component>` refuses an unregistered name by quoting it back
     * (`Unknown component "%s"`, lib/operate.php) — raw argv, echoed because it matched
     * nothing. Its sibling resolver sinks in this file already wrapped.
     */
    public function testTheUnknownComponentRefusalStripsTheName(): void
    {
        try {
            (new PP_Schema_Command())->__invoke([self::HOSTILE], []);
            $this->fail('an unregistered component name must be refused');
        } catch (WpCliExitException $e) {
            $this->assertDefanged($e->getMessage(), 'unknown component');
            // The POSITIVE half: defanged-only would pass just as well if the refusal
            // stopped naming the component at all, which is a different regression and
            // an equally bad one — an operator who mistyped a name needs to see what
            // was read.
            $this->assertStringContainsString(
                'Unknown component "' . self::HOSTILE_CLI . '"',
                $e->getMessage(),
                'and the name is still named, in cleaned form'
            );
        }
    }

    /**
     * `readiness unacknowledge` SUCCESS line, and the asymmetry with `acknowledge`'s
     * success line is deliberate: that one is vouched for by theme-authored constants,
     * this one only by membership in the pp_acknowledged_findings OPTION — stored site
     * data, so membership proves the key was stored, not that it is printable.
     */
    public function testTheUnacknowledgeSuccessLineStripsTheStoredKey(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_acknowledged_findings'] = [
            self::HOSTILE => ['acknowledged_at' => '2026-08-30'],
        ];

        (new PP_Readiness_Command())->unacknowledge([self::HOSTILE], []);

        $printed = $this->printed();
        $this->assertStringContainsString('Un-acknowledged', $printed, 'premise: the reversal succeeded');
        $this->assertDefanged($printed, 'unacknowledge success');
    }

    // ── B. Stored site data (lib/cli.php) ─────────────────────────────────────

    /**
     * `wp pp check page` prints stored component names and ids on the generated-id line.
     *
     * The inconsistency this closes sat INSIDE one command body: the finding loops above and
     * below this one already routed through _pp_cli_finding_line() -> _pp_cli_printable().
     */
    public function testCheckPageStripsStoredNamesAndIdsOnTheGeneratedIdLine(): void
    {
        $this->seedPage(410, [
            ['component' => self::HOSTILE, 'props' => ['title' => 'X']],
        ]);

        (new PP_Check_Command())->page([], ['post_id' => 410]);

        $printed = $this->printed();
        $this->assertStringContainsString('without a durable component_id', $printed, 'premise: the line was printed');
        $this->assertDefanged($printed, 'check page generated-id line');
    }

    /** The same command's ambiguous-targeting TABLE, whose cells are a terminal sink too. */
    public function testCheckPageStripsStoredNamesInTheAmbiguousTargetingTable(): void
    {
        $this->seedPage(411, [
            ['component' => self::HOSTILE, 'props' => ['title' => 'X']],
            ['component' => self::HOSTILE, 'props' => ['title' => 'Y']],
        ]);

        (new PP_Check_Command())->page([], ['post_id' => 411]);

        $this->assertNotSame([], $GLOBALS['_pp_test_format_items'], 'premise: a table was emitted');
        $this->assertDefanged($this->tableCells(), 'ambiguous-targeting table');
        $this->assertStringContainsString(self::HOSTILE_CLI, $this->tableCells(), 'and the name is still named');
    }

    /** The Custom CSS conflicts table, printed by two commands through one row renderer. */
    public function testTheConflictsTableStripsTheStoredSelector(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = self::HOSTILE . " .hero { color: red; }";

        (new PP_Check_Command())->conflicts([], []);

        $this->assertNotSame([], $GLOBALS['_pp_test_format_items'], 'premise: a conflict was found and tabled');
        $this->assertDefanged($this->tableCells(), 'conflicts table');
        $this->assertStringContainsString(
            self::HOSTILE_CLI,
            $this->tableCells(),
            'and the selector is still shown — a table that emptied the cell would defang trivially'
        );
    }

    /** A selector with nothing hostile in it is byte-identical through the row renderer. */
    public function testTheConflictsRowRendererLeavesAnOrdinarySelectorAlone(): void
    {
        $rows = _pp_cli_printable_conflict_rows([['selector' => '.hero .btn', 'component' => 'hero']]);

        $this->assertSame([['selector' => '.hero .btn', 'component' => 'hero']], $rows);
    }

    /**
     * `wp pp validate page` prints the RENDERED-html findings, whose messages are built by
     * interpolating stored component names and media paths (lib/post-apply-validate.php).
     * Its neighbours in `check page` were wrapped; this loop was not.
     */
    public function testValidatePageStripsTheRenderedFindingMessages(): void
    {
        $this->seedPage(412, [['component' => self::HOSTILE, 'props' => ['title' => 'X']]]);

        try {
            (new PP_Validate_Command())->page([], ['post_id' => 412]);
        } catch (WpCliHaltException $e) {
            // Expected: an unrenderable component fails the gate. The OUTPUT is the subject.
        }

        $printed = $this->printed();
        $this->assertStringContainsString('error(s)', $printed, 'premise: findings were printed');
        $this->assertDefanged($printed, 'validate page findings');
    }

    /**
     * `wp pp operate patch` reflects the caller's own --target selector and stored ids
     * through pp_patch_composition()'s WP_Error. Its sibling sink in the same class
     * (inspect-composition) already wrapped, and that one only reflects a literal.
     *
     * --preview keeps the path read-only and ungated, which is what makes it drivable here.
     *
     * WHICH BRANCH THIS ACTUALLY REACHES, stated because it is not the obvious one: a
     * hostile selector fails the selector PARSER before the resolver ever runs, so the
     * message asserted below is `Invalid selector: ... in "<target>"`, not the resolver's
     * `No component of type "%s"` / `Matching IDs: %s`. Both come back through the same
     * WP_Error and the same wrapped sink, so the conversion is pinned either way — but
     * the resolver's STORED-ID half is reached only by a target that parses cleanly and
     * resolves to nothing, which no fixture here builds. Recorded so nobody reads this
     * test as proving more than it does.
     */
    public function testOperatePatchStripsTheSelectorItQuotesBack(): void
    {
        $this->seedPage(413, [['component' => 'hero', 'props' => ['title' => 'X']]]);

        try {
            (new PP_Operate_Command())->patch([], [
                'post_id' => 413,
                'target'  => self::HOSTILE . '.title',
                'value'   => 'new',
                'preview' => true,
            ]);
            $this->fail('a selector naming no component must be refused');
        } catch (WpCliExitException $e) {
            $this->assertDefanged($e->getMessage(), 'operate patch');
            $this->assertStringContainsString(
                self::HOSTILE_CLI,
                $e->getMessage(),
                'and the refused selector is still quoted back, in cleaned form'
            );
        }
    }

    /**
     * `wp pp validate site` names the stored POST TITLE on all three of its per-page lines.
     *
     * A SOURCE SLICE, not a command call, and the reason is recorded rather than assumed:
     * pp_composition_pages() caches statically for the life of the process, so the command
     * passes in isolation and silently finds zero pages in a full-suite run — a test that
     * reports success for the wrong reason. CompositionShapeTrustTest makes the same call
     * for the same branch. The behavior of the owner is pinned directly below this.
     */
    public function testValidateSiteReadsThePageTitleThroughTheOwner(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/cli.php');
        $method = strpos($source, 'public function site(');
        $start  = strpos($source, 'foreach ($pages as $page) {', $method);
        $this->assertNotFalse($start, 'the per-page loop of `validate site` was not found — has it been restructured?');

        // SLICED TO THE LOOP'S REAL END, never a magic length. An earlier version of this
        // test took a fixed 1600-character window, which stopped inside the issue-count
        // branch — so the two lines that print the title on the OTHER two branches sat
        // outside the very assertion that claimed to cover them. The terminator is the
        // summary section that follows the loop.
        $end = strpos($source, '// 3. Summary', $start);
        $this->assertNotFalse($end, 'the summary section that ends the per-page loop was not found');
        $loop = substr($source, $start, $end - $start);

        // WINDOW-INTEGRITY GUARD, so this test can never again silently shrink below the
        // branches it is asserting over. If the loop is restructured and this marker moves
        // out of the slice, this fails loudly instead of passing over less code.
        $this->assertStringContainsString('OK: Page', $loop, 'the slice must reach the clean-page branch');

        $this->assertStringContainsString(
            "\$title       = _pp_cli_printable((string) (\$page['title'] ?? '(untitled)'));",
            $loop,
            'the stored post title must be read through the owner, once, where every branch below picks it up'
        );
        $this->assertSame(
            1,
            substr_count($loop, "\$page['title']"),
            'and nowhere else in the loop may read the raw title'
        );
        // The styling-warning loop's stored component name, which sits between two loops
        // whose lines already route through _pp_cli_finding_line().
        $this->assertStringContainsString(
            "\$named = _pp_cli_printable((string) \$w['component']);",
            $loop,
            'the stored component name on the ambiguous-targeting line must go through the owner'
        );
    }

    /**
     * The SAME command's Custom CSS conflicts table, which sits above the per-page loop.
     *
     * Pinned separately because _pp_cli_printable_conflict_rows() exists precisely so two
     * commands cannot drift about which column is trusted — and only the OTHER caller
     * (`check conflicts`) is reachable by a behavioral test, for the static-cache reason
     * recorded above. A shared renderer with one untested call site is one revert away
     * from being two inline copies again.
     */
    public function testValidateSiteTablesItsConflictsThroughTheSharedRowRenderer(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/cli.php');
        $method = strpos($source, 'public function site(');
        $this->assertNotFalse($method, '`validate site` was not found');
        $body = substr($source, $method, 4000);

        $this->assertStringContainsString(
            "format_items('table', _pp_cli_printable_conflict_rows(\$conflicts), ['selector', 'component'])",
            $body,
            'the stored CSS selector must reach the table through the shared row renderer'
        );
    }

    /** The behavior that source slice is standing in for. */
    public function testTheOwnerDefangsAPageTitle(): void
    {
        $this->assertDefanged(_pp_cli_printable(self::HOSTILE), 'post title');
        $this->assertSame('My Page', _pp_cli_printable('My Page'), 'and an ordinary title is byte-identical');
    }

    /**
     * ONE MALFORMED BYTE MUST NOT DOWNGRADE THE GUARD (#647).
     *
     * `preg_replace` with `/u` returns null on a single malformed byte ANYWHERE in the
     * subject, and this owner used to answer that with a byte-wise `[\x00-\x1f\x7f]`
     * strip — a second, weaker definition of "clean" reached by exactly the input that
     * most warrants the strong one. It removed C0 and DEL but not `\p{Cf}` and not C1,
     * so appending `\xff` to any value carried the bidi and zero-width set straight
     * through to the terminal, on every sink in this file.
     *
     * These are the two vectors that demonstrated it, pinned verbatim as regression
     * tests rather than paraphrased. The fix is the idiom both sibling owners already
     * shipped: repair the encoding, re-run the SAME pattern.
     *
     * @dataProvider malformedBypassProvider
     */
    public function testAMalformedByteDoesNotSmuggleFormatOrC1Characters(string $label, string $input, string $needle): void
    {
        $this->assertStringNotContainsString(
            $needle,
            _pp_cli_printable($input),
            $label . ': one invalid byte must not downgrade the strip'
        );
    }

    public static function malformedBypassProvider(): array
    {
        return [
            // The measured bypass: U+202E survived as e2 80 ae behind a single 0xff.
            'bidi override behind an invalid byte' => ['bidi', "safe\xff\u{202E}evil", "\u{202E}"],
            // 0x9b is the 8-bit CSI — a terminal honours it exactly like ESC-[, and the
            // old fallback's range stopped at 0x7f, so it was never even a candidate.
            'C1 CSI behind an invalid byte'        => ['C1 CSI', "safe\xff\x9b31m", "\x9b"],
            // The zero-width set, same route.
            'zero-width behind an invalid byte'    => ['ZWSP', "safe\xff\u{200B}x", "\u{200B}"],
        ];
    }

    /**
     * The repair path must not become a way to LOSE the diagnostic either — the whole
     * reason this owner repairs rather than returning '(unprintable)'.
     */
    public function testTheRepairPathKeepsTheReadablePartOfTheString(): void
    {
        $this->assertStringContainsString(
            'evil',
            _pp_cli_printable("safe\xff\u{202E}evil"),
            'the readable text either side of the bad byte survives'
        );
        $this->assertStringContainsString('safe', _pp_cli_printable("safe\xff\u{202E}evil"));
    }

    /** And well-formed input is untouched by the new retry, since it never reaches it. */
    public function testWellFormedTextIsByteIdenticalThroughTheCliOwner(): void
    {
        foreach (['My Page', '.hero .btn', 'wp pp check page', 'héllo wörld', '数据'] as $text) {
            $this->assertSame($text, _pp_cli_printable($text), 'well-formed text must pass through unchanged');
        }
    }

    /**
     * `integrity status` reads the stored version out of the `pp_theme_integrity` OPTION
     * and prints it as prose. Only PP_VERSION ever writes that key, but nothing enforces
     * its shape at READ time — and the version comparison is exactly what makes this line
     * reachable with a value that is not PP_VERSION.
     */
    public function testTheIntegrityStalenessWarningStripsTheStoredVersion(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_theme_integrity'] = [
            'status'  => 'ok',
            'version' => self::HOSTILE,
        ];

        (new PP_Integrity_Command())->status([], []);

        // ASSERTED ON THE WARNING, not on everything the command printed. This command
        // also emits the whole option through _pp_cli_emit_json(), and that is the #717
        // MACHINE channel, not a prose one: json_encode escapes the escape byte and the
        // bidi override to  and ‮ by construction, and its pretty-printer
        // contributes real newlines of its own. Folding that JSON into a prose assertion
        // would test the wrong sink and fail for a reason that is not a defect.
        $warnings = implode(' | ', WP_CLI::$warnings);
        $this->assertStringContainsString('Results are from version', $warnings, 'premise: the staleness warning fired');
        $this->assertDefanged($warnings, 'integrity staleness warning');
        $this->assertStringContainsString(
            'version ' . self::HOSTILE_CLI . ',',
            $warnings,
            'and the stored version is still named, in cleaned form'
        );
    }

    /**
     * THE THREE CONVERTED SINKS NO TEST CAN DRIVE, pinned at the source instead.
     *
     * Each is behind setup this suite cannot honestly reach: the two `ai-chat.php` sinks
     * are AJAX callbacks behind a nonce and a capability check, and `apply restore`
     * requires a run token with a completed PREFLIGHT step, an apply capability, a frozen
     * token snapshot and a touched-key list. A tripwire that pins the WRAP is weaker than
     * a behavioral test and is not pretending otherwise — but it is strictly stronger than
     * the nothing these three had, and it fails loudly if a wrap is dropped.
     */
    public function testTheUndrivableSinksStillRouteThroughTheirOwners(): void
    {
        $chat = file_get_contents(dirname(__DIR__) . '/lib/ai-chat.php');
        $cli  = file_get_contents(dirname(__DIR__) . '/lib/cli.php');

        $this->assertStringContainsString(
            'wp_send_json_error(_pp_clean_reflected_text($result->get_error_message(), PP_REFLECTED_ERROR_MAX));',
            $chat,
            'the preview handler general branch must clean the validator message it ships'
        );
        $this->assertStringContainsString(
            "return ['ok' => false, 'data' => _pp_clean_reflected_text(\$result->get_error_message(), PP_REFLECTED_ERROR_MAX)];",
            $chat,
            'and the execute path WP_Error arm must use the same owner as the payload arm below it'
        );
        $this->assertStringContainsString(
            "'Token \"' . _pp_cli_printable((string) \$token) . '\" was not changed by run",
            $cli,
            'the token-restore success line must strip the argv token it quotes back'
        );
    }

    // ── C. Validator messages (lib/admin.php) ─────────────────────────────────

    /**
     * #647's Observed pair, first half: the #379 numeric-bounds rejection used a bare cast
     * where every nested rule beside it used the shared helper.
     */
    public function testTheNumericBoundsRejectionRoutesTheValueThroughTheOwner(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['columns' => str_repeat('9', 500), 'items' => [['title' => 'X']]]],
        ]);

        $message = $this->firstMessageContaining($errors, 'must be an integer between');
        $this->assertLessThan(400, strlen($message), 'a 500-character value must not be echoed whole');
        $this->assertStringContainsString('...', $message, 'and the truncation is marked');
    }

    /** And the hostile fixture at the same sink, which the length/type cases above miss. */
    public function testTheNumericBoundsRejectionDefangsAHostileValue(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['columns' => self::HOSTILE, 'items' => [['title' => 'X']]]],
        ]);

        $this->assertDefanged(
            $this->firstMessageContaining($errors, 'must be an integer between'),
            'numeric-bounds rejection'
        );
    }

    /**
     * The row the helper's own docblock exists for: `(string) false` is the empty string, so
     * the bare cast told an agent its rejected value was `""` when it was `false`.
     */
    public function testABooleanIsNamedAsABooleanInTheNumericBoundsRejection(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['columns' => false, 'items' => [['title' => 'X']]]],
        ]);

        $message = $this->firstMessageContaining($errors, 'must be an integer between');
        $this->assertStringContainsString('got false.', $message);
        $this->assertStringNotContainsString('got "".', $message);
    }

    /** #647's Observed pair, second half: the #380/#579 strict-enum rejection. */
    public function testTheStrictEnumRejectionRoutesTheValueThroughTheOwner(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['title' => 'X', 'spacing' => self::HOSTILE]],
        ]);

        $message = $this->firstMessageContaining($errors, 'must be one of');
        $this->assertDefanged($message, 'strict-enum rejection');
    }

    /**
     * BYTE-IDENTICAL FOR WELL-FORMED VALUES, which is the property that made this a safe
     * conversion rather than a message rewrite. The helper supplies the quotes the format
     * string used to carry, so an ordinary rejected value reads exactly as before.
     */
    public function testAnOrdinaryRejectedEnumValueIsByteIdentical(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['title' => 'X', 'spacing' => 'sunset']],
        ]);

        $this->assertStringContainsString('got "sunset".', $this->firstMessageContaining($errors, 'must be one of'));
    }

    /** Same for the numeric path, including the integer-shaped string case. */
    public function testAnOrdinaryRejectedNumericValueIsByteIdentical(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['columns' => '99', 'items' => [['title' => 'X']]]],
        ]);

        $this->assertStringContainsString('got "99".', $this->firstMessageContaining($errors, 'must be an integer between'));
    }

    /**
     * The #147 top-level unknown-prop gate, which echoed its KEY raw while the identical
     * species of key one level down (#643's RULE 5) went through the #633 bounder.
     *
     * RULE 5's own docblock recorded the asymmetry and named the direction to close it in:
     * "harmonizing the two means bounding #147, never unbounding this". Both issue bodies
     * pooled it into this axis on 2026-08-18. The two now call the same renderer.
     */
    public function testTheTopLevelUnknownPropGateBoundsItsKey(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['title' => 'X', self::HOSTILE => 'v']],
        ]);

        $message = $this->firstMessageContaining($errors, 'has no prop');
        $this->assertStringNotContainsString("\x1b", $message, 'the escape byte must not reach the envelope');
        $this->assertStringNotContainsString("\n", $message, 'nor the newline that fakes a second finding');
        $this->assertStringNotContainsString("\u{202E}", $message, 'nor the bidi override');
    }

    /**
     * BYTE-IDENTICAL for a well-formed key — the renderer emits the key BARE, so the format
     * string keeps the literal quotes it always carried and the sentence is unchanged. This
     * is why no existing message pin in SchemaValidationTest/WriteRejectionLocatorTest moved.
     */
    public function testAnOrdinaryUnknownPropKeyIsByteIdentical(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['title' => 'X', 'subtitle' => 'v']],
        ]);

        $this->assertStringContainsString(
            'has no prop "subtitle". Available props:',
            $this->firstMessageContaining($errors, 'has no prop')
        );
    }

    /** And bounded, at the key bounder's own cap rather than a second number. */
    public function testAnOverLongUnknownPropKeyIsBoundedAndMarked(): void
    {
        $key    = str_repeat('p', 300);
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['title' => 'X', $key => 'v']],
        ]);

        $message = $this->firstMessageContaining($errors, 'has no prop');
        $this->assertStringNotContainsString($key, $message, 'the whole key must not be echoed back');
        $this->assertStringContainsString(
            'has no prop "' . str_repeat('p', PP_UNDECLARED_KEY_MAX_LENGTH) . '..."',
            $message,
            'cut at the #633 bounder\'s cap and MARKED'
        );
    }

    /**
     * THE ONE TOP-LEVEL CASE THAT IS NOT BYTE-IDENTICAL, pinned so it is a choice.
     *
     * An EMPTY prop key used to render `has no prop ""`. The #633 bounder maps a key that
     * cleans to nothing onto `(unprintable key)` — which is what RULE 5 has always done for
     * an empty items[] field key. Harmonizing the two means adopting that answer here too;
     * inventing a third spelling for the top-level case is the split this axis exists to end.
     * An empty string is not a legitimate prop key on any authoring path.
     */
    public function testAnEmptyUnknownPropKeyAdoptsTheNestedRulesSpelling(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'hero', 'props' => ['title' => 'X', '' => 'v']],
        ]);

        $this->assertStringContainsString(
            'has no prop "(unprintable key)"',
            $this->firstMessageContaining($errors, 'has no prop')
        );
    }

    /**
     * SECTION 14.1, the authoring path: the conversions above are asserted on the validator,
     * but the surface that matters is the one an agent actually calls. This drives the REAL
     * `update_component` action and reads the ENVELOPE — the field the chat, the editor save
     * response and the CLI all render — rather than the WP_Error the engine returned.
     *
     * Both halves of the message are hostile at once: the KEY (#147's gate) and, on the
     * second call, the VALUE (#647's strict-enum path). One write, one envelope, no bytes.
     */
    public function testTheRealWriteEnvelopeCarriesNoHostileBytesFromAKeyOrAValue(): void
    {
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        try {
            $id = pp_create_page('Reflected text authoring path', 'draft');
            pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

            $by_key = pp_execute_action('update_component', [
                'post_id'         => $id,
                'component_index' => 0,
                'props'           => [self::HOSTILE => 'x'],
            ]);
            $this->assertFalse($by_key['ok'], 'premise: an undeclared prop key is still refused');
            $this->assertSame('unknown_prop', $by_key['error_code'], 'and still carries its code');
            $this->assertDefanged($by_key['error'], 'update_component envelope, hostile KEY');

            $by_value = pp_execute_action('update_component', [
                'post_id'         => $id,
                'component_index' => 0,
                'props'           => ['spacing' => self::HOSTILE],
            ]);
            $this->assertFalse($by_value['ok'], 'premise: an out-of-enum value is still refused');
            // Pinned to the ENUM path specifically. Without this, a future required-field
            // or shape gate firing first would satisfy assertFalse, and assertDefanged
            // would then pass trivially on a message that never carried the value.
            $this->assertSame('invalid_prop_value', $by_value['error_code'], 'and it is the enum refusal');
            $this->assertStringContainsString('must be one of', $by_value['error']);
            $this->assertDefanged($by_value['error'], 'update_component envelope, hostile VALUE');

            // Neither refusal wrote anything. Asserted on the two keys the calls tried to
            // set rather than on the whole prop bag, because the seeding
            // pp_update_composition() above legitimately adds a durable `id` of its own.
            $stored = pp_get_composition($id)[0]['props'];
            $this->assertSame('Hi', $stored['title'], 'the authored prop is untouched');
            $this->assertArrayNotHasKey(self::HOSTILE, $stored, 'no phantom key persisted');
            $this->assertArrayNotHasKey('spacing', $stored, 'and no rejected enum value persisted');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    private function firstMessageContaining(array $errors, string $needle): string
    {
        foreach ($errors as $error) {
            if (str_contains($error->get_error_message(), $needle)) {
                return $error->get_error_message();
            }
        }
        $this->fail('no error carried "' . $needle . '" — the fixture no longer trips the rule it targets');
    }

    // ── D. The AJAX execute payload (lib/ai-chat.php) ─────────────────────────

    /**
     * The EXECUTE path's error, which the preview path already cleaned and this one did not.
     *
     * Asserted on the payload builder directly: the endpoint around it needs a logged-in
     * user with capabilities, and the divergence being closed is entirely in here.
     */
    public function testTheExecuteErrorPayloadCleansTheCollapsedMessage(): void
    {
        $payload = _pp_ai_execute_error_payload(['ok' => false, 'error' => 'Refused: ' . self::HOSTILE], []);

        $this->assertIsString($payload);
        $this->assertDefanged($payload, 'execute error payload');
        $this->assertStringContainsString('Refused:', $payload, 'the message still says what it said');
    }

    /** And the structured conflict arm, which reflects the same field. */
    public function testTheConflictPayloadCleansItsErrorField(): void
    {
        $payload = _pp_ai_execute_error_payload(
            ['ok' => false, 'error_code' => 'composition_conflict', 'error' => 'Stale: ' . self::HOSTILE],
            []
        );

        $this->assertIsArray($payload);
        $this->assertSame('composition_conflict', $payload['error_code'], 'the machine-readable code is untouched');
        $this->assertDefanged($payload['error'], 'conflict payload error field');
    }

    /** An ordinary refusal is byte-identical, so the chat card reads as it always did. */
    public function testAnOrdinaryExecuteErrorIsByteIdentical(): void
    {
        $this->assertSame(
            'Component "hero" prop "theme" must be one of: light, dark; got "sunset".',
            _pp_ai_execute_error_payload(
                ['ok' => false, 'error' => 'Component "hero" prop "theme" must be one of: light, dark; got "sunset".'],
                []
            )
        );
    }

    /** The default is still the default. */
    public function testAFailureWithNoMessageStillCollapsesToTheDefault(): void
    {
        $this->assertSame('Execution failed.', _pp_ai_execute_error_payload(['ok' => false], []));
    }

    /**
     * The message is BOUNDED to the server's own number, which is the claim
     * tests/ChatUndoBoundTrait.php pins against the client constant. Before #647 the
     * client's bound was the only one this field ever met.
     */
    public function testALongExecuteErrorIsBoundedByTheServer(): void
    {
        $payload = _pp_ai_execute_error_payload(['ok' => false, 'error' => str_repeat('x', PP_REFLECTED_ERROR_MAX * 2)], []);

        $this->assertSame(PP_REFLECTED_ERROR_MAX, mb_strlen($payload), 'bounded to the server budget, in CHARACTERS');
        $this->assertStringEndsWith('...', $payload, 'and marked, so the cut is visible');
    }

    // ── E. The AJAX/editor channel (#864) ─────────────────────────────────────
    //
    // The remainder of the channel #647 opened. The sections above pin the terminal
    // channel and the two chat error payloads; these pin the editor's own AJAX
    // responses and the two NESTED fields that are not a one-line wrap.

    /**
     * A $_POST value the way WordPress actually delivers one.
     *
     * BOTH HANDLERS UNSLASH BEFORE DECODING — `stripslashes()` in
     * _pp_save_composition_response(), `wp_unslash()` in
     * _pp_ai_execute_batch_response() — because WordPress magic-quotes every $_POST
     * value during bootstrap (wp_magic_quotes()). A fixture that hands them plain
     * json_encode() output therefore gets the BACKSLASHES of its \uXXXX and \n escapes
     * removed, and decodes to the harmless LITERAL text uXXXX, so the
     * hostile bytes never reach the validator and the test passes for the wrong
     * reason — it proves nothing about cleaning, because there was nothing to clean.
     * Slash-escaping here is what makes these pins real.
     */
    private function postJson(array $value): string
    {
        return addslashes((string) wp_json_encode($value));
    }

    /** Seeds a page through the real writer, so its version marker is real too. */
    private function makePage(string $title, array $composition): int
    {
        $id = pp_create_page($title);
        pp_update_composition($id, $composition);
        return $id;
    }

    /**
     * A PHP file with its comments removed, so a source assertion is about CODE.
     *
     * WITHOUT THIS, EVERY SOURCE TRIPWIRE IN THIS FILE IS SATISFIED BY A COMMENT. Proven by
     * mutation on this very change: commenting out three of the real wraps in lib/admin.php
     * while leaving the needle text alive as a comment left the whole suite green. A pin a
     * comment can satisfy is not a pin — it is a pin-shaped string search that fails only
     * when someone deletes the words, which is the one way the guard was never going to be
     * lost. The idiom is the repo's own (tests/PpIsListContractTest.php).
     *
     * @param string $path  Absolute path to a PHP file.
     */
    private static function sourceWithoutComments(string $path): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    /**
     * The report-row twin of firstMessageContaining() above.
     *
     * A separate helper rather than a widened one: that one takes WP_Error OBJECTS
     * from the validation engines, these are the plain {check, message} ROWS
     * pp_post_apply_validate() returns, and a helper that accepted either would have to
     * guess which it was holding.
     *
     * @param array $rows  A validation report channel.
     */
    private function firstReportMessageContaining(array $rows, string $needle): string
    {
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['message']) && str_contains((string) $row['message'], $needle)) {
                return (string) $row['message'];
            }
        }
        $this->fail('no report row carried "' . $needle . '" — the fixture no longer trips the rule it targets');
    }

    /**
     * ONE DEFINITION OF CLEAN, AND IT IS REACHABLE FROM EVERY LOAD CONTEXT (#864).
     *
     * This is the census the owner move exists to make true, asserted rather than
     * argued. Before the move the owner lived in lib/ai-chat.php, which functions.php
     * loads only under is_admin(), so an always-loaded file could not call it without
     * depending on a conditionally-loaded one — the coupling #649 rejected for
     * _pp_item_index_label(). A second copy defined "for the other files" is the exact
     * failure this pins against.
     */
    public function testTheOwnerIsDefinedOnceInAnUnconditionallyLoadedFile(): void
    {
        $definitions = [];
        foreach (glob(dirname(__DIR__) . '/lib/*.php') as $file) {
            if (preg_match('/^\s*function\s+_pp_clean_reflected_text\s*\(/m', (string) file_get_contents($file))) {
                $definitions[] = basename($file);
            }
        }

        $this->assertSame(['wp.php'], $definitions, 'the reflected-text owner is defined exactly once, in lib/wp.php');

        // And lib/wp.php is required UNCONDITIONALLY — the half that makes the move
        // worth anything. Sliced at the first `if (` so a require that moved inside the
        // is_admin() or WP_CLI gate fails this rather than passing on a mere substring.
        $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/functions.php');
        $gate      = strpos($bootstrap, "\nif (");
        $this->assertNotFalse($gate, 'functions.php must still have its conditional load gates');
        $this->assertStringContainsString(
            "require_once get_template_directory() . '/lib/wp.php';",
            substr($bootstrap, 0, $gate),
            'lib/wp.php must be required before any conditional gate, or the owner is not always defined'
        );
    }

    // ── E1. The one nested shape: _pp_clean_reflected_report() ────────────────

    /** Both channels are cleaned, because both carry the same reflected messages. */
    public function testTheReportHelperDefangsBothChannels(): void
    {
        $cleaned = _pp_clean_reflected_report([
            'ok'       => false,
            'warnings' => [['check' => 'duplicate_component_id', 'message' => 'warned: ' . self::HOSTILE]],
            'errors'   => [['check' => 'empty_render', 'message' => 'failed: ' . self::HOSTILE]],
        ]);

        $this->assertDefanged($cleaned['warnings'][0]['message'], 'report warnings channel');
        $this->assertDefanged($cleaned['errors'][0]['message'], 'report errors channel');
        // The POSITIVE half: a helper that emptied the message would defang trivially.
        $this->assertStringContainsString('warned: aa', $cleaned['warnings'][0]['message']);
        $this->assertStringContainsString('failed: aa', $cleaned['errors'][0]['message']);
    }

    /** Everything AROUND the message is left exactly as the producer wrote it. */
    public function testTheReportHelperTouchesNothingButTheMessage(): void
    {
        $report = [
            'ok'       => false,
            'warnings' => [],
            'errors'   => [['check' => 'empty_render', 'component_index' => 3, 'message' => 'plain']],
        ];

        $this->assertSame($report, _pp_clean_reflected_report($report));
    }

    /**
     * Shapes the helper does not own are passed through, never coerced.
     *
     * `validation` is null whenever the caller passed no post_id, and every other case
     * here can only come from a producer that changed shape. Cleaning text is this
     * helper's job; normalizing someone else's array is not.
     */
    public static function unownedReportShapeProvider(): array
    {
        return [
            'null (no post_id, validation skipped)' => [null],
            'not an array at all'                   => ['a string'],
            'channel that is not a list'            => [['errors' => 'nope']],
            'row that is not an array'              => [['errors' => ['nope']]],
            'row with no message'                   => [['errors' => [['check' => 'x']]]],
            'message that is not a string'          => [['errors' => [['message' => 42]]]],
            'both channels absent'                  => [['ok' => true]],
        ];
    }

    /** @dataProvider unownedReportShapeProvider */
    public function testTheReportHelperPassesThroughShapesItDoesNotOwn($report): void
    {
        $this->assertSame($report, _pp_clean_reflected_report($report));
    }

    /**
     * The helper's SIZE bound is the owner's, which is the whole point of delegating.
     *
     * There is no row counter here on purpose (see the helper's docblock): what bounds
     * the text is that every string goes through _pp_clean_reflected_text() at
     * PP_REFLECTED_ERROR_MAX. This pins that the delegation actually happens rather
     * than the message being copied across.
     */
    public function testTheReportHelperBoundsAMessageToTheOwnersBudget(): void
    {
        $cleaned = _pp_clean_reflected_report([
            'errors' => [['message' => str_repeat('x', PP_REFLECTED_ERROR_MAX * 2)]],
        ]);

        $this->assertSame(PP_REFLECTED_ERROR_MAX, mb_strlen($cleaned['errors'][0]['message']));
        $this->assertStringEndsWith('...', $cleaned['errors'][0]['message'], 'and the cut is marked');
    }

    /**
     * The bound holds for a budget SMALLER than its own truncation marker.
     *
     * `$max_length - 3` goes negative below 3, and a negative length makes mb_substr() cut
     * from the END — so the branch whose only job is to enforce the budget used to return
     * eight characters for a budget of two. Unreachable through PP_REFLECTED_NAME_MAX or
     * PP_REFLECTED_ERROR_MAX, and pinned precisely because the owner is now always-loaded
     * and takes an arbitrary int: the next caller does not have to know this.
     */
    public function testTheOwnerHonoursBudgetsSmallerThanItsTruncationMarker(): void
    {
        foreach ([0, 1, 2, 3, 4] as $budget) {
            $result = _pp_clean_reflected_text('abcdefghij', $budget);

            $this->assertLessThanOrEqual(
                max($budget, 3),
                mb_strlen($result),
                "a budget of $budget must not produce a longer string than the budget or the marker"
            );
        }

        // And the shipped budgets are untouched by the guard.
        $this->assertSame(
            PP_REFLECTED_ERROR_MAX,
            mb_strlen(_pp_clean_reflected_text(str_repeat('x', PP_REFLECTED_ERROR_MAX * 2), PP_REFLECTED_ERROR_MAX))
        );
    }

    // ── E2. The editor save response, driven end to end ───────────────────────

    /**
     * THE SITE THIS ISSUE IS NAMED FOR, driven through the real handler.
     *
     * Section 14.1: the composition is authored through _pp_save_composition_response()
     * — the body of the wp_ajax_pp_save_composition closure — which runs the real
     * `update_composition` action and the real validator, not a helper-only slice. The
     * message it rejects with is composed prose quoting a component name the caller
     * supplied, and before #864 it reached the editor's error banner verbatim.
     */
    public function testTheEditorSaveResponseCleansTheRejectedWritesMessage(): void
    {
        $id = $this->makePage('Editor save', [['component' => 'hero', 'props' => ['title' => 'A']]]);

        $resp = _pp_save_composition_response([
            'post_id'     => $id,
            'nonce'       => 'valid-in-the-stub',
            'composition' => $this->postJson([['component' => self::HOSTILE, 'props' => ['title' => 'A']]]),
        ]);

        $this->assertFalse($resp['ok'], 'premise: an unregistered component name is refused');
        $this->assertIsArray($resp['data']);
        $this->assertDefanged($resp['data']['message'], 'editor save response');
        $this->assertStringContainsString(
            'Unknown component: "aa',
            $resp['data']['message'],
            'and the refused name is still quoted back, in cleaned form'
        );
        // The STRUCTURED half is untouched: the editor branches on this, not on the prose.
        $this->assertSame('invalid_composition', $resp['data']['code']);
    }

    /**
     * A well-formed rejection is BYTE-IDENTICAL — the property that makes this safe to
     * ship. Asserted against the envelope the action itself produced, so it cannot
     * drift with the validator's wording.
     */
    public function testAnOrdinaryEditorSaveRejectionIsByteIdentical(): void
    {
        $id = $this->makePage('Editor save plain', [['component' => 'hero', 'props' => ['title' => 'A']]]);
        $bad = [['component' => 'no-such-component', 'props' => ['title' => 'A']]];

        $envelope = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => $bad]);
        $resp     = _pp_save_composition_response([
            'post_id'     => $id,
            'nonce'       => 'valid-in-the-stub',
            'composition' => $this->postJson($bad),
        ]);

        $this->assertFalse($envelope['ok'], 'premise: the action refused it');
        $this->assertSame($envelope['error'], $resp['data']['message'], 'the message the sink ships is the message the action wrote');
    }

    /**
     * The happy path is untouched, and so are the three pre-flight refusals.
     *
     * The extraction that made this handler testable must not have changed what it
     * answers — a refactor that quietly altered the accepted path would be a far worse
     * regression than the one #864 fixes.
     */
    public function testTheEditorSaveResponseStillAcceptsAGoodWrite(): void
    {
        $id = $this->makePage('Editor save good', [['component' => 'hero', 'props' => ['title' => 'A']]]);

        $resp = _pp_save_composition_response([
            'post_id'     => $id,
            'nonce'       => 'valid-in-the-stub',
            'composition' => $this->postJson([['component' => 'hero', 'props' => ['title' => 'B']]]),
        ]);

        $this->assertTrue($resp['ok']);
        $this->assertSame('B', $resp['data']['composition'][0]['props']['title'], 'the write really landed');
        $this->assertSame(pp_get_composition_marker($id)['version'], $resp['data']['version']);
    }

    /** @return array<string, array{array, string}> */
    public static function editorSavePreflightProvider(): array
    {
        return [
            'no post_id'   => [['nonce' => 'x'], 'Invalid nonce.'],
            'no nonce'     => [['post_id' => 9999], 'Invalid nonce.'],
            'bad JSON'     => [['post_id' => 9999, 'nonce' => 'x', 'composition' => '{not json'], 'Invalid JSON.'],
        ];
    }

    /**
     * @dataProvider editorSavePreflightProvider
     *
     * BYTE-EXACT ON PURPOSE. These three are theme-authored literals and are deliberately
     * NOT routed through the owner — and the editor DEPENDS on that: saveErrorMessage()
     * (assets/js/pp-admin-editor.js) compares `msg === 'Invalid nonce.'` to swap in the
     * "Session expired" prose. Cleaning a literal would be a no-op today and a silent
     * behaviour change the day one of them gained a character the strip removes.
     *
     * The set is the STRING-bodied malformed cases. A non-string `composition` (what
     * WordPress delivers for `composition[]=x`) crashes in stripslashes() before any of
     * these are reached — pre-existing, unchanged by #864, filed separately.
     */
    public function testTheEditorSavePreflightRefusalsAreUnchanged(array $post, string $expected): void
    {
        $resp = _pp_save_composition_response($post);

        $this->assertFalse($resp['ok']);
        $this->assertSame($expected, $resp['data'], 'theme-authored literals, deliberately unguarded');
    }

    /**
     * The capability guard, pinned for the DENIED case.
     *
     * The extraction is what made this reachable from PHPUnit at all, and reachable
     * means owed a test: in production `wp_send_json_error()` DIES, so the old closure
     * got its "stop here" from the sink; the extracted function gets it from `return`.
     * Asserting the refusal alone would pass against a handler that refused and wrote
     * anyway, so the stored composition is checked too.
     */
    public function testTheEditorSaveResponseRefusesAWriterWithoutEditRights(): void
    {
        $id = $this->makePage('Editor save denied', [['component' => 'hero', 'props' => ['title' => 'A']]]);

        $GLOBALS['_pp_test_user_caps'] = ['edit_post' => false];
        try {
            $resp = _pp_save_composition_response([
                'post_id'     => $id,
                'nonce'       => 'valid-in-the-stub',
                'composition' => $this->postJson([['component' => 'hero', 'props' => ['title' => 'B']]]),
            ]);
        } finally {
            unset($GLOBALS['_pp_test_user_caps']);
        }

        $this->assertFalse($resp['ok']);
        $this->assertSame('Insufficient permissions.', $resp['data'], 'a theme-authored literal, deliberately unguarded');
        $this->assertSame('A', pp_get_composition($id)[0]['props']['title'], 'and the refused write never landed');
    }

    /**
     * The #13 compare-and-swap, threaded from the REQUEST ARRAY the extraction now takes.
     *
     * The riskiest line in the whole extraction is `_pp_expected_version_from_request($post)`:
     * a `$_POST` left behind there reads the ambient superglobal instead of the argument,
     * which in production is the SAME array and would therefore never fail — until a caller
     * passes anything else. Pinning both halves kills that and the "guard deleted entirely"
     * mutation at once, and it is the only test that produces `composition_conflict` through
     * this handler, which is the whole reason the payload carries a structured `code`.
     */
    public function testTheEditorSaveResponseThreadsTheOptimisticLockingBaseline(): void
    {
        $id      = $this->makePage('Editor save CAS', [['component' => 'hero', 'props' => ['title' => 'A']]]);
        $current = pp_get_composition_marker($id)['version'];

        $stale = _pp_save_composition_response([
            'post_id'          => $id,
            'nonce'            => 'valid-in-the-stub',
            'expected_version' => (string) ($current - 1),
            'composition'      => $this->postJson([['component' => 'hero', 'props' => ['title' => 'B']]]),
        ]);

        $this->assertFalse($stale['ok'], 'a stale baseline must not clobber an interleaved write');
        $this->assertSame('composition_conflict', $stale['data']['code']);
        $this->assertSame('A', pp_get_composition($id)[0]['props']['title'], 'the stale write never landed');

        $ok = _pp_save_composition_response([
            'post_id'          => $id,
            'nonce'            => 'valid-in-the-stub',
            'expected_version' => (string) $current,
            'composition'      => $this->postJson([['component' => 'hero', 'props' => ['title' => 'B']]]),
        ]);

        $this->assertTrue($ok['ok'], 'and a current baseline still saves');
        $this->assertSame('B', $ok['data']['composition'][0]['props']['title']);
    }

    /**
     * THE SIX EDITOR SINKS, pinned at the source like their lib/ai-chat.php neighbours.
     *
     * add_action() is a no-op in this bootstrap, so a closure body is unreachable from
     * PHPUnit. #864 extracted ONE of the six — the save handler, driven behaviourally
     * above — because it is the highest-value path and because extracting all six would
     * be a refactor of the whole editor AJAX surface inside a text-cleaning change. The
     * other five are spellings of one rule, and a tripwire that fails loudly when a wrap
     * is dropped is strictly stronger than the nothing they had.
     *
     * THE SAVE SINK IS PINNED HERE TOO, belt and braces: its behavioural test proves the
     * bytes are clean, this proves the CLEANING IS STILL WHERE IT SAYS IT IS — a
     * refactor that moved it somewhere subtler would keep the behavioural pin green.
     *
     * Three of the six share `_pp_editor_error_payload()`, so the rule is asserted ONCE
     * in the helper plus once per call site. Pinning the helper's body alone would let a
     * call site quietly stop using it; pinning the call sites alone would let the rule
     * change underneath all three at once.
     *
     * Matched on STRINGS, never on line numbers: this file's own inventory notes that
     * positions drift, and a pin that rots into a line offset teaches maintainers to
     * delete tripwires.
     */
    public function testTheEditorAjaxSinksStillRouteThroughTheOwner(): void
    {
        $admin = self::sourceWithoutComments(dirname(__DIR__) . '/lib/admin.php');

        $wraps = [
            'the shared structured payload cleans the message and leaves the code alone'
                => "'message' => _pp_clean_reflected_text((string) \$result['error'], PP_REFLECTED_ERROR_MAX),",
            'and it does not guard the machine-readable code'
                => "'code'    => \$result['error_code'] ?? '',",
            'the save endpoint builds its rejection through that helper'
                => "return ['ok' => false, 'data' => _pp_editor_error_payload(\$result)];",
            'the publish endpoint\'s save arm does too'
                => 'wp_send_json_error(_pp_editor_error_payload($save_result));',
            'and its publish arm does too'
                => 'wp_send_json_error(_pp_editor_error_payload($pub_result));',
            'the preview endpoint cleans the validator message it ships'
                => 'wp_send_json_error(_pp_clean_reflected_text($result->get_error_message(), PP_REFLECTED_ERROR_MAX));',
            'the preview endpoint\'s WP_DEBUG render-failure arm cleans the Throwable message'
                => "wp_send_json_error(_pp_clean_reflected_text('Render failed: ' . \$e->getMessage(), PP_REFLECTED_ERROR_MAX));",
            'the title endpoint cleans its rejection'
                => "wp_send_json_error(_pp_clean_reflected_text((string) \$result['error'], PP_REFLECTED_ERROR_MAX));",
        ];

        foreach ($wraps as $why => $needle) {
            $this->assertStringContainsString($needle, $admin, $why);
        }
    }

    /**
     * The ADAPTER, tripwired — the half the extraction moved rather than removed.
     *
     * Every behavioural pin in this section drives `_pp_save_composition_response()`
     * directly. If the closure stopped calling it, or transposed the ok/error arms, all
     * of them would keep passing against a function no longer wired to the endpoint —
     * which is exactly the "helper-only slice" failure the #387 lesson names, one layer
     * further out than where #387 found it.
     */
    public function testTheSaveClosureStillDelegatesToTheExtractedResponder(): void
    {
        $admin = self::sourceWithoutComments(dirname(__DIR__) . '/lib/admin.php');

        $this->assertStringContainsString(
            '$resp = _pp_save_composition_response($_POST);',
            $admin,
            'the wp_ajax_pp_save_composition closure must still delegate, or this section tests a dead function'
        );
        $this->assertStringContainsString(
            "    if (\$resp['ok']) {\n        wp_send_json_success(\$resp['data']);\n    } else {\n        wp_send_json_error(\$resp['data']);\n    }",
            $admin,
            'and success/error must not be transposed'
        );
    }

    // ── E3. The two nested chat payload fields, driven end to end ─────────────

    /**
     * `data.validation.errors[].message` on the SUCCESS envelope (#864).
     *
     * The asymmetry this closes lived inside one response: every failure arm of this
     * endpoint has been cleaned since v1.17.8, while the validation report attached to
     * a SUCCEEDED step shipped raw — so whether a stored bidi sequence reached the chat
     * card depended only on whether the step had worked.
     *
     * `duplicate_component_id` is the row driven here because it reflects a stored
     * `props.id` through a scan that runs whatever the components render, so the
     * fixture needs no unregistered component and raises no render warning.
     */
    public function testTheExecuteSuccessPayloadCleansItsValidationReport(): void
    {
        $id = $this->makePage('Nested validation', [
            ['component' => 'hero', 'props' => ['title' => 'A', 'id' => self::HOSTILE]],
            ['component' => 'hero', 'props' => ['title' => 'B', 'id' => self::HOSTILE]],
        ]);

        $resp = _pp_ai_execute_response([
            'type' => 'action', 'name' => 'update_page_title',
            'params' => ['post_id' => $id, 'title' => 'Renamed'],
        ]);

        $this->assertTrue($resp['ok'], 'premise: the action succeeded, so this is the success payload');
        $duplicate = $this->firstReportMessageContaining($resp['data']['validation']['warnings'], 'duplicate ID');
        $this->assertDefanged($duplicate, 'execute success payload validation report');
        $this->assertStringContainsString("duplicate ID 'aa", $duplicate, 'and the stored id is still named');
    }

    /**
     * The same report's THEME-AUTHORED rows are byte-identical through the same helper.
     *
     * Stated as its own test because "everything was defanged" and "everything was
     * mangled" are indistinguishable without it.
     */
    public function testTheValidationReportsOwnSentencesAreByteIdentical(): void
    {
        $id = $this->makePage('Nested plain', [['component' => 'hero', 'props' => ['title' => 'A']]]);

        $resp = _pp_ai_execute_response([
            'type' => 'action', 'name' => 'update_page_title',
            'params' => ['post_id' => $id, 'title' => 'Renamed'],
        ]);

        $direct = pp_post_apply_validate($id);
        $this->assertSame(
            array_column($direct['warnings'], 'message'),
            array_column($resp['data']['validation']['warnings'], 'message'),
            'a report with nothing to clean travels through the sink unchanged'
        );
    }

    /**
     * `data.steps[i].error` — the batch twin of the single-execute payload.
     *
     * Cleaned at the CHAT entry point rather than inside pp_ai_execute_batch(), because
     * that executor is shared with WP-CLI, whose channel strips at its own sink. This
     * drives the real batch through the real CAS mandate.
     */
    public function testTheBatchPayloadCleansAFailedStepsError(): void
    {
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        try {
            $id       = $this->makePage('Batch step error', [['component' => 'hero', 'props' => ['title' => 'A']]]);
            $baseline = pp_get_composition_marker($id)['version'];

            $resp = _pp_ai_execute_batch_response([
                'steps' => $this->postJson([
                    ['type' => 'action', 'name' => 'add_component',
                     'params' => ['post_id' => $id, 'component' => self::HOSTILE, 'props' => ['title' => 'X']]],
                ]),
                'baselines' => wp_json_encode([(string) $id => $baseline]),
            ]);

            $this->assertTrue($resp['ok'], 'premise: the batch ran and reported per-step');
            $this->assertFalse($resp['data']['steps'][0]['ok'], 'premise: the step was refused');
            $this->assertDefanged($resp['data']['steps'][0]['error'], 'batch step error');
            $this->assertStringContainsString(
                'Unknown component: "aa',
                $resp['data']['steps'][0]['error'],
                'and the refused name is still quoted back, in cleaned form'
            );
            $this->assertSame('invalid_composition', $resp['data']['steps'][0]['error_code'], 'the literal code is untouched');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    /** `data.steps[i].validation` — the same report shape, one level deeper. */
    public function testTheBatchPayloadCleansANestedValidationReport(): void
    {
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        try {
            $id = $this->makePage('Batch nested validation', [
                ['component' => 'hero', 'props' => ['title' => 'A', 'id' => self::HOSTILE]],
                ['component' => 'hero', 'props' => ['title' => 'B', 'id' => self::HOSTILE]],
            ]);

            $resp = _pp_ai_execute_batch_response([
                'steps' => $this->postJson([
                    ['type' => 'action', 'name' => 'update_page_title',
                     'params' => ['post_id' => $id, 'title' => 'Batch renamed']],
                ]),
                'baselines' => wp_json_encode([]),
            ]);

            $this->assertTrue($resp['ok']);
            $this->assertTrue($resp['data']['steps'][0]['ok'], 'premise: the step succeeded, so it carries a report');
            $duplicate = $this->firstReportMessageContaining(
                $resp['data']['steps'][0]['validation']['warnings'],
                'duplicate ID'
            );
            $this->assertDefanged($duplicate, 'batch step nested validation report');
            $this->assertStringContainsString("duplicate ID 'aa", $duplicate, 'and the stored id is still named');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    // ── E4. _pp_build_friendly_error()'s hinted branch ────────────────────────

    /**
     * Two branches of one switch arm, one answer (#864).
     *
     * The no-hint branch has routed `$component_name` through the owner since #661,
     * inside _pp_no_hint_slot_message(); the HINTED branch interpolated the identical
     * value raw. Which one a caller got depended on whether a cross-component hint
     * happened to match — so the guarantee could not be stated at all.
     *
     * The fixture asks for `--hero-bg` on a component that does not declare it, which
     * suffix-matches `--cta-bg` on cta and therefore lands on the hinted branch.
     */
    public function testTheHintedFriendlyErrorCleansTheStoredComponentName(): void
    {
        $id = $this->makePage('Hinted', [['component' => self::HOSTILE, 'props' => ['title' => 'A']]]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component has no style slot "--hero-bg".'),
            ['post_id' => $id, 'component_index' => 0, 'style' => ['--hero-bg' => 'red']]
        );

        $this->assertNotSame([], (array) $friendly['cross_component_hints'], 'premise: this is the HINTED branch');
        $this->assertDefanged($friendly['user_message'], 'friendly error hinted branch');
        $this->assertStringContainsString('on the aa', $friendly['user_message'], 'and the component is still named');
    }

    /** An ordinary stored name is byte-identical through the same wrap. */
    public function testAnOrdinaryComponentNameIsByteIdenticalInTheHintedBranch(): void
    {
        $id = $this->makePage('Hinted plain', [['component' => 'hero', 'props' => ['title' => 'A']]]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component has no style slot "--nope-bg".'),
            ['post_id' => $id, 'component_index' => 0, 'style' => ['--nope-bg' => 'red']]
        );

        $this->assertNotSame(
            [],
            (array) $friendly['cross_component_hints'],
            'premise: this must reach the HINTED branch — a skip here would delete the assertion silently'
        );
        $this->assertStringContainsString('on the hero component', $friendly['user_message']);
    }

    /**
     * The case the wrap actually CHANGES: a name that is non-empty going in and empty
     * coming out.
     *
     * A stored component name made entirely of format characters is invisible but not
     * absent, so before #864 the hinted branch printed it and the sentence read "on the
     *  component" with a hole in it. Cleaning collapses it to '', and the `?:` kept in
     * this branch is the only thing that turns that into "selected". This is why the `?:`
     * was NOT swapped for the sibling's `=== ''` test: the fallback has to survive the
     * wrap, and nothing else in the section exercises the empty-after-cleaning path —
     * the neighbouring test feeds a name that was already empty before cleaning, which
     * is the branch the pre-#864 code took too.
     *
     * Built with mb_chr() rather than a string escape, deliberately: authoring tools
     * silently turn escape TEXT into the character it names, so a fixture that must
     * contain specific invisible code points is safer constructed than quoted.
     */
    public function testAnAllInvisibleComponentNameFallsBackToSelectedInTheHintedBranch(): void
    {
        $invisible = mb_chr(0x202E, 'UTF-8') . mb_chr(0x200B, 'UTF-8');
        $this->assertNotSame('', $invisible, 'premise: the fixture is non-empty going in');

        $id = $this->makePage('Hinted invisible', [['component' => $invisible, 'props' => ['title' => 'A']]]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component has no style slot "--hero-bg".'),
            ['post_id' => $id, 'component_index' => 0, 'style' => ['--hero-bg' => 'red']]
        );

        $this->assertNotSame([], (array) $friendly['cross_component_hints'], 'premise: this is the HINTED branch');
        $this->assertStringContainsString('on the selected component', $friendly['user_message']);
        $this->assertDefanged($friendly['user_message'], 'friendly error hinted branch, all-invisible name');
    }

    /**
     * The empty name still reads as "selected", which is why the `?:` was KEPT rather
     * than swapped for the sibling's `=== ''` test. Cleaning an empty string returns an
     * empty string, so the fallback has to survive the wrap or every hint-bearing
     * rejection on a nameless target would say "on the  component".
     */
    public function testAnUnresolvedComponentStillReadsAsSelectedInTheHintedBranch(): void
    {
        $id = $this->makePage('Hinted nameless', [['component' => '', 'props' => ['title' => 'A']]]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component has no style slot "--hero-bg".'),
            ['post_id' => $id, 'component_index' => 0, 'style' => ['--hero-bg' => 'red']]
        );

        if ((array) $friendly['cross_component_hints'] === []) {
            $this->markTestSkipped('no hint for this key on the shipped registry');
        }
        $this->assertStringContainsString('on the selected component', $friendly['user_message']);
    }
}
