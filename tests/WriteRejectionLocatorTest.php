<?php
/**
 * tests/WriteRejectionLocatorTest.php — the band a rejected write blocked on (#642).
 *
 * Every composition-mutating action validates the WHOLE composition, so the band that
 * blocks a write is routinely one the caller never named. Before #642 the rejection
 * named the component TYPE only:
 *
 *   page = [logos(bad image_id), logos(bad image_id)]
 *   update_component(component_index: 1, props: {items: [{image_url, image_alt}]})
 *     -> 'Component "logos" prop "items" item 0 field "image_id" must be a number; got array.'
 *   update_component(component_index: 0, ...)  -> the SAME string, byte for byte.
 *
 * The agent submitted a payload with no image_id at all, was told item 0 had a bad one,
 * "fixed" its own payload, resubmitted, and got the identical string back — because the
 * blocking value lived in a band it never touched. The offset was computed all along
 * (#622 stamps it as WP_Error data) and discarded one layer up.
 *
 * What this file pins, per the ratified D1 vocabulary (#687 clause 4):
 *
 *   1. The rejected envelope carries `index` beside `error_code` — an integer
 *      composition offset, or null when no single band owns the rejection.
 *   2. The message names the same band, and the two can never disagree: both read the
 *      one offset the validator stamped.
 *   3. A locator is real or absent, NEVER fabricated — a non-integer key, a cross-item
 *      rule, and add_component's synthetic one-item array all report none.
 *   4. Nothing else moves: the same writes are accepted and rejected with the same
 *      codes, the stored bytes are untouched, and the REPORTING vocabulary that
 *      #650/#652/#687 will decide is byte-identical to what it was.
 *
 * Section 14.1 (authoring path): every locator assertion runs through the real write
 * surface (pp_execute_action), not raw _pp_composition meta writes.
 */

use PHPUnit\Framework\TestCase;

class WriteRejectionLocatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_store']);
        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** A logos band whose stored items[0].image_id is an array — the #642 repro value. */
    private function badLogosBand(string $tag): array
    {
        return [
            'component' => 'logos',
            'props'     => ['id' => 'pp-' . $tag, 'items' => [[
                'image_url' => '/' . $tag . '.png',
                'image_alt' => strtoupper($tag),
                'image_id'  => ['attachment_id' => 42],
            ]]],
        ];
    }

    private function healthyLogosBand(): array
    {
        return [
            'component' => 'logos',
            'props'     => ['items' => [['image_url' => '/ok.png', 'image_alt' => 'OK']]],
        ];
    }

    private function seedPage(int $id, array $composition): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'ID' => $id, 'post_title' => 'Locator Page', 'post_type' => 'page', 'post_status' => 'publish',
        ];
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_wp_page_template'] = 'composition.php';
        $GLOBALS['_pp_test_store']['post_meta'][$id]['_pp_composition']   = wp_json_encode($composition);
    }

    private function storedJson(int $id): string
    {
        return $GLOBALS['_pp_test_store']['post_meta'][$id]['_pp_composition'];
    }

    /** Repairs the band the caller targeted, leaving the other one stale. */
    private function repairPayload(int $index): array
    {
        return [
            'post_id'         => 200,
            'component_index' => $index,
            'props'           => ['items' => [['image_url' => '/new.png', 'image_alt' => 'NEW']]],
        ];
    }

    // ── 1. The #642 repro: two bad bands are now distinguishable ──────────────

    public function testTheTwoBadBandsProduceDistinguishableRejections(): void
    {
        $this->seedPage(200, [$this->badLogosBand('aaa'), $this->badLogosBand('bbb')]);

        $editing_band_1 = pp_execute_action('update_component', $this->repairPayload(1));
        $editing_band_0 = pp_execute_action('update_component', $this->repairPayload(0));

        $this->assertFalse($editing_band_1['ok']);
        $this->assertFalse($editing_band_0['ok']);
        $this->assertNotSame(
            $editing_band_1['error'],
            $editing_band_0['error'],
            'the whole point of #642: repairing band 1 and repairing band 0 must not report the identical string'
        );

        // Repairing band 1 leaves band 0 blocking, and vice versa — the message names
        // the band that is STILL broken, which is the one the agent has to go fix.
        $this->assertSame(
            'Component 0 ("logos") prop "items" item 0 field "image_id" must be a number; got array.',
            $editing_band_1['error']
        );
        $this->assertSame(0, $editing_band_1['index']);

        $this->assertSame(
            'Component 1 ("logos") prop "items" item 0 field "image_id" must be a number; got array.',
            $editing_band_0['error']
        );
        $this->assertSame(1, $editing_band_0['index']);
    }

    /**
     * The mirror case, and the commoner one: the caller's OWN payload is the bad one.
     * Without this, a renderer that always named the first failing OTHER band would pass
     * every other assertion in this file.
     */
    public function testTheCallersOwnBandIsNamedWhenItsOwnPayloadIsBad(): void
    {
        $this->seedPage(200, [$this->healthyLogosBand(), $this->healthyLogosBand()]);

        $result = pp_execute_action('update_component', [
            'post_id'         => 200,
            'component_index' => 1,
            'props'           => ['items' => [['image_url' => '/x.png', 'image_alt' => 'X', 'image_id' => ['x' => 1]]]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['index'], 'the band the caller targeted, not a healthy bystander');
        $this->assertStringStartsWith('Component 1 ("logos")', $result['error']);
    }

    /** create_page validates the whole composition too, and names the band the same way. */
    public function testCreatePageNamesTheBandThatBlockedIt(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'New',
            'composition' => [$this->healthyLogosBand(), $this->badLogosBand('ccc')],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['index']);
        $this->assertStringStartsWith('Component 1 ("logos")', $result['error']);
    }

    public function testTheRejectedEnvelopeCarriesIndexBesideErrorCode(): void
    {
        $this->seedPage(200, [$this->healthyLogosBand(), $this->badLogosBand('bbb')]);

        $result = pp_execute_action('update_component', $this->repairPayload(0));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('index', $result, 'the locator is a first-class envelope field, not message text only');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertSame(1, $result['index']);
        $this->assertStringStartsWith('Component 1 ("logos")', $result['error'],
            'message and payload must agree: both read the offset the validator stamped');
    }

    public function testARejectedWriteStoresNothing(): void
    {
        $composition = [$this->badLogosBand('aaa'), $this->badLogosBand('bbb')];
        $this->seedPage(200, $composition);
        $before = $this->storedJson(200);

        foreach ([0, 1] as $index) {
            $this->assertFalse(pp_execute_action('update_component', $this->repairPayload($index))['ok']);
        }
        $this->assertFalse(pp_execute_action('update_composition', [
            'post_id' => 200, 'composition' => $composition,
        ])['ok']);

        $this->assertSame($before, $this->storedJson(200),
            'naming the band changes what a rejection SAYS, never what is stored');
    }

    // ── 2. One case per rule family: every write rejection names its band ──────

    /**
     * The tripwire for the message-rendering coupling documented on
     * _pp_band_named_composition_error(): the band is rendered by rewriting the leading
     * component label, so a rule that rewords its message must not silently lose the
     * locator. One case per family that can fire on a stored band.
     *
     * @dataProvider ruleFamilies
     */
    public function testWriteRejectionsNameTheirBand(array $band, string $expected_prefix, string $code): void
    {
        $this->seedPage(200, [$this->healthyLogosBand(), $band]);

        $result = pp_execute_action('update_component', $this->repairPayload(0));

        $this->assertFalse($result['ok'], 'the stale band must still block the write');
        $this->assertSame($code, $result['error_code']);
        $this->assertSame(1, $result['index']);
        $this->assertStringStartsWith($expected_prefix, $result['error']);
    }

    public static function ruleFamilies(): array
    {
        return [
            'nested field type' => [
                ['component' => 'logos', 'props' => ['items' => [['image_url' => '/a.png', 'image_alt' => 'A', 'image_id' => ['x' => 1]]]]],
                'Component 1 ("logos") prop "items" item 0 field "image_id" must be a number',
                'invalid_prop_value',
            ],
            'nested unknown field' => [
                ['component' => 'logos', 'props' => ['items' => [['image_url' => '/a.png', 'image_alt' => 'A', 'imageId' => 42]]]],
                'Component 1 ("logos") prop "items" item 0 has no field "imageId"',
                'unknown_prop',
            ],
            'nested missing required field' => [
                ['component' => 'logos', 'props' => ['items' => [['image_alt' => 'A']]]],
                'Component 1 ("logos") prop "items" item 0 is missing required field "image_url"',
                'invalid_composition',
            ],
            'nested entry shape' => [
                ['component' => 'logos', 'props' => ['items' => ['nope']]],
                'Component 1 ("logos") prop "items" item 0 must be an object',
                'invalid_prop_value',
            ],
            'prop type' => [
                ['component' => 'logos', 'props' => ['items' => 'nope']],
                'Component 1 ("logos") prop "items" must be an array',
                'invalid_prop_value',
            ],
            'strict enum' => [
                ['component' => 'logos', 'props' => ['theme' => 'bogus', 'items' => [['image_url' => '/a.png', 'image_alt' => 'A']]]],
                'Component 1 ("logos") prop "theme" must be one of',
                'invalid_prop_value',
            ],
            'missing required prop' => [
                ['component' => 'cta', 'props' => ['title' => 'T']],
                'Component 1 ("cta") is missing required prop "button_text"',
                'invalid_composition',
            ],
            'unknown prop' => [
                ['component' => 'hero', 'props' => ['title' => 'T', 'subtitle' => 'S']],
                'Component 1 ("hero") has no prop "subtitle"',
                'unknown_prop',
            ],
            'content requirement' => [
                ['component' => 'section', 'props' => []],
                'Component 1 ("section") needs at least one of',
                'invalid_composition',
            ],
            'component style slot' => [
                ['component' => 'hero', 'props' => ['title' => 'T'], 'style' => ['--nope' => '1']],
                'Component 1 ("hero") has no style slot "--nope"',
                'invalid_style_slot',
            ],
            'per-item style slot' => [
                ['component' => 'grid', 'props' => ['items' => [['title' => 'T', 'text' => 'x', 'style' => ['--nope' => '1']]]]],
                'Component 1 ("grid") item 0 has no style slot "--nope"',
                'invalid_style_slot',
            ],
            'link url' => [
                ['component' => 'cta', 'props' => ['title' => 'T', 'button_text' => 'G', 'button_url' => 'javascript:alert(1)']],
                'Component 1 ("cta") prop "button_url" is not a usable link URL',
                'invalid_prop_value',
            ],
            // Two families name the component in their own words, so the parenthesised
            // form would stutter. They get the offset, which is what was missing.
            'unknown component' => [
                ['component' => 'nope', 'props' => []],
                'Component 1: Unknown component: "nope".',
                'invalid_composition',
            ],
            'template-owned chrome' => [
                ['component' => 'nav', 'props' => []],
                'Component 1: "nav" is site chrome',
                'template_owned_component',
            ],
        ];
    }

    /**
     * The other structural rule: a band that HAS a `component` key whose value is an
     * array. Its message names the offset already, and the component name is unusable,
     * so the renderer must leave it alone here too.
     */
    public function testANonScalarComponentKeyKeepsItsOwnItemPrefix(): void
    {
        $this->seedPage(200, [$this->healthyLogosBand(), ['component' => ['logos'], 'props' => []]]);

        $result = pp_execute_action('update_component', $this->repairPayload(0));

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['index']);
        $this->assertSame('Item 1 has a non-scalar "component" key.', $result['error']);
    }

    /**
     * The structural rules spell their own "Item N" prefix for this same offset, so the
     * renderer leaves them alone rather than stuttering "Component 1: Item 1 is ...".
     */
    public function testAStructuralRejectionKeepsItsOwnItemPrefix(): void
    {
        $this->seedPage(200, [$this->healthyLogosBand(), ['props' => []]]);

        $result = pp_execute_action('update_component', $this->repairPayload(0));

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['index']);
        $this->assertSame('Item 1 is missing the "component" key.', $result['error']);
    }

    // ── 3. A locator is real or absent, never fabricated ──────────────────────

    /**
     * A cross-item rule belongs to no single band. It already names every colliding
     * index in its own message, so it keeps that message and reports no offset.
     */
    public function testACrossItemRejectionReportsNoBand(): void
    {
        $duplicate = static fn () => [
            'component' => 'logos',
            'props'     => ['id' => 'dup', 'items' => [['image_url' => '/a.png', 'image_alt' => 'A']]],
        ];
        $this->seedPage(200, [$duplicate(), $duplicate()]);

        $result = pp_execute_action('update_composition', [
            'post_id' => 200, 'composition' => [$duplicate(), $duplicate()],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('duplicate_component_id', $result['error_code']);
        $this->assertNull($result['index']);
        $this->assertStringStartsWith('Duplicate component id "dup" on items 0, 1.', $result['error']);
    }

    /**
     * A composition stored as a JSON OBJECT decodes to string keys. There is no integer
     * offset to report, so the write reports none — the honest-null contract
     * pp_composition_error_index() has enforced since #622.
     *
     * #650 ANSWERED THE OTHER HALF. This test used to assert `Item 0`, and that string was
     * the defect: `(int) "aa"` is 0, there is no band 0, and the SAME WP_Error honestly
     * carried `index: null` while its own message named a band that does not exist. Message
     * and payload must agree; a locator is real or absent, never fabricated. The message now
     * names the real key, so an operator repairs the band that is actually broken.
     *
     * The payload stays null on purpose: `index` is typed as an integer composition offset
     * (#622), and a string key is not one. Carrying the locator in the words and not in the
     * field is the honest reading, and it is what #650's recorded expectation asks for.
     *
     * #724 SUPERSEDES THE WRITE-PATH HALF OF THIS BLOCK, and the six tests below say so
     * one at a time rather than being deleted. The write path no longer reaches the
     * structural band rules with an object-shaped COMPOSITION at all: ruling D-A refuses
     * the container first, so `{"aa": ...}` is answered "this is not a composition" instead
     * of "this band is missing its component key". That is the stronger claim and the one
     * an agent can act on — the band was never addressable, because the thing holding it
     * was not a composition. What #650/#652 built is NOT deleted by that: the same locator
     * renderer still serves the `items[]` depth on every ordinary list-shaped write (pinned
     * in DiagnosticReachTest), and the BAND-level spelling is pinned directly below in
     * testTheBandLocatorRendererStillSpellsAnObjectKeyHonestly so the contract survives
     * with evidence even though no production caller can reach it through the write path.
     */
    public function testANonIntegerCompositionKeyIsRefusedAsAContainerBeforeAnyBandRule(): void
    {
        $this->seedPage(200, [$this->healthyLogosBand()]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => 200,
            'composition' => ['aa' => ['props' => []]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error_code']);
        $this->assertNull($result['index'], 'a container-level refusal owns no band offset');
        $this->assertStringContainsString('must be a list of components', $result['error']);
        $this->assertStringNotContainsString('Item 0', $result['error'],
            'the fabricated band 0 is what #650 removed; #724 removes the band claim entirely');
    }

    /**
     * #650/#652's BAND-level renderer, pinned where it can still be exercised.
     *
     * After #724 no write can hand an object-shaped composition to the structural rules, so
     * the messages the five superseded tests above used to assert are unreachable through
     * the action layer. The renderer that produces them is still shipped and still shared
     * with the `items[]` depth, and a locator contract that survives only as prose is one
     * refactor away from silently regressing. This asserts the contract directly, on the
     * exact containers those tests used to drive through the write path.
     */
    public function testTheBandLocatorRendererStillSpellsAnObjectKeyHonestly(): void
    {
        $string_keyed = ['aa' => ['props' => []]];
        $this->assertSame('Item key "aa"', _pp_band_index_label('aa', $string_keyed));

        $numeric_object = [1 => ['props' => []], 0 => ['props' => []]];
        $this->assertSame('Item key "1"', _pp_band_index_label(1, $numeric_object),
            'a numeric OBJECT key still reads as a key, never as a position');

        $sparse = [3 => ['props' => []]];
        $this->assertSame('Item key "3"', _pp_band_index_label(3, $sparse));

        // The load-bearing constraint from #652: a LIST container is byte-identical.
        $this->assertSame('Item 1', _pp_band_index_label(1, [['props' => []], ['props' => []]]));
    }

    /**
     * The #642 WRITE-BOUNDARY PREFIX, pinned where it can still be exercised.
     *
     * testTheWriteBoundaryPrefixIsNotReachedByAnObjectComposition and
     * testASparseNumericKeyIsRefusedAsAContainer above used to be the ONLY coverage of
     * `Component key "N" ("logos")`, and rewriting them for #724 would have left that
     * branch of _pp_band_named_composition_error() both unreachable AND unpinned — a
     * different function from the _pp_band_index_label() renderer pinned above, so the
     * sibling test does not cover it. Driven directly here on the same containers.
     */
    public function testTheWriteBoundaryPrefixStillSpellsAnObjectKeyHonestly(): void
    {
        $sparse   = [3 => $this->badLogosBand('ccc')];
        $stamped  = new WP_Error('invalid_style_slot', 'Component "logos" style slot "--ccc" is not declared.', ['index' => 3]);
        $rendered = _pp_band_named_composition_error($stamped, $sparse);
        $this->assertStringStartsWith('Component key "3" ("logos")', $rendered->get_error_message());
        $this->assertStringNotContainsString('Component 3 ', $rendered->get_error_message(),
            'a bare 3 reads as the fourth band; there is one band, stored under key 3');

        // A LIST container stays byte-identical — the #652 constraint, at this depth too.
        $listed = _pp_band_named_composition_error(
            new WP_Error('invalid_style_slot', 'Component "logos" style slot "--ccc" is not declared.', ['index' => 0]),
            [$this->badLogosBand('ccc')]
        );
        $this->assertStringStartsWith('Component 0 ("logos")', $listed->get_error_message());
    }

    /**
     * The duplicate-id KEY LIST, pinned where it can still be exercised.
     *
     * testDuplicateComponentIdNamesObjectKeysAsKeys above was the only coverage of the
     * cross-item pass rendering `on items key "1", key "0".`. That pass runs after the
     * per-item loop, so #724's container gate makes it unreachable from a write; the
     * rendering itself is unchanged and is asserted here against the engine directly.
     */
    public function testTheDuplicateIdKeyListStillSpellsObjectKeysAsKeys(): void
    {
        $dupe = static fn () => [
            'component' => 'logos',
            'props'     => ['id' => 'dup', 'items' => [['image_url' => '/a.png', 'image_alt' => 'A']]],
        ];

        // The cross-item detector reads the container it is given; drive it past the
        // container gate the same way the engine's own pass does.
        $collisions = pp_find_duplicate_component_ids([1 => $dupe(), 0 => $dupe()]);
        $this->assertCount(1, $collisions);
        $rendered = implode(', ', array_map(
            static fn ($key) => _pp_item_index_label($key, [1 => $dupe(), 0 => $dupe()]),
            $collisions[0]['indices']
        ));
        $this->assertSame('key "1", key "0"', $rendered);

        // List container: byte-identical, and this one IS still reachable from a write.
        $listed = pp_validate_composition_errors([$dupe(), $dupe()]);
        $this->assertSame('duplicate_component_id', $listed[0]->get_error_code());
        $this->assertStringStartsWith('Duplicate component id "dup" on items 0, 1.', $listed[0]->get_error_message());
    }

    /**
     * THE #650 REPRO THAT SURVIVES A NUMERIC KEY. `{"1": ..., "0": ...}` decodes to real
     * integer keys, so the payload CAN carry an honest offset — but the message used to
     * render it with `%d`, which reads as a POSITION. Iteration position 0 holds the "1"
     * band, so an operator counting bands from the top repairs the wrong one. Naming it as
     * a key is what makes the message and the payload say the same thing: `$items[0]` is a
     * key lookup, and it resolves to the band the message names.
     */
    public function testANumericObjectCompositionIsRefusedAsAContainer(): void
    {
        // SUPERSEDED BY #724 (see the block comment above): this used to assert
        // `Item key "1" is missing the "component" key.` The write path now answers the
        // container, so no band is named at all — and `index` goes null, because a
        // container-level refusal belongs to no band.
        $this->seedPage(200, [$this->healthyLogosBand()]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => 200,
            'composition' => [1 => ['props' => []], 0 => ['props' => []]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error_code']);
        $this->assertNull($result['index']);
        $this->assertStringContainsString('JSON object (2 entries)', $result['error']);
        $this->assertStringNotContainsString('Component 1:', $result['error'],
            'no band prefix may attach to a container-level refusal');
    }

    /**
     * #650 names TWO structural sites, and this is the second one. The missing-component rule
     * above is pinned three ways; the non-scalar-component rule beside it was asserted only on
     * LIST-shaped compositions, which is the shape that did not move — so half the issue would
     * have shipped verified only against the case it does not change.
     */
    public function testANonScalarComponentOnAnObjectKeyedBandIsRefusedAsAContainer(): void
    {
        // SUPERSEDED BY #724: the second structural site is unreachable from the write path
        // for the same reason as the first. The non-scalar-`component` rule itself is
        // untouched and still fires on a LIST — pinned by the sibling test in this file.
        $this->seedPage(200, [$this->healthyLogosBand()]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => 200,
            'composition' => ['aa' => ['component' => ['not', 'scalar'], 'props' => []]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error_code']);
        $this->assertNull($result['index']);
        $this->assertStringNotContainsString('Item 0', $result['error']);
    }

    /**
     * THE #642 WRITE-BOUNDARY PREFIX, converged by the #687 addendum.
     *
     * `[1 => bad, 0 => healthy]` is not a list. The bad band is stored under key 1 and is
     * the FIRST one iterated, so the old `Component 1` sent a reader to the second band —
     * which is the healthy one. Exactly the lie #650 removed from the structural family,
     * in the message family an agent actually hits on a rejected write.
     */
    public function testTheWriteBoundaryPrefixIsNotReachedByAnObjectComposition(): void
    {
        // SUPERSEDED BY #724: `[1 => bad, 0 => healthy]` never reaches the #642 prefix,
        // because the container is refused before any band is judged. The prefix itself is
        // unchanged and pinned byte-identically on list-shaped compositions below.
        $this->seedPage(200, [$this->healthyLogosBand()]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => 200,
            'composition' => [1 => $this->badLogosBand('ccc'), 0 => $this->healthyLogosBand()],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error_code']);
        $this->assertNull($result['index']);
        $this->assertStringNotContainsString('Component ', $result['error'],
            'a container refusal names no component and no band');
    }

    /**
     * The cross-item duplicate-id rule names SEVERAL bands in one breath, so it is the one
     * message where a key read as a position is hardest to notice. Its `index` stays null —
     * it belongs to no single band, and that contract is unchanged — but the keys it lists
     * now say what they are.
     */
    public function testDuplicateComponentIdNamesObjectKeysAsKeys(): void
    {
        $dupe = static fn () => [
            'component' => 'logos',
            'props'     => ['id' => 'dup', 'items' => [['image_url' => '/a.png', 'image_alt' => 'A']]],
        ];
        $this->seedPage(200, [$dupe()]);

        $result = pp_execute_action('update_composition', [
            'post_id' => 200, 'composition' => [1 => $dupe(), 0 => $dupe()],
        ]);

        // SUPERSEDED BY #724: the cross-item pass runs AFTER the per-item loop, so an
        // object-shaped composition never reaches it either. The duplicate-id rule and its
        // key-list rendering are unchanged on list-shaped compositions (pinned below).
        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error_code']);
        $this->assertNull($result['index'], 'a container refusal owns no band, same as the cross-item rule');
        $this->assertStringNotContainsString('Duplicate component id', $result['error']);
    }

    /**
     * BYTE-IDENTICAL PIN for both newly converged families. A list-shaped composition is
     * what every shipped example authors, so neither the #642 prefix nor the duplicate-id
     * key list may have moved one byte.
     */
    public function testListShapedCompositionsKeepBothConvergedFamiliesVerbatim(): void
    {
        $dupe = static fn () => [
            'component' => 'logos',
            'props'     => ['id' => 'dup', 'items' => [['image_url' => '/a.png', 'image_alt' => 'A']]],
        ];
        $this->seedPage(200, [$dupe(), $dupe()]);

        $collision = pp_execute_action('update_composition', [
            'post_id' => 200, 'composition' => [$dupe(), $dupe()],
        ]);
        $this->assertStringStartsWith('Duplicate component id "dup" on items 0, 1.', $collision['error']);

        $this->seedPage(201, [$this->healthyLogosBand()]);
        $rejected = pp_execute_action('update_composition', [
            'post_id'     => 201,
            'composition' => [$this->healthyLogosBand(), $this->badLogosBand('ccc')],
        ]);
        $this->assertStringStartsWith('Component 1 ("logos")', $rejected['error']);
    }

    /**
     * THE BYTE-IDENTICAL PIN for the band family. Every shipped example authors a
     * composition as a JSON list, so this is the shape essentially every real rejection
     * has. It must not have moved one byte in #650/#652.
     */
    public function testAListShapedCompositionKeepsItsIntegerBandLabelVerbatim(): void
    {
        $this->seedPage(200, [$this->healthyLogosBand()]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => 200,
            'composition' => [$this->healthyLogosBand(), ['props' => []]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['index']);
        $this->assertSame('Item 1 is missing the "component" key.', $result['error']);
    }

    /**
     * The stamped offset is the composition ARRAY KEY, not a running count, so a sparse
     * or out-of-order map names the key the operator can address.
     *
     * SINCE #650/#652 THE MESSAGE SAYS SO OUT LOUD, and that is this test's own thesis
     * finally reaching the words. `[3 => band]` is a single band at iteration position 0,
     * so the old `Component 3` was true as a key and false as a position — a reader counts
     * to a fourth band that does not exist. This fixture was always the object-shape seam
     * for the #642 prefix (its acceptance fixtures are list-shaped and unmoved); the
     * `key "3"` form is what makes the prefix agree with the `index` beside it, which has
     * been a key lookup since #622.
     */
    public function testASparseNumericKeyIsRefusedAsAContainer(): void
    {
        // SUPERSEDED BY #724: `[3 => band]` is a one-entry object, not a one-band list, and
        // the container rule is what says so. The `key "3"` spelling stays pinned directly
        // in testTheBandLocatorRendererStillSpellsAnObjectKeyHonestly.
        $this->seedPage(200, [3 => $this->badLogosBand('ccc')]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => 200,
            'composition' => [3 => $this->badLogosBand('ccc')],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error_code']);
        $this->assertNull($result['index']);
        $this->assertStringContainsString('JSON object (1 entry)', $result['error']);
    }

    /**
     * add_component validates a synthetic one-item array (it judges only the item it
     * adds), so offset 0 inside it is NOT the page's band 0. Reporting it would send an
     * agent to repair an unrelated stored band.
     */
    public function testAddComponentReportsNoBandForItsOwnPayload(): void
    {
        $this->seedPage(200, [$this->healthyLogosBand(), $this->healthyLogosBand()]);

        $result = pp_execute_action('add_component', [
            'post_id'   => 200,
            'component' => 'logos',
            'props'     => ['items' => [['image_url' => '/x.png', 'image_alt' => 'X', 'image_id' => ['x' => 1]]]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNull($result['index'], 'offset 0 of a one-item array is not the page\'s band 0');
        $this->assertSame(
            'Component "logos" prop "items" item 0 field "image_id" must be a number; got array.',
            $result['error'],
            'unchanged: the rejection describes the payload the caller just submitted'
        );
    }

    /**
     * Execute-stage failures are writer errors (pp_update_composition is a non-validating
     * writer), so they belong to no band and say so rather than defaulting to 0.
     */
    public function testAnExecuteStageFailureReportsNoBand(): void
    {
        $this->seedPage(200, [$this->healthyLogosBand()]);

        $result = pp_execute_action('update_composition', [
            'post_id'          => 200,
            'composition'      => [$this->healthyLogosBand()],
            'expected_version' => 999,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('composition_conflict', $result['error_code']);
        $this->assertArrayHasKey('index', $result, 'a rejected envelope carries the field even when no band owns it');
        $this->assertNull($result['index']);
    }

    /**
     * A rejection with no composition offset at all — a param-shape error, resolved
     * before any composition rule runs — reports null rather than a fabricated 0.
     */
    public function testAParamRejectionReportsNoBand(): void
    {
        $result = pp_execute_action('update_component', [
            'post_id'         => 999999,
            'component_index' => 0,
            'props'           => ['title' => 'T'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNull($result['index']);
    }

    // ── 3b. The renderers themselves ──────────────────────────────────────────

    /**
     * Re-rendering a message means building a new WP_Error, and a new WP_Error is where
     * producer-stamped context goes to die. The #626 rejected-slot payload is the live
     * example: it rides in the same data array as the offset, and a rendering step that
     * re-stamped `['index' => N]` would silently drop it.
     */
    public function testTheBandRendererCarriesProducerStampedDataForward(): void
    {
        $error = new WP_Error(
            'invalid_style_slot',
            'Component "hero" has no style slot "--nope". Available: --hero-bg',
            [
                'index'           => 1,
                'component_name'  => 'hero',
                'available_slots' => ['--hero-bg' => ['type' => 'color']],
                'candidate_slots' => ['--nope'],
            ]
        );

        $rendered = _pp_band_named_composition_error($error, [
            ['component' => 'logos'],
            ['component' => 'hero'],
        ]);

        $this->assertStringStartsWith('Component 1 ("hero")', $rendered->get_error_message());
        $this->assertSame(1, pp_composition_error_index($rendered));
        $this->assertNotNull(pp_rejected_slot_context($rendered), 'the sibling context must survive re-rendering');
    }

    public function testDroppingTheOffsetKeepsEverythingElse(): void
    {
        $unlocated = _pp_unlocated_composition_error(
            new WP_Error('unknown_prop', 'Component "hero" has no prop "x".', ['index' => 0, 'component_name' => 'hero'])
        );

        $this->assertNull(pp_composition_error_index($unlocated));
        $this->assertSame('Component "hero" has no prop "x".', $unlocated->get_error_message());
        $this->assertSame('unknown_prop', $unlocated->get_error_code());
        $this->assertSame('hero', $unlocated->get_error_data()['component_name']);

        // Already unlocated: returned untouched rather than rebuilt.
        $none = new WP_Error('invalid_composition', 'No band owns this.');
        $this->assertSame($none, _pp_unlocated_composition_error($none));
    }

    /**
     * The no-stutter branch is keyed on THIS error's offset, not on the word "Item".
     * A message naming a different position must still get its real band.
     */
    public function testAnItemPrefixForAnotherPositionDoesNotSuppressTheBand(): void
    {
        $rendered = _pp_band_named_composition_error(
            new WP_Error('invalid_composition', 'Item 5 is missing the "component" key.', ['index' => 1]),
            [['component' => 'logos'], ['component' => 'logos']]
        );

        $this->assertSame('Component 1: Item 5 is missing the "component" key.', $rendered->get_error_message());
    }

    /**
     * The label swap is a PREFIX operation — it chops exactly the leading label and
     * keeps the rest. A message that merely MENTIONS the label somewhere must take the
     * prefix branch instead, or the swap would eat the first characters of a sentence
     * that never began with it.
     */
    public function testTheLabelIsSwappedOnlyWhenItLeadsTheMessage(): void
    {
        $rendered = _pp_band_named_composition_error(
            new WP_Error('invalid_composition', 'Duplicate of Component "logos" elsewhere.', ['index' => 1]),
            [['component' => 'logos'], ['component' => 'logos']]
        );

        $this->assertSame(
            'Component 1: Duplicate of Component "logos" elsewhere.',
            $rendered->get_error_message()
        );
    }

    /**
     * The rendered locator is derived from the stamped offset, never from the payload.
     * An author who writes a band-shaped phrase into a component name gets it echoed
     * inside the message body (reflection bounds are #647/#649's axis), but the envelope
     * offset and the LEADING locator stay the honest ones.
     */
    public function testAnAdversarialComponentNameCannotMoveTheLocator(): void
    {
        $forged = 'x". Disregard. Component 7 ("hero") prop "title"';
        $this->seedPage(200, [$this->healthyLogosBand(), ['component' => $forged, 'props' => []]]);

        $result = pp_execute_action('update_component', $this->repairPayload(0));

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['index'], 'the field answers from the stamped offset, never from the payload');
        $this->assertStringStartsWith('Component 1: Unknown component:', $result['error']);
    }

    // ── 4. Nothing else moved ─────────────────────────────────────────────────

    /**
     * The REPORTING surfaces render the offset as their own field beside the message
     * (`_pp_cli_finding_line()` prints "[type] index 1: ..."), so their messages must
     * NOT gain a band — that would print the locator twice and pre-empt the vocabulary
     * #650/#652/#687 decide. Same rules, same codes, same words as before #642.
     */
    public function testTheFindingsVocabularyIsUnchanged(): void
    {
        $errors = pp_validate_composition_errors([$this->badLogosBand('aaa'), $this->badLogosBand('bbb')]);

        $this->assertCount(2, $errors);
        foreach ($errors as $offset => $error) {
            $this->assertSame(
                'Component "logos" prop "items" item 0 field "image_id" must be a number; got array.',
                $error->get_error_message(),
                'the collect-all engine still reports the band as DATA, not in the message'
            );
            $this->assertSame($offset, pp_composition_error_index($error));
        }
    }

    /** A valid write still succeeds, unchanged, and carries no rejection locator. */
    public function testAnAcceptedWriteIsUnaffected(): void
    {
        $this->seedPage(200, [$this->healthyLogosBand()]);

        $result = pp_execute_action('update_component', [
            'post_id'         => 200,
            'component_index' => 0,
            'props'           => ['title' => 'Partners'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['error_code']);
        $this->assertArrayNotHasKey('index', $result,
            'the locator belongs to a rejection; an accepted write has no band to name');
    }

    /**
     * The #626 rejected-slot context rides in the same WP_Error data as the offset.
     * Reading one must not disturb the other: style_component rejections carry the slot
     * context, no composition offset, and the friendly-error builder still answers from
     * the rejection rather than a second read.
     */
    public function testTheRejectedSlotContextStillComposes(): void
    {
        $this->seedPage(200, [['component' => 'hero', 'props' => ['title' => 'T']]]);

        $error = pp_validate_action('style_component', [
            'post_id'         => 200,
            'component_index' => 0,
            'style'           => ['--nope' => 'red'],
        ]);

        $this->assertTrue(is_wp_error($error));
        $this->assertSame('invalid_style_slot', $error->get_error_code());
        $this->assertNull(pp_composition_error_index($error), 'no composition offset was ever stamped here');
        $this->assertNotNull(pp_rejected_slot_context($error), 'the #626 context survives untouched');

        $envelope = pp_execute_action('style_component', [
            'post_id'         => 200,
            'component_index' => 0,
            'style'           => ['--nope' => 'red'],
        ]);
        $this->assertFalse($envelope['ok']);
        $this->assertNull($envelope['index']);
    }
}
