/**
 * tests/js/pp-ai-chat-undo-failure.test.js — a refused undo says WHY (#822).
 *
 * THE BUG. The chat's "Undo these changes" link runs `restore_composition` through
 * `pp_ai_execute`. Its response handling had three outcomes, and the last one was two
 * words:
 *
 *     if (resp.success)                        -> 'Changes undone ✓' + findings
 *     else if (ppChatIsCompositionConflict())  -> 'Page changed — undo not applied'
 *     else                                     -> 'Undo failed'          <-- the server's
 *     .catch()                                 -> 'Undo failed'              message, gone
 *
 * Every non-conflict refusal collapsed to those two words, and the actionable half of the
 * message went with them. The refusal that makes this worth fixing rather than noting is
 * #818's `history_entry_not_restorable`: after a chat-driven repair of a corrupt page the
 * newest ring slot holds the page's ORIGINAL undecodable bytes, so the link's `steps_back: 1`
 * selects it and the restore is refused — and that refusal is the ONLY place a chat-only
 * operator is told the bytes survived the repair, naming
 * `wp pp operate composition-history --post_id=N` as the route to them. Since #829 a second
 * class reaches the same branch: `history_target_shifted`, a concurrent write moving what the
 * selector names.
 *
 * THE WIRE SHAPE THESE TESTS ENCODE, because it is the whole reason there is no per-code
 * switch on the client. `_pp_ai_execute_error_payload()` (lib/ai-chat.php) returns a
 * STRUCTURED array for `composition_conflict` only, and `$result['error']` as a bare STRING
 * for every other refusal. So both classes above arrive as plain strings carrying no
 * `error_code`, and rendering the message IS the code-aware behavior. The PHP side pins that
 * boundary (CompositionHistoryRawPreservationTest, CompositionRestoreSelectorLockTest); this
 * file pins what the client does with what arrives.
 *
 * Coverage:
 *   the string arm (both refusal classes) and the { error } arm (missing_expected_version)
 *   the shapes that must draw NOTHING rather than an empty red bar
 *   the bound, and that it cannot reach the real messages
 *   textContent only — a payload never becomes markup
 *   the success-path renderer is untouched by any of it
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
    undoFailureText,
    appendUndoFailure,
    appendUndoFindings,
    UNDO_ERROR_MAX,
    UNDO_FAILED_LABEL,
    UNDO_FAILURE_CLASS
} = require('../../assets/js/pp-ai-chat.js');

/**
 * The real #818 refusal, as `_pp_reject_unreplayable_history_entry()` (lib/actions.php)
 * composes it for post 250 / index 1 / 1284 preserved bytes.
 *
 * A COPY, AND ONLY PARTLY GUARDED — said plainly so it is not mistaken for more. This side
 * of the wire has no access to PHP, so these are hand-copied sentences. What the PHP tests
 * hold to the live message is the CLAUSE SET the tests below assert on
 * (CompositionHistoryRawPreservationTest for #818, CompositionRestoreSelectorLockTest for
 * #829); the rest of each sentence can be reworded in PHP with nothing failing. So treat the
 * fixtures as realistic specimens whose load-bearing phrases are pinned elsewhere, not as a
 * mirror of the live text. Nothing here goes false-green either way: every assertion below
 * is about what the CLIENT does with a string, and the one test that depends on the real
 * message's SIZE is duplicated against the live messages on the PHP side.
 */
const PRESERVED_BYTES_REFUSAL =
    'History entry 1 (steps_back 1) holds stored bytes that did not decode to a composition '
    + '(1284 bytes as this ring holds them), so it cannot be replayed as one. The bytes were '
    + 'preserved rather than discarded: read them with '
    + '`wp pp operate composition-history --post_id=250`, which states which byte views are '
    + 'exact. To roll the page back to a real composition, select an earlier entry.';

/** The real #829 refusal, from `_pp_restore_target_still_addressed()`. */
const TARGET_SHIFTED_REFUSAL =
    'The composition history of page 250 changed while this restore was being prepared, so '
    + 'the entry you selected is no longer the entry your selector names. Nothing was written. '
    + 'Against the ring as it now stands, that selector now names a different entry (index 8 '
    + 'of 10). Another writer (a CLI action, the dashboard editor, or the AI chat) recorded a '
    + 'state on this page in the meantime. Re-read the ring with '
    + '`wp pp operate composition-history --post_id=250` and select again: steps_back counts '
    + 'backwards from the newest entry, so it names a different snapshot after every '
    + 'concurrent write, while a history_index taken from the current listing stays stable '
    + 'until the ring evicts. [history_target_shifted]';

