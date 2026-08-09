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
        return (string) file_get_contents($this->themeRoot . '/' . $relative);
    }

    // ── Style-slot coverage ──────────────────────────────────────────────────

    /**
     * Every style slot a component declares must be named in that component's
     * README. Six READMEs (testimonials, faq, logos, embed, table, stats) plus
     * hero documented ZERO slots before #585, so a slot could ship with no
     * authoring-surface mention at all. Derived from the schema, so a new slot
     * fails this the moment it lands undocumented.
     *
     * @dataProvider composableComponentProvider
     */
    public function testEveryDeclaredStyleSlotIsNamedInItsReadme(string $component): void
    {
        $slots  = $this->slots($component);
        $readme = $this->readme($component);
        $this->assertNotEmpty(
            $slots,
            "{$component} is listed as composable but declares no style slots — "
            . 'add it to self::CHROME if that is intentional.'
        );

        $missing = [];
        foreach (array_keys($slots) as $slot) {
            // Boundary-anchored: a bare substring match would let --hero-bg be
            // satisfied by --hero-bg-position, so six slots across the theme
            // (--hero-bg, --hero-accent, --section-bg, --cta-bg, --cta-accent,
            // --stats-bg) could be deleted from a README with this still green.
            if (!preg_match('/' . preg_quote($slot, '/') . '(?![a-z0-9-])/', $readme)) {
                $missing[] = $slot;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "components/{$component}/README.md does not name these declared style slots: "
            . implode(', ', $missing)
            . '. A slot an agent cannot find in the README is a slot it will not use.'
        );
    }

    /**
     * The reverse direction: a README must not advertise as available a custom
     * property the schema does not declare. That is the failure mode a rename
     * leaves behind — the old name keeps living in prose and an agent writes it
     * into style_component, where it is rejected.
     *
     * Naming an undeclared property is NOT banned outright, because several of the
     * most useful disclosures do exactly that: "the remedy would be
     * `--testimonials-avatar-size`", "`--table-bg` does not exist". Those tell an
     * agent where the boundary is. The rule is that such a mention must be marked
     * absent IN THE SAME PARAGRAPH — a neutral mention reads as an offer, and that is
     * the failure this pins. Family shorthands (`--stats-number-*`, written with a
     * trailing hyphen) are skipped: they are prose, not a slot name.
     *
     * Known limit, stated rather than papered over: a markdown TABLE is one paragraph,
     * so an absence marker in one row excuses a neutral mention in another row of the
     * same table. Per-row scope would be a real improvement, not a defect this already
     * handles.
     *
     * @dataProvider composableComponentProvider
     */
    public function testReadmeNamesNoSlotTheSchemaDoesNotDeclare(string $component): void
    {
        $declared = array_keys($this->slots($component));
        // Deliberately does NOT include "stated default": that phrase is the section
        // heading, so it would excuse every mention in the table beneath it.
        $absence  = '/(do(es)? not exist|is no |are no |no slot|not shipped|not declared|'
                  . 'is ever\s+shipped|would be|not authorable|not among them)/i';

        // Paragraph scope, not line scope: markdown prose wraps, so a disclosure
        // and the name it discloses routinely land on different lines.
        $paragraphs = preg_split('/\n\s*\n/', $this->readme($component)) ?: [];

        $unmarked = [];
        foreach ($paragraphs as $paragraph) {
            preg_match_all('/--' . preg_quote($component, '/') . '-[a-z0-9-]+/', $paragraph, $m);
            foreach (array_unique($m[0]) as $name) {
                if (in_array($name, $declared, true)) {
                    continue;
                }
                // A family shorthand such as `--stats-number-*` keeps its trailing
                // hyphen once the wildcard is dropped; that is prose, not a name.
                if (str_ends_with($name, '-')) {
                    continue;
                }
                if (preg_match($absence, $paragraph)) {
                    continue;
                }
                $unmarked[] = $name;
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($unmarked)),
            "components/{$component}/README.md names custom properties the schema does "
            . 'not declare, in a paragraph that never marks them absent: '
            . implode(', ', array_unique($unmarked))
            . '. Either declare them, mark them absent, or stop naming them.'
        );
    }

    /**
     * The aggregate count in AI_CONTEXT.md, and the per-component breakdown beside
     * it, must equal what the schemas actually declare. This number was "232 style
     * slots across 10 components" for five minor versions while the real figure
     * moved; regenerating it by hand is exactly what keeps going wrong.
     */
    public function testAiContextSlotCensusMatchesTheSchemas(): void
    {
        $total  = 0;
        $counts = [];
        foreach (self::composableComponents() as $component) {
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
     * `--table-bg`, `--embed-bg` and `--logos-bg` are a DEFERRED family, not a
     * shipped one. Two things must hold at once and this pins both: no schema may
     * declare them (that would be the gate shipping by accident), and every doc
     * that names them must mark them absent in the same breath — the deferred
     * gate's entry criteria are documented precisely where an agent looks for the
     * slots, so a naive "never mention them" rule would forbid the disclosure the
     * issue asks for.
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

    // ── Providers ────────────────────────────────────────────────────────────

    public static function composableComponentProvider(): array
    {
        return array_map(static fn ($c) => [$c], self::composableComponents());
    }

    public static function allComponentProvider(): array
    {
        return array_map(static fn ($c) => [$c], self::allComponents());
    }
}
