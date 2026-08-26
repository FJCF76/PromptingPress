<?php
/**
 * tests/ContainerPropWriteEnforcementTest.php
 *
 * A declared CONTAINER type means a container at the write path, at both depths (#744).
 *
 * THE DEFECT. The top-level prop pass has rejected a scalar where an array belongs
 * since #507. The NESTED items[] field walk never asked the question at all: RULE 2
 * (`item_type: "string"`) is gated on `is_array($entry[$field])`, so a scalar field
 * never enters its loop, and RULE 3's fence (#614) was scalar types only. So the two
 * depths disagreed about what `type: "array"` means. Measured on main before the fix,
 * through the shared validator:
 *
 *     write                                            verdict     stored     rendered
 *     ───────────────────────────────────────────────  ──────────  ─────────  ────────
 *     grid.items[].bullets: "not an array"             ok:true     raw        nothing
 *     grid.items[].style: "dark"                       ok:true     raw        nothing
 *     section.panel_items[].style: "dark"              ok:true     raw        nothing
 *     grid.items (top level): "not an array"           ok:false    --         --
 *
 * `components/grid/grid.php` reads bullets as `is_array($item['bullets'] ?? null) ?
 * $item['bullets'] : []`, and both renderers read a per-item `style` through the same
 * guard; the per-item slot engine in lib/admin.php skips a non-array style map. So an
 * author asked for a checklist, or for one card in a distinct colour, was told the
 * write worked, and got neither — the reported-success-without-effect class, one type
 * over from #614 (scalars) and #707 (`string`).
 *
 * THE RULING (D-A, canonical text in #724's body, applied to #744). REJECT, NEVER
 * COERCE: the write path refuses a present SCALAR in a field declaring `array` or
 * `object`, with the standard `invalid_prop_value` envelope naming component, prop,
 * item and field. No wrapping a scalar in a one-element array, no stored-data
 * migration. A documented breaking narrowing of write acceptance.
 *
 * WHAT THIS FILE PINS. Each entry names the section that holds it, so the roadmap and
 * the `──` headers below stay one map rather than two:
 *
 *   §1 THE PREDICATE — both container legs, the unset sentinels, and the
 *      not-applicable contract that lets a caller hand it any declaration.
 *   §2 UNIFORM COVERAGE — that BOTH depths actually route through that predicate. A
 *      two-case pin would prove the helper changed; it would not prove every
 *      container-typed schema path reaches it. The walks are schema-driven and assert
 *      the INVENTORY (9 top-level array props, 3 nested container fields today), so a
 *      declaration landing tomorrow is covered the day it lands and cannot opt out.
 *      Both walks drive every scalar in NON_CONTAINER_SCALARS, not just a string:
 *      mutation showed a string-only walk leaves an `is_string()`-narrowed arm green.
 *   §3 THE AUTHORING PATH (Section 14.1) — create_page / update_composition /
 *      update_component / add_component, not raw `_pp_composition` meta writes. Each
 *      rejection is paired with the well-formed counterpart, so a fixture failing for
 *      an unrelated reason cannot read as a pass, and each asserts nothing persisted.
 *   §4 THE ACCEPT SIDE AND THE BOUNDARIES — what must survive, and what this rule must
 *      NOT start doing. Real arrays, a style map decoded from real JSON, `[]`, and the
 *      `null` / `''` sentinels all stay accepted; the sentinels are an ACCEPTED
 *      limitation stated out loud (`bullets: ""` is still accepted and still renders
 *      nothing). The boundaries: `restore_composition` still restores verbatim and
 *      reports rather than blocks (#233); a rejected scalar `style` must not suppress
 *      a sibling card's slot finding; the shared findings engine names every offending
 *      field (what `wp pp check page` and `wp pp validate site` read, #622 — pinned at
 *      that engine, which is the layer this change touches, not at the CLI); and the
 *      render-side `is_array()` guards still turn a STORED scalar into an empty list
 *      instead of fataling a public page. This closes the front door those guards were
 *      built to survive; it does not repair what is already stored (#805 is the
 *      read-side half, deliberately untouched).
 *   §5 THE FORWARD-LOOKING TOP-LEVEL `object` ARM, through a SYNTHETIC component. No
 *      shipped schema declares a top-level `object` prop, and an arm no test can enter
 *      is how a fence silently stops being one — so this file builds a throwaway
 *      component directory, points the template root at it, and drives the real
 *      validator through it. It is also the only place the container rule's own
 *      list-vs-map boundary is observable, with no style-slot engine behind it.
 */

use PHPUnit\Framework\TestCase;

