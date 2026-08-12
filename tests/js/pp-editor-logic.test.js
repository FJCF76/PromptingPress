/**
 * Tests for assets/js/pp-editor-logic.js
 *
 * Covers:
 *   getJsonContextFromText  — autocomplete context walker
 *   validateCompositionData — JSON + component registry validation
 *   getInsertPosition       — array item insertion point walker
 */

const {
    escapeHtml,
    getJsonContextFromText,
    validateCompositionData,
    getInsertPosition,
    buildAccordionData,
    serializeAccordionData,
    wouldLoseArrayData,
    reconcileArrayItems,
    deepDiff,
    checkSerializationInvariant,
    unadvertisedEnumDiffs,
    formatDiffsForIssue,
    getCollapsedRowPreview,
} = require('../../assets/js/pp-editor-logic.js');

const fs = require('fs');
const path = require('path');

// ─── Fixtures ────────────────────────────────────────────────────────────────

const HERO = {
    name: 'hero',
    schema: {
        props: {
            title:    { type: 'string',  required: true  },
            subheading: { type: 'string',  required: false },
        },
    },
};

const FAQ = {
    name: 'faq',
    schema: {
        props: {
            items: {
                type: 'array', required: true,
                items: {
                    question: { type: 'string', required: true },
                    answer:   { type: 'string', required: true },
                },
            },
            title: { type: 'string', required: false },
        },
    },
};

const SECTION = {
    name: 'section',
    schema: {
        props: {
            body:    { type: 'string',  required: true, description: 'HTML body content.' },
            title:   { type: 'string',  required: false, default: '' },
            layout:  { type: 'enum', values: ['text-only', 'image-left', 'image-right', 'centered'], required: false, default: 'text-only' },
            theme:   { type: 'enum', values: ['default', 'muted', 'inverted'], required: false, default: 'default' },
        },
    },
};

const GRID = {
    name: 'grid',
    schema: {
        props: {
            title: { type: 'string', required: false },
            items: {
                type: 'array', required: true,
                items: {
                    title:    { type: 'string', required: false },
                    text:     { type: 'string', required: false },
                    image_url:{ type: 'string', required: false },
                    link_url: { type: 'string', required: false },
                },
            },
        },
    },
};

const FOOTER = {
    name: 'footer',
    schema: {
        props: {
            location: { type: 'string', required: false, default: 'footer' },
        },
    },
};

const REGISTRY = [HERO, FAQ, SECTION, GRID, FOOTER];

// ─── getJsonContextFromText ───────────────────────────────────────────────────

describe('getJsonContextFromText', () => {
    test('cursor immediately after "component": " → component-value', () => {
        const text = '[\n  {\n    "component": "';
        expect(getJsonContextFromText(text, ['hero', 'faq'])).toEqual({ type: 'component-value' });
    });

    test('cursor after partial component name → component-value', () => {
        const text = '[\n  {\n    "component": "her';
        expect(getJsonContextFromText(text, ['hero'])).toEqual({ type: 'component-value' });
    });

    test('cursor inside empty props object → props-key with component name', () => {
        const text = '[\n  {\n    "component": "hero",\n    "props": {\n      "';
        expect(getJsonContextFromText(text, ['hero'])).toEqual({ type: 'props-key', componentName: 'hero' });
    });

    test('cursor inside props of second component when two components in text', () => {
        const text =
            '[\n  {\n    "component": "hero",\n    "props": { "title": "Hi" }\n  },\n' +
            '  {\n    "component": "faq",\n    "props": {\n      "';
        const ctx = getJsonContextFromText(text, ['hero', 'faq']);
        expect(ctx).toEqual({ type: 'props-key', componentName: 'faq' });
    });

    test('cursor between two fully-closed components → null', () => {
        const text =
            '[\n  { "component": "hero", "props": { "title": "Hi" } },\n  ';
        expect(getJsonContextFromText(text, ['hero', 'faq'])).toBeNull();
    });

    test('completely empty text → null', () => {
        expect(getJsonContextFromText('', [])).toBeNull();
    });

    test('cursor before any component key → null', () => {
        expect(getJsonContextFromText('[\n  {', [])).toBeNull();
    });

    test('string value containing } inside props does not close the depth counter', () => {
        // The value of "title" contains a "}" — should still be inside props
        const text = '[\n  {\n    "component": "hero",\n    "props": { "title": "a}b", "';
        expect(getJsonContextFromText(text, ['hero'])).toEqual({ type: 'props-key', componentName: 'hero' });
    });

    test('escaped quote inside string value does not break inStr tracking', () => {
        const text = '[\n  {\n    "component": "hero",\n    "props": { "title": "say \\"hi\\"", "';
        expect(getJsonContextFromText(text, ['hero'])).toEqual({ type: 'props-key', componentName: 'hero' });
    });

    test('cursor inside a value string (afterColon=true) → null, not props-key', () => {
        // Cursor is mid-way through a string value — should NOT trigger props-key autocomplete.
        // This is the regression path for the afterColon fix.
        const text = '[\n  {\n    "component": "hero",\n    "props": { "title": "hell';
        expect(getJsonContextFromText(text, ['hero'])).toBeNull();
    });

    test('cursor after colon but before opening quote of value → null (value-slot, not key-slot)', () => {
        const text = '[\n  {\n    "component": "hero",\n    "props": { "title": ';
        expect(getJsonContextFromText(text, ['hero'])).toBeNull();
    });

    test('cursor at end of document with unclosed brace — no false positive for component-value', () => {
        const text = '[\n  {\n    "component": "hero';
        // does NOT end with `"` so regex won't match component-value either
        const ctx = getJsonContextFromText(text, ['hero']);
        // partial component name in value → component-value
        expect(ctx).toEqual({ type: 'component-value' });
    });

    test('empty "props": {} followed by sibling object — must not return props-key', () => {
        // Regression for the depth-0 break fix: after props closes, a sibling key with
        // an object value would re-open depth to 1, triggering a false props-key hit.
        const text = '[ { "component": "hero", "props": {}, "extra": { "';
        expect(getJsonContextFromText(text, ['hero'])).toBeNull();
    });

    test('"component" key text with no name yet (just partial) and cursor not in props → not props-key', () => {
        // There's no closed "component": "..." match, so getNearestComponentName finds nothing
        const text = '[\n  {\n    "component": "';
        const ctx = getJsonContextFromText(text, ['hero']);
        expect(ctx).toEqual({ type: 'component-value' });
    });
});

