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

    // ── Gradient-typed style slots render unmangled (#99) ────────────────

    public function testRenderStyleVarsGradientSurvivesUnmangledForHero(): void
    {
        // pp_render_style_vars() only applies the {};<> injection guard — it
        // does not re-validate type, so a gradient value round-trips exactly
        // like a flat color already does (testRenderStyleVarsBasic above).
        $result = pp_render_style_vars(
            ['--hero-bg' => 'linear-gradient(135deg, #1a1a2e, #16121f)'],
            'hero'
        );
        $this->assertStringContainsString('--hero-bg: linear-gradient(135deg, #1a1a2e, #16121f)', $result);
    }

    public function testRenderStyleVarsGradientSurvivesUnmangledForCta(): void
    {
        $result = pp_render_style_vars(
            ['--cta-bg' => 'radial-gradient(circle, #fff, #000)'],
            'cta'
        );
        $this->assertStringContainsString('--cta-bg: radial-gradient(circle, #fff, #000)', $result);
    }

    public function testRenderStyleVarsGradientSurvivesUnmangledForGrid(): void
    {
        $result = pp_render_style_vars(
            ['--grid-card-bg' => 'linear-gradient(180deg, #fff, #eee)'],
            'grid'
        );
        $this->assertStringContainsString('--grid-card-bg: linear-gradient(180deg, #fff, #eee)', $result);
    }

    public function testRenderStyleVarsGradientSurvivesUnmangledForSection(): void
    {
        $result = pp_render_style_vars(
            ['--section-bg' => 'linear-gradient(to bottom, #f0f4ff, #ffffff)'],
            'section'
        );
        $this->assertStringContainsString('--section-bg: linear-gradient(to bottom, #f0f4ff, #ffffff)', $result);
    }

    public function testRenderStyleVarsGradientOverlayScrimSurvivesUnmangled(): void
    {
        // The primary practical motivation for gradient support: a
        // transparent-to-dark scrim over a background image for legibility.
        $result = pp_render_style_vars(
            ['--hero-overlay-bg' => 'linear-gradient(to bottom, transparent, rgba(0,0,0,0.7))'],
            'hero'
        );
        $this->assertStringContainsString('--hero-overlay-bg: linear-gradient(to bottom, transparent, rgba(0,0,0,0.7))', $result);
    }

    public function testHeroComponentRendersGradientBackgroundInInlineStyle(): void
    {
        // End-to-end: a gradient value set on a real component's __pp_style
        // survives all the way into the rendered <section style="..."> output,
        // exactly the "exact production regression" pattern used for #36.
        $html = $this->render('hero', [
            'title' => 'Welcome',
            '__pp_style' => ['--hero-bg' => 'linear-gradient(135deg, #1a1a2e, #16121f)'],
        ]);
        $this->assertStringContainsString('--hero-bg: linear-gradient(135deg, #1a1a2e, #16121f)', $html);
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

    // ── Hero CTA variants (props, set via update_component) — #93 ───────────
    // Extends the shared .btn--*/--btn-* primitive (established for CTA above)
    // to hero's two CTA buttons, which previously hardcoded bare .btn / .btn--outline
    // with no way to select secondary/ghost. cta2_variant defaults to 'outline'
    // to preserve the historical always-outline second button.

    private function heroProps(array $extra = []): array
    {
        return array_merge(['title' => 'T', 'cta_text' => 'Go', 'cta2_text' => 'Learn more'], $extra);
    }

    public function testHeroCtaVariantDefaultsToPrimary(): void
    {
        $html = $this->render('hero', $this->heroProps());
        $this->assertMatchesRegularExpression('/class="hero__cta btn"[^-]/', $html . ' ');
    }

    public function testHeroCta2VariantDefaultsToOutline(): void
    {
        // Preserves pre-#93 behavior: an unset cta2_variant still renders outline.
        $html = $this->render('hero', $this->heroProps());
        $this->assertStringContainsString('class="hero__cta hero__cta--secondary btn btn--outline"', $html);
    }

    public function testHeroCtaVariantSecondary(): void
    {
        $html = $this->render('hero', $this->heroProps(['cta_variant' => 'secondary']));
        $this->assertStringContainsString('class="hero__cta btn btn--secondary"', $html);
    }

    public function testHeroCtaVariantGhost(): void
    {
        $html = $this->render('hero', $this->heroProps(['cta_variant' => 'ghost']));
        $this->assertStringContainsString('class="hero__cta btn btn--ghost"', $html);
    }

    public function testHeroCta2VariantPrimary(): void
    {
        $html = $this->render('hero', $this->heroProps(['cta2_variant' => 'primary']));
        $this->assertStringContainsString('class="hero__cta btn"', $html);
        // Neither button should carry a btn-- modifier now.
        $this->assertSame(0, substr_count($html, 'btn--'));
    }

    public function testHeroCtaVariantInvalidFallsBackToPrimary(): void
    {
        $html = $this->render('hero', $this->heroProps(['cta_variant' => 'neon']));
        $this->assertMatchesRegularExpression('/class="hero__cta btn"[^-]/', $html . ' ');
    }

    public function testHeroCta2VariantInvalidFallsBackToOutline(): void
    {
        $html = $this->render('hero', $this->heroProps(['cta2_variant' => 'neon']));
        $this->assertStringContainsString('class="hero__cta hero__cta--secondary btn btn--outline"', $html);
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
        // Allow for the :not(.btn--outline):not(.btn--ghost):not(.btn--secondary)
        // exclusion clauses added between .btn and { by the #111 cascade-bug fix.
        $this->assertMatchesRegularExpression(
            '/\.cta\s+\.btn[^{]*\{[^}]*var\(\s*--cta-accent/s',
            $css,
            'The component-scoped .cta .btn override must still consume var(--cta-accent) '
            . 'after the shared button was tokenized — otherwise old compositions lose their color.'
        );
    }

    // ── Secondary/outline button cascade bug + per-instance slots (#111) ────

    public function testCtaAccentFillExcludesOutlineGhostSecondary(): void
    {
        // Regression guard: .cta .btn (2 classes, specificity 0,2,0) has HIGHER
        // specificity than .btn--outline/--ghost/--secondary (1 class each,
        // 0,1,0), so without this :not() exclusion the accent fill
        // unconditionally wins regardless of source order. Confirmed
        // empirically (Playwright): without the exclusion, outline and ghost
        // both render with button text the same color as the button
        // background (fully invisible).
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $this->assertMatchesRegularExpression(
            '/\.cta\s+\.btn:not\(\.btn--outline\):not\(\.btn--ghost\):not\(\.btn--secondary\)\s*\{[^}]*var\(\s*--cta-accent\b/s',
            $css,
            ".cta .btn's accent-fill rule must exclude outline/ghost/secondary via :not(), "
            . 'or those variants render with an invisible/wrong-colored button (#111).'
        );
    }

    public function testHeroAccentFillExcludesOutlineGhostSecondary(): void
    {
        // Same guard for hero's equivalent rule (this specific fix predates
        // #111 — pins it so a future refactor can't silently reintroduce it).
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $this->assertMatchesRegularExpression(
            '/\.hero\s+\.btn:not\(\.btn--outline\):not\(\.btn--ghost\):not\(\.btn--secondary\)\s*\{[^}]*var\(\s*--hero-accent\b/s',
            $css,
            ".hero .btn's accent-fill rule must exclude outline/ghost/secondary via :not()."
        );
    }

    public function testHeroSchemaDeclaresAllSixCta2Slots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/hero/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        foreach (['--hero-cta2-bg', '--hero-cta2-border', '--hero-cta2-color', '--hero-cta2-hover-bg', '--hero-cta2-hover-border', '--hero-cta2-hover-color'] as $name) {
            $this->assertArrayHasKey($name, $slots, "hero must declare {$name}.");
            $this->assertSame('color', $slots[$name]['type']);
        }
    }

    public function testCtaSchemaDeclaresAllSixButtonSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/cta/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        foreach (['--cta-button-bg', '--cta-button-border', '--cta-button-color', '--cta-button-hover-bg', '--cta-button-hover-border', '--cta-button-hover-color'] as $name) {
            $this->assertArrayHasKey($name, $slots, "cta must declare {$name}.");
            $this->assertSame('color', $slots[$name]['type']);
        }
    }

    public function testHeroCta2OverrideAppliesOnlyToSecondaryButton(): void
    {
        // The override must be scoped to .hero__cta--secondary, not leak onto
        // the primary button — even when the override is set, the primary's
        // rendered class list carries no such class.
        $html = $this->render('hero', $this->heroProps([
            '__pp_style' => ['--hero-cta2-color' => '#00ff00'],
        ]));
        $this->assertStringContainsString('--hero-cta2-color: #00ff00', $html);
        $this->assertStringContainsString('hero__cta hero__cta--secondary btn', $html);
        // Only ONE button should carry the secondary class.
        $this->assertSame(1, substr_count($html, 'hero__cta--secondary'));
    }

    public function testCtaButtonOverrideRenders(): void
    {
        $html = $this->render('cta', $this->ctaProps([
            'button_variant' => 'outline',
            '__pp_style' => ['--cta-button-bg' => '#ff00ff', '--cta-button-border' => '#ffff00'],
        ]));
        $this->assertStringContainsString('--cta-button-bg: #ff00ff', $html);
        $this->assertStringContainsString('--cta-button-border: #ffff00', $html);
    }

    public function testHeroCta2VariantsAllRouteThroughOverrideSlotsInCss(): void
    {
        // Comprehensive coverage beyond the keystone contract test's "at least
        // one compatible consumption" check — every one of the 4 variant-specific
        // rule blocks must reference --hero-cta2-bg, not just one of them.
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $this->assertSame(
            4,
            substr_count($css, '--hero-cta2-bg'),
            '--hero-cta2-bg must be referenced in exactly 4 places: the primary-shape rule '
            . 'plus outline/secondary/ghost — one per variant (#111).'
        );
    }

    public function testCtaButtonVariantsAllRouteThroughOverrideSlotsInCss(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $this->assertSame(
            4,
            substr_count($css, '--cta-button-bg'),
            '--cta-button-bg must be referenced in exactly 4 places: the primary-shape rule '
            . 'plus outline/secondary/ghost — one per variant (#111).'
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

    // ── Second-round cross-model adversarial review: bypasses of the
    // DOMDocument-based rewrite ──────────────────────────────────────────

    public function testEscImageSrcRejectsSixLayerEncodedScriptTag(): void
    {
        // Six layers of percent-encoding must not survive a decode budget
        // that gives up too early — the loop must converge to a TRUE fixed
        // point (or reject outright), not just decode a fixed N rounds and
        // proceed on a still-partially-encoded string.
        $payload = '<svg><script>alert(1)</script></svg>';
        for ($i = 0; $i < 6; $i++) {
            $payload = rawurlencode($payload);
        }
        $this->assertSame('', pp_esc_image_src('data:image/svg+xml,' . $payload));
    }

    public function testEscImageSrcRejectsBackslashCssEscapedScriptTag(): void
    {
        // "\3c"/"\3e" are inert to an XML parser (just three ordinary
        // characters) but CSS's own url()-token tokenizer resolves
        // backslash-hex escapes independently of percent-decoding — this
        // reconstructs "<script>" only once the browser's CSS parser reads
        // the surrounding style="...url(...)..." attribute.
        $payload = 'data:image/svg+xml,<svg><rect data-x="\3cscript\3ealert(1)\3c/script\3e"/></svg>';
        $result = pp_esc_image_src($payload);
        $this->assertNotSame('', $result, 'A literal backslash in otherwise-safe SVG content should not be rejected outright.');
        $this->assertStringNotContainsString('\\', $result);
    }

    public function testEscImageSrcRejectsStyleElement(): void
    {
        // Unlike <script>, an SVG's own <style> element IS applied during
        // ordinary <img>/background-image rendering — an @import inside it
        // would exfiltrate on every page view, not just on a deliberate
        // "open image in new tab."
        $payload = 'data:image/svg+xml,<svg><style>@import url(https://evil.test/x.css);</style></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsExternalHrefOnUseElement(): void
    {
        $payload = 'data:image/svg+xml,<svg xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://evil.test/x.svg#y"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsExternalHrefOnImageElement(): void
    {
        $payload = 'data:image/svg+xml,<svg><image href="https://evil.test/beacon.png"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcAcceptsSameDocumentFragmentHrefOnUseElement(): void
    {
        // A <use> referencing a locally-defined symbol is a legitimate,
        // common SVG pattern and must not be rejected.
        $payload = 'data:image/svg+xml,<svg><symbol id="icon"><circle r="5"/></symbol><use href="#icon"/></svg>';
        $this->assertNotSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsSmilAnimateRetargetingHref(): void
    {
        $payload = 'data:image/svg+xml,<svg><a id="x" href="https://safe.example/"><text>click</text></a>'
            . '<animate href="#x" attributeName="href" values="https://safe.example/;javascript:alert(1)" dur="1s"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsProcessingInstruction(): void
    {
        $payload = 'data:image/svg+xml,<?xml-stylesheet type="text/css" href="https://evil.test/x.css"?><svg><circle r="5"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsJavascriptUriWithEmbeddedNewline(): void
    {
        // Browser URL parsing strips embedded tab/newline/CR from anywhere
        // in a URL string, so "java\nscript:" resolves to "javascript:" at
        // navigation time even though no literal "javascript:" substring
        // (and no attribute value literally starting with it) exists in
        // the parsed DOM text.
        $payload = "data:image/svg+xml,<svg><a href=\"java&#x0A;script:alert(1)\">x</a></svg>";
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsDataTextHtmlHrefInsideSvg(): void
    {
        // A literal "<" inside an attribute value isn't valid XML, so the
        // nested script markup is XML-entity-escaped here, same as any
        // well-formed SVG author would have to write it.
        $payload = 'data:image/svg+xml,<svg><a href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;">x</a></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    // ── Third-round Codex review: empirically confirmed in a real browser
    // (Playwright/Chromium) that these attributes trigger real network
    // requests to an external host during ORDINARY rendering — no click,
    // no top-level navigation required ────────────────────────────────────

    public function testEscImageSrcRejectsExternalUrlInStyleFilter(): void
    {
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><rect style="filter:url(https://evil.test/filter.svg#f)"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsExternalUrlInFilterAttribute(): void
    {
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><rect filter="url(https://evil.test/filter.svg#f)"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsExternalUrlInFillAttribute(): void
    {
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(https://evil.test/pattern.svg#p)"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsExternalUrlInStyleCursor(): void
    {
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><rect style="cursor:url(https://evil.test/cursor.png), auto"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcAcceptsSameDocumentFragmentUrlInFillAttribute(): void
    {
        // fill="url(#gradientId)" referencing a locally-defined gradient is
        // an extremely common, legitimate SVG pattern and must not be
        // rejected.
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"/></defs><rect fill="url(#g)"/></svg>';
        $this->assertNotSame('', pp_esc_image_src($payload));
    }

    // ── Fourth-round Claude adversarial review ──────────────────────────────

    public function testEscImageSrcRejectsExternalHrefOnFeImageElement(): void
    {
        // <feImage> is an SVG filter primitive whose entire purpose is
        // fetching an image resource — the same "fires on ordinary render"
        // exfiltration risk as <use>/<image>, but on a different element
        // name the original element-scoped fix didn't cover.
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<filter id="f"><feImage xlink:href="https://evil.test/beacon.png" x="0" y="0" width="10" height="10"/></filter>'
            . '<rect width="10" height="10" filter="url(#f)"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsNestedDataUriWithOnloadViaUseHref(): void
    {
        // A data: URI nested inside a <use href="..."> is validated
        // recursively — it's not automatically safe just because it's a
        // data: URI, since <use>'s clone-based reference model might treat
        // the referenced content as a live, scriptable subtree.
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg">'
            . '<use href="data:image/svg+xml,%3Csvg%20onload%3D%22alert(1)%22%2F%3E"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcAcceptsSameDocumentFragmentHrefOnPatternElement(): void
    {
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><defs><pattern id="p"/></defs><rect fill="url(#p)"/></svg>';
        $this->assertNotSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsExcessiveDataUriNestingDepth(): void
    {
        // Recursion depth guard: a data: URI nested inside a use/image href
        // is validated recursively (see the previous test) — that recursion
        // must be bounded rather than following an attacker-constructed
        // chain indefinitely. Exercised directly via the internal $depth
        // parameter (documented as "callers never need to pass this" —
        // ordinary callers always start at the default of 0).
        $svg = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>';
        $this->assertNotSame('', pp_esc_image_src($svg, 0), 'Sanity check: valid at depth 0.');
        $this->assertSame('', pp_esc_image_src($svg, 4), 'Must reject once the depth cap is exceeded.');
    }

    // ── Sixth-round Codex adversarial review (empirical Playwright) ─────────

    public function testEscImageSrcRejectsCssHexEscapedUrlFunctionName(): void
    {
        // Browsers resolve CSS Syntax Level 3 \XX hex escapes BEFORE
        // recognizing the "url" function name. fill="u\72l(...)" is not the
        // literal substring "url(" and evaded the plain regex scan for it,
        // but Chromium resolves \72 to "r" while parsing the value and
        // fetches the external resource exactly as if "url(" had been
        // written literally — confirmed empirically via Playwright against
        // both the raw and base64-encoded payload forms.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20">'
            . '<rect width="20" height="20" fill="u\72l(https://evil.test/p.svg#p)"/></svg>';
        $this->assertSame('', pp_esc_image_src('data:image/svg+xml,' . rawurlencode($svg)));
        $this->assertSame('', pp_esc_image_src('data:image/svg+xml;base64,' . base64_encode($svg)));
    }

    public function testEscImageSrcRejectsCssNumericEscapedUrlFunctionName(): void
    {
        // Every character of "url" escaped individually (\75\72\6c), not
        // just one — the same bypass class, exercised at its most extreme.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20">'
            . '<rect width="20" height="20" fill="\75\72\6c(https://evil.test/p.svg#p)"/></svg>';
        $this->assertSame('', pp_esc_image_src('data:image/svg+xml,' . rawurlencode($svg)));
    }

    public function testEscImageSrcRejectsXmlBaseAttribute(): void
    {
        // xml:base (SVG/XML's equivalent of <base href>) can retarget a
        // "#fragment" reference — treated everywhere else in this function
        // as unconditionally same-document-safe — to resolve against an
        // attacker-controlled origin instead (RFC 3986 §5.3: a
        // fragment-only reference resolved against a base URL takes on the
        // base's scheme+authority+path). Rejected outright regardless of
        // whether a #fragment reference is even present.
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" xml:base="https://evil.test/">'
            . '<rect width="10" height="10"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcRejectsXmlBaseWithFragmentReference(): void
    {
        // The concrete exploit shape: xml:base on the root plus a same-
        // document fill="url(#leak)" that would resolve externally.
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" xml:base="https://evil.test/marker">'
            . '<defs><linearGradient id="leak"/></defs><rect width="10" height="10" fill="url(#leak)"/></svg>';
        $this->assertSame('', pp_esc_image_src($payload));
    }

    public function testEscImageSrcAcceptsSameDocumentFragmentAfterCssUnescape(): void
    {
        // The CSS-unescape pass must not turn a legitimate, safe reference
        // into a false-positive rejection — a literal backslash preceding a
        // non-hex character is a valid CSS "escaped character" that decodes
        // to that character itself, so this must still resolve to the
        // harmless local fragment reference "#g".
        $payload = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"/></defs>'
            . '<rect fill="ur\6c(#g)"/></svg>';
        $this->assertNotSame('', pp_esc_image_src($payload));
    }

    // ── faq / stats style slots (#100) ───────────────────────────────────

    private function faqProps(array $extra = []): array
    {
        return array_merge(['title' => 'FAQ', 'items' => [['question' => 'Q?', 'answer' => 'A.']]], $extra);
    }

    private function statsProps(array $extra = []): array
    {
        return array_merge(['title' => 'Stats', 'items' => [['number' => '40+', 'label' => 'Years']]], $extra);
    }

    public function testFaqSchemaDeclaresAllSevenStyleSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/faq/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        $expected = [
            '--faq-bg' => 'gradient',
            '--faq-item-bg' => 'gradient',
            '--faq-heading-color' => 'color',
            '--faq-question-color' => 'color',
            '--faq-answer-color' => 'color',
            '--faq-border-color' => 'color',
            '--faq-accent' => 'color',
        ];
        foreach ($expected as $name => $type) {
            $this->assertArrayHasKey($name, $slots, "faq must declare {$name}.");
            $this->assertSame($type, $slots[$name]['type'], "{$name} must be type {$type}.");
            $this->assertArrayHasKey('default', $slots[$name]);
            $this->assertNotEmpty($slots[$name]['description']);
        }
    }

    public function testStatsSchemaDeclaresAllFourStyleSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/stats/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        $expected = [
            '--stats-bg' => 'gradient',
            '--stats-title-color' => 'color',
            '--stats-number-color' => 'color',
            '--stats-label-color' => 'color',
        ];
        foreach ($expected as $name => $type) {
            $this->assertArrayHasKey($name, $slots, "stats must declare {$name}.");
            $this->assertSame($type, $slots[$name]['type'], "{$name} must be type {$type}.");
            $this->assertArrayHasKey('default', $slots[$name]);
            $this->assertNotEmpty($slots[$name]['description']);
        }
    }

    public function testFaqRendersHeadingColorSlot(): void
    {
        // The exact production regression #100 fixes: faq could not reach brand
        // fidelity on a dark surface because it had no --faq-heading-color slot.
        $html = $this->render('faq', array_merge($this->faqProps(), [
            '__pp_style' => ['--faq-heading-color' => '#ffffff'],
        ]));
        $this->assertStringContainsString('--faq-heading-color: #ffffff', $html);
    }

    public function testFaqRendersAllSevenSlots(): void
    {
        $overrides = [
            '--faq-bg' => 'linear-gradient(135deg, #1a1a2e, #16121f)',
            '--faq-item-bg' => '#222222',
            '--faq-heading-color' => '#ffffff',
            '--faq-question-color' => '#eeeeee',
            '--faq-answer-color' => '#cccccc',
            '--faq-border-color' => '#333333',
            '--faq-accent' => '#ea3900',
        ];
        $html = $this->render('faq', array_merge($this->faqProps(), ['__pp_style' => $overrides]));
        foreach ($overrides as $slot => $value) {
            $this->assertStringContainsString("{$slot}: {$value}", $html, "{$slot} did not render.");
        }
    }

    public function testFaqRejectsInjectionInStyleSlot(): void
    {
        $html = $this->render('faq', array_merge($this->faqProps(), [
            '__pp_style' => ['--faq-heading-color' => '#fff; background:url(evil)'],
        ]));
        $this->assertStringNotContainsString('url(evil)', $html);
    }

    public function testStatsRendersAllFourSlots(): void
    {
        $overrides = [
            '--stats-bg' => 'radial-gradient(#fff, #000)',
            '--stats-title-color' => '#111111',
            '--stats-number-color' => '#ea3900',
            '--stats-label-color' => '#666666',
        ];
        $html = $this->render('stats', array_merge($this->statsProps(), ['__pp_style' => $overrides]));
        foreach ($overrides as $slot => $value) {
            $this->assertStringContainsString("{$slot}: {$value}", $html, "{$slot} did not render.");
        }
    }

    public function testStatsStyleSlotMergesWithBackgroundImage(): void
    {
        // stats.php's __pp_style rendering must coexist with the pre-existing
        // background_image inline-style mechanism (same pattern as hero/section).
        $html = $this->render('stats', array_merge($this->statsProps(), [
            '__pp_style' => ['--stats-number-color' => '#ea3900'],
            'background_image' => 'https://example.com/bg.jpg',
        ]));
        $this->assertStringContainsString('--stats-number-color: #ea3900', $html);
        $this->assertStringContainsString('background-image:url(', $html);
    }

    public function testStatsRejectsInjectionInStyleSlot(): void
    {
        $html = $this->render('stats', array_merge($this->statsProps(), [
            '__pp_style' => ['--stats-title-color' => '#fff; background:url(evil)'],
        ]));
        $this->assertStringNotContainsString('url(evil)', $html);
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
