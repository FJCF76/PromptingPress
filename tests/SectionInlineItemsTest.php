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
        // Left-packed so the hanging-separator clip (#489) can hide line-leading
        // separators at the box edge — an edge-clip cannot hide them on a per-line-
        // centered row.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*justify-content:\s*flex-start/s',
            $this->componentsCss,
            '.section__inline-items must be left-packed (flex-start) for the #489 clip.'
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
}
