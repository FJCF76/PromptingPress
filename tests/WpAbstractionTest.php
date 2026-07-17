<?php
/**
 * tests/WpAbstractionTest.php
 *
 * Tests for lib/wp.php abstraction wrappers.
 */

declare(strict_types=1);

namespace PromptingPress\Tests;

use PHPUnit\Framework\TestCase;

class WpAbstractionTest extends TestCase
{
    // ── pp_field() ────────────────────────────────────────────────────────

    public function testPpFieldReturnsNullWhenAcfNotPresent(): void
    {
        // Our test bootstrap does NOT define get_field(), so pp_field()
        // should return null (the graceful fallback path).
        $this->assertFalse(
            function_exists('get_field'),
            'get_field() must NOT be defined for this test to be meaningful.'
        );

        $result = pp_field('any_field_name');
        $this->assertNull($result, 'pp_field() should return null when ACF is not installed.');
    }

    public function testPpFieldWithIdParameterReturnsNullWhenAcfNotPresent(): void
    {
        $result = pp_field('some_field', 42);
        $this->assertNull($result);
    }

    // ── pp_site_title() ───────────────────────────────────────────────────

    public function testPpSiteTitleCallsGetBloginfo(): void
    {
        // Our bootstrap stub returns 'Test Site' for get_bloginfo('name').
        $result = pp_site_title();
        $this->assertSame('Test Site', $result);
    }

    // ── pp_site_description() ─────────────────────────────────────────────

    public function testPpSiteDescriptionReturnsString(): void
    {
        $result = pp_site_description();
        $this->assertIsString($result);
        $this->assertSame('Test Description', $result);
    }

    // ── pp_site_url() ─────────────────────────────────────────────────────

    public function testPpSiteUrlReturnsHomeUrl(): void
    {
        $result = pp_site_url();
        $this->assertStringContainsString('example.com', $result);
    }

    public function testPpSiteUrlAppendsPath(): void
    {
        $result = pp_site_url('/about');
        $this->assertStringEndsWith('/about', $result);
    }

    // ── pp_page_title() ───────────────────────────────────────────────────

    public function testPpPageTitleReturnsString(): void
    {
        $result = pp_page_title();
        $this->assertIsString($result);
        $this->assertSame('Test Post Title', $result);
    }

    // ── pp_page_content() ─────────────────────────────────────────────────

    public function testPpPageContentReturnsHtmlString(): void
    {
        $result = pp_page_content();
        $this->assertIsString($result);
        $this->assertStringContainsString('<p>', $result);
    }

    // ── pp_excerpt() ──────────────────────────────────────────────────────

    public function testPpExcerptReturnsString(): void
    {
        $result = pp_excerpt();
        $this->assertIsString($result);
    }

    public function testPpExcerptRespectsWordLimit(): void
    {
        $result = pp_excerpt(3);
        $words  = explode(' ', trim($result));
        $this->assertLessThanOrEqual(3, count($words));
    }

    // ── pp_permalink() ────────────────────────────────────────────────────

    public function testPpPermalinkReturnsString(): void
    {
        $result = pp_permalink();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    // ── pp_thumbnail_url() ────────────────────────────────────────────────

    public function testPpThumbnailUrlReturnsString(): void
    {
        $result = pp_thumbnail_url();
        $this->assertIsString($result);
    }

    // ── pp_body_classes() ─────────────────────────────────────────────────

    public function testPpBodyClassesReturnsSpaceSeparatedString(): void
    {
        $result = pp_body_classes();
        $this->assertIsString($result);
        // Should be space-separated (not an array).
        $this->assertStringNotContainsString('[', $result);
    }

    // ── pp_is_front_page() ────────────────────────────────────────────────

    public function testPpIsFrontPageReturnsBool(): void
    {
        $result = pp_is_front_page();
        $this->assertIsBool($result);
    }

    // ── pp_composition() render-path legacy migration (issue #400) ─────────
    //
    // pp_composition() is the in-loop front-end renderer (used by
    // templates/composition.php + templates/front-page.php). Before #400 it
    // decoded _pp_composition raw and returned it, so a stored pre-#69
    // composition still carrying `variant` rendered with `variant` silently
    // ignored — the one reader that bypassed the shared migration every other
    // read path (editor / restore / inspect) applies. #400 routes the decoded
    // items through pp_migrate_stored_composition() (the same shared
    // pp_migrate_legacy_variant_keys() engine), no second migration.
    //
    // The get_the_ID() bootstrap stub returns 0, so pp_composition() reads
    // post_meta[0]; each test seeds that slot and tearDown clears it.

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition']);
        parent::tearDown();
    }

