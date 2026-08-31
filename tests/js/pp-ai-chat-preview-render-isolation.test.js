/**
 * One step's render failure must not cost the other steps their previews (#663).
 *
 * Before #663 the batch-preview chain was a bare `results.forEach(...)` inside a
 * `Promise.all(...).then(...)` with no guard anywhere on it. A throw on step 2 of 5
 * abandoned steps 3, 4 and 5 mid-flight: they kept the `pp-ai-step-executing` class and
 * the 'Loading preview' placeholder their card was built with, `addStatusMessage()`
 * never ran, and the Apply/Cancel row was never appended. The card hung, permanently and
 * silently, and the rejection went nowhere.
 *
 * Two halves are pinned here, because the fix has two halves.
 *
 * BEHAVIOUR, against ppChatRenderPreviewResult() — the per-step helper the loop now
 * calls. Every arm of it must reach a terminal state and none may propagate a throw.
 *
 * SHAPE, against the source of the chain itself. The loop lives inside the file's IIFE
 * and cannot be reached from vitest at any price, so the structural half is a source
 * tripwire in the idiom tests/js/pp-ai-chat-error-card-typography.test.js established:
 * a later edit that stops routing results through the helper, or drops the chain's
 * `.catch`, fails here rather than shipping a hang no unit test can see.
 *
 * HOW THE THROWS ARE INJECTED, and why not the obvious way. When these tests were
 * written, ppChatRenderPreviewError() had two reachable throw sites —
 * `hints[keys[0]].component` on a null, `alternatives.join()` on a string — and sending
 * one of those payloads would have been the natural trigger. It was deliberately not
 * used: #667 was open against exactly those two sites, and the day it landed those
 * payloads would stop throwing. Every test written that way would have kept passing
 * while testing nothing at all. That day came (v1.16.5) — both are guarded now, so a
 * payload is no longer a throw source at all. What the tests do instead has not changed
 * and does not need to: the target step gets a real DOM diff area whose `appendChild`
 * throws, which is independent of any payload and is a genuine throw whatever the
 * renderer accepts. The reasoning is kept rather than deleted because it is the reason
 * the file did not have to be rewritten when #667 landed.
 */

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<!DOCTYPE html><html><body>' +
    '<div id="pp-ai-messages"></div>' +
    '<textarea id="pp-ai-input"></textarea>' +
    '<button id="pp-ai-send"></button>' +
    '</body></html>', { url: 'http://localhost' });

global.window = dom.window;
global.document = dom.window.document;
global.HTMLElement = dom.window.HTMLElement;

const {
    renderPreviewResult,
    previewRenderErrorText,
    getStatusMessage,
} = require('../../assets/js/pp-ai-chat.js');

const JS = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/pp-ai-chat.js'),
    'utf-8',
);

/**
 * The placeholder renderProposal() writes into every diff area before the previews land.
 *
 * Built from a char code rather than typed: the source spells it with a unicode escape,
 * and authoring tools in this repo have a habit of silently converting escape TEXT into
 * the character it names, which would make a literal here drift from the source without
 * anyone noticing.
 */
const PLACEHOLDER = 'Loading preview' + String.fromCharCode(0x2026);

/** A step as renderProposal() builds it: executing, with a placeholder diff area. */
function newStep(opts) {
    const el = document.createElement('div');
    el.className = 'pp-ai-proposal-step pp-ai-step-executing';

    const diff = document.createElement('div');
    diff.className = 'pp-ai-step-diff';
    diff.textContent = PLACEHOLDER;
    el.appendChild(diff);

    if (opts && opts.throwOnRender) {
        diff.appendChild = function () {
            throw new TypeError('injected render failure');
        };
    }

    return {
        el: el,
        diff: diff,
        step: { name: (opts && opts.name) || 'update_design_token', params: {} },
    };
}

/**
 * The arrow ppChatBuildCompositionSummary() puts between the two component counts.
 *
 * Built from a char code for the same reason as PLACEHOLDER above: the source spells it
 * as a unicode escape, and a literal here would drift from it silently.
 */
const ARROW = String.fromCharCode(0x2192);

/** Every `pp-ai-step-*` state class currently on an element. */
function stateClasses(el) {
    return Array.from(el.classList).filter((c) => c.indexOf('pp-ai-step-') === 0
        && c !== 'pp-ai-step-diff');
}

const OK_RESULT = {
    success: true,
    data: { changes: [{ path: '--color-accent', from: '#111', to: '#222' }] },
};

/** invalid_style_slot naming another component: classifies as fixable, not failed. */
const FIXABLE_RESULT = {
    success: false,
    data: {
        error_code: 'invalid_style_slot',
        user_message: 'That setting is not on this component.',
        cross_component_hints: { '--hero-bg': { component: 'hero', slot: '--hero-bg' } },
    },
};

/** What an expired nonce actually produces: a bare -1, so `resp.data` is undefined. */
const NO_ENVELOPE_RESULT = { success: undefined, data: undefined };

