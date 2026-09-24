<?php
/**
 * tests/CliCallerExpectedVersionTest.php — `wp pp action execute` honours the caller's
 * `expected_version` (#1094).
 *
 * THE DEFECT, MEASURED ON wp-env BEFORE THE FIX. PP_Action_Command::execute() ran the
 * preflight-freshness gate and then OVERWROTE whatever `expected_version` the caller sent
 * with the baseline that gate computed. After every successful write the run's baseline is
 * advanced (_pp_cli_refresh_composition_baseline), so by the second write of a run it
 * already sits PAST the version an author read. The documented content-then-styling
 * revision — read the version and composition, land a content edit with update_component,
 * then write the restyled composition back with the version it read — reported `ok: true`,
 * `findings: []`, and put the content edit's title back to what it was before the edit.
 * The engine's compare-and-swap was sound the whole time; the CLI simply never asked it the
 * caller's question.
 *
 * THE FIX, AND WHAT IT DOES NOT CHANGE. An explicitly supplied value wins; the computed
 * baseline stays the default for a caller that sends none. The freshness gate still runs
 * and still refuses a stale PREFLIGHT exactly as before — that is a different question
 * ("is this run's preflight still valid?") from the caller's ("has the page moved since I
 * read it?"), and both are asked now.
 *
 * DRIVEN THROUGH THE REAL COMMAND, not a helper. The defect was an ordering inside the
 * command method, so only a test that runs the method can see it — the same reasoning
 * CorruptPageRepairCarveOutTest records for its own command-level pins.
 */

use PHPUnit\Framework\TestCase;

// ── WP_CLI stub (shared shape with CliGateTest/CorruptPageRepairCarveOutTest) ──
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

/** Grants the advisory lock the versioned writer takes, so the CAS runs its real branch. */
class PP_CallerCas_Lockable_Wpdb extends wpdb
{
    public function get_var(string $query)
    {
        if (str_contains($query, 'GET_LOCK')) {
            return '1';
        }
        return parent::get_var($query);
    }

    public function query(string $query)
    {
        return 1; // RELEASE_LOCK
    }
}

