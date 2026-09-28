<?php
/**
 * #1194 A2: an acknowledge route for composition advisories (orchestrator ruling D2-D5 = A).
 *
 * Acknowledgement exists for VERIFIED-INTENTIONAL states (judgment calls). A value that does
 * not paint is never intentional, so it never acknowledges. One acknowledgement covers one
 * finding as reported, and it dies with the state it judged: its key embeds a fingerprint of
 * the finding, that band's stored bytes (the whole page for a run smell), the site tokens, udc
 * map, Additional CSS and front-page settings and the theme version, so any change to what was judged re-opens it (STALE); a fixed finding leaves it ORPHANED
 * and inert. Acknowledging refuses a key that is not a currently present finding, so an
 * operator can only acknowledge the exact state `check page` just showed.
 *
 * The four build conditions of the ruling, each pinned below:
 *   (1) the owner's rebuilt-site shape exits 0 once its advisory is acknowledged;
 *   (2) the lifecycle: acknowledge -> edit the band -> stale fails -> restore bytes -> revived;
 *   (3) the gaming probe: changing the acknowledged value MUST go stale;
 *   (4) orphans are reported and never fail.
 */

use PHPUnit\Framework\TestCase;

// THE CLI STUBS FIRST (learning pp-bootstrap-omits-cli-php): this file sorts before every other
// CLI test, and lib/cli.php returns early without WP_CLI, which would leave its classes
// undefined for the whole run. Same guarded shape the CLI suites use.
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
        // #685 registers a before_run_command hook at load time; the stub only
        // needs to accept it — the hook's decision is pinned directly instead.
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

