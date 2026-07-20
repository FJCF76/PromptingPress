<?php
/**
 * tests/HeroCompositionTest.php — PHPUnit tests for hero composition props
 *
 * Covers: split_ratio, vertical_align, proof slot.
 */

use PHPUnit\Framework\TestCase;

class HeroCompositionTest extends TestCase
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

    private function render(array $props): string
    {
        ob_start();
        pp_get_component('hero', $props);
        return ob_get_clean();
    }

    // ── Split Ratio ───────────────────────────────────────────────────────

    // Split-geometry props only apply when the split actually renders two
    // columns, so these cases carry an image — a media-less split degrades to
    // the single-column "left" layout (#440) and would drop the attribute for a
    // reason unrelated to the split_ratio logic under test.
    public function testSplitRatio6040OutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'image_url' => 'https://example.com/wp-content/uploads/2026/07/split.png',
            'split_ratio' => '60-40',
        ]);
        $this->assertStringContainsString('data-pp-split-ratio="60-40"', $html);
    }

    public function testSplitRatio4060OutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'image_url' => 'https://example.com/wp-content/uploads/2026/07/split.png',
            'split_ratio' => '40-60',
        ]);
        $this->assertStringContainsString('data-pp-split-ratio="40-60"', $html);
    }

    public function testSplitRatioDefaultOmitsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'image_url' => 'https://example.com/wp-content/uploads/2026/07/split.png',
            'split_ratio' => '50-50',
        ]);
        $this->assertStringNotContainsString('data-pp-split-ratio', $html);
    }

    public function testSplitRatioInvalidFallsBackToDefault(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'image_url' => 'https://example.com/wp-content/uploads/2026/07/split.png',
            'split_ratio' => '70-30',
        ]);
        $this->assertStringNotContainsString('data-pp-split-ratio', $html);
    }

    public function testSplitRatioIgnoredOnNonSplitVariant(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'centered',
            'split_ratio' => '60-40',
        ]);
        $this->assertStringNotContainsString('data-pp-split-ratio', $html);
    }

    // ── Vertical Align ────────────────────────────────────────────────────

    public function testVerticalAlignTopOnCoverOutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'cover',
            'vertical_align' => 'top',
        ]);
        $this->assertStringContainsString('data-pp-vertical-align="top"', $html);
    }

    public function testVerticalAlignBottomOnSplitOutputsAttribute(): void
    {
        // Carries an image so the split keeps its two columns; a media-less
        // split degrades to "left", which is not in the vertical-align set (#440).
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'image_url' => 'https://example.com/wp-content/uploads/2026/07/split.png',
            'vertical_align' => 'bottom',
        ]);
        $this->assertStringContainsString('data-pp-vertical-align="bottom"', $html);
    }

    public function testVerticalAlignDefaultOmitsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'cover',
            'vertical_align' => 'center',
        ]);
        $this->assertStringNotContainsString('data-pp-vertical-align', $html);
    }

    public function testVerticalAlignInvalidFallsBackToDefault(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'cover',
            'vertical_align' => 'middle',
        ]);
        $this->assertStringNotContainsString('data-pp-vertical-align', $html);
    }

    public function testVerticalAlignIgnoredOnCenteredVariant(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'centered',
            'vertical_align' => 'top',
        ]);
        $this->assertStringNotContainsString('data-pp-vertical-align', $html);
    }

    // ── Proof Slot ────────────────────────────────────────────────────────

    public function testProofNonEmptyRendersDiv(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'proof' => '<p>Trusted by 500+ companies</p>',
        ]);
        $this->assertStringContainsString('hero__proof', $html);
        $this->assertStringContainsString('Trusted by 500+ companies', $html);
    }

    public function testProofEmptyRendersNoDiv(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'proof' => '',
        ]);
        $this->assertStringNotContainsString('hero__proof', $html);
    }

    public function testProofHtmlIsSanitized(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'proof' => '<img src="logo.png" alt="Logo" width="120"> <span>4.9 stars</span>',
        ]);
        $this->assertStringContainsString('hero__proof', $html);
        $this->assertStringContainsString('<img src="logo.png"', $html);
        $this->assertStringContainsString('4.9 stars', $html);
    }

    public function testSplitVariantRendersProofAsSurface(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'proof' => '<p class="hero__surface-label">Workflow surface</p>',
        ]);
        $this->assertStringContainsString('hero__surface', $html);
        $this->assertStringNotContainsString('hero__proof', $html);
        $this->assertStringContainsString('Workflow surface', $html);
    }

    // ── Width ─────────────────────────────────────────────────────────────

    public function testWidthNarrowOutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'width' => 'narrow',
        ]);
        $this->assertStringContainsString('data-pp-width="narrow"', $html);
    }

    public function testWidthFullOutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'width' => 'full',
        ]);
        $this->assertStringContainsString('data-pp-width="full"', $html);
    }

    public function testWidthDefaultOmitsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'width' => 'default',
        ]);
        $this->assertStringNotContainsString('data-pp-width', $html);
    }

    public function testWidthInvalidFallsBackToDefault(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'width' => 'extra-wide',
        ]);
        $this->assertStringNotContainsString('data-pp-width', $html);
    }

    // ── Spacing (compact enum) ────────────────────────────────────────────

    public function testSpacingCompactOutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'spacing' => 'compact',
        ]);
        $this->assertStringContainsString('data-pp-spacing="compact"', $html);
    }

    // ── Split media degradation (#440) ────────────────────────────────────
    // Three acceptance states from the issue: split+image unchanged,
    // split+proof unchanged, split+neither degrades to single-column "left".

    public function testSplitWithImageRendersTwoColumnSplit(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'image_url' => 'https://example.com/wp-content/uploads/2026/07/split.png',
            'image_alt' => 'Split image',
        ]);
        // Still the split layout with the image column — no degradation.
        $this->assertStringContainsString('hero hero--split', $html);
        $this->assertStringNotContainsString('hero--left', $html);
        $this->assertStringContainsString('hero__image-wrap', $html);
        $this->assertStringContainsString('src="https://example.com/wp-content/uploads/2026/07/split.png"', $html);
    }

    public function testSplitWithProofNoImageRendersSurface(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'proof' => '<p>Product workflow surface</p>',
        ]);
        // Proof fills the second column — working state today, unchanged.
        $this->assertStringContainsString('hero hero--split', $html);
        $this->assertStringNotContainsString('hero--left', $html);
        $this->assertStringContainsString('hero__surface', $html);
        $this->assertStringContainsString('Product workflow surface', $html);
    }

    public function testSplitWithNoImageNoProofDegradesToLeft(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
        ]);
        // No second-column content: degrade to the single-column "left" layout.
        $this->assertStringContainsString('hero hero--left', $html);
        $this->assertStringNotContainsString('hero--split', $html);
        // No empty reserved column: neither the image wrap nor the surface renders.
        $this->assertStringNotContainsString('hero__image-wrap', $html);
        $this->assertStringNotContainsString('hero__surface', $html);
    }

    public function testSplitDegradationDropsSplitGeometryAttributes(): void
    {
        // Even with split-only geometry props set, a media-less split degrades,
        // so the split-scoped attributes must not appear.
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'split_ratio' => '60-40',
            'vertical_align' => 'bottom',
        ]);
        $this->assertStringContainsString('hero hero--left', $html);
        $this->assertStringNotContainsString('data-pp-split-ratio', $html);
        $this->assertStringNotContainsString('data-pp-vertical-align', $html);
    }

    public function testSplitWithNonNumericImageIdDegradesToLeft(): void
    {
        // (int) cast makes a non-numeric image_id resolve to 0, i.e. no media,
        // so the split degrades exactly as the "no image" case does.
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'image_id' => 'abc',
        ]);
        $this->assertStringContainsString('hero hero--left', $html);
        $this->assertStringNotContainsString('hero--split', $html);
        $this->assertStringNotContainsString('hero__image-wrap', $html);
    }

    public function testSplitWithImageIdOnlyDoesNotDegrade(): void
    {
        // image_id resolves to a real attachment via wp_get_attachment_image(),
        // so a split with image_id but no image_url keeps its two columns and
        // renders the responsive image (not an empty reserved column).
        $GLOBALS['_pp_test_store']['attachment_urls'][42] =
            'https://example.com/wp-content/uploads/2026/07/from-id.png';
        $html = $this->render([
            'title' => 'Test',
            'layout' => 'split',
            'image_id' => 42,
            'image_alt' => 'From attachment id',
        ]);
        $this->assertStringContainsString('hero hero--split', $html);
        $this->assertStringNotContainsString('hero--left', $html);
        $this->assertStringContainsString('hero__image-wrap', $html);
        $this->assertStringContainsString('from-id.png', $html);
    }
}
