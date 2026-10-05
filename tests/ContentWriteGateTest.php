<?php
/**
 * tests/ContentWriteGateTest.php
 *
 * Layer 3A's WRITE gate through the real authoring surface (Section 14.1; #1242 T3a;
 * docs/v2/LAYER-3-CONTRACT.md §2.2, §2.6, §3.3, §4 E6; T-3 and T-14 of §10).
 *
 * THE DELTA THIS FILE PROVES. Before the gate, every content prop was type-checked and
 * nothing else (§1.2): probe-02's update_composition carrying event attributes, a
 * `javascript:` link, an iframe, a script and forged engine attributes returned ok:true
 * with zero findings, and render stripped them silently. Each refusal below was RED on
 * origin/main (the same file run there: ok:true, stored), and is green only because the
 * shared engine now runs lib/content.php's predicate on every changed band.
 *
 *   §1 refusals: whole write refused with content_construct_excluded, clause named,
 *      nothing stored — through update_composition, update_component, create_page,
 *      add_component and the editor save.
 *   §2 admissions: what the ratified rulings admit lands verbatim (stored as authored).
 *   §3 M-2 / T-14: a stored band's legacy content never blocks an unrelated edit.
 *   §4 E6 props side: the write that adds a colliding anchor is the one refused.
 *   §5 report surfaces never refuse: preview + findings; the plugin-boundary disclosure.
 */

use PHPUnit\Framework\TestCase;

class ContentWriteGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    private function section(string $body, array $extra = []): array
    {
        return ['component' => 'section', 'props' => array_merge(['title' => 'T', 'body' => $body], $extra)];
    }

    private function page(array $composition): int
    {
        $post_id = pp_create_page('Content gate', 'draft');
        if ($composition !== []) {
            // Raw meta: the STORED state a later gate must live with (legacy bytes).
            update_post_meta($post_id, '_pp_composition', wp_slash(wp_json_encode($composition)));
        }
        return $post_id;
    }

    private function stored(int $post_id): array
    {
        return pp_get_composition($post_id);
    }

    // ── §1 refusals ───────────────────────────────────────────────────────────

    /** probe-02's payload, one hostile construct per case, each with its clause. */
    public static function refusedBodies(): array
    {
        return [
            'E1 event handler'          => ['<p onclick="steal()">x</p>', 'E1'],
            'E1 data-wp directive'      => ['<div data-wp-on--click="actions.x">x</div>', 'E1'],
            'E2 javascript URL'         => ['<a href="javascript:alert(1)">x</a>', 'E2'],
            'E3 iframe'                 => ['<iframe src="https://example.com"></iframe>', 'E3'],
            'E4 script'                 => ['<script>var a=1;</script>', 'E4'],
            'E6 forged band marker'     => ['<div data-pp-band="pp-1a2b3c4d">x</div>', 'E6'],
            'E6 minted id'              => ['<div id="pp-1a2b3c4d">x</div>', 'E6'],
            'E7 url() in style'         => ['<div style="background:url(https://evil.example/x.png)">x</div>', 'D3'],
            'E8 unknown element'        => ['<blink>x</blink>', 'E8'],
            'E9 formaction'             => ['<button formaction="https://evil.example">x</button>', 'E9'],
            'E10 stray closer'          => ['x</div></section><p>outside</p>', 'E10'],
            'E11 clobbering name'       => ['<object type="application/pdf" data="/wp-content/uploads/a.pdf" id="querySelector"></object>', 'E11'],
            'D5 forms (descoped)'       => ['<form action="/subscribe" method="post"><input type="email" name="email"></form>', 'D5'],
            'E12 out-of-band reference' => ['<button popovertarget="pp-nav-menu">x</button>', 'E12'],
            'P-16 unsupported markup'   => ['<p><b>Note</p><p>rest</p>', 'P-16'],
            'P-17 nonce'                => ['<span nonce="abc">x</span>', 'P-17'],
        ];
    }

    /** @dataProvider refusedBodies */
    public function testUpdateCompositionRefusesTheWholeWriteAndStoresNothing(string $body, string $clause): void
    {
        $original = [$this->section('<p>stored</p>')];
        $post_id  = $this->page($original);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [$this->section($body)],
        ]);

        $this->assertFalse($result['ok'], 'the hostile body must not be accepted');
        $this->assertSame('content_construct_excluded', $result['error_code']);
        $this->assertStringContainsString('prop "body"', $result['error']);
        $display = preg_match('/^D(\d)$/', $clause, $m) ? 'Δ' . $m[1] : $clause;
        $this->assertStringContainsString('refused by ' . $display, $result['error']);
        $this->assertStringContainsString('Nothing was stored', $result['error']);
        $this->assertSame($original, $this->stored($post_id), 'refuse, never coerce: the stored page is untouched');
    }

    public function testUpdateComponentRefusesAContentPatch(): void
    {
        $post_id = $this->page([$this->section('<p>a</p>')]);
        $result  = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0,
            'props'   => ['body' => '<img src="x" onerror="alert(1)">'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('content_construct_excluded', $result['error_code']);
        $this->assertSame('<p>a</p>', $this->stored($post_id)[0]['props']['body']);
    }

    public function testCreatePageRefusesHostileContentAndLeavesNoPage(): void
    {
        $before = count($GLOBALS['_pp_test_store']['posts']);
        $result = pp_execute_action('create_page', [
            'title' => 'X', 'composition' => [$this->section('<a href="vbscript:x">x</a>')],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('content_construct_excluded', $result['error_code']);
        $this->assertSame($before, count($GLOBALS['_pp_test_store']['posts']), 'validate runs before any page exists');
    }

    public function testAddComponentRefusesHostileContent(): void
    {
        $post_id = $this->page([$this->section('<p>a</p>')]);
        $result  = pp_execute_action('add_component', [
            'post_id' => $post_id, 'component' => 'cta',
            'props'   => ['title' => 'Go', 'body' => 'Read <div>this</div>', 'button_text' => 'Go', 'button_url' => '/x'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('content_construct_excluded', $result['error_code']);
        $this->assertStringContainsString('P-2', $result['error'], 'INLINE refuses a non-inline element (§3.3)');
        $this->assertCount(1, $this->stored($post_id));
    }

    public function testTheEditorSaveRunsTheSameGate(): void
    {
        $post_id = $this->page([$this->section('<p>a</p>')]);
        $response = _pp_save_composition_response([
            'post_id' => $post_id,
            'nonce' => 'ok',
            'composition' => wp_slash(wp_json_encode([$this->section('<p style="color:red !important">x</p>')])),
        ]);
        $this->assertFalse($response['ok'], 'the editor save is update_composition and refuses the same way');
        $this->assertStringContainsString('!important', wp_json_encode($response['data']));
        $this->assertSame('<p>a</p>', $this->stored($post_id)[0]['props']['body']);
    }

    /** INLINE and heading sinks (P-2 B+), each through a list field too. */
    public function testInlineAndHeadingPropsRefuseWhatTheRulingDoesNotAdmit(): void
    {
        $cases = [
            ['grid', ['title' => 'G', 'items' => [['title' => 'Card', 'text' => '<ul><li>x</li></ul>']]], 'items item 0 field "text"'],
            ['grid', ['title' => 'G', 'items' => [['title' => 'A <b>bold</b> card', 'text' => 'x']]], 'items item 0 field "title"'],
            ['testimonials', ['title' => 'T', 'items' => [['quote' => '<p>para</p>', 'author' => 'A']]], 'items item 0 field "quote"'],
            ['hero', ['title' => 'Big <h1>nested</h1>'], '"title"'],
            ['cta', ['title' => 'C', 'body' => '<span data-x="1">a</span>', 'button_text' => 'b', 'button_url' => '/'], '"body"'],
        ];
        foreach ($cases as [$component, $props, $prop_label]) {
            $post_id = $this->page([]);
            $result  = pp_execute_action('update_composition', [
                'post_id' => $post_id, 'composition' => [['component' => $component, 'props' => $props]],
            ]);
            $this->assertFalse($result['ok'], $component . ' ' . $prop_label);
            $this->assertSame('content_construct_excluded', $result['error_code'], $result['error'] ?? '');
            $this->assertStringContainsString('prop ' . $prop_label, $result['error']);
        }
    }

    public function testTableCellsRunTheCellWrapper(): void
    {
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [[
            'component' => 'table',
            'props' => ['title' => 'T', 'headers' => ['A', 'B'], 'rows' => [['ok', 'x</td><td>escaped']]],
        ]]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('rows row 0 cell 1', $result['error']);
        $this->assertStringContainsString('E10', $result['error']);
    }

    // ── §2 admissions: stored exactly as authored ─────────────────────────────

    public static function admittedBodies(): array
    {
        return [
            'P-17 microdata + ARIA 1.2'     => ['<div itemscope itemtype="https://schema.org/Thing"><span itemprop="name" aria-level="2" tabindex="0" translate="no">x</span><bdi>y</bdi></div>'],
            'Δ1 SVG icon (P-12, P-18)'      => ['<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" role="img"><defs><linearGradient id="g1" href="#g0"><stop offset="0" stop-color="#000"/></linearGradient></defs><path d="M0 0h24v24H0z" fill="url(#g1)" color-interpolation-filters="sRGB" textLength="3"/><text><textPath href="#p1">t</textPath></text><path id="p1" d="M0 0"/><linearGradient id="g0"/></svg>'],
            'Δ2 picture/srcset'             => ['<picture><source srcset="/a.avif 1x, /a@2x.avif 2x" type="image/avif"><img src="/a.jpg" srcset="/a.jpg 1x, https://cdn.example/a2.jpg 2x" alt="a" decoding="async" fetchpriority="high"></picture>'],
            'Δ3 modern style (P-13, P-20)'  => ['<div style="transform: rotate(2deg); color: rgb(0 0 0 / .5); font-family: &quot;Inter&quot;, sans-serif; --accent: #f00; fill: url(#g1)">x</div><svg><linearGradient id="g1"/></svg>'],
            'invoker commands (P-17)'       => ['<button commandfor="d1" command="show-modal">Open</button><dialog id="d1"><button commandfor="d1" command="close">Close</button></dialog>'],
            'P-10 app link + raster data:'  => ['<a href="whatsapp://send?text=hi">w</a> <img alt="" src="data:image/png;base64,iVBORw0KGgo=">'],
            'P-11 same-install PDF'         => ['<object type="application/pdf" data="https://example.com/wp-content/uploads/2026/10/guide.pdf"></object>'],
            'P-23 custom element'           => ['<my-widget class="w">x</my-widget>'],
            'E12 in-band references'        => ['<button popovertarget="m1">open</button><div popover id="m1" aria-labelledby="h1"><h3 id="h1">M</h3></div>'],
            'P-16 parses fine'              => ['<p>a<p>b<ul><li>c<li>d</ul>'],
            'Δ4 target (normalisation)'     => ['<a href="https://example.com" target="_blank">x</a>'],
        ];
    }

    /** @dataProvider admittedBodies */
    public function testRatifiedAdmissionsLandVerbatim(string $body): void
    {
        $post_id = $this->page([]);
        $result  = pp_execute_action('update_composition', [
            'post_id' => $post_id, 'composition' => [$this->section($body)],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame($body, $this->stored($post_id)[0]['props']['body'], 'accepted bytes are stored exactly as authored (§2.2)');
    }

    public function testHeadingsAndInlineAdmitTheP2Set(): void
    {
        $post_id = $this->page([]);
        $title = 'Brand<sup>®</sup> <span class="accent" style="color:#c00">red</span> <code>x</code> <mark>m</mark> <small>s</small> <sub>2</sub>';
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            ['component' => 'grid', 'props' => ['title' => $title, 'items' => [
                ['title' => 'H<sub>2</sub>O', 'text' => 'See <a href="/docs" title="Docs">docs</a>, <strong>now</strong><br><em>ok</em>'],
            ]]],
        ]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame($title, $this->stored($post_id)[0]['props']['title']);
    }

    /** §3.3: labels, buttons and URLs stay PLAIN: never parsed, never refused. */
    public function testPlainPropsAreNeverRefused(): void
    {
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            ['component' => 'cta', 'props' => ['title' => 'C', 'eyebrow' => 'The <details> element',
                'button_text' => '<b>Go</b>', 'button_url' => '/go']],
        ]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('The <details> element', $this->stored($post_id)[0]['props']['eyebrow']);
    }

    // ── §3 M-2 / T-14 ─────────────────────────────────────────────────────────

    public function testALegacyBandNeverBlocksAnEditToAnotherBand(): void
    {
        $legacy = $this->section('<p onclick="old()">legacy</p>', ['id' => 'legacy']);
        $post_id = $this->page([$legacy, $this->section('<p>b</p>')]);

        // update_composition re-sending the legacy band unchanged
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            $legacy, $this->section('<p>edited</p>'),
        ]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        // update_component on the other band
        $result = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 1, 'props' => ['body' => '<p>again</p>'],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        // add_component next to it
        $result = pp_execute_action('add_component', [
            'post_id' => $post_id, 'component' => 'section', 'props' => ['title' => 'N', 'body' => '<p>n</p>'],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
    }

    public function testAReEmittedNormalisedOrReIdentifiedBandIsUnchanged(): void
    {
        $post_id = $this->page([$this->section("<p onclick='old()' class=a>legacy &amp; x</p>")]);
        $reemitted = $this->section('<p class="a" onclick="old()">legacy &#38; x</p>');
        $reemitted['id'] = 'pp-9f8e7d6c'; // a fresh band id: matched by content, not id (§2.6)
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$reemitted]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
    }

    public function testRewritingAnExcludedConstructIsAChange(): void
    {
        $post_id = $this->page([$this->section('<p onclick="a()">x</p>')]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            $this->section('<p onclick="b()">x</p>'),
        ]]);
        $this->assertFalse($result['ok'], 'raw-parse comparison: onclick a -> b is a change (T-14)');
        $this->assertSame('content_construct_excluded', $result['error_code']);
    }

    public function testEditingTheLegacyBandItselfIsRefusedUntilTheConstructGoes(): void
    {
        $post_id = $this->page([$this->section('<p onclick="old()">legacy</p>')]);
        $result = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'New title'],
        ]);
        $this->assertFalse($result['ok'], 'a changed band answers for all of its content (§2.6)');
        $result = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'New title', 'body' => '<p>legacy</p>'],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
    }

    /** T-14 at the band-scoped boundary: a non-content patch to a legacy band is not refused. */
    public function testANonContentPatchToALegacyBandIsNotRefused(): void
    {
        $post_id = $this->page([$this->section('<p onclick="x()">legacy</p>')]);
        $result = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['eyebrow' => 'New'],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
    }

    /** The context the write gate builds itself (not a hand-made one), through the real action. */
    public function testCrossPropAndCrossBandReferencesUseTheRealContext(): void
    {
        $faq = static fn (array $answers): array => ['component' => 'faq', 'props' => ['title' => 'F',
            'items' => array_map(static fn ($a) => ['question' => 'Q', 'answer' => $a], $answers)]];

        // in-band across items of one band: admitted
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            $faq(['<a href="#a2" aria-controls="a2">x</a>', '<div id="a2">t</div>']),
            $faq(['<img alt="" src="/m.png" usemap="#m">', '<map name="m"><area alt="" href="/x" shape="rect" coords="0,0,1,1"></map>']),
            $this->section('<button popovertarget="sec">open</button>', ['id' => 'sec']),
        ]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        // a details name group shared with another band: refused (E12)
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            $faq(['<details name="g"><summary>a</summary>b</details>']),
            $faq(['<details name="g"><summary>c</summary>d</details>']),
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('E12', $result['error']);
    }

    /** Two bands carrying the same id: a reference in either may bind to the other (E12). */
    public function testADuplicateIdAcrossBandsRefusesTheReference(): void
    {
        $band = $this->section('<label for="email">Email</label><meter id="email" value="1"></meter>');
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$band, $band]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('E12', $result['error']);
    }

    /** A row stored as an object keyed by position still supplies its bands as the M-2 baseline. */
    public function testAnObjectShapedStoredRowStillSuppliesTheBaseline(): void
    {
        $legacy = $this->section('<p onclick="old()">legacy</p>');
        $post_id = pp_create_page('Shape', 'draft');
        update_post_meta($post_id, '_pp_composition', wp_slash(wp_json_encode(['1' => $legacy])));
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$legacy]]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'the repair save re-sends the stored band unchanged');
    }

    /**
     * M-2 is ONE-TO-ONE (2026-10-05 ruling on T3a 7A question 1): a stored band vouches for at
     * most one incoming band, so a copy of a stored band is new content and runs the gate.
     * Red-proof: all three were ACCEPTED under the "some stored band" matching.
     */
    public function testACopyOfAStoredBandIsNewContent(): void
    {
        $a = ['component' => 'faq', 'props' => ['title' => 'F',
            'items' => [['question' => 'Q', 'answer' => '<details name="g"><summary>a</summary>b</details>']]]];
        $b = ['component' => 'faq', 'props' => ['title' => 'G', 'items' => [['question' => 'Q', 'answer' => '<p>b</p>']]]];
        $legacy = $this->section('<p onclick="x()">legacy</p>');

        $post_id = $this->page([$a, $b]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$a, $a]]);
        $this->assertFalse($result['ok'], 'sending a stored band twice: the second copy is judged');
        $this->assertStringContainsString('E12', $result['error']);

        $result = pp_execute_action('update_component', ['post_id' => $post_id, 'component_index' => 1,
            'props' => ['title' => 'F', 'items' => $a['props']['items']]]);
        $this->assertFalse($result['ok'], 'turning band B into a copy of band A is judged');
        $this->assertSame('content_construct_excluded', $result['error_code'], $result['error']);
        $this->assertStringContainsString('E12', $result['error']);

        $post_id = $this->page([$legacy]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$legacy, $legacy]]);
        $this->assertFalse($result['ok'], 'cloning a legacy band: the clone is judged');
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$legacy, $this->section('<p>b</p>')]]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'the original still matches its own stored band');
    }

    /** M-8 per prop: the cap admits exactly its size; one byte more is refused whole, named and sized. */
    public function testM8APropOverTheCapIsRefusedWholeWithItsSize(): void
    {
        $at_cap = '<p>' . str_repeat('a', PP_CONTENT_PROP_MAX_BYTES - 7) . '</p>';
        $this->assertSame(PP_CONTENT_PROP_MAX_BYTES, strlen($at_cap));
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$this->section($at_cap)]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $over = $at_cap . 'b';
        $post_id = $this->page([$this->section('<p>stored</p>')]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$this->section($over)]]);
        $this->assertFalse($result['ok']);
        $this->assertSame('content_too_large', $result['error_code']);
        $this->assertStringContainsString('is 65,537 bytes', $result['error']);
        $this->assertStringContainsString('at most 65,536 bytes', $result['error']);
        $this->assertStringContainsString('never truncated', $result['error']);
        $this->assertSame('<p>stored</p>', $this->stored($post_id)[0]['props']['body'], 'refused whole: nothing stored');
    }

    /** M-8 per write: the changed content's total bytes and value count are capped; unchanged bands do not count. */
    public function testM8AWriteOverTheTotalCapsIsRefused(): void
    {
        $big = '<p>' . str_repeat('a', 60000) . '</p>';
        $bands = [];
        for ($k = 0; $k < 18; $k++) {
            $bands[] = $this->section($big . $k);
        }
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => $bands]);
        $this->assertFalse($result['ok']);
        $this->assertSame('content_too_large', $result['error_code']);
        $this->assertStringContainsString('at most 1,048,576 bytes', $result['error']);

        // The same bands already stored are unchanged: not judged, not counted.
        $post_id = $this->page($bands);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => $bands]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');

        $rows = array_fill(0, 2100, ['a', 'b']);
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            ['component' => 'table', 'props' => ['title' => 'T', 'headers' => ['A', 'B'], 'rows' => $rows]],
        ]]);
        $this->assertFalse($result['ok']);
        $this->assertSame('content_too_large', $result['error_code']);
        $this->assertStringContainsString('4,096 values', $result['error']);
    }

    /** One-to-one with position first: a copy into an EARLIER band does not steal the stored original. */
    public function testACopyIntoAnEarlierBandIsJudged(): void
    {
        $a = ['component' => 'faq', 'props' => ['title' => 'F',
            'items' => [['question' => 'Q', 'answer' => '<details name="g"><summary>a</summary>b</details>']]]];
        $b = ['component' => 'faq', 'props' => ['title' => 'G', 'items' => [['question' => 'Q', 'answer' => '<p>b</p>']]]];
        $post_id = $this->page([$b, $a]);
        $result = pp_execute_action('update_component', ['post_id' => $post_id, 'component_index' => 0,
            'props' => ['title' => 'F', 'items' => $a['props']['items']]]);
        $this->assertFalse($result['ok'], 'band 0 turned into a copy of stored band 1 is judged');
        $this->assertSame('content_construct_excluded', $result['error_code']);
        $this->assertStringContainsString('E12', $result['error']);

        $post_id = $this->page([$this->section('<p>o</p>'), $this->section('<p onclick="x()">l</p>')]);
        $result = pp_execute_action('update_component', ['post_id' => $post_id, 'component_index' => 0,
            'props' => ['body' => '<p onclick="x()">l</p>']]);
        $this->assertFalse($result['ok'], 'cloning a later legacy band into band 0 is judged');

        // The matcher pairs by position first, then id, then any byte-equal stored band.
        $this->assertSame([1 => true], pp_content_unchanged_keys([$a, $a], [$b, $a]));
        $this->assertSame([0 => true, 1 => true], pp_content_unchanged_keys([$a, $b], [$b, $a]), 'a swap leaves both unchanged');
    }

    /** E12 the other way, through the write: a new band may not capture a stored band's reference. */
    public function testANewBandMayNotCaptureAStoredBandsReference(): void
    {
        $j = $this->section('<button popovertarget="x">open</button><div popover id="x">j</div>');
        $i = $this->section('<div popover id="x">captured</div>');
        $post_id = $this->page([$j]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$i, $j]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('another band refers to this id', $result['error']);
    }

    /** A band's own prop cap is checked before that band is parsed: a 4 MB prop is refused fast and named. */
    public function testM8APropsOwnCapIsCheckedBeforeItIsParsed(): void
    {
        $stored = [$this->section('<p>stored</p>')];
        $huge = $this->section(str_repeat('<b>x</b>', 500000));
        $post_id = $this->page($stored);
        $start = microtime(true);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$this->section('<p>a</p>'), $huge]]);
        $this->assertSame('content_too_large', $result['error_code']);
        $this->assertLessThan(1.0, microtime(true) - $start, 'no signature or parse of a 4 MB prop');
    }

    /** The write cap is checked over every changed band BEFORE the first one is parsed. */
    public function testM8AnOverBudgetWriteParsesNothing(): void
    {
        $bands = [];
        for ($k = 0; $k < 20; $k++) {
            $bands[] = $this->section(str_repeat('<b>x</b>', 7000) . $k); // ~56 KB, under the prop cap
        }
        $post_id = $this->page([]);
        $start = microtime(true);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => $bands]);
        $this->assertSame('content_too_large', $result['error_code']);
        $this->assertLessThan(1.0, microtime(true) - $start, 'refused before ~17 bands of dense markup are parsed');
    }

    /** The M-2 matcher never signs a value over the prop cap, incoming or stored (it can only match byte for byte). */
    public function testTheMatcherNeverSignsAnOversizedValue(): void
    {
        $stored = [$this->section('<p>stored</p>')];
        // Over the prop cap but under the write cap, so only the prop-cap guard can spare it.
        $huge = $this->section(str_repeat('<b>x</b>', 120000)); // 960 KB
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $this->assertSame([], pp_content_unchanged_keys([$huge], $stored));
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before, 'no token array built for a 960 KB value');

        // The stored side: an over-cap legacy band is not signed either.
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $this->assertSame([], pp_content_unchanged_keys([$this->section('<p>new</p>')], [$huge]));
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before, 'no token array built for a stored 960 KB value');
    }

    /** The matcher stops signing once the write's byte budget is spent; the rest count as changed. */
    public function testTheMatcherStopsSigningPastTheWriteBudget(): void
    {
        $bands = $stored = [];
        for ($k = 0; $k < 20; $k++) {
            $body = str_repeat('<b>x</b>', 7000) . $k; // ~56 KB each, 20 of them ~1.1 MB
            $stored[] = $this->section(str_replace('<b>', "<b\t>", $body)); // structurally equal, not byte-equal
            $bands[] = $this->section($body);
        }
        $matched = pp_content_unchanged_keys($bands, $stored);
        $this->assertNotSame([], $matched, 'bands inside the budget still match structurally');
        $this->assertArrayNotHasKey(19, $matched, 'bands past the 1 MiB budget are not signed and count as changed');
    }

    /**
     * The matcher's ONE budget covers what it signs on BOTH sides (cycle-7 ruling): stored
     * bands it signs are charged too, so a large stored page cannot make a small write slow.
     * At exactly the budget the last stored band is still signed and matched; one stored
     * byte more and it is not (the incoming band is then judged, as new content is).
     */
    /** @group timing */
    public function testTheMatcherChargesStoredSigningToTheSameBudget(): void
    {
        // Each band is 'T' + a 65,535-byte body: 65,536 bytes, 16 of them exactly the budget.
        $body = static fn (string $fill, string $tail) => '<p>' . str_repeat($fill, 65535 - 7 - strlen($tail)) . $tail . '</p>';
        $incoming = [$this->section($body('a', '&amp;'))];
        $stored = [];
        foreach (range('b', 'o') as $fill) { // 14 structurally different stored bands
            $stored[] = $this->section($body($fill, '&amp;'));
        }
        $stored[] = $this->section($body('a', '&#38;')); // structurally equal to the incoming band, not byte-equal
        $this->assertSame(PP_CONTENT_WRITE_MAX_BYTES, array_sum(array_map(static fn ($b) => strlen($b['props']['title'] . $b['props']['body']),
            array_merge($incoming, $stored))));
        $this->assertSame([0 => true], pp_content_unchanged_keys($incoming, $stored), 'signed at exactly the budget');
        // Exactly one byte more: one structurally different stored band one byte longer.
        $stored[0] = $this->section($body('b', '&amp;') . ' ');
        $this->assertSame([], pp_content_unchanged_keys($incoming, $stored), 'one stored byte more: the budget is spent before the match');

        // A large stored page and a one-band write: the write stays fast.
        $page = [];
        for ($k = 0; $k < 160; $k++) {
            $page[] = $this->section(str_repeat('<b>x</b>', 8000) . $k);
        }
        $best = INF;
        for ($run = 0; $run < 3; $run++) { // best of three: a busy machine's one slow run is no signal
            $start = microtime(true);
            $this->assertSame([], pp_content_unchanged_keys([$this->section('<p>new</p>')], $page));
            $best = min($best, microtime(true) - $start);
        }
        if (!extension_loaded('xdebug') && !extension_loaded('pcov')) {
            $this->assertLessThan(2.0, $best, 'stored signing is bounded by the write budget');
        }
    }

    /**
     * The facts the cross-band rules use for a JUDGED band come from the predicate's walk
     * (kept and finished, never walked twice); an unjudged band's are read lexically.
     */
    public function testJudgedBandFactsComeFromThePredicatesWalk(): void
    {
        $items = [$this->section('<p id="a1">x</p><label for="a1">L</label>'), $this->section('<p id="b1">y</p>')];
        $index = pp_content_composition_index($items, [0]);
        $this->assertArrayHasKey('states', $index[0]);
        $this->assertArrayNotHasKey('states', $index[1]);
        $this->assertSame(['a1'], $index[0]['ids']);
        $this->assertSame(['a1'], $index[0]['refs']);
        $this->assertSame(['b1'], $index[1]['ids']);
        // The walk never sees an id the parser does not build: a refused element's id is no fact.
        $index = pp_content_composition_index([$this->section('<p id="pp-0000abcd">x</p><p id="ok">y</p>')]);
        $this->assertSame(['ok'], $index[0]['ids']);
        $this->assertSame([], pp_content_band_losses($items, 0, pp_content_composition_index($items, [0])));
    }

    // ── cycle 8: the trust tier, the forms credential gate, app schemes, M-2 pass-through ──

    /**
     * Routed item 12 (ruled for T3a): the widened set needs unfiltered_html. A writer
     * without it gets core parity, refused by name; a writer with it gets the full set.
     * Through the real write path, both tiers.
     */
    public function testTheWidenedSetNeedsUnfilteredHtml(): void
    {
        $beyond = [
            '<svg viewBox="0 0 1 1"><path d="M0 0"/></svg>', '<div tabindex="0">t</div>', '<a href="sip:100">call</a>',
            '<img src="data:image/png;base64,AAAA" alt="">', '<button commandfor="d" command="show-modal">o</button><dialog id="d">d</dialog>',
            '<my-widget>w</my-widget>', '<p style="display:grid">x</p>', '<p contenteditable="true">x</p>',
        ];
        $parity = '<p class="lead" style="color: red">Hello <a href="/x" title="t">there</a> <strong>s</strong></p>'
            . '<ul><li>one</li></ul><img src="/a.png" alt="a" width="10" height="10"><blockquote cite="https://example.com/q">q</blockquote>';
        $post_id = $this->page([]);
        $GLOBALS['_pp_test_user_caps'] = ['unfiltered_html' => false];
        try {
            $this->assertSame('core', pp_content_write_tier());
            $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$this->section($parity)]]);
            $this->assertTrue($result['ok'], $result['error'] ?? '');
            foreach ($beyond as $body) {
                $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$this->section($body)]]);
                $this->assertFalse($result['ok'], $body);
                $this->assertStringContainsString('unfiltered_html', $result['error'], $body);
            }
        } finally {
            unset($GLOBALS['_pp_test_user_caps']);
        }
        $this->assertSame('full', pp_content_write_tier());
        foreach ($beyond as $body) {
            $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$this->section($body)]]);
            $this->assertTrue($result['ok'], $body . ': ' . ($result['error'] ?? ''));
        }
        // A stored band beyond core stays, unchanged, under a core-tier edit to another band (§2.6).
        $GLOBALS['_pp_test_user_caps'] = ['unfiltered_html' => false];
        try {
            $stored = $this->stored($post_id);
            $result = pp_execute_action('update_composition', ['post_id' => $post_id,
                'composition' => array_merge($stored, [$this->section('<p>added by a contributor</p>')])]);
            $this->assertTrue($result['ok'], $result['error'] ?? '');
        } finally {
            unset($GLOBALS['_pp_test_user_caps']);
        }
    }

    /**
     * Item 18 (ruled): the byte-equal pass-through is exempt from the matcher's budget, so an
     * untouched stored band never blocks an edit to another band, whatever its size.
     */
    public function testAnUntouchedHugeBandNeverBlocksAnEditElsewhere(): void
    {
        $huge = $this->section('<p>' . str_repeat('legacy ', 300000) . '</p>'); // ~2 MB, far over every cap
        $post_id = $this->page([$huge, $this->section('<p>small</p>')]);
        $stored = $this->stored($post_id);
        $stored[1]['props']['body'] = '<p>small, edited</p>';
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => $stored]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([0 => true], pp_content_unchanged_keys([$huge], [$huge]), 'matched byte for byte, unbudgeted');
    }

    // ── cycle 9 ───────────────────────────────────────────────────────────────

    /**
     * E12 completions (cycle 9): a usemap binds to the FIRST map named (or id'd) so in the
     * document, and an SVG fragment reference to the first element with that id, so both
     * are cross-band references: the reproduced captures are refused, both directions.
     */
    public function testUsemapAndSvgFragmentReferencesStayInTheirBand(): void
    {
        $phish = $this->section('<map name="m"><area shape="default" href="https://evil.test/phish" alt=""></map>');
        $image = $this->section('<img alt="" src="/a.png" usemap="#m"><map name="m"><area shape="rect" coords="0,0,1,1" href="/x" alt=""></map>');
        $this->assertNotSame([], pp_validate_composition_errors([$phish, $image], null, null, null), 'both new');
        $this->assertNotSame([], pp_validate_composition_errors([$phish, $image], null, null, [$phish]), 'the map band stored, the image band new');
        $this->assertNotSame([], pp_validate_composition_errors([$phish, $image], null, null, [$image]), 'the image band stored, the map band new');
        $by_id = $this->section('<map id="m"><area shape="default" href="https://evil.test/" alt=""></map>');
        $this->assertNotSame([], pp_validate_composition_errors([$by_id, $image], null, null, null), 'a map id binds too');
        $this->assertSame([], pp_validate_composition_errors([$image], null, null, null), 'alone, in band: admitted');

        $logo = $this->section('<svg><g id="logo"><a href="https://evil.test/pay"><rect width="1" height="1"/></a></g></svg>');
        $use = $this->section('<svg><use href="#logo"/></svg>');
        $this->assertNotSame([], pp_validate_composition_errors([$logo, $use], null, null, null));
        $this->assertNotSame([], pp_validate_composition_errors([$logo, $use], null, null, [$use]), 'the reference stored, the id new');
        $clip = $this->section('<svg><rect width="1" height="1" clip-path="url(#c)"/></svg>');
        $clipdef = $this->section('<svg><clipPath id="c"><rect width="1" height="1"/></clipPath></svg>');
        $this->assertNotSame([], pp_validate_composition_errors([$clipdef, $clip], null, null, null));
        $styled = $this->section('<p style="filter:url(#f)">x</p>');
        $filter = $this->section('<svg><filter id="f"><feGaussianBlur stdDeviation="2"/></filter></svg>');
        $this->assertNotSame([], pp_validate_composition_errors([$filter, $styled], null, null, null));
        $this->assertSame([], pp_validate_composition_errors([$this->section('<svg><defs><linearGradient id="g"/></defs><rect fill="url(#g)"/><use href="#g"/></svg>')], null, null, null));
    }

    /**
     * The facts walk of unchanged bands is bounded in WALKS as well as bytes (cycle 9): a
     * page of many small bands makes no write slow, and a band that cannot carry a fact (no
     * `<`, or no `=`) is not walked at all.
     */
    public function testTheFactsWalkIsBoundedByCount(): void
    {
        $plain = $tagged = [];
        for ($i = 0; $i < 6000; $i++) {
            $plain[] = $this->section('<p>' . $i . '</p>');
            $tagged[] = $this->section('<p id="a' . $i . '">x</p>');
        }
        $index = pp_content_composition_index(array_merge($plain, [$this->section('<p>new</p>')]), [6000]);
        $this->assertFalse($index[5999]['incomplete'], 'a band with no `=` carries no fact and is not walked');
        $index = pp_content_composition_index(array_merge($tagged, [$this->section('<p>new</p>')]), [6000]);
        // The judged band walks 2 values (title, body); each unchanged band 1 (its title has no `<`).
        $this->assertFalse($index[PP_CONTENT_WRITE_MAX_VALUES - 3]['incomplete']);
        $this->assertTrue($index[PP_CONTENT_WRITE_MAX_VALUES - 2]['incomplete'], 'exactly at the value budget');
        $this->assertTrue($index[5999]['incomplete'], 'past the write\'s value budget the facts are incomplete');
        if (!extension_loaded('xdebug') && !extension_loaded('pcov')) {
            $items = array_merge($tagged, [$this->section('<p>new</p>')]);
            $best = INF;
            for ($run = 0; $run < 2; $run++) {
                $start = microtime(true);
                pp_validate_composition_errors($items, null, null, $tagged);
                $best = min($best, microtime(true) - $start);
            }
            $this->assertLessThan(5.0, $best, '6,000 unchanged bands with facts: bounded by the walk budget');
        }
    }

    /** The facts budget at its exact edge: judged bytes are charged once, then the unchanged bands. */
    public function testTheFactsBudgetEdge(): void
    {
        $band = fn (int $k) => $this->section('<p class="s">' . str_repeat('s', 63981) . sprintf('%02d', $k) . '</p>'); // 64,000 + 'T'
        $this->assertSame(64001, array_sum(array_map(static fn ($v) => strlen($v[3]), pp_content_band_values($band(0)))));
        $fits = [];
        for ($k = 1; $k <= 15; $k++) {
            $fits[] = $band($k);
        }
        $index = pp_content_composition_index(array_merge([$band(0)], $fits), [0]);
        $this->assertFalse($index[15]['incomplete'], '16 x 64,001 bytes fit one write (judged charged once)');
        $index = pp_content_composition_index(array_merge([$band(0)], $fits, [$band(16)]), [0]);
        $this->assertTrue($index[16]['incomplete'], 'the 17th does not');
    }

    /** With incomplete facts, every cross-band fact is unverifiable: an id, a reference, a details group, a map. */
    public function testIncompleteFactsCoverEveryCrossBandFact(): void
    {
        $stored = [];
        for ($k = 0; $k < 17; $k++) {
            $stored[] = $this->section('<p class="s">' . str_repeat('s', 64990) . $k . '</p>');
        }
        foreach (['<p aria-describedby="x">a</p><span id="x">s</span>', '<details name="g"><summary>s</summary>d</details>',
            '<map name="m"></map>', '<svg><rect fill="url(#g)"/><linearGradient id="g"/></svg>'] as $body) {
            $errors = pp_validate_composition_errors(array_merge($stored, [$this->section($body)]), null, null, $stored);
            $this->assertNotSame([], $errors, $body);
            $this->assertStringContainsString('cannot be verified', $errors[0]->get_error_message(), $body);
        }
    }

    /** add_component judges the added band at the writer's tier too. */
    public function testAddComponentUsesTheWritersTier(): void
    {
        $post_id = $this->page([$this->section('<p>stored</p>')]);
        $GLOBALS['_pp_test_user_caps'] = ['unfiltered_html' => false];
        try {
            $result = pp_execute_action('add_component', ['post_id' => $post_id, 'component' => 'section',
                'props' => ['title' => 'T', 'body' => '<svg viewBox="0 0 1 1"></svg>']]);
            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('unfiltered_html', $result['error']);
        } finally {
            unset($GLOBALS['_pp_test_user_caps']);
        }
    }

    /**
     * Item 18: the byte-equal passes are not charged to the matcher budget, so a huge
     * untouched band never spends the budget a structurally-equal band beside it needs.
     */
    public function testByteEqualMatchesNeverSpendTheStructuralBudget(): void
    {
        $cells = array_fill(0, 18, [str_repeat('c', 60000)]);
        $huge = ['component' => 'table', 'props' => ['title' => 'T', 'headers' => ['A'], 'rows' => $cells]]; // ~1.08 MB
        $stored = [$huge, $this->section('<p class=a>x</p>')];
        $incoming = [$huge, $this->section('<p class="a">x</p>')]; // structurally equal, not byte-equal
        $this->assertSame([0 => true, 1 => true], pp_content_unchanged_keys($incoming, $stored));
    }

    /**
     * M-2's passes look stored bands up in buckets, never pair by pair: 12,000 tiny bands that
     * are structurally equal but not byte-equal (measured 18 s with pair loops) stay fast.
     *
     * @group timing
     */
    public function testTheMatcherIsLinearInTheBands(): void
    {
        $in = $stored = [];
        for ($i = 0; $i < 12000; $i++) {
            $in[] = $this->section('<b >x</b>');
            $stored[] = $this->section('<b>x</b>');
        }
        $start = microtime(true);
        $this->assertCount(12000, pp_content_unchanged_keys($in, $stored));
        if (!extension_loaded('xdebug') && !extension_loaded('pcov')) {
            $this->assertLessThan(3.0, microtime(true) - $start, 'bucketed, not quadratic');
        }
    }

    /** A stored usemap binds to what follows its FIRST `#`, as a browser reads it. */
    public function testALegacyUsemapFactIsReadAsTheBrowserReadsIt(): void
    {
        $legacy = ['component' => 'section', 'props' => ['title' => 'T', 'body' => '<img alt="" src="/a.png" usemap="x#m">']];
        $index = pp_content_composition_index([$legacy, $this->section('<p>n</p>')], [1]);
        $this->assertSame(['m'], $index[0]['refs']);
        $capture = $this->section('<map name="m"><area shape="default" href="https://evil.test/" alt=""></map>');
        $this->assertNotSame([], pp_validate_composition_errors([$legacy, $capture], null, null, [$legacy]));
    }

    /** The kept walk is what band_losses finishes: a judged value is never walked twice. */
    public function testBandLossesFinishTheKeptWalk(): void
    {
        $items = [$this->section('<p>clean</p>')];
        $index = pp_content_composition_index($items, [0]);
        $index[0]['states'][0]['losses'][] = _pp_content_loss('marker', '', 'E1', 'planted in the kept state');
        $this->assertContains('marker', array_column(array_column(pp_content_band_losses($items, 0, $index), 'loss'), 'construct'));
    }

    /**
     * ONE fact extractor (cycle 8): an unchanged band's facts are the walk's too, so an id
     * the tokenizer cannot see (inside SVG <style> raw text) still counts against a new band.
     */
    public function testUnchangedBandFactsComeFromTheWalk(): void
    {
        $legacy = $this->section('<svg><style><a id="zz"></a></style></svg>');
        $index = pp_content_composition_index([$legacy, $this->section('<p>x</p>')], [1]);
        $this->assertSame(['zz'], $index[0]['ids']);
        $this->assertArrayNotHasKey('states', $index[0], 'an unchanged band is read, not kept');
        // Two new bands with an empty details name are no group (HTML), so neither is refused.
        $pair = [$this->section('<details name=""><summary>a</summary>x</details>'), $this->section('<details name=""><summary>b</summary>y</details>')];
        $this->assertSame([], pp_validate_composition_errors($pair, null, null, null));
    }

    /**
     * Unchanged bands are walked within what the write leaves of its byte budget. Past it,
     * their facts are incomplete, and a write whose content carries an id, an id reference
     * or a details group, or that adds a band anchor, cannot be verified and is refused by
     * name; content without such a fact is still written.
     */
    public function testIncompleteFactsRefuseOnlyWhatTheyCannotVerify(): void
    {
        $stored = [];
        for ($k = 0; $k < 17; $k++) {
            $stored[] = $this->section('<p class="s">' . str_repeat('s', 64990) . $k . '</p>'); // `=`: it may carry a fact
        }
        $plain = array_merge($stored, [$this->section('<p>new text</p>')]);
        $this->assertSame([], pp_validate_composition_errors($plain, null, null, $stored));
        $index = pp_content_composition_index($plain, [17]);
        $this->assertTrue($index[16]['incomplete'], 'the 17th unchanged band does not fit what the write leaves');

        $with_id = array_merge($stored, [$this->section('<p id="new-id">new text</p>')]);
        $errors = pp_validate_composition_errors($with_id, null, null, $stored);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('cannot be verified', $errors[0]->get_error_message());

        $anchored = array_merge($stored, [$this->section('<p>new text</p>', ['id' => 'new-anchor'])]);
        $messages = array_map(static fn ($e) => $e->get_error_message(), pp_validate_composition_errors($anchored, null, null, $stored));
        $this->assertNotSame([], array_filter($messages, static fn ($m) => str_contains($m, 'cannot be verified unique')));
    }

    /** A value never read for disclosures past the prop cap (its write is refused, a stored one never judged). */
    public function testDisclosuresSkipAValueOverThePropCap(): void
    {
        $big = $this->section('<p id="wp">' . str_repeat('a', PP_CONTENT_PROP_MAX_BYTES) . '</p>');
        $this->assertNotContains('content_global_shadow', array_column(_pp_composition_findings([$big]), 'type'));
    }

    /** A band with an over-cap prop is refused for that prop only; its bytes are not also charged to the write. */
    public function testAnOverCapPropIsNotAlsoChargedToTheWrite(): void
    {
        $budget = ['bytes' => 0, 'values' => 0];
        $errors = pp_content_band_size_errors($this->section(str_repeat('a', PP_CONTENT_WRITE_MAX_BYTES + 1)), 'section', $budget);
        $this->assertCount(1, $errors);
        $this->assertSame(0, $budget['bytes']);
    }

    /** The matcher compares a style attribute as its declarations: spacing around `:` is no change. */
    public function testTheMatcherComparesStyleAsDeclarations(): void
    {
        $this->assertSame([0 => true], pp_content_unchanged_keys([$this->section('<p style="color:red">x</p>')],
            [$this->section('<p style="color: red">x</p>')]));
    }

    /** M-8 per write at its exact edges: 4,096 values admitted, 4,097 refused; 1 MiB admitted, one byte more refused. */
    public function testM8WriteCapsAdmitExactlyTheirSize(): void
    {
        $table = static fn (int $rows) => ['component' => 'table', 'props' => ['title' => 'T', 'headers' => ['A', 'B'],
            'rows' => array_fill(0, $rows, ['a', 'b'])]];
        // title + 2 x rows values
        $at = $table(2047);
        $at['props']['rows'][] = ['a'];
        $this->assertSame(PP_CONTENT_WRITE_MAX_VALUES, count(pp_content_band_values($at)));
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$at]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $at['props']['rows'][2047][] = 'b';
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$at]]);
        $this->assertSame('content_too_large', $result['error_code']);

        $bands = [];
        $remaining = PP_CONTENT_WRITE_MAX_BYTES;
        while ($remaining > 0) {
            $len = min(60000, $remaining - 1);
            $bands[] = ['component' => 'section', 'props' => ['title' => 'T', 'body' => str_repeat('a', $len)]];
            $remaining -= $len + 1;
        }
        $total = array_sum(array_map(static fn ($b) => strlen($b['props']['title']) + strlen($b['props']['body']), $bands));
        $this->assertSame(PP_CONTENT_WRITE_MAX_BYTES, $total);
        $post_id = $this->page([]);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => $bands]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $bands[0]['props']['body'] .= 'a';
        $post_id = $this->page([]); // a fresh page: on the first one these bands are unchanged and not counted
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => $bands]);
        $this->assertFalse($result['ok']);
        $this->assertSame('content_too_large', $result['error_code']);
    }

    // ── §4 E6 props side ──────────────────────────────────────────────────────

    public function testTheWriteThatAddsACollidingAnchorIsRefused(): void
    {
        $post_id = $this->page([$this->section('<p id="pricing">x</p>'), $this->section('<p>b</p>')]);
        $result = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 1, 'props' => ['id' => 'pricing'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('item 0', $result['error'], 'names the band whose content holds the id');

        $result = pp_execute_action('add_component', [
            'post_id' => $post_id, 'component' => 'section', 'props' => ['title' => 'P', 'body' => '<p>p</p>', 'id' => 'pricing'],
        ]);
        $this->assertFalse($result['ok'], 'add_component runs the cross-band check against the stored page');
        $this->assertSame('invalid_prop_value', $result['error_code']);
    }

    public function testAStoredAnchorDoesNotBlockAnEditAndIsNotAFinding(): void
    {
        $stored = [$this->section('<p>a</p>', ['id' => 'pricing']), $this->section('<p id="pricing">legacy</p>')];
        $post_id = $this->page($stored);
        $result = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'T2'],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'an anchor that was already stored is not "added"');
        $this->assertNotContains('invalid_prop_value', array_column(_pp_composition_findings($stored), 'type'),
            'report surfaces never refuse (§2.6)');
    }

    public function testAnAnchorMayNotTakeAnIdInsideItsOwnUnchangedContent(): void
    {
        $post_id = $this->page([$this->section('<p id="foo">x</p>')]);
        $result = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['id' => 'foo'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code']);
    }

    public function testContentMayNotTakeAnExistingAnchor(): void
    {
        $post_id = $this->page([$this->section('<p>a</p>', ['id' => 'pricing'])]);
        $result = pp_execute_action('add_component', [
            'post_id' => $post_id, 'component' => 'section', 'props' => ['title' => 'P', 'body' => '<h3 id="pricing">x</h3>'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('E6', $result['error']);
    }

    // ── §5 report surfaces ────────────────────────────────────────────────────

    public function testFindingsNeverCarryAContentErrorAndDiscloseCustomElements(): void
    {
        $items = [$this->section('<p onclick="x()">legacy</p><my-widget>w</my-widget>')];
        $types = array_column(_pp_composition_findings($items), 'severity', 'type');
        $this->assertArrayNotHasKey('content_construct_excluded', $types, 'the render side owns stored-content findings (§2.3)');
        $this->assertSame('info', $types['content_plugin_output'] ?? null);
    }

    public function testAnIdEqualToAPageGlobalIsDisclosedNotRefused(): void
    {
        $post_id = $this->page([]);
        $items = [$this->section('<p id="pricing">a</p>'),
            $this->section('<div id="top">x</div><div id="wp">y</div><p id="jQuery">z</p>')];
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => $items]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $found = array_values(array_filter(_pp_composition_findings($items), static fn ($f) => $f['type'] === 'content_global_shadow'));
        $this->assertCount(1, $found, 'only the band carrying a page-global name is disclosed');
        $this->assertSame('info', $found[0]['severity']);
        $this->assertSame(1, $found[0]['index']);
        $this->assertStringContainsString('"wp"', $found[0]['message']);
        $this->assertStringContainsString('"jQuery"', $found[0]['message']);
        $this->assertStringNotContainsString('"top"', $found[0]['message']);

        // A name on an element window named access does not expose is no shadow.
        $items = [$this->section('<div name="jQuery">x</div><span name="wp">y</span>')];
        $this->assertNotContains('content_global_shadow', array_column(_pp_composition_findings($items), 'type'));

        // Every attribute spelling the tokenizer reads is disclosed: after a `/`, or packed
        // against a quoted value.
        foreach (['<div/id="wp">x</div>', '<div class="a"id="wp">x</div>'] as $shape) {
            $this->assertContains('content_global_shadow', array_column(_pp_composition_findings([$this->section($shape)]), 'type'), $shape);
        }
        $this->assertContains('content_plugin_output',
            array_column(_pp_composition_findings([$this->section('<p>a</p><button/is="my-x">b</button>')]), 'type'));
    }

    public function testThePreviewValidationNeverRefusesOnContent(): void
    {
        // The preview AJAX handler is an anonymous closure; pin its call shape (with the
        // statement's semicolon, so explanatory prose cannot satisfy it).
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/admin.php');
        $start = strpos($source, "add_action('wp_ajax_pp_preview_composition'");
        $this->assertNotFalse($start);
        $closure = substr($source, $start, 4000);
        $this->assertStringContainsString('$result = pp_validate_composition($composition, false);', $closure);

        $this->assertTrue(pp_validate_composition([$this->section('<script>x</script>')], false));
        $this->assertInstanceOf(WP_Error::class, pp_validate_composition([$this->section('<script>x</script>')]));
    }
}
