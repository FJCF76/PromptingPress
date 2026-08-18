<?php
/**
 * tests/WriteRenderGrammarTest.php
 *
 * Write/render value-grammar convergence (issue #579).
 *
 * The gate's single claim: the set of values the WRITE path accepts equals the set
 * of values the RENDER path acts on. Where the two used to diverge, a write
 * returned ok:true and the page did something else — the reported-success-without-
 * effect class, in its most destructive form.
 *
 *     WRITE                                RENDER
 *     _pp_validate_token_value()           pp_render_style_value_allowed()
 *          │                                    │
 *          └──── _pp_forbidden_css_construct() ──┘      A-33: ONE set, two callers
 *
 *     _pp_validate_style_slot_map($style, …, $item_index)   write, item scope
 *     pp_render_style_vars($style, …, $item_scope)          render, item scope
 *          └──── pp_item_eligible_slots() ────┘             A-19: ONE predicate
 *
 * Covered here:
 *   A-33  the shared reject set, and the `serif /*` defect it closes
 *   A-30  the `length-or-none` band-geometry grammar
 *   A-19  item scope enforced at RENDER, proved through a raw-meta seed
 *   A-27  nested item-field contracts (required + item_type)
 *   #614  nested item-field scalar types (string + number), sharing the top-level
 *         #507 predicate so the two depths cannot disagree about what "42" is
 *   #600  nested item-field strict enums, sharing the top-level #380/#579 membership
 *         predicate for the same reason — the last accept-at-write / coerce-at-render
 *         surface in the composition grammar
 *   A-34  the non-blocking transparent-fill advisory
 *
 * A-32 (universal strict enums; its `aliases` consumer was retired in #606) lives
 * with its neighbours:
 * declaration-side pins in SchemaValidationTest, authoring-path pins in ActionsTest.
 *
 * Two rulings constrain every case below and are asserted, not assumed:
 *   - restore_composition NEVER blocks on a new rejection; it restores verbatim and
 *     reports findings through the shared engines (#233).
 *   - Well-formed-but-ineffective values WARN, they do not reject (the transparent
 *     fill). Only provably dead values are rejected.
 */

use PHPUnit\Framework\TestCase;

