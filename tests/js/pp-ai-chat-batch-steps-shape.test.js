/**
 * The shape of `batch.steps` is checked before it is walked (#853).
 *
 * executeProposal() opened by running `batch.steps.forEach`, and that one line stood ahead
 * of every exit below it. A non-list `steps` threw there; the throw lands in the promise
 * chain's catch, which renders `err.message` straight into the transcript. So the operator
 * got
 *
 *     Error: batch.steps.forEach is not a function
 *
 * in place of whichever card the envelope was actually asking for — including the rollback
 * report, whose own docblock exists to stop exactly "a stack-shaped string" replacing it.
 *
 * WHAT THIS FILE PINS, IN TWO KINDS, said here rather than implied because the two are
 * easy to confuse and only one of them is red against the pre-fix source.
 *
 *   RED-PROOF — these fail before the guard, because the chain throws:
 *     1. No non-list shape reaches the chain's catch. The transcript never carries a
 *        TypeError's words and no row is left spinning under an error line.
 *     2. Each of the three failure exits is REACHABLE on a non-list envelope, and renders
 *        its own landed answer: the conflict card (#797), the up-front refusal (#749), and
 *        the executed-failure line WITH its rollback report (#755/#797). The report is the
 *        point: it reads `rollback_errors`, never `steps`, so it survives an unreadable
 *        envelope whole and the operator still learns what stayed dirty.
 *     3. An envelope carrying more step results than the card rendered rows does not throw
 *        either — same line, same class, named in the issue body.
 *
 *   PRESERVATION — these pass before AND after, and they are what stops a later change
 *   from buying the pins above by making the guard swallow real envelopes:
 *     4. List-shaped envelopes behave exactly as they did: a success applies and reports,
 *        a conflict still claims "Nothing was applied." when its report is clean, and an
 *        executed failure still quotes the failing step's own reason.
 *     5. The success exit is the one exit that must REFUSE an unreadable envelope. Its
 *        two quietest properties — nothing written into the model's context claiming an
 *        apply, no version map adopted from an envelope nobody could parse — are pinned
 *        as PRESERVATION, because the old throw happened to deliver them too, by accident
 *        rather than by decision. What is red is the refusal itself: a stated unknown and
 *        rows that stopped spinning, in place of the TypeError.
 *
 * MEASURED, not asserted, and the reds are not all the same strength — said plainly, because
 * a file about claims outrunning their evidence is the wrong place to round a number up. Run
 * against the pre-fix source: 44 of the 52 blocks go red, 8 stay green.
 *
 *   38 BEHAVIOURAL reds, which are the real proof. Each drives executeProposal() through the
 *      real surface. Most fail with one of the two literals the operator actually saw —
 *      `Error: batch.steps.forEach is not a function` for an object, string, number or
 *      array-like, and `Error: Cannot read properties of null (reading 'forEach')` and its
 *      relatives for a null envelope, a falsy member, or an index past the rendered rows,
 *      depending on which read blew up first.
 *   1  of those 38 is not a crash at all, and is the one worth reading twice: with a `steps`
 *      planted on `Object.prototype`, the pre-fix client WALKED the planted list and finished
 *      with `✓ Applied: Update the hero subtitle`. A silent false apply, not an error.
 *   6  NEW-SYMBOL reds, the weakest kind: the ppChatBatchStepsReadable and ppChatMarkStepsFailed
 *      blocks fail only because those exports did not exist yet. Worth having, worth not
 *      counting as evidence of the fix.
 *   8  GREEN, the preservation pins: the four list-shaped ones plus the accidental properties
 *      named in 5.
 *
 * One assertion is a forward guard rather than a red-proof, and is marked as such where it
 * lives: ppChatConflictOutcome()'s own non-list arm shipped in #797 and already answered
 * correctly, so only the predicate half of the divergence pin is load-bearing today.
 *
 * WHY THIS FILE DRIVES THE REAL SURFACE. executeProposal() is IIFE-local and the bug was a
 * missing check inside it, so every pure-helper assertion about `steps` passes whether or
 * not the function ever asks the question. The blocks below send a message, let the client
 * render and preview a proposal, click Apply, and answer with a hand-shaped envelope — then
 * read the transcript and the card the operator would be looking at. The fetch mock is the
 * only seam. Same harness shape as pp-ai-chat-conflict-rollback.test.js, deliberately.
 *
 * NO SOURCE-TEXT TRIPWIRE HERE, and that is a choice. Every pin above is behavioural, so
 * reformatting the guard, renaming the local, or moving the predicate cannot fire any of
 * them — only deleting the behaviour can. A test that goes red on a rename teaches
 * maintainers to delete tripwires, which is the opposite of what a tripwire is for.
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

const SITE_URL = 'http://shape.example.com';
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

localStorage.setItem(STORAGE_KEY, JSON.stringify({
    activePageId: PAGE_ID,
    pageBaselines: { 42: 3 },
    conversation: []
}));

// ─── Fixtures ────────────────────────────────────────────────────────────────

/**
 * Every non-list value the issue's table names, plus the absent key.
 *
 * `{0: ..., length: 1}` is the array-like: it has a length and indexes like a list, which
 * is precisely the shape a `typeof`/`length` test would wave through. Only Array.isArray
 * separates it from the real thing.
 */
