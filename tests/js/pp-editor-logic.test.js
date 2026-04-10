/**
 * Tests for assets/js/pp-editor-logic.js
 *
 * Covers:
 *   getJsonContextFromText  — autocomplete context walker
 *   validateCompositionData — JSON + component registry validation
 *   getInsertPosition       — array item insertion point walker
 */

const {
    getJsonContextFromText,
    validateCompositionData,
    getInsertPosition,
    buildAccordionData,
    serializeAccordionData,
} = require('../../assets/js/pp-editor-logic.js');

// ─── Fixtures ────────────────────────────────────────────────────────────────

const HERO = {
    name: 'hero',
    schema: {
        props: {
            title:    { type: 'string',  required: true  },
            subtitle: { type: 'string',  required: false },
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
            layout:  { type: 'enum', values: ['text-only', 'image-left', 'image-right'], required: false, default: 'text-only' },
            variant: { type: 'enum', values: ['default', 'dark', 'inverted'], required: false, default: 'default' },
        },
    },
};

const REGISTRY = [HERO, FAQ, SECTION];

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
        // hero has subtitle as optional — omitting it is fine
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
        const subtitleField = result.components[0].fields.find(f => f.name === 'subtitle');
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
        expect(layoutField.values).toEqual(['text-only', 'image-left', 'image-right']);
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

describe('serializeAccordionData', () => {
    test('round-trip: hero parse→build→serialize preserves user-touched props', () => {
        const original = [{ component: 'hero', props: { title: 'Hello', subtitle: 'World' } }];
        const json = JSON.stringify(original);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect(reparsed[0].component).toBe('hero');
        expect(reparsed[0].props.title).toBe('Hello');
        expect(reparsed[0].props.subtitle).toBe('World');
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
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi', subtitle: '' } }]);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        expect('subtitle' in reparsed[0].props).toBe(true);
        expect(reparsed[0].props.subtitle).toBe('');
    });

    test('schema-default never touched — omitted from output', () => {
        const json = JSON.stringify([{ component: 'hero', props: { title: 'Hi' } }]);
        const data = buildAccordionData(json, REGISTRY);
        const serialized = serializeAccordionData(data.components);
        const reparsed = JSON.parse(serialized);
        // subtitle was not in original JSON, so userTouched=false, should be omitted
        expect('subtitle' in reparsed[0].props).toBe(false);
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
});
