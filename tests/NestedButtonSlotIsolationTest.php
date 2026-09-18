<?php
/**
 * tests/NestedButtonSlotIsolationTest.php
 *
 * Per-instance button slots reach the component's OWN buttons and nothing else (issue 545).
 *
 * The five per-instance filled-button slot families (--hero-button-* / --hero-button2-* /
 * --cta-button-* / --cta-button2-* / --section-panel-cta-*) are emitted by the renderer as
 * inline custom properties on the COMPONENT ROOT, and three of their consumers select by
 * DESCENT rather than by the component's own button class: `main .btn:not(...)` (the premium
 * winner), `.hero .btn:not(...)` and `.cta .btn:not(...)`. Custom properties inherit, so those
 * slots also repainted a `.btn` an AUTHOR hand-writes into a rich-text prop — contradicting the
 * invariant stated at the top of components.css. The fix neutralises the whole family
 * (`--slot: initial`, the guaranteed-invalid value) on every composed `.btn` that is not one of
 * the three renderer-owned button elements.
 *
 * This file pins the parts of that contract that span PHP and CSS, which neither layer can see
 * alone:
 *   1. WHICH SURFACES can hold a nested `.btn` at all — derived from the renderers, so the fix
 *      is scoped to reality rather than to assumption (cta.body cannot: pp_kses_inline's `a`
 *      allowlist is href/title, so the class never survives; section panel_body/panel_items are
 *      esc_html, the #551 finding).
 *   2. WHICH CLASSES the renderers put on the buttons they own — the exact set the CSS rule must
 *      exclude. Rename a button class in a template and this goes red instead of silently
 *      unhooking that component's slots from its own button.
 *   3. The authoring path: a section body carrying that markup is ACCEPTED by the real
 *      validate surface (Section 14.1), so the nested-button case is a supported composition,
 *      not an edge case reachable only by raw meta writes.
 *
 * PHPUnit does not execute CSS. The property-list completeness of the neutralisation rule is
 * pinned in tests/js/css-lint.test.js, and the rendered cascade (nested button before/after,
 * rest and hover) in tests/e2e/style-render.spec.ts.
 */

use PHPUnit\Framework\TestCase;

class NestedButtonSlotIsolationTest extends TestCase
{
    private string $themeRoot;
    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
        $this->css       = file_get_contents($this->themeRoot . '/assets/css/components.css');
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

    /** The neutralisation rule's selector, comments stripped. */
    private function neutralisationSelector(): string
    {
        $stripped = preg_replace('/\/\*.*?\*\//s', '', $this->css) ?? $this->css;
        $found    = null;
        if (preg_match_all('/(main\s+\.btn(?::not\(\.[a-z0-9_-]+\))+)\s*\{([^}]*)\}/i', $stripped, $m, PREG_SET_ORDER)) {
            foreach ($m as $rule) {
                $decls = array_filter(array_map('trim', explode(';', $rule[2])));
                if ($decls === []) {
                    continue;
                }
                $allInitial = true;
                foreach ($decls as $d) {
                    if (!preg_match('/^--[a-z0-9-]+:\s*initial$/', $d)) {
                        $allInitial = false;
                        break;
                    }
                }
                if ($allInitial) {
                    $found = trim($rule[1]);
                    break;
                }
            }
        }
        $this->assertNotNull(
            $found,
            'components.css must carry a `main .btn:not(...) { --slot: initial; }` rule (issue 545).'
        );
        return $found;
    }

    // ── 1. The surfaces that can actually hold an author-written .btn ────────────────

