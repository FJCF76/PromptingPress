/**
 * Tests for proposal card helpers in assets/js/pp-ai-chat.js
 *
 * Covers:
 *   getImpactWarning      — server-driven lookup (window.ppAiChat.impact_warnings)
 *   formatDiffValue       — string/object/null formatting for diff display
 *   shouldShowMultiStepWarning — 3+ step threshold
 *   isRevertEligible      — single-step update_design_token detection
 */

// The IIFE in pp-ai-chat.js requires DOM elements + window.ppAiChat.
// Stub the bare minimum so require() doesn't throw.
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

// Config must be set BEFORE requiring the script — the IIFE reads it on load.
dom.window.ppAiChat = {
    configured: true,
    ajaxUrl: '/wp-admin/admin-ajax.php',
    executeNonce: 'test-nonce',
    siteUrl: 'http://example.com',
    streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
    streamNonce: 'stream-nonce',
    // Server-driven destructive warnings (mirrors what lib/ai-chat.php localizes
    // from the action + apply registries). The original 4 are the regression set.
    impact_warnings: {
        update_composition: 'Replaces entire page composition',
        reset_all_design_tokens: 'Resets ALL token overrides to defaults',
        clear_custom_css: 'Removes ALL Custom CSS',
        remove_component: 'Removes component from page'
    }
};
global.window.ppAiChat = dom.window.ppAiChat;

const {
    getImpactWarning,
    formatDiffValue,
    shouldShowMultiStepWarning,
    isRevertEligible,
    compositionUndoTarget,
    renderPreviewError,
    getErrorStepClass,
    getStatusMessage,
    buildCompositionSummary,
} = require('../../assets/js/pp-ai-chat.js');

// ─── getImpactWarning (server-driven from window.ppAiChat.impact_warnings) ────

describe('getImpactWarning', function () {
    // REGRESSION: the original 4 destructive warnings must still render after
    // the move from a hardcoded JS map to the server-driven registry.
    test('returns warning text for update_composition (regression)', function () {
        expect(getImpactWarning('update_composition')).toBe('Replaces entire page composition');
    });

    test('returns warning text for reset_all_design_tokens apply (regression)', function () {
        expect(getImpactWarning('reset_all_design_tokens')).toBe('Resets ALL token overrides to defaults');
    });

    test('returns warning text for clear_custom_css (regression)', function () {
        expect(getImpactWarning('clear_custom_css')).toBe('Removes ALL Custom CSS');
    });

    test('returns warning text for remove_component (regression)', function () {
        expect(getImpactWarning('remove_component')).toBe('Removes component from page');
    });

    test('returns null for normal action (update_component)', function () {
        expect(getImpactWarning('update_component')).toBeNull();
    });

    test('returns null for unknown action', function () {
        expect(getImpactWarning('nonexistent_action')).toBeNull();
    });

    test('returns null for empty string', function () {
        expect(getImpactWarning('')).toBeNull();
    });

    // Safe fallback: if the server provided no map at all, lookups must not
    // throw — they degrade to "no warning".
    test('returns null (no crash) when impact_warnings is absent', function () {
        const saved = global.window.ppAiChat.impact_warnings;
        delete global.window.ppAiChat.impact_warnings;
        try {
            expect(getImpactWarning('update_composition')).toBeNull();
        } finally {
            global.window.ppAiChat.impact_warnings = saved;
        }
    });
});

// ─── formatDiffValue ─────────────────────────────────────────────────────────

describe('formatDiffValue', function () {
    test('handles plain string', function () {
        expect(formatDiffValue('hello')).toBe('hello');
    });

    test('handles empty string', function () {
        expect(formatDiffValue('')).toBe('');
    });

    test('handles number', function () {
        expect(formatDiffValue(42)).toBe('42');
    });

    test('handles boolean', function () {
        expect(formatDiffValue(true)).toBe('true');
    });

    test('handles null', function () {
        expect(formatDiffValue(null)).toBe('(none)');
    });

    test('handles undefined', function () {
        expect(formatDiffValue(undefined)).toBe('(none)');
    });

    test('handles short object (JSON stringified)', function () {
        var obj = { a: 1 };
        expect(formatDiffValue(obj)).toBe('{"a":1}');
    });

    test('truncates long object at 80 chars', function () {
        var obj = {
            a: 'this is a very long string value',
            b: 'another very long string value here',
            c: 'yet another long string for padding'
        };
        var result = formatDiffValue(obj);
        expect(result.length).toBeLessThanOrEqual(80);
        expect(result).toMatch(/\.\.\.$/);
    });

    test('handles array', function () {
        var arr = [1, 2, 3];
        expect(formatDiffValue(arr)).toBe('[1,2,3]');
    });

    test('handles long array (truncated)', function () {
        var arr = [];
        for (var i = 0; i < 50; i++) arr.push('item-' + i);
        var result = formatDiffValue(arr);
        expect(result.length).toBeLessThanOrEqual(80);
        expect(result).toMatch(/\.\.\.$/);
    });
});

// ─── shouldShowMultiStepWarning ──────────────────────────────────────────────

