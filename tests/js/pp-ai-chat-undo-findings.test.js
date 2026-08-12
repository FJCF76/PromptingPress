/**
 * Tests for ppChatAppendUndoFindings() — restore_composition findings on the
 * chat's "Undo these changes" card (issue #233, severity rendering #622).
 *
 * Restore is never blocked by current validation rules: undo is the user's safety
 * net, so a snapshot today's validators reject still restores. The findings therefore
 * describe a write that SUCCEEDED — the HEADING stays a warning and still says
 * "Restored", because telling the user undo broke when it worked is its own trust bug.
 *
 * The ITEMS carry their own severity and are styled from it (#622). Every finding used
 * to render as a warning regardless; post-#604 an undo of any pre-vocabulary-freeze
 * snapshot produces error-severity findings ("a normal write of this would be
 * rejected"), and showing those in the advisory grey understates the fix.
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

const { appendUndoFindings, findingClass } = require('../../assets/js/pp-ai-chat.js');

const CHROME_FINDING = {
    type: 'template_owned_component',
    severity: 'error',
    message: '"nav" at index 0 is site chrome rendered by the page template.',
    index: 0
};

const SMELL_FINDING = {
    type: 'consecutive_text_sections',
    severity: 'warning',
    message: 'Three consecutive text-only sections.',
    index: 3
};

function newCard() {
    return document.createElement('div');
}

describe('ppChatAppendUndoFindings', () => {
    it('renders nothing when the restored snapshot is clean', () => {
        const card = newCard();
        appendUndoFindings(card, []);
        expect(card.children.length).toBe(0);
    });

    it('renders nothing when findings is null or undefined', () => {
        const card = newCard();
        appendUndoFindings(card, null);
        appendUndoFindings(card, undefined);
        expect(card.children.length).toBe(0);
    });

    it('keeps the heading a warning — the undo itself succeeded', () => {
        const card = newCard();
        appendUndoFindings(card, [CHROME_FINDING]);

        expect(card.children.length).toBe(1);
        const heading = card.firstChild.firstChild;

        expect(heading.className).toBe('pp-ai-step-warning');
        expect(heading.textContent).toContain('Restored');
        expect(card.innerHTML).not.toContain('pp-ai-link-error');
        expect(card.innerHTML).not.toContain('Undo failed');
    });

    it('styles an error-severity finding as an error, not a warning (#622)', () => {
        const card = newCard();
        appendUndoFindings(card, [CHROME_FINDING]);

        const items = card.querySelectorAll('div.pp-ai-step-warning, div.pp-ai-step-failed');
        // [0] is the heading; [1] is the finding.
        expect(items[1].className).toBe('pp-ai-step-failed');
        expect(items[1].textContent).toContain('site chrome');
    });

    it('styles a warning-severity finding as a warning (#622)', () => {
        const card = newCard();
        appendUndoFindings(card, [SMELL_FINDING]);

        const items = card.querySelectorAll('div.pp-ai-step-warning, div.pp-ai-step-failed');
        expect(items[1].className).toBe('pp-ai-step-warning');
        expect(card.innerHTML).not.toContain('pp-ai-step-failed');
    });

    it('styles a mixed list per item, not per list (#622)', () => {
        const card = newCard();
        appendUndoFindings(card, [SMELL_FINDING, CHROME_FINDING]);

        expect(card.querySelectorAll('.pp-ai-step-failed').length).toBe(1);
        // Heading + the one warning-severity finding.
        expect(card.querySelectorAll('.pp-ai-step-warning').length).toBe(2);
    });

    it('degrades an unknown or missing severity to the warning class (#622)', () => {
        expect(findingClass({ severity: 'error' })).toBe('pp-ai-step-failed');
        expect(findingClass({ severity: 'warning' })).toBe('pp-ai-step-warning');
        expect(findingClass({ type: 'x' })).toBe('pp-ai-step-warning');
        expect(findingClass({ severity: 'ERROR' })).toBe('pp-ai-step-warning');
        expect(findingClass(null)).toBe('pp-ai-step-warning');
    });

    it('states the restore succeeded and names the issue count', () => {
        const card = newCard();
        appendUndoFindings(card, [CHROME_FINDING]);

        const text = card.textContent;
        expect(text).toContain('Restored');
        expect(text).toContain('1 issue');
        expect(text).toContain('"nav" at index 0 is site chrome');
    });

    it('pluralizes the issue count', () => {
        const card = newCard();
        appendUndoFindings(card, [
            CHROME_FINDING,
            // index 1, not null: `Unknown component` is a per-item rule, so since #622 the
            // backend cannot emit it without a locator. `null` is reserved for the one
            // cross-item rule, duplicate_component_id.
            { type: 'invalid_composition', severity: 'error', message: 'Unknown component: "ghost".', index: 1 }
        ]);

        expect(card.textContent).toContain('2 issues');
        expect(card.textContent).toContain('ghost');
    });

    it('is announced to assistive tech as a status, not an alert', () => {
        const card = newCard();
        appendUndoFindings(card, [CHROME_FINDING]);

        const section = card.firstChild;
        expect(section.getAttribute('role')).toBe('status');
        expect(section.getAttribute('aria-live')).toBe('polite');
    });

    it('collapses overflow beyond five findings into a disclosure', () => {
        const card = newCard();
        const many = [];
        for (let i = 0; i < 7; i++) {
            many.push({ type: 'invalid_composition', severity: 'error', message: 'problem ' + i, index: i });
        }
        appendUndoFindings(card, many);

        // Reuses ppChatAppendValidationItems: 5 inline, the rest behind <details>.
        expect(card.querySelectorAll('details').length).toBe(1);
        // "errors", not "warnings": the noun comes from the hidden items' own severities
        // now that the list carries per-item severity (#622).
        expect(card.textContent).toContain('Show 2 more errors');
    });

    it('names the overflow from the hidden items own severities (#622)', () => {
        const warn = (n) => ({ type: 'consecutive_text_sections', severity: 'warning', message: 'smell ' + n, index: n });
        const err = (n) => ({ type: 'invalid_composition', severity: 'error', message: 'broken ' + n, index: n });

        const allWarnings = newCard();
        appendUndoFindings(allWarnings, [warn(0), warn(1), warn(2), warn(3), warn(4), warn(5), warn(6)]);
        expect(allWarnings.textContent).toContain('Show 2 more warnings');

        const mixed = newCard();
        appendUndoFindings(mixed, [warn(0), warn(1), warn(2), warn(3), warn(4), warn(5), err(6)]);
        expect(mixed.textContent).toContain('Show 2 more issues');

        const oneError = newCard();
        appendUndoFindings(oneError, [warn(0), warn(1), warn(2), warn(3), warn(4), err(5)]);
        expect(oneError.textContent).toContain('Show 1 more error');
    });

    it('styles overflow findings by severity too (#622)', () => {
        const card = newCard();
        const many = [];
        for (let i = 0; i < 5; i++) {
            many.push({ type: 'consecutive_text_sections', severity: 'warning', message: 'smell ' + i, index: i });
        }
        many.push(CHROME_FINDING);
        appendUndoFindings(card, many);

        const details = card.querySelector('details');
        expect(details.querySelectorAll('.pp-ai-step-failed').length).toBe(1);
    });
});