    /**
     * SCOPE OF THESE TWO TESTS. tests/bootstrap.php stubs wp_kses_post() as a passthrough
     * for markup (since #709 it strips control characters and reproduces core's type
     * contract, but it still applies no allowlist), so they cannot prove what WordPress
     * core's post allowlist permits. What they
     * DO prove is the half that lives in this repo and can regress here: the renderer passes
     * these props through UNESCAPED, so author markup survives as markup. Switch either prop to
     * esc_html() and both go red. The real-WP proof that `class` survives the allowlist is the
     * E2E pair in tests/e2e/style-render.spec.ts, whose `.section__content .btn` and
     * `.hero__proof .btn` locators only resolve if kses kept the class on a real WordPress.
     */
    public function testSectionBodyCanCarryAnAuthorWrittenButton(): void
    {
        $html = $this->render('section', [
            'layout' => 'text-panel',
            'title'  => 'Plans',
            'body'   => 'Pick a plan. <a class="btn" href="/x">Inline CTA</a>',
            'panel_heading'  => 'Starter',
            'panel_cta_text' => 'Book a call',
            'panel_cta_url'  => '/contact',
        ]);
        $this->assertStringContainsString('<a class="btn" href="/x">Inline CTA</a>', $html);
        $this->assertStringNotContainsString('&lt;a class=', $html,
            'section.body must not be escaped — escaping it would make the nested-button case '
            . 'unreachable and this whole isolation rule dead code.');
    }

    /** hero.proof is the hero's second unescaped surface (the two wp_kses_post($proof_markup)
     * renders in components/hero/hero.php). Same scope note. */
    public function testHeroProofCanCarryAnAuthorWrittenButton(): void
    {
        $html = $this->render('hero', [
            'title'    => 'Ship faster',
            'button_text' => 'Start',
            'button_url'  => '/start',
            'proof'    => 'Trusted by teams <a class="btn" href="/x">Inline CTA</a>',
        ]);
        $this->assertStringContainsString('<a class="btn" href="/x">Inline CTA</a>', $html);
        $this->assertStringNotContainsString('&lt;a class=', $html);
    }

    public function testCtaTextCannotCarryAnAuthorWrittenButton(): void
    {
        // cta.body goes through pp_kses_inline (helpers.php:141), whose `a` allowlist is
        // href/title only. The anchor survives; the class does not — so the --cta-button-*
        // half of the neutralisation rule is defensive, not load-bearing. Scoping the fix
        // honestly depends on this staying true.
        $html = $this->render('cta', [
            'button_text' => 'Go',
            'button_url'  => '/go',
            'body'        => 'Read <a class="btn" href="/x">this</a>.',
        ]);
        $this->assertStringContainsString('<a href="/x">this</a>', $html);
        $this->assertStringNotContainsString('class="btn"', str_replace(
            ['cta__button btn', 'cta__button cta__button--secondary btn'],
            '',
            $html
        ));
    }

    public function testSectionPanelSurfacesCannotCarryAnyMarkup(): void
    {
        // panel_body / panel_items are esc_html (the #551 finding): not even a link is
        // possible there, let alone a button.
        $html = $this->render('section', [
            'layout'      => 'text-panel',
            'title'       => 'Plans',
            'body'        => 'Copy.',
            'panel_body'  => 'Call <a class="btn" href="/x">us</a>',
            'panel_items' => ['Item <a class="btn" href="/y">z</a>'],
        ]);
        $this->assertStringNotContainsString('<a class="btn"', $html);
        $this->assertStringContainsString('&lt;a class=&quot;btn&quot;', $html);
    }

    // ── 2. The renderer-owned button classes the CSS rule must exclude ───────────────

