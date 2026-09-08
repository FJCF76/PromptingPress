/**
 * A batch is narrated as applied only when every proposed step was accounted for,
 * and the failure sentence names a step number only when it has one (#871, #872).
 *
 * TWO FIELDS, ONE LAYER. executeProposal() reads `batch.steps` to decide WHAT
 * happened and `batch.failed_at` to decide WHICH step it happened to, and it used to
 * make a claim from each without checking the field could support it.
 *
 *   #871  `batch.steps.length` was never compared to the proposal's own step count, so an
 *         envelope reporting FEWER results than the card rendered rows narrated a subset
 *         as the whole: a success card, and `[Applied changes: <subset>]` written into the
 *         model's context. The model's next turn then reasons from a list missing a step
 *         it believes was applied. RULING T5 (ratified 2026-09-06) gates success narration
 *         — the card AND the model note — on complete step accounting.
 *
 *   #872  `batch.failed_at` was tested only for null/undefined and otherwise concatenated
 *         into "Error on step " + (failed_at + 1). Measured on the pre-fix source: `{}`
 *         printed `Error on step [object Object]1`, `'2'` printed `Error on step 21`,
 *         `1.5` printed `Error on step 2.5`, `true` printed a perfectly plausible
 *         `Error on step 2`, and `-1` printed `Error on step 0` AND drove the skip pass
 *         from index 0, rewriting every row's state.
 *
 *         AND A FAMILY THE ISSUE'S TABLE DOES NOT NAME, which turned out to be the more
 *         serious half: `'0'`, `0.5` and `-0.5` all land INSIDE the skip pass's loop bound
 *         on a key that is not an array index, so the loop read `undefined.classList` and
 *         THREW. The throw lands in the chain's catch, so the operator got a stack-shaped
 *         string and the whole executed-failure exit was lost with it — rollback report,
 *         dirty-page sentence, repair affordance. #853's own defect, still live one field
 *         over. The "quieter since #853, not rarer" reading in the issue body holds only
 *         for the shapes that reach the SENTENCE; these never did.
 *
 * WHY THE TWO SHIP TOGETHER: they are the same evidence layer. One decides whether the
 * success exit may speak, the other decides whether the failure exit may name a step.
 * Designed apart, the second would be free to fabricate a number inside an exit the
 * first had just routed a batch into.
 *
 * ── THE EXEMPTIONS COME FIRST, AND THAT ORDERING IS THE POINT ────────────────────
 *
 * #871's ruling carries a BINDING GUARD: the legitimate short-count exits keep their
 * honest exits. Three envelopes report fewer step results than the proposal had steps
 * and are RIGHT to:
 *
 *   #749 up-front refusal   `{ok: false, steps: [], failed_at: null}` — the executor
 *                           refused the whole proposal before step 1 and says so on the
 *                           envelope. Zero results for two steps, and every row is
 *                           SKIPPED, not failed.
 *   #853 unreadable steps   `{ok: true, steps: {}}` — no per-step truth at all, already
 *                           routed to a stated unknown by the readability guard.
 *   #853 short list on a    `{ok: false, steps: [{ok:true}], failed_at: 1}` — a genuine
 *        FAILURE            executed failure whose list stops at the failure point.
 *
 * A fourth reader must also stay exactly as weak as it is: `ppChatBatchHitConflict()`
 * asks one index one question about the CAUSE, and its own docblock records that handing
 * it a stronger predicate sends a conflicting batch to the wrong exit and re-masks the
 * #797 card. Neither predicate added here is given to it.
 *
 * Those four are pinned in the first describe block below, and they were written and run
 * GREEN against unmodified source before either guard existed. A gate that buys its
 * red-proofs by breaking a legitimate exit would otherwise look exactly like a gate that
 * works.
 *
 * WHAT MAKES THEM SAFE, structurally rather than by inspection: #871's gate is only
 * consulted when `batch.ok` is truthy, and every legitimate short-count exit above is
 * either `ok: false` or already routed away by the readability guard. The gate cannot reach
 * them. (Truthy, not `=== true`: every read of that field in the client is a truthiness
 * test, and a gate stricter than the exit it guards would let `{ok: 1}` slip past it.)
 *
 * ── WHY THIS FILE DRIVES THE REAL SURFACE ────────────────────────────────────────
 *
 * executeProposal() is IIFE-local. A pure-helper assertion about `steps` or `failed_at`
 * passes whether or not the function ever asks the question, which is exactly how both
 * defects survived a file this heavily pinned. So the blocks below send a message, let
 * the client render and preview a proposal, click Apply, and answer with a hand-shaped
 * envelope — then read the transcript, the card, and the persisted conversation the model
 * will be sent. The fetch mock is the only seam. Same harness shape as
 * pp-ai-chat-batch-steps-shape.test.js, deliberately.
 *
 * BOTH SURFACES, EVERY TIME. A short-counted envelope has to lose the card's success
 * claim AND the `[Applied changes: ...]` turn. The card is the half a reviewer looks at;
 * the conversation is the half that reaches the model, and it is the one #871 is actually
 * about. Every red-proof block below asserts both.
 *
 * A SEPARATE FILE FROM pp-ai-chat-batch-steps-shape.test.js on purpose: that file's header
 * carries a measured red/green census for #853, and folding a second issue's blocks under
 * it would make that census unreadable. The landed pins there stay untouched and green.
 *
 * ── MEASURED, NOT ASSERTED ───────────────────────────────────────────────────────
 *
 * Run against the pre-fix source in a scratch copy: 50 of the 62 blocks go red, 12 stay
 * green. Every one of the 12 is a pin that SHOULD be green before the fix, and each says so
 * where it lives — no block in this file is green pre-fix by accident:
 *
 *   6  the binding-guard exemptions: the #749 refusal (twice), #853's unreadable-steps
 *      guard, #853's short list on a failure, and ppChatBatchHitConflict's deliberate
 *      weakness (twice).
 *   3  preservation: a fully-accounted batch still narrates, an over-counted one is
 *      unchanged, a well-formed index still skips post-failure rows.
 *   1  the zero-step proposal, which pins renderProposal()'s early return rather than
 *      anything in this layer.
 *   1  the #923 inventory block, which asserts only what is settled and says in its own
 *      title that it closes nothing.
 *   1  "#853 preserved: leaves no row spinning", labelled in its own title.
 *
 * THREE BLOCKS HERE WERE FALSE GREENS BEFORE REVIEW, named because the shape recurs and is
 * the whole reason a census is worth measuring rather than claiming. "bounds that reason
 * (#793)" asserted only length CEILINGS, which the pre-fix SUCCESS line satisfied at 32
 * characters — so the one pin covering the bound at the new exit proved nothing. And three
 * rows of "degrades to the stated unknown" were satisfied by the pre-fix code THROWING,
 * because the chain's catch renders a message that contains "Error: " and does not contain
 * "Error on step". Both now assert something only the intended exit can produce: the exact
 * bounded sentence, and the rollback clause.
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

const SITE_URL = 'http://accounting.example.com';
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

localStorage.setItem(STORAGE_KEY, JSON.stringify({
    activePageId: PAGE_ID,
    pageBaselines: { 42: 3 },
    conversation: []
}));

// ─── Fixtures ────────────────────────────────────────────────────────────────

const STEP_ONE_DESC = 'Update the hero title';
const STEP_TWO_DESC = 'Update the hero subtitle';

const PROPOSED_STEPS = [
    {
        type: 'action',
        name: 'update_component',
        description: STEP_ONE_DESC,
        params: { post_id: PAGE_ID, component_index: 0, props: { title: 'New' } }
    },
    {
        type: 'action',
        name: 'update_component',
        description: STEP_TWO_DESC,
        params: { post_id: PAGE_ID, component_index: 1, props: { title: 'Also new' } }
    }
];

/**
 * Every `failed_at` the issue's table names, plus the three it does not.
 *
 * `true` is the one worth adding: `true + 1` is 2, so a boolean renders a perfectly
 * plausible "Error on step 2" — no bracket, no NaN, nothing to notice. `-0` is the
 * signed-zero edge (`Math.floor(-0) === -0` and `-0 >= 0`, so it is a legitimate index 0
 * by every arithmetic test and must NOT be rejected). `Infinity` is the non-finite that
 * survives a naive `Math.floor(x) === x` test, because `Math.floor(Infinity)` is Infinity.
 */
