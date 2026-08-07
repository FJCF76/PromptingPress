<?php
/**
 * tests/TableEmbedLogosMarkupTest.php
 *
 * Issue 583 — the renderer-level half of the stressed-state net for the three
 * components that carried no test of any kind: `table`, `embed` and `logos`.
 *
 * The rendered half lives in tests/e2e/style-render.spec.ts (the `#583` block) and
 * owns everything that needs a browser: computed boxes, the label-driven image-height
 * switch, the horizontal-scroll mechanism, contrast. This file deliberately does NOT
 * repeat any of that. It covers only the branches that a rendered test either cannot
 * reach or can only reach vacuously — the ones that decide whether an element is
 * emitted at all:
 *
 *   table.php   headers/rows empty  -> `.table-section__empty` INSTEAD of the table
 *               caption omitted     -> no <caption> at all
 *   logos.php   item without image_url -> the whole <li> is skipped, silently
 *               item with label        -> the `--labeled` modifier that drives the cap
 *   embed.php   title omitted       -> no <h2>; content omitted -> no `.embed__content`
 *
 * These are MARKUP-CONTRACT pins for the v1.13.0 gates that rename slot prefixes and
 * add `applies_when` conditions on top of the same templates — substring and
 * occurrence-count assertions on the elements that must (or must not) be emitted, not
 * a byte-for-byte snapshot: attribute order and surrounding markup are deliberately
 * not constrained. The byte-identity half of the net is the RENDERED block in
 * tests/e2e/style-render.spec.ts, which pins computed values.
 *
 * Nothing here asserts a product DECISION — every expectation is the template's
 * current literal output, quoted from the template, and is a pin to be moved
 * deliberately, not a specification to be defended.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TableEmbedLogosMarkupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    // ── table ────────────────────────────────────────────────────────────────

    public function testTableRendersHeadThroughBodyForAFullDataSet(): void
    {
        $html = $this->render('table', [
            'id'      => 'plans',
            'title'   => 'How the plans compare',
            'caption' => 'Rate card figures.',
            'headers' => ['Capability', 'Starter'],
            'rows'    => [['Composed bands', 'Unlimited']],
        ]);

        $this->assertStringContainsString('<section id="plans" class="table-section" data-pp-component="table">', $html);
        $this->assertStringContainsString('<h2 class="table-section__heading">How the plans compare</h2>', $html);
        $this->assertStringContainsString('<div class="table-wrap">', $html);
        $this->assertStringContainsString('<caption class="table__caption">Rate card figures.</caption>', $html);
        $this->assertStringContainsString('<th class="table__header" scope="col">', $html);
        $this->assertStringContainsString('<td class="table__cell">', $html);
        $this->assertStringNotContainsString('table-section__empty', $html);
    }

    /**
     * The empty branch. `table.php` renders the table only when BOTH headers and rows
     * are non-empty; either one empty swaps the whole scroll shell for a paragraph.
     *
     * Pinned because it is the one table state a rendered test cannot assert without
     * asserting an absence — and because the empty copy is the thing an author sees
     * when a data import half-fails.
     *
     * Omitting the props ENTIRELY is deliberately not a case: the component loader
     * raises its own "missing required prop" warning for that, which is the loader's
     * contract rather than this template's empty branch, and is covered by
     * SchemaValidationTest.
     *
     * @dataProvider emptyTableShapes
     */
    public function testTableRendersTheEmptyParagraphUnlessBothHeadersAndRowsArePresent(array $props): void
    {
        $html = $this->render('table', $props + ['title' => 'Comparison']);

        $this->assertStringContainsString(
            '<p class="table-section__empty text-muted">No data.</p>',
            $html,
            'the empty branch renders the literal current copy'
        );
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('table-wrap', $html);
        // The heading still renders — the band is not suppressed, only its data is.
        $this->assertStringContainsString('table-section__heading', $html);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function emptyTableShapes(): array
    {
        return [
            'no headers, no rows' => [['headers' => [], 'rows' => []]],
            'headers only'        => [['headers' => ['A', 'B'], 'rows' => []]],
            'rows only'           => [['headers' => [], 'rows' => [['1', '2']]]],
        ];
    }

    public function testTableOmitsTheOptionalHeadingAndCaptionWhenUnset(): void
    {
        $html = $this->render('table', [
            'headers' => ['A'],
            'rows'    => [['1']],
        ]);

        $this->assertStringNotContainsString('table-section__heading', $html);
        $this->assertStringNotContainsString('<caption', $html);
        // No id prop -> no id attribute at all (not id="").
        $this->assertStringContainsString('<section class="table-section"', $html);
    }

    // ── embed ────────────────────────────────────────────────────────────────

    public function testEmbedRendersHeadingAndContentWrapper(): void
    {
        $html = $this->render('embed', [
            'id'      => 'book',
            'title'   => 'Book a call',
            'content' => '<p>Embedded body copy.</p>',
        ]);

        $this->assertStringContainsString('<section id="book" class="embed" data-pp-component="embed">', $html);
        $this->assertStringContainsString('<h2 class="embed__heading">Book a call</h2>', $html);
        $this->assertStringContainsString('<div class="embed__content">', $html);
        // The content must actually reach the wrapper — an empty `.embed__content` is a
        // broken band, not a passing one. This pins that the template still ECHOES
        // $content; it cannot pin sanitisation, because do_shortcode() and
        // wp_kses_post() are identity stubs under this harness (tests/bootstrap.php).
        $this->assertStringContainsString('<p>Embedded body copy.</p>', $html);
    }

    /**
     * Both branches carry a POSITIVE anchor as well as the absence assertion. Without
     * one, a renderer that returned nothing at all — file renamed, loader guard
     * changed, fatal swallowed by the output buffer — would satisfy both
     * assertStringNotContainsString calls and the test would go green on an empty
     * string.
     */
    public function testEmbedOmitsTheHeadingAndTheContentWrapperWhenUnset(): void
    {
        $noTitle = $this->render('embed', ['content' => '<p>Body only.</p>']);
        $this->assertStringContainsString('data-pp-component="embed"', $noTitle);
        $this->assertStringContainsString('embed__content', $noTitle);
        $this->assertStringNotContainsString('embed__heading', $noTitle);

        // content is schema-required, but the template still guards it: an empty
        // content string renders the band shell with no `.embed__content` box, which
        // is the state a stripped/sanitised import lands in.
        $noContent = $this->render('embed', ['title' => 'Heading only', 'content' => '']);
        $this->assertStringContainsString('data-pp-component="embed"', $noContent);
        $this->assertStringContainsString('<h2 class="embed__heading">Heading only</h2>', $noContent);
        $this->assertStringNotContainsString('embed__content', $noContent);
    }

    public function testEmbedInvertedThemeEmitsTheInvertedModifier(): void
    {
        $html = $this->render('embed', ['theme' => 'inverted', 'content' => 'x']);
        $this->assertStringContainsString('class="embed embed--inverted"', $html);
    }

    // ── logos ────────────────────────────────────────────────────────────────

    /**
     * The `--labeled` modifier is what re-declares the image cap from 3rem to 2.5rem
     * (assets/css/components.css, `.logos__item--labeled .logos__image`). The rendered
     * consequence is pinned in the e2e mixed-strip test; this pins the markup that
     * causes it, which is the half a slot rename or an `applies_when` condition reads.
     */
    public function testLogosEmitsTheLabeledModifierOnlyForItemsThatCarryALabel(): void
    {
        $html = $this->render('logos', [
            'title' => 'Trusted by',
            'items' => [
                ['image_url' => 'https://example.com/a.png', 'image_alt' => 'A'],
                ['image_url' => 'https://example.com/b.png', 'image_alt' => 'B', 'label' => 'Beta'],
            ],
        ]);

        $this->assertSame(2, substr_count($html, '<li class="logos__item'), 'both items render');
        $this->assertSame(1, substr_count($html, 'logos__item--labeled'), 'only the labeled item gets the modifier');
        $this->assertSame(1, substr_count($html, 'class="logos__label"'), 'only the labeled item gets a label span');
        $this->assertStringContainsString('<span class="logos__label">Beta</span>', $html);
        $this->assertStringContainsString('<ul class="logos__list" role="list">', $html);
    }

    /**
     * An item carrying a label but NO image_url renders NOTHING — no <li>, no label,
     * no warning (the `if ($image_url)` guard in components/logos/logos.php).
     *
     * Pinned as CURRENT RENDER BEHAVIOUR, not as a decision. Issue #579 makes nested
     * `required` enforcement reject that shape at WRITE time; when it lands, this
     * render-path pin still holds (a composition that predates the rule, or one seeded
     * by raw meta, still reaches the renderer) — what changes is that the action
     * surface stops producing it. If #579 also changes the RENDER path, this test is
     * the one that will say so.
     */
    public function testLogosSilentlyDropsAnItemThatHasALabelButNoImage(): void
    {
        $html = $this->render('logos', [
            'items' => [
                ['image_url' => 'https://example.com/a.png', 'image_alt' => 'A'],
                ['label' => 'Category with no artwork'],
                ['image_url' => '', 'image_alt' => 'Blank url', 'label' => 'Also dropped'],
            ],
        ]);

        $this->assertSame(1, substr_count($html, '<li class="logos__item'), 'only the item with an image survives');
        $this->assertStringNotContainsString('Category with no artwork', $html);
        $this->assertStringNotContainsString('Also dropped', $html);
        // The band shell still renders, so the page shows an emptier strip, not an error.
        $this->assertStringContainsString('data-pp-component="logos"', $html);
        $this->assertStringContainsString('<ul class="logos__list" role="list">', $html);
    }

    // ── escaping routes ──────────────────────────────────────────────────────

    /**
     * The one asymmetry in these three templates where a silent regression is a
     * SECURITY regression: `table.php` sends row cells through `wp_kses_post()` (HTML
     * allowed, by design — the schema says "strings, HTML allowed") while headers, the
     * caption, every heading and the logos label go through `esc_html()`. None of the
     * three components appears in TextPropMarkupContractTest's provider, so nothing in
     * the repo pinned which prop takes which route.
     *
     * What this can and cannot prove under this harness: `esc_html()` is a real
     * htmlspecialchars in tests/bootstrap.php, so the escaped half is genuinely
     * exercised. `wp_kses_post()` is an IDENTITY stub there, so this cannot prove the
     * allowlist filters anything — only that the cell is NOT routed through esc_html.
     * That is the half that catches a swapped route in either direction; the real
     * sanitizer proof belongs to WordPress core and to the E2E suite that renders on a
     * real WP.
     */
    public function testTableRoutesCellsThroughKsesAndHeadersCaptionTitleThroughEscHtml(): void
    {
        $html = $this->render('table', [
            'title'   => 'Plans <b>compared</b>',
            'caption' => 'Rates <b>excl.</b> tax',
            'headers' => ['Capability <b>tier</b>'],
            'rows'    => [['<em>Unlimited</em> bands']],
        ]);

        // Cell: markup survives (wp_kses_post route).
        $this->assertStringContainsString('<em>Unlimited</em> bands', $html);
        // Header, caption and heading: markup is escaped to literal text (esc_html route).
        $this->assertStringContainsString('Capability &lt;b&gt;tier&lt;/b&gt;', $html);
        $this->assertStringContainsString('Rates &lt;b&gt;excl.&lt;/b&gt; tax', $html);
        $this->assertStringContainsString('Plans &lt;b&gt;compared&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>compared</b>', $html);
    }

    public function testLogosRoutesTitleAndLabelThroughEscHtml(): void
    {
        $html = $this->render('logos', [
            'title' => 'Trusted <b>by</b>',
            'items' => [
                ['image_url' => 'https://example.com/a.png', 'image_alt' => 'A', 'label' => 'Beta <b>tier</b>'],
            ],
        ]);

        $this->assertStringContainsString('Trusted &lt;b&gt;by&lt;/b&gt;', $html);
        $this->assertStringContainsString('Beta &lt;b&gt;tier&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testEmbedRoutesTitleThroughEscHtmlAndContentThroughKses(): void
    {
        $html = $this->render('embed', [
            'title'   => 'Book <b>now</b>',
            'content' => '<em>Embedded</em> markup',
        ]);

        $this->assertStringContainsString('Book &lt;b&gt;now&lt;/b&gt;', $html);
        // content is the sanctioned arbitrary-HTML surface — it must NOT be esc_html'd.
        $this->assertStringContainsString('<em>Embedded</em> markup', $html);
    }

    public function testLogosWithNoItemsRendersNoList(): void
    {
        $html = $this->render('logos', ['title' => 'Trusted by', 'items' => []]);

        $this->assertStringNotContainsString('logos__list', $html);
        $this->assertStringContainsString('<h2 class="logos__heading">Trusted by</h2>', $html);
    }
}