describe('shouldShowMultiStepWarning', function () {
    test('returns false for 1 step', function () {
        expect(shouldShowMultiStepWarning([{}])).toBe(false);
    });

    test('returns false for 2 steps', function () {
        expect(shouldShowMultiStepWarning([{}, {}])).toBe(false);
    });

    test('returns true for 3 steps', function () {
        expect(shouldShowMultiStepWarning([{}, {}, {}])).toBe(true);
    });

    test('returns true for 5 steps', function () {
        expect(shouldShowMultiStepWarning([{}, {}, {}, {}, {}])).toBe(true);
    });

    test('returns false for empty array', function () {
        expect(shouldShowMultiStepWarning([])).toBe(false);
    });

    test('returns falsy for null', function () {
        expect(shouldShowMultiStepWarning(null)).toBeFalsy();
    });

    test('returns falsy for undefined', function () {
        expect(shouldShowMultiStepWarning(undefined)).toBeFalsy();
    });
});

// ─── isRevertEligible ────────────────────────────────────────────────────────

describe('isRevertEligible', function () {
    test('returns true for single-step update_design_token', function () {
        expect(isRevertEligible([{ name: 'update_design_token', params: { token: '--color-bg' } }])).toBe(true);
    });

    test('returns false for single-step update_component', function () {
        expect(isRevertEligible([{ name: 'update_component' }])).toBe(false);
    });

    test('returns false for multi-step even if first is update_design_token', function () {
        expect(isRevertEligible([
            { name: 'update_design_token' },
            { name: 'update_component' }
        ])).toBe(false);
    });

    test('returns false for empty steps', function () {
        expect(isRevertEligible([])).toBe(false);
    });

    test('returns falsy for null', function () {
        expect(isRevertEligible(null)).toBeFalsy();
    });
});

// ─── renderPreviewError ────────────────────────────────────────────────────

