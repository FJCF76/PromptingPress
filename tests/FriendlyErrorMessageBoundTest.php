<?php
/**
 * tests/FriendlyErrorMessageBoundTest.php — the visible half of a preview
 * rejection stays readable however many slots the component declares (#661).
 *
 * A failed style_component step renders the friendly payload like this. On THIS
 * branch — no cross-component hint, so nothing lands in .pp-ai-preview-error-hint —
 * `user_message` is the only piece written out with nothing to collapse:
 *
 *   user_message   ──→ .pp-ai-preview-error-message   ALWAYS OPEN  ← bounded here
 *   raw_error      ──→ ┐ ONE <details>, summary "Show technical details".
 *   alternatives   ──→ ┘ Both are LINES inside its single content div:
 *                        raw_error (PP_REFLECTED_ERROR_MAX) and the
 *                        "Available slots: …" line (COMPLETE, every name).
 *
 * Before #661 the no-hint branch built `user_message` by joining the DESCRIPTION of
 * every declared slot. The descriptions are full sentences with multi-clause caveats,
 * so on hero (49 slots) the string measured 11,309 characters and buried the
 * Apply/Cancel row under many screens at 375px — while `raw_error`, carrying strictly
 * less text, was bounded AND collapsed.
 *
 * What is pinned here is the asymmetry being gone and staying gone:
 *
 *   1. the message is small, and small for the RIGHT reason — it does not
 *      grow with the number of slots the component declares;
 *   2. nothing was lost to make it small: `alternatives` still carries every
 *      declared name, and the sample is that list's own opening;
 *   3. the branches #661 does not own are untouched — the cross-component
 *      sentence and `raw_error` behave exactly as before.
 *
 * Pages are authored through the real surfaces (pp_create_page + the
 * update_composition ACTION) wherever the shipped registry can express the case;
 * fixture theme roots cover the slot counts no shipped component has.
 */

use PHPUnit\Framework\TestCase;

class FriendlyErrorMessageBoundTest extends TestCase
{
    /**
     * Generous headroom over the measured worst real case (hero, 49 slots, 273
     * characters). Not a target — a tripwire. Anything that re-couples the message
     * to the size of the declared set blows straight past it, and anything that
     * merely rewords the prose does not.
     *
     * Compared against mb_strlen, not strlen, because the cap it stands in for is a
     * CHARACTER budget: _pp_clean_reflected_text() truncates with mb_substr(). On the
     * ASCII slot names the registry ships the two agree, so a byte-wise assertion
     * would pass today and start lying the first time a name carries a multi-byte
     * character.
     */
    private const READABLE_CEILING = 600;

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

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** Authors a page through the real write path (schema-validated). */
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
     * Writes a fixture theme root holding one component with $count style slots,
     * each carrying a description of the same ORDER as the longest the shipped
     * registry declares (~1180 here against 1203 characters for
     * `--cta-button-hover-border`). The length is the point: it is what made the old
     * message enormous, so a fixture with short descriptions could not tell a real
     * bound apart from a small registry.
     */
    private function useFixtureComponent(string $name, int $count, int $namePadding = 0): array
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/pp-661-' . getmypid() . '-' . mt_rand();
        $dir = $this->fixtureRoot . '/components/' . $name;

        $this->assertTrue(mkdir($dir, 0755, true), "Fixture dir {$dir} must be created.");
        $this->assertNotFalse(file_put_contents($dir . '/' . $name . '.php', "<?php // fixture component\n"));

        $slots = [];
        for ($i = 0; $i < $count; $i++) {
            $slots['--' . $name . '-slot-' . $i . str_repeat('x', $namePadding)] = [
                'type'        => 'color',
                'default'     => '#000000',
                'description' => 'Fixture description number ' . $i . '. ' . str_repeat('Long enough to matter. ', 50),
            ];
        }

        $this->assertNotFalse(file_put_contents($dir . '/schema.json', json_encode([
            'description' => 'Fixture component for #661.',
            'props'       => [
                'id' => ['type' => 'string', 'required' => false, 'default' => '', 'description' => 'Anchor id.'],
            ],
            'styling'     => [
                'root_class'  => $name,
                'style_slots' => $slots,
            ],
        ], JSON_PRETTY_PRINT)));

