<?php
/**
 * tests/StoredTitleRenderGuardTest.php
 *
 * #706 — a stored non-scalar `title` or `title_accent` must never fatal the public page.
 *
 * WHY THIS FILE EXISTS SEPARATELY. The per-component shapes are pinned in
 * ComponentPropsTest, and those render a component directly from a props array. That is
 * a renderer-level control: it proves the guard works, but it does not prove the bad
 * value can REACH the renderer. This class closes that gap by writing real stored bytes
 * and rendering them through the loop templates/composition.php actually runs, so what
 * is asserted is what a visitor's browser receives. It is the sibling of
 * tests/StoredImageUrlRenderGuardTest.php (#641) and
 * tests/StoredBackgroundImageRenderGuardTest.php (#705).
 *
 * THE DEFECT, AND IT IS WIDER THAN THE ISSUE FILED. One helper, BOTH of its text
 * parameters typed:
 *
 *   lib/wp.php  pp_render_heading_with_accent(string $title, string $accent, string $accent_class)
 *
 * Seven components read `title` / `title_accent` from stored props and pass them
 * straight in: hero, grid, section (three layout branches), cta, stats, faq,
 * testimonials. Measured on current main, one render per component: a stored ARRAY
 * `title` raises "Argument #1 ($title) must be of type string, array given" on ALL
 * SEVEN, and a stored array `title_accent` NEXT TO A PERFECTLY GOOD TITLE raises the
 * same on Argument #2, again on all seven. The filed issue claimed argument #2 for hero
 * alone; re-deriving the call set from the source showed it is every one of them, which
 * is why both props are guarded everywhere. templates/composition.php:16-26 calls
 * pp_get_component() with no try/catch, so one malformed stored value returns a
 * whole-page 500 rather than a band with a missing heading. `title` sits on nearly
 * every band, which makes this the widest blast radius in the theme.
 *
 * (Catchable in principle; deliberately not caught in practice, and adding a catch is
 * NOT the fix — see the wp_kses_post note under SCOPE below for why swallowing an
 * escaping throw is worse than the throw.)
 *
 * THE GUARD SITS AT THE READ, AND THAT PLACEMENT IS THE BEHAVIOUR. Widening the
 * helper's signature to accept mixed would be a one-file diff instead of seven, and it
 * would not produce the degradation D-B asks for. In six of the seven components a
 * truthiness gate (`if ($title)`, plus the header gates reading
 * `$title || $eyebrow || $subheading`) sits UPSTREAM of the call, and a stored array is
 * TRUTHY — so a mixed-typed helper would let those gates open and emit an empty `<h2>`
 * inside a header wrapper that exists only to hold it. Guarding at the read closes the
 * gates instead: the band renders WITHOUT its heading, which is the ruling.
 *
 * THE PREDICATE IS is_scalar, NOT is_string, AND THAT IS LOAD-BEARING. PHP runs coercive
 * here (no declare(strict_types)), so only NON-SCALARS ever fataled — a stored `42`
 * coerced at the boundary and rendered the heading "42". #707 has since narrowed the
 * WRITE path so a scalar title is refused, but this guard's subject is STORAGE, not
 * writes: a pre-#707 composition, a restored snapshot (#233) and a raw meta write all
 * still hold it, and it still has to render. So:
 *
 *   NON-SCALAR -> ""            CHANGED: the fatal, now a degraded render.
 *   SCALAR     -> (string) cast UNCHANGED: as it rendered before the guard.
 *
 * Scoped precisely, because "only non-scalars ever fataled" is slightly too broad as
 * usually stated: coercive mode would ALSO have accepted a __toString object, which this
 * guard blanks. That is unreachable from the store rather than merely unlikely —
 * pp_get_composition() decodes with json_decode($raw, true) (lib/wp.php:230), which
 * yields arrays and never objects, so an ARRAY is the only non-scalar these props can
 * hold.
 *
 * TWO WRINKLES THIS PROP PAIR HAS AND #705's DID NOT.
 *
 * 1. HERO'S CALL IS UNGATED. Its `<h1>` renders unconditionally, so a guarded-away title
 *    emits `<h1 class="hero__title"></h1>` — the element, empty — rather than no
 *    heading. That is not a new shape: a stored empty-string title emits exactly those
 *    bytes today and always has, so the guard makes a non-scalar render identically to a
 *    case that has shipped since the component existed (asserted below, by comparing the
 *    two renders). Adding a gate to hero was considered and REJECTED: it would change
 *    rendering for well-formed data (an intentionally empty title would stop emitting
 *    the h1), which D-B forbids. The honest cost: corrupt stored data still leaves an
 *    empty page heading in the accessibility tree. Much smaller than a 500, but real,
 *    and closing it means changing hero's markup contract — its own ruling, not a rider.
 *
 * 2. HERO AND FAQ HAVE NON-EMPTY `??` DEFAULTS ('Default Title', 'Frequently Asked
 *    Questions'); all three of #705's sites defaulted to ''. The guard's else-branch is
 *    '' and NOT the default, because `??` fires only when the key is ABSENT while a
 *    stored non-scalar is PRESENT. Degrading a corrupt title into those placeholder
 *    words would paint invented content onto a visitor's page. Degrade means render
 *    less, never make something up — asserted below.
 *
 * SCOPE. This closes the NAMED typed call for the NAMED prop pair: `title` and
 * `title_accent` into pp_render_heading_with_accent(), on all seven components that make
 * that call. The set is complete for the PUBLIC RENDER PATH — verified by grep for the
 * helper name across components/, templates/ and lib/, and by grep for dynamic
 * `$props[$var]` access, which no component template uses.
 *
 * THREE COMPONENTS SURVIVE A PROP-NAME GREP AND ARE DELIBERATELY LEFT ALONE, named here
 * so the completeness claim is not read as broader than it is: logos.php:13,
 * table.php:13 and embed.php:18 all read `$props['title']` and pass it to esc_html(),
 * which does NOT fatal — it renders the literal string `Array` plus an E_WARNING
 * (measured). Same prop, different boundary, so the admitting criterion #641 set for
 * this family applies: the same TYPED CALL, not the same prop and not the same file.
 * Filed separately rather than smuggled in here. The source-level drift catcher in
 * InvariantTest is keyed on the CALL for exactly this reason.
 *
 * The same defect class through OTHER surfaces is deliberately NOT fixed here. #708
 * (count() on a scalar items, pp_render_style_vars on a non-array style) has since
 * LANDED — see tests/StoredStyleAndItemsRenderGuardTest.php. Still filed and open: #730
 * (core's esc_url/wp_kses_post, which DO fatal in production), #733 (lib/ai-context.php's
 * mb_strlen()/basename() on this same raw title, the AI page-context index — so a
 * composition this guard makes safe to VIEW can still fatal the surface an operator
 * would use to DIAGNOSE it), and #707 (what the write path accepts — since narrowed to
 * is_string, which changed nothing here: this file's subject is stored data). Never try/catch a
 * wp_kses_post TypeError to degrade: the throw escapes between core's
 * remove_filter('pre_kses', …) and the matching re-add, so swallowing it de-registers
 * block-attribute KSES for the rest of the request. Guard BEFORE the call.
 *
 * WHY STORED DATA IS THE POINT. The write path rejects non-scalars (asserted below, so a
 * future change cannot relax it and call this issue fixed). But the validator gates
 * WRITES, not storage:
 *
 *   - a composition authored before the type rules landed still carries the value,
 *   - restore_composition restores and REPORTS, and never blocks (#233),
 *   - a raw `_pp_composition` meta write is not gated at all.
 *
 * A stricter write path does not repair a page that ALREADY stores the bad value. That
 * page is what 500s, and that is what the render guard covers.
 *
 * WHAT THIS DOES NOT PROMISE. "A band with a heading can no longer 500" would be too
 * strong, and the ordering is the reason. pp_render_style_vars() runs BEFORE the heading
 * in these templates, so when this file landed, a band carrying both a non-array
 * `__pp_style` and a bad title still fataled — via #708, upstream of this guard, which
 * never got to matter. #708 has since LANDED and shut that door; the combined case is
 * pinned in tests/StoredStyleAndItemsRenderGuardTest.php rather than here, because every
 * fixture below still deliberately carries NO `style` key so that what THESE assertions
 * measure is this guard and not the corridor around it. The corridor is still not fully
 * closed: #730, #733, #736, #738, #739 and #740 remain open on it. Note for the seven components
 * swept here in particular: a faq band still 500s on a stored scalar `items` via #739,
 * and a grid card's array `link_url` still 500s via #730.
 *
 * ASSERTED AFFIRMATIVELY, NEVER BY ABSENCE OF A FATAL. phpunit.xml sets
 * failOnWarning="false", and esc_html/esc_attr render a stored array as the literal
 * string `Array` plus an E_WARNING WITHOUT fataling. A test that only proved "nothing
 * threw" would pass against a coercing implementation that printed `Array` as the
 * heading of every band on the page. So every case below asserts what the emitted HTML
 * actually contains.
 *
 * DEGRADE, NEVER REWRITE. Nothing here touches stored data (v1.13 posture). The value
 * stays exactly as stored, the operator diagnostic still names it, and the page renders
 * without the heading — the same rendering an empty title has always produced.
 *
 *   stored props ──> pp_get_composition() ──> pp_get_component()
 *                    (plain decode, no          │
 *                     sanitising)               ├─ is_scalar($raw_title) ? (string) $raw_title : ''
 *                                               ├─ is_scalar($raw_title_accent) ? … : ''
 *                                               │
 *                                               ├─ header wrapper gate ──> skipped   grid/section/testimonials
 *                                               ├─ text-block gate     ──> skipped   cta
 *                                               ├─ `if ($title)` gate  ──> skipped   6 of 7
 *                                               ├─ hero: NO gate       ──> empty <h1>
 *                                               └─ pp_render_heading_with_accent() ──> never reached
 */

