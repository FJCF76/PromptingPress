<?php
/**
 * tests/ObjectShapedPropWriteEnforcementTest.php
 *
 * A declared `type: "object"` means a JSON MAP at the write path, at both depths (#883).
 *
 * THE MIRROR OF #738, AND THE HONEST VERSION OF WHY IT SHIPPED. Nothing was broken.
 * Measured on main before this rule, through the real authoring surface:
 *
 *     write                                        verdict   refused by
 *     ───────────────────────────────────────────  ────────  ─────────────────────────
 *     grid.items[].style: ["#fff"]                 ok:false  the STYLE-SLOT engine
 *     section.panel_items[].style: ["#fff"]        ok:false  the STYLE-SLOT engine
 *     a synthetic `object` prop: ["a", "b"]        ok:TRUE   nothing at all
 *
 * The first two rows are why this is not a bug report. Both `object` declarations in
 * the shipped registry are per-item style maps, and a list reaching either is refused a
 * few rules later by the shared style-slot engine, which reads a list's integer keys as
 * slot names: `item 0 has no style slot "0". Available slots: ...`. So the authoring
 * contract an operator reads ("a per-item style takes a JSON object") held end to end.
 *
 * The THIRD row is the issue. That safety was a property of today's two consumers, not
 * of the rule. `_pp_schema_container_value_is_valid()` decides "container or scalar?"
 * and says so in its own docblock — a JSON list and a JSON object both decode to a PHP
 * array — so a future `type: "object"` field routed anywhere but the slot engine (a
 * metadata bag, an options object) would have accepted a JSON list, persisted it behind
 * `ok:true`, and rendered whatever its consumer does with a list. That is the
 * reported-success-without-effect class #614/#707/#744 each closed one type over, and it
 * is the same argument #744 used to build its own top-level `object` arm ahead of any
 * shipped declaration: the cheap moment to close a fence is before something lands on it.
 *
 * THE RULING (T4, recorded in #883's body; D-A posture, canonical text in #724's body).
 * REJECT, NEVER COERCE: no array_combine(), no synthetic keys, no stored-data migration.
 *
 * BE PRECISE ABOUT WHAT BREAKS, because "breaking narrowing" is the wrong headline for
 * the shipped surface and the right one for everything else. For `grid.items[].style`
 * and `section.panel_items[].style` the set of REFUSED writes is unchanged — a populated
 * list always carries integer key `0`, no component declares a slot named `"0"`, and the
 * empty container was and is accepted by both rules. What changes for those two is the
 * ERROR CODE and the message: `invalid_style_slot` / `has no style slot "0"` becomes
 * `invalid_prop_value` / `must be an object, but this one is a JSON list`. Anything keyed
 * on the old code for this shape is what the disclosure is about. The genuine narrowing is
 * prospective — any future `type: "object"` declaration, at either depth — plus one edge
 * that is newly refused at the predicate: a JSON object whose keys are exactly `0..n-1`
 * (§5). Both installs were swept read-only before this shipped: zero stored pages carry a
 * list under a declared-object prop.
 *
 * WHAT THIS FILE PINS. Each entry names the section that holds it:
 *
 *   §1 THE PREDICATE — the `object` leg, the empty container, the not-applicable
 *      contract, and the STAGE ORDER that keeps a scalar on the container rule's message.
 *   §2 UNIFORM COVERAGE — that BOTH depths route through the predicate, asserted by
 *      INVENTORY off the shipped schemas rather than by two hand-picked cases, so an
 *      `object` declaration landing tomorrow is covered the day it lands.
 *   §3 THE AUTHORING PATH (Section 14.1) — update_composition / create_page /
 *      update_component / add_component, never raw `_pp_composition` meta writes. Each
 *      refusal is paired with the well-formed counterpart so a fixture failing for an
 *      unrelated reason cannot read as a pass, and each asserts nothing persisted.
 *   §4 THE ROUTE TABLE — `restore_composition` must still restore VERBATIM and report
 *      rather than block (#233). That is the most important assertion in the file.
 *   §5 THE BOUNDARIES — the empty container, the folded-numeric limit, the two-findings
 *      posture on collect-all surfaces, the synthetic TOP-LEVEL arm (the only way to
 *      enter it), and the RENDER path, which degrades rather than fatals and therefore
 *      needs no new guard.
 */

