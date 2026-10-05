/**
 * tests/js/pp-ai-chat-content-as-text.test.js — no chat surface renders a content value as
 * HTML (LAYER-3-CONTRACT §8.1, T-12; #1242 T2).
 *
 * Layer 3 widens what a content prop can carry, so the approval card's diff becomes an
 * obligation rather than an accident: `changes[].from` / `changes[].to` reach the DOM as
 * text. This pins both renderers a preview result can take (the generic diff line and the
 * whole-composition summary) with a value carrying `<img onerror>`: the card shows the
 * characters, and no element is created from them.
 *
 * Not red on main by design: it pins behaviour main already has (textContent), so a later
 * change to innerHTML fails here.
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

const { renderPreviewResult } = require('../../assets/js/pp-ai-chat.js');

const HOSTILE = '<img src=x onerror="window.__ppPwned=1">';

function newStep(name) {
    const el = document.createElement('div');
    el.className = 'pp-ai-proposal-step pp-ai-step-executing';
    const diff = document.createElement('div');
    diff.className = 'pp-ai-step-diff';
    el.appendChild(diff);
    return { el: el, diff: diff, step: { name: name, params: {} } };
}

describe('a content value in changes[].from/to renders as text (§8.1)', function () {

    it('the generic diff line shows the characters and creates no element', function () {
        const s = newStep('update_component');
        const failure = renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: { changes: [{ path: 'props.title', from: HOSTILE, to: 'Safe ' + HOSTILE }] },
        });

        expect(failure).toBeNull();
        expect(s.diff.querySelector('img')).toBeNull();
        expect(s.diff.textContent).toContain(HOSTILE);
        expect(s.diff.textContent).toContain('Safe ' + HOSTILE);
        expect(window.__ppPwned).toBeUndefined();
    });

    it('the whole-composition summary shows the characters and creates no element', function () {
        const s = newStep('update_composition');
        const band = { component: 'section', props: { title: HOSTILE, body: HOSTILE } };
        const failure = renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: { changes: [{ path: 'composition', from: [], to: [band] }] },
        });

        expect(failure).toBeNull();
        expect(s.diff.querySelector('img')).toBeNull();
        // The summary's raw view prints the composition as JSON, so the value appears in its
        // JSON spelling (quotes escaped) — as characters, not as an element.
        expect(s.diff.textContent).toContain(JSON.stringify(HOSTILE).slice(1, -1));
        expect(window.__ppPwned).toBeUndefined();
    });
});
