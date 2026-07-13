<?php
/**
 * tests/FooterChromeTest.php
 *
 * Issue 300 — dark marketing footer chrome. The footer is template-owned
 * (issue 223) with no composition style slots, so its background/text/link
 * colors and its blurb/contact/copyright content are set through whitelisted
 * site options (the same safe surface as pp_footer_show_logo, issue 234). This
 * suite pins:
 *   - the whitelist + type mapping for the six new pp_footer_* options;
 *   - the new 'color' site-option type delegating to the shared _pp_validate_color
 *     engine (issue 230) — no second, footer-specific color validator;
 *   - the footer template rendering (inline --footer-* custom properties, blurb,
 *     contact, configurable copyright) with correct escaping;
 *   - byte-identical unset output (no style attr, default copyright, no brand block);
 *   - the CSS var(--footer-*, <literal>) consume-plus-fallback contract, which is
 *     OUTSIDE the issue 305 schema-slot guard because the footer declares no
 *     style_slots — this file is that contract's guard.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class FooterChromeTest extends TestCase
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

    private function renderFooter(array $props): string
    {
        ob_start();
        pp_get_component('footer', $props);
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

    public function testAllowedSiteOptionsIncludesFooterChromeKeys(): void
    {
        $allowed = pp_allowed_site_options();
        // pp_footer_bg is 'gradient' (the color-OR-gradient union), not 'color' — issue
        // 333. Issue 300 typed it 'color' believing that engine already took gradients;
        // it does not, so a gradient footer was silently inexpressible.
        $this->assertSame('gradient', $allowed['pp_footer_bg']);
        $this->assertSame('color',  $allowed['pp_footer_text']);
        $this->assertSame('color',  $allowed['pp_footer_link_color']);
        $this->assertSame('string', $allowed['pp_footer_blurb']);
        $this->assertSame('string', $allowed['pp_footer_contact']);
        $this->assertSame('string', $allowed['pp_footer_copyright']);
    }

    // ── Gradient background (issue 333) ─────────────────────────────────────

    public function testFooterBgAcceptsGradients(): void
    {
        foreach ([
            'linear-gradient(135deg, #1a1a2e, #16121f)',
            'radial-gradient(circle at top left, #2a2a4e, #16121f)',
        ] as $val) {
            $this->assertTrue(
                pp_validate_site_option_value('pp_footer_bg', $val),
                "pp_footer_bg should accept the gradient '{$val}'."
            );
        }
    }

    public function testFooterBgGradientIsNotAcceptedOnTextOrLinkOptions(): void
    {
        // The union widening is scoped to the BACKGROUND option only; text/link stay
        // plain colors (a gradient is meaningless on `color:`).
        foreach (['pp_footer_text', 'pp_footer_link_color'] as $key) {
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_validate_site_option_value($key, 'linear-gradient(135deg, #1a1a2e, #16121f)'),
                "{$key} must reject a gradient."
            );
        }
    }

    public function testFooterBgGradientDelegatesToTheSharedEngine(): void
    {
        // No second validator (repo invariant): whatever the shared 'gradient' engine
        // accepts, the option accepts, and vice versa.
        foreach ([
            'linear-gradient(135deg, #1a1a2e, #16121f)',
            'conic-gradient(#111, #222)',                       // excluded gradient fn
            'linear-gradient(90deg, var(--color-accent), #111)', // var() inside a gradient
            '#1a1a2e',
            'garbage',
        ] as $val) {
            $engine = _pp_validate_token_value($val, 'gradient') === true;
            $option = pp_validate_site_option_value('pp_footer_bg', $val) === true;
            $this->assertSame($engine, $option, "Divergence from the shared gradient engine on '{$val}'.");
        }
    }

    public function testStoredGradientSurvivesTheRenderBoundary(): void
    {
        // Regression guard for the bug this issue found in footer.php: the render
        // boundary used to hardcode type 'color', which DROPPED every stored gradient
        // — it passed write-time validation and then silently never painted.
        $grad = 'linear-gradient(135deg, #1a1a2e, #16121f)';
        $this->assertTrue(pp_update_site_option('pp_footer_bg', $grad));
        $html = $this->renderFooter(['location' => 'footer', 'bg' => $grad]);
        $this->assertStringContainsString('--footer-bg: ' . $grad, $html);
    }

    public function testRenderBoundaryDropsAGradientOnAColorTypedFooterSlot(): void
    {
        // The footer emitter carried the original hardcoded-'color' bug, so its own
        // per-option type resolution deserves a drop-case guard: a gradient is valid
        // for --footer-bg but NOT for the color-typed --footer-text, so it must be
        // dropped there even though the gradient engine would accept the string.
        $html = $this->renderFooter([
            'location' => 'footer',
            'text'     => 'linear-gradient(135deg, #1a1a2e, #16121f)',
        ]);
        $this->assertStringNotContainsString('--footer-text', $html);
    }

    // ── Color type delegates to the shared engine ───────────────────────────

    public function testColorOptionAcceptsSharedEngineColors(): void
    {
        foreach (['#1a1a2e', '#fff', 'rgb(26, 26, 46)', 'rgba(0, 0, 0, 0.55)',
                  'hsl(240, 30%, 14%)', 'transparent', 'currentColor'] as $val) {
            $this->assertTrue(
                pp_validate_site_option_value('pp_footer_bg', $val),
                "Footer color option should accept '{$val}'."
            );
        }
    }

    public function testColorOptionRejectsNonColors(): void
    {
        foreach (['red', 'notacolor', 'url(javascript:alert(1))', '#12g', ''] as $val) {
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_validate_site_option_value('pp_footer_link_color', $val),
                "Footer color option should reject '{$val}'."
            );
        }
    }

    public function testColorTypeUsesTheSameEngineAsStyleSlots(): void
    {
        // No second validator (repo invariant): whatever _pp_validate_color accepts,
        // the color site option accepts, and vice versa.
        foreach (['#abcdef', 'transparent', 'red', 'oops'] as $val) {
            $engine = _pp_validate_color($val);
            $option = pp_validate_site_option_value('pp_footer_text', $val) === true;
            $this->assertSame($engine, $option, "Divergence from _pp_validate_color on '{$val}'.");
        }
    }

    public function testStringOptionsAcceptFreeText(): void
    {
        $this->assertTrue(pp_validate_site_option_value('pp_footer_blurb', 'Anything at all.'));
        $this->assertTrue(pp_validate_site_option_value('pp_footer_contact', "line1\nline2"));
        $this->assertTrue(pp_validate_site_option_value('pp_footer_copyright', '© 2026 X.'));
    }

    // ── Write path + round-trip ─────────────────────────────────────────────

    public function testUpdateStoresColorAndReValidates(): void
    {
        $this->assertTrue(pp_update_site_option('pp_footer_bg', '#1a1a2e'));
        $stored = get_option('pp_footer_bg');
        $this->assertSame('#1a1a2e', $stored);
        // A stored value must survive a round-trip through the validating writer
        // (the snapshot/rollback path re-applies it).
        $this->assertTrue(pp_validate_site_option_value('pp_footer_bg', $stored));
    }

    public function testUpdateRejectsInvalidColor(): void
    {
        $result = pp_update_site_option('pp_footer_bg', 'chartreuse-ish');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertArrayNotHasKey('pp_footer_bg', $GLOBALS['_pp_test_store']['options']);
    }

    public function testActionPathValidatesFooterColor(): void
    {
        $ok = pp_execute_action('update_site_option', ['key' => 'pp_footer_bg', 'value' => '#101020']);
        $this->assertTrue($ok['ok'], 'valid footer color should pass the action');
        $this->assertSame('#101020', get_option('pp_footer_bg'));
        $bad = pp_execute_action('update_site_option', ['key' => 'pp_footer_bg', 'value' => 'nope']);
        $this->assertFalse($bad['ok'], 'invalid footer color should fail the action');
    }

    // ── Render: styled ──────────────────────────────────────────────────────

    public function testStyledFooterEmitsInlineCustomProperties(): void
    {
        $html = $this->renderFooter([
            'location'   => 'footer',
            'bg'         => '#1a1a2e',
            'text'       => '#e8e8f0',
            'link_color' => '#c8c8e0',
        ]);
        $this->assertStringContainsString('--footer-bg: #1a1a2e', $html);
        $this->assertStringContainsString('--footer-text: #e8e8f0', $html);
        $this->assertStringContainsString('--footer-link-color: #c8c8e0', $html);
        $this->assertMatchesRegularExpression('/<footer[^>]*style="[^"]*--footer-bg/', $html);
    }

    public function testBlurbContactCopyrightRender(): void
    {
        $html = $this->renderFooter([
            'location'  => 'footer',
            'blurb'     => 'Ship credible sites fast.',
            'contact'   => "hello@example.com\nSan Francisco",
            'copyright' => 'Custom line 2026.',
        ]);
        $this->assertStringContainsString('site-footer__blurb', $html);
        $this->assertStringContainsString('Ship credible sites fast.', $html);
        $this->assertStringContainsString('site-footer__contact', $html);
        // Multi-line contact renders line breaks (nl2br).
        $this->assertStringContainsString('San Francisco', $html);
        $this->assertMatchesRegularExpression('/hello@example\.com<br\s*\/?>/', $html);
        // Configurable copyright replaces the default verbatim.
        $this->assertStringContainsString('Custom line 2026.', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    public function testContentIsEscaped(): void
    {
        $html = $this->renderFooter([
            'location'  => 'footer',
            'blurb'     => '<script>alert(1)</script>',
            'copyright' => 'A & B <b>c</b>',
        ]);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('A &amp; B', $html);
    }

    // ── Render: unset (byte-identical to pre-300 footer) ────────────────────

    public function testUnsetFooterHasNoStyleAttributeOrBrandBlock(): void
    {
        $html = $this->renderFooter(['location' => 'footer']);
        $this->assertStringNotContainsString('style="', $html, 'unset footer must not emit a style attr');
        $this->assertStringNotContainsString('site-footer__brand', $html, 'no brand block without logo/blurb');
        $this->assertStringNotContainsString('site-footer__contact', $html);
        $this->assertStringNotContainsString('site-footer__blurb', $html);
    }

    public function testUnsetFooterRendersDefaultCopyrightWithoutTextMutedClass(): void
    {
        $GLOBALS['_pp_test_store']['options']['blogname'] = 'Acme';
        $html = $this->renderFooter(['location' => 'footer']);
        $this->assertStringContainsString('All rights reserved.', $html);
        // The .text-muted utility hardcodes --color-muted and would beat
        // --footer-text on a dark footer; the copyright must not carry it.
        $this->assertStringNotContainsString('site-footer__copyright text-muted', $html);
        $this->assertMatchesRegularExpression('/class="site-footer__copyright"/', $html);
    }

    // ── CSS consume + fallback contract (the issue 305 guard does NOT cover
    //    the footer, which declares no style_slots — this is that guard). ────

    public function testFooterBackgroundRoutesThroughSlotWithSurfaceFallback(): void
    {
        $block = $this->cssRuleBlock('.site-footer');
        $this->assertNotNull($block);
        // MUST be the `background` shorthand, never `background-color` (issue 333):
        // --footer-bg is gradient-typed, and a gradient is a CSS <image>. Assigning one
        // to background-color is invalid, so the browser drops the declaration and the
        // footer paints nothing. This assertion is the static half of that guard; the
        // computed-style E2E pin is the rendered half.
        $this->assertMatchesRegularExpression(
            '/(?<!-)background:\s*var\(--footer-bg,\s*var\(--color-surface\)\)/',
            $block
        );
        $this->assertDoesNotMatchRegularExpression(
            '/background-color:\s*var\(--footer-bg/',
            $block,
            'background-color cannot paint a gradient — use the background shorthand.'
        );
        $this->assertMatchesRegularExpression('/color:\s*var\(--footer-text,\s*inherit\)/', $block);
    }

    public function testFooterNavLinkRoutesThroughSlotWithMutedFallback(): void
    {
        $block = $this->cssRuleBlock('.site-footer__nav ul li a');
        $this->assertNotNull($block);
        $this->assertMatchesRegularExpression(
            '/color:\s*var\(--footer-link-color,\s*var\(--color-muted\)\)/',
            $block
        );
    }

    public function testFooterCopyrightRoutesThroughTextSlotWithMutedFallback(): void
    {
        $block = $this->cssRuleBlock('.site-footer__copyright');
        $this->assertNotNull($block);
        $this->assertMatchesRegularExpression(
            '/color:\s*var\(--footer-text,\s*var\(--color-muted\)\)/',
            $block
        );
    }

    public function testFooterLogoCapNotRegressed(): void
    {
        // Issue 300 must not touch the issue 299 cap.
        $block = $this->cssRuleBlock('.site-footer__logo-image');
        $this->assertNotNull($block);
        $this->assertMatchesRegularExpression('/max-height:\s*2\.5rem/', $block);
    }

    // ── base.php maps every option onto a footer prop ───────────────────────

    public function testBaseTemplateMapsEveryFooterOption(): void
    {
        $base = file_get_contents($this->themeRoot . '/templates/base.php');
        foreach (['pp_footer_bg', 'pp_footer_text', 'pp_footer_link_color',
                  'pp_footer_blurb', 'pp_footer_contact', 'pp_footer_copyright'] as $opt) {
            $this->assertStringContainsString(
                "get_option('{$opt}'",
                $base,
                "templates/base.php must read the {$opt} site option and pass it to the footer."
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Footer STRUCTURE (issue 335): column headings, delimited bottom bar,
    //  footer logo override. Additive to issue 300; every new option optional,
    //  unset output stays the issue-300 footer.
    // ══════════════════════════════════════════════════════════════════════

    /** Seeds a Media Library image attachment the resolver/validator will accept. */
    private function seedImage(int $id, string $url): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id]['post_type']  = 'attachment';
        $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = true;
        $GLOBALS['_pp_test_store']['attachment_urls'][$id]     = $url;
    }

    // ── Whitelist + types ───────────────────────────────────────────────────

    public function testAllowedSiteOptionsIncludesFooterStructureKeys(): void
    {
        $allowed = pp_allowed_site_options();
        $this->assertSame('string',        $allowed['pp_footer_menu_label']);
        $this->assertSame('string',        $allowed['pp_footer_contact_label']);
        $this->assertSame('string',        $allowed['pp_footer_note']);
        // The footer logo override is an attachment ID, validated like pp_logo_id.
        $this->assertSame('attachment_id', $allowed['pp_footer_logo_id']);
    }

    public function testFooterStructureLabelsAndNoteAcceptFreeText(): void
    {
        $this->assertTrue(pp_validate_site_option_value('pp_footer_menu_label', 'Legal'));
        $this->assertTrue(pp_validate_site_option_value('pp_footer_contact_label', 'Contact'));
        $this->assertTrue(pp_validate_site_option_value('pp_footer_note', "Made with care\nsecond line"));
    }

    // ── Footer logo override validates exactly like pp_logo_id ───────────────

    public function testFooterLogoIdValidatesAsImageAttachment(): void
    {
        $this->seedImage(57, 'http://example.com/footer-light.png');
        $this->assertTrue(pp_validate_site_option_value('pp_footer_logo_id', '57'));

        // Empty, zero, and non-existent IDs are rejected (same as pp_logo_id).
        foreach (['', '0', '999'] as $bad) {
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_validate_site_option_value('pp_footer_logo_id', $bad),
                "pp_footer_logo_id must reject '{$bad}'."
            );
        }

        // A non-image attachment is rejected.
        $GLOBALS['_pp_test_store']['posts'][58]['post_type']  = 'attachment';
        $GLOBALS['_pp_test_store']['attachment_is_image'][58] = false;
        $this->assertInstanceOf(
            \WP_Error::class,
            pp_validate_site_option_value('pp_footer_logo_id', '58')
        );
    }

    public function testFooterLogoIdSharesTheSameRuleAsPpLogoId(): void
    {
        // No second, surface-specific rule: pp_footer_logo_id and pp_logo_id must
        // accept/reject identically (both funnel through pp_is_image_attachment).
        $this->seedImage(57, 'http://example.com/x.png');
        foreach (['57', '0', '', '999'] as $val) {
            $logo   = pp_validate_site_option_value('pp_logo_id', $val) === true;
            $footer = pp_validate_site_option_value('pp_footer_logo_id', $val) === true;
            $this->assertSame($logo, $footer, "Divergence from pp_logo_id on '{$val}'.");
        }
    }

    // ── Render: column headings ─────────────────────────────────────────────

    public function testMenuHeadingRendersOnlyWhenLabelSet(): void
    {
        $with = $this->renderFooter(['location' => 'footer', 'menu_label' => 'Legal']);
        $this->assertStringContainsString('site-footer__heading', $with);
        $this->assertStringContainsString('Legal', $with);

        $without = $this->renderFooter(['location' => 'footer']);
        $this->assertStringNotContainsString('site-footer__heading', $without);
    }

    public function testContactHeadingRendersOnlyWhenLabelAndContactSet(): void
    {
        // Label set but no contact -> no contact block, so no heading either.
        $noContact = $this->renderFooter(['location' => 'footer', 'contact_label' => 'Contact']);
        $this->assertStringNotContainsString('site-footer__contact', $noContact);
        $this->assertStringNotContainsString('site-footer__heading', $noContact);

        // Label + contact -> heading appears inside the contact block.
        $both = $this->renderFooter([
            'location'      => 'footer',
            'contact'       => 'hello@example.com',
            'contact_label' => 'Contact',
        ]);
        $this->assertStringContainsString('site-footer__contact', $both);
        $this->assertStringContainsString('site-footer__heading', $both);
        $this->assertStringContainsString('Contact', $both);
    }

    // ── Render: delimited bottom bar ────────────────────────────────────────

    public function testNoteTriggersDelimitedBottomBarWithSingleCopyright(): void
    {
        $html = $this->renderFooter([
            'location'  => 'footer',
            'note'      => 'Made with care.',
            'copyright' => 'Custom 2026.',
        ]);
        $this->assertStringContainsString('site-footer__bottom', $html);
        $this->assertStringContainsString('site-footer__note', $html);
        $this->assertStringContainsString('Made with care.', $html);
        // Copyright is MOVED into the bar, not duplicated: exactly one occurrence.
        $this->assertSame(1, substr_count($html, 'site-footer__copyright'));
        // ...and it renders inside the bottom bar (after the bar opens).
        $this->assertGreaterThan(
            strpos($html, 'site-footer__bottom'),
            strpos($html, 'site-footer__copyright'),
            'copyright must render inside the bottom bar when a note is set'
        );
    }

    public function testNoBottomBarWhenNoteEmptyCopyrightStaysInline(): void
    {
        $html = $this->renderFooter(['location' => 'footer', 'copyright' => 'Custom 2026.']);
        $this->assertStringNotContainsString('site-footer__bottom', $html);
        $this->assertStringNotContainsString('site-footer__note', $html);
        $this->assertStringContainsString('site-footer__copyright', $html);
        $this->assertStringContainsString('Custom 2026.', $html);
    }

    // ── Render: footer logo override + fallback ─────────────────────────────

    public function testFooterLogoOverrideUsesTheOverrideAttachment(): void
    {
        // base.php passes pp_footer_logo_id as the footer's logo_id prop.
        $this->seedImage(57, 'http://example.com/footer-light.png');
        $html = $this->renderFooter(['location' => 'footer', 'show_logo' => true, 'logo_id' => 57]);
        $this->assertStringContainsString('http://example.com/footer-light.png', $html);
        $this->assertStringContainsString('site-footer__logo-image', $html);
    }

    public function testFooterLogoFallsBackToPpLogoIdWhenOverrideUnset(): void
    {
        // Empty logo_id prop (what base.php passes when pp_footer_logo_id is unset)
        // -> pp_resolve_logo falls back to the pp_logo_id site option.
        $this->seedImage(42, 'http://example.com/brand.png');
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = '42';
        $html = $this->renderFooter(['location' => 'footer', 'show_logo' => true, 'logo_id' => '']);
        $this->assertStringContainsString('http://example.com/brand.png', $html);
    }

    // ── Escaping ────────────────────────────────────────────────────────────

    public function testStructureContentIsEscaped(): void
    {
        $html = $this->renderFooter([
            'location'      => 'footer',
            'menu_label'    => '<script>alert(1)</script>',
            'contact'       => 'x',
            'contact_label' => 'A & B',
            'note'          => '<b>hi</b>',
        ]);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('A &amp; B', $html);
        $this->assertStringNotContainsString('<b>hi</b>', $html);
    }

    // ── Unset footer carries no new structure (byte-identical to issue 300) ──

    public function testUnsetFooterHasNoStructureElements(): void
    {
        $html = $this->renderFooter(['location' => 'footer']);
        $this->assertStringNotContainsString('site-footer__heading', $html);
        $this->assertStringNotContainsString('site-footer__bottom', $html);
        $this->assertStringNotContainsString('site-footer__note', $html);
    }

    // ── CSS: neutral, token-routed structure styling ────────────────────────

    public function testFooterHeadingRoutesThroughTextSlot(): void
    {
        // Neutral: no baked color — inherits the footer text color via --footer-text.
        $block = $this->cssRuleBlock('.site-footer__heading');
        $this->assertNotNull($block);
        $this->assertMatchesRegularExpression('/color:\s*var\(--footer-text,\s*inherit\)/', $block);
    }

    public function testFooterBottomBarDividerUsesTheBorderToken(): void
    {
        // The "delimited" band reuses --color-border (the footer's own border token),
        // not a baked color/size opinion.
        $block = $this->cssRuleBlock('.site-footer__bottom');
        $this->assertNotNull($block);
        $this->assertMatchesRegularExpression('/border-top:\s*1px solid var\(--color-border\)/', $block);
    }

    public function testFooterNoteRoutesThroughTextSlot(): void
    {
        $block = $this->cssRuleBlock('.site-footer__note');
        $this->assertNotNull($block);
        $this->assertMatchesRegularExpression('/color:\s*var\(--footer-text,\s*var\(--color-muted\)\)/', $block);
    }

    public function testBaseTemplateMapsEveryFooterStructureOption(): void
    {
        $base = file_get_contents($this->themeRoot . '/templates/base.php');
        foreach (['pp_footer_menu_label', 'pp_footer_contact_label',
                  'pp_footer_note', 'pp_footer_logo_id'] as $opt) {
            $this->assertStringContainsString(
                "get_option('{$opt}'",
                $base,
                "templates/base.php must read the {$opt} site option and pass it to the footer."
            );
        }
    }
}
