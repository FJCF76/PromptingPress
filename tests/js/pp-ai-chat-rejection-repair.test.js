/**
 * The operator-gated self-correction loop (#704, ruling D-2).
 *
 * A refused proposal used to reach exactly one participant. The error rendered to the
 * OPERATOR and stopped there, so the model that authored the bad step never learned why it
 * was refused. D-2 ships the mechanical half — the rejection re-enters the model's
 * conversation — and withholds the autonomous half: the retry is PROPOSED to the operator,
 * never sent.
 *
 * WHY THIS FILE DRIVES THE WHOLE SURFACE INSTEAD OF THE HELPERS. The two exported helpers
 * are pure and easy to test, and testing only them would prove nothing that matters here:
 * every assertion about them passes whether or not executeProposal() calls them, and the
 * bug being fixed IS a missing call. So the tests below walk the real path — send a
 * message, let the client render a proposal card, click Apply, answer with a refused batch
 * — and then read the two things that actually decide whether the loop shipped:
 *
 *   localStorage.conversation   what the NEXT request will carry to the model
 *   the card's DOM              what the operator is offered, and whether anything moved
 *                               without them
 *
 * The fetch mock is the seam. It answers by `action`, counts every call, and is the only
 * way a request can leave this file — so "no code path sends a model request without an
 * operator action" is checked by arithmetic on that counter, not by reading the source.
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

const SITE_URL = 'http://repair.example.com';
const USER_ID = '9';
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

// A page selection and a CAS baseline must exist before the IIFE boots: sendMessage()
// refuses to send without a selected page, and ppChatBuildBatchBaselines() needs the
// baseline for a mutating step. restoreConversation() restores both from saved state even
// when the conversation itself is empty, which is the cheapest honest way in.
localStorage.setItem(STORAGE_KEY, JSON.stringify({
    activePageId: PAGE_ID,
    pageBaselines: { 42: 3 },
    conversation: []
}));

// ─── The seam ────────────────────────────────────────────────────────────────

/** Every fetch this module makes, in order, as {action, url}. */
let calls = [];
/** What the batch endpoint answers with. Set per test. */
let batchResponse = null;

function jsonOk(payload) {
    return Promise.resolve({ ok: true, json: function () { return Promise.resolve(payload); } });
}

/** The proposal the fallback hands back — one mutating step on the selected page. */
const PROPOSED_STEP = {
    type: 'action',
    name: 'update_component',
    description: 'Update the hero title',
    params: { post_id: PAGE_ID, component_index: 0, props: { title: 'New' } }
};

global.fetch = function (url, opts) {
    // The SSE path rejects, which drops the client onto ajaxFallback() immediately. That
    // is the same code path a buffering proxy produces in production, and it renders the
    // proposal card through the identical renderProposal() the streaming path uses.
    if (url === dom.window.ppAiChat.streamUrl) {
        calls.push({ action: 'stream', url: url });
        return Promise.reject(new Error('no stream here'));
    }

    const body = opts && opts.body;
    const action = body && typeof body.get === 'function' ? body.get('action') : 'chat-fallback';
    calls.push({ action: action, url: url });

    if (action === 'pp_ai_preview') {
        return jsonOk({ success: true, data: { changes: [{ path: 'props.title', from: 'Old', to: 'New' }] } });
    }
    if (action === 'pp_ai_execute_batch') {
        return jsonOk(batchResponse);
    }
    if (action === 'pp_ai_page_baseline') {
        // A non-conflict rollback re-reads every touched page's baseline (#404), because
        // the snapshot-restore bumped the version this client is still holding.
        return jsonOk({ success: true, data: { post_id: PAGE_ID, version: 4 } });
    }
    if (action === 'pp_ai_chat') {
        return jsonOk({
            success: true,
            data: {
                content: 'Here is my proposal.',
                proposal: { proposal: true, steps: [PROPOSED_STEP] },
                page_baseline: { post_id: PAGE_ID, version: 3 }
            }
        });
    }
    // Loud rather than helpful: an unrecognized action answered with a plausible chat
    // response is how a counter that measures nothing passes for a green test.
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

function storedConversation() {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw).conversation || []) : [];
}