class ContainerPropWriteEnforcementTest extends TestCase
{
    /**
     * The scalars a container field must refuse, one list, used by every case below.
     *
     * `''` is deliberately ABSENT: it is the unset sentinel, asserted accepted in the
     * accept-side section rather than rejected here. `'0'` and `0` earn their places
     * as the falsy shapes a truthiness gate waves through.
     */
    private const NON_CONTAINER_SCALARS = ['not an array', '0', 42, 0, 3.14, true, false];

    /** Set when a test swaps the template root, so tearDown can restore it. */
    private ?string $syntheticThemeDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    /**
     * Restores the real theme root and the component registry.
     *
     * The registry caches per template root and is consulted on every composition
     * read (#576), so a test that swapped the root and did not put it back would
     * leak an empty or synthetic registry into every later test class.
     */
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

    /** Both container legs reject every present scalar and keep their sentinels. */
    public function testTheSharedPredicateAcceptsContainersAndTheUnsetSentinelsOnly(): void
    {
        foreach (['array', 'object'] as $type) {
            foreach (self::NON_CONTAINER_SCALARS as $bad) {
                $this->assertFalse(
                    _pp_schema_container_value_is_valid($type, $bad),
                    sprintf('type:%s must reject %s', $type, var_export($bad, true))
                );
            }
            foreach ([[], ['a'], ['k' => 'v'], [['nested']]] as $container) {
                $this->assertTrue(
                    _pp_schema_container_value_is_valid($type, $container),
                    sprintf('type:%s must accept %s', $type, var_export($container, true))
                );
            }
            $this->assertTrue(
                _pp_schema_container_value_is_valid($type, null),
                'null is the unset sentinel — it keeps a whole-composition rule from blocking unrelated bands'
            );
            $this->assertTrue(
                _pp_schema_container_value_is_valid($type, ''),
                'the empty string is the other unset sentinel, matching the top-level array rule this was extracted from'
            );
        }
    }

    /**
     * NOT-APPLICABLE RETURNS TRUE, so a caller can hand it every field definition it
     * walks without pre-classifying — the same contract both sibling predicates carry.
     * A regression here would not fail loudly; it would start rejecting every string
     * prop in the theme through a rule that has no business judging one.
     */
    public function testThePredicateIsNotApplicableToEveryOtherDeclaredType(): void
    {
        foreach (['string', 'number', 'enum', 'boolean', null, ''] as $type) {
            foreach (['x', 42, true, null, [], ['a']] as $value) {
                $this->assertTrue(
                    _pp_schema_container_value_is_valid($type, $value),
                    sprintf('type:%s is owned by another rule and must fall through', var_export($type, true))
                );
            }
        }
    }

    /**
     * The SCALAR predicate is untouched. #744 sits one type over from #707, and the
     * two rules share a walk but not an answer: `number` must keep accepting a numeric
     * string (a JSON/CLI write sends "3"), and `string` must keep meaning is_string().
     */
    public function testTheScalarPredicateIsUnchanged(): void
    {
        $this->assertTrue(_pp_schema_scalar_value_is_valid('number', '3'));
        $this->assertFalse(_pp_schema_scalar_value_is_valid('number', 'abc'));
        $this->assertTrue(_pp_schema_scalar_value_is_valid('string', ''));
        $this->assertFalse(_pp_schema_scalar_value_is_valid('string', 42));
        // And it still declines the container types, which is why a second predicate
        // had to exist rather than a widened first one.
        $this->assertTrue(_pp_schema_scalar_value_is_valid('array', 'not an array'));
        $this->assertTrue(_pp_schema_scalar_value_is_valid('object', 'not an object'));
    }

    // ── §2. Uniform coverage over every shipped declaration ─────────────────