const NON_LISTS = [
    ['an object', {}],
    ['an object with numeric keys', { 0: { ok: true } }],
    ['an array-like', { 0: { ok: true }, length: 1 }],
    ['a string', 'not a list'],
    ['a number', 7],
    ['null', null]
];

const CAUSE = 'This page changed while the proposal was pending (another tab, agent, or editor).';
const CLEAN_CLAIM = 'Nothing was applied.';
const DIRTY_CLAIM = 'some changes could not be reverted.';
const MENU = 'could not recreate menu item "Contact"';

/** The words a TypeError from either throw site puts in the transcript. */
const STACK_SHAPED = ['is not a function', 'Cannot read propert', 'undefined', 'TypeError'];

// ─── The seam ────────────────────────────────────────────────────────────────

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
    if (url === dom.window.ppAiChat.streamUrl) {
        return Promise.reject(new Error('no stream here'));
    }

    const body = opts && opts.body;
    const action = body && typeof body.get === 'function' ? body.get('action') : 'chat-fallback';

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
                // CLONED PER RESPONSE, because executeProposal() WRITES to the step objects
                // it is handed (`step._validation`, `step._staleWarnings`). Serving the same
                // objects to every block would let one block's envelope leave state on the
                // next block's fixture — order-dependent by construction, and silent until a
                // fixture starts carrying `validation`.
                proposal: { proposal: true, steps: JSON.parse(JSON.stringify(PROPOSED_STEPS)) },
                page_baseline: { post_id: PAGE_ID, version: 3 }
            }
        });
    }
    throw new Error('unexpected action in fetch mock: ' + action);
};

const {
    batchStepsReadable,
    markStepsFailed,
    conflictOutcome,
    rollbackErrorReport
} = require('../../assets/js/pp-ai-chat.js');

// ─── Harness ─────────────────────────────────────────────────────────────────

const messagesEl = document.getElementById('pp-ai-messages');
const inputEl = document.getElementById('pp-ai-input');
const sendBtn = document.getElementById('pp-ai-send');
const newChatBtn = document.getElementById('pp-ai-new-chat');
const pageSelectEl = document.getElementById('pp-ai-page-select');

async function settle() {
    for (let i = 0; i < 60; i++) await Promise.resolve();
}

/** Sends a message, previews the proposal, clicks Apply, and returns the settled card. */
async function applyAndGetCard() {
    inputEl.value = 'Change the hero';
    sendBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    // An explicit last-match, not `:last-of-type` — that pseudo-class matches on ELEMENT
    // type among siblings, so on a transcript whose last div is not a proposal card it
    // selects nothing and a fallback would quietly hand back the FIRST card instead.
    const cards = messagesEl.querySelectorAll('.pp-ai-proposal-card');
    expect(cards.length).toBeGreaterThan(0);
    const card = cards[cards.length - 1];

    const applyBtn = card.querySelector('.pp-ai-proposal-apply');
    expect(applyBtn).not.toBeNull();
    applyBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    return card;
}

