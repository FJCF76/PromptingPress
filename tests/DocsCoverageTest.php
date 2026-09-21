<?php
/**
 * tests/DocsCoverageTest.php
 *
 * Issue 585 (A-20) — the DERIVED half of the docs contract: every claim in this
 * file is checked against `schema.json` / the action registry, never against a
 * hardcoded list that would drift the same way the docs did.
 *
 * WHY THIS SUITE EXISTS. Schema legibility IS the product surface: the harness
 * agent is thin by design and carries no product knowledge, so what it can learn
 * from `schema.json` plus the shipped docs is the whole of the baseline. A-20
 * found fourteen separate places where those two had drifted apart — a README
 * calling an optional prop required, six READMEs documenting zero style slots, a
 * slot count five gates stale, an action description enumerating 24 of 25
 * whitelisted keys. Every one of those was invisible to the existing suites
 * because nothing compared prose to schema.
 *
 * WHAT THESE ASSERTIONS PIN, AND WHAT THEY DO NOT. They pin COVERAGE and
 * COUNTS derived from the schemas: that a name declared in a schema also appears
 * in the doc an agent would read. They deliberately do NOT pin prose — rewording
 * must stay free, or the docs calcify and nobody improves them. What must fail
 * here is a schema growing a prop, a slot, or a whitelisted key that no doc
 * mentions. That is the exact drift A-20 had to clean up by hand.
 *
 * The stated-reason half of #585 lives in StatedReasonsTest.php: different
 * failure mode (a decision silently deleted), different assertion style
 * (presence of a disclosure, not a derived set), so a different file.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class DocsCoverageTest extends TestCase
{
    private string $themeRoot;

    /**
     * The template-owned chrome pair. Excluded from the slot assertions on
     * purpose: they declare zero style slots by the ratified chrome contract
     * (#223), so "document every slot" is vacuous for them, and their authoring
     * surface is site options, guarded by ChromeAuthoringSurfaceTest. They ARE
     * included in the prop and do_not_touch assertions below.
     */
    private const CHROME = ['nav', 'footer'];

    /**
     * Both rosters are DERIVED from the components directory, never hardcoded —
     * a hardcoded list would drift exactly the way the docs it guards did, and a
     * newly added component would be silently exempt from every assertion here.
     */
    private static function allComponents(): array
    {
        $names = array_map(
            static fn ($p) => basename(dirname($p)),
            glob(dirname(__DIR__) . '/components/*/schema.json') ?: []
        );
        sort($names);
        return $names;
    }

    private static function composableComponents(): array
    {
        return array_values(array_diff(self::allComponents(), self::CHROME));
    }

    /**
     * The components still on the v1 STYLE-SLOT system, which is what every slot
     * assertion below is about.
     *
     * DERIVED from the schemas through the engine's own predicate, never listed
     * here: a hardcoded roster would drift exactly the way the docs it guards
     * did. A component is v2 when its schema declares `roles`, and a v2
     * component has no slots to document — it has ROLES, covered by
     * testEveryDeclaredRoleIsNamedInItsReadme() below. The two assertions
     * together still cover every composable component's authoring surface; the
     * roster each walks is what changed.
     */
    private static function slotStyledComponents(): array
    {
        return array_values(array_filter(
            self::composableComponents(),
            static fn (string $name): bool => !pp_udc_is_v2_component($name)
        ));
    }

    private static function roleStyledComponents(): array
    {
        return array_values(array_filter(
            self::composableComponents(),
            static fn (string $name): bool => pp_udc_is_v2_component($name)
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
    }

    private function schema(string $component): array
    {
        $path    = $this->themeRoot . "/components/{$component}/schema.json";
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, "{$component}/schema.json must be valid JSON.");
        return $decoded;
    }

    private function readme(string $component): string
    {
        return (string) file_get_contents(
            $this->themeRoot . "/components/{$component}/README.md"
        );
    }

    private function slots(string $component): array
    {
        return $this->schema($component)['styling']['style_slots'] ?? [];
    }

    private function doc(string $relative): string
    {
        // ASSERT THE FILE EXISTS FIRST. Without this, a renamed or moved doc makes
        // file_get_contents() return false and every assertion downstream reports something
        // else entirely — "states a stale retired-prop count" for a file that is simply gone.
        // This PR relocates `ai-instructions/add-page.md`, which is exactly the move that
        // produces that misleading failure, and the sibling anchor helper in
        // ModelFacingRosterTest was given the same guard for the same reason.
        $path = $this->themeRoot . '/' . $relative;
        $this->assertFileExists($path, "a docs guard reads {$relative}, which no longer exists");
        return (string) file_get_contents($path);
    }

    /**
     * THE TWO SLOT-README GUARDS RETIRED AT #1101, AND THE CLAIM THEY MADE IS KEPT.
     *
     * They walked `slotStyledComponentProvider()` — every component declaring
     * `styling.style_slots` — and asserted, in both directions, that its README named
     * exactly the slots its schema declared: no declared slot missing from the prose, and
     * no prose name the schema did not declare unless the same paragraph marked it absent.
     *
     * grid was the last member of that provider, so with its rebuild the provider returns
     * an empty list and PHPUnit ERRORS on an empty data set rather than skipping — which
     * is how this retirement announced itself rather than passing silently. There is
     * nothing left for either direction to check: a component with no slots has no slot
     * to omit from its README and no slot name to over-advertise.
     *
     * THE EMPTINESS IS ASSERTED BELOW rather than left implicit, because the provider is
     * DERIVED: if a rebuilt component ever re-grows a slot map, this line fails first and
     * the two guards above have to be restored deliberately instead of the map shipping
     * with no README coverage at all. The ROLE-grain equivalents
     * (`roleStyledComponentProvider`, `testEveryDeclaredRoleIsNamedInItsReadme` and
     * `testTheLayoutRosterInEachReadmeMatchesItsSchema`) are the live guards now, and grid
     * joined them in this same change.
     */
    public function testNoComponentDeclaresStyleSlotsAnyMore(): void
    {
        $this->assertSame(
            [],
            self::slotStyledComponents(),
            'a component declares `styling.style_slots` again. The v1 slot surface was '
            . 'retired with grid at #1101; if this is deliberate, restore the two README '
            . 'coverage guards this assertion replaced in the same commit, or the slot '
            . 'ships with no authoring-surface coverage at all.'
        );
        // Anti-vacuity: the derivation must still be able to SEE components, or an empty
        // result would prove nothing about slots.
        $this->assertNotEmpty(self::allComponents(), 'component discovery found nothing');
    }

    /**
     * The aggregate count in AI_CONTEXT.md, and the per-component breakdown beside
     * it, must equal what the schemas actually declare. This number was "232 style
     * slots across 10 components" for five minor versions while the real figure
     * moved; regenerating it by hand is exactly what keeps going wrong.
     */
    /**
     * THE LAYOUT ROSTER IS WRITTEN IN THREE PLACES, and two of them were tied
     * together while the third was not (#1084, found by the pre-landing
     * maintainability pass).
     *
     * A component's `schema.json` declares which roles carry `layout`, and
     * tests/js/css-lint.test.js asserts that list against a derivation from the
     * stylesheet in both directions. The nine component READMEs then hand-copy the
     * same role names for a human reader — and a hand-copied roster is the #1045
     * class exactly: it goes stale at the next rebuild, and a vocabulary-scoped doc
     * sweep cannot find it. This ties the prose to the schema, which is the only
     * one of the three an author can act on.
     */
    public function testTheLayoutRosterInEachReadmeMatchesItsSchema(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode((string) file_get_contents($file), true);
            $roles     = $schema['roles'] ?? [];
            $expected  = [];
            foreach ($roles as $role => $definition) {
                if (in_array('layout', $definition['groups'] ?? [], true)) {
                    $expected[] = $role;
                }
            }
            if ($expected === []) {
                continue;
            }

            $readme = (string) @file_get_contents(dirname($file) . '/README.md');
            $this->assertNotSame('', $readme, "{$component} exposes layout but has no README to say so");

            $ok = preg_match('/Which roles carry `layout`[^\n]*\n?[^\n]*/', $readme, $m);
            $this->assertSame(1, $ok,
                "components/{$component}/README.md does not name which of its roles carry `layout`. "
                . 'A roster a reader cannot find is a roster they will re-derive, usually wrong.');

            $named = [];
            foreach ($expected as $role) {
                if (preg_match('/`' . preg_quote($role, '/') . '`/', $m[0])) {
                    $named[] = $role;
                }
            }
            sort($named);
            $sorted = $expected;
            sort($sorted);
            $this->assertSame($sorted, $named, sprintf(
                'components/%s/README.md names %s in its layout roster; the schema declares %s.',
                $component,
                implode(', ', $named) ?: '(none)',
                implode(', ', $sorted)
            ));
            $checked++;
        }

        // Fail-closed: a walk that stops finding schemas would pass vacuously.
        $this->assertGreaterThanOrEqual(9, $checked,
            'the roster walk found almost no components — it is asserting on an empty set');
    }

    public function testAiContextSlotCensusMatchesTheSchemas(): void
    {
        // The census covers the components still ON the style-slot system. A v2
        // component contributes no slots, so listing it as "(0)" would read as a
        // component someone forgot to style rather than one that moved to roles.
        $total  = 0;
        $counts = [];
        foreach (self::slotStyledComponents() as $component) {
            $n = count($this->slots($component));
            $counts[$component] = $n;
            $total += $n;
        }

        $context = $this->doc('AI_CONTEXT.md');

        $this->assertStringContainsString(
            "**{$total} style slots** across " . count($counts) . ' components',
            $context,
            "AI_CONTEXT.md's slot census is stale. The schemas declare {$total} slots "
            . 'across ' . count($counts) . ' components; regenerate the line rather than '
            . 'hand-editing it.'
        );

        foreach ($counts as $component => $n) {
            $this->assertStringContainsString(
                "{$component} ({$n})",
                $context,
                "AI_CONTEXT.md's per-component slot breakdown is stale for {$component}: "
                . "the schema declares {$n} slots."
            );
        }
    }

    /**
     * style-component.md restates the per-component slot count in prose for the four
     * narrow bands ("`table` (6 slots)"). That is a second copy of the census, and a
     * second copy is a second thing that goes stale — the exact defect A-20 spent a
     * gate cleaning up. Derived from the schemas, same as the AI_CONTEXT.md census.
     */
    public function testStyleComponentNarrowBandCountsMatchTheSchemas(): void
    {
        $doc = $this->doc('ai-instructions/style-component.md');
        // Derived, not hardcoded: any component the doc gives a "(N slots)" headline to
        // is checked, so a new narrow-band paragraph is covered the day it lands.
        preg_match_all('/\*\*`([a-z]+)` \(\d+ slots?\)/', $doc, $m);
        $named = array_values(array_unique($m[1]));
        $this->assertNotEmpty($named, 'style-component.md no longer states any slot count.');
        foreach ($named as $component) {
            $n = count($this->slots($component));
            $this->assertStringContainsString(
                "**`{$component}` ({$n} slots)",
                $doc,
                "ai-instructions/style-component.md states a stale slot count for "
                . "{$component}: the schema declares {$n}. Regenerate it rather than "
                . 'hand-editing, or drop the number and point at AI_CONTEXT.md.'
            );
        }
    }

    /**
     * add-component.md tells the model WHICH prop keys answer `retired_prop` rather than
     * `unknown_prop`, by stating a count and enumerating the roster. That is a third copy
     * of a registry fact, and it went stale exactly the way the two above were built to
     * stop: #1023 wrote "the ten v2-rebuild keys" and #1026's cta rebuild added four more,
     * leaving the doc telling an agent that cta's `theme` is an unknown prop when the
     * schema answers `retired_prop` with a migration message. The floor test in
     * SchemaValidationTest guards the REGISTRY; nothing guarded the sentence about it.
     *
     * Derived from the registry, never listed here, for the reason slotStyledComponents()
     * records: a hand-written roster is the defect.
     */
    public function testTheRetiredPropRosterInAddComponentMatchesTheSchemas(): void
    {
        $keys = [];
        foreach (self::composableComponents() as $component) {
            foreach (array_keys(pp_component_retired_props($component)) as $prop) {
                $keys[] = [$component, $prop];
            }
        }
        $this->assertNotEmpty($keys, 'no component declares a retired prop any more.');

        // ODD NUMBERS WERE MISSING UNTIL #1046, and the gap was invisible because every
        // rebuild so far had retired an EVEN number of props: hero 4, section 4, cta 4,
        // testimonials 2. faq retired exactly one, so the roster went 14 -> 15 and this
        // map could not spell the total — the test failed with its own "extend $words"
        // message, which is the guard working, but only after the arithmetic happened to
        // land on a number nobody had provided. Filled in to 21 so the next rebuild meets
        // a map rather than a gap.
        $words = [
            1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
            7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven',
            12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen',
            16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen',
            20 => 'twenty', 21 => 'twenty-one', 22 => 'twenty-two',
            23 => 'twenty-three', 24 => 'twenty-four', 25 => 'twenty-five',
            26 => 'twenty-six', 27 => 'twenty-seven', 28 => 'twenty-eight',
            29 => 'twenty-nine', 30 => 'thirty',
        ];
        $total = count($keys);
        $this->assertArrayHasKey(
            $total,
            $words,
            "the retired-prop roster is now {$total} keys and this test has no English word "
            . 'for it — extend $words, then fix the doc sentence.'
        );

        // EVERY COPY OF THE ROSTER, not just the first one written (#1087).
        //
        // This check used to cover `ai-instructions/add-component.md` alone, and its own
        // docblock called that "a third copy of a registry fact". The prose rewrite then
        // widened the roster in two MORE places — validate-site.md's repair table and the CLI
        // reference's error-code migration — because both are genuinely the right place for a
        // reader to meet it. That is three full enumerations of a registry fact with one of
        // them guarded, which is exactly how validate-site.md's list came to say fifteen keys
        // when the registry had nineteen.
        //
        // Each entry carries its own anchor because each file states the roster in its own
        // words, and each anchor is FAIL-CLOSED: if the sentence is reworded so the anchor
        // stops matching, this fails rather than silently checking nothing.
        //
        // AND EACH CAPTURE IS BOUNDED, for the reason ModelFacingRosterTest states at length
        // in this same change: an unbounded `(.+?)` under `/s` is terminated only by the next
        // occurrence of its closing phrase ANYWHERE in the file, so a reworded delimiter can
        // silently grow the span into neighbouring prose and let a component named elsewhere
        // satisfy a roster it has left. Measured today these captures are 485 and 451
        // characters on a single line each, so the line bound plus 800 characters is generous
        // for the roster and far short of the next paragraph. Two guards in one PR should not
        // disagree about what a checkable roster is.
        $componentCount = count(array_unique(array_column($keys, 0)));
        $sites = [
            [
                'ai-instructions/add-component.md',
                "the {$words[$total]} v2-rebuild keys",
                '/the ' . $words[$total] . ' v2-rebuild keys \((.+?)\) return/',
            ],
            [
                'ai-instructions/validate-site.md',
                // Derived, like its two siblings — a hardcoded literal fails on a correct
                // update and passes on a stale one. AND IT KEEPS THE COMPONENT COUNT: the
                // first cut at deriving this dropped the word "components" and with it the
                // only assertion in the repo that the component count is right, so
                // "nineteen keys across twelve components" passed. $componentCount was
                // already computed for a failure message and never asserted.
                "{$words[$total]} keys across {$words[$componentCount]} components",
                '/The whole set is ' . $words[$total] . ' keys across [a-z-]+ components\*\*, ([^\n]{0,800}?) `table` is v2/',
            ],
            [
                'docs/reference-apply-cli.md',
                "**{$words[$total]}** keys",
                '/the \*\*' . $words[$total] . '\*\* keys across \*\*[a-z-]+\*\* components([^\n]{0,800}?)moved from/',
            ],
        ];

        foreach ($sites as [$relative, $countPhrase, $rosterPattern]) {
            $doc = $this->doc($relative);
            $this->assertStringContainsString(
                $countPhrase,
                $doc,
                "{$relative} states a stale retired-prop count. The registry declares "
                . "{$total} keys across {$componentCount} components. An agent reading the "
                . 'stale number is told a retired prop is an unknown one, so it never learns '
                . 'where the value went.'
            );

            // And the roster itself: every declaring component and every key it retired has
            // to be NAMED, or the sentence's count is right while its list still omits a
            // component. Scoped to the roster span, not the whole doc: matching document-wide
            // would let a component named in some unrelated checklist satisfy a roster it has
            // left.
            $this->assertSame(
                1,
                preg_match($rosterPattern, $doc, $roster),
                "{$relative} no longer carries a retired-prop roster its count can be checked "
                . 'against, so nothing states WHICH keys they are. Re-point the anchor in this '
                . 'test if the sentence was deliberately reworded.'
            );
            // EACH COMPONENT'S SEGMENT, not a character window. The first cut let the
            // search run from a component's possessive to the next SEMICOLON, which two of
            // the three rosters do not use as their separator — so the window crossed into a
            // neighbour and would certify a MISSING pair as present (measured: `embed`'s
            // window reached `stats`' `background_image`). Stopping at a comma instead broke
            // the third roster, which comma-separates one component's OWN props.
            //
            // The real divider is the NEXT possessive. Splitting on it gives each component
            // exactly its own entry, whichever punctuation the file uses inside it.
            // THE FIRST SEGMENT ONLY, and parentheticals stripped. Concatenating every
            // mention of a component let any later reference satisfy it — "logos' `theme` —
            // hero's old `width` slot is unrelated" certified hero's `width` while hero's own
            // entry omitted it. And a parenthetical inside an entry does the same within one
            // segment: "stats' `theme` (its `background_image` moved to the `_band` role)"
            // certified a pair the roster does not claim. Neither is exotic prose; both are
            // how these rosters already read.
            $possessive = "/([a-z]+)`?(?:'s|') /";
            $segments   = [];
            $parts      = preg_split($possessive, $roster[1], -1, PREG_SPLIT_DELIM_CAPTURE);
            for ($i = 1; $i < count($parts); $i += 2) {
                if (isset($segments[$parts[$i]])) {
                    continue;
                }
                // STRIP A PARENTHETICAL ONLY WHEN SOMETHING SURVIVES IT. An aside like
                // "stats' `theme` (its `background_image` moved to `_band`)" must not certify
                // a pair the roster does not claim — but a roster that lists its props INSIDE
                // parentheses ("hero's retired keys (`spacing`, `width`)") is ordinary prose
                // and stripping it wholesale fails a correct doc. Strip only if a backticked
                // prop remains outside.
                $segment = $parts[$i + 1] ?? '';
                $outside = preg_replace('/\([^)]*\)/', '', $segment) ?? '';
                $segments[$parts[$i]] = str_contains($outside, '`') ? $outside : $segment;
            }

            foreach ($keys as [$component, $prop]) {
                $this->assertArrayHasKey(
                    $component,
                    $segments,
                    "{$relative}'s retired-prop roster never names {$component} at all. "
                    . "The roster reads: {$roster[1]}"
                );
                $this->assertMatchesRegularExpression(
                    // Possessive either way: "hero's" and the plural "testimonials'" — and
                    // with or without the backtick the newer copies wrap the name in, so
                    // "`cta`'s `theme`" and "cta's `theme`" both satisfy it. Requiring one
                    // house style here would fail a roster that is correct.
                    '/`' . preg_quote($prop, '/') . '`/',
                    $segments[$component],
                    "{$relative}'s retired-prop roster never names {$component}'s "
                    . "`{$prop}`. The roster reads: {$roster[1]}"
                );
            }
        }
    }

    /**
     * AI_RULES.md tells whoever edits `assets/css/` WHICH components the structural-CSS
     * lint holds to the v2 boundary. It named five and cta's rebuild made it six: the lint
     * roster itself is derived from the registry (tests/js/css-lint.test.js walks the
     * components directory), so the code followed the rebuild and only the sentence about
     * it went stale. Both directions, because either is a live hazard: an unnamed v2
     * component reads as one whose designable values may still go in the stylesheet, and a
     * v1 component named here would send its slots to a role that does not exist.
     */
    /**
     * THE RUNTIME PROMPT'S v2 ROSTER, DERIVED (#1046).
     *
     * `lib/ai-context.php` is not documentation — it is the text the model reads at run
     * time — and it carried a hand-written roster: "ON A v2 COMPONENT (hero, section,
     * testimonials, cta)". faq's rebuild falsified it, and nothing failed: the derived
     * catalog in the same prompt was already advertising faq's ten roles while this
     * sentence told the model faq was not on the contract. A model reading a roster it is
     * not on concludes the capability is missing, which is the #1045 class landing on the
     * one surface where it changes behaviour rather than only reading.
     *
     * Derived from `pp_udc_is_v2_component()` in both directions, so the next rebuild
     * fails here instead of shipping a prompt that contradicts its own catalog.
     */
    public function testTheRuntimePromptsV2RosterMatchesTheRegistry(): void
    {
        // SEED THE STORE FIRST. `pp_ai_system_prompt()` enumerates pages and menus, so it
        // reaches the `get_posts()` stub — and this file's other tests read DOCS, never the
        // prompt, so nothing here had initialised `$_pp_test_store`. Without this the test
        // passes while emitting three PHP warnings into the suite (undefined global, array
        // offset on null, foreach on null), which is how a green run quietly grows its
        // warning count. Mirrors AiContextTest::setUp().
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];

        $prompt = pp_ai_system_prompt();
        $this->assertMatchesRegularExpression(
            '/ON A v2 COMPONENT \(([^)]+)\)/',
            $prompt,
            'the runtime prompt must carry the v2 roster it teaches the focal-point route with'
        );
        preg_match('/ON A v2 COMPONENT \(([^)]+)\)/', $prompt, $m);
        $roster = $m[1];

        foreach (array_keys(pp_composable_components()) as $component) {
            if (pp_udc_is_v2_component($component)) {
                $this->assertStringContainsString(
                    $component,
                    $roster,
                    "the runtime prompt's v2 roster omits `{$component}`, which declares roles — "
                    . 'the model would read a roster it is not on and conclude the `udc` route '
                    . "does not apply to {$component}. Roster reads: {$roster}"
                );
            } else {
                $this->assertStringNotContainsString(
                    $component,
                    $roster,
                    "the runtime prompt's v2 roster names `{$component}`, which is still on style "
                    . "slots. Roster reads: {$roster}"
                );
            }
        }
    }

    public function testTheStructuralLintRosterInAiRulesMatchesTheRegistry(): void
    {
        $v2 = array_values(array_filter(self::allComponents(), 'pp_udc_is_v2_component'));
        $v1 = array_values(array_diff(self::allComponents(), $v2));
        $this->assertNotEmpty($v2, 'no component declares roles any more.');

        $doc = 'AI_RULES.md';
        $this->assertSame(
            1,
            preg_match('/As of #\d+ that covers ([^.]+)\./', $this->doc($doc), $m),
            "{$doc} no longer states which components the structural-CSS lint covers."
        );
        $roster = $m[1];

        foreach ($v2 as $component) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($component, '/') . '\b/',
                $roster,
                "{$doc}'s structural-CSS lint roster omits `{$component}`, which declares "
                . 'roles. Read as written, its designable values may still go in the '
                . "stylesheet — the one thing the v2 boundary forbids. Roster reads: {$roster}"
            );
        }
        foreach ($v1 as $component) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b' . preg_quote($component, '/') . '\b/',
                $roster,
                "{$doc}'s structural-CSS lint roster names `{$component}`, which is still on "
                . "style slots. Roster reads: {$roster}"
            );
        }
    }

    /**
     * The recipes table claims five components ship NO named recipe. That is a
     * schema-derivable claim, so adding a recipe later would leave the authoring
     * surface asserting it does not exist — and an agent would never try it.
     */
    public function testRecipeTableMatchesTheDeclaredRecipes(): void
    {
        $doc = $this->doc('ai-instructions/style-component.md');
        foreach (self::composableComponents() as $component) {
            $recipes = $this->schema($component)['styling']['recipes'] ?? [];
            foreach (array_keys($recipes) as $recipe) {
                // Row-scoped. A whole-file grep is vacuous here: `dark-showcase` is
                // declared by BOTH grid and testimonials, so grid's row would satisfy
                // a testimonials row that had been replaced with "no recipes".
                $this->assertMatchesRegularExpression(
                    '/^\| ' . preg_quote($component, '/') . ' \| `' . preg_quote($recipe, '/') . '` \|/m',
                    $doc,
                    "ai-instructions/style-component.md's recipe table has no `| {$component} | "
                    . "`{$recipe}` |` row for that declared recipe."
                );
            }
            if ($recipes === []) {
                $this->assertMatchesRegularExpression(
                    '/\| ' . preg_quote($component, '/') . ' \| — \|/',
                    $doc,
                    "ai-instructions/style-component.md must record that {$component} ships "
                    . 'no named recipe (a `—` row), so an agent stops looking for one.'
                );
            }
        }
    }

    /**
     * The docs now assert, in three places, that the table's horizontal scroll is
     * viewport-independent. That is a checkable CSS fact, and the claim is the whole
     * point of the correction — it replaced five years of "scrolls on mobile" prose.
     * Pinned the same way StatedReasonsTest pins the global reduced-motion guard.
     */
    public function testTableScrollMechanismIsStillViewportIndependent(): void
    {
        $css = $this->doc('assets/css/components.css');

        $this->assertMatchesRegularExpression(
            '/\.table-wrap \{[^}]*overflow-x: auto;/',
            $css,
            '.table-wrap no longer declares overflow-x: auto. Three authoring surfaces '
            . 'state that a wide table scrolls at any viewport; if the mechanism moved, '
            . 'those need rewriting, not this assertion relaxing.'
        );
        // Both declarations, order-independent: swapping them is a rendering no-op and
        // must not fail this guard.
        $tableRule = preg_match('/^\.table \{([^}]*)\}/m', $css, $m) ? $m[1] : '';
        $this->assertStringContainsString(
            'width: max-content;',
            $tableRule,
            '.table no longer sizes to max-content — half of the mechanism that makes the '
            . 'wrapper scroll rather than the table shrink.'
        );
        $this->assertStringContainsString(
            'min-width: 100%;',
            $tableRule,
            '.table lost its 100% floor, so a narrow table no longer fills the band.'
        );
        // The claim is "no media query", so the rule must sit at column 0 (outside
        // any @media block), which is how this stylesheet indents nested rules.
        $this->assertMatchesRegularExpression(
            '/^\.table-wrap \{/m',
            $css,
            '.table-wrap appears to have moved inside a @media block. The documented '
            . 'contract is that the scroll is viewport-independent.'
        );
    }

    // ── Prop coverage ────────────────────────────────────────────────────────

    /**
     * Every prop a schema declares must be named in that component's README.
     * `section/README.md` omitted all six panel_* props and `table/README.md`
     * omitted `id` — in both cases a whole authorable capability was invisible to
     * anyone reading the doc.
     *
     * Chrome is included deliberately: nav/footer props are UNREACHABLE from a
     * composition (#582), and the README is where that is disclosed, so they must
     * be listed there precisely so the disclosure has somewhere to hang.
     *
     * @dataProvider allComponentProvider
     */
    public function testEveryDeclaredPropIsNamedInItsReadme(string $component): void
    {
        $props  = array_keys($this->schema($component)['props'] ?? []);
        $readme = $this->readme($component);

        $missing = [];
        foreach ($props as $prop) {
            if (!preg_match('/`' . preg_quote($prop, '/') . '`/', $readme)) {
                $missing[] = $prop;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "components/{$component}/README.md does not name these declared props: "
            . implode(', ', $missing) . '.'
        );
    }

    /**
     * A README must not contradict the schema about whether a prop is required.
     * `cta/README.md` said `title` was required for five minor versions after the
     * schema made it optional and `composition.md` documented the heading-less
     * button pattern that depends on it being optional.
     *
     * @dataProvider allComponentProvider
     */
    public function testReadmeRequiredColumnMatchesTheSchema(string $component): void
    {
        $props  = $this->schema($component)['props'] ?? [];
        $readme = $this->readme($component);

        $matched = 0;
        foreach ($props as $name => $def) {
            $required = (bool) ($def['required'] ?? false);
            // Match the README's props-table row for this prop and read its
            // Required cell.
            if (!preg_match('/^\|\s*`' . preg_quote($name, '/') . '`\s*\|([^|]*)\|([^|]*)\|/m', $readme, $m)) {
                continue;
            }
            $matched++;
            $cell = strtolower(trim($m[2]));
            // Enforce BOTH directions explicitly. Testing only "starts with no" let a
            // required prop be documented as "Optional" or "—" and still pass.
            $saysNo  = str_starts_with($cell, 'no');
            $saysYes = str_starts_with($cell, 'yes');

            if ($required) {
                $this->assertTrue(
                    $saysYes,
                    "components/{$component}/README.md does not mark `{$name}` as required "
                    . "(cell: \"{$cell}\"), but the schema declares required: true."
                );
            } else {
                $this->assertTrue(
                    $saysNo,
                    "components/{$component}/README.md marks `{$name}` as required "
                    . "(cell: \"{$cell}\"), but the schema declares required: false. "
                    . 'A README that over-states a requirement makes agents pass props '
                    . 'they do not need and skip patterns that depend on the prop being optional.'
                );
            }
        }

        // Without this the whole check degrades to a silent no-op the moment a
        // README reshuffles its props table, which is exactly when it is needed.
        $this->assertSame(
            count($props),
            $matched,
            "components/{$component}/README.md has a props table this check could not "
            . 'parse for every declared prop (' . $matched . ' of ' . count($props)
            . ' rows matched). Keep the table shape `| `prop` | type | required | ...` '
            . 'so the required-column check stays live.'
        );
    }

    // ── One consistent do_not_touch string ───────────────────────────────────

    /**
     * `do_not_touch` told three different stories across twelve components: four
     * said "schema.json without updating README.md", five said "...AI_CONTEXT.md",
     * hero said "...without ALSO updating AI_CONTEXT.md". Both docs genuinely have
     * to move when a schema changes — the README carries the prop table and slot
     * list, AI_CONTEXT.md carries the slot census this file also pins — so the one
     * consistent string names both rather than picking a single doc and being
     * wrong half the time.
     */
    public function testDoNotTouchIsOneConsistentStringEverywhere(): void
    {
        $seen = [];
        foreach (self::allComponents() as $component) {
            $seen[$component] = $this->schema($component)['do_not_touch'] ?? [];
        }

        $expected = ["schema.json without updating this component's README.md and the repo-root AI_CONTEXT.md"];
        foreach ($seen as $component => $value) {
            $this->assertSame(
                $expected,
                $value,
                "components/{$component}/schema.json declares a different do_not_touch "
                . 'string than its siblings. Twelve components giving three different '
                . 'answers to "what else must I update?" is what made this drift.'
            );
        }
    }

    // ── The site-option whitelist ────────────────────────────────────────────

    /**
     * `update_site_option`'s own description is the only place the AI learns which
     * keys it may write. It enumerated 24 of the 25 whitelisted keys — pp_footer_social
     * was reachable, validated, and documented nowhere the writer would look.
     */
    public function testUpdateSiteOptionDescriptionNamesEveryWhitelistedKey(): void
    {
        require_once $this->themeRoot . '/lib/wp.php';
        $keys = array_keys(pp_allowed_site_options());
        $this->assertNotEmpty($keys, 'pp_allowed_site_options() returned nothing.');

        $actions = (string) file_get_contents($this->themeRoot . '/lib/actions.php');
        $start   = strpos($actions, "pp_register_action('update_site_option'");
        $this->assertNotFalse($start, 'update_site_option registration not found.');
        // Bound the window at the NEXT registration. A fixed byte count overspills
        // into sibling actions, so another action's description could satisfy a key
        // this one never names.
        $end   = strpos($actions, 'pp_register_action(', $start + 20);
        $block = $end === false ? substr($actions, $start) : substr($actions, $start, $end - $start);

        $missing = [];
        foreach ($keys as $key) {
            // Boundary-anchored: pp_footer_contact must not be satisfied by
            // pp_footer_contact_label — the exact near-miss this test exists for.
            if (!preg_match('/' . preg_quote($key, '/') . '(?![a-z0-9_])/', $block)) {
                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'update_site_option is registered with a description/semantics that never '
            . 'names these whitelisted keys: ' . implode(', ', $missing)
            . '. A key the description omits is a key the AI does not know it may write.'
        );
    }

    // ── The markup contract ──────────────────────────────────────────────────

    /**
     * Every prop whose schema description declares the RICH contract must appear
     * in composition.md's markup-contract table. That table claims to cover every
     * text prop, and it listed two of the five rich surfaces — `table` cells,
     * `embed.content` and `hero.proof` were missing, so an agent reading it would
     * conclude a table cell could not take a link.
     *
     * Keyed off the schema `description` marker rather than off the PHP render
     * path: the marker is the contract the schema publishes, and TextPropMarkupContractTest
     * already pins that the marker matches rendering.
     */
    public function testCompositionMarkupTableListsEveryRichProp(): void
    {
        $marker = 'Rich HTML (sanitized via wp_kses_post)';
        $table  = $this->doc('ai-instructions/composition.md');

        $rich = [];
        foreach (self::composableComponents() as $component) {
            $this->collectRichProps($this->schema($component)['props'] ?? [], $component, $rich);
        }

        $this->assertNotEmpty($rich, "No prop declares the '{$marker}' contract — marker changed?");

        $missing = [];
        foreach ($rich as $component => $props) {
            foreach ($props as $prop) {
                // The table names them in prose (e.g. "`table.rows[][]` cells"),
                // so require the component AND the prop name to co-occur.
                if (!preg_match('/`' . preg_quote($component, '/') . '\.' . preg_quote($prop, '/') . '/', $table)) {
                    $missing[] = "{$component}.{$prop}";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "ai-instructions/composition.md's markup-contract table omits these rich-HTML "
            . 'surfaces: ' . implode(', ', $missing)
            . '. The table claims to cover every text prop, so an omission reads as '
            . '"this prop takes plain text".'
        );
    }

    /** Walk a schema props map (including nested `items`) collecting rich-contract props. */
    private function collectRichProps(array $props, string $component, array &$out, string $prefix = ''): void
    {
        foreach ($props as $name => $def) {
            if (!is_array($def)) {
                continue;
            }
            $path = $prefix === '' ? $name : "{$prefix}.{$name}";
            if (strpos((string) ($def['description'] ?? ''), 'Rich HTML (sanitized via wp_kses_post)') !== false) {
                $out[$component][] = $path;
            }
            if (isset($def['items']) && is_array($def['items'])) {
                $this->collectRichProps($def['items'], $component, $out, "{$path}[]");
            }
        }
    }

    // ── The three band-background slots that do not exist ────────────────────

    /**
     * `--table-bg`, `--embed-bg` and `--logos-bg` are undeclared, and since #1066
     * they are undeclared for TWO different reasons — the shared assertion below
     * outlived the single reason that first motivated it.
     *
     * `--logos-bg` is still the DEFERRED family this test was written for: logos
     * is on style slots, its `muted` variant paints `--color-surface` directly,
     * and the gate's entry criterion (ship the ink and framing slots in the same
     * change, or do not ship) is still live.
     *
     * `--table-bg` and `--embed-bg` are NOT deferred any more. Both components
     * went to the Universal Design Contract at #1066, so a band tone on either is
     * the `_band` role's `background.fill` — a shipped capability, not a withheld
     * one. Their names stay barred here for the stronger reason that a v2
     * component declares NO style slots at all, so any `--table-*` / `--embed-*`
     * key reappearing is a v2 boundary violation.
     *
     * Two things must hold at once and this pins both: no schema may declare them,
     * and every doc that names them must mark them absent in the same breath — a
     * naive "never mention them" rule would forbid the disclosure logos still owes.
     */
    public function testDeferredBandBackgroundSlotsAreUndeclaredAndAlwaysMarkedAbsent(): void
    {
        $deferred = ['--table-bg', '--embed-bg', '--logos-bg'];

        foreach (self::composableComponents() as $component) {
            foreach ($deferred as $slot) {
                $this->assertArrayNotHasKey(
                    $slot,
                    $this->slots($component),
                    "{$component} declares {$slot}. That slot is part of a deferred "
                    . 'band-background family: it ships with the ink and framing slots '
                    . 'that keep the band readable, or it does not ship.'
                );
            }
        }

        $surfaces = [
            'ai-instructions/style-component.md',
            'components/table/README.md',
            'components/embed/README.md',
            'components/logos/README.md',
        ];

        $absence = '/(do(es)? not exist|is no |are no |not shipped|not declared|deferred|is ever\s+shipped)/i';

        foreach ($surfaces as $relative) {
            // Paragraph scope. A doc-wide check is vacuous here: all four surfaces
            // already contain the word "deferred" several times for unrelated
            // reasons, so a neutral mention in one paragraph would be excused by a
            // disclosure in another.
            foreach (preg_split('/\n\s*\n/', $this->doc($relative)) ?: [] as $paragraph) {
                foreach ($deferred as $slot) {
                    if (strpos($paragraph, $slot) === false) {
                        continue;
                    }
                    $this->assertMatchesRegularExpression(
                        $absence,
                        $paragraph,
                        "{$relative} names {$slot} in a paragraph that never marks it absent. "
                        . 'A doc that names an undeclared slot neutrally reads as an offer.'
                    );
                }
            }
        }
    }

    /**
     * THE v2 TWIN of testEveryDeclaredStyleSlotIsNamedInItsReadme().
     *
     * A component on the Universal Design Contract has no style slots to
     * document; its authoring surface is the set of ROLES its schema declares,
     * and each one has to be named in the README for the same reason a slot did
     * — a role nobody can find is a design surface nobody can reach, which is
     * the whole defect #901 reported about the quote's typography.
     *
     * Derived from the schema, so a role added in a later sprint fails this the
     * moment it lands undocumented.
     *
     * @dataProvider roleStyledComponentProvider
     */
    public function testEveryDeclaredRoleIsNamedInItsReadme(string $component): void
    {
        $roles  = pp_udc_component_roles($component);
        $readme = $this->readme($component);

        $this->assertNotEmpty($roles, "{$component} is v2 but declares no roles.");

        $missing = [];
        foreach (array_keys($roles) as $role) {
            // `_band` is the implicit root role every v2 component has; it is
            // named in the shared contract doc rather than restated per README.
            if ($role === '_band') {
                continue;
            }
            // BACKTICKED, not bare prose. Most role names are ordinary English
            // (`card`, `quote`, `author`, `meta`, `list`, `heading`), so a
            // word-boundary match against the whole README passes on incidental
            // usage: deleting every line that documents a role still satisfied 7
            // of 11. The style-slot guard this replaced matched `--component-slot`
            // strings that cannot occur by accident, and its v2 twin has to be
            // just as hard to satisfy by coincidence.
            if (!preg_match('/`' . preg_quote($role, '/') . '`/', $readme)) {
                $missing[] = $role;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "{$component}/README.md does not name these declared roles: " . implode(', ', $missing)
        );
    }

    // ── Providers ────────────────────────────────────────────────────────────

    public static function composableComponentProvider(): array
    {
        return array_map(static fn ($c) => [$c], self::composableComponents());
    }

    public static function slotStyledComponentProvider(): array
    {
        return array_map(static fn ($c) => [$c], self::slotStyledComponents());
    }

    public static function roleStyledComponentProvider(): array
    {
        return array_map(static fn ($c) => [$c], self::roleStyledComponents());
    }

    public static function allComponentProvider(): array
    {
        return array_map(static fn ($c) => [$c], self::allComponents());
    }
    /**
     * THE PROMPT CLAIMS A SLOT FAMILY IS EXTINCT — SO THE REGISTRY HAS TO AGREE (#1066 PR2).
     *
     * pp_ai_system_prompt() tells the authoring model that `--stats-bg-position` "was the
     * last one" of the `position`-typed style slots and that a band background's focal point
     * is `_band` -> `background.position` on every component now. Nothing asserted the first
     * half, and it is the half that rots: the day someone adds a `position` slot to grid, the
     * prompt keeps saying the family is extinct and an author is told to reach for a role
     * parameter on a component that has a slot for it.
     *
     * Derived from the registry rather than read from the prose, so it fails on the FACT
     * changing rather than on the sentence changing. The same shape as this file's v2-roster
     * test: the prompt's claim and the schemas are checked against each other, in the
     * direction that catches a schema gaining something the prompt has written off.
     */
    public function testNoPositionTypedStyleSlotSurvivesTheClaimThatTheFamilyIsExtinct(): void
    {
        $carriers = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            foreach (($schema['styling']['style_slots'] ?? []) as $slot => $definition) {
                if (is_array($definition) && ($definition['type'] ?? null) === 'position') {
                    $carriers[] = basename(dirname($file)) . ' ' . $slot;
                }
            }
        }

        $this->assertSame(
            [],
            $carriers,
            "pp_ai_system_prompt() states that `--stats-bg-position` was the LAST "
            . "position-typed style slot and that a focal point is now the `_band` role's "
            . '`background.position` everywhere. These slots contradict it: '
            . implode(', ', $carriers)
            . '. Either retire them or correct the prompt in the same change.'
        );

        // FAIL-CLOSED ON THE OTHER SIDE: an empty result is only meaningful while the prompt
        // still makes the claim. If that sentence is ever rewritten, this test should be
        // revisited rather than left asserting an absence nobody depends on.
        //
        // THE ANCHOR MOVED AT #1101, AND THIS ARM IS WHY IT WAS NOTICED. It used to read
        // 'was the last one and retired with stats at #1066' — a clause inside the
        // sentence that told the model there was no focal-point route on a v1 component.
        // grid's rebuild made that whole sentence false (there is no v1 component left to
        // have no route ON), so it was rewritten, and this arm failed on the rewrite
        // exactly as its own comment promised. The anchor is repointed to the half of the
        // claim that survived and is still the thing this test protects: that a band
        // background's focal point is a ROLE parameter everywhere, with no slot anywhere
        // contradicting it.
        $this->assertStringContainsString(
            "The BAND background's focal point is `_band` `background.position` everywhere",
            pp_ai_system_prompt(),
            'the prompt no longer makes the claim this test exists to protect — reconcile them'
        );
    }

}
