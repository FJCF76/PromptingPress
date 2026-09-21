<?php
/**
 * tests/AppliesWhenTest.php
 *
 * Issue #580 — the EVALUATOR half of the `applies_when` contract.
 *
 * ONE FIELD, TWO CONSUMERS (ruling 8). #575 landed the clause grammar and the AI
 * catalog emitter; #580 populated ~125 definitions and added a write-time warning.
 * Both consumers read the SAME `applies_when` — there is deliberately no second
 * condition table.
 *
 * ONE OF THOSE CONSUMERS IS GONE (#1101). The `inert_slot` advisory was derived from
 * this grammar over a component's STYLE SLOTS, and the style-slot engine is retired,
 * so eighteen methods here went with it. What survives is the grammar itself and the
 * consumers that read it over PROPS:
 *
 *      schema.json  applies_when
 *         │
 *         ├──► pp_ai_definition_suffix()       the AI catalog, BEFORE the write
 *         │       "applies when background_image is set"
 *         │
 *         └──► pp_applies_when_clause_met()    the `refuse_props_when` REFUSAL, which
 *                 -> inert_prop                is a v2 mechanism and is NOT retiring
 *
 * Do not read the retirement as "this grammar is on its way out". The predicate is
 * load-bearing for a live refusal (lib/admin.php:2878, :2892), and the clause-level
 * tests below are unit-level against SYNTHETIC definitions precisely so they never
 * depended on which component happened to declare a condition.
 *
 * THE GATE THIS MUST NOT TRIP. `wp pp validate site` sets $pass = false on ANY smell
 * and halts(1) (lib/cli.php). A false-positive advisory therefore reds a fresh
 * install against the theme's own seeded homepage with no authorable fix — the exact
 * trap that deferred #578's measure advisory to issue #610. Hence the fail-open
 * posture throughout, and the starter-seed pin further down this file.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

final class AppliesWhenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // NO FIXTURE THEME SINCE #1101. It was here to supply a component declaring
        // conditional STYLE SLOTS, which is what the retired half of this file needed.
        // Everything left runs against synthetic definitions or the shipped registry.
        // Reset the in-memory store for test isolation (the repo's stub-suite idiom).
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    // ── The clause evaluator ─────────────────────────────────────────────────
    //
    // Unit-level, against synthetic prop definitions, so each predicate's edges are
    // pinned independently of whatever the shipped schemas happen to declare today.

    /** @return array<string,array<string,mixed>> a minimal `props` schema map */
    private function propDefs(array $defaults = []): array
    {
        $defs = [];
        foreach ($defaults as $name => $default) {
            $defs[$name] = ['type' => 'string', 'default' => $default];
        }
        return $defs;
    }

    public function testEqualsMatchesAndMismatches(): void
    {
        $clause = ['prop' => 'layout', 'equals' => 'split'];
        $this->assertTrue(pp_applies_when_clause_met($clause, ['layout' => 'split'], [], []));
        $this->assertFalse(pp_applies_when_clause_met($clause, ['layout' => 'cover'], [], []));
    }

    /**
     * DEFAULT RESOLUTION — the single most load-bearing behaviour here. The worked
     * example was grid's `card_emphasis`, which defaulted to "featured": a grid that
     * simply omitted the prop WAS featured, and without the fallback `--grid-featured-*`
     * would have been reported inert on most grids on the internet — a false positive
     * that halts `wp pp validate site`.
     *
     * THE EXAMPLE RETIRED WITH grid's SLOT MAP AT #1101; the BEHAVIOUR did not. The
     * defaults here are synthetic on purpose (see propDefs above), so this pin never
     * depended on that prop shipping — only the prose did, and it is kept as history
     * because it is still the clearest statement of what a missing fallback costs.
     * No conditional declaration of ANY kind is left on a slot — the engine that read
     * them is retired — and the one conditional PROP that ships (footer's
     * `contact_label`) uses `present`, whose default resolution is pinned by
     * testPresentUsesTheSchemaDefaultToo below.
     */
    public function testAnAbsentPropTakesItsSchemaDefault(): void
    {
        $clause = ['prop' => 'card_emphasis', 'equals' => 'featured'];
        $defs   = $this->propDefs(['card_emphasis' => 'featured']);

        $this->assertTrue(pp_applies_when_clause_met($clause, [], $defs, []), 'absent -> default');
        $this->assertFalse(pp_applies_when_clause_met($clause, ['card_emphasis' => 'uniform'], $defs, []));
    }

    public function testInMatchesAnyMemberAndNothingElse(): void
    {
        $clause = ['prop' => 'layout', 'in' => ['image-left', 'image-right']];
        $this->assertTrue(pp_applies_when_clause_met($clause, ['layout' => 'image-left'], [], []));
        $this->assertTrue(pp_applies_when_clause_met($clause, ['layout' => 'image-right'], [], []));
        $this->assertFalse(pp_applies_when_clause_met($clause, ['layout' => 'text-only'], [], []));
    }

    /** An int prop and an int clause member compare as their string forms, both ways. */
    public function testEqualsAndInCompareIntegersByValue(): void
    {
        $this->assertTrue(pp_applies_when_clause_met(['prop' => 'columns', 'equals' => 2], ['columns' => 2], [], []));
        $this->assertTrue(pp_applies_when_clause_met(['prop' => 'columns', 'equals' => 2], ['columns' => '2'], [], []));
        $this->assertTrue(pp_applies_when_clause_met(['prop' => 'columns', 'in' => [2, 3]], ['columns' => 3], [], []));
        $this->assertFalse(pp_applies_when_clause_met(['prop' => 'columns', 'in' => [2, 3]], ['columns' => 4], [], []));
    }

    /**
     * `present` is the ONE predicate where absence means NOT met — "the author never set
     * `eyebrow`, so the six eyebrow slots render nothing" is the whole point of the field.
     */
    public function testPresentIsNonEmptyStringOrNonEmptyArray(): void
    {
        $clause = ['prop' => 'eyebrow', 'present' => true];

        $this->assertTrue(pp_applies_when_clause_met($clause, ['eyebrow' => 'New'], [], []));
        $this->assertTrue(pp_applies_when_clause_met($clause, ['eyebrow' => '0'], [], []), '"0" is a real value, not emptiness');
        $this->assertTrue(
            pp_applies_when_clause_met($clause, ['eyebrow' => '   '], [], []),
            'renderer parity: `if ($eyebrow)` on a whitespace string is TRUE and emits a visible pill, '
            . 'so trimming here would report six slots inert on a band whose eyebrow is on screen'
        );
        $this->assertTrue(pp_applies_when_clause_met(['prop' => 'items', 'present' => true], ['items' => [['a']]], [], []));

        $this->assertFalse(pp_applies_when_clause_met($clause, ['eyebrow' => ''], [], []));
        $this->assertFalse(pp_applies_when_clause_met($clause, [], [], []), 'absent is not present');
        $this->assertFalse(pp_applies_when_clause_met(['prop' => 'items', 'present' => true], ['items' => []], [], []));
    }

    /**
     * `present` has no reading for a bool or an int, so a prop the author DID set to one
     * fails OPEN. The alternative is the worst class of false positive: telling an author
     * "applies when show_logo is set" about a prop they just set to `true` — visibly wrong
     * advice, on a channel that halts `wp pp validate site`.
     */
    public function testPresentFailsOpenOnAValueItCannotRead(): void
    {
        $this->assertTrue(pp_applies_when_clause_met(['prop' => 'show_logo', 'present' => true], ['show_logo' => true], [], []));
        $this->assertTrue(pp_applies_when_clause_met(['prop' => 'show_logo', 'present' => true], ['show_logo' => false], [], []));
        $this->assertTrue(pp_applies_when_clause_met(['prop' => 'columns', 'present' => true], ['columns' => 3], [], []));
        $this->assertTrue(pp_applies_when_clause_met(['prop' => 'columns', 'present' => true], ['columns' => 0], [], []));
        $this->assertFalse(
            pp_applies_when_clause_met(['prop' => 'columns', 'present' => true], ['columns' => null], [], []),
            'explicit null is absence, not an unreadable value'
        );
    }

    /** A prop whose schema default is the empty string is still "not present" when absent. */
    public function testPresentUsesTheSchemaDefaultToo(): void
    {
        $defs = $this->propDefs(['eyebrow' => '', 'proof' => 'seeded']);
        $this->assertFalse(pp_applies_when_clause_met(['prop' => 'eyebrow', 'present' => true], [], $defs, []));
        $this->assertTrue(pp_applies_when_clause_met(['prop' => 'proof', 'present' => true], [], $defs, []));
    }


    // ── Fail-open: every ambiguity resolves to "applies" (stay silent) ───────

    /**
     * A clause the GRAMMAR rejects must not produce a warning. The definition surface is
     * a repo-CI invariant, not a runtime gate (pp_slot_definition_keys), so a hand-edited
     * schema on a live install can carry one — and inventing a condition from a broken
     * declaration would red `validate site` with a message the operator cannot act on.
     */
    public function testAnUngrammaticalClauseFailsOpen(): void
    {
        foreach ([
            ['any_of' => ['a', 'b']],                              // a fifth clause form
            ['prop' => 'x'],                                       // no predicate
            ['prop' => 'x', 'equals' => 'a', 'in' => ['b']],       // two predicates
            ['prop' => 'x', 'slot' => '--y', 'present' => true],   // two subjects
            ['prop' => 'x', 'present' => false],                   // the negated form
            'image_treatment = icon',                              // not an object
        ] as $bad) {
            $this->assertTrue(
                pp_applies_when_clause_met($bad, [], [], []),
                'an unevaluable clause must never fabricate a warning'
            );
        }
    }

    /** `equals`/`in` against a value with no defined comparison (bool, array, null). */
    public function testAnIncomparableValueFailsOpen(): void
    {
        $clause = ['prop' => 'show_logo', 'equals' => 'true'];
        $this->assertTrue(pp_applies_when_clause_met($clause, ['show_logo' => true], [], []));
        $this->assertTrue(pp_applies_when_clause_met($clause, ['show_logo' => ['a']], [], []));
        $this->assertTrue(pp_applies_when_clause_met($clause, [], [], []), 'no value and no default');
    }

    // ── pp_applies_when_unmet_clauses ────────────────────────────────────────


    // ── The inert_slot advisory ──────────────────────────────────────────────


    /**
     * THE MULTI-CLAUSE ADVISORY TEST RETIRED AT #1101, and this is its replacement.
     *
     * (1) WHAT IT PROVED. A slot whose `applies_when` lists TWO clauses, both unmet,
     *     produces exactly ONE advisory naming BOTH — not one warning per clause, and
     *     not one warning naming only the first miss. It drove that through
     *     `grid.--grid-featured-shadow` ("layout = cards" AND "card_emphasis =
     *     featured"), a band satisfying neither.
     *
     * (2) WHY THE SUBJECT IS GONE. It had already been re-homed twice — hero's
     *     equivalent left at #986, cta's two-button family at #1026 — and the note it
     *     carried said plainly that grid was the only component whose conditional slots
     *     spanned both the one-clause and the two-clause shape. grid's whole slot map
     *     retired with its v2 rebuild, and the census below is the measurement: NO
     *     DEFINITION ANYWHERE — no shipped prop, no shipped slot, not even the
     *     slot-engine fixture — DECLARES MORE THAN ONE `applies_when` CLAUSE. Driving
     *     this through the advisory now would require inventing a fixture slot that
     *     does not ship, which is the vacuous-pin shape this suite exists to refuse.
     *
     * (3) WHERE THE CLAIM WENT. It splits in two, and both halves are pinned on real
     *     code rather than on a schema that would have to be invented:
     *       - the LISTER still reports every failing clause in declaration order, on a
     *         synthetic two-clause list — testUnmetReportsEveryFailingClauseInDeclarationOrder
     *         above, unchanged and still green; and
     *       - the CONJUNCTION RENDERING is pinned directly on pp_ai_definition_suffix()
     *         below, which joins clauses with " AND " through the same
     *         pp_ai_format_applies_when_clause() the advisory calls per clause.
     *     What has NO pin left is the guardrails-side `implode(' AND ', $phrases)` that
     *     glues the advisory's own sentence together. Stated rather than papered over:
     *     that line is unreachable until some schema declares a second clause, and the
     *     census below fails the moment one does — which is the moment to restore the
     *     end-to-end test.
     *
     * (4) THE PIN. The census is the retirement's guard. It is deliberately a MAXIMUM
     *     assertion, not a "zero multi-clause slots" assertion, so it reads as what it
     *     is: a statement about today's surface that a single new clause list breaks.
     */
    public function testNoDefinitionDeclaresATwoClauseConditionAnyMoreAndTheConjunctionStillRenders(): void
    {
        $counts = [];
        foreach (pp_get_registered_components() as $component => $schema) {
            $definitions = array_merge(
                array_values($schema['props'] ?? []),
                array_values($schema['styling']['style_slots'] ?? [])
            );
            foreach ($definitions as $definition) {
                if (!is_array($definition) || empty($definition['applies_when']) || !is_array($definition['applies_when'])) {
                    continue;
                }
                $counts[] = count($definition['applies_when']);
            }
        }

        // NOT VACUOUS: the census has to find conditional definitions at all, or a
        // registry that declared none would satisfy the maximum below trivially.
        $this->assertNotSame([], $counts, 'no definition anywhere declares applies_when — the advisory has no subject at all');
        $this->assertSame(
            1,
            max($counts),
            'A definition now declares more than one applies_when clause. The end-to-end multi-clause '
            . 'advisory test retired at #1101 because no such definition existed; restore it against '
            . 'this one — the guardrails-side clause join has had no coverage since.'
        );

        // The conjunction RENDERING, pinned where it is still reachable. This is the
        // same joiner the advisory relies on, one layer up from it.
        $this->assertSame(
            '; applies when layout = "cards" AND card_emphasis = "featured"',
            pp_ai_definition_suffix(['applies_when' => [
                ['prop' => 'layout', 'equals' => 'cards'],
                ['prop' => 'card_emphasis', 'equals' => 'featured'],
            ]]),
            'the two clauses render as ONE condition, ANDed — two "applies when" phrases would read '
            . 'as two competing conditions'
        );
    }


    // RETIRED (#1026) — AND THE RETIREMENT CARRIES A DISCLOSURE, not just a re-home.
    //
    // The `transparent_fill` advisory (#579) warns that a colour slot marked `role: "fill"`
    // was set to `transparent` / `currentColor`, which validates and stores but paints a
    // button no one can see. It recognises a fill by that DECLARED marker, deliberately, and
    // not by a `-bg` name convention. cta's four button fills were the last slots in the
    // theme that declared it — hero's left at #986 and `--section-panel-cta-bg` at #1023 —
    // so as of this change NO shipped slot declares `role: "fill"` and the advisory has no
    // reachable subject. These tests could only be kept by inventing a fixture slot that
    // does not ship, which is the vacuous-pin shape this suite exists to refuse.
    //
    // WHAT THIS MEANS, stated plainly because it is a real gap rather than a tidy move: the
    // v2 equivalent — a role's `background.fill: "transparent"` — is accepted with no
    // advisory at all. The marker, the engine and `pp_slot_roles()` all remain, so a v1
    // component could still declare it and the advisory would fire; what has no successor is
    // the WARNING on the v2 surface that replaced the slots. Filed rather than fixed here:
    // adding an advisory to the UDC engine is a change to the shared engine's finding
    // vocabulary, which is not cta's rebuild to make.


    // ── The authoring path (Section 14.1) and restore (#233) ────────────────


    // ── The `wp pp validate site` gate (the #610 failure mode) ───────────────


    /**
     * And nothing else the seed does regressed into a smell either. Deliberately broader
     * than — and therefore redundant with — the pin above: that one is kept because its
     * failure message explains WHY a fresh install would exit 1, which is the thing a
     * future author needs at the moment the assertion breaks.
     */
    public function testTheShippedStarterHomepageIsSmellFreeEndToEnd(): void
    {
        $this->assertSame([], pp_validate_composition_smells(pp_default_homepage_composition()));
    }

    // ── A-17 part 3: the populated values reach the runtime AI catalog ───────


    /**
     * RETIREMENT NOTE (#1023), replacing the `in`-set assertion the test above carried.
     *
     * Section's `--section-*` slots were the only `in` clauses in any LIVE slot catalog,
     * and the v2 rebuild retired the whole map. Asserting the rendering against a catalog
     * that no longer has a producer would have been a vacuous pass, so the claim splits in
     * two and both halves are proven against real surface:
     *
     *   1. the emitter still renders an `in` set, pinned directly on the renderer; and
     *   2. the `in` OPERATOR still has a live declaring surface — it moved from
     *      `applies_when` to section's `refuse_props_when`, which reuses this same clause
     *      grammar (#1011). So the grammar is still exercised by shipped schema, just on
     *      the write-refusal channel rather than the advisory one.
     *
     * The pair fails if either half rots: a renderer regression, or the last `in` clause
     * leaving the shipped schemas entirely.
     */
    public function testTheInSetRenderingAndItsLiveDeclaringSurfaceBothSurvivedTheV2Rebuild(): void
    {
        $this->assertSame(
            'layout is one of "image-left", "image-right"',
            pp_ai_format_applies_when_clause(
                ['prop' => 'layout', 'in' => ['image-left', 'image-right']]
            ),
            'the `in` renderer is the half that has no live catalog producer left'
        );

        $clauses = [];
        foreach (pp_get_registered_components() as $name => $schema) {
            foreach (($schema['refuse_props_when'] ?? []) as $rule) {
                foreach (($rule['when'] ?? []) as $clause) {
                    if (is_array($clause) && array_key_exists('in', $clause)) {
                        $clauses[] = $name;
                    }
                }
            }
        }

        $this->assertNotSame([], $clauses, 'no shipped schema declares an `in` clause any more');
        $this->assertContains('section', $clauses, 'section is where the `in` operator now lives');
    }

    /**
     * RETIREMENT NOTE (#1101), replacing the clause-list-AND-note assertion the catalog
     * test above carried, and modelled on the `in`-set split directly above it.
     *
     * (1) WHAT IT PROVED. A definition declaring BOTH `applies_when` clauses and a
     *     `conditionality_note` renders them as ONE condition — "applies when A AND B AND
     *     <the prose>" — rather than two competing "applies when" phrases. It read that
     *     off the live prompt, through grid's featured slot, whose two clauses were joined
     *     to the note "the component sits at the top level".
     *
     * (2) WHY THE SUBJECT IS GONE. grid's slot map retired with its v2 rebuild, and it
     *     held the theme's only definition declaring both fields. The census below is the
     *     measurement: every surviving `applies_when` is note-free (footer's
     *     `contact_label` is the only one left) and every surviving note is clause-free (logos,
     *     nav, footer). Reading the conjunction off the live prompt now would assert
     *     against a string no producer emits.
     *
     * (3) WHERE THE CLAIM WENT. Here, split the same way the `in` set was: the RENDERER is
     *     pinned directly, and the census pins the reason it has to be. The two populations
     *     are asserted non-empty separately, so "nothing declares both" can never be
     *     satisfied by a registry that declares neither.
     *
     * (4) THE PIN. The disjointness assertion fails the moment one definition declares both
     *     again — which is the moment to put the end-to-end prompt assertion back.
     */
    public function testTheClauseAndNoteConjunctionStillRendersThoughNothingDeclaresBoth(): void
    {
        $with_clauses = [];
        $with_notes   = [];
        $with_both    = [];
        foreach (pp_get_registered_components() as $component => $schema) {
            $definitions = ($schema['props'] ?? []) + ($schema['styling']['style_slots'] ?? []);
            foreach ($definitions as $key => $definition) {
                if (!is_array($definition)) {
                    continue;
                }
                $has_clauses = !empty($definition['applies_when']) && is_array($definition['applies_when']);
                $has_note    = !empty($definition['conditionality_note']) && is_string($definition['conditionality_note']);
                if ($has_clauses) {
                    $with_clauses[] = "{$component}.{$key}";
                }
                if ($has_note) {
                    $with_notes[] = "{$component}.{$key}";
                }
                if ($has_clauses && $has_note) {
                    $with_both[] = "{$component}.{$key}";
                }
            }
        }

        // Both populations are alive — the disjointness below is a real separation, not
        // the emptiness of a registry that stopped declaring conditions at all.
        $this->assertNotSame([], $with_clauses, 'no definition declares applies_when any more');
        $this->assertNotSame([], $with_notes, 'no definition declares conditionality_note any more');
        $this->assertSame(
            [],
            $with_both,
            'A definition declares applies_when AND conditionality_note again. The end-to-end prompt '
            . 'assertion retired at #1101 because none did; restore it against this definition — the '
            . 'catalog must render ONE ANDed condition, never two "applies when" phrases.'
        );

        // The renderer, pinned where it is still reachable: one clause, one note, one
        // condition. The note rides in VERBATIM (bar its trailing period) and behind the
        // same single "applies when" prefix the clause got.
        $this->assertSame(
            '; applies when background_image is set AND the component sits at the top level',
            pp_ai_definition_suffix([
                'applies_when'        => [['prop' => 'background_image', 'present' => true]],
                'conditionality_note' => 'the component sits at the top level.',
            ])
        );
    }

    /**
     * The nav/footer chrome preconditions are the ONE populated set the chat catalog does
     * not carry — and that is a pre-existing, deliberate boundary, not a #580 regression:
     * pp_ai_system_prompt() lists pp_composable_components() only, because listing
     * template-owned chrome is what led an agent to compose duplicate chrome (#223). They
     * reach an agent through schema.json and ai-instructions instead. Pinned so the
     * emitter is proven to render them the moment any surface does list chrome props.
     */
    public function testTheChromePreconditionsRenderEvenThoughTheChatCatalogOmitsChrome(): void
    {
        $footer = pp_get_registered_components()['footer']['props'];

        $this->assertSame(
            '; applies when contact is set',
            pp_ai_definition_suffix($footer['contact_label'])
        );
        $this->assertStringContainsString(
            'applies when show_logo is true',
            pp_ai_definition_suffix($footer['logo_id'])
        );
        $this->assertArrayNotHasKey(
            'footer',
            pp_composable_components(),
            'chrome stays out of the composable catalog (#223) — that is why these notes ride the schema'
        );
    }

}
