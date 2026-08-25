<?php
/**
 * tests/CompositionFindingsBoundsTest.php — the reporting surfaces carry a bounded report (#654).
 *
 * THE FAILURE THIS CLOSES. Since #621 findings are exhaustive per authored LOCATION, so
 * the SIZE of a report scales with the size of the INPUT, and nothing capped it on the
 * reporting path. Measured on this repo: one `logos` band whose `items` is a list of
 * 10,000 empty entries produces 20,001 findings and a 22 MB peak; the issue body records
 * 120,000 findings / ~113 MB on a 176 KB input. Every reporting consumer took that array
 * whole — restore preview, restore execute, the run-scoped rollback (which aggregates
 * EVERY touched post into one JSON document), and the chat undo card, which renders one
 * DOM node per finding.
 *
 * THE CONTRACT (D1 clause 3, ratified in #687; extended to these surfaces 2026-08-25).
 * One budget, one helper, one owner:
 *
 *     _pp_composition_findings()          exhaustive — UNCHANGED below the cap (#621)
 *              │
 *     _pp_bounded_findings($f, $post_id)  ≤ 100 + one findings_truncated tail naming the page
 *              │
 *              ├─ restore_composition preview      (lib/actions.php)
 *              ├─ restore_composition execute      (lib/actions.php)  ← the chat undo card's payload
 *              └─ the run-scoped rollback          (lib/operate.php)  ← bounded PER POST
 *
 * THREE THINGS THIS DELIBERATELY DOES NOT DO, each pinned below as a seam rather than
 * left to prose:
 *
 *   `wp pp check page` IS NOT BOUNDED. Its own name is the breadcrumb inside every
 *   truncation tail ("Run `wp pp check page --post_id=N` for the complete report"), which
 *   is ratified #687 contract. Capping it would falsify that sentence on every surface at
 *   once and leave the product with no complete report anywhere — the operator who
 *   followed the breadcrumb would get the same first 100 findings back. See
 *   _pp_cli_page_diagnostics().
 *
 *   THE WRITE-REJECTION PATH IS UNTOUCHED. It keeps #621's budget of 1 and returns
 *   byte-identical messages.
 *
 *   THE COUNT BUDGET IS NOT AN AVAILABILITY GATE. Both engines run to completion before
 *   the helper sees the array, so memory is NOT bounded by this change — only what the
 *   envelope carries is. The post-write OOM that argument implies for restore/rollback is
 *   a #233 posture question with its own issue, not a rider on this one.
 *
 * Section 14.1 (authoring path): every fixture below is authored through the real write
 * surface (pp_create_page + pp_update_composition + pp_execute_action), never a raw
 * `_pp_composition` meta write, because the envelope IS the contract under test.
 */

// tests/bootstrap.php does NOT load lib/cli.php. The carve-out pins below call
// _pp_cli_page_diagnostics() and _pp_cli_page_fails_site_validation(), so without this the
// two tests guarding the deliberate `wp pp check page` exclusion pass only when some OTHER
// test file happens to have loaded cli.php first — running this file alone errored with
// "Call to undefined function". Same idiom as tests/CompositionShapeTrustTest.php.
require_once dirname(__DIR__) . '/lib/cli.php';

use PHPUnit\Framework\TestCase;

