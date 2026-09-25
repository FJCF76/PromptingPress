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

    /**
     * THE PAGE LIST IS READ FRESH TOO. WordPress (6.1+) caches a WP_Query's ID list for the
     * rest of the request, salted by `last_changed`, and nothing between validate and the
     * writer moves that salt — so without opting out, the under-lock scan would walk
     * validate's page list and miss a page another process created in between. The stub
     * does not model that cache, so the contract is pinned on what the gate ASKS for.
     */
    public function testTheReferenceGateOptsOutOfTheInRequestQueryCache(): void
    {
        $this->savePreset('probe-type', ['typography' => ['weight' => '800']]);
        $GLOBALS['_pp_test_get_posts_calls'] = [];

        pp_execute_action('delete_preset', ['name' => 'probe-type']);

        $gate_calls = array_values(array_filter(
            $GLOBALS['_pp_test_get_posts_calls'],
            static fn (array $a): bool => in_array('trash', (array) ($a['post_status'] ?? []), true)
        ));
        $this->assertCount(2, $gate_calls, 'premise: validate and the writer each list the pages');
        foreach ($gate_calls as $args) {
            $this->assertFalse($args['cache_results'] ?? true, 'the reference gate lists pages uncached');
            $this->assertFalse($args['update_post_meta_cache'] ?? true, 'and does not prime the meta cache');
        }
    }

    // ═══ #1116 — the shadowed-preset disclosure at item grain ════════════════

    public function testACardPresetShadowedByTheRoleDefaultIsDisclosedWithTheItemLocator(): void
    {
        $this->savePreset('probe-shadow', ['typography' => ['size' => '3rem', 'weight' => '800']]);

        [$id, $result] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-shadow']]));

        $found = $this->findingsOfType($result, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $found, 'the card grain is disclosed like the band grain');
        $this->assertSame(0, $found[0]['index']);
        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];
        $this->assertStringContainsString('item "' . $item_id . '"', $found[0]['message']);
        $this->assertStringContainsString('role "card-title"', $found[0]['message']);
        $this->assertStringContainsString('probe-shadow', $found[0]['message']);
    }

    /**
     * GROUP GRAIN TOO. A `_preset` inside a group (`typography._preset`) ranks under the
     * role's defaults exactly like a role-grain one, so the same suppressed parameters
     * go unpainted — at card grain and at band grain.
     */
    public function testAGroupGrainPresetShadowedByTheRoleDefaultIsDisclosedAtBothGrains(): void
    {
        $this->savePreset('probe-gshadow', ['size' => '3rem', 'weight' => '800', 'style' => 'italic'], 'typography');

        [$id, $card] = $this->page($this->grid(['card-title' => ['typography' => ['_preset' => 'probe-gshadow']]]));
        [, $band]    = $this->page([[
            'component' => 'grid',
            'udc'       => ['card-title' => ['typography' => ['_preset' => 'probe-gshadow']]],
            'props'     => ['title' => 'G', 'items' => [['title' => 'One']]],
        ]], 'band group grain');

        $item_id = (string) pp_get_composition($id)[0]['props']['items'][0]['id'];
        $on_card = $this->findingsOfType($card, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $on_card);
        $this->assertStringContainsString('item "' . $item_id . '"', $on_card[0]['message']);
        $this->assertStringContainsString('group "typography"', $on_card[0]['message']);
        $this->assertStringContainsString('typography.size', $on_card[0]['message']);
        $this->assertStringNotContainsString('typography.style', $on_card[0]['message'], 'italic paints: no role default for it');
        $this->assertCount(1, $this->findingsOfType($band, 'udc_preset_value_shadowed_by_role_default'));
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
        $this->assertStringNotContainsString('at breakpoint', $found[0]['message'], 'the base tier names no breakpoint');
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

    /**
     * The walk's pre-filter reads THROUGH a preset: a `_preset` whose bundle names no
     * overlay cannot make the emitter drop one, so the band is not compiled for this
     * question. Presets like `button` sit on most bands — counting every `_preset` as a
     * candidate compiled nearly every band on every write (measured 7 -> 806 ms at
     * 400 bands x 20 cards). A preset cannot reference another preset, so one level is
     * the whole answer.
     */
    public function testThePreFilterReadsThroughAPresetBundle(): void
    {
        $this->savePreset('probe-scrim', ['background' => ['overlay' => '#112233']]);
        $this->savePreset('probe-plain', ['typography' => ['weight' => '800']]);
        $this->savePreset('probe-gscrim', ['overlay' => '#112233'], 'background');

        $this->assertTrue(_pp_udc_map_may_carry_overlay(['card' => ['_preset' => 'probe-scrim']]));
        $this->assertTrue(_pp_udc_map_may_carry_overlay(['card' => ['background' => ['_preset' => 'probe-gscrim']]]));
        $this->assertTrue(_pp_udc_map_may_carry_overlay(['card' => ['background' => ['overlay' => '#000000']]]));
        $this->assertFalse(_pp_udc_map_may_carry_overlay(['card' => ['_preset' => 'probe-plain']]), 'no overlay in the bundle');
        $this->assertFalse(_pp_udc_map_may_carry_overlay(['cta' => ['_preset' => 'button']]), 'a shipped button preset carries no scrim');
        $this->assertFalse(_pp_udc_map_may_carry_overlay(['card' => ['_preset' => 'no-such-preset']]), 'dangling: refused at write, nothing to compile');
    }

    /** The pre-filter reads every card, not only the first. */
    public function testAnOverlayOnALaterCardIsStillDisclosed(): void
    {
        [, $result] = $this->page([['component' => 'grid', 'props' => ['title' => 'G', 'items' => [
            ['title' => 'One', 'udc' => ['card' => ['background' => ['fill' => '#111111']]]],
            ['title' => 'Two', 'udc' => ['card' => ['background' => ['fill' => '#000000', 'overlay' => '#112233']]]],
        ]]]]);

        $this->assertCount(1, $this->findingsOfType($result, 'udc_overlay_without_image'));
    }

    /** The card-only pass says when IT stopped early, like the band-map pass does. */
    public function testTheCardOnlyPassFlagsItsOwnTruncation(): void
    {
        $composition = [[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]];
        for ($b = 0; $b < 26; $b++) {
            $composition[] = ['component' => 'grid', 'props' => ['title' => 'G', 'items' => [
                ['title' => 'One', 'udc' => ['card' => ['background' => ['fill' => '#111111']]]],
            ]]];
        }
        [$id] = $this->page($composition);

        $rows = json_encode(pp_check_udc_emit_drops($id, pp_get_composition($id)));
        $this->assertStringContainsString('bands_truncated', (string) $rows);
        $this->assertStringContainsString('styled only at card level', (string) $rows);
    }

    /** Unstyled bands spend neither budget: only bands with something to compile count. */
    public function testUnstyledBandsDoNotSpendTheCardOnlyBudget(): void
    {
        $composition = array_fill(0, 25, ['component' => 'section', 'props' => ['title' => 'S', 'body' => 'b']]);
        $composition[] = ['component' => 'grid', 'props' => ['title' => 'G', 'items' => [
            ['title' => 'One', 'udc' => ['card' => ['background' => ['fill' => '#000000', 'overlay' => '#112233']]]],
        ]]];
        [$id] = $this->page($composition);

        $rows = json_encode(pp_check_udc_emit_drops($id, pp_get_composition($id)));
        $this->assertStringContainsString('band 26', (string) $rows);
    }

    /**
     * ONE LEVEL, ENFORCED. A preset cannot reference another preset through any write
     * path, but the row can be written raw, and stored presets are not re-validated on
     * read. The pre-filter must not follow a `_preset` found INSIDE a resolved bundle —
     * following it let a stored chain of wide bundles cost O(width^depth) per findings call
     * (measured 7.6 s at 120 entries per level, against 0.001 s before the pre-filter).
     * A nested reference is answered conservatively: the band is compiled.
     */
    public function testThePreFilterNeverFollowsAPresetInsideAPreset(): void
    {
        $chain = [];
        // Three levels, so the walk's depth cap (which answers "compile") never fires and
        // only the one-level rule can bound it.
        foreach (['a1' => 'a2', 'a2' => 'a3'] as $name => $next) {
            $udc = [];
            for ($k = 0; $k < 200; $k++) {
                $udc['r' . $k] = ['_preset' => $next];
            }
            $chain[$name] = ['grain' => 'role', 'udc' => $udc];
        }
        $leaf = [];
        for ($k = 0; $k < 200; $k++) {
            $leaf['r' . $k] = ['typography' => ['weight' => '800']];
        }
        $chain['a3'] = ['grain' => 'role', 'udc' => $leaf];
        update_option(PP_SITE_UDC_OPTION, (string) json_encode(['_version' => 0, '_presets_version' => 1, '_presets' => $chain]));

        $started = microtime(true);
        $answer  = _pp_udc_map_may_carry_overlay(['_band' => ['_preset' => 'a1']]);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($answer, 'a nested reference is a candidate: compile rather than guess');
        $this->assertLessThan(1.0, $elapsed, 'the walk stops at the first bundle');
    }

    /**
     * PER-REFERENCE WORK IS PAID ONCE PER PRESET. A preset bundle can be as large as the
     * 64 KB row allows (raw-stored), and cards may reference it without limit. Walking the
     * bundle again for every reference — in the overlay pre-filter and in the shadow arm —
     * made one findings call cost references x bundle size (measured 3.1 s at 400 bands x
     * 20 cards, against 25 ms on main). Both answers depend only on the preset (and the
     * role), so each is computed once per call.
     */
    public function testAHugePresetReferencedFromEveryCardIsWalkedOncePerCall(): void
    {
        $leaves = [];
        for ($k = 0; $k < 5000; $k++) {
            $leaves['p' . $k] = '1';
        }
        update_option(PP_SITE_UDC_OPTION, (string) json_encode(['_version' => 0, '_presets_version' => 1,
            '_presets' => ['leafy' => ['grain' => 'typography', 'udc' => $leaves]]]));
        $bands = [];
        for ($b = 0; $b < 100; $b++) {
            $items = [];
            for ($k = 0; $k < 20; $k++) {
                $items[] = ['id' => sprintf('it-%08x', $b * 100 + $k + 1), 'title' => 'x',
                            'udc' => ['card-title' => ['typography' => ['_preset' => 'leafy']]]];
            }
            $bands[] = ['component' => 'grid', 'id' => sprintf('pp-%08x', $b + 1), 'props' => ['title' => 'G', 'items' => $items]];
        }

        $started = microtime(true);
        pp_udc_composition_findings($bands);
        // Fixed: ~0.02 s. Either memo removed on its own: ~0.35 s; both: ~0.7 s.
        $this->assertLessThan(0.2, microtime(true) - $started, 'each preset bundle is walked once per call');
    }

    /**
     * A CARD's scrim over the band map's image for the same role is dropped — the item
     * compile does not combine grains — and the reason must say THAT, not tell an author
     * who set the image to go and set it.
     */
    public function testACardOverlayOverTheBandMapsImageGetsATruthfulReason(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $result] = $this->page($this->grid(
            ['card' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]],
            ['card' => ['background' => ['image' => 9001]]]
        ));

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('not combined', $found[0]['message'], 'the cross-grain cause is named');
    }

    /** A raw `_css` background shorthand is not reported as a `background.fill` the author never wrote. */
    public function testARawCssBackgroundIsNotCalledAFill(): void
    {
        [, $result] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['_css' => ['background' => 'linear-gradient(#ff0000, #0000ff)'], 'background' => ['overlay' => '#112233']]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]);

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString('has a background.fill', $found[0]['message']);
        $this->assertStringContainsString('_css', $found[0]['message']);
    }

    /**
     * A CARD MAP ONLY SPEAKS FOR ITEM ROLES. A stored card map naming a band-only role
     * (raw meta, restore) is dropped whole by the emitter ("not settable on a single
     * item"); the preset arms must not advise writing the value "in your own map for this
     * role" there, which the write gate would refuse.
     */
    public function testThePresetArmsIgnoreABandOnlyRoleInACardMap(): void
    {
        // typography: shadowed by heading's defaults; shadow: not permitted on heading (skipped).
        $this->savePreset('probe-heavy', ['typography' => ['size' => '3rem', 'weight' => '800'], 'shadow' => ['box' => 'none']]);
        $band_only = ['component' => 'grid', 'id' => 'pp-a1b2c3d4', 'udc' => ['heading' => ['_preset' => 'probe-heavy']],
                      'props' => ['title' => 'G', 'items' => [['id' => 'it-0000abcd', 'title' => 'One']]]];
        $premise = array_column(pp_udc_composition_findings([$band_only]), 'type');
        $this->assertContains('udc_preset_value_shadowed_by_role_default', $premise, 'premise: at band grain it speaks');
        $this->assertContains('udc_preset_groups_skipped', $premise, 'premise: and so does the skipped arm');

        $on_card = ['component' => 'grid', 'id' => 'pp-a1b2c3d4',
                    'props' => ['title' => 'G', 'items' => [['id' => 'it-0000abcd', 'title' => 'One',
                        'udc' => ['heading' => ['_preset' => 'probe-heavy']]]]]];
        $messages = array_column(array_filter(
            pp_udc_composition_findings([$on_card]),
            static fn (array $f): bool => in_array($f['type'], ['udc_preset_value_shadowed_by_role_default', 'udc_preset_groups_skipped'], true)
        ), 'message');
        $this->assertSame([], array_values($messages), 'heading is not an item role on grid');
    }

    /** A fill that came from a PRESET is named as possibly a preset's, not as the author's own. */
    public function testAPresetSuppliedFillIsNamedAsPossiblyAPresets(): void
    {
        $this->savePreset('probe-tint', ['background' => ['fill' => '#123456']]);
        [, $result] = $this->page([[
            'component' => 'grid',
            'udc'       => ['heading' => ['_preset' => 'probe-tint', 'background' => ['overlay' => 'rgba(0,0,0,0.4)']]],
            'props'     => ['title' => 'G', 'items' => [['title' => 'One']]],
        ]]);

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('supplied by a preset', $found[0]['message']);
    }

    /**
     * A VALUE THE AUTHOR ALREADY WROTE IS NOT "NOT APPLIED". The shadow finding tells the
     * author to write the value in their own map; where the same map already sets that
     * parameter (and state), the author's value paints and the advice is noise.
     */
    public function testTheShadowFindingSkipsValuesTheSameMapAlreadySets(): void
    {
        $this->savePreset('probe-shadow3', ['typography' => ['size' => '3rem', 'weight' => '800', 'color' => '#aa0000']]);

        [, $all] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-shadow3',
            'typography' => ['size' => '2rem', 'weight' => '700', 'color' => '#111111']]]));
        $this->assertSame([], $this->findingsOfType($all, 'udc_preset_value_shadowed_by_role_default'), 'every shadowed value is authored');

        [, $some] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-shadow3',
            'typography' => ['size' => '2rem']]]), 'partly authored');
        $found = $this->findingsOfType($some, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString('typography.size', $found[0]['message']);
        $this->assertStringContainsString('typography.weight', $found[0]['message']);
    }

    /**
     * The reverse of the card case: a scrim on the BAND's map, over images the cards set
     * for the same role. Each card's own image replaces its whole background, so the band
     * scrim reaches no card — and the reason must say so rather than "set background.image".
     */
    public function testABandOverlayOverCardImagesGetsATruthfulReason(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $result] = $this->page($this->grid(
            ['card' => ['background' => ['image' => 9001]]],
            ['card' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]]
        ));

        $found = $this->findingsOfType($result, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString("card's own map", $found[0]['message']);
        $this->assertStringNotContainsString('or remove the overlay', $found[0]['message'], 'not the generic reason');
        $this->assertStringNotContainsString('or set (or re-import)', $found[0]['message'],
            'that advice leads to a band image the card-set images still hide, silently');
    }

    /** An authored STATE value is left out of the shadow finding at state grain only. */
    public function testAnAuthoredStateValueIsLeftOutOfTheShadowFinding(): void
    {
        $this->savePreset('probe-hov', ['typography' => [':hover' => ['color' => '#aa0000', 'decoration' => 'none']]]);
        [, $r] = $this->page($this->grid(['card-link' => ['_preset' => 'probe-hov', 'typography' => [':hover' => ['color' => '#111111']]]]));

        $found = $this->findingsOfType($r, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $found);
        $this->assertStringNotContainsString('typography.color (:hover)', $found[0]['message']);
        $this->assertStringContainsString('typography.decoration (:hover)', $found[0]['message']);
    }

    /** The shadow memo is keyed by role: two roles on one card, one preset, two answers. */
    public function testTheShadowMemoIsKeyedByRole(): void
    {
        $this->savePreset('probe-two', ['typography' => ['letter-spacing' => '0.1em'], 'spacing' => ['margin-bottom' => '0']]);
        [, $r] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-two'], 'card-text' => ['_preset' => 'probe-two']]));

        $found = $this->findingsOfType($r, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(2, $found);
        foreach ($found as $f) {
            if (str_contains($f['message'], 'role "card-title"')) {
                $this->assertStringNotContainsString('margin-bottom', $f['message']);
            } else {
                $this->assertStringNotContainsString('letter-spacing', $f['message']);
            }
        }
    }

    /** Only a card IMAGE on the SAME role earns the cross-grain band-scrim reason. */
    public function testABandScrimIsNotBlamedOnCardsThatSetNoImageForItsRole(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        foreach ([['card' => ['background' => ['fill' => '#111111']]], ['card-media' => ['background' => ['image' => 9001]]]] as $n => $card_udc) {
            [, $r] = $this->page($this->grid($card_udc, ['card' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]]), 'p' . $n);
            $found = $this->findingsOfType($r, 'udc_overlay_without_image');
            $this->assertCount(1, $found);
            $this->assertStringNotContainsString("card's own map", $found[0]['message']);
        }
    }

    /** Card images outrank a band fill in the scrim reason: the scrim reaches no card either way. */
    public function testCardImagesOutrankABandFillInTheScrimReason(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $r] = $this->page($this->grid(
            ['card' => ['background' => ['image' => 9001]]],
            ['card' => ['background' => ['fill' => '#000000', 'overlay' => 'rgba(0,0,0,0.5)']]]
        ));

        $found = $this->findingsOfType($r, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString("card's own map", $found[0]['message']);
    }

    /**
     * The cross-grain band-scrim reason allows for a deleted band image, and counts only
     * a card image on an ITEM role (any other role is dropped whole on a card).
     */
    public function testTheCardImageReasonAllowsADeletedBandImageAndOnlyItemRoles(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [, $r] = $this->page($this->grid(
            ['card' => ['background' => ['image' => 9001]]],
            ['card' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]]
        ));
        $found = $this->findingsOfType($r, 'udc_overlay_without_image');
        $this->assertStringContainsString('deleted', $found[0]['message']);

        $stored = ['component' => 'grid', 'id' => 'pp-a1b2c3d4',
                   'udc'   => ['heading' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]],
                   'props' => ['title' => 'G', 'items' => [['id' => 'it-0000abcd', 'title' => 'One',
                       'udc' => ['heading' => ['background' => ['image' => 9001]]]]]]];
        $overlay = array_values(array_filter(
            pp_udc_composition_findings([$stored]),
            static fn (array $f): bool => $f['type'] === 'udc_overlay_without_image'
        ));
        $this->assertCount(1, $overlay);
        $this->assertStringNotContainsString("card's own map", $overlay[0]['message'], 'heading is not an item role');
    }

    /**
     * LINEAR IN THE ROLE MAP. A raw-stored role map with thousands of group keys, each a
     * group-grain `_preset`, must not rebuild the authored-label list per reference
     * (measured O(G^2): 2.7 s at 4,000 keys, 0 ms on main). Groups the role does not permit
     * are not resolved at all — the shadow helper ignores them anyway.
     */
    public function testAHostileRoleMapOfPresetGroupsStaysLinear(): void
    {
        $this->savePreset('probe-grp', ['size' => '3rem'], 'typography');
        $role_map = [];
        for ($k = 0; $k < 4000; $k++) {
            $role_map['g' . $k] = ['_preset' => 'probe-grp', 'size' => '1rem'];
        }
        $band = ['component' => 'grid', 'id' => 'pp-a1b2c3d4', 'udc' => ['heading' => $role_map],
                 'props' => ['title' => 'G', 'items' => [['id' => 'it-0000abcd', 'title' => 'One']]]];

        $started = microtime(true);
        pp_udc_composition_findings([$band]);
        $this->assertLessThan(0.3, microtime(true) - $started);
    }

    /**
     * AN AUTHORED VALUE COUNTS ONLY WHERE IT PAINTS. Written at phone alone, it leaves the
     * desktop tier to the role default, which still outranks the preset there — so the
     * shadow finding must stay.
     */
    public function testAPhoneOnlyAuthoredValueDoesNotHideTheShadowFinding(): void
    {
        $this->savePreset('probe-bp', ['typography' => ['size' => '3rem']]);
        [, $r] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-bp', 'typography' => ['size' => ['p' => '1rem']]]]));

        $found = $this->findingsOfType($r, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('typography.size', $found[0]['message']);
    }

    /** A card image whose attachment is gone hides nothing: it is not counted as a card image. */
    public function testADeletedCardImageDoesNotEarnTheCardImageReason(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        [$id] = $this->page($this->grid(
            ['card' => ['background' => ['image' => 9001]]],
            ['card' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]]
        ));
        unset($GLOBALS['_pp_test_store']['posts'][9001], $GLOBALS['_pp_test_store']['attachment_is_image'][9001]);

        $found = array_values(array_filter(
            pp_udc_composition_findings(pp_get_composition($id)),
            static fn (array $f): bool => $f['type'] === 'udc_overlay_without_image'
        ));
        $band = array_values(array_filter($found, static fn (array $f): bool => !str_contains($f['message'], 'item "')));
        $this->assertCount(1, $band);
        $this->assertStringNotContainsString('replaces that card', $band[0]['message']);
    }

    /**
     * THE PRESET SIDE IS PER TIER TOO. The emitter ranks per breakpoint, so a preset value
     * set at a tier the role default does not cover paints there. A preset tier the
     * default never covers is not "not applied"; a value that loses only some tiers says
     * which.
     */
    public function testTheShadowFindingComparesPresetAndDefaultPerBreakpoint(): void
    {
        // card-title's default line-height is a single (base-tier) value.
        $this->savePreset('probe-phone', ['typography' => ['line-height' => ['p' => '2']]]);
        [, $phone] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-phone']]));
        $this->assertSame([], $this->findingsOfType($phone, 'udc_preset_value_shadowed_by_role_default'), 'the phone value paints');

        $this->savePreset('probe-both', ['typography' => ['line-height' => ['d' => '1.9', 'p' => '2']]]);
        [, $both] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-both']]), 'both tiers');
        $found = $this->findingsOfType($both, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $found);
        $this->assertMatchesRegularExpression('/typography\.line-height at breakpoint d(?![\/\w])/', $found[0]['message']);
        $this->assertStringNotContainsString('d/p', $found[0]['message'], 'only the LOST tier is named');
    }

    /** A card image supplied by a card-level PRESET hides the band scrim just like an authored one. */
    public function testAPresetSuppliedCardImageEarnsTheCardImageReason(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        $this->savePreset('probe-img', ['background' => ['image' => 9001]]);
        [, $r] = $this->page($this->grid(
            ['card' => ['_preset' => 'probe-img']],
            ['card' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]]
        ));

        $found = $this->findingsOfType($r, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString("card's own map", $found[0]['message']);
    }

    /** An authored value at the SAME narrower tier the default took from the preset covers it. */
    public function testAnAuthoredNarrowerTierCoversTheSameLostTier(): void
    {
        $this->savePreset('probe-p', ['typography' => ['line-height' => ['d' => '1.9', 'p' => '2']]]);
        // card-title's default line-height is base-tier only, so the preset loses only `d`;
        // an author value at `d` removes it, one at `p` alone does not.
        [, $d] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-p', 'typography' => ['line-height' => ['d' => '1.5']]]]));
        $this->assertSame([], $this->findingsOfType($d, 'udc_preset_value_shadowed_by_role_default'));

        [, $p] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-p', 'typography' => ['line-height' => ['p' => '1.5']]]]), 'p only');
        $this->assertCount(1, $this->findingsOfType($p, 'udc_preset_value_shadowed_by_role_default'));

        // A default that covers `p` too: the author's `p` value covers that lost tier.
        $this->savePreset('probe-size', ['typography' => ['size' => ['p' => '2rem']]]);
        $roles = pp_udc_component_roles('grid');
        $default_size = $roles['card-title']['defaults']['typography']['size'] ?? null;
        if (!is_array($default_size) || !array_key_exists('p', $default_size)) {
            $this->markTestIncomplete('premise: needs a role default with a `p` tier for size');
        }
        [, $cover] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-size', 'typography' => ['size' => ['p' => '1.5rem']]]]), 'cover p');
        $this->assertSame([], $this->findingsOfType($cover, 'udc_preset_value_shadowed_by_role_default'));
    }

    /** A card's own image whose attachment is gone falls back to its preset's image, as the emitter does. */
    public function testADeletedOwnCardImageFallsBackToThePresetImage(): void
    {
        foreach ([9001, 9002] as $att) {
            $GLOBALS['_pp_test_store']['posts'][$att]               = ['post_type' => 'attachment'];
            $GLOBALS['_pp_test_store']['attachment_is_image'][$att] = true;
        }
        $this->savePreset('probe-img2', ['background' => ['image' => 9002]]);
        [$id] = $this->page($this->grid(
            ['card' => ['_preset' => 'probe-img2', 'background' => ['image' => 9001]]],
            ['card' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]]
        ));
        unset($GLOBALS['_pp_test_store']['posts'][9001], $GLOBALS['_pp_test_store']['attachment_is_image'][9001]);

        $this->assertSame(9002, _pp_udc_role_map_background_image(pp_get_composition($id)[0]['props']['items'][0]['udc']['card']));
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

    /**
     * A GROUP-GRAIN background preset supplies a card image too. _pp_udc_role_map_background_image()
     * reads `background._preset` before the role-grain `_preset`, as the emitter does; a
     * card image arriving that way hides the band scrim like any other.
     */
    public function testAGroupGrainBackgroundPresetImageEarnsTheCardImageReason(): void
    {
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        $this->savePreset('probe-gimg', ['image' => 9001], 'background');

        $this->assertSame(9001, _pp_udc_role_map_background_image(['background' => ['_preset' => 'probe-gimg']]));

        [, $r] = $this->page($this->grid(
            ['card' => ['background' => ['_preset' => 'probe-gimg']]],
            ['card' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]]
        ));
        $found = $this->findingsOfType($r, 'udc_overlay_without_image');
        $this->assertCount(1, $found);
        $this->assertStringContainsString("card's own map", $found[0]['message']);
    }

    /**
     * The pre-filter's guard rails: a non-map is no candidate, a non-string `_preset` is not
     * resolved, a map deeper than any real one is answered "compile", and the memo records
     * one answer per preset name.
     */
    public function testThePreFilterGuardRailsAndMemo(): void
    {
        $this->assertFalse(_pp_udc_map_may_carry_overlay('not-a-map'));
        $this->assertFalse(_pp_udc_map_may_carry_overlay(['card' => ['_preset' => ['not', 'a', 'name']]]));

        $deep = ['typography' => ['weight' => '800']];
        for ($k = 0; $k < 10; $k++) {
            $deep = ['n' => $deep];
        }
        $this->assertTrue(_pp_udc_map_may_carry_overlay($deep), 'a shape it cannot see through is compiled, not skipped');

        $this->savePreset('probe-scrim', ['background' => ['overlay' => '#112233']]);
        $this->savePreset('probe-plain', ['typography' => ['weight' => '800']]);
        $memo = [];
        $this->assertTrue(_pp_udc_map_may_carry_overlay(['card' => ['_preset' => 'probe-scrim']], 0, false, $memo));
        $this->assertFalse(_pp_udc_map_may_carry_overlay(['card' => ['_preset' => 'probe-plain']], 0, false, $memo));
        $this->assertSame(['probe-scrim' => true, 'probe-plain' => false], $memo);
    }

    /**
     * The model is told about what the engine emits: the overlay finding type (read back
     * from a real write, not typed here) is named in the runtime prompt, and the prompt and
     * delete_preset's description both say a CARD reference blocks a delete.
     */
    public function testThePromptNamesTheOverlayFindingAndCardReferences(): void
    {
        [, $result] = $this->page([[
            'component' => 'section',
            'udc'       => ['_band' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]);
        $types = array_unique(array_column($result['findings'] ?? [], 'type'));
        $this->assertContains('udc_overlay_without_image', $types, 'premise: the engine emits it');

        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('udc_overlay_without_image', $prompt, 'a finding the model is never told about is one it ignores');
        $this->assertStringContainsString('band, card or chrome role still references', $prompt);
        $this->assertStringContainsString('item "<id>"', (string) pp_get_action('delete_preset')['description']);
    }

    /** Every preset rung is resolve-checked too: a deleted group-preset image falls through to the role preset's. */
    public function testADeletedGroupPresetImageFallsThroughToTheRolePresetImage(): void
    {
        foreach ([9001, 9002] as $att) {
            $GLOBALS['_pp_test_store']['posts'][$att]               = ['post_type' => 'attachment'];
            $GLOBALS['_pp_test_store']['attachment_is_image'][$att] = true;
        }
        $this->savePreset('probe-gimg1', ['image' => 9001], 'background');
        $this->savePreset('probe-rimg2', ['background' => ['image' => 9002]]);
        unset($GLOBALS['_pp_test_store']['posts'][9001], $GLOBALS['_pp_test_store']['attachment_is_image'][9001]);

        $this->assertSame(9002, _pp_udc_role_map_background_image(
            ['_preset' => 'probe-rimg2', 'background' => ['_preset' => 'probe-gimg1']]
        ));
    }

    /** A base-tier authored value covers EVERY tier the default took from the preset. */
    public function testABaseTierAuthoredValueCoversEveryLostTier(): void
    {
        $this->savePreset('probe-dp', ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]);
        [, $none] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-dp']]), 'none');
        $this->assertCount(1, $this->findingsOfType($none, 'udc_preset_value_shadowed_by_role_default'), 'premise');

        [, $single] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-dp', 'typography' => ['size' => '2rem']]]), 'single');
        $this->assertSame([], $this->findingsOfType($single, 'udc_preset_value_shadowed_by_role_default'));
        [, $dmap] = $this->page($this->grid(['card-title' => ['_preset' => 'probe-dp', 'typography' => ['size' => ['d' => '2rem']]]]), 'dmap');
        $this->assertSame([], $this->findingsOfType($dmap, 'udc_preset_value_shadowed_by_role_default'));
    }

    /** A band-map reference is still seen on a band whose cards carry their own maps. */
    public function testABandReferenceOnACardStyledBandIsStillRefused(): void
    {
        $this->savePreset('probe-type', ['typography' => ['weight' => '800']]);
        $this->page($this->grid(['card' => ['background' => ['fill' => '#111111']]], ['card-title' => ['_preset' => 'probe-type']]));

        $result = pp_execute_action('delete_preset', ['name' => 'probe-type']);
        $this->assertSame('preset_in_use', $result['error_code'] ?? null);
    }

    /** Band-map rows come first, so card rows cannot take the shared row budget from them. */
    public function testBandMapDropsWinTheSharedRowBudget(): void
    {
        $items = [];
        for ($c = 0; $c < 12; $c++) {
            $items[] = ['title' => 'C' . $c, 'udc' => ['card' => ['background' => ['fill' => '#000000', 'overlay' => '#112233']]]];
        }
        [$id] = $this->page([
            ['component' => 'grid', 'props' => ['title' => 'G', 'items' => $items]],
            ['component' => 'section', 'udc' => ['_band' => ['background' => ['overlay' => 'rgba(0,0,0,0.5)']]], 'props' => ['title' => 'S', 'body' => 'b']],
        ]);

        $this->assertStringContainsString('band 2', (string) json_encode(pp_check_udc_emit_drops($id, pp_get_composition($id))));
    }

    /** The overlay row respects the per-compile ledger bound on a band with unbounded cards. */
    public function testTheOverlayLedgerRowIsBoundedPerCompile(): void
    {
        $items = [];
        for ($c = 0; $c < 210; $c++) {
            $items[] = ['id' => sprintf('it-%08x', $c + 1), 'title' => 'C' . $c,
                        'udc' => ['card' => ['background' => ['fill' => '#000000', 'overlay' => '#112233']]]];
        }
        $drops = [];
        pp_udc_compile_band(['component' => 'grid', 'id' => 'pp-a1b2c3d4', 'props' => ['title' => 'G', 'items' => $items]], 'authored', $drops);

        $this->assertGreaterThanOrEqual(PP_UDC_MAX_EMIT_DROPS, count($drops), 'premise: the fixture reaches the cap');
        $this->assertLessThanOrEqual(PP_UDC_MAX_EMIT_DROPS, count($drops));
    }

    /** The shadow memo is keyed by component: one preset on the same role name of two components. */
    public function testTheShadowMemoIsKeyedByComponent(): void
    {
        $this->savePreset('probe-hd', ['typography' => ['weight' => '800']]);
        [, $r] = $this->page([
            ['component' => 'cta', 'udc' => ['heading' => ['_preset' => 'probe-hd']], 'props' => ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/x']],
            ['component' => 'grid', 'udc' => ['heading' => ['_preset' => 'probe-hd']], 'props' => ['title' => 'G', 'items' => [['title' => 'One']]]],
        ]);

        $found = $this->findingsOfType($r, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('Component "grid"', $found[0]['message']);
    }

    /** A phone-only authored STATE value does not hide the desktop state loss either. */
    public function testAPhoneOnlyAuthoredStateValueDoesNotHideTheShadowFinding(): void
    {
        $this->savePreset('probe-hov2', ['typography' => [':hover' => ['color' => '#aa0000']]]);
        [, $r] = $this->page($this->grid(['card-link' => ['_preset' => 'probe-hov2', 'typography' => [':hover' => ['color' => ['p' => '#111111']]]]]));

        $found = $this->findingsOfType($r, 'udc_preset_value_shadowed_by_role_default');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('typography.color (:hover)', $found[0]['message']);
    }

    /** Per tier inside a STATE too: a preset :hover value only at phone paints over a base-only default. */
    public function testAStatePresetTierTheDefaultNeverCoversIsNotReported(): void
    {
        $this->savePreset('probe-hov3', ['typography' => [':hover' => ['color' => ['p' => '#aa0000']]]]);
        [, $r] = $this->page($this->grid(['card-link' => ['_preset' => 'probe-hov3']]));

        $this->assertSame([], $this->findingsOfType($r, 'udc_preset_value_shadowed_by_role_default'));
    }
}
