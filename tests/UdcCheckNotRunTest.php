<?php
/**
 * tests/UdcCheckNotRunTest.php — a check inside the UDC findings engine that throws is a
 * gating finding, never a silence (#1222).
 *
 * WHAT WAS BROKEN. Every arm of pp_udc_composition_findings() that compiles a band guards the
 * compile with its own `catch (\Throwable)`. Those guards logged the throw and carried on with
 * an empty result, so the check's findings were missing and nothing in the report said so:
 * `wp pp check page` and `wp pp validate site` passed a band nothing had judged. The outer
 * `findings_skipped` guards only see a failure that escapes the whole engine.
 *
 * THE FIX. Each guard also appends one `udc_check_not_run` warning naming the check (by the
 * finding type it would have produced), the component and the band index. Fixed shape: the
 * exception's text goes to the PHP error log and nowhere else. Not acknowledgeable: a check
 * that did not run is a malfunction, not a judgment call.
 *
 * THE THROW. tests/bootstrap.php's wp_get_attachment_url() stub throws for an id listed in
 * `attachment_url_throws`; pp_udc_compile_band() reaches it through a `_band` background image.
 * No production seam: the engine runs as shipped.
 *
 * RED ON MAIN. Run against main's lib/udc.php this file fails: the throwing band reports no
 * finding at all and the page passes the gate (evidence in #1222's body).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with ChromeSiteGateTest / AdvisoryAcknowledgementTest) ────
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

final class UdcCheckNotRunTest extends TestCase
{
    private const TYPE = 'udc_check_not_run';

    /** Carried by the injected exception; must reach the error log and nothing else. */
    private const SENTINEL = 'SENTINEL-1222 /srv/secret/path stored-value-xyz';

    /** A light scrim over an image: one gating `udc_overlay_accent_off_scrim` when nothing throws. */
    private const LIGHT_SCRIM = ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.8)'];

    private const CTA_PROPS = ['title' => 'C', 'title_accent' => 'A', 'button_text' => 'Go', 'button_url' => '/x'];

    private string $log = '';
    private $saved_log = false;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        WP_CLI::$lines = [];

        $this->log       = (string) tempnam(sys_get_temp_dir(), 'pp1222');
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

    private function throwOnCompile(): void
    {
        $GLOBALS['_pp_test_store']['attachment_url_throws'][9001] = self::SENTINEL;
    }

    private function band(array $udc, string $id = 'pp-a1b2c3d4'): array
    {
        return ['component' => 'cta', 'id' => $id, 'udc' => $udc, 'props' => self::CTA_PROPS];
    }

    private static function ofType(array $findings, string $type): array
    {
        return array_values(array_filter($findings, static fn ($f): bool => is_array($f) && ($f['type'] ?? '') === $type));
    }

    /** The check names each `udc_check_not_run` row carries, in order. */
    private static function checksNamed(array $findings): array
    {
        $named = [];
        foreach (self::ofType($findings, self::TYPE) as $row) {
            if (preg_match('/the (udc_[a-z_]+) check could not run/', (string) $row['message'], $m) !== 0) {
                $named[] = $m[1];
            }
        }
        return $named;
    }

    // ── The engine ──────────────────────────────────────────────────────────

    /** Premise: without the throw the fixture raises its finding and no not-run row. */
    public function testWithoutAThrowTheFixtureIsJudgedAndNoRowAppears(): void
    {
        $findings = pp_udc_composition_findings([$this->band(['_band' => ['background' => self::LIGHT_SCRIM]])]);
        $this->assertCount(1, self::ofType($findings, 'udc_overlay_accent_off_scrim'));
        $this->assertSame([], self::ofType($findings, self::TYPE));
    }

    /**
     * THE BUG. With the compile throwing, main returned [] here: the off-scrim finding was gone
     * and nothing said so. Every compile-guarded arm the band reaches now says it could not run.
     */
    public function testAThrowingCompileLeavesARowForEveryCheckItStopped(): void
    {
        $this->throwOnCompile();
        $findings = pp_udc_composition_findings([$this->band(['_band' => ['background' => self::LIGHT_SCRIM]])]);

        $this->assertSame([], self::ofType($findings, 'udc_overlay_accent_off_scrim'), 'premise: the check really did not run');
        $this->assertSame(
            ['udc_overlay_accent_off_scrim', 'udc_role_ink_over_own_surface', 'udc_overlay_without_image'],
            self::checksNamed($findings)
        );
        foreach (self::ofType($findings, self::TYPE) as $row) {
            $this->assertSame(0, $row['index'], 'located on the band');
            $this->assertStringStartsWith('Component "cta": the ', $row['message']);
            $this->assertStringEndsWith(
                'check could not run here, so this report does not cover what it checks. '
                . 'Validation fails until it can run; the cause is in the PHP error log.',
                $row['message']
            );
        }
    }

    /** The two degraded arms (a fallback still reports) say so too: the report cannot vouch for them. */
    public function testTheDegradedArmsAlsoSayTheyCouldNotRun(): void
    {
        $this->throwOnCompile();
        $findings = pp_udc_composition_findings([$this->band([
            '_band'   => ['background' => self::LIGHT_SCRIM, 'typography' => ['color' => '#ffffff']],
            'heading' => ['typography' => ['color' => '#101828'], '_css' => ['color' => '#202020']],
        ])]);
        $named = self::checksNamed($findings);
        $this->assertContains('udc_band_value_shadowed_by_role_default', $named);
        $this->assertContains('udc_css_overrides_group_value', $named);
        $this->assertCount(1, self::ofType($findings, 'udc_css_overrides_group_value'),
            'the collision itself is still reported, in the wording that does not read the compile');
        $this->assertSame(1, count(array_keys($named, 'udc_css_overrides_group_value', true)),
            'the raw-collision compile is tried once per band, so it is named once');
    }

    /** FIXED SHAPE: the exception text reaches the error log and no finding. */
    public function testTheExceptionTextGoesToTheLogAndNeverIntoTheReport(): void
    {
        $this->throwOnCompile();
        $findings = pp_udc_composition_findings([$this->band(['_band' => ['background' => self::LIGHT_SCRIM]])]);
        $this->assertNotSame([], self::ofType($findings, self::TYPE));

        $encoded = (string) wp_json_encode($findings);
        foreach (['SENTINEL-1222', '/srv/secret', 'stored-value-xyz', 'RuntimeException', 'pp-a1b2c3d4'] as $needle) {
            $this->assertStringNotContainsString($needle, $encoded, $needle . ' must not reach the report');
        }
        $this->assertStringContainsString('SENTINEL-1222', (string) file_get_contents($this->log),
            'the developer channel keeps the cause');
    }

    /** One stopped check on every band of a wide page: bounded, and the bound says so and gates. */
    public function testTheRowsAreBoundedAndTheCapIsDisclosed(): void
    {
        $this->throwOnCompile();
        $items = [];
        for ($n = 0; $n < 80; $n++) {
            $items[] = $this->band(['_band' => ['background' => self::LIGHT_SCRIM]], sprintf('pp-%08x', $n));
        }
        $findings = pp_udc_composition_findings($items);
        $this->assertCount(PP_UDC_MAX_EMIT_DROPS, self::ofType($findings, self::TYPE));
        $capped = self::ofType($findings, 'udc_findings_capped');
        $this->assertCount(1, $capped);
        $this->assertStringContainsString('for udc_check_not_run, so more of them may exist', $capped[0]['message']);
        $this->assertStringEndsWith('Once the checks can run again, read the report again to see the rest.', $capped[0]['message'],
            'a check that could not run is not fixed by editing what it lists');
        $this->assertStringNotContainsString('Fix the ones listed', $capped[0]['message']);
    }

    /** The row is located on the band that threw, not on the first band of the page. */
    public function testTheRowIsLocatedOnTheBandThatThrew(): void
    {
        $this->throwOnCompile();
        $clean    = ['component' => 'cta', 'id' => 'pp-00000001', 'props' => self::CTA_PROPS];
        $findings = pp_udc_composition_findings([$clean, $this->band(['_band' => ['background' => self::LIGHT_SCRIM]])]);
        $rows     = self::ofType($findings, self::TYPE);
        $this->assertNotSame([], $rows);
        foreach ($rows as $row) {
            $this->assertSame(1, $row['index']);
        }
    }

    // ── The gates ───────────────────────────────────────────────────────────

    /** Severity comes from the one authority: a warning, so it gates; never a note. */
    public function testItIsAGatingWarningOnThePageAssembler(): void
    {
        $this->assertNotContains(self::TYPE, pp_informational_finding_types());
        $this->assertSame('warning', pp_finding_severity(self::TYPE));

        $this->throwOnCompile();
        $rows = self::ofType(_pp_composition_findings([$this->band(['_band' => ['background' => self::LIGHT_SCRIM]])]), self::TYPE);
        $this->assertNotSame([], $rows);
        foreach ($rows as $row) {
            $this->assertSame('warning', $row['severity']);
        }
    }

    /**
     * `wp pp check page` and each page of `wp pp validate site` read _pp_cli_page_diagnostics();
     * the page authored through the real write path passes, then fails once its check cannot run.
     * On main the second assertion was the bug: the page passed.
     */
    public function testAPageWhoseCheckCannotRunFailsCheckPageAndValidateSite(): void
    {
        $id = pp_create_page('Not run', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'cta',
            'udc'       => ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(6,10,28,0.72)']]],
            'props'     => self::CTA_PROPS,
        ]]]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertFalse(_pp_cli_page_fails_site_validation(_pp_cli_page_diagnostics(pp_get_composition($id), $id)),
            'premise: a dark full scrim is clean');

        $this->throwOnCompile();
        $diagnostics = _pp_cli_page_diagnostics(pp_get_composition($id), $id);
        $this->assertNotSame([], self::ofType($diagnostics['smells'], self::TYPE));
        $this->assertTrue(_pp_cli_page_fails_site_validation($diagnostics));
        $this->assertFalse(_pp_cli_report_site_page($id, 'Not run', $diagnostics));
        $this->assertNotSame([], array_filter(WP_CLI::$lines, static fn (string $l): bool => str_contains($l, 'check could not run here')),
            'the operator is told which check did not run');
    }

    /** The write envelope says so too: a write whose report could not judge a band is not reported clean. */
    public function testTheWriteEnvelopeCarriesTheRow(): void
    {
        $id = pp_create_page('Envelope', 'draft');
        $this->throwOnCompile();
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'cta',
            'udc'       => ['_band' => ['background' => self::LIGHT_SCRIM]],
            'props'     => self::CTA_PROPS,
        ]]]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $rows = self::ofType($result['findings'] ?? [], self::TYPE);
        $this->assertNotSame([], $rows);
        $this->assertSame('warning', $rows[0]['severity']);
    }

    /** The header and footer section of `validate site` (#1204) fails on it, with no fabricated index. */
    public function testTheChromeSectionOfValidateSiteFailsOnIt(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => PP_SITE_UDC_OPTION,
            'value' => (string) wp_json_encode(['nav' => ['_band' => ['background' => self::LIGHT_SCRIM]]]),
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        $this->throwOnCompile();
        $diagnostics = _pp_cli_site_chrome_diagnostics();
        $rows = self::ofType($diagnostics['smells'], self::TYPE);
        $this->assertNotSame([], $rows);
        $this->assertStringStartsWith('Component "nav": the ', $rows[0]['message']);
        $this->assertNull($rows[0]['index'], 'chrome has no band offset');
        $this->assertArrayNotHasKey('ack_unkeyable', $rows[0], 'not an acknowledgeable type, so no "not yet" note');
        $this->assertFalse(_pp_cli_report_site_chrome($diagnostics));
    }

    // ── Acknowledgements ────────────────────────────────────────────────────

    /**
     * The owner's acknowledged ink pair (AdvisoryAcknowledgementTest's production shape), with a
     * card image so the compile reaches the throwing lookup.
     */
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
     * 7A RULING Q1 = B. When the check an acknowledgement judged cannot run, the acknowledgement
     * is HELD, never ORPHANED: the ORPHANED line tells the operator to delete it, and the row is
     * correct and signed. The page still fails (the not-run row gates). When the check runs again
     * the acknowledgement applies again under the existing semantics.
     */
    public function testAnAcknowledgementOfACheckThatCouldNotRunIsHeldNotOrphaned(): void
    {
        $id = pp_create_page('Held', 'draft');
        $this->assertTrue(pp_update_composition($id, [$this->inkBand()]));
        $before = _pp_cli_page_diagnostics(pp_get_composition($id), $id);
        $ink    = self::ofType($before['smells'], 'udc_role_ink_over_own_surface');
        $this->assertCount(1, $ink, 'premise: the ink advisory is raised');
        $this->assertArrayHasKey('ack_key', $ink[0]);
        $key = $ink[0]['ack_key'];
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'measured 9.1:1, AA'));
        $this->assertFalse(_pp_cli_page_fails_site_validation(_pp_cli_page_diagnostics(pp_get_composition($id), $id)),
            'premise: acknowledged, the page passes');

        $this->throwOnCompile();
        $during = _pp_cli_page_diagnostics(pp_get_composition($id), $id);
        $this->assertContains('udc_role_ink_over_own_surface', array_column(self::ofType($during['smells'], self::TYPE), 'check'));
        $this->assertSame([], $during['orphaned'], 'the check did not run, so nothing says the finding is gone');
        $this->assertSame([], $during['stale']);
        $this->assertSame([$key], array_column($during['held'], 'ack_key'));
        $this->assertSame('udc_role_ink_over_own_surface', $during['held'][0]['ack_check']);
        $this->assertTrue(_pp_cli_page_fails_site_validation($during), 'a held acknowledgement does not pass the gate');

        WP_CLI::$lines = [];
        _pp_cli_print_acknowledgements($during, $id);
        $printed = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('HELD acknowledgement ' . $key, $printed);
        $this->assertStringContainsString('could not run on its band', $printed);
        $this->assertStringContainsString('Do not remove it.', $printed);
        $this->assertStringNotContainsString('unacknowledge', $printed, 'no removal route is offered');
        $this->assertStringNotContainsString('orphaned', $printed);

        unset($GLOBALS['_pp_test_store']['attachment_url_throws']);
        $after = _pp_cli_page_diagnostics(pp_get_composition($id), $id);
        $this->assertSame([], self::ofType($after['smells'], self::TYPE));
        $this->assertSame([], $after['held']);
        $this->assertSame([$key], array_column($after['acknowledged'], 'ack_key'), 'the acknowledgement applies again');
        $this->assertFalse(_pp_cli_page_fails_site_validation($after));
    }

    /** Acknowledge the ink pair on every band of $composition; returns the keys by band id. */
    private function acknowledgeInk(int $id): array
    {
        $keys = [];
        foreach (self::ofType(_pp_cli_page_diagnostics(pp_get_composition($id), $id)['smells'], 'udc_role_ink_over_own_surface') as $f) {
            $this->assertTrue(pp_acknowledge_advisory($id, $f['ack_key'], 'measured'));
            $keys[] = $f['ack_key'];
        }
        return $keys;
    }

    /**
     * HELD IS PER BAND. Band A cannot run its check; band B's acknowledgement is orphaned for real
     * (its value changed and the advisory is gone), so it keeps the orphaned route.
     */
    public function testHeldIsScopedToTheBandWhoseCheckCouldNotRun(): void
    {
        $id = pp_create_page('Two', 'draft');
        $a  = $this->inkBand();
        $b  = $this->inkBand();
        $b['props']['id'] = 'steps-b';
        unset($b['udc']['card']);
        $this->assertTrue(pp_update_composition($id, [$a, $b]));
        $this->assertCount(2, $this->acknowledgeInk($id), 'premise: both bands raise the ink pair');

        unset($b['udc']['_band']); // no authored band surface: the ink advisory is gone (fixed)
        $this->assertTrue(pp_update_composition($id, [$a, $b]));
        $this->throwOnCompile();
        $stored = pp_get_composition($id);
        $during = _pp_cli_page_diagnostics($stored, $id);
        $this->assertCount(1, $during['held']);
        $this->assertStringContainsString(':' . $stored[0]['id'] . ':', $during['held'][0]['ack_key'], 'band A, whose check did not run');
        $this->assertCount(1, $during['orphaned']);
        $this->assertStringContainsString(':' . $stored[1]['id'] . ':', $during['orphaned'][0]['ack_key'], 'band B, whose finding is gone');
    }

    /**
     * PAST THE ROW BUDGET. The engine stops naming checks at 200 rows, so a band past that point
     * has no row of its own; its acknowledgement is still held, never offered for removal.
     */
    public function testAnAcknowledgementPastTheRowBudgetIsStillHeld(): void
    {
        $id = pp_create_page('Wide', 'draft');
        $empty = ['component' => 'section', 'props' => ['id' => 'empty']];
        $this->assertTrue(pp_update_composition($id, [$empty, $this->inkBand()]));
        $keys = $this->acknowledgeInk($id);
        $this->assertCount(1, $keys);
        // An acknowledgement of a type the engine does not guard, whose finding then goes away
        // (the band is removed below): it must stay ORPHANED even while the budget is spent.
        $empty_key = null;
        foreach (_pp_cli_page_diagnostics(pp_get_composition($id), $id)['smells'] as $f) {
            if ($f['type'] === 'empty_section' && isset($f['ack_key'])) {
                $empty_key = $f['ack_key'];
            }
        }
        $this->assertNotNull($empty_key, 'premise: the empty section raises a keyed advisory');
        $this->assertTrue(pp_acknowledge_advisory($id, $empty_key, 'intentional spacer'));

        $items = [];
        for ($n = 0; $n < 80; $n++) {
            $items[] = $this->band(['_band' => ['background' => self::LIGHT_SCRIM]], sprintf('pp-%08x', $n));
        }
        $items[] = $this->inkBand();
        $this->assertTrue(pp_update_composition($id, $items));
        $this->throwOnCompile();
        $during = _pp_cli_page_diagnostics(pp_get_composition($id), $id);
        $this->assertCount(PP_UDC_MAX_EMIT_DROPS, self::ofType($during['smells'], self::TYPE));
        $this->assertNotContains(80, array_column(self::ofType($during['smells'], self::TYPE), 'index'),
            'premise: the acknowledged band is past the budget and has no row');
        $this->assertSame($keys, array_column($during['held'], 'ack_key'));
        $this->assertSame([$empty_key], array_column($during['orphaned'], 'ack_key'),
            'only guarded checks are held past the budget; an unguarded type whose finding is gone stays orphaned');
    }

    /**
     * STALE BEFORE HELD. When a finding of the same type is present and gating (here a keyless one,
     * which opens the type page-wide), the acknowledgement reads stale, as it always has: the
     * operator is told to review the listed finding, which is true, and nothing suggests removal.
     */
    public function testStaleTakesPrecedenceOverHeld(): void
    {
        $id = pp_create_page('Order', 'draft');
        $this->assertTrue(pp_update_composition($id, [$this->inkBand()]));
        $keys = $this->acknowledgeInk($id);
        $composition = pp_get_composition($id);
        $smells = [
            ['type' => self::TYPE, 'severity' => 'warning', 'message' => 'm', 'index' => 0, 'check' => 'udc_role_ink_over_own_surface'],
            ['type' => 'udc_role_ink_over_own_surface', 'severity' => 'warning', 'message' => 'm', 'index' => null],
        ];
        $parts = pp_partition_acknowledged_advisories($id, $composition, $smells);
        $this->assertSame($keys, array_column($parts['stale'], 'ack_key'));
        $this->assertSame([], $parts['held']);

        // And without the open finding, the same row is held.
        $parts = pp_partition_acknowledged_advisories($id, $composition, [$smells[0]]);
        $this->assertSame($keys, array_column($parts['held'], 'ack_key'));
    }

    /** Every check a row can name is in the guarded list the partition reads. */
    public function testEveryNamedCheckIsAGuardedCheck(): void
    {
        $this->throwOnCompile();
        $findings = pp_udc_composition_findings([$this->band([
            '_band'   => ['background' => self::LIGHT_SCRIM, 'typography' => ['color' => '#ffffff']],
            'heading' => ['typography' => ['color' => '#101828'], '_css' => ['color' => '#202020']],
        ])]);
        $named = array_unique(array_column(self::ofType($findings, self::TYPE), 'check'));
        sort($named);
        $guarded = pp_udc_guarded_check_types();
        sort($guarded);
        $this->assertSame($guarded, array_values($named), 'all five call sites fire on this band, and each names a guarded check');
    }

    /** The `check` key is the literal check name the engine stamps, and rides the page envelope. */
    public function testTheCheckKeyIsTheLiteralCheckName(): void
    {
        $this->throwOnCompile();
        $rows = self::ofType(_pp_composition_findings([$this->band(['_band' => ['background' => self::LIGHT_SCRIM]])]), self::TYPE);
        $this->assertSame(
            ['udc_overlay_accent_off_scrim', 'udc_role_ink_over_own_surface', 'udc_overlay_without_image'],
            array_column($rows, 'check')
        );
        foreach ($rows as $row) {
            $this->assertStringContainsString('the ' . $row['check'] . ' check could not run', $row['message']);
        }
    }

    /**
     * A check that did not run is a malfunction, not a judgment call: it carries no key, stays
     * gating, and no key of its shape can be acknowledged. The page path's own refusals do this;
     * chrome has no acknowledgements at all (#1220).
     */
    public function testItCannotBeAcknowledged(): void
    {
        $this->assertNotContains(self::TYPE, pp_acknowledgeable_finding_types());

        $id = pp_create_page('Unackable', 'draft');
        $this->assertTrue(pp_update_composition($id, [$this->band(['_band' => ['background' => self::LIGHT_SCRIM]])]));
        $this->throwOnCompile();
        $composition = pp_get_composition($id);
        $diagnostics = _pp_cli_page_diagnostics($composition, $id);
        $rows = self::ofType($diagnostics['smells'], self::TYPE);
        $this->assertNotSame([], $rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('ack_key', $row);
            $reason = _pp_advisory_key_or_reason($id, $composition, $row);
            $this->assertNull($reason['key']);
            $this->assertStringContainsString('`udc_check_not_run` cannot be acknowledged', (string) $reason['reason']);
        }
        $this->assertSame([], self::ofType($diagnostics['acknowledged'], self::TYPE));

        $refused = pp_acknowledge_advisory($id, self::TYPE . ':pp-a1b2c3d4:0000', 'it is fine');
        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertSame('advisory_not_present', $refused->get_error_code());
        $this->assertTrue(_pp_cli_page_fails_site_validation(_pp_cli_page_diagnostics(pp_get_composition($id), $id)));
    }
}
