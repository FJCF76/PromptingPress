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
use PromptingPress\Tests\Support\FixtureTheme;

class WriteRenderGrammarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // THE BAND HERE IS A FIXTURE, NOT A SUBJECT (#1025). The mechanism under test
        // is the slot engine; which component carries the slots is incidental, which is
        // why this whole set was re-homed hero -> section -> stats over three rebuilds.
        // It targets `ppfixture` now, so stats' rebuild is the last one that moved it.
        FixtureTheme::activate();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
            'custom_css' => '', 'filters' => [],
        ];
    }

    protected function tearDown(): void
    {
        // MUST pair with the activate() above. PHPUnit runs every class in ONE process,
        // so a fixture root left in force is inherited by every later class — which does
        // not look like a leak, it looks like the UDC suites suddenly seeing a component
        // that declares no roles. Pinned in FixtureThemeSeamTest.
        FixtureTheme::deactivate();
        parent::tearDown();
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
        pp_update_composition($id, [['component' => 'ppfixture', 'props' => ['items' => [
            ['number' => '99%', 'label' => 'Uptime'],
        ]]]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--ppfixture-number-font' => 'serif /*'],
        ]);

        $this->assertFalse($result['ok'], 'a value the renderer would drop must not be accepted at write');
        $this->assertStringContainsString('--ppfixture-number-font', $result['error']);
        $this->assertStringNotContainsString(
            'serif /*',
            pp_get_composition($id)[0]['style']['--ppfixture-number-font'] ?? '',
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
        pp_update_composition($id, [['component' => 'ppfixture', 'props' => ['items' => [
            ['number' => '99%', 'label' => 'Uptime'],
        ]]]]);
        $raw = pp_get_composition($id);
        $raw[0]['style'] = ['--ppfixture-number-font' => 'serif /*'];
        $this->seedRaw($id, $raw);
        pp_update_composition($id, [['component' => 'ppfixture', 'props' => ['items' => [
            ['number' => '1', 'label' => 'Later'],
        ]]]]);

        $result = pp_execute_action('restore_composition', ['post_id' => $id, 'steps_back' => 1]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'restore must never block');
        $this->assertSame('serif /*', pp_get_composition($id)[0]['style']['--ppfixture-number-font']);
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
            'component' => 'ppfixture',
            'props'     => ['items' => [['number' => '99%', 'label' => 'Uptime']]],
            'style'     => [
                '--ppfixture-number-font'  => 'serif /*',
                '--ppfixture-number-color' => '#ff0000',
            ],
        ]]);

        $html = $this->renderStored($id);

        $this->assertStringNotContainsString('serif /*', $html);
        $this->assertStringContainsString('--ppfixture-number-color: #ff0000', $html);
    }

    // ── A-30 — the length grammar can express `none` ─────────────────────────

    /**
     * `--ppfixture-max-width` DECLARED `default: "none"` while its grammar could not
     * express it, so ai-instructions documented the workaround verbatim: "set 100%,
     * the type has no none input". A declared default nobody can author is a third
     * state. Authored through the real action, not the validator directly.
     */
    public function testNoneIsAcceptedOnTheBandGeometrySlot(): void
    {
        $id = pp_create_page('Max-width none', 'draft');
        pp_update_composition($id, [['component' => 'ppfixture', 'props' => ['items' => [
            ['number' => '99%', 'label' => 'Uptime'],
        ]]]]);

        $result = pp_execute_action('style_component', [
            'post_id'         => $id,
            'component_index' => 0,
            'style'           => ['--ppfixture-max-width' => 'none'],
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? 'the declared default must be authorable');
        $this->assertSame('none', pp_get_composition($id)[0]['style']['--ppfixture-max-width']);
        $this->assertStringContainsString('--ppfixture-max-width: none', $this->renderStored($id));
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
            // stats' two rows left this table at #1066 PR2 with its slot map, the way
            // section's went at #1023. The length-vs-length-or-none distinction they pinned
            // is hosted on the fixture now (#1025) — it is a claim about the GRAMMAR, not
            // about stats — and on the v2 side it is a role param type, pinned per
            // component in the RoleDefaultsEmitTests.
            'padding'        => ['ppfixture', '--ppfixture-padding-top'],
            'font size'      => ['ppfixture', '--ppfixture-image-size'],
            // section's row left this table at #1023 with its slot map. The
            // length-vs-length-or-none distinction it pinned lives on for the components
            // still on slots, and for section it is now a ROLE param type
            // (`length-or-none` on `sizing.max-width`), pinned in MeasureSurfaceTest.
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
     * THE A-19 ITEM-SCOPE RENDER TEST RETIRED AT #1101, and this is its replacement.
     *
     * (1) WHAT IT PROVED. testContainerScopedSlotIsNotEmittedOnAGridCard seeded a card
     *     carrying two slots through raw meta — `--grid-gap`, read on the LIST, and
     *     `--grid-item-bg`, read on the card — and asserted the renderer narrowed the
     *     card's inline style to the item-eligible one. The write path has rejected the
     *     container-scoped slot on a card since #323; a raw meta write or a
     *     restore_composition (which by ruling never blocks) is how it reaches storage
     *     anyway, so the RENDERER had to enforce the same fence.
     *
     * (2) WHY THE SUBJECT IS GONE — TWICE OVER, which is why this retires rather than
     *     re-homes. The section half of the pair was already deleted at #1023 when
     *     section's slot map went. What is left needs two things that no longer exist
     *     anywhere: a schema declaring `item_eligible` (grid's twenty were the last, and
     *     they retired with its v2 rebuild), and a component template that renders a
     *     per-item style map at all. Measured: `ppfixture.php` is now the ONLY caller of
     *     pp_render_style_vars() left in any component, and it renders the BAND's map —
     *     there is no item call site in the theme. Item-grain design is the `udc` map's
     *     job now (BUILD-SPEC Addendum B), with its own emission and its own tests.
     *
     * (3) WHERE THE CLAIM WENT. The narrowing FUNCTION is unchanged and still has the
     *     no-op pin below, re-homed onto the fixture. What has no successor is the
     *     end-to-end proof that a render call site narrows, because there is no such
     *     call site. Reported rather than repaired: pp_item_eligible_slots() and
     *     pp_render_style_vars()'s `$item_scope` parameter are now dead code reachable
     *     only from tests, and the slot engine they belong to goes in this task's PR2.
     *
     * (4) THE PIN. The census below fails the moment a schema declares `item_eligible`
     *     again, which is when the end-to-end test should come back.
     */
    public function testNoSchemaDeclaresItemEligibleSlotsSoTheRenderNarrowingHasNoCallSite(): void
    {
        $eligible = [];
        $slots    = 0;
        foreach (pp_get_registered_components() as $component => $schema) {
            $declared = $schema['styling']['style_slots'] ?? [];
            $slots   += count($declared);
            foreach (pp_item_eligible_slots($declared) as $slot => $definition) {
                $eligible[] = "{$component}{$slot}";
            }
        }

        // NOT VACUOUS: the registry still has to declare slots for the narrowing to have
        // had anything to narrow, or this would be the emptiness of an empty registry.
        $this->assertGreaterThan(0, $slots, 'no style slots remain at all — this census proves nothing');
        $this->assertSame(
            [],
            $eligible,
            'A schema declares item_eligible slots again. The A-19 end-to-end render test retired at '
            . '#1101 because none did, and because no component renders a per-item style map any more '
            . '— restore it against this component if it also renders one.'
        );
    }

    /**
     * Byte-identity for the COMPONENT-level map: the narrowing must apply to item scope
     * only, so a component's own style map still renders in full.
     *
     * RE-HOMED FROM `grid` TO `ppfixture` AT #1101. grid's v2 rebuild retired its slot
     * map, so this band would now render nothing at all and the assertion would fail —
     * and `ppfixture.php` is the only component template left that renders a style map,
     * which makes it the only host this claim has. The slot it uses is deliberately
     * `--ppfixture-gap`, the fixture's strip-rhythm slot: a slot whose job is read on the
     * CONTAINER is exactly what the retired half of this pair proved is stripped from a
     * card and kept here.
     */
    public function testComponentLevelStyleStillRendersContainerScopedSlots(): void
    {
        $id = pp_create_page('Fixture container scope');
        $this->seedRaw($id, [[
            'component' => 'ppfixture',
            'props'     => ['items' => [['number' => '10', 'label' => 'Sites']]],
            'style'     => ['--ppfixture-gap' => '4rem'],
        ]]);

        $this->assertStringContainsString('--ppfixture-gap: 4rem', $this->renderStored($id));
    }

    /**
     * Opt-in by presence, mirroring the write path: a component whose slots carry no
     * item_eligible flag keeps the FULL set, so an un-annotated component that gains
     * a per-item style is not wholesale stripped by this shared renderer.
     *
     * RE-HOMED FROM `hero` TO `ppfixture` AT #1101, AND IT HAD GONE VACUOUS. hero went v2
     * at #986 and has declared no slots since, so BOTH sides of the byte-identity were the
     * empty string — the assertion held because there was nothing to render, not because
     * the narrowing was a no-op. `ppfixture` declares sixteen slots and none of them
     * `item_eligible`, which is the exact shape this test is about; the non-emptiness is
     * asserted below so it cannot rot the same way again.
     */
    public function testItemScopeIsANoOpForAComponentWithNoEligibleSlots(): void
    {
        $this->assertSame([], pp_item_eligible_slots(pp_get_style_slots('ppfixture')), 'fixture assumption');

        $rendered = pp_render_style_vars(['--ppfixture-bg' => '#ff0000'], 'ppfixture');
        $this->assertNotSame('', $rendered, 'the un-narrowed render must produce something to compare');
        $this->assertSame(
            $rendered,
            pp_render_style_vars(['--ppfixture-bg' => '#ff0000'], 'ppfixture', true)
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

    /**
     * Write and render read the SAME predicate, not two that can drift.
     *
     * RE-FOUNDED ON A SYNTHETIC SLOT MAP AT #1101, AND IT WAS SILENTLY RISKY BEFORE THE
     * CHANGE THAT FORCED THIS. The old body walked `['grid', 'section']` and asserted once
     * per declared slot; section's map retired at #1023 and grid's at #1101, so BOTH inner
     * loops ran zero times and the test performed no assertions at all — PHPUnit reported
     * it risky rather than failed, which is exactly how a fence stops being a fence
     * quietly. Written to `$slots` counters here so the same rot cannot recur.
     *
     * WHAT THIS CAN AND CANNOT STILL REACH, stated plainly. The WRITE half takes its
     * available-slot map as a PARAMETER, so it can be driven against a hand-built map that
     * declares both eligibilities — which is what runs below, and it is a stronger
     * exercise than the shipped schemas ever gave it (grid declared 20 eligible slots but
     * they all agreed, so a predicate that inverted would have failed on the container
     * ones only). The RENDER half resolves slots from a component NAME, so it cannot be
     * driven synthetically; no schema declares `item_eligible` and no component template
     * renders a per-item style map any more, so the render side of the convergence has no
     * subject. See testNoSchemaDeclaresItemEligibleSlotsSoTheRenderNarrowingHasNoCallSite
     * above for that census, and restore the paired walk when either comes back.
     */
    public function testWriteAndRenderShareTheItemEligibilityPredicate(): void
    {
        $available = [
            '--fence-gap'      => ['type' => 'length', 'default' => '1rem', 'description' => 'Container-scoped.'],
            '--fence-item-bg'  => ['type' => 'color', 'default' => '#ffffff', 'description' => 'Item-scoped.', 'item_eligible' => true],
            '--fence-padding'  => ['type' => 'length', 'default' => '2rem', 'description' => 'Container-scoped.'],
            '--fence-item-ink' => ['type' => 'color', 'default' => '#000000', 'description' => 'Item-scoped.', 'item_eligible' => true],
        ];

        $eligible = pp_item_eligible_slots($available);
        $this->assertSame(
            ['--fence-item-bg', '--fence-item-ink'],
            array_keys($eligible),
            'premise: the shared predicate splits this map two and two'
        );

        $checked = 0;
        foreach ($available as $slot => $def) {
            $written = _pp_validate_style_slot_map(
                [$slot => $def['type'] === 'color' ? '#ff0000' : $def['default']],
                $available,
                'fencebox',
                0
            );
            $scoped_out_at_write = is_wp_error($written)
                && str_contains($written->get_error_message(), 'container-scoped');

            $this->assertSame(
                !isset($eligible[$slot]),
                $scoped_out_at_write,
                "{$slot}: write scope must follow the shared predicate"
            );
            $checked++;
        }

        $this->assertSame(count($available), $checked, 'every slot in the map was actually judged');
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
            'string: ordinary text'  => ['label', 'Acme'],
            'string: numeric string' => ['label', '7'],
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
            // FLIPPED BY #707. `label` 7 and `label` true were in the ACCEPT list
            // above until the D-A ruling: the #507/#614 predicate read `is_scalar`,
            // so a nested string field took any scalar and stored it raw. Both depths
            // share that predicate, which is why one line moved both.
            'string: an int'              => ['label', 7, 'must be a string'],
            'string: a float'             => ['label', 3.14, 'must be a string'],
            'string: true'                => ['label', true, 'must be a string'],
            'string: false'               => ['label', false, 'must be a string'],
            // wp_json_encode(-0.0) emits `-0`, which decodes to int 0 — a FALSY
            // non-string scalar, the shape a truthiness gate would wave through.
            'string: negative zero'       => ['label', -0.0, 'must be a string'],
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
     * THE NESTED SCOPE-FENCE PAIR RETIRED AT #1101 — TWO TESTS, replaced by one claim
     * plus the successor truth about the field they drove.
     *
     * (1) WHAT THEY PROVED.
     *       - testNestedEnumsAreEnforcedAndObjectFieldsAreNot held both posts of #614's
     *         scope fence at once: a nested `enum` field is STRICT (RULE 4, #600 — the
     *         accept-and-coerce gap closed), while a nested `object` field is NOT swept
     *         up by the scalar-type or enum rules on the way past, because nothing had
     *         decided what an item style object may contain.
     *       - testTheNestedEnumWriteRejectionMatchesWhatTheRendererIgnores proved the two
     *         sides CONVERGE: the write path rejects precisely the roles the renderer
     *         would have ignored, with the render half seeded through raw meta because
     *         that (or a restore_composition, which never blocks) is the only way an
     *         out-of-set role can still reach a template.
     *
     * (2) WHY THE SUBJECTS ARE GONE. Both posts of the fence were `grid.items[]` fields
     *     and both retired with grid's v2 rebuild: `text_role` was the THEME'S LAST NESTED
     *     ENUM, and `style` its LAST NESTED OBJECT FIELD. The census below measures both.
     *     Neither can be re-homed onto the slot-engine fixture: `ppfixture` declares its
     *     nested fields under `item_fields`, a documentary key no engine path reads (the
     *     nested rules walk `props.<name>.items`), so a fixture field there would be
     *     ignored and the tests would pass or fail for unrelated reasons.
     *
     * (3) WHERE THE CLAIMS WENT. RULE 4's code (lib/admin.php) is untouched and simply has
     *     no declaring surface — reported, not repaired. The CONVERGENCE claim has a
     *     successor and it is asserted below, because the outcome for an author is what
     *     matters and it got stricter rather than weaker: `text_role` is no longer an
     *     out-of-set VALUE on a known field, it is an unknown FIELD, so the write is
     *     refused by name and the renderer — which has no role classes left at all — emits
     *     nothing for it. That is the same write/render convergence one level up.
     *
     * (4) THE PIN. The census fails the moment a schema declares a nested enum or a nested
     *     object field again, which is when the fence needs both its posts back.
     */
    public function testNoNestedEnumOrObjectFieldRemainsAndTheRetiredRoleIsRefusedByName(): void
    {
        $nested_enums   = [];
        $nested_objects = [];
        $nested_fields  = 0;
        foreach (pp_get_registered_components() as $component => $schema) {
            foreach (($schema['props'] ?? []) as $prop => $definition) {
                if (!is_array($definition) || !isset($definition['items']) || !is_array($definition['items'])) {
                    continue;
                }
                foreach ($definition['items'] as $field => $field_def) {
                    if (!is_array($field_def)) {
                        continue;
                    }
                    $nested_fields++;
                    if (($field_def['type'] ?? null) === 'enum') {
                        $nested_enums[] = "{$component}.{$prop}[].{$field}";
                    }
                    if (($field_def['type'] ?? null) === 'object') {
                        $nested_objects[] = "{$component}.{$prop}[].{$field}";
                    }
                }
            }
        }

        // NOT VACUOUS: nested field declarations still have to exist for the emptiness to
        // be a statement about their TYPES rather than about their absence.
        $this->assertGreaterThan(0, $nested_fields, 'no nested item fields are declared at all — this census proves nothing');
        $this->assertSame([], $nested_enums, 'a nested enum field is declared again — restore the strict-enum half of the fence');
        $this->assertSame([], $nested_objects, 'a nested object field is declared again — restore the object half of the fence');

        // THE SUCCESSOR TRUTH, end to end, on the same field the retired pair drove.
        // WRITE: refused, and refused BY NAME — a silent accept is what #600 closed, and
        // an unknown-field refusal that did not name the field would send an agent hunting.
        $rejected = pp_validate_composition([
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card', 'text' => 'Body', 'text_role' => 'terminal'],
            ]]],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $rejected, 'the retired role must not be quietly accepted');
        // `retired_prop`, NOT `unknown_prop`, AND THE CHANGE IS AN UPGRADE ON THIS TEST'S
        // OWN TERMS (#1101). This asserted `unknown_prop` while grid's rebuild was being
        // written, because the item-FIELD gate did not consult `retired_props` and every
        // retired item key fell through to the typo arm — so a field that had moved
        // answered with a list of live field names, which is the one thing §3.1 says a
        // refusal must not do. The gate consults it now.
        //
        // The claim this test makes is "refused, and refused BY NAME, because an
        // unknown-field refusal that did not name the field would send an agent hunting".
        // `retired_prop` still names the field and additionally names WHERE THE VALUE
        // WENT, so the agent does not have to hunt at all — it is the same claim, better
        // served. The out-of-set-value distinction the old message carried is preserved:
        // a `text_role` of `terminal` is not reported as an invalid enum value, because
        // there is no enum left to be out of.
        $this->assertSame(
            'retired_prop',
            $rejected->get_error_code(),
            'it is a RETIRED field now — not an out-of-set value, and not a typo either'
        );
        $this->assertStringContainsString('text_role', $rejected->get_error_message());
        $this->assertStringContainsString(
            'card-text',
            $rejected->get_error_message(),
            'and the refusal names the role that replaced it, which is what stops the hunt'
        );

        // RENDER: the raw-meta seed, for the population the write path can no longer
        // create — aged storage and restore_composition. The card still renders, and the
        // dead role reaches no class attribute.
        $post_id = pp_create_page('Retired nested role', 'draft');
        $this->seedRaw($post_id, [
            ['component' => 'grid', 'props' => ['items' => [
                ['title' => 'Card', 'text' => 'Body', 'text_role' => 'terminal'],
            ]]],
        ]);
        $html = $this->renderStored($post_id);

        $this->assertStringContainsString('Body', $html, 'the card itself still renders');
        $this->assertStringNotContainsString('text-terminal', $html, 'an unadvertised role never reaches a class attribute');
        $this->assertStringNotContainsString('text_role', $html, 'nor does the stored field name itself');
    }

    /**
     * section.panel_items accepts MIXED string and object entries, so the traversal
     * skips non-object entries entirely. A plain-string panel item must keep
     * validating, or this rule would break the shape it never intended to touch.
     */
    public function testMixedStringAndObjectPanelItemsStillValidate(): void
    {
        // `layout` is explicit since #1023: the panel props are REFUSED as `inert_prop`
        // on any layout that does not render the panel, which is the #1006-class rule
        // section's rebuild added. The mixed-entry traversal this case is about is
        // unchanged; it just has to author a band that actually shows a panel.
        $this->assertTrue(pp_validate_composition([['component' => 'section', 'props' => [
            'body'        => 'x',
            'layout'      => 'text-panel',
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

    // RETIRED (#1026) — AND THE RETIREMENT CARRIES A DISCLOSURE, not just a re-home.
    //
    // The `transparent_fill` advisory (#579) warns that a colour slot marked `role: "fill"`
    // was set to `transparent` / `currentColor`, which validates and stores but paints a
    // button no one can see. It recognises a fill by that DECLARED marker, deliberately, and
    // not by a `-bg` name convention. cta's four button fills were the last slots in the
    // theme that declared it — hero's left at #986 and `--section-panel-cta-bg` at #1023 —
    // so as of this change NO shipped slot declares `role: "fill"` and the advisory has no
    // reachable subject. These tests could only be kept by inventing a fixture slot that
    // does not ship, which is the vacuous-pin shape this suite exists to refuse.
    //
    // WHAT THIS MEANS, stated plainly because it is a real gap rather than a tidy move: the
    // v2 equivalent — a role's `background.fill: "transparent"` — is accepted with no
    // advisory at all. The marker, the engine and `pp_slot_roles()` all remain, so a v1
    // component could still declare it and the advisory would fire; what has no successor is
    // the WARNING on the v2 surface that replaced the slots. Filed rather than fixed here:
    // adding an advisory to the UDC engine is a change to the shared engine's finding
    // vocabulary, which is not cta's rebuild to make.

    // fillSlotFamily() IS GONE (#1026 review). It was the dataProvider for the
    // transparent_fill advisory tests, every row naming a retired `--cta-button*` slot, and it
    // had no `@dataProvider` consumer left. It also carried a latent bug worth recording: four
    // of its keys were DUPLICATED, so PHP silently collapsed the eight written rows to four and
    // the provider had been half the size it read as.




    /**
     * Does ANY shipped schema still declare a `role: "fill"` slot?
     *
     * The transparent_fill advisory recognises a fill by that DECLARED marker, so with an
     * empty roster it can fire on nothing and every negative control for it passes vacuously.
     * #1026's review proved exactly that: deleting the advisory's whole value check left all
     * 5086 tests green. Rather than delete the controls (they encode real behaviour) or leave
     * them reading as coverage they no longer provide, they SKIP while the roster is empty and
     * wake up by themselves the moment a component declares a fill slot again. Tracked as
     * #1036, which asks whether the advisory should be retired or kept as a forward guard.
     */
    private function fillSlotRosterIsEmpty(): bool
    {
        foreach (array_keys(pp_get_registered_components()) as $component) {
            foreach (pp_get_style_slots($component) as $slot) {
                if (is_array($slot) && ($slot['role'] ?? null) === 'fill') {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Smells run over arbitrary history-ring snapshots and raw-meta writes, so a
     * style map here can carry a non-string key or an array value. The guard must
     * skip those silently — without it the cast below emits "Array to string
     * conversion" inside a path that is documented never to block.
     */
    public function testTheFillAdvisoryIgnoresMalformedStyleEntries(): void
    {
        if ($this->fillSlotRosterIsEmpty()) {
            $this->markTestSkipped(
                'no shipped schema declares role: "fill" since #1026, so the advisory has no '
                . 'reachable subject and this control cannot fail (#1036)'
            );
        }
        $warnings = pp_validate_composition_smells([[
            'component' => 'cta',
            'props'     => ['title' => 'Go', 'button_text' => 'Go', 'button_url' => '/'],
            'style'     => ['--cta-button-bg' => ['transparent'], 7 => 'transparent'],
        ]]);
        $this->assertNotContains('transparent_fill', array_column($warnings, 'type'));
    }


    /**
     * The advisory reads the DECLARED `role: "fill"` marker, never a `-bg` name
     * convention — a convention is a second source of truth, which is the defect the
     * definition-surface contract fixes one layer down.
     *
     * THIS ONE IS STILL NON-VACUOUS, unlike its two siblings above, but for a DIFFERENT
     * reason than its old docblock claimed. It used to say "`--cta-bg` is a `-bg` slot with
     * no fill role". `--cta-bg` does not exist at all since #1026 — cta declares no slots —
     * so what the fixture now exercises is an UNDECLARED name, which the advisory must also
     * pass over in silence. The name is left in place deliberately: an undeclared `-bg` slot
     * is the sharpest possible test that the advisory keys on the marker and not on the name.
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
        if ($this->fillSlotRosterIsEmpty()) {
            $this->markTestSkipped(
                'no shipped schema declares role: "fill" since #1026, so the advisory has no '
                . 'reachable subject and this control cannot fail (#1036)'
            );
        }
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
     * the retired fillSlotFamily provider, exercised through the full authoring path.
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
