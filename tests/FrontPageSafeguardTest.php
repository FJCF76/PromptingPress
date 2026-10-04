<?php
/**
 * tests/FrontPageSafeguardTest.php — front-page blank-page safeguard (#506).
 *
 * pp_resolve_front_page_render() classifies the stored composition BEFORE the
 * blank-page safeguard can seed defaults, so a CORRUPT homepage composition is
 * never silently overwritten on render (the pre-#506 raw-update_post_meta bug that
 * destroyed the recoverable bytes pp_get_composition_result() preserves, #144).
 *
 * Coverage:
 *   corrupt (decode_error / unexpected_shape) → mode 'corrupt', ZERO writes, and
 *     inspect (pp_get_composition_result) still reports the exact error afterwards.
 *   absent meta → seed ONCE through the versioned writer (version marker set to 1,
 *     seeded composition passes pp_validate_composition), renders defaults.
 *   post_id 0 → mode 'no_front', zero writes.
 *   any page but the one Settings -> Reading names (pp_front_page_id(); setUp names
 *     $postId) → rendered as stored, NEVER seeded: absent meta renders [] with zero
 *     writes (#1173: the newest blog post on a latest-posts site, a page core treats as
 *     the front page by title, page_on_front unset).
 *   present valid list → mode 'render', no seed, meta byte-identical.
 *   stored empty list "[]" → mode 'render' + blank, NOT re-seeded (raw !== null is a
 *     deliberate authored state, not a genuinely-absent page).
 *   legacy cta_text → render output is normalized to button_text (pp_composition parity).
 */

use PHPUnit\Framework\TestCase;

