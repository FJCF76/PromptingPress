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

    public function testRejectedValueDroppedSiblingSurvives(): void
    {
        // A stored (never write-validated) url() lands on one slot; a valid
        // shadow lands on another. Only the url() is filtered.
        $result = pp_render_style_vars(
            ['--hero-bg' => 'url(https://example.test/ping)', '--hero-shadow' => 'var(--shadow-md)'],
            'hero'
        );
        $this->assertStringNotContainsString('url(', $result);
        $this->assertStringNotContainsString('--hero-bg', $result);
        $this->assertStringContainsString('--hero-shadow: var(--shadow-md)', $result);
    }

    public function testPassSetRendersUnchangedThroughSink(): void
    {
        $result = pp_render_style_vars(
            [
                '--hero-heading-color'        => 'currentColor',
                '--hero-bg'          => 'radial-gradient(circle at 20% 30%, #ffffff, #000000)',
                '--hero-padding-top' => '8rem',
                '--hero-shadow'      => '0 4px 12px rgba(0,0,0,0.3)',
            ],
            'hero'
        );
        $this->assertStringContainsString('--hero-heading-color: currentColor', $result);
        $this->assertStringContainsString('--hero-bg: radial-gradient(circle at 20% 30%, #ffffff, #000000)', $result);
        $this->assertStringContainsString('--hero-padding-top: 8rem', $result);
        $this->assertStringContainsString('--hero-shadow: 0 4px 12px rgba(0,0,0,0.3)', $result);
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

    public function testGridComponentDropsStoredUrlInSlotStyle(): void
    {
        // Seed the value the way restore / out-of-band writes would: straight
        // into __pp_style, bypassing the action-layer validator, then render the
        // real component and assert the url() never reaches the style attribute.
        $html = $this->render('grid', [
            'title'      => 'Cards',
            '__pp_style' => ['--grid-item-bg' => 'url(https://example.test/beacon.gif)'],
            'items'      => [['title' => 'A']],
        ]);
        $this->assertStringNotContainsString('url(', $html);
        $this->assertStringNotContainsString('--grid-item-bg', $html);
    }

    public function testGridItemStyleDropsStoredUrlSiblingSurvives(): void
    {
        // items[].style funnels through the same sink; a per-card url() is
        // dropped while a valid per-card shadow on the same card still renders.
        $html = $this->render('grid', [
            'items' => [[
                'title' => 'Card',
                'style' => [
                    '--grid-item-bg'     => 'url(https://example.test/x)',
                    '--grid-item-shadow' => 'var(--shadow-md)',
                ],
            ]],
        ]);
        $this->assertStringNotContainsString('url(', $html);
        $this->assertStringNotContainsString('--grid-item-bg', $html);
        $this->assertStringContainsString('--grid-item-shadow: var(--shadow-md)', $html);
    }

    // ── Footer color sink (components/footer/footer.php) ─────────────────────

    public function testFooterDropsStoredUrlColorSiblingSurvives(): void
    {
        // Props go straight into the footer's inline --footer-* custom props,
        // simulating an out-of-band stored option value. The url() bg is
        // dropped; the valid text color still renders.
        $html = $this->render('footer', [
            'location' => 'footer',
            'bg'       => 'url(https://example.test/ping)',
            'text'     => '#e5e7eb',
        ]);
        $this->assertStringNotContainsString('url(', $html);
        $this->assertStringNotContainsString('--footer-bg', $html);
        $this->assertStringContainsString('--footer-text: #e5e7eb', $html);
    }

    public function testFooterPassSetRendersUnchanged(): void
    {
        $html = $this->render('footer', [
            'location'   => 'footer',
            'bg'         => '#0b0f0a',
            'text'       => 'currentColor',
            'link_color' => 'var(--color-accent)',
        ]);
        $this->assertStringContainsString('--footer-bg: #0b0f0a', $html);
        $this->assertStringContainsString('--footer-text: currentColor', $html);
        $this->assertStringContainsString('--footer-link-color: var(--color-accent)', $html);
    }
}
