/**
 * Form-sync robustness pins for the accordion editor.
 *
 * Three properties of the accordion <-> JSON sync are pinned here:
 *
 *   1. FIELD RESOLUTION   a top-level scalar prop resolves to the card's own
 *                         control, not to an array row's sub-field control that
 *                         happens to carry the same name.
 *   2. VALUE ROUND-TRIP   a stored value renders as itself and reads back as
 *                         itself. Numeric and boolean values are the interesting
 *                         case: they are falsy, and a falsy-based default would
 *                         render them as empty.
 *   3. PENDING EDITS      a field edit made inside the sync's debounce window
 *                         survives a structural row operation (move/delete/add).
 *
 * Every test boots the REAL assets/js/pp-admin-editor.js under jsdom and drives
 * real events through the real debounced sync, then asserts on the JSON the
 * editor actually serialized. The sibling pp-editor-dom.test.js re-implements
 * parts of the lookup in its own test body; a re-implementation stays green
 * while the shipped code regresses, so no ASSERTION here is made against a
 * re-implementation. The `scalarControl` locator below does repeat the
 * array-descendant rule, because a test has to pick a control without going
 * through the construction it is pinning — but it only chooses what to type
 * into. What is asserted is always the JSON the shipped editor produced.
 *
 *   edit a control ──trigger('input')──> syncAccordionToJson (300ms debounce)
 *                                              │
 *                                     cm.setValue(serialized JSON)
 *                                              │
 *                                       assert the round-trip
 *
 * @vitest-environment jsdom
 */

const path = require('path');

const LOGIC_PATH  = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-editor-logic.js');
const EDITOR_PATH = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-admin-editor.js');

const { wouldLoseArrayData } = require(LOGIC_PATH);

// ─── Registries ─────────────────────────────────────────────────────────────
//
// `card` mirrors the shape components/grid/schema.json ships: a top-level
// `title` prop AND an `items[].title` sub-key. Both controls end up carrying
// data-comp="0" data-field="title", so the pair is the collision under test.
// `subheading` is declared but left out of every fixture below — it is the
// probe for a sync that ran when it should not have, since a sync marks every
// control it resolves as touched and would add the prop to the output.

const SCALAR_FIRST = [
    {
        name: 'card',
        templateOwned: false,
        schema: {
            props: {
                title:      { type: 'string', required: false, description: 'Heading' },
                subheading: { type: 'string', required: false, description: 'Supporting line' },
                items: {
                    type: 'array', required: false,
                    items: {
                        title: { type: 'string', required: false },
                        body:  { type: 'string', required: false },
                    },
                },
            },
        },
    },
];

/**
 * The same collision with the array declared FIRST. Nothing but key order
 * differs, which is exactly the point: declaration order decides document
 * order, and resolution must not depend on it.
 */
const ARRAY_FIRST = [
    {
        name: 'card',
        templateOwned: false,
        schema: {
            props: {
                items: {
                    type: 'array', required: false,
                    items: {
                        title: { type: 'string', required: false },
                        body:  { type: 'string', required: false },
                    },
                },
                title:      { type: 'string', required: false, description: 'Heading' },
                subheading: { type: 'string', required: false, description: 'Supporting line' },
            },
        },
    },
];

/** A component with a multiline field, to cover the <textarea> render branch. */
const MULTILINE_REGISTRY = [
    {
        name: 'card',
        templateOwned: false,
        schema: {
            props: {
                title: { type: 'string', required: false },
                body:  { type: 'string', required: false },
            },
        },
    },
];

// ─── Harness ────────────────────────────────────────────────────────────────

/** The markup pp-admin-editor.js expects to find on the page. */
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
 * Returns the jQuery handle plus a reader for the editor's current buffer.
 */
