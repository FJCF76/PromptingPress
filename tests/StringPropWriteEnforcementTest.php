<?php
/**
 * tests/StringPropWriteEnforcementTest.php
 *
 * `type: "string"` means a PHP string at the write path, at both depths (#707).
 *
 * THE DEFECT. `_pp_schema_scalar_value_is_valid()` — the ONE predicate the #507
 * top-level prop pass and the #614 nested items[] field pass both call — read
 * `is_scalar()` for `string`. So the declaration said `string` and the gate enforced
 * "not a container". Measured through the sanctioned action, at both depths:
 *
 *     stored image_url   create_page          value read back   PHP type
 *     ─────────────────  ───────────────────  ────────────────  ────────
 *     42                 ok:true, no error    42                integer
 *     3.14               ok:true, no error    3.14              double
 *     true               ok:true, no error    true              boolean
 *     false              ok:true, no error    false             boolean
 *     ['attachment_id']  ok:false             --                --
 *
 * `_pp_composition_findings()` reported NOTHING for the four accepted shapes, so an
 * agent wrote `image_url: 42`, was told it worked, and the page painted
 * `<img src="42">` with no diagnostic anywhere behind it. The same gap let the
 * v1.15.7 smoke measure `section.panel_cta_url: false` -> ok:true, zero findings,
 * and a button rendered with an empty href.
 *
 * THE RULING (D-A, canonical text in #724's body, applied to #707). REJECT, NEVER
 * COERCE: the write path refuses a non-string scalar with the standard
 * `invalid_prop_value` envelope the array rejection already used, at BOTH depths.
 * No write-time coercion, no stored-data migration. A documented breaking narrowing
 * of write acceptance.
 *
 * WHAT THIS FILE PINS, and why each half is here rather than assumed:
 *
 *   1. THE PREDICATE, at both depths, over EVERY shipped declaration — 61 top-level
 *      string props and 22 nested items[] string fields across the 10 composable
 *      components. A two-case pin would prove the helper changed; it would not prove
 *      that every string-typed schema path actually routes THROUGH the helper. The
 *      walks below are schema-driven, so a declaration landing tomorrow is covered
 *      the day it lands and cannot quietly opt out.
 *   2. THE AUTHORING PATH (Section 14.1): create_page / update_composition /
 *      update_component, not raw `_pp_composition` meta writes — the surfaces an
 *      agent actually reaches for. Each rejection is paired with the well-formed
 *      counterpart, so a fixture that fails for an unrelated reason cannot read as
 *      a pass, and each asserts that NOTHING persisted.
 *   3. THE ACCEPT SIDE, which decides whether the narrowing is shippable at all:
 *      real strings, the empty string, and the `null` unset sentinel must survive.
 *      `null` is not a hole in the rule — `is_scalar(null)` is false, so it never
 *      travelled the scalar arm; it is the sentinel that keeps a rule which
 *      validates the WHOLE composition from blocking edits to unrelated bands.
 *   4. THE BOUNDARIES the narrowing must NOT cross: `restore_composition` still
 *      restores verbatim and reports rather than blocks (#233), the `number` leg
 *      still accepts a numeric string, and the render-side `is_scalar()` guards
 *      still degrade a STORED non-string scalar instead of fataling — this closes
 *      the front door those guards were built to survive, it does not replace them.
 *   5. THE DIAGNOSTIC HALF: `_pp_composition_findings()` now NAMES the shape it used
 *      to be silent about. That is the reporting entry point `wp pp check page` and
 *      `wp pp validate site` both read (#622), so an operator holding aged content
 *      is told which prop to repair rather than discovering it through a blocked write.
 */

use PHPUnit\Framework\TestCase;

class StringPropWriteEnforcementTest extends TestCase
{
    /**
     * The non-string scalars, one list, used by every case below.
     *
     * `-0.0` earns its place: `wp_json_encode(-0.0)` emits `-0`, which decodes back
     * to int 0 — a FALSY non-string scalar, the shape a truthiness gate waves
     * through while an `is_string()` gate does not.
     */
    private const NON_STRING_SCALARS = [42, 3.14, -1, 0, -0.0, true, false];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    // ── 1. The predicate itself, both depths ────────────────────────────────

