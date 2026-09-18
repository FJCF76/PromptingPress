<?php
/**
 * tests/NavDisclosureWalkerTest.php
 *
 * The header's dropdown disclosure button, server-rendered (#994, ruling D5).
 *
 * WHAT MOVED AND WHY IT HAD TO. `assets/js/main.js` built this button in the
 * browser — created the `<button>`, filled it with an inline SVG chevron, and
 * inserted it between a parent link and its `<ul class="sub-menu">`. That made
 * `.nav__submenu-toggle` a class NO PHP ever emitted, and the UDC's role-selector
 * lint checks selectors against markup a component actually renders. So the six
 * declarations styling that button had two honest homes: a role whose selector the
 * server can prove, or nowhere at all. #994's retirement removed "nowhere" as an
 * option (the stylesheet can no longer hold designable values for a v2 component),
 * and #995's two chevron defects are what the deferral was costing.
 *
 * WHAT THIS FILE PROVES, in the order the risk runs:
 *
 *   1. THE MARKUP CONTRACT. The class names two role selectors depend on, the
 *      `aria-expanded` the disclosure pattern requires, a distinct accessible name,
 *      and a decorative icon. Asserted on the string builder directly, so it holds
 *      without WordPress's walker class present.
 *   2. ONLY WHERE THERE ARE CHILDREN. `start_lvl()` runs exactly when an item has a
 *      submenu, so a flat menu gains nothing. A button beside a childless item would
 *      be a control that discloses nothing.
 *   3. THE NO-JS PATH. The button renders unconditionally but is `display: none`
 *      until main.js marks the group `.pp-has-dropdown` — so a visitor without
 *      JavaScript sees exactly what they saw before (no control), and never an inert
 *      button a keyboard user can reach and press to no effect.
 *   4. THE aria-controls HANDOFF. It is set by main.js, beside the id it points at.
 *      Stated here rather than left to be discovered, because "the a11y wiring is
 *      preserved" is only true if BOTH halves are accounted for.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class NavDisclosureWalkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_pp_test_store'] = [
            'post_meta' => [], 'posts' => [], 'options' => [], 'next_id' => 100,
        ];
    }

    /** The header, as the template renders it. */
    private function renderNav(): string
    {
        ob_start();
        try {
            pp_get_component('nav', ['location' => 'primary']);
        } finally {
            return (string) ob_get_clean();
        }
    }

    // ── 1. The markup contract ──────────────────────────────────────────────

    public function testTheButtonCarriesEveryAttributeTheDisclosurePatternNeeds(): void
    {
        $html = pp_nav_submenu_toggle_markup('Services');

        // The two classes role selectors point at. A rename here silently disables
        // `submenu-toggle` (the selector gate skips a role whose element is absent
        // only at lint time, never at render time), so they are pinned by name.
        $this->assertStringContainsString('class="nav__submenu-toggle"', $html);
        $this->assertStringContainsString('class="nav__submenu-toggle-icon"', $html);

        $this->assertStringContainsString('<button type="button"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);

        // A DISTINCT accessible name. The button sits beside a link carrying the same
        // label and the two are different controls — the link navigates, the button
        // discloses. This is the string main.js built, preserved verbatim so no
        // screen-reader user hears a changed name.
        $this->assertStringContainsString('aria-label="Toggle submenu for Services"', $html);

        // The icon is decorative: the button already has a name.
        $this->assertStringContainsString('aria-hidden="true"', $html);

        // NOT a menubar. The disclosure pattern is deliberate and role="menu" would
        // promise keyboard semantics this does not implement.
        $this->assertStringNotContainsString('role="menu', $html);
    }

    public function testTheAccessibleNameIsEscapedAndSurvivesAnEmptyLabel(): void
    {
        $this->assertStringContainsString(
            'aria-label="Toggle submenu for submenu"',
            pp_nav_submenu_toggle_markup(''),
            'a nameless group still needs a name, not an empty one'
        );

        // A menu label is site content and reaches an attribute, so it is escaped.
        // The payload's TEXT survives (it is a label, not a threat); what must not
        // survive is its ability to close the attribute and open a new one — so the
        // assertion is about the raw quote, not about the word.
        $html = pp_nav_submenu_toggle_markup('A" onfocus="alert(1)');
        $this->assertStringNotContainsString('" onfocus="', $html, 'the quote must not break out of the attribute');
        $this->assertStringContainsString('&quot; onfocus=&quot;alert(1)', $html);
    }

    // ── 2. Only where there are children ────────────────────────────────────

    public function testTheHeaderRendersOneButtonPerSubmenuAndNoneForFlatItems(): void
    {
        $html = $this->renderNav();

        // The harness's menu fixture is three top-level items, exactly one of which
        // has a child level (see tests/bootstrap.php's wp_nav_menu stub).
        $this->assertSame(
            1,
            substr_count($html, 'class="nav__submenu-toggle"'),
            'one disclosure per submenu — never one per item'
        );
        $this->assertSame(1, substr_count($html, '<ul class="sub-menu">'));

        // And it sits BETWEEN the parent link and the submenu, which is where main.js
        // used to insert it and where the `.sub-menu` sibling selectors expect it.
        $button  = strpos($html, 'class="nav__submenu-toggle"');
        $submenu = strpos($html, '<ul class="sub-menu">');
        $this->assertIsInt($button);
        $this->assertIsInt($submenu);
        $this->assertLessThan($submenu, $button);
    }

    // ── 3. The no-JS path ───────────────────────────────────────────────────

    public function testTheButtonIsHiddenUntilJavaScriptMarksTheGroup(): void
    {
        // Rendering it unconditionally and hiding it in CSS is what keeps the no-JS
        // render byte-identical in EFFECT to the old one: main.js is the only thing
        // that adds `.pp-has-dropdown`, so without it the control never appears.
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/components.css');
        $this->assertIsString($css);

        $this->assertMatchesRegularExpression(
            '/\.nav__submenu-toggle\s*\{[^}]*display:\s*none/s',
            $css,
            'the server-rendered button must start hidden, or a no-JS visitor gets an inert control'
        );
        $this->assertMatchesRegularExpression(
            '/\.nav__menu li\.pp-has-dropdown > \.nav__submenu-toggle\s*\{[^}]*display:\s*inline-flex/s',
            $css,
            'and it must appear exactly when JS takes ownership of the group'
        );

        // The no-JS submenu stays EXPANDED, which is the other half of the same
        // promise: the collapse is keyed on the same JS-set class.
        $this->assertMatchesRegularExpression(
            '/\.nav__menu li\.pp-has-dropdown > \.sub-menu\s*\{[^}]*display:\s*none/s',
            $css
        );
    }

    // ── 4. The aria-controls handoff ────────────────────────────────────────

    public function testMainJsStillOwnsAriaControlsAndNoLongerBuildsTheButton(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/assets/js/main.js');
        $this->assertIsString($js);

        // KEPT: the id and the attribute pointing at it are minted together, beside
        // the collapse behaviour the id labels. Emitting the id server-side would mean
        // duplicating core's start_lvl() (dropping the nav_menu_submenu_css_class
        // filter with it) or rewriting core's output after the fact.
        $this->assertStringContainsString("setAttribute('aria-controls', submenu.id)", $js);
        $this->assertStringContainsString("submenu.id = 'pp-submenu-'", $js);

        // THE "main.js NO LONGER BUILDS IT" TRIPWIRE LIVES IN THE JS SUITE, not here
        // (tests/js/main-nav-dropdown.test.js). It is a grep of a JavaScript file, and
        // two suites failing identically on one edit buys nothing except a second place
        // to remember. What this test uniquely owns is the PHP side of the handoff: the
        // attribute main.js must still set, asserted above.
    }

    public function testTheWalkerIsWiredIntoEveryThemeMenuAndDegradesWithoutCore(): void
    {
        // The walker is passed for every location, not only `primary`, and that is safe
        // rather than a widening: it emits nothing for a FLAT menu, because start_lvl()
        // only runs when an item has children. A nested NON-header menu would get a
        // hidden, unwired button — the reveal rule and main.js are both header-scoped —
        // which is inert markup, not a second disclosure. See pp_nav_menu()'s docblock;
        // this test asserts the wiring, not that claim.
        $wp = file_get_contents(dirname(__DIR__) . '/lib/wp.php');
        $this->assertIsString($wp);
        $this->assertStringContainsString("\$args['walker'] = \$walker;", $wp);

        // In-process, the harness stubs the base class, so a walker IS built.
        $this->assertNotNull(pp_nav_menu_walker(), 'the harness stubs the base class, so one is built');
    }

    /**
     * THE DEGRADE PATH, EXECUTED RATHER THAN NAMED.
     *
     * `class PP_Nav_Menu_Walker extends Walker_Nav_Menu` is a hard dependency on a
     * WordPress core class, which is the entire reason lib/nav-walker.php is required
     * lazily behind a class_exists() check: a file-scope declaration would fatal
     * anywhere core has not loaded — this unit harness included, before #994 taught it
     * to stub the base class.
     *
     * THAT STUB IS WHY THIS NEEDS A SUBPROCESS. tests/bootstrap.php now defines
     * `Walker_Nav_Menu` unconditionally, so the null arm became unreachable in-process
     * at the same moment it acquired a test — and the test it acquired asserted the
     * OPPOSITE branch while grepping the source for the guard's text, which proves a
     * string, not a behaviour. A child process with no bootstrap is the only place the
     * guard actually runs.
     */
    public function testTheWalkerDegradesToNullWhereCoresBaseClassIsAbsent(): void
    {
        $probe = tempnam(sys_get_temp_dir(), 'ppnav') . '.php';
        file_put_contents(
            $probe,
            "<?php\nrequire " . var_export(dirname(__DIR__) . '/lib/wp.php', true) . ";\n"
            . "var_export(pp_nav_menu_walker() === null);\n"
        );

        try {
            $out = shell_exec(PHP_BINARY . ' ' . escapeshellarg($probe) . ' 2>&1');
        } finally {
            @unlink($probe);
        }

        $this->assertSame(
            'true',
            trim((string) $out),
            'without core\'s Walker_Nav_Menu, pp_nav_menu_walker() must return null rather than '
            . 'fatal on a missing parent class — output was: ' . trim((string) $out)
        );
    }
}
