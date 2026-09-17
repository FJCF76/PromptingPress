/**
 * Tests for assets/js/main.js's dropdown-submenu disclosure enhancement
 * (issue 381). WordPress's nav walker emits a nested <ul class="sub-menu">
 * inside <li class="menu-item-has-children">, and the THEME's walker
 * (lib/nav-walker.php) emits the .nav__submenu-toggle button between the two.
 * main.js wires that button into a WAI-ARIA disclosure: it marks the parent
 * .pp-has-dropdown, sets aria-controls, and toggles .is-open + aria-expanded.
 *
 * THE FIXTURES CARRY THE BUTTON SINCE #994 (ruling D5), because the server does.
 * main.js used to create it, which made `.nav__submenu-toggle` a class no PHP
 * ever emitted and therefore a class no UDC role could legally select. These
 * fixtures are the walker's output, so what they exercise is the real contract:
 * a menu that arrives WITHOUT a button is a menu main.js must not half-enhance,
 * which is its own test below.
 *
 * Progressive enhancement is verified separately by the CSS (no-JS still
 * exposes the submenu, and the button stays display:none because nothing sets
 * .pp-has-dropdown); these tests cover the JS-on behavior.
 */

const { JSDOM } = require('jsdom');

function setup(menuHtml) {
    const dom = new JSDOM('<!DOCTYPE html><html><body>' +
        '<header class="site-header">' +
        '  <div class="nav__container">' +
        '    <button class="nav__toggle" aria-expanded="false"></button>' +
        '    <div id="pp-nav-menu" class="nav__menu">' + menuHtml + '</div>' +
        '  </div>' +
        '</header>' +
        '</body></html>', { url: 'http://localhost', runScripts: 'outside-only' });

    global.window = dom.window;
    global.document = dom.window.document;

    delete require.cache[require.resolve('../../assets/js/main.js')];
    const script = require('fs').readFileSync(require.resolve('../../assets/js/main.js'), 'utf8');
    dom.window.eval(script);

    return dom;
}

// The disclosure button exactly as pp_nav_submenu_toggle_markup() (lib/wp.php) renders
// it. A HAND COPY, and therefore a drift risk worth naming: the PHP side is pinned by
// NavDisclosureWalkerTest, but nothing fails here if the class names or the aria-label
// wording change there. Keep the two in step by hand, and prefer changing the PHP first
// so its own pin fails loudly before this fixture goes quietly stale.
const TOGGLE_MARKUP =
    '<button type="button" class="nav__submenu-toggle" aria-expanded="false"' +
    ' aria-label="Toggle submenu for Servicios">' +
    '<svg class="nav__submenu-toggle-icon" aria-hidden="true"></svg></button>';

const DROPDOWN_MENU =
    '<ul class="menu">' +
    '  <li class="menu-item"><a href="/">Inicio</a></li>' +
    '  <li class="menu-item menu-item-has-children"><a href="/servicios">Servicios</a>' +
    TOGGLE_MARKUP +
    '    <ul class="sub-menu">' +
    '      <li class="menu-item"><a href="/cloud">Cloud</a></li>' +
    '      <li class="menu-item"><a href="/hosting">Hosting</a></li>' +
    '    </ul>' +
    '  </li>' +
    '</ul>';

// The same menu with NO button — a third-party menu, or markup from before the
// walker existed. main.js must leave it entirely alone rather than collapse a
// submenu it has no control to reopen.
const DROPDOWN_MENU_NO_BUTTON =
    '<ul class="menu">' +
    '  <li class="menu-item menu-item-has-children"><a href="/servicios">Servicios</a>' +
    '    <ul class="sub-menu">' +
    '      <li class="menu-item"><a href="/cloud">Cloud</a></li>' +
    '    </ul>' +
    '  </li>' +
    '</ul>';

const FLAT_MENU =
    '<ul class="menu">' +
    '  <li class="menu-item"><a href="/">Inicio</a></li>' +
    '  <li class="menu-item"><a href="/about">About</a></li>' +
    '</ul>';

