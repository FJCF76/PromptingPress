/**
 * The conflict exit's rollback truth (#797).
 *
 * #755 made the batch-failure line stop claiming a clean revert without reading
 * `rollback_errors`, and wired the report into ONE of executeProposal()'s three failure
 * exits. The conflict exit reached its renderer first, rebuilt the card, and made the
 * STRONGER claim over the same channel:
 *
 *     "This page changed while the proposal was pending (another tab, agent, or editor).
 *      Nothing was applied."
 *
 * That sentence is true for the PRE-EXECUTION refusals, which are also routed here — the
 * CAS gate turned the batch away before step 1, so no step ran. It is not reliably true
 * for a batch whose FAILING STEP carried the conflict: earlier steps executed and were
 * rolled back, and the executor does not special-case a conflicting step (it reaches the
 * same failure return and runs the same `_pp_restore_batch_snapshot()` — lib/actions.php).
 * So the envelope carries `rolled_back: true` and a `rollback_errors` list, and the page
 * whose composition restore was WITHHELD (#749) sat there holding mid-batch state while
 * the operator read that nothing had been applied.
 *
 * WHAT IS PINNED HERE, IN TWO KINDS. Most of these fail against the pre-fix source and are
 * the red-proof for the bug. The rest exist so the fix cannot be mistaken for DELETING the
 * sentence: they pass before and after, and they are what stops a later change from buying
 * pin 1 by making the card say nothing at all. Both kinds are wanted, and which is which is
 * said here rather than implied, in a file whose subject is claims that outrun their
 * evidence. The preservation pins are 3, the byte-exact clean claim, the three
 * "conflicts that really did leave nothing behind" blocks, the never-throws pin, the
 * cause-prefix pin, and the same-words-as-#755 pin.
 *
 *   1. "Nothing was applied." now requires an explicitly CLEAN report — present, a list,
 *      empty, and paired with a rollback that happened. That is the red-proof for the bug:
 *      the old renderer said it unconditionally.
 *   2. A non-list or absent channel is an UNKNOWN and says nothing, rather than degrading
 *      to the affirmative. Same non-list discipline #755 closed on its own exit, one
 *      key-preserving `array_filter` upstream from being live.
 *   3. The pre-execution refusals KEEP the claim. They are the reason the payload is
 *      threaded through rather than the claim being dropped outright, and a fix that
 *      simply deleted the sentence would pass a naive version of pin 1 while losing a true
 *      statement on the more common path.
 *   4. Non-empty entries render through the LANDED #755 adapter on this card too, so the
 *      operator is told which page or menu stayed dirty and why. One owner, one heading,
 *      one budget — no second vocabulary for the same fact.
 *   5. The conflict-specific affordance, Re-read & re-preview, survives all of it. This is
 *      additive truth, not a redesign of what the operator may do next.
 *   6. The wiring itself (source tripwire), because showConflictState() lives inside the
 *      IIFE and every pure-helper assertion above passes whether or not it is called.
 *
 * WHY THIS FILE DRIVES THE REAL SURFACE. The bug was a missing call, and a missing call is
 * exactly what helper tests cannot see. The end-to-end blocks below send a message, let the
 * client render and preview a proposal, click Apply, and answer with a real-shaped batch
 * envelope — then read the card the operator would be looking at. The fetch mock is the
 * only seam.
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

const SITE_URL = 'http://conflict.example.com';
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
// baseline for a mutating step.
localStorage.setItem(STORAGE_KEY, JSON.stringify({
    activePageId: PAGE_ID,
    pageBaselines: { 42: 3 },
    conversation: []
}));

// ─── Fixtures ────────────────────────────────────────────────────────────────

/**
 * The #749 producer's real shape: a page whose composition restore was WITHHELD because
 * its stored bytes went unreadable mid-batch. This is the entry that exists precisely to
 * say "this page did not roll back", and the one the conflict card used to discard.
 */
const WITHHELD = 'Page 42: composition data integrity error (decode_error). The stored '
    + '_pp_composition is not a valid composition list — treat as corrupted, not empty. Its '
    + 'composition was NOT rolled back: the stored bytes changed to an unreadable state '
    + 'during this batch, and restoring the snapshot over them would destroy the only '
    + 'recoverable copy. Every other field on this page was rolled back.';

