<?php
/**
 * tests/ContentWriteTierDisallowTest.php
 *
 * The content write tier under WP-CLI honors DISALLOW_UNFILTERED_HTML, as WordPress core does
 * (wp-includes/capabilities.php, map_meta_cap() 'unfiltered_html': defined and truthy denies
 * the capability to every user). On an install that sets it, a CLI or agent write gets core
 * `post` parity like every web user there: a construct beyond core's set is refused by name
 * (clause unfiltered_html), and an admitted value is never vouched for the widened set. On an
 * install without it (or with it false), a CLI write is byte-identical to before: the full tier.
 *
 * RED ON MAIN: the hardened cases. pp_content_write_tier() returned 'full' for every WP-CLI
 * run, so the widened write landed and its marker vouched for the widened set.
 *
 * WHY EVERY TEST RUNS IN ITS OWN PROCESS. Both WP_CLI and DISALLOW_UNFILTERED_HTML are real
 * PHP constants: they cannot be mocked or undefined once defined, and the rest of the suite
 * must keep running without WP_CLI. Each case defines what it needs in a fresh process
 * (precedent: tests/ChatPageListPermissionTest.php).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class ContentWriteTierDisallowTest extends TestCase
{
    /** Constructs only the widened (unfiltered_html) set admits. */
    private const BEYOND = [
        '<svg viewBox="0 0 1 1"><path d="M0 0"/></svg>',
        '<div tabindex="0">t</div>',
        '<a href="sip:100">call</a>',
        '<img src="data:image/png;base64,AAAA" alt="">',
        '<my-widget>w</my-widget>',
        '<p style="display:grid">x</p>',
    ];

    /** Markup core's `post` set admits whole. */
    private const PARITY = '<p class="lead">Hello <a href="/x" title="t">there</a> <strong>s</strong></p>';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
        unset($GLOBALS['post']);
        pp_content_end_vouch_action();
    }

    private function section(string $body): array
    {
        return ['component' => 'section', 'props' => ['title' => 'T', 'body' => $body]];
    }

    private function write(int $post_id, string $body): array
    {
        return pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$this->section($body)]]);
    }

    private function render(int $post_id): string
    {
        $GLOBALS['post'] = (object) ['ID' => $post_id, 'post_type' => 'page', 'post_password' => ''];
        ob_start();
        pp_render_composition_bands(pp_get_composition($post_id));
        return (string) ob_get_clean();
    }

    /** Defines WP_CLI and, unless $disallow is the 'undefined' marker, DISALLOW_UNFILTERED_HTML. */
    private static function boot(mixed $disallow): void
    {
        define('WP_CLI', true);
        if ($disallow !== 'undefined') {
            define('DISALLOW_UNFILTERED_HTML', $disallow);
        }
    }

    /** Core's semantics: defined and truthy. */
    public static function hardened(): array
    {
        return ['true' => [true], 'truthy int' => [1]];
    }

    /** Defined falsy, or absent: the install is not hardened. */
    public static function notHardened(): array
    {
        return ['false' => [false], 'falsy int' => [0], 'undefined' => ['undefined']];
    }

    #[DataProvider('hardened')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testACliWriteOnAHardenedInstallGetsCoreParity(mixed $disallow): void
    {
        self::boot($disallow);
        // The bootstrap's default user holds every capability: the CLI branch, not the user,
        // decides here, exactly as in a real `wp` run.
        $this->assertTrue(current_user_can('unfiltered_html'));
        $this->assertSame('core', pp_content_write_tier());

        $post_id = pp_create_page('Hardened', 'draft');
        foreach (self::BEYOND as $body) {
            $result = $this->write($post_id, $body);
            $this->assertFalse($result['ok'], $body);
            $this->assertStringContainsString('unfiltered_html', $result['error'] ?? '', $body);
        }
        $this->assertSame([], pp_get_composition($post_id), 'a refused write stores nothing');

        // What core admits is written, and recorded at post parity: no widened vouch.
        $result = $this->write($post_id, self::PARITY);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame(self::PARITY, pp_get_composition($post_id)[0]['props']['body']);
        $this->assertArrayNotHasKey(pp_content_value_hash(self::PARITY), pp_content_vouched($post_id)['f']);
    }

    #[DataProvider('notHardened')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testACliWriteWithoutTheConstantKeepsTheFullTier(mixed $disallow): void
    {
        self::boot($disallow);
        $this->assertSame('full', pp_content_write_tier());

        $post_id = pp_create_page('Not hardened', 'draft');
        foreach (self::BEYOND as $body) {
            $result = $this->write($post_id, $body);
            $this->assertTrue($result['ok'], $body . ': ' . ($result['error'] ?? ''));
            $this->assertSame($body, pp_get_composition($post_id)[0]['props']['body'], 'stored as authored');
            $this->assertArrayHasKey(pp_content_value_hash($body), pp_content_vouched($post_id)['f'], 'vouched for the widened set');
        }
        // The last write's widened construct renders as written.
        $this->assertStringContainsString('style="display:grid"', $this->render($post_id));

        $result = $this->write($post_id, self::PARITY);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertArrayHasKey(pp_content_value_hash(self::PARITY), pp_content_vouched($post_id)['f']);
    }

    /**
     * The two-write re-record route (ai-instructions/validate-site.md) on a hardened install:
     * a stored widened value whose marker no longer vouches cannot be re-recorded from WP-CLI.
     * Writing it back is refused by name, and the page keeps rendering it at core parity.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheReRecordRouteDoesNotReVouchWidenedContentOnAHardenedInstall(): void
    {
        self::boot(true);
        $widened = '<p style="display:grid">x</p>';
        $post_id = pp_create_page('Legacy', 'draft');
        // Raw meta, no marker: what an unsigned (pre-#1249) or rolled-back page holds.
        update_post_meta($post_id, '_pp_composition', wp_slash(wp_json_encode([$this->section($widened)])));
        $this->assertStringNotContainsString('display:grid', $this->render($post_id), 'unvouched: it renders at core parity');

        $this->assertTrue($this->write($post_id, '<p>changed</p>')['ok'], 'the first write (changed) lands');
        $back = $this->write($post_id, $widened);
        $this->assertFalse($back['ok'], 'the second write (back to the widened bytes) is refused');
        $this->assertStringContainsString('unfiltered_html', $back['error'] ?? '');
        $this->assertArrayNotHasKey(pp_content_value_hash($widened), pp_content_vouched($post_id)['f']);
    }

    /**
     * Disclosed in the CHANGELOG: on a hardened install a CLI edit to a band whose content an
     * earlier write left beyond core's set is refused whole (the changed band is judged whole,
     * as for any web user without unfiltered_html); a band the write does not change is not
     * re-checked (M-2), so an edit elsewhere on the page still lands.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnAHardenedInstallACliEditToABandHoldingWidenedContentIsRefused(): void
    {
        self::boot(true);
        $widened = $this->section('<p style="display:grid">x</p>');
        $post_id = pp_create_page('Widened band', 'draft');
        update_post_meta($post_id, '_pp_composition', wp_slash(wp_json_encode([$widened, $this->section('<p>other</p>')])));

        $retitled = $widened;
        $retitled['props']['title'] = 'Retitled';
        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$retitled, $this->section('<p>other</p>')]]);
        $this->assertFalse($result['ok'], 'the edited band still holds widened content');
        $this->assertStringContainsString('unfiltered_html', $result['error'] ?? '');

        $result = pp_execute_action('update_composition', ['post_id' => $post_id, 'composition' => [$widened, $this->section('<p>other, edited</p>')]]);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('<p style="display:grid">x</p>', pp_get_composition($post_id)[0]['props']['body'], 'the untouched band is kept as stored');
    }
}
