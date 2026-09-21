<?php
/**
 * tests/StyleSlotContractTest.php
 *
 * THE v1 STYLE-SLOT CONTRACT, RETIRED WITH ITS LAST SUBJECT (#1101).
 *
 * WHAT THIS FILE WAS. The KEYSTONE contract test (#92): it proved that every declared
 * `styling.style_slots` entry was actually HONOURED by the renderer's CSS, not merely
 * present in a schema. A slot the API accepted and the stylesheet dropped or mis-wired
 * was the defect class it existed to catch — an authoring surface that reports success
 * and paints nothing, which is the accepted-stored-ignored failure the whole v2 engine
 * was later built to close. Twenty-six tests, auto-discovering components from
 * `components/*.schema.json`, deriving the slot -> subject -> property contract from the
 * stylesheet itself, and failing closed on every derivation that collapsed.
 *
 * WHAT THE TWENTY-SIX PROVED, grouped as the file itself grouped them. This is the whole
 * record; nothing below it is a summary of something still asserted elsewhere unless it
 * says so.
 *
 *   DISCOVERY AND ITS FLOOR
 *   `testDiscoveryFindsTheKnownStyledComponents` — the fail-closed floor for every other
 *       check here: the schema glob must actually find the slot-bearing components, or an
 *       empty list would pass all twenty-five of them vacuously. It also asserted the
 *       NEGATIVE — that a v2 component must NOT be discovered — so the suite could never
 *       start enforcing a slot contract against a component that has none.
 *
 *   CHECK 1-2: THE SLOT IS WIRED
 *   `testEverySlotConsumedInComponentBlock` — every declared slot is consumed as
 *       `var(--slot …)` INSIDE its own `COMPONENT: <name>` block, comments stripped. The
 *       negative was stated and verified: delete a consumption and this goes red.
 *   `testSlotConsumedOnTypeCompatibleProperty` — at least one consumption sits on a
 *       property the slot's declared TYPE can paint (shadow -> box-shadow, color ->
 *       color/background/border-color, length -> padding/size/radius/border-width, number
 *       -> line-height/weight). A `color` slot consumed only on `padding` was wired and
 *       useless, and nothing else in the suite could tell the difference.
 *
 *   CHECK 3-5: THE SLOT IS NOT DEFEATED
 *   `testSlottedPropertyNotClobberedAnywhereInStylesheet` — a hand-maintained cross-block
 *       contract (slot -> the selector that must read it -> the property), pinning that no
 *       rule ANYWHERE re-declares that property on that subject and so silently outranks
 *       the slot. Each entry carried its own anti-vacuity floor: if the scan found no
 *       declaration of that property on that selector at all, the entry was stale and the
 *       test said so rather than passing.
 *   `testDarkSurfaceVariantsRouteForegroundColorsThroughSlots` — #61: a dark-surface
 *       variant's descendant colour rules had to route slots rather than pin literals, or
 *       a themed band could not be re-inked at all.
 *   `testDeclaredSlotsNotBypassedByLiteralReDeclarations` (+ `testWaiverLedgerOnlyShrinks`,
 *       `testGuardDetectsTheDeadSlotClass`) — #305's generalized bypass guard: the
 *       slot/subject/property contract DERIVED from the CSS, with any literal
 *       re-declaration that defeats a consumed slot failing the build unless waived in the
 *       shrink-only #309 ledger. The ledger could only shrink, and the guard carried a
 *       mutation self-test proving it still detected the dead-slot class.
 *   `testIssue293FeaturedRemnantSlotFallbacks`, `testIssue584LogosConditionalityNoteMatches
 *       TheStylesheet` — two per-issue pins of exact fallback chains and of a schema
 *       conditionality note against the rules that implement it.
 *
 *   THE WP-CORE BORDER TRIGGER (a defect found by dogfooding, 1.0-H)
 *   `testNoBorderTriggerSlotBelongsToChrome`, `testBorderTriggerSlotsHaveCascadeImmunity`,
 *       `testInlineSlotSurfacesAreCoveredByTheImmunityBaseline`,
 *       `testImmunityGuardDetectsAMissingBaseline`,
 *       `testBorderTriggerDiscoveryCoversPerSideCoreRules` — setting any `border-*-width`
 *       or `border-*-color` slot made WordPress core's own block rules apply a 3px border,
 *       so every inline slot surface needed a baseline declaration ahead of the component
 *       rules to be immune. Discovery, the baseline, the ORDER of the baseline relative to
 *       the component rules, and a mutation self-test for the guard.
 *
 *   THE CROSS-SHEET AND PARSER LAYER
 *   `testNoStylesheetRuleDeclaresASchemaSlot` — no rule may DECLARE a slot custom property
 *       (as opposed to reading one), with an exact, documented exemption list diffed by
 *       COUNT so a duplicate re-declaration could not slip through.
 *   `testNoUnacknowledgedCrossSheetClobber` (+ `testCrossSheetLedgerOnlyShrinks`,
 *       `testCrossSheetGuardDetectsANewClobber`, `testCrossSheetLoadOrderAssumptionHolds`)
 *       — the same clobber question across stylesheet BOUNDARIES, where load order decides
 *       the winner, with its own shrink-only ledger and mutation self-test.
 *   `testWhereContributesNoSpecificityIncludingItsArguments`,
 *       `testTheSubjectParserSplitsAtParenDepthZero`,
 *       `testARuleAfterTheLayerWrapperIsStillTopLevel`,
 *       `testALayerStatementDoesNotSwallowTheNextRule`,
 *       `testABaselineInsideAMediaBlockStillDoesNotCount`,
 *       `testTheOrderCheckStillSeesABaselineBelowTheComponentRules` — the specificity
 *       scorer and the CSS parsers the guards above are built on, each pinned against
 *       synthetic input so a parser regression could not silently disarm a guard.
 *
 * WHY THE WHOLE CONTRACT HAS NO SUBJECT. Every single check above begins by asking a
 * schema which style slots it declares. The answer is now `[]` for every component in the
 * theme. The v2 rebuilds emptied the roster one component at a time — testimonials #958,
 * hero #986, section #1023, cta #1026, faq #1046, table and embed #1066, stats and logos
 * #1066 PR2 — and #1101 rebuilt GRID, which this file's own floor recorded as "the last
 * component in the theme that declares a style slot at all", with the decision that the
 * suite "retires WITH grid's rebuild, not before". This is that retirement.
 *
 * Nine of the twenty-six went RED at #1101 rather than green, and every one of them was a
 * fail-closed floor firing: "the bypass guard would pass vacuously", "discovery found
 * fewer border-trigger slots than the 4 known today", "the cross-sheet guard would pass
 * vacuously". That is the guard machinery working exactly as designed, and it is why this
 * file could not be narrowed to nothing quietly — which was the point of building the
 * floors in the first place.
 *
 * WHERE THE CLAIMS WENT. Not here, and that is deliberate: a contract about how a slot
 * must be wired has no v2 translation, because a v2 value is not wired through the
 * stylesheet at all. The engine emits a band-scoped rule from a role's declared groups,
 * so "is this slot consumed", "is it consumed on a compatible property", "does a literal
 * elsewhere defeat it" and "does another stylesheet clobber it" are questions the
 * architecture no longer permits to be asked. What replaced them is per-component: each
 * v2 component has its own `*RoleDefaultsEmitTest` asserting the EMITTED declaration, and
 * `tests/js/css-lint.test.js` holds the stylesheet-text half (its own rosters emptied at
 * #1101 too, and are asserted empty there rather than deleted).
 *
 * WHAT IS LEFT HERE, AND WHY IT IS THREE TESTS RATHER THAN NONE. The file stays so the
 * retirement cannot be reversed in silence: the surface is asserted EMPTY, the discovery
 * that found it empty is proved still able to SEE a slot map, and the stylesheet is
 * pinned as declaring no slot custom property at all. A schema that re-declares
 * `styling.style_slots` fails here, loudly, with the twenty-six-test record above sitting
 * directly beside the failure — which is the only thing that makes re-adding one a
 * deliberate decision rather than an accident nobody can price.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class StyleSlotContractTest extends TestCase
{
    private string $themeRoot;
    private string $css;

    protected function setUp(): void
    {
        $this->themeRoot = dirname(__DIR__);
        $this->css       = file_get_contents($this->themeRoot . '/assets/css/components.css');
        $this->assertNotEmpty($this->css, 'components.css should be readable.');
    }

    /**
     * THE DISCOVERY PREDICATE, EXTRACTED SO IT CAN BE PROVED (#1101).
     *
     * It was an inline `!empty($schema['styling']['style_slots'])` inside the discovery
     * loop, which was fine while the roster was non-empty: any bug in it showed up as a
     * missing component. With the roster empty, a bug in it is INDISTINGUISHABLE from the
     * emptiness it is being used to establish — so it is a named function with its own
     * detection proof below, and the two assertions are no longer the same assertion.
     */
    private static function declaresStyleSlots(array $schema): bool
    {
        return ($schema['styling']['style_slots'] ?? []) !== [];
    }

    /**
     * Components that declare style_slots, AUTO-DISCOVERED from components/x/schema.json
     * (issue 305). Previously a hand-maintained list — a NEW component's slots were
     * invisible to every check in this file until someone remembered to add it here.
     * Discovery makes "a new schema slot with no CSS consumer fails out of the box"
     * hold for components that do not exist yet, and it is what makes the emptiness below
     * a fact about the theme rather than about a list nobody updated.
     *
     * @return array{0: list<string>, 1: list<string>}  [slot-bearing components, all components]
     */
    private function discover(): array
    {
        $bearing = [];
        $all     = [];
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $schemaFile) {
            $component = basename(dirname($schemaFile));
            $schema    = json_decode(file_get_contents($schemaFile), true);
            // A malformed schema must fail loudly, not silently exit every check.
            $this->assertIsArray(
                $schema,
                $component . '/schema.json is not valid JSON — discovery would silently skip it.'
            );
            $all[] = $component;
            if (self::declaresStyleSlots($schema)) {
                $bearing[] = $component;
            }
        }
        sort($bearing);
        sort($all);
        return [$bearing, $all];
    }

    /**
     * THE SURFACE IS EMPTY, AND THE DERIVATION THAT SAYS SO CAN STILL SEE A SLOT MAP.
     *
     * Three separate facts, because collapsing them is how an emptiness claim goes wrong:
     * the schemas declare no slots, the ENGINE agrees with the schemas (a fallback slot
     * table inside `pp_get_style_slots()` would make the first true and the behaviour
     * false), and the predicate that produced both answers still returns true for a schema
     * that DOES declare a slot map.
     *
     * The detection proof is a synthetic decoded schema rather than a fixture on disk. The
     * fixture component `tests/fixtures/components/ppfixture` still declares sixteen slots
     * and would have served — but it is scheduled for deletion, and a proof that dies with
     * a fixture is a proof that stops proving on someone else's schedule.
     */
    public function testNoComponentDeclaresAStyleSlotAndDiscoveryCouldStillSeeOne(): void
    {
        [$bearing, $all] = $this->discover();

        $this->assertSame(
            [],
            $bearing,
            'a component declares `styling.style_slots` again — the first since grid left at '
            . '#1101. Read the twenty-six-test record at the top of this file before '
            . 'proceeding: a declared slot ships with NO wiring contract, no bypass guard, '
            . 'no WP-core border immunity and no cross-sheet clobber guard, because all '
            . 'twenty-six retired with their last subject.'
        );

        // ANTI-VACUITY 1: the glob must have found the theme's components, or the emptiness
        // above is a fact about a broken scan.
        $this->assertGreaterThanOrEqual(
            12,
            count($all),
            'schema discovery found fewer components than the theme ships'
        );

        // ANTI-VACUITY 2: the engine's own answer, per component, not inferred from the
        // schemas it was just asked about.
        foreach ($all as $component) {
            $this->assertSame(
                [],
                pp_get_style_slots($component),
                "pp_get_style_slots('{$component}') returns slots the schema does not declare"
            );
        }

        // ANTI-VACUITY 3: the predicate itself. Without this, deleting the `!== []` and
        // returning a bare `false` would make every assertion above pass.
        $this->assertTrue(
            self::declaresStyleSlots(['styling' => ['style_slots' => ['--x-bg' => ['type' => 'color']]]]),
            'the discovery predicate no longer recognises a declared slot map, so the '
            . 'emptiness it reports is meaningless'
        );
        $this->assertFalse(self::declaresStyleSlots(['styling' => ['style_slots' => []]]));
        $this->assertFalse(self::declaresStyleSlots([]));

        // ANTI-VACUITY 4: THE TEST FIXTURE, WHICH THE GLOB ABOVE CANNOT SEE (#1101 PR2).
        //
        // `discover()` reads components/*/schema.json — the SHIPPED registry. But the last
        // declarer of a style slot in this repo was never a shipped component: it was
        // tests/fixtures/components/ppfixture, which is why the slot engine stayed alive
        // for a whole sprint after the last real consumer left. A census that cannot see
        // the fixture would have reported ZERO throughout that sprint and been wrong about
        // the only thing anyone needed to know.
        //
        // Read off disk rather than through the registry, deliberately: the fixture is
        // invisible to pp_get_registered_components() without an opt-in, so asking the
        // registry would answer "not present" and prove nothing about what it declares.
        $fixture = dirname(__DIR__) . '/tests/fixtures/components/ppfixture/schema.json';
        $this->assertFileExists($fixture, 'the fixture moved — this census now has a blind spot');
        $fixtureSchema = json_decode((string) file_get_contents($fixture), true);
        $this->assertIsArray($fixtureSchema);
        $this->assertFalse(
            self::declaresStyleSlots($fixtureSchema),
            'ppfixture declares a style slot again. It was the LAST declarer in the repo and '
            . 'the reason the engine outlived its last shipped consumer by a sprint — so a '
            . 'slot here is not a test detail, it is the engine coming back with no wiring '
            . 'contract behind it. Read this file\'s header first.'
        );
    }

    /**
     * THE RECIPE HALF OF THE SAME CENSUS, and it needs its own method because a recipe
     * could be declared without a slot map: `styling.recipes` is read by a different
     * accessor and nothing makes the two move together.
     *
     * A recipe was a named bundle of slot values. With no slots to bundle, one would be a
     * name an author could ask for and never receive — style_component refuses before it
     * reads the `recipe` parameter at all, so the request would not even fail informatively.
     */
    public function testNoComponentDeclaresARecipeEither(): void
    {
        $declaring = [];
        $checked   = 0;
        foreach (glob($this->themeRoot . '/components/*/schema.json') as $schemaFile) {
            $schema = json_decode((string) file_get_contents($schemaFile), true);
            $this->assertIsArray($schema, basename(dirname($schemaFile)) . '/schema.json is not valid JSON');
            $checked++;
            if (($schema['styling']['recipes'] ?? []) !== []) {
                $declaring[] = basename(dirname($schemaFile));
            }
        }

        $this->assertSame([], $declaring, 'a component declares `styling.recipes` again, and there is no engine to expand it');
        $this->assertGreaterThanOrEqual(12, $checked, 'schema discovery found fewer components than the theme ships');

        // The fixture, for the same reason as above: it held the last three recipes in the
        // repo, moved here from grid earlier in #1101 and deleted with the engine.
        $fixtureSchema = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/components/ppfixture/schema.json'),
            true
        );
        $this->assertIsArray($fixtureSchema);
        $this->assertSame([], $fixtureSchema['styling']['recipes'] ?? [], 'ppfixture declares a recipe again');

        // And the engine's own answer, which is what an author would actually meet.
        foreach (['grid', 'hero', 'section', 'cta'] as $component) {
            $this->assertSame([], pp_get_style_recipes($component));
        }
    }

    /**
     * NO STYLESHEET RULE DECLARES A SLOT CUSTOM PROPERTY — `testNoStylesheetRuleDeclaresA
     * SchemaSlot`'s claim, now unconditional.
     *
     * That test held the strictest half of the contract: a rule may READ a slot
     * (`var(--grid-bg, …)`) but must never DECLARE one, because a declaration in the
     * stylesheet competes with the author's inline value and decides the cascade by source
     * order rather than by intent. It carried an exact exemption list, diffed by COUNT so a
     * DUPLICATE rule re-declaring the same slot could not slip past a set comparison, and
     * that list was empty by the time grid left.
     *
     * The claim survives the schemas that gave it its vocabulary: the names are derived
     * from the component roster, so `--grid-item-bg` is exactly as forbidden today as it
     * was when grid declared it — more so, since nothing would read it back.
     */
    public function testNoStylesheetRuleDeclaresAComponentSlotCustomProperty(): void
    {
        [, $all]  = $this->discover();
        $stripped = $this->stripComments($this->css);
        $pattern  = '/(--(?:' . implode('|', array_map('preg_quote', $all)) . ')-[a-z0-9-]+)\s*:/';

        preg_match_all($pattern, $stripped, $m);
        $declared = array_values(array_unique($m[1]));
        sort($declared);

        $this->assertSame(
            [],
            $declared,
            'components.css DECLARES a component-scoped custom property. A stylesheet '
            . 'declaration of a slot-shaped name competes with an author\'s value and '
            . 'resolves by source order rather than by intent — which is the defect '
            . 'testNoStylesheetRuleDeclaresASchemaSlot existed to keep out, and no component '
            . 'declares a slot for it to be the legitimate value of.'
        );

        // DETECTION PROOF: the matcher must still catch the shipped-and-removed spelling,
        // or this absence is a statement about a broken regex.
        $this->assertSame(
            1,
            preg_match($pattern, '.grid__item { --grid-item-bg: #111111; }'),
            'the slot-declaration matcher no longer recognises a declared slot'
        );
        $this->assertSame(
            0,
            preg_match($pattern, '.grid__item { background: var(--grid-item-bg, #fff); }'),
            'the matcher has started flagging a READ as a declaration — the distinction is '
            . 'the entire point of this check'
        );
    }

    /**
     * THE TWO DEAD READS, PINNED AS A SHRINK-ONLY LEDGER RATHER THAN LEFT UNNAMED.
     *
     * Reading a slot is not forbidden the way declaring one is, and two reads outlived
     * their declarers: `.stats` and `.logos` still route their band padding through
     * `var(--<name>-padding-top, var(--pp-band-padding-adjacent-top))` although both
     * components became v2 at #1066 PR2. Nothing declares those names, nothing can write
     * them, and no composition can make them resolve — so each `var()` falls back on every
     * render, which is why they are harmless and why nobody noticed them.
     *
     * They are pinned rather than tolerated, in the shape this file used for exactly this
     * situation (the #309 waiver ledger and the cross-sheet ledger, both shrink-only): the
     * derived set must EQUAL the ledger. A new dead read fails because the set grew; a
     * cleanup fails because it shrank, which forces the cleanup to be a deliberate edit
     * that removes the entry here in the same commit rather than a drive-by. Both
     * directions matter — an unpinned residue is how a reader concludes the name still
     * works.
     */
    public function testTheOnlySlotReadsLeftAreTheTwoKnownDeadFallbacks(): void
    {
        // Shrink-only. Every entry is a read of a name NO component declares.
        $ledger = ['--logos-padding-top', '--stats-padding-top'];

        [, $all]  = $this->discover();
        $stripped = $this->stripComments($this->css);
        $pattern  = '/var\(\s*(--(?:' . implode('|', array_map('preg_quote', $all)) . ')-[a-z0-9-]+)/';

        preg_match_all($pattern, $stripped, $m);
        $read = array_values(array_unique($m[1]));
        sort($read);

        $this->assertSame(
            $ledger,
            $read,
            'the set of stylesheet reads of an undeclared slot name changed. A GROWTH is a '
            . 'new dead read — a `var()` that can never resolve, which reads to the next '
            . 'author as a live authoring surface. A SHRINK is a cleanup: delete the entry '
            . 'from the ledger above in the same commit, so removing the last one is a '
            . 'decision rather than a silent narrowing of this guard to nothing.'
        );

        // And each ledger entry is genuinely dead: no component declares it, so it cannot
        // be written and cannot resolve. Without this the ledger is just a list of strings.
        foreach ($ledger as $name) {
            preg_match('/^--([a-z0-9]+)-/', $name, $owner);
            $this->assertSame(
                [],
                pp_get_style_slots($owner[1] ?? ''),
                "{$name}'s owner declares slots again — this entry may no longer be dead, and "
                . 'a live slot needs the wiring contract this file retired, not a ledger row'
            );
        }

        // DETECTION PROOF for the read matcher, the mirror of the declaration proof above.
        $this->assertSame(
            1,
            preg_match($pattern, 'padding-top: var(--stats-padding-top, 1rem);'),
            'the slot-read matcher no longer recognises a read'
        );
    }

    private function stripComments(string $css): string
    {
        return preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
    }
}
