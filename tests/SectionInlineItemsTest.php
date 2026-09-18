<?php
/**
 * tests/SectionInlineItemsTest.php
 *
 * Section inline-items row (issue 475): section.body_items renders a band of short
 * plain-text items with a CSS-generated, slot-colorable separator between them. The
 * renderer emits `<ul class="section__inline-items" role="list">` only when
 * body_items is non-empty, after .section__content; the separator is a `li::before`
 * pseudo-element (never a content character) so it can be slot-colored and stays out
 * of the accessibility tree.
 *
 * Hanging-separator clip (issue 489): the separator is on EVERY item's `::before`,
 * each item is pulled left by exactly the separator's occupied width, and the row is
 * overflow:hidden, so the separator that would otherwise dangle at the start of a
 * wrapped line is clipped. The row is left-packed and centered as a block (width:
 * fit-content + auto margins), so a single-line row still reads centered while
 * wrapped lines pack from the left.
 *
 * Two halves: render pins (assert the emitted HTML) and CSS-content pins (PHPUnit
 * does not execute CSS, so — like SectionBodyListTest/TypographyRoleTest — we assert
 * the source declares the routing rules; the computed cascade is covered by the
 * style-render e2e).
 */

use PHPUnit\Framework\TestCase;

class SectionInlineItemsTest extends TestCase
{
    private string $themeRoot;
    private string $componentsCss;
    private string $cssDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot     = dirname(__DIR__);
        $this->componentsCss = file_get_contents($this->themeRoot . '/assets/css/components.css');
        // Declarations, not prose. The retirement pins below assert that a slot name has
        // left the stylesheet, and the shared-mechanisms banner NAMES the retired slots
        // in its comment to explain why the colour is no longer authorable — so an
        // assertion run against the raw bytes would read that explanation as the thing
        // it forbids.
        $this->cssDeclarations = preg_replace('#/\*.*?\*/#s', '', $this->componentsCss);
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

    // ── Render: the row appears only when body_items is set ───────────────

    public function testUnsetBodyItemsEmitsNoRowAndStaysByteIdentical(): void
    {
        $without = $this->render('section', ['layout' => 'text-only', 'body' => '<p>Hi</p>']);
        $withEmpty = $this->render('section', ['layout' => 'text-only', 'body' => '<p>Hi</p>', 'body_items' => []]);

        $this->assertStringNotContainsString('section__inline-items', $without,
            'unset body_items must emit no inline-items row.');
        $this->assertSame($without, $withEmpty,
            'an empty body_items array must render byte-identically to the unset case.');
    }

