<?php
/**
 * tests/AppliesWhenTest.php
 *
 * Issue #580 — the EVALUATOR half of the `applies_when` contract, and the
 * `inert_slot` advisory derived from it.
 *
 * ONE FIELD, TWO CONSUMERS (ruling 8). #575 landed the clause grammar and the AI
 * catalog emitter; #580 populates ~125 definitions and adds the write-time warning.
 * Both consumers read the SAME `applies_when` — there is deliberately no second
 * condition table — so these tests exercise the field from both ends:
 *
 *      schema.json  applies_when
 *         │
 *         ├──► pp_ai_definition_suffix()          BEFORE the write (the catalog)
 *         │       "applies when background_image is set"
 *         │
 *         └──► pp_applies_when_clause_met()       AFTER the write (the advisory)
 *                 -> pp_validate_composition_smells() -> inert_slot
 *
 * THE GATE THIS MUST NOT TRIP. `wp pp validate site` sets $pass = false on ANY smell
 * and halts(1) (lib/cli.php). A false-positive advisory therefore reds a fresh
 * install against the theme's own seeded homepage with no authorable fix — the exact
 * trap that deferred #578's measure advisory to issue #610. Hence the fail-open
 * posture throughout, and the starter-seed pin at the bottom of this file.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;
use PromptingPress\Tests\Support\FixtureTheme;

final class AppliesWhenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // THE BAND HERE IS A FIXTURE, NOT A SUBJECT (#1025). The mechanism under test
        // is the slot engine; which component carries the slots is incidental, which is
        // why this whole set was re-homed hero -> section -> stats over three rebuilds.
        // It targets `ppfixture` now, so stats' rebuild is the last one that moved it.
        FixtureTheme::activate();
        // Reset the in-memory store for test isolation (the repo's stub-suite idiom).
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    protected function tearDown(): void
    {
        FixtureTheme::deactivate();
        parent::tearDown();
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
     * Every conditional slot left in the theme (ppfixture's nine) uses `present`, whose
     * default resolution is pinned by testPresentUsesTheSchemaDefaultToo below.
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

    /**
     * The sibling-slot form reads the authored STYLE map, not the props.
     *
     * THE SLOT NAME MOVED FROM `--grid-item-bar-color` TO `--ppfixture-bg` AT #1101,
     * and nothing else changed: this predicate never looks a slot up in any schema, it
     * only asks whether the key carries a scalar value in the map it was handed. The
     * old name was grid's, and grid's slot map retired with its v2 rebuild — a dead
     * name here would still have passed, which is precisely why it is worth replacing
     * with a live one rather than leaving as a fossil a reader has to decode.
     */
    public function testSlotPresentReadsTheStyleMap(): void
    {
        $clause = ['slot' => '--ppfixture-bg', 'present' => true];

        $this->assertTrue(pp_applies_when_clause_met($clause, [], [], ['--ppfixture-bg' => '#f00']));
        $this->assertFalse(pp_applies_when_clause_met($clause, [], [], []));
        $this->assertFalse(pp_applies_when_clause_met($clause, [], [], ['--ppfixture-bg' => '']));
        $this->assertFalse(
            pp_applies_when_clause_met($clause, [], [], ['--ppfixture-bg' => ['not', 'scalar']]),
            'a non-scalar slot value is not a set value'
        );
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

    public function testUnmetReportsEveryFailingClauseInDeclarationOrder(): void
    {
        $clauses = [
            ['prop' => 'layout', 'equals' => 'split'],
            ['prop' => 'proof', 'present' => true],
        ];

        $this->assertSame(
            [],
            pp_applies_when_unmet_clauses($clauses, 'section', ['layout' => 'split', 'proof' => 'x'], [])
        );
        $this->assertSame(
            [$clauses[1]],
            pp_applies_when_unmet_clauses($clauses, 'section', ['layout' => 'split'], [])
        );
        $this->assertSame(
            $clauses,
            pp_applies_when_unmet_clauses($clauses, 'section', [], []),
            'both misses are reported: naming only the first sends the author to fix the wrong thing'
        );
    }

    /** The component name resolves real schema defaults, not synthetic ones. */
    public function testUnmetResolvesDefaultsFromTheNamedComponentSchema(): void
    {
        $clauses = [['prop' => 'layout', 'equals' => 'cards']];
        $this->assertSame([], pp_applies_when_unmet_clauses($clauses, 'grid', [], []), 'grid layout defaults to cards');
        $this->assertSame($clauses, pp_applies_when_unmet_clauses($clauses, 'grid', ['layout' => 'steps'], []));
    }

    public function testUnmetIgnoresAMalformedClauseList(): void
    {
        $this->assertSame([], pp_applies_when_unmet_clauses([], 'section', [], []));
        $this->assertSame([], pp_applies_when_unmet_clauses(['a' => ['prop' => 'x', 'present' => true]], 'section', [], []));
    }

    // ── The inert_slot advisory ──────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> the inert_slot entries of a smell run */
    private function inertSmells(array $composition): array
    {
        return array_values(array_filter(
            pp_validate_composition_smells($composition),
            static fn ($s) => $s['type'] === 'inert_slot'
        ));
    }

    public function testAnInertSlotWarnsAndNamesTheUnmetCondition(): void
    {
        // Re-homed from section to stats in #1023: section's slot map left with the v2
        // rebuild. `--ppfixture-overlay-bg` carries the identical clause shape section's
        // `--section-overlay-bg` did (`background_image is set`), so the pin is unchanged.
        $smells = $this->inertSmells([
            ['component' => 'ppfixture', 'props' => ['id' => 'h', 'items' => [['number' => '10', 'label' => 'Sites']]],
             'style' => ['--ppfixture-overlay-bg' => '#fff']],
        ]);

        $this->assertCount(1, $smells);
        $this->assertSame('h', $smells[0]['id']);
        $this->assertSame(0, $smells[0]['index']);
        $this->assertStringContainsString('--ppfixture-overlay-bg', $smells[0]['message']);
        $this->assertStringContainsString('applies when background_image is set', $smells[0]['message']);
    }

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

    /** ...and one warning PER SLOT, so a band that defeats six slots reports six. */
    public function testEveryInertSlotOnOneComponentGetsItsOwnWarning(): void
    {
        // Re-homed to stats (#1023). Two slots, one unmet clause each: both heading slots
        // condition on `title is set`, and this band has no title.
        $smells = $this->inertSmells([
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '10', 'label' => 'Sites']]],
             'style' => ['--ppfixture-heading-size' => '2rem', '--ppfixture-heading-color' => '#fff']],
        ]);

        $this->assertCount(2, $smells, 'one warning per SLOT, not one per component');
        $this->assertStringContainsString('--ppfixture-heading-size', $smells[0]['message']);
        $this->assertStringContainsString('--ppfixture-heading-color', $smells[1]['message']);
    }

    /**
     * The warning points at the component the author has to edit. `index` is the ONLY
     * thing that identifies it on an id-less band, so an off-by-one here sends every
     * advisory on a ten-band page to band zero.
     */
    public function testTheWarningNamesTheComponentItCameFrom(): void
    {
        $smells = $this->inertSmells([
            ['component' => 'section', 'props' => ['title' => 'A', 'body' => '<p>x</p>']],
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '10', 'label' => 'Sites']]],
             'style' => ['--ppfixture-overlay-bg' => '#fff']],
        ]);

        $this->assertCount(1, $smells);
        $this->assertSame(1, $smells[0]['index']);
    }

    public function testAMetConditionIsSilent(): void
    {
        $this->assertSame([], $this->inertSmells([
            // The condition on `--ppfixture-overlay-bg` is `background_image is set`, so
            // setting it is what makes the advisory silent.
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '10', 'label' => 'Sites']],
                                                 'background_image' => 'https://example.com/bg.png'],
             'style' => ['--ppfixture-overlay-bg' => '#fff']],
        ]));
    }

    public function testASlotWithNoAppliesWhenIsSilent(): void
    {
        $this->assertSame([], $this->inertSmells([
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '10', 'label' => 'Sites']]], 'style' => ['--ppfixture-bg' => '#101010']],
        ]));
    }

    /**
     * THE PROSE-ONLY SILENCE TEST RETIRED AT #1101, after a third re-home would have been
     * its third vacuous pass in three rebuilds.
     *
     * (1) WHAT IT PROVED. A slot carrying a `conditionality_note` — prose the four-form
     *     clause grammar cannot express (disjunction, composed-page `main >` scope,
     *     interaction state, an item-level condition) — produces NO `inert_slot`
     *     advisory. That is the KNOWN BOUND, not an oversight: such a condition is
     *     unevaluable by construction, so guessing at it after the write would put
     *     unactionable text on the channel that halts `wp pp validate site`. It reaches
     *     the author through the AI catalog BEFORE the write instead.
     *
     * (2) WHY THE SUBJECT IS GONE. This test has a history of passing for the wrong
     *     reason, because an UNDECLARED slot name raises no advisory whatever its
     *     condition — the same `[]` an honest silence returns. #1023 pointed it at faq's
     *     `--faq-question-open-color`; #1046 retired faq's slots and re-pointed it at
     *     grid's `--grid-item-icon-size`, whose unevaluable half was "at least one item
     *     declares an image_url". grid's slot map retired at #1101, and the census below
     *     is the measurement: NO STYLE SLOT ANYWHERE — shipped or fixture — DECLARES
     *     `conditionality_note` at all. The advisory channel has no prose-bearing
     *     subject left, so the silence is unreachable rather than merely unexercised.
     *
     * (3) WHERE THE CLAIM WENT. The prose classes did NOT retire with the slots — they
     *     moved wholesale to PROPS (logos' item-level height cap; nav's and footer's
     *     negations and WordPress-state preconditions). The advisory only ever read
     *     style slots, so on today's surface the whole prose population reaches the
     *     author exclusively through the catalog, which is what the second and third
     *     assertions pin. Stated rather than papered over: if a v2-era component ever
     *     declares a prose-conditional SLOT again, the silence is reachable once more
     *     and the first assertion here fails, which is the moment to restore the
     *     end-to-end test.
     */
    public function testNoStyleSlotCarriesProseConditionalityAnyMoreAndThePropSurfaceCarriesItInstead(): void
    {
        $slots_with_prose = [];
        $slots_seen       = 0;
        $props_with_prose = [];
        foreach (pp_get_registered_components() as $component => $schema) {
            foreach (($schema['styling']['style_slots'] ?? []) as $slot => $definition) {
                $slots_seen++;
                if (is_array($definition) && !empty($definition['conditionality_note'])) {
                    $slots_with_prose[] = "{$component}{$slot}";
                }
            }
            foreach (($schema['props'] ?? []) as $prop => $definition) {
                if (is_array($definition) && !empty($definition['conditionality_note'])) {
                    $props_with_prose[] = "{$component}.{$prop}";
                }
            }
        }

        // NOT VACUOUS: a registry with no style slots left at all would satisfy the
        // emptiness below for the wrong reason, so the walk has to have seen slots.
        $this->assertGreaterThan(0, $slots_seen, 'no style slots remain to inspect — this census proves nothing');
        $this->assertSame(
            [],
            $slots_with_prose,
            'A style slot declares conditionality_note again. The prose-silence test retired at #1101 '
            . 'because no slot did; restore it against this slot — the advisory must stay SILENT on it.'
        );

        // The prose classes themselves are alive, on the prop surface.
        $this->assertNotSame([], $props_with_prose, 'the prose conditionality classes have left the theme entirely');
        $this->assertContains('logos.items', $props_with_prose, 'the item-level class is the one grid used to carry');

        // And the catalog — the channel prose was always meant to travel on — renders it
        // verbatim, with no fabricated evaluable clause in front of it.
        $suffix = pp_ai_definition_suffix(pp_get_registered_components()['logos']['props']['items']);
        $this->assertStringContainsString('applies when the height cap applied to a logo image', $suffix);
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

    /**
     * ONE warning per PAINTED declaration, in BOTH stored key orders.
     *
     * This used to pin the canonical-wins arbitration between a legacy name and its
     * twin. #603 removed that arbitration along with the slot-alias surface, so what
     * is left to pin is simpler and still real: the advisory walks the stored map but
     * reports only what the renderer will emit. A retired name sitting beside a
     * declared one contributes nothing — not a second warning, and not a warning under
     * its own name — regardless of which key JSON happened to store first.
     */
    public function testOnlyThePaintedDeclarationWarnsRegardlessOfStoredKeyOrder(): void
    {
        // RE-HOMED FROM `grid` TO `ppfixture` AT #1101, and this is the SECOND retarget:
        // it went testimonials -> grid at #1026 for exactly the reason it now leaves grid.
        // grid was rebuilt on the Universal Design Contract and declares no style slots at
        // all, so BOTH names in each pair below would now be undeclared and every case
        // would pass VACUOUSLY — an undeclared name raises no inert advisory, which is the
        // wrong reason for a green test. `ppfixture` is the registered test-only component
        // that keeps a slot map across rebuilds precisely so the slot-engine suites do not
        // have to chase the last shipped v1 component (#1025), and it carries the shape
        // this fixture needs: `--ppfixture-overlay-bg` is gated on `background_image is
        // set`, so a band with no background image leaves it declared-but-unmet, which is
        // exactly the state this advisory reports. `--ppfixture-scrim-bg` stands in for the
        // retired twin — a name no schema declares, which is all the arbitration needs.
        // The fixture dies with the slot engine itself, in this task's PR2.
        $props = ['items' => [['number' => '10', 'label' => 'Sites']]];

        foreach ([
            ['--ppfixture-scrim-bg' => '#fff', '--ppfixture-overlay-bg' => '#eee'],
            ['--ppfixture-overlay-bg' => '#eee', '--ppfixture-scrim-bg' => '#fff'],
        ] as $style) {
            $smells = $this->inertSmells([
                ['component' => 'ppfixture', 'props' => $props, 'style' => $style],
            ]);

            $this->assertCount(1, $smells, 'one painted declaration, one warning');
            $this->assertStringContainsString(
                '--ppfixture-overlay-bg',
                $smells[0]['message'],
                'the declared slot is the one that paints, whichever key was stored first'
            );
            $this->assertStringNotContainsString(
                '--ppfixture-scrim-bg',
                $smells[0]['message'],
                'the retired name is undeclared: it paints nothing and is named nowhere'
            );
        }
    }

    /**
     * A declaration the RENDERER drops is not a declaration. An empty value, an undeclared
     * slot name, and a value the #330 render boundary rejects all paint nothing already —
     * reporting them as "no effect as configured" would be true for the wrong reason and
     * would put a stale no-op entry on a channel that halts `wp pp validate site`.
     *
     * Resolved through pp_style_declaration_renders(), the same predicate
     * pp_render_style_vars() consults, so "will this paint?" keeps ONE answer.
     */
    public function testADeclarationThatCannotPaintIsNotReportedInert(): void
    {
        // RE-HOMED FROM `grid` TO `ppfixture` AT #1101. grid was the LAST shipped
        // component declaring style slots, and its v2 rebuild retired every one of them —
        // so all three rows below would have collapsed into the SECOND row ("undeclared
        // slot") and passed for one reason instead of three. `ppfixture` is the registered
        // test-only slot host (#1025) and dies with the slot engine in this task's PR2.
        // This band sets neither `title` nor `background_image`, so both conditional slots
        // used here are genuinely unmet: each row is silent because the RENDERER drops the
        // declaration, not because the condition happens to hold.
        $props = ['items' => [['number' => '10', 'label' => 'Sites']]];

        foreach ([
            'empty value'        => ['--ppfixture-overlay-bg' => ''],
            'undeclared slot'    => ['--ppfixture-not-a-slot' => '#fff'],
            'rejected by render' => ['--ppfixture-heading-size' => 'not-a-length'],
        ] as $label => $style) {
            $this->assertSame(
                [],
                $this->inertSmells([
                    ['component' => 'ppfixture', 'props' => $props, 'style' => $style],
                ]),
                "{$label}: the renderer drops this declaration, so the advisory must not report it"
            );
        }

        // POSITIVE CONTROL, added with the re-home. Without it the three silences above
        // could all be the silence of a band that simply has nothing inert on it, which is
        // the failure mode that sent this suite's prose-condition test through two vacuous
        // re-homes. The SAME slot on the SAME band, with a value the renderer accepts, is
        // reported — so each row above is measuring the renderer gate and nothing else.
        $painting = $this->inertSmells([
            ['component' => 'ppfixture', 'props' => $props, 'style' => ['--ppfixture-overlay-bg' => '#fff']],
        ]);
        $this->assertCount(1, $painting, 'the same slot, painting, IS reported — the silences above are the gate, not the band');
        $this->assertStringContainsString('--ppfixture-overlay-bg', $painting[0]['message']);
    }

    /**
     * A RETIRED legacy slot name raises no inert advisory (#603). The two alias-path
     * cases that stood here were retired with the alias surface itself — one of them
     * said so in its own fixture assumption.
     *
     * The advisory reports a DECLARED slot whose `applies_when` is unmet. A name no
     * schema declares has no `applies_when` to be unmet, and the renderer drops it, so
     * "has no effect as configured" would be true for the wrong reason. The dead
     * declaration reaches the operator on the error channel instead.
     */
    public function testARetiredLegacySlotNameRaisesNoInertAdvisory(): void
    {
        // RE-HOMED FROM `grid` TO `ppfixture` AT #1101. The shape this test needs is a
        // component that DOES declare slots, carrying a name that is not one of them —
        // "declared neighbours, dead key". grid's v2 rebuild left it with no slot map at
        // all, which turns the case into "a component with no slot surface rejects every
        // name", a different and weaker claim. `ppfixture` keeps the slot map (#1025) and
        // dies with the slot engine in this task's PR2.
        $items = [
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '10', 'label' => 'Sites']]],
             'style' => ['--ppfixture-scrim-bg' => '#ffffff']],
        ];

        $this->assertSame([], $this->inertSmells($items));

        $errors = pp_validate_composition_errors($items);
        $this->assertNotSame([], $errors, 'the dead slot is an error, not a silent no-op');
        $this->assertStringContainsString(
            '--ppfixture-scrim-bg',
            implode(' | ', array_map(static fn ($e) => $e->get_error_message(), $errors)),
            'reported somewhere in the findings, not necessarily first'
        );
    }

    /**
     * The sibling-slot clause form is answered against the CANONICAL view of the style
     * map. Resolving aliases per lookup instead would answer "unset" for a stored legacy
     * twin and warn about a slot the author did set.
     */
    public function testTheSiblingSlotFormSeesTheWholeCanonicalStyleMap(): void
    {
        // Slot name and host both moved to `ppfixture` at #1101, for the reason given on
        // testSlotPresentReadsTheStyleMap above: grid's slot map retired with its v2
        // rebuild, and a dead name here would have gone on passing.
        $clauses = [['slot' => '--ppfixture-bg', 'present' => true]];
        $this->assertSame(
            [],
            pp_applies_when_unmet_clauses($clauses, 'ppfixture', [], ['--ppfixture-bg' => '#f00'])
        );
        $this->assertSame($clauses, pp_applies_when_unmet_clauses($clauses, 'ppfixture', [], []));
    }

    /**
     * Corrupt rows must not fatal. restore_composition runs these smells over arbitrary
     * history-ring snapshots (#233), and `wp pp validate site` runs them over whatever is
     * in the database — a fatal there is a broken command, not a warning.
     */
    public function testMalformedRowsAreSkippedWithoutFataling(): void
    {
        $smells = pp_validate_composition_smells([
            // 0: `layout` is an array — no defined comparison, so the clause fails open.
            ['component' => 'section', 'props' => ['background_image' => ['not', 'scalar'], 'body' => 'p'], 'style' => ['--section-overlay-bg' => '#fff']],
            // 1: a non-scalar SLOT value is skipped before any condition is read.
            ['component' => 'section', 'props' => ['title' => 'T', 'body' => '<p>x</p>'], 'style' => ['--section-overlay-bg' => ['array']]],
            // 2: a non-array style map.
            ['component' => 'section', 'props' => ['title' => 'T', 'body' => '<p>x</p>'], 'style' => 'not-an-array'],
            // 3: a non-array props bag.
            ['component' => 'section', 'props' => 'not-an-array', 'style' => ['--section-bg' => '#000']],
            // 4: an INT-keyed style entry (a raw-meta write or a history-ring snapshot can
            // carry a JSON array here, which PHP decodes to integer keys). Pins the SHAPE,
            // not the guard: without declare(strict_types) an int key coerces cleanly into
            // pp_style_declaration_renders(string $name, ...) and is dropped as undeclared
            // either way, so the is_string guard on the painted-style walk stays defensive
            // rather than load-bearing. What this row asserts is that such a map neither
            // fatals nor produces a spurious advisory.
            ['component' => 'section', 'props' => ['title' => 'T', 'body' => '<p>x</p>'], 'style' => ['#fff', '--section-bg' => '#000']],
        ]);

        $this->assertIsArray($smells, 'a corrupt row must be skipped, never fatal');
        $this->assertSame(
            [],
            array_values(array_filter($smells, static fn ($s) => $s['type'] === 'inert_slot')),
            'no row here carries a READABLE unmet condition, so the advisory stays silent'
        );
    }

    // ── The authoring path (Section 14.1) and restore (#233) ────────────────

    /**
     * 14.1 AUTHORING-PATH MANDATE — exercised through create_page, not a raw meta write.
     * The advisory is NON-BLOCKING by ruling: the value is well-formed and would work on a
     * sibling configuration, it is just ineffective here, which is the "plausible but
     * ineffective" class the smells channel exists for. So the write must SUCCEED.
     */
    public function testCreatePageWithAnInertSlotSucceedsAndReportsTheSmell(): void
    {
        // RE-HOMED FROM `grid` TO `ppfixture` AT #1101, and it is the second retarget for
        // the identical reason: it moved testimonials -> grid at #1026 because a v2
        // component with no style slots REJECTS this write (invalid_style_slot) instead of
        // accepting it with an advisory — the opposite of what the test is about — and
        // grid is now itself such a component. `ppfixture` is the registered test-only
        // slot host (#1025); it dies with the slot engine in this task's PR2.
        // `--ppfixture-overlay-bg` applies when `background_image` is set, and this band
        // sets no background image, so the stored value is well-formed and dead.
        $composition = [
            ['component' => 'ppfixture', 'props' => [
                'id'    => 'cards',
                'items' => [['number' => '10', 'label' => 'Sites']],
            ], 'style' => ['--ppfixture-overlay-bg' => '#ffffff']],
        ];

        $this->assertTrue(
            pp_validate_action('create_page', ['title' => 'Card grid', 'composition' => $composition]),
            'an inert slot is advisory — it must never reject the write'
        );

        $result = pp_execute_action('create_page', [
            'title'       => 'Card grid',
            'composition' => $composition,
        ]);
        $this->assertTrue($result['ok']);

        $stored = pp_get_composition((int) $result['target']['post_id']);
        $this->assertSame('#ffffff', $stored[0]['style']['--ppfixture-overlay-bg'], 'stored as authored');

        $smells = $this->inertSmells($stored);
        $this->assertCount(1, $smells);
        $this->assertStringContainsString('--ppfixture-overlay-bg', $smells[0]['message']);
        $this->assertStringContainsString('applies when background_image is set', $smells[0]['message']);
    }

    /** update_component onto a configuration that defeats a set slot: same posture. */
    public function testUpdateComponentIntoAnInertConfigurationSucceedsAndReportsIt(): void
    {
        // RE-HOMED FROM `grid` TO `ppfixture` AT #1101. The prop edit that used to defeat
        // the slot was `card_emphasis: featured -> uniform`, and both the prop and the
        // `--grid-featured-shadow` it gated retired with grid's v2 rebuild. The shape is
        // preserved exactly — a band that is CLEAN, then one legal prop patch away from
        // carrying a dead slot — with `title` as the condition and its removal (a null
        // patch, update_component's documented prop-removal form) as the edit.
        // `ppfixture` is the registered test-only slot host (#1025), dead in this PR2.
        $post_id = pp_create_page('Fixture heading', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'ppfixture', 'props' => [
                'title' => 'Cards',
                'items' => [['number' => '10', 'label' => 'Sites']],
            ], 'style' => ['--ppfixture-heading-color' => '#ffffff']],
        ]);
        $this->assertSame([], $this->inertSmells(pp_get_composition($post_id)), 'the title is set, so the heading slot applies');

        $result = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['title' => null],
        ]);
        $this->assertTrue($result['ok'], 'dropping the title is a legal edit, not a rejected one');

        $smells = $this->inertSmells(pp_get_composition($post_id));
        $this->assertCount(1, $smells);
        $this->assertStringContainsString('--ppfixture-heading-color', $smells[0]['message']);
        $this->assertStringContainsString('applies when title is set', $smells[0]['message']);
    }

    /**
     * restore_composition is NEVER blocked by current validation rules — it restores and
     * reports through the shared engines (#233). An inert slot is a finding, not a wall.
     */
    public function testRestoreIsNotBlockedByAnInertSlotAndReportsItAsAFinding(): void
    {
        // RE-HOMED FROM `grid` TO `ppfixture` AT #1101. grid's v2 rebuild left it with no
        // style slots, so the snapshot below would have been REFUSED at the write that
        // seeds the history ring rather than restored-with-a-finding — there would be
        // nothing to restore. `ppfixture` keeps the slot map across rebuilds (#1025) and
        // dies with the slot engine in this task's PR2.
        $post_id = pp_create_page('Inert snapshot');
        pp_update_composition($post_id, [
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '10', 'label' => 'Sites']]],
             'style' => ['--ppfixture-overlay-bg' => '#111111']],
        ]);
        pp_update_composition($post_id, [
            ['component' => 'ppfixture', 'props' => ['title' => 'B', 'items' => [['number' => '10', 'label' => 'Sites']]]],
        ]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], 'restore reports, it does not block');
        $inert = array_values(array_filter($result['findings'], static fn ($f) => $f['type'] === 'inert_slot'));
        $this->assertCount(1, $inert);
        $this->assertSame('warning', $inert[0]['severity'], 'advisory severity, never an error');
        $this->assertStringContainsString('--ppfixture-overlay-bg', $inert[0]['message']);

        $restored = pp_get_composition($post_id);
        $this->assertSame('#111111', $restored[0]['style']['--ppfixture-overlay-bg'], 'the snapshot came back intact');
    }

    // ── The `wp pp validate site` gate (the #610 failure mode) ───────────────

    /**
     * THE SHIPPED STARTER MUST STAY CLEAN. lib/cli.php's `validate site` sets $pass=false
     * on ANY smell and halts(1), so an advisory that fires on pp_default_homepage_composition()
     * makes a FRESH INSTALL exit 1 against the theme's own seeded homepage — with no
     * authorable fix, since the operator did not write that composition. That is exactly
     * why #578's measure advisory was deferred to issue #610, and this pin is why #580's
     * advisory could ship instead.
     */
    public function testTheShippedStarterHomepageEmitsNoInertSlotSmell(): void
    {
        $smells = pp_validate_composition_smells(pp_default_homepage_composition());

        // THE SEED CARRIES NO `style` MAP AT ALL SINCE #1101 — its last one went with
        // grid's rebuild, and every band now designs itself through `udc`. Stated plainly
        // because it changes what this assertion is: it was a live measurement over 60+
        // seeded slots across six components, and it is now a STANDING GATE that goes live
        // again the moment any seeded band carries a slot. It is kept rather than retired
        // because the failure mode it guards is unchanged and unforgiving — an advisory
        // firing on the theme's own homepage exits a fresh install 1 with no authorable
        // fix — and because the sibling test below still measures the seed end-to-end
        // across every smell type, which is the half that is still live.
        $this->assertSame(
            [],
            array_values(array_filter($smells, static fn ($s) => $s['type'] === 'inert_slot')),
            'If this fails, `wp pp validate site` now exits 1 on a fresh install against the theme\'s '
            . 'own seeded homepage — fix the CONDITION or the seed, never the gate.'
        );
        $this->assertSame(
            [],
            array_values(array_filter(
                pp_default_homepage_composition(),
                static fn ($band) => !empty($band['style'])
            )),
            'A seeded band carries a `style` map again. The pin above stopped being a live '
            . 'measurement at #1101 and became a standing gate; it is a measurement again now, '
            . 'so check that slot\'s applies_when against the band that seeds it.'
        );
    }

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
     * A field an agent never sees is not in the baseline (ruling 4). #575 landed the
     * emitter; this proves the POPULATED values actually travel through it, on both the
     * slot catalog and the condensed prop catalog, in the one prompt an agent reads.
     */
    public function testThePopulatedConditionsReachTheRuntimeCatalog(): void
    {
        $prompt = pp_ai_system_prompt();

        // A clause list, ANDed, on the slot catalog. Section's surface slots carried this
        // before the v2 rebuild (#1023); `--ppfixture-overlay-bg` carries the same clause now.
        $this->assertStringContainsString(
            'applies when background_image is set',
            $prompt,
            'a background-conditional slot must advertise its condition to the agent BEFORE the write'
        );
        // Prose-only conditionality. Re-pointed THREE times now, and the moves are the
        // point: the DISJUNCTION example was section's link-colour pair and left with its
        // slot map at #1023; the INTERACTION-STATE example was faq's open question and left
        // at #1046, when the open state became a ROLE (`question-open`) whose selector
        // states the condition instead of prose describing it; the ITEM-LEVEL example was
        // grid's icon box ("at least one item declares an image_url") and left at #1101
        // with the last slot map in the theme.
        //
        // THE CLASS SURVIVED THE LOSS OF EVERY SLOT THAT CARRIED IT, on the PROP surface —
        // logos' height cap is the same item-level shape grid's icon box was, and the
        // clause grammar cannot reach it for the same reason: clauses address the BAND's
        // props, never an item's. So the two separate assertions this test used to carry —
        // one for the slot catalog's prose, one for the condensed prop catalog's — are now
        // ONE assertion, because there is one surviving producer and it is a prop. See
        // testNoStyleSlotCarriesProseConditionalityAnyMore... above for the census, and
        // SchemaValidationTest's prose-class census for what each retirement cost.
        $this->assertStringContainsString('the height cap applied to a logo image is chosen by that item', $prompt);
        // A clause list AND a note joined as ONE condition used to be pinned here on grid's
        // top-level-scoped featured slot. No definition in the theme declares both fields
        // any more; the claim moved, with its census, to
        // testTheClauseAndNoteConjunctionStillRendersThoughNothingDeclaresBoth below.
    }

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
     *     measurement: every surviving `applies_when` is note-free (ppfixture's nine slots
     *     and footer's `contact_label`) and every surviving note is clause-free (logos,
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

    /**
     * The advisory and the catalog phrase a condition IDENTICALLY, because both render it
     * with pp_ai_format_applies_when_clause(). Two phrasings of one condition is the
     * second-source-of-truth defect this contract exists to prevent.
     */
    public function testTheWarningAndTheCatalogPhraseTheConditionIdentically(): void
    {
        // A single-clause, note-free slot, so the catalog's whole condition and the
        // advisory's whole condition are the same string. (On a multi-clause slot the
        // advisory deliberately names only the clauses that MISSED — see
        // testUnmetReportsEveryFailingClauseInDeclarationOrder — so they diverge by
        // design there, not by phrasing.)
        // RE-HOMED FROM `grid` TO `ppfixture` AT #1101. `--grid-step-bg` retired with
        // grid's v2 rebuild, and an undeclared slot raises no advisory at all — the
        // assertCount(1) below would have failed rather than gone quietly vacuous, but the
        // fix is the same one every slot-engine test in this file took: `ppfixture` is the
        // registered test-only host kept across rebuilds (#1025), and it dies with the
        // slot engine in this task's PR2. `--ppfixture-overlay-bg` has the property this
        // test needs and grid's step slot had: exactly one clause and no
        // `conditionality_note`, so the catalog's whole condition IS the advisory's whole
        // condition and comparing them as strings is meaningful.
        $smells = $this->inertSmells([
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '10', 'label' => 'Sites']]],
             'style' => ['--ppfixture-overlay-bg' => '#eeeeee']],
        ]);
        $this->assertCount(1, $smells);

        $suffix    = pp_ai_definition_suffix(pp_get_style_slots('ppfixture')['--ppfixture-overlay-bg']);
        $condition = substr($suffix, strpos($suffix, 'applies when'));

        $this->assertSame('applies when background_image is set', $condition);
        $this->assertStringContainsString($condition, $smells[0]['message']);
    }
}