describe('renderPreviewError', function () {
    test('structured error shows user_message not raw error', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'invalid_style_slot',
            user_message: 'That style property isn\'t available on the hero component.',
            alternatives: ['--hero-bg', '--hero-heading-color'],
            raw_error: 'Component "hero" has no style slot "--hero-display". Available: --hero-bg, --hero-heading-color'
        });

        var msgEl = diffArea.querySelector('.pp-ai-preview-error-message');
        expect(msgEl).not.toBeNull();
        expect(msgEl.textContent).toContain('isn\'t available');
        expect(msgEl.textContent).not.toContain('Component "hero" has no style slot');
    });

    test('structured error shows alternatives in details disclosure', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: ['--hero-bg', '--hero-heading-color', '--hero-padding-top'],
            cross_component_hints: {},
            raw_error: 'raw'
        });

        var detailEl = diffArea.querySelector('.pp-ai-preview-error-detail');
        expect(detailEl).not.toBeNull();
        expect(detailEl.textContent).toContain('--hero-bg');
        expect(detailEl.textContent).toContain('--hero-heading-color');
        expect(detailEl.textContent).toContain('--hero-padding-top');
        var summary = detailEl.querySelector('summary');
        expect(summary).not.toBeNull();
        expect(summary.textContent).toBe('Show technical details');
    });

    // The three tests below pin what `alternatives` controls in THIS renderer (#627):
    // whether the technical-details disclosure opens at all, and the one line it
    // contributes inside. The same field also decides the step's fixable-vs-impossible
    // class and the status sentence, through ppChatHasSlotAlternatives() ->
    // ppChatGetErrorStepClass() / ppChatGetStatusMessage() (#625); those are pinned in
    // their own describe blocks below. An empty list is not an element that fails to
    // render — there is no per-alternatives element at any size of the list. It is a
    // LINE that is not added to the disclosure's text, and when nothing else would fill
    // the disclosure, the disclosure itself is skipped.
    //
    //   alternatives non-empty ──┐
    //                            ├── OR ──► <details class="pp-ai-preview-error-detail">
    //   raw_error truthy ────────┘                 raw_error line
    //                                              hint lines
    //                                              "Available slots: …" (alternatives only)
    //   neither ─────────────────────────► no <details> at all
    //
    // Asserting on absent ELEMENTS here is what made the predecessor of these tests
    // vacuous: it looked for a class the renderer never writes, so it would have kept
    // passing through any breakage of alternatives rendering.
    //
    // One line per contribution is a cross-layer promise, not a JS-only one. The split
    // below separates them because the sole producer of this payload,
    // _pp_build_friendly_error() (lib/ai-chat.php), routes every caller-supplied string
    // it reflects through _pp_clean_reflected_text(), which strips \p{Cc} — so no
    // reflected text arrives carrying a newline of its own. The renderer does not
    // enforce that itself; a future producer that skips the cleaner could forge a line.

    test('empty alternatives add no slot line, and the disclosure still opens for raw_error', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'no_style_slots',
            user_message: 'This component doesn\'t support style customization.',
            alternatives: [],
            raw_error: 'raw'
        });

        var detailEl = diffArea.querySelector('.pp-ai-preview-error-detail');
        expect(detailEl).not.toBeNull();

        // Line-exact, not a substring scan: the claim is that no slot line was added,
        // and a line is what the renderer joins.
        var lines = detailEl.querySelector(':scope > div').textContent.split('\n');
        expect(lines).toEqual(['raw']);

        expect(diffArea.querySelector('.pp-ai-preview-error-message').textContent).toContain('doesn\'t support');
    });

    test('no disclosure at all when there is neither an alternatives list nor a raw error', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'no_style_slots',
            user_message: 'This component doesn\'t support style customization.',
            alternatives: []
        });

        expect(diffArea.querySelector('.pp-ai-preview-error-detail')).toBeNull();
        expect(diffArea.querySelector('.pp-ai-preview-error-message').textContent).toContain('doesn\'t support');
    });

    test('alternatives alone open the disclosure and are its only line', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: ['--hero-bg', '--hero-heading-color']
        });

        var detailEl = diffArea.querySelector('.pp-ai-preview-error-detail');
        expect(detailEl).not.toBeNull();
        var lines = detailEl.querySelector(':scope > div').textContent.split('\n');
        expect(lines).toEqual(['Available slots: --hero-bg, --hero-heading-color']);
    });

    // #661: the server sentence sends the author to "the details below" for the rest of
    // a sampled slot list. That word is only true while the disclosure is appended AFTER
    // the message element. Nothing else pins order — the sibling tests use querySelector,
    // which finds both elements whichever way round they render — so reordering the
    // appends would leave the suite green while the copy pointed the wrong way.
    test('the disclosure renders below the message the server points down from', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'invalid_style_slot',
            user_message: 'I tried to set "--hero-bgs" on the hero component, but it doesn\'t support that '
                + 'style setting. It has 49 style settings, including --hero-bg. The full list is in the details below.',
            alternatives: ['--hero-bg', '--hero-heading-color'],
            raw_error: 'Component "hero" has no style slot "--hero-bgs".'
        });

        var msgEl = diffArea.querySelector('.pp-ai-preview-error-message');
        var detailEl = diffArea.querySelector('.pp-ai-preview-error-detail');
        expect(msgEl).not.toBeNull();
        expect(detailEl).not.toBeNull();
        expect(msgEl.textContent).toContain('details below');
        // DOCUMENT_POSITION_FOLLOWING === 4: detailEl comes after msgEl in document order.
        expect(msgEl.compareDocumentPosition(detailEl) & 4).toBe(4);
    });

    test('plain string error renders as text content', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, 'Permission denied.');

        expect(diffArea.textContent).toBe('Permission denied.');
        expect(diffArea.querySelector('.pp-ai-preview-error-message')).toBeNull();
    });

    test('fallback for unexpected data shape', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, { message: 'Something went wrong' });

        expect(diffArea.textContent).toBe('Something went wrong');
    });

    test('null data shows generic fallback', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, null);

        expect(diffArea.textContent).toBe('Preview failed');
    });

    test('cross-component hint element rendered when hints present', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'invalid_style_slot',
            user_message: 'Not available on section.',
            alternatives: ['--section-bg'],
            cross_component_hints: { '--grid-gap': { component: 'grid', slot: '--grid-gap', match: 'exact' } },
            raw_error: 'raw'
        });

        var hintEl = diffArea.querySelector('.pp-ai-preview-error-hint');
        expect(hintEl).not.toBeNull();
        expect(hintEl.textContent).toContain('grid');

        // The hint also contributes a line INSIDE the disclosure, between the raw error
        // and the slot list. Line-exact, so the order the renderer joins them in is
        // pinned too, not just their presence.
        var detailEl = diffArea.querySelector('.pp-ai-preview-error-detail');
        expect(detailEl.querySelector(':scope > div').textContent.split('\n')).toEqual([
            'raw',
            'Available on grid: --grid-gap',
            'Available slots: --section-bg'
        ]);
    });

    test('cross-component hint element absent when no hints', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: ['--hero-bg'],
            cross_component_hints: {},
            raw_error: 'raw'
        });

        var hintEl = diffArea.querySelector('.pp-ai-preview-error-hint');
        expect(hintEl).toBeNull();
    });

    test('grouped alternatives in details disclosure with descriptions', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: ['--hero-bg', '--hero-heading-color'],
            cross_component_hints: {},
            raw_error: 'raw error text'
        });

        var detailEl = diffArea.querySelector('.pp-ai-preview-error-detail');
        expect(detailEl).not.toBeNull();
        expect(detailEl.textContent).toContain('--hero-bg');
        expect(detailEl.textContent).toContain('--hero-heading-color');
    });

    // A hint with nothing else in the payload: the visible line renders and the
    // disclosure does NOT open, so the "Available on X: slot" line is dropped. That
    // predates #667 (the disclosure has always been gated on alternatives-or-raw_error)
    // and #667 kept it rather than deciding it. Pinned in the direction it actually
    // behaves, so a future change to it is a choice someone makes, not a side effect.
    test('a hint alone renders its line but does not open the disclosure', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'invalid_style_slot',
            user_message: 'Not available on section.',
            alternatives: [],
            cross_component_hints: { '--grid-gap': { component: 'grid', slot: '--grid-gap', match: 'exact' } }
        });

        expect(diffArea.querySelector('.pp-ai-preview-error-hint').textContent)
            .toBe('This setting exists on the grid component.');
        expect(diffArea.querySelector('.pp-ai-preview-error-detail')).toBeNull();
    });
});

