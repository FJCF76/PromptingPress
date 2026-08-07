<?php
/**
 * tests/GuardrailsTest.php — PHPUnit tests for PromptingPress Guardrails
 *
 * Covers: pp_check_custom_css_conflicts(), pp_validate_composition_styling()
 */

use PHPUnit\Framework\TestCase;

class GuardrailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta'  => [],
            'posts'      => [],
            'options'    => [],
            'next_id'    => 100,
            'custom_css' => '',
        ];
    }

    protected function tearDown(): void
    {
        unset($_GET['post']);
        unset($GLOBALS['_pp_test_store']['current_screen']);
        unset($GLOBALS['_pp_test_store']['page_template_slug']);
        parent::tearDown();
    }

    // ── Conflict Detection ──────────────────────────────────────────────────

    public function testEmptyCustomCssReturnsNoConflicts(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '';
        $this->assertSame([], pp_check_custom_css_conflicts());
    }

    public function testNoCustomCssSetReturnsNoConflicts(): void
    {
        unset($GLOBALS['_pp_test_store']['custom_css']);
        $this->assertSame([], pp_check_custom_css_conflicts());
    }

    public function testDetectsHeroClassConflict(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero { color: red; }';
        $conflicts = pp_check_custom_css_conflicts();

        $this->assertCount(1, $conflicts);
        $this->assertEquals('.hero', $conflicts[0]['selector']);
        $this->assertEquals('hero', $conflicts[0]['component']);
    }

    public function testHeroBannerDoesNotFalsePositive(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero-banner { color: red; }';
        $conflicts = pp_check_custom_css_conflicts();

        $this->assertSame([], $conflicts, '.hero-banner must NOT match .hero');
    }

    public function testDetectsCompoundSelector(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero .hero__title { font-size: 4rem; }';
        $conflicts = pp_check_custom_css_conflicts();

        $this->assertCount(1, $conflicts);
        $this->assertEquals('.hero .hero__title', $conflicts[0]['selector']);
        $this->assertEquals('hero', $conflicts[0]['component']);
    }

    public function testDetectsMultipleConflicts(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero { color: red; } .cta { padding: 0; }';
        $conflicts = pp_check_custom_css_conflicts();

        $this->assertCount(2, $conflicts);
        $components = array_map(fn($c) => $c['component'], $conflicts);
        $this->assertContains('hero', $components);
        $this->assertContains('cta', $components);
    }

    public function testIgnoresCssComments(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '/* .hero { color: red; } */ .unrelated { margin: 0; }';
        $conflicts = pp_check_custom_css_conflicts();

        $this->assertSame([], $conflicts);
    }

    /**
     * A-24 (#576): matching still keys on the ROOT CLASS `.table-section` — that is what
     * the operator's CSS contains — but the report names the REGISTERED COMPONENT,
     * `table`. Before #576 the `--table-section-*` slot family spoke the same name; the
     * canonical vocabulary renamed those to `--table-*`, which would have left this
     * detector as the only surface handing an agent a name that appears in no schema,
     * no slot and no action parameter.
     */
    public function testDetectsTableSectionClassAndReportsTheComponentName(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.table-section { border: 1px solid red; }';
        $conflicts = pp_check_custom_css_conflicts();

        $this->assertCount(1, $conflicts);
        $this->assertEquals('.table-section { border: 1px solid red; }', trim($conflicts[0]['selector']) . ' { border: 1px solid red; }');
        $this->assertEquals('table', $conflicts[0]['component'], 'the report names the component, not the root class');
    }

    /**
     * The mapping is derived from each schema's own `styling.root_class`, so it is
     * identity wherever the two already agree, and it resolves the THREE components
     * whose root class differs from their name — table (.table-section) plus the two
     * chrome components, nav (.site-header) and footer (.site-footer). Reporting the
     * component name for the chrome classes is the same win as for table: `nav` and
     * `footer` are names an agent can act on; `site-header` is not.
     */
    public function testComponentNameForClassIsDerivedFromRootClass(): void
    {
        foreach (['hero', 'section', 'cta', 'grid', 'faq', 'stats', 'logos', 'embed'] as $component) {
            $this->assertSame($component, pp_component_name_for_class($component));
        }
        $this->assertSame('table', pp_component_name_for_class('table-section'));
        $this->assertSame('nav', pp_component_name_for_class('site-header'));
        $this->assertSame('footer', pp_component_name_for_class('site-footer'));

        // A class no component owns reports itself rather than guessing.
        $this->assertSame('not-a-component', pp_component_name_for_class('not-a-component'));
    }

    // ── Composition Styling Validation ──────────────────────────────────────

    public function testAllComponentsWithIdsReturnNoWarnings(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'pp-abc123']],
            ['component' => 'section', 'props' => ['id' => 'pp-def456']],
        ];
        $this->assertSame([], pp_validate_composition_styling($composition));
    }

    public function testDuplicateTypesWithoutIdsAreFlagged(): void
    {
        $composition = [
            ['component' => 'section', 'props' => ['body' => 'A']],
            ['component' => 'hero', 'props' => ['title' => 'Hi', 'id' => 'pp-has-id']],
            ['component' => 'section', 'props' => ['body' => 'B']],
        ];
        $warnings = pp_validate_composition_styling($composition);

        $this->assertCount(1, $warnings);
        $this->assertEquals('section', $warnings[0]['component']);
        $this->assertEquals([0, 2], $warnings[0]['indices']);
    }

    public function testSameTypeWithDifferentIdsNotFlagged(): void
    {
        $composition = [
            ['component' => 'section', 'props' => ['id' => 'pp-aaa', 'body' => 'A']],
            ['component' => 'section', 'props' => ['id' => 'pp-bbb', 'body' => 'B']],
        ];
        $this->assertSame([], pp_validate_composition_styling($composition));
    }

    public function testEmptyCompositionReturnsNoWarnings(): void
    {
        $this->assertSame([], pp_validate_composition_styling([]));
    }

    public function testStylingSkipsNonArrayItems(): void
    {
        // Regression (#119 follow-up): a malformed/corrupted composition
        // decoded from JSON can contain non-array elements (e.g. a scalar
        // from truncated storage). Non-array items must be skipped rather
        // than indexed into, mirroring pp_check_nav_readiness's guard.
        $composition = [
            'not-an-array',
            ['component' => 'section', 'props' => ['body' => 'A']],
        ];
        $this->assertSame([], pp_validate_composition_styling($composition));
    }

    public function testStylingSkipsNonArrayProps(): void
    {
        // The item itself is a valid array, but its 'props' value is a
        // scalar. Without the props-level guard (not just the item-level
        // guard above), indexing ['id'] into a string triggers an "Illegal
        // string offset" warning and can coerce garbage into a truthy id.
        $composition = [
            ['component' => 'section', 'props' => 'not-an-array'],
            ['component' => 'section', 'props' => ['id' => 'pp-bbb', 'body' => 'B']],
        ];
        $this->assertSame([], pp_validate_composition_styling($composition));
    }

    public function testSingleComponentWithoutIdNotFlagged(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Solo']],
        ];
        $this->assertSame([], pp_validate_composition_styling($composition));
    }

    // ── Generated component IDs (#232) ─────────────────────────────────────

    public function testGeneratedIdPatternMatches(): void
    {
        $this->assertTrue(pp_is_generated_component_id('pp-0a38d49e'));
        $this->assertTrue(pp_is_generated_component_id('pp-00000000'));
        $this->assertTrue(pp_is_generated_component_id('pp-deadbeef'));
    }

    public function testGeneratedIdPatternRejectsAuthoredShapes(): void
    {
        $this->assertFalse(pp_is_generated_component_id('inicio'));
        $this->assertFalse(pp_is_generated_component_id('home-hero'));
        $this->assertFalse(pp_is_generated_component_id('pp-abc123'));      // 6 hex — too short
        $this->assertFalse(pp_is_generated_component_id('pp-0a38d49e0'));   // 9 hex — too long
        $this->assertFalse(pp_is_generated_component_id('pp-DEADBEEF'));    // uppercase hex
        $this->assertFalse(pp_is_generated_component_id('pp-has-id00'));    // non-hex chars
        $this->assertFalse(pp_is_generated_component_id('xpp-0a38d49e'));   // wrong prefix
        $this->assertFalse(pp_is_generated_component_id('pp-0a38d49e-x'));  // trailing chars
        $this->assertFalse(pp_is_generated_component_id("pp-0a38d49e\n"));  // trailing newline (\z, not $)
        $this->assertFalse(pp_is_generated_component_id(''));
    }

    public function testGeneratorAndDetectorStayInSync(): void
    {
        // Drift guard: calls the PRODUCTION generator (lib/wp.php), so a format
        // change on either side fails here instead of silently unmatching.
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(pp_is_generated_component_id(pp_generate_component_id()));
        }
    }

    public function testDuplicateTypesWithGeneratedIdsAreFlagged(): void
    {
        // Persisted compositions always carry an id (injection at write time),
        // so generated-pattern ids must count as "no stable id" or the
        // duplicate-type check is unreachable post-persist (#232).
        $composition = [
            ['component' => 'grid', 'props' => ['id' => 'pp-0a38d49e']],
            ['component' => 'hero', 'props' => ['id' => 'home-hero']],
            ['component' => 'grid', 'props' => ['id' => 'pp-03455932']],
        ];
        $warnings = pp_validate_composition_styling($composition);

        $this->assertCount(1, $warnings);
        $this->assertEquals('grid', $warnings[0]['component']);
        $this->assertEquals([0, 2], $warnings[0]['indices']);
    }

    public function testDuplicateTypeWithOneAuthoredIdNotFlagged(): void
    {
        // Only one of the two grids is unidentifiable — no ambiguity.
        $composition = [
            ['component' => 'grid', 'props' => ['id' => 'services']],
            ['component' => 'grid', 'props' => ['id' => 'pp-03455932']],
        ];
        $this->assertSame([], pp_validate_composition_styling($composition));
    }

    public function testAuthoredIdsStillCleanRegression(): void
    {
        // Regression: realistic authored ids (seed homepage / dogfood pages)
        // must keep producing zero warnings after the #232 tightening.
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'home-hero']],
            ['component' => 'grid', 'props' => ['id' => 'indicadores']],
            ['component' => 'grid', 'props' => ['id' => 'especificaciones']],
            ['component' => 'grid', 'props' => ['id' => 'contacto']],
        ];
        $this->assertSame([], pp_validate_composition_styling($composition));
    }

    public function testFindGeneratedIdsFlagsGeneratedAndMissing(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'inicio']],
            ['component' => 'section', 'props' => ['body' => 'A']],
            ['component' => 'faq', 'props' => ['id' => 'pp-03455932']],
        ];
        $findings = pp_find_generated_component_ids($composition);

        $this->assertCount(2, $findings);
        $this->assertSame(['index' => 1, 'component' => 'section', 'id' => ''], $findings[0]);
        $this->assertSame(['index' => 2, 'component' => 'faq', 'id' => 'pp-03455932'], $findings[1]);
    }

    public function testFindGeneratedIdsCleanOnAuthoredComposition(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['id' => 'inicio']],
            ['component' => 'cta', 'props' => ['id' => 'contacto']],
        ];
        $this->assertSame([], pp_find_generated_component_ids($composition));
        $this->assertSame([], pp_find_generated_component_ids([]));
    }

    public function testFindGeneratedIdsGuardsMalformedEntries(): void
    {
        // Mirrors the styling validator's guards: non-array items and
        // non-array props must be skipped/handled, never indexed into.
        // A non-scalar component value must be coerced to '' — check page
        // interpolates it into CLI output (same guard class as #233).
        $composition = [
            'not-an-array',
            ['component' => 'section', 'props' => 'not-an-array'],
            ['component' => 'grid', 'props' => ['id' => ['nested']]],
            ['component' => ['corrupt'], 'props' => []],
        ];
        $findings = pp_find_generated_component_ids($composition);

        $this->assertCount(3, $findings);
        $this->assertEquals('section', $findings[0]['component']);
        $this->assertEquals('grid', $findings[1]['component']);
        $this->assertSame('', $findings[1]['id']);
        $this->assertSame('', $findings[2]['component']);
    }

    public function testStylingValidatorTreatsNonScalarIdAsMissing(): void
    {
        // The finder and the styling validator share _pp_component_durable_id():
        // a corrupt array-valued id must count as "no stable id" in both, so two
        // same-type components with one corrupt id and one generated id are
        // ambiguous.
        $composition = [
            ['component' => 'grid', 'props' => ['id' => ['nested']]],
            ['component' => 'grid', 'props' => ['id' => 'pp-03455932']],
        ];
        $warnings = pp_validate_composition_styling($composition);

        $this->assertCount(1, $warnings);
        $this->assertEquals([0, 1], $warnings[0]['indices']);
    }

    public function testStylingValidatorCoercesNonScalarComponent(): void
    {
        // Two corrupt rows with array components and no durable ids must not
        // fatal (illegal offset type on $type_map[$component]); they coerce to
        // '' and group together as one ambiguous-type warning.
        $composition = [
            ['component' => ['corrupt'], 'props' => []],
            ['component' => ['also-corrupt'], 'props' => ['id' => 'pp-03455932']],
        ];
        $warnings = pp_validate_composition_styling($composition);

        $this->assertCount(1, $warnings);
        $this->assertSame('', $warnings[0]['component']);
        $this->assertEquals([0, 1], $warnings[0]['indices']);
    }

    public function testFalsyIdBoundaryTreatedAsMissing(): void
    {
        // '0' is empty() in PHP, so the write path would overwrite it with a
        // generated id — the validators classify it the same way (missing),
        // staying consistent with pp_update_composition()'s injection check.
        $composition = [
            ['component' => 'section', 'props' => ['id' => '0']],
        ];
        $findings = pp_find_generated_component_ids($composition);
        $this->assertCount(1, $findings);
        $this->assertSame('0', $findings[0]['id']);
    }

    public function testFullRewriteRegeneratesGeneratedIdsButKeepsAuthored(): void
    {
        // The #232 behavior itself: writing the same id-less source JSON twice
        // yields two DIFFERENT generated ids for the id-less component, while
        // an authored id survives both writes. Also pins the write site to the
        // reserved shape (a format drift at lib/wp.php's injection loop would
        // fail here even if the pure generator/detector pair stayed in sync).
        $post_id = 501;
        $source  = [
            ['component' => 'hero', 'props' => ['id' => 'inicio', 'title' => 'Hi']],
            ['component' => 'faq', 'props' => ['items' => [['question' => 'Q', 'answer' => 'A']]]],
        ];

        $this->assertTrue(pp_update_composition($post_id, $source));
        $first = pp_get_composition($post_id);

        $this->assertTrue(pp_update_composition($post_id, $source));
        $second = pp_get_composition($post_id);

        $this->assertSame('inicio', $first[0]['props']['id']);
        $this->assertSame('inicio', $second[0]['props']['id']);
        $this->assertTrue(pp_is_generated_component_id($first[1]['props']['id']));
        $this->assertTrue(pp_is_generated_component_id($second[1]['props']['id']));
        $this->assertNotSame($first[1]['props']['id'], $second[1]['props']['id']);

        $this->assertSame([], pp_find_generated_component_ids([$first[0]]));
        $this->assertCount(1, pp_find_generated_component_ids([$first[1]]));
    }

    // ── Admin Notice ───────────────────────────────────────────────────────

    public function testAdminNoticeReturnsWarningWhenConflictsExist(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero { color: red; }';
        $GLOBALS['_pp_test_store']['current_screen'] = (object) [
            'base' => 'post',
            'post_type' => 'page',
        ];
        // Simulate a composition page being edited.
        $_GET['post'] = 42;
        $GLOBALS['_pp_test_store']['posts'][42] = [
            'post_type' => 'page',
            'post_title' => 'Test',
            'post_status' => 'publish',
        ];

        ob_start();
        pp_admin_notice_css_conflicts();
        $output = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $output);
        $this->assertStringContainsString('.hero', $output);
    }

    public function testAdminNoticeReturnsEmptyWhenNoConflicts(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '';
        $GLOBALS['_pp_test_store']['current_screen'] = (object) [
            'base' => 'post',
            'post_type' => 'page',
        ];
        $_GET['post'] = 42;
        $GLOBALS['_pp_test_store']['posts'][42] = [
            'post_type' => 'page',
            'post_title' => 'Test',
            'post_status' => 'publish',
        ];

        ob_start();
        pp_admin_notice_css_conflicts();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testAdminNoticeBailsOnWrongScreenBase(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero { color: red; }';
        $GLOBALS['_pp_test_store']['current_screen'] = (object) [
            'base' => 'dashboard',
            'post_type' => 'page',
        ];
        $_GET['post'] = 42;

        ob_start();
        pp_admin_notice_css_conflicts();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testAdminNoticeBailsOnWrongPostType(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero { color: red; }';
        $GLOBALS['_pp_test_store']['current_screen'] = (object) [
            'base' => 'post',
            'post_type' => 'post',
        ];
        $_GET['post'] = 42;

        ob_start();
        pp_admin_notice_css_conflicts();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testAdminNoticeBailsWhenNoPostId(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero { color: red; }';
        $GLOBALS['_pp_test_store']['current_screen'] = (object) [
            'base' => 'post',
            'post_type' => 'page',
        ];
        // $_GET['post'] intentionally not set.

        ob_start();
        pp_admin_notice_css_conflicts();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testAdminNoticeBailsOnNonCompositionTemplate(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero { color: red; }';
        $GLOBALS['_pp_test_store']['current_screen'] = (object) [
            'base' => 'post',
            'post_type' => 'page',
        ];
        $_GET['post'] = 42;
        $GLOBALS['_pp_test_store']['page_template_slug'] = 'default';

        ob_start();
        pp_admin_notice_css_conflicts();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testAdminNoticeSafeWithNonNumericPostId(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.hero { color: red; }';
        $GLOBALS['_pp_test_store']['current_screen'] = (object) [
            'base' => 'post',
            'post_type' => 'page',
        ];
        $_GET['post'] = '42<script>alert(1)</script>';

        ob_start();
        pp_admin_notice_css_conflicts();
        $output = ob_get_clean();

        // (int) cast turns this to 42; function should produce safe output or
        // return early if post 42 doesn't have the composition template.
        $this->assertStringNotContainsString('<script>', $output);
    }

    // ── Composition Smell Validation ─────────────────────────────────────

    public function testSmellsEmptyCompositionReturnsNoWarnings(): void
    {
        $this->assertSame([], pp_validate_composition_smells([]));
    }

    // ── Template-owned chrome smell (#223) ───────────────────────────────
    //
    // The read-time half of the fix. `wp pp check page` and `wp pp validate site`
    // never call pp_validate_composition(), so a chrome row that predates the
    // write-time rejection (or arrived via a raw meta write / legacy history
    // restore) would otherwise still be certified clean.

    public function testSmellsFlagComposedNav(): void
    {
        $warnings = pp_validate_composition_smells([
            ['component' => 'nav', 'props' => []],
        ]);

        $this->assertCount(1, $warnings);
        $this->assertSame('template_owned_component', $warnings[0]['type']);
        $this->assertSame(0, $warnings[0]['index']);
        $this->assertStringContainsString('renders it twice', $warnings[0]['message']);
    }

    public function testSmellsFlagComposedFooterAndNameTheRemediation(): void
    {
        $warnings = pp_validate_composition_smells([
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
            ['component' => 'footer', 'props' => []],
        ]);

        $chrome = array_values(array_filter($warnings, fn($w) => $w['type'] === 'template_owned_component'));
        $this->assertCount(1, $chrome);
        $this->assertSame(1, $chrome[0]['index']);
        // The operator needs the escape hatch, and the index that identifies it.
        $this->assertStringContainsString('remove_component', $chrome[0]['message']);
        $this->assertStringContainsString('index 1', $chrome[0]['message']);
    }

    public function testSmellMessageWarnsThatIndicesShiftOnRemoval(): void
    {
        // remove_component array_splices the composition (lib/actions.php), so every
        // later index shifts down. Handing the operator two literal indices to remove
        // in order would make them delete the wrong component on a nav+hero+footer
        // page. Same failure class as #228: instructions that can't be followed
        // literally. The message names the action and the ordering rule instead.
        $warnings = pp_validate_composition_smells([
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
            ['component' => 'footer', 'props' => []],
        ]);

        $chrome = array_values(array_filter($warnings, fn($w) => $w['type'] === 'template_owned_component'));
        $this->assertCount(2, $chrome);
        foreach ($chrome as $w) {
            $this->assertStringContainsString('remove_component', $w['message']);
            $this->assertStringContainsString('highest index first', $w['message']);
            $this->assertStringNotContainsString(
                '--component_index=',
                $w['message'],
                'Do not emit a literal index flag. Indices shift after each removal, and this '
                . 'function has no post_id to build a runnable command from.'
            );
            $this->assertStringNotContainsString(
                'wp pp apply',
                $w['message'],
                'remove_component is an action (wp pp action execute), not an apply.'
            );
        }
    }

    public function testSmellsFlagEveryChromeItemIndependently(): void
    {
        $warnings = pp_validate_composition_smells([
            ['component' => 'nav', 'props' => []],
            ['component' => 'hero', 'props' => ['title' => 'Hi']],
            ['component' => 'footer', 'props' => []],
        ]);

        $chrome = array_values(array_filter($warnings, fn($w) => $w['type'] === 'template_owned_component'));
        $this->assertCount(2, $chrome);
        $this->assertSame([0, 2], array_column($chrome, 'index'));
    }

    public function testSmellsIgnoreChromeFreeComposition(): void
    {
        $warnings = pp_validate_composition_smells([
            ['component' => 'hero', 'props' => ['title' => 'Hi', 'image_url' => '/a.jpg']],
        ]);

        $this->assertSame(
            [],
            array_filter($warnings, fn($w) => $w['type'] === 'template_owned_component'),
            'A normal content page must not be flagged for chrome.'
        );
    }

    public function testSmellsSkipsNonArrayItems(): void
    {
        // Regression (#119 follow-up): a malformed/corrupted composition
        // decoded from JSON can contain non-array elements. Non-array items
        // must be skipped rather than indexed into (string-offset access
        // would otherwise coerce garbage into $props/$hero_layout/$image_url).
        $composition = [
            'not-an-array',
            ['component' => 'hero', 'props' => ['layout' => 'left', 'image_url' => '/img/x.jpg']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    public function testSmellsSkipsNonArrayProps(): void
    {
        // The item itself is a valid array, but its 'props' value is a
        // scalar. Without the props-level guard (not just the item-level
        // guard above), this must still not coerce garbage into
        // $props/$hero_layout/$image_url via string-offset access.
        //
        // The corrupt item DOES raise the #579 empty-band advisory — with no
        // readable props it renders nothing, which is true and is what the advisory
        // says. What must never appear is a layout smell derived from string-offset
        // garbage, so that is what this asserts.
        $composition = [
            ['component' => 'hero', 'props' => 'not-an-array'],
            ['component' => 'hero', 'props' => ['layout' => 'left', 'title' => 'Test', 'image_url' => '/img/x.jpg']],
        ];
        $types = array_column(pp_validate_composition_smells($composition), 'type');
        $this->assertSame(['empty_section'], $types);
    }

    public function testSmellsHeroLeftNoImageTriggersWarning(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['layout' => 'left', 'title' => 'Test']],
        ];
        $warnings = pp_validate_composition_smells($composition);

        $this->assertCount(1, $warnings);
        $this->assertEquals('hero_left_no_image', $warnings[0]['type']);
        $this->assertEquals(0, $warnings[0]['index']);
    }

    public function testSmellsHeroLeftWithImageDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['layout' => 'left', 'image_url' => '/img/test.jpg']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    public function testSmellsHeroCenteredNoImageDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['layout' => 'centered', 'title' => 'Test']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    // ── Hero split without media (#440) ───────────────────────────────────

    public function testSmellsHeroSplitNoMediaTriggersWarning(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['layout' => 'split', 'title' => 'Test']],
        ];
        $warnings = pp_validate_composition_smells($composition);

        $this->assertCount(1, $warnings);
        $this->assertEquals('hero_split_no_media', $warnings[0]['type']);
        $this->assertEquals(0, $warnings[0]['index']);
    }

    public function testSmellsHeroSplitWithImageUrlDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['layout' => 'split', 'image_url' => '/img/test.jpg']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    public function testSmellsHeroSplitWithImageIdDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['layout' => 'split', 'image_id' => 42]],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    public function testSmellsHeroSplitWithProofDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['layout' => 'split', 'proof' => '<p>Trusted by 500+</p>']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    public function testSmellsHeroSplitNonNumericImageIdTriggersWarning(): void
    {
        // A non-numeric image_id is not a resolvable attachment. The renderer
        // ((int) cast) treats it as no media and degrades, so the validator's
        // predicate must agree and warn — not stay silent on empty()-truthiness.
        $composition = [
            ['component' => 'hero', 'props' => ['layout' => 'split', 'image_id' => 'abc']],
        ];
        $warnings = pp_validate_composition_smells($composition);

        $this->assertCount(1, $warnings);
        $this->assertEquals('hero_split_no_media', $warnings[0]['type']);
    }

    public function testSmellsMixedWarnings(): void
    {
        // `title` is present because hero.title is a REQUIRED prop — a title-less
        // hero is a separate (and, since #579, warned) defect, not the subject here.
        $composition = [
            ['component' => 'hero', 'props' => ['layout' => 'left', 'title' => 'Test']],
            ['component' => 'section', 'props' => ['body' => 'Content']],
            ['component' => 'section', 'props' => ['body' => 'More content']],
        ];
        $warnings = pp_validate_composition_smells($composition);

        $this->assertCount(1, $warnings);
        $this->assertEquals('hero_left_no_image', $warnings[0]['type']);
    }

    public function testSmellsDefaultPropsNoWarnings(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Welcome']],
            ['component' => 'section', 'props' => ['body' => 'Content']],
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Feature']]]],
            ['component' => 'cta', 'props' => ['title' => 'Get started']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    // ── Consecutive Text Sections Smell ──────────────────────────────────

    public function testSmellsThreeConsecutiveTextOnlyTriggersWarning(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Welcome']],
            ['component' => 'section', 'props' => ['body' => 'A']],
            ['component' => 'section', 'props' => ['body' => 'B', 'layout' => 'text-only']],
            ['component' => 'section', 'props' => ['body' => 'C']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('consecutive_text_sections', $types);
    }

    public function testSmellsTwoTextOnlySectionsDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'section', 'props' => ['body' => 'A']],
            ['component' => 'section', 'props' => ['body' => 'B']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('consecutive_text_sections', $types);
    }

    public function testSmellsTextSectionCounterResetsOnGrid(): void
    {
        $composition = [
            ['component' => 'section', 'props' => ['body' => 'A']],
            ['component' => 'section', 'props' => ['body' => 'B']],
            ['component' => 'grid', 'props' => ['items' => []]],
            ['component' => 'section', 'props' => ['body' => 'C']],
            ['component' => 'section', 'props' => ['body' => 'D']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('consecutive_text_sections', $types);
    }

    public function testSmellsSectionWithImageDoesNotCountAsTextOnly(): void
    {
        $composition = [
            ['component' => 'section', 'props' => ['body' => 'A']],
            ['component' => 'section', 'props' => ['body' => 'B', 'image_url' => 'img.jpg', 'layout' => 'image-left']],
            ['component' => 'section', 'props' => ['body' => 'C']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('consecutive_text_sections', $types);
    }

    public function testSmellsSectionWithBackgroundImageDoesNotCountAsTextOnly(): void
    {
        $composition = [
            ['component' => 'section', 'props' => ['body' => 'A']],
            ['component' => 'section', 'props' => ['body' => 'B', 'background_image' => 'bg.jpg']],
            ['component' => 'section', 'props' => ['body' => 'C']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('consecutive_text_sections', $types);
    }

    // ── Consecutive Narrow Width / Compact Spacing Smells (issue 51) ──────

    public function testSmellsThreeConsecutiveNarrowWidthTriggersWarning(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Welcome', 'width' => 'narrow']],
            ['component' => 'section', 'props' => ['body' => 'A', 'width' => 'narrow']],
            ['component' => 'grid', 'props' => ['items' => [], 'width' => 'narrow']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('consecutive_narrow_width', $types);
    }

    public function testSmellsTwoConsecutiveNarrowWidthDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Welcome', 'width' => 'narrow']],
            ['component' => 'section', 'props' => ['body' => 'A', 'width' => 'narrow']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('consecutive_narrow_width', $types);
    }

    public function testSmellsNarrowWidthCounterResetsOnDefaultWidth(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Welcome', 'width' => 'narrow']],
            ['component' => 'section', 'props' => ['body' => 'A', 'width' => 'narrow']],
            ['component' => 'grid', 'props' => ['items' => [], 'width' => 'default']],
            ['component' => 'section', 'props' => ['body' => 'B', 'width' => 'narrow']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('consecutive_narrow_width', $types);
    }

    public function testSmellsThreeConsecutiveCompactSpacingTriggersWarning(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Welcome', 'spacing' => 'compact']],
            ['component' => 'section', 'props' => ['body' => 'A', 'spacing' => 'compact']],
            ['component' => 'grid', 'props' => ['items' => [], 'spacing' => 'compact']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('consecutive_compact_spacing', $types);
    }

    public function testSmellsTwoConsecutiveCompactSpacingDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Welcome', 'spacing' => 'compact']],
            ['component' => 'section', 'props' => ['body' => 'A', 'spacing' => 'compact']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('consecutive_compact_spacing', $types);
    }

    public function testSmellsCompactSpacingCounterResetsOnDefaultSpacing(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['title' => 'Welcome', 'spacing' => 'compact']],
            ['component' => 'section', 'props' => ['body' => 'A', 'spacing' => 'compact']],
            ['component' => 'grid', 'props' => ['items' => [], 'spacing' => 'spacious']],
            ['component' => 'section', 'props' => ['body' => 'B', 'spacing' => 'compact']],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('consecutive_compact_spacing', $types);
    }

    // ── Empty Section Smell (issue 87) ─────────────────────────────────────

    public function testSmellsEmptyFaqItemsTriggersWarning(): void
    {
        $composition = [
            ['component' => 'faq', 'props' => ['title' => 'FAQ', 'items' => []]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $this->assertCount(1, $warnings);
        $this->assertEquals('empty_section', $warnings[0]['type']);
        $this->assertEquals(0, $warnings[0]['index']);
    }

    public function testSmellsFaqItemsMissingQuestionTriggersWarning(): void
    {
        $composition = [
            ['component' => 'faq', 'props' => ['title' => 'FAQ', 'items' => [
                ['question' => '', 'answer' => 'An answer with no question'],
            ]]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('empty_section', $types);
    }

    public function testSmellsValidFaqDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'faq', 'props' => ['title' => 'FAQ', 'items' => [
                ['question' => 'Is this real?', 'answer' => 'Yes.'],
            ]]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('empty_section', $types);
    }

    public function testSmellsEmptyGridTriggersWarning(): void
    {
        $composition = [
            ['component' => 'grid', 'props' => ['items' => []]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('empty_section', $types);
    }

    public function testSmellsGridWithItemsDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'grid', 'props' => ['items' => [['title' => 'Feature']]]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('empty_section', $types);
    }

    public function testSmellsEmptyStatsTriggersWarning(): void
    {
        $composition = [
            ['component' => 'stats', 'props' => ['items' => []]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('empty_section', $types);
    }

    public function testSmellsEmptyLogosTriggersWarning(): void
    {
        $composition = [
            ['component' => 'logos', 'props' => ['items' => []]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('empty_section', $types);
    }

    public function testSmellsLogosItemsMissingImageUrlTriggersWarning(): void
    {
        $composition = [
            ['component' => 'logos', 'props' => ['items' => [['label' => 'Acme']]]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('empty_section', $types);
    }

    public function testSmellsEmptyTableTriggersWarning(): void
    {
        $composition = [
            ['component' => 'table', 'props' => ['headers' => [], 'rows' => []]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('empty_section', $types);
    }

    public function testSmellsTableWithHeadersButNoRowsTriggersWarning(): void
    {
        $composition = [
            ['component' => 'table', 'props' => ['headers' => ['Plan', 'Price'], 'rows' => []]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertContains('empty_section', $types);
    }

    public function testSmellsEmptySectionWarningIncludesComponentId(): void
    {
        $composition = [
            ['component' => 'faq', 'props' => ['id' => 'pp-a1b2c3', 'items' => []]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $this->assertEquals('pp-a1b2c3', $warnings[0]['id']);
    }

    public function testSmellsEmptySectionWarningOmitsIdWhenAbsent(): void
    {
        $composition = [
            ['component' => 'faq', 'props' => ['items' => []]],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $this->assertArrayNotHasKey('id', $warnings[0]);
    }

    public function testSmellsEmptyBandComponentsAreFlagged(): void
    {
        // #579, A-27: the empty-band smell used to cover five structured-content
        // components and return false for everything else, so a content-less hero
        // or section was reported clean. Both now warn — a hero paints a bare empty
        // <h1>, and a section with none of its content_requirement props renders an
        // empty container. Neither has a `default` arm to hide behind any more.
        $composition = [
            ['component' => 'hero', 'props' => []],
            ['component' => 'section', 'props' => []],
        ];
        $warnings = array_values(array_filter(
            pp_validate_composition_smells($composition),
            static fn ($w) => $w['type'] === 'empty_section'
        ));
        $this->assertCount(2, $warnings, 'both the hero and the section must warn');
        $this->assertSame([0, 1], array_column($warnings, 'index'));
    }

    public function testSmellsHeroWithAZeroImageIdIsStillEmpty(): void
    {
        // REGRESSION. `image_id` declares 0 as its schema DEFAULT meaning "no image",
        // and it is routinely written as a literal 0 rather than omitted. Counting
        // that 0 as content made the hero arm unreachable for the most common stored
        // shape of a blank hero — the arm would exist and never fire.
        $warnings = pp_validate_composition_smells([
            ['component' => 'hero', 'props' => ['image_id' => 0]],
        ]);
        $this->assertContains('empty_section', array_column($warnings, 'type'));
    }

    public function testSmellsHeroWithARealImageIdIsNotEmpty(): void
    {
        $warnings = pp_validate_composition_smells([
            ['component' => 'hero', 'props' => ['image_id' => 42]],
        ]);
        $this->assertNotContains('empty_section', array_column($warnings, 'type'));
    }

    public function testSmellsStatContentOfLiteralZeroStillCounts(): void
    {
        // The zero exclusion is scoped to the NUMERIC zero. A string "0" is real
        // authored content (a "0 downtime" stat), so it must still count as filled.
        $warnings = pp_validate_composition_smells([
            ['component' => 'hero', 'props' => ['title' => '0']],
        ]);
        $this->assertNotContains('empty_section', array_column($warnings, 'type'));
    }

    public function testSmellsCtaWithoutButtonTextIsNotEmpty(): void
    {
        // The cta's primary <a> renders UNCONDITIONALLY with the 'Get Started'
        // default when button_text is absent, so an absent key is a rendered button,
        // not a dead band. Mirrors components/cta/cta.php, which is the contract
        // _pp_component_is_empty() follows for every component.
        $warnings = pp_validate_composition_smells([['component' => 'cta', 'props' => []]]);
        $this->assertNotContains('empty_section', array_column($warnings, 'type'));
    }

    public function testSmellsCtaWithBlankedButtonAndNoTextIsEmpty(): void
    {
        // Explicitly blanking the label removes the only thing that rendered.
        $warnings = pp_validate_composition_smells([
            ['component' => 'cta', 'props' => ['button_text' => '', 'title' => '', 'body' => '']],
        ]);
        $this->assertContains('empty_section', array_column($warnings, 'type'));
    }

    public function testSmellsTestimonialsWithNoQuotesIsEmpty(): void
    {
        // Mirrors components/testimonials/testimonials.php — `if (!$quote) continue;`
        // skips every item, so the band renders a heading over an empty grid.
        $warnings = pp_validate_composition_smells([
            ['component' => 'testimonials', 'props' => ['items' => [
                ['author' => 'Ada'],
                ['author' => 'Grace', 'quote' => ''],
            ]]],
        ]);
        $this->assertContains('empty_section', array_column($warnings, 'type'));
    }

    public function testSmellsTestimonialsWithOneQuoteIsNotEmpty(): void
    {
        $warnings = pp_validate_composition_smells([
            ['component' => 'testimonials', 'props' => ['items' => [
                ['author' => 'Ada'],
                ['author' => 'Grace', 'quote' => 'It works.'],
            ]]],
        ]);
        $this->assertNotContains('empty_section', array_column($warnings, 'type'));
    }

    public function testSmellsEmbedWithoutContentIsEmpty(): void
    {
        $warnings = pp_validate_composition_smells([
            ['component' => 'embed', 'props' => ['title' => 'Contact', 'content' => '   ']],
        ]);
        $this->assertContains('empty_section', array_column($warnings, 'type'));
    }

    public function testSmellsSectionWithAnyContentRequirementPropIsNotEmpty(): void
    {
        // Driven by the schema's OWN content_requirement.any_of list (#488), so the
        // warn set and the write-time reject set cannot drift apart. panel_items
        // alone is enough — exactly as the write gate accepts it.
        $warnings = pp_validate_composition_smells([
            ['component' => 'section', 'props' => ['panel_items' => ['One']]],
        ]);
        $this->assertNotContains('empty_section', array_column($warnings, 'type'));
    }

    public function testHtmlCommentPresentWhenDebugAndConflicts(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.cta { margin: 0; }';

        // pp_check_custom_css_conflicts is called directly; we verify the
        // conflict detection + HTML comment format works together.
        $conflicts = pp_check_custom_css_conflicts();
        $this->assertNotEmpty($conflicts);

        // Simulate the HTML comment rendering logic from base.php.
        $selectors = array_map(fn($c) => $c['selector'], $conflicts);
        $comment = '<!-- PP WARNING: Custom CSS conflicts detected: ' . implode(', ', $selectors) . ' -->';

        $this->assertStringContainsString('.cta', $comment);
        $this->assertStringContainsString('PP WARNING', $comment);
    }

    // ── Surface Classification ──────────────────────────────────────────────

    public function testCoreFilesClassifiedAsCore(): void
    {
        $core_paths = [
            'lib/wp.php',
            'lib/apply.php',
            'lib/actions.php',
            'lib/operate.php',
            'functions.php',
            'style.css',
            'AI_RULES.md',
            'AI_CONTEXT.md',
            'phpunit.xml',
            'composer.json',
            'package.json',
        ];

        foreach ($core_paths as $path) {
            $result = pp_classify_surface($path);
            $this->assertSame('core', $result['classification'], "Expected '{$path}' to be classified as core");
            $this->assertNotEmpty($result['guidance'], "Expected guidance for '{$path}'");
        }
    }

    public function testExtensionFilesClassifiedAsExtension(): void
    {
        $extension_paths = [
            'components/hero/hero.php',
            'components/section/schema.json',
            'templates/composition.php',
            'assets/css/components.css',
            'assets/css/base.css',
            'assets/js/main.js',
        ];

        foreach ($extension_paths as $path) {
            $result = pp_classify_surface($path);
            $this->assertSame('extension', $result['classification'], "Expected '{$path}' to be classified as extension");
        }
    }

    public function testAbsolutePathNormalized(): void
    {
        $theme_dir = get_template_directory();
        $result = pp_classify_surface($theme_dir . '/lib/wp.php');
        $this->assertSame('core', $result['classification']);
    }

    public function testAbsoluteWindowsPathNormalized(): void
    {
        // Regression for issue 127: on Windows hosting, get_template_directory()
        // and an absolute $path may both use backslashes — the theme-prefix
        // strip must still work, and the resulting relative path must use
        // forward slashes for planned_files overlap matching to work.
        $GLOBALS['_pp_test_template_dir'] = 'C:\\wp\\wp-content\\themes\\promptingpress';
        try {
            $result = pp_classify_surface('C:\\wp\\wp-content\\themes\\promptingpress\\components\\hero\\hero.php');
            $this->assertSame('extension', $result['classification']);
        } finally {
            unset($GLOBALS['_pp_test_template_dir']);
        }
    }

    public function testUnknownPathDefaultsToCore(): void
    {
        $result = pp_classify_surface('random-file.txt');
        $this->assertSame('core', $result['classification']);
    }

    public function testGuidanceRoutesLibToStyleComponent(): void
    {
        $result = pp_classify_surface('lib/wp.php');
        $this->assertStringContainsString('style_component', $result['guidance']);
    }

    public function testGuidanceRoutesFunctionsPhpToApply(): void
    {
        $result = pp_classify_surface('functions.php');
        $this->assertStringContainsString('enqueue_font', $result['guidance']);
    }

    public function testGuidanceRoutesStyleCssToTokenApply(): void
    {
        $result = pp_classify_surface('style.css');
        $this->assertStringContainsString('update_design_token', $result['guidance']);
    }

    // ── Preflight Surface Integration ───────────────────────────────────────

    public function testPreflightBlocksCoreFiles(): void
    {
        $result = pp_preflight([
            'planned_files' => ['lib/wp.php'],
        ]);

        $surface_check = null;
        foreach ($result['checks'] as $check) {
            if ($check['check'] === 'surface') {
                $surface_check = $check;
                break;
            }
        }

        $this->assertNotNull($surface_check, 'Expected a surface check in preflight results');
        $this->assertFalse($surface_check['pass'], 'Expected surface check to fail for core file');
        $this->assertStringContainsString('lib/wp.php', $surface_check['message']);
        $this->assertFalse($result['ok'], 'Expected preflight to fail overall');
    }

    public function testPreflightAllowsExtensionFiles(): void
    {
        $result = pp_preflight([
            'planned_files' => ['assets/css/base.css'],
        ]);

        $surface_check = null;
        foreach ($result['checks'] as $check) {
            if ($check['check'] === 'surface') {
                $surface_check = $check;
                break;
            }
        }

        $this->assertNotNull($surface_check, 'Expected a surface check in preflight results');
        $this->assertTrue($surface_check['pass'], 'Expected surface check to pass for extension file');
    }

    public function testPreflightOmitsSurfaceCheckWithoutPlannedFiles(): void
    {
        $result = pp_preflight([]);

        $has_surface_check = false;
        foreach ($result['checks'] as $check) {
            if ($check['check'] === 'surface') {
                $has_surface_check = true;
                break;
            }
        }

        $this->assertFalse($has_surface_check, 'Expected no surface check when no planned_files');
    }

    // ── Theme Integrity Check ──────────────────────────────────────────────

    private string $integrityDir;

    private function setupIntegrityDir(): void
    {
        $this->integrityDir = sys_get_temp_dir() . '/pp-integrity-test-' . getmypid() . '-' . mt_rand();
        mkdir($this->integrityDir, 0755, true);
        $GLOBALS['_pp_test_template_dir'] = $this->integrityDir;
    }

    private function teardownIntegrityDir(): void
    {
        if (isset($this->integrityDir) && is_dir($this->integrityDir)) {
            $this->recursiveDeleteDir($this->integrityDir);
        }
        unset($GLOBALS['_pp_test_template_dir']);
        unset($GLOBALS['_pp_test_store']['options']['pp_theme_integrity']);
    }

    private function recursiveDeleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDeleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeManifest(array $manifest): void
    {
        file_put_contents(
            $this->integrityDir . '/integrity-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT)
        );
    }

    public function testIntegrityCheckReturnsNullWhenNoManifest(): void
    {
        $this->setupIntegrityDir();

        $result = pp_check_theme_integrity();
        $this->assertNull($result);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckReturnsInvalidManifestOnBadJson(): void
    {
        $this->setupIntegrityDir();
        file_put_contents($this->integrityDir . '/integrity-manifest.json', 'not json{{{');

        $result = pp_check_theme_integrity();

        $this->assertSame('invalid_manifest', $result['status']);
        $this->assertSame(PP_VERSION, $result['version']);
        $this->assertStringContainsString('Invalid JSON', $result['error']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckReturnsInvalidManifestOnMissingVersion(): void
    {
        $this->setupIntegrityDir();
        $this->writeManifest(['file_hashes' => ['a.php' => 'abc123']]);

        $result = pp_check_theme_integrity();

        $this->assertSame('invalid_manifest', $result['status']);
        $this->assertStringContainsString('version', $result['error']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckReturnsInvalidManifestOnMissingFileHashes(): void
    {
        $this->setupIntegrityDir();
        $this->writeManifest(['version' => '0.7.0']);

        $result = pp_check_theme_integrity();

        $this->assertSame('invalid_manifest', $result['status']);
        $this->assertStringContainsString('file_hashes', $result['error']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckReturnsInvalidManifestOnEmptyFileHashes(): void
    {
        $this->setupIntegrityDir();
        $this->writeManifest(['version' => '0.7.0', 'file_hashes' => []]);

        $result = pp_check_theme_integrity();

        $this->assertSame('invalid_manifest', $result['status']);
        $this->assertStringContainsString('file_hashes', $result['error']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckReturnsSafeWhenAllMatch(): void
    {
        $this->setupIntegrityDir();

        file_put_contents($this->integrityDir . '/functions.php', '<?php echo "hi";');
        $hash = md5_file($this->integrityDir . '/functions.php');

        $this->writeManifest([
            'version' => PP_VERSION,
            'file_hashes' => ['functions.php' => $hash],
        ]);

        $result = pp_check_theme_integrity();

        $this->assertSame('safe', $result['status']);
        $this->assertEmpty($result['modified']);
        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['extra']);
        $this->assertNull($result['error']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckDetectsModifiedFile(): void
    {
        $this->setupIntegrityDir();

        file_put_contents($this->integrityDir . '/functions.php', '<?php echo "modified";');

        $this->writeManifest([
            'version' => PP_VERSION,
            'file_hashes' => ['functions.php' => 'original_hash_that_wont_match'],
        ]);

        $result = pp_check_theme_integrity();

        $this->assertSame('unsafe', $result['status']);
        $this->assertContains('functions.php', $result['modified']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckDetectsMissingFile(): void
    {
        $this->setupIntegrityDir();

        // File in manifest but not on disk.
        $this->writeManifest([
            'version' => PP_VERSION,
            'file_hashes' => ['deleted-file.php' => 'abc123'],
        ]);

        $result = pp_check_theme_integrity();

        $this->assertSame('unsafe', $result['status']);
        $this->assertContains('deleted-file.php', $result['missing']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckDetectsExtraFile(): void
    {
        $this->setupIntegrityDir();

        file_put_contents($this->integrityDir . '/tracked.php', '<?php');
        file_put_contents($this->integrityDir . '/extra.php', '<?php // extra');

        $hash = md5_file($this->integrityDir . '/tracked.php');

        $this->writeManifest([
            'version' => PP_VERSION,
            'file_hashes' => ['tracked.php' => $hash],
        ]);

        $result = pp_check_theme_integrity();

        $this->assertSame('unsafe', $result['status']);
        $this->assertContains('extra.php', $result['extra']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckDetectsMultipleDriftTypes(): void
    {
        $this->setupIntegrityDir();

        file_put_contents($this->integrityDir . '/modified.php', '<?php // changed');
        file_put_contents($this->integrityDir . '/extra.php', '<?php // new');

        $this->writeManifest([
            'version' => PP_VERSION,
            'file_hashes' => [
                'modified.php' => 'wrong_hash',
                'deleted.php'  => 'abc123',
            ],
        ]);

        $result = pp_check_theme_integrity();

        $this->assertSame('unsafe', $result['status']);
        $this->assertContains('modified.php', $result['modified']);
        $this->assertContains('deleted.php', $result['missing']);
        $this->assertContains('extra.php', $result['extra']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckStoresResultInOption(): void
    {
        $this->setupIntegrityDir();

        file_put_contents($this->integrityDir . '/ok.php', '<?php');
        $hash = md5_file($this->integrityDir . '/ok.php');

        $this->writeManifest([
            'version' => PP_VERSION,
            'file_hashes' => ['ok.php' => $hash],
        ]);

        pp_check_theme_integrity();

        $stored = get_option('pp_theme_integrity');
        $this->assertIsArray($stored);
        $this->assertSame('safe', $stored['status']);

        $this->teardownIntegrityDir();
    }

    public function testIntegrityCheckErrorFieldSetOnInvalidManifest(): void
    {
        $this->setupIntegrityDir();
        file_put_contents($this->integrityDir . '/integrity-manifest.json', '{"version": 123}');

        $result = pp_check_theme_integrity();

        $this->assertSame('invalid_manifest', $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertNotEmpty($result['error']);

        $this->teardownIntegrityDir();
    }

    // ── Theme Integrity Admin Notice ────────────────────────────────────────

    public function testIntegrityNoticeShowsNothingWhenOptionMissing(): void
    {
        unset($GLOBALS['_pp_test_store']['options']['pp_theme_integrity']);

        ob_start();
        pp_admin_notice_theme_integrity();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testIntegrityNoticeShowsNothingWhenSafe(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_theme_integrity'] = [
            'status'  => 'safe',
            'version' => PP_VERSION,
        ];

        ob_start();
        pp_admin_notice_theme_integrity();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testIntegrityNoticeShowsRedWarningWhenUnsafe(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_theme_integrity'] = [
            'status'   => 'unsafe',
            'version'  => PP_VERSION,
            'modified' => ['lib/wp.php'],
            'missing'  => [],
            'extra'    => ['components/custom.php'],
        ];

        ob_start();
        pp_admin_notice_theme_integrity();
        $output = ob_get_clean();

        $this->assertStringContainsString('notice-error', $output);
        $this->assertStringContainsString('1 modified', $output);
        $this->assertStringContainsString('1 extra', $output);
        $this->assertStringContainsString('wp pp integrity check', $output);
    }

    public function testIntegrityNoticeShowsYellowWarningWhenInvalidManifest(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_theme_integrity'] = [
            'status'  => 'invalid_manifest',
            'version' => PP_VERSION,
            'error'   => 'Invalid JSON',
        ];

        ob_start();
        pp_admin_notice_theme_integrity();
        $output = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $output);
        $this->assertStringContainsString(PP_VERSION, $output);
        $this->assertStringContainsString('integrity-manifest.json', $output);
    }

    public function testIntegrityNoticeDeletesOptionOnVersionMismatch(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_theme_integrity'] = [
            'status'  => 'unsafe',
            'version' => '0.0.0-old',
            'modified' => ['lib/wp.php'],
            'missing'  => [],
            'extra'    => [],
        ];

        ob_start();
        pp_admin_notice_theme_integrity();
        $output = ob_get_clean();

        // Should produce no output and delete the option.
        $this->assertEmpty($output);
        $this->assertFalse(get_option('pp_theme_integrity'));
    }

    public function testIntegrityNoticeShowsNothingAfterVersionMismatchClear(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_theme_integrity'] = [
            'status'  => 'unsafe',
            'version' => '0.0.0-old',
            'modified' => ['lib/wp.php'],
            'missing'  => [],
            'extra'    => [],
        ];

        // First call clears stale option.
        ob_start();
        pp_admin_notice_theme_integrity();
        ob_get_clean();

        // Second call should see no option and produce no output.
        ob_start();
        pp_admin_notice_theme_integrity();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    /**
     * A corrupt row can hold an array in `component`. Casting it warns, and
     * _pp_component_is_empty() declares `string $component`, so it would throw.
     * restore_composition's findings (#233) run these smells over arbitrary
     * history-ring snapshots, so a malformed item must be skipped, never fatal.
     */
    public function testSmellsSkipNonScalarComponentInsteadOfThrowing(): void
    {
        $raised = [];
        set_error_handler(static function (int $no, string $str) use (&$raised): bool {
            $raised[] = $str;
            return true;
        });

        try {
            $warnings = pp_validate_composition_smells([
                ['component' => [], 'props' => []],
                ['component' => ['nested' => 'nav'], 'props' => []],
                ['component' => 'nav', 'props' => []],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised, 'no PHP warning from a non-scalar component');

        // The malformed items are skipped; the real chrome item is still reported.
        $types = array_column($warnings, 'type');
        $this->assertContains('template_owned_component', $types);
        $this->assertSame(2, $warnings[0]['index'], 'the surviving warning points at the nav item');
    }

    // ── Duplicate component ids: shared detector + advisory surface (issue 238) ──

    public function testFindDuplicateComponentIdsGroupsAllIndices(): void
    {
        $dupes = pp_find_duplicate_component_ids([
            ['component' => 'hero', 'props' => ['id' => 'x', 'title' => 'A']],
            ['component' => 'section', 'props' => ['id' => 'y', 'title' => 'B']],
            ['component' => 'section', 'props' => ['id' => 'x', 'title' => 'C']],
            ['component' => 'section', 'props' => ['id' => 'x', 'title' => 'D']],
        ]);

        $this->assertCount(1, $dupes, 'only the collided id is reported');
        $this->assertSame('x', $dupes[0]['id']);
        $this->assertSame([0, 2, 3], $dupes[0]['indices']);
    }

    public function testFindDuplicateComponentIdsIgnoresEmptyNonScalarAndUnique(): void
    {
        $dupes = pp_find_duplicate_component_ids([
            ['component' => 'hero', 'props' => ['id' => '', 'title' => 'A']],
            ['component' => 'section', 'props' => ['title' => 'B']],                 // no id
            ['component' => 'section', 'props' => ['id' => ['x'], 'title' => 'C']],  // non-scalar
            ['component' => 'section', 'props' => ['id' => 'solo', 'title' => 'D']], // unique
        ]);

        $this->assertSame([], $dupes);
    }

    public function testDuplicateIdsSurfaceAsASmellForCheckPageAndValidateSite(): void
    {
        // `check page` / `validate site` read pp_validate_composition_smells(); a
        // duplicate that reached persisted state (raw/legacy write) must show there,
        // mirroring the write-time error (same dual surfacing as template chrome).
        $warnings = pp_validate_composition_smells([
            ['component' => 'hero', 'props' => ['id' => 'dup', 'title' => 'A']],
            ['component' => 'section', 'props' => ['id' => 'dup', 'title' => 'B']],
        ]);

        $types = array_column($warnings, 'type');
        $this->assertContains('duplicate_component_id', $types);
        $dupe = $warnings[array_search('duplicate_component_id', $types, true)];
        $this->assertStringContainsString('dup', $dupe['message']);
    }
}
