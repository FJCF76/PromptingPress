<?php
/**
 * tests/FindingsReportThrowableGuardTest.php — a page findings report that throws is one
 * gating `findings_skipped` row, never a failed envelope over a landed write, a crash, or a
 * pass (#1278).
 *
 * WHAT WAS BROKEN. _pp_composition_findings() had no Throwable guard. The udc engine guards
 * each of its checks (#1222), but a throw that starts anywhere else in the join escaped the
 * whole assembler. Every write path calls it AFTER its write has landed, so the write's
 * envelope failed for a change that happened (a client that retries on failure re-sends it),
 * and `wp pp check page` / `wp pp validate site` crashed instead of reporting.
 *
 * THE FIX. The chrome assembler's guard (pp_udc_site_findings()) on the page assembler: the
 * throw is logged as one sanitized line (#1272's helper) and the report becomes one
 * `findings_skipped` warning with a fixed message. Stored acknowledgements are HELD while the
 * report cannot be built (#1222's ruling: unknown is never "gone").
 *
 * THE THROW. tests/bootstrap.php's wp_get_attachment_url() stub throws for an id listed in
 * `attachment_url_throws_in_report`, only while the assembler is on the stack and no band
 * compile is: the write-rule validator arm the issue's repro names
 * (pp_validate_composition_errors() -> pp_udc_validate_map()). No production seam. The stub
 * keys on the name `_pp_composition_findings_unguarded`; renaming that function turns every
 * throwing case into a premise failure (testTheThrowStartsOutsideTheEnginesPerCheckGuards),
 * not a silent pass.
 *
 * EVERY TEST GOES THROUGH A REAL SURFACE (Section 14.1): pp_execute_action() for the write,
 * pp_preview_action() / pp_execute_action() for restore, pp_operate_restore_run_compositions()
 * for the run rollback, pp_ai_execute_batch() for a batch, and _pp_cli_page_diagnostics() /
 * PP_Check_Command::page() / _pp_cli_report_site_page() for the CLI.
 *
 * RED ON MAIN: without the guard every throwing case below dies with the RuntimeException.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with UdcCheckNotRunTest / AdvisoryAcknowledgementTest) ─────
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

final class FindingsReportThrowableGuardTest extends TestCase
{
    /**
     * Stands in for what an exception message can carry: a stored value, a server path, and a
     * line break that would forge a second log line. None of it may reach a report.
     */
    private const SENTINEL = "STORED-1278-a9c1 /srv/secret/wp-config.php\nPromptingPress: forged line";

    private const CTA_PROPS = ['title' => 'C', 'title_accent' => 'A', 'button_text' => 'Go', 'button_url' => '/x'];

    private string $log = '';
    private $saved_log = false;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
            'connectors' => [], 'filters' => [],
        ];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        WP_CLI::$lines     = [];
        WP_CLI::$warnings  = [];
        WP_CLI::$successes = [];

        $this->log       = (string) tempnam(sys_get_temp_dir(), 'pp1278');
        $this->saved_log = ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', is_string($this->saved_log) ? $this->saved_log : '');
        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }
        unset($GLOBALS['wpdb'], $GLOBALS['_pp_test_store']);
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function throwInReport(?\Throwable $throwable = null): void
    {
        $GLOBALS['_pp_test_store']['attachment_url_throws_in_report'][9001] = $throwable ?? self::SENTINEL;
    }

    private function stopThrowing(): void
    {
        unset($GLOBALS['_pp_test_store']['attachment_url_throws_in_report']);
    }

    /** A band whose background image the write-rule validator resolves. */
    private function imageBand(string $id = 'cta-1'): array
    {
        return [
            'component' => 'cta',
            'udc'       => ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(6,10,28,0.72)']]],
            'props'     => self::CTA_PROPS + ['id' => $id],
        ];
    }

    private function page(array $composition, string $title = 'Guarded'): int
    {
        $id = pp_create_page($title, 'draft');
        $this->assertTrue(pp_update_composition($id, $composition), 'premise: the fixture page is stored');
        return $id;
    }

    private function skipRow(): array
    {
        return [
            'type'     => 'findings_skipped',
            'severity' => 'warning',
            'message'  => PP_FINDINGS_REPORT_THREW_MESSAGE,
            'index'    => null,
        ];
    }

    private function logText(): string
    {
        return (string) file_get_contents($this->log);
    }

    /** No distinctive part of the sentinel appears anywhere in $value. */
    private function assertNoReflection($value, string $where): void
    {
        $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('STORED-1278-a9c1', $text, "{$where}: no stored value");
        $this->assertStringNotContainsString('wp-config.php', $text, "{$where}: no path");
        $this->assertStringNotContainsString('RuntimeException', $text, "{$where}: no exception class");
        $this->assertStringNotContainsString('TypeError', $text, "{$where}: no error class");
    }

    // ── The premise and the transparent path ────────────────────────────────

    /** The seam reaches the arm the issue names, and that arm is outside every per-check guard. */
    public function testTheThrowStartsOutsideTheEnginesPerCheckGuards(): void
    {
        $id = $this->page([$this->imageBand()]);
        $this->throwInReport();

        try {
            _pp_composition_findings_unguarded(pp_get_composition($id), $id);
            $this->fail('premise: the unguarded assembler lets the throw escape');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SENTINEL, $e->getMessage());
        }
    }

    /** Without a throw the guard changes nothing: the same report, byte for byte. */
    public function testWithoutAThrowTheReportIsUnchanged(): void
    {
        $composition = [$this->imageBand(), ['component' => 'section', 'props' => ['id' => 's', 'title' => 'T', 'body' => 'B', 'zzA' => 1]]];
        $id          = $this->page([$this->imageBand()]);

        $guarded = _pp_composition_findings($composition, $id);
        $this->assertSame(_pp_composition_findings_unguarded($composition, $id), $guarded);
        $this->assertContains('unknown_prop', array_column($guarded, 'type'), 'premise: a real finding is reported');
        $this->assertNotContains('findings_skipped', array_column($guarded, 'type'));
        $this->assertSame('', $this->logText(), 'nothing is logged when nothing threw');
    }

    // ── The write path ──────────────────────────────────────────────────────

    /**
     * THE ISSUE'S CASE. An accepted write whose report throws still answers ok:true with its
     * changes, the write is stored, and the report is the one fixed row. On main the action
     * threw out of pp_execute_action() after the write had landed.
     */
    public function testAWriteWhoseReportThrowsLandsAndSaysTheReportCouldNotBeBuilt(): void
    {
        $id = $this->page([$this->imageBand()]);
        $this->throwInReport();

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['id' => 'added', 'title' => 'Added', 'body' => 'B'],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertNotSame([], $result['changes'], 'the envelope reports the write');
        $this->assertSame(['cta', 'section'], array_column(pp_get_composition($id), 'component'), 'the write landed');
        $this->assertSame([$this->skipRow()], $result['findings']);
        $this->assertNoReflection($result, 'write envelope');
    }

    /** A whole-composition write too: its own pre-write validation runs outside the report. */
    public function testAFullCompositionWriteLandsToo(): void
    {
        $id = $this->page([['component' => 'section', 'props' => ['id' => 's', 'title' => 'T', 'body' => 'B']]]);
        $this->throwInReport();

        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [$this->imageBand()]]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(['cta'], array_column(pp_get_composition($id), 'component'));
        $this->assertSame([$this->skipRow()], $result['findings']);
    }

    /** An Error is caught like an Exception: a TypeError is the commonest unexpected throw. */
    public function testATypeErrorIsCaughtLikeAnException(): void
    {
        $id = $this->page([$this->imageBand()]);
        $this->throwInReport(new \TypeError('STORED-1278-a9c1 given'));

        $result = pp_execute_action('add_component', [
            'post_id' => $id, 'component' => 'section', 'props' => ['id' => 'added', 'title' => 'Added', 'body' => 'B'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([$this->skipRow()], $result['findings']);
        $this->assertNoReflection($result, 'write envelope');
        $this->assertStringContainsString('threw: TypeError: STORED-1278-a9c1 given', $this->logText());
    }

    /**
     * THE DETAILS GO TO THE ERROR LOG, AS ONE LINE. Class, message and throw site, naming the
     * page; the line break in the message cannot start a forged second line.
     */
    public function testTheDetailsGoToTheLogAsOneSanitizedLine(): void
    {
        $id = $this->page([$this->imageBand()]);
        $this->throwInReport();

        pp_execute_action('add_component', [
            'post_id' => $id, 'component' => 'section', 'props' => ['id' => 'added', 'title' => 'Added', 'body' => 'B'],
        ]);

        $lines = array_values(array_filter(explode("\n", $this->logText()), static fn (string $l): bool => $l !== ''));
        $this->assertCount(1, $lines, 'one throw, one log line');
        $this->assertStringContainsString(
            'PromptingPress: findings report for page ' . $id . ' threw: RuntimeException: STORED-1278-a9c1 /srv/secret/wp-config.php?PromptingPress: forged line at ',
            $lines[0]
        );
        $this->assertStringContainsString('tests/bootstrap.php:', $lines[0], 'with the throw site');
    }

    /**
     * IN A BATCH THE STEP SUCCEEDS. Before the guard, #1272's step guard caught the report's throw
     * as "the step threw" and rolled back a write that was fine.
     */
    public function testABatchStepWhoseReportThrowsIsNotRolledBack(): void
    {
        $id = $this->page([$this->imageBand()]);
        $this->throwInReport();

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'add_component', 'params' => [
                'post_id' => $id, 'component' => 'section', 'props' => ['id' => 'added', 'title' => 'Added', 'body' => 'B'],
            ]],
        ]);

        $this->assertTrue($batch['ok'], 'the batch succeeded');
        $this->assertFalse($batch['rolled_back'] ?? false);
        $this->assertSame([$this->skipRow()], $batch['steps'][0]['findings']);
        $this->assertSame(['cta', 'section'], array_column(pp_get_composition($id), 'component'), 'the step stayed applied');
        $this->assertNoReflection($batch, 'batch envelope');
    }

    // ── Restore ─────────────────────────────────────────────────────────────

    /** Restore preview and execute both report the row; execute still restores (#233). */
    public function testRestorePreviewAndExecuteCarryTheRowAndTheRestoreLands(): void
    {
        $id = $this->page([$this->imageBand()]);
        $this->assertTrue(pp_execute_action('add_component', [
            'post_id' => $id, 'component' => 'section', 'props' => ['id' => 'added', 'title' => 'Added', 'body' => 'B'],
        ])['ok'], 'premise: one write to undo');
        $this->throwInReport();

        $preview = pp_preview_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);
        $this->assertIsArray($preview);
        $this->assertSame([$this->skipRow()], $preview['findings']);
        $this->assertNoReflection($preview, 'restore preview');
        $this->assertSame(['cta', 'section'], array_column(pp_get_composition($id), 'component'), 'a preview writes nothing');

        $result = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(['cta'], array_column(pp_get_composition($id), 'component'), 'the restore landed');
        $this->assertSame([$this->skipRow()], $result['findings']);
        $this->assertNoReflection($result, 'restore envelope');
    }

    /**
     * The run rollback reverts EVERY touched post. Before the guard the first post's throw left
     * the loop, so the posts after it were never reverted.
     */
    public function testTheRunRollbackRevertsEveryPostAndCarriesTheRowPerPost(): void
    {
        $a = $this->page([$this->imageBand('a')], 'A');
        $b = $this->page([$this->imageBand('b')], 'B');

        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, $a, pp_get_composition($a));
        pp_operate_record_composition_content_snapshot($run_id, $b, pp_get_composition($b));
        pp_update_composition($a, [['component' => 'hero', 'props' => ['title' => 'A1']]]);
        pp_operate_record_touched_post_id($run_id, $a);
        pp_update_composition($b, [['component' => 'hero', 'props' => ['title' => 'B1']]]);
        pp_operate_record_touched_post_id($run_id, $b);
        $this->throwInReport();

        $report = pp_operate_restore_run_compositions($run_id);

        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['skipped']);
        $this->assertSame([$a, $b], array_column($report['reverted'], 'post_id'));
        foreach ($report['reverted'] as $entry) {
            $this->assertSame([$this->skipRow()], $entry['findings']);
        }
        $this->assertSame(['cta'], array_column(pp_get_composition($a), 'component'));
        $this->assertSame(['cta'], array_column(pp_get_composition($b), 'component'));
        $this->assertNoReflection($report, 'run rollback report');

        // `wp pp apply restore-composition` names both posts in its own warning, and does not
        // count them as composition findings (the row is about the report, not the page).
        $this->assertSame(2, pp_operate_restore_run_report_skipped_count($report));
        $this->assertSame(0, pp_operate_restore_run_finding_count($report));
    }

    /** Without a throw the rollback's report-skipped count is zero. */
    public function testTheRunRollbackCountsNoSkipWhenTheReportBuilds(): void
    {
        $a      = $this->page([$this->imageBand('a')], 'A');
        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, $a, pp_get_composition($a));
        pp_update_composition($a, [['component' => 'hero', 'props' => ['title' => 'A1']]]);
        pp_operate_record_touched_post_id($run_id, $a);

        $this->assertSame(0, pp_operate_restore_run_report_skipped_count(pp_operate_restore_run_compositions($run_id)));
    }

    // ── The CLI ─────────────────────────────────────────────────────────────

    /**
     * `wp pp validate site` FAILS on the page rather than crashing or passing, and the reason
     * is the fixed row, not the exception's text.
     */
    public function testValidateSiteFailsThePageRatherThanCrashingOrPassing(): void
    {
        $id = $this->page([$this->imageBand()]);
        $this->assertFalse(_pp_cli_page_fails_site_validation(_pp_cli_page_diagnostics(pp_get_composition($id), $id)),
            'premise: the page passes when nothing throws');

        $this->throwInReport();
        $diagnostics = _pp_cli_page_diagnostics(pp_get_composition($id), $id);
        $this->assertSame([], $diagnostics['errors']);
        $this->assertSame([$this->skipRow()], $diagnostics['smells']);
        $this->assertTrue(_pp_cli_page_fails_site_validation($diagnostics));

        $this->assertFalse(_pp_cli_report_site_page($id, 'Guarded', $diagnostics));
        $printed = implode("\n", array_merge(WP_CLI::$warnings, WP_CLI::$lines));
        $this->assertStringContainsString('could not be built', $printed);
        $this->assertNoReflection($printed, 'validate site output');
    }

    /** `wp pp check page` prints the row and never its success line. */
    public function testCheckPageReportsTheRowAndNoSuccess(): void
    {
        $id = $this->page([$this->imageBand()]);
        $this->throwInReport();

        (new PP_Check_Command())->page([], ['post_id' => (string) $id]);

        $this->assertSame([], WP_CLI::$successes, 'no "valid under current write rules" line');
        $this->assertContains('1 composition smell(s):', WP_CLI::$warnings);
        $printed = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('[findings_skipped]', $printed);
        $this->assertStringContainsString('could not be built', $printed);
        $this->assertNoReflection(implode("\n", array_merge(WP_CLI::$warnings, WP_CLI::$lines)), 'check page output');
    }

    // ── Acknowledgements ────────────────────────────────────────────────────

    /** The ink pair on a grid whose card image the validator resolves (UdcCheckNotRunTest's band). */
    private function inkBand(): array
    {
        return [
            'component' => 'grid',
            'udc'       => [
                '_band'       => ['background' => ['fill' => '@color-bg-inverted']],
                'step-number' => ['typography' => ['color' => '@color-bg-inverted']],
                'card'        => ['background' => ['image' => 9001]],
            ],
            'props'     => ['id' => 'steps', 'layout' => 'steps', 'title' => 'How it works', 'items' => [['number' => '1', 'title' => 'Ask']]],
        ];
    }

    /**
     * #1222'S RULING, FOR THE WHOLE REPORT. While the report cannot be built, a stored
     * acknowledgement is HELD, never ORPHANED (whose line says to remove it), and the line says
     * why. When the report builds again, the acknowledgement applies again.
     */
    public function testAcknowledgementsAreHeldNotOrphanedWhileTheReportCannotBeBuilt(): void
    {
        $id  = $this->page([$this->inkBand()]);
        $ink = array_values(array_filter(
            _pp_cli_page_diagnostics(pp_get_composition($id), $id)['smells'],
            static fn ($f): bool => ($f['type'] ?? '') === 'udc_role_ink_over_own_surface'
        ));
        $this->assertCount(1, $ink, 'premise: the ink advisory is raised');
        $key = $ink[0]['ack_key'];
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'measured 9.1:1, AA'));

        $this->throwInReport();
        $during = _pp_cli_page_diagnostics(pp_get_composition($id), $id);
        $this->assertSame([], $during['orphaned'], 'nothing was looked for, so nothing is gone');
        $this->assertSame([], $during['stale']);
        $this->assertSame([], $during['acknowledged']);
        $this->assertSame([$key], array_column($during['held'], 'ack_key'));
        $this->assertSame(PP_ACK_HELD_REPORT_NOT_BUILT, $during['held'][0]['ack_held']);
        $this->assertTrue(_pp_cli_page_fails_site_validation($during));

        WP_CLI::$lines = [];
        _pp_cli_print_acknowledgements($during, $id);
        $printed = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('HELD acknowledgement ' . $key . ': the findings report for this page could not be built', $printed);
        $this->assertStringContainsString('Do not remove it.', $printed);
        $this->assertStringNotContainsString('unacknowledge', $printed, 'no removal route is offered');
        $this->assertStringNotContainsString('udc_check_not_run', $printed, 'not the per-band wording');

        $this->stopThrowing();
        $after = _pp_cli_page_diagnostics(pp_get_composition($id), $id);
        $this->assertSame([], $after['held']);
        $this->assertSame([$key], array_column($after['acknowledged'], 'ack_key'), 'the acknowledgement applies again');
    }

    /**
     * Acknowledging while the report cannot be built refuses with the real reason, not
     * "the page may have changed" (which would send the operator to `check page`, which hits
     * the same failure). Nothing is written.
     */
    public function testAcknowledgingWhileTheReportCannotBeBuiltRefusesWithTheRealReason(): void
    {
        $id  = $this->page([$this->inkBand()]);
        $ink = array_values(array_filter(
            _pp_cli_page_diagnostics(pp_get_composition($id), $id)['smells'],
            static fn ($f): bool => ($f['type'] ?? '') === 'udc_role_ink_over_own_surface'
        ));
        $this->throwInReport();

        $refused = pp_acknowledge_advisory($id, $ink[0]['ack_key'], 'measured');

        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertSame('findings_unavailable', $refused->get_error_code());
        $this->assertStringContainsString('could not be built', $refused->get_error_message());
        $this->assertNoReflection($refused->get_error_message(), 'acknowledge refusal');
        $this->assertSame([], pp_acknowledged_advisories($id), 'nothing was acknowledged');

        // The refusal does not stick: once the report builds, the same key acknowledges.
        $this->stopThrowing();
        $this->assertTrue(pp_acknowledge_advisory($id, $ink[0]['ack_key'], 'measured'));
        $this->assertArrayHasKey($ink[0]['ack_key'], pp_acknowledged_advisories($id));
    }

    /**
     * The request continues past a caught throw now, so the catch re-adds core's `pre_kses`
     * filter as every render catch does. The harness's add_filter() is a no-op, so this pins the
     * call in the catch body (ContentRenderSurfacesTest's convention).
     */
    public function testTheCatchReHooksPreKses(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/actions.php');
        $start  = strpos($source, 'function _pp_composition_findings(array $items');
        $this->assertNotFalse($start);
        $body = substr($source, $start, (int) strpos($source, "\n}\n", $start) - $start);
        $this->assertMatchesRegularExpression('/catch\s*\(\\\\Throwable \$e\)\s*\{.*pp_content_rehook_pre_kses\(\);.*return \[_pp_findings_report_threw_row\(\)\];/s', $body);
    }
}