// ─── renderPreviewError — shapes the classifiers reject (#667) ──────────────

// The card reads one payload with three readers: this renderer, ppChatGetErrorStepClass
// (the colour), and ppChatGetStatusMessage (the sentence). Before #667 the renderer asked
// weaker questions than the other two — a `length`, or truthiness — so a payload could
// answer YES to the renderer and NO to the classifiers:
//
//   alternatives: [null, {a:1}]  ──► renderer: "Available slots: , [object Object]"
//                                └─► class:    pp-ai-step-impossible   (grey, dead end)
//                                └─► status:   "This change isn't possible…"
//
// Every test below therefore asserts on ALL THREE, not on the renderer alone: the defect
// is not "the line is ugly", it is "one card says two opposite things". Asserting the
// line is gone while leaving the class unread would pass just as well if the renderer
// stopped rendering anything at all.
//
// None of these payloads is reachable through _pp_build_friendly_error() (lib/ai-chat.php,
// sole producer, no filter on the path). They are the tripwire #625 accepted for the
// classifiers, now extended to the renderer that reads the same object.
describe('renderPreviewError agrees with the classifiers about the payload', function () {
    function renderCard(data) {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, data);
        return diffArea;
    }

    /** The disclosure's lines, as the renderer joined them. */
    function detailLines(diffArea) {
        return diffArea.querySelector('.pp-ai-preview-error-detail')
            .querySelector(':scope > div').textContent.split('\n');
    }

    test('a list of empty strings names nothing, so no half of the card says it does', function () {
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: [''],
            cross_component_hints: {}
        };
        var diffArea = renderCard(data);

        expect(diffArea.textContent).not.toContain('Available slots');
        expect(diffArea.querySelector('.pp-ai-preview-error-detail')).toBeNull();
        expect(getErrorStepClass(data)).toBe('pp-ai-step-impossible');
        expect(getStatusMessage(data)).toContain('isn\'t possible');
    });

    test('nulls and objects in the list render no line and no [object Object]', function () {
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: [null, { a: 1 }],
            cross_component_hints: {}
        };
        var diffArea = renderCard(data);

        expect(diffArea.textContent).not.toContain('[object Object]');
        expect(diffArea.textContent).not.toContain('Available slots');
        expect(getErrorStepClass(data)).toBe('pp-ai-step-impossible');
    });

    test('a mixed list prints exactly the entries that are names', function () {
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: ['--hero-bg', null, '', 42, '--hero-pad'],
            cross_component_hints: {}
        };
        var diffArea = renderCard(data);

        // Line-exact: the claim is which entries survived, and a line is what the
        // renderer joins. A substring scan would pass on ", 42" too.
        expect(detailLines(diffArea)).toEqual(['Available slots: --hero-bg, --hero-pad']);
        // The list names something, so this one IS fixable — the guard drops entries,
        // it does not condemn the payload.
        expect(getErrorStepClass(data)).toBe('pp-ai-step-fixable');
    });

    test('whitespace still counts as a name, because #625 says it does', function () {
        // Not an endorsement: it pins that the renderer did not quietly adopt a
        // stricter test than the class it has to agree with. Whether ' ' should be a
        // name at all belongs to #775, which owns the element-type contract.
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: ['   '],
            cross_component_hints: {}
        };
        expect(getErrorStepClass(data)).toBe('pp-ai-step-fixable');
        expect(renderCard(data).textContent).toContain('Available slots:    ');
    });

    test('a string in place of the list renders nothing and throws nothing', function () {
        // `alternatives.join` is undefined on a string, so this used to throw out of the
        // renderer — recoverable since #663, but as a generic "could not display" card
        // that loses the server's actual answer. Now the string is simply not a list.
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: '--hero-bg',
            cross_component_hints: {}
        };
        var diffArea;
        expect(function () { diffArea = renderCard(data); }).not.toThrow();

        expect(diffArea.querySelector('.pp-ai-preview-error-message').textContent)
            .toBe('Not available.');
        expect(diffArea.querySelector('.pp-ai-preview-error-detail')).toBeNull();
        expect(getErrorStepClass(data)).toBe('pp-ai-step-impossible');
    });

    test('an array of hints is not a hint map, in the card as in the class', function () {
        // typeof [] === 'object' and Object.keys([...]) counts, which is why
        // ppChatHasCrossComponentHint() carries an explicit Array.isArray rejection.
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: [],
            cross_component_hints: [{ component: 'grid', slot: '--grid-gap' }],
            raw_error: 'raw'
        };
        var diffArea = renderCard(data);

        expect(diffArea.querySelector('.pp-ai-preview-error-hint')).toBeNull();
        expect(diffArea.textContent).not.toContain('Available on');
        expect(getErrorStepClass(data)).toBe('pp-ai-step-impossible');
        expect(getStatusMessage(data)).toContain('isn\'t possible');
    });

    test('hint entries that name no component print no hint line', function () {
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: [],
            cross_component_hints: { '--x': null, '--y': 'grid', '--z': { slot: '--grid-gap' } },
            raw_error: 'raw'
        };
        var diffArea = renderCard(data);

        expect(diffArea.querySelector('.pp-ai-preview-error-hint')).toBeNull();
        expect(diffArea.textContent).not.toContain('undefined');
        expect(detailLines(diffArea)).toEqual(['raw']);

        // THE RESIDUAL, PINNED SO IT IS A KNOWN COST AND NOT A SURPRISE: the map has
        // keys, so the classifier still calls this fixable and the status bar still
        // sends the author to a component the card does not name. #667 chose that over
        // "exists on the undefined component"; closing it means tightening
        // ppChatHasCrossComponentHint(), which moves what #625 landed — filed as #789.
        expect(getErrorStepClass(data)).toBe('pp-ai-step-fixable');
        expect(getStatusMessage(data)).toContain('different component');
    });

    test('a well-formed hint beside malformed ones is the one that renders', function () {
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: ['--section-bg'],
            cross_component_hints: {
                '--x': null,
                '--grid-gap': { component: 'grid', slot: '--grid-gap', match: 'exact' }
            }
        };
        var diffArea = renderCard(data);

        expect(diffArea.querySelector('.pp-ai-preview-error-hint').textContent)
            .toBe('This setting exists on the grid component.');
        expect(detailLines(diffArea)).toEqual([
            'Available on grid: --grid-gap',
            'Available slots: --section-bg'
        ]);
    });

    // The shipped payload can carry SEVERAL hints: _pp_build_friendly_error()
    // (lib/ai-chat.php) writes one entry per rejected slot, up to
    // PP_CROSS_COMPONENT_HINT_MAX. Two things about that are contracts, not accidents,
    // and neither is pinned by the single-entry maps every other test here uses:
    // the visible line names the FIRST entry — the same one the server's user_message
    // names, since it picks its subject with reset() on this map — and the disclosure
    // lists them in payload order. Naming the wrong one puts the line in direct conflict
    // with the sentence right above it, which is the disagreement #667 exists to close.
    test('several hints: the visible line names the first, the disclosure lists them in order', function () {
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available on section.',
            alternatives: ['--section-bg'],
            cross_component_hints: {
                '--grid-gap': { component: 'grid', slot: '--grid-gap', match: 'exact' },
                '--cta-pad': { component: 'cta', slot: '--cta-pad', match: 'suffix' }
            },
            raw_error: 'raw'
        };
        var diffArea = renderCard(data);

        expect(diffArea.querySelector('.pp-ai-preview-error-hint').textContent)
            .toBe('This setting exists on the grid component.');
        expect(detailLines(diffArea)).toEqual([
            'raw',
            'Available on grid: --grid-gap',
            'Available on cta: --cta-pad',
            'Available slots: --section-bg'
        ]);
    });

    test('a hint that names a component but no slot draws neither of its lines', function () {
        // Both lines come from ONE list, so the entry test is what a drawable hint
        // needs for the WIDER of the two uses: the disclosure line prints the slot.
        // A component-only entry could in principle still fill the visible line, and
        // the choice not to split the test is deliberate — one question about the
        // field, asked once — but it is a choice, so it is pinned rather than left to
        // whichever branch someone reads first. No producer emits this shape.
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: [],
            cross_component_hints: { '--grid-gap': { component: 'grid' } },
            raw_error: 'raw'
        };
        var diffArea = renderCard(data);

        expect(diffArea.querySelector('.pp-ai-preview-error-hint')).toBeNull();
        expect(detailLines(diffArea)).toEqual(['raw']);

        // Same residual as the entries-name-nothing case: the classifier counts the
        // map's keys, so the sentence still points at a component the card does not
        // name (#789).
        expect(getErrorStepClass(data)).toBe('pp-ai-step-fixable');
        expect(getStatusMessage(data)).toContain('different component');
    });

    test('a raw_error that is not text contributes no line', function () {
        var data = {
            error_code: 'invalid_style_slot',
            user_message: 'Not available.',
            alternatives: ['--hero-bg'],
            cross_component_hints: {},
            raw_error: { code: 'invalid_style_slot' }
        };
        var diffArea = renderCard(data);

        expect(diffArea.textContent).not.toContain('[object Object]');
        expect(detailLines(diffArea)).toEqual(['Available slots: --hero-bg']);
    });

    test('a non-text raw_error alone leaves no disclosure to open', function () {
        var data = {
            error_code: 'no_style_slots',
            user_message: 'This component doesn\'t support style customization.',
            alternatives: [],
            cross_component_hints: {},
            raw_error: {}
        };
        var diffArea = renderCard(data);

        // The invariant the guards buy: the two fields that OPEN the disclosure are the
        // two that write lines into it, so it can never open holding nothing.
        expect(diffArea.querySelector('.pp-ai-preview-error-detail')).toBeNull();
        expect(diffArea.querySelector('.pp-ai-preview-error-message').textContent)
            .toContain('doesn\'t support');
    });

    test('a payload whose message is not text falls to the plain branch', function () {
        var data = {
            error_code: 'invalid_style_slot',
            user_message: { text: 'Not available.' },
            alternatives: ['--hero-bg'],
            cross_component_hints: {}
        };
        var diffArea = renderCard(data);

        // No structured card at all: there is no honest sentence to head it with, and
        // the card has no vocabulary for "the server sent something I couldn't read".
        // Degrading to the plain branch is the remedy the issue names for a shape the
        // renderer cannot consume; the alternative it replaces was heading the card with
        // "[object Object]", which is a false sentence rather than a generic one.
        expect(diffArea.querySelector('.pp-ai-preview-error-message')).toBeNull();
        expect(diffArea.textContent).toBe('Preview failed');

        // The cost, pinned: the classifiers never read user_message, so they still
        // classify from the rest of the payload and the status bar still points at
        // details this card no longer draws. Same residual as the hint case above —
        // a pointer that goes nowhere, not a card claiming two opposite things — and it
        // closes from the classifier side, which #625 owns (#789).
        expect(getErrorStepClass(data)).toBe('pp-ai-step-fixable');
        expect(getStatusMessage(data)).toContain('above');
    });

    test('the plain branch does not print [object Object] either', function () {
        expect(renderCard({ message: { nested: true } }).textContent).toBe('Preview failed');
        // The string path through the same branch is untouched — it is where every
        // caught render failure lands (#663), carrying a bounded sentence.
        expect(renderCard('Preview could not be displayed: boom').textContent)
            .toBe('Preview could not be displayed: boom');
        expect(renderCard({ message: 'Something went wrong' }).textContent)
            .toBe('Something went wrong');
    });
});

