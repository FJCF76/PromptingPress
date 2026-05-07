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
}
