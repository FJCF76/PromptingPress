/**
 * Tests for the rollback report's two kinds (#855).
 *
 * THE BUG. `rollback_errors` carried two meanings on one opaque string channel — a restore
 * the rollback DECLINED to make on purpose, and one it OWED and did not land — and this card
 * drew both in the failure colour. On the #756 producer that is a false alarm: the batch
 * found the page unreadable, so there is no pre-batch composition to return it to and
 * leaving the stored bytes alone IS the honest restore. The operator was steered toward
 * recovery over a page nothing had failed against, which is the mirror image of the false
 * reassurance #755 and #797 removed out of this same channel.
 *
 * RULING T2 is envelope-ADDITIVE: `rollback_errors` is untouched and a new index-aligned
 * `rollback_error_kinds` travels beside it. The wording half stays pooled on #664, so what
 * changes here is TREATMENT, never text.
 *
 * What is pinned, and why each pin is the one that would fail if this went wrong:
 *
 *   1. A withheld entry draws the warning treatment and a failed entry the failure one,
 *      including when both are in the same report.
 *   2. THE KINDS PAIR AT THE SERVER'S INDEX. `shown` drops unrenderable members and stops
 *      at the budget, so a kinds list walked afterwards would be SHIFTED by every drop —
 *      silently, and in the direction that relabels a failure as a withhold.
 *   3. Every degradation lands on today's rendering, never on a softer one: an unknown
 *      kind, an absent list, a list of the wrong length, a non-array.
 *   4. OLD-ENVELOPE BYTE IDENTITY, asserted against HTML captured from the PRE-CHANGE
 *      renderer rather than from what this change intends. That is the difference between
 *      proving the branch and proving the bytes.
 *   5. The counts are untouched. `reported`, `total` and `shown` are what
 *      ppChatRollbackSentence, ppChatConflictOutcome and the #856 survival branch key on,
 *      and a withheld entry still costs the clean claim.
 *   6. The heading treatment needs the WHOLE report to be withholds — not just the drawn
 *      rows — so a failure hiding past the display budget still paints it red.
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

const {
    rollbackErrorReport,
    rollbackSentence,
    appendRollbackErrors,
    rollbackRowClass,
    conflictOutcome,
    ROLLBACK_KIND_WITHHELD,
    ROLLBACK_ERRORS_MAX
} = require('../../assets/js/pp-ai-chat.js');

const FAILED = 'failed';
const WARNING_CLASS = 'pp-ai-step-warning';
const FAILURE_CLASS = 'pp-ai-step-failed';

/** The #756 producer's real sentence — the one whose alarm was false. */
const WITHHELD_MESSAGE = 'Page 42: composition data integrity error (decode_error). Its '
    + 'composition was NOT rolled back, and that is the safe outcome: the stored bytes could '
    + 'not be read when this batch snapshotted them.';

/** The #857 producer's real sentence — a restore that was owed and refused. */
const FAILED_MESSAGE = 'Page 42: its title was NOT rolled back: the restoring write was '
    + 'refused, so the value this batch wrote is still stored.';

function failedBatch(rollbackErrors, rollbackErrorKinds) {
    const batch = {
        ok: false,
        steps: [{ ok: true }, { ok: false, error: 'boom' }],
        failed_at: 1,
        rolled_back: true,
        rollback_errors: rollbackErrors,
        versions: {}
    };
    if (arguments.length > 1) batch.rollback_error_kinds = rollbackErrorKinds;
    return batch;
}

/** A card shaped like the one renderProposal() builds, so the insert target exists. */
function renderCard(batch) {
    const card = document.createElement('div');
    card.className = 'pp-ai-proposal-card';
    const actions = document.createElement('div');
    actions.className = 'pp-ai-proposal-actions';
    card.appendChild(actions);
    appendRollbackErrors(card, rollbackErrorReport(batch));
    return card;
}

function rowsOf(card) {
    return Array.from(card.querySelectorAll('[role="status"] > div, details > div'));
}

