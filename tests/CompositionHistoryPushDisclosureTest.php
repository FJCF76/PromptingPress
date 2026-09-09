<?php
/**
 * tests/CompositionHistoryPushDisclosureTest.php — an accepted composition write whose
 * history push was skipped says so in its own envelope (#821).
 *
 * THE GAP THIS PINS. pp_update_composition() (lib/wp.php) pushes the PRIOR composition onto
 * the bounded per-post history ring and then overwrites `_pp_composition`. When the ring
 * encode fails, the push is skipped — and the overwrite happens anyway, so the state the
 * write replaced is gone with nothing to restore from. The ring half of that was already
 * guarded before this change (the previous entries are kept rather than clobbered with a
 * false); what was missing is that NOBODY WAS TOLD. The only trace was a line in the server
 * error log, while the envelope returned `ok: true` carrying a `findings` report that
 * described the stored composition — which is perfectly healthy — and said nothing about
 * the undo point that had just been lost.
 *
 * THE RULING (Fernando, 2026-09-01) is the narrow first step:
 *
 *   guard the encode          the ring keeps its previous entries. FAIL CLOSED for the ring.
 *   let the write proceed     no new refusal on a public surface. NOT fail-closed for the
 *                             composition — that is the deliberate narrowness.
 *   disclose it               the accepted write carries a findings entry saying it has no
 *                             undo point. No silent skip.
 *
 * and the second step — blocking on `update_post_meta()`'s ambiguous false, which also means
 * "the value did not change" — is deferred until something can tell those apart.
 *
 *     pp_update_composition($post_id, $new)
 *         │
 *         ├─ forget the post's skip slot ─────────────► this write owns it from here
 *         │
 *         ├─ prior exists? ──no──► nothing to push (a first write, a seed)
 *         │        │
 *         │       yes
 *         │        ▼
 *         │   build entry, rebuild ring, encode it
 *         │        │
 *         │        ├─ encode ok ────► update_post_meta(ring)         ring grows
 *         │        │
 *         │        └─ encode FALSE ─► ring untouched                 ring KEPT
 *         │                           error_log(...)                 ops breadcrumb
 *         │                           record the skip ───────┐
 *         │                                                  │
 *         └─ write composition + hash + version (ALWAYS)     │
 *                                                            │
 *     pp_execute_action() / the run-scoped rollback ◄─────────┘  drain + prepend
 *         └─ findings: [ history_not_recorded, ...the composition report ]
 *
 * HOW A FAILING ENCODE IS REACHED HONESTLY, because the fixture is the load-bearing half of
 * this file. wp_json_encode() does NOT return false for the corruption class the ring was
 * built around: invalid UTF-8 is coerced by _wp_json_sanity_check() and re-encoded, and
 * since #818 raw payloads are stored base64 anyway. JSON_ERROR_DEPTH is the class that DOES
 * reach false — verified against the installed WP 7.0 core (wp-includes/functions.php:
 * json_encode() fails, _wp_json_sanity_check() throws "Reached depth limit", wp_json_encode()
 * returns false). Wrapping a prior composition inside a ring ENTRY inside the RING nests it
 * two levels deeper than it sat when json_decode() accepted it, so there is a narrow band of
 * depths where the prior stores and decodes perfectly and the ring encode of it does not.
 *
 * THE DEPTH IS PROBED, NOT HARDCODED. A literal 508 would be a number that happens to be
 * true on one PHP build, and the first release that shifted json's depth accounting would
 * turn this file green over a fixture that no longer triggers anything. depthThatBreaksTheRingEncode()
 * finds the band at run time and fails loudly if the band has moved, which is the report we
 * would actually want.
 *
 * tests/bootstrap.php stubs wp_json_encode() as a bare json_encode(), which returns false on
 * depth exactly as the real function does — so unlike the invalid-UTF-8 class (where the stub
 * and production diverge, see CompositionHistoryRawPreservationTest), this trigger behaves
 * the same in both. That is why it is the trigger chosen here.
 */

use PHPUnit\Framework\TestCase;

class CompositionHistoryPushDisclosureTest extends TestCase
{
    /** The finding type the ruling's disclosure ships as. */
    private const NOTICE = 'history_not_recorded';