/** The menu layer's producer shape — one entry per item it could not recreate. */
const MENU = 'could not recreate menu item "Contact"';

/**
 * The two producers #854 added, in the shapes _pp_restore_batch_snapshot() builds them
 * (lib/actions.php). They exist here because #854 is the issue that made this card's
 * EMPTY report worth something: until it landed, a batch that created a redirect or
 * imported an attachment reported `rollback_errors: []` while both survived, so the
 * clean claim this file gates was gated on a channel that had never looked. Now the
 * rollback deletes what its own batch created and NAMES whatever it could not — which
 * only helps if the naming reaches the operator, and this is where that is checked.
 *
 * Nothing about the adapter changed to carry them: they are plain strings on the same
 * channel, and they route through the same report, the same heading and the same budget
 * as the two producers above. That is the property pinned below — a new producer needs no
 * new client vocabulary, which is also what keeps #855's later `kind` tagging free to
 * land on the channel rather than on the renderer.
 */
const SURVIVING_REDIRECT = 'The redirect for "/old-launch" was NOT rolled back: the stored '
    + 'redirect map could not be written, so this batch\'s change to that path is still live. '
    + 'Check it with the list_redirects action and undo it by hand.';

const SURVIVING_ATTACHMENT = 'Media item 118 was imported by this batch and could NOT be '
    + 'deleted during the rollback, so it is still in the Media Library. Remove it there if '
    + 'it should not remain.';

const CAUSE = 'This page changed while the proposal was pending (another tab, agent, or editor).';
const CLEAN_CLAIM = 'Nothing was applied.';
const DIRTY_CLAIM = 'Some changes could not be reverted.';

/**
 * A batch whose FAILING step carried the conflict, carrying whatever report it was given.
 * Shaped exactly as pp_ai_execute_batch() returns it on a failed step (lib/actions.php):
 * the earlier step succeeded, the rollback ran, and `versions` is emptied because no
 * page's version survived.
 */
function conflictBatch(rollbackErrors, extra) {
    return Object.assign({
        ok: false,
        steps: [
            { ok: true, action: 'update_component' },
            { ok: false, action: 'update_component', error: 'Version mismatch.', error_code: 'composition_conflict' }
        ],
        failed_at: 1,
        rolled_back: true,
        rollback_errors: rollbackErrors,
        versions: {}
    }, extra || {});
}

/** The pre-execution refusals: an error payload with no `steps`, because none ran. */
const PRE_EXEC_CONFLICT = {
    error: 'This page changed since you last read it.',
    error_code: 'composition_conflict',
    expected_version: 3,
    current_version: 4
};

const PRE_EXEC_MISSING_BASELINE = {
    error: 'This proposal changes a page but is missing that page\'s current version.',
    error_code: 'missing_expected_version'
};

// ─── The seam ────────────────────────────────────────────────────────────────

let calls = [];
let batchResponse = null;

function jsonOk(payload) {
    return Promise.resolve({ ok: true, json: function () { return Promise.resolve(payload); } });
}

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
        description: 'Update the hero subtitle',
        params: { post_id: PAGE_ID, component_index: 1, props: { title: 'Also new' } }
    }
];

global.fetch = function (url, opts) {
    // The SSE path rejects, dropping the client onto ajaxFallback() immediately — the same
    // code path a buffering proxy produces, rendering through the identical renderProposal().
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
        return jsonOk({ success: true, data: { post_id: PAGE_ID, version: 4 } });
    }
    if (action === 'pp_ai_chat') {
        return jsonOk({
            success: true,
            data: {
                content: 'Here is my proposal.',
                proposal: { proposal: true, steps: PROPOSED_STEPS },
                page_baseline: { post_id: PAGE_ID, version: 3 }
            }
        });
    }
    // Loud rather than helpful: an unrecognized action answered with a plausible response
    // is how a seam that measures nothing passes for a green test.
    throw new Error('unexpected action in fetch mock: ' + action);
};

