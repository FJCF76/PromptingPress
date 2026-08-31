/**
 * The post-apply links fire exactly ONCE, whatever activates them (#861).
 *
 * THE BUG, MEASURED. Both links on the post-apply card guarded a second activation with
 * `link.style.pointerEvents = 'none'`, set before the fetch, restored by no branch
 * anywhere — so single-shot was plainly the intent. `pointer-events: none` removes an
 * element as a MOUSE target and nothing else: the anchor stays focusable, and Enter on a
 * focused anchor still runs its activation behavior and dispatches `click`. In headless
 * Chromium, on exactly this idiom, a mouse click gives 1 handler invocation and two
 * subsequent Enter presses give 3.
 *
 * WHY IT COST SOMETHING. Both links WRITE.
 *
 *   undoLink  ──▶ POST restore_composition + expected_version (the CAS baseline)
 *                 The first activation bumps or consumes that baseline, so the second
 *                 request is refused for a conflict the operator caused by pressing
 *                 Enter twice — a concurrent-writer story about a page nobody else
 *                 touched, told to the one operator who cannot see the mouse-only guard.
 *   resetLink ──▶ POST reset_design_token, idempotent, so a duplicate request rather
 *                 than a wrong answer. Still a duplicate write.
 *
 * WHAT jsdom CAN AND CANNOT MODEL HERE, said plainly rather than papered over. jsdom has
 * no layout and `dispatchEvent` does no hit-testing, so `pointer-events` is invisible to
 * it and a dispatched click is NOT a model of the mouse path. It is an exact model of the
 * KEYBOARD path, which is the path this bug is about: Enter on a focused anchor does not
 * consult `pointer-events` either — it runs activation behavior and dispatches a click,
 * the same untrusted-but-real event these tests send. So every activation below stands
 * for "the operator pressed Enter again", and the pre-fix source fails them for the same
 * reason a real keyboard fails it. The mouse half, and the browser's own pointer-events
 * behavior, are pinned in real Chromium in tests/e2e/ai-chat.spec.ts.
 *
 * THE BOOT SCAFFOLD IS COPIED ON PURPOSE, not by drift. The JSDOM markup, the `global.*`
 * assignments, the fetch route table and applyAndGetCard() below are near-twins of the ones
 * in tests/js/pp-ai-chat-conflict-rollback.test.js and
 * tests/js/pp-ai-chat-batch-steps-shape.test.js. Extracting them was considered and not
 * done here: assets/js/pp-ai-chat.js boots a module-level IIFE ONCE per require, reading
 * `window.ppAiChat` and localStorage as it goes, so each suite needs its OWN window, its
 * own storage key and its own route table established BEFORE the require. A shared helper
 * would have to own that ordering for every caller, and a suite that got it wrong would
 * fail as a mystery rather than as a bad import. The duplication is three independent
 * boots, which is the property being bought. Extracting it is filed as a follow-up rather
 * than done in a bug-fix diff — if you are that follow-up, this paragraph is the reason to
 * beat, and the ordering constraint is the thing to preserve.
 *
 * WHY THIS FILE DRIVES THE REAL CARD. buildPostApplyCard() lives inside the file's IIFE
 * and is not exported; the links, their handlers, and the FormData they build exist only
 * as a consequence of a proposal actually being applied. A helper test can prove the
 * latch works and still leave the links wired to nothing — a missing call is exactly what
 * helper tests cannot see (#797's file makes the same argument). So the blocks below send
 * a message, let the client preview and apply, and then activate the links on the card the
 * operator would be looking at. The fetch mock is the only seam, and it records every
 * request so the count is the assertion.
 */

const { JSDOM } = require('jsdom');

const dom = new JSDOM('<!DOCTYPE html><html><body>' +
    '<div id="pp-ai-messages"></div>' +
    '<textarea id="pp-ai-input"></textarea>' +
    '<button id="pp-ai-send"></button>' +
    '<button id="pp-ai-new-chat"></button>' +
    '<select id="pp-ai-page-select">' +
    '<option value=""></option><option value="42">Landing</option>' +
    '</select>' +
    '</body></html>', { url: 'http://localhost' });

global.window = dom.window;
global.document = dom.window.document;
global.HTMLElement = dom.window.HTMLElement;
global.localStorage = dom.window.localStorage;
global.FormData = dom.window.FormData;
global.AbortController = dom.window.AbortController;

const SITE_URL = 'http://oneshot.example.com';
const USER_ID = '9';
const PAGE_ID = 42;
const STORAGE_KEY = 'pp_ai_chat_' + SITE_URL + '_' + USER_ID;

