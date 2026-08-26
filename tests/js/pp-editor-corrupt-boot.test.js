/**
 * The composition editor on a page whose STORED composition cannot be read (#750).
 *
 * Two states end in the same place — JSON-only mode — and until #750 they told the same
 * story there, which was the drift story:
 *
 *   stored row corrupt?  --yes--> corruption notice     classification + repair route
 *          |no                                          (this file)
 *   round-trip unsafe?   --yes--> serialization notice   diff paths + "Copy as GitHub Issue"
 *          |no                                          (#605/#745/#805, pinned in
 *   render the accordion                                 pp-editor-form-sync.test.js)
 *
 * Every test boots the REAL assets/js/pp-admin-editor.js under jsdom and asserts on what
 * reached the DOM, not on any re-implementation of the decision. The classification itself
 * is decided in PHP (pp_composition_editor_integrity, pinned in
 * tests/CompositionEditorCorruptBootTest.php) and arrives as ppAdminEditor.compositionIntegrity;
 * the client's job — and what is pinned here — is rendering it and refusing to present the
 * page as a blank form.
 *
 * @vitest-environment jsdom
 */

const path = require('path');

const LOGIC_PATH  = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-editor-logic.js');
const EDITOR_PATH = path.join(__dirname, '..', '..', 'assets', 'js', 'pp-admin-editor.js');

// ─── Registry ───────────────────────────────────────────────────────────────

const REGISTRY = [
    {
        name: 'card',
        templateOwned: false,
        schema: {
            props: {
                title: { type: 'string', required: false, description: 'Heading' },
                body:  { type: 'string', required: false, description: 'Copy' },
            },
        },
    },
];

/** What PHP ships for a page classified `unexpected_shape`. */
const CORRUPT = {
    error: 'unexpected_shape',
    message: 'Page 7: composition data integrity error (unexpected_shape). The stored _pp_composition is not a valid composition list — treat as corrupted, not empty.',
    repair: 'This editor is one of the repair surfaces, so you can fix it here: replace the JSON below with a valid composition array and save.',
};

// ─── Harness ────────────────────────────────────────────────────────────────

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
        '<div class="pp-pane pp-pane--editor">',
        '<div class="pp-pane-header"></div>',
        '<div class="pp-pane-body">',
        '<textarea id="pp-composition-editor"></textarea>',
        '</div></div>',
        '<iframe id="pp-preview-frame"></iframe>',
    ].join('');
}

/**
 * Boot the real editor over `json`, optionally with an integrity payload.
 *
 * `setBuffer` is the one escape hatch, and it models a real sequence rather than a
 * shortcut: the author opens a healthy page (so the accordion, and therefore the insert
 * dropdown, are rendered), then edits the JSON pane into something that is no longer a
 * composition list. Nothing in the editor re-renders the accordion on a buffer change, so
 * the stale dropdown stays clickable — which is exactly the path the insert handler's guard
 * has to survive.
 */
