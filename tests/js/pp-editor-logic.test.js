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
    nonStringValueDiffs,
    nonContainerValueDiffs,
    satisfiesContainerDeclaration,
    subFieldIsDisplayOnly,
    subFieldIsTypedScalar,
    reconcileSubFieldTypes,
    displayOnlySubKeys,
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

/**
 * Non-string shapes the #745 walk must NOT report: an items[] sub-key declaring
 * `number` (mirroring the shipped grid/logos/testimonials `items[].image_id`), and
 * an array-typed prop with no `items` sub-schema at all. Both are boundaries the
 * docblock states explicitly, and neither is reachable from the string-only
 * fixtures above.
 */
const NUMERIC = {
    name: 'numeric',
    schema: {
        props: {
            items: {
                type: 'array', required: false,
                items: {
                    label: { type: 'string', required: false },
                    count: { type: 'number', required: false },
                },
            },
            loose: { type: 'array', required: false },
        },
    },
};

/**
 * The CONTAINER sub-key shapes, mirroring what components/grid/schema.json ships:
 * `items[].bullets` declaring `array` and `items[].style` declaring `object`,
 * beside a `number` and a `string`. Before #805 all four rendered as text
 * controls and read back as text; now the two containers render read-only and
 * the number is settled against what it was rendered from.
 */
const CONTAINER = {
    name: 'container',
    schema: {
        props: {
            title: { type: 'string', required: false },
            items: {
                type: 'array', required: false,
                items: {
                    title:    { type: 'string', required: false },
                    bullets:  { type: 'array',  required: false },
                    style:    { type: 'object', required: false },
                    image_id: { type: 'number', required: false },
                },
            },
        },
    },
};

const REGISTRY = [HERO, FAQ, SECTION, GRID, FOOTER, NUMERIC, CONTAINER];

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

// ─── nonStringValueDiffs (#745) ─────────────────────────────────────────────
//
// The second member of the same class as #605 above. A prop the schema declares
// `type: "string"` gets a text control carrying escapeHtml(value), which is
// String(value); syncAccordionToJson reads it back with .val(), so a stored
// non-string comes back as its own TEXT. The measured case that motivated this:
// `section.panel_cta_url: false` renders a dead button, and one keystroke anywhere
// in the accordion turns it into the string "false" — which #707 accepts at write,
// because a scheme-less path is a legal link — so the band silently starts pointing
// at /false. The in-memory round-trip cannot see it (buildAccordionData and
// serializeAccordionData both keep props[key] verbatim), which is why the check
// has to be stated separately rather than falling out of deepDiff.

