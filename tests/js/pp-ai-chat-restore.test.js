/**
 * Tests for restoreConversation() in assets/js/pp-ai-chat.js (#140)
 *
 * Internal apply-confirmation assistant messages (marked with `internal: true`)
 * must never render as visible chat bubbles on reload, regardless of which of
 * the four confirmation shapes they are (success, success+stale-note, warnings,
 * validation failure). A genuine assistant message must still render even if
 * its text happens to start with "Changes applied".
 *
 * restoreConversation() runs automatically inside the module's top-level IIFE
 * at require() time, so localStorage must be seeded BEFORE requiring the
 * module — there is no exported hook to call it again per-test.
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
    siteUrl: 'http://restore.example.com',
    streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
    streamNonce: 'stream-nonce'
};
global.window.ppAiChat = dom.window.ppAiChat;

// Distinct siteUrl (and therefore STORAGE_KEY) from the other test files that
// touch localStorage — this file is the first to seed nontrivial pre-existing
// conversation content, so a shared key risks cross-file bleed if vitest's
// per-file isolation is ever disabled for a CI speed optimization (#140
// adversarial review finding).
const STORAGE_KEY = 'pp_ai_chat_' + dom.window.ppAiChat.siteUrl;

const conversation = [
    { role: 'user', content: 'Add a hero section' },
    { role: 'assistant', content: 'Changes applied successfully.', internal: true },
    { role: 'assistant', content: 'Changes applied successfully. Note: some existing token overrides may not match the new palette: --accent. These were kept as-is — update them if the visual result looks inconsistent.', internal: true },
    { role: 'assistant', content: 'Changes applied with warnings: missing alt text', internal: true },
    { role: 'assistant', content: 'Changes applied but rendered page validation failed: broken image. The page may still have broken images or missing content.', internal: true },
    { role: 'assistant', content: 'Changes applied to your resume, nice work on the new job!' },
    { role: 'user', content: '[Applied changes: hero updated]' },
];

localStorage.setItem(STORAGE_KEY, JSON.stringify({ conversation: conversation, activePageId: null }));

require('../../assets/js/pp-ai-chat.js');

describe('restoreConversation (#140)', function () {
    function getAssistantBodies() {
        return Array.prototype.slice.call(
            document.querySelectorAll('#pp-ai-messages .pp-ai-msg-assistant .pp-ai-msg-body')
        ).map(function (el) { return el.textContent; });
    }

    test('suppresses all four internal apply-confirmation shapes', function () {
        var bodies = getAssistantBodies();

        expect(bodies).not.toContain('Changes applied successfully.');
        expect(bodies.some(function (t) { return t.indexOf('Note: some existing token overrides') !== -1; })).toBe(false);
        expect(bodies.some(function (t) { return t.indexOf('Changes applied with warnings') !== -1; })).toBe(false);
        expect(bodies.some(function (t) { return t.indexOf('rendered page validation failed') !== -1; })).toBe(false);
    });

    test('does not suppress a genuine assistant message starting with "Changes applied"', function () {
        expect(getAssistantBodies().some(function (t) { return t.indexOf('nice work on the new job') !== -1; })).toBe(true);
    });

    test('renders exactly one assistant bubble (the non-internal one)', function () {
        var assistantMsgs = document.querySelectorAll('#pp-ai-messages .pp-ai-msg-assistant');
        expect(assistantMsgs.length).toBe(1);
    });

    test('still skips bracket-prefixed internal user messages (pre-existing behavior)', function () {
        var userMsgs = document.querySelectorAll('#pp-ai-messages .pp-ai-msg-user .pp-ai-msg-body');
        var texts = Array.prototype.slice.call(userMsgs).map(function (el) { return el.textContent; });
        expect(texts).toEqual(['Add a hero section']);
    });

    test('internal messages remain in conversation context for the next model turn', function () {
        // sendMessage() synchronously pushes the new user message and calls
        // saveState() before the (mocked, unresolved-in-this-test) fetch even
        // starts — so the persisted conversation reflects the full history
        // the next request will send, without needing to mock the SSE reader.
        document.getElementById('pp-ai-input').value = 'Now add a footer too';
        document.getElementById('pp-ai-send').click();

        var persisted = JSON.parse(localStorage.getItem(STORAGE_KEY));
        var internalCount = persisted.conversation.filter(function (m) { return m.internal === true; }).length;

        expect(internalCount).toBe(4);
        expect(persisted.conversation[persisted.conversation.length - 1]).toEqual(
            { role: 'user', content: 'Now add a footer too' }
        );
    });
});