    /**
     * EVERY nested items[] field declaring `array` or `object` is enforced — 3 today
     * (grid.items[].bullets, grid.items[].style, section.panel_items[].style), walked
     * from the schemas rather than listed here so the pin cannot go stale.
     *
     * Chrome (`nav`, `footer`) is excluded because this engine never judges it: a
     * template-owned component is rejected on its identity several rules earlier, so
     * including it would assert a rejection that comes from the wrong rule.
     */
    public function testEveryNestedContainerFieldIsEnforcedAcrossTheShippedSchemas(): void
    {
        $checked = [];
        foreach (pp_composable_components() as $component => $schema) {
            foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                if (!is_array($prop_def)
                    || ($prop_def['type'] ?? null) !== 'array'
                    || !is_array($prop_def['items'] ?? null)
                ) {
                    continue;
                }
                foreach ($prop_def['items'] as $field => $field_def) {
                    if (!is_array($field_def)) {
                        continue;
                    }
                    $field_type = $field_def['type'] ?? null;
                    if ($field_type !== 'array' && $field_type !== 'object') {
                        continue;
                    }
                    $entry            = $this->wellFormedEntry($prop_def['items']);
                    $entry[$field]    = 'not a container';
                    $band             = [
                        'component' => $component,
                        'props'     => $this->wellFormedProps($component, [$prop_name => [$entry]]),
                    ];
                    $expected_article = $field_type === 'array' ? 'an array' : 'an object';

                    $this->assertInstanceOf(
                        WP_Error::class,
                        pp_validate_composition([$band]),
                        "{$component}.{$prop_name}[].{$field} declares type:{$field_type} and must reject a scalar"
                    );
                    // Assert against the COLLECT-ALL engine, not just errors[0]. The write
                    // path is first-error-wins, so a fixture that started failing an earlier
                    // rule for an unrelated reason could otherwise satisfy a first-error
                    // assertion and leave this field's own rule unexercised. Requiring a
                    // finding that names THIS prop, item and field AND carries this rule's
                    // phrasing is immune to rule order: nothing else in the engine produces
                    // that pairing. The message has to carry the whole repair ADDRESS,
                    // because "a container field somewhere is wrong" is not actionable.
                    $this->assertNotSame(
                        [],
                        array_filter(
                            pp_validate_composition_errors([$band]),
                            static fn ($e) => $e->get_error_code() === 'invalid_prop_value'
                                && str_contains($e->get_error_message(), sprintf('prop "%s"', $prop_name))
                                && str_contains($e->get_error_message(), 'item 0')
                                && str_contains($e->get_error_message(), sprintf('field "%s"', $field))
                                && str_contains($e->get_error_message(), "must be {$expected_article}")
                        ),
                        "the rejection for {$component}.{$prop_name}[].{$field} must name prop, item, field and rule"
                    );
                    $checked[] = "{$component}.{$prop_name}[].{$field}";
                }
            }
        }
        // The INVENTORY is asserted, not just the count: #744 was found by sweeping the
        // registry for this shape, and a future schema that drops or renames one of
        // these should fail here with the list rather than quietly shrink the walk.
        sort($checked);
        $this->assertSame(
            [
                'grid.items[].bullets',
                'grid.items[].style',
                'section.panel_items[].style',
            ],
            $checked,
            'the shipped schemas declare exactly three nested container fields; update this pin deliberately'
        );
    }

    /**
     * The TOP-LEVEL array pass still rejects a scalar for all 9 shipped array props.
     *
     * This half did not change behaviour — #744 moved its inline test into the shared
     * predicate — which is exactly why it needs a pin: a refactor that quietly altered
     * what the top level accepts would otherwise land invisibly, and the whole point
     * of sharing the predicate is that the two depths give one answer.
     *
     * EVERY scalar in the list, not just a string. An earlier draft passed only the
     * string `'not an array'`, and mutation proved what that was worth: narrowing the
     * arm to `is_string($value) && !_pp_schema_container_value_is_valid(...)` left the
     * whole 4108-test suite green. A file whose thesis is "both depths answer the same
     * way about the same scalars" has to hand both depths the same scalars.
     */
    public function testEveryTopLevelArrayPropStillRejectsEveryScalar(): void
    {
        $checked = [];
        foreach (pp_composable_components() as $component => $schema) {
            foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                if (!is_array($prop_def) || ($prop_def['type'] ?? null) !== 'array') {
                    continue;
                }
                foreach (self::NON_CONTAINER_SCALARS as $bad) {
                    $band = [
                        'component' => $component,
                        'props'     => $this->wellFormedProps($component, [$prop_name => $bad]),
                    ];
                    $this->assertNotSame(
                        [],
                        array_filter(
                            pp_validate_composition_errors([$band]),
                            static fn ($e) => $e->get_error_code() === 'invalid_prop_value'
                                && str_contains($e->get_error_message(), sprintf('prop "%s"', $prop_name))
                                && str_contains($e->get_error_message(), 'must be an array')
                        ),
                        sprintf(
                            '%s.%s declares type:array and must keep rejecting %s',
                            $component,
                            $prop_name,
                            var_export($bad, true)
                        )
                    );
                }
                $checked[] = "{$component}.{$prop_name}";
            }
        }
        // The INVENTORY, not a count — same argument as the nested walk above. A rename
        // plus an addition keeps a count at 9 and lets the drift land in silence.
        sort($checked);
        $this->assertSame(
            [
                'faq.items',
                'grid.items',
                'logos.items',
                'section.body_items',
                'section.panel_items',
                'stats.items',
                'table.headers',
                'table.rows',
                'testimonials.items',
            ],
            $checked,
            'the shipped schemas declare exactly these 9 composable top-level array props; update this pin deliberately'
        );
    }

    // ── §3. The authoring path (Section 14.1) ───────────────────────────────

    /**
     * create_page refuses a scalar bullets list and writes nothing.
     *
     * @dataProvider nonContainerScalars
     */
    public function testCreatePageRejectsAScalarInANestedArrayField($bad): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Rejected at creation',
            'composition' => [[
                'component' => 'grid',
                'props'     => ['items' => [['title' => 'Card', 'text' => 'T', 'bullets' => $bad]]],
            ]],
        ]);

        $this->assertFalse($result['ok'], sprintf('bullets %s must not persist behind ok:true', var_export($bad, true)));
        $this->assertSame('invalid_prop_value', $result['error_code'],
            'the rejection must carry the standard machine-readable code, not just a message');
        $this->assertStringContainsString('bullets', $result['error']);
        $this->assertStringContainsString('item 0', $result['error']);
        $this->assertStringContainsString('must be an array', $result['error']);
        $this->assertSame([], $GLOBALS['_pp_test_store']['posts'], 'a rejected create_page must create no post');
    }

    /**
     * The object leg through the same action. Both shipped `object` declarations are
     * per-item `style` maps, so this is the shape an agent reaches for when it wants
     * one card to look different — and the one that used to silently do nothing.
     *
     * @dataProvider nonContainerScalars
     */
    public function testCreatePageRejectsAScalarInANestedObjectField($bad): void
    {
        $result = pp_execute_action('create_page', [
            'title'       => 'Rejected at creation, object leg',
            'composition' => [[
                'component' => 'grid',
                'props'     => ['items' => [['title' => 'Card', 'text' => 'T', 'style' => $bad]]],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('style', $result['error']);
        $this->assertStringContainsString('item 0', $result['error']);
        $this->assertStringContainsString('must be an object', $result['error']);
        $this->assertSame([], $GLOBALS['_pp_test_store']['posts'], 'a rejected create_page must create no post');
    }

    /**
     * The well-formed counterpart: the same writes with real containers are accepted
     * and stored, including a style map that arrived as REAL JSON.
     *
     * The json_decode() round trip is load-bearing, not decoration. The rule's whole
     * premise is that a JSON object and a JSON list both decode to a PHP array, so a
     * test that only ever passes PHP literals would never prove that the shape an
     * actual `--props` payload produces survives the gate it now has to pass.
     */
    public function testCreatePageStillAcceptsWellFormedContainers(): void
    {
        $decoded = json_decode(
            '{"items":[{"title":"Card","text":"T","bullets":["Fast","Honest"],"style":{"--grid-item-bg":"#111111"}}]}',
            true
        );

        $result = pp_execute_action('create_page', [
            'title'       => 'Accepted at creation',
            'composition' => [
                ['component' => 'grid', 'props' => $decoded],
                ['component' => 'section', 'props' => [
                    'body'        => 'Body copy',
                    'panel_items' => [['label' => 'Uptime', 'value' => '99%', 'style' => ['--section-panel-text' => '#222222']]],
                ]],
            ],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'a well-formed container write must still be accepted');
        $stored = pp_get_composition($result['target']['post_id']);
        $this->assertSame(['Fast', 'Honest'], $stored[0]['props']['items'][0]['bullets']);
        $this->assertSame(['--grid-item-bg' => '#111111'], $stored[0]['props']['items'][0]['style']);
        $this->assertSame(['--section-panel-text' => '#222222'], $stored[1]['props']['panel_items'][0]['style']);
    }

    /**
     * update_composition refuses the same shape and leaves the stored composition
     * untouched — a rejected write must not be a partial write.
     */
    public function testUpdateCompositionRejectsAScalarAndStoresNothing(): void
    {
        $post_id = pp_create_page('Existing page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Original', 'bullets' => ['Kept']]]]],
        ]);

        $result = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'grid', 'props' => ['items' => [['title' => 'New', 'bullets' => 'Fast, honest']]]]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must be an array', $result['error']);
        $this->assertSame(
            ['Kept'],
            pp_get_composition($post_id)[0]['props']['items'][0]['bullets'],
            'a rejected write must leave the stored composition untouched'
        );
    }

    /**
     * update_component is the action an agent reaches for most often when repairing a
     * single band. It must refuse the shape rather than merging it in.
     */
    public function testUpdateComponentRejectsAScalarAtBothLegs(): void
    {
        $post_id = pp_create_page('Page with a band to edit', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Card', 'bullets' => ['Kept']]]]],
        ]);

        foreach ([
            ['bullets' => 'Fast, honest', 'must be an array'],
            ['style'   => 'dark',         'must be an object'],
        ] as $case) {
            $field    = array_key_first($case);
            $expected = $case[array_key_last($case)];
            $result   = pp_execute_action('update_component', [
                'post_id'         => $post_id,
                'component_index' => 0,
                'props'           => ['items' => [['title' => 'Card', $field => $case[$field]]]],
            ]);

            $this->assertFalse($result['ok'], "a scalar {$field} must be refused");
            $this->assertStringContainsString($field, $result['error']);
            $this->assertStringContainsString($expected, $result['error']);
        }

        $this->assertSame(
            ['Kept'],
            pp_get_composition($post_id)[0]['props']['items'][0]['bullets'],
            'neither rejected edit may have landed'
        );
    }

    /**
     * `add_component` is the fourth sanctioned write verb and the odd one out
     * structurally: it validates only the ITEM it adds, through
     * `pp_validate_composition_item()` rather than the whole composition, so it reaches
     * the rule by a different route than the three above and could regress alone.
     */
    public function testAddComponentRejectsAScalarContainer(): void
    {
        $post_id = pp_create_page('Page to append to', 'draft');
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'T']]]);

        // BOTH LEGS through this route, because the route is the point: a regression
        // isolated to the item-only path would otherwise be caught for `array` and
        // missed for `object`, which is the same half-coverage #744 is about.
        foreach ([
            ['bullets', 'Fast, honest', 'must be an array'],
            ['style',   'dark',         'must be an object'],
        ] as [$field, $bad, $expected]) {
            $result = pp_execute_action('add_component', [
                'post_id'   => $post_id,
                'component' => 'grid',
                'props'     => ['items' => [['title' => 'Card', $field => $bad]]],
            ]);

            $this->assertFalse($result['ok'], "a scalar {$field} must be refused by add_component");
            $this->assertStringContainsString($field, $result['error']);
            $this->assertStringContainsString($expected, $result['error']);
        }

        $this->assertCount(1, pp_get_composition($post_id), 'neither rejected append may persist');
    }

    // ── §4. The accept side and the boundaries ──────────────────────────────

    /**
     * The unset sentinels and the empty container survive, at both legs.
     *
     * This is the half that decides whether the narrowing is shippable at all. Every
     * action validates the WHOLE composition, so a rule that rejected a blank would
     * block edits to unrelated bands on the same page. `''` is an ACCEPTED LIMITATION
     * and is asserted rather than hidden: it is still accepted and still renders
     * nothing, exactly as the top-level array rule has always treated it, and
     * narrowing it would be a different ruling than #744's.
     */
    public function testTheUnsetSentinelsAndEmptyContainersAreAccepted(): void
    {
        foreach ([null, '', []] as $sentinel) {
            foreach (['bullets', 'style'] as $field) {
                $this->assertTrue(
                    pp_validate_composition([[
                        'component' => 'grid',
                        'props'     => ['items' => [['title' => 'Card', $field => $sentinel]]],
                    ]]) === true,
                    sprintf('%s must accept the sentinel %s', $field, var_export($sentinel, true))
                );
            }
        }
    }

    /**
     * A JSON LIST in an `object` field is still ACCEPTED, deliberately.
     *
     * PHP has one shape for both JSON containers, so this rule enforces "container,
     * not scalar" and decides nothing about what a container may hold — map-vs-list,
     * and what an item `style` may contain, stay unowned (no decision exists). The pin
     * exists so a later reader does not "tighten" this into a shape nobody ruled on;
     * the entry-shape question one level up is owned by `item_type: "object"`, which is
     * a different rule with a different message.
     */
    public function testAListInAnObjectFieldIsNotThisRulesBusinessAndIsCaughtBySlotValidation(): void
    {
        $this->assertTrue(
            pp_validate_composition([[
                'component' => 'grid',
                'props'     => ['items' => [['title' => 'Card', 'style' => []]]],
            ]]) === true,
            'an empty map is a container and is not this rule\'s business'
        );

        // AND THE LIST CASE IS STILL REFUSED, by the rule that owns style slots. This
        // is what makes "container, not scalar" a sufficient line here rather than a
        // hole: the operator-facing contract ("a per-item style takes a JSON object")
        // holds end to end, and the message names the card and lists what it accepts.
        // Both halves are asserted together on purpose — reading the accept above
        // alone would suggest a list quietly persists, which is exactly what a later
        // reader might "fix" by widening this rule onto a shape nobody ruled on.
        foreach ([
            ['grid',    ['items' => [['title' => 'Card', 'style' => ['#fff']]]]],
            ['section', ['body' => 'B', 'panel_items' => [['label' => 'L', 'style' => ['#fff']]]]],
        ] as [$component, $props]) {
            $rejected = pp_validate_composition([['component' => $component, 'props' => $props]]);
            $this->assertInstanceOf(WP_Error::class, $rejected, "a list-shaped {$component} style must not persist");
            $this->assertSame('invalid_style_slot', $rejected->get_error_code(),
                'and it must be refused by the SLOT rule, not by the container rule — the two own different questions');
            $this->assertStringContainsString('has no style slot "0"', $rejected->get_error_message());
        }
    }

    /**
     * restore_composition reports and NEVER blocks (#233). Undo is wired to it, so a
     * snapshot captured before this rule existed must still restore — this narrowing
     * changes what a WRITE accepts, not what history can hold.
     */
    public function testRestoreCompositionStillRestoresAStoredScalarAndReportsIt(): void
    {
        $post_id = pp_create_page('Page with history', 'draft');
        // The non-validating writer: exactly how a pre-#744 composition, a raw meta
        // write, and a pre-rule history entry all look by the time restore replays one.
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Aged card', 'bullets' => 'Fast, honest']]]],
        ]);
        $aged_json = get_post_meta($post_id, '_pp_composition', true);
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Newer card']]]],
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
        $this->assertSame('Fast, honest', pp_get_composition($post_id)[0]['props']['items'][0]['bullets'],
            'the string comes back as a string');

        $this->assertArrayHasKey('findings', $result, 'restore must report, since it does not block');
        $joined = implode(' | ', array_column($result['findings'], 'message'));
        $this->assertStringContainsString('bullets', $joined);
        $this->assertStringContainsString('must be an array', $joined);
        $this->assertContains('invalid_prop_value', array_column($result['findings'], 'type'));
    }

    /**
     * The DIAGNOSTIC half: the shape the write path used to accept in silence is now
     * named by the shared reporting engine, which is what `wp pp check page` and
     * `wp pp validate site` read (#622). An operator holding aged content learns which
     * field to repair BEFORE a write is blocked by it. Collect-all, so one repair pass
     * sees every offending field rather than the first.
     */
    public function testCompositionFindingsNameEveryOffendingField(): void
    {
        $messages = array_column(_pp_composition_findings([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card A', 'bullets' => 'Fast, honest'],
                ['title' => 'Card B', 'style' => 'dark'],
            ]]],
        ]), 'message');
        $joined = implode(' | ', $messages);

        $this->assertStringContainsString('item 0', $joined);
        $this->assertStringContainsString('bullets', $joined);
        $this->assertStringContainsString('item 1', $joined);
        $this->assertStringContainsString('style', $joined);
        $this->assertGreaterThanOrEqual(2, count(array_filter(
            $messages,
            static fn ($m) => str_contains($m, 'must be an array') || str_contains($m, 'must be an object')
        )), 'both cards must be reported — a first-error-only answer sends an operator round the loop twice');
    }

    /**
     * A REJECTED SCALAR `style` MUST NOT SWALLOW THE SLOT DIAGNOSTIC on a sibling card.
     *
     * The per-item style engine claims the role segment `item-style`, not `prop`,
     * specifically so a scalar-typed `style` field could not claim a card's location
     * and silence the slot finding — that comment was written before this rule existed
     * and anticipated it. A suppressed diagnostic is the one failure mode the claim set
     * must never cause, so the anticipation is pinned rather than trusted.
     */
    public function testAScalarStyleDoesNotSuppressTheSlotFindingOnASiblingCard(): void
    {
        $messages = array_column(_pp_composition_findings([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card A', 'style' => 'dark'],
                ['title' => 'Card B', 'style' => ['--not-a-real-slot' => '#fff']],
            ]]],
        ]), 'message');
        $joined = implode(' | ', $messages);

        $this->assertStringContainsString('must be an object', $joined, 'card 0 reports the type defect');
        $this->assertStringContainsString('--not-a-real-slot', $joined, 'card 1 still reports its dead slot');
    }

    /**
     * THE MIGRATION BLAST RADIUS, pinned rather than only described.
     *
     * This is the documented cost of a reject-never-coerce ruling with no stored-data
     * migration, and it is the part an operator actually meets: every composition-
     * mutating action validates the WHOLE composition, so one stale band blocks edits
     * to bands that are perfectly fine. The message naming the OFFENDING band rather
     * than the one the caller named (#642) is what makes that survivable, so it is
     * asserted. Both recovery routes are asserted too, because "recoverable" is a claim
     * the release notes make and a claim has to be a test.
     */
    public function testAStaleBandBlocksEditsToCleanBandsAndBothRepairRoutesWork(): void
    {
        $post_id = pp_create_page('Aged page', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Stale card', 'bullets' => 'Fast, honest']]]],
            ['component' => 'cta',  'props' => ['title' => 'Clean band', 'button_text' => 'Go', 'button_url' => '/']],
        ]);

        $blocked = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 1, 'props' => ['title' => 'Edited'],
        ]);
        $this->assertFalse($blocked['ok'], 'a stale band blocks an edit to an unrelated band');
        $this->assertStringContainsString('bullets', $blocked['error']);
        $this->assertStringContainsString('Component 0', $blocked['error'],
            'the message must name the OFFENDING band (#642), not the one the caller edited');

        // ROUTE 1 — repair in place, which is what the error message asks for.
        $repaired = pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 0,
            'props'   => ['items' => [['title' => 'Stale card', 'bullets' => ['Fast', 'honest']]]],
        ]);
        $this->assertTrue($repaired['ok'], $repaired['error'] ?? 'wrapping the value in a list must repair the page');
        $this->assertSame([], _pp_composition_findings(pp_get_composition($post_id)),
            'once wrapped, the page is clean and reports nothing');
        $this->assertTrue(pp_execute_action('update_component', [
            'post_id' => $post_id, 'component_index' => 1, 'props' => ['title' => 'Now editable'],
        ])['ok'], 'edits to other bands unblock immediately after the repair');
    }

    /** ROUTE 2 — delete the offending band, which never whole-validates. */
    public function testRemovingTheStaleBandAlsoUnblocksThePage(): void
    {
        $post_id = pp_create_page('Aged page, second route', 'draft');
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Stale card', 'bullets' => 'Fast, honest']]]],
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

    /**
     * The RENDER side is unchanged and must stay that way.
     *
     * A stored scalar bullets list still renders as NO bullets — that is the defect
     * #744 closes at the WRITE path and explicitly does NOT repair in storage. The
     * assertion is on both halves: the band still renders (no fatal, the guard holds)
     * AND the scalar produces no bullet markup. A test that asserted only "the page
     * still renders" would pass just as happily against a change that started painting
     * the raw string into the list, which is the coercion this ruling forbids. The
     * editor's read-side handling of the same stored shape is #805 and is untouched.
     */
    public function testAStoredScalarBulletsStillRendersAsNothingAndDoesNotFatal(): void
    {
        $post_id = pp_create_page('Raw-written page', 'publish');
        // Deliberately NOT through an action: this is the aged/raw-meta shape.
        pp_update_composition($post_id, [
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Aged card', 'text' => 'Body copy', 'bullets' => 'Fast, honest'],
            ]]],
        ]);

        $html = $this->renderStoredComposition($post_id);
        $this->assertStringContainsString('Aged card', $html, 'the band still renders — the guard holds');
        $this->assertStringNotContainsString('grid__item-bullets', $html,
            'the stored scalar still renders as no bullets: this change repairs the write path, not stored data');
        $this->assertStringNotContainsString('Fast, honest', $html,
            'and nothing coerces the raw string into the card — reject, never coerce');
    }

    // ── §5. The forward-looking TOP-LEVEL object arm ────────────────────────

    /**
     * A TOP-LEVEL `type: "object"` prop rejects a scalar, proven through the real
     * validator on a SYNTHETIC component.
     *
     * No shipped schema declares one, so there is no other way to enter that arm — and
     * an arm no test can enter is how a fence silently stops being one. #744 exists
     * because two depths enforced one declaration differently; leaving the top level
     * unenforced for `object` would plant the same defect for whoever ships the first
     * top-level object prop. The registry is read from `get_template_directory() .
     * '/components/'` and caches per root, so swapping the root with the invalidate
     * flag (the idiom ApplyTest / OperateTest already use) is enough to drive the real
     * engine against a schema this repo does not ship.
     */
    public function testATopLevelObjectPropRejectsAScalarThroughTheRealValidator(): void
    {
        $this->useSyntheticComponent('widget', [
            'props' => [
                'id'     => ['type' => 'string'],
                'config' => ['type' => 'object', 'required' => false],
            ],
        ]);

        // EVERY scalar, and the SHAPE NAME for each. An earlier draft passed only the
        // string, and mutation proved that worthless: narrowing this arm to
        // `is_string($value) && !_pp_schema_container_value_is_valid('object', ...)`
        // left the entire suite green. This is the ONLY test that can enter this arm,
        // so a partial pin here is the fence quietly not being one.
        foreach (self::NON_CONTAINER_SCALARS as $bad) {
            $rejected = pp_validate_composition([
                ['component' => 'widget', 'props' => ['id' => 'w', 'config' => $bad]],
            ]);
            $this->assertInstanceOf(WP_Error::class, $rejected,
                sprintf('a top-level object prop must reject %s', var_export($bad, true)));
            $this->assertSame('invalid_prop_value', $rejected->get_error_code());
            $this->assertStringContainsString(
                sprintf('prop "config" must be an object; got %s.', gettype($bad)),
                $rejected->get_error_message(),
                'and it must name the SHAPE, in the same vocabulary the nested arm uses'
            );
        }

        foreach ([['k' => 'v'], [], null, ''] as $accepted) {
            $this->assertTrue(
                pp_validate_composition([
                    ['component' => 'widget', 'props' => ['id' => 'w', 'config' => $accepted]],
                ]) === true,
                sprintf('a top-level object prop must accept %s', var_export($accepted, true))
            );
        }

        // THE BOUNDARY THIS RULE DELIBERATELY DOES NOT CROSS, pinned where it is
        // actually observable. The container rule decides "container, not scalar", so a
        // JSON LIST in an `object` prop passes IT — and here, on a synthetic prop with
        // no style-slot engine behind it, that is the whole answer, so the acceptance
        // is visible instead of being masked by a later rule. Both shipped `object`
        // fields ARE style maps, which is why the nested test one section up sees the
        // list refused by `invalid_style_slot` instead. Two rules, two questions; this
        // pair is what keeps a later reader from "fixing" one into the other.
        $this->assertTrue(
            pp_validate_composition([
                ['component' => 'widget', 'props' => ['id' => 'w', 'config' => ['a', 'b']]],
            ]) === true,
            'a JSON list is a container, so the container rule passes it — map-vs-list is nobody\'s rule here'
        );
    }

    public static function nonContainerScalars(): array
    {
        $cases = [];
        foreach (self::NON_CONTAINER_SCALARS as $value) {
            $cases[var_export($value, true)] = [$value];
        }
        return $cases;
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Points the theme root at a throwaway directory holding ONE synthetic component,
     * so the real registry and the real validator can be driven against a schema shape
     * this repo does not ship. tearDown() restores the root and invalidates the cache.
     */
    private function useSyntheticComponent(string $name, array $schema): void
    {
        // UNPREDICTABLE AND PRIVATE, not `getmypid() . mt_rand()`. This fixture writes
        // into shared /tmp and then RECURSIVELY DELETES what it wrote, so a guessable
        // path is a local user's invitation to pre-create it as a symlink and redirect
        // both halves. random_bytes + 0700 + a checked mkdir is the repo's own safe
        // precedent (tests/InvariantTest.php); the sibling test files that still use the
        // predictable form are a separate cleanup, not a standard to match.
        $this->syntheticThemeDir = sys_get_temp_dir() . '/pp-container-test-' . bin2hex(random_bytes(8));
        $dir = $this->syntheticThemeDir . '/components/' . $name;
        $this->assertTrue(mkdir($dir, 0700, true), 'the synthetic theme dir must be created fresh, not adopted');
        // The registry only registers a component whose <name>/<name>.php exists.
        file_put_contents($dir . '/' . $name . '.php', "<?php\n");
        file_put_contents($dir . '/schema.json', json_encode($schema));

        $GLOBALS['_pp_test_template_dir']             = $this->syntheticThemeDir;
        $GLOBALS['_pp_registered_components_invalidate'] = true;
    }

    /**
     * Deletes the fixture tree without following symlinks.
     *
     * `is_dir()` answers true for a symlink POINTING at a directory, so a bare
     * is_dir/recurse walk would descend out of the fixture and delete somebody else's
     * files. Checking is_link() FIRST is what keeps the teardown inside the tree this
     * test created.
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
     * "Otherwise complete" is the load-bearing part: a fixture missing a required prop
     * is rejected for the WRONG reason, and the walks above would then pass without
     * ever exercising the rule under test. Every assertion pairs with a
     * message-names-the-field check for exactly that reason.
     *
     * THE MAP IS ASSERTED COMPLETE RATHER THAN DEFAULTED: ending this
     * `$base[$component] ?? []` would walk a NEW composable component with an EMPTY
     * fixture, reject it for its missing required prop, and still PASS, because the
     * collect-all assertions find the container finding sitting next to that one. The
     * walk would report coverage it never had. Failing here instead names the
     * component whose fixture is missing.
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
