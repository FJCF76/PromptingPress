<?php
/**
 * tests/HeaderChromeTest.php
 *
 * Issue 333 — header chrome. The header/nav is template-owned (issue 223) with no
 * composition style slots, so before this it was the one above-the-fold element with
 * NO styling surface at all: .site-header was hard-bound to --color-bg. Its background,
 * text, and link colors are now set through whitelisted site options — the same safe
 * surface as the footer's (issue 300). This suite pins:
 *   - the whitelist + type mapping for the three new pp_header_* options;
 *   - pp_header_bg typed 'gradient' (the color-OR-gradient union) so a gradient
 *     marketing header is expressible, delegating to the shared _pp_validate_token_value
 *     engine — no second, header-specific validator (a repo invariant);
 *   - the nav template rendering inline --header-* custom properties, with the render
 *     boundary (issue 330) re-validating each value against its DECLARED type;
 *   - byte-identical unset output (no style attribute at all);
 *   - the CSS var(--header-*, <literal>) consume-plus-fallback contract, which is
 *     OUTSIDE the issue 305 schema-slot guard because nav declares no style_slots —
 *     this file is that contract's guard;
 *   - critically, that the background routes through the `background` SHORTHAND and not
 *     `background-color`: a gradient is a CSS <image>, and background-color would drop
 *     it as invalid, so the option would validate on write and then never paint.
 *
 * Defaults are unchanged: this issue adds a capability, never a color opinion.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class HeaderChromeTest extends TestCase
{
    private string $themeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themeRoot = dirname(__DIR__);
        $GLOBALS['_pp_test_store'] = [
            'post_meta'           => [],
            'posts'               => [],
            'options'             => [],
            'theme_mods'          => [],
            'attachment_urls'     => [],
            'attachment_is_image' => [],
            'next_id'             => 100,
        ];
    }

    private function renderNav(array $props): string
    {
        ob_start();
        pp_get_component('nav', $props);
        return ob_get_clean();
    }

    private function css(): string
    {
        return file_get_contents($this->themeRoot . '/assets/css/components.css');
    }

    /** Extracts a CSS rule block by exact selector at a line start. */
    private function cssRuleBlock(string $selector): ?string
    {
        $pattern = '/(?:^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m';
        return preg_match($pattern, $this->css(), $m) ? $m[1] : null;
    }

    // ── Whitelist + types ───────────────────────────────────────────────────

    public function testAllowedSiteOptionsIncludesHeaderChromeKeys(): void
    {
        $allowed = pp_allowed_site_options();
        $this->assertSame('gradient', $allowed['pp_header_bg']);
        $this->assertSame('color',    $allowed['pp_header_text']);
        $this->assertSame('color',    $allowed['pp_header_link_color']);
    }

    // ── Validation delegates to the shared engines ──────────────────────────

    public function testHeaderBgAcceptsColorsAndGradients(): void
    {
        foreach ([
            '#1a1a2e', '#fff', 'rgb(26, 26, 46)', 'rgba(0, 0, 0, 0.55)',
            'hsl(240, 30%, 14%)', 'transparent', 'currentColor',
            'linear-gradient(135deg, #1a1a2e, #16121f)',
            'radial-gradient(circle at top left, #2a2a4e, #16121f)',
        ] as $val) {
            $this->assertTrue(
                pp_validate_site_option_value('pp_header_bg', $val),
                "pp_header_bg should accept '{$val}'."
            );
        }
    }

    public function testHeaderBgRejectsJunkAndUnboundedGradients(): void
    {
        foreach ([
            'red',                                            // named colors are rejected
            'notacolor',
            'url(javascript:alert(1))',
            '',
            'conic-gradient(#111, #222)',                     // excluded gradient function
            'repeating-linear-gradient(#111, #222)',
            'linear-gradient(90deg, url(evil), #111)',        // url() inside a gradient
            'linear-gradient(90deg, var(--color-accent), #111)', // var() inside a gradient
        ] as $val) {
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_validate_site_option_value('pp_header_bg', $val),
                "pp_header_bg should reject '{$val}'."
            );
        }
    }

    public function testHeaderBgDelegatesToTheSharedGradientEngine(): void
    {
        // No second validator (repo invariant): whatever the shared engine accepts,
        // the option accepts, and vice versa.
        foreach ([
            'linear-gradient(135deg, #1a1a2e, #16121f)',
            'conic-gradient(#111, #222)',
            '#abcdef',
            'transparent',
            'red',
            'oops',
        ] as $val) {
            $engine = _pp_validate_token_value($val, 'gradient') === true;
            $option = pp_validate_site_option_value('pp_header_bg', $val) === true;
            $this->assertSame($engine, $option, "Divergence from the shared gradient engine on '{$val}'.");
        }
    }

    public function testHeaderTextAndLinkAreColorOnly(): void
    {
        foreach (['pp_header_text', 'pp_header_link_color'] as $key) {
            $this->assertTrue(pp_validate_site_option_value($key, '#e8e8f0'));
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_validate_site_option_value($key, 'linear-gradient(135deg, #1a1a2e, #16121f)'),
                "{$key} must reject a gradient — a gradient is meaningless on `color:`."
            );
        }
    }

    public function testHeaderColorTypeUsesTheSameEngineAsStyleSlots(): void
    {
        foreach (['#abcdef', 'transparent', 'red', 'oops'] as $val) {
            $engine = _pp_validate_color($val);
            $option = pp_validate_site_option_value('pp_header_text', $val) === true;
            $this->assertSame($engine, $option, "Divergence from _pp_validate_color on '{$val}'.");
        }
    }

    // ── Write path + round-trip ─────────────────────────────────────────────

    public function testUpdateStoresGradientAndReValidates(): void
    {
        $grad = 'linear-gradient(135deg, #1a1a2e, #16121f)';
        $this->assertTrue(pp_update_site_option('pp_header_bg', $grad));
        $stored = get_option('pp_header_bg');
        $this->assertSame($grad, $stored);
        // A stored value must survive a round-trip through the validating writer
        // (the snapshot/rollback path re-applies it).
        $this->assertTrue(pp_validate_site_option_value('pp_header_bg', $stored));
    }

    public function testUpdateRejectsInvalidHeaderBg(): void
    {
        $result = pp_update_site_option('pp_header_bg', 'chartreuse-ish');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertArrayNotHasKey('pp_header_bg', $GLOBALS['_pp_test_store']['options']);
    }

    public function testActionPathValidatesHeaderOptions(): void
    {
        $ok = pp_execute_action('update_site_option', [
            'key' => 'pp_header_bg', 'value' => 'linear-gradient(135deg, #1a1a2e, #16121f)',
        ]);
        $this->assertTrue($ok['ok'], 'a valid header gradient should pass the action');
        $this->assertSame('linear-gradient(135deg, #1a1a2e, #16121f)', get_option('pp_header_bg'));

        $bad = pp_execute_action('update_site_option', ['key' => 'pp_header_bg', 'value' => 'nope']);
        $this->assertFalse($bad['ok'], 'an invalid header background should fail the action');
    }

    // ── Restore is never blocked by current validation (#233 / #281) ────────

    public function testRestoreOfAnUnsetHeaderBaselineDeletesTheOption(): void
    {
        // An unset baseline is captured as '' and restored by DELETING the option —
        // it must not be pushed back through the validating writer, which would reject
        // '' and silently leave the applied value in place.
        $GLOBALS['_pp_test_store']['options']['pp_header_bg'] = '#1a1a2e'; // stray applied value

        $errors = _pp_restore_batch_snapshot([
            'created_posts'   => [],
            'posts'           => [],
            'site_options'    => ['pp_header_bg' => ''],
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ]);

        $this->assertSame([], $errors);
        $this->assertArrayNotHasKey(
            'pp_header_bg',
            $GLOBALS['_pp_test_store']['options'],
            'unset -> unset must round-trip by deleting the option.'
        );
    }

    public function testRestoreIsNotBlockedByCurrentValidationRules(): void
    {
        // #233 / #281: a restore replays the captured baseline VERBATIM, bypassing the
        // create-time validator. A once-stored value that a newer rule would now reject
        // must still roll back, or the applied value would be left in place.
        $GLOBALS['_pp_test_store']['options']['pp_header_bg'] = 'linear-gradient(135deg, #1a1a2e, #16121f)';

        $errors = _pp_restore_batch_snapshot([
            'created_posts'   => [],
            'posts'           => [],
            'site_options'    => ['pp_header_bg' => 'chartreuse-ish'], // would fail validation today
            'custom_css'      => null,
            'token_overrides' => null,
            'font_urls'       => null,
            'menus'           => null,
        ]);

        $this->assertSame([], $errors);
        $this->assertSame('chartreuse-ish', get_option('pp_header_bg', ''));
    }

    // ── Render: styled ──────────────────────────────────────────────────────

    public function testStyledHeaderEmitsInlineCustomProperties(): void
    {
        $html = $this->renderNav([
            'location'   => 'primary',
            'bg'         => 'linear-gradient(135deg, #1a1a2e, #16121f)',
            'text'       => '#e8e8f0',
            'link_color' => '#c8c8e0',
        ]);
        $this->assertStringContainsString('--header-bg: linear-gradient(135deg, #1a1a2e, #16121f)', $html);
        $this->assertStringContainsString('--header-text: #e8e8f0', $html);
        $this->assertStringContainsString('--header-link-color: #c8c8e0', $html);
        $this->assertMatchesRegularExpression('/<header[^>]*style="[^"]*--header-bg/', $html);
    }

    public function testRenderBoundaryDropsAValueThatNeverPassedWriteValidation(): void
    {
        // Issue 330: an out-of-band write (someone edits the option row directly) must
        // not reach the inline style attribute. The rest of the header still renders.
        $html = $this->renderNav([
            'location' => 'primary',
            'bg'       => 'url(javascript:alert(1))',
            'text'     => '#e8e8f0',
        ]);
        $this->assertStringNotContainsString('--header-bg', $html, 'unvalidated bg must be dropped');
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('--header-text: #e8e8f0', $html, 'the valid slot still renders');
        $this->assertStringContainsString('site-header', $html, 'the header itself still renders');
    }

    public function testRenderBoundaryDropsAGradientOnAColorTypedSlot(): void
    {
        // The emitter's per-variable type map must be honored: a gradient is valid for
        // bg but NOT for text, so it must be dropped there even though it is a value the
        // gradient engine would accept.
        $html = $this->renderNav([
            'location' => 'primary',
            'text'     => 'linear-gradient(135deg, #1a1a2e, #16121f)',
        ]);
        $this->assertStringNotContainsString('--header-text', $html);
    }

    // ── The shared chrome emitter derives type from the whitelist ───────────

    public function testChromeStyleAttrDerivesTypeFromTheWhitelist(): void
    {
        // Both nav.php and footer.php route through pp_chrome_style_attr(), which reads
        // each value's type from pp_allowed_site_options() keyed by the option name —
        // never a hand-copied type. This is the structural fix for the #333 drift class
        // (whitelist said 'gradient', a separate render map said 'color', gradients were
        // silently dropped). A gradient must be KEPT when the named option is
        // gradient-typed and DROPPED when it is color-typed, with no type argument passed
        // by the caller at all.
        $grad = 'linear-gradient(135deg, #1a1a2e, #16121f)';

        $keptOnGradient = pp_chrome_style_attr([
            '--x' => ['value' => $grad, 'option' => 'pp_header_bg'], // 'gradient' in the whitelist
        ]);
        $this->assertStringContainsString('--x: ' . $grad, $keptOnGradient);

        $droppedOnColor = pp_chrome_style_attr([
            '--x' => ['value' => $grad, 'option' => 'pp_header_text'], // 'color' in the whitelist
        ]);
        $this->assertSame('', $droppedOnColor, 'a gradient on a color-typed option must be dropped');
    }

    public function testChromeStyleAttrEmitsNothingForAnEmptySet(): void
    {
        $this->assertSame('', pp_chrome_style_attr([
            '--header-bg'   => ['value' => '', 'option' => 'pp_header_bg'],
            '--header-text' => ['value' => '', 'option' => 'pp_header_text'],
        ]));
    }

    public function testChromeStyleAttrFailsClosedOnAnUnknownOptionKey(): void
    {
        // A wiring mistake (a typo'd or non-whitelisted 'option') resolves to a null
        // type, which would drop the value to layer-1-only validation. The helper must
        // fail CLOSED and drop the declaration entirely rather than emit it
        // under-validated — even a value that is otherwise a clean color.
        $this->assertSame('', pp_chrome_style_attr([
            '--x' => ['value' => '#1a1a2e', 'option' => 'pp_not_a_real_option'],
        ]));
    }

    // ── Render: unset (byte-identical to the pre-333 header) ────────────────

    public function testUnsetHeaderHasNoStyleAttribute(): void
    {
        $html = $this->renderNav(['location' => 'primary']);
        $this->assertStringNotContainsString('style="', $html, 'unset header must not emit a style attr');
        $this->assertStringNotContainsString('--header-', $html);
        $this->assertStringContainsString('class="site-header"', $html);
    }

    // ── CSS consume + fallback contract (the issue 305 guard does NOT cover
    //    nav, which declares no style_slots — this is that guard). ───────────

    public function testHeaderBackgroundUsesTheShorthandNotBackgroundColor(): void
    {
        $block = $this->cssRuleBlock('.site-header');
        $this->assertNotNull($block);
        // THE load-bearing assertion of this issue. --header-bg is gradient-typed, and a
        // gradient is a CSS <image>: `background-color: linear-gradient(...)` is invalid,
        // so the browser drops the declaration and the header paints nothing. The
        // shorthand accepts both a color and a gradient.
        $this->assertMatchesRegularExpression(
            '/(?<!-)background:\s*var\(--header-bg,\s*var\(--color-bg\)\)/',
            $block
        );
        $this->assertDoesNotMatchRegularExpression(
            '/background-color:\s*var\(--header-bg/',
            $block,
            'background-color cannot paint a gradient — use the background shorthand.'
        );
    }

    public function testHeaderTextRoutesThroughSlotWithTextFallback(): void
    {
        foreach (['.nav__logo', '.nav__toggle'] as $selector) {
            $block = $this->cssRuleBlock($selector);
            $this->assertNotNull($block, "{$selector} rule should exist");
            $this->assertMatchesRegularExpression(
                '/color:\s*var\(--header-text,\s*var\(--color-text\)\)/',
                $block,
                "{$selector} must consume --header-text with the existing literal as fallback."
            );
        }
    }

    public function testHeaderNavLinkRoutesThroughSlotWithTextFallback(): void
    {
        $block = $this->cssRuleBlock('.nav__menu ul li a');
        $this->assertNotNull($block);
        $this->assertMatchesRegularExpression(
            '/color:\s*var\(--header-link-color,\s*var\(--color-text\)\)/',
            $block
        );
    }

    public function testDefaultHeaderColorsAreUnchanged(): void
    {
        // This issue adds a CAPABILITY, never a color opinion: every fallback must still
        // be the literal that was there before, so an unstyled site renders identically.
        $header = $this->cssRuleBlock('.site-header');
        $this->assertStringContainsString('var(--color-bg)', $header);
        $this->assertStringContainsString('var(--header-text, var(--color-text))', $this->cssRuleBlock('.nav__logo'));
    }

    // ── base.php maps every option onto a nav prop ──────────────────────────

    public function testBaseTemplateMapsEveryHeaderOption(): void
    {
        $base = file_get_contents($this->themeRoot . '/templates/base.php');
        foreach (['pp_header_bg', 'pp_header_text', 'pp_header_link_color'] as $opt) {
            $this->assertStringContainsString(
                "get_option('{$opt}'",
                $base,
                "templates/base.php must read the {$opt} site option and pass it to the nav."
            );
        }
    }

    public function testNavSchemaDeclaresTheStyleProps(): void
    {
        $schema = json_decode(
            file_get_contents($this->themeRoot . '/components/nav/schema.json'),
            true
        );
        foreach (['bg', 'text', 'link_color'] as $prop) {
            $this->assertArrayHasKey($prop, $schema['props'], "nav schema must document the {$prop} prop.");
        }
        foreach (['--header-bg', '--header-text', '--header-link-color'] as $token) {
            $this->assertContains($token, $schema['styling']['tokens']);
        }
    }
}
