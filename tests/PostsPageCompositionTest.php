<?php
/**
 * tests/PostsPageCompositionTest.php
 *
 * #1181 — template authoring, slice 1: the posts page renders its stored composition,
 * with a query-bound grid source (`items_source: "posts"`) supplying the listing.
 *
 * THE GAP. `templates/home.php` rendered a hard-coded hero + grid, and the emitter read
 * nothing on the posts index (`pp_udc_current_composition()` returns [] on every
 * non-singular request), so /blog/ could not be authored at all.
 *
 * THE RULINGS (orchestrator, 2026-09-26; recorded in #1181's body):
 *   D1 one band-loop helper for composition.php, front-page.php and home.php;
 *   D2 `items_source` is refused at write unless the page is the current posts page
 *      (band-scoped per #1007; create_page refuses it outright), stored drift carries a
 *      finding, and off the posts index the band renders the grid's empty state;
 *   D3 `items` stays required: a listing band stores `items: []`, and a non-empty
 *      `items` beside `items_source` is refused through `refuse_props_when`;
 *   D4 the card fields are today's, through the grid's existing sinks;
 *   D5 a posts-page composition without a listing band carries a finding.
 *
 * WHAT THIS FILE PROVES (Layer-3 postures: no new sink, reject-never-coerce, no silent
 * ignore — the #570 convergence rule):
 *   1. one resolver, keyed on core's own `is_posts_page`, feeds head and body alike;
 *   2. a query-bound grid renders EXACTLY what the same grid renders with the listing
 *      authored as items — the same code path, so the same sinks — and restores the main
 *      query's loop state;
 *   3. the write surface refuses what the render cannot honour, and never blocks the
 *      removal of a stale value;
 *   4. what storage can still drift into is disclosed, never silently dropped;
 *   5. the three request templates share one band loop, and home.php renders the stored
 *      composition only when there is one this visitor may see — with the posts page as
 *      the current post while its bands render (set up only when it is not already
 *      current, so `the_post` fires once, and once more after the listing), and the post
 *      state main's /blog/ leaves put back afterwards (also when a band throws);
 *   6. the in-admin assistant is told which page is the posts page and what it accepts;
 *   7. the branches the coverage audit found unpinned (message band list, the budgeted
 *      skip, refusal wording, trashed posts page, finding index, restore on a throw).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// The document shell templates/base.php prints around a template's bands, so home.php can
// be rendered whole here. Kept out of tests/bootstrap.php on purpose: TemplateBandDefaultsTest
// runs base.php in a child process with its OWN wp_head() stub, which a bootstrap stub would
// pre-empt. Hooks do nothing here: the head's CSS is pinned through its own functions.
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
// Records which post each band is set up for, interleaved with the main query's own
// the_post() calls, so the posts page's bands can be seen to run with the PAGE as the
// current post (not a blog post of the listing).
if (!function_exists('setup_postdata')) {
    function setup_postdata($post): bool {
        // The global current post is logged too: it is what get_the_ID()/get_post() read.
        $GLOBALS['_pp_test_postdata_log'][] = 'setup:' . (is_object($post) ? $post->ID : (int) $post)
            . '/post:' . (is_object($GLOBALS['post'] ?? null) ? $GLOBALS['post']->ID : '-');
        // As core's WP_Query::setup_postdata(): it sets EVERY template global and fires the
        // `the_post` action, which plugins (view counters) listen to.
        foreach (pp_test_generate_postdata($post) as $name => $value) {
            $GLOBALS[$name] = $value;
        }
        $GLOBALS['_pp_test_the_post_fires'] = ($GLOBALS['_pp_test_the_post_fires'] ?? 0) + 1;
        return true;
    }
}
// The template globals core's WP_Query::generate_postdata() computes for a post (the
// same names setup_postdata() writes), with values that identify the post.
function pp_test_generate_postdata($post): array {
    $id = is_object($post) ? $post->ID : (int) $post;
    return [
        'id' => $id, 'authordata' => 'author of ' . $id, 'currentday' => 'day ' . $id,
        'currentmonth' => 'month ' . $id, 'page' => 1, 'pages' => ['content of ' . $id],
        'multipage' => 0, 'more' => 0, 'numpages' => 1,
    ];
}
if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void { echo esc_html($text); }
}

/**
 * A main query the grid can iterate: core's loop API, the `is_posts_page` flag core sets
 * on the posts index (wp-includes/class-wp-query.php, `is_posts_page` / queried object),
 * and a position the loop-state invariant can be read from.
 */
final class PpPostsIndexQuery extends WP_Query
{
    public bool $is_posts_page = false;
    public bool $is_search = false;
    public int $current_post = -1;
    public int $the_post_calls = 0;
    /** @var object|null Core's WP_Query::$post: the current post, the first one before the loop. */
    public $post = null;

    public function __construct(int $count = 0, bool $postsPage = true, int $pages = 1)
    {
        $this->posts         = $count > 0 ? range(1, $count) : [];
        $this->is_posts_page = $postsPage;
        $this->max_num_pages = $pages;
        $this->post          = $count > 0 ? (object) ['ID' => 9000] : null;
    }

    public function generate_postdata($post): array
    {
        return pp_test_generate_postdata($post);
    }

    public function have_posts(): bool
    {
        return $this->current_post + 1 < count($this->posts);
    }

    public function the_post(): void
    {
        $this->current_post++;
        $this->post = (object) ['ID' => 9000 + $this->current_post];
        $this->the_post_calls++;
        $GLOBALS['_pp_test_postdata_log'][] = 'the_post';
        // Core's the_post() makes each listed post current. Modelled only where a test asks,
        // because the bootstrap's wp_reset_postdata() is a no-op that cannot put it back.
        if (!empty($GLOBALS['_pp_test_model_post_global'])) {
            $GLOBALS['post'] = (object) ['ID' => 9000 + $this->current_post];
        }
    }

    public function rewind_posts(): void
    {
        $this->current_post = -1;
        $this->post         = $this->posts !== [] ? (object) ['ID' => 9000] : null;
    }
}

