/**
 * Tests for proposal card helpers in assets/js/pp-ai-chat.js
 *
 * Covers:
 *   IMPACT_WARNINGS       — static warning map for high-impact actions
 *   getImpactWarning      — lookup by action name
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
    streamNonce: 'stream-nonce'
};
global.window.ppAiChat = dom.window.ppAiChat;

const {
    IMPACT_WARNINGS,
    getImpactWarning,
    formatDiffValue,
    shouldShowMultiStepWarning,
    isRevertEligible,
    renderPreviewError,
    getErrorStepClass,
    getStatusMessage,
    buildCompositionSummary,
} = require('../../assets/js/pp-ai-chat.js');

// ─── IMPACT_WARNINGS map ────────────────────────────────────────────────────

describe('IMPACT_WARNINGS', function () {
    test('contains update_composition', function () {
        expect(IMPACT_WARNINGS['update_composition']).toBe('Replaces entire page composition');
    });

    test('contains reset_all_design_tokens', function () {
        expect(IMPACT_WARNINGS['reset_all_design_tokens']).toBe('Resets ALL token overrides to defaults');
    });

    test('contains clear_custom_css', function () {
        expect(IMPACT_WARNINGS['clear_custom_css']).toBe('Removes ALL Custom CSS');
    });

    test('contains remove_component', function () {
        expect(IMPACT_WARNINGS['remove_component']).toBe('Removes component from page');
    });

    test('does not contain normal actions like update_component', function () {
        expect(IMPACT_WARNINGS['update_component']).toBeUndefined();
    });
});

// ─── getImpactWarning ────────────────────────────────────────────────────────

describe('getImpactWarning', function () {
    test('returns warning text for update_composition', function () {
        expect(getImpactWarning('update_composition')).toBe('Replaces entire page composition');
    });

    test('returns warning text for reset_all_design_tokens', function () {
        expect(getImpactWarning('reset_all_design_tokens')).toBe('Resets ALL token overrides to defaults');
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
            alternatives: ['--hero-bg', '--hero-text'],
            raw_error: 'Component "hero" has no style slot "--hero-display". Available: --hero-bg, --hero-text'
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
            alternatives: ['--hero-bg', '--hero-text', '--hero-padding-top'],
            cross_component_hints: {},
            raw_error: 'raw'
        });

        var detailEl = diffArea.querySelector('.pp-ai-preview-error-detail');
        expect(detailEl).not.toBeNull();
        expect(detailEl.textContent).toContain('--hero-bg');
        expect(detailEl.textContent).toContain('--hero-text');
        expect(detailEl.textContent).toContain('--hero-padding-top');
        var summary = detailEl.querySelector('summary');
        expect(summary).not.toBeNull();
        expect(summary.textContent).toBe('Show technical details');
    });

    test('structured error without alternatives omits alternatives element', function () {
        var diffArea = document.createElement('div');
        renderPreviewError(diffArea, {
            error_code: 'no_style_slots',
            user_message: 'This component doesn\'t support style customization.',
            alternatives: [],
            raw_error: 'raw'
        });

        var altEl = diffArea.querySelector('.pp-ai-preview-error-alternatives');
        expect(altEl).toBeNull();
        expect(diffArea.querySelector('.pp-ai-preview-error-message').textContent).toContain('doesn\'t support');
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
            alternatives: ['--hero-bg', '--hero-text'],
            cross_component_hints: {},
            raw_error: 'raw error text'
        });

        var detailEl = diffArea.querySelector('.pp-ai-preview-error-detail');
        expect(detailEl).not.toBeNull();
        expect(detailEl.textContent).toContain('--hero-bg');
        expect(detailEl.textContent).toContain('--hero-text');
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

    test('returns pp-ai-step-impossible for invalid_style_slot without hints', function () {
        expect(getErrorStepClass({
            error_code: 'invalid_style_slot',
            cross_component_hints: {}
        })).toBe('pp-ai-step-impossible');
    });

    test('returns pp-ai-step-fixable for invalid_style_value', function () {
        expect(getErrorStepClass({ error_code: 'invalid_style_value' })).toBe('pp-ai-step-fixable');
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