const {
    conflictMessage,
    conflictOutcome,
    rollbackErrorReport,
    rollbackSentence
} = require('../../assets/js/pp-ai-chat.js');

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

/** Sends a message, previews the proposal, clicks Apply, and returns the settled card. */
async function applyAndGetCard() {
    inputEl.value = 'Change the hero';
    sendBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    const card = messagesEl.querySelector('.pp-ai-proposal-card:last-of-type')
        || messagesEl.querySelector('.pp-ai-proposal-card');
    expect(card).not.toBeNull();

    const applyBtn = card.querySelector('.pp-ai-proposal-apply');
    expect(applyBtn).not.toBeNull();
    applyBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    // Premise for every assertion below: this really is the conflict rendering.
    expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(true);
    return card;
}

/** The rollback disclosure section, or null when the card drew none. */
function rollbackSection(card) {
    return card.querySelector('[role="status"]');
}

/** The disclosure's own rows: heading + inline entries, never the <details> body. */
function rowTexts(card) {
    const section = rollbackSection(card);
    if (!section) return [];

    return Array.prototype.slice.call(section.children)
        .filter(function (el) { return el.tagName === 'DIV'; })
        .map(function (el) { return el.textContent; });
}

function messageText(card) {
    return card.querySelector('.pp-ai-status-error').textContent;
}

/** The message as this file's owner would build it, for the pure-state assertions. */
function messageFor(payload) {
    return conflictMessage(payload, rollbackErrorReport(payload));
}

beforeEach(function () {
    calls = [];
    batchResponse = null;
    newChatBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    pageSelectEl.value = String(PAGE_ID);
    pageSelectEl.dispatchEvent(new dom.window.Event('change'));
});

// ─── The claim's state machine ───────────────────────────────────────────────