async function bootEditor(json, opts) {
    opts = opts || {};
    installDom();

    const jquery = require('jquery');
    global.jQuery = jquery;
    global.$ = jquery;

    // Preview traffic is left pending (a resolved preview would rewrite nothing here but
    // would open a socket-shaped code path for no reason). A composition SAVE can be
    // answered, because "what happens to the notice after the repair lands" is only
    // observable through the server's reply.
    const posts = [];
    jquery.post = (url, data) => {
        posts.push(data);
        const isSave = data && (data.action === 'pp_save_composition' || data.action === 'pp_publish_page');
        return {
            done(fn) {
                if (isSave && opts.saveResponse) fn(opts.saveResponse);
                return this;
            },
            fail() { return this; },
            always(fn) { if (fn) fn(); return this; },
        };
    };

    let buffer = json;
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

    // `cmDisabled` models the WordPress profile option "Disable syntax highlighting while
    // editing": initEditor styles the bare textarea and returns before assigning `cm`, so
    // every cm-dependent path in the editor is dead for that author. The corruption notice
    // must not be one of them.
    global.wp = window.wp = opts.cmDisabled ? {} : {
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
        postId: 7,
        postStatus: 'draft',
        compositionVersion: 1,
        codeEditorSettings: { codemirror: {} },
        cmDisabled: !!opts.cmDisabled,
        compositionIntegrity: opts.integrity || null,
    };

    delete require.cache[require.resolve(LOGIC_PATH)];
    delete require.cache[require.resolve(EDITOR_PATH)];
    require(LOGIC_PATH);
    require(EDITOR_PATH);

    // The boot block runs from jQuery's document-ready queue, which dispatches
    // asynchronously even on a complete document. Poll for the observable result,
    // bounded so a genuine boot failure fails fast instead of hanging.
    let settled = false;
    for (let i = 0; i < 50; i++) {
        const rendered = document.getElementById('pp-accordion-view').innerHTML !== '';
        const blocked  = document.querySelector('.pp-serialization-error') !== null;
        if (rendered || blocked) { settled = true; break; }
        await new Promise((resolve) => setTimeout(resolve, 1));
    }
    if (!settled) {
        throw new Error('editor never booted: #pp-accordion-view is empty and no notice was posted');
    }

    return {
        $: jquery,
        getBuffer: () => buffer,
        getWrites: () => writes,
        setBuffer: (v) => { buffer = v; },
        savePosts: () => posts.filter((p) =>
            p.action === 'pp_save_composition' || p.action === 'pp_publish_page'),
    };
}

/** Trigger the real insert dropdown for `name` and let its handler run. */
async function insertComponent($, name) {
    const $select = $('#pp-accordion-view .pp-accordion-insert').first();
    if (!$select.length) throw new Error('no insert dropdown is rendered');
    $select.val(name).trigger('change');
    await new Promise((resolve) => setTimeout(resolve, 5));
}

// ─── Tests ──────────────────────────────────────────────────────────────────

