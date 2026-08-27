/**
 * Tests for the batch-failure rendering's rollback report (#755).
 *
 * pp_ai_execute_batch()'s envelope documents `rollback_errors` as the channel that makes
 * `rolled_back: true` trustworthy — "a consumer must not treat rolled_back: true as clean
 * without checking it". The chat never checked it, so a rollback that could NOT fully
 * restore something still told the operator, flatly, that everything had been reverted.
 * Two producers feed the channel (lib/actions.php): the menu layer, and since #749 a page
 * whose composition restore was WITHHELD because its stored bytes went unreadable
 * mid-batch — the entry that exists precisely to say "this page did not roll back".
 *
 * What is pinned here, and why each pin is the one that would have failed before the fix:
 *
 *   1. The legacy "all changes ... reverted" sentence is byte-identical on a CLEAN
 *      rollback, and UNREACHABLE the moment the channel says anything else. That is the
 *      red-proof for the bug itself: the old code appended it on `rolled_back` alone.
 *   2. The clean sentence requires an explicitly EMPTY ARRAY. A channel that is absent,
 *      or present but not a list, is an UNKNOWN and may not be narrated as a clean
 *      revert — the failure mode is one key-preserving `array_filter` upstream away.
 *   3. The heading counts what the SERVER reported, so a member this file cannot draw
 *      still shows up in the total rather than vanishing.
 *   4. The report is computed ONCE and both surfaces read it, so the card cannot
 *      contradict the sentence.
 *   5. The #749 UP-FRONT refusal (steps: [], failed_at: null) stays a distinct rendering
 *      with nothing to revert.
 *   6. The wiring itself (source tripwire) — every helper test passes whether or not the
 *      failure branch calls them, so the branch is checked directly, including the
 *      append-before-announce ordering that keeps the alert on screen.
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
    rollbackErrorReport,
    rollbackSentence,
    appendRollbackErrors,
    batchWasRefusedUpFront,
    batchHitConflict,
    conflictMessage,
    ROLLBACK_ERRORS_MAX
} = require('../../assets/js/pp-ai-chat.js');

/** The sentence a clean rollback has always shown. Byte-exact on purpose. */
const CLEAN_SENTENCE = ' — all changes in this proposal have been reverted.';
const DIRTY_SENTENCE = ' — some changes could not be reverted.';

/** The #749 producer's real shape: a page whose composition restore was withheld. */
const WITHHELD = 'Page 42: composition data integrity error (decode_error). The stored '
    + '_pp_composition is not a valid composition list — treat as corrupted, not empty. Its '
    + 'composition was NOT rolled back: the stored bytes changed to an unreadable state '
    + 'during this batch, and restoring the snapshot over them would destroy the only '
    + 'recoverable copy. Every other field on this page was rolled back.';

/** The menu layer's producer shape — short, and one entry per item it could not recreate. */
const MENU = 'could not recreate menu item "Contact"';

/** A failed batch that rolled back, carrying whatever report it was given. */
function failedBatch(rollbackErrors) {
    return {
        ok: false,
        steps: [{ ok: true }, { ok: false, error: 'Component "cta" prop "heading" is required.' }],
        failed_at: 1,
        rolled_back: true,
        rollback_errors: rollbackErrors,
        versions: {}
    };
}

function sentenceFor(batch) {
    return rollbackSentence(rollbackErrorReport(batch));
}

function renderFor(card, batch) {
    return appendRollbackErrors(card, rollbackErrorReport(batch));
}

function newCard() {
    return document.createElement('div');
}

/** The section's own rows: the heading and the inline entries, never the disclosure body. */
function rowTexts(card) {
    const section = card.querySelector('[role="status"]');
    if (!section) return [];

    return Array.prototype.slice.call(section.children)
        .filter(function (el) { return el.tagName === 'DIV'; })
        .map(function (el) { return el.textContent; });
}