describe('a withheld entry stops reading as a failure (#855)', () => {
    test('draws the warning treatment on the row and on the heading', () => {
        const card = renderCard(failedBatch([WITHHELD_MESSAGE], [ROLLBACK_KIND_WITHHELD]));
        const rows = rowsOf(card);

        expect(rows).toHaveLength(2); // heading + one entry
        expect(rows[0].className).toBe(WARNING_CLASS);
        expect(rows[1].className).toBe(WARNING_CLASS);
        expect(rows[1].textContent).toBe(WITHHELD_MESSAGE);
    });

    test('draws the failure treatment for a failed entry, exactly as before', () => {
        const card = renderCard(failedBatch([FAILED_MESSAGE], [FAILED]));
        const rows = rowsOf(card);

        expect(rows[0].className).toBe(FAILURE_CLASS);
        expect(rows[1].className).toBe(FAILURE_CLASS);
    });

    /**
     * THE SHAPE THE ISSUE IS ABOUT. One report, both meanings, drawn apart — and the
     * heading stays red because the report is not all withholds.
     */
    test('draws both kinds in one report, in the server\'s order', () => {
        const card = renderCard(failedBatch(
            [WITHHELD_MESSAGE, FAILED_MESSAGE],
            [ROLLBACK_KIND_WITHHELD, FAILED]
        ));
        const rows = rowsOf(card);

        expect(rows[0].className).toBe(FAILURE_CLASS); // heading: not all withholds
        expect(rows[1].className).toBe(WARNING_CLASS);
        expect(rows[1].textContent).toBe(WITHHELD_MESSAGE);
        expect(rows[2].className).toBe(FAILURE_CLASS);
        expect(rows[2].textContent).toBe(FAILED_MESSAGE);
    });

    /**
     * THE WORDING IS UNTOUCHED, AND THAT IS THE RULING. T2 keeps the wording half pooled on
     * #664, so a report of nothing but withholds still HEADS "could not be reverted" — it
     * counts, because the rollback was not clean — and what changed is the treatment beside
     * the server's own "that is the safe outcome" sentence.
     */
    test('says the same words over an all-withheld report', () => {
        const card = renderCard(failedBatch([WITHHELD_MESSAGE], [ROLLBACK_KIND_WITHHELD]));

        expect(rowsOf(card)[0].textContent).toBe('⚠ 1 change could not be reverted:');
    });
});

describe('the kinds pair at the server\'s own index (#855)', () => {
    /**
     * THE SHIFT THIS PREVENTS. `shown` skips members this file cannot draw, so a kinds list
     * built by walking `shown` afterwards would hand entry 2's kind to entry 1. It fails
     * quietly and it fails in the wrong direction: a failed revert relabelled as a withhold.
     */
    test('skips the kind of a member it cannot draw, rather than shifting the rest', () => {
        const report = rollbackErrorReport(failedBatch(
            ['drawable', { not: 'a string' }, WITHHELD_MESSAGE],
            [FAILED, FAILED, ROLLBACK_KIND_WITHHELD]
        ));

        expect(report.shown).toEqual(['drawable', WITHHELD_MESSAGE]);
        expect(report.kinds).toEqual([FAILED, ROLLBACK_KIND_WITHHELD]);
        expect(report.reported).toBe(3);
        expect(report.total).toBe(2);
    });

    test('renders that pairing, so the withheld row is the withheld message', () => {
        const card = renderCard(failedBatch(
            ['drawable', { not: 'a string' }, WITHHELD_MESSAGE],
            [FAILED, FAILED, ROLLBACK_KIND_WITHHELD]
        ));
        const rows = rowsOf(card);

        expect(rows[1].textContent).toBe('drawable');
        expect(rows[1].className).toBe(FAILURE_CLASS);
        expect(rows[2].textContent).toBe(WITHHELD_MESSAGE);
        expect(rows[2].className).toBe(WARNING_CLASS);
    });
});

