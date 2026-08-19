<?php
/**
 * tests/StoredBackgroundImageRenderGuardTest.php
 *
 * #705 — a stored non-scalar `background_image` must never fatal the public page.
 *
 * WHY THIS FILE EXISTS SEPARATELY. The per-component shapes are pinned in
 * ComponentPropsTest, and those render a component directly from a props array. That is
 * a renderer-level control: it proves the guard works, but it does not prove the bad
 * value can REACH the renderer. This class closes that gap by writing real stored bytes
 * and rendering them through the loop templates/composition.php actually runs, so what
 * is asserted is what a visitor's browser receives. It is the sibling of
 * tests/StoredImageUrlRenderGuardTest.php, which does the same job for #641.
 *
 * THE DEFECT. Three call sites pass a raw stored value into a TYPED parameter:
 *
 *   lib/wp.php  pp_esc_image_src(string $url, int $depth = 0)
 *     cta.php, stats.php, section.php — the `background_image` prop on each.
 *
 * Each is gated on truthiness, and a non-empty array is TRUTHY, so the gate passes and
 * the typed call raises a TypeError that no caller catches. templates/composition.php
 * calls pp_get_component() with no try/catch, so one malformed stored value returns a
 * whole-page 500 rather than a band with a missing background. (Catchable in principle;
 * deliberately not caught in practice, and adding a catch is NOT the fix — see the
 * wp_kses_post note under SCOPE below for why swallowing an escaping throw is worse
 * than the throw.)
 *
 * THE GUARD SITS AT THE READ, AND THAT PLACEMENT IS THE BEHAVIOUR. This prop drives
 * THREE gates per component — the `--has-bg-image` modifier, the inline
 * `background-image` declaration, and the overlay `<div>` — and the read is upstream of
 * all three. A call-site-only guard would leave the modifier and the overlay ON with
 * nothing painting underneath: a dark scrim over the band's own background, wearing the
 * light on-overlay ink the modifier selects. That is a visual state nobody designed.
 * Guarding at the read reuses one that shipped long ago — the band renders exactly as
 * it does with an empty `background_image`.
 *
 * THE PREDICATE IS is_scalar, NOT is_string, AND THAT IS LOAD-BEARING. PHP runs coercive
 * here (no declare(strict_types)), so only NON-SCALARS ever fataled — a stored `42`
 * coerced at the boundary and painted `url(42)`. The write path is scalar-permissive to
 * match: create_page accepts `background_image: 42`, stores it RAW, and the findings
 * engine reports nothing (#707). So:
 *
 *   NON-SCALAR -> ""            CHANGED: the fatal, now a degraded render.
 *   SCALAR     -> (string) cast UNCHANGED: as it painted before the guard.
 *
 * Stated honestly, ONE half of the #641 rationale does NOT carry over to this prop:
 * `background_image` has no `image_id` companion (it is CSS `background-image`, not an
 * `<img>`), so there is no resolvable attachment for an is_string() guard to discard
 * here. The write-accepted-scalar half carries on its own and is sufficient. The scalar
 * URL semantics this preserves (`true` painting `url(1)`) are COMPATIBILITY, not a claim
 * that they are correct — tightening what the write path ACCEPTS is #707, and this guard
 * deliberately does not prejudge it by rejecting at render what the front door admits.
 *
 * SCOPE. This closes the NAMED typed call for the NAMED prop: `background_image` into
 * pp_esc_image_src() on cta, stats and section. That read-site set is complete for the
 * PUBLIC RENDER PATH — verified by grep for the literal prop name across components/,
 * templates/ and lib/, and by grep for dynamic `$props[$var]` access, which no component
 * template uses (lib/ does, see below).
 *
 * ONE READ SITE SURVIVES THAT GREP AND IS DELIBERATELY LEFT ALONE, named here so the
 * completeness claim above is not read as broader than it is: `lib/ai-context.php:643`
 * loops `foreach (['image_url','background_image'] as $img_prop)` and passes the raw
 * value to `basename()`, which is also typed, behind an `!empty()` gate a non-empty array
 * passes. Same defect class, different surface — that is the AI chat's page-context
 * index, not the public page, so a composition this guard makes safe to VIEW can still
 * fatal the surface an operator would use to DIAGNOSE it. Filed as #733; not fixed here,
 * and not covered by #706/#708/#730 either.
 *
 * The same defect class through OTHER surfaces is filed and deliberately
 * NOT fixed here: #706 (title/title_accent into pp_render_heading_with_accent), since
 * LANDED with its own guard test, tests/StoredTitleRenderGuardTest.php; #708 (count() on
 * a scalar items, pp_render_style_vars on a non-array style) and #730 (core's
 * esc_url/wp_kses_post, which DO fatal in production), both still open. Never try/catch a wp_kses_post
 * TypeError to degrade: the throw escapes between core's remove_filter('pre_kses', …)
 * and the matching re-add, so swallowing it de-registers block-attribute KSES for the
 * rest of the request. Guard BEFORE the call, which is what this file pins.
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
 * WHAT THIS DOES NOT PROMISE. "A background-image band can no longer 500" would be too
 * strong, and the ordering is the reason. In all three templates the
 * pp_render_style_vars() slot call runs BEFORE the background gate, so a band carrying
 * both a non-array `__pp_style` and a non-scalar `background_image` still fatals — via
 * #708, upstream of this guard, which never gets to matter. Same for a band whose
 * `title` is a stored array (#706) or whose `button_url`/`body` is (#730). What this
 * issue closes is precisely one door of several on the same corridor; the family is only
 * shut when its siblings land.
 *
 * ASSERTED AFFIRMATIVELY, NEVER BY ABSENCE OF A FATAL. phpunit.xml sets
 * failOnWarning="false", and esc_html/esc_attr render a stored array as the literal
 * string `Array` plus an E_WARNING WITHOUT fataling. A test that only proved "nothing
 * threw" would pass against a coercing implementation that painted `url(Array)` into
 * every page. So every case below asserts what the emitted HTML actually contains.
 *
 * DEGRADE, NEVER REWRITE. Nothing here touches stored data (v1.13 posture). The value
 * stays exactly as stored, the operator diagnostic still names it, and the page renders
 * without the background — the same rendering an empty background_image has always
 * produced.
 *
 *   stored props ──> pp_get_composition() ──> pp_get_component()
 *                    (plain decode, no          │
 *                     sanitising)               ├─ is_scalar($raw) ? (string) $raw : ''
 *                                               │
 *                                               ├─ modifier class ──> skipped
 *                                               ├─ overlay <div>  ──> skipped
 *                                               ├─ truthiness gate ──> skipped
 *                                               └─ pp_esc_image_src() ──> never reached
 */