use PHPUnit\Framework\TestCase;

class ObjectShapedPropWriteEnforcementTest extends TestCase
{
    /** Set by useSyntheticComponent(); torn down in tearDown(). */
    private ?string $syntheticThemeDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->syntheticThemeDir !== null) {
            unset($GLOBALS['_pp_test_template_dir']);
            $GLOBALS['_pp_registered_components_invalidate'] = true;
            $this->recursiveDelete($this->syntheticThemeDir);
            $this->syntheticThemeDir = null;
        }
        parent::tearDown();
    }

    // ── §1. The predicate itself ────────────────────────────────────────────

    /**
     * The `object` leg refuses a POPULATED list and accepts every map.
     *
     * Driven over several list shapes, not one: a single `['#fff']` case would stay
     * green against a predicate narrowed to one-element lists or to string elements.
     */
    public function testTheObjectLegRefusesAPopulatedListAndAcceptsAMap(): void
    {
        $lists = [
            ['#fff'],
            ['#fff', '#000'],
            [['nested'], ['deeper']],
            [0, 1, 2],
            [null],
        ];
        foreach ($lists as $bad) {
            $this->assertFalse(
                _pp_schema_object_value_is_valid('object', $bad),
                sprintf('type:object must refuse the list %s', var_export($bad, true))
            );
        }

        $maps = [
            ['--grid-item-bg' => '#111111'],
            ['--section-panel-text' => '#fff', '--grid-item-radius' => '4px'],
            // A numeric key that is NOT a 0-based run is a map, and stays one.
            ['1' => 'a', '2' => 'b'],
            ['0' => 'a', '2' => 'b'],
        ];
        foreach ($maps as $good) {
            $this->assertTrue(
                _pp_schema_object_value_is_valid('object', $good),
                sprintf('type:object must accept the map %s', var_export($good, true))
            );
        }
    }

    /**
     * The EMPTY container is accepted, and so is every non-array.
     *
     * `json_decode('{}', true)` and `json_decode('[]', true)` both return `[]`, so the
     * empty object is indistinguishable from the empty list at this layer and refusing
     * one would refuse the other. `null` / `''` / scalars are not this predicate's
     * business at all — see the stage-order pin below.
     */
    public function testTheEmptyContainerAndEveryNonArrayAreAccepted(): void
    {
        $this->assertSame([], json_decode('{}', true), 'the premise: {} and [] decode identically');
        $this->assertSame([], json_decode('[]', true));
        $this->assertTrue(_pp_schema_object_value_is_valid('object', []),
            'the empty container must stay accepted — it is the only shape both spellings share');

        foreach ([null, '', 'text', 0, 42, 3.14, true, false] as $non_array) {
            $this->assertTrue(
                _pp_schema_object_value_is_valid('object', $non_array),
                sprintf('a non-array is not this predicate\'s business: %s', var_export($non_array, true))
            );
        }
    }

    /**
     * The not-applicable contract every sibling predicate carries: any other declared
     * type returns true, so a caller may hand it every declaration it walks without
     * pre-classifying.
     */
    public function testEveryOtherDeclaredTypeIsNotApplicable(): void
    {
        foreach (['array', 'string', 'number', 'enum', 'bool', null] as $other) {
            $this->assertTrue(_pp_schema_object_value_is_valid($other, ['#fff']),
                sprintf('type:%s is not this rule\'s business', var_export($other, true)));
            $this->assertTrue(_pp_schema_object_value_is_valid($other, ['a' => 1]));
        }
    }

    /**
     * THE STAGE-ORDER PIN, asserting BOTH halves rather than only this predicate's answer.
     *
     * A scalar returns TRUE here, which is safe only while
     * _pp_schema_container_value_is_valid() runs in FRONT of it. A future caller that
     * reached for this one ALONE to enforce `type: "object"` would silently accept every
     * scalar — the #744 defect reintroduced under a newer number. Asserting only
     * `object(scalar) === true` would document the hazard without catching it; asserting
     * the pair means the arrangement that makes it safe is what the test holds.
     */
    public function testAScalarIsStillTheContainerRulesToRefuse(): void
    {
        foreach (['not an array', '0', 42, 0, 3.14, true, false] as $scalar) {
            $this->assertTrue(_pp_schema_object_value_is_valid('object', $scalar));
            $this->assertFalse(_pp_schema_container_value_is_valid('object', $scalar));
        }
    }

    /**
     * The two shape predicates are SEPARATE FUNCTIONS asking opposite questions, and
     * neither answers for the other's declared type.
     *
     * This is the pin against a later "cleanup" folding them into one predicate that
     * switches on the declared type: one function would mean one message answering for
     * two rules, and the two messages are what tell an author which bracket to send.
     */
    public function testTheListAndObjectPredicatesStayIndependent(): void
    {
        // A list under `object`: refused by the object rule, not the list rule's business.
        $this->assertFalse(_pp_schema_object_value_is_valid('object', ['#fff']));
        $this->assertTrue(_pp_schema_list_value_is_valid('object', ['#fff']));
        // A map under `array`: refused by the list rule, not the object rule's business.
        $this->assertFalse(_pp_schema_list_value_is_valid('array', ['first' => 1]));
        $this->assertTrue(_pp_schema_object_value_is_valid('array', ['first' => 1]));
    }

    // ── §2. Uniform coverage across both depths, by inventory ───────────────

    /**
     * Every NESTED `type: "object"` field in the shipped schemas refuses a populated list.
     *
     * INVENTORY-DRIVEN, not case-driven, for the reason #744's and #738's sibling sweeps
     * give: a two-case pin proves the helper changed; it does not prove every
     * object-typed schema path reaches it. The count is asserted so a NEW declaration is
     * covered the day it lands and a DELETED one is noticed.
     */
    public function testEveryNestedObjectFieldRefusesAPopulatedList(): void
    {
        $seen = 0;
        foreach (pp_composable_components() as $component => $schema) {
            foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                if (($prop_def['type'] ?? null) !== 'array' || !is_array($prop_def['items'] ?? null)) {
                    continue;
                }
                foreach ($prop_def['items'] as $field_name => $field_def) {
                    if (!is_array($field_def) || ($field_def['type'] ?? null) !== 'object') {
                        continue;
                    }
                    $seen++;

                    $entry = $this->wellFormedEntry($prop_def['items']);
                    $entry[$field_name] = ['#fff'];
                    $rejected = pp_validate_composition([[
                        'component' => $component,
                        'props'     => $this->wellFormedProps($component, [$prop_name => [$entry]]),
                    ]]);

                    $this->assertInstanceOf(WP_Error::class, $rejected, sprintf(
                        '%s.%s[].%s declares type:object and must refuse a populated list',
                        $component, $prop_name, $field_name
                    ));
                    $this->assertSame('invalid_prop_value', $rejected->get_error_code());
                    $this->assertStringContainsString(
                        sprintf('field "%s" must be an object, but this one is a JSON list (1 entry).', $field_name),
                        $rejected->get_error_message(),
                        'and the message names the FIELD and the shape it actually got'
                    );

                    // Paired with the well-formed counterpart, so a fixture failing for
                    // an unrelated reason cannot read as a pass.
                    $entry[$field_name] = [];
                    $this->assertTrue(
                        pp_validate_composition([[
                            'component' => $component,
                            'props'     => $this->wellFormedProps($component, [$prop_name => [$entry]]),
                        ]]) === true,
                        sprintf('%s.%s[].%s must still accept the empty container', $component, $prop_name, $field_name)
                    );
                }
            }
        }

        $this->assertSame(2, $seen,
            'the registry declares exactly two nested `object` fields today (grid.items[].style,'
            . ' section.panel_items[].style) — if this count moved, the walk above covered a'
            . ' different set than the one this file claims and the number needs updating deliberately');
    }

    /**
     * No shipped schema declares a TOP-LEVEL `object` prop, asserted rather than assumed.
     *
     * The top-level arm is therefore reachable only by the synthetic fixture in §5. If
     * this count ever moves, that arm has a real caller and §5 is no longer the only
     * thing standing between it and a silent regression.
     */
    public function testNoShippedSchemaDeclaresATopLevelObjectProp(): void
    {
        $found = [];
        foreach (pp_composable_components() as $component => $schema) {
            foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                if (is_array($prop_def) && ($prop_def['type'] ?? null) === 'object') {
                    $found[] = $component . '.' . $prop_name;
                }
            }
        }
        $this->assertSame([], $found,
            'a top-level `object` prop now ships — the §5 synthetic arm is no longer the only'
            . ' coverage that rule has, and this file should pin the real declaration too');
    }

    // ── §3. The authoring path (Section 14.1) ───────────────────────────────

    /**
     * THE REFUSAL THROUGH THE ACTION AN AUTHOR ACTUALLY USES.
     *
     * Section 14.1 requires this to run through the real surface rather than a raw
     * `_pp_composition` write, because tests/bootstrap.php accepts an already-decoded
     * array on raw writes and so bypasses the decode filter — which is exactly how a
     * schema-contract defect escaped in #488.
     */
    public function testUpdateCompositionRefusesAListStyleAndStoresNothing(): void
    {
        $post_id = pp_create_page('Existing page', 'draft');
        $before  = [['component' => 'grid', 'props' => [
            'id' => 'pp-keep', 'items' => [['title' => 'Original card', 'text' => 'Body']],
        ]]];
        pp_update_composition($post_id, $before);
        $stored_before = $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'];

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'grid', 'props' => [
                'items' => [['title' => 'Card', 'text' => 'Body', 'style' => json_decode('["#fff"]', true)]],
            ]]],
        ]);

        $this->assertFalse($result['ok'], 'a list where an object belongs must not return ok:true');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        // The standard envelope: band, then prop, then item, then field, then what to send.
        $this->assertStringContainsString('Component 0 ("grid")', $result['error']);
        $this->assertStringContainsString('prop "items" item 0 field "style"', $result['error']);
        $this->assertStringContainsString('must be an object, but this one is a JSON list (1 entry).', $result['error']);
        $this->assertStringContainsString('Send it as an object with keys ({...}), not an array ([...]).', $result['error']);
        // The SHAPE rule answers, not the slot engine that used to stand in for it.
        $this->assertStringNotContainsString('has no style slot', $result['error'],
            'the rule that owns the declared type answers now — the slot engine owns slot NAMES');

        $this->assertSame(
            $stored_before,
            $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'],
            'a rejected write stores nothing — not even partially'
        );
    }

    /**
     * The WELL-FORMED counterpart, same card, same action. Paired with the refusal above
     * so a fixture that started failing for an unrelated reason cannot read as a pass.
     */
    public function testUpdateCompositionStillAcceptsTheMapForm(): void
    {
        $post_id = pp_create_page('Existing page', 'draft');
        pp_update_composition($post_id, [['component' => 'grid', 'props' => ['items' => [['title' => 'x']]]]]);

        $decoded = json_decode(
            '{"items":[{"title":"Card","text":"Body","style":{"--grid-item-bg":"#111111"}}]}',
            true
        );
        $result = pp_execute_action('update_composition', [
            'post_id' => $post_id, 'composition' => [['component' => 'grid', 'props' => $decoded]],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'the map form must still be accepted');
        $style = pp_get_composition($post_id)[0]['props']['items'][0]['style'];
        $this->assertSame(['--grid-item-bg' => '#111111'], $style, 'and it is stored verbatim');
    }

    /** create_page — the other whole-composition verb, refusing the same payload. */
    public function testCreatePageRefusesAListStyleAndCreatesNoPage(): void
    {
        $before = count($GLOBALS['_pp_test_store']['posts']);

        $result = pp_execute_action('create_page', [
            'title'       => 'Should not exist',
            'composition' => [['component' => 'grid', 'props' => [
                'items' => [['title' => 'Card', 'text' => 'Body', 'style' => ['#fff']]],
            ]]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('field "style" must be an object', $result['error']);
        $this->assertCount($before, $GLOBALS['_pp_test_store']['posts'], 'a refused create_page creates nothing');
    }

    /**
     * update_component — the action an agent reaches for most often when repairing one
     * band, and the one that validates the MERGED whole composition rather than the patch.
     */
    public function testUpdateComponentRefusesAListStyleAndLeavesTheBandUntouched(): void
    {
        $post_id = pp_create_page('Patch me', 'draft');
        pp_update_composition($post_id, [['component' => 'grid', 'props' => [
            'id' => 'pp-target', 'items' => [['title' => 'Original card', 'text' => 'Body']],
        ]]]);

        $result = pp_execute_action('update_component', [
            'post_id'      => $post_id,
            'component_id' => 'pp-target',
            'props'        => ['items' => [['title' => 'Card', 'text' => 'Body', 'style' => ['#fff']]]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('field "style" must be an object', $result['error']);
        $this->assertSame(
            'Original card',
            pp_get_composition($post_id)[0]['props']['items'][0]['title'],
            'a refused patch leaves the stored band exactly as it was'
        );
    }

    /**
     * add_component — judged by pp_validate_composition_item(), which wraps the ONE new
     * band in a synthetic array and runs the same shared engine, so the refusal reaches
     * it without a second validator.
     */
    public function testAddComponentRefusesAListStyle(): void
    {
        $post_id = pp_create_page('Append to me', 'draft');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result = pp_execute_action('add_component', [
            'post_id'   => $post_id,
            'component' => 'grid',
            'props'     => ['items' => [['title' => 'Card', 'text' => 'Body', 'style' => ['#fff']]]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('field "style" must be an object', $result['error']);
        $this->assertCount(1, pp_get_composition($post_id), 'nothing was appended');
    }

    /**
     * THE SECOND SHIPPED FIELD, through a real action rather than only through the §2
     * inventory walk.
     *
     * `section.panel_items[].style` reaches RULE 6c by a different route than
     * `grid.items[].style`: its prop accepts MIXED string and object entries, so the
     * entry walk it sits in is not the one grid uses. A rule proven on one of two
     * shipped callers is a rule proven on half the surface.
     */
    public function testTheSectionPanelRowStyleIsRefusedThroughARealActionToo(): void
    {
        $post_id = pp_create_page('Panel page', 'draft');
        pp_update_composition($post_id, [['component' => 'section', 'props' => ['body' => 'Body copy']]]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'section', 'props' => [
                'body'        => 'Body copy',
                'panel_items' => [['label' => 'Row', 'style' => json_decode('["#fff","#000"]', true)]],
            ]]],
        ]);

        $this->assertFalse($result['ok'], 'the panel-row style must be refused too');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('field "style"', $result['error']);
        $this->assertStringContainsString('must be an object, but this one is a JSON list (2 entries).', $result['error'],
            'and the entry count pluralizes');
    }

    // ── §4. The route table: what refuses, and what deliberately bypasses ───

    /**
     * restore_composition IS NEVER BLOCKED BY CURRENT VALIDATION RULES (#233), and this is
     * the most important assertion in the file.
     *
     * Undo is wired to restore. A restore that current rules refuse would fail exactly
     * when a user most needs it, and a narrowing that blocked restore would strand the
     * very pages it exists to name. Restore replays a snapshot VERBATIM and REPORTS rule
     * violations in `findings` instead of vetoing them.
     */
    public function testRestoreCompositionRestoresAListStyleAndReportsRatherThanBlocking(): void
    {
        $post_id = pp_create_page('History page', 'draft');
        // v1 holds the list-shaped style. pp_update_composition() is the storage writer,
        // not a validator, which is precisely how a pre-rule composition got into a ring.
        pp_update_composition($post_id, [['component' => 'grid', 'props' => [
            'items' => [['title' => 'Card one', 'text' => 'Body', 'style' => ['#fff']]],
        ]]]);
        pp_update_composition($post_id, [['component' => 'grid', 'props' => [
            'items' => [['title' => 'Clean card', 'text' => 'Body']],
        ]]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'version' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore must not be blocked by a write rule');
        $this->assertSame(
            ['#fff'],
            pp_get_composition($post_id)[0]['props']['items'][0]['style'],
            'and it is restored VERBATIM — no key synthesis, no coercion (D-A)'
        );

        // REPORTS, not just "does not block", which is the half the #233 contract is
        // actually about and the half a silent regression would eat.
        $messages = array_column($result['findings'] ?? [], 'message');
        $this->assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'field "style" must be an object')
        ), 'the restore envelope must REPORT the shape defect it declined to block on');
    }

    /**
     * remove_component and reorder_components run no composition validation at all, so a
     * page already holding a list-shaped style can still have that band removed or moved.
     * Gating them would make such a page unrepairable by the two actions most likely to
     * repair it.
     */
    public function testThePermutationVerbsStillWorkOnAPageHoldingAListStyle(): void
    {
        $post_id = pp_create_page('Aged page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Card', 'style' => ['#fff']]]]],
            ['component' => 'hero', 'props' => ['title' => 'Move me']],
        ]);

        $reordered = pp_execute_action('reorder_components', ['post_id' => $post_id, 'order' => [1, 0]]);
        $this->assertTrue($reordered['ok'], $reordered['error'] ?? 'reordering past a bad band must stay possible');
        $this->assertSame(['#fff'], pp_get_composition($post_id)[1]['props']['items'][0]['style'],
            'and the untouched band is still stored verbatim — a permutation is not a rewrite');

        $removed = pp_execute_action('remove_component', ['post_id' => $post_id, 'component_index' => 1]);
        $this->assertTrue($removed['ok'], $removed['error'] ?? 'removing the bad band must stay possible');
        $this->assertCount(1, pp_get_composition($post_id));
    }

    // ── §5. The boundaries ──────────────────────────────────────────────────

    /**
     * THE FOLDED-NUMERIC LIMIT, stated in a test so it is not discovered later.
     *
     * `json_decode('{"0":"a","1":"b"}', true)` returns a PHP LIST — the keys ARE 0..n-1
     * in order — so an object whose keys are exactly a 0-based run is refused where an
     * `object` is declared, even though the author wrote an object. This is #738's limit
     * seen from the other side, and separating the two would mean inspecting raw JSON
     * TEXT, which no caller still has by the time a validator runs. It costs nothing on
     * the shipped fields: a style map's keys are slot names like `--grid-item-bg`.
     */
    public function testAZeroBasedNumericObjectIsIndistinguishableFromAListAndIsRefused(): void
    {
        $decoded = json_decode('{"0":"a","1":"b"}', true);
        $this->assertTrue(pp_is_list($decoded), 'the premise: PHP folds those keys into a list');
        $this->assertFalse(_pp_schema_object_value_is_valid('object', $decoded),
            'so it is refused where an object is declared — the accepted limit, not a bug');

        // And a numeric object that is NOT a 0-based run stays a map, so the limit is
        // narrow rather than "numeric keys are banned".
        $this->assertTrue(_pp_schema_object_value_is_valid('object', json_decode('{"1":"a","0":"b"}', true)));
    }

    /**
     * BOTH FINDINGS SURVIVE ON A COLLECT-ALL SURFACE, and the write path still returns one.
     *
     * The shape rule claims `prop/<prop>/<entry>/<field>` and the per-item style engine
     * claims `item-style/<prop>/<entry>`, so a reporting surface names the shape defect
     * AND the slot defect for one list-shaped `style`. Two true sentences about one value
     * is the posture #621 and #738 both chose over suppression. The write path is
     * unaffected: budget 1, first-error-wins, and the shape rule runs first — so an
     * authoring agent gets exactly one message and it is the one about the shape.
     */
    public function testACollectAllSurfaceNamesBothTheShapeAndTheSlotDefect(): void
    {
        $post_id = pp_create_page('Findings page', 'draft');
        pp_update_composition($post_id, [['component' => 'grid', 'props' => [
            'items' => [['title' => 'Card', 'text' => 'Body', 'style' => ['#fff']]],
        ]]]);

        $messages = array_column(_pp_composition_findings(pp_get_composition($post_id)), 'message');

        $this->assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'field "style" must be an object')
        ), 'the shape rule reports');
        $this->assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'has no style slot')
        ), 'and the slot engine still reports too — a distinct claim role, so neither suppresses the other');
    }

    /**
     * A STORED list-shaped style still RENDERS, and this is why no new render guard ships
     * with this rule.
     *
     * The write gate closes the front door; what is already stored reaches the renderer
     * regardless (pre-rule compositions, `restore_composition`, raw `_pp_composition`
     * writes). `pp_render_style_vars()` hands each key to `pp_style_declaration_renders()`,
     * whose first act is `isset($slots[$name])` — a list's keys are integers, no component
     * declares a slot named "0", so every declaration is dropped before the value is cast.
     * The card renders UNSTYLED rather than taking the page down.
     *
     * Both halves are asserted. A test that checked only "the page still renders" would
     * pass just as happily against a change that started painting `0: #fff` into the
     * style attribute, which is the coercion this ruling forbids.
     */
    public function testAStoredListStyleRendersUnstyledAndDoesNotFatal(): void
    {
        $post_id = pp_create_page('Raw-written page', 'publish');
        // Deliberately NOT through an action: this is the aged/raw-meta shape.
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Aged card', 'text' => 'Body copy', 'style' => ['#fff']],
            ]]],
        ]);

        $html = $this->renderStoredComposition($post_id);

        $this->assertStringContainsString('Aged card', $html, 'the band still renders — no fatal');
        $this->assertStringContainsString('class="grid__item"', $html,
            'and the card carries no style attribute at all: every declaration was dropped');
        $this->assertStringNotContainsString('#fff', $html,
            'nothing coerces the list element into a painted value — reject, never coerce');
        $this->assertStringNotContainsString('0: ', $html,
            'and the integer key is never emitted as a custom-property name');
    }

    /**
     * A TOP-LEVEL `type: "object"` prop refuses a populated list, proven through the real
     * validator on a SYNTHETIC component.
     *
     * No shipped schema declares one (asserted in §2), so there is no other way to enter
     * that arm — and an arm no test can enter is how a fence silently stops being one.
     * This is also the ONLY place the acceptance is observable UNMASKED: both shipped
     * `object` fields are style maps, so the nested cases would still be refused (by the
     * slot engine) even if this rule were deleted. Here there is no engine behind the
     * prop, so the assertion is about this rule and nothing else.
     *
     * This case is the direct inversion of the boundary
     * ContainerPropWriteEnforcementTest::testATopLevelObjectPropRejectsAScalar...()
     * asserted before #883, and that assertion was updated in the same change.
     */
    public function testATopLevelObjectPropRefusesAListThroughTheRealValidator(): void
    {
        $this->useSyntheticComponent('widget', [
            'props' => [
                'id'     => ['type' => 'string'],
                'config' => ['type' => 'object', 'required' => false],
            ],
        ]);

        foreach ([['a', 'b'], ['#fff'], [1, 2, 3]] as $bad) {
            $rejected = pp_validate_composition([
                ['component' => 'widget', 'props' => ['id' => 'w', 'config' => $bad]],
            ]);
            $this->assertInstanceOf(WP_Error::class, $rejected,
                sprintf('a top-level object prop must refuse the list %s', var_export($bad, true)));
            $this->assertSame('invalid_prop_value', $rejected->get_error_code());
            $this->assertStringContainsString(
                sprintf(
                    'prop "config" must be an object, but this one is a JSON list (%d %s).',
                    count($bad),
                    count($bad) === 1 ? 'entry' : 'entries'
                ),
                $rejected->get_error_message(),
                'and it uses the same vocabulary the nested arm uses'
            );
        }

        // The accept side, unchanged: maps, the empty container, and the sentinels.
        foreach ([['k' => 'v'], [], null, ''] as $accepted) {
            $this->assertTrue(
                pp_validate_composition([
                    ['component' => 'widget', 'props' => ['id' => 'w', 'config' => $accepted]],
                ]) === true,
                sprintf('a top-level object prop must still accept %s', var_export($accepted, true))
            );
        }

        // And a SCALAR still gets the container rule's message, byte-identical since #507
        // — the stage order, observed at the surface rather than only at the predicate.
        $scalar = pp_validate_composition([
            ['component' => 'widget', 'props' => ['id' => 'w', 'config' => 'dark']],
        ]);
        $this->assertInstanceOf(WP_Error::class, $scalar);
        $this->assertStringContainsString('prop "config" must be an object; got string.',
            $scalar->get_error_message(),
            'a scalar keeps the container rule\'s message — this rule never races it');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Points the theme root at a throwaway directory holding ONE synthetic component, so
     * the real registry and the real validator can be driven against a schema shape this
     * repo does not ship. tearDown() restores the root and invalidates the cache.
     */
    private function useSyntheticComponent(string $name, array $schema): void
    {
        // UNPREDICTABLE AND PRIVATE: this fixture writes into shared /tmp and then
        // RECURSIVELY DELETES what it wrote, so a guessable path is a local user's
        // invitation to pre-create it as a symlink and redirect both halves.
        $this->syntheticThemeDir = sys_get_temp_dir() . '/pp-object-test-' . bin2hex(random_bytes(8));
        $dir = $this->syntheticThemeDir . '/components/' . $name;
        $this->assertTrue(mkdir($dir, 0700, true), 'the synthetic theme dir must be created fresh, not adopted');
        // The registry only registers a component whose <name>/<name>.php exists.
        file_put_contents($dir . '/' . $name . '.php', "<?php\n");
        file_put_contents($dir . '/schema.json', json_encode($schema));

        $GLOBALS['_pp_test_template_dir']                = $this->syntheticThemeDir;
        $GLOBALS['_pp_registered_components_invalidate'] = true;
    }

    /**
     * Deletes the fixture tree without following symlinks. `is_dir()` answers true for a
     * symlink POINTING at a directory, so checking is_link() FIRST is what keeps the
     * teardown inside the tree this test created.
     */
    private function recursiveDelete(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            (is_dir($child) && !is_link($child)) ? $this->recursiveDelete($child) : @unlink($child);
        }
        @rmdir($path);
    }

    /** Renders a stored composition the way templates/composition.php does. */
    private function renderStoredComposition(int $post_id): string
    {
        ob_start();
        foreach (pp_get_composition($post_id) as $item) {
            echo pp_get_component($item['component'], $item['props'] ?? []);
        }
        return (string) ob_get_clean();
    }

    /**
     * A well-formed props bag for one component, with $override merged last.
     *
     * "Otherwise complete" is load-bearing: a fixture missing a required prop is rejected
     * for the WRONG reason, and the inventory walk would then pass without ever
     * exercising the rule under test. The map is asserted complete rather than defaulted,
     * so a new composable component fails HERE by name instead of being walked against an
     * empty fixture and reporting coverage it never had.
     */
    private function wellFormedProps(string $component, array $override): array
    {
        $base = [
            'cta'          => ['button_text' => 'Go', 'button_url' => '/'],
            'embed'        => ['content' => '<iframe src="/x"></iframe>'],
            'faq'          => ['items' => [['question' => 'Q', 'answer' => 'A']]],
            'grid'         => ['items' => [['title' => 'Card', 'text' => 'Text']]],
            'hero'         => ['title' => 'Real title'],
            'logos'        => ['items' => [['image_url' => '/a.png', 'image_alt' => 'Acme']]],
            'section'      => ['body' => 'Body copy'],
            'stats'        => ['items' => [['number' => '99%', 'label' => 'Uptime']]],
            'table'        => ['headers' => ['A'], 'rows' => [['1']]],
            'testimonials' => ['items' => [['quote' => 'Great']]],
        ];
        $this->assertArrayHasKey(
            $component,
            $base,
            "add a well-formed fixture for the composable component \"{$component}\" — without one "
            . 'the schema walk would judge it against an incomplete band and report coverage it does not have'
        );
        return array_merge($base[$component], $override);
    }

    /** An items[] entry carrying every required field of the given field map. */
    private function wellFormedEntry(array $field_map): array
    {
        $entry = [];
        foreach ($field_map as $field => $def) {
            if (is_array($def) && !empty($def['required'])) {
                $entry[$field] = 'x';
            }
        }
        return $entry;
    }
}
