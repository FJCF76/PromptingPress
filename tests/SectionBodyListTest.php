<?php
/**
 * tests/SectionBodyListTest.php
 *
 * Section body list rendering (issue 295): lists authored in section.body must
 * render with markers + indent. The global reset (base.css *{padding:0} +
 * ul,ol{list-style:none}) strips both, so components.css must re-declare marker,
 * indent, and rhythm scoped to .section__content — the surface where the body
 * HTML (wp_kses_post($body)) actually lands (section.php).
 *
 * These are CSS-content pins (same approach as TypographyRoleTest): PHPUnit does
 * not execute CSS, so we assert the source declares the restore rules rather than
 * a computed style. The rendered-cascade half is covered by the section renderer
 * putting body HTML inside .section__content, asserted here too.
 */

use PHPUnit\Framework\TestCase;

class SectionBodyListTest extends TestCase
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

    // ── The defect: base reset strips list rendering ──────────────────────

    public function testBaseCssResetStripsListMarkers(): void
    {
        // Documents the cause: the global reset the fix must override.
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        $this->assertMatchesRegularExpression(
            '/ul,\s*\n?\s*ol\s*\{\s*list-style:\s*none/',
            $base,
            'base.css reset (ul,ol{list-style:none}) is the cause issue 295 fixes; if it changes, revisit the section list restore.'
        );
    }

    // ── The fix: markers restored, scoped to the rich-text surface ────────

    public function testSectionContentRestoresUnorderedMarkers(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__content ul\b/',
            $this->componentsCss,
            'components.css must scope a list rule to .section__content ul.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__content ul\s*\{[^}]*list-style:\s*disc/s',
            $this->componentsCss,
            '.section__content ul must restore disc markers (issue 295).'
        );
    }

    public function testSectionContentRestoresOrderedMarkers(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__content ol\s*\{[^}]*list-style:\s*decimal/s',
            $this->componentsCss,
            '.section__content ol must restore decimal markers (issue 295).'
        );
    }

    public function testSectionContentRestoresIndentViaSpacingToken(): void
    {
        // Indent must come back (marker room) and use a spacing token, not a literal.
        $this->assertMatchesRegularExpression(
            '/\.section__content ul,\s*\n?\s*\.section__content ol\s*\{[^}]*padding-left:\s*var\(--space-/s',
            $this->componentsCss,
            '.section__content ul/ol must restore padding-left through a var(--space-*) token (issue 295).'
        );
    }

    public function testSectionContentRestoresListItemRhythm(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__content li\s*\{[^}]*margin-bottom:\s*var\(--space-/s',
            $this->componentsCss,
            '.section__content li must carry token-based vertical rhythm (issue 295).'
        );
    }

    // ── The anchor: body HTML actually renders inside .section__content ───

    public function testSectionBodyRendersInsideSectionContent(): void
    {
        $html = $this->render('section', [
            'layout' => 'text-only',
            'title'  => 'Why us',
            'body'   => '<ul><li>First</li><li>Second</li></ul>',
        ]);
        $this->assertStringContainsString('section__content', $html,
            'section must wrap body HTML in .section__content so the issue 295 list rule applies.');
        // The <ul> the fix targets must survive into the content surface.
        $this->assertMatchesRegularExpression(
            '/section__content[^>]*>.*<ul>.*<li>First<\/li>/s',
            $html,
            'authored <ul> in section.body must render inside .section__content.'
        );
    }
}