describe('ppChatRenderPreviewResult — terminal states', () => {
    it('renders a successful preview and reports no failure', () => {
        const s = newStep();
        const failure = renderPreviewResult(s.el, s.diff, s.step, OK_RESULT);

        expect(failure).toBeNull();
        expect(s.el.classList.contains('pp-ai-step-executing')).toBe(false);
        expect(s.diff.textContent).not.toContain('Loading preview');
        expect(s.diff.textContent).toContain('--color-accent');
        expect(s.step._previewChanges).toEqual(OK_RESULT.data.changes);
    });

    it('says so when a preview carries no changes at all', () => {
        const s = newStep();
        const failure = renderPreviewResult(s.el, s.diff, s.step,
            { success: true, data: { changes: [] } });

        expect(failure).toBeNull();
        expect(s.diff.textContent).toBe('(no changes)');
    });

    it('routes an update_composition change to the composition renderer', () => {
        // The hoist moved ppChatRenderCompositionDiff to module scope precisely so this
        // arm could be reached from here. Without this the four-conjunct branch never
        // runs in any test and could be disabled outright without a suite noticing.
        const s = newStep({ name: 'update_composition' });
        const failure = renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [{
                    path: 'composition',
                    from: [{ component: 'hero' }],
                    to: [{ component: 'hero' }, { component: 'cta' }],
                }],
            },
        });

        expect(failure).toBeNull();
        expect(s.diff.querySelector('.pp-ai-composition-summary')).not.toBeNull();
        expect(s.diff.textContent).toContain('Full composition replacement: 1 ' + ARROW + ' 2 components');
        expect(s.diff.querySelector('.pp-ai-composition-raw summary').textContent)
            .toContain('2 components');
        // The ABSENCE of the value-diff spans is what proves the composition branch ran
        // rather than falling through to the plain diff-line renderer.
        expect(s.diff.querySelector('.pp-ai-step-diff-from')).toBeNull();
    });

    // ── the unreadable-composition marker on the approval gate (#836) ───────────
    //
    // These four drive the REAL per-step entry point, because #836 is a routing bug as
    // much as a wording one: the branch that draws the "Full composition replacement"
    // claim used to require `Array.isArray(change.from)`, so a corrupt before side could
    // not reach it even once the server started telling the truth.

    it('routes a corrupt before side to the composition renderer, not the generic line', () => {
        const s = newStep({ name: 'update_composition' });
        const failure = renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [{
                    path: 'composition',
                    from: {
                        unreadable: true,
                        classification: 'decode_error',
                        message: 'Page 7: composition data integrity error (decode_error).',
                    },
                    to: [{ component: 'hero' }, { component: 'cta' }],
                }],
            },
        });

        expect(failure).toBeNull();
        expect(s.diff.querySelector('.pp-ai-composition-summary')).not.toBeNull();
        // The lie #836 was filed about, named exactly so a regression cannot hide.
        expect(s.diff.textContent).not.toContain('replacement: 0 ' + ARROW);
        expect(s.diff.textContent).toContain('Full composition replacement: unreadable ' + ARROW + ' 2 components');
        // The diagnosis carries WEIGHT rather than sitting in the summary. On a card whose
        // job is stopping a destructive approval, a corruption notice styled identically to
        // "+ Added: hero" is the pre-#836 card with better words.
        const notice = s.diff.querySelector('.pp-ai-step-warning');
        expect(notice).not.toBeNull();
        expect(notice.textContent).toBe('Page 7: composition data integrity error (decode_error).');
        expect(notice.parentNode).toBe(s.diff);
        // ...and it is drawn ABOVE the summary: the alarm before the mechanics.
        expect(notice.nextElementSibling.className).toBe('pp-ai-composition-summary');
        // The disclosure still describes what WILL be written, and is never empty (#667).
        expect(s.diff.querySelector('.pp-ai-composition-raw summary').textContent).toContain('2 components');
    });

    it('tells the truth on a restore_composition card too, through the generic line', () => {
        // restore_composition is the OTHER verb ruling D-1 admits on a corrupt page, and
        // the composition-summary branch is scoped to update_composition — so this card is
        // drawn by ppChatRenderDiffLine(), which would otherwise JSON-stringify the marker
        // and truncate it at 80 characters, cutting the diagnosis in half.
        const s = newStep({ name: 'restore_composition' });
        // A synthetic placeholder, not a copy of the real sentence: the sentence has one
        // owner in PHP (#650/#652) and nothing here parses it.
        const message = 'SERVER SENTENCE PLACEHOLDER, long enough that a truncating '
            + 'renderer would have to cut it somewhere before this clause ends.';
        const failure = renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [{
                    path: 'composition',
                    from: { unreadable: true, classification: 'unexpected_shape', message: message },
                    to: [{ component: 'hero' }],
                }],
            },
        });

        expect(failure).toBeNull();
        // The label is SHORT and, deliberately, NOT in `.pp-ai-step-diff-from`: that class
        // is styled line-through, an idiom meaning "this value was replaced", which is the
        // wrong visual claim to make about a diagnosis.
        expect(s.diff.querySelector('.pp-ai-step-diff-from')).toBeNull();
        expect(s.diff.textContent).toContain('unreadable (unexpected_shape)');
        // The sentence lands uncut, and carries the same weight as on the summary card.
        const notice = s.diff.querySelector('.pp-ai-step-warning');
        expect(notice).not.toBeNull();
        expect(notice.textContent).toBe(message);
        expect(s.diff.textContent).not.toContain('...');
    });

    it('never renders a marker-shaped value on a path the server cannot send one on', () => {
        // THE SPOOFING PIN. `changes[].from` carries author- and model-controlled data on
        // the per-prop diff paths (_pp_diff_props / _pp_diff_style build it out of stored
        // prop values, which are free-form). Shape alone is therefore not proof: a stored
        // prop shaped like the marker satisfies every clause of the predicate. If the
        // renderer trusted it, a planted value would replace a real before-value with a
        // fake corruption notice carrying attacker-chosen text — this bug in a disguise.
        const s = newStep({ name: 'update_component' });
        renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [{
                    path: 'composition[0].props.title',
                    from: { unreadable: true, classification: 'PLANTED', message: 'PLANTED NOTICE' },
                    to: 'New title',
                }],
            },
        });

        // It renders as the ordinary value it is: JSON in the struck-through from-span.
        expect(s.diff.querySelector('.pp-ai-step-warning')).toBeNull();
        expect(s.diff.querySelector('.pp-ai-step-diff-from')).not.toBeNull();
        expect(s.diff.textContent).not.toContain('unreadable (PLANTED)');
        expect(s.diff.querySelector('.pp-ai-step-diff-from').textContent).toContain('PLANTED NOTICE');
    });

    it('leaves a real composition diff on exactly the path it took before', () => {
        // The regression pin for the routing change: `to` must still be a list, and a
        // `from` that is neither a list nor the marker still goes to the generic line.
        const s = newStep({ name: 'update_composition' });
        renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [{ path: 'composition', from: { to: '/new', code: 301 }, to: [{ component: 'hero' }] }],
            },
        });

        expect(s.diff.querySelector('.pp-ai-composition-summary')).toBeNull();
        expect(s.diff.querySelector('.pp-ai-step-diff-from')).not.toBeNull();
    });

    it('never prints the word undefined when a marker names no classification', () => {
        // The predicate requires a non-empty string classification precisely so this
        // payload takes the ordinary path instead of painting "unreadable (undefined)".
        const s = newStep({ name: 'restore_composition' });
        renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [{
                    path: 'composition',
                    from: { unreadable: true, message: 'SERVER SENTENCE PLACEHOLDER' },
                    to: [{ component: 'hero' }],
                }],
            },
        });

        expect(s.diff.textContent).not.toContain('undefined');
        expect(s.diff.querySelector('.pp-ai-step-warning')).toBeNull();
    });

    it('renders the marker as text, never as markup', () => {
        // The sentence carries a page id and a classification derived from stored data.
        const s = newStep({ name: 'restore_composition' });
        renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [{
                    path: 'composition',
                    from: {
                        unreadable: true,
                        classification: 'decode_error',
                        message: '<img src=x onerror=alert(1)>corrupted',
                    },
                    to: [{ component: 'hero' }],
                }],
            },
        });

        expect(s.diff.querySelector('img')).toBeNull();
        expect(s.diff.textContent).toContain('<img src=x onerror=alert(1)>corrupted');
    });

    it('renders a classified error and reports the failure', () => {
        const s = newStep();
        const failure = renderPreviewResult(s.el, s.diff, s.step, FIXABLE_RESULT);

        expect(failure).toEqual({ data: FIXABLE_RESULT.data });
        expect(stateClasses(s.el)).toEqual(['pp-ai-step-fixable']);
        expect(s.diff.textContent).toContain('That setting is not on this component.');
    });
});

