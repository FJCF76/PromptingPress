<?php
/**
 * tests/UdcTruthSpineTest.php
 *
 * THE TRUTH SPINE, OVER `udc` (BUILD-SPEC §2, §3.6 — slice deliverable 5).
 *
 * The spine is the half of this theme that is not about styling at all: a write
 * is validated before it lands, an operator sees what they are approving, a
 * concurrent edit is refused rather than silently overwritten, and a landed write
 * can be taken back. v1 gave those guarantees to `props` and to the `style` map.
 * The whole point of putting `udc` INSIDE the composition value — rather than in
 * a site-level store — is that it inherits all four for free.
 *
 * "For free" is a claim, so this file tests it: a real write through the real
 * authoring surface, carrying a real `udc` map, through every station of the
 * spine. Every assertion is red-proven — each one was watched to fail against the
 * behaviour it pins before the behaviour existed.
 *
 * ── SECTION 14.1, THE AUTHORING-PATH MANDATE ────────────────────────────────
 * Every write below goes through pp_validate_action()/pp_execute_action(), never
 * a raw `_pp_composition` meta write. That rule exists because raw-meta seeding
 * bypasses the validator entirely: a udc contract that only ever saw hand-built
 * fixtures would look correct while refusing every real write.
 */

use PHPUnit\Framework\TestCase;

final class UdcTruthSpineTest extends TestCase
{
    /** The #901 brand card, as a real author would send it. */
    private function brandUdc(): array
    {
        return [
            'card'  => [
                'background' => ['fill' => '#ffffff'],
                'border'     => ['width' => '1px', 'style' => 'solid', 'color' => '#e6e6e6'],
                'shadow'     => ['box' => 'none'],
            ],
            'quote' => ['typography' => [
                'family' => '@font-heading',
                'style'  => 'italic',
                'size'   => ['d' => '19px', 'p' => '17px'],
            ]],
            'attribution' => [
                'border'  => ['width-top' => '1px', 'style-top' => 'solid', 'color-top' => '#e6e6e6'],
                'spacing' => ['padding-top' => '1rem'],
            ],
        ];
    }

    private function band(array $udc): array
    {
        return [
            'component' => 'testimonials',
            'props'     => ['items' => [['quote' => 'They shipped in six weeks.', 'author' => 'Ada Lovelace', 'role' => 'CTO', 'company' => 'Analytical']]],
            'udc'       => $udc,
        ];
    }

    private function seed(string $title, array $composition): int
    {
        $id = pp_create_page($title);
        pp_update_composition($id, $composition);
        return $id;
    }

    // ── 1. VALIDATE ─────────────────────────────────────────────────────────

    public function testARealWriteCarryingUdcValidatesThroughTheAuthoringSurface(): void
    {
        $valid = pp_validate_action('create_page', [
            'title'       => 'Brand quotes',
            'composition' => [$this->band($this->brandUdc())],
        ]);
        $this->assertTrue($valid, 'the #901 brand card must be expressible through the real write path');
    }

    /**
     * THE GATE THAT HAD TO BE BUILT. Before v2 nothing in the validator iterated a
     * composition item's own top-level keys, so an item carrying `udc` was
     * accepted, stored and ignored — ok:true over a write that changed nothing,
     * the class the #147 and #643 gates close one level down.
     */
    public function testAMisspelledRoleIsRefusedAtWriteInsteadOfStoredAndIgnored(): void
    {
        $error = pp_validate_action('create_page', [
            'title'       => 'Typo',
            'composition' => [$this->band(['quotte' => ['typography' => ['style' => 'italic']]])],
        ]);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('unknown_udc_role', $error->get_error_code());
        // And the refusal names the BAND, so an operator with a ten-band page knows
        // which one to fix (§3.6).
        $this->assertMatchesRegularExpression('/Component \d+ \("testimonials"\)/', $error->get_error_message());
    }

    public function testARefusedUdcWriteStoresNothingAtAll(): void
    {
        $composition = [$this->band(['quote' => ['typography' => ['size' => 'enormous']]])];

        // The gate refuses before anything is written…
        $this->assertInstanceOf(
            WP_Error::class,
            pp_validate_action('create_page', ['title' => 'Refused', 'composition' => $composition])
        );

        // …and the executor honours that refusal rather than reporting success over a
        // write it did not make. No success envelope over a failed write (invariant I1).
        $result = pp_execute_action('create_page', ['title' => 'Refused', 'composition' => $composition]);
        $this->assertFalse($result['ok'] ?? true, 'no success envelope over a refused write');
    }

    // ── 2. PREVIEW / APPROVE ────────────────────────────────────────────────