/** Every status line in the transcript, in order. */
function statusTexts() {
    return Array.prototype.slice.call(messagesEl.querySelectorAll('.pp-ai-status'))
        .map(function (el) { return el.textContent; });
}

/** The last status line, which is the one the apply produced. */
function lastStatus() {
    const all = statusTexts();
    return all.length ? all[all.length - 1] : '';
}

/** The conversation as the client persisted it, which is what the model will be sent. */
function storedConversation() {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    return JSON.parse(raw).conversation || [];
}

function stepRows(card) {
    return Array.prototype.slice.call(card.querySelectorAll('.pp-ai-proposal-step'));
}

/**
 * The premise every red-proof block rests on: nothing threw into the chain's catch, and
 * no row was abandoned mid-flight.
 *
 * The catch renders `err.message`, so a TypeError's words appearing in the transcript is
 * the bug's exact signature. The spinning-row half matters independently: a card whose
 * rows never leave `pp-ai-step-executing` sits under an error line spinning forever, which
 * is the failure the #749 exit already carries a comment about.
 */
function expectNoStackShapedString(card) {
    const status = lastStatus();
    STACK_SHAPED.forEach(function (fragment) {
        expect(status).not.toContain(fragment);
    });
    expect(card.querySelectorAll('.pp-ai-step-executing').length).toBe(0);
}

beforeEach(function () {
    batchResponse = null;
    newChatBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    pageSelectEl.value = String(PAGE_ID);
    pageSelectEl.dispatchEvent(new dom.window.Event('change'));
});

// ─── The predicate ───────────────────────────────────────────────────────────

describe('ppChatBatchStepsReadable', function () {

    it('accepts a real list, empty or not', function () {
        expect(batchStepsReadable({ steps: [] })).toBe(true);
        expect(batchStepsReadable({ steps: [{ ok: true }] })).toBe(true);
    });

    it('rejects every non-list the issue names, the array-like included', function () {
        NON_LISTS.forEach(function (pair) {
            expect(batchStepsReadable({ steps: pair[1] })).toBe(false);
        });
    });

    it('rejects an envelope with no steps key, and an absent envelope', function () {
        expect(batchStepsReadable({ ok: true })).toBe(false);
        expect(batchStepsReadable(null)).toBe(false);
        expect(batchStepsReadable(undefined)).toBe(false);
    });

    // wp-admin loads third-party JS in this realm. An INHERITED `steps` is not evidence
    // about this envelope, and reading one would walk rows the server never sent — past the
    // refusal, into a success card and an applied-changes turn written to the model. Same
    // own-property idiom ppChatConflictOutcome() already uses on this field.
    it('does not accept a steps planted on Object.prototype', function () {
        Object.prototype.steps = [{ ok: true }];
        try {
            expect(batchStepsReadable({ ok: true })).toBe(false);
        } finally {
            delete Object.prototype.steps;
        }
    });

    // The whole reason the predicate exists rather than a second inline Array.isArray:
    // the renderer and the classifier must answer the same question about the same field
    // (#667). Asked here as a property of the pair, not of either one's spelling.
    //
    // A FORWARD GUARD, NOT A RED-PROOF. ppChatConflictOutcome()'s half already answered
    // correctly before #853 (its own `Array.isArray` arm shipped with #797), so what this
    // pins is that the two cannot DRIFT apart later — not that either was wrong today.
    it('gives ppChatConflictOutcome the same answer it acts on', function () {
        NON_LISTS.forEach(function (pair) {
            const payload = { ok: false, steps: pair[1], failed_at: 0 };

            expect(batchStepsReadable(payload)).toBe(false);
            expect(conflictOutcome(payload, rollbackErrorReport(payload))).toBe('');
        });
    });
});

describe('ppChatMarkStepsFailed', function () {

    it('takes every row out of the executing state, whatever it was carrying', function () {
        const rows = ['pp-ai-step-executing', 'pp-ai-step-done', ''].map(function (cls) {
            const el = document.createElement('div');
            if (cls) el.className = cls;
            return el;
        });

        markStepsFailed(rows);

        rows.forEach(function (el) {
            expect(el.classList.contains('pp-ai-step-executing')).toBe(false);
            expect(el.classList.contains('pp-ai-step-failed')).toBe(true);
        });
    });
});

