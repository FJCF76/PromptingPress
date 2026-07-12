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

    // ── Footer show-logo site option (issue 234) ────────────────────────────

    public function testAllowedSiteOptionsIncludesFooterShowLogoAsBool(): void
    {
        $allowed = pp_allowed_site_options();
        $this->assertArrayHasKey('pp_footer_show_logo', $allowed);
        $this->assertSame('bool', $allowed['pp_footer_show_logo']);
    }

    public function testValidateBoolAcceptsCanonicalForms(): void
    {
        foreach (['1', '0', 'true', 'false', 'TRUE', 'False', ' true '] as $val) {
            $this->assertTrue(
                pp_validate_site_option_value('pp_footer_show_logo', $val),
                "Expected '{$val}' to be accepted as a boolean"
            );
        }
    }

    public function testValidateBoolRejectsNonBool(): void
    {
        foreach (['maybe', 'flase', '2', '', 'yes', 'on'] as $val) {
            $result = pp_validate_site_option_value('pp_footer_show_logo', $val);
            $this->assertInstanceOf(\WP_Error::class, $result, "Expected '{$val}' to be rejected");
            $this->assertStringContainsString('boolean', $result->get_error_message());
        }
    }

    public function testUpdateBoolNormalizesTrueFormsToCanonicalOne(): void
    {
        foreach (['1', 'true', 'TRUE', ' true '] as $val) {
            $this->assertTrue(pp_update_site_option('pp_footer_show_logo', $val));
            $this->assertSame('1', get_option('pp_footer_show_logo'), "'{$val}' should store as '1'");
        }
    }

    public function testUpdateBoolNormalizesFalseFormsToCanonicalZero(): void
    {
        foreach (['0', 'false', 'False'] as $val) {
            $this->assertTrue(pp_update_site_option('pp_footer_show_logo', $val));
            $this->assertSame('0', get_option('pp_footer_show_logo'), "'{$val}' should store as '0'");
        }
    }

    public function testStoredBoolValuesRoundTripThroughValidatingWriter(): void
    {
        // The snapshot/rollback path (lib/actions.php) re-applies the stored
        // value through pp_update_site_option — the canonical stored form must
        // itself pass validation. Regression guard for the '' OFF form (which
        // the validator rejects) that an earlier draft of issue 234 shipped.
        foreach (['1', '0'] as $stored) {
            $this->assertTrue(pp_validate_site_option_value('pp_footer_show_logo', $stored));
            $this->assertTrue(pp_update_site_option('pp_footer_show_logo', $stored));
            $this->assertSame($stored, get_option('pp_footer_show_logo'));
        }
    }

    public function testUpdateBoolRejectsNonBoolAndDoesNotWrite(): void
    {
        $result = pp_update_site_option('pp_footer_show_logo', 'maybe');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertArrayNotHasKey('pp_footer_show_logo', $GLOBALS['_pp_test_store']['options']);
    }

    public function testActionSetsFooterShowLogo(): void
    {
        $result = pp_execute_action('update_site_option', ['key' => 'pp_footer_show_logo', 'value' => 'true']);
        $this->assertTrue($result['ok']);
        $this->assertSame('1', get_option('pp_footer_show_logo'));
    }

    public function testActionRejectsNonBoolFooterShowLogoWithClearMessage(): void
    {
        $result = pp_execute_action('update_site_option', ['key' => 'pp_footer_show_logo', 'value' => 'nope']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('boolean', $result['error']);
    }

    // ── Footer render honors show_logo prop (base.php passes the option) ─────

    private function renderFooter(array $props): string
    {
        ob_start();
        pp_get_component('footer', $props);
        return ob_get_clean();
    }

    public function testFooterRendersLogoWhenShowLogoTrueAndLogoResolves(): void
    {
        $this->seedAttachment(70, 'https://example.com/footer-logo.png', 'Brand');
        update_option('pp_logo_id', '70');
        $html = $this->renderFooter(['location' => 'footer', 'show_logo' => true]);
        $this->assertStringContainsString('site-footer__logo', $html);
        $this->assertStringContainsString('https://example.com/footer-logo.png', $html);
    }

    public function testFooterOmitsLogoWhenShowLogoFalse(): void
    {
        $this->seedAttachment(71, 'https://example.com/footer-logo.png', 'Brand');
        update_option('pp_logo_id', '71');
        $html = $this->renderFooter(['location' => 'footer', 'show_logo' => false]);
        $this->assertStringNotContainsString('site-footer__logo', $html);
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

    // ── #155: shared image-attachment predicate ─────────────────────────────

    public function testImageAttachmentPredicateRejectsZeroAndNegative(): void
    {
        $this->assertFalse(pp_is_image_attachment(0));
        $this->assertFalse(pp_is_image_attachment(-5));
    }

    public function testImageAttachmentPredicateRejectsNonExistent(): void
    {
        // Nothing seeded for 404 — not an attachment.
        $this->assertFalse(pp_is_image_attachment(404));
    }

    public function testImageAttachmentPredicateRejectsNonImageAttachment(): void
    {
        $this->seedAttachment(50, 'https://example.com/doc.pdf', '', false);
        $this->assertFalse(pp_is_image_attachment(50));
    }

    public function testImageAttachmentPredicateAcceptsImage(): void
    {
        $this->seedAttachment(51, 'https://example.com/img.png');
        $this->assertTrue(pp_is_image_attachment(51));
    }

    // ── #155: resolver hardening (explicit guard, tested fallback) ──────────

    public function testResolveTextWhenComponentLogoIdIsNonImage(): void
    {
        // A component logo_id pointing at a non-image attachment (PDF/video)
        // must deterministically fall back to the wordmark, not rely on WP
        // core's silent-false behavior.
        $this->seedAttachment(60, 'https://example.com/movie.mp4', '', false);
        $logo = pp_resolve_logo(['logo_id' => 60, 'logo_text' => 'Wordmark']);
        $this->assertSame('text', $logo['type']);
        $this->assertSame('', $logo['url']);
        $this->assertSame('Wordmark', $logo['text']);
    }

    public function testResolveTextWhenCustomLogoThemeModIsNonImage(): void
    {
        // The theme-mod custom_logo path is also covered by the explicit guard.
        $this->seedAttachment(61, 'https://example.com/notimage.pdf', '', false);
        $GLOBALS['_pp_test_store']['theme_mods']['custom_logo'] = 61;
        $logo = pp_resolve_logo(['logo_text' => 'Brand']);
        $this->assertSame('text', $logo['type']);
        $this->assertSame('Brand', $logo['text']);
    }

    // ── #155: single logo_id value validation ───────────────────────────────

    public function testValidateSingleLogoIdAllowsEmptyClearValues(): void
    {
        // Absent/cleared logo (empty per PHP empty()) — nothing to validate.
        foreach (['', '0', 0, null, false] as $empty) {
            $this->assertTrue(
                _pp_validate_single_logo_id($empty),
                sprintf('empty logo_id %s should pass', var_export($empty, true))
            );
        }
    }

    public function testValidateSingleLogoIdAcceptsImageId(): void
    {
        $this->seedAttachment(70, 'https://example.com/logo.png');
        $this->assertTrue(_pp_validate_single_logo_id(70));
        $this->assertTrue(_pp_validate_single_logo_id('70')); // integer-string
    }

    public function testValidateSingleLogoIdRejectsNonImage(): void
    {
        $this->seedAttachment(71, 'https://example.com/doc.pdf', '', false);
        $result = _pp_validate_single_logo_id(71);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_logo_id', $result->get_error_code());
    }

    public function testValidateSingleLogoIdRejectsMalformedShapes(): void
    {
        // (int) coercion would silently accept these — the shape check must not.
        $this->seedAttachment(12, 'https://example.com/img.png'); // real image ID 12
        foreach (['12abc', '12.0', '-4', 'https://evil.test/x.png', [12], 12.0, true] as $bad) {
            $result = _pp_validate_single_logo_id($bad);
            $this->assertInstanceOf(
                \WP_Error::class,
                $result,
                sprintf('malformed logo_id %s must be rejected', var_export($bad, true))
            );
            $this->assertSame('invalid_logo_id', $result->get_error_code());
        }
    }

    // ── #155: params walker (mirrors #124 traversal) ────────────────────────

    public function testValidateLogoIdsRejectsNonImageInFlatProps(): void
    {
        $this->seedAttachment(80, 'https://example.com/doc.pdf', '', false);
        $result = _pp_validate_logo_ids_in_params(['props' => ['logo_id' => 80]]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_logo_id', $result->get_error_code());
    }

    public function testValidateLogoIdsRejectsNonImageInComposition(): void
    {
        $this->seedAttachment(81, 'https://example.com/doc.pdf', '', false);
        $params = ['composition' => [
            ['component' => 'nav', 'props' => ['logo_id' => 81]],
        ]];
        $result = _pp_validate_logo_ids_in_params($params);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_logo_id', $result->get_error_code());
    }

    public function testValidateLogoIdsAcceptsValidAndEmpty(): void
    {
        $this->seedAttachment(82, 'https://example.com/logo.png');
        $this->assertTrue(_pp_validate_logo_ids_in_params(['props' => ['logo_id' => 82]]));
        $this->assertTrue(_pp_validate_logo_ids_in_params(['props' => ['heading' => 'Hi']]));
        $this->assertTrue(_pp_validate_logo_ids_in_params([]));
    }

    /**
     * Decision 3 (eng review): the logo_id walker must cover exactly the same
     * locations as #124's URL walker so the two cannot drift. Feed one payload
     * carrying both a logo_id and an image_url at each traversal location
     * (flat props, composition[].props, items[]) and assert both extractors
     * pull three values.
     */
    public function testLogoIdWalkerMirrorsUrlWalkerLocations(): void
    {
        $params = [
            'props' => [
                'logo_id'   => 1,
                'image_url' => 'https://example.com/a.png',
                'items'     => [
                    ['logo_id' => 2, 'image_url' => 'https://example.com/b.png'],
                ],
            ],
            'composition' => [
                ['props' => [
                    'logo_id'   => 3,
                    'image_url' => 'https://example.com/c.png',
                ]],
            ],
        ];
        $this->assertCount(3, _pp_extract_logo_ids_from_params($params));
        $this->assertCount(3, _pp_extract_urls_from_params($params));
    }

    // ── #155: wired into the central action-validation choke point ──────────

    public function testActionValidationRejectsNonImageComponentLogoId(): void
    {
        // Runs before the action's own page-existence validate, so a bad
        // logo_id short-circuits regardless of a real target page.
        $this->seedAttachment(90, 'https://example.com/doc.pdf', '', false);
        $result = pp_validate_action('update_component', [
            'post_id' => 999,
            'props'   => ['logo_id' => 90],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_logo_id', $result->get_error_code());
    }
}