    /**
     * Every page this test created, so tearDown() can drain the skip register.
     *
     * THE REGISTER OUTLIVES THE TEST, and nothing else in the harness does. PHPUnit runs
     * the whole suite in ONE process, so the `static` inside _pp_history_push_skip_state()
     * survives from test to test — while setUp() resets `next_id` to 100, so POST IDS ARE
     * REUSED. Two tests here deliberately leave an undrained slot standing (the ones that
     * prove an undrained notice does not leak), and the production clear-on-every-write
     * invariant absorbs that today because every assertion in this file follows a write to
     * the page it asks about. It stops absorbing it the moment someone asserts the ABSENCE
     * of a notice on a path that does not write first — a create_page envelope on a
     * recycled id, say — and that failure would be order-dependent, which is the worst kind
     * to debug. Draining here keeps the hazard from ever being inherited.
     *
     * @var int[]
     */
    private array $pages = [];

    /** Creates a page and registers it for the tearDown drain. */
    private function page(string $title): int
    {
        $post_id       = pp_create_page($title, 'draft');
        $this->pages[] = $post_id;
        return $post_id;
    }

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
        ];
        unset($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        foreach ($this->pages as $post_id) {
            _pp_take_history_push_skipped($post_id);
        }
        $this->pages = [];
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    private function shallowBands(string $title = 'Shallow'): array
    {
        return [['component' => 'hero', 'props' => ['id' => 'band-1', 'title' => $title]]];
    }

    /**
     * A composition whose single band carries a prop value nested $depth levels deep.
     *
     * Written through pp_update_composition() rather than raw meta on purpose: the prior
     * state has to arrive the way a real prior arrives, with the version marker, the content
     * hash and the ring all consistent, or the write under test would be pushing against a
     * fixture rather than against a page. The writer does not validate (the ACTION layer
     * does), so a band carrying an undeclared prop reaches storage exactly as a page that
     * predates a schema change would.
     */
    private function deepBands(int $depth): array
    {
        $value = 'leaf';
        for ($i = 0; $i < $depth; $i++) {
            $value = [$value];
        }
        return [['component' => 'hero', 'props' => ['id' => 'deep-1', 'title' => 'Deep', 'deep' => $value]]];
    }

    /**
     * The nesting depth at which a prior composition still STORES and DECODES cleanly but
     * the ring encode of an entry wrapping it returns false.
     *
     * Mirrors the three encodes the real path performs, in order, so the band it finds is
     * the band pp_update_composition() actually walks into:
     *
     *   1. the composition write itself must succeed  (or the fixture never gets stored)
     *   2. json_decode() of those bytes must succeed  (or the entry is filed as raw bytes,
     *                                                  base64'd, and the ring encodes fine)
     *   3. the ring encode must fail                  (the branch under test)
     */
    private function depthThatBreaksTheRingEncode(): int
    {
        for ($depth = 480; $depth <= 530; $depth++) {
            $composition = $this->deepBands($depth);

            $stored = wp_json_encode($composition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($stored === false) {
                continue;
            }
            $decoded = json_decode($stored, true);
            if (!is_array($decoded)) {
                continue;
            }
            $ring = wp_json_encode(
                [['timestamp' => 1, 'version' => 1, 'hash' => 'h', 'composition' => $decoded]],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($ring === false) {
                return $depth;
            }
        }

        self::fail(
            'No nesting depth in 480..530 both stores/decodes cleanly and breaks the ring encode. '
            . "json's depth accounting has moved; this file's trigger needs revisiting before "
            . 'anything here can be trusted as coverage of the skipped-push branch.'
        );
    }

    /**
     * A page whose CURRENT stored composition is the deep one — so the NEXT write to it is
     * the write whose history push cannot be encoded.
     */
    private function pageWhoseNextWriteCannotBeRecorded(): int
    {
        $post_id = $this->page('No undo point');
        // A real earlier state, so the ring holds a genuine snapshot to be preserved (or
        // lost). "The previous entries were kept" only means something if there are some.
        $this->assertTrue(
            pp_update_composition($post_id, $this->shallowBands('Original')),
            'fixture: the baseline write must land'
        );
        $this->assertTrue(
            pp_update_composition($post_id, $this->deepBands($this->depthThatBreaksTheRingEncode())),
            'fixture: the deep prior must land'
        );
        // THE FIXTURE ASSERTS ITSELF, because eight tests rest on it. The probe validates
        // json's depth accounting, not this writer's behaviour — so if pp_update_composition()
        // ever gains a guard on its own encode, or the stored ring form gains a wrapper
        // level, the deep state silently stops landing and every test in this file fails as
        // "expected 1 disclosure, got 0", pointing at the disclosure code for a fault in the
        // setup. One assertion here turns eight misleading failures into one honest one.
        $stored = pp_get_composition($post_id);
        $this->assertArrayHasKey(
            'deep',
            $stored[0]['props'] ?? [],
            'fixture: the deep prior must really be in storage, or nothing below is testing what it says'
        );
        return $post_id;
    }

    /** @param array[] $findings */
    private function noticesIn(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (array $f): bool => ($f['type'] ?? '') === self::NOTICE
        ));
    }

    // ── 1. The ring half: already guarded, and it must stay that way ─────────

    /**
     * THE CLOBBER GUARD, PINNED. Nothing in the suite covered the `else` arm before this
     * file, so the guard that keeps ten entries alive was one careless edit from being
     * removed with everything still green. Without it, `wp_slash(false)` is false, the meta
     * write stores an empty value, and the ring reads back as `[]` — every slot gone, on
     * the very write whose job was to preserve one.
     */
    public function testAFailedRingEncodeLeavesThePreviousEntriesByteIdentical(): void
    {
        $post_id = $this->pageWhoseNextWriteCannotBeRecorded();

        $ring_before = pp_get_composition_history($post_id);
        $this->assertNotSame([], $ring_before, 'premise: there is a ring to lose');
        $raw_before = get_post_meta($post_id, '_pp_composition_history', true);

        $this->assertTrue(
            pp_update_composition($post_id, $this->shallowBands('Repaired')),
            'the composition write itself still proceeds — the ruling is guard-and-disclose, not block'
        );

        $this->assertSame(
            $raw_before,
            get_post_meta($post_id, '_pp_composition_history', true),
            'the stored ring row must be byte-identical: kept, not rebuilt and not wiped'
        );
        $this->assertSame($ring_before, pp_get_composition_history($post_id), 'and it still reads back the same');
        // The write really did land, so the loss is real: the page now holds the new
        // composition and the ring has no entry for the state it replaced.
        $this->assertSame('Repaired', pp_get_composition($post_id)[0]['props']['title']);
    }

    // ── 2. The disclosure, through the real authoring surface ────────────────

    /**
     * THE RULING'S OTHER HALF. Driven through pp_execute_action('update_composition') rather
     * than the writer directly, because the envelope is the thing under test: an agent or an
     * operator sees `ok: true` and this report, and nothing else.
     */
    public function testTheAcceptedWriteDisclosesThatItHasNoUndoPoint(): void
    {
        $post_id = $this->pageWhoseNextWriteCannotBeRecorded();

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->shallowBands('Replacement'),
        ]);

        $this->assertTrue($result['ok'], 'report-only: the disclosure must never block the write');
        $this->assertArrayHasKey('findings', $result);

        $notices = $this->noticesIn($result['findings']);
        $this->assertCount(1, $notices, 'exactly one disclosure for one skipped push');

        $notice = $notices[0];
        $this->assertSame('warning', $notice['severity'], 'the severity every generic consumer branches on');
        $this->assertNull($notice['index'], 'no single band owns this — the findings_truncated/skipped idiom');
        $this->assertStringContainsString('NO UNDO POINT', $notice['message']);
        $this->assertStringContainsString(
            'wp pp operate composition-history --post_id=' . $post_id,
            $notice['message'],
            'the command names the actual page, not a placeholder'
        );

        // THE ORDER IS THE TRUNCATION DEFENCE. A report is cut at PP_WRITE_FINDINGS_BUDGET
        // and closed by a tail; an appended notice would be the entry a pathological page
        // drops. Leading the list is what makes it unloseable.
        $this->assertSame(self::NOTICE, $result['findings'][0]['type'], 'the disclosure leads the report');
    }

    /**
     * THE DISCLOSURE DOES NOT DISPLACE THE COMPOSITION REPORT — it leads it. Driven through
     * `add_component`, which validates only the item it adds and so legitimately accepts a
     * write onto a page whose other bands current rules reject: the stored composition here
     * keeps the deep band with its undeclared prop, so the envelope carries a real finding
     * about the composition AND the notice about the write, in that order.
     */
    public function testTheDisclosureLeadsARealCompositionReportRatherThanReplacingIt(): void
    {
        $post_id = $this->pageWhoseNextWriteCannotBeRecorded();

        $result = pp_execute_action('add_component', [
            'post_id'   => $post_id,
            'component' => 'section',
            'props'     => ['body' => 'Appended'],
        ]);

        $this->assertTrue($result['ok'], 'the item-scoped write is accepted');
        $this->assertSame(self::NOTICE, $result['findings'][0]['type'], 'the disclosure is first');
        $this->assertGreaterThan(
            1,
            count($result['findings']),
            'and the composition report is still there behind it — the deep band is still undeclared'
        );
        $this->assertCount(1, $this->noticesIn($result['findings']), 'still exactly one disclosure');
    }

    /**
     * RESTORE OWNS ITS OWN `findings` KEY (#233/#687), so the disclosure has to be attached
     * where BOTH owners are covered rather than inside the derived report. This is also the
     * surface where the disclosure matters most: an undo whose own undo point is missing.
     */
    public function testRestoreCompositionCarriesTheDisclosureOnItsOwnFindingsKey(): void
    {
        $post_id = $this->pageWhoseNextWriteCannotBeRecorded();
        $this->assertNotSame([], pp_get_composition_history($post_id), 'premise: something to restore');

        $result = pp_execute_action('restore_composition', [
            'post_id'    => $post_id,
            'steps_back' => 1,
        ]);

        $this->assertTrue($result['ok'], 'restore is never blocked');
        $this->assertCount(
            1,
            $this->noticesIn($result['findings']),
            'restore sets findings before the dispatcher runs; the disclosure must still reach it'
        );
        $this->assertSame(self::NOTICE, $result['findings'][0]['type']);
    }

    /**
     * THE RUN-SCOPED ROLLBACK is the other surface that reports findings for an accepted
     * composition write, and it never goes through pp_execute_action(). Drained per post,
     * inside the revert loop, so a run touching several pages attributes each notice to the
     * page whose write produced it.
     */
    public function testTheRunScopedRollbackCarriesTheDisclosurePerPost(): void
    {
        $clean = $this->page('Clean revert');
        $lossy = $this->page('Lossy revert');
        pp_update_composition($clean, $this->shallowBands('Clean before'));
        pp_update_composition($lossy, $this->shallowBands('Lossy before'));

        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, $clean, pp_get_composition($clean));
        pp_operate_record_composition_content_snapshot($run_id, $lossy, pp_get_composition($lossy));

        pp_update_composition($clean, $this->shallowBands('Clean after'));
        pp_operate_record_touched_post_id($run_id, $clean);
        // Only this page's mid-run state is unencodable, so only its revert loses an undo point.
        pp_update_composition($lossy, $this->deepBands($this->depthThatBreaksTheRingEncode()));
        pp_operate_record_touched_post_id($run_id, $lossy);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertTrue($report['ok']);

        $by_post = [];
        foreach ($report['reverted'] as $entry) {
            $by_post[$entry['post_id']] = $entry['findings'];
        }
        $this->assertCount(1, $this->noticesIn($by_post[$lossy] ?? []), 'the page that lost an undo point says so');
        $this->assertSame(
            [],
            $this->noticesIn($by_post[$clean] ?? []),
            'and the page that did not must not inherit it — the notice is per post, not per run'
        );

        pp_operate_cleanup_run($run_id);
    }

    /**
     * THE DISCLOSURE MUST NOT TRIP THE CLI'S RULE-VIOLATION WARNING.
     * pp_operate_restore_run_finding_count() drives `wp pp apply restore-composition`'s
     * "N reverted post(s) have composition findings under current validation rules"
     * (lib/cli.php). Before #821 every entry a reverted post could carry WAS a rule
     * finding, so "non-empty findings" was the same question. A page whose restored
     * composition is clean and whose rollback write merely lost its undo point breaks no
     * rule, and counting it would send the operator hunting a violation that is not there.
     */
    public function testTheDisclosureAloneDoesNotCountAsACompositionFindingForTheCliWarning(): void
    {
        $post_id = $this->page('Clean composition, lost undo point');
        pp_update_composition($post_id, $this->shallowBands('Before'));

        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, $post_id, pp_get_composition($post_id));
        pp_update_composition($post_id, $this->deepBands($this->depthThatBreaksTheRingEncode()));
        pp_operate_record_touched_post_id($run_id, $post_id);

        $report = pp_operate_restore_run_compositions($run_id);
        $entry  = $report['reverted'][0];

        $this->assertCount(1, $this->noticesIn($entry['findings']), 'premise: the notice is on the report');
        $this->assertSame(
            [],
            array_values(array_filter($entry['findings'], 'pp_finding_is_about_the_composition')),
            'premise: the restored composition itself is clean'
        );
        $this->assertSame(
            0,
            pp_operate_restore_run_finding_count($report),
            'the CLI must not announce a validation-rule problem over a page that broke no rule'
        );

        pp_operate_cleanup_run($run_id);
    }

    /**
     * NARROWING THE RULE COUNT MUST NOT MAKE THE CLI SILENT. The sibling count exists
     * because suppressing the false rule-warning removed the only human-readable signal
     * on `wp pp apply restore-composition`: without it a run whose every reverted page
     * lost its undo point printed no warning at all, and the disclosure lived only inside
     * the stdout JSON — on the very surface the entry's own message tells you to use.
     */
    public function testTheRollbackStillCountsPagesThatLostTheirUndoPointSeparately(): void
    {
        $post_id = $this->page('Counted as a lost undo point');
        pp_update_composition($post_id, $this->shallowBands('Before'));

        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, $post_id, pp_get_composition($post_id));
        pp_update_composition($post_id, $this->deepBands($this->depthThatBreaksTheRingEncode()));
        pp_operate_record_touched_post_id($run_id, $post_id);

        $report = pp_operate_restore_run_compositions($run_id);

        $this->assertSame(
            0,
            pp_operate_restore_run_finding_count($report),
            'no composition rule was broken'
        );
        $this->assertSame(
            1,
            pp_operate_restore_run_no_undo_count($report),
            'but the page still has to be named by the CLI, on its own count and its own sentence'
        );

        pp_operate_cleanup_run($run_id);
    }

    /**
     * And a clean rollback trips neither count, so the CLI stays quiet when it should.
     */
    public function testACleanRollbackTripsNeitherCount(): void
    {
        $post_id = $this->page('Nothing to warn about');
        pp_update_composition($post_id, $this->shallowBands('Before'));

        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, $post_id, pp_get_composition($post_id));
        pp_update_composition($post_id, $this->shallowBands('After'));
        pp_operate_record_touched_post_id($run_id, $post_id);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertSame(0, pp_operate_restore_run_finding_count($report));
        $this->assertSame(0, pp_operate_restore_run_no_undo_count($report));

        pp_operate_cleanup_run($run_id);
    }

    /**
     * The same seam still counts a page that DOES carry a rule finding, so the fix above
     * narrowed the count rather than blanking it.
     */
    public function testARealCompositionFindingStillCountsForTheCliWarning(): void
    {
        $post_id = $this->page('Genuinely broken snapshot');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'Fine', 'nonsense' => 'x']],
        ]);

        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, $post_id, pp_get_composition($post_id));
        pp_update_composition($post_id, $this->shallowBands('Mid-run'));
        pp_operate_record_touched_post_id($run_id, $post_id);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertSame([], $this->noticesIn($report['reverted'][0]['findings']), 'no undo point was lost here');
        $this->assertSame(
            1,
            pp_operate_restore_run_finding_count($report),
            'a snapshot current rules reject is still counted and still warned about'
        );

        pp_operate_cleanup_run($run_id);
    }

    // ── 3. The paths that must NOT move ──────────────────────────────────────

    /**
     * A HEALTHY WRITE IS BYTE-IDENTICAL. The whole change is inert on every ordinary write:
     * the ring grows, the report is exactly the composition report, and no notice appears.
     */
    public function testAHealthyEncodeGrowsTheRingAndCarriesNoDisclosure(): void
    {
        $post_id = $this->page('Ordinary page');
        pp_update_composition($post_id, $this->shallowBands('First'));

        $ring_before = pp_get_composition_history($post_id);
        $result      = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->shallowBands('Second'),
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['findings'], 'a clean write still reports a clean, EMPTY list');
        $this->assertCount(
            count($ring_before) + 1,
            pp_get_composition_history($post_id),
            'and the push it discloses nothing about actually happened'
        );
    }

    // ── 4. The register cannot report a skip against the wrong write ─────────

    /**
     * DRAINED, NOT LATCHED. One skipped push is one disclosure: the next write to the same
     * page is a different write, and reporting the earlier one against it would tell the
     * operator that a write which DOES have an undo point does not.
     */
    public function testTheDisclosureDoesNotSurviveOntoTheNextWriteToTheSamePage(): void
    {
        $post_id = $this->pageWhoseNextWriteCannotBeRecorded();

        $first = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->shallowBands('First replacement'),
        ]);
        $this->assertCount(1, $this->noticesIn($first['findings']), 'premise: the first write disclosed');

        $second = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->shallowBands('Second replacement'),
        ]);
        $this->assertTrue($second['ok']);
        $this->assertSame([], $this->noticesIn($second['findings']), 'the second write pushed fine and says nothing');
    }

    /**
     * THE SLOT IS CLEARED BY EVERY WRITE, NOT ONLY BY ONE THAT PUSHES — which is why
     * pp_update_composition() forgets it OUTSIDE the "is there a prior?" gate. A write with
     * no prior state pushes nothing and would otherwise leave an earlier page-scoped notice
     * standing, so its envelope would claim a first write had lost an undo point it never had.
     */
    public function testAWriteWithNothingToPushStillClearsAnEarlierNotice(): void
    {
        $post_id = $this->pageWhoseNextWriteCannotBeRecorded();

        // A skipped push that nobody drains: the writer called directly, no envelope built.
        pp_update_composition($post_id, $this->shallowBands('Undrained'));

        // The page is emptied of stored composition, so the next write has no prior at all.
        // An EMPTY row rather than a deleted one, because that is the reachable state: the
        // `_pp_composition` sanitize_callback in lib/admin.php rewrites any non-array
        // payload to '', and both in-lock readers map '' to "no prior" (#818).
        update_post_meta($post_id, '_pp_composition', '');

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $this->shallowBands('Fresh start'),
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame(
            [],
            $this->noticesIn($result['findings']),
            'nothing was pushed and nothing was lost, so nothing may be claimed'
        );
    }

    /**
     * ONE SKIPPED PUSH IS ONE FINDING, even though two surfaces can ask.
     *
     * THE DRAIN IS LOAD-BEARING AND WAS INVISIBLE. _pp_take_history_push_skipped() reads
     * AND clears; three docblocks say so and call it the reason one event cannot be
     * reported twice, but nothing distinguished it from a pure read — the register could be
     * mutated to peek-and-restore and all 4671 tests stayed green, because every other test
     * here is protected by the clear-on-every-write invariant instead. That is precisely the
     * state in which a tidying refactor collapses the twin functions and nothing goes red.
     * Pinned at the register so it does not depend on any surface's wiring.
     */
    public function testTakingTheNoticeConsumesIt(): void
    {
        $post_id = $this->page('Drained once');

        _pp_record_history_push_skipped($post_id);
        $this->assertTrue(_pp_take_history_push_skipped($post_id), 'the first ask gets the notice');
        $this->assertFalse(_pp_take_history_push_skipped($post_id), 'the second ask gets nothing');
    }

    /**
     * The same property at the envelope level: the finding builder mints one entry for one
     * skipped push, so two reporting surfaces asking about the same write cannot both
     * disclose it.
     */
    public function testTheFindingBuilderMintsOneEntryPerSkippedPush(): void
    {
        $post_id = $this->page('Minted once');

        _pp_record_history_push_skipped($post_id);
        $this->assertCount(1, _pp_history_push_skipped_findings($post_id));
        $this->assertSame([], _pp_history_push_skipped_findings($post_id), 'drained, not latched');
    }

    /**
     * PER PAGE, NOT PER REQUEST. Two pages written in one request must not share one
     * page's loss — the register is keyed by post id precisely so the envelope names the
     * page it is talking about.
     */
    public function testASkipOnOnePageDoesNotReachAnotherPagesEnvelope(): void
    {
        $lossy = $this->pageWhoseNextWriteCannotBeRecorded();
        $other = $this->page('Innocent bystander');
        pp_update_composition($other, $this->shallowBands('Bystander first'));

        // The lossy page's skip happens first and is never drained.
        pp_update_composition($lossy, $this->shallowBands('Lossy replacement'));

        $result = pp_execute_action('update_composition', [
            'post_id'     => $other,
            'composition' => $this->shallowBands('Bystander second'),
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $this->noticesIn($result['findings']), 'a different page, a different slot');
    }
}
