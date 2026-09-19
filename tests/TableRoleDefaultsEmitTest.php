<?php
/**
 * THE SHIPPED DEFAULTS, ASSERTED AS CSS RATHER THAN AS JSON (#1066).
 *
 * WHY THIS FILE EXISTS, and it is the same reason FaqRoleDefaultsEmitTest,
 * CtaRoleDefaultsEmitTest and SectionRoleDefaultsEmitTest exist one, two and three rebuilds
 * earlier (the order is section #1023 -> cta #1026 -> faq #1046 -> table #1066, so faq is
 * the nearest and section the furthest). table's rebuild moved ~30 declarations out of
 * the stylesheet and into eleven roles, ten of which carry defaults (`cell-link`
 * deliberately carries none), and every claim made about that move — "this is the value v1 rendered",
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
        // SCOPED TO THE BAND'S OWN BLOCK, INCLUDING THE PADDING. A whole-sheet search
        // finds every role's declarations and proves nothing about the band: `background`
        // would match the `table` and `head` fills, and `padding-bottom` would match the
        // `caption`, `header`, `cell` and `empty` roles, all of which declare one. The
        // padding pair was asserted against the unscoped base tier until the #1066 review
        // caught it — in this very method, four lines above the comment saying why that is
        // wrong. EmbedRoleDefaultsEmitTest had it right; this now matches it.
        preg_match('/@layer pp-zero\{:where\(\[data-pp-component="table"\]\)\{([^}]*)\}/', $this->css, $band);
        $this->assertNotEmpty($band, 'the band tier did not emit');

        $this->assertStringContainsString('padding-top:var(--pp-band-padding);', $band[1]);
        $this->assertStringContainsString('padding-bottom:var(--pp-band-padding);', $band[1]);
        $this->assertStringNotContainsString('padding-top:53.6px', $band[1], 'the fluid token must not be frozen to a tier');

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
     * THE FRAME, WHICH IS THE ONE EDGE A READER ACTUALLY SEES.
     *
     * `.table-wrap` gave up exactly two declarations to this role — `border: 1px solid
     * var(--color-border)` and `border-radius: var(--radius)` — and they were the only
     * DESIGN values that block ever carried. Everything left in the stylesheet there is
     * the scroll affordance, which is why the schema calls the border "the frame" and the
     * scrolling "structural" in the same sentence.
     *
     * THE SHORTHAND PARAMS ARE CORRECT HERE, and that is the opposite call from `row` two
     * tests down: the frame draws on all four edges, so `border.width` / `style` / `color`
     * are what v1 declared. Per-edge longhands would be four times the schema for the same
     * paint. The style half is load-bearing for the same reason it is on `row` —
     * `border-style`'s initial value is `none`, so a width and a colour alone paint
     * nothing.
     *
     * AND THE SHELL DECLARES NO FILL: measured transparent, so the `table` role's own
     * `@color-bg` island is what shows inside the frame. A fill here would sit between the
     * two and change nothing visible today, which is exactly why an absence needs a test.
     */
    public function testTheScrollShellCarriesTheFrameAndNoFillOfItsOwn(): void
    {
        $block = $this->roleBlock('.table-wrap');

        $this->assertStringContainsString('border-width:1px;', $block);
        $this->assertStringContainsString(
            'border-style:solid;',
            $block,
            'without a style the width and the colour paint nothing — `border-style` initialises to `none`'
        );
        $this->assertStringContainsString('border-color:var(--color-border);', $block);
        $this->assertStringContainsString(
            'border-radius:var(--radius);',
            $block,
            'the radius routes the global token, so one update_design_token write still reaches the frame'
        );

        $this->assertStringNotContainsString(
            'background',
            $block,
            "the shell measured transparent: the `table` role's own fill is what shows through the frame"
        );
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
     * THE THREE PADDED CELL SURFACES, WHICH ARE table's OWN VALUES AND NOT A GLOBAL.
     *
     * Same reasoning the `empty` role's padding carries one test up, and it is the reason
     * these are not covered BY that test: base.css zeroes every element's padding, so each
     * of these is a declaration table made rather than a value it inherited, and dropping
     * one collapses a cell onto its own rule.
     *
     * THEY EMIT AS FOUR LONGHANDS FROM FOUR PARAMS, deliberately, and the engine's shape
     * is the claim: `padding-top` / `-bottom` carry `@space-sm` while `-left` / `-right`
     * carry `@space-md`, so an author retuning one edge does not silently reset the other
     * three the way the `padding` shorthand would.
     *
     * The caption's own `font-size` rides along because it is the one value in this group
     * that is not spacing: 14px against the 15px it would otherwise inherit from the
     * `table` role, a real step down that only the caption takes.
     */
    public function testThePaddedCellSurfacesKeepTableSOwnSpacingAndTheCaptionItsOwnSize(): void
    {
        foreach (['.table__header', '.table__cell', '.table__caption'] as $selector) {
            $block = $this->roleBlock($selector);
            foreach ([
                'padding-top:var(--space-sm);',
                'padding-bottom:var(--space-sm);',
                'padding-left:var(--space-md);',
                'padding-right:var(--space-md);',
            ] as $declaration) {
                $this->assertStringContainsString(
                    $declaration,
                    $block,
                    "{$selector} must keep `{$declaration}` — base.css zeroes every element's padding, "
                    . "so this is table's own value and not a global it could drop"
                );
            }
        }

        $this->assertStringContainsString(
            'font-size:0.875rem;',
            $this->roleBlock('.table__caption'),
            "the caption steps down from the 15px it would inherit from the `table` role"
        );
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

    /**
     * EVERY RETIRED SLOT'S REPLACEMENT PAINTS AT ITS NEW ADDRESS.
     *
     * The shape faq's rebuild left behind (ComponentPropsTest::
     * testFaqDeclaresRolesAndNoStyleSlots), applied to table's six. SchemaValidationTest's
     * SLOT_RENAME_MIGRATION_NOTES already checks that each note's route names a role this
     * component declares, a group that role permits and a parameter the taxonomy knows —
     * all three of which are REGISTRY facts. None of them is emission.
     *
     * The notes are the only migration path an author gets: the write is refused and the
     * message hands them this text. A route that validates and then paints nothing sends
     * that author somewhere the page never changes, which is worse than no note because it
     * reads as authoritative. So each route is driven with a distinctive value and read
     * back out of the emitter — acceptance is not emission, in #1046's words.
     */
    public function testEveryRetiredSlotsReplacementPaintsAtItsNewAddress(): void
    {
        // The six retired slots, each at the role that owns the value now.
        $routes = [
            '--table-padding-top'           => ['_band',   'spacing',    'padding-top',   '7px'],
            '--table-padding-bottom'        => ['_band',   'spacing',    'padding-bottom', '9px'],
            '--table-heading-size'          => ['heading', 'typography', 'size',          '13px'],
            '--table-heading-color'         => ['heading', 'typography', 'color',      '#123456'],
            '--table-heading-measure'       => ['heading', 'sizing',     'max-width',     '11px'],
            '--table-heading-margin-bottom' => ['heading', 'spacing',    'margin-bottom', '19px'],
        ];

        $roles = pp_udc_component_roles('table');
        foreach ($routes as $retired => [$role, $group, $param, $value]) {
            $this->assertArrayHasKey($role, $roles, "{$retired} routes to a role table does not declare");
            $this->assertContains(
                $group,
                $roles[$role]['groups'] ?? [],
                "{$retired}'s replacement needs `{$role}` to permit the `{$group}` group"
            );
            $css = pp_udc_band_css([
                'component' => 'table',
                'id'        => 'pp-1a2b3c4d',
                'props'     => [],
                'udc'       => [$role => [$group => [$param => $value]]],
            ]);
            $this->assertStringContainsString(
                $value,
                $css,
                "{$retired}'s replacement ({$role}.{$group}.{$param}) validated but never reached "
                . 'the page — the migration note would send an author somewhere nothing happens'
            );
        }
    }

    /**
     * `row` WITHHOLDS `typography`, AND THE WITHHOLDING IS THE FEATURE.
     *
     * Every other element role in this component permits the group. `row` does not, and
     * the schema states why: a colour on a <tr> reaches its cells only by INHERITANCE,
     * while `cell` pins `@color-text` as a direct declaration on the <td>. So the group
     * would be accepted at write, stored, reported applied — and paint nothing. That is
     * the accepted-but-inert class the whole contract exists to end, which makes this the
     * one omission in the component that a future author is most likely to "fix".
     *
     * BOTH HALVES, because a withholding that is only half asserted reads as a capability
     * loss: the group is refused HERE, and the route the schema offers instead is accepted
     * THERE. A refusal with no working route would be the thing the omission is accused of
     * being.
     *
     * Asserted through the write gate rather than against the schema's `groups` array: the
     * array is what the gate reads, so the gate is the stronger end of the same claim.
     */
    public function testTheRowRoleWithholdsTypographyAndSendsTheAuthorToTheCell(): void
    {
        $refused = pp_udc_validate_map(['row' => ['typography' => ['color' => '#ffffff']]], 'table');
        $this->assertInstanceOf(\WP_Error::class, $refused, 'a typography write on `row` would paint nothing');
        $this->assertSame('unknown_udc_group', $refused->get_error_code());
        $this->assertStringContainsString('does not permit', $refused->get_error_message());

        // AND THE ROUTE WORKS. `cell` is the element the ink actually lands on.
        $this->assertNull(
            pp_udc_validate_map(['cell' => ['typography' => ['color' => '#ffffff']]], 'table'),
            "`cell` is where the schema sends an author for row ink, so it must accept it"
        );
    }

    /**
     * EVERY DEFAULT THE SCHEMA DECLARES ACTUALLY EMITS — THE COMPLETENESS HALF.
     *
     * Each test above names ONE declaration and defends ONE claim, which is the right
     * shape for a claim and the wrong shape for a CENSUS: a default on a role nobody
     * thought to check can be added, or stop emitting, with nothing going red. That is not
     * hypothetical here — the `wrap` role's entire frame (the two declarations
     * `.table-wrap` gave up) reached this file unasserted, and only the rendered e2e
     * baseline would have caught it, several minutes later in the gate.
     *
     * DERIVED RATHER THAN COUNTED, and that is the difference from
     * EmbedRoleDefaultsEmitTest's exact declaration count. Embed can afford a count at
     * eight; table's forty-three would be a golden file in disguise — it would fail on
     * every legitimate addition and teach the next author to retune the number without
     * reading it. So the schema's own defaults are walked, each param's CSS property is
     * looked up from the ENGINE'S taxonomy rather than restated here, and the block for
     * that role has to carry it.
     *
     * UdcEngineTest already sweeps every v2 default for GRAMMAR — that the value is one
     * the engine would accept. This is the other half, in #1046's words: acceptance is not
     * emission.
     */
    public function testEveryDefaultTheSchemaDeclaresReachesItsOwnBlock(): void
    {
        $groups  = pp_udc_groups();
        $states  = pp_udc_states();
        $checked = 0;

        foreach (pp_udc_component_roles('table') as $role => $definition) {
            $defaults = $definition['defaults'] ?? [];
            if ($defaults === []) {
                continue; // `cell-link` defaults nothing by design — its own test pins that.
            }
            $haystack = $this->blocksForRole((string) ($definition['selector'] ?? ''));

            foreach ($defaults as $group => $params) {
                // A STATE SUB-MAP IS FLATTENED IN, NOT SKIPPED. `row`'s hover tint is a
                // DEFAULT like any other and has to emit like one; skipping states here
                // would leave the only state default in the component unswept.
                $names = [];
                foreach ($params as $key => $value) {
                    if (isset($states[$key]) && is_array($value)) {
                        foreach (array_keys($value) as $stateParam) {
                            $names[] = (string) $stateParam;
                        }
                        continue;
                    }
                    $names[] = (string) $key;
                }

                foreach ($names as $param) {
                    $property = $groups[$group]['params'][$param]['property'] ?? null;
                    $this->assertNotNull(
                        $property,
                        "table.{$role} defaults {$group}.{$param}, which names no CSS property"
                    );
                    $this->assertStringContainsString(
                        $property . ':',
                        $haystack,
                        "table.{$role} declares {$group}.{$param} and its block never carries "
                        . "`{$property}` — a default the schema advertises and the sheet does not "
                        . 'ship is the accepted-but-inert class this contract exists to end'
                    );
                    $checked++;
                }
            }
        }

        // Fail-closed: a walk that stops finding defaults must not read as compliance.
        $this->assertGreaterThan(30, $checked, 'the sweep stopped reaching the shipped defaults');
    }

    /**
     * Every emitted block belonging to one role, states included, concatenated.
     *
     * `_band`'s empty selector is the pp-zero tier rather than an element block, and a
     * state prints as its own block (`.table__row:hover{…}`), so a per-role haystack has
     * to admit both or the sweep above would report a false absence for the hover tint.
     * Scoped per role rather than searched whole-sheet on purpose: five roles declare a
     * padding and two declare a fill, so a whole-sheet search would let any one of them
     * vouch for the others.
     */
    private function blocksForRole(string $selector): string
    {
        if ($selector === '') {
            preg_match('/@layer pp-zero\{:where\(\[data-pp-component="table"\]\)\{([^}]*)\}/', $this->css, $m);
            return $m[1] ?? '';
        }
        // The optional state suffix, and NOTHING else: the `{` immediately after keeps
        // `.table` from matching `.table__cell` or `.table-wrap`.
        $pattern = '/\[data-pp-component="table"\] ' . preg_quote($selector, '/') . '(?::[a-z-]+)?\{([^}]*)\}/';
        preg_match_all($pattern, $this->css, $m);
        return implode('', $m[1] ?? []);
    }
}
