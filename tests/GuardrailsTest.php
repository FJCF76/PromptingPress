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

    // ── Composition Smell Validation ─────────────────────────────────────

    public function testSmellsEmptyCompositionReturnsNoWarnings(): void
    {
        $this->assertSame([], pp_validate_composition_smells([]));
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
            ['component' => 'grid', 'props' => []],
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
}
