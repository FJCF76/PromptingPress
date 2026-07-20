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
            $this->assertNotContains('dark', $theme['values'], "'{$component}' still advertises the deprecated 'dark'");
            // The description must steer toward inverted for a dark band, and must not
            // present 'dark' as an offered value.
            $this->assertStringContainsString('muted', $theme['description']);
            $this->assertStringContainsString('inverted', $theme['description']);
        }
    }
}
