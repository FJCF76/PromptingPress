<?php

use PHPUnit\Framework\TestCase;

/**
 * Schema contract: enums SHARED across component schemas must describe every value
 * identically. This is the test that would have caught the #442 drift, where the
 * `theme` enum's `dark` value was documented as "dark surface" in 3 schemas and
 * "surface background with borders" in 5 — the AI's belief depended on which schema
 * it read last. The rule is generic (any prop name shared across 2+ components), so
 * it also guards `layout`, `card_emphasis`, or any future shared enum.
 *
 * It also pins the #442 migration outcome directly: the `theme` enum advertises
 * `default | muted | inverted` (no `dark`), byte-identical across all eight bands.
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
     * Components that share the SAME enum — the same prop name AND the same set of
     * values — must describe it identically. Two enums that merely share a name but
     * carry different value sets (e.g. `layout`, whose values differ per component)
     * are genuinely different enums and are allowed to differ; grouping by value set
     * lets those through while still pinning a true shared vocabulary like `theme`.
     *
     * This is precisely the test that fails on the #442 drift: all eight `theme`
     * enums declared the same values ([default, dark, inverted]) yet 3 described the
     * band as "dark surface" and 5 as "surface background with borders" — one group,
     * divergent descriptions.
     */
    public function testSharedEnumPropsAreDescribedIdenticallyAcrossComponents(): void
    {
        $schemas = $this->loadSchemas();

        // prop name => value-set-signature => [component => description]
        $groups = [];
        foreach ($schemas as $component => $schema) {
            foreach (($schema['props'] ?? []) as $propName => $def) {
                if (($def['type'] ?? null) !== 'enum') {
                    continue;
                }
                $signature = json_encode($def['values'] ?? null);
                $groups[$propName][$signature][$component] = $def['description'] ?? null;
            }
        }

        // `theme` must be a real shared enum: same value set across 2+ components.
        $this->assertArrayHasKey('theme', $groups, 'theme enum not found in any schema');
        $themeSharedGroups = array_filter($groups['theme'], static fn ($comps) => count($comps) >= 2);
        $this->assertNotEmpty($themeSharedGroups, 'theme should share one value set across components');

        $checkedTheme = false;
        foreach ($groups as $propName => $bySignature) {
            foreach ($bySignature as $signature => $componentsToDesc) {
                if (count($componentsToDesc) < 2) {
                    continue; // a per-component enum (or a lone occurrence) — nothing shared to enforce
                }
                if ($propName === 'theme') {
                    $checkedTheme = true;
                }
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

        $this->assertTrue($checkedTheme, 'the shared theme enum was not actually asserted');
    }

    /**
     * Pins the #442 migration: `theme` advertises muted, not dark, everywhere.
     */
    public function testThemeEnumAdvertisesMutedNotDark(): void
    {
        $bandComponents = ['cta', 'section', 'faq', 'grid', 'embed', 'logos', 'stats', 'testimonials'];
        $schemas        = $this->loadSchemas();

        foreach ($bandComponents as $component) {
            $this->assertArrayHasKey($component, $schemas, "missing schema for '{$component}'");
            $theme = $schemas[$component]['props']['theme'] ?? null;
            $this->assertIsArray($theme, "'{$component}' has no theme prop");
            $this->assertSame(
                ['default', 'muted', 'inverted'],
                $theme['values'],
                "'{$component}' theme enum must advertise default|muted|inverted (no dark) — #442"
            );
            $this->assertNotContains('dark', $theme['values'], "'{$component}' still advertises the removed 'dark' (#605)");
            // The description must steer toward inverted for a dark band, and must not
            // present 'dark' as an offered value.
            $this->assertStringContainsString('muted', $theme['description']);
            $this->assertStringContainsString('inverted', $theme['description']);
        }
    }

    /**
     * Schema contract: the band padding slots must state ONE truthful default
     * everywhere. This is the test that would have caught the #446 drift, where
     * six older band schemas (section, grid, cta, stats, faq, testimonials) still
     * declared `"default": "var(--space-xl)"` on their `--*-padding-top/bottom`
     * slots even though the CSS has routed those slots through
     * `var(--pp-band-padding)` since #431 — while the three #438 schemas (table,
     * logos, embed) already declared the truthful `var(--pp-band-padding)`. The
     * `default` field is descriptive metadata the AI reads to predict unset output
     * (never emitted as CSS — see pp_render_style_vars(), which reads only a slot's
     * `type`), so a stale default teaches the AI wrong geometry for every band.
     *
     * The band set is hardcoded rather than derived from CSS on purpose: these
     * nine are the canonical bands that route padding through the shared
     * `--pp-band-padding` rhythm. `hero` ALSO has `--hero-padding-top/bottom`
     * slots, but its CSS falls back to `var(--space-xl)` / `var(--space-2xl)`
     * (NOT the band rhythm), so its `var(--space-xl)` default is truthful and it
     * is deliberately excluded — the drift class this test guards is band-only.
     */
    public function testBandPaddingSlotDefaultsAreUniformAndTruthful(): void
    {
        // The canonical band components: their `--*-padding-top/bottom` slots
        // route through `--pp-band-padding`. `hero` is NOT a band here (see docblock).
        // Intentional delta from the 8-member $bandComponents in
        // testThemeEnumAdvertisesMutedNotDark() above: `table` has no `theme` prop
        // (so it is absent from the #442 theme list) but DOES route padding through
        // the band rhythm, so it belongs here. Do not "sync" the two lists.
        $bandComponents = ['section', 'grid', 'cta', 'stats', 'faq', 'testimonials', 'table', 'logos', 'embed'];
        $schemas        = $this->loadSchemas();

        $expected = 'var(--pp-band-padding)';

        // family suffix (`padding-top` | `padding-bottom`) => [component => default]
        $families = [];
        foreach ($bandComponents as $component) {
            $this->assertArrayHasKey($component, $schemas, "missing schema for '{$component}'");
            $slots = $schemas[$component]['styling']['style_slots'] ?? null;
            $this->assertIsArray($slots, "'{$component}' has no style_slots");

            $found = 0;
            foreach ($slots as $slotName => $slotDef) {
                if (!preg_match('/-(padding-(?:top|bottom))$/', $slotName, $m)) {
                    continue;
                }
                $this->assertArrayHasKey('default', $slotDef, "'{$component}' slot '{$slotName}' has no default");
                $families[$m[1]][$component] = $slotDef['default'];
                $found++;
            }
            // Every band declares exactly a top and a bottom band-padding slot.
            $this->assertSame(2, $found, "'{$component}' must declare exactly one padding-top and one padding-bottom band slot");
        }

        $this->assertArrayHasKey('padding-top', $families, 'no band padding-top slots found');
        $this->assertArrayHasKey('padding-bottom', $families, 'no band padding-bottom slots found');

        foreach ($families as $family => $componentToDefault) {
            $uniqueDefaults = array_unique(array_values($componentToDefault), SORT_REGULAR);
            $this->assertCount(
                1,
                $uniqueDefaults,
                sprintf(
                    "Band slot family '%s' has divergent `default` values across components (%s). "
                    . "Every band's padding default must be the same, truthful `%s` — the shared "
                    . "rhythm the CSS actually routes through since #431/#438 (#446).",
                    $family,
                    implode(', ', array_map(
                        static fn ($c, $d) => "{$c}={$d}",
                        array_keys($componentToDefault),
                        array_values($componentToDefault)
                    )),
                    $expected
                )
            );
            $this->assertSame(
                $expected,
                $uniqueDefaults[0],
                sprintf(
                    "Band slot family '%s' default is '%s' but must be the truthful '%s' (#446). "
                    . "The CSS routes these slots through the shared band rhythm; declaring "
                    . "`var(--space-xl)` teaches the AI the wrong unset geometry.",
                    $family,
                    $uniqueDefaults[0],
                    $expected
                )
            );
        }

        // Guard the exclusion: hero must NOT silently adopt the band default on
        // EITHER edge — if it ever does, either hero became a band (update this list)
        // or someone mis-edited it (including a one-sided top-only/bottom-only edit).
        // Its truthful default is the space-scale token.
        $heroSlots = $schemas['hero']['styling']['style_slots'] ?? [];
        foreach (['--hero-padding-top', '--hero-padding-bottom'] as $heroSlot) {
            $this->assertNotSame(
                $expected,
                $heroSlots[$heroSlot]['default'] ?? null,
                "hero is not a band (its CSS falls back to var(--space-xl)/var(--space-2xl)); "
                . "if hero now routes through --pp-band-padding, add it to \$bandComponents (#446). "
                . "Offending slot: {$heroSlot}."
            );
        }
    }
}
