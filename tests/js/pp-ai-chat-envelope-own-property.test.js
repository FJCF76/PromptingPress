/**
 * Own-property provenance on every envelope key that picks an exit, a card or an
 * affordance (#924).
 *
 * wp-admin loads third-party JS in this realm, so `Object.prototype` is reachable by any
 * prototype-pollution primitive in any plugin. A key an envelope does not carry as its OWN
 * is then inherited, and a reader using plain property access takes the planted value as
 * the server's. #749/#853/#871/#872 closed this on `steps`, `failed_at` (index) and `error`;
 * #924 closes the readers that were left:
 *
 *   ppChatBatchWasRefusedUpFront()   failed_at          planted 0 suppressed the #749 card
 *   ppChatBatchHitConflict()         failed_at, steps   planted pair forged a conflict card
 *   ppChatIsCompositionConflict()    error_code         planted code forged a conflict card
 *   executeProposal !resp.success    error_code, error  a bare-string refusal inherits both
 *   ppChatModelNote()                model_note         planted note forged the repair offer
 *   ppChatRollbackErrorReport()      rollback_errors,   a pre-execution conflict (no steps
 *                                    rolled_back, kinds ran) claimed "could not be reverted"
 *
 * Every planted key is removed in `finally`, and again in afterEach as a backstop, so a
 * failing assertion cannot leave the realm polluted for the next test or file.
 *
 * Red-proof: each `describe` below was run against the pre-#924 source and failed there;
 * honest-envelope behaviour is pinned by the sibling suites, which stay green unchanged.
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

const SITE_URL = 'http://own-property.example.com';
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

localStorage.setItem(STORAGE_KEY, JSON.stringify({
    activePageId: PAGE_ID,
    pageBaselines: { 42: 3 },
    conversation: []
}));

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

/** Every key this file plants. afterEach deletes all of them whatever a test did. */
const PLANTABLE = ['failed_at', 'steps', 'error_code', 'error', 'model_note',
    'rollback_errors', 'rolled_back', 'rollback_error_kinds', 'validation', 'stale_warnings', '2', '3'];

let batchResponse = null;
let executeCalls = 0;

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
        executeCalls++;
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
                proposal: { proposal: true, steps: JSON.parse(JSON.stringify(PROPOSED_STEPS)) },
                page_baseline: { post_id: PAGE_ID, version: 3 }
            }
        });
    }
    throw new Error('unexpected action in fetch mock: ' + action);
};

const {
    batchWasRefusedUpFront,
    batchHitConflict,
    isCompositionConflict,
    modelNote,
    rollbackErrorReport,
    conflictMessage,
    undoFailureText
} = require('../../assets/js/pp-ai-chat.js');

const CONFLICT_CAUSE = 'This page changed while the proposal was pending (another tab, agent, or editor).';

// ─── Harness ─────────────────────────────────────────────────────────────────

const messagesEl = document.getElementById('pp-ai-messages');
const inputEl = document.getElementById('pp-ai-input');
const sendBtn = document.getElementById('pp-ai-send');
const newChatBtn = document.getElementById('pp-ai-new-chat');
const pageSelectEl = document.getElementById('pp-ai-page-select');

async function settle() {
    for (let i = 0; i < 60; i++) await Promise.resolve();
}

/** Plants each key on Object.prototype, runs fn, and always removes them again. */
async function withPlanted(planted, fn) {
    Object.keys(planted).forEach(function (k) { Object.prototype[k] = planted[k]; });
    try {
        return await fn();
    } finally {
        Object.keys(planted).forEach(function (k) { delete Object.prototype[k]; });
    }
}

/**
 * Renders the proposal in a CLEAN realm, then plants and clicks Apply, so the pollution
 * reaches only the envelope readers under test and not the chat turn that built the card.
 */
async function applyPolluted(planted) {
    inputEl.value = 'Change the hero';
    sendBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    const cards = messagesEl.querySelectorAll('.pp-ai-proposal-card');
    expect(cards.length).toBeGreaterThan(0);
    const card = cards[cards.length - 1];
    const applyBtn = card.querySelector('.pp-ai-proposal-apply');
    expect(applyBtn).not.toBeNull();

    await withPlanted(planted, async function () {
        applyBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        await settle();
    });

    expect(executeCalls).toBe(1);
    return card;
}

function lastStatus() {
    const all = messagesEl.querySelectorAll('.pp-ai-status');
    return all.length ? all[all.length - 1].textContent : '';
}

