<?php
/**
 * ITEM-GRAIN UDC, PINNED AT THE GRAIN AN AUTHOR ACTUALLY WRITES AT (#1101, Addendum B).
 *
 * WHY THIS FILE EXISTS. Addendum B gives a single `items[]` entry its own `udc` map, so
 * one card in an otherwise uniform grid can be dark while its siblings stay light. That
 * is the owner's live design language — measured on 4 of his 5 content pages, cards 01
 * and 03 light and card 02 dark — and it is the capability Sprint 3's reconstruction is
 * gated on. It is also a WRITE PATH, a MINTING path, a CASCADE tier and a DISCLOSURE
 * channel all at once, and until this file existed not one of those four had a test.
 *
 * THE TRAP THIS FILE WAS WRITTEN AGAINST, stated because it nearly swallowed the
 * feature twice. The item tier is reachable only from a component that declares
 * `item_roles`, and until grid shipped, NOTHING DID. So every arm of the engine could be
 * written, committed and run against a fully green suite while being permanently
 * unreachable — and one of them was: the mint classifier's first fix was itself broken
 * with the whole suite green over it, because nothing exercised the path. A green run
 * proves nothing about code nothing calls. Every negative control below therefore
 * carries a PLANTED-DEFECT PROOF, run in a copy of the tree (14.7): the guard was
 * removed or inverted, the test was confirmed RED, and the tree was restored. Nineteen
 * defects were planted; eighteen went red on the first pass.
 *
 * THE NINETEENTH DID NOT, AND IT WAS THE MOST IMPORTANT ONE. Planting
 * `pp_udc_validate_map($item['udc'], $name, [])` at the write gate — restoring the
 * catastrophe where the engine permanently refuses its own stored output — left the
 * classifier test GREEN, because that test handed the classifier its item maps ITSELF
 * and so only ever proved the classifier right in isolation. A control that supplies
 * the thing it is testing for is not a control, and this is the same shape as the
 * original defect rather than a new one. It is now pinned through a real round trip
 * (write, read back, re-send), which is what restore, `wp pp check page` and every
 * subsequent edit actually do. Recorded here rather than quietly fixed, because the
 * next author writing an item-grain test will reach for the same shortcut.
 *
 * WHAT IT COVERS, in the order the contract states it:
 *   B1  an item map validates through the same engine, the same grammar, the same codes
 *   B2  `it-<hex8>` ids: minted on write only, only for entries carrying a map, honoured
 *       never overwritten, carried by index, cleared with the map, unique within the band
 *   B3  emission: band-scoped selectors, the item tier printing LAST, no attribute at all
 *       when the id is absent or malformed
 *   B4  provenance and all four disclosure families, through the same predicate
 *   B6  the seven exclusions, each REFUSING rather than being ignored
 *   D6  `update_component` preserving `id`/`udc` by index instead of eating them
 *
 * AUTHORED THROUGH THE REAL SURFACE (Section 14.1). Raw `_pp_composition` meta writes
 * bypass every gate this file is about, so the write-path tests go through
 * `pp_execute_action()` and `pp_update_composition()`. Where a test needs a shape the
 * write path would refuse, it says so and reads the engine directly — which is the other
 * half of the same contract, because stored bytes no gate ever saw still have to render
 * safely (#233).
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;

class GridItemUdcTest extends TestCase
{
    /** The owner's production dark card, verbatim from the T4 live read (#1023). */
    private const DARK_CARD = [
        'card'       => ['background' => ['fill' => '#14141F'], 'border' => ['color' => '#0A0A12']],
        'card-title' => ['typography' => ['color' => '#F2EEE5']],
        'card-text'  => ['typography' => ['color' => '#E8E2D4']],
    ];

    /** A three-card band, card 02 dark — the shape 4 of the owner's 5 content pages hold. */
    private function ownersBand(): array
    {
        return [
            'component' => 'grid',
            'props'     => [
                'title' => 'How it works',
                'items' => [
                    ['title' => '01', 'text' => 'one'],
                    ['title' => '02', 'text' => 'two', 'udc' => self::DARK_CARD],
                    ['title' => '03', 'text' => 'three'],
                ],
            ],
        ];
    }

    /** Writes a composition through the real action surface and returns the envelope. */
    private function write(int $post_id, array $composition): array
    {
        return pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $composition,
        ]);
    }

    private function newPage(string $title): int
    {
        return pp_create_page($title, 'draft');
    }

    /**
     * Renders grid's template, buffered.
     *
     * `ob_get_clean()` runs in a `finally` for the reason ComponentPropsTest records: a
     * template that throws mid-render would otherwise leave the buffer open and swallow
     * output later in the same process, turning a regression into a mystery.
     */
    private function render(array $props): string
    {
        ob_start();
        try {
            pp_get_component('grid', $props);
        } finally {
            $html = ob_get_clean();
        }
        return (string) $html;
    }

    /** Validates one item map against grid's own declaration. */
    private function validateItemMap($map, array $band_tokens = []): ?WP_Error
    {
        return pp_udc_validate_item_map($map, 'grid', pp_udc_item_roles('grid'), $band_tokens, 'item 1');
    }

    // ───────────────────────────────────────────────────────────────────────
    // B1/B2/B3 — the write path, end to end through the real surface
    // ───────────────────────────────────────────────────────────────────────

    /**
     * THE HEADLINE CAPABILITY, AUTHORED THE WAY AN AUTHOR AUTHORS IT (14.1).
     *
     * One write, through the real action, carrying the owner's production dark card. Four
     * things have to be true afterwards and each one is a different layer: the write is
     * ACCEPTED, an id is MINTED for the one entry that carries a map and for no other, the
     * emitted CSS carries all three of B3's selector shapes, and the template renders the
     * attribute the selectors are keyed on. Any one of them failing ships a stored design
     * that paints nothing.
     */
    public function testTheOwnersDarkCardAuthorsEndToEndAndEmitsAllThreeSelectorShapes(): void
    {
        $post_id = $this->newPage('item udc end to end');
        $result  = $this->write($post_id, [$this->ownersBand()]);

        $this->assertTrue($result['ok'], 'the production dark-card write was refused: ' . ($result['error'] ?? ''));

        $stored = pp_get_composition($post_id);
        $items  = $stored[0]['props']['items'];

        // B2: minted for the styled entry only. An id on every list entry would change
        // stored shape for no reader and put a meaningless attribute on every card.
        $this->assertArrayNotHasKey('id', $items[0], 'an unstyled card must carry no id');
        $this->assertArrayNotHasKey('id', $items[2], 'an unstyled card must carry no id');
        $item_id = $items[1]['id'] ?? '';
        $this->assertTrue(
            pp_udc_valid_item_id($item_id),
            "the styled card's minted id must be `it-` + eight lowercase hex; got " . var_export($item_id, true)
        );

        // B3: the band block carries all three shapes, keyed on the band AND the item.
        $band_id = $stored[0]['id'];
        $css     = pp_udc_band_css($stored[0]);

        $this->assertStringContainsString(
            '[data-pp-band="' . $band_id . '"] [data-pp-item="' . $item_id . '"]{',
            $css,
            'the ROOT role must emit under the attribute alone — the element already carries the class'
        );
        $this->assertStringContainsString(
            '[data-pp-band="' . $band_id . '"] [data-pp-item="' . $item_id . '"] .grid__item-title{',
            $css,
            'a non-root role must emit under the attribute PLUS its own class'
        );
        $this->assertStringContainsString('background:#14141F;', $css);
        $this->assertStringContainsString('color:#F2EEE5;', $css);

        // EVERY item selector is band-scoped. An unscoped `[data-pp-item="…"]` would reach
        // an identically-minted item in another band — which B2 permits, because uniqueness
        // is within the band precisely BECAUSE the selector always carries the band.
        preg_match_all('/([^{}]*\[data-pp-item="[^"]*"\][^{}]*)\{/', $css, $m);
        $this->assertNotEmpty($m[1], 'no item-keyed selector emitted at all');
        foreach ($m[1] as $selector) {
            $this->assertStringContainsString(
                '[data-pp-band="' . $band_id . '"]',
                $selector,
                "item selector `{$selector}` is not band-scoped, so it reaches other bands' cards"
            );
        }

        // And the template renders the handle the selectors are keyed on.
        $html = $this->render($stored[0]['props']);
        $this->assertStringContainsString('data-pp-item="' . $item_id . '"', $html);
        $this->assertSame(
            1,
            substr_count($html, 'data-pp-item='),
            'exactly one card carries the attribute: the one that carries a map'
        );
        // ASSERTED STRUCTURALLY, NOT AS A BYTE SEQUENCE, AND THE FIRST CUT WAS VACUOUS.
        //
        // This read `assertStringNotContainsString('<li class="grid__item" style=', …)`,
        // which cannot fail for the regression it names: `$item_attr` emits `data-pp-item`
        // FIRST, so a restored per-item inline style renders
        // `<li class="grid__item" data-pp-item="it-…" style="…">` and the literal never
        // matches. PROVEN by planting exactly the v1 `items[].style` shape in a copy of
        // the tree — a `style="background:#14141F"` on every styled card — and watching
        // the ENTIRE PHP suite stay green: 5174 tests, 0 failures.
        //
        // So the tag is parsed and every card's open tag is checked, styled and unstyled
        // alike. §3.4 forbids inline style emission outright; the claim is about the
        // ELEMENT, so the assertion has to be about the element rather than about one
        // spelling of it.
        preg_match_all('/<li class="grid__item"[^>]*>/', $html, $tags);
        $this->assertNotEmpty($tags[0], 'no card tags were parsed — the assertion below would be vacuous');
        $this->assertCount(3, $tags[0], 'all three cards must be parsed, or an unstyled sibling escapes the check');
        foreach ($tags[0] as $tag) {
            $this->assertStringNotContainsString(
                'style=',
                $tag,
                '§3.4 forbids inline style emission outright — that is what items[].udc replaced'
            );
        }
    }

    /**
     * THE ITEM TIER PRINTS LAST, AND FOR THE ROOT ROLE THAT IS THE WHOLE MECHANISM.
     *
     * For a non-root role the item selector carries one more attribute than the band's and
     * wins on specificity whatever the order. For the ROOT role the two are both (0,2,0) —
     * `[data-pp-band] [data-pp-item]` against `[data-pp-band] .grid__item` — so the tie
     * breaks on SOURCE ORDER and nothing else. §3.4 forbids `!important` outright, so
     * there is no second mechanism to fall back on: if the item block ever printed first,
     * a band-level card fill would silently beat every per-card fill on the page and the
     * headline capability would stop working with every test that checks emission still
     * green.
     *
     * PLANTED-DEFECT PROOF: moving the item-tier loop in `pp_udc_compile_band()` above the
     * role loop takes this test RED on the ordering assertion while the two "contains"
     * assertions above stay green — confirming the ordering claim is carried here and
     * nowhere else.
     */
    public function testTheItemBlockPrintsAfterTheBandBlockForTheSameRootRole(): void
    {
        $post_id = $this->newPage('item tier order');
        $band    = $this->ownersBand();
        // BOTH tiers write the SAME role and the SAME parameter, which is the only
        // arrangement where the tie exists at all.
        $band['udc'] = ['card' => ['background' => ['fill' => '#ffffff']]];
        $this->assertTrue($this->write($post_id, [$band])['ok']);

        $stored  = pp_get_composition($post_id);
        $band_id = $stored[0]['id'];
        $item_id = $stored[0]['props']['items'][1]['id'];
        $css     = pp_udc_band_css($stored[0]);

        $band_at = strpos($css, '[data-pp-band="' . $band_id . '"] .grid__item{');
        $item_at = strpos($css, '[data-pp-band="' . $band_id . '"] [data-pp-item="' . $item_id . '"]{');
        $this->assertIsInt($band_at, 'the band tier did not emit its card block');
        $this->assertIsInt($item_at, 'the item tier did not emit its root block');
        $this->assertGreaterThan(
            $band_at,
            $item_at,
            'the two selectors both weigh (0,2,0): print the item block first and the band fill '
            . 'wins on every card, silently defeating the whole item tier'
        );
    }

    /**
     * AN ID IS MINTED ONLY FOR AN ENTRY THAT CARRIES A MAP, AND NEVER OVERWRITTEN.
     *
     * Both halves are B2 and both matter. Minting for every entry would put a meaningless
     * attribute on every card and change stored shape for no reader; overwriting an
     * already-valid id would break every rule already keyed on it the moment anything
     * re-applied the array.
     */
    public function testAnIdIsMintedOnlyForMappedEntriesAndAnExistingValidIdIsHonoured(): void
    {
        $post_id = $this->newPage('item id lifecycle');
        $this->assertTrue($this->write($post_id, [$this->ownersBand()])['ok']);
        $first = pp_get_composition($post_id)[0]['props']['items'][1]['id'];

        // Re-applying the whole array with the id already on it must not re-mint.
        $band = $this->ownersBand();
        $band['props']['items'][1]['id'] = $first;
        $this->assertTrue($this->write($post_id, [$band])['ok']);
        $this->assertSame(
            $first,
            pp_get_composition($post_id)[0]['props']['items'][1]['id'],
            'an already-minted valid id is honoured, never overwritten (B2)'
        );

        // And a band whose component declares no item grain is untouched: `section`'s
        // `panel_items` is the second live instance of this gap and is NOT wired yet, so
        // this is also the pin that keeps the engine from growing a component list.
        $this->assertNull(
            pp_udc_item_roles('testimonials'),
            'only components that DECLARE item_roles have an item tier — the engine holds no '
            . 'list of component names (B5)'
        );
    }

    /**
     * AN ID IS CARRIED FORWARD BY INDEX, AND CLEARED WHEN ITS MAP GOES.
     *
     * The carry is what makes the design travel with the CARD rather than with position 2.
     * The clearing half is not optional and is easy to forget: honouring "no map, no id"
     * only at MINT time would let an id outlive the map that justified it, so a card whose
     * design was deleted would keep emitting a `data-pp-item` attribute with no rules
     * behind it — a handle to nothing.
     */
    public function testAnIdIsCarriedByIndexAndClearedWhenItsMapIsRemoved(): void
    {
        $post_id = $this->newPage('item id carry');
        $this->assertTrue($this->write($post_id, [$this->ownersBand()])['ok']);
        $minted = pp_get_composition($post_id)[0]['props']['items'][1]['id'];

        // A full re-apply that does NOT send the id: same index, same component, same map.
        $band = $this->ownersBand();
        $band['props']['items'][1]['text'] = 'two, edited';
        $this->assertTrue($this->write($post_id, [$band])['ok']);
        $after = pp_get_composition($post_id)[0]['props']['items'][1];
        $this->assertSame($minted, $after['id'], 'the id is carried forward by index + component match');
        $this->assertSame('two, edited', $after['text']);

        // Now the design is deleted. The handle must go with it.
        $band = $this->ownersBand();
        unset($band['props']['items'][1]['udc']);
        $band['props']['items'] = array_values($band['props']['items']);
        $this->assertTrue($this->write($post_id, [$band])['ok']);
        $this->assertArrayNotHasKey(
            'id',
            pp_get_composition($post_id)[0]['props']['items'][1],
            'an id that outlives its map is a handle to nothing, and would keep emitting an attribute'
        );
    }

    /**
     * TWO ENTRIES CLAIMING ONE ID REFUSE AT WRITE, MIRRORING `duplicate_component_id`.
     *
     * Two entries sharing an id share a SELECTOR, so one card's design paints on the other
     * — the same cross-apply failure a duplicate band id causes, one level down. B2 scopes
     * uniqueness to the band rather than leaving it to chance for exactly this reason.
     *
     * PLANTED-DEFECT PROOF: deleting the `$seen_item_ids` arm in `lib/admin.php` takes
     * this test RED (the write is accepted) — the control is live, not decorative.
     */
    public function testTwoEntriesClaimingOneIdRefuseAtWrite(): void
    {
        $post_id = $this->newPage('duplicate item id');
        $band    = $this->ownersBand();
        $band['props']['items'][0]['id']  = 'it-7b2c91d4';
        $band['props']['items'][0]['udc'] = self::DARK_CARD;
        $band['props']['items'][1]['id']  = 'it-7b2c91d4';

        $result = $this->write($post_id, [$band]);
        $this->assertFalse($result['ok'], 'two cards sharing one id would paint each design on both');
        $this->assertSame('duplicate_component_id', $result['error_code']);
        $this->assertStringContainsString('it-7b2c91d4', $result['error']);

        // THE SAME ID IN A DIFFERENT BAND IS FINE, and that is B2's ruling rather than an
        // oversight: the emitted selector is always band-scoped, so neither reaches the
        // other. Without this the uniqueness claim would be untestable from its negative.
        $two = $this->newPage('same id two bands');
        $a   = $this->ownersBand();
        $b   = $this->ownersBand();
        $a['props']['items'][1]['id'] = 'it-7b2c91d4';
        $b['props']['items'][1]['id'] = 'it-7b2c91d4';
        $this->assertTrue(
            $this->write($two, [$a, $b])['ok'],
            'uniqueness is WITHIN the band, because the selector always carries the band'
        );
    }

    /**
     * A FORGED OR MALFORMED ID REFUSES AT WRITE, AND EMITS NO ATTRIBUTE IF IT GETS STORED.
     *
     * TWO GATES, BOTH NEEDED, and the second is the one an author never sees. The write
     * gate refuses anything that is not `it-` + exactly eight lowercase hex. But the write
     * gate is not the only way data arrives — a raw meta write, a composition written
     * before this tier existed, and `restore_composition` (#233) all reach the template
     * directly. There the rule is stricter than at band grain: an EMPTY `data-pp-item`
     * attribute inside a band scope would match every OTHER unstyled card in the same band
     * and paint one card's design onto all of them, so the template emits nothing at all.
     *
     * A BAND-SHAPED ID IS REFUSED TOO. `pp-` is the band prefix; accepting one here would
     * let a selector, a message or a test confuse the two grains, which is the confusion
     * the distinct prefix exists to make impossible.
     *
     * PLANTED-DEFECT PROOF: relaxing `pp_udc_valid_item_id()`'s `\z` anchor to `$` takes
     * the trailing-newline case RED — the anchoring discipline is load-bearing here,
     * because this string is interpolated into a CSS attribute selector.
     */
    public function testAForgedIdRefusesAtWriteAndRendersNoAttributeIfItIsStoredAnyway(): void
    {
        foreach (
            [
                'ITEM-1'        => 'not the engine\'s shape at all',
                'it-7B2C91D4'   => 'uppercase hex',
                'it-7b2c91d'    => 'seven digits',
                'it-7b2c91d44'  => 'nine digits',
                'pp-7b2c91d4'   => 'a BAND id, which the distinct prefix exists to keep separate',
                "it-7b2c91d4\n" => 'a trailing newline, which `$` would have admitted and `\\z` refuses',
            ] as $forged => $why
        ) {
            $post_id = $this->newPage('forged id');
            $band    = $this->ownersBand();
            $band['props']['items'][1]['id'] = $forged;

            $result = $this->write($post_id, [$band]);
            $this->assertFalse($result['ok'], "the write must refuse {$why}: " . var_export($forged, true));
            $this->assertSame('invalid_prop_value', $result['error_code']);

            $this->assertFalse(pp_udc_valid_item_id($forged), "`{$why}` must not satisfy the id shape");
        }

        // The render half, on stored bytes no gate ever saw.
        $props = [
            'title' => 'T',
            'items' => [
                ['title' => '01', 'text' => 'one', 'id' => 'ITEM-1', 'udc' => self::DARK_CARD],
                ['title' => '02', 'text' => 'two'],
            ],
        ];
        $html = $this->render($props);
        $this->assertStringNotContainsString(
            'data-pp-item',
            $html,
            'an empty or malformed attribute inside a band scope would match every other id-less '
            . 'card in the band and paint one card\'s design onto all of them'
        );
        $this->assertStringContainsString('<li class="grid__item">', $html, 'the card still renders structurally');
    }

    // ───────────────────────────────────────────────────────────────────────
    // D6 — the merge that ate its own data
    // ───────────────────────────────────────────────────────────────────────

    /**
     * EDITING ONE CARD'S COPY NO LONGER DESTROYS EVERY CARD'S DESIGN (D6).
     *
     * MEASURED ON THE SHIPPED TREE BEFORE THE GUARD, on the v1 shape with the same anatomy
     * (evidence-1101/redproof-OUTPUT-on-7ddf708-BEFORE.txt):
     *
     *     before: card 2 style = {"--grid-item-bg":"#14141F"}
     *     update_component props={items: [...three cards, one word edited...]}
     *     action ok  : true
     *     findings   : []
     *     after : card 2 style = null
     *
     * So fixing one card's copy destroyed every card's design on the band, and the
     * envelope reported SUCCESS with no finding — the reported-success-without-effect
     * class inverted into reported-success-with-DAMAGE. Band `udc` is structurally immune
     * because it is a SIBLING of `props`; item `udc` sits inside it, so it needed a guard
     * the band never did.
     *
     * ALL THREE ROUTES ARE PINNED, because "preserve it" alone would make the design
     * impossible to remove: an ABSENT key is filled back in, an EXPLICIT map is taken as
     * sent, and an explicit EMPTY map clears. Widening the first to "preserve anything the
     * caller left out" would turn a replace into a merge and make deleting a field
     * impossible, which is why only the two engine-owned keys are carried.
     *
     * PLANTED-DEFECT PROOF: reverting `_pp_merge_component_props()` to the bare
     * `$merged[$key] = $value;` takes the first assertion RED with the envelope still
     * reporting `ok: true` — reproducing the original defect exactly.
     */
    public function testEditingOneCardsCopyPreservesEveryOtherCardsDesign(): void
    {
        $post_id = $this->newPage('merge preserves item design');
        $this->assertTrue($this->write($post_id, [$this->ownersBand()])['ok']);
        $minted = pp_get_composition($post_id)[0]['props']['items'][1]['id'];

        // The author fixes ONE card's copy, resending the array the way an editor would —
        // with no `id` and no `udc`, because those are not fields they author.
        $result = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['title' => '01', 'text' => 'one'],
                ['title' => '02', 'text' => 'two EDITED'],
                ['title' => '03', 'text' => 'three'],
            ]],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $after = pp_get_composition($post_id)[0]['props']['items'];
        $this->assertSame('two EDITED', $after[1]['text'], 'the caller\'s edit still lands');
        $this->assertSame($minted, $after[1]['id'] ?? null, 'the minted handle survives a copy edit');
        $this->assertSame(
            self::DARK_CARD,
            $after[1]['udc'] ?? null,
            'fixing one card\'s copy must not destroy every card\'s design — the defect D6 red-proofed'
        );

        // AN EXPLICIT EMPTY MAP CLEARS, or there would be no way to remove a design once
        // minted. Absent is not the same as empty, and the difference is the whole route.
        $this->assertTrue(pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['title' => '01', 'text' => 'one'],
                ['title' => '02', 'text' => 'two EDITED', 'udc' => []],
                ['title' => '03', 'text' => 'three'],
            ]],
        ])['ok']);
        $cleared = pp_get_composition($post_id)[0]['props']['items'][1];
        $this->assertSame([], $cleared['udc'] ?? null, 'an explicit empty map is a caller clearing the design');
        $this->assertArrayNotHasKey('id', $cleared, 'and the handle goes with it');

        // AN EXPLICIT MAP IS TAKEN AS SENT, never merged into the stored one.
        $this->assertTrue(pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['title' => '01', 'text' => 'one'],
                ['title' => '02', 'text' => 'two', 'udc' => ['card' => ['background' => ['fill' => '#123456']]]],
                ['title' => '03', 'text' => 'three'],
            ]],
        ])['ok']);
        $replaced = pp_get_composition($post_id)[0]['props']['items'][1]['udc'];
        $this->assertSame(['card' => ['background' => ['fill' => '#123456']]], $replaced);
        $this->assertArrayNotHasKey(
            'card-title',
            $replaced,
            'an explicit map replaces rather than merging — otherwise a design could never shrink'
        );
    }

    /**
     * REORDER, DELETE, AND THE ID THAT MAKES BOTH CORRECT (D6, completed).
     *
     * THE RED-PROOF SET, ENCODED. Measured through the real action surface before the
     * fix, three cards with card 02 dark:
     *
     *   deletes card 01            design + minted id MIGRATE to card 03      ok: true
     *   reorders 02 and 03         design stays on POSITION 1                 ok: true
     *   reorders, re-sends the id  REFUSED — duplicate_component_id           ok: false
     *
     * The third line is the one that settled the ruling. The index pass filled stored
     * index 1's id into the caller's index 1 while the caller had also sent it at index
     * 2, so the engine collided with itself and reported "item 1 and item 2 both claim
     * the id" — a caller doing exactly the right thing, refused by the guard meant to
     * protect them. There was no correct way to reorder a styled card at all.
     *
     * The reorder is also the ORDINAL behaviour ruling D9 retired `card_emphasis` to
     * eliminate. grid's own `retired_props` says an item is addressed by its minted id
     * "so that reordering carries styling WITH the item"; it did not.
     *
     * ID WINS, THEN POSITION — which is what "preserve by index" had to mean once ids
     * existed to win. The same-length no-id case is D6's red-proofed one and is
     * byte-identical; a LENGTH CHANGE drops rather than migrates, because position
     * demonstrably lies once an entry was added or removed and a design that vanishes is
     * visible where one on the wrong card looks deliberate.
     */
    public function testReorderAndDeleteCarryTheDesignWithTheCardWhenIdsAreSent(): void
    {
        $seed = function (): array {
            $post_id = $this->newPage('d6 ' . uniqid('', true));
            $this->assertTrue($this->write($post_id, [$this->ownersBand()])['ok']);
            return [$post_id, pp_get_composition($post_id)[0]['props']['items'][1]['id']];
        };
        $titles = static function (array $items): array {
            $out = [];
            foreach ($items as $entry) {
                $out[] = ($entry['title'] ?? '?') . (isset($entry['udc']) ? ':dark' : '');
            }
            return $out;
        };
        $patch = function (int $post_id, array $items): array {
            return pp_execute_action('update_component', [
                'post_id'         => $post_id,
                'component_index' => 0,
                'props'           => ['items' => $items],
            ]);
        };

        // 1. REORDER WITH IDS — was refused outright, now correct.
        [$post_id, $minted] = $seed();
        $result = $patch($post_id, [
            ['title' => '01', 'text' => 'one'],
            ['title' => '03', 'text' => 'three'],
            ['title' => '02', 'text' => 'two', 'id' => $minted],
        ]);
        $this->assertTrue($result['ok'], 're-sending the id must not collide with the carry: ' . ($result['error'] ?? ''));
        $this->assertSame(
            ['01', '03', '02:dark'],
            $titles(pp_get_composition($post_id)[0]['props']['items']),
            'the design follows the CARD when the caller says which card it is'
        );

        // 2. DELETE WITH IDS — the surviving styled card keeps its design.
        [$post_id, $minted] = $seed();
        $this->assertTrue($patch($post_id, [
            ['title' => '02', 'text' => 'two', 'id' => $minted],
            ['title' => '03', 'text' => 'three'],
        ])['ok']);
        $this->assertSame(
            ['02:dark', '03'],
            $titles(pp_get_composition($post_id)[0]['props']['items'])
        );

        // 3. DELETE WITHOUT IDS — drops rather than migrating. This is the behaviour
        //    change: it used to put card 02's design on card 03.
        [$post_id] = $seed();
        $this->assertTrue($patch($post_id, [
            ['title' => '02', 'text' => 'two'],
            ['title' => '03', 'text' => 'three'],
        ])['ok']);
        $this->assertSame(
            ['02', '03'],
            $titles(pp_get_composition($post_id)[0]['props']['items']),
            'a length change must not hand one card\'s design to another'
        );

        // 4. SAME LENGTH, NO IDS — D6's red-proofed case, unchanged.
        [$post_id] = $seed();
        $this->assertTrue($patch($post_id, [
            ['title' => '01', 'text' => 'one'],
            ['title' => '02', 'text' => 'two EDITED'],
            ['title' => '03', 'text' => 'three'],
        ])['ok']);
        $items = pp_get_composition($post_id)[0]['props']['items'];
        $this->assertSame(['01', '02:dark', '03'], $titles($items));
        $this->assertSame('two EDITED', $items[1]['text'], 'and the caller\'s edit still lands');
    }

    /**
     * CARRYING BY POSITION IS DISCLOSED, WITH THE ROUTE THAT REMOVES THE AMBIGUITY.
     *
     * A same-length patch that omits the ids is CORRECT whenever the caller did not
     * reorder, and wrong when they did — and nothing in the merge can tell the two apart.
     * `[{02},{03}]` is indistinguishable from "the author rewrote the copy of both
     * cards". So the write is accepted and the assumption is stated, which is this
     * program's posture for every accepted-but-possibly-not-what-you-meant write.
     *
     * ON THE WRITE'S OWN CHANNEL, because the fact cannot be derived from stored bytes:
     * once the merge has run, nothing distinguishes a design the engine carried from one
     * the caller sent. Same drain-slot shape as the history-push notice (#821).
     */
    public function testCarryingAnItemDesignByPositionIsDisclosedWithItsRoute(): void
    {
        $post_id = $this->newPage('d6 disclosure');
        $this->assertTrue($this->write($post_id, [$this->ownersBand()])['ok']);
        $minted = pp_get_composition($post_id)[0]['props']['items'][1]['id'];

        $carried = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['title' => '01', 'text' => 'one'],
                ['title' => '02', 'text' => 'two EDITED'],
                ['title' => '03', 'text' => 'three'],
            ]],
        ]);
        $this->assertTrue($carried['ok']);

        $disclosure = null;
        foreach ($carried['findings'] ?? [] as $finding) {
            if ($finding['type'] === 'udc_item_design_carried_by_position') {
                $disclosure = $finding;
            }
        }
        $this->assertNotNull($disclosure, 'an assumption the caller can remove must be stated');
        $this->assertStringContainsString('BY POSITION', $disclosure['message']);
        $this->assertStringContainsString(
            're-send entries with their ids',
            $disclosure['message'],
            'a disclosure without its route is a warning the reader cannot act on'
        );

        // AND IT IS SILENT WHEN THE CALLER REMOVED THE AMBIGUITY. A finding on a correct
        // write trains an operator to stop reading findings.
        $explicit = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['title' => '01', 'text' => 'one'],
                ['title' => '02', 'text' => 'two AGAIN', 'id' => $minted],
                ['title' => '03', 'text' => 'three'],
            ]],
        ]);
        $this->assertTrue($explicit['ok']);
        $this->assertNotContains(
            'udc_item_design_carried_by_position',
            array_column($explicit['findings'] ?? [], 'type'),
            'sending the ids is the route the message names, so taking it must silence it'
        );
    }

    /**
     * AN ITEM MAP ARRIVING THROUGH `update_component` IS VALIDATED, NOT JUST STORED (D6).
     *
     * The half of D6 that is easy to miss. A band map is a SIBLING of `props` and no
     * action can write one at all (#1088), so `pp_udc_validate_map()`'s call sites never
     * needed to descend into `props.items`. An item map rides in on an ordinary prop
     * patch, so the surface that ACCEPTS it has to be the surface that CHECKS it —
     * otherwise a malformed map stores clean and fails at render, which is the I29
     * write/render split this engine exists to prevent.
     */
    public function testAnItemMapWrittenThroughUpdateComponentIsValidated(): void
    {
        $post_id = $this->newPage('update_component validates items');
        $this->assertTrue($this->write($post_id, [$this->ownersBand()])['ok']);

        $result = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['items' => [
                ['title' => '01', 'text' => 'one'],
                ['title' => '02', 'text' => 'two', 'udc' => ['card' => ['background' => ['fill' => '@nosuchtoken']]]],
                ['title' => '03', 'text' => 'three'],
            ]],
        ]);

        $this->assertFalse($result['ok'], 'an item map on a prop patch must be validated, not merely stored');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('@nosuchtoken', $result['error']);
        $this->assertStringContainsString(
            'item 1',
            $result['error'],
            'and the refusal names the card, through the real surface as well as the unit one'
        );
    }

    /**
     * THE TWO RETIRED ITEM FIELDS OFFER A ROUTE, NOT A LIST OF LIVE FIELD NAMES.
     *
     * §3.1: "Every retirement lands in `retired_props` naming the v2 surface that replaced
     * it, SO A REFUSAL OFFERS A ROUTE INSTEAD OF A LIST OF LIVE PROP NAMES." grid is the
     * first component whose retirements reach INSIDE an `items[]` entry — `items[].style`
     * and `items[].text_role` — and the item-field gate did not consult `retired_props`,
     * so both fell through to the typo gate and answered a deliberate v1 authoring shape
     * with `unknown_prop` and a field list.
     *
     * MEASURED BEFORE THE FIX, through the real write path:
     *
     *   items[].style     -> unknown_prop  'has no field "style". Available fields: …'
     *   items[].text_role -> unknown_prop  'has no field "text_role". Available fields: …'
     *
     * Both are keys an author had on every pre-rebuild page, and the replacement for one
     * of them is the headline capability of this whole task — so "here is the list of
     * fields you may use" was the least useful true sentence available.
     *
     * IT ALSO MADE THE MODEL-FACING PROMPT UNTRUE IN THE SAME CHANGE THAT WROTE IT.
     * `lib/ai-context.php` tells the model that a stale `style` or `text_role` on ONE card
     * "refuses the band like any other retired key". It did not, and a prompt that
     * describes a diagnostic the engine does not emit is the drift the AI-facing docs rule
     * exists to prevent.
     *
     * THE CODE IS THE SAME AS THE BAND-PROP ARM'S ON PURPOSE. A caller that cannot tell
     * "you typo'd" from "this moved, and here is where" has to string-match prose to know
     * whether to re-read the schema or rewrite the value.
     */
    public function testTheTwoRetiredItemFieldsRefuseWithARouteRatherThanAFieldList(): void
    {
        foreach (
            [
                'style'     => 'udc',
                'text_role' => 'card-text',
            ] as $field => $route_fragment
        ) {
            $post_id = $this->newPage("retired item field {$field}");
            $band    = $this->ownersBand();
            unset($band['props']['items'][1]['udc']);
            $band['props']['items'][1][$field] = $field === 'style'
                ? ['--grid-item-bg' => '#14141F']
                : 'meta';

            $result = $this->write($post_id, [$band]);

            $this->assertFalse($result['ok'], "a stale `{$field}` must refuse");
            $this->assertSame(
                'retired_prop',
                $result['error_code'],
                "`items[].{$field}` is retired, not a typo — the code is what lets a caller tell "
                . 'the two apart without string-matching prose'
            );
            $this->assertStringContainsString($field, $result['error'], 'the refusal names the key');
            $this->assertStringContainsString(
                $route_fragment,
                $result['error'],
                "the refusal must name the v2 surface that replaced `items[].{$field}`, per §3.1"
            );
            // And it locates the CARD, not just the band: a page with one stale card
            // should not send an author reading three.
            $this->assertStringContainsString('item ', $result['error']);
        }

        // BOTH ROUTES ARE DECLARED, or the messages above are improvised rather than
        // derived — `retired_props` is the single source they are read from.
        $retired = pp_component_retired_props('grid');
        $this->assertArrayHasKey('items[].style', $retired);
        $this->assertArrayHasKey('items[].text_role', $retired);

        // AND THE ROUTE WORKS, which is the half that makes a refusal a redirect rather
        // than a dead end: the same design the stale `style` expressed is accepted at the
        // address the message names.
        $post_id = $this->newPage('retired item field route works');
        $this->assertTrue(
            $this->write($post_id, [$this->ownersBand()])['ok'],
            'the route the refusal offers must actually take the write'
        );
    }

    // ───────────────────────────────────────────────────────────────────────
    // B6 — the seven exclusions, each REFUSING rather than being ignored
    // ───────────────────────────────────────────────────────────────────────

    /**
     * ALL SEVEN EXCLUSIONS REFUSE, AND THE REFUSAL IS THE POINT.
     *
     * Accepted-stored-ignored is the I35 class this engine exists to close, and it is
     * worse here than at band grain: an author who writes `_band` inside a card is
     * expressing an intention the contract has DECIDED against, and silence would let them
     * believe it landed.
     *
     * The seventh (`_css`) is the orchestrator's implementation-time addition under D8,
     * recorded in the spec with its date and overridable. It is refused at TWO depths,
     * because an author can spell it either way: as a ROLE (caught by the reserved-key
     * gate) and as a GROUP inside a permitted role (caught with the same sentence rather
     * than being called an unknown group — the author asked for a capability the contract
     * withholds, which is a different answer from "no such group").
     *
     * EXCLUSIONS 3 AND 6 ARE ENFORCED BY THE ROLE-NAME CHARSET RATHER THAN BY A LIST, and
     * that is stronger than a blocklist: `.grid__item:first-child` and `.grid__item::before`
     * are UNSPELLABLE because the role-selector charset admits no `:`, so there is no
     * ordinal or pseudo-element to refuse in the first place. This asserts they land in the
     * no-such-role arm, which is where an unspellable thing has to land.
     *
     * PLANTED-DEFECT PROOF, run per exclusion in a copy of the tree: removing an entry
     * from `pp_udc_item_reserved_keys()` takes its case RED; removing the `PP_UDC_CSS_KEY`
     * group arm in `pp_udc_validate_item_map()` takes the group-depth `_css` case RED
     * while the role-depth case stays green, confirming the two depths are separately
     * carried.
     */
    public function testEachOfTheSevenExclusionsRefusesRatherThanBeingIgnored(): void
    {
        $cases = [
            // 2 — no per-item `_band`: an item cannot restyle its container.
            'exclusion 2, `_band` inside an item'
                => [['_band' => ['background' => ['fill' => '#000000']]], 'unknown_udc_role', 'restyle the band'],
            // 3 — no ordinal or structural selectors; unspellable by charset.
            'exclusion 3, an ordinal role'
                => [['card:first-child' => ['background' => ['fill' => '#000000']]], 'unknown_udc_role', 'no UDC role'],
            'exclusion 3, an nth-child role'
                => [['card:nth-child(2)' => ['background' => ['fill' => '#000000']]], 'unknown_udc_role', 'no UDC role'],
            // 4 — no nesting beyond one level: an item inside an item is not addressable.
            'exclusion 4, an item nested inside an item'
                => [['card' => ['items' => [['udc' => []]]]], 'unknown_udc_group', 'does not exist'],
            // 5 — an item may REFERENCE a preset, never DEFINE one.
            'exclusion 5, defining a preset inside an item'
                => [['card' => ['_preset' => ['background' => ['fill' => '#000000']]]], 'invalid_prop_value', 'must be a preset name'],
            // 6 — pseudo-elements remain deferred, carried forward from ruling A3.
            'exclusion 6, a pseudo-element role'
                => [['card::before' => ['background' => ['fill' => '#000000']]], 'unknown_udc_role', 'no UDC role'],
            // 7 — `_css` excluded at item grain (D8), at both depths.
            'exclusion 7, `_css` written as a role'
                => [['_css' => ['color' => 'red']], 'unknown_udc_role', 'raw CSS is not available at item grain'],
            'exclusion 7, `_css` written as a group'
                => [['card' => ['_css' => ['object-fit' => 'contain']]], 'unknown_udc_group', 'raw CSS is not available at item grain'],
            // The `_tokens` boundary, which falls out of B4 rather than being ruled: a
            // token map inside an item would have no element to declare itself on.
            'the `_tokens` boundary'
                => [['_tokens' => ['brandink' => '#14141F']], 'unknown_udc_role', 'declared once per band'],
        ];

        foreach ($cases as $label => [$map, $code, $fragment]) {
            $error = $this->validateItemMap($map);
            $this->assertInstanceOf(WP_Error::class, $error, "{$label}: accepted-stored-ignored is the I35 class");
            $this->assertSame($code, $error->get_error_code(), $label);
            $this->assertStringContainsString($fragment, $error->get_error_message(), $label);
        }

        // 1 — no per-item chrome. Chrome is site-grain by ruling A1 and is not a COMPONENT
        // at all, so it has no `item_roles` declaration and therefore no item tier to
        // reach. This is the structural form of the exclusion, and it is stronger than a
        // refusal message: there is no surface on which to write the thing.
        foreach (['header', 'footer'] as $chrome) {
            $this->assertNull(
                pp_udc_item_roles($chrome),
                "exclusion 1: chrome declares no item grain, so per-item chrome is unreachable rather than refused"
            );
        }
    }

    /**
     * A BAND-GRAIN ROLE REACHED FOR AT ITEM GRAIN GETS ITS OWN SENTENCE, NOT "NO SUCH ROLE".
     *
     * TWO DIFFERENT MISTAKES, TWO DIFFERENT REPAIRS. A role that does not exist is a typo.
     * A role that exists but is not item-addressable is a real role the author reached for
     * at the wrong GRAIN, and the fix is to set it on the band instead. One message for
     * both would send half the authors to the wrong repair — and `heading` is exactly the
     * role an author darkening one card will try, because it is the one they just used at
     * band grain.
     *
     * The band-level header roles are absent from `item_roles` because they exist ONCE per
     * band: styling them "for one card" has no meaning at all.
     */
    public function testARoleThatExistsButIsNotItemSettableIsToldWhereToWriteIt(): void
    {
        foreach (['_band', 'header', 'eyebrow', 'heading', 'heading-accent', 'subheading', 'list', 'empty'] as $role) {
            $this->assertArrayHasKey(
                $role,
                pp_udc_component_roles('grid'),
                "`{$role}` must exist at band grain, or this test is checking nothing"
            );
            $this->assertNotContains(
                $role,
                pp_udc_item_roles('grid')['roles'],
                "`{$role}` exists once per band, so styling it for one card has no meaning"
            );
        }

        // `_band` has its own sentence (exclusion 2, above). The other seven share the
        // wrong-grain one, which must name the repair.
        $error = $this->validateItemMap(['heading' => ['typography' => ['color' => '#000000']]]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('unknown_udc_role', $error->get_error_code());
        $this->assertStringContainsString('exists but is not settable per item', $error->get_error_message());
        $this->assertStringContainsString(
            'where it applies to every item',
            $error->get_error_message(),
            'the refusal has to name the repair, or it is a dead end rather than a redirect'
        );

        // And the redirect actually works, or the sentence is advice that fails.
        $this->assertNull(pp_udc_validate_map(['heading' => ['typography' => ['color' => '#000000']]], 'grid'));
    }

    /**
     * A DANGLING REFERENCE INSIDE AN ITEM MAP REFUSES — TOKENS AND PRESETS BOTH.
     *
     * The same engine, the same grammar, the same refusal codes as a band map: that is B1
     * in one sentence, and the token arm is where it is most easily lost. An item map's
     * `@name` resolves against the BAND's `_tokens`, because minted item literals lift
     * into the band's token map (the band root is the only element the two tiers share).
     * A gate that checked the item map against an empty token set would refuse every
     * correct responsive item value the engine itself had just minted.
     */
    public function testADanglingTokenOrPresetReferenceInsideAnItemRefuses(): void
    {
        $dangling = $this->validateItemMap(['card' => ['background' => ['fill' => '@nosuchtoken']]]);
        $this->assertInstanceOf(WP_Error::class, $dangling);
        $this->assertSame('invalid_prop_value', $dangling->get_error_code());
        $this->assertStringContainsString('not a registered design token', $dangling->get_error_message());

        // AND IT NAMES THE CARD (B4: findings carry the item locator beside the band
        // index). This is the arm that lost it: `pp_udc_validate_item_map()` writes its
        // OWN refusals with the locator, but DELEGATES group and value checking, and the
        // delegated validator opened with its default `Component "x" role "y"` preamble.
        //
        // Measured before the fix, on a three-card band: a bad token inside ONE card
        // produced a message byte-identical to the same mistake on the BAND, so an
        // operator could not tell which grain was wrong, let alone which card. The most
        // common item-grain refusal was the one that said the least.
        $this->assertStringContainsString(
            'item 1',
            $dangling->get_error_message(),
            'the delegated group validator must carry the item locator, or the most common '
            . 'item refusal is indistinguishable from a band refusal'
        );

        // THE SAME REFERENCE RESOLVES when the BAND declares the token — which is where an
        // item's tokens live, and the asymmetry this test exists to pin.
        $this->assertNull(
            $this->validateItemMap(['card' => ['background' => ['fill' => '@brandink']]], ['brandink' => '#14141F']),
            'an item reference resolves against the BAND\'s _tokens, not against an empty set'
        );

        $preset = $this->validateItemMap(['card' => ['_preset' => 'no-such-preset']]);
        $this->assertInstanceOf(WP_Error::class, $preset);
        $this->assertStringContainsString('does not exist', $preset->get_error_message());

        // A real preset reference is accepted — exclusion 5 forbids DEFINING one here, not
        // referencing one, and a test that only proved the refusals would let the whole
        // capability be removed silently.
        $this->assertNull($this->validateItemMap(['card' => ['_preset' => 'button']]));
    }

    // ───────────────────────────────────────────────────────────────────────
    // B4 — minting, provenance and the four disclosure families
    // ───────────────────────────────────────────────────────────────────────

    /**
     * THE ENGINE RECOGNISES ITS OWN ITEM MINT, AND A FORGED ONE IT DOES NOT.
     *
     * THE DEFECT THIS PINS WOULD HAVE BEEN CATASTROPHIC AND WAS INVISIBLE TO THE SUITE.
     * The reserved-name classifier walked only the BAND map. An item-minted token lands in
     * the band's `_tokens` while its REFERENCE lives in `props.items[k].udc`, which the
     * classifier never saw — so `pp_udc_validate_map()` refused the band with "uses a name
     * the engine mints for itself" on every band carrying a responsive item value. The
     * engine would have permanently refused its own stored output, and every
     * round-trip — restore, `wp pp check page`, any edit — would have failed on data the
     * engine wrote. Measured before the fix, in evidence-1101/redproof.php.
     *
     * THE FORGED-NAME ARM IS THE SECURITY HALF (the T2 collision lessons). The item
     * segment widens the token namespace with an author-influenced segment for the first
     * time at this grain, so a name that LOOKS item-minted but names no item this band
     * holds must NOT be treated as the engine's own — otherwise an author could declare a
     * `_tokens` name the engine would later overwrite. The classifier answers from the
     * item maps actually present, not from the name's shape alone.
     *
     * PLANTED-DEFECT PROOF: dropping the `$item_maps` parameter from
     * `_pp_udc_name_is_the_engines_own_mint()` takes the first assertion RED; making it
     * answer `true` on shape alone takes the forged assertion RED. Both arms are carried.
     */
    public function testTheClassifierKnowsItsOwnItemMintAndRefusesToVouchForAForgedOne(): void
    {
        $name      = 'it-7b2c91d4-card-title-typography-color-d';
        $item_maps = ['it-7b2c91d4' => [
            'card-title' => ['typography' => ['color' => ['d' => '@' . $name]]],
        ]];

        $this->assertTrue(
            _pp_udc_is_mint_shaped_name($name),
            'the gate has to fire at all, or the arms below are unreachable'
        );
        $this->assertTrue(
            _pp_udc_name_is_the_engines_own_mint($name, [], $item_maps),
            'the engine must recognise the name it minted itself, or it refuses its own stored output'
        );
        $this->assertFalse(
            _pp_udc_name_is_the_engines_own_mint($name, []),
            'with no item maps in hand there is nothing to vouch for the name'
        );
        $this->assertFalse(
            _pp_udc_name_is_the_engines_own_mint('it-deadbeef-card-title-typography-color-d', [], $item_maps),
            'a forged id that names no item this band holds must not be read as the engine\'s own'
        );

        $this->assertNull(
            pp_udc_validate_map(['_tokens' => [$name => '#F2EEE5']], 'grid', $item_maps),
            'the band carrying an item-minted token must validate'
        );

        // AND THE WRITE GATE AGREES WITH THE CLASSIFIER — THROUGH THE REAL SURFACE, which
        // is the only form of this claim that is worth anything.
        //
        // THE ASSERTIONS ABOVE HAND THE CLASSIFIER ITS ITEM MAPS THEMSELVES, so they prove
        // the classifier is right IN ISOLATION and say nothing about whether the gate ever
        // passes them. Measured: planting `pp_udc_validate_map($item['udc'], $name, [])`
        // at the gate in `lib/admin.php` left every assertion above GREEN while restoring
        // the original catastrophe — which is exactly how the first fix for this defect
        // shipped broken under a green suite. A control that supplies the thing it is
        // testing for is not a control.
        //
        // THE ROUND TRIP IS THE REAL SHAPE. The engine mints an item token into the BAND's
        // `_tokens` on the first write; re-sending what it stored is what `restore`,
        // `wp pp check page` and every subsequent edit do. If the gate does not hand the
        // item maps to the classifier, the second write is refused with "uses a name the
        // engine mints for itself" — the engine refusing its own stored output, on data it
        // wrote one line earlier.
        $post_id = $this->newPage('classifier round trip');
        $first   = $this->write($post_id, [[
            'component' => 'grid',
            'props'     => ['title' => 'T', 'items' => [
                ['title' => '02', 'udc' => [
                    'card' => ['background' => ['fill' => ['d' => '#14141F', 'p' => '#222233']]],
                ]],
            ]],
        ]]);
        $this->assertTrue($first['ok'], $first['error'] ?? '');

        $stored = pp_get_composition($post_id);
        $tokens = $stored[0]['udc']['_tokens'] ?? [];
        $this->assertNotSame([], $tokens, 'the responsive item value must have minted, or this proves nothing');
        foreach (array_keys($tokens) as $minted) {
            $this->assertTrue(
                _pp_udc_is_mint_shaped_name((string) $minted),
                "the stored token `{$minted}` must trip the reserved-name gate, or the gate is not "
                . 'under test here at all'
            );
        }

        $again = $this->write($this->newPage('classifier round trip 2'), $stored);
        $this->assertTrue(
            $again['ok'],
            'the engine must accept its OWN stored output; it refused with: ' . ($again['error'] ?? '')
        );
    }

    /**
     * THE ITEM SEGMENT KEEPS TWO CARDS FROM COLLIDING ON ONE TOKEN NAME (B4).
     *
     * Without the id in the mint name, two items styling the SAME role at the SAME
     * breakpoint would produce one name for two literals. The collision guard would then
     * leave the second value UNMINTED — which paints correctly, but for a reason nobody
     * could explain, and it would look like a bug in the minting rather than a collision
     * that was handled.
     *
     * Measured through the real write path, so the names asserted are the ones actually
     * stored.
     */
    public function testTwoCardsStylingTheSameRoleMintTwoDistinctTokenNames(): void
    {
        $post_id = $this->newPage('item mint collision');
        $band    = [
            'component' => 'grid',
            'props'     => ['title' => 'T', 'items' => [
                ['title' => '01', 'udc' => ['card' => ['background' => ['fill' => ['d' => '#111111', 'p' => '#222222']]]]],
                ['title' => '02', 'udc' => ['card' => ['background' => ['fill' => ['d' => '#333333', 'p' => '#444444']]]]],
            ]],
        ];
        $this->assertTrue($this->write($post_id, [$band])['ok']);

        $stored = pp_get_composition($post_id)[0];
        $tokens = $stored['udc']['_tokens'] ?? [];
        $ids    = [$stored['props']['items'][0]['id'], $stored['props']['items'][1]['id']];

        $this->assertNotSame($ids[0], $ids[1], 'two styled cards get two ids');
        $this->assertCount(4, $tokens, 'two cards x two breakpoints is four distinct mint names');
        foreach ($ids as $id) {
            $this->assertArrayHasKey($id . '-card-background-fill-d', $tokens);
            $this->assertArrayHasKey($id . '-card-background-fill-p', $tokens);
        }
        // All four literals survived, which is the thing the collision would have cost.
        $this->assertSame(
            ['#111111', '#222222', '#333333', '#444444'],
            array_values(array_unique(array_values($tokens))),
            'a collision would have left one card\'s value unminted and both would still paint — '
            . 'correct output for a reason no one could explain'
        );

        // THE TOKENS LIVE ON THE BAND, not on the item, and that is forced rather than
        // chosen: a minted literal emits as a custom property on the BAND ROOT, which is
        // the only element the band and its items share.
        $css = pp_udc_band_css($stored);
        $this->assertStringContainsString('--pp-' . $ids[0] . '-card-background-fill-d:#111111;', $css);
    }

    /**
     * STORED BYTES NO WRITE GATE SAW ARE LEDGERED, NOT SILENTLY DROPPED, AT ITEM GRAIN.
     *
     * The emitter refuses `_css` and an unpermitted group AGAIN, after the write gate has
     * already refused them, and that second refusal is not redundant: the write gate is
     * not the only way data arrives. A raw meta write, a composition written before this
     * tier existed, and `restore_composition` (#233) all reach the compiler directly. A
     * gate that runs only at write is a gate the emitter disagrees with.
     *
     * THE LEDGER IS THE WHOLE POINT ON THAT PATH. On stored bytes there is no write
     * envelope to carry a refusal, so the emit-drop advisory is the ONLY signal an
     * operator gets — which makes it exactly the arm that must not be silent, and it had
     * no test. Each entry names the CARD as well as the role, because a band with ten
     * cards and one bad stored map is otherwise a hunt.
     */
    public function testStoredItemValuesTheEmitterRefusesAreLedgeredWithTheirCard(): void
    {
        $band = [
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'props'     => ['items' => [
                ['title' => '02', 'id' => 'it-7b2c91d4', 'udc' => [
                    // Exclusion 7, arriving as a GROUP inside a permitted role.
                    'card'       => [PP_UDC_CSS_KEY => ['object-fit' => 'contain']],
                    // A real group the role does not permit.
                    'card-title' => ['layout' => ['columns' => '2']],
                    // A group that is not in the vocabulary at all.
                    'card-text'  => ['nosuchgroup' => ['x' => 'y']],
                ]],
            ]],
        ];

        $drops = [];
        $css   = pp_udc_compile_band($band, 'authored', $drops);

        $this->assertCount(3, $drops, 'every refused item declaration must reach the ledger');
        $reasons = [];
        foreach ($drops as $drop) {
            $this->assertStringContainsString(
                'it-7b2c91d4',
                $drop['where'],
                'a drop that does not name the card sends an operator hunting through the band'
            );
            $reasons[] = $drop['reason'];
        }
        $this->assertContains('raw CSS is not available on a single item', $reasons);
        $this->assertContains('there is no such group in the design vocabulary', $reasons);
        $this->assertStringContainsString(
            'the write gate refuses it',
            implode(' | ', $reasons),
            'the unpermitted-group drop must say the two halves agree'
        );

        // AND NOTHING PAINTED. A ledger entry beside an emitted declaration would be the
        // worst of both: an operator warned about a value that is on the page anyway.
        $emitted = '';
        foreach ($css['blocks'] as $block) {
            $emitted .= implode(',', array_keys($block['decls'] ?? []));
        }
        $this->assertStringNotContainsString('object-fit', $emitted);
        $this->assertStringNotContainsString('grid-template-columns', $emitted);
    }

    /**
     * A DANGLING ITEM BACKGROUND IMAGE REPORTS ON THE SAME CHANNEL A BAND'S DOES.
     *
     * IT HAD NO CHANNEL AT ALL, which is a sharper failure than it sounds.
     * `_pp_udc_place()` deliberately suppresses its own drop-ledger entry for an
     * `attachment_id` value, on the stated premise that the readiness check owns the
     * report — and that check walked the BAND map only. So an item's background image
     * whose attachment was deleted after a valid write vanished at render with no ledger
     * row, no advisory and no finding.
     *
     * The carve-out's own comment names that outcome in as many words: a carve-out whose
     * reason has lapsed is not a carve-out, it is a drop on no channel at all.
     *
     * `background` is permitted on eight of grid's ten item-settable roles, so this is a
     * real surface rather than a theoretical one. Measured before the fix: the band-grain
     * control reported, the item-grain one reported nothing.
     */
    public function testADanglingItemBackgroundImageIsReportedWithItsCard(): void
    {
        $GLOBALS['_pp_test_store']['posts'][42]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][42] = true;

        $post_id = $this->newPage('dangling item image');
        $this->assertTrue($this->write($post_id, [[
            'component' => 'grid',
            'props'     => ['title' => 'T', 'items' => [
                ['title' => 'a', 'udc' => ['card' => ['background' => ['image' => 42]]]],
            ]],
        ]])['ok'], 'a LIVE attachment must be accepted, or the deletion below proves nothing');

        // Deleted AFTER a valid write — the only way to reach this state, since the write
        // gate validates the id against the Media Library.
        unset(
            $GLOBALS['_pp_test_store']['attachment_is_image'][42],
            $GLOBALS['_pp_test_store']['posts'][42]
        );

        $rows = [];
        foreach (pp_check_udc_background_images($post_id) as $check) {
            if (($check['check'] ?? '') === 'udc_background_image') {
                $rows[] = (string) ($check['message'] ?? '');
            }
        }
        $this->assertCount(1, $rows, 'the item-grain dangling image must reach the advisory');
        $this->assertMatchesRegularExpression('/item "it-[0-9a-f]{8}"/', $rows[0], 'and it names the CARD');
        $this->assertStringContainsString('role "card"', $rows[0]);
        $this->assertStringContainsString('attachment 42', $rows[0]);
    }

    /**
     * THE ITEM DISCLOSURES ARE BOUNDED AT THE SOURCE, NOT AT THE READER.
     *
     * The sibling `_css` disclosure caps itself and records why: slicing the reader's
     * output does not bound the ALLOCATION, and this function is reached by
     * `wp pp check page`, `restore_composition` and every post-write envelope — without
     * the write path's pre-engine size gate in front of them.
     *
     * The item arm reintroduced that shape with a different multiplier: the ITEM count
     * rather than the property count, and `items` declares no `max_items`. Measured at
     * 400 styled cards: 400 findings before the bound.
     */
    public function testTheItemShadowingDisclosureIsBoundedByTheItemCount(): void
    {
        $items = [];
        for ($i = 0; $i < 400; $i++) {
            $items[] = [
                'title' => "c{$i}",
                'id'    => sprintf('it-%08x', $i),
                // Darken the card and set the ink on the ROOT — the five-write trap, so
                // every one of the 400 entries produces a finding.
                'udc'   => ['card' => [
                    'background' => ['fill' => '#14141F'],
                    'typography' => ['color' => '#F2EEE5'],
                ]],
            ];
        }

        $findings = pp_udc_composition_findings([[
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'props'     => ['items' => $items],
        ]]);

        $shadow = 0;
        foreach ($findings as $finding) {
            if ($finding['type'] === 'udc_item_value_shadowed_by_role_default') {
                $shadow++;
            }
        }
        $this->assertGreaterThan(0, $shadow, 'the disclosure must still fire, or this bounds nothing');
        $this->assertLessThanOrEqual(
            PP_UDC_MAX_EMIT_DROPS,
            $shadow,
            'the item axis must be bounded at the source, exactly as the `_css` axis is'
        );

        // ACROSS THE COMPOSITION, NOT PER BAND — and the first cut of this bound got
        // that wrong while quoting the sibling that had already learned it. A counter
        // declared inside the per-band loop delivers its bound ONCE PER BAND: measured
        // at 400 cards on each of 50 bands, 10,000 findings from a bound of 200.
        //
        // Asserted at TEN bands rather than one, because one band cannot tell a
        // composition-wide counter from a per-band one.
        $tenBands = [];
        for ($b = 0; $b < 10; $b++) {
            $tenBands[] = [
                'component' => 'grid',
                'id'        => sprintf('pp-%08x', $b),
                'props'     => ['items' => $items],
            ];
        }
        $wide = 0;
        foreach (pp_udc_composition_findings($tenBands) as $finding) {
            if ($finding['type'] === 'udc_item_value_shadowed_by_role_default') {
                $wide++;
            }
        }
        $this->assertLessThanOrEqual(
            PP_UDC_MAX_EMIT_DROPS,
            $wide,
            'the bound is composition-wide: a per-band counter multiplies it by the band count'
        );
    }

    /**
     * A STORED ITEM-MAP KEY THIS TIER CANNOT ADDRESS IS LEDGERED, NOT STEPPED OVER.
     *
     * The compiler walks the DECLARED roles, so anything else in a stored item map is
     * never visited. At write that is fine — each key is refused by name. But the write
     * gate is not the only way data arrives, and on a raw meta write or
     * `restore_composition` (#233) these keys were accepted by nothing, refused by
     * nothing and reported by nothing.
     *
     * The `_css` GROUP arm already ledgered its half, so the two depths of the SAME
     * exclusion disagreed about whether to say anything at all.
     */
    public function testStoredItemMapKeysThisTierCannotAddressAreLedgered(): void
    {
        $band = [
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'props'     => ['items' => [
                ['title' => 'a', 'id' => 'it-7b2c91d4', 'udc' => [
                    PP_UDC_CSS_KEY => ['color' => 'red'],          // exclusion 7, role depth
                    '_band'        => ['background' => ['fill' => '#000000']], // exclusion 2
                    'heading'      => ['typography' => ['color' => '#000000']], // band-only role
                    'nosuchrole'   => ['x' => 1],                   // not a role at all
                    'card'         => ['background' => ['fill' => '#14141F']], // the valid one
                ]],
            ]],
        ];

        $drops = [];
        $css   = pp_udc_compile_band($band, 'authored', $drops);

        $where = [];
        foreach ($drops as $drop) {
            $where[] = $drop['where'];
        }
        $this->assertCount(4, $drops, 'every unaddressable stored key must be ledgered');
        foreach (['_css', '_band', 'heading', 'nosuchrole'] as $key) {
            $this->assertStringContainsString(
                'role "' . $key . '"',
                implode(' | ', $where),
                "the stored key `{$key}` reached no channel at all"
            );
        }
        foreach ($where as $locator) {
            $this->assertStringContainsString('it-7b2c91d4', $locator, 'each drop names the card');
        }

        // AND THE VALID ROLE STILL EMITTED — a ledger that also suppressed the good half
        // would be a worse cure than the disease.
        $emitted = '';
        foreach ($css['blocks'] as $block) {
            $emitted .= implode(',', array_keys($block['decls'] ?? []));
        }
        $this->assertStringContainsString('background', $emitted);
    }

    /**
     * A RESPONSIVE VALUE INSIDE A STATE BLOCK MINTS WITH BOTH SEGMENTS.
     *
     * The item pass has two arms and only the flat one was exercised. This is the other:
     * a per-breakpoint value written inside `:hover`, which is the shape MOST likely to
     * collide, because it is the one carrying the most name segments. If the item segment
     * were dropped from a state-scoped mint, two cards hovering the same role at the same
     * breakpoint would produce one name for two literals, and the collision guard would
     * leave the second value unminted — painting correctly, for a reason nobody could
     * explain from the stored data.
     */
    public function testAResponsiveValueInsideAStateBlockMintsPerItemAndPerState(): void
    {
        $band = pp_udc_normalize_band([
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'props'     => ['items' => [
                ['title' => 'a', 'id' => 'it-11111111', 'udc' => [
                    'card-link' => ['typography' => [':hover' => ['color' => ['d' => '#111111', 'p' => '#222222']]]],
                ]],
                ['title' => 'b', 'id' => 'it-22222222', 'udc' => [
                    'card-link' => ['typography' => [':hover' => ['color' => ['d' => '#333333', 'p' => '#444444']]]],
                ]],
            ]],
        ]);

        $tokens = $band['udc']['_tokens'] ?? [];
        foreach (['it-11111111', 'it-22222222'] as $item_id) {
            foreach (['d', 'p'] as $bp) {
                $this->assertArrayHasKey(
                    $item_id . '-card-link-typography-color-hover-' . $bp,
                    $tokens,
                    'the mint name must carry BOTH the item segment and the state segment'
                );
            }
        }
        $this->assertCount(4, $tokens, 'two cards x two breakpoints is four distinct names');
        $this->assertSame(
            ['#111111', '#222222', '#333333', '#444444'],
            array_values(array_unique(array_values($tokens))),
            'all four literals survive — a name clash would have left one card unminted'
        );
    }

    /**
     * THE COMPILED ITEM BLOCK CARRIES ITEM PROVENANCE AND ITS OWN LOCATOR (B4).
     *
     * `source` gains the value `item`. That is not bookkeeping: the compiler's provenance
     * is what the emit-drop advisory reports from and what ranks presets under defaults, so
     * an item declaration arriving labelled `udc` would be indistinguishable from a band
     * one in every downstream reader. The block also carries the item id, which is what
     * lets a finding name the CARD rather than only the band.
     */
    public function testTheCompiledItemBlockCarriesItemProvenanceAndItsLocator(): void
    {
        $band = [
            'component' => 'grid',
            'id'        => 'pp-3f9a1c2e',
            'udc'       => ['card' => ['background' => ['fill' => '#ffffff']]],
            'props'     => ['items' => [
                ['title' => '02', 'id' => 'it-7b2c91d4', 'udc' => ['card-title' => ['typography' => ['color' => '#F2EEE5']]]],
            ]],
        ];

        $blocks = pp_udc_compile_band($band, 'authored')['blocks'];
        $byItem = [];
        foreach ($blocks as $block) {
            $byItem[(string) ($block['item'] ?? '')] = $block;
        }

        $this->assertArrayHasKey('', $byItem, 'the band tier must still compile beside the item tier');
        $this->assertSame('udc', $byItem['']['decls']['background']['source']);

        $this->assertArrayHasKey('it-7b2c91d4', $byItem, 'the item block must carry its own locator');
        $this->assertSame(
            'item',
            $byItem['it-7b2c91d4']['decls']['color']['source'],
            '`source` gains the value `item`; without it an item declaration is indistinguishable '
            . 'from a band one in every downstream reader'
        );
        $this->assertSame('card-title', $byItem['it-7b2c91d4']['role']);
    }

    /**
     * ALL FOUR DISCLOSURE FAMILIES FIRE AT ITEM GRAIN (B4).
     *
     * The clause names four, and each one is a different failure an author cannot see:
     *
     *   `udc_token_minted`                        — "you wrote #14141F; it is stored as a token"
     *   `udc_unused_band_token`                   — counts ITEM references, or every item mint
     *                                               would be reported unused on the write that
     *                                               created it
     *   `udc_item_value_shadowed_by_role_default` — the five-write trap: darken the card, and
     *                                               the title keeps its pinned ink
     *   `udc_preset_groups_skipped`               — a bundle partially applied, silently
     *
     * The shadowing one is the one that earns its keep. Darkening a card's fill and setting
     * its text colour on the card ROOT is the obvious authoring move and the one that
     * silently half-works, because the card's parts declare their colours DIRECTLY and a
     * direct declaration beats inheritance at any specificity and in any source order —
     * precisely the #1059 shape that shipped a 3.21:1 row on faq.
     */
    public function testAllFourDisclosureFamiliesFireAtItemGrain(): void
    {
        // (a) minted + (b) referenced, in one band: a responsive item value.
        $minting = pp_udc_normalize_band([
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'props'     => ['items' => [
                ['title' => '02', 'id' => 'it-7b2c91d4', 'udc' => [
                    'card' => ['background' => ['fill' => ['d' => '#14141F', 'p' => '#222233']]],
                ]],
            ]],
        ]);
        $types = array_column(pp_udc_composition_findings([$minting]), 'type');
        $this->assertContains('udc_token_minted', $types, 'the no-coercion disclosure must reach item grain');
        $this->assertNotContains(
            'udc_unused_band_token',
            $types,
            'an item-minted token is referenced from inside props.items — reporting it unused would '
            . 'tell an author their own value has no effect while it paints'
        );

        // The same family's negative: a band token NOTHING references, item maps included.
        $orphan = [
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'udc'       => ['_tokens' => ['brandink' => '#14141F']],
            'props'     => ['items' => [['title' => '01']]],
        ];
        $this->assertContains(
            'udc_unused_band_token',
            array_column(pp_udc_composition_findings([$orphan]), 'type')
        );

        // (c) the five-write trap, disclosed at item grain with the item named.
        $shadowed = [
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'props'     => ['items' => [
                ['title' => '02', 'id' => 'it-7b2c91d4', 'udc' => [
                    'card' => ['background' => ['fill' => '#14141F'], 'typography' => ['color' => '#F2EEE5']],
                ]],
            ]],
        ];
        $findings = pp_udc_composition_findings([$shadowed]);
        $shadow   = null;
        foreach ($findings as $finding) {
            if ($finding['type'] === 'udc_item_value_shadowed_by_role_default') {
                $shadow = $finding;
            }
        }
        $this->assertNotNull($shadow, 'the five-write trap must be disclosed at item grain');
        $this->assertStringContainsString('it-7b2c91d4', $shadow['message'], 'the finding names the CARD');
        $this->assertStringContainsString('card-title', $shadow['message']);

        // (d) a preset bundle partially applied inside an item.
        $preset = [
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'props'     => ['items' => [
                ['title' => '02', 'id' => 'it-7b2c91d4', 'udc' => ['card-media' => ['_preset' => 'button']]],
            ]],
        ];
        $skipped = null;
        foreach (pp_udc_composition_findings([$preset]) as $finding) {
            if ($finding['type'] === 'udc_preset_groups_skipped') {
                $skipped = $finding;
            }
        }
        $this->assertNotNull(
            $skipped,
            'a preset partially applied inside an item must disclose: the grain an author writes at '
            . 'must not decide whether they are told what landed'
        );
        $this->assertStringContainsString('it-7b2c91d4', $skipped['message'], 'the finding names the CARD');
        $this->assertStringContainsString('typography', $skipped['message']);
    }

    /**
     * THE BAND-VALUE-OVERRIDDEN-EVERYWHERE DISCLOSURE, AND THE NOISE IT DELIBERATELY IS NOT.
     *
     * B4's literal reading — "a band value shadowed by an item value" — would fire whenever
     * ANY item overrides anything. That is the headline capability working, and it is
     * exactly what the owner's live design does on 10 of 11 production bands: set the card
     * fill on the band, override it on one card. A finding on every correct write trains an
     * operator to stop reading findings, which costs more than the disclosure buys.
     *
     * The honest subject is the one the band tier's own disclosures share: a declared value
     * that cannot take effect ANYWHERE. A band value is that only when EVERY entry
     * overrides the same role and parameter. With even one entry not overriding, the band
     * value paints there and the cascade is doing its job.
     *
     * BOTH SIDES ARE PINNED, because a disclosure that fires on correct data and one that
     * never fires are failures of the same kind.
     */
    public function testTheBandValueDisclosureFiresOnlyWhenEveryItemOverridesIt(): void
    {
        $band = static fn (array $items): array => [
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'udc'       => ['card' => ['background' => ['fill' => '#ffffff']]],
            'props'     => ['items' => $items],
        ];

        // The owner's live shape: two of three cards take the band fill. SILENT.
        $this->assertNotContains(
            'udc_band_value_shadowed_by_item_value',
            array_column(pp_udc_composition_findings([$band([
                ['title' => '01'],
                ['title' => '02', 'id' => 'it-7b2c91d4', 'udc' => ['card' => ['background' => ['fill' => '#14141F']]]],
                ['title' => '03'],
            ])]), 'type'),
            'the headline capability working is not a finding'
        );

        // Every card overrides it, so the band value paints nowhere. DISCLOSED.
        $this->assertContains(
            'udc_band_value_shadowed_by_item_value',
            array_column(pp_udc_composition_findings([$band([
                ['title' => '01', 'id' => 'it-11111111', 'udc' => ['card' => ['background' => ['fill' => '#111111']]]],
                ['title' => '02', 'id' => 'it-22222222', 'udc' => ['card' => ['background' => ['fill' => '#222222']]]],
            ])]), 'type'),
            'a band value no pixel will ever show is worth saying'
        );
    }

    /**
     * THE ITEM DISCLOSURES FIRE ON A BAND THAT CARRIES NO MAP OF ITS OWN.
     *
     * THE SHAPE THE WALK USED TO SKIP IS THE HEADLINE ONE. `pp_udc_composition_findings()`
     * used to `continue` on a band with no `udc` key — correct while the walk had one
     * subject, and the SAME early exit `pp_udc_normalize_band()` had already had to correct
     * for the same reason: the owner's live design styles CARDS and leaves the band alone.
     *
     * MEASURED BEFORE THE FIX, through the real write path, on identical item data: a band
     * carrying one unrelated band-level value disclosed the five-write trap; the same three
     * cards on a band with no map of its own disclosed NOTHING. Whether an author was
     * warned depended on a band-level value that had nothing to do with the warning.
     *
     * This test is the pair of writes that measured it, kept as the pin.
     */
    public function testTheItemDisclosuresDoNotDependOnTheBandCarryingAMapOfItsOwn(): void
    {
        $cards = [
            ['title' => '01', 'text' => 'one'],
            // Darken the card, set the ink on the card ROOT and nowhere else: the trap.
            ['title' => '02', 'text' => 'two', 'udc' => [
                'card' => ['background' => ['fill' => '#14141F'], 'typography' => ['color' => '#F2EEE5']],
            ]],
            ['title' => '03', 'text' => 'three'],
        ];

        $bare = $this->newPage('item findings, bare band');
        $this->assertTrue($this->write($bare, [[
            'component' => 'grid',
            'props'     => ['title' => 'T', 'items' => $cards],
        ]])['ok']);
        $bare_stored = pp_get_composition($bare);
        $this->assertArrayNotHasKey('udc', $bare_stored[0], 'this band genuinely carries no map of its own');

        $withMap = $this->newPage('item findings, band with a map');
        $this->assertTrue($this->write($withMap, [[
            'component' => 'grid',
            'udc'       => ['list' => ['spacing' => ['gap' => '2rem']]],
            'props'     => ['title' => 'T', 'items' => $cards],
        ]])['ok']);

        $bare_types = array_column(pp_udc_composition_findings($bare_stored), 'type');
        $with_types = array_column(pp_udc_composition_findings(pp_get_composition($withMap)), 'type');

        $this->assertContains(
            'udc_item_value_shadowed_by_role_default',
            $bare_types,
            'the five-write trap must be disclosed on the arrangement an author actually reaches it '
            . 'through — cards styled, band left alone'
        );
        $this->assertSame(
            $with_types,
            $bare_types,
            'identical item data must disclose identically: a band-level value that has nothing to do '
            . 'with the warning must not decide whether the author is warned'
        );
    }

    /**
     * A MINTED ITEM ID DOES NOT FALSE-CONFLICT THE COMPOSITION CONTENT HASH.
     *
     * The CAS hash strips writer-injected identity — the band `id` and `props.id` — so a
     * caller that sends a composition and reads back the stored one sees the same hash. A
     * minted `items[].id` is exactly the same kind of thing and was NOT stripped, so the
     * very first round-trip of an item-styled band would have reported a conflict against
     * a change the engine itself made. That is the write-path honesty failure inverted: the
     * operator is told someone else edited the page, and nobody did.
     *
     * The second assertion is the one that keeps the first honest: stripping too much would
     * make a REAL content change invisible to the hash, which is worse than a false
     * conflict.
     */
    public function testAMintedItemIdDoesNotFalseConflictTheContentHash(): void
    {
        $sent = [[
            'component' => 'grid',
            'props'     => ['items' => [['title' => '02', 'udc' => ['card' => ['background' => ['fill' => '#14141F']]]]]],
        ]];
        $stored = [[
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'props'     => ['items' => [[
                'title' => '02',
                'id'    => 'it-7b2c91d4',
                'udc'   => ['card' => ['background' => ['fill' => '#14141F']]],
            ]]],
        ]];
        $this->assertSame(
            pp_composition_content_hash($sent),
            pp_composition_content_hash($stored),
            'a minted item id is engine-injected identity, like the band id beside it'
        );

        $changed = [[
            'component' => 'grid',
            'props'     => ['items' => [['title' => 'CHANGED', 'udc' => ['card' => ['background' => ['fill' => '#14141F']]]]]],
        ]];
        $this->assertNotSame(
            pp_composition_content_hash($sent),
            pp_composition_content_hash($changed),
            'and a real content change still moves the hash — stripping too much is worse than a false conflict'
        );
    }

    /**
     * THE REDUCED-MOTION GUARD FOLLOWS THE ITEM TIER.
     *
     * The guard builds its selector from the scope it is guarding. Built from band scope
     * plus role selector alone, an item-tier motion declaration would OUT-SPECIFY its own
     * guard — the item selector carries one more attribute — and a user who asked the
     * operating system to stop animating would keep the transition. That is the same
     * defect the guard's own comment records for the STATE axis, met again on a third axis.
     *
     * PLANTED-DEFECT PROOF: rebuilding the guard selector without the item attribute takes
     * this RED; nothing else in either suite notices.
     */
    public function testTheReducedMotionGuardFollowsTheItemTier(): void
    {
        $band = [
            'component' => 'grid',
            'id'        => 'pp-11112222',
            'props'     => ['items' => [
                ['title' => '02', 'id' => 'it-7b2c91d4', 'udc' => [
                    'card' => ['motion' => ['transition-duration' => '0.4s']],
                ]],
            ]],
        ];
        $css = pp_udc_band_css($band);

        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\{[^}]*\[data-pp-band="pp-11112222"\] '
            . '\[data-pp-item="it-7b2c91d4"\][^}]*transition-duration:0\.01ms;/',
            $css,
            'the guard must carry the item attribute, or the item declaration out-specifies it and a '
            . 'reduced-motion user keeps the transition'
        );
    }

    /**
     * A CARD YOU STYLED CAN BE DELETED — AND BEFORE THIS IT COULD NOT.
     *
     * THE WORST DEFECT THE REVIEW FOUND, and it sat behind the most ordinary action
     * there is. Minting lifts an item's responsive literals into the BAND's `_tokens`
     * under a name carrying the card's id. Deleting the card removed the map and left
     * the token, and the reserved-name gate then refused the band PERMANENTLY:
     *
     *   ok: false, invalid_prop_value: udc token "it-387bb4bc-card-title-typography-
     *   size-d" uses a name the engine mints for itself … Pick another name
     *
     * On a name the author never typed, naming a repair they could not perform —
     * `update_component` carried no `udc` param before #1088, so `_tokens` was
     * unreachable from the surface that refused them. The documented escape hatch hit the same wall:
     * `_pp_preserve_item_design()` promises an explicit `{"udc": {}}` clears a design
     * "because there is otherwise no way to remove an item's design once minted".
     *
     * TWO HALVES, AND BOTH ARE NEEDED. The gate now treats an ORPHANED item mint as
     * debris rather than squatting — it cannot collide, because no card carries that id,
     * and collision is the only thing that gate's own message claims to prevent. And
     * `pp_udc_normalize_band()` reaps orphans on the next write, so they do not
     * accumulate. The carve-out is what lets that write happen at all.
     */
    public function testACardCarryingAResponsiveValueCanBeDeletedAndCleared(): void
    {
        $responsive = ['card-title' => ['typography' => ['size' => ['d' => '2rem', 'p' => '1.2rem']]]];

        foreach (['delete' => null, 'clear' => []] as $route => $cleared_map) {
            $post_id = $this->newPage("orphan mint {$route}");
            $this->assertTrue($this->write($post_id, [[
                'component' => 'grid',
                'props'     => ['title' => 'T', 'items' => [
                    ['title' => '01'],
                    ['title' => '02', 'udc' => $responsive],
                ]],
            ]])['ok'], 'the responsive item write must be accepted');

            $tokens = pp_get_composition($post_id)[0]['udc']['_tokens'] ?? [];
            $this->assertNotSame([], $tokens, 'the responsive value must have minted, or this proves nothing');

            // DELETE re-sends the array without the card; CLEAR re-sends it with an
            // explicit empty map, which is the route the merge's docblock promises.
            $items = $cleared_map === null
                ? [['title' => '01']]
                : [['title' => '01'], ['title' => '02', 'udc' => $cleared_map]];

            $result = pp_execute_action('update_component', [
                'post_id'         => $post_id,
                'component_index' => 0,
                'props'           => ['items' => $items],
            ]);
            $this->assertTrue(
                $result['ok'],
                "the `{$route}` route must not be refused by the engine's own leftover token: "
                . ($result['error'] ?? '')
            );

            // AND THE DEBRIS IS GONE, not merely tolerated — otherwise every edit to the
            // band carries a growing token map nothing references.
            $after = pp_get_composition($post_id)[0];
            $this->assertSame(
                [],
                $after['udc']['_tokens'] ?? [],
                "the orphaned mint must be reaped on the next write (`{$route}`)"
            );
        }
    }

    /**
     * SQUATTING IS STILL REFUSED — THE CARVE-OUT ABOVE IS NARROW.
     *
     * The gate exists to stop an author declaring a `_tokens` name the engine would
     * later overwrite. An ORPHANED item mint cannot be that, because no card carries its
     * id and the engine will never mint it again. Everything else still refuses, and
     * this is the test that keeps the carve-out from widening into the hole it looks
     * like.
     */
    public function testTheOrphanCarveOutDoesNotOpenTheSquattingHole(): void
    {
        // The id is LIVE, and the token is not the engine's own: refused.
        $live = pp_udc_validate_map(
            ['_tokens' => ['it-deadbeef-card-background-fill-d' => '#ff0000']],
            'grid',
            ['it-deadbeef' => ['card' => ['background' => ['fill' => ['d' => '#111111']]]]]
        );
        $this->assertInstanceOf(WP_Error::class, $live, 'a live id makes the name collidable again');

        // A BAND-shaped mint is refused exactly as before — untouched by the carve-out.
        $this->assertInstanceOf(
            WP_Error::class,
            pp_udc_validate_map(['_tokens' => ['card-title-typography-color-d' => '#ff0000']], 'grid'),
            'the band-grain gate is unchanged'
        );

        // The orphan itself is ACCEPTED, and reported as the debris it is.
        $this->assertNull(
            pp_udc_validate_map(['_tokens' => ['it-deadbeef-card-background-fill-d' => '#ff0000']], 'grid', []),
            'an orphan cannot collide, so it is debris rather than squatting'
        );
        $this->assertContains(
            'udc_unused_band_token',
            array_column(pp_udc_composition_findings([[
                'component' => 'grid',
                'id'        => 'pp-11112222',
                'udc'       => ['_tokens' => ['it-deadbeef-card-background-fill-d' => '#ff0000']],
                'props'     => ['items' => [['title' => 'a']]],
            ]]), 'type'),
            'and it is reported on the channel debris belongs on — a warning, not a wall'
        );
    }

    /**
     * TWO CARDS CLAIMING ONE ID IS LEDGERED RATHER THAN SWALLOWED.
     *
     * The write gate refuses this as `duplicate_component_id` — "sharing one would paint
     * each design on both". From STORAGE the outcome was silent and worse than that
     * message describes: the second card's design is DISCARDED and the first card's is
     * painted on both, with no drop row and no finding.
     *
     * Reachable exactly where the engine says its gates do not reach: restore_composition
     * (#233), a raw meta write, or bytes written before the gate.
     */
    public function testTwoStoredCardsClaimingOneIdAreLedgered(): void
    {
        $band = [
            'component' => 'grid',
            'id'        => 'pp-11111111',
            'props'     => ['items' => [
                ['title' => 'A', 'id' => 'it-aaaaaaaa', 'udc' => ['card' => ['background' => ['fill' => '#111111']]]],
                ['title' => 'B', 'id' => 'it-aaaaaaaa', 'udc' => ['card' => ['background' => ['fill' => '#222222']]]],
            ]],
        ];

        $drops = [];
        pp_udc_compile_band($band, 'authored', $drops);

        $this->assertCount(1, $drops, 'a duplicate stored id must reach the ledger');
        $this->assertStringContainsString('it-aaaaaaaa', $drops[0]['where']);
        $this->assertStringContainsString('painted on both', $drops[0]['reason']);

        // FIRST MAP WINS, unchanged — the ledger reports what happens, it does not change
        // it. The first design is the one an already-rendered page was built against.
        $this->assertStringContainsString('#111111', pp_udc_band_css($band));
        $this->assertStringNotContainsString('#222222', pp_udc_band_css($band));
    }

    /**
     * THE ITEM-GRAIN ROSTER REACHES THE AUTHORING MODEL, DERIVED FROM THE DECLARATION.
     *
     * The write gate declines to declare `id` and `udc` as entry FIELDS, and justifies it
     * by saying "the capability still reaches the model through the `item_roles`
     * declaration". Nothing composed that declaration onto any model-facing surface, so
     * the claim was false: the prompt contained no `item_roles`, no `data-pp-item` and no
     * item-settable roster, while showing grid's full eighteen-role list. A model was
     * left to discover the ten by being refused.
     *
     * DERIVED ON BOTH SURFACES so a component that opts in tomorrow appears the day it
     * lands, and an exclusion that changes cannot drift out of sync with the prose.
     */
    public function testTheItemGrainRosterReachesBothModelFacingSurfaces(): void
    {
        $declaration = pp_udc_item_roles('grid');
        $prompt      = pp_ai_system_prompt();

        $this->assertStringContainsString('ITEM-GRAIN roles', $prompt);
        foreach ($declaration['roles'] as $role) {
            $this->assertStringContainsString(
                $role,
                $prompt,
                "the item-settable role `{$role}` must reach the authoring model"
            );
        }
        foreach (array_keys(pp_udc_item_reserved_keys()) as $excluded) {
            $this->assertStringContainsString(
                $excluded,
                $prompt,
                "the exclusion `{$excluded}` must be stated, not discovered by refusal"
            );
        }
        $this->assertStringContainsString(
            'props.' . $declaration['prop'] . '[]',
            $prompt,
            'the model needs the SHAPE, not only the role names'
        );

        // The CLI/SSH surface carries it too — same derivation, no second roster.
        $report = pp_component_schema_report('grid');
        $this->assertArrayHasKey('item_roles', $report);
        $this->assertSame($declaration['roles'], $report['item_roles']['roles']);
        $this->assertSame($declaration['prop'], $report['item_roles']['prop']);

        // OMITTED, NOT EMPTIED, for a component that declares none — absence has to keep
        // meaning "no item grain" rather than "an empty one".
        $this->assertArrayNotHasKey('item_roles', pp_component_schema_report('testimonials'));
    }

    // ───────────────────────────────────────────────────────────────────────
    // B5 — generality
    // ───────────────────────────────────────────────────────────────────────

    /**
     * THE ENGINE READS A DECLARATION AND HOLDS NO LIST OF COMPONENT NAMES (B5).
     *
     * This is not a grid feature, and the test that says so is the one that will still be
     * read when testimonials, faq, logos, stats, table or `section.panel_items` declare the
     * same thing. `pp_udc_item_roles()` is the ONE reader, and it validates the declaration
     * rather than trusting it: a `root` that is not a declared role, a role list naming
     * something the component does not declare, a `prop` that is not a declared prop, or a
     * root outside its own role list all disqualify the declaration entirely — because a
     * half-valid declaration would mint ids for a tier that could never emit.
     */
    public function testTheItemTierIsDeclaredRatherThanNamedAndTheDeclarationIsValidated(): void
    {
        $declaration = pp_udc_item_roles('grid');
        $this->assertIsArray($declaration);
        $this->assertSame('items', $declaration['prop'], 'the repeater must be a declared prop');
        $this->assertSame('card', $declaration['root']);
        $this->assertContains($declaration['root'], $declaration['roles'], 'the root is one of the addressable roles');

        $roles = pp_udc_component_roles('grid');
        foreach ($declaration['roles'] as $role) {
            $this->assertArrayHasKey($role, $roles, "`{$role}` is item-addressable and must be a declared role");
        }
        $this->assertArrayHasKey(
            $declaration['prop'],
            pp_get_registered_components()['grid']['props'],
            'the repeater prop must exist, or nothing ever reaches this tier'
        );

        // THE ROOT ROLE IS THE ELEMENT THE ATTRIBUTE GOES ON, so it must be the element the
        // other item roles live INSIDE. A root that did not contain them would emit
        // selectors that match nothing.
        $this->assertSame(
            '.grid__item',
            $roles[$declaration['root']]['selector'],
            'the root role is the element grid.php renders `data-pp-item` on'
        );

        // Every OTHER v2 component declares no item grain today and must behave exactly as
        // it did — B5's "declaring it is optional" half, which is what makes this a
        // contract rather than a migration.
        $declaring = [];
        foreach (array_keys(pp_get_registered_components()) as $component) {
            if (pp_udc_item_roles($component) !== null) {
                $declaring[] = $component;
            }
        }
        $this->assertSame(
            ['grid'],
            $declaring,
            'grid is the first and so far only component to opt in; a second one arriving is a '
            . 'deliberate act that should have to argue with this test'
        );
    }
}