use PHPUnit\Framework\TestCase;

class StoredBackgroundImageRenderGuardTest extends TestCase
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
     * while proving nothing about the page a visitor gets.
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
            'import_media envelope' => [['attachment_id' => 42, 'url' => '/bg.png', 'action' => 'imported']],
            'list of urls'          => [['/a.png', '/b.png']],
            'nested map'            => [['src' => ['url' => '/bg.png']]],
        ];
    }

    /**
     * THE primary pin. All three background-image bands in one stored composition, each
     * carrying a malformed `background_image` — plus a trailing good band that only
     * renders if nothing above it threw. This is the page that used to 500.
     *
     * @dataProvider fatalStoredShapes
     */
    public function testAStoredNonScalarBackgroundImageRendersThePageInsteadOfFataling($bad): void
    {
        $id = pp_create_page('Stored bad background_image', 'draft');
        // Thin writer, no validation — persists the shape exactly as a pre-rule install
        // holds it, as restore_composition can replay it, and as a raw meta write leaves
        // it. Going through create_page here would be the wrong test: it REJECTS this
        // shape, which is precisely why the render path needs its own guard.
        pp_update_composition($id, [
            ['component' => 'cta',     'props' => ['title' => 'Cta band', 'button_text' => 'Go', 'button_url' => '/go', 'background_image' => $bad]],
            ['component' => 'stats',   'props' => ['title' => 'Stats band', 'items' => [['number' => '40+', 'label' => 'Years']], 'background_image' => $bad]],
            ['component' => 'section', 'props' => ['body' => '<p>Section body</p>', 'layout' => 'text-only', 'background_image' => $bad]],
            // Renders last, and only if every band above survived.
            ['component' => 'cta',     'props' => ['title' => 'Page survived', 'button_text' => 'Go', 'button_url' => '/go']],
        ]);

        $html = $this->renderStored($id);

        // The page is whole.
        $this->assertStringContainsString('Page survived', $html, 'the last band renders, so nothing above threw');
        $this->assertStringContainsString('Cta band', $html);
        $this->assertStringContainsString('Stats band', $html);
        $this->assertStringContainsString('40+', $html, 'the stats numbers still render');
        $this->assertStringContainsString('<p>Section body</p>', $html);

        // And not one background gate opened anywhere on it.
        $this->assertStringNotContainsString('background-image', $html, 'a malformed background_image paints NO background');
        $this->assertStringNotContainsString('--has-bg-image', $html, 'and sets no background-image modifier');
        $this->assertStringNotContainsString('cta__overlay', $html, 'and renders no cta overlay');
        $this->assertStringNotContainsString('stats__overlay', $html, 'and renders no stats overlay');
        $this->assertStringNotContainsString('section__overlay', $html, 'and renders no section overlay');
        // failOnWarning is false and esc_* render an array as the literal `Array` without
        // fataling, so this is the assertion that separates DEGRADED from COERCED.
        $this->assertStringNotContainsString('Array', $html, 'the value is degraded, never coerced into the page');
    }

    /**
     * THE REGRESSION PIN for the predicate, on real stored bytes.
     *
     * A stored non-string SCALAR background_image is not hypothetical: create_page
     * accepts it and stores it raw (#707), and in coercive mode it has always painted.
     * is_string() would have blanked it and closed all three gates, silently dropping a
     * background the front door had just accepted. This fails the moment the predicate
     * narrows.
     *
     * A NOTE FOR WHOEVER IMPLEMENTS #707. This pin asserts COMPATIBILITY — that the guard
     * did not change how an already-accepted scalar renders — NOT that painting `url(42)`
     * is correct. It is deliberately not a contract you must preserve. When #707 tightens
     * what the WRITE path accepts, updating or deleting this pin is the expected and
     * correct move, not a regression. What must survive #707 is the surrounding property:
     * the render path degrades a non-scalar instead of fataling.
     */
    public function testAStoredScalarBackgroundImageStillPaints(): void
    {
        $id = pp_create_page('Stored scalar background_image', 'draft');
        pp_update_composition($id, [
            ['component' => 'cta',     'props' => ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/go', 'background_image' => 42]],
            ['component' => 'stats',   'props' => ['title' => 'S', 'items' => [['number' => '1', 'label' => 'L']], 'background_image' => 42]],
            ['component' => 'section', 'props' => ['body' => '<p>b</p>', 'layout' => 'text-only', 'background_image' => 42]],
        ]);

        $html = $this->renderStored($id);

        $this->assertSame(3, substr_count($html, 'background-image:url(42)'), 'all three bands still paint the scalar');
        $this->assertStringContainsString('cta--has-bg-image', $html);
        $this->assertStringContainsString('stats--has-bg-image', $html);
        $this->assertStringContainsString('section--has-bg-image', $html);
    }

    /**
     * THE SANITIZER IS STILL IN THE PATH, pinned at the call site rather than in
     * isolation.
     *
     * Every other accept-side assertion in this file uses a benign URL, on which
     * esc_url() and the `)` -> `%29` replacement are both no-ops. That means they would
     * all stay green if a future refactor of this guard block dropped the
     * pp_esc_image_src() call and emitted the guarded string raw — and this prop lands in
     * an UNQUOTED CSS `url()` token inside a `style` attribute, where a literal `)` closes
     * the token early and everything after it becomes attacker-controlled CSS. So the
     * escaper's effect is pinned here, on the stored-bytes path, with values chosen so
     * the assertion fails the moment the call disappears.
     *
     * Both cases are SCALARS on purpose: they exercise the guard's accept side and the
     * sanitizer in one pass, without touching the predicate or the degrade-never-rewrite
     * posture. pp_esc_image_src() itself is unit-tested in isolation in
     * ComponentPropsTest; this pins that the three call sites still route through it.
     */
    public function testTheEscaperStillRunsAtEveryBackgroundImageCallSite(): void
    {
        // 1. CSS url() token breakout: a literal ')' must survive as %29.
        $breakout = 'https://example.com/a.jpg);background:url(https://evil.test/x.png';
        $id = pp_create_page('Stored url() breakout', 'draft');
        pp_update_composition($id, [
            ['component' => 'cta',     'props' => ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/go', 'background_image' => $breakout]],
            ['component' => 'stats',   'props' => ['title' => 'S', 'items' => [['number' => '1', 'label' => 'L']], 'background_image' => $breakout]],
            ['component' => 'section', 'props' => ['body' => '<p>b</p>', 'layout' => 'text-only', 'background_image' => $breakout]],
        ]);
        $html = $this->renderStored($id);

        $this->assertSame(3, substr_count($html, 'a.jpg%29'), 'every call site percent-encodes the closing paren');
        $this->assertStringNotContainsString(');background:url(', $html, 'no call site lets the url() token be closed early');

        // 2. Stored-XSS vector: a data: URI of a non-image type must be rejected outright.
        $id2 = pp_create_page('Stored data: URI', 'draft');
        pp_update_composition($id2, [
            ['component' => 'cta',     'props' => ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/go', 'background_image' => 'data:text/html,<script>alert(1)</script>']],
            ['component' => 'stats',   'props' => ['title' => 'S', 'items' => [['number' => '1', 'label' => 'L']], 'background_image' => 'data:image/svg+xml,<svg onload=alert(1)></svg>']],
        ]);
        $html2 = $this->renderStored($id2);

        $this->assertStringNotContainsString('<script>', $html2, 'the text/html data URI never reaches the page');
        $this->assertStringNotContainsString('onload', $html2, 'the scripted SVG data URI never reaches the page');

        // PINNED AS PRE-EXISTING, NOT ENDORSED. A sanitizer-rejected value empties the
        // url() token but leaves the modifier and the overlay ON, because all three gates
        // key on the PRE-escaper string. That is the same scrim-over-nothing state the
        // guard's placement rationale in components/cta/cta.php calls undesigned — this
        // guard closes the NON-SCALAR route into it, not the sanitizer-rejection route.
        // Unchanged by this issue (a stored string always reached the escaper); asserted
        // here so the behaviour is recorded rather than discovered later. Closing it means
        // gating on the escaper's OUTPUT, a behaviour change filed separately.
        $this->assertSame(2, substr_count($html2, 'background-image:url()'), 'both rejected values render an empty url()');
        $this->assertStringContainsString('cta--has-bg-image', $html2, 'pre-existing: the modifier stays on for a rejected value');
        $this->assertStringContainsString('stats--has-bg-image', $html2, 'pre-existing: same on stats');
    }

    /**
     * THE -0.0 EXCEPTION, pinned on real stored bytes through BOTH storage channels.
     *
     * `-0.0` is the one scalar where the `(string)` cast flips the truthiness gate
     * (`(string) -0.0` is `'-0'`, and only `''` and `'0'` are falsy strings), so it is the
     * one value this guard newly PAINTS where it used to render plain. The renderer-level
     * sweep lives in ComponentPropsTest; what belongs here is whether stored bytes can
     * actually deliver it, and the answer is channel-dependent in a way that is easy to
     * get backwards — two reviewers of this change read it opposite ways, which is why it
     * is measured here rather than argued:
     *
     *   json_encode(-0.0)             -> text `-0`   -> json_decode -> INT 0   -> no flip
     *   stored text `-0.0` (literal)  ->                json_decode -> FLOAT -0 -> FLIP
     *
     * PHP's json_encode never emits the decimal-point form, so every writer that
     * re-encodes round-trips it to int 0. Only bytes that already hold the literal text
     * reach the flip. Both halves are asserted so neither claim can rot.
     */
    public function testNegativeZeroFlipsTheGateOnlyThroughARawMetaWrite(): void
    {
        // Channel 1 — the normal write path. json_encode flattens -0.0 to `-0`, which
        // decodes as int 0, so the band renders exactly as it always did.
        $encoded = pp_create_page('Negative zero, encoded', 'draft');
        pp_update_composition($encoded, [
            ['component' => 'cta', 'props' => ['title' => 'Encoded band', 'button_text' => 'Go', 'button_url' => '/go', 'background_image' => -0.0]],
        ]);

        $this->assertSame(0, pp_get_composition($encoded)[0]['props']['background_image'], 'json_encode round-trips -0.0 to int 0');
        $html = $this->renderStored($encoded);
        $this->assertStringContainsString('Encoded band', $html, 'the band renders');
        $this->assertStringNotContainsString('cta--has-bg-image', $html, 'and paints no background, exactly as before the guard');
        $this->assertStringNotContainsString('background-image', $html);

        // Channel 2 — stored bytes that already carry the literal `-0.0` text, which is
        // the raw-meta reachability this whole file exists for. Here the flip is real.
        $raw = pp_create_page('Negative zero, raw', 'draft');
        update_post_meta($raw, '_pp_composition', '[{"component":"cta","props":{"title":"Raw band","button_text":"Go","button_url":"/go","background_image":-0.0}}]');

        $stored = pp_get_composition($raw)[0]['props']['background_image'];
        $this->assertIsFloat($stored, 'the literal text decodes as a float, not an int');
        $this->assertSame(-0.0, $stored);

        $html = $this->renderStored($raw);
        $this->assertStringContainsString('Raw band', $html, 'the band renders');
        $this->assertStringContainsString('cta--has-bg-image', $html, 'and here the cast DOES open the gate');
        $this->assertStringContainsString('cta__overlay', $html);
    }

    /**
     * The stored value is REPORTED, not silently absorbed. The render guard is a
     * last-resort degradation, so the operator-facing diagnostic has to keep naming the
     * bad value — otherwise "no background" is indistinguishable from "no background was
     * set". Verified against the SHARED engine, which is what the check page and the
     * validate actions read; this change adds no second, surface-specific validator.
     *
     * SCOPE OF THE CLAIM, stated honestly: this holds for the NON-SCALAR shapes, which
     * are the ones the guard newly degrades. The findings engine reports nothing for a
     * non-string scalar, but the guard does not change how those render either, so it
     * introduces no new silence. Closing that gap is #707, not this issue.
     */
    public function testTheStoredValueIsStillReportedAsAFinding(): void
    {
        foreach ([
            'cta'     => ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/go', 'background_image' => ['attachment_id' => 42]],
            'stats'   => ['title' => 'T', 'items' => [['number' => '1', 'label' => 'L']], 'background_image' => ['attachment_id' => 42]],
            'section' => ['body' => '<p>b</p>', 'layout' => 'text-only', 'background_image' => ['attachment_id' => 42]],
        ] as $component => $props) {
            $findings = _pp_composition_findings([
                ['component' => $component, 'props' => $props],
            ]);

            $this->assertNotEmpty($findings, "{$component}: the malformed stored value is still surfaced");
            $this->assertContains('invalid_prop_value', array_column($findings, 'type'), $component);
            $encoded = json_encode($findings);
            $this->assertStringContainsString('background_image', $encoded, "{$component}: the finding names the prop");
            $this->assertStringContainsString('must be a string', $encoded, "{$component}: with the type rule");
        }
    }

    /**
     * The stored bytes are not touched. Degrade, never rewrite: reading the composition
     * back reports exactly what was written, so the render-time degradation cannot be
     * mistaken for a migration and a later fix-up still sees the original value.
     */
    public function testTheGuardDoesNotRewriteTheStoredValue(): void
    {
        $bad = ['attachment_id' => 42, 'url' => '/bg.png'];
        $id  = pp_create_page('Stored value preserved', 'draft');
        pp_update_composition($id, [
            ['component' => 'cta',     'props' => ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/go', 'background_image' => $bad]],
            ['component' => 'stats',   'props' => ['title' => 'T', 'items' => [['number' => '1', 'label' => 'L']], 'background_image' => $bad]],
            ['component' => 'section', 'props' => ['body' => '<p>b</p>', 'layout' => 'text-only', 'background_image' => $bad]],
        ]);

        $this->renderStored($id);

        foreach (pp_get_composition($id) as $i => $item) {
            $this->assertSame($bad, $item['props']['background_image'], "band {$i}: the stored value is untouched");
        }
    }

    /**
     * The write path stays STRICT for the shape it already rejected (rule 14.1: exercise
     * the real authoring surface, not a raw meta write). The render guard is defense for
     * data that is already stored; it must not become a reason to accept the shape at the
     * front door.
     *
     * NOT asserted here, deliberately: that the write path rejects non-string SCALARS. It
     * does not — create_page accepts `background_image: 42` and stores it raw. That gap is
     * #707. Pinning it as "strict" here would be false, and pinning the current
     * permissiveness as correct would prejudge #707's fix.
     */
    public function testTheAuthoringPathStillRejectsANonScalarBackgroundImage(): void
    {
        $bad = ['attachment_id' => 42];
        $cases = [
            'cta'     => ['title' => 'T', 'button_text' => 'Go', 'button_url' => '/go', 'background_image' => $bad],
            'stats'   => ['title' => 'T', 'items' => [['number' => '1', 'label' => 'L']], 'background_image' => $bad],
            'section' => ['body' => '<p>b</p>', 'layout' => 'text-only', 'background_image' => $bad],
        ];

        foreach ($cases as $component => $props) {
            $result = pp_execute_action('create_page', [
                'title'       => 'Rejected ' . $component,
                'composition' => [['component' => $component, 'props' => $props]],
            ]);
            $this->assertFalse($result['ok'], "{$component}: a non-scalar must not be accepted at write");
            $this->assertStringContainsString('background_image', $result['error'], "{$component}: the error names the prop");
            $this->assertStringContainsString('must be a string', $result['error'], "{$component}: with the type rule");
        }
    }

    /**
     * The accept side, on real stored bytes: an ordinary composition renders as before,
     * down to the literal style attribute and overlay markup. A guard that quietly
     * dropped legitimate backgrounds would pass every negative test in this file.
     *
     * The literal `style="background-image:url(...);"` holds because no band here sets a
     * style slot. `$style_attr` is built by imploding slot styles AND the background
     * declaration, so an authored band carrying both renders
     * `style="<slots>; background-image:url(...);"`. That combined path is not this
     * guard's business — the background fragment is appended identically either way — but
     * the literal below is exact only for the slot-less shape, which is worth saying
     * rather than leaving as an apparent whole-attribute contract.
     */
    public function testAnOrdinaryStoredCompositionIsUnchanged(): void
    {
        $id = pp_create_page('Good backgrounds', 'draft');
        pp_update_composition($id, [
            ['component' => 'cta',     'props' => ['title' => 'C', 'button_text' => 'Go', 'button_url' => '/go', 'background_image' => 'https://example.com/cta.jpg']],
            ['component' => 'stats',   'props' => ['title' => 'S', 'items' => [['number' => '1', 'label' => 'L']], 'background_image' => 'https://example.com/stats.jpg']],
            ['component' => 'section', 'props' => ['body' => '<p>b</p>', 'layout' => 'text-only', 'background_image' => 'https://example.com/section.jpg']],
        ]);

        $html = $this->renderStored($id);

        $this->assertStringContainsString('style="background-image:url(https://example.com/cta.jpg);"', $html);
        $this->assertStringContainsString('style="background-image:url(https://example.com/stats.jpg);"', $html);
        $this->assertStringContainsString('style="background-image:url(https://example.com/section.jpg);"', $html);
        $this->assertStringContainsString('<div class="cta__overlay" aria-hidden="true"></div>', $html);
        $this->assertStringContainsString('<div class="stats__overlay" aria-hidden="true"></div>', $html);
        $this->assertStringContainsString('<div class="section__overlay" aria-hidden="true"></div>', $html);
    }
}
