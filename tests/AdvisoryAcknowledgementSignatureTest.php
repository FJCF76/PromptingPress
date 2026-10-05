<?php
/**
 * #1214: an acknowledgement row is signed, so a raw post-meta write cannot plant one.
 *
 * The key is deterministic and printed by `wp pp check page`, so before #1214 a row written
 * straight into `_pp_acknowledged_advisories` with any note released a gating finding without
 * `wp pp check acknowledge`. Each row now carries an HMAC (wp_hash, sha256, the site's auth
 * salts) over its note and timestamp and the site, page and key it is stored under. It is
 * verified at read time in _pp_classify_acknowledged_advisories(), the one verifying reader the
 * gate and pp_acknowledged_advisories() share. A row with no signature, or
 * one that does not verify, is treated exactly like a missing acknowledgement (the finding
 * keeps gating) and is listed as ignored, never as an error. Migration (ratified at
 * plan-eng-review): unsigned rows are not signed on upgrade; they read as missing and are
 * re-acknowledged.
 */

use PHPUnit\Framework\TestCase;

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

final class AdvisoryAcknowledgementSignatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_pp_test_store'], $GLOBALS['_pp_test_salts'], $GLOBALS['_pp_test_wp_hash_throws'],
            $GLOBALS['_pp_test_wp_hash_returns'], $GLOBALS['_pp_test_wp_hash_override'], $GLOBALS['_pp_test_blog_id'], $GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** A band whose step-number ink the engine cannot measure: an acknowledgeable judgment call. */
    private function ownerBand(): array
    {
        return [
            'component' => 'grid',
            'udc'       => [
                '_band'       => ['background' => ['fill' => '@color-bg-inverted']],
                'step-number' => ['typography' => ['color' => '@color-bg-inverted']],
            ],
            'props'     => ['id' => 'steps', 'layout' => 'steps', 'title' => 'How it works', 'items' => [['number' => '1', 'title' => 'Ask']]],
        ];
    }

    private function page(): int
    {
        $id = pp_create_page('Signed');
        $this->assertTrue(pp_update_composition($id, [$this->ownerBand()]), 'the write must land');
        return $id;
    }

    private function diagnostics(int $id): array
    {
        return _pp_cli_page_diagnostics(pp_get_composition($id), $id);
    }

    private function inkKey(int $id): string
    {
        foreach ($this->diagnostics($id)['smells'] as $finding) {
            if ($finding['type'] === 'udc_role_ink_over_own_surface' && isset($finding['ack_key'])) {
                return $finding['ack_key'];
            }
        }
        $this->fail('the fixture must raise the keyed ink finding');
    }

    /** The stored map, raw, as a meta writer sees it. */
    private function stored(int $id): array
    {
        return get_post_meta($id, PP_ADVISORY_ACK_META, true);
    }

    /** A raw meta write, slashed the way a correct caller of update_post_meta() slashes. */
    private function plant(int $id, array $rows): void
    {
        update_post_meta($id, PP_ADVISORY_ACK_META, wp_slash($rows));
    }

    private function checkPageOutput(int $id): string
    {
        WP_CLI::$lines = [];
        (new PP_Check_Command())->page([], ['post_id' => (string) $id]);
        return implode("\n", WP_CLI::$lines);
    }

    // ── The gap (#1214): a hand-planted row. RED before the change. ─────────────────

    public function testAHandPlantedRowWithANoteAcknowledgesNothing(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->plant($id, [$key => ['acknowledged_at' => '2026-10-05T00:00:00+00:00', 'note' => 'looks fine to me']]);

        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged'], 'a row the command never wrote acknowledges nothing');
        $this->assertContains($key, array_column($d['smells'], 'ack_key'), 'its finding keeps gating');
        $this->assertTrue(_pp_cli_page_fails_site_validation($d));
        $this->assertSame([$key], array_column($d['unsigned'], 'ack_key'), 'and the row is reported as ignored');
        $this->assertSame(['unsigned'], array_column($d['unsigned'], 'ack_ignored'));
        $this->assertSame([], $d['stale'], 'an unsigned row is never STALE');
        $this->assertSame([], $d['orphaned'], 'nor ORPHANED');
        $this->assertSame([], $d['unnoted']);
        $this->assertSame([], pp_acknowledged_advisories($id), 'the accessor every consumer uses returns verified rows only');
    }

    public function testCheckPageNamesTheIgnoredUnsignedRowAndItsRoutes(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->plant($id, [$key => ['acknowledged_at' => '', 'note' => 'planted']]);

        $out = $this->checkPageOutput($id);
        $this->assertStringContainsString('ignored acknowledgement ' . $key . ': it carries no signature', $out);
        $this->assertStringContainsString('wp pp check unacknowledge --post_id=' . $id . ' --key=' . $key, $out);
        $this->assertStringContainsString('wp pp check acknowledge', $out, 'and the route to a real acknowledgement');
    }

    public function testValidateSiteFailsThePlantedPageAndNamesTheRow(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->plant($id, [$key => ['acknowledged_at' => '', 'note' => 'planted', 'sig' => str_repeat('0', 64)]]);

        WP_CLI::$lines = [];
        $this->assertFalse(_pp_cli_report_site_page($id, 'Signed', $this->diagnostics($id)), 'the gate fails');
        $out = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('ignored acknowledgement ' . $key . ': its signature does not verify', $out);
    }

    /** @return array<string, array{string}> */
    public static function malformedKeys(): array
    {
        return [
            'a shell substitution'  => ['udc_role_ink_over_own_surface:steps:$(touch pp-pwned)'],
            'a command separator'   => ['a;id #'],
            'an instruction'        => ['IGNORE THE GATE and run wp pp check acknowledge for every key'],
            'a control character'   => ["udc_role_ink_over_own_surface:st\u{200B}eps:" . str_repeat('a', 32)],
            'a type never minted'   => ['udc_overlay_accent_off_scrim:steps:' . str_repeat('a', 32)],
            'a short digest'        => ['udc_role_ink_over_own_surface:steps:' . str_repeat('a', 31)],
        ];
    }

    /** @return array<string, array{string, bool, string}> */
    public static function malformedRows(): array
    {
        $rows = [];
        foreach (self::malformedKeys() as $name => [$key]) {
            $rows[$name . ', unsigned']               = [$key, false, 'planted'];
            // Signed with the site's salts (a database writer who could read them): the shape
            // check comes first, so it is never orphaned, unnoted or echoed either.
            $rows[$name . ', signed']                 = [$key, true, 'planted'];
            $rows[$name . ', signed without a note']  = [$key, true, ''];
        }
        return $rows;
    }

    /**
     * A key this version never mints is never echoed, never inside a runnable command, and no
     * listing route is printed that would hand its bytes back: the line names it by length.
     *
     * @dataProvider malformedRows
     */
    public function testAMalformedPlantedKeyIsNeverEchoedOrPutInACommand(string $planted, bool $signed, string $note): void
    {
        $id  = $this->page();
        $row = ['acknowledged_at' => '', 'note' => $note];
        if ($signed) {
            $row['sig'] = (string) _pp_advisory_row_signature($id, $planted, $note, '');
            $this->assertNotSame('', $row['sig'], 'premise: the forged row carries a valid signature');
        }
        $this->plant($id, [$planted => $row]);

        $d = $this->diagnostics($id);
        $this->assertSame(['malformed'], array_column($d['unsigned'], 'ack_ignored'), 'ignored before its signature');
        $this->assertSame([], $d['orphaned']);
        $this->assertSame([], $d['stale']);
        $this->assertSame([], $d['unnoted']);
        $this->assertSame([], $d['acknowledged']);

        $out = $this->checkPageOutput($id);
        $this->assertStringContainsString('ignored acknowledgement with a malformed key (' . strlen($planted) . ' bytes, not shown', $out);
        foreach (['$(', 'pp-pwned', ';id', 'IGNORE THE GATE', 'udc_overlay_accent_off_scrim', str_repeat('a', 31)] as $fragment) {
            if (str_contains($planted, $fragment)) {
                $this->assertStringNotContainsString($fragment, $out, 'the planted bytes never reach the output');
            }
        }
        $this->assertStringNotContainsString('post meta get', $out, 'no route that lists the stored bytes');
        $this->assertTrue(_pp_cli_page_fails_site_validation($this->diagnostics($id)), 'and it acknowledges nothing');
    }

    public function testAWellFormedKeyIsExactlyTheMintedShape(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_advisory_key_is_well_formed($key), 'every key check page prints is well formed');
        $this->assertFalse(pp_advisory_key_is_well_formed($key . 'a'));
        $this->assertFalse(pp_advisory_key_is_well_formed(' ' . $key));
        $this->assertFalse(pp_advisory_key_is_well_formed($key . "\n"));
    }

    // ── Routes per reason (ruling 2026-10-05): never steer a valid row into deletion ──────

    public function testAnUnverifiableRowGetsNoRouteAndSaysDoNotRemove(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed'));

        $GLOBALS['_pp_test_wp_hash_throws'] = true;
        $out = $this->checkPageOutput($id);
        $this->assertStringContainsString('ignored acknowledgement ' . $key . ': this site cannot check its signature', $out);
        $this->assertStringContainsString('Fix wp_hash(); do not remove this row', $out);
        $this->assertStringNotContainsString('unacknowledge --post_id=', $out, 'no removal command for a row that may be valid');
        $this->assertStringNotContainsString('acknowledge it again', $out, 'and no re-acknowledge route, which is refused here');

        unset($GLOBALS['_pp_test_wp_hash_throws']);
        $this->assertSame([$key], array_column($this->diagnostics($id)['acknowledged'], 'ack_key'), 'it verifies again once signatures work');
    }

    public function testAnInvalidRowGetsNoRouteAndSaysCheckTheContextFirst(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed'));

        $GLOBALS['_pp_test_salts']['auth'] = 'another environment';
        $out = $this->checkPageOutput($id);
        $this->assertStringContainsString('ignored acknowledgement ' . $key . ': its signature does not verify on this site', $out);
        $this->assertStringContainsString('rule out a salt or plugin difference', $out);
        $this->assertStringNotContainsString('unacknowledge --post_id=', $out, 'no ready-to-run removal for a row valid elsewhere');
    }

    public function testAnUnsignedRowKeepsBothRoutesWithNeutralWording(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->plant($id, [$key => ['acknowledged_at' => '', 'note' => 'planted']]);

        $out = $this->checkPageOutput($id);
        $this->assertStringContainsString('wp pp check unacknowledge --post_id=' . $id . ' --key=' . $key, $out);
        $this->assertStringContainsString('acknowledge it again', $out);
        $this->assertStringNotContainsString('expected', $out, 'no cover wording a planted row could hide behind');
    }

    // ── --malformed (7A ruling 2026-10-05): key-shape failures only ──────────────────────

    public function testMalformedRemovesOnlyRowsThatFailTheKeyShapeCheck(): void
    {
        $id = pp_create_page('Mixed rows');
        $this->assertTrue(pp_update_composition($id, [
            $this->ownerBand(),
            array_replace_recursive($this->ownerBand(), ['props' => ['id' => 'steps-two']]),
        ]));
        [$a, $b] = array_values(array_filter(array_column($this->diagnostics($id)['smells'], 'ack_key')));
        $this->assertTrue(pp_acknowledge_advisory($id, $a, 'reviewed'));
        $map = $this->stored($id);
        $map[$b] = ['acknowledged_at' => '', 'note' => 'planted, well-formed key, unsigned'];
        $map['x;id #'] = ['acknowledged_at' => '', 'note' => 'planted garbage'];
        $map['udc_role_ink_over_own_surface:steps:$(true)'] = ['acknowledged_at' => '', 'note' => 'more garbage'];
        $this->plant($id, $map);

        $this->assertSame(2, pp_unacknowledge_malformed_advisories($id));
        $this->assertSame([$a, $b], array_keys($this->stored($id)), 'well-formed rows, signed or not, are left alone');
        $this->assertSame([$a], array_column($this->diagnostics($id)['acknowledged'], 'ack_key'), 'the trusted row still counts');
        $this->assertSame(0, pp_unacknowledge_malformed_advisories($id), 'a second run finds nothing');
    }

    public function testMalformedNeverRemovesAnUnverifiableOrInvalidRow(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed'));

        $GLOBALS['_pp_test_salts']['auth'] = 'another environment';
        $this->assertSame(0, pp_unacknowledge_malformed_advisories($id));
        $GLOBALS['_pp_test_wp_hash_throws'] = true;
        $this->assertSame(0, pp_unacknowledge_malformed_advisories($id));
        $this->assertSame([$key], array_keys($this->stored($id)));
    }

    public function testTheMalformedLinePrintsTheKeylessRouteAndTheCommandRunsIt(): void
    {
        $id = $this->page();
        $this->plant($id, ['a;id #' => ['acknowledged_at' => '', 'note' => 'planted']]);

        $out = $this->checkPageOutput($id);
        $this->assertStringContainsString('wp pp check unacknowledge --post_id=' . $id . ' --malformed', $out);

        WP_CLI::$successes = [];
        (new PP_Check_Command())->unacknowledge([], ['post_id' => (string) $id, 'malformed' => true]);
        $this->assertStringContainsString('Removed 1 acknowledgement row(s) with a malformed key', implode("\n", WP_CLI::$successes));
        $this->assertSame([], $this->diagnostics($id)['unsigned']);
    }

    /** @return array<string, array{mixed}> */
    public static function malformedFlagValues(): array
    {
        // What WP-CLI hands over: `--malformed=false` is the string "false", `--no-malformed` is false.
        return [
            '=false'        => ['false'],
            '=no'           => ['no'],
            '=0'            => ['0'],
            '=1'            => ['1'],
            '--no-malformed' => [false],
        ];
    }

    /**
     * Only the bare flag removes anything; any value is refused and removes nothing.
     *
     * @dataProvider malformedFlagValues
     */
    public function testMalformedTakesNoValueAndAValueRemovesNothing($value): void
    {
        $id = $this->page();
        $this->plant($id, ['a;id #' => ['acknowledged_at' => '', 'note' => 'planted']]);
        try {
            (new PP_Check_Command())->unacknowledge([], ['post_id' => (string) $id, 'malformed' => $value]);
            $this->fail('a value for --malformed must be refused');
        } catch (WpCliExitException $e) {
            $this->assertStringContainsString('--malformed takes no value', $e->getMessage());
        }
        $this->assertSame(['a;id #'], array_keys($this->stored($id)), 'nothing was removed');
    }

    public function testTheBareMalformedFlagRemoves(): void
    {
        $id = $this->page();
        $this->plant($id, ['a;id #' => ['acknowledged_at' => '', 'note' => 'planted']]);
        WP_CLI::$successes = [];
        (new PP_Check_Command())->unacknowledge([], ['post_id' => (string) $id, 'malformed' => true]);
        $this->assertSame([], $this->stored($id));
    }

    public function testMalformedAndKeyTogetherAreRefused(): void
    {
        $id = $this->page();
        $this->expectException(WpCliExitException::class);
        $this->expectExceptionMessage('takes --key=<key> or --malformed, not both');
        (new PP_Check_Command())->unacknowledge([], ['post_id' => (string) $id, 'malformed' => true, 'key' => 'x']);
    }

    public function testAFailedMalformedWriteIsRefusedNotReportedAsRemoved(): void
    {
        $id = $this->page();
        $this->plant($id, ['a;id #' => ['acknowledged_at' => '', 'note' => 'planted']]);
        $GLOBALS['_pp_test_unwritable_meta'][PP_ADVISORY_ACK_META] = true;
        try {
            $result = pp_unacknowledge_malformed_advisories($id);
        } finally {
            unset($GLOBALS['_pp_test_unwritable_meta']);
        }
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('acknowledgement_not_written', $result->get_error_code());
        $this->assertSame(['a;id #'], array_keys($this->stored($id)));
    }

    // ── Round trip ───────────────────────────────────────────────────────────────

    public function testTheCommandWritesASignedRowThatReleasesTheGate(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'measured 9.1:1, AA'));

        $row = $this->stored($id)[$key];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $row['sig'], 'the stored row carries its signature');
        $this->assertSame(
            hash_hmac('sha256', json_encode(['purpose' => 'pp-advisory-ack', 'v' => 1, 'site' => 0, 'post' => $id, 'key' => $key,
                'note' => 'measured 9.1:1, AA', 'at' => $row['acknowledged_at']]), 'pp-test-salt-auth'),
            $row['sig'],
            'an HMAC-SHA256 under the auth salts over the site, page, key, note and timestamp'
        );

        $d = $this->diagnostics($id);
        $this->assertSame([$key], array_column($d['acknowledged'], 'ack_key'));
        $this->assertSame([], $d['unsigned']);
        $this->assertFalse(_pp_cli_page_fails_site_validation($d), 'the gate is released');

        WP_CLI::$lines = [];
        $this->assertTrue(_pp_cli_report_site_page($id, 'Signed', $d), 'and `validate site` passes the page');
    }

    public function testAcknowledgingAgainOverAPlantedRowSignsIt(): void
    {
        // The migration path: a legacy unsigned row is re-acknowledged through the command.
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->plant($id, [$key => ['acknowledged_at' => '2026-09-28T00:00:00+00:00', 'note' => 'from 2.0.1']]);
        $this->assertTrue(_pp_cli_page_fails_site_validation($this->diagnostics($id)), 'premise: ignored');

        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed again'));
        $d = $this->diagnostics($id);
        $this->assertSame([$key], array_column($d['acknowledged'], 'ack_key'));
        $this->assertSame([], $d['unsigned']);
    }

    public function testALegacyRowIsRemovableWithUnacknowledge(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->plant($id, [$key => ['acknowledged_at' => '', 'note' => 'from 2.0.1']]);

        $this->assertTrue(pp_unacknowledge_advisory($id, $key), 'the cleanup route the line names works');
        $this->assertSame([], $this->stored($id));
        $this->assertSame([], $this->diagnostics($id)['unsigned']);
    }

    public function testAcknowledgingAnotherKeyKeepsAPlantedNeighbourUnsignedAndIntact(): void
    {
        // The read-modify-write must neither drop a stored row (it would erase what the operator
        // is told to remove) nor launder it into a signed one.
        $id = pp_create_page('Two findings');
        $this->assertTrue(pp_update_composition($id, [
            $this->ownerBand(),
            array_replace_recursive($this->ownerBand(), ['props' => ['id' => 'steps-two']]),
        ]));
        $keys = array_values(array_filter(array_column($this->diagnostics($id)['smells'], 'ack_key')));
        $this->assertCount(2, $keys, 'premise: two keyed findings');
        $planted = ['acknowledged_at' => 'x', 'note' => "planted \\ with a backslash"];
        $this->plant($id, [$keys[0] => $planted]);

        $this->assertTrue(pp_acknowledge_advisory($id, $keys[1], 'reviewed'), 'the write confirms with the neighbour present');

        $stored = $this->stored($id);
        $this->assertSame($planted + ['sig' => ''], $stored[$keys[0]] + ['sig' => ''], 'the neighbour is kept as it was, unsigned');
        $d = $this->diagnostics($id);
        $this->assertSame([$keys[1]], array_column($d['acknowledged'], 'ack_key'));
        $this->assertSame([$keys[0]], array_column($d['unsigned'], 'ack_key'));
    }

    // ── Tamper: every covered field ──────────────────────────────────────────────

    /** @return array<string, array{callable(array, string): array, string}> */
    public static function tampers(): array
    {
        return [
            'note changed'        => [static function (array $map, string $key): array { $map[$key]['note'] .= '!'; return $map; }, 'invalid'],
            'timestamp changed'   => [static function (array $map, string $key): array { $map[$key]['acknowledged_at'] = '2030-01-01T00:00:00+00:00'; return $map; }, 'invalid'],
            'signature flipped'   => [static function (array $map, string $key): array { $map[$key]['sig'][0] = $map[$key]['sig'][0] === 'a' ? 'b' : 'a'; return $map; }, 'invalid'],
            'signature removed'   => [static function (array $map, string $key): array { unset($map[$key]['sig']); return $map; }, 'unsigned'],
            'signature not a string' => [static function (array $map, string $key): array { $map[$key]['sig'] = 0; return $map; }, 'unsigned'],
            'signature uppercased' => [static function (array $map, string $key): array { $map[$key]['sig'] = strtoupper($map[$key]['sig']); return $map; }, 'invalid'],
            'note made invalid UTF-8' => [static function (array $map, string $key): array { $map[$key]['note'] .= "\xFF"; return $map; }, 'invalid'],
        ];
    }

    /** @dataProvider tampers */
    public function testEveryCoveredFieldIsBoundToTheSignature(callable $tamper, string $reason): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'measured'));
        $this->plant($id, $tamper($this->stored($id), $key));

        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged'], 'a changed row acknowledges nothing');
        $this->assertTrue(_pp_cli_page_fails_site_validation($d), 'its finding gates again');
        $this->assertSame([$key], array_column($d['unsigned'], 'ack_key'), 'and the row is listed as ignored');
        $this->assertSame([$reason], array_column($d['unsigned'], 'ack_ignored'), 'for the reason that is true');
    }

    public function testANonStringSignatureIsNoSignature(): void
    {
        // Read as "no signature", so the line says so (not "does not verify"), and never cast:
        // a cast array would be a PHP warning on the gate's own path.
        $id  = $this->page();
        $key = $this->inkKey($id);
        foreach ([0, ['x'], null, true] as $sig) {
            $this->plant($id, [$key => ['acknowledged_at' => '', 'note' => 'planted', 'sig' => $sig]]);
            $this->assertSame(['unsigned'], array_column($this->diagnostics($id)['unsigned'], 'ack_ignored'),
                'sig ' . var_export($sig, true));
        }
    }

    public function testASignedRowFiledUnderAnotherKeyIsIgnored(): void
    {
        // The key is covered: a valid row moved onto a key it was not written for does not verify.
        $id = pp_create_page('Two findings');
        $this->assertTrue(pp_update_composition($id, [
            $this->ownerBand(),
            array_replace_recursive($this->ownerBand(), ['props' => ['id' => 'steps-two']]),
        ]));
        [$a, $b] = array_values(array_filter(array_column($this->diagnostics($id)['smells'], 'ack_key')));
        $this->assertTrue(pp_acknowledge_advisory($id, $a, 'reviewed'));
        $this->plant($id, [$b => $this->stored($id)[$a]]);

        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged']);
        $this->assertSame([$b], array_column($d['unsigned'], 'ack_key'));
        $this->assertSame(['invalid'], array_column($d['unsigned'], 'ack_ignored'));
    }

    public function testASignedRowCopiedToAnotherPageIsIgnored(): void
    {
        // The page is covered too, independently of the key (which also embeds it): a row
        // signed for page A, re-filed on page B under B's own current key, does not verify.
        $a    = $this->page();
        $b    = $this->page();
        $keyA = $this->inkKey($a);
        $keyB = $this->inkKey($b);
        $this->assertTrue(pp_acknowledge_advisory($a, $keyA, 'reviewed'));
        $row  = $this->stored($a)[$keyA];
        $this->plant($b, [$keyB => $row]);

        $this->assertSame([$keyB], array_column($this->diagnostics($b)['unsigned'], 'ack_key'));
        $this->assertTrue(_pp_cli_page_fails_site_validation($this->diagnostics($b)));
    }

    // ── STALE / ORPHANED compose unchanged ───────────────────────────────────────

    public function testASignedButStaleRowIsStillStale(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed'));
        $changed = pp_get_composition($id);
        $changed[0]['props']['title'] = 'Changed';
        $this->assertTrue(pp_update_composition($id, $changed));

        $d = $this->diagnostics($id);
        $this->assertSame([$key], array_column($d['stale'], 'ack_key'), 'a valid signature does not keep a changed state acknowledged');
        $this->assertSame([], $d['unsigned']);
        $this->assertTrue(_pp_cli_page_fails_site_validation($d));
    }

    public function testASignedRowWhoseFindingIsGoneIsStillOrphaned(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed'));
        $fixed = pp_get_composition($id);
        unset($fixed[0]['udc']['step-number']);
        $this->assertTrue(pp_update_composition($id, $fixed));

        $d = $this->diagnostics($id);
        $this->assertSame([$key], array_column($d['orphaned'], 'ack_key'));
        $this->assertSame([], $d['unsigned']);
        $this->assertFalse(_pp_cli_page_fails_site_validation($d));
    }

    public function testAnUnsignedRowWhoseFindingIsGoneIsListedIgnoredAndNeverFails(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->plant($id, [$key => ['acknowledged_at' => '', 'note' => 'planted']]);
        $fixed = pp_get_composition($id);
        unset($fixed[0]['udc']['step-number']);
        $this->assertTrue(pp_update_composition($id, $fixed));

        $d = $this->diagnostics($id);
        $this->assertSame([], $d['orphaned']);
        $this->assertSame([$key], array_column($d['unsigned'], 'ack_key'));
        $this->assertFalse(_pp_cli_page_fails_site_validation($d), 'ignored is never an error state');
    }

    // ── Salt rotation and missing key material ───────────────────────────────────

    public function testRotatingTheSaltsIgnoresEveryRowUntilReacknowledged(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed'));

        $GLOBALS['_pp_test_salts']['auth'] = 'rotated-salt';
        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged'], 'new salts: the old signature no longer verifies');
        $this->assertSame(['invalid'], array_column($d['unsigned'], 'ack_ignored'));
        $this->assertTrue(_pp_cli_page_fails_site_validation($d), 'fail closed: the finding gates again');
        $this->assertStringContainsString("the site's salts changed", $this->checkPageOutput($id));

        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed again'));
        $this->assertSame([$key], array_column($this->diagnostics($id)['acknowledged'], 'ack_key'), 're-acknowledging restores it');
    }

    public function testWithNoUsableKeyMaterialNothingIsAcknowledgedAndTheRefusalIsNamed(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed'));

        $GLOBALS['_pp_test_wp_hash_throws'] = true;
        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged'], 'an unverifiable row reads as missing, never as trusted');
        $this->assertTrue(_pp_cli_page_fails_site_validation($d));
        $this->assertSame(['unverifiable'], array_column($d['unsigned'], 'ack_ignored'),
            'named as a site that cannot check, not as tampering');
        $this->assertStringContainsString('this site cannot check its signature', $this->checkPageOutput($id));

        $result = pp_acknowledge_advisory($id, $key, 'again');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('acknowledgement_unsignable', $result->get_error_code());
    }

    public function testAnOverrideThatReturnsNothingRefusesToWrite(): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        foreach (['', false, null] as $returned) {
            $GLOBALS['_pp_test_wp_hash_returns'] = $returned;
            $result = pp_acknowledge_advisory($id, $key, 'reviewed');
            $this->assertInstanceOf(WP_Error::class, $result, var_export($returned, true));
            $this->assertSame('acknowledgement_unsignable', $result->get_error_code());
            $this->assertSame('', get_post_meta($id, PP_ADVISORY_ACK_META, true), 'nothing was written');
        }
    }

    /** @return array<string, array{callable(string): mixed}> */
    public static function unusableOverrides(): array
    {
        return [
            'a constant'            => [static fn (string $data) => '1'],
            'the unsalted sha256'   => [static fn (string $data) => hash('sha256', $data)],
            'an md5 instead'        => [static fn (string $data) => hash_hmac('md5', $data, 'salt')],
            'uppercase hex'         => [static fn (string $data) => strtoupper(hash_hmac('sha256', $data, 'salt'))],
        ];
    }

    /**
     * wp_hash() is pluggable. An override whose answer is not a salted sha256 HMAC's shape, or
     * is the unsalted digest anyone can compute, makes no signature: the command refuses, and a
     * row planted with that same answer acknowledges nothing.
     *
     * @dataProvider unusableOverrides
     */
    public function testAnOverrideThatIsNotASaltedSha256SignsNothing(callable $override): void
    {
        $id  = $this->page();
        $key = $this->inkKey($id);
        $at  = '2026-10-05T00:00:00+00:00';
        $GLOBALS['_pp_test_wp_hash_override'] = $override;

        $result = pp_acknowledge_advisory($id, $key, 'planted');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('acknowledgement_unsignable', $result->get_error_code());

        $this->plant($id, [$key => ['acknowledged_at' => $at, 'note' => 'planted', 'sig' => $override((string) _pp_advisory_row_envelope($id, $key, 'planted', $at))]]);
        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged'], 'the planted row with the override\'s own answer is not trusted');
        $this->assertSame(['unverifiable'], array_column($d['unsigned'], 'ack_ignored'));
    }

    public function testThePageIsBoundIndependentlyOfTheKey(): void
    {
        // The same key, a different page: only the page in the envelope can tell. Without it
        // the row would verify on page B and read ORPHANED there instead of ignored.
        $a   = $this->page();
        $b   = $this->page();
        $key = $this->inkKey($a);
        $this->assertTrue(pp_acknowledge_advisory($a, $key, 'reviewed'));
        $this->plant($b, [$key => $this->stored($a)[$key]]);

        $d = $this->diagnostics($b);
        $this->assertSame([$key], array_column($d['unsigned'], 'ack_key'));
        $this->assertSame(['invalid'], array_column($d['unsigned'], 'ack_ignored'));
        $this->assertSame([], $d['orphaned']);
    }

    public function testARowCopiedToAnotherSiteOfTheNetworkIsIgnored(): void
    {
        // A multisite network shares one set of salts and post ids repeat per site: the site is
        // in the envelope, so the same post id and key on another site does not verify.
        $id  = $this->page();
        $key = $this->inkKey($id);
        $GLOBALS['_pp_test_blog_id'] = 2;
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'signed on site 2'));
        $this->assertSame([$key], array_column($this->diagnostics($id)['acknowledged'], 'ack_key'), 'premise: valid on its own site');

        $GLOBALS['_pp_test_blog_id'] = 3;
        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged']);
        $this->assertSame(['invalid'], array_column($d['unsigned'], 'ack_ignored'));
    }

    public function testThePlantedNeighbourSurvivesTheDatabaseBackedWriteToo(): void
    {
        // The production branch of the locked reader (a database handle): the same guarantee as
        // the cached branch, neither dropped nor re-signed.
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        $id = pp_create_page('Two findings');
        $this->assertTrue(pp_update_composition($id, [
            $this->ownerBand(),
            array_replace_recursive($this->ownerBand(), ['props' => ['id' => 'steps-two']]),
        ]));
        $keys = array_values(array_filter(array_column($this->diagnostics($id)['smells'], 'ack_key')));
        $this->assertCount(2, $keys);
        $this->plant($id, [$keys[0] => ['acknowledged_at' => 'x', 'note' => 'planted']]);

        $this->assertTrue(pp_acknowledge_advisory($id, $keys[1], 'reviewed'));
        $this->assertSame(['acknowledged_at' => 'x', 'note' => 'planted', 'sig' => ''],
            _pp_normalize_acknowledged_advisories($this->stored($id))[$keys[0]]);
        $this->assertSame([$keys[0]], array_column($this->diagnostics($id)['unsigned'], 'ack_key'));
    }

    // ── The note arm still stands, behind the signature ──────────────────────────

    public function testASignedRowWithABlankNoteIsUnnoted(): void
    {
        // Only reachable if the note rules ever tighten; the note arm must survive the signature.
        $id  = $this->page();
        $key = $this->inkKey($id);
        $sig = _pp_advisory_row_signature($id, $key, "\u{200B}", '');
        $this->assertIsString($sig);
        $this->plant($id, [$key => ['acknowledged_at' => '', 'note' => "\u{200B}", 'sig' => $sig]]);

        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged']);
        $this->assertSame([$key], array_column($d['unnoted'], 'ack_key'));
        $this->assertSame([], $d['unsigned']);
    }

    public function testPageBlindDiagnosticsCarryAnEmptyUnsignedBucket(): void
    {
        $this->assertSame([], _pp_cli_page_diagnostics([$this->ownerBand()])['unsigned']);
    }
}