use PHPUnit\Framework\TestCase;

class StoredTitleRenderGuardTest extends TestCase
{
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
     * header and footer and has nothing to do with this guard. Everything between those
     * two — the decode, the prop/style handling, and the uncaught pp_get_component() call
     * that is the actual 500 — is the template's own code path.
     *
     * DRIFT: if templates/composition.php's loop changes shape, update this helper in
     * lockstep. A reproduction that has silently diverged from its original still passes
     * while proving nothing about the page a visitor gets. Kept byte-identical to the
     * same helper in StoredBackgroundImageRenderGuardTest and StoredImageUrlRenderGuardTest
     * so all three drift together or not at all.
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
     * One band per component that calls the heading helper, each carrying whatever else it
     * needs to render, with `title` / `title_accent` left for the caller to inject. Every
     * band deliberately carries NO `style` key — a non-array `__pp_style` fataled upstream
     * of this guard until #708 landed, and a fixture carrying two corrupt shapes proves
     * nothing about either. Kept style-free now that #708 has landed, for the same reason:
     * axis isolation is what makes these assertions mean #706 and only #706. The combined
     * case lives in tests/StoredStyleAndItemsRenderGuardTest.php.
     *
     * @param mixed $title
     * @param mixed $accent
     */
    private function allSevenBands($title, $accent): array
    {
        return [
            ['component' => 'hero',         'props' => ['title' => $title, 'title_accent' => $accent]],
            ['component' => 'grid',         'props' => ['title' => $title, 'title_accent' => $accent, 'items' => [['title' => 'Card', 'body' => 'Body']]]],
            ['component' => 'section',      'props' => ['title' => $title, 'title_accent' => $accent, 'body' => '<p>Section body</p>', 'layout' => 'text-only']],
            ['component' => 'cta',          'props' => ['title' => $title, 'title_accent' => $accent, 'button_text' => 'Go', 'button_url' => '/go']],
            ['component' => 'stats',        'props' => ['title' => $title, 'title_accent' => $accent, 'items' => [['number' => '40+', 'label' => 'Years']]]],
            ['component' => 'faq',          'props' => ['title' => $title, 'title_accent' => $accent, 'items' => [['question' => 'Q one', 'answer' => 'A one']]]],
            ['component' => 'testimonials', 'props' => ['title' => $title, 'title_accent' => $accent, 'items' => [['quote' => 'Great work', 'author' => 'A. Person']]]],
        ];
    }