// ─── validateCompositionData ──────────────────────────────────────────────────

describe('validateCompositionData', () => {
    test('empty string → no errors', () => {
        expect(validateCompositionData('', REGISTRY)).toEqual([]);
    });

    test('whitespace-only string → no errors', () => {
        expect(validateCompositionData('   \n  ', REGISTRY)).toEqual([]);
    });

    test('valid single component with all required props → no errors', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hello' } }]);
        expect(validateCompositionData(json, REGISTRY)).toEqual([]);
    });

    test('valid multi-component composition → no errors', () => {
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'Hello' } },
            { component: 'faq',  props: { items: [] } },
        ]);
        expect(validateCompositionData(json, REGISTRY)).toEqual([]);
    });

    test('root is object not array → error', () => {
        const json = JSON.stringify({ component: 'hero', props: {} });
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors).toHaveLength(1);
        expect(errors[0]).toMatch(/must be a JSON array/i);
    });

    test('syntax error → single error with JSON syntax message', () => {
        const errors = validateCompositionData('{broken json', REGISTRY);
        expect(errors).toHaveLength(1);
        expect(errors[0]).toMatch(/JSON syntax error/i);
    });

    test('item missing "component" key → error', () => {
        const json = JSON.stringify([{ props: { title: 'Hi' } }]);
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors.some(e => /missing "component" key/.test(e))).toBe(true);
    });

    test('unknown component name → error', () => {
        const json = JSON.stringify([{ component: 'ghost', props: {} }]);
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors.some(e => /Unknown component.*ghost/.test(e))).toBe(true);
    });

    test('missing required prop → error naming component and prop', () => {
        const json = JSON.stringify([{ component: 'hero', props: {} }]);
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors.some(e => /"hero".*missing.*"title"/.test(e) || /"title"/.test(e))).toBe(true);
    });

    test('optional prop absent → no error', () => {
        // hero has subheading as optional — omitting it is fine
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi' } }]);
        expect(validateCompositionData(json, REGISTRY)).toEqual([]);
    });

    test('required prop set to null → error (null is not a valid value)', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: null } }]);
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors.some(e => /"title"/.test(e))).toBe(true);
    });

    test('required prop set to false → error', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: false } }]);
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors.some(e => /"title"/.test(e))).toBe(true);
    });

    test('required prop set to empty string → error', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: '' } }]);
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors.some(e => /"title"/.test(e))).toBe(true);
    });

    test('required array prop set to empty array → no error (empty array is valid)', () => {
        // faq.items is required:true, type:array — an empty list is valid
        const json = JSON.stringify([{ component: 'faq', props: { items: [] } }]);
        expect(validateCompositionData(json, REGISTRY)).toEqual([]);
    });

    test('item with no props key at all (and component has required props) → error', () => {
        const json = JSON.stringify([{ component: 'hero' }]);
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors.length).toBeGreaterThan(0);
    });

    test('multiple errors returned when composition has several problems', () => {
        const json = JSON.stringify([
            { component: 'ghost' },      // unknown + no props
            { component: 'hero' },       // missing title
            { component: 'faq' },        // missing items
        ]);
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors.length).toBeGreaterThanOrEqual(3);
    });

    test('item that is null → error about not being an object', () => {
        const json = JSON.stringify([null]);
        const errors = validateCompositionData(json, REGISTRY);
        expect(errors.some(e => /not an object/i.test(e))).toBe(true);
    });
});

// ─── getInsertPosition ────────────────────────────────────────────────────────

// Helper to build a two-space-indented JSON composition string
function makeComposition(items) {
    return JSON.stringify(items, null, 2);
}

