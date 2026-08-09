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

        // A `//` line comment ends at its NEWLINE: match [^\n]*, not `.*$` under /s.
        // With the /s flag, `.` also matches newlines, so `//.*$` was greedily eating
        // from the FIRST line comment to the end of the file — which silently deleted
        // every pp_get_component() call below it. The guard only passed because the
        // first `//` happened to sit after the last call it needed to see; adding a
        // comment higher up in base.php made this guard start reporting phantom drift.
        return preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $template);
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

    // ══════════════════════════════════════════════════════════════════════
    //  issue 582 — the CONDITIONALLY rendered location (footer_secondary)
    //
    //  The footer renders a THIRD menu location, footer_secondary, which paints
    //  only when a menu is assigned to it. It is deliberately NOT in
    //  pp_template_owned_menu_locations(): adding it there would emit a row on
    //  every site that never assigned that menu, which is exactly the noise
    //  pp_check_nav_readiness()'s docstring rules out by name.
    //
    //  So the rule is INVERTED for this list. Only one state is worth a word:
    //
    //    no menu assigned  -> nothing   (the intended default)
    //    assigned + items  -> nothing   (no passing row; the surface is optional)
    //    assigned + EMPTY  -> ONE warning row (otherwise completely silent)
    // ══════════════════════════════════════════════════════════════════════

    /** Only the footer_secondary rows, by finding_key (never by message prose). */
    private function secondaryRows(array $checks): array
    {
        return array_values(array_filter(
            $checks,
            fn($c) => str_starts_with((string) ($c['finding_key'] ?? ''), 'nav_readiness:footer_secondary:')
        ));
    }

    /** Registers footer_secondary the way functions.php does. */
    private function registerSecondaryLocation(): void
    {
        $GLOBALS['_pp_test_store']['registered_nav_menus']['footer_secondary'] = 'Footer Secondary Navigation';
    }

    public function testNoRowWhenNoMenuIsAssignedToTheConditionalLocation(): void
    {
        $this->registerSecondaryLocation();
        // Registered, never assigned — the overwhelmingly common case.

        $checks = pp_check_nav_readiness();
        $this->assertSame([], $this->secondaryRows($checks));
        $this->assertStringNotContainsString(
            'footer_secondary',
            implode(' | ', array_column($checks, 'message')),
            'A site that never assigned the optional second footer menu must carry no row '
            . 'about it at all — not even a passing one.'
        );
    }

    public function testAssignedButEmptyConditionalMenuFiresExactlyOneWarningRow(): void
    {
        $this->registerSecondaryLocation();
        $GLOBALS['_pp_test_store']['nav_menu_locations']['footer_secondary'] = 9;
        $GLOBALS['_pp_test_store']['nav_menu_items'][9] = []; // assigned, but empty

        $rows = $this->secondaryRows(pp_check_nav_readiness());
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame('nav_readiness', $row['check']);
        $this->assertFalse($row['pass']);
        $this->assertSame('warning', $row['severity']);
        $this->assertSame('configuration', $row['class']);
        $this->assertSame('nav_readiness:footer_secondary:empty_menu', $row['finding_key']);
        $this->assertTrue($row['acknowledgeable']);
        $this->assertNotSame('', $row['next_action']);
        $this->assertStringContainsString('empty', $row['message']);
    }

    public function testAssignedMenuWithNoResolvableItemsIsTreatedAsEmpty(): void
    {
        // wp_get_nav_menu_items() returns false for a menu that cannot resolve.
        // The conditional check uses the IDENTICAL emptiness test as the
        // template-owned loop, so the two surfaces cannot drift on what "empty"
        // means.
        $this->registerSecondaryLocation();
        $GLOBALS['_pp_test_store']['nav_menu_locations']['footer_secondary'] = 11;
        // No nav_menu_items entry for 11 at all -> the stub returns false.

        $rows = $this->secondaryRows(pp_check_nav_readiness());
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['pass']);
    }

    public function testHealthyConditionalMenuReportsNothingAtAll(): void
    {
        $this->registerSecondaryLocation();
        $GLOBALS['_pp_test_store']['nav_menu_locations']['footer_secondary'] = 10;
        $GLOBALS['_pp_test_store']['nav_menu_items'][10] = [['id' => 1], ['id' => 2]];

        $this->assertSame(
            [],
            $this->secondaryRows(pp_check_nav_readiness()),
            'An optional-by-design surface must not leave a standing passing row on every '
            . 'site that uses it — the same rule the site-logo check follows.'
        );
    }

    public function testConditionalRowIsAdditiveAndLeavesTheTemplateOwnedRowsAlone(): void
    {
        $this->registerSecondaryLocation();
        $GLOBALS['_pp_test_store']['nav_menu_locations'] = ['primary' => 7, 'footer' => 8, 'footer_secondary' => 9];
        $GLOBALS['_pp_test_store']['nav_menu_items']     = [7 => [['id' => 1]], 8 => [['id' => 2]], 9 => []];

        $rows = pp_check_nav_readiness();
        // menuRows() drops only the logo row, so subtract the conditional rows too.
        $templateOwned = array_values(array_filter(
            $this->menuRows($rows),
            fn($c) => !str_starts_with((string) ($c['finding_key'] ?? ''), 'nav_readiness:footer_secondary:')
        ));
        $this->assertCount(2, $templateOwned, 'The two template-owned rows are unchanged.');
        foreach ($templateOwned as $row) {
            $this->assertTrue($row['pass'], 'Both template-owned locations are healthy here.');
        }
        $this->assertCount(1, $this->secondaryRows($rows));
    }

    // ── The three things that must NOT have changed ─────────────────────────

    public function testTemplateOwnedLocationListIsUnchangedByTheConditionalSurface(): void
    {
        // The conditional location must never leak into the always-on list: that
        // is the whole point of keeping two lists.
        $this->assertSame(['primary', 'footer'], pp_template_owned_menu_locations());
        $this->assertNotContains('footer_secondary', pp_template_owned_menu_locations());
    }

    public function testTheTwoLocationListsAreDisjoint(): void
    {
        // Both loops build the SAME finding_key string ('nav_readiness:<loc>:empty_menu'),
        // and acknowledgement keys on that string. A slug appearing in both lists would
        // emit two rows sharing one key, so acknowledging one would silence the other.
        // The literal pin above covers this incidentally today; this covers it
        // structurally, so a future addition to either list cannot reintroduce it.
        $this->assertSame(
            [],
            array_intersect(
                pp_template_owned_menu_locations(),
                pp_conditionally_rendered_menu_locations()
            ),
            'A location cannot be both always-rendered and conditionally-rendered: the two '
            . 'loops would emit rows sharing one finding_key, and one acknowledgement would '
            . 'silence both.'
        );
    }

    public function testConditionalRowNextActionNamesRealActions(): void
    {
        // The row tells the operator to use set_menu / add_menu_item. If either action
        // stopped existing (or was renamed), the finding would become an unactionable
        // standing row telling them to run something that is not there.
        $this->registerSecondaryLocation();
        $GLOBALS['_pp_test_store']['nav_menu_locations']['footer_secondary'] = 12;
        $GLOBALS['_pp_test_store']['nav_menu_items'][12] = [];

        $rows       = $this->secondaryRows(pp_check_nav_readiness());
        $nextAction = $rows[0]['next_action'];
        $registered = array_keys(pp_get_registered_actions());

        foreach (['set_menu', 'add_menu_item'] as $action) {
            $this->assertStringContainsString($action, $nextAction);
            $this->assertContains(
                $action,
                $registered,
                "next_action names the '{$action}' action, which must actually be registered."
            );
        }
    }

    public function testConditionalLocationListMatchesTheBaseTemplate(): void
    {
        // The sibling of testTemplateOwnedLocationsMatchBaseTemplate(). base.php
        // passes the slug as `secondary_location` — deliberately NOT a 'location'
        // key, so the template-owned drift guard cannot see it and this pin is
        // the only thing standing between the two.
        preg_match_all(
            "/'secondary_location'\s*=>\s*'([a-z0-9_-]+)'/i",
            $this->baseTemplateCode(),
            $matches
        );

        $this->assertSame(
            pp_conditionally_rendered_menu_locations(),
            $matches[1],
            'pp_conditionally_rendered_menu_locations() has drifted from the '
            . "`secondary_location` slugs in templates/base.php. Update the function "
            . '(lib/wp.php) to match the template.'
        );
    }

    public function testConditionalLocationIsRegisteredByTheTheme(): void
    {
        // A conditional location the theme never registered is unassignable (core's
        // has_nav_menu() requires registration), so the diagnostic could never fire.
        // Read functions.php back rather than trusting the test store, which seeds
        // registrations by hand.
        //
        // Comments are STRIPPED first, for the reason baseTemplateCode() documents at
        // the top of this class: functions.php already discusses footer_secondary in a
        // comment, so a bare file-wide grep would keep passing after the real
        // register_nav_menus() entry was deleted. Match inside the registration call.
        $functions = file_get_contents(__DIR__ . '/../functions.php');
        $this->assertNotFalse($functions, 'functions.php must be readable.');
        $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $functions);

        $this->assertSame(
            1,
            preg_match('/register_nav_menus\s*\(\s*\[(.*?)\]\s*\)/s', $code, $m),
            'functions.php must call register_nav_menus() with an array literal.'
        );
        foreach (pp_conditionally_rendered_menu_locations() as $loc) {
            $this->assertStringContainsString(
                "'{$loc}'",
                $m[1],
                "functions.php must register the '{$loc}' menu location inside "
                . 'register_nav_menus(), not merely mention it.'
            );
        }
    }
}
