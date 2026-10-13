<?php
/**
 * tests/BatchStepThrowableGuardTest.php
 *
 * A batch step whose executor THROWS is a failed step, not an escaped exception (#1272).
 *
 * Before the guard, a throw from pp_execute_action() / pp_execute_apply() left
 * pp_ai_execute_batch() before its rollback ran: the steps before it stayed applied and the
 * chat request died as a 500. Now the throw becomes a failed-step envelope and the batch
 * takes the same exit a refused step takes.
 *
 * WHAT IS PINNED, through the real pp_ai_execute_batch() (Section 14.1) and, once, through
 * the chat handler behind wp_ajax_pp_ai_execute_batch:
 *   - the steps before the throw are rolled back, and so is the throwing step's own write
 *     when it wrote before throwing;
 *   - an Error (TypeError) is caught as well as an Exception;
 *   - no step after the throw runs;
 *   - an apply step is guarded the same way as an action step;
 *   - the failed step carries a FIXED message and code, and no field of the envelope (nor
 *     the chat response) carries the exception's text; the chat attaches no model note,
 *     because a throw is a site fault the model cannot repair;
 *   - the exception's class, message and site land in the error log, as ONE line that a
 *     CR/LF in the message or the step name cannot split;
 *   - a create_page / redirect step that writes and then throws (a write the rollback can
 *     only learn of from a succeeded result) adds one fixed `failed` rollback entry, so the
 *     channel is never empty when an orphan may survive; a refused or snapshot-covered step
 *     adds none;
 *   - the guard's own handler holds for a string-keyed step list, and an output buffer the
 *     throwing step left open is discarded;
 *   - the post-apply validator's own catch arm (the second site the issue's addendum names)
 *     reports a fixed message too, with the details in the log only.
 *
 * NOT DUPLICATED HERE: a step refused by VALIDATION rolls the batch back — that path is
 * SetMenuItemTypeValidationTest's. Every throw here comes from a step that passed
 * validation and reached its executor.
 *
 * HOW THE THROW IS PRODUCED. The real executor is wrapped in the action/apply registry for
 * the duration of one test: it runs the real write, then throws. That is the worst case the
 * guard has to survive (a step that changed state before failing), with no production seam.
 * The post-apply arm uses the bootstrap's `upload_dir_throws` hook, because the validator
 * reads the upload dir outside its per-component try; the step arms it after its own write.
 */

use PHPUnit\Framework\TestCase;

class BatchStepThrowableGuardTest extends TestCase
{
    /**
     * Stands in for what an exception message can carry: a stored value and a server path.
     * None of it may reach an envelope; all of it must reach the log.
     */
    private const SENTINEL = 'STORED-VALUE-7f3a /srv/site/wp-config.php';

    private const MENU = 'Main Menu';