describe('ppChatRenderPreviewResult — a render failure stays inside its own step', () => {
    it('does not rethrow when the error card cannot be drawn', () => {
        const s = newStep({ throwOnRender: true });

        expect(() => renderPreviewResult(s.el, s.diff, s.step, FIXABLE_RESULT)).not.toThrow();
        expect(s.el.classList.contains('pp-ai-step-executing')).toBe(false);
        expect(s.diff.textContent).toContain('Preview could not be displayed');
        expect(s.diff.textContent).toContain('injected render failure');
    });

    it('does not rethrow when the diff itself cannot be drawn', () => {
        const s = newStep({ throwOnRender: true });

        expect(() => renderPreviewResult(s.el, s.diff, s.step, OK_RESULT)).not.toThrow();
        expect(s.el.classList.contains('pp-ai-step-executing')).toBe(false);
        expect(s.diff.textContent).toContain('Preview could not be displayed');
    });

    it('reports the broken step as a failure, so Apply stays withheld', () => {
        const s = newStep({ throwOnRender: true });
        const failure = renderPreviewResult(s.el, s.diff, s.step, OK_RESULT);

        // renderProposal() appends Apply/Cancel only when no step reported a failure,
        // so a step whose preview could not be drawn must never read as a success.
        expect(failure).not.toBeNull();
        expect(failure.data).toContain('Preview could not be displayed');
    });

    it('leaves no preview changes behind on a step it painted as broken', () => {
        const s = newStep({ throwOnRender: true });
        renderPreviewResult(s.el, s.diff, s.step, OK_RESULT);

        // The changes are stashed only once the diff has fully drawn, so the field is
        // present exactly when a drawn preview justifies it. Nothing reads the field
        // today (see the helper's docblock) — this pins the write's hygiene, not a
        // contract with a reader.
        expect(s.step._previewChanges).toBeUndefined();
    });

    it('drops changes a previous run left on the same step object', () => {
        const s = newStep();
        renderPreviewResult(s.el, s.diff, s.step, OK_RESULT);
        expect(s.step._previewChanges).toEqual(OK_RESULT.data.changes);

        // Re-render the SAME step object, this time into a diff area that throws. Not
        // stashing on failure is only half the property; the other half is that a value
        // from an earlier successful render cannot outlive the card that justified it.
        s.diff.appendChild = function () {
            throw new TypeError('injected render failure');
        };
        renderPreviewResult(s.el, s.diff, s.step, OK_RESULT);

        expect(s.step._previewChanges).toBeUndefined();
    });

    it('drops the half-drawn card rather than leaving it under the error text', () => {
        const s = newStep();
        let drawn = 0;
        const realAppend = s.diff.appendChild.bind(s.diff);
        s.diff.appendChild = function (node) {
            if (drawn++ > 0) throw new TypeError('injected render failure');
            return realAppend(node);
        };

        renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [
                    { path: '--first', from: 'a', to: 'b' },
                    { path: '--second', from: 'c', to: 'd' },
                ],
            },
        });

        expect(s.diff.textContent).not.toContain('--first');
        expect(s.diff.textContent).toContain('Preview could not be displayed');
    });

    it('ends on exactly one state class, and it is the generic failed one', () => {
        const s = newStep({ throwOnRender: true });
        renderPreviewResult(s.el, s.diff, s.step, FIXABLE_RESULT);

        // This pins the deliberate render-before-classify order in the error arm. If the
        // class were painted first, this step would wear BOTH pp-ai-step-fixable (from
        // the payload the renderer choked on) and pp-ai-step-failed (from the catch), and
        // the CSS in assets/css/pp-ai-chat.css would resolve two backgrounds against each
        // other. It is also the honest state: a card that could not draw the fixable
        // affordance must not claim to be showing one.
        //
        // Naming the exact class matters for a second reason. `.pp-ai-step-diff` is
        // monospace, and only the enumerated error states reset it back to prose (#662);
        // a throw-caught step renders PROSE, so landing outside that set would show the
        // operator an English sentence in a value-diff typeface. Which classes are in the
        // set is pinned by tests/js/pp-ai-chat-error-card-typography.test.js, which
        // DERIVES it from both files rather than restating it — so this test asserts the
        // outcome and leaves the enumeration to the tripwire built for it.
        expect(stateClasses(s.el)).toEqual(['pp-ai-step-failed']);
    });
});

