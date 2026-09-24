<?php
/**
 * tests/ComponentUdcParamTest.php — a band's `udc` map is writable one band at a time (#1088).
 *
 * THE GAP. `update_component` and `add_component` declared no `udc` param, and
 * pp_validate_action() walks the DECLARED set only — so the only verbs that could carry a
 * band's design were update_composition and create_page, both of which take the WHOLE page.
 * The revise-a-section playbook tells an agent to touch only the target band; followed
 * literally, a model could not restyle a v2 band at all, and the workaround widened every
 * styling edit's blast radius to the page (and made an unrelated concurrent edit a conflict).
 *
 * THE RULING (orchestrator 7A, D1/D2, 2026-09-24):
 *   update_component.udc  SHALLOW MERGE BY ROLE — the documented `props` rule ("shallow
 *                         merge, null removes") applied to the udc key. A role you send
 *                         replaces that role's map; `null` removes the role; roles you do not
 *                         send are kept as stored.
 *   add_component.udc     SET — the band is new, so the map is simply its map.
 *   `props` stops being registry-required on update_component; a call carrying none of
 *   props / udc / style is refused with `missing_component_update` (the registry's
 *   "Either X or Y is required" family: missing_component_target, missing_style, …).
 *   CAS, band-scoped validation (#1007) and write-time minting are unchanged.
 *
 * ONE CONSEQUENCE THE RULING DID NOT HAVE TO NAME, pinned here because it is where a naive
 * merge breaks. Write-time minting rewrites a responsive literal into `@<role>-…-<bp>` and
 * stores the value under the band's `_tokens`. Replacing that role orphans the token, and
 * the engine refuses an unreferenced mint-shaped name as an author squatting its namespace
 * (measured on wp-env before this was written: the whole write refused with
 * invalid_prop_value naming a token the author never typed). So the merge prunes exactly the
 * tokens the ENGINE minted in the stored map that the merged map no longer references — and
 * nothing else: a patch that sends its own `_tokens` owns them.
 *
 * Every write below goes through pp_execute_action(), the real authoring surface (rule 14.1).
 */

use PHPUnit\Framework\TestCase;

