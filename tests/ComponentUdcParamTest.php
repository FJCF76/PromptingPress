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

        // The write envelope records exactly what changed, at the grain the merge replaced.
        $rows = [];
        foreach ($result['changes'] as $row) {
            $rows[$row['path']] = $row;
        }
        $this->assertArrayHasKey('composition[0].udc.heading', $rows);
        $this->assertArrayNotHasKey('composition[0].udc._band', $rows, 'an unsent role is not a change');
        $this->assertSame(['typography' => ['color' => '#ffffff', 'weight' => '700']], $rows['composition[0].udc.heading']['from']);
        $this->assertSame(['typography' => ['color' => '#ffd166']], $rows['composition[0].udc.heading']['to']);
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

    /** An EMPTY udc or style changes nothing, so it is not "something to change" either. */
    public function testEmptyUdcOrStyleIsRefusedRatherThanWrittenAsANoOp(): void
    {
        $id = $this->page();
        $before = (int) pp_get_composition_marker($id)['version'];

        $udc   = $this->update(['post_id' => $id, 'component_index' => 0, 'udc' => []]);
        $style = $this->update(['post_id' => $id, 'component_index' => 0, 'style' => []]);

        $this->assertSame('missing_component_update', $udc['error_code'] ?? null);
        $this->assertSame('missing_component_update', $style['error_code'] ?? null);
        $this->assertSame($before, (int) pp_get_composition_marker($id)['version'], 'no version was spent');
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

    /**
     * THE MERGED MAP IS WHAT IS JUDGED. This patch is INVALID on its own — it names a band
     * token it does not declare — and valid only once merged over the stored `_tokens` that
     * declares it. A validate arm that judged the patch alone would refuse it.
     */
    public function testTheMergedMapIsWhatIsValidatedNotThePatchAlone(): void
    {
        $id = $this->page();
        $this->assertTrue($this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['_tokens' => ['brand-ink' => '#ffd166']],
        ])['ok'], 'premise');

        $result = $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['color' => '@brand-ink']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame('@brand-ink', $this->band($id, 1)['udc']['heading']['typography']['color']);
    }

    /** The mirror: a patch valid on its own whose MERGE is refused — the stored map is judged too. */
    public function testAPatchValidAloneIsRefusedWhenItsMergeIsNot(): void
    {
        $id = $this->page();
        $this->assertTrue($this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => [
                '_tokens' => ['brand-ink' => '#ffd166'],
                'heading' => ['typography' => ['color' => '@brand-ink']],
            ],
        ])['ok'], 'premise');

        // Valid alone (it just replaces the token map), dangling once merged.
        $result = $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['_tokens' => ['other-ink' => '#000000']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('brand-ink', (string) $result['error']);
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

        // Tokens are reported PER NAME, so the envelope grows with the change, not the map.
        $paths = array_column($result['changes'], 'path');
        $this->assertContains('composition[1].udc._tokens.heading-typography-size-d', $paths);
        $this->assertContains('composition[1].udc._tokens.heading-typography-size-p', $paths);
        $this->assertNotContains('composition[1].udc._tokens', $paths);
        $this->assertNotContains('composition[1].udc._tokens.eyebrow-typography-size-d', $paths, 'unchanged tokens are not rows');
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

    /**
     * THE PRUNE'S ROSTER — WHY IT EXISTS, AND THE ONE THING IT MUST NEVER DO.
     *
     * The motivating case is the Sprint-2 orphan-token trap (#1101's grid saga, and #1128
     * today): an engine mint left in `_tokens` with nothing referencing it is read by the
     * squat gate as an author squatting the engine's namespace, and the band refuses every
     * later write, permanently, over a name nobody typed. The prune exists to stop a udc
     * merge from CREATING that state. It must therefore never do the opposite harm: delete a
     * mint that is still referenced anywhere on the band, which would leave a dangling
     * reference instead. The roster:
     *
     *   orphaned by this merge, referenced nowhere   -> pruned   testReplacingAMintedRolePrunesTheTokensItOrphaned
     *   still referenced at its own coordinate        -> kept     (this test, and the eyebrow half of the one above)
     *   still referenced by a card                    -> kept     testABandRoleEditKeepsTheTokensAnItemStillReferences
     *   its value gone, another role still names it   -> REFUSED  testReplacingAMintAnotherRoleStillReferencesIsRefusedByName
     *   its value gone, a card still names it          -> REFUSED  testReplacingAMintACardStillReferencesIsRefusedByName
     *   a squat that arrived off-path                  -> left     testASquattingNameThatArrivedOffPathIsLeftForTheGateToName
     *   #1128's shape (props-only item restyle)        -> NOT this merge's; pinned below as the open defect
     */
    public function testThePruneNeverRemovesAMintThatIsStillReferencedAnywhere(): void
    {
        $id = pp_create_page('prune never removes a live mint', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'grid',
            'udc'       => [
                'heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]],
                'eyebrow' => ['typography' => ['size' => ['d' => '1rem', 'p' => '0.9rem']]],
            ],
            'props'     => ['title' => 'G', 'items' => [
                ['title' => 'One', 'udc' => ['card-title' => ['typography' => ['size' => ['d' => '2rem', 'p' => '1rem']]]]],
            ]],
        ]]])['ok'], 'premise');
        $before = $this->band($id, 0)['udc']['_tokens'];
        $this->assertCount(6, $before, 'premise: band + card mints stored');

        // An edit that touches a DIFFERENT role: every stored mint is still referenced.
        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['subheading' => ['typography' => ['color' => '#101828']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame($before, $this->band($id, 0)['udc']['_tokens'], 'not one live mint was removed');
        foreach (array_column($result['changes'], 'path') as $path) {
            $this->assertStringNotContainsString('._tokens.', (string) $path, 'no token row for an untouched mint');
        }
    }

    /**
     * #1128, THE MOTIVATING CASE, PINNED AS THE OPEN DEFECT IT IS. A props-only item restyle
     * with new responsive values leaves the item's old mints orphaned and is refused over the
     * engine's own name; the udc merge's prune does not reach the props path (#1088's ruling
     * scoped it to udc). The same restyle carrying a band udc patch goes through, because the
     * prune runs. When #1128 is fixed the first assertion flips — update it there, on purpose.
     */
    public function testIssue1128TheLeftoverItemMintTrapIsOpenOnThePropsPathAndClosedOnTheUdcPath(): void
    {
        $id = pp_create_page('1128 motivating case', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'grid',
            'props'     => ['title' => 'G', 'items' => [
                ['title' => 'One', 'udc' => ['card-title' => ['typography' => ['size' => ['d' => '2rem', 'p' => '1rem']]]]],
            ]],
        ]]])['ok'], 'premise');
        $items = pp_get_composition($id)[0]['props']['items'];
        $items[0]['udc'] = ['card-title' => ['typography' => ['size' => ['d' => '3rem', 'p' => '1.5rem']]]];

        $props_only = $this->update(['post_id' => $id, 'component_index' => 0, 'props' => ['items' => $items]]);
        $this->assertFalse($props_only['ok'], 'OPEN (#1128): the props path still refuses over the leftover mint');
        $this->assertStringContainsString('mints for itself', (string) $props_only['error']);

        $with_udc = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'props'   => ['items' => $items],
            'udc'     => ['heading' => ['typography' => ['color' => '#101828']]],
        ]);
        $this->assertTrue($with_udc['ok'], 'the udc merge prunes the orphaned card mint: ' . ($with_udc['error'] ?? ''));
        $item_id = (string) $this->band($id, 0)['props']['items'][0]['id'];
        $this->assertSame('3rem', $this->band($id, 0)['udc']['_tokens'][$item_id . '-card-title-typography-size-d']);
    }

    /**
     * ITEM-MINTED TOKENS LIVE IN THE BAND'S `_tokens` and their references sit in
     * `props.items[k].udc`. A band-role udc edit must see those references, or it prunes a
     * token a card still paints with (review finding: the item-map arm was unpinned — a
     * mutation dropping the item maps from the merged check left the whole suite green).
     */
    public function testABandRoleEditKeepsTheTokensAnItemStillReferences(): void
    {
        $id = pp_create_page('item mints', 'draft');
        $seed = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'grid',
            'udc'       => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
            'props'     => ['title' => 'G', 'items' => [
                ['title' => 'One', 'udc' => ['card-title' => ['typography' => ['size' => ['d' => '2rem', 'p' => '1rem']]]]],
            ]],
        ]]]);
        $this->assertTrue($seed['ok'], 'premise: ' . ($seed['error'] ?? ''));
        $item_tokens = array_filter(
            array_keys($this->band($id, 0)['udc']['_tokens']),
            static fn ($n) => str_starts_with((string) $n, 'it-')
        );
        $this->assertCount(2, $item_tokens, 'premise: the item values were minted into the band');

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['heading' => ['typography' => ['size' => '4rem']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $tokens = $this->band($id, 0)['udc']['_tokens'];
        foreach ($item_tokens as $name) {
            $this->assertArrayHasKey($name, $tokens, 'the card still paints with ' . $name);
        }
        $this->assertArrayNotHasKey('heading-typography-size-d', $tokens, 'the band mint the edit orphaned is pruned');
    }

    /**
     * THE ROUND TRIP: read the band's map back, change one role, send the WHOLE map — its
     * `_tokens` included, engine mints and all. The mints the edit orphaned are the engine's,
     * so they are pruned here too; refusing the round trip would blame the caller for names
     * the engine wrote (adversarial-pass finding: this used to be refused with "pick another
     * name").
     */
    public function testARoundTripThatResendsTheStoredTokenMapIsAccepted(): void
    {
        $id = $this->page();
        $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
        ]);
        $map = $this->band($id, 1)['udc'];
        $map['heading'] = ['typography' => ['size' => '4rem']];

        $result = $this->update(['post_id' => $id, 'component_index' => 1, 'udc' => $map]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertArrayNotHasKey('_tokens', $this->band($id, 1)['udc'], 'both orphaned mints pruned');
        $this->assertSame('4rem', $this->band($id, 1)['udc']['heading']['typography']['size']);
    }

    /** A mint-shaped name the PATCH introduces is not the engine's — the squat gate names it. */
    public function testAMintShapedNameThePatchIntroducesIsStillRefused(): void
    {
        $id = $this->page();

        $result = $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['_tokens' => ['heading-typography-size-d' => '3rem']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('heading-typography-size-d', (string) $result['error']);
    }

    /**
     * A MINT ANOTHER ROLE REUSES (update_composition stores that shape) is neither pruned nor
     * kept when its own role is replaced — the refusal says which token and why, rather than
     * blaming the role the caller did not touch.
     */
    public function testReplacingAMintAnotherRoleStillReferencesIsRefusedByName(): void
    {
        $id = pp_create_page('reused mint', 'draft');
        // Two writes, as an author reaches it: the first mints, the second reuses the stored
        // mint by name from another role (a reference cannot name a mint the same write makes).
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'section',
            'udc'       => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]])['ok'], 'premise: minted');
        $stored = pp_get_composition($id);
        $stored[0]['udc']['subheading'] = ['typography' => ['size' => '@heading-typography-size-d']];
        $seed = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => $stored]);
        $this->assertTrue($seed['ok'], 'premise: reused: ' . ($seed['error'] ?? ''));
        $before = $this->band($id, 0);

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['heading' => ['typography' => ['color' => '#111111']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code'] ?? null);
        $this->assertStringContainsString('@heading-typography-size-d', (string) $result['error']);
        $this->assertStringContainsString('still references', (string) $result['error']);
        $this->assertSame($before, $this->band($id, 0), 'nothing stored');
    }

    /** The same stranded case when the reuse sits in a CARD's map rather than a band role. */
    public function testReplacingAMintACardStillReferencesIsRefusedByName(): void
    {
        $id = pp_create_page('reused by a card', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'grid',
            'udc'       => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
            'props'     => ['title' => 'G', 'items' => [['title' => 'One']]],
        ]]])['ok'], 'premise: minted');
        $stored = pp_get_composition($id);
        $stored[0]['props']['items'][0]['udc'] = ['card-title' => ['typography' => ['size' => '@heading-typography-size-d']]];
        $seed = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => $stored]);
        $this->assertTrue($seed['ok'], 'premise: reused by a card: ' . ($seed['error'] ?? ''));

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['heading' => ['typography' => ['color' => '#111111']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('still references', (string) $result['error']);
    }

    /**
     * THE RECORD DESCRIBES WHAT IS STORED. Changing a responsive value keeps its minted
     * token and gives it a new value; the envelope used to report the token as DELETED and
     * the role as a raw literal, because it diffed the merge before the write minted it.
     */
    public function testARestyledResponsiveValueIsReportedAsATokenValueChange(): void
    {
        $id = $this->page();
        $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
        ]);

        $preview = pp_preview_action('update_component', [
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['size' => ['d' => '5rem', 'p' => '2rem']]]],
        ]);
        $result = $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['size' => ['d' => '5rem', 'p' => '2rem']]]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        foreach (['preview' => $preview['changes'], 'execute' => $result['changes']] as $surface => $changes) {
            $rows = array_column($changes, null, 'path');
            $row = $rows['composition[1].udc._tokens.heading-typography-size-d'] ?? null;
            $this->assertNotNull($row, $surface . ' reports the token');
            $this->assertSame('3rem', $row['from'], $surface);
            $this->assertSame('5rem', $row['to'], $surface . ': a new value, not a deletion');
            $this->assertArrayNotHasKey('composition[1].udc.heading', $rows,
                $surface . ': the role still holds the same references, so it is not a change');
        }
        $this->assertSame('5rem', $this->band($id, 1)['udc']['_tokens']['heading-typography-size-d']);
    }

    /**
     * EXECUTE FAILS CLOSED ON A STRANDED MINT, independently of validate. A caller with no
     * expected_version can have the band change between validate's read and execute's; the
     * writer normalizes but does not re-validate a udc map. Driven by calling the registered
     * execute arm directly against a band validate never saw.
     */
    public function testExecuteRefusesAStrandedMintOnTheStateItMerged(): void
    {
        $id = pp_create_page('execute-stage strand', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'section',
            'udc'       => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]])['ok']);
        $stored = pp_get_composition($id);
        $stored[0]['udc']['subheading'] = ['typography' => ['size' => '@heading-typography-size-d']];
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => $stored])['ok']);
        $before = $this->band($id, 0);

        $execute = pp_get_action('update_component')['execute'];
        $result = $execute([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['heading' => ['typography' => ['color' => '#111111']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertSame($before, $this->band($id, 0), 'nothing stored');
    }

    /**
     * EXECUTE RE-JUDGES THE WHOLE MERGED BAND (Codex adversarial pass): a `_tokens`
     * replacement that drops a token the band still references is refused even when
     * validate never saw that reference — here, because execute is driven directly against
     * a band validate did not read, the shape a validate/execute race produces.
     */
    public function testExecuteRevalidatesTheMergedBandItIsAboutToWrite(): void
    {
        $id = $this->page();
        $this->assertTrue($this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => [
                '_tokens' => ['brand-ink' => '#ffd166'],
                'heading' => ['typography' => ['color' => '@brand-ink']],
            ],
        ])['ok'], 'premise');
        $before = $this->band($id, 1);

        $execute = pp_get_action('update_component')['execute'];
        $result = $execute([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['_tokens' => ['other-ink' => '#000000']],
        ]);

        $this->assertFalse($result['ok'], 'a dangling @brand-ink is never stored');
        $this->assertStringContainsString('brand-ink', (string) $result['error']);
        $this->assertSame($before, $this->band($id, 1));
    }

    /**
     * THE WRITE RECORD NAMES THE TOKENS THAT WERE STORED (Codex adversarial pass). A card
     * added in the same call is given its id by the write itself, so its minted token names
     * cannot be known beforehand; execute reads its udc record back from storage.
     */
    public function testExecuteReportsANewCardsMintsUnderTheIdItWasStoredWith(): void
    {
        $id = pp_create_page('new card mints', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'grid', 'props' => ['title' => 'G', 'items' => [['title' => 'One']]],
        ]]])['ok']);

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'props'   => ['items' => [
                ['title' => 'One'],
                ['title' => 'New', 'udc' => ['card-title' => ['typography' => ['size' => ['d' => '2rem', 'p' => '1rem']]]]],
            ]],
            'udc'     => ['heading' => ['typography' => ['color' => '#101828']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $new_id = (string) $this->band($id, 0)['props']['items'][1]['id'];
        $this->assertContains(
            'composition[0].udc._tokens.' . $new_id . '-card-title-typography-size-d',
            array_column($result['changes'], 'path')
        );
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

    public function testAddComponentWithAnEmptyMapStoresNoUdcKey(): void
    {
        $id = $this->page();

        $result = pp_execute_action('add_component', [
            'post_id' => $id, 'component' => 'section',
            'props'   => ['title' => 'Added', 'body' => 'b'], 'udc' => [],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertArrayNotHasKey('udc', $this->band($id, 2), 'byte-identical to an add with no udc');
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

    // ── Ship coverage audit additions ────────────────────────────────────────

    /** The stranded refusal's PLURAL wording: two mints stranded at once are both named. */
    public function testTwoStrandedMintsAreBothNamedInThePluralRefusal(): void
    {
        $id = pp_create_page('two stranded', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', ['post_id' => $id, 'composition' => [[
            'component' => 'section',
            'udc'       => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
            'props'     => ['title' => 'S', 'body' => 'b'],
        ]]])['ok'], 'premise: minted');
        $stored = pp_get_composition($id);
        $stored[0]['udc']['subheading'] = ['typography' => ['size' => '@heading-typography-size-d']];
        $stored[0]['udc']['eyebrow']    = ['typography' => ['size' => '@heading-typography-size-p']];
        $seed = pp_execute_action('update_composition', ['post_id' => $id, 'composition' => $stored]);
        $this->assertTrue($seed['ok'], 'premise: reused twice: ' . ($seed['error'] ?? ''));
        $before = $this->band($id, 0);

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['heading' => ['typography' => ['color' => '#111111']]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code'] ?? null);
        $error = (string) $result['error'];
        $this->assertStringContainsString('tokens ', $error, 'plural noun');
        $this->assertStringContainsString('@heading-typography-size-d', $error);
        $this->assertStringContainsString('@heading-typography-size-p', $error);
        $this->assertStringContainsString('still references them', $error);
        $this->assertStringContainsString('those references', $error);
        $this->assertSame($before, $this->band($id, 0), 'nothing stored');
    }

    /**
     * Emptying the whole map in one call: the stored band has NO udc key, so execute's
     * read-back falls back to the applied (empty) map — and still records each removed role.
     */
    public function testRemovingEveryRoleInOneCallRecordsEachRemovedRole(): void
    {
        $id = $this->page();

        $result = $this->update([
            'post_id' => $id, 'component_index' => 0,
            'udc'     => ['_band' => null, 'heading' => null],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertArrayNotHasKey('udc', $this->band($id, 0));
        $rows = array_column($result['changes'], null, 'path');
        $this->assertArrayHasKey('composition[0].udc._band', $rows);
        $this->assertArrayHasKey('composition[0].udc.heading', $rows);
        $this->assertNull($rows['composition[0].udc._band']['to']);
        $this->assertSame(['background' => ['fill' => '#101828']], $rows['composition[0].udc._band']['from']);
        $this->assertNull($rows['composition[0].udc.heading']['to']);
    }

    /** A props-only edit on a styled band reports no udc rows and leaves the map byte-identical. */
    public function testAPropsOnlyEditOnAStyledBandReportsNoUdcRows(): void
    {
        $id = $this->page();
        $udc_before = $this->band($id, 0)['udc'];
        $params = ['post_id' => $id, 'component_index' => 0, 'props' => ['title' => 'Renamed']];

        $preview = pp_preview_action('update_component', $params);
        $result  = $this->update($params);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        foreach (['preview' => $preview['changes'] ?? [], 'execute' => $result['changes']] as $surface => $changes) {
            foreach (array_column($changes, 'path') as $path) {
                $this->assertStringNotContainsString('.udc', (string) $path, $surface . ' reported a udc row for a props-only edit');
            }
        }
        $this->assertSame($udc_before, $this->band($id, 0)['udc']);
        $this->assertSame('Renamed', $this->band($id, 0)['props']['title']);
    }

    /** The first mint on a band is reported per token, from null — never as a whole-map row. */
    public function testAFirstMintIsReportedPerTokenFromNull(): void
    {
        $id = $this->page();

        $result = $this->update([
            'post_id' => $id, 'component_index' => 1,
            'udc'     => ['heading' => ['typography' => ['size' => ['d' => '3rem', 'p' => '2rem']]]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $rows = array_column($result['changes'], null, 'path');
        $this->assertArrayNotHasKey('composition[1].udc._tokens', $rows);
        $row = $rows['composition[1].udc._tokens.heading-typography-size-d'] ?? null;
        $this->assertNotNull($row);
        $this->assertNull($row['from']);
        $this->assertSame('3rem', $row['to']);
        $this->assertSame('2rem', $rows['composition[1].udc._tokens.heading-typography-size-p']['to'] ?? null);
        $this->assertArrayHasKey('composition[1].udc.heading', $rows, 'the role itself is new');
    }

    /** add_component's `position` splice carries the new band's map to the index it lands on. */
    public function testAddComponentAtAPositionCarriesItsMapThere(): void
    {
        $id = $this->page();

        $result = pp_execute_action('add_component', [
            'post_id'   => $id,
            'component' => 'section',
            'props'     => ['title' => 'First', 'body' => 'b'],
            'udc'       => ['heading' => ['typography' => ['color' => '#123456']]],
            'position'  => 0,
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertCount(3, pp_get_composition($id));
        $this->assertSame('First', $this->band($id, 0)['props']['title']);
        $this->assertSame(['heading' => ['typography' => ['color' => '#123456']]], $this->band($id, 0)['udc']);
        $this->assertSame('Styled', $this->band($id, 1)['props']['title'], 'the old first band moved down');
    }

    /** A udc patch addressed by component_id reaches the band the id names, and only it. */
    public function testAUdcPatchAddressedByComponentIdRestylesThatBand(): void
    {
        $id = $this->page();
        $seed = $this->update(['post_id' => $id, 'component_index' => 1, 'props' => ['id' => 'plain-band']]);
        $this->assertTrue($seed['ok'], 'premise: authored id prop: ' . ($seed['error'] ?? ''));
        $band_id = 'plain-band';
        $other_before = $this->band($id, 0);

        $result = $this->update([
            'post_id' => $id, 'component_id' => $band_id,
            'udc'     => ['heading' => ['typography' => ['color' => '#abcdef']]],
        ]);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(['heading' => ['typography' => ['color' => '#abcdef']]], $this->band($id, 1)['udc']);
        $this->assertSame($other_before, $this->band($id, 0), 'the other band is untouched');
    }
}