function newCard() {
    return document.createElement('div');
}

describe('ppChatUndoFailureText', () => {
    it('returns the #818 preserved-bytes refusal whole, command and all', () => {
        const text = undoFailureText(PRESERVED_BYTES_REFUSAL);

        expect(text).toBe(PRESERVED_BYTES_REFUSAL);
        // The half the operator acts on is at the END of the message, so a bound that cut
        // anywhere would take exactly this.
        expect(text).toContain('wp pp operate composition-history --post_id=250');
        expect(text).toContain('preserved rather than discarded');
    });

    it('returns the #829 target-shifted refusal whole', () => {
        const text = undoFailureText(TARGET_SHIFTED_REFUSAL);

        expect(text).toBe(TARGET_SHIFTED_REFUSAL);
        expect(text).toContain('Nothing was written.');
        expect(text).toContain('[history_target_shifted]');
    });

    it('reads .error out of a structured payload (missing_expected_version, #404)', () => {
        // The one non-conflict payload that arrives as an OBJECT: the undo link omits
        // expected_version when it holds no baseline, and the CAS mandate refuses before
        // the write. ppChatIsCompositionConflict() does not match it, so it lands here.
        const payload = {
            error: 'This change needs the page\'s current version as a baseline, which is '
                 + 'missing. Re-read the page and try again.',
            error_code: 'missing_expected_version'
        };

        expect(undoFailureText(payload)).toBe(payload.error);
    });

    it('says nothing when the server sent nothing usable', () => {
        expect(undoFailureText(undefined)).toBeNull();
        expect(undoFailureText(null)).toBeNull();
        expect(undoFailureText('')).toBeNull();
        expect(undoFailureText(0)).toBeNull();
        expect(undoFailureText(42)).toBeNull();
        expect(undoFailureText(true)).toBeNull();
        expect(undoFailureText({})).toBeNull();
        expect(undoFailureText({ error_code: 'no_history' })).toBeNull();
        expect(undoFailureText({ error: '' })).toBeNull();
        expect(undoFailureText({ error: 404 })).toBeNull();
        // A list is not a keyed payload, and `typeof [] === 'object'` is exactly the trap
        // ppChatIsPlainObject() exists for.
        expect(undoFailureText([PRESERVED_BYTES_REFUSAL])).toBeNull();
    });

    it('passes whitespace through, because that contract is not this issue\'s to move', () => {
        // ppChatIsNonEmptyString() deliberately accepts whitespace, and which side owns that
        // question is #775's, not #822's — so this pins the behavior rather than arguing with
        // it. Unreachable in practice: every message on this path is a server literal.
        expect(undoFailureText('   ')).toBe('   ');
    });

    it('bounds an oversized message and marks the cut', () => {
        const text = undoFailureText('x'.repeat(UNDO_ERROR_MAX + 500));

        expect(text.length).toBe(UNDO_ERROR_MAX);
        expect(text.endsWith('...')).toBe(true);
    });

    it('leaves a message exactly at the bound untouched', () => {
        const exact = 'y'.repeat(UNDO_ERROR_MAX);

        expect(undoFailureText(exact)).toBe(exact);
    });

    it('keeps enough headroom that no real refusal can reach the bound', () => {
        // The tripwire for the bound itself: if someone lowers PP_CHAT_UNDO_ERROR_MAX to a
        // number that would truncate the messages this feature exists to show, this fails
        // here rather than in production. The PHP side asserts the LIVE messages fit; this
        // asserts the constant is not the thing that shrank.
        expect(PRESERVED_BYTES_REFUSAL.length).toBeLessThan(UNDO_ERROR_MAX);
        expect(TARGET_SHIFTED_REFUSAL.length).toBeLessThan(UNDO_ERROR_MAX);
    });
});