    public function testBodyItemsRendersRowWithRoleList(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['No credit card', 'Cancel anytime'],
        ]);
        $this->assertMatchesRegularExpression(
            '/<ul class="section__inline-items" role="list">/',
            $html,
            'body_items must render a <ul class="section__inline-items" role="list">.'
        );
    }

    public function testEachItemRendersAsListItemWithMatchingCount(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two', 'Three'],
        ]);
        $this->assertSame(3, substr_count($html, 'section__inline-item">'),
            'each body_items entry must render exactly one <li class="section__inline-item">.');
        $this->assertStringContainsString('<li class="section__inline-item">One</li>', $html);
        $this->assertStringContainsString('<li class="section__inline-item">Two</li>', $html);
        $this->assertStringContainsString('<li class="section__inline-item">Three</li>', $html);
    }

    public function testItemsAreEscapedAsPlainText(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['<b>bold</b> & "risky"'],
        ]);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;risky&quot;', $html,
            'body_items entries must be escaped with esc_html (no raw HTML).');
        $this->assertStringNotContainsString('<b>bold</b>', $html,
            'raw HTML in a body_items entry must not survive into the row.');
    }

    public function testEmptyStringEntriesAreDropped(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', '', 'Two'],
        ]);
        $this->assertSame(2, substr_count($html, 'section__inline-item">'),
            'empty-string entries must be dropped from the row.');
    }

    public function testRowRendersAfterBodyContent(): void
    {
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Body prose.</p>',
            'body_items' => ['Meta'],
        ]);
        $contentPos = strpos($html, 'section__content');
        $rowPos     = strpos($html, 'section__inline-items');
        $this->assertNotFalse($contentPos);
        $this->assertNotFalse($rowPos);
        $this->assertLessThan($rowPos, $contentPos,
            'the inline-items row must render after .section__content when both are set.');
    }

    public function testRowRendersInImageAndPanelLayouts(): void
    {
        $image = $this->render('section', [
            'layout'     => 'image-right',
            'body'       => '<p>Hi</p>',
            'image_url'  => 'https://example.com/x.png',
            'body_items' => ['Meta'],
        ]);
        $this->assertStringContainsString('section__inline-items', $image,
            'the row must render in the image layout body scope.');

        $panel = $this->render('section', [
            'layout'        => 'text-panel',
            'body'          => '<p>Hi</p>',
            'panel_heading' => 'Panel',
            'body_items'    => ['Meta'],
        ]);
        $this->assertStringContainsString('section__inline-items', $panel,
            'the row must render in the text-panel layout body scope.');
    }

    // ── The flush-top strip: retired, and the same three cases inverted (#1023) ──
    //
    // #488 inferred the modifier from whether body copy preceded the row. The three
    // cases below were its contract — body-less, with-body, whitespace-only — and they
    // are kept as ONE test with the opposite expectation rather than deleted, because
    // the inference is exactly what went away: the row's class is now the same on all
    // three, and an author who wants the flush strip says so on the role.

    public function testTheRowsClassNoLongerDependsOnWhetherBodyCopyPrecedesIt(): void
    {
        $base = '<ul class="section__inline-items" role="list">';

        $bodyless = $this->render('section', [
            'layout'     => 'text-only',
            'body_items' => ['SOC 2 Type II', '99.99% uptime'],
        ]);
        $withBody = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Real body copy.</p>',
            'body_items' => ['Meta'],
        ]);
        $whitespace = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => "   \n\t ",
            'body_items' => ['Meta'],
        ]);

        foreach (['body-less' => $bodyless, 'with body' => $withBody, 'whitespace body' => $whitespace] as $label => $html) {
            $this->assertStringContainsString($base, $html,
                "the $label strip carries the base class and nothing inferred from the body.");
            $this->assertStringNotContainsString('section__inline-items--flush-top', $html,
                "the $label strip must not carry a modifier nothing on the page can act on.");
        }
    }

    public function testTheFlushTopModifierIsRetiredRatherThanLeftDead(): void
    {
        // #488's automatic flush-top is GONE (#1023), and this pin is inverted rather
        // than deleted, because the reason matters. Its rule lived in this stylesheet
        // (`pp-v1`) while the `inline-items` role's `spacing.margin-top` default emits
        // UNLAYERED, so it could never win again — the class of declaration that
        // validates and paints nothing. A rule that cannot win is worse than an absent
        // one, so both the rule and the modifier class went, together.
        //
        // THE CAPABILITY HAS AN AUTHOR IDIOM INSTEAD: set `spacing.margin-top: 0` on the
        // `inline-items` role for a body-copy-less strip. Disclosed in the component
        // README, the CHANGELOG, and the AI-facing authoring docs.
        $this->assertStringNotContainsString(
            'section__inline-items--flush-top',
            $this->componentsCss,
            'the dead --flush-top rule must not survive in the stylesheet.'
        );
        $this->assertStringNotContainsString(
            'section__inline-items--flush-top',
            file_get_contents($this->themeRoot . '/components/section/section.php'),
            'the renderer must not emit a modifier class nothing styles.'
        );
    }

    // ── CSS pins: the row layout, the separator, and slot routing ─────────

    public function testInlineItemsRowIsABlockCenteredWrappingFlexRow(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*display:\s*flex/s',
            $this->componentsCss,
            '.section__inline-items must be a flex row.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*flex-wrap:\s*wrap/s',
            $this->componentsCss,
            '.section__inline-items must wrap (the responsive default, no mobile rule).'
        );
        // justify-content is `flex-start` outright since #1023. It used to read the
        // --section-inline-items-align SLOT; alignment is the `body_items_align` PROP
        // now (justify-content is a layout property and the taxonomy carries no layout
        // group), and the prop derives the --center modifier rather than a raw keyword,
        // because the modifier also switches the separator from ::before to ::after.
        // flex-start is what an unset slot resolved to, so the default row is unchanged
        // — and left-packing is what lets the #489 clip hide line-leading separators.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*justify-content:\s*flex-start/s',
            $this->componentsCss,
            '.section__inline-items justify-content must be flex-start (the default row is left-packed).'
        );
        // Centered as a BLOCK instead: shrink-to-fit width + auto side margins, so a
        // single-line row still reads centered.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*width:\s*fit-content/s',
            $this->componentsCss,
            '.section__inline-items must shrink-wrap (width: fit-content) to center as a block.'
        );
        // `margin: 0 auto` — the auto SIDE margins are the block-centering mechanism and
        // stay structural; the row's TOP margin left this rule at #1023 and is the
        // `inline-items` role's `spacing.margin-top` (@space-md, the same value). The
        // boundary admits `0 auto` as geometry and would reject the old three-value
        // form, which carried a real length.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*margin:\s*0\s+auto/s',
            $this->componentsCss,
            '.section__inline-items must use auto side margins to center the shrink-wrapped block.'
        );
    }

    public function testHangingSeparatorClipIsWiredForBleedThroughFreeWrapping(): void
    {
        // #489: overflow:hidden is the clip surface; each item is pulled left by the
        // separator's occupied width; the separator is a fixed-width inline-block box
        // so that pull is an exact token value independent of the glyph's advance.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items\s*\{[^}]*overflow:\s*hidden/s',
            $this->componentsCss,
            '.section__inline-items must clip its overflow to hide line-leading separators (#489).'
        );
        // The pull moved to the SHARED GLYPH AND PROSE MECHANISMS block with the
        // separator it belongs to, and is spelled `margin-left` rather than the
        // four-value shorthand — the arithmetic is identical. A NEGATIVE margin is
        // admitted by the structural boundary precisely because it cannot express
        // separation, only this kind of pull; a positive one still fails the lint.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-item\s*\{[^}]*margin-left:\s*calc\(-1 \* \(var\(--space-sm\) \+ var\(--space-xs\)\)\)/s',
            $this->componentsCss,
            '.section__inline-item must be pulled left by the separator occupied width (--space-sm + --space-xs).'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items li::before\s*\{[^}]*display:\s*inline-block[^}]*width:\s*var\(--space-sm\)/s',
            $this->componentsCss,
            'the separator must be a fixed-width inline-block box (width: --space-sm) so the pull is exact.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items li::before\s*\{[^}]*margin-right:\s*var\(--space-xs\)/s',
            $this->componentsCss,
            'the separator right margin (--space-xs) completes its occupied width.'
        );
    }

    /**
     * INVERTED (#1023). The earlier version of this test asserted that the role carries
     * the body's size and weight as its own defaults — and its own comment contained the
     * fact that disproves it.
     *
     * The comment correctly observed that the row is a SIBLING of `.section__content`, so
     * dropping the declarations "would have fallen back to the band". That is exactly what
     * v1 DID: it declared `font-size: var(--section-body-size, inherit)`, whose fallback is
     * `inherit`, not a value. With the slots unset the row fell back to the band and
     * rendered 16px/400 — measured at 375/768/1280 — while copying the body's literals into
     * the role gave 17.04px/430. The premise was right and the conclusion was backwards.
     *
     * So the role declares NO typography at all. A role legitimately declining to default
     * a parameter is the faithful expression of `inherit`, and it restores the
     * follow-the-parent behaviour the shared slot pair used to give: an author who sizes
     * the `body` role and wants the strip to match sets the strip too, and one who wants
     * the strip to follow the band leaves it alone — which is the same design principle as
     * the separator's `currentColor` fallback.
     */
    public function testInlineItemsDeclineToDefaultTheirTypeSoTheRowInheritsAsItDid(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $defaults = $schema['roles']['inline-items']['defaults'];

        $this->assertArrayNotHasKey(
            'typography',
            $defaults,
            'the row must declare NO type default: v1\'s fallback was `inherit`, which is '
            . 'not a value that can be carried forward. Copying the body\'s literals here '
            . 'renders 17.04px/430 where v1 rendered 16px/400.'
        );

        // `typography` must still be an ALLOWED group, or the author could not size the
        // strip at all — declining a DEFAULT is not the same as withholding the surface.
        $this->assertContains(
            'typography',
            $schema['roles']['inline-items']['groups'],
            'the row must still be typeable by an author, just not defaulted by the theme'
        );

        // The cap its shared wrapper used to give it, restored on the role.
        $this->assertSame(
            '40rem',
            $defaults['sizing']['max-width'],
            'v1 capped the row at `max-width: 100%` of a 40rem wrapper; that wrapper is gone'
        );

        $this->assertStringNotContainsString(
            '--section-body-size',
            $this->cssDeclarations,
            'the retired body-size slot must not be referenced anywhere any more'
        );
    }

    public function testSeparatorIsCssGeneratedAndScreenReaderQuiet(): void
    {
        // Middot glyph via ::before with empty alt-text, so it never becomes a
        // content character and stays out of the accessibility tree.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items li::before\s*\{[^}]*content:\s*"\\\\00b7"\s*\/\s*""/s',
            $this->componentsCss,
            'the separator must be a CSS-generated middot with empty alt-text (content: "\\00b7" / "").'
        );
    }

    public function testTheSeparatorColourIsNoLongerAuthorable(): void
    {
        // THE NARROWING, PINNED RATHER THAN LEFT TO BE NOTICED (#1023). The separator is
        // drawn with `content` on a `::before`, and ruling A3 defers pseudo-elements to
        // their own ruling — so no role can address it at any value, and
        // `--section-separator-color` has no v2 home. Its two rules (the base and the
        // bg-image re-route) went with the slot.
        //
        // THE RENDERED COLOUR DOES CHANGE, AND THIS PIN SAYS SO — an earlier draft of this
        // comment claimed byte-identity by carrying the LIST MARKERS' story onto the
        // SEPARATOR, and it was wrong. The markers defaulted to `var(--color-accent)` and
        // still paint it. The separator defaulted to `var(--color-muted)`, and that muted
        // default existed to make it FOLLOW ITS SIBLING TEXT: on an inverted band
        // `--color-muted` was remapped to the light on-inverted colour, and
        // `.section--has-bg-image` carried an explicit re-route to `--color-bg`. Both were
        // BAND-CLASS remaps; a v2 band has no class, so reusing the literal would have
        // painted a fixed grey that vanishes on the dark bands v2 makes easy.
        //
        // So the fallback is `currentColor` — the same intent in the mechanism v2 has.
        // Residual, disclosed in three places rather than rounded off: on a default light
        // band the middot moves #5e6677 -> #2d3648, because the row inherits
        // `@color-text-secondary`. `--pp-list-marker-color` still leads the chain, so
        // setting it to `@color-muted` restores the old grey.
        //
        // The two halves are asserted separately below, because collapsing them is exactly
        // the mistake this comment is correcting.
        $this->assertStringNotContainsString(
            '--section-separator-color',
            $this->cssDeclarations,
            'the retired separator-colour slot must not be referenced in the stylesheet.'
        );
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertArrayNotHasKey(
            'style_slots',
            $schema['styling'],
            'section is on the UDC and declares no style slots at all.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items li::before\s*\{[^}]*color:\s*var\(--pp-list-marker-color,\s*currentColor\)/s',
            $this->componentsCss,
            'the separator keeps painting, through the shared marker variable, following its row.'
        );
        // The CENTRED mode's trailing glyph must carry the IDENTICAL colour contract, or
        // `body_items_align` would change the separator's colour as well as its position.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--center li:not\(:last-child\)::after\s*\{[^}]*color:\s*var\(--pp-list-marker-color,\s*currentColor\)/s',
            $this->componentsCss,
            'the centred trailing separator must share the leading one\'s colour contract.'
        );
        // …and the LIST MARKERS must NOT have been dragged along with it: their accent
        // default is correct and unchanged, and conflating the two is what produced the
        // false byte-identity claim this test now corrects.
        $this->assertMatchesRegularExpression(
            '/\.section__content--marker-arrow > ul > li::before\s*\{[^}]*color:\s*var\(--pp-list-marker-color,\s*var\(--color-accent\)\)/s',
            $this->componentsCss,
            'the body list marker keeps the accent fallback it always painted.'
        );
    }

    // ── Per-line alignment: a PROP since #1023 (issue 510's capability) ──────
    //
    // It was an enum STYLE SLOT. v2 components declare none, and this one could not
    // become a role value either: it sets `justify-content`, a LAYOUT property, and the
    // UDC taxonomy carries no layout group — the same reason hero kept `split_ratio`
    // and `vertical_align` as props. It also has to derive a MODIFIER rather than emit a
    // raw keyword, because the centred mode switches the separator from ::before to
    // ::after; a role value could never do that. So it is `body_items_align`, with the
    // same two accepted values and the same default.

    public function testAlignIsAPropWithTheSameTwoValuesAndDefault(): void
    {
        $schema = json_decode(file_get_contents($this->themeRoot . '/components/section/schema.json'), true);
        $this->assertArrayNotHasKey('style_slots', $schema['styling'],
            'section declares no style slots at all, so the align slot cannot be one.');

        $prop = $schema['props']['body_items_align'];
        $this->assertSame('enum', $prop['type'], 'the align prop must be an enum prop.');
        $this->assertTrue($prop['strict'], 'the enum must be strict, so an out-of-set value refuses at write.');
        $this->assertSame(['start', 'center'], $prop['values'],
            'the align prop must accept exactly start | center, as the slot did.');
        $this->assertSame('start', $prop['default'],
            'the align prop must default to start (unchanged historical behavior).');
    }

    public function testCenterAlignValueAddsCenterModifierClass(): void
    {
        // The renderer reads the validated component style map (top-level style →
        // __pp_style) and derives the --center modifier when the value is center.
        $html = $this->render('section', [
            'layout'           => 'text-only',
            'body'             => '<p>Hi</p>',
            'body_items'       => ['One', 'Two', 'Three'],
            'body_items_align' => 'center',
        ]);
        $this->assertStringContainsString(
            '<ul class="section__inline-items section__inline-items--center" role="list">',
            $html,
            'a center-aligned strip must carry the --center modifier class.'
        );
    }

    public function testUnsetAlignStaysByteIdenticalAndStartEmitsNoCenterModifier(): void
    {
        // Unset align renders byte-identically to before this issue: no --center
        // modifier and no inline --section-inline-items-align custom property at all
        // (the base rule's flex-start fallback drives it). The unchanged #489
        // hanging-clip pins above assert the visual result stays put.
        $unset = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two'],
        ]);
        $this->assertStringNotContainsString('section__inline-items--center', $unset,
            'an unset align must not add the --center modifier.');
        $this->assertStringNotContainsString('style=', $unset,
            'a v2 band emits no style attribute at all — the whole slot map is gone.');

        // Explicit start is a no-op mode: it never adds the --center modifier, so the
        // row reads left-packed exactly as an unset align does.
        $start = $this->render('section', [
            'layout'           => 'text-only',
            'body'             => '<p>Hi</p>',
            'body_items'       => ['One', 'Two'],
            'body_items_align' => 'start',
        ]);
        $this->assertStringNotContainsString('section__inline-items--center', $start,
            'align:start must not add the --center modifier (left-packed).');
    }

    public function testUnknownAlignValueFallsBackToStartAtRender(): void
    {
        // Render-time fail-safe: any non-'center' value (an out-of-band / legacy /
        // restore write the strict write-time enum would have rejected) falls
        // through to the unchanged left-packed default — never a half-applied mode.
        $html = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two'],
            'body_items_align' => 'left',
        ]);
        $this->assertStringNotContainsString('section__inline-items--center', $html,
            'a non-center align value must not trigger the center modifier (fail-safe to start).');
    }

    public function testACentredBodyLessStripCarriesOnlyTheCentreModifier(): void
    {
        // This case used to assert BOTH derived modifiers. Only one is derived now:
        // #488's flush-top inference is retired (see the class test above), so a
        // body-less centred strip is indistinguishable in markup from a centred strip
        // that follows body copy. The centre modifier is still derived, because it
        // carries the ::before -> ::after separator switch a role value could not.
        $html = $this->render('section', [
            'layout'           => 'text-only',
            'body_items'       => ['SOC 2', '99.99% uptime'],
            'body_items_align' => 'center',
        ]);
        $this->assertStringContainsString(
            '<ul class="section__inline-items section__inline-items--center" role="list">',
            $html,
            'a body-less centered strip carries the --center modifier and nothing else.'
        );
    }

    // ── Authoring-path validation (issue 510, Section 14.1) ───────────────

    public function testCenterAlignValidatesThroughComposition(): void
    {
        // Rule 14.1: author through the REAL validate surface, not a raw meta write.
        // The gate is now the PROP enum rather than the style-slot engine's enum, which
        // is the whole point of the conversion — one fewer authoring surface for the
        // same capability.
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'body_items' => ['A', 'B'], 'body_items_align' => 'center'],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'align:center must validate through the composition prop gate.'
        );
    }

    public function testStartAlignValidatesThroughComposition(): void
    {
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'body_items' => ['A', 'B'], 'body_items_align' => 'start'],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'align:start must validate through the composition prop gate.'
        );
    }

    public function testOutOfSetAlignValueRejectedByComposition(): void
    {
        // Anything outside the bounded set is rejected at write time (nothing
        // persists). The code changes from `invalid_style_value` to
        // `invalid_prop_value` with the surface; the guarantee does not.
        $composition = [[
            'component' => 'section',
            'props'     => ['body' => '<p>x</p>', 'body_items' => ['A', 'B'], 'body_items_align' => 'left'],
        ]];
        $result = pp_validate_composition($composition);
        $this->assertInstanceOf(\WP_Error::class, $result,
            'an out-of-set align value must be rejected by the authoring surface.');
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        $this->assertStringContainsString('start, center', $result->get_error_message(),
            'the rejection must name the accepted value set.');
    }

    public function testRenderBoundaryDropsOutOfSetAlignButEmitsValidOne(): void
    {
        // #330 parity, restated for a prop. The boundary used to re-validate the enum
        // and emit the survivor as an INLINE CUSTOM PROPERTY; a v2 band emits no style
        // attribute at all, so the survivor is the derived modifier class instead. The
        // guarantee is the one that mattered: an out-of-set value reaches the DOM as
        // nothing, never as a half-applied mode.
        $valid = $this->render('section', [
            'layout'           => 'text-only',
            'body'             => '<p>Hi</p>',
            'body_items'       => ['One', 'Two'],
            'body_items_align' => 'center',
        ]);
        $this->assertStringContainsString('section__inline-items--center', $valid,
            'a valid enum value must be emitted as the derived modifier class.');
        $this->assertStringNotContainsString('style=', $valid,
            'and never as an inline custom property — a v2 band emits no style attribute.');

        $rogue = $this->render('section', [
            'layout'     => 'text-only',
            'body'       => '<p>Hi</p>',
            'body_items' => ['One', 'Two'],
            '__pp_style' => ['--section-inline-items-align' => 'left'],
        ]);
        $this->assertStringNotContainsString('--section-inline-items-align', $rogue,
            'an out-of-set enum value must be dropped at the render boundary (#330).');
    }

    // ── CSS pins: the centered trailing-separator technique ───────────────

    public function testCenterModifierZeroesItemPullAndSwitchesSeparator(): void
    {
        // On the centered row the hanging-clip geometry does not apply, so the
        // per-item left pull is zeroed and the leading ::before separator is
        // suppressed (content: none).
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--center \.section__inline-item\s*\{[^}]*margin-left:\s*0/s',
            $this->componentsCss,
            'the --center modifier must zero the per-item left pull.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--center li::before\s*\{[^}]*content:\s*none/s',
            $this->componentsCss,
            'the --center modifier must suppress the leading ::before separator.'
        );
    }

    public function testCenterModifierEmitsTrailingSeparatorThroughTheSharedMarkerVariable(): void
    {
        // The centered separator is a TRAILING middot on every item except the last
        // (:not(:last-child)). Its colour used to route through the
        // --section-separator-color slot; that slot is retired with the rest of them, so
        // both modes read the shared --pp-list-marker-color — falling back to
        // `currentColor`, NOT to the accent the two list markers take. See
        // testTheSeparatorColourIsNoLongerAuthorable() for why the separator's fallback
        // differs from theirs, and for the residual that difference leaves.
        //
        // The point of asserting it HERE too is that the two modes must not diverge: if
        // only one carried `currentColor`, `body_items_align` would silently change the
        // separator's COLOUR as well as its position, which is not what the prop selects.
        // The BG-IMAGE re-route that mirrored this rule is gone outright, because
        // `.section--has-bg-image` is not emitted.
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--center li:not\(:last-child\)::after\s*\{[^}]*content:\s*"\\\\00b7"\s*\/\s*""/s',
            $this->componentsCss,
            'the --center separator must be a trailing middot on li:not(:last-child)::after.'
        );
        $this->assertMatchesRegularExpression(
            '/\.section__inline-items--center li:not\(:last-child\)::after\s*\{[^}]*color:\s*var\(--pp-list-marker-color,\s*currentColor\)/s',
            $this->componentsCss,
            'the --center trailing separator must paint through the shared marker variable, following its row.'
        );
        $this->assertStringNotContainsString(
            'section--has-bg-image',
            $this->cssDeclarations,
            'the retired bg-image variant must leave no rule behind.'
        );
    }

    public function testCenterModifierDeclaredAfterBaseRule(): void
    {
        // Equal-specificity source order: the modifier must follow the base rule.
        $basePos   = strpos($this->componentsCss, '.section__inline-items {');
        $centerPos = strpos($this->componentsCss, '.section__inline-items--center .section__inline-item {');
        $this->assertNotFalse($basePos);
        $this->assertNotFalse($centerPos);
        $this->assertGreaterThan($basePos, $centerPos,
            'the --center modifier must be declared after the base .section__inline-items rule.');
    }
}