    /**
     * §3.1's no-coercion rule at the surface an operator actually reads.
     *
     * The engine normalises a responsive value into a band-scoped token. What the
     * operator approves must still be what they wrote — with the normalisation
     * DISCLOSED beside it, never substituted for it. A diff that showed only
     * `@quote-typography-size-d` would be asking for approval of something the
     * author never typed.
     */
    public function testMintingPreservesTheAuthorsLiteralAsTheTokensValue(): void
    {
        $normalised = pp_udc_normalize_composition([$this->band($this->brandUdc())]);

        // THE PROPERTY THE WHOLE DISCLOSURE RESTS ON: normalisation moves the
        // author's literal, it does not replace it. `19px` is still `19px`, now
        // as the token's value — which is why the envelope can report what the
        // author wrote from stored data alone, long after the write.
        $this->assertSame('19px', $normalised[0]['udc']['_tokens']['quote-typography-size-d']);
        $this->assertSame('17px', $normalised[0]['udc']['_tokens']['quote-typography-size-p']);
        $this->assertSame(
            ['d' => '@quote-typography-size-d', 'p' => '@quote-typography-size-p'],
            $normalised[0]['udc']['quote']['typography']['size']
        );

        // The non-responsive values are untouched — minting normalises a
        // RESPONSIVE value only, so nothing else is rewritten behind the author.
        $this->assertSame('italic', $normalised[0]['udc']['quote']['typography']['style']);
        $this->assertSame('#ffffff', $normalised[0]['udc']['card']['background']['fill']);
    }

    /**
     * THE SAME PROMISE, AT THE SURFACE AN OPERATOR ACTUALLY READS.
     *
     * The test above drives the normalizer directly, which proves the disclosure
     * is BUILT and proves nothing about whether it is DELIVERED. That gap was
     * real: the writer created the disclosure array and dropped it, so every
     * `udc_token_minted` entry was discarded while the runtime prompt told the
     * authoring model "the approval diff shows YOUR literal beside the name it
     * was stored as". A promise kept in a local variable is not kept.
     *
     * This one goes through the real authoring surface and reads the envelope,
     * so deleting the join in _pp_composition_findings() fails it.
     */
    public function testTheWriteENVELOPECarriesTheMintDisclosureAndTheUnusedTokenLint(): void
    {
        $band = $this->band($this->brandUdc());
        // An extra token nothing references, so both finding types are exercised.
        $band['udc']['_tokens'] = ['orphan' => '17px'];

        $result = pp_execute_action('create_page', ['title' => 'Envelope', 'composition' => [$band]]);
        $this->assertTrue($result['ok'], 'the write itself must succeed — these are disclosures, not refusals');

        $findings = $result['findings'] ?? [];
        $this->assertNotEmpty($findings, 'the envelope must carry the engine\'s disclosures');

        $byType = [];
        foreach ($findings as $finding) {
            $byType[$finding['type']][] = $finding;
        }

        // 1. The no-coercion disclosure, carrying the AUTHOR'S literal.
        $this->assertArrayHasKey('udc_token_minted', $byType, 'a responsive value was normalised, so the envelope must say so');
        $minted = implode(' ', array_column($byType['udc_token_minted'], 'message'));
        $this->assertStringContainsString('you wrote "19px"', $minted);
        $this->assertStringContainsString('--pp-quote-typography-size-d', $minted);
        $this->assertSame(0, $byType['udc_token_minted'][0]['index'], 'and it names the band');

        // 2. The unused-token lint, as a warning rather than a refusal (§3.1).
        $this->assertArrayHasKey('udc_unused_band_token', $byType);
        $this->assertSame('warning', $byType['udc_unused_band_token'][0]['severity']);
        $this->assertStringContainsString('orphan', $byType['udc_unused_band_token'][0]['message']);
    }

