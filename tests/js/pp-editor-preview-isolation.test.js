/**
 * The editor preview renders content in an isolated origin (LAYER-3-CONTRACT §8.3).
 *
 * The iframe is sandboxed without allow-same-origin (pinned in
 * tests/PreviewFrameIsolationTest.php), so the editor can no longer read or
 * write the preview document. This file pins the editor side of that change:
 *
 *   1. SCHEMA   the only message the editor accepts from the frame is a scroll
 *               position: exactly {type: 'pp-preview:scroll', y: <finite >= 0>}.
 *   2. BRIDGE   the script appended to every preview document interpolates
 *               nothing but a bounded non-negative integer, and at run time
 *               restores instantly, keeps restoring while the document grows,
 *               reports once per scroll burst, never reports a clamp of a
 *               still-growing document, and never pulls a reader back.
 *   3. REFRESH  booting the REAL pp-admin-editor.js: each preview response
 *               rebuilds srcdoc (html + bridge); a message is honoured only from
 *               the frame's own window AND only when it passes the schema; only
 *               the latest preview request may paint.
 *   4. SOURCE   the editor never reaches into the frame's document.
 *
 * Every refusal is asserted against a NON-default position established first by
 * a valid message: asserting the default (0) would pass through the bug path
 * too, because the bridge coerces junk to 0.
 *
 * The browser half (the frame really is an opaque origin, real cross-frame
 * messages, and the preview still renders) is tests/e2e/preview-isolation.spec.ts.
 *
 * @vitest-environment jsdom
 */

const fs   = require('fs');
const path = require('path');

const LOGIC_PATH  = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-editor-logic.js');
const EDITOR_PATH = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-admin-editor.js');

const { isPreviewScrollMessage, previewScrollBridge } = require(LOGIC_PATH);

// ─── 1. Schema ──────────────────────────────────────────────────────────────

describe('isPreviewScrollMessage — the frame may report a scroll position and nothing else', () => {
    it.each([
        ['zero',               { type: 'pp-preview:scroll', y: 0 }],
        ['an integer',         { type: 'pp-preview:scroll', y: 640 }],
        ['a fractional value', { type: 'pp-preview:scroll', y: 12.5 }],
        ['keys in any order',  { y: 3, type: 'pp-preview:scroll' }],
    ])('accepts %s', (_label, data) => {
        expect(isPreviewScrollMessage(data)).toBe(true);
    });

    it.each([
        ['an extra field',        { type: 'pp-preview:scroll', y: 10, extra: 1 }],
        ['an action verb',        { type: 'pp-preview:scroll', y: 10, action: 'save' }],
        ['an action as the type', { type: 'save', y: 10 }],
        ['markup',                { type: 'pp-preview:scroll', y: 10, html: '<b>x</b>' }],
        ['a URL',                 { type: 'pp-preview:scroll', y: 10, url: 'https://example.test/' }],
        ['a selector',            { type: 'pp-preview:scroll', y: 10, selector: '#pp-save-btn' }],
        ['y missing',             { type: 'pp-preview:scroll', x: 10 }],
        ['type missing',          { y: 10, kind: 'pp-preview:scroll' }],
        ['a string y',            { type: 'pp-preview:scroll', y: '10' }],
        ['a negative y',          { type: 'pp-preview:scroll', y: -1 }],
        ['NaN',                   { type: 'pp-preview:scroll', y: NaN }],
        ['Infinity',              { type: 'pp-preview:scroll', y: Infinity }],
        ['a boxed number',        { type: 'pp-preview:scroll', y: Object(5) }],
        ['null',                  null],
        ['a bare number',         640],
        ['a string',              'pp-preview:scroll'],
        ['an array',              ['pp-preview:scroll', 10]],
    ])('rejects %s', (_label, data) => {
        expect(isPreviewScrollMessage(data)).toBe(false);
    });

    it('rejects keys that are only inherited', () => {
        const data = Object.create({ type: 'pp-preview:scroll', y: 1 });
        data.a = 1; data.b = 2;
        expect(isPreviewScrollMessage(data)).toBe(false);
    });
});

