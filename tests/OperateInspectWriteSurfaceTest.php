<?php
/**
 * tests/OperateInspectWriteSurfaceTest.php — what `wp pp operate inspect` writes (#1219).
 *
 * THE CLAIM BEING PINNED. The docs used to call INSPECT "read-only" and say it "never
 * mutates the site", while it has always written a row: the run state that mints the run
 * token every mutating command later checks. That row is by design and load-bearing, so
 * the fix was the description, not the command. The precise statement, now in
 * docs/reference-apply-cli.md and ai-instructions/operating-loop.md, is:
 *
 *   - inspect is mutation-free against site DESIGN state (compositions, chrome, presets,
 *     tokens, any option an operator owns);
 *   - it creates exactly one bookkeeping row, the run-state option
 *     `pp_operate_run_<uuid>`, with autoload off;
 *   - and, first, it deletes dead run-state rows: a value that is not an array, has no
 *     `created_at`, or is past the TTL (the sweep in pp_operate_gc_expired_runs()).
 *     Nothing else.
 *
 * A description is only as good as the thing that keeps it true, so these tests drive the
 * real CLI entry (PP_Operate_Command::inspect) and compare the WHOLE harness store before
 * and after: options, posts, post meta, theme mods, Custom CSS. Any new write that lands
 * anywhere in inspect's call graph — a helper inside pp_inspect_site() that starts caching
 * into an option, a migration that rewrites pp_site_udc on read, a sweep that reaps a live
 * run — turns one of these red, and the docs have to change with it.
 *
 * LIMIT, stated so nobody reads more into this than it holds: the harness store is the
 * boundary. A filesystem write (the deployment manifest lives on disk) or an object-cache
 * write is not seen here. A transient write would fatal, because the harness has no
 * transient stubs — which is red too.
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with CliEnvelopeEmitTest/CliGateTest) ──────────
if (!class_exists('WpCliExitException')) {
    class WpCliExitException extends \RuntimeException {}
}
if (!class_exists('WpCliHaltException')) {
    class WpCliHaltException extends \RuntimeException {}
}
if (!class_exists('WP_CLI_Command')) {
    class WP_CLI_Command {}
}
if (!class_exists('WP_CLI')) {
    class WP_CLI {
        public static array $lines = [];
        public static array $warnings = [];
        public static array $successes = [];
        public static function error($message, $exit = true): void { throw new WpCliExitException((string) $message); }
        public static function add_command($name, $handler, $args = []): void {}
        public static function add_hook($when, $callback): void {}
        public static function line($message = ''): void { self::$lines[] = (string) $message; }
        public static function warning($message = ''): void { self::$warnings[] = (string) $message; }
        public static function success($message = ''): void { self::$successes[] = (string) $message; }
        public static function debug($message = '', $group = false): void {}
        public static function log($message = ''): void {}
        public static function halt($code = 0): void { throw new WpCliHaltException((string) $code, (int) $code); }
    }
}

require_once dirname(__DIR__) . '/lib/cli.php';

class OperateInspectWriteSurfaceTest extends TestCase
{
    /** Design-state options an operator owns. Seeded so "untouched" is a real assertion. */
    private const DESIGN_OPTIONS = [
        'blogname'            => 'Inspect write-surface site',
        'pp_footer_copyright' => '(c) Inspect',
        'pp_site_udc'         => '{"nav":{"_band":{"background":{"fill":"#101828"}}},"_version":3}',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';
        foreach (self::DESIGN_OPTIONS as $key => $value) {
            $GLOBALS['_pp_test_store']['options'][$key] = $value;
        }
        unset($GLOBALS['wpdb']);
        WP_CLI::$lines     = [];
        WP_CLI::$warnings  = [];
        WP_CLI::$successes = [];
    }

    protected function tearDown(): void
    {
        // Leave the bootstrap's default store behind, not an unset one: later test classes
        // (OperateTest) rely on the store existing and never reset it themselves.
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
        ];
        unset(
            $GLOBALS['wpdb'],
            $GLOBALS['_pp_test_option_writes'],
            $GLOBALS['_pp_test_option_deletes'],
            $GLOBALS['_pp_test_option_autoload']
        );
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Runs the real CLI entry and returns [run_id, created_at window]. */
    private function runInspect(array $assoc_args = []): array
    {
        $GLOBALS['_pp_test_option_writes']   = [];
        $GLOBALS['_pp_test_option_deletes']  = [];
        $GLOBALS['_pp_test_option_autoload'] = [];

        $before = time();
        (new PP_Operate_Command())->inspect([], $assoc_args);
        $after = time();

        $this->assertCount(1, WP_CLI::$lines, 'inspect prints exactly one JSON document');
        $doc = json_decode(WP_CLI::$lines[0], true);
        $this->assertIsArray($doc, 'the document decodes');
        // The whole picture was computed, so the call graph under test is the real one
        // (the `pages` listing may come from pp_composition_pages()'s process memo).
        foreach (['target', 'pages', 'drift', 'preflight', 'tokens', 'chrome', 'conflicts', 'smells', 'token_smells', 'composition_decode_error'] as $field) {
            $this->assertArrayHasKey($field, $doc, "inspect computed `{$field}`");
        }
        $this->assertArrayHasKey('run_id', $doc, 'the CLI appends the run token');
        $this->assertTrue(pp_operate_valid_run_id((string) $doc['run_id']), 'the token is a UUID v4');

        return [(string) $doc['run_id'], $before, $after];
    }

    /** The one row inspect is allowed to create, checked field by field. */
    private function assertRunRow(string $run_id, int $before, int $after): void
    {
        $key = pp_operate_run_option_name($run_id);
        $this->assertSame('pp_operate_run_' . $run_id, $key);

        $row = $GLOBALS['_pp_test_store']['options'][$key] ?? null;
        $this->assertIsArray($row, 'the run-state row exists');
        $this->assertSame(['steps_completed', 'created_at', 'site_id'], array_keys($row), 'the row holds run bookkeeping and nothing else');
        $this->assertSame(['INSPECT'], $row['steps_completed']);
        $this->assertIsInt($row['created_at']);
        $this->assertGreaterThanOrEqual($before, $row['created_at']);
        $this->assertLessThanOrEqual($after, $row['created_at']);
        $this->assertSame(pp_operate_site_id(), $row['site_id']);

        $this->assertSame([$key => 1], $GLOBALS['_pp_test_option_writes'], 'one options write, on the run row, and no other');
        $this->assertFalse($GLOBALS['_pp_test_option_autoload'][$key], 'the run row is written with autoload OFF');
    }

    /** Everything in the store except the options slice. */
    private function nonOptionStore(): array
    {
        $store = $GLOBALS['_pp_test_store'];
        unset($store['options']);
        return $store;
    }

    /** A $wpdb that enumerates run rows matching the sweep's LIKE query, in insertion order (it does not model ORDER BY or LIMIT). */
    private function installSweepWpdb(): void
    {
        $store =& $GLOBALS['_pp_test_store']['options'];
        $GLOBALS['wpdb'] = new class($store) {
            public string $options = 'wp_options';
            private array $store;
            public function __construct(&$store) { $this->store =& $store; }
            public function esc_like(string $text): string { return $text; }
            public function prepare(string $query, ...$args): string { return $query; }
            public function get_col(string $query): array {
                $out = [];
                foreach (array_keys($this->store) as $name) {
                    if (strpos((string) $name, 'pp_operate_run_') === 0) { $out[] = $name; }
                }
                return $out;
            }
        };
    }

    // ── 1. A bare inspect writes the run row and nothing else ────────────────

    public function testBareInspectCreatesOnlyTheRunRow(): void
    {
        $options_before = $GLOBALS['_pp_test_store']['options'];
        $rest_before    = $this->nonOptionStore();

        [$run_id, $t0, $t1] = $this->runInspect();

        $options_after = $GLOBALS['_pp_test_store']['options'];
        $added   = array_diff_key($options_after, $options_before);
        $removed = array_diff_key($options_before, $options_after);

        $this->assertSame([pp_operate_run_option_name($run_id)], array_keys($added), 'exactly one new option: the run row');
        $this->assertSame([], $removed, 'nothing deleted (no $wpdb, so the sweep has nothing to enumerate)');
        $this->assertSame([], $GLOBALS['_pp_test_option_deletes'], 'no delete was even attempted');
        $this->assertRunRow($run_id, $t0, $t1);

        foreach ($options_before as $key => $value) {
            $this->assertSame($value, $options_after[$key], "option {$key} is byte-identical after inspect");
        }
        $this->assertSame($rest_before, $this->nonOptionStore(), 'posts, post meta, theme mods and Custom CSS are untouched');
    }

    // ── 2. The sweep: deletes expired/corrupt run rows, and only those ──────

    public function testInspectSweepDeletesOnlyDeadRunRows(): void
    {
        // Kept: a live run, one a few seconds short of the TTL, a live run minted by
        // another install (the sweep reaps on age and shape, never on identity), and a
        // fresh row with no steps_completed (the sweep checks only array + created_at).
        // Swept: long expired, a few seconds past the TTL, not an array, no timestamp.
        // The near-TTL pair sits 5s either side of the cutoff rather than on it: the
        // harness has no clock seam, so an exact-boundary row would race the second hand.
        $live      = wp_generate_uuid4();
        $near_ttl  = wp_generate_uuid4();
        $foreign   = wp_generate_uuid4();
        $partial   = wp_generate_uuid4();
        $expired   = wp_generate_uuid4();
        $just_past = wp_generate_uuid4();
        $corrupt   = wp_generate_uuid4();
        $no_stamp  = wp_generate_uuid4();
        $row = static fn(int $age, string $site) => [
            'steps_completed' => ['INSPECT', 'PREFLIGHT'], 'created_at' => time() - $age, 'site_id' => $site,
        ];
        $opts =& $GLOBALS['_pp_test_store']['options'];
        $opts[pp_operate_run_option_name($live)]      = $row(60, pp_operate_site_id());
        $opts[pp_operate_run_option_name($near_ttl)]  = $row(PP_OPERATE_RUN_TTL - 5, pp_operate_site_id());
        $opts[pp_operate_run_option_name($foreign)]   = $row(60, 'another-install');
        $opts[pp_operate_run_option_name($partial)]   = ['created_at' => time() - 60];
        $opts[pp_operate_run_option_name($expired)]   = $row(PP_OPERATE_RUN_TTL + 600, pp_operate_site_id());
        $opts[pp_operate_run_option_name($just_past)] = $row(PP_OPERATE_RUN_TTL + 5, pp_operate_site_id());
        $opts[pp_operate_run_option_name($corrupt)]   = 'garbage';
        $opts[pp_operate_run_option_name($no_stamp)]  = ['steps_completed' => ['INSPECT'], 'site_id' => pp_operate_site_id()];
        unset($opts);
        $this->installSweepWpdb();

        $options_before = $GLOBALS['_pp_test_store']['options'];
        $rest_before    = $this->nonOptionStore();

        [$run_id, $t0, $t1] = $this->runInspect();

        $options_after = $GLOBALS['_pp_test_store']['options'];
        $added   = array_keys(array_diff_key($options_after, $options_before));
        $removed = array_keys(array_diff_key($options_before, $options_after));
        $expected_removed = [
            pp_operate_run_option_name($expired),
            pp_operate_run_option_name($just_past),
            pp_operate_run_option_name($corrupt),
            pp_operate_run_option_name($no_stamp),
        ];

        $this->assertSame([pp_operate_run_option_name($run_id)], $added, 'one new option: the run row');
        $this->assertEqualsCanonicalizing($expected_removed, $removed, 'the sweep removed the dead run rows, nothing else');
        $this->assertEqualsCanonicalizing($expected_removed, array_keys($GLOBALS['_pp_test_option_deletes']), 'no delete was attempted on any other key');
        foreach ([$live, $near_ttl, $foreign, $partial] as $kept) {
            $this->assertArrayHasKey(pp_operate_run_option_name($kept), $options_after, 'a run row inside its TTL survives the sweep');
        }
        $this->assertRunRow($run_id, $t0, $t1);

        foreach ($options_after as $key => $value) {
            if (isset($options_before[$key])) {
                $this->assertSame($options_before[$key], $value, "surviving option {$key} is byte-identical (the live run included)");
            }
        }
        $this->assertSame($rest_before, $this->nonOptionStore(), 'posts, post meta, theme mods and Custom CSS are untouched');
    }

    // ── 3. --post_id adds page smells, and still writes only the run row ─────

    public function testPageScopedInspectLeavesThePageAlone(): void
    {
        // pp_composition_pages() memoizes for the whole process and has no reset seam.
        // Fill the memo BEFORE this test's page exists, so inspect (which reads it
        // without `$fresh`) can never be the call that memoizes this page and leaks it
        // into later test classes (AiContextTest expects an empty page list).
        pp_composition_pages();
        $post_id = pp_create_page('Inspect write-surface page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'band-1', 'title' => 'One']],
            ['component' => 'hero', 'props' => ['id' => 'band-2', 'title' => 'Two']],
        ]);
        $this->assertCount(2, pp_get_composition($post_id), 'the authored page exists before inspect runs');

        $options_before = $GLOBALS['_pp_test_store']['options'];
        $rest_before    = $this->nonOptionStore();

        [$run_id, $t0, $t1] = $this->runInspect(['post_id' => (string) $post_id]);

        $added = array_keys(array_diff_key($GLOBALS['_pp_test_store']['options'], $options_before));
        $this->assertSame([pp_operate_run_option_name($run_id)], $added, 'exactly one new option: the run row');
        $this->assertSame([], array_diff_key($options_before, $GLOBALS['_pp_test_store']['options']), 'nothing deleted');
        $this->assertRunRow($run_id, $t0, $t1);
        $this->assertSame($rest_before, $this->nonOptionStore(), 'the page, its composition meta and its history are untouched');
    }

    // ── 4. The run row cannot be written: no token, no row, sweep still ran ──

    public function testRefusedRunRowFailsWithoutATokenAndWritesNothingElse(): void
    {
        $expired = wp_generate_uuid4();
        $GLOBALS['_pp_test_store']['options'][pp_operate_run_option_name($expired)] = [
            'steps_completed' => ['INSPECT'], 'created_at' => time() - (PP_OPERATE_RUN_TTL + 600), 'site_id' => pp_operate_site_id(),
        ];
        $this->installSweepWpdb();

        // Refuse the NEW run row. Its key is a fresh UUID, so it cannot be named in
        // advance; this filter on the harness's own refusal list matches any run-row key
        // not already stored. The harness's delete_option reads the same list, so the
        // existing dead row must stay deletable, or the sweep would be refused too.
        $GLOBALS['_pp_test_unwritable_options'] = new class(array_keys($GLOBALS['_pp_test_store']['options'])) implements \ArrayAccess {
            public function __construct(private array $existing) {}
            public function offsetExists($k): bool {
                return strpos((string) $k, 'pp_operate_run_') === 0 && !in_array($k, $this->existing, true);
            }
            public function offsetGet($k): mixed { return $this->offsetExists($k); }
            public function offsetSet($k, $v): void {}
            public function offsetUnset($k): void {}
        };

        $options_before = $GLOBALS['_pp_test_store']['options'];
        $rest_before    = $this->nonOptionStore();
        $GLOBALS['_pp_test_option_deletes'] = [];
        $GLOBALS['_pp_test_option_writes']  = [];

        try {
            (new PP_Operate_Command())->inspect([], []);
            $this->fail('inspect must fail when the run row cannot be written');
        } catch (WpCliExitException $e) {
            $this->assertStringContainsString('Cannot create run token', $e->getMessage());
            // The message quotes the UUID it tried to store. The docs tell an agent to treat
            // it as unusable, because (on a refused write) nothing was stored under it.
            $this->assertSame(1, preg_match('/"([0-9a-f-]{36})"/', $e->getMessage(), $m), 'the error quotes a UUID');
            $this->assertSame('not_found', pp_operate_run_status($m[1]), 'the quoted UUID was never stored');
        } finally {
            unset($GLOBALS['_pp_test_unwritable_options']);
        }

        $this->assertSame([], WP_CLI::$lines, 'no document, so no token, is printed');
        $options_after = $GLOBALS['_pp_test_store']['options'];
        $this->assertSame([], array_diff_key($options_after, $options_before), 'no run row (and nothing else) was created');
        $this->assertSame(
            [pp_operate_run_option_name($expired)],
            array_keys(array_diff_key($options_before, $options_after)),
            'the sweep ran before the refused write and removed only the dead row'
        );
        $this->assertSame([pp_operate_run_option_name($expired)], array_keys($GLOBALS['_pp_test_option_deletes']), 'no other delete was attempted');
        $writes = array_keys($GLOBALS['_pp_test_option_writes']);
        $this->assertCount(1, $writes, 'exactly one write was attempted');
        $this->assertStringStartsWith('pp_operate_run_', $writes[0], 'and it was the (refused) run row');
        $this->assertSame($rest_before, $this->nonOptionStore(), 'posts, post meta, theme mods and Custom CSS are untouched');
    }
}