// ─── RED-PROOF 1: no shape reaches the chain's catch ─────────────────────────

describe('a non-list steps never renders a stack-shaped string', function () {

    NON_LISTS.forEach(function (pair) {
        const label = pair[0];
        const shape = pair[1];

        it('survives ' + label + ' on an executed failure', async function () {
            batchResponse = {
                success: true,
                data: { ok: false, steps: shape, failed_at: 0, rolled_back: true, rollback_errors: [], versions: {} }
            };

            expectNoStackShapedString(await applyAndGetCard());
        });

        it('survives ' + label + ' on a batch claiming success', async function () {
            batchResponse = { success: true, data: { ok: true, steps: shape, versions: { 42: 5 } } };

            expectNoStackShapedString(await applyAndGetCard());
        });

        it('survives ' + label + ' on an up-front refusal', async function () {
            batchResponse = {
                success: true,
                data: { ok: false, steps: shape, failed_at: null, error: 'Refused before step 1.', rolled_back: false, rollback_errors: [] }
            };

            expectNoStackShapedString(await applyAndGetCard());
        });
    });

    // The envelope with no `steps` key at all, and the one with no data at all. Both used
    // to throw on the same line for the same reason.
    it('survives an envelope with no steps key', async function () {
        batchResponse = { success: true, data: { ok: false, failed_at: null, error: 'No steps key here.' } };

        expectNoStackShapedString(await applyAndGetCard());
    });

    it('survives a success envelope carrying no data at all', async function () {
        batchResponse = { success: true };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        // Not just "no TypeError": the REFUSAL is what has to have fired. Without these the
        // block passes on any exit that happens not to throw, including one that quietly
        // claims something about steps that were never sent.
        expect(lastStatus()).toBe('Error: Unknown error');
        stepRows(card).forEach(function (row) {
            expect(row.classList.contains('pp-ai-step-failed')).toBe(true);
            expect(row.classList.contains('pp-ai-step-skipped')).toBe(false);
        });
        storedConversation().forEach(function (msg) {
            if (typeof msg.content !== 'string') return;
            expect(msg.content.indexOf('[Applied changes:')).toBe(-1);
        });
    });

    // A truthy NON-OBJECT envelope. It has no `failed_at` to state anything about itself, so
    // ppChatBatchWasRefusedUpFront() would read its undefined one as the #749 refusal shape
    // and paint every row "never ran" — a confident per-step claim over nothing at all. The
    // refusal's typeof arm is what stops that.
    [['a string', 'boom'], ['a number', 7], ['a boolean', true]].forEach(function (pair) {
        it('refuses ' + pair[0] + ' as an envelope rather than claiming no step ran', async function () {
            batchResponse = { success: true, data: pair[1] };

            const card = await applyAndGetCard();
            expectNoStackShapedString(card);

            expect(lastStatus()).toBe('Error: Unknown error');
            stepRows(card).forEach(function (row) {
                expect(row.classList.contains('pp-ai-step-skipped')).toBe(false);
                expect(row.classList.contains('pp-ai-step-failed')).toBe(true);
            });
        });
    });

    // The server's own reason, when the unreadable envelope carries one. Every other refusal
    // block here feeds an envelope with no `error`, so without this one the whole arm could
    // be replaced by a hardcoded 'Unknown error' and both suites would stay green.
    it('renders the server\'s own reason when the unreadable envelope carries one', async function () {
        batchResponse = {
            success: true,
            data: { ok: true, steps: {}, error: 'Executor could not summarise this batch.', versions: { 42: 5 } }
        };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        expect(lastStatus()).toBe('Error: Executor could not summarise this batch.');
    });

    it('bounds that reason like every other reflected span (#793)', async function () {
        const huge = 'E'.repeat(5000);
        batchResponse = { success: true, data: { ok: true, steps: {}, error: huge, versions: { 42: 5 } } };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        // The bound's own budget is #793's to set; what this pins is that the span passes
        // THROUGH it, so an unbounded server string cannot reach the transcript whole.
        expect(lastStatus().length).toBeLessThan(huge.length);
    });
});

