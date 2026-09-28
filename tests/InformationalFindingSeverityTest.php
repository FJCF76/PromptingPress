<?php
/**
 * #1194 A1: an INFORMATIONAL finding never fails `wp pp validate site`.
 *
 * Before 2.0.1 every udc disclosure was stamped `severity: warning`, and the site gate
 * failed on any warning. `udc_token_minted` fires once per responsive (breakpoint-map)
 * literal and says "nothing to fix", so ANY responsive value made a correct v2 site exit
 * non-zero. The ruling (orchestrator, 2026-09-28, D1 = A): the mint disclosure becomes
 * `severity: info`. It is still reported everywhere it was (write envelope, `check page`,
 * `validate site`), and it never gates. Every other advisory keeps gating.
 *
 * Driven through the real authoring surface (Section 14.1): the band is written with
 * create_page, read back from storage, and judged by the same diagnostics the CLI runs.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/cli.php';

final class InformationalFindingSeverityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolated from whatever store the previous class left (review: this class failed
        // when it ran right after DiagnosticReachTest, whose tearDown unsets the store).
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_store']);
        parent::tearDown();
    }

    /** One testimonials band, with an authored id, whose udc is exactly $udc. */
    private function quotesBand(string $id, array $udc): array
    {
        return [
            'component' => 'testimonials',
            'props'     => ['id' => $id, 'items' => [['quote' => 'Q', 'author' => 'A', 'role' => 'R', 'company' => 'C']]],
            'udc'       => $udc,
        ];
    }

    /** Stores $composition through the real writer and returns the page's diagnostics. */
    private function diagnose(array $composition): array
    {
        $id = pp_create_page('Budget');
        $this->assertTrue(pp_update_composition($id, $composition), 'the write must land');
        return _pp_cli_page_diagnostics(pp_get_composition($id), $id);
    }

    /**
     * THE BYPASS A1 FIRST SHIPPED WITH (review cycle 1, three reviewers independently). The
     * mint notes and the gating `udc_unused_band_token` shared one 200-per-page budget, and the
     * mints run first, so 200 responsive values used it up and the real warning was never
     * emitted: `validate site` passed a page that should fail. A note must never spend a
     * gating finding's budget.
     */
    public function testTwoHundredMintsCannotHideAnUnusedTokenWarning(): void
    {
        $composition = [];
        for ($b = 0; $b < 101; $b++) {
            $composition[] = $this->quotesBand('q' . $b, ['quote' => ['typography' => ['size' => ['d' => '19px', 'p' => '17px']]]]);
        }
        $composition[] = $this->quotesBand('orphaned', [
            'quote'   => ['typography' => ['style' => 'italic']],
            '_tokens' => ['orphan' => '17px'],
        ]);
        $diagnostics = $this->diagnose($composition);

        $this->assertGreaterThanOrEqual(200, count($diagnostics['info']), 'the fixture must exhaust a 200 budget with notes');
        $this->assertContains('udc_unused_band_token', array_column($diagnostics['smells'], 'type'),
            'the gating warning must still be emitted after 200+ notes');
        $this->assertTrue(_pp_cli_page_fails_site_validation($diagnostics));
    }

    /**
     * A GATING arm that reaches its cap says so with a finding that itself gates (orchestrator
     * ruling on A1 cycle 1): a page whose warnings were cut off is not a verified-clean page,
     * and once warnings can be acknowledged (A2) the omitted ones could never be.
     */
    public function testAGatingArmThatReachesItsCapEmitsAGatingTruncationFinding(): void
    {
        $tokens = [];
        for ($t = 0; $t < 205; $t++) {
            $tokens['orphan-' . $t] = '17px';
        }
        $diagnostics = $this->diagnose([$this->quotesBand('many', [
            'quote'   => ['typography' => ['style' => 'italic']],
            '_tokens' => $tokens,
        ])]);

        $unused = array_values(array_filter($diagnostics['smells'], static fn (array $f): bool => $f['type'] === 'udc_unused_band_token'));
        $this->assertCount(PP_UDC_MAX_EMIT_DROPS, $unused, 'the arm is bounded');
        $capped = array_values(array_filter($diagnostics['smells'], static fn (array $f): bool => $f['type'] === 'udc_findings_capped'));
        $this->assertCount(1, $capped, 'one truncation finding for the arm that hit its cap');
        $this->assertSame('warning', $capped[0]['severity'], 'the truncation row gates');
        $this->assertStringContainsString('udc_unused_band_token', $capped[0]['message'], 'and names what may be missing');
        $this->assertStringContainsString('read the report again', $capped[0]['message']);
        $this->assertStringNotContainsString('wp pp check page', $capped[0]['message'],
            'the engine also builds chrome envelopes, which have no page route');
        $this->assertTrue(_pp_cli_page_fails_site_validation($diagnostics));
    }

    /**
     * NOTES NEVER CROWD A WARNING OUT OF A BOUNDED REPORT (#1194 A1, cycle-2 adversarial,
     * reproduced). Every bounded consumer (the write envelope, restore, the chat card) keeps
     * the first 100 entries, and a band emits its mint notes before its unused-token
     * warning, so 100+ notes delivered only notes on a page that fails the gate. The
     * assembler delivers info entries LAST.
     */
    public function testABoundedWriteEnvelopeKeepsTheWarningAheadOfTheNotes(): void
    {
        $composition = [];
        for ($b = 0; $b < 60; $b++) {
            $composition[] = $this->quotesBand('q' . $b, ['quote' => ['typography' => ['size' => ['d' => '19px', 'p' => '17px']]]]);
        }
        $composition[] = $this->quotesBand('orphaned', [
            'quote'   => ['typography' => ['style' => 'italic']],
            '_tokens' => ['orphan' => '17px'],
        ]);
        $result = pp_execute_action('create_page', ['title' => 'Crowded', 'composition' => $composition]);
        $this->assertTrue($result['ok']);
        $types = array_column($result['findings'], 'type');

        $this->assertContains('findings_truncated', $types, 'the fixture must overflow the 100 budget');
        $this->assertContains('udc_unused_band_token', $types, 'the gating warning is delivered, not only counted');
        $firstInfo = array_search('udc_token_minted', $types, true);
        $this->assertLessThan($firstInfo, array_search('udc_unused_band_token', $types, true));
    }

    public function testTheCappedRowReachesABoundedWriteEnvelope(): void
    {
        $tokens = [];
        for ($t = 0; $t < 205; $t++) {
            $tokens['orphan-' . $t] = '17px';
        }
        $result = pp_execute_action('create_page', ['title' => 'Capped', 'composition' => [$this->quotesBand('many', [
            'quote'   => ['typography' => ['style' => 'italic']],
            '_tokens' => $tokens,
        ])]]);
        $this->assertTrue($result['ok']);

        $this->assertContains('udc_findings_capped', array_column($result['findings'], 'type'),
            'a "more exist" row that only exists past the cut is a row that does not exist');
    }

    public function testInfoFindingsAreDeliveredLastInTheirOwnOrder(): void
    {
        $band = $this->quotesBand('mixed', [
            'quote'   => ['typography' => ['size' => ['d' => '19px', 'p' => '17px']]],
            '_tokens' => ['orphan' => '17px'],
        ]);
        $id = pp_create_page('Order');
        $this->assertTrue(pp_update_composition($id, [$band]));
        $findings   = _pp_composition_findings(pp_get_composition($id), $id);
        $severities = array_column($findings, 'severity');
        $firstInfo  = array_search('info', $severities, true);

        $this->assertNotFalse($firstInfo);
        foreach (array_slice($severities, $firstInfo) as $severity) {
            $this->assertSame('info', $severity, 'nothing gating follows the first note');
        }
        $minted = array_values(array_filter($findings, static fn (array $f): bool => $f['type'] === 'udc_token_minted'));
        $this->assertStringContainsString('19px', $minted[0]['message'], 'the notes keep their own order');
        $this->assertStringContainsString('17px', $minted[1]['message']);
    }

    /**
     * THE CAPPED ROW LEADS THE WHOLE REPORT (cycle 3): it used to lead only the UDC slice,
     * which follows the validator's errors and smells, so 100 of those still cut it off.
     */
    public function testTheCappedRowLeadsTheWholeDeliveredReport(): void
    {
        $ordered = pp_order_findings_for_delivery([
            ['type' => 'unknown_prop', 'severity' => 'error', 'message' => 'e1', 'index' => 0],
            ['type' => 'udc_token_minted', 'severity' => 'info', 'message' => 'n1', 'index' => 0],
            ['type' => 'empty_section', 'severity' => 'warning', 'message' => 'w1', 'index' => 1],
            ['type' => 'udc_findings_capped', 'severity' => 'warning', 'message' => 'c1', 'index' => null],
            ['type' => 'udc_token_minted', 'severity' => 'info', 'message' => 'n2', 'index' => 1],
            ['type' => 'unknown_prop', 'severity' => 'error', 'message' => 'e2', 'index' => 2],
        ]);

        $this->assertSame(['c1', 'e1', 'w1', 'e2', 'n1', 'n2'], array_column($ordered, 'message'),
            'capped row first, then the gating findings in their own order, then the notes in theirs');
    }

    public function testAMalformedEntryIsDeliveredWithTheGatingFindingsNotTheNotes(): void
    {
        $ordered = pp_order_findings_for_delivery([
            ['type' => 'udc_token_minted', 'severity' => 'info', 'message' => 'n', 'index' => 0],
            'not an array',
            ['type' => 'empty_section', 'severity' => 'warning', 'message' => 'w', 'index' => 0],
        ]);

        $this->assertSame(['not an array', 'w', 'n'], array_map(
            static fn ($f) => is_array($f) ? $f['message'] : $f,
            $ordered
        ), 'an unreadable entry is never promoted past a note, and never demoted behind one');
    }

    public function testAPageWithManyErrorsStillDeliversTheCappedRow(): void
    {
        $findings = pp_order_findings_for_delivery(array_merge(
            array_fill(0, 150, ['type' => 'unknown_prop', 'severity' => 'error', 'message' => 'e', 'index' => 0]),
            [['type' => 'udc_findings_capped', 'severity' => 'warning', 'message' => 'c', 'index' => null]]
        ));
        $bounded = _pp_bounded_findings($findings, 1);
        $this->assertContains('udc_findings_capped', array_column($bounded, 'type'));
    }

    /**
     * THE BUDGET AUDIT AS A STANDING INVARIANT (orchestrator ruling, cycle 3): every counter
     * pp_udc_composition_findings() declares, except the notes-only mint budget, must feed
     * `$gating_budgets`, so an arm added or dropped later cannot lose its capped row silently.
     */
    public function testEveryGatingDisclosureBudgetFeedsTheCappedRow(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/udc.php');
        $start  = strpos($source, 'function pp_udc_composition_findings(');
        $this->assertNotFalse($start);
        $end    = strpos($source, "\n}\n", $start);
        $body   = substr($source, $start, $end - $start);

        preg_match_all('/^\s*(\$[a-z_]+_disclosed)\s*=\s*0;/m', $body, $declared);
        $counters = array_values(array_diff(array_unique($declared[1]), ['$tokens_disclosed']));
        $this->assertGreaterThanOrEqual(8, count($counters), 'the scan found the counters (vacuity guard)');

        $this->assertMatchesRegularExpression('/\$gating_budgets\s*=\s*\[(.*?)\];/s', $body);
        preg_match('/\$gating_budgets\s*=\s*\[(.*?)\];/s', $body, $budgets);
        foreach ($counters as $counter) {
            $this->assertMatchesRegularExpression('/=>\s*' . preg_quote($counter, '/') . '\s*,/', $budgets[1],
                $counter . ' is a gating budget with no capped row');
        }
        $this->assertStringNotContainsString('$tokens_disclosed', $budgets[1], 'the notes-only mint budget never gates');
    }

    public function testAnArmBelowItsCapEmitsNoTruncationFinding(): void
    {
        $diagnostics = $this->diagnose([$this->quotesBand('few', [
            'quote'   => ['typography' => ['style' => 'italic']],
            '_tokens' => ['orphan-a' => '17px', 'orphan-b' => '18px'],
        ])]);

        $this->assertNotContains('udc_findings_capped', array_column($diagnostics['smells'], 'type'));
    }

    /**
     * The info-only budget reaching its cap is NOT a gating truncation: every omitted entry
     * would have been a note, and a note never gates.
     */
    public function testTheMintNotesHaveTheirOwnBoundOf200(): void
    {
        $composition = [];
        for ($b = 0; $b < 105; $b++) {
            $composition[] = $this->quotesBand('q' . $b, ['quote' => ['typography' => ['size' => ['d' => '19px', 'p' => '17px']]]]);
        }
        $diagnostics = $this->diagnose($composition);

        $this->assertCount(PP_UDC_MAX_EMIT_DROPS, $diagnostics['info'], '210 mints, bounded at the cap');
        $this->assertNotContains('udc_findings_capped', array_column($diagnostics['smells'], 'type'));
    }

    public function testTheEngineItselfPutsTheCappedRowFirst(): void
    {
        // The chrome/preset envelope does not reorder (#1204), so the engine's own order is
        // what puts the capped row first there.
        $tokens = [];
        for ($t = 0; $t < 205; $t++) {
            $tokens['orphan-' . $t] = '17px';
        }
        $findings = pp_udc_composition_findings([[
            'component' => 'testimonials', 'id' => 'many',
            'props'     => ['items' => [['quote' => 'Q', 'author' => 'A', 'role' => 'R', 'company' => 'C']]],
            'udc'       => ['quote' => ['typography' => ['style' => 'italic']], '_tokens' => $tokens],
        ]]);

        $this->assertSame('udc_findings_capped', $findings[0]['type']);
    }

    public function testTheMintBudgetReachingItsCapDoesNotGate(): void
    {
        $composition = [];
        for ($b = 0; $b < 101; $b++) {
            $composition[] = $this->quotesBand('q' . $b, ['quote' => ['typography' => ['size' => ['d' => '19px', 'p' => '17px']]]]);
        }
        $diagnostics = $this->diagnose($composition);

        $this->assertSame([], $diagnostics['smells']);
        $this->assertFalse(_pp_cli_page_fails_site_validation($diagnostics));
    }

    /**
     * ONE AUTHORITY FOR SEVERITY (review cycle 1): the chrome assembler stamps severity too,
     * and it hardcoded 'warning', so a chrome mint disagreed with a composition mint.
     */
    public function testTheChromeEnvelopeStampsTheMintInfoToo(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => PP_SITE_UDC_OPTION,
            'value' => (string) wp_json_encode(['nav' => ['link' => ['typography' => ['size' => ['d' => '16px', 'p' => '15px']]]]]),
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $findings = $result['findings'] ?? [];
        $this->assertContains('udc_token_minted', array_column($findings, 'type'), 'the fixture mints');
        $this->assertSame(['info'], array_values(array_unique(array_column(
            array_filter($findings, static fn (array $f): bool => $f['type'] === 'udc_token_minted'),
            'severity'
        ))), 'a chrome mint is a note, exactly as a composition mint');
        $this->assertSame('info', pp_finding_severity('udc_token_minted'));
        $this->assertSame('warning', pp_finding_severity('udc_unused_band_token'));
    }

    /** A testimonials band whose ONLY finding-worthy fact is one responsive literal. */
    private function responsiveBand(): array
    {
        return [
            'component' => 'testimonials',
            'props'     => ['items' => [['quote' => 'They shipped in six weeks.', 'author' => 'Ada Lovelace', 'role' => 'CTO', 'company' => 'Analytical']]],
            'udc'       => ['quote' => ['typography' => ['size' => ['d' => '19px', 'p' => '17px']]]],
        ];
    }

    /** Writes a page through create_page and returns [post_id, envelope findings]. */
    private function create(array $composition): array
    {
        $result = pp_execute_action('create_page', ['title' => 'Gate honesty', 'composition' => $composition]);
        $this->assertTrue($result['ok'], 'the write must be accepted: these are disclosures, not refusals');
        return [(int) $result['target']['post_id'], $result['findings'] ?? []];
    }

    private function byType(array $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            $out[$finding['type']][] = $finding;
        }
        return $out;
    }

    public function testTheMintDisclosureIsStampedInfoOnTheWriteEnvelope(): void
    {
        [, $findings] = $this->create([$this->responsiveBand()]);
        $byType = $this->byType($findings);

        $this->assertArrayHasKey('udc_token_minted', $byType, 'the disclosure must still reach the author');
        foreach ($byType['udc_token_minted'] as $minted) {
            $this->assertSame('info', $minted['severity'], 'a mint is informational: nothing to fix');
        }
    }

    public function testAPageWhoseOnlyFindingIsAMintPassesSiteValidation(): void
    {
        [$post_id] = $this->create([$this->responsiveBand()]);
        $diagnostics = _pp_cli_page_diagnostics(pp_get_composition($post_id), $post_id);

        $this->assertSame([], $diagnostics['errors']);
        $this->assertSame([], $diagnostics['smells'], 'an informational finding is not a smell');
        $this->assertContains('udc_token_minted', array_column($diagnostics['info'], 'type'),
            'it is still reported, in its own bucket');
        $this->assertFalse(_pp_cli_page_fails_site_validation($diagnostics),
            'a correct responsive site must exit 0 (#1194)');
    }

    public function testAWarningBesideTheMintStillFailsSiteValidation(): void
    {
        // The counter-direction: the severity split must not become an "only errors gate"
        // filter (option B, refused). An unused band token is a real warning.
        $band = $this->responsiveBand();
        $band['udc']['_tokens'] = ['orphan' => '17px'];
        [$post_id] = $this->create([$band]);
        $diagnostics = _pp_cli_page_diagnostics(pp_get_composition($post_id), $post_id);

        $this->assertContains('udc_unused_band_token', array_column($diagnostics['smells'], 'type'));
        $this->assertSame('warning', $this->byType($diagnostics['smells'])['udc_unused_band_token'][0]['severity']);
        $this->assertTrue(_pp_cli_page_fails_site_validation($diagnostics));
    }

    public function testATruncatedReportCountsItsNotesForTheCountingConsumers(): void
    {
        // The chat undo card renders "N issues" from the tail's `total`; on a truncated
        // report most notes are past the budget, so only the server can count them.
        $findings = [];
        for ($i = 0; $i < 5; $i++) {
            $findings[] = ['type' => 'unknown_prop', 'severity' => 'error', 'message' => 'e', 'index' => $i];
        }
        for ($i = 0; $i < 7; $i++) {
            $findings[] = ['type' => 'udc_token_minted', 'severity' => 'info', 'message' => 'm', 'index' => $i];
        }
        $findings[] = 'not even an array';

        $bounded = _pp_bounded_findings($findings, 7, 3);
        $tail    = end($bounded);

        $this->assertSame('findings_truncated', $tail['type']);
        $this->assertSame(13, $tail['total']);
        $this->assertSame(7, $tail['total_info'], 'every note is counted, including the omitted ones');
        $this->assertSame(0, _pp_count_info_findings([['severity' => 'warning'], 'x', null]));
    }

    public function testOnlyTheMintIsInformational(): void
    {
        // The informational set is exactly one type. A second entry is a gate decision
        // and must come with its own ruling, so widening it has to fail here first.
        $this->assertSame(['udc_token_minted'], pp_informational_finding_types());
    }

    public function testTheDiagnosticsBucketsPartitionTheFindingsBySeverity(): void
    {
        $band = $this->responsiveBand();
        $band['udc']['_tokens'] = ['orphan' => '17px'];
        [$post_id] = $this->create([$band]);
        $composition = pp_get_composition($post_id);
        $diagnostics = _pp_cli_page_diagnostics($composition, $post_id);

        foreach ($diagnostics['info'] as $finding) {
            $this->assertSame('info', $finding['severity']);
        }
        foreach ($diagnostics['smells'] as $finding) {
            $this->assertSame('warning', $finding['severity']);
        }
        $all    = _pp_composition_findings($composition, $post_id);
        $merged = array_merge($diagnostics['errors'], $diagnostics['smells'], $diagnostics['info']);
        $this->assertCount(count($all), $merged, 'nothing is dropped or invented by the split');
        foreach ($all as $finding) {
            $this->assertContains($finding, $merged);
        }
        $this->assertNotSame([], $diagnostics['info'], 'the fixture exercises the info bucket');
        $this->assertNotSame([], $diagnostics['smells'], 'and the warning bucket');
    }
}
