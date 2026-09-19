<?php
/**
 * THE SHIPPED DEFAULTS, ASSERTED AS CSS RATHER THAN AS JSON (#1066).
 *
 * WHY THIS FILE EXISTS, and it is the same reason SectionRoleDefaultsEmitTest,
 * CtaRoleDefaultsEmitTest and FaqRoleDefaultsEmitTest exist one, two and three rebuilds
 * earlier. table's rebuild moved ~30 declarations out of the stylesheet and into eleven
 * role defaults, and every claim made about that move — "this is the value v1 rendered",
 * "this role declines to default", "the separator moved to the top edge", "these two
 * roles pin because the table stays light" — is otherwise pinned only against the
 * schema's JSON TEXT. That is one layer above the thing the claim is about.
 *
 * A schema key can be correct while the emission is wrong: an `@token` could stop
 * resolving, a state could emit at the wrong specificity, a role could silently emit
 * nothing because its selector changed. Every one of those passes a JSON-text assertion
 * and ships a visibly different band.
 *
 * WHAT THIS FILE IS NOT. It is not a golden-file snapshot. A test that asserts the exact
 * bytes of thirty declarations fails on every legitimate reordering and teaches the next
 * author to regenerate it without reading it. Each assertion here names ONE declaration
 * and says which claim it defends.
 *
 * FOUR CLAIMS HERE ARE ABSENCES, and they are the ones most worth pinning: the band
 * declares no fill and no border, the heading declares no weight/leading/tracking, the
 * cell declares no font-size, and `cell-link` declares nothing at all. An absence needs a
 * test more than a presence does, because nothing else in the suite can tell a deliberate
 * silence from a value someone forgot — and three of these four are silences that a
 * reader of the COMPUTED values alone would have filled in wrongly.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class TableRoleDefaultsEmitTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = pp_udc_component_defaults_css('table');
        $this->assertNotSame('', $this->css, 'table emitted no role defaults at all');
    }

    /** The base tier is everything before the first `@media`. */
    private function baseTier(): string
    {
        $at = strpos($this->css, '@media');
        return $at === false ? $this->css : substr($this->css, 0, $at);
    }

    /** One role's emitted block body, by selector, from the element tier. */
    private function roleBlock(string $selector): string
    {
        $quoted = preg_quote('[data-pp-component="table"] ' . $selector, '/');
        if (!preg_match('/' . $quoted . '\{([^}]*)\}/', $this->css, $m)) {
            $this->fail("no emitted block for role selector '{$selector}'");
        }
        return $m[1];
    }

    /**
     * THE BAND'S RHYTHM — AND ITS TWO ABSENCES, WHICH ARE THE INTERESTING HALF.
     *
     * faq's `_band` paints `@color-surface`; cta's draws a 1px rule top and bottom. table's
     * v1 band measured `rgba(0, 0, 0, 0)` with 0px/none on all four edges, so declaring
     * either would claim a decision v1 never made — and a fill in particular would paint a
     * surface onto every table band on every site that upgrades.
     *
     * `@pp-band-padding` is a fluid clamp, which is why the padding needs no breakpoint map
     * even though Chromium measured three different values (53.6 / 68 / 76.8px). A literal
     * here would have frozen the band at one tier — the mistake a reader of the computed
     * values alone would make.
     */
    public function testTheBandCarriesOnlyTheSharedRhythmAndAFocalPoint(): void
    {
        $base = $this->baseTier();
        $this->assertStringContainsString('padding-top:var(--pp-band-padding);', $base);
        $this->assertStringContainsString('padding-bottom:var(--pp-band-padding);', $base);
        $this->assertStringNotContainsString('padding-top:53.6px', $base, 'the fluid token must not be frozen to a tier');

        // SCOPED TO THE BAND'S OWN BLOCK. A whole-sheet search for "background" finds the
        // `table` and `head` role fills and proves nothing about the band.
        preg_match('/@layer pp-zero\{:where\(\[data-pp-component="table"\]\)\{([^}]*)\}/', $this->css, $band);
        $this->assertNotEmpty($band, 'the band tier did not emit');

        $this->assertStringNotContainsString(
            'background:',
            $band[1],
            "the band must declare no fill: v1's .table-section measured rgba(0, 0, 0, 0)"
        );
        foreach (['border-top-width', 'border-bottom-width', 'border-left-width', 'border-right-width', 'border-color', 'border-style'] as $prop) {
            $this->assertStringNotContainsString(
                $prop,
                $band[1],
                "the band must declare no {$prop}: v1's table band drew no border on any edge"
            );
        }

        // The focal point IS declared, and it is not dead: `background.image` is newly
        // authorable here, and without this an authored image would pin top-left (#1023).
        $this->assertStringContainsString('background-position:center;', $band[1]);

        // And the tier it rides is load-bearing for the shared adjacent-band rhythm.
        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="table"\]\)\{[^}]*padding-top:var\(--pp-band-padding\);/',
            $this->css,
            'the band tier must ride pp-zero, or it would defeat the #430/#431 adjacent rhythm'
        );
    }

    /**
     * THE HEADING FOLLOWS THE BAND, AND DECLARES NONE OF BASE.CSS'S TYPE.
     *
     * `currentColor` is the #1046 ruling applied to a component that could not make a dark
     * band at all on v1 — so the pinned `@color-text` literal it declared was identical to
     * the inherited value on every band v1 could express, and diverges only on the band
     * shape v2 newly makes easy. Shipping the literal is the cta defect (#1026, 1.016:1).
     *
     * The five absences are the GLOBAL-VALUE rule. faq declared weight and leading
     * because its values came from rules inside media queries; table has no such rules, so
     * restating base.css's `h1`-`h6` values here would move them to the unlayered tier and
     * beat that rule in every state.
     */
    public function testTheHeadingFollowsTheBandAndRestatesNoGlobalType(): void
    {
        $block = $this->roleBlock('.table-section__heading');

        $this->assertStringContainsString('color:currentColor;', $block, 'the heading must follow the band');
        $this->assertStringNotContainsString(
            'color:var(--color-text)',
            $block,
            'pinning @color-text is the #1026 defect: it strands the heading on an authored dark band'
        );
        $this->assertStringContainsString('font-size:var(--pp-band-heading-size);', $block);
        $this->assertStringContainsString('margin-bottom:var(--space-lg);', $block);
        $this->assertStringContainsString('max-width:var(--measure-heading);', $block);

        foreach (['font-weight', 'line-height', 'letter-spacing', 'font-family', 'text-wrap'] as $global) {
            $this->assertStringNotContainsString(
                $global,
                $block,
                "{$global} renders from base.css's h1-h6 rule; restating it here moves it to the unlayered tier"
            );
        }
        // And no alignment: measured `start` with margin-left 0 at every tier, which is
        // the initial value rather than a declaration. stats is the centred one.
        $this->assertStringNotContainsString('text-align', $block, 'this heading is left-pinned and always was');
        $this->assertStringNotContainsString('margin-left', $block);
    }

    /**
     * THE SEPARATOR IS ON THE TOP EDGE, WITH ITS STYLE, AND NOTHING ON THE BOTTOM.
     *
     * This is the rebuild's one real mechanism change, and all three halves need pinning:
     *
     *  - TOP, not bottom. v1 drew `border-bottom` and suppressed it on `:last-child`. A
     *    `:last-child` suppression cannot survive, because the base separator becomes an
     *    in-band ELEMENT default which emits UNLAYERED while the suppression would sit in
     *    `pp-v1` — unlayered beats every layer at any specificity, so it would stop
     *    working. Under `border-collapse: collapse` a top-edge separator merges into the
     *    header's heavier bottom rule and the last row simply has no bottom edge.
     *  - STYLE IS LOAD-BEARING. `border-style`'s initial is `none`, the LOWEST priority in
     *    the collapse model, so a width and a colour with no style paint NOTHING. This is
     *    the assertion that would catch someone "tidying" the style away as redundant.
     *  - PER-EDGE COLOUR. The shorthand would colour all four edges; only the top has a
     *    width, so the shorthand renders identically today and says something it does not
     *    mean — and it would silently colour a bottom edge an author later adds.
     */
    public function testTheRowSeparatorMovedToTheTopEdgeWithItsStyle(): void
    {
        $block = $this->roleBlock('.table__row');

        $this->assertStringContainsString('border-top-width:1px;', $block);
        $this->assertStringContainsString(
            'border-top-style:solid;',
            $block,
            'without a style the collapsed model treats the edge as `none` and paints nothing'
        );
        $this->assertStringContainsString('border-top-color:var(--color-border);', $block);

        $this->assertStringNotContainsString(
            'border-bottom',
            $block,
            'the separator moved to the top edge; a bottom edge here would double every rule'
        );
        // The shorthand, not merely the bottom longhand: `border-color` would reach the
        // bottom edge too.
        $this->assertStringNotContainsString(
            'border-color:',
            $block,
            'per-edge colour, so the default cannot colour an edge it does not draw'
        );
    }

    /** The hover tint is a real measured v1 state, emitted heavier than the resting row. */
    public function testTheRowHoverTintEmitsAndOutranksTheRestingRow(): void
    {
        $hover = $this->roleBlock('.table__row:hover');
        $this->assertStringContainsString('background:var(--color-surface);', $hover);

        // Source order AND specificity both favour it; assert the order, because the
        // specificity is only one class apart and a reordering would be silent.
        $restAt  = strpos($this->css, '[data-pp-component="table"] .table__row{');
        $hoverAt = strpos($this->css, '[data-pp-component="table"] .table__row:hover{');
        $this->assertNotFalse($restAt);
        $this->assertNotFalse($hoverAt);
        $this->assertGreaterThan($restAt, $hoverAt, 'the hover state must print after the resting row');
    }

    /**
     * THE TWO ROLES THAT PIN BECAUSE THE TABLE STAYS LIGHT.
     *
     * `table` fills `@color-bg` and `head` fills `@color-surface`, and both stay light when
     * an author darkens the band — the same choice faq makes for its items. That is why
     * `cell` and `header` pin `@color-text` instead of following the band, and it is the
     * opposite call from the heading three tests up. Getting this wrong in either
     * direction ships unreadable text: follow the band and the cells go light on a light
     * table; drop the fills and the pinned ink loses its surface.
     */
    public function testTheTableSurfacesStayLightAndTheirInkPins(): void
    {
        $this->assertStringContainsString('background:var(--color-bg);', $this->roleBlock('.table'));
        $this->assertStringContainsString('background:var(--color-surface);', $this->roleBlock('.table__head'));

        foreach (['.table__cell', '.table__header'] as $selector) {
            $this->assertStringContainsString(
                'color:var(--color-text);',
                $this->roleBlock($selector),
                "{$selector} sits on the table's own light fill, so its ink must pin rather than follow the band"
            );
            $this->assertStringNotContainsString(
                'color:currentColor',
                $this->roleBlock($selector),
                "{$selector} must NOT follow the band: the surface under it stays light"
            );
        }
    }

    /**
     * THE TWO ROLES ON THE BAND FILL, WHICH A DARK WRITE STRANDS.
     *
     * `caption` is the one that is easy to miss, and the reason is structural rather than
     * visual: a caption box renders OUTSIDE the table's background box (CSS 2.1 §17.4), so
     * `table`'s `@color-bg` never paints behind it. It groups with `empty`, not with
     * `cell`. Both pin `@color-muted`, which measures about 3.1:1 on an authored
     * `@color-bg-inverted` band — under the 4.5:1 floor.
     */
    public function testTheCaptionAndTheEmptyLineSitOnTheBandFillAndPinTheSameGrey(): void
    {
        foreach (['.table__caption', '.table-section__empty'] as $selector) {
            $this->assertStringContainsString(
                'color:var(--color-muted);',
                $this->roleBlock($selector),
                "{$selector} renders on the BAND fill, so its grey is a role default a dark band must override"
            );
        }

        // The caption's alignment is a real declaration defeating a UA default, not a
        // reset: the HTML rendering spec centres a <caption>.
        $this->assertStringContainsString(
            'text-align:left;',
            $this->roleBlock('.table__caption'),
            'dropping this centres every caption on every site'
        );

        // The empty line's padding is table's own value: base.css zeroes every element's
        // padding, so dropping it closes the line up against the heading.
        $empty = $this->roleBlock('.table-section__empty');
        $this->assertStringContainsString('padding-top:var(--space-md);', $empty);
        $this->assertStringContainsString('padding-bottom:var(--space-md);', $empty);
    }

    /**
     * THE HEADER'S TWO UA OVERRIDES, WHICH ARE NOT RESETS.
     *
     * The HTML rendering spec gives `th` a CENTRED, BOLD presentation. Both declarations
     * are table's own rules overriding it. The global-value rule does not reach them: that
     * rule is about the THEME's own layered rules changing tier, and a UA default lives in
     * an origin that loses to any author declaration whatever layer it sits in.
     */
    public function testTheHeaderOverridesTheBrowsersCentredBoldDefault(): void
    {
        $block = $this->roleBlock('.table__header');
        $this->assertStringContainsString('text-align:left;', $block, 'dropping this centres every column header');
        $this->assertStringContainsString('font-weight:700;', $block, 'the theme states its own weight rather than inheriting the UA bold');
        $this->assertStringContainsString('border-bottom-width:2px;', $block);
        $this->assertStringContainsString('border-bottom-style:solid;', $block);
        $this->assertStringContainsString('border-bottom-color:var(--color-border);', $block);
    }

    /**
     * THE CELL DECLARES NO SIZE AND NO LEADING, FOR TWO DIFFERENT REASONS.
     *
     * The 15px it renders is INHERITED from the `table` role's own `typography.size`, so
     * declaring it here would pin a value the parent owns and silently break
     * `table` -> `typography.size` for every cell. The 1.6 leading is base.css's body rule
     * — a global value. A reader of the computed styles alone would have added both.
     */
    public function testTheCellInheritsItsSizeFromTheTableAndItsLeadingFromBaseCss(): void
    {
        $block = $this->roleBlock('.table__cell');
        $this->assertStringNotContainsString(
            'font-size',
            $block,
            'the cell inherits the table role size; pinning it breaks table -> typography.size'
        );
        $this->assertStringNotContainsString('line-height', $block, 'the leading is base.css body copy, a global value');
        $this->assertStringContainsString('font-size:0.9375rem;', $this->roleBlock('.table'), 'and the parent is where it lives');
    }

    /**
     * `cell-link` EMITS NOTHING, AND THAT IS THE ROLE WORKING.
     *
     * The empty defaults block is the difference between rule 2 forbidding a DEFAULT and
     * forbidding a ROLE. With a default, base.css's anchor values would emit unlayered and
     * outrank the premium button rules for an author-written `<a class="btn">` in a cell
     * — the #545 defect through a role selector. With none, the role costs zero cascade
     * movement at rest and still gives an author an ADDRESS, which is the thing a dark
     * table surface needs (measured on faq: a container role's ink cannot reach a link,
     * because base.css's `a` rule is a direct declaration — #1069).
     *
     * The role must still EXIST, so both halves are asserted: nothing emitted, and the
     * role declared with its groups.
     */
    public function testTheCellLinkRoleEmitsNothingButStillExists(): void
    {
        $this->assertStringNotContainsString(
            '.table__cell a{',
            $this->css,
            'a cell-link DEFAULT would outrank the premium button rules for an author-written .btn'
        );

        $roles = pp_udc_component_roles('table');
        $this->assertArrayHasKey('cell-link', $roles, 'the role must exist even though it defaults nothing');
        $this->assertSame('.table__cell a', $roles['cell-link']['selector']);
        $this->assertSame([], $roles['cell-link']['defaults'] ?? null, 'and it must default nothing');
        $this->assertContains('typography', $roles['cell-link']['groups'], 'or an author could not colour a link at all');
    }

    /**
     * NO ROLE RESTATES A GLOBAL VALUE ANYWHERE IN THE SHEET.
     *
     * The per-role tests above each guard their own block. This is the whole-sheet
     * backstop for the one failure mode they cannot see between them: a value added to a
     * role nobody thought to check. Every one of these renders from base.css or from a
     * parent role, and every one of them would be a silent cascade change.
     */
    public function testNoRoleRestatesAValueThatComesFromBaseCss(): void
    {
        foreach (['text-wrap', 'font-family', 'letter-spacing'] as $global) {
            $this->assertStringNotContainsString(
                $global,
                $this->css,
                "{$global} comes from base.css for every element table renders; no role may restate it"
            );
        }
        // The anchor pair, which is the #545 shape.
        $this->assertStringNotContainsString('text-decoration', $this->css, 'the underline is base.css\'s anchor rule');
    }
}