describe('a page whose stored composition cannot be read (#750)', () => {
    it('lands the author in JSON-only mode, with no way back into the accordion', async () => {
        const { $ } = await bootEditor('{"1":{"component":"card"}}', { integrity: CORRUPT });

        expect($('.pp-serialization-error').length).toBe(1);
        expect($('#pp-accordion-view').css('display')).toBe('none');
        expect($('#pp-json-view').css('display')).not.toBe('none');
        expect($('#pp-view-toggle').css('display')).toBe('none');
    });

    it('never presents the page as a pristine empty form', async () => {
        const { $ } = await bootEditor('{"1":{"component":"card"}}', { integrity: CORRUPT });

        // The two shapes the accordion can take, both of which would be the lie: cards for
        // components it could not read, or "No components yet" for a page that HAS one.
        expect($('#pp-accordion-view .pp-accordion-card').length).toBe(0);
        expect($('#pp-accordion-view .pp-accordion-empty').length).toBe(0);
        // And no insert dropdown, so the coerce-and-replace path is not even reachable.
        expect($('#pp-accordion-view .pp-accordion-insert').length).toBe(0);
    });

    it('names the classification and the repair route', async () => {
        const { $ } = await bootEditor('{"1":{"component":"card"}}', { integrity: CORRUPT });
        const $notice = $('.pp-serialization-error');

        expect($notice.attr('data-pp-corrupt')).toBe('unexpected_shape');
        expect($notice.text()).toContain('unexpected_shape');
        expect($notice.text()).toContain('treat as corrupted, not empty');
        expect($notice.text()).toContain('replace the JSON below');
    });

    it('does not offer a diff table or a bug report for a corrupt row', async () => {
        // The drift notice's diff table and issue button are right for a defect in this
        // editor. A stored row that is not a composition is not that, and pointing the
        // author at a bug report instead of a repair is the wrong next step.
        const { $ } = await bootEditor('{"1":{"component":"card"}}', { integrity: CORRUPT });

        expect($('.pp-serialization-error table').length).toBe(0);
        expect($('.pp-serialization-error .pp-copy-issue-btn').length).toBe(0);
    });

    it('keeps the raw stored bytes in the pane, which is what the repair edits', async () => {
        const stored = '{"1":{"component":"card"}}';
        const { getBuffer, getWrites } = await bootEditor(stored, { integrity: CORRUPT });

        expect(getBuffer()).toBe(stored);
        expect(getWrites()).toBe(0);
    });

    it('keeps the accordion closed even if the hidden view toggle is fired', async () => {
        // JSON-only mode HIDES the toggle, it does not unbind it. The guard at the top of
        // renderAccordion() is the backstop for anything that reaches the handler anyway —
        // a programmatic click, a restored control, a future caller.
        const { $ } = await bootEditor('{"1":{"component":"card"}}', { integrity: CORRUPT });

        $('#pp-view-toggle').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 5));

        expect($('#pp-accordion-view .pp-accordion-card').length).toBe(0);
        expect($('#pp-accordion-view .pp-accordion-empty').length).toBe(0);
        expect($('.pp-serialization-error').length).toBe(1);
        expect($('.pp-serialization-error').attr('data-pp-corrupt')).toBe('unexpected_shape');
    });

    it('still says so when the author has syntax highlighting disabled', async () => {
        // With `cmDisabled` there is no CodeMirror instance, and every other boot path needs
        // one. Gating the corruption notice on it too would leave this author staring at an
        // empty editor for a corrupt page: the presentation this issue exists to remove,
        // surviving in the one configuration nobody looks at.
        const { $ } = await bootEditor('{"1":{"component":"card"}}', {
            integrity: CORRUPT,
            cmDisabled: true,
        });

        expect($('.pp-serialization-error').attr('data-pp-corrupt')).toBe('unexpected_shape');
        expect($('.pp-serialization-error').text()).toContain('treat as corrupted, not empty');
        expect($('#pp-accordion-view').css('display')).toBe('none');
        expect($('#pp-json-view').css('display')).not.toBe('none');
        expect($('#pp-accordion-view .pp-accordion-empty').length).toBe(0);
    });

    it('ignores a payload that names no classification', async () => {
        // The client renders a decision, it does not make one. A payload with no
        // classification is not a corrupt page; it is a payload with nothing to say.
        const { $ } = await bootEditor('[{"component":"card","props":{"title":"Hi"}}]', {
            integrity: { error: '', message: '', repair: '' },
        });

        expect($('.pp-serialization-error').length).toBe(0);
        expect($('#pp-accordion-view .pp-accordion-card').length).toBe(1);
    });

    it('renders an undecodable row the same way, under its own classification', async () => {
        const { $ } = await bootEditor('not json at{', {
            integrity: {
                error: 'decode_error',
                message: 'Page 7: composition data integrity error (decode_error). The stored _pp_composition is not a valid composition list — treat as corrupted, not empty.',
                repair: 'This editor is one of the repair surfaces, so you can fix it here: replace the JSON below with a valid composition array and save.',
            },
        });

        expect($('.pp-serialization-error').attr('data-pp-corrupt')).toBe('decode_error');
        expect($('.pp-serialization-error').text()).toContain('decode_error');
    });
});