describe('getInsertPosition', () => {
    test('single item, cursor inside it → afterIdx = 0', () => {
        const text = makeComposition([{ component: 'hero', props: { title: 'Hi' } }]);
        // Put cursor somewhere in the middle of the first (only) item
        const cursorOff = text.indexOf('"hero"') + 3;
        const { afterIdx, itemEnds } = getInsertPosition(text, cursorOff);
        expect(itemEnds).toHaveLength(1);
        expect(afterIdx).toBe(0);
    });

    test('two items, cursor inside second → afterIdx = 1', () => {
        const text = makeComposition([
            { component: 'hero', props: { title: 'A' } },
            { component: 'faq',  props: { items: [] } },
        ]);
        // Cursor after the second item's closing brace
        const { afterIdx, itemEnds } = getInsertPosition(text, text.length - 2);
        expect(itemEnds).toHaveLength(2);
        expect(afterIdx).toBe(1);
    });

    test('cursor before first item (right after [) → afterIdx = 0 (insert before all)', () => {
        const text = makeComposition([{ component: 'hero', props: { title: 'Hi' } }]);
        const bracketPos = text.indexOf('[');
        // cursor right at the bracket position — before any item ends
        const { afterIdx } = getInsertPosition(text, bracketPos);
        expect(afterIdx).toBe(-1);
    });

    test('empty array ([]) → itemEnds is empty, bracketPos found', () => {
        const text = '[]';
        const { afterIdx, itemEnds, bracketPos } = getInsertPosition(text, 1);
        expect(itemEnds).toHaveLength(0);
        expect(bracketPos).toBe(0);
        expect(afterIdx).toBe(-1);
    });

    test('nested object in props does not produce false item-end entry', () => {
        // A props value that is an object — inner `}` should not be counted as top-level end
        const item = { component: 'hero', props: { title: 'Hi', meta: { key: 'val' } } };
        const text = makeComposition([item]);
        const { itemEnds } = getInsertPosition(text, 0);
        expect(itemEnds).toHaveLength(1);
    });

    test('string value containing } is not counted as depth-closer', () => {
        const item = { component: 'hero', props: { title: 'a}b}c' } };
        const text = makeComposition([item]);
        const { itemEnds } = getInsertPosition(text, 0);
        expect(itemEnds).toHaveLength(1);
    });

    test('two items, cursor in first → afterIdx = 0', () => {
        const text = makeComposition([
            { component: 'hero', props: { title: 'A' } },
            { component: 'faq',  props: { items: [] } },
        ]);
        // Put cursor inside first item (before first item's closing brace)
        const firstItemEnd = text.indexOf('}');
        const cursorOff = firstItemEnd - 2; // just before `}`
        const { afterIdx } = getInsertPosition(text, cursorOff);
        expect(afterIdx).toBe(0);
    });
});

// ─── buildAccordionData ─────────────────────────────────────────────────────

describe('buildAccordionData', () => {
    test('valid JSON with known component — fields merged with schema', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hello' } }]);
        const result = buildAccordionData(json, REGISTRY);
        expect(result.errors).toEqual([]);
        expect(result.components).toHaveLength(1);
        expect(result.components[0].name).toBe('hero');
        // title field should be present and userTouched
        const titleField = result.components[0].fields.find(f => f.name === 'title');
        expect(titleField.value).toBe('Hello');
        expect(titleField.required).toBe(true);
        expect(titleField.userTouched).toBe(true);
    });

    test('schema fields not in JSON get default values and userTouched=false', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi' } }]);
        const result = buildAccordionData(json, REGISTRY);
        const subtitleField = result.components[0].fields.find(f => f.name === 'subheading');
        expect(subtitleField.value).toBe('');
        expect(subtitleField.userTouched).toBe(false);
    });

    test('unknown component — raw props preserved, no schema merge', () => {
        const json = JSON.stringify([{ component: 'ghost', props: { foo: 'bar' } }]);
        const result = buildAccordionData(json, REGISTRY);
        expect(result.errors).toEqual([]);
        expect(result.components).toHaveLength(1);
        expect(result.components[0].name).toBe('ghost');
        expect(result.components[0].fields).toHaveLength(1);
        expect(result.components[0].fields[0].name).toBe('foo');
        expect(result.components[0].fields[0].userTouched).toBe(true);
    });

    test('invalid JSON string — returns errors array', () => {
        const result = buildAccordionData('{broken', REGISTRY);
        expect(result.components).toEqual([]);
        expect(result.errors).toHaveLength(1);
        expect(result.errors[0]).toMatch(/JSON syntax error/);
    });

    test('empty array — returns empty components, no errors', () => {
        const result = buildAccordionData('[]', REGISTRY);
        expect(result.components).toEqual([]);
        expect(result.errors).toEqual([]);
    });

    test('empty/whitespace string — returns empty result', () => {
        const result = buildAccordionData('  ', REGISTRY);
        expect(result.components).toEqual([]);
        expect(result.errors).toEqual([]);
    });

    test('enum field has values array', () => {
        const json = JSON.stringify([{ component: 'section', props: { body: '<p>Hi</p>' } }]);
        const result = buildAccordionData(json, REGISTRY);
        const layoutField = result.components[0].fields.find(f => f.name === 'layout');
        expect(layoutField.type).toBe('enum');
        expect(layoutField.values).toEqual(['text-only', 'image-left', 'image-right', 'centered']);
    });

    test('array field has items sub-schema', () => {
        const json = JSON.stringify([{ component: 'faq', props: { items: [{ question: 'Q?', answer: 'A.' }] } }]);
        const result = buildAccordionData(json, REGISTRY);
        const itemsField = result.components[0].fields.find(f => f.name === 'items');
        expect(itemsField.type).toBe('array');
        expect(itemsField.items).toBeDefined();
        expect(itemsField.items.question).toBeDefined();
    });

    test('prop in JSON but not in schema — preserved as pass-through', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi', custom_thing: 'val' } }]);
        const result = buildAccordionData(json, REGISTRY);
        const customField = result.components[0].fields.find(f => f.name === 'custom_thing');
        expect(customField).toBeDefined();
        expect(customField.value).toBe('val');
        expect(customField.userTouched).toBe(true);
    });

    test('multiline flag set for body field', () => {
        const json = JSON.stringify([{ component: 'section', props: { body: '<p>text</p>' } }]);
        const result = buildAccordionData(json, REGISTRY);
        const bodyField = result.components[0].fields.find(f => f.name === 'body');
        expect(bodyField.multiline).toBe(true);
        const titleField = result.components[0].fields.find(f => f.name === 'title');
        expect(titleField.multiline).toBe(false);
    });

    test('multiline flag set for answer field in faq items sub-schema', () => {
        const json = JSON.stringify([{ component: 'faq', props: { items: [] } }]);
        const result = buildAccordionData(json, REGISTRY);
        // answer is inside items sub-schema, not a top-level field
        // multiline detection is on top-level field names only
        const itemsField = result.components[0].fields.find(f => f.name === 'items');
        expect(itemsField.multiline).toBe(false); // items itself is not multiline
    });
});

