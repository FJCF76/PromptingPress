<?php
/**
 * tests/SocialMetaTest.php
 *
 * Issue 468 — Open Graph / Twitter social-share meta. Before this the theme
 * emitted ZERO og and twitter tags, so sharing a page URL produced no rich card.
 * This suite pins the full surface added by #468:
 *   - the four new site-option keys (pp_og_image, pp_og_site_name,
 *     pp_og_default_description, pp_twitter_card) on the whitelist, with the
 *     right types and validation (og_image = the pp_logo_id image-attachment
 *     rule; twitter_card = a closed enum; og_default_description = meta_
 *     description's 320-char cap; og_site_name = free text);
 *   - the two per-page keys (og_title, twitter_title) on _pp_seo_meta via
 *     update_seo_meta, validated like seo_title and round-tripping non-ASCII
 *     through the #471-fixed store;
 *   - pp_social_meta_tags() rendering: each fallback chain at every level, the
 *     no-empty-tag rule, escaping at the sink, non-singular / no-post omission,
 *     stale-attachment re-check, and missing image-metadata dimensions;
 *   - the batch snapshot/rollback covering the new site option like its siblings.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class SocialMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'            => [],
            'posts'                => [],
            'options'              => [],
            'theme_mods'           => [],
            'attachment_urls'      => [],
            'attachment_is_image'  => [],
            'attachment_metadata'  => [],
            'next_id'              => 100,
        ];
    }

    /** Seed a Media Library image attachment with a resolvable URL and optional alt/metadata. */
    private function seedImage(int $id, string $url, string $alt = '', ?array $metadata = null): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_urls'][$id] = $url;
        $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = true;
        if ($alt !== '') {
            $GLOBALS['_pp_test_store']['post_meta'][$id]['_wp_attachment_image_alt'] = $alt;
        }
        if ($metadata !== null) {
            $GLOBALS['_pp_test_store']['attachment_metadata'][$id] = $metadata;
        }
    }

    /** Render pp_social_meta_tags() for a singular page. */
    private function renderFor(int $post_id): string
    {
        $GLOBALS['_pp_test_store']['is_singular'] = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $post_id;
        ob_start();
        pp_social_meta_tags();
        return ob_get_clean();
    }

    // ── Whitelist + types ───────────────────────────────────────────────────

    public function testAllowedSiteOptionsIncludesSocialKeys(): void
    {
        $allowed = pp_allowed_site_options();
        $this->assertSame('attachment_id', $allowed['pp_og_image']);
        $this->assertSame('string',        $allowed['pp_og_site_name']);
        $this->assertSame('string',        $allowed['pp_og_default_description']);
        $this->assertSame('twitter_card',  $allowed['pp_twitter_card']);
    }

    // ── pp_og_image: the pp_logo_id image-attachment rule ───────────────────

    public function testOgImageAcceptsImageAttachment(): void
    {
        $this->seedImage(42, 'https://example.com/og.jpg');
        $this->assertTrue(pp_validate_site_option_value('pp_og_image', '42'));
    }

    public function testOgImageRejectsNonImageAndEmpty(): void
    {
        // A non-image attachment (e.g. a PDF), a non-existent ID, and 0/'' all reject.
        $GLOBALS['_pp_test_store']['posts'][7] = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][7] = false;
        foreach (['7', '9999', '0', ''] as $val) {
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_validate_site_option_value('pp_og_image', $val),
                "pp_og_image should reject '{$val}'."
            );
        }
    }

    // ── pp_twitter_card: closed enum ────────────────────────────────────────

    public function testTwitterCardAcceptsClosedSetAndClear(): void
    {
        foreach (['summary', 'summary_large_image', ''] as $val) {
            $this->assertTrue(
                pp_validate_site_option_value('pp_twitter_card', $val),
                "pp_twitter_card should accept '{$val}'."
            );
        }
    }

    public function testTwitterCardRejectsUnknownValue(): void
    {
        foreach (['app', 'player', 'large', 'notacard'] as $val) {
            $this->assertInstanceOf(
                \WP_Error::class,
                pp_validate_site_option_value('pp_twitter_card', $val),
                "pp_twitter_card should reject '{$val}'."
            );
        }
    }

    public function testTwitterCardNormalizesCaseAndRoundTrips(): void
    {
        // A mixed-case but in-set value validates and stores lower-cased, so the
        // stored value always re-validates through the snapshot/rollback path.
        $this->assertTrue(pp_update_site_option('pp_twitter_card', 'SUMMARY'));
        $this->assertSame('summary', (string) get_option('pp_twitter_card'));
        $this->assertTrue(pp_validate_site_option_value('pp_twitter_card', (string) get_option('pp_twitter_card')));
    }

    // ── pp_og_default_description: meta_description's 320 cap ────────────────

    public function testOgDefaultDescriptionCapAt320(): void
    {
        $this->assertTrue(pp_validate_site_option_value('pp_og_default_description', str_repeat('x', 320)));
        $this->assertInstanceOf(
            \WP_Error::class,
            pp_validate_site_option_value('pp_og_default_description', str_repeat('x', 321))
        );
    }

    public function testOgSiteNameIsFreeTextNoCap(): void
    {
        // Consistent with the other 'string' options (pp_footer_blurb et al.):
        // no length cap, no surface-specific rule.
        $this->assertTrue(pp_validate_site_option_value('pp_og_site_name', str_repeat('x', 5000)));
    }

    // ── Per-page keys: og_title / twitter_title on _pp_seo_meta ──────────────

    public function testSeoMetaDefaultsIncludeSocialTitleKeys(): void
    {
        $id = pp_create_page('Page', 'draft');
        $meta = pp_get_seo_meta($id);
        $this->assertSame('', $meta['og_title']);
        $this->assertSame('', $meta['twitter_title']);
    }

    public function testUpdateSeoMetaAcceptsSocialTitles(): void
    {
        $id = pp_create_page('Page', 'draft');
        $result = pp_execute_action('update_seo_meta', [
            'post_id' => $id,
            'meta'    => ['og_title' => 'Share Title', 'twitter_title' => 'Tweet Title'],
        ]);
        $this->assertTrue($result['ok']);
        $meta = pp_get_seo_meta($id);
        $this->assertSame('Share Title', $meta['og_title']);
        $this->assertSame('Tweet Title', $meta['twitter_title']);
    }

    public function testUpdateSeoMetaCapsSocialTitlesAt200(): void
    {
        $id = pp_create_page('Page', 'draft');
        foreach (['og_title', 'twitter_title'] as $key) {
            $result = pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => [$key => str_repeat('x', 201)]]);
            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('200 characters', $result['error']);
        }
    }

    public function testSocialTitlesRoundTripNonAscii(): void
    {
        // Reuse the #471 matrix: non-ASCII must survive the store byte-identically.
        $id = pp_create_page('Page', 'draft');
        $og = 'Título — 日本語 🚀';
        $tw = 'Compártelo — ñandú';
        pp_execute_action('update_seo_meta', ['post_id' => $id, 'meta' => ['og_title' => $og, 'twitter_title' => $tw]]);
        $meta = pp_get_seo_meta($id);
        $this->assertSame($og, $meta['og_title']);
        $this->assertSame($tw, $meta['twitter_title']);
    }

    // ── Renderer: gating ────────────────────────────────────────────────────

    public function testRendererEmitsNothingWhenNotSingular(): void
    {
        $id = pp_create_page('Page', 'draft');
        $GLOBALS['_pp_test_store']['is_singular'] = false;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;
        ob_start();
        pp_social_meta_tags();
        $this->assertSame('', ob_get_clean());
    }

    public function testRendererEmitsNothingWhenNoQueriedObject(): void
    {
        $GLOBALS['_pp_test_store']['is_singular'] = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = 0;
        ob_start();
        pp_social_meta_tags();
        $this->assertSame('', ob_get_clean());
    }

    // ── Renderer: title chains ──────────────────────────────────────────────

    public function testOgTitleChainPrefersOgTitleThenSeoTitleThenPostTitle(): void
    {
        // og_title wins.
        $id = pp_create_page('Post Title', 'draft');
        pp_update_seo_meta($id, ['og_title' => 'OG Title', 'seo_title' => 'SEO Title']);
        $this->assertStringContainsString('<meta property="og:title" content="OG Title">', $this->renderFor($id));

        // seo_title when og_title is empty.
        $id2 = pp_create_page('Post Title', 'draft');
        pp_update_seo_meta($id2, ['seo_title' => 'SEO Title']);
        $this->assertStringContainsString('<meta property="og:title" content="SEO Title">', $this->renderFor($id2));

        // post title when both are empty.
        $id3 = pp_create_page('Post Title', 'draft');
        $this->assertStringContainsString('<meta property="og:title" content="Post Title">', $this->renderFor($id3));
    }

    public function testTwitterTitleChainPrefersTwitterTitleThenOgTitleChain(): void
    {
        $id = pp_create_page('Post Title', 'draft');
        pp_update_seo_meta($id, ['twitter_title' => 'Tweet Title', 'og_title' => 'OG Title']);
        $this->assertStringContainsString('<meta name="twitter:title" content="Tweet Title">', $this->renderFor($id));

        // Falls back to the og:title chain (here og_title) when twitter_title unset.
        $id2 = pp_create_page('Post Title', 'draft');
        pp_update_seo_meta($id2, ['og_title' => 'OG Title']);
        $this->assertStringContainsString('<meta name="twitter:title" content="OG Title">', $this->renderFor($id2));
    }

    // ── Renderer: description chain ─────────────────────────────────────────

    public function testDescriptionChainPageThenSiteDefaultThenOmit(): void
    {
        // Page meta_description wins.
        $id = pp_create_page('P', 'draft');
        pp_update_seo_meta($id, ['meta_description' => 'Page desc']);
        pp_update_site_option('pp_og_default_description', 'Site default desc');
        $html = $this->renderFor($id);
        $this->assertStringContainsString('<meta property="og:description" content="Page desc">', $html);
        $this->assertStringContainsString('<meta name="twitter:description" content="Page desc">', $html);

        // Site default when the page has none.
        $id2 = pp_create_page('P', 'draft');
        $html2 = $this->renderFor($id2);
        $this->assertStringContainsString('<meta property="og:description" content="Site default desc">', $html2);

        // Omitted entirely when neither is set.
        delete_option('pp_og_default_description');
        $id3 = pp_create_page('P', 'draft');
        $html3 = $this->renderFor($id3);
        $this->assertStringNotContainsString('og:description', $html3);
        $this->assertStringNotContainsString('twitter:description', $html3);
    }

    // ── Renderer: image + site-wide tags ────────────────────────────────────

    public function testFullImageAndSiteTagsRender(): void
    {
        $id = pp_create_page('Post Title', 'publish');
        $this->seedImage(55, 'https://example.com/og.jpg', 'Alt text', ['width' => 1200, 'height' => 630]);
        pp_update_site_option('pp_og_image', '55');
        pp_update_site_option('pp_og_site_name', 'Acme Co');
        pp_update_site_option('pp_twitter_card', 'summary');
        $html = $this->renderFor($id);

        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Acme Co">', $html);
        $this->assertStringContainsString('<meta property="og:locale" content="en_US">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="https://example.com/', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://example.com/og.jpg">', $html);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        $this->assertStringContainsString('<meta property="og:image:height" content="630">', $html);
        $this->assertStringContainsString('<meta property="og:image:alt" content="Alt text">', $html);
        $this->assertStringContainsString('<meta name="twitter:image" content="https://example.com/og.jpg">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
    }

    public function testOgSiteNameDefaultsToBloginfoWhenUnset(): void
    {
        $id = pp_create_page('P', 'draft');
        $html = $this->renderFor($id);
        // get_bloginfo('name') stub returns 'Test Site'.
        $this->assertStringContainsString('<meta property="og:site_name" content="Test Site">', $html);
    }

    public function testTwitterCardDefaultsToSummaryLargeImageWhenUnset(): void
    {
        $id = pp_create_page('P', 'draft');
        $html = $this->renderFor($id);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
    }

    public function testImageTagsOmittedWhenNoOgImage(): void
    {
        $id = pp_create_page('P', 'draft');
        $html = $this->renderFor($id);
        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('twitter:image', $html);
    }

    public function testStaleImageIdOmitsImageTags(): void
    {
        // An ID that validated on write but no longer resolves to an image
        // attachment (deleted/trashed) must omit the image tags at render time.
        $id = pp_create_page('P', 'draft');
        $GLOBALS['_pp_test_store']['options']['pp_og_image'] = '999'; // never seeded
        $html = $this->renderFor($id);
        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('twitter:image', $html);
    }

    public function testMissingImageDimensionsOmitWidthHeight(): void
    {
        $id = pp_create_page('P', 'draft');
        $this->seedImage(60, 'https://example.com/og.jpg', 'Alt', []); // no width/height
        pp_update_site_option('pp_og_image', '60');
        $html = $this->renderFor($id);
        $this->assertStringContainsString('<meta property="og:image" content="https://example.com/og.jpg">', $html);
        $this->assertStringNotContainsString('og:image:width', $html);
        $this->assertStringNotContainsString('og:image:height', $html);
        // Alt still renders (it was set).
        $this->assertStringContainsString('<meta property="og:image:alt" content="Alt">', $html);
    }

    public function testImageAltOmittedWhenAttachmentHasNoAlt(): void
    {
        $id = pp_create_page('P', 'draft');
        $this->seedImage(61, 'https://example.com/og.jpg'); // no alt
        pp_update_site_option('pp_og_image', '61');
        $html = $this->renderFor($id);
        $this->assertStringContainsString('og:image', $html);
        $this->assertStringNotContainsString('og:image:alt', $html);
    }

    // ── Renderer: escaping at the sink ──────────────────────────────────────

    public function testValuesAreEscapedInOutput(): void
    {
        $id = pp_create_page('P', 'draft');
        pp_update_seo_meta($id, ['og_title' => 'Quote "x" & <b>', 'meta_description' => 'a "quoted" <tag>']);
        pp_update_site_option('pp_og_site_name', 'A & B "co"');
        $html = $this->renderFor($id);
        $this->assertStringContainsString('content="Quote &quot;x&quot; &amp; &lt;b&gt;"', $html);
        $this->assertStringContainsString('content="a &quot;quoted&quot; &lt;tag&gt;"', $html);
        $this->assertStringContainsString('content="A &amp; B &quot;co&quot;"', $html);
        // No raw angle bracket leaked into an attribute value.
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testWhitespaceOnlyValuesTreatedAsEmpty(): void
    {
        // A whitespace-only site value must NOT emit a blank tag and must NOT
        // suppress the fallback: pp_og_site_name = "   " still falls back to the
        // WP site name, and a whitespace og_title falls through to the post title.
        $id = pp_create_page('Post Title', 'draft');
        pp_update_seo_meta($id, ['og_title' => '   ']);
        $GLOBALS['_pp_test_store']['options']['pp_og_site_name'] = '   ';
        $GLOBALS['_pp_test_store']['options']['pp_og_default_description'] = '   ';
        $html = $this->renderFor($id);
        $this->assertStringContainsString('<meta property="og:title" content="Post Title">', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Test Site">', $html);
        // Whitespace description resolves to empty → the tag is omitted entirely.
        $this->assertStringNotContainsString('og:description', $html);
        $this->assertStringNotContainsString('content="   "', $html);
    }

    // ── Batch snapshot / rollback covers the new site option ────────────────

    public function testSnapshotRollbackRestoresOgImageOption(): void
    {
        $this->seedImage(70, 'https://example.com/a.jpg');
        $this->seedImage(71, 'https://example.com/b.jpg');

        // Baseline SET → change → rollback restores prior value verbatim.
        pp_update_site_option('pp_og_image', '70');
        $baseline = (string) get_option('pp_og_image');
        $steps = [['name' => 'update_site_option', 'params' => ['key' => 'pp_og_image', 'value' => '70']]];
        $snapshot = _pp_snapshot_batch_targets($steps);
        pp_update_site_option('pp_og_image', '71');
        _pp_restore_batch_snapshot($snapshot);
        $this->assertSame($baseline, (string) get_option('pp_og_image'));

        // Baseline UNSET → set → rollback clears back to unset.
        delete_option('pp_og_image');
        $snapshot2 = _pp_snapshot_batch_targets($steps);
        pp_update_site_option('pp_og_image', '70');
        _pp_restore_batch_snapshot($snapshot2);
        $this->assertSame('', (string) get_option('pp_og_image'));
    }

    public function testSnapshotRollbackRestoresPerPageSocialTitles(): void
    {
        $id = pp_create_page('P', 'draft');
        pp_update_seo_meta($id, ['og_title' => 'Original']);
        $steps = [['name' => 'update_seo_meta', 'params' => ['post_id' => $id, 'meta' => ['og_title' => 'Original']]]];
        $snapshot = _pp_snapshot_batch_targets($steps);
        pp_update_seo_meta($id, ['og_title' => 'Changed']);
        _pp_restore_batch_snapshot($snapshot);
        $this->assertSame('Original', pp_get_seo_meta($id)['og_title']);
    }

    // ── Action-layer rejection surfaces the standard envelope ───────────────

    public function testUpdateSiteOptionActionRejectsBadTwitterCard(): void
    {
        $result = pp_execute_action('update_site_option', ['key' => 'pp_twitter_card', 'value' => 'player']);
        $this->assertFalse($result['ok']);
    }
}
