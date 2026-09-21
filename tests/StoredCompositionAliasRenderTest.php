<?php
/**
 * tests/StoredCompositionAliasRenderTest.php
 *
 * The stored-composition legacy-name contract (issues #575 / #495, ended by #603/#604).
 *
 * NO SURFACE IS LEFT. All three alias surfaces this class covers are gone, and
 * pinning their ABSENCE — at the render boundary, on real stored bytes — is now the
 * whole job of the file.
 *
 * ONE THING IS DELIBERATELY KEPT, and it is not an alias. `theme: "muted"` still
 * EMITS the legacy `<root>--dark` CSS class (#570 DG-4). That is an OUTPUT NAME:
 * renaming it would change the emitted HTML of the installed base and invalidate
 * every stylesheet rule and `variant_classes` declaration for no authoring gain. Do
 * not "finish the cleanup" by removing it — input aliasing and output naming are
 * different things, and only the first one went.
 *
 *   SLOT NAME   pp_legacy_slot_aliases() — REMOVED (#603). Shipped empty in #575,
 *               populated by #576 with 51 renames, deleted outright along with its two
 *               resolution helpers, the pp_normalize_legacy_slots() wrapper and the
 *               public filter. A slot name a component does not declare is rejected at
 *               write and dropped at render, with nothing in between.
 *   VALUE       the `theme` prop's legacy `dark` — REMOVED (#605). The last shipped
 *               `aliases` declaration. Its name mispredicted its output (it rendered
 *               a LIGHT band), so accepting it produced the wrong page silently on the
 *               very request an agent would generate it for. A retired VALUE differs in
 *               shape from a retired NAME: the prop stays valid and the value simply
 *               falls outside its accepted set, so it coerces to the prop default.
 *   PROP KEY    pp_legacy_prop_aliases() — REMOVED (#604). The 13-entry map
 *               (cta_text/cta_url -> button_text/button_url from #495, extended by #576
 *               with hero's button family, cta.text -> body and heading_align ->
 *               title_align) is deleted, together with the `variant` -> `layout`/`theme`
 *               read migration and the pp_migrate_stored_composition() shim that applied
 *               both. A retired prop name is rejected at write and unread at render.
 *
 * Why both surfaces went. #575's bounded rule said a legacy name resolves IFF a shipped
 * mechanism promises the already-stored document will render, and named
 * `restore_composition` (#233) as that mechanism. The #570 decision record, Addendum #4,
 * RETIRES that mechanism-trust rule: restore's actual contract is that it restores and
 * REPORTS, never that what it restores still paints. Under the governing ruling —
 * backward compatibility, stale demo pages, old compositions, migrations and legacy
 * tolerance are all NON-GOALS — neither map had a basis left. #603 took the slots, #604
 * took the props.
 *
 * What the second half asserts is therefore the STALE-DATA BREAKAGE, on purpose: a
 * stored retired name renders the schema default (or nothing), and the authored value is
 * lost. That is the accepted cost of the vocabulary freeze, stated rather than inferred.
 *
 * Everything renders through the EXACT loop templates/composition.php runs, so what is
 * asserted is what a visitor's browser receives, not an intermediate array.
 */

use PHPUnit\Framework\TestCase;
use PromptingPress\Tests\Support\FixtureTheme;

class StoredCompositionAliasRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // THE BAND HERE IS A FIXTURE, NOT A SUBJECT (#1025). The mechanism under test
        // is the slot engine; which component carries the slots is incidental, which is
        // why this whole set was re-homed hero -> section -> stats over three rebuilds.
        // It targets `ppfixture` now, so stats' rebuild is the last one that moved it.
        FixtureTheme::activate();
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
        // MUST pair with the activate() above. PHPUnit runs every class in ONE process,
        // so a fixture root left in force is inherited by every later class — which does
        // not look like a leak, it looks like the UDC suites suddenly seeing a component
        // that declares no roles. Pinned in FixtureThemeSeamTest.
        FixtureTheme::deactivate();
        parent::tearDown();
    }

    /**
     * Renders a stored composition exactly as templates/composition.php does:
     * read the stored items, promote `style` to the `__pp_style` prop, render each
     * component in order. The only difference is the read accessor
     * (pp_get_composition($id) rather than pp_composition(), which resolves the post
     * from the loop) — both are plain decodes since #604, so the parity holds.
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
            // THE BAND IDENTITY PROMOTION, as the real loop does it (#1046). Without this
            // the helper rendered a v2 band with no `data-pp-band` attribute — markup no
            // page ever produces, because templates/composition.php, templates/front-page.php
            // and the editor preview all promote first. A v1 band is unaffected: the
            // promoter only adds keys when the item carries a usable id.
            $props = pp_udc_promote_band_identity(is_array($item) ? $item : [], $props);
            pp_get_component((string) $item['component'], $props);
        }
        return ob_get_clean();
    }

    // ── Slot NAME aliases: REMOVED (#603) ────────────────────────────────────
    //
    // pp_legacy_slot_aliases(), its two resolution helpers, the
    // pp_normalize_legacy_slots() wrapper and the public `pp_legacy_slot_aliases`
    // filter are all gone. The cases below pin the ONE contract that replaced them:
    // a slot name the component does not declare is rejected at write and dropped at
    // render, with nothing canonicalizing it in between.
    //
    // The stale-data consequence is INTENTIONAL and is pinned here rather than
    // softened. A composition stored before the #576 vocabulary rename loses that
    // declaration at render, and any whole-composition validating action now rejects
    // it with `invalid_style_slot`. Per the governing ruling, backward compatibility,
    // stale demo pages, old compositions, migrations and legacy tolerance are
    // NON-GOALS — this is the stated outcome of the removal, not a defect to heal.

    /**
     * THE primary pin, promoted from the #575-era negative case
     * (testWithoutTheAliasTheSameStoredSlotIsSilentlyDropped, which proved what
     * happened with the alias ABSENT). That is now the only behavior there is: a
     * stored legacy slot name is dropped at render exactly like any other undeclared
     * key — silently, with the page still rendering.
     */
    public function testAStoredLegacySlotNameIsSilentlyDroppedAtRender(): void
    {
        $id = pp_create_page('Legacy slot page', 'draft');
        // Thin writer, no validation — persists the legacy shape exactly as a
        // pre-1.13.0 install holds it (and as restore_composition can replay it).
        // RE-HOMED FROM `grid` TO `ppfixture` AT #1101 — the legacy-slot family's last
        // available move. These tests need a component that DECLARES slots, so a
        // stale name can be shown to be dropped while a canonical one still paints;
        // grid was the last shipped one, and the fixture exists for exactly this
        // (#1025). `--ppfixture-text` plays the retired legacy name and
        // `--ppfixture-heading-color` the canonical twin — the same pairing
        // `--grid-text` / `--grid-heading-color` carried.
        pp_update_composition($id, [[
            'component' => 'ppfixture',
            'props'     => ['title' => 'Unstyled', 'items' => [['number' => '1', 'label' => 'Card']]],
            'style'     => ['--ppfixture-text' => '#f0f0f0'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('#f0f0f0', $html, 'the legacy declaration does not paint');
        $this->assertStringNotContainsString('--grid-text', $html, 'and its own name is never emitted');
        $this->assertStringNotContainsString(
            '--ppfixture-heading-color',
            $html,
            'nothing canonicalizes it on the way through — the read path has no slot map any more'
        );
        $this->assertStringContainsString(
            'Unstyled',
            $html,
            'the page still renders — the drop is silent, exactly as for any undeclared key'
        );
    }

    /**
     * VALIDATORS ARE NOT WEAKENED (acceptance criterion 2). A NEW write naming a
     * legacy slot was rejected before #603 — `_pp_validate_style_slot_map()` never
     * consulted the alias map — and is rejected after it, by this same test. Removing
     * the map cannot have widened the accepted slot set, because the map was never
     * on the write path to begin with.
     */
    public function testANewWriteOfALegacySlotNameIsRejected(): void
    {
        $id = pp_create_page('Legacy slot write', 'draft');
        pp_update_composition($id, [
            ['component' => 'ppfixture', 'props' => ['title' => 'Canonical', 'items' => [['number' => '1', 'label' => 'Card']]]],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--ppfixture-text' => '#f0f0f0'],
        ]);

        $this->assertFalse($result['ok'], 'a legacy slot name is not authorable');
        $this->assertStringContainsString('--ppfixture-text', (string) ($result['error'] ?? ''));
        $this->assertSame('invalid_style_slot', $result['error_code'] ?? null);
    }

    /**
     * THE STATED BREAKAGE, pinned so it can never be quietly softened into a
     * migration or a warning-only tolerance.
     *
     * The whole-composition validating actions (`update_component` and the other
     * read-modify-write actions, which validate the ENTIRE array they write back)
     * now see the stale declaration. Before #603 the read path canonicalized stored
     * slot names first, so a legacy name on one band was invisible to that
     * validation. Now a targeted edit to ANOTHER band fails with `invalid_style_slot`
     * naming the dead slot. On the dev corpus that is ~105 declarations across 7 of
     * 12 compositions.
     *
     * `style_component` is deliberately NOT the probe here: it validates only the
     * incoming style patch against the targeted component's slots, so it never sees a
     * sibling band's stored declaration. The breakage is real on the whole-array
     * actions, and this pin says exactly which — an over-broad claim would rot.
     *
     * This is the intended outcome of the removal. The recovery path is authoring the
     * canonical name, not a shim.
     */
    public function testAStoredLegacySlotNameIsReportedOnEveryAcceptedWrite(): void
    {
        $id = pp_create_page('Legacy slot blocks edits', 'draft');
        pp_update_composition($id, [
            ['component' => 'ppfixture', 'props' => ['title' => 'Legacy', 'items' => [['number' => '1', 'label' => 'Card']]], 'style' => ['--ppfixture-text' => '#f0f0f0']],
            ['component' => 'section', 'props' => ['title' => 'Band', 'body' => 'Copy.']],
        ]);

        // INVERTED BY #1007: update_component validates the band it targets, so a stale
        // SIBLING no longer refuses this edit. Nothing is migrated or healed — the stale
        // bytes stay stale and are still reported, now on the accepted envelope instead
        // of in a refusal. The repair route below is unchanged and still the way out.
        // An edit to the OTHER band, touching nothing about the cta.
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Renamed band'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'the untouched band is editable');
        $reported = array_values(array_filter(
            $result['findings'],
            static fn (array $f): bool => ($f['type'] ?? '') === 'invalid_style_slot'
        ));
        $this->assertNotEmpty($reported, 'the stale declaration is still visible to validation');
        $this->assertStringContainsString(
            '--ppfixture-text',
            $reported[0]['message'],
            'the disclosure names the dead slot on the band the operator never touched'
        );

        // THE ESCAPE HATCH, pinned so the intended breakage has a proven way out.
        //
        // style_component is NOT the way out. It succeeds — it validates only its own
        // patch — but it MERGES into the stored map, so the dead key survives beside
        // the new canonical one and the page stays unwritable. Pinned because
        // "just re-style the band" is the obvious wrong fix to reach for.
        $merge = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--ppfixture-heading-color' => '#f0f0f0'],
        ]);
        $this->assertTrue($merge['ok'], (string) ($merge['error'] ?? ''));
        $this->assertArrayHasKey(
            '--ppfixture-text',
            pp_get_composition($id)[0]['style'],
            'the merge did not evict the dead key'
        );
        // The dead key survives the merge, so it is STILL REPORTED on the next accepted
        // write. Since #1007 it no longer refuses that write, but "just re-style the band"
        // is still the wrong fix: it leaves a declaration that paints nothing, and the
        // findings say so every time.
        $stillReported = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Renamed band'],
        ]);
        $this->assertTrue($stillReported['ok'], (string) ($stillReported['error'] ?? ''));
        $this->assertStringContainsString(
            '--ppfixture-text',
            implode(' ', array_column($stillReported['findings'], 'message')),
            'the dead key is still diagnosed after the merge that failed to evict it'
        );

        $repaired = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [
                ['component' => 'ppfixture', 'props' => ['title' => 'Legacy', 'items' => [['number' => '1', 'label' => 'Card']]], 'style' => ['--ppfixture-heading-color' => '#f0f0f0']],
                ['component' => 'section', 'props' => ['title' => 'Band', 'body' => 'Copy.']],
            ],
        ]);
        $this->assertTrue($repaired['ok'], (string) ($repaired['error'] ?? ''));

        // Recovered: the page reports nothing, and the value the author meant paints
        // under the canonical name.
        $after = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Renamed band'],
        ]);
        $this->assertTrue($after['ok'], (string) ($after['error'] ?? ''));
        $this->assertSame([], $after['findings'], 'the page is clean once the dead key is gone');
        $this->assertStringContainsString('--ppfixture-heading-color: #f0f0f0', $this->renderStored($id));
    }

    /**
     * A canonical declaration is untouched when a stale legacy twin sits beside it.
     * Before #603 canonical-wins arbitrated between the two; now there is nothing to
     * arbitrate — the canonical name paints because it is declared, and the legacy one
     * is dropped because it is not.
     */
    public function testACanonicalDeclarationStillPaintsBesideAStaleLegacyTwin(): void
    {
        $id = pp_create_page('Both slot names', 'draft');
        // RE-HOMED FROM `grid` TO `ppfixture` AT #1101 — the legacy-slot family's last
        // available move. These tests need a component that DECLARES slots, so a
        // stale name can be shown to be dropped while a canonical one still paints;
        // grid was the last shipped one, and the fixture exists for exactly this
        // (#1025). `--ppfixture-text` plays the retired legacy name and
        // `--ppfixture-heading-color` the canonical twin — the same pairing
        // `--grid-text` / `--grid-heading-color` carried.
        pp_update_composition($id, [[
            'component' => 'ppfixture',
            'props'     => ['title' => 'Both', 'items' => [['number' => '1', 'label' => 'Card']]],
            'style'     => ['--ppfixture-text' => '#111111', '--ppfixture-heading-color' => '#222222'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('--ppfixture-heading-color: #222222', $html, 'the canonical value paints');
        $this->assertStringNotContainsString('#111111', $html, 'the stale legacy value is simply gone');
    }

    /**
     * PER-ITEM style maps lose the alias too. The schema-derived per-item resolution
     * loop (_pp_resolve_item_legacy_slots) is gone, so a grid card carrying a legacy
     * name is dropped by pp_render_style_vars()'s item-scope path like any other
     * undeclared key — the same answer the component-level map gets.
     */
    public function testALegacySlotNameOnAPerItemStyleMapIsDroppedToo(): void
    {
        $id = pp_create_page('Legacy per-item slot', 'draft');
        pp_update_composition($id, [[
            'component' => 'grid',
            'props'     => ['title' => 'Cards', 'items' => [
                ['title' => 'One', 'text' => 'a', 'style' => ['--grid-card-bg' => '#101014']],
            ]],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('#101014', $html, 'the per-item legacy declaration does not paint');
        $this->assertStringNotContainsString('--grid-item-bg', $html, 'and nothing renames it');
        $this->assertStringContainsString('One', $html, 'the card itself still renders');
    }

    /**
     * FRESH-GENERATION CORRECTNESS (acceptance criterion 4). The removal is safe for
     * everything the AI can actually author today: the runtime catalog and
     * AI_CONTEXT.md advertise canonical names only, so a freshly generated composition
     * cannot contain a legacy slot name except by hallucination — and that write is
     * rejected (see testANewWriteOfALegacySlotNameIsRejected).
     *
     * Authored through the REAL surface, then asserted on the RENDERED HTML for hero,
     * grid and section, and read back byte-identical. Raw-meta seeding is exactly what
     * cannot tell a declared slot from an undeclared one (Section 14.1).
     */
    public function testAFreshCanonicalCompositionWritesValidatesReadsBackAndRenders(): void
    {
        $authored = [
            // faq's band carries a `udc` map instead of a `style` map since #1046 — the
            // same two values, addressed as role parameters. Kept in this fixture rather
            // than dropped: the test's subject is that an AUTHORED design map survives a
            // real write/read round trip byte-identically, and that subject applies to
            // the v2 surface exactly as it did to the v1 one. Dropping the band would
            // have quietly narrowed the test to slot-bearing components only.
            ['component' => 'faq', 'props' => ['title' => 'Fresh', 'items' => [['question' => 'Q', 'answer' => 'A']]], 'udc' => [
                'heading' => ['typography' => ['color' => '#f0f0f0', 'size' => '4rem']]]],
            // grid's band carries a `udc` map at BOTH GRAINS since #1101, which is what
            // makes it the most valuable band in this fixture: it is the only component
            // that can round-trip a band map AND a per-ITEM map, so the byte-identity
            // claim now covers the item tier Addendum B added. The two values are the same
            // two the v1 fixture carried, addressed as role parameters.
            ['component' => 'grid', 'props' => ['title' => 'Cards', 'items' => [
                ['title' => 'One', 'text' => 'a', 'udc' => ['card' => ['background' => ['fill' => '#101014']]]]]],
             'udc' => ['heading' => ['sizing' => ['max-width' => '40rem']]]],
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '1', 'label' => 'One']], 'title' => 'Band'], 'style' => [
                '--ppfixture-label-color' => '#334455']]];

        $id     = pp_create_page('Fresh canonical page', 'draft');
        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => $authored]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        // Read back: every authored style map survives the round trip untouched.
        $stored = pp_get_composition($id);
        $this->assertSame($authored[0]['udc'], $stored[0]['udc'], 'the faq udc map is byte-identical');
        $this->assertSame($authored[1]['udc'], $stored[1]['udc'], 'the grid band udc map is byte-identical');
        $this->assertSame(
            $authored[1]['props']['items'][0]['udc'],
            $stored[1]['props']['items'][0]['udc'],
            'the per-ITEM udc map is byte-identical — the Addendum B tier this fixture now covers'
        );
        // AND THE ENGINE MINTED THE ITEM ITS HANDLE, which is the half a byte-identity
        // check cannot see: the author writes no `id`, the write path adds one, and
        // without it the map above would validate, store, and emit under no selector.
        $this->assertMatchesRegularExpression(
            '/^it-[0-9a-f]{8}\z/',
            (string) ($stored[1]['props']['items'][0]['id'] ?? ''),
            'an item carrying a udc map is minted an it-<hex8> handle on write'
        );
        $this->assertSame($authored[2]['style'], $stored[2]['style'], 'section style map is byte-identical');

        // Render: every authored declaration reaches the page.
        $html = $this->renderStored($id);
        // faq's two values land in the band-scoped block the ENGINE emits into the
        // document head, not in an inline style attribute on the section — the v2
        // emission shape. `renderStored()` above walks the composition and renders
        // component MARKUP only, which is the whole page on v1 and half of it on v2, so
        // the assertion reads the other half from the emitter. It follows the value to
        // where it paints rather than retiring with the slot.
        $bandCss = pp_udc_page_css($stored);
        $this->assertMatchesRegularExpression(
            '/\[data-pp-band="pp-[0-9a-f]{8}"\] \.faq__heading\{font-size:4rem;color:#f0f0f0;\}/',
            $bandCss,
            'both authored values must reach the page, in the BAND-scoped block (not the '
            . 'component-defaults tier), which is what makes them this band\'s design'
        );
        $this->assertStringNotContainsString('--faq-heading-color', $html);
        // Scoped to each band's own <section>. THE POINT OF THE FIXTURE INVERTED AT #1101:
        // it used to be that faq was v2 while grid and the fixture band were still v1, so
        // one page carried both emission shapes. Now only the test FIXTURE component is on
        // the v1 shape, and the interesting contrast is between the two v2 GRAINS — faq's
        // band-scoped block and grid's band-AND-item-scoped blocks on the same page.
        preg_match('/<section[^>]*data-pp-component="faq"[^>]*>/', $html, $faqTag);
        $this->assertNotEmpty($faqTag, 'the faq band must render');
        $this->assertStringNotContainsString(
            'style=',
            $faqTag[0],
            'a v2 band emits data-pp-band and no inline style attribute'
        );
        $this->assertStringContainsString('data-pp-band="', $faqTag[0]);

        // GRID'S TWO GRAINS, which is what this fixture gained at #1101 and what nothing
        // else on the page can show. The band map emits under the band selector; the ITEM
        // map emits under a `[data-pp-item]` selector nested inside it, and the item's
        // handle in the emitted CSS must be the one the engine minted into storage —
        // otherwise the map would validate, store, and paint under a selector matching
        // nothing.
        $itemId = $stored[1]['props']['items'][0]['id'];
        $this->assertMatchesRegularExpression(
            '/\[data-pp-band="pp-[0-9a-f]{8}"\] \.grid__heading\{[^}]*max-width:40rem/',
            $bandCss,
            'the grid band map reaches the band-scoped block'
        );
        $this->assertMatchesRegularExpression(
            '/\[data-pp-band="pp-[0-9a-f]{8}"\] \[data-pp-item="' . preg_quote($itemId, '/') . '"\][^{]*\{[^}]*#101014/',
            $bandCss,
            'the per-item map reaches an ITEM-scoped rule keyed on the minted handle'
        );
        // And the card carries that handle in the markup, or the rule above matches nothing.
        $this->assertStringContainsString('data-pp-item="' . $itemId . '"', $html);
        // Neither grain emits an inline style attribute — §3.4, theme-wide since #1101.
        preg_match('/<section[^>]*data-pp-component="grid"[^>]*>/', $html, $gridTag);
        $this->assertNotEmpty($gridTag, 'the grid band must render');
        $this->assertStringNotContainsString('style=', $gridTag[0]);
        $this->assertStringNotContainsString('--grid-', $html, 'no grid custom property is emitted anywhere');

        // The FIXTURE band is the only v1 shape left on the page, and it still paints its
        // slot inline — which is what keeps the contrast in this test real rather than
        // asserted about a surface that no longer exists.
        $this->assertStringContainsString('--ppfixture-label-color: #334455', $html);

        // And validation is clean — no findings on a canonically authored document.
        $this->assertSame([], pp_validate_composition_errors($stored));
    }

    /**
     * THE GUARDRAILS PATH, pinned because it resolved slot aliases too (lib/guardrails.php)
     * and the advisories are the surface most likely to drift back into "resolve the old
     * name so the warning still fires".
     *
     * A dead legacy slot name renders nothing, so no advisory about its VALUE can be
     * true: `--testimonials-card-bg: transparent` is not an invisible fill, it is not a
     * fill at all. The advisory channel stays quiet and the ERROR channel
     * (invalid_style_slot) is what reports the dead declaration — one message, on the
     * right channel.
     */
    public function testADeadLegacySlotNameRaisesNoAdvisoryButIsReportedAsAnError(): void
    {
        $items = [[
            'component' => 'testimonials',
            'props'     => ['layout' => 'stack', 'items' => [['quote' => 'q']]],
            'style'     => ['--testimonials-card-bg' => 'transparent'],
        ]];

        $this->assertSame(
            [],
            pp_validate_composition_smells($items),
            'an undeclared slot paints nothing, so no value-level advisory about it can be true'
        );

        $errors = pp_validate_composition_errors($items);
        $this->assertNotSame([], $errors, 'the dead declaration is reported on the error channel instead');
        $this->assertStringContainsString(
            '--testimonials-card-bg',
            implode(' | ', array_map(static fn ($e) => $e->get_error_message(), $errors)),
            'reported somewhere in the findings, not necessarily first'
        );
    }

    // ── Prop KEY resolution is GONE — the rendered proof (#604) ─────────────
    //
    // SUPERSEDES the five #495/#576 render-resolution pins that stood here. Those
    // asserted that a stored legacy prop name reached the page as its AUTHORED value.
    // #604 removed the alias map, so the opposite is now true and is pinned with the
    // same rendered evidence, at the same render boundary.
    //
    // Rendered evidence matters more than an array assertion here, exactly as it did
    // before: every renderer reads the canonical key only, so the loss shows up as a
    // silently missing element (hero.subtitle), a silently reverted layout
    // (grid.heading_align) or a schema default replacing authored copy (cta) — never
    // as an error. These tests are what make "the authored value is lost" a stated,
    // observed fact rather than an inference.

    public function testStoredRetiredCtaPropRendersTheSchemaDefault(): void
    {
        $id = pp_create_page('Retired prop page', 'draft');
        // Thin writer, no validation — persists the stale shape as a live install holds
        // it (and as restore_composition can replay it).
        pp_update_composition($id, [[
            'component' => 'cta',
            'props'     => ['cta_text' => 'View on GitHub', 'cta_url' => 'https://example.com/repo'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('View on GitHub', $html, 'the retired label no longer reaches the page');
        $this->assertStringNotContainsString('https://example.com/repo', $html, 'nor does the retired destination');
        $this->assertStringContainsString('Get Started', $html, 'the schema default renders instead — the stated breakage');
    }

    public function testBothKeysStoredRendersTheCanonicalValueAndIgnoresTheRetiredOne(): void
    {
        // Canonical-wins used to be an ACTIVE rule that dropped the retired key. Now it
        // is simply what happens: the renderer reads the canonical name and never looks
        // at the other one. Same rendered outcome, no machinery behind it.
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

        $this->assertStringContainsString('Fresh label', $html, 'the canonical value renders');
        $this->assertStringNotContainsString('Stale label', $html, 'the retired key is never read');
    }

    public function testStoredRetiredHeroSubtitleRendersNothing(): void
    {
        $id = pp_create_page('Retired hero subtitle', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Headline', 'subtitle' => 'The authored supporting line.'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('hero__subtitle', $html, 'the element is not emitted at all');
        $this->assertStringNotContainsString('The authored supporting line.', $html);
        $this->assertStringContainsString('Headline', $html, 'the canonical title on the same band still renders');
    }

    public function testStoredRetiredGridHeadingAlignRevertsToTheDefaultAlignment(): void
    {
        $id = pp_create_page('Retired grid heading_align', 'draft');
        pp_update_composition($id, [[
            'component' => 'grid',
            'props'     => [
                'title'         => 'Centred header',
                'heading_align' => 'center',
                'items'         => [['title' => 'One', 'text' => 'a']],
            ],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString(
            'grid__header--center',
            $html,
            'the retired heading_align no longer resolves to title_align; the header reverts to the default'
        );
    }

    public function testStoredRetiredCtaTextRendersNoBody(): void
    {
        $id = pp_create_page('Retired cta text', 'draft');
        pp_update_composition($id, [[
            'component' => 'cta',
            'props'     => ['title' => 'Join', 'text' => 'Limited spots remain.', 'button_text' => 'Go', 'button_url' => '/go'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('Limited spots remain.', $html, 'the retired cta.text copy is dropped');
        $this->assertStringContainsString('Join', $html, 'the canonical title still renders');
    }

    public function testFreshCanonicalCompositionRendersEveryComponentUnaffected(): void
    {
        // AC4, the other side of the ledger: a composition authored in the CURRENT
        // canonical vocabulary is completely untouched by the removal. All eight
        // composable components, including stats/logos/embed whose tonal `variant`
        // the deleted migration also covered.
        $id = pp_create_page('Fresh canonical', 'draft');
        $canonical = [
            ['component' => 'hero',         'props' => ['title' => 'Hero title', 'subheading' => 'Hero sub', 'button_text' => 'Go', 'button_url' => '/go', 'layout' => 'split']],
            // section carries content props only now: `title_align` and `theme` both
            // retired at #1023. The band still renders in this every-component sweep —
            // that is the point of it — just without the two styling props.
            ['component' => 'section',      'props' => ['title' => 'Section title', 'body' => 'Section copy.']],
            ['component' => 'cta',          'props' => ['title' => 'CTA title', 'body' => 'CTA copy.', 'button_text' => 'Join', 'button_url' => '/join', 'layout' => 'inline']],
            // grid carries content props only now: `title_align`, `theme`,
            // `card_emphasis` and `image_treatment` all retired at #1101. The band still
            // renders in this every-component sweep — that is the point of it — just
            // without the four styling props.
            ['component' => 'grid',         'props' => ['title' => 'Grid title', 'layout' => 'cards', 'items' => [['title' => 'One', 'text' => 'a']]]],
            // testimonials carries neither `title_align` nor `theme` now: it is the first
            // v2 component, and both props' entire effect was value-styling that the
            // structural-CSS boundary removed. It stays in this roster because the
            // canonical-render contract is about PROPS surviving a round trip, and its
            // remaining props do.
            ['component' => 'testimonials', 'props' => ['title' => 'Quotes', 'layout' => 'stack', 'items' => [['quote' => 'Great.', 'author' => 'Ada']]]],
            ['component' => 'ppfixture',        'props' => ['title' => 'Numbers', 'theme' => 'inverted', 'items' => [['number' => '10', 'label' => 'Customers']]]],
            // logos' `theme` retired at #1066 PR2, so the band carries content only — the
            // tone it used to express is the `_band` role's background in the `udc` map.
            ['component' => 'logos',        'props' => ['title' => 'Logos', 'items' => [['image_url' => 'https://example.com/acme.png', 'image_alt' => 'Acme']]]],
            // embed's `theme` retired at #1066, so it carries none here. It stays in the
            // roster for the reason testimonials does: this contract is about PROPS
            // surviving a round trip, and its remaining props do.
            ['component' => 'embed',        'props' => ['title' => 'Embed', 'content' => '<p>hi</p>']],
        ];
        pp_update_composition($id, $canonical);

        // It validates through the REAL authoring surface (Section 14.1) ...
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => $canonical]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'canonical composition must validate');

        // ... reads back byte-identical (ids are injected by the writer, so compare props
        // the caller authored rather than the whole array) ...
        $stored = pp_get_composition($id);
        $this->assertCount(count($canonical), $stored);
        foreach ($canonical as $i => $band) {
            $this->assertSame($band['component'], $stored[$i]['component'], "band {$i} component");
            foreach ($band['props'] as $k => $v) {
                $this->assertSame($v, $stored[$i]['props'][$k], "band {$i} prop {$k} round-trips unchanged");
            }
        }

        // ... and renders every authored value.
        $html = $this->renderStored($id);
        foreach (['Hero title', 'Hero sub', 'Section title', 'Section copy.', 'CTA title',
                  'CTA copy.', 'Grid title', 'Quotes', 'Numbers', 'Logos', 'Embed'] as $needle) {
            $this->assertStringContainsString($needle, $html, "canonical content '{$needle}' renders");
        }
    }

    // ── Authoring-path coverage (Section 14.1) ───────────────────────────────

    /**
     * The section body-link HOVER colour is still reachable from the authoring path —
     * through the `body-link` role's `:hover`, not a slot (#1023).
     *
     * The slot pair this replaces is the clearest illustration of why the rebuild moved
     * states and resting values together: `--section-body-link-color` and
     * `--section-body-link-hover-color` were separate names that had to be kept in step
     * by discipline, and docs/explanation-cascade-layers.md §1b records what happens when
     * one tier moves without the other (the #992 chrome defect). One role, one map, both
     * values — they cannot be set apart now.
     *
     * Still the REAL authoring surface (rule 14.1): the write goes through
     * update_composition's validation, and the assertion reads the compiled band block,
     * because a v2 band emits its design there rather than as an inline custom property.
     */
    public function testTheSectionBodyLinkHoverIsReachableFromTheAuthoringPath(): void
    {
        $id = pp_create_page('Link hover role', 'draft');
        $composition = [[
            'component' => 'section',
            'id'        => 'pp-4b5c6d7e',
            'props'     => ['title' => 'Band', 'body' => '<p>Copy with a <a href="/x">link</a>.</p>'],
            'udc'       => ['body-link' => ['typography' => [
                'color'  => '#cc4400',
                ':hover' => ['color' => '#ff6600'],
            ]]],
        ]];
        $this->assertTrue(pp_validate_composition($composition),
            'the role map must validate through the shared engine');
        pp_update_composition($id, $composition);

        $css = pp_udc_page_authored_css(pp_get_composition($id));
        $this->assertStringContainsString('[data-pp-band="pp-4b5c6d7e"] .section__content a{', $css);
        $this->assertStringContainsString('color:#cc4400', $css);
        $this->assertStringContainsString('[data-pp-band="pp-4b5c6d7e"] .section__content a:hover{', $css);
        $this->assertStringContainsString('color:#ff6600', $css);
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
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '1', 'label' => 'One']], 'title' => 'Band']]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-accent-hover' => '#ff6600']]);

        $this->assertFalse($result['ok'], 'an undeclared, unaliased slot name must still be rejected');
        $this->assertStringContainsString('--section-accent-hover', (string) ($result['error'] ?? ''));
    }

    /**
     * THE INVERSION OF #594'S BOUNDARY (converted, not deleted — the "still rejected at
     * write" half is exactly what acceptance criterion 2 asks to keep proving).
     *
     * #594 made a stored legacy slot name EDITABLE: it painted under its canonical name,
     * and the band carrying it could still be styled. #603 removes both halves of that.
     * A band carrying a now-undeclared slot name paints nothing AND cannot be edited —
     * `_pp_validate_style_slot_map()` rejects the whole composition with
     * `invalid_style_slot` naming the slot the operator never typed.
     *
     * That was #594's stated defect, and it is now the intended state: under the
     * governing ruling the fix is to author the canonical name, not to teach the
     * validator to tolerate the stale one. The one thing that must NOT break is
     * restore_composition, which reports rather than blocks (#233) — pinned below.
     */
    public function testABandCarryingALegacySlotNameCanNoLongerBeEditedAndTheWriteIsRejected(): void
    {
        $id = pp_create_page('Legacy slot write boundary', 'draft');
        pp_update_composition($id, [
            ['component' => 'ppfixture', 'props' => ['items' => [['number' => '1', 'label' => 'One']], 'title' => 'Band'],
             'style' => ['--section-text' => '#334455']]]);

        // STORED: paints nothing, under either name.
        $html = $this->renderStored($id);
        $this->assertStringNotContainsString('#334455', $html, 'the stored legacy declaration is dead');
        $this->assertStringNotContainsString('--ppfixture-label-color', $html, 'and nothing renames it');
        $this->assertStringNotContainsString('--section-text', $html);

        // The band can no longer be edited at all — the stale declaration is visible
        // to the whole-array validation `update_component` runs. This is the
        // inversion: #594 made this edit succeed, #603 makes it fail on purpose.
        $blocked = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'Renamed']]);
        $this->assertFalse($blocked['ok'], 'the dead slot fails the write it sits on');
        $this->assertSame('invalid_style_slot', $blocked['error_code'] ?? null);
        $this->assertStringContainsString(
            '--section-text',
            (string) ($blocked['error'] ?? ''),
            'the error names the dead slot, so the operator knows what to fix'
        );

        // NEW WRITE naming a legacy slot: still rejected, exactly as before #603.
        $rejected = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--section-title-size' => '3rem']]);
        $this->assertFalse($rejected['ok'], 'authoring a legacy slot name was never accepted and still is not');
        $this->assertStringContainsString('--section-title-size', (string) ($rejected['error'] ?? ''));
    }

    // ── restore_composition: reports, never blocks (#233) ────────────────────

    /**
     * THE ONE THING THE REMOVAL MUST NOT BREAK (acceptance criterion 3), converted from
     * the #594-era pin that asserted the opposite.
     *
     * DG-9 justified render-time resolution on the "mechanism trust" argument that
     * restore_composition promises an already-stored document will render. Addendum #4
     * retires that rule: restore's actual #233 contract is that it RESTORES and REPORTS,
     * never that what it restores still paints. So a snapshot carrying pre-#576 slot
     * names must still restore successfully — and must now say, on the findings channel,
     * that those declarations are dead.
     *
     * The failure this guards against is restore silently BLOCKING on stale slot names,
     * which would break the durability mechanism itself. Reporting is the correct
     * outcome; blocking is not.
     */
    public function testRestoreOfALegacyNamedSnapshotSucceedsAndReportsTheDeadSlots(): void
    {
        $id = pp_create_page('Restore legacy slots', 'draft');
        // v1: a snapshot as a pre-1.13.0 install holds it.
        pp_update_composition($id, [
            ['component' => 'cta', 'props' => ['title' => 'Legacy', 'body' => 'B', 'button_text' => 'Go', 'button_url' => '/'], 'style' => [
                '--cta-title-size' => '4rem',
                '--grid-text'       => '#f0f0f0',
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

        // NEVER BLOCKS. This is the #233 contract and the load-bearing assertion here.
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        // REPORTS. Both the preview and the result name the dead slots, so an operator
        // sees the consequence before and after committing the restore.
        foreach ([['preview', $preview], ['result', $result]] as [$label, $envelope]) {
            $encoded = json_encode($envelope['findings'] ?? []);
            $this->assertStringContainsString(
                'invalid_style_slot',
                $encoded,
                "{$label}: the dead declarations must be reported, not silently swallowed"
            );
            $this->assertStringContainsString('--cta-title-size', $encoded, $label);
        }

        // The restored document is stored VERBATIM — restore is not a rewrite — and the
        // dead declarations simply do not paint.
        $stored = pp_get_composition($id);
        $this->assertSame('4rem', $stored[0]['style']['--cta-title-size'], 'the snapshot is replayed verbatim');

        $html = $this->renderStored($id);
        $this->assertStringContainsString('Legacy', $html, 'the page still renders');
        foreach (['4rem', '#f0f0f0', '#101014'] as $dead) {
            $this->assertStringNotContainsString($dead, $html, "the dead declaration {$dead} does not paint");
        }
    }

    // ── VALUE aliases: the `theme: "dark"` input value (#605) ────────────────
    //
    // The third and last alias surface, removed under the same Addendum #4 ruling as
    // the slot names (#603) and the prop keys (#604). Its shape differs from theirs:
    // a retired NAME is unread at render, whereas a retired VALUE leaves the prop
    // itself perfectly valid and simply falls outside its accepted set — so it
    // coerces to the prop default, which is the base render contract for every enum,
    // not an alias.
    //
    // What is NOT removed, and is asserted alongside every pin here: `theme: "muted"`
    // still EMITS the legacy `<root>--dark` CSS class (#570 DG-4). Output naming and
    // input aliasing are different things, and only the second one went.

    public function testAStoredRemovedThemeValueRendersTheDefaultBandNotTheMutedOne(): void
    {
        $id = pp_create_page('Stale theme value', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => 'Stale', 'body' => 'held from before #605', 'theme' => 'dark']],
        ]);

        $html = $this->renderStored($id);

        // The band renders — the page is not broken — but it renders as DEFAULT.
        $this->assertStringContainsString('Stale', $html, 'the page still renders');
        $this->assertStringNotContainsString('pp-section--dark', $html, 'no muted surface');
        $this->assertStringNotContainsString('pp-section--inverted', $html);

        // Storage is never rewritten behind the author.
        $this->assertSame('dark', pp_get_composition($id)[0]['props']['theme']);
    }

    public function testTheCanonicalMutedValueStillEmitsTheLegacyDarkClass(): void
    {
        // #570 DG-4, pinned on real stored bytes through the render loop: this is the
        // proof the input-value removal did not touch the emitted class NAME.
        // Re-homed to `grid` at #1023: section retired `theme` and with it the one
        // component whose modifier prefix differed from its name. The DG-4 guarantee this
        // pins is the emitted class NAME, which is a property of the theme prop rather
        // than of any component — so the host moved a third time at #1101, to `ppfixture`,
        // which is the only component left declaring a `theme` prop at all. The fixture
        // exists for exactly this (#1025) and keeps the legacy `--dark` output name
        // deliberately; it goes with this test in the v1 machinery sweep.
        $id = pp_create_page('Canonical muted band', 'draft');
        pp_update_composition($id, [
            ['component' => 'ppfixture', 'props' => ['title' => 'Muted', 'items' => [['number' => '1', 'label' => 'a']], 'theme' => 'muted']],
        ]);

        $this->assertStringContainsString('ppfixture--dark', $this->renderStored($id));
    }

    public function testANewWriteOfTheRemovedThemeValueIsRejected(): void
    {
        // THE HOST COULD NOT FOLLOW THE PROP THIS TIME, and that is worth stating because
        // every other re-homing in this file did. The claim is STRICTNESS: a `strict` enum
        // refuses an unadvertised value outright rather than coercing it, which is what
        // #605 established when `dark` was removed from `theme`. `ppfixture` declares a
        // `theme` prop but declares it NON-strict on purpose — its schema says it is kept
        // "because the theme-variant and friendly-error suites exercise enum COERCION" —
        // so hosting the strictness claim there would have asserted the opposite of what
        // the fixture is for. And after #1101 no shipped component declares `theme` at all.
        //
        // So the claim follows the PROPERTY rather than the prop: `layout` is `strict` on
        // five shipped components, including grid, and an unadvertised value there is
        // refused by the same rule with the same code and the same advertised-set message.
        // The `theme` half of the record — that `dark` was removed rather than aliased —
        // lives in grid's `retired_props` route, which names what replaced the whole prop.
        $composition = [['component' => 'grid', 'props' => ['title' => 'A', 'items' => [['title' => 'One', 'text' => 'a']], 'layout' => 'masonry']]];

        $result = pp_validate_action('create_page', ['title' => 'Removed enum value', 'composition' => $composition]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        $this->assertStringContainsString('cards, steps', $result->get_error_message());
        // AND NOT COERCED: a strict enum names the set rather than silently clamping to a
        // default, which is the half #605 was actually about.
        $this->assertStringNotContainsString('masonry', strtolower((string) $result->get_error_data()['coerced'] ?? ''));
    }

    // NAME KEPT DELIBERATELY at its original numeral, the way MeasureSurfaceTest's and
    // ComponentPropsTest's rosters keep theirs: renaming it on every rebuild breaks
    // `--filter` continuity for no fact. The roster inside is the fact.
    public function testAFreshCanonicalThemeWriteValidatesReadsBackAndRendersOnTheLastThemedBand(): void
    {
        // SEVEN BANDS UNTIL #1066 PR2, ONE NOW. Each rebuild took its `theme` prop with it —
        // testimonials (#958), hero (#986), section (#1023), cta (#1026), faq (#1046), embed
        // (#1066), and stats and logos in that issue's second half. grid is the last band
        // that can carry a canonical theme value at all, so this test asserts on the one
        // survivor rather than on a roster that would otherwise be empty.
        // Acceptance criterion 5: fresh-generation correctness. Every band component
        // that carries a `theme` accepts each of the three canonical values through
        // the REAL authoring surface, stores it verbatim, and renders the documented
        // class — `muted` under the legacy `--dark` name, `inverted` under its own,
        // `default` under none.
        $bands = [
            // grid left this roster at #1101 and `ppfixture` took its place — the roster's
            // THIRD re-homing, and the last one available: no shipped component declares a
            // `theme` prop any more. The fixture exists for exactly this (#1025); it keeps
            // the legacy `--dark` output name and the same three canonical values, so the
            // claim is unchanged and the class names move with the host.
            'ppfixture'    => ['title' => 'G', 'items' => [['number' => '1', 'label' => 'a']]],
            // testimonials is absent: the v2 rebuild removed its `theme` prop, whose
            // entire effect was value-styling the structural-CSS boundary forbids.
            // section is absent since #1023 for the same reason, and it took the one
            // naming oddity with it: it was the only component whose modifier prefix
            // (`pp-section--`) differed from its name, so the $prefixes map that existed
            // solely for it is gone too. cta is absent since #1026, and its departure
            // carries one measured fact worth keeping: on a full-width cta the `muted` and
            // `dark` renders were BYTE-IDENTICAL to `default`, so retiring `theme` there
            // cost exactly one rendered state (`inverted`) rather than three.
            // faq is absent since #1046, and its departure carries a measured fact of its
            // own, different from cta's: faq's `muted` was NOT byte-identical to `default`
            // — it drew a 1px `--color-border` rule top and bottom — so retiring `theme`
            // there cost two rendered states rather than one, and `retired_props` names
            // the `border` group alongside `background` for exactly that reason.
            // embed is absent since #1066, and its measured fact is faq's shape again with
            // one addition: `muted` drew the same 1px framing rule, AND `inverted`
            // re-coloured the CONTENT ink as well as the heading — which is why its route
            // names `typography.color` on `_band` and then goes on to say what an
            // inherited colour does NOT reach inside arbitrary author HTML. table is
            // rebuilt in that same issue and never appears here at all: it never declared
            // `theme`. The roster is THREE bands now and still means the same thing —
            // every component that declares `theme` round-trips its canonical values.
        ];

        foreach ($bands as $component => $props) {
            $prefix = $component;
            foreach (['default' => null, 'muted' => 'dark', 'inverted' => 'inverted'] as $theme => $slug) {
                $composition = [['component' => $component, 'props' => $props + ['theme' => $theme]]];

                $this->assertTrue(
                    pp_validate_action('create_page', ['title' => "Fresh {$component} {$theme}", 'composition' => $composition]),
                    "{$component}.theme={$theme} must validate through the authoring surface"
                );

                $id = pp_create_page("Fresh {$component} {$theme}", 'draft');
                pp_update_composition($id, $composition);
                $this->assertSame($theme, pp_get_composition($id)[0]['props']['theme'],
                    "{$component}.theme={$theme} must read back verbatim");

                $html = $this->renderStored($id);
                if ($slug === null) {
                    // No THEME modifier. Layout modifiers (cta--full-width, and the
                    // like) are a different axis and must be left alone.
                    $this->assertStringNotContainsString("{$prefix}--dark", $html,
                        "{$component}.theme=default must emit no theme modifier class");
                    $this->assertStringNotContainsString("{$prefix}--inverted", $html,
                        "{$component}.theme=default must emit no theme modifier class");
                } else {
                    $this->assertStringContainsString("{$prefix}--{$slug}", $html,
                        "{$component}.theme={$theme} must emit {$prefix}--{$slug}");
                }
            }
        }
    }

    public function testRestoreOfASnapshotHoldingTheRemovedThemeValueSucceedsAndReportsIt(): void
    {
        // Acceptance criterion 4, on the same #233 contract the slot-name pin above
        // proves: restore RESTORES and REPORTS, never blocks. A snapshot carrying the
        // removed `theme` value is exactly the case an operator hits after #605.
        $id = pp_create_page('Restore removed theme value', 'draft');
        // `grid` since #1023, same reason as the refusal pin above: the #233
        // restore-and-report contract is the subject, not the component.
        // v1: a snapshot as a pre-#605 install holds it.
        pp_update_composition($id, [
            ['component' => 'grid', 'props' => ['title' => 'Legacy', 'items' => [['title' => 'One', 'text' => 'a']], 'theme' => 'dark']],
        ]);
        // v2: pushes v1 onto the history ring.
        pp_update_composition($id, [
            ['component' => 'grid', 'props' => ['title' => 'Now', 'items' => [['title' => 'One', 'text' => 'a']]]],
        ]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);
        $result  = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        // NEVER BLOCKS — undo must not become impossible because a value was retired.
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        // REPORTS, on both channels, so the operator sees it before and after.
        foreach ([['preview', $preview], ['result', $result]] as [$label, $envelope]) {
            $encoded = json_encode($envelope['findings'] ?? []);
            // THE FINDING CHANGED CLASS AT #1101, and it changed for the better. The stored
            // `dark` used to be an out-of-set VALUE on a live enum (`invalid_prop_value`,
            // naming "default, muted, inverted"). grid's rebuild retired the whole PROP, so
            // the same snapshot now reports `retired_prop` — which tells the operator where
            // the value WENT (`_band` -> `background`) instead of offering three values that
            // no longer exist either. The claim this test makes is unchanged: restore is not
            // blocked (#233), the stored bytes are not rewritten, and the problem is
            // REPORTED rather than swallowed.
            $this->assertStringContainsString('retired_prop', $encoded,
                "{$label}: the removed value must be reported, not silently swallowed");
            $this->assertStringContainsString('no longer has a prop \"theme\"', $encoded, $label);
            $this->assertStringContainsString('`_band`', $encoded, $label . ' — and it names the route');
        }

        // Restored VERBATIM — restore is not a rewrite — and the band renders default.
        $this->assertSame('dark', pp_get_composition($id)[0]['props']['theme']);
        $html = $this->renderStored($id);
        $this->assertStringContainsString('Legacy', $html, 'the page still renders');
        $this->assertStringNotContainsString('pp-section--dark', $html, 'but not as the muted band');
    }
}