final class AdvisoryAcknowledgementTest extends TestCase
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
        unset($GLOBALS['_pp_test_store']);
        parent::tearDown();
    }

    /**
     * The owner's production shape (#1194): a grid steps band on a dark band background whose
     * step-number ink was measured AA against its default accent fill, plus a responsive value
     * elsewhere on the page (an informational mint note, which never gates).
     */
    private function ownerBand(string $ink = '@color-bg-inverted'): array
    {
        return [
            'component' => 'grid',
            'udc'       => [
                '_band'       => ['background' => ['fill' => '@color-bg-inverted']],
                'step-number' => ['typography' => ['color' => $ink]],
                'heading'     => ['typography' => ['size' => ['d' => '2.5rem', 'p' => '2rem']]],
            ],
            'props'     => ['id' => 'steps', 'layout' => 'steps', 'title' => 'How it works', 'items' => [['number' => '1', 'title' => 'Ask']]],
        ];
    }

    private function page(array $composition): int
    {
        $id = pp_create_page('Acknowledged');
        $this->assertTrue(pp_update_composition($id, $composition), 'the write must land');
        return $id;
    }

    private function rewrite(int $id, array $composition): void
    {
        $this->assertTrue(pp_update_composition($id, $composition));
    }

    private function diagnostics(int $id): array
    {
        return _pp_cli_page_diagnostics(pp_get_composition($id), $id);
    }

    private function inkKey(int $id): string
    {
        foreach ($this->diagnostics($id)['smells'] as $finding) {
            if ($finding['type'] === 'udc_role_ink_over_own_surface') {
                $this->assertArrayHasKey('ack_key', $finding, 'an acknowledgeable finding carries its key');
                return $finding['ack_key'];
            }
        }
        $this->fail('the fixture must raise the ink finding');
    }

    // ── (1) The owner's site exits 0 once its advisory is acknowledged ───────────────

    public function testTheOwnersRebuiltSiteShapePassesOnceTheInkPairIsAcknowledged(): void
    {
        $id = $this->page([$this->ownerBand()]);
        $before = $this->diagnostics($id);
        $this->assertNotSame([], $before['info'], 'the responsive heading mints (a note, never gating)');
        $this->assertTrue(_pp_cli_page_fails_site_validation($before), 'premise: the ink advisory gates');

        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'measured 9.1:1, AA'));

        $after = $this->diagnostics($id);
        $this->assertSame([], $after['smells']);
        $this->assertSame([$key], array_column($after['acknowledged'], 'ack_key'));
        $this->assertSame('measured 9.1:1, AA', $after['acknowledged'][0]['ack_note']);
        $this->assertFalse(_pp_cli_page_fails_site_validation($after), 'a correct, reviewed site exits 0');
    }

    // ── (2) + (3) Lifecycle and the gaming probe ────────────────────────────────────

    public function testChangingTheAcknowledgedValueMakesItStaleAndRestoringTheBytesRevivesIt(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed'));
        $original = pp_get_composition($id);

        // The gaming probe: the operator acknowledged one pair and then changed the ink.
        $changed = $original;
        $changed[0]['udc']['step-number']['typography']['color'] = '#ffffff';
        $this->rewrite($id, $changed);

        $stale = $this->diagnostics($id);
        $this->assertContains('udc_role_ink_over_own_surface', array_column($stale['smells'], 'type'),
            'the changed pair is an unreviewed finding again');
        $this->assertSame([$key], array_column($stale['stale'], 'ack_key'), 'and the old acknowledgement is reported stale');
        $this->assertTrue(_pp_cli_page_fails_site_validation($stale), 'a stale acknowledgement never passes the gate');

        // Restore the exact acknowledged bytes: the same judged state, so it revives (disclosed).
        $this->rewrite($id, $original);
        $revived = $this->diagnostics($id);
        $this->assertSame([$key], array_column($revived['acknowledged'], 'ack_key'));
        $this->assertFalse(_pp_cli_page_fails_site_validation($revived));
    }

    public function testEditingAnotherPropOfTheSameBandAlsoReopensIt(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'reviewed');

        $changed = pp_get_composition($id);
        $changed[0]['props']['title'] = 'How it works now';
        $this->rewrite($id, $changed);

        $this->assertTrue(_pp_cli_page_fails_site_validation($this->diagnostics($id)),
            'the whole band is what was judged; any change to it re-opens the review');
    }

    public function testEditingAnotherBandLeavesTheAcknowledgementStanding(): void
    {
        $id  = $this->page([
            $this->ownerBand(),
            ['component' => 'section', 'props' => ['id' => 'about', 'title' => 'About', 'body' => '<p>One.</p>']],
        ]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'reviewed');

        $changed = pp_get_composition($id);
        $changed[1]['props']['body'] = '<p>Two.</p>';
        $this->rewrite($id, $changed);

        $this->assertSame([$key], array_column($this->diagnostics($id)['acknowledged'], 'ack_key'));
    }

    public function testASiteTokenChangeReopensIt(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'reviewed');

        update_option('pp_token_overrides', ['color-accent' => '#ff00aa']);

        $this->assertNotSame($key, $this->inkKey($id), 'the pair renders differently now');
        $this->assertTrue(_pp_cli_page_fails_site_validation($this->diagnostics($id)));
    }

    public function testASitePresetChangeReopensIt(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $context = pp_advisory_ack_context();

        $saved = pp_execute_action('save_preset', ['name' => 'probe-dark', 'grain' => 'background', 'udc' => ['fill' => '#101828']]);
        $this->assertTrue($saved['ok'], 'premise: preset saved: ' . (string) ($saved['error'] ?? ''));

        $this->assertNotSame($context, pp_advisory_ack_context(), 'a preset is part of what was judged');
        $this->assertNotSame($key, $this->inkKey($id), 'so every key on the site re-opens');
    }

    public function testAThemeUpgradeReopensEveryAcknowledgement(): void
    {
        $id      = $this->page([$this->ownerBand()]);
        $finding = $this->inkFinding($id);
        $composition = pp_get_composition($id);

        $now     = pp_advisory_finding_key($id, $composition, $finding);
        $upgrade = pp_advisory_finding_key($id, $composition, $finding, pp_advisory_ack_context('99.0.0'));

        $this->assertNotNull($now);
        $this->assertNotSame($now, $upgrade, 'an upgrade can change what paints, so it re-opens every review');
    }

    public function testReorderingTheTokenMapDoesNotReopenAnything(): void
    {
        update_option('pp_token_overrides', ['color-accent' => '#3157f4', 'color-bg' => '#ffffff']);
        $before = pp_advisory_ack_context();
        update_option('pp_token_overrides', ['color-bg' => '#ffffff', 'color-accent' => '#3157f4']);

        $this->assertSame($before, pp_advisory_ack_context(), 'key order is not meaning');
    }

    public function testALongNoteIsBoundedWhenStored(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, str_repeat('é', 600));

        $this->assertLessThanOrEqual(PP_ADVISORY_ACK_NOTE_MAX, strlen(pp_acknowledged_advisories($id)[$key]['note']));
        $this->assertTrue(mb_check_encoding(pp_acknowledged_advisories($id)[$key]['note'], 'UTF-8'), 'cut on a character boundary');
    }

    public function testADuplicatedPageDoesNotInheritAcknowledgements(): void
    {
        $a = $this->page([$this->ownerBand()]);
        $b = $this->page([$this->ownerBand()]);
        $composition_b = pp_get_composition($b);
        $composition_b[0] = pp_get_composition($a)[0];
        $this->rewrite($b, $composition_b);

        $this->assertNotSame($this->inkKey($a), $this->inkKey($b), 'the key is scoped to its page');
    }

    // ── (4) Orphans ──────────────────────────────────────────────────────────────

    public function testAFixedFindingLeavesAnOrphanThatNeverFails(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'reviewed');

        $fixed = pp_get_composition($id);
        unset($fixed[0]['udc']['step-number']);
        $this->rewrite($id, $fixed);

        $d = $this->diagnostics($id);
        $this->assertSame([], $d['smells']);
        $this->assertSame([$key], array_column($d['orphaned'], 'ack_key'), 'reported, so it can be removed');
        $this->assertSame([], $d['stale']);
        $this->assertFalse(_pp_cli_page_fails_site_validation($d), 'an orphan is inert');
    }

    // ── What can be acknowledged (D2) and the refusals (D5) ──────────────────────

    public function testTheAcknowledgeableSetIsExactlyTheRuledJudgmentCalls(): void
    {
        $this->assertSame([
            'udc_role_ink_over_own_surface',
            'udc_css_unchecked_property',
            'empty_section',
            'hero_left_no_image',
            'hero_split_no_media',
            'consecutive_compact_spacing',
            'consecutive_narrow_width',
            'consecutive_text_sections',
        ], pp_acknowledgeable_finding_types());
    }

    public function testAValueThatDoesNotPaintIsNeverAcknowledgeable(): void
    {
        $band = $this->ownerBand();
        $band['udc']['_tokens'] = ['orphan' => '17px'];
        $id = $this->page([$band]);

        foreach ($this->diagnostics($id)['smells'] as $finding) {
            if ($finding['type'] === 'udc_unused_band_token') {
                $this->assertArrayNotHasKey('ack_key', $finding);
                return;
            }
        }
        $this->fail('the fixture must raise udc_unused_band_token');
    }

    public function testAcknowledgingAnAbsentKeyIsRefused(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);

        $result = pp_acknowledge_advisory($id, $key . 'x', 'n');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('advisory_not_present', $result->get_error_code());
        $this->assertStringContainsString('wp pp check page --post_id=' . $id, $result->get_error_message());
        $this->assertSame([], pp_acknowledged_advisories($id), 'nothing was written');
    }

    public function testAKeyForAStateThatChangedBeforeTheAcknowledgeIsRefused(): void
    {
        // The CAS: the operator read the key, then the band changed before they acknowledged.
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $changed = pp_get_composition($id);
        $changed[0]['udc']['step-number']['typography']['color'] = '#ffffff';
        $this->rewrite($id, $changed);

        $result = pp_acknowledge_advisory($id, $key, 'n');
        $this->assertInstanceOf(WP_Error::class, $result, 'a state you did not see cannot be acknowledged');
    }

    public function testABandWithNoIdCannotBeAcknowledgedAndTheReasonNamesTheRoute(): void
    {
        // A raw meta write mints no ids (learning pp-raw-meta-mints-nothing). An empty section
        // raises the acknowledgeable `empty_section` smell with nothing to key it on.
        $id = pp_create_page('Raw');
        update_post_meta($id, '_pp_composition', wp_json_encode([['component' => 'section', 'props' => []]]));

        $finding = null;
        foreach (_pp_composition_findings(pp_get_composition($id), $id) as $f) {
            if ($f['type'] === 'empty_section') {
                $finding = $f;
            }
        }
        $this->assertNotNull($finding, 'premise: the raw-written band raises the finding');
        $this->assertNull(pp_advisory_finding_key($id, pp_get_composition($id), $finding));
        $this->assertStringContainsString('update_composition', _pp_advisory_key_or_reason($id, pp_get_composition($id), $finding)['reason']);
    }

    public function testUnacknowledgeReversesAndRefusesAnUnknownKey(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'reviewed');

        $this->assertTrue(pp_unacknowledge_advisory($id, $key));
        $this->assertTrue(_pp_cli_page_fails_site_validation($this->diagnostics($id)), 'it gates again');
        $again = pp_unacknowledge_advisory($id, $key);
        $this->assertInstanceOf(WP_Error::class, $again);
        $this->assertSame('advisory_not_acknowledged', $again->get_error_code());
    }

    public function testUnacknowledgeRemovesAStaleOrOrphanedAcknowledgementToo(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'reviewed');
        $fixed = pp_get_composition($id);
        unset($fixed[0]['udc']['step-number']);
        $this->rewrite($id, $fixed);

        $this->assertTrue(pp_unacknowledge_advisory($id, $key), 'cleanup of an orphan is allowed');
        $this->assertSame([], $this->diagnostics($id)['orphaned']);
    }

    public function testDiagnosticsWithoutAPageHaveNoAcknowledgements(): void
    {
        $d = _pp_cli_page_diagnostics([$this->ownerBand()]);
        $this->assertSame([], $d['acknowledged']);
        $this->assertSame([], $d['stale']);
        $this->assertSame([], $d['orphaned']);
    }

    // ── Run smells judge the page, not the band that completes the run (cycle-1 ruling A) ──

    /** A text-only section: three in a row raise `consecutive_text_sections` on the third. */
    private function textBand(string $id, string $body = '<p>Text.</p>'): array
    {
        return ['component' => 'section', 'props' => ['id' => $id, 'title' => ucfirst($id), 'body' => $body]];
    }

    private function runKey(int $id): string
    {
        foreach ($this->diagnostics($id)['smells'] as $finding) {
            if ($finding['type'] === 'consecutive_text_sections') {
                $this->assertArrayHasKey('ack_key', $finding, 'a run smell is acknowledgeable');
                return $finding['ack_key'];
            }
        }
        $this->fail('the fixture must raise consecutive_text_sections');
    }

    /** Acknowledge the run [a,b,c], rewrite the page, and return the diagnostics after. */
    private function ackRunThenRewrite(callable $edit): array
    {
        $id  = $this->page([$this->textBand('a'), $this->textBand('b'), $this->textBand('c')]);
        $key = $this->runKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'a deliberate essay page'));
        $this->assertFalse(_pp_cli_page_fails_site_validation($this->diagnostics($id)), 'premise: acknowledged');

        $this->rewrite($id, $edit(pp_get_composition($id)));
        $d = $this->diagnostics($id);
        $this->assertContains('consecutive_text_sections', array_column($d['smells'], 'type'), 'the run is judged again');
        $this->assertSame([$key], array_column($d['stale'], 'ack_key'), 'the old acknowledgement is stale');
        $this->assertTrue(_pp_cli_page_fails_site_validation($d), 'and it never passes the gate');
        return $d;
    }

    public function testGrowingAnAcknowledgedRunReopensIt(): void
    {
        // The reproduced gaming vector: [a,b,c] acknowledged, then [a',b',c,d,e] kept passing.
        $this->ackRunThenRewrite(function (array $c): array {
            $c[0]['props']['body'] = '<p>Rewritten.</p>';
            $c[1]['props']['body'] = '<p>Rewritten too.</p>';
            $c[] = $this->textBand('d');
            $c[] = $this->textBand('e');
            return $c;
        });
    }

    public function testReplacingAnEarlierBandOfAnAcknowledgedRunReopensIt(): void
    {
        $this->ackRunThenRewrite(function (array $c): array {
            $c[0] = $this->textBand('z', '<p>A different first band.</p>');
            return $c;
        });
    }

    public function testReorderingAnAcknowledgedRunReopensIt(): void
    {
        $this->ackRunThenRewrite(function (array $c): array {
            [$c[0], $c[1]] = [$c[1], $c[0]];
            return $c;
        });
    }

    public function testASingleBandFindingStillIgnoresEditsToOtherBands(): void
    {
        // The page scope is for the three run types only; the other six stay band-scoped
        // (testEditingAnotherBandLeavesTheAcknowledgementStanding is the positive pin).
        $id = $this->page([$this->ownerBand(), $this->textBand('about')]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'reviewed');
        $changed = pp_get_composition($id);
        [$changed[0], $changed[1]] = [$changed[1], $changed[0]];
        $this->rewrite($id, $changed);

        $this->assertSame([$key], array_column($this->diagnostics($id)['acknowledged'], 'ack_key'));
    }

    // ── Fingerprinting fails closed (cycle-1 ruling A) ─────────────────────────────

    public function testAFindingWhoseBandCannotBeFingerprintedHasNoKeyAndKeepsGating(): void
    {
        // A raw meta write is the only way a non-finite number reaches a stored band: JSON
        // `1e999` decodes to INF, which wp_json_encode() cannot encode. The fingerprint must
        // not collapse to the hash of an empty string (a key bound to nothing).
        $id = pp_create_page('Raw');
        update_post_meta($id, '_pp_composition',
            '[{"component":"section","id":"pp-inf00001","props":{"id":"empty","columns":1e999}}]');
        $composition = pp_get_composition($id);
        $this->assertTrue(is_infinite($composition[0]['props']['columns']), 'premise: INF is stored');

        $finding = null;
        foreach (_pp_composition_findings($composition, $id) as $f) {
            if ($f['type'] === 'empty_section') {
                $finding = $f;
            }
        }
        $this->assertNotNull($finding, 'premise: the band raises an acknowledgeable finding');
        $this->assertNull(pp_advisory_finding_key($id, $composition, $finding), 'no key: nothing stable to bind to');
        $this->assertStringContainsString('cannot be encoded exactly', (string) _pp_advisory_key_or_reason($id, $composition, $finding)['reason']);

        $d = $this->diagnostics($id);
        $this->assertContains('empty_section', array_column($d['smells'], 'type'), 'it keeps gating');
        $this->assertTrue(_pp_cli_page_fails_site_validation($d));
    }

    public function testAnUnencodableSiteContextMatchesNoStoredKey(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'reviewed'));

        update_option('pp_token_overrides', ['color-accent' => INF]);

        $d = $this->diagnostics($id);
        foreach ($d['smells'] as $finding) {
            // A constant stand-in digest would let a fresh acknowledgement survive every later
            // token edit that stays unencodable: the context must bind nothing, so no key.
            $this->assertArrayNotHasKey('ack_key', $finding, 'no key can be minted against an unencodable context');
        }
        $this->assertSame([], $d['acknowledged'], 'an unencodable context never matches');
        $this->assertTrue(_pp_cli_page_fails_site_validation($d), 'so the finding gates');
        $this->assertInstanceOf(WP_Error::class, pp_acknowledge_advisory($id, $key, 'again'),
            'and nothing can be acknowledged against it');
    }

    public function testTheKeyCarries128BitsOfFingerprint(): void
    {
        $id = $this->page([$this->ownerBand()]);
        $this->assertMatchesRegularExpression('/^udc_role_ink_over_own_surface:[A-Za-z0-9_-]{1,64}:[0-9a-f]{32}\z/', $this->inkKey($id));
    }

    // ── The lock reads the database, not the object cache (#113/#200) ─────────────

    public function testAcknowledgingReadsTheStoredAcknowledgementsUncached(): void
    {
        // The staged row stands for the database as another process left it. The harness freezes
        // a staged row (writes land in the cache store only), so it is consumed by the one read
        // it models: the write's own read-back then sees what production would, the new row.
        $GLOBALS['wpdb'] = new class extends PP_Lockable_Wpdb {
            public function get_var(string $query)
            {
                $value = parent::get_var($query);
                if (str_contains($query, PP_ADVISORY_ACK_META)) {
                    foreach ($GLOBALS['_pp_test_store']['wpdb_postmeta'] ?? [] as $pid => $row) {
                        unset($GLOBALS['_pp_test_store']['wpdb_postmeta'][$pid][PP_ADVISORY_ACK_META]);
                    }
                }
                return $value;
            }
        };
        try {
            $id  = $this->page([$this->ownerBand(), $this->textBand('a'), $this->textBand('b'), $this->textBand('c')]);
            $ink = $this->inkKey($id);
            $run = $this->runKey($id);

            // Another process acknowledged the run; this process's cache has not seen it.
            $GLOBALS['_pp_test_store']['wpdb_postmeta'][$id][PP_ADVISORY_ACK_META] =
                maybe_serialize([$run => ['acknowledged_at' => '2026-09-28T00:00:00+00:00', 'note' => 'theirs']]);

            $this->assertTrue(pp_acknowledge_advisory($id, $ink, 'mine'));
            $stored = get_post_meta($id, PP_ADVISORY_ACK_META, true);
            $this->assertArrayHasKey($run, $stored, 'the other process\'s acknowledgement survives (no lost update)');
            $this->assertArrayHasKey($ink, $stored);
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function testAcknowledgingChecksTheKeyAgainstTheStoredCompositionUncached(): void
    {
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
        try {
            $id  = $this->page([$this->ownerBand()]);
            $key = $this->inkKey($id);

            // Another process changed the ink; this process's cache still holds the old page.
            $changed = pp_get_composition($id);
            $changed[0]['udc']['step-number']['typography']['color'] = '#ffffff';
            $GLOBALS['_pp_test_store']['wpdb_postmeta'][$id]['_pp_composition'] = wp_json_encode($changed);

            $this->assertInstanceOf(WP_Error::class, pp_acknowledge_advisory($id, $key, 'n'),
                'the key is checked against what is stored now, not a copy cached before the lock');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function testAFailedAuthoritativeReadRefusesRatherThanOverwrite(): void
    {
        $GLOBALS['wpdb'] = new class extends PP_Lockable_Wpdb {
            public string $last_error = '';
            public function get_var(string $query)
            {
                $this->last_error = '';
                if (str_contains($query, PP_ADVISORY_ACK_META)) {
                    $this->last_error = 'MySQL server has gone away';
                    return null;
                }
                return parent::get_var($query);
            }
        };
        try {
            $id  = $this->page([$this->ownerBand()]);
            $key = $this->inkKey($id);
            update_post_meta($id, PP_ADVISORY_ACK_META, ['kept:x:' . str_repeat('0', 32) => ['acknowledged_at' => '', 'note' => '']]);

            $result = pp_acknowledge_advisory($id, $key, 'n');
            $this->assertInstanceOf(WP_Error::class, $result, 'an unreadable store is not an empty one');
            $this->assertSame(['kept:x:' . str_repeat('0', 32)], array_keys(get_post_meta($id, PP_ADVISORY_ACK_META, true)), 'nothing was overwritten');
            $this->assertInstanceOf(WP_Error::class, pp_unacknowledge_advisory($id, 'kept:x:' . str_repeat('0', 32)));
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    // ── Cycle-2 review fixes ───────────────────────────────────────────────────────

    public function testTheFingerprintNeverUsesTheLossyEncoder(): void
    {
        // Real wp_json_encode() does not fail on invalid UTF-8: it replaces each bad byte
        // with '?' and returns a string, so "a\xFF" and "a?" fingerprint alike and a raw
        // write can change the judged bytes under an old acknowledgement. The unit stub
        // returns false instead, which would hide it, so the rule is pinned at the source:
        // no call to wp_json_encode() anywhere in the acknowledgement section.
        $src   = file_get_contents(dirname(__DIR__) . '/lib/operate.php');
        $start = strpos($src, '// ── Composition-advisory acknowledgement (#1194 A2)');
        $this->assertNotFalse($start, 'premise: the section marker exists');
        $tokens = token_get_all('<?php ' . substr($src, $start));
        $calls  = 0;
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'wp_json_encode') {
                for ($j = $i + 1; isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
                if (($tokens[$j] ?? null) === '(') {
                    $calls++;
                }
            }
        }
        $this->assertSame(0, $calls, 'fingerprints use strict json_encode, which fails on invalid UTF-8');
    }

    public function testInvalidUtf8InABandMintsNoKey(): void
    {
        $band = $this->ownerBand();
        $band['id'] = 'pp-abcd0001';
        $band['props']['title'] = "Is it\xff";
        $id = pp_create_page('Raw');
        update_post_meta($id, '_pp_composition', [$band]);

        $d = $this->diagnostics($id);
        $this->assertContains('udc_role_ink_over_own_surface', array_column($d['smells'], 'type'), 'premise: it raises');
        foreach ($d['smells'] as $finding) {
            $this->assertArrayNotHasKey('ack_key', $finding, 'bytes that cannot be encoded exactly bind nothing');
        }
    }

    public function testARunSmellOnAPageThatCannotBeFingerprintedMintsNoKey(): void
    {
        // The run bands encode fine; another band on the page does not. A run smell judges the
        // whole page, so it must not key on a page digest of nothing.
        $id = pp_create_page('Raw');
        update_post_meta($id, '_pp_composition', '[{"component":"section","id":"pp-a0000001","props":{"id":"a","title":"A","body":"<p>A</p>"}},'
            . '{"component":"section","id":"pp-b0000001","props":{"id":"b","title":"B","body":"<p>B</p>"}},'
            . '{"component":"section","id":"pp-c0000001","props":{"id":"c","title":"C","body":"<p>C</p>"}},'
            . '{"component":"stats","id":"pp-d0000001","props":{"id":"d","columns":1e999}}]');

        $run = null;
        foreach ($this->diagnostics($id)['smells'] as $finding) {
            if ($finding['type'] === 'consecutive_text_sections') {
                $run = $finding;
            }
        }
        $this->assertNotNull($run, 'premise: the run is raised');
        $this->assertArrayNotHasKey('ack_key', $run);
    }

    public function testANoteKeepsItsBackslashesAcrossLaterWrites(): void
    {
        $id  = $this->page([$this->ownerBand(), $this->textBand('a'), $this->textBand('b'), $this->textBand('c')]);
        $ink = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $ink, 'C:\\x ratio 9\\1'));
        $this->assertTrue(pp_acknowledge_advisory($id, $this->runKey($id), 'n'), 'a later write rewrites the whole map');

        $this->assertSame('C:\\x ratio 9\\1', pp_acknowledged_advisories($id)[$ink]['note']);
    }

    public function testAFailedAcknowledgeWriteIsRefusedNotReportedAsDone(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $GLOBALS['_pp_test_unwritable_meta'][PP_ADVISORY_ACK_META] = true;
        try {
            $this->assertInstanceOf(WP_Error::class, pp_acknowledge_advisory($id, $key, 'n'));
        } finally {
            unset($GLOBALS['_pp_test_unwritable_meta']);
        }
        $this->assertTrue(_pp_cli_page_fails_site_validation($this->diagnostics($id)), 'nothing was acknowledged');
    }

    public function testAFailedUnacknowledgeWriteIsRefusedNotReportedAsRemoved(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, 'n'));
        $GLOBALS['_pp_test_unwritable_meta'][PP_ADVISORY_ACK_META] = true;
        try {
            $result = pp_unacknowledge_advisory($id, $key);
        } finally {
            unset($GLOBALS['_pp_test_unwritable_meta']);
        }
        $this->assertInstanceOf(WP_Error::class, $result, 'a removal that did not happen is not reported as done');
    }

    public function testTheSiteContextIsReadUncachedInsideTheLock(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);

        // Another process changed the tokens; this process's option cache still holds the old ones.
        $GLOBALS['wpdb'] = new class extends PP_Lockable_Wpdb {
            public function get_var(string $query)
            {
                if (str_contains($query, "option_name = 'pp_token_overrides'")) {
                    return maybe_serialize(['color-accent' => '#ff00aa']);
                }
                return parent::get_var($query);
            }
        };
        try {
            $this->assertInstanceOf(WP_Error::class, pp_acknowledge_advisory($id, $key, 'n'),
                'the key is checked against the tokens stored now, not a copy cached before the lock');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function testReorderingThePresetMapReopensAcknowledgements(): void
    {
        // Preset map order can decide which placement paints (_pp_udc_place: a later placement
        // overwrites an earlier one), so it is NOT canonicalised: stricter, never looser.
        foreach (['alpha', 'beta'] as $name) {
            $saved = pp_execute_action('save_preset', ['name' => $name, 'grain' => 'background', 'udc' => ['fill' => '#101828']]);
            $this->assertTrue($saved['ok'], 'premise: preset saved');
        }
        $before = pp_advisory_ack_context();
        $raw = json_decode((string) get_option(PP_SITE_UDC_OPTION, ''), true);
        $this->assertIsArray($raw['_presets'] ?? null, 'premise: presets live under _presets');
        $raw['_presets'] = array_reverse($raw['_presets'], true);
        update_option(PP_SITE_UDC_OPTION, wp_json_encode($raw));
        $this->assertSame(array_reverse(array_keys(pp_udc_custom_presets())), ['alpha', 'beta'], 'premise: reordered');

        $this->assertNotSame($before, pp_advisory_ack_context());
    }

    public function testBecomingThePostsPageReopensIt(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        update_option('show_on_front', 'page');
        update_option('page_for_posts', $id);

        $this->assertNotSame($key, $this->inkKey($id), 'the posts page renders a listing band differently');
    }

    public function testMovingWhereARunCompletesReportsTheAcknowledgementStaleNotOrphaned(): void
    {
        $id  = $this->page([$this->textBand('a'), $this->textBand('b'), $this->textBand('c')]);
        $key = $this->runKey($id);
        pp_acknowledge_advisory($id, $key, 'n');
        $changed = pp_get_composition($id);
        array_unshift($changed, $this->textBand('x'));   // the run now completes on b, not c
        $this->rewrite($id, $changed);

        $d = $this->diagnostics($id);
        $this->assertSame([$key], array_column($d['stale'], 'ack_key'), 'the run is still there, so it is stale');
        $this->assertSame([], $d['orphaned']);
    }

    public function testAnAcknowledgementWhoseFindingIsPresentButKeylessIsStaleNotOrphaned(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'n');
        update_option('pp_token_overrides', ['color-accent' => INF]);

        $d = $this->diagnostics($id);
        $this->assertSame([$key], array_column($d['stale'], 'ack_key'), 'the finding is present; "remove it" would be wrong');
        $this->assertSame([], $d['orphaned']);
    }

    public function testTheOrphanHintIsARunnableCommand(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'n');
        $fixed = pp_get_composition($id);
        unset($fixed[0]['udc']['step-number']);
        $this->rewrite($id, $fixed);

        WP_CLI::$lines = [];
        (new PP_Check_Command())->page([], ['post_id' => (string) $id]);
        $this->assertStringContainsString('wp pp check unacknowledge --post_id=' . $id . ' --key=' . $key, implode("\n", WP_CLI::$lines));
    }

    public function testCheckPageSaysWhyAFindingHasNoKey(): void
    {
        $id = pp_create_page('Raw');
        update_post_meta($id, '_pp_composition', wp_json_encode([['component' => 'section', 'props' => []]]));

        WP_CLI::$lines = [];
        (new PP_Check_Command())->page([], ['post_id' => (string) $id]);
        $this->assertStringContainsString('nothing stable to belong to', implode("\n", WP_CLI::$lines),
            'the reason (and its update_composition route) reaches the operator');
    }

    // ── Cycle-2 ruling (Q1-Q4) ─────────────────────────────────────────────────────

    // ── Cycle-3 ruling B: the scrim judgment is not acknowledgeable in 2.0.1 (#1211) ──

    public function testTheScrimJudgmentIsNotAcknowledgeable(): void
    {
        // Four review cycles found four ways the photo behind a scrim escaped a hand-enumerated
        // fingerprint (attachment alias, token reference, file contents, CSS url()). Until the key
        // reads the same compiled truth the finding reads (#1211), the finding keeps gating.
        $GLOBALS['_pp_test_store']['posts'][9001]               = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][9001] = true;
        // A real, readable file behind the id, so nothing but the descope keeps the key away.
        $photo = tempnam(sys_get_temp_dir(), 'pp-ack-photo');
        file_put_contents($photo, 'photo A');
        $GLOBALS['_pp_test_store']['attached_file'][9001] = $photo;
        $id = $this->page([[
            'component' => 'cta',
            'udc'       => ['_band' => ['background' => ['image' => 9001, 'overlay' => 'rgba(255,255,255,0.8)']]],
            'props'     => ['id' => 'offer', 'title' => 'C', 'title_accent' => 'A', 'button_text' => 'Go', 'button_url' => '/x'],
        ]]);

        $scrim = null;
        foreach ($this->diagnostics($id)['smells'] as $finding) {
            if ($finding['type'] === 'udc_overlay_accent_off_scrim') {
                $scrim = $finding;
            }
        }
        $this->assertNotNull($scrim, 'premise: the scrim judgment is raised');
        $this->assertArrayNotHasKey('ack_key', $scrim);
        $this->assertTrue(_pp_cli_page_fails_site_validation($this->diagnostics($id)), 'it keeps gating');
        @unlink($photo);
    }

    public function testARowWrittenStraightIntoMetaWithoutANoteIsNotAnAcknowledgement(): void
    {
        // The key is deterministic and printed by check page, so a raw meta write can plant it.
        // The command refuses an empty note; the gate must too, or the refusal is decoration.
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        update_post_meta($id, PP_ADVISORY_ACK_META, [$key => ['acknowledged_at' => '2026-09-28T00:00:00+00:00', 'note' => '  ']]);

        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged'], 'a row with no reason acknowledges nothing');
        $this->assertTrue(_pp_cli_page_fails_site_validation($d));
        $this->assertSame([$key], array_column($d['unnoted'], 'ack_key'), 'and it is reported, so it can be removed');
        $this->assertTrue(pp_unacknowledge_advisory($id, $key), 'the cleanup route removes it');
    }

    public function testAFailedLockedTokenReadRefuses(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $GLOBALS['wpdb'] = new class extends PP_Lockable_Wpdb {
            public string $last_error = '';
            public function get_var(string $query)
            {
                $this->last_error = '';
                if (str_contains($query, "option_name = 'pp_token_overrides'")) {
                    $this->last_error = 'Lock wait timeout exceeded';
                    return null;
                }
                return parent::get_var($query);
            }
        };
        try {
            $result = pp_acknowledge_advisory($id, $key, 'n');
            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('acknowledgements_unreadable', $result->get_error_code());
        } finally {
            unset($GLOBALS['wpdb']);
        }
        $this->assertSame([], pp_acknowledged_advisories($id), 'nothing was written');
    }

    public function testAcknowledgeReadsAdditionalCssThroughTheSameFunctionAsTheGate(): void
    {
        // ONE TRUTH, ONE READER (focused-cycle ruling, fix 1). A raw "newest published custom_css
        // row" differs from wp_get_custom_css() (theme_mod resolution plus its filter) whenever an
        // import or a filter is involved; two readers made every acknowledgement on such a site
        // impossible. The database here holds a different row than the gate's function returns.
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $GLOBALS['wpdb'] = new class extends PP_Lockable_Wpdb {
            public string $posts = 'wp_posts';
            public function get_var(string $query)
            {
                if (str_contains($query, "custom_css")) {
                    return '/* a stray row the gate never reads */';
                }
                return parent::get_var($query);
            }
        };
        try {
            $this->assertTrue(pp_acknowledge_advisory($id, $key, 'n'), 'the key check page printed is acknowledgeable');
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function testANoteThatIsBlankAfterTheCutIsRefused(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        foreach ([str_repeat(' ', PP_ADVISORY_ACK_NOTE_MAX) . 'measured 9:1', "\u{00A0}\u{200B}\u{FEFF}"] as $note) {
            $result = pp_acknowledge_advisory($id, $key, $note);
            $this->assertInstanceOf(WP_Error::class, $result, 'a note that reads as nothing is no note');
            $this->assertSame('acknowledgement_note_required', $result->get_error_code());
        }
        $this->assertSame([], pp_acknowledged_advisories($id));
    }

    public function testAStoredRowWithAnInvisibleNoteIsUnnoted(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        update_post_meta($id, PP_ADVISORY_ACK_META, [$key => ['acknowledged_at' => '', 'note' => "\u{200B}\u{00A0}"]]);

        $d = $this->diagnostics($id);
        $this->assertSame([], $d['acknowledged']);
        $this->assertSame([$key], array_column($d['unnoted'], 'ack_key'));
    }

    public function testTheLeadingWhitespaceOfANoteIsNotStored(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $this->assertTrue(pp_acknowledge_advisory($id, $key, "  \u{00A0}measured 9:1  "));
        $this->assertSame('measured 9:1', pp_acknowledged_advisories($id)[$key]['note']);
    }

    public function testThePostsPageSettingIsReadUncachedInsideTheLock(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        // Another process made this page the posts page; this process's option cache has not seen it.
        $GLOBALS['wpdb'] = new class($id) extends PP_Lockable_Wpdb {
            public function __construct(private int $pid) {}
            public function get_var(string $query)
            {
                if (str_contains($query, "option_name = 'show_on_front'")) {
                    return 'page';
                }
                if (str_contains($query, "option_name = 'page_for_posts'")) {
                    return (string) $this->pid;
                }
                return parent::get_var($query);
            }
        };
        try {
            $this->assertInstanceOf(WP_Error::class, pp_acknowledge_advisory($id, $key, 'n'));
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function testAnIntegerAndItsFloatAreDifferentBytes(): void
    {
        $id = pp_create_page('Raw');
        $grid = '{"component":"grid","id":"pp-g0000001","props":{"id":"g","columns":%s,"items":[{"title":"A"}]}}';
        $run  = '{"component":"section","id":"pp-a0000001","props":{"id":"a","title":"A","body":"<p>A</p>"}},'
            . '{"component":"section","id":"pp-b0000001","props":{"id":"b","title":"B","body":"<p>B</p>"}},'
            . '{"component":"section","id":"pp-c0000001","props":{"id":"c","title":"C","body":"<p>C</p>"}}';
        update_post_meta($id, '_pp_composition', '[' . sprintf($grid, '2') . ',' . $run . ']');
        $before = $this->runKey($id);
        update_post_meta($id, '_pp_composition', '[' . sprintf($grid, '2.0') . ',' . $run . ']');

        $this->assertNotSame($before, $this->runKey($id), 'the grid renders 2 and 2.0 differently');
    }

    public function testTheChromeMapIsPartOfTheSiteContext(): void
    {
        $before = pp_advisory_ack_context();
        update_option(PP_SITE_UDC_OPTION, wp_json_encode(['nav' => ['link' => ['_css' => 'margin-bottom: -40px']]]));
        $this->assertNotSame($before, pp_advisory_ack_context(), 'chrome _css can reach a band, like Additional CSS');
    }

    public function testASelfReferencingTokenMapFailsClosedInsteadOfRecursing(): void
    {
        // Only a raw write (an unserialized PHP reference) can store this; the canonicaliser
        // must give up and mint no key, not recurse until the process dies.
        $id    = $this->page([$this->ownerBand()]);
        $loop  = ['color-accent' => '#3157f4'];
        $loop['self'] = &$loop;
        $GLOBALS['_pp_test_store']['options']['pp_token_overrides'] = $loop;

        $this->assertSame('', pp_advisory_ack_context());
        foreach ($this->diagnostics($id)['smells'] as $finding) {
            $this->assertArrayNotHasKey('ack_key', $finding);
        }
        unset($GLOBALS['_pp_test_store']['options']['pp_token_overrides']);
    }

    public function testCheckPagePrintsTheIgnoredRowWithItsRemoval(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        update_post_meta($id, PP_ADVISORY_ACK_META, [$key => ['acknowledged_at' => '', 'note' => '']]);

        WP_CLI::$lines = [];
        (new PP_Check_Command())->page([], ['post_id' => (string) $id]);
        $out = implode("\n", WP_CLI::$lines);
        $this->assertStringContainsString('ignored acknowledgement ' . $key, $out);
        $this->assertStringContainsString('wp pp check unacknowledge --post_id=' . $id . ' --key=' . $key, $out);
    }

    public function testTheOrphanLineSaysItCanCountAgain(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        pp_acknowledge_advisory($id, $key, 'n');
        $fixed = pp_get_composition($id);
        unset($fixed[0]['udc']['step-number']);
        $this->rewrite($id, $fixed);

        WP_CLI::$lines = [];
        (new PP_Check_Command())->page([], ['post_id' => (string) $id]);
        $this->assertStringContainsString('it counts again if these exact bytes return', implode("\n", WP_CLI::$lines));
    }

    public function testAFailedLockedPresetReadRefuses(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        $GLOBALS['wpdb'] = new class extends PP_Lockable_Wpdb {
            public string $last_error = '';
            public function get_var(string $query)
            {
                $this->last_error = '';
                if (str_contains($query, "option_name = 'pp_site_udc'")) {
                    $this->last_error = 'Lock wait timeout exceeded';
                    return null;
                }
                return parent::get_var($query);
            }
        };
        try {
            $result = pp_acknowledge_advisory($id, $key, 'n');
            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('acknowledgements_unreadable', $result->get_error_code());
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function testTheNoKeyReasonNamesTheRealCause(): void
    {
        $id = $this->page([$this->ownerBand()]);
        update_option('pp_token_overrides', ['color-accent' => INF]);
        $reason = null;
        foreach ($this->diagnostics($id)['smells'] as $finding) {
            if ($finding['type'] === 'udc_role_ink_over_own_surface') {
                $reason = $finding['ack_unkeyable'] ?? null;
            }
        }
        $this->assertIsString($reason);
        $this->assertStringContainsString('site', $reason, 'the cause is the site context, not this band');
        $this->assertStringNotContainsString('this band holds', $reason);
    }

    public function testAdditionalCssIsPartOfTheSiteContext(): void
    {
        $before = pp_advisory_ack_context();
        $GLOBALS['_pp_test_store']['custom_css'] = '[data-pp-band] .cta__title-accent { color: #fff; }';
        $this->assertNotSame($before, pp_advisory_ack_context(), 'Additional CSS changes what paints');
    }

    public function testANoteIsRequiredAndTheRefusalSaysWhatItIsFor(): void
    {
        $id  = $this->page([$this->ownerBand()]);
        $key = $this->inkKey($id);
        foreach (['', "  \t "] as $note) {
            $result = pp_acknowledge_advisory($id, $key, $note);
            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertSame('acknowledgement_note_required', $result->get_error_code());
            $this->assertStringContainsString('record why this state is intentional', $result->get_error_message());
        }
        $this->assertSame([], pp_acknowledged_advisories($id), 'nothing was written');
    }

    // ── helpers ───────────────────────────────────────────────────────────────────

    private function inkFinding(int $id): array
    {
        foreach (_pp_composition_findings(pp_get_composition($id), $id) as $finding) {
            if ($finding['type'] === 'udc_role_ink_over_own_surface') {
                return $finding;
            }
        }
        $this->fail('the fixture must raise the ink finding');
    }
}
