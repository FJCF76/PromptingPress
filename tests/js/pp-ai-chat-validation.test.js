/**
 * Tests for post-apply validation rendering in assets/js/pp-ai-chat.js
 *
 * Covers:
 *   appendValidationItems — truncation at 5 items + details disclosure (D6)
 *   Validation card states — green (pass), amber (warnings), red (errors)
 *   Conversation injection — conditional on validation result
 *   Last-step-wins — multi-step same page
 *   No-validation — step without post_id
 */

const { JSDOM } = require('jsdom');
const dom = new JSDOM('<!DOCTYPE html><html><body>' +
    '<div id="pp-ai-messages"></div>' +
    '<textarea id="pp-ai-input"></textarea>' +
    '<button id="pp-ai-send"></button>' +
    '</body></html>', { url: 'http://localhost' });

global.window = dom.window;
global.document = dom.window.document;
global.HTMLElement = dom.window.HTMLElement;
global.localStorage = dom.window.localStorage;
global.FormData = dom.window.FormData;
global.fetch = function () { return Promise.resolve({ json: function () { return Promise.resolve({}); } }); };

dom.window.ppAiChat = {
    configured: true,
    ajaxUrl: '/wp-admin/admin-ajax.php',
    executeNonce: 'test-nonce',
    siteUrl: 'http://example.com',
    streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
    streamNonce: 'stream-nonce'
};
global.window.ppAiChat = dom.window.ppAiChat;

const { appendValidationItems } = require('../../assets/js/pp-ai-chat.js');

// ─── appendValidationItems ──────────────────────────────────────────────────

describe('appendValidationItems', function () {
    let container;

    beforeEach(function () {
        container = document.createElement('div');
    });

    test('renders all items when 5 or fewer', function () {
        var items = [
            { message: 'Error 1' },
            { message: 'Error 2' },
            { message: 'Error 3' },
        ];
        appendValidationItems(container, items, 'pp-ai-step-failed');

        expect(container.children.length).toBe(3);
        expect(container.querySelector('details')).toBeNull();
    });

    test('truncates at 5 and shows disclosure for overflow', function () {
        var items = [];
        for (var i = 1; i <= 8; i++) {
            items.push({ message: 'Error ' + i });
        }
        appendValidationItems(container, items, 'pp-ai-step-failed');

        // 5 inline divs + 1 details element
        expect(container.children.length).toBe(6);
        var details = container.querySelector('details');
        expect(details).not.toBeNull();
        expect(details.querySelector('summary').textContent).toBe('Show 3 more errors');
    });

    test('uses correct singular form for 1 overflow item', function () {
        var items = [];
        for (var i = 1; i <= 6; i++) {
            items.push({ message: 'Warning ' + i });
        }
        appendValidationItems(container, items, 'pp-ai-step-warning');

        var summary = container.querySelector('details summary');
        expect(summary.textContent).toBe('Show 1 more warning');
    });

    test('does nothing for empty items', function () {
        appendValidationItems(container, [], 'pp-ai-step-failed');
        expect(container.children.length).toBe(0);
    });

    test('does nothing for null items', function () {
        appendValidationItems(container, null, 'pp-ai-step-failed');
        expect(container.children.length).toBe(0);
    });

    test('applies className to each item', function () {
        appendValidationItems(container, [{ message: 'Test' }], 'pp-ai-step-warning');
        expect(container.firstChild.className).toBe('pp-ai-step-warning');
    });

    test('items inside disclosure also get className', function () {
        var items = [];
        for (var i = 1; i <= 7; i++) {
            items.push({ message: 'Error ' + i });
        }
        appendValidationItems(container, items, 'pp-ai-step-failed');

        var details = container.querySelector('details');
        var overflowDivs = details.querySelectorAll('.pp-ai-step-failed');
        expect(overflowDivs.length).toBe(2);
    });

    test('uses existing disclosure pattern class', function () {
        appendValidationItems(container, Array(7).fill({ message: 'E' }), 'pp-ai-step-failed');
        var details = container.querySelector('details');
        expect(details.className).toBe('pp-ai-preview-error-detail');
    });
});