function countCalls(action) {
    return calls.filter(function (c) { return c.action === action; }).length;
}

/**
 * Requests that would reach the PROVIDER, counted as one number.
 *
 * Both spellings count, and that is the point: a chat turn opens on the SSE endpoint and
 * falls back to `pp_ai_chat` when it cannot stream, so counting either one alone leaves a
 * send that took the other route invisible — which is exactly the shape of an accidental
 * automatic retry.
 */
function modelRequests() {
    return countCalls('stream') + countCalls('pp_ai_chat');
}

/** Sends a message and returns the proposal card, previewed and showing Apply. */
async function proposeAndPreview() {
    inputEl.value = 'Change the hero title';
    sendBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    const card = messagesEl.querySelector('.pp-ai-proposal-card:last-of-type')
        || messagesEl.querySelector('.pp-ai-proposal-card');
    expect(card).not.toBeNull();
    return card;
}

/** Clicks Apply on a previewed card and lets the batch response land. */
async function apply(card) {
    const applyBtn = card.querySelector('.pp-ai-proposal-apply');
    expect(applyBtn).not.toBeNull();
    applyBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();
}

function repairRow(card) {
    return card.querySelector('.pp-ai-repair-actions');
}

/** The turns the loop is allowed to add: hidden environment reports start with '['. */
function rejectionTurns() {
    return storedConversation().filter(function (m) {
        return m.role === 'user' && typeof m.content === 'string' && m.content.indexOf('[Rejected:') === 0;
    });
}

/** A refused batch shaped like the server's, carrying the server-written note. */
const NOTE = '[Rejected: step 1 (update_component) was refused, so this proposal was not '
    + 'applied. error_code: unknown_prop. Blocking composition band: index 0. Reason: '
    + 'Component 0 ("hero") has no prop "titel". All changes in this proposal were reverted. '
    + 'The operator decides whether to retry — do not re-send this proposal unless asked.]';

function refusedBatch(extra) {
    return {
        success: true,
        data: Object.assign({
            ok: false,
            steps: [{ ok: false, action: 'update_component', error: 'No such prop.', error_code: 'unknown_prop', index: 0 }],
            failed_at: 0,
            rolled_back: true,
            rollback_errors: [],
            versions: {},
            model_note: NOTE
        }, extra || {})
    };
}

// The module keeps ONE `conversation` array for the life of the require, so a per-test
// localStorage rewrite would be overwritten by the next saveState() and every count would
// measure the whole file instead of the test. Reset through the real New Chat button —
// which is the only thing that clears it — and re-pick the page through the real selector.
// The CAS baseline comes back on its own: the chat response carries `page_baseline`, and
// storePageBaseline() lands it before Apply ever needs it.
beforeEach(function () {
    calls = [];
    batchResponse = null;
    newChatBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    pageSelectEl.value = String(PAGE_ID);
    pageSelectEl.dispatchEvent(new dom.window.Event('change'));
    expect(storedConversation()).toHaveLength(0);
});

// ─── The loop, end to end ────────────────────────────────────────────────────

