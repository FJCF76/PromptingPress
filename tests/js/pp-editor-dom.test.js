/**
 * DOM selector alignment tests for the accordion editor.
 *
 * Proves that the data-field attributes rendered by buildArrayFieldHtml
 * match the selectors used by syncAccordionToJson to read values back.
 *
 * @vitest-environment jsdom
 */

const jquery = require('jquery');

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Reproduce the HTML structure that buildArrayFieldHtml creates for array items.
 * This mirrors pp-admin-editor.js lines 205-236 — the render path.
 */
function buildArrayItemHtml(compIdx, fieldName, subKeys, items) {
    let h = '<div class="pp-accordion-array" data-comp="' + compIdx + '" data-field="' + fieldName + '">';
    items.forEach(function (item, itemIdx) {
        h += '<div class="pp-accordion-array-item" data-item="' + itemIdx + '">';
        subKeys.forEach(function (sk) {
            // Mirrors buildFieldHtml: data-field uses the subField.name (sk), NOT fieldName.sk
            h += '<input type="text" data-comp="' + compIdx + '" data-field="' + sk + '"';
            h += ' value="' + (item[sk] || '') + '" />';
        });
        h += '</div>';
    });
    h += '</div>';
    return h;
}

/**
 * Reproduce the read logic from syncAccordionToJson (pp-admin-editor.js lines 321-328).
 * Uses the FIXED selector: data-field="sk" (not the broken "fieldName.sk").
 */
function readArrayItemsFixed(compIdx, fieldName, subKeys, $container) {
    var items = [];
    var $arrayItems = $container.find('.pp-accordion-array[data-comp="' + compIdx + '"][data-field="' + fieldName + '"] .pp-accordion-array-item');
    $arrayItems.each(function () {
        var item = {};
        var $this = jquery(this);
        subKeys.forEach(function (sk) {
            var $input = $this.find('[data-field="' + sk + '"][data-comp="' + compIdx + '"]');
            if ($input.length) item[sk] = $input.val();
        });
        items.push(item);
    });
    return items;
}

/**
 * Reproduce the BROKEN read logic (pre-fix): data-field="fieldName.sk".
 */
function readArrayItemsBroken(compIdx, fieldName, subKeys, $container) {
    var items = [];
    var $arrayItems = $container.find('.pp-accordion-array[data-comp="' + compIdx + '"][data-field="' + fieldName + '"] .pp-accordion-array-item');
    $arrayItems.each(function () {
        var item = {};
        var $this = jquery(this);
        subKeys.forEach(function (sk) {
            var $input = $this.find('[data-field="' + fieldName + '.' + sk + '"][data-comp="' + compIdx + '"]');
            if ($input.length) item[sk] = $input.val();
        });
        items.push(item);
    });
    return items;
}

// ─── Tests ──────────────────────────────────────────────────────────────────

describe('DOM selector alignment — array field round-trip', () => {
    const faqItems = [
        { question: 'What is PromptingPress?', answer: 'A composition page builder.' },
        { question: 'Is it free?', answer: 'Yes, open source.' },
    ];
    const faqSubKeys = ['question', 'answer'];

    const gridItems = [
        { title: 'Fast', text: 'Lightning speed', image_url: '/img/fast.png' },
        { title: 'Safe', text: 'Enterprise security', image_url: '/img/safe.png' },
    ];
    const gridSubKeys = ['title', 'text', 'image_url'];

    test('fixed selector reads FAQ items correctly from rendered DOM', () => {
        const html = buildArrayItemHtml(0, 'items', faqSubKeys, faqItems);
        const $container = jquery('<div>').html(html);
        const result = readArrayItemsFixed(0, 'items', faqSubKeys, $container);
        expect(result).toHaveLength(2);
        expect(result[0]).toEqual({ question: 'What is PromptingPress?', answer: 'A composition page builder.' });
        expect(result[1]).toEqual({ question: 'Is it free?', answer: 'Yes, open source.' });
    });

    test('broken selector reads FAQ items as empty objects (regression proof)', () => {
        const html = buildArrayItemHtml(0, 'items', faqSubKeys, faqItems);
        const $container = jquery('<div>').html(html);
        const result = readArrayItemsBroken(0, 'items', faqSubKeys, $container);
        expect(result).toHaveLength(2);
        // Broken selector finds nothing — items are empty objects
        expect(result[0]).toEqual({});
        expect(result[1]).toEqual({});
    });

    test('fixed selector reads grid items with multiple sub-fields', () => {
        const html = buildArrayItemHtml(1, 'items', gridSubKeys, gridItems);
        const $container = jquery('<div>').html(html);
        const result = readArrayItemsFixed(1, 'items', gridSubKeys, $container);
        expect(result).toHaveLength(2);
        expect(result[0]).toEqual({ title: 'Fast', text: 'Lightning speed', image_url: '/img/fast.png' });
        expect(result[1]).toEqual({ title: 'Safe', text: 'Enterprise security', image_url: '/img/safe.png' });
    });

    test('no collision between array sub-field and top-level scalar with same name', () => {
        // Render a grid with a top-level "title" scalar AND items with a "title" sub-field
        let html = '<input type="text" data-comp="0" data-field="title" value="Section Title" />';
        html += buildArrayItemHtml(0, 'items', ['title', 'text'], [{ title: 'Card', text: 'Body' }]);
        const $container = jquery('<div>').html(html);

        // The array read scopes to .pp-accordion-array-item, so it should NOT pick up the top-level title
        const result = readArrayItemsFixed(0, 'items', ['title', 'text'], $container);
        expect(result).toHaveLength(1);
        expect(result[0].title).toBe('Card'); // NOT 'Section Title'
    });

    test('empty items array renders and reads back as empty', () => {
        const html = buildArrayItemHtml(0, 'items', faqSubKeys, []);
        const $container = jquery('<div>').html(html);
        const result = readArrayItemsFixed(0, 'items', faqSubKeys, $container);
        expect(result).toHaveLength(0);
    });
});

