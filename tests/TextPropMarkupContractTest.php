<?php
/**
 * tests/TextPropMarkupContractTest.php
 *
 * Text-prop markup contract (issue 439). Three contracts, each stated in the
 * schema and enforced by the renderer:
 *   - Rich HTML   (wp_kses_post)  — section.body, faq.answer
 *   - Inline HTML (pp_kses_inline: a, strong, em, br) — cta.body,
 *                 grid.items[].text, testimonials.items[].quote
 *   - Plain text  (esc_html)      — titles, eyebrows, button/label text, URLs
 *
 * These tests pin: (1) the upgraded props render allowlisted markup as real
 * elements (the link that used to appear as escaped source now works), strip
 * disallowed tags, and leave plain text unchanged; (2) props kept plain still
 * escape byte-identically; (3) the pp_kses_inline helper's allowlist/coercion/
 * protocol behavior directly; (4) every text prop's schema description states its
 * contract (the doc-contract enumeration).
 *
 * The wp_kses allowlist SHAPE is exercised here via the behavioral stub in
 * tests/bootstrap.php; the authoritative protocol/XSS proof against real
 * WordPress lives in the E2E suite (tests/e2e/style-render.spec.ts).
 */

use PHPUnit\Framework\TestCase;

class TextPropMarkupContractTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
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

    // ── The defect this fixes: a link in cta.body used to render as escaped source ──

    public function testCtaTextLinkRendersAsAnchorNotEscapedSource(): void
    {
        $html = $this->render('cta', [
            'button_text' => 'Go',
            'button_url'  => '/go',
            'body'        => 'Read our <a href="/terms">terms</a>.',
        ]);
        // The would-have-caught assertion: the link is a working anchor, NOT the
        // literal escaped source the esc_html renderer produced before #439.
        $this->assertStringContainsString('<a href="/terms">terms</a>', $html);
        $this->assertStringNotContainsString('&lt;a href', $html);
    }

    public function testCtaTextStripsScriptAndBlockTags(): void
    {
        $html = $this->render('cta', [
            'button_text' => 'Go',
            'button_url'  => '/go',
            'body'        => 'Hi<script>alert(1)</script><div>block</div><strong>ok</strong>',
        ]);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<div>block', $html);
        $this->assertStringContainsString('<strong>ok</strong>', $html);
    }

    public function testCtaTextPlainStringUnchanged(): void
    {
        $html = $this->render('cta', [
            'button_text' => 'Go',
            'button_url'  => '/go',
            'body'        => 'Just a plain supporting line.',
        ]);
        $this->assertStringContainsString('<p class="cta__body">Just a plain supporting line.</p>', $html);
    }

    public function testGridItemTextRendersInlineLink(): void
    {
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Card', 'text' => 'See <a href="/docs">docs</a> and <em>more</em>.'],
            ],
        ]);
        $this->assertStringContainsString('<a href="/docs">docs</a>', $html);
        $this->assertStringContainsString('<em>more</em>', $html);
        $this->assertStringNotContainsString('&lt;a href', $html);
    }

    public function testGridItemTextStripsDisallowedTags(): void
    {
        $html = $this->render('grid', [
            'items' => [
                ['title' => 'Card', 'text' => '<p>para</p><script>x</script>ok'],
            ],
        ]);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<p>para', $html);
    }

    public function testTestimonialsQuoteRendersInlineLink(): void
    {
        $html = $this->render('testimonials', [
            'items' => [
                ['quote' => 'Best <strong>tool</strong> — see <a href="/case">case study</a>.', 'author' => 'Ana'],
            ],
        ]);
        $this->assertStringContainsString('<strong>tool</strong>', $html);
        $this->assertStringContainsString('<a href="/case">case study</a>', $html);
        $this->assertStringNotContainsString('&lt;a href', $html);
    }

    public function testTestimonialsQuoteStripsDisallowedTags(): void
    {
        $html = $this->render('testimonials', [
            'items' => [
                ['quote' => '<div>x</div><script>y</script>ok<em>e</em>', 'author' => 'Ana'],
            ],
        ]);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<div>x', $html);
        $this->assertStringContainsString('<em>e</em>', $html);
    }

    // ── Props kept plain-text still escape byte-identically ──────────────────

    public function testStatsLabelStillEscapesMarkup(): void
    {
        $html = $this->render('stats', [
            'items' => [
                ['number' => '10', 'label' => 'Ships <b>fast</b>'],
            ],
        ]);
        // Plain-text contract: the markup is shown as escaped source, never active.
        $this->assertStringContainsString('Ships &lt;b&gt;fast&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>fast', $html);
    }

    public function testCtaTitleStillEscapesMarkup(): void
    {
        $html = $this->render('cta', [
            'button_text' => 'Go',
            'button_url'  => '/go',
            'title'       => 'Plan <b>X</b>',
        ]);
        $this->assertStringContainsString('Plan &lt;b&gt;X&lt;/b&gt;', $html);
    }

    // ── pp_kses_inline helper: allowlist, coercion, protocol handling ────────

    public function testHelperKeepsAllowlistedTagsOnly(): void
    {
        $out = pp_kses_inline('<a href="/x" title="t">L</a> <strong>b</strong> <em>i</em><br>');
        $this->assertStringContainsString('<a href="/x" title="t">L</a>', $out);
        $this->assertStringContainsString('<strong>b</strong>', $out);
        $this->assertStringContainsString('<em>i</em>', $out);
        $this->assertStringContainsString('<br />', $out);
    }

    public function testHelperStripsDisallowedAttributes(): void
    {
        // onclick / class are not on the a allowlist (href/title only).
        $out = pp_kses_inline('<a href="/x" onclick="steal()" class="c">L</a>');
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('class', $out);
        $this->assertStringContainsString('href="/x"', $out);
    }

    public function testHelperDropsJavascriptProtocolHref(): void
    {
        $out = pp_kses_inline('<a href="javascript:alert(1)">L</a>');
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function testHelperCoercesNonStringToEmpty(): void
    {
        $this->assertSame('', pp_kses_inline(null));
        $this->assertSame('', pp_kses_inline(['a' => 1]));
        $this->assertSame('', pp_kses_inline(123));
        $this->assertSame('', pp_kses_inline(''));
    }

    public function testHelperLeavesPlainTextUnchanged(): void
    {
        $plain = 'A plain supporting line with no markup.';
        $this->assertSame($plain, pp_kses_inline($plain));
    }

    public function testPreEscapedMarkupStaysLiteral(): void
    {
        // Migration/compat (#439): content that was already escaped (entities) must
        // NOT be reactivated into live markup — it stays visible as literal text.
        $out = pp_kses_inline('Legacy &lt;a href="/x"&gt;link&lt;/a&gt; copy.');
        $this->assertStringContainsString('&lt;a href="/x"&gt;link&lt;/a&gt;', $out);
        $this->assertStringNotContainsString('<a href="/x">link</a>', $out);
    }

    // ── Doc-contract enumeration: every text prop states its markup contract ──

    /**
     * The curated set of text (content) props in the components that own the
     * markup contract, with the contract each must declare. The test fails if any
     * description drops its contract marker — the guard the issue asks for.
     */
    private const CONTRACT_MARKERS = [
        'plain'  => 'Plain text — HTML is escaped.',
        'inline' => 'Inline HTML allowed: a, strong, em, br.',
        'rich'   => 'Rich HTML (sanitized via wp_kses_post)',
    ];

    public static function contractPropProvider(): array
    {
        // [component, dotted-path-under-props, contract-kind]
        return [
            // cta
            ['cta', 'title', 'plain'],
            ['cta', 'eyebrow', 'plain'],
            ['cta', 'body', 'inline'],
            ['cta', 'button_text', 'plain'],
            // grid (top-level + item props under items.items)
            ['grid', 'title', 'plain'],
            ['grid', 'eyebrow', 'plain'],
            ['grid', 'subheading', 'plain'],
            ['grid', 'items.items.title', 'plain'],
            ['grid', 'items.items.text', 'inline'],
            // testimonials
            ['testimonials', 'title', 'plain'],
            ['testimonials', 'eyebrow', 'plain'],
            ['testimonials', 'subheading', 'plain'],
            ['testimonials', 'items.items.quote', 'inline'],
            ['testimonials', 'items.items.author', 'plain'],
            // stats
            ['stats', 'title', 'plain'],
            ['stats', 'items.items.number', 'plain'],
            ['stats', 'items.items.label', 'plain'],
            // section / faq (the rich-HTML surfaces + faq question plain)
            ['section', 'body', 'rich'],
            ['faq', 'items.items.question', 'plain'],
            ['faq', 'items.items.answer', 'rich'],
        ];
    }

    /**
     * @dataProvider contractPropProvider
     */
    public function testEveryTextPropDeclaresItsContract(string $component, string $path, string $kind): void
    {
        $schema = json_decode(
            file_get_contents($this->themeRoot . "/components/$component/schema.json"),
            true
        );
        $this->assertIsArray($schema, "$component schema.json is not valid JSON");

        $node = $schema['props'] ?? [];
        foreach (explode('.', $path) as $segment) {
            $this->assertArrayHasKey($segment, $node, "$component: prop path '$path' missing at '$segment'");
            $node = $node[$segment];
        }
        $desc = $node['description'] ?? '';
        $marker = self::CONTRACT_MARKERS[$kind];
        $this->assertStringContainsString(
            $marker,
            $desc,
            "$component.$path must state its '$kind' markup contract (expected marker: \"$marker\")"
        );
    }
}