describe('what the conflict card is allowed to claim', function () {
    test('every answer opens with the cause, which is true on every path here', function () {
        [undefined, 'a bare string', PRE_EXEC_CONFLICT, conflictBatch([]), conflictBatch([MENU])]
            .forEach(function (payload) {
                expect(messageFor(payload).indexOf(CAUSE)).toBe(0);
            });
    });

    // THE BUG. Entries mean a page or a menu is still carrying mid-batch state, so the
    // strongest claim this file makes is unreachable.
    test('drops "Nothing was applied." when the rollback reported anything', function () {
        const msg = messageFor(conflictBatch([WITHHELD]));

        expect(msg).not.toContain(CLEAN_CLAIM);
        expect(msg).toBe(CAUSE + ' ' + DIRTY_CLAIM);
    });

    test('says the same thing for one entry or many', function () {
        expect(messageFor(conflictBatch([MENU, MENU, WITHHELD]))).toBe(CAUSE + ' ' + DIRTY_CLAIM);
    });

    // A channel whose every member is unrenderable still cost the clean claim (#755's
    // `reported`-not-`total` rule): the server said something went wrong, and a filter meant
    // to make rendering safe must not launder that into an all-clear.
    test('costs the clean claim even when nothing in the report can be drawn', function () {
        const msg = messageFor(conflictBatch([{ message: 'x' }, null, '']));

        expect(msg).toBe(CAUSE + ' ' + DIRTY_CLAIM);
    });

    test('keeps the clean claim byte-exact on an explicitly empty report', function () {
        expect(messageFor(conflictBatch([]))).toBe(CAUSE + ' ' + CLEAN_CLAIM);
    });

    // One key-preserving `array_filter` in _pp_restore_batch_snapshot() makes wp_json_encode
    // emit an OBJECT instead of a list, and nothing on either side asserts list-ness. An
    // unknown may not be narrated as a clean revert.
    test('treats an absent or non-list channel as unknown, never as clean', function () {
        [undefined, null, 'not an array', 7, { 0: MENU, length: 1 }].forEach(function (raw) {
            const payload = conflictBatch(raw);
            delete payload.rollback_errors;
            if (raw !== undefined) payload.rollback_errors = raw;

            const msg = messageFor(payload);
            expect(msg).toBe(CAUSE);
            expect(msg).not.toContain(CLEAN_CLAIM);
        });
    });

    // `rollback_errors: []` says the rollback reported no errors. It does not say a rollback
    // HAPPENED, and steps that ran and were never reverted are the one shape where the clean
    // claim would be false over a clean report.
    //
    // Truthiness, not identity, and that is inherited rather than chosen here: the flag is
    // read once by ppChatRollbackErrorReport() as `!!batch.rolled_back` (#755), and this
    // exit reads that report rather than the envelope. Asking a stricter question here would
    // mean a second read of the same field and two answers to one question. The executor
    // sends a real boolean either way (lib/actions.php).
    test('needs a rollback that happened, not just a report with nothing in it', function () {
        expect(messageFor(conflictBatch([], { rolled_back: false }))).toBe(CAUSE);
        expect(messageFor(conflictBatch([], { rolled_back: null }))).toBe(CAUSE);
        const noFlag = conflictBatch([]);
        delete noFlag.rolled_back;
        expect(messageFor(noFlag)).toBe(CAUSE);
    });

    // The pre-execution refusals are why the payload is threaded through at all: dropping
    // the sentence outright would satisfy the bug's headline and lose a true statement on
    // the path an operator hits more often.
    test('keeps the claim for the refusals where nothing ran', function () {
        expect(messageFor(PRE_EXEC_CONFLICT)).toBe(CAUSE + ' ' + CLEAN_CLAIM);
        expect(messageFor(PRE_EXEC_MISSING_BASELINE)).toBe(CAUSE + ' ' + CLEAN_CLAIM);
        // The executor's own spelling of "no step ran" (#749) reads the same way. Spelled
        // with `rolled_back: false` because that is what pp_ai_execute_batch() actually
        // returns for it (lib/actions.php) — nothing ran, so nothing was rolled back. It
        // also matters that the fixture be exact here: carrying `rolled_back: true` would
        // let the LAST arm satisfy this assertion, and the empty-list arm it is written to
        // pin could be deleted with the suite still green.
        expect(messageFor(conflictBatch([], { steps: [], failed_at: null, rolled_back: false })))
            .toBe(CAUSE + ' ' + CLEAN_CLAIM);
    });

    // `steps` IS AS FORGEABLE AS THE CHANNEL BESIDE IT, and this is the arm that decides
    // whether anything ran. `$results` is a JSON list only because it is built with
    // `$results[] =` (lib/actions.php); one key-preserving edit upstream makes it an OBJECT.
    // Reading that as "no steps" would hand the strongest sentence to a batch that ran —
    // the same fail-open the channel already refuses, one field over.
    test('treats a present but unrecognizable steps field as unknown, not as nothing-ran', function () {
        [{}, 'not a list', 7, { 0: { ok: false }, length: 1 }, null].forEach(function (steps) {
            expect(messageFor(conflictBatch([], { steps: steps }))).toBe(CAUSE);
        });
    });

    // AND THE ENTRIES OUTRANK IT. A malformed `steps` beside an intact channel is the
    // payload that matters most: something ran, something did not come back, and the field
    // that would have told us so is the broken one.
    test('reports the entries even when the steps field is the malformed part', function () {
        expect(messageFor(conflictBatch([WITHHELD], { steps: {} })))
            .toBe(CAUSE + ' ' + DIRTY_CLAIM);
        expect(messageFor({ error: 'Page changed.', rolled_back: true, rollback_errors: [MENU] }))
            .toBe(CAUSE + ' ' + DIRTY_CLAIM);
    });

    // Fail-closed, and in the direction that matters: a caller who forgets the evidence
    // loses a true sentence instead of asserting a false one.
    test('claims nothing at all when handed no evidence', function () {
        expect(conflictMessage()).toBe(CAUSE);
        expect(conflictMessage(null, null)).toBe(CAUSE);
        expect(conflictMessage('a bare string', null)).toBe(CAUSE);
        expect(conflictOutcome(undefined, undefined)).toBe('');
    });

    // THE OTHER HALF OF THAT PROMISE. The payload guard catches a caller who forgot both
    // arguments; this catches one who passed a payload and lost the report. A steps-carrying
    // payload is the only input that gets far enough for a missing report to matter, so
    // without these two the fail-closed reads on `report` are never exercised at all.
    test('withholds the claim when the payload arrives without its report', function () {
        expect(conflictOutcome(conflictBatch([]), null)).toBe('');
        expect(conflictOutcome(conflictBatch([]), undefined)).toBe('');
        expect(conflictOutcome(conflictBatch([WITHHELD]), null)).toBe('');
    });

    test('never throws on a malformed payload, whatever shape it arrives in', function () {
        [undefined, null, 0, '', 'x', [], {}, { steps: 'not a list' }, { steps: [{}] }]
            .forEach(function (payload) {
                expect(function () { messageFor(payload); }).not.toThrow();
            });
    });
});

