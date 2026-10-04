<?php
/**
 * tests/ChromeSiteGateTest.php
 *
 * #1204: `wp pp validate site` reads the header and footer (`pp_site_udc`) findings.
 *
 * WHAT WAS BROKEN. The gate checked Custom CSS conflicts and every composition page, and never
 * read the chrome findings pp_udc_site_findings() builds. A gating header or footer advisory
 * (an unused band token, an unchecked `_css` property, `udc_findings_capped`) rode only the
 * write envelope, and the command CI runs exited 0 over it.
 *
 * ONE OWNER. The chrome section reads pp_udc_site_findings(), the same list the chrome write
 * envelope carries (lib/actions.php), bucketed by the same fail-closed splitter the pages use.
 * There is no second walk to drift from the first.
 *
 * AUTHORING PATH (Section 14.1). Every fixture writes chrome through
 * pp_execute_action('update_site_option'), the surface an author or the model reaches, except
 * where the point is the probe failing (that state cannot be produced through the real write).
 *
 * WHY PER-SECTION HELPERS AS WELL AS THE COMMAND. PP_Validate_Command::site() reads
 * pp_composition_pages(), which memoises for the life of the process, so in a full-suite run the
 * page loop can see pages another file created (CompositionShapeTrustTest records this). The
 * end-to-end test below asserts only what holds under any memo state: the chrome section prints
 * the finding and the command halts 1. The deterministic outcomes (pass, fail, notes, the no-key
 * line) are pinned on the section helpers the command calls.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with CliGateTest/DiagnosticReachTest) ──────────
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

class ChromeSiteGateTest extends TestCase
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

    /** The real chrome write surface. */
    private function writeChrome(array $map): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => PP_SITE_UDC_OPTION,
            'value' => (string) wp_json_encode($map),
        ]);
        $this->assertTrue($result['ok'], 'fixture write must land: ' . ($result['error'] ?? ''));
    }

    /** A nav map carrying a band token nothing references: a gating `udc_unused_band_token`. */
    private function orphanTokenChrome(): array
    {
        return [
            'nav' => [
                '_tokens' => ['nobody-references-me' => '4px'],
                'link'    => ['typography' => ['color' => '#ffffff']],
            ],
        ];
    }

    // ── The red proof: the command itself ───────────────────────────────────

    /**
     * THE BUG, END TO END. Before #1204 this printed no chrome section and, with no failing page,
     * reported "Site validation passed." over a gating header advisory.
     */
    public function testValidateSiteFailsOnAGatingChromeAdvisory(): void
    {
        $this->writeChrome($this->orphanTokenChrome());

        $halted = null;
        try {
            (new PP_Validate_Command())->site([], []);
        } catch (WpCliHaltException $e) {
            $halted = $e->getCode();
        }

        $joined = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('--- Site chrome (header and footer) ---', $joined,
            'the gate must have a chrome section');
        $this->assertStringContainsString('[udc_unused_band_token]', $joined,
            'the chrome finding must be listed by the gate, not only on the write envelope');
        $this->assertContains('Site chrome: 1 issue(s)', WP_CLI::$warnings);
        $this->assertSame(1, $halted, 'a gating chrome advisory must fail the command CI runs');
        $this->assertNotContains('Site validation passed.', WP_CLI::$successes);
    }

    /**
     * A nav map with one orphan band token and well over 100 responsive values: in engine order
     * the 100+ mint notes come before the unused-token lint, so a cut at 100 would leave the
     * gating warning only as a count.
     */
    private function overflowChrome(): array
    {
        $params = [
            'typography' => ['size', 'letter-spacing', 'line-height'],
            'spacing'    => ['padding-top', 'padding-bottom', 'padding-left', 'padding-right', 'margin-top', 'margin-bottom', 'gap'],
            'sizing'     => ['width', 'height', 'min-height', 'max-width'],
            'border'     => ['radius', 'width'],
        ];
        $map = ['_tokens' => ['nobody-references-me' => '4px']];
        $n   = 0;
        foreach (pp_udc_component_roles('nav') as $role => $def) {
            foreach (($def['groups'] ?? []) as $group) {
                foreach ($params[$group] ?? [] as $param) {
                    $map[$role][$group][$param] = ['d' => '1' . ($n % 9) . 'px', 'p' => '1px'];
                    $n++;
                }
            }
        }
        $this->assertGreaterThan(PP_WRITE_FINDINGS_BUDGET, $n, 'premise: more responsive values than the envelope budget');
        return ['nav' => $map];
    }

    /**
     * THE CASE THE ORDER EXISTS FOR (#1204). A chrome write whose report is cut at 100 still
     * lists its gating warning, and the cut row sends the operator to the command that lists the
     * whole report, which does.
     */
    public function testACutChromeEnvelopeKeepsItsWarningAndValidateSiteListsTheRest(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => PP_SITE_UDC_OPTION,
            'value' => (string) wp_json_encode($this->overflowChrome()),
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $types = array_column($result['findings'], 'type');
        $this->assertSame('findings_truncated', end($types), 'premise: the envelope was cut');
        $this->assertSame('udc_unused_band_token', $types[0], 'the gating warning survives the cut, first');
        $tail = end($result['findings']);
        $this->assertStringContainsString('wp pp validate site', $tail['message']);

        $diagnostics = _pp_cli_site_chrome_diagnostics();
        $this->assertGreaterThan(PP_WRITE_FINDINGS_BUDGET, count($diagnostics['info']), 'the gate lists every note, uncut');
        $this->assertSame(['udc_unused_band_token'], array_column($diagnostics['smells'], 'type'));
        $this->assertFalse(_pp_cli_report_site_chrome($diagnostics));
    }

    /** A preset write reports the same chrome list, in the same delivery order. */
    public function testAPresetWriteDeliversTheChromeReportInTheSameOrder(): void
    {
        $this->writeChrome([
            'nav' => [
                '_tokens' => ['nobody-references-me' => '4px'],
                'link'    => ['typography' => ['size' => ['d' => '19px', 'p' => '15px']]],
            ],
        ]);

        $result = pp_execute_action('save_preset', [
            'name' => 'gate-probe', 'grain' => 'role', 'udc' => ['typography' => ['weight' => '700']],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $types = array_column($result['findings'], 'type');
        $this->assertContains('udc_token_minted', $types, 'premise: the preset envelope carries the chrome notes');
        $this->assertLessThan(array_search('udc_token_minted', $types, true),
            array_search('udc_unused_band_token', $types, true));
        $this->assertSame(pp_order_findings_for_delivery($result['findings']), $result['findings']);
    }

    // ── The section: one producer, the page buckets ─────────────────────────

    /** The gate reads exactly the list the envelope carries: no second walk. */
    public function testTheChromeSectionReadsTheEnvelopesOwnFindings(): void
    {
        $this->writeChrome($this->orphanTokenChrome());

        $diagnostics = _pp_cli_site_chrome_diagnostics();
        $all = array_merge($diagnostics['errors'], $diagnostics['smells'], $diagnostics['info']);
        $strip = static fn (array $f): array => array_diff_key($f, ['ack_unkeyable' => true]);

        $this->assertEqualsCanonicalizing(
            pp_udc_site_findings(),
            array_map($strip, $all),
            'every finding the chrome assembler produces lands in exactly one bucket, unchanged'
        );
        $this->assertSame(['udc_unused_band_token'], array_column($diagnostics['smells'], 'type'));
    }

    public function testAGatingChromeAdvisoryFailsTheSectionAndIsListed(): void
    {
        $this->writeChrome($this->orphanTokenChrome());

        $this->assertFalse(_pp_cli_report_site_chrome(_pp_cli_site_chrome_diagnostics()));
        $this->assertSame(['Site chrome: 1 issue(s)'], WP_CLI::$warnings);
        $joined = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('  - [udc_unused_band_token]: ', $joined,
            'the page line format, with no fabricated index');
        $this->assertStringContainsString('Component "nav"', $joined);
    }

    /** A chrome mint is a note on chrome exactly as on a band: printed, never failing. */
    public function testChromeNotesArePrintedAndNeverFail(): void
    {
        $this->writeChrome(['nav' => ['link' => ['typography' => ['size' => ['d' => '19px', 'p' => '15px']]]]]);

        $diagnostics = _pp_cli_site_chrome_diagnostics();
        $this->assertSame([], $diagnostics['smells']);
        $this->assertNotSame([], $diagnostics['info']);

        $this->assertTrue(_pp_cli_report_site_chrome($diagnostics));
        $this->assertSame([], WP_CLI::$warnings);
        $this->assertSame('OK: the header and footer styling reports no findings that fail this command.', WP_CLI::$lines[0]);
        $joined = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('informational note(s), which never fail `wp pp validate site`', $joined);
        $this->assertStringContainsString('[udc_token_minted]', $joined);
    }

    public function testAFailingSectionPrintsItsNotesAfterItsSmells(): void
    {
        $this->writeChrome([
            'nav' => [
                '_tokens' => ['nobody-references-me' => '4px'],
                'link'    => ['typography' => ['size' => ['d' => '19px', 'p' => '15px']]],
            ],
        ]);

        $this->assertFalse(_pp_cli_report_site_chrome(_pp_cli_site_chrome_diagnostics()));
        $this->assertSame(['Site chrome: 1 issue(s)'], WP_CLI::$warnings, 'a note is not counted as an issue');
        $joined = implode("\n", WP_CLI::$lines);
        $this->assertLessThan(strpos($joined, '[udc_token_minted]'), strpos($joined, '[udc_unused_band_token]'));
    }

    public function testNoChromeStylingPasses(): void
    {
        $this->assertSame(['errors' => [], 'smells' => [], 'info' => []], _pp_cli_site_chrome_diagnostics());
        $this->assertTrue(_pp_cli_report_site_chrome(_pp_cli_site_chrome_diagnostics()));
        $this->assertSame([], WP_CLI::$warnings);
    }

    public function testACleanChromeWritePasses(): void
    {
        $this->writeChrome(['nav' => ['link' => ['typography' => ['color' => '#ffffff']]]]);

        $this->assertTrue(_pp_cli_report_site_chrome(_pp_cli_site_chrome_diagnostics()));
        $this->assertSame([], WP_CLI::$warnings);
    }

    /**
     * A SKIP IS NOT A CLEAN BILL OF HEALTH (I29). When the chrome probe cannot run it returns one
     * `findings_skipped` warning (pp_udc_site_findings()'s guard); the gate must fail on it, not
     * read the section as clean.
     */
    public function testAChromeProbeThatCannotRunFailsTheSection(): void
    {
        $skipped = [[
            'type' => 'findings_skipped', 'severity' => 'warning', 'message' => 'could not be built', 'index' => null,
        ]];

        $diagnostics = _pp_cli_site_chrome_diagnostics($skipped);
        $this->assertSame(['findings_skipped'], array_column($diagnostics['smells'], 'type'));
        $this->assertFalse(_pp_cli_report_site_chrome($diagnostics));

        // The guard words its row for a write envelope; the gate made no write, so it must not
        // print "The write itself landed".
        $joined = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('[findings_skipped]: The header and footer findings could not be built', $joined);
        $this->assertStringNotContainsString('write', $diagnostics['smells'][0]['message']);
    }

    /**
     * THE GATE READS THE GUARDED PRODUCER. The probe failure the guard catches cannot be provoked
     * through the shipped readers, so the skip tests above feed the row through the seam; this
     * pins the call shape that makes the real command reach the guard. Calling the unguarded body
     * instead would turn a throwing probe into a crashed gate, and nothing else would notice.
     */
    public function testTheChromeSectionReadsTheGuardedProducer(): void
    {
        $fn  = new \ReflectionFunction('_pp_cli_site_chrome_diagnostics');
        $src = implode('', array_slice((array) file((string) $fn->getFileName()), $fn->getStartLine() - 1,
            $fn->getEndLine() - $fn->getStartLine() + 1));
        $this->assertStringContainsString('_pp_cli_diagnostics_buckets($findings ?? pp_udc_site_findings());', $src);
        $this->assertStringNotContainsString('_pp_udc_site_findings_unguarded(', $src);
    }

    /** The real guard's row, as pp_udc_site_findings() returns it, is the one the gate rewords. */
    public function testTheGuardsOwnSkipRowSpeaksOfAWrite(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $start  = strpos($source, 'function pp_udc_site_findings(');
        $body   = substr($source, $start, strpos($source, "\n}\n", $start) - $start);
        $this->assertStringContainsString("'type'     => 'findings_skipped',", $body,
            'premise: the guard returns a findings_skipped row');
        $this->assertStringContainsString('The write itself', $body,
            'premise: its message is worded for a write envelope, which is why the gate rewords it');
    }

    /** Fails closed on severity exactly as a page does: only exactly 'info' passes. */
    public function testAnUnknownChromeSeverityStaysGating(): void
    {
        foreach (['INFO', 'notice', '', null] as $severity) {
            $diagnostics = _pp_cli_site_chrome_diagnostics([[
                'type' => 'udc_token_minted', 'severity' => $severity, 'message' => 'm', 'index' => null,
            ]]);
            $this->assertCount(1, $diagnostics['smells'], var_export($severity, true) . ' must gate');
        }
    }

    public function testAnErrorSeverityChromeFindingFailsTheSection(): void
    {
        $diagnostics = _pp_cli_site_chrome_diagnostics([[
            'type' => 'x_error', 'severity' => 'error', 'message' => 'bad', 'index' => null,
        ]]);
        $this->assertFalse(_pp_cli_report_site_chrome($diagnostics));
        $this->assertContains('  - [x_error]: bad', WP_CLI::$lines);
    }

    // ── Acknowledgement: not available on chrome yet (#1220) ────────────────

    /**
     * An acknowledgeable type reaches chrome through the real write (an unchecked `_css`
     * property). On a page it could be acknowledged; on chrome there is no store and no key
     * (#1220), so the gate says why beside the finding instead of leaving the operator to look
     * for a key that does not exist.
     */
    public function testAnAcknowledgeableChromeFindingSaysWhyItHasNoKey(): void
    {
        $this->writeChrome(['nav' => ['link' => [PP_UDC_CSS_KEY => ['zz-prop' => '1']]]]);

        $diagnostics = _pp_cli_site_chrome_diagnostics();
        $this->assertSame(['udc_css_unchecked_property'], array_column($diagnostics['smells'], 'type'));
        $this->assertContains('udc_css_unchecked_property', pp_acknowledgeable_finding_types(),
            'premise: the same finding on a band can be acknowledged');

        $this->assertFalse(_pp_cli_report_site_chrome($diagnostics), 'it still gates');
        $joined = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('[no key: header and footer findings cannot be acknowledged yet', $joined);
        $this->assertStringNotContainsString('wp pp check acknowledge', $joined,
            'no route is offered that cannot work');
    }

    /** A finding that is never acknowledgeable gets no no-key note (it would imply a key could exist). */
    public function testANonAcknowledgeableChromeFindingCarriesNoNoKeyNote(): void
    {
        $this->writeChrome($this->orphanTokenChrome());

        foreach (_pp_cli_site_chrome_diagnostics()['smells'] as $finding) {
            $this->assertArrayNotHasKey('ack_unkeyable', $finding);
        }
    }

    /** The annotation is the CLI's: the envelope list itself never carries it. */
    public function testTheNoKeyNoteNeverReachesTheEnvelope(): void
    {
        $this->writeChrome(['nav' => ['link' => [PP_UDC_CSS_KEY => ['zz-prop' => '1']]]]);

        foreach (pp_udc_site_findings() as $finding) {
            $this->assertArrayNotHasKey('ack_unkeyable', $finding);
        }
    }
}
