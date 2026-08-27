<?php
/**
 * tests/WriteEnvelopeFindingsTest.php — accepted composition writes report what they wrote (#687).
 *
 * THE FAILURE THIS CLOSES. A composition write could validate, store, return `ok: true`
 * and paint nothing. The pp-eval trap that demonstrated it: `--hero-overlay-bg` on a
 * `split`-layout hero. That slot renders only under `layout: "cover"`, so the value is
 * stored, the version bumps, the envelope says success, and nothing on the page reads it.
 * The #580 `inert_slot` advisory had always named it — but only on surfaces an agent had
 * to opt into (`wp pp check page`, INSPECT, restore's findings, #233). An agent that ran
 * none of them truthfully reported success on a no-op, which is the most corrosive
 * failure class for AI-led maintenance: it presents as success, so nothing prompts
 * recovery.
 *
 * THE CONTRACT (D1 clause 4, ratified 2026-08-16). Every accepted composition-mutating
 * write carries a `findings` key describing the composition it just stored:
 *
 *     write lands ──► pp_get_composition()      the STORED bytes, not the assembled array
 *                        │
 *                        ├─ pp_validate_composition_errors()  severity 'error'
 *                        └─ pp_validate_composition_smells()  severity 'warning' (inert_slot)
 *                        │
 *                     _pp_bounded_findings()     ≤ 100 + one findings_truncated tail
 *                        │
 *                     envelope['findings']       REPORT-ONLY: never blocks, never alters
 *
 * REPORT-ONLY is the whole ruling. Findings are computed AFTER the write, on success
 * only. Rejections keep rejecting exactly as before (and carry no `findings` key at all),
 * the write-rejection path keeps #621's budget of 1, and the stored composition is
 * byte-identical to what the same call stored before this change.
 *
 * WHAT THIS FILE DOES NOT OWN. #654 has since bounded the restore/rollback surfaces at
 * the same budget, so the seam asserted here is no longer "restore is unbounded" but the
 * sharper one it always meant: restore sets its own `findings` and the dispatcher skips a
 * result that already carries the key. Same budget, different mechanism — restore reports
 * on the array it just wrote with no size gate, while this path reads the STORED bytes
 * through _pp_write_findings_for() and applies Addendum #2's 1 MiB gate. Bounds
 * themselves belong to tests/CompositionFindingsBoundsTest.php.
 *
 * Section 14.1 (authoring path): every envelope assertion below goes through the real
 * write surface (pp_execute_action / pp_patch_composition / pp_ai_execute_batch), never a
 * raw `_pp_composition` meta write, because the envelope IS the contract under test.
 */

use PHPUnit\Framework\TestCase;

