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
 *         │       "applies when layout = \"split\" AND proof is set"
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

final class AppliesWhenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
     * DEFAULT RESOLUTION — the single most load-bearing behaviour here. `card_emphasis`
     * defaults to "featured", so a grid that simply omits the prop IS featured. Without
     * the fallback, `--grid-featured-*` would be reported inert on most grids on the
     * internet, which is a false positive that halts `wp pp validate site`.
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

    /** The sibling-slot form reads the authored STYLE map, not the props. */
    public function testSlotPresentReadsTheStyleMap(): void
    {
        $clause = ['slot' => '--grid-item-bar-color', 'present' => true];

        $this->assertTrue(pp_applies_when_clause_met($clause, [], [], ['--grid-item-bar-color' => '#f00']));
        $this->assertFalse(pp_applies_when_clause_met($clause, [], [], []));
        $this->assertFalse(pp_applies_when_clause_met($clause, [], [], ['--grid-item-bar-color' => '']));
        $this->assertFalse(
            pp_applies_when_clause_met($clause, [], [], ['--grid-item-bar-color' => ['not', 'scalar']]),
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
            pp_applies_when_unmet_clauses($clauses, 'hero', ['layout' => 'split', 'proof' => 'x'], [])
        );
        $this->assertSame(
            [$clauses[1]],
            pp_applies_when_unmet_clauses($clauses, 'hero', ['layout' => 'split'], [])
        );
        $this->assertSame(
            $clauses,
            pp_applies_when_unmet_clauses($clauses, 'hero', [], []),
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
        $this->assertSame([], pp_applies_when_unmet_clauses([], 'hero', [], []));
        $this->assertSame([], pp_applies_when_unmet_clauses(['a' => ['prop' => 'x', 'present' => true]], 'hero', [], []));
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
        $smells = $this->inertSmells([
            ['component' => 'hero', 'props' => ['id' => 'h', 'title' => 'T', 'layout' => 'cover'],
             'style' => ['--hero-surface-bg' => '#fff']],
        ]);

        $this->assertCount(1, $smells);
        $this->assertSame('h', $smells[0]['id']);
        $this->assertSame(0, $smells[0]['index']);
        $this->assertStringContainsString('--hero-surface-bg', $smells[0]['message']);
        $this->assertStringContainsString('applies when layout = "split" AND proof is set', $smells[0]['message']);
    }

    /** ONE warning per slot, listing EVERY unmet clause — not one warning per clause. */
    public function testOneWarningPerSlotListsEveryUnmetClause(): void
    {
        $smells = $this->inertSmells([
            ['component' => 'hero', 'props' => ['title' => 'T'], 'style' => ['--hero-button2-bg' => '#000']],
        ]);

        $this->assertCount(1, $smells);
        $this->assertStringContainsString('button_text is set AND button2_text is set', $smells[0]['message']);
    }

    /** ...and one warning PER SLOT, so a band that defeats six slots reports six. */
    public function testEveryInertSlotOnOneComponentGetsItsOwnWarning(): void
    {
        $smells = $this->inertSmells([
            ['component' => 'hero', 'props' => ['title' => 'T', 'layout' => 'cover'],
             'style' => ['--hero-surface-bg' => '#fff', '--hero-surface-padding' => '2rem']],
        ]);

        $this->assertCount(2, $smells, 'one warning per SLOT, not one per component');
        $this->assertStringContainsString('--hero-surface-bg', $smells[0]['message']);
        $this->assertStringContainsString('--hero-surface-padding', $smells[1]['message']);
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
            ['component' => 'hero', 'props' => ['title' => 'T', 'layout' => 'cover'],
             'style' => ['--hero-surface-bg' => '#fff']],
        ]);

        $this->assertCount(1, $smells);
        $this->assertSame(1, $smells[0]['index']);
    }

    public function testAMetConditionIsSilent(): void
    {
        $this->assertSame([], $this->inertSmells([
            ['component' => 'hero', 'props' => ['title' => 'T', 'layout' => 'split', 'proof' => '<p>x</p>'],
             'style' => ['--hero-surface-bg' => '#fff']],
        ]));
    }

    public function testASlotWithNoAppliesWhenIsSilent(): void
    {
        $this->assertSame([], $this->inertSmells([
            ['component' => 'hero', 'props' => ['title' => 'T'], 'style' => ['--hero-bg' => '#101010']],
        ]));
    }

    /**
     * A `conditionality_note`-only definition stays silent, and that is the KNOWN BOUND,
     * not an oversight: the three prose classes (disjunction, `main >` scope, interaction
     * state) are unevaluable by construction. They reach the author through the AI
     * catalog before the write, never through this channel after it.
     */
    public function testAProseOnlyConditionIsSilent(): void
    {
        $this->assertSame([], $this->inertSmells([
            ['component' => 'section', 'props' => ['title' => 'T', 'body' => '<p>x</p>'],
             'style' => ['--section-body-link-color' => '#09f']],
        ]));
    }

    /**
     * An inert slot renders nothing, so no advisory about its VALUE can be true. The
     * transparent_fill warning tells the author to switch to the `outline` button variant
     * — useless advice about a second button that is not on the page at all, and a second
     * entry on a channel that halts `wp pp validate site`.
     */
    public function testAnInertFillSlotSuppressesTheValueLevelAdvisory(): void
    {
        $both = pp_validate_composition_smells([
            ['component' => 'cta', 'props' => ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/'],
             'style' => ['--cta-button2-bg' => 'transparent']],
        ]);
        $this->assertSame(['inert_slot'], array_column($both, 'type'));

        // With the second button actually rendered, the fill advisory is true again.
        $rendered = pp_validate_composition_smells([
            ['component' => 'cta', 'props' => [
                'title' => 'T', 'button_text' => 'Go', 'button_url' => '/',
                'button2_text' => 'More', 'button2_url' => '/more',
            ], 'style' => ['--cta-button2-bg' => 'transparent']],
        ]);
        $this->assertSame(['transparent_fill'], array_column($rendered, 'type'));
    }

    /**
     * A legacy name and its canonical twin stored together emit ONE custom property, so
     * they emit ONE warning — and it names the declaration that actually paints, in BOTH
     * stored key orders. Keying the effective map on last-write instead would make the
     * reported slot name depend on JSON key order.
     */
    public function testALegacyAndCanonicalPairWarnOnceUnderThePaintedName(): void
    {
        $props = ['layout' => 'stack', 'items' => [['quote' => 'q']]];

        foreach ([
            ['--testimonials-card-bg' => '#fff', '--testimonials-item-bg' => '#eee'],
            ['--testimonials-item-bg' => '#eee', '--testimonials-card-bg' => '#fff'],
        ] as $style) {
            $smells = $this->inertSmells([
                ['component' => 'testimonials', 'props' => $props, 'style' => $style],
            ]);

            $this->assertCount(1, $smells, 'one emitted property, one warning');
            $this->assertStringContainsString(
                '--testimonials-item-bg',
                $smells[0]['message'],
                'the canonical declaration is the one that paints, whichever key was stored first'
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
        foreach ([
            'empty value'        => ['--testimonials-item-bg' => ''],
            'undeclared slot'    => ['--testimonials-not-a-slot' => '#fff'],
            'rejected by render' => ['--testimonials-item-radius' => 'not-a-length'],
        ] as $label => $style) {
            $this->assertSame(
                [],
                $this->inertSmells([
                    ['component' => 'testimonials', 'props' => ['layout' => 'stack', 'items' => [['quote' => 'q']]],
                     'style' => $style],
                ]),
                "{$label}: the renderer drops this declaration, so the advisory must not report it"
            );
        }
    }

    /** The legacy name still warns when its canonical twin cannot paint — the renderer's rule. */
    public function testALegacyNameStillWarnsWhenTheCanonicalTwinCannotPaint(): void
    {
        $smells = $this->inertSmells([
            ['component' => 'testimonials', 'props' => ['layout' => 'stack', 'items' => [['quote' => 'q']]],
             'style' => ['--testimonials-card-bg' => '#fff', '--testimonials-item-bg' => '']],
        ]);

        $this->assertCount(1, $smells);
        $this->assertStringContainsString('--testimonials-card-bg', $smells[0]['message']);
    }

    /** A stored LEGACY slot name warns exactly as its canonical twin does. */
    public function testALegacySlotNameWarnsUnderItsCanonicalCondition(): void
    {
        $this->assertSame(
            '--testimonials-item-bg',
            pp_legacy_slot_aliases()['testimonials']['--testimonials-card-bg'] ?? null,
            'this test is about the alias path; if the alias is retired, retire the test with it'
        );

        $smells = $this->inertSmells([
            ['component' => 'testimonials', 'props' => ['layout' => 'stack', 'items' => [['quote' => 'q']]],
             'style' => ['--testimonials-card-bg' => '#ffffff']],
        ]);

        $this->assertCount(1, $smells);
        $this->assertStringContainsString(
            '--testimonials-card-bg',
            $smells[0]['message'],
            'the message names what the AUTHOR wrote, not the canonical twin they never typed'
        );
        $this->assertStringContainsString('applies when layout = "grid"', $smells[0]['message']);
    }

    /**
     * The sibling-slot clause form is answered against the CANONICAL view of the style
     * map. Resolving aliases per lookup instead would answer "unset" for a stored legacy
     * twin and warn about a slot the author did set.
     */
    public function testTheSiblingSlotFormSeesTheWholeCanonicalStyleMap(): void
    {
        $clauses = [['slot' => '--grid-item-bar-color', 'present' => true]];
        $this->assertSame(
            [],
            pp_applies_when_unmet_clauses($clauses, 'grid', [], ['--grid-item-bar-color' => '#f00'])
        );
        $this->assertSame($clauses, pp_applies_when_unmet_clauses($clauses, 'grid', [], []));
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
            ['component' => 'hero', 'props' => ['layout' => ['not', 'scalar'], 'proof' => 'p'], 'style' => ['--hero-surface-bg' => '#fff']],
            // 1: a non-scalar SLOT value is skipped before any condition is read.
            ['component' => 'hero', 'props' => ['title' => 'T'], 'style' => ['--hero-surface-bg' => ['array']]],
            // 2: a non-array style map.
            ['component' => 'hero', 'props' => ['title' => 'T'], 'style' => 'not-an-array'],
            // 3: a non-array props bag.
            ['component' => 'hero', 'props' => 'not-an-array', 'style' => ['--hero-bg' => '#000']],
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
        $composition = [
            ['component' => 'testimonials', 'props' => [
                'id'     => 'quotes',
                'layout' => 'stack',
                'items'  => [['quote' => 'Good.', 'author' => 'A']],
            ], 'style' => ['--testimonials-item-bg' => '#ffffff']],
        ];

        $this->assertTrue(
            pp_validate_action('create_page', ['title' => 'Stack quotes', 'composition' => $composition]),
            'an inert slot is advisory — it must never reject the write'
        );

        $result = pp_execute_action('create_page', [
            'title'       => 'Stack quotes',
            'composition' => $composition,
        ]);
        $this->assertTrue($result['ok']);

        $stored = pp_get_composition((int) $result['target']['post_id']);
        $this->assertSame('#ffffff', $stored[0]['style']['--testimonials-item-bg'], 'stored as authored');

        $smells = $this->inertSmells($stored);
        $this->assertCount(1, $smells);
        $this->assertStringContainsString('--testimonials-item-bg', $smells[0]['message']);
        $this->assertStringContainsString('applies when layout = "grid"', $smells[0]['message']);
    }

    /** update_component onto a configuration that defeats a set slot: same posture. */
    public function testUpdateComponentIntoAnInertConfigurationSucceedsAndReportsIt(): void
    {
        $post_id = pp_create_page('Grid emphasis', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => [
                'title' => 'Cards',
                'items' => [['title' => 'A'], ['title' => 'B']],
            ], 'style' => ['--grid-featured-shadow' => '0 2px 4px rgba(0,0,0,0.2)']],
        ]);
        $this->assertSame([], $this->inertSmells(pp_get_composition($post_id)), 'card_emphasis defaults to featured');

        $result = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['card_emphasis' => 'uniform'],
        ]);
        $this->assertTrue($result['ok'], 'switching to uniform is a legal edit, not a rejected one');

        $smells = $this->inertSmells(pp_get_composition($post_id));
        $this->assertCount(1, $smells);
        $this->assertStringContainsString('card_emphasis = "featured"', $smells[0]['message']);
    }

    /**
     * restore_composition is NEVER blocked by current validation rules — it restores and
     * reports through the shared engines (#233). An inert slot is a finding, not a wall.
     */
    public function testRestoreIsNotBlockedByAnInertSlotAndReportsItAsAFinding(): void
    {
        $post_id = pp_create_page('Inert snapshot');
        pp_update_composition($post_id, [
            ['component' => 'cta', 'props' => ['title' => 'A', 'button_text' => 'Go', 'button_url' => '#x'],
             'style' => ['--cta-button2-color' => '#111111']],
        ]);
        pp_update_composition($post_id, [
            ['component' => 'cta', 'props' => ['title' => 'B', 'button_text' => 'Go', 'button_url' => '#x']],
        ]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], 'restore reports, it does not block');
        $inert = array_values(array_filter($result['findings'], static fn ($f) => $f['type'] === 'inert_slot'));
        $this->assertCount(1, $inert);
        $this->assertSame('warning', $inert[0]['severity'], 'advisory severity, never an error');
        $this->assertStringContainsString('--cta-button2-color', $inert[0]['message']);

        $restored = pp_get_composition($post_id);
        $this->assertSame('#111111', $restored[0]['style']['--cta-button2-color'], 'the snapshot came back intact');
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

        $this->assertSame(
            [],
            array_values(array_filter($smells, static fn ($s) => $s['type'] === 'inert_slot')),
            'The starter seed sets 60+ slots across six components. If this fails, `wp pp validate site` '
            . 'now exits 1 on a fresh install — fix the CONDITION or the seed, never the gate.'
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

        // A clause list, ANDed, on the slot catalog.
        $this->assertStringContainsString(
            'applies when layout = "split" AND proof is set',
            $prompt,
            '--hero-surface-* must advertise its condition to the agent BEFORE the write'
        );
        // An `in` set.
        $this->assertStringContainsString('layout is one of "image-left", "image-right"', $prompt);
        // Prose-only conditionality (the disjunction class).
        $this->assertStringContainsString('the band is dark — theme: "inverted" OR a background_image is set', $prompt);
        // A clause list AND a note, joined as ONE condition.
        $this->assertStringContainsString(
            'applies when layout = "cards" AND card_emphasis = "featured" AND the component sits at the top level',
            $prompt
        );
        // The condensed PROP catalog carries it too, not only the slot catalog.
        $this->assertStringContainsString('the height cap applied to a logo image is chosen by that item', $prompt);
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
        $smells = $this->inertSmells([
            ['component' => 'testimonials', 'props' => ['layout' => 'stack', 'items' => [['quote' => 'q']]],
             'style' => ['--testimonials-item-shadow' => 'none']],
        ]);
        $this->assertCount(1, $smells);

        $suffix    = pp_ai_definition_suffix(pp_get_style_slots('testimonials')['--testimonials-item-shadow']);
        $condition = substr($suffix, strpos($suffix, 'applies when'));

        $this->assertSame('applies when layout = "grid"', $condition);
        $this->assertStringContainsString($condition, $smells[0]['message']);
    }
}
