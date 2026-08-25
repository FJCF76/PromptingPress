/**
 * Error-card typography contract (#662) and wrap contract (#666).
 *
 * These are SOURCE tripwires, not render assertions — the rendered proof lives in
 * tests/e2e/ai-chat.spec.ts, which loads the real stylesheet in a real browser and
 * reads computed values. What a source scan can do that the browser pin cannot is
 * catch ENUMERATION DRIFT: a fourth error class added to ppChatGetErrorStepClass()
 * would render monospace prose on a state no E2E fixture happens to exercise, and
 * every existing test would stay green.
 *
 *   assets/js/pp-ai-chat.js          assets/css/pp-ai-chat.css
 *   ppChatGetErrorStepClass()        .pp-ai-step-failed     .pp-ai-step-diff,
 *     -> 'pp-ai-step-failed'    ===  .pp-ai-step-impossible .pp-ai-step-diff,
 *     -> 'pp-ai-step-impossible'     .pp-ai-step-fixable    .pp-ai-step-diff
 *     -> 'pp-ai-step-fixable'          { font-family: inherit }
 *
 * The two sides are compared as SETS, so reordering or reformatting either file is
 * free; only a class that exists on one side and not the other fails.
 */

const fs = require('fs');
const path = require('path');

const JS = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/pp-ai-chat.js'),
    'utf-8',
);
const CSS = fs.readFileSync(
    path.resolve(__dirname, '../../assets/css/pp-ai-chat.css'),
    'utf-8',
);

