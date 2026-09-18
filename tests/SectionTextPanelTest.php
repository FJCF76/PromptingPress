<?php
/**
 * tests/SectionTextPanelTest.php
 *
 * Section text-panel layout (issue 104): a two-column "text + content panel"
 * layout where the right column is a server-validated panel built from PROPS
 * (panel_heading / panel_body / panel_items / panel CTA) — NOT nested
 * components, so the "components never nest components" invariant holds. The
 * panel is styleable per-instance through the --section-panel-* style slots.
 *
 * Three layers are pinned here:
 *   1. Render — the panel column, list, and CTA render (and degrade) correctly,
 *      and the left column keeps the normal section header/content markup.
 *   2. Validation — the new flat props and the new style slots pass the SHARED
 *      engine (pp_validate_composition), and an unknown panel-ish prop is still
 *      rejected by the #147 prop-key gate.
 *   3. CSS contract — the panel box + text route through the slots, the list
 *      markers are restored (base reset strips them), the panel CTA color routes
 *      through the documented per-component --btn-* idiom, and the two columns
 *      top-align at >=768px. (The generic "every slot is consumed / unbypassed"
 *      proof is owned by StyleSlotContractTest #305; these are the value-level
 *      and structure pins that file does not assert.)
 */

use PHPUnit\Framework\TestCase;

