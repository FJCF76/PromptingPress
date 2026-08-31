<?php
/**
 * tests/StoredStyleAndItemsRenderGuardTest.php
 *
 * #708 — a stored non-array `__pp_style` or a stored scalar grid `items` must never
 * fatal the public page.
 *
 * WHY THIS FILE EXISTS SEPARATELY. The per-component shapes are pinned in
 * ComponentPropsTest, and those render a component directly from a props array. That is
 * a renderer-level control: it proves the guard works, but not that the bad value can
 * REACH the renderer. This class closes that gap by writing real stored bytes and
 * rendering them through the loop templates/composition.php actually runs, so what is
 * asserted is what a visitor's browser receives. It is the third sibling of
 * tests/StoredImageUrlRenderGuardTest.php (#641),
 * tests/StoredBackgroundImageRenderGuardTest.php (#705) and
 * tests/StoredTitleRenderGuardTest.php (#706).
 *
 * TWO TYPED BOUNDARIES, ONE PREDICATE. Both are `array`, not `string`:
 *
 *   lib/wp.php  pp_render_style_vars(array $style, string $component_name, bool $item_scope = false)
 *   PHP builtin count(Countable|array $value, int $mode = COUNT_NORMAL): int
 *
 * so the guard is is_array, NOT the is_scalar of the three earlier siblings. That is not
 * a deviation from the family idiom, it is the idiom's stated shape-appropriate form:
 * the earlier props feed `string` parameters, where coercive mode (no
 * declare(strict_types) anywhere in this theme) means only NON-scalars ever fataled and
 * is_string would have dropped values already in storage. Here an array IS the contract —
 * every scalar fatals, and the write path says the same thing — so is_array both
 * describes the boundary and changes nothing for data that already rendered.
 *
 *   -0.0 DOES NOT APPLY HERE, and the reason is worth stating rather than
 *   cargo-culting the pin from #705/#706. That trap needs a (string) CAST meeting a
 *   downstream truthiness gate: -0.0 is falsy but (string) -0.0 is "-0", which is
 *   truthy. This guard performs no cast — a float is rejected like every other
 *   non-array — so no scalar can change which side of a gate it lands on.
 *
 * MEASURED, NOT ASSUMED. One render per component on current main, before the guard:
 * a stored string `__pp_style` raises "Argument #1 ($style) must be of type array,
 * string given" on ALL TEN components that call the helper — hero, grid, section, cta,
 * stats, faq, testimonials, logos, table and embed. The filed issue said "all five
 * image-bearing components"; the call set re-derived from source is every component
 * that declares a style slot. A stored scalar `items` raises "count(): Argument #1
 * ($value) must be of type Countable|array" on grid for "a string", 42 AND true.
 * templates/composition.php:16-26 calls pp_get_component() with no try/catch, so each
 * one is a 500 for the whole page.
 *
 * THE FILED MECHANISM FOR THE STYLE AXIS IS REFUTED, AND THAT DECIDED THE GUARD'S
 * PLACEMENT. #708 says templates/composition.php "promotes a stored `style` map to the
 * `__pp_style` prop" without checking it. It does not. All four promotion sites read
 *
 *   $style = isset($item['style']) && is_array($item['style']) ? $item['style'] : [];
 *
 * (templates/composition.php:21, templates/front-page.php:75,
 * lib/post-apply-validate.php:68, lib/admin.php:3520), so a non-array TOP-LEVEL `style`
 * is dropped before any component sees it — asserted below, so a future refactor cannot
 * quietly remove those four guards and leave this file passing. A guard added at the
 * promotion would therefore have fixed nothing at all.
 *
 * What actually reaches the helper is `__pp_style` stored INSIDE `props`. The promotion
 * only OVERWRITES that key when a valid array-valued top-level `style` exists, so a
 * stored item {"props":{"__pp_style":"red"}} walks straight through. That also explains
 * the issue's own aside that the findings engine already reports this shape as
 * `unknown_prop`: inside props, `__pp_style` IS an undeclared prop. It is reported, and
 * the page 500s anyway. So the read inside each component is the only boundary that is
 * both real and reachable, and that is where all ten guards sit.
 *
 * WHY THE STYLE AXIS IS THE LOAD-BEARING ONE. pp_render_style_vars() runs BEFORE the
 * guards #705 and #706 landed, in every affected template. Until this lands, no band is
 * genuinely un-500-able: a corrupt `__pp_style` fatals upstream of both. The combined
 * case below is the evidence that the corridor is now closed FOR THE STYLE AXIS.
 *
 * WHAT THIS DOES NOT PROMISE. Not "a band can no longer 500". This closes the style door
 * and the grid-count door; the corridor still holds #730 (core's esc_url and
 * wp_kses_post, which DO fatal on arrays in production), #733, #739 and #740 (#738 has
 * since landed — see tests/StoredGridItemsMapRenderGuardTest.php). Two
 * of those are worth naming here rather than leaving to the list below, because they
 * touch components this file sweeps and asserts "still renders" for:
 *
 *   - #739: faq reads the SAME `items` prop into pp_render_faq_schema(array $items), and
 *     that call sits OUTSIDE faq's `!empty($items)` gate — so a faq band still 500s on a
 *     stored scalar `items`, including falsy shapes ('', 0, false) that grid survives.
 *     Nothing in this file guards it, and the guard faq did receive is the style one.
 *   - #730 reaches inside a WELL-FORMED items list: a grid card's `link_url` goes to
 *     core's esc_url(), which fatals on an array. So "the grid cards still render" below
 *     holds for the fixtures used here, not for every stored card shape.
 *
 * DELIBERATELY UNTOUCHED, named so the completeness claim is not read as broader than
 * it is:
 *   - The two ITEM-SCOPE callers of the same helper, grid.php ($item_style) and
 *     section.php ($row_style), already carry is_array guards of their own and never
 *     fataled. Asserted below rather than assumed.
 *   - An ARRAY-valued `__pp_style` inside props still renders. The write path rejects it
 *     as an unknown prop, but pp_render_style_vars() gates every declaration through the
 *     #330 render boundary and the declared-slot filter, so it cannot paint anything a
 *     valid style map could not. Blocking it would EXTEND this ruling, not apply it.
 *   - An ASSOCIATIVE `items` array passes through untouched: count() accepts it, so it
 *     never fataled at THIS boundary. It is not asserted as "renders", because writing
 *     that assertion uncovered a THIRD fatal one line below this issue's — grid's
 *     `(string) ($index + 1)`, which raises "Unsupported operand types: string + int" on
 *     a string key. Filed as #738, since landed; see testAWellFormedItemsListStillRenders() for the
 *     full statement of what that means for this guard's coverage.
 *   - Malformed ELEMENTS of a well-formed items list, which are NOT uniformly safe. A
 *     card's text-ish reads (title, text, label) reach esc_html() and degrade to the
 *     literal word `Array` plus an E_WARNING (#736, warn-not-fatal), but a card's
 *     `link_url` reaches core's esc_url() and FATALS — measured: `["x"]` raises
 *     "ltrim(): Argument #1 ($string) must be of type string, array given". That is
 *     #730, open.
 *   - faq's `items` into pp_render_faq_schema(array $items), a different typed call on
 *     the same prop, outside faq's `!empty()` gate so it fatals on falsy shapes too.
 *     Filed as #739.
 *   - An OBJECT value inside a well-formed style map: pp_style_declaration_renders()
 *     does `(string) $value`, which fatals on an object with no __toString. This guard
 *     checks the CONTAINER, not its elements. Filed as #740.
 *   - A top-level scalar `style` is ACCEPTED by pp_validate_composition and then
 *     silently dropped at render. Real, not a fatal, and write-path acceptance is #707's
 *     business.
 *
 * ASSERTED AFFIRMATIVELY, NEVER BY ABSENCE OF A FATAL. phpunit.xml sets
 * failOnWarning="false", and esc_html/esc_attr render a stored array as the literal
 * string `Array` plus an E_WARNING WITHOUT fataling. A test that only proved "nothing
 * threw" would pass against a coercing implementation. Every case below asserts what the
 * emitted HTML actually contains — and the degradation cases go further, comparing the
 * degraded render byte-for-byte against the render of a band that stored no style (or no
 * items) at all, which is the exact claim the ruling makes.
 *
 * DEGRADE, NEVER REWRITE. Nothing here touches stored data. The value stays as stored,
 * the operator diagnostic still names it, and the band renders without the fragment.
 *
 *   stored props ──> pp_get_composition() ──> pp_get_component()
 *                    (plain decode, no          │
 *                     sanitising)               ├─ is_array($raw_style) ? $raw_style : []
 *                                               │    ├─ pp_render_style_vars()   ──> '' ──> no style attr
 *                                               │    ├─ pp_grid_link_align_decl() ──> ''      (grid)
 *                                               │    └─ $style['--section-…']     ──> ''      (section)
 *                                               │
 *                                               └─ is_array($raw_items) ? $raw_items : []
 *                                                    └─ !empty($items) gate ──> closed ──> no <ul>, no count
 */

