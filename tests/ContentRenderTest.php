<?php
/**
 * tests/ContentRenderTest.php — Layer 3A's RENDER side (LAYER-3-CONTRACT.md §2.3, test row
 * T-4; #1242 T3b).
 *
 * Every stored content prop renders through the ONE predicate (lib/content.php) with the
 * composition's cross-band context; the page loop is driven here exactly as the theme's
 * templates drive it (pp_render_composition_bands()). Stored bytes are planted as RAW META,
 * the only way bytes the write gate refuses can exist (a restore, a raw write, a site
 * upgraded across the contract). The authoring path (§14.1) is exercised where the rule is an
 * authoring rule: the stored-intent marker for titles is only ever set by a gated write.
 *
 * RED ON MAIN: before T3b the templates called wp_kses_post()/pp_kses_inline()/esc_html()
 * directly (the test harness's wp_kses_post() passes content through), so every
 * stripped-construct, empty-prop, finding and markup-title assertion here fails there.
 */

use PHPUnit\Framework\TestCase;

class ContentRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
        unset($GLOBALS['post']);
        // The gate's request registry is process-wide: start every test with none noted.
        pp_content_end_vouch_action();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['post'], $GLOBALS['_pp_test_user_caps']);
        parent::tearDown();
    }

    private function section(string $body, array $extra = []): array
    {
        return ['component' => 'section', 'props' => array_merge(['title' => 'T', 'body' => $body], $extra)];
    }

    /** A page whose stored composition is exactly $composition (raw meta: legacy bytes). */
    private function page(array $composition): int
    {
        $post_id = pp_create_page('Render ' . uniqid('', true), 'draft');
        update_post_meta($post_id, '_pp_composition', wp_slash(wp_json_encode($composition)));
        return $post_id;
    }

    /**
     * Records `f` (full-tier) vouches for stored values, as a full-tier gated write would have:
     * for fixtures the gate itself refuses to write (cross-band references).
     */
    private function vouch(int $post_id, array $values): void
    {
        update_post_meta($post_id, PP_CONTENT_VERIFIED_META,
            wp_json_encode(array_map(static fn ($v) => 'f' . pp_content_value_hash($v), $values)));
    }

    /** The page's bands, rendered by the theme's own band loop. */
    private function render(int $post_id): string
    {
        $GLOBALS['post'] = (object) ['ID' => $post_id, 'post_type' => 'page', 'post_password' => ''];
        ob_start();
        pp_render_composition_bands(pp_get_composition($post_id));
        return (string) ob_get_clean();
    }

    private function findings(int $post_id, string $type): array
    {
        return array_values(array_filter(_pp_composition_findings(pp_get_composition($post_id), $post_id),
            static fn ($f) => $f['type'] === $type));
    }

    // ── §2.3: a stored construct is stripped, and the finding states the facts ──────────

    public function testAStoredExcludedConstructIsStrippedAndReported(): void
    {
        $id = $this->page([$this->section('<p onclick="steal()">kept text</p>')]);
        $html = $this->render($id);
        $this->assertStringContainsString('kept text', $html, 'the rest of the prop renders');
        $this->assertStringNotContainsString('onclick', $html, 'the construct is absent from the render');
        $this->assertStringNotContainsString('steal', $html);

        $found = $this->findings($id, 'content_stripped_at_render');
        $this->assertCount(1, $found);
        $this->assertSame('warning', $found[0]['severity']);
        $this->assertSame(0, $found[0]['index']);
        foreach (['"section"', 'prop "body"', 'renders without the refused constructs', 'onclick', 'E1'] as $fact) {
            $this->assertStringContainsString($fact, $found[0]['message']);
        }
    }

    public function testAStoredScriptBodyLeavesNoByteBehind(): void
    {
        $id = $this->page([$this->section('<p>before</p><script>var secretToken = 1;</script><p>after</p>')]);
        $html = $this->render($id);
        $this->assertStringContainsString('before', $html);
        $this->assertStringContainsString('after', $html);
        $this->assertStringNotContainsString('secretToken', $html, 'E4: the refused element goes WITH its body');
    }

    /** E10 and P-16 are whole-prop: the prop renders empty, the band and the next band render. */
    public static function wholePropShapes(): array
    {
        return [
            'E10 stray closer'           => ['x</div></section><p>outside</p>', 'E10'],
            'P-16 unclosed link'         => ['<p><a href="#">x</p>', 'P-16'],
            'P-16 misnested formatting'  => ['<p><b>Note</p><p>rest</p>', 'P-16'],
        ];
    }

    /** @dataProvider wholePropShapes */
    public function testAStoredWholePropFailureRendersEmptyAndTheBandStillRenders(string $body, string $clause): void
    {
        $id = $this->page([
            $this->section($body, ['title' => 'First band']),
            $this->section('<p>Next band body</p>', ['title' => 'Second band']),
        ]);
        $html = $this->render($id);
        $this->assertStringContainsString('First band', $html, 'the band renders');
        $this->assertStringContainsString('Next band body', $html, 'the next band renders');
        $this->assertStringNotContainsString('outside', $html, 'a stray closer is never a DOM node');
        $this->assertStringNotContainsString('Note', $html);
        $this->assertSame(2, substr_count($html, '<section'), 'no band boundary was broken');

        $found = $this->findings($id, 'content_stripped_at_render');
        $this->assertCount(1, $found);
        $this->assertSame(0, $found[0]['index']);
        $this->assertStringContainsString('renders EMPTY', $found[0]['message']);
        $this->assertStringContainsString('prop "body"', $found[0]['message']);
        $this->assertStringContainsString($clause, $found[0]['message']);
    }

    public function testAListPropFindingNamesTheItem(): void
    {
        $id = $this->page([['component' => 'faq', 'props' => ['title' => 'F', 'items' => [
            ['question' => 'Q1', 'answer' => '<p>fine</p>'],
            ['question' => 'Q2', 'answer' => 'x</div>'],
        ]]]]);
        $html = $this->render($id);
        $this->assertStringContainsString('fine', $html);
        $found = $this->findings($id, 'content_stripped_at_render');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('items item 1 field "answer"', $found[0]['message']);
    }

    public function testAStoredPropOverTheSizeCapRendersEmptyWithoutBeingWalked(): void
    {
        $big = '<p>' . str_repeat('a', PP_CONTENT_PROP_MAX_BYTES) . '</p>';
        $result = pp_content_sanitize($big, 'rich');
        $this->assertSame('', $result['html']);
        $this->assertSame('M-8', $result['losses'][0]['clause']);
        $id = $this->page([$this->section($big)]);
        $this->assertStringNotContainsString('aaaa', $this->render($id));
        $this->assertStringContainsString('M-8', $this->findings($id, 'content_stripped_at_render')[0]['message']);
    }

    public function testACleanPageCarriesNoRenderFinding(): void
    {
        $id = $this->page([$this->section('<p>Hello <strong>world</strong></p>')]);
        $this->assertStringContainsString('<p>Hello <strong>world</strong></p>', $this->render($id));
        $this->assertSame([], $this->findings($id, 'content_stripped_at_render'));
    }

    /** Admissions the old render dropped now render (§2.5 convergence, inline SVG and the P-2 inline set). */
    public function testWhatTheGateAdmitsTheRenderEmits(): void
    {
        // §14.1: written through the real authoring surface by a writer WordPress trusts with
        // unfiltered HTML, so the widened set (inline SVG) is vouched and renders.
        $id = pp_create_page('Admitted', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [
            $this->section('<p>Icon <svg viewBox="0 0 10 10" width="10"><circle cx="5" cy="5" r="4"/></svg></p>'),
            ['component' => 'cta', 'props' => ['title' => 'C', 'body' => 'H<sub>2</sub>O and <mark>this</mark>', 'button_text' => 'Go', 'button_url' => '/go']],
        ]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $html = $this->render($id);
        $this->assertStringContainsString('<svg viewBox="0 0 10 10" width="10"><circle cx="5" cy="5" r="4"/></svg>', $html);
        $this->assertStringContainsString('H<sub>2</sub>O and <mark>this</mark>', $html);
    }

    public function testAnOpenedLinkGetsNoopenerAtRender(): void
    {
        $id = $this->page([$this->section('<p><a href="https://example.org/" target="_blank">x</a></p>')]);
        $this->assertStringContainsString('target="_blank" rel="noopener"', $this->render($id));
    }

    // ── E12 at render: a cross-band reference is stripped, not left in place ────────────

    public function testACrossBandReferenceIsStrippedAtRender(): void
    {
        $id = $this->page([
            $this->section('<p aria-describedby="note">described</p><span id="own">a</span><p aria-labelledby="own">in band</p>'),
            $this->section('<p id="note">the note</p>'),
        ]);
        $html = $this->render($id);
        $this->assertStringNotContainsString('aria-describedby', $html, 'the out-of-band reference is dropped');
        $this->assertStringContainsString('aria-labelledby="own"', $html, 'an in-band reference stays');
        $this->assertStringNotContainsString('id="note"', $html, 'an id another band refers to is dropped too');
        $this->assertStringContainsString('the note', $html, 'the element itself renders');
        $this->assertCount(2, $this->findings($id, 'content_stripped_at_render'));
    }

    public function testAFragmentReferenceToAnotherBandIsStrippedAtRender(): void
    {
        $id = $this->page([
            $this->section('<svg viewBox="0 0 4 4"><rect width="4" height="4" fill="url(#g)"/><use href="#g"/></svg>'),
            $this->section('<svg viewBox="0 0 4 4"><linearGradient id="g"></linearGradient></svg>'),
        ]);
        $this->vouch($id, ['<svg viewBox="0 0 4 4"><rect width="4" height="4" fill="url(#g)"/><use href="#g"/></svg>',
            '<svg viewBox="0 0 4 4"><linearGradient id="g"></linearGradient></svg>']);
        $html = $this->render($id);
        $this->assertStringNotContainsString('url(#g)', $html);
        $this->assertStringNotContainsString('href="#g"', $html);
        $this->assertStringContainsString('<rect width="4" height="4"', $html, 'only the attribute goes');
    }

    // ── titles and headings: escaped, never empty; markup only when a gated write admitted it ──

    public function testAStoredTitleThatFailsThePredicateRendersEscapedNeverEmpty(): void
    {
        $title = 'The <code> element';
        $this->assertNotSame([], pp_content_sanitize($title, 'heading')['losses'], 'premise: it fails the predicate');
        $id = $this->page([$this->section('<p>b</p>', ['title' => $title])]);
        $html = $this->render($id);
        $this->assertStringContainsString('<h2 class="section__title">The &lt;code&gt; element</h2>', $html,
            'the rendered text equals the stored text character for character, and no element is created');

        $found = $this->findings($id, 'content_stripped_at_render');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('renders as plain text', $found[0]['message']);
        $census = pp_content_census([$id]);
        $this->assertSame(['text'], array_column($census, 'outcome'), 'and it is census-listed');
    }

    public function testAStoredMarkupTitleNoGatedWriteAdmittedRendersAsTheTextItWas(): void
    {
        // Unique bytes: the gate's request registry is process-wide, and another test in this
        // process may have had the plain spelling admitted.
        $u = uniqid();
        $title = 'The <span> element ' . $u;
        $this->assertSame([], pp_content_sanitize($title, 'heading')['losses'], 'premise: it PASSES the predicate');
        $id = $this->page([$this->section('<p>b</p>', ['title' => $title, 'subheading' => 'Use <em>this</em> ' . $u])]);
        $html = $this->render($id);
        $this->assertStringContainsString('The &lt;span&gt; element ' . $u, $html, 'stored intent: text');
        $this->assertStringContainsString('Use &lt;em&gt;this&lt;/em&gt; ' . $u, $html);
        $this->assertSame([], $this->findings($id, 'content_stripped_at_render'), 'it renders as it always did: nothing to fix');
        $rows = pp_content_census([$id]);
        $this->assertSame(['unverified', 'unverified'], array_column($rows, 'clause'), 'the census lists it');
    }

    /** §14.1: the marker is set ONLY by a gated write, through the real authoring surface. */
    public function testAGatedWriteAdmitsMarkupInATitleAndTheRenderShowsIt(): void
    {
        $post_id = pp_create_page('Gated title', 'draft');
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            $this->section('<p>b</p>', ['title' => 'H<sub>2</sub>O matters', 'subheading' => 'It is <strong>wet</strong>']),
        ]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $html = $this->render($post_id);
        $this->assertStringContainsString('<h2 class="section__title">H<sub>2</sub>O matters</h2>', $html);
        $this->assertStringContainsString('It is <strong>wet</strong>', $html);
        $this->assertSame([], pp_content_census([$post_id]));
    }

    public function testARestoreNeverVerifiesATitle(): void
    {
        $post_id = pp_create_page('Restore', 'draft');
        $u = uniqid();
        $composition = [$this->section('<p>b</p>', ['title' => 'A <em>b</em> ' . $u])];
        // The writer, called the way restore calls it: no gate admitted these bytes.
        $this->assertTrue(pp_update_composition($post_id, $composition));
        $this->assertStringContainsString('A &lt;em&gt;b&lt;/em&gt; ' . $u, $this->render($post_id));
        $this->assertSame(['h' => [], 'f' => []], pp_content_vouched($post_id));
    }

    public function testAnUnchangedBandIsNotVerifiedBySomeoneElsesEdit(): void
    {
        $u = uniqid();
        $post_id = $this->page([$this->section('<p>b</p>', ['title' => 'Old <em>x</em> ' . $u])]);
        $stored = pp_get_composition($post_id);
        $stored[] = $this->section('<p>new</p>', ['title' => 'New <em>y</em>']);
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => $stored]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $html = $this->render($post_id);
        $this->assertStringContainsString('Old &lt;em&gt;x&lt;/em&gt; ' . $u, $html, 'M-2: the unchanged band was not judged');
        $this->assertStringContainsString('New <em>y</em>', $html, 'the judged band was');
    }

    public function testTheVouchedSetIsBoundedAndKeepsWhatThePageHolds(): void
    {
        $post_id = pp_create_page('Bound', 'draft');
        $old = [];
        for ($n = 0; $n < PP_CONTENT_VERIFIED_MAX; $n++) {
            $old[] = 'f' . pp_content_value_hash('old ' . $n);
        }
        update_post_meta($post_id, PP_CONTENT_VERIFIED_META, wp_json_encode($old));
        $band = $this->section('<p>b</p>', ['title' => 'Kept <em>k</em>']);
        pp_content_note_vouches(pp_content_band_vouches($band, [], 'full'), pp_content_composition_scope([$band]));
        pp_content_bind_vouch_target($post_id);
        pp_content_record_verified($post_id, [$band]);
        pp_content_end_vouch_action();
        $set = pp_content_vouched($post_id);
        $this->assertCount(PP_CONTENT_VERIFIED_MAX, json_decode(get_post_meta($post_id, PP_CONTENT_VERIFIED_META, true), true), 'bounded');
        $this->assertArrayHasKey(pp_content_value_hash('Kept <em>k</em>'), $set['h'], 'the composition\'s own heading is kept as markup');
        $this->assertArrayHasKey(pp_content_value_hash('<p>b</p>'), $set['f'], 'and its body for the widened set');
    }

    // ── title_accent on the parsed title (routed item 13) ──────────────────────────────

    public function testTheAccentWrapsTextNodesOnlyAndNeverSplitsATag(): void
    {
        $post_id = pp_create_page('Accent', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            $this->section('<p>b</p>', ['title' => 'Make <span class="k">fast</span> sites &amp; more', 'title_accent' => 'fast']),
            $this->section('<p>b</p>', ['title' => 'Make <span class="k">fast</span> sites', 'title_accent' => 'span class']),
            $this->section('<p>b</p>', ['title' => 'Tom <em>&amp;</em> Jerry', 'title_accent' => '&']),
        ]])['ok']);
        $html = $this->render($post_id);
        $this->assertStringContainsString(
            'Make <span class="k"><span class="section__title-accent">fast</span></span> sites &amp; more', $html,
            'the accent wraps the matched text inside its text node');
        $this->assertStringContainsString('<h2 class="section__title">Make <span class="k">fast</span> sites</h2>', $html,
            'an accent that only matches tag bytes matches nothing: no tag is split');
        $this->assertStringContainsString('Tom <em><span class="section__title-accent">&amp;</span></em> Jerry', $html,
            'the accent is matched on DECODED text');
    }

    public function testAnEscapedTitleKeepsTheLegacyAccentByteForByte(): void
    {
        $id = $this->page([$this->section('<p>b</p>', ['title' => 'Plain fast title', 'title_accent' => 'fast'])]);
        $this->assertStringContainsString(
            'Plain <span class="section__title-accent">fast</span> title', $this->render($id));
    }

    // ── context and legacy paths ───────────────────────────────────────────────────────

    public function testOutsideACompositionContextTheLegacyRenderIsUnchanged(): void
    {
        // page.php hands `the_content` to a section body: WordPress data, not stored bytes.
        $this->assertFalse(pp_content_render_active());
        ob_start();
        pp_get_component('section', ['title' => 'A <b>t</b>', 'body' => '<iframe src="https://www.youtube.com/embed/x"></iframe>']);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('<iframe', $html, 'the harness\'s wp_kses_post passes it, so the predicate did not run');
        $this->assertStringContainsString('A &lt;b&gt;t&lt;/b&gt;', $html);
    }

    public function testTheRenderContextIsClosedAfterTheLoop(): void
    {
        $id = $this->page([$this->section('<p>b</p>')]);
        $this->render($id);
        $this->assertFalse(pp_content_render_active());
    }

    public function testAStoredNumberStillRendersItsDigits(): void
    {
        $id = $this->page([['component' => 'logos', 'props' => ['title' => 2026, 'items' => []]]]);
        $this->assertStringContainsString('2026', $this->render($id));
    }

    // ── the other findings ─────────────────────────────────────────────────────────────

    public function testAnIdInTwoBandsIsDisclosed(): void
    {
        $id = $this->page([
            $this->section('<p id="shared">a</p>'),
            $this->section('<p id="shared">b</p>'),
            $this->section('<p id="alone">c</p>'),
        ]);
        $found = $this->findings($id, 'content_duplicate_id');
        $this->assertCount(1, $found);
        $this->assertSame('warning', $found[0]['severity']);
        $this->assertSame(1, $found[0]['index']);
        $this->assertStringContainsString('"shared"', $found[0]['message']);
        $this->assertStringContainsString('#0, #1', $found[0]['message']);
    }

    public function testContentStyleDeclarationsAndPopoversAreAnInfoNote(): void
    {
        $id = $this->page([
            $this->section('<p style="color: #123456; margin-top: 2rem">a</p><div popover id="tip">t</div>'),
            $this->section('<p>plain</p>'),
        ]);
        $this->vouch($id, ['<p style="color: #123456; margin-top: 2rem">a</p><div popover id="tip">t</div>']);
        $found = $this->findings($id, 'content_inline_style');
        $this->assertCount(1, $found);
        $this->assertSame('info', $found[0]['severity']);
        $this->assertSame(0, $found[0]['index']);
        foreach (['2 style declaration(s)', 'color', 'margin-top', '1 popover element(s) (<div>)'] as $fact) {
            $this->assertStringContainsString($fact, $found[0]['message']);
        }
    }

    // ── the editor preview (§2.6 last bullet) ──────────────────────────────────────────

    public function testThePreviewShowsAJustTypedMarkupTitleAsTheSaveWill(): void
    {
        $u = uniqid();
        $post_id = $this->page([$this->section('<p>b</p>', ['title' => 'Stored <em>s</em> ' . $u])]);
        $sent = pp_get_composition($post_id);
        $sent[] = $this->section('<p>b</p>', ['title' => 'Typed <sup>t</sup>']);
        pp_content_note_judged_bands($sent, pp_content_stored_baseline($post_id));
        pp_content_render_begin($sent, $post_id);
        try {
            ob_start();
            foreach ($sent as $k => $band) {
                pp_content_render_band($k);
                pp_get_component($band['component'], $band['props']);
            }
            $html = (string) ob_get_clean();
        } finally {
            pp_content_render_end();
        }
        $this->assertStringContainsString('Typed <sup>t</sup>', $html, 'the changed band renders as it will once saved');
        $this->assertStringContainsString('Stored &lt;em&gt;s&lt;/em&gt; ' . $u, $html, 'the unchanged stored band does not');
    }

    // ── /review cycle 1 regressions ─────────────────────────────────────────────────────

    public function testAnInBandFragmentSharingAPrefixWithAnOutOfBandTargetSurvives(): void
    {
        $id = $this->page([
            $this->section('<svg viewBox="0 0 4 4"><linearGradient id="gallery"></linearGradient><use href="#gallery"/><use href="#g"/></svg>'),
            $this->section('<svg viewBox="0 0 4 4"><linearGradient id="g"></linearGradient></svg>'),
        ]);
        $this->vouch($id, ['<svg viewBox="0 0 4 4"><linearGradient id="gallery"></linearGradient><use href="#gallery"/><use href="#g"/></svg>',
            '<svg viewBox="0 0 4 4"><linearGradient id="g"></linearGradient></svg>']);
        $html = $this->render($id);
        $this->assertStringNotContainsString('href="#g"', $html);
        $this->assertStringContainsString('<use href="#gallery"/>', $html, '`#g` inside `#gallery` is another id');
    }

    public function testARefusedWriteNeverVerifiesATitleForALaterRestore(): void
    {
        $title = 'Old <em>x</em> ' . uniqid();
        $orig = [$this->section('<p>b</p>', ['title' => $title])];
        $post_id = $this->page([]);
        $r = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            $this->section('<p>changed</p>', ['title' => $title]),
            $this->section('<p onclick="x()">bad</p>'),
        ]]);
        $this->assertFalse($r['ok'], 'premise: the write is refused');
        $this->assertTrue(pp_update_composition($post_id, $orig), 'a restore-style write in the same request');
        $this->assertSame(['h' => [], 'f' => []], pp_content_vouched($post_id));
    }

    public function testEditingAnotherPropNeverVerifiesAnUntouchedLegacyTitle(): void
    {
        $title = 'Use <br> for line breaks ' . uniqid();
        $post_id = $this->page([$this->section('<p>old body</p>', ['title' => $title])]);
        $r = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            $this->section('<p>new body</p>', ['title' => $title]),
        ]]);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertStringContainsString('Use &lt;br&gt; for line breaks', $this->render($post_id),
            'the band was judged, but the title was re-sent unchanged: it stays the text it was');

        $edited = 'Use <em>br</em> ' . uniqid();
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [
            $this->section('<p>new body</p>', ['title' => $edited]),
        ]])['ok']);
        $this->assertStringContainsString(str_replace('<em>br</em>', '<em>br</em>', $edited), $this->render($post_id), 'an edited title is admitted');
    }

    public function testShortcodeAttributesReachTheShortcodeWithTheirQuotes(): void
    {
        $id = $this->page([['component' => 'embed', 'props' => ['title' => 'E', 'content' => '<p>[form id="123" title=\'Contact us\']</p>']]]);
        $this->assertStringContainsString('[form id="123" title=\'Contact us\']', $this->render($id),
            'do_shortcode() parses this view: quotes in text stay literal');
    }

    public function testTheRenderContextClosesWhenAComponentThrows(): void
    {
        // A component whose template throws, from a throwaway theme directory (the
        // PostApplyValidateTest pattern): the loop's finally must still close the context.
        $dir = sys_get_temp_dir() . '/pp-t3b-throw-' . getmypid() . '-' . mt_rand();
        mkdir($dir . '/components/boom', 0755, true);
        file_put_contents($dir . '/components/boom/boom.php', '<?php throw new RuntimeException("boom");');
        $GLOBALS['_pp_test_template_dir'] = $dir;
        $thrown = false;
        ob_start();
        try {
            pp_render_composition_bands([['component' => 'boom', 'props' => []]]);
        } catch (RuntimeException $e) {
            $thrown = true;
        } finally {
            ob_end_clean();
            unset($GLOBALS['_pp_test_template_dir']);
            unlink($dir . '/components/boom/boom.php');
            rmdir($dir . '/components/boom');
            rmdir($dir . '/components');
            rmdir($dir);
        }
        $this->assertTrue($thrown, 'premise: the render threw');
        $this->assertFalse(pp_content_render_active(), 'no later render inherits this composition');
    }

    public function testADuplicateIdTheRenderDropsIsNotReported(): void
    {
        $id = $this->page([
            $this->section('<p id="dup">a</p>'),
            $this->section('<p id="dup">b</p>'),
            $this->section('<p aria-describedby="dup">c</p>'),
        ]);
        $html = $this->render($id);
        $this->assertStringNotContainsString('id="dup"', $html, 'premise: E12 drops both ids at render');
        $this->assertSame([], $this->findings($id, 'content_duplicate_id'), 'findings read the rendered html');
    }

    // ── ruling Q1: stored bytes render at core parity unless a full-tier gated write vouched ──

    public function testUnvouchedStoredContentRendersAtCoreParityAndSaysSo(): void
    {
        $body = '<div popover style="position: fixed; inset: 0">overlay</div><svg viewBox="0 0 4 4"><circle cx="2" cy="2" r="1"/></svg><p>kept <strong>text</strong></p>';
        $this->assertSame([], pp_content_sanitize($body, 'rich', ['tier' => 'full'])['losses'], 'premise: the full tier admits it');
        $id = $this->page([$this->section($body)]);
        $html = $this->render($id);
        $this->assertStringContainsString('<p>kept <strong>text</strong></p>', $html, 'core content renders');
        $this->assertStringNotContainsString('<svg', $html, 'the widened set needs a trusted, checked write');
        // Core parity, exactly: what core's own `post` list and CSS filter keep stays (WordPress
        // 7.0 lists `popover`), the same as the pre-Layer-3 wp_kses_post render showed.
        $found = $this->findings($id, 'content_stripped_at_render');
        $this->assertCount(1, $found);
        $this->assertStringContainsString('unfiltered_html', $found[0]['message']);
        $this->assertSame([['stripped', 'unfiltered_html']], array_map(static fn ($r) => [$r['outcome'], $r['clause']], pp_content_census([$id])));
    }

    public function testAFullTierEditVouchesOnlyTheBytesItSends(): void
    {
        $legacy = '<p>Legacy icon <svg viewBox="0 0 4 4"><circle cx="2" cy="2" r="1"/></svg></p>';
        $id = $this->page([$this->section($legacy, ['title' => 'Old'])]);
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [
            $this->section($legacy, ['title' => 'Edited title']),
            $this->section('<p>New icon <svg viewBox="0 0 4 4"><rect width="2" height="2"/></svg></p>'),
        ]])['ok']);
        $html = $this->render($id);
        $this->assertStringContainsString('<rect width="2" height="2"/>', $html, 'new bytes from a trusted writer: widened');
        $this->assertStringNotContainsString('<circle', $html, 'the untouched legacy body still renders at core parity');
    }

    public function testACoreTierWriteNeverVouchesTheWidenedSet(): void
    {
        $GLOBALS['_pp_test_user_caps'] = ['unfiltered_html' => false];
        $id = pp_create_page('Core writer', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [
            $this->section('<p>Plain <strong>copy</strong></p>', ['title' => 'H<sub>2</sub>O']),
        ]])['ok']);
        $vouched = pp_content_vouched($id);
        $this->assertSame([], $vouched['f'], 'nothing vouched for the widened set');
        $this->assertNotSame([], $vouched['h'], 'the title markup is still admitted (core parity holds sub)');
        $this->assertStringContainsString('H<sub>2</sub>O', $this->render($id));
    }

    // ── ruling Q2: one page view walks at most the render budget ─────────────────────────

    public function testContentPastTheRenderBudgetRendersThroughThePreLayer3PathAndIsReported(): void
    {
        $cells = array_fill(0, PP_CONTENT_RENDER_MAX_VALUES + 3, 'c');
        $rows = array_chunk($cells, 8);
        $id = $this->page([
            $this->section('<p>first band</p>'),
            ['component' => 'table', 'props' => ['title' => 'Big', 'headers' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 'rows' => $rows]],
        ]);
        $found = $this->findings($id, 'content_not_checked');
        $this->assertCount(1, $found);
        $this->assertSame('warning', $found[0]['severity']);
        $this->assertSame(1, $found[0]['index'], 'it names the band past the budget');
        $this->assertContains('unchecked', array_column(pp_content_census([$id]), 'outcome'));
        $this->assertStringContainsString('first band', $this->render($id), 'the page still renders');
    }

    public function testThePreviewVouchesForNothingAWriteWouldRefuseAsTooLarge(): void
    {
        $post_id = $this->page([]);
        $sent = [$this->section('<p>' . str_repeat('a', PP_CONTENT_PROP_MAX_BYTES) . '</p>', ['title' => 'Big <em>t</em> ' . uniqid()])];
        pp_content_note_judged_bands($sent, []);
        $registry = _pp_content_verified_registry();
        $this->assertSame([[], []], [$registry['h'], $registry['f']], 'the write caps apply to the preview');
        $ok = [$this->section('<p>small</p>', ['title' => 'Small <em>t</em> ' . uniqid()])];
        pp_content_note_judged_bands($ok, []);
        $this->assertNotSame([], _pp_content_verified_registry()['h'], 'control: a write-sized preview vouches');
    }

    /** Ruling Q1 inside the predicate's render view: core parity, exactly (never more, never less). */
    public static function coreParityShapes(): array
    {
        return [
            'a data: image src'            => ['<img src="data:image/png;base64,iVBORw0KGgo=" alt="">', '<img alt="">'],
            'an app-scheme link'           => ['<a href="sip:123">call</a>', '<a>call</a>'],
            'an attribute core does not list' => ['<p translate="no">x</p>', '<p>x</p>'],
            'a style core filters in part' => ['<p style="color:#fff;box-sizing:border-box">x</p>', '<p style="color: #fff">x</p>'],
            'an element beyond core: unwrapped, its text kept' => ['<p>Hello <x-widget>World</x-widget> end</p>', '<p>Hello World end</p>'],
            'inline SVG text: unwrapped' => ['<p>a<svg viewBox="0 0 4 4"><text>T</text></svg>b</p>', '<p>aTb</p>'],
        ];
    }

    /** @dataProvider coreParityShapes */
    public function testTheCoreTierRenderViewKeepsExactlyWhatCoreKeeps(string $stored, string $expected): void
    {
        $r = pp_content_sanitize($stored, 'rich', ['tier' => 'core']);
        $this->assertSame($expected, $r['html']);
        $this->assertSame(['unfiltered_html'], array_values(array_unique(array_column($r['losses'], 'clause'))));
    }

    public function testATemplateCopyOfAValuePastTheBudgetTakesTheLegacyPathToo(): void
    {
        $rows = array_chunk(array_fill(0, PP_CONTENT_RENDER_MAX_VALUES + 3, 'c'), 8);
        $id = $this->page([
            ['component' => 'table', 'props' => ['title' => 'Big', 'headers' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 'rows' => $rows]],
            // hero trims `proof` before rendering it, so the template's bytes miss the stored ones
            ['component' => 'hero', 'props' => ['title' => 'H', 'layout' => 'split', 'proof' => '  <p onclick="legacyMarker()">proof</p>  ']],
        ]);
        $html = $this->render($id);
        $this->assertStringContainsString('legacyMarker()', $html,
            'past the budget the copy is not walked: the harness\'s wp_kses_post passes it unchanged');
    }

    // ── /review cycle 2 regressions ─────────────────────────────────────────────────────

    public function testAMarkupTitlePastTheBudgetRendersAsTextNeverFatal(): void
    {
        $rows = array_chunk(array_fill(0, PP_CONTENT_RENDER_MAX_VALUES + 3, 'c'), 8);
        $id = $this->page([
            ['component' => 'table', 'props' => ['title' => 'Big', 'headers' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 'rows' => $rows]],
            $this->section('<p>x</p>', ['title' => 'A <em>b</em>', 'title_accent' => 'b']),
        ]);
        $html = $this->render($id);
        $this->assertStringContainsString('A &lt;em&gt;<span class="section__title-accent">b</span>&lt;/em&gt;', $html);
    }

    public function testAVouchedHeroProofKeepsItsWidenedMarkupDespiteTheTemplatesTrim(): void
    {
        $id = pp_create_page('Proof', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [
            ['component' => 'hero', 'props' => ['title' => 'H', 'proof' => "<p><svg viewBox=\"0 0 4 4\"><rect width=\"2\" height=\"2\"/></svg> Trusted</p>\n"]],
        ]])['ok']);
        $this->assertStringContainsString('<rect width="2" height="2"/>', $this->render($id));
    }

    public function testAFailedCommitSpendsItsVouchNotes(): void
    {
        $id = $this->page([]);
        $band = $this->section('<p>b</p>', ['title' => 'Noted <em>n</em> ' . uniqid()]);
        pp_content_note_vouches(pp_content_band_vouches($band, [], 'full'), pp_content_composition_scope([$band]));
        pp_content_bind_vouch_target($id);
        $this->assertTrue(is_wp_error(pp_update_composition($id, [$band], 999)), 'premise: a stale expected version refuses');
        $this->assertTrue(pp_update_composition($id, [$band]), 'a later restore-style write in the same request');
        pp_content_end_vouch_action();
        $this->assertSame(['h' => [], 'f' => []], pp_content_vouched($id));
    }

    // ── cycle 4: a validation's notes vouch only for the write they were judged for ──────

    public function testAValidationWhoseActionNeverWroteVouchesForNoOtherWrite(): void
    {
        $title = 'The <em>x</em> element ' . uniqid();
        $legacy = [$this->section('<p>b</p>', ['title' => $title])];
        $b = $this->page($legacy);
        // A gated validation passes (its action then refuses before any write) ...
        $this->assertTrue(pp_validate_composition([$this->section('<p>other</p>', ['title' => $title])], []));
        // ... and a later write in the same request stores B's legacy heading unchanged.
        $this->assertTrue(pp_update_composition($b, $legacy));
        $this->assertSame(['h' => [], 'f' => []], pp_content_vouched($b));
        $this->assertStringContainsString('The &lt;em&gt;x&lt;/em&gt; element', $this->render($b), 'still the text it was');
    }

    /**
     * Cycle 5 (the focused cycle-4 pass's reproduction): a batch step whose gate passes and
     * whose action then refuses (the posts-listing rule) must not vouch for the rollback's
     * restore of a page holding the same content.
     */
    public function testABatchRollbackNeverRecordsARefusedStepsVouches(): void
    {
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        try {
            $legacy = [$this->section('<p>b</p>', ['title' => 'Leg <em>z</em> ' . uniqid()])];
            $a = $this->page($legacy);
            $b = pp_create_page('B', 'draft');
            $this->assertTrue(pp_update_composition($b, [$this->section('<p>p</p>')]));
            $r = pp_ai_execute_batch([
                ['type' => 'action', 'name' => 'update_composition', 'params' => ['post_id' => $a, 'composition' => [$this->section('<p>o</p>')]]],
                ['type' => 'action', 'name' => 'update_composition', 'params' => ['post_id' => $b,
                    'composition' => array_merge($legacy, [['component' => 'grid', 'props' => ['items_source' => 'posts', 'items' => []]]])]],
            ]);
            $this->assertTrue($r['rolled_back'] ?? false, 'premise: the second step is refused and the batch rolls back');
            $vouched = pp_content_vouched($a);
            $this->assertSame([], $vouched['h'], 'the rollback restore vouches for no heading');
            foreach (pp_content_band_values($legacy[0]) as [, , , $value]) {
                $this->assertArrayNotHasKey(pp_content_value_hash($value), $vouched['f'], 'nor for any restored legacy value');
            }
            $this->assertStringContainsString('Leg &lt;em&gt;z&lt;/em&gt;', $this->render($a), 'A\'s legacy title is still the text it was');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function testAnOrdinaryGatedWriteStillVouchesForItsOwnPage(): void
    {
        $id = pp_create_page('Own page', 'draft');
        $other = pp_create_page('Other page', 'draft');
        $band = $this->section('<p>b</p>', ['title' => 'Mine <sup>1</sup> ' . uniqid()]);
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [$band]])['ok']);
        $this->assertNotSame([], pp_content_vouched($id)['h']);
        $this->assertTrue(pp_update_composition($other, [$band]), 'the same bytes written to another page afterwards');
        $this->assertSame(['h' => [], 'f' => []], pp_content_vouched($other), 'the notes were spent: no other page gains them');
    }

    public function testNotesBoundToOnePageNeverRecordOnAnother(): void
    {
        $a = pp_create_page('A', 'draft');
        $b = pp_create_page('B', 'draft');
        $band = $this->section('<p>b</p>', ['title' => 'Bound <em>x</em> ' . uniqid()]);
        pp_content_note_vouches(pp_content_band_vouches($band, [], 'full'), pp_content_composition_scope([$band]));
        pp_content_bind_vouch_target($a);
        $this->assertTrue(pp_update_composition($b, [$band]), 'the same composition, written to another page');
        pp_content_end_vouch_action();
        $this->assertSame(['h' => [], 'f' => []], pp_content_vouched($b));
    }

    // ── every gated write path still vouches for its own page (cycle 5) ────────────────

    public function testCreatePageVouchesForThePageItCreates(): void
    {
        $t = 'New <em>p</em> ' . uniqid();
        $r = pp_execute_action('create_page', ['title' => 'CP', 'composition' => [$this->section('<p>b</p>', ['title' => $t])]]);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertArrayHasKey(pp_content_value_hash($t), pp_content_vouched((int) $r['target']['post_id'])['h']);
    }

    public function testAddComponentVouchesAtAPosition(): void
    {
        $id = pp_create_page('AC', 'draft');
        $this->assertTrue(pp_update_composition($id, [$this->section('<p>a</p>')]));
        $t = 'Added <em>a</em> ' . uniqid();
        $r = pp_execute_action('add_component', ['post_id' => $id, 'component' => 'section', 'props' => ['title' => $t, 'body' => '<p>n</p>'], 'position' => 0]);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertArrayHasKey(pp_content_value_hash($t), pp_content_vouched($id)['h']);
    }

    public function testUpdateComponentVouchesForTheBandItEdits(): void
    {
        $id = pp_create_page('UC', 'draft');
        $this->assertTrue(pp_update_composition($id, [$this->section('<p>a</p>')]));
        $t = 'Edited <em>e</em> ' . uniqid();
        $r = pp_execute_action('update_component', ['post_id' => $id, 'component_index' => 0, 'props' => ['title' => $t]]);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertArrayHasKey(pp_content_value_hash($t), pp_content_vouched($id)['h']);
    }

    public function testAPreviewOrAStandaloneValidationVouchesForNoLaterWrite(): void
    {
        $id = pp_create_page('PV', 'draft');
        $band = $this->section('<p>b</p>', ['title' => 'Previewed <em>p</em> ' . uniqid()]);
        pp_preview_action('update_composition', ['post_id' => $id, 'composition' => [$band]]);
        $this->assertTrue(pp_update_composition($id, [$band]), 'an ungated write of the same composition');
        $this->assertSame(['h' => [], 'f' => []], pp_content_vouched($id));

        $this->assertTrue(pp_validate_composition([$band], []), 'a gated validation outside any action');
        // Any action, accepted or refused, starts its vouch lifecycle clean.
        pp_execute_action('reorder_components', ['post_id' => $id, 'order' => [0]]);
        $this->assertTrue(pp_update_composition($id, [$band]), 'then an ungated write of the same composition');
        $this->assertSame(['h' => [], 'f' => []], pp_content_vouched($id), 'an ungated action starts its lifecycle clean');
    }
}
