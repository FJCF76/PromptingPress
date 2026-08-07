<?php
/**
 * tests/StoredCompositionAliasRenderTest.php
 *
 * The render-time legacy-name resolution contract (issue #575).
 *
 * This is the test class the ruling names as load-bearing: without it the
 * resolution mechanism is unpinned, and the failure mode it guards against is
 * SILENT. Under a clean break `restore_composition` (#233) would still SUCCEED and
 * still return ok:true while rendering a page stripped of its styling, because
 * pp_render_style_vars() drops an undeclared slot with a bare `continue` — no
 * finding, no warning, no log, no admin notice. A durability mechanism that returns
 * success and produces an unstyled page has not restored anything. So the assertion
 * that matters is not "the map has an entry", it is "the stored document still
 * PAINTS".
 *
 * The bounded rule the mechanism implements, verbatim:
 *
 *   A legacy name resolves at render IFF a shipped mechanism promises that the
 *   already-stored document will render. Today exactly one mechanism makes that
 *   promise (`restore_composition`, #233 — it restores the snapshot verbatim and
 *   reports findings, and it never blocks). No other legacy surface qualifies.
 *
 * Two surfaces, two shapes of legacy name:
 *
 *   SLOT NAME   pp_legacy_slot_aliases() (lib/wp.php) — ships EMPTY in #575, so
 *               these cases inject a SYNTHETIC pair through the map's filter. The
 *               mechanism is proven before ~40 real names move through it.
 *   PROP KEY    pp_legacy_prop_aliases() (lib/admin.php) — already populated with
 *               the live cta_text/cta_url -> button_text/button_url mapping, so
 *               these cases use real data.
 *
 * Both render through the EXACT loop templates/composition.php runs, so what is
 * asserted is what a visitor's browser receives, not an intermediate array.
 */

use PHPUnit\Framework\TestCase;

class StoredCompositionAliasRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'next_id'    => 100,
            'custom_css' => '',
            'filters'    => [],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_pp_test_store']['filters'] = [];
        parent::tearDown();
    }

    /**
     * Renders a stored composition exactly as templates/composition.php does:
     * read the stored items, promote `style` to the `__pp_style` prop, render each
     * component in order. The only difference is the read accessor
     * (pp_get_composition($id) rather than pp_composition(), which resolves the post
     * from the loop) — both route through pp_migrate_stored_composition().
     */
    private function renderStored(int $post_id): string
    {
        ob_start();
        foreach (pp_get_composition($post_id) as $item) {
            if (!isset($item['component'])) {
                continue;
            }
            $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
            $style = isset($item['style'])  && is_array($item['style'])  ? $item['style']  : [];
            if ($style) {
                $props['__pp_style'] = $style;
            }
            pp_get_component((string) $item['component'], $props);
        }
        return ob_get_clean();
    }

    /** Installs a synthetic legacy -> canonical slot pair for the duration of one test. */
    private function withSyntheticSlotAlias(string $component, string $legacy, string $canonical): void
    {
        $GLOBALS['_pp_test_store']['filters']['pp_legacy_slot_aliases'] = [
            $component => [$legacy => $canonical],
        ];
    }

    // ── Slot NAME resolution (synthetic pair, map ships empty) ───────────────

    /**
     * THE ruling's test. A composition stored with a legacy slot name still paints:
     * the declaration reaches the rendered style attribute under its CANONICAL name.
     *
     * Without the resolution this assertion fails silently in production — the slot
     * is simply absent from the style attribute and the page renders unstyled.
     */
    public function testStoredLegacySlotNameStillPaints(): void
    {
        $this->withSyntheticSlotAlias('hero', '--hero-legacy-bg', '--hero-bg');

        $id = pp_create_page('Legacy slot page', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Still painted'],
            'style'     => ['--hero-legacy-bg' => '#1a1a2e'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('--hero-bg: #1a1a2e', $html, 'the legacy slot must paint under its canonical name');
        $this->assertStringNotContainsString('--hero-legacy-bg', $html, 'the legacy name itself is never emitted');
    }

    /**
     * The mechanism must resolve ABOVE the declared-slot filter, not below it. If it
     * ran below, the legacy name would already have been dropped by the `continue`
     * and the alias would be dead code that still passes a map-shaped unit test.
     * Proven by the negative: with the alias absent, the same stored document paints
     * nothing.
     */
    public function testWithoutTheAliasTheSameStoredSlotIsSilentlyDropped(): void
    {
        $id = pp_create_page('Unaliased legacy slot page', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Unstyled'],
            'style'     => ['--hero-legacy-bg' => '#1a1a2e'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('#1a1a2e', $html, 'an unmapped undeclared slot is dropped');
        $this->assertStringContainsString('Unstyled', $html, 'and the page still renders — the drop is silent');
    }

    /**
     * CANONICAL-WINS, mirroring the prop-key alias contract: when a stored style map
     * carries BOTH names, the canonical declaration is the author's explicit value
     * and the stale legacy one must not overwrite it.
     */
    public function testCanonicalSlotWinsWhenBothNamesAreStored(): void
    {
        $this->withSyntheticSlotAlias('hero', '--hero-legacy-bg', '--hero-bg');

        $id = pp_create_page('Both slot names', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Both'],
            'style'     => ['--hero-legacy-bg' => '#111111', '--hero-bg' => '#222222'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('--hero-bg: #222222', $html, 'the canonical value wins');
        $this->assertStringNotContainsString('#111111', $html, 'the stale legacy value is dropped, not emitted twice');
    }

    /**
     * A resolved legacy name is still a stored value that never passed current
     * write-time validation, so it must clear the #330 render boundary like any
     * other. Resolution renames; it does not launder.
     */
    public function testResolvedLegacySlotStillClearsTheRenderBoundary(): void
    {
        $this->withSyntheticSlotAlias('hero', '--hero-legacy-bg', '--hero-bg');

        $id = pp_create_page('Hostile legacy slot', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Guarded'],
            'style'     => ['--hero-legacy-bg' => 'url(https://example.test/ping)'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('example.test', $html, 'the #330 reject set still applies after resolution');
        $this->assertStringContainsString('Guarded', $html, 'and the page still renders');
    }

    /** The alias map is scoped per component — it never leaks across components. */
    public function testSlotAliasIsScopedToItsComponent(): void
    {
        $this->withSyntheticSlotAlias('hero', '--hero-legacy-bg', '--hero-bg');

        $id = pp_create_page('Cross-component leak', 'draft');
        pp_update_composition($id, [[
            'component' => 'section',
            'props'     => ['title' => 'Section', 'body' => 'Body copy.'],
            'style'     => ['--hero-legacy-bg' => '#1a1a2e'],
        ]]);

        $this->assertStringNotContainsString('#1a1a2e', $this->renderStored($id), 'a hero alias must not resolve on a section');
    }

    /** The map ships EMPTY: #575 lands the mechanism, the vocabulary gate lands the names. */
    public function testShippedSlotAliasMapIsEmpty(): void
    {
        $this->assertSame([], pp_legacy_slot_aliases(), '#575 applies no renames — the map ships empty');
    }

    // ── Map sanitizing: the mechanism must not become the failure ────────────
    //
    // The map's only public entry point is a filter, i.e. arbitrary third-party
    // code on the PUBLIC RENDER PATH. Every case below produced either a fatal or
    // the exact silent unstyled-page failure this mechanism exists to prevent,
    // before pp_legacy_slot_aliases() shape-sanitized its input.

    /**
     * A nested-array alias target reached `isset($slots[$name])` with a non-string
     * key: "TypeError: Cannot access offset of type array on array" — a white
     * screen on a public page, from the mechanism's own extension point.
     */
    public function testMalformedFilterValuesCannotFatalTheRenderPath(): void
    {
        foreach ([
            'nested array target' => ['hero' => ['--hero-legacy-bg' => ['--hero-bg']]],
            'scalar entry map'    => ['hero' => 'nonsense'],
            'int target'          => ['hero' => ['--hero-legacy-bg' => 5]],
            'int component key'   => [7 => ['--hero-legacy-bg' => '--hero-bg']],
            'empty names'         => ['hero' => ['' => '--hero-bg', '--hero-legacy-bg' => '']],
            'not a map at all'    => 'not-a-map',
        ] as $label => $bad) {
            $GLOBALS['_pp_test_store']['filters']['pp_legacy_slot_aliases'] = $bad;

            $id = pp_create_page("Bad filter: {$label}", 'draft');
            pp_update_composition($id, [[
                'component' => 'hero',
                'props'     => ['title' => 'Still here'],
                'style'     => ['--hero-bg' => '#123456'],
            ]]);

            $html = $this->renderStored($id);
            $this->assertStringContainsString('--hero-bg: #123456', $html, "{$label}: a valid sibling slot must still paint");
            $this->assertStringContainsString('Still here', $html, "{$label}: the page must still render");
        }
    }

    /**
     * IDENTITY (legacy === canonical). Canonical-wins would see the very key being
     * iterated, hit `continue`, and drop the declaration — silently unstyled.
     */
    public function testIdentityAliasIsDiscardedAndTheSlotStillPaints(): void
    {
        $this->withSyntheticSlotAlias('hero', '--hero-bg', '--hero-bg');
        $this->assertSame([], pp_legacy_slot_aliases(), 'an identity entry is not a rename');

        $id = pp_create_page('Identity alias', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Painted'],
            'style'     => ['--hero-bg' => '#1a1a2e'],
        ]]);

        $this->assertStringContainsString('--hero-bg: #1a1a2e', $this->renderStored($id));
    }

    /**
     * CHAIN (A -> B -> C). Resolution is deliberately single-hop, so a chain would
     * resolve A to B, find B undeclared, and paint nothing. The chained entry is
     * dropped rather than half-applied.
     */
    public function testChainedAliasDropsTheBrokenEdgeAndKeepsTheWorkingOne(): void
    {
        // A -> B -> C. `--hero-a => --hero-b` is the UNSAFE edge: one hop lands on
        // --hero-b, which is not a declared slot, so nothing paints.
        // `--hero-b => --hero-bg` is a perfectly good single-hop rename.
        // Dropping the terminal edge instead (the first implementation did exactly
        // that) discards the only working mapping and keeps the broken one.
        $GLOBALS['_pp_test_store']['filters']['pp_legacy_slot_aliases'] = [
            'hero' => ['--hero-a' => '--hero-b', '--hero-b' => '--hero-bg'],
        ];
        $map = pp_legacy_slot_aliases()['hero'] ?? [];
        $this->assertArrayNotHasKey('--hero-a', $map, 'the non-terminal hop is the unsafe edge');
        $this->assertSame('--hero-bg', $map['--hero-b'] ?? null, 'the terminal rename must survive');

        $id = pp_create_page('Chained alias', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Chain'],
            'style'     => ['--hero-b' => '#1a1a2e'],
        ]]);
        $this->assertStringContainsString('--hero-bg: #1a1a2e', $this->renderStored($id), 'the surviving edge paints');
    }

    /** A two-node cycle drops BOTH edges, whatever the iteration order. */
    public function testCyclicAliasEntriesAreFullyDiscarded(): void
    {
        $GLOBALS['_pp_test_store']['filters']['pp_legacy_slot_aliases'] = [
            'hero' => ['--hero-a' => '--hero-b', '--hero-b' => '--hero-a'],
        ];
        $this->assertSame([], pp_legacy_slot_aliases(), 'a cycle resolves to nothing and must not half-apply');
    }

    /**
     * CANONICAL-WINS is conditional on the canonical declaration actually painting.
     * Presence is not authority: a stored document holding a VALID legacy value and
     * an unrenderable canonical one (rejected by the #330 boundary, empty, or
     * undeclared) must keep its paint, not lose both declarations.
     */
    public function testLegacyValueCarriesThePaintWhenTheCanonicalOneCannotRender(): void
    {
        $this->withSyntheticSlotAlias('hero', '--hero-legacy-bg', '--hero-bg');

        foreach ([
            'rejected by the #330 boundary' => 'url(https://example.test/ping)',
            'empty'                         => '',
        ] as $label => $canonicalValue) {
            $id = pp_create_page("Unrenderable canonical: {$label}", 'draft');
            pp_update_composition($id, [[
                'component' => 'hero',
                'props'     => ['title' => 'Painted'],
                'style'     => ['--hero-legacy-bg' => '#1a1a2e', '--hero-bg' => $canonicalValue],
            ]]);

            $html = $this->renderStored($id);
            $this->assertStringContainsString('--hero-bg: #1a1a2e', $html, "{$label}: the legacy value must carry the paint");
            $this->assertStringNotContainsString('example.test', $html, "{$label}: the reject set still applies");
        }
    }

    /**
     * ASYMMETRY, pinned deliberately. The slot-NAME map resolves at RENDER ONLY;
     * every whole-composition validation still rejects the legacy name. A page
     * carrying one therefore RENDERS but cannot be edited or saved.
     *
     * This is in scope for #575 only because the map ships EMPTY and no name has
     * been renamed. The canonical-vocabulary gate that lands the first real rename
     * MUST close it in the same change — read-path resolution, or a validation-time
     * alias check — or that gate ships pages that paint correctly and are frozen.
     * This test exists so that gate cannot miss it: it fails the moment a real
     * alias entry is added without the write-path half.
     */
    public function testSlotAliasIsRenderOnlyAndTheWritePathStillRejectsTheLegacyName(): void
    {
        $this->withSyntheticSlotAlias('hero', '--hero-legacy-bg', '--hero-bg');

        $id = pp_create_page('Render-only asymmetry', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero',    'props' => ['title' => 'Legacy'], 'style' => ['--hero-legacy-bg' => '#1a1a2e']],
            ['component' => 'section', 'props' => ['title' => 'Sibling', 'body' => 'Edit me.']],
        ]);

        // It paints...
        $this->assertStringContainsString('--hero-bg: #1a1a2e', $this->renderStored($id));

        // ...but a targeted edit to the UNTOUCHED sibling is rejected, naming a slot
        // the operator never typed on a band they never touched.
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Edited'],
        ]);
        $this->assertFalse(
            $result['ok'],
            'KNOWN AND SCOPED: slot-name aliases do not resolve at write. If this now passes, '
            . 'the write-path half has landed — delete this pin and pin the new symmetry instead.'
        );
        $this->assertStringContainsString('--hero-legacy-bg', (string) ($result['error'] ?? ''));
    }

    // ── Prop KEY resolution (the live cta_text -> button_text mapping) ───────

    /**
     * A stored composition carrying a legacy PROP name renders the AUTHORED value,
     * not the schema default. components/cta/cta.php reads $props['button_text'] /
     * $props['button_url'] only, so without resolution a legacy-shaped cta band
     * renders the hardcoded 'Get Started' / '#' — a live page quietly losing its
     * call to action and its destination.
     */
    public function testStoredLegacyPropRendersTheAuthoredValueNotTheSchemaDefault(): void
    {
        $id = pp_create_page('Legacy prop page', 'draft');
        // Thin writer, no validation — persists the legacy shape as a live install
        // holds it (and as restore_composition can replay it).
        pp_update_composition($id, [[
            'component' => 'cta',
            'props'     => ['cta_text' => 'View on GitHub', 'cta_url' => 'https://example.com/repo'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('View on GitHub', $html, 'the authored label renders');
        $this->assertStringContainsString('https://example.com/repo', $html, 'the authored destination renders');
        $this->assertStringNotContainsString('Get Started', $html, 'the schema default must not win over authored content');
    }

    /** CANONICAL-WINS on the prop surface too (the shipped #495 rule, pinned here at render). */
    public function testCanonicalPropWinsWhenBothKeysAreStored(): void
    {
        $id = pp_create_page('Both prop keys', 'draft');
        pp_update_composition($id, [[
            'component' => 'cta',
            'props'     => [
                'cta_text'    => 'Stale label',
                'button_text' => 'Fresh label',
                'button_url'  => 'https://example.com/fresh',
            ],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('Fresh label', $html, 'the canonical value wins');
        $this->assertStringNotContainsString('Stale label', $html, 'the stale legacy value is dropped');
    }
}