    /**
     * The stored shapes that actually FATAL. Every one is a non-scalar AND non-empty, so
     * it is truthy and genuinely opens the gate that reaches the typed call. An empty
     * array is deliberately absent: it is falsy, never reached the call in the six gated
     * components, and would pass identically with the guard removed.
     */
    public static function fatalStoredShapes(): array
    {
        return [
            'localised map'   => [['en' => 'Our services', 'es' => 'Nuestros servicios']],
            'list of strings' => [['Our', 'services']],
            'rich-text node'  => [['text' => 'Our services', 'marks' => ['bold']]],
        ];
    }

    /**
     * THE primary pin. All seven heading components in one stored composition, each
     * carrying a malformed `title` — plus a trailing good band that only renders if
     * nothing above it threw. This is the page that used to 500.
     *
     * @dataProvider fatalStoredShapes
     */
    public function testAStoredNonScalarTitleRendersThePageInsteadOfFataling($bad): void
    {
        $id = pp_create_page('Stored bad title', 'draft');
        // Thin writer, no validation — persists the shape exactly as a pre-rule install
        // holds it, as restore_composition can replay it, and as a raw meta write leaves
        // it. Going through create_page here would be the wrong test: it REJECTS this
        // shape, which is precisely why the render path needs its own guard.
        pp_update_composition($id, array_merge(
            $this->allSevenBands($bad, ''),
            // Renders last, and only if every band above survived.
            [['component' => 'cta', 'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']]]
        ));

        $html = $this->renderStored($id);

        // The page is whole, and every band kept the content that is not its heading.
        $this->assertStringContainsString('Page survived', $html, 'the last band renders, so nothing above threw');
        $this->assertStringContainsString('Card', $html, 'the grid cards still render');
        $this->assertStringContainsString('<p>Section body</p>', $html, 'the section body still renders');
        $this->assertStringContainsString('40+', $html, 'the stats numbers still render');
        $this->assertStringContainsString('Q one', $html, 'the faq questions still render');
        $this->assertStringContainsString('Great work', $html, 'the testimonial quotes still render');
        $this->assertStringContainsString('/go', $html, 'the cta button still renders');

