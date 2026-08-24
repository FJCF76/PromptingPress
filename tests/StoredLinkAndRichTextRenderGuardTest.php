<?php
/**
 * tests/StoredLinkAndRichTextRenderGuardTest.php
 *
 * #730 — a stored non-string LINK or RICH-TEXT prop must never fatal the public page
 *        through WordPress CORE's own escapers, esc_url() and wp_kses_post().
 * #739 — a stored non-array faq `items` must never fatal the public page through
 *        pp_render_faq_schema(array $items), which sits OUTSIDE faq's !empty() gate.
 *
 * The fifth and sixth boundaries in this family, landed together because they meet in
 * components/faq/faq.php: #739 guards the items CONTAINER, #730 guards the `answer`
 * ELEMENT inside it. Siblings, in landing order:
 *   tests/StoredImageUrlRenderGuardTest.php          (#641, image_url)
 *   tests/StoredBackgroundImageRenderGuardTest.php   (#705, background_image)
 *   tests/StoredTitleRenderGuardTest.php             (#706, title / title_accent)
 *   tests/StoredStyleAndItemsRenderGuardTest.php     (#708, __pp_style / grid items)
 *
 * WHAT MAKES THIS PAIR DIFFERENT FROM THE FIRST FOUR. Every earlier boundary was one of
 * the THEME's own typed helpers, where the signature announces the danger. These two are
 * WordPress core functions that declare NO parameter type at all:
 *
 *   wp-includes/formatting.php   function esc_url( $url, $protocols = null, $_context = 'display' )
 *   wp-includes/kses.php         function wp_kses_post( $data )
 *
 * They fatal anyway, because each reaches a string-only PHP builtin before it makes any
 * sanitization decision. Measured on real WordPress 7.0 / PHP 8.3.31, one FRESH PROCESS
 * per case (see the trap below), during #709:
 *
 *   | input                  | esc_html/esc_attr   | esc_url             | wp_kses_post        |
 *   |------------------------|---------------------|---------------------|---------------------|
 *   | array                  | 'Array' + E_WARNING | FATAL ltrim()       | FATAL str_contains()|
 *   | object, no __toString  | FATAL (string) cast | FATAL ltrim()       | FATAL preg_replace()|
 *   | int / float / bool     | coerces             | coerces             | coerces             |
 *
 * So esc_html/esc_attr coerce-and-warn while esc_url/wp_kses_post genuinely 500 the page.
 * That row split is the whole admitting criterion for this issue, and it CORRECTS the
 * premise this family was founded on (#709's "only the theme's own typed helpers and PHP
 * builtins are production-fatal"), which is recorded on #705 as a binding amendment.
 *
 * TWO MEASUREMENT TRAPS, both of which produce FALSE NEGATIVES rather than noisy failures,
 * which is why they are written down instead of merely avoided:
 *
 *   1. wp_pre_kses_block_attributes() calls remove_filter('pre_kses', …), then
 *      filter_block_content(), then the matching add_filter(). When the middle step
 *      THROWS, the re-add never runs, so every LATER wp_kses_post() in that request
 *      silently passes through unsanitized. A probe that catches the first throw and
 *      keeps going reports every subsequent surface as safe. Measure one process per
 *      case. This is also exactly why the recorded ruling forbids try/catch as the fix.
 *   2. phpunit.xml sets failOnWarning="false", and esc_html/esc_attr render a stored
 *      array as the literal string `Array` WITHOUT fataling. A test that only proved
 *      "nothing threw" would pass against a coercing implementation that quietly paints
 *      the word Array where a link or a paragraph belongs. Every assertion below is
 *      therefore AFFIRMATIVE about the emitted HTML, and the degradation cases compare
 *      byte-for-byte against a control render of a band that stored nothing at all.
 *
 * TWO STORAGE CHANNELS, AND THEY CARRY DIFFERENT SHAPES. This matters enough to shape the
 * test matrix rather than being a footnote:
 *
 *   JSON channel  — the normal one. pp_get_composition_result() json_decode()s the meta
 *                   string with assoc=true, so it can only ever produce
 *                   null|bool|int|float|string|array. AN OBJECT IS UNREACHABLE THROUGH IT.
 *   array channel — pp_get_composition_result() also accepts an ALREADY-DECODED array
 *                   from meta ("a caller/fixture may have persisted an already-decoded
 *                   array"). WordPress serializes array-valued meta with PHP serialize(),
 *                   which DOES carry objects. This is the only route to the object rows.
 *
 * Both are exercised. A file that tested only the JSON channel would silently skip half
 * the measured fatal matrix and still read as complete.
 *
 * THE GUARDS, and why the predicate differs between the two issues:
 *
 *   #730  is_scalar($raw) ? (string) $raw : ''     — a STRING is the contract at these
 *         sinks, PHP runs coercive (no declare(strict_types) anywhere in this theme), so
 *         only non-scalars ever fataled and is_string would drop STORED scalars (#707
 *         closed the front door on new ones; it migrated none of the existing ones).
 *   #739  is_array($raw) ? $raw : []                — an ARRAY is the contract at
 *         pp_render_faq_schema(array $items), so every non-array fatals and the
 *         shape-appropriate predicate is the #708 one.
 *
 * Both are the same ratified idiom in its two stated forms, not a deviation.
 *
 *   stored props ──> pp_get_composition() ──> pp_get_component()
 *                    (plain decode, no          │
 *                     sanitising)               ├─ is_scalar($raw_button_url)  ──> esc_url()      (cta, hero)
 *                                               ├─ is_scalar($raw_panel_cta_url) ─┐
 *                                               │     └─ + is_scalar in $has_panel_cta gate ──> no button
 *                                               ├─ is_scalar($raw_link_url)    ──> esc_url()      (grid card)
 *                                               ├─ is_scalar($raw_body)        ──> wp_kses_post() (section x3)
 *                                               ├─ is_scalar($raw_answer)      ──> wp_kses_post() (faq item)
 *                                               ├─ is_scalar($raw_cell)        ──> wp_kses_post() (table cell)
 *                                               ├─ is_scalar($raw_content)     ──> wp_kses_post() (embed)
 *                                               └─ is_array($raw_items)        ──> pp_render_faq_schema()
 *
 * DEGRADE, NEVER REWRITE. Nothing here touches stored data. The value stays as stored,
 * the operator diagnostic still names it, and the band renders without the fragment.
 */

use PHPUnit\Framework\TestCase;

class StoredLinkAndRichTextRenderGuardTest extends TestCase
{
    /**
     * The complete #730 inventory: every component read that carries a stored value into
     * core's esc_url() or wp_kses_post().
     *
     * RE-DERIVED FROM SOURCE, NOT COPIED FROM THE ISSUE, and the two disagree. The filed
     * body listed "footer logo/social URLs" among the reachable surfaces; measured, both
     * are already safe and are asserted as such in
     * testTheExcludedSurfacesAreSafeByMeasurementNotByAssumption(). Carrying the issue's
     * list verbatim would have added two guards that close nothing and would have implied
     * a reachability that does not exist.
     *
     * Coupled to the source by testTheGuardedCallSiteInventoryMatchesTheSource() rather
     * than by a comment promising they agree.
     *
     * @var array<string, array{0:string, 1:string, 2:string}>  label => [component, sink, guarded local]
     */
    private const GUARDED_SURFACES = [
        'cta.button_url'         => ['cta',     'esc_url',      '$button_url'],
        'cta.button2_url'        => ['cta',     'esc_url',      '$button2_url'],
        'hero.button_url'        => ['hero',    'esc_url',      '$button_url'],
        'hero.button2_url'       => ['hero',    'esc_url',      '$button2_url'],
        'section.panel_cta_url'  => ['section', 'esc_url',      '$panel_cta_url'],
        'grid.items[].link_url'  => ['grid',    'esc_url',      '$link_url'],
        'section.body'           => ['section', 'wp_kses_post', '$body'],
        'faq.items[].answer'     => ['faq',     'wp_kses_post', '$answer'],
        'table.rows[][]'         => ['table',   'wp_kses_post', '$cell'],
        'embed.content'          => ['embed',   'wp_kses_post', '$content'],
    ];