    /** The shared predicate rejects every non-string scalar and keeps its sentinel. */
    public function testTheSharedPredicateAcceptsStringsAndTheNullSentinelOnly(): void
    {
        foreach (self::NON_STRING_SCALARS as $bad) {
            $this->assertFalse(
                _pp_schema_scalar_value_is_valid('string', $bad),
                sprintf('type:string must reject %s', var_export($bad, true))
            );
        }
        foreach (['text', '', '0', '42', 'true'] as $ok) {
            $this->assertTrue(_pp_schema_scalar_value_is_valid('string', $ok));
        }
        $this->assertTrue(
            _pp_schema_scalar_value_is_valid('string', null),
            'null is the unset sentinel and stays accepted — it keeps a whole-composition rule from blocking unrelated bands'
        );
        foreach ([[], ['a'], ['k' => 'v']] as $container) {
            $this->assertFalse(_pp_schema_scalar_value_is_valid('string', $container));
        }
    }

    /**
     * The `number` leg is DELIBERATELY untouched: a JSON/CLI write sends `"3"`, and
     * the #379 bounds family already accepts that shape for grid.columns. The two
     * arms are asymmetric on purpose, and a future "make them consistent" refactor
     * would break every numeric-string write in the wild.
     */
    public function testTheNumberLegStillAcceptsNumericStrings(): void
    {
        foreach (['3', '42', '0', 3, 42.5, 0, null, ''] as $ok) {
            $this->assertTrue(
                _pp_schema_scalar_value_is_valid('number', $ok),
                sprintf('type:number must still accept %s', var_export($ok, true))
            );
        }
        $this->assertFalse(_pp_schema_scalar_value_is_valid('number', true));
        $this->assertFalse(_pp_schema_scalar_value_is_valid('number', 'abc'));
    }

    // ── 2. Uniform coverage over every shipped declaration ──────────────────