        $GLOBALS['_pp_test_template_dir'] = $this->fixtureRoot;

        return array_keys($slots);
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

    /** Rejects $slot on component 0 of a freshly authored page, through the real preview. */
    private function reject(string $component, array $props, array $style): array
    {
        $post_id = $this->authorPage('Bound ' . $component, [
            ['component' => $component, 'props' => $props],
        ]);

        $params = [
            'post_id'         => $post_id,
            'component_index' => 0,
            'style'           => $style,
        ];

        $error = pp_preview_action('style_component', $params);
        $this->assertInstanceOf(WP_Error::class, $error, 'An undeclared slot must be rejected.');
        $this->assertSame('invalid_style_slot', $error->get_error_code());

        return _pp_build_friendly_error($error, $params);
    }

    // ── 1. The message is bounded, and bounded structurally ───────────────

    public function testTheWorstRealRejectionFitsInTheChatColumn(): void
    {
        // hero declares more style slots than any other shipped component (49), so it
        // is the worst case the shipped registry can produce.
        $friendly = $this->reject('hero', ['title' => 'Hi'], ['--hero-bgs' => '#111111']);

        $this->assertLessThan(
            self::READABLE_CEILING,
            mb_strlen($friendly['user_message']),
            'The always-open message must stay readable at 375px.'
        );

        // The specific thing that made it enormous: full slot descriptions. The longest
        // one the registry declares is over a thousand characters by itself.
        $descriptions = array_column(pp_get_style_slots('hero'), 'description');
        // Stated before the loop below: assertStringNotContainsString('', $x) always
        // fails, so an empty description would read as a bound regression rather than
        // as the fixture premise having changed.
        $this->assertNotContains('', $descriptions, 'Fixture premise: every hero slot carries a description.');
        $longest      = max(array_map('mb_strlen', $descriptions));
        $this->assertGreaterThan(
            self::READABLE_CEILING,
            $longest,
            'Fixture premise: one hero description alone exceeds the whole message budget.'
        );
        foreach ($descriptions as $description) {
            $this->assertStringNotContainsString(
                $description,
                $friendly['user_message'],
                'Slot descriptions belong to the schema, not to the visible error.'
            );
        }
    }

    public function testTheMessageDoesNotGrowWithTheNumberOfDeclaredSlots(): void
    {
        // The real invariant. A ceiling alone can be satisfied by a registry that
        // happens to be small today; this fails the moment the message is rebuilt by
        // concatenating something per-slot, whatever that something is.
        //
        // Both fixtures carry descriptions of the same length, so the ONLY difference
        // between the two runs is how many slots are declared: 6 against 120, twenty
        // times as many.
        $this->useFixtureComponent('narrowbox', 6);
        $narrow = $this->reject('narrowbox', [], ['--narrowbox-nope' => '#111111']);
        $narrowLength = strlen($narrow['user_message']);
        $this->deleteTree($this->fixtureRoot);
        $this->fixtureRoot = null;

        $wide = $this->useFixtureComponent('widebox', 120);
        $wideFriendly = $this->reject('widebox', [], ['--widebox-nope' => '#111111']);
        $wideLength   = strlen($wideFriendly['user_message']);

        $this->assertCount(120, $wide, 'Fixture premise: the wide component declares 120 slots.');
        $this->assertCount(120, $wideFriendly['alternatives'], 'All 120 still ship in the payload.');

        // A ratio, not a subtraction: the two messages differ only by the sampled names
        // and the printed total, so twentyfold more slots must not buy even double the
        // text. Scale-relative so the pin survives any rewording of the prose.
        $this->assertLessThan(
            2.0,
            $wideLength / $narrowLength,
            sprintf(
                'Message grew with slot count: %d chars at 6 slots, %d chars at 120.',
                $narrowLength,
                $wideLength
            )
        );
    }