describe('every unknown degrades to today\'s rendering (#855)', () => {
    test.each([
        ['a kind this file does not know', ['some-future-kind']],
        ['a kinds list that is shorter than the channel', []],
        ['a kinds list that is longer than the channel', [ROLLBACK_KIND_WITHHELD, FAILED]],
        ['a kinds list that is not an array', 'withheld'],
        // AN ARRAY-LIKE OBJECT OF THE RIGHT LENGTH is the case that red-proves Array.isArray
        // on its own. A bare string is already rejected by the length check beside it, so it
        // would pass with the isArray half deleted — and this is the realistic shape: a
        // key-preserving edit upstream makes wp_json_encode emit a JSON OBJECT, which is the
        // hazard lib/actions.php pins from its own side.
        ['a kinds object, not a list', { length: 1, 0: 'withheld' }],
        ['a kinds list of nulls', [null]]
    ])('%s draws the failure treatment', (_label, kinds) => {
        const card = renderCard(failedBatch([WITHHELD_MESSAGE], kinds));
        const rows = rowsOf(card);

        expect(rows[0].className).toBe(FAILURE_CLASS);
        expect(rows[1].className).toBe(FAILURE_CLASS);
    });

    /**
     * A WRONG-LENGTH LIST IS DISCARDED WHOLE, not honoured for the entries it happens to
     * cover. This is the case that decides the posture: with a two-entry channel and a
     * one-entry kinds list, honouring the prefix would draw the FAILED message amber —
     * a real failed revert rendered as a harmless protection, off a list whose own length
     * says the server's alignment contract is broken. Nothing is trusted from it.
     */
    test('a one-short kinds list does not colour the entries it covers', () => {
        const card = renderCard(failedBatch(
            [FAILED_MESSAGE, WITHHELD_MESSAGE],
            [ROLLBACK_KIND_WITHHELD]
        ));
        const rows = rowsOf(card);

        expect(rows[0].className).toBe(FAILURE_CLASS);
        expect(rows[1].textContent).toBe(FAILED_MESSAGE);
        expect(rows[1].className).toBe(FAILURE_CLASS);
        expect(rows[2].textContent).toBe(WITHHELD_MESSAGE);
        expect(rows[2].className).toBe(FAILURE_CLASS);
    });

    test('and the report reports no withholds from a wrong-length list', () => {
        const report = rollbackErrorReport(failedBatch(
            [FAILED_MESSAGE, WITHHELD_MESSAGE],
            [ROLLBACK_KIND_WITHHELD]
        ));

        expect(report.withheld).toBe(0);
        expect(report.kinds).toEqual(['', '']);
        expect(report.reported).toBe(2);
        expect(report.total).toBe(2);
    });

    /**
     * THE DEFAULT SITS ON THE FAILURE SIDE, and that asymmetry is deliberate. The sibling
     * helper ppChatFindingClass() answers the opposite way for restore findings, where an
     * unrecognized item is advisory. Here an unrecognized kind is an older envelope or a
     * newer server, and T2 says it renders as it does today.
     */
    test('the row-class helper defaults to the failure class, unlike ppChatFindingClass', () => {
        expect(rollbackRowClass({ severity: 'warning' })).toBe(WARNING_CLASS);
        expect(rollbackRowClass({ severity: 'error' })).toBe(FAILURE_CLASS);
        expect(rollbackRowClass({})).toBe(FAILURE_CLASS);
        expect(rollbackRowClass(null)).toBe(FAILURE_CLASS);
    });
});