dom.window.ppAiChat = {
    configured: true,
    ajaxUrl: '/wp-admin/admin-ajax.php',
    executeNonce: 'test-nonce',
    siteUrl: SITE_URL,
    currentUserId: USER_ID,
    streamUrl: '/wp-admin/admin-ajax.php?action=pp_ai_stream',
    streamNonce: 'stream-nonce',
    pages: [{ id: PAGE_ID, title: 'Landing' }],
    impact_warnings: {}
};
global.window.ppAiChat = dom.window.ppAiChat;

// A page selection and a CAS baseline must exist before the IIFE boots: sendMessage()
// refuses to send without a selected page, and the undo link only threads
// expected_version when it holds a baseline for the page.
localStorage.setItem(STORAGE_KEY, JSON.stringify({
    activePageId: PAGE_ID,
    pageBaselines: { 42: 3 },
    conversation: []
}));

// ─── Fixtures ────────────────────────────────────────────────────────────────

/** A composition mutation on one page — what earns the card its "Undo these changes". */
const UNDO_STEPS = [
    {
        type: 'action',
        name: 'update_component',
        description: 'Update the hero title',
        params: { post_id: PAGE_ID, component_index: 0, props: { title: 'New' } }
    }
];

/** A lone update_design_token — the only shape ppChatIsRevertEligible() accepts. */
const RESET_STEPS = [
    {
        type: 'apply',
        name: 'update_design_token',
        description: 'Make the accent blue',
        params: { token: '--color-accent', value: '#0000ff' }
    }
];

/** Every request the client made, in order. The count IS the assertion. */
let calls = [];
/** Which proposal the mocked stream hands back on the next send. */
let proposedSteps = UNDO_STEPS;
/** What the undo/reset POST answers with, so both outcomes can be driven. */
let executeResponse = { success: true, data: { composition_version: 5, findings: [] } };
/**
 * Whether the undo/reset POST never comes back at all — the `.catch` arm.
 *
 * It gets its own seam because it is the branch most likely to tempt a future author into
 * RE-ARMING the link ("the request failed, let them retry"), which would reintroduce
 * exactly the duplicate write #861 closed, on the path where the first request may well
 * have reached the server anyway.
 */
let executeRejects = false;

function jsonOk(payload) {
    return Promise.resolve({ ok: true, json: function () { return Promise.resolve(payload); } });
}

global.fetch = function (url, opts) {
    // The SSE path rejects, dropping the client onto ajaxFallback() immediately — the
    // same code path a buffering proxy produces, rendering through the identical
    // renderProposal().
    if (url === dom.window.ppAiChat.streamUrl) {
        return Promise.reject(new Error('no stream here'));
    }

    const body = opts && opts.body;
    const get = (k) => (body && typeof body.get === 'function' ? body.get(k) : null);
    const action = get('action') || 'chat-fallback';
    // `name` is what separates the two one-shot writes from everything else on this
    // endpoint: both travel as pp_ai_execute and differ only by the action they name.
    calls.push({ action: action, name: get('name'), expectedVersion: get('params[expected_version]') });

    if (action === 'pp_ai_preview') {
        return jsonOk({ success: true, data: { changes: [{ path: 'props.title', from: 'Old', to: 'New' }] } });
    }
    if (action === 'pp_ai_execute_batch') {
        return jsonOk({ success: true, data: { ok: true, steps: [{ ok: true }], failed_at: null, versions: { 42: 4 } } });
    }
    if (action === 'pp_ai_execute') {
        return executeRejects
            ? Promise.reject(new Error('transport failure'))
            : jsonOk(executeResponse);
    }
    if (action === 'pp_ai_page_baseline') {
        return jsonOk({ success: true, data: { post_id: PAGE_ID, version: 4 } });
    }
    if (action === 'pp_ai_chat') {
        return jsonOk({
            success: true,
            data: {
                content: 'Here is my proposal.',
                proposal: { proposal: true, steps: proposedSteps },
                page_baseline: { post_id: PAGE_ID, version: 3 }
            }
        });
    }
    // Loud rather than helpful: an unrecognized action answered with a plausible
    // response is how a seam that measures nothing passes for a green test.
    throw new Error('unexpected action in fetch mock: ' + action);
};

const { oneShotLink, LINK_SPENT_ATTR } = require('../../assets/js/pp-ai-chat.js');

// ─── Harness ─────────────────────────────────────────────────────────────────

const messagesEl = document.getElementById('pp-ai-messages');
const inputEl = document.getElementById('pp-ai-input');
const sendBtn = document.getElementById('pp-ai-send');