const MALFORMED_INDEXES = [
    ['an object', {}, '[object Object]'],
    ['an array', [], null],
    ['a numeric string', '2', '21'],
    ['a numeric string that coerces into the loop', '0', null],
    ['a negative integer', -1, 'step 0'],
    ['a float', 1.5, '2.5'],
    ['a fraction below one', 0.5, '1.5'],
    ['a negative fraction', -0.5, '0.5'],
    ['a boolean', true, 'step 2'],
    ['NaN', NaN, 'NaN'],
    ['Infinity', Infinity, 'Infinity']
];

const MENU = 'could not recreate menu item "Contact"';
const DIRTY_CLAIM = 'some changes could not be reverted.';

// ─── The seam ────────────────────────────────────────────────────────────────

let batchResponse = null;
let proposalSteps = PROPOSED_STEPS;

function jsonOk(payload) {
    return Promise.resolve({ ok: true, json: function () { return Promise.resolve(payload); } });
}

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
                // CLONED PER RESPONSE: executeProposal() WRITES to the step objects it is
                // handed (`step._validation`, `step._staleWarnings`), so serving the same
                // objects to every block would let one block's envelope leave state on the
                // next block's fixture.
                proposal: { proposal: true, steps: JSON.parse(JSON.stringify(proposalSteps)) },
                page_baseline: { post_id: PAGE_ID, version: 3 }
            }
        });
    }
    throw new Error('unexpected action in fetch mock: ' + action);
};

