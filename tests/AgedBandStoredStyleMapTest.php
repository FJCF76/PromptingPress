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
     */
    private const AGED_STYLE = [
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
    public function testClearingEveryStoredSlotInOneCallIsAcceptedAndRemovesTheKey(): void
    {
        $id     = $this->agedPage();
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => [],
            'style'           => ['--grid-item-bg' => null, '--grid-gap' => null],
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

        $this->assertStringContainsString('EVERY stored slot name set to null', $message);
        $this->assertStringContainsString('partial clear is refused', $message);
        $this->assertStringContainsString(
            'a props-only edit included',
            $message,
            'and it says out loud that this blocks edits that are not about styling'
        );
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