// ─── getErrorStepClass ────────────────────────────────────────────────────

describe('getErrorStepClass', function () {
    test('returns pp-ai-step-impossible for no_style_slots', function () {
        expect(getErrorStepClass({ error_code: 'no_style_slots' })).toBe('pp-ai-step-impossible');
    });

    test('returns pp-ai-step-fixable for invalid_style_slot with cross-component hint', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            cross_component_hints: { '--grid-gap': { component: 'grid', slot: '--grid-gap', match: 'exact' } }
        })).toBe('pp-ai-step-fixable');
    });

    // #625: the near-miss. `--hero-bgs` normalizes to a suffix no other component
    // declares, so the cross-component scan finds nothing — but the slot the author
    // meant is right there in `alternatives`. This is the exact payload the real
    // validator produces (pinned server-side in tests/PreviewErrorActionabilityTest.php),
    // and it used to be painted grey under "this change isn't possible".
    test('returns pp-ai-step-fixable for invalid_style_slot whose alternatives name the settings', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            alternatives: ['--hero-bg', '--hero-heading-color'],
            cross_component_hints: {}
        })).toBe('pp-ai-step-fixable');
    });

    test('returns pp-ai-step-impossible for invalid_style_slot naming neither a hint nor an alternative', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            alternatives: [],
            cross_component_hints: {}
        })).toBe('pp-ai-step-impossible');
    });

    test('an absent alternatives key is not an alternative', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            cross_component_hints: {}
        })).toBe('pp-ai-step-impossible');
    });

    // A malformed payload names nothing, so it must not be promoted to fixable on the
    // strength of a truthy field: a string has a length and an object has keys, and
    // both would sail through a laxer test than Array.isArray / typeof.
    test('a non-array alternatives value names nothing', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            alternatives: '--hero-bg',
            cross_component_hints: {}
        })).toBe('pp-ai-step-impossible');
    });

    test('a non-object cross_component_hints value names nothing', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            alternatives: [],
            cross_component_hints: 'grid'
        })).toBe('pp-ai-step-impossible');
    });

    // typeof [] is 'object' and Object.keys(['x']).length is 1, so an array sails
    // through the loose shape test the map check replaced.
    test('an array cross_component_hints value is not a hint map', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            alternatives: [],
            cross_component_hints: ['--grid-gap']
        })).toBe('pp-ai-step-impossible');
    });

    // Length is not the question — "does it name a setting" is. These have length.
    test('alternatives holding no usable name names nothing', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            alternatives: [null, '', {}],
            cross_component_hints: {}
        })).toBe('pp-ai-step-impossible');
    });

    test('alternatives naming one real slot among unusable entries is enough', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            alternatives: [null, '--hero-bg'],
            cross_component_hints: {}
        })).toBe('pp-ai-step-fixable');
    });

    test('returns pp-ai-step-fixable for invalid_style_value', function () {
        expect(getErrorStepClass({ error_code: 'invalid_style_value' })).toBe('pp-ai-step-fixable');
    });

    test('returns pp-ai-step-failed for a non-object payload', function () {
        expect(getErrorStepClass('Preview failed')).toBe('pp-ai-step-failed');
    });

    test('returns pp-ai-step-failed for an unrecognized error code', function () {
        expect(getErrorStepClass({ error_code: 'component_not_found' })).toBe('pp-ai-step-failed');
    });
});