describe('a refused proposal reaches the model (#704)', function () {
    it('appends the server-written note to the conversation the next request carries', async function () {
        batchResponse = refusedBatch();

        const card = await proposeAndPreview();
        expect(rejectionTurns()).toHaveLength(0); // premise: nothing before the refusal
        await apply(card);

        const turns = rejectionTurns();
        expect(turns).toHaveLength(1);
        // VERBATIM. The client copies one server string; it does not assemble a second,
        // shorter retelling that could drift from the card the operator is reading.
        expect(turns[0].content).toBe(NOTE);
        // A `user` turn, because this is the environment reporting an outcome — the same
        // provenance `[Applied changes: ...]` already claims on the success path.
        expect(turns[0].role).toBe('user');
        expect(turns[0].internal).toBe(true);
    });

    it('hides the note from the transcript, so the operator never sees a turn they did not type', async function () {
        batchResponse = refusedBatch();

        const card = await proposeAndPreview();
        await apply(card);

        const userBubbles = Array.from(messagesEl.querySelectorAll('.pp-ai-msg-user, .pp-ai-message-user'))
            .map(function (el) { return el.textContent; });
        userBubbles.forEach(function (text) {
            expect(text.indexOf('[Rejected:')).toBe(-1);
        });
        // And the rule that keeps it hidden across a RELOAD is the leading bracket, which
        // restoreConversation() tests directly. Pin the property, not the renderer.
        expect(rejectionTurns()[0].content.charAt(0)).toBe('[');
    });

    it('tells the operator the model has it, and offers exactly one way to spend that', async function () {
        batchResponse = refusedBatch();

        const card = await proposeAndPreview();
        await apply(card);

        const row = repairRow(card);
        expect(row).not.toBeNull();
        expect(row.textContent).toContain('The AI can see this error');

        const buttons = row.querySelectorAll('button');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].textContent).toBe('Ask the AI to fix it');
        expect(buttons[0].disabled).toBe(false);
    });

    it('grows the card before announcing, so the alert stays on screen (#755 ordering)', async function () {
        batchResponse = refusedBatch();

        const card = await proposeAndPreview();
        await apply(card);

        // addStatusMessage() pins the transcript to its own bottom and the card is an
        // earlier sibling in that scroller. If the repair row were appended AFTER the
        // announce, the alert would be pushed back off the fold. Checked as DOM order in
        // the real transcript rather than as source-string order.
        const children = Array.from(messagesEl.children);
        const alerts = children.filter(function (el) {
            return el.classList.contains('pp-ai-status-error');
        });
        expect(alerts.length).toBeGreaterThan(0);
        expect(children.indexOf(card)).toBeLessThan(children.indexOf(alerts[alerts.length - 1]));
        expect(repairRow(card)).not.toBeNull();
    });
});

// ─── The gate: nothing moves without the operator ────────────────────────────

describe('the retry is proposed, never automatic (ruling D-2)', function () {
    it('sends nothing to the model when the refusal lands', async function () {
        batchResponse = refusedBatch();

        const card = await proposeAndPreview();
        const before = modelRequests();
        expect(before).toBeGreaterThan(0); // premise: the counter counts something
        await apply(card);

        // THE LOAD-BEARING ASSERTION. The note is in context and the affordance is on
        // screen, and not one byte has gone to the provider.
        expect(modelRequests()).toBe(before);
        expect(rejectionTurns()).toHaveLength(1);
        expect(repairRow(card)).not.toBeNull();
    });

    it('sends exactly one request when the operator clicks, and renders what was asked', async function () {
        batchResponse = refusedBatch();

        const card = await proposeAndPreview();
        await apply(card);
        const before = countCalls('pp_ai_chat');

        repairRow(card).querySelector('button').dispatchEvent(
            new dom.window.MouseEvent('click', { bubbles: true })
        );
        await settle();

        expect(countCalls('pp_ai_chat')).toBe(before + 1);
        // A real operator message: visible in the transcript, because the operator is
        // approving a request made in their name and has to be able to read it.
        const sent = storedConversation().filter(function (m) {
            return m.role === 'user' && m.content.indexOf('That proposal was rejected') === 0;
        });
        expect(sent).toHaveLength(1);
        expect(messagesEl.textContent).toContain('That proposal was rejected');
    });

    it('disables itself the moment it is spent, so the card cannot resend', async function () {
        batchResponse = refusedBatch();

        const card = await proposeAndPreview();
        await apply(card);
        const btn = repairRow(card).querySelector('button');
        const before = countCalls('pp_ai_chat');

        btn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        await settle();

        // `disabled` is the browser's gate, not this file's: a real second click on a
        // disabled button never reaches the listener. Asserted as state rather than by
        // dispatching again, because dispatchEvent() invokes listeners directly and would
        // prove the opposite of what a user experiences.
        expect(btn.disabled).toBe(true);
        expect(countCalls('pp_ai_chat')).toBe(before + 1);
    });

    it('adds exactly one note per refused apply, and closes Apply behind it', async function () {
        batchResponse = refusedBatch();

        const card = await proposeAndPreview();
        await apply(card);

        expect(rejectionTurns()).toHaveLength(1);
        expect(card.querySelectorAll('.pp-ai-repair-actions')).toHaveLength(1);
        // executeProposal() disables Apply on entry and never re-enables it on failure, so
        // there is no second execute to append a second note — the accumulating
        // conversation is the thing that would hurt, and this is what prevents it.
        expect(card.querySelector('.pp-ai-proposal-apply').disabled).toBe(true);
    });
});