async function bootEditor(json, components) {
    installDom();

    const jquery = require('jquery');
    global.jQuery = jquery;
    global.$ = jquery;

    // The sync path ends in runPreview(), which POSTs to admin-ajax. Stub it to
    // a chainable no-op so the test never opens a socket.
    const chainable = { done() { return this; }, fail() { return this; }, always() { return this; } };
    jquery.post = () => chainable;

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
    if (document.querySelector('.pp-serialization-error')) {
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

    return { $: jquery, getBuffer: () => buffer, getWrites: () => writes };
}

/**
 * Controls carrying `fieldName`, without going through a selector — the test
 * must not depend on the very construction it is pinning.
 */
function controls($, fieldName, $scope) {
    return ($scope || $('#pp-accordion-view')).find('input, textarea, select').filter(function () {
        return $(this).attr('data-field') === fieldName;
    });
}

/** The scalar control: the one carrying `fieldName` outside any array row. */
function scalarControl($, fieldName, $scope) {
    return controls($, fieldName, $scope).filter(function () {
        return !this.closest('.pp-accordion-array');
    });
}

/** The control for `subKey` inside array row `rowIdx`. */
function rowControl($, subKey, rowIdx) {
    const $row = $('#pp-accordion-view .pp-accordion-array-item').eq(rowIdx);
    return controls($, subKey, $row);
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

/**
 * Type into a control and IMMEDIATELY run `act`, without waiting — the edit is
 * still inside the 300ms debounce window when `act` fires. Then let every timer
 * drain so a trailing-edge sync, if one were still scheduled, would land before
 * the assertion reads the buffer.
 */
async function editThen($input, value, act) {
    $input.val(value).trigger('input');
    act();
    await new Promise((resolve) => setTimeout(resolve, 600));
}

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

// ─── 1. Field resolution for nested rows ────────────────────────────────────

describe('a top-level prop and an array sub-key of the same name resolve independently', () => {
    const FIXTURE = JSON.stringify([
        {
            component: 'card',
            props: {
                title: 'Section heading',
                items: [
                    { title: 'Row one', body: 'First body' },
                    { title: 'Row two', body: 'Second body' },
                ],
            },
        },
    ]);

    // Both orders are asserted with the SAME expectations. That pairing is the
    // test: if resolution still depended on document order, exactly one of them
    // would fail.
    const ORDERS = [
        ['prop declared before the array', SCALAR_FIRST],
        ['prop declared after the array',  ARRAY_FIRST],
    ];

    ORDERS.forEach(([label, registry]) => {
        describe(label, () => {
            it('gives the name to more than one control, so the pair is genuinely ambiguous', async () => {
                const { $ } = await bootEditor(FIXTURE, registry);
                // Guards the fixture itself: if the markup ever stopped emitting a
                // shared name, every assertion below would pass vacuously.
                expect(controls($, 'title').length).toBe(3);
                expect(scalarControl($, 'title').length).toBe(1);
            });

            it('writes an edit of the top-level prop to the top-level prop', async () => {
                const { $, getBuffer } = await bootEditor(FIXTURE, registry);
                const parsed = await editAndSync($, scalarControl($, 'title'), 'Heading edited', getBuffer);

                expect(parsed[0].props.title).toBe('Heading edited');
                expect(parsed[0].props.items[0].title).toBe('Row one');
                expect(parsed[0].props.items[1].title).toBe('Row two');
            });

            it('writes an edit of a row sub-field to that row only', async () => {
                const { $, getBuffer } = await bootEditor(FIXTURE, registry);
                const parsed = await editAndSync($, rowControl($, 'title', 0), 'Row one edited', getBuffer);

                expect(parsed[0].props.items[0].title).toBe('Row one edited');
                expect(parsed[0].props.items[1].title).toBe('Row two');
                expect(parsed[0].props.title).toBe('Section heading');
            });

            it('leaves the top-level prop alone when a row sub-field drives the sync', async () => {
                const { $, getBuffer } = await bootEditor(FIXTURE, registry);
                const parsed = await editAndSync($, rowControl($, 'body', 1), 'Second body edited', getBuffer);

                expect(parsed[0].props.title).toBe('Section heading');
                expect(parsed[0].props.items[1].body).toBe('Second body edited');
            });
        });
    });

    it('leaves the prop alone when only a row carries its name', async () => {
        // `body` is a sub-key here and NOT a top-level prop, so the card has no
        // scalar control for it. The sync must report it unresolved rather than
        // adopt a row's value.
        const { $, getBuffer } = await bootEditor(FIXTURE, SCALAR_FIRST);
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        // Take the scalar title control out of reach, then drive the sync from a
        // row so the pass still runs over the now-unresolvable prop.
        scalarControl($, 'title').removeAttr('data-field');

        const parsed = await editAndSync($, rowControl($, 'body', 0), 'First body edited', getBuffer);

        expect(parsed[0].props.title).toBe('Section heading');
        expect(parsed[0].props.items[0].body).toBe('First body edited');
        expect(warn).toHaveBeenCalledWith(expect.stringContaining('no control resolved'));
    });
});

// ─── 2. Round-tripping numeric and boolean values ───────────────────────────

describe('a stored value renders as itself and reads back as itself', () => {
    function fixtureWithTitle(value) {
        return JSON.stringify([{ component: 'card', props: { title: value, items: [] } }]);
    }

    // 0 and false are the cases a falsy-based default silently blanks; '' and a
    // plain string are the controls that must not change.
    const VALUES = [
        ['the number zero',  0,       '0'],
        ['a nonzero number', 42,      '42'],
        ['boolean false',    false,   'false'],
        ['boolean true',     true,    'true'],
        ['the empty string', '',      ''],
        ['a plain string',   'Hello', 'Hello'],
    ];

    VALUES.forEach(([label, stored, rendered]) => {
        it('renders ' + label + ' into the field', async () => {
            const { $ } = await bootEditor(fixtureWithTitle(stored), SCALAR_FIRST);
            expect(scalarControl($, 'title').val()).toBe(rendered);
        });
    });

    it('renders an absent value as an empty field', async () => {
        const { $ } = await bootEditor(
            JSON.stringify([{ component: 'card', props: { items: [] } }]), SCALAR_FIRST);
        expect(scalarControl($, 'title').val()).toBe('');
    });

    it('renders a null value as an empty field', async () => {
        const { $ } = await bootEditor(fixtureWithTitle(null), SCALAR_FIRST);
        expect(scalarControl($, 'title').val()).toBe('');
    });

    it('keeps a zero when an unrelated field is edited', async () => {
        // The sync rewrites every resolved field, so a value that rendered blank
        // would be read back as '' and written over even though the user never
        // touched it. Editing a DIFFERENT field is what exposes that.
        const json = JSON.stringify([
            { component: 'card', props: { title: 0, subheading: 'Sub', items: [] } },
        ]);
        const { $, getBuffer } = await bootEditor(json, SCALAR_FIRST);
        const parsed = await editAndSync($, scalarControl($, 'subheading'), 'Sub edited', getBuffer);

        expect(parsed[0].props.subheading).toBe('Sub edited');
        expect(parsed[0].props.title).toBe('0');
    });

    it('keeps a false when an unrelated field is edited', async () => {
        const json = JSON.stringify([
            { component: 'card', props: { title: false, subheading: 'Sub', items: [] } },
        ]);
        const { $, getBuffer } = await bootEditor(json, SCALAR_FIRST);
        const parsed = await editAndSync($, scalarControl($, 'subheading'), 'Sub edited', getBuffer);

        expect(parsed[0].props.title).toBe('false');
    });

    it('renders a zero into a multiline field', async () => {
        const json = JSON.stringify([{ component: 'card', props: { title: 'T', body: 0 } }]);
        const { $ } = await bootEditor(json, MULTILINE_REGISTRY);

        const $body = scalarControl($, 'body');
        expect($body.prop('tagName')).toBe('TEXTAREA');
        expect($body.val()).toBe('0');
    });

    it('renders a zero into a row sub-field', async () => {
        const json = JSON.stringify([
            { component: 'card', props: { title: 'T', items: [{ title: 0, body: false }] } },
        ]);
        const { $ } = await bootEditor(json, SCALAR_FIRST);

        expect(rowControl($, 'title', 0).val()).toBe('0');
        expect(rowControl($, 'body', 0).val()).toBe('false');
    });

    it('keeps a zero in a row when an unrelated field is edited', async () => {
        // The render fix has to hold one level down too: row values are assembled
        // before the renderer sees them, so a falsy default applied there would
        // blank the value just as effectively, and the sync would read '' back.
        const json = JSON.stringify([
            { component: 'card', props: { title: 'Heading', items: [{ title: 0, body: 'B' }] } },
        ]);
        const { $, getBuffer } = await bootEditor(json, SCALAR_FIRST);
        const parsed = await editAndSync($, scalarControl($, 'title'), 'Heading edited', getBuffer);

        expect(parsed[0].props.title).toBe('Heading edited');
        expect(parsed[0].props.items[0].title).toBe('0');
        expect(parsed[0].props.items[0].body).toBe('B');
    });
});

// ─── 2b. The array guard, on originals that are not objects ─────────────────

describe('the array guard measures whether originals exist, not what type they are', () => {
    it('fires for object originals', () => {
        expect(wouldLoseArrayData([{}, {}], [{ title: 'a' }, { title: 'b' }])).toBe(true);
    });

    // Object.keys(1) and Object.keys(true) are both [], so an item-by-item
    // content test reads these originals as empty and stands the guard down.
    it('fires for numeric originals', () => {
        expect(wouldLoseArrayData([{}, {}, {}], [1, 2, 3])).toBe(true);
    });

    it('fires for boolean originals', () => {
        expect(wouldLoseArrayData([{}], [true])).toBe(true);
    });

    it('fires for zero and false, which are falsy as well as non-object', () => {
        expect(wouldLoseArrayData([{}, {}], [0, false])).toBe(true);
    });

    it('fires for string originals', () => {
        expect(wouldLoseArrayData([{}], ['a'])).toBe(true);
    });

    it('fires for null originals, which would otherwise become empty objects', () => {
        expect(wouldLoseArrayData([{}, {}], [null, null])).toBe(true);
    });

    it('fires for originals that were already empty objects', () => {
        // The guard no longer inspects the originals item by item, so this shape
        // now answers true where it once answered false. It costs nothing: the
        // skipped write and the performed write produce the same value.
        expect(wouldLoseArrayData([{}], [{}])).toBe(true);
        expect(wouldLoseArrayData([{}, {}], [{}, {}])).toBe(true);
    });

    it('stays down when the read produced content', () => {
        expect(wouldLoseArrayData([{ title: 'edited' }], [{ title: 'a' }])).toBe(false);
    });

    it('stays down when a partial read produced some content', () => {
        expect(wouldLoseArrayData([{ title: 'a' }, {}], [{ title: 'a' }, { title: 'b' }])).toBe(false);
    });

    it('stays down when there were no originals', () => {
        expect(wouldLoseArrayData([{}, {}], [])).toBe(false);
        expect(wouldLoseArrayData([{}, {}], undefined)).toBe(false);
    });

    it('stays down when every row was removed', () => {
        expect(wouldLoseArrayData([], [{ title: 'a' }])).toBe(false);
    });
});

describe('a pass-through array survives a sync of the component that holds it', () => {
    // No schema entry for `tags`, so it has no sub-key spec and its rows render
    // no controls — the DOM can only ever read them back as empty objects.
    const PASSTHROUGH = JSON.stringify([
        { component: 'card', props: { title: 'Heading', tags: [1, 2, 3] } },
    ]);

    it('keeps the stored items when another field is edited', async () => {
        const { $, getBuffer } = await bootEditor(PASSTHROUGH, SCALAR_FIRST);
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const parsed = await editAndSync($, scalarControl($, 'title'), 'Heading edited', getBuffer);

        expect(parsed[0].props.title).toBe('Heading edited');
        expect(parsed[0].props.tags).toEqual([1, 2, 3]);
        expect(warn).toHaveBeenCalledWith(expect.stringContaining('data-loss guard fired'));
    });

    it('keeps the stored items across a structural row operation', async () => {
        // The edit before the click is what makes this bite: it leaves a sync
        // pending, so the move settles one, which drives the guard over a
        // pass-through array on the way to rewriting the buffer. Without the
        // pending edit the sync never runs and the guard is never consulted.
        const json = JSON.stringify([
            { component: 'card', props: { title: 'First', tags: [1, 2, 3] } },
            { component: 'card', props: { title: 'Second', tags: [] } },
        ]);
        const { $, getBuffer } = await bootEditor(json, SCALAR_FIRST);
        vi.spyOn(console, 'warn').mockImplementation(() => {});

        const $card0 = $('#pp-accordion-view .pp-accordion-card').eq(0);
        await editThen(scalarControl($, 'title', $card0), 'First edited',
            () => $card0.find('.pp-move-down').trigger('click'));

        const parsed = JSON.parse(getBuffer());
        expect(parsed.map((c) => c.props.title)).toEqual(['Second', 'First edited']);
        expect(parsed[1].props.tags).toEqual([1, 2, 3]);
    });
});

// ─── 3. Pending edits across row operations ─────────────────────────────────

describe('an edit made just before a row operation survives it', () => {
    function twoCards() {
        return JSON.stringify([
            { component: 'card', props: { title: 'First', items: [] } },
            { component: 'card', props: { title: 'Second', items: [] } },
        ]);
    }

    function card(idx, $) {
        return $('#pp-accordion-view .pp-accordion-card[data-comp-idx="' + idx + '"]');
    }

    it('survives moving its own component down', async () => {
        const { $, getBuffer } = await bootEditor(twoCards(), SCALAR_FIRST);
        const $title = scalarControl($, 'title', card(0, $));

        await editThen($title, 'First edited', () => card(0, $).find('.pp-move-down').trigger('click'));

        const parsed = JSON.parse(getBuffer());
        expect(parsed.map((c) => c.props.title)).toEqual(['Second', 'First edited']);
    });

    it('survives moving its own component up', async () => {
        const { $, getBuffer } = await bootEditor(twoCards(), SCALAR_FIRST);
        const $title = scalarControl($, 'title', card(1, $));

        await editThen($title, 'Second edited', () => card(1, $).find('.pp-move-up').trigger('click'));

        const parsed = JSON.parse(getBuffer());
        expect(parsed.map((c) => c.props.title)).toEqual(['Second edited', 'First']);
    });

    it('survives deleting a different component', async () => {
        const { $, getBuffer } = await bootEditor(twoCards(), SCALAR_FIRST);
        const $title = scalarControl($, 'title', card(0, $));

        await editThen($title, 'First edited', () => card(1, $).find('.pp-delete-btn').trigger('click'));

        const parsed = JSON.parse(getBuffer());
        expect(parsed.map((c) => c.props.title)).toEqual(['First edited']);
    });

    it('survives adding a component', async () => {
        const { $, getBuffer } = await bootEditor(twoCards(), SCALAR_FIRST);
        const $title = scalarControl($, 'title', card(0, $));

        await editThen($title, 'First edited', () => {
            $('#pp-accordion-view .pp-accordion-insert').first().val('card').trigger('change');
        });

        const parsed = JSON.parse(getBuffer());
        expect(parsed.length).toBe(3);
        expect(parsed[0].props.title).toBe('First edited');
    });

    it('survives adding a row to an array', async () => {
        const json = JSON.stringify([
            { component: 'card', props: { title: 'Heading', items: [{ title: 'Row one', body: 'B' }] } },
        ]);
        const { $, getBuffer } = await bootEditor(json, SCALAR_FIRST);

        await editThen(scalarControl($, 'title'), 'Heading edited',
            () => $('#pp-accordion-view .pp-array-add-btn').first().trigger('click'));

        const parsed = JSON.parse(getBuffer());
        expect(parsed[0].props.title).toBe('Heading edited');
        expect(parsed[0].props.items.length).toBe(2);
        expect(parsed[0].props.items[0].title).toBe('Row one');
    });

    it('survives removing a row from an array', async () => {
        const json = JSON.stringify([
            {
                component: 'card',
                props: {
                    title: 'Heading',
                    items: [{ title: 'Row one', body: 'B1' }, { title: 'Row two', body: 'B2' }],
                },
            },
        ]);
        const { $, getBuffer } = await bootEditor(json, SCALAR_FIRST);

        await editThen(scalarControl($, 'title'), 'Heading edited',
            () => $('#pp-accordion-view .pp-array-remove-btn').eq(1).trigger('click'));

        const parsed = JSON.parse(getBuffer());
        expect(parsed[0].props.title).toBe('Heading edited');
        expect(parsed[0].props.items.length).toBe(1);
        expect(parsed[0].props.items[0].title).toBe('Row one');
    });

    it('survives as a row sub-field edit across a move', async () => {
        const json = JSON.stringify([
            { component: 'card', props: { title: 'First', items: [{ title: 'Row one', body: 'B' }] } },
            { component: 'card', props: { title: 'Second', items: [] } },
        ]);
        const { $, getBuffer } = await bootEditor(json, SCALAR_FIRST);

        await editThen(rowControl($, 'title', 0), 'Row one edited',
            () => card(0, $).find('.pp-move-down').trigger('click'));

        const parsed = JSON.parse(getBuffer());
        expect(parsed.map((c) => c.props.title)).toEqual(['Second', 'First']);
        expect(parsed[1].props.items[0].title).toBe('Row one edited');
    });

    // `subheading` is declared by the schema but absent from every fixture, so it
    // is untouched and stays out of the serialized output. A sync marks every
    // control it resolves as touched, so if an operation settled a sync that was
    // never scheduled, the prop appears on bands the user never opened. That
    // makes it the probe for "this click rewrote more than it should have".
    it('does not rewrite the composition when no edit is pending', async () => {
        const { $, getBuffer } = await bootEditor(twoCards(), SCALAR_FIRST);

        card(0, $).find('.pp-move-down').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 600));

        const parsed = JSON.parse(getBuffer());
        expect(parsed.map((c) => c.props.title)).toEqual(['Second', 'First']);
        expect('subheading' in parsed[0].props).toBe(false);
        expect('subheading' in parsed[1].props).toBe(false);
    });

    it('does not rewrite existing components when one is added', async () => {
        // The insert control is a <select> inside the accordion, so it matches the
        // generic field-change handler as well as its own. If that handler treated
        // it as a field, every insert would leave a sync pending and rewrite the
        // whole composition on the way through.
        const { $, getBuffer } = await bootEditor(twoCards(), SCALAR_FIRST);

        $('#pp-accordion-view .pp-accordion-insert').first().val('card').trigger('change');
        await new Promise((resolve) => setTimeout(resolve, 600));

        const parsed = JSON.parse(getBuffer());
        expect(parsed.length).toBe(3);
        expect(parsed[0].props.title).toBe('First');
        expect(parsed[1].props.title).toBe('Second');
        // The new component is seeded from required props only, and every prop
        // in this registry is optional, so it arrives with an empty props object.
        expect(parsed[2].props).toEqual({});
        // The two that already existed are returned byte-for-byte unchanged.
        expect('subheading' in parsed[0].props).toBe(false);
        expect('subheading' in parsed[1].props).toBe(false);
        expect(parsed[0].props.items).toEqual([]);
    });

    it('does not rewrite untouched fields when a row is added', async () => {
        const json = JSON.stringify([
            { component: 'card', props: { title: 'Heading', items: [] } },
        ]);
        const { $, getBuffer } = await bootEditor(json, SCALAR_FIRST);

        $('#pp-accordion-view .pp-array-add-btn').first().trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 600));

        const parsed = JSON.parse(getBuffer());
        expect(parsed[0].props.items.length).toBe(1);
        expect('subheading' in parsed[0].props).toBe(false);
    });

    it('settles the edit once, not again on the trailing edge', async () => {
        // A flush that ran the sync without disarming its timer would fire again
        // 300ms later, against a DOM the re-render had already replaced. The
        // second run usually writes the same text, so the buffer cannot reveal
        // it — count the writes instead.
        const { $, getBuffer, getWrites } = await bootEditor(twoCards(), SCALAR_FIRST);
        const $title = scalarControl($, 'title', card(0, $));

        const before = getWrites();
        $title.val('First edited').trigger('input');
        card(0, $).find('.pp-move-down').trigger('click');

        // One write settles the pending edit, one applies the move.
        expect(getWrites() - before).toBe(2);

        // Let the window the trailing edge would have fired in elapse.
        await new Promise((resolve) => setTimeout(resolve, 600));
        expect(getWrites() - before).toBe(2);

        const parsed = JSON.parse(getBuffer());
        expect(parsed.map((c) => c.props.title)).toEqual(['Second', 'First edited']);
    });

    it('writes nothing extra when a row operation follows another', async () => {
        const { $, getBuffer } = await bootEditor(twoCards(), SCALAR_FIRST);
        const $title = scalarControl($, 'title', card(0, $));

        await editThen($title, 'First edited', () => {
            card(0, $).find('.pp-move-down').trigger('click');
            card(1, $).find('.pp-move-up').trigger('click');
        });

        const parsed = JSON.parse(getBuffer());
        expect(parsed.map((c) => c.props.title)).toEqual(['First edited', 'Second']);
    });
});