describe('rollbackErrorReport', () => {
    it('reads the reported entries in server order', () => {
        const report = rollbackErrorReport(failedBatch([WITHHELD, MENU]));

        expect(report.shown).toEqual([WITHHELD, MENU]);
        expect(report.total).toBe(2);
        expect(report.reported).toBe(2);
        expect(report.readable).toBe(true);
        expect(report.rolledBack).toBe(true);
    });

    it('reads a clean rollback as readable-and-empty, not as unknown', () => {
        const report = rollbackErrorReport(failedBatch([]));

        expect(report.readable).toBe(true);
        expect(report.reported).toBe(0);
        expect(report.shown).toEqual([]);
    });

    // A throw here would escape into executeProposal()'s chain catch, which renders
    // err.message into the transcript — losing the very report this function delivers.
    it('never throws on a malformed or missing channel, and marks it unreadable', () => {
        [undefined, null, {}, { rollback_errors: null }, { rollback_errors: 'not an array' },
            { rollback_errors: 7 }, { rollback_errors: { 0: MENU, length: 1 } }
        ].forEach(function (batch) {
            const report = rollbackErrorReport(batch);
            expect(report.readable).toBe(false);
            expect(report.reported).toBe(0);
            expect(report.shown).toEqual([]);
        });
    });

    it('drops members that are not renderable strings but still counts them as reported', () => {
        const report = rollbackErrorReport({
            rollback_errors: [MENU, null, '', undefined, 0, {}, [], false, WITHHELD]
        });

        expect(report.shown).toEqual([MENU, WITHHELD]);
        expect(report.total).toBe(2);
        expect(report.reported).toBe(9);
    });

    it('retains only the display budget, however long the report is', () => {
        const many = [];
        for (let i = 0; i < ROLLBACK_ERRORS_MAX + 25; i++) many.push('entry ' + i);
        const report = rollbackErrorReport(failedBatch(many));

        expect(report.shown.length).toBe(ROLLBACK_ERRORS_MAX);
        expect(report.total).toBe(ROLLBACK_ERRORS_MAX + 25);
        expect(report.reported).toBe(ROLLBACK_ERRORS_MAX + 25);
    });
});

describe('rollbackSentence', () => {
    it('claims a clean revert, byte for byte, when the rollback reported nothing', () => {
        expect(sentenceFor(failedBatch([]))).toBe(CLEAN_SENTENCE);
    });

    // THE RED-PROOF. Before #755 this branch appended CLEAN_SENTENCE on `rolled_back`
    // alone, so this assertion fails against the old code with the exact user-visible lie
    // the issue is about.
    it('never claims a clean revert when a page did not roll back', () => {
        const sentence = sentenceFor(failedBatch([WITHHELD]));

        expect(sentence).not.toContain(CLEAN_SENTENCE.trim());
        expect(sentence).not.toContain('all changes');
        expect(sentence).toBe(DIRTY_SENTENCE);
    });

    it('says nothing about reverting when no rollback was claimed or reported', () => {
        expect(sentenceFor({ ok: false, failed_at: 1, rolled_back: false, rollback_errors: [] })).toBe('');
        expect(rollbackSentence(null)).toBe('');
    });

    // The report outranks the flag. Today's executor cannot emit this pair (the only
    // return that fills rollback_errors also sets rolled_back: true), but spelling the
    // condition the other way round would re-open the bug for exactly this shape.
    it('reports the failures even when the flag says no rollback happened', () => {
        expect(sentenceFor({ rolled_back: false, rollback_errors: [MENU] })).toBe(DIRTY_SENTENCE);
    });

    // An unreadable channel is an UNKNOWN, and an unknown is not an all-clear. This is
    // one key-preserving edit upstream from live: $errors in _pp_restore_batch_snapshot()
    // is a JSON list only because it is built with $errors[] = and array_merge.
    it('refuses to call a rollback clean when the channel cannot be read', () => {
        [{ rolled_back: true, rollback_errors: 'oops' },
            { rolled_back: true, rollback_errors: { 2: MENU } },
            { rolled_back: true, rollback_errors: 7 },
            { rolled_back: true }
        ].forEach(function (batch) {
            expect(sentenceFor(batch)).toBe('');
        });
    });

    // Every member unrenderable is still a channel that REPORTED something. Keying the
    // sentence on what survives the filter would draw the clean claim over it.
    it('refuses to call a rollback clean when every reported member is unrenderable', () => {
        expect(sentenceFor({ rolled_back: true, rollback_errors: [{ message: 'x' }] })).toBe(DIRTY_SENTENCE);
        expect(sentenceFor({ rolled_back: true, rollback_errors: [''] })).toBe(DIRTY_SENTENCE);
    });
});