    /**
     * EVERY top-level `type: "string"` prop is enforced — 61 of them today, walked
     * from the schemas rather than listed here so the pin cannot go stale.
     *
     * Chrome (`nav`, `footer`) is excluded because this engine never judges it: a
     * template-owned component is rejected on its identity four rules earlier, so
     * including it would assert a rejection that comes from the wrong rule.
     */
    public function testEveryTopLevelStringPropIsEnforcedAcrossTheShippedSchemas(): void
    {
        $checked = 0;
        foreach (pp_composable_components() as $component => $schema) {
            foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                if (!is_array($prop_def) || ($prop_def['type'] ?? null) !== 'string') {
                    continue;
                }
                $band = [
                    'component' => $component,
                    'props'     => $this->wellFormedProps($component, [$prop_name => 42]),
                ];
                $this->assertInstanceOf(
                    WP_Error::class,
                    pp_validate_composition([$band]),
                    "{$component}.{$prop_name} declares type:string and must reject a non-string scalar"
                );
                // Assert against the COLLECT-ALL engine, not just errors[0]. The write
                // path is first-error-wins, so a fixture that started failing an earlier
                // rule for an unrelated reason could otherwise satisfy a first-error
                // assertion and leave this prop's own rule unexercised. Requiring a
                // finding that names THIS prop AND says "must be a string" is immune to
                // rule order: no other rule in the engine produces that pairing.
                $this->assertNotSame(
                    [],
                    array_filter(
                        pp_validate_composition_errors([$band]),
                        static fn ($e) => $e->get_error_code() === 'invalid_prop_value'
                            && str_contains($e->get_error_message(), sprintf('prop "%s"', $prop_name))
                            && str_contains($e->get_error_message(), 'must be a string')
                    ),
                    "the rejection for {$component}.{$prop_name} must name that prop and that rule"
                );
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(
            61,
            $checked,
            'the shipped schemas declare 61 composable top-level string props; a lower count means the walk stopped finding them'
        );
    }

    /** The same walk one level down: every nested items[] `type: "string"` field (22 today). */
    public function testEveryNestedStringFieldIsEnforcedAcrossTheShippedSchemas(): void
    {
        $checked = 0;
        foreach (pp_composable_components() as $component => $schema) {
            foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                if (!is_array($prop_def)
                    || ($prop_def['type'] ?? null) !== 'array'
                    || !is_array($prop_def['items'] ?? null)
                ) {
                    continue;
                }
                foreach ($prop_def['items'] as $field => $field_def) {
                    if (!is_array($field_def) || ($field_def['type'] ?? null) !== 'string') {
                        continue;
                    }
                    $entry = $this->wellFormedEntry($prop_def['items']);
                    $entry[$field] = 42;

                    $band = [
                        'component' => $component,
                        'props'     => $this->wellFormedProps($component, [$prop_name => [$entry]]),
                    ];
                    $this->assertInstanceOf(
                        WP_Error::class,
                        pp_validate_composition([$band]),
                        "{$component}.{$prop_name}[].{$field} declares type:string and must be enforced"
                    );
                    // Same order-independent form as the top-level walk, and the nested
                    // message has to carry the whole repair ADDRESS — prop, item index
                    // and field — because "a string field somewhere is wrong" is not
                    // something an operator can act on.
                    $this->assertNotSame(
                        [],
                        array_filter(
                            pp_validate_composition_errors([$band]),
                            static fn ($e) => $e->get_error_code() === 'invalid_prop_value'
                                && str_contains($e->get_error_message(), sprintf('prop "%s"', $prop_name))
                                && str_contains($e->get_error_message(), 'item 0')
                                && str_contains($e->get_error_message(), sprintf('field "%s"', $field))
                                && str_contains($e->get_error_message(), 'must be a string')
                        ),
                        "the rejection for {$component}.{$prop_name}[].{$field} must name prop, item and field"
                    );
                    $checked++;
                }
            }
        }
        $this->assertGreaterThanOrEqual(
            22,
            $checked,
            'the shipped schemas declare 22 composable nested string fields; a lower count means the walk stopped finding them'
        );
    }

    // ── 3. The authoring path (Section 14.1) ────────────────────────────────