    public function testAnApprovedUdcWriteLandsAndRendersOnThePage(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Approved',
            'composition' => [$this->band($this->brandUdc())],
        ]);
        $this->assertTrue($result['ok']);
        $post_id = (int) $result['target']['post_id'];

        $stored = pp_get_composition($post_id);
        $this->assertArrayHasKey('udc', $stored[0], 'the map is stored on the item, inside the composition value');
        $this->assertNotEmpty($stored[0]['id'] ?? '', 'and the band was minted an id at write');
        $this->assertTrue(pp_udc_valid_band_id($stored[0]['id']));

        // The whole point: what was stored is what paints.
        $css = pp_udc_page_css($stored);
        $this->assertStringContainsString('font-style:italic', $css);
        $this->assertStringContainsString('background:#ffffff', $css);
        $this->assertStringContainsString('border-top-width:1px', $css, 'the 1px rule above the attribution');
        $this->assertStringContainsString('[data-pp-band="' . $stored[0]['id'] . '"]', $css);
    }

    // ── 3. CAS ──────────────────────────────────────────────────────────────

    public function testAStaleBaselineIsRefusedAndTheLandedUdcIsUntouched(): void
    {
        $id    = $this->seed('Concurrent', [$this->band($this->brandUdc())]);
        $stale = pp_get_composition_marker($id)['version'];

        // A second writer lands first.
        $winner = $this->band($this->brandUdc());
        $winner['udc']['quote']['typography']['style'] = 'normal';
        pp_update_composition($id, [$winner], $stale);
        $current = pp_get_composition_marker($id)['version'];
        $this->assertSame($stale + 1, $current);

        // The first writer, still holding the pre-edit baseline, is refused.
        $loser = $this->band($this->brandUdc());
        $loser['udc']['quote']['typography']['style'] = 'oblique';
        $conflict = pp_update_composition($id, [$loser], $stale);

        $this->assertInstanceOf(WP_Error::class, $conflict);
        $this->assertSame('composition_conflict', $conflict->get_error_code());

        // The winner's udc stands, byte for byte. A CAS that refused the write but
        // let a partial map through would be worse than no CAS at all.
        $this->assertSame('normal', pp_get_composition($id)[0]['udc']['quote']['typography']['style']);
    }

    /**
     * A udc write is accepted only when its baseline EQUALS the stored version.
     *
     * That is what this test pins, and the name says so, because the name it used
     * to carry — "a baseline it never earned is refused" — promised invariant I8
     * and did not deliver it. The assertions below are numeric equality with the
     * stored version in all three directions: a version read from another page is
     * refused because its NUMBER differs, a planted future version because it is
     * not equal either, and the version this edit was actually read against is
     * accepted. Nothing here examines PROVENANCE. Two pages sitting at the same
     * version number would swap baselines and both writes would land.
     *
     * The gap is not a defect in the CAS check; equality is what pp_update_composition
     * implements and this pins it honestly. The gap is that I8 asks for something
     * strictly stronger — that the baseline came from the read the write was
     * reasoned against, for the page it targets — and no test in this suite pins
     * that. A test whose name claims coverage its body does not carry is an I40
     * violation: it makes the invariant look guarded and stops anyone looking.
     *
     * @todo #909 (CRITICAL) — pin I8 itself: a baseline must be traceable to the
     *       read it came from, not merely numerically equal to the current one.
     *       The known violation is the chat's localStorage-persisted baseline;
     *       BUILD-SPEC §7 defers the fix to Sprint 3 or the first post-2.0.0 work.
     *       Until then I8 is UNPINNED — see docs/v2/BUILD-SPEC-sprint0.md §6.
     */
    public function testAUdcWriteIsRefusedUnlessItsBaselineEqualsTheStoredVersion(): void
    {
        $page  = $this->seed('Target', [$this->band($this->brandUdc())]);
        $other = $this->seed('Other',  [$this->band($this->brandUdc())]);

        // Move the OTHER page along so the two pages' versions genuinely differ.
        pp_update_composition($other, [$this->band($this->brandUdc())], pp_get_composition_marker($other)['version']);
        $foreign = pp_get_composition_marker($other)['version'];
        $mine    = pp_get_composition_marker($page)['version'];
        $this->assertNotSame($foreign, $mine, 'the fixture needs two genuinely different versions');

        // A version read from a DIFFERENT page is refused here — but note WHAT
        // refuses it: the number differs from this page's stored version. The
        // check is equality, not origin, so this case is evidence for equality
        // and not for I8. See the @todo above.
        $edit = $this->band($this->brandUdc());
        $edit['udc']['quote']['typography']['style'] = 'oblique';
        $refused = pp_update_composition($page, [$edit], $foreign);

        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertSame('composition_conflict', $refused->get_error_code());
        $this->assertSame('italic', pp_get_composition($page)[0]['udc']['quote']['typography']['style']);

        // A PLANTED future baseline is refused too — the check is equality with the
        // stored version, so "newer than current" is no more acceptable than older.
        $planted = pp_update_composition($page, [$edit], $mine + 99);
        $this->assertInstanceOf(WP_Error::class, $planted);
        $this->assertSame('composition_conflict', $planted->get_error_code());

        // …and the baseline that WAS earned, from the read this edit was reasoned
        // against, is accepted. Without this half the test would pass by refusing
        // everything.
        $this->assertTrue(pp_update_composition($page, [$edit], $mine));
        $this->assertSame('oblique', pp_get_composition($page)[0]['udc']['quote']['typography']['style']);
    }

    /**
     * The band id is stripped from the content hash for the same reason props.id
     * is: the writer injects it, so a caller round-tripping a composition it read
     * back would otherwise hash a value it never sent and conflict with itself.
     */
    public function testAMintedBandIdNeverMakesACompositionConflictWithItself(): void
    {
        $id = $this->seed('Roundtrip', [$this->band($this->brandUdc())]);

        // Read back what the writer stored (ids and all) and send it straight back.
        $readBack = pp_get_composition($id);
        $this->assertNotEmpty($readBack[0]['id']);

        $version = pp_get_composition_marker($id)['version'];
        $this->assertTrue(
            pp_update_composition($id, $readBack, $version),
            'a composition round-tripped through a read must not false-conflict against itself'
        );

        // And the id is stable across that round trip — nothing was re-minted.
        $this->assertSame($readBack[0]['id'], pp_get_composition($id)[0]['id']);
    }

    public function testTwoBandsMayNotClaimOneBandId(): void
    {
        $a = $this->band($this->brandUdc());
        $b = $this->band($this->brandUdc());
        $a['id'] = 'pp-deadbeef';
        $b['id'] = 'pp-deadbeef';

        $error = pp_validate_action('create_page', ['title' => 'Collide', 'composition' => [$a, $b]]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('duplicate_band_id', $error->get_error_code());
        // Its own code, not duplicate_component_id: that message explains itself in
        // terms of update/remove/style targeting, which is the OTHER id namespace.
        $this->assertStringContainsString('scopes that band', $error->get_error_message());
    }

    // ── 4. UNDO ─────────────────────────────────────────────────────────────

    public function testUndoRestoresThePreviousUdcExactly(): void
    {
        $id     = $this->seed('Undoable', [$this->band($this->brandUdc())]);
        $before = pp_get_composition($id);

        $edit = $this->band($this->brandUdc());
        $edit['udc']['card']['background']['fill'] = '#101010';
        $edit['udc']['quote']['typography']['style'] = 'normal';
        $this->assertTrue(pp_update_composition($id, [$edit], pp_get_composition_marker($id)['version']));
        $this->assertSame('#101010', pp_get_composition($id)[0]['udc']['card']['background']['fill']);

        // Undo is "write the previous value back" — it needs no udc-specific support
        // precisely because the map rides inside the composition value.
        $this->assertTrue(pp_update_composition($id, $before, pp_get_composition_marker($id)['version']));

        $restored = pp_get_composition($id);
        $this->assertSame('#ffffff', $restored[0]['udc']['card']['background']['fill']);
        $this->assertSame('italic', $restored[0]['udc']['quote']['typography']['style']);
        $this->assertSame($before[0]['udc'], $restored[0]['udc'], 'the whole map returns, not just the keys that changed');
        $this->assertSame($before[0]['id'], $restored[0]['id'], 'and the band keeps its identity across the undo');
    }

    // ── The legacy boundary ─────────────────────────────────────────────────

    /**
     * The eleven components still on the v1 styling system must keep working
     * EXACTLY as today — the slice's own scope boundary, asserted through the same
     * authoring surface rather than assumed.
     */
    public function testALegacyComponentStillWritesReadsAndPaintsItsStyleMap(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Legacy still works',
            'composition' => [[
                'component' => 'hero',
                'props'     => ['title' => 'Still here'],
                'style'     => ['--hero-bg' => '#1a1a2e'],
            ]],
        ]);
        $this->assertTrue($result['ok']);

        $stored = pp_get_composition((int) $result['target']['post_id']);
        $this->assertSame('#1a1a2e', $stored[0]['style']['--hero-bg'], 'stored as authored');
        $this->assertArrayNotHasKey('id', $stored[0], 'and no band id was minted onto a legacy component');

        ob_start();
        try {
            pp_get_component('hero', array_merge($stored[0]['props'], ['__pp_style' => $stored[0]['style']]));
        } finally {
            $html = ob_get_clean();
        }
        $this->assertStringContainsString('--hero-bg: #1a1a2e', $html, 'the inline style path is untouched');
        $this->assertStringNotContainsString('data-pp-band', $html, 'and a legacy band carries no v2 scope attribute');
    }

    /** A v2 component refuses a legacy style map — one styling system, not two. */
    public function testAStyleMapOnTheV2ComponentIsRefusedRatherThanStoredDead(): void
    {
        $error = pp_validate_action('create_page', [
            'title'       => 'Wrong system',
            'composition' => [[
                'component' => 'testimonials',
                'props'     => ['items' => [['quote' => 'q']]],
                'style'     => ['--testimonials-item-bg' => '#ffffff'],
            ]],
        ]);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('invalid_style_slot', $error->get_error_code());
        $this->assertStringContainsString('(none)', $error->get_error_message(), 'it declares no slots at all');
    }
}
