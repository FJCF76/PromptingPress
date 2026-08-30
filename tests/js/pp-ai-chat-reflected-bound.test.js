/**
 * tests/js/pp-ai-chat-reflected-bound.test.js — the chat's ceiling on server error text (#793).
 *
 * WHAT #793 IS AFTER v1.17.8, because the issue body predates the answer. The body asks for a
 * JS twin of `_pp_cli_printable()` — a `/[\p{Cc}\p{Cf}]+/gu` strip applied to `item.message` —
 * and offers, as its own alternative, that the fix might belong "in the server's message
 * builder so every consumer inherits it". #647/#649 took that alternative: v1.17.8 routes every
 * server-side sink through `_pp_clean_reflected_text()` and records the reason a JS twin was
 * NOT written ("a second strip in JavaScript would be a second definition of 'clean' that could
 * only drift, and the client cannot repair invalid UTF-8"). So the strip half is closed, and
 * closed in a way that FORBIDS the thing the body asked for.
 *
 * What that pass deliberately left is written into the code it wrote: lib/ai-chat.php's
 * `_pp_ai_execute_error_payload()` says "What the client still owns is the LENGTH it renders
 * under (#793)", and tests/ChatUndoBoundTrait.php says "#793 owns the client half". That is
 * this file's subject: a LENGTH ceiling, no sanitizer.
 *
 * WHY A CEILING IS NOT REDUNDANT WITH THE SERVER. Three of the five sites have no server
 * length bound at all — `_pp_action_error()` stores `'error' => $error` verbatim,
 * `_pp_bounded_findings()` is a count bound by its own docblock, and
 * `pp_ai_parse_error_response()` returns a third party's body. Closing those server-side is
 * #864, deferred. Here the ceiling is the only one those strings meet.
 *
 * Coverage:
 *   the helper: passthrough, the exact boundary, the cut, the marker, the surrogate guard
 *   the helper does NOT sanitize — control and format characters survive (server's job)
 *   non-strings pass through, so an off-contract payload renders as it does today
 *   the real row builder (`appendValidationItems`) bounds a hostile finding
 *   BOTH halves of that row are bounded, not just `message` — `type` is server-supplied too
 *   textContent only: an oversized payload never becomes markup
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
    streamNonce: 'stream-nonce'
};
global.window.ppAiChat = dom.window.ppAiChat;

const {
    boundReflectedText,
    appendValidationItems,
    REFLECTED_ERROR_MAX,
    UNDO_ERROR_MAX
} = require('../../assets/js/pp-ai-chat.js');

// ─── the helper ─────────────────────────────────────────────────────────────

describe('ppChatBoundReflectedText', function () {

    it('exposes a positive integer budget', function () {
        expect(typeof REFLECTED_ERROR_MAX).toBe('number');
        expect(Number.isInteger(REFLECTED_ERROR_MAX)).toBe(true);
        expect(REFLECTED_ERROR_MAX).toBeGreaterThan(0);
    });

    it('leaves an ordinary validator message exactly as it arrived', function () {
        const msg = 'Component "section" has no style slot "--hero-padding".';

        expect(boundReflectedText(msg)).toBe(msg);
    });

    it('leaves a message of exactly the budget untouched', function () {
        // The contract is "cut only when it EXCEEDS the budget", so equality still renders
        // whole. Off-by-one here would mark a message that fits.
        const exact = 'x'.repeat(REFLECTED_ERROR_MAX);

        expect(boundReflectedText(exact)).toBe(exact);
        expect(boundReflectedText(exact).length).toBe(REFLECTED_ERROR_MAX);
    });

    it('cuts one unit past the budget, to exactly the budget, and marks it', function () {
        const over = 'x'.repeat(REFLECTED_ERROR_MAX + 1);
        const out  = boundReflectedText(over);

        expect(out.length).toBe(REFLECTED_ERROR_MAX);
        expect(out.endsWith('...')).toBe(true);
        expect(out.startsWith('x'.repeat(REFLECTED_ERROR_MAX - 3))).toBe(true);
    });

    it('bounds a wildly oversized message to the budget, never beyond it', function () {
        // The shape #864's deferred sinks can actually deliver: `Unknown component: "%s"`
        // with a stored component name that never passed write validation.
        const hostile = 'Unknown component: "' + 'A'.repeat(500000) + '".';
        const out     = boundReflectedText(hostile);

        expect(out.length).toBe(REFLECTED_ERROR_MAX);
        expect(out.endsWith('...')).toBe(true);
    });

    it('never SPLITS a well-formed surrogate pair at the cut', function () {
        // A cut by code unit can land between the halves of an astral character. Left
        // unguarded the row would render U+FFFD immediately before the ellipsis — a bound
        // producing malformed text on exactly the input it exists to contain.
        //
        // '\u{1F600}' is two code units, so a string made only of it has an EVEN length and
        // the cut at (MAX - 3) — an odd offset — always lands mid-pair.
        const astral = '\u{1F600}'.repeat(REFLECTED_ERROR_MAX);
        const out    = boundReflectedText(astral);

        expect(out.endsWith('...')).toBe(true);
        expect(out.length).toBeLessThanOrEqual(REFLECTED_ERROR_MAX);

        const body = out.slice(0, -3);
        const tail = body.charCodeAt(body.length - 1);
        expect(tail >= 0xD800 && tail <= 0xDBFF).toBe(false);
        expect(body).not.toContain('�');
        // The guard drops at most one dangling unit, so the result is one short here.
        expect(out.length).toBe(REFLECTED_ERROR_MAX - 1);
    });

    it('does not clean lone surrogates the cut did not orphan, and still holds the budget', function () {
        // The guarantee is "never splits a well-formed pair", not "never ends on a lone
        // surrogate", and the difference is a scope line rather than a gap. Input that
        // ALREADY carries lone surrogates can still end on one after a cut lands just past
        // it — that character was malformed before this function saw it. Walking backwards
        // until the tail is well-formed would delete input the cut did not orphan, which is
        // sanitizing, which #647 put on the server and ruled out here.
        //
        // What must hold regardless is the budget.
        const malformed = 'a'.repeat(REFLECTED_ERROR_MAX - 5) + '\uD800\uD800' + 'b'.repeat(50);
        const out = boundReflectedText(malformed);

        expect(out.length).toBeLessThanOrEqual(REFLECTED_ERROR_MAX);
        expect(out.length).toBeGreaterThanOrEqual(REFLECTED_ERROR_MAX - 1);
        expect(out.endsWith('...')).toBe(true);
    });

    it('does not cut in the middle of a surrogate pair that ends cleanly', function () {
        // The complementary case: one ASCII character shifts the parity so the cut lands
        // BETWEEN pairs. Nothing should be dropped then.
        const astral = 'a' + '\u{1F600}'.repeat(REFLECTED_ERROR_MAX);
        const out    = boundReflectedText(astral);

        expect(out.length).toBe(REFLECTED_ERROR_MAX);
        expect(out.endsWith('...')).toBe(true);
    });

    it('does NOT sanitize — control and format characters are the server\'s job', function () {
        // The load-bearing negative. #793's body asked for a JS strip; v1.17.8 ruled the
        // strip is single-owned by _pp_clean_reflected_text() on the server, because a
        // second definition of "clean" could only drift from the first. If this ever starts
        // passing with the characters removed, that decision was reversed by accident.
        const hostile = 'name ‮ reversed ​ zero-width  bell';

        expect(boundReflectedText(hostile)).toBe(hostile);
        expect(boundReflectedText(hostile)).toContain('‮');
        expect(boundReflectedText(hostile)).toContain('​');
    });

    it('returns a non-string AS ITSELF when its rendered form fits', function () {
        // The identity half of the contract. Every call site coerces on its own
        // (`'Error: ' + x`, `textContent = x`), and returning the string form here would
        // change what they render: `textContent = null` is empty, while String(null) is the
        // word "null". So a value that fits comes back untouched and nothing renders
        // differently. What a non-string element MEANS in a chat payload stays #775's
        // contract question; this answers only how long it may be.
        const obj = { error: 'nested' };
        const arr = ['a', 'b'];

        expect(boundReflectedText(obj)).toBe(obj);
        expect(boundReflectedText(arr)).toBe(arr);
        expect(boundReflectedText(null)).toBe(null);
        expect(boundReflectedText(undefined)).toBe(undefined);
        expect(boundReflectedText(42)).toBe(42);
        expect(boundReflectedText(false)).toBe(false);
        expect(boundReflectedText('')).toBe('');
    });

    it('bounds a non-string whose RENDERED form is oversized', function () {
        // The hole this closes. A `typeof text !== 'string'` guard would hand an array
        // straight back, and the call site's own `'Error: ' + x` or `textContent = x` would
        // then stringify a million elements into the DOM at full length — the ceiling
        // bypassed by the one payload shape the theme never produces, which is exactly the
        // shape a defensive ceiling exists for.
        const arr = new Array(200000).fill('AAAAAAAAAA');
        const out = boundReflectedText(arr);

        expect(typeof out).toBe('string');
        expect(out.length).toBe(REFLECTED_ERROR_MAX);
        expect(out.endsWith('...')).toBe(true);

        const obj = { toString: () => 'x'.repeat(REFLECTED_ERROR_MAX * 2) };
        const objOut = boundReflectedText(obj);

        expect(typeof objOut).toBe('string');
        expect(objOut.length).toBe(REFLECTED_ERROR_MAX);
    });

    it('hands back a value whose own toString throws, rather than throwing here', function () {
        // The call site's coercion throws on these too. Returning the value keeps that
        // failure exactly where it already was instead of moving it one frame earlier into
        // a helper whose whole job is supposed to be measuring a length.
        const hostile = { toString: function () { throw new Error('nope'); } };

        expect(function () { boundReflectedText(hostile); }).not.toThrow();
        expect(boundReflectedText(hostile)).toBe(hostile);
    });

    it('shares the server\'s budget with the undo card\'s bound', function () {
        // Both client constants are hand-copies of PP_REFLECTED_ERROR_MAX. The PHP side
        // asserts each against the server (tests/ChatUndoBoundTrait.php,
        // tests/ChatReflectedTextBoundTest.php); this catches the two drifting from each
        // other from the JavaScript side, which is where they are read.
        expect(REFLECTED_ERROR_MAX).toBe(UNDO_ERROR_MAX);
    });
});

// ─── the real row builder ───────────────────────────────────────────────────

describe('a hostile finding rendered through the real row builder', function () {
    let container;

    beforeEach(function () {
        container = document.createElement('div');
    });

    const classFor = function () { return 'pp-ai-validation-item'; };

    it('bounds an oversized finding message on the rendered row', function () {
        const locator = '[unknown_prop] index 2: ';

        appendValidationItems(container, [{
            type: 'unknown_prop',
            index: 2,
            message: 'x'.repeat(REFLECTED_ERROR_MAX * 4)
        }], classFor);

        const row = container.querySelector('.pp-ai-validation-item');

        expect(row).not.toBeNull();
        expect(row.textContent.length).toBe(locator.length + REFLECTED_ERROR_MAX);
        expect(row.textContent.endsWith('...')).toBe(true);
    });

    it('keeps the locator vocabulary whole and at the front of the bounded row', function () {
        // #655 made `[type] index N: ` the card's authoritative locator, and #793's body
        // notes a stored value can imitate it mid-message. The locator must therefore stay
        // whole and stay FIRST — a bound that cut from the left, or that spent the budget on
        // the message and cut the prefix, would remove the half a reader has to trust.
        appendValidationItems(container, [{
            type: 'unknown_prop',
            index: 7,
            message: 'y'.repeat(REFLECTED_ERROR_MAX * 2)
        }], classFor);

        const row = container.querySelector('.pp-ai-validation-item');

        expect(row.textContent.startsWith('[unknown_prop] index 7: ')).toBe(true);
        expect(row.textContent.endsWith('...')).toBe(true);
    });

    it('bounds the row when the LOCATOR is the oversized half, without starving the message', function () {
        // ppChatFindingBand() gates `index` to a finite non-negative integer, but
        // ppChatFindingLocator() only tests `type` for being a non-empty string. Every one
        // of the ~45 server emitters passes a literal, so a long `type` cannot come from
        // this theme — and nothing on this side enforces that.
        //
        // THE MESSAGE MUST SURVIVE IT. Bounding the two halves as ONE composed span would
        // let the hostile `type` eat the whole budget and cut the validator message away
        // entirely, leaving a plausible-looking locator and no actionable text — the display
        // dishonesty of #793 reappearing as its own fix. Two spans mean a hostile `type`
        // costs the reader the locator and nothing else.
        appendValidationItems(container, [{
            type: 'z'.repeat(REFLECTED_ERROR_MAX * 3),
            index: 0,
            message: 'Component "hero" has an unknown prop "headline".'
        }], classFor);

        const row = container.querySelector('.pp-ai-validation-item');

        expect(row.textContent.length).toBeLessThanOrEqual(REFLECTED_ERROR_MAX * 2);
        expect(row.textContent).toContain('Component "hero" has an unknown prop "headline".');
        expect(row.textContent.startsWith('[zzz')).toBe(true);
    });

    it('renders an oversized payload as text, never as markup', function () {
        appendValidationItems(container, [{
            type: 'unknown_prop',
            index: 0,
            message: '<img src=x onerror=alert(1)>' + 'x'.repeat(REFLECTED_ERROR_MAX * 2)
        }], classFor);

        const row = container.querySelector('.pp-ai-validation-item');

        expect(row.querySelector('img')).toBeNull();
        expect(row.textContent).toContain('<img src=x onerror=alert(1)>');
        expect(row.textContent.length).toBeLessThanOrEqual(REFLECTED_ERROR_MAX * 2);
        expect(row.textContent.endsWith('...')).toBe(true);
    });

    it('bounds a finding whose message is a non-string, oversized payload', function () {
        // The row builder is where a non-string `message` most plausibly arrives, and where
        // the old `typeof` guard would have let it through: `locator + arr` stringifies the
        // whole array into the DOM.
        appendValidationItems(container, [{
            type: 'unknown_prop',
            index: 3,
            message: new Array(200000).fill('AAAAAAAAAA')
        }], classFor);

        const row = container.querySelector('.pp-ai-validation-item');

        expect(row.textContent.length).toBeLessThanOrEqual(REFLECTED_ERROR_MAX * 2);
        expect(row.textContent.endsWith('...')).toBe(true);
    });

    it('leaves an ordinary finding row byte-identical to before the bound', function () {
        // The bound must be invisible on every message the theme actually produces.
        appendValidationItems(container, [{
            type: 'unknown_prop',
            index: 1,
            message: 'Component "hero" has an unknown prop "headline".'
        }], classFor);

        const row = container.querySelector('.pp-ai-validation-item');

        expect(row.textContent).toBe('[unknown_prop] index 1: Component "hero" has an unknown prop "headline".');
    });

    it('renders a finding with no message exactly as it did before the bound', function () {
        // The helper's null/undefined early return is tested directly above, but the ROW is
        // where a reader would ever see it, and the two-span composition is the code that
        // preserves it. `'[type] index N: ' + undefined` produced this string before #793 and
        // must still: the bound is not licence to change what an off-contract finding renders.
        appendValidationItems(container, [{ type: 'unknown_prop', index: 1 }], classFor);

        expect(container.querySelector('.pp-ai-validation-item').textContent)
            .toBe('[unknown_prop] index 1: undefined');
    });

    it('renders a finding whose message is null exactly as it did before the bound', function () {
        appendValidationItems(container, [{ type: 'unknown_prop', index: 1, message: null }], classFor);

        expect(container.querySelector('.pp-ai-validation-item').textContent)
            .toBe('[unknown_prop] index 1: null');
    });

    it('still renders a post-apply item that carries no locator at all', function () {
        // Those items have no `type`, so the locator is '' and the row is the message alone
        // — the path that must be unchanged by composing the two halves before bounding.
        appendValidationItems(container, [{
            check: 'media',
            message: 'Referenced image 41 is missing.'
        }], classFor);

        const row = container.querySelector('.pp-ai-validation-item');

        expect(row.textContent).toBe('Referenced image 41 is missing.');
    });
});
