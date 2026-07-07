/**
 * Tests for restoreConversation() page-selection restore with an EMPTY
 * conversation (v0.16.48 pre-landing review).
 *
 * A page picked in the <select> before the first message is persisted as
 * {conversation: [], activePageId: N}. restoreConversation() used to bail on
 * the empty conversation BEFORE reading activePageId, so the selection was
 * silently dropped on reload. The selection restore is now hoisted above the
 * empty-conversation early return.
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
    siteUrl: 'http://restore-empty-selection.example.com',
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

// The select-then-reload-before-first-message shape: page picked, no
// conversation yet. Stored as a string to also cover pre-normalization
// states in the same pass.
localStorage.setItem(STORAGE_KEY, JSON.stringify({
    conversation: [],
    activePageId: '3'
}));

require('../../assets/js/pp-ai-chat.js');

describe('restoreConversation with an empty conversation', () => {
    test('restores the persisted page selection into the select', () => {
        const select = document.getElementById('pp-ai-page-select');
        expect(select.value).toBe('3');
    });

    test('renders no messages for the empty conversation', () => {
        const messages = document.getElementById('pp-ai-messages');
        expect(messages.children.length).toBe(0);
    });
});
