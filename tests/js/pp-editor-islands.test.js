/**
 * The custom band in the accordion editor (#1242 T5, LAYER-3-CONTRACT.md §7.2 and P-7).
 *
 *   - `markup` is STRUCTURAL (schema `structural_only: true`): shown, never a control, and
 *     never rewritten by a sync. Only the JSON view (a structural write) edits it.
 *   - `islands` is a name -> string MAP: one text box per island, listing every stored
 *     island plus every island the markup names. A box edit merges by key, so siblings
 *     are kept, and an untouched empty box adds nothing.
 *   - A non-string island routes the composition to JSON-only mode (the #745/#805 family).
 *
 * Boots the REAL assets/js/pp-admin-editor.js under jsdom with the SHIPPED custom schema,
 * and asserts on the JSON the editor serialized.
 *
 * @vitest-environment jsdom
 */

const path = require('path');
const fs = require('fs');

const LOGIC_PATH  = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-editor-logic.js');
const EDITOR_PATH = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-admin-editor.js');
const SCHEMA = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'components', 'custom', 'schema.json'), 'utf8'));
const REGISTRY = [{ name: 'custom', templateOwned: false, schema: SCHEMA }];

const logic = require(LOGIC_PATH);

const MARKUP = '<div><h2 data-pp-island="title" data-pp-island-kind="inline"></h2><p data-pp-island="lede"></p><p data-pp-island=\'note\'></p></div>';
const BAND = (islands) => JSON.stringify([{ component: 'custom', props: { markup: MARKUP, islands } }], null, 2);

function installDom() {
    // jsdom implements no layout, so it ships no scrollIntoView. The insert
    // handler scrolls the new card into view from a timer, which would otherwise
    // throw AFTER the test that triggered it had already returned — an unhandled
    // rejection attributed to whichever test happened to be running next.
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
        '<div class="pp-pane pp-pane--editor">',
        '<div class="pp-pane-header"></div>',
        '<div class="pp-pane-body">',
        '<textarea id="pp-composition-editor"></textarea>',
        '</div></div>',
        '<iframe id="pp-preview-frame"></iframe>',
    ].join('');
}

async function bootEditor(json, components, opts) {
    opts = opts || {};
    installDom();

    const jquery = require('jquery');
    global.jQuery = jquery;
    global.$ = jquery;

    // The sync path ends in runPreview(), which POSTs to admin-ajax. Stub it to
    // a chainable no-op so the test never opens a socket.
    //
    // The handlers are recorded but never invoked, which is deliberate: the save
    // path's .done() runs cm.setValue(res.data.composition) on the server's
    // normalized reply, so a stub that resolved would rewrite the buffer for
    // reasons unrelated to the flush and make "did the flush settle the edit?"
    // unanswerable from the buffer. Leaving the request pending isolates the
    // question to what was actually POSTed.
    const posts = [];
    const chainable = { done() { return this; }, fail() { return this; }, always() { return this; } };
    jquery.post = (url, data) => { posts.push(data); return chainable; };

    let buffer = json;
    // Counting writes, not just reading the final buffer: a sync that runs a
    // second time usually lands on the same text, so the buffer alone cannot
    // tell one settled sync from two.
    let writes = 0;
    const cm = {
        getValue:  () => buffer,
        setValue:  (v) => { writes += 1; buffer = v; },
        getRange:  () => '',
        getCursor: () => ({ line: 0, ch: 0 }),
        on:        () => {},
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
        components,
        ajaxUrl: '/wp-admin/admin-ajax.php',
        nonce: 'test-nonce',
        postId: 1,
        postStatus: 'draft',
        compositionVersion: 1,
        codeEditorSettings: { codemirror: {} },
    };

    // Both files are IIFEs whose whole effect is the side effect of running, and
    // they sit in Node's CJS require.cache, which vi.resetModules() does not
    // clear. Without deleting the cache entry the second and later boots in this
    // file silently no-op and the accordion never renders.
    delete require.cache[require.resolve(LOGIC_PATH)];
    delete require.cache[require.resolve(EDITOR_PATH)];
    require(LOGIC_PATH);
    require(EDITOR_PATH);

    // The boot block runs from jQuery's document-ready queue, which dispatches
    // asynchronously even on a complete document. Poll for the observable
    // result, bounded so a genuine boot failure fails fast instead of hanging.
    let settled = false;
    for (let i = 0; i < 50; i++) {
        const rendered = document.getElementById('pp-accordion-view').innerHTML !== '';
        const blocked  = document.querySelector('.pp-serialization-error') !== null;
        if (rendered || blocked) { settled = true; break; }
        await new Promise((resolve) => setTimeout(resolve, 1));
    }
    if (!settled) {
        throw new Error('editor never booted: #pp-accordion-view is empty and no serialization notice was posted');
    }
    if (document.querySelector('.pp-serialization-error') && !opts.allowBlocked) {
        throw new Error('the serialization-invariant gate blocked the accordion; this fixture cannot exercise sync');
    }

    jquery('#pp-accordion-view .pp-accordion-toggle').each(function () {
        if (jquery(this).attr('aria-expanded') === 'false') jquery(this).trigger('click');
        const panel = document.getElementById(jquery(this).attr('aria-controls'));
        if (!panel) throw new Error('toggle aria-controls resolved to no element');
        if (panel.getAttribute('aria-hidden') === 'true') {
            throw new Error('toggle did not reveal its panel');
        }
    });

    /** Every composition-write POST, in order — preview traffic filtered out. */
    const savePosts = () => posts.filter((p) =>
        p.action === 'pp_save_composition' || p.action === 'pp_publish_page');

    return { $: jquery, getBuffer: () => buffer, getWrites: () => writes, savePosts };
}