describe('ppChatAppendUndoFailure', () => {
    it('draws the refusal as one announced row on the card, led by the outcome', () => {
        const card = newCard();
        const row = appendUndoFailure(card, PRESERVED_BYTES_REFUSAL);

        expect(card.children.length).toBe(1);
        expect(row).toBe(card.children[0]);
        // The server's sentence never says whether the undo happened, and the link's label is
        // not in a live region, so the row has to carry the outcome itself.
        expect(row.textContent).toBe(UNDO_FAILED_LABEL + ': ' + PRESERVED_BYTES_REFUSAL);
        expect(row.classList.contains('pp-ai-step-failed')).toBe(true);
        expect(row.classList.contains(UNDO_FAILURE_CLASS)).toBe(true);
        // Announced, because the operator's attention is on the link they just clicked.
        expect(row.getAttribute('role')).toBe('status');
        expect(row.getAttribute('aria-live')).toBe('polite');
    });

    it('draws the #829 refusal the same way', () => {
        const card = newCard();
        appendUndoFailure(card, TARGET_SHIFTED_REFUSAL);

        expect(card.children.length).toBe(1);
        expect(card.textContent).toContain('wp pp operate composition-history --post_id=250');
    });

    it('rewrites its own row instead of stacking a second one', () => {
        // `pointer-events: none` on the link is NOT a single-shot latch — it removes the
        // anchor as a mouse target but leaves it focusable, and Enter still dispatches click
        // (measured in Chromium). So a keyboard operator can re-activate the link, and
        // without this the card would grow a second ~370-character row and re-announce it.
        const card = newCard();
        const first = appendUndoFailure(card, PRESERVED_BYTES_REFUSAL);
        const second = appendUndoFailure(card, TARGET_SHIFTED_REFUSAL);

        expect(card.children.length).toBe(1);
        expect(second).toBe(first);
        // The LATEST refusal wins, and rewriting a region already in the DOM is a mutation,
        // so it announces again — which is what a second activation is waiting to hear.
        expect(first.textContent).toBe(UNDO_FAILED_LABEL + ': ' + TARGET_SHIFTED_REFUSAL);
    });

    it('does not mistake a rollback report row for its own', () => {
        // ppChatAppendRollbackErrors draws its heading and every entry with `pp-ai-step-failed`
        // on this same card, which is why the renderer looks for its own class instead.
        const card = newCard();
        const foreign = document.createElement('div');
        foreign.className = 'pp-ai-step-failed';
        foreign.textContent = 'not mine';
        card.appendChild(foreign);

        appendUndoFailure(card, PRESERVED_BYTES_REFUSAL);

        expect(card.children.length).toBe(2);
        expect(foreign.textContent).toBe('not mine');
    });

    it('draws nothing at all when there is nothing to say', () => {
        const card = newCard();

        expect(appendUndoFailure(card, undefined)).toBeNull();
        expect(appendUndoFailure(card, '')).toBeNull();
        expect(appendUndoFailure(card, {})).toBeNull();
        // No empty red bar under a link that already says "Undo failed".
        expect(card.children.length).toBe(0);
        expect(card.innerHTML).toBe('');
    });

    it('tolerates a missing card rather than throwing inside the response handler', () => {
        expect(appendUndoFailure(null, PRESERVED_BYTES_REFUSAL)).toBeNull();
    });

    it('never turns a payload into markup', () => {
        const card = newCard();
        const hostile = '<img src=x onerror="alert(1)"> and <b>bold</b>';

        appendUndoFailure(card, hostile);

        expect(card.children.length).toBe(1);
        expect(card.querySelector('img')).toBeNull();
        expect(card.querySelector('b')).toBeNull();
        expect(card.children[0].textContent).toBe(UNDO_FAILED_LABEL + ': ' + hostile);
    });

    it('renders no band locator, so a message cannot forge one', () => {
        // Deliberately NOT routed through ppChatValidationItemRow(), whose `[type] index N: `
        // prefix is the surface #793 is filed against. A refusal owns no band, so no locator
        // is drawn — the only prefix is the outcome, which this file owns.
        const card = newCard();
        appendUndoFailure(card, '[unknown_prop] index 3: not a finding');

        expect(card.children[0].textContent)
            .toBe(UNDO_FAILED_LABEL + ': [unknown_prop] index 3: not a finding');
        expect(card.querySelector('details')).toBeNull();
    });

    it('leaves the success-path findings renderer alone', () => {
        // Both append a status region to the same card; they must not become one renderer
        // by accident. The success heading still says "Restored" (#233) and is a warning,
        // not a failure.
        const card = newCard();
        appendUndoFindings(card, [{ type: 'unknown_prop', severity: 'error', message: 'x', index: 0 }]);

        expect(card.children.length).toBe(1);
        expect(card.textContent).toContain('Restored');
        expect(card.querySelector('.pp-ai-step-warning')).not.toBeNull();
    });
});
