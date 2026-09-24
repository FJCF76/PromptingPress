<?php
/**
 * tests/ItemGrainDisclosureTest.php — the item-grain disclosure gaps (#1115, #1116, #1117).
 *
 * THE CLASS. All three are I35 (accepted, reported successful, paints nothing), and all
 * three sit where Sprint 2's two new capabilities meet: custom presets and item-grain
 * styling. In each, a walk that was written for the BAND map was not widened when item
 * grain arrived, while a sibling walk was (#1101 widened `udc_preset_groups_skipped` to
 * both grains, lib/udc.php — the `$preset_maps` list).
 *
 *   #1115  delete_preset's reference gate walked only `$item['udc']`: a preset referenced
 *          from a CARD was deleted with ok:true, findings:[] — and the card stopped
 *          painting. The under-lock writer had no reference scan at all.
 *   #1116  the shadowed-preset disclosure walked only the band map: the byte-identical
 *          map on a card got findings:[] while the band got the warning.
 *   #1117  `background.overlay` with no `background.image` is dropped at emit with no
 *          drop-ledger row and no finding — at band grain, and on every item-settable role.
 *
 * #1115's LOCKOUT HALF stays as the issue records it: UNREPRODUCED, and not planned here.
 *
 * Every write goes through pp_execute_action(), the real authoring surface (rule 14.1).
 */

use PHPUnit\Framework\TestCase;

