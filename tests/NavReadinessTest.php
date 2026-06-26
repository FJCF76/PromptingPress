<?php
/**
 * tests/NavReadinessTest.php
 *
 * Navigation readiness diagnostics (#88): pp_check_nav_readiness() detects empty
 * or incomplete nav configuration for the locations a composition references, and
 * surfaces (warning-grade) through pp_preflight().
 */

use PHPUnit\Framework\TestCase;

class NavReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100, 'custom_css' => '',
            // Nav state — overridden per test. Default: primary + footer registered, none assigned.
            'registered_nav_menus' => ['primary' => 'Primary Navigation', 'footer' => 'Footer Navigation'],
            'nav_menu_locations'   => [],
            'nav_menu_items'       => [],
        ];
    }

    private function navComposition(string $location = 'primary'): array
    {
        return [['component' => 'nav', 'props' => ['location' => $location]]];
    }

    // ── Scoping: only referenced locations ────────────────────────────────

    public function testNoNavComponentReturnsNoChecks(): void
    {
        $checks = pp_check_nav_readiness([
            ['component' => 'hero', 'props' => ['title' => 'X']],
        ]);
        $this->assertSame([], $checks);
    }

    public function testUnusedRegisteredLocationNotFlagged(): void
    {
        // Composition references only primary; footer is registered but unused → silent.
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 5];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [5 => [['id' => 1]]];

        $checks = pp_check_nav_readiness($this->navComposition('primary'));
        $this->assertCount(1, $checks);
        $this->assertStringContainsString('primary', $checks[0]['message']);
        $this->assertStringNotContainsString('footer', $checks[0]['message']);
    }

    // ── States ────────────────────────────────────────────────────────────

    public function testReferencedLocationWithNoMenuAssigned(): void
    {
        $checks = pp_check_nav_readiness($this->navComposition('primary'));
        $this->assertCount(1, $checks);
        $this->assertFalse($checks[0]['pass']);
        $this->assertStringContainsString('no menu assigned', $checks[0]['message']);
    }

    public function testAssignedMenuIsEmpty(): void
    {
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 7];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [7 => []]; // empty menu
        $checks = pp_check_nav_readiness($this->navComposition('primary'));
        $this->assertCount(1, $checks);
        $this->assertFalse($checks[0]['pass']);
        $this->assertStringContainsString('empty', $checks[0]['message']);
    }

    public function testReadyLocationPasses(): void
    {
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 7];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [7 => [['id' => 1], ['id' => 2]]];
        $checks = pp_check_nav_readiness($this->navComposition('primary'));
        $this->assertCount(1, $checks);
        $this->assertTrue($checks[0]['pass']);
        $this->assertStringContainsString('ready', $checks[0]['message']);
        $this->assertStringContainsString('2 item', $checks[0]['message']);
    }

    public function testReferenceToUnregisteredLocationIsFlagged(): void
    {
        $checks = pp_check_nav_readiness($this->navComposition('sidebar'));
        $this->assertCount(1, $checks);
        $this->assertFalse($checks[0]['pass']);
        $this->assertStringContainsString('unregistered', $checks[0]['message']);
    }

    public function testNavWithoutLocationDefaultsToPrimary(): void
    {
        $checks = pp_check_nav_readiness([['component' => 'nav', 'props' => []]]);
        $this->assertCount(1, $checks);
        $this->assertStringContainsString('primary', $checks[0]['message']);
    }

    public function testBothPrimaryAndFooterDiagnosedIndependently(): void
    {
        // primary ready, footer assigned-but-empty.
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 7, 'footer' => 8];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [7 => [['id' => 1]], 8 => []];

        $composition = array_merge($this->navComposition('primary'), $this->navComposition('footer'));
        $checks = pp_check_nav_readiness($composition);
        $this->assertCount(2, $checks);

        $byPass = [];
        foreach ($checks as $c) {
            $byPass[$c['pass'] ? 'pass' : 'fail'][] = $c['message'];
        }
        $this->assertCount(1, $byPass['pass']);
        $this->assertCount(1, $byPass['fail']);
        $this->assertStringContainsString('footer', $byPass['fail'][0]);
    }

    public function testAllRowsAreWarningSeverity(): void
    {
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 7];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [7 => [['id' => 1]]];
        $checks = pp_check_nav_readiness($this->navComposition('primary'));
        foreach ($checks as $c) {
            $this->assertSame('warning', $c['severity']);
            $this->assertSame('nav_readiness', $c['check']);
        }
    }

    // ── Hardening (malformed input + output escaping) ─────────────────────

    public function testMalformedCompositionItemsDoNotFatal(): void
    {
        // A non-array item and a nav with non-array props must not throw; the
        // bad-props nav and the no-props nav both default to 'primary'.
        $checks = pp_check_nav_readiness([
            'not-an-array',
            ['component' => 'nav', 'props' => 'also-not-an-array'],
            ['component' => 'nav'],
        ]);
        $this->assertNotEmpty($checks);
        foreach ($checks as $c) {
            $this->assertSame('nav_readiness', $c['check']);
        }
    }

    public function testCraftedLocationIsEscapedInMessage(): void
    {
        $checks = pp_check_nav_readiness([
            ['component' => 'nav', 'props' => ['location' => '<script>x</script>']],
        ]);
        $this->assertCount(1, $checks);
        $this->assertStringNotContainsString('<script>', $checks[0]['message']);
    }

    // ── pp_preflight integration ──────────────────────────────────────────

    public function testPreflightSurfacesNavReadinessWarning(): void
    {
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Nav page', 'post_status' => 'publish']);
        // Write via the real path so pp_get_composition() decodes it.
        pp_update_composition($post_id, $this->navComposition('primary'));

        $result   = pp_preflight(['post_id' => $post_id]);
        $navRows  = array_values(array_filter($result['checks'], fn($c) => $c['check'] === 'nav_readiness'));

        $this->assertNotEmpty($navRows, 'pp_preflight must surface nav_readiness rows for a nav-bearing page.');
        $this->assertFalse($navRows[0]['pass']);
        $this->assertSame('warning', $navRows[0]['severity']);
        $this->assertStringContainsString('no menu assigned', $navRows[0]['message']);
    }

    public function testFailingNavWarningDoesNotBlockWhenOtherChecksPass(): void
    {
        // Build a checks array where the only failure is a nav warning, then apply
        // the same exclusion rule pp_preflight() uses for `ok`.
        $checks = [
            ['check' => 'target', 'pass' => true, 'message' => ''],
            ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning', 'message' => ''],
        ];
        $blocking = array_filter($checks, fn($c) => !$c['pass'] && (($c['severity'] ?? 'error') !== 'warning'));
        $this->assertEmpty($blocking, 'A nav warning must not count as a blocking failure.');
    }
}
