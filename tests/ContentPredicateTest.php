<?php
/**
 * tests/ContentPredicateTest.php
 *
 * The one content predicate (lib/content.php, #1242 T3a), row by row against
 * docs/v2/LAYER-3-CONTRACT.md §3 (admissions, as ratified in #1167) and §4 (exclusions,
 * each by its own test-shape column where that shape applies at write). Every admission
 * row is pinned in BOTH directions (§10: "a planted defect in the gate must turn a pin
 * red"), and T-18's open-set rows prove the Δ lists are spec-derived, not closed.
 *
 * Runs core's real WP_HTML_Processor / WP_HTML_Tag_Processor (WordPress 7.0, vendored
 * under tests/fixtures/wp-html-api-7.0): the predicate's correctness IS the tree
 * builder's behaviour, so a stub would test nothing.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ContentWriteGateTest.php';

class ContentPredicateTest extends TestCase
{
    private function losses(string $bytes, string $sink = 'rich', array $ctx = []): array
    {
        return pp_content_sanitize($bytes, $sink, $ctx)['losses'];
    }

    /** One incoming band against a stored page: does it claim a stored band (§2.6, one-to-one)? */
    private function unchanged(array $item, array $stored): bool
    {
        return pp_content_unchanged_keys([$item], $stored) !== [];
    }

    private function clauses(string $bytes, string $sink = 'rich', array $ctx = []): array
    {
        return array_values(array_unique(array_column($this->losses($bytes, $sink, $ctx), 'clause')));
    }

    private function assertAdmitted(string $bytes, string $sink = 'rich', array $ctx = [], string $why = ''): void
    {
        $losses = $this->losses($bytes, $sink, $ctx);
        $this->assertSame([], $losses, trim("{$why}\nexpected admitted: {$bytes}\n" . implode("\n", array_column($losses, 'message'))));
    }

    private function assertRefused(string $bytes, string $clause, string $sink = 'rich', array $ctx = []): void
    {
        $clauses = $this->clauses($bytes, $sink, $ctx);
        $this->assertContains($clause, $clauses, "expected {$clause} for: {$bytes}; got " . implode(',', $clauses));
    }

    // ── guard and PLAIN ───────────────────────────────────────────────────────

    public function testANonStringIsALossNotAThrow(): void
    {
        foreach ([['x'], 1, null, new stdClass()] as $value) {
            $r = pp_content_sanitize($value, 'rich');
            $this->assertSame('guard', $r['losses'][0]['clause']);
            $this->assertSame('', $r['html']);
        }
    }

    public function testPlainIsNeverParsedAndNeverALoss(): void
    {
        $r = pp_content_sanitize('The <details> element <script>x</script>', 'plain');
        $this->assertSame([], $r['losses']);
        $this->assertSame('The &lt;details&gt; element &lt;script&gt;x&lt;/script&gt;', $r['html']);
    }

    // ── E1 ────────────────────────────────────────────────────────────────────

    public function testE1NameMatrixAcrossNamespaces(): void
    {
        $shapes = [
            '<p onclick="x">a</p>', '<p ONCLICK="x">a</p>', '<p OnMouseOver="x">a</p>',
            '<p on-x="1">a</p>', '<p on="1">a</p>', '<img src="/a.png" alt="" onerror="x">',
            '<svg onload="x"></svg>', '<svg><circle onbegin="x" r="1"/></svg>',
            '<svg><g onfocusin="x"></g></svg>', '<math onclick="x"><mi>x</mi></math>',
            '<div data-wp-interactive="x">a</div>', '<div data-wp-on--click="a">a</div>',
            '<details ontoggle="x"><summary>s</summary></details>',
        ];
        foreach ($shapes as $shape) {
            $this->assertRefused($shape, 'E1');
        }
        $this->assertAdmitted('<p data-only="1" title="online">a</p>');
    }

    // ── E2 ────────────────────────────────────────────────────────────────────

    public function testE2ObfuscationMatrixIsRefusedNeverRewritten(): void
    {
        $shapes = [
            '<a href="javascript:alert(1)">x</a>', '<a href="JaVaScRiPt:alert(1)">x</a>',
            '<a href="&#106;avascript:alert(1)">x</a>', '<a href="&#x6A;avascript:x">x</a>',
            "<a href=\"java\tscript:x\">x</a>", "<a href=\"java\nscript:x\">x</a>", '<a href="java&#x09;script:x">x</a>',
            '<a href=" javascript:x">x</a>', '<a href="&#1;javascript:x">x</a>', "<a href=\"\x01 javascript:x\">x</a>", '<a href="javascript&colon;x">x</a>',
            '<a href="vbscript:x">x</a>', '<a href="data:text/html,x">x</a>',
            '<img alt="" src="data:image/svg+xml;base64,PHN2Zz4=">', '<img alt="" src="javascript:x">',
            '<img alt="" srcset="/a.png 1x, javascript:x 2x">', '<img alt="" srcset="data:image/png;base64,AAAA 1x">',
            '<video poster="javascript:x"></video>', '<blockquote cite="javascript:x">q</blockquote>',
            '<form action="javascript:x"></form>', '<svg><a href="javascript:x"><text>t</text></a></svg>',
            '<svg><a xlink:href="javascript:x"><text>t</text></a></svg>',
            '<a href="ms-msdt:x">x</a>', '<img alt="" src="sip:x">',
            '<div itemscope itemtype="javascript:x">a</div>',
        ];
        foreach ($shapes as $shape) {
            $this->assertRefused($shape, 'E2');
            // §1.3's rewrite-to-relative is pinned ABSENT: the render view drops the
            // attribute; it never emits a rewritten href="alert(1)".
            $this->assertStringNotContainsString('href="alert', pp_content_sanitize($shape, 'rich')['html']);
        }
        foreach ([
            '<a href="https://example.com/x">x</a>', '<a href="//cdn.example/x">x</a>', '<a href="/rel">x</a>',
            '<a href="#frag">x</a>', '<a href="mailto:a@example.com">x</a>', '<a href="tel:+1">x</a>',
            '<a href="HTTPS://EXAMPLE.COM">x</a>', '<a href="sip:a@example.com">x</a>', '<a href="geo:1,2">x</a>',
            '<a href="facetime:x">x</a>', '<a href="signal:x">x</a>', '<a href="maps:q=x">x</a>',
            '<img alt="" src="data:image/webp;base64,UklGRg==">', '<img alt="" src="data:image/avif;base64,AAAA">',
            '<img alt="" srcset="/a.png 1x, https://cdn.example/b.png 2x" sizes="(min-width: 50em) 50vw, 100vw">',
        ] as $ok) {
            $this->assertAdmitted($ok);
        }
    }

    public function testDataImagesStopAtTheExistingCap(): void
    {
        $big = 'data:image/png;base64,' . str_repeat('A', PP_CONTENT_DATA_IMAGE_MAX_BYTES);
        $this->assertNotNull(pp_content_url_loss($big, 'img-src'));
        $this->assertNull(pp_content_url_loss('data:image/png;base64,' . str_repeat('A', 1000), 'img-src'));
        $this->assertNotNull(pp_content_url_loss('data:image/png;base64,AAAA', 'fetch'), 'img src only');
    }

    // ── E3 / P-11 ─────────────────────────────────────────────────────────────

    public function testE3ElementsAndSrcdocAndTheSameInstallPdfException(): void
    {
        foreach (['iframe', 'frame', 'frameset', 'embed', 'applet', 'portal', 'fencedframe'] as $tag) {
            $this->assertRefused("<{$tag} src=\"https://example.com\"></{$tag}>", 'E3');
        }
        $this->assertRefused('<div srcdoc="&lt;script&gt;">x</div>', 'E3');
        $uploads = 'https://example.com/wp-content/uploads/2026/10/a.pdf';
        $this->assertAdmitted('<object type="application/pdf" data="' . $uploads . '"></object>');
        $this->assertAdmitted('<object type="application/pdf" data="https://example.com/wp-content/uploads/a.pdf" width="600" height="400"></object>');
        foreach ([
            '<object type="application/pdf" data="https://evil.example/wp-content/uploads/a.pdf"></object>',
            '<object type="application/pdf" data="https://example.com/wp-content/uploads/a.pdf?x=1"></object>',
            '<object type="application/pdf" data="https://example.com/a.pdf"></object>',
            '<object type="text/html" data="' . $uploads . '"></object>',
            '<object data="' . $uploads . '"></object>',
            '<object type="application/pdf" data="https://example.com/wp-content/uploads/../../x.pdf"></object>',
        ] as $refused) {
            $this->assertRefused($refused, 'E3');
        }
    }

    // ── E4 ────────────────────────────────────────────────────────────────────

    public function testE4ElementsAndTheIgnoredTokensOnlyTheLexicalViewSees(): void
    {
        foreach (['script', 'style', 'noscript', 'template', 'base', 'meta', 'link', 'title', 'slot',
            'xmp', 'noembed', 'noframes', 'plaintext', 'listing'] as $tag) {
            $this->assertRefused("<{$tag}>x</{$tag}>", 'E4');
        }
        // The tree builder ignores these tokens; a browser merges the attributes onto the
        // page's real <html>/<body> (measured: WP_HTML_Processor emits no token for them).
        $this->assertRefused('<body onload="x">t', 'E4');
        $this->assertRefused('<html lang="x">t', 'E4');
        $this->assertRefused('t</body>', 'E4');
        // The render view never leaves a refused script's body as visible text (§1.3 leak).
        $html = pp_content_sanitize('<p>a</p><script>var leak=1;</script>', 'rich')['html'];
        $this->assertStringNotContainsString('leak', $html);
    }

    public function testTheLexicalViewJudgesTokensTheTreeDrops(): void
    {
        // Chromium's customizable <select> keeps elements inside <option> that the WP 7.0
        // tree builder drops; the lexical view still judges them (the union rule).
        $this->assertRefused('<select><option>a<img src="/x.png" alt="" onerror="x"></option></select>', 'E1');
        $this->assertRefused('<select><option><iframe src="/x"></iframe></option></select>', 'E3');
    }

    // ── E5 / Δ1 ───────────────────────────────────────────────────────────────

    public function testE5ActiveSvgAndMathml(): void
    {
        foreach ([
            '<svg><animate attributeName="href" to="javascript:x"/></svg>',
            '<svg><set attributeName="href" to="javascript:x"/></svg>',
            '<svg><animateTransform/></svg>', '<svg><animateMotion/></svg>', '<svg><discard/></svg>',
            '<svg><foreignObject><div>x</div></foreignObject></svg>', '<svg><image href="https://x/a.png"/></svg>',
            '<svg><filter id="f"><feImage href="https://x/a.png"/></filter></svg>', '<svg><script>x</script></svg>',
            '<svg><style>*{}</style></svg>', '<math><annotation-xml><b>x</b></annotation-xml></math>',
            '<math><maction>x</maction></math>', '<svg><circle href="#a" r="1"/></svg>',
        ] as $shape) {
            $clauses = $this->clauses($shape);
            $this->assertNotSame([], array_intersect(['E5', 'E4'], $clauses), "expected E5 for {$shape}");
        }
    }

    public function testDelta1StaticSvgBothDirections(): void
    {
        $this->assertAdmitted('<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" focusable="false" xml:space="preserve"><title>Icon</title><desc>d</desc><defs><linearGradient id="_g-1"/><clipPath id="c"><rect width="1" height="1"/></clipPath></defs><g transform="translate(1 1)"><path d="M0 0L1 1" fill="url(#_g-1)" stroke="currentColor" stroke-width="2"/><circle cx="1" cy="1" r="1" clip-path="url( \'#c\' )"/></g></svg>');
        // P-12: fragment href on gradients/patterns/filters/textPath/use.
        $this->assertAdmitted('<svg><defs><linearGradient id="a"/><radialGradient id="b" href="#a"/><pattern id="p" xlink:href="#a"/><filter id="f" href="#a"><feGaussianBlur stdDeviation="2"/></filter><path id="t" d="M0 0"/></defs><use href="#t"/><text><textPath href="#t">x</textPath></text></svg>');
        // Each refused row names its own clause AND its own reason, so a planted defect in
        // one value gate cannot be masked by a different loss on the same input.
        foreach ([
            ['<svg><use href="https://evil.example/s.svg#x"/></svg>', 'D1', 'P-12'],
            ['<svg><linearGradient href="/other.svg#a"/></svg>', 'D1', 'P-12'],
            ['<svg><path d="M0 0" fill="url(https://evil.example/a)"/></svg>', 'D1', 'url('],
            ['<svg><path d="M0 0" fill="url(#a) red; x"/></svg>', 'D1', 'control characters'],
            ['<svg><path d="M0 0" fill="\\75 rl(x)"/></svg>', 'D1', 'control characters'],
            ['<svg xml:base="https://evil.example/"><path d="M0"/></svg>', 'D1', 'xml:base'],
            ['<svg xmlns="http://evil.example/ns"></svg>', 'D1', 'xmlns must be'],
            ['<svg xml:space="bogus"></svg>', 'D1', 'xml:space must be'],
            ['<svg><title><b>x</b></title></svg>', 'D1', 'text-only'],
            ['<svg><desc><img src="/a.png" alt=""></desc></svg>', 'D1', 'text-only'],
            ['<svg><path d="M0 0" stroke="javascript:x"/></svg>', 'D1', 'javascript:'],
            ['<svg><rect mask="image-set(&#39;https://evil.example/m.png&#39; 1x)"/></svg>', 'D1', 'image-set('],
            ['<svg><rect cursor="src(&#39;https://evil.example/c.png&#39;)"/></svg>', 'D1', 'src('],
            // Editor-namespace attributes run the SAME Δ1 grammar (cycle-7 ruling), CSS
            // escapes included: inert in a browser or not, one grammar.
            ['<svg><g inkscape:x="javascript:alert(1)"></g></svg>', 'D1', 'javascript:'],
            ['<svg><g inkscape:x="java\\73 cript:alert(1)"></g></svg>', 'D1', 'control characters'],
            ['<svg><g sodipodi:x="url(https://evil.example/a)"></g></svg>', 'D1', 'url('],
            ['<svg><g inkscape:label="a;b"></g></svg>', 'D1', 'control characters'],
            ['<svg><circle href="#a" r="1"/></svg>', 'E5', 'href is refused'],
        ] as [$refused, $clause, $fragment]) {
            $hit = array_values(array_filter($this->losses($refused), static fn ($l) => $l['clause'] === $clause));
            $this->assertNotSame([], $hit, "expected {$clause} for {$refused}");
            $this->assertStringContainsString($fragment, implode(' | ', array_column($hit, 'message')), $refused);
        }
        // MathML: core `post`'s set plus MathML Core's <none/>.
        $this->assertAdmitted('<math display="block"><mmultiscripts><mi>x</mi><none/><mi>y</mi></mmultiscripts><mfrac><mn>1</mn><mn>2</mn></mfrac></math>');
        $this->assertRefused('<math><mblah>x</mblah></math>', 'E8');
        // Parse note: viewbox is emitted as viewBox.
        $this->assertStringContainsString('viewBox="0 0 1 1"', pp_content_sanitize('<svg viewbox="0 0 1 1"></svg>', 'rich')['html']);
    }

    /** T-18 under P-18: an unlisted-in-the-contract but valid static SVG construct passes. */
    public function testDelta1IsSpecDerivedNotTheContractsPrintedList(): void
    {
        $this->assertAdmitted('<svg><filter id="l"><feDiffuseLighting lighting-color="#fff" surfaceScale="2"><feDistantLight azimuth="45" elevation="30"/></feDiffuseLighting><feTile/><feConvolveMatrix kernelMatrix="1 0 0 0 1 0 0 0 1" order="3"/></filter><text textLength="40" lengthAdjust="spacing" color-interpolation-filters="sRGB">t</text></svg>');
        $this->assertAdmitted('<svg xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" inkscape:version="1.3"><metadata><rdf:RDF><cc:Work about=""/></rdf:RDF></metadata><g inkscape:label="Layer 1" sodipodi:nodetypes="cc"></g></svg>');
        // Prefixed elements are inert only INSIDE <metadata>; the scope ends with it.
        $this->assertRefused('<svg><metadata><rdf:RDF/></metadata><x:y/></svg>', 'E8');
    }

    // ── E6 ────────────────────────────────────────────────────────────────────

    public function testE6EngineNamespace(): void
    {
        foreach (['data-pp-band="x"', 'data-pp-component="grid"', 'data-pp-item="it-1a2b3c4d"', 'data-pp-chrome="nav"',
            'data-pp-band-overlay=""', 'data-pp-island="x"', 'data-pp-island-kind="plain"', 'data-pp-s-0123="0"',
            'data-pp-style-slot="x"', 'id="pp-1a2b3c4d"', 'id="it-0f0f0f0f"', 'id="main"', 'id="pp-nav-menu"'] as $attr) {
            $this->assertRefused("<div {$attr}>x</div>", 'E6');
        }
        $this->assertRefused('<div id="pricing">x</div>', 'E6', 'rich', ['anchors' => ['pricing']]);
        $this->assertAdmitted('<div id="pricing-2" class="section__content btn">x</div>', 'rich', ['anchors' => ['pricing']]);
        $this->assertAdmitted('<div id="pp-notminted">x</div>');
    }

    // ── E7 / Δ3 ───────────────────────────────────────────────────────────────

    public function testDelta3StyleGateBothDirections(): void
    {
        foreach ([
            'transform: rotate(3deg)', 'filter: blur(2px)', 'text-shadow: 0 1px 2px #000', 'transition: opacity .2s',
            'animation: none', 'inset: 0', 'outline: 1px solid', 'clip-path: circle(50%)', 'mix-blend-mode: multiply',
            'isolation: isolate', 'place-items: center', 'text-wrap: balance', 'list-style: none', 'pointer-events: none',
            'color: rgb(0 0 0 / .5)', 'color: hsl(10 50% 50%)', 'color: oklch(0.7 0.1 200)',
            'color: color-mix(in srgb, red 50%, blue)', 'grid-template-areas: &quot;a b&quot; &quot;c d&quot;',
            'font-family: &quot;Inter&quot;, sans-serif', 'position: fixed', '--brand: #f00',
            'fill: url(#grad)', 'list-style-type: disc', 'text-overflow: ellipsis', 'quotes: auto',
            '-webkit-line-clamp: 3', 'unknown-but-valid-name: 1',
        ] as $decl) {
            // A url(#id) reference is E12's: its target sits in the same band.
            $this->assertAdmitted('<div style="' . $decl . '">x</div><svg><linearGradient id="grad"/></svg>');
        }
        $this->assertRefused('<div style="fill: url(#grad)">x</div>', 'E12');
        foreach ([
            'background: url(https://x/a.png)', 'background-image: image-set("a.png" 1x)', 'color: red !important',
            'color: red ! important', 'all: unset', 'content: "x"', 'behavior: url(x.htc)', '-moz-binding: url(x)',
            '-pp-background-overlay: x', '--pp-color-accent: red', 'Color: red', 'width: expression(alert(1))',
            'color: re\\64', 'font-family: "O\'Reilly Sans"', 'quotes: "«" "»"', 'list-style-type: "✓"',
            'text-overflow: "…"', 'color: red; /* x */', 'color red', '@import "x"', 'width: calc(1px',
        ] as $decl) {
            $this->assertRefused('<div style="' . htmlspecialchars($decl, ENT_QUOTES) . '">x</div>', 'D3');
        }
    }

    /** §1.3's entity-split mangling: a quoted value written with &quot; round-trips intact. */
    public function testTheEntitySplitCaseRoundTrips(): void
    {
        $html = pp_content_sanitize('<p style="font-family:&quot;X Y&quot;, serif">x</p>', 'rich')['html'];
        $this->assertStringContainsString('font-family:&quot;X Y&quot;, serif', $html);
    }

    // ── E8 / P-23 ─────────────────────────────────────────────────────────────

    public function testE8UnknownRefusedAndHyphenatedCustomAdmittedAndNoted(): void
    {
        foreach (['<blink>x</blink>', '<marquee>x</marquee>', '<foo>x</foo>', '<font-face>x</font-face>',
            '<missing-glyph>x</missing-glyph>', '<color-profile>x</color-profile>'] as $shape) {
            $this->assertRefused($shape, 'E8');
        }
        $r = pp_content_sanitize('<my-card class="c" data-x="1" aria-label="c">x</my-card><button is="fancy-button">b</button>', 'rich');
        $this->assertSame([], $r['losses']);
        $this->assertContains('custom element <my-card>', $r['notes']);
        $this->assertRefused('<button is="button">b</button>', 'P-17');
        $this->assertRefused('<my-card onclick="x">x</my-card>', 'E1');
        $this->assertRefused('<my-card src="https://x">x</my-card>', 'P-17', 'rich');
    }

    // ── E9 ────────────────────────────────────────────────────────────────────

    public function testE9RedirectorsOnEveryElement(): void
    {
        foreach (['formaction="/x"', 'formtarget="_blank"', 'formmethod="post"', 'formenctype="text/plain"',
            'ping="https://x"', 'http-equiv="refresh"', 'form="other"'] as $attr) {
            foreach (['button', 'input', 'a', 'div'] as $tag) {
                $this->assertRefused("<{$tag} {$attr}>x</{$tag}>", 'E9');
            }
        }
    }

    // ── E10 per sink wrapper ──────────────────────────────────────────────────

    /**
     * E10 is enforced by OVERLAPPING layers, and two planted-defect proofs stay green by
     * design (accepted by the orchestrator, 2026-10-05):
     *
     *   1. The end-marker check (the container may only close after the marker). Every
     *      shape it catches today is also caught by another layer: an early close by the
     *      tail check, an unclosed comment by the lexical unfinished-token check (P-16).
     *      Removing it alone turns nothing red.
     *   2. The form sentinel's breadcrumb check. No input reaches a state where the
     *      sentinel <form> is built but not a child of <main> on the WordPress 7.0 tree
     *      builder; the check guards a future parser that relocates it.
     *   3-6. Four sub-checks of the tail and <p> sentinel, each redundant with the others
     *      (cycle-7 mutation review): the sentinel <p>'s breadcrumbs, the sentinel's text,
     *      the ORDER of the wrapper's tail closers, and "every tail closer seen" at the
     *      sentinel. Any input that defeats one of them moves a wrapper element, which the
     *      remaining ones (and the form sentinel) see; removing any ONE alone turns nothing
     *      red. They are kept because each is the exact statement of "the template's next
     *      band renders where the template put it", and a future parser may separate them.
     *   7-9. Since the walk ends at the first E10 (cycle 8): the container closer's depth
     *      test, and continuing an early close or a tail escape as the tail phase, are
     *      equivalent to returning (the refusal is already recorded; nothing after it is
     *      read).
     *
     * Removing the tail check, the <p> sentinel AND the form sentinel together turns this
     * test red; so does removing the form sentinel alone (the unclosed-form test below).
     */
    public function testE10ContainmentPerSink(): void
    {
        // An unclosed comment swallows the rest of the page in the browser; here it would
        // also swallow the end marker, which is how it is caught.
        $rich = ['x</div>', 'x</div></section><p>outside</p>', 'x</section>', '<textarea>swallow', 'x</main>',
            '<!--x', 'x<!--c', 'x<xmp>', '<select><option>x', '<table><tr><td>x'];
        foreach ($rich as $shape) {
            $this->assertNotSame([], array_intersect(['E10', 'P-16'], $this->clauses($shape)), "rich: {$shape}");
        }
        foreach (['</td><td>x', 'x</tr><tr><td>y', 'x</table>', '<td>y', '<tr><td>z'] as $shape) {
            $this->assertNotSame([], array_intersect(['E10', 'P-16'], $this->clauses($shape, 'rich_cell')), "cell: {$shape}");
        }
        foreach (['x</p><p>y', '<div>x</div>'] as $shape) {
            $this->assertNotSame([], array_intersect(['E10', 'P-2'], $this->clauses($shape, 'inline')), "inline: {$shape}");
        }
        $this->assertAdmitted('<p>a<p>b', 'rich');
        $this->assertAdmitted('<p>a</p><!-- a closed comment --><div>x', 'rich');
        $this->assertAdmitted('<ul><li>a<li>b</ul>', 'rich');
        $this->assertAdmitted('<a href="/x"><div>block link</div></a>', 'rich');
        $this->assertAdmitted('<strong>a</strong> <em>b</em>', 'rich_cell');
        // A whole-prop loss renders nothing (§2.3).
        $this->assertSame('', pp_content_sanitize('x</div><p>o</p>', 'rich')['html']);
    }

    // ── E11 ───────────────────────────────────────────────────────────────────

    /**
     * E11 on the real clobber surface only (rulings of 2026-10-05, cycles 4 and 6), red-proofed
     * both ways. Refused: a document built-in as the NAME of an element document named access
     * exposes (embed/form/iframe/img/object), and a form control's name or id while the form
     * element pointer is set. Admitted: every ordinary id, even one that spells a document
     * built-in or a window property, since an id shadows 0 of 997 window names and reaches
     * document named access only through elements other rules refuse.
     */
    public function testE11RefusesTheRealClobberSurfaceOnly(): void
    {
        $table = pp_content_clobber_table();
        foreach (['getElementById', 'querySelector', 'cookie', 'forms', 'images', 'body', 'title'] as $n) {
            $this->assertArrayHasKey($n, $table['document'], $n);
            $this->assertRefused("<img alt=\"\" src=\"/a.png\" name=\"{$n}\">", 'E11');
            $this->assertAdmitted("<h2 id=\"{$n}\">x</h2><a href=\"#{$n}\">back</a>");
        }
        $this->assertAdmitted('<svg aria-labelledby="title"><title id="title">Logo</title></svg>');
        foreach (['top', 'status', 'name', 'history', 'location', 'navigation', 'opener', 'pricing-table'] as $n) {
            $this->assertAdmitted("<div id=\"{$n}\">x</div><a href=\"#{$n}\">back</a>");
        }
        // Without a form there is no form-control named access: a button's id is any id.
        $this->assertAdmitted('<button id="submit">b</button><button id="action">c</button>');
        $this->assertAdmitted('<a href="/x" name="top">x</a>', 'rich', [], 'an <a name> is not document named access');
        // `name` is judged only on the five elements document named access exposes: a
        // document built-in as an <a name> is admitted.
        $this->assertAdmitted('<a href="/x" name="title">x</a>', 'rich', [], 'an <a name="title"> is not document named access');
        // An id on the P-11 PDF <object> IS document named access (cycle-7 ruling).
        $pdf = static fn (string $id) => '<object type="application/pdf" data="/wp-content/uploads/a.pdf" id="' . $id . '"></object>';
        $this->assertRefused($pdf('cookie'), 'E11');
        $this->assertRefused($pdf('getElementById'), 'E11');
        $this->assertAdmitted($pdf('annual-report'));
        $this->assertAdmitted('<img alt="" src="/a.png" id="action">');
    }

    /**
     * Δ5, DESCOPED BY THE OWNER 2026-10-05: a form and its controls are refused (clause D5)
     * until the Layer-3 forms contract admits them. Every shape the forms arm once judged
     * (credential and admin-endpoint actions, implicitly closed and pointer-owned forms,
     * radio groups across bands) is now refused whole by this one rule. `button`, `label`,
     * `meter` and `progress` stay admitted: none owns form data without a form.
     */
    public function testFormsAreDescoped(): void
    {
        foreach (['<form action="/s"></form>', '<form method="dialog"><button>x</button></form>',
            '<form action="https://evil.example/x" method="post"><input type="password" name="pw"></form>',
            '<form action="/wp-login.php"><input type="hidden" name="log" value="a"></form>',
            '<div><form action="/s"></div><input name="action">', '<form action="/s">', '<input type="text" name="q">',
            '<input type="radio" name="plan" value="a">', '<select name="s"><option>a</option></select>',
            '<textarea name="t">x</textarea>', '<output name="o"></output>', '<fieldset><legend>L</legend></fieldset>',
            '<datalist id="d"><option value="a"></option></datalist>', '<input type="image" src="/a.png" name="i">'] as $shape) {
            $this->assertRefused($shape, 'D5');
            $this->assertStringContainsString('descoped by the owner', implode(' ', array_column($this->losses($shape), 'message')), $shape);
        }
        $this->assertAdmitted('<button popovertarget="p">o</button><div id="p" popover>x</div>'
            . '<label for="m">M</label><meter id="m" value="0.5"></meter><progress value="1" max="2"></progress>');
        // Every write tier refuses them: D5 is judged before the trust tier.
        $this->assertContains('D5', array_column(pp_content_sanitize('<form></form>', 'rich', ['tier' => 'core'])['losses'], 'clause'));
    }

    /**
     * E12: ids and single-id references may not contain whitespace (HTML forbids it in ids).
     * Every reference shape has its TARGET present, so only the whitespace rule can refuse it
     * (an unresolved reference would be E12 too), and the message is asserted.
     */
    public function testE12RefusesWhitespaceInIdsAndSingleReferences(): void
    {
        foreach (['<div id=" x ">a</div>', '<div id="a b">a</div>', '<svg><g id="a b"></g></svg>', "<div id=\"a\fb\">a</div>"] as $shape) {
            $this->assertStringContainsString('an id may not contain whitespace', implode(' | ', array_column($this->losses($shape), 'message')), $shape);
        }
        $target = '<div id="x" popover>p</div><meter id="x2" value="1"></meter><dialog id="x4">d</dialog>';
        foreach (['<button popovertarget=" x">b</button>', "<button popovertarget=\"x\f\">b</button>",
            '<button commandfor="x4 " command="show-modal">o</button>', '<label for=" x2">E</label>',
            '<div role="listbox" aria-activedescendant=" x">l</div>',
            '<div role="textbox" aria-invalid="true" aria-errormessage="x ">t</div>'] as $ref) {
            $this->assertStringContainsString('a reference to one id may not contain whitespace',
                implode(' | ', array_column($this->losses($ref . $target), 'message')), $ref);
            // The same reference without the whitespace resolves and is admitted.
            $this->assertAdmitted(preg_replace('/="\s*(x\d?)\s*"/', '="$1"', $ref) . $target);
        }
        $this->assertAdmitted('<p aria-describedby=" d1  d2 ">x</p><span id="d1">a</span><span id="d2">b</span>',
            'rich', [], 'an id-reference LIST is whitespace-separated by definition');
        // `list` (an input's) stays a single-id reference in the table for the forms contract.
        $this->assertSame('one', pp_content_idref_attributes()['list']);
    }

    // ── E12 ───────────────────────────────────────────────────────────────────

    public function testE12ReferencesResolveInBand(): void
    {
        $in = [
            '<button popovertarget="m">o</button><div popover id="m">m</div>',
            '<button commandfor="d" command="show-modal">o</button><dialog id="d">d</dialog>',
            '<label for="e">E</label><meter id="e" value="1"></meter>',
            '<img alt="" src="/m.png" usemap="#m"><map name="m"><area alt="" href="/x" shape="rect" coords="0,0,1,1"></map>',
            '<p aria-describedby="d1 d2" aria-labelledby="d1" aria-controls="d2" aria-owns="d1">x</p><span id="d1">a</span><span id="d2">b</span>',
            '<table><tr><th id="h">H</th></tr><tr><td headers="h">c</td></tr></table>',
            '<details name="faq"><summary>a</summary>b</details><details name="faq"><summary>c</summary>d</details>',
        ];
        foreach ($in as $shape) {
            $this->assertAdmitted($shape);
        }
        $out = [
            '<button popovertarget="pp-nav-menu">o</button>', '<button commandfor="elsewhere" command="close">x</button>',
            '<label for="pp-ai-input">x</label>', '<img alt="" src="/m.png" usemap="#nomap">',
            '<p aria-describedby="here gone">x</p><span id="here">a</span>', '<table><tr><td headers="gone">c</td></tr></table>',
        ];
        foreach ($out as $shape) {
            $this->assertRefused($shape, 'E12');
        }
        // Another band's content id is out of band; the band's own anchor and ids are in.
        $this->assertAdmitted('<a aria-controls="mine" href="#x">x</a>', 'rich', ['band_ids' => ['mine']]);
        $this->assertRefused('<details name="g"><summary>a</summary></details>', 'E12', 'rich', ['other_details_names' => ['g']]);
    }

    // ── P-16 ──────────────────────────────────────────────────────────────────

    public function testP16UnsupportedMarkupNamesTheElementToCloseFirst(): void
    {
        $cases = [
            '<b><p>x</b>y</p>'            => 'close <p> before </b>',
            '<p><i>a<b>b</i>c</b></p>'    => 'close <b> before </i>',
            '<p><em>a</p><p>b</em></p>'   => 'close <em> before </p>',
            '<p><a href="#">x</p>'        => 'close <a> before </p>',
            '<p><b>Note</p><p>rest</p>'   => 'close <b> before </p>',
            '<b>x'                         => 'close <b>',
        ];
        foreach ($cases as $shape => $hint) {
            $losses = $this->losses($shape);
            $this->assertSame('P-16', $losses[0]['clause'] ?? null, $shape);
            $this->assertStringContainsString('unsupported markup', $losses[0]['message']);
            $this->assertStringContainsString($hint, $losses[0]['message'], $shape);
        }
        $this->assertSame('P-16', $this->losses('<table><tr><td>a</td></tr>x</table>')[0]['clause']);
    }

    // ── base (P-17) and the four argued attributes ────────────────────────────

    public function testP17BaseAndTheFourArguedAttributes(): void
    {
        $this->assertAdmitted('<div tabindex="0" translate="no" inert itemscope itemprop="x" accesskey="k" draggable="true" spellcheck="false" autocapitalize="off" enterkeyhint="go" inputmode="text" aria-pressed="true" aria-level="2" aria-invalid="false" aria-modal="true" role="region" popover="auto" slot="s">x</div><bdi>b</bdi><canvas width="1" height="1"></canvas>');
        $this->assertAdmitted('<button autofocus>a</button><div contenteditable="true">e</div>');
        $this->assertRefused('<p nonce="n">x</p>', 'P-17');
        $this->assertAdmitted('<button is="x-button">b</button>');
        $this->assertRefused('<p aria-description="ARIA 1.3, not 1.2">x</p>', 'P-17');
        $this->assertRefused('<p frobnicate="1">x</p>', 'P-17');
    }

    // ── INLINE / heading (P-2 B+) ─────────────────────────────────────────────

    public function testInlineAndHeadingAdmitExactlyTheRuledSet(): void
    {
        // Titles and headings take the INLINE set without `a` (the Sprint-6 titles ruling:
        // a template may render a title inside a link, and a link in a link is unparseable).
        $this->assertAdmitted('<a href="/x" title="t">a</a> <strong>s</strong>', 'inline');
        $this->assertRefused('<a href="/x">a</a>', 'P-2', 'heading');
        $this->assertStringContainsString('(no links)', implode(' ', array_column($this->losses('<a href="/x">a</a>', 'heading'), 'message')));
        foreach (['inline', 'heading'] as $sink) {
            $this->assertAdmitted('<strong>s</strong> <em>e</em><br><span class="c" style="color:red">sp</span> <sup>1</sup><sub>2</sub><small>s</small><mark>m</mark><code>c</code>', $sink);
            foreach (['<b>b</b>', '<i>i</i>', '<img src="/a.png" alt="">', '<a href="/x" target="_blank">a</a>',
                '<span id="x">s</span>', '<code class="x">c</code>', '<span data-x="1">s</span>', '<svg></svg>',
                '<div>d</div>', '<details>d</details>'] as $shape) {
                $this->assertRefused($shape, 'P-2', $sink);
            }
            $this->assertRefused('<a href="javascript:x">a</a>', 'E2', $sink);
            $this->assertRefused('<span style="background:url(https://x)">s</span>', 'D3', $sink);
            $this->assertAdmitted('Fish &amp; chips, 1 < 2, A > B', $sink);
        }
    }

    // ── Δ4 and the render view (for T3b) ──────────────────────────────────────

    public function testDelta4AddsNoopenerInTheRenderViewOnly(): void
    {
        $html = pp_content_sanitize('<a href="/x" target="_blank" rel="nofollow">a</a><a href="/y" target="named">b</a><a href="/z" target="_self">c</a>', 'rich')['html'];
        $this->assertStringContainsString('rel="nofollow noopener"', $html);
        $this->assertStringContainsString('target="named" rel="noopener"', $html);
        $this->assertStringContainsString('target="_self">c', $html);
    }

    /** The render view of every admitted fixture is a fixed point: re-judging it finds nothing and changes nothing. */
    public function testTheRenderViewIsIdempotentOverAdmittedContent(): void
    {
        foreach (array_merge(ContentWriteGateTest::admittedBodies(), [
            ['<p>a<p>b<ul><li>c<li>d</ul><svg viewbox="0 0 1 1"><path d="M0 0"/></svg><math><mi>x</mi></math>'],
            ['<p style="font-family:&quot;X&quot;">Q&amp;A &lt;tag&gt; &quot;q&quot;</p>'],
        ]) as [$body]) {
            $first = pp_content_sanitize($body, 'rich');
            $this->assertSame([], $first['losses'], $body);
            $second = pp_content_sanitize($first['html'], 'rich');
            $this->assertSame([], $second['losses'], "render view re-judged: {$first['html']}");
            $this->assertSame($first['html'], $second['html'], 'fixed point');
        }
    }

    // ── review fixes (#1242 T3a /review cycle 1) ──────────────────────────────

    /**
     * The title blind spot: the lexical tokenizer reads every <title> as RCDATA, the tree
     * builder drops an in-body <body>/<html> inside an SVG title, and a browser merges its
     * attributes onto the real element. Neither view reports the token, so a tag-open in
     * any title's text is refused.
     */
    public function testMarkupInsideATitleIsRefusedEvenWhenNeitherViewSeesATag(): void
    {
        $this->assertRefused('<svg><title><body onload="alert(1)"></title></svg>', 'D1');
        $this->assertRefused('<svg><title><html onclick="alert(1)"></title></svg>', 'D1');
        $this->assertAdmitted('<svg><title>A &lt; B, fish &amp; chips</title></svg>');
    }

    /** The symmetric union rule: a start tag the tree builder drops was never fully judged. */
    public function testAStartTagTheTreeBuilderDropsIsUnsupportedMarkup(): void
    {
        // Inside <select> the refusal follows the RUNTIME parser (ruling on T3a 7A question
        // 3): WordPress 7.0's tree builder drops these (P-16), 7.1's builds them and the
        // element's own rule refuses them. Either way they are refused; the per-version
        // truth is pinned in ContentTableDriftTest. Run this file with PP_TEST_HTML_API=7.1.2
        // to see the other side.
        foreach ([
            ['<select style="appearance:base-select"><svg><a><animate attributeName="href" values="javascript:alert(1)"/><text>c</text></a></svg></select>', 'E5'],
            ['<select><img name="getElementById" id="pp-0a1b2c3d" alt=""></select>', 'E6'],
            ['<select><div id="pp-12345678">x</div></select>', 'E6'],
            ['<div><td>stray cell</td></div>', 'P-16'],
            ['<form action="/a"><form action="/b">x</form></form>', 'P-16'],
        ] as [$shape, $own]) {
            $clauses = $this->clauses($shape);
            $this->assertNotSame([], array_intersect(['P-16', $own], $clauses), "{$shape}: " . implode(',', $clauses));
        }
    }

    /** P-11: the uploads path may not be climbed out of in any spelling a browser resolves. */
    public function testP11PdfPathsMayNotClimbOutOfUploads(): void
    {
        foreach (['https://example.com/wp-content/uploads/%2e%2e/%2e%2e/x.pdf',
            'https://example.com/wp-content/uploads/%252e%252e/x.pdf',
            'https://example.com/wp-content/uploads/a/..\\..\\x.pdf',
            "https://example.com/wp-content/uploads/.\t./x.pdf", 'https://example.com/wp-content/uploads/a:1/../../x.pdf',
            'wp-content/uploads/a.pdf'] as $url) {
            $this->assertFalse(pp_content_is_same_install_pdf($url), $url);
        }
        // Read by the shared canonicaliser: a `.` segment that stays inside uploads resolves
        // to the same file a browser fetches.
        $this->assertTrue(pp_content_is_same_install_pdf('https://example.com/wp-content/uploads/./x.pdf'));
        // The page's own scheme with one slash is a root-relative path (https site).
        $this->assertTrue(pp_content_is_same_install_pdf('https:/wp-content/uploads/a.pdf'));
        $this->assertTrue(pp_content_is_same_install_pdf('https://example.com/wp-content/uploads/2026/10/a.pdf'));
        // A network-path reference takes the PAGE's scheme (https here): the uploads' own.
        $this->assertTrue(pp_content_is_same_install_pdf('//example.com/wp-content/uploads/a.pdf'));
    }

    /** E12 for every ARIA id-reference list, and a usemap that is not a #reference. */
    public function testE12CoversEveryIdReferenceAttribute(): void
    {
        foreach (['aria-details', 'aria-owns', 'aria-flowto', 'aria-activedescendant', 'aria-errormessage'] as $attr) {
            $this->assertRefused("<p {$attr}=\"gone\">x</p>", 'E12');
            $this->assertAdmitted("<p {$attr}=\"here\">x</p><span id=\"here\">a</span>");
        }
        $this->assertRefused('<img alt="" src="/m.png" usemap="m"><map name="m"></map>', 'E12');
    }

    public function testDelta4AddsNoopenerOnSvgLinksToo(): void
    {
        $html = pp_content_sanitize('<svg><a href="/x" target="_blank"><text>t</text></a><use xlink:href="#i"/></svg>', 'rich')['html'];
        $this->assertStringContainsString('target="_blank" rel="noopener"', $html);
        $this->assertStringContainsString('xlink:href="#i"', $html, 'the render view keeps the qualified attribute name');
    }

    public function testARefusalNeverQuotesTheGatesOwnEndMarker(): void
    {
        $message = implode(' ', array_column($this->losses('a<b', 'heading'), 'message'));
        $this->assertStringNotContainsString('pp-end-', $message);
    }

    /** M-2's structural key sees raw-text bodies and the self-closing flag (§2.2). */
    public function testTheUnchangedComparisonSeesRawTextAndSelfClosing(): void
    {
        $stored = [['component' => 'section', 'props' => ['body' => '<svg><title>a</title></svg>']]];
        $this->assertFalse($this->unchanged(
            ['component' => 'section', 'props' => ['body' => '<svg><title><img src=x onerror=1></title></svg>']], $stored));
        $stored = [['component' => 'section', 'props' => ['body' => '<svg><g/><circle r="1"/></svg>']]];
        $this->assertFalse($this->unchanged(
            ['component' => 'section', 'props' => ['body' => '<svg><g><circle r="1"></svg>']], $stored));
        $stored = [['component' => 'section', 'props' => ['body' => "<p class='a'>x &amp; y</p>"]]];
        $this->assertTrue($this->unchanged(
            ['component' => 'section', 'props' => ['body' => '<p class="a">x &#38; y</p>']], $stored));
        $this->assertFalse($this->unchanged(
            ['component' => 'cta', 'props' => ['body' => "<p class='a'>x &amp; y</p>"]], $stored), 'same component only');
    }

    /**
     * M-8 nesting cap (cycles 6 and 7): read from the parser's own stack depth in the walk.
     * 256 deep is admitted; 257 is refused whole and named; the walk stops at the cap, so a
     * pathological depth never reaches the region where the tree builder's cost is quadratic.
     */
    public function testM8NestingDepthIsCapped(): void
    {
        $this->assertAdmitted(str_repeat('<div>', PP_CONTENT_MAX_DEPTH) . 'x' . str_repeat('</div>', PP_CONTENT_MAX_DEPTH));
        $losses = $this->losses(str_repeat('<b>', PP_CONTENT_MAX_DEPTH + 1) . 'x' . str_repeat('</b>', PP_CONTENT_MAX_DEPTH + 1));
        $this->assertSame(['M-8'], array_column($losses, 'clause'));
        $this->assertStringContainsString('at least 257 deep', $losses[0]['message']);
        $this->assertStringContainsString('at most 256 deep', $losses[0]['message']);
        // Ordinary implied closes do not accumulate depth, and neither do voids or
        // self-closed foreign elements (which the parser does close).
        $this->assertAdmitted(str_repeat('<p>para', 600) . '<ul>' . str_repeat('<li>item', 600) . '</ul>');
        $this->assertAdmitted(str_repeat('<br>', 300) . '<p>' . str_repeat('<img alt="" src="/a.png">', 300) . '</p>');
        $this->assertAdmitted('<svg>' . str_repeat('<path d="M0 0"/>', 300) . '</svg>');
        if (!extension_loaded('xdebug') && !extension_loaded('pcov')) {
            $start = microtime(true);
            $this->assertSame(['M-8'], $this->clauses(str_repeat('<b>', 9285) . 'x' . str_repeat('</b>', 9285)));
            $this->assertLessThan(0.5, microtime(true) - $start, 'the walk stops at the cap');
        }
        // What the parser keeps open counts, whatever its spelling: a self-closed HTML
        // element, and a self-closed CUSTOM element (an HTML element too).
        $this->assertSame(['M-8'], $this->clauses(str_repeat('<b/>', 300) . 'x'));
        $this->assertSame(['M-8'], $this->clauses(str_repeat('<a-/>', PP_CONTENT_MAX_DEPTH + 1) . 'x'));
        $this->assertAdmitted(str_repeat('<a-/>', PP_CONTENT_MAX_DEPTH) . 'x');
        // What the parser ignores does not reduce it: a </span> behind a <div>, a </p>
        // behind a table cell.
        $this->assertSame(['M-8'], $this->clauses(str_repeat('<span><div></span>', 200) . 'x' . str_repeat('</div></span>', 200)));
        $this->assertSame(['M-8'], $this->clauses(str_repeat('<p><table><tr><td><b><i></p>', 60) . 'x'
            . str_repeat('</i></b></td></tr></table>', 60)));
    }

    /**
     * The union rule counts only tree elements that have a source token: an element the tree
     * builder creates itself (an implied <tbody>) cannot stand in for an authored start tag
     * it dropped.
     */
    public function testAnImpliedElementNeverMasksADroppedAuthoredOne(): void
    {
        $this->assertRefused('<table><tr><td>x</td></tr></table><tbody>', 'P-16');
        $this->assertAdmitted('<table><tbody><tr><td>x</td></tr></tbody></table>');
        $this->assertAdmitted('<table><tr><td>x</td></tr></table>');
        // A start tag's identity is its name AND its attributes, so a real tree element can
        // never stand in for a dropped authored one that carries other attributes.
        $key = static function (string $html): string {
            $p = new WP_HTML_Tag_Processor($html);
            $p->next_tag();
            return _pp_content_start_key($p, strtolower((string) $p->get_tag()));
        };
        $this->assertNotSame($key('<b>'), $key('<b class="x">'));
        $this->assertNotSame($key('<b class="x">'), $key('<b class="y">'));
        $this->assertSame($key('<b class="x" id="i">'), $key('<b id="i" class="x">'));
    }

    /** The P-16 hint models a self-closed custom element as open (it is an HTML element). */
    public function testTheCloseFirstHintKeepsASelfClosedCustomElementOpen(): void
    {
        $this->assertSame('close <b> before </x-a>', pp_content_close_first_hint('<x-a/><b></x-a>'));
    }

    // ── review fixes (#1242 T3a /review cycle 2) ──────────────────────────────

    /**
     * A tokenizer that stops on an unfinished token judged nothing after it: a self-closed
     * <title/> (read as an open RCDATA title) or a prop ending inside a tag or comment.
     */
    public function testAnUnfinishedTokenBlindsNothing(): void
    {
        foreach (['<svg><title/></svg><body onload="x">', '<svg><title/></svg><select><option><img src=x onerror=alert(1)></select>',
            '<svg><title><body onload="x"></svg>', '<p>Hello</p><a href="https://evil.example/"', '<p>x</p><!--'] as $shape) {
            $this->assertNotSame([], array_intersect(['P-16', 'E10', 'D1'], $this->clauses($shape)), $shape);
        }
        // Judged on the tokenizer's own title boundaries: `</titlex>` does not end a title.
        $this->assertRefused('<svg><title>a</titlex><body onload="x"></title></svg>', 'D1');
        $this->assertRefused('<svg><title></title-x><html onclick="x"></title></svg><p>x</p>', 'D1');
        // The accepted cost of judging decoded text: an escaped "<" + letter in a title is refused too.
        $this->assertRefused('<svg><title>A &lt;b&gt; c</title></svg>', 'D1');
        $this->assertAdmitted('<svg><title>A &lt; b, 3 &gt; 2</title></svg><title-card class="t">x</title-card>', 'rich');
    }

    /** The unchanged comparison matches an unfinished or raw-text prop only byte for byte. */
    public function testUnchangedNeverMatchesAnAppendedUnfinishedTail(): void
    {
        $stored = [['component' => 'section', 'props' => ['body' => '<p>Hello</p>']]];
        foreach (['<p>Hello</p><img src=x onerror=alert(1) ', '<p>Hello</p><a href="https://evil.example/"', '<p>Hello</p><!--'] as $tail) {
            $this->assertFalse($this->unchanged(['component' => 'section', 'props' => ['body' => $tail]], $stored), $tail);
        }
        $stored = [['component' => 'section', 'props' => ['body' => '<svg><title>&lt;img src=x onerror=1&gt;</title></svg>']]];
        $this->assertFalse($this->unchanged(
            ['component' => 'section', 'props' => ['body' => '<svg><title><img src=x onerror=1></title></svg>']], $stored));
        $this->assertTrue($this->unchanged(
            ['component' => 'section', 'props' => ['body' => '<svg><title>&lt;img src=x onerror=1&gt;</title></svg>']], $stored));
    }

    public function testTheCloseFirstHintTreatsASelfClosedHtmlElementAsOpen(): void
    {
        $this->assertStringContainsString('close <i>', pp_content_close_first_hint('<p><i class="icon"/>x</p>'));
        $this->assertStringNotContainsString('close <path>', pp_content_close_first_hint('<svg><path d="M0"/></svg><b>x'));
    }

    public function testP11ComparesTheHostCaseInsensitively(): void
    {
        $this->assertTrue(pp_content_is_same_install_pdf('https://EXAMPLE.com/wp-content/uploads/a.pdf'));
    }

    /**
     * P-11 compares the canonical ORIGIN, scheme + host + port (owner rulings, 2026-10-05):
     * another port, http against an https base (mixed content), or a host spelled in
     * characters a browser would map (full-width, an ideographic full stop, a soft hyphen, a
     * trailing dot) is another origin and refused: inequality is refusal. A root-relative
     * path resolves against the SITE's origin, so it is this install's only when the uploads
     * share it.
     */
    public function testP11ComparesTheCanonicalOriginSchemeHostAndPort(): void
    {
        foreach (['https://example.com:8080/wp-content/uploads/a.pdf', 'http://example.com/wp-content/uploads/a.pdf',
            'http://example.com:443/wp-content/uploads/a.pdf', 'https://example.com:80/wp-content/uploads/a.pdf',
            'https://ｅxample.com/wp-content/uploads/a.pdf', 'https://example。com/wp-content/uploads/a.pdf',
            "https://exa\u{00AD}mple.com/wp-content/uploads/a.pdf", 'https://example.com./wp-content/uploads/a.pdf',
            'https://example.com../wp-content/uploads/a.pdf', 'https://EXAMPLE.COM.evil/wp-content/uploads/a.pdf'] as $url) {
            $this->assertFalse(pp_content_is_same_install_pdf($url), $url);
        }
        $this->assertTrue(pp_content_is_same_install_pdf('https://example.com:443/wp-content/uploads/a.pdf'), 'an explicit default port is the same origin');
        $this->assertTrue(pp_content_is_same_install_pdf('/wp-content/uploads/a.pdf'));
        foreach (['https://example.com:8443/wp-content/uploads', 'http://example.com/wp-content/uploads',
            'https://cdn.example.com/wp-content/uploads'] as $base) {
            $GLOBALS['_pp_test_upload_baseurl'] = $base;
            try {
                $this->assertFalse(pp_content_is_same_install_pdf('/wp-content/uploads/a.pdf'),
                    'a root-relative path is on the site origin, not ' . $base);
                $this->assertFalse(pp_content_is_same_install_pdf('https://example.com/wp-content/uploads/a.pdf'), $base);
                $this->assertTrue(pp_content_is_same_install_pdf($base . '/a.pdf'), $base);
            } finally {
                unset($GLOBALS['_pp_test_upload_baseurl']);
            }
        }
    }

    /** CSS functions are default-deny (I19): the url() family only by its own rules; anything unlisted is refused. */
    public function testCssFunctionsAreDefaultDeny(): void
    {
        foreach (['background:-moz-element(#secret)', 'background:element(#secret)', 'background: paint(x)',
            'width: attr(data-w px)', 'color: unknown-fn(1)', 'background: cross-fade(red, blue)'] as $decl) {
            $this->assertRefused('<div style="' . $decl . '">x</div><p id="secret">s</p>', 'D3');
        }
        $this->assertRefused('<svg><rect transform="frobnicate(1)"/></svg>', 'D1');
        foreach (['color: rgb(0 0 0 / .5)', 'width: calc(100% - var(--gap, 1rem))', 'transform: rotate(3deg) translateX(2px)',
            'background: linear-gradient(red, blue)', 'filter: drop-shadow(0 1px 2px #000)', 'clip-path: polygon(0 0, 1px 1px, 0 1px)',
            'grid-template-columns: repeat(3, minmax(0, 1fr))', 'transition: opacity .2s cubic-bezier(.2, 0, 0, 1)',
            'color: color-mix(in oklch, red 50%, blue)'] as $decl) {
            $this->assertAdmitted('<div style="' . $decl . '">x</div>');
        }
        $this->assertAdmitted('<svg><rect transform="rotate(45 10 10) translate(1 2)" fill="rgb(0,0,0)"/></svg>');
        // Text is not a call (owner ruling, 2026-10-05): the check runs on CSS-valued SVG
        // attributes and style declarations only, and skips quoted strings.
        foreach (['<svg aria-label="Revenue (2024)" role="img"><title>Sales (Q1)</title></svg>',
            '<svg><text font-family="Foo (Pro)" aria-roledescription="a (b)">t</text></svg>',
            '<p style="font-family: &quot;Foo (Pro)&quot;, serif">x</p>', "<p style=\"font-family: 'Bar (Bold)'\">x</p>"] as $text) {
            $this->assertAdmitted($text);
        }
        // ... while a real call beside a quoted string is still judged.
        $this->assertRefused('<div style="font-family: &quot;a (b)&quot;; background: -moz-element(#s)">x</div><p id="s">s</p>', 'D3');
        $this->assertRefused('<svg><rect fill="paint(x)"/></svg>', 'D1');
        $this->assertRefused('<svg><rect stroke-width="unknown-fn(1)"/></svg>', 'D1');
    }

    // ── review fixes (#1242 T3a /review cycle 3) ──────────────────────────────

    /** In foreign content a CDATA section is not a comment: such a prop matches only byte for byte. */
    public function testUnchangedNeverEquatesACommentWithCdataOrABogusComment(): void
    {
        $stored = [['component' => 'section', 'props' => ['body' => '<svg><!--[CDATA[--><p title="]]&gt;x"></p></svg>']]];
        $this->assertFalse($this->unchanged(
            ['component' => 'section', 'props' => ['body' => '<svg><![CDATA[><p title="]]>x"></p></svg>']], $stored));
        $stored = [['component' => 'section', 'props' => ['body' => '<p><!--a--></p>']]];
        $this->assertFalse($this->unchanged(['component' => 'section', 'props' => ['body' => '<p><?a></p>']], $stored));
        $this->assertTrue($this->unchanged(['component' => 'section', 'props' => ['body' => '<p><!--a--></p>']], $stored),
            'an ordinary comment still compares structurally');
    }

    /** E12: an id another band also carries binds to whichever comes first, so it is not "in band". */
    public function testE12RefusesATargetIdThatAnotherBandAlsoCarries(): void
    {
        $this->assertRefused('<label for="email">E</label><meter id="email" value="1"></meter>', 'E12', 'rich',
            ['other_ids' => ['email']]);
        $this->assertAdmitted('<label for="email">E</label><meter id="email" value="1"></meter>', 'rich',
            ['other_ids' => ['phone']]);
    }

    /** The lexical/tree start-tag comparison keys on attributes, so an implied bare <tr> cannot cover a dropped one. */
    public function testAnImpliedElementCannotStandInForADroppedAuthoredOne(): void
    {
        $this->assertRefused('<table><td>a</td></table><select><tr data-x="1"></tr></select>', 'P-16');
        $this->assertAdmitted('<table><td>a</td></table>');
    }

    public function testTheCloseFirstHintCoversALinkInsideALink(): void
    {
        $this->assertStringContainsString('a link may not contain another link',
            pp_content_close_first_hint('<a href="/1">one <a href="/2">two</a></a>'));
    }

    // ── review fixes (#1242 T3a /review cycle 4) ──────────────────────────────

    /** Fail closed: a deploy missing a derived table refuses every prop rather than guessing. */
    public static function missingTables(): array
    {
        return ['clobber table' => ['dom-clobber-names.json'], 'core post table' => ['core-post-wp-7.0.json']];
    }

    /** @dataProvider missingTables */
    public function testAMissingTableFailsClosed(string $table): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/content/fail-closed-runner.php')
            . ' ' . escapeshellarg($table) . ' 2>&1';
        $result = json_decode((string) shell_exec($cmd), true);
        $this->assertIsArray($result);
        $this->assertSame('', $result['html']);
        $this->assertSame(['guard'], array_column($result['losses'], 'clause'));
    }

    /** E12 both ways: an id this band authors that another band already refers to is refused. */
    public function testE12RefusesAnIdAnotherBandRefersTo(): void
    {
        $this->assertRefused('<div popover id="x">captured</div>', 'E12', 'rich', ['other_refs' => ['x']]);
        $this->assertAdmitted('<div popover id="x">mine</div>', 'rich', ['other_refs' => ['y']]);
        // itemref and SVG/MathML ARIA references are id references too.
        $this->assertRefused('<div itemscope itemref="gone">x</div>', 'E12');
        $this->assertAdmitted('<div itemscope itemref="here">x</div><p id="here">y</p>');
        $this->assertRefused('<svg aria-labelledby="gone"><rect width="1" height="1"/></svg>', 'E12');
        $this->assertAdmitted('<svg aria-labelledby="t1"><title id="t1">Logo</title></svg>');
    }

    /**
     * A backslash is a path separator in a URL resolved against this page's http(s) base, so
     * `\\host\p` is classified as the protocol-relative reference `//host/p` is (P-4 option
     * A), never as something else.
     */
    public function testBackslashUrlsAreClassifiedAsTheBrowserResolvesThem(): void
    {
        foreach (['link', 'fetch', 'img-src'] as $context) {
            $this->assertSame(pp_content_url_loss('//evil.example/p', $context), pp_content_url_loss('\\\\evil.example\\p', $context));
            $this->assertSame(pp_content_url_loss('//evil.example/a.png', $context), pp_content_url_loss('/\\evil.example/a.png', $context));
        }
        $this->assertNotNull(pp_content_url_loss('javascript:alert(1)\\x', 'link'));
    }

    public function testP11AdmitsARootRelativeUploadsPath(): void
    {
        $this->assertTrue(pp_content_is_same_install_pdf('/wp-content/uploads/2026/10/a.pdf'));
        $this->assertFalse(pp_content_is_same_install_pdf('/wp-admin/a.pdf'));
        $this->assertFalse(pp_content_is_same_install_pdf('/wp-content/uploads/../../a.pdf'));
    }

    /**
     * Cross-band references stay linear in the page's ids: the page-wide counts are built
     * once per write. Timed as a RATIO (the same changed table beside 0 and beside 6,000
     * indexed stored ids), so a per-value rebuild of the id sets shows up whatever the rig.
     */
    /** @group timing */
    public function testReferenceResolutionIsLinearInThePagesIds(): void
    {
        if (extension_loaded('xdebug') || extension_loaded('pcov')) {
            $this->markTestSkipped('timing ratio is not meaningful under coverage instrumentation');
        }
        $stored = [];
        for ($b = 0; $b < 3; $b++) {
            $ids = '';
            for ($k = 0; $k < 2000; $k++) {
                $ids .= '<i id="b' . $b . 'a' . $k . '"></i>';
            }
            $this->assertLessThan(PP_CONTENT_PROP_MAX_BYTES, strlen($ids));
            $stored[] = ['component' => 'section', 'props' => ['title' => 'S' . $b, 'body' => $ids]];
        }
        $this->assertCount(6000, pp_content_index_counts(pp_content_composition_index($stored))['ids'],
            'the stored ids are indexed (under the prop cap)');
        $table = ['component' => 'table', 'props' => ['title' => 'T', 'headers' => ['A', 'B'],
            'rows' => array_fill(0, 1500, ['<a aria-describedby="c1" href="#x">x</a><span id="c1">y</span>', 'z'])]];
        // Best of three runs each, so a busy machine's one slow run is no ratio.
        $time = static function (array $items, array $baseline): float {
            $best = INF;
            for ($run = 0; $run < 3; $run++) {
                $start = microtime(true);
                pp_validate_composition($items, $baseline);
                $best = min($best, microtime(true) - $start);
            }
            return $best;
        };
        $alone = $time([$table], []);
        $beside = $time(array_merge($stored, [$table]), $stored);
        $this->assertLessThan(1.5, $beside / max($alone, 0.001), sprintf('%.2f s alone vs %.2f s beside 6,000 ids', $alone, $beside));
    }

    // ── cycle 8 ───────────────────────────────────────────────────────────────

    /**
     * The walk ends at the first E10: what follows an escape sits outside the container,
     * where no depth cap applies, so continuing would only spend the parser's quadratic cost
     * on a prop already refused whole.
     */
    public function testTheWalkEndsAtTheFirstContainerEscape(): void
    {
        foreach ([['</div>' . str_repeat('<i>', 9000) . 'x' . str_repeat('</i>', 9000), 'rich'],
            ['</div>' . str_repeat('<b>', 8000) . str_repeat('</q>', 10000), 'rich'],
            ['</td>' . str_repeat('<i>', 9000) . 'x', 'rich_cell'],
            ['</p>' . str_repeat('<em>', 9000) . 'x', 'inline']] as [$value, $sink]) {
            $start = microtime(true);
            $this->assertContains('E10', $this->clauses($value, $sink), $sink);
            if (!extension_loaded('xdebug') && !extension_loaded('pcov')) {
                $this->assertLessThan(0.5, microtime(true) - $start, 'refused at the escape, not after the nest');
            }
        }
        $this->assertSame(['E10'], $this->clauses('</div>' . str_repeat('<b>', 300) . 'x'),
            'no union-rule noise for the tags after the escape');
    }

    /**
     * Why there is no lexical early bound on depth: the opener count is not an upper bound.
     * The tree builder creates elements no opener names (a <td> implies <tbody> and <tr>),
     * so 240 openers nest about 480 deep. The walk's own depth is the only sound count.
     */
    public function testOpenerCountIsNoBoundOnDepth(): void
    {
        $value = str_repeat('<table><td>', 120) . 'x';
        $p = new WP_HTML_Tag_Processor($value);
        $openers = 0;
        while ($p->next_tag()) {
            $openers++;
        }
        $this->assertLessThan(PP_CONTENT_MAX_DEPTH, $openers);
        $this->assertSame(['M-8'], $this->clauses($value));
    }

    /** An element the parser implies counts toward depth like any other (a virtual <p>). */
    public function testAnImpliedElementCountsTowardDepth(): void
    {
        $this->assertSame(['M-8'], $this->clauses(str_repeat('<div>', PP_CONTENT_MAX_DEPTH) . '</p>'));
        $this->assertAdmitted(str_repeat('<div>', PP_CONTENT_MAX_DEPTH - 1) . '</p>');
    }

    /** A prop refused whole is not also reported for references its partial walk could not resolve. */
    public function testAWholePropRefusalIsNotPaddedWithPartialReferences(): void
    {
        // The reference comes BEFORE the cut, so the walk records it; the whole-prop M-8
        // refusal is then not padded with an E12 for it.
        $this->assertSame(['M-8'], $this->clauses('<p aria-labelledby="gone">x</p>' . str_repeat('<b>', 257) . 'x'));
    }

    /**
     * HTML whitespace, not PHP's: a vertical tab or NUL is part of a reference (the browser
     * matches it), and a form feed separates list entries.
     */
    public function testReferencesUseHtmlWhitespace(): void
    {
        $this->assertRefused("<button popovertarget=\"x\x0B\">b</button><div id=\"x\" popover>p</div>", 'E12');
        $this->assertRefused("<p aria-labelledby=\"x\x0B\">a</p><span id=\"x\">s</span>", 'E12');
        $this->assertAdmitted('<p aria-labelledby="&#12;x">a</p><span id="x">s</span>');
        $this->assertAdmitted('<p aria-labelledby="x&#12;y">a</p><span id="x">s</span><span id="y">t</span>');
        $this->assertSame(['a', 'b'], _pp_content_split_ws("\x0Ca \t b\n"));
        $this->assertSame(["a\x0B"], _pp_content_split_ws("a\x0B"));
    }

    /** An empty name joins no details group and names no map. */
    public function testEmptyNamesAreNoFacts(): void
    {
        $this->assertRefused('<img alt="" src="/a.png" usemap="#"><map name=""><area alt="" href="/x" shape="rect" coords="0,0,1,1"></map>', 'E12');
        $this->assertSame([], pp_content_composition_index([['component' => 'section',
            'props' => ['title' => 'T', 'body' => '<details name=""><summary>a</summary>x</details><p id="">y</p>']]])[0]['details']);
    }

    /** Document named access reaches an id only on <object>; an <object name> is refused (P-17 and E11). */
    public function testE11ReachesIdsOnlyOnObject(): void
    {
        $this->assertAdmitted('<img alt="" src="/a.png" id="cookie">');
        $this->assertAdmitted('<button id="cookie">b</button>');
        $this->assertContains('E11', $this->clauses('<object type="application/pdf" data="/wp-content/uploads/a.pdf" name="cookie"></object>'));
    }

    /** Small value gates, each pinned by the input that needs it. */
    public function testValueGateEdges(): void
    {
        $this->assertSame(['E12'], $this->clauses('<label for="gone">l</label>'));
        $this->assertSame(['D1', 'E2'], $this->sorted($this->clauses('<svg><use href="javascript:x"/></svg>')),
            'an SVG href is judged by the SVG branch too, not only as a URL');
        $this->assertRefused('<svg><a xlink:title="x"><text>t</text></a></svg>', 'D1');
        $this->assertRefused('<svg><path d="M0 0" fill="red /* x */"/></svg>', 'D1');
        $this->assertRefused('<svg xmlns:xlink="http://evil.example/ns"></svg>', 'D1');
        $this->assertNotNull(pp_content_srcset_loss('/a.png, javascript:x 2x'), 'a trailing comma ends the URL');
        $this->assertNull(pp_content_srcset_loss('/a.png 1x (x,javascript:y), /b.png 2x'),
            'a comma inside a descriptor\'s parentheses ends nothing (the spec\'s srcset parser)');
        $this->assertRefused('<div itemscope itemtype="https://schema.org/Thing javascript:x">t</div>', 'E2');
        $this->assertFalse(pp_content_is_same_install_pdf('/wp-content/uploads/evil#.pdf'));
        $GLOBALS['_pp_test_upload_baseurl'] = 'http:';
        try {
            $this->assertFalse(pp_content_is_same_install_pdf('/a.pdf'), 'a degenerate uploads base admits nothing');
        } finally {
            unset($GLOBALS['_pp_test_upload_baseurl']);
        }
        $this->assertAdmitted('<button is="My-X">b</button>', 'rich', [], 'is is judged lowercased, as the parser lowercases names');
    }

    /** The render view and the messages: Δ4 noopener, textarea text, reflected text, one Loss per construct. */
    public function testRenderViewAndMessageEdges(): void
    {
        $this->assertStringContainsString('rel="noopener"', pp_content_sanitize('<map name="m"><area alt="" href="/x" target="_blank" shape="rect" coords="0,0,1,1"></map>', 'rich')['html']);
        $losses = $this->losses("<p onclick=\"a\">x</p><span onmouseover=\"c\">y</span>");
        $this->assertCount(2, array_filter($losses, static fn ($l) => $l['clause'] === 'E1'), 'one Loss per construct, not per clause');
        // A reflected VALUE carries no control character into the message.
        $losses = $this->losses("<a href=\"bad\x01url\">x</a>");
        $this->assertNotSame([], $losses);
        foreach ($losses as $l) {
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $l['message']);
            $this->assertStringContainsString('bad', $l['message'], 'the value is reflected, cleaned');
        }
        $this->assertStringNotContainsString('close <p> before', pp_content_close_first_hint('<div><p>a<p>b</p></div>'),
            'a <p> closes an open <p> sibling by itself');
    }

    /** Item 16 (ruled): app schemes on a link's href only, `a` and `area`; every other URL attribute refuses them. */
    public function testAppSchemesOnlyOnALinksHref(): void
    {
        $this->assertAdmitted('<a href="sip:100">call</a><a href="whatsapp://send?text=x">w</a>');
        $this->assertAdmitted('<map name="m"><area alt="" href="geo:0,0" shape="rect" coords="0,0,1,1"></map>');
        $this->assertAdmitted('<svg><a href="facetime:x"><text>f</text></a></svg>', 'rich', [], 'an SVG <a> is a link');
        foreach (['<form action="sip:100"></form>', '<img src="geo:0,0" alt="">', '<blockquote cite="maps:x">q</blockquote>',
            '<video poster="signal:x"></video>', '<object type="application/pdf" data="sip:1"></object>'] as $shape) {
            $this->assertContains('E2', $this->clauses($shape), $shape);
        }
    }

    /**
     * THE URL CANONICALISER (cycle 9): one parse, browser semantics, fail closed, for E2,
     * srcset, P-11 and the trust tier. A table and a fuzz set pin the parse.
     */
    public function testTheUrlCanonicaliserFollowsTheBrowser(): void
    {
        // The parse itself (kind, scheme, host, port, resolved path); null = refused.
        foreach ([
            ['https://example.com/x', ['absolute', 'https', 'example.com', 443, '/x']],
            ['https:/x/./y/../z', ['relative', null, null, null, '/x/z']],
            ['http:/evil.example/x', ['absolute', 'http', 'evil.example', 80, '/x']],
            ['\\\\evil.example\\p', ['network', null, 'evil.example', 443, '/p']],
            [" \x01 //EVIL.example:8080/a/../b \x1F", ['network', null, 'evil.example', 8080, '/b']],
            ["ja\tva\nscript:x", ['opaque', 'javascript', null, null, 'x']],
            ['/a/%2E%2e/b', ['relative', null, null, null, '/b']],
            ['../../x', ['relative', null, null, null, '../../x']],
            ['#frag', ['relative', null, null, null, '']],
            ['', ['relative', null, null, null, '']],
            ['mailto:a@example.com', ['opaque', 'mailto', null, null, 'a@example.com']],
            ['https://user:pw@example.com:8443', ['absolute', 'https', 'example.com', 8443, '/']],
        ] as [$url, $want]) {
            $c = pp_content_url_parse($url);
            $this->assertNotNull($c, $url);
            $this->assertSame($want, [$c['kind'], $c['scheme'], $c['host'], $c['port'], $c['path']], $url);
        }
        foreach (["/a\x01b", 'http://exa mple.com/', 'https://example.com:99999/', 'http://[zz/', 'https://', '//',
            'file:///etc/passwd', 'http://a%41.example/', "http://example.com\x7F/"] as $bad) {
            $this->assertNull(pp_content_url_parse($bad), $bad);
            $this->assertNotNull(pp_content_url_loss($bad, 'link'), 'an unparseable URL is refused: ' . $bad);
        }
        // Fuzz: random URL-shaped strings never throw, and a URL is either parsed or refused.
        mt_srand(1242);
        $alphabet = ['h', 't', 'p', 's', ':', '/', '\\', '.', '%2e', '#', '?', '@', 'a', 'w', '-', "\t", "\x01", ' ', '..', '[', ']', '1'];
        for ($i = 0; $i < 400; $i++) {
            $u = '';
            for ($k = mt_rand(1, 14); $k > 0; $k--) {
                $u .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $c = pp_content_url_parse($u);
            $this->assertTrue($c === null || is_array($c), $u);
            if ($c === null) {
                $this->assertNotNull(pp_content_url_loss($u, 'link'), $u);
            }
        }
    }

    /** Remaining edges from the cycle-8 mutation review. */
    public function testCycleEightMutationEdges(): void
    {
        $this->assertRefused('<button popovertarget="">b</button>', 'E12');
        $this->assertAdmitted('<svg><a xlink:href="sip:100"><text>t</text></a></svg>', 'rich', [], 'an SVG <a> is a link');
        $html = pp_content_sanitize("<a href=\"/x\" target=\"_blank\" rel=\"nofollow\x0Bnoopener\">a</a>", 'rich')['html'];
        $this->assertStringContainsString(' noopener"', $html, 'a vertical tab does not separate rel tokens (HTML whitespace only)');
        $this->assertSame([], pp_content_composition_index([['component' => 'section',
            'props' => ['title' => 'T', 'body' => '<p id="">y</p>']]])[0]['ids'], 'an empty id is no fact');
    }

    /** The trust tier's remaining edges: a value other than 'full' is core parity; core's own grammars. */
    public function testTheTrustTierEdges(): void
    {
        $core = static fn (string $v, string $tier = 'core') => array_column(pp_content_sanitize($v, 'rich', ['tier' => $tier])['losses'], 'clause');
        $this->assertContains('unfiltered_html', $core('<svg></svg>', 'bogus'), 'fail closed: an unknown tier is core');
        $this->assertSame([], $core('<p data-x_y="1" data-z-9="2">x</p>'), 'core kses admits data-[a-z0-9_-]+');
        foreach (['data-a.b', 'data-x:y'] as $attr) {
            $this->assertContains('unfiltered_html', $core('<p ' . $attr . '="1">x</p>'), $attr);
        }
        foreach (['da&#x09;ta:image/png;base64,AAAA', ' data:image/png;base64,AAAA', "&#1;data:image/png;base64,AAAA"] as $src) {
            $this->assertNotSame([], $core('<img alt="" src="' . $src . '">'), $src);
        }
        $this->assertContains('unfiltered_html', $core('<a href="SIP:100">c</a>'));
        $beyond = array_values(array_diff(array_keys(pp_content_math_elements()), array_keys(pp_content_core_post_table())));
        $this->assertNotSame([], $beyond);
        $this->assertContains('unfiltered_html', $core('<math><' . $beyond[0] . '></' . $beyond[0] . '></math>'), $beyond[0]);
        $this->assertSame([], $core('<math><mrow><mi>x</mi></mrow></math>'), 'MathML in core post stays admitted');
        // Kept WHOLE: a declaration core's filter rewrites (here: drops) is refused.
        $this->assertContains('unfiltered_html', $core('<p style="color: red; display: grid">x</p>'));
        $this->assertSame([], $core('<p style="color:red;text-align : center">x</p>'), 'whitespace is normalised');
    }

    /** A fragment-reference refusal names the attribute's own value, not only its target id. */
    public function testAFragmentReferenceMessageShowsTheAttributeValue(): void
    {
        $messages = implode(' | ', array_column($this->losses('<svg><rect fill="url(#gone)"/></svg><p style="filter:url(#nope)">x</p>'), 'message'));
        $this->assertStringContainsString('fill="url(#gone)"', $messages);
        $this->assertStringContainsString('style="filter:url(#nope)"', $messages);
    }

    private function sorted(array $list): array
    {
        sort($list);
        return $list;
    }

    // ── T-17 ──────────────────────────────────────────────────────────────────

    /**
     * T-17's write-side budget: a 64 KiB RICH prop (the M-8 bound) is judged in under a
     * second on the CI runner class (measured locally ~0.2 s). The render-side budget and
     * its cache are T3b's.
     */
    public function testAMaximalRichPropStaysWithinTheWriteBudget(): void
    {
        if (extension_loaded('xdebug') || extension_loaded('pcov')) {
            $this->markTestSkipped('timing budget is not meaningful under coverage instrumentation');
        }
        $unit = '<p>Lorem <strong>ipsum</strong> <a href="https://example.com/x">dolor</a> sit <span style="color:#333">amet</span>.</p>';
        $body = str_repeat($unit, (int) floor(65536 / strlen($unit)));
        $start = microtime(true);
        $r = pp_content_sanitize($body, 'rich');
        $elapsed = microtime(true) - $start;
        $this->assertSame([], $r['losses']);
        $this->assertLessThan(1.0, $elapsed, sprintf('64 KiB RICH prop took %.3f s', $elapsed));
    }
}