    public function testTheMessageDoesNotGrowWithTheLengthOfDeclaredSlotNames(): void
    {
        // The other half of the same invariant, and the one a count cap alone misses:
        // capping HOW MANY names are printed bounds nothing if each name is unbounded.
        // The declared map travels on the rejection's error data, and
        // pp_rejected_slot_context() (lib/actions.php) checks it for presence, type and
        // emptiness — never for the size of its keys — so a component declaring
        // 4000-character slot names is a shape the code has to survive, not one the
        // shipped registry rules out.
        $slots = $this->useFixtureComponent('hugebox', 12, 4000);
        $this->assertGreaterThan(4000, strlen($slots[0]), 'Fixture premise: the names are enormous.');

        $friendly = $this->reject('hugebox', [], ['--hugebox-nope' => '#111111']);

        // Each printed name is cleaned at PP_REFLECTED_NAME_MAX, so the whole message
        // stays inside the arithmetic ceiling: the sample plus the rejected name plus
        // the component name, each capped, plus fixed prose.
        $ceiling = (PP_FRIENDLY_SLOT_SAMPLE_MAX + 2) * (PP_REFLECTED_NAME_MAX + 2) + 200;
        $this->assertLessThan(
            $ceiling,
            mb_strlen($friendly['user_message']),
            'A long declared name must not reopen the unbounded message.'
        );

        // Bounded by saying less, not by knowing less: the full names still ship intact.
        $this->assertSame($slots, $friendly['alternatives']);
    }

    // ── 2. Bounded by saying less, not by knowing less ────────────────────

    public function testTheCompleteSlotListStillShipsInTheSamePayload(): void
    {
        $friendly = $this->reject('hero', ['title' => 'Hi'], ['--hero-bgs' => '#111111']);

        $declared = array_keys(pp_get_style_slots('hero'));
        $this->assertSame(
            $declared,
            $friendly['alternatives'],
            'The bound applies to what is SAID, never to what is sent — the card prints all of this inside <details>.'
        );

        // And the visible sample is that list's own opening, in its own order, so the
        // disclosure opens onto the names the author has just finished reading.
        $sample = array_slice($declared, 0, PP_FRIENDLY_SLOT_SAMPLE_MAX);
        $this->assertStringContainsString(
            implode(', ', $sample),
            $friendly['user_message'],
            'The sample must be a prefix of alternatives, not a separately-ordered list.'
        );
        $this->assertStringNotContainsString(
            $declared[PP_FRIENDLY_SLOT_SAMPLE_MAX],
            $friendly['user_message'],
            'The sample stops at the cap.'
        );

        // The count is what stops a sample from reading as the whole capability.
        $this->assertStringContainsString('It has ' . count($declared) . ' style settings', $friendly['user_message']);
        $this->assertStringContainsString('full list is in the details', $friendly['user_message']);
    }

    public function testAnOverlongRejectedNameCannotReopenTheUnboundedMessage(): void
    {
        // The rejected name is the one value here that the CALLER supplies outright —
        // it is whatever key the model put in the style map, and nothing upstream
        // constrains its length. Every other bound test stresses DECLARED names, so
        // without this the clean on the rejected name could be deleted and the whole
        // suite would stay green while the always-open message went back to being
        // unbounded, which is the entire defect #661 exists to close.
        $huge = '--hero-' . str_repeat('z', 9000);

        $friendly = $this->reject('hero', ['title' => 'Hi'], [$huge => '#111111']);

        // Asserted against the arithmetic ceiling, not READABLE_CEILING: the cap that
        // does the work here is PP_REFLECTED_NAME_MAX, and this case lands close enough
        // to the readability tripwire that passing it would understate the pin.
        $ceiling = (PP_FRIENDLY_SLOT_SAMPLE_MAX + 2) * (PP_REFLECTED_NAME_MAX + 2) + 200;
        $this->assertLessThan($ceiling, mb_strlen($friendly['user_message']));
        $this->assertStringNotContainsString($huge, $friendly['user_message'], 'The raw caller key must never be echoed whole.');
        // Truncated, not dropped: the author still sees which of their names failed.
        $this->assertStringContainsString('--hero-zzz', $friendly['user_message']);
    }

