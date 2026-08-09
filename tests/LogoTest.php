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

    // ── site_icon / favicon site option (issue 414) ─────────────────────────
    // WordPress core's own `site_icon` option, whitelisted as an image
    // attachment_id and validated by the SAME pp_is_image_attachment rule as
    // pp_logo_id, so the browser-tab favicon / app icon is settable through the
    // same typed, validated path as the logo. Core's wp_site_icon() then emits
    // the <link rel="icon"> tags in wp_head automatically (core behavior — no
    // theme render code, so no render test here per the option-write pin).

    public function testAllowedSiteOptionsIncludesSiteIconAsAttachmentId(): void
    {
        $allowed = pp_allowed_site_options();
        $this->assertArrayHasKey('site_icon', $allowed);
        $this->assertSame('attachment_id', $allowed['site_icon']);
    }

    public function testValidateAcceptsRealImageAttachmentForSiteIcon(): void
    {
        $this->seedAttachment(60, 'https://example.com/favicon-512.png');
        $this->assertTrue(pp_validate_site_option_value('site_icon', '60'));
    }

    public function testValidateRejectsNonImageAttachmentForSiteIcon(): void
    {
        // An attachment that is not an image (e.g. a PDF/ICO core rejects).
        $this->seedAttachment(61, 'https://example.com/icon.pdf', '', false);
        $result = pp_validate_site_option_value('site_icon', '61');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('image', $result->get_error_message());
    }

    public function testValidateRejectsNonexistentAndZeroSiteIcon(): void
    {
        // 404 is not in the store; 0 is never a valid attachment. Both rejected,
        // and 0 is a rejection, not a silent "unset" (same as pp_logo_id).
        $this->assertInstanceOf(\WP_Error::class, pp_validate_site_option_value('site_icon', '404'));
        $this->assertInstanceOf(\WP_Error::class, pp_validate_site_option_value('site_icon', '0'));
    }

    public function testUpdateWritesAndNormalizesSiteIcon(): void
    {
        $this->seedAttachment(62, 'https://example.com/brand-icon.png');
        $this->assertTrue(pp_update_site_option('site_icon', '062'));
        // Stored as the canonical int string core reads via get_option('site_icon').
        $this->assertSame('62', get_option('site_icon'));
    }

    public function testActionAcceptsValidSiteIcon(): void
    {
        $this->seedAttachment(63, 'https://example.com/app-icon.png');
        $result = pp_execute_action('update_site_option', ['key' => 'site_icon', 'value' => '63']);
        $this->assertTrue($result['ok']);
        $this->assertSame('63', get_option('site_icon'));
    }

    public function testActionRejectsNonImageSiteIconWithClearMessage(): void
    {
        $result = pp_execute_action('update_site_option', ['key' => 'site_icon', 'value' => '404']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('attachment', $result['error']);
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

    // ── issue 299: footer logo has a height cap, consistent with the nav ─────

    /** Extract a single CSS declaration block by selector from components.css. */
    private function cssRuleBlock(string $selector): ?string
    {
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        // Match "<selector> { ... }" (selector anchored at a line start to avoid
        // catching it as part of a grouped/compound selector).
        $pattern = '/(?:^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m';
        return preg_match($pattern, $css, $m) ? $m[1] : null;
    }

    public function testFooterLogoImageHasHeightCap(): void
    {
        // Regression for issue 299: without a cap a real wordmark (664x150)
        // rendered near intrinsic size and dominated the footer. The footer is
        // template-owned with zero style slots, so the fix is a literal cap.
        $block = $this->cssRuleBlock('.site-footer__logo-image');
        $this->assertNotNull($block, '.site-footer__logo-image rule missing from components.css');
        $this->assertMatchesRegularExpression('/max-height:\s*2\.5rem/', $block);
        $this->assertMatchesRegularExpression('/width:\s*auto/', $block);
        $this->assertMatchesRegularExpression('/object-fit:\s*contain/', $block);
        $this->assertMatchesRegularExpression('/display:\s*block/', $block);

        // Bind the capped selector to the template element it sizes: the footer
        // <img> must actually carry site-footer__logo-image, or the cap is dead
        // CSS while every source-text assertion above still passes.
        $this->seedAttachment(72, 'https://example.com/wordmark.png', 'Brand');
        update_option('pp_logo_id', '72');
        $html = $this->renderFooter(['location' => 'footer', 'show_logo' => true]);
        $this->assertStringContainsString('class="site-footer__logo-image"', $html);
    }

    public function testFooterLogoCapMatchesNavCap(): void
    {
        // The recorded direction for issue 299 is "consistent with the nav
        // treatment": the footer must use the same max-height as the nav so the
        // two logo treatments cannot silently drift apart.
        $nav    = $this->cssRuleBlock('.nav__logo-image');
        $footer = $this->cssRuleBlock('.site-footer__logo-image');
        $this->assertNotNull($nav, '.nav__logo-image rule missing from components.css');
        $this->assertNotNull($footer, '.site-footer__logo-image rule missing from components.css');
        $extractMaxHeight = static function (string $block): ?string {
            // Do not require a trailing ";" — max-height may be the last
            // declaration in a block; stop at ";" or the closing "}".
            return preg_match('/max-height:\s*([^;}]+)/', $block, $m) ? trim($m[1]) : null;
        };
        $this->assertNotNull($extractMaxHeight($nav), 'nav logo has no max-height');
        $this->assertSame(
            $extractMaxHeight($nav),
            $extractMaxHeight($footer),
            'footer logo max-height must match the nav logo cap (issue 299 direction)'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    //  issue 582 — pp_logo_alt is WIRED
    //
    //  pp_logo_alt was whitelisted in v0.16.0 and documented on three surfaces
    //  as THE logo alt surface, but nothing read it: templates/base.php never
    //  passed `logo_alt`, pp_resolve_logo() only reads $props['logo_alt'], and
    //  no get_option('pp_logo_alt') existed anywhere. A write succeeded and
    //  changed nothing rendered. base.php is now that consumer.
    //
    //  This is NOT an accessibility fix. The alt was never empty — it falls
    //  back to the attachment's own alt and then the site title. What was
    //  missing is a per-site alt override DISTINCT from the attachment's alt.
    //
    //  Byte-identity: a site that never set pp_logo_alt renders exactly as
    //  before, which is what the "unset" pins below exist to prove.
    // ══════════════════════════════════════════════════════════════════════

    private function renderNav(array $props): string
    {
        ob_start();
        pp_get_component('nav', $props);
        return ob_get_clean();
    }

    /** The props base.php passes today, resolved live so a drop here fails loudly. */
    private function navPropsFromBaseTemplate(): array
    {
        return [
            'location'   => 'primary',
            'bg'         => (string) get_option('pp_header_bg', ''),
            'text'       => (string) get_option('pp_header_text', ''),
            'link_color' => (string) get_option('pp_header_link_color', ''),
            'logo_alt'   => (string) get_option('pp_logo_alt', ''),
        ];
    }

    private function footerPropsFromBaseTemplate(): array
    {
        return [
            'location'  => 'footer',
            'show_logo' => get_option('pp_footer_show_logo', '') === '1',
            'logo_id'   => (string) get_option('pp_footer_logo_id', ''),
            'logo_alt'  => (string) get_option('pp_logo_alt', ''),
        ];
    }

    // ── The wiring itself ───────────────────────────────────────────────────

    public function testBaseTemplatePassesLogoAltToBothChromeComponents(): void
    {
        $base = file_get_contents(dirname(__DIR__) . '/templates/base.php');
        $this->assertNotFalse($base, 'templates/base.php must be readable.');

        // Strip comments first: a commented-out example must never satisfy this.
        $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $base);

        // Anchor the assertion PER COMPONENT. A file-wide count of 2 is satisfied by
        // the nav call carrying the key twice while the footer carries it zero times —
        // a plausible bad merge that would silently drop the footer's alt override on
        // every install.
        foreach (['nav', 'footer'] as $component) {
            $this->assertSame(
                1,
                preg_match(
                    "/pp_get_component\(\s*'{$component}'\s*,\s*\[(.*?)\n\]\)/s",
                    $code,
                    $m
                ),
                "templates/base.php must contain exactly one pp_get_component('{$component}', [...]) call."
            );
            $this->assertSame(
                1,
                preg_match_all("/'logo_alt'\s*=>\s*\(string\)\s*get_option\(\s*'pp_logo_alt'/", $m[1]),
                "templates/base.php must pass pp_logo_alt as the {$component}'s logo_alt prop "
                . 'exactly once. Without a consumer, the whitelisted option is documented on '
                . 'three surfaces and does nothing.'
            );
        }
    }

    public function testLogoAltWiringKeepsLocationAsTheFirstKeyOfEachChromeCall(): void
    {
        // The NavReadinessTest drift guard derives pp_template_owned_menu_locations()
        // from the FIRST 'location' => key of each pp_get_component() call. Adding
        // logo_alt ABOVE 'location' would silently break that guard's regex rather
        // than fail it, so pin the ordering here where the new key was added.
        $base = file_get_contents(dirname(__DIR__) . '/templates/base.php');
        $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $base);

        preg_match_all("/pp_get_component\(\s*'[a-z0-9_-]+'\s*,\s*\[\s*'([a-z0-9_]+)'/i", $code, $m);
        $this->assertSame(
            ['location', 'location'],
            $m[1],
            "Every chrome pp_get_component() call in base.php must open with 'location'."
        );
    }

    // ── AUTHORING PATH: the real write surface, not a raw option poke ────────

    public function testAltWrittenThroughTheRealSiteOptionActionReachesTheRenderedImage(): void
    {
        // Section 14.1: exercise the authoring contract, not a raw store write.
        // pp_update_site_option is what the update_site_option action calls, so
        // this proves validation accepts the value AND that it renders.
        $this->seedAttachment(41, 'https://example.com/brand.png', 'Attachment Alt');
        $this->assertTrue(pp_update_site_option('pp_logo_id', '41'));
        $this->assertTrue(pp_update_site_option('pp_logo_alt', 'NeoCompute'));

        $html = $this->renderNav($this->navPropsFromBaseTemplate());
        $this->assertStringContainsString('alt="NeoCompute"', $html);
        $this->assertStringNotContainsString('alt="Attachment Alt"', $html);
    }

    public function testHostileAltFromTheAuthoringSurfaceStaysEscapedInBothChromeComponents(): void
    {
        // #582 turns pp_logo_alt into the first AI-writable site option that reaches
        // rendered HTML. Its 'string' type is pass-through — pp_validate_site_option_value
        // has no 'string' branch — so the stored bytes are whatever was written, and
        // esc_attr() at the two sinks is the ONLY thing standing between the option and
        // the markup. Pin it: a future template edit that interpolates $logo['alt']
        // without esc_attr would otherwise ship green.
        $hostile = '" onerror=alert(1) x="';
        $this->seedAttachment(50, 'https://example.com/brand.png', 'Attachment Alt');
        $this->assertTrue(pp_update_site_option('pp_logo_id', '50'));
        $this->assertTrue(pp_update_site_option('pp_logo_alt', $hostile));
        update_option('pp_footer_show_logo', '1');

        foreach ([
            'nav'    => $this->renderNav($this->navPropsFromBaseTemplate()),
            'footer' => $this->renderFooter($this->footerPropsFromBaseTemplate()),
        ] as $component => $html) {
            // The payload text survives inside the attribute VALUE, harmlessly — what
            // must not survive is the raw double quote that would close the attribute
            // and start a new one. Assert on the quote-break, not on the words.
            $this->assertStringNotContainsString(
                $hostile,
                $html,
                "The {$component} logo alt must never reach the markup with its quotes intact."
            );
            $this->assertStringContainsString(
                'alt="' . esc_attr($hostile) . '"',
                $html,
                "The {$component} logo alt must be entity-encoded by esc_attr()."
            );
        }
    }

    // ── Set: the option value IS the rendered alt ───────────────────────────

    public function testNavAltUsesTheOptionWhenSet(): void
    {
        $this->seedAttachment(42, 'https://example.com/brand.png', 'Attachment Alt');
        update_option('pp_logo_id', '42');
        update_option('pp_logo_alt', 'Acme brand mark');

        $html = $this->renderNav($this->navPropsFromBaseTemplate());
        $this->assertStringContainsString('alt="Acme brand mark"', $html);
    }

    public function testFooterAltUsesTheSameOptionWhenSet(): void
    {
        $this->seedAttachment(43, 'https://example.com/brand.png', 'Attachment Alt');
        update_option('pp_logo_id', '43');
        update_option('pp_footer_show_logo', '1');
        update_option('pp_logo_alt', 'Acme brand mark');

        $html = $this->renderFooter($this->footerPropsFromBaseTemplate());
        $this->assertStringContainsString('alt="Acme brand mark"', $html);
    }

    public function testSiteWideAltAlsoOverridesTheFooterLogoOverridesOwnAlt(): void
    {
        // There is deliberately no pp_footer_logo_alt: one site-wide alt serves
        // both chrome logos. Stated because it is intentional — when the footer
        // runs a DIFFERENT image via pp_footer_logo_id, a set pp_logo_alt still
        // wins over that attachment's own alt metadata.
        $this->seedAttachment(44, 'https://example.com/dark.png',  'Header Attachment Alt');
        $this->seedAttachment(45, 'https://example.com/light.png', 'Footer Attachment Alt');
        update_option('pp_logo_id', '44');
        update_option('pp_footer_logo_id', '45');
        update_option('pp_footer_show_logo', '1');
        update_option('pp_logo_alt', 'Acme brand mark');

        $html = $this->renderFooter($this->footerPropsFromBaseTemplate());
        $this->assertStringContainsString('https://example.com/light.png', $html);
        $this->assertStringContainsString('alt="Acme brand mark"', $html);
        $this->assertStringNotContainsString('Footer Attachment Alt', $html);
    }

    // ── Unset: the existing fallback chain is UNCHANGED ─────────────────────

    public function testUnsetOptionLeavesTheAttachmentAltInPlace(): void
    {
        $this->seedAttachment(46, 'https://example.com/brand.png', 'Attachment Alt');
        update_option('pp_logo_id', '46');
        // pp_logo_alt deliberately never set — base.php passes ''.

        $html = $this->renderNav($this->navPropsFromBaseTemplate());
        $this->assertStringContainsString('alt="Attachment Alt"', $html);
    }

    public function testUnsetOptionAndNoAttachmentAltFallsBackToTheSiteTitle(): void
    {
        // pp_site_title() reads get_bloginfo('name'), which the test bootstrap
        // fixes at 'Test Site'.
        $this->seedAttachment(47, 'https://example.com/brand.png'); // no alt meta
        update_option('pp_logo_id', '47');

        $html = $this->renderNav($this->navPropsFromBaseTemplate());
        $this->assertStringContainsString('alt="' . pp_site_title() . '"', $html);
    }

    public function testEmptyOptionIsTreatedAsAbsentByTheResolver(): void
    {
        // base.php passes '' for an unset option, so the resolver MUST treat ''
        // exactly like an omitted prop. If it did not, wiring the option would
        // have emptied the alt on every site on earth.
        $this->seedAttachment(48, 'https://example.com/brand.png', 'Attachment Alt');
        $explicitEmpty = pp_resolve_logo(['logo_id' => 48, 'logo_alt' => '']);
        $omitted       = pp_resolve_logo(['logo_id' => 48]);
        $this->assertSame($omitted, $explicitEmpty);
        $this->assertSame('Attachment Alt', $explicitEmpty['alt']);
    }

    // ── Whitespace-only counts as UNPROVIDED (maintainer ruling, #582) ──────

    public function testWhitespaceOnlyAltDoesNotSuppressTheAttachmentsOwnAlt(): void
    {
        // The defect this closes: '   ' is not '', so before the trim it counted as
        // an authored alt. The logo rendered alt="   " — nothing to a screen reader —
        // and the attachment's real alt was suppressed, leaving the operator strictly
        // worse off than never setting the option. pp_logo_alt is a 'string' option
        // with no validation branch, so the value arrives exactly as written.
        $this->seedAttachment(52, 'https://example.com/brand.png', 'Real attachment alt');
        $this->assertTrue(pp_update_site_option('pp_logo_id', '52'));
        $this->assertTrue(pp_update_site_option('pp_logo_alt', '   '));

        $logo = pp_resolve_logo(['logo_alt' => (string) get_option('pp_logo_alt', '')]);
        $this->assertSame('Real attachment alt', $logo['alt']);

        $html = $this->renderNav($this->navPropsFromBaseTemplate());
        $this->assertStringContainsString('alt="Real attachment alt"', $html);
    }

    public function testWhitespaceOnlyAltFallsAllTheWayToTheSiteTitle(): void
    {
        // Same rule with no attachment alt to catch it: the chain continues rather
        // than stopping on a value that means nothing.
        $this->seedAttachment(53, 'https://example.com/brand.png'); // no alt meta
        $logo = pp_resolve_logo(['logo_id' => 53, 'logo_alt' => "\t \n "]);
        $this->assertSame(pp_site_title(), $logo['alt']);

        // And on the text-wordmark branch.
        $textBranch = pp_resolve_logo(['logo_alt' => '   ']);
        $this->assertSame('text', $textBranch['type']);
        $this->assertSame(pp_site_title(), $textBranch['alt']);
    }

    public function testWhitespaceOnlyLogoTextAlsoFallsBack(): void
    {
        // The far end of the same chain: a whitespace-only wordmark must not become
        // the alt either, or the guarantee is defeated from the last hop.
        $logo = pp_resolve_logo(['logo_text' => '   ']);
        $this->assertSame(pp_site_title(), $logo['text']);
        $this->assertSame(pp_site_title(), $logo['alt']);
    }

    public function testTrimDecidesOnlyWhetherAValueCountsNeverRewritesIt(): void
    {
        // The stored option is untouched and a real value renders VERBATIM, including
        // its surrounding spaces. trim() is the provided/not-provided test, not a
        // sanitizer — pinning this stops a future "tidy up" from trimming the output.
        $this->seedAttachment(54, 'https://example.com/brand.png', 'Attachment Alt');
        $this->assertTrue(pp_update_site_option('pp_logo_alt', '  Acme brand mark  '));
        $this->assertSame('  Acme brand mark  ', get_option('pp_logo_alt'));

        $logo = pp_resolve_logo(['logo_id' => 54, 'logo_alt' => (string) get_option('pp_logo_alt', '')]);
        $this->assertSame('  Acme brand mark  ', $logo['alt']);
    }

    // ── The contract: alt is NEVER empty, on either branch ──────────────────

    public function testAltIsNeverEmptyOnTheImageBranch(): void
    {
        $this->seedAttachment(49, 'https://example.com/brand.png'); // no alt meta
        $logo = pp_resolve_logo(['logo_id' => 49, 'logo_alt' => '']);
        $this->assertSame('image', $logo['type']);
        $this->assertNotSame('', $logo['alt']);
        $this->assertSame(pp_site_title(), $logo['alt']);
    }

    public function testAltIsNeverEmptyOnTheTextWordmarkBranch(): void
    {
        // The text branch used `?? $text` alone, which was unreachable while
        // nothing passed the prop. Wiring base.php made '' reachable there, and
        // `??` does not catch '' — it would have returned an empty alt. Both
        // branches now normalize empty-as-absent.
        $logo = pp_resolve_logo(['logo_alt' => '']);
        $this->assertSame('text', $logo['type']);
        $this->assertNotSame('', $logo['alt']);
        $this->assertSame(pp_site_title(), $logo['alt']);

        // An explicit alt still wins on this branch.
        $explicit = pp_resolve_logo(['logo_alt' => 'Wordmark alt']);
        $this->assertSame('Wordmark alt', $explicit['alt']);
    }

    public function testEmptyLogoTextFallsBackToTheSiteTitleOnBothBranches(): void
    {
        // The "alt is never empty" contract has THREE inputs, not two: the terminal
        // hop is logo_text, and it used `??` alone — so logo_text => '' produced an
        // empty $text and therefore an empty alt on both branches, defeating the
        // guarantee from the far end. Normalizing it also makes the long-standing
        // schema claim "Falls back to pp_site_title() when empty" true; before #582 it
        // fell back only when the key was ABSENT.
        $this->seedAttachment(51, 'https://example.com/brand.png'); // no alt meta

        $image = pp_resolve_logo(['logo_id' => 51, 'logo_text' => '', 'logo_alt' => '']);
        $this->assertSame('image', $image['type']);
        $this->assertSame(pp_site_title(), $image['alt']);
        $this->assertSame(pp_site_title(), $image['text']);

        $textBranch = pp_resolve_logo(['logo_text' => '', 'logo_alt' => '']);
        $this->assertSame('text', $textBranch['type']);
        $this->assertSame(pp_site_title(), $textBranch['alt']);
        $this->assertSame(pp_site_title(), $textBranch['text']);

        // A real logo_text still wins.
        $explicit = pp_resolve_logo(['logo_text' => 'My Brand']);
        $this->assertSame('My Brand', $explicit['text']);
        $this->assertSame('My Brand', $explicit['alt']);
    }

    public function testNoLogoImageMeansTheWordmarkRendersUnaffectedByTheOption(): void
    {
        // Setting pp_logo_alt on a site with no logo image changes nothing: the
        // nav renders the text wordmark, which never carries an alt attribute.
        update_option('pp_logo_alt', 'Acme brand mark');

        $html = $this->renderNav($this->navPropsFromBaseTemplate());
        $this->assertStringNotContainsString('nav__logo-image', $html);
        $this->assertStringNotContainsString('Acme brand mark', $html);
        $this->assertStringContainsString(pp_site_title(), $html);
    }
}