describe('one broken step among several', () => {
    /**
     * The fold renderProposal() runs over its results. Kept identical to the source, and
     * the source tripwire at the bottom of this file is what keeps it identical.
     */
    function foldResults(steps, results) {
        let firstFailure = null;
        let firstIndex = -1;
        results.forEach((result, i) => {
            const failure = renderPreviewResult(steps[i].el, steps[i].diff, steps[i].step, result);
            // Deliberately the source's own condition, truthiness and all — the whole
            // question this fold answers is whether that condition can still skip a step.
            if (failure && !firstFailure) {
                firstFailure = failure;
                firstIndex = i;
            }
        });
        return { firstFailure: firstFailure, firstIndex: firstIndex };
    }

    it('renders every other step and leaves nothing loading', () => {
        const steps = [newStep(), newStep({ throwOnRender: true }), newStep()];
        foldResults(steps, [OK_RESULT, OK_RESULT, OK_RESULT]);

        expect(steps[0].diff.textContent).toContain('--color-accent');
        expect(steps[2].diff.textContent).toContain('--color-accent');
        expect(steps[1].diff.textContent).toContain('Preview could not be displayed');

        steps.forEach((s) => {
            expect(s.el.classList.contains('pp-ai-step-executing')).toBe(false);
            expect(s.diff.textContent).not.toBe(PLACEHOLDER);
            expect(s.diff.textContent.length).toBeGreaterThan(0);
        });
    });

    it('tells the operator which step broke, and why', () => {
        const steps = [newStep(), newStep({ throwOnRender: true }), newStep()];
        foldResults(steps, [OK_RESULT, OK_RESULT, OK_RESULT]);

        // Not merely "something failed": the broken step names the reason in its own card.
        expect(steps[1].diff.textContent).toContain('injected render failure');
    });

    it('leaves a step that already rendered exactly as it was', () => {
        // The chain-level catch's fold. It repaints nothing: by the time it runs, every
        // step the loop reached already carries the right state, and `pp-ai-step-executing`
        // is precisely the set that never got one. Terminalizing indiscriminately would
        // wipe good previews and their stashed changes, which is the same class of loss
        // #663 is about, arriving from the guard instead of the gap.
        const done = newStep();
        const pending = newStep();
        renderPreviewResult(done.el, done.diff, done.step, OK_RESULT);

        const text = 'Preview could not be displayed: boom';
        [done, pending].forEach((s) => {
            if (s.el.classList.contains('pp-ai-step-executing')) {
                renderPreviewResult(s.el, s.diff, s.step, { success: false, data: text });
            }
        });

        expect(done.diff.textContent).toContain('--color-accent');
        expect(done.step._previewChanges).toHaveLength(1);
        expect(stateClasses(done.el)).toEqual([]);

        expect(pending.diff.textContent).toBe(text);
        expect(pending.el.classList.contains('pp-ai-step-executing')).toBe(false);
        expect(stateClasses(pending.el)).toEqual(['pp-ai-step-failed']);
    });

    it('narrates the FIRST failure even when its payload is empty', () => {
        const steps = [newStep(), newStep()];
        const folded = foldResults(steps, [NO_ENVELOPE_RESULT, FIXABLE_RESULT]);

        // The old code tracked `if (!firstFailedData) firstFailedData = result.data`,
        // which asks whether the PAYLOAD is truthy rather than whether a step failed. An
        // expired nonce makes check_ajax_referer() emit a bare -1, so step 1 has no data
        // at all: it was painted as failed and then skipped here, and the status bar
        // described step 2's error instead. The wrapper is truthy whatever it carries.
        //
        // The INDEX is the load-bearing assertion. Reading only the narrated text passes
        // vacuously when the fold picks step 2 — step 2's payload has no `data` key, so
        // `firstFailure.data` comes back undefined and produces the same generic sentence
        // by a different route. Which step the operator is being told about is the thing
        // the bug got wrong, so it is the thing to assert.
        expect(folded.firstIndex).toBe(0);
        expect(folded.firstFailure).not.toBeNull();
        expect(folded.firstFailure.data).toBeUndefined();
        expect(getStatusMessage(folded.firstFailure.data))
            .toBe('Some changes couldn\'t be previewed. See details above.');
        expect(getStatusMessage(folded.firstFailure.data))
            .not.toBe('That setting lives on a different component. See details above.');
    });
});

