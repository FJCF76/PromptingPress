<?php
/**
 * tests/ScreenshotTest.php — Tests for screenshot capture infrastructure
 *
 * Covers: spec generation, capture error handling, directory management,
 * pruning, and output path conventions.
 */

use PHPUnit\Framework\TestCase;

class ScreenshotTest extends TestCase
{
    private string $screenshotDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->screenshotDir = sys_get_temp_dir() . '/pp-screenshot-test-' . getmypid() . '-' . mt_rand();
        mkdir($this->screenshotDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->screenshotDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // ── Spec Generation ────────────────────────────────────────────────────

    public function testScreenshotSpecGeneratesCorrectURLsAndPaths(): void
    {
        $specs = pp_screenshot_spec(42, 'create-page');

        $this->assertCount(2, $specs, 'Should generate specs for 2 viewports');

        // Desktop spec
        $desktop = $specs[0];
        $this->assertStringContainsString('page_id=42', $desktop['url']);
        $this->assertEquals(1280, $desktop['width']);
        $this->assertEquals(800, $desktop['height']);
        $this->assertStringContainsString('create-page/42/', $desktop['output']);
        $this->assertStringContainsString('-desktop.png', $desktop['output']);

        // Mobile spec
        $mobile = $specs[1];
        $this->assertEquals(375, $mobile['width']);
        $this->assertEquals(812, $mobile['height']);
        $this->assertStringContainsString('-mobile.png', $mobile['output']);
    }

    // ── Capture — No Browser ───────────────────────────────────────────────

    public function testCaptureReturnsNoBrowserErrorWhenNotConfigured(): void
    {
        // Ensure PP_BROWSER_CMD is not set
        // (It shouldn't be in the test environment, but let's be explicit)
        putenv('PP_BROWSER_CMD');

        $result = pp_screenshot_capture([
            'url'    => 'https://example.com',
            'width'  => 1280,
            'height' => 800,
            'output' => $this->screenshotDir . '/test.png',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertEquals('no_browser', $result['error']);
    }

    // ── Readiness / doctor / status (#84) ───────────────────────────────────

    public function testNoBrowserCarriesNeedsVisualVerificationStatus(): void
    {
        // Operating model (operating-loop.md): an unconfigured browser means capture was
        // never attempted -> NEEDS_VISUAL_VERIFICATION, NOT SCREENSHOT_FAILED.
        putenv('PP_BROWSER_CMD');
        $result = pp_screenshot_capture([
            'url'    => 'https://example.com',
            'width'  => 1280,
            'height' => 800,
            'output' => $this->screenshotDir . '/test.png',
        ]);
        $this->assertSame('NEEDS_VISUAL_VERIFICATION', $result['status']);
    }

    public function testConfiguredButFailedCaptureCarriesScreenshotFailedStatus(): void
    {
        // Browser IS configured but the capture itself fails -> SCREENSHOT_FAILED, the
        // explicit machine-readable status so the loop never claims a false VERIFIED.
        putenv('PP_BROWSER_CMD=/bin/false');
        try {
            $result = pp_screenshot_capture([
                'url'    => 'https://example.com',
                'width'  => 1280,
                'height' => 800,
                'output' => $this->screenshotDir . '/test.png',
            ]);
            $this->assertSame('SCREENSHOT_FAILED', $result['status']);
        } finally {
            putenv('PP_BROWSER_CMD');
        }
    }

    public function testReadinessReportsNotReadyAndContextWhenUnconfigured(): void
    {
        putenv('PP_BROWSER_CMD');
        $readiness = pp_screenshot_readiness();
        $this->assertFalse($readiness['ready']);
        // Tri-state (#497): unconfigured is the definitive `unavailable` STATE, not an
        // ambient "may not work" warning.
        $this->assertSame('unavailable', $readiness['state']);
        $this->assertSame('cli', $readiness['context']); // PHPUnit runs under the CLI SAPI
        $this->assertNull($readiness['source']);
        $this->assertStringContainsString('PP_BROWSER_CMD', $readiness['message']);
        // No probe requested and no candidates requested: candidates stays out of the
        // lean (preflight) shape.
        $this->assertArrayNotHasKey('candidates', $readiness);
    }

    public function testReadinessReadyWhenEnvConfigured(): void
    {
        putenv('PP_BROWSER_CMD=/bin/true');
        try {
            $readiness = pp_screenshot_readiness(); // capability only, no probe
            $this->assertTrue($readiness['ready']);
            // /bin/true resolves on $PATH → `available` (resolves; not yet capture-verified).
            $this->assertSame('available', $readiness['state']);
            $this->assertSame('env', $readiness['source']);
            $this->assertSame('/bin/true', $readiness['browser_cmd']);
        } finally {
            putenv('PP_BROWSER_CMD');
        }
    }

    public function testReadinessBrokenStateWhenBinaryMissingNoExec(): void
    {
        // Configured but the command's binary is not on $PATH → `broken`, detected WITHOUT
        // launching a process (cheap enough for the read-only preflight surface).
        putenv('PP_BROWSER_CMD=/nonexistent/pp-screenshot-binary-xyz');
        try {
            $readiness = pp_screenshot_readiness(); // no probe
            $this->assertFalse($readiness['ready']);
            $this->assertSame('broken', $readiness['state']);
            $this->assertNull($readiness['probe'], 'binary-missing broken must not have run a probe');
            $this->assertStringContainsString('not found on $PATH', $readiness['message']);
        } finally {
            putenv('PP_BROWSER_CMD');
        }
    }

    public function testReadinessAvailableStateWithVerifiedProbe(): void
    {
        // A fake adapter that honors the contract (writes a PNG to --output, exits 0) makes
        // the probe path reach a definitive, capture-verified `available` — and we inspect
        // what it wrote (Section 14.2): a non-empty file.
        $adapter = $this->screenshotDir . '/fake-adapter.sh';
        file_put_contents($adapter, <<<'SH'
#!/bin/sh
out=""
for arg in "$@"; do
  case "$arg" in
    --output=*) out="${arg#--output=}" ;;
  esac
done
[ -n "$out" ] || exit 3
printf '\211PNG\r\n\032\n fake pixels' > "$out"
exit 0
SH
        );
        chmod($adapter, 0755);
        putenv('PP_BROWSER_CMD=' . $adapter);
        try {
            $readiness = pp_screenshot_readiness(true); // probe
            $this->assertTrue($readiness['ready'], $readiness['message']);
            $this->assertSame('available', $readiness['state']);
            $this->assertIsArray($readiness['probe']);
            $this->assertTrue($readiness['probe']['ok'], 'the fake adapter capture should succeed');
            $this->assertGreaterThan(0, $readiness['probe']['bytes'], 'probe must report the captured byte count');
        } finally {
            putenv('PP_BROWSER_CMD');
            foreach (glob(pp_screenshot_dir() . '/.doctor-probe-*.png') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    public function testProbeArbitratesShellFormWhoseFirstTokenIsNotOnPath(): void
    {
        // Regression: a shell command line whose FIRST token is not a $PATH binary (here an
        // env-var prefix) is `broken` on the cheap no-exec check, but `doctor --probe` must
        // NOT short-circuit — the real capture is the arbiter, and this one captures fine.
        $adapter = $this->screenshotDir . '/env-prefixed-adapter.sh';
        file_put_contents($adapter, <<<'SH'
#!/bin/sh
out=""
for arg in "$@"; do
  case "$arg" in --output=*) out="${arg#--output=}" ;; esac
done
[ -n "$out" ] || exit 3
printf '\211PNG\r\n\032\n fake pixels' > "$out"
exit 0
SH
        );
        chmod($adapter, 0755);
        // First token is `PP_FAKE=1`, which is not a binary on $PATH.
        putenv('PP_BROWSER_CMD=PP_FAKE=1 ' . $adapter);
        try {
            // No probe: the cheap check cannot resolve the first token → broken.
            $cheap = pp_screenshot_readiness();
            $this->assertSame('broken', $cheap['state']);

            // Probe (doctor default): the actual capture succeeds → available, not a false broken.
            $probed = pp_screenshot_readiness(true);
            $this->assertSame('available', $probed['state'], $probed['message']);
            $this->assertTrue($probed['ready']);
            $this->assertTrue($probed['probe']['ok']);
            $this->assertGreaterThan(0, $probed['probe']['bytes']);
        } finally {
            putenv('PP_BROWSER_CMD');
            foreach (glob(pp_screenshot_dir() . '/.doctor-probe-*.png') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    public function testReadinessCandidatesIncludedForDoctorWhenUnconfigured(): void
    {
        // Doctor passes include_candidates=true so an unconfigured operator gets discovery
        // hints. The set may be empty on a browserless container — the CONTRACT is that the
        // key is present and is a list, each entry naming a binary + resolved path.
        putenv('PP_BROWSER_CMD');
        $readiness = pp_screenshot_readiness(true, true); // doctor shape: probe + candidates
        $this->assertSame('unavailable', $readiness['state']);
        $this->assertArrayHasKey('candidates', $readiness);
        $this->assertIsArray($readiness['candidates']);
        foreach ($readiness['candidates'] as $cand) {
            $this->assertArrayHasKey('name', $cand);
            $this->assertArrayHasKey('path', $cand);
        }
    }

    public function testCandidateBrowsersReturnsList(): void
    {
        $candidates = pp_screenshot_candidate_browsers();
        $this->assertIsArray($candidates);
    }

    public function testCommandBinaryIsQuoteAwareFirstToken(): void
    {
        $this->assertSame('/usr/bin/node', pp_screenshot_command_binary('/usr/bin/node /path/shot.js --flag'));
        $this->assertSame('/opt/my browser/pp-shot', pp_screenshot_command_binary('"/opt/my browser/pp-shot" --width=1'));
        $this->assertSame('pp-shot', pp_screenshot_command_binary('pp-shot'));
        $this->assertSame('', pp_screenshot_command_binary('   '));
    }

    public function testPreflightSurfacesScreenshotReadinessAsNonBlockingWarning(): void
    {
        putenv('PP_BROWSER_CMD');
        $result = pp_preflight([]);
        $shotChecks = array_values(array_filter(
            $result['checks'],
            fn ($c) => ($c['check'] ?? '') === 'screenshot_readiness'
        ));
        $this->assertCount(1, $shotChecks, 'Preflight must surface a screenshot readiness check.');
        // Warning severity is what makes it advisory: pp_preflight ignores severity=warning
        // rows when computing `ok`, so an unready browser never blocks a typed mutation.
        $this->assertSame('warning', $shotChecks[0]['severity']);
        $this->assertFalse($shotChecks[0]['pass'], 'With no browser, the readiness check should fail...');
        // ...but it must NOT block. Prove the behavior, not just the label: rebuild the
        // blocking set exactly as pp_preflight does (non-warning failures) and assert the
        // readiness check is never in it, regardless of other checks' state.
        $blocking = array_filter(
            $result['checks'],
            fn ($c) => !$c['pass'] && (($c['severity'] ?? 'error') !== 'warning')
        );
        $this->assertNotContains(
            'screenshot_readiness',
            array_column($blocking, 'check'),
            'An unready browser (warning) must never be a blocking preflight failure.'
        );
    }

    public function testPreflightUnavailableFindingCarriesUnavailableState(): void
    {
        // #497: preflight renders the tri-state distinctly. Unconfigured → a capability
        // finding tagged state `unavailable`, surfaced in the classified findings block.
        putenv('PP_BROWSER_CMD');
        $result = pp_preflight([]);
        $shot = array_values(array_filter(
            $result['checks'],
            fn ($c) => ($c['check'] ?? '') === 'screenshot_readiness'
        ))[0];
        $this->assertFalse($shot['pass']);
        $this->assertSame('capability', $shot['class']);
        $this->assertSame('unavailable', $shot['state']);
        $this->assertSame('wp pp screenshot doctor', $shot['next_action']);
        // The classified findings block copies the sub-state through.
        $cap = $result['findings']['by_class']['capability'];
        $row = array_values(array_filter($cap, fn ($r) => ($r['check'] ?? '') === 'screenshot_readiness'))[0];
        $this->assertSame('unavailable', $row['state']);
    }

    public function testPreflightBrokenFindingCarriesBrokenState(): void
    {
        // #497: a configured-but-missing binary renders as `broken` in preflight, WITHOUT
        // preflight launching a browser (the cheap non-exec check), and stays non-blocking.
        putenv('PP_BROWSER_CMD=/nonexistent/pp-screenshot-binary-xyz');
        try {
            $result = pp_preflight([]);
            $shot = array_values(array_filter(
                $result['checks'],
                fn ($c) => ($c['check'] ?? '') === 'screenshot_readiness'
            ))[0];
            $this->assertFalse($shot['pass']);
            $this->assertSame('capability', $shot['class']);
            $this->assertSame('broken', $shot['state']);
            // Distinct from `unavailable`: the two states never collapse into one warning.
            $blocking = array_filter(
                $result['checks'],
                fn ($c) => !$c['pass'] && (($c['severity'] ?? 'error') !== 'warning')
            );
            $this->assertNotContains('screenshot_readiness', array_column($blocking, 'check'));
        } finally {
            putenv('PP_BROWSER_CMD');
        }
    }

    public function testReadinessProbeReflectsCaptureOutcome(): void
    {
        // --probe runs a real capture; a failing adapter must flip ready to false and
        // report the probe failure (no false VERIFIED off a broken capture).
        putenv('PP_BROWSER_CMD=/bin/false');
        try {
            $readiness = pp_screenshot_readiness(true);
            $this->assertFalse($readiness['ready'], 'A failing probe must report not-ready.');
            // A configured-but-failing probe is the `broken` state (#497), distinct from
            // `unavailable` (never configured).
            $this->assertSame('broken', $readiness['state']);
            $this->assertIsArray($readiness['probe']);
            $this->assertFalse($readiness['probe']['ok'], 'The probe sub-result must record the failure.');
        } finally {
            putenv('PP_BROWSER_CMD');
            // Clean up any probe artifact the capture attempt may have created.
            foreach (glob(pp_screenshot_dir() . '/.doctor-probe-*.png') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    // ── Directory ──────────────────────────────────────────────────────────

    public function testScreenshotDirCreatesDirectory(): void
    {
        // PP_SCREENSHOT_DIR is not defined, so it falls back to WP_CONTENT_DIR
        $dir = pp_screenshot_dir();
        $this->assertIsString($dir);
        $this->assertDirectoryExists($dir);

        // Clean up the created directory
        if (is_dir($dir) && strpos($dir, 'pp-screenshots') !== false) {
            @rmdir($dir);
        }
    }

    // ── Pruning ────────────────────────────────────────────────────────────

    public function testPruneKeepsMostRecentNFiles(): void
    {
        // Create 15 fake screenshot files with different mtimes
        for ($i = 1; $i <= 15; $i++) {
            $file = $this->screenshotDir . "/screenshot-{$i}.png";
            file_put_contents($file, "fake-{$i}");
            touch($file, time() - (20 - $i)); // older files have older mtime
        }

        $deleted = pp_screenshot_prune($this->screenshotDir, 10);

        $this->assertEquals(5, $deleted);

        $remaining = glob($this->screenshotDir . '/*.png');
        $this->assertCount(10, $remaining);

        // Verify the 5 oldest were deleted (files 1-5)
        for ($i = 1; $i <= 5; $i++) {
            $this->assertFileDoesNotExist(
                $this->screenshotDir . "/screenshot-{$i}.png",
                "File screenshot-{$i}.png should have been pruned"
            );
        }
    }

    public function testPruneReturnsZeroOnEmptyDirectory(): void
    {
        $deleted = pp_screenshot_prune($this->screenshotDir, 10);
        $this->assertEquals(0, $deleted);
    }

    public function testPruneReturnsZeroWhenUnderLimit(): void
    {
        // Create 5 files (under default keep=10)
        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($this->screenshotDir . "/screenshot-{$i}.png", "fake-{$i}");
        }

        $deleted = pp_screenshot_prune($this->screenshotDir, 10);
        $this->assertEquals(0, $deleted);
    }

    public function testPruneReturnsZeroForNonExistentDirectory(): void
    {
        $deleted = pp_screenshot_prune('/nonexistent/path/' . mt_rand(), 10);
        $this->assertEquals(0, $deleted);
    }

    // ── Output Path Convention ─────────────────────────────────────────────

    public function testOutputPathFollowsConvention(): void
    {
        $specs = pp_screenshot_spec(42, 'create-page');

        foreach ($specs as $spec) {
            // Path should contain: pp-screenshots/playbook/post_id/timestamp-viewport.png
            $this->assertMatchesRegularExpression(
                '#/create-page/42/\d{8}-\d{6}-(desktop|mobile)\.png$#',
                $spec['output']
            );
        }
    }
}