final class ComponentUdcParamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** A two-band page: band 0 styled on two roles, band 1 unstyled. */
    private function page(): int
    {
        $id = pp_create_page('udc reach', 'draft');
        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [
                [
                    'component' => 'section',
                    'udc'       => [
                        '_band'   => ['background' => ['fill' => '#101828']],
                        'heading' => ['typography' => ['color' => '#ffffff', 'weight' => '700']],
                    ],
                    'props'     => ['title' => 'Styled', 'body' => 'b'],
                ],
                ['component' => 'section', 'props' => ['title' => 'Plain', 'body' => 'b']],
            ],
        ]);
        $this->assertTrue($result['ok'], 'premise: ' . ($result['error'] ?? ''));
        return $id;
    }

    private function band(int $post_id, int $index): array
    {
        return pp_get_composition($post_id)[$index];
    }

    private function update(array $params): array
    {
        return pp_execute_action('update_component', $params);
    }

    // ── The declared surface ─────────────────────────────────────────────────

    public function testBothVerbsDeclareUdcAndUpdateComponentNoLongerRequiresProps(): void
    {
        $update = pp_get_action('update_component');
        $add    = pp_get_action('add_component');

        $this->assertSame(['type' => 'array', 'required' => false], $update['params']['udc'] ?? null);
        $this->assertSame(['type' => 'array', 'required' => false], $add['params']['udc'] ?? null);
        $this->assertFalse($update['params']['props']['required'],
            'a udc-only or style-only edit must not be refused for a missing props');
        $this->assertTrue($add['params']['props']['required'],
            'add_component still needs the new band\'s content');
    }

    // ── update_component: shallow merge by role ──────────────────────────────

    public function testAUdcOnlyEditRestylesOneBandWithoutResendingThePage(): void
    {
        $id = $this->page();

        $result = $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['color' => '#101828']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(['heading' => ['typography' => ['color' => '#101828']]], $this->band($id, 1)['udc']);
        $this->assertSame('Plain', $this->band($id, 1)['props']['title'], 'props untouched');
    }

    public function testASentRoleReplacesThatRoleAndUnsentRolesAreKept(): void
    {
        $id = $this->page();

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['heading' => ['typography' => ['color' => '#ffd166']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $udc = $this->band($id, 0)['udc'];
        $this->assertSame(['typography' => ['color' => '#ffd166']], $udc['heading'],
            'the sent role REPLACES its map: the stored weight is gone, not merged under');
        $this->assertSame(['background' => ['fill' => '#101828']], $udc['_band'], 'the unsent role is kept');
    }

    public function testNullRemovesARoleAndTheLastRoleRemovedLeavesNoMap(): void
    {
        $id = $this->page();

        $one = $this->update(['post_id' => $id, 'component_index' => 0, 'udc' => ['heading' => null]]);
        $this->assertTrue($one['ok'], (string) ($one['error'] ?? ''));
        $this->assertSame(['_band' => ['background' => ['fill' => '#101828']]], $this->band($id, 0)['udc']);

        $two = $this->update(['post_id' => $id, 'component_index' => 0, 'udc' => ['_band' => null]]);
        $this->assertTrue($two['ok'], (string) ($two['error'] ?? ''));
        $this->assertArrayNotHasKey('udc', $this->band($id, 0), 'an emptied map is removed, not stored as {}');
    }

    public function testAPropsAndUdcEditLandsBothInOneWrite(): void
    {
        $id = $this->page();
        $before = (int) pp_get_composition_marker($id)['version'];

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'props'   => ['title' => 'Both'],
            'udc'     => ['eyebrow' => ['typography' => ['color' => '#ffffff']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame('Both', $this->band($id, 0)['props']['title']);
        $this->assertSame(['typography' => ['color' => '#ffffff']], $this->band($id, 0)['udc']['eyebrow']);
        $this->assertSame($before + 1, (int) pp_get_composition_marker($id)['version'], 'one write, one version');
    }

    // ── The refusal, and each single-payload form passing ────────────────────

    public function testACallCarryingNoneOfPropsUdcOrStyleIsRefusedByName(): void
    {
        $id = $this->page();

        $result = $this->update(['post_id' => $id, 'component_index' => 0]);

        $this->assertFalse($result['ok']);
        $this->assertSame('missing_component_update', $result['error_code'] ?? null);
        foreach (['props', 'udc', 'style'] as $name) {
            $this->assertStringContainsString($name, (string) $result['error'], 'the refusal names every route');
        }
    }

    public function testNullValuedPayloadsCountAsAbsent(): void
    {
        $id = $this->page();

        $result = $this->update(['post_id' => $id, 'component_index' => 0, 'props' => null, 'udc' => null]);

        $this->assertFalse($result['ok']);
        $this->assertSame('missing_component_update', $result['error_code'] ?? null);
    }

    public function testEachSinglePayloadFormPasses(): void
    {
        $id = $this->page();

        $props = $this->update(['post_id' => $id, 'component_index' => 1, 'props' => ['title' => 'P']]);
        $udc   = $this->update(['post_id' => $id, 'component_index' => 1, 'udc' => ['heading' => ['typography' => ['weight' => '600']]]]);
        // style-only: the #1101 clear route, which sends only nulls for the v1 keys.
        $style = $this->update(['post_id' => $id, 'component_index' => 1, 'style' => ['--gone' => null]]);

        $this->assertTrue($props['ok'], (string) ($props['error'] ?? ''));
        $this->assertTrue($udc['ok'], (string) ($udc['error'] ?? ''));
        $this->assertTrue($style['ok'], (string) ($style['error'] ?? ''));
    }

    // ── Validation, CAS and minting are the ordinary ones ────────────────────

    public function testAnInvalidMapIsRefusedByTheSharedEngineAndNothingIsStored(): void
    {
        $id = $this->page();
        $before = $this->band($id, 0);

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['no-such-role' => ['typography' => ['color' => '#ffffff']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no-such-role', (string) $result['error']);
        $this->assertSame($before, $this->band($id, 0), 'the band is byte-identical');
    }

    public function testTheMergedMapIsWhatIsValidatedNotThePatchAlone(): void
    {
        $id = $this->page();

        // A role whose stored map is fine, patched with a value the engine refuses.
        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['heading' => ['typography' => ['color' => 'not-a-colour']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(['typography' => ['color' => '#ffffff', 'weight' => '700']],
            $this->band($id, 0)['udc']['heading']);
    }

    public function testAStaleExpectedVersionIsRefusedExactlyAsAPropsEditIs(): void
    {
        $id = $this->page();
        $stale = (int) pp_get_composition_marker($id)['version'];
        $this->update(['post_id' => $id, 'component_index' => 1, 'props' => ['title' => 'moved']]);

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc' => ['heading' => null], 'expected_version' => $stale,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('composition_conflict', $result['error_code'] ?? null);
    }

    public function testAResponsiveValueIsMintedAsOnEveryOtherWrite(): void
    {
        $id = $this->page();

        $result = $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $types = array_column($result['findings'] ?? [], 'type');
        $this->assertContains('udc_token_minted', $types, 'the mint disclosure rides the envelope');
        $udc = $this->band($id, 1)['udc'];
        $this->assertSame('@heading-typography-size-d', $udc['heading']['typography']['size']['d']);
        $this->assertSame('3rem', $udc['_tokens']['heading-typography-size-d']);
    }

    // ── The minted-token consequence ─────────────────────────────────────────

    /**
     * RED BEFORE THE PRUNE: replacing a role whose value the engine minted used to be
     * refused over a token name the author never wrote.
     */
    public function testReplacingAMintedRolePrunesTheTokensItOrphaned(): void
    {
        $id = $this->page();
        $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => [
                'heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]],
                'eyebrow' => ['typography' => ['size' => ['d' => '1rem', 'p' => '0.9rem']]],
            ],
        ]);

        $result = $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['size' => '4rem']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $tokens = $this->band($id, 1)['udc']['_tokens'];
        $this->assertArrayNotHasKey('heading-typography-size-d', $tokens, 'orphaned by the replace');
        $this->assertArrayNotHasKey('heading-typography-size-p', $tokens);
        $this->assertSame('1rem', $tokens['eyebrow-typography-size-d'], 'a kept role keeps its tokens');
        $this->assertSame('0.9rem', $tokens['eyebrow-typography-size-p']);
    }

    public function testRemovingEveryMintedRoleLeavesNoEmptyTokenMap(): void
    {
        $id = $this->page();
        $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
        ]);

        $result = $this->update(['post_id' => $id, 'component_index' => 1, 'udc' => ['heading' => null]]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertArrayNotHasKey('udc', $this->band($id, 1));
    }

    /**
     * The prune reaches ENGINE mints only. An author's own band token is theirs — it survives
     * a role replace even when nothing references it any more, exactly as it would survive an
     * update_composition that left it unreferenced.
     */
    public function testAnAuthorsOwnTokenIsNeverPruned(): void
    {
        $id = $this->page();
        $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => [
                '_tokens' => ['brand-ink' => '#ffd166'],
                'heading' => ['typography' => ['color' => '@brand-ink']],
            ],
        ]);

        $result = $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['color' => '#ffffff']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(['brand-ink' => '#ffd166'], $this->band($id, 1)['udc']['_tokens']);
    }

    /**
     * A mint-SHAPED name the engine did NOT mint — it reached storage off the write path, so
     * nothing ever referenced it — is not the merge's to delete. The gate names it, exactly
     * as it would on any other write to this band; pruning it would silently repair data the
     * operator has not been told about.
     */
    public function testASquattingNameThatArrivedOffPathIsLeftForTheGateToName(): void
    {
        $id = pp_create_page('off-path squat', 'draft');
        update_post_meta($id, '_pp_composition', wp_json_encode([[
            'component' => 'section',
            'id'        => 'pp-5a5a5a5a',
            'udc'       => [
                '_tokens' => ['heading-typography-size-d' => '3rem'],
                'heading' => ['typography' => ['color' => '#ffffff']],
            ],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]));

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['eyebrow' => ['typography' => ['color' => '#ffffff']]],
        ]);

        $this->assertFalse($result['ok'], 'the pre-existing squat is refused, not quietly deleted');
        $this->assertStringContainsString('heading-typography-size-d', (string) $result['error']);
    }

    // ── Preview reports what execute will store ──────────────────────────────

    public function testThePreviewNamesEachRoleThatChanges(): void
    {
        $id = $this->page();

        $preview = pp_preview_action('update_component', [
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['heading' => null, 'eyebrow' => ['typography' => ['color' => '#ffffff']]],
        ]);

        $paths = array_column($preview['changes'] ?? [], 'path');
        $this->assertContains('composition[0].udc.heading', $paths);
        $this->assertContains('composition[0].udc.eyebrow', $paths);
        $this->assertNotContains('composition[0].udc._band', $paths, 'an unsent role is not a change');
    }

    // ── add_component: set ───────────────────────────────────────────────────

    public function testAddComponentWritesTheNewBandsMapInTheSameWrite(): void
    {
        $id = $this->page();

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['title' => 'Added', 'body' => 'b'],
            'udc'       => ['_band' => ['background' => ['fill' => '#101828']], 'heading' => ['typography' => ['color' => '#ffffff']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $added = $this->band($id, 2);
        $this->assertSame('#101828', $added['udc']['_band']['background']['fill']);
        $this->assertMatchesRegularExpression('/\App-[0-9a-f]{8}\z/', (string) ($added['id'] ?? ''),
            'minted a band id like any other written band, so the design is addressable');
    }

    public function testAddComponentRefusesAnInvalidMapAndAddsNothing(): void
    {
        $id = $this->page();

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['title' => 'Added', 'body' => 'b'],
            'udc'       => ['no-such-role' => ['typography' => ['color' => '#ffffff']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertCount(2, pp_get_composition($id));
    }

    public function testAddComponentPreviewCarriesTheMap(): void
    {
        $id = $this->page();

        $preview = pp_preview_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['title' => 'Added', 'body' => 'b'],
            'udc'       => ['heading' => ['typography' => ['color' => '#ffffff']]],
        ]);

        $after = $preview['after'] ?? [];
        $this->assertSame(['heading' => ['typography' => ['color' => '#ffffff']]], end($after)['udc'] ?? null);
    }
}