describe('ppChatPreviewRenderErrorText', () => {
    it('names the reason the card is empty', () => {
        expect(previewRenderErrorText(new TypeError('first.component is undefined')))
            .toBe('Preview could not be displayed: first.component is undefined');
    });

    it('still says something when the throw carried no message', () => {
        expect(previewRenderErrorText(undefined)).toContain('Unknown error');
        expect(previewRenderErrorText({})).toContain('Unknown error');
    });

    it('still names the reason when a bare string was thrown', () => {
        // `throw 'boom'` has no `.message`. Degrading to 'Unknown error' would lose the
        // only reason the card carries.
        expect(previewRenderErrorText('boom')).toBe('Preview could not be displayed: boom');
    });

    it('says Unknown error rather than [object Object] for a thrown object', () => {
        expect(previewRenderErrorText({ code: 7 })).toContain('Unknown error');
        expect(previewRenderErrorText({ code: 7 })).not.toContain('[object Object]');
    });

    it('survives a thrown value whose own message cannot be read', () => {
        // Every caller of this is inside a catch, so a throw HERE escapes that catch and
        // takes the remaining steps down with it. `throw` accepts any value, and a getter
        // is free to throw, so reading `message` is guarded rather than assumed.
        const hostile = {};
        Object.defineProperty(hostile, 'message', {
            get() { throw new Error('getter refused'); },
        });

        expect(() => previewRenderErrorText(hostile)).not.toThrow();
        expect(previewRenderErrorText(hostile)).toContain('Unknown error');
    });

    it('survives a message that cannot be coerced to a string', () => {
        const hostile = { message: { [Symbol.toPrimitive]() { throw new Error('nope'); } } };

        expect(() => previewRenderErrorText(hostile)).not.toThrow();
        expect(previewRenderErrorText(hostile)).toContain('Unknown error');
    });

    it('bounds a pathological message so it cannot crowd out the steps that rendered', () => {
        const text = previewRenderErrorText(new Error('x'.repeat(5000)));
        const reason = text.replace('Preview could not be displayed: ', '');

        expect(reason.length).toBe(200);
        expect(reason.endsWith('...')).toBe(true);
    });

    it('leaves a message that already fits completely alone', () => {
        const message = 'y'.repeat(200);
        expect(previewRenderErrorText(new Error(message)))
            .toBe('Preview could not be displayed: ' + message);
    });
});

/**
 * The chain itself, read as source.
 *
 * These are the assertions that would have caught #663 in the first place: the bug was
 * never in a function, it was in the SHAPE of the chain around one.
 */