// ─── The card the operator actually reads ────────────────────────────────────

describe('a mid-batch conflict whose rollback left something behind', function () {
    test('names what stayed dirty instead of claiming nothing was applied', async function () {
        batchResponse = { success: true, data: conflictBatch([WITHHELD]) };

        const card = await applyAndGetCard();

        expect(messageText(card)).not.toContain(CLEAN_CLAIM);
        expect(messageText(card)).toContain(DIRTY_CLAIM);
        // The disclosure the emptied card used to have nowhere to put.
        expect(rowTexts(card)).toContain(WITHHELD);
        expect(card.textContent).toContain('Page 42');
    });

    test('counts the report in its heading, exactly as the failure card does', async function () {
        batchResponse = { success: true, data: conflictBatch([MENU, WITHHELD]) };

        const card = await applyAndGetCard();

        expect(rowTexts(card)[0]).toBe('⚠ 2 changes could not be reverted:');
    });

    // Reused, not re-implemented: the rows go through ppChatAppendValidationItems, so the
    // five-inline-plus-disclosure selection and the #667 never-open-on-empty rule are
    // inherited on this card rather than coined again.
    test('hands a long report to the landed adapter, disclosure and all', async function () {
        const many = [];
        for (let i = 0; i < 9; i++) many.push('could not recreate menu item "Item ' + i + '"');
        batchResponse = { success: true, data: conflictBatch(many) };

        const card = await applyAndGetCard();

        const details = card.querySelector('details');
        expect(details).not.toBeNull();
        expect(details.open).toBe(false);
        expect(rowTexts(card)).toHaveLength(6); // heading + five inline
        expect(details.textContent).toContain('Item 8');
    });

    // Additive truth, not a redesign. Whether re-reading is the right offer when a page's
    // bytes cannot be read is a repair-route question, and it travels with #756 / #767.
    test('keeps the Re-read & re-preview affordance, above nothing and below the report', async function () {
        batchResponse = { success: true, data: conflictBatch([WITHHELD]) };

        const card = await applyAndGetCard();

        const actions = card.querySelector('.pp-ai-proposal-actions');
        expect(actions).not.toBeNull();
        expect(actions.textContent).toContain('Re-read & re-preview');

        // ORDER IS CONTRACTUAL. ppChatAppendRollbackErrors() places itself by inserting
        // before `.pp-ai-proposal-actions` and falls back to appendChild — grow the card in
        // the wrong order and the explanation lands underneath the button it explains.
        const order = Array.prototype.slice.call(card.children);
        expect(order.indexOf(card.querySelector('.pp-ai-status-error'))).toBe(0);
        expect(order.indexOf(rollbackSection(card))).toBe(1);
        expect(order.indexOf(actions)).toBe(2);
    });

    test('announces the disclosure politely, without a second alert', async function () {
        batchResponse = { success: true, data: conflictBatch([MENU]) };

        const card = await applyAndGetCard();

        expect(rollbackSection(card).getAttribute('aria-live')).toBe('polite');
        expect(card.querySelectorAll('[role="alert"]')).toHaveLength(1);
    });

    // The end-to-end twin of the non-list pin: an unknown channel reaches the real card and
    // still does not produce an all-clear.
    test('makes no clean claim through the real card when the channel is unreadable', async function () {
        batchResponse = { success: true, data: conflictBatch({ 0: MENU }) };

        const card = await applyAndGetCard();

        expect(messageText(card)).toBe(CAUSE);
        expect(rollbackSection(card)).toBeNull();
        expect(card.textContent).toContain('Re-read & re-preview');
    });
});

