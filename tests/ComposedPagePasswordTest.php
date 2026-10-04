<?php
/**
 * tests/ComposedPagePasswordTest.php
 *
 * Composed pages honour post passwords, the way core's own content does.
 *
 * A page that renders a stored composition (templates/composition.php, and the static
 * front page through templates/front-page.php) shows core's password form until the
 * visitor has entered the page's password, and only then its bands. The head follows
 * the same rule: the page's band CSS is emitted only once the password cookie is there.
 *
 * ONE PREDICATE, TWO SIDES (the pattern of the posts-page gate, #1181). The body gate
 * sits in the shared band loop, pp_render_composition_bands(), which every request
 * template renders through; the head gate sits in pp_udc_current_composition(), which
 * the emitter reads. Both ask pp_composition_locked_page(), so head and body
 * cannot disagree.
 *
 * Authored through the real writer (pp_update_composition). Core's cookie check is
 * stubbed here (tests/bootstrap.php: `_pp_test_password_entered`); the real cookie flow
 * is pinned in the browser by tests/e2e/composed-page-password.spec.ts.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ComposedPagePasswordTest extends TestCase
{
    private const BAND_ID = 'pp-5e6f7a8b';
    private const INK     = '#7a3e9d';

    private int $pageId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        unset($GLOBALS['wp_query']); // not the posts index
        $this->pageId = (int) pp_create_page('Members', 'publish');
        $this->assertNotWPError(pp_update_composition($this->pageId, self::composition()));
        $GLOBALS['_pp_test_store']['is_singular']       = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $this->pageId;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['_pp_test_password_entered']);
        unset($GLOBALS['_pp_test_store']['is_front_page'], $GLOBALS['_pp_test_store']['is_singular'], $GLOBALS['_pp_test_store']['queried_object_id']);
        foreach (['post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages'] as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function assertNotWPError($value): void
    {
        $this->assertFalse(is_wp_error($value), 'the composition must store through the real writer');
    }

    private static function composition(): array
    {
        return [[
            'component' => 'section',
            'id'        => self::BAND_ID,
            'props'     => ['title' => 'Members-only schedule', 'body' => '<p>Thursday at eight.</p>'],
            'udc'       => ['body' => ['typography' => ['color' => self::INK]]],
        ]];
    }

    private function protect(int $postId): void
    {
        $GLOBALS['_pp_test_store']['posts'][$postId]['post_password'] = 'opensesame';
    }

    private function render(int $ownerPageId = 0): string
    {
        ob_start();
        pp_render_composition_bands(pp_get_composition($this->pageId), $ownerPageId);
        return (string) ob_get_clean();
    }

    // ── Body ────────────────────────────────────────────────────────────────

    public function testAProtectedPageShowsThePasswordFormAndNoBands(): void
    {
        $this->protect($this->pageId);
        $html = $this->render();
        $this->assertStringContainsString('class="post-password-form"', $html);
        $this->assertStringNotContainsString('data-pp-component', $html, 'no band markup');
        $this->assertStringNotContainsString('Members-only schedule', $html);
        $this->assertStringNotContainsString('Thursday at eight.', $html);
    }

    public function testTheFormIsCoresOwnOutputForThisPageEchoedVerbatim(): void
    {
        $this->protect($this->pageId);
        $html = $this->render();
        $this->assertStringContainsString(get_the_password_form(get_post($this->pageId)), $html,
            'core\'s (filterable) form, for this page, never routed through a rich-text sink that strips <form>/<input>');
        $this->assertStringContainsString('<div class="container"', $html, 'in the theme\'s content container');
    }

    public function testTheProtectedPageKeepsItsHeading(): void
    {
        $GLOBALS['_pp_test_store']['posts'][$this->pageId]['post_title'] = 'Members & <guests>';
        $this->protect($this->pageId);
        $html = $this->render();
        $this->assertStringContainsString('>Members &amp; &lt;guests&gt;</h1>', $html, 'the page title, escaped, above the form');
        $this->assertLessThan(strpos($html, 'post-password-form'), strpos($html, '<h1'));
    }

    public function testTheBandsRenderOnceThePasswordIsEntered(): void
    {
        $this->protect($this->pageId);
        $GLOBALS['_pp_test_password_entered'] = true;
        $html = $this->render();
        $this->assertStringContainsString('data-pp-band="' . self::BAND_ID . '"', $html);
        $this->assertStringContainsString('Thursday at eight.', $html);
        $this->assertStringNotContainsString('post-password-form', $html);
    }

    public function testAPageWithoutAPasswordRendersItsBandsAsBefore(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('data-pp-band="' . self::BAND_ID . '"', $html);
        $this->assertStringNotContainsString('post-password-form', $html);
    }

    public function testTheGateReadsTheQueriedPageNeverTheCurrentPost(): void
    {
        // Core's post_password_required() reads the GLOBAL post when handed an empty id.
        // The gate must ask about the page the request is for, so a protected post that
        // merely happens to be current neither gates nor un-gates the bands.
        $other = (int) pp_create_page('Elsewhere', 'publish');
        $this->protect($other);
        $GLOBALS['post'] = get_post($other);

        $GLOBALS['_pp_test_store']['queried_object_id'] = 0;
        $this->assertStringNotContainsString('post-password-form', $this->render(), 'nothing queried: no gate');

        $GLOBALS['_pp_test_store']['queried_object_id'] = $this->pageId;
        $this->assertStringNotContainsString('post-password-form', $this->render(), 'the queried page is not protected');

        $this->protect($this->pageId);
        $GLOBALS['post'] = get_post($other);
        $this->assertStringContainsString('data-test-post="' . $this->pageId . '"', $this->render(), 'the form is for the queried page');
    }

    public function testNoPageMeansNoGate(): void
    {
        // A queried object that is not a post (an id the store does not hold stands in
        // for a term or user archive), and an owner id that names no post: neither is a
        // page with a password, so the bands render.
        $this->protect($this->pageId);
        $GLOBALS['_pp_test_store']['queried_object_id'] = 987654;
        $this->assertNull(pp_composition_locked_page());
        $this->assertStringContainsString('data-pp-band="' . self::BAND_ID . '"', $this->render());

        $GLOBALS['_pp_test_store']['queried_object_id'] = $this->pageId;
        $this->assertNull(pp_composition_locked_page(987654), 'an owner id that names no post');
    }

    public function testWithAnOwnerPageTheOwnerDecides(): void
    {
        // The loop's $owner_page_id names the page the bands belong to (the posts page on
        // /blog/, where it is also the queried page). The gate asks about that page.
        // (The owner loop with the password entered sets the owner up as the current post;
        // PostsPageCompositionTest models that post state and pins it.)
        $owner = (int) pp_create_page('Blog', 'publish');
        $this->protect($owner);
        $html = $this->render($owner);
        $this->assertStringContainsString('data-test-post="' . $owner . '"', $html);
        $this->assertStringNotContainsString('data-pp-component', $html);
    }

    // ── Head ────────────────────────────────────────────────────────────────

    public function testAuthoredCssWaitsForThePasswordCookie(): void
    {
        $this->assertStringContainsString(self::INK, pp_udc_page_authored_css(pp_udc_current_composition()), 'unprotected: as before');

        $this->protect($this->pageId);
        $this->assertSame([], pp_udc_current_composition());
        $this->assertSame('', pp_udc_page_authored_css(pp_udc_current_composition()));

        $GLOBALS['_pp_test_password_entered'] = true;
        $this->assertSame(pp_get_composition($this->pageId), pp_udc_current_composition());
        $this->assertStringContainsString(self::INK, pp_udc_page_authored_css(pp_udc_current_composition()));
    }

    public function testAProtectedFrontPageHeadEmitsNoBandCssAndDoesNotSeed(): void
    {
        $GLOBALS['_pp_test_store']['is_front_page'] = true;
        $this->protect($this->pageId);
        $this->assertSame([], pp_udc_current_composition(), 'stored composition: no band CSS');

        // Absent meta: the head must not reach the front-page arm, which would seed one.
        unset($GLOBALS['_pp_test_store']['post_meta'][$this->pageId]['_pp_composition']);
        $this->assertSame([], pp_udc_current_composition());
        $this->assertArrayNotHasKey('_pp_composition', $GLOBALS['_pp_test_store']['post_meta'][$this->pageId] ?? []);
    }

    public function testAProtectedFrontPageBodyShowsThePasswordForm(): void
    {
        // What templates/front-page.php does: resolve the front page, then the band loop.
        // (Whether the resolver may seed an ABSENT composition on a render is #1173's
        // subject, not this file's: only the form and the absence of bands are pinned.)
        $GLOBALS['_pp_test_store']['is_front_page'] = true;
        $this->protect($this->pageId);
        foreach (['stored' => false, 'absent' => true] as $case => $absent) {
            if ($absent) {
                unset($GLOBALS['_pp_test_store']['post_meta'][$this->pageId]['_pp_composition']);
            }
            $render = pp_resolve_front_page_render($this->pageId);
            ob_start();
            pp_render_composition_bands($render['composition']);
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('data-test-post="' . $this->pageId . '"', $html, $case);
            $this->assertStringNotContainsString('data-pp-component', $html, $case);
        }
    }

    public function testAProtectedFrontPageResolvesOnceThePasswordIsEntered(): void
    {
        $GLOBALS['_pp_test_store']['is_front_page'] = true;
        $this->protect($this->pageId);
        $GLOBALS['_pp_test_password_entered'] = true;
        $this->assertSame(pp_get_composition($this->pageId), pp_udc_current_composition());
    }
}