// ─── RED-PROOF: rows the envelope did not account for ────────────────────────

describe('a readable list shorter than the failure it reports', function () {

    // THE ONE THE PRE-FIX CODE GOT RIGHT BY ACCIDENT. `steps[1]` was undefined, `.error`
    // threw, and the chain's catch swept every row on its way past. Making that read
    // null-safe removed the crash AND the cleanup, so row 1 sat spinning under "Error on
    // step 2" until ppChatFinishSpinningSteps() was asked for on purpose.
    it('leaves no row spinning', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: true }],
                failed_at: 1,
                rolled_back: true,
                rollback_errors: [MENU],
                versions: {}
            }
        };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        expect(lastStatus()).toContain('Error on step 2:');
        expect(lastStatus()).toContain(DIRTY_CLAIM);
    });

    // Terminalize, do not repaint: the row the envelope DID answer keeps its answer.
    it('keeps the answered row done and finishes only the unanswered one', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: true }],
                failed_at: 1,
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        const card = await applyAndGetCard();
        const rows = stepRows(card);

        expect(rows[0].classList.contains('pp-ai-step-done')).toBe(true);
        expect(rows[0].classList.contains('pp-ai-step-failed')).toBe(false);
        expect(rows[1].classList.contains('pp-ai-step-failed')).toBe(true);
    });
});

// ─── RED-PROOF 2: each failure exit is reachable ─────────────────────────────

describe('the failure exits the throw used to mask', function () {

    // #797 built this arm and its docblock recorded that it could not be reached through
    // the UI, because the forEach threw first. This is the pin that it is live: the exit is
    // entered (ppChatBatchHitConflict indexes `steps[failed_at]`, which an object answers)
    // and the card ends on the cause with NO outcome claim, because a non-list channel is
    // an unknown rather than an all-clear.
    it('reaches the conflict card, which then claims nothing it cannot evidence', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: { 0: { ok: false, error: 'Version mismatch.', error_code: 'composition_conflict' } },
                failed_at: 0,
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(true);

        const msg = card.querySelector('.pp-ai-status-error').textContent;
        expect(msg).toContain(CAUSE);
        expect(msg).not.toContain(CLEAN_CLAIM);
        expect(msg).toBe(CAUSE);

        // The affordance is the exit's, not this guard's to change (#797).
        expect(card.textContent).toContain('Re-read & re-preview');
    });

    // #749's exit reads `batch.error` and never touches `steps`, so it was masked purely
    // by the line above it.
    it('reaches the up-front refusal, carrying the server\'s own reason', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: {},
                failed_at: null,
                error: 'This page\'s stored composition cannot be read.',
                rolled_back: false,
                rollback_errors: []
            }
        };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        expect(lastStatus()).toBe('Error: This page\'s stored composition cannot be read.');

        // No step ran, so every row is skipped — the exit's own answer, unchanged.
        stepRows(card).forEach(function (row) {
            expect(row.classList.contains('pp-ai-step-skipped')).toBe(true);
        });
    });

    // THE ONE THAT MATTERS MOST. ppChatRollbackErrorReport()'s docblock says a throw in
    // this neighbourhood "would replace the honest report with a stack-shaped string, i.e.
    // lose exactly the information this exists to deliver" — and that is precisely what
    // happened, one line earlier. The report reads `rollback_errors`, not `steps`, so an
    // unreadable envelope costs the failing step's QUOTE and nothing else.
    it('reaches the executed-failure exit with its rollback report intact', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: {},
                failed_at: 1,
                rolled_back: true,
                rollback_errors: [MENU],
                versions: {}
            }
        };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        const status = lastStatus();
        expect(status).toContain('Error on step 2:');
        expect(status).toContain(DIRTY_CLAIM);

        // The disclosure names what stayed dirty, through the landed #755 adapter.
        const section = card.querySelector('[role="status"]');
        expect(section).not.toBeNull();
        expect(section.textContent).toContain(MENU);
    });

    // Same exit, clean channel: the guard must not invent a rollback complaint either.
    it('keeps the clean revert sentence when the rollback channel says so', async function () {
        batchResponse = {
            success: true,
            data: { ok: false, steps: {}, failed_at: 0, rolled_back: true, rollback_errors: [], versions: {} }
        };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        expect(lastStatus()).toContain('all changes in this proposal have been reverted.');
    });
});