final class ItemGrainDisclosureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
        ];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function savePreset(string $name, array $udc, string $grain = 'role'): void
    {
        $saved = pp_execute_action('save_preset', ['name' => $name, 'grain' => $grain, 'udc' => $udc]);
        $this->assertTrue($saved['ok'], 'premise: preset saved: ' . ($saved['error'] ?? ''));
    }

    /** A page written through the real surface; returns [post id, write envelope]. */
    private function page(array $composition, string $title = 'item grain'): array
    {
        $id = pp_create_page($title, 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => $composition]);
        $this->assertTrue($result['ok'], 'premise: page written: ' . ($result['error'] ?? ''));
        return [$id, $result];
    }

    private function grid(array $item_udc, array $band_udc = []): array
    {
        $band = [
            'component' => 'grid',
            'props'     => ['title' => 'G', 'items' => [['title' => 'One', 'udc' => $item_udc]]],
        ];
        if ($band_udc !== []) {
            $band['udc'] = $band_udc;
        }
        return [$band];
    }

    private function findingsOfType(array $envelope, string $type): array
    {
        return array_values(array_filter(
            $envelope['findings'] ?? [],
            static fn (array $f): bool => ($f['type'] ?? '') === $type
        ));
    }

    // ═══ #1115 — delete_preset sees item-grain references ════════════════════

    public function testDeletingAPresetACardReferencesIsRefusedNamingTheCard(): void
    {
        $this->savePreset('probe-type', ['typography' => ['weight' => '800']]);
        [$id] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-type']]));
        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];

        $result = pp_execute_action('delete_preset', ['name' => 'probe-type']);

        $this->assertFalse($result['ok'], 'a live card reference must block the delete');
        $this->assertSame('preset_in_use', $result['error_code'] ?? null);
        $this->assertStringContainsString('item "' . $item_id . '"', (string) $result['error'], 'the refusal names the card');
        $this->assertStringContainsString('role "card-title"', (string) $result['error']);
        $this->assertArrayHasKey('probe-type', pp_udc_custom_presets(), 'nothing was deleted');
    }

    public function testAGroupGrainCardReferenceIsSeenToo(): void
    {
        $this->savePreset('probe-typo', ['weight' => '800'], 'typography');
        $this->page($this->grid(['card-title' => ['typography' => ['_preset' => 'probe-typo']]]));

        $result = pp_execute_action('delete_preset', ['name' => 'probe-typo']);

        $this->assertSame('preset_in_use', $result['error_code'] ?? null);
        $this->assertStringContainsString('group "typography"', (string) $result['error']);
    }

    /** Control: the band-grain reference was always seen, and still is. */
    public function testABandReferenceIsStillRefused(): void
    {
        $this->savePreset('probe-type', ['typography' => ['weight' => '800']]);
        $this->page([[
            'component' => 'grid',
            'udc'       => ['card-title' => ['_preset' => 'probe-type']],
            'props'     => ['title' => 'G', 'items' => [['title' => 'One']]],
        ]]);

        $result = pp_execute_action('delete_preset', ['name' => 'probe-type']);

        $this->assertSame('preset_in_use', $result['error_code'] ?? null);
        $this->assertStringNotContainsString('item "', (string) $result['error'], 'a band reference carries no item locator');
    }

    /** Control: nothing referencing it — the delete still goes through. */
    public function testAnUnreferencedPresetIsStillDeleted(): void
    {
        $this->savePreset('probe-type', ['typography' => ['weight' => '800']]);
        $this->page($this->grid(['card-title' => ['typography' => ['weight' => '700']]]));

        $result = pp_execute_action('delete_preset', ['name' => 'probe-type']);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertArrayNotHasKey('probe-type', pp_udc_custom_presets());
    }

    /**
     * THE UNDER-LOCK BACKSTOP. A reference written between validate and the writer's lock is
     * the race the issue records; the writer used to re-check everything but references.
     * Driven by calling the registered execute arm directly — validate never ran, exactly
     * the state a concurrent band write produces.
     */
    public function testTheWriterRefusesAReferenceValidateNeverSaw(): void
    {
        $this->savePreset('probe-type', ['typography' => ['weight' => '800']]);
        $this->page($this->grid(['card-title' => ['_preset' => 'probe-type']]));

        $execute = pp_get_action('delete_preset')['execute'];
        $result = $execute(['name' => 'probe-type']);

        $this->assertFalse($result['ok'], 'the writer is the last point that can say no');
        $this->assertSame('preset_in_use', $result['error_code'] ?? null);
        $this->assertArrayHasKey('probe-type', pp_udc_custom_presets());
    }

    /**
     * THE BACKSTOP READS WHAT THE WRITER HOLDS. The chrome half of the scan must come from
     * the row the writer read under its lock, not the request-cached option: a chrome
     * reference committed by another process after this request loaded its options is
     * exactly what the backstop exists to catch. The double below serves a FRESHER row to
     * the in-lock read than the option store holds.
     */
    public function testTheWriterSeesAChromeReferenceTheCachedOptionDoesNot(): void
    {
        $this->savePreset('probe-type', ['typography' => ['weight' => '800']]);
        $stored = get_option(PP_SITE_UDC_OPTION, '');
        $row    = is_string($stored) ? (json_decode($stored, true) ?: []) : (array) $stored;
        $row['nav'] = ['link' => ['_preset' => 'probe-type']];
        $fresher = (string) json_encode($row);

        $GLOBALS['wpdb'] = new class ($fresher) extends PP_Lockable_Wpdb {
            public function __construct(private string $fresher) {}
            public function get_var(string $query)
            {
                if (str_contains($query, "option_name = '" . PP_SITE_UDC_OPTION . "'")) {
                    return $this->fresher;
                }
                return parent::get_var($query);
            }
        };

        $execute = pp_get_action('delete_preset')['execute'];
        $result  = $execute(['name' => 'probe-type']);

        $this->assertFalse($result['ok'], 'the committed chrome reference must block the delete');
        $this->assertSame('preset_in_use', $result['error_code'] ?? null);
        $this->assertStringContainsString('site chrome "nav"', (string) $result['error']);
    }

    // ═══ #1116 — the shadowed-preset disclosure at item grain ════════════════

    public function testACardPresetShadowedByTheRoleDefaultIsDisclosedWithTheItemLocator(): void
    {
        $this->savePreset('probe-shadow', ['typography' => ['size' => '3rem', 'weight' => '800']]);

        [$id, $result] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-shadow']]));

        $found = $this->findingsOfType($result, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $found, 'the card grain is disclosed like the band grain');
        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];
        $this->assertStringContainsString('item "' . $item_id . '"', $found[0]['message']);
        $this->assertStringContainsString('role "card-title"', $found[0]['message']);
        $this->assertStringContainsString('probe-shadow', $found[0]['message']);
    }

    /** Control: the band grain keeps its disclosure, with no item locator. */
    public function testTheBandGrainDisclosureIsUnchanged(): void
    {
        $this->savePreset('probe-shadow', ['typography' => ['size' => '3rem', 'weight' => '800']]);

        [, $result] = $this->page([[
            'component' => 'grid',
            'udc'       => ['card-title' => ['_preset' => 'probe-shadow']],
            'props'     => ['title' => 'G', 'items' => [['title' => 'One']]],
        ]]);

        $found = $this->findingsOfType($result, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString('item "', $found[0]['message']);
    }

    // ═══ #1117 — an overlay with nothing to lie over ═════════════════════════

    public function testABandOverlayWithNoImageIsDisclosedOnTheWrite(): void
    {
        [, $result] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]);

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found, 'accepted, stored, painted nowhere — and now said so');
        $this->assertStringContainsString('background.image', $found[0]['message']);
        $this->assertSame(0, $found[0]['index']);
    }

    /** The likely authoring mistake: a scrim over a FILL. The message names the pairing. */
    public function testAnOverlayPairedWithAFillIsDisclosedNamingTheFill(): void
    {
        [, $result] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['fill' => '#000000', 'overlay' => '#112233']]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]);

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('background.fill', $found[0]['message']);
    }

    public function testACardOverlayWithNoImageIsDisclosedWithTheItemLocator(): void
    {
        [$id, $result] = $this->page($this->grid(['card' => ['background' => ['fill' => '#000000', 'overlay' => '#112233']]]));

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];
        $this->assertStringContainsString('item "' . $item_id . '"', $found[0]['message']);
    }

    /**
     * A STATE's overlay. `background.image` is refused inside a state, so a `:hover` scrim
     * never has an image in its own bucket and is dropped even when the role HAS a base
     * image. The finding must say that, not claim the role declares no image.
     */
    public function testAHoverOverlayIsDisclosedTruthfullyEvenOverABaseImage(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $result] = $this->page($this->grid(['card' => ['background' => [
            'image'  => 9001,
            ':hover' => ['overlay' => '#112233'],
        ]]]));

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found, 'the hover scrim paints nowhere');
        $this->assertStringContainsString('(:hover)', $found[0]['message']);
        $this->assertStringContainsString('inside a state', $found[0]['message']);
        $this->assertStringNotContainsString('no usable background.image', $found[0]['message'], 'the role does declare one');
    }

    /**
     * CHROME, BY IDENTITY. A chrome write's findings come from the same engine
     * (pp_udc_site_findings() wraps each entry and calls pp_udc_composition_findings()),
     * so the nav's scrim is disclosed with no chrome-specific arm. Pinned so a future
     * chrome-only findings path cannot quietly drop it.
     */
    public function testAChromeOverlayWithNoImageIsDisclosedOnTheChromeWrite(): void
    {
        $result = pp_execute_action('update_site_option', [
            'key'   => 'pp_site_udc',
            'value' => json_encode(['nav' => ['_band' => ['background' => ['overlay' => '#112233']]]]),
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('Component "nav"', $found[0]['message']);
        $this->assertNull($found[0]['index'], 'chrome names no band offset');
    }

    /**
     * BOUNDED ACROSS THE COMPOSITION. Item grain multiplies every per-map arm by the card
     * count, and `items` declares no maximum — the sibling arms' comments record 10,000
     * findings and +8 MB from exactly this shape. Every item-grain arm counts against a
     * composition-wide cap, like `$item_disclosed`.
     */
    public function testTheItemGrainDisclosuresAreBoundedAcrossTheComposition(): void
    {
        // typography size/weight: shadowed by card-title's defaults; shadow: not permitted there.
        $this->savePreset('probe-wide', [
            'typography' => ['size' => '3rem', 'weight' => '800'],
            'shadow'     => ['box' => 'none'],
        ]);
        $bands = [];
        for ($b = 0; $b < 50; $b++) {
            $items = [];
            for ($k = 0; $k < 20; $k++) {
                $items[] = ['id' => sprintf('it-%08x', $b * 100 + $k + 1), 'title' => 'x', 'udc' => [
                    'card-title' => ['_preset' => 'probe-wide'],
                    'card'       => ['background' => ['overlay' => ['d' => '#111111', 't' => '#222222', 'p' => '#333333']]],
                ]];
            }
            $bands[] = ['component' => 'grid', 'id' => sprintf('pp-%08x', $b + 1), 'props' => ['title' => 'G', 'items' => $items]];
        }

        $counts = array_count_values(array_column(pp_udc_composition_findings($bands), 'type'));

        foreach (['udc_overlay_without_image', 'udc_preset_value_shadowed_by_role_default', 'udc_preset_groups_skipped'] as $type) {
            $this->assertGreaterThan(0, $counts[$type] ?? 0, "premise: $type fires on this fixture");
            $this->assertLessThanOrEqual(PP_UDC_MAX_EMIT_DROPS, $counts[$type] ?? 0, "$type is bounded composition-wide");
        }
    }

    /** Band grain carries the state reason and locator too (the second compose call site). */
    public function testABandGrainHoverOverlayIsDisclosedTruthfully(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $result] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['image' => 9001, ':hover' => ['overlay' => '#112233']]]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]);

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('(:hover)', $found[0]['message']);
        $this->assertStringContainsString('inside a state', $found[0]['message']);
        $this->assertStringNotContainsString('no usable background.image', $found[0]['message']);
    }

    /** A responsive scrim with no image is dropped per breakpoint, and each row names its tier. */
    public function testANarrowOverlayWithNoImageNamesItsBreakpoint(): void
    {
        [, $result] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['overlay' => ['d' => '#112233', 'p' => '#445566']]]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]);

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(2, $found);
        $this->assertStringContainsString('at breakpoint p', implode("\n", array_column($found, 'message')));
    }

    /**
     * Control: the finding reads ONLY this discard's ledger rows. A band whose compile writes
     * a different drop row must not surface it as an overlay finding.
     */
    public function testANonOverlayLedgerRowIsNotSurfacedAsAnOverlayFinding(): void
    {
        // A painting overlay over a live image, so the band reaches the compile (the walk's
        // pre-filter skips a band that names no overlay), plus a different discarded value.
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        $drops = [];
        $band  = ['component' => 'section', 'id' => 'pp-a1b2c3d4', 'props' => ['title' => 'S', 'body' => 'b'],
                  'udc' => ['_band' => ['_css' => 'not-a-map', 'background' => ['image' => 9001, 'overlay' => '#112233']]]];
        pp_udc_compile_band($band, 'authored', $drops);
        $this->assertNotSame([], $drops, 'premise: this band writes some other ledger row');

        $found = array_filter(
            pp_udc_composition_findings([$band]),
            static fn (array $f): bool => $f['type'] === 'udc_overlay_without_image'
        );
        $this->assertSame([], array_values($found));
    }

    /**
     * An image whose attachment was deleted AFTER the write is dropped at place time, so the
     * overlay over it is dropped too. The author did set an image: the reason must not tell
     * them they did not (check 8c owns the deleted image itself).
     */
    public function testAnOverlayOverADeletedImageDoesNotBlameTheAuthor(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [$id] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.5)']]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]);
        unset($GLOBALS['_pp_test_store']['posts'][9001], $GLOBALS['_pp_test_store']['attachment_is_image'][9001]);

        $found = array_values(array_filter(
            pp_udc_composition_findings(pp_get_composition($id)),
            static fn (array $f): bool => $f['type'] === 'udc_overlay_without_image'
        ));
        $this->assertCount(1, $found, 'the scrim really is gone');
        $this->assertStringNotContainsString('declares no background.image', $found[0]['message']);
        $this->assertStringContainsString('deleted', $found[0]['message'], 'the other cause is named');
    }

    /**
     * An overlay can arrive through a PRESET: the map itself has no `overlay` key. Whatever
     * narrows which bands get compiled must still reach this one.
     */
    public function testAnOverlayCarriedByAPresetIsDisclosed(): void
    {
        $this->savePreset('probe-scrim', ['background' => ['overlay' => '#112233']]);

        [, $result] = $this->page($this->grid(['card' => ['_preset' => 'probe-scrim']]));

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('role "card"', $found[0]['message']);
    }

    /**
     * WIDENING MUST NOT NARROW. Card-only bands joining check 8e must not spend the band
     * budget a band-level map was always checked within: a band-map drop after 25 clean
     * card-only bands was reported before item grain joined the walk, and still is.
     */
    public function testCardOnlyBandsDoNotCrowdABandMapOutOfCheck8e(): void
    {
        $composition = [];
        for ($b = 0; $b < 25; $b++) {
            $composition[] = ['component' => 'grid', 'props' => ['title' => 'G', 'items' => [
                ['title' => 'One', 'udc' => ['card' => ['background' => ['fill' => '#111111']]]],
            ]]];
        }
        $composition[] = [
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ];
        [$id] = $this->page($composition);

        $rows = json_encode(pp_check_udc_emit_drops($id, pp_get_composition($id)));
        $this->assertStringContainsString('band 26', (string) $rows, 'the band-map band is still inside the window');
    }

    /**
     * A STORED BAND WITH NO USABLE ID renders nothing at all (the emitter's id gate), and
     * that is what must be said. Reachable through raw meta and restore (#233). The findings
     * walk must not compile it under an invented id and describe a render that never
     * happens, and readiness 8e must carry the whole-band row for a card-only band too.
     */
    public function testABandWithNoIdIsReportedAsTheWholeBandNotAsAnOverlay(): void
    {
        $band = ['component' => 'grid', 'props' => ['title' => 'G', 'items' => [
            ['id' => 'it-aaaaaaaa', 'title' => 'One', 'udc' => ['card' => ['background' => ['fill' => '#111111', 'overlay' => '#112233']]]],
        ]]];
        $id = pp_create_page('no id', 'draft');

        $overlay = array_filter(
            pp_udc_composition_findings([$band]),
            static fn (array $f): bool => $f['type'] === 'udc_overlay_without_image'
        );
        $this->assertSame([], array_values($overlay), 'no render happens, so no overlay story is told');

        $rows = json_encode(pp_check_udc_emit_drops($id, [$band]));
        $this->assertStringContainsString('the whole band', (string) $rows);
        $this->assertStringContainsString('no id', (string) $rows);
    }

    /** The readiness channel (the emit-drop ledger) carries the same fact. */
    public function testTheEmitDropLedgerRecordsTheDiscardedOverlay(): void
    {
        [$id] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]);

        $rows = json_encode(pp_check_udc_emit_drops($id, pp_get_composition($id)));
        $this->assertStringContainsString('overlay', (string) $rows);
        $this->assertStringContainsString('background.image', (string) $rows);
    }

    /**
     * The readiness channel at ITEM grain. The issue's own observed row is a card overlay
     * with `drops=[]`: check 8e only compiled bands that carried a band-level map, so a band
     * styled at item grain alone was never probed — for this drop or any other.
     */
    public function testTheEmitDropLedgerSeesABandStyledOnlyAtItemGrain(): void
    {
        [$id] = $this->page($this->grid(['card' => ['background' => ['fill' => '#000000', 'overlay' => '#112233']]]));
        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];

        $rows = json_encode(pp_check_udc_emit_drops($id, pp_get_composition($id)));
        $this->assertStringContainsString('item \"' . $item_id . '\"', (string) $rows, 'the card is named');
        $this->assertStringContainsString('background.overlay', (string) $rows);
    }

    /** Controls: an overlay over an image, and a narrower overlay over the base image, paint. */
    public function testAnOverlayThatPaintsIsNotDisclosed(): void
    {
        // A live image attachment, in the store shape GridItemUdcTest uses for the same thing.
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $plain] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(0,0,0,0.5)']]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]], 'overlay paints');
        [, $narrow] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['image' => 9001, 'overlay' => ['d' => 'rgba(0,0,0,0.5)', 'p' => 'rgba(0,0,0,0.7)']]]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]], 'narrow overlay borrows the image');

        $this->assertSame([], $this->findingsOfType($plain, 'udc_overlay_without_image'));
        $this->assertSame([], $this->findingsOfType($narrow, 'udc_overlay_without_image'));
    }
}