        // And not one heading rendered anywhere on it.
        $this->assertStringNotContainsString('grid__heading', $html, 'the grid heading is skipped entirely');
        $this->assertStringNotContainsString('section__title', $html, 'the section heading is skipped entirely');
        $this->assertStringNotContainsString('stats__heading', $html, 'the stats heading is skipped entirely');
        $this->assertStringNotContainsString('faq__heading', $html, 'the faq heading is skipped entirely');
        $this->assertStringNotContainsString('testimonials__heading', $html, 'the testimonials heading is skipped entirely');
        $this->assertStringNotContainsString('-accent', $html, 'and no accent span is emitted anywhere');

        // The header WRAPPERS go too, because the read is upstream of those gates.
        $this->assertStringNotContainsString('grid__header', $html, 'the grid header wrapper is not emitted for a heading that is not there');
        $this->assertStringNotContainsString('testimonials__header', $html, 'same on testimonials');

        // hero is the exception: its call is ungated, so the element survives, empty.
        $this->assertStringContainsString('<h1 class="hero__title"></h1>', $html, 'hero degrades to an empty h1, its call being ungated');

        // failOnWarning is false and esc_html renders an array as the literal `Array`
        // without fataling, so this is the assertion that separates DEGRADED from COERCED.
        $this->assertStringNotContainsString('Array', $html, 'the value is degraded, never coerced into the page');
    }

    /**
     * ARGUMENT #2 ON ITS OWN. The filed issue reported the accent fatal for hero only; it
     * is all seven, so this is a full sweep and not a spot check. The title here is
     * PERFECTLY GOOD, which is the point — a page can be taken down by a prop that only
     * decorates.
     *
     * @dataProvider fatalStoredShapes
     */
    public function testAStoredNonScalarTitleAccentRendersThePageInsteadOfFataling($bad): void
    {
        $id = pp_create_page('Stored bad title_accent', 'draft');
        pp_update_composition($id, array_merge(
            $this->allSevenBands('Our services', $bad),
            [['component' => 'cta', 'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']]]
        ));

        $html = $this->renderStored($id);

        $this->assertStringContainsString('Page survived', $html, 'the last band renders, so nothing above threw');

        // Every heading renders — PLAIN. The accent degrades to "no accent", which is the
        // helper's own documented behaviour for an accent that does not match the title,
        // so a malformed one lands on a path the component already had.
        $this->assertSame(7, substr_count($html, 'Our services'), 'all seven headings render their title');
        $this->assertStringContainsString('<h1 class="hero__title">Our services</h1>', $html);
        $this->assertStringContainsString('<h2 class="grid__heading">Our services</h2>', $html);
        $this->assertStringContainsString('<h2 class="section__title">Our services</h2>', $html);
        $this->assertStringContainsString('<h2 class="cta__title">Our services</h2>', $html);
        $this->assertStringContainsString('<h2 class="stats__heading">Our services</h2>', $html);
        $this->assertStringContainsString('<h2 class="faq__heading">Our services</h2>', $html);
        $this->assertStringContainsString('<h2 class="testimonials__heading">Our services</h2>', $html);

        $this->assertStringNotContainsString('-accent', $html, 'no accent span is emitted for a malformed accent');
        $this->assertStringNotContainsString('Array', $html, 'the value is degraded, never coerced into the page');
    }

    /**
     * HERO'S DEGRADED RENDER IS A SHAPE THAT ALREADY SHIPS, asserted by rendering both and
     * comparing rather than by claiming it in a comment.
     *
     * hero is the only one of the seven whose heading call is unconditional, so a
     * guarded-away title cannot degrade to "no heading" the way its siblings do — it
     * degrades to an EMPTY `<h1>`. The defensible version of that claim is not "an empty
     * h1 is fine" but "this exact markup is what a stored empty-string title has always
     * produced", which is what this pins. If someone later adds an `if ($title)` gate to
     * hero, this test fails and forces the markup-contract conversation into the open
     * instead of letting it ride along with a guard.
     */
    public function testHeroDegradesToTheSameMarkupAnEmptyStoredTitleAlreadyProduces(): void
    {
        $corrupt = pp_create_page('Hero, corrupt title', 'draft');
        pp_update_composition($corrupt, [
            ['component' => 'hero', 'props' => ['title' => ['en' => 'Welcome'], 'subheading' => 'Sub']],
        ]);

        $empty = pp_create_page('Hero, empty title', 'draft');
        pp_update_composition($empty, [
            ['component' => 'hero', 'props' => ['title' => '', 'subheading' => 'Sub']],
        ]);

        // The generated `id="pp-xxxxxxxx"` anchor is derived per post, so the two renders
        // differ there and nowhere else. Normalising it is the point of the comparison,
        // not a workaround for a weak assertion: what is being claimed is that the guard
        // produces an ALREADY-SHIPPING rendering, and the anchor id is not part of that
        // claim. Everything else — every class, element and text node — must match exactly.
        $normalise = static fn(string $html): string
            => preg_replace('/id="pp-[0-9a-f]+"/', 'id="pp-NORMALISED"', $html);

        $this->assertSame(
            $normalise($this->renderStored($empty)),
            $normalise($this->renderStored($corrupt)),
            'a corrupt hero title renders identically to a stored empty one, anchor id aside'
        );
        $this->assertStringContainsString(
            '<h1 class="hero__title"></h1>',
            $this->renderStored($corrupt),
            'and that shared rendering is the empty h1 — recorded, not endorsed: corrupt'
            . ' stored data still leaves an empty page heading in the accessibility tree'
        );
    }

    /**
     * A CORRUPT TITLE NEVER PAINTS THE COMPONENT'S OWN PLACEHOLDER.
     *
     * hero and faq are the two components whose `??` default is a non-empty string
     * ('Default Title', 'Frequently Asked Questions'). The guard's else-branch is '' and
     * not that default, because `??` fires only on an ABSENT key while a stored non-scalar
     * is PRESENT. Getting this backwards would be worse than the bug it fixes: a visitor
     * would read invented words that no one authored, and an operator would have no signal
     * that anything was wrong. The absent-key case is asserted alongside it so the default
     * is not accidentally deleted while making the corrupt case pass.
     */
    public function testACorruptTitleDegradesToNoHeadingRatherThanTheComponentDefault(): void
    {
        $corrupt = pp_create_page('Defaults, corrupt', 'draft');
        pp_update_composition($corrupt, [
            ['component' => 'hero', 'props' => ['title' => ['en' => 'Welcome'], 'subheading' => 'Hero sub']],
            ['component' => 'faq',  'props' => ['title' => ['en' => 'Questions'], 'items' => [['question' => 'Q one', 'answer' => 'A one']]]],
        ]);
        $html = $this->renderStored($corrupt);

        $this->assertStringNotContainsString('Default Title', $html, 'hero must not paint its placeholder over a corrupt stored title');
        $this->assertStringNotContainsString('Frequently Asked Questions', $html, 'nor faq over its own');
        $this->assertStringNotContainsString('faq__heading', $html, 'faq is gated, so it renders no heading at all');
        $this->assertStringContainsString('Q one', $html, 'and the questions still render');
        $this->assertStringContainsString('Hero sub', $html, 'and the hero subheading still renders');

        // The defaults themselves are untouched — they belong to the ABSENT key, and the
        // guard has no opinion about that path.
        //
        // This half emits one E_USER_WARNING from lib/components.php:48 ("component 'hero'
        // missing required prop 'title'"), and that is the registry doing its job on a
        // deliberately title-less band, not a defect in the guard. It is left visible
        // rather than suppressed: the absent-key path is exactly what the `??` defaults
        // serve, and a reader who sees the warning should be able to find this note
        // instead of assuming the new test is leaking noise.
        $absent = pp_create_page('Defaults, absent', 'draft');
        pp_update_composition($absent, [
            ['component' => 'hero', 'props' => ['subheading' => 'Hero sub']],
            ['component' => 'faq',  'props' => ['items' => [['question' => 'Q one', 'answer' => 'A one']]]],
        ]);
        $htmlAbsent = $this->renderStored($absent);

        $this->assertStringContainsString('<h1 class="hero__title">Default Title</h1>', $htmlAbsent, 'an absent title still gets the default');
        $this->assertStringContainsString('Frequently Asked Questions', $htmlAbsent, 'same for faq');
    }

    /**
     * THE REGRESSION PIN for the predicate, on real stored bytes.
     *
     * A stored non-string SCALAR title is not hypothetical, and #707 did not make it so.
     * In coercive mode it has always rendered, and is_string() would blank it and drop a
     * heading that renders correctly. This fails the moment the predicate narrows.
     *
     * ANSWERING THE NOTE THIS DOCBLOCK USED TO LEAVE FOR #707. It said the pin asserted
     * COMPATIBILITY — that the guard did not change how an already-accepted scalar
     * renders — not that a heading reading "42" is CORRECT, and that updating or deleting
     * it once the write path tightened would be expected rather than a regression. #707
     * has now landed and the pin is KEPT, because what it really holds is the property
     * the note said had to survive: the render path still handles a stored scalar rather
     * than dropping it. What changed is only the reachability sentence. `create_page` no
     * longer accepts `title: 42` — the fixture below seeds through
     * `pp_update_composition()`, the NON-validating writer, which is precisely the
     * channel that still produces this state: a pre-#707 composition, a restore (#233,
     * reports and never blocks), a raw meta write.
     */
    public function testAStoredScalarTitleStillRenders(): void
    {
        $id = pp_create_page('Stored scalar title', 'draft');
        pp_update_composition($id, [
            ['component' => 'stats', 'props' => ['title' => 42, 'items' => [['number' => '1', 'label' => 'L']]]],
            ['component' => 'faq',   'props' => ['title' => 3.14, 'items' => [['question' => 'Q', 'answer' => 'A']]]],
            ['component' => 'hero',  'props' => ['title' => true]],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('<h2 class="stats__heading">42</h2>', $html, 'an int title still renders');
        $this->assertStringContainsString('<h2 class="faq__heading">3.14</h2>', $html, 'a float title still renders');
        $this->assertStringContainsString('<h1 class="hero__title">1</h1>', $html, 'a bool title still renders as it always coerced');
    }

    /**
     * THE -0.0 EXCEPTION, pinned on real stored bytes through BOTH storage channels.
     *
     * `-0.0` is the one scalar where the `(string)` cast flips a truthiness gate
     * (`(string) -0.0` is `'-0'`, and only `''` and `'0'` are falsy strings), so it is the
     * one value this guard newly RENDERS where it used to skip the heading. The renderer
     * sweep across the other scalars lives in ComponentPropsTest; what belongs here is
     * whether stored bytes can actually deliver it, and the answer is channel-dependent in
     * a way that is easy to get backwards — measured here rather than argued:
     *
     *   json_encode(-0.0)             -> text `-0`   -> json_decode -> INT 0   -> no flip
     *   stored text `-0.0` (literal)  ->                json_decode -> FLOAT -0 -> FLIP
     *
     * PHP's json_encode never emits the decimal-point form, so every writer that
     * re-encodes round-trips it to int 0. Only bytes that already hold the literal text
     * reach the flip. Both halves are asserted so neither claim can rot.
     *
     * Left as-is deliberately, matching the landed #705 decision: '-0' is inert once
     * escaped, and special-casing it would mean inspecting and rewriting the stored value,
     * which is exactly what D-B forbids. Note hero is absent from both halves on purpose —
     * it has no gate, so it renders '-0' either way and cannot demonstrate a flip.
     */
    public function testNegativeZeroFlipsTheGateOnlyThroughARawMetaWrite(): void
    {
        // Channel 1 — the normal write path. json_encode flattens -0.0 to `-0`, which
        // decodes as int 0, so the band renders exactly as it always did.
        $encoded = pp_create_page('Negative zero, encoded', 'draft');
        pp_update_composition($encoded, [
            ['component' => 'stats', 'props' => ['title' => -0.0, 'items' => [['number' => '1', 'label' => 'Encoded band']]]],
        ]);

        $this->assertSame(0, pp_get_composition($encoded)[0]['props']['title'], 'json_encode round-trips -0.0 to int 0');
        $html = $this->renderStored($encoded);
        $this->assertStringContainsString('Encoded band', $html, 'the band renders');
        $this->assertStringNotContainsString('stats__heading', $html, 'and renders no heading, exactly as before the guard');

        // Channel 2 — stored bytes that already carry the literal `-0.0` text, which is
        // the raw-meta reachability this whole file exists for. Here the flip is real.
        $raw = pp_create_page('Negative zero, raw', 'draft');
        update_post_meta($raw, '_pp_composition', '[{"component":"stats","props":{"title":-0.0,"items":[{"number":"1","label":"Raw band"}]}}]');

        $stored = pp_get_composition($raw)[0]['props']['title'];
        $this->assertIsFloat($stored, 'the literal text decodes as a float, not an int');
        $this->assertSame(-0.0, $stored);

        $html = $this->renderStored($raw);
        $this->assertStringContainsString('Raw band', $html, 'the band renders');
        $this->assertStringContainsString('<h2 class="stats__heading">-0</h2>', $html, 'and here the cast DOES open the gate');
    }

    /**
     * ZERO RENDERING CHANGE FOR WELL-FORMED DATA, on the stored path, including the accent
     * mechanism the helper exists for. Without this, every assertion in this file could be
     * satisfied by a guard that blanked everything.
     */
    public function testAWellFormedStoredHeadingRendersItsAccentUnchanged(): void
    {
        $id = pp_create_page('Well-formed heading', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero',  'props' => ['title' => 'Seguridad y salud', 'title_accent' => 'Seguridad']],
            ['component' => 'stats', 'props' => ['title' => 'Seguridad y salud', 'title_accent' => 'Seguridad', 'items' => [['number' => '1', 'label' => 'L']]]],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString(
            '<h1 class="hero__title"><span class="hero__title-accent">Seguridad</span> y salud</h1>',
            $html,
            'the accent split is untouched by the guard'
        );
        $this->assertStringContainsString(
            '<h2 class="stats__heading"><span class="stats__heading-accent">Seguridad</span> y salud</h2>',
            $html
        );
    }

    /**
     * THE ESCAPER IS STILL IN THE PATH, pinned on the stored-bytes path.
     *
     * Every other accept-side assertion here uses benign copy, on which esc_html() is a
     * no-op — so they would all stay green if a future refactor of these guard blocks
     * emitted the guarded string raw. A heading lands in element text, so a surviving `<`
     * is a stored-XSS vector. The values are chosen so the assertion fails the moment the
     * helper stops escaping. Both fragments matter: the helper escapes the title AND the
     * accent substring separately, so the accent is attacked too.
     */
    public function testTheHeadingEscaperStillRunsOnStoredBytes(): void
    {
        $id = pp_create_page('Stored heading XSS', 'draft');
        pp_update_composition($id, [
            ['component' => 'stats', 'props' => [
                'title'        => '<script>alert(1)</script> and more',
                'title_accent' => '<script>alert(1)</script>',
                'items'        => [['number' => '1', 'label' => 'L']],
            ]],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('<script>', $html, 'no raw script tag reaches the page');
        $this->assertStringContainsString('&lt;script&gt;', $html, 'it is escaped, as a plain title always was');
        $this->assertStringContainsString('stats__heading-accent', $html, 'and the accent still splits on the escaped fragment');
    }

    /**
     * EVERY SECTION LAYOUT BRANCH, because one guarded read feeding three call sites is a
     * fact about today's code, not a property the tests were checking.
     *
     * section reaches the helper from three independently editable places: `text-only` and
     * `centered` share one, `text-panel` has its own, and `image-left`/`image-right` share
     * a third. Every other fixture in this file pins section to `text-only`, so two of the
     * three were never rendered with a malformed title at all — a reassignment of the raw
     * value inside either of the other branches reintroduced the exact #706 TypeError with
     * the whole suite still green. This renders all five layout values, so each branch has
     * to survive on its own.
     */
    public function testEverySectionLayoutBranchDegradesItsHeading(): void
    {
        $bad = ['en' => 'Our services'];
        $id  = pp_create_page('Section layouts', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['title' => $bad, 'body' => '<p>text-only body</p>',  'layout' => 'text-only']],
            ['component' => 'section', 'props' => ['title' => $bad, 'body' => '<p>centered body</p>',   'layout' => 'centered']],
            ['component' => 'section', 'props' => ['title' => $bad, 'body' => '<p>panel body</p>',      'layout' => 'text-panel', 'panel_heading' => 'Panel']],
            ['component' => 'section', 'props' => ['title' => $bad, 'body' => '<p>left body</p>',       'layout' => 'image-left',  'image_url' => 'https://example.com/a.jpg']],
            ['component' => 'section', 'props' => ['title' => $bad, 'body' => '<p>right body</p>',      'layout' => 'image-right', 'image_url' => 'https://example.com/b.jpg']],
            ['component' => 'cta',     'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('Page survived', $html, 'the last band renders, so no branch threw');
        foreach (['text-only', 'centered', 'panel', 'left', 'right'] as $body) {
            $this->assertStringContainsString("<p>{$body} body</p>", $html, "the {$body} band keeps its body");
        }
        $this->assertStringContainsString('Panel', $html, 'the text-panel band keeps its panel heading');
        $this->assertStringNotContainsString('section__title', $html, 'no branch emits a heading');
        $this->assertStringNotContainsString('section__header', $html, 'nor a header wrapper');
        $this->assertStringNotContainsString('Array', $html, 'degraded, never coerced into the page');
    }

    /**
     * DEGRADE, NEVER REWRITE — the D-B clause this file's header claims, now asserted
     * instead of only stated.
     *
     * Both siblings carry this pin (StoredBackgroundImageRenderGuardTest and
     * StoredImageUrlRenderGuardTest); dropping it here left a real hole. A "helpful"
     * migrate-on-read implementation — one that blanks the non-scalar and writes the
     * sanitised composition back — satisfies every other assertion in this file, because
     * they all look at emitted HTML and that implementation emits exactly the same HTML.
     * The difference is only visible from the store, so that is where it is checked. It
     * matters beyond principle: an operator's diagnostic, a restore, and any later repair
     * all need the original bytes to still be there.
     */
    public function testTheGuardDoesNotRewriteTheStoredValue(): void
    {
        $bad = ['en' => 'Our services', 'es' => 'Nuestros servicios'];
        $id  = pp_create_page('Stored title preserved', 'draft');
        pp_update_composition($id, $this->allSevenBands($bad, $bad));

        $this->renderStored($id);

        foreach (pp_get_composition($id) as $i => $item) {
            $this->assertSame($bad, $item['props']['title'], "band {$i} ({$item['component']}): the stored title is untouched");
            $this->assertSame($bad, $item['props']['title_accent'], "band {$i} ({$item['component']}): the stored accent is untouched");
        }
    }

    /**
     * THE ESCAPER RUNS AT EVERY CALL SITE, INCLUDING THE NO-ACCENT BRANCH.
     *
     * The accent-present case above pins one component through one branch. That leaves the
     * more common path — a heading with no `title_accent` at all — unpinned everywhere: a
     * refactor emitting the guarded `$title` raw whenever no accent is set passes every
     * other assertion in this file and in the suite. A heading lands in element text, so
     * that is a live stored-XSS route, and it is precisely the failure the accent-present
     * test's docblock claims to prevent. Counting the escaped occurrences rather than only
     * asserting the absence of a raw tag is what makes it fail on a single missed site.
     */
    public function testTheHeadingEscaperRunsAtEveryCallSiteWithNoAccentSet(): void
    {
        $xss = '<script>alert(1)</script>';
        $id  = pp_create_page('Heading XSS sweep', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero',         'props' => ['title' => $xss]],
            ['component' => 'grid',         'props' => ['title' => $xss, 'items' => [['title' => 'C', 'text' => 'B']]]],
            ['component' => 'section',      'props' => ['title' => $xss, 'body' => '<p>b</p>', 'layout' => 'text-only']],
            ['component' => 'section',      'props' => ['title' => $xss, 'body' => '<p>b</p>', 'layout' => 'text-panel', 'panel_heading' => 'P']],
            ['component' => 'section',      'props' => ['title' => $xss, 'body' => '<p>b</p>', 'layout' => 'image-left', 'image_url' => 'https://example.com/a.jpg']],
            ['component' => 'cta',          'props' => ['title' => $xss, 'button_text' => 'Go', 'button_url' => '/go']],
            ['component' => 'stats',        'props' => ['title' => $xss, 'items' => [['number' => '1', 'label' => 'L']]]],
            ['component' => 'faq',          'props' => ['title' => $xss, 'items' => [['question' => 'Q', 'answer' => 'A']]]],
            ['component' => 'testimonials', 'props' => ['title' => $xss, 'items' => [['quote' => 'Q']]]],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'no call site emits the title raw');
        $this->assertSame(
            9,
            substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'),
            'all nine heading renders escape, with no accent set anywhere'
        );
    }

    /**
     * THE WRITE PATH STILL REFUSES THE SHAPE — the authoring-path half of the argument
     * (pipeline 14.1), so this issue cannot be "fixed" by quietly relaxing the front door
     * and letting the render guard absorb it. The render guard is a last resort for bytes
     * that are ALREADY stored, never a licence to accept more.
     */
    public function testTheWritePathStillRejectsANonScalarTitle(): void
    {
        foreach (['title', 'title_accent'] as $prop) {
            $result = pp_execute_action('create_page', [
                'title'       => 'Authoring path, ' . $prop,
                'composition' => [
                    ['component' => 'stats', 'props' => [
                        'title' => 'Fine',
                        $prop   => ['en' => 'Nope'],
                        'items' => [['number' => '1', 'label' => 'L']],
                    ]],
                ],
            ]);

            $this->assertFalse($result['ok'], "{$prop}: a non-scalar must not be accepted at write");
            $this->assertStringContainsString($prop, $result['error'], "{$prop}: the error names the prop");
            $this->assertStringContainsString('must be a string', $result['error'], "{$prop}: with the type rule");
        }
    }

    /**
     * The stored value is REPORTED, not silently absorbed. The render guard is a
     * last-resort degradation, so the operator-facing diagnostic has to keep naming the
     * bad value — otherwise "no heading" is indistinguishable from "no heading was set".
     * Verified against the SHARED engine, which is what the check page and the validate
     * actions read; this change adds no second, surface-specific validator.
     *
     * SCOPE OF THE CLAIM, stated honestly: this holds for the NON-SCALAR shapes, which are
     * the ones the guard newly degrades. The findings engine reports nothing for a
     * non-string scalar, but the guard does not change how those render either, so it
     * introduces no new silence. Closing that gap is #707, not this issue.
     */
    public function testTheStoredTitleIsStillReportedAsAFinding(): void
    {
        foreach (['title', 'title_accent'] as $prop) {
            $findings = _pp_composition_findings([
                ['component' => 'stats', 'props' => [
                    'title'  => 'Fine',
                    $prop    => ['en' => 'Nope'],
                    'items'  => [['number' => '1', 'label' => 'L']],
                ]],
            ]);

            $this->assertNotEmpty($findings, "{$prop}: the malformed stored value is still surfaced");
            $this->assertContains('invalid_prop_value', array_column($findings, 'type'), $prop);
            $encoded = json_encode($findings);
            $this->assertStringContainsString($prop, $encoded, "{$prop}: the finding names the prop");
        }
    }
}
