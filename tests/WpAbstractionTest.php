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
    // templates/composition.php + templates/front-page.php). It decodes
    // _pp_composition and returns it, with NO name resolution of any kind (#604).
    //
    // #400 had routed it through pp_migrate_stored_composition() so a stored pre-#69
    // `variant` decoded to `layout`/`theme`, and #575 added the prop-key alias map to
    // the same shim. #604 deleted both surfaces and the shim itself, so this reader is
    // back to a plain decode — but now so is EVERY other read path, which is the point:
    // one contract, not a renderer that disagrees with the editor.
    //
    // The get_the_ID() bootstrap stub returns 0, so pp_composition() reads
    // post_meta[0]; each test seeds that slot and tearDown clears it.

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition']);
        parent::tearDown();
    }

    public function testPpCompositionReturnsLegacyVariantVerbatimOnRender(): void
    {
        // SUPERSEDES the two #400 migration pins (structural -> layout, tone -> theme).
        // Nothing migrates on render any more: the retired key reaches the renderer,
        // which does not read it, so the band renders with the schema default. The
        // authored structural/tone setting is LOST — intended, and pinned as such.
        $stored = [
            ['component' => 'hero',    'props' => ['variant' => 'split', 'heading' => 'Hi']],
            ['component' => 'section', 'props' => ['variant' => 'dark']],
        ];
        $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'] = wp_json_encode($stored);

        $result = pp_composition();

        $this->assertSame($stored, $result, 'the renderer hands back exactly what is stored');
        $this->assertArrayNotHasKey('layout', $result[0]['props'], 'no layout is manufactured from variant');
        $this->assertArrayNotHasKey('theme', $result[1]['props'], 'no theme is manufactured from variant');
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

    // ── pp_composition() resolves NO prop names (#604) ─────────────────────
    //
    // SUPERSEDES the #495/#576 render-resolution pins. A pre-1.0 `cta` stored
    // `cta_text`/`cta_url` and the renderer reads `button_text`/`button_url`; the alias
    // map used to bridge that on the render path. It is gone, so the retired key
    // reaches the renderer unread and the authored label is lost. The band still
    // renders and the stored bytes are still never mutated by a read.

    public function testPpCompositionReturnsRetiredPropNamesVerbatimOnRender(): void
    {
        $items  = [
            ['component' => 'cta',  'props' => ['cta_text' => 'View on GitHub', 'cta_url' => 'https://example.com/repo']],
            // A canonical band beside it: the removal must not touch correct data.
            ['component' => 'hero', 'props' => ['title' => 'Hi', 'button_text' => 'Get Started', 'button_url' => '/docs']],
        ];
        $stored = wp_json_encode($items);
        $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'] = $stored;

        $result = pp_composition();

        $this->assertSame($items, $result, 'the read is a plain decode: retired names survive, canonical ones are untouched');
        $this->assertArrayNotHasKey('button_text', $result[0]['props'], 'nothing manufactures the canonical key');
        $this->assertSame('Get Started', $result[1]['props']['button_text'], 'canonical props are unaffected by the removal');

        // A read still never mutates stored meta.
        $this->assertSame(
            $stored,
            $GLOBALS['_pp_test_store']['post_meta'][0]['_pp_composition'],
            'the render path must NOT mutate the stored composition.'
        );
    }

    public function testPpCompositionLeavesNonListShapeUnchanged(): void
    {
        // Defensive-parity pin: pp_composition() has never enforced list shape
        // (that is #144's domain, deliberately untouched). Since #604 there is no
        // migration engine left to walk the decoded value at all, so a non-list
        // shape passes through for the simplest possible reason — nothing touches
        // it. This pin now guards against a future reader re-introducing a walk.
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
