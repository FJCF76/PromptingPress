<?php
/**
 * tests/StoredImageUrlRenderGuardTest.php
 *
 * #641 — a stored non-scalar `image_url` / `image_alt` must never fatal the public page.
 *
 * WHY THIS FILE EXISTS SEPARATELY. The per-component shapes are pinned in
 * ComponentPropsTest (alongside the image_id guards they mirror), and those render a
 * component directly from a props array. That is a renderer-level control: it proves the
 * guard works, but it does not prove the bad value can REACH the renderer. This class
 * closes that gap by writing real stored bytes and rendering them through the loop
 * templates/composition.php actually runs, so what is asserted is what a visitor's
 * browser receives.
 *
 * THE DEFECT. Seven call sites pass a raw stored value into a TYPED parameter — six into
 * pp_render_responsive_image() and one into pp_esc_image_src():
 *
 *   lib/wp.php  pp_render_responsive_image(string $url, string $alt, ...)
 *     logos.php, grid.php, testimonials.php, hero.php (split), section.php (x2)
 *     BOTH raw-value arguments fatal: $url on image_url, $alt on image_alt.
 *   lib/wp.php  pp_esc_image_src(string $url, int $depth = 0)
 *     hero.php (cover background-image) — the SAME image_url prop, a different helper.
 *
 * Each is gated on truthiness, and a non-empty array is TRUTHY, so the gate passes and
 * the typed call raises an uncatchable TypeError. templates/composition.php calls
 * pp_get_component() with no try/catch, so one malformed stored value returns a
 * whole-page 500 rather than a band with a missing image.
 *
 * THE PREDICATE IS is_scalar, NOT is_string, AND THAT IS LOAD-BEARING. PHP runs coercive
 * here (no declare(strict_types)), so only NON-SCALARS ever fataled — a stored `42`
 * coerced at the boundary and painted. The write path is scalar-permissive to match:
 * create_page accepts `image_url: 42`, stores it RAW, and the findings engine reports
 * nothing (#707). Because pp_render_responsive_image() resolves $attachment_id before it
 * falls back to $url, an is_string() guard would have blanked the URL, closed the
 * truthiness gate, and silently dropped a real resolvable image_id attachment on four of
 * the five components. So:
 *
 *   NON-SCALAR -> ""            CHANGED: the fatal, now a degraded render.
 *   SCALAR     -> (string) cast UNCHANGED: byte-identical to before the guard.
 *
 * SCOPE (ratified at gate 7A). This closes the NAMED typed call — both raw-value
 * arguments of pp_render_responsive_image(), and the pp_esc_image_src() branch that the
 * same guarded local feeds. The identical defect through OTHER typed helpers is filed
 * and deliberately NOT fixed here: #705 (background_image -> pp_esc_image_src on
 * cta/stats/section), #706 (title/title_accent -> pp_render_heading_with_accent), #708
 * (count() on a scalar items, pp_render_style_vars on a non-array style). The admitting
 * criterion was same-typed-call, not same-file and not same-family.
 *
 * WHY STORED DATA IS THE POINT. The write path rejects non-scalars at both depths
 * (asserted below, so a future change cannot relax it and call this issue fixed). But
 * the validator gates WRITES, not storage:
 *
 *   - a composition authored before the type rules landed still carries the value,
 *   - restore_composition restores and REPORTS, and never blocks (#233),
 *   - a raw `_pp_composition` meta write is not gated at all.
 *
 * A stricter write path does not repair a page that ALREADY stores the bad value. That
 * page is what 500s, and that is what the render guard covers.
 *
 * DEGRADE, NEVER REWRITE. Nothing here touches stored data (v1.13 posture). The value
 * stays exactly as stored, and the page renders without the broken image — the same
 * rendering an empty image_url has always produced.
 *
 *   stored props ──> pp_get_composition() ──> pp_get_component()
 *                    (plain decode, no          │
 *                     sanitising)               ├─ is_scalar($raw) ? (string) $raw : ''
 *                                               │
 *                                               ├─ truthiness gate ──> skipped
 *                                               └─ typed helper      ──> never reached
 */

use PHPUnit\Framework\TestCase;

