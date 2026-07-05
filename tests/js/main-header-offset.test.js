/**
 * Tests for assets/js/main.js's sticky-header anchor-offset behavior
 * (issue 63) and the mobile-menu-closes-on-link-click behavior that
 * prevents the header's taller expanded-menu state from persisting into
 * an anchor scroll.
 *
 * jsdom has no real layout engine, so offsetHeight always reads 0 unless
 * explicitly stubbed — each test defines it on the header element to
 * simulate a real rendered height.
 */

const { JSDOM } = require('jsdom');

function setup(headerHeight) {
    const dom = new JSDOM('<!DOCTYPE html><html><body>' +
        '<header class="site-header">' +
        '  <div class="nav__container">' +
        '    <button class="nav__toggle" aria-expanded="false"></button>' +
        '    <nav id="pp-nav-menu"><ul><li><a href="#pricing">Pricing</a></li></ul></nav>' +
        '  </div>' +
        '</header>' +
        '</body></html>', { url: 'http://localhost', runScripts: 'outside-only' });

    global.window = dom.window;
    global.document = dom.window.document;

    const header = dom.window.document.querySelector('.site-header');
    Object.defineProperty(header, 'offsetHeight', { value: headerHeight, configurable: true });

    delete require.cache[require.resolve('../../assets/js/main.js')];
    const script = require('fs').readFileSync(require.resolve('../../assets/js/main.js'), 'utf8');
    dom.window.eval(script);

    return dom;
}

describe('sticky header anchor offset (issue 63)', function () {
    afterEach(function () {
        delete global.window;
        delete global.document;
    });

    test('sets --header-height to the real rendered header height on load', function () {
        const dom = setup(72);
        const value = dom.window.document.documentElement.style.getPropertyValue('--header-height');
        expect(value).toBe('72px');
    });

    test('updates --header-height on window resize', function () {
        const dom = setup(72);
        const header = dom.window.document.querySelector('.site-header');
        Object.defineProperty(header, 'offsetHeight', { value: 120, configurable: true });

        dom.window.dispatchEvent(new dom.window.Event('resize'));

        const value = dom.window.document.documentElement.style.getPropertyValue('--header-height');
        expect(value).toBe('120px');
    });

    test('clicking the hamburger toggle re-measures the header height', function () {
        const dom = setup(65);
        const header = dom.window.document.querySelector('.site-header');
        const toggle = dom.window.document.querySelector('.nav__toggle');

        // Simulate the menu expanding and growing the header's total height.
        Object.defineProperty(header, 'offsetHeight', { value: 240, configurable: true });
        toggle.click();

        const value = dom.window.document.documentElement.style.getPropertyValue('--header-height');
        expect(value).toBe('240px');
    });

    test('clicking a link inside the mobile menu closes it', function () {
        const dom = setup(65);
        const toggle = dom.window.document.querySelector('.nav__toggle');
        const menu = dom.window.document.getElementById('pp-nav-menu');
        const link = menu.querySelector('a');

        // Open the menu first.
        toggle.click();
        expect(menu.hidden).toBe(false);

        link.click();

        expect(menu.hidden).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    test('clicking a link re-measures the header height back to its collapsed size', function () {
        const dom = setup(65);
        const header = dom.window.document.querySelector('.site-header');
        const toggle = dom.window.document.querySelector('.nav__toggle');
        const menu = dom.window.document.getElementById('pp-nav-menu');
        const link = menu.querySelector('a');

        // Open the menu (header grows) then click a link — the header
        // should be re-measured at its now-collapsed height, not left at
        // the taller expanded-menu measurement.
        Object.defineProperty(header, 'offsetHeight', { value: 240, configurable: true });
        toggle.click();

        Object.defineProperty(header, 'offsetHeight', { value: 65, configurable: true });
        link.click();

        const value = dom.window.document.documentElement.style.getPropertyValue('--header-height');
        expect(value).toBe('65px');
    });
});