// ─── Where there is deliberately no loop ─────────────────────────────────────

describe('failures that are not the model\'s to answer get nothing', function () {
    it('leaves an accepted proposal completely untouched', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: true,
                steps: [{ ok: true, action: 'update_component', validation: { ok: true, warnings: [], errors: [] } }],
                failed_at: null,
                rolled_back: false,
                rollback_errors: [],
                versions: { 42: 4 }
            }
        };

        const card = await proposeAndPreview();
        await apply(card);

        expect(rejectionTurns()).toHaveLength(0);
        expect(repairRow(card)).toBeNull();
        // The success path's own conversation injection still happens, unchanged.
        const applied = storedConversation().filter(function (m) {
            return m.content.indexOf('[Applied changes:') === 0;
        });
        expect(applied).toHaveLength(1);
    });

    it('offers no repair on a conflict, which the server answers with no note', async function () {
        // The page moved under a proposal that was correct when written. The server writes
        // no note for this class, so presence alone keeps the client out of it — and the
        // conflict's own affordance stays the single thing on the card.
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: false, action: 'update_component', error: 'Page changed.', error_code: 'composition_conflict' }],
                failed_at: 0,
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        const card = await proposeAndPreview();
        await apply(card);

        expect(rejectionTurns()).toHaveLength(0);
        expect(repairRow(card)).toBeNull();
        expect(card.textContent).toContain('Re-read & re-preview');
    });

    it('offers no repair when the failure payload carries no note at all', async function () {
        // 'Permission denied.' and the malformed-request refusals answer with a bare
        // string. Nothing the model writes changes a capability check.
        batchResponse = { success: false, data: 'Permission denied.' };

        const card = await proposeAndPreview();
        await apply(card);

        expect(rejectionTurns()).toHaveLength(0);
        expect(repairRow(card)).toBeNull();
        expect(messagesEl.textContent).toContain('Permission denied.');
    });
});

// ─── The pre-execution refusal, which does carry one ─────────────────────────

describe('a batch refused before step 1 (#749)', function () {
    it('appends its note and offers the repair, with every step marked skipped', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [],
                failed_at: null,
                rolled_back: false,
                rollback_errors: [],
                versions: {},
                error: 'That page\'s stored composition cannot be read.',
                model_note: '[Rejected: this proposal was refused before any step ran. '
                    + 'error_code: decode_error. No step ran, so nothing was changed. '
                    + 'The operator decides whether to retry — do not re-send this proposal unless asked.]'
            }
        };

        const card = await proposeAndPreview();
        await apply(card);

        expect(rejectionTurns()).toHaveLength(1);
        expect(rejectionTurns()[0].content).toContain('refused before any step ran');
        expect(repairRow(card)).not.toBeNull();
        expect(card.querySelectorAll('.pp-ai-step-skipped').length).toBeGreaterThan(0);
    });
});

