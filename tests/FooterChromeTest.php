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
        // The email is now an actionable mailto: link (issue 427), still followed
        // by the nl2br line break before the next line.
        $this->assertMatchesRegularExpression(
            '/<a href="mailto:hello@example\.com">hello@example\.com<\/a><br\s*\/?>/',
            $html
        );
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

    // ══════════════════════════════════════════════════════════════════════
    //  Footer BASELINE (issue 427): nav landmark, consistent headings, column
    //  grid + mobile stack, actionable <address> contact (email/phone links),
    //  graceful sparse-config degradation, and the #382 social landing slot.
    //  Additive to #300/#335; every option contract unchanged, unset output
    //  still emits nothing option-driven.
    // ══════════════════════════════════════════════════════════════════════

    // ── Semantics: nav landmark + heading level ─────────────────────────────

    public function testFooterNavIsAnAriaLabelledLandmark(): void
    {
        // The footer menu must be a real <nav> carrying an aria-label, distinct
        // from the header nav's "Main navigation", so AT users can tell them apart.
        $html = $this->renderFooter(['location' => 'footer']);
        $this->assertMatchesRegularExpression(
            '/<nav class="site-footer__nav" aria-label="Footer navigation">/',
            $html
        );
        // Header/footer labels must not collide.
        $this->assertStringNotContainsString('aria-label="Main navigation"', $html);
    }

    public function testColumnHeadingsUseOneConsistentLevel(): void
    {
        // Both the menu heading and the contact heading render at the SAME level
        // and class (h2.site-footer__heading) — no mixed hierarchy.
        $html = $this->renderFooter([
            'location'      => 'footer',
            'menu_label'    => 'Legal',
            'contact'       => 'hi@example.com',
            'contact_label' => 'Contact',
        ]);
        $this->assertSame(
            2,
            preg_match_all('/<h2 class="site-footer__heading">/', $html),
            'both column headings must be h2.site-footer__heading'
        );
        // No other heading level is used for the columns.
        $this->assertDoesNotMatchRegularExpression('/<h[13-6][^>]*class="site-footer__heading"/', $html);
    }

    // ── Layout: column grid wrapper + graceful sparse degradation ───────────

    public function testColumnsWrapperGroupsBrandNavContact(): void
    {
        $html = $this->renderFooter([
            'location' => 'footer',
            'blurb'    => 'Brand line.',
            'contact'  => 'hi@example.com',
        ]);
        $this->assertStringContainsString('site-footer__columns', $html);
        // DOM order inside the grid reads brand -> nav -> contact.
        $brand   = strpos($html, 'site-footer__brand');
        $nav     = strpos($html, 'site-footer__nav');
        $contact = strpos($html, 'site-footer__contact');
        $this->assertNotFalse($brand);
        $this->assertLessThan($nav, $brand, 'brand column comes before the nav column');
        $this->assertLessThan($contact, $nav, 'nav column comes before the contact column');
    }

    public function testSparseFooterHasNoEmptyStructuralColumns(): void
    {
        // Minimal config: no brand, no contact. The nav column is the only one,
        // and there must be no empty brand/contact column left behind.
        $html = $this->renderFooter(['location' => 'footer']);
        $this->assertStringContainsString('site-footer__columns', $html);
        $this->assertStringContainsString('site-footer__nav', $html);
        $this->assertStringNotContainsString('site-footer__brand', $html);
        $this->assertStringNotContainsString('site-footer__contact', $html);
        // No empty column div/element with only whitespace inside.
        $this->assertDoesNotMatchRegularExpression('/<div class="site-footer__brand">\s*<\/div>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<div class="site-footer__contact">\s*<\/div>/', $html);
    }

    public function testColumnsWrapperIsNeverEmpty(): void
    {
        // The wrapper is unconditional, so it must always contain the always-present
        // nav column — never an empty grid container.
        $html = $this->renderFooter(['location' => 'footer']);
        $this->assertDoesNotMatchRegularExpression('/<div class="site-footer__columns">\s*<\/div>/', $html);
    }

    // ── Actionable contact: <address> + conservative email/phone auto-link ──

    public function testContactRendersInsideAddress(): void
    {
        $html = $this->renderFooter(['location' => 'footer', 'contact' => 'Say hi.']);
        $this->assertMatchesRegularExpression('/<address class="site-footer__address">/', $html);
        $this->assertStringContainsString('</address>', $html);
    }

    public function testContactEmailBecomesMailtoLink(): void
    {
        $html = $this->renderFooter(['location' => 'footer', 'contact' => 'Email hello@example.com today']);
        $this->assertStringContainsString('<a href="mailto:hello@example.com">hello@example.com</a>', $html);
        // Surrounding plain text is untouched.
        $this->assertStringContainsString('Email ', $html);
        $this->assertStringContainsString(' today', $html);
    }

    public function testContactInternationalPhoneBecomesTelLink(): void
    {
        $html = $this->renderFooter(['location' => 'footer', 'contact' => 'Call +1 (555) 123-4567']);
        // The tel: target is normalized to "+" + digits; the display keeps its formatting.
        $this->assertStringContainsString('<a href="tel:+15551234567">+1 (555) 123-4567</a>', $html);
    }

    public function testContactDomesticNumbersAndTextPassThroughUntouched(): void
    {
        // Conservative parser: without a leading "+", a phone-shaped string is NOT
        // linked, so order numbers, postcodes, and dates never get mangled.
        foreach ([
            '555-123-4567',          // domestic phone, no +
            'Order 100002345',       // order number
            'San Francisco CA 94103',// city + zip
            'Open Mon-Fri 9-5',      // hours
        ] as $text) {
            $html = $this->renderFooter(['location' => 'footer', 'contact' => $text]);
            $this->assertStringNotContainsString('<a href="tel:', $html, "must not link: {$text}");
            $this->assertStringNotContainsString('<a href="mailto:', $html, "must not link: {$text}");
        }
    }

    public function testPlainContactIsByteIdenticalToPre427Rendering(): void
    {
        // Text with no email/phone must render exactly as before (esc_html + nl2br),
        // just inside the new <address> wrapper.
        $contact = "123 Market St\nSuite 400";
        $html = $this->renderFooter(['location' => 'footer', 'contact' => $contact]);
        $inner = pp_footer_linkify_contact($contact);
        $this->assertSame(nl2br(esc_html($contact)), $inner, 'plain contact must equal the pre-427 escaping');
        $this->assertStringContainsString('<address class="site-footer__address">' . $inner . '</address>', $html);
    }

    public function testContactLinkifyIsConservativeAtBoundariesAndNewlines(): void
    {
        // A "+"-number glued to an identifier is NOT a phone (left boundary).
        $html = $this->renderFooter(['location' => 'footer', 'contact' => 'SKU+1234567 in stock']);
        $this->assertStringNotContainsString('<a href="tel:', $html);

        // A number immediately followed by letters is NOT a phone (right boundary).
        $html = $this->renderFooter(['location' => 'footer', 'contact' => 'ref +1234567abc']);
        $this->assertStringNotContainsString('<a href="tel:', $html);

        // The phone separators exclude newlines, so digits split across two address
        // lines are never fused into one tel: link.
        $this->assertStringNotContainsString('<a href="tel:', pp_footer_linkify_contact("+1\n5551234"));

        // Positive control: a clean international number still links.
        $ok = pp_footer_linkify_contact('Call +1 (555) 123-4567 today');
        $this->assertStringContainsString('<a href="tel:+15551234567">+1 (555) 123-4567</a>', $ok);
    }

    public function testContactLinkifyEscapesBeforeLinking(): void
    {
        // Escaping runs FIRST, so injected markup can never be reintroduced, and an
        // adjacent HTML-special char cannot bleed into a link.
        $html = $this->renderFooter([
            'location' => 'footer',
            'contact'  => '<b>x</b> hello@example.com & more',
        ]);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('&amp; more', $html);
        // The email still links cleanly around the escaped neighbours.
        $this->assertStringContainsString('<a href="mailto:hello@example.com">hello@example.com</a>', $html);
    }

    // ── CSS: column grid mechanism, address reset, #382 landing slot ────────

    public function testFooterColumnsUseAGridMechanism(): void
    {
        // Mobile base: a grid (single-column stack). Desktop: the auto-flow column
        // mechanism that makes one equal track per present column.
        $base = $this->cssRuleBlock('.site-footer__columns');
        $this->assertNotNull($base);
        $this->assertMatchesRegularExpression('/display:\s*grid/', $base);

        $css = $this->css();
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\)\s*\{\s*\.site-footer__columns\s*\{[^}]*grid-auto-flow:\s*column/s',
            $css,
            'desktop columns must use grid-auto-flow: column'
        );
        $this->assertMatchesRegularExpression(
            '/\.site-footer__columns\s*\{[^}]*grid-auto-columns:\s*minmax\(0,\s*1fr\)/s',
            $css,
            'columns must be equal minmax(0, 1fr) tracks'
        );
    }

    public function testFooterAddressResetsItalicAndRoutesLinkColor(): void
    {
        $addr = $this->cssRuleBlock('.site-footer__address');
        $this->assertNotNull($addr);
        $this->assertMatchesRegularExpression('/font-style:\s*normal/', $addr);

        $link = $this->cssRuleBlock('.site-footer__address a');
        $this->assertNotNull($link);
        $this->assertMatchesRegularExpression(
            '/color:\s*var\(--footer-link-color,\s*var\(--color-muted\)\)/',
            $link
        );
    }

    public function testSocialLandingSlotExistsForIssue382(): void
    {
        // The #382 social-icon row is NOT built here, but its designed home must
        // exist so #382 lands cleanly. This is the pinned landing slot.
        $block = $this->cssRuleBlock('.site-footer__social');
        $this->assertNotNull($block, '.site-footer__social landing slot must exist for #382');
        $this->assertMatchesRegularExpression('/display:\s*flex/', $block);
        // The footer must NOT already emit the social markup (that is #382).
        $html = $this->renderFooter(['location' => 'footer', 'blurb' => 'x', 'contact' => 'x']);
        $this->assertStringNotContainsString('site-footer__social', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Second footer menu column (issue 469). A generic second footer nav
    //  location (footer_secondary) with its own optional heading option
    //  (pp_footer_secondary_label). The "Legal" column is one use of this,
    //  not the capability. Renders ONLY when a menu is assigned to the
    //  location; legacy single-menu footers stay byte-identical when unset.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Assigns a (stub) menu to a theme location so has_nav_menu() reports it present.
     *
     * Registers the location as well as assigning it (#582): the bootstrap's
     * has_nav_menu() stub now mirrors WordPress core, which returns false for a
     * location the theme never registered no matter what get_nav_menu_locations()
     * says. functions.php registers footer_secondary on a real site, so seeding
     * both is what actually models one.
     */
    private function assignMenuLocation(string $location, int $menuId = 9): void
    {
        // Seed from get_registered_nav_menus() first: the stub only falls back to the
        // primary/footer defaults while the key is ABSENT, so writing straight into it
        // would silently unregister those two for the rest of the test.
        $GLOBALS['_pp_test_store']['registered_nav_menus'] ??= get_registered_nav_menus();
        $GLOBALS['_pp_test_store']['registered_nav_menus'][$location] = $location;
        $GLOBALS['_pp_test_store']['nav_menu_locations'][$location]   = $menuId;
    }

    public function testAllowedSiteOptionsIncludesSecondaryLabel(): void
    {
        $allowed = pp_allowed_site_options();
        $this->assertSame('string', $allowed['pp_footer_secondary_label']);
    }

    public function testSecondaryLabelAcceptsFreeText(): void
    {
        $this->assertTrue(pp_validate_site_option_value('pp_footer_secondary_label', 'Legal'));
        $this->assertTrue(pp_validate_site_option_value('pp_footer_secondary_label', ''));
    }

    public function testSecondaryColumnRendersOnlyWhenMenuAssigned(): void
    {
        // No menu assigned to the secondary location → no second nav column.
        $without = $this->renderFooter([
            'location'           => 'footer',
            'secondary_location' => 'footer_secondary',
            'secondary_label'    => 'Legal',
        ]);
        $this->assertSame(
            1,
            preg_match_all('/class="site-footer__nav"/', $without),
            'with no menu assigned to footer_secondary, only the primary footer nav renders'
        );
        $this->assertStringNotContainsString('Footer secondary navigation', $without);

        // Assign a menu → the second nav column renders.
        $this->assignMenuLocation('footer_secondary');
        $with = $this->renderFooter([
            'location'           => 'footer',
            'secondary_location' => 'footer_secondary',
            'secondary_label'    => 'Legal',
        ]);
        $this->assertSame(
            2,
            preg_match_all('/class="site-footer__nav"/', $with),
            'an assigned footer_secondary menu adds a second footer nav column'
        );
    }

    public function testSecondaryColumnHasDistinctAriaLabel(): void
    {
        $this->assignMenuLocation('footer_secondary');
        $html = $this->renderFooter([
            'location'           => 'footer',
            'secondary_location' => 'footer_secondary',
        ]);
        // Both navs are landmarks; their labels must differ so AT users can tell
        // the two footer menus apart.
        $this->assertMatchesRegularExpression(
            '/<nav class="site-footer__nav" aria-label="Footer navigation">/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<nav class="site-footer__nav" aria-label="Footer secondary navigation">/',
            $html
        );
    }

    public function testSecondaryColumnIsHeadlessWhenLabelUnset(): void
    {
        // Menu assigned but no label → the column renders, but with no heading
        // (the same headless-when-unset rule as the primary menu column).
        $this->assignMenuLocation('footer_secondary');
        $html = $this->renderFooter([
            'location'           => 'footer',
            'secondary_location' => 'footer_secondary',
        ]);
        $this->assertStringContainsString('Footer secondary navigation', $html);
        $this->assertStringNotContainsString('site-footer__heading', $html);
    }

    public function testSecondaryColumnHeadingRendersWhenLabelSet(): void
    {
        $this->assignMenuLocation('footer_secondary');
        $html = $this->renderFooter([
            'location'           => 'footer',
            'secondary_location' => 'footer_secondary',
            'secondary_label'    => 'Legal',
        ]);
        $this->assertMatchesRegularExpression(
            '/<nav class="site-footer__nav" aria-label="Footer secondary navigation">\s*<h2 class="site-footer__heading">Legal<\/h2>/',
            $html
        );
    }

    public function testSecondaryLabelIsEscaped(): void
    {
        $this->assignMenuLocation('footer_secondary');
        $html = $this->renderFooter([
            'location'           => 'footer',
            'secondary_location' => 'footer_secondary',
            'secondary_label'    => '<script>alert(1)</script>',
        ]);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testUnassignedSecondaryLocationIsByteIdenticalToLegacyFooter(): void
    {
        // Passing secondary_location (as base.php always does) with no menu assigned
        // must be byte-for-byte identical to omitting it entirely.
        $legacy = $this->renderFooter(['location' => 'footer']);
        $withSecondary = $this->renderFooter([
            'location'           => 'footer',
            'secondary_location' => 'footer_secondary',
            'secondary_label'    => 'Legal',
        ]);
        $this->assertSame($legacy, $withSecondary);

        // Guard against the whitespace-drift class the new-vs-new comparison above
        // cannot see (both branches share footer.php): an unrendered secondary column
        // must leak NO extra template whitespace. The single-menu footer never emits a
        // whitespace-only line wider than the 12-space column indent; the buggy first
        // cut of #469 emitted a 24-space orphan line here. A whitespace-only line of
        // 13+ chars is that artifact — the if/endif must stay at column 0.
        $this->assertDoesNotMatchRegularExpression(
            '/^[ \t]{13,}$/m',
            $withSecondary,
            'An unassigned secondary footer column must not leak template whitespace '
            . '(keep the footer_secondary if/endif at column 0 in footer.php).'
        );
    }

    public function testSecondaryColumnDomOrderIsBetweenNavAndContact(): void
    {
        $this->assignMenuLocation('footer_secondary');
        $html = $this->renderFooter([
            'location'           => 'footer',
            'secondary_location' => 'footer_secondary',
            'secondary_label'    => 'Legal',
            'contact'            => 'hi@example.com',
            'contact_label'      => 'Contact',
        ]);
        $primaryNav = strpos($html, 'aria-label="Footer navigation"');
        $secondNav  = strpos($html, 'aria-label="Footer secondary navigation"');
        $contact    = strpos($html, 'site-footer__contact');
        $this->assertNotFalse($primaryNav);
        $this->assertNotFalse($secondNav);
        $this->assertNotFalse($contact);
        $this->assertLessThan($secondNav, $primaryNav, 'secondary nav follows the primary nav');
        $this->assertLessThan($contact, $secondNav, 'secondary nav precedes the contact column');
    }

    public function testBaseTemplateMapsSecondaryFooterOptions(): void
    {
        $base = file_get_contents($this->themeRoot . '/templates/base.php');
        $this->assertStringContainsString(
            "get_option('pp_footer_secondary_label'",
            $base,
            'base.php must read the pp_footer_secondary_label site option and pass it to the footer.'
        );
        $this->assertStringContainsString(
            "'secondary_location' => 'footer_secondary'",
            $base,
            'base.php must pass the fixed footer_secondary location slug to the footer.'
        );
    }

    public function testFunctionsRegistersSecondaryFooterLocation(): void
    {
        $functions = file_get_contents($this->themeRoot . '/functions.php');
        $this->assertMatchesRegularExpression(
            "/register_nav_menus\(.*'footer_secondary'\s*=>/s",
            $functions,
            'functions.php must register the footer_secondary theme location so assign_menu_location / set_menu accept it.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Footer SOCIAL-ICON row (issue 382). pp_footer_social is the only
    //  structured site option: a JSON list of {network, url} from a CLOSED
    //  network set, rendered as accessible inline-SVG icon links in the
    //  reserved .site-footer__social slot (#427). Unknown networks / non-URL
    //  values are rejected with the standard envelope; unset = byte-identical
    //  footer. Glyphs ship inline (no icon font, no external requests).
    // ══════════════════════════════════════════════════════════════════════

    private const SOCIAL_VALID = '[{"network":"x","url":"https://x.com/acme"},{"network":"linkedin","url":"https://linkedin.com/company/acme"}]';

    // ── Whitelist + closed network set ──────────────────────────────────────

    public function testAllowedSiteOptionsIncludesSocialAsStructuredType(): void
    {
        $this->assertSame('social', pp_allowed_site_options()['pp_footer_social']);
    }

    public function testNetworkMapIsTheSingleSourceWithLabelAndGlyph(): void
    {
        // Every accepted network MUST have a human label (the accessible name) and
        // a non-empty single-path glyph — validation and rendering both key off this
        // one map, so an accepted network can never be un-renderable.
        $networks = pp_footer_social_networks();
        $this->assertNotEmpty($networks);
        foreach (['x', 'linkedin', 'facebook', 'instagram', 'youtube', 'github', 'tiktok', 'mastodon'] as $slug) {
            $this->assertArrayHasKey($slug, $networks, "network '{$slug}' must be in the closed set");
            $this->assertNotSame('', trim($networks[$slug]['label']), "network '{$slug}' needs a label");
            $this->assertNotSame('', trim($networks[$slug]['path']), "network '{$slug}' needs a glyph path");
        }
    }

    // ── Validation: accepted shapes ─────────────────────────────────────────

    public function testValidSocialListIsAccepted(): void
    {
        $this->assertTrue(pp_validate_site_option_value('pp_footer_social', self::SOCIAL_VALID));
        // Every closed-set network is individually acceptable.
        foreach (array_keys(pp_footer_social_networks()) as $slug) {
            $json = '[{"network":"' . $slug . '","url":"https://example.com/' . $slug . '"}]';
            $this->assertTrue(
                pp_validate_site_option_value('pp_footer_social', $json),
                "network '{$slug}' should be accepted"
            );
        }
    }

    public function testEmptyStringClearsTheRow(): void
    {
        // '' is a valid CLEAR (unset the row), distinct from a malformed value.
        $this->assertTrue(pp_validate_site_option_value('pp_footer_social', ''));
        $this->assertTrue(pp_validate_site_option_value('pp_footer_social', '   '));
    }

    public function testExternalProfileUrlIsNotRejectedAsOffSite(): void
    {
        // These are EXTERNAL profile URLs — the same-site redirect validator must
        // NOT be reused. A cross-origin https URL is exactly what's expected.
        $this->assertTrue(pp_validate_site_option_value(
            'pp_footer_social',
            '[{"network":"github","url":"https://github.com/some-org"}]'
        ));
    }

    // ── Validation: rejected shapes (standard envelope) ─────────────────────

    public function testUnknownNetworkIsRejected(): void
    {
        $result = pp_validate_site_option_value(
            'pp_footer_social',
            '[{"network":"myspace","url":"https://myspace.com/acme"}]'
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_option_value', $result->get_error_code());
        $this->assertStringContainsString('myspace', $result->get_error_message());
    }

    public function testNonUrlAndNonHttpSchemesAreRejected(): void
    {
        foreach ([
            '[{"network":"x","url":"not a url"}]',
            '[{"network":"x","url":"javascript:alert(1)"}]',
            '[{"network":"x","url":"data:text/html,evil"}]',
            '[{"network":"x","url":"//x.com/acme"}]',            // protocol-relative
            '[{"network":"x","url":"ftp://x.com/acme"}]',        // non-http scheme
            '[{"network":"x","url":""}]',                        // empty url
        ] as $json) {
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_validate_site_option_value('pp_footer_social', $json),
                "must reject: {$json}"
            );
        }
    }

    public function testMalformedAndWrongShapeJsonIsRejected(): void
    {
        foreach ([
            'not json at all',
            '{"network":"x","url":"https://x.com"}',                 // top-level object, not a list
            '[]',                                                    // empty list
            '[["x","https://x.com"]]',                               // list-shaped child, not an object
            '[{"url":"https://x.com/acme"}]',                        // missing network
            '[{"network":"x"}]',                                     // missing url
            '[{"network":123,"url":"https://x.com"}]',               // non-string network
            '[{"network":"x","url":42}]',                            // non-string url
        ] as $json) {
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_validate_site_option_value('pp_footer_social', $json),
                "must reject: {$json}"
            );
        }
    }

    public function testTooManyEntriesAreRejected(): void
    {
        $entries = array_fill(0, PP_FOOTER_SOCIAL_MAX + 1, '{"network":"x","url":"https://x.com/a"}');
        $json = '[' . implode(',', $entries) . ']';
        $result = pp_validate_site_option_value('pp_footer_social', $json);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ── Write path: canonicalize + round-trip; reject leaves nothing stored ──

    public function testWriteStoresCanonicalFormAndRoundTrips(): void
    {
        // Extra keys are dropped, url is trimmed, output re-validates (the
        // snapshot/rollback path re-applies the stored value).
        $noisy = '[{"network":"x","url":"  https://x.com/acme  ","extra":"drop me"}]';
        $this->assertTrue(pp_update_site_option('pp_footer_social', $noisy));
        $stored = get_option('pp_footer_social');
        $this->assertStringNotContainsString('drop me', $stored, 'extra keys must be stripped');
        $this->assertStringContainsString('https://x.com/acme', $stored);
        $this->assertStringNotContainsString('  https', $stored, 'url must be trimmed');
        $this->assertTrue(
            pp_validate_site_option_value('pp_footer_social', $stored),
            'canonical stored value must survive a re-validation round-trip'
        );
    }

    public function testEmptyWriteClearsStoredValue(): void
    {
        $this->assertTrue(pp_update_site_option('pp_footer_social', self::SOCIAL_VALID));
        $this->assertNotSame('', get_option('pp_footer_social'));
        $this->assertTrue(pp_update_site_option('pp_footer_social', ''));
        $this->assertSame('', (string) get_option('pp_footer_social'));
    }

    public function testWriteRejectsInvalidAndStoresNothing(): void
    {
        $result = pp_update_site_option('pp_footer_social', '[{"network":"nope","url":"https://x.com"}]');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertArrayNotHasKey('pp_footer_social', $GLOBALS['_pp_test_store']['options']);
    }

    public function testActionPathValidatesSocial(): void
    {
        $ok = pp_execute_action('update_site_option', ['key' => 'pp_footer_social', 'value' => self::SOCIAL_VALID]);
        $this->assertTrue($ok['ok'], 'valid social list should pass the action');
        $bad = pp_execute_action('update_site_option', ['key' => 'pp_footer_social', 'value' => '[{"network":"nope","url":"https://x.com"}]']);
        $this->assertFalse($bad['ok'], 'unknown network should fail the action');
    }

    // ── Snapshot / rollback covers pp_footer_social like other footer options ─

    public function testSnapshotRollbackRestoresSocialOption(): void
    {
        // Baseline SET → change → rollback restores the prior value verbatim.
        pp_update_site_option('pp_footer_social', self::SOCIAL_VALID);
        $baseline = get_option('pp_footer_social');
        $steps = [['name' => 'update_site_option', 'params' => ['key' => 'pp_footer_social', 'value' => self::SOCIAL_VALID]]];
        $snapshot = _pp_snapshot_batch_targets($steps);
        pp_update_site_option('pp_footer_social', '[{"network":"github","url":"https://github.com/x"}]');
        _pp_restore_batch_snapshot($snapshot);
        $this->assertSame($baseline, (string) get_option('pp_footer_social'), 'rollback must restore the prior social value');

        // Baseline UNSET → set → rollback deletes it back to unset.
        delete_option('pp_footer_social');
        $snapshot2 = _pp_snapshot_batch_targets($steps);
        pp_update_site_option('pp_footer_social', self::SOCIAL_VALID);
        _pp_restore_batch_snapshot($snapshot2);
        $this->assertSame('', (string) get_option('pp_footer_social'), 'rollback of an unset baseline must clear the option');
    }

    // ── Render: the icon row (links, aria, decorative SVG) ──────────────────

    public function testSocialRowRendersAccessibleIconLinks(): void
    {
        $html = $this->renderFooter(['location' => 'footer', 'social' => self::SOCIAL_VALID]);
        $this->assertStringContainsString('site-footer__social', $html);
        // Exactly two links, with the right hrefs and per-link accessible names.
        $this->assertSame(2, preg_match_all('/class="site-footer__social-link"/', $html));
        $this->assertStringContainsString('href="https://x.com/acme"', $html);
        $this->assertStringContainsString('href="https://linkedin.com/company/acme"', $html);
        $this->assertStringContainsString('aria-label="X"', $html);
        $this->assertStringContainsString('aria-label="LinkedIn"', $html);
        // External-profile hardening.
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        // The SVG glyph is decorative — the accessible name comes from the link.
        $this->assertMatchesRegularExpression('/<svg[^>]*aria-hidden="true"/', $html);
    }

    public function testSocialRowRendersInsideBrandColumnEvenWithoutLogoOrBlurb(): void
    {
        // The row's home is the brand column; a social-only footer must still emit
        // the brand column (the render condition includes social).
        $html = $this->renderFooter(['location' => 'footer', 'social' => self::SOCIAL_VALID]);
        $brand  = strpos($html, 'site-footer__brand');
        $social = strpos($html, 'site-footer__social');
        $this->assertNotFalse($brand, 'social-only footer must still render the brand column');
        $this->assertNotFalse($social);
        $this->assertLessThan($social, $brand, 'the social row lives inside the brand column');
    }

    public function testSocialRowSkipsUnknownNetworksDefensively(): void
    {
        // A hand-edited DB value whose network is not in the closed map must never
        // emit broken markup — the render skips it (validation is the gate, the
        // render is defense in depth).
        $html = $this->renderFooter([
            'location' => 'footer',
            'social'   => '[{"network":"x","url":"https://x.com/acme"},{"network":"ghost","url":"https://ghost.example/x"}]',
        ]);
        $this->assertSame(1, preg_match_all('/class="site-footer__social-link"/', $html), 'only the known network renders');
        $this->assertStringNotContainsString('ghost.example', $html);
    }

    public function testSocialUrlIsRejectedBeforeItCanReachTheRender(): void
    {
        // The primary XSS gate is validation: a URL carrying attribute-breaking
        // characters is rejected by filter_var before it can ever be stored.
        $this->assertInstanceOf(
            \WP_Error::class,
            pp_validate_site_option_value(
                'pp_footer_social',
                '[{"network":"x","url":"https://x.com/\"><script>alert(1)</script>"}]'
            )
        );
    }

    public function testSocialRenderRoutesHrefAndLabelThroughEscapers(): void
    {
        // Defense in depth: the template MUST wrap the href in esc_url and the
        // aria-label in esc_attr (WP core esc_url is the production escaping
        // boundary; the PHPUnit stub is deliberately naive, so this is a source
        // contract, not an output assertion).
        $footer = file_get_contents($this->themeRoot . '/components/footer/footer.php');
        $this->assertMatchesRegularExpression(
            "/href=\"<\?php echo esc_url\(\\\$social_item\['url'\]\); \?>\"/",
            $footer,
            'the social link href must be routed through esc_url'
        );
        $this->assertMatchesRegularExpression(
            "/aria-label=\"<\?php echo esc_attr\(\\\$social_item\['label'\]\); \?>\"/",
            $footer,
            'the social link aria-label must be routed through esc_attr'
        );
    }

    // ── Unset footer: byte-identical, no whitespace artifact ────────────────

    public function testUnsetSocialLeavesFooterByteIdentical(): void
    {
        // Passing an empty social prop (what base.php passes when pp_footer_social is
        // unset) must be byte-for-byte identical to omitting it entirely, EVEN when a
        // brand column is present (blurb set) — the social if/endif must leak no
        // template whitespace (the #469 discipline: keep it at column 0).
        $legacy = $this->renderFooter(['location' => 'footer', 'blurb' => 'Brand line.']);
        $withEmptySocial = $this->renderFooter(['location' => 'footer', 'blurb' => 'Brand line.', 'social' => '']);
        $this->assertSame($legacy, $withEmptySocial);
        // No whitespace-only line wider than the normal column indent (the buggy
        // shape would orphan a deep-indented blank line where the row would go).
        $this->assertDoesNotMatchRegularExpression(
            '/^[ \t]{25,}$/m',
            $withEmptySocial,
            'an unset social row must not leak template whitespace (keep the if/endif at column 0).'
        );
    }

    public function testUnsetFooterEmitsNoSocialMarkup(): void
    {
        $html = $this->renderFooter(['location' => 'footer', 'blurb' => 'x', 'contact' => 'x']);
        $this->assertStringNotContainsString('site-footer__social', $html);
    }

    // ── base.php mapping + CSS slot contract ────────────────────────────────

    public function testBaseTemplateMapsSocialOption(): void
    {
        $base = file_get_contents($this->themeRoot . '/templates/base.php');
        $this->assertStringContainsString(
            "get_option('pp_footer_social'",
            $base,
            'base.php must read the pp_footer_social site option and pass it to the footer.'
        );
    }

    public function testSocialRowCssResetsListAndRoutesLinkColor(): void
    {
        // The row is a <ul>, so the list chrome must be reset; the link color routes
        // through the footer link slot (muted fallback) exactly like the nav links.
        $slot = $this->cssRuleBlock('.site-footer__social');
        $this->assertNotNull($slot);
        $this->assertMatchesRegularExpression('/display:\s*flex/', $slot);
        $this->assertMatchesRegularExpression('/list-style:\s*none/', $slot);

        $link = $this->cssRuleBlock('.site-footer__social-link');
        $this->assertNotNull($link);
        $this->assertMatchesRegularExpression(
            '/color:\s*var\(--footer-link-color,\s*var\(--color-muted\)\)/',
            $link
        );
    }
}
