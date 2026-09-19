<?php
/**
 * THE SHIPPED DEFAULTS, ASSERTED AS CSS RATHER THAN AS JSON (#1066).
 *
 * WHY THIS FILE EXISTS, and it is the same reason SectionRoleDefaultsEmitTest,
 * CtaRoleDefaultsEmitTest, FaqRoleDefaultsEmitTest and TableRoleDefaultsEmitTest exist.
 * embed's rebuild moved every declaration it had out of the stylesheet, and every claim
 * made about that move is otherwise pinned only against the schema's JSON TEXT — one
 * layer above the thing the claim is about.
 *
 * embed IS THE SMALLEST EMISSION IN THE THEME AND THE ONE MOST MADE OF ABSENCES, which
 * inverts the usual balance of one of these files. Three roles emit eight declarations
 * between them; a fourth emits nothing at all. Four separate claims here are silences:
 *
 *   - the band declares no fill and no border        (v1's unthemed band painted nothing)
 *   - the heading declares no weight/leading/tracking (base.css's h1-h6 rule)
 *   - `content` declares NO COLOUR                    (the fourth condition -> silence)
 *   - `content-link` declares nothing at all          (rule 2 forbids a default, not a role)
 *
 * The third is the subtle one and the reason this file is worth its length. v1 declared
 * `color: var(--embed-body-color, inherit)` — an explicit `inherit`, which IS a
 * declaration, and the #1026 fourth condition exists precisely because declaring nothing
 * is not the same as declaring `inherit`. It resolves to SILENCE here rather than to
 * `currentColor` for one measurable reason: `.embed__content` is a <div>, and no rule in
 * this theme declares `color` on a bare div, so an inherited `_band` value already lands.
 * cta's heading needed `currentColor` because base.css's h1-h6 rule MATCHES an <h2>
 * directly. Same rule, opposite answer, because the elements differ.
 *
 * WHAT THIS FILE IS NOT. It is not a golden-file snapshot. Each assertion names ONE
 * declaration (or one absence) and says which claim it defends.
 */

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class EmbedRoleDefaultsEmitTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = pp_udc_component_defaults_css('embed');
        $this->assertNotSame('', $this->css, 'embed emitted no role defaults at all');
    }

    /** One role's emitted block body, by selector, from the element tier. */
    private function roleBlock(string $selector): string
    {
        $quoted = preg_quote('[data-pp-component="embed"] ' . $selector, '/');
        if (!preg_match('/' . $quoted . '\{([^}]*)\}/', $this->css, $m)) {
            $this->fail("no emitted block for role selector '{$selector}'");
        }
        return $m[1];
    }

    /**
     * THE BAND'S RHYTHM — AND ITS TWO ABSENCES.
     *
     * faq's `_band` paints `@color-surface`; cta's draws a 1px rule top and bottom.
     * embed's v1 band measured `rgba(0, 0, 0, 0)` with 0px/none on all four edges — the
     * framing belonged to `.embed--dark`, the retired `theme: "muted"` variant — so
     * declaring either would claim a decision v1 never made, and a fill would paint a
     * surface onto every embed band on every site that upgrades.
     *
     * `@pp-band-padding` is a fluid clamp, which is why the padding needs no breakpoint
     * map even though Chromium measured three different values (53.6 / 68 / 76.8px).
     */
    public function testTheBandCarriesOnlyTheSharedRhythmAndAFocalPoint(): void
    {
        preg_match('/@layer pp-zero\{:where\(\[data-pp-component="embed"\]\)\{([^}]*)\}/', $this->css, $band);
        $this->assertNotEmpty($band, 'the band tier did not emit');

        $this->assertStringContainsString('padding-top:var(--pp-band-padding);', $band[1]);
        $this->assertStringContainsString('padding-bottom:var(--pp-band-padding);', $band[1]);
        $this->assertStringNotContainsString('padding-top:53.6px', $band[1], 'the fluid token must not be frozen to a tier');

        $this->assertStringNotContainsString(
            'background:',
            $band[1],
            "the band must declare no fill: v1's unthemed .embed measured rgba(0, 0, 0, 0)"
        );
        foreach (['border-top-width', 'border-bottom-width', 'border-left-width', 'border-right-width', 'border-color', 'border-style'] as $prop) {
            $this->assertStringNotContainsString(
                $prop,
                $band[1],
                "the band must declare no {$prop}: the framing belonged to the retired `muted` variant"
            );
        }

        // The focal point IS declared and is not dead: `background.image` is newly
        // authorable here, and without this an authored image would pin top-left (#1023).
        $this->assertStringContainsString('background-position:center;', $band[1]);

        // The tier it rides is load-bearing for the shared adjacent-band rhythm.
        $this->assertMatchesRegularExpression(
            '/@layer pp-zero\{:where\(\[data-pp-component="embed"\]\)\{[^}]*padding-top:var\(--pp-band-padding\);/',
            $this->css,
            'the band tier must ride pp-zero, or it would defeat the #430/#431 adjacent rhythm'
        );
    }

    /**
     * THE HEADING FOLLOWS THE BAND, AND DECLARES NONE OF BASE.CSS'S TYPE.
     *
     * v1's rule was a pinned literal with a `.embed--inverted` twin; the only dark embed
     * band v1 could render came from that retired prop, so `@color-text` is identical to
     * `currentColor` on every band v1 could express and diverges only where v1 was
     * inexpressible — at 1.006:1, the defect #1026 shipped and corrected.
     */
    public function testTheHeadingFollowsTheBandAndRestatesNoGlobalType(): void
    {
        $block = $this->roleBlock('.embed__heading');

        $this->assertStringContainsString('color:currentColor;', $block, 'the heading must follow the band');
        $this->assertStringNotContainsString(
            'color:var(--color-text)',
            $block,
            'pinning @color-text is the #1026 defect: it strands the heading on an authored dark band'
        );
        $this->assertStringNotContainsString(
            'color:var(--color-bg)',
            $block,
            'and pinning the INVERTED literal would strand it on every light band instead'
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
        $this->assertStringNotContainsString('text-align', $block, 'measured `start`, which is the initial value');
    }

    /**
     * THE CONTENT COLUMN KEEPS ITS OWN MEASURE AND DECLARES NO INK.
     *
     * TWO CLAIMS, AND THE SECOND IS THE ONE THIS WHOLE FILE EXISTS FOR.
     *
     * The measure is `40rem` as a LITERAL, deliberately not `@measure-heading`. Both
     * resolve to 640px today, so a "consistency" pass folding one into the other would be
     * invisible until the next heading-scale retune re-flowed every embedded form. #578
     * separated them on purpose. Asserting the literal is what makes that survivable.
     *
     * The absent colour is the fourth condition resolving to silence. v1 declared an
     * explicit `inherit`, which IS a declaration — but nothing in this theme matches a
     * bare <div> for `color`, so silence is byte-identical and is the smaller statement.
     * A `currentColor` here would also be correct and would be a no-op declaration; a
     * LITERAL would strand the content on a dark band, and that is what this guards.
     */
    public function testTheContentKeepsItsOwnMeasureAndDeclaresNoInk(): void
    {
        $block = $this->roleBlock('.embed__content');

        $this->assertStringContainsString('max-width:40rem;', $block, 'the body measure keeps its own literal');
        $this->assertStringNotContainsString(
            'max-width:var(--measure-heading)',
            $block,
            'routing the heading token here re-flows embedded content on the next heading-scale retune (#578)'
        );

        $this->assertStringNotContainsString(
            'color:',
            $block,
            'v1 declared an explicit `inherit`, and no rule matches a bare <div> for colour — '
            . 'so silence is byte-identical, and any literal here would strand the content on a dark band'
        );
        $this->assertStringNotContainsString('font-size', $block, "the 16px is base.css's body rule, a global value");
        $this->assertStringNotContainsString('line-height', $block, "the 1.6 is base.css's body rule too");
    }

    /**
     * `content-link` EMITS NOTHING, AND THAT IS THE ROLE WORKING.
     *
     * The empty defaults block is the difference between rule 2 forbidding a DEFAULT and
     * forbidding a ROLE. With a default, base.css's anchor values would emit unlayered and
     * outrank the premium button rules for an author-written `<a class="btn">` — and on
     * THIS component that is measured rather than theoretical, because `content` goes
     * through wp_kses_post(), which admits `class`.
     *
     * With none, the role costs zero cascade movement at rest and still gives an author an
     * ADDRESS — which is what replaces `.embed--inverted a` (#437), the automatic dark-band
     * remap that retires with the `theme` class. Without this role the capability would be
     * deleted outright: a container role's ink reaches a link only by INHERITANCE, and
     * base.css's `a` rule is a direct declaration on the element (measured, #1069).
     *
     * Both halves are asserted: nothing emitted, and the role declared with its groups.
     */
    public function testTheContentLinkRoleEmitsNothingButStillExists(): void
    {
        $this->assertStringNotContainsString(
            '.embed__content a{',
            $this->css,
            'a content-link DEFAULT would outrank the premium button rules for an author-written .btn'
        );

        $roles = pp_udc_component_roles('embed');
        $this->assertArrayHasKey('content-link', $roles, 'the role must exist even though it defaults nothing');
        $this->assertSame('.embed__content a', $roles['content-link']['selector']);
        $this->assertSame([], $roles['content-link']['defaults'] ?? null, 'and it must default nothing');
        $this->assertContains(
            'typography',
            $roles['content-link']['groups'],
            'or the #437 capability really would be deleted rather than re-addressed'
        );
    }

    /**
     * THE WHOLE EMISSION IS EIGHT DECLARATIONS, AND THE COUNT IS THE CLAIM.
     *
     * embed's rebuild is the one that empties a stylesheet block entirely, so the risk
     * here is the opposite of every other component's: not a value left behind in CSS,
     * but a value invented in the schema to fill the space. A count is the cheapest guard
     * against that, and it is asserted as a RANGE rather than a number so a legitimate
     * reordering or a whitespace change does not fail it while a new role default does.
     */
    public function testTheEmissionStaysAsSmallAsTheComponentIs(): void
    {
        $declarations = substr_count($this->css, ';');
        $this->assertSame(
            8,
            $declarations,
            'embed emits exactly eight declarations: three on the band, four on the heading, '
            . 'one on the content — a change here means a role gained or lost a default, '
            . 'which is a decision that belongs in the schema with a stated reason'
        );

        // And exactly three selectors emit at all.
        $this->assertSame(1, substr_count($this->css, '@layer pp-zero{'), 'one band tier');
        $this->assertSame(1, substr_count($this->css, '.embed__heading{'), 'one heading block');
        $this->assertSame(1, substr_count($this->css, '.embed__content{'), 'one content block');
        $this->assertSame(0, substr_count($this->css, '.embed__content a{'), 'and no link block');
    }

    /** No role restates a value that comes from base.css. The whole-sheet backstop. */
    public function testNoRoleRestatesAValueThatComesFromBaseCss(): void
    {
        foreach (['text-wrap', 'font-family', 'letter-spacing', 'text-decoration'] as $global) {
            $this->assertStringNotContainsString(
                $global,
                $this->css,
                "{$global} comes from base.css for every element embed renders; no role may restate it"
            );
        }
    }
}