    /**
     * Call sites that reach a core escaper WITHOUT a stored-prop value, each with its
     * warrant. Kept beside the inventory because the equality assertion below needs both
     * halves to describe the complete real call-site set.
     *
     * @var array<int, string>  component|sink|argument
     */
    private const EXEMPT_CALL_SITES = [
        // A theme function, not a prop.
        'footer|esc_url|pp_site_url()',
        'nav|esc_url|pp_site_url()',
        // pp_resolve_logo() sources `url` from wp_get_attachment_image_url(), which
        // returns string|false — never a stored prop value.
        "footer|esc_url|\$logo['url']",
        "nav|esc_url|\$logo['url']",
        // footer's social decode loop skips non-array entries and (string)-casts the url
        // before the escaper sees it.
        "footer|esc_url|\$social_item['url']",
        // DELIBERATELY UNGUARDED: hero's $proof_markup is `trim((string) $proof)`, so the
        // value arriving at the escaper is ALREADY a string and this site cannot fatal.
        // That prop's fatal is upstream at the cast and object-only — a language
        // construct, not a core escaper. Belongs to the open #721.
        'hero|wp_kses_post|$proof_markup',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStore();
    }

    private function resetStore(): void
    {
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'next_id'    => 100,
            'custom_css' => '',
            'filters'    => [],
        ];
    }

    /**
     * Reproduces the render loop of templates/composition.php (its lines 16-26): read the
     * stored items, skip any without a `component` key, promote a `style` map to the
     * `__pp_style` prop, and render each component in order. Deliberately carries NO
     * try/catch, because the absence of one is the whole defect — a TypeError here is the
     * 500 a visitor gets. The buffer is closed in a `finally` so a regression reports as a
     * clean failure instead of a risky test with a leaked output buffer.
     *
     * STATED PRECISELY: this is a REPRODUCTION of that loop, not an invocation of it. Two
     * deliberate substitutions — it calls pp_get_composition($post_id) where the template
     * calls pp_composition() (which resolves the CURRENT post, and there is no global post
     * in a unit test), and it omits the pp_base_template() chrome wrapper, which renders
     * header and footer and has nothing to do with these guards. Everything between those
     * two — the decode, the prop/style handling, and the uncaught pp_get_component() call
     * that is the actual 500 — is the template's own code path.
     *
     * DRIFT: kept byte-identical to the same helper in StoredStyleAndItemsRenderGuardTest,
     * StoredTitleRenderGuardTest, StoredBackgroundImageRenderGuardTest and
     * StoredImageUrlRenderGuardTest so all five drift together or not at all.
     */
    private function renderStored(int $post_id): string
    {
        ob_start();
        try {
            foreach (pp_get_composition($post_id) as $item) {
                if (!isset($item['component'])) {
                    continue;
                }
                $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
                $style = isset($item['style'])  && is_array($item['style'])  ? $item['style']  : [];
                if ($style) {
                    $props['__pp_style'] = $style;
                }
                pp_get_component((string) $item['component'], $props);
            }
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }

    /**
     * Writes stored bytes through the JSON channel — the normal one, and the only one a
     * real composition write produces. Cannot carry an object; see the class docblock.
     *
     * Goes through pp_update_composition(), the actual storage writer, rather than a hand
     * -rolled update_post_meta(wp_json_encode(...)). That is not a stylistic preference:
     * the update_post_meta() stub models WordPress's real magic-quotes contract and
     * UNSLASHES what it stores, so a raw wp_json_encode() write silently loses its
     * backslashes and any payload containing a quote (an <iframe src="…">, a <p> tag)
     * comes back as decode_error and renders as a BLANK PAGE. A fixture that lost its
     * composition that way would make every "still renders" assertion below vacuous while
     * the file stayed green. Same writer the four sibling files use.
     */
    private function renderComposition(array $bands): string
    {
        $this->resetStore();
        pp_update_composition(500, $bands);
        return $this->renderStored(500);
    }

    private function renderJson(string $component, array $props, string $id = 'pp-band-fixed'): string
    {
        return $this->renderComposition([
            ['component' => $component, 'props' => array_merge(['id' => $id], $props)],
        ]);
    }

    /**
     * Writes stored bytes through the ALREADY-DECODED ARRAY channel, which
     * pp_get_composition_result() explicitly accepts and which WordPress persists with
     * PHP serialize(). The only route by which a stored value can be an OBJECT.
     */
    private function renderArray(string $component, array $props, string $id = 'pp-band-fixed'): string
    {
        $this->resetStore();
        update_post_meta(500, '_pp_composition', [
            ['component' => $component, 'props' => array_merge(['id' => $id], $props)],
        ]);
        return $this->renderStored(500);
    }

    /**
     * A well-formed band for each component under test, with the guarded prop left for the
     * caller to inject. Every entry carries well-formed values for every OTHER axis: the
     * axis under test is one prop, and a fixture carrying two corrupt shapes at once
     * proves nothing about either guard (the lesson the #705 and #706 landings recorded).
     *
     * EVERY BAND CARRIES AN EXPLICIT `id`, which is a requirement rather than tidiness:
     * pp_update_composition() mints one via pp_generate_component_id() at WRITE time for
     * any entry whose id is empty, so two separate writes of "the same" composition differ
     * in eight hex characters. The byte-equality cases below perform exactly two writes
     * (one control, one degraded); without a pinned id they would compare noise.
     */
    private static function band(string $component): array
    {
        return [
            'cta'     => ['title' => 'Cta heading', 'button_text' => 'Go', 'button_url' => '/go'],
            'hero'    => ['title' => 'Hero heading', 'button_text' => 'Go', 'button_url' => '/go'],
            'section' => ['title' => 'Section heading', 'layout' => 'text-only', 'body' => '<p>Section body</p>'],
            'grid'    => ['title' => 'Grid heading', 'items' => [['title' => 'Card one', 'text' => 'Card body']]],
            'faq'     => ['title' => 'Faq heading', 'items' => [['question' => 'Q one', 'answer' => 'A one']]],
            'table'   => ['title' => 'Table heading', 'headers' => ['Plan'], 'rows' => [['Free']]],
            'embed'   => ['title' => 'Embed heading', 'content' => '<iframe src="/e"></iframe>'],
        ][$component];
    }

    /**
     * Builds the props for one guarded surface with $value injected at the right depth.
     * Element-level surfaces (grid card link_url, faq item answer, table cell) inject
     * INSIDE an otherwise well-formed collection, which is the reachability #708's landing
     * flagged and could not close: the container is guarded, the leaf is not.
     */
    private static function propsFor(string $surface, $value): array
    {
        switch ($surface) {
            case 'cta.button_url':
                return ['title' => 'T', 'button_text' => 'Go', 'button_url' => $value];
            case 'cta.button2_url':
                return ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/a',
                        'button2_text' => 'More', 'button2_url' => $value];
            case 'hero.button_url':
                return ['title' => 'T', 'button_text' => 'Go', 'button_url' => $value];
            case 'hero.button2_url':
                return ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/a',
                        'button2_text' => 'More', 'button2_url' => $value];
            case 'section.panel_cta_url':
                return ['title' => 'T', 'layout' => 'text-panel', 'body' => '<p>b</p>',
                        'panel_cta_text' => 'Go', 'panel_cta_url' => $value];
            case 'grid.items[].link_url':
                return ['title' => 'T', 'items' => [['title' => 'Card', 'text' => 'Body', 'link_url' => $value]]];
            case 'section.body':
                return ['title' => 'T', 'layout' => 'text-only', 'body' => $value];
            case 'faq.items[].answer':
                return ['title' => 'T', 'items' => [['question' => 'Q one', 'answer' => $value]]];
            case 'table.rows[][]':
                return ['title' => 'T', 'headers' => ['Plan'], 'rows' => [[$value]]];
            case 'embed.content':
                return ['title' => 'T', 'content' => $value];
        }
        throw new InvalidArgumentException('unknown surface ' . $surface);
    }

    /**
     * The control every degradation case is compared against: the SAME band storing an
     * EMPTY STRING at the guarded prop.
     *
     * The empty string, not an absent key, and the difference is real rather than
     * cosmetic. Two of these props carry a non-empty `??` default — cta and hero both
     * default `button_url` to '#' — so an absent key renders href="#" while the guard
     * degrades to href="". Comparing against absence would therefore fail for a reason
     * that has nothing to do with the guard, and "degrade to the default" is NOT the claim
     * the ruling makes. The claim is that a malformed value renders as an EMPTY one does,
     * which is exactly this control.
     */
    private static function controlPropsFor(string $surface): array
    {
        if ($surface === 'table.rows[][]') {
            return ['title' => 'T', 'headers' => ['Plan'], 'rows' => [['']]];
        }
        return self::propsFor($surface, '');
    }

    public static function guardedSurfaces(): array
    {
        $out = [];
        foreach (array_keys(self::GUARDED_SURFACES) as $surface) {
            $out[$surface] = [$surface];
        }
        return $out;
    }

    // ── Coupling: the swept inventory must match the source ───────────────────

    /**
     * Fails the moment a component starts or stops carrying a stored value into either
     * core escaper, so the sweeps in this file cannot quietly stop covering the real set.
     *
     * SET EQUALITY, not membership, and the difference is the whole test. An earlier draft
     * only asserted that each call site's argument appeared in an allowlist of accepted
     * spellings. That admits a NEW component silently, because a new file reading
     * `$body = $props['body'] ?? '';` straight into wp_kses_post() reuses an already-
     * accepted NAME — the exact production-500 shape this issue exists to prevent. It was
     * proven to pass before this was rewritten. Comparing the discovered call-site set
     * against the declared inventory fails in BOTH directions: a new site is an unexpected
     * member, a removed site is a missing one.
     *
     * This is the inventory half. The GUARD half — that each of those locals is actually
     * assigned through the is_scalar idiom, which a name can never prove — lives in
     * InvariantTest::testEveryCoreEscaperCallInAComponentTakesAGuardedLocal(). Neither
     * test is sufficient alone: this one pins WHICH sites exist, that one pins that each
     * is guarded.
     *
     * COMMENT-STRIPPED BEFORE THE GATE, not after — the same reason every source-level
     * checker in this repo does it. The guard blocks added by this change are long prose
     * that quote `esc_url()` and `wp_kses_post()` by name, so a raw scan counts sentences
     * as call sites and would report components that merely mention them.
     */
    public function testTheGuardedCallSiteInventoryMatchesTheSource(): void
    {
        $found = [];
        $iter  = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__) . '/components')
        );
        foreach ($iter as $entry) {
            if ($entry->getExtension() !== 'php') {
                continue;
            }
            $stripped  = $this->stripComments(file_get_contents($entry->getPathname()));
            $component = basename(dirname($entry->getPathname()));
            foreach (['esc_url', 'wp_kses_post'] as $sink) {
                // One level of nesting allowed, so esc_url(pp_site_url()) captures the
                // whole inner call rather than truncating at its first ')'.
                $re = '/\b' . $sink . '\(\s*([^()]*(?:\([^()]*\)[^()]*)*)\)/';
                if (preg_match_all($re, $stripped, $m)) {
                    foreach ($m[1] as $arg) {
                        $found[] = $component . '|' . $sink . '|' . trim($arg);
                    }
                }
            }
        }

        // The complete expected call-site set: the guarded inventory plus the named
        // exemptions. Both halves live in constants at the top of this class, so the
        // inventory this file sweeps and the inventory it enforces cannot drift apart.
        $expected = self::EXEMPT_CALL_SITES;
        foreach (self::GUARDED_SURFACES as [$component, $sink, $arg]) {
            $expected[] = $component . '|' . $sink . '|' . $arg;
        }

        // Unique, because several surfaces legitimately have more than one call site:
        // section renders `body` in all three layout branches from one guarded read, and
        // hero echoes $proof_markup twice. What is being pinned is which (component, sink,
        // argument) triples exist, not how many times each is echoed.
        $found    = array_values(array_unique($found));
        $expected = array_values(array_unique($expected));

        $this->assertEqualsCanonicalizing(
            $expected,
            $found,
            'the set of component call sites reaching core\'s esc_url() / wp_kses_post()'
            . ' changed (#730). A NEW site must either take the #730 guard and join'
            . ' GUARDED_SURFACES, or be exempted by name in EXEMPT_CALL_SITES with the'
            . ' reason it cannot carry a stored value. Both escapers are UNTYPED but still'
            . ' fatal from the inside on an array or an object, and'
            . ' templates/composition.php has no try/catch, so an unguarded one 500s the'
            . ' WHOLE public page. A REMOVED site means this file is sweeping a surface'
            . ' that no longer exists.'
        );

        // Non-vacuity: if this ever finds nothing, the comparison above is trivial.
        $this->assertGreaterThanOrEqual(
            13,
            count($found),
            'the escaper call-site scan found almost nothing — the checker has drifted'
        );
    }

    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    // ── #730: the fatal shapes now render ─────────────────────────────────────

    /**
     * The array row of the measured matrix, through the JSON channel — the reachability
     * every one of these surfaces actually has on a live site.
     *
     * Asserts AFFIRMATIVELY that the band still emitted its own markup, not merely that
     * nothing threw. A non-empty array is TRUTHY, so on the gated surfaces the gate used
     * to pass and hand the array straight to the escaper.
     *
     * @dataProvider guardedSurfaces
     */
    public function testAStoredArrayRendersThePageInsteadOfFataling(string $surface): void
    {
        [$component] = self::GUARDED_SURFACES[$surface];
        $html = $this->renderJson($component, self::propsFor($surface, ['x']));

        $this->assertStringContainsString(
            'data-pp-component="' . $component . '"',
            $html,
            $surface . ': a stored ARRAY must degrade, not 500 the page.'
        );

        if ($surface === 'faq.items[].answer') {
            // The band's own rendering degrades correctly — the .faq__answer div is empty.
            // But faq ALSO hands the whole items array to pp_render_faq_schema(), which
            // re-reads each element's answer with its own (string) cast, so the literal
            // word Array still reaches the JSON-LD payload. That is the residual pinned in
            // testAnArrayAnswerStillLeaksTheWordArrayIntoTheSchemaFragment(); asserting
            // "no Array anywhere" here would fail for that reason and hide this one.
            $this->assertStringContainsString('faq__answer', $html);
            return;
        }

        $this->assertStringNotContainsString(
            'Array',
            $html,
            $surface . ': the degraded render must not paint the literal word "Array".'
            . ' esc_html/esc_attr coerce an array to that string without fataling, so a'
            . ' guard that merely stopped the throw would leave this visible to a visitor.'
        );
    }

    /**
     * The EMPTY array, which several of these surfaces treat differently from a populated
     * one and which is easy to assume is harmless. It is not: on the ungated sites
     * (cta/hero primary button, section body, faq answer, table cell) an empty array
     * reaches the escaper exactly as a populated one does, because there is no truthiness
     * gate in front of it to filter it out.
     *
     * @dataProvider guardedSurfaces
     */
    public function testAStoredEmptyArrayRendersThePageInsteadOfFataling(string $surface): void
    {
        [$component] = self::GUARDED_SURFACES[$surface];
        $html = $this->renderJson($component, self::propsFor($surface, []));
        $this->assertStringContainsString('data-pp-component="' . $component . '"', $html, $surface);
    }

    /**
     * The object row of the matrix, through the ARRAY channel — the only route that can
     * carry one. An object with no __toString() fatals in BOTH escapers, by different
     * builtins (ltrim for esc_url, preg_replace via wp_kses_no_null for wp_kses_post).
     *
     * A __toString()-bearing object degrades here too, and that is deliberate rather than
     * an oversight: is_scalar() is false for every object. Admitting Stringable would move
     * the safety boundary instead of holding it — core's ltrim() would accept it, but
     * nothing at this layer can vouch for what its __toString() returns.
     *
     * @dataProvider guardedSurfaces
     */
    public function testAStoredObjectRendersThePageInsteadOfFataling(string $surface): void
    {
        [$component] = self::GUARDED_SURFACES[$surface];

        if ($surface === 'faq.items[].answer') {
            // KNOWN RESIDUAL, pinned as such in
            // testTheRemainingCastBoundariesAreStillOpen(): the guard added here DOES stop
            // faq's own wp_kses_post() call, but pp_render_faq_schema() re-reads the same
            // element with its own (string) cast, independently of this guard, and that
            // cast fatals on an object. A different boundary, filed separately rather than
            // widened into this ruling. Skipping here keeps that fact in exactly one place.
            $this->markTestSkipped('object answer still fatals inside pp_render_faq_schema() — see testTheRemainingCastBoundariesAreStillOpen');
        }

        $html = $this->renderArray($component, self::propsFor($surface, new stdClass()));
        $this->assertStringContainsString('data-pp-component="' . $component . '"', $html, $surface);
    }

    // ── #730: degradation is byte-identical to a band that stored nothing ─────

    /**
     * The claim the ruling actually makes: "the band renders without the affected
     * fragment", which means the degraded render must equal the render of a band that
     * never stored the prop at all.
     *
     * BYTE EQUALITY, not a marker search, wherever the control is well defined. The #708
     * landing established this as the family's strongest available pin, and it is what
     * catches a guard that degrades to something almost-but-not-quite the empty state —
     * an empty-href anchor, a stray wrapper, a whitespace change. Two of those were caught
     * during this change: an empty-href panel button, and a comment block that printed its
     * own indentation into every table body.
     *
     * @dataProvider guardedSurfaces
     */
    public function testEachDegradationIsByteIdenticalToACleanControlRender(string $surface): void
    {
        [$component] = self::GUARDED_SURFACES[$surface];

        if ($surface === 'faq.items[].answer') {
            // Not byte-identical, and the gap is the residual rather than the guard: the
            // unguarded pp_render_faq_schema() still emits a JSON-LD fragment for an array
            // answer (containing the literal word Array), where a genuinely empty answer
            // makes the helper skip the item and emit nothing. Pinned on its own below so
            // the difference is a measured fact with a named owner, not a silent exemption.
            $this->markTestSkipped('faq answer degradation is not byte-identical — see testAnArrayAnswerStillLeaksTheWordArrayIntoTheSchemaFragment');
        }

        $degraded = $this->renderJson($component, self::propsFor($surface, ['x']));
        $control  = $this->renderJson($component, self::controlPropsFor($surface));

        $this->assertSame(
            $control,
            $degraded,
            $surface . ': the degraded render must be byte-identical to a band that stored'
            . ' an empty value. Anything else is a state nobody designed.'
        );
    }

    /**
     * The faq answer's residual, stated as a measured fact so it cannot be mistaken for
     * coverage this change provides. The display path IS guarded — the accordion renders
     * with an empty answer — but pp_render_faq_schema() re-reads the same element
     * independently, casts it with (string), and puts `Array` into the page's structured
     * data where the answer text belongs.
     *
     * Same boundary as the object fatal in testTheFaqSchemaHelperStillFatalsOnObjectElements(),
     * and the same class as the open #721/#736. When that is fixed, this test flips.
     */
    public function testAnArrayAnswerStillLeaksTheWordArrayIntoTheSchemaFragment(): void
    {
        $html = $this->renderJson('faq', self::propsFor('faq.items[].answer', ['x']));

        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString(
            'Array',
            $html,
            'the schema fragment still carries the coerced value. If this stopped being'
            . ' true, the remaining (string)-cast boundary was closed — update this pin.'
        );
        // The rendered accordion itself is clean; only the schema payload is not.
        $visible = substr($html, 0, strpos($html, 'application/ld+json'));
        $this->assertStringNotContainsString('Array', $visible);
    }

    // ── #730: well-formed data is completely unchanged ────────────────────────

    /**
     * The other half of the ruling, and the half a guard is most likely to break silently:
     * ZERO rendering change for well-formed data.
     *
     * "Well-formed" is defined here as WHAT THE WRITE PATH ACCEPTS, not what the schema
     * ideally wants, and the distinction is load-bearing rather than pedantic. Measured:
     * pp_validate_composition() returns ok=true with ZERO findings for
     * panel_cta_url:false. So `false` is a value the front door admits, and the guard is
     * required to keep rendering it exactly as before — which is why $has_panel_cta reads
     * the RAW value and carries its own is_scalar term instead of testing the cast result.
     *
     * ASSERTS EMITTED BYTES, because an earlier draft of this test did not, and that draft
     * passed against exactly the implementation its own comment called out as wrong.
     * Measured, one mutation at a time: narrowing the guards from is_scalar to is_string —
     * which silently DROPS every stored 0 / 42 / 3.14 / true / false — left NINE of
     * the ten surfaces green, because the test only asserted that the band still rendered
     * and then skipped its containment check for every non-string by construction. A test
     * that cannot see a value disappear is not testing the value.
     *
     * The comparison is against a control render storing the scalar's OWN (string) cast:
     * that is precisely what "the guard passes the scalar through unchanged" means, and it
     * is stub-independent, which matters because tests/bootstrap.php's esc_url() is
     * type-faithful but NOT byte-faithful (real core prepends a scheme to a schemeless
     * value). Asserting `href="42"` would pin a fiction; asserting "identical to storing
     * '42'" pins the guard.
     *
     * section.body is the one documented exception, and it is excluded rather than
     * fudged: $has_body_copy legitimately distinguishes a stored int 42 from the string
     * '42' (it tests is_string on the RAW value, deliberately — see the guard block), so
     * those two renders are SUPPOSED to differ. That surface's scalar behaviour is pinned
     * by testSectionBodySpacingIsUnchangedForEveryScalar() instead.
     */
    public function testEveryStoredScalarPassesThroughTheGuardUnchanged(): void
    {
        // Every scalar shape a stored composition can carry. `null` is absent on purpose:
        // it triggers the `??` default, which is a different code path from the guard.
        $scalars = ['', '0', 'zzq', '/go', 0, 42, 3.14, 0.0, true, false];

        foreach (array_keys(self::GUARDED_SURFACES) as $surface) {
            [$component] = self::GUARDED_SURFACES[$surface];

            // THE TWO RAW-KEYED GATES ARE EXCLUDED, and it is the same reason both times,
            // which is why the exclusion list is exactly the set of gates that read the raw
            // value. At those two surfaces a stored scalar and its own string cast are
            // SUPPOSED to differ, because preserving that difference is the behaviour the
            // guard was written to protect:
            //   section.body        — $has_body_copy tests is_string on the raw value, so a
            //                         stored int 42 is not body copy while '42' is.
            //   section.panel_cta_url — the gate tests `$raw !== ''`, so a stored false
            //                         (stored) still renders its button while ''
            //                         renders none. Gating on the cast instead would have
            //                         deleted that button — the exact regression the raw
            //                         keying exists to prevent.
            // Each is pinned by its own dedicated test rather than waived.
            if ($surface === 'section.body' || $surface === 'section.panel_cta_url') {
                continue;
            }

            foreach ($scalars as $value) {
                $stored = $this->renderJson($component, self::propsFor($surface, $value));
                $cast   = $this->renderJson($component, self::propsFor($surface, (string) $value));

                $this->assertSame(
                    $cast,
                    $stored,
                    $surface . ' with stored ' . var_export($value, true) . ' must render'
                    . ' byte-identically to storing its own string cast. A difference here'
                    . ' means the guard is dropping or altering a value the write path'
                    . ' accepts — which is what an is_string guard would do, and why the'
                    . ' ratified idiom is is_scalar.'
                );
                $this->assertStringContainsString(
                    'data-pp-component="' . $component . '"',
                    $stored,
                    $surface . ' with ' . var_export($value, true) . ' must still render'
                );
            }

            // ...and a value that CANNOT already occur in the surrounding markup must be
            // visible in the output, so "passed through" is proved positively and not only
            // by two renders agreeing. `zzq` is chosen for exactly that: an earlier draft
            // probed with `x`, which the control markup already contains at all ten
            // surfaces, so the assertion was vacuous everywhere it ran.
            $probe = $this->renderJson($component, self::propsFor($surface, 'zzq'));
            $this->assertStringContainsString(
                'zzq',
                $probe,
                $surface . ': a stored scalar must reach the escaper, not be dropped by the'
                . ' guard.'
            );
        }
    }

    /**
     * The panel CTA's `false`, called out on its own because it is the single value where
     * a naive `(string)` cast would have changed a WRITE-ACCEPTED render, and because the
     * fix for it (an is_scalar term inside $has_panel_cta, gating on the raw value) reads
     * like an oddity unless the reason is pinned.
     *
     * A STORED `panel_cta_url: false` renders an anchor with an empty href. That is
     * arguably a broken button, and #707 has since stopped NEW ones being written — but it
     * migrated nothing, so the stored ones still render and this is still what they do.
     * What this test protects is that the guard did not silently make that decision on the
     * way past, and #707 landing does not change that: a render guard must not start
     * dropping values a write rule stopped accepting later.
     */
    public function testAStoredFalsePanelCtaUrlStillRendersItsButtonUnchanged(): void
    {
        $withFalse = $this->renderJson('section', self::propsFor('section.panel_cta_url', false));

        $this->assertStringContainsString(
            'section__panel-cta',
            $withFalse,
            'a stored false panel_cta_url is accepted by the write path (measured: ok=true,'
            . ' zero findings) and has always rendered its button. The #730 guard must not'
            . ' change that — only shapes that used to FATAL may change.'
        );

        // ...while the shape that used to fatal degrades to NO button at all, rather than
        // to the empty-href anchor a raw-value-only gate would have produced.
        $withArray = $this->renderJson('section', self::propsFor('section.panel_cta_url', ['x']));
        $this->assertStringNotContainsString(
            'section__panel-cta',
            $withArray,
            'a stored ARRAY panel_cta_url must remove the button entirely. An anchor with an'
            . ' empty href is not "the band renders without the affected fragment" — it is a'
            . ' button pointing at the current page.'
        );
    }

    /**
     * section.body reaches wp_kses_post() from THREE layout branches, and one guarded read
     * feeds all three. The sweeps above render only `text-only`, so without this the
     * degradation claim would be proved for one branch of three while reading as complete.
     *
     * Each branch gets its own degradation and its own byte-equality control, because
     * "the guard covers every call site" is a claim about the call sites, not about the read.
     */
    public function testSectionBodyDegradesIdenticallyInAllThreeLayoutBranches(): void
    {
        foreach (['text-only', 'text-panel', 'image-left'] as $layout) {
            $base = ['title' => 'T', 'layout' => $layout, 'image_url' => '/i.png', 'image_alt' => 'i'];

            $degraded = $this->renderJson('section', $base + ['body' => ['x']]);
            $control  = $this->renderJson('section', $base + ['body' => '']);

            $this->assertSame(
                $control,
                $degraded,
                $layout . ': a corrupt body must degrade to exactly what an empty body renders'
                . ' in THIS branch too — one guarded read has to cover every call site it feeds.'
            );
            $this->assertStringContainsString('data-pp-component="section"', $degraded);
            $this->assertStringNotContainsString('Array', $degraded);

            // ...and the branch still renders a well-formed body, so the case above is not
            // passing because the branch emits nothing at all.
            $wellFormed = $this->renderJson('section', $base + ['body' => '<p>Real copy</p>']);
            $this->assertStringContainsString('<p>Real copy</p>', $wellFormed, $layout);
        }
    }

    /**
     * section.panel_cta_url's full scalar behaviour, pinned on its own for two reasons.
     *
     * FIRST, it is excluded from the byte-equality sweep above (a stored `false` and a
     * stored `''` legitimately differ here), so without this it would have no scalar
     * coverage at all.
     *
     * SECOND, and less obvious: this is the one guarded surface where the GATE hides the
     * GUARD. Measured — deleting the guard outright, leaving `$panel_cta_url` as the raw
     * value while keeping the is_scalar term in $has_panel_cta, leaves every other test in
     * this file green, because a non-scalar closes the gate and esc_url() is then never
     * reached. The guard here is defence-in-depth for a future reader who adds an ungated
     * consumer of $panel_cta_url; the gate is what production actually relies on today.
     * Stating that is more honest than implying the behavioural suite proves the guard at
     * this surface, and it tells whoever adds that consumer what they are relying on.
     *
     * The rule this pins is the pre-guard one, unchanged: the button renders exactly when
     * the RAW stored value is not the empty string.
     */
    public function testThePanelCtaButtonStillFollowsTheRawStoredValueForEveryScalar(): void
    {
        foreach (['' => false, '0' => true, 'zzq' => true, '/go' => true] as $value => $expected) {
            $html = $this->renderJson('section', self::propsFor('section.panel_cta_url', (string) $value));
            $this->assertSame(
                $expected,
                str_contains($html, 'section__panel-cta'),
                'panel_cta_url ' . var_export($value, true) . ': the button must follow the raw'
                . ' stored value, exactly as before the guard.'
            );
        }

        foreach ([0, 42, 3.14, 0.0, true, false] as $value) {
            $html = $this->renderJson('section', self::propsFor('section.panel_cta_url', $value));
            $this->assertStringContainsString(
                'section__panel-cta',
                $html,
                'panel_cta_url ' . var_export($value, true) . ' is a STORED scalar and is not the'
                . ' empty string, so it still renders its button. Gating on the (string) cast'
                . ' would drop false, which is the regression the raw-keyed gate prevents.'
            );
        }

        // ...while every NON-scalar closes the gate entirely: no anchor at all, rather than
        // the empty-href anchor a raw-only gate would have produced.
        foreach ([['x'], []] as $bad) {
            $html = $this->renderJson('section', self::propsFor('section.panel_cta_url', $bad));
            $this->assertStringNotContainsString('section__panel-cta', $html);
            $this->assertStringContainsString('data-pp-component="section"', $html);
        }
    }

    /**
     * The second-order effect of the panel-CTA guard, pinned because the obvious
     * description of it ("the button disappears") is too small on one band shape.
     *
     * $has_panel_cta is one of four terms in $has_panel, and $has_panel decides whether
     * the text-panel layout renders its panel column at all or falls back to text-only.
     * On a panel whose ONLY content is the CTA, guarding the url away therefore collapses
     * the whole panel column and changes the band's layout. That is still exactly what the
     * same band stored with an empty panel_cta_url produces — asserted here by byte
     * equality, which is what makes it a degradation rather than a new state.
     */
    public function testAPanelWhoseOnlyContentIsTheCtaCollapsesExactlyAsAnEmptyUrlDoes(): void
    {
        $ctaOnly = static fn ($url) => [
            'title' => 'T', 'layout' => 'text-panel', 'body' => '<p>b</p>',
            'panel_cta_text' => 'Go', 'panel_cta_url' => $url,
        ];

        $degraded = $this->renderJson('section', $ctaOnly(['x']));
        $control  = $this->renderJson('section', $ctaOnly(''));

        $this->assertSame(
            $control,
            $degraded,
            'a CTA-only panel with a malformed url must collapse exactly as the same band'
            . ' with an empty url does — layout fallback included.'
        );
        $this->assertStringNotContainsString('section__panel', $degraded);
        $this->assertStringContainsString('data-pp-component="section"', $degraded);

        // ...and a panel carrying other content keeps its column, losing only the button.
        $withHeading = $this->renderJson('section', [
            'title' => 'T', 'layout' => 'text-panel', 'body' => '<p>b</p>',
            'panel_heading' => 'Plan', 'panel_cta_text' => 'Go', 'panel_cta_url' => ['x'],
        ]);
        $this->assertStringContainsString('section__panel', $withHeading);
        $this->assertStringContainsString('Plan', $withHeading);
        $this->assertStringNotContainsString('section__panel-cta', $withHeading);
    }

    /**
     * section's `body` also drives the inline-items row's top margin through
     * $has_body_copy, which tests is_string(). Guarding `body` at the read makes the
     * guarded local a string for every scalar, so keying that flag on the guarded value
     * would flip a stored `42` from "no body copy" to "has body copy" — a spacing change
     * for a stored value that renders fine today. It is keyed on the raw value instead;
     * this pins that.
     */
    public function testSectionBodySpacingIsUnchangedForEveryScalar(): void
    {
        foreach ([42, 3.14, true, 0, 0.0] as $value) {
            $props = ['title' => 'T', 'layout' => 'text-only', 'body' => $value,
                      'body_items' => ['One', 'Two']];
            $html  = $this->renderJson('section', $props);

            // A non-string scalar body must NOT be treated as body copy, exactly as before
            // the guard: the inline-items row keeps its flush-top modifier, which is the
            // class $has_body_copy actually drives.
            $this->assertStringContainsString(
                'section__inline-items--flush-top',
                $html,
                'a stored ' . gettype($value) . ' body must keep its pre-guard spacing.'
                . ' Keying $has_body_copy on the guarded (already-cast) value would flip it,'
                . ' because the guard makes every scalar a string and is_string() would then'
                . ' always be true.'
            );
        }

        // ...and a genuine string body still counts as body copy, so the row is NOT flush.
        $withCopy = $this->renderJson('section', [
            'title' => 'T', 'layout' => 'text-only', 'body' => '<p>Real copy</p>',
            'body_items' => ['One', 'Two'],
        ]);
        $this->assertStringNotContainsString('section__inline-items--flush-top', $withCopy);

        // ...and a NON-SCALAR body degrades to the same spacing an empty body produces,
        // which is what makes the raw-keyed flag safe rather than merely conservative.
        $degraded = $this->renderJson('section', [
            'title' => 'T', 'layout' => 'text-only', 'body' => ['x'], 'body_items' => ['One'],
        ]);
        $control = $this->renderJson('section', [
            'title' => 'T', 'layout' => 'text-only', 'body' => '', 'body_items' => ['One'],
        ]);
        $this->assertSame($control, $degraded);
    }

    /**
     * float -0.0, the one scalar where the ratified `(string)` cast flips a downstream
     * TRUTHINESS gate: -0.0 is falsy, but (string) -0.0 is '-0', and only '' and '0' are
     * falsy strings. Two of this change's surfaces are truthiness-gated (grid's card link
     * and embed's content); the rest are ungated or use a strict `!== ''`, so the flip
     * cannot reach them.
     *
     * Left as-is, following the #705 precedent that shipped exactly this flip. What makes
     * that defensible is the CHANNEL, and it is easy to state backwards, so it is measured
     * here in both directions rather than asserted:
     *
     *   json_encode(-0.0)            -> the text `-0`   -> json_decode gives INT 0 -> falsy, NO flip
     *   stored text `-0.0` (literal) -> json_decode gives FLOAT -0                 -> truthy, FLIP
     *
     * PHP's own json_encode never emits the decimal-point form, so every writer that
     * re-encodes round-trips it away. Only stored bytes that ALREADY contain the literal
     * text `-0.0` reach the flip.
     */
    public function testNegativeZeroFlipsOnlyThroughARawMetaWriteAndOnlyWhereAGateIsTruthiness(): void
    {
        // Channel 1 — anything that re-encodes. json_encode never emits `-0.0`.
        $this->assertSame('-0', json_encode(-0.0));
        $this->assertSame(0, json_decode(json_encode(-0.0), true));
        $this->assertFalse((bool) json_decode(json_encode(-0.0), true));

        // Channel 2 — stored bytes already containing the literal decimal-point form.
        $literal = json_decode('-0.0', true);
        $this->assertIsFloat($literal);
        $this->assertFalse((bool) $literal, 'the raw float is falsy...');
        $this->assertTrue((bool) (string) $literal, '...but its string cast is truthy — the flip');

        // Channel 1, end to end: written through the real composition writer, the value
        // round-trips to int 0 and the link stays absent — the flip is NOT reachable here.
        $viaWriter = $this->renderJson('grid', self::propsFor('grid.items[].link_url', -0.0));
        $this->assertStringNotContainsString(
            'grid__item-link',
            $viaWriter,
            'pp_update_composition() re-encodes, and json_encode never emits the'
            . ' decimal-point form, so a -0.0 written through any real writer cannot reach'
            . ' the flip. Asserting this is the whole reason the flip is acceptable.'
        );

        // Channel 2, end to end: stored bytes that ALREADY contain the literal text -0.0.
        // Written straight to meta, wp_slash()'d because the store models WordPress's
        // unslash-on-write contract, and hand-built rather than encoded precisely because
        // no encoder will produce these bytes.
        $this->resetStore();
        update_post_meta(500, '_pp_composition', wp_slash(
            '[{"component":"grid","props":{"id":"pp-band-fixed","title":"T",'
            . '"items":[{"title":"Card","text":"Body","link_url":-0.0}]}}]'
        ));
        $viaRawBytes = $this->renderStored(500);
        $this->assertStringContainsString(
            'grid__item-link',
            $viaRawBytes,
            'stored bytes carrying the literal text -0.0 decode to float -0 and DO reach'
            . ' the flip. This is the documented, accepted #705-precedent behaviour.'
        );

        // The OTHER truthiness-gated surface, through both channels, because naming two
        // surfaces in the docblock and exercising one is how a half-tested claim ships.
        $this->assertStringNotContainsString(
            'embed__content',
            $this->renderJson('embed', self::propsFor('embed.content', -0.0)),
            'written through a real writer, -0.0 round-trips to int 0 and stays gated out'
        );
        $this->resetStore();
        update_post_meta(500, '_pp_composition', wp_slash(
            '[{"component":"embed","props":{"id":"pp-band-fixed","title":"T","content":-0.0}}]'
        ));
        $this->assertStringContainsString(
            'embed__content',
            $this->renderStored(500),
            'stored literal -0.0 bytes reach the flip at embed too'
        );

        // The strictly-compared gate does NOT flip: `!== ''` is true for the raw float and
        // for its cast alike, so the panel button rendered before and renders now.
        $section = $this->renderJson('section', self::propsFor('section.panel_cta_url', $literal));
        $this->assertStringContainsString('section__panel-cta', $section);
    }

    // ── #739: faq items ───────────────────────────────────────────────────────

    /**
     * Every non-array shape that fataled at pp_render_faq_schema(array $items).
     *
     * NINE SHAPES, where the filed issue measured six. `true`, `3.14` and the object were
     * added when the set was re-derived rather than copied. Note what is NOT here: `null`
     * never fataled, because `?? []` fires on it — which is why the guard's default must
     * stay the empty array.
     *
     * The falsy shapes are the point of the separate issue. Grid survives them because its
     * only `items` boundary sits INSIDE its !empty() gate; faq's schema call sits OUTSIDE
     * that gate, so '' / 0 / false / '0' reach the typed parameter and 500 the page.
     */
    public static function nonArrayFaqItems(): array
    {
        return [
            'string'       => ['a string'],
            'empty string' => [''],
            'zero string'  => ['0'],
            'integer zero' => [0],
            'integer'      => [42],
            'float'        => [3.14],
            'boolean false'=> [false],
            'boolean true' => [true],
        ];
    }

    /**
     * @dataProvider nonArrayFaqItems
     */
    public function testAStoredNonArrayFaqItemsRendersTheBandInsteadOfFataling($items): void
    {
        $html = $this->renderJson('faq', ['title' => 'Faq heading', 'items' => $items]);

        $this->assertStringContainsString('data-pp-component="faq"', $html);
        // AFFIRMATIVE: the band degrades to its designed empty state, heading intact.
        $this->assertStringContainsString('faq__empty', $html);
        $this->assertStringContainsString('Faq heading', $html);
        // ...and emits no JSON-LD, because there are no items to describe.
        $this->assertStringNotContainsString('application/ld+json', $html);
    }

    /** The object shape, reachable only through the already-decoded array channel. */
    public function testAStoredObjectFaqItemsRendersTheBandInsteadOfFataling(): void
    {
        $html = $this->renderArray('faq', ['title' => 'Faq heading', 'items' => new stdClass()]);
        $this->assertStringContainsString('faq__empty', $html);
        $this->assertStringNotContainsString('application/ld+json', $html);
    }

    /**
     * The degradation claim for #739, by byte equality: a malformed `items` renders exactly
     * what a band that stored NO items renders.
     */
    public function testFaqDegradesToTheSameMarkupANoItemsBandProduces(): void
    {
        $degraded = $this->renderJson('faq', ['title' => 'Faq heading', 'items' => 'a string']);

        // Two controls, because they make slightly different claims and both are worth
        // holding. The empty ARRAY is what the guard literally degrades to; the ABSENT key
        // is the stronger "renders as a band that stored nothing" claim. The absent-key
        // control also emits a missing-required-prop notice from lib/components.php — a
        // schema diagnostic about the FIXTURE, not about the guard, and it does not reach
        // the rendered buffer, which is why byte equality still holds.
        $this->assertSame(
            $this->renderJson('faq', ['title' => 'Faq heading', 'items' => []]),
            $degraded
        );
        $this->assertSame(
            $this->renderJson('faq', ['title' => 'Faq heading']),
            $degraded
        );
    }

    /**
     * A well-formed faq is completely untouched — the schema fragment still renders, which
     * is the fragment the guard is closest to removing by accident.
     */
    public function testAWellFormedFaqStillEmitsItsSchemaFragment(): void
    {
        $html = $this->renderJson('faq', self::band('faq'));

        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('FAQPage', $html);
        $this->assertStringContainsString('Q one', $html);
        $this->assertStringContainsString('faq__question', $html);
    }

    // ── The corridor ──────────────────────────────────────────────────────────

    /**
     * THE CORRIDOR PIN, and the reason this pair was scheduled to close the render-guard
     * family rather than merely extend it.
     *
     * Every earlier sibling could only claim its own axis. #708's landing said so
     * explicitly: "not 'a band can no longer 500' — the corridor still holds #730, #733,
     * #738, #739 and #740". Two of those five are this change. So this test carries ONE
     * band holding a corrupt value on EVERY axis the family has landed, all at once:
     *
     *   __pp_style          corrupt (#708)   title / title_accent  corrupt (#706)
     *   background_image    corrupt (#705)   image_url / image_alt corrupt (#641)
     *   body (rich text)    corrupt (#730)   panel_cta_url         corrupt (#730)
     *
     * plus a second composition where every component in the family carries its own
     * corrupt axes, including faq's items (#739) and the element-level leaves inside
     * well-formed collections, since a single band cannot hold all of them.
     *
     * WHAT THIS DOES AND DOES NOT PROVE. It proves the axes listed above no longer 500 in
     * combination — which is stronger than each axis alone, because the earlier guards run
     * in sequence and an unguarded one upstream masks every one below it. It does NOT
     * prove "no stored value can ever 500 a band": the residuals are named and pinned in
     * testTheRemainingCastBoundariesAreStillOpen(). Read the two together.
     */
    public function testACombinedCorruptBandSurvivesEveryLandedAxisAtOnce(): void
    {
        $corrupt = ['x'];

        $html = $this->renderJson('section', [
            'id'               => 'pp-corridor',
            'layout'           => 'text-panel',
            'title'            => $corrupt,   // #706
            'title_accent'     => $corrupt,   // #706
            'background_image' => $corrupt,   // #705
            'image_url'        => $corrupt,   // #641
            'image_alt'        => $corrupt,   // #641
            'body'             => $corrupt,   // #730
            'panel_cta_text'   => 'Go',
            'panel_cta_url'    => $corrupt,   // #730
            '__pp_style'       => 'not-a-map', // #708
        ]);

        $this->assertStringContainsString(
            'data-pp-component="section"',
            $html,
            'a band corrupt on every landed axis at once must still render.'
        );
        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringNotContainsString('section__panel-cta', $html);
        $this->assertStringNotContainsString('style="', $html);
    }

    /**
     * The whole-composition form of the same claim: every component in the family, each
     * carrying its own corrupt axes, rendered through one pass of the composition loop.
     * A page, not a band.
     */
    public function testAWholeCompositionOfCorruptBandsStillRenders(): void
    {
        $c = ['x'];
        $this->resetStore();
        update_post_meta(500, '_pp_composition', wp_json_encode([
            ['component' => 'hero', 'id' => 'pp-b1', 'props' => [
                'title' => $c, 'button_text' => 'Go', 'button_url' => $c, 'button2_text' => 'B',
                'button2_url' => $c, 'background_image' => $c, '__pp_style' => 'x']],
            ['component' => 'cta', 'id' => 'pp-b2', 'props' => [
                'title' => $c, 'button_text' => 'Go', 'button_url' => $c,
                'button2_text' => 'B', 'button2_url' => $c]],
            ['component' => 'grid', 'id' => 'pp-b3', 'props' => [
                'title' => $c, 'items' => [['title' => 'Card', 'link_url' => $c, 'image_url' => $c]]]],
            ['component' => 'faq', 'id' => 'pp-b4', 'props' => [
                'title' => $c, 'items' => 'a string']],
            ['component' => 'table', 'id' => 'pp-b5', 'props' => [
                'title' => 'T', 'headers' => ['H'], 'rows' => [[$c]]]],
            ['component' => 'embed', 'id' => 'pp-b6', 'props' => [
                'title' => 'T', 'content' => $c]],
            ['component' => 'section', 'id' => 'pp-b7', 'props' => [
                'title' => $c, 'body' => $c, 'layout' => 'text-only']],
        ]));

        $html = $this->renderStored(500);

        foreach (['hero', 'cta', 'grid', 'faq', 'table', 'embed', 'section'] as $component) {
            $this->assertStringContainsString(
                'data-pp-component="' . $component . '"',
                $html,
                $component . ' did not survive the corrupt composition — one fataling band'
                . ' takes the WHOLE page down, so a missing marker here is a 500.'
            );
        }
        $this->assertStringNotContainsString('Array', $html);
    }

    /**
     * A well-formed composition of the same seven bands is COMPLETELY unaffected. The
     * corridor pin above would still pass against a renderer that had degraded everything
     * to nothing, so this is the control that makes it meaningful.
     */
    public function testAWellFormedCompositionOfEveryTouchedComponentIsUnchanged(): void
    {
        $bands = [];
        foreach (['hero', 'cta', 'grid', 'faq', 'table', 'embed', 'section'] as $i => $component) {
            $bands[] = [
                'component' => $component,
                'props'     => array_merge(['id' => 'pp-ok' . $i], self::band($component)),
            ];
        }
        $html = $this->renderComposition($bands);

        $this->assertStringContainsString('Hero heading', $html);
        $this->assertStringContainsString('Cta heading', $html);
        $this->assertStringContainsString('Card one', $html);
        $this->assertStringContainsString('faq__question', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('Free', $html);
        $this->assertStringContainsString('<iframe src="/e">', $html);
        $this->assertStringContainsString('<p>Section body</p>', $html);
    }

    // ── Boundaries: what this change deliberately does NOT close ──────────────

    /**
     * THE HONEST LIMIT of the corridor claim, pinned so it is a measured fact rather than
     * a caveat in a commit message — and so that whoever closes these issues has a test
     * that FLIPS when they do.
     *
     * Both residuals are the same shape: a `(string)` cast (or an array offset read) on a
     * possibly-non-scalar value, which is a LANGUAGE construct rather than a typed call or
     * a core escaper. That is a different boundary from either issue landed here, and the
     * family's admitting criterion is the same typed call, not the same prop and not the
     * same file. Both already have homes:
     *
     *   #721  hero.proof     — trim((string) $proof). An ARRAY renders the literal `Array`
     *                          plus a warning (that half is what #721 describes); an OBJECT
     *                          fatals at the cast, which is measured here and is NOT in
     *                          #721's body yet.
     *   (new) pp_render_faq_schema() re-reads each element's question/answer with its own
     *                          (string) cast, so an OBJECT there fatals even though faq's
     *                          own wp_kses_post() call is now guarded. An object ELEMENT
     *                          fatals differently again, on offset access.
     *
     * Objects reach these only through the already-decoded array meta channel; the JSON
     * channel cannot carry one at all.
     */
    public function testTheRemainingCastBoundariesAreStillOpen(): void
    {
        // hero.proof — array half: no fatal, but the literal word Array reaches the page.
        $heroArray = $this->renderJson('hero', ['title' => 'T', 'proof' => ['x']]);
        $this->assertStringContainsString(
            'Array',
            $heroArray,
            '#721: an array hero.proof still paints the literal word Array. If this ever'
            . ' stops being true, #721 was fixed — update this pin rather than deleting it.'
        );

        // hero.proof — object half: still a whole-page fatal, at the cast.
        $this->expectException(Error::class);
        $this->renderArray('hero', ['title' => 'T', 'proof' => new stdClass()]);
    }

    /** The faq-schema half of the same residual, separated so each has its own failure. */
    public function testTheFaqSchemaHelperStillFatalsOnObjectElements(): void
    {
        $this->expectException(Error::class);
        $this->renderArray('faq', [
            'title' => 'T',
            'items' => [['question' => 'Q', 'answer' => new stdClass()]],
        ]);
    }

    /**
     * The surfaces the filed issue LISTED but which measurement showed were already safe.
     * Asserted behaviourally rather than exempted by a comment, so that if any of them
     * later starts carrying a raw stored value to an escaper, this file fails instead of
     * production.
     *
     * - footer/nav logo url: pp_resolve_logo() sources it from
     *   wp_get_attachment_image_url(), which returns string|false. Never a stored prop.
     * - footer social url: the decode loop already skips non-array entries and casts the
     *   url with (string) before the escaper ever sees it.
     * - cta.body / grid.items[].text / testimonials.items[].quote: all route through
     *   pp_kses_inline(), which opens with `if (!is_string($content)) return ''`. Already
     *   guarded at the helper, so a component-level guard would be redundant.
     */
    public function testTheExcludedSurfacesAreSafeByMeasurementNotByAssumption(): void
    {
        // The inline-HTML helper's own guard is the reason three text props are excluded.
        $this->assertSame('', pp_kses_inline(['x']));
        $this->assertSame('', pp_kses_inline(42));
        $this->assertSame('', pp_kses_inline(true));

        // ...and it still passes well-formed inline markup through.
        $this->assertStringContainsString('<strong>', pp_kses_inline('a <strong>b</strong>'));

        // cta.body, grid text and testimonials quote therefore survive a stored array.
        $cta = $this->renderJson('cta', ['title' => 'T', 'button_text' => 'Go',
                                          'button_url' => '/a', 'body' => ['x']]);
        $this->assertStringContainsString('data-pp-component="cta"', $cta);
        $this->assertStringNotContainsString('Array', $cta);

        // footer social: the decode loop casts the url with (string) BEFORE esc_url() sees
        // it, so an array cannot fatal there. It does coerce to the literal word Array in
        // the href — pre-existing, the same warn-not-fatal class as #736, and explicitly
        // NOT this issue's to fix. Pinned as measured behaviour so the exclusion rests on
        // what the code does rather than on an assumption that it is clean.
        $footer = $this->renderJson('footer', [
            'social' => wp_json_encode([['network' => 'x', 'url' => ['x']]]),
        ]);
        $this->assertStringContainsString('site-footer__social-link', $footer);
        // The ANCHOR RENDERS, which is the guard-relevant fact: no fatal, the value reached
        // the escaper. Deliberately NOT asserting the escaped href bytes. tests/bootstrap.php's
        // esc_url() stub is type-faithful but NOT byte-faithful — real core prepends a scheme
        // to a value with no ':' and no leading /#? (wp-includes/formatting.php,
        // `$url = $scheme . $url`), so production emits `http://Array` where the stub shows
        // `Array`. Pinning the stub's bytes here would enshrine a fiction as measured
        // production behaviour; the stub/production delta has one home, and it is
        // tests/EscapingStubContractTest.php.

        // A non-array social ENTRY is skipped outright by the same loop.
        $skipped = $this->renderJson('footer', ['social' => wp_json_encode(['not-an-entry'])]);
        $this->assertStringNotContainsString('site-footer__social-link', $skipped);

        // footer/nav logo: resolved from an attachment, so the url is always a string.
        $logo = pp_resolve_logo(['logo_id' => ['x'], 'logo_text' => 'Brand']);
        $this->assertIsString($logo['url']);
    }

    // ── The ruling's two standing constraints ────────────────────────────────

    /**
     * DEGRADE, NEVER REWRITE. The guards read; they must not migrate the store. A guard
     * that "helpfully" normalised the value would destroy the operator's evidence of what
     * was actually written, and _pp_composition_findings() would stop reporting it.
     */
    public function testTheGuardsDoNotRewriteTheStoredValues(): void
    {
        $props = ['title' => 'T', 'button_text' => 'Go', 'button_url' => ['x']];
        $this->renderJson('cta', $props);

        $stored = json_decode(get_post_meta(500, '_pp_composition', true), true);
        $this->assertSame(['x'], $stored[0]['props']['button_url'],
            'the stored value must survive the render exactly as written.');

        $this->renderJson('faq', ['title' => 'T', 'items' => 'a string']);
        $stored = json_decode(get_post_meta(500, '_pp_composition', true), true);
        $this->assertSame('a string', $stored[0]['props']['items']);
    }

    /**
     * AUTHORING-PATH MANDATE (Section 14.1). These guards exist for STORED data, and the
     * reason that class exists at all is that the validator gates WRITES, not storage. So
     * the write path must still REJECT the shapes this change makes survivable — a guard
     * that accidentally taught the front door to accept them would convert a rescue into a
     * new way to author broken pages.
     */
    public function testTheAuthoringPathStillRejectsTheseShapes(): void
    {
        // EACH FIXTURE MUST BE REJECTED FOR THE SHAPE UNDER TEST, which is not automatic.
        // The cta case originally omitted button_text and was rejected for THAT — measured:
        // the same fixture with a perfectly valid url produced the identical
        // "missing required prop" error, so the assertion would have passed unchanged
        // against a validator that happily accepted an array button_url. Every fixture below
        // is therefore otherwise complete, and the assertions check the error names the prop.
        $rejected = [
            'cta button_url'  => ['cta',     ['title' => 'T', 'button_text' => 'Go', 'button_url' => ['x']], 'button_url'],
            'section body'    => ['section', ['title' => 'T', 'body' => ['x']], 'body'],
            'embed content'   => ['embed',   ['title' => 'T', 'content' => ['x']], 'content'],
            'faq items'       => ['faq',     ['title' => 'T', 'items' => 'a string'], 'items'],
        ];

        foreach ($rejected as $label => [$component, $props, $prop]) {
            // pp_validate_composition() returns a WP_Error on rejection, not an ok=false
            // array, so is_wp_error() is the check — an array-offset read would fatal here
            // and report as an ERROR rather than as the rejection it is.
            $result = pp_validate_composition([['component' => $component, 'props' => $props]]);
            $this->assertTrue(
                is_wp_error($result),
                $label . ': the write path must still reject this shape. The render guard'
                . ' rescues STORED data; it must not widen what the front door accepts.'
            );
            $this->assertStringContainsString(
                $prop,
                $result->get_error_message(),
                $label . ': the rejection must be ABOUT this prop. A fixture rejected for an'
                . ' unrelated reason (a missing sibling prop, say) would keep this test green'
                . ' against a validator that had stopped checking the shape entirely.'
            );
        }

        // ...and the same fixtures with well-formed values are ACCEPTED, which is what makes
        // the rejections above discriminating rather than incidental.
        $accepted = [
            'cta'     => ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/a'],
            'section' => ['title' => 'T', 'body' => '<p>ok</p>'],
            'embed'   => ['title' => 'T', 'content' => '<p>ok</p>'],
            'faq'     => ['title' => 'T', 'items' => [['question' => 'Q', 'answer' => 'A']]],
        ];
        foreach ($accepted as $component => $props) {
            $this->assertFalse(
                is_wp_error(pp_validate_composition([['component' => $component, 'props' => $props]])),
                $component . ': the well-formed counterpart must still be accepted.'
            );
        }
    }
}