/** Drain the promise chains the client queues (fetch → json → render, several deep). */
async function settle() {
    for (let i = 0; i < 60; i++) await Promise.resolve();
}

/** Sends a message, previews, clicks Apply, and returns the settled post-apply card. */
async function applyAndGetCard() {
    inputEl.value = 'Change the hero';
    sendBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    const cards = messagesEl.querySelectorAll('.pp-ai-proposal-card');
    expect(cards.length).toBeGreaterThan(0);
    const card = cards[cards.length - 1];

    const applyBtn = card.querySelector('.pp-ai-proposal-apply');
    expect(applyBtn).not.toBeNull();
    applyBtn.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    await settle();

    // Premise for every assertion below: this really is the post-apply rendering, which
    // is the only state that draws these links at all.
    expect(card.querySelector('.pp-ai-post-apply-links')).not.toBeNull();
    return card;
}

/** The card's one-shot link — always the last in the row, after "View Page". */
function oneShotOn(card) {
    const links = card.querySelectorAll('.pp-ai-post-apply-links a');
    expect(links.length).toBeGreaterThan(0);
    return links[links.length - 1];
}

/**
 * One activation, the way a keyboard produces one.
 *
 * A bubbling, cancelable click is precisely what an anchor's activation behavior
 * dispatches when Enter is pressed on it — no hit-testing, so `pointer-events` never
 * enters the picture, which is the whole bug.
 */
async function activate(link) {
    link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    await settle();
}

/** How many times the client asked the server to do `name`. */
function callsNamed(name) {
    return calls.filter((c) => c.name === name).length;
}

beforeEach(function () {
    calls = [];
    proposedSteps = UNDO_STEPS;
    executeResponse = { success: true, data: { composition_version: 5, findings: [] } };
    executeRejects = false;
    messagesEl.innerHTML = '';
});

// ─── The helper's own contract ───────────────────────────────────────────────

describe('ppChatOneShotLink', function () {
    function newLink() {
        const a = document.createElement('a');
        a.href = '#';
        a.textContent = 'Do the thing';
        return a;
    }

    it('runs the work once and marks the link spent', function () {
        let ran = 0;
        const link = newLink();
        oneShotLink(link, 'Doing…', function () { ran++; });

        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(ran).toBe(1);
        expect(link.textContent).toBe('Doing…');
        expect(link.getAttribute(LINK_SPENT_ATTR)).toBe('true');
        // Kept, because it is the spent link's mouse affordance — but it is not the
        // guard, and the tests below are what prove that.
        expect(link.style.pointerEvents).toBe('none');
    });

    it('refuses every later activation path, not just the mouse', function () {
        let ran = 0;
        const link = newLink();
        oneShotLink(link, 'Doing…', function () { ran++; });

        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        // Each of these is a real way a second activation arrives, and NONE of them
        // consults `pointer-events`: the keyboard's synthesized click, a script's
        // `.click()`, a double-click's second click, an untrusted dispatched event.
        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
        link.click();
        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(ran).toBe(1);
    });

    it('does not re-arm when the ARIA attribute is cleared from outside', function () {
        // THE LATCH IS PRIVATE, AND THIS IS WHY. `aria-disabled` is an ACCESSIBILITY
        // attribute that happens to describe the same state; it is not the state. A later
        // pass that normalises ARIA, or re-renders the link's attributes, would re-arm a
        // write path if the guard read its own answer back out of the DOM. Nothing in this
        // file does that today — the flag is private anyway, because the cost is one
        // variable and the failure it prevents is a duplicate composition restore.
        let ran = 0;
        const link = newLink();
        oneShotLink(link, 'Doing…', function () { ran++; });

        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        link.removeAttribute(LINK_SPENT_ATTR);
        link.style.pointerEvents = '';
        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(ran).toBe(1);
    });

    it('latches BEFORE the work runs, so re-entry during it cannot double-fire', function () {
        // The ordering contract: a `run()` that itself activates the link (a focus change,
        // a synthetic event, anything reentrant) must not find an unspent link.
        let ran = 0;
        const link = newLink();
        oneShotLink(link, 'Doing…', function () {
            ran++;
            link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        });

        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(ran).toBe(1);
    });

    it('always prevents the anchor default, spent or not', function () {
        // href="#" would otherwise scroll the admin page to the top under the operator on
        // every refused press — the guard must not buy its silence with that.
        const link = newLink();
        oneShotLink(link, 'Doing…', function () {});

        const first = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(first);
        const second = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(second);

        expect(first.defaultPrevented).toBe(true);
        expect(second.defaultPrevented).toBe(true);
    });

    it('stays spent after the work throws, exactly as the pre-#861 links did', function () {
        // No branch ever restored `pointerEvents` either, so "spent forever, including on
        // failure" is preserved behavior, not a new decision.
        //
        // The throw does NOT reach `dispatchEvent`'s caller — DOM event dispatch reports a
        // listener's exception to the global error handler and carries on, which is why
        // this is written as a state assertion rather than a `.toThrow()`. That is also
        // precisely why the latch is set BEFORE `run()`: a throw here is invisible to
        // everything except the link's own state, so if the latch came after, a link that
        // failed silently would silently re-arm.
        let ran = 0;
        const link = newLink();
        oneShotLink(link, 'Doing…', function () { ran++; throw new Error('boom'); });

        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
        link.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));

        expect(ran).toBe(1);
        expect(link.getAttribute(LINK_SPENT_ATTR)).toBe('true');
    });
});