async function bootBlocked(json, components) {
    const { $ } = await bootEditor(json, components, { allowBlocked: true });
    const $notice = $('.pp-serialization-error');
    if (!$notice.length) throw new Error('expected the invariant gate to block this fixture, but it rendered');
    return {
        $,
        // The accordion must be gone and the JSON pane showing — the author has to
        // land in the editor that can actually fix the value.
        accordionHidden: $('#pp-accordion-view').css('display') === 'none',
        jsonShown:       $('#pp-json-view').css('display') !== 'none',
        toggleHidden:    $('#pp-view-toggle').css('display') === 'none',
        paths: $notice.find('tbody tr td:nth-child(2) code').map(function () {
            return $(this).text();
        }).get(),
        // Built with toArray/Array#map rather than jQuery's .map().get(): jQuery
        // FLATTENS an array returned from its callback, which would collapse the
        // rows into one undifferentiated list of cells.
        rows: $notice.find('tbody tr').toArray().map((tr) =>
            $(tr).find('td').toArray().map((td) => $(td).text())),
    };
}

async function editAndSync($, $input, value, getBuffer) {
    const before = getBuffer();
    $input.val(value).trigger('input');
    for (let i = 0; i < 200; i++) {
        if (getBuffer() !== before) return JSON.parse(getBuffer());
        await new Promise((resolve) => setTimeout(resolve, 10));
    }
    throw new Error('sync never published a new buffer within the bound');
}

const box = ($, key) => $('#pp-accordion-view textarea[data-map-key="' + key + '"]');

afterEach(() => {
    // Unconditionally, not at the end of each test body: a console spy left in
    // place by a failing assertion would silence warnings for every test after
    // it, turning one failure into a run whose later results cannot be trusted.
    vi.restoreAllMocks();

    const jquery = require('jquery');
    jquery(document).off();
    jquery(window).off();

    // Cancel any debounce still in flight. A test that returns before its timer
    // fires would otherwise leave it to land during the NEXT test, against that
    // test's DOM but the previous boot's closure — order-dependent flake that
    // only shows up once some test stops changing the buffer. The editor keeps
    // its timer handles private, so sweep the id space instead; ids are small
    // integers in jsdom.
    const highest = setTimeout(function () {}, 0);
    for (let id = 0; id <= highest; id++) clearTimeout(id);

    document.body.innerHTML = '';
    delete global.ppAdminEditor; delete window.ppAdminEditor;
    delete global.wp;            delete window.wp;
    delete global.jQuery;        delete global.$;
});