describe('the batch-preview chain (source tripwire)', () => {
    /**
     * Whole-line `//` comments removed.
     *
     * These tripwires ask what the chain DOES, and the chain explains itself at length —
     * including by quoting the very idiom the third test below forbids. Reading the prose
     * as if it were code makes that test fail on its own explanation.
     */
    function codeOnly(source) {
        return source.split('\n').filter((line) => line.trim().indexOf('//') !== 0).join('\n');
    }

    /** The `Promise.all(previewPromises)` chain, from its head to the end of renderProposal(). */
    function previewChain() {
        const start = JS.indexOf('Promise.all(previewPromises)');
        expect(start).toBeGreaterThan(-1);
        const end = JS.indexOf('\n    function addStatusMessage(', start);
        expect(end).toBeGreaterThan(start);
        return codeOnly(JS.slice(start, end));
    }

    it('routes every result through the guarded per-step helper', () => {
        expect(previewChain()).toContain('ppChatRenderPreviewResult(stepElements[i], diffAreas[i], steps[i], result)');
    });

    it('carries a chain-level catch, like the execute chain beside it', () => {
        expect(previewChain()).toMatch(/\}\)\.catch\(function \(err\)/);
    });

    it('finishes the unrendered steps before it attempts the status message', () => {
        // addStatusMessage() is one of the things that can throw INTO this catch. Calling
        // it first would re-call the call that just failed, lose the rejection, and never
        // reach the loop, leaving steps mid-flight. That is #663's symptom reproduced by
        // the guard meant to prevent it, so the half that always survives runs first.
        const chain = previewChain();
        const catchBody = chain.slice(chain.indexOf('}).catch(function (err)'));

        const terminalized = catchBody.indexOf('ppChatRenderPreviewResult(el, diffAreas[i]');
        const announced = catchBody.indexOf('addStatusMessage(text, true)');

        expect(terminalized).toBeGreaterThan(-1);
        expect(announced).toBeGreaterThan(terminalized);
    });

    it('no longer decides the first failure by asking whether a payload is truthy', () => {
        expect(previewChain()).not.toContain('firstFailedData');
    });

    it('keeps the FIRST failure, and unwraps it before narrating', () => {
        // foldResults() above is a hand-copy of this loop, so a behaviour test cannot see
        // the source drifting away from it. These two expressions are the copy's contract:
        // `if (failure)` alone would be last-failure-wins (the status bar back to
        // describing the wrong step), and narrating the wrapper instead of its `.data`
        // would collapse every failure to the generic sentence.
        expect(previewChain()).toContain('if (failure && !firstFailure) firstFailure = failure;');
        expect(previewChain()).toContain('ppChatGetStatusMessage(firstFailure.data)');
    });

    it('terminalizes only the steps that never got a state', () => {
        // Same reason: the catch's fold is hand-copied into the behaviour test above.
        // Dropping the guard would repaint already-rendered steps as errors; dropping the
        // call would leave them executing.
        expect(previewChain()).toContain("if (el.classList.contains('pp-ai-step-executing')) {");
        expect(previewChain()).toContain(
            'ppChatRenderPreviewResult(el, diffAreas[i], steps[i], { success: false, data: text });',
        );
    });

    it('renders a failed step before it classifies it', () => {
        // The order is load-bearing (see ppChatRenderPreviewResult's docblock): classify
        // first and a throw leaves the step wearing a classified state the catch then has
        // to strip, which means enumerating the error classes in a second place.
        const start = JS.indexOf('function ppChatRenderPreviewResult(');
        expect(start).toBeGreaterThan(-1);
        const body = JS.slice(start, JS.indexOf('\n}', start));

        const rendered = body.indexOf('ppChatRenderPreviewError(diffArea, data)');
        const classified = body.indexOf('stepEl.classList.add(ppChatGetErrorStepClass(data))');

        expect(rendered).toBeGreaterThan(-1);
        expect(classified).toBeGreaterThan(rendered);
    });
});

/**
 * ONE ARROW PER DIFF ROW (#852).
 *
 * THE BUG. Two independent sources put an arrow between a diff row's before and after
 * values: ppChatRenderDiffLine() appended a ` → ` text node unconditionally, and
 * `.pp-ai-step-diff-from::after` (assets/css/pp-ai-chat.css) paints one via `content`.
 * Both fired, so every generic row rendered `--color-accent: #111 →  → #222` — confirmed
 * in headless Chromium against the real stylesheet, generic=2 arrows, marker=1.
 *
 * WHY NOTHING CAUGHT IT, and why these pins are shaped the way they are. jsdom does not
 * implement pseudo-element computed style: `getComputedStyle(el, '::after')` prints
 * "Not implemented: Window's getComputedStyle() method: with pseudo-elements" and answers
 * with the BASE element's style, so `.content` reads `"normal"` whether or not the rule
 * exists. Every pin this renderer had read `textContent`, which cannot see `content`
 * either. A duplicate arrow was therefore invisible to the entire unit suite by
 * construction — not an oversight, a blind spot — and no amount of textContent assertion
 * closes it.
 *
 * WHICH SIDE WAS REMOVED, AND WHY IT MATTERS TO THESE PINS. The CSS lost. A pseudo-element
 * arrow is not in the DOM, so it is absent from `innerText` and from anything copied out of
 * the card — the two values fused into `OldNew` — and it could not space itself either:
 * Chromium computes `content: ' \2192 '` as `" →"` (trailing space dropped) and
 * `display: inline-block` strips the rest, rendering a cramped `Old→New` directly beneath a
 * properly spaced marker row on the same card. So the renderer now owns the arrow for every
 * row, and these pins are shaped around that:
 *
 *   1. THE DOM HALF (here): EVERY row contributes exactly one arrow text node, whichever
 *      branch it took. Red-proven — against the pre-#852 source the generic rows carried a
 *      second arrow from the stylesheet that no textContent assertion could see.
 *   2. THE STYLESHEET HALF (below): that `.pp-ai-step-diff-from::after` declares NO arrow.
 *      jsdom cannot compute pseudo-element content, so it is asserted as a FACT ABOUT THE
 *      FILE — the only way this suite can see the source of the original duplicate. Put an
 *      arrow back in that rule and every row carrying the class renders two again, with
 *      pin 1 still green.
 *
 * The count that neither half can make — arrows actually painted in a browser — is made
 * in tests/e2e/ai-chat.spec.ts, in real Chromium, where a pseudo-element would resolve.
 */
