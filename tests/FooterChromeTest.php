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
        $this->assertSame('color',  $allowed['pp_footer_bg']);
        $this->assertSame('color',  $allowed['pp_footer_text']);
        $this->assertSame('color',  $allowed['pp_footer_link_color']);
        $this->assertSame('string', $allowed['pp_footer_blurb']);
        $this->assertSame('string', $allowed['pp_footer_contact']);
        $this->assertSame('string', $allowed['pp_footer_copyright']);
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
        $this->assertMatchesRegularExpression(
            '/background-color:\s*var\(--footer-bg,\s*var\(--color-surface\)\)/',
            $block
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
}