// ─── getStatusMessage ────────────────────────────────────────────────────

describe('getStatusMessage', function () {
    test('cross-component hint message', function () {
        var msg = getStatusMessage({
            error_code: 'invalid_style_slot',
            cross_component_hints: { '--grid-gap': { component: 'grid' } }
        });
        expect(msg).toContain('different component');
    });

    test('impossible message for no_style_slots', function () {
        var msg = getStatusMessage({ error_code: 'no_style_slots', cross_component_hints: {} });
        expect(msg).toContain('isn\'t possible');
    });

    // #625: the bar must say what the step's colour says. A rejection that names
    // settings the component does have is not "impossible" — they are printed in
    // user_message on the card above this bar (a sample of them, since #661).
    test('invalid_style_slot with alternatives points at the available settings', function () {
        var msg = getStatusMessage({
            error_code: 'invalid_style_slot',
            alternatives: ['--hero-bg'],
            cross_component_hints: {}
        });
        expect(msg).not.toContain('isn\'t possible');
        // Blames the name, not the component: the capability the author asked for is
        // in `alternatives` right there, so denying it would be the #625 bug in words.
        expect(msg).toContain('setting name this component doesn\'t have');
        // And points at settings that are really up there. Since #661 user_message names
        // a SAMPLE of the declared slots plus a total count, so the bar points at them
        // without claiming the sample is the whole list — on hero that would be 5 of 49.
        expect(msg).toContain('above');
        expect(msg).not.toContain('listed above');
    });

    test('invalid_style_slot naming nothing still reports the change as impossible', function () {
        var msg = getStatusMessage({
            error_code: 'invalid_style_slot',
            alternatives: [],
            cross_component_hints: {}
        });
        expect(msg).toContain('isn\'t possible');
    });

    // The settings sentence is gated on the error code, not on alternatives alone.
    // `invalid_recipe` also ships a non-empty `alternatives` list — the component's
    // recipe names — and calling those "setting names" would misdescribe them.
    test('invalid_recipe keeps the generic message despite carrying alternatives', function () {
        var msg = getStatusMessage({
            error_code: 'invalid_recipe',
            alternatives: ['dark', 'tight'],
            cross_component_hints: {}
        });
        expect(msg).toContain('couldn\'t be previewed');
        expect(msg).not.toContain('setting name');
    });

    // The bar must not narrate a hint the step's colour ignores. getErrorStepClass reads
    // hints only inside the invalid_style_slot arm, so the bar carries the same gate;
    // no shipped payload puts hints under another code, and neither reader assumes it.
    test('hints under another error code do not produce the retarget sentence', function () {
        var msg = getStatusMessage({
            error_code: 'component_not_found',
            cross_component_hints: { '--grid-gap': { component: 'grid' } }
        });
        expect(msg).not.toContain('different component');
        expect(msg).toContain('couldn\'t be previewed');
    });

    test('a cross-component hint outranks alternatives in the status bar', function () {
        var msg = getStatusMessage({
            error_code: 'invalid_style_slot',
            alternatives: ['--hero-bg'],
            cross_component_hints: { '--grid-gap': { component: 'grid' } }
        });
        expect(msg).toContain('different component');
    });

    test('fixable message for invalid_style_value', function () {
        var msg = getStatusMessage({ error_code: 'invalid_style_value', cross_component_hints: {} });
        expect(msg).toContain('value format');
    });

    test('fallback message for unknown error', function () {
        var msg = getStatusMessage({ error_code: 'something_else', cross_component_hints: {} });
        expect(msg).toContain('couldn\'t be previewed');
    });
});

