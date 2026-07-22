<?php
/**
 * tests/SectionInlineItemsTest.php
 *
 * Section inline-items row (issue 475): section.body_items renders a centered
 * single-row band of short plain-text items with a CSS-generated, slot-colorable
 * separator between them. The renderer emits `<ul class="section__inline-items"
 * role="list">` only when body_items is non-empty, after .section__content; the
 * separator is a `li + li::before` pseudo-element (never a content character) so it
 * can be slot-colored and stays out of the accessibility tree.
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

    // ── CSS pins: the row layout, the separator, and slot routing ─────────

    public function testInlineItemsRowIsACenteredWrappingFlexRow(): void
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
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*justify-content:\s*center/s',
            $this->componentsCss,
            '.section__inline-items must be centered.'
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
            '/\.section__inline-items li \+ li::before\s*\{[^}]*content:\s*"\\\\00b7"\s*\/\s*""/s',
            $this->componentsCss,
            'the separator must be a CSS-generated middot with empty alt-text (content: "\\00b7" / "").'
        );
    }

    public function testSeparatorColorRoutesThroughSlotWithMutedDefault(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items li \+ li::before\s*\{[^}]*color:\s*var\(--section-separator-color,\s*var\(--color-muted\)\)/s',
            $this->componentsCss,
            'the separator color must route through var(--section-separator-color, var(--color-muted)).'
        );
    }

    public function testSeparatorRoutesThroughOnOverlayRoleOnBgImageBand(): void
    {
        // .section--has-bg-image does not remap --color-muted, so the separator
        // default is re-routed to the light on-overlay text color like sibling text.
        $this->assertMatchesRegularExpression(
            '/\.section--has-bg-image \.section__inline-items li \+ li::before\s*\{[^}]*color:\s*var\(--section-separator-color,\s*var\(--color-bg\)\)/s',
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