describe('the surfaces #750 must leave alone', () => {
    it('renders the accordion normally on a readable page', async () => {
        const { $ } = await bootEditor('[{"component":"card","props":{"title":"Hi"}}]');

        expect($('.pp-serialization-error').length).toBe(0);
        expect($('#pp-accordion-view .pp-accordion-card').length).toBe(1);
        expect($('#pp-view-toggle').css('display')).not.toBe('none');
    });

    it('shows a genuinely blank page as empty, not as corrupt', async () => {
        const { $ } = await bootEditor('');

        expect($('.pp-serialization-error').length).toBe(0);
        expect($('#pp-accordion-view .pp-accordion-empty').length).toBe(1);
    });

    it('still gives a DRIFT fixture the drift notice, with its diff paths (#745)', async () => {
        // A healthy stored row the accordion would mutate on readback: the classification
        // is null, so the corruption branch must not fire and #745's notice must.
        const json = JSON.stringify([{ component: 'card', props: { title: false } }]);
        const { $ } = await bootEditor(json);

        const $notice = $('.pp-serialization-error');
        expect($notice.length).toBe(1);
        expect($notice.attr('data-pp-corrupt')).toBeUndefined();
        expect($notice.find('.pp-copy-issue-btn').length).toBe(1);
        const paths = $notice.find('tbody tr td:nth-child(2) code').map(function () {
            return $(this).text();
        }).get();
        expect(paths).toContain('[0].props.title');
    });
});

describe('after the repair lands (#750)', () => {
    /** A save that succeeds, echoing the composition the server stored. */
    const SAVED = {
        success: true,
        data: { composition: [{ component: 'card', props: { title: 'Repaired' } }], version: 2 },
    };

    // Both buttons, because they are two call sites of the same decision and only one of
    // them was ever exercised. `#pp-save-btn` posts pp_save_composition; `#pp-publish-btn`
    // posts pp_publish_page, and it is the one whose handler can skip the composition write.
    it.each([
        ['#pp-save-btn', 'pp_save_composition'],
        ['#pp-publish-btn', 'pp_publish_page'],
    ])('takes the corruption notice down and gives the accordion back (%s)', async (button) => {
        const { $, setBuffer } = await bootEditor('{"1":{"component":"card"}}', {
            integrity: CORRUPT,
            saveResponse: SAVED,
        });
        // The operator does the repair the notice prescribes: replace the pane with a list.
        setBuffer(JSON.stringify([{ component: 'card', props: { title: 'Repaired' } }]));

        $(button).trigger('click');
        // Read the status SYNCHRONOUSLY: the stubbed post resolves inside the click, and a
        // 3s setSaveStatus('','') timer from any earlier save is still pending against a DOM
        // this file keeps replacing. Asserting before the stack yields makes the pin depend
        // on the code under test rather than on how fast the file runs.
        expect($('#pp-save-status').text()).toBe('Composition repaired');

        await new Promise((resolve) => setTimeout(resolve, 5));

        expect($('.pp-serialization-error').length).toBe(0);
        expect($('#pp-accordion-view .pp-accordion-card').length).toBe(1);
        expect($('#pp-view-toggle').css('display')).not.toBe('none');
    });

    it('keeps the notice up when a publish went through WITHOUT writing a composition', async () => {
        // wp_ajax_pp_publish_page skips the composition write when the posted composition is
        // empty (`if ($raw !== '')`, lib/admin.php) and publishes anyway. An author who
        // clears the pane and hits Publish gets ok back over an untouched corrupt row, so
        // "the request succeeded" must not be read as "the page is repaired".
        const { $, setBuffer } = await bootEditor('{"1":{"component":"card"}}', {
            integrity: CORRUPT,
            saveResponse: {
                success: true,
                data: { status: 'publish', post_link: '/p', preview_link: '/pv', composition: [], version: 1 },
            },
        });
        setBuffer('');

        $('#pp-publish-btn').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 5));

        const $notice = $('.pp-serialization-error');
        expect($notice.length).toBe(1);
        expect($notice.attr('data-pp-corrupt')).toBe('unexpected_shape');
        expect($('#pp-accordion-view .pp-accordion-empty').length).toBe(0);
        expect($('#pp-accordion-view .pp-accordion-card').length).toBe(0);
    });

    it('swaps to the drift notice when the repair itself is drift-unsafe, never keeps the stale one', async () => {
        // The page is no longer corrupt — the write validated — but the accordion still
        // cannot open what landed (#745). Two different states, and the notice has to move.
        const { $, setBuffer } = await bootEditor('{"1":{"component":"card"}}', {
            integrity: CORRUPT,
            saveResponse: {
                success: true,
                data: { composition: [{ component: 'card', props: { title: false } }], version: 2 },
            },
        });
        setBuffer(JSON.stringify([{ component: 'card', props: { title: 'ok' } }]));

        $('#pp-save-btn').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 5));

        const $notice = $('.pp-serialization-error');
        expect($notice.length).toBe(1);
        expect($notice.attr('data-pp-corrupt')).toBeUndefined();
        expect($notice.find('.pp-copy-issue-btn').length).toBe(1);
    });
});

