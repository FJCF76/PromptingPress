<?php
/**
 * tests/LogoTest.php
 *
 * PR1 (#106) — logo safe surface: site-option whitelist + attachment-id
 * validation (pp_allowed_site_options / pp_validate_site_option_value /
 * pp_update_site_option / update_site_option action) and the shared logo
 * resolver (pp_resolve_logo). Attachment-ID only — never a URL.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class LogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    /** Seed a Media Library image attachment with a resolvable URL and optional alt. */
    private function seedAttachment(int $id, string $url, string $alt = '', bool $isImage = true): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_urls'][$id] = $url;
        $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = $isImage;
        if ($alt !== '') {
            $GLOBALS['_pp_test_store']['post_meta'][$id]['_wp_attachment_image_alt'] = $alt;
        }
    }

    // ── Whitelist single source ─────────────────────────────────────────────

    public function testAllowedSiteOptionsIncludesLogoKeys(): void
    {
        $allowed = pp_allowed_site_options();
        $this->assertArrayHasKey('blogname', $allowed);
        $this->assertArrayHasKey('blogdescription', $allowed);
        $this->assertSame('attachment_id', $allowed['pp_logo_id']);
        $this->assertSame('string', $allowed['pp_logo_alt']);
    }

    public function testSiteOptionAcceptsLogoIdKey(): void
    {
        update_option('pp_logo_id', '42');
        $this->assertSame('42', pp_site_option('pp_logo_id'));
    }

    // ── Value validation (attachment-id only) ───────────────────────────────

    public function testValidateAcceptsRealAttachmentId(): void
    {
        $this->seedAttachment(42, 'https://example.com/logo.png');
        $this->assertTrue(pp_validate_site_option_value('pp_logo_id', '42'));
    }

    public function testValidateRejectsNonAttachmentId(): void
    {
        // ID 7 exists but is a page, not an attachment.
        $GLOBALS['_pp_test_store']['posts'][7] = ['post_type' => 'page'];
        $result = pp_validate_site_option_value('pp_logo_id', '7');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('attachment', $result->get_error_message());
    }

    public function testValidateRejectsZeroAndNonNumericLogoId(): void
    {
        $this->assertInstanceOf(\WP_Error::class, pp_validate_site_option_value('pp_logo_id', '0'));
        $this->assertInstanceOf(\WP_Error::class, pp_validate_site_option_value('pp_logo_id', 'https://evil.test/x.png'));
    }

    public function testValidateRejectsNonImageAttachment(): void
    {
        // ID 8 is an attachment but not an image (e.g. a PDF/video).
        $this->seedAttachment(8, 'https://example.com/doc.pdf', '', false);
        $result = pp_validate_site_option_value('pp_logo_id', '8');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('image', $result->get_error_message());
    }

    public function testValidateStringTypePassesThrough(): void
    {
        $this->assertTrue(pp_validate_site_option_value('pp_logo_alt', 'Acme logo'));
        $this->assertTrue(pp_validate_site_option_value('blogname', 'Anything'));
    }

    // ── pp_update_site_option ───────────────────────────────────────────────

    public function testUpdateRejectsNonAttachmentLogoId(): void
    {
        $result = pp_update_site_option('pp_logo_id', '999'); // 999 not in store
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertArrayNotHasKey('pp_logo_id', $GLOBALS['_pp_test_store']['options']);
    }

    public function testUpdateAcceptsValidAttachmentLogoId(): void
    {
        $this->seedAttachment(55, 'https://example.com/brand.png');
        $this->assertTrue(pp_update_site_option('pp_logo_id', '55'));
        $this->assertSame('55', get_option('pp_logo_id'));
    }

    public function testUpdateNormalizesStoredAttachmentId(): void
    {
        $this->seedAttachment(7, 'https://example.com/seven.png');
        $this->assertTrue(pp_update_site_option('pp_logo_id', '007'));
        $this->assertSame('7', get_option('pp_logo_id')); // normalized to canonical int string
    }

    public function testUpdateRejectsUnwhitelistedKey(): void
    {
        $result = pp_update_site_option('admin_email', 'x@y.test');
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ── update_site_option action (validate path) ───────────────────────────

    public function testActionRejectsNonAttachmentLogoIdWithClearMessage(): void
    {
        $result = pp_execute_action('update_site_option', ['key' => 'pp_logo_id', 'value' => '404']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('attachment', $result['error']);
    }

    public function testActionAcceptsValidAttachmentLogoId(): void
    {
        $this->seedAttachment(88, 'https://example.com/logo2.png');
        $result = pp_execute_action('update_site_option', ['key' => 'pp_logo_id', 'value' => '88']);
        $this->assertTrue($result['ok']);
        $this->assertSame('88', get_option('pp_logo_id'));
    }

    // ── pp_resolve_logo ─────────────────────────────────────────────────────

    public function testResolveLogoFromPropId(): void
    {
        $this->seedAttachment(10, 'https://example.com/a.png', 'Brand A');
        $logo = pp_resolve_logo(['logo_id' => 10, 'logo_text' => 'Site']);
        $this->assertSame('image', $logo['type']);
        $this->assertSame('https://example.com/a.png', $logo['url']);
        $this->assertSame('Brand A', $logo['alt']); // attachment alt metadata
    }

    public function testResolveLogoExplicitAltWins(): void
    {
        $this->seedAttachment(10, 'https://example.com/a.png', 'Meta Alt');
        $logo = pp_resolve_logo(['logo_id' => 10, 'logo_alt' => 'Explicit Alt']);
        $this->assertSame('Explicit Alt', $logo['alt']);
    }

    public function testResolveLogoAltFallsBackToText(): void
    {
        $this->seedAttachment(10, 'https://example.com/a.png'); // no alt meta
        $logo = pp_resolve_logo(['logo_id' => 10, 'logo_text' => 'Wordmark']);
        $this->assertSame('Wordmark', $logo['alt']);
    }

    public function testResolveLogoFromSiteOption(): void
    {
        $this->seedAttachment(20, 'https://example.com/opt.png');
        update_option('pp_logo_id', '20');
        $logo = pp_resolve_logo(['logo_text' => 'Site']);
        $this->assertSame('image', $logo['type']);
        $this->assertSame('https://example.com/opt.png', $logo['url']);
    }

    public function testResolveLogoFromCustomLogoThemeMod(): void
    {
        $this->seedAttachment(30, 'https://example.com/mod.png');
        $GLOBALS['_pp_test_store']['theme_mods']['custom_logo'] = 30;
        $logo = pp_resolve_logo(['logo_text' => 'Site']);
        $this->assertSame('image', $logo['type']);
        $this->assertSame('https://example.com/mod.png', $logo['url']);
    }

    public function testResolvePrecedencePropBeatsOptionBeatsThemeMod(): void
    {
        $this->seedAttachment(1, 'https://example.com/prop.png');
        $this->seedAttachment(2, 'https://example.com/opt.png');
        $this->seedAttachment(3, 'https://example.com/mod.png');
        update_option('pp_logo_id', '2');
        $GLOBALS['_pp_test_store']['theme_mods']['custom_logo'] = 3;

        $this->assertSame('https://example.com/prop.png', pp_resolve_logo(['logo_id' => 1])['url']);
        $this->assertSame('https://example.com/opt.png', pp_resolve_logo([])['url']);
    }

    public function testResolveTextWhenNoLogoAnywhere(): void
    {
        $logo = pp_resolve_logo(['logo_text' => 'Just Text']);
        $this->assertSame('text', $logo['type']);
        $this->assertSame('', $logo['url']);
        $this->assertSame('Just Text', $logo['text']);
    }

    public function testResolveTextWhenAttachmentHasNoUrl(): void
    {
        // ID is an attachment in the store but has no resolvable URL.
        $GLOBALS['_pp_test_store']['posts'][9] = ['post_type' => 'attachment'];
        $logo = pp_resolve_logo(['logo_id' => 9, 'logo_text' => 'Fallback']);
        $this->assertSame('text', $logo['type']);
        $this->assertSame('Fallback', $logo['text']);
    }
}