const {
    batchStepsReadable,
    batchAccountsForAllSteps,
    batchFailedStepIndex,
    batchUnknownErrorText,
    isNonNegativeInteger,
    batchHitConflict,
    REFLECTED_ERROR_MAX
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

/** Sends a message and returns the settled proposal card, without clicking Apply. */
async function renderCard() {
    inputEl.value = 'Change the hero';
    sendBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    // An explicit last-match, not `:last-of-type` — that pseudo-class matches on ELEMENT
    // type among siblings, so on a transcript whose last div is not a proposal card it
    // selects nothing and a fallback would quietly hand back the FIRST card instead.
    const cards = messagesEl.querySelectorAll('.pp-ai-proposal-card');
    expect(cards.length).toBeGreaterThan(0);
    return cards[cards.length - 1];
}

/** Sends a message, previews the proposal, clicks Apply, and returns the settled card. */
async function applyAndGetCard() {
    const card = await renderCard();

    const applyBtn = card.querySelector('.pp-ai-proposal-apply');
    expect(applyBtn).not.toBeNull();
    applyBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    return card;
}

/** The last status line, which is the one the apply produced. */
function lastStatus() {
    const all = messagesEl.querySelectorAll('.pp-ai-status');
    return all.length ? all[all.length - 1].textContent : '';
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
 * Each row's state AS THE OPERATOR SEES IT, which means cascade order, not a preference of
 * this file's own.
 *
 * A row can legitimately carry two terminal classes (the skip pass adds `skipped` without
 * clearing what the paint loop wrote), and when it does, the one that WINS is the one
 * declared last in pp-ai-chat.css — `skipped` (:396), then `failed` (:392), then `done`
 * (:388), all at equal specificity. An oracle that checked `done` first would report green
 * for a row rendering greyed-out as "never ran", which is the exact inversion that makes a
 * contradictory row invisible to a suite whose whole job is catching contradictory claims.
 */
function rowClasses(card) {
    return stepRows(card).map(function (row) {
        if (row.classList.contains('pp-ai-step-skipped')) return 'skipped';
        if (row.classList.contains('pp-ai-step-failed')) return 'failed';
        if (row.classList.contains('pp-ai-step-done')) return 'done';
        if (row.classList.contains('pp-ai-step-executing')) return 'executing';
        return 'none';
    });
}

/**
 * THE MODEL-FACING HALF OF RULING T5, asked as one question.
 *
 * The card is what a reviewer looks at; the persisted conversation is what actually
 * reaches the provider on the next turn. finalizeProposalSuccess() writes BOTH an
 * `[Applied changes: ...]` user turn and one of three assistant confirmations, so a gate
 * that only suppressed the card would still hand the model a subset it would reason from.
 */
function expectNoSuccessNarrationToModel() {
    // NOT VACUOUS: a forEach over an empty array asserts nothing, and five blocks lean on
    // this helper for the model-facing half of ruling T5. A changed storage key, a moved
    // saveState(), or a beforeEach that cleared more than intended would turn all five
    // green-for-nothing. The turns the client DID persist have to exist before their
    // content can be evidence about what it did not write.
    expect(storedConversation().length).toBeGreaterThan(0);

    storedConversation().forEach(function (msg) {
        if (typeof msg.content !== 'string') return;
        expect(msg.content.indexOf('[Applied changes:')).toBe(-1);
        expect(msg.content.indexOf('Changes applied successfully.')).toBe(-1);
        expect(msg.content.indexOf('Changes applied with warnings:')).toBe(-1);
        expect(msg.content.indexOf('Changes applied but rendered page validation failed:')).toBe(-1);
    });
}

/**
 * The card half: buildPostApplyCard() opens with `card.innerHTML = ''`, so a narrated
 * success leaves NO step rows behind and paints its own applied-changes status line.
 * Surviving rows plus the absence of that line is what "the card made no claim" looks
 * like from the outside — asserted on the rendered card, not on a class name that would
 * still pass if the summary were restyled.
 *
 * CASE-INSENSITIVE ON PURPOSE. The card's two spellings are "✓ All changes applied
 * successfully." and "✓ Changes applied with warnings.", so a literal match on
 * "Changes applied" silently misses the first one — which is precisely the spelling these
 * fixtures produce, since they carry no validation payload. Asserted lowercase, the line
 * catches both.
 */
function expectNoSuccessNarrationOnCard(card) {
    expect(stepRows(card).length).toBe(2);
    stepRows(card).forEach(function (row) {
        expect(row.classList.contains('pp-ai-step-executing')).toBe(false);
    });
    expect(card.textContent.toLowerCase()).not.toContain('changes applied');
}

beforeEach(function () {
    batchResponse = null;
    proposalSteps = PROPOSED_STEPS;
    newChatBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    pageSelectEl.value = String(PAGE_ID);
    pageSelectEl.dispatchEvent(new dom.window.Event('change'));
});

// ═══════════════════════════════════════════════════════════════════════════════
// THE BINDING GUARD: the legitimate short-count exits, pinned FIRST.
//
// Written and run green against unmodified source before either guard existed. If a
// later edit buys #871's reds by routing one of these into the new refusal, this block
// is what says so.
// ═══════════════════════════════════════════════════════════════════════════════

describe('the legitimate short-count exits keep their honest exits', function () {

    // #749. Zero results for two proposed steps, and the envelope says why: the executor
    // refused before step 1 because a page it named has a stored composition it cannot
    // read. `failed_at: null` is a fact the ENVELOPE states about itself, so this exit is
    // allowed the "no step ran" claim without per-step evidence — and the rows are
    // SKIPPED, which is the claim, not FAILED, which would be a different one.
    it('#749: an up-front refusal still skips every row and carries the server\'s reason', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [],
                failed_at: null,
                error: 'That page\'s stored composition could not be read.',
                rolled_back: false,
                rollback_errors: []
            }
        };

        const card = await applyAndGetCard();

        expect(rowClasses(card)).toEqual(['skipped', 'skipped']);
        expect(lastStatus()).toBe('Error: That page\'s stored composition could not be read.');
        expectNoSuccessNarrationToModel();
    });

    // The same refusal must not be re-classified by the accounting gate. Zero of two IS a
    // short count; what keeps it out of the gate is that it is `ok: false`.
    it('#749: the refusal is not re-routed into the stated-unknown exit', async function () {
        batchResponse = {
            success: true,
            data: { ok: false, steps: [], failed_at: null, error: 'Refused before step 1.', rolled_back: false, rollback_errors: [] }
        };

        const card = await applyAndGetCard();

        // The stated-unknown exit paints every row FAILED. The refusal paints them SKIPPED.
        // If the gate ever swallowed this envelope, these two would swap.
        expect(rowClasses(card)).toEqual(['skipped', 'skipped']);
        expect(rowClasses(card)).not.toContain('failed');
    });

    // #853. Not a list at all, so no per-step claim is possible and the readability guard
    // already routes it to a stated unknown. The accounting gate must not change the
    // sentence, the rows, or the version-map refusal.
    it('#853: a success envelope with unreadable steps still refuses, unchanged', async function () {
        batchResponse = { success: true, data: { ok: true, steps: {}, versions: { 42: 99 } } };

        const card = await applyAndGetCard();

        expect(lastStatus()).toBe('Error: Unknown error');
        expect(rowClasses(card)).toEqual(['failed', 'failed']);
        expectNoSuccessNarrationToModel();
        expect(JSON.parse(localStorage.getItem(STORAGE_KEY)).pageBaselines[42]).not.toBe(99);
    });

    // #853. A readable list SHORTER than the failure it reports, on a FAILURE envelope.
    // This is a legitimate short count: the list stops at the failure point. The row the
    // envelope answered keeps its answer; only the unanswered one is finished.
    it('#853: a short readable list on a failure still quotes its step and terminalizes', async function () {
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

        expect(lastStatus()).toContain('Error on step 2:');
        expect(lastStatus()).toContain(DIRTY_CLAIM);
        expect(rowClasses(card)).toEqual(['done', 'failed']);
    });

    // #797 / #853. ppChatBatchHitConflict() is DELIBERATELY the weakest read of `steps` in
    // the file: one index, one error_code, no walk. Neither predicate added for #871/#872
    // is given to it, because an object-shaped `steps` would then stop classifying as a
    // conflict and the conflicting batch would land on the executed-failure exit — the
    // exact card #853 unmasked, re-masked.
    it('#797: a conflict on an object-shaped steps still reaches the conflict card', async function () {
        const objectSteps = { 1: { ok: false, error_code: 'composition_conflict', error: 'Version mismatch.' } };
        batchResponse = {
            success: true,
            data: { ok: false, steps: objectSteps, failed_at: 1, rolled_back: true, rollback_errors: [], versions: {} }
        };

        // The predicate's own answer, asked directly, so the pin cannot pass because the
        // classifier was quietly strengthened somewhere else.
        expect(batchHitConflict(batchResponse.data)).toBe(true);
        expect(batchStepsReadable(batchResponse.data)).toBe(false);

        const card = await applyAndGetCard();
        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(true);
    });

    // The conflict card renders a CAUSE and an outcome, never a step number, so a
    // malformed index cannot fabricate one there — which is why the index predicate is
    // not wired into this classifier either.
    it('#797: a conflict card names no step number, so the index guard is not needed there', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: true }, { ok: false, error_code: 'composition_conflict', error: 'Version mismatch.' }],
                failed_at: 1,
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        const card = await applyAndGetCard();

        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(true);
        expect(card.querySelector('.pp-ai-status-error').textContent).not.toContain('Error on step');
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// PRESERVATION: the shapes that already narrated correctly still do.
// ═══════════════════════════════════════════════════════════════════════════════