function stripCssComments(css) {
    return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** The body of ppChatGetErrorStepClass(), from its `function` line to the closing brace. */
function errorStepClassBody() {
    const start = JS.indexOf('function ppChatGetErrorStepClass(');
    expect(start).toBeGreaterThan(-1);
    const end = JS.indexOf('\n}', start);
    expect(end).toBeGreaterThan(start);
    return JS.slice(start, end);
}

/**
 * Every `pp-ai-step-*` class the function can return.
 *
 * All three quote styles, deliberately. Matching only single quotes would create a
 * silent hole rather than a loud one: a FOURTH arm added with a different quote style
 * would go uncounted while the three existing ones still matched, so the drift guard
 * below would pass on an enumeration it never actually saw. (A wholesale restyling of
 * the file fails loudly instead — the set comes back empty and the first test says so.)
 */
function returnedErrorClasses() {
    const body = errorStepClassBody();
    const found = body.match(/return\s+['"`](pp-ai-step-[a-z-]+)['"`]/g) || [];
    return new Set(found.map((m) => m.replace(/^return\s+['"`]|['"`]$/g, '')));
}

/**
 * `.pp-ai-step-diff` as a WHOLE class, never as a prefix.
 *
 * `.pp-ai-step-diff-from` and `.pp-ai-step-diff-to` are real sibling classes in this
 * stylesheet, so an unanchored match would count a reset targeting one of them — or a
 * typo'd `.pp-ai-step-diff-XX`, which targets nothing at all — as covering a state.
 */
const STATE_DIFF_PAIR_SOURCE = /\.(pp-ai-step-[a-z-]+)\s+\.pp-ai-step-diff(?![\w-])/;
/** Fresh /g instance per call — a shared one carries `lastIndex` between `.test()`s. */
const stateDiffPairs = (s) => s.match(new RegExp(STATE_DIFF_PAIR_SOURCE.source, 'g')) || [];
const hasStateDiffPair = (s) => STATE_DIFF_PAIR_SOURCE.test(s);

/** Every `[selector, declarations]` pair in the stylesheet, comments stripped. */
function cssRules() {
    const css = stripCssComments(CSS);
    const rules = [];
    const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
    let m;
    while ((m = ruleRe.exec(css)) !== null) {
        rules.push([m[1].trim(), m[2]]);
    }
    return rules;
}

const DECLARES_FONT_INHERIT = /font-family\s*:\s*inherit\s*;/;

/**
 * Every `<state>` in a `.<state> .pp-ai-step-diff` selector on a rule that declares
 * `font-family: inherit` — the container reset #662 installed.
 */
function fontResetStates() {
    const states = new Set();
    for (const [selector, decls] of cssRules()) {
        if (!DECLARES_FONT_INHERIT.test(decls)) continue;
        const pairs = stateDiffPairs(selector);
        pairs.forEach((p) => states.add(p.match(/\.(pp-ai-step-[a-z-]+)/)[1]));
    }
    return states;
}

describe('#662 error-card prose renders in the admin UI font on every error state', function () {
    test('ppChatGetErrorStepClass returns exactly the three known error classes', function () {
        // Guards the test itself: if the function grows a fourth arm, the set below
        // changes and the reader is sent to look at the CSS.
        expect([...returnedErrorClasses()].sort()).toEqual([
            'pp-ai-step-failed',
            'pp-ai-step-fixable',
            'pp-ai-step-impossible',
        ]);
    });

    test('every class it can return also resets the diff container font', function () {
        const returned = returnedErrorClasses();
        const reset = fontResetStates();
        const missing = [...returned].filter((c) => !reset.has(c));
        expect(missing).toEqual([]);
    });

    test('the font reset covers no state the function cannot return', function () {
        // The reverse direction: a stale state left in the selector list would quietly
        // turn a real value diff proportional on some other step state.
        const returned = returnedErrorClasses();
        const reset = fontResetStates();
        const extra = [...reset].filter((c) => !returned.has(c));
        expect(extra).toEqual([]);
    });

    test('the red + 13px treatment stays scoped to the failed state', function () {
        // #662 is about the TYPEFACE only. The grey (impossible) and amber (fixable)
        // cards must not inherit the failed card's red, which is what widening the
        // whole original rule instead of splitting it would have done.
        //
        // Collected first, then asserted on. Asserting inside the scan loop is how a
        // pin like this goes vacuous: delete or rename the rule and the loop simply
        // never runs. #627 in this repo is titled "...pinned by a vacuously-passing
        // JS test" — same shape, same file.
        const redDiffRules = cssRules().filter(
            ([selector, decls]) => /#d63638/.test(decls) && hasStateDiffPair(selector),
        );
        expect(redDiffRules).toHaveLength(1);
        const [selector, decls] = redDiffRules[0];
        expect(selector).not.toMatch(/pp-ai-step-(impossible|fixable)/);
        // And the size the same rule carries, which the test name promises.
        expect(decls).toMatch(/font-size\s*:\s*13px\s*;/);
    });

    test('no rule resets the diff font WITHOUT scoping it to an error state', function () {
        // The one mutation the set comparison above cannot see. A bare
        // `.pp-ai-step-diff { font-family: inherit; }` contributes no state pairs, so
        // `missing` and `extra` both stay empty and every other tripwire here stays
        // green — while every SUCCESSFUL step's value diff quietly goes proportional.
        // The diff element's monospace is the whole reason #662 needed a fix; a reset
        // that forgets to say WHICH states it applies to gives away the other tenant.
        const unscoped = cssRules()
            .filter(([selector, decls]) => DECLARES_FONT_INHERIT.test(decls))
            .filter(([selector]) =>
                selector
                    .split(',')
                    .some((s) => /(^|\s)\.pp-ai-step-diff(?![\w-])/.test(s.trim()) && !hasStateDiffPair(s)),
            );
        expect(unscoped.map(([s]) => s)).toEqual([]);
    });

    test('the disclosure body keeps its own monospace', function () {
        // The prose reset above is inherited; this declaration is what stops raw_error
        // from being reset along with the sentence around it.
        const css = stripCssComments(CSS);
        const rule = css.match(/\.pp-ai-preview-error-detail\s*>\s*div\s*\{([^}]*)\}/);
        expect(rule).not.toBeNull();
        expect(rule[1]).toMatch(/font-family\s*:\s*monospace\s*;/);
    });
});

describe('#666 long machine text wraps instead of widening the chat pane', function () {
    const css = stripCssComments(CSS);

    test('the disclosure body declares a wrap alongside its pre-wrap', function () {
        const rule = css.match(/\.pp-ai-preview-error-detail\s*>\s*div\s*\{([^}]*)\}/);
        expect(rule).not.toBeNull();
        expect(rule[1]).toMatch(/white-space\s*:\s*pre-wrap\s*;/);
        expect(rule[1]).toMatch(/overflow-wrap\s*:\s*break-word\s*;/);
    });

    test('the message element declares a wrap', function () {
        // Post-#661 the server samples slot names into user_message too, so the
        // sentence carries an unbreakable token of its own.
        const rule = css.match(/\.pp-ai-preview-error-message\s*\{([^}]*)\}/);
        expect(rule).not.toBeNull();
        expect(rule[1]).toMatch(/overflow-wrap\s*:\s*break-word\s*;/);
    });

    test('neither wrap is break-all, which would chop the short slot names too', function () {
        const messageRule = css.match(/\.pp-ai-preview-error-message\s*\{([^}]*)\}/)[1];
        const detailRule = css.match(/\.pp-ai-preview-error-detail\s*>\s*div\s*\{([^}]*)\}/)[1];
        expect(messageRule).not.toMatch(/break-all/);
        expect(detailRule).not.toMatch(/break-all/);
    });
});
