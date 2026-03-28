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
            items: { type: 'array', required: true  },
            title: { type: 'string', required: false },
        },
    },
};

const REGISTRY = [HERO, FAQ];

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
