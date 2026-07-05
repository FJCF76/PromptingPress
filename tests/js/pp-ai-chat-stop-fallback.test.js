/**
 * Tests for Stop/cancel and first-token-watchdog fallback in
 * assets/js/pp-ai-chat.js (issue 139)
 *
 * Covers:
 *   - Clicking Stop mid-stream aborts the network request, finalizes the
 *     partial message, and never falls back to the non-streaming endpoint
 *     (an intentional cancellation is not a failure).
 *   - A stream that never emits a `data:` token (simulated proxy-buffered
 *     200) triggers the non-streaming fallback once the watchdog window
 *     elapses, without the user doing anything.
 *   - The existing streaming happy path (tokens arrive before the watchdog)
 *     is unaffected — no fallback engages, no Stop-related state leaks.
 *
 * global.fetch is mocked per-test so each streamChat() request's timing
 * (when/whether tokens arrive, whether it hangs) is fully controlled.
 */

const { JSDOM } = require('jsdom');

/**
 * Drains the native Promise microtask queue. An abort rejection propagates
 * through several .then()/pump() hops before reaching streamChat's outer
 * .catch — none of them are timer-based, so vi.advanceTimersByTimeAsync
 * alone doesn't guarantee they've all settled.
 */
async function flushMicrotasks() {
    for (var i = 0; i < 20; i++) {
        await Promise.resolve();
    }
}

function encode(str) {
    return new TextEncoder().encode(str);
}

/**
 * A controllable "reader" mimicking ReadableStreamDefaultReader: each call
 * to .read() resolves with the next queued chunk, or hangs forever (never
 * resolves) once the queue is exhausted and `hang` is true — simulating a
 * buffered proxy that never delivers anything after the initial response.
 *
 * Mirrors real fetch semantics: aborting AFTER the response promise has
 * already resolved (headers arrived) doesn't reject that promise — it
 * rejects the next reader.read() call instead. `signal` (optional) wires
 * that up for the hanging case.
 */
function makeReader(chunks, hang, signal) {
    var i = 0;
    return {
        read: function () {
            if (i < chunks.length) {
                var chunk = chunks[i++];
                return Promise.resolve({ done: false, value: encode(chunk) });
            }
            if (hang) {
                return new Promise(function (resolve, reject) {
                    if (signal) {
                        signal.addEventListener('abort', function () {
                            var err = new Error('The operation was aborted.');
                            err.name = 'AbortError';
                            reject(err);
                        });
                    }
                });
            }
            return Promise.resolve({ done: true, value: undefined });
        }
    };
}