// ─── serializeAccordionData ─────────────────────────────────────────────────

describe('getCollapsedRowPreview (#76)', () => {
    test('grid: uses the optional title field as the collapsed-row label', () => {
        const json = JSON.stringify([{ component: 'grid', props: { title: 'Our WordPress Services', items: [] } }]);
        const result = buildAccordionData(json, REGISTRY);
        expect(getCollapsedRowPreview(result.components[0])).toBe('Our WordPress Services');
    });

    test('grid: falls back to empty string when no title is set', () => {
        const json = JSON.stringify([{ component: 'grid', props: { items: [] } }]);
        const result = buildAccordionData(json, REGISTRY);
        expect(getCollapsedRowPreview(result.components[0])).toBe('');
    });

    test('hero: existing required-title behavior is preserved', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Reliable AI work' } }]);
        const result = buildAccordionData(json, REGISTRY);
        expect(getCollapsedRowPreview(result.components[0])).toBe('Reliable AI work');
    });

    test('faq: optional title field also now shows as the label', () => {
        const json = JSON.stringify([{ component: 'faq', props: { title: 'Common Questions', items: [] } }]);
        const result = buildAccordionData(json, REGISTRY);
        expect(getCollapsedRowPreview(result.components[0])).toBe('Common Questions');
    });

    test('footer: no title field at all — falls back to empty string, not a crash', () => {
        const json = JSON.stringify([{ component: 'footer', props: {} }]);
        const result = buildAccordionData(json, REGISTRY);
        expect(getCollapsedRowPreview(result.components[0])).toBe('');
    });

    test('a component whose title has a non-empty schema default shows that default when unset (intentional)', () => {
        // Real faq/schema.json declares default: "Frequently Asked Questions",
        // which components/faq/faq.php also uses as its own render fallback
        // (props['title'] ?? 'Frequently Asked Questions') — so surfacing it
        // here as the collapsed-row label accurately reflects what actually
        // renders on the page, rather than showing a blank/no-op "faq" row
        // (adversarial review finding, confirmed intentional against the
        // real component's render behavior, not a bug).
        const FAQ_WITH_DEFAULT = {
            name: 'faq',
            schema: { props: { title: { type: 'string', required: false, default: 'Frequently Asked Questions' } } },
        };
        const json = JSON.stringify([{ component: 'faq', props: {} }]);
        const result = buildAccordionData(json, [FAQ_WITH_DEFAULT]);
        expect(getCollapsedRowPreview(result.components[0])).toBe('Frequently Asked Questions');
    });

    test('truncates long values at 40 characters with an ellipsis', () => {
        const longTitle = 'A'.repeat(50);
        const json = JSON.stringify([{ component: 'grid', props: { title: longTitle, items: [] } }]);
        const result = buildAccordionData(json, REGISTRY);
        const preview = getCollapsedRowPreview(result.components[0]);
        expect(preview).toBe('A'.repeat(40) + '…');
    });

    test('a value exactly 40 characters long is returned verbatim, no ellipsis', () => {
        const exactTitle = 'A'.repeat(40);
        const json = JSON.stringify([{ component: 'grid', props: { title: exactTitle, items: [] } }]);
        const result = buildAccordionData(json, REGISTRY);
        expect(getCollapsedRowPreview(result.components[0])).toBe(exactTitle);
    });

    test('a value one character over 40 is truncated to 40 + ellipsis', () => {
        const overTitle = 'A'.repeat(41);
        const json = JSON.stringify([{ component: 'grid', props: { title: overTitle, items: [] } }]);
        const result = buildAccordionData(json, REGISTRY);
        expect(getCollapsedRowPreview(result.components[0])).toBe('A'.repeat(40) + '…');
    });

    test('missing or malformed fields array returns empty string, not a crash', () => {
        expect(getCollapsedRowPreview({})).toBe('');
        expect(getCollapsedRowPreview({ fields: [] })).toBe('');
        expect(getCollapsedRowPreview(null)).toBe('');
    });

    test('a non-title required field is preferred over no title field (unknown component)', () => {
        // Constructed directly (not via a schema) to prove the fallback path
        // still works for components with no 'title' field but a different
        // required string field.
        const compData = {
            fields: [
                { name: 'body', type: 'string', required: true, value: 'Some body text' },
                { name: 'theme', type: 'enum', required: false, value: 'default' },
            ],
        };
        expect(getCollapsedRowPreview(compData)).toBe('Some body text');
    });

    test('empty title string is treated as no title (falls through to required field)', () => {
        const compData = {
            fields: [
                { name: 'title', type: 'string', required: false, value: '' },
                { name: 'body', type: 'string', required: true, value: 'Fallback text' },
            ],
        };
        expect(getCollapsedRowPreview(compData)).toBe('Fallback text');
    });
});

