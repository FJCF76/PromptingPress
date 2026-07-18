/**
 * Tests for assets/js/main.js's dropdown-submenu disclosure enhancement
 * (issue 381). WordPress's default nav walker emits a nested
 * <ul class="sub-menu"> inside <li class="menu-item-has-children">; main.js
 * enhances each into a WAI-ARIA disclosure: it injects a toggle button, marks
 * the parent .pp-has-dropdown, and toggles .is-open + aria-expanded.
 *
 * Progressive enhancement is verified separately by the CSS (no-JS still
 * exposes the submenu); these tests cover the JS-on behavior.
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

const DROPDOWN_MENU =
    '<ul class="menu">' +
    '  <li class="menu-item"><a href="/">Inicio</a></li>' +
    '  <li class="menu-item menu-item-has-children"><a href="/servicios">Servicios</a>' +
    '    <ul class="sub-menu">' +
    '      <li class="menu-item"><a href="/cloud">Cloud</a></li>' +
    '      <li class="menu-item"><a href="/hosting">Hosting</a></li>' +
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

    test('injects a labelled disclosure button and marks the parent', function () {
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