// ─── #854's producers on the same channel ────────────────────────────────────

describe('a rollback that could not remove what the batch created', function () {
    test('names the surviving redirect and withholds the clean claim', async function () {
        batchResponse = { success: true, data: conflictBatch([SURVIVING_REDIRECT]) };

        const card = await applyAndGetCard();

        expect(messageText(card)).not.toContain(CLEAN_CLAIM);
        expect(messageText(card)).toContain(DIRTY_CLAIM);
        expect(rowTexts(card)).toContain(SURVIVING_REDIRECT);
        // The operator is told WHICH path is still live, not merely that something is.
        expect(card.textContent).toContain('/old-launch');
    });

    test('names the surviving attachment and withholds the clean claim', async function () {
        batchResponse = { success: true, data: conflictBatch([SURVIVING_ATTACHMENT]) };

        const card = await applyAndGetCard();

        expect(messageText(card)).not.toContain(CLEAN_CLAIM);
        expect(messageText(card)).toContain(DIRTY_CLAIM);
        expect(rowTexts(card)).toContain(SURVIVING_ATTACHMENT);
        expect(card.textContent).toContain('Media item 118');
    });

    // ONE HEADING, ONE BUDGET, WHICHEVER PRODUCERS FILLED IT. The server enumerates seven
    // distinct sentences on this channel (_pp_restore_batch_snapshot, lib/actions.php);
    // three of them are fixtured in this file. The card's count is of ENTRIES, not of
    // kinds, so a mixed report reads as one list rather than as sections per producer —
    // which is what lets #855 add a `kind` to the entries later without the card growing a
    // second shape, and why no number here needs to track the server's.
    test('counts a mixed report as one list, whichever producers filled it', async function () {
        batchResponse = {
            success: true,
            data: conflictBatch([WITHHELD, SURVIVING_REDIRECT, SURVIVING_ATTACHMENT])
        };

        const card = await applyAndGetCard();

        expect(rowTexts(card)[0]).toBe('⚠ 3 changes could not be reverted:');
        expect(rowTexts(card)).toContain(SURVIVING_REDIRECT);
        expect(rowTexts(card)).toContain(SURVIVING_ATTACHMENT);
    });

    // The transcript line and the card are one fact in one dialect (#755/#797), and a new
    // producer must not be the thing that splits them.
    test('says the same dirty half in the transcript line', function () {
        const payload = conflictBatch([SURVIVING_REDIRECT]);

        expect(rollbackSentence(rollbackErrorReport(payload)))
            .toBe(' — some changes could not be reverted.');
    });
});

describe('the conflicts that really did leave nothing behind', function () {
    test('a clean mid-batch rollback still says nothing was applied, and draws no section', async function () {
        batchResponse = { success: true, data: conflictBatch([]) };

        const card = await applyAndGetCard();

        expect(messageText(card)).toBe(CAUSE + ' ' + CLEAN_CLAIM);
        expect(rollbackSection(card)).toBeNull();
        expect(card.textContent).toContain('Re-read & re-preview');
    });

    test('a pre-execution conflict keeps the claim it always earned', async function () {
        batchResponse = { success: false, data: PRE_EXEC_CONFLICT };

        const card = await applyAndGetCard();

        expect(messageText(card)).toBe(CAUSE + ' ' + CLEAN_CLAIM);
        expect(rollbackSection(card)).toBeNull();
        expect(card.textContent).toContain('Re-read & re-preview');
    });

    test('a missing baseline is the same story and gets the same card', async function () {
        batchResponse = { success: false, data: PRE_EXEC_MISSING_BASELINE };

        const card = await applyAndGetCard();

        expect(messageText(card)).toBe(CAUSE + ' ' + CLEAN_CLAIM);
        expect(rollbackSection(card)).toBeNull();
    });
});

