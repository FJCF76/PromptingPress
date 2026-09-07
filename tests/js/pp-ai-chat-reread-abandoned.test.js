/**
 * A re-read that comes back to a conversation that ended (#880).
 *
 * `showConflictState()`'s Re-read & re-preview affordance re-reads the page's baseline and
 * then re-renders the proposal. Clicking New Chat while that read is in flight used to change
 * nothing about it: `resetChat()` emptied the transcript, and the read's `.then` ran anyway
 * and appended a fresh proposal card — with a live Apply button, wired to the ENDED
 * conversation's page — into the new, otherwise empty chat.
 *
 * WHY THAT CARD WAS DANGEROUS RATHER THAN UNTIDY. The re-read's whole job is to refresh the
 * CAS baseline (#404) before previewing again, so the injected card carried a baseline that
 * had just been read and was therefore CURRENT. The server had nothing to refuse: the write
 * would have gone through. The only thing standing between the operator and applying steps
 * they had not asked for in this conversation was noticing that the card did not belong to
 * it — which is exactly the awareness a cleared transcript takes away. v1.19.0's release
 * notes carried a user-facing caveat about not applying a card that predates the current
 * chat; this is the fix that retires it.
 *
 * WHAT KEY THE GUARD IS ON, AND WHY IT IS NOT `currentRequestId` (the ruling behind this
 * file). The other guarded callbacks in this file belong to a CHAT REQUEST and key on
 * `currentRequestId`, which `sendMessage()` also bumps. Borrowing it here would have made a
 * follow-up message count as abandoning the re-read: the re-preview the operator asked for
 * dropped silently, the card left holding a dead "Re-reading…" button. This affordance
 * outlives its request, so it gets the key that answers its own question —
 * `currentConversationId`, bumped only by `resetChat()`. Pin 5 is that decision, held.
 *
 * SCOPE, SAID ONCE SO NO PIN HAS TO IMPLY IT. Guarding this handler does not sweep the file:
 * the post-apply card's one-shot links outlive their request the same way, and the undo
 * link's late response writes a CAS baseline into whatever conversation is current (#909);
 * executeProposal()'s own response chain is #910; renderProposal()'s preview chain is #787.
 * What is unique to the re-read, and what these pins are about, is that its late response
 * RENDERS a fresh proposal with a live Apply. Everything here is also single-tab: a New Chat
 * in another tab does not end this one's conversation (issue 205's standing assumption).
 *
 * WHAT IS PINNED HERE, IN TWO KINDS, said plainly because the difference matters. Every test
 * name below carries its pin number, so the index is greppable rather than positional:
 *
 *   RED-PROOF (fails against the pre-fix source, and is the evidence the bug is gone)
 *     pin 1. New Chat mid-read: the new transcript stays EMPTY — no card, no Apply, no status.
 *     pin 2. New Chat mid-read on the FAILURE arm: no "Could not re-read the page." either.
 *     pin 3. New Chat mid-read builds no proposal card ANYWHERE in the document, attached or
 *            detached — the strong form of pin 1, and the one that says the render never ran
 *            rather than that its output landed out of sight.
 *
 *   PRESERVATION (passes before and after; stops the fix being bought by breaking the
 *   affordance, which is the cheap way to make pin 1 green)
 *     pin 4. No New Chat: the re-read still renders its fresh proposal, with a working Apply,
 *            and still spends the affordance the way #856 left it.
 *     pin 5. A follow-up SEND during a re-read still renders the re-preview — the regression
 *            Option A would have shipped, pinned against.
 *     pin 6. Source tripwires: the handler reads the conversation generation and checks it on
 *            BOTH arms before anything else, does not reach for `currentRequestId`, and
 *            `resetChat()` still bumps BOTH keys (the collapse in the other direction, which
 *            would silently restore issue 139).
 *
 * DELIBERATELY NOT PINNED HERE: the CAS baseline `refreshBaseline()` stores for the old page
 * on its way through. That write happens inside the shared helper, upstream of anything this
 * handler can guard, so this fix does not close it and this file does not claim to — it is
 * filed as #909.
 *
 * The seam is `fetch`, and the baseline read is GATED: the test holds the response open,
 * clicks New Chat, and only then resolves it. That is the whole race, driven rather than
 * simulated.
 */

