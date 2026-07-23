<?php
/**
 * tests/SetupTest.php — PromptingPress upgrade-safety guardrails
 *
 * Covers lib/setup.php:
 *  - _pp_is_active_theme_update()   theme-slug detection across WP hook shapes
 *  - pp_block_unsafe_theme_update() the upgrader_pre_install guard
 *  - pp_schedule/unschedule/teardown integrity cron lifecycle
 *  - _pp_record_blocked_update()    last-blocked persistence
 *
 * Integrity status is driven by building a temp theme directory with a real
 * integrity-manifest.json and letting pp_check_theme_integrity() hash it —
 * the guard calls the real checker, so this exercises the real comparison.
 */

use PHPUnit\Framework\TestCase;

class SetupTest extends TestCase
{
    /** @var string[] temp theme dirs to clean up */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
            'cron'      => [],
            'filters'   => [],
        ];
        // Active theme slug the guard compares against.
        $GLOBALS['_pp_test_store']['options']['stylesheet'] = 'promptingpress';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_template_dir']);
        foreach ($this->tmpDirs as $dir) {
            $this->rmrf($dir);
        }
        $this->tmpDirs = [];
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Build a temp theme dir and point get_template_directory() at it.
     *
     * @param array  $files         relative_path => file contents
     * @param ?array $manifestHashes file_hashes for a valid manifest, or null
     * @param ?string $rawManifest  raw manifest bytes (overrides $manifestHashes)
     */
    private function makeTheme(array $files, ?array $manifestHashes, ?string $rawManifest = null): string
    {
        $dir = sys_get_temp_dir() . '/pp-setup-test-' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;

        foreach ($files as $rel => $content) {
            file_put_contents("$dir/$rel", $content);
        }

        if ($rawManifest !== null) {
            file_put_contents("$dir/integrity-manifest.json", $rawManifest);
        } elseif ($manifestHashes !== null) {
            file_put_contents("$dir/integrity-manifest.json", json_encode([
                'version'     => PP_VERSION,
                'file_hashes' => $manifestHashes,
            ]));
        }

        $GLOBALS['_pp_test_template_dir'] = $dir;
        return $dir;
    }

    private function rmrf(string $dir): void
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

    /** A theme whose files exactly match the manifest → status 'safe'. */
    private function makeSafeTheme(): void
    {
        $this->makeTheme(
            ['style.css' => 'BODY{}'],
            ['style.css' => md5('BODY{}')]
        );
    }

    // ── pp_setup_homepage: activation-path provisioning (#512) ──────────────

    /**
     * Fresh activation must create the branded static front page end-to-end:
     * a published "Home" page on composition.php, the seed written through the
     * real composition writer (pp_update_composition — which initializes the
     * #113 freshness marker), and Reading Settings pointed at it. This exercises
     * the true activation surface, not a raw meta write (Section 14.1).
     */
    public function testSetupHomepageProvisionsBrandedFrontPageOnFreshInstall(): void
    {
        // Fresh install: no static front page configured yet.
        $this->assertNotSame('page', get_option('show_on_front'));

        pp_setup_homepage();

        // Reading Settings now point at a real published page.
        $this->assertSame('page', get_option('show_on_front'));
        $front_id = (int) get_option('page_on_front');
        $this->assertGreaterThan(0, $front_id);
        $this->assertSame('page', get_post_type($front_id));
        $this->assertSame('publish', get_post_status($front_id));
        $this->assertSame('Home', get_post($front_id)->post_title);

        // Composition template assigned explicitly (not left to a save hook).
        $this->assertSame('composition.php', get_post_meta($front_id, '_wp_page_template', true));

        // The seed was written through pp_update_composition: the freshness
        // marker (#113) is initialized to version 1, proving the intended
        // writer ran rather than a bare meta write.
        $this->assertSame('1', (string) get_post_meta($front_id, '_pp_composition_version', true));

        // The stored composition is the branded multi-band starter, and it
        // round-trips as schema-valid through the real read path.
        $stored = pp_get_composition($front_id);
        $this->assertSame(
            ['hero', 'section', 'section', 'grid', 'section', 'cta'],
            array_map(static fn ($c) => $c['component'], $stored),
            'the seeded front page must be the 6-band branded starter, not a placeholder'
        );
        $this->assertGreaterThanOrEqual(5, count($stored), 'starter must be multi-band, not a 3-component stub');
        $this->assertTrue(
            pp_validate_composition($stored) === true,
            'the seeded-and-stored composition must pass pp_validate_composition()'
        );
    }

    /**
     * The idempotent guard must never overwrite an existing valid static front
     * page: an already-configured live site keeps its own homepage on
     * re-activation (the fresh-install-vs-existing-site distinction in #512's
     * acceptance criteria).
     */
    public function testSetupHomepageDoesNotOverwriteConfiguredFrontPage(): void
    {
        // An existing site: a published page is already the static front page,
        // carrying its own (non-default) composition.
        $existing_id = wp_insert_post([
            'post_type'   => 'page',
            'post_title'  => 'Existing Home',
            'post_status' => 'publish',
        ]);
        $custom = [['component' => 'hero', 'props' => ['id' => 'kept', 'title' => 'Untouched']]];
        update_post_meta(
            $existing_id,
            '_pp_composition',
            wp_slash(wp_json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
        update_option('show_on_front', 'page');
        update_option('page_on_front', $existing_id);
        $next_id_before = $GLOBALS['_pp_test_store']['next_id'];

        pp_setup_homepage();

        // No new page was created and the pointer is unchanged.
        $this->assertSame($next_id_before, $GLOBALS['_pp_test_store']['next_id'], 'must not create a second Home page');
        $this->assertSame($existing_id, (int) get_option('page_on_front'));

        // The existing composition is left exactly as the site had it.
        $kept = pp_get_composition($existing_id);
        $this->assertSame('kept', $kept[0]['props']['id'] ?? null, 'an existing valid front page must never be overwritten');
    }

    // ── _pp_is_active_theme_update: hook-shape matrix ───────────────────────

    public function testDetectsSingleUpdateShape(): void
    {
        // Theme_Upgrader::upgrade()
        $this->assertTrue(_pp_is_active_theme_update(
            ['theme' => 'promptingpress', 'type' => 'theme', 'action' => 'update']
        ));
    }

    public function testDetectsBulkUpdateShapeWithoutType(): void
    {
        // Theme_Upgrader::bulk_upgrade() passes ['theme'=>slug] with NO 'type'.
        // Regression guard: a 'type'===theme gate would wrongly return false here.
        $this->assertTrue(_pp_is_active_theme_update(['theme' => 'promptingpress']));
    }

    public function testDetectsProcessCompletePluralShape(): void
    {
        // upgrader_process_complete bulk shape. This is the behavior-preserving
        // regression coverage for the refactored upgrader_process_complete handler.
        $this->assertTrue(_pp_is_active_theme_update(
            ['themes' => ['promptingpress', 'other'], 'type' => 'theme', 'action' => 'update']
        ));
    }

    public function testIgnoresPluginUpdate(): void
    {
        $this->assertFalse(_pp_is_active_theme_update(
            ['plugin' => 'akismet/akismet.php', 'type' => 'plugin']
        ));
    }

    public function testIgnoresNonMatchingTheme(): void
    {
        $this->assertFalse(_pp_is_active_theme_update(['theme' => 'twentytwentyfour']));
    }

    public function testIgnoresNonArrayHookExtra(): void
    {
        $this->assertFalse(_pp_is_active_theme_update('nonsense'));
        $this->assertFalse(_pp_is_active_theme_update(null));
    }

    public function testNoActiveStylesheetReturnsFalse(): void
    {
        unset($GLOBALS['_pp_test_store']['options']['stylesheet']);
        $this->assertFalse(_pp_is_active_theme_update(['theme' => 'promptingpress']));
    }

    // ── pp_block_unsafe_theme_update: branch coverage ───────────────────────

    public function testPassesThroughExistingWpError(): void
    {
        $err = new WP_Error('boom', 'prior failure');
        $this->makeSafeTheme();
        $out = pp_block_unsafe_theme_update($err, ['theme' => 'promptingpress']);
        $this->assertSame($err, $out, 'must never mask an upstream WP_Error');
    }

    public function testIgnoresNonActiveThemeOperation(): void
    {
        $out = pp_block_unsafe_theme_update(true, ['plugin' => 'x/x.php', 'type' => 'plugin']);
        $this->assertTrue($out);
    }

    public function testSafeThemeAllowsUpdate(): void
    {
        $this->makeSafeTheme();
        $out = pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress']);
        $this->assertTrue($out, 'clean theme must allow the update');
    }

    public function testNullManifestAllowsUpdate(): void
    {
        // No manifest file → pp_check_theme_integrity() returns null → allow.
        $this->makeTheme(['style.css' => 'BODY{}'], null);
        $out = pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress']);
        $this->assertTrue($out, 'pre-integrity theme (no manifest) must allow update');
    }

    public function testInvalidManifestBlocksUpdate(): void
    {
        $this->makeTheme(['style.css' => 'BODY{}'], null, '{ this is not json');
        $out = pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress']);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('pp_integrity_unverifiable', $out->get_error_code());
        // The block is also recorded (with the right status) for the admin notice.
        $rec = get_option('pp_last_blocked_update');
        $this->assertIsArray($rec);
        $this->assertSame('invalid_manifest', $rec['status']);
    }

    public function testModifiedFileBlocksUpdate(): void
    {
        // Manifest expects different content than what's on disk.
        $this->makeTheme(['style.css' => 'CHANGED'], ['style.css' => md5('ORIGINAL')]);
        $out = pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress']);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('pp_integrity_unsafe', $out->get_error_code());
    }

    public function testMissingFileBlocksUpdate(): void
    {
        // Manifest lists a file that isn't on disk.
        $this->makeTheme(
            ['style.css' => 'BODY{}'],
            ['style.css' => md5('BODY{}'), 'gone.php' => md5('x')]
        );
        $out = pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress']);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('pp_integrity_unsafe', $out->get_error_code());
    }

    public function testExtraFileBlocksUpdate(): void
    {
        // A file on disk that the manifest does not list — a theme update deletes it.
        $this->makeTheme(
            ['style.css' => 'BODY{}', 'extra.css' => 'X{}'],
            ['style.css' => md5('BODY{}')]
        );
        $out = pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress']);
        $this->assertInstanceOf(WP_Error::class, $out);
        $this->assertSame('pp_integrity_unsafe', $out->get_error_code());
    }

    public function testBypassFilterAllowsUnsafeUpdate(): void
    {
        $this->makeTheme(['style.css' => 'CHANGED'], ['style.css' => md5('ORIGINAL')]);
        $GLOBALS['_pp_test_store']['filters']['pp_allow_unsafe_theme_update'] = true;
        $out = pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress']);
        $this->assertTrue($out, 'bypass filter must allow an otherwise-blocked update');
    }

    public function testBypassFilterAllowsInvalidManifestUpdate(): void
    {
        // The invalid_manifest error advertises the bypass filter, so it must
        // actually honor it — otherwise a corrupt manifest is a permanent brick.
        $this->makeTheme(['style.css' => 'BODY{}'], null, '{ not json');
        $GLOBALS['_pp_test_store']['filters']['pp_allow_unsafe_theme_update'] = true;
        $out = pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress']);
        $this->assertTrue($out, 'bypass filter must also override an unverifiable manifest');
        $this->assertFalse(get_option('pp_last_blocked_update'),
            'a bypassed update must not record a block');
    }

    // ── last-blocked persistence ────────────────────────────────────────────

    public function testBlockRecordsLastBlockedUpdate(): void
    {
        $this->makeTheme(['style.css' => 'X', 'extra.css' => 'Y'], ['style.css' => md5('X')]);
        pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress', 'action' => 'update']);

        $rec = get_option('pp_last_blocked_update');
        $this->assertIsArray($rec);
        $this->assertSame('unsafe', $rec['status']);
        $this->assertNotEmpty($rec['timestamp']);
        $this->assertContains('extra.css', $rec['extra']);
        $this->assertSame('update', $rec['trigger']);
    }

    public function testAllowedUpdateDoesNotRecordBlock(): void
    {
        $this->makeSafeTheme();
        pp_block_unsafe_theme_update(true, ['theme' => 'promptingpress']);
        $this->assertFalse(get_option('pp_last_blocked_update'));
    }

    public function testSafeCheckClearsStaleLastBlockedRecord(): void
    {
        // Files were drifted and blocked earlier; now restored to baseline.
        $GLOBALS['_pp_test_store']['options']['pp_last_blocked_update'] = [
            'timestamp' => '2026-06-24T00:00:00+00:00', 'status' => 'unsafe',
            'modified' => ['x'], 'missing' => [], 'extra' => [], 'trigger' => 'update',
        ];
        $this->makeSafeTheme();
        pp_check_theme_integrity();
        $this->assertFalse(get_option('pp_last_blocked_update'),
            'a safe integrity check must self-heal the stale blocked-update notice');
    }

    public function testLastBlockedAdminNoticeRenders(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_last_blocked_update'] = [
            'timestamp' => '2026-06-24T00:00:00+00:00',
            'status'    => 'unsafe',
            'modified'  => ['components.css'],
            'missing'   => [],
            'extra'     => [],
            'trigger'   => 'update',
        ];
        ob_start();
        pp_admin_notice_last_blocked_update();
        $html = ob_get_clean();
        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('update was blocked', $html);
        $this->assertStringContainsString('1 modified', $html);
    }

    public function testLastBlockedAdminNoticeSilentWhenNoRecord(): void
    {
        ob_start();
        pp_admin_notice_last_blocked_update();
        $this->assertSame('', ob_get_clean());
    }

    // ── cron lifecycle ──────────────────────────────────────────────────────

    public function testScheduleIntegrityCronSchedulesWhenAbsent(): void
    {
        $this->assertFalse(wp_next_scheduled('pp_daily_integrity_check'));
        pp_schedule_integrity_cron();
        $this->assertNotFalse(wp_next_scheduled('pp_daily_integrity_check'));
    }

    public function testScheduleIntegrityCronIsIdempotent(): void
    {
        pp_schedule_integrity_cron();
        pp_schedule_integrity_cron();
        // Prove the wp_next_scheduled GUARD short-circuited the second call,
        // not the stub: wp_schedule_event must have been invoked exactly once.
        $this->assertSame(
            1,
            $GLOBALS['_pp_test_store']['cron_calls']['pp_daily_integrity_check'] ?? 0,
            're-scheduling must not call wp_schedule_event a second time'
        );
    }

    public function testUnscheduleIntegrityCronClearsEvent(): void
    {
        pp_schedule_integrity_cron();
        pp_unschedule_integrity_cron();
        $this->assertFalse(wp_next_scheduled('pp_daily_integrity_check'));
    }

    public function testTeardownClearsOptionsAndCron(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_theme_integrity'] = ['status' => 'safe'];
        $GLOBALS['_pp_test_store']['options']['pp_last_blocked_update'] = ['timestamp' => 'x'];
        pp_schedule_integrity_cron();

        pp_teardown_theme_integrity();

        $this->assertFalse(get_option('pp_theme_integrity'));
        $this->assertFalse(get_option('pp_last_blocked_update'));
        $this->assertFalse(wp_next_scheduled('pp_daily_integrity_check'));
    }
}
