/**
 * tests/js/pp-ai-chat-component-plural.test.js — the composition-diff card counts
 * components in English (#889).
 *
 * THE BUG. assets/js/pp-ai-chat.js hardcoded `count + ' components'`, so a proposal that
 * replaced a page with a SINGLE band rendered
 *
 *     Full composition replacement: unreadable -> 1 components
 *
 * while the rollback heading two screens down in the same file already pluralized correctly
 * ("1 change" / "3 changes"). Cosmetic, found in the v1.19.0 release smoke.
 *
 * THREE SITES, NOT TWO. The issue named the two summary lines. The card's raw-JSON
 * disclosure label reads the SAME count (`summary.toCount`) a few rows below the headline,
 * and had the same literal — so fixing only the named two would have shipped a card that
 * disagreed with itself: "0 -> 1 component" above "View raw composition JSON (1 components,
 * 2 KB)". All three now route through ppChatComponentCount().
 *
 * WHAT IS PINNED, AND AT WHICH GRAIN.
 *
 *   the helper      counts 0, 1, 2 and a large count, because the rule has to be right at
 *                   zero as well ("0 -> 0 components" is a real card) and the file also
 *                   carries a weaker `> 1 ? 's' : ''` form elsewhere that would be wrong
 *                   there.
 *   the two summary lines   through the exported builder, on BOTH of its branches — the
 *                   ordinary diff and the #836 unreadable-before branch, which is the exact
 *                   string the smoke reported.
 *   the raw-JSON label      through a real JSDOM RENDER of ppChatRenderCompositionDiff(),
 *                   not a source-text tripwire. A tripwire would assert the implementation
 *                   spells a particular helper name; this asserts what the operator reads.
 *
 * THE 0 AND 2 CASES ARE REGRESSION PINS, deliberately. Only n === 1 changes text; every
 * other count renders exactly the bytes it rendered before. A "fix" that started emitting
 * "0 component" would pass a naive one-count test and be a new bug.
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
    impact_warnings: {},
};
global.window.ppAiChat = dom.window.ppAiChat;

const {
    componentCount,
    buildCompositionSummary,
    renderCompositionDiff,
} = require('../../assets/js/pp-ai-chat.js');

/** N bands, so a count is produced by a real composition rather than asserted directly. */
function bands(n) {
    const out = [];
    for (let i = 0; i < n; i++) {
        out.push({ component: 'hero', props: { id: 'band-' + i, title: 'Band ' + i } });
    }
    return out;
}

/**
 * The server's unreadable-composition marker (#836), as the before side of a diff.
 * All four keys are load-bearing: ppChatIsUnreadableComposition() requires `unreadable`
 * strictly true plus a non-empty `message` AND a non-empty `classification`, so a marker
 * missing one of them falls through to the ordinary diff branch and this file would be
 * testing the wrong arm.
 */
const UNREADABLE = {
    unreadable: true,
    classification: 'decode_error',
    message: 'This page\'s stored contents cannot be read.',
};

describe('ppChatComponentCount', () => {
    it('uses the singular for exactly one', () => {
        expect(componentCount(1)).toBe('1 component');
    });

    it('uses the plural for zero', () => {
        expect(componentCount(0)).toBe('0 components');
    });

    it('uses the plural for two and above', () => {
        expect(componentCount(2)).toBe('2 components');
        expect(componentCount(11)).toBe('11 components');
    });
});

describe('buildCompositionSummary — ordinary diff headline', () => {
    it('says "1 component" when the result holds one band', () => {
        const summary = buildCompositionSummary(bands(3), bands(1));
        expect(summary.lines[0]).toBe('Full composition replacement: 3 → 1 component');
    });

    it('still says "components" at zero', () => {
        const summary = buildCompositionSummary(bands(2), bands(0));
        expect(summary.lines[0]).toBe('Full composition replacement: 2 → 0 components');
    });

    it('still says "components" above one', () => {
        const summary = buildCompositionSummary(bands(1), bands(4));
        expect(summary.lines[0]).toBe('Full composition replacement: 1 → 4 components');
    });
});