class StoredImageUrlRenderGuardTest extends TestCase
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
     * Renders a stored composition exactly as templates/composition.php does: read the
     * stored items, promote `style` to the `__pp_style` prop, render each component in
     * order. Deliberately carries NO try/catch, because the absence of one is the whole
     * defect — a TypeError here is the 500 a visitor gets. The buffer is closed in a
     * `finally` so a regression reports as a clean failure instead of a risky test with
     * a leaked output buffer.
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
     * The stored shapes that actually FATAL. Every one is a non-scalar AND non-empty, so
     * it is truthy and genuinely opens the gate that reaches the typed call. An empty
     * array is deliberately absent: it is falsy, never reached the call, and would pass
     * identically with the guard removed.
     */
    public static function fatalStoredShapes(): array
    {
        return [
            'import_media envelope' => [['attachment_id' => 42, 'url' => '/a.png', 'action' => 'imported']],
            'list of urls'          => [['/a.png', '/b.png']],
            'nested map'            => [['src' => ['url' => '/a.png']]],
        ];
    }

    /**
     * THE primary pin. Every component that renders an author image, in one stored
     * composition, each carrying a malformed image_url — plus a trailing good band that
     * only renders if nothing above it threw. This is the page that used to 500.
     *
     * @dataProvider fatalStoredShapes
     */
    public function testAStoredNonScalarImageUrlRendersThePageInsteadOfFataling($bad): void
    {
        $id = pp_create_page('Stored bad image_url', 'draft');
        // Thin writer, no validation — persists the shape exactly as a pre-rule install
        // holds it, as restore_composition can replay it, and as a raw meta write leaves
        // it. Going through create_page here would be the wrong test: it REJECTS this
        // shape, which is precisely why the render path needs its own guard.
        pp_update_composition($id, [
            ['component' => 'hero',    'props' => ['title' => 'Split hero', 'layout' => 'split', 'image_url' => $bad]],
            ['component' => 'hero',    'props' => ['title' => 'Cover hero', 'layout' => 'cover', 'image_url' => $bad]],
            ['component' => 'section', 'props' => ['body' => '<p>Left body</p>',  'layout' => 'image-left',  'image_url' => $bad]],
            ['component' => 'section', 'props' => ['body' => '<p>Right body</p>', 'layout' => 'image-right', 'image_url' => $bad]],
            ['component' => 'logos',   'props' => ['title' => 'Logos band', 'items' => [['image_url' => $bad, 'image_alt' => 'L']]]],
            ['component' => 'grid',    'props' => ['items' => [['title' => 'Grid card', 'image_url' => $bad]]]],
            ['component' => 'testimonials', 'props' => ['items' => [['quote' => 'Quoted well.', 'image_url' => $bad]]]],
            // Renders last, and only if every band above survived.
            ['component' => 'cta',     'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']],
        ]);

        $html = $this->renderStored($id);

        // The page is whole.
        $this->assertStringContainsString('Page survived', $html, 'the last band renders, so nothing above threw');
        $this->assertStringContainsString('Split hero', $html);
        $this->assertStringContainsString('Cover hero', $html);
        $this->assertStringContainsString('<p>Left body</p>', $html);
        $this->assertStringContainsString('<p>Right body</p>', $html);
        $this->assertStringContainsString('Logos band', $html);
        $this->assertStringContainsString('Grid card', $html);
        $this->assertStringContainsString('Quoted well.', $html);

        // And not one broken image was emitted anywhere on it.
        $this->assertStringNotContainsString('<img ', $html, 'a malformed image_url renders NO image');
        $this->assertStringNotContainsString('background-image', $html, 'including the cover background');
        $this->assertStringNotContainsString('section__image-wrap', $html);
        $this->assertStringNotContainsString('grid__item-image-wrap', $html);
        $this->assertStringNotContainsString('testimonials__avatar', $html);
    }

    /**
     * The same page, malformed on argument #2 instead of #1. image_alt fataled through
     * the identical call, so leaving it unguarded would have kept the 500 reachable one
     * argument over in the same statement.
     *
     * @dataProvider fatalStoredShapes
     */
    public function testAStoredNonScalarImageAltRendersThePageInsteadOfFataling($bad): void
    {
        $id = pp_create_page('Stored bad image_alt', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero',    'props' => ['title' => 'Split hero', 'layout' => 'split', 'image_url' => 'https://example.com/h.jpg', 'image_alt' => $bad]],
            ['component' => 'section', 'props' => ['body' => '<p>Body</p>', 'layout' => 'image-left', 'image_url' => 'https://example.com/s.jpg', 'image_alt' => $bad]],
            ['component' => 'logos',   'props' => ['items' => [['image_url' => 'https://example.com/l.png', 'image_alt' => $bad]]]],
            ['component' => 'grid',    'props' => ['items' => [['title' => 'Card', 'image_url' => 'https://example.com/c.jpg', 'image_alt' => $bad]]]],
            ['component' => 'testimonials', 'props' => ['items' => [['quote' => 'Q', 'image_url' => 'https://example.com/f.jpg', 'image_alt' => $bad]]]],
            ['component' => 'cta',     'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('Page survived', $html, 'the last band renders, so nothing above threw');
        // A broken ALT is not a reason to drop the image — every one still paints.
        $this->assertSame(5, substr_count($html, '<img '), 'all five images still render');
        $this->assertSame(5, substr_count($html, 'alt=""'), 'each with an empty alt');
    }

    /**
     * THE REGRESSION PIN for the predicate, on real stored bytes.
     *
     * A stored non-string SCALAR image_url is not hypothetical: create_page accepts it
     * and stores it raw (#707). Because the helper resolves $attachment_id before
     * falling back to $url, such a page renders its attachment correctly. is_string()
     * would have blanked the URL, closed the truthiness gate, and silently dropped that
     * image on logos, grid, testimonials and section. This fails the moment the
     * predicate narrows.
     */
    public function testAStoredScalarImageUrlStillRendersItsImageIdAttachment(): void
    {
        $GLOBALS['_pp_test_store']['attachment_urls'][77] = 'https://example.com/uploads/REAL.jpg';

        $id = pp_create_page('Stored scalar image_url', 'draft');
        pp_update_composition($id, [
            ['component' => 'logos',        'props' => ['items' => [['image_url' => 42, 'image_alt' => 'L', 'image_id' => 77]]]],
            ['component' => 'grid',         'props' => ['items' => [['title' => 'Card', 'image_url' => 42, 'image_id' => 77]]]],
            ['component' => 'testimonials', 'props' => ['items' => [['quote' => 'Q', 'image_url' => 42, 'image_id' => 77]]]],
            ['component' => 'section',      'props' => ['body' => '<p>b</p>', 'layout' => 'image-left', 'image_url' => 42, 'image_id' => 77]],
            ['component' => 'hero',         'props' => ['title' => 'T', 'layout' => 'split', 'image_url' => 42, 'image_id' => 77]],
        ]);

        $html = $this->renderStored($id);

        // One <img> per band, every one of them the resolved attachment. (REAL.jpg
        // itself appears more than five times: wp_get_attachment_image() repeats the URL
        // across its srcset candidates, so the element count is the honest measure.)
        $this->assertSame(5, substr_count($html, '<img '), 'all five components render an image');
        $this->assertSame(5, substr_count($html, 'srcset='), 'all five resolve the attachment responsively');
        $this->assertStringContainsString('REAL.jpg', $html);
    }

    /**
     * The stored value is REPORTED, not silently absorbed. The render guard is a
     * last-resort degradation, so the operator-facing diagnostic has to keep naming the
     * bad value — otherwise "no image" is indistinguishable from "no image was set".
     * Verified against the SHARED engine, which is what the check page and the validate
     * actions read; this change adds no second, surface-specific validator.
     *
     * SCOPE OF THE CLAIM, stated honestly: this holds for the NON-SCALAR shapes, which
     * are the ones the guard newly degrades. The findings engine reports nothing for a
     * non-string scalar, but the guard does not change how those render either, so it
     * introduces no new silence. Closing that gap is #707, not this issue.
     */
    public function testTheStoredValueIsStillReportedAsAFinding(): void
    {
        foreach ([
            'image_url' => ['image_url' => ['attachment_id' => 42], 'image_alt' => 'L'],
            'image_alt' => ['image_url' => '/a.png', 'image_alt' => ['x' => 1]],
        ] as $prop => $item) {
            $findings = _pp_composition_findings([
                ['component' => 'logos', 'props' => ['items' => [$item]]],
            ]);

            $this->assertNotEmpty($findings, "{$prop}: the malformed stored value is still surfaced");
            $this->assertContains('invalid_prop_value', array_column($findings, 'type'), $prop);
            $encoded = json_encode($findings);
            $this->assertStringContainsString($prop, $encoded, "{$prop}: the finding names the prop");
            $this->assertStringContainsString('must be a string', $encoded, "{$prop}: with the type rule");
        }
    }

    /**
     * The stored bytes are not touched. Degrade, never rewrite: reading the composition
     * back reports exactly what was written, so the render-time degradation cannot be
     * mistaken for a migration and a later fix-up still sees the original value.
     */
    public function testTheGuardDoesNotRewriteTheStoredValue(): void
    {
        $bad = ['attachment_id' => 42, 'url' => '/a.png'];
        $id  = pp_create_page('Stored value preserved', 'draft');
        pp_update_composition($id, [
            ['component' => 'section', 'props' => ['body' => '<p>b</p>', 'layout' => 'image-left', 'image_url' => $bad, 'image_alt' => $bad]],
        ]);

        $this->renderStored($id);

        $stored = pp_get_composition($id)[0]['props'];
        $this->assertSame($bad, $stored['image_url']);
        $this->assertSame($bad, $stored['image_alt']);
    }

    /**
     * The write path stays STRICT for the shapes it already rejected (rule 14.1:
     * exercise the real authoring surface, not a raw meta write). The render guard is
     * defense for data that is already stored; it must not become a reason to accept the
     * shape at the front door. Both depths, because that is where #614 put the nested
     * rule, and both arguments of the guarded call.
     *
     * NOT asserted here, deliberately: that the write path rejects non-string SCALARS.
     * It does not — create_page accepts `image_url: 42` and stores it raw. That gap is
     * #707. Pinning it as "strict" here would be false, and pinning the current
     * permissiveness as correct would prejudge #707's fix.
     */
    public function testTheAuthoringPathStillRejectsANonScalarImageValue(): void
    {
        $bad = ['attachment_id' => 42];
        $cases = [
            'top-level hero image_url'    => ['hero',    ['title' => 'T', 'layout' => 'split', 'image_url' => $bad], 'image_url'],
            'top-level section image_url' => ['section', ['body' => '<p>b</p>', 'layout' => 'image-left', 'image_url' => $bad], 'image_url'],
            'top-level hero image_alt'    => ['hero',    ['title' => 'T', 'layout' => 'split', 'image_url' => '/a.png', 'image_alt' => $bad], 'image_alt'],
            'nested logos image_url'      => ['logos',   ['items' => [['image_url' => $bad, 'image_alt' => 'L']]], 'image_url'],
            'nested logos image_alt'      => ['logos',   ['items' => [['image_url' => '/a.png', 'image_alt' => $bad]]], 'image_alt'],
            'nested grid image_url'       => ['grid',    ['items' => [['title' => 'C', 'image_url' => $bad]]], 'image_url'],
            'nested testimonial image_url'=> ['testimonials', ['items' => [['quote' => 'Q', 'image_url' => $bad]]], 'image_url'],
        ];

        foreach ($cases as $label => [$component, $props, $prop]) {
            $result = pp_execute_action('create_page', [
                'title'       => 'Rejected ' . $label,
                'composition' => [['component' => $component, 'props' => $props]],
            ]);
            $this->assertFalse($result['ok'], "{$label}: a non-scalar must not be accepted at write");
            $this->assertStringContainsString($prop, $result['error'], "{$label}: the error names the prop");
            $this->assertStringContainsString('must be a string', $result['error'], "{$label}: with the type rule");
        }
    }

    /**
     * The accept side, on real stored bytes: an ordinary composition renders exactly as
     * before. A guard that quietly dropped legitimate images would pass every negative
     * test in this file.
     */
    public function testAnOrdinaryStoredCompositionIsUnchanged(): void
    {
        $id = pp_create_page('Good images', 'draft');
        pp_update_composition($id, [
            ['component' => 'hero',    'props' => ['title' => 'H', 'layout' => 'split', 'image_url' => 'https://example.com/hero.jpg', 'image_alt' => 'Hero']],
            ['component' => 'section', 'props' => ['body' => '<p>b</p>', 'layout' => 'image-left', 'image_url' => 'https://example.com/side.jpg', 'image_alt' => 'Side']],
            ['component' => 'logos',   'props' => ['items' => [['image_url' => 'https://example.com/logo.png', 'image_alt' => 'Logo']]]],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString(
            '<img src="https://example.com/hero.jpg" alt="Hero" class="hero__image" loading="eager">',
            $html
        );
        $this->assertStringContainsString(
            '<img src="https://example.com/side.jpg" alt="Side" class="section__image" loading="lazy">',
            $html
        );
        $this->assertStringContainsString(
            '<img src="https://example.com/logo.png" alt="Logo" class="logos__image" loading="lazy">',
            $html
        );
        $this->assertStringContainsString('hero--split', $html, 'the split layout is kept');
    }
}
