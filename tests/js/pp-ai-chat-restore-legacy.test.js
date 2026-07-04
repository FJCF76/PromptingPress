/**
 * Regression test for #140: localStorage conversations saved BEFORE this fix
 * have no `internal` key at all on their apply-confirmation assistant messages.
 * Cross-model adversarial review (Codex + Testing specialist, independently)
 * found that a naive `msg.internal === true` check alone would un-hide the one
 * shape ("Changes applied successfully." with no stale suffix) that the OLD
 * exact-string-match code correctly suppressed — a regression on upgrade for
 * existing users. restoreConversation() must fall back to the legacy exact
 * match for messages that predate the `internal` flag.
 *
 * Kept in its own file (separate module instance) because restoreConversation()
 * runs once, automatically, inside the top-level IIFE at require() time.
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

const STORAGE_KEY = 'pp_ai_chat_' + dom.window.ppAiChat.siteUrl;

// Pre-fix shape: no `internal` key anywhere — this is what's actually sitting
// in real users' browsers the moment this fix ships.
const legacyConversation = [
    { role: 'user', content: 'Add a hero section' },
    { role: 'assistant', content: 'Changes applied successfully.' },
    { role: 'assistant', content: 'Changes applied with warnings: missing alt text' },
];

localStorage.setItem(STORAGE_KEY, JSON.stringify({ conversation: legacyConversation, activePageId: null }));

require('../../assets/js/pp-ai-chat.js');

describe('restoreConversation legacy fallback (#140)', function () {
    test('still hides the bare success message with no internal flag (pre-fix behavior preserved)', function () {
        var bodies = Array.prototype.slice.call(
            document.querySelectorAll('#pp-ai-messages .pp-ai-msg-assistant .pp-ai-msg-body')
        ).map(function (el) { return el.textContent; });

        expect(bodies).not.toContain('Changes applied successfully.');
    });

    test('the warnings shape without internal flag still leaks (pre-existing gap, not a new regression)', function () {
        // This is the SAME gap the original bug report described for legacy
        // data — it only closes going forward for newly-created messages
        // (which always carry internal: true). Documenting it here so a
        // future change to this fallback logic doesn't silently reintroduce
        // the opposite regression without anyone noticing the tradeoff.
        var bodies = Array.prototype.slice.call(
            document.querySelectorAll('#pp-ai-messages .pp-ai-msg-assistant .pp-ai-msg-body')
        ).map(function (el) { return el.textContent; });

        expect(bodies.some(function (t) { return t.indexOf('Changes applied with warnings') !== -1; })).toBe(true);
    });
});
