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
 *     lib/ai-chat.php responses  ──► _pp_clean_reflected_text()      (strip + bound + repair)
 *
 * No new sanitizer, no new constant, no second definition of "safe to echo back". This file
 * pins the CONVERSIONS — one test per surface — plus the two properties that make the change
 * safe to ship: well-formed text is BYTE-IDENTICAL through every one of them, and the sites
 * deliberately left alone stay legible.
 *
 * WHAT THIS FILE DOES *NOT* CLAIM. The rule above is stated for the surfaces #647/#649
 * inventoried, not for every sink in the theme, and reading it as universal would let a
 * future reader mistake an unguarded site for an audited one. Known-unguarded, on purpose:
 *
 *   - the COMPOSED-MESSAGE cluster — lib/admin.php's wp_send_json_error editor-save sites,
 *     `Unknown component: "%s"` at lib/admin.php, the raw $component_name in
 *     _pp_build_friendly_error()'s hinted branch, and two nested chat payload fields.
 *     Deferred to #864, which carries the open owner question;
 *   - the ~23 sibling `Component "%s"` messages, which reflect a stored component name
 *     verbatim. #649 treats that family's spelling as the reference point, not the target;
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
}