class FrontPageSafeguardTest extends TestCase
{
    private int $postId = 506;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        // The resolver serves the CONFIGURED static front page only (#1173): Settings ->
        // Reading names $this->postId as the front page, as setup.php does on activation.
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'page';
        $GLOBALS['_pp_test_store']['options']['page_on_front'] = $this->postId;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_store']['options']['show_on_front'], $GLOBALS['_pp_test_store']['options']['page_on_front']);
        parent::tearDown();
    }

    /** Stores an exact raw meta string (bypasses the writer) to exercise a state. */
    private function storeRaw(string $value): void
    {
        update_post_meta($this->postId, '_pp_composition', $value);
    }

    /** Deep snapshot of the whole post-meta store for byte-identical assertions. */
    private function metaSnapshot(): array
    {
        return $GLOBALS['_pp_test_store']['post_meta'];
    }

    // ── Corrupt: never overwrite, never write, inspect stays honest ──────────

    public function testCorruptDecodeErrorRendersFallbackAndWritesNothing(): void
    {
        // Truncated JSON — undecodable. pp_get_composition_result → decode_error, raw kept.
        $this->storeRaw('[{"component":"hero","props":{"title":"Half');
        $before = $this->metaSnapshot();

        $render = pp_resolve_front_page_render($this->postId);

        $this->assertSame('corrupt', $render['mode']);
        $this->assertSame([], $render['composition']);

        // ZERO writes: the entire post-meta store is byte-identical after render.
        $this->assertSame($before, $this->metaSnapshot(), 'corrupt render must not write any meta');

        // Inspect remains the honest reporter: the exact error and raw bytes survive.
        $result = pp_get_composition_result($this->postId);
        $this->assertFalse($result['ok']);
        $this->assertSame('decode_error', $result['error']);
        $this->assertSame('[{"component":"hero","props":{"title":"Half', $result['raw']);
    }

    public function testCorruptUnexpectedShapeRendersFallbackAndWritesNothing(): void
    {
        // Valid JSON but an object, not a list — unexpected_shape.
        $this->storeRaw('{"component":"hero"}');
        $before = $this->metaSnapshot();

        $render = pp_resolve_front_page_render($this->postId);

        $this->assertSame('corrupt', $render['mode']);
        $this->assertSame([], $render['composition']);
        $this->assertSame($before, $this->metaSnapshot(), 'unexpected-shape render must not write any meta');

        $result = pp_get_composition_result($this->postId);
        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error']);
    }

    public function testCorruptScalarShapeRendersFallbackAndWritesNothing(): void
    {
        // Valid JSON but a bare scalar (decodes to a non-list int) — a distinct
        // value-type path into the same corrupt classification. Must not seed.
        $this->storeRaw('42');
        $before = $this->metaSnapshot();

        $render = pp_resolve_front_page_render($this->postId);

        $this->assertSame('corrupt', $render['mode']);
        $this->assertSame([], $render['composition']);
        $this->assertSame($before, $this->metaSnapshot(), 'scalar-shape render must not write any meta');

        $result = pp_get_composition_result($this->postId);
        $this->assertFalse($result['ok']);
        $this->assertSame('unexpected_shape', $result['error']);
    }

    // ── Absent meta: seed once, through the versioned writer ─────────────────

    public function testAbsentMetaSeedsDefaultsThroughVersionedWriter(): void
    {
        // No meta row at all: version marker is absent (reads as 0).
        $this->assertSame(0, (int) get_post_meta($this->postId, '_pp_composition_version', true));

        $render = pp_resolve_front_page_render($this->postId);

        $this->assertSame('render', $render['mode']);
        $this->assertNotEmpty($render['composition']);
        $this->assertSame('hero', $render['composition'][0]['component'], 'defaults render, first band is the hero');

        // The seed went through pp_update_composition(), NOT a raw meta write: the
        // versioned writer initializes the freshness marker to version 1. A raw
        // update_post_meta() (the pre-#506 path) would leave this absent.
        $this->assertSame(1, (int) get_post_meta($this->postId, '_pp_composition_version', true));
        $this->assertNotSame('', get_post_meta($this->postId, '_pp_composition_hash', true), 'writer sets the content hash');

        // The stored seed is a valid composition (authoring/validation surface, 14.1).
        $storedJson = get_post_meta($this->postId, '_pp_composition', true);
        $stored     = json_decode($storedJson, true);
        $this->assertIsArray($stored);
        $this->assertTrue(pp_is_list($stored));
        $this->assertTrue(pp_validate_composition($stored), 'seeded composition must be schema-valid');
    }

    public function testAbsentMetaSeedsOnlyOnce(): void
    {
        pp_resolve_front_page_render($this->postId);
        $this->assertSame(1, (int) get_post_meta($this->postId, '_pp_composition_version', true));

        // A second render must NOT re-seed (meta is now present): version stays 1.
        pp_resolve_front_page_render($this->postId);
        $this->assertSame(1, (int) get_post_meta($this->postId, '_pp_composition_version', true), 'second render must not seed again');
    }

    public function testSeedWriteFailureStillRendersDefaultsAndLeavesMetaAbsent(): void
    {
        // Best-effort seed: when the versioned writer can't acquire its lock it
        // returns a WP_Error and writes NOTHING. Render must still show the
        // defaults (blank-page promise holds) and leave the meta absent so the
        // next render retries — never a partial write. A global $wpdb whose
        // GET_LOCK query returns null makes pp_update_composition() fail closed.
        $GLOBALS['wpdb'] = new wpdb();
        try {
            $render = pp_resolve_front_page_render($this->postId);

            $this->assertSame('render', $render['mode']);
            $this->assertNotEmpty($render['composition'], 'defaults render even when the seed write is skipped');
            $this->assertSame('hero', $render['composition'][0]['component']);

            // The write was skipped: meta stays absent, version marker unset.
            $this->assertSame('', get_post_meta($this->postId, '_pp_composition', true), 'a failed seed writes no composition');
            $this->assertSame(0, (int) get_post_meta($this->postId, '_pp_composition_version', true), 'a failed seed leaves the version marker absent for a retry');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    // ── No static front page configured ──────────────────────────────────────

    public function testPostIdZeroIsNoFrontAndWritesNothing(): void
    {
        $before = $this->metaSnapshot(); // empty store

        $render = pp_resolve_front_page_render(0);

        $this->assertSame('no_front', $render['mode']);
        $this->assertSame([], $render['composition']);
        $this->assertSame($before, $this->metaSnapshot(), 'no_front must not write any meta');
    }

    // ── Only the configured static front page is ever seeded (#1173) ──────────

    public function testALatestPostsFrontPageNeverSeedsTheNewestPost(): void
    {
        // Settings -> Reading "Your latest posts": core loads front-page.php and the current
        // post is the NEWEST BLOG POST. Before #1173 a visitor GET wrote the default homepage
        // onto it. It is not a front page anyone configured: nothing is written.
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'posts';
        $newestPost = 4711;
        $before = $this->metaSnapshot();

        $render = pp_resolve_front_page_render($newestPost);

        $this->assertSame(['mode' => 'render', 'composition' => []], $render);
        $this->assertSame($before, $this->metaSnapshot(), 'a render must never write a composition onto a blog post');
        $this->assertArrayNotHasKey($newestPost, $GLOBALS['_pp_test_store']['post_meta']);
    }

    public function testAPageThatIsNotTheConfiguredFrontPageIsNeverSeeded(): void
    {
        // A static front page IS configured (506), but the queried page is another one (core's
        // is_page() also matches a page TITLED "506"). It renders what it stores, which is
        // nothing; only the configured front page may be seeded.
        $before = $this->metaSnapshot();

        $render = pp_resolve_front_page_render(777);

        $this->assertSame(['mode' => 'render', 'composition' => []], $render);
        $this->assertSame($before, $this->metaSnapshot(), 'only the configured front page may be seeded');
    }

    public function testAPageThatIsNotTheConfiguredFrontPageRendersWhatItStores(): void
    {
        update_post_meta(777, '_pp_composition', '[{"component":"hero","props":{"title":"Its own hero"}}]');
        $before = $this->metaSnapshot();

        $render = pp_resolve_front_page_render(777);

        $this->assertSame('render', $render['mode']);
        $this->assertSame('Its own hero', $render['composition'][0]['props']['title']);
        $this->assertSame($before, $this->metaSnapshot(), 'nothing re-written');
    }

    public function testNoFrontPageOptionMeansNoSeedEvenForAPage(): void
    {
        // show_on_front=page with page_on_front unset (0): no static front page exists.
        $GLOBALS['_pp_test_store']['options']['page_on_front'] = 0;
        $before = $this->metaSnapshot();

        $this->assertSame(['mode' => 'render', 'composition' => []], pp_resolve_front_page_render($this->postId));
        $this->assertSame($before, $this->metaSnapshot());
    }

    public function testFrontPageIdFollowsReadingSettings(): void
    {
        $this->assertSame($this->postId, pp_front_page_id());
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'posts';
        $this->assertSame(0, pp_front_page_id(), 'latest posts: page_on_front is ignored, as core ignores it');
        unset($GLOBALS['_pp_test_store']['options']['show_on_front']);
        $this->assertSame(0, pp_front_page_id(), 'unset show_on_front is not a static front page');
        $GLOBALS['_pp_test_store']['options']['show_on_front'] = 'page';
        $GLOBALS['_pp_test_store']['options']['page_on_front'] = '506';
        $this->assertSame(506, pp_front_page_id(), 'the stored option is a string in core; it is read as an id');
    }

    // ── Present valid composition: render, never seed ────────────────────────

    public function testPresentValidCompositionRendersWithoutSeeding(): void
    {
        $this->storeRaw('[{"component":"hero","props":{"title":"Live homepage"}}]');
        $before = $this->metaSnapshot();

        $render = pp_resolve_front_page_render($this->postId);

        $this->assertSame('render', $render['mode']);
        $this->assertCount(1, $render['composition']);
        $this->assertSame('hero', $render['composition'][0]['component']);
        $this->assertSame('Live homepage', $render['composition'][0]['props']['title']);

        // No seed, no version bump: the stored bytes are untouched.
        $this->assertSame($before, $this->metaSnapshot(), 'a present composition must not be re-written on render');
    }

    // ── Stored empty list is deliberate, not absent: render blank, no reseed ─

    public function testStoredEmptyListRendersBlankAndIsNotReseeded(): void
    {
        // A stored "[]" is a genuinely-authored empty homepage (raw !== null). The
        // blank-page promise covers only genuinely-absent meta, so this renders
        // blank and MUST NOT be overwritten with defaults (pre-#506 re-seeded it).
        $this->storeRaw('[]');
        $before = $this->metaSnapshot();

        $render = pp_resolve_front_page_render($this->postId);

        $this->assertSame('render', $render['mode']);
        $this->assertSame([], $render['composition']);
        $this->assertSame($before, $this->metaSnapshot(), 'a deliberately-empty composition must not be re-seeded');

        // Still classified as a clean (ok) empty page, not corruption.
        $result = pp_get_composition_result($this->postId);
        $this->assertTrue($result['ok']);
        $this->assertSame('[]', $result['raw']);
    }

    // ── Render parity with pp_composition(): no normalization on either path ──

    public function testRenderPathReturnsStoredPropsVerbatim(): void
    {
        // SUPERSEDES testRenderPathNormalizesLegacyProps (#495 -> #604). The front-page
        // resolver used to run pp_normalize_legacy_props() so a legacy-shaped cta rendered
        // its authored button. That call is gone, so the retired key survives to the
        // renderer, which does not read it — the authored value is lost at render.
        // Parity with pp_composition() is what actually matters here and it still holds:
        // NEITHER path normalizes now.
        $this->storeRaw('[{"component":"cta","props":{"cta_text":"Buy now","cta_url":"/checkout"}}]');

        $render = pp_resolve_front_page_render($this->postId);

        $this->assertSame('render', $render['mode']);
        $props = $render['composition'][0]['props'];
        $this->assertSame('Buy now', $props['cta_text'], 'the stored key survives untouched');
        $this->assertArrayNotHasKey('button_text', $props, 'nothing manufactures the canonical key');
    }

    public function testFreshlySeededHomepageIsUnaffectedByTheRemoval(): void
    {
        // The dropped normalize call was load-bearing for exactly one branch: the
        // in-memory seed, which never passes through a read. pp_default_homepage_composition()
        // is authored in the canonical vocabulary, so dropping the call changes nothing —
        // pinned so a future seed edit that reintroduces a retired name is caught here.
        foreach (pp_default_homepage_composition() as $i => $item) {
            $this->assertArrayHasKey('component', $item, "seed band {$i} names a component");
            foreach (array_keys($item['props'] ?? []) as $prop) {
                $this->assertNotContains(
                    $prop,
                    ['cta_text', 'cta_url', 'cta_variant', 'cta2_text', 'cta2_url', 'cta2_variant',
                     'subtitle', 'heading_align', 'text', 'variant'],
                    "seed band {$i} must not author the retired prop `{$prop}`"
                );
            }
        }
    }
}