describe('appendRollbackErrors', () => {
    it('appends nothing when the rollback was clean', () => {
        const card = newCard();
        renderFor(card, failedBatch([]));

        expect(card.children.length).toBe(0);
    });

    it('appends nothing when the channel is missing, malformed, or undrawable', () => {
        const card = newCard();
        renderFor(card, {});
        renderFor(card, { rollback_errors: 'nope' });
        renderFor(card, { rolled_back: true, rollback_errors: [''] });
        appendRollbackErrors(card, null);

        expect(card.children.length).toBe(0);
    });

    it('does not throw when there is no card to draw into', () => {
        expect(() => renderFor(null, failedBatch([WITHHELD]))).not.toThrow();
    });

    it('names which page did not roll back, and why, in the card', () => {
        const card = newCard();
        renderFor(card, failedBatch([WITHHELD]));

        const section = card.firstChild;
        expect(section.getAttribute('role')).toBe('status');
        expect(section.getAttribute('aria-live')).toBe('polite');
        expect(section.firstChild.textContent).toBe('⚠ 1 change could not be reverted:');
        expect(section.firstChild.className).toBe('pp-ai-step-failed');
        // The whole reason, not a prefix of it: "which page" and "why" both survive.
        expect(section.children[1].textContent).toBe(WITHHELD);
        expect(section.children[1].className).toBe('pp-ai-step-failed');
    });

    it('renders entries in server order and as text, never as markup', () => {
        const card = newCard();
        renderFor(card, failedBatch([MENU, '<img src=x onerror=alert(1)>']));

        expect(rowTexts(card)).toEqual([
            '⚠ 2 changes could not be reverted:',
            MENU,
            '<img src=x onerror=alert(1)>'
        ]);
        expect(card.querySelector('img')).toBeNull();
    });

    it('keeps the step rows it is appended beside', () => {
        const card = newCard();
        const step = document.createElement('div');
        step.className = 'pp-ai-proposal-step';
        card.appendChild(step);

        renderFor(card, failedBatch([MENU]));

        expect(card.firstChild).toBe(step);
        expect(card.children.length).toBe(2);
    });

    // renderProposal() appends the action row last, so a plain appendChild would put the
    // disclosure below a pair of disabled buttons, away from the steps it explains.
    it('renders above the action row rather than below it', () => {
        const card = newCard();
        const actions = document.createElement('div');
        actions.className = 'pp-ai-proposal-actions';
        card.appendChild(actions);

        renderFor(card, failedBatch([MENU]));

        expect(card.children.length).toBe(2);
        expect(card.lastChild).toBe(actions);
        expect(card.firstChild.getAttribute('role')).toBe('status');
    });

    // Five inline plus a disclosure for the rest, inherited from ppChatAppendValidationItems:
    // every entry is unlocated (no `index`), so none of them is ever pooled with another.
    it('shows five entries inline and never opens a disclosure it does not need', () => {
        const card = newCard();
        renderFor(card, failedBatch(['a', 'b', 'c', 'd', 'e']));

        expect(card.querySelector('details')).toBeNull();
        expect(rowTexts(card).slice(1)).toEqual(['a', 'b', 'c', 'd', 'e']);
    });

    it('collapses the sixth and later entries into the disclosure', () => {
        const card = newCard();
        renderFor(card, failedBatch(['a', 'b', 'c', 'd', 'e', 'f']));

        const details = card.querySelector('details');
        expect(details.querySelector('summary').textContent).toBe('Show 1 more error');
        expect(details.textContent).toContain('f');
    });

    it('pluralises the disclosure summary', () => {
        const card = newCard();
        renderFor(card, failedBatch(['a', 'b', 'c', 'd', 'e', 'f', 'g']));

        expect(card.querySelector('summary').textContent).toBe('Show 2 more errors');
    });

    it('draws a single entry with no disclosure at all', () => {
        const card = newCard();
        renderFor(card, failedBatch([MENU]));

        expect(card.querySelector('details')).toBeNull();
        expect(rowTexts(card)).toEqual(['⚠ 1 change could not be reverted:', MENU]);
    });

    // rollback_errors is the one report channel with no server cap — the menu layer emits
    // one entry per item it could not recreate. The DOM is bounded; the COUNT is not.
    it('bounds the drawn report and still names how many were reported', () => {
        const many = [];
        for (let i = 0; i < ROLLBACK_ERRORS_MAX + 25; i++) many.push('entry ' + i);

        const card = newCard();
        renderFor(card, failedBatch(many));

        expect(card.firstChild.firstChild.textContent).toBe(
            '⚠ ' + (ROLLBACK_ERRORS_MAX + 25) + ' changes could not be reverted (showing the first '
            + ROLLBACK_ERRORS_MAX + '):'
        );
        // Heading + 5 inline rows; the rest of the budget sits inside the disclosure.
        expect(rowTexts(card).length).toBe(1 + 5);
        const details = card.querySelector('details');
        expect(details.querySelectorAll('div').length).toBe(ROLLBACK_ERRORS_MAX - 5);
        expect(details.textContent).toContain('entry ' + (ROLLBACK_ERRORS_MAX - 1));
        // Everything past the budget is dropped from the DOM, not silently implied.
        expect(card.textContent).not.toContain('entry ' + ROLLBACK_ERRORS_MAX);
    });

    // A member this file cannot draw must not vanish from the count.
    it('counts unrenderable members in the heading rather than hiding them', () => {
        const card = newCard();
        renderFor(card, failedBatch([MENU, { message: 'x' }, null]));

        expect(card.firstChild.firstChild.textContent)
            .toBe('⚠ 3 changes could not be reverted (showing the first 1):');
    });

    it('says nothing about a total when the whole report fits', () => {
        const card = newCard();
        renderFor(card, failedBatch([MENU, WITHHELD]));

        expect(card.firstChild.firstChild.textContent).toBe('⚠ 2 changes could not be reverted:');
    });
});