describe('the insert dropdown on a buffer that is not a composition list (#750)', () => {
    it('still adds the first component to a genuinely blank page', async () => {
        // The case that made the old coercion look reasonable: JSON.parse('') throws, and
        // the [] fallback is how a new page gets its first band. It must keep working.
        const { $, getBuffer } = await bootEditor('');

        await insertComponent($, 'card');

        expect(JSON.parse(getBuffer())).toEqual([{ component: 'card', props: {} }]);
    });

    it('refuses, and writes nothing, when the pane holds a JSON object', async () => {
        const { $, getBuffer, setBuffer } = await bootEditor('[{"component":"card","props":{"title":"Hi"}}]');
        setBuffer('{"1":{"component":"card"}}');

        await insertComponent($, 'card');

        expect(getBuffer()).toBe('{"1":{"component":"card"}}');
        // Asserted on the live region, not the error bar: the standing 300ms validation
        // writes its own message over the bar, so a pin there passes or fails on timing.
        expect($('#pp-accordion-live').text()).toContain('Nothing was added');
        expect($('#pp-accordion-live').text()).toContain('JSON object');
    });

    it('refuses, and writes nothing, when the pane is not valid JSON', async () => {
        const { $, getBuffer, setBuffer } = await bootEditor('[{"component":"card","props":{"title":"Hi"}}]');
        setBuffer('[{"component": "card"');

        await insertComponent($, 'card');

        expect(getBuffer()).toBe('[{"component": "card"');
        expect($('#pp-accordion-live').text()).toContain('Nothing was added');
        expect($('#pp-accordion-live').text()).toContain('not valid JSON');
    });

    it('still adds the first component when the pane holds only whitespace', async () => {
        const { $, getBuffer } = await bootEditor('   \n  ');

        await insertComponent($, 'card');

        expect(JSON.parse(getBuffer())).toEqual([{ component: 'card', props: {} }]);
    });

    // `null` is the interesting one: JSON.parse('null') succeeds and yields a FALSY value,
    // so a guard written around truthiness instead of Array.isArray would fall straight back
    // into the coercion this function replaced.
    it.each([
        ['null', 'JSON null'],
        ['0', 'JSON number'],
        ['"a string"', 'JSON string'],
        ['true', 'JSON boolean'],
    ])('refuses, and names what it found, when the pane holds %s', async (buffer, described) => {
        const { $, getBuffer, setBuffer } = await bootEditor('[{"component":"card","props":{"title":"Hi"}}]');
        setBuffer(buffer);

        await insertComponent($, 'card');

        expect(getBuffer()).toBe(buffer);
        expect($('#pp-accordion-live').text()).toContain('Nothing was added');
        expect($('#pp-accordion-live').text()).toContain(described);
    });

    it('appends to a real list rather than replacing it', async () => {
        const { $, getBuffer } = await bootEditor('[{"component":"card","props":{"title":"Hi"}}]');

        await insertComponent($, 'card');

        const parsed = JSON.parse(getBuffer());
        expect(parsed.length).toBe(2);
        expect(parsed[0].props.title).toBe('Hi');
    });
});
