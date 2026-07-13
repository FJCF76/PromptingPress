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

    public function testPanelCtaVariantAddsModifier(): void
    {
        $html = $this->render($this->fullPanelProps(['panel_cta_variant' => 'outline']));
        $this->assertStringContainsString('class="section__panel-cta btn btn--outline"', $html);
    }

    public function testPanelCtaInvalidVariantFallsBackToPrimary(): void
    {
        $html = $this->render($this->fullPanelProps(['panel_cta_variant' => 'rainbow']));
        // primary is the bare .btn — no btn-- modifier.
        $this->assertStringContainsString('class="section__panel-cta btn"', $html);
        $this->assertStringNotContainsString('btn--rainbow', $html);
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

    public function testPanelStyleSlotsRenderInlineOnRoot(): void
    {
        $html = $this->render($this->fullPanelProps([
            '__pp_style' => [
                '--section-panel-bg'   => '#0f172a',
                '--section-panel-text' => '#f8fafc',
            ],
        ]));
        $this->assertStringContainsString('--section-panel-bg: #0f172a', $html);
        $this->assertStringContainsString('--section-panel-text: #f8fafc', $html);
    }

    // ── 2. Validation (shared engine) ─────────────────────────────────────

    public function testTextPanelPropsPassSharedValidation(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => $this->fullPanelProps(['panel_cta_variant' => 'ghost']),
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'A section with all text-panel props must validate.'
        );
    }

    public function testTextPanelStyleSlotMapValidates(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'layout' => 'text-panel', 'panel_heading' => 'H'],
            'style'     => [
                '--section-panel-bg'           => '#0f172a',
                '--section-panel-border-color' => '#334155',
                '--section-panel-border-width' => '1px',
                '--section-panel-radius'       => '1rem',
                '--section-panel-padding'      => '2rem',
                '--section-panel-text'         => '#f8fafc',
            ],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'All six --section-panel-* slots must validate against the shared style-slot engine.'
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

    public function testEveryPanelSlotConsumedWithFallbackInSectionBlock(): void
    {
        $block = $this->sectionBlock();
        $slots = [
            '--section-panel-bg', '--section-panel-border-color', '--section-panel-border-width',
            '--section-panel-radius', '--section-panel-padding', '--section-panel-text',
        ];
        foreach ($slots as $slot) {
            $this->assertMatchesRegularExpression(
                '/var\(' . preg_quote($slot, '/') . ',/',
                $block,
                "{$slot} must be consumed as var({$slot}, <fallback>) inside the COMPONENT: section block."
            );
        }
    }

    public function testPanelListRestoresMarkersAndIndent(): void
    {
        $block = $this->sectionBlock();
        $this->assertMatchesRegularExpression(
            '/\.section__panel-list\s*\{[^}]*list-style:\s*disc/s',
            $block,
            'the panel list must restore disc markers stripped by the base reset (issue 104).'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__panel-list\s*\{[^}]*padding-left:\s*var\(--space-/s',
            $block,
            'the panel list indent must use a spacing token.'
        );
    }

    public function testTextPanelColumnsTopAlignAtDesktop(): void
    {
        // The panel column must top-align (align-items:start) rather than center
        // like the image variants — pinned inside a >=768px media rule.
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\)\s*\{\s*\.section--text-panel \.section__grid\s*\{[^}]*align-items:\s*start/s',
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

    public function testMarkerColorSlotsValidateAndMapInSectionBlock(): void
    {
        // The two new colour slots pass the shared style-slot engine …
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'layout' => 'text-panel', 'panel_heading' => 'H'],
            'style'     => [
                '--section-panel-marker-color' => '#ea3900',
                '--section-body-marker-color'  => '#16a34a',
            ],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'Both marker-colour slots must validate against the shared style-slot engine.'
        );

        // … and are mapped onto the shared plumbing var INSIDE the section block
        // (StyleSlotContractTest check 1 requires each slot consumed in its own
        // block; the mapping is what satisfies it).
        $block = $this->sectionBlock();
        $this->assertMatchesRegularExpression(
            '/--pp-list-marker-color:\s*var\(--section-panel-marker-color,/',
            $block,
            '--section-panel-marker-color must map onto --pp-list-marker-color in the section block.'
        );
        $this->assertMatchesRegularExpression(
            '/--pp-list-marker-color:\s*var\(--section-body-marker-color,/',
            $block,
            '--section-body-marker-color must map onto --pp-list-marker-color in the section block.'
        );
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

    public function testPanelPairedRowPerRowStyleRendersInline(): void
    {
        // A per-row style map (issue 306 mechanism) emits inline custom
        // properties on the row element only.
        $html = $this->render($this->fullPanelProps([
            'panel_items' => [
                ['label' => 'Plan', 'value' => 'Free'],
                ['label' => 'Plan', 'value' => 'Pro', 'style' => ['--section-panel-text' => '#22d3ee']],
            ],
        ]));
        $this->assertMatchesRegularExpression(
            '/<li class="section__panel-row" style="--section-panel-text: #22d3ee;">/',
            $html
        );
        // The un-styled row carries no inline style attribute.
        $this->assertMatchesRegularExpression('/<li class="section__panel-row">\s*<span class="section__panel-row-label">Plan<\/span>\s*<span class="section__panel-row-value">Free/', $html);
    }

    // Validation — through the shared engine only (no second validator).

    public function testPanelPairedRowStructuredFormPassesValidation(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => $this->fullPanelProps([
                'panel_items' => ['A string bullet', ['label' => 'WordPress', 'value' => '6.7.1']],
            ]),
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'A mixed string + paired-row panel_items array must validate.'
        );
    }

    public function testPanelPairedRowStyleMapValidates(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => $this->fullPanelProps([
                'panel_items' => [['label' => 'X', 'value' => 'Y', 'style' => ['--section-panel-text' => '#f8fafc']]],
            ]),
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'A per-row style setting the item_eligible --section-panel-text slot must validate.'
        );
    }

    public function testPanelPairedRowRejectsIneligibleSlot(): void
    {
        // --section-padding-top is a real section slot but is section-scoped, not
        // item_eligible, so it renders nothing on a single row — the issue-323
        // gate rejects it (reported-success-without-effect class).
        $composition = [[
            'component' => 'section',
            'props'     => $this->fullPanelProps([
                'panel_items' => [['label' => 'X', 'value' => 'Y', 'style' => ['--section-padding-top' => '4rem']]],
            ]),
        ]];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_slot', $result->get_error_code());
    }

    public function testPanelPairedRowRejectsInvalidStyleValue(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => $this->fullPanelProps([
                'panel_items' => [['label' => 'X', 'value' => 'Y', 'style' => ['--section-panel-text' => 'notacolor']]],
            ]),
        ]];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_style_value', $result->get_error_code());
    }

    public function testPanelFontSlotValidatesWithMonoToken(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'layout' => 'text-panel', 'panel_heading' => 'H'],
            'style'     => ['--section-panel-font' => 'var(--font-mono)'],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            '--section-panel-font must accept the --font-mono token via the shared engine.'
        );
    }

    // CSS contract (structure pins; the rendered paint lives in the E2E spec).

    public function testPanelFontSlotConsumedInSectionBlock(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__panel\s*\{[^}]*font-family:\s*var\(--section-panel-font,\s*inherit\)/s',
            $this->sectionBlock(),
            '--section-panel-font must be consumed as font-family: var(--section-panel-font, inherit) so an unset panel inherits the page font byte-identically.'
        );
    }

    public function testPanelRowColorRoutesThroughPanelTextSlot(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__panel-row\s*\{[^}]*color:\s*var\(--section-panel-text,/s',
            $this->sectionBlock(),
            'A paired row must take its colour from the item_eligible --section-panel-text slot so a per-row override recolours it.'
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
