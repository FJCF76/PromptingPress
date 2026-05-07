<?php
/**
 * tests/ComponentPropsTest.php — PHPUnit tests for spacing, width, and centered props
 *
 * Covers: data-pp-spacing, data-pp-width attributes across section-level components,
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

    // ── Spacing Prop ───────────────────────────────────────────────────────

    public function testSectionSpacingNonDefaultOutputsAttribute(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'spacing' => 'compact',
        ]);
        $this->assertStringContainsString('data-pp-spacing="compact"', $html);
    }

    public function testSectionSpacingDefaultOmitsAttribute(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'spacing' => 'default',
        ]);
        $this->assertStringNotContainsString('data-pp-spacing', $html);
    }

    public function testSectionSpacingInvalidFallsBackToDefault(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'spacing' => 'huge',
        ]);
        $this->assertStringNotContainsString('data-pp-spacing', $html);
    }

    // ── Width Prop ─────────────────────────────────────────────────────────

    public function testSectionWidthNonDefaultOutputsAttribute(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'width' => 'narrow',
        ]);
        $this->assertStringContainsString('data-pp-width="narrow"', $html);
    }

    public function testSectionWidthDefaultOmitsAttribute(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'width' => 'default',
        ]);
        $this->assertStringNotContainsString('data-pp-width', $html);
    }

    public function testSectionWidthInvalidFallsBackToDefault(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'width' => 'extra-wide',
        ]);
        $this->assertStringNotContainsString('data-pp-width', $html);
    }

    // ── Spacing on other components ────────────────────────────────────────

    public function testHeroSpacingOutputsAttribute(): void
    {
        $html = $this->render('hero', [
            'title' => 'Test Hero',
            'spacing' => 'spacious',
        ]);
        $this->assertStringContainsString('data-pp-spacing="spacious"', $html);
    }

    public function testCtaSpacingOutputsAttribute(): void
    {
        $html = $this->render('cta', [
            'title' => 'Test CTA',
            'button_text' => 'Click',
            'button_url' => '#',
            'spacing' => 'compact',
        ]);
        $this->assertStringContainsString('data-pp-spacing="compact"', $html);
    }

    // ── Width on other components ──────────────────────────────────────────

    public function testGridWidthOutputsAttribute(): void
    {
        $html = $this->render('grid', [
            'items' => [['title' => 'Card 1', 'text' => 'Text']],
            'width' => 'full',
        ]);
        $this->assertStringContainsString('data-pp-width="full"', $html);
    }

    public function testStatsWidthOutputsAttribute(): void
    {
        $html = $this->render('stats', [
            'items' => [['number' => '100', 'label' => 'Users']],
            'width' => 'narrow',
        ]);
        $this->assertStringContainsString('data-pp-width="narrow"', $html);
    }

    public function testEmbedWidthOutputsAttribute(): void
    {
        $html = $this->render('embed', [
            'content' => '<p>Embedded content</p>',
            'width' => 'narrow',
        ]);
        $this->assertStringContainsString('data-pp-width="narrow"', $html);
    }

    // ── Logos spacing/width ───────────────────────────────────────────────────

    public function testLogosSpacingOutputsAttribute(): void
    {
        $html = $this->render('logos', [
            'items' => [['image_url' => 'https://example.com/logo.png', 'image_alt' => 'Logo']],
            'spacing' => 'compact',
        ]);
        $this->assertStringContainsString('data-pp-spacing="compact"', $html);
    }

    public function testLogosWidthOutputsAttribute(): void
    {
        $html = $this->render('logos', [
            'items' => [['image_url' => 'https://example.com/logo.png', 'image_alt' => 'Logo']],
            'width' => 'full',
        ]);
        $this->assertStringContainsString('data-pp-width="full"', $html);
    }

    // ── Centered + spacing/width combo ────────────────────────────────────────

    public function testSectionCenteredWithSpacingOutputsBothAttributes(): void
    {
        $html = $this->render('section', [
            'body' => '<p>Content</p>',
            'layout' => 'centered',
            'spacing' => 'spacious',
        ]);
        $this->assertStringContainsString('section--centered', $html);
        $this->assertStringContainsString('data-pp-spacing="spacious"', $html);
    }

    // ── Invalid-value fallback on non-section component ───────────────────────

    public function testHeroSpacingInvalidFallsBackToDefault(): void
    {
        $html = $this->render('hero', [
            'title' => 'Test Hero',
            'spacing' => 'huge',
        ]);
        $this->assertStringNotContainsString('data-pp-spacing', $html);
    }

    public function testGridWidthInvalidFallsBackToDefault(): void
    {
        $html = $this->render('grid', [
            'items' => [['title' => 'Card 1', 'text' => 'Text']],
            'width' => 'extra-wide',
        ]);
        $this->assertStringNotContainsString('data-pp-width', $html);
    }
}
