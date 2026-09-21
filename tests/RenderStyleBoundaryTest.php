<?php
/**
 * tests/RenderStyleBoundaryTest.php
 *
 * Render-boundary re-validation of stored style values (issue #330).
 *
 * Style values are strictly validated at WRITE time, but two paths can put a
 * value into storage that never passed current validation: snapshot restore
 * (never blocked by current rules — the #233 principle) and out-of-band DB
 * writes. This suite pins the defense-in-depth check applied by
 * pp_render_style_value_allowed() at every inline-style sink:
 *
 *   - the shared component sink pp_render_style_vars() (all components);
 *   - the grid per-item items[].style path (grid.php);
 *   - the footer color sink in components/footer/footer.php.
 *
 * TWO OF THOSE THREE SINKS NOW HAVE NO SHIPPED CONSUMER, and that is asserted rather
 * than assumed. Ruling A1 closed the chrome surface, and #1101 took the last component
 * declaring `styling.style_slots` (grid) onto the Universal Design Contract, which also
 * retired `items[].style`. The boundary's HELPER is untouched and still exercised
 * directly below — it is the shared gate any future sink would call — while the two
 * component-facing arms are repriced to pin the closure instead of the filtering. The
 * long records live on the replacement methods.
 *
 * Two invariants:
 *   - REJECT SET: a stored value that never passed write-time validation
 *     (url(...), expression(...), @import, backslash escapes, control chars) is
 *     NOT emitted; sibling valid declarations in the same map still render.
 *   - PASS SET: every legitimate value (colors incl. transparent/currentColor,
 *     var(--known-token), validated gradients incl. radial-at-position, lengths,
 *     shadows) renders unchanged across the slot types.
 *
 * These pin behavior at the render boundary only — write-time and restore
 * semantics are untouched (a rejected value is dropped from output, never a
 * blocked page or a failed restore).
 */

use PHPUnit\Framework\TestCase;

