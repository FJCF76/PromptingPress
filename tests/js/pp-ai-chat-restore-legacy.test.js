/**
 * Regression test for #140: localStorage conversations saved BEFORE this fix
 * have no `internal` key at all on their apply-confirmation assistant messages.
 * Cross-model adversarial review (Codex, the Testing specialist, and a Claude
 * adversarial subagent, all independently) found that a naive
 * `msg.internal === true` check alone would un-hide EVERY pre-fix confirmation
 * shape for existing users on upgrade — reproducing issue #140 for exactly the
 * conversations already sitting in their browsers. restoreConversation() falls
 * back to matching the three known legacy content prefixes for messages that
 * predate the `internal` flag (see LEGACY_INTERNAL_PREFIXES in pp-ai-chat.js).
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
    siteUrl: 'http://legacy.example.com',
    streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
    streamNonce: 'stream-nonce'
};
global.window.ppAiChat = dom.window.ppAiChat;

const STORAGE_KEY = 'pp_ai_chat_' + dom.window.ppAiChat.siteUrl;

// Pre-fix shape: no `internal` key anywhere — this is what's actually sitting
// in real users' browsers the moment this fix ships. Also includes a
// malformed entry (null content) to exercise the defensive guard added
// alongside the fallback.
const legacyConversation = [
    { role: 'user', content: 'Add a hero section' },
    { role: 'assistant', content: 'Changes applied successfully.' },
    { role: 'assistant', content: 'Changes applied successfully. Note: some existing token overrides may not match the new palette: --accent.' },
    { role: 'assistant', content: 'Changes applied with warnings: missing alt text' },
    { role: 'assistant', content: 'Changes applied but rendered page validation failed: broken image.' },
    { role: 'assistant', content: null },
    { role: 'assistant', content: 'Great, all set!' },
];

localStorage.setItem(STORAGE_KEY, JSON.stringify({ conversation: legacyConversation, activePageId: null }));

require('../../assets/js/pp-ai-chat.js');

describe('restoreConversation legacy fallback (#140)', function () {
    function getAssistantBodies() {
        return Array.prototype.slice.call(
            document.querySelectorAll('#pp-ai-messages .pp-ai-msg-assistant .pp-ai-msg-body')
        ).map(function (el) { return el.textContent; });
    }

    test('hides all three legacy confirmation shapes with no internal flag', function () {
        var bodies = getAssistantBodies();

        expect(bodies).not.toContain('Changes applied successfully.');
        expect(bodies.some(function (t) { return t.indexOf('Note: some existing token overrides') !== -1; })).toBe(false);
        expect(bodies.some(function (t) { return t.indexOf('Changes applied with warnings') !== -1; })).toBe(false);
        expect(bodies.some(function (t) { return t.indexOf('rendered page validation failed') !== -1; })).toBe(false);
    });

    test('does not crash on a malformed entry and still renders a genuine message', function () {
        // The null-content entry must not throw inside the top-level IIFE —
        // if it did, the whole widget (including this genuine message) would
        // never render at all.
        expect(getAssistantBodies()).toContain('Great, all set!');
    });
});