    public function testNoRejectedNameAtAllKeepsTheUnattributedOpening(): void
    {
        // The zero boundary of the singular/plural split. Reachable on the contextless
        // fallback when every key in the style map is in fact declared: array_diff
        // yields nothing, and the message must not quote a name it does not have.
        $post_id = $this->authorPage('No invalid keys', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Hand-built, no context.'),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => ['--hero-bg' => '#111']]
        );

        $this->assertStringContainsString('a style setting that the hero component doesn\'t support', $friendly['user_message']);
        $this->assertStringNotContainsString('I tried to set "', $friendly['user_message']);
    }

    public function testTheRejectedNameIsNamedWhenThereIsExactlyOne(): void
    {
        // The near miss #625 is about. The old message never said which name was
        // rejected, so the author read a wall of settings without being told which of
        // their own words had failed.
        $friendly = $this->reject('hero', ['title' => 'Hi'], ['--hero-bgs' => '#111111']);

        $this->assertStringContainsString('"--hero-bgs"', $friendly['user_message']);
        // And the slot they meant is among the names they can see without opening
        // anything — the whole point of naming settings above the fold.
        $this->assertStringContainsString('--hero-bg,', $friendly['user_message']);
    }

    public function testSeveralRejectedNamesKeepTheUnattributedOpening(): void
    {
        // Naming one of several would read as a claim about the whole set. raw_error
        // carries the specifics; the visible sentence stays honest about scope.
        $friendly = $this->reject('hero', ['title' => 'Hi'], [
            '--hero-bgs' => '#111111',
            '--hero-qqq' => '#222222',
            '--hero-www' => '#333333',
        ]);

        $this->assertStringContainsString('a style setting that the hero component doesn\'t support', $friendly['user_message']);
        $this->assertStringNotContainsString('"--hero-bgs"', $friendly['user_message']);
        $this->assertLessThan(self::READABLE_CEILING, mb_strlen($friendly['user_message']));
    }

    public function testAComponentWithFewEnoughSlotsIsToldCompletelyAndSentNowhere(): void
    {
        // Below the cap there is no remainder, so promising a fuller list elsewhere
        // would send the author looking for names they have already read.
        $slots = $this->useFixtureComponent('tinybox', 3);
        $this->assertLessThanOrEqual(PP_FRIENDLY_SLOT_SAMPLE_MAX, count($slots), 'Fixture premise: under the cap.');

        $friendly = $this->reject('tinybox', [], ['--tinybox-nope' => '#111111']);

        $this->assertStringContainsString('Its style settings are: ' . implode(', ', $slots) . '.', $friendly['user_message']);
        // The phrase the over-cap branch actually emits. Asserting on the disclosure's
        // own label instead would be vacuous: the server never writes that label.
        $this->assertStringNotContainsString('details below', $friendly['user_message']);
        $this->assertStringNotContainsString('including', $friendly['user_message']);
    }

    public function testExactlyTheSampleSizeIsStillToldCompletely(): void
    {
        // The inclusive edge of the branch split (`<=` vs `>`). A component declaring
        // exactly PP_FRIENDLY_SLOT_SAMPLE_MAX slots has no remainder to point at, so an
        // off-by-one here would promise a fuller list that does not exist — the one
        // wrong answer at this boundary that the author would actually go looking for.
        $slots = $this->useFixtureComponent('edgebox', PP_FRIENDLY_SLOT_SAMPLE_MAX);
        $this->assertCount(PP_FRIENDLY_SLOT_SAMPLE_MAX, $slots, 'Fixture premise: exactly at the cap.');

        $friendly = $this->reject('edgebox', [], ['--edgebox-nope' => '#111111']);

        $this->assertStringContainsString('Its style settings are: ' . implode(', ', $slots) . '.', $friendly['user_message']);
        $this->assertStringNotContainsString('details below', $friendly['user_message']);
        // Every declared name is said out loud here, so nothing is held back.
        $this->assertSame($slots, $friendly['alternatives']);
    }

