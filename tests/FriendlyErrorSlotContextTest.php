<?php
/**
 * tests/FriendlyErrorSlotContextTest.php — the chat error builder describes the
 * slot set that was actually judged (#626).
 *
 * `style_component`'s validate callable resolves the target component, reads the
 * slots it declares, and expands any recipe — from ONE composition read — and
 * rejects the first slot the component doesn't declare. The chat's preview handler
 * then turns that WP_Error into the message the author reads.
 *
 * Before #626 the builder answered from its own second look at the world: it read
 * the composition again (so a write landing in between could make it describe a
 * component that had rejected nothing) and it rebuilt the candidate slot set from
 * `$params['style']` alone (so recipe-contributed slots were invisible to it and
 * the `__recipe` tracking key looked like a slot).
 *
 *   pp_preview_action('style_component')            [lib/actions.php]
 *     └─ validate: ONE pp_get_composition() read
 *          ├─ component_name  ─┐
 *          ├─ available_slots ─┼─ attached to the WP_Error as data
 *          └─ candidate_slots    ─┘   (recipe ∪ style, minus __recipe, minus nulls)
 *                                        │
 *   _pp_build_friendly_error()  [lib/ai-chat.php]
 *     ├─ data present  → describe THAT component and THAT slot set (no second read)
 *     └─ data absent   → best-effort composition read (pre-#626 behaviour, kept for
 *                        producers of this code that attach nothing)
 *
 * The tests pair the two calls exactly as the AJAX preview handler does — the
 * handler body itself is a closure registered through add_action, which is a no-op
 * in this bootstrap — and author every page through the real write surfaces
 * (pp_create_page + the update_composition action), never a raw meta write.
 */

use PHPUnit\Framework\TestCase;

