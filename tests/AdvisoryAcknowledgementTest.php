<?php
/**
 * #1194 A2: an acknowledge route for composition advisories (orchestrator ruling D2-D5 = A).
 *
 * Acknowledgement exists for VERIFIED-INTENTIONAL states (judgment calls). A value that does
 * not paint is never intentional, so it never acknowledges. One acknowledgement covers one
 * finding as reported, and it dies with the state it judged: its key embeds a fingerprint of
 * the finding, that band's stored bytes, the site tokens and presets and the theme version,
 * so any change to what was judged re-opens it (STALE); a fixed finding leaves it ORPHANED
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
            'udc_overlay_accent_off_scrim',
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
        $this->assertStringContainsString('update_composition', pp_advisory_unkeyable_reason(pp_get_composition($id), $finding));
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