class SectionTextPanelTest extends TestCase
{
    private string $themeRoot;
    private string $componentsCss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot     = dirname(__DIR__);
        $this->componentsCss = file_get_contents($this->themeRoot . '/assets/css/components.css');
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
    }

    private function render(array $props): string
    {
        ob_start();
        pp_get_component('section', $props);
        return ob_get_clean();
    }

    private function fullPanelProps(array $overrides = []): array
    {
        return array_merge([
            'layout'          => 'text-panel',
            'eyebrow'         => 'Honest',
            'title'           => 'No fine print',
            'body'            => '<p>Left column copy.</p>',
            'panel_heading'   => 'Who is it for?',
            'panel_body'      => 'Teams of every size.',
            'panel_items'     => ['Freelancers', 'Small agencies'],
            'panel_cta_text'  => 'Get started',
            'panel_cta_url'   => '/signup',
        ], $overrides);
    }

    // ── 1. Render ─────────────────────────────────────────────────────────

    public function testTextPanelRendersBothColumns(): void
    {
        $html = $this->render($this->fullPanelProps());

        $this->assertStringContainsString('section--text-panel', $html, 'root carries the layout class.');
        $this->assertStringContainsString('section__grid', $html, 'two columns share the section grid.');
        // Left column: normal section header + content.
        $this->assertStringContainsString('section__body', $html);
        $this->assertMatchesRegularExpression('/section__content[^>]*>\s*<p>Left column copy\.<\/p>/s', $html);
        // Right column: the panel.
        $this->assertStringContainsString('class="section__panel"', $html);
        $this->assertStringContainsString('<h3 class="section__panel-heading">Who is it for?</h3>', $html);
        $this->assertStringContainsString('Teams of every size.', $html);
    }

    public function testPanelListRendersEachItem(): void
    {
        $html = $this->render($this->fullPanelProps());
        $this->assertStringContainsString('<ul class="section__panel-list">', $html);
        $this->assertStringContainsString('<li class="section__panel-item">Freelancers</li>', $html);
        $this->assertStringContainsString('<li class="section__panel-item">Small agencies</li>', $html);
    }

    public function testPanelListSkipsNonStringAndEmptyEntries(): void
    {
        $html = $this->render($this->fullPanelProps([
            'panel_items' => ['Keep', '', ['nested'], 42, 'Also keep'],
        ]));
        $this->assertSame(2, substr_count($html, 'section__panel-item'), 'only non-empty string items render.');
        $this->assertStringContainsString('>Keep</li>', $html);
        $this->assertStringContainsString('>Also keep</li>', $html);
    }

    public function testPanelCtaRendersWithTextAndUrl(): void
    {
        $html = $this->render($this->fullPanelProps());
        $this->assertMatchesRegularExpression(
            '/<a href="\/signup" class="section__panel-cta btn">\s*Get started\s*<\/a>/s',
            $html
        );
    }

    public function testPanelCtaSuppressedWithoutUrl(): void
    {
        $html = $this->render($this->fullPanelProps(['panel_cta_url' => '']));
        $this->assertStringNotContainsString('section__panel-cta', $html, 'CTA needs both a label and a URL.');
    }

    public function testPanelCtaSuppressedWithoutText(): void
    {
        $html = $this->render($this->fullPanelProps(['panel_cta_text' => '']));
        $this->assertStringNotContainsString('section__panel-cta', $html);
    }

    /**
     * `panel_cta_variant` retired at #1023; the two cases it had collapse into one.
     *
     * It was an enum of four bundled button treatments (primary/secondary/outline/ghost)
     * that derived a `btn--<variant>` modifier. That bundle is exactly what the UDC
     * expresses directly: the `panel-cta` role's `border`, `background` and `typography`,
     * or one of the shipped `button` / `button-secondary` presets. So the button always
     * renders as the bare `.btn` now, and a variant is a design the author applies rather
     * than a name they pick from a closed list.
     *
     * Both old cases asserted the same underlying fact from opposite sides — a valid
     * variant adds its modifier, an invalid one does not — and neither can be true now,
     * so they become one assertion that no modifier is ever derived.
     */
    public function testThePanelCtaRendersAsTheBareButtonWithNoDerivedVariant(): void
    {
        $html = $this->render($this->fullPanelProps());
        $this->assertStringContainsString('class="section__panel-cta btn"', $html);
        $this->assertStringNotContainsString('btn--', $html,
            'no variant modifier is derived any more — the treatment is the role or a preset.');

        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertArrayNotHasKey('panel_cta_variant', $schema['props']);
        $this->assertArrayHasKey('panel_cta_variant', $schema['retired_props'],
            'the retirement must offer a route, not just remove the prop.');
        $this->assertStringContainsString('panel-cta', $schema['retired_props']['panel_cta_variant'],
            'and the route must name the role that replaced it.');
    }

    public function testPanelCtaUrlIsEscaped(): void
    {
        // The CTA href routes through esc_url — a URL with an illegal space is
        // sanitized rather than emitted verbatim.
        $html = $this->render($this->fullPanelProps([
            'panel_cta_url' => 'https://example.com/a b',
        ]));
        $this->assertStringContainsString('href="https://example.com/ab"', $html);
        $this->assertStringNotContainsString('example.com/a b', $html);
    }

    public function testPanelHeadingAndBodyAreEscaped(): void
    {
        $html = $this->render($this->fullPanelProps([
            'panel_heading' => 'A & B <x>',
            'panel_body'    => 'C & D <y>',
            'panel_items'   => ['E & F <z>'],
        ]));
        $this->assertStringNotContainsString('<x>', $html);
        $this->assertStringNotContainsString('<y>', $html);
        $this->assertStringNotContainsString('<z>', $html);
        $this->assertStringContainsString('A &amp; B', $html);
    }

    // ── Fallback: no panel content degrades to text-only ──────────────────

    public function testTextPanelWithoutContentFallsBackToTextOnly(): void
    {
        $html = $this->render([
            'layout' => 'text-panel',
            'title'  => 'Just text',
            'body'   => '<p>Nothing on the right.</p>',
        ]);
        $this->assertStringContainsString('section--text-only', $html, 'empty panel degrades to text-only.');
        $this->assertStringNotContainsString('section--text-panel', $html);
        $this->assertStringNotContainsString('section__panel', $html);
    }

    public function testTextPanelWithOnlyHeadingStillRendersPanel(): void
    {
        $html = $this->render([
            'layout'        => 'text-panel',
            'title'         => 'Has a panel',
            'body'          => '<p>Body.</p>',
            'panel_heading' => 'Solo heading',
        ]);
        $this->assertStringContainsString('section--text-panel', $html);
        $this->assertStringContainsString('class="section__panel"', $html);
    }

    public function testTextPanelWithOnlyItemsStillRendersPanel(): void
    {
        $html = $this->render([
            'layout'      => 'text-panel',
            'title'       => 'Has a panel',
            'body'        => '<p>Body.</p>',
            'panel_items' => ['Only a list'],
        ]);
        $this->assertStringContainsString('section--text-panel', $html);
        $this->assertStringContainsString('<li class="section__panel-item">Only a list</li>', $html);
    }

    public function testTextPanelWithOnlyCtaStillRendersPanel(): void
    {
        $html = $this->render([
            'layout'         => 'text-panel',
            'title'          => 'Has a panel',
            'body'           => '<p>Body.</p>',
            'panel_cta_text' => 'Only a button',
            'panel_cta_url'  => '/go',
        ]);
        $this->assertStringContainsString('section--text-panel', $html);
        $this->assertStringContainsString('section__panel-cta', $html);
    }

    public function testTextPanelWithOnlyBodyRendersPanelNotDropped(): void
    {
        // panel_body counts toward $has_panel, so a body-only panel is never
        // silently dropped to text-only (authored content preservation).
        $html = $this->render([
            'layout'     => 'text-panel',
            'title'      => 'Has a panel',
            'body'       => '<p>Left.</p>',
            'panel_body' => 'A supporting note.',
        ]);
        $this->assertStringContainsString('section--text-panel', $html);
        $this->assertStringContainsString('<p class="section__panel-body">A supporting note.</p>', $html);
    }

    public function testNonArrayPanelItemsCoerceToEmptyWithoutWarning(): void
    {
        // A non-array panel_items (e.g. a string) must coerce to no list and not
        // emit a PHP warning — with a heading present so the panel still renders.
        $html = $this->render([
            'layout'        => 'text-panel',
            'title'         => 'Has a panel',
            'body'          => '<p>Left.</p>',
            'panel_heading' => 'H',
            'panel_items'   => 'oops-not-an-array',
        ]);
        $this->assertStringContainsString('class="section__panel"', $html);
        $this->assertStringNotContainsString('section__panel-item', $html);
        $this->assertStringNotContainsString('section__panel-list', $html);
    }

    // ── Style slots reach the rendered root ───────────────────────────────

    public function testPanelDesignCompilesIntoTheBandBlockNotAnInlineStyle(): void
    {
        // The same two values, followed to where they land. A v2 band emits no style
        // attribute, so a panel surface and its ink are the `panel` role's
        // `background.fill` and `typography.color`, emitted into the band's scoped block.
        $item = [
            'component' => 'section',
            'id'        => 'pp-77aa22bb',
            'props'     => $this->fullPanelProps(),
            'udc'       => ['panel' => [
                'background' => ['fill' => '#0f172a'],
                'typography' => ['color' => '#f8fafc'],
            ]],
        ];
        $this->assertNull(pp_udc_validate_map($item['udc'], 'section'));

        $css = pp_udc_band_css($item);
        $this->assertStringContainsString('[data-pp-band="pp-77aa22bb"] .section__panel{', $css);
        $this->assertStringContainsString('background:#0f172a', $css);
        $this->assertStringContainsString('color:#f8fafc', $css);

        $html = $this->render($this->fullPanelProps(['__pp_udc_band' => 'pp-77aa22bb']));
        $this->assertStringNotContainsString('style=', $html,
            'no inline custom properties — the whole slot map is gone.');
    }

    // ── 2. Validation (shared engine) ─────────────────────────────────────

    public function testTextPanelPropsPassSharedValidation(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => $this->fullPanelProps(),
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'A section with all text-panel props must validate.'
        );
    }

    /**
     * The same six panel values, authored through the surface that replaced the slots.
     *
     * Rule 14.1 still applies: this goes through pp_validate_composition(), the real
     * gate, not a raw meta write. What changed is that the six flat slot names became
     * five GROUPS on one role, which is the whole point of the taxonomy — an author sets
     * `border` once rather than reaching for three separately-named border slots.
     */
    public function testTextPanelPanelRoleMapValidates(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'layout' => 'text-panel', 'panel_heading' => 'H'],
            'udc'       => ['panel' => [
                'background' => ['fill' => '#0f172a'],
                'border'     => ['color' => '#334155', 'width' => '1px', 'radius' => '1rem'],
                'spacing'    => ['padding' => '2rem'],
                'typography' => ['color' => '#f8fafc'],
            ]],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'The panel role must accept all five groups the six retired slots covered.'
        );
    }

    public function testUnknownPanelPropIsRejected(): void
    {
        // The #147 prop-key gate reads schema.json props; a plausible-but-absent
        // panel key must still be rejected (reported-success-without-effect class).
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'layout' => 'text-panel', 'panel_footer' => 'nope'],
        ]];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unknown_prop', $result->get_error_code());
        $this->assertStringContainsString('panel_footer', $result->get_error_message());
    }

    // ── 3. CSS contract (value-level + structure pins) ────────────────────

    private function sectionBlock(): string
    {
        // The COMPONENT: section block through the next COMPONENT header.
        preg_match(
            '/COMPONENT:\s*section\b(.*?)(?=\/\*\s*={5,}[^*]*?COMPONENT:|\z)/s',
            $this->componentsCss,
            $m
        );
        return $m[1] ?? '';
    }

    /**
     * INVERTED at #1023. This was the dead-slot guard: a declared slot that no rule
     * CONSUMES is a value an author can set and never see, so each of the six had to
     * appear as `var(<slot>, <fallback>)` inside the component's block.
     *
     * The v2 equivalent of "declared but never consumed" is a role default the engine
     * never emits, and the guard for it is structural rather than textual: the emitter
     * reads the same `roles` block the schema declares, so a declared group cannot go
     * unconsumed. What CAN still go wrong is the reverse — a stale slot name left behind
     * in the stylesheet, reachable by nothing — so that is what this now asserts.
     */
    public function testNoRetiredPanelSlotIsStillConsumedInTheStylesheet(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($this->themeRoot . '/assets/css/components.css'));
        foreach ([
            '--section-panel-bg', '--section-panel-border-color', '--section-panel-border-width',
            '--section-panel-radius', '--section-panel-padding', '--section-panel-text',
            '--section-panel-font', '--section-panel-marker-color',
        ] as $slot) {
            $this->assertStringNotContainsString($slot, $css,
                "{$slot} is retired — a surviving consumption would be a rule nothing can reach.");
        }

        // And the values live on the role instead, where the emitter reads them.
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $panel  = $schema['roles']['panel']['defaults'];
        $this->assertSame('@color-surface', $panel['background']['fill']);
        $this->assertSame('@space-lg', $panel['spacing']['padding']);
        $this->assertSame('@radius', $panel['border']['radius']);
        $this->assertSame('@color-text', $panel['typography']['color']);
    }

    public function testPanelListRestoresMarkersAndIndent(): void
    {
        $block = $this->sectionBlock();
        $this->assertMatchesRegularExpression(
            '/\.section__panel-list\s*\{[^}]*list-style:\s*disc/s',
            $block,
            'the panel list must restore disc markers stripped by the base reset (issue 104).'
        );
        // The indent is the `panel-list` role's `spacing.padding-left` since #1023 — a
        // token-valued padding is a design value whatever it is restoring, so it cannot
        // live in a v2 component's stylesheet. The disc above still can: the global reset
        // strips the marker and putting it back is normalize.
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertSame(
            '@space-lg',
            $schema['roles']['panel-list']['defaults']['spacing']['padding-left'],
            'the panel list keeps its indent, as a role default.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__panel-list\s*\{[^}]*list-style:\s*disc/s',
            $block,
            'the panel list indent must use a spacing token.'
        );
    }

    public function testTextPanelColumnsTopAlignAtDesktop(): void
    {
        // The panel column must top-align (align-items:start) rather than center
        // like the image variants — pinned inside a >=768px media rule.
        // Not anchored to the at-rule's FIRST rule any more: since #1023 the desktop
        // at-rule opens with the shared two-column track and the text-panel override
        // follows it in the same block, which is the arrangement it describes.
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\)\s*\{.*?\.section--text-panel \.section__grid\s*\{[^}]*align-items:\s*start/s',
            $this->componentsCss
        );
    }

    // ── 4. List markers (issue 339) ───────────────────────────────────────
    //
    // A list can carry a marker other than the default disc — check / dash /
    // arrow — with an authorable marker colour, on the panel list AND on body
    // lists. Generic marker capability; `disc` is the untouched default. The
    // shared paint lives in components.css; StyleSlotContractTest proves the
    // colour slots are consumed and unbypassed. Here we pin the render-time
    // class wiring, the clamp, the byte-identical default, and the section
    // block's colour-slot mapping. The cross-sheet PAINT (that the marker
    // actually renders over the issue-295 disc rules) is pinned in
    // tests/e2e/style-render.spec.ts.

    public function testPanelItemsMarkerCheckAddsSharedTreatmentClasses(): void
    {
        $html = $this->render($this->fullPanelProps(['panel_items_marker' => 'check']));
        $this->assertStringContainsString(
            'class="section__panel-list pp-marker-list pp-marker-list--check"',
            $html
        );
    }

    public function testPanelItemsMarkerDashAndArrowSelectTheirModifier(): void
    {
        $dash = $this->render($this->fullPanelProps(['panel_items_marker' => 'dash']));
        $this->assertStringContainsString('pp-marker-list pp-marker-list--dash', $dash);

        $arrow = $this->render($this->fullPanelProps(['panel_items_marker' => 'arrow']));
        $this->assertStringContainsString('pp-marker-list pp-marker-list--arrow', $arrow);
    }

    public function testPanelItemsMarkerDefaultsToDiscWithNoExtraClass(): void
    {
        // Byte-identical to the pre-339 markup: a plain section__panel-list.
        $html = $this->render($this->fullPanelProps());
        $this->assertStringContainsString('<ul class="section__panel-list">', $html);
        $this->assertStringNotContainsString('pp-marker-list', $html);
    }

    public function testPanelItemsMarkerDiscExplicitIsAlsoBare(): void
    {
        $html = $this->render($this->fullPanelProps(['panel_items_marker' => 'disc']));
        $this->assertStringContainsString('<ul class="section__panel-list">', $html);
        $this->assertStringNotContainsString('pp-marker-list', $html);
    }

    public function testPanelItemsMarkerInvalidValueClampsToDisc(): void
    {
        $html = $this->render($this->fullPanelProps(['panel_items_marker' => 'checklist']));
        $this->assertStringContainsString('<ul class="section__panel-list">', $html);
        $this->assertStringNotContainsString('pp-marker-list', $html);
        $this->assertStringNotContainsString('checklist', $html);
    }

    public function testBodyMarkerCheckAddsContainerModifier(): void
    {
        $html = $this->render([
            'body'        => '<ul><li>Fast</li><li>Honest</li></ul>',
            'body_marker' => 'check',
        ]);
        $this->assertStringContainsString(
            'class="section__content section__content--marker-check"',
            $html
        );
    }

    public function testBodyMarkerAppliesInTextPanelLayoutToo(): void
    {
        // The body column exists in every layout that renders body; the marker
        // modifier must reach it in the text-panel layout as well.
        $html = $this->render($this->fullPanelProps(['body_marker' => 'arrow']));
        $this->assertStringContainsString('section__content--marker-arrow', $html);
    }

    public function testBodyMarkerDefaultsToDiscWithNoModifier(): void
    {
        $html = $this->render(['body' => '<ul><li>Fast</li></ul>']);
        $this->assertStringContainsString('<div class="section__content">', $html);
        $this->assertStringNotContainsString('section__content--marker', $html);
    }

    public function testBodyMarkerInvalidValueClampsToDisc(): void
    {
        $html = $this->render(['body' => '<ul><li>Fast</li></ul>', 'body_marker' => 'feature-list']);
        $this->assertStringContainsString('<div class="section__content">', $html);
        $this->assertStringNotContainsString('section__content--marker', $html);
        $this->assertStringNotContainsString('feature-list', $html);
    }

    public function testBodyMarkerDashSelectsItsModifier(): void
    {
        // Symmetry with the panel matrix: every non-disc value wires a modifier.
        $html = $this->render(['body' => '<ul><li>Fast</li></ul>', 'body_marker' => 'dash']);
        $this->assertStringContainsString('class="section__content section__content--marker-dash"', $html);
    }

    public function testBodyMarkerDiscExplicitIsAlsoBare(): void
    {
        $html = $this->render(['body' => '<ul><li>Fast</li></ul>', 'body_marker' => 'disc']);
        $this->assertStringContainsString('<div class="section__content">', $html);
        $this->assertStringNotContainsString('section__content--marker', $html);
    }

    public function testMarkerPropsPassSharedValidation(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => $this->fullPanelProps([
                'panel_items_marker' => 'check',
                'body_marker'        => 'arrow',
            ]),
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'The panel_items_marker and body_marker props must be known to the shared engine.'
        );
    }

    /**
     * THE MARKER-COLOUR SLOTS ARE RETIRED, and this is the pin for the 7A-2 ruling.
     *
     * Both slots mapped onto the shared `--pp-list-marker-color` plumbing var, which is
     * read by a `li::before`. Ruling A3 defers PSEUDO-ELEMENTS to their own ruling, so no
     * role can address that glyph at any value — the colour has no v2 home, and inventing
     * one would have pre-empted a ruling the owner has not made.
     *
     * The GLYPH CHOICE stays authorable, as the `body_marker` / `panel_items_marker`
     * props, because a glyph is content. Only its colour went, and the rendered default
     * does not move: the plumbing var falls back to `var(--color-accent)`, which is
     * exactly what both slots defaulted to. Measured live exposure: zero marker-variant
     * lists on any of the owner's five content pages.
     *
     * Recorded beside the item-grain deferral in the Addendum B draft's exclusion list,
     * so the two open pseudo-element questions sit together.
     */
    public function testTheMarkerColoursAreRetiredButTheGlyphChoiceIsNot(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($this->themeRoot . '/assets/css/components.css'));
        foreach (['--section-panel-marker-color', '--section-body-marker-color'] as $slot) {
            $this->assertStringNotContainsString($slot, $css,
                "{$slot} is retired — ruling A3 defers pseudo-elements, so it has no role home.");
        }

        // The shared glyph still paints, through the plumbing var's accent fallback.
        $this->assertMatchesRegularExpression(
            '/color:\s*var\(--pp-list-marker-color,\s*var\(--color-accent\)\)/',
            $css,
            'the marker glyph keeps the colour the retired slots defaulted to.'
        );

        // And both glyph choices are still props, because a glyph is content.
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        foreach (['body_marker', 'panel_items_marker'] as $prop) {
            $this->assertSame(['disc', 'check', 'dash', 'arrow'], $schema['props'][$prop]['values'],
                "{$prop} keeps its four glyphs.");
        }
    }

    public function testSharedMarkerGlyphsDefinedOnceInStylesheet(): void
    {
        // One definition, shared by grid + panel + body: the check glyph is
        // defined for the grid bullet, the panel .pp-marker-list--check, and the
        // body .section__content--marker-check together — not duplicated per
        // component. Pin that the three consumers share a single content rule.
        $css = $this->componentsCss;
        $this->assertMatchesRegularExpression(
            '/\.grid__item-bullet::before,\s*\.pp-marker-list--check > li::before,\s*\.section__content--marker-check > ul > li::before\s*\{\s*content:\s*"\\\\2713"/s',
            $css,
            'The check glyph must be one shared rule across grid, panel, and body consumers.'
        );
        // The dash and arrow marker values also exist (generic, not check-only).
        $this->assertStringContainsString('content: "\2013"', $css);
        $this->assertStringContainsString('content: "\2192"', $css);
    }

    // ── 5. Paired label/value rows (issue 334) ────────────────────────────
    //
    // panel_items entries may be a plain string (a bullet, unchanged) OR a
    // { label, value, style? } object rendered as a two-part row. String and
    // paired-row entries mix in one <ul>. Rows are not bullets: they carry
    // list-style:none and their marker glyph is suppressed. A row's optional
    // per-row style routes through the SAME shared engine + item_eligible slots
    // as grid's per-card style (issue 306/323) — no second validator, no new
    // colour grammar. The rendered PAINT (mono font, row colour) is pinned in
    // tests/e2e/style-render.spec.ts; these are the markup/validation/CSS pins.

    public function testPanelPairedRowRendersLabelAndValue(): void
    {
        $html = $this->render($this->fullPanelProps([
            'panel_items' => [['label' => 'Uptime', 'value' => '99.9%']],
        ]));
        $this->assertStringContainsString('<li class="section__panel-row"', $html);
        $this->assertStringContainsString('<span class="section__panel-row-label">Uptime</span>', $html);
        $this->assertStringContainsString('<span class="section__panel-row-value">99.9%</span>', $html);
        // A row is NOT a bullet — it does not get the plain bullet item class.
        $this->assertStringNotContainsString('<li class="section__panel-item">Uptime', $html);
    }

    public function testExistingStringFormRendersByteIdentical(): void
    {
        // Backward-compat: an all-string panel_items array must render exactly
        // the pre-334 markup — one <ul> of section__panel-item bullets, no rows.
        $html = $this->render($this->fullPanelProps([
            'panel_items' => ['Freelancers', 'Small agencies'],
        ]));
        // The list container and each bullet <li> are unchanged from pre-334, and
        // no paired-row markup appears when every entry is a string.
        $this->assertStringContainsString('<ul class="section__panel-list">', $html);
        $this->assertStringContainsString('<li class="section__panel-item">Freelancers</li>', $html);
        $this->assertStringContainsString('<li class="section__panel-item">Small agencies</li>', $html);
        $this->assertStringNotContainsString('section__panel-row', $html);
        // No paired-row spans in the panel list when every entry is a string.
        $this->assertStringNotContainsString('section__panel-row-label', $html);
    }

    public function testPanelMixesStringAndPairedRows(): void
    {
        $html = $this->render($this->fullPanelProps([
            'panel_items' => ['Included', ['label' => 'Plan', 'value' => 'Pro']],
        ]));
        // Both shapes render, in one list, in order.
        $this->assertStringContainsString('<li class="section__panel-item">Included</li>', $html);
        $this->assertStringContainsString('<li class="section__panel-row"', $html);
        $this->assertMatchesRegularExpression('/Included.*section__panel-row/s', $html);
    }

    public function testPanelPairedRowLabelAndValueAreEscaped(): void
    {
        $html = $this->render($this->fullPanelProps([
            'panel_items' => [['label' => 'A & B', 'value' => '<x>']],
        ]));
        $this->assertStringContainsString('A &amp; B', $html);
        $this->assertStringContainsString('&lt;x&gt;', $html);
        $this->assertStringNotContainsString('<x>', $html);
    }

    public function testPanelPairedRowAcceptsLabelOnlyOrValueOnly(): void
    {
        // A partial row still renders authored content rather than silently
        // dropping it (mirrors the panel's other never-drop-content rules); the
        // absent side renders as an empty span.
        $labelOnly = $this->render($this->fullPanelProps([
            'panel_items' => [['label' => 'Solo']],
        ]));
        $this->assertStringContainsString('<span class="section__panel-row-label">Solo</span>', $labelOnly);
        $this->assertStringContainsString('<span class="section__panel-row-value"></span>', $labelOnly);

        $valueOnly = $this->render($this->fullPanelProps([
            'panel_items' => [['value' => '42']],
        ]));
        $this->assertStringContainsString('<span class="section__panel-row-value">42</span>', $valueOnly);
    }

    public function testPanelSkipsShapelessArrayAndNonScalarEntries(): void
    {
        // An array with neither label nor value, and non-scalar label/value, are
        // dropped (same posture as the string form's skip rule).
        $html = $this->render($this->fullPanelProps([
            'panel_items' => [
                ['nested' => 'x'],
                ['label' => ['not', 'scalar']],
                ['label' => '', 'value' => ''],
                ['label' => 'Kept', 'value' => 'Yes'],
            ],
        ]));
        $this->assertSame(1, substr_count($html, 'section__panel-row"'));
        $this->assertStringContainsString('Kept', $html);
    }

    /**
     * THE FOUR PER-ROW STYLE TESTS, COLLAPSED INTO ONE RETIREMENT (#1023).
     *
     * They pinned `section.panel_items[].style` — a per-ROW style map rendered as inline
     * custom properties on that row — from four sides: that a valid map rendered inline,
     * that it validated through the shared engine, that an INELIGIBLE slot (a
     * section-scoped one, which would paint nothing on a row) was refused, and that an
     * invalid VALUE was refused.
     *
     * All four described a capability v2 has no address for. Roles are BAND-grain, so
     * `panel-row` reaches every row in the band and nothing addresses one row; and §3.4
     * forbids inline style emission outright, which is the mechanism all four assert.
     *
     * IT IS NOT A DELETION WITHOUT A ROUTE. Per-item addressing is the contract question
     * staged as BUILD-SPEC Addendum B and gated on #1024, where the identical gap on
     * grid's `items[].style` is the blocking case (4 of the owner's 5 content pages style
     * one card differently). When that is ruled, these four cases come back at item
     * grain — which is why the shape they pinned is written down here rather than lost.
     */
    public function testPerRowStyleIsRetiredWithNoInlineFallback(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertArrayNotHasKey(
            'style',
            $schema['props']['panel_items']['items'],
            'the per-row style map is retired — per-item addressing is gated on #1024.'
        );

        // A stored per-row style is inert at render rather than half-applied: the row
        // renders with its content, and no style attribute reaches the DOM.
        $html = $this->render($this->fullPanelProps([
            'panel_items' => [['label' => 'Plan', 'value' => 'Pro', 'style' => ['--section-panel-text' => '#22d3ee']]],
        ]));
        $this->assertStringContainsString('section__panel-row', $html, 'the row still renders');
        $this->assertStringContainsString('Pro', $html, 'and keeps its content');
        $this->assertStringNotContainsString('style=', $html,
            'a v2 band emits no inline custom properties, on the band or on a row');

        // The band-grain replacement reaches every row through the role.
        $this->assertArrayHasKey('panel-row', $schema['roles']);
        $this->assertContains('typography', $schema['roles']['panel-row']['groups']);
    }

    public function testPanelFontValidatesWithMonoTokenThroughTheRole(): void
    {
        // Same capability, same token, one surface over: the `panel` role's
        // `typography.family`. The reference spelling changes from CSS `var(--font-mono)`
        // to the udc `@font-mono`, which is the engine's own reference grammar — and the
        // engine checks the token's DECLARED TYPE (#972), so a font token is accepted
        // here for the reason its value text happens to look right, not by luck.
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'layout' => 'text-panel', 'panel_heading' => 'H'],
            'udc'       => ['panel' => ['typography' => ['family' => '@font-mono']]],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'the panel role must accept the --font-mono token through the shared engine.'
        );
    }

    // CSS contract (structure pins; the rendered paint lives in the E2E spec).

    /**
     * The slot's `inherit` fallback existed so an UNSET panel inherited the page font
     * byte-identically. A role achieves that by declaring nothing: with no
     * `typography.family` default the engine emits no `font-family` at all, which is
     * strictly better than emitting `font-family: inherit` — same rendering, one fewer
     * declaration, and nothing for a later rule to have to out-specify.
     */
    public function testAnUnsetPanelFontEmitsNoFontFamilyAtAll(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertArrayNotHasKey(
            'family',
            $schema['roles']['panel']['defaults']['typography'],
            'no shipped family default — an unset panel inherits the page font.'
        );
        $this->assertStringNotContainsString(
            'font-family',
            pp_udc_component_defaults_css('section'),
            'and the emitter declares none for it.'
        );
    }

    /**
     * A paired row took its colour from `--section-panel-text` specifically because that
     * slot was `item_eligible` — a per-row override could then recolour one row. Both
     * halves of that arrangement are gone: the slot with the rest, and per-item
     * addressing pending #1024.
     *
     * What replaces it is inheritance, which is what the row wanted in the first place:
     * the `panel` role sets `typography.color` on the surface and the row inherits it, so
     * one authored value still colours every row.
     */
    public function testAPairedRowInheritsThePanelInk(): void
    {
        $item = [
            'component' => 'section',
            'id'        => 'pp-33cc44dd',
            'props'     => $this->fullPanelProps(),
            'udc'       => ['panel' => ['typography' => ['color' => '#f8fafc']]],
        ];
        $css = pp_udc_band_css($item);
        $this->assertStringContainsString('[data-pp-band="pp-33cc44dd"] .section__panel{', $css);
        $this->assertStringContainsString('color:#f8fafc', $css);
        $this->assertStringNotContainsString('.section__panel-row{color', $css,
            'the row is not separately coloured — it inherits, so one value reaches every row.');
    }

    // ── #536 panel-CTA fill slots ─────────────────────────────────────────

    /**
     * REBASED at #1023. The three slots rode the band ROOT and the premium `.btn` winner
     * read them by inheritance — which is why the test asserted them on the root and
     * then asserted the anchor markup was untouched.
     *
     * The `panel-cta` role addresses the anchor DIRECTLY, so the inheritance trick is not
     * needed and the values land where they are aimed. The markup assertion is the half
     * worth keeping unchanged: the anchor is still the bare `.btn`, because a role styles
     * an element without needing a class to hang the styling on.
     */
    public function testPanelCtaDesignLandsOnTheAnchorAndLeavesItsMarkupAlone(): void
    {
        $item = [
            'component' => 'section',
            'id'        => 'pp-55ee66ff',
            'props'     => $this->fullPanelProps(),
            'udc'       => ['panel-cta' => [
                'background' => ['fill' => '#7c3aed'],
                'typography' => ['color' => '#ffffff'],
                'shadow'     => ['box' => 'none'],
            ]],
        ];
        $this->assertNull(pp_udc_validate_map($item['udc'], 'section'));

        $css = pp_udc_band_css($item);
        $this->assertStringContainsString('[data-pp-band="pp-55ee66ff"] .section__panel-cta{', $css);
        $this->assertStringContainsString('background:#7c3aed', $css);
        $this->assertStringContainsString('color:#ffffff', $css);
        $this->assertStringContainsString('box-shadow:none', $css);

        $html = $this->render($this->fullPanelProps());
        $this->assertMatchesRegularExpression(
            '/<a href="\/signup" class="section__panel-cta btn">/',
            $html,
            'The panel CTA anchor must keep its exact pre-536 markup.'
        );
    }

    /** Unset, nothing is emitted: the markup is byte-identical to before #536. */
    public function testPanelCtaFillSlotsAbsentWhenUnset(): void
    {
        $html = $this->render($this->fullPanelProps());

        $this->assertStringNotContainsString('--section-panel-cta-bg', $html);
        $this->assertStringNotContainsString('--section-panel-cta-color', $html);
        $this->assertStringNotContainsString('--section-panel-cta-shadow', $html);
    }

    /**
     * #551's load-bearing structural claim: `.section__panel-cta` is the ONLY anchor the
     * panel can contain. That is what makes the compound `a:not(.section__panel-cta)`
     * carve-out equivalent to a panel-wide one, and it is only true because every other
     * panel field is escaped text — the `.section__panel-heading`, `.section__panel-body`
     * and `.section__panel-item` renders in components/section/section.php all go through
     * esc_html(), so none of them can emit markup. (Named by their BEM classes rather than
     * by line number: the numbers this comment used to carry were displaced by the #706
     * guard block and would decay again on the next edit.)
     *
     * If a future change gives any panel field a wp_kses_post treatment (rich text with
     * links), this fails — and the carve-out must widen to the panel before that ships,
     * or the band's near-white overlay ink lands on the light panel again at ~1.04:1.
     */
    public function testPanelContainsNoAnchorOtherThanTheCta(): void
    {
        foreach (['primary', 'secondary', 'outline', 'ghost'] as $variant) {
            $html = $this->render($this->fullPanelProps([
                'panel_cta_variant' => $variant,
                // Feed every panel text field something that WOULD become an anchor if the
                // field were ever rendered as raw HTML instead of escaped text.
                'panel_heading'     => 'Plans <a href="/x">link</a>',
                'panel_body'        => 'Copy <a href="/y">link</a>',
                'panel_items'       => ['Item <a href="/z">link</a>'],
            ]));

            // Isolate the panel subtree, then count anchors inside it.
            $start = strpos($html, '<div class="section__panel">');
            $this->assertNotFalse($start, "panel must render (panel_cta_variant=$variant)");
            $panel = substr($html, $start);

            $this->assertSame(
                1,
                preg_match_all('/<a\b/', $panel),
                "the panel must contain exactly ONE anchor (panel_cta_variant=$variant) — "
                . '#551 carves `.section__panel-cta` out of the band-wide `a` ink rule, which '
                . 'only covers the whole panel while the CTA is its only anchor.'
            );
            $this->assertStringContainsString(
                'section__panel-cta',
                $panel,
                "the panel's single anchor must be the CTA (panel_cta_variant=$variant)."
            );
        }
    }

    /**
     * THE #551 CARVE-OUT IS RETIRED BECAUSE ITS COLLISION CANNOT RECUR (#1023).
     *
     * It existed because `.pp-section--inverted a` and `.section--has-bg-image a` were
     * BAND-WIDE anchor rules: they reached inside `.section__panel`, whose surface is
     * light and author-controlled, and repainted the panel CTA with near-white band ink.
     * The fix was `:not(.section__panel-cta)` on all four rules, rest and hover.
     *
     * Neither band class is emitted now — `theme` and `background_image` both retired —
     * so there are no band-wide anchor rules to carve out of. And the replacement could
     * not recreate the collision even if it wanted to: a section body link is the
     * `body-link` role, whose selector is `.section__content a`, which cannot reach the
     * panel. The carve-out is not needed rather than merely removed, and that distinction
     * is what this test now records.
     */
    public function testTheBandLinkInkCannotReachThePanelCtaAnyMore(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($this->themeRoot . '/assets/css/components.css'));

        foreach (['.pp-section--inverted', '.section--has-bg-image'] as $band) {
            $this->assertStringNotContainsString($band, $css,
                "{$band} is not emitted any more, so no rule may still select it.");
        }
        $this->assertStringNotContainsString(':not(.section__panel-cta)', $css,
            'the carve-out is unnecessary now, not merely relocated.');

        // The replacement is scoped by construction: the body-link role cannot select
        // anything inside the panel.
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertSame('.section__content a', $schema['roles']['body-link']['selector'],
            'a body link is scoped to the body container, which excludes the panel.');
    }

    /**
     * The section RENDERER emits exactly ONE button surface, which is why #536 needs no
     * #526-style isolation rule. Pin that structural fact across every panel_cta_variant:
     * if a second button surface is ever added to the section, this fails and the isolation
     * question has to be answered again. Counts elements carrying the `btn` CLASS (matched
     * at a word boundary inside a class attribute), not the substring — a variant modifier
     * (`btn btn--outline`) is still ONE surface, and an unrelated `btn` substring elsewhere
     * in the markup is not a surface at all.
     */
    public function testSectionRendersExactlyOneButtonSurface(): void
    {
        foreach (['primary', 'secondary', 'outline', 'ghost'] as $variant) {
            $html = $this->render($this->fullPanelProps(['panel_cta_variant' => $variant]));
            $this->assertSame(
                1,
                preg_match_all('/class="[^"]*\bbtn\b/', $html),
                "section must render exactly one .btn surface (panel_cta_variant=$variant) — a "
                . 'second one would need the #526 slot-isolation treatment before the #536 slots '
                . 'could be trusted.'
            );
        }
    }

    // ── #568 paired-row mobile stack ──────────────────────────────────────

    /**
     * The COMPONENT: section block's `@media (max-width: 767px)` at-rule body,
     * brace-matched (a regex cannot match the nested rule braces).
     */
    private function panelRowMobileBlock(): string
    {
        $block  = $this->sectionBlock();
        $needle = '@media (max-width: 767px)';
        $offset = 0;

        // Select the at-rule that actually OWNS the paired row, not simply the
        // first mobile at-rule in the block. The section component may grow a
        // second `max-width: 767px` at-rule at any time (the file already carries
        // eight), and picking by position would then either blame correct CSS or
        // pass a leak check vacuously, depending on which side it landed.
        while (($start = strpos($block, $needle, $offset)) !== false) {
            $open   = strpos($block, '{', $start);
            $offset = $start + strlen($needle);
            $depth  = 0;
            for ($i = $open, $len = strlen($block); $i < $len; $i++) {
                if ($block[$i] === '{') {
                    $depth++;
                } elseif ($block[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $body = substr($block, $open + 1, $i - $open - 1);
                        if (strpos($body, '.section__panel-row') !== false) {
                            return $body;
                        }
                        $offset = $i;
                        continue 2;
                    }
                }
            }
        }
        return '';
    }

    /**
     * #568: a paired row keeps its two-column geometry at every width, so at 375
     * the value is squeezed into ~170px of a 247px row content box and a long
     * comparison value wraps to four right-aligned lines beside a one-word label.
     * The ruled default stacks the pair below the mobile breakpoint. Five
     * properties; each is asserted here at the source level, and the RENDERED
     * proof (that the cascade actually delivers them, and that the label reads as
     * a label) lives in tests/e2e/style-render.spec.ts.
     */
    public function testPanelRowStacksBelowTheMobileBreakpoint(): void
    {
        $mobile = $this->panelRowMobileBlock();
        $this->assertNotSame(
            '',
            $mobile,
            'The COMPONENT: section block must carry a @media (max-width: 767px) at-rule for the #568 paired-row stack.'
        );

        // 1 + 3: the row stacks, with the TIGHT intra-pair gap.
        $this->assertMatchesRegularExpression(
            '/\.section__panel-row\s*\{[^}]*flex-direction:\s*column/s',
            $mobile,
            'A paired row must stack to flex-direction: column below 768px.'
        );
        // The GAP is the `panel-row` role's `spacing.gap` since #1023 — `gap` is a
        // designable value and cannot live in a v2 component's stylesheet — but it is
        // still responsive and still tight on the phone, which is the behaviour this
        // case is about. The stacking above stays structural.
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertSame(
            '@space-xs',
            $schema['roles']['panel-row']['defaults']['spacing']['gap']['p'],
            'The stacked intra-pair label->value gap must be the tight --space-xs step.'
        );

        // 4: the LOOSE inter-pair rhythm, as an adjacent-sibling margin-top.
        // margin-bottom is not available: `.section__panel-list li` (0,1,1) owns it
        // and its :last-child companion (0,1,2) zeroes it, so a margin-bottom answer
        // must out-specify both and re-implement the last-child zero. `+` (0,2,0)
        // writes margin-top, which nothing else on a panel li sets. It fires on
        // every row after the first, INCLUDING the last, but only ever adds space
        // ABOVE a row — so it can never leave a trailing gap below the last row,
        // i.e. above a panel CTA.
        // The inter-pair rhythm lives in the SHARED GLYPH AND PROSE MECHANISMS block
        // since #1023, not in the component's own: it is an ADJACENT-SIBLING margin, and
        // a role addresses one element — the UDC has no sibling dimension — so it cannot
        // be a role default, and a token-valued margin cannot be in a v2 component's
        // block. It is still scoped to the mobile at-rule, which is what this case cares
        // about, so the assertion reads the whole stylesheet rather than section's slice.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\)\s*\{\s*\.section__panel-row \+ \.section__panel-row\s*\{[^}]*margin-top:\s*var\(--space-md\)/s',
            file_get_contents($this->themeRoot . '/assets/css/components.css'),
            'The inter-pair rhythm must be a --space-md margin-top on a row that follows a row.'
        );

        // 2: one shared left edge — the desktop `text-align: right` is the half of
        // the defect that survives stacking, so it must be answered explicitly.
        // The value's mobile left-alignment went with the label/value role merge
        // (#1023, review finding A4) — a stacked column inherits left alignment anyway,
        // so this one was a no-op restatement even in v1. Its desktop sibling
        // (text-align: right) is the half that actually changed rendering, and that cost
        // is stated in testPanelRowDesktopPresentationIsUnchanged.
        $this->assertDoesNotMatchRegularExpression(
            '/\.section__panel-row-value\s*\{[^}]*text-align:\s*left/s',
            $mobile,
            'The stacked value must be left-aligned so label and value share one left edge.'
        );

        // 5: stacked, position no longer distinguishes label from value. The split
        // follows the theme's own stacked key/value idiom (.hero__surface-key /
        // .hero__surface-value): the label is the small tracked one, the value
        // carries the weight. The SIZE step is the load-bearing half — the theme
        // ships no webfont, so in a bare font environment a weight-only treatment
        // renders pixel-identical, which is why the weight is pinned here (where a
        // regex can see the declaration) rather than relied on as visual proof.
        // The label/value treatment is role defaults since #1023, so it is read from the
        // schema rather than from the mobile at-rule. It is NOT scoped to the phone any
        // more and that is a deliberate widening: v1 declared it only inside
        // @media (max-width: 767px), which meant a desktop paired row got no label
        // treatment at all even though the same distinction helps there. A role default
        // applies at every width unless an author narrows it.
        $label = $schema['roles']['panel-row-label']['defaults']['typography'];
        $this->assertSame('0.8125rem', $label['size'],
            'The stacked label needs an explicit size step; weight alone is not guaranteed to render.');
        $this->assertSame('0.04em', $label['letter-spacing'],
            'The stacked label uses the theme eyebrow tracking so it reads as a label.');
        $this->assertSame(
            '600',
            $schema['roles']['panel-row-value']['defaults']['typography']['weight'],
            'The stacked value carries the weight, mirroring .hero__surface-value.'
        );
        $this->assertSame(
            'left',
            $schema['roles']['panel-row-value']['defaults']['typography']['align']['p'],
            'and shares the label\'s left edge once the row stacks.'
        );

        // The label must NOT be recoloured: row colour routes through the
        // item_eligible --section-panel-text slot and the panel background is
        // author-controlled (and may be dark).
        // The label must NOT be recoloured by DEFAULT: the panel background is
        // author-controlled and may be dark, so a shipped label colour would be a
        // contrast guess. An AUTHOR may still set one — the role permits typography —
        // which is the difference between a default and a prohibition.
        $this->assertArrayNotHasKey(
            'color',
            $schema['roles']['panel-row-label']['defaults']['typography'],
            'The stacked label distinction is typographic, never colour (#568).'
        );
    }

    /**
     * #568 is a DEFAULT, not a knob, and desktop is untouched: every new
     * declaration must live inside the mobile at-rule, and the desktop rules must
     * still say exactly what they said before.
     */
    public function testPanelRowDesktopPresentationIsUnchanged(): void
    {
        $block  = $this->sectionBlock();
        $mobile = $this->panelRowMobileBlock();

        // Without this guard the whole test passes VACUOUSLY if the at-rule is
        // ever deleted: panelRowMobileBlock() returns '', str_replace('', '', ...)
        // is a no-op, and every leak check below then runs against the full block
        // and finds nothing to complain about — green over a removed feature.
        $this->assertNotSame(
            '',
            $mobile,
            'The #568 mobile at-rule must exist before its leak checks mean anything.'
        );

        $desktop = str_replace($mobile, '', $block);

        $this->assertMatchesRegularExpression(
            '/\.section__panel-row\s*\{[^}]*justify-content:\s*space-between/s',
            $desktop,
            'The desktop paired row keeps space-between.'
        );
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertSame(
            '@space-md',
            $schema['roles']['panel-row']['defaults']['spacing']['gap']['d'],
            'The desktop paired row keeps the --space-md gap, as a role default.'
        );
        // The value's right alignment survives, as the `panel-row-value` role's
        // `typography.align` — responsive, so the desktop `right` and the stacked-phone
        // `left` both keep the exact behaviour this test and its mobile sibling pin.
        //
        // AN EARLIER CUT OF #1023 MERGED THIS ROLE INTO `panel-row` on review finding
        // A4's role-count argument, and this suite is what reversed it: the label and
        // value carry DISTINCT named visual jobs (the label is small and tracked, the
        // value carries the weight), which is exactly the bar A4 sets for a role above
        // the precedent count. A4 was right about `.section__panel-row-label` being a
        // markup-shaped NAME and wrong that the job was markup trivia.
        $this->assertSame(
            'right',
            $schema['roles']['panel-row-value']['defaults']['typography']['align']['d'],
            'The desktop value stays right-aligned — #568 changes nothing at >=768px.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.section__panel-row-label\s*\{/s',
            $desktop,
            'The label carries NO desktop rule; its type treatment is mobile-only (#568).'
        );
        // .section__grid legitimately stacks at desktop scope, so scope the
        // leak check to the paired row itself.
        $this->assertDoesNotMatchRegularExpression(
            '/\.section__panel-row[^{]*\{[^}]*flex-direction/s',
            $desktop,
            'The stack must not leak out of the mobile at-rule.'
        );

        // No responsive SLOT: #568 is a defaults change, not a mobile knob.
        $this->assertStringNotContainsString(
            '--section-panel-row',
            $block,
            '#568 introduces no per-instance paired-row slot — mobile behaviour stays defaulted.'
        );
    }

    public function testPanelRowMarkerGlyphIsSuppressed(): void
    {
        // A row is not a bullet: its ::before marker box is neutralised so a
        // marker on the <ul> paints only the string bullets, not the rows.
        $this->assertMatchesRegularExpression(
            '/\.section__panel-list > \.section__panel-row::before\s*\{\s*content:\s*none/s',
            $this->sectionBlock(),
            'The shared issue-339 marker glyph must be suppressed on paired rows.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__panel-row\s*\{[^}]*list-style:\s*none/s',
            $this->sectionBlock()
        );
    }
}
