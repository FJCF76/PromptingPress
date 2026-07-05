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

    public function testDetectsTableSectionClass(): void
    {
        $GLOBALS['_pp_test_store']['custom_css'] = '.table-section { border: 1px solid red; }';
        $conflicts = pp_check_custom_css_conflicts();

        $this->assertCount(1, $conflicts);
        $this->assertEquals('table-section', $conflicts[0]['component']);
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

    public function testSmellsSkipsNonArrayItems(): void
    {
        // Regression (#119 follow-up): a malformed/corrupted composition
        // decoded from JSON can contain non-array elements. Non-array items
        // must be skipped rather than indexed into (string-offset access
        // would otherwise coerce garbage into $props/$variant/$image_url).
        $composition = [
            'not-an-array',
            ['component' => 'hero', 'props' => ['variant' => 'left', 'image_url' => '/img/x.jpg']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    public function testSmellsSkipsNonArrayProps(): void
    {
        // The item itself is a valid array, but its 'props' value is a
        // scalar. Without the props-level guard (not just the item-level
        // guard above), this must still not coerce garbage into
        // $props/$variant/$image_url via string-offset access.
        $composition = [
            ['component' => 'hero', 'props' => 'not-an-array'],
            ['component' => 'hero', 'props' => ['variant' => 'left', 'image_url' => '/img/x.jpg']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    public function testSmellsHeroLeftNoImageTriggersWarning(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['variant' => 'left', 'title' => 'Test']],
        ];
        $warnings = pp_validate_composition_smells($composition);

        $this->assertCount(1, $warnings);
        $this->assertEquals('hero_left_no_image', $warnings[0]['type']);
        $this->assertEquals(0, $warnings[0]['index']);
    }

    public function testSmellsHeroLeftWithImageDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['variant' => 'left', 'image_url' => '/img/test.jpg']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    public function testSmellsHeroCenteredNoImageDoesNotTrigger(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['variant' => 'centered', 'title' => 'Test']],
        ];
        $this->assertSame([], pp_validate_composition_smells($composition));
    }

    public function testSmellsMixedWarnings(): void
    {
        $composition = [
            ['component' => 'hero', 'props' => ['variant' => 'left']],
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

    public function testSmellsHeroAndCtaAreNeverFlaggedAsEmpty(): void
    {
        // hero/cta/section have no items/headers-rows structure — the empty
        // check must not misfire on components it doesn't understand.
        $composition = [
            ['component' => 'hero', 'props' => []],
            ['component' => 'cta', 'props' => []],
            ['component' => 'section', 'props' => []],
        ];
        $warnings = pp_validate_composition_smells($composition);
        $types = array_column($warnings, 'type');
        $this->assertNotContains('empty_section', $types);
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
}