describe('an old envelope renders byte for byte as it always did (#855)', () => {
    /**
     * CAPTURED FROM THE PRE-CHANGE RENDERER, not written from what this change intends.
     * The two literals below are the exact `innerHTML` main produced for these inputs
     * before the kinds existed; anything that changes them changes what a server without
     * kinds shows an operator.
     */
    const PRE_CHANGE_ONE = '<div role="status" aria-live="polite">'
        + '<div class="pp-ai-step-failed">⚠ 1 change could not be reverted:</div>'
        + '<div class="pp-ai-step-failed">Page 42: its composition was NOT rolled back.</div>'
        + '</div><div class="pp-ai-proposal-actions"></div>';

    const PRE_CHANGE_SEVEN = '<div role="status" aria-live="polite">'
        + '<div class="pp-ai-step-failed">⚠ 7 changes could not be reverted:</div>'
        + '<div class="pp-ai-step-failed">entry 1</div>'
        + '<div class="pp-ai-step-failed">entry 2</div>'
        + '<div class="pp-ai-step-failed">entry 3</div>'
        + '<div class="pp-ai-step-failed">entry 4</div>'
        + '<div class="pp-ai-step-failed">entry 5</div>'
        + '<details class="pp-ai-preview-error-detail"><summary>Show 2 more errors</summary>'
        + '<div class="pp-ai-step-failed">entry 6</div>'
        + '<div class="pp-ai-step-failed">entry 7</div>'
        + '</details></div><div class="pp-ai-proposal-actions"></div>';

    test('one entry, no kinds key at all', () => {
        const card = renderCard(failedBatch(['Page 42: its composition was NOT rolled back.']));

        expect(card.innerHTML).toBe(PRE_CHANGE_ONE);
    });

    /**
     * THE DISCLOSURE NOUN IS PART OF THE BYTES. Passing a per-item class function makes
     * ppChatAppendValidationItems derive the summary's noun from the items' severities, so
     * the adapter has to spell 'error' for the failed and unknown cases — leave the field
     * off and this reads "Show 2 more warnings" over a list of failures.
     */
    test('seven entries, the disclosure, and its noun', () => {
        const card = renderCard(failedBatch(
            Array.from({ length: 7 }, (_, i) => 'entry ' + (i + 1))
        ));

        expect(card.innerHTML).toBe(PRE_CHANGE_SEVEN);
    });

    test('an all-withheld overflow gets the helper\'s own warning noun, not a new one', () => {
        const card = renderCard(failedBatch(
            Array.from({ length: 7 }, (_, i) => 'entry ' + (i + 1)),
            Array.from({ length: 7 }, () => ROLLBACK_KIND_WITHHELD)
        ));

        expect(card.querySelector('summary').textContent).toBe('Show 2 more warnings');
    });

    test('a mixed overflow gets the helper\'s own mixed noun', () => {
        const kinds = Array.from({ length: 7 }, (_, i) => (i === 6 ? FAILED : ROLLBACK_KIND_WITHHELD));
        const card = renderCard(failedBatch(
            Array.from({ length: 7 }, (_, i) => 'entry ' + (i + 1)),
            kinds
        ));

        expect(card.querySelector('summary').textContent).toBe('Show 2 more issues');
    });
});

describe('the counts and the claims are untouched (#855)', () => {
    /**
     * THE HALF T2 DOES NOT RELAX. A withheld entry still costs the clean claim: the
     * rollback was not clean, bytes were left in a state the operator must know about. Every
     * consumer that keys on the channel's SIZE must answer exactly as it did.
     */
    test('an all-withheld report still says some changes could not be reverted', () => {
        const report = rollbackErrorReport(failedBatch([WITHHELD_MESSAGE], [ROLLBACK_KIND_WITHHELD]));

        expect(report.reported).toBe(1);
        expect(report.total).toBe(1);
        expect(rollbackSentence(report)).toBe(' — some changes could not be reverted.');
    });

    test('and the conflict card still withholds "Nothing was applied."', () => {
        const payload = failedBatch([WITHHELD_MESSAGE], [ROLLBACK_KIND_WITHHELD]);
        const outcome = conflictOutcome(payload, rollbackErrorReport(payload));

        expect(outcome).not.toContain('Nothing was applied.');
    });

    test('a clean rollback is still clean, and carries an empty kinds list', () => {
        const report = rollbackErrorReport(failedBatch([], []));

        expect(report.reported).toBe(0);
        expect(report.kinds).toEqual([]);
        expect(report.withheld).toBe(0);
        expect(rollbackSentence(report)).toBe(' — all changes in this proposal have been reverted.');
    });

    test('an unreadable channel is still an unknown, whatever the kinds say', () => {
        const report = rollbackErrorReport(failedBatch('not a list', [ROLLBACK_KIND_WITHHELD]));

        expect(report.readable).toBe(false);
        expect(report.kinds).toEqual([]);
        expect(rollbackSentence(report)).toBe('');
    });
});

