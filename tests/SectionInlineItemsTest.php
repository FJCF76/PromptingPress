<?php
/**
 * tests/SectionInlineItemsTest.php
 *
 * Section inline-items row (issue 475): section.body_items renders a band of short
 * plain-text items with a CSS-generated, slot-colorable separator between them. The
 * renderer emits `<ul class="section__inline-items" role="list">` only when
 * body_items is non-empty, after .section__content; the separator is a `li::before`
 * pseudo-element (never a content character) so it can be slot-colored and stays out
 * of the accessibility tree.
 *
 * Hanging-separator clip (issue 489): the separator is on EVERY item's `::before`,
 * each item is pulled left by exactly the separator's occupied width, and the row is
 * overflow:hidden, so the separator that would otherwise dangle at the start of a
 * wrapped line is clipped. The row is left-packed and centered as a block (width:
 * fit-content + auto margins), so a single-line row still reads centered while
 * wrapped lines pack from the left.
 *
 * Two halves: render pins (assert the emitted HTML) and CSS-content pins (PHPUnit
 * does not execute CSS, so — like SectionBodyListTest/TypographyRoleTest — we assert
 * the source declares the routing rules; the computed cascade is covered by the
 * style-render e2e).
 */

use PHPUnit\Framework\TestCase;

class SectionInlineItemsTest extends TestCase
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

    private function render(string $component, array $props): string
    {
        ob_start();
        pp_get_component($component, $props);
        return ob_get_clean();
    }

    // ── Render: the row appears only when body_items is set ───────────────

    public function testUnsetBodyItemsEmitsNoRowAndStaysByteIdentical(): void
    {
        $without = $this->render('section', ['layout' => 'text-only', 'body' => '<p>Hi</p>']);
        $withEmpty = $this->render('section', ['layout' => 'text-only', 'body' => '<p>Hi</p>', 'body_items' => []]);

        $this->assertStringNotContainsString('section__inline-items', $without,
            'unset body_items must emit no inline-items row.');
        $this->assertSame($without, $withEmpty,
            'an empty body_items array must render byte-identically to the unset case.');
    }

    public function testBodyItemsRendersRowWithRoleList(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['No credit card', 'Cancel anytime'],
        ]);
        $this->assertMatchesRegularExpression(
            '/<ul class="section__inline-items" role="list">/',
            $html,
            'body_items must render a <ul class="section__inline-items" role="list">.'
        );
    }

    public function testEachItemRendersAsListItemWithMatchingCount(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two', 'Three'],
        ]);
        $this->assertSame(3, substr_count($html, 'section__inline-item">'),
            'each body_items entry must render exactly one <li class="section__inline-item">.');
        $this->assertStringContainsString('<li class="section__inline-item">One</li>', $html);
        $this->assertStringContainsString('<li class="section__inline-item">Two</li>', $html);
        $this->assertStringContainsString('<li class="section__inline-item">Three</li>', $html);
    }

    public function testItemsAreEscapedAsPlainText(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['<b>bold</b> & "risky"'],
        ]);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;risky&quot;', $html,
            'body_items entries must be escaped with esc_html (no raw HTML).');
        $this->assertStringNotContainsString('<b>bold</b>', $html,
            'raw HTML in a body_items entry must not survive into the row.');
    }

    public function testEmptyStringEntriesAreDropped(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', '', 'Two'],
        ]);
        $this->assertSame(2, substr_count($html, 'section__inline-item">'),
            'empty-string entries must be dropped from the row.');
    }

    public function testRowRendersAfterBodyContent(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Body prose.</p>',
            'body_items' => ['Meta'],
        ]);
        $contentPos = strpos($html, 'section__content');
        $rowPos     = strpos($html, 'section__inline-items');
        $this->assertNotFalse($contentPos);
        $this->assertNotFalse($rowPos);
        $this->assertLessThan($rowPos, $contentPos,
            'the inline-items row must render after .section__content when both are set.');
    }

    public function testRowRendersInImageAndPanelLayouts(): void
    {
        $image = $this->render('section', [
            'layout'     => 'image-right',
            'body'       => '<p>Hi</p>',
            'image_url'  => 'https://example.com/x.png',
            'body_items' => ['Meta'],
        ]);
        $this->assertStringContainsString('section__inline-items', $image,
            'the row must render in the image layout body scope.');

        $panel = $this->render('section', [
            'layout'        => 'text-panel',
            'body'          => '<p>Hi</p>',
            'panel_heading' => 'Panel',
            'body_items'    => ['Meta'],
        ]);
        $this->assertStringContainsString('section__inline-items', $panel,
            'the row must render in the text-panel layout body scope.');
    }

    // ── Flush-top margin on a body-less strip (issue 488) ─────────────────

    public function testBodyLessStripGetsFlushTopModifier(): void
    {
        // body_items alone, no body — the primary #475 trust-strip use case, now
        // authorable without a body:"" placeholder (#488). The row zeroes its
        // body-relative top margin so the band padding centers it.
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body_items' => ['SOC 2 Type II', '99.99% uptime'],
        ]);
        $this->assertStringContainsString(
            '<ul class="section__inline-items section__inline-items--flush-top" role="list">',
            $html,
            'a body-less strip must carry the --flush-top modifier that zeroes the top margin.'
        );
    }

    public function testStripWithBodyDoesNotGetFlushTopModifier(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Real body copy.</p>',
            'body_items' => ['Meta'],
        ]);
        $this->assertStringContainsString('<ul class="section__inline-items" role="list">', $html,
            'a strip WITH body copy keeps the base class (var(--space-md) top margin).');
        $this->assertStringNotContainsString('section__inline-items--flush-top', $html,
            'a strip following body copy must NOT get the flush-top modifier.');
    }

    public function testWhitespaceOnlyBodyIsTreatedAsBodyLess(): void
    {
        // The flush-top decision is keyed on trimmed body, matching the content
        // requirement (#488): a whitespace-only body renders nothing, so the row
        // is still the first visible content and must sit flush.
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => "   \n\t ",
            'body_items' => ['Meta'],
        ]);
        $this->assertStringContainsString('section__inline-items--flush-top', $html,
            'a whitespace-only body must be treated as body-less (flush-top applies).');
    }

    public function testFlushTopModifierZeroesTopMarginInSource(): void
    {
        // PHPUnit does not execute CSS; assert the source declares the override.
        // The computed 0-vs-16px cascade is pinned by the style-render e2e.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--flush-top\s*\{[^}]*margin-top:\s*0/s',
            $this->componentsCss,
            '.section__inline-items--flush-top must zero the top margin.'
        );
        // It must be declared AFTER the base rule so equal-specificity source
        // order wins; a modifier placed before the base would no-op.
        $basePos  = strpos($this->componentsCss, '.section__inline-items {');
        $flushPos = strpos($this->componentsCss, '.section__inline-items--flush-top {');
        $this->assertNotFalse($basePos);
        $this->assertNotFalse($flushPos);
        $this->assertGreaterThan($basePos, $flushPos,
            'the --flush-top override must be declared after the base .section__inline-items rule.');
    }

    // ── CSS pins: the row layout, the separator, and slot routing ─────────

    public function testInlineItemsRowIsABlockCenteredWrappingFlexRow(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*display:\s*flex/s',
            $this->componentsCss,
            '.section__inline-items must be a flex row.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*flex-wrap:\s*wrap/s',
            $this->componentsCss,
            '.section__inline-items must wrap (the responsive default, no mobile rule).'
        );
        // justify-content reads the --section-inline-items-align slot (#510) and
        // defaults to flex-start when unset — byte-identical to the historical
        // left-packed row that lets the hanging-separator clip (#489) hide
        // line-leading separators at the box edge.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*justify-content:\s*var\(--section-inline-items-align,\s*flex-start\)/s',
            $this->componentsCss,
            '.section__inline-items justify-content must read var(--section-inline-items-align, flex-start) (default flex-start).'
        );
        // Centered as a BLOCK instead: shrink-to-fit width + auto side margins, so a
        // single-line row still reads centered.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*width:\s*fit-content/s',
            $this->componentsCss,
            '.section__inline-items must shrink-wrap (width: fit-content) to center as a block.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*margin:\s*var\(--space-md\)\s+auto\s+0/s',
            $this->componentsCss,
            '.section__inline-items must use auto side margins to center the shrink-wrapped block.'
        );
    }

    public function testHangingSeparatorClipIsWiredForBleedThroughFreeWrapping(): void
    {
        // #489: overflow:hidden is the clip surface; each item is pulled left by the
        // separator's occupied width; the separator is a fixed-width inline-block box
        // so that pull is an exact token value independent of the glyph's advance.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*overflow:\s*hidden/s',
            $this->componentsCss,
            '.section__inline-items must clip its overflow to hide line-leading separators (#489).'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-item\s*\{[^}]*margin:\s*0 0 0 calc\(-1 \* \(var\(--space-sm\) \+ var\(--space-xs\)\)\)/s',
            $this->componentsCss,
            '.section__inline-item must be pulled left by the separator occupied width (--space-sm + --space-xs).'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items li::before\s*\{[^}]*display:\s*inline-block[^}]*width:\s*var\(--space-sm\)/s',
            $this->componentsCss,
            'the separator must be a fixed-width inline-block box (width: --space-sm) so the pull is exact.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items li::before\s*\{[^}]*margin-right:\s*var\(--space-xs\)/s',
            $this->componentsCss,
            'the separator right margin (--space-xs) completes its occupied width.'
        );
    }

    public function testInlineItemsInheritBodyTypeSlots(): void
    {
        // The row reads the SAME #470 slots as .section__content, so a 15px/600
        // brand strip needs no extra typography slots.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*font-size:\s*var\(--section-body-size,/s',
            $this->componentsCss,
            '.section__inline-items must read --section-body-size (issue 470 scope).'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*font-weight:\s*var\(--section-body-weight,/s',
            $this->componentsCss,
            '.section__inline-items must read --section-body-weight (issue 470 scope).'
        );
    }

    public function testSeparatorIsCssGeneratedAndScreenReaderQuiet(): void
    {
        // Middot glyph via ::before with empty alt-text, so it never becomes a
        // content character and stays out of the accessibility tree.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items li::before\s*\{[^}]*content:\s*"\\\\00b7"\s*\/\s*""/s',
            $this->componentsCss,
            'the separator must be a CSS-generated middot with empty alt-text (content: "\\00b7" / "").'
        );
    }

    public function testSeparatorColorRoutesThroughSlotWithMutedDefault(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items li::before\s*\{[^}]*color:\s*var\(--section-separator-color,\s*var\(--color-muted\)\)/s',
            $this->componentsCss,
            'the separator color must route through var(--section-separator-color, var(--color-muted)).'
        );
    }

    public function testSeparatorRoutesThroughOnOverlayRoleOnBgImageBand(): void
    {
        // .section--has-bg-image does not remap --color-muted, so the separator
        // default is re-routed to the light on-overlay text color like sibling text.
        $this->assertMatchesRegularExpression(
            '/\.section--has-bg-image \.section__inline-items li::before\s*\{[^}]*color:\s*var\(--section-separator-color,\s*var\(--color-bg\)\)/s',
            $this->componentsCss,
            'on the bg-image band the separator default must route through var(--section-separator-color, var(--color-bg)).'
        );
    }

    public function testSeparatorColorSlotIsDeclaredInSchema(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $slots  = $schema['styling']['style_slots'];
        $this->assertArrayHasKey('--section-separator-color', $slots,
            'schema must declare the --section-separator-color style slot.');
        $this->assertSame('color', $slots['--section-separator-color']['type']);
        $this->assertSame('var(--color-muted)', $slots['--section-separator-color']['default']);
    }

    // ── Per-line alignment slot (issue 510) ───────────────────────────────

    public function testAlignSlotIsEnumStartCenterDefaultStartInSchema(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $slots  = $schema['styling']['style_slots'];
        $this->assertArrayHasKey('--section-inline-items-align', $slots,
            'schema must declare the --section-inline-items-align style slot.');
        $slot = $slots['--section-inline-items-align'];
        $this->assertSame('enum', $slot['type'], 'the align slot must be an enum slot.');
        $this->assertSame(['start', 'center'], $slot['values'],
            'the align slot must accept exactly start | center.');
        $this->assertSame('start', $slot['default'],
            'the align slot must default to start (unchanged historical behavior).');
    }

    public function testCenterAlignValueAddsCenterModifierClass(): void
    {
        // The renderer reads the validated component style map (top-level style →
        // __pp_style) and derives the --center modifier when the value is center.
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two', 'Three'],
            '__pp_style' => ['--section-inline-items-align' => 'center'],
        ]);
        $this->assertStringContainsString(
            '<ul class="section__inline-items section__inline-items--center" role="list">',
            $html,
            'a center-aligned strip must carry the --center modifier class.'
        );
    }

    public function testUnsetAlignStaysByteIdenticalAndStartEmitsNoCenterModifier(): void
    {
        // Unset align renders byte-identically to before this issue: no --center
        // modifier and no inline --section-inline-items-align custom property at all
        // (the base rule's flex-start fallback drives it). The unchanged #489
        // hanging-clip pins above assert the visual result stays put.
        $unset = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two'],
        ]);
        $this->assertStringNotContainsString('section__inline-items--center', $unset,
            'an unset align must not add the --center modifier.');
        $this->assertStringNotContainsString('--section-inline-items-align', $unset,
            'an unset align must not emit the inline custom property (byte-identical to before).');

        // Explicit start is a no-op mode: it emits the prop (resolving to flex-start,
        // the fallback) but never the --center modifier, so it reads left-packed.
        $start = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two'],
            '__pp_style' => ['--section-inline-items-align' => 'start'],
        ]);
        $this->assertStringNotContainsString('section__inline-items--center', $start,
            'align:start must not add the --center modifier (left-packed).');
    }

    public function testUnknownAlignValueFallsBackToStartAtRender(): void
    {
        // Render-time fail-safe: any non-'center' value (an out-of-band / legacy /
        // restore write the strict write-time enum would have rejected) falls
        // through to the unchanged left-packed default — never a half-applied mode.
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two'],
            '__pp_style' => ['--section-inline-items-align' => 'left'],
        ]);
        $this->assertStringNotContainsString('section__inline-items--center', $html,
            'a non-center align value must not trigger the center modifier (fail-safe to start).');
    }

    public function testFlushTopAndCenterModifiersCombineOnBodyLessCenteredStrip(): void
    {
        // A body-less centered strip carries BOTH derived modifiers.
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body_items' => ['SOC 2', '99.99% uptime'],
            '__pp_style' => ['--section-inline-items-align' => 'center'],
        ]);
        $this->assertStringContainsString(
            '<ul class="section__inline-items section__inline-items--flush-top section__inline-items--center" role="list">',
            $html,
            'a body-less centered strip must carry both --flush-top and --center modifiers.'
        );
    }

    // ── Authoring-path validation (issue 510, Section 14.1) ───────────────

    public function testCenterAlignValidatesThroughComposition(): void
    {
        // Author the slot through the REAL validate surface (pp_validate_composition
        // → shared style-slot engine → enum membership check), not a raw meta write.
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'body_items' => ['A', 'B']],
            'style'     => ['--section-inline-items-align' => 'center'],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'align:center must validate through the shared style-slot engine.'
        );
    }

    public function testStartAlignValidatesThroughComposition(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'body_items' => ['A', 'B']],
            'style'     => ['--section-inline-items-align' => 'start'],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'align:start must validate through the shared style-slot engine.'
        );
    }

    public function testOutOfSetAlignValueRejectedByComposition(): void
    {
        // Anything outside the bounded set is rejected at write time (nothing
        // persists) — the enum slot rejects 'left' exactly as it rejects garbage.
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'body_items' => ['A', 'B']],
            'style'     => ['--section-inline-items-align' => 'left'],
        ]];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result,
            'an out-of-set align value must be rejected by the authoring surface.');
        $this->assertSame('invalid_style_value', $result->get_error_code());
        $this->assertStringContainsString('start, center', $result->get_error_message(),
            'the rejection must name the accepted value set.');
    }

    public function testRenderBoundaryDropsOutOfSetAlignButEmitsValidOne(): void
    {
        // #330 parity: the render boundary re-validates the enum value set, so a
        // valid value is emitted as an inline custom property while an out-of-band
        // value outside {start, center} is dropped (never reaches the DOM).
        $valid = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two'],
            '__pp_style' => ['--section-inline-items-align' => 'center'],
        ]);
        $this->assertStringContainsString('--section-inline-items-align: center', $valid,
            'a valid enum value must be emitted as an inline custom property.');

        $rogue = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two'],
            '__pp_style' => ['--section-inline-items-align' => 'left'],
        ]);
        $this->assertStringNotContainsString('--section-inline-items-align', $rogue,
            'an out-of-set enum value must be dropped at the render boundary (#330).');
    }

    // ── CSS pins: the centered trailing-separator technique ───────────────

    public function testCenterModifierZeroesItemPullAndSwitchesSeparator(): void
    {
        // On the centered row the hanging-clip geometry does not apply, so the
        // per-item left pull is zeroed and the leading ::before separator is
        // suppressed (content: none).
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--center \.section__inline-item\s*\{[^}]*margin-left:\s*0/s',
            $this->componentsCss,
            'the --center modifier must zero the per-item left pull.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--center li::before\s*\{[^}]*content:\s*none/s',
            $this->componentsCss,
            'the --center modifier must suppress the leading ::before separator.'
        );
    }

    public function testCenterModifierEmitsTrailingSeparatorWithSlotColor(): void
    {
        // The centered separator is a TRAILING middot on every item except the last
        // (:not(:last-child)), routed through the SAME --section-separator-color slot.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--center li:not\(:last-child\)::after\s*\{[^}]*content:\s*"\\\\00b7"\s*\/\s*""/s',
            $this->componentsCss,
            'the --center separator must be a trailing middot on li:not(:last-child)::after.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--center li:not\(:last-child\)::after\s*\{[^}]*color:\s*var\(--section-separator-color,\s*var\(--color-muted\)\)/s',
            $this->componentsCss,
            'the --center trailing separator color must route through --section-separator-color.'
        );
    }

    public function testCenterModifierRoutesTrailingSeparatorOnBgImageBand(): void
    {
        // The overlay band remaps the trailing ::after default to the light on-overlay
        // color, mirroring the ::before rule, so both modes behave identically there.
        $this->assertMatchesRegularExpression(
            '/\.section--has-bg-image \.section__inline-items--center li:not\(:last-child\)::after\s*\{[^}]*color:\s*var\(--section-separator-color,\s*var\(--color-bg\)\)/s',
            $this->componentsCss,
            'on the bg-image band the --center trailing separator default must route through var(--section-separator-color, var(--color-bg)).'
        );
    }

    public function testCenterModifierDeclaredAfterBaseRule(): void
    {
        // Equal-specificity source order: the modifier must follow the base rule.
        $basePos   = strpos($this->componentsCss, '.section__inline-items {');
        $centerPos = strpos($this->componentsCss, '.section__inline-items--center .section__inline-item {');
        $this->assertNotFalse($basePos);
        $this->assertNotFalse($centerPos);
        $this->assertGreaterThan($basePos, $centerPos,
            'the --center modifier must be declared after the base .section__inline-items rule.');
    }
}