// ─── The real card ───────────────────────────────────────────────────────────

describe('the post-apply card — Undo these changes', function () {
    it('sends exactly one restore_composition however many times it is activated', async function () {
        const card = await applyAndGetCard();
        const undo = oneShotOn(card);
        expect(undo.textContent).toBe('Undo these changes');

        await activate(undo);
        await activate(undo);
        await activate(undo);

        // THE PIN. Against the pre-#861 source this is 3: every dispatched click reached
        // the handler, and each one POSTed a restore with the same now-stale baseline.
        expect(callsNamed('restore_composition')).toBe(1);
    });

    it('leaves the first activation byte-identical — same request, same reporting', async function () {
        const card = await applyAndGetCard();
        const undo = oneShotOn(card);

        await activate(undo);

        const restores = calls.filter((c) => c.name === 'restore_composition');
        expect(restores).toHaveLength(1);
        // The CAS baseline still travels, and it is the one the batch response refreshed
        // (#404) — not the seeded 3. A guard that changed which version was threaded would
        // be a different bug wearing this fix's name.
        expect(restores[0].expectedVersion).toBe('4');
        expect(restores[0].action).toBe('pp_ai_execute');
        // And the success reporting is untouched.
        expect(undo.textContent).toBe('Changes undone ✓');
    });

    it('announces itself as disabled to assistive tech once spent', async function () {
        const card = await applyAndGetCard();
        const undo = oneShotOn(card);
        expect(undo.getAttribute(LINK_SPENT_ATTR)).toBeNull();

        await activate(undo);

        // The operator most likely to reach this link by keyboard is the one who most
        // needs the state announced, and `pointer-events` announces nothing.
        expect(undo.getAttribute(LINK_SPENT_ATTR)).toBe('true');
    });

    it('stays spent after a refusal, and does not retry on a second press', async function () {
        // The reachable failure: #818's preserved-bytes refusal. Pre-fix, pressing Enter
        // again here re-sent the restore and re-rendered the refusal row.
        executeResponse = {
            success: false,
            data: 'History entry 0 (steps_back 1) holds stored bytes that did not decode '
                + 'to a composition, so it cannot be replayed as one.'
        };

        const card = await applyAndGetCard();
        const undo = oneShotOn(card);

        await activate(undo);
        expect(undo.textContent).toBe('Undo failed');
        const afterFirst = callsNamed('restore_composition');

        await activate(undo);
        await activate(undo);

        expect(afterFirst).toBe(1);
        expect(callsNamed('restore_composition')).toBe(1);
        // The refusal row is still the one the first attempt drew, not a second copy.
        expect(card.querySelectorAll('.pp-ai-undo-failure')).toHaveLength(1);
    });
});

describe('the post-apply card — Reset to default', function () {
    it('sends exactly one reset_design_token however many times it is activated', async function () {
        proposedSteps = RESET_STEPS;

        const card = await applyAndGetCard();
        const reset = oneShotOn(card);
        expect(reset.textContent).toBe('Reset to default');

        await activate(reset);
        await activate(reset);
        await activate(reset);

        // Idempotent on the server, so this was never a wrong ANSWER — but three writes
        // were sent where the code plainly meant to send one.
        expect(callsNamed('reset_design_token')).toBe(1);
        expect(reset.textContent).toBe('Reset applied ✓');
        expect(reset.getAttribute(LINK_SPENT_ATTR)).toBe('true');
    });

    it('stays spent after a refusal, and does not retry on a second press', async function () {
        // Parity with the undo link's refusal pin. Both links keep the spent state through
        // their failure branches, which is the pre-#861 behavior preserved — no branch ever
        // restored `pointerEvents` either.
        proposedSteps = RESET_STEPS;
        executeResponse = { success: false, data: 'That token is not resettable.' };

        const card = await applyAndGetCard();
        const reset = oneShotOn(card);

        await activate(reset);
        expect(reset.textContent).toBe('Reset failed');
        expect(callsNamed('reset_design_token')).toBe(1);

        await activate(reset);
        await activate(reset);

        expect(callsNamed('reset_design_token')).toBe(1);
        expect(reset.getAttribute(LINK_SPENT_ATTR)).toBe('true');
    });
});

