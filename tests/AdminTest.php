<?php
/**
 * tests/AdminTest.php — PHPUnit tests for lib/admin.php helpers.
 *
 * Covers the pure, output-free decision helpers behind the composition
 * workspace admin page (the thin wp_safe_redirect/exit and render glue is not
 * unit-testable and is exercised via E2E).
 */

use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    // ── #160: composition-editor redirect for a missing/GC'd post ───────────

    public function testRedirectUrlForMissingOrGcdPost(): void
    {
        // A bookmarked URL for a post WordPress has since force-deleted (the
        // ~7-day auto-draft GC) resolves to no post → redirect to the Pages list.
        $url = pp_composition_missing_post_redirect_url(4242);
        $this->assertSame('https://example.com/wp-admin/edit.php?post_type=page', $url);
    }

    public function testRedirectUrlForNonPagePost(): void
    {
        $GLOBALS['_pp_test_store']['posts'][7] = [
            'post_type'   => 'post',
            'post_status' => 'publish',
        ];
        $url = pp_composition_missing_post_redirect_url(7);
        $this->assertSame('https://example.com/wp-admin/edit.php?post_type=page', $url);
    }

    public function testNoRedirectForRealPage(): void
    {
        $id = pp_create_page('Real Page', 'draft');
        $this->assertNull(pp_composition_missing_post_redirect_url($id));
    }

    public function testNoRedirectForAbsentPostId(): void
    {
        // No post specified — the render callback reports "No page specified.";
        // the load hook must not redirect.
        $this->assertNull(pp_composition_missing_post_redirect_url(0));
    }
}
