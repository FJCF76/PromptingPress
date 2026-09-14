<?php

/**
 * The editor preview must rank the two UDC layers the way the front end does.
 *
 * BUILD-SPEC §3.4 ranks a band's authored values above a component's role
 * defaults, and both above nothing else — because both layers are zero-or-low
 * specificity by construction. Nothing in the emitted CSS expresses that
 * ranking. The POSITION each layer prints at expresses it, and only that:
 *
 *     defaults  → before the theme stylesheets  (functions.php: handle `pp-base`)
 *     authored  → after  the theme stylesheets  (functions.php: handle `pp-utilities`)
 *
 * The preview endpoint builds its own <head> and never calls wp_head(), so it
 * restates that order by hand. It restated it wrong: it emitted
 * pp_udc_page_css() — both layers concatenated — in ONE block after all three
 * stylesheets, which put the DEFAULTS layer above the shared design-system rules
 * it is supposed to lose to. The preview showed a ranking the site does not have,
 * and nothing failed, because no test could reach inside the AJAX closure.
 *
 * So this file pins the order from both ends and pins the trap shut:
 *
 *   1. the preview's own head places each block at the right position;
 *   2. functions.php still attaches each layer to the handle that produces that
 *      position, so the two sides cannot drift apart one edit at a time;
 *   3. nothing that emits CSS may call the concatenation again.
 *
 * The rendered half of this proof — that the preview iframe and the front end
 * COMPUTE the same values for the same fixture — lives in
 * tests/e2e/composition-editor.spec.ts, because only a browser can run a cascade.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PreviewCascadeParityTest extends TestCase
{
    /** A band that authors one value in a group whose other values come from defaults. */
    private function fixture(): array
    {
        return [[
            'component' => 'testimonials',
            'id'        => 'pp-3f9a1c2e',
            'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
            'udc'       => ['quote' => ['typography' => ['size' => '19px']]],
        ]];
    }

    private function head(): string
    {
        return pp_preview_document_head($this->fixture(), 'https://example.test/theme');
    }

    // ── 1. The preview's own positions ───────────────────────────────────────

    /**
     * The defaults block must print BEFORE components.css.
     *
     * This is the half that was inverted. A role default that targets the band
     * root emits inside `:where(...)` at zero specificity, exactly like the
     * shared adjacent-band rhythm rule in components.css, so whichever prints
     * later wins — and the shared rule must. Printed after components.css, the
     * component's own default starts beating the design system.
     */
    public function testTheDefaultsBlockPrintsBeforeTheComponentStylesheet(): void
    {
        $head = $this->head();

        $defaults   = strpos($head, 'id="pp-udc-defaults"');
        $components = strpos($head, 'components.css');

        $this->assertNotFalse($defaults, 'the preview must emit a defaults block');
        $this->assertNotFalse($components, 'the preview must link components.css');
        $this->assertLessThan(
            $components,
            $defaults,
            'the defaults layer must print before components.css, or it outranks the shared design-system rules'
        );
    }

    /** And the authored block must print after ALL of them, which is what makes it win. */
    public function testTheAuthoredBlockPrintsAfterEveryStylesheet(): void
    {
        $head = $this->head();

        $authored = strpos($head, 'id="pp-udc-authored"');
        $this->assertNotFalse($authored, 'the preview must emit an authored block');

        foreach (['base.css', 'components.css', 'utilities.css'] as $sheet) {
            $link = strpos($head, $sheet);
            $this->assertNotFalse($link, "the preview must link {$sheet}");
            $this->assertLessThan(
                $authored,
                $link,
                "the authored layer must print after {$sheet}, or a structural rule can outrank an authored value"
            );
        }
    }

    /**
     * One block per layer, never one block for both. The concatenated form is
     * the defect itself: a single string occupies a single position, so it
     * cannot express two ranks.
     */
    public function testTheTwoLayersAreTwoBlocks(): void
    {
        $head = $this->head();

        $this->assertSame(2, substr_count($head, '<style'), 'exactly two style blocks');
        $this->assertStringContainsString('font-size:19px', $head, 'the authored value is emitted');
        $this->assertStringContainsString(':where(', $head, 'the root-level defaults are emitted at zero specificity');
    }

    /** An empty composition emits no empty blocks — the head stays clean. */
    public function testACompositionWithNoV2BandsEmitsNeitherBlock(): void
    {
        $head = pp_preview_document_head([], 'https://example.test/theme');

        $this->assertStringNotContainsString('<style', $head);
        $this->assertStringContainsString('base.css', $head, 'the stylesheets are still linked');
    }

    /**
     * THE ASYMMETRIC CASE, which is the ordinary one: a v2 band with no authored
     * udc at all. Defaults exist, authored is empty, so exactly one block emits —
     * and the surviving block must be the DEFAULTS one, still ahead of
     * components.css. The two ternaries that decide this are otherwise only ever
     * exercised both-true (the authored fixture) and both-false (the empty one),
     * so the state a real unstyled page lands in is the untested one.
     */
    public function testAnUnauthoredBandEmitsOnlyTheDefaultsBlockAndStillAheadOfComponents(): void
    {
        $head = pp_preview_document_head([[
            'component' => 'testimonials',
            'id'        => 'pp-3f9a1c2e',
            'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
        ]], 'https://example.test/theme');

        $this->assertSame(1, substr_count($head, '<style'), 'exactly one block');
        $this->assertStringContainsString('id="pp-udc-defaults"', $head);
        $this->assertStringNotContainsString('pp-udc-authored', $head, 'nothing authored, nothing emitted');
        $this->assertLessThan(
            strpos($head, 'components.css'),
            strpos($head, 'id="pp-udc-defaults"'),
            'the surviving block is still the defaults layer, still ahead of components.css'
        );
    }

    /**
     * The theme URI still reaches the attribute through esc_url().
     *
     * Asserted on the SOURCE, not on the rendered bytes: the PHPUnit esc_url()
     * stub is type-faithful but not byte-faithful, so a rendered assertion here
     * would pin the stub's behaviour and quietly enshrine it as the theme's
     * (the residual recorded on tests/EscapingStubContractTest.php). What is
     * worth pinning is that extracting this head out of the AJAX closure carried
     * the escaper with it.
     */
    public function testTheStylesheetUriStillRoutesThroughTheUrlEscaper(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/admin.php');
        $this->assertIsString($source);

        $start = strpos($source, 'function pp_preview_document_head');
        $this->assertNotFalse($start, 'the named head builder must exist');
        $body = substr($source, $start, 900);

        $this->assertStringContainsString('esc_url($dir_uri)', $body);
    }

    // ── 2. The front end still produces the positions the preview mirrors ────

    /**
     * THE OTHER END OF THE SAME CONTRACT.
     *
     * The preview's order is only correct while the front end still produces it.
     * The front end expresses the order through two stylesheet HANDLES — the
     * defaults layer rides `pp-base` (enqueued before components.css) and the
     * authored layer rides `pp-utilities` (enqueued last) — so a change to
     * either attachment silently invalidates the preview's hand-written copy.
     * Read as source because the enqueue callback is an anonymous closure on
     * wp_enqueue_scripts; the rendered equivalence is proven in Playwright.
     */
    public function testTheFrontEndStillAttachesEachLayerToTheHandleThatPositionsIt(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/functions.php');
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression(
            '/wp_add_inline_style\(\s*[\'"]pp-base[\'"],\s*\$pp_udc_defaults/',
            $source,
            'the defaults layer must ride pp-base, which prints before components.css'
        );
        $this->assertMatchesRegularExpression(
            '/wp_add_inline_style\(\s*[\'"]pp-utilities[\'"],\s*\$pp_udc_authored/',
            $source,
            'the authored layer must ride pp-utilities, which prints after every stylesheet'
        );

        // CHROME RIDES THE SAME TWO HANDLES, so it belongs in the same pin.
        //
        // Ruling A1 puts site chrome on this engine, and its two tiers attach to the
        // same handles for the same reason. Without these two assertions, swapping
        // $pp_chrome_authored onto `pp-base` would print authored chrome BEFORE
        // components.css — so every authored chrome value would silently lose to the
        // stylesheet it is supposed to override — and the entire suite would stay
        // green, because nothing else reads this wiring.
        $this->assertMatchesRegularExpression(
            '/wp_add_inline_style\(\s*[\'"]pp-base[\'"],\s*\$pp_chrome_defaults/',
            $source,
            'the chrome defaults tier must ride pp-base, which prints before components.css'
        );
        $this->assertMatchesRegularExpression(
            '/wp_add_inline_style\(\s*[\'"]pp-utilities[\'"],\s*\$pp_chrome_authored/',
            $source,
            'authored chrome must ride pp-utilities, or it loses to the stylesheet it overrides'
        );

        // AND IT IS NOT GATED ON THE COMPOSITION. Chrome renders on every page,
        // including 404 and search, where pp_udc_current_composition() answers [].
        $this->assertMatchesRegularExpression(
            '/\$pp_chrome_authored\s*=\s*pp_udc_chrome_authored_css\(\s*\)/',
            $source,
            'chrome CSS must be built from the option, not from the page composition'
        );
    }

    /**
     * AND THE PREVIEW ENDPOINT MUST ACTUALLY CALL IT.
     *
     * Everything above exercises pp_preview_document_head() in isolation, which
     * says nothing about whether the AJAX handler still uses it. That gap is not
     * theoretical: the handler's head can be reverted inline to one concatenated
     * `<style>` after all three links — byte-for-byte the original B1 defect —
     * and every other test here stays green, because the bypass never names
     * pp_udc_page_css() and never touches the extracted function. A guard that
     * bans one SPELLING of a mistake does not ban the mistake.
     *
     * Read as source, like the functions.php pin above, because the handler is an
     * anonymous closure on `wp_ajax_pp_preview_composition`.
     */
    public function testThePreviewEndpointBuildsItsHeadThroughTheSharedFunction(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/lib/admin.php');
        $this->assertIsString($source);

        $start = strpos($source, "add_action('wp_ajax_pp_preview_composition'");
        $this->assertNotFalse($start, 'the preview endpoint must exist');
        // Stop at the head builder's DOCBLOCK, not at its `function` keyword —
        // that docblock draws the emitted head, `<style id="pp-udc-defaults">`
        // included, and slicing past it would put those literals inside the text
        // this test asserts are absent.
        $end = strpos($source, "\n/**\n * The preview iframe's <head>", $start);
        $this->assertNotFalse($end, 'the head builder must follow the endpoint');
        $closure = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            'pp_preview_document_head(',
            $closure,
            'the preview endpoint must build its head through the shared function'
        );
        // No head assembly of its own: a second <style> written here is how the
        // two surfaces drifted apart in the first place.
        $this->assertStringNotContainsString(
            '<style',
            $closure,
            'the preview endpoint must not emit a style block of its own'
        );
        $this->assertStringNotContainsString(
            'rel="stylesheet"',
            $closure,
            'the preview endpoint must not link stylesheets of its own'
        );
    }

    // ── 3. The trap stays shut ───────────────────────────────────────────────

    /**
     * NO PRODUCTION FILE MAY CALL THE CONCATENATION AGAIN.
     *
     * pp_udc_page_css() returns both layers in one string. That is a legitimate
     * thing for a test to assert on and an illegitimate thing to emit, and the
     * difference is invisible at the call site — which is how the preview came
     * to hold it for a whole sprint. A docblock asking callers not to do it is
     * the guard that already failed; this is the one that fails the build.
     */
    public function testNoProductionFileEmitsTheConcatenatedLayers(): void
    {
        $root    = dirname(__DIR__);
        $callers = [];
        $scanned = 0;

        // EVERY production .php file, not a hand-listed set — a tripwire that
        // scans a fixed list stops covering the directory someone adds next.
        // tests/ is the one exclusion, because using this function is exactly
        // what tests are permitted to do.
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (['/tests/', '/vendor/', '/node_modules/', '/.git/'] as $skip) {
                if (strpos($path, $skip) !== false) {
                    continue 2;
                }
            }
            $scanned++;

            // TOKENIZED, not regex-stripped. A regex that removes `//…` and
            // `/*…*/` does not know what a PHP string is, so it mangles any file
            // containing `"http://…"` or a CSS comment in a literal and can hide
            // — or invent — a call. token_get_all() is the parser's own answer.
            $tokens = token_get_all((string) file_get_contents($path));
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'pp_udc_page_css') {
                    continue;
                }
                // The declaration in lib/udc.php is not a call: it is preceded by
                // the `function` keyword.
                $prev = null;
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (is_array($tokens[$j])
                        && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $prev = $tokens[$j];
                    break;
                }
                if (is_array($prev) && $prev[0] === T_FUNCTION) {
                    continue;
                }
                $callers[] = str_replace($root . '/', '', $path) . ':' . $token[2];
            }
        }

        $this->assertGreaterThan(10, $scanned, 'the scan must actually reach the production files');
        $this->assertSame(
            [],
            $callers,
            'pp_udc_page_css() flattens the two cascade tiers into one position; '
            . 'emit pp_udc_page_defaults_css() and pp_udc_page_authored_css() separately'
        );
    }
}
