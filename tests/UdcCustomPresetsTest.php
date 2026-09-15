<?php
/**
 * tests/UdcCustomPresetsTest.php
 *
 * RULING A3's Sprint-2 half, as executable evidence: author/AI-created presets.
 *
 * Sprint 1 shipped the resolution mechanism and three theme-shipped presets.
 * This is the store, the verbs and the gates around them — and the ruled contract
 * it has to satisfy is mostly about what a preset write must NOT be able to do.
 *
 * THE ORDER IS THE ARGUMENT. The container tests come first, because the two
 * bugs that would have made everything after them worthless live there: a chrome
 * write rebuilt the row from chrome names alone, and the documented "clear chrome"
 * call deleted the row outright. Either one silently destroys every shared bundle
 * on the site, reported as `ok: true`. Presets only mean something if the row they
 * live in survives its neighbour.
 *
 *     save_preset ─┐                        ┌─ _presets          (this verb owns)
 *                  ├─► pp_site_udc row ─────┤
 *  update_site_    ─┘   one lock,           └─ nav / footer      (the other owns)
 *     option            two baselines
 *
 * Each verb reads the row under the lock, patches ONLY its own subtree, carries
 * the other one forward untouched, and advances only its own baseline. Both
 * directions of that are pinned, because a rule only one side keeps is not a rule.
 */

use PHPUnit\Framework\TestCase;