describe('a one-shot link whose request never comes back', function () {
    // THE ARM MOST LIKELY TO BE "HELPFULLY" RE-ARMED LATER. A transport failure looks like
    // the one case where letting the operator try again is kind — but the first request may
    // have reached the server and written, so a retry is the duplicate write in a costume.
    // The links stay spent, and these pin it on both.

    it('keeps the undo link spent, even once the transport recovers', async function () {
        const card = await applyAndGetCard();
        const undo = oneShotOn(card);

        executeRejects = true;
        await activate(undo);
        expect(callsNamed('restore_composition')).toBe(1);
        expect(undo.textContent).toBe('Undo failed');
        // The transport arm draws NO refusal row — there is no server sentence to render.
        expect(card.querySelectorAll('.pp-ai-undo-failure')).toHaveLength(0);

        // A retry would now succeed. The latch must still refuse it.
        executeRejects = false;
        await activate(undo);
        await activate(undo);

        expect(callsNamed('restore_composition')).toBe(1);
        expect(undo.getAttribute(LINK_SPENT_ATTR)).toBe('true');
    });

    it('keeps the reset link spent, even once the transport recovers', async function () {
        proposedSteps = RESET_STEPS;

        const card = await applyAndGetCard();
        const reset = oneShotOn(card);

        executeRejects = true;
        await activate(reset);
        expect(callsNamed('reset_design_token')).toBe(1);
        expect(reset.textContent).toBe('Reset failed');

        executeRejects = false;
        await activate(reset);
        await activate(reset);

        expect(callsNamed('reset_design_token')).toBe(1);
        expect(reset.getAttribute(LINK_SPENT_ATTR)).toBe('true');
    });
});

/**
 * SOURCE TRIPWIRE — the idiom, not the two links (#861).
 *
 * Every test above proves the two links that exist today are guarded. None of them can
 * see a THIRD one-shot write link added next year with the old `pointerEvents = 'none'`
 * and nothing else, which is the shape of the bug coming back. `pointer-events` is a
 * mouse affordance and never a guard, so the checkable fact is: this file writes it in
 * exactly one place, and that place is inside the helper.
 *
 * Same idiom the file family already uses for wiring that unit tests cannot reach — see
 * the source-tripwire blocks in tests/js/pp-ai-chat-conflict-rollback.test.js and
 * tests/js/pp-ai-chat-preview-render-isolation.test.js.
 */
describe('one-shot idiom (source tripwire)', function () {
    const fs = require('fs');
    const path = require('path');

    it('writes pointerEvents in exactly one place, inside the helper', function () {
        // Comments stripped first: the docblocks discuss the old idiom at length, and
        // prose about a mistake is not a place the mistake can be made.
        const src = fs.readFileSync(
            path.resolve(__dirname, '../../assets/js/pp-ai-chat.js'), 'utf-8',
        ).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

        expect(src.match(/\.style\.pointerEvents\s*=/g) || []).toHaveLength(1);

        const start = src.indexOf('function ppChatOneShotLink(');
        expect(start).toBeGreaterThan(-1);
        const helper = src.slice(start, src.indexOf('\n}', start));
        expect(helper).toContain('.style.pointerEvents =');
    });

    it('binds both post-apply links through the helper', function () {
        const src = fs.readFileSync(
            path.resolve(__dirname, '../../assets/js/pp-ai-chat.js'), 'utf-8',
        ).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

        // The declaration plus its two call sites. A fourth means a new one-shot link
        // arrived, and this is where it gets checked for its guard.
        expect(src.split('ppChatOneShotLink(').length - 1).toBe(3);
        expect(src).toContain('ppChatOneShotLink(resetLink,');
        expect(src).toContain('ppChatOneShotLink(undoLink,');
        // And neither link re-acquired a raw handler alongside it.
        expect(src).not.toContain("resetLink.addEventListener('click'");
        expect(src).not.toContain("undoLink.addEventListener('click'");
    });
});