describe('the heading treatment needs the whole report (#855)', () => {
    /**
     * NOT "no failures AMONG THE DRAWN ROWS". The budget stops `shown` at
     * PP_CHAT_ROLLBACK_ERRORS_MAX, so a report whose first hundred entries are withholds and
     * whose hundred-and-first is a real failure would paint an amber heading over a genuine
     * failed revert if the test were taken over the rows.
     */
    test('a failure hidden past the display budget still paints the heading red', () => {
        const size = ROLLBACK_ERRORS_MAX + 1;
        const messages = Array.from({ length: size }, (_, i) => 'entry ' + i);
        const kinds = Array.from({ length: size }, (_, i) =>
            (i === size - 1 ? FAILED : ROLLBACK_KIND_WITHHELD));

        const report = rollbackErrorReport(failedBatch(messages, kinds));
        expect(report.shown).toHaveLength(ROLLBACK_ERRORS_MAX);
        expect(report.withheld).toBe(ROLLBACK_ERRORS_MAX);
        expect(report.reported).toBe(size);

        const card = renderCard(failedBatch(messages, kinds));
        expect(rowsOf(card)[0].className).toBe(FAILURE_CLASS);
    });

    /**
     * AND AN UNRENDERABLE MEMBER COSTS THE AMBER TOO. It counts in `reported` and can never
     * count in `withheld`, so the strict equality fails closed on it — which is right: a
     * member this file cannot read is not a withhold it verified.
     */
    test('an unrenderable member keeps the heading red', () => {
        const card = renderCard(failedBatch(
            [WITHHELD_MESSAGE, { not: 'a string' }],
            [ROLLBACK_KIND_WITHHELD, ROLLBACK_KIND_WITHHELD]
        ));

        expect(rowsOf(card)[0].className).toBe(FAILURE_CLASS);
        expect(rowsOf(card)[1].className).toBe(WARNING_CLASS);
    });

    test('every entry a known withhold, and the heading goes amber', () => {
        const card = renderCard(failedBatch(
            [WITHHELD_MESSAGE, WITHHELD_MESSAGE],
            [ROLLBACK_KIND_WITHHELD, ROLLBACK_KIND_WITHHELD]
        ));

        expect(rowsOf(card)[0].className).toBe(WARNING_CLASS);
    });
});

describe('the wiring (source tripwire) (#855)', () => {
    const fs = require('fs');
    const path = require('path');
    const source = fs.readFileSync(
        path.join(__dirname, '../../assets/js/pp-ai-chat.js'),
        'utf8'
    );

    /**
     * THE SERVER'S KEY IS READ EXACTLY ONCE, in the report builder, and nowhere else. Every
     * helper test above passes whether or not the renderer is wired to the envelope; this is
     * what says the key reaches the report at all, and that no second reader grew beside it
     * to decide something on the entries — which is the rule #856's own tripwire holds the
     * survival branch to, for the same reason.
     */
    test('rollback_error_kinds is read only by the report builder', () => {
        // COUNTED AS A PROPERTY ACCESS, NOT AS A WORD. Several docblocks in this file NAME
        // the key, deliberately, and executeProposal's inline envelope shape lists it as a
        // trailing comment on a code line — a tripwire that counted every occurrence of the
        // word would fail the day somebody documents the field once more, which is the
        // opposite of what a tripwire is for. What must stay unique is the READ.
        // BLOCK COMMENTS STRIPPED, because one of the docblocks DRAWS the access
        // (`batch.rollback_error_kinds ──▶ …` in ppChatAppendRollbackErrors's flow diagram)
        // and a diagram is documentation, not a reader. Trailing `//` comments are left
        // alone: none of them spells the property form.
        const ACCESS = /(?:\.\s*|\[\s*['"])rollback_error_kinds/g;
        const code = source.replace(/\/\*[\s\S]*?\*\//g, '');
        const reads = (code.match(ACCESS) || []).length;
        expect(reads).toBeGreaterThan(0);

        const builder = code.slice(
            code.indexOf('function ppChatRollbackErrorReport('),
            code.indexOf('function ppChatRollbackSentence(')
        );
        expect(builder).toContain('batch.rollback_error_kinds');
        expect((builder.match(ACCESS) || []).length).toBe(reads);
    });

    /**
     * THE COUNTS ARE COMPUTED WITHOUT THE KINDS. `reported` and `total` are what every
     * claim-making consumer keys on, so a kind must never reach them — that is the whole of
     * "additive" on this side of the wire.
     */
    test('neither reported nor total is conditioned on a kind', () => {
        const builder = source.slice(
            source.indexOf('function ppChatRollbackErrorReport('),
            source.indexOf('function ppChatRollbackSentence(')
        );
        const body = builder.replace(/\/\/[^\n]*/g, '').replace(/\/\*[\s\S]*?\*\//g, '');

        expect(body).toContain('report.reported = raw.length;');
        expect(body).toMatch(/if \(!ppChatIsNonEmptyString\(raw\[i\]\)\) continue;\s*report\.total\+\+;/);
    });
});