    public function testOneMoreThanTheSampleSizeSwitchesToTheCountedForm(): void
    {
        // The other side of the same edge: the first count at which a remainder exists.
        $slots = $this->useFixtureComponent('overbox', PP_FRIENDLY_SLOT_SAMPLE_MAX + 1);

        $friendly = $this->reject('overbox', [], ['--overbox-nope' => '#111111']);

        $this->assertStringContainsString(
            'It has ' . (PP_FRIENDLY_SLOT_SAMPLE_MAX + 1) . ' style settings, including ',
            $friendly['user_message']
        );
        $this->assertStringContainsString('details below', $friendly['user_message']);
        // The one name held back is exactly the remainder, and it still ships.
        $this->assertStringNotContainsString($slots[PP_FRIENDLY_SLOT_SAMPLE_MAX], $friendly['user_message']);
        $this->assertContains($slots[PP_FRIENDLY_SLOT_SAMPLE_MAX], $friendly['alternatives']);
    }

    public function testAnUnresolvableTargetIsNotToldItHasNoSettings(): void
    {
        // An out-of-range component_index resolves to nothing, but the target-not-found
        // answer in _pp_build_friendly_error() fires only for a bad component_id, so
        // this rejection reaches the no-hint branch with an empty declared map. Saying
        // "it has no style settings" here would be a confident claim about a component
        // that does not exist — and the singular form would additionally quote a name
        // as having been refused BY it. Neither is supportable, so neither is said.
        //
        // (The guard hole itself is pre-existing and out of scope for #661; it is filed
        // separately. What is pinned here is that the message does not exploit it.)
        $post_id = $this->authorPage('Empty map', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Component "ghost" has no style slot "--ghost-zzz".'),
            ['post_id' => $post_id, 'component_index' => 7, 'style' => ['--ghost-zzz' => '#111']]
        );

        $this->assertSame([], $friendly['alternatives']);
        $this->assertStringNotContainsString('It has no style settings', $friendly['user_message']);
        $this->assertStringNotContainsString('"--ghost-zzz"', $friendly['user_message']);
        $this->assertStringContainsString('couldn\'t tell which component', $friendly['user_message']);
        $this->assertLessThan(self::READABLE_CEILING, mb_strlen($friendly['user_message']));
    }

    public function testAResolvedComponentDeclaringNothingIsToldSo(): void
    {
        // The other half: when the component really did resolve, "it has no style
        // settings" is a true statement about a real component, so it stays.
        $this->useFixtureComponent('barebox', 0);

        $post_id = $this->authorPage('Bare', [
            ['component' => 'barebox', 'props' => []],
        ]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Hand-built, no context.'),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => ['--barebox-nope' => '#111']]
        );

        $this->assertStringContainsString('It has no style settings.', $friendly['user_message']);
        $this->assertStringContainsString('barebox', $friendly['user_message']);
    }

    public function testASecondHandRejectionDoesNotQuoteASingleName(): void
    {
        // Without stamped context the candidate set is re-derived from $params['style'],
        // which is NOT recipe-expanded — so the one key visible here need not be the one
        // the validator refused. Quoting it would be a confident attribution built on
        // second-hand evidence, so the hedged opening is used instead.
        $post_id = $this->authorPage('Second hand', [
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
        ]);

        $friendly = _pp_build_friendly_error(
            new WP_Error('invalid_style_slot', 'Hand-built, no context.'),
            ['post_id' => $post_id, 'component_index' => 0, 'style' => ['--hero-zzz' => '#111']]
        );

        $this->assertStringNotContainsString('I tried to set "', $friendly['user_message']);
        $this->assertStringContainsString('a style setting that the hero component doesn\'t support', $friendly['user_message']);
        // The orientation half is unaffected — it never depended on the attribution.
        $this->assertStringContainsString('It has ' . count(pp_get_style_slots('hero')) . ' style settings', $friendly['user_message']);
    }

