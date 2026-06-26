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
}