use PHPUnit\Framework\TestCase;

class StoredStyleAndItemsRenderGuardTest extends TestCase
{
    /**
     * Every component that calls pp_render_style_vars() with a component-level map.
     *
     * A hand-written literal, COUPLED TO THE SOURCE by the test immediately below rather
     * than by a comment promising they agree. Without that coupling an eleventh
     * style-bearing component would fail the drift catcher in tests/InvariantTest.php,
     * a developer would update that catcher's list, and every behavioural sweep in this
     * file — the fatal-shape page, the byte-equality sweep, the corridor pin, the accept
     * side — would go on silently skipping the new component while the whole file stayed
     * green. Derived-at-runtime was rejected: an explicit list is what makes a new
     * component a decision someone takes, rather than something a glob absorbs.
     */
    private const STYLE_COMPONENTS = [
        'hero', 'grid', 'section', 'cta', 'stats', 'faq', 'testimonials', 'logos', 'table', 'embed',
    ];

    /**
     * The coupling. Fails the moment a component starts or stops calling
     * pp_render_style_vars() with a component-level map, so this file's sweeps cannot
     * quietly stop covering the full set.
     *
     * Reads comment-stripped source for the same reason every source-level checker in
     * this repo does: the ten guard blocks quote the helper's name in prose, so a raw
     * scan counts sentences as calls.
     */
    public function testTheSweptComponentListMatchesTheSource(): void
    {
        $callers = [];
        // RecursiveDirectoryIterator, not a one-level glob: the sibling catcher in
        // tests/InvariantTest.php walks components/ recursively, and a future
        // components/<name>/partials/*.php caller would otherwise be seen by one checker
        // and not the other — with this one silently under-reporting the set it exists to
        // pin. The two must sweep the same tree.
        $files = [];
        $iter  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/components'));
        foreach ($iter as $entry) {
            if ($entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $stripped = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $stripped .= $token[1];
                    continue;
                }
                $stripped .= $token;
            }
            if (preg_match('/pp_render_style_vars\(\s*\$style\s*,/', $stripped)) {
                $callers[] = basename(dirname($file));
            }
        }