class WriteRenderGrammarTest extends TestCase
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
     * Renders a stored composition exactly as templates/composition.php does, so a
     * raw-meta seed is proved against the REAL read+render path rather than against
     * a hand-built props array.
     */
    private function renderStored(int $post_id): string
    {
        ob_start();
        foreach (pp_get_composition($post_id) as $item) {
            if (!isset($item['component'])) {
                continue;
            }
            $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
            $style = isset($item['style'])  && is_array($item['style'])  ? $item['style']  : [];
            if ($style) {
                $props['__pp_style'] = $style;
            }
            pp_get_component((string) $item['component'], $props);
        }
        return ob_get_clean();
    }

    /** Writes a composition straight to meta, bypassing every validator. */
    private function seedRaw(int $post_id, array $items): void
    {
        update_post_meta($post_id, '_pp_composition', wp_json_encode($items));
    }

    // ── A-33 — one reject set, two callers ───────────────────────────────────

    /**
     * THE defect this entry closes, end to end. `serif /*` cleared write validation
     * (the write engine's class was only `{};<>`), persisted, and then opened a CSS
     * comment inside the inline style attribute that swallowed every declaration
     * after it — so the band silently lost its number colour AND its background
     * image while `.stats--has-bg-image` still painted the scrim over nothing.
     *
     * It is rejected at WRITE now, with a named error, through the real action.
     */
    public function testStatsNumberFontWithACommentOpenerIsRejectedAtWrite(): void
    {
        $id = pp_create_page('Comment-opener font', 'draft');
        pp_update_composition($id, [['component' => 'stats', 'props' => ['items' => [
            ['number' => '99%', 'label' => 'Uptime'],
        ]]]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--stats-number-font' => 'serif /*'],
        ]);

        $this->assertFalse($result['ok'], 'a value the renderer would drop must not be accepted at write');
        $this->assertStringContainsString('--stats-number-font', $result['error']);
        $this->assertStringNotContainsString(
            'serif /*',
            pp_get_composition($id)[0]['style']['--stats-number-font'] ?? '',
            'nothing persisted'
        );
    }

    /**
     * The convergence itself, stated as an equality rather than a list: for every
     * construct the render boundary drops, the write engine must also reject. A
     * value in the gap is precisely the accepted-and-then-dropped class.
     *
     * @dataProvider forbiddenConstructs
     */
    public function testWriteRejectsEveryConstructTheRenderBoundaryDrops(string $label, string $value): void
    {
        $this->assertFalse(
            pp_render_style_value_allowed($value, null),
            "render must drop '{$label}'"
        );
        $result = _pp_validate_token_value($value, null);
        $this->assertInstanceOf(WP_Error::class, $result, "write must reject '{$label}'");
        $this->assertSame('injection', $result->get_error_code(), $label);
    }

    public static function forbiddenConstructs(): array
    {
        return [
            'brace'            => ['brace', 'red}'],
            'semicolon'        => ['semicolon', 'red; color: blue'],
            'angle bracket'    => ['angle bracket', 'red</style>'],
            'backslash'        => ['backslash', "red\\65 vil"],
            'control char'     => ['control char', "red\x01"],
            'newline'          => ['newline', "red\nmore"],
            'comment opener'   => ['comment opener', 'serif /*'],
            'comment closer'   => ['comment closer', '*/ red'],
            'url()'            => ['url()', 'url(https://evil.test/x.png)'],
            'url with space'   => ['url with space', 'url (https://evil.test/x.png)'],
            'expression()'     => ['expression()', 'expression(alert(1))'],
            '@import'          => ['@import', '@import "evil.css"'],
            'uppercase URL('   => ['uppercase URL(', 'URL(https://evil.test/x.png)'],
        ];
    }

    /**
     * The `;` reject is what closes the ENTITY route into the comment guard, and the
     * coupling is invisible from either half alone. The sink for an accepted value is
     * esc_attr() (htmlspecialchars with double_encode=false), so an entity already in
     * a stored value passes through and the BROWSER decodes it inside the style
     * attribute — after the raw-byte `/ *` check has looked and found nothing. The
     * terminated forms are caught by `;`; the unterminated forms are re-encoded inert
     * by esc_attr. Pinned here so removing `;` from the char class fails loudly
     * instead of silently re-opening comment injection on the public render path.
     */
    public function testEntityEncodedCommentDelimitersAreRejected(): void
    {
        foreach (['&#47;&#42;', '&#x2f;&#x2a;', 'serif&#47;&#42;', '&#42;&#47; red'] as $value) {
            $this->assertNotNull(
                _pp_forbidden_css_construct($value),
                "{$value} decodes to a CSS comment delimiter in the browser and must be rejected"
            );
            $this->assertFalse(pp_render_style_value_allowed($value, null), $value);
        }
    }

    /**
     * The shared set is BOUNDED. A bare `*` is legal CSS arithmetic and must stay
     * accepted — rejecting the character instead of the two-character delimiter
     * would break every multiplying calc() on every length slot.
     */
    public function testTheCommentGuardDoesNotRejectCalcArithmetic(): void
    {
        foreach (['calc(4rem * 2)', 'calc(4rem * 2 / 3)', 'calc(100% / 3)'] as $value) {
            $this->assertTrue(
                _pp_validate_token_value($value, 'length') === true,
                "{$value} must still validate"
            );
            $this->assertTrue(pp_render_style_value_allowed($value, 'length'), $value);
        }
    }

    /**
     * The TOKEN surface, which the shared set also governs because it runs ahead of
     * the type switch. Every shipped design-token type keeps validating its own
     * shipped default: the widening rejects nothing reachable, which is the
     * enumeration #579's acceptance criteria demand be proved rather than asserted.
     */
    public function testNoShippedDesignTokenValueIsNewlyRejected(): void
    {
        $checked = 0;
        foreach (pp_design_tokens() as $token => $data) {
            $value = (string) $data['value'];
            $this->assertNull(
                _pp_forbidden_css_construct($value),
                "shipped token {$token} = '{$value}' must not hit the widened reject set"
            );
            $checked++;
        }
        $this->assertGreaterThan(0, $checked, 'no tokens read — the walk is broken');
    }

    /** The same enumeration for every shipped style-slot default. */
    public function testNoShippedSlotDefaultIsNewlyRejected(): void
    {
        foreach (pp_get_registered_components() as $component => $schema) {
            foreach (($schema['styling']['style_slots'] ?? []) as $slot => $def) {
                $this->assertNull(
                    _pp_forbidden_css_construct((string) ($def['default'] ?? '')),
                    "{$component} {$slot} default must not hit the widened reject set"
                );
            }
        }
    }

    /** Ruling 2: restore never blocks, for this rejection either. */
    public function testRestoreNeverBlocksOnACommentOpenerAndReportsIt(): void
    {
        $id = pp_create_page('Comment-opener snapshot');
        pp_update_composition($id, [['component' => 'stats', 'props' => ['items' => [
            ['number' => '99%', 'label' => 'Uptime'],
        ]]]]);
        $raw = pp_get_composition($id);
        $raw[0]['style'] = ['--stats-number-font' => 'serif /*'];
        $this->seedRaw($id, $raw);
        pp_update_composition($id, [['component' => 'stats', 'props' => ['items' => [
            ['number' => '1', 'label' => 'Later'],
        ]]]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore must never block');
        $this->assertSame('serif /*', pp_get_composition($id)[0]['style']['--stats-number-font']);
        $this->assertContains('invalid_style_value', array_column($result['findings'], 'type'));
    }

    /**
     * And the render side still drops it, so a restored page loses ONE declaration
     * rather than every declaration after it. This is the property that makes the
     * write-time rejection safe to add rather than merely strict.
     */
    public function testARestoredCommentOpenerIsDroppedWithoutTakingItsSiblings(): void
    {
        $id = pp_create_page('Comment-opener render');
        $this->seedRaw($id, [[
            'component' => 'stats',
            'props'     => ['items' => [['number' => '99%', 'label' => 'Uptime']]],
            'style'     => [
                '--stats-number-font'  => 'serif /*',
                '--stats-number-color' => '#ff0000',
            ],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('serif /*', $html);
        $this->assertStringContainsString('--stats-number-color: #ff0000', $html);
    }

    // ── A-30 — the length grammar can express `none` ─────────────────────────

    /**
     * `--stats-max-width` DECLARED `default: "none"` while its grammar could not
     * express it, so ai-instructions documented the workaround verbatim: "set 100%,
     * the type has no none input". A declared default nobody can author is a third
     * state. Authored through the real action, not the validator directly.
     */
    public function testNoneIsAcceptedOnTheBandGeometrySlot(): void
    {
        $id = pp_create_page('Max-width none', 'draft');
        pp_update_composition($id, [['component' => 'stats', 'props' => ['items' => [
            ['number' => '99%', 'label' => 'Uptime'],
        ]]]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--stats-max-width' => 'none'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'the declared default must be authorable');
        $this->assertSame('none', pp_get_composition($id)[0]['style']['--stats-max-width']);
        $this->assertStringContainsString('--stats-max-width: none', $this->renderStored($id));
    }

    /**
     * NOT a global widening. `none` on a padding or a font-size is a value CSS
     * drops, so those slots keep the plain `length` grammar and keep rejecting it —
     * which is what stops this fix from re-opening the accepted-but-dead class it
     * was meant to close.
     *
     * @dataProvider lengthSlotsThatMustRejectNone
     */
    public function testNoneIsStillRejectedOnOrdinaryLengthSlots(string $component, string $slot): void
    {
        $slots  = pp_get_style_slots($component);
        $this->assertSame('length', $slots[$slot]['type'] ?? null, "{$slot} must stay a plain length slot");

        $result = _pp_validate_token_value('none', 'length');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_length', $result->get_error_code());
        $this->assertFalse(pp_render_style_value_allowed('none', 'length'), 'and the render boundary agrees');
    }

    public static function lengthSlotsThatMustRejectNone(): array
    {
        return [
            'padding'        => ['stats', '--stats-padding-top'],
            'font size'      => ['stats', '--stats-number-size'],
            'text measure'   => ['section', '--section-body-measure'],
        ];
    }

    /** The keyword is the ONLY addition: everything else the grammar rejected, it still rejects. */
    public function testLengthOrNoneAcceptsNothingElseNew(): void
    {
        $this->assertTrue(_pp_validate_token_value('none', 'length-or-none') === true);
        $this->assertTrue(_pp_validate_token_value('NONE', 'length-or-none') === true, 'CSS keywords are case-insensitive');
        $this->assertTrue(_pp_validate_token_value('40rem', 'length-or-none') === true);
        foreach (['auto', 'unset', 'initial', 'inherit', 'fit-content', '1.2.3rem'] as $bad) {
            $this->assertInstanceOf(
                WP_Error::class,
                _pp_validate_token_value($bad, 'length-or-none'),
                "{$bad} must stay rejected"
            );
        }
    }

    // ── A-19 — item scope is enforced at RENDER too ──────────────────────────

    /**
     * The raw-meta seed the acceptance criteria call for, for GRID — by far the
     * larger surface (20 of 37 slots are item-eligible against section's 1).
     *
     * `--grid-gap` is container-scoped: it is read on the list, so setting it on one
     * card does nothing but still landed in the card's inline style attribute. The
     * write path has rejected it since #323; a raw meta write or a restore
     * (which never blocks, by ruling) is exactly how it reaches storage anyway.
     */
    public function testContainerScopedSlotIsNotEmittedOnAGridCard(): void
    {
        $id = pp_create_page('Grid item scope');
        $this->seedRaw($id, [[
            'component' => 'grid',
            'props'     => ['items' => [
                ['title' => 'Card', 'style' => [
                    '--grid-gap'             => '4rem',   // container-scoped
                    '--grid-item-bg'         => '#ff0000', // item-eligible
                ]],
            ]],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('--grid-gap', $html, 'a container-scoped slot must not reach the <li>');
        $this->assertStringContainsString('--grid-item-bg: #ff0000', $html, 'the item-eligible sibling still paints');
    }

    /** The same seed for SECTION's panel row, the other render call site. */
    public function testContainerScopedSlotIsNotEmittedOnASectionPanelRow(): void
    {
        $eligible = array_keys(pp_item_eligible_slots(pp_get_style_slots('section')));
        $this->assertNotEmpty($eligible, 'section must declare at least one item-eligible slot');
        $item_slot = $eligible[0];

        $id = pp_create_page('Section item scope');
        $this->seedRaw($id, [[
            'component' => 'section',
            'props'     => [
                'title'       => 'Rows',
                'layout'      => 'text-panel', // panel rows render only in this layout
                'panel_items' => [
                    ['label' => 'A', 'value' => 'B', 'style' => [
                        '--section-padding-top' => '9rem',      // container-scoped
                        $item_slot              => '#ff0000',   // item-eligible
                    ]],
                ],
            ],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('--section-padding-top', $html);
        $this->assertStringContainsString($item_slot . ': #ff0000', $html);
    }

    /**
     * Byte-identity for the COMPONENT-level map: narrowing must apply to item scope
     * only. A container-scoped slot on the component's own style still renders,
     * which is the whole point of it being container-scoped.
     */
    public function testComponentLevelStyleStillRendersContainerScopedSlots(): void
    {
        $id = pp_create_page('Grid container scope');
        $this->seedRaw($id, [[
            'component' => 'grid',
            'props'     => ['items' => [['title' => 'Card']]],
            'style'     => ['--grid-gap' => '4rem'],
        ]]);

        $this->assertStringContainsString('--grid-gap: 4rem', $this->renderStored($id));
    }

    /**
     * Opt-in by presence, mirroring the write path: a component whose slots carry no
     * item_eligible flag keeps the FULL set, so an un-annotated component that gains
     * a per-item style is not wholesale stripped by this shared renderer.
     */
    public function testItemScopeIsANoOpForAComponentWithNoEligibleSlots(): void
    {
        $this->assertSame([], pp_item_eligible_slots(pp_get_style_slots('hero')), 'fixture assumption');
        $this->assertSame(
            pp_render_style_vars(['--hero-bg' => '#ff0000'], 'hero'),
            pp_render_style_vars(['--hero-bg' => '#ff0000'], 'hero', true)
        );
    }

    /**
     * The shared predicate's own guard. A malformed/scalar slot definition reaching
     * `!empty($def['item_eligible'])` would change the item-scope set on BOTH the
     * write and the render path at once — the exact coupled failure that sharing one
     * predicate was meant to make impossible.
     */
    public function testItemEligiblePredicateIgnoresMalformedSlotDefinitions(): void
    {
        $this->assertSame(
            ['--y'],
            array_keys(pp_item_eligible_slots([
                '--x' => 'not-an-array',
                '--y' => ['item_eligible' => true],
                '--z' => ['item_eligible' => false],
            ]))
        );
    }

    /** Write and render read the SAME predicate, not two that can drift. */
    public function testWriteAndRenderShareTheItemEligibilityPredicate(): void
    {
        foreach (['grid', 'section'] as $component) {
            $eligible = pp_item_eligible_slots(pp_get_style_slots($component));
            foreach (pp_get_style_slots($component) as $slot => $def) {
                $accepted_at_write = _pp_validate_style_slot_map(
                    [$slot => $def['type'] === 'color' ? '#ff0000' : ($def['default'] ?: '0')],
                    pp_get_style_slots($component),
                    $component,
                    0
                );
                $scoped_out_at_write = is_wp_error($accepted_at_write)
                    && str_contains($accepted_at_write->get_error_message(), 'container-scoped');
                $this->assertSame(
                    !isset($eligible[$slot]),
                    $scoped_out_at_write,
                    "{$component} {$slot}: write scope must follow the shared predicate"
                );
            }
        }
    }

    // ── A-27 — nested item-field contracts ───────────────────────────────────

    /**
     * The named case: a logos entry with a `label` and no `image_url` validates,
     * persists, returns ok:true and renders NOTHING — and the empty_section smell
     * stays silent because it fires only when NO entry has an image, so a strip of
     * four logos that lost one URL warned about nothing at all.
     *
     * Authored through the real action (14.1): raw-meta seeding would bypass the
     * very contract under test.
     */
    public function testLogosItemWithoutAnImageUrlIsRejectedAtWrite(): void
    {
        $id = pp_create_page('Logos missing url', 'draft');
        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [[
                'component' => 'logos',
                'props'     => ['items' => [
                    ['image_url' => '/a.png', 'image_alt' => 'A'],
                    ['label' => 'Acme'],
                ]],
            ]],
        ]);

        $this->assertFalse($result['ok'], 'a silently-disappearing entry must not be accepted');
        $this->assertStringContainsString('item 1', $result['error']);
        $this->assertStringContainsString('image_url', $result['error']);
    }

    /**
     * Every nested `required: true` declaration in the shipped schemas is enforced —
     * walked from the schemas themselves, so a new declaration is covered the day it
     * lands rather than the day someone remembers to extend this list.
     */
    public function testEveryNestedRequiredDeclarationIsEnforced(): void
    {
        $checked = 0;
        foreach (pp_get_registered_components() as $component => $schema) {
            foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                if (($prop_def['type'] ?? null) !== 'array' || !is_array($prop_def['items'] ?? null)) {
                    continue;
                }
                $required = [];
                foreach ($prop_def['items'] as $field => $field_def) {
                    if (is_array($field_def) && !empty($field_def['required'])) {
                        $required[] = $field;
                    }
                }
                if ($required === []) {
                    continue;
                }
                foreach ($required as $omitted) {
                    // One entry carrying every required field EXCEPT the one under test.
                    $entry = [];
                    foreach ($required as $field) {
                        if ($field !== $omitted) {
                            $entry[$field] = 'x';
                        }
                    }
                    $result = pp_validate_composition([[
                        'component' => $component,
                        'props'     => $this->minimalProps($component, [$prop_name => [$entry]]),
                    ]]);
                    $this->assertInstanceOf(
                        WP_Error::class,
                        $result,
                        "{$component}.{$prop_name}[].{$omitted} declares required:true and must be enforced"
                    );
                    $this->assertStringContainsString($omitted, $result->get_error_message());
                    $checked++;
                }
            }
        }
        // SEVEN, not the eight #579's body enumerates. The body's list counts
        // `grid.items[].number`, which ships as `required: false` — its description
        // says "Required when grid layout is 'steps'", i.e. it is CONDITIONALLY
        // required, and the nested-required pass evaluates no `applies_when`.
        // Flipping it to required:true to reach eight would reject EVERY ordinary
        // card grid, so the enumeration in the issue body is the thing that is off
        // by one, not the schema. The walk is schema-driven, so if `number` ever
        // gains a conditional-required contract it is covered the day it lands.
        $this->assertSame(
            7,
            $checked,
            'every nested required:true declaration in the shipped schemas must be exercised'
        );
    }

    /**
     * `required` MIRRORS the top-level rule exactly: the key being ABSENT is the
     * violation, a present-but-blank value is not. Deliberate — every action
     * validates the WHOLE composition, so a newly-rejected stored shape would block
     * edits to unrelated bands on the same page.
     */
    public function testNestedRequiredTreatsAPresentBlankValueAsSatisfied(): void
    {
        $this->assertTrue(pp_validate_composition([[
            'component' => 'logos',
            'props'     => ['items' => [['image_url' => '', 'image_alt' => '']]],
        ]]));
    }

    /** Ruling 2 again, for the nested-required rejection. */
    public function testRestoreNeverBlocksOnAMissingNestedRequiredField(): void
    {
        $id = pp_create_page('Logos snapshot');
        pp_update_composition($id, [['component' => 'logos', 'props' => ['items' => [
            ['image_url' => '/a.png', 'image_alt' => 'A'],
        ]]]]);
        $this->seedRaw($id, [['component' => 'logos', 'props' => ['items' => [
            ['label' => 'Acme'],
        ]]]]);
        pp_update_composition($id, [['component' => 'logos', 'props' => ['items' => [
            ['image_url' => '/b.png', 'image_alt' => 'B'],
        ]]]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore must never block');
        $this->assertSame([['label' => 'Acme']], pp_get_composition($id)[0]['props']['items']);
        $this->assertContains('invalid_composition', array_column($result['findings'], 'type'));
    }

    /**
     * table.rows carried no item_type, so entries were never type-checked and
     * table.php's `foreach ((array) $row as $cell)` CAST a scalar row into a
     * one-cell row — a write that reported ok:true and produced a broken table.
     */
    public function testTableRejectsAScalarRow(): void
    {
        $id = pp_create_page('Scalar row', 'draft');
        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [[
                'component' => 'table',
                'props'     => ['headers' => ['A', 'B'], 'rows' => [['1', '2'], 'oops']],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('rows', $result['error']);
        $this->assertStringContainsString('item 1', $result['error']);
    }

    /**
     * And table.headers now type-checks its entries through the #475 family.
     * Authored through the real action surface, matching its `rows` sibling (14.1).
     */
    public function testTableRejectsANonStringHeader(): void
    {
        $id     = pp_create_page('Bad header', 'draft');
        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [[
                'component' => 'table',
                'props'     => ['headers' => ['A', ['nested']], 'rows' => [['1', '2']]],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('headers', $result['error']);
    }

    /** A well-formed table still validates — the annotations reject shapes, not content. */
    public function testAWellFormedTableStillValidates(): void
    {
        $this->assertTrue(pp_validate_composition([[
            'component' => 'table',
            'props'     => ['headers' => ['A', 'B'], 'rows' => [['1', '2'], ['3', '4']]],
        ]]));
    }

    /**
     * grid.items[].bullets is a NESTED array; the #475 bounded-string-array family
     * walks top-level props only, so its new `item_type: "string"` annotation is
     * enforced by the nested pass. A non-string bullet reached the renderer, which
     * escapes each entry and printed "Array".
     */
    public function testGridBulletsRejectANonStringEntry(): void
    {
        $id = pp_create_page('Bad bullets', 'draft');
        $result = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [[
                'component' => 'grid',
                'props'     => ['items' => [['title' => 'Card', 'bullets' => ['ok', ['nested']]]]],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('bullets', $result['error']);
    }

    /** String bullets are untouched — the array stays UNBOUNDED here, by scope. */
    public function testGridBulletsAcceptAnyNumberOfStrings(): void
    {
        $this->assertTrue(pp_validate_composition([[
            'component' => 'grid',
            'props'     => ['items' => [['title' => 'Card', 'bullets' => array_fill(0, 40, 'line')]]],
        ]]));
    }

    // ── #614 — declared SCALAR types on a nested item field ──────────────────
    //
    // A-27 shipped two nested rules — `required` and `item_type: "string"` — and a
    // nested field's own `type` was still enforced by nothing. The write path
    // therefore accepted ANY value for a `number`-typed field, which matters because
    // PHP's cast is not a rejection:
    //
    //     (int) ['attachment_id' => 42]  => 1        <- the sharp one
    //     (int) true                     => 1        <- and its twin
    //     (int) 'abc'                    => 0
    //
    // so a renderer doing `(int) ($item['image_id'] ?? 0)` resolved attachment ID 1 —
    // typically the site's FIRST upload — and discarded the author's image_url. The
    // page rendered a confidently wrong image behind an ok:true. The shape is
    // plausible rather than adversarial: the field description says "get an attachment
    // id via the import_media apply", and import_media returns
    // {attachment_id, url, action}, so passing the whole object through lands exactly
    // on `(int) [...] === 1`.
    //
    // The rule mirrors the #507 top-level pass through ONE shared predicate
    // (_pp_schema_scalar_value_is_valid), so "what string and number mean at the write
    // path" has one definition and cannot drift between the two depths.

    /**
     * The reported shape, through the real action surface (14.1): the import_media
     * envelope passed whole into image_id. Rejected with the standard envelope, and —
     * the half that makes the rejection worth having — NOTHING is written.
     */
    public function testNestedNumberFieldRejectsTheImportMediaEnvelope(): void
    {
        $id = pp_create_page('Logos import_media envelope', 'draft');
        pp_update_composition($id, [['component' => 'logos', 'props' => ['items' => [
            ['image_url' => '/a.png', 'image_alt' => 'A'],
        ]]]]);

        $result = pp_execute_action('update_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'props'           => ['items' => [[
                'image_url' => '/a.png',
                'image_alt' => 'A',
                'image_id'  => ['attachment_id' => 42, 'url' => '/a.png', 'action' => 'imported'],
            ]]],
        ]);

        $this->assertFalse($result['ok'], 'a non-numeric image_id must not be accepted');
        $this->assertStringContainsString('image_id', $result['error']);
        $this->assertStringContainsString('must be a number', $result['error']);
        $this->assertStringContainsString('item 0', $result['error']);
        $this->assertArrayNotHasKey(
            'image_id',
            pp_get_composition($id)[0]['props']['items'][0],
            'a rejected write must leave the stored composition untouched'
        );
    }

    /**
     * Every write path validates through the same engine, so the rejection must not
     * depend on which action the agent reached for. create_page and add_component are
     * separate entry points (lib/actions.php), not aliases of update_composition.
     *
     * @dataProvider nestedTypeWritePaths
     */
    public function testEveryWritePathRejectsANonScalarNestedField(callable $write): void
    {
        $result = $write($this);
        $this->assertFalse($result['ok'], 'every write path must reject the same shape');
        $this->assertStringContainsString('image_id', $result['error']);
    }

    public static function nestedTypeWritePaths(): array
    {
        $bad = ['image_url' => '/a.png', 'image_alt' => 'A', 'image_id' => ['attachment_id' => 42]];
        $band = ['component' => 'logos', 'props' => ['items' => [$bad]]];

        return [
            'create_page' => [static fn () => pp_execute_action('create_page', [
                'title'       => 'Bad id at creation',
                'composition' => [$band],
            ])],
            'update_composition' => [static function () use ($band) {
                $id = pp_create_page('Bad id via update_composition', 'draft');
                return pp_execute_action('update_composition', [
                    'post_id' => $id, 'composition' => [$band],
                ]);
            }],
            'add_component' => [static function () use ($band) {
                $id = pp_create_page('Bad id via add_component', 'draft');
                pp_update_composition($id, [['component' => 'hero', 'props' => ['title' => 'T']]]);
                return pp_execute_action('add_component', [
                    'post_id' => $id, 'component' => 'logos', 'props' => $band['props'],
                ]);
            }],
        ];
    }

    /**
     * The accept side, which is the half that decides whether the rule is shippable:
     * the sentinels and the ordinary values an agent really writes must all survive.
     * A numeric STRING is the one worth naming — a JSON/CLI write sends "42", and the
     * top-level #507 number rule already accepts it, so rejecting it here would make
     * the two depths disagree.
     *
     * @dataProvider acceptedNestedScalarValues
     */
    public function testNestedScalarFieldsAcceptTheirLegitimateValues(string $field, $value): void
    {
        $entry = ['image_url' => '/a.png', 'image_alt' => 'A'];
        $entry[$field] = $value;

        $this->assertTrue(
            pp_validate_composition([['component' => 'logos', 'props' => ['items' => [$entry]]]]),
            sprintf('logos.items[].%s must accept %s', $field, var_export($value, true))
        );
    }

    public static function acceptedNestedScalarValues(): array
    {
        return [
            'number: int'            => ['image_id', 42],
            'number: numeric string' => ['image_id', '42'],
            'number: float'          => ['image_id', 42.5],
            'number: zero'           => ['image_id', 0],
            'number: negative'       => ['image_id', -5],
            'number: null sentinel'  => ['image_id', null],
            'number: empty sentinel' => ['image_id', ''],
            'string: empty string'   => ['label', ''],
            'string: null sentinel'  => ['label', null],
            'string: an int'         => ['label', 7],
            'string: a bool'         => ['label', true],
        ];
    }

    /**
     * The reject side, per declared type. `true` and `'abc'` are in the number list
     * deliberately: `is_numeric()` is false for both, exactly as the #507 top-level
     * number rule already has it — the point is one definition at both depths, not a
     * softer one down here.
     *
     * @dataProvider rejectedNestedScalarValues
     */
    public function testNestedScalarFieldsRejectTheirIllegitimateValues(
        string $field,
        $value,
        string $expected
    ): void {
        $entry = ['image_url' => '/a.png', 'image_alt' => 'A'];
        $entry[$field] = $value;

        $result = pp_validate_composition([['component' => 'logos', 'props' => ['items' => [$entry]]]]);

        $this->assertInstanceOf(
            WP_Error::class,
            $result,
            sprintf('logos.items[].%s must reject %s', $field, var_export($value, true))
        );
        $this->assertSame('invalid_prop_value', $result->get_error_code());
        $this->assertStringContainsString($expected, $result->get_error_message());
        $this->assertStringContainsString($field, $result->get_error_message());
    }

    public static function rejectedNestedScalarValues(): array
    {
        return [
            'number: import_media object' => ['image_id', ['attachment_id' => 42], 'must be a number'],
            'number: list'                => ['image_id', ['a', 'b'], 'must be a number'],
            'number: empty array'         => ['image_id', [], 'must be a number'],
            'number: true'                => ['image_id', true, 'must be a number'],
            'number: non-numeric string'  => ['image_id', 'abc', 'must be a number'],
            'string: object'              => ['label', ['text' => 'Acme'], 'must be a string'],
            'string: list'                => ['label', ['Acme'], 'must be a string'],
            'string: empty array'         => ['label', [], 'must be a string'],
        ];
    }

    /**
     * The rule is schema-driven, not logos-driven: every component that declares a
     * nested `number` field is covered the day it lands. Walked from the schemas so a
     * future declaration cannot quietly opt out of the gate.
     */
    public function testEveryNestedNumberFieldIsEnforcedAcrossTheShippedSchemas(): void
    {
        $checked = 0;
        foreach (pp_get_registered_components() as $component => $schema) {
            foreach (($schema['props'] ?? []) as $prop_name => $prop_def) {
                if (($prop_def['type'] ?? null) !== 'array' || !is_array($prop_def['items'] ?? null)) {
                    continue;
                }
                foreach ($prop_def['items'] as $field => $field_def) {
                    if (!is_array($field_def) || ($field_def['type'] ?? null) !== 'number') {
                        continue;
                    }
                    // Carry every required sibling so the required rule cannot be what fails.
                    $entry = [];
                    foreach ($prop_def['items'] as $sibling => $sibling_def) {
                        if (is_array($sibling_def) && !empty($sibling_def['required'])) {
                            $entry[$sibling] = 'x';
                        }
                    }
                    $entry[$field] = ['attachment_id' => 42];

                    $result = pp_validate_composition([[
                        'component' => $component,
                        'props'     => $this->minimalProps($component, [$prop_name => [$entry]]),
                    ]]);
                    $this->assertInstanceOf(
                        WP_Error::class,
                        $result,
                        "{$component}.{$prop_name}[].{$field} declares type:number and must be enforced"
                    );
                    // Name the field, not just "something was rejected". The sibling-fill
                    // loop above assigns 'x' to every required sibling regardless of the
                    // sibling's own declared type, so a future component declaring a
                    // required nested `number` field would be rejected on the SIBLING and
                    // this walk would pass without ever exercising the field under test.
                    $this->assertStringContainsString($field, $result->get_error_message());
                    $this->assertStringContainsString('must be a number', $result->get_error_message());
                    $checked++;
                }
            }
        }
        // grid, logos and testimonials each declare items[].image_id (#584 took the
        // field to 3/3). A fourth declaration is covered the day it lands; this count
        // is here so ADDING one is a deliberate act rather than a silent widening.
        $this->assertSame(3, $checked, 'every nested number field in the shipped schemas must be exercised');
    }

    /**
     * The scope fence, asserted rather than described — and #600 moved one of its two
     * posts. The nested `enum` half USED to assert that an out-of-set text_role still
     * validated (#614's fence: scalar types only, enums deferred); it now asserts the
     * opposite, because RULE 4 landed on this same traversal. The nested `object` half
     * is unchanged and is what is left of the fence: nothing has decided what an item
     * style object may contain, and neither the scalar rule nor the enum rule may
     * sweep it up on the way past.
     */
    public function testNestedEnumsAreEnforcedAndObjectFieldsAreNot(): void
    {
        $enum = pp_validate_composition([['component' => 'grid', 'props' => ['items' => [
            ['title' => 'Card', 'text_role' => 'not-a-declared-role'],
        ]]]]);
        $this->assertInstanceOf(
            \WP_Error::class,
            $enum,
            'a nested enum is strict since #600 — the accept-and-coerce gap is closed'
        );
        $this->assertSame('invalid_prop_value', $enum->get_error_code());

        $this->assertTrue(
            pp_validate_composition([['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card', 'style' => ['--grid-item-bg' => '#fff']],
            ]]]]),
            'a nested object field must not be swept up by a scalar-type or enum rule'
        );
    }

    /**
     * WRITE/RENDER CONVERGENCE for the nested enum, which is the gate's whole claim
     * and the reason #600 belongs in this file. Before it, the write path accepted
     * `text_role: "terminal"` and the renderer acted on nothing — the two sets
     * disagreed by exactly the set of unknown strings. Now the write path rejects
     * precisely what the renderer would have ignored.
     *
     * The RENDER side is proved through a RAW META SEED, deliberately: that is the
     * only way an out-of-set role can still reach the renderer once the write path
     * refuses it (a raw database write, or a restore_composition of an old snapshot,
     * which by rule never blocks). It proves the grid.php allowlist is still load-
     * bearing rather than redundant with the new gate — an arbitrary authored string
     * never becomes a CSS class.
     */
    public function testTheNestedEnumWriteRejectionMatchesWhatTheRendererIgnores(): void
    {
        $this->assertInstanceOf(\WP_Error::class, pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card', 'text' => 'Body', 'text_role' => 'terminal'],
            ]]],
        ]), 'the write path rejects the role the renderer would ignore');

        $post_id = pp_create_page('Nested enum render convergence', 'draft');
        $this->seedRaw($post_id, [
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card', 'text' => 'Body', 'text_role' => 'terminal'],
            ]]],
        ]);
        $html = $this->renderStored($post_id);

        $this->assertStringContainsString('class="grid__item-text"', $html, 'the unknown role renders as plain body text');
        $this->assertStringNotContainsString('text-terminal', $html, 'an unadvertised role never reaches a class attribute');
        $this->assertStringContainsString('Body', $html, 'the card itself still renders');
    }

    /**
     * section.panel_items accepts MIXED string and object entries, so the traversal
     * skips non-object entries entirely. A plain-string panel item must keep
     * validating, or this rule would break the shape it never intended to touch.
     */
    public function testMixedStringAndObjectPanelItemsStillValidate(): void
    {
        $this->assertTrue(pp_validate_composition([['component' => 'section', 'props' => [
            'body'        => 'x',
            'panel_items' => ['a plain string row', ['label' => 'Seats', 'value' => '12']],
        ]]]));
    }

    /**
     * First-error order within an entry follows SCHEMA DECLARATION ORDER, not rule
     * precedence. Both the required rule and the type rule `continue 4`, so whichever
     * field is declared first in the component's `items` map reports and ends the
     * item. Pinned in BOTH directions deliberately, because the tempting summary —
     * "a missing required field always wins over a type error" — is false, and a test
     * that asserted only the first case would read as proving a rule that does not
     * exist. logos declares image_url, image_alt, image_id in that order.
     */
    public function testFirstErrorInAnEntryFollowsSchemaDeclarationOrder(): void
    {
        // image_alt (required, declared 2nd) is missing; image_id (declared 3rd) is
        // the wrong type. The earlier declaration reports.
        $required_first = pp_validate_composition([['component' => 'logos', 'props' => ['items' => [
            ['image_url' => '/a.png', 'image_id' => ['attachment_id' => 42]],
        ]]]]);
        $this->assertInstanceOf(WP_Error::class, $required_first);
        $this->assertSame('invalid_composition', $required_first->get_error_code());
        $this->assertStringContainsString('image_alt', $required_first->get_error_message());

        // Reverse it: image_url (declared 1st) is the wrong type while image_alt
        // (declared 2nd, required) is missing. Now the TYPE error reports first.
        $type_first = pp_validate_composition([['component' => 'logos', 'props' => ['items' => [
            ['image_url' => ['/a.png']],
        ]]]]);
        $this->assertInstanceOf(WP_Error::class, $type_first);
        $this->assertSame('invalid_prop_value', $type_first->get_error_code());
        $this->assertStringContainsString('image_url', $type_first->get_error_message());
        $this->assertStringNotContainsString('image_alt', $type_first->get_error_message());
    }

    /**
     * The exact message text, pinned once per branch of the shared renderer. Without
     * this the helper is invisible to the suite — its whole job is the "; got X" half,
     * and docs/reference-apply-cli.md quotes this string verbatim.
     *
     * The boolean case is the one worth the assertion: PHP's `(string) true` is "1",
     * so the obvious implementation tells an agent its rejected value "1" is not a
     * number. The helper prints `true` instead.
     *
     * @dataProvider rejectionMessageShapes
     */
    public function testRejectionMessagesRenderTheOffendingValueHonestly(
        $value,
        string $expected
    ): void {
        $result = pp_validate_composition([['component' => 'logos', 'props' => ['items' => [
            ['image_url' => '/a.png', 'image_alt' => 'A', 'image_id' => $value],
        ]]]]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame($expected, $result->get_error_message());
    }

    public static function rejectionMessageShapes(): array
    {
        // Band-named because this runs the WRITE path (#642); the value rendering this
        // provider owns is the "; got X" tail, which is identical on both surfaces.
        $prefix = 'Component 0 ("logos") prop "items" item 0 field "image_id" must be a number; got ';
        return [
            'container degrades to its type' => [['attachment_id' => 42], $prefix . 'array.'],
            'scalar is quoted'               => ['abc', $prefix . '"abc".'],
            'true is not rendered as 1'      => [true, $prefix . 'true.'],
            'false is not rendered as blank' => [false, $prefix . 'false.'],
        ];
    }

    /**
     * An author-supplied value is echoed into the message, and that message reaches a
     * terminal (WP_CLI::error writes it raw), an action envelope and the editor save
     * response. So it gets the same strip-and-cap the file's other reflection helpers
     * apply: no control characters, bounded length.
     */
    public function testARejectedValueIsStrippedAndBoundedBeforeItIsEchoedBack(): void
    {
        $hostile = "ESC\x1b[31m and a newline\nand a zero-width\u{200b}mark " . str_repeat('A', 400);
        $result  = pp_validate_composition([['component' => 'logos', 'props' => ['items' => [
            ['image_url' => '/a.png', 'image_alt' => 'A', 'image_id' => $hostile],
        ]]]]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $message = $result->get_error_message();
        $this->assertStringNotContainsString("\x1b", $message, 'no escape sequences reach a terminal');
        $this->assertStringNotContainsString("\n", $message, 'no newlines break a log line');
        $this->assertStringNotContainsString("\u{200b}", $message, 'no zero-width marks');
        $this->assertStringContainsString('...', $message, 'an over-long value is truncated');
        $this->assertLessThan(250, mb_strlen($message), 'one bad value cannot bloat the envelope');
    }

    /**
     * Ruling 2, for the new rejection: restore_composition REPORTS and restores. A
     * page that already stores the bad shape must still be recoverable — the gate is
     * on writes, never on undo (#233).
     */
    public function testRestoreNeverBlocksOnANonScalarNestedField(): void
    {
        $id = pp_create_page('Logos bad id snapshot');
        pp_update_composition($id, [['component' => 'logos', 'props' => ['items' => [
            ['image_url' => '/a.png', 'image_alt' => 'A'],
        ]]]]);
        $this->seedRaw($id, [['component' => 'logos', 'props' => ['items' => [
            ['image_url' => '/a.png', 'image_alt' => 'A', 'image_id' => ['attachment_id' => 42]],
        ]]]]);
        pp_update_composition($id, [['component' => 'logos', 'props' => ['items' => [
            ['image_url' => '/b.png', 'image_alt' => 'B'],
        ]]]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore must never block');
        $this->assertSame(
            ['attachment_id' => 42],
            pp_get_composition($id)[0]['props']['items'][0]['image_id'],
            'restore puts the snapshot back verbatim'
        );
        $this->assertContains('invalid_prop_value', array_column($result['findings'], 'type'));
    }

    // ── A-34 — the warn channel ──────────────────────────────────────────────

    /**
     * A transparent fill is WELL-FORMED and legal; it is only INEFFECTIVE in this
     * one context, so it warns and never blocks. The observed failure:
     * fill=rgba(0,0,0,0) ring=rgba(0,0,0,0) ink=rgb(252,253,255) on a white page —
     * a button that is present, focusable, clickable and completely invisible.
     *
     * @dataProvider fillSlotFamily
     */
    public function testATransparentFillWarnsWithoutBlocking(string $component, string $slot, array $props): void
    {
        $id     = pp_create_page("Transparent {$slot}", 'draft');
        pp_update_composition($id, [['component' => $component, 'props' => $props]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => [$slot => 'transparent'],
        ]);
        $this->assertTrue($result['ok'], $result['error'] ?? 'a transparent fill must NEVER block the write');
        $this->assertSame('transparent', pp_get_composition($id)[0]['style'][$slot]);

        $warnings = pp_validate_composition_smells(pp_get_composition($id));
        $matching = array_values(array_filter($warnings, static fn ($w) => $w['type'] === 'transparent_fill'));
        $this->assertCount(1, $matching, "{$slot} must raise exactly one advisory");
        $this->assertStringContainsString($slot, $matching[0]['message']);
        $this->assertStringContainsString('outline', $matching[0]['message'], 'the advisory must name the intended route');
        $this->assertSame(0, $matching[0]['index']);
    }

    public static function fillSlotFamily(): array
    {
        // Each row must actually RENDER the button its fill slot paints. Since #580 an
        // inert declaration reports `inert_slot` and suppresses the value-level advisory,
        // because "this transparent fill makes the button invisible" is not true of a
        // button that is not on the page — so a button2 row needs `button2_text` and the
        // panel-CTA row needs the `text-panel` layout, or the fixture would be asserting
        // the fill contract against markup that never renders.
        $cta      = ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/'];
        $cta2     = $cta + ['button2_text' => 'More', 'button2_url' => '/more'];
        $hero     = ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/'];
        $hero2    = $hero + ['button2_text' => 'More', 'button2_url' => '/more'];
        $section  = [
            'title'          => 'Go',
            'body'           => 'B',
            'layout'         => 'text-panel',
            'panel_cta_text' => 'Go',
            'panel_cta_url'  => '/',
        ];
        return [
            '--cta-button-bg'          => ['cta', '--cta-button-bg', $cta],
            '--cta-button-hover-bg'    => ['cta', '--cta-button-hover-bg', $cta],
            '--cta-button2-bg'         => ['cta', '--cta-button2-bg', $cta2],
            '--cta-button2-hover-bg'   => ['cta', '--cta-button2-hover-bg', $cta2],
            '--hero-button-bg'         => ['hero', '--hero-button-bg', $hero],
            '--hero-button-hover-bg'   => ['hero', '--hero-button-hover-bg', $hero],
            '--hero-button2-bg'        => ['hero', '--hero-button2-bg', $hero2],
            '--hero-button2-hover-bg'  => ['hero', '--hero-button2-hover-bg', $hero2],
            '--section-panel-cta-bg'   => ['section', '--section-panel-cta-bg', $section],
        ];
    }

    /**
     * A HOVER fill gets DIFFERENT advice. The same value means two different things:
     * on a resting fill it is the invisible-button defect, on a hover fill it only
     * flattens the pointer state — and telling an author already on the `outline`
     * variant to use `outline` is advice that cannot be acted on.
     */
    public function testAHoverFillAdvisoryDoesNotRecommendTheOutlineVariant(): void
    {
        $warnings = pp_validate_composition_smells([[
            'component' => 'cta',
            'props'     => ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/', 'button_variant' => 'outline'],
            'style'     => ['--cta-button-hover-bg' => 'transparent'],
        ]]);
        $matching = array_values(array_filter($warnings, static fn ($w) => $w['type'] === 'transparent_fill'));

        $this->assertCount(1, $matching);
        $this->assertStringContainsString('no fill on hover', $matching[0]['message']);
        $this->assertStringNotContainsString(
            'use the "outline" button variant',
            $matching[0]['message'],
            'the hover advisory must not point an outline-variant author back at outline'
        );
    }

    /** The advisory carries the component id when one is authored, like empty_section. */
    public function testTheFillAdvisoryCarriesTheComponentId(): void
    {
        $with = pp_validate_composition_smells([[
            'component' => 'cta',
            'props'     => ['id' => 'pp-a1b2c3', 'title' => 'Go', 'button_text' => 'Go', 'button_url' => '/'],
            'style'     => ['--cta-button-bg' => 'transparent'],
        ]]);
        $this->assertSame('pp-a1b2c3', $with[0]['id']);

        $without = pp_validate_composition_smells([[
            'component' => 'cta',
            'props'     => ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/'],
            'style'     => ['--cta-button-bg' => 'transparent'],
        ]]);
        $this->assertArrayNotHasKey('id', $without[0]);
    }

    /**
     * Smells run over arbitrary history-ring snapshots and raw-meta writes, so a
     * style map here can carry a non-string key or an array value. The guard must
     * skip those silently — without it the cast below emits "Array to string
     * conversion" inside a path that is documented never to block.
     */
    public function testTheFillAdvisoryIgnoresMalformedStyleEntries(): void
    {
        $warnings = pp_validate_composition_smells([[
            'component' => 'cta',
            'props'     => ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/'],
            'style'     => ['--cta-button-bg' => ['transparent'], 7 => 'transparent'],
        ]]);
        $this->assertNotContains('transparent_fill', array_column($warnings, 'type'));
    }

    /** `currentColor` is the other value that resolves to "no fill you can see". */
    public function testCurrentColorOnAFillSlotWarnsToo(): void
    {
        $warnings = pp_validate_composition_smells([[
            'component' => 'cta',
            'props'     => ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/'],
            'style'     => ['--cta-button-bg' => 'currentColor'],
        ]]);
        $this->assertContains('transparent_fill', array_column($warnings, 'type'));
    }

    /**
     * The advisory reads the DECLARED `role: "fill"` marker, never a `-bg` name
     * convention — a convention is a second source of truth, which is the defect the
     * definition-surface contract fixes one layer down. `--cta-bg` is a `-bg` slot
     * with no fill role: transparent is its shipped DEFAULT and must stay silent.
     */
    public function testATransparentNonFillBackgroundDoesNotWarn(): void
    {
        $this->assertNull(pp_get_style_slots('cta')['--cta-bg']['role'] ?? null, 'fixture assumption');
        $warnings = pp_validate_composition_smells([[
            'component' => 'cta',
            'props'     => ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/'],
            'style'     => ['--cta-bg' => 'transparent'],
        ]]);
        $this->assertNotContains('transparent_fill', array_column($warnings, 'type'));
    }

    /** A real colour on a fill slot is silent — the advisory is not a fill-slot alarm. */
    public function testAnOpaqueFillDoesNotWarn(): void
    {
        $warnings = pp_validate_composition_smells([[
            'component' => 'cta',
            'props'     => ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/'],
            'style'     => ['--cta-button-bg' => 'var(--color-accent)'],
        ]]);
        $this->assertNotContains('transparent_fill', array_column($warnings, 'type'));
    }

    /**
     * A LEGACY slot name warns about NOTHING (#603). `--hero-cta2-bg` was renamed to
     * `--hero-button2-bg` by #576 and its alias entry was deleted by #603, so the name
     * is undeclared: it paints nothing, and "this transparent fill makes the button
     * invisible" is not true of a declaration the renderer drops. The advisory channel
     * stays quiet; the ERROR channel reports the dead slot instead.
     *
     * The canonical name's own coverage is unaffected — `--hero-button2-bg` is a row in
     * fillSlotFamily above, exercised through the full authoring path.
     */
    public function testARetiredLegacyFillSlotNameNoLongerWarns(): void
    {
        $items = [[
            'component' => 'hero',
            'props'     => ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/', 'button2_text' => 'More', 'button2_url' => '/more'],
            'style'     => ['--hero-cta2-bg' => 'transparent'],
        ]];

        $this->assertNotContains('transparent_fill', array_column(pp_validate_composition_smells($items), 'type'));

        $errors = pp_validate_composition_errors($items);
        $this->assertNotSame([], $errors, 'the dead slot is reported as an error, not an advisory');
        $this->assertStringContainsString(
            '--hero-cta2-bg',
            implode(' | ', array_map(static fn ($e) => $e->get_error_message(), $errors)),
            'reported somewhere in the findings, not necessarily first'
        );
    }

    /**
     * Minimal valid props for a component, merged with an override — so the
     * schema-walked nested-required test isolates the field it omits instead of
     * tripping a component's own top-level required props or content gate.
     */
    private function minimalProps(string $component, array $override): array
    {
        $base = [
            'faq'          => ['items' => []],
            'grid'         => ['items' => []],
            'logos'        => ['items' => []],
            'stats'        => ['items' => []],
            'table'        => ['headers' => ['A'], 'rows' => [['1']]],
            'testimonials' => ['items' => []],
            'section'      => ['body' => 'x'],
            'cta'          => ['button_text' => 'Go', 'button_url' => '/'],
            'hero'         => ['title' => 'Go'],
            'embed'        => ['content' => 'x'],
        ];
        return array_merge($base[$component] ?? [], $override);
    }
}
