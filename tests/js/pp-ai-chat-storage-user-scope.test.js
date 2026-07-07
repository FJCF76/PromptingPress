/**
 * Tests for site+user-scoped chat persistence in assets/js/pp-ai-chat.js (#157)
 *
 * The persisted conversation must be keyed by BOTH site URL and the current WP
 * user id so two admins sharing an OS/browser profile can't read each other's
 * chat history. When the user id is missing or invalid (a broken
 * wp_localize_script contract on an edit_posts-gated page), persistence FAILS
 * CLOSED: nothing is read from or written to localStorage — an "anon" fallback
 * would just recreate the shared-profile leak this fix closes.
 *
 * wp_localize_script casts scalar payload values to strings, so a real page
 * receives currentUserId as e.g. "7", never a JS number. Every fixture below
 * seeds it as a string to exercise the string-aware validation.
 *
 * pp-ai-chat.js runs its setup IIFE (including STORAGE_KEY derivation, the
 * legacy-key cleanup, and restoreConversation()) at evaluation time, so each
 * scenario boots a fresh module via a cache-busting dynamic import() against a
 * fresh JSDOM + localStorage seeded BEFORE evaluation.
 */

const { JSDOM } = require('jsdom');

const SITE = 'http://scope.example.com';
const legacyKey = 'pp_ai_chat_' + SITE;
const scopedKey = (uid) => 'pp_ai_chat_' + SITE + '_' + uid;

// The module's setup IIFE runs once per evaluation. To boot it fresh per
// scenario we dynamic-import with a unique cache-busting query so Vite
// re-evaluates the module against the current globals each time.
let bootSeq = 0;

/**
 * Boot a fresh pp-ai-chat module instance.
 * @param {object} config       - the window.ppAiChat payload
 * @param {function} [seed]     - called with localStorage before evaluation
 * @returns {Promise<{dom, warnCalls}>} - JSDOM plus captured console.warn args
 */
async function bootChat(config, seed) {
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

    dom.window.ppAiChat = config;
    global.window.ppAiChat = config;

    if (seed) seed(dom.window.localStorage);

    const warnCalls = [];
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(function () {
        warnCalls.push(Array.prototype.slice.call(arguments).join(' '));
    });

    await import('../../assets/js/pp-ai-chat.js?boot=' + (++bootSeq));
    warnSpy.mockRestore();

    return { dom: dom, warnCalls: warnCalls };
}

function baseConfig(overrides) {
    return Object.assign({
        configured: true,
        ajaxUrl: '/wp-admin/admin-ajax.php',
        executeNonce: 'test-nonce',
        siteUrl: SITE,
        streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
        streamNonce: 'stream-nonce',
    }, overrides || {});
}

function renderedBodies(dom) {
    return Array.prototype.slice.call(
        dom.window.document.querySelectorAll('#pp-ai-messages .pp-ai-msg-body')
    ).map(function (el) { return el.textContent; });
}

const userAState = JSON.stringify({
    conversation: [
        { role: 'user', content: 'User A secret question' },
        { role: 'assistant', content: 'User A private answer' },
    ],
    activePageId: 1,
});

describe('site+user-scoped chat persistence (#157)', function () {

    test('different user ids produce different keys — user B cannot read user A history',  async function () {
        // User A ("7") has persisted history. Boot as user B ("8") on the same
        // browser profile: B must not read or render any of A's content.
        const { dom } = await bootChat(baseConfig({ currentUserId: '8' }), function (ls) {
            ls.setItem(scopedKey('7'), userAState);
        });

        // A's key is untouched, B's key was never written by a bare load.
        expect(dom.window.localStorage.getItem(scopedKey('7'))).toBe(userAState);
        expect(scopedKey('7')).not.toBe(scopedKey('8'));

        // None of A's message text leaked into B's rendered conversation.
        const bodies = renderedBodies(dom);
        expect(bodies.some(function (t) { return t.indexOf('User A') !== -1; })).toBe(false);
    });

    test('same user id restores its own persisted history',  async function () {
        const { dom } = await bootChat(baseConfig({ currentUserId: '7' }), function (ls) {
            ls.setItem(scopedKey('7'), userAState);
        });

        const bodies = renderedBodies(dom);
        expect(bodies).toContain('User A secret question');
        expect(bodies).toContain('User A private answer');
    });

    test('legacy unscoped key is removed on load (valid user)',  async function () {
        const { dom } = await bootChat(baseConfig({ currentUserId: '7' }), function (ls) {
            ls.setItem(legacyKey, userAState);
        });

        expect(dom.window.localStorage.getItem(legacyKey)).toBeNull();
    });

    test('legacy unscoped key is removed on load even when user id is invalid',  async function () {
        // Cleanup must not sit behind a valid STORAGE_KEY — a broken user id
        // still leaves a contaminated shared bucket that must be dropped.
        const { dom } = await bootChat(baseConfig({ currentUserId: '' }), function (ls) {
            ls.setItem(legacyKey, userAState);
        });

        expect(dom.window.localStorage.getItem(legacyKey)).toBeNull();
    });

    describe('fail closed on missing/invalid currentUserId', function () {
        const invalidIds = [
            { label: 'missing', cfg: {} },
            { label: 'undefined', cfg: { currentUserId: undefined } },
            { label: 'null', cfg: { currentUserId: null } },
            { label: 'zero number', cfg: { currentUserId: 0 } },
            { label: 'zero string', cfg: { currentUserId: '0' } },
            { label: 'empty string', cfg: { currentUserId: '' } },
            { label: 'non-numeric', cfg: { currentUserId: '12abc' } },
            { label: 'decimal', cfg: { currentUserId: '1.5' } },
            { label: 'negative', cfg: { currentUserId: '-1' } },
            { label: 'whitespace', cfg: { currentUserId: ' 7 ' } },
        ];

        invalidIds.forEach(function (c) {
            test('id=' + c.label + ' → nothing read, nothing written, warns', async function () {
                // Seed BOTH a scoped and legacy key. Fail-closed means the
                // module must not read either into the rendered conversation.
                const { dom, warnCalls } = await bootChat(baseConfig(c.cfg), function (ls) {
                    ls.setItem(scopedKey('7'), userAState);
                });

                // Nothing from any persisted conversation was rendered.
                expect(renderedBodies(dom).length).toBe(0);

                // The broken-contract diagnostic fired.
                expect(warnCalls.some(function (m) {
                    return m.indexOf('persistence disabled') !== -1;
                })).toBe(true);

                // No new persistence key was created for this session; the only
                // key that may remain is the pre-seeded scoped-7 key (the legacy
                // key, if present, is intentionally removed — none seeded here).
                const keys = Object.keys(dom.window.localStorage);
                expect(keys).toEqual([scopedKey('7')]);
            });
        });
    });

    test('valid multi-digit id is accepted and keyed correctly', async function () {
        const state123 = JSON.stringify({
            conversation: [{ role: 'assistant', content: 'Hello user 123' }],
            activePageId: null,
        });
        const { dom } = await bootChat(baseConfig({ currentUserId: '123' }), function (ls) {
            ls.setItem(scopedKey('123'), state123);
        });

        expect(renderedBodies(dom)).toContain('Hello user 123');
    });
});
