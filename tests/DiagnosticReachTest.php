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
        // #685 registers a before_run_command hook at load time; the stub only
        // needs to accept it — the hook's decision is pinned directly instead.
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
        // Since #621 the cta contributes FOUR findings (two required props it lost with
        // the alias map, two unrecognized keys) and the hero one. Every one of them must
        // carry the band that owns it: exhaustive reporting is only useful if a longer
        // list stays attributable.
        $errors = pp_validate_composition_errors($this->staleComposition());

        $this->assertSame(
            [0, 0, 0, 0, 1],
            array_map(static fn ($e) => pp_composition_error_index($e), $errors),
            'the cta is item 0, the hero is item 1'
        );
    }

    public function testErrorDerivedFindingsCarryTheBandIndex(): void
    {
        $findings = _pp_composition_findings($this->staleComposition());
        $errors   = array_values(array_filter($findings, static fn ($f) => $f['severity'] === 'error'));

        $this->assertCount(5, $errors);
        $this->assertSame(
            [0, 0, 0, 0, 1],
            array_column($errors, 'index'),
            "the pre-#622 value was null — 'which band?' was unanswerable"
        );
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

        // The exact list, not a de-duplicated set: the broken cta owes three findings
        // (both required props it lost with the alias map, plus the retired key itself)
        // and the healthy one owes none. Asserting the deduplicated set here would stop
        // catching a regression that reports one finding twice.
        $this->assertSame([1, 1, 1], array_column($errors, 'index'));
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

    /**
     * #621 through the read-only CLI, which is the other surface an operator queries
     * before touching a stale page. One band, two independent problems: the retired prop
     * name and a dead style slot. Before #621 `check page` printed the prop only, so an
     * operator who fixed it and re-ran was told about the slot on the SECOND run — the
     * command's whole promise is "here is what is wrong with this page", not "here is the
     * first thing wrong with it".
     */
    public function testCheckPageReportsEveryProblemInOneBandNotJustTheFirst(): void
    {
        $this->seedPage(304, [
            ['component' => 'cta', 'props' => [
                'title' => 'Ready?', 'button_text' => 'Go', 'button_url' => '/go', 'cta_text' => 'Go',
            ], 'style' => ['--cta-not-a-slot' => 'red']],
        ]);

        (new PP_Check_Command())->page([], ['post_id' => 304]);

        $joined = implode("\n", array_merge(WP_CLI::$warnings, WP_CLI::$lines));
        $this->assertStringContainsString('[unknown_prop] index 0', $joined);
        $this->assertStringContainsString('[invalid_style_slot] index 0', $joined);
        $this->assertStringContainsString('2 composition error(s)', $joined, 'the count matches the list');
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
     *
     * #724 MADE THE ANSWER SHORTER, not different. This used to collect three per-item
     * findings (the cta naming both required props it is missing, plus the unknown
     * component) and assert that none of them invented a locator. The engine now judges the
     * CONTAINER first: a non-list is not a composition, so there are no bands to report on
     * and one finding says exactly that.
     *
     * BE PRECISE ABOUT WHAT THIS STILL PROVES, because the original subject moved. The
     * no-fabricated-offset assertion below now iterates ONE container error that trivially
     * carries no index, so it no longer exercises the thing it was written for: that a
     * string key surviving into the per-item loop is stamped as `null` rather than coerced
     * to `(int) "first"` === 0. That contract lives in _pp_composition_item_error() and is
     * pinned directly in the sibling test below, since #724 leaves it unreachable from any
     * production caller. Same for "never a fatal": the loop is no longer entered, so the
     * guard is untested here rather than proven here.
     */
    public function testANonListCompositionYieldsNoLocatorRatherThanAFatal(): void
    {
        $errors = pp_validate_composition_errors([
            'first'  => ['component' => 'cta', 'props' => ['title' => 'Hi']],
            'second' => ['component' => 'ghost'],
        ]);

        $this->assertCount(1, $errors, 'one container, one fact');
        $this->assertSame('unexpected_shape', $errors[0]->get_error_code());
        foreach ($errors as $error) {
            $this->assertNull(pp_composition_error_index($error));
        }
    }

    /**
     * The honest-null stamp itself (#622), pinned directly now that #724 keeps every
     * non-integer key out of the per-item loop.
     *
     * _pp_composition_item_error() records `is_int($index) ? $index : null`. That guard is
     * defence-in-depth after the container gate rather than a live path, and an unreachable
     * guard with no test is one refactor away from being deleted as dead code and one
     * refactor after that from a fabricated `0` coming back.
     */
    public function testTheItemErrorStampIsNullForANonIntegerKeyRatherThanCoercedToZero(): void
    {
        $stamped = _pp_composition_item_error('first', 'invalid_composition', 'Item key "first" is missing the "component" key.');
        $this->assertNull(pp_composition_error_index($stamped), '(int) "first" is 0, and there is no band 0');

        $listed = _pp_composition_item_error(2, 'invalid_composition', 'Item 2 is missing the "component" key.');
        $this->assertSame(2, pp_composition_error_index($listed), 'a real integer offset is still carried');
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

        $this->assertCount(5, $diagnostics['errors']);
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

    // ── 7. Nested ITEM locators name a real position, never a cast (#634) ──────
    //
    // The band-level half of this theme is section 2 above: an error-derived finding
    // carries the composition offset, and a cross-item rule reports none rather than a
    // fabricated 0. #634 is the same rule one level down, inside the message TEXT.
    //
    //   items value          key seen by the rule    locator: #633 -> #634 -> #652
    //   ["a", "b"]           int 1                   "1"  -> "1"  -> "1"        (never moved)
    //   {"aa": {...}}        string "aa"             "0"  -> "aa" -> key "aa"
    //   {"zz": {...}} style  string "zz"             "0"  -> "zz" -> key "zz"
    //   {"1": .., "0": ..}   int 0 (PHP folds)       "0"  -> "0"  -> key "0"
    //
    // Two of the eight nested-locator sites that existed AT #634 hard-cast the key
    // ((int) "aa" === 0) while six rendered it honestly, so ONE function answered "which
    // item?" two ways. (That 2+6 is #634-era history; #643 has since added a ninth site,
    // which is the count the drift-catcher asserts against today.) There
    // is no item 0 in a string-keyed map: the message sent the operator to repair an
    // element that does not exist, which is worse than carrying no locator at all.
    //
    // #634 fixed the FABRICATION and stopped there, deliberately: rendering the key alone
    // still cannot tell a list POSITION from a numeric object KEY, because PHP folds
    // `{"1": ...}` to the integer 1 at decode. #652 added the container as the
    // discriminator, so the two now read differently in the one case where they mean
    // different elements. A list container is byte-identical at every site — that is the
    // load-bearing constraint, and testAListShapedItemsArrayStillReportsItsIntegerPosition
    // AtBothSites plus the LIST arms of the provider below are what hold it.

    /** An `items` map that is a JSON object rather than a list, keyed 'aa'. */
    private function stringKeyedGrid(array $itemFields): array
    {
        return [['component' => 'grid', 'props' => ['items' => ['aa' => $itemFields]]]];
    }

    public function testTheNestedLinkUrlLocatorNamesTheStoredKeyRatherThanFabricatingItemZero(): void
    {
        $errors = pp_validate_composition_errors(
            $this->stringKeyedGrid(['title' => 'X', 'link_url' => 'javascript:alert(1)'])
        );

        $this->assertCount(1, $errors);
        $message = $errors[0]->get_error_message();
        $this->assertStringContainsString('item key "aa" field "link_url"', $message);
        $this->assertStringNotContainsString('item 0', $message, '(int) "aa" is 0, and there is no item 0');
    }

    public function testThePerItemStyleLocatorNamesTheStoredKeyRatherThanFabricatingItemZero(): void
    {
        $errors = pp_validate_composition_errors(
            $this->stringKeyedGrid(['title' => 'X', 'style' => ['--nope' => '1']])
        );

        $this->assertCount(1, $errors);
        $message = $errors[0]->get_error_message();
        $this->assertStringContainsString('Component "grid" item key "aa" has no style slot', $message);
        $this->assertStringNotContainsString('item 0', $message);
    }

    /**
     * The per-item style path reaches the shared slot engine through a widened parameter,
     * and that engine decides per-item SCOPE (#323) on `$item_index !== null`. A string
     * key must still be "an item" for that gate — otherwise widening the type would have
     * quietly turned a container-scoped slot on one card into an unknown-slot error, or
     * into acceptance.
     */
    public function testAStringKeyedItemStillEnforcesThePerItemSlotScope(): void
    {
        $errors = pp_validate_composition_errors(
            $this->stringKeyedGrid(['title' => 'X', 'style' => ['--grid-gap' => '2rem']])
        );

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_style_slot', $errors[0]->get_error_code());
        $this->assertStringContainsString(
            'Component "grid" item key "aa" style slot "--grid-gap" is container-scoped',
            $errors[0]->get_error_message()
        );
    }

    /**
     * NON-VACUITY, and the case every shipped example is: an ordinary LIST still reports
     * its integer position at both fixed sites. Written at index 1, not 0, so a rule that
     * lost the index entirely (or reported the band) fails here. `docs/reference-apply-cli.md`
     * shows this shape; #634 must not disturb it.
     */
    public function testAListShapedItemsArrayStillReportsItsIntegerPositionAtBothSites(): void
    {
        $link = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Fine', 'link_url' => '/ok'],
                ['title' => 'Dead', 'link_url' => 'javascript:alert(1)'],
            ]]],
        ]);
        $this->assertStringContainsString('item 1 field "link_url"', $link[0]->get_error_message());

        $style = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Fine'],
                ['title' => 'Bad', 'style' => ['--nope' => '1']],
            ]]],
        ]);
        $this->assertStringContainsString('Component "grid" item 1 has no style slot', $style[0]->get_error_message());
    }

    /**
     * ONE CONVENTION, proved on ONE key. The two repaired rules and one of the six that
     * were always honest are asked about the same key in the same composition: before
     * #634 the first two said `item 0` and the third said `item aa` — the divergence the
     * issue reports, inside a single validation pass.
     */
    public function testEveryNestedRuleRendersTheSameKeyTheSameWay(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'logos', 'props' => ['items' => ['aa' => ['image_alt' => 'X']]]],
            ['component' => 'grid',  'props' => ['items' => ['aa' => ['title' => 'X', 'link_url' => 'javascript:alert(1)']]]],
            ['component' => 'grid',  'props' => ['items' => ['aa' => ['title' => 'X', 'style' => ['--nope' => '1']]]]],
        ]);

        $this->assertCount(3, $errors, 'one error per band');
        foreach ($errors as $i => $error) {
            $this->assertStringContainsString('item key "aa"', $error->get_error_message(), "band {$i} names the stored key");
            $this->assertStringNotContainsString('item 0', $error->get_error_message());
        }
    }

    /**
     * Section 14.1 — the AUTHORING path, which is where this defect is most reachable and
     * where the issue's own repro understates it. A JSON object where a list belongs
     * survives `pp_execute_action`'s params intact, so `create_page` / `update_composition`
     * produced the fabricated locator themselves; no raw `update_post_meta()` write or
     * history-ring snapshot was required. The rejection must also leave the stored
     * composition untouched — a validation failure never half-writes.
     */
    public function testTheAuthoringPathReportsTheHonestItemLocatorAndStoresNothing(): void
    {
        $before = [['component' => 'hero', 'props' => ['title' => 'Hi']]];
        $this->seedPage(320, $before);

        $result = pp_execute_action('update_composition', [
            'post_id'     => 320,
            'composition' => $this->stringKeyedGrid(['title' => 'X', 'link_url' => 'javascript:alert(1)']),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('item key "aa" field "link_url"', $result['error']);
        $this->assertStringNotContainsString('item 0', $result['error']);
        $this->assertSame(
            wp_json_encode($before),
            $GLOBALS['_pp_test_store']['post_meta'][320]['_pp_composition'],
            'a rejected write stores nothing'
        );
    }

    /**
     * THE #652 REPRO, through the real write surface. `items` is a JSON object whose keys
     * run {"1", "0"}: PHP folds both to integers at decode, so iteration POSITION 0 holds
     * the entry keyed "1" — the healthy one — while the dead link sits at position 1 under
     * key "0". The old locator said `item 0` and was true only as a key; an operator or a
     * chat AI reading it counts to the first card and repairs the card that is fine, then
     * re-submits and is rejected by the identical message. Naming it `item key "0"` is what
     * stops that loop.
     *
     * Section 14.1: authored through pp_execute_action(), not a raw _pp_composition write —
     * #652's body records that an object-shaped items map survives pp_execute_action params
     * intact, and this is the pin for that claim.
     */
    public function testAReorderedNumericObjectItemsMapNamesTheBadEntryNotTheHealthyOne(): void
    {
        $this->seedPage(330, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => 330,
            'composition' => [['component' => 'grid', 'props' => ['items' => [
                '1' => ['title' => 'Fine', 'link_url' => '/ok'],
                '0' => ['title' => 'Bad',  'link_url' => 'javascript:alert(1)'],
            ]]]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('item key "0" field "link_url"', $result['error'],
            'the locator must name the stored KEY of the dead card, flagged as a key');
        // The exact failure the issue reports: `item 0` reads as "the first card", and the
        // first card is the healthy one.
        $this->assertStringNotContainsString('item 0 field', $result['error']);
    }

    /**
     * EVERY nested rule, against an object-shaped container — not just the two the
     * `stringKeyedGrid` fixture happened to reach.
     *
     * Why this exists: the sibling tests above cover the link-URL rule, the per-item style
     * rule, the missing-required-field rule and the undeclared-field rule, and it is easy to
     * read that as "the nested family is covered". It is not the same claim. Each rule threads
     * its OWN container argument (`$value` for the shape rules, `$entries` for the field
     * rules), so a rule whose container was dropped or wired to the wrong variable renders an
     * object key as a position again while every existing test stays green — the #652 defect,
     * reintroduced one rule at a time. One row per rule is what makes the container wiring
     * asserted rather than assumed.
     *
     * @dataProvider objectKeyedNestedRuleProvider
     */
    public function testEveryNestedRuleNamesAnObjectKeyAsAKey(array $props, string $component, string $expected): void
    {
        $errors = pp_validate_composition_errors([['component' => $component, 'props' => $props]]);

        $this->assertNotSame([], $errors, 'the fixture must actually trip the rule it is pinning');
        $message = $errors[0]->get_error_message();
        $this->assertStringContainsString($expected, $message);
        // The whole point: position 0 is what a bare key would have claimed.
        $this->assertStringNotContainsString('item 0 ', $message);
    }

    public static function objectKeyedNestedRuleProvider(): array
    {
        return [
            // item_type: "object" — a scalar where an entry object belongs.
            'entry must be an object' => [
                ['items' => ['aa' => 'not-an-object']], 'grid', 'item key "aa" must be an object',
            ],
            // item_type: "array" — a scalar row where a row array belongs.
            'entry must be an array' => [
                ['headers' => ['H'], 'rows' => ['aa' => 'not-a-row']], 'table', 'item key "aa" must be an array',
            ],
            // RULE 3 — the field's own declared scalar type.
            'nested field type' => [
                ['items' => ['aa' => ['title' => 'T', 'text' => 'x', 'image_id' => ['nope']]]], 'grid',
                'item key "aa" field "image_id"',
            ],
            // RULE 4 — nested enum membership.
            'nested enum value' => [
                ['items' => ['aa' => ['title' => 'T', 'text' => 'x', 'text_role' => 'terminal']]], 'grid',
                'item key "aa" field "text_role"',
            ],
            // Nested array-of-strings field.
            'nested bullets entries' => [
                ['items' => ['aa' => ['title' => 'T', 'text' => 'x', 'bullets' => [123]]]], 'grid',
                'item key "aa" field "bullets"',
            ],
        ];
    }

    /**
     * A single-entry map keyed {"5"}. The element is at position 0 and the locator says 5,
     * which is right as a key and wrong as a position — so it says which one it means.
     */
    public function testASparseNumericObjectItemsMapNamesTheKeyAsAKey(): void
    {
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['items' => [
                '5' => ['title' => 'Bad', 'link_url' => 'javascript:alert(1)'],
            ]]],
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('item key "5" field "link_url"', $errors[0]->get_error_message());
    }

    public function testTheCreatePagePathReportsTheHonestItemLocatorForAPerItemStyle(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'New',
            'composition' => $this->stringKeyedGrid(['title' => 'X', 'style' => ['--nope' => '1']]),
        ]);

        $this->assertFalse($result['ok']);
        // Band 0 named by the write path since #642; the nested locator this test owns
        // is still the honest `item key "aa"`, never the fabricated `item 0`.
        $this->assertStringContainsString('Component 0 ("grid") item key "aa" has no style slot', $result['error']);
        $this->assertStringNotContainsString('item 0', $result['error']);
    }

    /**
     * The read-only reach #622 opened. `wp pp check page` is pointed at stored data that
     * never passed write validation — exactly the population where a non-list `items` map
     * lives — so the honest locator has to survive the CLI's own sanitizer, not just the
     * validator.
     */
    public function testCheckPageReportsTheHonestItemLocator(): void
    {
        $this->seedPage(321, $this->stringKeyedGrid(['title' => 'X', 'link_url' => 'javascript:alert(1)']));

        (new PP_Check_Command())->page([], ['post_id' => 321]);

        $this->assertSame([], WP_CLI::$successes);
        $joined = implode("\n", array_merge(WP_CLI::$warnings, WP_CLI::$lines));
        $this->assertStringContainsString('item key "aa" field "link_url"', $joined);
        $this->assertStringNotContainsString('item 0', $joined);
    }

    /**
     * The renderer itself: both arms of its union type AND both arms of its container
     * discriminator. PHP folds a numeric-STRING array key ("5") to the integer 5 on the
     * way in, so the string arm is only observable through a genuinely non-numeric key —
     * which is why the behavioral tests above are keyed 'aa' and this one asserts the
     * arms directly.
     *
     * The LIST rows are the byte-identical pin. Every shipped example, doc snippet and
     * sibling test authors `items` as a JSON list, so if #652's container discriminator
     * ever leaks into that shape, these rows fail before any of the message-level tests do.
     *
     * @dataProvider itemIndexLabelProvider
     */
    public function testTheItemIndexLabelRendersBothArrayKeyTypes($index, ?array $container, string $expected): void
    {
        $this->assertSame($expected, _pp_item_index_label($index, $container));
    }

    public static function itemIndexLabelProvider(): array
    {
        $list   = ['a', 'b'];                 // pp_is_list() === true
        $object = ['aa' => 'a', 'bb' => 'b']; // pp_is_list() === false

        return [
            // ── LIST container: byte-identical to every version since #634 ──────────
            'first list position'      => [0, $list, '0'],
            'later list position'      => [7, $list, '7'],

            // ── OBJECT container: the key is named AS a key (#652) ──────────────────
            'object key'               => ['aa', $object, 'key "aa"'],
            // The #652 repro in miniature. PHP folded this key to int 0 at decode, so the
            // renderer sees the same argument a list position 0 would give it; only the
            // container tells them apart. Without this row the whole issue is untested.
            'numeric object key'       => [0, ['1' => 'a', '0' => 'b'], 'key "0"'],
            'sparse numeric object'    => [5, ['5' => 'a'], 'key "5"'],

            // ── NO container in scope: the bare key, as before ──────────────────────
            // The two message-builder delegates default here when a caller genuinely has
            // no view of the container (the grid-level style path passes no index at all,
            // and the direct unit callers below pass none).
            'object key, no container' => ['aa', null, 'aa'],
            'numeric-string key'       => ['5', null, '5'],

            // The degenerate key `{"": {...}}`. The locator renders EMPTY rather than
            // inventing a position for it — "no locator at all" is one of the two answers
            // the issue names as acceptable, and it is what the six sibling rules already
            // produce for the same key. Pinned so the empty render is a recorded choice
            // rather than something nobody looked at. Inside an object container it
            // becomes `key ""`, which is ugly and honest: there IS a key, and it is empty.
            'empty object key'         => ['', null, ''],
            'empty key in object'      => ['', ['' => 'a', 'x' => 'b'], 'key ""'],

            // Hostile keys. The key is rendered WHOLE — no strip, no truncation, no
            // escape — because bounding what a message reflects is #647/#649's axis and
            // must stay uniform across this family rather than being decided one surface
            // at a time. `key "a"b"` IS ambiguous to a reader; that is a recorded,
            // deliberate limitation routed to #647/#649, not an oversight.
            'key containing a quote'   => ['a"b', ['a"b' => 1, 'z' => 2], 'key "a"b"'],
            'key with control chars'   => ["a\tb", ["a\tb" => 1, 'z' => 2], "key \"a\tb\""],
        ];
    }

    /**
     * ORDERED numeric object keys are UNRECOVERABLE, and that is a documented limit rather
     * than a gap in the fix. `json_decode('{"0":"a","1":"b"}', true)` returns a PHP LIST —
     * the keys really are 0..n-1 in order — so nothing downstream can tell it from an
     * authored list, and this renders the list form. Harmless by construction: when key
     * and position agree, both readings address the same element. #652 is only about the
     * case where they DISAGREE.
     */
    public function testAnOrderedNumericObjectIsIndistinguishableFromAListAndSaysSo(): void
    {
        $decoded = json_decode('{"0":"a","1":"b"}', true);

        $this->assertTrue(pp_is_list($decoded), 'PHP destroys the distinction at decode');
        $this->assertSame('1', _pp_item_index_label(1, $decoded), 'so the list form is the only honest answer');
    }

    /**
     * lib/admin.php with every comment removed, for the source tripwires below.
     *
     * The file explains its own defects in prose — a docblock that quotes the retired
     * `sprintf('Item %d ', $index)` spelling is documentation working correctly, not the
     * defect returning. Stripping comments lets the guards assert on what the parser will
     * actually execute, which is the only thing they were ever meant to police.
     */
    private static function adminSourceWithoutComments(): string
    {
        $code = '';
        foreach (token_get_all(file_get_contents(dirname(__DIR__) . '/lib/admin.php')) as $token) {
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
     * THE DRIFT-CATCHER. Nine sites render this fragment and #614/#600/#643 each added one;
     * #644 and the rest of the #687 cluster will add more. The behavioral pins above prove
     * the nine that exist today, but only a source check stops a TENTH rule from
     * re-introducing the cast that started this, or from open-coding the old inline copy
     * beside the shared renderer.
     *
     * The load-bearing assertion is the PAIRING, not the name blocklist. A guard that only
     * greps for `(int) $entry_index` / `(int) $elem_index` is evadable by a new rule whose
     * key variable has a different name — `foreach ($rows as $row_index => $row)` with
     * `_pp_item_index_label((int) $row_index)` defeats every name-based check while
     * reintroducing exactly this defect. So: every `item %s` locator in the file must be
     * matched by one call to the shared renderer, and no call may be handed a cast.
     *
     * SINCE #652 THE GUARD ALSO WATCHES THE CONTAINER ARGUMENT, because the fix created a
     * second way to drift that the first guard cannot see. `$container` is nullable, so a
     * new rule that passes `null` — or that omits nothing because the delegates default —
     * compiles, passes every behavioral test written against LIST fixtures, and silently
     * renders a numeric object key as a position again. A missing-argument check alone
     * does not catch it; a literal-null check does.
     *
     * SCOPE, stated so the guard is not read as covering more than it does. Two locator
     * families live in this file and they are counted separately: the lowercase `item %s`
     * fragment (the nested items[] locator, #634/#652) and the capital-I band locator
     * ~1,700 lines up (which composition offset, #650). Since #650 the band family also
     * routes through the shared renderer — via _pp_band_index_label() — so the pairing
     * arithmetic below accounts for exactly those extra calls rather than pretending the
     * band level does not exist, which is what the pre-#650 version of this test did.
     */
    public function testEveryItemIndexLocatorRoutesThroughTheSharedLabel(): void
    {
        // Asserted on booleans and counts, not on the 140 KB haystack: a string assertion
        // that fails here dumps the whole file into the report and buries its own message.
        //
        // COMMENTS ARE STRIPPED FIRST. Every assertion below is about CODE, and this file
        // documents its own history in prose — the docblocks legitimately quote the old
        // `sprintf('Item %d ', $index)` spelling to explain why it is gone. A raw
        // str_contains() cannot tell the explanation from the defect, so it fails on the
        // very comment that records the fix. Tokenising is also strictly stronger for the
        // pairing counts: a format string mentioned in a comment can no longer inflate them.
        $source = self::adminSourceWithoutComments();

        // The BAND-level families route through the shared renderer too, and there are three
        // of them since the #687 addendum: the structural `Item` label (via
        // _pp_band_index_label), the #642 write-boundary `Component` prefix, and the
        // duplicate-id key list. The first two pass `($index, $items)`; the third maps over
        // its colliding keys and so passes `($key, $items)`. Counted explicitly rather than
        // lumped in, because the pairing arithmetic below only describes the nested family.
        $band_label_calls = substr_count($source, '_pp_item_index_label($index, $items)')
            + substr_count($source, '_pp_item_index_label($key, $items)');
        $this->assertSame(3, $band_label_calls, 'three band-level renderings, each exactly one renderer call');

        $this->assertSame(
            substr_count($source, 'item %s'),
            substr_count($source, '_pp_item_index_label($') - $band_label_calls,
            'every nested item locator must be fed by the shared renderer — one call per locator'
        );
        $this->assertSame(
            0,
            preg_match_all('/_pp_item_index_label\(\s*\((?:int|string|float|bool)\)/', $source),
            'the renderer takes the raw array key; casting on the way in is the #634 defect wearing a hat'
        );
        // #652: the container is what tells a list position from an object key, so the guard
        // is an ALLOWLIST of container expressions, not a blocklist of bad ones. A blocklist
        // that greps for a literal `, null)` is trivially evadable — `$no_container = null;`
        // one line up, or the house-style `$item['props'][$p] ?? null`, both slip through it
        // while throwing the discriminator away exactly as a literal null would. Requiring
        // every call to name one of the four known containers means a new rule must either
        // reuse a reviewed container or edit this list, which is the point: a new container
        // is a decision someone should look at, not something that compiles quietly.
        preg_match_all('/_pp_item_index_label\(\s*\$\w+\s*,\s*([^),]*(?:\[[^\]]*\][^),]*)*)\)/', $source, $containers);
        $allowed = ['$items', '$value', '$entries', '$item_container'];
        foreach ($containers[1] as $container) {
            $this->assertContains(
                trim($container),
                $allowed,
                'unreviewed container argument — a null or a fallback here re-fabricates positions for object keys'
            );
        }
        $this->assertGreaterThanOrEqual(10, count($containers[1]), 'the regex must actually be matching the calls');
        // Same escape hatch one level out, at the two message-builder delegates. Asserted
        // on the SIGNATURES via reflection rather than on the spelling of a call, because a
        // regex pinned to `_pp_link_url_error_message($name, $prop_name, $entry_index, ...)`
        // stops matching the moment someone renames a local at the call site — it would then
        // pass vacuously forever while the drift it guards is present, which is the same
        // evade-by-renaming weakness this docblock criticises in the cast blocklist.
        $link_container = (new ReflectionFunction('_pp_link_url_error_message'))->getParameters()[5];
        $this->assertSame('item_container', $link_container->getName());
        $this->assertFalse(
            $link_container->isDefaultValueAvailable(),
            'no default: a nested call that omits the container must fail loudly, not render a position'
        );

        // The style delegate CANNOT take the same treatment: its $item_index is optional, and
        // PHP cannot require a parameter after an optional one. The default is therefore a
        // known hazard rather than a convenience, and this pins that the hazard is confined to
        // exactly that one parameter for exactly that one reason.
        $style_params = (new ReflectionFunction('_pp_validate_style_slot_map'))->getParameters();
        $this->assertSame('item_container', $style_params[4]->getName());
        $this->assertTrue($style_params[3]->isDefaultValueAvailable(), 'the reason the next one must default too');
        $this->assertNull($style_params[4]->getDefaultValue(), 'and the default must be the honest "no view", never a list');
        $this->assertFalse(str_contains($source, '(int) $entry_index'), 'a cast fabricates item 0 for a string key');
        $this->assertFalse(str_contains($source, '(int) $elem_index'), 'same cast on the per-item style path');
        $this->assertFalse(
            str_contains($source, 'is_scalar($entry_index) ?'),
            'the inline copies are the shared renderer now — a new copy is how the two conventions came back'
        );
        $this->assertFalse(str_contains($source, 'item %d'), 'no nested locator formats the key as an integer');
        // #650: the band family used to spell its own `Item %d`, which is how it fabricated
        // band 0 for a string-keyed composition while its own payload said null.
        $this->assertFalse(str_contains($source, 'Item %d'), 'the band locator formats its key through the shared renderer, not %d');
        // Symmetric ban for the #642 write-boundary prefix, converged by the #687 addendum.
        // `Component %d` is how that family printed a key as a position; a `%d` here means
        // someone re-spelled the locator instead of routing it through the renderer.
        $this->assertFalse(str_contains($source, 'Component %d'), 'the write-boundary band prefix renders its key through the shared renderer, not %d');
        // ...and the band family gets the SAME pairing invariant the nested one has, for the
        // same reason. Banning only `Item %d` would let a new band rule open-code
        // sprintf('Item %s', $i): capital-I, so it never counts toward the lowercase pairing
        // arithmetic above, and not `%d`, so the ban misses it — a second spelling of the one
        // label, which is precisely how #650 happened. Exactly one `Item %s` may exist, inside
        // _pp_band_index_label(), and every band message must route through that.
        $this->assertSame(
            1,
            substr_count($source, 'Item %s'),
            'only _pp_band_index_label() may spell the band label — a second spelling is the #650 defect returning'
        );
        $this->assertGreaterThanOrEqual(9, substr_count($source, 'item %s'), 'the nine known nested locators are still there');
    }

    /**
     * The #642 write-boundary gate reads the band label from the SAME function that writes
     * it, so the two cannot drift into disagreeing about its spelling. Before #650 the gate
     * re-spelled `sprintf('Item %d ', $index)` independently; rewording the label would have
     * silently defeated the no-stutter branch and printed the locator twice.
     */
    public function testTheWriteBoundaryGateReadsTheBandLabelFromTheSharedRenderer(): void
    {
        $source = self::adminSourceWithoutComments();

        $this->assertStringContainsString(
            "str_starts_with(\$message, _pp_band_index_label(\$index, \$items) . ' ')",
            $source,
            'the no-stutter gate must compare against the renderer output, never a second spelling'
        );
    }

    /**
     * THE DEFERRAL, recorded in the suite rather than only in a docblock. The key is
     * reflected VERBATIM — unbounded and unstripped — which is what the six sibling rules
     * have always done and what #634 was fenced to keep uniform rather than fix at two
     * sites out of eight. Bounding it for the whole family is #649.
     *
     * Two surfaces, deliberately asserted apart: the CLI finding line IS sanitized
     * (_pp_cli_printable), the action envelope is NOT. When #649 lands, this test should
     * be rewritten to the new contract, not deleted — that is the signal that the gap was
     * closed on purpose.
     */
    public function testAHostileItemKeyIsStillReflectedVerbatimIntoTheEnvelopeUntil649(): void
    {
        $key    = "aa\x1b[31m\nWARNING: fake";
        $errors = pp_validate_composition_errors([
            ['component' => 'grid', 'props' => ['items' => [$key => ['title' => 'X', 'link_url' => 'javascript:a']]]],
        ]);

        $this->assertStringContainsString("\x1b", $errors[0]->get_error_message(), 'documented gap, not a claim that this is right (#649)');

        $line = _pp_cli_finding_line(['type' => 'invalid_prop_value', 'index' => 0, 'message' => $errors[0]->get_error_message()]);
        $this->assertStringNotContainsString("\x1b", $line, 'the CLI surface strips it today');
        $this->assertStringNotContainsString("\n", $line);
    }
}