describe('nonStringValueDiffs (#745)', () => {
    const withTitle = (value) =>
        JSON.stringify([{ component: 'hero', props: { title: value } }]);

    it('flags the measured case: a stored boolean under a string-typed prop', () => {
        const diffs = nonStringValueDiffs(withTitle(false), REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[0].props.title');
        expect(diffs[0].before).toBe(false);
        // The value the DOM read would actually put in the buffer.
        expect(diffs[0].after).toBe('false');
        expect(diffs[0].changeType).toBe('changed');
    });

    it('is generic — every non-string shape, each reported as the text it becomes', () => {
        // `after` is asserted per shape rather than "some string": the notice shows
        // this to the author as what the accordion would do, so a wrong value there
        // is a wrong claim, not a cosmetic slip. `[object Object]` and `1,2` are the
        // two that are least obvious, and both are what String() really produces.
        const CASES = [
            [true,      'true'],
            [0,         '0'],
            [42,        '42'],
            [-1.5,      '-1.5'],
            [[1, 2],    '1,2'],
            [{ a: 1 },  '[object Object]'],
        ];
        CASES.forEach(([stored, becomes]) => {
            const diffs = nonStringValueDiffs(withTitle(stored), REGISTRY);
            expect(diffs).toHaveLength(1);
            expect(diffs[0].after).toBe(becomes);
        });
    });

    it('does not flag a string, however empty', () => {
        expect(nonStringValueDiffs(withTitle('Hello'), REGISTRY)).toEqual([]);
        expect(nonStringValueDiffs(withTitle(''), REGISTRY)).toEqual([]);
    });

    it('does not flag null, the unset sentinel #707 carves out', () => {
        // null renders empty and reads back as '', so it does NOT survive either.
        // It is still not drift: null and '' are two spellings of "leave this prop
        // on its default", so the rewrite is within one meaning. Pinned so the
        // carve-out is a decision on record rather than an oversight.
        expect(nonStringValueDiffs(withTitle(null), REGISTRY)).toEqual([]);
    });

    it('does not flag an absent prop', () => {
        const json = JSON.stringify([{ component: 'hero', props: { subheading: 'x' } }]);
        expect(nonStringValueDiffs(json, REGISTRY)).toEqual([]);
    });

    it('reports the band index so the author can find it', () => {
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'fine' } },
            { component: 'hero', props: { title: false } },
        ]);
        const diffs = nonStringValueDiffs(json, REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[1].props.title');
    });

    it('flags a nested items[] sub-field the schema declares type string', () => {
        const json = JSON.stringify([
            { component: 'grid', props: { items: [{ title: 'ok' }, { link_url: false }] } },
        ]);
        const diffs = nonStringValueDiffs(json, REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[0].props.items[1].link_url');
        expect(diffs[0].after).toBe('false');
    });

    it('reports both depths of the same composition together', () => {
        const json = JSON.stringify([
            { component: 'grid', props: { title: 0, items: [{ image_url: true }] } },
        ]);
        const paths = nonStringValueDiffs(json, REGISTRY).map((d) => d.path).sort();
        expect(paths).toEqual(['[0].props.items[0].image_url', '[0].props.title']);
    });

    // ── Boundaries: values another guard already settles, or another rule owns ──

    it('leaves enum props to unadvertisedEnumDiffs, so one value is not reported twice', () => {
        const json = JSON.stringify([
            { component: 'section', props: { body: 'x', theme: false } },
        ]);
        expect(nonStringValueDiffs(json, REGISTRY)).toEqual([]);
    });

    it('does not descend into a non-array under an array-typed prop', () => {
        // wouldLoseArrayData already fires here: the prop renders no rows at all,
        // so the sync leaves it alone. Reporting it as drift too would lock the
        // whole page over a value that is already safe.
        const json = JSON.stringify([{ component: 'grid', props: { items: 'not a list' } }]);
        expect(nonStringValueDiffs(json, REGISTRY)).toEqual([]);
    });

    it('does not descend into a non-object row', () => {
        // reconcileArrayItems restores that row index on its own.
        const json = JSON.stringify([
            { component: 'grid', props: { items: ['plain', 42, null] } },
        ]);
        expect(nonStringValueDiffs(json, REGISTRY)).toEqual([]);
    });

    it('ignores a row sub-key the schema does not declare', () => {
        const json = JSON.stringify([
            { component: 'grid', props: { items: [{ mystery: false }] } },
        ]);
        expect(nonStringValueDiffs(json, REGISTRY)).toEqual([]);
    });

    it('ignores a row sub-key declaring a type other than string', () => {
        // The `sub.type !== 'string'` boundary. Without a non-string sub-key in some
        // fixture this branch is never taken, and weakening the guard to `if (!sub)
        // return;` would pass every other case in this file. NUMERIC_ITEMS mirrors
        // the shipped grid/logos/testimonials `items[].image_id` declaration.
        const json = JSON.stringify([
            { component: 'numeric', props: { items: [{ label: 'A', count: 0 }] } },
        ]);
        expect(nonStringValueDiffs(json, REGISTRY)).toEqual([]);
    });

    it('ignores an array-typed prop that declares no items sub-schema', () => {
        // The `!def.items` boundary. Rows under such a prop render no sub-field
        // controls at all, so there is no text control to launder anything.
        const json = JSON.stringify([
            { component: 'numeric', props: { loose: [{ anything: false }] } },
        ]);
        expect(nonStringValueDiffs(json, REGISTRY)).toEqual([]);
    });

    it('ignores an array-valued row, not just a scalar one', () => {
        // isPlainObject rejects arrays as well as primitives. A weaker
        // `typeof row === 'object' && row !== null` check would accept an array row
        // and start walking its numeric indices, so the array case is what makes
        // that call load-bearing.
        const json = JSON.stringify([
            { component: 'grid', props: { items: [['a', 'b']] } },
        ]);
        expect(nonStringValueDiffs(json, REGISTRY)).toEqual([]);
    });

    it('ignores a band with no props and a registry entry with no schema', () => {
        expect(nonStringValueDiffs(
            JSON.stringify([{ component: 'hero' }]), REGISTRY
        )).toEqual([]);
        expect(nonStringValueDiffs(
            JSON.stringify([{ component: 'bare', props: { title: false } }]),
            REGISTRY.concat([{ name: 'bare' }])
        )).toEqual([]);
    });

    it('ignores an undeclared top-level prop', () => {
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'x', mystery: false } },
        ]);
        expect(nonStringValueDiffs(json, REGISTRY)).toEqual([]);
    });

    it('does not throw on a value whose own toString is not callable', () => {
        // `String({toString: 'x'})` raises TypeError: ToPrimitive finds a non-callable
        // own `toString`, falls through to Object.prototype.valueOf, and gets a
        // non-primitive back. That value is ordinary JSON, so it is reachable as
        // stored content — and the boot call in pp-admin-editor.js is NOT wrapped, so
        // a throw here would leave the editor with neither an accordion nor a notice
        // on exactly the page this check exists to get repaired.
        const hostile = JSON.parse('{"toString":"x"}');
        const json = JSON.stringify([{ component: 'hero', props: { title: hostile } }]);

        expect(() => String(hostile)).toThrow(TypeError);   // the hazard is real
        const diffs = nonStringValueDiffs(json, REGISTRY);  // the guard survives it
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[0].props.title');
        expect(typeof diffs[0].after).toBe('string');
        // And it still refuses, which is the part that matters.
        expect(checkSerializationInvariant(json, REGISTRY).safe).toBe(false);
    });

    it('ignores unknown components and malformed JSON rather than throwing', () => {
        expect(nonStringValueDiffs('not json', REGISTRY)).toEqual([]);
        expect(nonStringValueDiffs('{}', REGISTRY)).toEqual([]);
        expect(nonStringValueDiffs('', REGISTRY)).toEqual([]);
        expect(nonStringValueDiffs(
            JSON.stringify([{ component: 'nope', props: { title: false } }]), REGISTRY
        )).toEqual([]);
        expect(nonStringValueDiffs(
            JSON.stringify([null, 'x', { props: { title: false } }]), REGISTRY
        )).toEqual([]);
    });
});

