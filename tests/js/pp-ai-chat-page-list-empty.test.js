/**
 * Chat: the page list shows the pages you can work on — the empty list, client side.
 *
 * The page list the chat receives (ppAiChat.pages) holds only the pages the current user
 * can edit, so it can be empty. Sending with nothing selected used to raise an error
 * telling the user to pick from the dropdown above, which then had nothing in it. With an
 * empty list the chat says "No pages found." as a plain status line (no error, no alert
 * role) and sends nothing. With pages, the existing "Select a page" prompt is unchanged.
 *
 * Fresh DOM + fresh module per test, as in pp-ai-chat-stream-refusal.test.js.
 */

const { JSDOM } = require('jsdom');

describe('sending with no page selected', function () {
    var dom, window, document, fetchCalls;

    function boot(pages) {
        dom = new JSDOM('<!DOCTYPE html><html><body>' +
            '<div id="pp-ai-messages"></div>' +
            '<select id="pp-ai-page-select"><option value=""></option></select>' +
            '<textarea id="pp-ai-input"></textarea>' +
            '<button id="pp-ai-send"></button>' +
            '<button id="pp-ai-stop" style="display:none;"></button>' +
            '<button id="pp-ai-new-chat"></button>' +
            '</body></html>', { url: 'http://localhost' });

        window = dom.window;
        document = dom.window.document;

        global.window = window;
        global.document = document;
        global.HTMLElement = window.HTMLElement;
        global.localStorage = window.localStorage;
        global.FormData = window.FormData;

        fetchCalls = [];
        global.fetch = function (url, opts) {
            fetchCalls.push(url);
            return Promise.resolve({ json: function () { return Promise.resolve({}); } });
        };

        window.ppAiChat = {
            configured: true,
            ajaxUrl: '/wp-admin/admin-ajax.php',
            executeNonce: 'test-nonce',
            siteUrl: 'http://page-list-empty.example.com',
            streamUrl: '/stream',
            streamNonce: 'stream-nonce',
            pages: pages
        };
        global.window.ppAiChat = window.ppAiChat;

        var modulePath = require.resolve('../../assets/js/pp-ai-chat.js');
        delete require.cache[modulePath];
        vi.resetModules();
        require('../../assets/js/pp-ai-chat.js');
    }

    afterEach(function () {
        delete global.fetch;
    });

    function send(text) {
        document.getElementById('pp-ai-input').value = text;
        document.getElementById('pp-ai-send').click();
    }

    test('an empty page list says "No pages found." without an error', function () {
        boot([]);

        send('tighten the hero copy');

        var statuses = document.querySelectorAll('.pp-ai-status');
        expect(statuses.length).toBe(1);
        expect(statuses[0].textContent).toBe('No pages found.');
        expect(statuses[0].classList.contains('pp-ai-status-error')).toBe(false);
        expect(statuses[0].getAttribute('role')).toBeNull();
        expect(document.querySelector('.pp-ai-msg-user')).toBeNull(); // nothing was sent
        expect(fetchCalls.length).toBe(0);
    });

    test('a page list with pages keeps the "Select a page" prompt', function () {
        boot([{ id: 1, title: 'About' }]);

        send('tighten the hero copy');

        var status = document.querySelector('.pp-ai-status');
        expect(status.textContent).toBe('Select a page before sending, using the page dropdown above.');
        expect(status.classList.contains('pp-ai-status-error')).toBe(true);
        expect(fetchCalls.length).toBe(0);
    });
});