describe('serializeAccordionData', () => {
    test('round-trip: hero parse→build→serialize preserves user-touched props', () => {
        const original = [{ component: 'hero', props: { title: 'Hello', subheading: 'World' } }];
        const json = JSON.stringify(original);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(reparsed[0].component).toBe('hero');
        expect(reparsed[0].props.title).toBe('Hello');
        expect(reparsed[0].props.subheading).toBe('World');
    });

    test('round-trip: faq with array items', () => {
        const original = [{ component: 'faq', props: { items: [{ question: 'Q?', answer: 'A.' }] } }];
        const json = JSON.stringify(original);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(reparsed[0].props.items).toEqual([{ question: 'Q?', answer: 'A.' }]);
    });

    test('user-touched empty string — preserved in output', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi', subheading: '' } }]);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect('subheading' in reparsed[0].props).toBe(true);
        expect(reparsed[0].props.subheading).toBe('');
    });

    test('schema-default never touched — omitted from output', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi' } }]);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        // subheading was not in original JSON, so userTouched=false, should be omitted
        expect('subheading' in reparsed[0].props).toBe(false);
    });

    test('empty array items — preserved', () => {
        const json = JSON.stringify([{ component: 'faq', props: { items: [] } }]);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(reparsed[0].props.items).toEqual([]);
    });

    test('pretty-prints with 2-space indent', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi' } }]);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        expect(serialized).toContain('\n');
        expect(serialized).toMatch(/^  /m); // 2-space indent on some line
    });

    test('multiple components serialized in order', () => {
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'First' } },
            { component: 'faq', props: { items: [] } },
        ]);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(reparsed).toHaveLength(2);
        expect(reparsed[0].component).toBe('hero');
        expect(reparsed[1].component).toBe('faq');
    });

    test('round-trip: grid with multiple items preserves all sub-fields', () => {
        const original = [{
            component: 'grid',
            props: {
                title: 'Features',
                items: [
                    { title: 'Fast', text: 'Lightning speed', image_url: '/img/fast.png', link_url: '/fast' },
                    { title: 'Safe', text: 'Enterprise security', image_url: '/img/safe.png', link_url: '/safe' },
                    { title: 'Easy', text: 'One-click setup', image_url: '/img/easy.png', link_url: '/easy' },
                ],
            },
        }];
        const json = JSON.stringify(original);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(reparsed[0].props.items).toHaveLength(3);
        expect(reparsed[0].props.items[0]).toEqual({ title: 'Fast', text: 'Lightning speed', image_url: '/img/fast.png', link_url: '/fast' });
        expect(reparsed[0].props.items[1]).toEqual({ title: 'Safe', text: 'Enterprise security', image_url: '/img/safe.png', link_url: '/safe' });
        expect(reparsed[0].props.items[2]).toEqual({ title: 'Easy', text: 'One-click setup', image_url: '/img/easy.png', link_url: '/easy' });
    });

    test('round-trip: hero + grid mixed — hero edit does not lose grid items', () => {
        const original = [
            { component: 'hero', props: { title: 'Welcome', subheading: 'To our site' } },
            { component: 'grid', props: { items: [{ title: 'Card 1', text: 'Content 1' }, { title: 'Card 2', text: 'Content 2' }] } },
        ];
        const json = JSON.stringify(original);
        const data = buildAccordionData(json, REGISTRY);
        // Simulate editing the hero title
        data.components[0].fields.find(f => f.name === 'title').value = 'New Welcome';
        data.components[0].fields.find(f => f.name === 'title').userTouched = true;
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(reparsed[0].props.title).toBe('New Welcome');
        expect(reparsed[1].props.items).toEqual([{ title: 'Card 1', text: 'Content 1' }, { title: 'Card 2', text: 'Content 2' }]);
    });
});

// ─── wouldLoseArrayData ──────────────────────────────────────────────────────

describe('wouldLoseArrayData', () => {
    test('all new items empty + originals had content → true', () => {
        const newItems = [{}, {}, {}];
        const origItems = [{ question: 'Q?', answer: 'A.' }, { question: 'Q2?', answer: 'A2.' }, { question: 'Q3?', answer: 'A3.' }];
        expect(wouldLoseArrayData(newItems, origItems)).toBe(true);
    });

    test('new items have content (normal edit) → false', () => {
        const newItems = [{ question: 'Updated Q', answer: 'Updated A' }];
        const origItems = [{ question: 'Q?', answer: 'A.' }];
        expect(wouldLoseArrayData(newItems, origItems)).toBe(false);
    });

    test('originals were already empty array → false', () => {
        const newItems = [{}, {}];
        const origItems = [];
        expect(wouldLoseArrayData(newItems, origItems)).toBe(false);
    });

    test('originals undefined (new component, no prior data) → false', () => {
        const newItems = [{}, {}];
        expect(wouldLoseArrayData(newItems, undefined)).toBe(false);
    });

    // Removing every row through the button does NOT arrive here: that handler
    // splices the JSON buffer and re-renders, so the next sync already compares
    // against a stored `[]`. A zero-row read while rows are still stored is a
    // failed read, and the guard now says so. The end-to-end removal path is
    // pinned in pp-editor-form-sync.test.js.
    test('items array is empty while originals had content → true', () => {
        const newItems = [];
        const origItems = [{ question: 'Q?', answer: 'A.' }];
        expect(wouldLoseArrayData(newItems, origItems)).toBe(true);
    });

    test('items array is empty and originals were too → false', () => {
        expect(wouldLoseArrayData([], [])).toBe(false);
        expect(wouldLoseArrayData([], undefined)).toBe(false);
    });

    // A MIXED read — one row carrying content, one keyless — is not a
    // whole-field question: refusing the field would discard the content the
    // author typed into the other row. reconcileArrayItems settles it per row.
    test('a mixed read is left to the per-row merge, not refused wholesale', () => {
        expect(wouldLoseArrayData([{ question: 'kept' }, {}], [{ question: 'kept' }, 7])).toBe(false);
    });
});