// ─── Enum <select> rendering (#605) ─────────────────────────────────────────
//
// Re-homed from the deleted pp-editor-enum-legacy.test.js. That file existed to
// pin the #442 legacy-value injection, which prepended any stored-but-unadvertised
// enum value as its own `<option>` so re-saving round-tripped it. #605 deleted the
// injection: every shipped enum is `strict: true` (#579), so the only way to hold
// an unadvertised value is stale storage, and tolerating stale data is an explicit
// non-goal. These keep the assertions that were never about legacy values, and add
// the one that pins the new contract.

/**
 * Mirrors the enum branch of buildFieldHtml in pp-admin-editor.js — post-#605, the
 * <select> is built from the advertised `values` and nothing else.
 */
function buildEnumSelectHtml(field) {
    function esc(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    let h = '<select data-field="' + field.name + '">';
    if (field.type === 'enum' && field.values) {
        field.values.forEach(function (v) {
            const sel = v === field.value ? ' selected' : '';
            h += '<option value="' + esc(v) + '"' + sel + '>' + esc(v) + '</option>';
        });
    }
    h += '</select>';
    return h;
}

function selectedValue(html) {
    document.body.innerHTML = html;
    return document.querySelector('select').value;
}

describe('accordion editor enum <select>', () => {
    const themeValues = ['default', 'muted', 'inverted'];

    test('selects an advertised value normally', () => {
        const html = buildEnumSelectHtml({ name: 'theme', type: 'enum', values: themeValues, value: 'muted' });
        expect(selectedValue(html)).toBe('muted');
        expect((html.match(/<option/g) || []).length).toBe(3);
    });

    test('does not inject a stray option when no value is stored', () => {
        const html = buildEnumSelectHtml({ name: 'theme', type: 'enum', values: themeValues, value: undefined });
        expect((html.match(/<option/g) || []).length).toBe(3);
        // Browser defaults to the first option.
        expect(selectedValue(html)).toBe('default');
    });

    test('does not double-count when the stored value is the default', () => {
        const html = buildEnumSelectHtml({ name: 'theme', type: 'enum', values: themeValues, value: 'default' });
        expect((html.match(/<option/g) || []).length).toBe(3);
        expect(selectedValue(html)).toBe('default');
    });

    // SOURCE TRIPWIRE. Everything above mirrors the editor's enum branch rather
    // than importing it (buildFieldHtml lives inside an IIFE and is unexported), so
    // on its own it would keep passing if the shipped file drifted. This asserts
    // against the shipped bytes: the legacy-option injection is gone from
    // pp-admin-editor.js and re-introducing it fails here (#605).
    test('the shipped editor contains no legacy-option injection', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(
            path.join(__dirname, '..', '..', 'assets', 'js', 'pp-admin-editor.js'),
            'utf-8'
        );
        expect(src).not.toContain('(legacy)');
        // The guard that only existed to protect the injection goes with it.
        expect(src).not.toContain('/^[a-z0-9_-]+$/i');
        // And the <select> is still built from the advertised values.
        expect(src).toContain('field.values.forEach');
    });

    test('a stored value outside the advertised set falls back to the first option', () => {
        // THE #605 CONTRACT, and the reason it is safe to state the consequence in
        // the release notes: the editor serializes from the DOM (pp-admin-editor.js
        // reads $input.val() off the live <select>), so a band still holding the
        // removed theme value "dark" shows — and re-saves — the default. No stray
        // "(legacy)" option, no round-trip of stale storage.
        const html = buildEnumSelectHtml({ name: 'theme', type: 'enum', values: themeValues, value: 'dark' });
        expect(html).not.toContain('(legacy)');
        expect(html).not.toContain('dark');
        expect((html.match(/<option/g) || []).length).toBe(3);
        expect(selectedValue(html)).toBe('default');
    });
});