        sort($callers);
        $expected = self::STYLE_COMPONENTS;
        sort($expected);
        $this->assertSame(
            $expected,
            $callers,
            'STYLE_COMPONENTS no longer matches the components that call pp_render_style_vars()'
            . ' with a component-level map. Update the constant so every sweep in this file'
            . ' covers the real set.'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
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
     * STATED PRECISELY, because "renders exactly as the template does" would overclaim:
     * this is a REPRODUCTION of that loop, not an invocation of it. Two deliberate
     * substitutions — it calls pp_get_composition($post_id) where the template calls
     * pp_composition() (which resolves the CURRENT post, and there is no global post in a
     * unit test), and it omits the pp_base_template() chrome wrapper, which renders the
     * header and footer and has nothing to do with these guards. Everything between those
     * two — the decode, the prop/style handling, and the uncaught pp_get_component() call
     * that is the actual 500 — is the template's own code path.
     *
     * The `is_array($item['style'])` line is load-bearing here in a way it was not in the
     * sibling files: it is the guard this issue's filed premise claimed was missing, so
     * this reproduction must keep it or the tests below would measure a template that
     * does not exist.
     *
     * DRIFT: if templates/composition.php's loop changes shape, update this helper in
     * lockstep. A reproduction that has silently diverged from its original still passes
     * while proving nothing about the page a visitor gets. Kept byte-identical to the same
     * helper in StoredTitleRenderGuardTest, StoredBackgroundImageRenderGuardTest and
     * StoredImageUrlRenderGuardTest so all four drift together or not at all.
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
     * Whatever each component needs in order to render a full band, with `__pp_style`
     * left for the caller to inject. Every entry deliberately carries a WELL-FORMED
     * title, body and items: the axis under test is the style map alone, and a fixture
     * carrying two corrupt shapes at once would prove nothing about either guard (the
     * lesson the #705 and #706 landings both recorded).
     *
     * EVERY BAND CARRIES AN EXPLICIT `id`, and that is a requirement rather than tidiness.
     * pp_update_composition() mints one for any entry whose `id` is empty, at WRITE time,
     * via pp_generate_component_id() — `'pp-' . bin2hex(random_bytes(4))` (lib/wp.php:3120,
     * called at :3179). So two SEPARATE writes of the same composition, which is exactly
     * what the byte-equality cases below perform (one control page, one degraded page),
     * differ in those eight hex characters. Those assertions are what actually prove
     * "degrades to exactly the markup a clean band produces"; without a pinned id they
     * would compare noise and fail for a reason that has nothing to do with either guard.
     *
     * Note the mechanism is the WRITE, not the render: no component generates an id at
     * render time — every template just reads `$props['id'] ?? ''`.
     */
    private static function bandProps(string $component): array
    {
        $base = [
            'hero'         => ['title' => 'Hero heading'],
            'grid'         => ['title' => 'Grid heading', 'items' => [['title' => 'Card one', 'text' => 'Card body']]],
            'section'      => ['title' => 'Section heading', 'body' => '<p>Section body</p>', 'layout' => 'text-only'],
            'cta'          => ['title' => 'Cta heading', 'button_text' => 'Go', 'button_url' => '/go'],
            'stats'        => ['title' => 'Stats heading', 'items' => [['number' => '40+', 'label' => 'Years']]],
            'faq'          => ['title' => 'Faq heading', 'items' => [['question' => 'Q one', 'answer' => 'A one']]],
            'testimonials' => ['title' => 'Testimonials heading', 'items' => [['quote' => 'Great work', 'author' => 'A. Person']]],
            'logos'        => ['title' => 'Logos heading', 'items' => [['image_url' => '/logo.png', 'image_alt' => 'Acme', 'label' => 'Acme']]],
            'table'        => ['title' => 'Table heading', 'headers' => ['Plan'], 'rows' => [['Free']]],
            'embed'        => ['title' => 'Embed heading', 'content' => '<iframe src="/e"></iframe>'],
        ];

        return array_merge(['id' => 'pp-band-' . $component], $base[$component]);
    }

    /**
     * The stored shapes that actually FATAL at `array $style`. Every one is non-array AND
     * truthy, so none of them is filtered out by an upstream emptiness check before the
     * call. `0` and `''` are covered separately by the byte-equality sweep, which renders
     * every shape including the falsy ones.
     */
    public static function fatalStyleShapes(): array
    {
        return [
            'css text'     => ['--hero-bg: #1a1a2e'],
            'slot name'    => ['--hero-padding-top'],
            'integer'      => [42],
            'boolean true' => [true],
            'float'        => [1.5],
        ];
    }

    /** The stored shapes that fatal at count(). Same reasoning. */
    public static function fatalItemsShapes(): array
    {
        return [
            'string'       => ['a string'],
            'integer'      => [42],
            'boolean true' => [true],
            'float'        => [1.5],
        ];
    }

    /**
     * THE primary pin for the style axis. All ten style-bearing components in ONE stored
     * composition, each carrying a malformed `__pp_style` inside its props — plus a
     * trailing good band that only renders if nothing above it threw. This is the page
     * that used to 500 ten different ways.
     *
     * @dataProvider fatalStyleShapes
     */
    public function testAStoredNonArrayPpStyleRendersThePageInsteadOfFataling($bad): void
    {
        $bands = [];
        foreach (self::STYLE_COMPONENTS as $component) {
            $bands[] = [
                'component' => $component,
                'props'     => array_merge(self::bandProps($component), ['__pp_style' => $bad]),
            ];
        }
        // Renders last, and only if every band above survived.
        $bands[] = ['component' => 'cta', 'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']];

        $id = pp_create_page('Stored bad style', 'draft');
        // Thin writer, no validation — persists the shape exactly as a pre-rule install
        // holds it, as restore_composition can replay it, and as a raw meta write leaves
        // it. Going through create_page here would be the wrong test: it REJECTS this
        // shape, which is precisely why the render path needs its own guard.
        pp_update_composition($id, $bands);

        $html = $this->renderStored($id);

        // The page is whole, and every band kept everything that is not its style map.
        $this->assertStringContainsString('Page survived', $html, 'the last band renders, so nothing above threw');
        foreach (self::STYLE_COMPONENTS as $component) {
            $this->assertStringContainsString(
                'data-pp-component="' . $component . '"',
                $html,
                $component . ': the band still renders'
            );
        }
        $this->assertStringContainsString('Card one', $html, 'the grid cards still render');
        $this->assertStringContainsString('<p>Section body</p>', $html, 'the section body still renders');
        $this->assertStringContainsString('40+', $html, 'the stats numbers still render');
        $this->assertStringContainsString('Q one', $html, 'the faq questions still render');
        $this->assertStringContainsString('Great work', $html, 'the testimonial quotes still render');
        $this->assertStringContainsString('Acme', $html, 'the logo names still render');

        // And not one custom property was painted anywhere on the page.
        $this->assertStringNotContainsString('--hero-', $html, 'no hero custom property is emitted');
        $this->assertStringNotContainsString('--grid-padding', $html, 'no grid custom property is emitted');
        $this->assertStringNotContainsString('--section-padding', $html, 'no section custom property is emitted');

        // failOnWarning is false and esc_attr renders an array as the literal `Array`
        // without fataling, so this is the assertion that separates DEGRADED from COERCED.
        $this->assertStringNotContainsString('Array', $html, 'the value is degraded, never coerced into the page');
    }

    /**
     * THE DEGRADATION CLAIM ITSELF, asserted the only way that actually proves it: render
     * each component with a malformed `__pp_style` and render it again with NO style key
     * at all, and require the two to be BYTE-IDENTICAL.
     *
     * Every earlier assertion in this file is a substring check, and a substring check
     * cannot see an empty `style=""` attribute, a stray semicolon, or a lost space — the
     * exact residue a "returns '' so nothing is emitted" argument would leave if any one
     * of the ten call sites built its attribute unconditionally. (None does: all ten gate
     * on the helper's return value. This test is what keeps that true.) Byte-equality is
     * also the strongest possible reading of "zero rendering change", so it is applied to
     * every non-array shape, falsy ones included, not just the truthy fatal set.
     */
    public function testEveryComponentDegradesToTheSameMarkupANoStyleBandProduces(): void
    {
        $shapes = ['--hero-bg: #1a1a2e', 42, true, 1.5, 0, '', false, null];

        foreach (self::STYLE_COMPONENTS as $component) {
            $clean_id = pp_create_page('Clean ' . $component, 'draft');
            pp_update_composition($clean_id, [['component' => $component, 'props' => self::bandProps($component)]]);
            $clean = $this->renderStored($clean_id);
            $this->assertNotSame('', $clean, $component . ': the control render is non-empty');

            foreach ($shapes as $i => $bad) {
                $bad_id = pp_create_page('Bad ' . $component . ' ' . $i, 'draft');
                pp_update_composition($bad_id, [[
                    'component' => $component,
                    'props'     => array_merge(self::bandProps($component), ['__pp_style' => $bad]),
                ]]);

                $this->assertSame(
                    $clean,
                    $this->renderStored($bad_id),
                    $component . ': a stored ' . gettype($bad) . ' __pp_style renders byte-identically to no style at all'
                );
            }
        }
    }

    /**
     * THE primary pin for the count axis, and its degradation claim in one: a grid whose
     * stored `items` is a scalar renders byte-identically to a grid that stored no items.
     *
     * That equality is the whole ruling for this axis. The guard sits at the READ, which
     * is upstream of the `if (!empty($items))` gate, so the gate closes and the band emits
     * no `<ul>`, no `data-pp-count` and no cards — rather than an empty list element
     * carrying `data-pp-count="0"`, which is what widening the count() call would have
     * produced and which no valid composition ever emits.
     *
     * The control fixture omits `items` entirely, so under WP_DEBUG it trips
     * pp_get_component()'s required-prop notice ("component 'grid' missing required prop
     * 'items'"). That notice is a property of the CONTROL, not of the guard — the degraded
     * render carries a stored `items` key and does not trip it — and the two still emit
     * identical bytes, which is the claim under test. Left as-is rather than silenced: the
     * control has to be a genuinely items-less grid for the comparison to mean anything.
     *
     * @dataProvider fatalItemsShapes
     */
    public function testAStoredScalarItemsRendersTheGridWithoutItsList($bad): void
    {
        $clean_id = pp_create_page('Grid no items', 'draft');
        pp_update_composition($clean_id, [['component' => 'grid', 'props' => ['id' => 'pp-band-grid', 'title' => 'Grid heading']]]);
        $clean = $this->renderStored($clean_id);

        $bad_id = pp_create_page('Grid bad items', 'draft');
        pp_update_composition($bad_id, [['component' => 'grid', 'props' => ['id' => 'pp-band-grid', 'title' => 'Grid heading', 'items' => $bad]]]);
        $html = $this->renderStored($bad_id);

        $this->assertSame($clean, $html, 'a scalar items renders byte-identically to no items at all');

        // Affirmative, not just equality: name what is and is not in the emitted bytes.
        $this->assertStringContainsString('data-pp-component="grid"', $html, 'the grid band still renders');
        $this->assertStringContainsString('Grid heading', $html, 'and keeps its heading');
        $this->assertStringNotContainsString('data-pp-count', $html, 'the count attribute is not emitted');
        $this->assertStringNotContainsString('grid__list', $html, 'and neither is the list element');
        $this->assertStringNotContainsString('Array', $html, 'the value is degraded, never coerced into the page');
    }

    /**
     * A malformed `items` must not take the whole PAGE down either, and the trailing
     * survivor band is the only assertion that actually proves it.
     */
    public function testAMalformedItemsDoesNotStopLaterBandsRendering(): void
    {
        $id = pp_create_page('Grid bad items in page', 'draft');
        pp_update_composition($id, [
            ['component' => 'grid', 'props' => ['id' => 'pp-band-grid', 'title' => 'Grid heading', 'items' => 'a string']],
            ['component' => 'cta',  'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('Page survived', $html, 'the band after the malformed grid renders');
    }

    /**
     * GRID'S SECOND TYPED BOUNDARY on the same read, pinned separately because deleting
     * the first call would otherwise silently reopen it.
     *
     * pp_grid_link_align_decl(array $style) is typed identically to
     * pp_render_style_vars() and fatals identically on a non-array. On current main it is
     * unreachable only because the pp_render_style_vars() call two lines above throws
     * first — an ordering accident, not a guarantee. Both now read the one guarded local,
     * so this asserts the boundary directly rather than trusting the ordering.
     */
    public function testGridsSecondTypedStyleBoundaryTakesTheGuardedValue(): void
    {
        // Direct call: a non-array would raise "Argument #1 ($style) must be of type
        // array" here exactly as it does at the helper next door.
        $this->assertSame('', pp_grid_link_align_decl([]), 'an empty map yields no alignment declaration');

        // And through the render path, a well-formed per-grid alignment slot still paints,
        // so the consolidation onto the guarded local did not disarm the feature.
        $id = pp_create_page('Grid link align', 'draft');
        pp_update_composition($id, [[
            'component' => 'grid',
            'props'     => ['title' => 'Grid heading', 'items' => [['title' => 'Card one', 'text' => 'Body']]],
            'style'     => ['--grid-item-text-align' => 'center'],
        ]]);

        $html = $this->renderStored($id);
        $this->assertStringContainsString('--grid-item-text-align', $html, 'the authored slot still paints');
        $this->assertStringContainsString('--pp-grid-link-align', $html, 'and still derives its link-alignment companion');
    }

    /**
     * SECTION'S THIRD READ of the same prop, folded onto the guarded local. It never
     * fataled — `??` uses isset() semantics and isset() on a non-numeric string offset is
     * false — so the only thing to prove is that folding it changed nothing for a
     * well-formed map.
     */
    public function testSectionInlineItemsAlignStillReadsAWellFormedMap(): void
    {
        $id = pp_create_page('Section inline align', 'draft');
        pp_update_composition($id, [[
            'component' => 'section',
            'props'     => ['title' => 'Section heading', 'body' => '<p>b</p>', 'layout' => 'text-only', 'body_items' => ['One', 'Two']],
            'style'     => ['--section-inline-items-align' => 'center'],
        ]]);

        $html = $this->renderStored($id);
        $this->assertStringContainsString('section__inline-items--center', $html, 'the centering modifier still derives from the slot');

        // And a malformed map falls through to the unchanged left-packed default.
        $bad_id = pp_create_page('Section inline align bad', 'draft');
        pp_update_composition($bad_id, [[
            'component' => 'section',
            'props'     => ['title' => 'Section heading', 'body' => '<p>b</p>', 'layout' => 'text-only', 'body_items' => ['One', 'Two'], '__pp_style' => 'center'],
        ]]);

        $bad_html = $this->renderStored($bad_id);
        $this->assertStringContainsString('section__inline-items', $bad_html, 'the row still renders');
        $this->assertStringNotContainsString('section__inline-items--center', $bad_html, 'without deriving the modifier from a malformed map');
    }

    /**
     * THE ACCEPT SIDE for the style axis, on real stored bytes: a well-formed map still
     * paints its custom property on every one of the ten components. A guard that blanked
     * legitimate style maps would pass every negative test in this file.
     */
    public function testAWellFormedStyleMapStillPaintsOnEveryComponent(): void
    {
        foreach (self::STYLE_COMPONENTS as $component) {
            $slot = '--' . $component . '-padding-top';
            $id   = pp_create_page('Styled ' . $component, 'draft');
            pp_update_composition($id, [[
                'component' => $component,
                'props'     => self::bandProps($component),
                'style'     => [$slot => '8rem'],
            ]]);

            $html = $this->renderStored($id);
            $this->assertStringContainsString(
                $slot . ': 8rem',
                $html,
                $component . ': a well-formed style slot still paints'
            );
        }
    }

    /**
     * THE ACCEPT SIDE for the count axis: a well-formed list still renders and still
     * reports its count.
     *
     * WHAT IS NOT ASSERTED HERE, and why the omission is deliberate. The obvious companion
     * case is an ASSOCIATIVE `items` array — count() accepts one, so it never fataled at
     * this issue's boundary, and the guard correctly passes it through untouched. Writing
     * that assertion is what found a THIRD stored-shape render fatal, ONE LINE below the
     * one this issue fixes and outside its two boundaries:
     *
     *   components/grid/grid.php   $item_number = $item['number'] ?? (string) ($index + 1);
     *
     * `??` short-circuits, so the arithmetic runs only for an element that OMITS `number`.
     * Measured both ways: an associative list whose every element carries `number` renders
     * a full band, while one element without it raises "Unsupported operand types:
     * string + int" on the non-numeric key and 500s the page exactly as count() did. So
     * the flat assertion "an associative array renders" would be false for the shape that
     * matters, which is why the case is documented here instead of half-asserted.
     *
     * Filed separately rather than fixed in passing: this issue's ruling names two
     * boundaries, and a third is a scope extension rather than an application of it. Filed
     * as #738. At the time this file landed, the honest statement of this guard's coverage
     * was that it makes every NON-ARRAY `items` safe and leaves array shapes exactly as it
     * found them — including one that was still broken.
     *
     * #738 HAS SINCE LANDED, and the assertion this docblock was written to justify
     * WITHHOLDING now exists — in tests/StoredGridItemsMapRenderGuardTest.php, the fifth
     * file in this family, which renders a stored map both ways (entries with `number`
     * and entries without) and compares the degraded band against the equivalent list.
     * It stays there rather than moving here for the reason every fixture in this file
     * carries exactly one corrupt axis: what THIS file measures is the count() and
     * style-vars boundaries, and folding a second issue's shape into its fixtures is how
     * a green file stops proving what its name claims. The case below is unchanged — a
     * well-formed LIST is the control both files need.
     */
    public function testAWellFormedItemsListStillRenders(): void
    {
        $id = pp_create_page('Grid list items', 'draft');
        pp_update_composition($id, [['component' => 'grid', 'props' => [
            'title' => 'Grid heading',
            'items' => [['title' => 'Card one', 'text' => 'One'], ['title' => 'Card two', 'text' => 'Two']],
        ]]]);

        $html = $this->renderStored($id);
        $this->assertStringContainsString('data-pp-count="2"', $html, 'a well-formed list still reports its count');
        $this->assertStringContainsString('Card one', $html);
        $this->assertStringContainsString('Card two', $html);

    }

    /**
     * THE CORRIDOR-CLOSURE PIN, and the reason this issue was scheduled as the
     * load-bearing member of its family.
     *
     * pp_render_style_vars() runs BEFORE the heading and background guards that #705 and
     * #706 landed. So before this issue, a band carrying a corrupt `__pp_style` fatalled
     * upstream of both, and neither of those guards could matter. This renders a
     * composition in which EVERY style-bearing component carries all three corrupt shapes
     * at once — `__pp_style`, `title`, `title_accent`, and `background_image` where the
     * component takes one — and requires the page to survive intact.
     *
     * Applied across all ten components rather than one sampled band, because a
     * single-band version would prove the corridor closed on one path and be quoted as
     * though it proved it everywhere.
     *
     * SCOPED HONESTLY, and the fixture is built around three open siblings rather than
     * pretending they are shut:
     *   - #730 (core's esc_url / wp_kses_post, which DO fatal on arrays in production) and
     *     #733 remain open on this corridor, so no band here carries a link or rich-text
     *     prop. A fixture that tripped #730 would fail this test for a reason that is not
     *     this issue's.
     *   - The corrupt `title` goes only to the SEVEN components #706 guards. logos, table
     *     and embed read the same prop into esc_html(), which does not fatal — it paints
     *     the literal word `Array` plus an E_WARNING. That is #736, deliberately still
     *     open, so those three keep well-formed titles here. Feeding them the corrupt one
     *     would make the "nothing was coerced into the page" assertion below fail on a
     *     defect this issue does not own, and quietly turn a corridor pin into a #736
     *     regression test.
     */
    public function testACombinedCorruptBandSurvivesEveryLandedAxisAtOnce(): void
    {
        $corrupt = ['en' => 'Our services', 'es' => 'Nuestros servicios'];
        $takes_background = ['section', 'cta', 'stats'];
        // The #706 set: the components that pass `title` into pp_render_heading_with_accent().
        $guarded_title = ['hero', 'grid', 'section', 'cta', 'stats', 'faq', 'testimonials'];

        $bands = [];
        foreach (self::STYLE_COMPONENTS as $component) {
            $props = array_merge(self::bandProps($component), [
                '__pp_style' => '--hero-bg: #1a1a2e',
            ]);
            if (in_array($component, $guarded_title, true)) {
                $props['title']        = $corrupt;
                $props['title_accent'] = $corrupt;
            }
            if (in_array($component, $takes_background, true)) {
                $props['background_image'] = $corrupt;
            }
            $bands[] = ['component' => $component, 'props' => $props];
        }
        $bands[] = ['component' => 'cta', 'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']];

        $id = pp_create_page('Corridor closure', 'draft');
        pp_update_composition($id, $bands);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('Page survived', $html, 'the whole corridor is walkable: the last band renders');
        foreach (self::STYLE_COMPONENTS as $component) {
            $this->assertStringContainsString(
                'data-pp-component="' . $component . '"',
                $html,
                $component . ': the band renders despite three corrupt props at once'
            );
        }

        // Everything that is not one of the three corrupt axes survived.
        $this->assertStringContainsString('Card one', $html, 'the grid cards still render');
        $this->assertStringContainsString('<p>Section body</p>', $html, 'the section body still renders');
        $this->assertStringContainsString('40+', $html, 'the stats numbers still render');

        // And nothing was coerced into the page.
        $this->assertStringNotContainsString('Array', $html, 'no corrupt value is painted as the literal word Array');
        $this->assertStringNotContainsString('Nuestros', $html, 'no corrupt value leaks its contents into the page');
    }

    /**
     * The four PROMOTION sites this issue's filed premise claimed were unguarded are in
     * fact guarded, and this pins that at the behavioural level for the one the public
     * page uses. If a refactor ever removes the `is_array($item['style'])` check, a
     * non-array top-level `style` would start reaching the components — where the new
     * guard now catches it, so the page would still not 500, but the premise correction
     * recorded here would have silently gone stale.
     */
    public function testANonArrayTopLevelStyleIsDroppedBeforeItReachesAComponent(): void
    {
        $clean_id = pp_create_page('Hero no style', 'draft');
        pp_update_composition($clean_id, [['component' => 'hero', 'props' => self::bandProps('hero')]]);
        $clean = $this->renderStored($clean_id);

        $id = pp_create_page('Hero scalar top-level style', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => self::bandProps('hero'),
            'style'     => 'not-an-array',
        ]]);

        $this->assertSame($clean, $this->renderStored($id), 'a non-array top-level style never reaches the component');
    }

    /**
     * The ITEM-SCOPE callers of the same helper already carried is_array guards of their
     * own and are deliberately untouched by this issue. Asserted rather than assumed, so
     * "already safe" is a measured claim and a future refactor that drops one of those
     * guards fails here instead of in production.
     */
    public function testTheItemScopeStyleCallersWereAlreadyGuarded(): void
    {
        $id = pp_create_page('Item scope styles', 'draft');
        pp_update_composition($id, [
            ['component' => 'grid', 'props' => [
                'title' => 'Grid heading',
                'items' => [['title' => 'Card one', 'text' => 'One', 'style' => 'not-an-array']],
            ]],
            ['component' => 'section', 'props' => [
                'title'       => 'Section heading',
                'layout'      => 'text-panel',
                'body'        => '<p>b</p>',
                'panel_items' => [['label' => 'L', 'value' => 'V', 'style' => 'not-an-array']],
            ]],
            ['component' => 'cta', 'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('Page survived', $html, 'a non-array per-item style does not fatal the page');
        $this->assertStringContainsString('Card one', $html, 'the card still renders');
        $this->assertStringContainsString('grid__item', $html, 'with its list item element intact');

        // The section half, asserted rather than assumed. Without this, a fixture that
        // stopped producing a panel row (layout key renamed, panel_items gated
        // differently) would leave 'Page survived' true while covering nothing — an
        // unrendered row cannot fatal, so the test would pass vacuously.
        $this->assertStringContainsString('section__panel-row', $html, 'the section panel row is actually reached');
        $this->assertStringContainsString('>L<', $html, 'with its label rendered');
        $this->assertStringContainsString('>V<', $html, 'and its value');
    }

    /**
     * A malformed `__pp_style` NEXT TO a well-formed sibling inline style, on the four
     * components that MERGE the two into one attribute.
     *
     * hero, cta, section and stats do not emit the helper's return value directly: they
     * push it into an `$inline_styles` array alongside a `background-image` declaration
     * and implode the result. The byte-equality sweep above cannot see a regression here,
     * because its control band carries no background either — so a change that appended
     * the helper's output unconditionally would emit `style="; background-image:url(…)"`
     * on a live page with every existing assertion still green.
     *
     * The background value is deliberately WELL-FORMED. This is the one place the axes
     * are intentionally mixed, and only in one direction: corrupt style, good background,
     * so what is measured is whether the degraded fragment leaves residue in an attribute
     * that still has real content to carry.
     *
     * hero reaches the same merge by a different prop pair — `layout: cover` plus
     * `image_url`, not `background_image` (which it does not read at all; #705's
     * background inventory is cta/section/stats). Each component therefore contributes
     * whatever props actually make it paint, and the control assertion below fails loudly
     * if one of them stops painting, so this test cannot quietly become a no-op.
     */
    public function testAMalformedStyleLeavesNoResidueInAMergedStyleAttribute(): void
    {
        $background_props = [
            'hero'    => ['layout' => 'cover', 'image_url' => '/bg.png'],
            'cta'     => ['background_image' => '/bg.png'],
            'section' => ['background_image' => '/bg.png'],
            'stats'   => ['background_image' => '/bg.png'],
        ];

        foreach ($background_props as $component => $background) {
            $clean_id = pp_create_page('Merged clean ' . $component, 'draft');
            pp_update_composition($clean_id, [[
                'component' => $component,
                'props'     => array_merge(self::bandProps($component), $background),
            ]]);
            $clean = $this->renderStored($clean_id);
            $this->assertStringContainsString('background-image', $clean, $component . ': the control actually paints a background');

            $bad_id = pp_create_page('Merged bad ' . $component, 'draft');
            pp_update_composition($bad_id, [[
                'component' => $component,
                'props'     => array_merge(self::bandProps($component), $background, ['__pp_style' => 'a string']),
            ]]);
            $bad = $this->renderStored($bad_id);

            $this->assertSame(
                $clean,
                $bad,
                $component . ': a malformed style map leaves the merged style attribute byte-identical'
            );
            $this->assertStringNotContainsString('style="; ', $bad, $component . ': no empty leading segment');
            $this->assertStringNotContainsString(';;', $bad, $component . ': no doubled separator');
        }
    }

    /**
     * An ARRAY-valued `__pp_style` stored inside props still renders — and cannot paint
     * anything a validly-authored style map could not.
     *
     * This pins the claim components/grid/grid.php makes in prose. That shape is an
     * undeclared prop the write path rejects, so it is the one place this change lets
     * operator-uncontrolled data reach the style renderer as an array. It is safe because
     * pp_render_style_vars() gates every declaration twice: the slot NAME must be one the
     * component's own schema declares, and the VALUE must clear _pp_forbidden_css_construct
     * plus the slot's declared grammar (#330). Asserting it here means a future relaxation
     * of either gate fails a test instead of quietly painting attacker-shaped CSS.
     */
    public function testAnArrayPpStyleInPropsCannotPaintMoreThanAValidStyleMap(): void
    {
        $id = pp_create_page('Adversarial style map', 'draft');
        pp_update_composition($id, [[
            'component' => 'hero',
            'props'     => array_merge(self::bandProps('hero'), ['__pp_style' => [
                '--hero-bg'                 => '#123456',            // declared + valid: must paint
                '--hero-bg; background'     => 'red',                // slot name carrying a separator
                '--not-a-declared-slot'     => 'red',                // undeclared slot
                '--hero-padding-top'        => 'red; background-image:url(//evil/x)',
                '--hero-padding-bottom'     => 'url(//evil/x.png)',
                '--grid-bg'                 => '#000000',            // another component's slot
            ]]),
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('--hero-bg: #123456', $html, 'the one declared, valid declaration paints');
        $this->assertStringNotContainsString('evil', $html, 'no injected url survives the render boundary');
        $this->assertStringNotContainsString('background-image:url(//', $html, 'no smuggled declaration is emitted');
        $this->assertStringNotContainsString('--not-a-declared-slot', $html, 'an undeclared slot is dropped');
        $this->assertStringNotContainsString('--grid-bg', $html, "another component's slot is dropped");
    }

    /**
     * The stored bytes are not touched. Degrade, never rewrite: reading the composition
     * back reports exactly what was written, so the render-time degradation cannot be
     * mistaken for a migration and a later fix-up still sees the original value.
     */
    public function testTheGuardsDoNotRewriteTheStoredValues(): void
    {
        $id = pp_create_page('Stored values preserved', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero', 'props' => ['title' => 'T', '__pp_style' => '--hero-bg: #1a1a2e']],
            ['component' => 'grid', 'props' => ['title' => 'T', 'items' => 'a string']],
        ]);

        $this->renderStored($id);

        $stored = pp_get_composition($id);
        $this->assertSame('--hero-bg: #1a1a2e', $stored[0]['props']['__pp_style'], 'the stored style value is untouched');
        $this->assertSame('a string', $stored[1]['props']['items'], 'the stored items value is untouched');
    }

    /**
     * The write path stays STRICT for both shapes (rule 14.1: exercise the real authoring
     * surface, not a raw meta write). These render guards are defense for data that is
     * already stored; they must not become a reason to accept the shapes at the front
     * door.
     */
    public function testTheAuthoringPathStillRejectsBothShapes(): void
    {
        $scalar_items = pp_execute_action('create_page', [
            'title'       => 'Rejected items',
            'composition' => [['component' => 'grid', 'props' => ['title' => 'T', 'items' => 'a string']]],
        ]);
        $this->assertFalse($scalar_items['ok'], 'a scalar items must not be accepted at write');
        $this->assertStringContainsString('items', $scalar_items['error'], 'the error names the prop');
        $this->assertStringContainsString('must be an array', $scalar_items['error'], 'with the type rule');

        $props_style = pp_execute_action('create_page', [
            'title'       => 'Rejected style',
            'composition' => [['component' => 'hero', 'props' => ['title' => 'T', '__pp_style' => 'red']]],
        ]);
        $this->assertFalse($props_style['ok'], '__pp_style inside props must not be accepted at write');
        $this->assertStringContainsString('__pp_style', $props_style['error'], 'the error names the prop');
    }

    /**
     * The operator diagnostic still names both stored values. Degrading at render must not
     * make the page look healthy to the surface an operator would use to find the problem.
     */
    public function testTheStoredValuesAreStillReportedAsFindings(): void
    {
        $style_findings = _pp_composition_findings([
            ['component' => 'hero', 'props' => ['title' => 'T', '__pp_style' => 'red']],
        ]);
        $this->assertNotEmpty($style_findings, 'the malformed style value is still surfaced');
        $this->assertStringContainsString('__pp_style', json_encode($style_findings), 'the finding names the prop');

        $items_findings = _pp_composition_findings([
            ['component' => 'grid', 'props' => ['title' => 'T', 'items' => 'a string']],
        ]);
        $this->assertNotEmpty($items_findings, 'the malformed items value is still surfaced');
        $this->assertStringContainsString('items', json_encode($items_findings), 'the finding names the prop');
    }
}
