<?php

use PHPUnit\Framework\TestCase;

/**
 * THE SHARED `theme` ENUM AND THE BAND-PADDING SLOT FAMILY — RETIRED WITH GRID (#1101),
 * AND THE TWO CLAIMS RE-ASSERTED AT THE ADDRESS THEY LIVE AT NOW.
 *
 * WHAT THE THREE TESTS IN THIS FILE PROVED, so the record survives the subject.
 *
 *   1. `testSharedEnumPropsAreDescribedIdenticallyAcrossComponents` — a prop name AND
 *      value set shared by 2+ schemas must carry a byte-identical `description` in every
 *      one of them. This is the test that would have caught the #442 drift, where all
 *      eight `theme` enums advertised the same three values while THREE described `dark`
 *      as "dark surface" and FIVE as "surface background with borders": the authoring
 *      model's belief about a band depended on which schema it happened to read last.
 *      The rule was deliberately generic — grouped by VALUE SET, so a `layout` whose
 *      values genuinely differ per component was never forced into false agreement.
 *
 *   2. `testThemeEnumAdvertisesMutedNotDark` — the #442/#605 migration outcome: the enum
 *      advertises `default | muted | inverted` and never the removed `dark` alias, on
 *      every component that declared it, with descriptions that steer a dark band toward
 *      `inverted`.
 *
 *   3. `testBandPaddingSlotDefaultsAreUniformAndTruthful` — every band's
 *      `--*-padding-top` / `--*-padding-bottom` slot declared the SAME, TRUTHFUL default,
 *      `var(--pp-band-padding)`. This is the #446 drift: six band schemas still declared
 *      `var(--space-xl)` long after #431 routed those slots through the shared rhythm, so
 *      the `default` field — which is descriptive metadata the AI reads to predict unset
 *      output, never emitted as CSS — taught the wrong geometry for every band. hero was
 *      excluded and guarded as excluded, because its padding genuinely falls back to the
 *      space scale rather than to the band rhythm.
 *
 * WHY ALL THREE SUBJECTS ARE GONE. `theme` left component by component across the v2
 * rebuilds — testimonials #958, section #1023, cta #1026, faq #1046, embed and table
 * #1066, stats and logos #1066 PR2 — and GRID WAS THE LAST DECLARER; #1101 retired it
 * with the rest of grid's v1 prop surface. A dark band is the `_band` role's
 * `background.fill` now, plus the ink the retirement route names. In the same change grid
 * became the last component to declare `styling.style_slots` at all, so the band-padding
 * slot FAMILY has no member left either: nothing in the theme declares a slot, so there
 * is no slot `default` anywhere that could drift from any other.
 *
 * WHERE EACH CLAIM WENT, and both are asserted below rather than assumed:
 *
 *   - The shared-enum rule keeps its whole generic body (the loop still runs over every
 *     enum in every schema) and gains an explicit pin that NO prop name is shared across
 *     components today. An inert loop reading as a passing audit is the vacuous-pass
 *     shape this repo refuses; the emptiness is stated out loud so re-declaring a shared
 *     enum turns the loop back on with a failure rather than in silence.
 *   - The band-rhythm claim survives INTACT and stronger: it is the `_band` role's
 *     `spacing.padding-top` / `padding-bottom` defaults, which must be the shared
 *     `@pp-band-padding` on every band, derived from the schemas rather than listed. The
 *     hero exclusion survives with it, as does the rule that a DEFAULT and the value the
 *     page actually renders must agree — the #446 defect class, one vocabulary later.
 */
class SchemaThemeConsistencyTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>  component name => decoded schema
     */
    private function loadSchemas(): array
    {
        $root    = dirname(__DIR__);
        $schemas = [];
        foreach (glob($root . '/components/*/schema.json') as $file) {
            $name = basename(dirname($file));
            $data = json_decode(file_get_contents($file), true);
            $this->assertIsArray($data, "schema.json for '{$name}' is not valid JSON");
            $schemas[$name] = $data;
        }
        $this->assertNotEmpty($schemas, 'no component schemas found');
        return $schemas;
    }

    /**
     * The #442 rule, kept whole, over a vocabulary that no longer shares anything.
     *
     * The loop below is the original: group every enum by prop name AND value set, and
     * require one description per group. What changed is that no group has two members,
     * so the loop is inert — and an inert loop reporting green is indistinguishable from
     * an audit that checked something. Three things are therefore asserted directly:
     * the scan SAW enums (10 of them ship, on cta, grid, hero, section and testimonials),
     * NO prop is shared across components, and `theme` in particular is declared by
     * nobody — the fact that retired this file's headline subject.
     */
    public function testNoEnumIsSharedAcrossComponentsSoTheIdenticalDescriptionRuleIsInert(): void
    {
        $schemas = $this->loadSchemas();

        // prop name => value-set-signature => [component => description]
        $groups     = [];
        $enumsSeen  = 0;
        foreach ($schemas as $component => $schema) {
            foreach (($schema['props'] ?? []) as $propName => $def) {
                if (($def['type'] ?? null) !== 'enum') {
                    continue;
                }
                $enumsSeen++;
                $signature = json_encode($def['values'] ?? null);
                $groups[$propName][$signature][$component] = $def['description'] ?? null;
            }
        }

        // ANTI-VACUITY, FIRST: the derivation must still be able to SEE enums, or every
        // claim below is a statement about a broken scan rather than about the schemas.
        $this->assertGreaterThan(
            0,
            $enumsSeen,
            'the enum scan found nothing at all — props/type/enum parsing has broken, and '
            . 'the emptiness pinned below would then prove nothing'
        );

        // `theme` IS DECLARED BY NOBODY SINCE #1101. grid was the last of the eight
        // declarers; a dark band is the `_band` role's `background.fill` now.
        $this->assertArrayNotHasKey(
            'theme',
            $groups,
            'a component declares a `theme` enum again. It was retired component by '
            . 'component across the v2 rebuilds and left entirely with grid at #1101; if '
            . 'this is deliberate, restore the muted-not-dark guard (#442/#605) this '
            . 'assertion replaced in the same commit, or the enum ships with no guard at all'
        );

        // AND NO OTHER PROP NAME IS SHARED EITHER, so the loop below has no subject. It is
        // kept whole rather than deleted: a second declarer of any enum name turns it back
        // on, and this pin is what makes that a deliberate edit rather than a silent one.
        $shared = [];
        foreach ($groups as $propName => $bySignature) {
            foreach ($bySignature as $signature => $componentsToDesc) {
                if (count($componentsToDesc) >= 2) {
                    $shared[] = $propName . ' ' . $signature;
                }
            }
        }
        sort($shared);
        $this->assertSame(
            [],
            $shared,
            'an enum prop is shared across 2+ components with one value set again — the '
            . 'identical-description loop below is live, and this pin is the notice'
        );

        // The loop itself, unchanged and inert. `layout` is declared by five components
        // and reaches here every run; it never groups, because its value sets genuinely
        // differ per component, which is the distinction the value-set signature exists
        // to preserve.
        $checked = 0;
        foreach ($groups as $propName => $bySignature) {
            foreach ($bySignature as $signature => $componentsToDesc) {
                if (count($componentsToDesc) < 2) {
                    continue; // a per-component enum (or a lone occurrence) — nothing shared to enforce
                }
                $checked++;
                $uniqueDescriptions = array_unique(array_values($componentsToDesc), SORT_REGULAR);
                $this->assertCount(
                    1,
                    $uniqueDescriptions,
                    sprintf(
                        "Shared enum '%s' (values %s) has divergent descriptions across components (%s). "
                        . "Components sharing the same enum must document each value identically (#442).",
                        $propName,
                        $signature,
                        implode(', ', array_keys($componentsToDesc))
                    )
                );
            }
        }
        $this->assertSame(0, $checked, 'the shared-enum loop did work while the roster above said it could not');
    }

    /**
     * THE RETIREMENT IS ROUTED, NOT MERELY ABSENT — which is what stops #442's subject
     * from vanishing without an answer for the author who meets `theme` on an aged page.
     *
     * `testThemeEnumAdvertisesMutedNotDark` used to pin the enum's values on every
     * declarer. Nobody declares it, so what remains to guard is the other half of a
     * retirement: each component that HAD the prop names, in `retired_props`, the v2
     * surface that replaced it. SchemaValidationTest checks those routes in both
     * directions (a key still present in `props`, or a route naming a role the component
     * does not declare, fails there); this pins the ROSTER, so a component cannot quietly
     * drop the route and leave the name unexplained.
     */
    public function testEveryFormerThemeDeclarerStillNamesTheRouteThatReplacedIt(): void
    {
        $schemas = $this->loadSchemas();

        $routed  = [];
        foreach ($schemas as $component => $schema) {
            $this->assertArrayNotHasKey(
                'theme',
                $schema['props'] ?? [],
                "'{$component}' declares a live `theme` prop again — see the docblock at the top of this file"
            );
            if (isset($schema['retired_props']['theme'])) {
                $route = (string) $schema['retired_props']['theme'];
                $this->assertStringContainsString(
                    '_band',
                    $route,
                    "'{$component}' retires `theme` without naming the `_band` role, which is "
                    . 'where a band tone is expressed on v2 — a route that does not route is '
                    . 'a dead end wearing an explanation'
                );
                $routed[] = $component;
            }
        }
        sort($routed);

        // EXACT, NOT A FLOOR. The eight bands that advertised the enum plus faq, whose own
        // rebuild retired it the same way. A shrink means a component dropped the route and
        // left the name unexplained; a growth means a new component retired a prop it never
        // declared, which is a schema mistake rather than a migration.
        $this->assertSame(
            ['cta', 'embed', 'faq', 'grid', 'logos', 'section', 'stats', 'testimonials'],
            $routed,
            'the components whose `retired_props` still explain where `theme` went'
        );
    }

    /**
     * #446's CLAIM, RE-ASSERTED ON THE ROLE DEFAULTS — the same defect class, one
     * vocabulary later.
     *
     * The original pinned that every band's two padding SLOTS declared the same truthful
     * `var(--pp-band-padding)` default, because a stale `default` teaches the authoring
     * model geometry the page does not render. Not one slot is declared anywhere in the
     * theme since #1101, so the slot half has no subject — but the claim never depended
     * on the slot: it is that every band states ONE shared rhythm, that the statement is
     * truthful, and that hero's deliberate opt-out is not folded in by a later
     * "consistency" pass.
     *
     * DERIVED, NOT LISTED, so a component rebuilt later is covered the moment it lands.
     */
    public function testEveryBandStatesTheOneSharedPaddingRhythmAndHeroStillOptsOut(): void
    {
        $schemas  = $this->loadSchemas();
        $expected = '@pp-band-padding';

        // CHROME IS NOT A BAND and never was: nav has no band padding at all, and footer's
        // is its own @space-lg chrome rhythm. hero is the documented band-shaped exclusion
        // (its own @space-2xl/@space-xl tiers), guarded explicitly below rather than merely
        // skipped here.
        $exceptions = ['nav', 'footer', 'hero'];

        $bands = [];
        foreach ($schemas as $component => $schema) {
            if (in_array($component, $exceptions, true)) {
                continue;
            }
            $spacing = $schema['roles']['_band']['defaults']['spacing'] ?? [];
            foreach (['padding-top', 'padding-bottom'] as $edge) {
                $this->assertSame(
                    $expected,
                    $spacing[$edge] ?? null,
                    sprintf(
                        "'%s' must state the shared band rhythm on `_band` -> spacing.%s. "
                        . 'Every band declaring the SAME truthful value is what #446 closed: a '
                        . 'per-band literal is a default the page does not render, and the '
                        . 'authoring model reads defaults to predict unset output.',
                        $component,
                        $edge
                    )
                );
            }
            $bands[] = $component;
        }
        sort($bands);

        // Fail-closed AND exact, for the same reason the roster above is: a shrink means a
        // band stopped stating the rhythm, a growth is a new band to review here.
        $this->assertSame(
            ['cta', 'embed', 'faq', 'grid', 'logos', 'section', 'stats', 'table', 'testimonials'],
            $bands,
            'the nine bands that route the shared --pp-band-padding rhythm'
        );

        // THE EXCLUSION, GUARDED ON BOTH EDGES — including a one-sided edit, which is how
        // the original found its way into the slot version of this test. hero's truthful
        // default is the space scale, and it carries a breakpoint map rather than one value.
        $heroSpacing = $schemas['hero']['roles']['_band']['defaults']['spacing'] ?? [];
        foreach (['padding-top', 'padding-bottom'] as $edge) {
            $this->assertNotSame(
                $expected,
                $heroSpacing[$edge] ?? null,
                "hero is not a band (its padding falls back to the space scale, not the band "
                . "rhythm); if hero now routes @pp-band-padding, remove it from the exception "
                . "list above deliberately (#446). Offending edge: {$edge}."
            );
            $this->assertIsArray(
                $heroSpacing[$edge] ?? null,
                "hero's {$edge} is a per-tier map (@space-2xl desktop, @space-xl below), which "
                . 'is the shape that made it an exclusion rather than an oversight'
            );
        }

        // AND THE SLOT HALF IS EMPTY, ASSERTED RATHER THAN ASSUMED. The padding slot family
        // is the reason this file existed at all; nothing declares one, and a re-added slot
        // map must fail here rather than quietly reopen a second place to state the rhythm.
        $declarers = [];
        foreach ($schemas as $component => $schema) {
            if (($schema['styling']['style_slots'] ?? []) !== []) {
                $declarers[] = $component;
            }
        }
        $this->assertSame(
            [],
            $declarers,
            'a component declares `styling.style_slots` again. The band-padding slot family '
            . 'left with grid at #1101; two places to state one rhythm is exactly the drift '
            . '#446 closed'
        );
    }
}