describe('buildCompositionSummary — unreadable-before branch', () => {
    // The exact sentence the v1.19.0 smoke reported.
    it('says "1 component" when the result holds one band', () => {
        const summary = buildCompositionSummary(UNREADABLE, bands(1));
        expect(summary.lines[0]).toBe('Full composition replacement: unreadable → 1 component');
    });

    it('still says "components" at zero and above one', () => {
        expect(buildCompositionSummary(UNREADABLE, bands(0)).lines[0])
            .toBe('Full composition replacement: unreadable → 0 components');
        expect(buildCompositionSummary(UNREADABLE, bands(5)).lines[0])
            .toBe('Full composition replacement: unreadable → 5 components');
    });

    it('leaves the rest of the branch alone', () => {
        const summary = buildCompositionSummary(UNREADABLE, bands(1));
        expect(summary.notice).toBe(UNREADABLE.message);
        expect(summary.fromCount).toBeNull();
        expect(summary.toCount).toBe(1);
    });
});

describe('buildCompositionSummary — the content-changes line keeps its gate', () => {
    // THE ONE SITE THE #889 HELPER DELIBERATELY DID NOT TAKE OVER. That line still spells
    // the rule inline as `(n > 1 ? 's' : '')`, which is wrong at zero — it would render
    // "Content changes in 0 component". It ships correct only because its call site is
    // gated on `contentChanges > 0`, and nothing pinned that gate. Relaxing the gate, or
    // moving the line, would ship the exact defect #889 just closed, one screen up in the
    // same function, with every other test green. So the gate is what gets pinned.
    function withTitles(titles) {
        return titles.map((t, i) => ({ component: 'hero', props: { id: 'band-' + i, title: t } }));
    }

    it('omits the line entirely when nothing changed', () => {
        const from = withTitles(['Alpha', 'Beta']);
        const text = buildCompositionSummary(from, withTitles(['Alpha', 'Beta'])).lines.join('\n');

        expect(text).not.toContain('Content changes in');
        expect(text).not.toContain('0 component');
    });

    it('is singular at one changed band and plural above one', () => {
        const from = withTitles(['Alpha', 'Beta']);

        expect(buildCompositionSummary(from, withTitles(['CHANGED', 'Beta'])).lines.join('\n'))
            .toContain('Content changes in 1 component');
        expect(buildCompositionSummary(from, withTitles(['CHANGED', 'ALSO'])).lines.join('\n'))
            .toContain('Content changes in 2 components');
    });
});

describe('renderCompositionDiff — the raw-JSON disclosure label', () => {
    function render(from, to) {
        const diffArea = document.createElement('div');
        renderCompositionDiff(diffArea, { from: from, to: to });
        return diffArea;
    }

    it('agrees with the headline above it on a single-band replacement', () => {
        const diffArea = render(bands(2), bands(1));
        const label = diffArea.querySelector('.pp-ai-composition-raw summary').textContent;

        expect(label).toContain('1 component,');
        expect(label).not.toContain('1 components');
        // The card must not contradict itself: same count, same noun, one card.
        expect(diffArea.textContent).toContain('2 → 1 component');
    });

    it('still says "components" at zero and above one', () => {
        expect(render(bands(1), bands(0)).querySelector('.pp-ai-composition-raw summary').textContent)
            .toContain('0 components,');
        expect(render(bands(1), bands(3)).querySelector('.pp-ai-composition-raw summary').textContent)
            .toContain('3 components,');
    });

    it('keeps the KB half of the label intact', () => {
        const label = render(bands(2), bands(1)).querySelector('.pp-ai-composition-raw summary').textContent;
        expect(label).toMatch(/^View raw composition JSON \(1 component, \d+ KB\)$/);
    });
});