// ─── reconcileArrayItems ────────────────────────────────────────────────────

describe('reconcileArrayItems', () => {
    test('keeps a row whose stored item held keys that got no control', () => {
        expect(reconcileArrayItems([{ question: '', answer: '' }], [{ legacy: 'kept' }]))
            .toEqual({ items: [{ legacy: 'kept' }], restored: [0] });
    });

    test('keeps a row whose stored item was not an object at all', () => {
        expect(reconcileArrayItems([{ question: '' }], [7]))
            .toEqual({ items: [7], restored: [0] });
    });

    test('takes the read when every stored key was on screen to be cleared', () => {
        expect(reconcileArrayItems([{ question: '' }], [{ question: 'Q?' }]))
            .toEqual({ items: [{ question: '' }], restored: [] });
    });

    test('never fabricates: a row the author typed into always wins', () => {
        expect(reconcileArrayItems([{ question: 'typed' }], [{ legacy: 'kept' }]))
            .toEqual({ items: [{ question: 'typed' }], restored: [] });
    });

    test('settles each row on its own', () => {
        expect(reconcileArrayItems(
            [{ question: '' }, { question: 'edited' }],
            [7, { question: 'Q2' }]
        )).toEqual({ items: [7, { question: 'edited' }], restored: [0] });
    });

    test('some items empty, others have content (partial edit) → false', () => {
        const newItems = [{ question: 'Edited Q' }, {}, { question: 'Another Q', answer: 'Another A' }];
        const origItems = [{ question: 'Q1' }, { question: 'Q2' }, { question: 'Q3', answer: 'A3' }];
        expect(wouldLoseArrayData(newItems, origItems)).toBe(false);
    });
});

// ─── extraKeys pass-through ─────────────────────────────────────────────────

describe('extraKeys pass-through', () => {
    test('composition with style key survives round-trip', () => {
        const original = [{ component: 'hero', props: { title: 'Hi' }, style: { background: '#000' } }];
        const json = JSON.stringify(original);
        const data = buildAccordionData(json, REGISTRY);
        expect(data.components[0].extraKeys).toEqual({ style: { background: '#000' } });
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(reparsed[0].style).toEqual({ background: '#000' });
    });

    test('multiple extra keys preserved', () => {
        const original = [{ component: 'hero', props: { title: 'Hi' }, style: { color: 'red' }, meta: { version: 2 } }];
        const json = JSON.stringify(original);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(reparsed[0].style).toEqual({ color: 'red' });
        expect(reparsed[0].meta).toEqual({ version: 2 });
    });

    test('no extra keys → clean round-trip', () => {
        const original = [{ component: 'hero', props: { title: 'Hi' } }];
        const json = JSON.stringify(original);
        const data = buildAccordionData(json, REGISTRY);
        expect(data.components[0].extraKeys).toEqual({});
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(Object.keys(reparsed[0])).toEqual(['component', 'props']);
    });
});

// ─── deepDiff ───────────────────────────────────────────────────────────────

describe('deepDiff', () => {
    test('identical objects → empty array', () => {
        expect(deepDiff({ a: 1, b: 'x' }, { a: 1, b: 'x' }, '')).toEqual([]);
    });

    test('added key detected', () => {
        const diffs = deepDiff({ a: 1 }, { a: 1, b: 2 }, '');
        expect(diffs).toHaveLength(1);
        expect(diffs[0]).toEqual({ path: 'b', before: undefined, after: 2, changeType: 'added' });
    });

    test('removed key detected', () => {
        const diffs = deepDiff({ a: 1, b: 2 }, { a: 1 }, '');
        expect(diffs).toHaveLength(1);
        expect(diffs[0]).toEqual({ path: 'b', before: 2, after: undefined, changeType: 'removed' });
    });

    test('changed value detected', () => {
        const diffs = deepDiff({ a: 1 }, { a: 2 }, '');
        expect(diffs).toHaveLength(1);
        expect(diffs[0]).toEqual({ path: 'a', before: 1, after: 2, changeType: 'changed' });
    });

    test('nested object diff → dotted path', () => {
        const diffs = deepDiff({ x: { y: 1 } }, { x: { y: 2 } }, '');
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('x.y');
        expect(diffs[0].changeType).toBe('changed');
    });

    test('array item diff → indexed path', () => {
        const diffs = deepDiff([1, 2, 3], [1, 9, 3], '');
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[1]');
        expect(diffs[0].before).toBe(2);
        expect(diffs[0].after).toBe(9);
    });

    test('type mismatch detected (string→array)', () => {
        const diffs = deepDiff({ items: 'not-an-array' }, { items: [] }, '');
        expect(diffs).toHaveLength(1);
        expect(diffs[0].changeType).toBe('type_mismatch');
    });

    test('null handling', () => {
        const diffs = deepDiff({ a: null }, { a: 'value' }, '');
        expect(diffs).toHaveLength(1);
        expect(diffs[0].changeType).toBe('changed');
        expect(diffs[0].before).toBeNull();
    });
});

// ─── checkSerializationInvariant ────────────────────────────────────────────

