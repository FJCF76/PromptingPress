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
 *   SLOT NAME   pp_legacy_slot_aliases() (lib/wp.php) — shipped EMPTY in #575 and
 *               POPULATED by #576 with 51 canonical-vocabulary renames. The
 *               mechanism cases still inject a SYNTHETIC pair through the map's
 *               filter (they are about the mechanism, not any one name); the
 *               vocabulary cases use REAL renamed pairs, as #576 requires.
 *   PROP KEY    pp_legacy_prop_aliases() (lib/admin.php) — the live
 *               cta_text/cta_url -> button_text/button_url mapping (#495), extended
 *               by #576 with hero's button family, cta.text -> body and
 *               heading_align -> title_align.
 *
 * SYMMETRY (#594, closed by #576 in the same change as the first rename). #575
 * resolved slot names at RENDER only, so a document carrying one painted correctly
 * and could not be edited or saved — every action validates the WHOLE composition and
 * the slot validator did not consult the map. Both maps now resolve on every
 * composition READ as well. The boundary is pinned too: resolution covers the
 * already-stored document, it does not let a NEW write author a legacy slot name.
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
    /**
     * SUPERSEDES testShippedSlotAliasMapIsEmpty (#575 -> #576).
     *
     * #575 shipped the map EMPTY and pinned that fact. #576 populates it with the
     * canonical-vocabulary renames, so the honest pin is no longer "empty" but
     * "coherent": every legacy name must be gone from its component's schema and every
     * canonical target must actually be a declared slot. A dangling target is the
     * failure this map exists to prevent — pp_render_style_vars() drops an undeclared
     * name with a bare `continue`, so a typo'd target renders an unstyled band while
     * every action still returns ok:true.
     */
    public function testShippedSlotAliasMapResolvesOnlyToDeclaredSlots(): void
    {
        $map = pp_legacy_slot_aliases();
        $this->assertNotEmpty($map, '#576 populates the map — an empty map means no rename resolves');

        $total = 0;
        foreach ($map as $component => $pairs) {
            $slots = pp_get_style_slots($component);
            $this->assertNotEmpty($slots, "component `{$component}` declares no style slots");
            foreach ($pairs as $legacy => $canonical) {
                $total++;
                $this->assertArrayHasKey(
                    $canonical,
                    $slots,
                    "alias {$component}.{$legacy} -> {$canonical} points at a slot the schema does not declare"
                );
                $this->assertArrayNotHasKey(
                    $legacy,
                    $slots,
                    "{$component}.{$legacy} is aliased away yet still declared — one of the two is wrong"
                );
            }
        }
        $this->assertSame(51, $total, 'the canonical-vocabulary rename count changed — update the audit and this pin together');
    }

    /**
     * The ratified rename table's grid-step SWAP is deliberately NOT in the map (#576,
     * #570 decision record Addendum #3). The table asked for
     * `--grid-step-color` -> `--grid-step-bg` AND `--grid-step-text-color` ->
     * `--grid-step-color`; a swap cannot be carried by a single-hop alias map, so only
     * the FILL rename shipped and `--grid-step-text-color` kept its name.
     *
     * Pinned because the failure mode of "just add the second entry later" is silent:
     * the CHAIN sanitizer would discard it, and forcing it past the sanitizer would make
     * the new canonical `--grid-step-color` un-authorable (every read rewrites it).
     */
    public function testGridStepSwapIsNotInTheMapAndTheInkSlotKeptItsName(): void
    {
        $grid = pp_legacy_slot_aliases()['grid'];

        $this->assertSame('--grid-step-bg', $grid['--grid-step-color'], 'the fill rename ships');
        $this->assertArrayNotHasKey(
            '--grid-step-text-color',
            $grid,
            'the ink slot keeps its name — a swap entry here would be discarded by the CHAIN sanitizer'
        );

        $slots = pp_get_style_slots('grid');
        $this->assertArrayHasKey('--grid-step-bg', $slots, 'the badge FILL is now --grid-step-bg');
        $this->assertArrayHasKey('--grid-step-text-color', $slots, 'the badge INK keeps --grid-step-text-color');
        $this->assertArrayNotHasKey('--grid-step-color', $slots, 'no slot means "the fill" under a -color name any more');
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
    /**
     * SUPERSEDES testSlotAliasIsRenderOnlyAndTheWritePathStillRejectsTheLegacyName
     * (#575 -> #576/#594). RECORDED SUPERSESSION, not a deletion.
     *
     * #575 shipped slot-alias resolution at RENDER ONLY and pinned that asymmetry
     * deliberately, with the instruction "if this now passes, the write-path half has
     * landed — delete this pin and pin the new symmetry instead." #594 is that bug and
     * #576 is that change, so this is the replacement pin.
     *
     * THE BUG THE OLD PIN DESCRIBED. _pp_validate_style_slot_map() (lib/admin.php) never
     * consulted the alias map, and every action validates the WHOLE composition — so ONE
     * legacy slot name on ONE band made a targeted edit to ANY OTHER band fail with
     * `invalid_style_slot`, naming a slot the operator never typed on a band they never
     * touched. The page rendered correctly and could not be edited, previewed or saved,
     * and no heal-on-write existed for slots, so it never recovered. Harmless while the
     * map shipped empty; a 51-name trap the moment #576 populated it.
     *
     * THE CONTRACT NOW: read and render resolve the same names, so a document carrying a
     * legacy slot name both PAINTS and stays EDITABLE. Asserting only the edit half would
     * pass on a change that broke rendering, so both are asserted here on one document.
     */
    public function testSlotAliasResolvesSymmetricallyAtRenderAndOnTheWritePath(): void
    {
        $this->withSyntheticSlotAlias('hero', '--hero-legacy-bg', '--hero-bg');

        $id = pp_create_page('Read/render symmetry', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero',    'props' => ['title' => 'Legacy'], 'style' => ['--hero-legacy-bg' => '#1a1a2e']],
            ['component' => 'section', 'props' => ['title' => 'Sibling', 'body' => 'Edit me.']],
        ]);

        // It paints...
        $this->assertStringContainsString('--hero-bg: #1a1a2e', $this->renderStored($id));

        // ...AND a targeted edit to the untouched sibling now succeeds.
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Edited'],
        ]);
        $this->assertTrue(
            $result['ok'],
            'a sibling band carrying a legacy slot name must not block an edit elsewhere: '
            . (string) ($result['error'] ?? '')
        );

        // The whole-array write-back healed the untouched band to the canonical name,
        // and the value is preserved — key-only, render-identical.
        $stored = pp_get_composition($id);
        $this->assertSame(['--hero-bg' => '#1a1a2e'], $stored[0]['style'], 'the legacy slot name heals on write-back');
        $this->assertSame('Edited', $stored[1]['props']['title']);

        // Still paints after the heal.
        $this->assertStringContainsString('--hero-bg: #1a1a2e', $this->renderStored($id));
    }

    /**
     * The band that carries the legacy name can itself be edited (#594). The old
     * asymmetry blocked this too — the operator could not touch the very band whose
     * styling they were trying to change.
     */
    public function testTheBandCarryingALegacySlotNameCanItselfBeEdited(): void
    {
        $this->withSyntheticSlotAlias('hero', '--hero-legacy-bg', '--hero-bg');

        $id = pp_create_page('Edit the legacy band', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Legacy'], 'style' => ['--hero-legacy-bg' => '#1a1a2e']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'Retitled'],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertStringContainsString('--hero-bg: #1a1a2e', $this->renderStored($id), 'the styling survives the edit');
    }

    /**
     * A REAL renamed pair, not the synthetic one — the acceptance criterion #576 states.
     * `--hero-text` -> `--hero-heading-color` is the heaviest single rename in the set
     * and reaches five surfaces in components.css.
     */
    public function testStoredRealLegacySlotNameStillPaintsAndStaysEditable(): void
    {
        $id = pp_create_page('Real renamed pair', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero',    'props' => ['title' => 'Legacy ink'], 'style' => ['--hero-text' => '#f0f0f0']],
            ['component' => 'section', 'props' => ['title' => 'Sibling', 'body' => 'Edit me.']],
        ]);

        $html = $this->renderStored($id);
        $this->assertStringContainsString('--hero-heading-color: #f0f0f0', $html, 'the legacy slot paints under its canonical name');
        $this->assertStringNotContainsString('--hero-text', $html, 'the legacy name is never emitted');

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Edited'],
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
    }

    /**
     * Per-ITEM style maps resolve on the same read path. grid cards are the surface with
     * the most renamed slots (9 of the --grid-card-* family), and a card's `style` is
     * validated by the same shared engine as component-level style — so if read
     * resolution missed the per-item surface, a legacy-named card would render but lock
     * the whole page for editing.
     */
    public function testPerItemLegacySlotNamesResolveOnTheReadPath(): void
    {
        $id = pp_create_page('Per-card legacy slots', 'draft');
        pp_update_composition($id, [
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'One', 'text' => 'a', 'style' => ['--grid-card-bg' => '#101014']],
            ]]],
            ['component' => 'section', 'props' => ['title' => 'Sibling', 'body' => 'Edit me.']],
        ]);

        $html = $this->renderStored($id);
        $this->assertStringContainsString('--grid-item-bg: #101014', $html, 'the per-card legacy slot paints under its canonical name');
        $this->assertStringNotContainsString('--grid-card-bg', $html);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Edited'],
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
    }

    /**
     * CANONICAL-WINS on the slot surface, on the READ path, with a real pair — the twin
     * of testCanonicalPropWinsWhenBothKeysAreStored. And the refinement that matters:
     * canonical wins only when it will actually PAINT, so a canonical declaration the
     * #330 render boundary rejects must not take the legacy declaration down with it.
     */
    public function testCanonicalSlotWinsOnReadOnlyWhenItWouldActuallyPaint(): void
    {
        $id = pp_create_page('Both slot names', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Both'], 'style' => [
                '--hero-text'          => '#111111',   // legacy
                '--hero-heading-color' => '#eeeeee',   // canonical, valid
            ]],
            ['component' => 'hero', 'props' => ['title' => 'Bad canonical'], 'style' => [
                '--hero-text'          => '#222222',       // legacy, valid
                '--hero-heading-color' => 'red; }evil{',   // canonical, rejected at the render boundary
            ]],
            ['component' => 'hero', 'props' => ['title' => 'Bad canonical first'], 'style' => [
                // REVERSE key order: the non-rendering canonical twin is seen FIRST, so the
                // legacy declaration must OVERWRITE it rather than be skipped past. Stored
                // JSON preserves author key order, so both orderings are reachable.
                '--hero-heading-color' => 'red; }evil{',
                '--hero-text'          => '#333333',
            ]],
        ]);

        // ASSERT THE READ, not just the render. pp_render_style_vars() has carried
        // canonical-wins since #575, so a rendered-HTML assertion here is byte-identical
        // with and without pp_normalize_legacy_slots() — it would stay green if the
        // read-path resolution this test exists for were deleted outright. The stored
        // array is the only surface that changes.
        $stored = pp_get_composition($id);

        $this->assertSame(
            ['--hero-heading-color' => '#eeeeee'],
            $stored[0]['style'],
            'the canonical value wins on READ and the legacy key is gone'
        );
        $this->assertSame(
            ['--hero-heading-color' => '#222222'],
            $stored[1]['style'],
            'a canonical declaration that cannot paint hands the value to its legacy twin'
        );
        $this->assertSame(
            ['--hero-heading-color' => '#333333'],
            $stored[2]['style'],
            'the same holds when the non-rendering canonical twin is stored FIRST'
        );

        // ...and the render agrees with the read.
        $html = $this->renderStored($id);
        $this->assertStringContainsString('--hero-heading-color: #eeeeee', $html);
        $this->assertStringNotContainsString('#111111', $html, 'the stale legacy value is dropped');
        $this->assertStringContainsString('--hero-heading-color: #222222', $html);
        $this->assertStringContainsString('--hero-heading-color: #333333', $html);
        $this->assertStringNotContainsString('evil', $html);
    }

    /**
     * MUTATION PROOF for the read path. Every other case in this class routes through
     * pp_get_composition(), which resolves; this one calls the helper directly on a
     * legacy-shaped map and asserts the rewrite, so "the read path resolves" is pinned
     * by something that cannot be satisfied by pp_render_style_vars() alone.
     */
    public function testReadPathHelperRewritesLegacySlotNamesDirectly(): void
    {
        $this->assertSame(
            ['--hero-heading-size' => '4rem', '--hero-bg' => '#101014'],
            _pp_apply_legacy_slot_aliases(
                ['--hero-title-size' => '4rem', '--hero-bg' => '#101014'],
                'hero'
            ),
            'a legacy slot name is rewritten in place, siblings untouched'
        );

        // Idempotent: an already-canonical map is returned unchanged.
        $canonical = ['--hero-heading-size' => '4rem', '--hero-bg' => '#101014'];
        $this->assertSame($canonical, _pp_apply_legacy_slot_aliases($canonical, 'hero'));

        // A component with no alias map is untouched.
        $embed = ['--embed-heading-size' => '2rem'];
        $this->assertSame($embed, _pp_apply_legacy_slot_aliases($embed, 'embed'));
    }

    /**
     * The defensive guards, mirroring the prop twin's pins in SchemaValidationTest.
     * restore_composition replays arbitrary history-ring snapshots through this path, so
     * malformed items are the shape most likely to arrive here.
     */
    public function testSlotResolutionLeavesMalformedItemsUntouchedWithoutWarnings(): void
    {
        $cases = [
            'non-scalar component' => ['component' => ['not', 'scalar'], 'style' => ['--hero-text' => '#fff']],
            'no component key'     => ['props' => ['title' => 'x'], 'style' => ['--hero-text' => '#fff']],
            'unknown component'    => ['component' => 'nope', 'style' => ['--hero-text' => '#fff']],
            'non-array style'      => ['component' => 'hero', 'style' => 'nope'],
            'no style key'         => ['component' => 'hero', 'props' => ['title' => 'x']],
            'non-array props'      => ['component' => 'hero', 'props' => 'nope'],
        ];

        $warned = false;
        set_error_handler(function () use (&$warned) { $warned = true; return true; });
        try {
            foreach ($cases as $label => $item) {
                $this->assertSame($item, _pp_resolve_item_legacy_slots($item), "{$label}: returned untouched");
            }
            // The array wrapper must survive a non-array member too.
            $this->assertSame(['x', 3], pp_normalize_legacy_slots(['x', 3]));
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($warned, 'a malformed item must not trigger a PHP warning');
    }

    /**
     * The OTHER schema-declared per-item style surface. `section.panel_items[]` is the
     * second one the schema-derived loop claims to cover, and it behaves differently from
     * grid cards: every renamed section slot is container-scoped, so a legacy name stored
     * on a panel row resolves and is THEN correctly rejected as container-scoped. The
     * claim under test is that the loop reaches it at all.
     */
    public function testPanelItemLegacySlotNamesAreReachedByTheSchemaDerivedLoop(): void
    {
        $item = [
            'component' => 'section',
            'props'     => [
                'title'       => 'Panel',
                'layout'      => 'text-panel',
                'panel_items' => [
                    ['label' => 'Plan', 'value' => 'Pro', 'style' => ['--section-text' => '#334455']],
                ],
            ],
        ];

        $resolved = _pp_resolve_item_legacy_slots($item);

        $this->assertSame(
            ['--section-body-color' => '#334455'],
            $resolved['props']['panel_items'][0]['style'],
            'a legacy slot on a panel row is resolved by the schema-derived per-item loop'
        );
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

    // ── Prop KEY resolution for the #576 canonical vocabulary ────────────────

    /**
     * The three heaviest prop renames on dev, each proven by RENDER: the authored value
     * reaches the page, not the schema default. Asserting the stored array is not enough
     * — every renderer reads the canonical key only, so a resolution gap shows up as a
     * silently missing element (hero.subtitle) or a silently reverted layout
     * (grid.heading_align), never as an error.
     */
    public function testStoredLegacyHeroSubtitleRendersTheAuthoredValue(): void
    {
        $id = pp_create_page('Legacy hero subtitle', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Headline', 'subtitle' => 'The authored supporting line.'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('hero__subtitle', $html, 'the subtitle element must render at all');
        $this->assertStringContainsString('The authored supporting line.', $html);
    }

    public function testStoredLegacyGridHeadingAlignRendersTheAuthoredValue(): void
    {
        $id = pp_create_page('Legacy grid heading_align', 'draft');
        pp_update_composition($id, [[
            'component' => 'grid',
            'props'     => [
                'title'         => 'Centred header',
                'heading_align' => 'center',
                'items'         => [['title' => 'One', 'text' => 'a']],
            ],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString(
            'grid__header--center',
            $html,
            'legacy heading_align must resolve to title_align; without it the header silently reverts to start'
        );
    }

    /** cta.text -> body: the band renders its supporting copy, not an empty block. */
    public function testStoredLegacyCtaTextRendersTheAuthoredBody(): void
    {
        $id = pp_create_page('Legacy cta text', 'draft');
        pp_update_composition($id, [[
            'component' => 'cta',
            'props'     => ['title' => 'Join', 'text' => 'Limited spots remain.', 'button_text' => 'Go', 'button_url' => '/go'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('cta__body', $html);
        $this->assertStringContainsString('Limited spots remain.', $html);
    }

    // ── Authoring-path coverage (Section 14.1) ───────────────────────────────

    /**
     * `--section-body-link-hover-color` is a NEW declaration, not a rename: it was
     * consumed at components.css:1853/:1947 and declared in no schema, so it was
     * unreachable from every authoring path — a real intended surface hiding in plain
     * sight. Authored through the REAL surface (style_component -> the shared style
     * engine), not a raw meta write, because raw seeding is exactly what cannot tell a
     * declared slot from an undeclared one.
     */
    public function testNewSectionLinkHoverSlotIsReachableFromTheAuthoringPath(): void
    {
        $id = pp_create_page('Link hover slot', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => 'Band', 'body' => 'Copy.']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-body-link-hover-color' => '#ff6600'],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertStringContainsString('--section-body-link-hover-color: #ff6600', $this->renderStored($id));
    }

    /**
     * The write path REJECTS a name that is neither declared nor aliased — the rename
     * must not have widened the accepted slot set. `--section-accent-hover` is the exact
     * name the new slot replaces, and it gets no alias entry (it was never storable, so
     * no document can carry it).
     */
    public function testTheReplacedUndeclaredNameIsStillRejectedAtWrite(): void
    {
        $id = pp_create_page('Rejected slot', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => 'Band', 'body' => 'Copy.']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-accent-hover' => '#ff6600'],
        ]);

        $this->assertFalse($result['ok'], 'an undeclared, unaliased slot name must still be rejected');
        $this->assertStringContainsString('--section-accent-hover', (string) ($result['error'] ?? ''));
    }

    /**
     * THE BOUNDARY OF #594, pinned because it is a deliberate asymmetry and an
     * unpinned one would drift in either direction.
     *
     * Resolution covers the ALREADY-STORED document: a stored legacy slot name renders,
     * and no longer blocks editing or saving. It does NOT widen what a NEW write may
     * author — an incoming `style` patch naming a legacy slot is still rejected with
     * `invalid_style_slot`.
     *
     * That is the bounded rule doing its job. A legacy name resolves IFF a shipped
     * mechanism promises the already-stored document will render (restore_composition,
     * #233). Nothing promises anything about a name an agent chooses to type TODAY, and
     * the vocabulary freeze exists precisely so new writes use canonical names. There is
     * also no friction: every read path now returns canonical names, so `inspect` and
     * the editor show an agent the names it should patch.
     *
     * Note this is where the slot map and the PROP map legitimately differ — incoming
     * prop patches ARE canonicalized (lib/actions.php, the #495 heal-on-write model).
     * Making the slot surface match would be widening the write contract, not closing
     * #594, so it is deliberately not done here.
     */
    public function testAStoredLegacySlotIsEditableButANewLegacyWriteIsStillRejected(): void
    {
        $id = pp_create_page('Legacy slot write boundary', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => 'Band', 'body' => 'Copy.'],
             'style' => ['--section-text' => '#334455']],
        ]);

        // STORED: renders under the canonical name...
        $this->assertStringContainsString('--section-body-color: #334455', $this->renderStored($id));

        // ...and the band it sits on can be styled further, using CANONICAL names.
        $ok = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-heading-color' => '#101010'],
        ]);
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));

        $html = $this->renderStored($id);
        $this->assertStringContainsString('--section-body-color: #334455', $html, 'the healed value survives the edit');
        $this->assertStringContainsString('--section-heading-color: #101010', $html);
        $this->assertStringNotContainsString('--section-text', $html);

        // NEW WRITE naming a legacy slot: still rejected.
        $rejected = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-title-size' => '3rem'],
        ]);
        $this->assertFalse($rejected['ok'], 'authoring a legacy slot name is not part of the alias contract');
        $this->assertStringContainsString('--section-title-size', (string) ($rejected['error'] ?? ''));
    }

    // ── restore_composition: the mechanism's own premise (#233 / #576 / #594) ──

    /**
     * REGRESSION. The entire alias rule is premised on ONE mechanism:
     *
     *   A legacy name resolves IFF a shipped mechanism promises the already-stored
     *   document will render. Today exactly one makes that promise: restore_composition.
     *
     * So restore must not report `invalid_style_slot` for a name this theme deliberately
     * still supports. It did: restore builds its target with pp_normalize_composition() +
     * pp_migrate_legacy_variant_keys() EXPLICITLY (it is a decode path that does not route
     * through pp_migrate_stored_composition), and #576 added a THIRD decode surface that
     * the two explicit calls did not include. The restore still succeeded and the next read
     * healed the names, so the findings were purely false — on the one path the rule
     * depends on, and visible in the preview, so an agent could abort a legitimate restore.
     */
    public function testRestoreOfALegacyNamedSnapshotReportsNoInvalidSlotFindings(): void
    {
        $id = pp_create_page('Restore legacy slots', 'draft');
        // v1: a snapshot as a pre-1.13.0 install holds it.
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Legacy'], 'style' => [
                '--hero-title-size' => '4rem',
                '--hero-text'       => '#f0f0f0',
            ]],
            ['component' => 'grid', 'props' => ['title' => 'Cards', 'items' => [
                ['title' => 'One', 'text' => 'a', 'style' => ['--grid-card-bg' => '#101014']],
            ]]],
        ]);
        // v2: pushes v1 onto the history ring.
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => 'Now', 'body' => 'current']],
        ]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);
        $result  = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        foreach ([['preview', $preview], ['result', $result]] as [$label, $envelope]) {
            $encoded = json_encode($envelope['findings'] ?? []);
            $this->assertStringNotContainsString(
                'invalid_style_slot',
                $encoded,
                "{$label}: restore must not report a renamed slot as invalid — that is a false "
                . 'finding on the exact durability path the alias mechanism exists for'
            );
            foreach (['--hero-title-size', '--hero-text', '--grid-card-bg'] as $legacy) {
                $this->assertStringNotContainsString($legacy, $encoded, "{$label}: {$legacy}");
            }
        }

        // And the restored document paints under canonical names.
        $html = $this->renderStored($id);
        $this->assertStringContainsString('--hero-heading-size: 4rem', $html);
        $this->assertStringContainsString('--hero-heading-color: #f0f0f0', $html);
        $this->assertStringContainsString('--grid-item-bg: #101014', $html);
    }

    // ── No schema, no heal (the read path writes its answer back) ────────────

    /**
     * REGRESSION. pp_render_style_vars() can afford to guess when the schema is
     * unreadable — it just paints nothing for that request. The READ path cannot: the
     * read-modify-write actions persist its answer.
     *
     * With an empty slot set, pp_style_declaration_renders() is false for EVERY name, so
     * canonical-wins inverts — the canonical twin never "paints", and the stale LEGACY
     * value would overwrite the author's canonical value under the canonical key, then be
     * written to the database. The guard is "no schema, no heal".
     */
    public function testAnUnreadableSchemaLeavesTheStyleMapUntouchedRatherThanInverting(): void
    {
        $authored = [
            '--hero-heading-size' => '4rem',   // canonical, the author's real value
            '--hero-title-size'   => '2rem',   // stale legacy twin
        ];

        $emptyRoot = sys_get_temp_dir() . '/pp-no-components-' . getmypid();
        @mkdir($emptyRoot, 0777, true);
        $GLOBALS['_pp_test_template_dir']              = $emptyRoot;
        $GLOBALS['_pp_registered_components_invalidate'] = true;
        try {
            $this->assertSame(
                [],
                pp_get_style_slots('hero'),
                'precondition: the schema is genuinely unreadable in this state'
            );
            $this->assertSame(
                $authored,
                _pp_apply_legacy_slot_aliases($authored, 'hero'),
                'with no schema the map is returned untouched — never healed into the legacy value'
            );
        } finally {
            unset($GLOBALS['_pp_test_template_dir']);
            $GLOBALS['_pp_registered_components_invalidate'] = true;
            @rmdir($emptyRoot);
        }

        // Sanity: with the real schema back, the heal runs and canonical wins.
        $this->assertSame(
            ['--hero-heading-size' => '4rem'],
            _pp_apply_legacy_slot_aliases($authored, 'hero')
        );
    }
}