// ─── 2. Bridge ──────────────────────────────────────────────────────────────

describe('previewScrollBridge — only bounded digits reach the script', () => {
    const target = (s) => /var y=([^;]*);/.exec(s)[1];

    it('is one complete script element', () => {
        const s = previewScrollBridge(10);
        expect(s.startsWith('<script>')).toBe(true);
        expect(s.endsWith('</script>')).toBe(true);
        expect(s.match(/<\/script>/g)).toHaveLength(1);
    });

    it.each([
        [640, '640'],
        [12.9, '12'],
        [0, '0'],
        [-5, '0'],
        [NaN, '0'],
        [Infinity, '0'],
        [2147483647, '10000000'],
        [1e21, '10000000'],
        [1e300, '10000000'],
        ['640', '0'],
        ['1;alert(1)', '0'],
        [undefined, '0'],
        [{ valueOf: () => 9 }, '0'],
    ])('interpolates %s as %s', (input, expected) => {
        const value = target(previewScrollBridge(input));
        expect(value).toBe(expected);
        expect(value).toMatch(/^\d+$/);
    });
});

describe('previewScrollBridge — run time, inside the frame', () => {
    // The bridge runs in the preview document. Here it runs against a fresh
    // stand-in window per test: a listener registry, a scroll position clamped
    // to the document's current scrollable height (`max`), and a ResizeObserver
    // stub, so a document that keeps GROWING after the script ran (lazy images
    // with no dimensions) can be modelled. Listeners never leak between tests.
    let win;
    let offset;
    let max;
    let scrollCalls;
    let posts;
    let observers;
    let images;

    function makeWindow() {
        // A plain listener registry: jsdom's own EventTarget needs a document to
        // decide passive defaults for wheel/touch listeners.
        const listeners = {};
        const w = {
            addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
            dispatch(type) { (listeners[type] || []).forEach((fn) => fn({ type })); },
            // A horizontal scrollbar: innerHeight includes it, clientHeight does
            // not. The maximum scroll is scrollHeight - clientHeight, i.e. `max`.
            innerHeight: 17,
            document: {
                documentElement: { style: {}, clientHeight: 0, get scrollHeight() { return max; } },
                querySelectorAll: (sel) => (sel === 'img[loading=lazy],iframe[loading=lazy]' ? images.filter((im) => im.loading === 'lazy') : []),
            },
            ResizeObserver: class {
                constructor(cb) { observers.push(cb); }
                observe() {}
            },
        };
        Object.defineProperty(w, 'pageYOffset', { get: () => offset });
        w.scrollTo = (a, b) => {
            scrollCalls.push(a);
            offset = Math.min(typeof a === 'object' ? a.top : b, max);
        };
        w.parent = { postMessage: (data, target) => { posts.push({ data, target }); } };
        return w;
    }

    function runBridge(y) {
        const src = previewScrollBridge(y).replace(/^<script>/, '').replace(/<\/script>$/, '');
        // eslint-disable-next-line no-new-func
        new Function('window', src)(win);
    }

    const fire = (type) => win.dispatch(type);
    /** The document grew to `height` of scrollable range; observers fire. */
    const grow = (height) => { max = height; observers.forEach((cb) => cb()); };
    const reported = () => posts.map((p) => p.data.y);
    /** A scroll event the bridge itself caused, after its throttle. */
    const settleTimers = () => vi.advanceTimersByTime(100);

    beforeEach(() => {
        vi.useFakeTimers();
        offset = 0;
        max = Infinity;
        scrollCalls = [];
        posts = [];
        observers = [];
        images = [{ loading: 'lazy' }, { loading: 'lazy', tag: 'iframe' }, { loading: 'eager' }];
        win = makeWindow();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('switches scroll anchoring off, so content arriving above holds the restored coordinate', () => {
        runBridge(480);
        expect(win.document.documentElement.style.overflowAnchor).toBe('none');
    });

    it('turns scroll anchoring back on at the reader\'s first input', () => {
        runBridge(480);
        fire('wheel');
        expect(win.document.documentElement.style.overflowAnchor).toBe('');
    });

    it('loads lazy images and iframes eagerly when restoring below the top, so content far above the target arrives', () => {
        runBridge(480);
        expect(images.map((im) => im.loading)).toEqual(['eager', 'eager', 'eager']);
    });

    it('loads them eagerly at the top too, so a coordinate is always measured in the layout it is restored into', () => {
        runBridge(0);
        expect(images.map((im) => im.loading)).toEqual(['eager', 'eager', 'eager']);
    });

    it('treats a shrink while still clamped as the clamp, not the reader: nothing reported, restore stays armed', () => {
        max = 500;
        runBridge(900);
        expect(offset).toBe(500);
        // The document gets SHORTER (e.g. a font swap): the browser lowers the offset.
        max = 450; offset = 450;
        fire('scroll'); settleTimers();
        expect(reported(), 'a clamp is not where the reader is').toEqual([]);
        grow(5000);
        expect(offset, 'the restore is still armed and reaches the target').toBe(900);
    });

    it('treats a fractional clamp within 1px of the maximum as the clamp (zoom, non-integer DPR)', () => {
        max = 500;
        runBridge(900);
        max = 450; offset = 449.6;
        fire('scroll'); settleTimers();
        expect(reported()).toEqual([]);
        grow(5000);
        expect(offset).toBe(900);
    });

    it('measures the maximum without a horizontal scrollbar: a reader just above it is the reader', () => {
        // innerHeight (17) includes the scrollbar; clientHeight does not. 8px
        // above the true maximum is a reader move, not the clamp.
        max = 500;
        runBridge(900);
        offset = 492;
        fire('scroll');
        expect(reported()).toEqual([492]);
        grow(5000);
        expect(offset).toBe(492);
    });

    it('still treats a move below max scroll as the reader, even while clamped', () => {
        max = 500;
        runBridge(900);
        offset = 300; // the reader scrolled up; the page is not at its maximum
        fire('scroll');
        expect(reported()).toEqual([300]);
        grow(5000);
        expect(offset).toBe(300);
    });

    it('restores instantly, never animating from the top', () => {
        runBridge(480);
        expect(scrollCalls[0]).toEqual({ top: 480, left: 0, behavior: 'instant' });
        expect(offset).toBe(480);
    });

    it('falls back to the two-argument form when the engine rejects the options form', () => {
        win.scrollTo = (a, b) => {
            if (typeof a === 'object') throw new TypeError('unsupported');
            scrollCalls.push([a, b]);
            offset = b;
        };
        runBridge(300);
        expect(scrollCalls).toEqual([[0, 300]]);
    });

    it('reports once per scroll burst, with the position at send time', () => {
        runBridge(0);
        offset = 100; fire('scroll');
        offset = 200; fire('scroll');
        offset = 250; fire('scroll');
        settleTimers();
        expect(posts).toEqual([{ data: { type: 'pp-preview:scroll', y: 250 }, target: '*' }]);
        expect(isPreviewScrollMessage(posts[0].data)).toBe(true);
    });

    it('keeps re-applying the restore while the document grows, past `load`, and reports nothing short of it', () => {
        // Lazy images with no dimensions: the page is short when the script runs
        // AND when `load` fires, and grows only as images near the viewport.
        max = 150;
        runBridge(900);
        expect(offset).toBe(150);
        fire('scroll'); settleTimers();
        grow(500);
        expect(offset).toBe(500);
        fire('scroll'); settleTimers();
        fire('load'); // still short: load is not "settled"
        fire('scroll'); settleTimers();
        expect(reported(), 'a clamp is not where the reader is').toEqual([]);

        grow(5000);
        expect(offset).toBe(900);
        fire('scroll'); settleTimers();
        expect(reported()).toEqual([900]);
    });

    it('treats a scroll it did not cause as the reader moving: settles, reports it at once, never pulls them back', () => {
        max = 150;
        runBridge(900);
        offset = 40; // a scrollbar drag fires no wheel/pointer/key input
        fire('scroll');
        expect(reported(), 'reported immediately, not after load').toEqual([40]);
        grow(5000);
        fire('load');
        expect(scrollCalls).toHaveLength(1);
        expect(offset).toBe(40);
    });

    it('does not pull back a reader whose move lands before its scroll event is dispatched', () => {
        // Scroll events are delivered asynchronously; a growth callback can run
        // between the reader's move and the event that would settle the bridge.
        max = 150;
        runBridge(900);
        offset = 40;
        grow(5000);
        expect(offset).toBe(40);
        expect(scrollCalls).toHaveLength(1);
    });

    it('falls back to `load` to re-apply the restore where ResizeObserver is missing', () => {
        delete win.ResizeObserver;
        max = 150;
        runBridge(900);
        max = 5000;
        fire('load');
        expect(offset).toBe(900);
    });

    it.each(['wheel', 'pointerdown', 'touchstart', 'keydown'])('settles on the reader\'s first %s', (input) => {
        max = 150;
        runBridge(900);
        fire(input);
        expect(reported()).toEqual([150]);
        grow(5000);
        expect(offset, 'settled: growth no longer moves the reader').toBe(150);
        offset = 60;
        fire('scroll'); settleTimers();
        expect(reported()).toEqual([150, 60]);
    });

    it('reports a scroll above the target at once when the restore reached it exactly', () => {
        runBridge(480);
        expect(offset).toBe(480);
        offset = 200;
        fire('scroll'); settleTimers();
        expect(reported()).toEqual([200]);
    });

    it('settles without a report, and watches no growth, when the restore reached its target', () => {
        runBridge(480);
        fire('load');
        expect(posts).toEqual([]);
        expect(observers).toHaveLength(0);
    });
});

// ─── 3. Refresh (real editor under jsdom) ───────────────────────────────────

const REGISTRY = [
    { name: 'card', templateOwned: false, schema: { props: { title: { type: 'string', required: false } } } },
];
const FIXTURE = JSON.stringify([{ component: 'card', props: { title: 'Hello' } }]);
const page    = (text) => `<!DOCTYPE html><html><head></head><body><main id="main">${text}</main></body></html>`;
const HTML    = page('rendered');

function installDom() {
    if (!window.Element.prototype.scrollIntoView) {
        window.Element.prototype.scrollIntoView = function () {};
    }
    document.body.innerHTML = [
        '<div id="pp-error-bar"></div>',
        '<div id="pp-save-status"></div>',
        '<div id="pp-preview-status"></div>',
        '<div id="pp-accordion-live"></div>',
        '<div id="pp-accordion-view"></div>',
        '<div id="pp-json-view"></div>',
        '<button id="pp-view-toggle">JSON</button>',
        '<button id="pp-save-btn">Save draft</button>',
        '<button id="pp-publish-btn">Publish</button>',
        '<div class="pp-pane pp-pane--editor"><div class="pp-pane-header"></div><div class="pp-pane-body">',
        '<textarea id="pp-composition-editor"></textarea>',
        '</div></div>',
        '<iframe id="pp-preview-frame" sandbox="allow-scripts"></iframe>',
    ].join('');
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function bootEditor() {
    installDom();

    const jquery = require('jquery');
    global.jQuery = jquery;
    global.$ = jquery;

    // Preview posts resolve at once with HTML unless `hold` is set, in which case
    // each is parked in `pending` as {resolve, reject} and settled by the test,
    // in any order.
    const previews = [];
    const pending  = [];
    const ctl = { hold: false, value: FIXTURE };
    jquery.post = (url, data) => {
        const isPreview = data.action === 'pp_preview_composition';
        const parked = {};
        if (isPreview) {
            previews.push(data);
            if (ctl.hold) pending.push(parked);
        }
        const chain = {
            done(fn) {
                if (isPreview) {
                    if (ctl.hold) parked.resolve = fn;
                    else fn({ success: true, data: { html: HTML } });
                }
                return chain;
            },
            fail(fn) {
                if (isPreview && ctl.hold) parked.reject = fn;
                return chain;
            },
            always() { return chain; },
        };
        return chain;
    };

    let onChange = null;
    const cm = {
        getValue:  () => ctl.value,
        setValue:  () => {},
        getRange:  () => '',
        getCursor: () => ({ line: 0, ch: 0 }),
        on:        (name, fn) => { if (name === 'change') onChange = fn; },
        refresh:   () => {},
        setSize:   () => {},
    };

    global.wp = window.wp = {
        CodeMirror: {
            fromTextArea:   () => cm,
            registerHelper: () => {},
            showHint:       () => {},
            Pos:            (line, ch) => ({ line, ch }),
        },
    };
    global.ppAdminEditor = window.ppAdminEditor = {
        components: REGISTRY,
        ajaxUrl: '/wp-admin/admin-ajax.php',
        nonce: 'test-nonce',
        postId: 1,
        postStatus: 'draft',
        compositionVersion: 1,
        codeEditorSettings: { codemirror: {} },
    };

    delete require.cache[require.resolve(LOGIC_PATH)];
    delete require.cache[require.resolve(EDITOR_PATH)];
    require(LOGIC_PATH);
    require(EDITOR_PATH);

    for (let i = 0; i < 50 && document.getElementById('pp-accordion-view').innerHTML === ''; i++) {
        await wait(1);
    }
    if (document.getElementById('pp-accordion-view').innerHTML === '') {
        throw new Error('editor never booted');
    }

    const frame = document.getElementById('pp-preview-frame');
    // The boot preview is debounced (500ms).
    await wait(600);

    return {
        frame,
        previews,
        pending,
        ctl,
        /** Edit the buffer and wait out the preview debounce. */
        async refresh() { onChange(); await wait(600); },
        /** Dispatch a message as if posted by `source`. */
        post(data, source) {
            window.dispatchEvent(new window.MessageEvent('message', { data, source, origin: 'null' }));
        },
        scrollTarget() {
            const m = /var y=(\d+);/.exec(frame.getAttribute('srcdoc') || '');
            return m ? Number(m[1]) : null;
        },
    };
}

describe('the preview refresh crosses the origin boundary only through srcdoc and a validated message', () => {
    afterEach(() => {
        const jquery = require('jquery');
        jquery(document).off();
        jquery(window).off();
        const highest = setTimeout(function () {}, 0);
        for (let id = 0; id <= highest; id++) clearTimeout(id);
        document.body.innerHTML = '';
        delete global.ppAdminEditor; delete window.ppAdminEditor;
        delete global.wp;            delete window.wp;
        delete global.jQuery;        delete global.$;
    });

    it('renders the response as the whole document plus the scroll bridge, opening at the top', async () => {
        const ed = await bootEditor();
        expect(ed.previews.length).toBeGreaterThan(0);
        expect(ed.frame.getAttribute('srcdoc')).toBe(HTML + previewScrollBridge(0));
    });

    it('rebuilds the document on every refresh, at the scroll position the frame last reported', async () => {
        const ed = await bootEditor();
        ed.post({ type: 'pp-preview:scroll', y: 480 }, ed.frame.contentWindow);
        await ed.refresh();
        expect(ed.frame.getAttribute('srcdoc')).toBe(HTML + previewScrollBridge(480));
    });

    it.each([
        ['a second sandboxed frame', () => {
            const other = document.createElement('iframe');
            other.setAttribute('sandbox', 'allow-scripts');
            document.body.appendChild(other);
            return other.contentWindow;
        }],
        ['the editor window itself', () => window],
        ['no window at all', () => null],
    ])('ignores a valid message from %s (the sender check)', async (_label, sender) => {
        const ed = await bootEditor();
        ed.post({ type: 'pp-preview:scroll', y: 120 }, ed.frame.contentWindow);
        ed.post({ type: 'pp-preview:scroll', y: 900 }, sender());
        await ed.refresh();
        expect(ed.scrollTarget()).toBe(120);
    });

    it.each([
        ['an extra field',        { type: 'pp-preview:scroll', y: 301, extra: true }],
        ['an action verb',        { type: 'pp-preview:scroll', y: 302, action: 'publish' }],
        ['an action as the type', { type: 'publish', y: 303 }],
        ['markup',                { type: 'pp-preview:scroll', y: 304, html: '<b>x</b>' }],
        ['a negative position',   { type: 'pp-preview:scroll', y: -305 }],
    ])('ignores a message from inside the frame carrying %s', async (_label, bad) => {
        const ed = await bootEditor();
        ed.post({ type: 'pp-preview:scroll', y: 120 }, ed.frame.contentWindow);
        ed.post(bad, ed.frame.contentWindow);
        await ed.refresh();
        expect(ed.scrollTarget()).toBe(120);
        // No message caused a request of any other kind either.
        expect(ed.previews.every((p) => p.action === 'pp_preview_composition')).toBe(true);
    });

    it('lets only the latest preview request paint, whatever order the responses arrive in', async () => {
        const ed = await bootEditor();
        ed.ctl.hold = true;
        await ed.refresh();
        await ed.refresh();
        expect(ed.pending).toHaveLength(2);
        const [older, newer] = ed.pending;
        newer.resolve({ success: true, data: { html: page('newer') } });
        older.resolve({ success: true, data: { html: page('older') } });
        expect(ed.frame.getAttribute('srcdoc').startsWith(page('newer'))).toBe(true);
    });

    it('forgets the scroll position when the editor is cleared, so the next preview opens at the top', async () => {
        const ed = await bootEditor();
        ed.post({ type: 'pp-preview:scroll', y: 480 }, ed.frame.contentWindow);
        ed.ctl.value = '';
        await ed.refresh();
        expect(ed.frame.getAttribute('srcdoc')).toBe('');
        ed.ctl.value = FIXTURE;
        await ed.refresh();
        expect(ed.scrollTarget()).toBe(0);
    });

    it('does not let a response that lands after the editor was cleared refill the preview', async () => {
        const ed = await bootEditor();
        ed.ctl.hold = true;
        await ed.refresh();
        ed.ctl.value = '';
        await ed.refresh();
        expect(ed.frame.getAttribute('srcdoc')).toBe('');
        ed.pending[0].resolve({ success: true, data: { html: page('stale') } });
        expect(ed.frame.getAttribute('srcdoc')).toBe('');
    });

    it('does not let an older request the server refused overwrite a newer preview or its status', async () => {
        const ed = await bootEditor();
        ed.ctl.hold = true;
        await ed.refresh();
        await ed.refresh();
        const [older, newer] = ed.pending;
        newer.resolve({ success: true, data: { html: page('newer') } });
        older.resolve({ success: false, data: 'Render failed.' });
        expect(document.getElementById('pp-preview-status').textContent).toBe('');
        expect(ed.frame.getAttribute('srcdoc').startsWith(page('newer'))).toBe(true);
    });

    it('does not let an older request that fails overwrite the status of a newer one', async () => {
        const ed = await bootEditor();
        ed.ctl.hold = true;
        await ed.refresh();
        await ed.refresh();
        const [older, newer] = ed.pending;
        newer.resolve({ success: true, data: { html: page('newer') } });
        expect(document.getElementById('pp-preview-status').textContent).toBe('');
        older.reject({ status: 500 });
        expect(document.getElementById('pp-preview-status').textContent).toBe('');
    });
});

// ─── 4. Source ──────────────────────────────────────────────────────────────

describe('the editor never reaches into the preview document', () => {
    // Whole-line // comments are stripped first: the explanatory comments name
    // the very idioms this forbids.
    const code = fs.readFileSync(EDITOR_PATH, 'utf8')
        .split('\n')
        .filter((line) => !/^\s*\/\//.test(line))
        .join('\n');

    it.each(['contentDocument', 'contentWindow.document', 'contentWindow.scrollTo', 'contentWindow.pageYOffset', 'DOMParser'])(
        'does not use %s',
        (idiom) => {
            expect(code).not.toContain(idiom);
        }
    );

    it('checks the message sender, never the origin', () => {
        expect(code).toContain('event.source !== frame.contentWindow');
        expect(code).not.toMatch(/event\.origin/);
    });
});