// ─── 4. The two fixes that only work together ───────────────────────────────

describe('a pending edit to a name shared with a row lands on the right field', () => {
    // Settling pending edits turns the name collision from a discarded edit into
    // a committed one, so the two behaviors have to hold at the same time: the
    // edit must survive the operation AND land on the prop the user typed into.
    const FIXTURE = JSON.stringify([
        {
            component: 'card',
            props: {
                title: 'Section heading',
                items: [{ title: 'Row one', body: 'First body' }],
            },
        },
        { component: 'card', props: { title: 'Second', items: [] } },
    ]);

    [['prop declared before the array', SCALAR_FIRST],
     ['prop declared after the array',  ARRAY_FIRST]].forEach(([label, registry]) => {
        it('keeps a top-level edit off the row (' + label + ')', async () => {
            const { $, getBuffer } = await bootEditor(FIXTURE, registry);
            const $card0 = $('#pp-accordion-view .pp-accordion-card[data-comp-idx="0"]');

            await editThen(scalarControl($, 'title', $card0), 'Heading edited',
                () => $card0.find('.pp-move-down').trigger('click'));

            const parsed = JSON.parse(getBuffer());
            const moved = parsed[1];
            expect(moved.props.title).toBe('Heading edited');
            expect(moved.props.items[0].title).toBe('Row one');
        });

        it('keeps a row edit off the top-level prop (' + label + ')', async () => {
            const { $, getBuffer } = await bootEditor(FIXTURE, registry);
            const $card0 = $('#pp-accordion-view .pp-accordion-card[data-comp-idx="0"]');

            await editThen(rowControl($, 'title', 0), 'Row one edited',
                () => $card0.find('.pp-move-down').trigger('click'));

            const parsed = JSON.parse(getBuffer());
            const moved = parsed[1];
            expect(moved.props.title).toBe('Section heading');
            expect(moved.props.items[0].title).toBe('Row one edited');
        });
    });

    it('keeps a zero on a shared name across a row operation', async () => {
        const json = JSON.stringify([
            { component: 'card', props: { title: 0, items: [{ title: 'Row one', body: 'B' }] } },
            { component: 'card', props: { title: 'Second', items: [] } },
        ]);
        const { $, getBuffer } = await bootEditor(json, SCALAR_FIRST);
        const $card0 = $('#pp-accordion-view .pp-accordion-card[data-comp-idx="0"]');

        await editThen(rowControl($, 'body', 0), 'Body edited',
            () => $card0.find('.pp-move-down').trigger('click'));

        const parsed = JSON.parse(getBuffer());
        expect(parsed[1].props.title).toBe('0');
        expect(parsed[1].props.items[0].body).toBe('Body edited');
    });
});