describe('the #749 up-front refusal stays a distinct rendering', () => {
    // Nothing ran, so nothing needed reverting: the refusal envelope carries an empty
    // rollback_errors by construction (lib/actions.php). It must keep its own branch and
    // must not grow a rollback report.
    const refused = {
        ok: false,
        steps: [],
        failed_at: null,
        rolled_back: false,
        rollback_errors: [],
        error: 'Page 42 has a stored composition that cannot be read.',
        error_code: 'decode_error'
    };

    it('is still detected by its own predicate', () => {
        expect(batchWasRefusedUpFront(refused)).toBe(true);
    });

    it('makes no claim about reverting and draws no rollback section', () => {
        const card = newCard();
        renderFor(card, refused);

        expect(sentenceFor(refused)).toBe('');
        expect(card.children.length).toBe(0);
    });

    it('is not confused with an executed failure that rolled back', () => {
        expect(batchWasRefusedUpFront(failedBatch([WITHHELD]))).toBe(false);
    });
});

/**
 * The conflict envelope is real, carries entries, and now reaches a surface (#797).
 *
 * This described a BOUNDARY while #755 owned one of the three failure exits: the report
 * layer answered correctly for a conflicting batch, and only the routing was missing —
 * `ppChatBatchHitConflict()` returned earlier, into showConflictState(), which rebuilt the
 * card and said "Nothing was applied." The executor does not special-case a conflicting
 * step (it reaches the same failure return and runs the same _pp_restore_batch_snapshot(),
 * lib/actions.php), so the shape was always producible and always discarded.
 *
 * The routing exists now, so the last assertion flipped rather than the test being deleted.
 * What is kept from the boundary version is the premise it established: this payload is one
 * the predicate claims and the report layer reads. The rendering itself is pinned end to
 * end, through the real card, in pp-ai-chat-conflict-rollback.test.js.
 */
