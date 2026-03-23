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
}
