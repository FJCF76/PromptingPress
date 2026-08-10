/**
 * Attribute-context rendering pins for the accordion builder.
 *
 * These boot the REAL assets/js/pp-admin-editor.js under jsdom and assert on the
 * markup it actually produces. That matters more than it looks: the file is an
 * IIFE with no exports, so the older DOM test alongside this one re-implements
 * the builder's markup in the test body. A re-implementation stays green while
 * the shipped builder regresses, so it pins the test's copy, not the product.
 *
 * Booting the real thing costs one CodeMirror stub:
 *
 *   ppAdminEditor (config) ──┐
 *   PPEditorLogic  ──────────┼─→ pp-admin-editor.js IIFE
 *   wp.CodeMirror (stubbed) ─┘        │
 *                                     ├─ initEditor()      → cm = stub
 *                                     └─ $(ready)          → renderAccordion()
 *                                                                │
 *                                                    #pp-accordion-view.innerHTML
 *
 * @vitest-environment jsdom
 */

const path = require('path');

const LOGIC_PATH  = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-editor-logic.js');
const EDITOR_PATH = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-admin-editor.js');

const REGISTRY = [
    {
        name: 'hero',
        templateOwned: false,
        schema: {
            props: {
                title:    { type: 'string', required: true, description: 'Headline' },
                subheading: { type: 'string', required: false, description: 'Supporting line' },
                layout:   { type: 'enum', required: false, values: ['left', 'centered'], default: 'centered' },
            },
        },
    },
    {
        name: 'faq',
        templateOwned: false,
        schema: {
            props: {
                items: {
                    type: 'array', required: true,
                    items: {
                        question: { type: 'string', required: true },
                        answer:   { type: 'string', required: true },
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
        '<div id="pp-accordion-live"></div>',
        '<div id="pp-accordion-view"></div>',
        '<div id="pp-json-view"></div>',
        '<button id="pp-view-toggle">JSON</button>',
        // showSerializationNotice() mounts into the editor pane and returns early
        // without it, so the invariant gate would fire invisibly if this were absent.
        '<div class="pp-pane pp-pane--editor">',
        '<div class="pp-pane-header"></div>',
        '<div class="pp-pane-body">',
        '<textarea id="pp-composition-editor"></textarea>',
        '</div></div>',
        '<iframe id="pp-preview-frame"></iframe>',
    ].join('');
}

/**
 * Boot the real editor over `json` and hand back the rendered accordion markup.
 * Every card is requested expanded so field-level markup is present.
 */
async function renderAccordionHtml(json, components) {
    installDom();

    const jquery = require('jquery');
    global.jQuery = jquery;
    global.$ = jquery;

    // Minimal CodeMirror surface: only what initEditor() and renderAccordion()
    // touch. Without a non-null `cm` the boot path returns before rendering.
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

    // Both files are IIFEs whose whole effect is the side effect of running, so
    // each boot needs a genuine re-execution. vi.resetModules() is not enough:
    // these are plainly require()d source files, so they sit in Node's CJS
    // require.cache, which resetModules does not clear. Without this the second
    // and later boots silently no-op and the accordion never renders.
    delete require.cache[require.resolve(LOGIC_PATH)];
    delete require.cache[require.resolve(EDITOR_PATH)];
    require(LOGIC_PATH);
    require(EDITOR_PATH);

    // The editor's boot block runs from jQuery's document-ready queue, which
    // dispatches asynchronously even on an already-complete document, so the
    // markup does not exist at require() time. Poll for the observable result,
    // bounded so a genuine boot failure fails fast instead of hanging.
    //
    // Two boot outcomes count as "settled": the accordion rendered, or the
    // serialization-invariant gate forced JSON-only mode and posted its notice.
    //
    // Throwing when neither happens is load-bearing. Several assertions below are
    // absence-only ("no element carries the injected attribute"), and every one of
    // them passes against an accordion that never rendered — so a silent boot
    // failure would turn this file green instead of red.
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

    // The boot renders with every card collapsed; expand them all so the field
    // markup under test is in the DOM.
    jquery('#pp-accordion-view .pp-accordion-toggle').each(function () {
        if (jquery(this).attr('aria-expanded') === 'false') jquery(this).trigger('click');
    });

    return { html: document.getElementById('pp-accordion-view').innerHTML, $: jquery, cm };
}

// Each boot re-runs the editor's document- and window-level wiring (the view
// toggle, the resize handler). installDom() replaces document.body, which discards
// the per-container delegates but not those, so without a teardown they accumulate
// one handler per test and the first test to exercise the toggle fails in a way
// that looks order-dependent rather than like leaked state.
afterEach(() => {
    const jquery = require('jquery');
    jquery(document).off();
    jquery(window).off();
    document.body.innerHTML = '';
    delete global.ppAdminEditor; delete window.ppAdminEditor;
    delete global.wp;            delete window.wp;
    delete global.jQuery;        delete global.$;
});

// ─── Benign compositions render unchanged ────────────────────────────────────

describe('ordinary compositions are unaffected', () => {
    it('renders the declared field names into data-field attributes verbatim', async () => {
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'Ship it', subheading: 'Today' } },
        ]);
        const { html, $ } = await renderAccordionHtml(json);

        expect($('#pp-accordion-view [data-field="title"]').length).toBe(1);
        expect($('#pp-accordion-view [data-field="subheading"]').length).toBe(1);
        expect($('#pp-accordion-view [data-field="layout"]').length).toBe(1);
        expect(html).toContain('value="Ship it"');
    });

    it('round-trips a value containing an apostrophe byte-identically', async () => {
        // The escaper emits &#39; for an apostrophe. That is a different BYTE in the
        // markup and the same CHARACTER in the DOM, which is the whole claim: the
        // author's text must survive a render/read cycle exactly.
        const title = "Don't ship what you can't measure";
        const json = JSON.stringify([{ component: 'hero', props: { title } }]);
        const { $ } = await renderAccordionHtml(json);

        expect($('#pp-accordion-view [data-field="title"]').val()).toBe(title);
    });

    it('round-trips a value containing a double quote byte-identically', async () => {
        const title = 'The "composition" layer';
        const json = JSON.stringify([{ component: 'hero', props: { title } }]);
        const { $ } = await renderAccordionHtml(json);

        expect($('#pp-accordion-view [data-field="title"]').val()).toBe(title);
    });

    it('round-trips markup characters in a value', async () => {
        const title = 'a < b && c > d';
        const json = JSON.stringify([{ component: 'hero', props: { title } }]);
        const { $ } = await renderAccordionHtml(json);

        expect($('#pp-accordion-view [data-field="title"]').val()).toBe(title);
    });

    it('renders array sub-fields with reachable data-field attributes', async () => {
        const json = JSON.stringify([
            { component: 'faq', props: { items: [{ question: 'Why?', answer: 'Because.' }] } },
        ]);
        const { $ } = await renderAccordionHtml(json);

        const $item = $('#pp-accordion-view .pp-accordion-array-item').first();
        expect($item.find('[data-field="question"]').val()).toBe('Why?');
        expect($item.find('[data-field="answer"]').val()).toBe('Because.');
    });
});