final class WriteEnvelopeFindingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the in-memory store for test isolation (tests/bootstrap.php:57). Without
        // this the class is order-dependent: a class whose tearDown unsets the store leaves
        // nothing for pp_create_page() to write into, and every fixture here fatals.
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
        // A DATABASE HANDLE, because the #749 batch gate reads the postmeta row and fails
        // closed without one (#833). Production always has one; without it every batch
        // below would be refused before its first step and prove nothing.
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** A hero whose overlay slot is inert: the slot paints only under layout "cover". */
    private function trapPage(): int
    {
        $id = pp_create_page('Inert overlay trap', 'draft');
        pp_update_composition($id, [
            [
                'component' => 'hero',
                'props'     => [
                    'id'        => 'hero-1',
                    'title'     => 'Ship faster',
                    'layout'    => 'split',
                    'image_url' => 'https://example.com/x.png',
                ],
            ],
        ]);

        return $id;
    }

    /** A page whose bands are all clean under current rules. */
    private function cleanPage(string $title = 'Clean page'): int
    {
        $id = pp_create_page($title, 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'One', 'body' => 'Body copy.']],
            ['component' => 'section', 'props' => ['id' => 's2', 'title' => 'Two', 'body' => 'Body copy.']],
        ]);

        return $id;
    }

    private static function findingTypes(array $envelope): array
    {
        return array_column($envelope['findings'], 'type');
    }

    /** Every finding of one type, in report order. */
    private static function findingsOfType(array $envelope, string $type): array
    {
        return array_values(array_filter(
            $envelope['findings'],
            static fn ($f) => $f['type'] === $type
        ));
    }

    /** Every finding of one severity, in report order. */
    private static function findingsOfSeverity(array $envelope, string $severity): array
    {
        return array_values(array_filter(
            $envelope['findings'],
            static fn ($f) => $f['severity'] === $severity
        ));
    }

    // ── 1. THE ACCEPTANCE CRITERION ────────────────────────────────────────────

    /**
     * The pp-eval trap, verbatim: same command, same envelope, `ok: true` PLUS a finding
     * that names the inert slot. This single assertion is why the issue exists — if it
     * ever regresses, an agent can once again report success on a write that paints
     * nothing.
     */
    public function testTheInertSlotTrapReturnsOkTrueAndSaysTheSlotIsDead(): void
    {
        $id = $this->trapPage();

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
        ]);

        $this->assertTrue($result['ok'], 'the write is ACCEPTED — findings never block');
        $this->assertContains('inert_slot', self::findingTypes($result));

        $inert = self::findingsOfType($result, 'inert_slot');
        $this->assertCount(1, $inert);
        $this->assertSame('warning', $inert[0]['severity']);
        $this->assertSame(0, $inert[0]['index'], 'the advisory names the band it belongs to');
        $this->assertStringContainsString('--hero-overlay-bg', $inert[0]['message']);
        $this->assertStringContainsString('layout = "cover"', $inert[0]['message'], 'it names the unmet condition');
        $this->assertStringContainsString('nothing on the page reads it', $inert[0]['message']);
    }

    /**
     * The value really was stored. Without this the test above would pass on a write that
     * silently refused the slot, which is a different (and also wrong) behaviour.
     */
    public function testTheInertValueIsStillStoredExactlyAsAuthored(): void
    {
        $id = $this->trapPage();
        pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
        ]);

        $this->assertSame(
            'rgba(0,0,0,.5)',
            pp_get_composition($id)[0]['style']['--hero-overlay-bg'],
            'report-only: the advisory describes the write, it does not undo it'
        );
    }

    // ── 2. REACH: every composition-mutating action plus create_page ────────────

    public function testUpdateCompositionCarriesFindings(): void
    {
        $id = $this->cleanPage('update_composition reach');
        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [
                ['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'T', 'layout' => 'split']],
            ],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertArrayHasKey('findings', $result);
        $this->assertContains('hero_split_no_media', self::findingTypes($result));
    }

    public function testAddComponentCarriesFindings(): void
    {
        $id = $this->cleanPage('add_component reach');
        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'hero',
            'props'     => ['id' => 'h', 'title' => 'T', 'layout' => 'split'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertContains('hero_split_no_media', self::findingTypes($result));
    }

    public function testUpdateComponentCarriesFindings(): void
    {
        $id = $this->trapPage();
        // Re-assert the same inert slot through the OTHER action that can set style.
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'Renamed'],
            'style'           => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertContains('inert_slot', self::findingTypes($result));
    }

    public function testRemoveComponentCarriesFindings(): void
    {
        $id = pp_create_page('remove_component reach', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'One', 'body' => 'B']],
            ['component' => 'hero',    'props' => ['id' => 'h', 'title' => 'T', 'layout' => 'split']],
        ]);

        $result = pp_execute_action('remove_component', ['post_id' => $id, 'component_index' => 0]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        // The report describes what SURVIVES the removal, and the surviving band's
        // locator has shifted to 0 — findings read the stored composition, not the
        // pre-write one.
        $this->assertContains('hero_split_no_media', self::findingTypes($result));
        $split = self::findingsOfType($result, 'hero_split_no_media');
        $this->assertSame(0, $split[0]['index']);
    }

    public function testReorderComponentsCarriesFindings(): void
    {
        $id = pp_create_page('reorder reach', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'One', 'body' => 'B']],
            ['component' => 'hero',    'props' => ['id' => 'h', 'title' => 'T', 'layout' => 'split']],
        ]);

        $result = pp_execute_action('reorder_components', ['post_id' => $id, 'order' => [1, 0]]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $split = self::findingsOfType($result, 'hero_split_no_media');
        $this->assertCount(1, $split);
        $this->assertSame(0, $split[0]['index'], 'the hero moved to band 0 and the locator moved with it');
    }

    public function testStyleComponentCarriesFindings(): void
    {
        // Covered by the acceptance test above; this pins the KEY's presence on a write
        // whose composition is clean, so "clean" reads as an empty report rather than an
        // absent one.
        $id = $this->cleanPage('style_component clean');
        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-bg' => '#101014'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertArrayHasKey('findings', $result);
        $this->assertSame([], $result['findings'], 'a clean composition reports an EMPTY list, not a missing key');
    }

    public function testCreatePageCarriesFindingsForTheCompositionItSeeded(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Seeded with an inert slot',
            'composition' => [
                [
                    'component' => 'hero',
                    'props'     => ['id' => 'h', 'title' => 'T', 'layout' => 'split', 'image_url' => 'https://example.com/x.png'],
                    'style'     => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
                ],
            ],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertContains('inert_slot', self::findingTypes($result));
    }

    public function testCreatePageWithNoCompositionReportsAnEmptyList(): void
    {
        $result = pp_execute_action('create_page', ['title' => 'Empty page']);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([], $result['findings']);
    }

    /**
     * REGRESSION: the report must describe the page THIS WRITE LANDED ON.
     *
     * `create_page` declares no `post_id` param, and pp_validate_action() does not reject
     * undeclared params — so a caller can hand it a stray `post_id`. Resolving the report's
     * page from params first (the order `composition_version` uses, #404) made that call
     * return a brand-new empty page's envelope carrying an ENTIRELY DIFFERENT page's
     * diagnostics. An envelope that names the wrong page is worse than one that says
     * nothing, and it is the precise failure class #687 exists to close, so `findings`
     * resolves from the result target first.
     *
     * The `composition_version` half of that defect is pre-existing #404 behaviour and is
     * deliberately NOT changed here; it is asserted below exactly as it behaves today so
     * the divergence is recorded rather than discovered later.
     */
    public function testCreatePageReportsThePageItCreatedNotAStrayPostIdParam(): void
    {
        $other = pp_create_page('Someone else\'s page', 'draft');
        pp_update_composition($other, [[
            'component' => 'hero',
            'props'     => ['id' => 'h', 'title' => 'T', 'layout' => 'split', 'image_url' => 'https://example.com/x.png'],
            'style'     => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
        ]]);
        $this->assertContains(
            'inert_slot',
            array_column(_pp_composition_findings(pp_get_composition($other)), 'type'),
            'precondition: the OTHER page has a finding that must not leak'
        );

        $result = pp_execute_action('create_page', ['title' => 'Fresh page', 'post_id' => $other]);
        $created = $result['target']['post_id'];

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertNotSame($other, $created, 'precondition: a new page really was created');
        $this->assertSame([], pp_get_composition($created), 'and it is empty');
        $this->assertSame([], $result['findings'], 'so its report is empty, not the other page\'s');

        // Recorded, not fixed: composition_version still resolves params-first (#404).
        $this->assertSame(
            pp_get_composition_marker($other)['version'],
            $result['composition_version'],
            'pre-existing #404 behaviour, left untouched by this change'
        );
    }

    /**
     * `operate patch` is the eighth surface named by the ruling, and it gets the key for
     * free by routing through pp_execute_action('update_component'). Pinned so a future
     * refactor that gives patch its own envelope has to notice.
     */
    public function testOperatePatchCarriesFindings(): void
    {
        $id = $this->trapPage();
        pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
        ]);

        $result = pp_patch_composition($id, 'hero.title', 'Patched');

        $this->assertIsArray($result);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertContains('inert_slot', self::findingTypes($result));
    }

    // ── 3. THE BOUNDARY: what does NOT get the key ──────────────────────────────

    /**
     * The key is scoped to the composition surface. A token/menu/page-lifecycle action
     * shares _pp_action_result() but has no composition to report on, and attaching an
     * empty list there would tell every consumer to look for a report that can never say
     * anything.
     */
    public function testNonCompositionActionsCarryNoFindingsKey(): void
    {
        $id = $this->cleanPage('lifecycle actions');

        foreach ([
            ['update_page_title', ['post_id' => $id, 'title' => 'Renamed']],
            ['publish_page',      ['post_id' => $id]],
        ] as [$name, $params]) {
            $result = pp_execute_action($name, $params);
            $this->assertTrue($result['ok'], $result['error'] ?? '');
            $this->assertArrayNotHasKey('findings', $result, "$name owns no composition report");
        }
    }

    /**
     * A REJECTED write reports why it was rejected and nothing else. Findings are computed
     * only on success, so a rejection cannot pay for a full two-engine pass, and the
     * rejection envelope keeps exactly the shape #642 gave it.
     */
    public function testARejectedWriteCarriesNoFindingsAndKeepsItsRejectionShape(): void
    {
        $id = pp_create_page('Rejected write', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'One', 'body' => 'B']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['no_such_prop' => 'x'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayNotHasKey('findings', $result, 'rejections keep rejecting exactly as today');
        $this->assertArrayHasKey('index', $result, '#642 rejection locator is untouched');
        $this->assertSame('unknown_prop', $result['error_code']);
    }

    /**
     * The write-rejection path keeps #621's budget of 1: ONE message, not a report. Three
     * undeclared props are submitted and the rejection names only the first — that is the
     * budget's one observable effect from outside the validator, and asserting merely that
     * `error` is a non-empty string would pass no matter what the budget did.
     */
    public function testTheRejectionPathStillReturnsExactlyOneMessage(): void
    {
        $id = pp_create_page('Rejection budget', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'One', 'body' => 'B']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['aa' => 1, 'bb' => 2, 'cc' => 3],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayNotHasKey('findings', $result, 'a rejection is a message, never a report');
        $this->assertStringContainsString('"aa"', $result['error'], 'the first offending prop');
        $this->assertStringNotContainsString('"bb"', $result['error'], 'and only the first');
        $this->assertStringNotContainsString('"cc"', $result['error']);
    }

    // ── 4. REPORT-ONLY: the stored bytes never move ─────────────────────────────

    /**
     * The load-bearing invariant. A write onto a composition that produces a LONG report
     * stores exactly the same bytes as one that produces none — findings are read off the
     * result, never fed back into it.
     */
    public function testStoredBytesAreIdenticalWhetherOrNotTheReportIsLong(): void
    {
        $noisy = pp_create_page('Noisy page', 'draft');
        pp_update_composition($noisy, [
            ['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'T', 'layout' => 'split']],
        ]);
        $quiet = pp_create_page('Quiet page', 'draft');
        pp_update_composition($quiet, [
            ['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'T', 'layout' => 'centered']],
        ]);

        $noisyResult = pp_execute_action('update_component', [
            'post_id' => $noisy, 'component_index' => 0, 'props' => ['title' => 'Edited'],
        ]);
        $quietResult = pp_execute_action('update_component', [
            'post_id' => $quiet, 'component_index' => 0, 'props' => ['title' => 'Edited'],
        ]);

        $this->assertNotSame([], $noisyResult['findings']);
        $this->assertSame([], $quietResult['findings']);

        $stored = pp_get_composition($noisy);
        $this->assertSame('Edited', $stored[0]['props']['title']);
        $this->assertSame('split', $stored[0]['props']['layout'], 'nothing about the band was repaired');
        $this->assertArrayNotHasKey('findings', $stored[0], 'findings are envelope-only, never persisted');
    }

    /**
     * An item-scoped action legitimately accepts a write onto a page whose OTHER bands
     * current rules reject (style_component validates no props at all). Reporting only the
     * advisories there would hide the louder problem, so error-severity findings ride
     * along too.
     */
    public function testAnItemScopedWriteReportsErrorsOnBandsItNeverTouched(): void
    {
        $id = pp_create_page('Stale sibling band', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'One', 'body' => 'B']],
            ['component' => 'section', 'props' => ['id' => 's2', 'title' => 'Two', 'body' => 'B', 'retired_key' => 'x']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-bg' => '#101014'],
        ]);

        $this->assertTrue($result['ok'], 'style_component validates only its own slots');
        $errors = self::findingsOfSeverity($result, 'error');
        $this->assertNotSame([], $errors, 'the untouched broken band is still reported');
        $this->assertSame(1, $errors[0]['index'], 'and it names the band that owns it, not the one written');
    }

    // ── 5. THE BUDGET (D1 clause 3, on this surface only) ───────────────────────

    /** A composition whose report is longer than the budget. */
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

    public function testALongReportIsCappedAndClosedByOneTruncationFinding(): void
    {
        $id = $this->pathologicalPage();
        $true_total = count(_pp_composition_findings(pp_get_composition($id)));
        $this->assertGreaterThan(
            PP_WRITE_FINDINGS_BUDGET,
            $true_total,
            'precondition: this fixture must actually exceed the budget'
        );

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-bg' => '#101014'],
        ]);

        $this->assertTrue($result['ok'], 'a long report never blocks the write');
        $this->assertCount(PP_WRITE_FINDINGS_BUDGET + 1, $result['findings'], '100 findings plus one tail');

        $tail = end($result['findings']);
        $this->assertSame('findings_truncated', $tail['type'], 'the tail closes the list');
        $this->assertSame('warning', $tail['severity']);
        $this->assertNull($tail['index'], 'truncation belongs to no single band');
        $this->assertStringContainsString((string) $true_total, $tail['message'], 'the tail states the TRUE total');
        $this->assertStringContainsString(
            (string) ($true_total - PP_WRITE_FINDINGS_BUDGET),
            $tail['message'],
            'and how many it omitted'
        );

        // Only ONE tail, and only at the end.
        $this->assertSame(
            1,
            count(array_filter($result['findings'], static fn ($f) => $f['type'] === 'findings_truncated'))
        );
    }

    /**
     * The truncation tail is a real finding, not a special case a consumer has to know
     * about: it leads with the same four keys as every other entry, in the same order, so
     * anything that renders findings renders it without a branch.
     *
     * #654 added ONE key after those four — `total`, the true finding count as an integer —
     * and the assertion is written as "the ordinary four, then exactly total" rather than
     * relaxed to a subset check. A subset check would let a fifth key appear unnoticed, and
     * the whole value of this pin is that a consumer can treat the tail as an ordinary
     * finding. `total` earns its place because a consumer that renders a COUNT cannot parse
     * the message and must not count the delivered array (the chat undo card did exactly
     * that and reported 101 for 20,001); every other consumer ignores it.
     */
    public function testTheTruncationFindingHasTheOrdinaryFindingShape(): void
    {
        $id = $this->pathologicalPage();
        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--section-bg' => '#101014'],
        ]);
        $tail = end($result['findings']);

        $this->assertSame(['type', 'severity', 'message', 'index', 'total'], array_keys($tail));
        $this->assertSame(
            ['type', 'severity', 'message', 'index'],
            array_slice(array_keys($tail), 0, 4),
            'the ordinary four come first and in order, so a generic renderer needs no branch'
        );
        $this->assertIsInt($tail['total']);
    }

    // ── 5b. The bounding helper, directly at its boundaries ─────────────────────

    private static function fakeFindings(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['type' => 'unknown_prop', 'severity' => 'error', 'message' => "m$i", 'index' => $i];
        }

        return $out;
    }

    public function testAReportAtExactlyTheBudgetIsNotTruncated(): void
    {
        $bounded = _pp_bounded_findings(self::fakeFindings(PP_WRITE_FINDINGS_BUDGET));

        $this->assertCount(PP_WRITE_FINDINGS_BUDGET, $bounded);
        $this->assertSame([], array_filter($bounded, static fn ($f) => $f['type'] === 'findings_truncated'));
    }

    public function testOneOverTheBudgetTruncatesToBudgetPlusTail(): void
    {
        $bounded = _pp_bounded_findings(self::fakeFindings(PP_WRITE_FINDINGS_BUDGET + 1));

        $this->assertCount(PP_WRITE_FINDINGS_BUDGET + 1, $bounded);
        $tail = end($bounded);
        $this->assertSame('findings_truncated', $tail['type']);
        $this->assertStringContainsString((string) (PP_WRITE_FINDINGS_BUDGET + 1), $tail['message']);
        $this->assertStringContainsString(' 1 more', $tail['message'], 'one omitted, stated as one');
    }

    public function testAShortReportPassesThroughUnchanged(): void
    {
        $findings = self::fakeFindings(3);
        $this->assertSame($findings, _pp_bounded_findings($findings));
        $this->assertSame([], _pp_bounded_findings([]));
    }

    /**
     * The `$budget` parameter is a real seam, not unexercised generality — it is what let
     * #654 bound the restore/rollback surfaces through this same helper instead of coining
     * a second cap. Exercised at a small budget so a boundary bug cannot hide behind the
     * one production value.
     */
    public function testAnExplicitSmallBudgetTruncatesTheSameWay(): void
    {
        $bounded = _pp_bounded_findings(self::fakeFindings(5), null, 2);

        $this->assertCount(3, $bounded, '2 kept plus the tail');
        $this->assertSame(['m0', 'm1'], array_column(array_slice($bounded, 0, 2), 'message'));
        $tail = end($bounded);
        $this->assertSame('findings_truncated', $tail['type']);
        $this->assertStringContainsString('Showing 2 of 5 findings and 3 more', $tail['message']);
    }

    /**
     * A negative budget would make array_slice() cut from the END of the report and print
     * "Showing -1 of N". Clamped to 0, which yields the honest degenerate answer: keep
     * nothing, say everything was omitted.
     */
    public function testANegativeBudgetIsClampedRatherThanSlicingFromTheEnd(): void
    {
        $bounded = _pp_bounded_findings(self::fakeFindings(4), null, -1);

        $this->assertCount(1, $bounded);
        $this->assertSame('findings_truncated', $bounded[0]['type']);
        $this->assertStringContainsString('Showing 0 of 4 findings and 4 more', $bounded[0]['message']);
    }

    /**
     * The truncation tail names the page, so it is a command an operator can paste. Every
     * production caller now has a page to name — #654's aggregating rollback bounds PER
     * POST precisely so it does too — leaving the placeholder form for direct and unit
     * callers that genuinely have none.
     */
    public function testTheTruncationTailNamesThePageWhenOneOwnsTheReport(): void
    {
        $id     = $this->pathologicalPage();
        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--section-bg' => '#101014'],
        ]);
        $tail = end($result['findings']);

        $this->assertStringContainsString('wp pp check page --post_id=' . $id, $tail['message']);
        $this->assertStringNotContainsString('<id>', $tail['message']);
        $this->assertStringContainsString(
            '--post_id=<id>',
            _pp_bounded_findings(self::fakeFindings(200))[PP_WRITE_FINDINGS_BUDGET]['message'],
            'with no page in hand the tail stays a template'
        );
    }

    /**
     * THE ORDERING CONSEQUENCE, pinned deliberately rather than left implicit.
     *
     * The ratified budget is a FLAT per-report cap, not a per-severity quota, and
     * _pp_composition_findings() emits errors before warnings. So on a composition with
     * more than PP_WRITE_FINDINGS_BUDGET error-severity findings, the advisories truncate
     * away — inert_slot included, the very advisory #687 exists to surface.
     *
     * This is a real limit of the ratified shape, reachable only on a composition that
     * already has 100+ rule violations (which no validated write path can author). It is
     * asserted here so it lives in CI as a visible tradeoff: a future change to interleave
     * by severity, or to give advisories their own quota, has to come here and say so.
     */
    public function testAdvisoriesTruncateBehindAHundredErrorsAndTheTailSaysSo(): void
    {
        $id = pp_create_page('Errors drown the advisory', 'draft');
        $composition = [[
            'component' => 'hero',
            'props'     => ['id' => 'h', 'title' => 'T', 'layout' => 'split', 'image_url' => 'https://example.com/x.png'],
        ]];
        for ($i = 0; $i < 40; $i++) {
            $composition[] = ['component' => 'section', 'props' => [
                'id' => "s$i", 'title' => "T$i", 'body' => 'B',
                'zzA' => 1, 'zzB' => 2, 'zzC' => 3, 'zzD' => 4,
            ]];
        }
        pp_update_composition($id, $composition);

        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertContains(
            'inert_slot',
            array_column(_pp_composition_findings(pp_get_composition($id)), 'type'),
            'precondition: the engines DO see the inert slot'
        );
        $this->assertNotContains('inert_slot', self::findingTypes($result),
            'but it falls behind the flat cap on a page with 100+ errors');
        $this->assertContains('findings_truncated', self::findingTypes($result),
            'and the report says out loud that it is incomplete');
    }

    public function testTheKeptFindingsAreThePrefixOfTheReportInOrder(): void
    {
        $findings = self::fakeFindings(PP_WRITE_FINDINGS_BUDGET + 5);
        $bounded  = _pp_bounded_findings($findings);

        $this->assertSame(
            array_slice($findings, 0, PP_WRITE_FINDINGS_BUDGET),
            array_slice($bounded, 0, PP_WRITE_FINDINGS_BUDGET),
            'truncation drops the tail of the list, it does not reshuffle it'
        );
    }

    // ── 5c. THE AVAILABILITY GATE (D1 Addendum #2) ──────────────────────────────

    /**
     * A composition of a given stored size, carrying a real finding (the inert hero
     * overlay) so the gate can be shown to suppress CONTENT, not an empty report.
     *
     * @param int $bands  Section bands of ~11 KB each, on top of the hero.
     */
    private function bulkyPage(int $bands): int
    {
        $composition = [[
            'component' => 'hero',
            'props'     => ['id' => 'h', 'title' => 'T', 'layout' => 'split', 'image_url' => 'https://example.com/x.png'],
            'style'     => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
        ]];
        $body = str_repeat('lorem ipsum dolor sit amet ', 420);
        for ($i = 0; $i < $bands; $i++) {
            $composition[] = ['component' => 'section', 'props' => [
                'id' => "s$i", 'title' => "T$i", 'body' => $body,
            ]];
        }
        $id = pp_create_page('Bulky page', 'draft');
        pp_update_composition($id, $composition);

        return $id;
    }

    private static function storedBytes(int $post_id): int
    {
        return strlen((string) get_post_meta($post_id, '_pp_composition', true));
    }

    /**
     * OVER THE THRESHOLD: the write lands, the envelope is small, and it says out loud
     * that it diagnosed nothing.
     *
     * This is the availability half of #687 (D1 Addendum #2). Both engines materialise
     * every finding before any budget can slice it, at roughly 28 MB per stored MB, and
     * they run AFTER the write has persisted — so on a large enough page the caller used
     * to get an OOM fatal for a change that had already happened, with no envelope, no
     * touched-post record and no refreshed CAS baseline. An OOM is not catchable, so the
     * only fix is to not start.
     */
    public function testAnOversizedCompositionSkipsTheReportInsteadOfRiskingTheWrite(): void
    {
        $id = $this->bulkyPage(100);
        $bytes = self::storedBytes($id);
        $this->assertGreaterThan(
            PP_WRITE_FINDINGS_MAX_STORED_BYTES,
            $bytes,
            'precondition: this fixture must actually exceed the gate'
        );

        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--hero-overlay-bg' => 'rgba(0,0,0,.6)'],
        ]);

        // The write is untouched by the gate: it landed, exactly as authored.
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $stored = pp_get_composition($id);
        $this->assertCount(101, $stored);
        $this->assertSame('rgba(0,0,0,.6)', $stored[0]['style']['--hero-overlay-bg']);

        // Exactly one entry, and it is the skip.
        $this->assertCount(1, $result['findings']);
        $this->assertSame('findings_skipped', $result['findings'][0]['type']);
        $this->assertSame('warning', $result['findings'][0]['severity']);
        $this->assertNull($result['findings'][0]['index']);
        $this->assertSame(
            ['type', 'severity', 'message', 'index'],
            array_keys($result['findings'][0]),
            'same shape as every other finding — no consumer needs a special case'
        );
    }

    /** The skip states the real numbers and the exact next command. */
    public function testTheSkipFindingNamesTheSizeTheLimitAndTheCommandToRun(): void
    {
        $id      = $this->bulkyPage(100);
        $bytes   = self::storedBytes($id);
        $result  = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--hero-overlay-bg' => 'rgba(0,0,0,.6)'],
        ]);
        $message = $result['findings'][0]['message'];

        $this->assertStringContainsString((string) $bytes, $message, 'the real stored size');
        $this->assertStringContainsString((string) PP_WRITE_FINDINGS_MAX_STORED_BYTES, $message, 'the limit it crossed');
        $this->assertStringContainsString('wp pp check page --post_id=' . $id, $message, 'the exact next command');
        $this->assertStringContainsString('Nothing here says the composition is healthy', $message,
            'a skip must never be readable as a clean bill of health');
    }

    /**
     * THE GATE SUPPRESSED REAL CONTENT, which is what proves it ran BEFORE the engines
     * rather than after them. The same page diagnosed directly yields a genuine report
     * including the inert hero overlay; the envelope carries none of it.
     *
     * Content is the honest observable here. PHP gives no way to assert "this function was
     * not called" without a mocking layer this suite does not use, so the assertion is that
     * the engines' OUTPUT is absent while being demonstrably available.
     */
    public function testTheSkipSuppressesAReportThatWouldOtherwiseHaveContent(): void
    {
        $id = $this->bulkyPage(100);

        $direct = _pp_composition_findings(pp_get_composition($id));
        $this->assertContains('inert_slot', array_column($direct, 'type'),
            'precondition: the engines DO have something to say about this page');

        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--hero-overlay-bg' => 'rgba(0,0,0,.6)'],
        ]);

        $this->assertSame(['findings_skipped'], self::findingTypes($result));
        $this->assertNotContains('inert_slot', self::findingTypes($result));
        $this->assertLessThan(
            2048,
            strlen((string) json_encode($result)),
            'the whole envelope stays small — that is the point of skipping'
        );
    }

    /**
     * UNDER THE THRESHOLD: nothing changes. A page just below the limit still gets the
     * exact report, so the gate cannot quietly degrade pages an operator actually authors
     * (the realistic six-band composition this gate measured stores about 5 KB).
     */
    public function testACompositionUnderTheThresholdStillGetsTheExactReport(): void
    {
        $id    = $this->bulkyPage(80);
        $bytes = self::storedBytes($id);
        $this->assertLessThanOrEqual(
            PP_WRITE_FINDINGS_MAX_STORED_BYTES,
            $bytes,
            'precondition: this fixture must sit under the gate'
        );

        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--hero-overlay-bg' => 'rgba(0,0,0,.6)'],
        ]);

        $this->assertNotContains('findings_skipped', self::findingTypes($result));
        $this->assertContains('inert_slot', self::findingTypes($result));
        $this->assertSame(
            _pp_bounded_findings(_pp_composition_findings(pp_get_composition($id)), $id),
            $result['findings'],
            'byte-for-byte the report it always was'
        );
    }

    /** The gate is a strict `>`: a composition AT the limit is still diagnosed. */
    public function testTheGateComparesStrictlyGreaterThanTheLimit(): void
    {
        $id = $this->cleanPage('At the limit');
        $this->assertLessThan(PP_WRITE_FINDINGS_MAX_STORED_BYTES, self::storedBytes($id));

        $this->assertSame(
            _pp_bounded_findings(_pp_composition_findings(pp_get_composition($id)), $id),
            _pp_write_findings_for($id),
            'an ordinary page is never gated'
        );
    }

    // ── 6. SEAMS: what this change must NOT reach ───────────────────────────────

    /**
     * restore_composition builds its own `findings` (#233), so the dispatcher must leave an
     * existing key alone. #654 has since bounded that report too, at the same budget — so
     * the seam is no longer about SIZE but about MECHANISM: restore reports on what it
     * wrote with no size gate, this path reads the stored bytes through the gated helper.
     * Leaving the key alone is also what stops a report being bounded twice.
     */
    public function testRestoreCompositionSetsItsOwnFindingsRatherThanInheritingTheWritePath(): void
    {
        $id = $this->pathologicalPage();
        // Give the ring a prior state to restore, then restore it.
        pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--section-bg' => '#101014'],
        ]);

        $result = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');

        // The SEAM this file owns: restore sets `findings` itself and the dispatcher skips
        // a result that already carries the key. #654 later gave restore the same COUNT
        // budget, so the two now agree on size — but they are still not the same mechanism,
        // and that is the part this asserts. The write path routes through
        // _pp_write_findings_for(), which reads the STORED composition and applies
        // Addendum #2's 1 MiB availability gate; restore reports on the array it just
        // wrote, with no size gate, because #233 says an undo tells you what it brought
        // back. Bounds live in CompositionFindingsBoundsTest.
        $this->assertSame(
            _pp_bounded_findings(_pp_composition_findings($this->storedComposition($id)), $id),
            $result['findings'],
            'restore reports its own composition through the shared bounder, not the gated write helper'
        );
        $this->assertArrayNotHasKey(
            'findings_skipped',
            array_flip(self::findingTypes($result)),
            'restore never emits the write path\'s size-gate skip entry'
        );
    }

    /** The stored composition, read the way the write path reads it. */
    private function storedComposition(int $post_id): array
    {
        return pp_get_composition($post_id);
    }

    /**
     * The dispatcher checks for the KEY, not for truthiness. A restore of a CLEAN
     * composition legitimately reports an empty list; re-deriving it would be harmless
     * today and wrong the moment the two reports differ.
     */
    public function testAnEmptyRestoreReportIsNotRederived(): void
    {
        $id = $this->cleanPage('Clean restore');
        pp_execute_action('update_component', [
            'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'Edited'],
        ]);

        $result = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([], $result['findings']);
    }

    // ── 7. INHERITED VOCABULARY (iterations 7-8) ────────────────────────────────

    /**
     * NO SECOND VOCABULARY. The envelope's report is exactly what the shared engines
     * produce for the stored composition, bounded — nothing is re-rendered, re-worded or
     * re-located on the way out. That is the whole reason the locator work landed in
     * iterations 7-8 (#634/#642/#650/#652) is inherited here for free rather than
     * reimplemented: change a message there and this surface changes with it.
     */
    public function testTheEnvelopeReportIsTheSharedEngineReportVerbatim(): void
    {
        $id = $this->trapPage();
        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
        ]);

        $this->assertSame(
            _pp_bounded_findings(_pp_composition_findings(pp_get_composition($id)), $id),
            $result['findings'],
            'the envelope must not hold a re-derived view of the rules'
        );
    }

    /**
     * THE KEY-FORM LOCATOR IS UNREACHABLE FROM THIS SURFACE, and that is a property of the
     * READER, not of this change. #652's `key "N"` form exists for an object-shaped
     * composition — but pp_get_composition_result() (lib/wp.php:391) classifies a non-list
     * stored row as `unexpected_shape` and hands back an EMPTY composition, so the engines
     * never see the object form through a write path, and the composition precondition
     * refuses the write outright. The key form stays reachable where it was designed to be:
     * restore's history-ring snapshots, which decode their own meta.
     *
     * Pinned so nobody looks for `key "N"` in a write envelope and concludes the
     * inheritance is broken.
     */
    public function testAnObjectShapedStoredRowIsRefusedBeforeAnyReportIsBuilt(): void
    {
        $id = pp_create_page('Object-shaped composition', 'draft');
        pp_update_composition($id, [
            '1' => ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'One', 'body' => 'B']],
            '0' => ['props' => ['id' => 's2']],
        ]);

        $this->assertSame([], pp_get_composition($id), 'the reader refuses a non-list row');

        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--section-bg' => '#101014'],
        ]);

        $this->assertFalse($result['ok'], 'the write is refused, so there is no accepted envelope to report on');
        $this->assertArrayNotHasKey('findings', $result);
    }

    // ── 8. CONSUMERS ────────────────────────────────────────────────────────────

    /**
     * Batch execution: every step's envelope carries its own report, so a multi-step batch
     * says which step the page was in that state FROM, rather than collapsing to one
     * page-level verdict at the end.
     *
     * Read the contract precisely, because it is easy to over-read: each report describes
     * the WHOLE stored composition as it stands after THAT step. So a problem introduced in
     * step 2 is absent from step 1 and present from step 2 onward — the transition names
     * the culprit, not the presence. A problem that was already on the page before the
     * batch appears on every step.
     */
    public function testEachBatchStepCarriesItsOwnFindings(): void
    {
        $id = $this->trapPage();

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'Step one'],
            ]],
            ['type' => 'action', 'name' => 'style_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'style' => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
            ]],
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'Step three'],
            ]],
        ]);

        $this->assertTrue($batch['ok'], 'precondition: the batch lands');
        $this->assertNotContains('inert_slot', self::findingTypes($batch['steps'][0]),
            'the slot is not inert yet');
        $this->assertContains('inert_slot', self::findingTypes($batch['steps'][1]),
            'the step that made it inert is where the report first says so');
        $this->assertContains('inert_slot', self::findingTypes($batch['steps'][2]),
            'and it STAYS reported: each step describes the whole composition, not its own delta');
    }

    /**
     * The CLI prints the envelope verbatim, so `findings` reaches an operator with no CLI
     * change at all. Pinned as a JSON round-trip because that encode is the actual
     * transport (lib/cli.php:641).
     */
    public function testTheEnvelopeSurvivesTheCliJsonEncode(): void
    {
        $id = $this->trapPage();
        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
        ]);

        $decoded = json_decode(
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            true
        );

        $this->assertNotNull($decoded, 'the report must be JSON-encodable — it is the CLI transport');
        // By membership, not by position: the engines guarantee errors before warnings and
        // nothing finer, so pinning an offset here would fail on an unrelated new smell.
        $this->assertContains('inert_slot', array_column($decoded['findings'], 'type'));
    }

    /**
     * The chat single-execute surface. It returns the envelope WHOLE on success and injects
     * its own `validation` key beside it, so this pins both halves: the report arrives, and
     * it does not collide with the key the handler already owns.
     *
     * What it deliberately does NOT pin: any chat UI rendering. assets/js/pp-ai-chat.js
     * reads `findings` only inside the undo card's own restore_composition fetch, so an
     * ordinary write's report reaches the browser and is not displayed. Rendering it is
     * #655's axis.
     */
    public function testTheChatSingleExecuteResponseCarriesFindingsBesideValidation(): void
    {
        $id = $this->trapPage();
        $version = pp_get_composition_marker($id)['version'];

        $response = _pp_ai_execute_response([
            'type'   => 'action',
            'name'   => 'style_component',
            'params' => [
                'post_id'          => $id,
                'component_index'  => 0,
                'style'            => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
                'expected_version' => $version,
            ],
        ]);

        $this->assertTrue($response['ok'], 'precondition: the chat write lands');
        $this->assertContains('inert_slot', array_column($response['data']['findings'], 'type'));
        $this->assertArrayHasKey('validation', $response['data'], 'the handler key is untouched');
    }

    /**
     * A ROLLED-BACK BATCH keeps each accepted step's report, and those reports describe
     * compositions that no longer exist — the same hazard the docs already record for a
     * rejected step's `index`. Pinned so the semantics cannot flip silently, and mirrored
     * in docs/reference-apply-cli.md.
     */
    public function testARolledBackBatchStillCarriesTheStepReportsItAlreadyBuilt(): void
    {
        $id = $this->trapPage();

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'style_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'style' => ['--hero-overlay-bg' => 'rgba(0,0,0,.5)'],
            ]],
            ['type' => 'action', 'name' => 'update_component', 'params' => [
                'post_id' => $id, 'component_index' => 0, 'props' => ['no_such_prop' => 'x'],
            ]],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back'], 'precondition: step 2 fails and step 1 is reverted');
        $this->assertContains('inert_slot', self::findingTypes($batch['steps'][0]),
            'step 1 keeps the report it was handed');
        $this->assertArrayNotHasKey('style', pp_get_composition($id)[0],
            'while the composition it described has been rolled back out of existence');
    }

    /**
     * THE BYTE AXIS IS REACHABLE THROUGH ORDINARY VALIDATED CALLS, which is the whole
     * reason _pp_bounded_findings()'s docblock refuses to describe it as a raw-meta-write
     * problem.
     *
     * `add_component` validates only the item it adds, so N calls carrying the SAME
     * authored `props.id` are every one of them accepted. `duplicate_component_id` then
     * names every colliding index in a SINGLE message, so one entry grows with the band
     * count and the 100-ENTRY cap cannot touch it: "bounded at 100" is not "bounded in
     * bytes". Pinned so the comfortable version of that claim cannot come back.
     */
    public function testTheCapBoundsEntriesNotBytesAndPlainAddComponentCallsProveIt(): void
    {
        $id = pp_create_page('Colliding ids', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 'same', 'title' => 'T', 'body' => 'B']],
        ]);

        for ($i = 0; $i < 60; $i++) {
            $added = pp_execute_action('add_component', [
                'post_id'   => $id,
                'component' => 'section',
                'props'     => ['id' => 'same', 'title' => "T$i", 'body' => 'B'],
            ]);
            $this->assertTrue($added['ok'], 'add_component validates only the item it adds, so a colliding id is accepted');
        }

        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--section-bg' => '#101014'],
        ]);
        $this->assertTrue($result['ok']);

        $dupes = self::findingsOfType($result, 'duplicate_component_id');
        $this->assertNotSame([], $dupes);
        $longest = max(array_map('strlen', array_column($dupes, 'message')));
        $this->assertGreaterThan(
            300,
            $longest,
            'one entry, length growing with band count — no per-entry budget bounds this'
        );
        $this->assertLessThanOrEqual(
            PP_WRITE_FINDINGS_BUDGET + 1,
            count($result['findings']),
            'the ENTRY cap still holds; it is the byte size it does not govern'
        );
    }

    /**
     * `index: null` from a REAL rule on an accepted write, not just from the synthetic
     * truncation tail. The docs promise int|null; a consumer that assumed an integer would
     * break on the first duplicate-id page, which style_component accepts happily because
     * it validates no props at all.
     */
    public function testAnAcceptedWriteCanReportACrossBandFindingWithNoLocator(): void
    {
        $id = pp_create_page('Duplicate ids', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['id' => 'dupe', 'title' => 'One', 'body' => 'B']],
            ['component' => 'section', 'props' => ['id' => 'dupe', 'title' => 'Two', 'body' => 'B']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id' => $id, 'component_index' => 0, 'style' => ['--section-bg' => '#101014'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $dupes = self::findingsOfType($result, 'duplicate_component_id');
        $this->assertNotSame([], $dupes);
        $this->assertContains(null, array_column($dupes, 'index'),
            'a cross-band rule owns no band and says so rather than fabricating one');
    }
}