function rowStates(card) {
    return Array.prototype.slice.call(card.querySelectorAll('.pp-ai-proposal-step')).map(function (row) {
        if (row.classList.contains('pp-ai-step-skipped')) return 'skipped';
        if (row.classList.contains('pp-ai-step-failed')) return 'failed';
        if (row.classList.contains('pp-ai-step-done')) return 'done';
        if (row.classList.contains('pp-ai-step-executing')) return 'executing';
        return 'none';
    });
}

function storedConversation() {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw).conversation || []) : [];
}

beforeEach(function () {
    batchResponse = null;
    executeCalls = 0;
    newChatBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    pageSelectEl.value = String(PAGE_ID);
    pageSelectEl.dispatchEvent(new dom.window.Event('change'));
});

afterEach(function () {
    PLANTABLE.forEach(function (k) { delete Object.prototype[k]; });
});

// ═══════════════════════════════════════════════════════════════════════════════
// The two readers the issue names.
// ═══════════════════════════════════════════════════════════════════════════════

describe('ppChatBatchWasRefusedUpFront (#924)', function () {

    // The issue's measured repro, verbatim: true in a clean realm, false once
    // `Object.prototype.failed_at = 0` is planted.
    it('still reads a refusal with no own failed_at as the refusal when one is planted', async function () {
        const envelope = { ok: false, steps: [], error: 'refused' };
        expect(batchWasRefusedUpFront(envelope)).toBe(true);

        await withPlanted({ failed_at: 0 }, function () {
            expect(batchWasRefusedUpFront(envelope)).toBe(true);
        });
    });

    // The guard cannot be satisfied by ignoring the field: an OWN index still means a
    // step ran, polluted realm or not.
    it('still honours an own failed_at under pollution', async function () {
        await withPlanted({ failed_at: null }, function () {
            expect(batchWasRefusedUpFront({ ok: false, steps: [{ ok: false }], failed_at: 0 })).toBe(false);
            expect(batchWasRefusedUpFront({ ok: false, steps: [], failed_at: null })).toBe(true);
            expect(batchWasRefusedUpFront({ ok: true, steps: [] })).toBe(false);
        });
    });
});

