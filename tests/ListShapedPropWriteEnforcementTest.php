<?php
/**
 * tests/ListShapedPropWriteEnforcementTest.php
 *
 * A declared `type: "array"` means a JSON LIST at the write path, at both depths (#738).
 *
 * THE DEFECT, measured on main through the real authoring surface before the fix:
 *
 *     write                                                       verdict   stored   rendered
 *     ──────────────────────────────────────────────────────────  ────────  ───────  ────────
 *     grid.items: {"first": {"title": "C1"}, "second": {...}}      ok:true   raw      500
 *     grid.items: {"first": {"title": "C1", "number": "1"}, ...}   ok:true   raw      the band
 *     grid.items: [{"title": "C1"}, {...}]                         ok:true   raw      the band
 *
 * A schema-clean `update_composition` carrying `items` as a JSON OBJECT returned ok:true
 * with NO findings, and the public page then 500'd at components/grid/grid.php's
 * `$item['number'] ?? (string) ($index + 1)` — a string key plus an int. `??`
 * short-circuits, so a map whose every entry carries `number` rendered fine and the page
 * died only once an author deleted one field. That is the v1.16.0 invariant inverted:
 * the write path refuses what the read path calls corrupt, and no stored shape takes down
 * a page. pp_get_composition_result() has always classified a decoded non-list as
 * `unexpected_shape`; the write path was manufacturing exactly that state, through the
 * ORDINARY authoring path rather than through aged storage.
 *
 * WHY THE EXISTING RULES DID NOT CATCH IT, since three of them look like they should.
 * _pp_schema_container_value_is_valid() (#744) decides "container or scalar?", and a JSON
 * list and a JSON object BOTH decode to a PHP array under `json_decode($json, true)` — its
 * own docblock says so and names map-vs-list as nobody's rule. `item_type: "object"` walks
 * the ENTRIES, and every entry of the repro is a perfectly good object. #724's container
 * gate judges the COMPOSITION, one level up. So the shape walked through all three.
 *
 * THE RULING (D-A, canonical text in #724's body, applied one level down). REJECT, NEVER
 * COERCE: no array_values(), no reindexing, no stored-data migration. A documented
 * breaking narrowing of write acceptance — the CHANGELOG carries it with the fix command.
 *
 * WHAT THIS FILE PINS. Each entry names the section that holds it:
 *
 *   §1 THE PREDICATE — the `array` leg, the `object` leg deliberately left alone, the
 *      unset sentinels, and the not-applicable contract.
 *   §2 UNIFORM COVERAGE — that BOTH depths route through that predicate, asserted by
 *      INVENTORY off the shipped schemas (9 top-level `array` props, 1 nested `array`
 *      field today) rather than by two hand-picked cases, so a declaration landing
 *      tomorrow is covered the day it lands and cannot opt out.
 *   §3 THE AUTHORING PATH (Section 14.1) — create_page / update_composition /
 *      update_component / add_component, not raw `_pp_composition` meta writes. Each
 *      refusal is paired with the well-formed counterpart so a fixture failing for an
 *      unrelated reason cannot read as a pass, and each asserts nothing persisted.
 *   §4 THE ROUTE TABLE — which write routes the refusal covers and which deliberately
 *      bypass it. The bypasses are the load-bearing half: `restore_composition` must
 *      still restore verbatim and report rather than block (#233), or undo breaks
 *      exactly when a user most needs it.
 *   §5 THE BOUNDARIES — what must NOT change: the empty container, the folded-numeric
 *      limit, the honest per-entry locators on the REPORTING surface, and the render
 *      guard that covers what a write gate cannot reach.
 */

use PHPUnit\Framework\TestCase;

class ListShapedPropWriteEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    /**
     * The addendum's exact repro, as a decoded payload.
     *
     * The json_decode() round trip is load-bearing rather than decoration: the whole
     * premise is that a JSON object and a JSON list both become a PHP array, so a fixture
     * built only from PHP literals would never prove that the shape a real `--props`
     * payload produces is the shape the gate now refuses.
     */
    private static function repro(): array
    {
        return json_decode(
            '{"title":"Grid heading","items":{"first":{"title":"Card one"},"second":{"title":"Card two"}}}',
            true
        );
    }

    // ── §1. The predicate itself ────────────────────────────────────────────

    /** The `array` leg: lists pass, a populated map does not, the sentinels stay. */
    public function testTheListPredicateAcceptsListsAndTheUnsetSentinelsOnly(): void
    {
        foreach ([[], ['a'], ['a', 'b'], [['x' => 1]], null, '', 'a string', 42, true, 0.5] as $ok) {
            $this->assertTrue(
                _pp_schema_list_value_is_valid('array', $ok),
                'the list rule must accept ' . var_export($ok, true)
                . ' — a scalar is the container rule\'s business, not this one\'s'
            );
        }

        foreach ([['aa' => 1], ['first' => [], 'second' => []], [1 => 'a', 0 => 'b'], [5 => 'x']] as $bad) {
            $this->assertFalse(
                _pp_schema_list_value_is_valid('array', $bad),
                'a JSON object where a list belongs must be refused: ' . var_export($bad, true)
            );
        }
    }

    /**
     * A SCALAR IS NOT THIS RULE'S BUSINESS, and the division matters for the MESSAGE as
     * much as for the logic. The container rule runs first and still answers
     * "must be an array; got string" for a scalar, byte-identical to every version since
     * #507. If this predicate also rejected scalars, the two rules would race for the same
     * claim and an operator would get whichever ran first.
     *
     * THIS IS THE STAGE-ORDER PIN, not a curiosity about one predicate's return value, and
     * it asserts BOTH halves for that reason. The list predicate returning TRUE for a
     * scalar is safe only while a container check runs in front of it; a future caller
     * that reached for the list predicate ALONE to enforce `type: "array"` would silently
     * accept every scalar — the #744 defect reintroduced under a newer number. Asserting
     * only `list(scalar) === true` would document the hazard without catching it; asserting
     * the pair means the arrangement that makes it safe is what the test actually holds.
     */
    public function testAScalarIsStillTheContainerRulesToRefuse(): void
    {
        foreach (['not an array', '0', 42, 0, 3.14, true, false] as $scalar) {
            $this->assertTrue(_pp_schema_list_value_is_valid('array', $scalar));
            $this->assertFalse(_pp_schema_container_value_is_valid('array', $scalar));
        }
    }

    /**
     * THE `object` LEG IS UNTOUCHED, asserted rather than left to prose.
     *
     * A JSON LIST handed to a field declaring `object` still passes both container
     * predicates. That is the narrowing _pp_schema_container_value_is_valid()'s docblock
     * explicitly rules a DIFFERENT ruling, and a list reaching one of the two shipped
     * `object` fields is already refused by the shared style-slot engine, which reads its
     * keys as slot names. #738 closes the direction that FATALS a page; widening it here
     * would open a second one on a shape nothing has measured.
     */
    public function testTheObjectLegIsDeliberatelyNotNarrowed(): void
    {
        $this->assertTrue(_pp_schema_list_value_is_valid('object', ['#fff']));
        $this->assertTrue(_pp_schema_list_value_is_valid('object', ['--grid-item-bg' => '#111111']));
        // And the not-applicable contract every sibling predicate carries.
        foreach (['string', 'number', 'enum', 'bool', null] as $other) {
            $this->assertTrue(_pp_schema_list_value_is_valid($other, ['aa' => 1]));
        }
    }

    // ── §2. Uniform coverage across both depths, by inventory ───────────────

    /**
     * Every TOP-LEVEL `type: "array"` prop in the shipped schemas refuses a map.
     *
     * INVENTORY-DRIVEN, not case-driven, for the reason #744's sibling sweep gives: a
     * two-case pin proves the helper changed; it does not prove every array-typed schema
     * path reaches it. The count is asserted so a NEW declaration is covered the day it
     * lands and a DELETED one is noticed.
     */
    public function testEveryTopLevelArrayPropRefusesAMap(): void
    {
        $checked = [];
        foreach (self::declaredArrayProps() as [$component, $prop, $entry]) {
            // The tested prop is the ONLY defect: every other required prop of the
            // component is supplied valid, or the missing-required-prop rule (which runs
            // first) would answer instead and the sweep would assert nothing.
            $props = self::requiredSiblings($component);
            $props[$prop] = ['aa' => $entry];
            $result = pp_validate_composition([['component' => $component, 'props' => $props]]);
            $this->assertInstanceOf(
                WP_Error::class,
                $result,
                "$component.$prop declares type: array and must refuse a JSON object"
            );
            // Names the PROP, not just "must be a list". The bare phrase is not
            // discriminating: #724's composition-level refusal ("The composition must be
            // a list of components, but ...") contains it too, so a fixture that tripped
            // the composition gate instead of this rule would read green. The nested
            // sweep below already holds itself to this bar; the two halves of §2 should
            // not have different ones.
            $this->assertStringContainsString(
                "prop \"$prop\" must be a list",
                $result->get_error_message()
            );
            $checked[] = "$component.$prop";
        }

        sort($checked);
        $this->assertSame([
            'faq.items', 'grid.items', 'logos.items', 'section.body_items', 'section.panel_items',
            'stats.items', 'table.headers', 'table.rows', 'testimonials.items',
        ], $checked, 'the shipped inventory of top-level array props moved — extend the sweep, do not trim it');
    }

    /**
     * Every NESTED `type: "array"` item field refuses a map, at the depth #744 had to add
     * because the two depths were enforcing one declaration differently.
     *
     * One field is in scope today (`grid.items[].bullets`). Sweeping it by inventory
     * anyway is what stops the second one from landing uncovered.
     */
    public function testEveryNestedArrayFieldRefusesAMap(): void
    {
        $checked = [];
        foreach (self::declaredNestedArrayFields() as [$component, $prop, $field, $entry]) {
            $result = pp_validate_composition([['component' => $component, 'props' => [
                $prop => [$entry + [$field => ['aa' => 'Fast']]],
            ]]]);
            $this->assertInstanceOf(
                WP_Error::class,
                $result,
                "$component.$prop" . "[].$field declares type: array and must refuse a JSON object"
            );
            $this->assertStringContainsString('must be a list', $result->get_error_message());
            $this->assertStringContainsString("field \"$field\"", $result->get_error_message(),
                'the nested message names the field, not just the prop');
            $checked[] = "$component.$prop.$field";
        }

        $this->assertSame(['grid.items.bullets'], $checked,
            'the shipped inventory of nested array fields moved — extend the sweep, do not trim it');
    }

    /**
     * The `array`-typed props the shipped schemas actually declare, each paired with an
     * ENTRY of the shape that prop's own `item_type` accepts.
     *
     * The entry shape is derived rather than hard-coded, and it is load-bearing: the
     * bounded families run BEFORE the generic type pass (a deliberate ordering, so their
     * more precise messages win — see _pp_claim_item_finding()'s docblock). So a fixture
     * that put an object inside `section.body_items` would trip `item_type: "string"`
     * first and this sweep would assert the wrong rule while looking green. Giving each
     * prop a VALID entry leaves the container shape as the only defect, which is what the
     * sweep is for.
     */
    private static function declaredArrayProps(): array
    {
        $found = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $schema = json_decode((string) file_get_contents($file), true);
            foreach (($schema['props'] ?? []) as $prop => $def) {
                if (!is_array($def) || ($def['type'] ?? null) !== 'array') {
                    continue;
                }
                $entry = match ($def['item_type'] ?? null) {
                    'string' => 'a string entry',
                    'array'  => ['a cell'],
                    default  => ['title' => 'X'],
                };
                $found[] = [basename(dirname($file)), $prop, $entry];
            }
        }
        return $found;
    }

    /**
     * A minimal VALID value for every REQUIRED prop of a component.
     *
     * Needed because the missing-required-prop rule runs before the type pass, so a
     * `table` fixture supplying only `headers` reports "missing required prop rows" and a
     * sweep asserting `must be a list` would fail for a reason that has nothing to do
     * with the rule under test. Derived from the schema rather than hard-coded per
     * component, so a new required prop does not silently break the sweep.
     */
    private static function requiredSiblings(string $component): array
    {
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__) . "/components/$component/schema.json"),
            true
        );
        $props = [];
        foreach (($schema['props'] ?? []) as $prop => $def) {
            if (!is_array($def) || empty($def['required'])) {
                continue;
            }
            $props[$prop] = match ($def['type'] ?? null) {
                'array' => [match ($def['item_type'] ?? null) {
                    'string' => 'Cell',
                    'array'  => ['Cell'],
                    default  => self::validEntryFor($component, $prop),
                }],
                default => 'Text',
            };
        }
        return $props;
    }

    /** One entry that satisfies a component's own required ITEM fields. */
    private static function validEntryFor(string $component, string $prop): array
    {
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__) . "/components/$component/schema.json"),
            true
        );
        $entry = [];
        foreach (($schema['props'][$prop]['items'] ?? []) as $field => $def) {
            if (is_array($def) && !empty($def['required'])) {
                $entry[$field] = 'Text';
            }
        }
        return $entry ?: ['title' => 'X'];
    }

    /**
     * The nested `array`-typed item fields, with a minimal VALID sibling entry so the
     * refusal under test is the only thing the fixture trips.
     */
    private static function declaredNestedArrayFields(): array
    {
        $entries = ['grid' => ['items' => ['title' => 'Card']]];
        $found   = [];
        foreach (glob(dirname(__DIR__) . '/components/*/schema.json') as $file) {
            $component = basename(dirname($file));
            $schema    = json_decode((string) file_get_contents($file), true);
            foreach (($schema['props'] ?? []) as $prop => $def) {
                if (!is_array($def) || !is_array($def['items'] ?? null)) {
                    continue;
                }
                foreach ($def['items'] as $field => $field_def) {
                    if (is_array($field_def) && ($field_def['type'] ?? null) === 'array') {
                        $found[] = [$component, $prop, $field, $entries[$component][$prop] ?? []];
                    }
                }
            }
        }
        return $found;
    }

    // ── §3. The authoring path (Section 14.1) ───────────────────────────────

    /**
     * THE ADDENDUM'S REPRO, through the action it was measured on.
     *
     * This is the case the whole issue is about: on main this returned ok:true with an
     * empty findings array and left the page rendering a 500. Section 14.1 requires it to
     * run through the real surface rather than a raw `_pp_composition` write, because
     * tests/bootstrap.php accepts an already-decoded array on raw writes and so bypasses
     * the decode filter — which is exactly how a schema-contract defect escaped in #488.
     */
    public function testUpdateCompositionRefusesTheReproAndStoresNothing(): void
    {
        $post_id = pp_create_page('Existing page', 'draft');
        $before  = [['component' => 'grid', 'props' => [
            'id' => 'pp-keep', 'title' => 'Grid heading', 'items' => [['title' => 'Original card']],
        ]]];
        pp_update_composition($post_id, $before);
        $stored_before = $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'];

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'grid', 'props' => self::repro()]],
        ]);

        $this->assertFalse($result['ok'], 'the repro must no longer return ok:true');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        // The standard envelope: band, then prop, then what to send instead.
        $this->assertStringContainsString('Component 0 ("grid")', $result['error']);
        $this->assertStringContainsString('prop "items" must be a list', $result['error']);
        $this->assertStringContainsString('JSON object (2 entries)', $result['error']);
        $this->assertStringContainsString('Send it as an array ([...])', $result['error']);

        $this->assertSame(
            $stored_before,
            $GLOBALS['_pp_test_store']['post_meta'][$post_id]['_pp_composition'],
            'a rejected write stores nothing — not even partially'
        );
    }

    /**
     * The WELL-FORMED counterpart, same cards, same action. Paired with the refusal above
     * so a fixture that started failing for an unrelated reason cannot read as a pass.
     */
    public function testUpdateCompositionStillAcceptsTheListForm(): void
    {
        $post_id = pp_create_page('Existing page', 'draft');
        pp_update_composition($post_id, [['component' => 'grid', 'props' => ['items' => [['title' => 'x']]]]]);

        $decoded = json_decode(
            '{"title":"Grid heading","items":[{"title":"Card one"},{"title":"Card two"}]}',
            true
        );
        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'grid', 'props' => $decoded]],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'the list form must still be accepted');
        $items = pp_get_composition($post_id)[0]['props']['items'];
        $this->assertTrue(pp_is_list($items), 'and it is stored as a list');
        $this->assertSame('Card one', $items[0]['title']);
        $this->assertSame('Card two', $items[1]['title']);
    }

    /**
     * THE NESTED DEPTH, THROUGH A REAL ACTION (Section 14.1), which the repro cases above
     * do not reach.
     *
     * Every §3 case so far sends a TOP-LEVEL `items` map, so they all exercise the
     * top-level arm. RULE 6b — the nested `grid.items[].bullets` rule — was reached only
     * by a direct pp_validate_composition() call, which is exactly the shape of coverage
     * 14.1 exists to reject: the authoring contract has to be exercised through the
     * surface an author actually uses, because tests/bootstrap.php accepts an
     * already-decoded array on raw writes and can hide a contract defect (the #488
     * lesson). A rule enforced only in a unit test is a rule nobody has watched reach an
     * envelope.
     */
    public function testUpdateCompositionRefusesAKeyedObjectAtTheNESTEDDepth(): void
    {
        $post_id = pp_create_page('Nested depth', 'draft');
        pp_update_composition($post_id, [['component' => 'grid', 'props' => [
            'items' => [['title' => 'Card', 'bullets' => ['Fast', 'Honest']]],
        ]]]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'grid', 'props' => [
                'items' => [['title' => 'Card', 'bullets' => json_decode('{"aa":"Fast"}', true)]],
            ]]],
        ]);

        $this->assertFalse($result['ok'], 'the nested rule must refuse through the real action too');
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('field "bullets"', $result['error'],
            'and the message names the FIELD, not just the prop');
        $this->assertStringContainsString('must be a list', $result['error']);
        $this->assertSame(
            ['Fast', 'Honest'],
            pp_get_composition($post_id)[0]['props']['items'][0]['bullets'],
            'a refused nested write leaves the stored list untouched'
        );
    }

    /** create_page — the other whole-composition verb, refusing the same payload. */
    public function testCreatePageRefusesTheReproAndCreatesNoPage(): void
    {
        $before = count($GLOBALS['_pp_test_store']['posts']);

        $result = pp_execute_action('create_page', [
            'title'       => 'Should not exist',
            'composition' => [['component' => 'grid', 'props' => self::repro()]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('prop "items" must be a list', $result['error']);
        $this->assertCount($before, $GLOBALS['_pp_test_store']['posts'], 'a refused create_page creates nothing');
    }

    /**
     * update_component — the action an agent reaches for most often when repairing one
     * band, and the one that validates the MERGED whole composition rather than the patch.
     */
    public function testUpdateComponentRefusesTheReproAndLeavesTheBandUntouched(): void
    {
        $post_id = pp_create_page('Patch me', 'draft');
        pp_update_composition($post_id, [['component' => 'grid', 'props' => [
            'id' => 'pp-target', 'title' => 'Grid heading', 'items' => [['title' => 'Original card']],
        ]]]);

        $result = pp_execute_action('update_component', [
            'post_id'      => $post_id,
            'component_id' => 'pp-target',
            'props'        => ['items' => self::repro()['items']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('prop "items" must be a list', $result['error']);
        $this->assertSame(
            'Original card',
            pp_get_composition($post_id)[0]['props']['items'][0]['title'],
            'a refused patch leaves the stored band exactly as it was'
        );
    }

    /**
     * add_component — judged by pp_validate_composition_item(), which wraps the ONE new
     * band in a synthetic array and runs the same shared engine. So the refusal reaches it
     * without a second validator, and the message carries no band locator (there is no
     * real band offset to name yet, #642).
     */
    public function testAddComponentRefusesTheRepro(): void
    {
        $post_id = pp_create_page('Append to me', 'draft');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result = pp_execute_action('add_component', [
            'post_id'   => $post_id,
            'component' => 'grid',
            'props'     => self::repro(),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('prop "items" must be a list', $result['error']);
        $this->assertCount(1, pp_get_composition($post_id), 'nothing was appended');
    }

    /**
     * THE CLAIM-COLLISION PIN. Both defects are named in ONE pass, on the two props where
     * the collision was real.
     *
     * `section.body_items` and `table.headers` declare `item_type: "string"`, and the #475
     * family that enforces it claims `prop/<prop>` and runs BEFORE this rule. While this
     * rule shared that namespace, a keyed object holding a non-string entry reported only
     * `items must be strings; got integer` — the container defect was claim-suppressed, so
     * an operator fixed the entry, re-sent, and only then learned the container was a JSON
     * object. A guaranteed two-round repair (#621's class). The fix is a distinct role
     * segment (`list-shape`), the same move the per-item style engine made with
     * `item-style`.
     *
     * Asserted through the collect-all engine rather than the write path deliberately: the
     * write is refused in round one either way (first-error-wins, and a suppressed claim
     * always implies a sibling error already exists), so the defect was never an
     * enforcement hole. It was a DIAGNOSTIC hole, and the diagnostic surface is where it
     * has to be pinned — `wp pp check page` and the restore/rollback findings are the
     * readers that were losing a finding.
     */
    public function testAKeyedObjectAndABadEntryAreBothNamedInOnePass(): void
    {
        foreach ([['section', 'body_items'], ['table', 'headers']] as [$component, $prop]) {
            $props = self::requiredSiblings($component);
            $props[$prop] = ['a' => 1];   // keyed object AND a non-string entry
            $messages = array_map(
                static fn (WP_Error $e): string => $e->get_error_message(),
                pp_validate_composition_errors([['component' => $component, 'props' => $props]])
            );

            $this->assertNotEmpty(array_filter(
                $messages,
                static fn (string $m): bool => str_contains($m, "prop \"$prop\" must be a list")
            ), "$component.$prop: the container defect must not be swallowed by the entry rule");
            $this->assertNotEmpty(array_filter(
                $messages,
                static fn (string $m): bool => str_contains($m, 'must be strings')
            ), "$component.$prop: and the entry defect must still be reported");
        }
    }

    // ── §4. The route table: what refuses, and what deliberately bypasses ───

    /**
     * restore_composition IS NEVER BLOCKED BY CURRENT VALIDATION RULES (#233), and this is
     * the most important assertion in the file.
     *
     * Undo is wired to restore. A restore that current rules refuse would fail exactly
     * when a user most needs it — and #738's whole population of already-stored maps is
     * reachable through history rings, so a narrowing that blocked restore would strand
     * the very pages it exists to name. Restore replays a snapshot VERBATIM and REPORTS
     * rule violations in `findings` instead of vetoing them.
     */
    public function testRestoreCompositionRestoresAMapAndReportsRatherThanBlocking(): void
    {
        $post_id = pp_create_page('History page', 'draft');
        // v1 holds the map — pp_update_composition() is the storage writer, not a
        // validator, which is precisely how a pre-rule composition got into a ring. The
        // dead `link_url` on one card is deliberate: it gives the snapshot a SECOND,
        // per-entry defect, which is what lets the findings assertion below prove the
        // keyed locator survives to the restore envelope rather than only proving that
        // the container refusal does.
        pp_update_composition($post_id, [['component' => 'grid', 'props' => [
            'title' => 'Grid heading',
            'items' => [
                'first'  => ['title' => 'Card one', 'link_url' => 'javascript:alert(1)'],
                'second' => ['title' => 'Card two'],
            ],
        ]]]);
        pp_update_composition($post_id, [['component' => 'grid', 'props' => [
            'title' => 'Grid heading', 'items' => [['title' => 'Clean card']],
        ]]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'version' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore must not be blocked by a write rule');
        $restored = pp_get_composition($post_id)[0]['props']['items'];
        $this->assertSame(['first', 'second'], array_keys($restored),
            'and it is restored VERBATIM — no reindexing, no coercion (D-A)');

        // REPORTS, not just "does not block", which is the half the #233 contract is
        // actually about and the half a silent regression would eat. A restore that
        // stopped emitting findings would still return ok:true and still restore the
        // bytes, so the assertion above cannot see it — and `restore_composition` is the
        // reader most likely to meet an already-stored map.
        $messages = array_column($result['findings'] ?? [], 'message');
        $this->assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'prop "items" must be a list')
        ), 'the restore envelope must REPORT the container defect it declined to block on');
        $this->assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'item key "first" field "link_url"')
        ), 'and the per-entry finding keeps its honest keyed locator (#634/#652) on this surface');
    }

    /**
     * reorder_components is the second unvalidated permutation verb, and it gets its own
     * case rather than riding on remove_component's.
     *
     * The §4 docblock makes a TWO-verb claim, and a prose claim with one test behind it is
     * how the untested half quietly stops being true: a future guardrail that started
     * validating on reorder would strand exactly the repair route this file documents,
     * with the suite green.
     */
    public function testReorderComponentsStillWorksOnAPageHoldingAMap(): void
    {
        $post_id = pp_create_page('Corrupt page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => self::repro()],
            ['component' => 'hero', 'props' => ['title' => 'Move me']],
        ]);

        $result = pp_execute_action('reorder_components', ['post_id' => $post_id, 'order' => [1, 0]]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'reordering past a bad band must stay possible');
        $after = pp_get_composition($post_id);
        $this->assertSame('hero', $after[0]['component']);
        $this->assertSame('grid', $after[1]['component']);
        $this->assertSame(['first', 'second'], array_keys($after[1]['props']['items']),
            'and the untouched band is still stored verbatim — a permutation is not a rewrite');
    }

    /**
     * The two permutation verbs are NOT gated either, and that is deliberate rather than
     * an oversight: remove_component and reorder_components run no composition
     * validation, so a page already holding a map can still have that band removed or
     * moved. Gating them would make a corrupt page unrepairable by the two actions most
     * likely to repair it.
     */
    public function testRemoveComponentStillWorksOnAPageHoldingAMap(): void
    {
        $post_id = pp_create_page('Corrupt page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => self::repro()],
            ['component' => 'hero', 'props' => ['title' => 'Keep me']],
        ]);

        $result = pp_execute_action('remove_component', ['post_id' => $post_id, 'component_index' => 0]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'removing the bad band must stay possible');
        $remaining = pp_get_composition($post_id);
        $this->assertCount(1, $remaining);
        $this->assertSame('hero', $remaining[0]['component']);
    }

    /**
     * THE ACCEPTED COST, stated as a test rather than only in the CHANGELOG: every action
     * that validates the WHOLE composition judges bands the caller did not touch, so a
     * page already holding a map blocks edits to unrelated bands until it is repaired.
     *
     * That is the same cost every rule in this family carries (#744's comment says so),
     * and the repair routes are the ones asserted above: replace the whole composition
     * through update_composition, restore an older version, or remove the band.
     */
    public function testAStoredMapBlocksEditsToAnUnrelatedBandUntilItIsRepaired(): void
    {
        $post_id = pp_create_page('Half-corrupt page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => self::repro()],
            ['component' => 'hero', 'props' => ['id' => 'pp-hero', 'title' => 'Old title']],
        ]);

        $blocked = pp_execute_action('update_component', [
            'post_id'      => $post_id,
            'component_id' => 'pp-hero',
            'props'        => ['title' => 'New title'],
        ]);
        $this->assertFalse($blocked['ok'], 'whole-composition validation judges the stored band too');
        $this->assertStringContainsString('prop "items" must be a list', $blocked['error']);

        // The repair route, and then the same edit succeeding — an accepted cost has to
        // have a documented way out, or it is a lockout.
        $repaired = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [
                ['component' => 'grid', 'props' => ['title' => 'Grid heading', 'items' => [['title' => 'Card one']]]],
                ['component' => 'hero', 'props' => ['id' => 'pp-hero', 'title' => 'Old title']],
            ],
        ]);
        $this->assertTrue($repaired['ok'], $repaired['error'] ?? 'the documented repair must work');

        $after = pp_execute_action('update_component', [
            'post_id'      => $post_id,
            'component_id' => 'pp-hero',
            'props'        => ['title' => 'New title'],
        ]);
        $this->assertTrue($after['ok'], $after['error'] ?? 'and the blocked edit then succeeds');
    }

    // ── §5. Boundaries ──────────────────────────────────────────────────────

    /**
     * THE EMPTY CONTAINER IS ACCEPTED, and the reason is not leniency: `{}` and `[]`
     * decode identically under `json_decode($json, true)`, so no validator can tell them
     * apart. It is accepted as the empty list it is indistinguishable from — the same
     * answer #724 gives one level up for the same reason.
     */
    public function testAnEmptyContainerIsAcceptedBecauseItIsIndistinguishableFromAList(): void
    {
        $this->assertSame(json_decode('{}', true), json_decode('[]', true), 'the premise');

        $post_id = pp_create_page('Empty items', 'draft');
        $result  = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'grid', 'props' => json_decode('{"title":"G","items":{}}', true)]],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'an empty container stays accepted');
    }

    /**
     * THE FOLDED-NUMERIC LIMIT, stated so it is not discovered later.
     * `json_decode('{"0":a,"1":b}', true)` returns a PHP LIST — the keys ARE 0..n-1 in
     * order, and the object/list distinction is destroyed before any PHP here can see it.
     * That case is also the harmless one: key and position agree, so the renderer's
     * ordinal is right either way.
     *
     * It is not fixable at THIS layer rather than unfixable in principle — separating the
     * two would mean inspecting raw JSON TEXT, and every caller reaches pp_execute_action()
     * with an already-decoded PHP array. Same limit #652 and #724 recorded.
     */
    public function testAnOrderedNumericObjectIsAcceptedBecauseItDecodesAsAList(): void
    {
        $decoded = json_decode('{"title":"G","items":{"0":{"title":"A"},"1":{"title":"B"}}}', true);
        $this->assertTrue(pp_is_list($decoded['items']), 'the premise: PHP hands this back as a list');

        $post_id = pp_create_page('Folded numeric', 'draft');
        $result  = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'grid', 'props' => $decoded]],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'a folded numeric object is a list by the time we see it');

        // And a REORDERED one is not, which is the half that is still enforceable.
        $reordered = json_decode('{"title":"G","items":{"1":{"title":"B"},"0":{"title":"A"}}}', true);
        $this->assertFalse(pp_is_list($reordered['items']));
        $this->assertInstanceOf(WP_Error::class, pp_validate_composition(
            [['component' => 'grid', 'props' => $reordered]]
        ));
    }

    /**
     * NO KEY TEXT REACHES THE OPERATOR through this message — it reflects the entry COUNT
     * and nothing else, exactly as #724's composition-level refusal does. So the
     * #633/#647 reflected-value bounding question does not arise for this rule at all, and
     * a hostile stored key cannot ride it into a terminal.
     *
     * The per-ENTRY rules still name keys, still bounded by #649's owner — asserted in
     * tests/DiagnosticReachTest.php, which is where that contract lives.
     */
    public function testTheRefusalReflectsTheCountAndNeverTheKeys(): void
    {
        $hostile = "aa\x1b[31m\nWARNING: fake";
        $result  = pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [$hostile => ['title' => 'X']]]],
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $message = $result->get_error_message();
        $this->assertStringContainsString('JSON object (1 entry)', $message);
        $this->assertStringNotContainsString('aa', $message, 'the key is not echoed at all');
        $this->assertStringNotContainsString("\x1b", $message);
        $this->assertStringNotContainsString("\n", $message);
    }

    /** Singular/plural, because an operator reading "1 entries" reads a bug. */
    public function testTheCountIsPluralised(): void
    {
        $one = pp_validate_composition([['component' => 'grid', 'props' => ['items' => ['aa' => ['title' => 'X']]]]]);
        $this->assertStringContainsString('(1 entry)', $one->get_error_message());

        $two = pp_validate_composition([['component' => 'grid', 'props' => ['items' => [
            'aa' => ['title' => 'X'], 'bb' => ['title' => 'Y'],
        ]]]]);
        $this->assertStringContainsString('(2 entries)', $two->get_error_message());
    }

    /**
     * THIS CLOSES THE FRONT DOOR; IT DOES NOT REPAIR WHAT IS ALREADY INSIDE. The render
     * guard is the other half and stays load-bearing — pinned in full by
     * tests/StoredGridItemsMapRenderGuardTest.php, and restated here as one end-to-end
     * case so the two mechanisms are visibly a pair rather than two independent changes.
     */
    public function testWhatTheWriteGateCannotReachStillRendersInsteadOfFataling(): void
    {
        $post_id = pp_create_page('Aged storage', 'draft');
        pp_update_composition($post_id, [['component' => 'grid', 'props' => self::repro()]]);

        ob_start();
        try {
            foreach (pp_get_composition($post_id) as $item) {
                pp_get_component((string) $item['component'], $item['props']);
            }
        } finally {
            $html = ob_get_clean();
        }

        $this->assertStringContainsString('Card one', $html);
        $this->assertStringContainsString('Card two', $html);
    }
}