describe('ppChatRenderDiffLine — exactly one arrow per row (#852)', () => {
    /**
     * Arrows ONE ROW contributes itself, as DOM text — the half jsdom can see.
     *
     * Direct text nodes only, and the precision is load-bearing twice over: a VALUE that
     * happens to contain an arrow lives inside `.pp-ai-step-diff-from` / `-to`, and the
     * marker row's diagnosis is a child `div`. Neither is a separator, and counting
     * `textContent` would score both as one.
     */
    function rowTextArrows(row) {
        return Array.prototype.slice.call(row.childNodes)
            .filter((n) => n.nodeType === 3)
            .reduce((n, t) => n + (t.textContent.match(/→/g) || []).length, 0);
    }

    /**
     * Every arrow a row renders.
     *
     * Since #852 that is exactly its own text nodes — no class paints one any more. The
     * class term is KEPT, scoring 0 by construction, because it is what makes this counter
     * fail loudly if `.pp-ai-step-diff-from::after` ever comes back: the stylesheet pin at
     * the bottom of this describe guards the rule, and this guards the count.
     */
    function rowArrows(row) {
        return rowTextArrows(row) + (cssPaintsAnArrow() && row.querySelector('.pp-ai-step-diff-from') ? 1 : 0);
    }

    /** Whether the stylesheet currently paints an arrow via the from-span's `::after`. */
    function cssPaintsAnArrow() {
        const rule = diffFromAfterRule();
        return rule !== null && /content\s*:\s*['"][^'"]*(?:\\2192|→)/.test(rule);
    }

    /** The body of `.pp-ai-step-diff-from::after`, or null when no such rule exists. */
    function diffFromAfterRule() {
        const css = fs.readFileSync(
            path.resolve(__dirname, '../../assets/css/pp-ai-chat.css'),
            'utf-8',
        ).replace(/\/\*[\s\S]*?\*\//g, '');

        const start = css.indexOf('.pp-ai-step-diff-from::after');
        return start === -1 ? null : css.slice(start, css.indexOf('}', start));
    }

    /** The diff LINES in a container, ignoring anything that is not one. */
    function diffRows(diff) {
        return Array.prototype.slice.call(diff.children)
            .filter((el) => el.querySelector('.pp-ai-step-diff-to'));
    }

    /** Arrows the whole container contributes as row-level text. */
    function textNodeArrows(diff) {
        return diffRows(diff).reduce((n, row) => n + rowTextArrows(row), 0);
    }

    it('draws exactly one arrow text node on a generic row', () => {
        const s = newStep({ name: 'update_design_token' });
        renderPreviewResult(s.el, s.diff, s.step, OK_RESULT);

        expect(s.diff.querySelector('.pp-ai-step-diff-from')).not.toBeNull();
        // One, from the renderer. Before #852 the stylesheet added a second that no
        // assertion in this file could see — that is the bug, named exactly.
        expect(textNodeArrows(s.diff)).toBe(1);
        // And it is real text, so it survives copy-paste and a screen reader — the whole
        // reason the CSS side lost rather than this one.
        expect(s.diff.textContent).toContain('#111 → #222');
    });

    it('draws the arrow text node on a marker row too, on the same terms', () => {
        const s = newStep({ name: 'restore_composition' });
        renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [{
                    path: 'composition',
                    from: {
                        unreadable: true,
                        classification: 'decode_error',
                        message: 'Page 7: composition data integrity error (decode_error).',
                    },
                    to: [{ component: 'hero' }],
                }],
            },
        });

        // #836's deliberate omission: the diagnosis is not struck through. Since #852 the
        // class has nothing to do with the arrow either way — this row and a generic row
        // get their separator from the same line of the renderer, which is the property
        // that makes them render identically.
        expect(s.diff.querySelector('.pp-ai-step-diff-from')).toBeNull();
        expect(textNodeArrows(s.diff)).toBe(1);
        expect(s.diff.textContent).toContain('unreadable (decode_error) → ');
    });

    it('gives both row kinds exactly one arrow, counting class and text node together', () => {
        // The invariant itself, stated once over both branches rather than implied by the
        // two cases above: arrow sources = (own text nodes) + (1 if the class is present).
        const generic = newStep({ name: 'update_design_token' });
        renderPreviewResult(generic.el, generic.diff, generic.step, OK_RESULT);

        const marker = newStep({ name: 'restore_composition' });
        renderPreviewResult(marker.el, marker.diff, marker.step, {
            success: true,
            data: {
                changes: [{
                    path: 'composition',
                    from: { unreadable: true, classification: 'unexpected_shape', message: 'x' },
                    to: [{ component: 'hero' }],
                }],
            },
        });

        expect(diffRows(generic.diff).map(rowArrows)).toEqual([1]);
        expect(diffRows(marker.diff).map(rowArrows)).toEqual([1]);
    });

    it('gives EVERY row one arrow in a multi-change diff, not just the first', () => {
        // PER ROW IS THE WHOLE CLAIM, and a single-change payload cannot test it. A real
        // preview is routinely multi-change — `_pp_diff_props()` emits one change per
        // changed prop — so a regression that gave only the FIRST row its separator would
        // leave every other row bare while a container-level count still read 1.
        const s = newStep({ name: 'update_design_token' });
        renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [
                    { path: '--color-accent', from: '#111', to: '#222' },
                    { path: '--color-bg', from: '#333', to: '#444' },
                    { path: '--color-fg', from: '#555', to: '#666' },
                ],
            },
        });

        const rows = diffRows(s.diff);
        expect(rows).toHaveLength(3);
        expect(rows.map(rowArrows)).toEqual([1, 1, 1]);
        // Every one of them from the renderer, so every one of them is real text.
        expect(rows.map(rowTextArrows)).toEqual([1, 1, 1]);
    });

    it('gives every row one arrow when a marker row and a generic row share a diff', () => {
        // The mixed case, which is where a per-branch fix is most likely to go wrong: the
        // two rows take OPPOSITE branches and must still agree on the count.
        const s = newStep({ name: 'restore_composition' });
        renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: {
                changes: [
                    {
                        path: 'composition',
                        from: { unreadable: true, classification: 'decode_error', message: 'x' },
                        to: [{ component: 'hero' }],
                    },
                    { path: 'props.title', from: 'Old', to: 'New' },
                ],
            },
        });

        const rows = diffRows(s.diff);
        expect(rows).toHaveLength(2);
        expect(rows.map(rowArrows)).toEqual([1, 1]);
        // Both from the SAME source now — that is the point of removing the CSS arrow.
        // Before #852 these two rows on one card rendered differently: the marker row
        // properly spaced, the generic row cramped and doubled.
        expect(rows.map(rowTextArrows)).toEqual([1, 1]);
    });

    it('does not count an arrow inside a VALUE as the row separator', () => {
        // A stored prop value is free-form and may contain the glyph. If the counter read
        // `textContent` it would score this row as two and the pins would fight the data
        // rather than the renderer.
        const s = newStep({ name: 'update_component' });
        renderPreviewResult(s.el, s.diff, s.step, {
            success: true,
            data: { changes: [{ path: 'props.title', from: 'a → b', to: 'c → d' }] },
        });

        const rows = diffRows(s.diff);
        // One separator, whatever the values contain. The arrows inside the two spans are
        // data; only the row's own text node is the separator.
        expect(rows.map(rowArrows)).toEqual([1]);
        expect(rows.map(rowTextArrows)).toEqual([1]);
        // The values really did survive intact — the counter is precise, not blind.
        expect(s.diff.textContent).toContain('a → b');
        expect(s.diff.textContent).toContain('c → d');
    });

    it('keeps the stylesheet out of the arrow business', () => {
        // THE STYLESHEET HALF, and the only way this suite can see the source of the
        // original duplicate: jsdom does not implement pseudo-element computed style, so
        // `.pp-ai-step-diff-from::after` is invisible to every DOM assertion above. It is
        // therefore asserted as a fact about the FILE.
        //
        // If this fails, someone put the arrow back in CSS and every row carrying the class
        // is rendering two again. The fix is to remove it from the stylesheet, not to
        // relax this test — and not to drop the text node instead, which is the trade #852
        // measured and rejected: a pseudo-element arrow is absent from `innerText` (the
        // values fuse into `OldNew` when copied) and cannot space itself, because Chromium
        // computes `content: ' \2192 '` as `" →"` and `display: inline-block` strips the
        // rest, rendering a cramped `Old→New`.
        expect(cssPaintsAnArrow()).toBe(false);

        // Stronger than "no arrow": the rule should not exist at all. A surviving empty
        // rule is where an arrow quietly comes back.
        expect(diffFromAfterRule()).toBeNull();
    });

    it('still strikes through the from-span, and never the separator', () => {
        // The one thing the class DOES own, unchanged by #852 — and the reason the arrow
        // is appended outside the span rather than inside it.
        const css = fs.readFileSync(
            path.resolve(__dirname, '../../assets/css/pp-ai-chat.css'),
            'utf-8',
        ).replace(/\/\*[\s\S]*?\*\//g, '');

        const start = css.indexOf('.pp-ai-step-diff-from {');
        expect(start).toBeGreaterThan(-1);
        expect(css.slice(start, css.indexOf('}', start))).toContain('line-through');

        const s = newStep({ name: 'update_design_token' });
        renderPreviewResult(s.el, s.diff, s.step, OK_RESULT);
        const row = diffRows(s.diff)[0];
        // The separator is a direct child of the row, not of the struck-through span.
        expect(row.querySelector('.pp-ai-step-diff-from').textContent).not.toContain('→');
        expect(rowTextArrows(row)).toBe(1);
    });
});
