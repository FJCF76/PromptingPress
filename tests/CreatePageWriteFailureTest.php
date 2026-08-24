<?php
/**
 * tests/CreatePageWriteFailureTest.php — create_page never reports success over a
 * composition it did not store (#719).
 *
 * THE FAILURE THIS CLOSES. `create_page`'s execute discarded the return value of its
 * composition write. `pp_update_composition()` is a non-validating writer, but it still
 * REFUSES when it cannot take the per-post advisory lock: GET_LOCK returns '0' (another
 * writer holds it) or NULL (DB error / unhealthy server), and `_pp_with_advisory_lock()`
 * logs, skips the write to avoid a lost update, and hands back a
 * `composition_lock_failed` WP_Error. Nobody looked. So the page row existed, the
 * composition did not, and the envelope read:
 *
 *     { "ok": true, "target": { "post_id": 231 }, "findings": [] }
 *
 * Since #687 that `findings: []` is not silence — AI_CONTEXT.md and
 * ai-instructions/operating-loop.md define the empty list as the positive confirmation
 * that the write did what you asked. A confident all-clear over a page that lost its
 * content is worse than the bare `ok: true` it replaced.
 *
 * THE SHAPE (#719). The seven composition-mutating siblings all convert this WP_Error
 * into a rejection; create_page was the only one that did not. It now does — AND it
 * deletes the page it just created, because a bare rejection would strand one:
 *
 *      pp_create_page() ──► page row exists
 *              │
 *      pp_update_composition()
 *              │
 *       ┌──────┴───────┐
 *    true            WP_Error (lock refused; nothing written)
 *      │                │
 *   ok:true          wp_delete_post($id, true)
 *   findings:[]         │
 *                ┌──────┴───────┐
 *             deleted        refused (falsy return)
 *                │                │
 *      ok:false, target:[]   ok:false, message NAMES the surviving post
 *      "...nothing was left behind"      "...could NOT be removed"
 *
 * WHY DELETE RATHER THAN REPORT THE ORPHAN. `_pp_action_error()` renders `target => []`,
 * and `_pp_ai_execute_error_payload()` (lib/ai-chat.php) collapses every failure except
 * `composition_conflict` to its message string — so a surviving page's id has nowhere
 * structural to go. Deleting makes the empty target TRUE instead of lossy, and mirrors
 * `_pp_restore_batch_snapshot()`'s treatment of a create_page step on exactly the same
 * rationale: the page did not exist before the call, so a refusal should not leave it
 * existing after.
 *
 * REACHING THE BRANCH. The Brain Monkey suite runs with no real $wpdb, so
 * `_pp_with_advisory_lock()` degrades to running its mutator directly and the refusal is
 * unreachable. Injecting a scripted $wpdb (the seam tests/TokenLockTest.php established
 * for #97/#113) is what makes this a real failure-path test rather than a mocked
 * tautology: the production code path is entered exactly as written, and only GET_LOCK's
 * answer is scripted.
 *
 * CAS IS NOT IN THIS FILE, deliberately. `pp_update_composition()`'s other refusal is a
 * compare-and-swap mismatch, and create_page cannot produce one: it calls the writer with
 * two arguments, so `$expected_version` is null and the CAS check is skipped. That is
 * asserted below rather than assumed, so the day create_page starts threading a baseline
 * the missing coverage is visible.
 */

use PHPUnit\Framework\TestCase;

/**
 * Scripted $wpdb for the composition lock path — BOTH directions.
 *
 * Two stubs already exist and neither covers both, which is why this one is local:
 *
 *   - tests/bootstrap.php's `wpdb` (installed by tests/FrontPageSafeguardTest.php:148 to
 *     force exactly this refusal) declares no `query()` method and no `$postmeta`. It can
 *     therefore only ever model a REFUSED lock: the moment GET_LOCK succeeds, the writer
 *     reads `{$wpdb->postmeta}` and then issues RELEASE_LOCK through `query()`, which does
 *     not exist. Perfect for half this file, unusable for the other half.
 *   - tests/TokenLockTest.php's PP_Mock_Wpdb answers every `meta_value` SELECT with ONE
 *     scripted value, conflating the in-lock version read (`_pp_composition_version`) with
 *     the in-lock prior-composition read (`_pp_composition`). It also lives in namespace
 *     PromptingPress\Tests inside a test file, so PSR-4 cannot autoload it from here —
 *     reusing it across files is load-order-dependent.
 *
 * This one discriminates on the meta_key the prepared statement carries, so an acquired
 * lock behaves like a real one and the success path stays honest.
 */
