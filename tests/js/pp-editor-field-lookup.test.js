/**
 * Field-lookup pins for the accordion editor's form-sync path.
 *
 * syncAccordionToJson reads every edited value back out of the DOM by matching
 * a component index and a field name. Field names are raw composition keys —
 * buildAccordionData passes Object.keys(props) straight through as `field.name`
 * for any prop the schema does not declare — so a name can contain characters
 * that are structural in a CSS selector: quotes, brackets, backslashes, dots.
 *
 * These boot the REAL assets/js/pp-admin-editor.js under jsdom and drive a real
 * edit through the real debounced sync, then assert on the JSON the editor
 * actually serialized. That matters here specifically: the sibling
 * pp-editor-dom.test.js re-implements the lookup in its own test body, and a
 * re-implementation stays green while the shipped lookup regresses.
 *
 *   edit an <input> ──trigger('input')──> syncAccordionToJson (300ms debounce)
 *                                              │
 *                                     cm.setValue(serialized JSON)
 *                                              │
 *                                      assert the round-trip
 *
 * Every field name below is built as a JS string constant and put into the
 * composition with JSON.stringify, then asserted against that same constant.
 * Nothing is hand-written as JSON text, so a key is always exactly the
 * characters the constant holds — no double-escaping drift between the
 * composition, the DOM, and the expectation.
 *
 * @vitest-environment jsdom
 */

const path = require('path');

const LOGIC_PATH  = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-editor-logic.js');
const EDITOR_PATH = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-admin-editor.js');

// ─── Field names with selector-significant characters ───────────────────────
//
// Written as JS constants so the exact character content is unambiguous:
//   QUOTE_NAME     contains a double quote  — closes a ["..."] attribute value
//   BRACKET_NAME   contains ] and .         — closes the attribute, then reads
//                                             as a class combinator
//   BACKSLASH_NAME contains ONE backslash   — a CSS escape introducer
const QUOTE_NAME     = 'he"adline';
const BRACKET_NAME   = 'a]b.c';
const BACKSLASH_NAME = 'back\\slash';

const ARRAY_NAME   = 'ro]ws" x';
const SUBKEY_QUOTE = 'la"bel';
const SUBKEY_DOT   = 'te]xt.body';

/** A registry whose schema declares an array prop and sub-keys with such names. */
const REGISTRY = [
    {
        name: 'widget',
        templateOwned: false,
        schema: {
            props: {
                title: { type: 'string', required: true, description: 'Headline' },
                [ARRAY_NAME]: {
                    type: 'array', required: true,
                    items: {
                        [SUBKEY_QUOTE]: { type: 'string', required: true },
                        [SUBKEY_DOT]:   { type: 'string', required: false },
                    },
                },
            },
        },
    },
];

/** The markup pp-admin-editor.js expects to find on the page. */
function installDom() {
    document.body.innerHTML = [
        '<div id="pp-error-bar"></div>',
        '<div id="pp-save-status"></div>',
        '<div id="pp-preview-status"></div>',
        '<div id="pp-accordion-live"></div>',
        '<div id="pp-accordion-view"></div>',
        '<div id="pp-json-view"></div>',
        '<button id="pp-view-toggle">JSON</button>',
        '<div class="pp-pane pp-pane--editor">',
        '<div class="pp-pane-header"></div>',
        '<div class="pp-pane-body">',
        '<textarea id="pp-composition-editor"></textarea>',
        '</div></div>',
        '<iframe id="pp-preview-frame"></iframe>',
    ].join('');
}

/**
 * Boot the real editor over `json` with every card expanded.
 * Returns the jQuery handle, plus a reader for the editor's current buffer.
 */