const fs = require('fs');
const path = require('path');
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

const SITE_URL = 'http://abandoned.example.com';
const USER_ID = '11';
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

// sendMessage() refuses without a selected page, and ppChatBuildBatchBaselines() needs a
// baseline for the mutating step, so both exist before the IIFE boots.
localStorage.setItem(STORAGE_KEY, JSON.stringify({
    activePageId: PAGE_ID,
    pageBaselines: { 42: 3 },
    conversation: []
}));

// ─── Fixtures ────────────────────────────────────────────────────────────────

/**
 * The pre-execution conflict refusal: an error payload with no `steps`, because none ran.
 * Chosen over an executed envelope on purpose — the executed path also calls
 * refreshTouchedBaselines(), which would open baseline reads this file's gate does not own,
 * and the executed/dirty half of the affordance is already pinned in
 * tests/js/pp-ai-chat-conflict-rollback.test.js.
 */
const PRE_EXEC_CONFLICT = {
    error: 'This page changed since you last read it.',
    error_code: 'composition_conflict',
    expected_version: 3,
    current_version: 4
};

const PROPOSED_STEPS = [
    {
        type: 'action',
        name: 'update_component',
        description: 'Update the hero title',
        params: { post_id: PAGE_ID, component_index: 0, props: { title: 'New' } }
    }
];

// ─── The seam ────────────────────────────────────────────────────────────────

let chatCalls = 0;
/**
 * Previews issued (#880 pin 3). renderProposal() cannot draw a card without firing one per
 * step, so this counter is the difference between "the render never ran" and "its output was
 * thrown away" — a distinction no DOM query can make, because a card that is built and then
 * detached never enters the document at all.
 */
let previewCalls = 0;
/** Set while a baseline read is open: {settle} resolves it, {fail} makes refreshBaseline throw. */
let baselineGate = null;

/** The response object itself — what the gated read has to resolve with, un-wrapped. */
function jsonResponse(payload) {
    return { ok: true, json: function () { return Promise.resolve(payload); } };
}

/** The house helper: the same shape, already a promise. One shape, two entry points. */
function jsonOk(payload) {
    return Promise.resolve(jsonResponse(payload));
}

global.fetch = function (url, opts) {
    // The SSE path rejects, dropping the client onto ajaxFallback() immediately — the same
    // code path a buffering proxy produces, rendering through the identical renderProposal().
    if (url === dom.window.ppAiChat.streamUrl) return Promise.reject(new Error('no stream here'));

    const body = opts && opts.body;
    const action = body && typeof body.get === 'function' ? body.get('action') : 'chat-fallback';

    if (action === 'pp_ai_preview') {
        previewCalls++;
        return jsonOk({ success: true, data: { changes: [{ path: 'props.title', from: 'Old', to: 'New' }] } });
    }
    if (action === 'pp_ai_execute_batch') {
        return jsonOk({ success: false, data: PRE_EXEC_CONFLICT });
    }
    if (action === 'pp_ai_page_baseline') {
        // Held open. The test decides when — and whether — this read comes back, which is the
        // only way to put a New Chat INSIDE the round trip rather than beside it.
        return new Promise(function (resolve) {
            baselineGate = {
                settle: function () {
                    resolve(jsonResponse({ success: true, data: { post_id: PAGE_ID, version: 4 } }));
                },
                fail: function () {
                    resolve(jsonResponse({ success: false, data: { error: 'could not read the page' } }));
                }
            };
        });
    }
    if (action === 'pp_ai_chat') {
        chatCalls++;
        // Only the first turn proposes, so pin 5 can count cards without ambiguity about which
        // one it is reading. NAMING WHAT THAT AVOIDS, because it is a real state and not a
        // fixture convenience: a follow-up turn that DID propose would leave two live cards for
        // one page, and every live card reads the same `pageBaselines` slot at Apply time, so
        // the older one threads the newer read's baseline. That is #911, it predates this
        // change, and it is that issue's to pin — not something this fixture is hiding.
        return jsonOk(chatCalls === 1
            ? {
                success: true,
                data: {
                    content: 'Here is my proposal.',
                    proposal: { proposal: true, steps: PROPOSED_STEPS },
                    page_baseline: { post_id: PAGE_ID, version: 3 }
                }
            }
            : {
                success: true,
                data: { content: 'Noted.', page_baseline: { post_id: PAGE_ID, version: 3 } }
            });
    }
    // Loud rather than helpful: an unrecognized action answered with a plausible response is
    // how a seam that measures nothing passes for a green test.
    throw new Error('unexpected action in fetch mock: ' + action);
};

