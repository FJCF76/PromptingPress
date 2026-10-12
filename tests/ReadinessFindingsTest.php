<?php
/**
 * tests/ReadinessFindingsTest.php
 *
 * Readiness/preflight finding classification, resolution, and acknowledgement (#496).
 *
 * Every readiness warning carries a CLASS (integrity | configuration | capability)
 * and a sanctioned NEXT ACTION. Integrity drift is re-baselineable; configuration
 * findings are additionally acknowledgeable (reversibly); capability gaps route to
 * their tool. A completed operation then reports zero UNEXPLAINED warnings — every
 * classed finding is actionable-now or acknowledged-intentional.
 *
 * Coverage:
 *   - pure helpers: pp_classify_findings, pp_apply_finding_acknowledgements,
 *     pp_current_configuration_finding_keys
 *   - per-class classification through pp_preflight (integrity/config/capability)
 *   - deployment manifest records the installed release_version
 *   - REAL CLI surfaces (Section 14.1): PP_Readiness_Command status / rebaseline /
 *     acknowledge / unacknowledge, and PP_Sync_Command::check, driven through the
 *     WP_CLI stub and parsed from stdout
 *   - read-only invariant: status/preflight never mutate the manifest or the
 *     acknowledgement store
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with CliGateTest; guarded so either file may define) ──
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
        /** @var string[] Captured success() output — the "all clear" channel (#622 asserts its ABSENCE on a stale page). */
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

class ReadinessFindingsTest extends TestCase
{
    private string $themeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeDir = sys_get_temp_dir() . '/pp-readiness-' . getmypid() . '-' . mt_rand();
        mkdir($this->themeDir . '/assets/css', 0755, true);
        file_put_contents($this->themeDir . '/functions.php', "<?php // theme\n");
        file_put_contents($this->themeDir . '/assets/css/base.css', ".x{color:red}\n");

        // The deployment manifest lives in WP_CONTENT_DIR; ensure it exists so this
        // file passes both standalone (--filter) and inside the full suite.
        if (!is_dir(WP_CONTENT_DIR)) {
            mkdir(WP_CONTENT_DIR, 0755, true);
        }