    /**
     * create_page refuses a top-level non-string scalar and writes nothing.
     *
     * @dataProvider nonStringScalars
     */
    public function testCreatePageRejectsATopLevelNonStringScalar($bad): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Rejected at creation',
            'composition' => [[
                'component' => 'hero',
                'props'     => ['title' => 'Real title', 'image_url' => $bad, 'image_alt' => 'A'],
            ]],
        ]);

        $this->assertFalse($result['ok'], sprintf('image_url %s must not persist behind ok:true', var_export($bad, true)));
        $this->assertSame('invalid_prop_value', $result['error_code'],
            'the rejection must carry the standard machine-readable code, not just a message');
        $this->assertStringContainsString('image_url', $result['error']);
        $this->assertStringContainsString('must be a string', $result['error']);
    }

    /**
     * The nested depth through the same action — the second half of the measured
     * table, and the half a top-level-only fix would have left open.
     *
     * @dataProvider nonStringScalars
     */
    public function testCreatePageRejectsANestedNonStringScalar($bad): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Rejected at creation, nested',
            'composition' => [[
                'component' => 'logos',
                'props'     => ['items' => [['image_url' => $bad, 'image_alt' => 'Acme']]],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('image_url', $result['error']);
        $this->assertStringContainsString('item 0', $result['error']);
        $this->assertStringContainsString('must be a string', $result['error']);
    }

    /** The well-formed counterpart: the same write with a string value is accepted and stored. */
    public function testCreatePageStillAcceptsTheWellFormedCounterpart(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Accepted at creation',
            'composition' => [
                ['component' => 'hero', 'props' => ['title' => 'Real title', 'image_url' => '/a.png', 'image_alt' => 'A']],
                ['component' => 'logos', 'props' => ['items' => [['image_url' => '/b.png', 'image_alt' => 'Acme']]]],
            ],
        ]);

        $this->assertTrue($result['ok'], 'a well-formed string write must still be accepted');
        $stored = pp_get_composition($result['target']['post_id']);
        $this->assertSame('/a.png', $stored[0]['props']['image_url']);
        $this->assertSame('/b.png', $stored[1]['props']['items'][0]['image_url']);
    }

    /**
     * update_composition refuses the same shapes and leaves the stored composition
     * untouched — a rejected write must not be a partial write.
     *
     * @dataProvider nonStringScalars
     */
    public function testUpdateCompositionRejectsANonStringScalarAndStoresNothing($bad): void
    {
        $post_id = pp_create_page('Existing page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Original']],
        ]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'hero', 'props' => ['title' => $bad]]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must be a string', $result['error']);
        $this->assertSame(
            'Original',
            pp_get_composition($post_id)[0]['props']['title'],
            'a rejected write must leave the stored composition untouched'
        );
    }

    /**
     * update_component is the third whole-composition-validating action, and the one
     * an agent reaches for most often when repairing a single band. It must refuse
     * the same shape rather than merging it in.
     */
    public function testUpdateComponentRejectsANonStringScalar(): void
    {
        $post_id = pp_create_page('Page with a band to edit', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'cta', 'props' => ['id' => 'closer', 'title' => 'Original', 'button_text' => 'Go', 'button_url' => '/']],
        ]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
            'props'           => ['title' => 42],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('title', $result['error']);
        $this->assertStringContainsString('must be a string', $result['error']);
        $this->assertSame('Original', pp_get_composition($post_id)[0]['props']['title']);
    }

    /**
     * The measured v1.15.7 smoke case, pinned by number.
     *
     * `section.panel_cta_url: false` returned ok:true with ZERO findings and rendered
     * a button with an empty href (#730's CHANGELOG records the rendering half). It is
     * a `format: "link_url"` prop, and `_pp_link_url_is_valid()` returns TRUE for any
     * non-string — so the link family never judged this value and never could. The
     * string-type rule runs earlier in the same engine and claims the prop, which is
     * what closes it: one finding, from the rule that owns the shape.
     */
    public function testTheMeasuredFalsePanelCtaUrlIsNowRejected(): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'The smoke fixture shape',
            'composition' => [[
                'component' => 'section',
                'props'     => ['body' => 'Body copy', 'panel_cta_text' => 'Book', 'panel_cta_url' => false],
            ]],
        ]);

        $this->assertFalse($result['ok'], 'panel_cta_url false must no longer be accepted');
        $this->assertStringContainsString('panel_cta_url', $result['error']);
        $this->assertStringContainsString('must be a string', $result['error']);
    }

    /**
     * `add_component` is the fourth sanctioned write verb and one of the four the new
     * `lib/ai-context.php` paragraph names, so the rule has to hold there too. It is the
     * odd one out structurally: it validates only the ITEM it adds, through
     * `pp_validate_composition_item()` rather than the whole composition, so it reaches
     * the rule by a different route than the three above and could regress alone.
     */
    public function testAddComponentRejectsANonStringScalarAtBothDepths(): void
    {
        $post_id = pp_create_page('Page to append to', 'draft');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'T']]]);

        $top = pp_execute_action('add_component', [
            'post_id' => $post_id, 'component' => 'cta',
            'props'   => ['title' => 42, 'button_text' => 'Go', 'button_url' => '/'],
        ]);
        $this->assertFalse($top['ok']);
        $this->assertStringContainsString('title', $top['error']);
        $this->assertStringContainsString('must be a string', $top['error']);

        $nested = pp_execute_action('add_component', [
            'post_id' => $post_id, 'component' => 'logos',
            'props'   => ['items' => [['image_url' => 42, 'image_alt' => 'Acme']]],
        ]);
        $this->assertFalse($nested['ok']);
        $this->assertStringContainsString('image_url', $nested['error']);
        $this->assertStringContainsString('must be a string', $nested['error']);

        $this->assertCount(1, pp_get_composition($post_id), 'neither rejected append may persist');
    }

    /**
     * THE TWO DEPTHS MUST NAME THE REJECTED SHAPE THE SAME WAY.
     *
     * #614 shared one predicate between the top-level pass and the nested items[] pass so
     * they could not disagree about what `string` accepts. A shared verdict rendered in two
     * vocabularies is that same drift one layer up, and #707 nearly introduced it: the
     * nested leg echoes the VALUE through _pp_schema_value_for_message(), which is right
     * for `number` (a rejected "abc" is repaired by looking at "abc") and actively
     * misleading once `string` starts rejecting scalars — `must be a string; got "42"`
     * tells an authoring agent the refused value already looks like a string. Before #707
     * the leg only fired for containers, where the helper degrades to a bare type name, so
     * the depths agreed by accident. Now they agree on purpose, and this pin is why.
     */
    public function testBothDepthsNameTheRejectedShapeIdentically(): void
    {
        foreach ([[42, 'integer'], [3.14, 'double'], [true, 'boolean'], [false, 'boolean']] as [$bad, $shape]) {
            $top = pp_validate_composition([[
                'component' => 'hero', 'props' => ['title' => 'T', 'image_url' => $bad],
            ]]);
            $nested = pp_validate_composition([[
                'component' => 'logos', 'props' => ['items' => [['image_url' => $bad, 'image_alt' => 'A']]],
            ]]);

            $this->assertStringContainsString("must be a string; got {$shape}.", $top->get_error_message(),
                sprintf('top level must name the SHAPE for %s', var_export($bad, true)));
            $this->assertStringContainsString("must be a string; got {$shape}.", $nested->get_error_message(),
                sprintf('nested must name the SAME shape for %s', var_export($bad, true)));
        }

        // The `number` leg keeps echoing the value, which is the repair signal there.
        $number = pp_validate_composition([[
            'component' => 'logos',
            'props'     => ['items' => [['image_url' => '/a.png', 'image_alt' => 'A', 'image_id' => 'abc']]],
        ]]);
        $this->assertStringContainsString('must be a number; got "abc".', $number->get_error_message(),
            'the number leg must keep echoing the value — narrowing string must not have touched it');
    }

    /**
     * THE MIGRATION BLAST RADIUS, pinned rather than only described.
     *
     * This is the documented cost of a reject-never-coerce ruling with no stored-data
     * migration, and it is the part an operator actually meets: every composition-mutating
     * action validates the WHOLE composition, so one stale band blocks edits to bands that
     * are perfectly fine. The message naming the OFFENDING band rather than the one the
     * caller named (#642) is what makes that survivable, so it is asserted, not assumed.
     * Both recovery routes are asserted too — repair in place, or delete the band — because
     * "recoverable" is a claim this release makes in its notes and a claim has to be a test.
     */
    public function testAStaleBandBlocksEditsToCleanBandsAndBothRepairRoutesWork(): void
    {
        $post_id = pp_create_page('Aged page', 'draft');
        // The non-validating writer: exactly how a pre-#707 page, a restore (#233) and a
        // raw meta write all leave this state behind.
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Stale band', 'image_url' => 42, 'image_alt' => 'A']],
            ['component' => 'cta',  'props' => ['title' => 'Clean band', 'button_text' => 'Go', 'button_url' => '/']],
        ]);

        $blocked = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 1, 'props' => ['title' => 'Edited'],
        ]);
        $this->assertFalse($blocked['ok'], 'a stale band blocks an edit to an unrelated band');
        $this->assertStringContainsString('image_url', $blocked['error']);
        $this->assertStringContainsString('Component 0', $blocked['error'],
            'the message must name the OFFENDING band (#642), not the one the caller edited');

        // The actions that do NOT whole-validate still work, which is what keeps the page
        // workable while the operator decides how to repair it.
        foreach (['add_component' => ['post_id' => $post_id, 'component' => 'hero', 'props' => ['title' => 'New']],
                  'reorder_components' => ['post_id' => $post_id, 'order' => [1, 0, 2]]] as $action => $params) {
            $this->assertTrue(pp_execute_action($action, $params)['ok'],
                "{$action} validates no props and must still succeed on a stale page");
        }

        // ROUTE 1 — repair in place. The stale band moved to index 1 by the reorder above.
        $stale_index = null;
        foreach (pp_get_composition($post_id) as $index => $band) {
            if (($band['props']['image_url'] ?? null) === 42) {
                $stale_index = $index;
            }
        }
        $this->assertNotNull($stale_index, 'the stale band is still stored — nothing migrated it');
        $repaired = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => $stale_index, 'props' => ['image_url' => '42'],
        ]);
        $this->assertTrue($repaired['ok'], $repaired['error'] ?? 'quoting the value must repair the page');
        $this->assertSame([], _pp_composition_findings(pp_get_composition($post_id)),
            'once quoted, the page is clean and reports nothing');
        $this->assertTrue(pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'Now editable'],
        ])['ok'], 'edits to other bands unblock immediately after the repair');
    }

    /** ROUTE 2 — delete the offending band, which never whole-validates. */
    public function testRemovingTheStaleBandAlsoUnblocksThePage(): void
    {
        $post_id = pp_create_page('Aged page, second route', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Stale band', 'image_url' => 42, 'image_alt' => 'A']],
            ['component' => 'cta',  'props' => ['title' => 'Clean band', 'button_text' => 'Go', 'button_url' => '/']],
        ]);

        $this->assertTrue(pp_execute_action('remove_component', [
            'post_id' => $post_id, 'component_index' => 0,
        ])['ok'], 'remove_component validates no props and must succeed on a stale band');

        $this->assertTrue(pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0, 'props' => ['title' => 'Now editable'],
        ])['ok'], 'with the stale band gone, whole-composition validation passes');
        $this->assertSame([], _pp_composition_findings(pp_get_composition($post_id)));
    }

    public static function nonStringScalars(): array
    {
        $cases = [];
        foreach (self::NON_STRING_SCALARS as $value) {
            $cases[var_export($value, true)] = [$value];
        }
        return $cases;
    }

    // ── 4. The boundaries the narrowing must not cross ──────────────────────

    /**
     * restore_composition reports and NEVER blocks (#233). Undo is wired to it, so a
     * snapshot captured before this rule existed must still restore — this narrowing
     * changes what a WRITE accepts, not what history can hold.
     */
    public function testRestoreCompositionStillRestoresAStoredNonStringScalarAndReportsIt(): void
    {
        $post_id = pp_create_page('Page with history', 'draft');
        // The aged snapshot goes in through the non-validating writer, which is what
        // a pre-#707 composition, a raw meta write, and a pre-rule history entry all
        // look like by the time restore has to replay one.
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Aged band', 'image_url' => 42, 'image_alt' => 'A']],
        ]);
        $aged_json = get_post_meta($post_id, '_pp_composition', true);
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => ['title' => 'Newer band']],
        ]);
        $this->assertSame([], _pp_composition_findings(pp_get_composition($post_id)),
            'the current composition is clean, so only the SNAPSHOT can be what restore trips on');

        // The real action, not the findings engine underneath it: the #233 contract is
        // about what restore_composition DOES, and a regression where it started
        // blocking invalid snapshots would sail past a findings-only assertion.
        $result = pp_execute_action('restore_composition', ['post_id' => $post_id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore must never block — undo has to work');
        $this->assertSame($aged_json, get_post_meta($post_id, '_pp_composition', true),
            'the snapshot is restored VERBATIM — no coercion, no repair, no migration');
        $this->assertSame(42, pp_get_composition($post_id)[0]['props']['image_url'],
            'the integer comes back as an integer');

        // And it REPORTS what current rules say about what it just restored. Read the
        // ENVELOPE key, not the engine underneath it — a `?? fallback` here would keep
        // passing if restore ever stopped attaching `findings` at all.
        $this->assertArrayHasKey('findings', $result, 'restore must report, since it does not block');
        $findings = $result['findings'];
        $this->assertNotSame([], $findings, 'the stored non-string scalar must be reported');
        $joined = implode(' | ', array_column($findings, 'message'));
        $this->assertStringContainsString('image_url', $joined);
        $this->assertStringContainsString('must be a string', $joined);
        $this->assertContains('invalid_prop_value', array_column($findings, 'type'));
    }

    /**
     * The DIAGNOSTIC half of the ruling: the shape the write path used to accept in
     * silence is now named by the shared reporting engine, which is what
     * `wp pp check page` and `wp pp validate site` read (#622). An operator holding
     * aged content learns which prop to quote BEFORE a write is blocked by it.
     */
    public function testCompositionFindingsNameTheProp(): void
    {
        $findings = _pp_composition_findings([
            ['component' => 'hero', 'props' => ['title' => 'Fine', 'image_url' => 42]],
            ['component' => 'logos', 'props' => ['items' => [['image_url' => true, 'image_alt' => 'Acme']]]],
        ]);

        $messages = array_column($findings, 'message');
        $joined   = implode(' | ', $messages);
        $this->assertStringContainsString('image_url', $joined);
        $this->assertStringContainsString('must be a string', $joined);
        // Both bands report; the engine is collect-all, so one repair pass sees both.
        $this->assertGreaterThanOrEqual(2, count(array_filter(
            $messages,
            static fn ($m) => str_contains($m, 'must be a string')
        )));
    }

    /**
     * The render side is UNCHANGED and must stay that way. The `is_scalar()` guards
     * (#641/#705/#706/#708/#730/#739) cover what a write gate cannot reach —
     * compositions authored before the type rules existed, restore_composition, and
     * raw `_pp_composition` meta writes. This closes the front door they were built
     * to survive; tightening them to `is_string()` would silently drop a value
     * production coercion still resolves.
     *
     * `layout => split` IS LOAD-BEARING, not fixture decoration. Hero defaults to
     * `centered`, which emits no `<img>` and no background at all — so a fixture that
     * omitted the layout would never reach the coercion, the coerced value would appear
     * nowhere in the output, and "the page still renders" would pass just as happily
     * against a guard tightened to `is_string()` that had dropped the value. That is the
     * exact vacuous-assertion class the v1.15.7 notes recorded (a sweep asserting only
     * that a band still rendered left nine of ten surfaces green under a narrowed
     * predicate). The assertion below is therefore on the COERCED BYTES, not on survival.
     */
    public function testAStoredNonStringScalarStillRendersThroughTheGuards(): void
    {
        $post_id = pp_create_page('Raw-written page', 'publish');
        // Deliberately NOT through an action: this is the aged/raw-meta shape.
        pp_update_composition($post_id, [
            ['component' => 'hero', 'props' => [
                'title' => 'Aged hero', 'layout' => 'split', 'image_url' => 42, 'image_alt' => 'Alt',
            ]],
        ]);

        $html = $this->renderStoredComposition($post_id);
        $this->assertNotSame('', trim($html), 'a stored non-string scalar must not take the page down');
        $this->assertStringContainsString('Aged hero', $html, 'the band still renders');
        $this->assertStringContainsString('42', $html,
            'the guard COERCES the stored integer into the markup — an is_string() guard would drop it silently');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Renders a stored composition the way templates/composition.php does, so the
     * guard claim is proved against the real read+render path.
     */
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
     * "Otherwise complete" is the load-bearing part: a fixture missing a required
     * prop is rejected for the WRONG reason, and the walk above would then pass
     * without ever exercising the rule under test. Every assertion pairs with a
     * message-names-the-prop check for exactly that reason.
     *
     * THE MAP IS ASSERTED COMPLETE RATHER THAN DEFAULTED, and the difference only shows
     * up in the case that matters. An earlier draft ended `$base[$component] ?? []`: a
     * NEW composable component would then be walked with an EMPTY fixture, rejected for
     * its missing required prop — and still PASS, because the collect-all assertions above
     * find the type finding sitting right next to that one. The walk would report coverage
     * it never had, and the paragraph above would quietly stop being true. Failing here
     * instead names the component whose fixture is missing.
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
            . 'the schema walks would judge it against an incomplete band and report coverage they do not have'
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