describe('Stop button and first-token watchdog fallback (issue 139)', function () {
    var dom, window, document, ajaxCalls, streamFetchBehavior;

    beforeEach(function () {
        vi.useFakeTimers();

        dom = new JSDOM('<!DOCTYPE html><html><body>' +
            '<div id="pp-ai-messages"></div>' +
            '<select id="pp-ai-page-select"></select>' +
            '<textarea id="pp-ai-input"></textarea>' +
            '<button id="pp-ai-send"></button>' +
            '<button id="pp-ai-stop" style="display:none;"></button>' +
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
        streamFetchBehavior = null; // set per-test before sending

        global.fetch = function (url, opts) {
            if (url.indexOf('ai-stream') !== -1 || url === '/stream') {
                return streamFetchBehavior(opts);
            }
            // AJAX fallback endpoint
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
            siteUrl: 'http://stop-fallback.example.com',
            streamUrl: '/stream',
            streamNonce: 'stream-nonce',
            pages: [{ id: 1, title: 'Test Page' }]
        };
        global.window.ppAiChat = window.ppAiChat;

        // vi.resetModules() alone does not clear Node's own CJS require
        // cache for a literal require() call — without this, the IIFE only
        // ever executes once (on the first test in the whole run) and every
        // later test's require() silently returns that first run's already-
        // bound DOM references (stale sendBtn/messagesEl/etc. from an
        // earlier test's now-detached document), so nothing in a later
        // test's fresh DOM ever actually receives its event listeners.
        var modulePath = require.resolve('../../assets/js/pp-ai-chat.js');
        delete require.cache[modulePath];
        vi.resetModules();
        require('../../assets/js/pp-ai-chat.js');

        // issue 136: sending is blocked without an explicit page selection —
        // select one directly rather than relying on localStorage restore
        // (restoreConversation() only restores state for a non-empty saved
        // conversation, which these tests intentionally start without).
        var pageSelect = document.getElementById('pp-ai-page-select');
        var opt = document.createElement('option');
        opt.value = '1';
        pageSelect.appendChild(opt);
        pageSelect.value = '1';
        pageSelect.dispatchEvent(new window.Event('change'));
    });

    afterEach(function () {
        vi.clearAllTimers();
        vi.useRealTimers();
        delete global.fetch;
        delete global.TextDecoder;
    });

    function send(text) {
        document.getElementById('pp-ai-input').value = text;
        document.getElementById('pp-ai-send').click();
    }

    test('Stop button aborts the request and re-enables input without falling back', async function () {
        var abortedController = null;
        streamFetchBehavior = function (opts) {
            abortedController = opts.signal;
            return Promise.resolve({
                ok: true,
                body: { getReader: function () { return makeReader(['data: {"content":"Partial "}\n\n'], true, opts.signal); } }
            });
        };

        send('make the hero bigger');
        await vi.advanceTimersByTimeAsync(0); // let the fetch + first chunk resolve

        expect(document.getElementById('pp-ai-stop').style.display).not.toBe('none');
        var assistantBody = document.querySelector('.pp-ai-msg-assistant .pp-ai-msg-body');
        expect(assistantBody.textContent).toBe('Partial ');

        document.getElementById('pp-ai-stop').click();
        await flushMicrotasks();

        expect(abortedController.aborted).toBe(true);
        expect(document.getElementById('pp-ai-send').disabled).toBe(false);
        expect(document.getElementById('pp-ai-input').disabled).toBe(false);
        expect(document.getElementById('pp-ai-stop').style.display).toBe('none');
        expect(ajaxCalls.length).toBe(0); // an intentional stop is not a failure — no fallback
    });

    test('a stream with no tokens falls back to the AJAX endpoint once the watchdog elapses', async function () {
        streamFetchBehavior = function (opts) {
            // Simulated buffered-200: headers resolve, but no data: line ever
            // arrives — mirrors a proxy holding the whole response.
            return Promise.resolve({
                ok: true,
                body: { getReader: function () { return makeReader([], true, opts.signal); } }
            });
        };

        send('make the hero bigger');
        await flushMicrotasks(); // let the fetch resolve and reader.read() attach its abort listener
        expect(ajaxCalls.length).toBe(0);

        await vi.advanceTimersByTimeAsync(15000);
        await flushMicrotasks();

        expect(ajaxCalls.length).toBe(1);
        expect(document.body.textContent).toContain('compatibility mode');
    });

    test('happy path with tokens arriving well before the watchdog never falls back', async function () {
        streamFetchBehavior = function (opts) {
            return Promise.resolve({
                ok: true,
                body: {
                    getReader: function () {
                        return makeReader([
                            'data: {"content":"Hello"}\n\n',
                            'data: {"done":true}\n\n'
                        ], false);
                    }
                }
            });
        };

        send('make the hero bigger');
        await vi.advanceTimersByTimeAsync(0);

        // Advance well past the watchdog window — since the stream already
        // finished (reader signalled done), no fallback should ever fire.
        await vi.advanceTimersByTimeAsync(20000);

        expect(ajaxCalls.length).toBe(0);
        expect(document.getElementById('pp-ai-stop').style.display).toBe('none');
        expect(document.getElementById('pp-ai-send').disabled).toBe(false);
    });
});