    private string $log = '';
    private $saved_log = false;
    private array $saved_actions = [];
    private array $saved_applies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'connectors' => [],
            'next_id'    => 100,
            'custom_css' => '',
            'filters'    => [],
        ];
        // The batch gate reads the postmeta row through the handle and fails closed without
        // one (#833); production always has one.
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();

        global $_pp_actions, $_pp_applies;
        $this->saved_actions = $_pp_actions;
        $this->saved_applies = $_pp_applies;

        $this->log       = (string) tempnam(sys_get_temp_dir(), 'pp1272');
        $this->saved_log = ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        global $_pp_actions, $_pp_applies;
        $_pp_actions = $this->saved_actions;
        $_pp_applies = $this->saved_applies;

        ini_set('error_log', is_string($this->saved_log) ? $this->saved_log : '');
        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Makes the named action run its real executor, then throw $throwable. */
    private function throwAfterAction(string $name, \Throwable $throwable): void
    {
        global $_pp_actions;
        $real = $_pp_actions[$name]['execute'];
        $_pp_actions[$name]['execute'] = static function (array $params) use ($real, $throwable) {
            call_user_func($real, $params);
            throw $throwable;
        };
    }

    /** Makes the named apply run its real callable, then throw $throwable. */
    private function throwAfterApply(string $name, \Throwable $throwable): void
    {
        global $_pp_applies;
        $real = $_pp_applies[$name]['apply'];
        $_pp_applies[$name]['apply'] = static function (array $params) use ($real, $throwable) {
            call_user_func($real, $params);
            throw $throwable;
        };
    }

    private function seedMenu(): array
    {
        $page   = pp_create_page('Pricing', 'publish');
        $result = pp_execute_action('set_menu', [
            'name'     => self::MENU,
            'items'    => [['page_id' => $page], ['url' => 'https://example.com/blog', 'label' => 'Blog']],
            'location' => 'primary',
        ]);
        $this->assertTrue($result['ok'], 'premise: the seed menu was built');
        return pp_get_menus();
    }

    private function seoPage(string $title = 'Original SEO title'): int
    {
        $page = pp_create_page('Landing', 'publish');
        pp_update_seo_meta($page, ['seo_title' => $title]);
        return $page;
    }

    private function seoStep(int $page, string $title = 'Changed by step 0'): array
    {
        return ['type' => 'action', 'name' => 'update_seo_meta', 'params' => [
            'post_id' => $page, 'meta' => ['seo_title' => $title],
        ]];
    }

    private function logText(): string
    {
        return (string) file_get_contents($this->log);
    }

    /** No byte of the sentinel's distinctive parts appears anywhere in $value. */
    private function assertNoReflection($value, string $where): void
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('STORED-VALUE-7f3a', $json, "{$where}: no stored value");
        $this->assertStringNotContainsString('wp-config.php', $json, "{$where}: no path");
        $this->assertStringNotContainsString('RuntimeException', $json, "{$where}: no exception class");
        $this->assertStringNotContainsString('TypeError', $json, "{$where}: no error class");
    }

    /** The failed step is the fixed envelope, whatever was thrown. */
    private function assertThrewEnvelope(array $step, string $action): void
    {
        $this->assertFalse($step['ok']);
        $this->assertSame($action, $step['action']);
        $this->assertSame(PP_BATCH_STEP_THREW_MESSAGE, $step['error'], 'the fixed message');
        $this->assertSame(PP_BATCH_STEP_THREW_CODE, $step['error_code'], 'the fixed code');
        $this->assertNull($step['index'], 'a throw belongs to no band');
        $this->assertSame([], $step['changes']);
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    /**
     * THE ISSUE'S CASE. Step 0 lands; step 1 writes the menu and then throws. Both are
     * rolled back, cleanly, and the failed step says only that it stopped on an internal
     * error. The details are in the log.
     */
    public function testALaterStepThatThrowsAfterItsOwnWriteRollsBackEveryStep(): void
    {
        $menus = $this->seedMenu();
        $page  = $this->seoPage();
        $this->throwAfterAction('set_menu', new \RuntimeException(self::SENTINEL));

        $batch = pp_ai_execute_batch([
            $this->seoStep($page),
            ['type' => 'action', 'name' => 'set_menu', 'params' => [
                'name'  => self::MENU,
                'items' => [['url' => 'https://example.com/only', 'label' => 'Only']],
            ]],
        ]);

        $this->assertTrue($batch['steps'][0]['ok'], 'premise: step 0 really landed');
        $this->assertFalse($batch['ok'], 'the batch failed');
        $this->assertSame(1, $batch['failed_at'], 'at the step that threw');
        $this->assertCount(2, $batch['steps']);
        $this->assertThrewEnvelope($batch['steps'][1], 'set_menu');
        $this->assertTrue($batch['rolled_back'], 'and rolled back');
        $this->assertSame([], $batch['rollback_errors'], 'cleanly');
        $this->assertSame([], $batch['rollback_error_kinds']);
        $this->assertSame([], $batch['versions']);

        $this->assertSame('Original SEO title', pp_get_seo_meta($page)['seo_title'], 'step 0 is undone');
        $this->assertSame($menus, pp_get_menus(), "the throwing step's own write is undone");

        $this->assertNoReflection($batch, 'batch envelope');
        $log = $this->logText();
        $this->assertStringContainsString('batch step 1 (set_menu) threw: RuntimeException: ' . self::SENTINEL, $log);
        $this->assertStringContainsString(' at ' . __FILE__ . ':', $log, 'with the throw site');
    }

    /** \Throwable, not \Exception: a TypeError is the likeliest throw and it is an Error. */
    public function testAnErrorIsCaughtAsWellAsAnException(): void
    {
        $page = $this->seoPage();
        $this->throwAfterAction('update_page_title', new \TypeError(self::SENTINEL));

        $batch = pp_ai_execute_batch([
            $this->seoStep($page),
            ['type' => 'action', 'name' => 'update_page_title', 'params' => [
                'post_id' => $page, 'title' => 'Renamed by step 1',
            ]],
        ]);

        $this->assertSame(1, $batch['failed_at']);
        $this->assertThrewEnvelope($batch['steps'][1], 'update_page_title');
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame([], $batch['rollback_errors']);
        $this->assertSame('Original SEO title', pp_get_seo_meta($page)['seo_title']);
        $this->assertSame('Landing', get_post($page)->post_title, 'the retitle is undone');
        $this->assertNoReflection($batch, 'batch envelope');
        $this->assertStringContainsString('threw: TypeError: ' . self::SENTINEL, $this->logText());
    }

    /** The batch stops at the throw: nothing after it runs. */
    public function testNoStepAfterTheThrowRuns(): void
    {
        $page = $this->seoPage();
        $this->throwAfterAction('update_seo_meta', new \RuntimeException(self::SENTINEL));

        $batch = pp_ai_execute_batch([
            $this->seoStep($page),
            ['type' => 'action', 'name' => 'update_page_title', 'params' => [
                'post_id' => $page, 'title' => 'Never written',
            ]],
        ]);

        $this->assertSame(0, $batch['failed_at'], 'a throw at the first step');
        $this->assertCount(1, $batch['steps'], 'and the second step never ran');
        $this->assertThrewEnvelope($batch['steps'][0], 'update_seo_meta');
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame([], $batch['rollback_errors']);
        $this->assertSame('Original SEO title', pp_get_seo_meta($page)['seo_title'], "the step's own write is undone");
        $this->assertSame('Landing', get_post($page)->post_title);
        $this->assertStringContainsString('batch step 0 (update_seo_meta) threw', $this->logText());
    }

    // ── Applies ─────────────────────────────────────────────────────────────

    /** The guard sits on the dispatch, so an apply step is covered exactly as an action is. */
    public function testAThrowingApplyStepIsGuardedAndRolledBack(): void
    {
        $page   = $this->seoPage();
        $before = pp_get_token_overrides();
        $this->throwAfterApply('update_design_token', new \RuntimeException(self::SENTINEL));

        $batch = pp_ai_execute_batch([
            $this->seoStep($page),
            ['type' => 'apply', 'name' => 'update_design_token', 'params' => [
                'token' => '--color-accent', 'value' => '#ff0000',
            ]],
        ]);

        $this->assertSame(1, $batch['failed_at']);
        $this->assertThrewEnvelope($batch['steps'][1], 'update_design_token');
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame([], $batch['rollback_errors']);
        $this->assertSame($before, pp_get_token_overrides(), "the apply's own write is undone");
        $this->assertSame('Original SEO title', pp_get_seo_meta($page)['seo_title']);
        $this->assertNoReflection($batch, 'batch envelope');
        $this->assertStringContainsString('batch step 1 (update_design_token) threw: RuntimeException', $this->logText());
    }

    // ── Writes the rollback cannot see (7A ruling Q1) ───────────────────────

    /**
     * create_page that makes its page and THEN throws: the rollback only learns of a page
     * from a succeeded result, so the page survives. The report must say so rather than
     * come back clean: one `failed` entry, fixed text, naming the step and the class.
     */
    public function testACreatingStepThatThrowsAfterItsWriteIsReportedAsPossiblySurviving(): void
    {
        $page = $this->seoPage();
        $before = array_keys($GLOBALS['_pp_test_store']['posts']);
        $this->throwAfterAction('create_page', new \RuntimeException(self::SENTINEL));

        $batch = pp_ai_execute_batch([
            $this->seoStep($page),
            ['type' => 'action', 'name' => 'create_page', 'params' => ['title' => 'Orphan candidate']],
        ]);

        $this->assertSame(1, $batch['failed_at']);
        $this->assertThrewEnvelope($batch['steps'][1], 'create_page');
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame('Original SEO title', pp_get_seo_meta($page)['seo_title'], 'step 0 is still undone');

        $this->assertNotSame(
            $before,
            array_keys($GLOBALS['_pp_test_store']['posts']),
            'premise: the page the throwing step made is still there'
        );
        $this->assertSame([
            'Step 2 stopped on an internal error and may already have made a page that the '
            . 'rollback cannot see, so it may still be on the site. Check for it before retrying.',
        ], $batch['rollback_errors'], 'so the channel is not empty');
        $this->assertSame([PP_ROLLBACK_ERROR_FAILED], $batch['rollback_error_kinds']);
        $this->assertNoReflection($batch, 'batch envelope');
    }

    /** The same for a redirect step, the third positively tracked class. */
    public function testARedirectStepThatThrowsAfterItsWriteIsReportedAsPossiblySurviving(): void
    {
        $this->throwAfterAction('create_redirect', new \RuntimeException(self::SENTINEL));

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_redirect', 'params' => ['from' => '/old', 'to' => '/new']],
        ]);

        $this->assertSame(0, $batch['failed_at']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertCount(1, $batch['rollback_errors']);
        $this->assertStringStartsWith('Step 1 stopped on an internal error and may already have made a redirect change', $batch['rollback_errors'][0]);
        $this->assertSame([PP_ROLLBACK_ERROR_FAILED], $batch['rollback_error_kinds']);
    }

    /**
     * import_media, the apply-side class. Its validation and import are replaced by a stand-in
     * that passes and throws, because what is pinned is the report, not the importer.
     */
    public function testAnImportMediaApplyThatThrowsIsReportedAsPossiblySurviving(): void
    {
        global $_pp_applies;
        $_pp_applies['import_media']['params']   = [];
        $_pp_applies['import_media']['validate'] = static fn (array $p) => true;
        $_pp_applies['import_media']['apply']    = static function (array $p) {
            throw new \RuntimeException(self::SENTINEL);
        };

        $batch = pp_ai_execute_batch([['type' => 'apply', 'name' => 'import_media', 'params' => []]]);

        $this->assertSame(0, $batch['failed_at']);
        $this->assertThrewEnvelope($batch['steps'][0], 'import_media');
        $this->assertCount(1, $batch['rollback_errors']);
        $this->assertStringStartsWith('Step 1 stopped on an internal error and may already have made a media attachment', $batch['rollback_errors'][0]);
        $this->assertSame([PP_ROLLBACK_ERROR_FAILED], $batch['rollback_error_kinds']);
    }

    /** remove_redirect is a redirect change too: a removal the rollback never learned of. */
    public function testARemoveRedirectStepThatThrowsIsReportedAsPossiblySurviving(): void
    {
        $this->throwAfterAction('remove_redirect', new \RuntimeException(self::SENTINEL));

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'remove_redirect', 'params' => ['from' => '/old']],
        ]);

        $this->assertSame(0, $batch['failed_at']);
        $this->assertCount(1, $batch['rollback_errors']);
        $this->assertStringStartsWith('Step 1 stopped on an internal error and may already have made a redirect change', $batch['rollback_errors'][0]);
    }

    /**
     * The class is the PAIR of type and name, mirroring the dispatcher: an apply that happens
     * to be named create_page, or an action named import_media, is not the tracked class.
     */
    public function testTheClassIsTheTypeAndNamePair(): void
    {
        global $_pp_applies, $_pp_actions;
        $_pp_applies['create_page'] = $_pp_applies['update_design_token'];
        $_pp_applies['create_page']['params']   = [];
        $_pp_applies['create_page']['validate'] = static fn (array $p) => true;
        $_pp_applies['create_page']['apply']    = static function (array $p) {
            throw new \RuntimeException(self::SENTINEL);
        };
        $_pp_actions['import_media'] = $_pp_actions['update_page_title'];
        $this->throwAfterAction('import_media', new \RuntimeException(self::SENTINEL));
        $page = $this->seoPage();

        $as_apply = pp_ai_execute_batch([['type' => 'apply', 'name' => 'create_page', 'params' => []]]);
        $as_action = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'import_media', 'params' => ['post_id' => $page, 'title' => 'X']],
        ]);

        $this->assertThrewEnvelope($as_apply['steps'][0], 'create_page');
        $this->assertSame([], $as_apply['rollback_errors'], 'an apply named create_page');
        $this->assertThrewEnvelope($as_action['steps'][0], 'import_media');
        $this->assertSame([], $as_action['rollback_errors'], 'an action named import_media');
    }

    /**
     * NOT for a step the snapshot covers: a throwing retitle, SEO write, menu write or token
     * apply is restored, and the channel stays empty (the earlier tests assert
     * `rollback_errors === []` for each). Nor for a create_page that was REFUSED rather than
     * thrown: a refusal made nothing.
     */
    public function testARefusedCreatingStepAddsNoEntry(): void
    {
        $page = $this->seoPage();

        $batch = pp_ai_execute_batch([
            $this->seoStep($page),
            ['type' => 'action', 'name' => 'create_page', 'params' => ['title' => '']],
        ]);

        $this->assertSame(1, $batch['failed_at'], 'premise: create_page was refused');
        $this->assertNotSame(PP_BATCH_STEP_THREW_CODE, $batch['steps'][1]['error_code'] ?? '');
        $this->assertSame([], $batch['rollback_errors']);
        $this->assertSame([], $batch['rollback_error_kinds']);
    }

    // ── The chat surface ────────────────────────────────────────────────────

    /**
     * THROUGH THE HANDLER THE CHAT CALLS (Section 14.1). It returns a normal batch envelope
     * (no exception escapes, so no 500), and no field of it carries the exception's text.
     *
     * NO MODEL NOTE. A throw is a site fault the model cannot repair by rewriting its
     * proposal, the same class as composition_conflict: the note is withheld, so the client
     * offers no "ask the model to repair" affordance against it (#704's rule).
     */
    public function testTheChatHandlerReturnsAFailedStepAndReflectsNothing(): void
    {
        $page = $this->seoPage();
        $this->throwAfterAction('update_page_title', new \RuntimeException(self::SENTINEL));

        $resp = _pp_ai_execute_batch_response([
            'steps' => json_encode([
                $this->seoStep($page),
                ['type' => 'action', 'name' => 'update_page_title', 'params' => [
                    'post_id' => $page, 'title' => 'Renamed by step 1',
                ]],
            ]),
        ]);

        $this->assertTrue($resp['ok'], 'the request succeeds and carries the batch');
        $batch = $resp['data'];
        $this->assertFalse($batch['ok']);
        $this->assertSame(1, $batch['failed_at']);
        $this->assertThrewEnvelope($batch['steps'][1], 'update_page_title');
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame('Original SEO title', pp_get_seo_meta($page)['seo_title']);

        $this->assertArrayNotHasKey('model_note', $batch, 'a site fault gets no note');
        $this->assertNoReflection($resp, 'chat response');
        $this->assertStringContainsString(self::SENTINEL, $this->logText());
    }

    // ── What the guard must survive itself ──────────────────────────────────

    /**
     * ONE THROW, ONE LOG LINE. The exception message and the step name both pass through the
     * log-line cleaner, so a CR/LF in either cannot write a second, forged line.
     */
    public function testAThrowCannotForgeASecondLogLine(): void
    {
        global $_pp_actions;
        $forged = "evil\nPromptingPress: forged by the name";
        $_pp_actions[$forged] = $_pp_actions['update_page_title'];
        $this->throwAfterAction($forged, new \RuntimeException(
            "bad value\r\nPromptingPress: forged by the message\u{2028}and a separator\u{0085}NEL\u{2029}PS"
        ));
        $page = $this->seoPage();

        $batch = pp_ai_execute_batch([
            $this->seoStep($page),
            ['type' => 'action', 'name' => $forged, 'params' => ['post_id' => $page, 'title' => 'X']],
        ]);

        $this->assertSame(1, $batch['failed_at']);
        $this->assertTrue($batch['rolled_back']);
        $lines = array_values(array_filter(explode("\n", $this->logText()), static fn ($l) => trim($l) !== ''));
        $this->assertCount(1, $lines, 'exactly one log line');
        $this->assertStringContainsString('(evil?PromptingPress: forged by the name) threw', $lines[0]);
        $this->assertStringContainsString('bad value??PromptingPress: forged by the message?and a separator?NEL?PS', $lines[0]);
    }

    /**
     * A long message is cut at 1000 bytes ON A CHARACTER BOUNDARY: the log line stays
     * bounded and stays valid UTF-8 even when a multibyte character straddles the cut.
     */
    public function testALongExceptionMessageIsCutOnACharacterBoundary(): void
    {
        // 999 ASCII bytes, then a 3-byte character across byte 1000, then more.
        $message = str_repeat('a', 999) . "\u{20AC}" . str_repeat('b', 500);
        $this->throwAfterAction('update_page_title', new \RuntimeException($message));
        $page = $this->seoPage();

        pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $page, 'title' => 'X']],
        ]);

        $this->assertSame(1, preg_match('/threw: RuntimeException: (a+)(\S*) at /', $this->logText(), $m));
        $this->assertSame(999, strlen($m[1]), 'cut before the character that would cross byte 1000');
        $this->assertSame('', $m[2], 'and nothing after it');
        $this->assertTrue(mb_check_encoding($this->logText(), 'UTF-8'));
    }

    /**
     * The executor is public, so a direct caller can send a string-keyed list. The guard's
     * own handler must not throw on a string key: that would be a second, uncaught throw
     * before the rollback.
     */
    public function testTheGuardHoldsForAStringKeyedList(): void
    {
        $page = $this->seoPage();
        $this->throwAfterAction('update_page_title', new \RuntimeException(self::SENTINEL));

        $batch = pp_ai_execute_batch([
            'first'  => $this->seoStep($page),
            'second' => ['type' => 'action', 'name' => 'update_page_title', 'params' => [
                'post_id' => $page, 'title' => 'Renamed',
            ]],
        ]);

        $this->assertSame('second', $batch['failed_at']);
        $this->assertThrewEnvelope($batch['steps'][1], 'update_page_title');
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame([], $batch['rollback_errors']);
        $this->assertSame('Original SEO title', pp_get_seo_meta($page)['seo_title']);
        $this->assertSame('Landing', get_post($page)->post_title);
        $this->assertStringContainsString('batch step second (update_page_title) threw: RuntimeException', $this->logText());
    }

    /**
     * An output buffer the throwing step opened is discarded, so its half-rendered bytes
     * cannot end up in front of the JSON response.
     */
    public function testAnOutputBufferTheThrowingStepOpenedIsDiscarded(): void
    {
        global $_pp_actions;
        $page = $this->seoPage();
        $_pp_actions['update_page_title']['execute'] = static function (array $params) {
            ob_start();
            echo 'HALF-RENDERED';
            throw new \RuntimeException(self::SENTINEL);
        };
        $level = ob_get_level();

        ob_start();
        $batch = pp_ai_execute_batch([
            $this->seoStep($page),
            ['type' => 'action', 'name' => 'update_page_title', 'params' => ['post_id' => $page, 'title' => 'X']],
        ]);
        $leaked = (string) ob_get_clean();

        $this->assertSame($level, ob_get_level(), 'no buffer left open');
        $this->assertSame('', $leaked, 'and nothing it held reached the output');
        $this->assertSame(1, $batch['failed_at']);
        $this->assertTrue($batch['rolled_back']);
    }

    // ── The post-apply validation arm (lib/actions.php, the addendum's site) ─

    /**
     * The validator itself throwing is reported on the step's validation report with a
     * fixed message; the details go to the log. The step stays applied: a validation crash
     * must never mask a successful apply, and nothing here rolls back.
     */
    public function testAPostApplyValidationThrowIsReportedWithAFixedMessage(): void
    {
        $page = $this->seoPage();
        pp_update_composition($page, [['component' => 'hero', 'props' => ['title' => 'Hello']]]);
        // Armed by the step itself, AFTER its real write: every action's validation reads
        // the upload dir too, so arming it up front would make the step throw instead of
        // the validator that runs after it.
        global $_pp_actions;
        $real = $_pp_actions['update_page_title']['execute'];
        $_pp_actions['update_page_title']['execute'] = static function (array $params) use ($real) {
            $result = call_user_func($real, $params);
            $GLOBALS['_pp_test_store']['upload_dir_throws'] = self::SENTINEL;
            return $result;
        };

        $resp = _pp_ai_execute_batch_response(['steps' => json_encode([
            ['type' => 'action', 'name' => 'update_page_title', 'params' => [
                'post_id' => $page, 'title' => 'Retitled',
            ]],
        ])]);
        unset($GLOBALS['_pp_test_store']['upload_dir_throws']);

        $this->assertTrue($resp['ok']);
        $batch = $resp['data'];
        $this->assertTrue($batch['ok'], 'the step itself succeeded');
        $this->assertSame('Retitled', get_post($page)->post_title, 'and stayed applied');
        $this->assertSame([[
            'check'   => 'validation_error',
            'message' => PP_BATCH_VALIDATION_THREW_MESSAGE,
        ]], $batch['steps'][0]['validation']['errors']);
        $this->assertFalse($batch['steps'][0]['validation']['ok']);

        $this->assertNoReflection($resp, 'chat response');
        $this->assertStringContainsString(
            'batch step 0 post-apply validation threw: RuntimeException: ' . self::SENTINEL,
            $this->logText()
        );
    }
}