class FriendlyErrorSlotContextTest extends TestCase
{
    /** @var string|null Fixture theme root, when a test swapped it in. */
    private ?string $fixtureRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [],
            'posts'     => [],
            'options'   => [],
            'next_id'   => 100,
        ];
    }

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            unset($GLOBALS['_pp_test_template_dir']);
            $this->deleteTree($this->fixtureRoot);
            $this->fixtureRoot = null;
        }
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Authors a page through the real write path: pp_create_page for the post,
     * the update_composition ACTION (schema-validated) for the composition.
     */
    private function authorPage(string $title, array $composition): int
    {
        $post_id = pp_create_page($title);
        $this->assertIsInt($post_id, 'Page creation must succeed.');

        $written = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => $composition,
        ]);
        $this->assertTrue($written['ok'], 'Fixture composition must pass the authoring surface: ' . ($written['error'] ?? ''));

        return $post_id;
    }

    /**
     * The chat preview pairing: validate through the action layer, then build the
     * response the handler would send. Returns [WP_Error, friendly response].
     */
    private function rejectThenReport(array $params): array
    {
        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error, 'The proposal must be rejected.');
        $this->assertSame('invalid_style_slot', $error->get_error_code());

        return [$error, _pp_build_friendly_error($error, $params)];
    }

    /**
     * Writes a fixture theme root with the given components and points the
     * template directory at it. Each component gets the render file the registry
     * scan requires plus a schema declaring `id` (pp_update_composition injects
     * one into every stored component, so a component that doesn't declare it
     * fails the next validated write — issue 147).
     *
     * @param array $components  name => ['style_slots' => [...], 'recipes' => [...]]
     */
    private function useFixtureTheme(array $components): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/pp-626-' . getmypid() . '-' . mt_rand();

        foreach ($components as $name => $styling) {
            $dir = $this->fixtureRoot . '/components/' . $name;
            // Asserted, not assumed: a fixture that fails to land would otherwise
            // surface far downstream as "this component declares no slots".
            $this->assertTrue(mkdir($dir, 0755, true), "Fixture dir {$dir} must be created.");
            $this->assertNotFalse(file_put_contents($dir . '/' . $name . '.php', "<?php // fixture component\n"));
            $this->assertNotFalse(file_put_contents($dir . '/schema.json', json_encode([
                'description' => 'Fixture component for #626.',
                'props'       => [
                    'id' => ['type' => 'string', 'required' => false, 'default' => '', 'description' => 'Anchor id.'],
                ],
                'styling'     => [
                    'root_class'  => $name,
                    'style_slots' => $styling['style_slots'],
                    'recipes'     => $styling['recipes'] ?? [],
                ],
            ], JSON_PRETTY_PRINT)));
        }

        $GLOBALS['_pp_test_template_dir'] = $this->fixtureRoot;
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── The rejection carries the validator's own answer ───────────────────

    public function testRejectionCarriesTheComponentAndSlotsItWasJudgedAgainst(): void
    {
        $post_id = $this->authorPage('Judged context', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        [$error] = $this->rejectThenReport([
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#1a1a2e'],
        ]);

        $data = $error->get_error_data();
        $this->assertIsArray($data, 'The rejection must carry its context as error data.');
        $this->assertSame('hero', $data['component_name']);
        $this->assertSame(
            pp_get_style_slots('hero'),
            $data['available_slots'],
            'The declared slot map travels with the error, descriptions and all.'
        );
        $this->assertSame(['--hero-bgs'], $data['candidate_slots']);
    }

    public function testCandidateSlotsAreRecipeExpandedAndSkipTheTrackingKeyAndRemovals(): void
    {
        // A recipe, a removal, and one undeclared name in a single proposal. The
        // judged set is what the validator looped over: the recipe's slots plus the
        // undeclared name — never `__recipe` (a tracking key, not a CSS property),
        // never the null (a removal the validator passes over).
        $post_id = $this->authorPage('Judged set', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        [$error] = $this->rejectThenReport([
            'post_id'         => $post_id,
            'component_index' => 0,
            'recipe'          => 'compact',
            'style'           => ['--hero-heading-color' => null, '--hero-bgs' => '#1a1a2e'],
        ]);

        $candidates = $error->get_error_data()['candidate_slots'];

        $this->assertNotContains('__recipe', $candidates, 'The recipe tracking key is not a slot.');
        $this->assertNotContains('--hero-heading-color', $candidates, 'A null is a removal, not a value to judge.');
        $this->assertContains('--hero-bgs', $candidates, 'The undeclared name the author wrote is judged.');
        foreach (array_keys(pp_get_style_recipes('hero')['compact']['slots']) as $recipe_slot) {
            $this->assertContains($recipe_slot, $candidates, 'Every slot the recipe contributed is judged.');
        }
    }

    // ── The report describes the component that actually rejected ──────────

    public function testReportDescribesTheRejectingComponentAfterTheTargetIsRetyped(): void
    {
        // The dangerous shape of the race: between validate and report, the slot
        // that was rejected becomes a slot the component at that index DOES
        // declare. Answering from a second read would tell the author the setting
        // is available on a component that never saw their proposal.
        $post_id = $this->authorPage('Retyped target', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-bg' => '#1a1a2e'],
        ];
        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error);

        // A concurrent proposal replaces the band with a `section` — which declares
        // the very slot the hero rejected.
        $swapped = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'section', 'props' => ['title' => 'Swapped', 'body' => 'Replacement copy.']]],
        ]);
        $this->assertTrue($swapped['ok']);
        $this->assertArrayHasKey('--section-bg', pp_get_style_slots('section'));

        $friendly = _pp_build_friendly_error($error, $params);

        $this->assertSame(
            array_keys(pp_get_style_slots('hero')),
            $friendly['alternatives'],
            'The alternatives are the rejecting component\'s slots, not the current occupant\'s.'
        );
        $this->assertStringContainsString('hero', $friendly['user_message']);
        // The hint itself is the proof that the rejected name was still judged as
        // unknown: judged against `section` it is a declared slot and would have
        // produced no hint at all. Which component the scan names first is registry
        // order (an exact match on `section`, a suffix match on any `--*-bg`), so
        // the assertion is that the named component really declares what it claims.
        $hints = (array) $friendly['cross_component_hints'];
        $this->assertArrayHasKey('--section-bg', $hints, 'The rejected slot is real elsewhere, and the hint says where.');
        $hint = $hints['--section-bg'];
        $this->assertNotSame('hero', $hint['component'], 'A hint points away from the component that rejected.');
        $this->assertArrayHasKey($hint['slot'], pp_get_style_slots($hint['component']));
    }

    public function testReportSurvivesTheTargetBeingRemovedBetweenValidateAndReport(): void
    {
        $post_id = $this->authorPage('Removed target', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
            ['component' => 'section', 'props' => ['title' => 'Second', 'body' => 'Second band copy.']],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#1a1a2e'],
        ];
        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error);

        $removed = pp_execute_action('remove_component', [
            'post_id'         => $post_id,
            'component_index' => 0,
        ]);
        $this->assertTrue($removed['ok']);

        $friendly = _pp_build_friendly_error($error, $params);

        $this->assertSame(
            array_keys(pp_get_style_slots('hero')),
            $friendly['alternatives'],
            'A component that is gone by report time still gets its own slot list reported.'
        );
        $this->assertStringContainsString('hero', $friendly['user_message']);
        $this->assertStringNotContainsString('(none)', $friendly['user_message']);
    }

    public function testIdTargetedRejectionIsNotRephrasedAsAMissingComponent(): void
    {
        // An id-targeted proposal resolves through the composition, so a write that
        // removes that id used to flip a real slot rejection into "I couldn't find
        // that component" — an answer that contradicts the rejection in hand.
        $post_id = $this->authorPage('Id target', [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hi']],
        ]);

        $params = [
            'post_id'      => $post_id,
            'component_id' => 'pp-aabb1122',
            'style'        => ['--hero-bgs' => '#1a1a2e'],
        ];
        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error);

        $replaced = pp_execute_action('update_composition', [
            'post_id'     => $post_id,
            'composition' => [['component' => 'hero', 'props' => ['id' => 'pp-ccdd3344', 'title' => 'Replaced']]],
        ]);
        $this->assertTrue($replaced['ok']);
        $this->assertSame(-1, _pp_resolve_component_index_for_error($params), 'The targeted id is gone by report time.');

        $friendly = _pp_build_friendly_error($error, $params);

        $this->assertStringNotContainsString('couldn\'t find that component', $friendly['user_message']);
        $this->assertSame(array_keys(pp_get_style_slots('hero')), $friendly['alternatives']);
        $this->assertStringContainsString('--hero-bgs', $friendly['raw_error']);
    }

    public function testPhantomKeysNeverReachTheCrossComponentScan(): void
    {
        // `__recipe` and removals are not slots, so they are not unknown slots
        // either. Submitted alongside exactly as many real unknown keys as the scan
        // will examine, they are visible in the one place that counts what the scan
        // did not reach: a phantom key would push the count above zero.
        $post_id = $this->authorPage('Phantom keys', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $style = ['__recipe' => 'dark-spacious', '--hero-removed-thing' => null];
        for ($i = 0; $i < PP_CROSS_COMPONENT_HINT_MAX; $i++) {
            $style['--hero-unknown-' . $i] = '#101010';
        }

        [$error, $friendly] = $this->rejectThenReport([
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => $style,
        ]);

        $candidates = $error->get_error_data()['candidate_slots'];
        $this->assertNotContains('__recipe', $candidates);
        $this->assertNotContains('--hero-removed-thing', $candidates);
        $this->assertCount(PP_CROSS_COMPONENT_HINT_MAX, $candidates);

        $this->assertArrayNotHasKey(
            'unknown_slots_unscanned',
            $friendly,
            'Exactly the scannable number of real unknown keys leaves nothing unscanned.'
        );
        $this->assertObjectNotHasProperty('__recipe', $friendly['cross_component_hints']);
    }

    public function testTheScanBoundStillFiresAndCountsOnTheAuthoritativeAnswer(): void
    {
        // The other side of the same boundary, on the path production takes. The cap
        // and the count it reports are arithmetic over the candidate set, and #626
        // changed where that set comes from — so the overflow case has to be pinned
        // against a rejection the validator actually produced, not a hand-built one.
        $overflow = 7;
        $post_id  = $this->authorPage('Scan bound', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $style = ['__recipe' => 'dark-spacious'];
        for ($i = 0; $i < PP_CROSS_COMPONENT_HINT_MAX + $overflow; $i++) {
            $style['--hero-unknown-' . $i] = '#101010';
        }

        [$error, $friendly] = $this->rejectThenReport([
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => $style,
        ]);

        $this->assertNotNull(pp_rejected_slot_context($error), 'This is the authoritative path.');
        $this->assertSame(
            $overflow,
            $friendly['unknown_slots_unscanned'],
            'The count reports the candidate keys the scan never reached — the tracking key is not one of them.'
        );
        $this->assertLessThanOrEqual(
            PP_CROSS_COMPONENT_HINT_MAX,
            count((array) $friendly['cross_component_hints']),
            'The scan cannot emit more hints than the keys it was allowed to examine.'
        );
    }

    public function testACrossComponentHintOnTheAuthoritativeAnswerStillNamesWhereTheSlotLives(): void
    {
        // The hint mechanism itself, exercised through the path production takes:
        // the pre-#626 hint tests all hand-build the rejection, so they now cover
        // the fallback branch only.
        $post_id = $this->authorPage('Hint on the real path', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        [, $friendly] = $this->rejectThenReport([
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--section-panel-bg' => '#101010'],
        ]);

        $hints = (array) $friendly['cross_component_hints'];
        $this->assertArrayHasKey('--section-panel-bg', $hints);
        $this->assertSame('section', $hints['--section-panel-bg']['component']);
        $this->assertSame('exact', $hints['--section-panel-bg']['match']);
        $this->assertStringContainsString('section', $friendly['user_message']);
    }

    // ── A recipe that drifts out of its component's declared slots ─────────

    public function testASlotOnlyTheRecipeContributedStillGetsItsCrossComponentHint(): void
    {
        // The case the issue was filed for: nothing the author wrote is invalid —
        // there is no `style` param at all. The rejected name enters through recipe
        // expansion, which the old builder could not see, so it reported no hints
        // for a name that exists on the component next door.
        $this->useFixtureTheme([
            'driftbox' => [
                'style_slots' => [
                    '--driftbox-bg' => ['type' => 'color', 'default' => '#fff', 'description' => 'Driftbox background'],
                ],
                'recipes'     => [
                    'drifted' => [
                        'description' => 'A recipe that outran its component\'s slot set.',
                        'slots'       => ['--driftbox-bg' => '#111111', '--panelbox-shade' => '#222222'],
                    ],
                ],
            ],
            'panelbox' => [
                'style_slots' => [
                    '--panelbox-shade' => ['type' => 'color', 'default' => '#eee', 'description' => 'Panelbox shade'],
                ],
            ],
        ]);

        $post_id = $this->authorPage('Recipe drift', [
            ['component' => 'driftbox', 'props' => []],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'recipe'          => 'drifted',
        ];
        $this->assertArrayNotHasKey('style', $params, 'The author wrote no style at all.');

        [$error, $friendly] = $this->rejectThenReport($params);

        $this->assertStringContainsString('--panelbox-shade', $error->get_error_message());
        $this->assertContains('--panelbox-shade', $error->get_error_data()['candidate_slots']);

        $hints = (array) $friendly['cross_component_hints'];
        $this->assertArrayHasKey(
            '--panelbox-shade',
            $hints,
            'A recipe-contributed slot is a candidate for a hint like any other.'
        );
        $this->assertSame('panelbox', $hints['--panelbox-shade']['component']);
        $this->assertSame('exact', $hints['--panelbox-shade']['match']);
        $this->assertStringContainsString('panelbox', $friendly['user_message']);
    }

    public function testRecipeContributedSlotsAreJudgedAgainstTheRecipesOwnComponent(): void
    {
        // Expansion happens once, against the component the validator resolved, so
        // the reported set cannot come from another component's recipe of the same
        // name. Both fixtures declare a `drifted` recipe; only driftbox's is used.
        $this->useFixtureTheme([
            'driftbox' => [
                'style_slots' => [
                    '--driftbox-bg' => ['type' => 'color', 'default' => '#fff', 'description' => 'Driftbox background'],
                ],
                'recipes'     => [
                    'drifted' => ['description' => 'Drift.', 'slots' => ['--panelbox-shade' => '#222222']],
                ],
            ],
            'panelbox' => [
                'style_slots' => [
                    '--panelbox-shade' => ['type' => 'color', 'default' => '#eee', 'description' => 'Panelbox shade'],
                ],
                'recipes'     => [
                    'drifted' => ['description' => 'Not this one.', 'slots' => ['--panelbox-shade' => '#333333']],
                ],
            ],
        ]);

        $post_id = $this->authorPage('Recipe ownership', [
            ['component' => 'driftbox', 'props' => []],
        ]);

        [$error, $friendly] = $this->rejectThenReport([
            'post_id'         => $post_id,
            'component_index' => 0,
            'recipe'          => 'drifted',
        ]);

        $this->assertSame('driftbox', $error->get_error_data()['component_name']);
        $this->assertSame(['--driftbox-bg'], $friendly['alternatives']);
    }

    // ── Rejections that carry no context keep the old, best-effort answer ──

    public function testAContextlessRejectionStillFallsBackToTheCompositionRead(): void
    {
        // Not every producer of this error code is style_component's validator —
        // the shared engine in lib/admin.php attaches nothing. Such a rejection must
        // still be answerable, from the composition as it reads now.
        $post_id = $this->authorPage('No context', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component "hero" has no style slot "--hero-bgs". Available: --hero-bg'),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => ['--section-bg' => '#111']]
        );

        $this->assertSame(array_keys(pp_get_style_slots('hero')), $friendly['alternatives']);
        $hints = (array) $friendly['cross_component_hints'];
        $this->assertArrayHasKey('--section-bg', $hints, 'The fallback still judges the keys it can see.');
    }

    public function testAContextlessRejectionStillReportsAnUnresolvableTarget(): void
    {
        $post_id = $this->authorPage('No context, bad id', [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hi']],
        ]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component "hero" has no style slot "--hero-bgs".'),
            ['post_id' => $post_id, 'component_id' => 'pp-nosuchid', 'style' => ['--hero-bgs' => '#111']]
        );

        $this->assertStringContainsString('couldn\'t find that component', $friendly['user_message']);
        $this->assertSame([], $friendly['alternatives']);
    }

    public function testTheAnswerComesFromThePayloadRatherThanTheRegistryItCameFrom(): void
    {
        // The declared map travels WITH the rejection, and that is what is reported.
        // Asserting it against pp_get_style_slots() can't tell the two apart — the
        // registry is static per theme root, so in-process they always agree. A
        // well-formed payload that deliberately disagrees can.
        $post_id = $this->authorPage('Payload is the answer', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $friendly = _pp_build_friendly_error(
            new WP_Error(
                'invalid_style_slot',
                'Component "hero" has no style slot "--hero-bgs". Available: --hero-only-this',
                [
                    'component_name'  => 'hero',
                    'available_slots' => ['--hero-only-this' => ['type' => 'color', 'description' => 'Only this one']],
                    'candidate_slots' => ['--hero-bgs'],
                ]
            ),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => ['--hero-bgs' => '#111']]
        );

        $this->assertSame(['--hero-only-this'], $friendly['alternatives']);
        // The message names the payload's slot, not the registry's — since #661 it says
        // the NAME rather than the description, so this asserts on `--hero-only-this`.
        $this->assertStringContainsString('--hero-only-this', $friendly['user_message']);
        $this->assertNotContains(
            '--hero-bg',
            $friendly['alternatives'],
            'A second read of the registry would have put every hero slot here.'
        );
    }

    /**
     * @dataProvider malformedContexts
     */
    public function testAMalformedContextRoutesToTheFallbackRatherThanHalfAnAnswer($data): void
    {
        // Half a context is worse than none: an empty available_slots would render
        // as "It has no style settings" on a component declaring dozens.
        $post_id = $this->authorPage('Malformed context', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $error = new WP_Error('invalid_style_slot', 'Component "hero" has no style slot "--hero-bgs".', $data);
        $this->assertNull(pp_rejected_slot_context($error), 'A partial payload is not a context.');

        $friendly = _pp_build_friendly_error($error, [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#111'],
        ]);

        $this->assertSame(array_keys(pp_get_style_slots('hero')), $friendly['alternatives']);
        $this->assertNotEmpty($friendly['alternatives']);
    }

    public static function malformedContexts(): array
    {
        // One case per guard. The last three are the ones whose absence would not
        // merely degrade the message: an empty declared map renders as "It has no
        // style settings" on a component with dozens, and a candidate list holding
        // anything that is not an array key fatals in the consumer's slot lookups
        // instead of falling back.
        return [
            'no data at all'           => [''],
            'component name only'      => [['component_name' => 'hero']],
            'slots missing'            => [['component_name' => 'hero', 'candidate_slots' => ['--hero-bgs']]],
            'candidates missing'       => [['component_name' => 'hero', 'available_slots' => ['--hero-bg' => []]]],
            'name is not a string'     => [['component_name' => ['hero'], 'available_slots' => ['--hero-bg' => []], 'candidate_slots' => []]],
            'name is empty'            => [['component_name' => '', 'available_slots' => ['--hero-bg' => []], 'candidate_slots' => []]],
            'slots are not an array'   => [['component_name' => 'hero', 'available_slots' => '--hero-bg', 'candidate_slots' => []]],
            'slots are empty'          => [['component_name' => 'hero', 'available_slots' => [], 'candidate_slots' => ['--hero-bgs']]],
            'candidates not an array'  => [['component_name' => 'hero', 'available_slots' => ['--hero-bg' => []], 'candidate_slots' => '--hero-bgs']],
            'a candidate is not a key' => [['component_name' => 'hero', 'available_slots' => ['--hero-bg' => []], 'candidate_slots' => [['--hero-bgs']]]],
        ];
    }
}