// ─── Attribute delimiters hold ───────────────────────────────────────────────

describe('values cannot terminate the attribute that carries them', () => {
    it('keeps a quote-bearing pass-through prop name inside its own data-field attribute', async () => {
        // Pass-through props (a key not in the component schema) take their name
        // straight from Object.keys(props), so any JSON key reaches the builder.
        const weird = 'note" data-comp="9';
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'x', [weird]: 'v' } },
        ]);
        const { $ } = await renderAccordionHtml(json);

        // Exactly one element carries the name, and it reads back whole.
        const $matched = $('#pp-accordion-view').find('[data-field]').filter(function () {
            return $(this).attr('data-field') === weird;
        });
        expect($matched.length).toBe(1);

        // The injected fragment did not become a second attribute on any element.
        expect($('#pp-accordion-view [data-comp="9"]').length).toBe(0);
    });

    it('keeps a quote-bearing value inside its own value attribute', async () => {
        const value = 'x" data-comp="9';
        const json = JSON.stringify([{ component: 'hero', props: { title: value } }]);
        const { $ } = await renderAccordionHtml(json);

        expect($('#pp-accordion-view [data-field="title"]').val()).toBe(value);
        expect($('#pp-accordion-view [data-comp="9"]').length).toBe(0);
    });

    it('does not let a value open a new element', async () => {
        const value = '<img src=x>';
        const json = JSON.stringify([{ component: 'hero', props: { title: value } }]);
        const { $ } = await renderAccordionHtml(json);

        expect($('#pp-accordion-view img').length).toBe(0);
        expect($('#pp-accordion-view [data-field="title"]').val()).toBe(value);
    });

    it('keeps a quote-bearing component name inside its aria-label', async () => {
        const name = 'ghost" hidden="hidden';
        const json = JSON.stringify([{ component: name, props: { a: 'v' } }]);
        const { $ } = await renderAccordionHtml(json);

        // Assert the label reads back whole. Asserting only on the injected
        // attribute's absence would be insensitive here: the buttons already carry
        // a data-* attribute earlier in the tag, and HTML keeps the FIRST of a
        // duplicated attribute, so an escape regression could hide behind that.
        expect($('#pp-accordion-view .pp-delete-btn').attr('aria-label'))
            .toBe('Delete ' + name + ' component');
        expect($('#pp-accordion-view .pp-move-up').attr('aria-label'))
            .toBe('Move ' + name + ' up');
        expect($('#pp-accordion-view [hidden]').length).toBe(0);
    });

    it('keeps a quote-bearing array prop name inside every attribute that carries it', async () => {
        // The array prop name reaches THREE attributes: the container, the per-row
        // remove button, and the add button. Injecting a `data-comp` here would be a
        // weak probe for the two buttons — they already carry a data-comp earlier in
        // the tag, and HTML keeps the FIRST of a duplicated attribute, so a regression
        // on those sites would hide behind the legitimate value. Inject a name that
        // appears on none of the three tags instead.
        const weird = 'rows" data-x="1';
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'x', [weird]: [{ a: '1' }] } },
        ]);
        const { $ } = await renderAccordionHtml(json);

        expect($('#pp-accordion-view [data-x]').length).toBe(0);

        const $arr = $('#pp-accordion-view .pp-accordion-array');
        expect($arr.length).toBe(1);
        expect($arr.attr('data-field')).toBe(weird);
        expect($('#pp-accordion-view .pp-array-remove-btn').attr('data-field')).toBe(weird);
        expect($('#pp-accordion-view .pp-array-add-btn').attr('data-field')).toBe(weird);
    });
});

// ─── The serialization invariant still gates the accordion (#605) ────────────

describe('the serialization invariant gate is untouched', () => {
    it('does not render the accordion when the round-trip would alter the composition', async () => {
        // A stored enum value the schema no longer advertises. #605 forces JSON-only
        // mode here; escaping must not have changed which compositions reach the
        // builder at all.
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'x', layout: 'diagonal' } },
        ]);
        const { $ } = await renderAccordionHtml(json);

        expect($('.pp-serialization-error').length).toBe(1);
        expect($('#pp-accordion-view .pp-accordion-card').length).toBe(0);
    });

    it('renders normally when the round-trip is faithful', async () => {
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'x', layout: 'centered' } },
        ]);
        const { $ } = await renderAccordionHtml(json);

        expect($('.pp-serialization-error').length).toBe(0);
        expect($('#pp-accordion-view .pp-accordion-card').length).toBe(1);
    });
});