    public function testNamesThatCleanAwayNeverBecomeAnEmptyItemInAnExhaustiveList(): void
    {
        // The completely-stated form claims to be the WHOLE set. A slot name made only
        // of format characters cleans to '', so printing it would render
        // "Its style settings are: , --x" — a complete list containing a nameless
        // setting. The counted form claims nothing about completeness, so it is the
        // honest fallback whenever the printed names are not intact.
        $this->fixtureRoot = sys_get_temp_dir() . '/pp-661-zw-' . getmypid() . '-' . mt_rand();
        $dir = $this->fixtureRoot . '/components/zwbox';
        $this->assertTrue(mkdir($dir, 0755, true));
        $this->assertNotFalse(file_put_contents($dir . '/zwbox.php', "<?php // fixture\n"));
        $this->assertNotFalse(file_put_contents($dir . '/schema.json', json_encode([
            'description' => 'Fixture component for #661.',
            'props'       => ['id' => ['type' => 'string', 'required' => false, 'default' => '', 'description' => 'Anchor id.']],
            'styling'     => [
                'root_class'  => 'zwbox',
                'style_slots' => [
                    // A zero-width space is a \p{Cf} character, so the cleaner removes it
                    // and this name has nothing left.
                    "\u{200b}"      => ['type' => 'color', 'default' => '#000', 'description' => 'Nameless.'],
                    '--zwbox-real'  => ['type' => 'color', 'default' => '#000', 'description' => 'Real one.'],
                ],
            ],
        ])));
        $GLOBALS['_pp_test_template_dir'] = $this->fixtureRoot;

        $friendly = $this->reject('zwbox', [], ['--zwbox-nope' => '#111111']);

        $this->assertStringNotContainsString('are: ,', $friendly['user_message']);
        $this->assertStringNotContainsString(', .', $friendly['user_message']);
        // Two declared slots but only one printable name, so no exhaustive claim.
        $this->assertStringNotContainsString('Its style settings are:', $friendly['user_message']);
        $this->assertStringContainsString('It has 2 style settings', $friendly['user_message']);
        $this->assertStringContainsString('--zwbox-real', $friendly['user_message']);
    }

    public function testASingleDeclaredSlotIsDescribedInTheSingular(): void
    {
        $this->useFixtureComponent('onebox', 1);

        $friendly = $this->reject('onebox', [], ['--onebox-nope' => '#111111']);

        $this->assertStringContainsString('Its one style setting is: --onebox-slot-0.', $friendly['user_message']);
        $this->assertStringNotContainsString('settings are', $friendly['user_message']);
    }

    // ── 3. The branches #661 does not own ─────────────────────────────────

    public function testTheCrossComponentSentenceIsUnchanged(): void
    {
        // #661 bounds the NO-HINT branch. When there is a hint the message was already
        // two short sentences naming one other component, so it must come through byte
        // for byte — pinned as a whole string, which is the only way a reworded
        // near-copy fails the test.
        $friendly = $this->reject('hero', ['title' => 'Hi'], ['--section-bg' => '#111111']);

        $this->assertNotSame([], (array) $friendly['cross_component_hints'], 'Fixture premise: this key hints.');
        $this->assertSame(
            'I tried to change a setting on the hero component, but it isn\'t available there. '
                . 'It does exist on the cta component. You could ask me to change it there instead.',
            $friendly['user_message']
        );
    }

    public function testRawErrorStillCarriesTheValidatorsCompleteList(): void
    {
        // raw_error was never the problem — it was already bounded at
        // PP_REFLECTED_ERROR_MAX and already collapsed. The risk in a message change is
        // that the two get conflated and this one is trimmed to match.
        $friendly = $this->reject('hero', ['title' => 'Hi'], ['--hero-bgs' => '#111111']);

        $this->assertStringContainsString('--hero-bgs', $friendly['raw_error']);
        $this->assertLessThanOrEqual(PP_REFLECTED_ERROR_MAX, mb_strlen($friendly['raw_error']));

        // Every declared slot is still named there, uncut: this is the complete list
        // the author is meant to be able to reach.
        foreach (array_keys(pp_get_style_slots('hero')) as $name) {
            $this->assertStringContainsString($name, $friendly['raw_error']);
        }

        // And it is still the LONGER of the two, which is the asymmetry #661 reports:
        // the bounded, collapsed string used to be the small one.
        $this->assertGreaterThan(
            strlen($friendly['user_message']),
            strlen($friendly['raw_error']),
            'The collapsed technical string should now be the verbose one.'
        );
    }
}