final class PP_CreatePage_Lock_Wpdb
{
    /** @var mixed GET_LOCK return: '1' acquired, '0' timed out, null DB error. */
    public $get_lock_return = '1';
    public string $postmeta = 'wp_postmeta';
    /**
     * @var mixed Scripted answer for the in-lock `_pp_composition_version` read. null models
     * an absent row (version 0). Set it to script a page the writer finds already at some
     * version, which is what a CAS baseline would be compared against.
     */
    public $version_return = null;
    /** @var string[] Every SQL string this handle was asked to run, in order. */
    public array $calls = [];

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $a) {
            $query = preg_replace_callback('/%[sd]/', function ($m) use ($a) {
                return $m[0] === '%d' ? (string) (int) $a : "'" . $a . "'";
            }, $query, 1);
        }
        return $query;
    }

    public function get_var(string $sql)
    {
        $this->calls[] = $sql;
        if (strpos($sql, 'GET_LOCK') !== false) {
            return $this->get_lock_return;
        }
        // The two in-lock authoritative reads (#113), told apart by meta_key so the
        // version read cannot be answered with the composition JSON or vice versa.
        if (strpos($sql, "'_pp_composition_version'") !== false) {
            return $this->version_return; // null = absent row → version 0 → this write becomes v1
        }
        if (strpos($sql, "'_pp_composition'") !== false) {
            return null; // no prior composition → nothing to push onto the history ring
        }
        return null;
    }

    public function query(string $sql)
    {
        $this->calls[] = $sql;
        return 1;
    }

    /** @return string[] only the GET_LOCK / RELEASE_LOCK calls, in order. */
    public function lockCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            fn ($c) => strpos($c, 'GET_LOCK') !== false || strpos($c, 'RELEASE_LOCK') !== false
        ));
    }
}