        $GLOBALS['_pp_test_template_dir'] = $this->themeDir;
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => ['siteurl' => 'https://example.com'],
            'next_id' => 100, 'custom_css' => '',
            // primary + footer registered, NONE assigned → two configuration findings.
            'registered_nav_menus' => ['primary' => 'Primary Navigation', 'footer' => 'Footer Navigation'],
            'nav_menu_locations'   => [],
            'nav_menu_items'       => [],
        ];
        // Ensure a clean capability state: no browser configured → capability finding.
        putenv('PP_BROWSER_CMD');
        unset($_SERVER['PP_BROWSER_CMD']);

        $this->clearManifest();
        WP_CLI::$lines = [];
        WP_CLI::$warnings = [];
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->themeDir);
        $this->clearManifest();
        unset($GLOBALS['_pp_test_template_dir']);
        unset($GLOBALS['_pp_test_store']);
        unset($GLOBALS['_pp_test_salts'], $GLOBALS['_pp_test_wp_hash_returns'], $GLOBALS['_pp_test_blog_id']);
        parent::tearDown();
    }

    private function clearManifest(): void
    {
        $path = _pp_deployment_manifest_path();
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** The JSON printed by the last CLI command (first line[] entry). */
    private function lastJson(): array
    {
        $this->assertNotEmpty(WP_CLI::$lines, 'command printed output');
        $decoded = json_decode(WP_CLI::$lines[0], true);
        $this->assertIsArray($decoded, 'command output is JSON');
        return $decoded;
    }

    /** A stored row signed as `wp pp readiness acknowledge` signs it (#1249). */
    private static function signedRow(string $key, string $at, string $note): array
    {
        return ['acknowledged_at' => $at, 'note' => $note, 'sig' => _pp_readiness_ack_signature($key, $note, $at)];
    }

    /** Finds the single drift row in a preflight checks array. */
    private function driftRow(array $checks): ?array
    {
        foreach ($checks as $c) {
            if (($c['check'] ?? '') === 'drift') {
                return $c;
            }
        }
        return null;
    }

    // ── Pure helpers ─────────────────────────────────────────────────────────

    public function testClassifyFindingsGroupsByClassAndCounts(): void
    {
        $checks = [
            ['check' => 'target', 'pass' => true, 'message' => 'ok'], // unclassed gate row
            ['check' => 'drift', 'pass' => true, 'class' => 'integrity', 'next_action' => 'rebase', 'message' => 'd'],
            ['check' => 'nav_readiness', 'pass' => false, 'class' => 'configuration', 'finding_key' => 'nav_readiness:footer:no_menu', 'acknowledgeable' => true, 'next_action' => 'assign', 'message' => 'n'],
            ['check' => 'screenshot_readiness', 'pass' => false, 'class' => 'capability', 'next_action' => 'doctor', 'message' => 's'],
        ];

        $findings = pp_classify_findings($checks);

        $this->assertCount(1, $findings['by_class']['integrity']);
        $this->assertCount(1, $findings['by_class']['configuration']);
        $this->assertCount(1, $findings['by_class']['capability']);
        // Unclassed gate row is not a finding.
        $this->assertSame(3, $findings['active_warnings']);
        $this->assertSame(0, $findings['acknowledged']);
        // Every classed finding carries a next_action.
        foreach (['integrity', 'configuration', 'capability'] as $class) {
            $this->assertNotNull($findings['by_class'][$class][0]['next_action']);
        }
    }

    public function testApplyAcknowledgementsStampsOnlyConfigurationFindings(): void
    {
        update_option('pp_acknowledged_findings', [
            'nav_readiness:footer:no_menu' => self::signedRow('nav_readiness:footer:no_menu', '2026-07-26T00:00:00+00:00', 'menu-less footer'),
            // An integrity key in the store must NOT be honored (integrity isn't acknowledgeable),
            // even signed.
            'drift:whatever' => self::signedRow('drift:whatever', 'x', 'nope'),
        ]);

        $checks = [
            ['check' => 'drift', 'pass' => false, 'class' => 'integrity', 'finding_key' => 'drift:whatever'],
            ['check' => 'nav_readiness', 'pass' => false, 'class' => 'configuration', 'finding_key' => 'nav_readiness:footer:no_menu'],
        ];
        $out = pp_apply_finding_acknowledgements($checks);

        $this->assertArrayNotHasKey('acknowledged', $out[0], 'integrity row never acknowledged');
        $this->assertTrue($out[1]['acknowledged']);
        $this->assertSame('menu-less footer', $out[1]['acknowledged_note']);
    }

    public function testAcknowledgedFindingsReadsMalformedOptionAsEmpty(): void
    {
        update_option('pp_acknowledged_findings', 'not-an-array');
        $this->assertSame([], pp_acknowledged_findings());
    }

    public function testCurrentConfigurationFindingKeysSourcedFromLiveState(): void
    {
        // primary + footer unassigned → both are configuration findings.
        $keys = pp_current_configuration_finding_keys();
        $this->assertContains('nav_readiness:primary:no_menu', $keys);
        $this->assertContains('nav_readiness:footer:no_menu', $keys);
    }

    // ── Classification through pp_preflight ──────────────────────────────────

    public function testNavReadinessProducesConfigurationFinding(): void
    {
        $result = pp_preflight([]);
        $config = $result['findings']['by_class']['configuration'];
        $this->assertNotEmpty($config);
        $keys = array_column($config, 'finding_key');
        $this->assertContains('nav_readiness:footer:no_menu', $keys);
        foreach ($config as $row) {
            $this->assertTrue($row['acknowledgeable']);
            $this->assertNotNull($row['next_action']);
        }
    }

    public function testScreenshotProducesCapabilityFinding(): void
    {
        $result = pp_preflight([]);
        $cap = $result['findings']['by_class']['capability'];
        $this->assertNotEmpty($cap, 'no browser configured → capability finding');
        $this->assertSame('wp pp screenshot doctor', $cap[0]['next_action']);
    }

    public function testDriftProducesIntegrityFindingWithReleaseVersion(): void
    {
        // Baseline against current files (records PP_VERSION), then change one.
        _pp_save_deployment_manifest($this->themeDir, _pp_hash_theme_files($this->themeDir));
        file_put_contents($this->themeDir . '/assets/css/base.css', ".x{color:blue}\n");

        $result = pp_preflight([]);
        $integrity = $result['findings']['by_class']['integrity'];
        $this->assertNotEmpty($integrity);
        $row = $integrity[0];
        $this->assertSame('wp pp readiness rebaseline', explodeCommand($row['next_action']));
        // Message names the installed release (PP_VERSION is '0.8.0' under test).
        $this->assertStringContainsString('since the installed release (' . PP_VERSION . ')', $row['message']);
    }

    public function testDriftMessageDegradesWhenBaselinePredatesVersionTracking(): void
    {
        // A pre-#496 manifest with no release_version.
        $hashes = _pp_hash_theme_files($this->themeDir);
        file_put_contents(_pp_deployment_manifest_path(), json_encode([
            'timestamp'   => '2026-01-01T00:00:00+00:00',
            'theme_path'  => $this->themeDir,
            'file_hashes' => $hashes,
        ]));
        file_put_contents($this->themeDir . '/assets/css/base.css', ".x{color:green}\n");

        $drift = $this->driftRow(pp_preflight([])['checks']);
        $this->assertNotNull($drift);
        $this->assertStringContainsString('predates release-version tracking', $drift['message']);
    }

    public function testNoDriftRowIsUnclassed(): void
    {
        _pp_save_deployment_manifest($this->themeDir, _pp_hash_theme_files($this->themeDir));
        $drift = $this->driftRow(pp_preflight([])['checks']);
        $this->assertNotNull($drift);
        $this->assertTrue($drift['pass']);
        $this->assertArrayNotHasKey('class', $drift, 'a healthy row is not a finding');
        $this->assertSame([], pp_preflight([])['findings']['by_class']['integrity']);
    }

    public function testManifestRecordsInstalledReleaseVersion(): void
    {
        _pp_save_deployment_manifest($this->themeDir, _pp_hash_theme_files($this->themeDir));
        $manifest = _pp_load_deployment_manifest();
        $this->assertSame(PP_VERSION, $manifest['release_version']);
    }

    // ── Acknowledgement suppression / restoration ────────────────────────────

    public function testAcknowledgingConfigurationFindingSuppressesTheWarning(): void
    {
        $before = pp_preflight([])['findings'];
        $activeBefore = $before['active_warnings'];
        $this->assertGreaterThan(0, $activeBefore);

        update_option('pp_acknowledged_findings', [
            'nav_readiness:footer:no_menu' => self::signedRow('nav_readiness:footer:no_menu', '2026-07-26T00:00:00+00:00', 'intentional'),
        ]);

        $after = pp_preflight([])['findings'];
        $this->assertSame($activeBefore - 1, $after['active_warnings'], 'one fewer active warning');
        $this->assertSame(1, $after['acknowledged']);

        // The row still appears under configuration, marked acknowledged (not absent).
        $acked = array_values(array_filter(
            $after['by_class']['configuration'],
            fn($r) => $r['finding_key'] === 'nav_readiness:footer:no_menu'
        ));
        $this->assertCount(1, $acked);
        $this->assertTrue($acked[0]['acknowledged']);
    }

    // ── REAL CLI surfaces (Section 14.1) ─────────────────────────────────────

    public function testCliStatusEmitsGroupedFindingsReadOnly(): void
    {
        $optsBefore = $GLOBALS['_pp_test_store']['options'];

        (new PP_Readiness_Command())->status([], []);
        $findings = $this->lastJson();

        $this->assertArrayHasKey('by_class', $findings);
        $this->assertArrayHasKey('configuration', $findings['by_class']);
        $this->assertArrayHasKey('capability', $findings['by_class']);
        $this->assertNotEmpty($findings['by_class']['configuration']);

        // Read-only: status must not have written the acknowledgement option or a manifest.
        $this->assertSame($optsBefore, $GLOBALS['_pp_test_store']['options'], 'status did not mutate options');
        $this->assertFileDoesNotExist(_pp_deployment_manifest_path(), 'status did not create a manifest');
    }

    public function testCliAcknowledgeThenUnacknowledgeRoundTrips(): void
    {
        (new PP_Readiness_Command())->acknowledge(['nav_readiness:footer:no_menu'], ['note' => 'deliberate']);
        $acks = pp_acknowledged_findings();
        $this->assertArrayHasKey('nav_readiness:footer:no_menu', $acks);
        $this->assertSame('deliberate', $acks['nav_readiness:footer:no_menu']['note']);

        // Now suppressed in status output.
        WP_CLI::$lines = [];
        (new PP_Readiness_Command())->status([], []);
        $this->assertSame(1, $this->lastJson()['acknowledged']);

        // Reverse it.
        (new PP_Readiness_Command())->unacknowledge(['nav_readiness:footer:no_menu'], []);
        $this->assertArrayNotHasKey('nav_readiness:footer:no_menu', pp_acknowledged_findings());
    }

    public function testCliAcknowledgeRejectsNonConfigurationKey(): void
    {
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('Not an acknowledgeable configuration finding');
        // A capability-class next-step is not acknowledgeable.
        (new PP_Readiness_Command())->acknowledge(['screenshot_readiness:missing'], []);
    }

    public function testCliAcknowledgeRejectsUnknownConfigurationKey(): void
    {
        $this->expectException(WpCliExitException::class);
        // A plausibly-shaped but not-currently-present key is refused.
        (new PP_Readiness_Command())->acknowledge(['nav_readiness:footer:empty_menu'], []);
    }

    public function testCliUnacknowledgeUnknownKeyErrors(): void
    {
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('is not acknowledged');
        (new PP_Readiness_Command())->unacknowledge(['nav_readiness:footer:no_menu'], []);
    }

    public function testCliRebaselineRecordsReleaseAndClearsDrift(): void
    {
        _pp_save_deployment_manifest($this->themeDir, _pp_hash_theme_files($this->themeDir));
        file_put_contents($this->themeDir . '/assets/css/base.css', ".x{color:orange}\n");
        $this->assertNotEmpty(pp_preflight([])['findings']['by_class']['integrity'], 'drift present pre-rebaseline');

        (new PP_Readiness_Command())->rebaseline([], []);

        $manifest = _pp_load_deployment_manifest();
        $this->assertSame(PP_VERSION, $manifest['release_version']);
        // Drift is gone; and re-appears only on a genuine post-baseline change.
        $this->assertSame([], pp_preflight([])['findings']['by_class']['integrity']);
        file_put_contents($this->themeDir . '/assets/css/base.css', ".x{color:purple}\n");
        $this->assertNotEmpty(pp_preflight([])['findings']['by_class']['integrity'], 'drift reappears on new change');
    }

    public function testCliSyncCheckReportsRecordedReleaseVersion(): void
    {
        _pp_save_deployment_manifest($this->themeDir, _pp_hash_theme_files($this->themeDir));
        (new PP_Sync_Command())->check([], []);
        $report = $this->lastJson();
        $this->assertSame(PP_VERSION, $report['manifest_release_version']);
    }

    /**
     * Read-only invariant (#522): plain `sync check` with NO manifest must not
     * create one. Driven through the real CLI surface (Section 14.1). Asserts
     * both the no-write (manifest still absent afterward) and that the emitted
     * guidance names the two explicit baseline commands.
     */
    public function testCliSyncCheckWithNoManifestWritesNothing(): void
    {
        // Precondition: setUp() already cleared the manifest.
        $this->assertFileDoesNotExist(_pp_deployment_manifest_path(), 'no manifest before check');

        // Plain check (no flags) on a manifest-less install must not throw
        // (exit 0 — no baseline is neither drift nor a failure).
        (new PP_Sync_Command())->check([], []);

        // The heart of the fix: still no manifest — the read command wrote nothing.
        $this->assertFileDoesNotExist(
            _pp_deployment_manifest_path(),
            'plain sync check must NOT create a baseline manifest'
        );

        // It REPORTS the no-baseline state (the advisory warning names it).
        $warnings = implode("\n", WP_CLI::$warnings);
        $this->assertStringContainsString('No deployment manifest found', $warnings);
        $this->assertStringContainsString('does not create a baseline', $warnings);

        // And the operator is pointed at the explicit baseline commands.
        $output = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('--save-manifest', $output);
        $this->assertStringContainsString('readiness rebaseline', $output);
    }

    /**
     * The explicit save path (#522 non-goal: unchanged) still creates a manifest.
     */
    public function testCliSyncCheckSaveManifestStillWrites(): void
    {
        $this->assertFileDoesNotExist(_pp_deployment_manifest_path(), 'no manifest before save');
        (new PP_Sync_Command())->check([], ['save-manifest' => true]);
        $this->assertFileExists(
            _pp_deployment_manifest_path(),
            '--save-manifest must create the deployment manifest'
        );
    }

    public function testOverlappingDriftIsBlockingIntegrityFinding(): void
    {
        _pp_save_deployment_manifest($this->themeDir, _pp_hash_theme_files($this->themeDir));
        file_put_contents($this->themeDir . '/assets/css/base.css', ".x{color:teal}\n");

        // Planned files overlap the drifted file → error-grade, blocks ok.
        $result = pp_preflight(['planned_files' => ['assets/css/base.css']]);
        $drift = $this->driftRow($result['checks']);
        $this->assertNotNull($drift);
        $this->assertFalse($drift['pass'], 'overlapping drift blocks');
        $this->assertSame('integrity', $drift['class']);
        $this->assertStringContainsString('rebaseline', $drift['next_action']);
        $this->assertFalse($result['ok'], 'error-grade drift fails preflight');
        // It is counted among active integrity findings.
        $this->assertNotEmpty($result['findings']['by_class']['integrity']);
    }

    public function testLogoAndUnregisteredAndEmptyMenuAreConfigurationFindings(): void
    {
        // footer template-owned but NOT registered → :unregistered
        $GLOBALS['_pp_test_store']['registered_nav_menus'] = ['primary' => 'Primary'];
        // primary assigned to an EMPTY menu → :empty_menu
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 7];
        $GLOBALS['_pp_test_store']['nav_menu_items'] = [7 => []];
        // a non-image logo → nav_readiness:logo:not_image
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = 55;
        $GLOBALS['_pp_test_store']['posts'][55] = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][55] = false;

        $keys = array_column(pp_preflight([])['findings']['by_class']['configuration'], 'finding_key');
        $this->assertContains('nav_readiness:footer:unregistered', $keys);
        $this->assertContains('nav_readiness:primary:empty_menu', $keys);
        $this->assertContains('nav_readiness:logo:not_image', $keys);

        // All three are acknowledgeable through the CLI validator.
        $ackable = pp_current_configuration_finding_keys();
        foreach (['nav_readiness:footer:unregistered', 'nav_readiness:primary:empty_menu', 'nav_readiness:logo:not_image'] as $k) {
            $this->assertContains($k, $ackable);
        }
    }

    public function testScreenshotReadyRowIsUnclassed(): void
    {
        putenv('PP_BROWSER_CMD=echo');
        try {
            $result = pp_preflight([]);
            $shotRow = null;
            foreach ($result['checks'] as $c) {
                if (($c['check'] ?? '') === 'screenshot_readiness') {
                    $shotRow = $c;
                }
            }
            $this->assertNotNull($shotRow);
            $this->assertTrue($shotRow['pass']);
            $this->assertArrayNotHasKey('class', $shotRow, 'a ready capability is not a finding');
            $this->assertSame([], $result['findings']['by_class']['capability']);
        } finally {
            putenv('PP_BROWSER_CMD');
        }
    }

    public function testCliAcknowledgeEmptyKeyErrors(): void
    {
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('A finding-key is required');
        (new PP_Readiness_Command())->acknowledge([], []);
    }

    public function testCliUnacknowledgeEmptyKeyErrors(): void
    {
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('A finding-key is required');
        (new PP_Readiness_Command())->unacknowledge([], []);
    }

    public function testCliRebaselineErrorsWhenThemePathUnresolvable(): void
    {
        $GLOBALS['_pp_test_template_dir'] = '/nonexistent-pp-theme-' . mt_rand();
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('Cannot resolve theme path');
        (new PP_Readiness_Command())->rebaseline([], []);
    }

    public function testAcknowledgedNoteAndTimestampSurfaceInClassifiedOutput(): void
    {
        (new PP_Readiness_Command())->acknowledge(['nav_readiness:footer:no_menu'], ['note' => 'deliberately menu-less']);
        $config = pp_preflight([])['findings']['by_class']['configuration'];
        $acked = array_values(array_filter($config, fn($r) => $r['finding_key'] === 'nav_readiness:footer:no_menu'));
        $this->assertCount(1, $acked);
        $this->assertTrue($acked[0]['acknowledged']);
        $this->assertSame('deliberately menu-less', $acked[0]['acknowledged_note']);
        $this->assertNotSame('', $acked[0]['acknowledged_at'], 'timestamp is surfaced');
    }

    // ── Acceptance: all three classes at once ────────────────────────────────

    public function testSeededSiteProducesAllThreeClassesWithNextActions(): void
    {
        // (a) manifest drift, (b) unassigned menu locations, (c) missing capability.
        _pp_save_deployment_manifest($this->themeDir, _pp_hash_theme_files($this->themeDir));
        file_put_contents($this->themeDir . '/functions.php', "<?php // changed\n");

        $findings = pp_preflight([])['findings'];

        $this->assertNotEmpty($findings['by_class']['integrity'], 'integrity: drift');
        $this->assertNotEmpty($findings['by_class']['configuration'], 'configuration: unassigned menu');
        $this->assertNotEmpty($findings['by_class']['capability'], 'capability: no browser');

        // Every classed finding across all classes carries a next_action.
        foreach ($findings['by_class'] as $rows) {
            foreach ($rows as $row) {
                $this->assertNotNull($row['next_action'], 'finding ' . ($row['check'] ?? '?') . ' has a next action');
            }
        }
    }

    // ── Signed acknowledgements (#1249, the #1214 mechanism) ─────────────────

    private const KEY = 'nav_readiness:footer:no_menu';

    /** The configuration row for KEY in a preflight's findings. */
    private function footerRow(array $findings): array
    {
        foreach ($findings['by_class']['configuration'] as $row) {
            if ($row['finding_key'] === self::KEY) {
                return $row;
            }
        }
        $this->fail('the footer finding is present');
    }

    /**
     * RED-PROOF: a row written straight into the option (no signature, as every row written
     * before #1249 and every planted row is) acknowledges nothing. On main it was honored.
     */
    public function testAPlantedUnsignedRowAcknowledgesNothingAndIsListedAsIgnored(): void
    {
        $before = pp_preflight([])['findings'];
        update_option('pp_acknowledged_findings', [
            self::KEY => ['acknowledged_at' => '2026-07-26T00:00:00+00:00', 'note' => 'planted'],
        ]);

        $after = pp_preflight([])['findings'];
        $this->assertSame($before['active_warnings'], $after['active_warnings'], 'no warning was suppressed');
        $this->assertSame(0, $after['acknowledged']);
        $this->assertFalse($this->footerRow($after)['acknowledged']);
        $this->assertSame([['finding_key' => self::KEY, 'key_bytes' => strlen(self::KEY), 'ack_ignored' => 'unsigned',
            'acknowledged_at' => '2026-07-26T00:00:00+00:00']], $after['ignored_acknowledgements'], 'named, not silent');
        $this->assertSame(1, $after['ignored_acknowledgements_total']);
        $this->assertSame([], pp_acknowledged_findings(), 'the verified accessor holds no such row');
        $this->assertArrayHasKey(self::KEY, get_option('pp_acknowledged_findings'), 'and the row is not deleted by a read');
    }

    /** RED-PROOF on the post-apply surface: a planted row does not hide the nav warning. */
    public function testAPlantedRowDoesNotSuppressTheNavReadinessCheck(): void
    {
        update_option('pp_acknowledged_findings', [self::KEY => ['acknowledged_at' => 'x', 'note' => 'planted']]);
        $rows = array_values(array_filter(pp_apply_finding_acknowledgements(pp_check_nav_readiness()),
            fn ($r) => ($r['finding_key'] ?? '') === self::KEY));
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('acknowledged', $rows[0]);
    }

    public function testATamperedRowIsInvalid(): void
    {
        $row = self::signedRow(self::KEY, '2026-07-26T00:00:00+00:00', 'original');
        $row['note'] = 'swapped';
        update_option('pp_acknowledged_findings', [self::KEY => $row]);
        $this->assertSame('invalid', pp_preflight([])['findings']['ignored_acknowledgements'][0]['ack_ignored']);
        $this->assertSame([], pp_acknowledged_findings());
    }

    public function testARowMovedToAnotherKeyIsInvalid(): void
    {
        update_option('pp_acknowledged_findings', [
            self::KEY => self::signedRow('nav_readiness:primary:no_menu', 'at', 'n'),
        ]);
        $this->assertSame('invalid', pp_ignored_finding_acknowledgements()[0]['ack_ignored']);
    }

    public function testRotatedSaltsAndAnotherSiteInvalidateEveryRow(): void
    {
        update_option('pp_acknowledged_findings', [self::KEY => self::signedRow(self::KEY, 'at', 'n')]);
        $this->assertArrayHasKey(self::KEY, pp_acknowledged_findings(), 'premise: it verifies');

        $GLOBALS['_pp_test_salts'] = ['auth' => 'rotated'];
        $this->assertSame('invalid', pp_ignored_finding_acknowledgements()[0]['ack_ignored']);
        unset($GLOBALS['_pp_test_salts']);

        $GLOBALS['_pp_test_blog_id'] = 2;
        $this->assertSame('invalid', pp_ignored_finding_acknowledgements()[0]['ack_ignored'], 'the site is signed in');
    }

    public function testABrokenWpHashMakesRowsUnverifiableAndRefusesToAcknowledge(): void
    {
        update_option('pp_acknowledged_findings', [self::KEY => self::signedRow(self::KEY, 'at', 'n')]);
        $GLOBALS['_pp_test_wp_hash_returns'] = '';
        $this->assertSame('unverifiable', pp_ignored_finding_acknowledgements()[0]['ack_ignored']);

        update_option('pp_acknowledged_findings', []);
        try {
            (new PP_Readiness_Command())->acknowledge([self::KEY], ['note' => 'n']);
            $this->fail('acknowledge refuses when it cannot sign');
        } catch (WpCliExitException $e) {
            $this->assertStringContainsString('could not be signed', $e->getMessage());
        }
        $this->assertSame([], get_option('pp_acknowledged_findings'), 'nothing was written');
    }

    /**
     * SCOPING PIN: the signature covers exactly purpose, envelope version, site, key, note and
     * timestamp: the same envelope as a page acknowledgement (#1214) without the page, and
     * NOT the theme version, so a theme update never re-opens a signed row.
     */
    public function testTheEnvelopeCoversExactlyTheseFieldsAndNotTheThemeVersion(): void
    {
        $envelope = json_decode((string) _pp_readiness_ack_envelope(self::KEY, 'n', 'at'), true);
        $this->assertSame(['purpose', 'v', 'site', 'key', 'note', 'at'], array_keys($envelope));
        $this->assertSame('pp-readiness-ack', $envelope['purpose']);
        $this->assertNotContains(PP_VERSION, $envelope);
    }

    public function testTheCommandSignsAndStatusListsTheRestWithTheirRoutes(): void
    {
        update_option('pp_acknowledged_findings', [
            'nav_readiness:primary:no_menu' => ['acknowledged_at' => 'at', 'note' => 'old'],
        ]);
        (new PP_Readiness_Command())->acknowledge([self::KEY], ['note' => 'deliberate']);
        $stored = get_option('pp_acknowledged_findings');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $stored[self::KEY]['sig'], 'signed');
        $this->assertSame(['acknowledged_at' => 'at', 'note' => 'old'], $stored['nav_readiness:primary:no_menu'],
            'the other row is kept byte for byte and never signed');

        WP_CLI::$lines = [];
        (new PP_Readiness_Command())->status([], []);
        $this->assertSame(1, $this->lastJson()['acknowledged']);
        $printed = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('ignored acknowledgement nav_readiness:primary:no_menu: it carries no signature', $printed);
        $this->assertStringContainsString('wp pp readiness acknowledge nav_readiness:primary:no_menu --note=', $printed);
        $this->assertStringContainsString('wp pp readiness unacknowledge nav_readiness:primary:no_menu', $printed);

        // An ignored row is removable through the command (no raw option edit needed).
        (new PP_Readiness_Command())->unacknowledge(['nav_readiness:primary:no_menu'], []);
        $this->assertSame([self::KEY], array_keys(get_option('pp_acknowledged_findings')));
    }

    /** Bytes that cannot be encoded exactly (a raw write) read as invalid, never fatal. */
    public function testAnUnencodableRowIsInvalidNotFatal(): void
    {
        update_option('pp_acknowledged_findings', [self::KEY => ['acknowledged_at' => 'at', 'note' => "\xC3\x28", 'sig' => str_repeat('a', 64)]]);
        $this->assertSame('invalid', pp_ignored_finding_acknowledgements()[0]['ack_ignored']);
        $this->assertFalse($this->footerRow(pp_preflight([])['findings'])['acknowledged']);
    }

    /** A note that is not valid UTF-8 is refused as what it is, not blamed on wp_hash(). */
    public function testAnUnencodableNoteIsRefusedByName(): void
    {
        try {
            (new PP_Readiness_Command())->acknowledge([self::KEY], ['note' => "\xC3\x28"]);
            $this->fail('refused');
        } catch (WpCliExitException $e) {
            $this->assertStringContainsString('not valid UTF-8', $e->getMessage());
        }
        $this->assertSame([], get_option('pp_acknowledged_findings', []));
    }

    /** The invalid and unverifiable lines say what to do, and that the row must not be removed blind. */
    public function testStatusNamesTheInvalidAndUnverifiableRoutes(): void
    {
        $row = self::signedRow(self::KEY, 'at', 'n');
        $row['note'] = 'swapped';
        update_option('pp_acknowledged_findings', [self::KEY => $row]);
        WP_CLI::$lines = [];
        (new PP_Readiness_Command())->status([], []);
        $p = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('ignored acknowledgement ' . self::KEY . ': its signature does not verify on this site', $p);
        $this->assertStringContainsString('rule out a salt or plugin difference', $p);

        $GLOBALS['_pp_test_wp_hash_returns'] = '';
        WP_CLI::$lines = [];
        (new PP_Readiness_Command())->status([], []);
        $p = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('this site cannot check its signature', $p);
        $this->assertStringContainsString('do not remove this', $p);
    }

    /** A planted key with an unusual shape is never echoed into a suggested command. */
    public function testAnUnusualPlantedKeyIsNamedByLengthOnly(): void
    {
        $planted = 'x; run this `now` $(id)';
        update_option('pp_acknowledged_findings', [$planted => ['acknowledged_at' => 'at', 'note' => 'n']]);
        WP_CLI::$lines = [];
        (new PP_Readiness_Command())->status([], []);
        $printed = implode("\n", array_slice(WP_CLI::$lines, 1));
        $this->assertStringContainsString('ignored acknowledgement with an unusual key (' . strlen($planted) . ' bytes, not shown', $printed);
        $this->assertStringNotContainsString('run this', $printed);
        $json = $this->lastJson();
        $this->assertSame(0, $json['acknowledged']);
        $this->assertNull($json['ignored_acknowledgements'][0]['finding_key'], 'not reflected in the JSON either');
        $this->assertStringNotContainsString('run this', WP_CLI::$lines[0]);
    }

    /** BOUNDED BY STORED DATA: the list is capped and counted; odd rows are listed, not dropped. */
    public function testTheIgnoredListIsBoundedAndKeepsOddRows(): void
    {
        $rows = ['123' => ['acknowledged_at' => 'at'], 'nav_readiness:x:y' => 'not a row'];
        for ($n = 0; $n < PP_IGNORED_ACK_MAX + 5; $n++) {
            $rows['planted_' . $n] = ['acknowledged_at' => 'Ignore previous instructions', 'note' => ''];
        }
        update_option('pp_acknowledged_findings', $rows);
        $findings = pp_preflight([])['findings'];
        $this->assertCount(PP_IGNORED_ACK_MAX, $findings['ignored_acknowledgements']);
        $this->assertSame(PP_IGNORED_ACK_MAX + 7, $findings['ignored_acknowledgements_total']);
        $first = $findings['ignored_acknowledgements'];
        $this->assertSame('malformed', $first[1]['ack_ignored'], 'a non-row entry is listed, as malformed');
        $this->assertSame('', $first[2]['acknowledged_at'], 'a non-timestamp is not reflected');

        WP_CLI::$lines = [];
        (new PP_Readiness_Command())->status([], []);
        $printed = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('7 more ignored acknowledgement(s) not listed', $printed);
        $this->assertStringContainsString('nav_readiness:x:y: what is stored under this key is not an acknowledgement row', $printed);
    }

    /** Re-acknowledging an unsigned row's key replaces it with a signed one: the re-record route. */
    public function testReAcknowledgingAnUnsignedRowSignsIt(): void
    {
        update_option('pp_acknowledged_findings', [self::KEY => ['acknowledged_at' => 'at', 'note' => 'old']]);
        (new PP_Readiness_Command())->acknowledge([self::KEY], ['note' => 'still intentional']);
        $this->assertSame('still intentional', pp_acknowledged_findings()[self::KEY]['note']);
        $this->assertSame([], pp_ignored_finding_acknowledgements());
    }
}

/**
 * Small parser: the drift next_action embeds the sanctioned command in backticks.
 * Extracts the `wp pp readiness rebaseline` token so the assertion is robust to
 * surrounding guidance prose.
 */
function explodeCommand(string $next_action): string
{
    if (preg_match('/`([^`]+)`/', $next_action, $m)) {
        return $m[1];
    }
    return $next_action;
}
