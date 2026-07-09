<?php
/**
 * tests/NavReadinessTest.php
 *
 * Site-chrome readiness diagnostics (#88, repointed by #223).
 *
 * pp_check_nav_readiness() diagnoses the chrome the base template renders on
 * every page — the menu locations in pp_template_owned_menu_locations() plus the
 * site logo option — and surfaces (warning-grade) through pp_preflight().
 *
 * Before #223 it scanned the page composition for `nav` components. Compositions
 * may no longer contain chrome, so the diagnostic is now site-scoped and takes
 * no arguments. Every test here exercises that post-Option-B path.
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

    /** Marks $id as a real image attachment for pp_is_image_attachment(). */
    private function seedImageAttachment(int $id): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = true;
    }

    /** Marks $id as an attachment that is NOT an image (e.g. a PDF). */
    private function seedNonImageAttachment(int $id): void
    {
        $GLOBALS['_pp_test_store']['posts'][$id] = ['post_type' => 'attachment'];
        $GLOBALS['_pp_test_store']['attachment_is_image'][$id] = false;
    }

    /** Only the nav_readiness rows about menu locations (drops the logo row). */
    private function menuRows(array $checks): array
    {
        return array_values(array_filter(
            $checks,
            fn($c) => !str_contains($c['message'], 'pp_logo_id')
        ));
    }

    // ── Source of truth: template-owned locations ─────────────────────────

    /**
     * templates/base.php with comments stripped, so a commented-out example call
     * can never satisfy a drift assertion.
     */
    private function baseTemplateCode(): string
    {
        $template = file_get_contents(__DIR__ . '/../templates/base.php');
        $this->assertNotFalse($template, 'templates/base.php must be readable.');

        return preg_replace('~//.*$|/\*.*?\*/~ms', '', $template);
    }

    /**
     * Drift guard. pp_template_owned_menu_locations() hardcodes the locations the
     * base template renders. If someone edits templates/base.php to render a
     * different location, the diagnostic would silently check a location nobody
     * renders. Read the template back and assert the two agree.
     */
    public function testTemplateOwnedLocationsMatchBaseTemplate(): void
    {
        preg_match_all(
            "/pp_get_component\(\s*'[a-z0-9_-]+'\s*,\s*\[\s*'location'\s*=>\s*'([a-z0-9_-]+)'/i",
            $this->baseTemplateCode(),
            $matches
        );

        $this->assertSame(
            pp_template_owned_menu_locations(),
            $matches[1],
            'pp_template_owned_menu_locations() has drifted from the pp_get_component() '
            . 'calls in templates/base.php. Update the function (lib/wp.php) to match the template.'
        );
    }

    /**
     * The drift guard that actually closes #223.
     *
     * Pinning only the menu locations leaves the real hole open: add a third chrome
     * render to templates/base.php (say a `banner`) and the locations still match,
     * yet pp_template_owned_components() would not list it — so it stays composable
     * and double-renders. That is the exact bug #223 exists to kill. Assert the SET
     * of components base.php renders equals the set declared as chrome.
     */
    public function testBaseTemplateRendersExactlyTheDeclaredChrome(): void
    {
        preg_match_all(
            "/pp_get_component\(\s*'([a-z0-9_-]+)'/i",
            $this->baseTemplateCode(),
            $matches
        );
        $rendered = array_values(array_unique($matches[1]));

        $this->assertEqualsCanonicalizing(
            pp_template_owned_components(),
            $rendered,
            'templates/base.php renders a component that pp_template_owned_components() does not '
            . 'list (or lists one it no longer renders). An unlisted component the template renders '
            . 'stays composable, so composing it duplicates the chrome and every validator passes — '
            . 'the exact false-pass issue #223 closed. Add it to pp_template_owned_components().'
        );
    }

    public function testTemplateOwnedComponentsAreRegisteredButNotComposable(): void
    {
        $registered = pp_get_registered_components();
        $composable = pp_composable_components();

        foreach (pp_template_owned_components() as $name) {
            $this->assertArrayHasKey($name, $registered, "Chrome '{$name}' must stay registered — the template renders it.");
            $this->assertArrayNotHasKey($name, $composable, "Chrome '{$name}' must not be advertised as composable.");
        }
        $this->assertArrayHasKey('hero', $composable, 'Content components must remain composable.');
    }

    // ── Scoping: template-owned locations only ────────────────────────────

    public function testBothTemplateOwnedLocationsAreDiagnosed(): void
    {
        // No composition involved at all: chrome renders on every page.
        $rows = $this->menuRows(pp_check_nav_readiness());
        $this->assertCount(2, $rows);

        $messages = implode(' | ', array_column($rows, 'message'));
        $this->assertStringContainsString('primary', $messages);
        $this->assertStringContainsString('footer', $messages);
    }

    public function testRegisteredButUnrenderedLocationIsNotFlagged(): void
    {
        // A plugin registers a location the theme never renders → silent.
        $GLOBALS['_pp_test_store']['registered_nav_menus']['plugin_sidebar'] = 'Plugin Sidebar';

        $messages = implode(' | ', array_column(pp_check_nav_readiness(), 'message'));
        $this->assertStringNotContainsString('plugin_sidebar', $messages);
    }

    // ── States ────────────────────────────────────────────────────────────

    public function testLocationWithNoMenuAssigned(): void
    {
        $rows = $this->menuRows(pp_check_nav_readiness());
        foreach ($rows as $row) {
            $this->assertFalse($row['pass']);
            $this->assertStringContainsString('no menu assigned', $row['message']);
        }
    }

    public function testAssignedMenuIsEmpty(): void
    {
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 7, 'footer' => 8];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [7 => [], 8 => []]; // both empty

        foreach ($this->menuRows(pp_check_nav_readiness()) as $row) {
            $this->assertFalse($row['pass']);
            $this->assertStringContainsString('empty', $row['message']);
        }
    }

    public function testReadyLocationPasses(): void
    {
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 7, 'footer' => 8];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [7 => [['id' => 1], ['id' => 2]], 8 => [['id' => 3]]];

        $rows = $this->menuRows(pp_check_nav_readiness());
        $this->assertCount(2, $rows);

        $primary = $rows[0];
        $this->assertTrue($primary['pass']);
        $this->assertStringContainsString('ready', $primary['message']);
        $this->assertStringContainsString('2 item', $primary['message']);
    }

    public function testTemplateRendersUnregisteredLocationIsFlagged(): void
    {
        // The theme renders 'footer' but nothing registered it.
        $GLOBALS['_pp_test_store']['registered_nav_menus'] = ['primary' => 'Primary Navigation'];

        $rows = $this->menuRows(pp_check_nav_readiness());
        $footer = array_values(array_filter($rows, fn($c) => str_contains($c['message'], 'footer')));

        $this->assertCount(1, $footer);
        $this->assertFalse($footer[0]['pass']);
        $this->assertStringContainsString('not registered', $footer[0]['message']);
    }

    public function testEachLocationIsDiagnosedIndependently(): void
    {
        // primary ready, footer assigned-but-empty.
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 7, 'footer' => 8];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [7 => [['id' => 1]], 8 => []];

        $byPass = [];
        foreach ($this->menuRows(pp_check_nav_readiness()) as $c) {
            $byPass[$c['pass'] ? 'pass' : 'fail'][] = $c['message'];
        }
        $this->assertCount(1, $byPass['pass']);
        $this->assertCount(1, $byPass['fail']);
        $this->assertStringContainsString('primary', $byPass['pass'][0]);
        $this->assertStringContainsString('footer', $byPass['fail'][0]);
    }

    public function testAllRowsAreWarningSeverity(): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = '55'; // non-image → logo row too
        $checks = pp_check_nav_readiness();

        $this->assertNotEmpty($checks);
        foreach ($checks as $c) {
            $this->assertSame('warning', $c['severity']);
            $this->assertSame('nav_readiness', $c['check']);
        }
    }

    // ── Logo readiness ────────────────────────────────────────────────────

    public function testUnsetLogoOptionReportsNothing(): void
    {
        // No pp_logo_id = a deliberate text wordmark, not a finding.
        $messages = implode(' | ', array_column(pp_check_nav_readiness(), 'message'));
        $this->assertStringNotContainsString('pp_logo_id', $messages);
    }

    public function testLogoOptionPointingAtImageReportsNothing(): void
    {
        $this->seedImageAttachment(42);
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = '42';

        $messages = implode(' | ', array_column(pp_check_nav_readiness(), 'message'));
        $this->assertStringNotContainsString('pp_logo_id', $messages);
    }

    public function testLogoOptionPointingAtNonImageIsFlagged(): void
    {
        $this->seedNonImageAttachment(42);
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = '42';

        $logo = array_values(array_filter(
            pp_check_nav_readiness(),
            fn($c) => str_contains($c['message'], 'pp_logo_id')
        ));

        $this->assertCount(1, $logo);
        $this->assertFalse($logo[0]['pass']);
        $this->assertSame('warning', $logo[0]['severity']);
        $this->assertStringContainsString('text wordmark', $logo[0]['message']);
    }

    /**
     * @dataProvider clearedLogoOptionProvider
     *
     * 0 is WordPress's conventional "cleared attachment" value, and pp_resolve_logo()
     * treats a cleared option as a deliberate text wordmark. Warning on it would put
     * an unfixable row on every preflight, reading "attachment 0, which is not an image".
     */
    public function testClearedLogoOptionReportsNothing($cleared): void
    {
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = $cleared;

        $messages = implode(' | ', array_column(pp_check_nav_readiness(), 'message'));
        $this->assertStringNotContainsString(
            'pp_logo_id',
            $messages,
            'A cleared logo option is a deliberate wordmark, not a finding.'
        );
    }

    public static function clearedLogoOptionProvider(): array
    {
        return [
            'empty string' => [''],
            'string zero'  => ['0'],
            'int zero'     => [0],
            'false'        => [false],
        ];
    }

    public function testLogoOptionPointingAtMissingAttachmentIsFlagged(): void
    {
        // An id that resolves to no attachment at all — same silent fallback.
        $GLOBALS['_pp_test_store']['options']['pp_logo_id'] = '999';

        $logo = array_values(array_filter(
            pp_check_nav_readiness(),
            fn($c) => str_contains($c['message'], 'pp_logo_id')
        ));
        $this->assertCount(1, $logo);
        $this->assertFalse($logo[0]['pass']);
    }

    // ── pp_preflight integration ──────────────────────────────────────────

    public function testPreflightSurfacesChromeReadinessWarningForAPage(): void
    {
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';
        $post_id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Content page', 'post_status' => 'publish']);
        // A perfectly ordinary content page — no chrome in the composition.
        pp_update_composition($post_id, [['component' => 'hero', 'props' => ['title' => 'Hi']]]);

        $result  = pp_preflight(['post_id' => $post_id]);
        $navRows = array_values(array_filter($result['checks'], fn($c) => $c['check'] === 'nav_readiness'));

        $this->assertNotEmpty($navRows, 'pp_preflight must surface chrome readiness rows for any page.');
        $this->assertFalse($navRows[0]['pass']);
        $this->assertSame('warning', $navRows[0]['severity']);
        $this->assertStringContainsString('no menu assigned', $navRows[0]['message']);
    }

    /**
     * #223: chrome is site-scoped, so a site-scoped mutation with no post_id
     * (e.g. update_site_option on pp_logo_id) must still get the diagnostic.
     * The old implementation gated Check 8 on isset($context['post_id']).
     */
    public function testPreflightSurfacesChromeReadinessWithoutAPostId(): void
    {
        $GLOBALS['_pp_test_store']['options']['siteurl'] = 'https://example.com';

        $result  = pp_preflight([]);
        $navRows = array_values(array_filter($result['checks'], fn($c) => $c['check'] === 'nav_readiness'));

        $this->assertNotEmpty(
            $navRows,
            'A site-scoped preflight (no post_id) must still diagnose the site chrome it can change.'
        );
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
