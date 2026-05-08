<?php
/**
 * tests/HeroCompositionTest.php — PHPUnit tests for hero composition props
 *
 * Covers: split_ratio, content_measure, vertical_align, proof slot.
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

    public function testSplitRatio6040OutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'split',
            'split_ratio' => '60-40',
        ]);
        $this->assertStringContainsString('data-pp-split-ratio="60-40"', $html);
    }

    public function testSplitRatio4060OutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'split',
            'split_ratio' => '40-60',
        ]);
        $this->assertStringContainsString('data-pp-split-ratio="40-60"', $html);
    }

    public function testSplitRatioDefaultOmitsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'split',
            'split_ratio' => '50-50',
        ]);
        $this->assertStringNotContainsString('data-pp-split-ratio', $html);
    }

    public function testSplitRatioInvalidFallsBackToDefault(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'split',
            'split_ratio' => '70-30',
        ]);
        $this->assertStringNotContainsString('data-pp-split-ratio', $html);
    }

    public function testSplitRatioIgnoredOnNonSplitVariant(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'centered',
            'split_ratio' => '60-40',
        ]);
        $this->assertStringNotContainsString('data-pp-split-ratio', $html);
    }

    // ── Content Measure ───────────────────────────────────────────────────

    public function testContentMeasureNarrowOutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'content_measure' => 'narrow',
        ]);
        $this->assertStringContainsString('data-pp-content-measure="narrow"', $html);
    }

    public function testContentMeasureWideOutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'content_measure' => 'wide',
        ]);
        $this->assertStringContainsString('data-pp-content-measure="wide"', $html);
    }

    public function testContentMeasureDefaultOmitsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'content_measure' => 'default',
        ]);
        $this->assertStringNotContainsString('data-pp-content-measure', $html);
    }

    public function testContentMeasureInvalidFallsBackToDefault(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'content_measure' => 'extra-wide',
        ]);
        $this->assertStringNotContainsString('data-pp-content-measure', $html);
    }

    // ── Vertical Align ────────────────────────────────────────────────────

    public function testVerticalAlignTopOnCoverOutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'cover',
            'vertical_align' => 'top',
        ]);
        $this->assertStringContainsString('data-pp-vertical-align="top"', $html);
    }

    public function testVerticalAlignBottomOnSplitOutputsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'split',
            'vertical_align' => 'bottom',
        ]);
        $this->assertStringContainsString('data-pp-vertical-align="bottom"', $html);
    }

    public function testVerticalAlignDefaultOmitsAttribute(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'cover',
            'vertical_align' => 'center',
        ]);
        $this->assertStringNotContainsString('data-pp-vertical-align', $html);
    }

    public function testVerticalAlignInvalidFallsBackToDefault(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'cover',
            'vertical_align' => 'middle',
        ]);
        $this->assertStringNotContainsString('data-pp-vertical-align', $html);
    }

    public function testVerticalAlignIgnoredOnCenteredVariant(): void
    {
        $html = $this->render([
            'title' => 'Test',
            'variant' => 'centered',
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
}
