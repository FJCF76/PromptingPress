/**
 * Tests for the composition CAS baseline helpers in assets/js/pp-ai-chat.js (#404).
 *
 * Covers the pure map/conflict helpers the chat UI uses to carry, refresh, and
 * thread the per-page composition version so a stale chat write is rejected
 * (composition_conflict) instead of silently clobbering a concurrent change:
 *   buildBatchBaselines   — {post_id → version} for a batch's touched pages
 *   applyVersionMap       — merge server post-write versions into the store
 *   isCompositionConflict — detect the structured conflict error payload
 *   batchHitConflict      — detect a batch that failed on a conflict step
 *   batchWasRefusedUpFront — detect the step-less #749 refusal envelope
 *   conflictMessage       — the single user-facing conflict message, whose closing claim
 *                           is now evidence-gated (#797); the state machine behind it lives
 *                           in pp-ai-chat-conflict-rollback.test.js
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
    siteUrl: 'http://example.com',
    streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
    streamNonce: 'stream-nonce',
    impact_warnings: {}
};
global.window.ppAiChat = dom.window.ppAiChat;

const {
    buildBatchBaselines,
    applyVersionMap,
    isCompositionConflict,
    batchHitConflict,
    batchWasRefusedUpFront,
    conflictMessage,
    rollbackErrorReport,
} = require('../../assets/js/pp-ai-chat.js');

describe('buildBatchBaselines', function () {
    test('includes a known baseline for every step that targets a page', function () {
        const steps = [
            { name: 'update_component', params: { post_id: 5 } },
            { name: 'style_component', params: { post_id: 5 } },
            { name: 'update_component', params: { post_id: 8 } },
        ];
        const map = buildBatchBaselines(steps, { 5: 3, 8: 1 });
        expect(map).toEqual({ 5: 3, 8: 1 });
    });

    test('omits a page with no stored baseline (server fails it closed)', function () {
        const steps = [{ name: 'update_component', params: { post_id: 9 } }];
        expect(buildBatchBaselines(steps, { 5: 3 })).toEqual({});
    });

    test('normalizes string post_id keys to numbers', function () {
        const steps = [{ name: 'update_component', params: { post_id: '5' } }];
        expect(buildBatchBaselines(steps, { 5: 2 })).toEqual({ 5: 2 });
    });

    test('skips steps with no post_id (e.g. create_page)', function () {
        const steps = [{ name: 'create_page', params: { title: 'X' } }];
        expect(buildBatchBaselines(steps, { 5: 2 })).toEqual({});
    });

    test('preserves a legacy version-0 baseline', function () {
        const steps = [{ name: 'update_composition', params: { post_id: 7 } }];
        expect(buildBatchBaselines(steps, { 7: 0 })).toEqual({ 7: 0 });
    });
});

describe('applyVersionMap', function () {
    test('merges post-write versions into the store', function () {
        const store = { 5: 1 };
        applyVersionMap(store, { 5: 2, 8: 4 });
        expect(store).toEqual({ 5: 2, 8: 4 });
    });

    test('ignores non-numeric or negative values', function () {
        const store = { 5: 1 };
        applyVersionMap(store, { 5: -1, 6: 'x', 7: null });
        expect(store).toEqual({ 5: 1 });
    });

    test('is a no-op for an empty or missing map', function () {
        const store = { 5: 1 };
        applyVersionMap(store, {});
        applyVersionMap(store, null);
        expect(store).toEqual({ 5: 1 });
    });
});

describe('isCompositionConflict', function () {
    test('true for the structured conflict payload', function () {
        expect(isCompositionConflict({ error_code: 'composition_conflict' })).toBe(true);
    });

    test('false for a plain string error or a different code', function () {
        expect(isCompositionConflict('Some error')).toBe(false);
        expect(isCompositionConflict({ error_code: 'missing_expected_version' })).toBe(false);
        expect(isCompositionConflict(null)).toBe(false);
    });
});

describe('batchHitConflict', function () {
    test('true when the failed step carries a composition_conflict', function () {
        const batch = { ok: false, failed_at: 1, steps: [
            { ok: true }, { ok: false, error_code: 'composition_conflict' },
        ] };
        expect(batchHitConflict(batch)).toBe(true);
    });

    test('false for a successful batch', function () {
        expect(batchHitConflict({ ok: true, failed_at: null, steps: [] })).toBe(false);
    });

    test('false when the failure was a non-conflict error', function () {
        const batch = { ok: false, failed_at: 0, steps: [{ ok: false, error_code: 'invalid_params' }] };
        expect(batchHitConflict(batch)).toBe(false);
    });
});

describe('batchWasRefusedUpFront', function () {
    // #749: the executor refuses a whole proposal before step 1 when a page it
    // names has an unreadable stored composition. That is the one ok:false shape
    // with no failing step, and the failure renderer indexes steps[failed_at] —
    // so this predicate is what keeps it from indexing null and throwing.
    test('true for the step-less refusal envelope', function () {
        const batch = { ok: false, failed_at: null, steps: [], rolled_back: false,
                        error: 'Page 7: composition data integrity error (unexpected_shape).',
                        error_code: 'unexpected_shape' };
        expect(batchWasRefusedUpFront(batch)).toBe(true);
    });

    test('false for an ordinary batch failure that has a failing step', function () {
        const batch = { ok: false, failed_at: 0, steps: [{ ok: false, error: 'nope' }] };
        expect(batchWasRefusedUpFront(batch)).toBe(false);
    });

    test('false for step index 0, which is falsy but a real step', function () {
        // Guards the classic bug: `!batch.failed_at` would call step 0 a refusal.
        expect(batchWasRefusedUpFront({ ok: false, failed_at: 0, steps: [{ ok: false }] })).toBe(false);
    });

    test('false for a successful batch', function () {
        expect(batchWasRefusedUpFront({ ok: true, failed_at: null, steps: [] })).toBe(false);
    });

    test('false for a missing payload rather than throwing', function () {
        expect(batchWasRefusedUpFront(null)).toBe(false);
        expect(batchWasRefusedUpFront(undefined)).toBe(false);
    });
});

describe('conflictMessage', function () {
    /** The pre-execution refusals: an error payload, no `steps`, so no step ran. */
    const PRE_EXEC = { error: 'Page changed.', error_code: 'composition_conflict' };

    test('is a single non-empty message naming the cause and that nothing applied', function () {
        const msg = conflictMessage(PRE_EXEC, rollbackErrorReport(PRE_EXEC));
        expect(typeof msg).toBe('string');
        expect(msg).toMatch(/changed/i);
        expect(msg).toMatch(/nothing was applied/i);
    });

    // The claim needs evidence, not a default (#797). A caller that forgets the payload
    // loses the sentence rather than asserting it over a batch that may have left a page
    // dirty — the fail-closed direction, checked here because it is the whole reason the
    // argument is threaded through showConflictState() instead of defaulted.
    test('withholds the clean claim when given no evidence at all', function () {
        expect(conflictMessage()).toBe(conflictMessage(null, null));
        expect(conflictMessage()).toMatch(/changed/i);
        expect(conflictMessage()).not.toMatch(/nothing was applied/i);
    });
});