describe('a batch claiming success over an inherited steps', function () {

    // The end-to-end half of the predicate pin above: the refusal has to hold when the
    // planted list is the ONLY thing that would make the envelope look walkable.
    it('is refused, not walked, when Object.prototype carries the list', async function () {
        Object.prototype.steps = [{ ok: true }, { ok: true }];
        try {
            batchResponse = { success: true, data: { ok: true, versions: { 42: 5 } } };

            const card = await applyAndGetCard();
            expectNoStackShapedString(card);

            expect(lastStatus()).toBe('Error: Unknown error');
            storedConversation().forEach(function (msg) {
                if (typeof msg.content !== 'string') return;
                expect(msg.content.indexOf('[Applied changes:')).toBe(-1);
            });
        } finally {
            delete Object.prototype.steps;
        }
    });
});

// ─── A readable list whose MEMBERS are not ───────────────────────────────────

describe('a readable list carrying an unreadable member', function () {

    // JSON cannot express a hole, but it expresses `null` freely, so `[null, {...}]` is a
    // shape the wire can actually deliver. `stepResult.ok` threw on it before; a member that
    // says nothing is now read the way any non-ok result is.
    // The container's shape is only half the question. NON_LISTS varies the container; these
    // vary the MEMBER, which is the same defect one level down — `stepResult.ok` on a falsy
    // member threw into the same catch and rendered the same stack-shaped string.
    [['null', null], ['undefined', undefined], ['a string', 'oops'], ['a number', 0]].forEach(function (pair) {
        it('does not throw on ' + pair[0] + ' as the first member', async function () {
            batchResponse = {
                success: true,
                data: { ok: true, steps: [pair[1], { ok: true }], versions: { 42: 5 } }
            };

            const card = await applyAndGetCard();
            expectNoStackShapedString(card);

            // A member that says nothing is not a member that says "ok".
            const applied = storedConversation().filter(function (m) {
                return typeof m.content === 'string' && m.content.indexOf('[Applied changes:') === 0;
            });
            expect(applied).toHaveLength(1);
            expect(applied[0].content).not.toContain('Update the hero title');
        });
    });

    it('marks the unreadable row failed instead of throwing', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [null, { ok: false, error: 'Second step failed.' }],
                failed_at: 1,
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        const rows = stepRows(card);
        expect(rows[0].classList.contains('pp-ai-step-failed')).toBe(true);
        expect(rows[0].classList.contains('pp-ai-step-done')).toBe(false);
        expect(lastStatus()).toContain('Error on step 2: Second step failed.');
    });

    it('never counts an unreadable member as applied', async function () {
        batchResponse = {
            success: true,
            data: { ok: true, steps: [null, null], versions: { 42: 5 } }
        };

        await applyAndGetCard();

        const applied = storedConversation().filter(function (m) {
            return typeof m.content === 'string' && m.content.indexOf('[Applied changes:') === 0;
        });
        expect(applied).toHaveLength(1);
        expect(applied[0].content).toBe('[Applied changes: ]');
    });
});

// ─── RED-PROOF 3: more results than rendered rows ────────────────────────────

describe('an envelope with more step results than the card has rows', function () {

    it('does not throw, and applies only the steps that exist', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: true,
                steps: [{ ok: true }, { ok: true }, { ok: true }],
                versions: { 42: 5 }
            }
        };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        const applied = storedConversation().filter(function (m) {
            return typeof m.content === 'string' && m.content.indexOf('[Applied changes:') === 0;
        });
        expect(applied).toHaveLength(1);
        // Two proposed steps, so two descriptions — never a third invented from an index
        // the card never rendered.
        expect(applied[0].content).toBe('[Applied changes: Update the hero title; Update the hero subtitle]');
    });
});

