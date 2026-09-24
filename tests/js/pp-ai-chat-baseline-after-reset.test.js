/**
 * A baseline read that lands after New Chat never enters the next conversation (#909).
 *
 * THE DEFECT. `resetChat()` empties `pageBaselines` and clears the stored state, but three
 * producers write a CAS baseline from inside an async arm that outlives the conversation
 * that started it:
 *
 *   producer 1  refreshBaseline()            — the Re-read & re-preview affordance (#880)
 *   producer 2  refreshTouchedBaselines()    — a best-effort re-read per page after a
 *                                              non-conflict rolled-back batch
 *   producer 3  the Undo link's success arm  — writes the POST-RESTORE version, i.e. exactly
 *                                              the current server state, and can be clicked
 *                                              arbitrarily long after its card was drawn
 *
 * Each late write lands in the NEW map `resetChat()` just installed and re-creates the
 * localStorage key `clearState()` removed. The fresh conversation then holds a baseline for
 * a page it never read — and a baseline is the WRITE TICKET the #404 fail-closed gate exists
 * to withhold: `_pp_ai_batch_baselines_cover_mutations()` refuses a mutating batch whose page
 * has none, so the residue turns a server REFUSAL into a server ACCEPT. Persisted, it
 * survives the tab.
 *
 * THE FIX IS IN THE SHARED WRITERS, NOT AT THE CALL SITES, because the write happens inside
 * refreshBaseline()'s own `.then` — upstream of anything a caller could guard. Keyed on
 * `currentConversationId`, the generation #880 introduced for exactly this shape (an
 * affordance that outlives its request), captured when the read STARTS.
 *
 * WHAT IS PINNED, BY KIND:
 *
 *   RED-PROOF (fails against the pre-fix source)
 *     1a/2a/3a  the late write leaves the persisted state free of the old page's baseline
 *     1b        the next conversation's batch carries NO baseline for a page it never read —
 *               the consent gate's own input, asserted on the outgoing request
 *   PRESERVATION (passes before and after)
 *     1c/2b/3b  with no New Chat in between, each producer still stores its baseline
 *     4         no producer leaves an unhandled rejection behind when it is abandoned
 *
 * OUT OF SCOPE, SAID ONCE: executeProposal()'s own response chain (#910) and
 * renderProposal()'s preview chain (#787) are sibling issues with their own pins.
 */

const { JSDOM } = require('jsdom');

const dom = new JSDOM('<!DOCTYPE html><html><body>' +
    '<div id="pp-ai-messages"></div>' +
    '<textarea id="pp-ai-input"></textarea>' +
    '<button id="pp-ai-send"></button>' +
    '<button id="pp-ai-new-chat"></button>' +
    '<select id="pp-ai-page-select">' +
    '<option value=""></option><option value="42">Landing</option>' +
    '</select>' +
    '</body></html>', { url: 'http://localhost' });

global.window = dom.window;
global.document = dom.window.document;
global.HTMLElement = dom.window.HTMLElement;
global.localStorage = dom.window.localStorage;
global.FormData = dom.window.FormData;
global.AbortController = dom.window.AbortController;

const SITE_URL = 'http://baseline-reset.example.com';
const USER_ID = '12';
const PAGE_ID = 42;
const STORAGE_KEY = 'pp_ai_chat_' + SITE_URL + '_' + USER_ID;

dom.window.ppAiChat = {
    configured: true,
    ajaxUrl: '/wp-admin/admin-ajax.php',
    executeNonce: 'test-nonce',
    siteUrl: SITE_URL,
    currentUserId: USER_ID,
    streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
    streamNonce: 'stream-nonce',
    pages: [{ id: PAGE_ID, title: 'Landing' }],
    impact_warnings: {}
};
global.window.ppAiChat = dom.window.ppAiChat;

// ─── Fixtures ────────────────────────────────────────────────────────────────

const PROPOSED_STEPS = [
    {
        type: 'action',
        name: 'update_component',
        description: 'Update the hero title',
        params: { post_id: PAGE_ID, component_index: 0, props: { title: 'New' } }
    },
    {
        type: 'action',
        name: 'update_component',
        description: 'Update the hero eyebrow',
        params: { post_id: PAGE_ID, component_index: 0, props: { eyebrow: 'Hi' } }
    }
];

/** Pre-execution conflict: opens the Re-read affordance (producer 1). */
const PRE_EXEC_CONFLICT = {
    error: 'This page changed since you last read it.',
    error_code: 'composition_conflict',
    expected_version: 3,
    current_version: 4
};

/**
 * A NON-conflict failure at step 2 with a rollback: the exit that calls
 * refreshTouchedBaselines() (producer 2). Shaped as pp_ai_execute_batch() returns it.
 */
