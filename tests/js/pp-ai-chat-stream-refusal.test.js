/**
 * Chat: page context honours per-page permissions — the client side.
 *
 * ai-stream.php answers a refusal (nonce, capability, or the selected page's permission)
 * with a 403 marked `X-PP-Refusal: 1`. streamChat() used to treat every non-ok response as
 * a transport failure and retry through the non-streaming endpoint, which turned the
 * refusal into a "compatibility mode" answer. A marked 403 is now shown as the refusal it
 * is and nothing is sent to the fallback. An unmarked 403 (a WAF or CDN in front of
 * WordPress) and any other failure still fall back (issue 139).
 *
 * Fresh DOM + fresh module per test, as in pp-ai-chat-stop-fallback.test.js.
 */

const { JSDOM } = require('jsdom');

/** Lets the fetch -> text() -> handler promise chain settle (no timers involved). */
async function flushMicrotasks() {
    for (var i = 0; i < 20; i++) {
        await Promise.resolve();
    }
}

function headers(map) {
    return {
        get: function (name) {
            var key = Object.keys(map).find(function (k) { return k.toLowerCase() === name.toLowerCase(); });
            return key === undefined ? null : map[key];
        }
    };
}

/** A response whose body never yields a token, so only the status path matters. */
function response(status, bodyPromise, headerMap) {
    return Promise.resolve({
        ok: status >= 200 && status < 300,
        status: status,
        headers: headers(headerMap || {}),
        text: function () { return bodyPromise; },
        body: { getReader: function () { return { read: function () { return new Promise(function () {}); } }; } }
    });
}

function refusal(body) {
    return response(403, Promise.resolve(body), { 'X-PP-Refusal': '1' });
}