require('../../assets/js/pp-ai-chat.js');

// ─── Harness ─────────────────────────────────────────────────────────────────

const messagesEl = document.getElementById('pp-ai-messages');
const inputEl = document.getElementById('pp-ai-input');
const sendBtn = document.getElementById('pp-ai-send');
const newChatBtn = document.getElementById('pp-ai-new-chat');
const pageSelectEl = document.getElementById('pp-ai-page-select');

/** Drain the promise chains the client queues (fetch → json → render, several deep). */
async function settle() {
    for (let i = 0; i < 60; i++) await Promise.resolve();
}

function click(el) {
    el.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
}

async function send(text) {
    inputEl.value = text;
    click(sendBtn);
    await settle();
}

/** Sends a message, applies the proposal, and returns the settled conflict card. */
async function getConflictCard() {
    await send('Change the hero');

    const cards = messagesEl.querySelectorAll('.pp-ai-proposal-card');
    expect(cards.length).toBe(1);
    const card = cards[0];

    const applyBtn = card.querySelector('.pp-ai-proposal-apply');
    expect(applyBtn).not.toBeNull();
    click(applyBtn);
    await settle();

    // Premise for everything below: this really is the conflict rendering.
    expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(true);
    return card;
}

/** Spends the affordance. Does NOT settle: the baseline read is left open on purpose. */
function clickReread(card) {
    const btn = card.querySelector('.pp-ai-proposal-actions button');
    expect(btn).not.toBeNull();
    expect(btn.textContent).toBe('Re-read & re-preview');
    click(btn);
    // The read is now in flight and gated.
    expect(baselineGate).not.toBeNull();
    return btn;
}

function applyButtons() {
    return messagesEl.querySelectorAll('.pp-ai-proposal-apply');
}

beforeEach(function () {
    chatCalls = 0;
    previewCalls = 0;
    baselineGate = null;
    click(newChatBtn);
    pageSelectEl.value = String(PAGE_ID);
    pageSelectEl.dispatchEvent(new dom.window.Event('change'));
});

// ─── 1. The bug ──────────────────────────────────────────────────────────────

