<?php
/**
 * tests/FrontPageRouteTest.php — what `/` renders, through the real templates (#1173).
 *
 * The routing seam is the root front-page.php: core loads it for ANY front page
 * (is_front_page() precedes is_home() in wp-includes/template-loader.php), including
 * Settings -> Reading "Your latest posts". Before #1173 it always rendered
 * templates/front-page.php, which resolved the front page from the current post: on a
 * latest-posts site that is the NEWEST BLOG POST, so a visitor GET seeded the default
 * homepage onto it and painted those bands with no CSS (the head answered [] there).
 *
 *   front-page.php ── pp_front_page_id() ─ 0 ──► templates/home.php  (the posts index)
 *                                        └ N ──► templates/front-page.php
 *                                                 locked ─► password form, nothing resolved
 *                                                 open ───► pp_resolve_front_page_render(N)
 *
 * Rendered whole: the shipped root file, the shipped templates and base.php, with core's
 * get_template_part() reduced to what it does here (locate the file, require it).
 * The resolver's own arms are FrontPageSafeguardTest's; the head's password gate is
 * ComposedPagePasswordTest's. tests/e2e/latest-posts-front-page.spec.ts runs the same
 * routes through real WordPress.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// The document shell base.php prints (as tests/PostsPageCompositionTest.php declares them;
// whichever file loads first defines them). Hooks do nothing: the head's CSS is pinned
// through its own functions.
if (!function_exists('language_attributes')) {
    function language_attributes(string $doctype = 'html'): void {}
}
if (!function_exists('wp_head')) {
    function wp_head(): void {}
}
if (!function_exists('wp_body_open')) {
    function wp_body_open(): void {}
}
if (!function_exists('wp_footer')) {
    function wp_footer(): void {}
}
if (!function_exists('bloginfo')) {
    function bloginfo(string $show = ''): void { echo esc_html((string) get_bloginfo($show)); }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void { echo esc_html($text); }
}
// Core's get_template_part(): locate `{$slug}.php` in the theme and load_template() it,
// which `require`s (never _once). The slug is logged so a test can name the route taken.
if (!function_exists('get_template_part')) {
    function get_template_part(string $slug, ?string $name = null, array $args = []) {
        $GLOBALS['_pp_test_template_parts'][] = $slug;
        require get_template_directory() . '/' . $slug . '.php';
    }
}

/** A posts listing as core's main query holds it on a latest-posts front page. */
final class PpFrontRouteQuery extends WP_Query
{
    public bool $is_posts_page = false; // core sets it for page_for_posts only
    public bool $is_search = false;
    public int $current_post = -1;
    /** @var object|null */
    public $post = null;

    /** @param int[] $ids */
    public function __construct(array $ids = [], int $pages = 1)
    {
        $this->posts         = $ids;
        $this->max_num_pages = $pages;
        $this->post          = $ids !== [] ? (object) ['ID' => $ids[0]] : null;
    }

    public function have_posts(): bool
    {
        return $this->current_post + 1 < count($this->posts);
    }

    public function the_post(): void
    {
        $this->current_post++;
        $this->post = (object) ['ID' => $this->posts[$this->current_post]];
        $GLOBALS['post'] = $this->post;
    }

    public function rewind_posts(): void
    {
        $this->current_post = -1;
        $this->post = $this->posts !== [] ? (object) ['ID' => $this->posts[0]] : null;
    }
}