// ─── The helpers, at their edges ─────────────────────────────────────────────

const { modelNote, appendRepairAffordance } = require('../../assets/js/pp-ai-chat.js');

describe('modelNote', function () {
    it('answers only to a non-empty string, so presence is the whole rule', function () {
        expect(modelNote({ model_note: '[Rejected: x]' })).toBe('[Rejected: x]');
        expect(modelNote({ model_note: '' })).toBeNull();
        expect(modelNote({})).toBeNull();
        expect(modelNote(null)).toBeNull();
        expect(modelNote('a bare string payload')).toBeNull();
    });

    it('refuses a non-string note rather than stringifying it', function () {
        // The payload is JSON off the wire. A `.indexOf` on a number inside the fetch
        // handler would throw and take the rest of the failure rendering with it (#663).
        expect(modelNote({ model_note: 42 })).toBeNull();
        expect(modelNote({ model_note: ['a'] })).toBeNull();
        expect(modelNote({ model_note: { text: 'a' } })).toBeNull();
    });
});

describe('appendRepairAffordance', function () {
    it('does not throw when there is no card to grow', function () {
        expect(appendRepairAffordance(null, function () {})).toBeNull();
    });

    it('describes the button with its own note, and gives every card a distinct id', function () {
        // The sentence is the answer to "did the correction get through?", and it has to
        // reach a screen-reader user at the moment it is actionable. It is NOT a third live
        // region: the rollback disclosure is already role="status" and the failure line is
        // role="alert", so a third announcement would talk over the two carrying the news.
        const first = document.createElement('div');
        const second = document.createElement('div');
        const btnA = appendRepairAffordance(first, function () {});
        const btnB = appendRepairAffordance(second, function () {});

        const noteA = first.querySelector('.pp-ai-repair-note');
        expect(btnA.getAttribute('aria-describedby')).toBe(noteA.id);
        expect(noteA.id).not.toBe('');

        // Two failure cards can sit in one transcript; a shared id would point both
        // descriptions at whichever the parser saw first.
        expect(second.querySelector('.pp-ai-repair-note').id).not.toBe(noteA.id);
        expect(btnB.getAttribute('aria-describedby')).toBe(second.querySelector('.pp-ai-repair-note').id);
    });

    it('takes focus, because the failure just threw it away', function () {
        // executeProposal() disables the Apply button the operator clicked, and disabling the
        // focused element drops focus to <body> — a keyboard user would otherwise Tab from
        // the top of the whole admin document to reach a button that appeared in direct
        // response to their own click.
        const card = document.createElement('div');
        document.body.appendChild(card); // jsdom only focuses attached elements
        const btn = appendRepairAffordance(card, function () {});

        expect(document.activeElement).toBe(btn);
        card.remove();
    });

    it('gives the button back when the handler says nothing was sent', function () {
        // sendMessage() declines while a stream is in flight. Staying disabled there would
        // leave the operator with a dead button and nothing on screen explaining it — the
        // refusal is silent by design, because its other caller keeps its own affordance.
        const card = document.createElement('div');
        const btn = appendRepairAffordance(card, function () { return false; });

        btn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(btn.disabled).toBe(false);
    });

    it('stays disabled when the handler makes no claim, which is the conservative read', function () {
        const card = document.createElement('div');
        const btn = appendRepairAffordance(card, function () { /* returns undefined */ });

        btn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(btn.disabled).toBe(true);
    });

    it('disables before it hands over, so a throwing handler leaves no live button', function () {
        const card = document.createElement('div');
        let seenDisabledInsideHandler = null;
        const btn = appendRepairAffordance(card, function () {
            seenDisabledInsideHandler = btn.disabled;
            throw new Error('handler exploded');
        });

        expect(function () {
            btn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        }).not.toThrow(); // listeners swallow; the state is what matters

        expect(seenDisabledInsideHandler).toBe(true);
        expect(btn.disabled).toBe(true);
    });
});