describe('New Chat while a re-read is in flight', function () {
    test('pin 1: renders nothing at all into the conversation that replaced it', async function () {
        const card = await getConflictCard();
        clickReread(card);

        click(newChatBtn);
        expect(messagesEl.children.length).toBe(0); // the transcript really was cleared

        baselineGate.settle();
        await settle();

        // The whole claim, in the order it matters: no card, no live affordance, nothing.
        expect(messagesEl.querySelectorAll('.pp-ai-proposal-card').length).toBe(0);
        expect(applyButtons().length).toBe(0);
        expect(messagesEl.querySelectorAll('button').length).toBe(0);
        expect(messagesEl.children.length).toBe(0);
        expect(messagesEl.textContent).toBe('');
    });

    test('pin 2: does not announce the read failure into it either', async function () {
        const card = await getConflictCard();
        clickReread(card);

        click(newChatBtn);
        baselineGate.fail();
        await settle();

        // addStatusMessage() appends to the LIVE transcript, so the failure arm can reach the
        // new conversation even though the button it hands back cannot.
        expect(messagesEl.children.length).toBe(0);
        expect(messagesEl.textContent).toBe('');
    });

    test('pin 3: never runs the render at all, rather than throwing its output away', async function () {
        const card = await getConflictCard();
        clickReread(card);

        const previewsBefore = previewCalls;

        click(newChatBtn);
        baselineGate.settle();
        await settle();

        // THE STRONG FORM OF PINS 1 AND 2, and the counter is the load-bearing half. Pins 1
        // and 2 say the transcript is empty; a "guard" that let renderProposal() build the
        // card and then detached it would satisfy them, and would satisfy a document-wide DOM
        // query too, because a detached card never enters the document in the first place
        // (renderProposal() creates it with createElement and it only becomes reachable at
        // messagesEl.appendChild). A preview request is the one thing the render cannot avoid
        // making — one per step, before any of that — so a flat count is the difference
        // between "it never ran" and "its output was thrown away". The fix has to be a return,
        // not a cleanup, and this is what says so without reading the source.
        expect(previewCalls).toBe(previewsBefore);

        // The DOM half, kept beside it: hiding rather than detaching is caught here.
        expect(document.querySelectorAll('.pp-ai-proposal-apply').length).toBe(0);
        expect(document.querySelectorAll('.pp-ai-proposal-card:not(.pp-ai-proposal-conflict)').length).toBe(0);
    });
});

// ─── 2. What must not change ─────────────────────────────────────────────────

describe('a re-read that comes back to the conversation it started in', function () {
    test('pin 4a: still re-previews the proposal and still offers Apply', async function () {
        const card = await getConflictCard();
        clickReread(card);

        baselineGate.settle();
        await settle();

        const cards = messagesEl.querySelectorAll('.pp-ai-proposal-card');
        expect(cards.length).toBe(1);

        const fresh = cards[0];
        expect(fresh).not.toBe(card);
        expect(fresh.classList.contains('pp-ai-proposal-conflict')).toBe(false);

        const applyBtn = fresh.querySelector('.pp-ai-proposal-apply');
        expect(applyBtn).not.toBeNull();
        expect(applyBtn.disabled).toBe(false);
    });

    test('pin 4b: still spends the affordance the way #856 left it', async function () {
        const card = await getConflictCard();
        clickReread(card);

        baselineGate.settle();
        await settle();

        // A clean report keeps today's behaviour exactly: the conflict card goes. (The dirty
        // half — card kept, action row removed — is pinned in
        // tests/js/pp-ai-chat-conflict-rollback.test.js, which owns that rule.)
        expect(messagesEl.contains(card)).toBe(false);
    });

    /**
     * THE REGRESSION THE OTHER KEY WOULD HAVE SHIPPED (#880).
     *
     * `sendMessage()` bumps `currentRequestId`, and nothing disables Send while a conflict
     * card is on screen — `executeProposal()`/`showConflictState()` never touch
     * setStreamingUiState. So had the guard borrowed that key, this sequence would silently
     * drop the re-preview and strand the affordance on a dead "Re-reading…" button. The
     * conversation has not ended, so the re-read still belongs to it.
     */
    test('pin 5: survives a follow-up message sent while the read is in flight', async function () {
        const card = await getConflictCard();
        clickReread(card);

        await send('Actually, also check the subtitle');
        expect(chatCalls).toBe(2); // the send really went

        baselineGate.settle();
        await settle();

        const cards = messagesEl.querySelectorAll('.pp-ai-proposal-card');
        expect(cards.length).toBe(1);
        expect(cards[0].querySelector('.pp-ai-proposal-apply')).not.toBeNull();
    });
});

// ─── 3. Source tripwires ─────────────────────────────────────────────────────