// The registries above are hand-written, so every assertion so far has been made
// against a shape this file chose. That is fine for the RULE, and wrong for the
// SCHEMA CONTRACT the rule reads: `def.items[sk].type` is an assumption about
// what components/*/schema.json actually ships, and a hand-written fixture agrees
// with it by construction. So this block builds the registry the way lib/admin.php
// does — the whole schema, verbatim — and asserts the guard against real props.
describe('nonStringValueDiffs against the real shipped schemas (#745)', () => {
    const REAL_REGISTRY = fs
        .readdirSync(path.resolve(__dirname, '../../components'), { withFileTypes: true })
        .filter((d) => d.isDirectory())
        .map((d) => ({
            name: d.name,
            file: path.resolve(__dirname, '../../components', d.name, 'schema.json'),
        }))
        .filter((c) => fs.existsSync(c.file))
        .map((c) => ({ name: c.name, schema: JSON.parse(fs.readFileSync(c.file, 'utf-8')) }));

    test('the real registry loaded', () => {
        expect(REAL_REGISTRY.length).toBeGreaterThan(5);
    });

    it('flags the exact prop and value the issue measured: section.panel_cta_url = false', () => {
        // Not a stand-in. This is the shipped declaration
        // (components/section/schema.json: {"type":"string","format":"link_url"}) and
        // the value the v1.15.0 smoke measured in real stored content. Laundered to
        // "false" it passes _pp_link_url_is_valid(), so the dead button becomes a
        // live link to /false.
        const json = JSON.stringify([
            { component: 'section', props: { body: 'Copy', panel_cta_url: false } },
        ]);
        const diffs = nonStringValueDiffs(json, REAL_REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[0].props.panel_cta_url');
        expect(diffs[0].after).toBe('false');
    });

    it('flags a real nested items[] string field', () => {
        // grid.items[].link_url ships as {"type":"string"} inside the `items` map,
        // which is the nested shape the walk reads. If a schema ever expressed row
        // fields another way, this fails and the hand-written fixtures would not.
        const json = JSON.stringify([
            { component: 'grid', props: { items: [{ title: 'A', link_url: 0 }] } },
        ]);
        const diffs = nonStringValueDiffs(json, REAL_REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[0].props.items[0].link_url');
    });

    it('the nested shape it reads is the shape every shipped schema actually uses', () => {
        // The structural premise, asserted directly rather than inferred from the two
        // cases above: wherever a schema declares `items`, it is a map of sub-key to
        // a spec object carrying a `type` string. buildArrayFieldHtml reads it the
        // same way, so a schema that broke this would break the renderer too.
        let checked = 0;
        REAL_REGISTRY.forEach((c) => {
            const props = (c.schema || {}).props || {};
            Object.keys(props).forEach((p) => {
                const items = props[p].items;
                if (!items) return;
                expect(typeof items).toBe('object');
                expect(Array.isArray(items)).toBe(false);
                Object.keys(items).forEach((sk) => {
                    expect(typeof items[sk]).toBe('object');
                    expect(typeof items[sk].type).toBe('string');
                    checked += 1;
                });
            });
        });
        expect(checked).toBeGreaterThan(10);
    });

    it('does not flag any shipped schema default', () => {
        // Every declared default must itself satisfy its declaration, or the guard
        // would fire on a composition that merely wrote a prop to its own default.
        REAL_REGISTRY.forEach((c) => {
            const props = (c.schema || {}).props || {};
            Object.keys(props).forEach((p) => {
                if (props[p].type !== 'string' || props[p].default === undefined) return;
                const json = JSON.stringify([
                    { component: c.name, props: { [p]: props[p].default } },
                ]);
                expect(nonStringValueDiffs(json, REAL_REGISTRY)).toEqual([]);
            });
        });
    });
});

describe('checkSerializationInvariant blocks a stored non-string value (#745)', () => {
    it('is UNSAFE when a string-typed prop holds a boolean', () => {
        // THE LOAD-BEARING PIN. Without it the in-memory round-trip reports safe
        // (field.value still holds `false`), the accordion renders, and the DOM
        // readback rewrites the band to the string "false" on the next keystroke.
        const json = JSON.stringify([
            { component: 'hero', props: { title: false } },
        ]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(false);
        expect(result.diffs.some((d) => d.path === '[0].props.title')).toBe(true);
    });

    it('is UNSAFE when a nested items[] string field holds a boolean', () => {
        const json = JSON.stringify([
            { component: 'grid', props: { items: [{ link_url: false }] } },
        ]);
        const result = checkSerializationInvariant(json, REGISTRY);
        expect(result.safe).toBe(false);
        expect(result.diffs.some((d) => d.path === '[0].props.items[0].link_url')).toBe(true);
    });

    it('stays SAFE for a well-formed composition, at both depths', () => {
        const json = JSON.stringify([
            { component: 'hero', props: { title: 'T', subheading: '' } },
            { component: 'grid', props: { title: null, items: [{ title: 'A', link_url: '/x' }] } },
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

// ─── #805: row sub-fields the controls cannot round-trip ─────────────────────
//
// Two halves, and the split is the whole design:
//
//   the CONTROL half   a sub-key declaring a container renders read-only and is
//                      never read back, so a WELL-FORMED list or map survives a
//                      sync and the page keeps its accordion
//   the GUARD half     a stored value that VIOLATES an array/object declaration
//                      is drift in #745's sense — the write path refuses it too
//                      (#744) — so the composition routes to JSON-only mode

const withItems = (items) =>
    JSON.stringify([{ component: 'container', props: { items } }]);

describe('satisfiesContainerDeclaration (#805)', () => {
    it('accepts the two unset sentinels the write path accepts', () => {
        // Mirrors _pp_schema_container_value_is_valid() (lib/admin.php): a guard
        // that disagreed with the write path about the sentinel would lock the
        // accordion on pages that save perfectly well.
        expect(satisfiesContainerDeclaration(null)).toBe(true);
        expect(satisfiesContainerDeclaration('')).toBe(true);
    });

    it('accepts a list and a map alike', () => {
        // One test for both declared types, because PHP has one shape for both:
        // a JSON list and a JSON map both decode to a PHP array.
        expect(satisfiesContainerDeclaration([])).toBe(true);
        expect(satisfiesContainerDeclaration(['a'])).toBe(true);
        expect(satisfiesContainerDeclaration({})).toBe(true);
        expect(satisfiesContainerDeclaration({ a: 1 })).toBe(true);
    });

    it('refuses every scalar', () => {
        ['text', 0, 42, false, true].forEach((v) => {
            expect(satisfiesContainerDeclaration(v)).toBe(false);
        });
    });
});

describe('the sub-field class rule is total (#805)', () => {
    it('sends only array and object to the read-only display', () => {
        expect(subFieldIsDisplayOnly({ type: 'array' })).toBe(true);
        expect(subFieldIsDisplayOnly({ type: 'object' })).toBe(true);
        ['string', 'enum', 'number', 'boolean'].forEach((t) => {
            expect(subFieldIsDisplayOnly({ type: t })).toBe(false);
        });
    });

    it('never calls a container a typed scalar', () => {
        // The two predicates are exported and a caller may ask either first, so
        // their mutual exclusivity has to be asserted rather than inferred from the
        // one call site that happens to ask in a safe order.
        expect(subFieldIsTypedScalar({ type: 'array' })).toBe(false);
        expect(subFieldIsTypedScalar({ type: 'object' })).toBe(false);
    });

    it('leaves string and enum on the plain text control', () => {
        // enum stays where it was deliberately: a nested enum renders as free
        // text today and the authoring gap is #646's, not this change's.
        expect(subFieldIsTypedScalar({ type: 'string' })).toBe(false);
        expect(subFieldIsTypedScalar({ type: 'enum' })).toBe(false);
    });

    it('treats every other declared type as a typed scalar', () => {
        expect(subFieldIsTypedScalar({ type: 'number' })).toBe(true);
        expect(subFieldIsTypedScalar({ type: 'boolean' })).toBe(true);
        // A type name nobody has written yet gets the conservative answer rather
        // than falling through to "read it back as text".
        expect(subFieldIsTypedScalar({ type: 'duration' })).toBe(true);
    });

    it('claims nothing about an undeclared sub-key', () => {
        [undefined, null, {}].forEach((def) => {
            expect(subFieldIsDisplayOnly(def)).toBe(false);
            expect(subFieldIsTypedScalar(def)).toBe(false);
        });
    });
});

describe('nonContainerValueDiffs (#805)', () => {
    it('reports a scalar stored under an array-declaring sub-key', () => {
        const diffs = nonContainerValueDiffs(withItems([{ bullets: 'one,two' }]), REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[0].props.items[0].bullets');
        expect(diffs[0].before).toBe('one,two');
    });

    it('reports a scalar stored under an object-declaring sub-key', () => {
        const diffs = nonContainerValueDiffs(withItems([{ style: '[object Object]' }]), REGISTRY);
        expect(diffs).toHaveLength(1);
        expect(diffs[0].path).toBe('[0].props.items[0].style');
    });

    it('says nothing about a well-formed list or map', () => {
        // The premise the mechanism rests on. Reporting these would lock every
        // correct page using two shipped features out of the accordion forever.
        expect(nonContainerValueDiffs(
            withItems([{ bullets: ['one', 'two'], style: { '--x': '#fff' } }]), REGISTRY,
        )).toEqual([]);
    });

    it('says nothing about the unset sentinels', () => {
        expect(nonContainerValueDiffs(withItems([{ bullets: '', style: null }]), REGISTRY)).toEqual([]);
    });

    it('never reports a number sub-key, whatever it holds', () => {
        // Deliberate, and it is #745's own recorded boundary: is_numeric() has no
        // exact JS mirror, so a guard here would be the editor inventing its own
        // idea of a valid number. reconcileSubFieldTypes makes it unnecessary.
        [123, '123', 'abc', {}, []].forEach((v) => {
            expect(nonContainerValueDiffs(withItems([{ image_id: v }]), REGISTRY)).toEqual([]);
        });
    });

    it('leaves the boundaries another guard already settles alone', () => {
        // A non-array under an array-typed prop is wouldLoseArrayData's (it
        // renders no rows at all); a non-object row is reconcileArrayItems'.
        const notAnArray = JSON.stringify([
            { component: 'container', props: { items: 'not an array' } },
        ]);
        expect(nonContainerValueDiffs(notAnArray, REGISTRY)).toEqual([]);
        expect(nonContainerValueDiffs(withItems(['plain', 42]), REGISTRY)).toEqual([]);
    });

    it('says nothing about a sub-key the schema does not declare', () => {
        expect(nonContainerValueDiffs(withItems([{ nope: 'x' }]), REGISTRY)).toEqual([]);
    });

    it('survives input the parser cannot use', () => {
        expect(nonContainerValueDiffs('not json', REGISTRY)).toEqual([]);
        expect(nonContainerValueDiffs('', REGISTRY)).toEqual([]);
        expect(nonContainerValueDiffs('{}', REGISTRY)).toEqual([]);
    });

    it('routes the composition through checkSerializationInvariant', () => {
        // The guard is only worth anything if it reaches the author, so pin the
        // wiring, not just the predicate.
        const result = checkSerializationInvariant(withItems([{ bullets: 'one,two' }]), REGISTRY);
        expect(result.safe).toBe(false);
        expect(result.diffs.some((d) => d.path === '[0].props.items[0].bullets')).toBe(true);

        expect(checkSerializationInvariant(withItems([{ bullets: ['one'] }]), REGISTRY).safe).toBe(true);
    });
});

describe('displayOnlySubKeys (#805)', () => {
    // The single derivation feeding reconcileArrayItems' unreadableKeys argument.
    // A wrong answer here silently re-opens the undeclared-key drop that argument
    // exists to prevent, so it is asserted directly rather than only through the
    // end-to-end pin that consumes it.
    const SUB = {
        title:    { type: 'string' },
        bullets:  { type: 'array'  },
        image_id: { type: 'number' },
        style:    { type: 'object' },
        role:     { type: 'enum', values: ['a'] },
    };

    it('names the container sub-keys, in declaration order', () => {
        expect(displayOnlySubKeys(SUB)).toEqual(['bullets', 'style']);
    });

    it('names nothing when no sub-key is a container', () => {
        expect(displayOnlySubKeys({ title: { type: 'string' }, n: { type: 'number' } })).toEqual([]);
        expect(displayOnlySubKeys({})).toEqual([]);
    });

    it('stands down on a sub-schema it cannot walk', () => {
        [undefined, null, 'not a schema', ['not', 'a', 'schema'], 42].forEach((bad) => {
            expect(displayOnlySubKeys(bad)).toEqual([]);
        });
    });
});

describe('reconcileArrayItems ignores the keys no control could carry (#805)', () => {
    // The guard whose failure mode is the silent loss of an undeclared row key.
    const STORED = [{ title: 'A', bullets: ['x'], foo: 'bar' }];

    it('keeps the row when the only content in the read was restored', () => {
        // Every control the author could type into reads back empty, and `foo`
        // never had one — so this row is not an edit, and taking the read would
        // drop `foo`. Without the ignore list the restored bullets would read as
        // content and the row would be taken.
        const read = [{ title: '', bullets: ['x'] }];
        expect(reconcileArrayItems(read, STORED, ['bullets']).items[0]).toEqual(STORED[0]);
        expect(reconcileArrayItems(read, STORED, ['bullets']).restored).toEqual([0]);
    });

    it('takes the read when the author actually typed something', () => {
        const read = [{ title: 'edited', bullets: ['x'] }];
        expect(reconcileArrayItems(read, STORED, ['bullets']).items[0].title).toBe('edited');
    });

    it('defaults to ignoring nothing', () => {
        // Two-argument callers keep exactly the behaviour they had.
        const read = [{ title: '', bullets: ['x'] }];
        expect(reconcileArrayItems(read, STORED).items[0].title).toBe('');
        expect(reconcileArrayItems(read, STORED, []).items[0].title).toBe('');
    });
});

describe('reconcileSubFieldTypes (#805)', () => {
    const SUB = {
        title:    { type: 'string' },
        bullets:  { type: 'array'  },
        style:    { type: 'object' },
        image_id: { type: 'number' },
    };

    it('always keeps a container sub-key, since no control ever showed it', () => {
        const read = [{ title: 'edited' }];
        const orig = [{ title: 'A', bullets: ['one', 'two'], style: { '--x': '#fff' } }];
        const out = reconcileSubFieldTypes(read, orig, SUB);

        expect(out.items[0]).toEqual({
            title: 'edited', bullets: ['one', 'two'], style: { '--x': '#fff' },
        });
        expect(out.restored).toEqual([
            { index: 0, key: 'bullets' }, { index: 0, key: 'style' },
        ]);
    });

    it('keeps a typed scalar only while its text is the text it was rendered from', () => {
        const orig = [{ image_id: 123 }];

        // Untouched: the read is String(123), so the stored NUMBER stands.
        expect(reconcileSubFieldTypes([{ image_id: '123' }], orig, SUB).items[0].image_id).toBe(123);
        // Edited: the author typed it, so it lands as typed. That it lands as a
        // STRING is the accepted limitation — is_numeric() accepts it at write,
        // deliberately (#707), and it is what the editor did before #805 too.
        expect(reconcileSubFieldTypes([{ image_id: '456' }], orig, SUB).items[0].image_id).toBe('456');
        // Cleared: '' is the unset sentinel and the author can mean it.
        expect(reconcileSubFieldTypes([{ image_id: '' }], orig, SUB).items[0].image_id).toBe('');
    });

    it('renders null as empty, so an untouched null survives as null', () => {
        // escapeHtml coerces null to '', which is what the read hands back — the
        // same sentinel-preserving rule the string guard applies one type over.
        const out = reconcileSubFieldTypes([{ image_id: '' }], [{ image_id: null }], SUB);
        expect(out.items[0].image_id).toBe(null);
    });

    it('treats a key missing from the read as unedited, whatever its class', () => {
        // No control resolved, so nothing showed it, so there was nothing to
        // change — the same answer the row guard gives one grain up.
        const out = reconcileSubFieldTypes([{}], [{ title: 'A', image_id: 7 }], SUB);
        expect(out.items[0]).toEqual({ title: 'A', image_id: 7 });
    });

    it('never invents a sub-key the stored row did not have', () => {
        const out = reconcileSubFieldTypes([{ title: 'A' }], [{ title: 'A' }], SUB);
        expect('bullets' in out.items[0]).toBe(false);
        expect('style' in out.items[0]).toBe(false);
        expect(out.restored).toEqual([]);
    });

    it('leaves a row that has no stored counterpart alone', () => {
        const out = reconcileSubFieldTypes(
            [{ title: 'A' }, { title: 'new row' }], [{ title: 'A', bullets: ['x'] }], SUB,
        );
        expect(out.items[1]).toEqual({ title: 'new row' });
    });

    it('leaves a row whose stored counterpart is not an object alone', () => {
        // reconcileArrayItems owns that row; two rules touching it would be two
        // rules disagreeing about it.
        const out = reconcileSubFieldTypes([{ title: '' }], ['plain'], SUB);
        expect(out.items[0]).toEqual({ title: '' });
    });

    it('does not mutate what it was given', () => {
        const read = [{ title: 'edited' }];
        const orig = [{ title: 'A', bullets: ['one'] }];
        reconcileSubFieldTypes(read, orig, SUB);
        expect(read).toEqual([{ title: 'edited' }]);
        expect(orig).toEqual([{ title: 'A', bullets: ['one'] }]);
    });

    it('stands down when there is nothing to reconcile against', () => {
        const read = [{ title: 'A' }];
        expect(reconcileSubFieldTypes(read, 'not an array', SUB).items).toBe(read);
        expect(reconcileSubFieldTypes(read, [{ title: 'A' }], undefined).items).toBe(read);
        expect(reconcileSubFieldTypes(read, [{ title: 'A' }], null).items).toBe(read);
    });
});

describe('#805 against the real shipped schemas', () => {
    const REAL = fs
        .readdirSync(path.resolve(__dirname, '../../components'), { withFileTypes: true })
        .filter((d) => d.isDirectory())
        .map((d) => ({ name: d.name, file: path.resolve(__dirname, '../../components', d.name, 'schema.json') }))
        .filter((c) => fs.existsSync(c.file))
        .map((c) => ({ name: c.name, schema: JSON.parse(fs.readFileSync(c.file, 'utf-8')) }));

    it('classifies the shipped container sub-keys as display-only', () => {
        // Not stand-ins: these are the declarations the issue measured.
        const grid = REAL.find((c) => c.name === 'grid').schema.props.items.items;
        const section = REAL.find((c) => c.name === 'section').schema.props.panel_items.items;

        expect(subFieldIsDisplayOnly(grid.bullets)).toBe(true);
        expect(subFieldIsDisplayOnly(grid.style)).toBe(true);
        expect(subFieldIsDisplayOnly(section.style)).toBe(true);
        expect(subFieldIsTypedScalar(grid.image_id)).toBe(true);
        expect(subFieldIsDisplayOnly(grid.title)).toBe(false);
        // The nested enum stays exactly where it was — #646's, not this change's.
        expect(subFieldIsDisplayOnly(grid.text_role)).toBe(false);
        expect(subFieldIsTypedScalar(grid.text_role)).toBe(false);
    });

    it('keeps the accordion for the shipped bullets and style shapes', () => {
        const json = JSON.stringify([
            {
                component: 'grid',
                props: {
                    items: [{
                        title: 'Card A',
                        bullets: ['Fast', 'Cheap'],
                        image_id: 42,
                        style: { '--grid-item-bg': '#fff' },
                    }],
                },
            },
        ]);
        expect(nonContainerValueDiffs(json, REAL)).toEqual([]);
        expect(checkSerializationInvariant(json, REAL).safe).toBe(true);
    });

    it('refuses the flattened shapes #744 rejects at write', () => {
        const json = JSON.stringify([
            {
                component: 'grid',
                props: { items: [{ bullets: 'Fast,Cheap', style: '[object Object]' }] },
            },
        ]);
        const paths = nonContainerValueDiffs(json, REAL).map((d) => d.path).sort();
        expect(paths).toEqual([
            '[0].props.items[0].bullets', '[0].props.items[0].style',
        ]);
    });
});