const ROLLED_BACK = {
    ok: false,
    steps: [
        { ok: true, action: 'update_component' },
        { ok: false, action: 'update_component', error: 'Unknown prop.', error_code: 'unknown_prop' }
    ],
    failed_at: 1,
    rolled_back: true,
    rollback_errors: [],
    versions: {}
};

/** A successful batch: draws the post-apply card with its Undo link (producer 3). */
const APPLIED = {
    ok: true,
    steps: [
        { ok: true, action: 'update_component' },
        { ok: true, action: 'update_component' }
    ],
    failed_at: null,
    rolled_back: false,
    rollback_errors: [],
    versions: { 42: 5 }
};

// ─── The seam ────────────────────────────────────────────────────────────────

/** What the next pp_ai_execute_batch answers with. */
let batchResponse = APPLIED;
/** Whether a chat turn hands back a page baseline (the new conversation's own read). */
let chatCarriesBaseline = true;
/** Every batch request's `baselines` field, in order. */
let batchBaselines = [];
/** Held-open responses, keyed by what they answer. */
let gates = {};

function jsonResponse(payload) {
    return { ok: true, json: function () { return Promise.resolve(payload); } };
}
function jsonOk(payload) {
    return Promise.resolve(jsonResponse(payload));
}
function held(name, payload) {
    return new Promise(function (resolve) {
        gates[name] = gates[name] || [];
        gates[name].push({
            settle: function (override) { resolve(jsonResponse(override || payload)); }
        });
    });
}

global.fetch = function (url, opts) {
    if (url === dom.window.ppAiChat.streamUrl) return Promise.reject(new Error('no stream here'));

    const body = opts && opts.body;
    const action = body && typeof body.get === 'function' ? body.get('action') : 'chat-fallback';

    if (action === 'pp_ai_preview') {
        return jsonOk({ success: true, data: { changes: [{ path: 'props.title', from: 'Old', to: 'New' }] } });
    }
    if (action === 'pp_ai_execute_batch') {
        batchBaselines.push(JSON.parse(body.get('baselines')));
        return jsonOk(batchResponse === PRE_EXEC_CONFLICT
            ? { success: false, data: PRE_EXEC_CONFLICT }
            : { success: true, data: batchResponse });
    }
    if (action === 'pp_ai_page_baseline') {
        return held('baseline', { success: true, data: { post_id: PAGE_ID, version: 7 } });
    }
    if (action === 'pp_ai_execute') {
        // The Undo link's restore_composition.
        return held('undo', { success: true, data: { composition_version: 9, findings: [] } });
    }
    if (action === 'pp_ai_chat') {
        const data = {
            content: 'Here is my proposal.',
            proposal: { proposal: true, steps: PROPOSED_STEPS }
        };
        if (chatCarriesBaseline) data.page_baseline = { post_id: PAGE_ID, version: 3 };
        return jsonOk({ success: true, data: data });
    }
    throw new Error('unexpected action in fetch mock: ' + action);
};

// Unhandled rejections are collected rather than left to crash the worker, so pin 4 can
// assert on them by name.
const unhandled = [];
process.on('unhandledRejection', function (reason) { unhandled.push(reason); });

require('../../assets/js/pp-ai-chat.js');

// ─── Harness ─────────────────────────────────────────────────────────────────

const messagesEl = document.getElementById('pp-ai-messages');
const inputEl = document.getElementById('pp-ai-input');
const sendBtn = document.getElementById('pp-ai-send');
const newChatBtn = document.getElementById('pp-ai-new-chat');
const pageSelectEl = document.getElementById('pp-ai-page-select');

async function settle() {
    for (let i = 0; i < 60; i++) await Promise.resolve();
}
function click(el) {
    el.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
}
async function send(text) {
    inputEl.value = text;
    click(sendBtn);
    await settle();
}
function newChat() {
    click(newChatBtn);
    pageSelectEl.value = String(PAGE_ID);
    pageSelectEl.dispatchEvent(new dom.window.Event('change'));
}
/** The persisted baseline map, or {} when nothing is stored. */
function storedBaselines() {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return {};
    const state = JSON.parse(raw);
    return state.pageBaselines || {};
}
/** Proposes and applies once; returns the settled card. */
async function proposeAndApply(response) {
    batchResponse = response;
    await send('Change the hero');
    const cards = messagesEl.querySelectorAll('.pp-ai-proposal-card');
    const card = cards[cards.length - 1];
    const applyBtn = card.querySelector('.pp-ai-proposal-apply');
    expect(applyBtn).not.toBeNull();
    click(applyBtn);
    await settle();
    return card;
}
function settleAll(name, override) {
    const open = gates[name] || [];
    expect(open.length).toBeGreaterThan(0); // the read really was in flight
    gates[name] = [];
    open.forEach(function (g) { g.settle(override); });
}