describe('custom band logic helpers', () => {
    test('islandNamesInMarkup reads every quoting form, in order, once, through the name grammar', () => {
        expect(logic.islandNamesInMarkup(MARKUP)).toEqual(['title', 'lede', 'note']);
        expect(logic.islandNamesInMarkup('<p DATA-PP-ISLAND=a></p><p data-pp-island="a"></p><p data-pp-island="Bad"></p><p data-pp-island-kind="x"></p>'))
            .toEqual(['a']);
        expect(logic.islandNamesInMarkup(null)).toEqual([]);
    });

    test('mergeMapRead: a box replaces its key, unboxed keys are kept, an untouched empty box adds nothing', () => {
        expect(logic.mergeMapRead({ a: '1', z: 'kept' }, { a: '2', b: '' })).toEqual({ a: '2', z: 'kept' });
        expect(logic.mergeMapRead({ a: '1' }, { a: '' })).toEqual({ a: '' });
        expect(logic.mergeMapRead('bad', { b: 'B' })).toEqual({ b: 'B' });
    });

    test('the shipped schema makes markup structural and islands a map', () => {
        const data = logic.buildAccordionData(BAND({ title: 'T' }), REGISTRY);
        const byName = Object.fromEntries(data.components[0].fields.map((f) => [f.name, f]));
        expect(byName.markup.structural).toBe(true);
        expect(byName.islands.type).toBe('map');
        expect(byName.id.structural).toBe(false);
    });

    test('a well-formed band round-trips; a non-string island is drift', () => {
        expect(logic.checkSerializationInvariant(BAND({ title: 'T', lede: 'L' }), REGISTRY).safe).toBe(true);
        const bad = logic.checkSerializationInvariant(BAND({ title: ['x'] }), REGISTRY);
        expect(bad.safe).toBe(false);
        expect(bad.diffs.map((d) => d.path)).toContain('[0].props.islands.title');
        expect(logic.checkSerializationInvariant(JSON.stringify([{ component: 'custom', props: { markup: MARKUP, islands: 'x' } }]), REGISTRY).safe).toBe(false);
    });
});

describe('the accordion island editor', () => {
    test('in a browser the island list is read from the parsed markup, not its text', () => {
        // jsdom provides DOMParser: a name in a comment, in attribute text or inside SVG is
        // not an island, so no box is offered for it.
        expect(logic.islandNamesInMarkup('<!-- <p data-pp-island="c"></p> --><p title=\' data-pp-island="t"\'></p>'
            + '<svg><g data-pp-island="s"></g></svg><p data-pp-island="real"></p>')).toEqual(['real']);
    });

    test('markup is shown without a control, and each island gets a box (stored and markup-declared)', async () => {
        const { $ } = await bootEditor(BAND({ title: 'Hello' }), REGISTRY);
        const $pre = $('#pp-accordion-view .pp-accordion-structural');
        expect($pre.length).toBe(1);
        expect($pre.text()).toBe(MARKUP);
        expect($('#pp-accordion-view [data-field="markup"]').length).toBe(0);
        expect(box($, 'title').val()).toBe('Hello');
        expect(box($, 'lede').val()).toBe('');
        expect(box($, 'note').length).toBe(1);
    });

    test('editing one island merges by key: the sibling survives and markup is byte-identical', async () => {
        const { $, getBuffer } = await bootEditor(BAND({ title: 'Hello', lede: 'Keep <em>me</em>' }), REGISTRY);
        const out = await editAndSync($, box($, 'title'), 'Changed', getBuffer);
        expect(out[0].props.islands).toEqual({ title: 'Changed', lede: 'Keep <em>me</em>' });
        expect(out[0].props.markup).toBe(MARKUP);
    });

    test('typing into an empty island adds it; the untouched empty boxes add nothing', async () => {
        const { $, getBuffer } = await bootEditor(BAND({ title: 'Hello' }), REGISTRY);
        const out = await editAndSync($, box($, 'lede'), 'New lede', getBuffer);
        expect(out[0].props.islands).toEqual({ title: 'Hello', lede: 'New lede' });
        expect(Object.keys(out[0].props.islands)).not.toContain('note');
    });

    test('a non-string island routes the page to JSON-only mode', async () => {
        const blocked = await bootBlocked(BAND({ title: 42 }), REGISTRY);
        expect(blocked.paths).toContain('[0].props.islands.title');
        expect(blocked.jsonShown).toBe(true);
    });
});