class FrontPageRouteTest extends TestCase
{
    private const SECRET = 'Thursday at eight, members only';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        $GLOBALS['_pp_test_store']['is_front_page'] = true;
        $GLOBALS['_pp_test_template_parts'] = [];
        pp_udc_declare_template_components([]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['wp_query'], $GLOBALS['post'], $GLOBALS['_pp_test_template_parts'], $GLOBALS['_pp_test_password_entered']);
        unset($GLOBALS['_pp_test_store']['is_front_page'], $GLOBALS['_pp_test_store']['is_singular'], $GLOBALS['_pp_test_store']['queried_object_id']);
        pp_udc_declare_template_components([]);
        parent::tearDown();
    }

    /** Renders `/` through the shipped root front-page.php. */
    private function renderFront(): string
    {
        ob_start();
        require dirname(__DIR__) . '/front-page.php';
        return (string) ob_get_clean();
    }

    /**
     * Settings -> Reading "Your latest posts" with three published posts, the newest
     * current (core's state before the template runs). page_on_front still names the
     * page a static setup once used: core keeps the option and ignores it.
     *
     * @return int[] The post ids, newest first.
     */
    private function latestPostsSite(): array
    {
        $old = (int) pp_create_page('Old home', 'publish');
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'posts';
        $GLOBALS['_pp_test_store']['options']['page_on_front'] = $old;
        $ids = [];
        foreach (['Newest', 'Middle', 'Oldest'] as $title) {
            $id = (int) pp_create_page($title, 'publish');
            $GLOBALS['_pp_test_store']['posts'][$id]['post_type'] = 'post';
            $ids[] = $id;
        }
        $GLOBALS['wp_query'] = new PpFrontRouteQuery($ids);
        $GLOBALS['post'] = get_post($ids[0]);
        return $ids;
    }

    private function storedCompositions(): array
    {
        $found = [];
        foreach ($GLOBALS['_pp_test_store']['post_meta'] as $id => $meta) {
            if (array_key_exists('_pp_composition', $meta)) {
                $found[] = $id;
            }
        }
        return $found;
    }

    // ── show_on_front=posts: `/` is the posts index ──────────────────────────

    public function testALatestPostsFrontPageRendersThePostsIndex(): void
    {
        $this->latestPostsSite();

        $html = $this->renderFront();

        $this->assertSame(['templates/home'], $GLOBALS['_pp_test_template_parts'], 'a latest-posts front page is the posts index');
        $this->assertSame(3, substr_count($html, 'Read post'), 'one card per post of the main query');
        $this->assertStringNotContainsString('home-hero', $html, 'the default homepage composition is not painted');
    }

    public function testALatestPostsFrontPageWritesNothing(): void
    {
        $this->latestPostsSite();
        $before = $GLOBALS['_pp_test_store']['post_meta'];

        $this->renderFront();

        $this->assertSame([], $this->storedCompositions(), 'a visitor GET writes no composition onto any post');
        $this->assertSame($before, $GLOBALS['_pp_test_store']['post_meta'], 'nor any other meta');
    }

    public function testALatestPostsFrontPagePrintsItsBandsDefaults(): void
    {
        // The head reads the template's declaration (#1171). The bands this route paints
        // are home.php's, so their role defaults are printed, never a bare render.
        $this->latestPostsSite();

        $this->renderFront();

        $this->assertSame([], pp_udc_current_composition(), 'no composition on this route');
        $declared = pp_udc_template_components();
        sort($declared);
        $this->assertSame(['grid', 'hero', 'section'], $declared);
        $css = pp_udc_page_defaults_css(pp_udc_request_defaults_items(pp_udc_current_composition()));
        $this->assertStringContainsString('[data-pp-component="hero"]', $css);
        $this->assertStringContainsString('[data-pp-component="grid"]', $css);
    }

    public function testAProtectedNewestPostsCompositionNeverRendersOnALatestPostsFrontPage(): void
    {
        // Ruling 2 (#1173): the newest post carries a password AND a stored composition.
        // `/` lists it as core lists any protected post; none of its bands, its text or its
        // band CSS reach the page, whether or not the visitor holds the password.
        $ids = $this->latestPostsSite();
        $GLOBALS['_pp_test_store']['posts'][$ids[0]]['post_password'] = 'opensesame';
        $this->assertNotWPError(pp_update_composition($ids[0], [[
            'component' => 'section',
            'props'     => ['title' => 'Members', 'body' => '<p>' . self::SECRET . '</p>'],
        ]]));

        foreach ([false, true] as $entered) {
            $GLOBALS['_pp_test_password_entered'] = $entered;
            $GLOBALS['_pp_test_template_parts'] = [];
            $GLOBALS['post'] = get_post($ids[0]);
            $html = $this->renderFront();
            $this->assertStringNotContainsString(self::SECRET, $html, $entered ? 'password entered' : 'no password');
            $this->assertSame([], pp_udc_current_composition(), 'the head emits none of its band CSS');
        }
        // The head's gate reads the queried object only (ruling 2): nothing is queried here.
        $this->assertNull(pp_composition_locked_page());
    }

    public function testALatestPostsFrontPageWithNoPostsSaysSo(): void
    {
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'posts';
        $GLOBALS['wp_query'] = new PpFrontRouteQuery([]);

        $html = $this->renderFront();

        $this->assertSame(['templates/home'], $GLOBALS['_pp_test_template_parts']);
        $this->assertStringContainsString('No posts found.', $html);
        $this->assertStringNotContainsString('Homepage not configured', $html);
    }

    // ── show_on_front=page: the static front page ────────────────────────────

    private function staticFrontPage(): int
    {
        $id = (int) pp_create_page('Home', 'publish');
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'page';
        $GLOBALS['_pp_test_store']['options']['page_on_front'] = $id;
        $GLOBALS['_pp_test_store']['is_singular']       = true;
        $GLOBALS['_pp_test_store']['queried_object_id'] = $id;
        $GLOBALS['post'] = get_post($id);
        return $id;
    }

    public function testAStaticFrontPageRendersItsComposition(): void
    {
        $id = $this->staticFrontPage();
        $this->assertNotWPError(pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Welcome home']]]));

        $html = $this->renderFront();

        $this->assertSame(['templates/front-page'], $GLOBALS['_pp_test_template_parts']);
        $this->assertStringContainsString('Welcome home', $html);
        $this->assertSame(pp_get_composition($id), pp_udc_current_composition(), 'head and body resolve the same page');
    }

    public function testTheBodyResolvesTheQueriedPageNeverTheCurrentPost(): void
    {
        // #1173's mechanism: the current post is not the front page whenever something
        // moved it (a secondary loop that never reset postdata; on a latest-posts site, the
        // newest post). The body resolves the page core queried, as the head does.
        $id = $this->staticFrontPage();
        $this->assertNotWPError(pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'Queried home']]]));
        $other = (int) pp_create_page('Stray', 'publish');
        $this->assertNotWPError(pp_update_composition($other, [['component' => 'hero', 'props' => ['title' => 'Stray loop post']]]));
        $GLOBALS['post'] = get_post($other);

        $html = $this->renderFront();

        $this->assertStringContainsString('Queried home', $html);
        $this->assertStringNotContainsString('Stray loop post', $html);
        $this->assertSame(pp_get_composition($id), pp_udc_current_composition());
    }

    public function testAnUnseededStaticFrontPageIsSeededOnce(): void
    {
        // #506's blank-page promise on the CONFIGURED front page is unchanged.
        $id = $this->staticFrontPage();

        $html = $this->renderFront();

        $this->assertSame([$id], $this->storedCompositions());
        $this->assertSame(1, (int) get_post_meta($id, '_pp_composition_version', true));
        $this->assertStringContainsString('data-pp-component="hero"', $html);
    }

    public function testAProtectedStaticFrontPageIsGatedBeforeAnythingIsResolved(): void
    {
        // Ruling 1 (#1173): the password gate runs BEFORE the resolver. With no stored
        // composition the resolver would seed the defaults; a visitor without the password
        // must not cause that write, and sees the title and core's form only.
        $id = $this->staticFrontPage();
        $GLOBALS['_pp_test_store']['posts'][$id]['post_password'] = 'opensesame';

        $html = $this->renderFront();

        $this->assertSame([], $this->storedCompositions(), 'no seed behind a password');
        $this->assertSame(0, (int) get_post_meta($id, '_pp_composition_version', true));
        $this->assertStringContainsString('data-test-post="' . $id . '"', $html, "core's password form for the front page");
        $this->assertStringNotContainsString('data-pp-component', $this->main($html), 'no band inside <main>');
        $this->assertSame([], pp_udc_current_composition());
    }

    public function testAProtectedStaticFrontPageRendersOnceThePasswordIsEntered(): void
    {
        $id = $this->staticFrontPage();
        $GLOBALS['_pp_test_store']['posts'][$id]['post_password'] = 'opensesame';
        $GLOBALS['_pp_test_password_entered'] = true;

        $html = $this->renderFront();

        $this->assertStringNotContainsString('post-password-form', $html);
        $this->assertStringContainsString('data-pp-component="hero"', $html, 'the seeded defaults render for a visitor with the password');
        $this->assertSame([$id], $this->storedCompositions());
    }

    /** The page body base.php wraps in <main> (chrome outside it carries data-pp-component too). */
    private function main(string $html): string
    {
        $start = strpos($html, '<main');
        $end   = strpos($html, '</main>');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        return substr($html, $start, $end - $start);
    }

    public function testAPageCoreTreatsAsTheFrontPageRendersItselfNeverTheConfiguredOne(): void
    {
        // Core's is_page(page_on_front) also matches a page whose TITLE is the front page's
        // id, so core loads front-page.php for it. That request renders the page core
        // queried, gated by that page's own password, never the configured front page
        // (here protected, with its own bands) and never a seed of it (/review).
        $front = $this->staticFrontPage();
        $GLOBALS['_pp_test_store']['posts'][$front]['post_password'] = 'opensesame';
        $this->assertNotWPError(pp_update_composition($front, [[
            'component' => 'section',
            'props'     => ['title' => 'Members', 'body' => '<p>' . self::SECRET . '</p>'],
        ]]));
        $decoy = (int) pp_create_page((string) $front, 'publish');
        $this->assertNotWPError(pp_update_composition($decoy, [['component' => 'hero', 'props' => ['title' => 'The decoy itself']]]));
        $GLOBALS['_pp_test_store']['queried_object_id'] = $decoy;
        $GLOBALS['post'] = get_post($decoy);
        $before = $GLOBALS['_pp_test_store']['post_meta'];

        $html = $this->renderFront();

        $this->assertStringNotContainsString(self::SECRET, $html, 'the configured front page is not painted here');
        $this->assertStringContainsString('The decoy itself', $html);
        $this->assertSame(pp_get_composition($decoy), pp_udc_current_composition(), 'head and body resolve the same page');
        $this->assertSame($before, $GLOBALS['_pp_test_store']['post_meta'], 'and nothing is written');
    }

    public function testAPageCoreTreatsAsTheFrontPageWithNothingStoredIsNotSeeded(): void
    {
        $front = $this->staticFrontPage();
        $decoy = (int) pp_create_page((string) $front, 'publish');
        $GLOBALS['_pp_test_store']['queried_object_id'] = $decoy;
        $GLOBALS['post'] = get_post($decoy);

        $this->renderFront();

        $this->assertSame([], $this->storedCompositions(), 'neither the queried page nor the configured one is seeded');
        $this->assertSame([], pp_udc_current_composition());
    }

    private function assertNotWPError($value): void
    {
        $this->assertFalse(is_wp_error($value), 'the composition must store through the real writer');
    }
}