describe('checkSerializationInvariant', () => {
    test('clean hero composition passes', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hello' } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('clean faq composition passes', () => {
        const json = JSON.stringify([{ component: 'faq', props: { items: [{ question: 'Q?', answer: 'A.' }] } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('clean section composition passes', () => {
        const json = JSON.stringify([{ component: 'section', props: { body: '<p>Hi</p>' } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('clean grid composition passes', () => {
        const json = JSON.stringify([{ component: 'grid', props: { items: [{ title: 'A', text: 'B' }] } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('absent optional fields NOT materialized — invariant passes', () => {
        // hero has optional subheading; omitting it should NOT cause drift
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi' } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('nested arrays round-trip cleanly — invariant passes', () => {
        const json = JSON.stringify([{ component: 'faq', props: { items: [{ question: 'Q?', answer: 'A.' }] } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('schema defaults for absent fields NOT emitted — invariant passes', () => {
        // section has optional layout (default: 'text-only') — omitting it should not cause drift
        const json = JSON.stringify([{ component: 'section', props: { body: '<p>Text</p>' } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('pass-through props (not in schema) survive — invariant passes', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi', custom_flag: true } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('unknown components survive — invariant passes', () => {
        const json = JSON.stringify([{ component: 'ghost', props: { foo: 'bar' } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('empty composition passes', () => {
        const result = checkSerializationInvariant('[]', REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('empty string is safe (no composition yet)', () => {
        expect(checkSerializationInvariant('', REGISTRY).safe).toBe(true);
        expect(checkSerializationInvariant('   ', REGISTRY).safe).toBe(true);
        expect(checkSerializationInvariant(null, REGISTRY).safe).toBe(true);
        expect(checkSerializationInvariant(undefined, REGISTRY).safe).toBe(true);
    });

    test('invalid JSON returns error, not crash', () => {
        const result = checkSerializationInvariant('{broken', REGISTRY);
        expect(result.safe).toBe(false);
        expect(result.error).toBeDefined();
    });

    test('style key round-trip preserved', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi' }, style: { bg: '#000' } }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(true);
    });

    test('missing props key caught — round-trip adds props: {}', () => {
        const json = JSON.stringify([{ component: 'footer' }]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(false);
        expect(result.diffs.some(d => d.path.includes('props') && d.changeType === 'added')).toBe(true);
    });
});

// ─── formatDiffsForIssue ────────────────────────────────────────────────────

describe('formatDiffsForIssue', () => {
    test('single diff → valid markdown with table', () => {
        const diffs = [{ path: '[0].props', before: undefined, after: {}, changeType: 'added' }];
        const md = formatDiffsForIssue(diffs, 'Test Page', 42);
        expect(md).toContain('## Accordion serialization drift');
        expect(md).toContain('Post ID: 42');
        expect(md).toContain('| Component 0 |');
        expect(md).toContain('added');
    });

    test('multiple diffs grouped by component', () => {
        const diffs = [
            { path: '[0].props', before: undefined, after: {}, changeType: 'added' },
            { path: '[1].style', before: { bg: '#000' }, after: undefined, changeType: 'removed' },
        ];
        const md = formatDiffsForIssue(diffs, 'Multi Page', 99);
        expect(md).toContain('Component 0');
        expect(md).toContain('Component 1');
    });

    test('special characters in values escaped for table', () => {
        const diffs = [{ path: '[0].props.text', before: 'a|b', after: 'c|d', changeType: 'changed' }];
        const md = formatDiffsForIssue(diffs, 'Pipe Page', 7);
        // Pipe chars should be escaped in table cells
        expect(md).toContain('\\|');
    });
});

// ─── Shared PHP<->JS validation contract (D5) ────────────────────────────────
//
// Golden fixtures in tests/fixtures/composition-validation-cases.json are
// asserted by BOTH this test and tests/SchemaValidationTest.php. If
// validateCompositionData drifts from pp_validate_composition on any
// shared-contract rule, one side fails. Known intentional asymmetries
// (blank required prop = JS-only stricter; style-slot validation = PHP-only)
// are documented in the fixture and deliberately excluded from this set.
describe('shared PHP<->JS validation contract (D5)', () => {
    const fixture = JSON.parse(
        fs.readFileSync(
            path.resolve(__dirname, '../fixtures/composition-validation-cases.json'),
            'utf-8'
        )
    );
    const registry = fixture.registry;

    test('fixture defines cases', () => {
        expect(Array.isArray(fixture.cases)).toBe(true);
        expect(fixture.cases.length).toBeGreaterThan(0);
    });

    fixture.cases.forEach((c) => {
        test(c.name, () => {
            const errors = validateCompositionData(JSON.stringify(c.composition), registry);
            if (c.expectValid) {
                expect(errors).toEqual([]);
            } else {
                expect(errors.length).toBeGreaterThan(0);
            }
        });
    });
});

// ─── unadvertisedEnumDiffs (#605) ───────────────────────────────────────────
//
// The accordion builds every enum <select> from the schema's advertised `values`.
// A stored value outside that set matches no option, so reading the field back off
// the DOM returns the FIRST option instead — and because syncAccordionToJson()
// reads EVERY field of EVERY component on any input event, one keystroke anywhere
// would rewrite such a band and the save would then pass the strict-enum gate.
// The guard refuses instead of laundering: it reports the drift so the invariant
// check routes the composition into JSON-only mode.

describe('unadvertisedEnumDiffs (#605)', () => {
    it('flags a stored enum value that is no longer advertised', () => {
        const json = JSON.stringify([
            { component: 'section', props: { body: 'x', theme: 'dark' } },
        ]);
        const diffs = unadvertisedEnumDiffs(json, REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[0].props.theme');
        expect(diffs[0].before).toBe('dark');
        // Names what the DOM would actually substitute: the FIRST advertised value.
        expect(diffs[0].after).toBe('default');
    });

    it('is generic — it flags ANY unadvertised enum value, not just theme', () => {
        const json = JSON.stringify([
            { component: 'section', props: { body: 'x', layout: 'split' } },
        ]);
        const diffs = unadvertisedEnumDiffs(json, REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[0].props.layout');
        expect(diffs[0].after).toBe('text-only');
    });

    it('reports the band index so the author can find it', () => {
        const json = JSON.stringify([
            { component: 'section', props: { body: 'ok', theme: 'muted' } },
            { component: 'section', props: { body: 'stale', theme: 'dark' } },
        ]);
        const diffs = unadvertisedEnumDiffs(json, REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[1].props.theme');
    });

    it('does not flag advertised values', () => {
        const json = JSON.stringify([
            { component: 'section', props: { body: 'x', theme: 'muted', layout: 'centered' } },
        ]);
        expect(unadvertisedEnumDiffs(json, REGISTRY)).toEqual([]);
    });

    it('does not flag the unset sentinel (absent, null, or empty string)', () => {
        // The documented way to keep a prop's default. Not drift.
        const absent = JSON.stringify([{ component: 'section', props: { body: 'x' } }]);
        const nulled = JSON.stringify([{ component: 'section', props: { body: 'x', theme: null } }]);
        const empty  = JSON.stringify([{ component: 'section', props: { body: 'x', theme: '' } }]);
        expect(unadvertisedEnumDiffs(absent, REGISTRY)).toEqual([]);
        expect(unadvertisedEnumDiffs(nulled, REGISTRY)).toEqual([]);
        expect(unadvertisedEnumDiffs(empty, REGISTRY)).toEqual([]);
    });

    it('ignores unknown components and malformed JSON rather than throwing', () => {
        expect(unadvertisedEnumDiffs('not json', REGISTRY)).toEqual([]);
        expect(unadvertisedEnumDiffs('{}', REGISTRY)).toEqual([]);
        expect(unadvertisedEnumDiffs(
            JSON.stringify([{ component: 'nope', props: { theme: 'dark' } }]), REGISTRY
        )).toEqual([]);
    });
});

describe('checkSerializationInvariant blocks a stale enum value (#605)', () => {
    it('is UNSAFE when a stored enum value is no longer advertised', () => {
        // THE LOAD-BEARING PIN. Without it the in-memory round-trip reports safe
        // (field.value still holds "dark"), the accordion renders, and the DOM
        // readback silently rewrites the band.
        const json = JSON.stringify([
            { component: 'section', props: { body: 'x', theme: 'dark' } },
        ]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(false);
        expect(result.diffs.some((d) => d.path === '[0].props.theme')).toBe(true);
    });

    it('stays SAFE for a composition whose enum values are all advertised', () => {
        const json = JSON.stringify([
            { component: 'section', props: { body: 'x', theme: 'muted' } },
        ]);
        expect(checkSerializationInvariant(json, REGISTRY).safe).toBe(true);
    });
});

// ─── escapeHtml ──────────────────────────────────────────────────────────────

describe('escapeHtml covers both markup and quote characters', () => {
    it('escapes the markup characters', () => {
        expect(escapeHtml('a<b>c&d')).toBe('a&lt;b&gt;c&amp;d');
    });

    it('escapes both quote characters', () => {
        // The reason this helper exists. jQuery's .text().html() round-trip that
        // preceded it escaped & < > and passed " and ' through unchanged, which is
        // correct for a text node and wrong for a quoted attribute.
        expect(escapeHtml('a"b')).toBe('a&quot;b');
        expect(escapeHtml("a'b")).toBe('a&#39;b');
    });

    it('replaces & first, so escaped output is not double-escaped', () => {
        // Replacing < before & would turn "<" into "&lt;" and then into "&amp;lt;",
        // which renders as the literal text "&lt;" instead of "<".
        expect(escapeHtml('<')).toBe('&lt;');
        expect(escapeHtml('&lt;')).toBe('&amp;lt;');
    });

    it('leaves a value with no special characters byte-identical', () => {
        const benign = 'Turn AI-assisted site drafts into maintainable WordPress composition.';
        expect(escapeHtml(benign)).toBe(benign);
    });

    it('leaves representative style slot and component names unchanged', () => {
        // The identical-rendering guarantee over a sample of the real vocabulary,
        // including the longest slot name the registry declares. The registry-wide
        // version of this guarantee is pinned PHP-side, where the registry is
        // readable (ActionsTest::testEveryComponentsValidatorMessageReachesTheAuthorIntact).
        ['hero', 'section', 'grid', 'cta', '--hero-bg', '--section-padding-block',
         '--testimonials-subheading-margin-bottom', 'title', 'body_items', 'image_url']
            .forEach((name) => expect(escapeHtml(name)).toBe(name));
    });

    it('coerces non-string input instead of throwing', () => {
        // The predecessor threw on undefined: jQuery treats .text(undefined) as a
        // getter, which returns a string, and a string has no .html().
        expect(escapeHtml(undefined)).toBe('');
        expect(escapeHtml(null)).toBe('');
        expect(escapeHtml(0)).toBe('0');
        expect(escapeHtml(false)).toBe('false');
    });

    it('produces output that cannot terminate a quoted attribute', () => {
        const hostile = '" data-comp="9';
        expect(escapeHtml(hostile).indexOf('"')).toBe(-1);
    });
});
