/**
 * Tests for page-targeting helpers in assets/js/pp-ai-chat.js (issue 136)
 *
 * Covers the pure functions backing the explicit page selector:
 *   detectPageId            — longest-substring title match, pure (no side effects)
 *   findPageById            — page lookup by id
 *   shouldSuggestPageSwitch — detection-vs-active-selection disagreement
 *
 * These replace the old detectPageId(messages), which read window state
 * directly and doubled as the mechanism that silently reassigned the active
 * page — the bug this issue fixes. The new functions are pure: callers decide
 * what to do with the result, and nothing here ever mutates page selection.
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
    siteUrl: 'http://page-target.example.com',
    streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
    streamNonce: 'stream-nonce'
};
global.window.ppAiChat = dom.window.ppAiChat;

const {
    detectPageId,
    findPageById,
    shouldSuggestPageSwitch,
} = require('../../assets/js/pp-ai-chat.js');

const pages = [
    { id: 1, title: 'About' },
    { id: 2, title: 'About the Team' },
    { id: 3, title: 'Pricing' },
    { id: 4, title: '' }, // untitled page — must never match
];

// ─── detectPageId ──────────────────────────────────────────────────────────

describe('detectPageId', function () {
    test('matches a page whose title is a substring of the text', function () {
        expect(detectPageId('please make the pricing page bolder', pages)).toBe(3);
    });

    test('picks the longest matching title on substring collision', function () {
        // "About" is a substring of "About the Team" — the longer, more
        // specific title must win, not whichever page comes first in the list.
        expect(detectPageId('tell me about the team roster', pages)).toBe(2);
    });

    test('shorter title still matches when the longer one is absent', function () {
        expect(detectPageId('what is this page about anyway', pages)).toBe(1);
    });

    test('returns null when no page title appears in the text', function () {
        expect(detectPageId('make the hero bigger', pages)).toBeNull();
    });

    test('returns null for empty text', function () {
        expect(detectPageId('', pages)).toBeNull();
    });

    test('returns null for an empty pages list', function () {
        expect(detectPageId('about pricing', [])).toBeNull();
    });

    test('returns null when pages is not provided', function () {
        expect(detectPageId('about pricing', null)).toBeNull();
    });

    test('never matches an untitled page', function () {
        // A page with an empty title must not turn every message into a match.
        expect(detectPageId('anything at all', pages)).toBeNull();
    });

    test('title comparison is case-insensitive even when the text is already lowercased', function () {
        // Contract: the caller pre-lowercases text (matches sendMessage's
        // usage — detectPageId itself does not lowercase its text argument),
        // but title comparison always lowercases the title side regardless
        // of how it's cased in config.pages.
        var mixedCaseTitlePages = [{ id: 3, title: 'PRICING' }];
        expect(detectPageId('the pricing page please', mixedCaseTitlePages)).toBe(3);
    });
});

// ─── findPageById ──────────────────────────────────────────────────────────

describe('findPageById', function () {
    test('finds a page by id', function () {
        expect(findPageById(3, pages)).toEqual({ id: 3, title: 'Pricing' });
    });

    test('returns null for an id not in the list', function () {
        expect(findPageById(999, pages)).toBeNull();
    });

    test('returns null for a null id', function () {
        expect(findPageById(null, pages)).toBeNull();
    });

    test('returns null when pages is not provided', function () {
        expect(findPageById(1, null)).toBeNull();
    });

    // Regression: config.pages ids are ints from PHP, but a pageId sourced
    // from the <select>'s value or a pre-normalization localStorage state is
    // a string. The old strict === lookup never matched those, dropping the
    // persisted selection on reload and hiding the proposal card's
    // "Target page:" label.
    test('finds a page when the id is the string form of a numeric id', function () {
        expect(findPageById('3', pages)).toEqual({ id: 3, title: 'Pricing' });
    });

    test('finds a page when the list ids are strings and the lookup id is a number', function () {
        expect(findPageById(2, [{ id: '2', title: 'About the Team' }]))
            .toEqual({ id: '2', title: 'About the Team' });
    });

    test('returns null for a string id not in the list', function () {
        expect(findPageById('999', pages)).toBeNull();
    });
});

// ─── shouldSuggestPageSwitch ────────────────────────────────────────────────

describe('shouldSuggestPageSwitch', function () {
    test('suggests a switch when detection disagrees with the active page', function () {
        expect(shouldSuggestPageSwitch(1, 3)).toBe(true);
    });

    test('suggests a switch when nothing is active but something was detected', function () {
        expect(shouldSuggestPageSwitch(null, 3)).toBe(true);
    });

    test('does not suggest when detection agrees with the active page', function () {
        expect(shouldSuggestPageSwitch(3, 3)).toBe(false);
    });

    test('does not suggest when nothing was detected', function () {
        expect(shouldSuggestPageSwitch(1, null)).toBe(false);
    });

    test('does not suggest when neither is set', function () {
        expect(shouldSuggestPageSwitch(null, null)).toBe(false);
    });
});