class PostsPageCompositionTest extends TestCase
{
    private int $postsPage = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = ['post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        $this->postsPage = (int) pp_create_page('Blog', 'publish');
        $GLOBALS['_pp_test_store']['options']['show_on_front']  = 'page';
        $GLOBALS['_pp_test_store']['options']['page_for_posts'] = $this->postsPage;
        $GLOBALS['wp_query'] = new PpPostsIndexQuery(3, true, 1);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['wp_query'], $GLOBALS['_pp_test_user_caps'], $GLOBALS['_pp_test_password_entered'], $GLOBALS['_pp_test_model_post_global']);
        unset($GLOBALS['_pp_test_store']['page_template_slug']);
        // The template globals the post-state tests set (as core's setup_postdata() does).
        foreach (['post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages', '_pp_test_postdata_log', '_pp_test_the_post_fires'] as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function store(int $postId, array $composition): void
    {
        $GLOBALS['_pp_test_store']['post_meta'][$postId]['_pp_composition'] = wp_json_encode($composition);
    }

    private static function listingBand(array $extra = []): array
    {
        return ['component' => 'grid', 'props' => array_merge(['title' => 'Latest', 'items_source' => 'posts', 'items' => []], $extra)];
    }

    private static function renderGrid(array $props): string
    {
        ob_start();
        pp_get_component('grid', $props);
        return (string) ob_get_clean();
    }

    // ── 1. One resolver, keyed on core's posts-index flag ───────────────────

    public function testThePostsIndexIsCoresOwnIsPostsPageFlag(): void
    {
        $this->assertTrue(pp_is_posts_index());
        $GLOBALS['wp_query'] = new PpPostsIndexQuery(3, false);
        $this->assertFalse(pp_is_posts_index(), 'a latest-posts front page (#1173) or any other route is not the posts index');
        unset($GLOBALS['wp_query']);
        $this->assertFalse(pp_is_posts_index(), 'no main query at all is not the posts index');
    }

    public function testThePostsPageIdIsTheConfiguredPostsPageOnAStaticFrontSetup(): void
    {
        $this->assertTrue(pp_is_posts_page_id($this->postsPage));
        $this->assertFalse(pp_is_posts_page_id($this->postsPage + 1));
        $this->assertFalse(pp_is_posts_page_id(0));
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'posts';
        $this->assertFalse(pp_is_posts_page_id($this->postsPage), 'with show_on_front=posts there is no posts page');
    }

    public function testTheResolverReturnsTheStoredCompositionOnlyOnThePostsIndex(): void
    {
        $this->assertSame([], pp_posts_page_composition(), 'nothing stored: today\'s bands');
        $this->store($this->postsPage, [self::listingBand()]);
        $this->assertSame('grid', pp_posts_page_composition()[0]['component'] ?? null);
        $GLOBALS['wp_query'] = new PpPostsIndexQuery(3, false);
        $this->assertSame([], pp_posts_page_composition(), 'off the posts index the posts page composition is not this route\'s');
    }

    public function testAnEmptyOrCorruptPostsPageCompositionFallsBackAndWritesNothing(): void
    {
        $this->store($this->postsPage, []);
        $this->assertSame([], pp_posts_page_composition());
        $GLOBALS['_pp_test_store']['post_meta'][$this->postsPage]['_pp_composition'] = '{not json';
        $this->assertSame([], pp_posts_page_composition());
        $this->assertSame('{not json', $GLOBALS['_pp_test_store']['post_meta'][$this->postsPage]['_pp_composition'], 'a render never heals storage (#506)');
    }

    public function testTheEmitterReadsThePostsPageCompositionThroughTheSameResolver(): void
    {
        $this->store($this->postsPage, [self::listingBand()]);
        $GLOBALS['_pp_test_store']['is_singular'] = false;
        $this->assertSame(pp_posts_page_composition(), pp_udc_current_composition(), 'head and body resolve the posts page the same way');
        $this->assertNotSame([], pp_udc_current_composition());
    }

    public function testAPostsPageTheVisitorMayNotSeeRendersTodaysBands(): void
    {
        // Core keeps /blog/ serving the post listing whatever the page's own status, and
        // clears page_for_posts only on trash. A page taken out of public view keeps its
        // composition out of public view too (/review security, #1181).
        $this->store($this->postsPage, [self::listingBand()]);
        $GLOBALS['_pp_test_user_caps'] = ['read_post' => false]; // a logged-out visitor
        // A PUBLISHED posts page is public: a visitor who holds no capability sees it.
        $this->assertSame('grid', pp_posts_page_composition()[0]['component'] ?? null, 'publish: body');
        $this->assertNotSame([], pp_udc_current_composition(), 'publish: head');
        foreach (['draft', 'pending', 'private'] as $status) {
            $GLOBALS['_pp_test_store']['posts'][$this->postsPage]['post_status'] = $status;
            $this->assertSame([], pp_posts_page_composition(), $status . ': body');
            $this->assertSame([], pp_udc_current_composition(), $status . ': head');
        }
        $GLOBALS['_pp_test_user_caps'] = ['read_post' => true]; // an editor previewing the draft
        $this->assertSame('grid', pp_posts_page_composition()[0]['component'] ?? null, 'a viewer who may read the page sees it');

        $GLOBALS['_pp_test_store']['posts'][$this->postsPage]['post_status']   = 'publish';
        $GLOBALS['_pp_test_store']['posts'][$this->postsPage]['post_password'] = 'secret';
        $this->assertSame([], pp_posts_page_composition(), 'password-protected: not without the password');
        $GLOBALS['_pp_test_password_entered'] = true;
        $this->assertSame('grid', pp_posts_page_composition()[0]['component'] ?? null, 'with the password entered');
    }

    public function testThePostsPagesTemplateMetaDoesNotDecideTheRoute(): void
    {
        // Core renders the posts index through home.php whatever template the page
        // carries, and every writer accepts a composition on it: what is stored renders
        // (/review Codex: a render gate the write path did not share accepted a band
        // that could never appear).
        $this->store($this->postsPage, [self::listingBand()]);
        $GLOBALS['_pp_test_store']['page_template_slug'] = 'elementor_canvas';
        $this->assertSame('grid', pp_posts_page_composition()[0]['component'] ?? null);
    }

    public function testASearchOnThePostsPageRouteIsNotThePostsIndex(): void
    {
        // /blog/?s=term: core keeps is_posts_page set, but the template loader picks
        // search.php, so the head must not emit the posts page's bands.
        $this->store($this->postsPage, [self::listingBand()]);
        $GLOBALS['wp_query']->is_search = true;
        $this->assertFalse(pp_is_posts_index());
        $this->assertSame([], pp_udc_current_composition());
    }

    public function testThePostsPageIsAmongTheCompositionPagesWhateverItsTemplateMeta(): void
    {
        // A posts page made outside the theme's Add New flow has no `composition.php`
        // template meta, yet it renders its composition at /blog/: the site-wide tools
        // (validate site, the assistant's page list, the preset reference gate) must see it.
        // With its composition.php meta (the theme's own Add New flow) the query already
        // returns it: listed once, not twice.
        $once = static fn (array $pages, int $id): int => count(array_keys(array_column($pages, 'id'), $id));
        $this->assertSame(1, $once(pp_composition_pages(true), $this->postsPage), 'listed once (query + posts page)');
        $this->assertSame(1, $once(pp_composition_pages_for_reference_gate(), $this->postsPage), 'listed once in the gate');

        unset($GLOBALS['_pp_test_store']['post_meta'][$this->postsPage]['_wp_page_template']);
        $GLOBALS['_pp_test_store']['page_template_slug'] = '';
        $this->assertContains($this->postsPage, array_column(pp_composition_pages(true), 'id'));
        $this->assertContains($this->postsPage, array_column(pp_composition_pages_for_reference_gate(), 'id'));
        $this->assertSame(1, $once(pp_composition_pages(true), $this->postsPage), 'listed once');
        // In title order among the others (the query's own order).
        pp_create_page('About', 'publish');
        pp_create_page('Zeta', 'publish');
        $this->assertSame(['About', 'Blog', 'Zeta'], array_column(pp_composition_pages(true), 'title'));

        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'posts';
        $this->assertNotContains($this->postsPage, array_column(pp_composition_pages(true), 'id'), 'no posts page, no extra entry');
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'page';
        $GLOBALS['_pp_test_store']['page_template_slug'] = 'elementor_canvas';
        $this->assertContains($this->postsPage, array_column(pp_composition_pages(true), 'id'), 'its template meta does not decide: home.php renders it');
    }

    // ── 2. The query-bound grid: the authored card path, loop state restored ─

    public function testAQueryBoundGridRendersTheMainQueryThroughTheAuthoredCardPath(): void
    {
        $bound = self::renderGrid(['items_source' => 'posts', 'items' => []]);
        $GLOBALS['wp_query']->rewind_posts();
        $cards = pp_posts_listing_items();
        $this->assertCount(3, $cards, 'one card per post in the main query');
        $this->assertSame(
            ['title', 'text', 'image_url', 'link_url', 'link_text'],
            array_keys($cards[0]),
            'D4: today\'s fields, nothing more'
        );
        $authored = self::renderGrid(['items' => $cards]);
        $this->assertSame($authored, $bound, 'same code path as authored cards, so the same sinks (esc_html, pp_kses_inline, esc_url, responsive image)');
        $this->assertSame(3, substr_count($bound, '<li class="grid__item"'), 'three cards');
    }

    public function testTheListingMapperMatchesTheFallbackTemplatesMapping(): void
    {
        // home.php's fallback builds its cards inline (left untouched: the byte-identical
        // contract). The mapper must produce the same arrays, or the two would drift.
        $inline = [];
        pp_the_loop(pp_main_query(), function () use (&$inline) {
            $inline[] = [
                'title'     => pp_page_title(),
                'text'      => pp_excerpt(25),
                'image_url' => pp_thumbnail_url('medium'),
                'link_url'  => pp_permalink(),
                'link_text' => 'Read post',
            ];
        });
        $GLOBALS['wp_query']->rewind_posts();
        $this->assertSame($inline, pp_posts_listing_items());
        $source = (string) file_get_contents(dirname(__DIR__) . '/templates/home.php');
        foreach (["'title'     => pp_page_title()", "'text'      => pp_excerpt(25)", "'image_url' => pp_thumbnail_url('medium')",
                  "'link_url'  => pp_permalink()", "'link_text' => 'Read post'"] as $line) {
            $this->assertStringContainsString($line, $source, 'the fallback mapping this test mirrors is still home.php\'s');
        }
    }

    public function testTheLoopStateIsRestoredForEverythingAfterTheBand(): void
    {
        $q = $GLOBALS['wp_query'];
        $GLOBALS['post'] = 'sentinel-post';
        $before = $q->current_post;
        self::renderGrid(['items_source' => 'posts', 'items' => []]);
        $this->assertSame(3, $q->the_post_calls, 'the band walked the main query');
        $this->assertSame($before, $q->current_post, 'rewound: later bands, pagination and template tags see an untouched main query');
        $this->assertSame('sentinel-post', $GLOBALS['post']);
    }

    public function testPaginationRendersInsideTheListingBand(): void
    {
        $GLOBALS['wp_query'] = new PpPostsIndexQuery(3, true, 3);
        $html = self::renderGrid(['items_source' => 'posts', 'items' => []]);
        $nav  = strpos($html, 'pp-pagination');
        $this->assertNotFalse($nav, 'the listing\'s own pagination');
        $this->assertLessThan(strrpos($html, '</section>'), $nav, 'inside the band, after its list');
        $this->assertGreaterThan(strpos($html, '</ul>'), $nav);
    }

    public function testOffThePostsIndexTheListingBandRendersTheEmptyState(): void
    {
        $GLOBALS['wp_query'] = new PpPostsIndexQuery(3, false, 3);
        $html = self::renderGrid(['title' => 'Latest', 'items_source' => 'posts', 'items' => []]);
        $this->assertStringContainsString('grid__empty', $html);
        $this->assertStringNotContainsString('<li class="grid__item"', $html, 'the main query here is not a post listing');
        $this->assertStringNotContainsString('pp-pagination', $html);
        $this->assertSame(0, $GLOBALS['wp_query']->the_post_calls, 'the main query is not touched off the posts index');
    }

    public function testAnAuthoredGridIsUntouchedByTheListingBranch(): void
    {
        $html = self::renderGrid(['items' => [['title' => 'A', 'text' => 'x']]]);
        $this->assertSame(1, substr_count($html, '<li class="grid__item"'));
        $this->assertSame(0, $GLOBALS['wp_query']->the_post_calls);
        $this->assertStringNotContainsString('pp-pagination', $html);
    }

    // ── 3. The write surface ────────────────────────────────────────────────

    public function testAnUnknownSourceIsRefusedByTheStrictEnum(): void
    {
        $result = pp_execute_action('update_composition', ['post_id' => $this->postsPage, 'composition' => [self::listingBand(['items_source' => 'pages'])]]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('items_source', (string) $result['error']);
    }

    public function testNonEmptyItemsBesideTheSourceAreRefused(): void
    {
        $result = pp_execute_action('update_composition', ['post_id' => $this->postsPage, 'composition' => [self::listingBand(['items' => [['title' => 'x']]])]]);
        $this->assertFalse($result['ok'], 'D3: items the render would ignore are refused, not stored dead');
        $this->assertStringContainsString('prop "items"', (string) $result['error']);
        $this->assertStringContainsString('items_source', (string) $result['error']);
    }

    /**
     * THE TRAP THIS SLICE WALKED INTO, AND THE GUARD THAT CAUGHT IT. `items_source` is an
     * optional enum with no default, and an `equals` gate on a default-less prop reads an
     * absent value as MET (the shared evaluator fails open on null) — written first as
     * `equals: "posts"`, the rule refused `items` on EVERY ordinary grid. The repo's own
     * well-formedness pin (SchemaValidationTest::testEveryShippedRefusePropsWhenRuleIsWellFormed)
     * requires a default for equals/in gates for exactly this reason. The rule therefore
     * gates on `present`, which decides absence correctly; the enum's only value makes
     * present equal to "posts". This pins the outcome and the shape.
     */
    public function testAnOrdinaryGridWithItemsIsNeverRefusedByTheListingRule(): void
    {
        $result = pp_execute_action('update_composition', ['post_id' => $this->postsPage, 'composition' => [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'An authored card']]]],
        ]]);
        $this->assertTrue($result['ok'], 'an ordinary grid is not a listing band: ' . ($result['error'] ?? ''));

        $schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/components/grid/schema.json'), true);
        $rule   = $schema['refuse_props_when'][0];
        $this->assertSame([['prop' => 'items_source', 'present' => true]], $rule['when']);
        $this->assertSame(['items'], $rule['props']);
        $this->assertSame(['posts'], $schema['props']['items_source']['values'], 'present == equals "posts" only while "posts" is the one value');
        $this->assertArrayNotHasKey('default', $schema['props']['items_source'], 'absent means an authored grid');
    }

    public function testTwoListingBandsOnOnePageAreRefused(): void
    {
        $result = pp_execute_action('update_composition', ['post_id' => $this->postsPage, 'composition' => [self::listingBand(), self::listingBand()]]);
        $this->assertFalse($result['ok'], 'two bands would print the same page of posts twice');
        $this->assertStringContainsString('one listing band', (string) $result['error']);
        $this->assertStringContainsString('remove `items_source` from the others', (string) $result['error'], 'the advice is a write the validator accepts (a listing band with authored items is refused)');
    }

    public function testUpdateComponentCannotMakeASecondListingBand(): void
    {
        $this->store($this->postsPage, [self::listingBand(), ['component' => 'grid', 'props' => ['title' => 'Other', 'items' => [['title' => 'x']]]]]);
        $r = pp_execute_action('update_component', ['post_id' => $this->postsPage, 'component_index' => 1, 'props' => ['items_source' => 'posts', 'items' => []]]);
        $this->assertFalse($r['ok'], 'the page-level rule holds on the band-scoped writer too');
        $this->assertSame('duplicate_listing_band', $r['error_code'] ?? null);
    }

    public function testTheOneListingRuleReportsBesideOtherErrors(): void
    {
        $codes = array_map(static fn (WP_Error $e): string => $e->get_error_code(), pp_validate_composition_errors([
            self::listingBand(), self::listingBand(), ['component' => 'hero', 'props' => ['title' => 'x', 'nonsense' => 1]],
        ]));
        $this->assertContains('duplicate_listing_band', $codes, 'the exhaustive report (no limit) lists every problem');
        $this->assertContains('unknown_prop', $codes);
    }

    public function testAddComponentCannotAddASecondListingBand(): void
    {
        $this->store($this->postsPage, [self::listingBand(), ['component' => 'hero', 'props' => ['title' => 'Go']]]);
        foreach ([[], ['position' => 0]] as $extra) {
            $r = pp_execute_action('add_component', ['post_id' => $this->postsPage, 'component' => 'grid', 'props' => ['items_source' => 'posts', 'items' => []]] + $extra);
            $this->assertFalse($r['ok'], 'one listing band per page, appended or positioned');
            $this->assertStringContainsString('one listing band', (string) $r['error']);
        }
        $this->assertCount(2, pp_get_composition($this->postsPage), 'nothing stored');
        $r = pp_execute_action('add_component', ['post_id' => $this->postsPage, 'component' => 'hero', 'props' => ['title' => 'More']]);
        $this->assertTrue($r['ok'], 'a band that is not a listing is never refused by the rule: ' . ($r['error'] ?? ''));

        // #1007: the page's own stale state (two listing bands, raw) never blocks adding an
        // ordinary band.
        $this->store($this->postsPage, [self::listingBand(), self::listingBand()]);
        $r = pp_execute_action('add_component', ['post_id' => $this->postsPage, 'component' => 'hero', 'props' => ['title' => 'Still']]);
        $this->assertTrue($r['ok'], 'an ordinary band is judged alone: ' . ($r['error'] ?? ''));
    }

    public function testTheOffPageRefusalNamesTheBand(): void
    {
        $other = (int) pp_create_page('About', 'publish');
        $this->store($other, [['component' => 'hero', 'props' => ['title' => 'a']], ['component' => 'grid', 'props' => ['items' => [['title' => 'x']]]]]);
        $r = pp_execute_action('update_component', ['post_id' => $other, 'component_index' => 1, 'props' => ['items_source' => 'posts', 'items' => []]]);
        $this->assertFalse($r['ok']);
        $this->assertSame('listing_band_off_posts_page', $r['error_code'] ?? null);
        $this->assertSame(1, $r['index'] ?? null, 'band-scoped rejections name the band (#642)');
        $r = pp_execute_action('add_component', ['post_id' => $other, 'component' => 'grid', 'position' => 1, 'props' => ['items_source' => 'posts', 'items' => []]]);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['index'] ?? null, 'the added band is not on the page yet: no offset names it');
    }

    public function testThePostsPageAcceptsAListingBand(): void
    {
        $result = pp_execute_action('update_composition', ['post_id' => $this->postsPage, 'composition' => [self::listingBand()]]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $types = array_column((array) ($result['findings'] ?? []), 'type');
        $this->assertNotContains('listing_band_off_posts_page', $types);
        $this->assertNotContains('posts_page_without_listing', $types);
        $this->assertNotContains('empty_section', $types, 'a listing band is not an empty grid');
    }

    public function testAListingBandsCardsAreJudgedByTheStylingFindingsLikeAuthoredCards(): void
    {
        // The write runs in an admin request, where the main query is NOT the posts index,
        // so the band's own render has no cards. The ink finding's "is this element on the
        // page" render must still see the cards the posts page will show (/review, #1181).
        $GLOBALS['wp_query'] = new PpPostsIndexQuery(3, false);
        $udc   = ['_band' => ['background' => ['fill' => '#101828']], 'step-number' => ['typography' => ['color' => '#fffff0']]];
        $ink   = static fn(array $r): array => array_values(array_filter((array) ($r['findings'] ?? []), static fn(array $f): bool => $f['type'] === 'udc_role_ink_over_own_surface'));
        $other = (int) pp_create_page('About', 'publish');

        $authored = pp_execute_action('update_composition', ['post_id' => $other, 'composition' => [
            ['component' => 'grid', 'udc' => $udc, 'props' => ['title' => 'Latest', 'layout' => 'steps', 'items' => [['title' => 'A post', 'text' => 'x']]]],
        ]]);
        $this->assertTrue($authored['ok'], (string) ($authored['error'] ?? ''));
        $this->assertCount(1, $ink($authored), 'premise: the authored twin fires');

        $listing = pp_execute_action('update_composition', ['post_id' => $this->postsPage, 'composition' => [
            ['component' => 'grid', 'udc' => $udc, 'props' => ['title' => 'Latest', 'layout' => 'steps', 'items_source' => 'posts', 'items' => []]],
        ]]);
        $this->assertTrue($listing['ok'], (string) ($listing['error'] ?? ''));
        $this->assertCount(1, $ink($listing), 'the listing band\'s cards carry the same clash on /blog/, so it is named too');
        $this->assertSame(0, $GLOBALS['wp_query']->the_post_calls, 'the finding never walked the main query');

        // Off the posts page the band renders its empty state: no card, so no card finding
        // (a finding about an element the page does not have is advice about nothing).
        // The band written above (on the posts page) goes stale when the posts page moves.
        $GLOBALS['_pp_test_store']['options']['page_for_posts'] = $this->postsPage + 999;
        $types = array_column(_pp_composition_findings(pp_get_composition($this->postsPage), $this->postsPage), 'type');
        $this->assertContains('listing_band_off_posts_page', $types);
        $this->assertNotContains('udc_role_ink_over_own_surface', $types, 'the stale band shows no cards');
    }

    public function testEveryPageAwareWriterRefusesAListingBandOffThePostsPage(): void
    {
        $other = (int) pp_create_page('About', 'publish');
        $this->store($other, [['component' => 'grid', 'props' => ['items' => [['title' => 'x']]]]]);
        $reason = 'the posts page';

        $r = pp_execute_action('update_composition', ['post_id' => $other, 'composition' => [self::listingBand()]]);
        $this->assertFalse($r['ok'], 'update_composition');
        $this->assertStringContainsString($reason, (string) $r['error']);

        $r = pp_execute_action('update_component', ['post_id' => $other, 'component_index' => 0, 'props' => ['items_source' => 'posts', 'items' => []]]);
        $this->assertFalse($r['ok'], 'update_component');
        $this->assertStringContainsString($reason, (string) $r['error']);

        $r = pp_execute_action('add_component', ['post_id' => $other, 'component' => 'grid', 'props' => ['items_source' => 'posts', 'items' => []]]);
        $this->assertFalse($r['ok'], 'add_component');
        $this->assertStringContainsString($reason, (string) $r['error']);

        $r = pp_execute_action('create_page', ['title' => 'New', 'composition' => [self::listingBand()]]);
        $this->assertFalse($r['ok'], 'create_page: a new page is never the posts page');
        $this->assertStringContainsString($reason, (string) $r['error']);

        $this->assertSame(
            [['component' => 'grid', 'props' => ['items' => [['title' => 'x']]]]],
            array_map(static fn(array $b): array => ['component' => $b['component'], 'props' => ['items' => array_map(static fn(array $i): array => ['title' => $i['title']], $b['props']['items'])]], pp_get_composition($other)),
            'refused writes stored nothing'
        );
    }

    public function testStaleStateIsNeverLockedInPlace(): void
    {
        // The posts page moved after the band was written: the band is stale.
        $this->store($this->postsPage, [self::listingBand(), ['component' => 'hero', 'props' => ['title' => 'Go']]]);
        $GLOBALS['_pp_test_store']['options']['page_for_posts'] = $this->postsPage + 999;

        $r = pp_execute_action('update_component', ['post_id' => $this->postsPage, 'component_index' => 1, 'props' => ['title' => 'Go now']]);
        $this->assertTrue($r['ok'], 'band-scoped (#1007): editing ANOTHER band is not refused: ' . ($r['error'] ?? ''));
        $this->assertContains('listing_band_off_posts_page', array_column((array) $r['findings'], 'type'), 'but the stale band is disclosed');

        $r = pp_execute_action('update_component', ['post_id' => $this->postsPage, 'component_index' => 0, 'props' => ['items_source' => null, 'items' => [['title' => 'Now authored']]]]);
        $this->assertTrue($r['ok'], 'removing the stale value is never refused: ' . ($r['error'] ?? ''));
        $this->assertArrayNotHasKey('items_source', pp_get_composition($this->postsPage)[0]['props']);
    }

    // ── 4. Disclosure of what storage can still drift into ──────────────────

    public function testAStaleListingBandIsReportedByCheckPage(): void
    {
        $other = (int) pp_create_page('About', 'publish');
        $this->store($other, [self::listingBand()]); // a raw write: no gate ran
        $types = array_column(_pp_composition_findings(pp_get_composition($other), $other), 'type');
        $this->assertContains('listing_band_off_posts_page', $types);
        $this->assertNotContains('empty_section', $types);
        $this->assertNotContains('listing_band_off_posts_page', array_column(_pp_composition_findings(pp_get_composition($other)), 'type'), 'page-blind callers report only what the page cannot decide');
    }

    public function testAPostsPageWithoutAListingIsDisclosedButAccepted(): void
    {
        $r = pp_execute_action('update_composition', ['post_id' => $this->postsPage, 'composition' => [['component' => 'hero', 'props' => ['title' => 'Blog']]]]);
        $this->assertTrue($r['ok'], 'D5: staged edits are not blocked');
        $finding = array_values(array_filter((array) $r['findings'], static fn(array $f): bool => $f['type'] === 'posts_page_without_listing'));
        $this->assertCount(1, $finding);
        $this->assertStringContainsString('no posts', $finding[0]['message']);
    }

    public function testInspectReportsThePostsPageFindingsBeforeAMutation(): void
    {
        $this->store($this->postsPage, [['component' => 'hero', 'props' => ['title' => 'Blog']]]);
        $inspect = pp_inspect_site($this->postsPage);
        $this->assertContains('posts_page_without_listing', array_column($inspect['smells'], 'type'), 'an agent reading the page before a write learns it has no listing');
    }

    public function testBothCliReportsReadThePageTheyReport(): void
    {
        // `check page` is exercised whole in DiagnosticReachTest; `validate site` walks the
        // memoized page list, which PHPUnit cannot reset, so its call is pinned by shape.
        $cli = (string) file_get_contents(dirname(__DIR__) . '/lib/cli.php');
        $this->assertSame(2, preg_match_all('~\$diagnostics\s*=\s*_pp_cli_page_diagnostics\(\$composition, \(int\) \$post_id\);~', $cli));
        $this->assertSame(0, preg_match_all('~_pp_cli_page_diagnostics\(\$composition\);~', $cli), 'no page-blind caller');
    }

    public function testAnEmptyPostsPageCompositionIsNotReportedAsListingless(): void
    {
        // [] renders home.php's own listing, so "shows no posts" would be false.
        $this->assertSame([], pp_posts_page_findings([], $this->postsPage));
    }

    public function testRestoreDisclosesThePostsPageFindingsOnPreviewAndExecute(): void
    {
        pp_execute_action('update_composition', ['post_id' => $this->postsPage, 'composition' => [['component' => 'hero', 'props' => ['title' => 'Blog']]]]);
        pp_execute_action('update_composition', ['post_id' => $this->postsPage, 'composition' => [self::listingBand()]]);

        $preview = pp_preview_action('restore_composition', ['post_id' => $this->postsPage]);
        $this->assertIsArray($preview, is_wp_error($preview) ? $preview->get_error_message() : '');
        $this->assertContains('posts_page_without_listing', array_column((array) ($preview['findings'] ?? []), 'type'), 'preview');

        $r = pp_execute_action('restore_composition', ['post_id' => $this->postsPage]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertContains('posts_page_without_listing', array_column((array) ($r['findings'] ?? []), 'type'), 'execute');
    }

    public function testTheOperateRestoreRunDisclosesAStaleListingBand(): void
    {
        $other = (int) pp_create_page('About', 'publish');
        $this->store($other, [self::listingBand()]); // the snapshot holds a stale band (raw)
        $run_id = pp_operate_create_run();
        pp_operate_record_step($run_id, 'PREFLIGHT');
        pp_operate_record_composition_content_snapshot($run_id, $other, pp_get_composition($other));
        pp_update_composition($other, [['component' => 'hero', 'props' => ['title' => 'After']]]);
        pp_operate_record_touched_post_id($run_id, $other);

        $report = pp_operate_restore_run_compositions($run_id);
        $this->assertTrue($report['ok']);
        $this->assertContains('listing_band_off_posts_page', array_column($report['reverted'][0]['findings'], 'type'));
    }

    public function testAnEmptyPostsIndexRendersTheEmptyStateWithoutPageLinks(): void
    {
        $GLOBALS['wp_query'] = new PpPostsIndexQuery(0, true, 3);
        $html = self::renderGrid(['title' => 'Latest', 'items_source' => 'posts', 'items' => []]);
        $this->assertStringContainsString('grid__empty', $html);
        $this->assertStringNotContainsString('pp-pagination', $html, 'no cards, no pages');
    }

    // ── 5. The shared band loop and the posts-page template ─────────────────

    public function testTheSharedBandLoopRendersEveryBandWithItsIdentity(): void
    {
        ob_start();
        pp_render_composition_bands([
            ['component' => 'hero', 'id' => 'pp-0000000a', 'props' => ['title' => 'One']],
            ['props' => ['title' => 'no component']],
            ['component' => 'grid', 'id' => 'pp-0000000b', 'props' => ['title' => 'G', 'items' => [['title' => 'c']]]],
        ]);
        $html = (string) ob_get_clean();
        $this->assertSame(2, substr_count($html, 'data-pp-band='), 'an item without a component is skipped');
        $this->assertStringContainsString('data-pp-band="pp-0000000a"', $html);
        $this->assertStringContainsString('data-pp-band="pp-0000000b"', $html);
        $this->assertLessThan(strpos($html, 'pp-0000000b'), strpos($html, 'pp-0000000a'), 'in order');
    }

    public function testThePostsPagesBandsRunWithThePageAsTheCurrentPost(): void
    {
        // On the posts index core's current post is the first post of the listing, and the
        // listing band's loop puts it back there (wp_reset_postdata after core's end-of-loop
        // rewind). A band's post-bound code (an embed's
        // shortcodes) must see the posts page itself, as on any composed page (/review).
        $this->store($this->postsPage, [['component' => 'hero', 'props' => ['title' => 'A']], self::listingBand(), ['component' => 'hero', 'props' => ['title' => 'B']]]);
        $GLOBALS['_pp_test_model_post_global'] = true;
        $GLOBALS['_pp_test_postdata_log'] = [];
        $before = $GLOBALS['wp_query']->post; // core's current post on this route: the first post of the listing
        $GLOBALS['post'] = $before;
        ob_start();
        include dirname(__DIR__) . '/templates/home.php';
        ob_end_clean();
        $page = 'setup:' . $this->postsPage . '/post:' . $this->postsPage;
        $this->assertSame([$page, 'the_post', 'the_post', 'the_post', $page], $GLOBALS['_pp_test_postdata_log'], 'the page before the first band, the listing loop inside, the page again after it');
        $this->assertSame($before->ID, $GLOBALS['post']->ID, 'the main query\'s post is current again afterwards');
        unset($GLOBALS['_pp_test_postdata_log'], $GLOBALS['post'], $GLOBALS['id'], $GLOBALS['pages'], $GLOBALS['_pp_test_the_post_fires']);
    }

    public function testThePostsPageIsSetUpOnlyWhenItIsNotAlreadyTheCurrentPost(): void
    {
        // `setup_postdata()` fires the `the_post` action. Setting the page up again before
        // every band would fire it once per band (and once more on the way out) for a page
        // nobody iterated: a view counter would count the posts page N+1 times. It is set
        // up once, and once more only after the listing band's loop moved the current post.
        $this->store($this->postsPage, [
            ['component' => 'hero', 'props' => ['title' => 'A']], self::listingBand(),
            ['component' => 'hero', 'props' => ['title' => 'B']], ['component' => 'hero', 'props' => ['title' => 'C']],
        ]);
        $GLOBALS['_pp_test_model_post_global'] = true;
        $GLOBALS['post'] = (object) ['ID' => 4242];
        $GLOBALS['_pp_test_the_post_fires'] = 0;
        ob_start();
        include dirname(__DIR__) . '/templates/home.php';
        ob_end_clean();
        $this->assertSame(2, $GLOBALS['_pp_test_the_post_fires'], 'before the first band, and after the listing: not once per band, not on restore');
        unset($GLOBALS['_pp_test_postdata_log'], $GLOBALS['post'], $GLOBALS['id'], $GLOBALS['pages'], $GLOBALS['_pp_test_the_post_fires']);
    }

    public function testAfterAComposedPostsPageThePostStateIsWhatMainsBlogLeaves(): void
    {
        // Main's /blog/ ends its listing loop with wp_reset_postdata(): the main query's
        // first post is current and every template global is set up for it. A footer, a
        // wp_footer consumer or core's own get_the_content() (which trusts the globals once
        // `the_post` has fired) must find that same state after a composed posts page — not
        // the pre-loop state where `pages` is unset (a TypeError in count()). Put back
        // without firing `the_post` again.
        $GLOBALS['_pp_test_model_post_global'] = true;
        $this->store($this->postsPage, [self::listingBand(), ['component' => 'hero', 'props' => ['title' => 'After']]]);
        $GLOBALS['post'] = (object) ['ID' => 9000]; // core's register_globals: the first post
        unset($GLOBALS['id'], $GLOBALS['pages'], $GLOBALS['authordata']);
        $GLOBALS['_pp_test_the_post_fires'] = 0;
        ob_start();
        include dirname(__DIR__) . '/templates/home.php';
        ob_end_clean();
        $this->assertSame(9000, $GLOBALS['post']->ID ?? null, 'the main query\'s first post is current');
        foreach (pp_test_generate_postdata($GLOBALS['wp_query']->post) as $name => $value) {
            $this->assertSame($value, $GLOBALS[$name] ?? null, $name . ' is set up for it, as main\'s reset leaves it');
        }
        $this->assertSame(2, $GLOBALS['_pp_test_the_post_fires'], 'the restore fires no `the_post` of its own');
        unset($GLOBALS['_pp_test_postdata_log'], $GLOBALS['_pp_test_the_post_fires']);
    }

    public function testWithoutAnOwnerTheBandLoopLeavesThePostGlobalsAlone(): void
    {
        // composition.php and front-page.php pass no owner: core already made the page current.
        $GLOBALS['_pp_test_model_post_global'] = true;
        $before = (object) ['ID' => 77];
        $GLOBALS['post'] = $before;
        $GLOBALS['page'] = '';
        $GLOBALS['_pp_test_postdata_log'] = [];
        $GLOBALS['_pp_test_the_post_fires'] = 0;
        ob_start();
        pp_render_composition_bands([['component' => 'hero', 'props' => ['title' => 'A']], ['component' => 'hero', 'props' => ['title' => 'B']]]);
        ob_end_clean();
        $this->assertSame($before, $GLOBALS['post']);
        $this->assertSame('', $GLOBALS['page'] ?? 'unset', 'a global core set is not touched');
        $this->assertSame(0, $GLOBALS['_pp_test_the_post_fires']);
        $this->assertSame([], $GLOBALS['_pp_test_postdata_log']);
        unset($GLOBALS['post'], $GLOBALS['page'], $GLOBALS['_pp_test_postdata_log'], $GLOBALS['_pp_test_the_post_fires']);
    }

    public function testAnEmptyListingLeavesThePostGlobalsAsTheyWere(): void
    {
        // Zero posts: core never set a current post, so no post global exists, exactly as
        // on main's empty /blog/. After the bands every global setup_postdata() touched is
        // put back to that state, not left holding the posts page for the footer's hooks.
        $GLOBALS['wp_query'] = new PpPostsIndexQuery(0, true, 1);
        $this->store($this->postsPage, [['component' => 'hero', 'props' => ['title' => 'A']], self::listingBand()]);
        $GLOBALS['_pp_test_model_post_global'] = true;
        foreach (['post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages'] as $name) {
            unset($GLOBALS[$name]); // main's empty /blog/: core set up no post
        }
        $GLOBALS['page'] = ''; // register_globals copies the query vars, `page` among them
        ob_start();
        include dirname(__DIR__) . '/templates/home.php';
        ob_end_clean();
        $this->assertNull($GLOBALS['post'] ?? null, 'no current post, as before');
        $this->assertArrayNotHasKey('id', $GLOBALS, 'the template globals are not left on the posts page');
        $this->assertArrayNotHasKey('pages', $GLOBALS);
        $this->assertArrayNotHasKey('authordata', $GLOBALS);
        $this->assertSame('', $GLOBALS['page'] ?? 'unset', 'a global that existed is put back as it was');
        unset($GLOBALS['_pp_test_postdata_log'], $GLOBALS['_pp_test_the_post_fires'], $GLOBALS['page']);
    }

    public function testTheHomeTemplateRendersTheStoredCompositionOnlyWhenThereIsOne(): void
    {
        $render = function (): string {
            ob_start();
            include dirname(__DIR__) . '/templates/home.php';
            return (string) ob_get_clean();
        };
        $this->store($this->postsPage, [['component' => 'hero', 'id' => 'pp-0000000c', 'props' => ['title' => 'Journal']], self::listingBand()]);
        $html = $render();
        $this->assertStringContainsString('data-pp-band="pp-0000000c"', $html);
        $this->assertSame(3, substr_count($html, '<li class="grid__item"'), 'the listing band holds this page of posts');

        $this->store($this->postsPage, []);
        $fallback = $render();
        $this->assertStringNotContainsString('pp-0000000c', $fallback);
        $this->assertSame(3, substr_count($fallback, '<li class="grid__item"'), 'nothing stored: today\'s hero + listing');
    }

    public function testAPlainEmptyGridStillCarriesTheEmptySectionSmell(): void
    {
        $types = array_column(_pp_composition_findings([['component' => 'grid', 'props' => ['items' => []]]]), 'type');
        $this->assertContains('empty_section', $types, 'the carve-out is for listing bands only');
    }

    // ── 6. Model-facing text ────────────────────────────────────────────────

    public function testTheInAdminPromptStatesThePostsPageContract(): void
    {
        $prompt = pp_ai_system_prompt();
        $this->assertStringContainsString('items_source?: "posts"', $prompt);
        $this->assertMatchesRegularExpression('~POSTS PAGE: the page marked "posts page" above[^\n]*"items_source": "posts"[^\n]*"items": \[\]`, accepted only there~', $prompt);
    }

    public function testThePagesListMarksThePostsPage(): void
    {
        $line = static fn (int $id): string => pp_ai_page_inventory_line(['id' => $id, 'title' => 'T', 'status' => 'publish', 'url' => 'https://example.com/t/']);
        $this->assertSame('- T (ID: ' . $this->postsPage . ', status: publish, URL: https://example.com/t/, posts page)', $line($this->postsPage));
        $this->assertSame('- T (ID: 7, status: publish, URL: https://example.com/t/)', $line(7), 'only the posts page is marked');
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'posts';
        $this->assertStringNotContainsString('posts page', $line($this->postsPage), 'no posts page, no mark');
    }

    // ── 7. Ship-audit additions (branches the review cycles left unpinned) ──

    public function testTheDuplicateListingMessageNamesEveryListingBand(): void
    {
        // The band list in the message was unpinned (a planted defect changing it survived):
        // it names exactly the listing bands, and not the band between them.
        $error = pp_duplicate_listing_band_error([self::listingBand(), ['component' => 'hero', 'props' => ['title' => 'x']], self::listingBand()]);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('duplicate_listing_band', $error->get_error_code());
        $this->assertStringContainsString('items 0, 2 all set it', $error->get_error_message());
        $this->assertNull(pp_duplicate_listing_band_error([self::listingBand(), ['component' => 'hero', 'props' => ['title' => 'x']]]), 'one listing band is fine');
    }

    public function testALimitedReportSkipsTheOneListingRuleOnlyWhenEarlierErrorsExist(): void
    {
        // The budgeted (first-error) form stops before the cross-item rules once it already
        // has an error; with none it still reaches the one-listing rule.
        $two   = [self::listingBand(), self::listingBand()];
        $codes = static fn (array $errs): array => array_map(static fn (WP_Error $e): string => $e->get_error_code(), $errs);
        $this->assertContains('duplicate_listing_band', $codes(pp_validate_composition_errors($two, 1)), 'a clean page still hears the rule');
        $bad = array_merge([['component' => 'hero', 'props' => ['title' => 'x', 'nonsense' => 1]]], $two);
        $this->assertNotContains('duplicate_listing_band', $codes(pp_validate_composition_errors($bad, 1)), 'the budget stops at the first error');
    }

    public function testTheOffPageRefusalSaysWhyForANewPageAndANewBand(): void
    {
        $r = pp_execute_action('create_page', ['title' => 'New', 'composition' => [self::listingBand()]]);
        $this->assertFalse($r['ok']);
        $this->assertSame('listing_band_off_posts_page', $r['error_code'] ?? null);
        $this->assertStringContainsString('a page that does not exist yet', (string) $r['error']);

        $other = (int) pp_create_page('About', 'publish');
        $this->store($other, [['component' => 'hero', 'props' => ['title' => 'a']]]);
        $r = pp_execute_action('add_component', ['post_id' => $other, 'component' => 'grid', 'props' => ['items_source' => 'posts', 'items' => []]]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('(the new band)', (string) $r['error'], 'an unlocated band is not named by an offset');
        $this->assertStringContainsString(sprintf('this page (%d), which is not the posts page', $other), (string) $r['error']);
    }

    public function testATrashedPostsPageIsListedOnlyWhereTrashIsAsked(): void
    {
        // The posts-page addition honours the caller's status filter: the ordinary page list
        // omits a trashed posts page, the preset reference gate (which scans trash) keeps it.
        unset($GLOBALS['_pp_test_store']['post_meta'][$this->postsPage]['_wp_page_template']);
        $GLOBALS['_pp_test_store']['page_template_slug'] = '';
        $GLOBALS['_pp_test_store']['posts'][$this->postsPage]['post_status'] = 'trash';
        $this->assertNotContains($this->postsPage, array_column(pp_composition_pages(true), 'id'));
        $this->assertContains($this->postsPage, array_column(pp_composition_pages_for_reference_gate(), 'id'));
    }

    public function testThePostsPageFindingIndexIsTheBandOffset(): void
    {
        $other    = (int) pp_create_page('About', 'publish');
        $findings = pp_posts_page_findings([['component' => 'hero', 'props' => ['title' => 'a']], self::listingBand()], $other);
        $this->assertSame(['listing_band_off_posts_page'], array_column($findings, 'type'));
        $this->assertSame(1, $findings[0]['index']);
        $this->assertStringContainsString('Item 1', $findings[0]['message']);
        $this->assertSame([], pp_posts_page_findings([self::listingBand()], $this->postsPage), 'the posts page with its listing: nothing to disclose');
    }

    public function testThePostGlobalsAreRestoredWhenABandThrows(): void
    {
        // The restore sits in `finally`: a band that throws mid-render must not leave the
        // posts page as the current post for whatever handles the error.
        $GLOBALS['wp_query'] = new class extends WP_Query {
            public bool $is_posts_page = true;
            public bool $is_search = false;
            public function __construct() { $this->posts = [1]; }
            public function have_posts(): bool { return true; }
            public function the_post(): void { throw new RuntimeException('band failed'); }
            public function rewind_posts(): void {}
        };
        $before = (object) ['ID' => 4242];
        $GLOBALS['post'] = $before;
        unset($GLOBALS['id'], $GLOBALS['pages']);
        $level = ob_get_level();
        ob_start();
        try {
            pp_render_composition_bands([['component' => 'hero', 'props' => ['title' => 'A']], self::listingBand()], $this->postsPage);
            $this->fail('the band exception propagates');
        } catch (RuntimeException $e) {
            $this->assertSame('band failed', $e->getMessage());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        $this->assertSame($before, $GLOBALS['post'], 'the previous current post is back');
        $this->assertArrayNotHasKey('id', $GLOBALS, 'globals that did not exist are unset again');
        $this->assertArrayNotHasKey('pages', $GLOBALS);
        unset($GLOBALS['post'], $GLOBALS['_pp_test_postdata_log'], $GLOBALS['_pp_test_the_post_fires']);
    }
}