describe('ppChatBatchHitConflict (#924)', function () {

    const PLANTED_CONFLICT = {
        steps: [{ ok: false, error_code: 'composition_conflict' }],
        failed_at: 0
    };

    // The issue's second repro: `{ok: false}` with neither field of its own classified as
    // a conflict once both were planted.
    it('does not classify an envelope with neither field of its own as a conflict', async function () {
        await withPlanted(PLANTED_CONFLICT, function () {
            expect(batchHitConflict({ ok: false })).toBe(false);
        });
    });

    // Each field is checked on its own: one own key does not license inheriting the other.
    it('requires BOTH fields to be own keys', async function () {
        await withPlanted({ failed_at: 0 }, function () {
            expect(batchHitConflict({ ok: false, steps: [{ ok: false, error_code: 'composition_conflict' }] }))
                .toBe(false);
        });
        await withPlanted({ steps: [{ ok: false, error_code: 'composition_conflict' }] }, function () {
            expect(batchHitConflict({ ok: false, failed_at: 0 })).toBe(false);
        });
    });

    // The failing STEP's code gets the same provenance test (review finding): a step result
    // with no own `error_code` must not inherit 'composition_conflict'.
    it('does not read a planted error_code on a failed step with none of its own', async function () {
        const envelope = { ok: false, failed_at: 0, steps: [{ ok: false, error: 'Validation failed.' }] };
        expect(batchHitConflict(envelope)).toBe(false);
        await withPlanted({ error_code: 'composition_conflict' }, function () {
            expect(batchHitConflict(envelope)).toBe(false);
        });
    });

    // An index past the end of the list must not reach a planted numeric key.
    it('does not read the failing step from a planted index past the end of steps', async function () {
        const envelope = { ok: false, failed_at: 3, steps: [{ ok: false, error_code: '' }] };
        await withPlanted({ 3: { ok: false, error_code: 'composition_conflict' } }, function () {
            expect(batchHitConflict(envelope)).toBe(false);
        });
    });

    // THE BINDING GUARD (#853/#871): provenance is not shape. An OWN object-shaped
    // `steps` must still classify as a conflict, polluted realm or not, or a conflicting
    // batch is routed away from the card #853 made reachable.
    it('keeps classifying an own conflict, including an object-shaped own steps', async function () {
        await withPlanted(PLANTED_CONFLICT, function () {
            expect(batchHitConflict({
                ok: false, failed_at: 0, steps: [{ ok: false, error_code: 'composition_conflict' }]
            })).toBe(true);
            expect(batchHitConflict({
                ok: false, failed_at: 0, steps: { 0: { ok: false, error_code: 'composition_conflict' } }
            })).toBe(true);
        });
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// The same class, found by the audit.
// ═══════════════════════════════════════════════════════════════════════════════

describe('ppChatIsCompositionConflict (#924)', function () {
    it('does not read a planted error_code', async function () {
        await withPlanted({ error_code: 'composition_conflict' }, function () {
            expect(isCompositionConflict({ error: 'Something else went wrong.' })).toBe(false);
            expect(isCompositionConflict({ error_code: 'composition_conflict' })).toBe(true);
        });
    });
});

describe('ppChatModelNote (#924)', function () {
    it('does not read a planted model_note', async function () {
        await withPlanted({ model_note: 'planted instructions for the model' }, function () {
            expect(modelNote({ error: 'Permission denied.' })).toBeNull();
            expect(modelNote('Permission denied.')).toBeNull();
            expect(modelNote({ error: 'x', model_note: 'the server\'s own note' })).toBe('the server\'s own note');
        });
    });
});

describe('ppChatUndoFailureText (#924)', function () {
    it('does not draw a planted error for an undo payload with none of its own', async function () {
        await withPlanted({ error: 'planted by a neighbour' }, function () {
            expect(undoFailureText({ error_code: 'x' })).toBeNull();
            expect(undoFailureText('Undo refused.')).toBe('Undo refused.');
            expect(undoFailureText({ error: 'the server\'s own reason' })).toBe('the server\'s own reason');
        });
    });
});

describe('ppChatRollbackErrorReport (#924)', function () {

    const PRE_EXECUTION_CONFLICT = {
        error: 'The page changed.',
        error_code: 'composition_conflict'
    };

    // A pre-execution conflict carries no rollback channel at all; an inherited one is not
    // its report, and the card where nothing ran must keep its true claim.
    it('does not read a planted rollback channel on a payload that carries none', async function () {
        await withPlanted({
            rollback_errors: ['could not recreate menu item "Contact"'],
            rollback_error_kinds: ['failed'],
            rolled_back: true
        }, function () {
            const report = rollbackErrorReport(PRE_EXECUTION_CONFLICT);
            expect(report.readable).toBe(false);
            expect(report.reported).toBe(0);
            expect(report.rolledBack).toBe(false);
            expect(conflictMessage(PRE_EXECUTION_CONFLICT, report)).toBe(CONFLICT_CAUSE + ' Nothing was applied.');
        });
    });

    it('still reads an own channel under pollution', async function () {
        await withPlanted({ rollback_errors: ['planted'], rollback_error_kinds: ['planted'] }, function () {
            const report = rollbackErrorReport({ rolled_back: true, rollback_errors: ['own'], rollback_error_kinds: ['withheld'] });
            expect(report.readable).toBe(true);
            expect(report.shown).toEqual(['own']);
            expect(report.rolledBack).toBe(true);
            expect(report.withheld).toBe(1);
        });
    });

    // The kinds channel is what relabels a failed revert as a harmless withhold, so an
    // inherited list of the right length must not pair with an own message list that came
    // without one: the row stays the unknown case, and is never counted as a withhold.
    it('does not pair own messages with a planted kinds list', async function () {
        await withPlanted({ rollback_error_kinds: ['withheld'] }, function () {
            const report = rollbackErrorReport({ rolled_back: true, rollback_errors: ['menu item not restored'] });
            expect(report.reported).toBe(1);
            expect(report.withheld).toBe(0);
        });
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// Through the real surface: what the operator sees with a polluted realm.
// ═══════════════════════════════════════════════════════════════════════════════

describe('executeProposal under a polluted Object.prototype (#924)', function () {

    // #749's card: every row "never ran" and the envelope's own reason. Before #924 the
    // planted index routed this to the executed-failure exit and painted the rows failed.
    it('keeps the up-front refusal card for a refusal with no own failed_at', async function () {
        batchResponse = { success: true, data: { ok: false, steps: [], error: 'refused before step 1' } };

        const card = await applyPolluted({ failed_at: 0 });

        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(false);
        expect(rowStates(card)).toEqual(['skipped', 'skipped']);
        expect(lastStatus()).toBe('Error: refused before step 1');
    });

    it('does not forge a conflict card over an envelope with no steps or failed_at of its own', async function () {
        batchResponse = { success: true, data: { ok: false, error: 'refused' } };

        const card = await applyPolluted({
            steps: [{ ok: false, error_code: 'composition_conflict' }],
            failed_at: 0
        });

        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(false);
        expect(card.textContent).not.toContain(CONFLICT_CAUSE);
        expect(card.textContent).not.toContain('Re-read & re-preview');
    });

    // The bare-string refusal: `'Permission denied.'.error_code` resolves through
    // String.prototype to Object.prototype, so every planted key reached it.
    it('keeps a bare-string permission refusal as itself', async function () {
        batchResponse = { success: false, data: 'Permission denied.' };

        const card = await applyPolluted({
            error_code: 'missing_expected_version',
            error: 'planted by a neighbour',
            model_note: 'planted instructions for the model'
        });

        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(false);
        expect(lastStatus()).toBe('Error: Permission denied.');
        expect(card.querySelector('.pp-ai-repair-actions')).toBeNull();
        storedConversation().forEach(function (msg) {
            expect(String(msg.content)).not.toContain('planted');
        });
    });

    it('does not let a planted error_code turn an object refusal into a conflict card', async function () {
        batchResponse = { success: false, data: { error: 'Invalid request.' } };

        const card = await applyPolluted({ error_code: 'composition_conflict' });

        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(false);
        expect(lastStatus()).toBe('Error: Invalid request.');
    });

    // The server leaves `validation` and `stale_warnings` OUT of a step result when there is
    // nothing to say (validation only exists on a step with a post_id; stale_warnings only
    // when non-empty), so on an honest success an inherited one would forge the post-apply
    // validation-failure card and its warnings.
    it('does not forge a validation failure or stale warnings on an honest success', async function () {
        batchResponse = { success: true, data: { ok: true, steps: [{ ok: true }, { ok: true }], failed_at: null, versions: { 42: 5 } } };

        const card = await applyPolluted({
            validation: { ok: false, warnings: [], errors: [{ check: 'planted', message: 'planted failure' }] },
            stale_warnings: [{ token: '--planted-token', message: 'planted warning' }]
        });

        expect(card.textContent).toContain('All changes applied successfully.');
        expect(card.textContent).not.toContain('validation failed');
        expect(card.textContent).not.toContain('planted');
        storedConversation().forEach(function (msg) {
            expect(String(msg.content)).not.toContain('planted');
        });
    });

    // The failing step's own words, or the stated unknown — never an inherited reason.
    it('does not attribute a planted error to a failed step with none of its own', async function () {
        batchResponse = {
            success: true,
            data: { ok: false, steps: [{ ok: false, error_code: '' }], failed_at: 0, rolled_back: true, rollback_errors: [], rollback_error_kinds: [] }
        };

        await applyPolluted({ error: 'planted by a neighbour' });

        expect(lastStatus()).toContain('Error on step 1: Unknown error');
        expect(lastStatus()).not.toContain('planted');
    });

    // A readable list SHORTER than `failed_at` is a shape the exit already handles (#853);
    // the failing step must then be read at an own index, not from a planted numeric key.
    it('does not read the failing step from a planted index past the end of a short list', async function () {
        batchResponse = {
            success: true,
            data: { ok: false, steps: [{ ok: false, error_code: '' }], failed_at: 2, rolled_back: true, rollback_errors: [], rollback_error_kinds: [] }
        };

        await applyPolluted({ 2: { ok: false, error_code: '', error: 'planted by a neighbour' } });

        expect(lastStatus()).toContain('Error on step 3: Unknown error');
        expect(lastStatus()).not.toContain('planted');
    });

    // The pre-execution conflict card keeps "Nothing was applied." and draws no rows that
    // the server never reported.
    it('keeps the true claim on a pre-execution conflict card', async function () {
        batchResponse = { success: false, data: { error: 'The page changed.', error_code: 'composition_conflict' } };

        const card = await applyPolluted({
            rollback_errors: ['could not recreate menu item "Contact"'],
            rolled_back: true
        });

        expect(card.classList.contains('pp-ai-proposal-conflict')).toBe(true);
        const alert = card.querySelector('[role="alert"]');
        expect(alert.textContent).toBe(CONFLICT_CAUSE + ' Nothing was applied.');
        expect(card.textContent).not.toContain('Contact');
    });
});