/**
 * SOURCE TRIPWIRE — the wiring, not the helpers (#755's pattern, #797's exit).
 *
 * showConflictState() lives inside the IIFE, so the properties that survive a refactor
 * unnoticed are checked by reading the source: that both call sites hand over their
 * evidence, and that the channel is walked ONCE per render so the message and the section
 * cannot disagree about what the rollback reported.
 */
describe('conflict-exit wiring', function () {
    const JS = fs.readFileSync(path.resolve(__dirname, '../../assets/js/pp-ai-chat.js'), 'utf-8');

    // Comments are stripped first: the docblocks quote both claims to explain when they may
    // be made, and a docblock is not a second place a sentence can be emitted from.
    const CODE = JS.replace(/\/\*[\s\S]*?\*\//g, '');

    function conflictRenderer() {
        const start = CODE.indexOf('function showConflictState(');
        expect(start).toBeGreaterThan(-1);
        const end = CODE.indexOf('function buildPostApplyCard(', start);
        expect(end).toBeGreaterThan(start);
        return CODE.slice(start, end);
    }

    // Counted as the STRING LITERAL, quotes and leading space included. Prose that discusses
    // a claim (`// ... "Nothing was applied." rests on ...`) is not a place it can be emitted
    // from, and line comments survive the block strip above.
    it('owns each claim in exactly one place', function () {
        expect(CODE.split("' Nothing was applied.'").length - 1).toBe(1);
        expect(CODE.split("' Some changes could not be reverted.'").length - 1).toBe(1);
        expect(CODE.indexOf("' Nothing was applied.'"))
            .toBeLessThan(CODE.indexOf('function ppChatConflictOutcome('));
    });

    // ONE FACT, ONE DIALECT. The conflict card and the transcript line describe the same
    // rollback in different grammar: this exit needs a standalone SENTENCE, #755's exit
    // needs a CLAUSE continuing "Error on step N: ...". The docblock says they are
    // deliberately the same words, and two dialects for one fact is how a reader learns to
    // distrust both — so the promise is checked rather than asserted in prose. Compared
    // after normalising away the parts that legitimately differ: the separator and the
    // opening capital.
    it('says the dirty half in the same words as the transcript line', function () {
        const normalise = function (s) {
            return s.replace(/^[\s—-]+/, '').toLowerCase();
        };

        expect(normalise(DIRTY_CLAIM))
            .toBe(normalise(rollbackSentence(rollbackErrorReport(conflictBatch([MENU])))));
    });

    it('passes the evidence from every call site, so none can default into the claim', function () {
        // Three mentions: the declaration and its two callers. A fourth means a new call
        // site arrived, and this test is where it gets checked for its evidence argument.
        expect(CODE.split('showConflictState(').length - 1).toBe(3);

        expect(CODE).toContain('function showConflictState(card, steps, pageId, payload)');
        expect(CODE).toContain('showConflictState(card, steps, pageId, resp.data)');
        expect(CODE).toContain('showConflictState(card, steps, pageId, batch)');
        // A bare three-argument call would silently withhold the claim; catch it as a typo
        // rather than as a mystery missing sentence.
        expect(CODE).not.toContain('showConflictState(card, steps, pageId);');
    });

    it('walks the channel once and hands the same report to both surfaces', function () {
        const renderer = conflictRenderer();

        expect(renderer.split('ppChatRollbackErrorReport(').length - 1).toBe(1);
        expect(renderer).toContain('ppChatConflictMessage(payload, rollback)');
        expect(renderer).toContain('ppChatAppendRollbackErrors(card, rollback)');
    });

    // The disclosure is placed by insertBefore('.pp-ai-proposal-actions'), so the action row
    // has to exist first. Deliberately sliced to the end of the renderer, not to the append
    // call, so a swap cannot move one of the two out of the window and pass vacuously.
    it('builds the action row before growing the card with the report', function () {
        const renderer = conflictRenderer();

        const actionsAt = renderer.indexOf("card.appendChild(actions)");
        const appendAt = renderer.indexOf('ppChatAppendRollbackErrors(card, rollback)');

        expect(actionsAt).toBeGreaterThan(-1);
        expect(appendAt).toBeGreaterThan(actionsAt);
    });
});
