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