    public function testPpCompositionMigratesLegacyStructuralVariantOnRender(): void
    {
        // Structural component (hero): legacy `variant` maps to `layout`.
        $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'] = wp_json_encode([
            ['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Hi']],
        ]);

        $result = pp_composition();

        $this->assertArrayNotHasKey('variant', $result[0]['props'], 'Legacy variant must be migrated away on render.');
        $this->assertSame('split', $result[0]['props']['layout'], 'variant value must carry across to layout.');
        $this->assertSame('Hi', $result[0]['props']['heading'], 'Other props are untouched.');
    }

    public function testPpCompositionMigratesLegacyToneVariantOnRender(): void
    {
        // Tone component (section): legacy `variant` maps to `theme`.
        $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'] = wp_json_encode([
            ['component' => 'section', 'props' => ['variant' => 'dark']],
        ]);

        $result = pp_composition();

        $this->assertArrayNotHasKey('variant', $result[0]['props']);
        $this->assertSame('dark', $result[0]['props']['theme'], 'tone variant must migrate to theme.');
    }

    public function testPpCompositionRendersModernShapeUnchanged(): void
    {
        // Modern layout/theme shape carries no legacy key: migration is a no-op,
        // so the renderer returns the decoded composition with strict identity.
        $modern = [
            ['component' => 'hero', 'props' => ['layout' => 'split', 'heading' => 'Hi']],
            ['component' => 'section', 'props' => ['theme' => 'dark']],
        ];
        $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'] = wp_json_encode($modern);

        $result = pp_composition();

        $this->assertSame($modern, $result, 'Modern-shape compositions must render unchanged (migration is a no-op).');
    }

    public function testPpCompositionLeavesNonListShapeUnchanged(): void
    {
        // Defensive-parity pin: pp_composition() has never enforced list shape
        // (that is #144's domain, deliberately untouched by #400). The migration
        // engine is not recursive: it walks the TOP-LEVEL entries of whatever
        // array it is handed and only rewrites an entry that is itself an array
        // with a props['variant'] key AND a component mapped to layout/theme.
        // Here neither entry qualifies (the string is not an array; the nested
        // array has no `component`, so it maps to nothing), so the decoded object
        // passes through unchanged, exactly as the raw decode did before #400.
        $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'] = wp_json_encode([
            'meta' => 'not-a-list',
            'nested' => ['props' => ['variant' => 'x']],
        ]);

        $result = pp_composition();

        $this->assertSame(
            ['meta' => 'not-a-list', 'nested' => ['props' => ['variant' => 'x']]],
            $result,
            'Non-list shapes with no mapped-component entries are returned unchanged.'
        );
    }

    public function testPpCompositionMigratesModernKeyCollisionKeepsExplicitLayout(): void
    {
        // Mixed shape (both legacy `variant` and modern `layout` on the same
        // component): the shared engine's rule is "an explicit new key wins;
        // the legacy `variant` is then dropped" (lib/admin.php). Pin that the
        // renderer honors it — layout is kept, variant is removed, no clobber.
        $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'] = wp_json_encode([
            ['component' => 'hero', 'props' => ['variant' => 'stacked', 'layout' => 'split']],
        ]);

        $result = pp_composition();

        $this->assertArrayNotHasKey('variant', $result[0]['props'], 'Legacy variant is dropped even when a modern key exists.');
        $this->assertSame('split', $result[0]['props']['layout'], 'Explicit modern layout wins; variant must not overwrite it.');
    }

    public function testPpCompositionLeavesMalformedItemUntouched(): void
    {
        // Item with non-array (scalar) props: the engine guards
        // isset($item['props']) && is_array($item['props']) before touching it,
        // so a malformed item renders unchanged rather than crashing.
        $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'] = wp_json_encode([
            ['component' => 'hero', 'props' => 'not-an-array'],
            'bare-scalar-item',
        ]);

        $result = pp_composition();

        $this->assertSame(
            [['component' => 'hero', 'props' => 'not-an-array'], 'bare-scalar-item'],
            $result,
            'Malformed items pass through the migration untouched.'
        );
    }

    public function testPpCompositionReturnsEmptyForAbsentMeta(): void
    {
        unset($GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition']);
        $this->assertSame([], pp_composition(), 'Absent meta renders as an empty composition.');
    }

    public function testPpCompositionReturnsEmptyForInvalidJson(): void
    {
        $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'] = 'NOT_VALID_JSON{{{';
        $this->assertSame([], pp_composition(), 'Undecodable JSON renders as an empty composition.');
    }
}