describe('the guard itself', function () {
    const JS = fs.readFileSync(path.resolve(__dirname, '../../assets/js/pp-ai-chat.js'), 'utf-8');
    // Docblocks discuss both keys at length, and prose is not a place a guard can live.
    const CODE = JS.replace(/\/\*[\s\S]*?\*\//g, '');

    function rereadHandler() {
        const start = CODE.indexOf("rereadBtn.addEventListener('click'");
        expect(start).toBeGreaterThan(-1);
        const end = CODE.indexOf('actions.appendChild(rereadBtn)', start);
        expect(end).toBeGreaterThan(start);

        // Line comments go too: they name the key they are explaining, and this file's
        // assertions are about what the handler READS.
        const handler = CODE.slice(start, end).replace(/\/\/[^\n]*/g, '');
        // Fail loud if the handler moved rather than accusing it of reading the wrong key —
        // hoisting the body into a named function collapses this window to a few characters.
        expect(handler.length).toBeGreaterThan(200);
        return handler;
    }

    /**
     * ONE CAPTURE IS THE CORRECTNESS PROPERTY; THE CHECKS ARE A FLOOR, NOT A CEILING.
     *
     * The capture is pinned exactly: two captures would mean two generations in one handler,
     * and the second would be read AFTER the read started, which is the bug. The checks are
     * pinned per-arm with a minimum instead of an exact count, deliberately — an exact count
     * would turn RED on a change that adds a THIRD check (a `.finally` arm, or threading the
     * same generation further down), i.e. it would penalise strengthening the very guard it
     * exists to protect.
     */
    it('captures the conversation generation once and checks it on both arms', function () {
        const handler = rereadHandler();

        expect(handler.split('var myConversationId = currentConversationId;').length - 1).toBe(1);

        const GUARD = 'myConversationId !== currentConversationId';
        const catchAt = handler.indexOf('.catch(function ()');
        expect(catchAt).toBeGreaterThan(-1);

        const thenArm = handler.slice(0, catchAt);
        const catchArm = handler.slice(catchAt);

        expect(thenArm.split(GUARD).length - 1).toBeGreaterThanOrEqual(1);
        expect(catchArm.split(GUARD).length - 1).toBeGreaterThanOrEqual(1);
    });

    /**
     * The ruling, held as a tripwire rather than as prose: this affordance outlives its
     * request, so it must not key on the request generation. See the declaration of
     * `currentConversationId` for the two defects unifying the keys re-introduces.
     */
    it('does not borrow the chat-request generation', function () {
        expect(rereadHandler()).not.toContain('currentRequestId');
    });

    it('checks before it renders, not after', function () {
        const handler = rereadHandler();

        const guardAt = handler.indexOf('myConversationId !== currentConversationId');
        const renderAt = handler.indexOf('renderProposal(');

        expect(guardAt).toBeGreaterThan(-1);
        expect(renderAt).toBeGreaterThan(guardAt);
    });

    /**
     * BOTH KEYS, BOTH DIRECTIONS.
     *
     * One owner for the conversation bump, and it is the function that ends a conversation —
     * a second bump site is how this key would quietly become a copy of `currentRequestId`.
     *
     * And the collapse in the OTHER direction is pinned in the same breath, because it is the
     * one this change makes tempting: two counters incremented on adjacent lines, under a
     * docblock arguing they are separable, invites a future reader to keep one. Dropping
     * `currentRequestId++` here restores issue 139 — an abandoned stream's partial text and
     * proposal re-populating a transcript the operator cleared — and nothing else in the
     * suite notices (verified by deleting that line in a copied tree: the whole JS suite
     * stayed green).
     */
    it('is bumped only by resetChat(), which still bumps the request key too', function () {
        expect(CODE.split('currentConversationId++').length - 1).toBe(1);

        const start = CODE.indexOf('function resetChat(');
        expect(start).toBeGreaterThan(-1);
        const end = CODE.indexOf('sendBtn.addEventListener(', start);
        expect(end).toBeGreaterThan(start);

        const resetChat = CODE.slice(start, end);
        expect(resetChat).toContain('currentConversationId++');
        expect(resetChat).toContain('currentRequestId++');
    });
});