// ─── buildCompositionSummary ───────────────────────────────────────────────

describe('buildCompositionSummary', function () {
    test('reports component count change', function () {
        var result = buildCompositionSummary(
            [{ component: 'hero', props: {} }],
            [{ component: 'hero', props: {} }, { component: 'cta', props: {} }]
        );
        expect(result.fromCount).toBe(1);
        expect(result.toCount).toBe(2);
        expect(result.lines[0]).toContain('1');
        expect(result.lines[0]).toContain('2');
    });

    test('identifies added components', function () {
        var result = buildCompositionSummary(
            [{ component: 'hero', props: {} }],
            [{ component: 'hero', props: {} }, { component: 'cta', props: {} }, { component: 'stats', props: {} }]
        );
        expect(result.lines.join('\n')).toContain('+ Added: cta, stats');
    });

    test('identifies removed components', function () {
        var result = buildCompositionSummary(
            [{ component: 'hero', props: {} }, { component: 'grid', props: {} }, { component: 'cta', props: {} }],
            [{ component: 'hero', props: {} }]
        );
        expect(result.lines.join('\n')).toContain('Removed: grid, cta');
    });

    test('detects reordered components', function () {
        var result = buildCompositionSummary(
            [{ component: 'hero', props: {} }, { component: 'cta', props: {} }],
            [{ component: 'cta', props: {} }, { component: 'hero', props: {} }]
        );
        expect(result.lines.join('\n')).toContain('reordered');
    });

    test('does not report reorder when types differ', function () {
        var result = buildCompositionSummary(
            [{ component: 'hero', props: {} }],
            [{ component: 'cta', props: {} }]
        );
        expect(result.lines.join('\n')).not.toContain('reordered');
    });

    test('detects content changes in text fields', function () {
        var result = buildCompositionSummary(
            [{ component: 'hero', props: { title: 'Old Title' } }],
            [{ component: 'hero', props: { title: 'New Title' } }]
        );
        expect(result.lines.join('\n')).toContain('Content changes in 1 component');
    });

    test('shows component type list', function () {
        var result = buildCompositionSummary(
            [],
            [{ component: 'hero', props: {} }, { component: 'services', props: {} }, { component: 'cta', props: {} }]
        );
        expect(result.lines.join('\n')).toContain('hero');
        expect(result.lines.join('\n')).toContain('services');
        expect(result.lines.join('\n')).toContain('cta');
    });

    test('handles empty from array (new page)', function () {
        var result = buildCompositionSummary(
            [],
            [{ component: 'hero', props: {} }, { component: 'cta', props: {} }]
        );
        expect(result.fromCount).toBe(0);
        expect(result.toCount).toBe(2);
        expect(result.lines[0]).toContain('0');
    });

    test('handles large HVAC-style homepage composition', function () {
        var from = [{ component: 'hero', props: { title: 'Old Hero' } }];
        var to = [
            { component: 'hero', props: { title: 'Expert HVAC Services' } },
            { component: 'services', props: { title: 'Our Services' } },
            { component: 'stats', props: { title: 'Why Choose Us' } },
            { component: 'testimonials', props: { title: 'Customer Reviews' } },
            { component: 'cta', props: { title: 'Get a Free Quote' } },
            { component: 'grid', props: { title: 'Service Areas' } },
            { component: 'section', props: { title: 'About Us' } },
            { component: 'cta', props: { title: 'Emergency Service' } },
        ];
        var result = buildCompositionSummary(from, to);
        expect(result.fromCount).toBe(1);
        expect(result.toCount).toBe(8);
        expect(result.lines[0]).toContain('1');
        expect(result.lines[0]).toContain('8');
        // Should report multiple additions
        expect(result.lines.join('\n')).toContain('+ Added');
        expect(result.lines.join('\n')).toContain('Content changes');
    });

    test('handles null/undefined inputs gracefully', function () {
        var result = buildCompositionSummary(null, null);
        expect(result.fromCount).toBe(0);
        expect(result.toCount).toBe(0);
    });
});