describe('nav dropdown disclosure (issue 381)', function () {
    afterEach(function () {
        delete global.window;
        delete global.document;
    });

    test('a menu with no server-rendered button is left unenhanced, not half-enhanced', function () {
        // THE REGRESSION THIS GUARDS is worse than "no dropdown": `.pp-has-dropdown`
        // is what the stylesheet keys the COLLAPSE on, so marking it without a working
        // toggle hides a submenu nothing can reopen. Progressive enhancement has to
        // fail back to "fully visible", never to "fully hidden".
        const dom = setup(DROPDOWN_MENU_NO_BUTTON);
        const parent = dom.window.document.querySelector('li.menu-item-has-children');

        expect(parent.querySelector('.nav__submenu-toggle')).toBeNull();
        expect(parent.classList.contains('pp-has-dropdown')).toBe(false);
        expect(parent.classList.contains('is-open')).toBe(false);
    });

    test('main.js no longer BUILDS the button — it only wires the one it is given', function () {
        // A source tripwire, because the behavioural tests above would pass just as
        // happily if main.js re-created a button it had been handed. The class name
        // and the chevron's markup are PHP's now (lib/wp.php, lib/nav-walker.php); a
        // copy here is the split authority #994 removed.
        const src = require('fs').readFileSync(
            require.resolve('../../assets/js/main.js'), 'utf8',
        );
        expect(src).not.toMatch(/createElement\(\s*['"]button['"]/);
        expect(src).not.toMatch(/nav__submenu-toggle-icon/);
        expect(src).not.toMatch(/<svg/);
    });

    test('wires the server-rendered disclosure button and marks the parent', function () {
        const dom = setup(DROPDOWN_MENU);
        const parent = dom.window.document.querySelector('li.menu-item-has-children');
        const toggle = parent.querySelector('.nav__submenu-toggle');
        const submenu = parent.querySelector('ul.sub-menu');

        expect(parent.classList.contains('pp-has-dropdown')).toBe(true);
        expect(toggle).not.toBeNull();
        expect(toggle.tagName).toBe('BUTTON');
        expect(toggle.getAttribute('type')).toBe('button');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        // aria-controls points at the submenu's (now-assigned) id.
        expect(submenu.id).toBeTruthy();
        expect(toggle.getAttribute('aria-controls')).toBe(submenu.id);
        // Distinct accessible name; the parent link stays a separate control.
        expect(toggle.getAttribute('aria-label')).toBe('Toggle submenu for Servicios');
        // Button sits after the parent link, not inside it.
        const link = parent.querySelector('a');
        expect(link.nextElementSibling).toBe(toggle);
    });

    test('clicking the toggle opens and closes the group', function () {
        const dom = setup(DROPDOWN_MENU);
        const parent = dom.window.document.querySelector('li.menu-item-has-children');
        const toggle = parent.querySelector('.nav__submenu-toggle');

        toggle.click();
        expect(parent.classList.contains('is-open')).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');

        toggle.click();
        expect(parent.classList.contains('is-open')).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('ArrowDown on the toggle opens the group and focuses the first child link', function () {
        const dom = setup(DROPDOWN_MENU);
        const parent = dom.window.document.querySelector('li.menu-item-has-children');
        const toggle = parent.querySelector('.nav__submenu-toggle');
        const firstChild = parent.querySelector('.sub-menu a');

        toggle.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));

        expect(parent.classList.contains('is-open')).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(dom.window.document.activeElement).toBe(firstChild);
    });

    test('Escape from inside the submenu closes it and returns focus to the toggle', function () {
        const dom = setup(DROPDOWN_MENU);
        const parent = dom.window.document.querySelector('li.menu-item-has-children');
        const toggle = parent.querySelector('.nav__submenu-toggle');
        const childLink = parent.querySelector('.sub-menu a');

        toggle.click();
        childLink.focus();
        expect(parent.classList.contains('is-open')).toBe(true);

        childLink.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(parent.classList.contains('is-open')).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(dom.window.document.activeElement).toBe(toggle);
    });

    test('a click outside an open group closes it', function () {
        const dom = setup(DROPDOWN_MENU);
        const parent = dom.window.document.querySelector('li.menu-item-has-children');
        const toggle = parent.querySelector('.nav__submenu-toggle');

        toggle.click();
        expect(parent.classList.contains('is-open')).toBe(true);

        dom.window.document.body.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(parent.classList.contains('is-open')).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('a flat menu with no submenu is left untouched', function () {
        const dom = setup(FLAT_MENU);
        expect(dom.window.document.querySelector('.nav__submenu-toggle')).toBeNull();
        expect(dom.window.document.querySelector('.pp-has-dropdown')).toBeNull();
    });
});