describe('stream refusal (a marked 403) is shown, not retried through the fallback', function () {
    var dom, window, document, ajaxCalls, streamFetchBehavior;

    beforeEach(function () {
        vi.useFakeTimers();

        dom = new JSDOM('<!DOCTYPE html><html><body>' +
            '<div id="pp-ai-messages"></div>' +
            '<select id="pp-ai-page-select"></select>' +
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
        global.TextDecoder = window.TextDecoder || require('util').TextDecoder;

        ajaxCalls = [];
        streamFetchBehavior = null;

        global.fetch = function (url, opts) {
            if (url === '/stream') {
                return streamFetchBehavior(opts);
            }
            ajaxCalls.push(opts);
            return Promise.resolve({
                json: function () {
                    return Promise.resolve({ success: true, data: { content: 'Fallback response text.' } });
                }
            });
        };

        window.ppAiChat = {
            configured: true,
            ajaxUrl: '/wp-admin/admin-ajax.php',
            executeNonce: 'test-nonce',
            siteUrl: 'http://stream-refusal.example.com',
            streamUrl: '/stream',
            streamNonce: 'stream-nonce',
            pages: [{ id: 1, title: 'Test Page' }]
        };
        global.window.ppAiChat = window.ppAiChat;

        var modulePath = require.resolve('../../assets/js/pp-ai-chat.js');
        delete require.cache[modulePath];
        vi.resetModules();
        require('../../assets/js/pp-ai-chat.js');

        var opt = document.createElement('option');
        opt.value = '1';
        document.getElementById('pp-ai-page-select').appendChild(opt);
        selectPage();
    });

    afterEach(function () {
        vi.clearAllTimers();
        vi.useRealTimers();
        delete global.fetch;
        delete global.TextDecoder;
    });

    function selectPage() {
        var pageSelect = document.getElementById('pp-ai-page-select');
        pageSelect.value = '1';
        pageSelect.dispatchEvent(new window.Event('change'));
    }

    function send(text) {
        document.getElementById('pp-ai-input').value = text;
        document.getElementById('pp-ai-send').click();
    }

    test('chat stream refusal is shown and does not fall back', async function () {
        streamFetchBehavior = function () { return refusal('Insufficient permissions.'); };

        send('tighten the hero copy');
        await flushMicrotasks();

        expect(ajaxCalls.length).toBe(0);
        var body = document.querySelector('.pp-ai-msg-assistant .pp-ai-msg-body');
        expect(body.textContent).toBe('Insufficient permissions.');
        expect(body.classList.contains('pp-ai-msg-error')).toBe(true);
        expect(document.querySelector('.pp-ai-status')).toBeNull(); // no "compatibility mode" note
        expect(document.getElementById('pp-ai-send').disabled).toBe(false);
        expect(document.getElementById('pp-ai-input').disabled).toBe(false);

        // The watchdog was cleared: nothing falls back later either.
        await vi.advanceTimersByTimeAsync(20000);
        expect(ajaxCalls.length).toBe(0);
    });

    test('chat stream refusal with an empty body still reads as a refusal', async function () {
        streamFetchBehavior = function () { return refusal(''); };

        send('tighten the hero copy');
        await flushMicrotasks();

        expect(ajaxCalls.length).toBe(0);
        expect(document.querySelector('.pp-ai-msg-assistant .pp-ai-msg-body').textContent).toBe('Permission denied.');
    });

    test('a refusal whose body cannot be read still reads as a refusal', async function () {
        streamFetchBehavior = function () {
            return response(403, Promise.reject(new Error('aborted')), { 'X-PP-Refusal': '1' });
        };

        send('tighten the hero copy');
        await flushMicrotasks();

        expect(ajaxCalls.length).toBe(0);
        var body = document.querySelector('.pp-ai-msg-assistant .pp-ai-msg-body');
        expect(body.textContent).toBe('Permission denied.');
        expect(body.classList.contains('pp-ai-msg-error')).toBe(true);
        expect(document.getElementById('pp-ai-send').disabled).toBe(false);
    });

    test('a marker other than "1" is not a refusal', async function () {
        streamFetchBehavior = function () {
            return response(403, Promise.resolve('Forbidden'), { 'X-PP-Refusal': '0' });
        };

        send('tighten the hero copy');
        await flushMicrotasks();

        expect(ajaxCalls.length).toBe(1);
    });

    test('a 403 without the refusal marker (a proxy in front of WordPress) still falls back', async function () {
        streamFetchBehavior = function () {
            return response(403, Promise.resolve('<html><body>Access Denied</body></html>'), { 'Content-Type': 'text/html' });
        };

        send('tighten the hero copy');
        await flushMicrotasks();

        expect(ajaxCalls.length).toBe(1);
        expect(document.body.textContent).not.toContain('Access Denied');
    });

    test('a stream server error still falls back to the non-streaming endpoint', async function () {
        streamFetchBehavior = function () { return response(500, Promise.resolve('Internal Server Error')); };

        send('tighten the hero copy');
        await flushMicrotasks();

        expect(ajaxCalls.length).toBe(1);
    });

    test('a refusal that arrives after New Chat does not touch the new conversation', async function () {
        var releaseFirst;
        streamFetchBehavior = function () {
            return response(403, new Promise(function (resolve) { releaseFirst = resolve; }), { 'X-PP-Refusal': '1' });
        };
        send('first question');
        await flushMicrotasks();
        expect(typeof releaseFirst).toBe('function'); // the refusal body is still pending

        document.getElementById('pp-ai-new-chat').click();
        selectPage();
        streamFetchBehavior = function () { return response(200, Promise.resolve('')); }; // streams, never finishes
        send('second question');
        await flushMicrotasks();
        expect(document.getElementById('pp-ai-send').disabled).toBe(true);

        releaseFirst('Insufficient permissions.');
        await flushMicrotasks();

        expect(document.getElementById('pp-ai-send').disabled).toBe(true); // still streaming
        expect(document.body.textContent).not.toContain('Insufficient permissions.');
        expect(ajaxCalls.length).toBe(0);
    });
});