describe('a conflicting batch that did not roll back cleanly (#797)', () => {
    const conflictBatch = {
        ok: false,
        steps: [{ ok: false, error_code: 'composition_conflict', error: 'Version mismatch.' }],
        failed_at: 0,
        rolled_back: true,
        rollback_errors: [MENU],
        versions: {}
    };

    it('is a shape the conflict predicate claims', () => {
        expect(batchHitConflict(conflictBatch)).toBe(true);
    });

    it('reports honestly at the report layer', () => {
        expect(rollbackErrorReport(conflictBatch).shown).toEqual([MENU]);
        expect(sentenceFor(conflictBatch)).toBe(DIRTY_SENTENCE);
    });

    // THE FLIPPED ASSERTION, and only it. The conflict exit no longer claims a clean revert
    // over a channel that reported one.
    //
    // Deliberately does NOT also call appendRollbackErrors() here to "show the entries
    // render": that helper is the #755 report layer this diff does not touch, so such an
    // assertion passes byte-identically against pre-fix source and restates what the test
    // above already checked — a decoration that would make this look like a routing pin
    // without being one. The routing, and the card it produces, are pinned end to end in
    // pp-ai-chat-conflict-rollback.test.js.
    it('no longer lets the conflict exit call this rollback clean', () => {
        expect(conflictMessage(conflictBatch, rollbackErrorReport(conflictBatch)))
            .not.toMatch(/nothing was applied/i);
    });
});

/**
 * SOURCE TRIPWIRE — the wiring, not the helpers (#755).
 *
 * Every assertion above passes whether or not executeProposal()'s failure branch actually
 * calls these functions, because they are exported and tested directly. The branch itself
 * lives inside the IIFE behind a fetch, so it is checked by reading it.
 */
describe('batch-failure branch wiring', () => {
    const JS = fs.readFileSync(path.resolve(__dirname, '../../assets/js/pp-ai-chat.js'), 'utf-8');

    // Comments are stripped first: the helper's own docblock quotes the sentence to
    // explain when it may be claimed, and a docblock is not a second place it can be
    // emitted from. Only executable occurrences count.
    const CODE = JS.replace(/\/\*[\s\S]*?\*\//g, '');

    /** The failure branch: from the report it computes to the status message it emits. */
    function failureBranch() {
        const start = CODE.indexOf('var rollback = ppChatRollbackErrorReport(batch);');
        expect(start).toBeGreaterThan(-1);
        const end = CODE.indexOf('addStatusMessage(message, true);', start);
        expect(end).toBeGreaterThan(start);
        return CODE.slice(start, end + 'addStatusMessage(message, true);'.length);
    }

    it('owns the clean-revert sentence in one place only', () => {
        const occurrences = CODE.split(CLEAN_SENTENCE.trim()).length - 1;

        expect(occurrences).toBe(1);
        expect(CODE.indexOf(CLEAN_SENTENCE.trim()))
            .toBeGreaterThan(CODE.indexOf('function ppChatRollbackSentence('));
    });

    it('asks the sentence helper instead of testing rolled_back inline', () => {
        const branch = failureBranch();

        expect(branch).toContain('ppChatRollbackSentence(rollback)');
        expect(branch).not.toContain('batch.rolled_back');
    });

    it('computes the report once and hands the same one to both surfaces', () => {
        const branch = failureBranch();

        expect(branch.split('ppChatRollbackErrorReport(').length - 1).toBe(1);
        expect(branch).toContain('ppChatAppendRollbackErrors(card, rollback)');
    });

    // addStatusMessage() pins the transcript to its own bottom, and the card is an EARLIER
    // sibling in that scroller. Growing the card afterwards pushes the alert back off the
    // fold, so the ordering is contractual: append, then announce.
    //
    // Deliberately NOT sliced at addStatusMessage: a window that ENDS at the announce call
    // drops the append out of itself the moment the two are swapped, leaving indexOf() at
    // -1 and the comparison vacuously true. The window runs to the end of the handler so
    // both calls stay inside it under either ordering.
    it('grows the card before announcing, so the alert stays on screen', () => {
        const start = CODE.indexOf('var rollback = ppChatRollbackErrorReport(batch);');
        const end = CODE.indexOf('.catch(function (err) {', start);
        expect(start).toBeGreaterThan(-1);
        expect(end).toBeGreaterThan(start);
        const handler = CODE.slice(start, end);

        const appendAt = handler.indexOf('ppChatAppendRollbackErrors(card, rollback)');
        const announceAt = handler.indexOf('addStatusMessage(message, true);');

        expect(appendAt).toBeGreaterThan(-1);
        expect(announceAt).toBeGreaterThan(-1);
        expect(appendAt).toBeLessThan(announceAt);
    });
});
