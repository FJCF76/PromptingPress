<?php
/**
 * tests/ContentCensusTest.php — the P-27 census trio (LAYER-3-CONTRACT.md §2.3, T-4;
 * #1242 T3b): the read-only census, the admin notice that points at it, and the re-run
 * after a WordPress core upgrade (scheduled for a later request, routed item 7).
 */

use PHPUnit\Framework\TestCase;

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

class ContentCensusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
        WP_CLI::$lines = WP_CLI::$warnings = WP_CLI::$successes = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_user_caps'], $GLOBALS['_pp_test_user_id']);
        parent::tearDown();
    }

    private function section(string $body, array $extra = []): array
    {
        return ['component' => 'section', 'props' => array_merge(['title' => 'T', 'body' => $body], $extra)];
    }

    /** A composition page with stored (raw-meta) content. */
    private function page(array $composition, string $title = 'Page'): int
    {
        $post_id = pp_create_page($title, 'publish');
        update_post_meta($post_id, '_pp_composition', wp_slash(wp_json_encode($composition)));
        update_post_meta($post_id, '_wp_page_template', 'composition.php');
        return $post_id;
    }

    private function storeSnapshot(): string
    {
        return serialize([$GLOBALS['_pp_test_store']['post_meta'], $GLOBALS['_pp_test_store']['options'],
            $GLOBALS['_pp_test_store']['posts'], $GLOBALS['_pp_test_store']['cron_single'] ?? [],
            $GLOBALS['_pp_test_store']['user_meta'] ?? []]);
    }

    // ── (1) the read-only census ───────────────────────────────────────────────────────

    public function testTheCensusListsExactlyWhatRendersDifferentlyWithItsClauseAndWritesNothing(): void
    {
        $blank = $this->page([
            $this->section('<p>clean</p>', ['title' => 'Clean band']),
            $this->section('<p><b>Note</p><p>rest</p>'),
            $this->section('<p onclick="x()">kept</p>', ['title' => 'The <code> element']),
        ], 'Blanking page');
        $clean = $this->page([$this->section('<p>all fine</p>')], 'Clean page');
        $pages = array_map(static fn ($p) => $p['id'], pp_composition_pages(true));
        $this->assertContains($blank, $pages, 'premise: both are composition pages');
        $this->assertContains($clean, $pages);

        $before = $this->storeSnapshot();
        $rows = pp_content_census();
        $this->assertSame($before, $this->storeSnapshot(), 'the census is read-only: nothing written, nothing scheduled');

        $this->assertSame([$blank, $blank, $blank], array_column($rows, 'post_id'), 'a clean prop and a clean page are absent');
        $by = [];
        foreach ($rows as $row) {
            $by[$row['band'] . ' ' . $row['prop']] = [$row['outcome'], $row['clause']];
        }
        $this->assertSame([
            '1 "body"'  => ['empty', 'P-16'],
            '2 "title"' => ['text', 'P-16'],
            '2 "body"'  => ['stripped', 'E1'],
        ], $by);
        $this->assertSame('Blanking page', $rows[0]['page']);
        $this->assertSame([$blank . ':1:"body":P-16'], pp_content_census_members($rows), 'the notice counts the EMPTY set only');
    }

    public function testTheCensusDoesNotExpandShortcodes(): void
    {
        $GLOBALS['shortcode_tags'] = ['blank' => static fn () => '</div></section>'];
        try {
            $id = $this->page([['component' => 'embed', 'props' => ['title' => 'E', 'content' => '<p>[blank]</p>']]]);
            $this->assertSame([], pp_content_census([$id]), 'it judges the stored bytes; shortcode output is the plugin boundary');
        } finally {
            unset($GLOBALS['shortcode_tags']);
        }
    }

    public function testTheCliCensusPrintsTheRowsAndWritesNothing(): void
    {
        $id = $this->page([$this->section("x</div>\u{202E}")], "Name \u{1b}[31m");
        $before = $this->storeSnapshot();
        (new PP_Content_Command())->census([], ['format' => 'json']);
        $this->assertSame($before, $this->storeSnapshot());
        $rows = json_decode(WP_CLI::$lines[0], true);
        $this->assertSame($id, $rows[0]['post_id']);
        $this->assertSame('empty', $rows[0]['outcome']);
        $this->assertSame('E10', $rows[0]['clause']);
        $this->assertStringNotContainsString("\u{1b}", WP_CLI::$lines[0], 'stored text is made printable');

        WP_CLI::$lines = [];
        (new PP_Content_Command())->census([], ['post_id' => (string) $id, 'format' => 'json']);
        $this->assertCount(1, json_decode(WP_CLI::$lines[0], true));
    }

    public function testTheCliCensusOnACleanSiteSaysSo(): void
    {
        $this->page([$this->section('<p>fine</p>')]);
        (new PP_Content_Command())->census([], []);
        $this->assertSame(['Every stored content prop renders as stored.'], WP_CLI::$successes);
    }

    // ── (3) the re-run: scheduled, never inline ────────────────────────────────────────

    public function testACoreUpgradeSchedulesTheCensusForALaterRequestAndDoesNotRunIt(): void
    {
        $this->page([$this->section('x</div>')]);
        pp_content_census_on_upgrade(null, ['action' => 'update', 'type' => 'core']);
        $this->assertArrayNotHasKey(PP_CONTENT_CENSUS_OPTION, $GLOBALS['_pp_test_store']['options'],
            'the upgrade request still has the OLD parser loaded: nothing is judged there');
        $scheduled = $GLOBALS['_pp_test_store']['cron_single'][PP_CONTENT_CENSUS_HOOK] ?? [];
        $this->assertCount(1, $scheduled);
        $this->assertSame(['core-upgrade'], array_values($scheduled)[0]['args']);
        pp_content_census_on_upgrade(null, ['action' => 'update', 'type' => 'core']);
        $this->assertSame(1, $GLOBALS['_pp_test_store']['cron_calls'][PP_CONTENT_CENSUS_HOOK], 'one pending run, not two');
    }

    public function testAPluginOrThemeUpgradeDoesNotScheduleIt(): void
    {
        pp_content_census_on_upgrade(null, ['action' => 'update', 'type' => 'plugin']);
        pp_content_census_on_upgrade(null, ['action' => 'update', 'type' => 'theme']);
        $this->assertArrayNotHasKey('cron_single', $GLOBALS['_pp_test_store']);
    }

    /**
     * The re-run raises the notice when the empty set GAINS a member, compared by membership:
     * between the runs the stored outcome changes (one prop is fixed, another breaks), so the
     * count is equal and only a membership comparison sees the growth.
     */
    public function testTheRecordedRunSeesGrowthByMembershipNotCount(): void
    {
        $id = $this->page([$this->section('x</div>'), $this->section('<p>fine</p>')]);
        $first = pp_content_census_record('theme-version');
        $this->assertSame([$id . ':0:"body":E10'], $first['members']);
        $this->assertTrue($first['grew'], 'first run: every member is new');
        $this->assertSame(1, $first['pages']);

        $same = pp_content_census_record('core-upgrade');
        $this->assertFalse($same['grew'], 'an unchanged set did not grow');

        update_post_meta($id, '_pp_composition', wp_slash(wp_json_encode([$this->section('<p>fixed</p>'), $this->section('y</div>')])));
        $swapped = pp_content_census_record('core-upgrade');
        $this->assertSame(1, count($swapped['members']), 'same count');
        $this->assertTrue($swapped['grew'], 'a new member is growth, whatever the count');
        $this->assertSame($swapped, get_option(PP_CONTENT_CENSUS_OPTION), 'the scheduled run is the one writer of the state');
    }

    public function testANewThemeVersionSchedulesItsFirstCensusOnce(): void
    {
        pp_content_census_on_admin_init();
        $this->assertCount(1, $GLOBALS['_pp_test_store']['cron_single'][PP_CONTENT_CENSUS_HOOK]);
        pp_content_census_record('theme-version');
        unset($GLOBALS['_pp_test_store']['cron_single']);
        pp_content_census_on_admin_init();
        $this->assertArrayNotHasKey('cron_single', $GLOBALS['_pp_test_store'], 'the same version does not schedule again');
    }

    public function testAnAcceptedWriteSchedulesARerunOnlyWhileTheSetIsNonEmpty(): void
    {
        $id = $this->page([$this->section('<p>fine</p>')]);
        $this->assertTrue(pp_update_composition($id, [$this->section('<p>fine 2</p>')]));
        $this->assertArrayNotHasKey('cron_single', $GLOBALS['_pp_test_store'], 'no recorded empty set: nothing to refresh');

        update_option(PP_CONTENT_CENSUS_OPTION, ['members' => [$id . ':0:"body":E10']]);
        $this->assertTrue(pp_update_composition($id, [$this->section('<p>fine 3</p>')]));
        $scheduled = $GLOBALS['_pp_test_store']['cron_single'][PP_CONTENT_CENSUS_HOOK] ?? [];
        $this->assertSame(['content-write'], array_values($scheduled)[0]['args'] ?? null);
    }

    // ── (2) the admin notice ───────────────────────────────────────────────────────────

    private function notice(): string
    {
        ob_start();
        pp_admin_notice_content_census();
        return (string) ob_get_clean();
    }

    public function testTheNoticePointsAtTheCensusAndHonoursADismissalOfThatExactSet(): void
    {
        $this->assertSame('', $this->notice(), 'no recorded census: no notice');
        $id = $this->page([$this->section('x</div>')]);
        pp_content_census_record('core-upgrade');
        $html = $this->notice();
        $this->assertStringContainsString('1 stored content prop(s) on 1 page(s) render empty', $html);
        $this->assertStringContainsString('wp pp content census', $html);
        $this->assertStringContainsString('The list grew after the WordPress update to', $html);
        $this->assertStringContainsString('action=pp_content_census_dismiss', $html);

        $key = pp_content_census_membership_key(get_option(PP_CONTENT_CENSUS_OPTION)['members']);
        update_user_meta(get_current_user_id(), PP_CONTENT_CENSUS_DISMISSED_META, $key);
        $this->assertSame('', $this->notice(), 'dismissed for this exact set');

        update_post_meta($id, '_pp_composition', wp_slash(wp_json_encode([$this->section('x</div>'), $this->section('<p><b>a</p>')])));
        pp_content_census_record('core-upgrade');
        $this->assertStringContainsString('2 stored content prop(s)', $this->notice(), 'a grown set raises it again');
    }

    public function testTheNoticeIsForAdministratorsOnly(): void
    {
        $this->page([$this->section('x</div>')]);
        pp_content_census_record('core-upgrade');
        $GLOBALS['_pp_test_user_caps'] = ['manage_options' => false];
        $this->assertSame('', $this->notice());
    }

    public function testATitleRenderedAsTextNeverRaisesTheNotice(): void
    {
        $this->page([$this->section('<p>b</p>', ['title' => 'The <code> element'])]);
        $state = pp_content_census_record('core-upgrade');
        $this->assertSame([], $state['members'], 'it renders as it always did; the census lists it, the notice does not');
        $this->assertSame('', $this->notice());
    }

    public function testAPendingWeightierRunCoversALighterTrigger(): void
    {
        pp_content_census_on_upgrade(null, ['type' => 'core']);
        pp_content_census_schedule('content-write');
        pp_content_census_schedule('theme-version');
        $this->assertSame(1, $GLOBALS['_pp_test_store']['cron_calls'][PP_CONTENT_CENSUS_HOOK], 'one whole-site run, not three');
        unset($GLOBALS['_pp_test_store']['cron_single'], $GLOBALS['_pp_test_store']['cron_calls']);
        pp_content_census_schedule('content-write');
        pp_content_census_on_upgrade(null, ['type' => 'core']);
        $this->assertSame(2, $GLOBALS['_pp_test_store']['cron_calls'][PP_CONTENT_CENSUS_HOOK], 'a core upgrade still gets its own run');
    }

    public function testTheThemeVersionIsRecordedWhenScheduledSoADyingRunIsNotRequeuedForever(): void
    {
        pp_content_census_on_admin_init();
        $this->assertSame(PP_VERSION, get_option(PP_CONTENT_CENSUS_VERSION_OPTION));
        unset($GLOBALS['_pp_test_store']['cron_single']);
        pp_content_census_on_admin_init();
        $this->assertArrayNotHasKey('cron_single', $GLOBALS['_pp_test_store']);
    }

    public function testANonAdministratorNeverSchedulesTheCensus(): void
    {
        $GLOBALS['_pp_test_user_caps'] = ['manage_options' => false];
        pp_content_census_on_admin_init();
        $this->assertArrayNotHasKey('cron_single', $GLOBALS['_pp_test_store']);
        $this->assertFalse(get_option(PP_CONTENT_CENSUS_VERSION_OPTION, false));
    }

    /** Ruling Q2: the scheduled census runs in resumable batches and only a finished run feeds the notice. */
    public function testTheScheduledCensusRunsInResumableBatches(): void
    {
        $big = $this->page([$this->section('<p>ok</p>', ['layout' => 'text-panel',
            'panel_body' => str_repeat('x', PP_CONTENT_RENDER_MAX_BYTES + 10)])], 'Big page');
        $broken = $this->page([$this->section('x</div>')], 'Broken page');
        $order = array_map(static fn ($p) => (int) $p['id'], pp_composition_pages(true));
        $this->assertSame([$big, $broken], $order, 'premise: the large page is read first');

        $first = pp_content_census_record('core-upgrade');
        $this->assertSame([$broken], $first['pending'], 'one batch: the budget is spent on the large page');
        $this->assertArrayNotHasKey(PP_CONTENT_CENSUS_OPTION, $GLOBALS['_pp_test_store']['options'], 'no half-finished state reaches the notice');
        $this->assertSame([['continue']], array_column($GLOBALS['_pp_test_store']['cron_single'][PP_CONTENT_CENSUS_HOOK], 'args'));

        $done = pp_content_census_record('continue');
        $this->assertSame([$broken . ':0:"body":E10'], $done['members']);
        $this->assertSame('core-upgrade', $done['trigger'], 'the run keeps the trigger that started it');
        $this->assertSame([], get_option(PP_CONTENT_CENSUS_PROGRESS_OPTION), 'the cursor is cleared');
    }

    public function testALighterTriggerDuringARunKeepsTheCursorAndRunsAgainAfter(): void
    {
        $this->page([$this->section('<p>ok</p>', ['layout' => 'text-panel', 'panel_body' => str_repeat('x', PP_CONTENT_RENDER_MAX_BYTES + 10)])]);
        $this->page([$this->section('x</div>')]);
        $first = pp_content_census_record('theme-version');
        $this->assertCount(1, $first['pending']);
        $second = pp_content_census_record('content-write');
        $this->assertSame('theme-version', $second['trigger'], 'the run in progress kept its cursor and trigger');
        $this->assertSame([], get_option(PP_CONTENT_CENSUS_PROGRESS_OPTION), 'and finished');
        $this->assertContains(['content-write'], array_column($GLOBALS['_pp_test_store']['cron_single'][PP_CONTENT_CENSUS_HOOK], 'args'),
            'the edit gets its own run afterwards');
    }

    public function testABatchNeverReadsMoreThanTheBudgetAcrossPages(): void
    {
        $half = (int) (PP_CONTENT_RENDER_MAX_BYTES * 0.6);
        $a = $this->page([$this->section('<p>a</p>', ['layout' => 'text-panel', 'panel_body' => str_repeat('x', $half)])]);
        $b = $this->page([$this->section('<p>b</p>', ['layout' => 'text-panel', 'panel_body' => str_repeat('y', $half)])]);
        $first = pp_content_census_record('core-upgrade');
        $this->assertSame([$b], $first['pending'], 'two pages that do not fit one batch are read in two');
        $this->assertSame([$b], get_option(PP_CONTENT_CENSUS_PROGRESS_OPTION)['pending'], 'the cursor is saved past the page read');
    }

    public function testAContinueLeftQueuedAfterItsRunFinishedDoesNothing(): void
    {
        $this->page([$this->section('<p>ok</p>', ['layout' => 'text-panel', 'panel_body' => str_repeat('x', PP_CONTENT_RENDER_MAX_BYTES + 10)])]);
        $this->page([$this->section('x</div>')]);
        pp_content_census_record('core-upgrade');
        $finished = pp_content_census_record('content-write'); // joins the run and finishes it
        $this->assertSame('core-upgrade', $finished['trigger']);
        $this->assertNotContains(['continue'], array_column($GLOBALS['_pp_test_store']['cron_single'][PP_CONTENT_CENSUS_HOOK] ?? [], 'args'),
            'the finished run clears its queued continue');
        $again = pp_content_census_record('continue');
        $this->assertSame($finished, $again, 'a stale continue starts nothing');
        $this->assertSame([], get_option(PP_CONTENT_CENSUS_PROGRESS_OPTION));
    }

    public function testARunThatDiedOnItsLastPageIsFinishedByItsContinue(): void
    {
        $id = $this->page([$this->section('x</div>')]);
        // The state a request leaves when it dies judging the last page: the cursor already
        // past it, a continue already queued, the page's members not yet added.
        update_option(PP_CONTENT_CENSUS_PROGRESS_OPTION, ['pending' => [], 'members' => [], 'trigger' => 'core-upgrade',
            'started' => time(), 'rerun' => false]);
        $state = pp_content_census_record('continue');
        $this->assertSame('core-upgrade', $state['trigger'], 'the run is finished, not abandoned');
        $this->assertSame([], get_option(PP_CONTENT_CENSUS_PROGRESS_OPTION));
    }

    public function testTheLastPageAlsoQueuesItsContinueBeforeItIsJudged(): void
    {
        $this->page([$this->section('<p>only page</p>')]);
        $GLOBALS['_pp_test_store']['options'][PP_CONTENT_CENSUS_PROGRESS_OPTION] = [];
        pp_content_census_record('core-upgrade');
        $this->assertSame(1, $GLOBALS['_pp_test_store']['cron_calls'][PP_CONTENT_CENSUS_HOOK] ?? 0,
            'queued before the page is judged, then cleared when the run finished');
        $this->assertSame([], $GLOBALS['_pp_test_store']['cron_single'][PP_CONTENT_CENSUS_HOOK] ?? []);
    }
}
