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
        $this->assertStringNotContainsString(
            '<li class="grid__item" style=',
            $html,
            '§3.4 forbids inline style emission outright — that is what items[].udc replaced'
        );
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