// ─── compositionUndoTarget (#133 — "Undo these changes" affordance) ───────────

describe('compositionUndoTarget', function () {
    test('single remove_component proposal → stepsBack 1 on its post', function () {
        var steps = [{ name: 'remove_component', params: { post_id: 42, component_index: 1 } }];
        expect(compositionUndoTarget(steps)).toEqual({ postId: 42, stepsBack: 1 });
    });

    test('multi-step composition proposal on one post → stepsBack = count', function () {
        var steps = [
            { name: 'add_component', params: { post_id: 7, component: 'hero', props: {} } },
            { name: 'update_component', params: { post_id: 7 } },
            { name: 'remove_component', params: { post_id: 7, component_index: 0 } },
        ];
        expect(compositionUndoTarget(steps)).toEqual({ postId: 7, stepsBack: 3 });
    });

    test('ignores non-composition steps when counting', function () {
        var steps = [
            { name: 'update_design_token', params: { token: '--color-accent' } },
            { name: 'remove_component', params: { post_id: 9, component_index: 0 } },
        ];
        expect(compositionUndoTarget(steps)).toEqual({ postId: 9, stepsBack: 1 });
    });

    test('returns null when no composition mutations present', function () {
        var steps = [{ name: 'update_design_token', params: { token: '--color-accent' } }];
        expect(compositionUndoTarget(steps)).toBeNull();
    });

    test('returns null when composition mutations span multiple posts', function () {
        var steps = [
            { name: 'remove_component', params: { post_id: 1, component_index: 0 } },
            { name: 'add_component', params: { post_id: 2, component: 'hero', props: {} } },
        ];
        expect(compositionUndoTarget(steps)).toBeNull();
    });

    test('returns null when a composition mutation lacks a post target', function () {
        var steps = [{ name: 'remove_component', params: { component_index: 0 } }];
        expect(compositionUndoTarget(steps)).toBeNull();
    });

    test('returns null for empty or missing steps', function () {
        expect(compositionUndoTarget([])).toBeNull();
        expect(compositionUndoTarget(null)).toBeNull();
        expect(compositionUndoTarget(undefined)).toBeNull();
    });

    test('treats string post_id consistently (wp_localize casts to string)', function () {
        var steps = [
            { name: 'remove_component', params: { post_id: '5', component_index: 0 } },
            { name: 'add_component', params: { post_id: 5, component: 'hero', props: {} } },
        ];
        expect(compositionUndoTarget(steps)).toEqual({ postId: '5', stepsBack: 2 });
    });
});