describe('complete accounting still narrates success', function () {

    it('applies a batch that accounts for every proposed step, on both surfaces', async function () {
        batchResponse = {
            success: true,
            data: { ok: true, steps: [{ ok: true }, { ok: true }], versions: { 42: 5 } }
        };

        await applyAndGetCard();

        const applied = storedConversation().filter(function (m) {
            return typeof m.content === 'string' && m.content.indexOf('[Applied changes:') === 0;
        });
        expect(applied).toHaveLength(1);
        expect(applied[0].content).toBe('[Applied changes: ' + STEP_ONE_DESC + '; ' + STEP_TWO_DESC + ']');

        // The version map is adopted, because this envelope accounted for the whole batch.
        expect(JSON.parse(localStorage.getItem(STORAGE_KEY)).pageBaselines[42]).toBe(5);
    });

    // OVER-COUNT IS NOT SHORT-COUNT, and the disposition is deliberate. Ruling T5 gates on
    // FEWER results than the proposal had steps; an envelope with MORE has accounted for
    // every proposed step and then some, so the ruling's own evidence test passes. The
    // loop already drops results with no row to paint (#853), so the extra one cannot
    // invent a third description. Routing this to a failure would extend T5 — a decision
    // this issue does not carry — and would also flip a landed pin in
    // pp-ai-chat-batch-steps-shape.test.js. Inventoried here, not silently ignored.
    it('narrates an over-counted batch exactly as before, dropping the extra result', async function () {
        batchResponse = {
            success: true,
            data: { ok: true, steps: [{ ok: true }, { ok: true }, { ok: true }], versions: { 42: 5 } }
        };

        await applyAndGetCard();

        const applied = storedConversation().filter(function (m) {
            return typeof m.content === 'string' && m.content.indexOf('[Applied changes:') === 0;
        });
        expect(applied).toHaveLength(1);
        expect(applied[0].content).toBe('[Applied changes: ' + STEP_ONE_DESC + '; ' + STEP_TWO_DESC + ']');
    });

    it('still marks post-failure steps skipped on a well-formed integer index', async function () {
        batchResponse = {
            success: true,
            data: { ok: false, steps: [{ ok: false, error: 'Nope.' }], failed_at: 0, rolled_back: true, rollback_errors: [], versions: {} }
        };

        const card = await applyAndGetCard();

        expect(lastStatus()).toContain('Error on step 1: Nope.');
        expect(rowClasses(card)).toEqual(['failed', 'skipped']);
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// #871 — RULING T5: a short-counted envelope narrates nothing.
// ═══════════════════════════════════════════════════════════════════════════════

describe('a success envelope that does not account for every proposed step', function () {

    const SHORT = { ok: true, steps: [{ ok: true }], versions: { 42: 99 } };

    it('writes no applied-changes turn into the model\'s context', async function () {
        batchResponse = { success: true, data: JSON.parse(JSON.stringify(SHORT)) };

        await applyAndGetCard();

        expectNoSuccessNarrationToModel();
    });

    // THE PRECISE DEFECT, asked as the sentence it used to produce. Before the gate the
    // model was handed a list naming step 1 alone while the operator had authorised two.
    it('never hands the model the subset it did report', async function () {
        batchResponse = { success: true, data: JSON.parse(JSON.stringify(SHORT)) };

        await applyAndGetCard();

        storedConversation().forEach(function (msg) {
            if (typeof msg.content !== 'string') return;
            expect(msg.content).not.toBe('[Applied changes: ' + STEP_ONE_DESC + ']');
        });
    });

    it('draws no success card', async function () {
        batchResponse = { success: true, data: JSON.parse(JSON.stringify(SHORT)) };

        const card = await applyAndGetCard();

        expectNoSuccessNarrationOnCard(card);
    });

    it('states the unknown in the transcript instead', async function () {
        batchResponse = { success: true, data: JSON.parse(JSON.stringify(SHORT)) };

        await applyAndGetCard();

        expect(lastStatus()).toBe('Error: Unknown error');
    });

    it('renders the envelope\'s own reason when it carries one', async function () {
        batchResponse = {
            success: true,
            data: { ok: true, steps: [{ ok: true }], error: 'Executor skipped a step type.', versions: { 42: 99 } }
        };

        await applyAndGetCard();

        expect(lastStatus()).toBe('Error: Executor skipped a step type.');
    });

    // ASSERTS THE EXACT SENTENCE, NOT A LENGTH CEILING, and the difference is the whole
    // value of the block. Two upper bounds on `lastStatus().length` are satisfied by ANY
    // short line — including the "✓ Applied: …" success line this exit exists to suppress,
    // which is what the pre-fix client rendered here. Measured: with length-only assertions
    // this block passed against the unmodified source, so the #793 wiring at the new exit
    // was effectively unpinned. Naming the bounded string makes it red for the right reason.
    it('bounds that reason like every other reflected span (#793)', async function () {
        const huge = 'E'.repeat(5000);
        batchResponse = { success: true, data: { ok: true, steps: [{ ok: true }], error: huge, versions: {} } };

        await applyAndGetCard();

        expect(lastStatus()).toBe('Error: ' + 'E'.repeat(REFLECTED_ERROR_MAX - 3) + '...');
    });

    it('does not adopt a version map from an envelope that could not account for itself', async function () {
        batchResponse = { success: true, data: JSON.parse(JSON.stringify(SHORT)) };

        await applyAndGetCard();

        expect(JSON.parse(localStorage.getItem(STORAGE_KEY)).pageBaselines[42]).not.toBe(99);
    });

    // REPAINTS EVERY ROW, and this is the pin that says the first draft of this fix was
    // wrong. Terminalizing only the unaccounted row would have left row 0 painted
    // `pp-ai-step-done` — keeping a POSITIONAL claim on the one exit that has just rejected
    // the envelope's headline claim. And a short list is precisely where position is
    // untrustworthy: the drift ppChatBatchStepsReadable()'s docblock names
    // (`array_values(array_filter(...))` upstream) yields a short, RE-INDEXED list in which
    // `steps[0]` is not step 0's result. It would also have produced two exits printing the
    // identical sentence with opposite row semantics and nothing on screen to tell them
    // apart.
    it('repaints every row rather than keeping a positional claim it just rejected', async function () {
        batchResponse = { success: true, data: JSON.parse(JSON.stringify(SHORT)) };

        const card = await applyAndGetCard();

        expect(rowClasses(card)).toEqual(['failed', 'failed']);

        // Asserted on the class list, not through rowClasses()'s precedence order: this is
        // the first caller of ppChatMarkStepsFailed() that reaches it with a row already
        // painted `done`, and before #871 the helper stripped only `executing` — so the row
        // carried both states at once and was legible only because the cascade happens to
        // declare `failed` second.
        stepRows(card).forEach(function (row) {
            expect(row.classList.contains('pp-ai-step-done')).toBe(false);
            expect(row.classList.contains('pp-ai-step-skipped')).toBe(false);
            expect(row.classList.contains('pp-ai-step-failed')).toBe(true);
        });
    });

    // `ok` IS READ FOR TRUTHINESS EVERYWHERE IN THIS FILE, so the gate must ask exactly the
    // question the exit it guards asks. A gate spelled `batch.ok === true` would let this
    // envelope past and straight into the success exit's `if (batch.ok)`, restoring the
    // whole defect for any executor that ever serialises the flag as 1.
    it('gates a truthy non-boolean ok exactly as it gates true', async function () {
        batchResponse = { success: true, data: { ok: 1, steps: [{ ok: true }], versions: { 42: 99 } } };

        const card = await applyAndGetCard();

        expect(lastStatus()).toBe('Error: Unknown error');
        expectNoSuccessNarrationToModel();
        expectNoSuccessNarrationOnCard(card);
        expect(JSON.parse(localStorage.getItem(STORAGE_KEY)).pageBaselines[42]).not.toBe(99);
    });

    // The degenerate row of the issue's table: an empty summary asserted over nothing.
    it('refuses an empty step list against a two-step proposal', async function () {
        batchResponse = { success: true, data: { ok: true, steps: [], versions: { 42: 99 } } };

        const card = await applyAndGetCard();

        expect(lastStatus()).toBe('Error: Unknown error');
        expectNoSuccessNarrationToModel();
        expectNoSuccessNarrationOnCard(card);
    });
});

/**
 * The `{ok: true, steps: []}` narration over a ZERO-step proposal, and why no guard is
 * added for it.
 *
 * The issue names it as the degenerate case — "Changes applied successfully." asserted
 * over nothing at all — and also notes it is not reachable from today's client. Verified
 * rather than inherited: renderProposal() returns at `if (steps.length === 0) return;`
 * BEFORE it builds the Apply/Cancel row, so a zero-step proposal has no Apply button and
 * executeProposal() is never reached. That is the honest pin — the unreachability is a
 * property of the renderer, not of arithmetic in the gate — and a guard for a shape the
 * client cannot produce would be a guard the ruling did not specify.
 */
describe('a zero-step proposal', function () {

    it('never wires an Apply button, so executeProposal is unreachable for it', async function () {
        proposalSteps = [];

        const card = await renderCard();

        expect(card.querySelector('.pp-ai-proposal-apply')).toBeNull();
        expect(card.querySelector('.pp-ai-proposal-cancel')).toBeNull();
        expect(stepRows(card).length).toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// #872 — a failure index that is not an index names no step.
// ═══════════════════════════════════════════════════════════════════════════════

describe('a malformed failed_at renders no fabricated step number', function () {

    MALFORMED_INDEXES.forEach(function (entry) {
        const label = entry[0];
        const value = entry[1];
        const fabricated = entry[2];

        it('degrades to the stated unknown on ' + label, async function () {
            batchResponse = {
                success: true,
                data: {
                    ok: false,
                    steps: [{ ok: true }, { ok: false, error: 'Something went wrong.' }],
                    failed_at: value,
                    rolled_back: true,
                    rollback_errors: [],
                    versions: {}
                }
            };

            await applyAndGetCard();

            // THE EXECUTED-FAILURE EXIT WAS ACTUALLY REACHED, asserted first because the
            // three assertions below cannot tell it apart from the chain's CATCH. Pre-fix,
            // `'0'`, `0.5` and `-0.5` threw out of the skip pass and the catch rendered
            // "Error: Cannot read properties of undefined (reading 'classList')" — which
            // contains "Error: " and does not contain "Error on step", so 3 of these 11
            // rows passed against the unmodified source. The rollback clause is only
            // appended by the exit itself, so the catch can never satisfy it.
            expect(lastStatus()).toContain('reverted');

            expect(lastStatus()).not.toContain('Error on step');
            if (fabricated !== null) expect(lastStatus()).not.toContain(fabricated);
            expect(lastStatus()).toContain('Error: ');
        });
    });

    // THE WORST ROW OF THE TABLE, and the only one that also corrupts the card. `-1 + 1`
    // is 0, so the skip pass started at index 0 and rewrote EVERY row to "never ran" —
    // under an error line naming a step that does not exist.
    it('does not let a negative index rewrite every row as skipped', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: true }, { ok: false, error: 'Nope.' }],
                failed_at: -1,
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        const card = await applyAndGetCard();

        expect(rowClasses(card)).not.toContain('skipped');
        expect(lastStatus()).not.toContain('Error on step 0');
    });

    /**
     * THE FAMILY THE ISSUE'S TABLE DOES NOT NAME, and the one that costs the most.
     *
     * The skip pass indexed `stepElements[j]` with whatever `failed_at + 1` produced, and
     * three shapes land INSIDE the loop bound on a key that is not an array index:
     *
     *   '0'    ->  '0' + 1 is the STRING '01'. `'01' < 2` coerces to 1 < 2 and passes,
     *              but the property '01' is not the property '1', so the read is undefined.
     *   0.5    ->  j = 1.5, `1.5 < 2` passes, `stepElements[1.5]` is undefined.
     *   -0.5   ->  j = 0.5, same.
     *
     * Each one then read `undefined.classList` and THREW. The throw lands in the promise
     * chain's catch, which renders `err.message`, so the operator got a stack-shaped string
     * — and the entire executed-failure exit went with it: the rollback report, the
     * sentence naming what stayed dirty, and the repair affordance. That is the exact
     * defect #853's file exists to certify closed, still live one field over.
     *
     * `1.5` is the shape that does NOT throw (`2.5 < 2` is false), which is why a probe
     * that varies only the rendered sentence misses the whole family.
     *
     * ASSERTING THE ROLLBACK REPORT IS THE LOAD-BEARING HALF. An earlier draft of this
     * block asserted only that no row was marked skipped — which the pre-fix code satisfied
     * BY THROWING, because the catch paints every row failed on its way past. A false
     * green, and exactly the class of test this cluster exists to stop shipping.
     */
    [['a numeric string', '0'], ['a fraction below one', 0.5], ['a negative fraction', -0.5]].forEach(function (pair) {
        it('does not throw out of the skip pass on ' + pair[0], async function () {
            batchResponse = {
                success: true,
                data: {
                    ok: false,
                    steps: [{ ok: true }, { ok: false, error: 'Nope.' }],
                    failed_at: pair[1],
                    rolled_back: true,
                    rollback_errors: [MENU],
                    versions: {}
                }
            };

            const card = await applyAndGetCard();

            // The throw's own signature, which is what the transcript used to carry.
            ['classList', 'Cannot read propert', 'TypeError', 'undefined'].forEach(function (fragment) {
                expect(lastStatus()).not.toContain(fragment);
            });

            // And the exit that the throw used to eat, arriving intact.
            expect(lastStatus()).toContain(DIRTY_CLAIM);
            expect(card.textContent).toContain('Contact');

            expect(rowClasses(card)).not.toContain('skipped');
            expect(rowClasses(card)).not.toContain('executing');
        });
    });

    // #853'S RULE FOR THIS EXIT, APPLIED TO THE INDEX. "Keeps its report and loses only
    // the quote" was written about an unreadable `steps`; a malformed index is the same
    // shape one field over. The rollback report reads `rollback_errors`, never `failed_at`,
    // so the one thing this exit exists to deliver survives whole.
    it('keeps the rollback report when the index is unusable', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: true }, { ok: false, error: 'Nope.' }],
                failed_at: {},
                rolled_back: true,
                rollback_errors: [MENU],
                versions: {}
            }
        };

        const card = await applyAndGetCard();

        expect(lastStatus()).toContain(DIRTY_CLAIM);
        expect(card.textContent).toContain('Contact');
        expect(lastStatus()).not.toContain('Error on step');
    });

    /**
     * AN INVENTORY PIN, NOT AN APPROVAL. An index that IS a non-negative integer but names
     * no row the card rendered still prints its number: `failed_at: 99` on a two-step
     * proposal renders "Error on step 100". That is the same harm #872's table names for
     * `-1` — a plausible line for a step that does not exist — reached by a value that
     * satisfies #872's recorded Expected ("validated as a non-negative integer"), which is
     * what landed here.
     *
     * So this asserts only what IS settled: the guard did not make it throw, the rollback
     * report still arrives, and no row is corrupted. It deliberately does NOT assert the
     * number is absent, because that would enshrine today's behaviour as intended. Tracked
     * as #923; when that is ruled on, this block becomes the red-proof.
     */
    it('inventories an in-type but out-of-range index (#923, not closed here)', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: true }, { ok: false, error: 'Nope.' }],
                failed_at: 99,
                rolled_back: true,
                rollback_errors: [MENU],
                versions: {}
            }
        };

        const card = await applyAndGetCard();

        expect(lastStatus()).toContain(DIRTY_CLAIM);
        expect(card.textContent).toContain('Contact');
        expect(rowClasses(card)).not.toContain('executing');
        ['classList', 'Cannot read propert', 'TypeError'].forEach(function (fragment) {
            expect(lastStatus()).not.toContain(fragment);
        });
    });

    it('renders the envelope\'s own reason when the index is unusable', async function () {
        batchResponse = {
            success: true,
            data: {
                ok: false,
                steps: [{ ok: true }],
                failed_at: '2',
                error: 'The executor could not name the failing step.',
                rolled_back: true,
                rollback_errors: [],
                versions: {}
            }
        };

        await applyAndGetCard();

        expect(lastStatus()).toContain('Error: The executor could not name the failing step.');
        expect(lastStatus()).not.toContain('step 21');
    });

    // A #853 PRESERVATION PIN, NOT A #872 RED-PROOF, labelled so because it sits inside the
    // #872 block and a reader would otherwise take it for evidence of the new guard. It is
    // green against the unmodified source for all eleven shapes: the non-throwing ones were
    // already swept by ppChatFinishSpinningSteps(), and the throwing ones by the chain's
    // catch calling ppChatMarkStepsFailed(). What it protects is that #872 did not COST the
    // sweep — a guard that suppressed the skip pass without leaving something to terminalize
    // the rows would strand them, which is the defect ppChatFinishSpinningSteps() exists for.
    it('#853 preserved: leaves no row spinning on any malformed index', async function () {
        for (const entry of MALFORMED_INDEXES) {
            newChatBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
            pageSelectEl.value = String(PAGE_ID);
            pageSelectEl.dispatchEvent(new dom.window.Event('change'));

            batchResponse = {
                success: true,
                data: {
                    ok: false,
                    steps: [{ ok: true }],
                    failed_at: entry[1],
                    rolled_back: true,
                    rollback_errors: [],
                    versions: {}
                }
            };

            const card = await applyAndGetCard();
            expect(rowClasses(card)).not.toContain('executing');
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// The predicates, asked directly.
// ═══════════════════════════════════════════════════════════════════════════════

describe('ppChatBatchAccountsForAllSteps', function () {

    it('accepts a list with one result per proposed step', function () {
        expect(batchAccountsForAllSteps({ steps: [{ ok: true }, { ok: true }] }, 2)).toBe(true);
    });

    // `>=`, not `===`: the ruling gates on FEWER results than the proposal had steps.
    it('accepts a list with more results than the proposal had steps', function () {
        expect(batchAccountsForAllSteps({ steps: [{}, {}, {}] }, 2)).toBe(true);
    });

    it('rejects a list shorter than the proposal', function () {
        expect(batchAccountsForAllSteps({ steps: [{ ok: true }] }, 2)).toBe(false);
        expect(batchAccountsForAllSteps({ steps: [] }, 2)).toBe(false);
    });

    // Delegated, not re-derived: the accounting question is only meaningful on a list, and
    // asking `Array.isArray` a second time here is how the two answers drift (#667/#853).
    it('rejects every shape ppChatBatchStepsReadable rejects', function () {
        [{}, { 0: {}, length: 3 }, 'steps', 7, null].forEach(function (shape) {
            const batch = { steps: shape };
            expect(batchStepsReadable(batch)).toBe(false);
            expect(batchAccountsForAllSteps(batch, 2)).toBe(false);
        });
        expect(batchAccountsForAllSteps({ ok: true }, 2)).toBe(false);
        expect(batchAccountsForAllSteps(null, 2)).toBe(false);
    });

    it('does not accept a steps planted on Object.prototype', function () {
        Object.prototype.steps = [{ ok: true }, { ok: true }];
        try {
            expect(batchAccountsForAllSteps({ ok: true }, 2)).toBe(false);
        } finally {
            delete Object.prototype.steps;
        }
    });

    // FAIL-CLOSED ON A MISSING COUNT, the same posture ppChatConflictOutcome() takes for
    // its own threaded argument: a caller that forgets it loses a true sentence rather
    // than being handed a false one.
    it('fails closed when the proposed count is missing or not a number', function () {
        expect(batchAccountsForAllSteps({ steps: [{}, {}] })).toBe(false);
        expect(batchAccountsForAllSteps({ steps: [{}, {}] }, null)).toBe(false);
        expect(batchAccountsForAllSteps({ steps: [{}, {}] }, '2')).toBe(false);
        expect(batchAccountsForAllSteps({ steps: [{}, {}] }, -1)).toBe(false);
        expect(batchAccountsForAllSteps({ steps: [{}, {}] }, 1.5)).toBe(false);
    });

    // A zero-step proposal is trivially accounted for. Kept explicit because the client
    // cannot reach it (see the zero-step block above) and a reader should not have to
    // re-derive which way the arithmetic falls.
    it('treats a zero-step proposal as accounted for', function () {
        expect(batchAccountsForAllSteps({ steps: [] }, 0)).toBe(true);
    });
});

describe('ppChatBatchFailedStepIndex', function () {

    it('returns a non-negative integer index unchanged', function () {
        expect(batchFailedStepIndex({ failed_at: 0 })).toBe(0);
        expect(batchFailedStepIndex({ failed_at: 1 })).toBe(1);
        expect(batchFailedStepIndex({ failed_at: 42 })).toBe(42);
    });

    // `Math.floor(-0) === -0` and `-0 >= 0`, so signed zero IS index 0 by every test the
    // predicate applies. Pinned because a hand-written `x > 0 || x === 0` would be the
    // obvious rewrite and would still pass — but `1 / result` proves it is usable as an
    // index rather than merely equal to one.
    it('accepts signed zero as index 0', function () {
        expect(batchFailedStepIndex({ failed_at: -0 })).toBe(0);
    });

    // The refusal shape #749 owns. It must read as "no index", not as index 0 — the
    // pre-#853 code's `null + 1` printed a fabricated "Error on step 1" over a refusal
    // that names its own reason perfectly well.
    it('returns null for the refusal shape and for an absent key', function () {
        expect(batchFailedStepIndex({ failed_at: null })).toBeNull();
        expect(batchFailedStepIndex({ failed_at: undefined })).toBeNull();
        expect(batchFailedStepIndex({ ok: false })).toBeNull();
    });

    it('returns null for every non-integer the issue names', function () {
        MALFORMED_INDEXES.forEach(function (entry) {
            expect(batchFailedStepIndex({ failed_at: entry[1] })).toBeNull();
        });
    });

    // `Math.floor(Infinity) === Infinity` and `Infinity >= 0`, so a floor-equality test
    // alone waves it through. The finiteness check is what stops "Error on step Infinity".
    it('returns null for the non-finite numbers a floor test alone would accept', function () {
        expect(batchFailedStepIndex({ failed_at: Infinity })).toBeNull();
        expect(batchFailedStepIndex({ failed_at: -Infinity })).toBeNull();
        expect(batchFailedStepIndex({ failed_at: NaN })).toBeNull();
    });

    it('returns null for an absent or non-object envelope', function () {
        expect(batchFailedStepIndex(null)).toBeNull();
        expect(batchFailedStepIndex(undefined)).toBeNull();
        expect(batchFailedStepIndex('boom')).toBeNull();
        expect(batchFailedStepIndex(7)).toBeNull();
    });

    // wp-admin loads third-party JS in this realm, so an inherited `failed_at` is not
    // evidence about this envelope. Same own-property idiom the rest of the file uses on
    // `steps`.
    it('does not accept a failed_at planted on Object.prototype', function () {
        Object.prototype.failed_at = 3;
        try {
            expect(batchFailedStepIndex({ ok: false })).toBeNull();
        } finally {
            delete Object.prototype.failed_at;
        }
    });
});

/**
 * The shared integer test, asked directly rather than only through its two callers.
 *
 * Its docblock argues each of the four clauses is load-bearing and names the value each one
 * exists to reject. That argument is only checkable at this level: through the callers, a
 * dropped clause shows up as one row of one table going the wrong way, which reads as a
 * fixture problem rather than as a missing condition. One assertion per clause here means a
 * clause cannot be deleted quietly.
 */
describe('ppChatIsNonNegativeInteger', function () {

    it('accepts zero, signed zero and the positive integers', function () {
        [0, -0, 1, 2, 42, Number.MAX_SAFE_INTEGER].forEach(function (v) {
            expect(isNonNegativeInteger(v)).toBe(true);
        });
    });

    // One row per clause, in the order the function applies them.
    it('rejects the value each clause exists for', function () {
        expect(isNonNegativeInteger('2')).toBe(false);       // typeof — '2' + 1 is '21'
        expect(isNonNegativeInteger(true)).toBe(false);      // typeof — true + 1 is 2
        expect(isNonNegativeInteger(Infinity)).toBe(false);  // isFinite — floor(Inf) is Inf
        expect(isNonNegativeInteger(-Infinity)).toBe(false); // isFinite
        expect(isNonNegativeInteger(NaN)).toBe(false);       // isFinite
        expect(isNonNegativeInteger(1.5)).toBe(false);       // floor
        expect(isNonNegativeInteger(-1)).toBe(false);        // >= 0
        expect(isNonNegativeInteger(-0.5)).toBe(false);      // >= 0 and floor
    });

    it('rejects every non-number, including the ones that coerce', function () {
        [null, undefined, {}, [], '', '0', 'boom', function () {}].forEach(function (v) {
            expect(isNonNegativeInteger(v)).toBe(false);
        });
    });
});

describe('ppChatBatchUnknownErrorText', function () {

    it('renders the envelope\'s own reason', function () {
        expect(batchUnknownErrorText({ error: 'Executor could not summarise this batch.' }))
            .toBe('Error: Executor could not summarise this batch.');
    });

    it('falls back to the stated unknown when the envelope carries no reason', function () {
        expect(batchUnknownErrorText({})).toBe('Error: Unknown error');
        expect(batchUnknownErrorText(null)).toBe('Error: Unknown error');
        expect(batchUnknownErrorText(undefined)).toBe('Error: Unknown error');
        expect(batchUnknownErrorText('boom')).toBe('Error: Unknown error');
    });

    // THE MOST EXPOSED OF THE THREE POLLUTION PINS, because of WHICH envelope reaches this
    // helper. An envelope claiming success normally carries no own `error` at all, so a
    // planted `Object.prototype.error` would win on the COMMON path of #871's refusal rather
    // than on an edge case — someone else's string wearing the server's voice inside a
    // `role="alert"`, at the exact moment the client has decided the envelope is not
    // trustworthy. Same own-property idiom the sibling predicates use on `steps` and
    // `failed_at`.
    it('does not read an error planted on Object.prototype', function () {
        Object.prototype.error = 'planted by a neighbour';
        try {
            expect(batchUnknownErrorText({ ok: true, steps: [] })).toBe('Error: Unknown error');
            expect(batchUnknownErrorText({})).toBe('Error: Unknown error');
            // An OWN error still wins, so the guard cannot be satisfied by muting the field.
            expect(batchUnknownErrorText({ error: 'the server\'s own reason' }))
                .toBe('Error: the server\'s own reason');
        } finally {
            delete Object.prototype.error;
        }
    });

    // The same envelope driven through the real surface, so the guard is pinned where it
    // actually matters rather than only on the helper.
    it('does not let a planted error reach the transcript on a short-counted batch', async function () {
        Object.prototype.error = 'planted by a neighbour';
        try {
            batchResponse = { success: true, data: { ok: true, steps: [{ ok: true }], versions: {} } };

            await applyAndGetCard();

            expect(lastStatus()).toBe('Error: Unknown error');
            expect(lastStatus()).not.toContain('planted');
        } finally {
            delete Object.prototype.error;
        }
    });

    // A NON-STRING `error` IS #872'S OWN DEFECT ONE FIELD OVER, and it reached this helper
    // first. ppChatBoundReflectedText() hands a short value straight back, so the
    // concatenation does the coercion: without the string test an object rendered
    // "Error: [object Object]" and an array rendered "Error: a,b" — an unvalidated envelope
    // field turned into a sentence, which is exactly what #872 exists to stop for
    // `failed_at`. Pinned here because this helper now owns that sentence at three exits.
    it('never renders a non-string reason as its own internals', function () {
        expect(batchUnknownErrorText({ error: { code: 7 } })).toBe('Error: Unknown error');
        expect(batchUnknownErrorText({ error: ['a', 'b'] })).toBe('Error: Unknown error');
        expect(batchUnknownErrorText({ error: 42 })).toBe('Error: Unknown error');
        expect(batchUnknownErrorText({ error: true })).toBe('Error: Unknown error');
        expect(batchUnknownErrorText({ error: '' })).toBe('Error: Unknown error');
    });

    // The 'Error: ' prefix is this file's own prose and is not counted against the budget
    // — the same rule PP_CHAT_RENDER_ERROR_MAX states for its prefix. Only the server's
    // span is bounded (#793).
    it('bounds the server\'s span but not its own prefix', function () {
        const huge = 'E'.repeat(5000);
        const out = batchUnknownErrorText({ error: huge });

        expect(out.indexOf('Error: ')).toBe(0);
        expect(out.length).toBeLessThan(huge.length);
        expect(out.length).toBeLessThanOrEqual(REFLECTED_ERROR_MAX + 'Error: '.length);
    });
});
