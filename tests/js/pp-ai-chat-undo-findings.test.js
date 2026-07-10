/**
 * Tests for ppChatAppendUndoFindings() — restore_composition findings on the
 * chat's "Undo these changes" card (issue #233).
 *
 * Restore is never blocked by current validation rules: undo is the user's safety
 * net, so a snapshot today's validators reject still restores. The findings therefore
 * describe a write that SUCCEEDED. They must render as warnings on a succeeded undo.
 * Rendering them as an error would tell the user undo broke when it worked.
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

const { appendUndoFindings } = require('../../assets/js/pp-ai-chat.js');

const CHROME_FINDING = {
    type: 'template_owned_component',
    severity: 'error',
    message: '"nav" at index 0 is site chrome rendered by the page template.',
    index: 0
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

    it('renders findings as warnings, never as an undo failure', () => {
        const card = newCard();
        appendUndoFindings(card, [CHROME_FINDING]);

        expect(card.children.length).toBe(1);
        const html = card.innerHTML;

        // Warning styling, not the error/failure class the failed-undo path uses.
        expect(html).toContain('pp-ai-step-warning');
        expect(html).not.toContain('pp-ai-link-error');
        expect(html).not.toContain('pp-ai-step-failed');
        expect(html).not.toContain('Undo failed');
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
            { type: 'invalid_composition', severity: 'error', message: 'Unknown component: "ghost".', index: null }
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
        expect(card.textContent).toContain('Show 2 more warnings');
    });
});