async function bootEditor(json, components) {
    installDom();

    const jquery = require('jquery');
    global.jQuery = jquery;
    global.$ = jquery;

    // The sync path ends in runPreview(), which POSTs to admin-ajax. Stub it to
    // a chainable no-op so the test never opens a socket and never depends on
    // jsdom's XHR behaviour for a relative URL.
    const chainable = { done() { return this; }, fail() { return this; }, always() { return this; } };
    jquery.post = () => chainable;

    let buffer = json;
    const cm = {
        getValue:  () => buffer,
        setValue:  (v) => { buffer = v; },
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
        components: components || REGISTRY,
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
    if (document.querySelector('.pp-serialization-error')) {
        throw new Error('the serialization-invariant gate blocked the accordion; this fixture cannot exercise sync');
    }

    jquery('#pp-accordion-view .pp-accordion-toggle').each(function () {
        if (jquery(this).attr('aria-expanded') === 'false') jquery(this).trigger('click');
        // The toggle resolves its panel through the id in aria-controls. Assert
        // the panel actually opened: if that lookup ever returned an empty set the
        // expand would silently no-op, and every absence-style assertion below
        // would still pass against a collapsed accordion.
        const panel = document.getElementById(jquery(this).attr('aria-controls'));
        if (!panel) throw new Error('toggle aria-controls resolved to no element');
        if (panel.getAttribute('aria-hidden') === 'true') {
            throw new Error('toggle did not reveal its panel');
        }
    });

    return { $: jquery, getBuffer: () => buffer };
}

/**
 * Find the single control carrying `fieldName`, without going through a
 * selector — the test must not depend on the very construction it is pinning.
 */
function control($, fieldName, $scope) {
    return ($scope || $('#pp-accordion-view')).find('input, textarea, select').filter(function () {
        return $(this).attr('data-field') === fieldName;
    });
}

/**
 * Edit a control and wait for the debounced sync to publish a new buffer.
 * Bounded: a sync that never lands fails here rather than hanging the suite.
 */
async function editAndSync($, $input, value, getBuffer) {
    const before = getBuffer();
    $input.val(value).trigger('input');
    for (let i = 0; i < 200; i++) {
        if (getBuffer() !== before) return JSON.parse(getBuffer());
        await new Promise((resolve) => setTimeout(resolve, 10));
    }
    throw new Error('sync never published a new buffer within the bound');
}

afterEach(() => {
    const jquery = require('jquery');
    jquery(document).off();
    jquery(window).off();

    // Cancel any debounce still in flight. syncAccordionToJson is on a 300ms
    // timer, so a test that returns before its timer fires would otherwise leave
    // it to land during the NEXT test, against that test's DOM but the previous
    // boot's closure — order-dependent flake that only shows up once some test
    // stops changing the buffer. The editor keeps its timer handles private, so
    // sweep the id space instead; ids are small integers in jsdom.
    const highest = setTimeout(function () {}, 0);
    for (let id = 0; id <= highest; id++) clearTimeout(id);

    document.body.innerHTML = '';
    delete global.ppAdminEditor; delete window.ppAdminEditor;
    delete global.wp;            delete window.wp;
    delete global.jQuery;        delete global.$;
});

// ─── Scalar fields ──────────────────────────────────────────────────────────

describe('form sync resolves scalar fields whose names contain selector-significant characters', () => {
    function scalarFixture() {
        return JSON.stringify([
            {
                component: 'widget',
                props: {
                    title: 'Original title',
                    [ARRAY_NAME]: [{ [SUBKEY_QUOTE]: 'a', [SUBKEY_DOT]: 'b' }],
                    [QUOTE_NAME]: 'quote original',
                    [BRACKET_NAME]: 'bracket original',
                    [BACKSLASH_NAME]: 'backslash original',
                },
            },
        ]);
    }

    it('writes an edit back under a name containing a double quote', async () => {
        const { $, getBuffer } = await bootEditor(scalarFixture());
        const $input = control($, QUOTE_NAME);
        expect($input.length).toBe(1);

        const parsed = await editAndSync($, $input, 'quote edited', getBuffer);
        expect(parsed[0].props[QUOTE_NAME]).toBe('quote edited');
    });

    it('writes an edit back under a name containing a bracket and a dot', async () => {
        const { $, getBuffer } = await bootEditor(scalarFixture());
        const $input = control($, BRACKET_NAME);
        expect($input.length).toBe(1);

        const parsed = await editAndSync($, $input, 'bracket edited', getBuffer);
        expect(parsed[0].props[BRACKET_NAME]).toBe('bracket edited');
    });

    it('writes an edit back under a name containing a backslash', async () => {
        const { $, getBuffer } = await bootEditor(scalarFixture());
        const $input = control($, BACKSLASH_NAME);
        expect($input.length).toBe(1);

        // Guard the constant itself: a mis-escaped literal would silently make
        // this a test about a two-character name that no longer contains \.
        expect(BACKSLASH_NAME).toContain('\\');
        expect(BACKSLASH_NAME.length).toBe('backslash'.length + 1);

        const parsed = await editAndSync($, $input, 'backslash edited', getBuffer);
        expect(parsed[0].props[BACKSLASH_NAME]).toBe('backslash edited');
    });

    it('leaves every sibling field untouched when one is edited', async () => {
        const { $, getBuffer } = await bootEditor(scalarFixture());
        const parsed = await editAndSync($, control($, QUOTE_NAME), 'only this one', getBuffer);

        expect(parsed[0].props[QUOTE_NAME]).toBe('only this one');
        expect(parsed[0].props.title).toBe('Original title');
        expect(parsed[0].props[BRACKET_NAME]).toBe('bracket original');
        expect(parsed[0].props[BACKSLASH_NAME]).toBe('backslash original');
        expect(parsed[0].props[ARRAY_NAME]).toEqual([{ [SUBKEY_QUOTE]: 'a', [SUBKEY_DOT]: 'b' }]);
    });

    it('leaves the field alone when its control does not resolve', async () => {
        const { $, getBuffer } = await bootEditor(scalarFixture());
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        // Take the quote-named control out of reach, then drive the sync from a
        // different field so the pass still runs over the unreachable one.
        control($, QUOTE_NAME).removeAttr('data-field');

        const parsed = await editAndSync($, control($, 'title'), 'title edited', getBuffer);

        expect(parsed[0].props.title).toBe('title edited');
        expect(parsed[0].props[QUOTE_NAME]).toBe('quote original');
        expect(warn).toHaveBeenCalledWith(expect.stringContaining('no control resolved'));
        warn.mockRestore();
    });

    it('keeps two components with the same field name independent', async () => {
        // The lookup matches on component index as well as field name. If the
        // index half regressed, editing component 1 would also rewrite component 0.
        const json = JSON.stringify([
            { component: 'widget', props: { title: 'first', [ARRAY_NAME]: [], [QUOTE_NAME]: 'comp zero' } },
            { component: 'widget', props: { title: 'second', [ARRAY_NAME]: [], [QUOTE_NAME]: 'comp one' } },
        ]);
        const { $, getBuffer } = await bootEditor(json);

        const $cards = $('#pp-accordion-view .pp-accordion-card');
        expect($cards.length).toBe(2);
        const $second = control($, QUOTE_NAME, $cards.eq(1));
        expect($second.length).toBe(1);

        const parsed = await editAndSync($, $second, 'comp one edited', getBuffer);
        expect(parsed[1].props[QUOTE_NAME]).toBe('comp one edited');
        expect(parsed[0].props[QUOTE_NAME]).toBe('comp zero');
    });
});

// ─── Array fields ───────────────────────────────────────────────────────────

describe('form sync resolves array fields and sub-keys whose names contain selector-significant characters', () => {
    function arrayFixture() {
        return JSON.stringify([
            {
                component: 'widget',
                props: {
                    title: 'Original title',
                    [ARRAY_NAME]: [
                        { [SUBKEY_QUOTE]: 'row one label', [SUBKEY_DOT]: 'row one text' },
                        { [SUBKEY_QUOTE]: 'row two label', [SUBKEY_DOT]: 'row two text' },
                    ],
                },
            },
        ]);
    }

    it('renders one row per item under an array prop with such a name', async () => {
        const { $ } = await bootEditor(arrayFixture());
        expect($('#pp-accordion-view .pp-accordion-array-item').length).toBe(2);
    });

    it('writes a sub-key edit back into the right row and leaves the others alone', async () => {
        const { $, getBuffer } = await bootEditor(arrayFixture());

        const $rows = $('#pp-accordion-view .pp-accordion-array-item');
        const $target = control($, SUBKEY_QUOTE, $rows.eq(1));
        expect($target.length).toBe(1);

        const parsed = await editAndSync($, $target, 'row two edited', getBuffer);
        expect(parsed[0].props[ARRAY_NAME]).toEqual([
            { [SUBKEY_QUOTE]: 'row one label', [SUBKEY_DOT]: 'row one text' },
            { [SUBKEY_QUOTE]: 'row two edited', [SUBKEY_DOT]: 'row two text' },
        ]);
    });

    it('writes an edit back under a sub-key containing a bracket and a dot', async () => {
        const { $, getBuffer } = await bootEditor(arrayFixture());

        const $rows = $('#pp-accordion-view .pp-accordion-array-item');
        const $target = control($, SUBKEY_DOT, $rows.eq(0));
        expect($target.length).toBe(1);

        const parsed = await editAndSync($, $target, 'dotted sub-key edited', getBuffer);
        expect(parsed[0].props[ARRAY_NAME][0][SUBKEY_DOT]).toBe('dotted sub-key edited');
        expect(parsed[0].props[ARRAY_NAME][0][SUBKEY_QUOTE]).toBe('row one label');
    });

    // A row lookup and a container lookup can each come up empty, and they reach
    // the sync as DIFFERENT values: an unresolved row yields {} per row, an
    // unresolved container yields no rows at all. Both are pinned, because a test
    // for one says nothing about the other.

    it('keeps the existing array guard reachable when a row lookup resolves nothing', async () => {
        // Rows that resolve nothing arrive as EMPTY OBJECTS, since
        // `if ($input.length)` never sets a key. Clearing the inputs instead would
        // produce {sk: ''}, which is an ordinary edit and deliberately not guarded
        // (see the partial-edit note in TODOS.md), so strip the attribute the
        // lookup matches on and let it resolve nothing.
        const { $, getBuffer } = await bootEditor(arrayFixture());

        const $rows = $('#pp-accordion-view .pp-accordion-array-item');
        $rows.find('input, textarea').removeAttr('data-field');

        const parsed = await editAndSync($, control($, 'title'), 'title edited', getBuffer);

        expect(parsed[0].props.title).toBe('title edited');
        expect(parsed[0].props[ARRAY_NAME]).toEqual([
            { [SUBKEY_QUOTE]: 'row one label', [SUBKEY_DOT]: 'row one text' },
            { [SUBKEY_QUOTE]: 'row two label', [SUBKEY_DOT]: 'row two text' },
        ]);
    });

    it('leaves the field alone when the array container does not resolve', async () => {
        // The container carries the prop name; its rows carry sub-keys. So the
        // container can fail to resolve while every row control is still present,
        // and the field then reads as zero rows — indistinguishable from an array
        // the author emptied unless the two are told apart at the lookup.
        const { $, getBuffer } = await bootEditor(arrayFixture());
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        $('#pp-accordion-view .pp-accordion-array').removeAttr('data-field');
        expect($('#pp-accordion-view .pp-accordion-array-item').length).toBe(2);

        const parsed = await editAndSync($, control($, 'title'), 'title edited', getBuffer);

        expect(parsed[0].props.title).toBe('title edited');
        expect(parsed[0].props[ARRAY_NAME]).toEqual([
            { [SUBKEY_QUOTE]: 'row one label', [SUBKEY_DOT]: 'row one text' },
            { [SUBKEY_QUOTE]: 'row two label', [SUBKEY_DOT]: 'row two text' },
        ]);
        expect(warn).toHaveBeenCalledWith(expect.stringContaining('no array container resolved'));
        warn.mockRestore();
    });

    it('does not read the array container or its buttons as a field value', async () => {
        // buildArrayFieldHtml stamps data-comp/data-field onto the array
        // container, the per-row remove button and the add button as well as the
        // inputs. Reading one of those for a value would serialize the array
        // prop as a string and destroy every row.
        const { $, getBuffer } = await bootEditor(arrayFixture());

        const $tagged = $('#pp-accordion-view').find('[data-field]').filter(function () {
            return $(this).attr('data-field') === ARRAY_NAME;
        });
        expect($tagged.length).toBeGreaterThan(1); // container + add + per-row remove

        const parsed = await editAndSync($, control($, 'title'), 'title edited', getBuffer);
        expect(Array.isArray(parsed[0].props[ARRAY_NAME])).toBe(true);
        expect(parsed[0].props[ARRAY_NAME]).toHaveLength(2);
    });
});

// ─── Ordinary names are unaffected ──────────────────────────────────────────

describe('ordinary field names round-trip unchanged', () => {
    it('writes an edit back under a plain name', async () => {
        const json = JSON.stringify([
            { component: 'widget', props: { title: 'before', [ARRAY_NAME]: [] } },
        ]);
        const { $, getBuffer } = await bootEditor(json);

        const parsed = await editAndSync($, control($, 'title'), 'after', getBuffer);
        expect(parsed[0].props.title).toBe('after');
    });
});

// ─── Source tripwires ───────────────────────────────────────────────────────
//
// Everything above drives the shipped file, so it already fails on a
// regression. These add a second, cheaper signal aimed at the specific shape
// that caused the problem, so a future edit that reintroduces it is named
// rather than merely red.

describe('the shipped editor builds no selector from a field name', () => {
    const fs = require('fs');
    const src = fs.readFileSync(EDITOR_PATH, 'utf-8');

    it('interpolates no dynamic value into a data-field or data-comp selector', () => {
        // Keyed on the SHAPE, not on today's spacing and quote choice: an
        // attribute selector for data-field/data-comp that reaches a `+` before
        // closing its bracket is building the value by concatenation, however it
        // is quoted. `data-comp-idx` is deliberately not matched — the `-` after
        // `comp` fails the `=`, and that site is a bounds-checked loop index.
        const concatenated = /\[data-(?:field|comp)\s*=[^\]]*\+/;
        expect(src).not.toMatch(concatenated);

        // And the shape the regex is standing in for is genuinely absent.
        expect(src).not.toContain('[data-field="\' +');
        expect(src).not.toContain('[data-comp="\' +');
    });

    it('routes the sync lookups through the attribute-comparing helper', () => {
        expect(src).toContain('function findByCompField(');
        // A floor, not an exact count: a future lookup that adopts the helper is
        // the intended outcome and must not turn this red. The definition plus
        // the three sync call sites (array container, sub-input, scalar input).
        expect(src.match(/findByCompField\(/g).length).toBeGreaterThanOrEqual(4);
        expect(src).toContain("findByCompField($scope, compIdx, field.name, '.pp-accordion-array')");
        expect(src).toContain('findByCompField($item, compIdx, sk, FIELD_CONTROLS)');
        expect(src).toContain('findByCompField($scope, compIdx, field.name, FIELD_CONTROLS)');
    });

    it('keeps the array data-loss guard', () => {
        expect(src).toContain('logic.wouldLoseArrayData(items, field.value)');
        expect(src).toContain('data-loss guard fired');
    });
});