// ─── PRESERVATION 5: the success exit refuses what it cannot read ────────────

describe('a batch claiming success over an unreadable steps', function () {

    it('is refused rather than narrated as an apply', async function () {
        batchResponse = { success: true, data: { ok: true, steps: {}, versions: { 42: 5 } } };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        expect(lastStatus()).toBe('Error: Unknown error');

        // finalizeProposalSuccess() would have wiped the card and drawn a post-apply
        // summary; the rows are still there, and they are not claiming success.
        expect(stepRows(card).length).toBe(2);
        stepRows(card).forEach(function (row) {
            expect(row.classList.contains('pp-ai-step-done')).toBe(false);
            expect(row.classList.contains('pp-ai-step-failed')).toBe(true);
        });
    });

    // The half the operator cannot see, and the more dangerous one: an apply confirmation
    // written into the model's context is a claim the next turn reasons from.
    it('writes no applied-changes turn into the conversation', async function () {
        batchResponse = { success: true, data: { ok: true, steps: { 0: { ok: true } }, versions: { 42: 5 } } };

        await applyAndGetCard();

        storedConversation().forEach(function (msg) {
            if (typeof msg.content !== 'string') return;
            expect(msg.content.indexOf('[Applied changes:')).toBe(-1);
            expect(msg.content).not.toBe('Changes applied successfully.');
        });
    });

    it('leaves the stored baseline alone rather than trusting the envelope\'s versions', async function () {
        batchResponse = { success: true, data: { ok: true, steps: 'not a list', versions: { 42: 99 } } };

        await applyAndGetCard();

        const stored = JSON.parse(localStorage.getItem(STORAGE_KEY));
        expect(stored.pageBaselines[42]).not.toBe(99);
    });
});

// ─── PRESERVATION 4: list-shaped envelopes are untouched ─────────────────────

describe('a list-shaped envelope behaves exactly as it did', function () {

    it('applies a successful batch and reports it to the model', async function () {
        batchResponse = {
            success: true,
            data: { ok: true, steps: [{ ok: true }, { ok: true }], versions: { 42: 5 } }
        };

        const card = await applyAndGetCard();
        expectNoStackShapedString(card);

        const applied = storedConversation().filter(function (m) {
            return typeof m.content === 'string' && m.content.indexOf('[Applied changes:') === 0;
        });
        expect(applied).toHaveLength(1);

        // The version map is trusted, because this envelope could be read.
        const stored = JSON.parse(localStorage.getItem(STORAGE_KEY));
        expect(stored.pageBaselines[42]).toBe(5);
    });

    it('still claims "Nothing was applied." on a conflict with a clean report', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [
                    { ok: true, action: 'update_component' },
                    { ok: false, error: 'Version mismatch.', error_code: 'composition_conflict' }
                ],
                failed_at: 1,
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        const card = await applyAndGetCard();

        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(true);
        expect(card.querySelector('.pp-ai-status-error').textContent).toBe(CAUSE + ' ' + CLEAN_CLAIM);
    });

    it('still quotes the failing step\'s own reason on an executed failure', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: true }, { ok: false, error: 'Component "hero" has no slot "--nope".' }],
                failed_at: 1,
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        const card = await applyAndGetCard();

        expect(lastStatus()).toContain('Error on step 2: Component "hero" has no slot "--nope".');

        // The per-step painting the guard now runs conditionally still runs.
        const rows = stepRows(card);
        expect(rows[0].classList.contains('pp-ai-step-done')).toBe(true);
        expect(rows[1].classList.contains('pp-ai-step-failed')).toBe(true);
    });

    it('still marks post-failure steps skipped rather than failed', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: false, error: 'Nope.' }],
                failed_at: 0,
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        const card = await applyAndGetCard();
        const rows = stepRows(card);

        expect(rows[0].classList.contains('pp-ai-step-failed')).toBe(true);
        expect(rows[1].classList.contains('pp-ai-step-skipped')).toBe(true);
        expect(rows[1].classList.contains('pp-ai-step-failed')).toBe(false);
    });
});
