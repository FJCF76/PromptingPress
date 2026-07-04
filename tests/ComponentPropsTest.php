<?php
/**
 * tests/ComponentPropsTest.php — PHPUnit tests for hero spacing and section centered layout
 *
 * Covers: data-pp-spacing on hero (only component retaining spacing prop),
 *         and section centered layout variant.
 */

use PHPUnit\Framework\TestCase;

class ComponentPropsTest extends TestCase
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
        ];
    }

    /**
     * Helper: render a component and return its HTML output.
     */
    private function render(string $component, array $props): string
    {
        ob_start();
        pp_get_component($component, $props);
        return ob_get_clean();
    }

    // ── Section Centered Layout ────────────────────────────────────────────

    public function testSectionCenteredIsValidLayout(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Centered content</p>',
            'layout' => 'centered',
        ]);
        $this->assertStringContainsString('section--centered', $html);
    }

    public function testSectionCenteredRendersBodyWithoutImageDiv(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Body text</p>',
            'layout' => 'centered',
        ]);
        $this->assertStringContainsString('section__body', $html);
        $this->assertStringNotContainsString('section__image', $html);
        $this->assertStringNotContainsString('section__grid', $html);
    }

    public function testSectionCenteredHasCenteredClass(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'layout' => 'centered',
        ]);
        $this->assertStringContainsString('class="section section--centered"', $html);
    }

    public function testSectionCenteredSuppressesImageEvenWhenProvided(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'layout' => 'centered',
            'image_url' => 'https://example.com/image.jpg',
        ]);
        $this->assertStringContainsString('section--centered', $html);
        $this->assertStringNotContainsString('section__image', $html);
        $this->assertStringNotContainsString('example.com/image.jpg', $html);
    }

    // ── Hero Spacing (hero retains spacing prop) ────────────────────────────

    public function testHeroSpacingOutputsAttribute(): void
    {
        $html = $this->render('hero', [
            'title' => 'Test Hero',
            'spacing' => 'spacious',
        ]);
        $this->assertStringContainsString('data-pp-spacing="spacious"', $html);
    }

    public function testHeroSpacingInvalidFallsBackToDefault(): void
    {
        $html = $this->render('hero', [
            'title' => 'Test Hero',
            'spacing' => 'huge',
        ]);
        $this->assertStringNotContainsString('data-pp-spacing', $html);
    }

    // ── Centered layout (unrelated to width/spacing props) ───────────────────

    public function testSectionCenteredRendersWithoutWidthOrSpacingAttributes(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'layout' => 'centered',
        ]);
        $this->assertStringContainsString('section--centered', $html);
        $this->assertStringNotContainsString('data-pp-spacing', $html);
        $this->assertStringNotContainsString('data-pp-width', $html);
    }

    // ── Regression: stripped components ignore width/spacing ──────────────

    /**
     * @dataProvider strippedComponentProvider
     */
    public function testStrippedComponentIgnoresWidth(string $component, array $baseProps): void
    {
        $html = $this->render($component, array_merge($baseProps, ['width' => 'narrow']));
        $this->assertStringNotContainsString('data-pp-width', $html);
    }

    /**
     * @dataProvider strippedComponentProvider
     */
    public function testStrippedComponentIgnoresSpacing(string $component, array $baseProps): void
    {
        $html = $this->render($component, array_merge($baseProps, ['spacing' => 'compact']));
        $this->assertStringNotContainsString('data-pp-spacing', $html);
    }

    public static function strippedComponentProvider(): array
    {
        return [
            'section' => ['section', ['body' => '<p>Test</p>']],
            'cta'     => ['cta', ['title' => 'Test', 'button_text' => 'Go']],
            'grid'    => ['grid', ['items' => [['title' => 'A', 'body' => 'B']]]],
            'stats'   => ['stats', ['items' => [['number' => '10', 'label' => 'X']]]],
            'logos'   => ['logos', ['items' => [['image_url' => 'logo.png']]]],
            'embed'   => ['embed', ['content' => '<p>Embed</p>']],
        ];
    }

    // ── pp_render_style_vars() ───────────────────────────────────────────

    public function testRenderStyleVarsBasic(): void
    {
        $result = pp_render_style_vars(
            ['--hero-bg' => '#1a1a2e', '--hero-padding-top' => '8rem'],
            'hero'
        );
        $this->assertStringContainsString('--hero-bg: #1a1a2e', $result);
        $this->assertStringContainsString('--hero-padding-top: 8rem', $result);
    }

    public function testRenderStyleVarsEmpty(): void
    {
        $result = pp_render_style_vars([], 'hero');
        $this->assertSame('', $result);
    }

    public function testRenderStyleVarsSkipsUnknownSlot(): void
    {
        $result = pp_render_style_vars(
            ['--hero-bg' => '#1a1a2e', '--hero-display' => 'none'],
            'hero'
        );
        $this->assertStringContainsString('--hero-bg', $result);
        $this->assertStringNotContainsString('--hero-display', $result);
    }

    public function testRenderStyleVarsSkipsRecipeKey(): void
    {
        $result = pp_render_style_vars(
            ['__recipe' => 'dark-spacious', '--hero-bg' => '#1a1a2e'],
            'hero'
        );
        $this->assertStringNotContainsString('__recipe', $result);
        $this->assertStringContainsString('--hero-bg', $result);
    }

    public function testRenderStyleVarsRejectsInjection(): void
    {
        $result = pp_render_style_vars(
            ['--hero-bg' => '#fff; background-image: url(evil)'],
            'hero'
        );
        // Semicolon in value triggers injection guard — slot is skipped.
        $this->assertSame('', $result);
    }

    public function testRenderStyleVarsUnknownComponent(): void
    {
        $result = pp_render_style_vars(
            ['--fake-bg' => '#000'],
            'nonexistent'
        );
        $this->assertSame('', $result);
    }

    // ── CTA button_variant (prop, set via update_component) ──────────────

    private function ctaProps(array $extra = []): array
    {
        return array_merge(['title' => 'T', 'button_text' => 'Go', 'button_url' => '#'], $extra);
    }

    public function testCtaButtonVariantPrimaryIsBareBtn(): void
    {
        $html = $this->render('cta', $this->ctaProps(['button_variant' => 'primary']));
        $this->assertStringContainsString('class="cta__button btn"', $html);
        $this->assertStringNotContainsString('btn--', $html);
    }

    public function testCtaButtonVariantSecondary(): void
    {
        $html = $this->render('cta', $this->ctaProps(['button_variant' => 'secondary']));
        $this->assertStringContainsString('btn--secondary', $html);
    }

    public function testCtaButtonVariantOutline(): void
    {
        $html = $this->render('cta', $this->ctaProps(['button_variant' => 'outline']));
        $this->assertStringContainsString('btn--outline', $html);
    }

    public function testCtaButtonVariantGhost(): void
    {
        $html = $this->render('cta', $this->ctaProps(['button_variant' => 'ghost']));
        $this->assertStringContainsString('btn--ghost', $html);
    }

    public function testCtaButtonVariantInvalidFallsBackToPrimary(): void
    {
        $html = $this->render('cta', $this->ctaProps(['button_variant' => 'neon']));
        $this->assertStringContainsString('class="cta__button btn"', $html);
        $this->assertStringNotContainsString('btn--', $html);
    }

    public function testCtaButtonVariantDefaultsToPrimary(): void
    {
        $html = $this->render('cta', $this->ctaProps());
        $this->assertStringContainsString('class="cta__button btn"', $html);
        $this->assertStringNotContainsString('btn--', $html);
    }

    // ── CRITICAL regression: button enrichment must not break --cta-accent ──
    //
    // The shared .btn was tokenized with --btn-* defaults. Existing compositions
    // and the dark-bold/accent-framed recipes color the CTA button via the
    // component-scoped `.cta .btn { background-color: var(--cta-accent, ...) }`
    // override. This proves that path still works end to end: the slot still
    // renders as an inline custom property AND the CSS still consumes it.

    public function testCtaAccentOnlyCompositionStillRendersAccentCustomProperty(): void
    {
        $html = $this->render('cta', $this->ctaProps([
            '__pp_style' => ['--cta-accent' => '#ff0000'],
        ]));
        $this->assertStringContainsString('--cta-accent: #ff0000', $html);
    }

    public function testCtaBtnCssStillConsumesCtaAccentAfterEnrichment(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $this->assertMatchesRegularExpression(
            '/\.cta\s+\.btn\s*\{[^}]*var\(\s*--cta-accent/s',
            $css,
            'The component-scoped .cta .btn override must still consume var(--cta-accent) '
            . 'after the shared button was tokenized — otherwise old compositions lose their color.'
        );
    }

    // ── pp_esc_image_src (#36) ───────────────────────────────────────────────

    public function testEscImageSrcEmptyReturnsEmpty(): void
    {
        $this->assertSame('', pp_esc_image_src(''));
    }

    public function testEscImageSrcDelegatesToEscUrlForOrdinaryUrls(): void
    {
        $this->assertSame(esc_url('https://example.com/photo.jpg'), pp_esc_image_src('https://example.com/photo.jpg'));
        $this->assertSame(esc_url('/wp-content/uploads/photo.jpg'), pp_esc_image_src('/wp-content/uploads/photo.jpg'));
    }

    public function testEscImageSrcAcceptsRawSvgDataUri(): void
    {
        $svg = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>';
        $result = pp_esc_image_src($svg);
        $this->assertNotSame('', $result, 'A well-formed raw SVG data URI must not be rejected outright.');
        $this->assertStringStartsWith('data:image/svg+xml,', $result);
        // The dangerous-in-context characters must be percent-encoded...
        $this->assertStringNotContainsString('"', $result);
        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
        // ...but the meaningful content is preserved (percent-decodes back):
        // '<' becomes %3C, the quote right after xmlns= becomes %22, and the
        // '=' itself is left alone (it's safe in both contexts already).
        $this->assertStringContainsString('%3Csvg', $result);
        $this->assertStringContainsString('xmlns=%22', $result);
    }

    public function testEscImageSrcAcceptsBase64PngDataUri(): void
    {
        $uri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB';
        $this->assertSame($uri, pp_esc_image_src($uri));
    }

    public function testEscImageSrcAcceptsBase64SvgWithCharset(): void
    {
        $uri = 'data:image/svg+xml;charset=utf-8;base64,PHN2Zz48L3N2Zz4=';
        $this->assertSame($uri, pp_esc_image_src($uri));
    }

    public function testEscImageSrcRejectsNonImageMimeType(): void
    {
        $this->assertSame('', pp_esc_image_src('data:text/html,<script>alert(1)</script>'));
        $this->assertSame('', pp_esc_image_src('data:application/javascript,alert(1)'));
    }

    public function testEscImageSrcRejectsUnlistedImageSubtype(): void
    {
        // bmp/tiff are real image mime types but not in the allowlist.
        $this->assertSame('', pp_esc_image_src('data:image/bmp;base64,AAAA'));
    }

    public function testEscImageSrcRejectsInvalidBase64Payload(): void
    {
        $this->assertSame('', pp_esc_image_src('data:image/png;base64,not valid base64!!'));
        $this->assertSame('', pp_esc_image_src('data:image/png;base64,'));
    }

    public function testEscImageSrcRejectsEmbeddedScriptTag(): void
    {
        $this->assertSame(
            '',
            pp_esc_image_src('data:image/svg+xml,<svg><script>alert(document.cookie)</script></svg>')
        );
    }

    public function testEscImageSrcRejectsJavascriptUriInSvg(): void
    {
        $this->assertSame(
            '',
            pp_esc_image_src('data:image/svg+xml,<svg><a href="javascript:alert(1)">x</a></svg>')
        );
    }

    public function testEscImageSrcRejectsEventHandlerAttribute(): void
    {
        $this->assertSame(
            '',
            pp_esc_image_src('data:image/svg+xml,<svg onload="alert(1)"><circle r="5"/></svg>')
        );
    }

    public function testEscImageSrcRejectsForeignObjectIframeEmbedObject(): void
    {
        $this->assertSame('', pp_esc_image_src('data:image/svg+xml,<svg><foreignObject><body>x</body></foreignObject></svg>'));
        $this->assertSame('', pp_esc_image_src('data:image/svg+xml,<svg><iframe src="//evil.test"></iframe></svg>'));
        $this->assertSame('', pp_esc_image_src('data:image/svg+xml,<svg><embed src="//evil.test"/></svg>'));
        $this->assertSame('', pp_esc_image_src('data:image/svg+xml,<svg><object data="//evil.test"></object></svg>'));
    }

    public function testEscImageSrcRejectsOversizedDataUri(): void
    {
        $huge = 'data:image/png;base64,' . str_repeat('A', 2000000);
        $this->assertSame('', pp_esc_image_src($huge));
    }

    public function testEscImageSrcEncodedResultCannotBreakOutOfHtmlAttribute(): void
    {
        // A payload attempting to smuggle an attribute-breakout via a literal
        // quote must come back with that quote neutralized, not rejected
        // outright (rejection is reserved for actual script-executing
        // constructs) — proving the encoded output is safe to concatenate
        // directly into src="..." with no further escaping.
        $payload = 'data:image/svg+xml,<svg data-x="y"><circle r="5"/></svg>';
        $result = pp_esc_image_src($payload);
        $this->assertStringNotContainsString('"', $result);
    }

    // ── Cross-model adversarial review: confirmed bypasses of an earlier,
    // regex/blocklist-only version of this function ───────────────────────

    public function testEscImageSrcRejectsNewlineSplitScriptTag(): void
    {
        // "<scr" + newline + "ipt>" defeats a literal "<script" substring
        // search but is not valid XML either way (a tag name cannot contain
        // whitespace) — must still come back rejected, not silently
        // reconstituted into "<script>" by an output-stripping step.
        $payload = "data:image/svg+xml,<svg><scr\nipt>alert(1)</scr\nipt></svg>";
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsPercentEncodedScriptTag(): void
    {
        // The entire malicious payload pre-encoded so no literal "<script"
        // substring ever appears in the raw input — must be caught after
        // decoding, not waved through because the check ran too early.
        $payload = 'data:image/svg+xml,%3Csvg%3E%3Cscript%3Ealert(document.domain)%3C%2Fscript%3E%3C%2Fsvg%3E';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsDoubleEncodedScriptTag(): void
    {
        $payload = 'data:image/svg+xml,%253Csvg%253E%253Cscript%253Ealert(1)%253C%252Fscript%253E%253C%252Fsvg%253E';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsNamespacePrefixedScriptTag(): void
    {
        // <x:script> where x is bound to the SVG namespace is equivalent to
        // <script> per XML namespace rules — a raw "<script" substring
        // search never sees it, but XPath's local-name() resolves the true
        // element name regardless of prefix.
        $payload = 'data:image/svg+xml,<svg xmlns:x="http://www.w3.org/2000/svg"><x:script>alert(1)</x:script></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsCharacterReferenceObfuscatedJavascriptUri(): void
    {
        // "jav&#x61;script:" contains no literal "javascript:" substring,
        // but a real XML parser resolves the numeric character reference
        // during parsing — by the time the attribute value is inspected,
        // it already reads "javascript:alert(1)".
        $payload = 'data:image/svg+xml,<svg><a href="jav&#x61;script:alert(1)">x</a></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsMaliciousContentInsideBase64Svg(): void
    {
        // The base64 alphabet is inert for HTML/CSS transport, but the SVG
        // it decodes to must still be scanned — "the alphabet is safe"
        // is not the same claim as "the decoded document is safe."
        $malicious = '<svg onload="alert(document.cookie)"><script>fetch(String.fromCharCode(47,47,101,118,105,108,46,116,101,115,116))</script></svg>';
        $payload = 'data:image/svg+xml;base64,' . base64_encode($malicious);
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsSvgWithDoctype(): void
    {
        // DOCTYPE is rejected outright rather than parsed "carefully" —
        // closes the external-entity/DTD attack surface unconditionally.
        $payload = "data:image/svg+xml,<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY x \"y\">]><svg>&x;</svg>";
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsMalformedXml(): void
    {
        $this->assertSame('', pp_esc_image_src('data:image/svg+xml,<svg><rect></svg>'));
    }

    public function testEscImageSrcAcceptsLegitimateMultilineFormattedSvg(): void
    {
        // Real-world hand-formatted/design-tool SVGs commonly span multiple
        // lines — this must not be treated as suspicious on its own.
        $svg = "data:image/svg+xml,<svg xmlns=\"http://www.w3.org/2000/svg\">\n  <circle r=\"5\"/>\n</svg>";
        $result = pp_esc_image_src($svg);
        $this->assertNotSame('', $result);
    }

    // ── End-to-end: exact production regression from #36 ────────────────────

    public function testHeroSplitVariantRendersDataUriSvgImageSrc(): void
    {
        $svg = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
        $html = $this->render('hero', [
            'title' => 'Welcome',
            'variant' => 'split',
            'image_url' => $svg,
        ]);
        $this->assertStringNotContainsString('src=""', $html, 'The exact #36 production regression: src="" instead of the data URI.');
        $this->assertMatchesRegularExpression('/src="data:image\/svg\+xml,[^"]*"/', $html);
    }

    public function testHeroCoverVariantRendersDataUriSvgBackgroundImage(): void
    {
        $svg = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
        $html = $this->render('hero', [
            'title' => 'Welcome',
            'variant' => 'cover',
            'image_url' => $svg,
        ]);
        $this->assertStringContainsString('background-image:url(data:image/svg+xml,', $html);
        $this->assertStringNotContainsString('background-image:url();', $html);
    }
}
