<?php
/**
 * tests/StoredCompositionAliasRenderTest.php
 *
 * The stored-composition legacy-name contract (issues #575 / #495, amended by #603).
 *
 * ONE surface is left. The SLOT-NAME surface is GONE (#603) and its removal is what
 * the first half of this class now pins; the PROP-KEY surface is live and is pinned
 * in the second half.
 *
 *   SLOT NAME   pp_legacy_slot_aliases() — REMOVED. Shipped empty in #575, populated
 *               by #576 with 51 renames, deleted outright by #603 along with its two
 *               resolution helpers, the pp_normalize_legacy_slots() wrapper and the
 *               public filter. A slot name a component does not declare is rejected
 *               at write and dropped at render, and nothing sits in between.
 *   PROP KEY    pp_legacy_prop_aliases() (lib/admin.php) — LIVE. The
 *               cta_text/cta_url -> button_text/button_url mapping (#495), extended
 *               by #576 with hero's button family, cta.text -> body and
 *               heading_align -> title_align.
 *
 * Why the two surfaces diverged. #575's bounded rule said a legacy name resolves IFF
 * a shipped mechanism promises the already-stored document will render, and named
 * `restore_composition` (#233) as that mechanism. The #570 decision record, Addendum
 * #4, RETIRES that mechanism-trust rule for the slot surface: restore's actual
 * contract is that it restores and REPORTS, never that what it restores still paints.
 * Under the governing ruling — backward compatibility, stale demo pages, old
 * compositions, migrations and legacy tolerance are all NON-GOALS — the slot map had
 * no basis left, so it went. The prop-key surface is a separate question, filed as
 * its own issue.
 *
 * Everything renders through the EXACT loop templates/composition.php runs, so what
 * is asserted is what a visitor's browser receives, not an intermediate array.
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
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Unstyled'],
            'style'     => ['--hero-text' => '#f0f0f0'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('#f0f0f0', $html, 'the legacy declaration does not paint');
        $this->assertStringNotContainsString('--hero-text', $html, 'and its own name is never emitted');
        $this->assertStringNotContainsString(
            '--hero-heading-color',
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
            ['component' => 'hero', 'props' => ['title' => 'Canonical']],
        ]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--hero-text' => '#f0f0f0'],
        ]);

        $this->assertFalse($result['ok'], 'a legacy slot name is not authorable');
        $this->assertStringContainsString('--hero-text', (string) ($result['error'] ?? ''));
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
    public function testAStoredLegacySlotNameNowFailsWholeCompositionValidation(): void
    {
        $id = pp_create_page('Legacy slot blocks edits', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'Legacy'], 'style' => ['--hero-text' => '#f0f0f0']],
            ['component' => 'section', 'props' => ['title' => 'Band', 'body' => 'Copy.']],
        ]);

        // An edit to the OTHER band, touching nothing about the hero.
        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Renamed band'],
        ]);

        $this->assertFalse($result['ok'], 'the stale declaration is now visible to validation');
        $this->assertSame('invalid_style_slot', $result['error_code'] ?? null);
        $this->assertStringContainsString(
            '--hero-text',
            (string) ($result['error'] ?? ''),
            'the error names the dead slot on the band the operator never touched'
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
            'style'           => ['--hero-heading-color' => '#f0f0f0'],
        ]);
        $this->assertTrue($merge['ok'], (string) ($merge['error'] ?? ''));
        $this->assertArrayHasKey(
            '--hero-text',
            pp_get_composition($id)[0]['style'],
            'the merge did not evict the dead key'
        );
        $stillBlocked = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Renamed band'],
        ]);
        $this->assertFalse($stillBlocked['ok'], 'so the sibling band is still unwritable');

        $repaired = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [
                ['component' => 'hero', 'props' => ['title' => 'Legacy'], 'style' => ['--hero-heading-color' => '#f0f0f0']],
                ['component' => 'section', 'props' => ['title' => 'Band', 'body' => 'Copy.']],
            ],
        ]);
        $this->assertTrue($repaired['ok'], (string) ($repaired['error'] ?? ''));

        // Recovered: the sibling edit that failed above now succeeds, and the value
        // the author meant paints under the canonical name.
        $after = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 1,
            'props'           => ['title' => 'Renamed band'],
        ]);
        $this->assertTrue($after['ok'], (string) ($after['error'] ?? ''));
        $this->assertStringContainsString('--hero-heading-color: #f0f0f0', $this->renderStored($id));
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
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => ['title' => 'Both'],
            'style'     => ['--hero-text' => '#111111', '--hero-heading-color' => '#222222'],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('--hero-heading-color: #222222', $html, 'the canonical value paints');
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
            ['component' => 'hero', 'props' => ['title' => 'Fresh'], 'style' => [
                '--hero-heading-color' => '#f0f0f0',
                '--hero-heading-size'  => '4rem',
            ]],
            ['component' => 'grid', 'props' => ['title' => 'Cards', 'items' => [
                ['title' => 'One', 'text' => 'a', 'style' => ['--grid-item-bg' => '#101014']],
            ]], 'style' => ['--grid-heading-measure' => '40rem']],
            ['component' => 'section', 'props' => ['title' => 'Band', 'body' => 'Copy.'], 'style' => [
                '--section-body-color' => '#334455',
            ]],
        ];

        $id     = pp_create_page('Fresh canonical page', 'draft');
        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => $authored,
        ]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        // Read back: every authored style map survives the round trip untouched.
        $stored = pp_get_composition($id);
        $this->assertSame($authored[0]['style'], $stored[0]['style'], 'hero style map is byte-identical');
        $this->assertSame($authored[1]['style'], $stored[1]['style'], 'grid style map is byte-identical');
        $this->assertSame(
            $authored[1]['props']['items'][0]['style'],
            $stored[1]['props']['items'][0]['style'],
            'the per-item style map is byte-identical'
        );
        $this->assertSame($authored[2]['style'], $stored[2]['style'], 'section style map is byte-identical');

        // Render: every authored declaration reaches the page.
        $html = $this->renderStored($id);
        $this->assertStringContainsString('--hero-heading-color: #f0f0f0', $html);
        $this->assertStringContainsString('--hero-heading-size: 4rem', $html);
        $this->assertStringContainsString('--grid-heading-measure: 40rem', $html);
        $this->assertStringContainsString('--grid-item-bg: #101014', $html);
        $this->assertStringContainsString('--section-body-color: #334455', $html);

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
            ['component' => 'section', 'props' => ['title' => 'Band', 'body' => 'Copy.'],
             'style' => ['--section-text' => '#334455']],
        ]);

        // STORED: paints nothing, under either name.
        $html = $this->renderStored($id);
        $this->assertStringNotContainsString('#334455', $html, 'the stored legacy declaration is dead');
        $this->assertStringNotContainsString('--section-body-color', $html, 'and nothing renames it');
        $this->assertStringNotContainsString('--section-text', $html);

        // The band can no longer be edited at all — the stale declaration is visible
        // to the whole-array validation `update_component` runs. This is the
        // inversion: #594 made this edit succeed, #603 makes it fail on purpose.
        $blocked = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['title' => 'Renamed'],
        ]);
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
            'style'           => ['--section-title-size' => '3rem'],
        ]);
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
            $this->assertStringContainsString('--hero-title-size', $encoded, $label);
        }

        // The restored document is stored VERBATIM — restore is not a rewrite — and the
        // dead declarations simply do not paint.
        $stored = pp_get_composition($id);
        $this->assertSame('4rem', $stored[0]['style']['--hero-title-size'], 'the snapshot is replayed verbatim');

        $html = $this->renderStored($id);
        $this->assertStringContainsString('Legacy', $html, 'the page still renders');
        foreach (['4rem', '#f0f0f0', '#101014'] as $dead) {
            $this->assertStringNotContainsString($dead, $html, "the dead declaration {$dead} does not paint");
        }
    }
}
