<?php
/**
 * tests/PreviewErrorActionabilityTest.php — the chat preview's failure payload
 * tells the author whether there is a move left (#625).
 *
 * The chat paints a failed step with one of three classes and writes a matching
 * sentence in the status bar (ppChatGetErrorStepClass / ppChatGetStatusMessage,
 * assets/js/pp-ai-chat.js). `pp-ai-step-impossible` is a claim about CAPABILITY —
 * "there is nothing to change here" — so it must only be painted when the payload
 * names no next action at all. Before #625 it was painted on ANY `invalid_style_slot`
 * without a cross-component hint, which is where a mistyped slot name lands: the
 * author was told the change wasn't possible while the slot they meant sat in
 * `alternatives` in the very same response.
 *
 * The classification lives in JS and is pinned there
 * (tests/js/pp-ai-chat-proposal.test.js). What is pinned HERE is the server half it
 * reads — the shape of the payloads the real write path actually produces, so the two
 * halves cannot drift into agreeing about a payload nobody sends:
 *
 *   pp_preview_action('style_component')                  [lib/actions.php]
 *     ├─ target unresolvable  → component_not_found   ─┐   never invalid_style_slot
 *     ├─ component declares 0 slots → no_style_slots  ─┤   never invalid_style_slot
 *     └─ slot not declared    → invalid_style_slot    ─┘   ALWAYS with a slot map,
 *                                                          because the two branches
 *                                                          above already returned
 *                                        │
 *   _pp_build_friendly_error()                            [lib/ai-chat.php]
 *     └─ alternatives = that slot map's names  → non-empty on every real rejection
 *
 * Pages are authored through the real surfaces (pp_create_page + the
 * update_composition ACTION), and the rejection is produced by the real preview
 * action, paired with the builder exactly as the AJAX handler pairs them — the
 * handler body is a closure registered through add_action, a no-op in this bootstrap.
 */

use PHPUnit\Framework\TestCase;