final class CompositionFindingsBoundsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the in-memory store for test isolation (tests/bootstrap.php). Without this
        // the class is order-dependent: a class whose tearDown unsets the store leaves
        // nothing for pp_create_page() to write into, and every fixture here fatals.
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────

    /**
     * A page whose stored composition produces MORE than the budget in findings.
     *
     * 40 bands x 4 undeclared prop keys — the same recipe WriteEnvelopeFindingsTest uses,
     * reused rather than re-invented so the two files cannot drift into disagreeing about
     * what "pathological" means. Authored through pp_update_composition(), the real
     * writer, so the stored bytes are the ones a restore would actually bring back.
     */
    private function pathologicalPage(int $bands = 40): int
    {
        $composition = [];
        for ($i = 0; $i < $bands; $i++) {
            $composition[] = ['component' => 'section', 'props' => [
                'id' => "s$i", 'title' => "T$i", 'body' => 'B',
                'zzA' => 1, 'zzB' => 2, 'zzC' => 3, 'zzD' => 4,
            ]];
        }
        $id = pp_create_page('Pathological page', 'draft');
        pp_update_composition($id, $composition);

        return $id;
    }

    /** A page with a handful of findings — well under the budget. */
    private function slightlyStalePage(): int
    {
        $id = pp_create_page('Slightly stale', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'One', 'body' => 'B', 'zzA' => 1]],
        ]);

        return $id;
    }

    /** A page whose bands are all clean under current rules. */
    private function cleanPage(string $title = 'Clean page'): int
    {
        $id = pp_create_page($title, 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'One', 'body' => 'Body copy.']],
        ]);

        return $id;
    }

    /**
     * Pushes a history entry so `steps_back => 1` has a target, then restores it.
     *
     * style_component is the cheapest composition-mutating write that does not touch the
     * props the fixtures above rely on, so the snapshot the restore brings back is the
     * fixture's own stored bytes.
     */
    private function restoreAfterOneWrite(int $post_id): array
    {
        pp_execute_action('style_component', [
            'post_id' => $post_id, 'component_index' => 0, 'style' => ['--section-bg' => '#101014'],
        ]);

        return pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);
    }

    private static function tailOf(array $findings): ?array
    {
        $tails = array_values(array_filter(
            $findings,
            static fn ($f) => ($f['type'] ?? '') === 'findings_truncated'
        ));

        return $tails === [] ? null : $tails[0];
    }

    // ── 1. RESTORE: EXECUTE ─────────────────────────────────────────────────────

    /**
     * THE ACCEPTANCE CRITERION for the surface undo is wired to. The restore still lands
     * (#233 — undo must never fail), and the report it hands back is capped and honest
     * about being capped.
     */
    public function testRestoreExecuteBoundsItsReportAndNamesThePage(): void
    {
        $id         = $this->pathologicalPage();
        $true_total = count(_pp_composition_findings(pp_get_composition($id)));
        $this->assertGreaterThan(
            PP_WRITE_FINDINGS_BUDGET,
            $true_total,
            'precondition: this fixture must actually exceed the budget'
        );

        $result = $this->restoreAfterOneWrite($id);

        $this->assertTrue($result['ok'], 'a long report never blocks the restore (#233)');
        $this->assertCount(
            PP_WRITE_FINDINGS_BUDGET + 1,
            $result['findings'],
            'the budget plus exactly one tail'
        );

        $tail = end($result['findings']);
        $this->assertSame('findings_truncated', $tail['type'], 'the tail closes the list');
        $this->assertSame('warning', $tail['severity'], 'the honest severity for an advisory about the REPORT');
        $this->assertNull($tail['index'], 'truncation belongs to no single band');
        $this->assertStringContainsString(
            (string) $true_total,
            $tail['message'],
            'the tail states the TRUE total, so a truncated report never reads as a complete one'
        );
        $this->assertStringContainsString(
            'wp pp check page --post_id=' . $id,
            $tail['message'],
            'the breadcrumb names the ACTUAL page — a command the operator can paste'
        );
        $this->assertSame(
            $true_total,
            $tail['total'],
            'the TRUE total is carried structurally, not only in prose — a consumer that '
            . 'renders a count cannot parse the message and must not count the array instead'
        );
    }

    /**
     * `total` IS ONLY EVER ON A TRUNCATION ENTRY, so its absence is meaningful: it says
     * nothing was omitted. A consumer reads `total ?? count($findings)`; if the key started
     * appearing on ordinary findings, or on the size-gate skip entry (where nothing was
     * counted at all), that read would start reporting confident nonsense.
     */
    public function testOnlyTheTruncationEntryCarriesATotal(): void
    {
        $id     = $this->pathologicalPage();
        $result = $this->restoreAfterOneWrite($id);

        foreach ($result['findings'] as $finding) {
            if ($finding['type'] === 'findings_truncated') {
                $this->assertArrayHasKey('total', $finding);
                continue;
            }
            $this->assertArrayNotHasKey(
                'total',
                $finding,
                'an ordinary finding carries no total — absence means "nothing was omitted"'
            );
        }

        // A short report has no tail at all, so no total anywhere.
        $short = $this->restoreAfterOneWrite($this->slightlyStalePage());
        foreach ($short['findings'] as $finding) {
            $this->assertArrayNotHasKey('total', $finding);
        }
    }

    /**
     * ONE BUDGET SYSTEM, NOT TWO. The bound is the shared helper's, at the ratified
     * constant — not a second cap with its own number that could drift from the write
     * path's. Asserted by reconstructing the expected report from the helper itself.
     */
    public function testRestoreUsesTheOneSharedBudgetHelper(): void
    {
        $id     = $this->pathologicalPage();
        $result = $this->restoreAfterOneWrite($id);

        $this->assertSame(
            _pp_bounded_findings(_pp_composition_findings(pp_get_composition($id)), $id),
            $result['findings'],
            'no second vocabulary and no second budget: exactly the shared helper output'
        );
    }

    /**
     * #621's contract is untouched BELOW the cap. The bound is on what the envelope
     * CARRIES; a page with fewer findings than the budget still reports every one of them
     * — exhaustively, per authored location — and carries no tail.
     */
    public function testAShortReportIsUnchangedAndCarriesNoTail(): void
    {
        $id       = $this->slightlyStalePage();
        $expected = _pp_composition_findings(pp_get_composition($id));
        $this->assertNotSame([], $expected, 'precondition: this fixture has findings');
        $this->assertLessThan(PP_WRITE_FINDINGS_BUDGET, count($expected), 'precondition: under the budget');

        $result = $this->restoreAfterOneWrite($id);

        $this->assertSame($expected, $result['findings'], 'every finding, byte-identical, no truncation');
        $this->assertNull(self::tailOf($result['findings']), 'and no tail on a report that fits');
    }

    /**
     * An empty report still means "this restore brought back something clean". A bound
     * must never turn a clean bill of health into an absent one.
     */
    public function testACleanRestoreStillReportsAnEmptyList(): void
    {
        $id     = $this->cleanPage();
        $result = $this->restoreAfterOneWrite($id);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([], $result['findings']);
    }

    // ── 2. RESTORE: PREVIEW ─────────────────────────────────────────────────────

    /**
     * PREVIEW AND EXECUTE MUST AGREE. A preview that reported 10,000 findings for a
     * restore whose execute reported 100 would be a new preview/execute asymmetry — the
     * class of defect #711 already tracks — invented by the fix for a different one.
     */
    public function testRestorePreviewBoundsItsReportTheSameWayExecuteDoes(): void
    {
        $id = $this->pathologicalPage();
        pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--section-bg' => '#101014'],
        ]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);
        $execute = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        $this->assertCount(PP_WRITE_FINDINGS_BUDGET + 1, $preview['findings'], 'preview is bounded too');
        $this->assertSame(
            $execute['findings'],
            $preview['findings'],
            'preview describes exactly what execute will report'
        );
    }

    // ── 3. THE AGGREGATING CONSUMER: THE RUN-SCOPED ROLLBACK ────────────────────

    /**
     * BOUNDED PER POST, AND EACH TAIL NAMES ITS OWN PAGE.
     *
     * This is the one consumer that aggregates — every touched post's report lands in one
     * JSON document printed by `wp pp apply restore-composition`. "A per-report budget of
     * 100" had to be resolved into a unit, and the unit is the POST, because the tail's
     * breadcrumb is `--post_id=N`: a budget spanning the whole run would have no single
     * page to name at exactly the moment the operator needs to know WHICH page overflowed.
     */
    public function testRunRollbackBoundsEachPostSeparatelyAndNamesEachPage(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'] = [];

        $big = [];
        for ($i = 0; $i < 40; $i++) {
            $big[] = ['component' => 'section', 'props' => [
                'id' => "s$i", 'title' => "T$i", 'body' => 'B',
                'zzA' => 1, 'zzB' => 2, 'zzC' => 3, 'zzD' => 4,
            ]];
        }
        pp_update_composition(901, $big);
        pp_update_composition(902, $big);

        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, 901, pp_get_composition(901));
        pp_operate_record_composition_content_snapshot($run_id, 902, pp_get_composition(902));

        pp_update_composition(901, [['component' => 'hero', 'props' => ['title' => 'A1']]]);
        pp_operate_record_touched_post_id($run_id, 901);
        pp_update_composition(902, [['component' => 'hero', 'props' => ['title' => 'B1']]]);
        pp_operate_record_touched_post_id($run_id, 902);

        $report = pp_operate_restore_run_compositions($run_id);

        $this->assertTrue($report['ok']);
        $this->assertCount(2, $report['reverted'], 'both touched posts reverted');
        $this->assertSame([], $report['skipped']);

        foreach ($report['reverted'] as $entry) {
            $this->assertCount(
                PP_WRITE_FINDINGS_BUDGET + 1,
                $entry['findings'],
                'each post carries its own bounded report, not a share of one global budget'
            );
            $tail = end($entry['findings']);
            $this->assertSame('findings_truncated', $tail['type']);
            $this->assertStringContainsString(
                'wp pp check page --post_id=' . $entry['post_id'],
                $tail['message'],
                "post {$entry['post_id']}'s tail must name post {$entry['post_id']}, not a placeholder"
            );
        }

        // The two tails must differ — proof they were built per post rather than copied.
        $this->assertNotSame(
            end($report['reverted'][0]['findings'])['message'],
            end($report['reverted'][1]['findings'])['message'],
            'each page gets its own breadcrumb'
        );

        pp_operate_cleanup_run($run_id);
    }

    /**
     * THE CLI'S DECISION SEAM SURVIVES THE BOUND. `wp pp apply restore-composition` warns
     * on pp_operate_restore_run_finding_count(), which counts POSTS carrying any finding.
     * A global budget would have blanked later posts' reports entirely, dropping them out
     * of this count and reporting a cleaner rollback than actually happened. A bound that
     * lies is worse than a long report.
     */
    public function testRunRollbackFindingCountSeamSurvivesBounding(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'] = [];

        $stale = [['component' => 'section', 'props' => ['id' => 's1', 'title' => 'T', 'body' => 'B', 'zzA' => 1]]];
        pp_update_composition(911, $stale);
        pp_update_composition(912, $stale);

        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, 911, pp_get_composition(911));
        pp_operate_record_composition_content_snapshot($run_id, 912, pp_get_composition(912));
        pp_update_composition(911, [['component' => 'hero', 'props' => ['title' => 'A1']]]);
        pp_operate_record_touched_post_id($run_id, 911);
        pp_update_composition(912, [['component' => 'hero', 'props' => ['title' => 'B1']]]);
        pp_operate_record_touched_post_id($run_id, 912);

        $report = pp_operate_restore_run_compositions($run_id);

        $this->assertSame(
            2,
            pp_operate_restore_run_finding_count($report),
            'both reverted posts still count as carrying findings'
        );

        pp_operate_cleanup_run($run_id);
    }

    /**
     * THE MIXED RUN, which is the realistic one and the only shape that can catch a
     * per-post/per-run unit mix-up.
     *
     * The two tests above are homogeneous — both posts over budget, or both under — so a
     * global budget spanning the run would still satisfy them by coincidence. Here one post
     * overflows and one does not, in the SAME report: the first must carry a tail naming
     * itself, the second must carry its exact unbounded report and no tail at all. A run-wide
     * budget would spend the whole allowance on the first post and blank or truncate the
     * second, which is precisely the "bound that lies" this design rejected.
     *
     * It also pins the residual lib/operate.php documents in prose as a real CEILING rather
     * than a comment: carried findings across the run are at most (budget + 1) x touched posts.
     */
    public function testAMixedRunBoundsTheOverflowingPostAndLeavesTheOtherIntact(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'] = [];

        $big = [];
        for ($i = 0; $i < 40; $i++) {
            $big[] = ['component' => 'section', 'props' => [
                'id' => "s$i", 'title' => "T$i", 'body' => 'B',
                'zzA' => 1, 'zzB' => 2, 'zzC' => 3, 'zzD' => 4,
            ]];
        }
        $small = [['component' => 'section', 'props' => ['id' => 's1', 'title' => 'T', 'body' => 'B', 'zzA' => 1]]];

        pp_update_composition(921, $big);
        pp_update_composition(922, $small);
        $small_expected = _pp_composition_findings(pp_get_composition(922));
        $this->assertNotSame([], $small_expected, 'precondition: the small page has findings');
        $this->assertLessThan(PP_WRITE_FINDINGS_BUDGET, count($small_expected), 'precondition: under budget');

        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, 921, pp_get_composition(921));
        pp_operate_record_composition_content_snapshot($run_id, 922, pp_get_composition(922));
        pp_update_composition(921, [['component' => 'hero', 'props' => ['title' => 'A1']]]);
        pp_operate_record_touched_post_id($run_id, 921);
        pp_update_composition(922, [['component' => 'hero', 'props' => ['title' => 'B1']]]);
        pp_operate_record_touched_post_id($run_id, 922);

        $report = pp_operate_restore_run_compositions($run_id);
        $by_post = [];
        foreach ($report['reverted'] as $entry) {
            $by_post[$entry['post_id']] = $entry['findings'];
        }

        // The overflowing post: bounded, with a tail naming ITSELF.
        $this->assertCount(PP_WRITE_FINDINGS_BUDGET + 1, $by_post[921]);
        $this->assertStringContainsString('--post_id=921', end($by_post[921])['message']);

        // The post beside it: untouched by the other one's overflow.
        $this->assertSame($small_expected, $by_post[922], 'a short report is unchanged, entry for entry');
        $this->assertNull(self::tailOf($by_post[922]), 'and carries no tail borrowed from its neighbour');

        // The documented residual, as a ceiling rather than prose.
        $carried = array_sum(array_map('count', array_values($by_post)));
        $this->assertLessThanOrEqual(
            (PP_WRITE_FINDINGS_BUDGET + 1) * count($report['reverted']),
            $carried,
            'the run-wide carried total is bounded by (budget + 1) x touched posts'
        );

        pp_operate_cleanup_run($run_id);
    }

    // ── 4. THE CARVE-OUT: `wp pp check page` STAYS COMPLETE ─────────────────────

    /**
     * THE BREADCRUMB MUST NOT BE A LIE.
     *
     * Every bounded surface closes its report with "Run `wp pp check page --post_id=N` for
     * the complete report" — ratified #687 text, printed by the write path, by restore and
     * by the rollback. This asserts the two halves of that promise together: the tail says
     * it, and the command it names actually delivers MORE than the tail did. If a future
     * change caps _pp_cli_page_diagnostics(), this fails and says why.
     */
    public function testCheckPageStaysCompleteBecauseEveryTruncationTailPointsAtIt(): void
    {
        $id     = $this->pathologicalPage();
        $result = $this->restoreAfterOneWrite($id);
        $tail   = end($result['findings']);

        $this->assertStringContainsString('for the complete report', $tail['message']);
        $this->assertStringContainsString('wp pp check page --post_id=' . $id, $tail['message']);

        $diagnostics = _pp_cli_page_diagnostics(pp_get_composition($id));
        $reported    = count($diagnostics['errors']) + count($diagnostics['smells']);

        $this->assertGreaterThan(
            count($result['findings']),
            $reported,
            'the command the tail names must return MORE than the bounded report, or the breadcrumb is circular'
        );
        $this->assertSame(
            count(_pp_composition_findings(pp_get_composition($id))),
            $reported,
            'and it returns ALL of them — this is the one complete-report surface'
        );
        $this->assertSame(
            [],
            array_filter(
                array_merge($diagnostics['errors'], $diagnostics['smells']),
                static fn ($f) => ($f['type'] ?? '') === 'findings_truncated'
            ),
            'check page never emits a truncation tail: it has nothing to truncate'
        );
    }

    /**
     * THE EXIT CODE CANNOT MOVE, and the reason is ordering, not luck.
     *
     * _pp_composition_findings() emits errors before advisories, so if a composition has
     * ANY error-severity finding it is inside the first PP_WRITE_FINDINGS_BUDGET entries.
     * The `errors` bucket _pp_cli_page_fails_site_validation() gates on therefore stays
     * non-empty under bounding, and the tail itself is severity `warning`, which lands in
     * `smells` — also non-empty. Bounding could not flip a failing page to passing even if
     * check page WERE bounded. Pinned so the argument survives with evidence.
     */
    public function testBoundingCouldNotFlipTheSiteValidationExitCode(): void
    {
        $id       = $this->pathologicalPage();
        $findings = _pp_composition_findings(pp_get_composition($id));
        $severities = array_column($findings, 'severity');

        $this->assertGreaterThan(PP_WRITE_FINDINGS_BUDGET, count($findings), 'precondition: over the budget');
        $this->assertSame(
            ['error'],
            array_values(array_unique(array_slice($severities, 0, PP_WRITE_FINDINGS_BUDGET))),
            'errors sort first, so the first 100 are all errors on this fixture'
        );

        $bounded = _pp_bounded_findings($findings, $id);
        $errors  = array_filter($bounded, static fn ($f) => $f['severity'] === 'error');
        $others  = array_filter($bounded, static fn ($f) => $f['severity'] !== 'error');

        $this->assertNotSame([], $errors, 'the bucket the exit code gates on is still non-empty');
        $this->assertNotSame([], $others, 'and the warning-severity tail lands in the smells bucket');

        // The predicate itself, on the real (unbounded) diagnostics and on a bounded view.
        $real = _pp_cli_page_diagnostics(pp_get_composition($id));
        $this->assertTrue(_pp_cli_page_fails_site_validation($real), 'this page fails site validation');
        $this->assertTrue(
            _pp_cli_page_fails_site_validation([
                'errors'  => array_values($errors),
                'smells'  => array_values($others),
                'styling' => $real['styling'],
            ]),
            'and it still fails when the same findings are bounded'
        );
    }

    // ── 5. THE WRITE PATH IS NOT THIS ISSUE'S ───────────────────────────────────

    /**
     * THE REJECTION PATH IS UNTOUCHED. #621 gives it a budget of 1 and #642 re-renders
     * that one error to name its band. This issue changed neither, and a rejected envelope
     * still carries no `findings` key at all.
     */
    public function testTheWriteRejectionPathIsUnchanged(): void
    {
        $id = $this->cleanPage('Rejection page');

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['nope_not_a_prop' => 'x'],
        ]);

        $this->assertFalse($result['ok'], 'precondition: this write is rejected');
        $this->assertArrayNotHasKey('findings', $result, 'a rejected envelope carries no findings key');

        $direct = pp_validate_composition([
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'T', 'body' => 'B', 'nope' => 1]],
        ]);
        $this->assertTrue(is_wp_error($direct), 'the shared write validator still rejects');
        $this->assertStringContainsString(
            'has no prop "nope"',
            $direct->get_error_message(),
            'and returns exactly the message it always did — one actionable rejection'
        );
    }

    /**
     * THE ACCEPTED-WRITE ENVELOPE KEEPS ITS OWN MECHANISM, including the 1 MiB
     * availability gate restore deliberately does NOT inherit. Asserted here so the two
     * budgets cannot silently converge: they share a helper and a constant, not a policy.
     */
    public function testTheAcceptedWriteEnvelopeStillCarriesItsOwnGatedReport(): void
    {
        $id     = $this->pathologicalPage();
        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--section-bg' => '#101014'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            _pp_write_findings_for($id),
            $result['findings'],
            'the write path still routes through the size-gated helper, not the bare bounder'
        );
    }
}
