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
        //
        // The selector is `:is(ul, ol)` since #1023, not a comma pair — a ROLE selector is
        // scoped by prefixing `[data-pp-band="…"]`, and a top-level comma would leave the
        // second half UNSCOPED and paint every band on the page. This rule is not a role
        // (it lives in the shared glyph-and-prose block, because restoring markers to
        // authored HTML is normalize rather than per-band design), but keeping one
        // selector is the habit that makes the mistake impossible to make by copying.
        $this->assertMatchesRegularExpression(
            '/\.section__content :is\(ul, ol\)\s*\{[^}]*padding-left:\s*var\(--space-/s',
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

    /**
     * THE `body-link` ROLE DECLARES NO DEFAULTS, and the absence is load-bearing (#1023).
     *
     * FOUND BY A RENDERED PIN, not by reading the schema. The role's first cut declared
     * `typography.color: @color-accent`, `decoration: underline` and a `:hover` — which is
     * byte-identical to what `base.css` already gives every `a`. Identical in VALUE, and
     * different in CASCADE POSITION: a role block is emitted UNLAYERED, so it outranked the
     * shared premium `main .btn:not(...)` rule — and an author-written `<a class="btn">`
     * inside `body` is an anchor too. The measured result was a nested author button
     * rendering its label rgb(49,87,244) on the premium blue gradient: accent-on-accent,
     * effectively invisible. That is the #545 nested-author-button defect, reintroduced
     * through a role selector.
     *
     * It cannot be fixed by narrowing the selector: the role-selector gate in lib/udc.php
     * admits `[A-Za-z0-9_ .>-]` only, so `:not(.btn)` is not expressible — and widening a
     * gate that guards a string interpolated into CSS to work around one component's
     * default would be the wrong trade.
     *
     * Leaving the role silent restores v1 exactly. Measured after the change: the prose
     * link is unchanged (accent + underline) and the nested button is rgb(252,253,255) on
     * the gradient, identical to the panel CTA beside it.
     *
     * An AUTHOR who sets a colour here still outranks both — which a dark band needs, and
     * which is also what v1 did on its inverted bands. Declining a DEFAULT is not
     * withholding the surface.
     */
    public function testTheBodyLinkRoleDeclinesToDefaultWhatBaseCssAlreadyGives(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $role   = $schema['roles']['body-link'];

        $this->assertSame(
            [],
            $role['defaults'] ?? [],
            'the `body-link` role must declare NO defaults: base.css already gives every anchor '
            . 'the accent colour, an underline and an accent hover, so a default here changes only '
            . 'the cascade position — and unlayered it repaints author-written .btn anchors in the body.'
        );

        // …but the surface stays open, or a dark band could not recolour its links at all.
        $this->assertContains(
            'typography',
            $role['groups'],
            'an author must still be able to colour prose links; only the DEFAULT is declined'
        );

        // The values the role would have pinned are the ones base.css ships, which is why
        // pinning them was redundant. If base.css ever stops shipping them, this test fails
        // and the role's silence has to be re-argued rather than silently becoming a gap.
        $base = file_get_contents($this->themeRoot . '/assets/css/base.css');
        $this->assertMatchesRegularExpression(
            '/\ba\s*\{[^}]*color:\s*var\(--color-accent\)/s',
            $base,
            'base.css must still give every anchor the accent colour — the role relies on it'
        );
        $this->assertMatchesRegularExpression(
            '/\ba:hover\s*\{[^}]*color:\s*var\(--color-accent-hover\)/s',
            $base,
            'base.css must still give every anchor an accent hover — the role relies on it'
        );
        // THE THIRD FACT, added in review. The docblock above names three values the first
        // cut of this role declared — colour, DECORATION and hover — and only two were
        // pinned, so dropping `text-decoration: underline` from base.css would have removed
        // the underline from every section prose link with this test still green. The point
        // of the pin is that the role's silence is only safe while base.css carries ALL of
        // what the role declined to declare.
        $this->assertMatchesRegularExpression(
            '/\ba\s*\{[^}]*text-decoration:\s*underline/s',
            $base,
            'base.css must still underline every anchor — the role relies on it'
        );
    }
}