beforeEach(function () {
    batchResponse = APPLIED;
    chatCarriesBaseline = true;
    batchBaselines = [];
    gates = {};
    unhandled.length = 0;
    newChat();
    expect(storedBaselines()).toEqual({}); // premise: every test opens on a clean conversation
});

// ─── Producer 1: the Re-read affordance ──────────────────────────────────────

describe('producer 1: refreshBaseline() behind Re-read & re-preview', function () {
    async function startReread() {
        const card = await proposeAndApply(PRE_EXEC_CONFLICT);
        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(true);
        const btn = card.querySelector('.pp-ai-proposal-actions button');
        expect(btn.textContent).toBe('Re-read & re-preview');
        click(btn);
        return card;
    }

    test('1a: a read landing after New Chat persists no baseline for the old page', async function () {
        await startReread();
        newChat();
        expect(storedBaselines()).toEqual({});

        settleAll('baseline');
        await settle();

        expect(storedBaselines()).toEqual({});
    });

    test('1b: the next conversation hands the server no baseline for a page it never read', async function () {
        await startReread();
        newChat();
        settleAll('baseline');
        await settle();

        // The new conversation proposes against page 42 WITHOUT reading it: its chat turn
        // carries no page_baseline. The batch's baselines are the consent gate's input, and
        // an empty map is what makes the server refuse (missing_expected_version).
        chatCarriesBaseline = false;
        batchBaselines = [];
        await proposeAndApply(APPLIED);

        expect(batchBaselines.length).toBe(1);
        expect(batchBaselines[0]).toEqual({});
    });

    test('1c: with no New Chat, the re-read still stores what it read', async function () {
        await startReread();
        settleAll('baseline');
        await settle();

        expect(storedBaselines()).toEqual({ 42: 7 });
    });
});

// ─── Producer 2: refreshTouchedBaselines() after a rolled-back batch ─────────

describe('producer 2: refreshTouchedBaselines() after a non-conflict rollback', function () {
    test('2a: a touched-page re-read landing after New Chat persists nothing', async function () {
        await proposeAndApply(ROLLED_BACK);
        newChat();

        settleAll('baseline');
        await settle();

        expect(storedBaselines()).toEqual({});
    });

    test('2b: with no New Chat, the touched page\'s baseline is still refreshed', async function () {
        await proposeAndApply(ROLLED_BACK);

        settleAll('baseline');
        await settle();

        expect(storedBaselines()).toEqual({ 42: 7 });
    });
});

// ─── Producer 3: the Undo link ───────────────────────────────────────────────

describe('producer 3: the post-apply card\'s Undo link', function () {
    async function clickUndo() {
        const card = await proposeAndApply(APPLIED);
        const undo = Array.prototype.find.call(card.querySelectorAll('a'), function (a) {
            return a.textContent === 'Undo these changes';
        });
        expect(undo).toBeDefined();
        click(undo);
        return undo;
    }

    test('3a: an undo that succeeds after New Chat persists no post-restore baseline', async function () {
        await clickUndo();
        newChat();
        expect(storedBaselines()).toEqual({});

        settleAll('undo');
        await settle();

        expect(storedBaselines()).toEqual({});
    });

    test('3a (consent gate): the next conversation still carries no baseline for that page', async function () {
        await clickUndo();
        newChat();
        settleAll('undo');
        await settle();

        chatCarriesBaseline = false;
        batchBaselines = [];
        await proposeAndApply(APPLIED);

        expect(batchBaselines[0]).toEqual({});
    });

    test('3b: with no New Chat, the undo still records the post-restore version', async function () {
        const undo = await clickUndo();
        settleAll('undo');
        await settle();

        expect(undo.textContent).toBe('Changes undone ✓');
        expect(storedBaselines()).toEqual({ 42: 9 });
    });
});

// ─── 4. Abandonment is quiet ─────────────────────────────────────────────────

describe('an abandoned producer', function () {
    test('4: leaves no unhandled rejection behind, on any of the three paths', async function () {
        // Producer 1
        let card = await proposeAndApply(PRE_EXEC_CONFLICT);
        click(card.querySelector('.pp-ai-proposal-actions button'));
        newChat();
        settleAll('baseline');
        await settle();

        // Producer 2
        await proposeAndApply(ROLLED_BACK);
        newChat();
        settleAll('baseline');
        await settle();

        // Producer 3
        card = await proposeAndApply(APPLIED);
        const undo = Array.prototype.find.call(card.querySelectorAll('a'), function (a) {
            return a.textContent === 'Undo these changes';
        });
        click(undo);
        newChat();
        settleAll('undo');
        await settle();

        // Give the runtime's rejection tracker a macrotask to report.
        await new Promise(function (r) { setTimeout(r, 0); });
        expect(unhandled).toEqual([]);
        expect(messagesEl.textContent).toBe('');
    });
});
