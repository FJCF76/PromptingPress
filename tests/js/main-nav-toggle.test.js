/**
 * Tests for assets/js/main.js's top-level hamburger disclosure (issue 426).
 *
 * The mobile menu is a WAI-ARIA *disclosure* (button + aria-expanded/aria-controls,
 * no role="menu"), mirroring the #381 submenu precedent. main.js owns the toggle:
 * it hides the menu on load (progressive enhancement — visible without JS), then
 * opens/closes it and keeps aria-expanded truthful across every path (toggle click,
 * Escape, link click, outside click, and a reset when the viewport crosses to the
 * >=768px desktop breakpoint). The open-state ICON swap (hamburger <-> X) is CSS-only
 * (driven off aria-expanded), pinned in tests/js/css-lint.test.js and the E2E render,
 * so these unit tests assert state/aria/focus, not computed icon visibility.
 *
 * No transitions are added by this change (the panel appears/disappears via the
 * `hidden` attribute), so there is no reduced-motion guard to exercise here.
 *
 *   setMenuOpen(open) is the single source of truth:
 *
 *     closed ──click / (was hidden)──▶ open ──click / Esc / link / outside / >=768px──▶ closed
 *        aria-expanded=false                       aria-expanded=true  ──▶  aria-expanded=false
 */

const { JSDOM } = require('jsdom');

/**
 * Build the nav DOM and eval main.js against it.
 *
 * `matchMedia` is a controllable stub: jsdom ships none, and main.js guards its
 * use, but the resize-reset path needs a fake MediaQueryList whose registered
 * `change` listener the test can fire. The stub records the listener on
 * `dom.window.__mql` so a test can dispatch a breakpoint crossing.
 */
function setup(menuHtml) {
    const dom = new JSDOM('<!DOCTYPE html><html><body>' +
        '<header class="site-header">' +
        '  <nav class="nav">' +
        '    <div class="container nav__container">' +
        '      <a class="nav__logo" href="/">Logo</a>' +
        '      <button class="nav__toggle" aria-expanded="false" aria-controls="pp-nav-menu" type="button">' +
        '        <span class="nav__toggle-icon nav__toggle-icon--open" aria-hidden="true"></span>' +
        '        <span class="nav__toggle-icon nav__toggle-icon--close" aria-hidden="true"></span>' +
        '        <span class="sr-only">Menu</span>' +
        '      </button>' +
        '      <div id="pp-nav-menu" class="nav__menu">' + menuHtml + '</div>' +
        '    </div>' +
        '  </nav>' +
        '</header>' +
        '<main><p>Page content outside the header</p></main>' +
        '</body></html>', { url: 'http://localhost', runScripts: 'outside-only' });

    // Controllable matchMedia stub (jsdom has none). Records the change listener so
    // the resize-reset test can simulate crossing the 768px breakpoint.
    const mql = {
        matches: false,
        _listeners: [],
        addEventListener(_evt, cb) { this._listeners.push(cb); },
        removeEventListener(_evt, cb) { this._listeners = this._listeners.filter(l => l !== cb); },
        dispatch(matches) {
            this.matches = matches;
            this._listeners.forEach(cb => cb({ matches: matches }));
        },
    };
    dom.window.matchMedia = function () { return mql; };
    dom.window.__mql = mql;

    global.window = dom.window;
    global.document = dom.window.document;

    delete require.cache[require.resolve('../../assets/js/main.js')];
    const script = require('fs').readFileSync(require.resolve('../../assets/js/main.js'), 'utf8');
    dom.window.eval(script);

    return dom;
}

const FLAT_MENU =
    '<ul class="menu">' +
    '  <li class="menu-item"><a href="/">Inicio</a></li>' +
    '  <li class="menu-item"><a href="/about">About</a></li>' +
    '</ul>';

describe('nav hamburger disclosure (issue 426)', function () {
    afterEach(function () {
        delete global.window;
        delete global.document;
    });

    function els(dom) {
        return {
            toggle: dom.window.document.querySelector('.nav__toggle'),
            menu: dom.window.document.getElementById('pp-nav-menu'),
        };
    }

    test('initial state: JS hides the menu and aria-expanded is false', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);
        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('clicking the toggle opens the menu (hidden cleared, aria-expanded true)', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        toggle.click();
        expect(menu.hidden).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
    });

    test('clicking the toggle again closes the menu', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        toggle.click();
        expect(toggle.getAttribute('aria-expanded')).toBe('true');

        toggle.click();
        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('Escape closes the menu and returns focus to the toggle', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        toggle.click();
        expect(menu.hidden).toBe(false);

        dom.window.document.dispatchEvent(
            new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
        );

        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(dom.window.document.activeElement).toBe(toggle);
    });

    test('Escape while already closed is a no-op (aria stays false)', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        dom.window.document.dispatchEvent(
            new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
        );

        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('clicking a menu link closes the menu', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        toggle.click();
        expect(menu.hidden).toBe(false);

        const link = menu.querySelector('a');
        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('a click outside the header closes the open menu', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        toggle.click();
        expect(menu.hidden).toBe(false);

        // A click on page content (outside .site-header).
        dom.window.document.querySelector('main p')
            .dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('a click INSIDE the panel does not close the menu', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        toggle.click();
        expect(menu.hidden).toBe(false);

        // Click a non-link spot inside the menu container (still inside the header).
        menu.querySelector('ul')
            .dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(menu.hidden).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
    });

    test('crossing to the >=768px breakpoint resets an open menu to closed', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        toggle.click();
        expect(menu.hidden).toBe(false);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');

        // Simulate the viewport growing past 768px (matchMedia change → matches:true).
        dom.window.__mql.dispatch(true);

        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('a breakpoint change back to mobile (matches:false) does not reopen the menu', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        // Start closed; a matches:false event must not open anything.
        dom.window.__mql.dispatch(false);

        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('a stale open state does not survive a breakpoint crossing (no lingering aria-expanded)', function () {
        const dom = setup(FLAT_MENU);
        const { toggle, menu } = els(dom);

        toggle.click();
        dom.window.__mql.dispatch(true);   // → desktop, reset to closed
        expect(toggle.getAttribute('aria-expanded')).toBe('false');

        // Returning to mobile leaves it closed until the user acts.
        dom.window.__mql.dispatch(false);
        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });
});
