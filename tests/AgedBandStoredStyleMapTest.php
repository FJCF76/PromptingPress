<?php
/**
 * THE AGED BAND: what a stored v1 `style` map does after the engine that read it is gone
 * (#1101 PR2, ruling D2).
 *
 * WHY THIS FILE EXISTS, AND WHY IT IS NEW IN A DELETION PR. The seam analysis that sized
 * this sweep counted `_pp_validate_style_slot_map()`, `_pp_no_style_slots_clause()` and
 * `update_component`'s `style` parameter among ~1,650 lines of unreachable code, on the
 * reasoning that no component declares a style slot any more. THAT READING IS BACKWARDS,
 * and measuring it is what this file records: those functions are not the retirement's
 * leftovers, they are the retirement's PRODUCT. A page authored before its component was
 * rebuilt still has a `style` map in its stored bytes, nothing rewrote it, and the
 * validator is the only thing that tells its author so.
 *
 * WHAT WAS MEASURED, and every line of it is asserted below:
 *
 *   aged band, stored style: {"--grid-item-bg": "#101014", "--grid-gap": "2rem"}
 *
 *     update_component props-only ............ REFUSED  invalid_style_slot
 *     update_component clearing ONE slot ..... REFUSED  invalid_style_slot (names the OTHER)
 *     update_component clearing BOTH ......... ACCEPTED, the `style` key removed entirely
 *
 * The first line is the one that matters and the one nobody expected: a stored map from a
 * previous era makes the band UNEDITABLE through the action layer until it is cleared. Not
 * "styling is refused" — every edit is, a title change included. That is a real migration
 * burden on every page built before Sprint 2, and it is the reason `style_component`'s
 * refusal and this validator survive a sweep that deletes the engine behind them.
 *
 * THE SECOND LINE IS WHY THE REFUSAL MESSAGE CHANGED. It used to end "To clear a stored
 * slot, send it as null through update_component's `style` param" — singular, and wrong in
 * the one direction that costs an author a loop: the validator walks the whole MERGED map,
 * so clearing one of two is refused naming the one still there, which reads as a new
 * problem rather than as "send them all". The message now states the measured rule, and
 * the assertions below hold the message and the behaviour to each other so they cannot
 * drift apart again.
 *
 * WHAT THIS FILE IS NOT. It is not a style-slot test. Nothing here declares a slot, asks
 * for one, or expects one to paint — `grid` declares none, which is precisely the
 * precondition every case below runs under. The CSS-side contract these slots used to
 * carry is retired with its record in tests/StyleSlotContractTest.php.
 *
 * AUTHORED THROUGH THE REAL SURFACE (Section 14.1) for every write under test. The aged
 * band itself is seeded through pp_update_composition() — the real writer — because that
 * is how the bytes got there: the write path accepted them when the component still
 * declared the slots, and no migration has touched them since.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class AgedBandStoredStyleMapTest extends TestCase
{
    /**
     * Two slots, because ONE would hide the defect this file was written for: with a
     * single stored slot, "clear the slot" and "clear every slot in one call" are the same
     * call and the partial-clear refusal has nothing to fire on.
     *
     * AND A `__recipe` KEY, added after review, because without it this fixture could not
     * see the gap in its own repair instruction. `__recipe` is what v1 wrote into the style
     * map every time a recipe was applied, and grid shipped three recipes until earlier in
     * #1101 — so a large share of real aged bands carry it. It is NOT a slot name, so an
     * author following an instruction that says "every stored SLOT name" omits it, and the
     * clear then succeeds while leaving a v1 key in the composition. Measured: the
     * slot-only clear returns ok:true with `{"__recipe":"dark-showcase"}` still stored.
     *
     * It is also why the skip at lib/admin.php:1343 is load-bearing rather than tidy: a
     * band storing ONLY `__recipe` is not blocked at all (measured: a props-only edit
     * returns ok:true), so removing the skip would make every recipe-applied v1 band
     * permanently uneditable — a worse version of the burden this file exists to disclose.
     */
    private const AGED_STYLE = [
        '__recipe'       => 'dark-showcase',
        '--grid-item-bg' => '#101014',
        '--grid-gap'     => '2rem',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    /** A page whose one band carries a stored `style` map from before grid's rebuild. */
    private function agedPage(): int
    {
        $id = pp_create_page('Aged band', 'draft');
        pp_update_composition($id, [[
            'component' => 'grid',
            'props'     => ['id' => 'g1', 'title' => 'T', 'items' => [['title' => 'One']]],
            'style'     => self::AGED_STYLE,
        ]]);

        return $id;
    }

    // ── 1. THE PRECONDITION ─────────────────────────────────────────────────────

    /**
     * THE FLOOR. Every case below is about a component that declares NO style slots; if
     * grid ever declared one again, all of them would be testing something else and
     * passing while they did it.
     */
    public function testGridDeclaresNoStyleSlotsAtAll(): void
    {
        $schema = pp_get_registered_components()['grid'];

        $this->assertSame(
            [],
            $schema['styling']['style_slots'] ?? [],
            'precondition: the whole file is about a component with no slots'
        );
    }

    /**
     * THE STARTER SEED CARRIES NO v1 STYLE MAP, which is the other half of the
     * precondition: if the theme's own seeded homepage shipped one, every fresh install
     * would be an aged page on day one and the refusal above would fire on content the
     * operator never authored.
     *
     * Salvaged from ActionsTest::testWidestShippedStyleMapIsReportedComplete at #1101,
     * whose other claim (a wide slot map must be reported COMPLETE rather than partially)
     * died with the cross-component hint scan. This half is a live census and belongs
     * beside the aged-band refusal rather than inside a friendly-error test. The seed's
     * last style map was grid's twenty slots, converted to a `udc` map by its rebuild.
     */
    public function testTheStarterHomepageSeedCarriesNoV1StyleMap(): void
    {
        $bands = pp_default_homepage_composition();

        $this->assertNotSame([], $bands, 'precondition: the starter seed actually has bands');
        foreach ($bands as $i => $band) {
            $this->assertSame(
                [],
                $band['style'] ?? [],
                "starter band {$i} ships a v1 style map, so a fresh install would be an aged "
                . 'page before anyone edited it'
            );
        }
    }

    /** And the aged bytes really are on the page — not quietly dropped by the writer. */
    public function testTheStoredMapSurvivesTheWriteThatSeededIt(): void
    {
        $composition = pp_get_composition($this->agedPage());

        $this->assertSame(
            self::AGED_STYLE,
            $composition[0]['style'],
            'nothing migrated these bytes away, which is the whole situation'
        );
    }

    // ── 2. THE MEASUREMENT ──────────────────────────────────────────────────────

    /**
     * THE HEADLINE, AND THE REASON THE VALIDATOR IS NOT DEAD CODE: a stored map from a
     * previous era refuses an edit that has nothing to do with styling.
     */
    public function testAPropsOnlyEditIsRefusedByTheStoredMapAlone(): void
    {
        $id     = $this->agedPage();
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'A new title'],
        ]);

        $this->assertFalse($result['ok'], 'the band cannot be edited at all while the map is stored');
        $this->assertSame('invalid_style_slot', $result['error_code']);
        $this->assertSame(
            self::AGED_STYLE,
            pp_get_composition($id)[0]['style'],
            'and the refusal changed nothing — no partial write, no silent repair'
        );
        $this->assertSame(
            'T',
            pp_get_composition($id)[0]['props']['title'],
            'the props the author DID send are not applied either'
        );
    }

    /**
     * THE DEFECT THE MESSAGE USED TO CAUSE. Clearing one slot is refused, and the refusal
     * names the slot the author did NOT touch — so following the old singular instruction
     * produced a message that read like a second, unrelated problem.
     */
    public function testClearingOneOfTwoStoredSlotsIsRefusedNamingTheOtherOne(): void
    {
        $id     = $this->agedPage();
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => [],
            'style'           => ['--grid-item-bg' => null],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_style_slot', $result['error_code']);
        $this->assertStringContainsString(
            '--grid-gap',
            $result['error'],
            'the refusal names the slot still stored, not the one being cleared'
        );
        $this->assertSame(
            self::AGED_STYLE,
            pp_get_composition($id)[0]['style'],
            'and neither slot was removed — a refused partial clear is not a partial clear'
        );
    }

    /**
     * THE REPAIR THAT WORKS, and the shape the message now tells the author to send:
     * every stored name as null, in one call, with `props` present because the action
     * requires it.
     */
    public function testClearingEveryStoredKeyInOneCallIsAcceptedAndRemovesTheMap(): void
    {
        $id     = $this->agedPage();
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => [],
            // EVERY KEY, `__recipe` INCLUDED. Sending only the two slot names is accepted
            // and leaves `{"__recipe":"dark-showcase"}` stored — measured. That is the gap
            // the old instruction ("every stored SLOT name") walked an author into.
            'style'           => ['--grid-item-bg' => null, '--grid-gap' => null, '__recipe' => null],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertArrayNotHasKey(
            'style',
            pp_get_composition($id)[0],
            'the map is gone entirely, not left as an empty array for the next read to trip on'
        );
    }

    /** And once it is gone, the ordinary edit that was refused above goes through. */
    public function testThePropsOnlyEditSucceedsOnceTheMapIsCleared(): void
    {
        $id = $this->agedPage();
        pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => [],
            'style'           => ['--grid-item-bg' => null, '--grid-gap' => null],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'A new title'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('A new title', pp_get_composition($id)[0]['props']['title']);
    }

    /**
     * THE SIBLING-BAND DISCLOSURE, and it is the half the refusals above cannot show.
     *
     * #1007 inverted what a stale map does to the REST of the page: `update_component`
     * validates the band it TARGETS, so an aged band no longer refuses an edit to its
     * neighbours. Nothing is migrated or healed by that — the stale bytes stay stale — so
     * the disclosure has to arrive somewhere, and it arrives on the accepted envelope of
     * the write that touched a different band entirely.
     *
     * Without this, an operator repairing a page band by band would see the aged band's
     * problem only by trying to edit the aged band. The envelope tells them on the first
     * accepted write anywhere on the page.
     *
     * Re-homed from StoredCompositionAliasRenderTest at #1101, where it ran on the
     * fixture's slot map. Two of that method's four claims died with the engine and are
     * not reproduced here: that `style_component` merges into the stored map rather than
     * evicting the dead key (it now refuses with `no_style_slots` before merging
     * anything), and that the canonical name paints once repaired (no slot paints).
     */
    public function testTheStoredMapIsReportedOnAnAcceptedEditToASiblingBand(): void
    {
        $id = pp_create_page('Aged band beside a clean one', 'draft');
        pp_update_composition($id, [
            ['component' => 'grid',
             'props'     => ['id' => 'g1', 'title' => 'T', 'items' => [['title' => 'One']]],
             'style'     => self::AGED_STYLE],
            ['component' => 'section', 'props' => ['id' => 's1', 'title' => 'Band', 'body' => 'Copy.']],
        ]);

        // An edit to the OTHER band, touching nothing about the aged one.
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Renamed band'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'the untouched band must stay editable');

        $reported = array_values(array_filter(
            $result['findings'],
            static fn (array $f): bool => ($f['type'] ?? '') === 'invalid_style_slot'
        ));
        $this->assertNotEmpty($reported, 'the stale declaration is still visible to validation');
        $this->assertStringContainsString(
            '--grid-item-bg',
            $reported[0]['message'],
            'the disclosure names the dead slot on the band the operator never touched'
        );
        $this->assertSame(0, $reported[0]['index'], 'and it names the band that owns it, not the one written');
    }

    /**
     * THE ROUTE THE MESSAGE NOW LEADS WITH. `update_component` with every KEY nulled is the
     * surgical one and the one an author can get wrong; rewriting the whole band without a
     * `style` key clears the map entire, with nothing to enumerate and nothing to miss.
     *
     * Pinned because the two routes go through different validators — the band-grain one
     * and the whole-composition one — and an aged page that could be repaired by only one
     * of them would be a trap for whichever half of the documentation the operator read.
     */
    public function testRewritingTheBandWithoutAStyleKeyAlsoClearsIt(): void
    {
        $id = $this->agedPage();

        $repaired = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [
                ['component' => 'grid', 'props' => ['id' => 'g1', 'title' => 'T', 'items' => [['title' => 'One']]]],
            ],
        ]);

        $this->assertTrue($repaired['ok'], (string) ($repaired['error'] ?? ''));
        $this->assertArrayNotHasKey('style', pp_get_composition($id)[0]);

        $after = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'A new title'],
        ]);
        $this->assertTrue($after['ok'], (string) ($after['error'] ?? ''));
        $this->assertSame([], $after['findings'], 'the page is clean once the dead map is gone');
    }

    /**
     * `__recipe` IS SKIPPED BY THE SLOT VALIDATOR, AND THAT SKIP IS LOAD-BEARING.
     *
     * Re-homed from SchemaValidationTest::testCompositionAllowsRecipeTrackingKey at #1101,
     * where it ran on the fixture's slot map. Its subject — the `__recipe` exemption at
     * lib/admin.php:1343 — is live, and deleting the test left it mutable with the whole
     * suite green.
     *
     * WHY THE SKIP MATTERS MORE NOW THAN IT DID. v1 wrote `__recipe` into the style map on
     * every recipe application, and grid shipped three recipes until earlier in #1101, so a
     * large share of aged bands carry the key. It is not a slot name and never was a CSS
     * property. Remove the skip and every recipe-applied band becomes permanently
     * uneditable through the action layer — measured below: a band storing ONLY `__recipe`
     * is editable today, and that is the whole of what the skip buys.
     */
    public function testARecipeTrackingKeyAloneDoesNotBlockTheBand(): void
    {
        $id = pp_create_page('Recipe-only band', 'draft');
        pp_update_composition($id, [[
            'component' => 'grid',
            'props'     => ['id' => 'g1', 'title' => 'T', 'items' => [['title' => 'One']]],
            'style'     => ['__recipe' => 'dark-showcase'],
        ]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'A new title'],
        ]);

        $this->assertTrue(
            $result['ok'],
            'a band whose only stored style key is `__recipe` must stay editable — the skip '
            . 'at lib/admin.php is what stops every recipe-applied v1 band being locked out'
        );
        $this->assertSame('A new title', pp_get_composition($id)[0]['props']['title']);

        // And the key is left exactly as stored: the skip is an exemption from VALIDATION,
        // not a licence to rewrite the author's bytes.
        $this->assertSame('dark-showcase', pp_get_composition($id)[0]['style']['__recipe']);
    }

    /** The validator accepts it beside a real slot too, which is the shape v1 actually wrote. */
    public function testARecipeTrackingKeyIsNotItselfReportedAsAnUndeclaredSlot(): void
    {
        $refusal = pp_execute_action('update_component', [
            'post_id'         => $this->agedPage(),
            'component_index' => 0,
            'props'           => ['title' => 'A new title'],
        ]);

        $this->assertFalse($refusal['ok'], 'the two real slots still refuse the band');

        // SCOPED TO THE REJECTED-SLOT CLAUSE, not to the whole message. The message DOES
        // mention `__recipe` now — in the repair advice, deliberately, because an author
        // clearing the map has to know about it. What must never happen is `__recipe`
        // appearing as the thing that was REJECTED, which would send them chasing an
        // undeclared slot that is not one.
        $this->assertSame(
            1,
            preg_match('/has no style slot "([^"]+)"/', $refusal['error'], $m),
            'precondition: the refusal names the slot it rejected'
        );
        $this->assertNotSame('__recipe', $m[1], 'the tracking key is never the rejected slot');
        $this->assertStringStartsWith('--grid-', $m[1], 'it is a real slot name from the stored map');
    }

    // ── 3. THE MESSAGE AND THE BEHAVIOUR, HELD TO EACH OTHER ────────────────────

    /**
     * THE REFUSAL ROUTES THE AUTHOR SOMEWHERE REAL. This is the half of the message that
     * survives the sweep by ruling (D2): the verb stays, the body behind it goes, and what
     * the author is told has to be the thing that actually works.
     */
    public function testTheRefusalNamesTheV2SurfaceAndTheComponentsOwnRoles(): void
    {
        $result = pp_execute_action('update_component', [
            'post_id'         => $this->agedPage(),
            'component_index' => 0,
            'props'           => ['title' => 'A new title'],
        ]);
        $message = $result['error'];

        $this->assertStringContainsString('`udc` map', $message, 'it names the surface that replaced slots');
        $this->assertStringContainsString('update_composition', $message, 'and the action that carries one');
        $this->assertStringContainsString(
            'card-title',
            $message,
            'and the component\'s OWN roles, derived from its schema rather than listed by hand'
        );
    }

    /**
     * THE REPAIR INSTRUCTION IS THE MEASURED ONE. Asserted against the message text
     * because a repair sentence that describes a call the engine refuses is worse than no
     * sentence: it costs the author a round trip and reads as a bug in their payload.
     */
    public function testTheRefusalTellsTheAuthorToClearEveryStoredSlotInOneCall(): void
    {
        $result = pp_execute_action('update_component', [
            'post_id'         => $this->agedPage(),
            'component_index' => 0,
            'props'           => ['title' => 'A new title'],
        ]);
        $message = $result['error'];

        $this->assertStringContainsString('EVERY STORED KEY set to null', $message);
        $this->assertStringContainsString('partial clear of the slots is refused', $message);
        $this->assertStringContainsString(
            'a props-only edit included',
            $message,
            'and it says out loud that this blocks edits that are not about styling'
        );

        // THE `__recipe` CLAUSE, which is the half an author cannot infer. It is not a slot
        // name, so "every slot" omits it, and the omission is silent: the clear succeeds and
        // leaves a v1 key in the composition.
        $this->assertStringContainsString('`__recipe` key', $message);
        $this->assertStringContainsString('not a slot name', $message);

        // AND THE ROUTE THAT CANNOT BE GOT WRONG IS OFFERED FIRST. Enumerating keys is the
        // fiddly path; rewriting the band without a `style` key clears the whole map with
        // nothing to miss, which is what an operator sweeping a page should reach for.
        $this->assertStringContainsString('update_composition', $message);
        $this->assertStringContainsString('nothing to enumerate', $message);
    }

    /**
     * THE PREVIEW SHOWS THE CLEAR BEFORE IT HAPPENS, and this is the only reachability
     * proof for a function #1101 kept against the inventory's advice.
     *
     * `_pp_diff_style()` was on the list of unreachable code to delete. It is reached here,
     * on the one route that still carries a non-empty `style` map through a write: the
     * aged-band clear. Without the preview an operator would be asked to approve a call
     * whose visible effect is nothing — the props are unchanged and the removal is the
     * whole point — so the diff is what makes the repair reviewable rather than a leap.
     *
     * Measured: two changes, each naming its slot and its `from` value, `to` null.
     */
    public function testThePreviewShowsEachSlotBeingRemoved(): void
    {
        $id      = $this->agedPage();
        $preview = pp_preview_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => [],
            'style'           => ['--grid-item-bg' => null, '--grid-gap' => null],
        ]);

        $this->assertNotInstanceOf(\WP_Error::class, $preview, 'the clear must be previewable');

        $paths = array_column($preview['changes'], 'path');
        $this->assertContains('composition[0].style.--grid-item-bg', $paths);
        $this->assertContains('composition[0].style.--grid-gap', $paths);

        foreach ($preview['changes'] as $change) {
            $this->assertNull($change['to'], 'every change is a removal');
            $this->assertNotNull(
                $change['from'],
                'and it names the value being removed — a diff that showed only the null '
                . 'would tell the operator nothing about what they are losing'
            );
        }
    }

    /**
     * THE CHAT PAYLOAD, PINNED BECAUSE THE SEAM WENT GREEN OVER A REAL DEFECT.
     *
     * The PHP suite and the JS suite were both green while the chat card told an author
     * that an aged band's refusal was IMPOSSIBLE — the exact #625 failure — because the
     * defect lived between them. PHP changed what the payload contains; the classifier in
     * assets/js/pp-ai-chat.js reads it, and nothing asserted the two agreed.
     *
     * So this pins the payload SHAPE that the JS branches on. Its counterpart lives in
     * tests/js/pp-ai-chat-proposal.test.js, which feeds these same values to
     * ppChatGetErrorStepClass() and ppChatGetStatusMessage(). Change either side and one
     * of the two goes red.
     *
     *   alternatives: []   -> the JS must NOT read this as "nothing to try"
     *   hints:        {}   -> no component declares a slot, so none can be hinted at
     *   user_message       -> describes the stored map, not a style setting the author
     *                         did not ask to change
     *   raw_error          -> carries the repair, because the status bar sends the
     *                         reader to the details for it
     */
    public function testTheChatPayloadNamesTheRepairAndOffersNoAlternatives(): void
    {
        $id     = $this->agedPage();
        $params = ['post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'A new title']];
        $result = pp_execute_action('update_component', $params);

        $this->assertSame('invalid_style_slot', $result['error_code'], 'precondition');

        $friendly = _pp_build_friendly_error(
            new \WP_Error($result['error_code'], $result['error']),
            $params
        );

        $this->assertSame([], $friendly['alternatives'], 'there is nothing to offer instead');
        $this->assertSame([], (array) $friendly['cross_component_hints'], 'and no component to point at');

        // THE VISIBLE SENTENCE. It renders unconditionally; raw_error is behind a
        // disclosure. It must describe what happened to the author who met it — who very
        // likely changed a title, as this test just did.
        $this->assertStringContainsString('"grid" band', $friendly['user_message']);
        $this->assertStringContainsString('old system', $friendly['user_message']);
        $this->assertStringNotContainsString(
            'style setting',
            $friendly['user_message'],
            'the author did not ask to change a style setting — telling them they did is the '
            . 'confident falsehood this branch was rewritten to stop telling'
        );

        // AND THE DETAILS CARRY THE ANSWER, because the status bar sends the reader there.
        $this->assertStringContainsString('EVERY STORED KEY set to null', $friendly['raw_error']);
    }

    /**
     * THE NEGATIVE CONTROL FOR THE WHOLE FILE. A band with NO stored map edits normally,
     * so none of the refusals above can be coming from "grid declares no slots" on its
     * own — they come from the stored bytes, which is the distinction the sweep turns on.
     */
    public function testABandWithNoStoredMapEditsNormally(): void
    {
        $id = pp_create_page('Fresh band', 'draft');
        pp_update_composition($id, [[
            'component' => 'grid',
            'props'     => ['id' => 'g1', 'title' => 'T', 'items' => [['title' => 'One']]],
        ]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'A new title'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('A new title', pp_get_composition($id)[0]['props']['title']);
    }

    /**
     * THE OTHER VERB, and the one ruling D2 is about: `style_component` refuses a
     * component with no slots BEFORE it looks at anything else, so the refusal names the
     * real reason rather than the first thing the payload got wrong.
     */
    public function testStyleComponentRefusesWithNoStyleSlotsWhateverThePayloadAsksFor(): void
    {
        $id = $this->agedPage();

        foreach ([
            'a slot value'     => ['style'  => ['--grid-gap' => '1rem']],
            'a recipe by name' => ['recipe' => 'dark-showcase'],
            'a made-up recipe' => ['recipe' => 'no-such-recipe'],
        ] as $label => $payload) {
            $result = pp_execute_action('style_component', array_merge(
                ['post_id' => $id, 'component_index' => 0],
                $payload
            ));

            $this->assertFalse($result['ok'], $label . ' must be refused');
            $this->assertSame(
                'no_style_slots',
                $result['error_code'],
                $label . ': the refusal names the component having no slots, never invalid_recipe —'
                . ' the slot check runs first, so the recipe lookup is never reached'
            );
        }
    }
}