class PreviewErrorActionabilityTest extends TestCase
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
     * Writes a fixture theme root holding one component with the given style slots
     * and points the template directory at it. Needed for the zero-slot case: every
     * SHIPPED component that declares no style slots (nav, footer) is site chrome the
     * composition validator refuses to place, so the no_style_slots branch cannot be
     * reached through the authoring path with the shipped registry alone. Each
     * component declares `id`, which pp_update_composition injects into every stored
     * component (#147).
     */
    private function useFixtureComponent(string $name, array $style_slots): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/pp-625-' . getmypid() . '-' . mt_rand();
        $dir = $this->fixtureRoot . '/components/' . $name;

        $this->assertTrue(mkdir($dir, 0755, true), "Fixture dir {$dir} must be created.");
        $this->assertNotFalse(file_put_contents($dir . '/' . $name . '.php', "<?php // fixture component\n"));
        $this->assertNotFalse(file_put_contents($dir . '/schema.json', json_encode([
            'description' => 'Fixture component for #625.',
            'props'       => [
                'id' => ['type' => 'string', 'required' => false, 'default' => '', 'description' => 'Anchor id.'],
            ],
            'styling'     => [
                'root_class'  => $name,
                'style_slots' => $style_slots,
            ],
        ], JSON_PRETTY_PRINT)));

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

    // ── The near miss: the payload names the slot the author meant ────────

    public function testANearMissSlotNameStillNamesTheSettingsTheComponentHas(): void
    {
        // `--hero-bgs` for `--hero-bg`. The cross-component scan normalizes it to
        // `--*-bgs`, which no registered component declares, so it produces no hint —
        // the condition that used to be read as "impossible" all by itself.
        $post_id = $this->authorPage('Near miss', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#111111'],
        ];

        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error, 'A slot the component does not declare must be rejected.');
        $this->assertSame('invalid_style_slot', $error->get_error_code());

        $friendly = _pp_build_friendly_error($error, $params);

        $this->assertSame(
            [],
            (array) $friendly['cross_component_hints'],
            'A near miss has no cross-component hint — that is the whole point of #625.'
        );
        $this->assertNotEmpty(
            $friendly['alternatives'],
            'With no hint, `alternatives` is the only thing left naming a next action.'
        );
        $this->assertContains(
            '--hero-bg',
            $friendly['alternatives'],
            'The slot the author meant is in the payload they were told was impossible.'
        );
    }

    public function testTheAlternativesListIsAJsonArraySoTheBrowserCanCountIt(): void
    {
        // The browser decides "names a next action" with Array.isArray(...).length,
        // so a map keyed by slot name would read as naming nothing and repaint the
        // step grey. array_keys() guarantees a list; this pins that it stays one.
        $post_id = $this->authorPage('Array shape', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#111111'],
        ];

        $friendly = _pp_build_friendly_error(pp_preview_action('style_component', $params), $params);

        $this->assertIsArray($friendly['alternatives']);
        // pp_is_list(), not array_is_list(): the latter is PHP 8.1+ and the plugin floor
        // is 8.0 (style.css "Requires PHP"), which is exactly why lib/wp.php ships the shim.
        $this->assertTrue(
            pp_is_list($friendly['alternatives']),
            'A JSON object here would be counted as zero alternatives by the chat.'
        );
        $this->assertSame(
            array_keys(pp_get_style_slots('hero')),
            $friendly['alternatives']
        );
    }

    public function testTheVisibleMessageItselfNamesTheSettingsTheStatusBarPointsAt(): void
    {
        // The status bar says "See the settings it does have above". That is only honest
        // because the non-hint branch names settings in `user_message`, which renders as
        // .pp-ai-preview-error-message with no disclosure to open.
        //
        // Since #661 what it names is a SAMPLE of the declared slot NAMES plus the total,
        // not every slot's description — joining those made the whole message measure
        // 11,309 characters on hero (the descriptions alone are 11,213 of it). The full
        // list still ships, in `alternatives`, behind the <details>.
        $post_id = $this->authorPage('Visible settings', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--hero-bgs' => '#111111'],
        ];

        $friendly = _pp_build_friendly_error(pp_preview_action('style_component', $params), $params);

        $declared = array_keys(pp_get_style_slots('hero'));
        $this->assertGreaterThan(
            PP_FRIENDLY_SLOT_SAMPLE_MAX,
            count($declared),
            'Fixture premise: hero declares more slots than the message samples.'
        );

        // The count is the part that says "this component is configurable" — it is what
        // keeps a sample from reading as the whole of what hero can do.
        $this->assertStringContainsString(
            'It has ' . count($declared) . ' style settings',
            $friendly['user_message']
        );

        // The sample is the FIRST entries of `alternatives`, in the same order, so the
        // disclosure opens onto the names the author has just read.
        foreach (array_slice($declared, 0, PP_FRIENDLY_SLOT_SAMPLE_MAX) as $name) {
            $this->assertStringContainsString($name, $friendly['user_message']);
        }

        // And it names what was tried, which the old message never did.
        $this->assertStringContainsString('--hero-bgs', $friendly['user_message']);
    }

    // ── Why "names nothing" is not where a real rejection lands ───────────

    public function testAComponentWithNoStyleSlotsReportsNoStyleSlotsInstead(): void
    {
        // The reason a real `invalid_style_slot` can never arrive with an empty
        // `alternatives` list: the validator returns no_style_slots BEFORE it looks at
        // any slot name (lib/actions.php), so the empty-map case has its own code —
        // the one that genuinely means "this component supports no styling".
        $this->useFixtureComponent('plainbox', []);
        $this->assertSame([], pp_get_style_slots('plainbox'), 'Fixture premise: plainbox declares no style slots.');

        $post_id = $this->authorPage('No slots', [
            ['component' => 'plainbox', 'props' => []],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => ['--plainbox-bg' => '#111111'],
        ];

        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('no_style_slots', $error->get_error_code());

        $friendly = _pp_build_friendly_error($error, $params);
        $this->assertSame('no_style_slots', $friendly['error_code']);
        $this->assertSame([], $friendly['alternatives']);
    }

    public function testAStaleComponentIdIsReportedAsComponentNotFoundNotAsAnInvalidSlot(): void
    {
        // #625 reads the target-not-found branch of _pp_build_friendly_error()'s
        // invalid_style_slot case as a second instance of the mislabelling. It is not
        // reachable from the chat: the target is resolved before any slot work, and a
        // stale id fails with its own code, which is painted pp-ai-step-failed — never
        // the grey "impossible". That branch answers only unstamped, hand-built errors
        // (pinned in tests/FriendlyErrorSlotContextTest.php).
        $post_id = $this->authorPage('Stale id', [
            ['component' => 'hero', 'props' => ['id' => 'pp-aabb1122', 'title' => 'Hi']],
        ]);

        $params = [
            'post_id'      => $post_id,
            'component_id' => 'pp-nosuchid',
            'style'        => ['--hero-bg' => '#111111'],
        ];

        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame(
            'component_not_found',
            $error->get_error_code(),
            'A stale id fails with its own code, never invalid_style_slot.'
        );

        // Carried through the reporting layer, because that is where the class is
        // decided: `component_not_found` falls to the builder's default branch, which
        // the chat paints pp-ai-step-failed — the generic red, never the grey the
        // issue reads this case as getting.
        $friendly = _pp_build_friendly_error($error, $params);
        $this->assertSame('component_not_found', $friendly['error_code']);
        $this->assertSame([], $friendly['alternatives']);
        $this->assertSame([], (array) $friendly['cross_component_hints']);
        $this->assertStringNotContainsString(
            'couldn\'t find that component',
            $friendly['user_message'],
            'The invalid_style_slot target-not-found branch is not on this path.'
        );
    }
}