final class UdcCustomPresetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
        ];
        $GLOBALS['wpdb'] = new PP_Lockable_Wpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /** The real verb, as an author or the model reaches it. */
    private function save(string $name, array $udc, string $grain = 'role', array $extra = []): array
    {
        return pp_execute_action('save_preset', array_merge([
            'name' => $name, 'grain' => $grain, 'udc' => $udc,
        ], $extra));
    }

    /** `''` is the documented "remove all chrome styling" call, not an empty object. */
    private function clearChrome(): array
    {
        return pp_execute_action('update_site_option', [
            'key' => PP_SITE_UDC_OPTION, 'value' => '',
        ]);
    }

    private function writeChrome(array $map, ?int $expected = null): array
    {
        $params = ['key' => PP_SITE_UDC_OPTION, 'value' => (string) wp_json_encode($map)];
        if ($expected !== null) {
            $params['expected_version'] = $expected;
        }
        return pp_execute_action('update_site_option', $params);
    }

    private function brandType(): array
    {
        return ['typography' => ['size' => '19px', 'weight' => '600']];
    }

    // ── 1. The container: two tenants, one row ──────────────────────────────

    /**
     * A CHROME WRITE MUST NOT DELETE THE PRESET STORE.
     *
     * pp_udc_normalize_site_map() built the stored container from `_version` plus
     * the chrome names and nothing else, which was complete while chrome was the
     * row's only tenant. With presets in the same row it is a function that
     * destroys every shared bundle on the site as a side effect of restyling the
     * header — and reports success.
     */
    public function testAChromeWriteCarriesThePresetStoreForward(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $chrome = $this->writeChrome(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]]);
        $this->assertTrue($chrome['ok'], $chrome['error'] ?? '');

        $this->assertArrayHasKey(
            'brand-type',
            pp_udc_custom_presets(),
            'restyling the nav must not delete the site presets'
        );
        $this->assertSame('#101828', pp_udc_site_map()['chrome']['nav']['_band']['background']['fill']);
    }

    /** And the reverse: a preset write leaves chrome exactly where it was. */
    public function testAPresetWriteCarriesTheChromeForward(): void
    {
        $this->assertTrue($this->writeChrome(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]])['ok']);
        $before = pp_udc_site_map();

        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $after = pp_udc_site_map();
        $this->assertSame($before['chrome'], $after['chrome'], 'chrome is carried, not re-derived');
        $this->assertSame(
            $before['version'],
            $after['version'],
            'a preset write is not a chrome write and must not advance the chrome baseline'
        );
        $this->assertSame(1, $after['presets_version']);
    }

    /**
     * CLEARING CHROME CLEARS CHROME. It is not a clear of the row.
     *
     * `""` is the documented way to remove all chrome styling and the
     * implementation deleted the option. With presets in the row that call
     * destroys the whole preset store — the most destructive path in this change,
     * reached by an author doing something entirely reasonable.
     */
    public function testClearingChromeKeepsThePresets(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);
        $this->assertTrue($this->writeChrome(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]])['ok']);

        $cleared = $this->clearChrome();
        $this->assertTrue($cleared['ok'], $cleared['error'] ?? '');

        $this->assertSame([], pp_udc_site_map()['chrome'], 'the chrome is gone');
        $this->assertSame('', pp_udc_chrome_authored_css());
        $this->assertArrayHasKey('brand-type', pp_udc_custom_presets(), 'the presets are not');
    }

    /**
     * With nothing left in it the row still goes away, which is the property the
     * clear path exists for: an absent row reads as ABSENT rather than as an
     * empty-but-versioned container.
     */
    public function testClearingChromeWithNoPresetsStillRemovesTheRow(): void
    {
        $this->assertTrue($this->writeChrome(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]])['ok']);

        $this->assertTrue($this->clearChrome()['ok']);

        $this->assertSame(0, pp_udc_site_map()['version']);
        $this->assertFalse(isset($GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION]));
    }

    /** A site with no presets stores the container it stored before this key existed. */
    public function testASiteWithNoPresetsStoresNoPresetKeys(): void
    {
        $this->assertTrue($this->writeChrome(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]])['ok']);

        $stored = json_decode($GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION], true);
        $this->assertArrayNotHasKey(PP_SITE_PRESETS_KEY, $stored);
        $this->assertArrayNotHasKey(PP_SITE_PRESETS_VERSION_KEY, $stored);
    }

    /**
     * The engine-owned keys are REFUSED on a chrome write, with a route.
     *
     * The action reports the stored bytes back as `to`, so a caller doing
     * read-modify-write from that has both keys in hand. Accepting and ignoring
     * `_presets` would let them believe they had just written presets.
     */
    public function testAChromeWriteCarryingThePresetKeysIsRefusedAndRouted(): void
    {
        foreach ([PP_SITE_PRESETS_KEY, PP_SITE_PRESETS_VERSION_KEY] as $key) {
            $result = $this->writeChrome([
                $key  => $key === PP_SITE_PRESETS_KEY ? ['x' => ['grain' => 'role', 'udc' => []]] : 3,
                'nav' => ['_band' => ['background' => ['fill' => '#101828']]],
            ]);
            $this->assertFalse($result['ok'], $key . ' must not be writable through the chrome verb');
            $this->assertStringContainsString('save_preset', $result['error']);
            $this->assertStringContainsString('preserved automatically', $result['error']);
        }
    }

    // ── 2. The verbs ────────────────────────────────────────────────────────

    public function testAPresetIsStoredAndResolvableThroughTheRealVerb(): void
    {
        $result = $this->save('brand-type', $this->brandType(), 'role', [
            'description' => 'The house heading treatment.',
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $resolved = pp_udc_resolve_preset('brand-type');
        $this->assertSame('role', $resolved['grain']);
        $this->assertSame('The house heading treatment.', $resolved['description']);
        $this->assertSame('19px', $resolved['udc']['typography']['size']);
    }

    /** A group-grain definition is storable, which is the other half of ruling A3. */
    public function testAGroupGrainPresetIsStorableAndApplies(): void
    {
        $this->assertTrue($this->save('brand-type', ['size' => '19px'], 'typography')['ok']);

        $this->assertNull(pp_udc_validate_map(
            ['list' => ['typography' => [PP_UDC_PRESET_KEY => 'brand-type']]],
            'testimonials'
        ));
    }

    /** A theme preset's name cannot be taken (invariant I36, no hidden aliasing). */
    public function testASystemPresetNameIsRefused(): void
    {
        $result = $this->save('button', $this->brandType());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('shipped by the theme', $result['error']);
        $this->assertStringContainsString('button-secondary', $result['error'], 'the refusal lists the theme presets');
    }

    /** And a theme preset cannot be deleted either. */
    public function testASystemPresetCannotBeDeleted(): void
    {
        $result = pp_execute_action('delete_preset', ['name' => 'button']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cannot be deleted', $result['error']);
    }

    /**
     * A preset's fragment passes the SAME grammar a band map does, and the refusal
     * names the preset rather than a component and role the author never wrote.
     */
    public function testAnInvalidValueIsRefusedNamingThePresetNotAComponent(): void
    {
        $result = $this->save('bad', ['typography' => ['size' => '19 pixels']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Preset "bad"', $result['error']);
        $this->assertStringContainsString('typography', $result['error']);
        $this->assertStringNotContainsString('Component "', $result['error']);
    }

    public function testAnUnknownGroupInAPresetIsRefused(): void
    {
        $result = $this->save('bad', ['nonsense' => ['size' => '19px']]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('nonsense', $result['error']);
    }

    public function testABadGrainIsRefusedAndListsTheGroups(): void
    {
        $result = $this->save('bad', $this->brandType(), 'roles');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('grain must be', $result['error']);
        $this->assertStringContainsString('typography', $result['error']);
    }

    public function testAnEmptyPresetIsRefused(): void
    {
        $result = $this->save('empty', []);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('declares nothing', $result['error']);
    }

    /** Presets resolve one level only, at definition time as well as at reference time. */
    public function testAPresetThatNamesAPresetIsRefusedAtDefinition(): void
    {
        $result = $this->save('chained', [
            PP_UDC_PRESET_KEY => 'button',
            'typography'      => ['size' => '19px'],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('one level only', $result['error']);
    }

    /**
     * A preset belongs to the SITE, so a band-local token is not in scope.
     *
     * Not an imposed rule: the definition is validated with no band tokens because
     * there is no band, so an `@name` that only resolves inside one is dangling
     * here — and a dangling reference is already a refusal everywhere else.
     */
    public function testAPresetReferencingABandLocalTokenIsRefused(): void
    {
        $result = $this->save('bad', ['typography' => ['size' => '@quote-size-d']]);
        $this->assertFalse($result['ok'], 'a band token cannot be resolved from a site preset');
        // ASSERT THE WORDING, because a bare `ok === false` hid a message aimed at
        // the wrong subject: it told a preset author their reference was "not
        // defined in this band's _tokens", naming a place a preset does not have
        // and cannot create. Same rule the sibling test enforces against naming a
        // component and a role the author never wrote.
        $this->assertSame('invalid_prop_value', $result['error_code']);
        $this->assertStringContainsString('Preset "bad"', $result['error']);
        $this->assertStringNotContainsString("this band's", $result['error']);
        $this->assertStringContainsString('Only site design tokens resolve here', $result['error']);

        $this->assertTrue(
            $this->save('good', ['typography' => ['color' => '@color-accent']])['ok'],
            'a SITE token resolves normally, which is how a preset follows a retheme'
        );
    }

    // ── 3. The bounds, and which one bit ────────────────────────────────────

    public function testTheCountBoundRefusesANewPresetAndStillAllowsAnEdit(): void
    {
        for ($i = 0; $i < PP_SITE_PRESETS_MAX; $i++) {
            $this->assertTrue($this->save('p' . $i, $this->brandType())['ok'], 'seeding preset ' . $i);
        }

        $overflow = $this->save('one-too-many', $this->brandType());
        $this->assertFalse($overflow['ok']);
        $this->assertStringContainsString('which is the limit', $overflow['error']);

        $this->assertTrue(
            $this->save('p0', ['typography' => ['size' => '21px']])['ok'],
            'a site at the cap must still be able to edit its way back under it'
        );
    }

    public function testThePerPresetByteBoundNamesItselfRatherThanTheCount(): void
    {
        $result = $this->save('huge', $this->brandType(), 'role', [
            'description' => str_repeat('x', PP_SITE_PRESET_MAX_BYTES + 1),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('limit for one preset', $result['error']);
        $this->assertStringNotContainsString('which is the limit', $result['error'], 'a byte bound is not a count bound');
    }

    // ── 4. The CAS on the preset store ──────────────────────────────────────

    public function testAStaleBaselineIsRefusedAndNothingIsOverwritten(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);
        $this->assertSame(1, pp_udc_site_map()['presets_version']);
        $this->assertTrue($this->save('brand-type', ['typography' => ['size' => '21px']])['ok']);

        $stale = $this->save('brand-type', ['typography' => ['size' => '99px']], 'role', [
            'expected_version' => 1,
        ]);

        $this->assertFalse($stale['ok']);
        $this->assertSame('site_option_conflict', $stale['error_code']);
        $this->assertSame('21px', pp_udc_resolve_preset('brand-type')['udc']['typography']['size']);
    }

    public function testACurrentBaselineLands(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $result = $this->save('brand-type', ['typography' => ['size' => '21px']], 'role', [
            'expected_version' => 1,
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('21px', pp_udc_resolve_preset('brand-type')['udc']['typography']['size']);
    }

    /**
     * A CHROME write does not make a preset baseline stale, and that is the whole
     * point of two counters in one row: a conflict refusal must mean "what you read
     * changed", not "somebody touched an unrelated part of the same row".
     */
    public function testAChromeWriteDoesNotInvalidateAPresetBaseline(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);
        $this->assertTrue($this->writeChrome(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]])['ok']);

        $result = $this->save('brand-type', ['typography' => ['size' => '21px']], 'role', [
            'expected_version' => 1,
        ]);

        $this->assertTrue($result['ok'], 'a chrome write must not stale a preset baseline: ' . ($result['error'] ?? ''));
    }

    // ── 5. Delete, and the reference gate in reverse ────────────────────────

    public function testAnUnreferencedPresetDeletes(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $result = pp_execute_action('delete_preset', ['name' => 'brand-type']);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertNull(pp_udc_resolve_preset('brand-type'));
    }

    public function testDeletingAPresetThatWasNeverStoredIsRefusedRatherThanReportedDone(): void
    {
        $result = pp_execute_action('delete_preset', ['name' => 'never-existed']);

        $this->assertFalse($result['ok'], 'a no-op reported as a success is a lie about what happened');
        $this->assertStringContainsString('no site preset called', $result['error']);
    }

    /**
     * THE REVERSE DANGLING-REFERENCE GATE. Deleting out from under a band would
     * leave it pointing at nothing, and the emitter would drop the declarations
     * with nothing anywhere saying why the page changed.
     */
    public function testAPresetStillReferencedByABandIsRefusedAndTheRefusalListsWhere(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $id = pp_create_page('Uses the preset', 'draft');
        $written = pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [[
                'component' => 'testimonials',
                'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
                'udc'       => ['list' => [PP_UDC_PRESET_KEY => 'brand-type']],
            ]],
        ]);
        $this->assertTrue($written['ok'], $written['error'] ?? '');

        $result = pp_execute_action('delete_preset', ['name' => 'brand-type']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('still referenced', $result['error']);
        $this->assertStringContainsString((string) $id, $result['error'], 'the refusal names the page');
        $this->assertStringContainsString('list', $result['error'], 'and the role');
        $this->assertIsArray(pp_udc_resolve_preset('brand-type'), 'nothing was deleted');
    }

    /** A group-grain reference counts too — a half-done walk rebuilds the bug. */
    public function testAGroupGrainReferenceAlsoBlocksTheDelete(): void
    {
        $this->assertTrue($this->save('brand-type', ['size' => '19px'], 'typography')['ok']);

        $id = pp_create_page('Uses it at group grain', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [[
                'component' => 'testimonials',
                'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
                'udc'       => ['list' => ['typography' => [PP_UDC_PRESET_KEY => 'brand-type']]],
            ]],
        ])['ok']);

        $result = pp_execute_action('delete_preset', ['name' => 'brand-type']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('group "typography"', $result['error']);
    }

    /** And chrome is scanned, not only pages. */
    public function testAChromeReferenceBlocksTheDelete(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);
        $this->assertTrue($this->writeChrome([
            'nav' => ['link' => [PP_UDC_PRESET_KEY => 'brand-type']],
        ])['ok']);

        $result = pp_execute_action('delete_preset', ['name' => 'brand-type']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('site chrome "nav"', $result['error']);
    }

    /**
     * A hostile page title does not ride the refusal into a terminal.
     *
     * The locator is built from stored site data — a title an author typed — and
     * lands in an operator-facing message. Cleaned and bounded at the sink, the
     * same treatment the emit-drop ledger gives its own stored fragments.
     */
    public function testAHostilePageTitleIsCleanedOutOfTheRefusal(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $id = pp_create_page("Evil\x1b[31m\nTitle", 'draft');
        $this->assertTrue(pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [[
                'component' => 'testimonials',
                'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
                'udc'       => ['list' => [PP_UDC_PRESET_KEY => 'brand-type']],
            ]],
        ])['ok']);

        $result = pp_execute_action('delete_preset', ['name' => 'brand-type']);

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString("\x1b", $result['error'], 'no escape byte reaches the terminal');
        $this->assertStringNotContainsString("\n", $result['error'], 'no newline breaks the message up');
    }

    /**
     * FAIL CLOSED ON A PAGE NOBODY CAN READ. "No references found" and "I could not
     * look" are different answers and only one of them makes a delete safe (I9).
     */
    public function testAnUnreadablePageRefusesTheDeleteRatherThanBeingSkipped(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $id = pp_create_page('Corrupt', 'draft');
        update_post_meta($id, '_pp_composition', '{not json at all');

        $result = pp_execute_action('delete_preset', ['name' => 'brand-type']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('could not be read', $result['error']);
        $this->assertStringContainsString((string) $id, $result['error']);
        $this->assertIsArray(pp_udc_resolve_preset('brand-type'));
    }

    /** The gate runs at validate, so a PREVIEW is told too rather than only a write. */
    public function testThePreviewIsRefusedForAReferencedPresetAsWell(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);
        $this->assertTrue($this->writeChrome([
            'nav' => ['link' => [PP_UDC_PRESET_KEY => 'brand-type']],
        ])['ok']);

        $result = pp_preview_action('delete_preset', ['name' => 'brand-type']);

        $this->assertInstanceOf(
            WP_Error::class,
            $result,
            'a preview that says "fine" and a write that refuses disagree'
        );
    }

    // ── 5b. What the adversarial pass found ─────────────────────────────────

    /**
     * A SHADOWED ROW CAN BE DELETED, which is the only way out of that state.
     *
     * The readiness check tells an operator to save their version under another
     * name and then delete the shadowed row. A refusal keyed on the NAME made that
     * impossible: the row would sit in the store forever, spending the count and
     * byte budget and keeping the warning lit. A stated route back that does not
     * work is worse than no route.
     */
    public function testAShadowedCustomRowCanBeDeletedEvenThoughTheNameIsAThemePreset(): void
    {
        // Seeded raw, because the save verb refuses the name — which is exactly
        // why this state can only arrive from a theme upgrade.
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            PP_SITE_UDC_VERSION_KEY     => 1,
            PP_SITE_PRESETS_VERSION_KEY => 1,
            PP_SITE_PRESETS_KEY         => [
                'button' => ['grain' => 'role', 'udc' => ['typography' => ['size' => '19px']]],
            ],
        ]);
        $this->assertSame(['button'], pp_udc_shadowed_presets());

        $result = pp_execute_action('delete_preset', ['name' => 'button']);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([], pp_udc_shadowed_presets(), 'the shadow is gone');
        $this->assertIsArray(pp_udc_resolve_preset('button'), 'the THEME preset is untouched');
        $this->assertSame([], pp_udc_custom_presets());
    }

    /** A theme preset with no stored row of that name is still undeletable. */
    public function testAnUnshadowedThemePresetIsStillUndeletable(): void
    {
        $result = pp_execute_action('delete_preset', ['name' => 'link']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('shipped by the theme', $result['error']);
    }

    /**
     * THE BASELINE DOES NOT GO BACKWARDS when the last preset is deleted.
     *
     * Both keys used to be written only when the map was non-empty, so create then
     * delete left the counter absent, which reads as 0 — and a caller still holding
     * the baseline it earned before either write would pass the compare and
     * overwrite whatever happened in between. A counter that can rewind is not a
     * counter.
     */
    public function testDeletingTheLastPresetDoesNotRewindTheBaseline(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);
        $this->assertSame(1, pp_udc_site_map()['presets_version']);

        $this->assertTrue(pp_execute_action('delete_preset', ['name' => 'brand-type'])['ok']);

        $this->assertSame([], pp_udc_custom_presets(), 'the store is empty');
        $this->assertSame(2, pp_udc_site_map()['presets_version'], 'but the baseline moved forward');

        $stale = $this->save('other', $this->brandType(), 'role', ['expected_version' => 0]);
        $this->assertFalse($stale['ok'], 'a baseline from before both writes must not pass');
        $this->assertSame('site_option_conflict', $stale['error_code']);
    }

    /**
     * A PRESET ROW NOBODY CAN PARSE STOPS THE WRITE instead of being dropped by it.
     *
     * The writer rebuilds `_presets` from the PARSED map and the parser fails closed
     * per member, so a malformed row would vanish as a side effect of saving some
     * unrelated preset — silent data loss on an operation that never mentioned it.
     */
    public function testAnUnreadablePresetRowRefusesTheNextPresetWrite(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            PP_SITE_UDC_VERSION_KEY     => 1,
            PP_SITE_PRESETS_VERSION_KEY => 1,
            PP_SITE_PRESETS_KEY         => [
                'good'   => ['grain' => 'role', 'udc' => ['typography' => ['size' => '19px']]],
                'broken' => ['grain' => 'role'],
            ],
        ]);
        $this->assertSame(['broken'], pp_udc_site_map()['presets_unreadable']);

        $result = $this->save('unrelated', $this->brandType());

        $this->assertFalse($result['ok'], 'an unrelated save must not silently drop the bad row');
        $this->assertSame('site_option_corrupt', $result['error_code']);
        $this->assertStringContainsString('broken', $result['error']);
        $this->assertStringContainsString('good', json_encode(array_keys(pp_udc_custom_presets())));
    }

    /**
     * A PRESET RESOLVES SITE TOKENS ONLY, at emit as at its definition.
     *
     * The definition gate validates with no band tokens, so a preset naming a
     * band-local token is refused. If the emitter handed such a preset the BAND's
     * tokens, a row written raw could be refused by every gate and paint anyway on
     * whichever band happens to mint that name — the write/render disagreement I29
     * forbids. Same scope on both sides: it resolves everywhere or nowhere.
     */
    public function testAPresetDoesNotResolveABandLocalTokenAtEmitEither(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            PP_SITE_UDC_VERSION_KEY     => 1,
            PP_SITE_PRESETS_VERSION_KEY => 1,
            PP_SITE_PRESETS_KEY         => [
                'sneaky' => ['grain' => 'role', 'udc' => ['typography' => ['size' => '@band-only']]],
            ],
        ]);

        $emitted = $this->emittedFor([
            '_tokens' => ['band-only' => '99px'],
            'list'    => [PP_UDC_PRESET_KEY => 'sneaky'],
        ]);

        $this->assertArrayNotHasKey(
            'font-size',
            $emitted,
            'a band token must not rescue a reference the definition gate refuses'
        );

        // THE POSITIVE CONTROL, so this proves SCOPING rather than "a preset never
        // resolves a reference at all" — which would pass the assertion above while
        // breaking every shipped preset.
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            PP_SITE_UDC_VERSION_KEY     => 1,
            PP_SITE_PRESETS_VERSION_KEY => 1,
            PP_SITE_PRESETS_KEY         => [
                'sitely' => ['grain' => 'role', 'udc' => ['typography' => ['color' => '@color-accent']]],
            ],
        ]);
        $emitted = $this->emittedFor(['list' => [PP_UDC_PRESET_KEY => 'sitely']]);
        $this->assertArrayHasKey('color', $emitted, 'a SITE token still resolves inside a preset');
    }

    /**
     * The properties one compile emitted, flattened across blocks.
     *
     * @return array<string, string>
     */
    private function emittedFor(array $udc): array
    {
        $compiled = pp_udc_compile_band([
            'component' => 'testimonials',
            'id'        => 'pp-a1b2c3d4',
            'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
            'udc'       => $udc,
        ], 'authored');

        $out = [];
        foreach ($compiled['blocks'] as $block) {
            foreach ($block['decls'] as $property => $entry) {
                $out[(string) $property] = (string) $entry['css'];
            }
        }
        return $out;
    }

    // ── 5c. What the testing specialist's mutation campaign found ───────────

    /**
     * THE NAME CHARSET GUARD, on both verbs.
     *
     * Removing it left the whole suite green — and it is not cosmetic: a name the
     * charset refuses is STORED by the write and then dropped by the read, because
     * both ends use the same predicate. The author gets ok:true, the preset does
     * not exist, and the row carries an entry nothing will ever resolve while it
     * spends the shared byte ceiling. Reported-success-with-no-effect, which is the
     * I35 class this change's own docblocks invoke.
     */
    public function testAnIllegalPresetNameIsRefusedByBothVerbs(): void
    {
        $save = $this->save('bad name!', $this->brandType());
        $this->assertFalse($save['ok'], 'a name the reader will drop must not be written');
        $this->assertStringContainsString('1-64 characters', $save['error']);
        $this->assertSame([], pp_udc_custom_presets(), 'and nothing was stored');

        $delete = pp_execute_action('delete_preset', ['name' => 'bad name!']);
        $this->assertFalse($delete['ok']);
        $this->assertStringContainsString('1-64 characters', $delete['error']);
    }

    /** The boundary itself, in both directions, plus the empty name. */
    public function testThePresetNameLengthBoundaryIsExact(): void
    {
        $this->assertTrue($this->save(str_repeat('a', 64), $this->brandType())['ok'], '64 is legal');

        $tooLong = $this->save(str_repeat('a', 65), $this->brandType());
        $this->assertFalse($tooLong['ok'], '65 is not');
        $this->assertStringContainsString('1-64 characters', $tooLong['error']);

        $this->assertFalse($this->save('', $this->brandType())['ok'], 'and neither is empty');
    }

    /**
     * THE ROW CEILING, which is a different fact from the per-preset ceiling.
     *
     * Only the per-preset bound had a test, and that test asserts the two messages
     * differ — so the change shipped a pin for the message that is NOT the
     * data-loss one and none for the message that is. The bound is reachable:
     * 64 presets x 8 KB each is eight times the row ceiling.
     */
    public function testTheRowCeilingRefusesAndLeavesTheStoredRowReadable(): void
    {
        $big = ['typography' => ['size' => '19px'], 'description' => str_repeat('x', 7000)];
        $i   = 0;
        $refusal = null;
        while ($i < PP_SITE_PRESETS_MAX) {
            $result = $this->save('p' . $i, ['typography' => ['size' => '19px']], 'role', [
                'description' => str_repeat('x', 7000),
            ]);
            if (!$result['ok']) {
                $refusal = $result;
                break;
            }
            $i++;
        }

        $this->assertNotNull($refusal, 'the shared row ceiling must be reachable');
        $this->assertSame('invalid_option_value', $refusal['error_code']);
        $this->assertStringContainsString('would be', $refusal['error']);
        $this->assertStringContainsString('bytes', $refusal['error']);
        $this->assertStringNotContainsString('which is the limit', $refusal['error'], 'that is the COUNT bound');

        // The load-bearing half: the row the refusal protected is still readable.
        $site = pp_udc_site_map();
        $this->assertFalse($site['corrupt'], 'the refusal exists so the row never becomes unreadable');
        $this->assertCount($i, $site['presets']);
    }

    /**
     * A CORRUPT ROW REFUSES A BASELINED PRESET WRITE, and accepts an unbaselined
     * one — the recovery route the message promises.
     *
     * The chrome arm has this test; the preset arm did not, and the guard survived
     * being turned off. An unreadable row reports version 0, which is also what a
     * never-written row reports, so a caller holding an ordinary 0 would otherwise
     * pass the compare and overwrite bytes the operator may want back.
     */
    public function testACorruptRowRefusesABaselinedPresetWriteButAllowsADeliberateOne(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = '{not json at all';
        $this->assertTrue(pp_udc_site_map()['corrupt']);

        $baselined = $this->save('brand-type', $this->brandType(), 'role', ['expected_version' => 0]);
        $this->assertFalse($baselined['ok']);
        $this->assertSame('site_option_corrupt', $baselined['error_code']);

        $deliberate = $this->save('brand-type', $this->brandType());
        $this->assertTrue(
            $deliberate['ok'],
            'a write with no baseline is the caller saying "I know what is there": ' . ($deliberate['error'] ?? '')
        );
    }

    /**
     * THE SHADOWED-ROW BYPASS RESTS ON A CLAIM. This is the claim, asserted.
     *
     * Deleting a shadowed row skips the reference gate, on the argument that every
     * reference to that name already resolves to the THEME bundle and still will
     * afterwards, so nothing can dangle. Removing the bypass left the suite green,
     * because the only test deleted a shadowed row nothing referenced.
     */
    public function testDeletingAShadowedRowLeavesItsReferencesResolvingToTheThemePreset(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            PP_SITE_UDC_VERSION_KEY     => 1,
            PP_SITE_PRESETS_VERSION_KEY => 1,
            PP_SITE_PRESETS_KEY         => [
                'button' => ['grain' => 'role', 'udc' => ['typography' => ['size' => '99px']]],
            ],
            'nav' => ['link' => [PP_UDC_PRESET_KEY => 'button']],
        ]);

        $id = pp_create_page('References the shadowed name', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [[
                'component' => 'testimonials',
                'id'        => 'pp-a1b2c3d4',
                'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
                'udc'       => ['list' => [PP_UDC_PRESET_KEY => 'button']],
            ]],
        ])['ok']);

        $before = pp_udc_resolve_preset('button');
        $this->assertTrue(pp_execute_action('delete_preset', ['name' => 'button'])['ok']);
        $after = pp_udc_resolve_preset('button');

        $this->assertSame($before, $after, 'the name resolves to the same theme bundle either side');
        $this->assertSame(
            pp_udc_system_presets()['button'],
            $after,
            'and that bundle is the theme\'s, not the row that was removed'
        );
        $this->assertNull(
            pp_udc_validate_map(['list' => [PP_UDC_PRESET_KEY => 'button']], 'testimonials'),
            'the band that referenced it is still valid — nothing dangled'
        );
    }

    /** A malformed preset baseline reads as 0 and refuses a baselined write by mismatching. */
    public function testAMalformedPresetBaselineReadsAsZeroWithoutLosingThePresets(): void
    {
        foreach (['abc', '3x', ' 4', '-1'] as $junk) {
            $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
                PP_SITE_UDC_VERSION_KEY     => 1,
                PP_SITE_PRESETS_VERSION_KEY => $junk,
                PP_SITE_PRESETS_KEY         => [
                    'brand-type' => ['grain' => 'role', 'udc' => $this->brandType()],
                ],
            ]);

            $site = pp_udc_site_map();
            $this->assertSame(0, $site['presets_version'], "junk baseline {$junk} must read as 0");
            $this->assertFalse($site['corrupt'], 'a junk marker on a readable row is not a corrupt container');
            $this->assertIsArray(pp_udc_resolve_preset('brand-type'), 'the presets still resolve');

            $stale = $this->save('brand-type', $this->brandType(), 'role', ['expected_version' => 3]);
            $this->assertFalse($stale['ok'], 'and a baselined write mismatches rather than passing');
            $this->assertSame('site_option_conflict', $stale['error_code']);
        }
    }

    /**
     * The write envelope reports what changed, on both verbs and in preview.
     *
     * Blanking the `from`/`to` pair on either arm left the suite green. On a repo
     * whose last three gates were write-path and approval-surface truth gates, two
     * new site-scope verbs shipping with no assertion on what their envelope says
     * is the gap most out of step with the program: `from`/`to` is exactly what an
     * approval surface renders.
     */
    public function testBothVerbsReportWhatChangedInPreviewAndInExecute(): void
    {
        $definition = ['grain' => 'role', 'udc' => $this->brandType()];

        $preview = pp_preview_action('save_preset', [
            'name' => 'brand-type', 'grain' => 'role', 'udc' => $this->brandType(),
        ]);
        $this->assertSame('preset.brand-type', $preview['changes'][0]['path']);
        $this->assertNull($preview['changes'][0]['from'], 'a create has no before');
        $this->assertSame($definition, $preview['changes'][0]['to']);

        $created = $this->save('brand-type', $this->brandType());
        $this->assertNull($created['changes'][0]['from']);
        $this->assertSame($definition, $created['changes'][0]['to']);

        $replaced = $this->save('brand-type', ['typography' => ['size' => '21px']]);
        $this->assertSame($definition, $replaced['changes'][0]['from'], 'a replace reports the old one');
        $this->assertSame(
            ['grain' => 'role', 'udc' => ['typography' => ['size' => '21px']]],
            $replaced['changes'][0]['to']
        );

        $deletePreview = pp_preview_action('delete_preset', ['name' => 'brand-type']);
        $this->assertNull($deletePreview['changes'][0]['to'], 'a delete reports no after');

        $deleted = pp_execute_action('delete_preset', ['name' => 'brand-type']);
        $this->assertTrue($deleted['ok']);
        $this->assertNull($deleted['changes'][0]['to']);
    }

    /** The shadow check reaches the surface an operator actually reads. */
    public function testTheShadowCheckReachesPreflight(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            PP_SITE_UDC_VERSION_KEY     => 1,
            PP_SITE_PRESETS_VERSION_KEY => 1,
            PP_SITE_PRESETS_KEY         => [
                'button' => ['grain' => 'role', 'udc' => $this->brandType()],
            ],
        ]);

        $rows = array_values(array_filter(
            pp_preflight([])['checks'],
            static fn(array $c): bool => ($c['check'] ?? '') === 'shadowed_presets'
        ));

        $this->assertCount(1, $rows, 'a check nobody splices into preflight is not a check');
        $this->assertSame('warning', $rows[0]['severity']);
        $this->assertTrue($rows[0]['acknowledgeable']);
    }

    /**
     * Every fragment of a delete locator is cleaned, not just the page title.
     *
     * Role keys, group keys and chrome names all come off a row a raw
     * `wp option update` can write — the same premise the page-title case rests on.
     */
    public function testEveryLocatorFragmentIsCleanedNotJustTheTitle(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $hostileRole  = "link";
        $hostileGroup = "typo
graphy";
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            PP_SITE_UDC_VERSION_KEY     => 1,
            PP_SITE_PRESETS_VERSION_KEY => 1,
            PP_SITE_PRESETS_KEY         => [
                'brand-type' => ['grain' => 'role', 'udc' => $this->brandType()],
            ],
            'nav' => [
                $hostileRole => [PP_UDC_PRESET_KEY => 'brand-type'],
                'link'       => [$hostileGroup => [PP_UDC_PRESET_KEY => 'brand-type']],
            ],
        ]);

        $result = pp_execute_action('delete_preset', ['name' => 'brand-type']);

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString("", $result['error'], 'role key cleaned');
        $this->assertStringNotContainsString("
", $result['error'], 'group key cleaned');
        $this->assertStringContainsString('site chrome "nav"', $result['error'], 'and it still says where');
    }

    /** The reference list is bounded, and says so when it truncates. */
    public function testTheReferenceListIsBoundedAndDeclaresTheOverflow(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $bands = [];
        for ($i = 0; $i < 21; $i++) {
            $bands[] = [
                'component' => 'testimonials',
                'id'        => sprintf('pp-%08x', $i + 1),
                'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
                'udc'       => ['list' => [PP_UDC_PRESET_KEY => 'brand-type']],
            ];
        }
        $id = pp_create_page('Many references', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', [
            'post_id' => $id, 'composition' => $bands,
        ])['ok']);

        $result = pp_execute_action('delete_preset', ['name' => 'brand-type']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('21 places', $result['error']);
        $this->assertStringContainsString('and 1 more', $result['error'], 'the cap declares itself');
    }

    /** Two unreadable pages are named, and read as plural. */
    public function testTwoUnreadablePagesAreBothNamed(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $a = pp_create_page('Corrupt A', 'draft');
        $b = pp_create_page('Corrupt B', 'draft');
        update_post_meta($a, '_pp_composition', '{not json');
        update_post_meta($b, '_pp_composition', '{also not json');

        $result = pp_execute_action('delete_preset', ['name' => 'brand-type']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString((string) $a, $result['error']);
        $this->assertStringContainsString((string) $b, $result['error']);
        $this->assertStringContainsString('those pages', $result['error'], 'plural wording');
    }

    /**
     * The three refusals pp_udc_validate_preset_definition() owns that the action
     * params cannot produce.
     *
     * _pp_preset_definition_from_params() builds the array itself, so a caller
     * cannot send an unknown field, a non-string description, or a non-array
     * preset. The branches are not dead: this function is the documented one-engine
     * seam a stored-row validator would reuse, and it is called here at that level
     * rather than left advertising refusals nothing can reach.
     */
    public function testTheDefinitionValidatorOwnsThreeShapesTheParamsCannotProduce(): void
    {
        $valid = ['grain' => 'role', 'udc' => $this->brandType()];

        $notArray = pp_udc_validate_preset_definition('x', 'button');
        $this->assertInstanceOf(WP_Error::class, $notArray);
        $this->assertStringContainsString('must be an object', $notArray->get_error_message());

        $unknownField = pp_udc_validate_preset_definition('x', $valid + ['colour' => 'red']);
        $this->assertInstanceOf(WP_Error::class, $unknownField);
        $this->assertStringContainsString('colour', $unknownField->get_error_message());

        $badDescription = pp_udc_validate_preset_definition('x', $valid + ['description' => 42]);
        $this->assertInstanceOf(WP_Error::class, $badDescription);
        $this->assertStringContainsString('description must be text', $badDescription->get_error_message());

        $this->assertNull(pp_udc_validate_preset_definition('x', $valid), 'and the valid shape passes');
    }

    // ── 6. The T2 intersect, on a CUSTOM preset, band AND chrome ────────────

    /**
     * A custom preset inherits the ruled semantics unchanged: the groups the role
     * permits apply, the rest are skipped, and the skip is DISCLOSED.
     */
    public function testACustomPresetDisclosesItsSkippedGroupsOnABandWrite(): void
    {
        $this->assertTrue($this->save('wide', [
            'typography' => ['size' => '19px'],
            'shadow'     => ['box' => '0 1px 2px #00000033'],
        ])['ok']);

        $findings = pp_udc_composition_findings([[
            'component' => 'testimonials',
            'id'        => 'pp-a1b2c3d4',
            'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
            'udc'       => ['list' => [PP_UDC_PRESET_KEY => 'wide']],
        ]]);

        $skipped = array_values(array_filter(
            $findings,
            static fn(array $f): bool => $f['type'] === 'udc_preset_groups_skipped'
        ));
        $this->assertCount(1, $skipped);
        $this->assertStringContainsString('wide', $skipped[0]['message']);
        $this->assertStringContainsString('shadow', $skipped[0]['message']);
    }

    /**
     * AND ON CHROME, which is the half #993 made reachable. The same preset on a
     * chrome role must disclose the same way — an asymmetry here is the bug #993
     * filed, arriving on schedule the moment custom presets shipped.
     */
    public function testACustomPresetDisclosesItsSkippedGroupsOnAChromeWriteToo(): void
    {
        $this->assertTrue($this->save('wide', [
            'typography' => ['size' => '19px'],
            'shadow'     => ['box' => '0 1px 2px #00000033'],
        ])['ok']);

        $permits = pp_udc_component_roles('nav')['link']['groups'] ?? [];
        $this->assertNotContains('shadow', $permits, 'this test needs a chrome role that omits shadow');

        $this->assertTrue($this->writeChrome(['nav' => ['link' => [PP_UDC_PRESET_KEY => 'wide']]])['ok']);

        $skipped = array_values(array_filter(
            pp_udc_site_findings(),
            static fn(array $f): bool => $f['type'] === 'udc_preset_groups_skipped'
        ));
        $this->assertCount(1, $skipped, 'a silent partial apply on chrome is the same I35 class as on a band');
        $this->assertStringContainsString('wide', $skipped[0]['message']);
        $this->assertStringContainsString('shadow', $skipped[0]['message']);
        $this->assertNull($skipped[0]['index'], 'a chrome entry has no band offset to claim');
    }

    /** An empty intersection refuses for a custom preset, on a band and on chrome. */
    public function testAnEmptyIntersectionRefusesOnBothSurfaces(): void
    {
        // TWO DIFFERENT PRESETS, because the two surfaces are narrow in different
        // places. On testimonials, `avatar` is the role with no typography. On
        // chrome, every role permits typography — `shadow` is the only group any
        // chrome role omits — so a shadow-only preset is the one that intersects
        // to nothing on nav's `link`. Picking the pair that actually empties is
        // the difference between a pin and a test that skips itself.
        $this->assertTrue($this->save('type-only', $this->brandType())['ok']);
        $this->assertTrue($this->save('shadow-only', ['shadow' => ['box' => '0 1px 2px #00000033']])['ok']);

        $band = pp_udc_validate_map(['avatar' => [PP_UDC_PRESET_KEY => 'type-only']], 'testimonials');
        $this->assertInstanceOf(WP_Error::class, $band);
        $this->assertStringContainsString('type-only', $band->get_error_message());
        $this->assertStringContainsString('avatar', $band->get_error_message());

        $this->assertNotContains(
            'shadow',
            pp_udc_component_roles('nav')['link']['groups'] ?? [],
            'this test needs a chrome role that omits shadow'
        );
        $chrome = $this->writeChrome(['nav' => ['link' => [PP_UDC_PRESET_KEY => 'shadow-only']]]);

        $this->assertFalse($chrome['ok'], 'an empty intersection must refuse on chrome too');
        $this->assertStringContainsString('shadow-only', $chrome['error']);
        $this->assertStringContainsString('link', $chrome['error']);
    }

    // ── 7. Round trip: created, applied, painted ────────────────────────────

    /**
     * THE WHOLE POINT, through the real surfaces end to end: a preset an author
     * creates reaches the page it is applied to.
     */
    public function testAPresetCreatedByTheVerbPaintsOnABandAuthoredByTheRealVerb(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $id = pp_create_page('Round trip', 'draft');
        $this->assertTrue(pp_execute_action('update_composition', [
            'post_id'     => $id,
            'composition' => [[
                'component' => 'testimonials',
                'id'        => 'pp-a1b2c3d4',
                'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
                'udc'       => ['list' => [PP_UDC_PRESET_KEY => 'brand-type']],
            ]],
        ])['ok']);

        $compiled = pp_udc_compile_band(pp_get_composition($id)[0], 'authored');
        $emitted  = [];
        foreach ($compiled['blocks'] as $block) {
            foreach ($block['decls'] as $property => $entry) {
                $emitted[(string) $property] = (string) $entry['css'];
            }
        }

        $this->assertSame('19px', $emitted['font-size'] ?? null);
        $this->assertSame('600', $emitted['font-weight'] ?? null);
    }

    /** Editing the preset moves every band that references it, with no band write. */
    public function testEditingThePresetMovesTheBandsThatReferenceIt(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);
        $this->assertTrue($this->save('brand-type', ['typography' => ['size' => '27px']])['ok']);

        $compiled = pp_udc_compile_band([
            'component' => 'testimonials',
            'id'        => 'pp-a1b2c3d4',
            'props'     => ['items' => [['quote' => 'Great.', 'author' => 'Ada']]],
            'udc'       => ['list' => [PP_UDC_PRESET_KEY => 'brand-type']],
        ], 'authored');

        $sizes = [];
        foreach ($compiled['blocks'] as $block) {
            foreach ($block['decls'] as $property => $entry) {
                if ($property === 'font-size') {
                    $sizes[] = (string) $entry['css'];
                }
            }
        }
        $this->assertSame(['27px'], $sizes);
    }

    /** A band naming a preset that does not exist is still refused, by name. */
    public function testADanglingPresetReferenceIsStillRefusedAndListsWhatExists(): void
    {
        $error = pp_udc_validate_map(['list' => [PP_UDC_PRESET_KEY => 'never-made']], 'testimonials');

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('never-made', $error->get_error_message());
        $this->assertStringContainsString('button', $error->get_error_message(), 'the refusal lists what exists');
    }

    /**
     * The shadow REACHES AN OPERATOR, not just a function that could report it.
     *
     * A site cannot create this state — the save verb refuses a theme name — so it
     * arrives via a theme upgrade, which is exactly the moment nobody is looking.
     * Seeded raw here because that is how it happens: bytes written when the name
     * was free, read back after it stopped being.
     */
    public function testAShadowedPresetIsReportedToTheOperator(): void
    {
        $GLOBALS['_pp_test_store']['options'][PP_SITE_UDC_OPTION] = (string) wp_json_encode([
            PP_SITE_UDC_VERSION_KEY     => 1,
            PP_SITE_PRESETS_VERSION_KEY => 1,
            PP_SITE_PRESETS_KEY         => [
                'button' => ['grain' => 'role', 'udc' => ['typography' => ['size' => '19px']]],
            ],
        ]);

        $rows = pp_check_shadowed_presets();

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['pass']);
        $this->assertSame('warning', $rows[0]['severity']);
        $this->assertStringContainsString('button', $rows[0]['message']);
        $this->assertStringContainsString('theme\'s version wins', $rows[0]['message']);
    }

    /** And a site with no collision reports nothing, rather than a reassuring row. */
    public function testNoShadowMeansNoRow(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $this->assertSame([], pp_check_shadowed_presets());
    }

    // ── 8. Rollback inside a chat batch ─────────────────────────────────────

    /**
     * A BATCH THAT FAILS LATER UNDOES THE PRESET WRITE.
     *
     * The snapshotter captures a site option under one action name — the one verb
     * that used to write one. `save_preset` writes the same row without going
     * through it, so before this arm was widened a batch could save a preset, fail
     * at step 2, roll everything back, and report `rollback_errors: []` over a
     * preset that was still there.
     */
    public function testABatchRollbackUndoesAPresetWrite(): void
    {
        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'save_preset', 'params' => [
                'name' => 'brand-type', 'grain' => 'role',
                'udc'  => ['typography' => ['size' => '19px']],
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertSame([], $batch['rollback_errors'] ?? [], 'nothing should have survived the rollback');
        $this->assertNull(
            pp_udc_resolve_preset('brand-type'),
            'a rolled-back batch that reports no survivors must not have left a preset behind'
        );
    }

    /** And a delete inside a rolled-back batch puts the preset back. */
    public function testABatchRollbackRestoresADeletedPreset(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'delete_preset', 'params' => ['name' => 'brand-type']],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertFalse($batch['ok']);
        $this->assertTrue($batch['rolled_back']);
        $this->assertIsArray(pp_udc_resolve_preset('brand-type'), 'the deleted preset came back');
    }

    /** The rollback restores BOTH subtrees of the row, not one of them. */
    public function testABatchRollbackRestoresChromeAndPresetsTogether(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);
        $this->assertTrue($this->writeChrome(['nav' => ['_band' => ['background' => ['fill' => '#101828']]]])['ok']);
        $before = pp_udc_site_map();

        $batch = pp_ai_execute_batch([
            ['type' => 'action', 'name' => 'save_preset', 'params' => [
                'name' => 'second', 'grain' => 'role', 'udc' => ['typography' => ['size' => '11px']],
            ]],
            ['type' => 'action', 'name' => 'unknown_action', 'params' => []],
        ]);

        $this->assertTrue($batch['rolled_back']);
        $after = pp_udc_site_map();
        $this->assertNull(pp_udc_resolve_preset('second'));
        $this->assertArrayHasKey('brand-type', $after['presets']);
        $this->assertSame($before['chrome'], $after['chrome']);
        $this->assertSame($before['version'], $after['version']);
        $this->assertSame($before['presets_version'], $after['presets_version']);
    }

    /** And a custom preset appears in that list once it exists. */
    public function testACustomPresetJoinsTheListARefusalPrints(): void
    {
        $this->assertTrue($this->save('brand-type', $this->brandType())['ok']);

        $error = pp_udc_validate_map(['list' => [PP_UDC_PRESET_KEY => 'still-not-real']], 'testimonials');

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringContainsString('brand-type', $error->get_error_message());
    }
}