final class CreatePageWriteFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
        unset($GLOBALS['wpdb'], $GLOBALS['_pp_test_undeletable_posts']);
    }

    protected function tearDown(): void
    {
        // MANDATORY, not hygiene: a leaked $wpdb makes every later test in the process
        // take the DB branch of _pp_with_advisory_lock() and fail for reasons that have
        // nothing to do with what they assert.
        unset($GLOBALS['wpdb'], $GLOBALS['_pp_test_undeletable_posts']);
        parent::tearDown();
    }

    /** A valid single-band composition — nothing here is what gets refused. */
    private static function composition(): array
    {
        return [[
            'component' => 'hero',
            'props'     => ['id' => 'h1', 'title' => 'Stored or not stored'],
        ]];
    }

    /** Installs a scripted $wpdb whose GET_LOCK answers $lock. */
    private function scriptLock($lock): PP_CreatePage_Lock_Wpdb
    {
        $wpdb = new PP_CreatePage_Lock_Wpdb();
        $wpdb->get_lock_return = $lock;
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    /** Titles are unique per test, so "did a page survive?" is answerable by title. */
    private function pagesTitled(string $title): array
    {
        $found = [];
        foreach ($GLOBALS['_pp_test_store']['posts'] as $id => $post) {
            if (($post['post_title'] ?? '') === $title) {
                $found[] = $id;
            }
        }
        return $found;
    }

    // ── 1. The refusal ──────────────────────────────────────────────────────────

    /**
     * THE FILED BUG. Lock busy (GET_LOCK '0') → the composition was never stored, so the
     * call must not report success.
     */
    public function testALockTimeoutRefusesInsteadOfReportingSuccess(): void
    {
        $this->scriptLock('0');

        $result = pp_execute_action('create_page', [
            'title'       => 'Lock timeout page',
            'composition' => self::composition(),
        ]);

        // The machine contract is error_code — _pp_action_error()'s own comment names it as
        // the thing callers key on. The writer's prose belongs to lib/wp.php and is asserted
        // there; only the clauses THIS change owns are asserted from here.
        $this->assertFalse($result['ok'], 'a composition that was not stored is not a success');
        $this->assertSame('composition_lock_failed', $result['error_code']);
    }

    /**
     * The #687 half. `findings: []` is documented as the confirmation that the write did
     * what you asked, so the one envelope that must never carry it is the one whose write
     * did nothing. Rejections carry no `findings` key at all — pp_execute_action() attaches
     * it only on ok:true — and this pins that the refusal keeps that shape.
     */
    public function testTheRefusedEnvelopeCarriesNoCleanFindingsList(): void
    {
        $this->scriptLock('0');

        $result = pp_execute_action('create_page', [
            'title'       => 'No clean bill of health',
            'composition' => self::composition(),
        ]);

        $this->assertArrayNotHasKey('findings', $result, 'an empty findings list here would assert the page is clean');
        $this->assertArrayNotHasKey('composition_version', $result, 'and no CAS baseline is minted for a write that did not land');
        $this->assertSame([], $result['target'], 'the rejection shape is the siblings\' shape');
        $this->assertNull($result['index'], 'no band owns a writer-level refusal (#642)');
    }

    /**
     * GET_LOCK NULL is a DIFFERENT cause from '0' — an unhealthy DB / killed connection,
     * not contention — and the writer deliberately collapses both to one refusal because
     * neither can be safely serialized. Asserted as the contract it is, not implied to
     * mean the same thing.
     */
    public function testADbErrorFromGetLockRefusesTheSameWay(): void
    {
        $this->scriptLock(null);

        $result = pp_execute_action('create_page', [
            'title'       => 'DB error page',
            'composition' => self::composition(),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('composition_lock_failed', $result['error_code']);
    }

    // ── 2. The page does not survive the refusal ────────────────────────────────

    /**
     * The empty `target` on the rejection is TRUE, not lossy: the page created moments
     * earlier is gone, so there is no id for the caller to have lost. Asserted by title
     * rather than by counting posts — a count would be hostage to any fixture or hook
     * that creates a post of its own.
     */
    public function testTheRefusalLeavesNoPageBehind(): void
    {
        $this->scriptLock('0');
        $title = 'Rolled back page ' . __FUNCTION__;

        $result = pp_execute_action('create_page', [
            'title'       => $title,
            'composition' => self::composition(),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $this->pagesTitled($title), 'the page this call created must not outlive its refusal');
        $this->assertStringContainsString('no page was left behind', $result['error']);
    }

    /**
     * The refusal releases the ROUTE, not just the row (#134). The slug is the one input
     * where a stranded page would corrupt the retry rather than merely duplicate it: a
     * surviving first attempt still holds `retry-slug`, so WordPress would hand the retry
     * `retry-slug-2` and a DIFFERENT canonical URL while still reporting ok:true.
     */
    public function testTheRefusalReleasesTheSlugSoARetryKeepsItsRoute(): void
    {
        $this->scriptLock('0');
        $failed = pp_execute_action('create_page', [
            'title'       => 'Routed page',
            'slug'        => 'retry-slug',
            'composition' => self::composition(),
        ]);
        $this->assertFalse($failed['ok']);

        $this->scriptLock('1');
        $ok = pp_execute_action('create_page', [
            'title'       => 'Routed page',
            'slug'        => 'retry-slug',
            'composition' => self::composition(),
        ]);

        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertSame(
            'retry-slug',
            get_post($ok['target']['post_id'])->post_name,
            'a refused attempt must not leave its slug reserved, or the retry silently changes the page URL'
        );
    }

    /**
     * Retry after a transient lock failure is the whole point of deleting: the second
     * call is a clean create, not a duplicate stacked beside an empty first attempt.
     */
    public function testRetryingAfterALockFailureCreatesExactlyOnePage(): void
    {
        $title = 'Retry page';

        $this->scriptLock('0');
        $failed = pp_execute_action('create_page', ['title' => $title, 'composition' => self::composition()]);
        $this->assertFalse($failed['ok']);

        // Contention clears; the caller repeats the same call.
        $this->scriptLock('1');
        $ok = pp_execute_action('create_page', ['title' => $title, 'composition' => self::composition()]);

        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertSame([$ok['target']['post_id']], $this->pagesTitled($title), 'exactly one page carries this title');
        $this->assertCount(1, pp_get_composition($ok['target']['post_id']), 'and it holds the composition that was asked for');
    }

    /**
     * CLEANUP CAN ITSELF FAIL — the unhealthy DB that lost the lock is the likely cause —
     * and wp_delete_post() reports that as a FALSY return, never a WP_Error. When it does,
     * the page IS still there, so the message names it: the chat surface collapses a
     * failure to its message string (lib/ai-chat.php), which makes the message the only
     * place the id can reach that caller.
     */
    public function testWhenCleanupFailsTheMessageNamesTheSurvivingEmptyPage(): void
    {
        $this->scriptLock('0');
        $title = 'Undeletable page';
        // Refuse the delete of whatever id this call is about to mint.
        $GLOBALS['_pp_test_undeletable_posts'] = [$GLOBALS['_pp_test_store']['next_id'] => true];

        $result = pp_execute_action('create_page', ['title' => $title, 'composition' => self::composition()]);
        $survivors = $this->pagesTitled($title);

        $this->assertFalse($result['ok'], 'still a refusal — the composition was still not stored');
        $this->assertCount(1, $survivors, 'precondition: the delete really was refused');
        $this->assertStringContainsString('post ' . $survivors[0], $result['error'], 'the orphan must be named, not left for the caller to hunt');
        $this->assertStringContainsString('is still there', $result['error']);
        $this->assertStringContainsString('stores no composition', $result['error']);
        $this->assertSame([], pp_get_composition($survivors[0]), 'and it is empty, exactly as the message says');
    }

    /**
     * THE DELETE IS GATED ON THE PAGE STILL BEING PRISTINE. The refusal proves only that
     * THIS call stored nothing — `wp_insert_post()` fires `save_post`, and a listener could
     * have stored a composition of its own. Deleting that would destroy content this action
     * never wrote, so a non-empty page is left alone and the message says so rather than
     * claiming an emptiness nobody checked.
     */
    public function testAPageSomethingElseWroteToIsLeftAloneRatherThanDeleted(): void
    {
        $title = 'Written by someone else';
        $this->scriptLock('0');
        // Stand in for a save_post listener: seed the composition the instant the row exists,
        // before create_page's own (about to be refused) write.
        $foreign = [['component' => 'hero', 'props' => ['id' => 'foreign', 'title' => 'Not ours']]];
        $predicted = $GLOBALS['_pp_test_store']['next_id'];
        $GLOBALS['_pp_test_store']['post_meta'][$predicted]['_pp_composition'] =
            wp_json_encode($foreign, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = pp_execute_action('create_page', ['title' => $title, 'composition' => self::composition()]);
        $survivors = $this->pagesTitled($title);

        $this->assertFalse($result['ok'], 'the refusal stands — our composition still did not store');
        $this->assertCount(1, $survivors, 'the page must NOT be deleted: it is not ours to destroy');
        $this->assertSame($predicted, $survivors[0], 'precondition: the seeded id is the one create_page minted');
        $this->assertStringContainsString('is NOT empty', $result['error']);
        $this->assertStringContainsString('something else wrote to it', $result['error']);
        $this->assertCount(1, pp_get_composition($survivors[0]), 'the foreign composition survives intact');
        $this->assertSame('foreign', pp_get_composition($survivors[0])[0]['props']['id']);
    }

    // ── 3. The success path is untouched ────────────────────────────────────────

    /**
     * The same call through a HEALTHY lock still succeeds, still stores, and still carries
     * the #687 report. This is the half that proves the new guard is a guard and not a
     * behaviour change: nothing about an accepted write moved.
     */
    public function testAnAcquiredLockStillStoresAndStillReportsFindings(): void
    {
        $wpdb = $this->scriptLock('1');

        $result = pp_execute_action('create_page', [
            'title'       => 'Healthy lock page',
            'composition' => self::composition(),
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([], $result['findings'], 'a clean composition still reports an empty list');
        $this->assertSame(1, $result['composition_version']);
        $this->assertCount(1, pp_get_composition($result['target']['post_id']));

        $locks = $wpdb->lockCalls();
        $this->assertStringContainsString('GET_LOCK', $locks[0] ?? '', 'the write really did go through the lock path');
        $this->assertStringContainsString('RELEASE_LOCK', $locks[1] ?? '', 'and released it');
    }

    /**
     * A create_page carrying NO composition never calls the writer, so a hostile lock
     * cannot refuse it. This is the lower boundary of the new guard — `!empty()` gates it —
     * and it matters because an empty page created for later population (#358) must stay
     * creatable while the DB is contended.
     */
    public function testAPageWithNoCompositionIsUnaffectedByAHostileLock(): void
    {
        $wpdb = $this->scriptLock(null);

        $result = pp_execute_action('create_page', ['title' => 'Empty by design']);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([], $result['findings']);
        $this->assertSame([], $wpdb->lockCalls(), 'no composition write means no composition lock');
    }

    /**
     * The OTHER spelling of "no composition", and it takes a different guard: validate() uses
     * `isset($params['composition'])` while execute uses `!empty()`, so an explicit `[]` — the
     * documented default — reaches execute as a present-but-empty param and must skip the
     * writer exactly like an absent one.
     */
    public function testAnExplicitlyEmptyCompositionAlsoNeverReachesTheWriter(): void
    {
        $wpdb = $this->scriptLock(null);

        $result = pp_execute_action('create_page', ['title' => 'Empty array', 'composition' => []]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([], $result['findings']);
        $this->assertSame([], $wpdb->lockCalls(), 'an empty composition array is still no composition write');
    }

    /**
     * THE BATCH INTERACTION, pinned because the execute block's comment leans on it.
     * pp_ai_execute_batch() records a created page for rollback only when the step returned
     * ok:true (`created_posts`), so a create_page step that is REJECTED after creating its
     * page is invisible to _pp_restore_batch_snapshot(). Self-deleting inside the action is
     * what keeps `rolled_back: true` honest — without it a batch would report a clean
     * rollback over a surviving empty page, and the rollback code (owned by a separate
     * issue) would need no bug of its own to produce that lie.
     */
    public function testABatchWhoseCreatePageStepIsRefusedLeavesNoPageBehind(): void
    {
        $this->scriptLock('0');
        $title = 'Batch page that never was';

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'create_page', 'params' => [
                'title'       => $title,
                'composition' => self::composition(),
            ]],
        ]);

        $this->assertFalse($batch['ok'], 'the batch fails because its only step was refused');
        $this->assertTrue($batch['rolled_back']);
        $this->assertFalse($batch['steps'][0]['ok']);
        $this->assertSame('composition_lock_failed', $batch['steps'][0]['error_code']);
        $this->assertSame([], $this->pagesTitled($title), 'and `rolled_back: true` is TRUE — no page outlived it');
    }

    /**
     * BOUNDARY, recorded rather than assumed: the writer's OTHER refusal
     * (`composition_conflict`, the compare-and-swap) is unreachable from create_page,
     * which is why this file tests only the lock. create_page declares no
     * `expected_version` param and passes none, so `$expected_version` is null and the CAS
     * check is skipped entirely.
     *
     * Asserted through BEHAVIOUR, not through the source text of the call: the mock is
     * scripted so the writer's in-lock version read finds the page already at version 7 —
     * precisely the state a CAS would compare a baseline against. A create_page that
     * threaded one would conflict here. This one writes straight through to version 8, and
     * the day that stops being true this test reddens for a real reason rather than
     * because a line was rewrapped.
     */
    public function testCreatePageThreadsNoCasBaselineSoTheConflictBranchCannotFire(): void
    {
        $wpdb = $this->scriptLock('1');
        $wpdb->version_return = '7';

        $result = pp_execute_action('create_page', [
            'title'       => 'No baseline threaded',
            'composition' => self::composition(),
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertNotSame('composition_conflict', $result['error_code'] ?? '');
        $this->assertSame(8, $result['composition_version'], 'the write chained off the DB version with no baseline compared');

        // The registered action's own contract, for the same reason: no baseline param exists
        // to thread, and create_page is deliberately outside the CAS set.
        $this->assertArrayNotHasKey('expected_version', pp_get_action('create_page')['params']);
        $this->assertFalse(pp_action_is_composition_mutating('create_page'));
    }
}
