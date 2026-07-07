/**
 * Tests for activePageId numeric normalization in assets/js/pp-ai-chat.js
 * (v0.16.48 release-readiness audit).
 *
 * Two coercion boundaries changed alongside the ppChatFindPageById numeric
 * comparison, and both modify pre-existing behavior:
 *   - restoreConversation(): a pre-normalization localStorage state stored
 *     the <select>'s STRING value — it must be migrated to a number and the
 *     persisted page selection must survive the reload (the original bug).
 *   - the page <select>'s change handler: this.value is a string; the
 *     handler must persist activePageId as a number (or null when cleared).
 *
 * restoreConversation() runs inside the module's top-level IIFE at require()
 * time, so localStorage is seeded BEFORE requiring the module — same pattern
 * as pp-ai-chat-restore.test.js.
 */

const { JSDOM } = require('jsdom');
const dom = new JSDOM('<!DOCTYPE html><html><body>' +
    '<div id="pp-ai-messages"></div>' +
    '<textarea id="pp-ai-input"></textarea>' +
    '<button id="pp-ai-send"></button>' +
    '<select id="pp-ai-page-select">' +
    '<option value=""></option>' +
    '<option value="1">About</option>' +
    '<option value="3">Pricing</option>' +
    '</select>' +
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
    siteUrl: 'http://page-select-coercion.example.com',
    currentUserId: '7', // string, matching wp_localize_script scalar casting (#157)
    streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
    streamNonce: 'stream-nonce',
    pages: [
        { id: 1, title: 'About' },
        { id: 3, title: 'Pricing' },
    ]
};
global.window.ppAiChat = dom.window.ppAiChat;

// Unique STORAGE_KEY per file (see pp-ai-chat-restore.test.js on cross-file
// localStorage bleed).
const STORAGE_KEY = 'pp_ai_chat_' + dom.window.ppAiChat.siteUrl + '_' + dom.window.ppAiChat.currentUserId;

// Pre-normalization state: activePageId persisted as the <select>'s string
// value. The old strict === lookup in ppChatFindPageById never matched it,
// so syncPageSelectValue() reset the selection to '' on every reload.
localStorage.setItem(STORAGE_KEY, JSON.stringify({
    conversation: [{ role: 'user', content: 'Make the pricing page bolder' }],
    activePageId: '3',
}));

require('../../assets/js/pp-ai-chat.js');

describe('activePageId select-boundary coercion', function () {
    test('restores a pre-normalization string activePageId and persists numeric ids from the select', function () {
        // Restore path: the string '3' from old localStorage must survive
        // syncPageSelectValue() (previously it was dropped as "stale").
        var select = document.getElementById('pp-ai-page-select');
        expect(select.value).toBe('3');

        // Change-handler path: picking a page stores a NUMBER, not the
        // select's string value.
        select.value = '1';
        select.dispatchEvent(new dom.window.Event('change'));
        var persisted = JSON.parse(localStorage.getItem(STORAGE_KEY));
        expect(persisted.activePageId).toBe(1);

        // Clearing the selection stores null, not '' coerced to 0.
        select.value = '';
        select.dispatchEvent(new dom.window.Event('change'));
        persisted = JSON.parse(localStorage.getItem(STORAGE_KEY));
        expect(persisted.activePageId).toBeNull();
    });
});
