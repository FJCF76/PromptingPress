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
 * HOW THE THROWS ARE INJECTED, and why not the obvious way. The reachable throw sites
 * today are inside ppChatRenderPreviewError() — `hints[keys[0]].component` on a null,
 * `alternatives.join()` on a string — and sending one of those payloads would be the
 * natural trigger. It is deliberately not used: issue #667 is open against exactly those
 * two sites, and on the day it lands those payloads stop throwing. Every test written
 * that way would keep passing while testing nothing at all. Instead the target step gets
 * a real DOM diff area whose `appendChild` throws, which is independent of any payload
 * and stays a genuine throw no matter what #667 decides.
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
