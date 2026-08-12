<?php
/**
 * tests/DiagnosticReachTest.php — read-only diagnostics reach + locators (#622).
 *
 * #604 removed the legacy prop-key alias map, the `variant` read migration and the
 * `type` -> `component` alias. Stale-data breakage is the INTENDED outcome. But the
 * surfaces an operator or agent queries FIRST to discover that breakage did not
 * report it, so a stale page looked healthy right up until an edit was refused:
 *
 *   1. `wp pp check page` / `wp pp validate site` called pp_validate_composition_styling()
 *      + pp_validate_composition_smells() and never pp_validate_composition_errors(),
 *      so a composition current write rules REJECT printed "no composition smells" /
 *      "Site validation passed".
 *   2. Error-derived findings hardcoded `'index' => null`, so on a page with two `cta`
 *      bands the operator could not tell which one was dead.
 *   3. A missing-required-prop error named only the canonical prop, never the
 *      unrecognized keys the item was actually carrying — unactionable when the value
 *      sits under a retired name.
 *
 * What this file pins, and one thing it deliberately does NOT: there is no alias
 * lookup anywhere in the fix. The help in the missing-prop message is derived from the
 * component's CURRENT schema (declared vs. present keys). A static "formerly known as"
 * list would be the alias machinery #603/#604/#605/#606 removed, under another name.
 *
 * Section 14.1 (authoring path): the enriched message is exercised through the real
 * write surface (pp_execute_action('update_composition')), not only through the
 * validator, so the contract a caller actually meets is the one asserted.
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with CliGateTest/ReadinessFindingsTest) ──────────
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
        public static function line($message = ''): void { self::$lines[] = (string) $message; }
        public static function warning($message = ''): void { self::$warnings[] = (string) $message; }
        public static function success($message = ''): void { self::$successes[] = (string) $message; }
        public static function debug($message = '', $group = false): void {}
        public static function log($message = ''): void {}
        public static function halt($code = 0): void { throw new WpCliHaltException((string) $code, (int) $code); }
    }
}

require_once dirname(__DIR__) . '/lib/cli.php';

class DiagnosticReachTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
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

    /**
     * The exact repro from #622: a cta authored with the retired `cta_text` / `cta_url`
     * keys, plus a hero carrying an undeclared `subtitle`.
     */
    private function staleComposition(): array
    {
        return [
            ['component' => 'cta',  'props' => ['title' => 'Ready?', 'cta_text' => 'Go', 'cta_url' => '/go']],
            ['component' => 'hero', 'props' => ['title' => 'Hi', 'subtitle' => 'There']],
        ];
    }

    private function seedPage(int $id, array $composition, string $title = 'Stale Page'): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'ID' => $id, 'post_title' => $title, 'post_type' => 'page', 'post_status' => 'publish',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_wp_page_template'] = 'composition.php';
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_pp_composition']   = wp_json_encode($composition);
    }

    // ── 1. The validator still finds the breakage (the premise, restated) ──────

    public function testTheStaleCompositionIsRejectedByCurrentWriteRules(): void
    {
        $errors = pp_validate_composition_errors($this->staleComposition());

        $this->assertNotSame([], $errors, '#604 makes this composition invalid; that is the intended break.');
        $codes = array_map(static fn ($e) => $e->get_error_code(), $errors);
        $this->assertContains('invalid_composition', $codes, 'cta is missing required prop button_text');
        $this->assertContains('unknown_prop', $codes, 'hero has no prop subtitle');
    }

    // ── 2. Locators: findings carry the band, not null (#622 gap 2) ────────────

    public function testEveryPerItemErrorCarriesItsCompositionOffset(): void
    {
        $errors = pp_validate_composition_errors($this->staleComposition());

        $this->assertSame(0, pp_composition_error_index($errors[0]), 'the cta is item 0');
        $this->assertSame(1, pp_composition_error_index($errors[1]), 'the hero is item 1');
    }

    public function testErrorDerivedFindingsCarryTheBandIndex(): void
    {
        $findings = _pp_composition_findings($this->staleComposition());
        $errors   = array_values(array_filter($findings, static fn ($f) => $f['severity'] === 'error'));

        $this->assertCount(2, $errors);
        $this->assertSame(0, $errors[0]['index'], "the pre-#622 value was null — 'which band?' was unanswerable");
        $this->assertSame(1, $errors[1]['index']);
    }

    /**
     * Two `cta` bands, only the second broken: the locator is what distinguishes them,
     * because both messages name the same component TYPE.
     */
    public function testTheLocatorDistinguishesTwoBandsOfTheSameType(): void
    {
        $findings = _pp_composition_findings([
            ['component' => 'cta', 'props' => ['title' => 'Fine', 'button_text' => 'Go', 'button_url' => '/go']],
            ['component' => 'cta', 'props' => ['title' => 'Broken', 'cta_text' => 'Go']],
        ]);
        $errors = array_values(array_filter($findings, static fn ($f) => $f['severity'] === 'error'));

        $this->assertCount(1, $errors);
        $this->assertSame(1, $errors[0]['index'], 'the SECOND cta is the dead one');
    }

    /**
     * A cross-item rule belongs to no single band. Reporting index 0 would be a lie, so
     * it stays null and the message names every colliding index instead.
     */
    public function testACrossItemErrorReportsNoSingleBandLocator(): void
    {
        $findings = _pp_composition_findings([
            ['component' => 'cta',  'props' => ['id' => 'dupe', 'title' => 'A', 'button_text' => 'Go', 'button_url' => '/a']],
            ['component' => 'hero', 'props' => ['id' => 'dupe', 'title' => 'B']],
        ]);
        // The collision produces BOTH an error and its advisory smell twin; only the
        // error side is the cross-item rule under test here.
        $dupes = array_values(array_filter(
            $findings,
            static fn ($f) => $f['type'] === 'duplicate_component_id' && $f['severity'] === 'error'
        ));

        $this->assertCount(1, $dupes);
        $this->assertNull($dupes[0]['index']);
        $this->assertStringContainsString('0, 1', $dupes[0]['message'], 'the message carries both indices');
    }

    /**
     * The two style-slot restamp sites are the only non-mechanical stampings in #622:
     * they rebuild a WP_Error the shared slot engine produced, which has no view of the
     * composition offset. The per-item one deliberately reports the BAND while its
     * message names the CARD — a locator that points at the wrong band is worse than
     * none, so both are pinned.
     */
    public function testStyleSlotErrorsCarryTheBandOffsetNotTheCardIndex(): void
    {
        $findings = _pp_composition_findings([
            ['component' => 'hero', 'props' => ['title' => 'A']],
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'x'],
                ['title' => 'y', 'style' => ['nope_slot' => 'red']],
            ]]],
        ]);
        $slot = array_values(array_filter($findings, static fn ($f) => $f['type'] === 'invalid_style_slot'));

        $this->assertCount(1, $slot);
        $this->assertSame(1, $slot[0]['index'], 'the BAND offset, not the card index the message names');
        $this->assertStringContainsString('item 1', $slot[0]['message']);
    }

    public function testAComponentLevelStyleSlotErrorCarriesItsBandOffset(): void
    {
        $findings = _pp_composition_findings([
            ['component' => 'hero', 'props' => ['title' => 'A']],
            ['component' => 'hero', 'props' => ['title' => 'B'], 'style' => ['nope_slot' => 'red']],
        ]);
        $slot = array_values(array_filter($findings, static fn ($f) => $f['type'] === 'invalid_style_slot'));

        $this->assertCount(1, $slot);
        $this->assertSame(1, $slot[0]['index']);
    }

    /**
     * The reader's guards, direct. Dropping `is_int` would let a string index reach the
     * finding payload and every CLI/JSON consumer of it; dropping the array guard would
     * fatal on a WP_Error nobody stamped.
     */
    public function testTheErrorIndexReaderIsHonestAboutAnUnstampedError(): void
    {
        $this->assertNull(pp_composition_error_index(new WP_Error('x', 'y')));
        $this->assertNull(pp_composition_error_index(new WP_Error('x', 'y', 'not-an-array')));
        $this->assertNull(pp_composition_error_index(new WP_Error('x', 'y', ['index' => '2'])), 'no coerced string index');
        $this->assertSame(0, pp_composition_error_index(new WP_Error('x', 'y', ['index' => 0])));
        $this->assertSame(7, pp_composition_error_index(new WP_Error('x', 'y', ['index' => 7])));
    }

    public function testSmellLocatorsAreUnchanged(): void
    {
        $findings = _pp_composition_findings([
            ['component' => 'nav'],
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);
        $smells = array_values(array_filter($findings, static fn ($f) => $f['severity'] === 'warning'));

        $this->assertNotSame([], $smells);
        foreach ($smells as $smell) {
            $this->assertIsInt($smell['index'], 'smells always carried a real index; that must not regress');
        }
    }

    // ── 3. The missing-required-prop message names the unknown keys (#622 gap 3) ──

    public function testAMissingRequiredPropNamesTheUndeclaredKeysOnTheSameItem(): void
    {
        $errors  = pp_validate_composition_errors($this->staleComposition());
        $message = $errors[0]->get_error_message();

        $this->assertStringContainsString('missing required prop "button_text"', $message);
        $this->assertStringContainsString('cta_text', $message, 'the key actually holding the label must be visible');
        $this->assertStringContainsString('cta_url', $message);
        $this->assertStringContainsString('Available props:', $message, 'the schema is the source of the rename target');
    }

    /**
     * No hint clause when there is nothing unrecognized to point at — the message stays
     * exactly what it always was for a plainly incomplete item.
     */
    public function testAMissingRequiredPropWithNoUndeclaredKeysKeepsTheOriginalMessage(): void
    {
        $errors = pp_validate_composition_errors([['component' => 'cta', 'props' => ['title' => 'Hi']]]);

        $this->assertSame(
            'Component "cta" is missing required prop "button_text".',
            $errors[0]->get_error_message()
        );
    }

    /**
     * The hint is derived from the schema, never from a retired-name list. A key nobody
     * ever shipped as an alias is reported identically to one that was — there is no
     * lookup table to consult, and re-adding one would be the alias machinery #604
     * removed (#622 fence).
     */
    public function testTheHintIsSchemaDerivedNotAliasDerived(): void
    {
        $errors  = pp_validate_composition_errors([
            ['component' => 'cta', 'props' => ['title' => 'Hi', 'zzz_never_existed' => 'x']],
        ]);
        $message = $errors[0]->get_error_message();

        $this->assertStringContainsString('zzz_never_existed', $message);
        $this->assertStringNotContainsString('formerly', strtolower($message));
        $this->assertStringNotContainsString('renamed', strtolower($message));
    }

    /**
     * Section 14.1: the enriched message is what a real caller gets, not only what the
     * validator returns in isolation.
     */
    public function testTheAuthoringPathReturnsTheEnrichedMessage(): void
    {
        $this->seedPage(300, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => 300,
            'composition' => [['component' => 'cta', 'props' => ['title' => 'Ready?', 'cta_text' => 'Go']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_composition', $result['error_code']);
        $this->assertStringContainsString('cta_text', $result['error']);
    }

    // ── 4. `wp pp check page` surfaces error-severity findings (#622 gap 1) ────

    public function testCheckPageReportsCompositionErrorsInsteadOfReportingClean(): void
    {
        $this->seedPage(301, $this->staleComposition());

        (new PP_Check_Command())->page([], ['post_id' => 301]);

        $this->assertSame([], WP_CLI::$successes, 'a stale page must not print a success line');
        $joined = implode("\n", array_merge(WP_CLI::$warnings, WP_CLI::$lines));
        $this->assertStringContainsString('composition error(s)', $joined);
        $this->assertStringContainsString('would be rejected', $joined);
        $this->assertStringContainsString('[invalid_composition] index 0', $joined, 'errors carry the smell locator format');
        $this->assertStringContainsString('[unknown_prop] index 1', $joined);
    }

    public function testCheckPageStillSucceedsOnACleanComposition(): void
    {
        $this->seedPage(302, [
            ['component' => 'hero', 'props' => ['id' => 'hero-1', 'title' => 'Hi']],
        ]);

        (new PP_Check_Command())->page([], ['post_id' => 302]);

        $this->assertCount(1, WP_CLI::$successes);
        $this->assertStringContainsString('valid under current write rules', WP_CLI::$successes[0]);
    }

    /**
     * `check page` is the per-page inspector, not the gate: it reports and returns.
     * Only `wp pp validate site` exits non-zero (#622).
     */
    public function testCheckPageDoesNotChangeItsExitContract(): void
    {
        $this->seedPage(303, $this->staleComposition());

        (new PP_Check_Command())->page([], ['post_id' => 303]);

        $this->assertTrue(true, 'no WpCliHaltException/WpCliExitException was thrown');
    }

    // ── 4b. Corrupt rows are REPORTED, never fatal (the #144 contract) ─────────

    /**
     * #622 routes the error engine into two read-only diagnostics whose whole job is to
     * be pointed at data that never passed write validation — raw meta writes and
     * history-ring snapshots. A malformed row must therefore come back as a finding, not
     * as a TypeError that kills the command. `props: "oops"` reached array_key_exists()
     * and fatalled before this pin existed.
     */
    public function testAMalformedPropsBagIsReportedNotFatal(): void
    {
        $errors = pp_validate_composition_errors([['component' => 'hero', 'props' => 'oops']]);

        $this->assertNotSame([], $errors);
        $this->assertSame('invalid_composition', $errors[0]->get_error_code());
        $this->assertStringContainsString('missing required prop', $errors[0]->get_error_message());
        $this->assertSame(0, pp_composition_error_index($errors[0]));
    }

    public function testCheckPageSurvivesAMalformedStoredComposition(): void
    {
        $this->seedPage(310, [
            ['component' => 'hero', 'props' => 'oops'],
            ['component' => 'cta',  'props' => ['title' => 'Hi']],
        ]);

        (new PP_Check_Command())->page([], ['post_id' => 310]);

        $this->assertSame([], WP_CLI::$successes, 'a corrupt page must not report clean');
        $joined = implode("\n", array_merge(WP_CLI::$warnings, WP_CLI::$lines));
        $this->assertStringContainsString('composition error(s)', $joined);
    }

    /**
     * A composition stored as a JSON OBJECT (a raw write, or an old history-ring entry)
     * decodes to a string-keyed array. The offset is then not an int, and the honest
     * answer is "no locator" — never a coerced 0 pointing at the wrong band, and never
     * a fatal.
     */
    public function testANonListCompositionYieldsNoLocatorRatherThanAFatal(): void
    {
        $errors = pp_validate_composition_errors([
            'first'  => ['component' => 'cta', 'props' => ['title' => 'Hi']],
            'second' => ['component' => 'ghost'],
        ]);

        $this->assertCount(2, $errors);
        $this->assertNull(pp_composition_error_index($errors[0]));
        $this->assertNull(pp_composition_error_index($errors[1]));
    }

    // ── 4c. The reflected key list is bounded and printable (#633 posture) ─────

    /**
     * The undeclared keys are stored/caller-supplied text that travels out through the
     * CLI, the action envelope, the editor and the chat. #633 bounded the sibling
     * style-slot reflection for exactly this reason; this path gets the same treatment
     * instead of echoing raw.
     */
    public function testTheUndeclaredKeyListIsBoundedInCount(): void
    {
        $props = ['title' => 'Hi'];
        for ($i = 0; $i < 25; $i++) {
            $props['stale_' . $i] = 'x';
        }
        $errors  = pp_validate_composition_errors([['component' => 'cta', 'props' => $props]]);
        $message = $errors[0]->get_error_message();

        $this->assertStringContainsString('stale_0', $message);
        $this->assertStringNotContainsString('stale_20', $message, 'the list is capped');
        $this->assertStringContainsString('and 15 more', $message, 'the tail reports the TRUE total');
    }

    public function testTheUndeclaredKeyListIsBoundedInLength(): void
    {
        $long    = str_repeat('a', 300);
        $errors  = pp_validate_composition_errors([['component' => 'cta', 'props' => ['title' => 'Hi', $long => 'x']]]);
        $message = $errors[0]->get_error_message();

        $this->assertStringContainsString(str_repeat('a', 64) . '...', $message);
        $this->assertStringNotContainsString(str_repeat('a', 65), $message);
    }

    public function testTheUndeclaredKeyListStripsControlCharacters(): void
    {
        $errors  = pp_validate_composition_errors([
            ['component' => 'cta', 'props' => ['title' => 'Hi', "cta\x1b[31m_text" => 'Go']],
        ]);
        $message = $errors[0]->get_error_message();

        $this->assertStringNotContainsString("\x1b", $message);
        $this->assertStringContainsString('cta[31m_text', $message, 'readable text survives, the escape does not');
    }

    // ── 5. The shared diagnostics bundle (what `validate site` consumes) ───────

    public function testThePageDiagnosticsBundleSplitsErrorsFromAdvisories(): void
    {
        $diagnostics = _pp_cli_page_diagnostics($this->staleComposition());

        $this->assertCount(2, $diagnostics['errors']);
        foreach ($diagnostics['errors'] as $error) {
            $this->assertSame('error', $error['severity']);
        }
        foreach ($diagnostics['smells'] as $smell) {
            $this->assertSame('warning', $smell['severity']);
        }
        $this->assertArrayHasKey('styling', $diagnostics);
    }

    /**
     * The bundle reads the SHARED findings engine — the same one restore_composition
     * reports through — rather than deriving a third view of the rules. If someone adds
     * a parallel validator, these two lists diverge and this fails.
     */
    public function testThePageDiagnosticsBundleIsTheSharedFindingsEngine(): void
    {
        $composition = $this->staleComposition();
        $diagnostics = _pp_cli_page_diagnostics($composition);
        $merged      = array_merge($diagnostics['errors'], $diagnostics['smells']);

        $this->assertEquals(_pp_composition_findings($composition), $merged);
    }

    /**
     * The exit-code decision of `wp pp validate site` — the command CI runs. Before #622
     * a page whose stored composition current write rules REJECT passed this gate.
     */
    public function testStaleContentNowFailsSiteValidation(): void
    {
        $diagnostics = _pp_cli_page_diagnostics($this->staleComposition());

        $this->assertNotSame([], $diagnostics['errors']);
        $this->assertTrue(
            _pp_cli_page_fails_site_validation($diagnostics),
            '`wp pp validate site` must exit non-zero for a page whose next edit would be refused'
        );
    }

    public function testAdvisoryOnlyContentStillFailsSiteValidation(): void
    {
        // A smell with no error: the gate is "nothing is quietly wrong", not a severity
        // filter, so this behavior is unchanged by #622.
        $diagnostics = _pp_cli_page_diagnostics([
            ['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'Hi', 'layout' => 'split']],
        ]);

        $this->assertSame([], $diagnostics['errors']);
        $this->assertNotSame([], $diagnostics['smells']);
        $this->assertTrue(_pp_cli_page_fails_site_validation($diagnostics));
    }

    public function testACleanPageDoesNotFailSiteValidation(): void
    {
        $this->assertFalse(
            _pp_cli_page_fails_site_validation(_pp_cli_page_diagnostics(pp_default_homepage_composition())),
            'the shipped starter must keep a fresh install at exit 0'
        );
        $this->assertFalse(_pp_cli_page_fails_site_validation(_pp_cli_page_diagnostics([])));
    }

    public function testTheFindingLineSharesOneLocatorFormatForErrorsAndSmells(): void
    {
        $this->assertSame(
            '  - [invalid_composition] index 3: boom',
            _pp_cli_finding_line(['type' => 'invalid_composition', 'index' => 3, 'message' => 'boom'])
        );
        $this->assertSame(
            '  - [duplicate_component_id]: boom',
            _pp_cli_finding_line(['type' => 'duplicate_component_id', 'index' => null, 'message' => 'boom']),
            'no fabricated index for a cross-item finding'
        );
    }

    /**
     * Composition error messages quote stored prop keys and values verbatim, and #622
     * points these commands at data that never passed write validation — so an escape
     * sequence in a raw-written composition must not reach the operator's terminal.
     */
    public function testTheFindingLineStripsTerminalControlSequences(): void
    {
        $line = _pp_cli_finding_line([
            'type'    => 'unknown_prop',
            'index'   => 0,
            'message' => "has no prop \x1b[31mred\x1b[0m\nsecond line",
        ]);

        $this->assertStringNotContainsString("\x1b", $line);
        $this->assertStringNotContainsString("\n", $line, 'a list item stays one line');
        $this->assertStringContainsString('red', $line, 'the readable text survives');
    }

    /**
     * One bad byte must not cost the operator the whole diagnostic. The `/u` pattern
     * refuses to run on invalid UTF-8; the byte-wise fallback keeps the readable part.
     */
    public function testTheFindingLineKeepsReadableTextOnInvalidUtf8(): void
    {
        $line = _pp_cli_finding_line([
            'type'    => 'unknown_prop',
            'index'   => 0,
            'message' => "Component \"cta\" has no prop \xC3\x28bad\x01 tail",
        ]);

        $this->assertStringContainsString('has no prop', $line, 'the rule survives one bad byte');
        $this->assertStringContainsString('tail', $line);
        $this->assertStringNotContainsString("\x01", $line);
    }

    public function testTheFindingLineSanitizesTheTypeToo(): void
    {
        $line = _pp_cli_finding_line(['type' => "bad\x1b[31mtype", 'index' => null, 'message' => 'ok']);

        $this->assertStringNotContainsString("\x1b", $line);
    }

    /**
     * The shared slot engine cast its value blind: a stored array warned, a stored object
     * was an uncaught Error. Both killed or corrupted the read-only diagnostics #622 opens
     * onto never-validated data.
     */
    public function testANonScalarStyleSlotValueIsReportedNotFatal(): void
    {
        foreach ([['a' => 1], new \stdClass()] as $bad) {
            $errors = pp_validate_composition_errors([
                ['component' => 'hero', 'props' => ['title' => 'x'], 'style' => ['--hero-bg' => $bad]],
            ]);

            $this->assertCount(1, $errors);
            $this->assertSame('invalid_style_value', $errors[0]->get_error_code());
            $this->assertStringContainsString('must be a scalar value', $errors[0]->get_error_message());
            $this->assertSame(0, pp_composition_error_index($errors[0]));
        }
    }

    public function testCheckPageSurvivesANonScalarStyleSlotValue(): void
    {
        $this->seedPage(311, [
            ['component' => 'hero', 'props' => ['title' => 'x'], 'style' => ['--hero-bg' => ['a' => 1]]],
        ]);

        (new PP_Check_Command())->page([], ['post_id' => 311]);

        $this->assertSame([], WP_CLI::$successes);
        $this->assertStringContainsString(
            'invalid_style_value',
            implode("\n", array_merge(WP_CLI::$warnings, WP_CLI::$lines))
        );
    }

    /**
     * The ambiguous-targeting clause of the gate predicate. Without this, deleting the
     * `styling` arm would weaken `wp pp validate site` with a green suite.
     */
    public function testAmbiguousTargetingAloneFailsSiteValidation(): void
    {
        $diagnostics = _pp_cli_page_diagnostics([
            ['component' => 'hero',    'props' => ['id' => 'h', 'title' => 'Hi', 'button_text' => 'Go', 'button_url' => '/go']],
            ['component' => 'section', 'props' => ['body' => 'one']],
            ['component' => 'section', 'props' => ['body' => 'two']],
        ]);

        $this->assertSame([], $diagnostics['errors']);
        $this->assertSame([], $diagnostics['smells']);
        $this->assertNotSame([], $diagnostics['styling']);
        $this->assertTrue(_pp_cli_page_fails_site_validation($diagnostics));
    }

    // ── 6. Fresh content and the shipped starter stay exit-0 (#622 fence) ──────

    /**
     * `wp pp validate site` is the command CI runs, and #622 makes it exit non-zero on
     * error-severity findings. That is intended for stale content — and it must NEVER
     * fire on the composition the theme itself seeds on activation, which the operator
     * did not write and cannot fix. If this fails, a fresh install exits 1: fix the seed,
     * never the gate.
     */
    public function testTheShippedStarterHomepageHasNoErrorSeverityFindings(): void
    {
        $diagnostics = _pp_cli_page_diagnostics(pp_default_homepage_composition());

        $this->assertSame([], $diagnostics['errors']);
        $this->assertSame([], $diagnostics['smells']);
        $this->assertSame([], $diagnostics['styling']);
    }

    public function testAFreshlyAuthoredCompositionHasNoErrorSeverityFindings(): void
    {
        $diagnostics = _pp_cli_page_diagnostics([
            ['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'Hi', 'button_text' => 'Go', 'button_url' => '/go']],
            ['component' => 'cta',  'props' => ['id' => 'c', 'title' => 'Ready?', 'button_text' => 'Go', 'button_url' => '/go']],
        ]);

        $this->assertSame([], $diagnostics['errors']);
    }

    public function testAnEmptyCompositionHasNoFindings(): void
    {
        $this->assertSame(
            ['errors' => [], 'smells' => [], 'styling' => []],
            _pp_cli_page_diagnostics([])
        );
    }
}