    /**
     * Every `.btn` a RENDERER emits, with the class list it carries. If a template renames
     * or drops one of these classes, the neutralisation rule stops excluding that button and
     * the component's own slots stop reaching it — a silent, rendered-only regression.
     */
    public function testRendererOwnedButtonsCarryTheExcludedClasses(): void
    {
        $cases = [
            ['hero', [
                'title' => 'T', 'button_text' => 'A', 'button_url' => '/a',
                'button2_text' => 'B', 'button2_url' => '/b',
            ], ['hero__cta']],
            ['cta', [
                'button_text' => 'A', 'button_url' => '/a',
                'button2_text' => 'B', 'button2_url' => '/b',
            ], ['cta__button']],
            // Section still RENDERS a button, and the sweep still checks it — what
            // changed at #1023 is that the button is no longer slot-styled, so it is not
            // in the neutralisation chain. Kept in this case list on purpose: the
            // question here is which surfaces can hold a nested `.btn` at all, and
            // section's body still can.
            ['section', [
                'layout' => 'text-panel', 'title' => 'T', 'body' => 'x',
                'panel_heading' => 'P', 'panel_cta_text' => 'C', 'panel_cta_url' => '/c',
            ], ['section__panel-cta']],
        ];

        foreach ($cases as [$component, $props, $expectedOwners]) {
            $html = $this->render($component, $props);
            $this->assertMatchesRegularExpression(
                '/class="[^"]*\bbtn\b/',
                $html,
                "{$component} must render at least one .btn for this pin to mean anything."
            );
            preg_match_all('/class="([^"]*\bbtn\b[^"]*)"/', $html, $m);
            foreach ($m[1] as $classList) {
                $classes = preg_split('/\s+/', trim($classList));
                $owned   = array_values(array_intersect($classes, $expectedOwners));
                $this->assertNotEmpty(
                    $owned,
                    "{$component} renders a .btn with classes '{$classList}' that carries none of "
                    . 'its owned button classes — the #545 rule would neutralise its own slots.'
                );
            }
        }
    }

    // RETIRED (#1026): the three tests that asserted the issue-545 NEUTRALISATION RULE
    // exists, excludes exactly the owned button classes, and is scoped to composed buttons.
    // The rule itself retired in the same change — see the long note where it used to sit in
    // assets/css/components.css.
    //
    //   testEveryRendererThatEmitsAButtonIsExcluded
    //   testNeutralisationRuleExcludesExactlyTheOwnedButtonClasses
    //   testNeutralisationRuleIsScopedToComposedButtons
    //
    // WHY THE RULE COULD GO, in one sentence, because "we deleted a guard" deserves one: it
    // reset per-instance button slot families to `initial` on any composed `.btn` the
    // renderer does not own, and no component declares a button slot family any more —
    // `--hero-button-*` left at #986, `--section-panel-cta-*` at #1023, `--cta-button*-*` at
    // #1026 — so it was neutralising properties no write path could produce.
    //
    // THE REST OF THIS FILE IS DELIBERATELY KEPT. Its other tests are about the DEFECT
    // rather than the fix: which renderers emit a button, which props are rich-text surfaces
    // that could carry an author-written `.btn`, and what each sanitizer's allowlist admits.
    // Every one of those facts still holds and still matters — a future component that emits
    // inline custom properties would reopen the class, and these are the tests that would
    // notice. What is gone is the assertion that one particular stylesheet rule exists.



    // ── 3. Authoring path (Section 14.1) ─────────────────────────────────────────────

    public function testSectionBodyWithNestedButtonValidatesThroughTheAuthoringSurface(): void
    {
        // The nested-button case must be a SUPPORTED composition, authored through the real
        // validate surface — not a shape only reachable by a raw _pp_composition meta write.
        $composition = [[
            'component' => 'section',
            'props'     => [
                'layout'         => 'text-panel',
                'title'          => 'Plans',
                'body'           => '<p>Pick a plan. <a class="btn" href="/x">Inline CTA</a></p>',
                'panel_heading'  => 'Starter',
                'panel_cta_text' => 'Book a call',
                'panel_cta_url'  => '/contact',
            ],
            // The panel button's fill is the `panel-cta` role since #1023, not a slot.
            // The invariant this case is about is unchanged and is the interesting half:
            // an author-written `.btn` inside the rich-text `body` must not be repainted
            // by the band's own button design.
            'udc'       => ['panel-cta' => ['background' => ['fill' => '#7c3aed']]],
        ]];
        $this->assertTrue(
            pp_validate_composition($composition),
            'a section body carrying an author-written .btn, styled with the panel fill slot, '
            . 'must validate through the real authoring surface.'
        );
    }
}