class RenderStyleBoundaryTest extends TestCase
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

    // ── Layer 1: the shared gate helper (unit) ───────────────────────────────

    /**
     * The conservative reject set fires regardless of slot type, so it protects
     * even a (hypothetical) untyped slot. Passing null type isolates layer 1.
     */
    public function testHelperRejectSetUntyped(): void
    {
        foreach ([
            'url(https://example.test/ping)',
            'URL(https://example.test/ping)',
            'url  (https://example.test/ping)',
            'expression(alert(1))',
            '@import "https://evil.test/x.css"',
            "#fff\\65 ",                 // backslash / CSS escape
            "#fff\t",                    // control char (tab)
            "red\n; background:url(x)",  // control char (newline) + injection ;
            '#000; color:red',           // existing ; injection guard
            '#000} body {display:none',  // existing } injection guard
        ] as $bad) {
            $this->assertFalse(
                pp_render_style_value_allowed($bad, null),
                'Untyped reject set should drop: ' . json_encode($bad)
            );
        }
    }

    /**
     * With type context, the helper delegates to the shared write-time engine —
     * so obfuscation the literal reject-set regex would miss (a CSS-comment
     * split like u/**\/rl() is still dropped because it is not a valid color.
     */
    public function testHelperTypedPathCatchesObfuscation(): void
    {
        $this->assertFalse(
            pp_render_style_value_allowed('u/**/rl(https://example.test)', 'color'),
            'CSS-comment-obfuscated url() must be rejected by the typed color engine.'
        );
        $this->assertFalse(
            pp_render_style_value_allowed('notacolor', 'color'),
            'A non-color must be rejected on a color slot at the render boundary.'
        );
    }

    /**
     * The untyped ALLOW arm: with no type context (type === null), a clean value
     * passes layer 1 and is emitted — layer 2 is skipped. This is the sole
     * pass-through path for a (hypothetical) slot without a declared type.
     */
    public function testHelperNullTypeAllowsCleanValue(): void
    {
        $this->assertTrue(pp_render_style_value_allowed('#1a1a2e', null));
        $this->assertTrue(pp_render_style_value_allowed('8rem', null));
    }

    /**
     * The pass set survives across every slot type — the helper never drops a
     * value the write-time engine would accept. No second grammar.
     */
    public function testHelperPassSetByType(): void
    {
        $pass = [
            ['color',    '#1a1a2e'],
            ['color',    'transparent'],
            ['color',    'currentColor'],
            ['color',    'var(--color-accent)'],
            ['length',   '8rem'],
            ['gradient', 'linear-gradient(135deg, #1a1a2e, #16121f)'],
            ['gradient', 'radial-gradient(circle at 20% 30%, #ffffff, #000000)'],
            ['shadow',   'var(--shadow-md)'],
            ['shadow',   '0 4px 12px rgba(0,0,0,0.3)'],
        ];
        foreach ($pass as [$type, $val]) {
            $this->assertTrue(
                pp_render_style_value_allowed($val, $type),
                "Legit {$type} value must pass the render boundary: {$val}"
            );
        }
    }

    // ── Component sink: pp_render_style_vars() ───────────────────────────────

    /**
     * THE COMPONENT INLINE-STYLE SINK HAS NO SHIPPED CONSUMER LEFT (#1101), and that is a
     * stronger guarantee than the three grid arms this replaces.
     *
     * WHAT THEY PROVED. `testRejectedValueDroppedSiblingSurvives` fed a stored (never
     * write-validated) `url(https://example.test/ping)` to `--grid-bg` beside a legitimate
     * `var(--shadow-md)` on `--grid-item-shadow`, and pinned that the render boundary
     * dropped exactly one of the two — the #330 defence-in-depth posture, where a value
     * that reached storage through snapshot restore (never blocked, by the #233 rule) or an
     * out-of-band DB write is filtered on its way to the browser rather than blocking a
     * page. `testPassSetRendersUnchangedThroughSink` pinned the other half over four slot
     * TYPES at once — colour, gradient, length and shadow — so the boundary could not be
     * satisfied by dropping everything. `testGridComponentDropsStoredUrlInSlotStyle` and
     * `testGridItemStyleDropsStoredUrlSiblingSurvives` drove the same two facts through the
     * REAL component, at band grain and at `items[].style` grain, which is where the bug's
     * actual shape lived.
     *
     * WHY THEY HAVE NO SUBJECT. grid was the last component in the theme declaring
     * `styling.style_slots`, and #1101 rebuilt it on the Universal Design Contract. The
     * sink's declared-slot filter (`pp_style_declaration_renders`) therefore drops EVERY
     * key for EVERY shipped component, so the four arms above now assert that an empty
     * string does not contain a url — true, and vacuous. The `items[].style` path is gone
     * outright: grid's `retired_props` routes it to the entry's own `udc` map, which emits
     * `[data-pp-band="…"] [data-pp-item="…"]` rules instead of an inline attribute.
     *
     * WHAT IS PINNED INSTEAD. Not "a bad value is dropped from the map" but "no map of any
     * shape, on any shipped component, at band grain OR item grain, produces a single
     * declaration". A regression here would not be one leaked declaration — it would be a
     * whole component climbing back out of the cascade onto an inline attribute that
     * outranks every stylesheet, which is the surface §3.4 forbids outright. The hostile
     * values are kept as the fixtures, so if the sink ever emits again it emits THESE, and
     * the failure names the exact defect the #330 boundary existed to catch.
     *
     * Layer 1 is untouched and still live above: `pp_render_style_value_allowed()` is a
     * pure function, it is the shared gate the footer sink and any future sink call, and
     * its reject/pass sets are exercised directly rather than through a component.
     */
    public function testNoShippedComponentCanPutAnInlineStyleMapThroughTheSink(): void
    {
        // One value per slot TYPE the pass set used to cover, plus the url() the reject set
        // was built around — so this fails loudly whichever half of the boundary regresses.
        $map = [
            '--grid-bg'            => 'url(https://example.test/ping)',
            '--grid-heading-color' => 'currentColor',
            '--grid-padding-top'   => '8rem',
            '--grid-item-shadow'   => 'var(--shadow-md)',
        ];

        $checked = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($schema, "{$component}/schema.json is not valid JSON");
            $this->assertSame(
                [],
                $schema['styling']['style_slots'] ?? [],
                "{$component} declares style slots again — the sink has a consumer, and the "
                . 'three #330 grid arms this test replaced should come back with it'
            );

            // The component name is substituted into the map so a component is never asked
            // about a foreign prefix: the point is that its OWN slot-shaped names are dead
            // too, not merely that grid's are.
            $own = [];
            foreach ($map as $slot => $value) {
                $own[str_replace('--grid-', "--{$component}-", $slot)] = $value;
            }

            $this->assertSame('', pp_render_style_vars($own, $component),
                "{$component} emitted an inline style declaration at band grain");
            $this->assertSame('', pp_render_style_vars($own, $component, true),
                "{$component} emitted an inline style declaration at item grain");
            $checked[] = $component;
        }

        // ANTI-VACUITY: an empty roster would make every assertion above unreachable while
        // reporting green — the exact shape this whole rewrite exists to refuse.
        $this->assertGreaterThanOrEqual(
            12,
            count($checked),
            'component discovery found fewer schemas than the theme ships — the glob broke, '
            . 'and the emptiness asserted above is then a fact about the scan, not the sink'
        );
    }

    /**
     * The boundary is render-only: filtering a bad value out of the OUTPUT must
     * not scrub it from the caller's stored map. This is why a snapshot restore
     * round-trips unaffected (#330 acceptance) — the change touches render output
     * only, never storage. (This suite changes no restore/update code; a rejected
     * value stays in storage and only fails to reach the browser.)
     */
    public function testRenderBoundaryDoesNotMutateStoredStyle(): void
    {
        $stored   = ['--hero-bg' => 'url(https://example.test/ping)', '--hero-shadow' => 'var(--shadow-md)'];
        $snapshot = $stored;
        $out      = pp_render_style_vars($stored, 'hero');
        $this->assertStringNotContainsString('url(', $out);   // filtered from rendered output
        $this->assertSame($snapshot, $stored);                // source map left byte-identical
    }

    // ── Render-through: real component output (the bug's actual shape) ───────

    /**
     * THE RENDER-THROUGH HALF, AT THE SAME RETIREMENT (#1101) — see the long record on
     * `testNoShippedComponentCanPutAnInlineStyleMapThroughTheSink` above.
     *
     * These two arms drove the #330 boundary through the REAL component rather than
     * through the sink helper, which is where the bug's actual shape lived: a stored
     * `url()` seeded straight into `__pp_style` (bypassing the action-layer validator,
     * exactly as a `restore_composition` or a raw meta write does) had to be absent from
     * the rendered `style` attribute, at band grain and again at `items[].style` grain,
     * with a valid per-card `var(--shadow-md)` sibling still painting.
     *
     * Both paths are gone. grid declares no slots, so `__pp_style` drops every key; and
     * `items[].style` is named in grid's `retired_props`, routed to the entry's own `udc`
     * map — which emits a band- and item-scoped RULE rather than an inline attribute, so
     * per-card design participates in the cascade instead of outranking it.
     *
     * The seeds are kept verbatim and the assertion is widened to the strongest form the
     * markup permits: not "this declaration is absent" but "there is no style attribute on
     * any element at all". A leak of any shape — a key that starts resolving, a new inline
     * sink, a re-added per-item map — fails here.
     */
    public function testTheGridComponentEmitsNoInlineStyleAttributeFromEitherRetiredPath(): void
    {
        foreach ([
            'band-grain __pp_style' => [
                'title'      => 'Cards',
                '__pp_style' => [
                    '--grid-item-bg'     => 'url(https://example.test/beacon.gif)',
                    '--grid-item-shadow' => 'var(--shadow-md)',
                ],
                'items'      => [['title' => 'A']],
            ],
            'item-grain items[].style' => [
                'items' => [[
                    'title' => 'Card',
                    'style' => [
                        '--grid-item-bg'     => 'url(https://example.test/x)',
                        '--grid-item-shadow' => 'var(--shadow-md)',
                    ],
                ]],
            ],
        ] as $label => $props) {
            $html = $this->render('grid', $props);

            $this->assertNotSame('', trim($html), "premise: grid rendered nothing for the {$label} case");
            $this->assertDoesNotMatchRegularExpression(
                '/<[a-z][^>]*\sstyle=/i',
                $html,
                "grid emitted an inline style attribute from the {$label} path. §3.4 forbids "
                . 'inline style emission outright: an inline attribute outranks every '
                . 'stylesheet, which is precisely why per-band and per-card design had to '
                . 'move to the `udc` map and its scoped rules'
            );
            $this->assertStringNotContainsString('url(', $html, "the {$label} case leaked a stored url()");
            $this->assertStringNotContainsString('--grid-item-bg', $html);
            $this->assertStringNotContainsString('--grid-item-shadow', $html);
        }
    }

    // ── Footer color sink (components/footer/footer.php) ─────────────────────

    /**
     * THE CHROME INLINE-STYLE SURFACE IS CLOSED, which is a stronger guarantee than
     * the two tests this replaces.
     *
     * They exercised the #330 render boundary over the footer's inline `--footer-*`
     * custom properties: a stored `url()` background was dropped while its valid
     * sibling still painted. That boundary was doing real work, but only because the
     * footer emitted an inline style attribute at all — and an inline style attribute
     * outranks every stylesheet, which is precisely why chrome styling could not
     * participate in the cascade the UDC engine provides.
     *
     * Ruling A1 removed the surface rather than hardening it further. So the thing to
     * pin is no longer "a bad value is dropped from the attribute" but "there is no
     * attribute": no value of any shape, passed as any prop, can put an inline style
     * on chrome. A regression here would not be a dropped declaration — it would be
     * chrome quietly climbing back out of the cascade.
     */
    public function testChromeEmitsNoInlineStyleAttributeForAnyPropAtAll(): void
    {
        foreach ([
            ['bg' => 'url(https://example.test/ping)', 'text' => '#e5e7eb'],
            ['bg' => '#0b0f0a', 'text' => 'currentColor', 'link_color' => 'var(--color-accent)'],
            ['bg' => 'linear-gradient(135deg, #1a1a2e, #16121f)'],
        ] as $props) {
            foreach (['footer' => 'footer', 'nav' => 'header'] as $component => $tag) {
                $html = $this->render($component, ['location' => $component] + $props);
                $this->assertDoesNotMatchRegularExpression(
                    '/<' . $tag . '[^>]*\sstyle=/',
                    $html,
                    "{$component} must emit no inline style attribute — the UDC block is the only styling source"
                );
                $this->assertStringNotContainsString('--footer-bg', $html);
                $this->assertStringNotContainsString('--header-bg', $html);
                $this->assertStringNotContainsString('url(', $html);
            }
        }
    }


}