class CliCallerExpectedVersionTest extends TestCase
{
    /** @var string[] */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';
        $GLOBALS['_pp_test_store']['post_meta']     = [];
        $GLOBALS['_pp_test_store']['posts']         = [];
        $GLOBALS['_pp_test_store']['wpdb_postmeta'] = [];
        $GLOBALS['wpdb'] = new PP_CallerCas_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        foreach ($this->runs as $run_id) {
            pp_operate_cleanup_run($run_id);
        }
        $this->runs = [];
        unset(
            $GLOBALS['wpdb'],
            $GLOBALS['_pp_test_store']['wpdb_postmeta'],
            $GLOBALS['_pp_test_store']['options']['siteurl']
        );
        $GLOBALS['_pp_test_store']['post_meta'] = [];
        $GLOBALS['_pp_test_store']['posts']     = [];
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** One-band page written through the versioned writer, so it carries a real version. */
    private function page(): int
    {
        $post_id = pp_create_page('CAS page', 'draft');
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'ORIGINAL']],
        ]));
        return $post_id;
    }

    /** A run token that has INSPECTed and PREFLIGHTed the page, exactly as the loop does. */
    private function preflightedRun(int $post_id): string
    {
        $run_id = pp_operate_create_run();
        $this->assertIsString($run_id);
        $this->runs[] = $run_id;
        $this->assertTrue(pp_operate_record_preflight(
            $run_id,
            $post_id,
            [],
            pp_get_composition_marker($post_id),
            pp_get_composition($post_id)
        ));
        return $run_id;
    }

    private function execute(string $run_id, string $name, array $params): array
    {
        WP_CLI::$lines = [];
        try {
            (new PP_Action_Command())->execute([$name], [
                'run-id' => $run_id,
                'params' => json_encode($params),
            ]);
        } catch (WpCliHaltException $e) {
            // A refused action halts after emitting its envelope; the envelope is the subject.
        }
        $this->assertNotEmpty(WP_CLI::$lines, 'an envelope reached stdout');
        $decoded = json_decode(WP_CLI::$lines[0], true);
        $this->assertIsArray($decoded, 'stdout line is valid JSON');
        return $decoded;
    }

    private function storedTitle(int $post_id): string
    {
        return (string) (pp_get_composition($post_id)[0]['props']['title'] ?? '');
    }

    // ── THE REPRODUCTION ─────────────────────────────────────────────────────

    /**
     * The documented content-then-styling revision, in the order the instruction files teach:
     * read the version and composition, a content edit lands, then the styling write carries
     * the version it READ. That write must be refused; before the fix it landed and erased
     * the content edit with `ok: true`.
     */
    public function testAStaleCallerVersionIsRefusedInsteadOfErasingTheEditThatLandedFirst(): void
    {
        $post_id = $this->page();
        $run_id  = $this->preflightedRun($post_id);

        $read_version     = (int) pp_get_composition_marker($post_id)['version'];
        $read_composition = pp_get_composition($post_id);

        $content = $this->execute($run_id, 'update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'CONTENT EDIT'],
        ]);
        $this->assertTrue($content['ok'], 'premise: the content edit lands: ' . ($content['error'] ?? ''));
        $this->assertSame('CONTENT EDIT', $this->storedTitle($post_id), 'premise');

        $styling = $this->execute($run_id, 'update_composition', [
            'post_id'          => $post_id,
            'composition'      => $read_composition,
            'expected_version' => $read_version,
        ]);

        $this->assertFalse($styling['ok'], 'a write based on a version the page has moved past must not land');
        $this->assertSame('composition_conflict', $styling['error_code'] ?? null,
            'refused by the compare-and-swap, on the caller\'s own version');
        $this->assertSame('CONTENT EDIT', $this->storedTitle($post_id),
            'the content edit that landed first survives');
    }

    /**
     * The refusal message names the CALLER's number. Before the fix, even a refusal (had one
     * happened) would have quoted the run's baseline — a version the caller never sent.
     */
    public function testTheConflictNamesTheVersionTheCallerSent(): void
    {
        $post_id = $this->page();
        $run_id  = $this->preflightedRun($post_id);
        $stale   = (int) pp_get_composition_marker($post_id)['version'];

        $this->execute($run_id, 'update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'moved'],
        ]);
        $refused = $this->execute($run_id, 'update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'late'],
            'expected_version' => $stale,
        ]);

        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('expected version ' . $stale, (string) ($refused['error'] ?? ''));
    }

    // ── CONTROLS: nothing that worked before stops working ───────────────────

    /** A caller that sends the CURRENT version still writes. */
    public function testACurrentCallerVersionStillWrites(): void
    {
        $post_id = $this->page();
        $run_id  = $this->preflightedRun($post_id);

        $this->execute($run_id, 'update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'first'],
        ]);
        $current = (int) pp_get_composition_marker($post_id)['version'];

        $second = $this->execute($run_id, 'update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'second'],
            'expected_version' => $current,
        ]);

        $this->assertTrue($second['ok'], 'a correct baseline passes: ' . ($second['error'] ?? ''));
        $this->assertSame('second', $this->storedTitle($post_id));
    }

    /**
     * A caller that sends NO version keeps the computed baseline as its default — so two
     * writes in one run still chain (the refresh after each write is untouched) …
     */
    public function testACallerThatSendsNoVersionStillChainsThroughTheRun(): void
    {
        $post_id = $this->page();
        $run_id  = $this->preflightedRun($post_id);

        $one = $this->execute($run_id, 'update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'one'],
        ]);
        $two = $this->execute($run_id, 'update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'two'],
        ]);

        $this->assertTrue($one['ok'], (string) ($one['error'] ?? ''));
        $this->assertTrue($two['ok'], (string) ($two['error'] ?? ''));
        $this->assertSame('two', $this->storedTitle($post_id));
    }

    /**
     * … and that default is still a REAL compare-and-swap, not an unconditional write: a
     * writer landing between the freshness gate's read and the in-lock read is refused.
     * Staged as a row/cache divergence, the production shape CorruptPageRepairCarveOutTest
     * uses for the same pin — the gate reads the warmed cache, the writer reads the row.
     */
    public function testTheDefaultBaselineStillReachesTheWriteAsACompareAndSwap(): void
    {
        $post_id = $this->page();
        $run_id  = $this->preflightedRun($post_id);
        $GLOBALS['_pp_test_store']['wpdb_postmeta'][$post_id]['_pp_composition_version'] =
            (string) ((int) pp_get_composition_marker($post_id)['version'] + 1);

        $envelope = $this->execute($run_id, 'update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'x'],
        ]);

        $this->assertFalse($envelope['ok'], 'the computed baseline still guards a caller that sent none');
        $this->assertSame('composition_conflict', $envelope['error_code'] ?? null);
    }

    /**
     * The freshness gate keeps its own job. A caller-supplied version does NOT excuse a
     * preflight the page has moved past through another path: the command still halts with
     * the stale-preflight error, before any write, whatever number the caller sent.
     */
    public function testACallerVersionDoesNotBypassTheStalePreflightGate(): void
    {
        $post_id = $this->page();
        $run_id  = $this->preflightedRun($post_id);
        // Another path writes the page after the preflight.
        $this->assertTrue(pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['id' => 'h1', 'title' => 'external']],
        ]));
        $current = (int) pp_get_composition_marker($post_id)['version'];

        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('Stale preflight for post ' . $post_id);
        (new PP_Action_Command())->execute(['update_component'], [
            'run-id' => $run_id,
            'params' => json_encode([
                'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'y'],
                'expected_version' => $current,
            ]),
        ]);
    }
}
