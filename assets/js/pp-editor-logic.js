/**
 * pp-editor-logic.js — Pure, testable logic extracted from pp-admin-editor.js
 *
 * No DOM, no CodeMirror, no jQuery. Three functions:
 *   getJsonContextFromText  — autocomplete context from text-before-cursor
 *   validateCompositionData — validate JSON string against component registry
 *   getInsertPosition       — find where to insert a new array item
 *
 * Loaded as a plain <script> before pp-admin-editor.js in the browser.
 * Loaded via require() in Vitest (Node/CommonJS).
 */

/* jshint esversion: 5 */

(function () {

/**
 * Determine autocomplete context from the text before the cursor.
 *
 * @param {string}   textBeforeCursor
 * @param {string[]} componentNames   List of known component names (unused for
 *                                    type detection but kept for signature parity).
 * @returns {{ type: 'component-value' }
 *          | { type: 'props-key', componentName: string }
 *          | null}
 */
function getJsonContextFromText(textBeforeCursor, componentNames) {
    // Inside a component-value string (after `"component": "`)
    if (/"component"\s*:\s*"[^"]*$/.test(textBeforeCursor)) {
        return { type: 'component-value' };
    }

    // Find the nearest preceding completed component name
    var re = /"component"\s*:\s*"([^"]+)"/g;
    var m, last = null;
    while ((m = re.exec(textBeforeCursor)) !== null) { last = m[1]; }
    var name = last;
    if (!name) return null;

    // Walk from that "component" key forward to find "props": {
    var idx   = textBeforeCursor.lastIndexOf('"component"');
    var after = textBeforeCursor.slice(idx);
    var pi    = after.indexOf('"props"');
    if (pi === -1) return null;

    var ap = after.slice(pi + 7);   // text after the word "props"
    var bi = ap.indexOf('{');
    if (bi === -1) return null;

    // Walk the props object to see if the cursor is still inside it at a key position.
    // Track `afterColon` to distinguish key slots from value slots:
    //   afterColon=false  → we're expecting the next key (or still typing one)
    //   afterColon=true   → we're in a value slot (saw `:` at depth=1)
    var inside = ap.slice(bi + 1);
    var depth = 1, inStr = false, escaped = false, afterColon = false;
    for (var i = 0; i < inside.length; i++) {
        var c = inside[i];
        if (escaped)             { escaped = false; continue; }
        if (c === '\\' && inStr) { escaped = true;  continue; }
        if (c === '"')           { inStr = !inStr;  continue; }
        if (!inStr) {
            if (c === '{') depth++;
            else if (c === '}') {
                depth--;
                if (depth === 0) break;  // props object closed — cursor is outside it
            }
            if (c === ':' && depth === 1) afterColon = true;
            if (c === ',' && depth === 1) afterColon = false;
        }
    }
    if (depth > 0 && !afterColon) return { type: 'props-key', componentName: name };
    return null;
}

/**
 * Validate a JSON composition string against a component registry.
 *
 * @param {string} jsonString
 * @param {Array<{ name: string, schema: Object }>} componentRegistry
 * @returns {string[]} Array of human-readable error messages (empty = valid).
 */
function validateCompositionData(jsonString, componentRegistry) {
    var errors = [];
    if (!jsonString.trim()) return errors;

    var parsed;
    try   { parsed = JSON.parse(jsonString); }
    catch (e) { errors.push('JSON syntax error: ' + e.message); return errors; }

    if (!Array.isArray(parsed)) {
        errors.push('Composition must be a JSON array.');
        return errors;
    }

    var nameMap = {};
    componentRegistry.forEach(function (c) { nameMap[c.name] = c; });

    parsed.forEach(function (item, i) {
        if (!item || typeof item !== 'object' || Array.isArray(item)) {
            errors.push('Item ' + i + ' is not an object.');
            return;
        }
        if (!item.component) {
            errors.push('Item ' + i + ' missing "component" key.');
            return;
        }
        var comp = nameMap[item.component];
        if (!comp) {
            errors.push('Unknown component: "' + item.component + '".');
            return;
        }
        var props = (comp.schema || {}).props || {};
        Object.keys(props).forEach(function (k) {
            if (!props[k].required) return;
            var absent = !item.props || !(k in item.props);
            var val    = item.props ? item.props[k] : undefined;
            var blank  = val === null || val === false || val === '';
            if (absent || blank) {
                errors.push('"' + item.component + '" missing required prop "' + k + '".');
            }
        });
    });

    return errors;
}

/**
 * Walk a composition string and compute top-level array item end positions,
 * then determine the afterIdx for a new-item insertion at cursorOffset.
 *
 * @param {string} compositionText   Full editor value.
 * @param {number} cursorOffset      Character offset of the cursor.
 * @returns {{ afterIdx: number, itemEnds: number[], bracketPos: number }}
 *   afterIdx  — index into itemEnds after which to insert (-1 = before all).
 *   itemEnds  — char offsets of each top-level `}`.
 *   bracketPos — char offset of the opening `[`.
 */
function getInsertPosition(compositionText, cursorOffset) {
    var bracketPos = compositionText.indexOf('[');
    if (bracketPos === -1) {
        return { afterIdx: -1, itemEnds: [], bracketPos: -1 };
    }
    var itemEnds   = [];
    var depth = 0, inStr = false, isEsc = false;

    for (var i = bracketPos + 1; i < compositionText.length; i++) {
        var c = compositionText[i];
        if (isEsc)              { isEsc = false; continue; }
        if (c === '\\' && inStr){ isEsc = true;  continue; }
        if (c === '"')          { inStr = !inStr; continue; }
        if (inStr) continue;
        if (c === '{') depth++;
        else if (c === '}') {
            depth--;
            if (depth === 0) itemEnds.push(i);
        }
        if (c === ']' && depth === 0) break;
    }

    var afterIdx = -1;
    for (var k = 0; k < itemEnds.length; k++) {
        if (cursorOffset > itemEnds[k]) afterIdx = k;
    }

    // Cursor is before the first item end but inside the array
    if (afterIdx === -1 && itemEnds.length > 0 && cursorOffset > bracketPos) {
        afterIdx = 0;
        for (var m = 0; m < itemEnds.length; m++) {
            if (cursorOffset <= itemEnds[m]) { afterIdx = m; break; }
        }
    }

    return { afterIdx: afterIdx, itemEnds: itemEnds, bracketPos: bracketPos };
}

/**
 * Multi-line field names. Fields with these names render as <textarea>
 * instead of <input> in the accordion. Pure display affordance — the JSON
 * value is the same string either way.
 */
var MULTILINE_FIELDS = ['body', 'content', 'answer'];

/**
 * Build accordion data by merging a JSON composition string with a component registry.
 *
 * @param {string} jsonString   Raw JSON from CodeMirror
 * @param {Array<{ name: string, schema: Object }>} componentRegistry
 * @returns {{ components: Array<{ name: string, fields: Array, props: Object }>, errors: string[] }}
 */
function buildAccordionData(jsonString, componentRegistry) {
    var result = { components: [], errors: [] };
    if (!jsonString || !jsonString.trim()) return result;

    var parsed;
    try   { parsed = JSON.parse(jsonString); }
    catch (e) { result.errors.push('JSON syntax error: ' + e.message); return result; }

    if (!Array.isArray(parsed)) {
        result.errors.push('Composition must be a JSON array.');
        return result;
    }

    var schemaMap = {};
    componentRegistry.forEach(function (c) { schemaMap[c.name] = c; });

    parsed.forEach(function (item) {
        if (!item || typeof item !== 'object' || !item.component) return;

        var compName = item.component;
        var comp = schemaMap[compName];
        var props = item.props || {};
        var fields = [];

        if (comp && comp.schema && comp.schema.props) {
            var schemaDef = comp.schema.props;
            // Schema-defined fields first
            Object.keys(schemaDef).forEach(function (key) {
                var spec = schemaDef[key];
                var hasValue = key in props;
                var field = {
                    name: key,
                    type: spec.type === 'enum' ? 'enum' : (spec.type === 'array' ? 'array' : 'string'),
                    required: !!spec.required,
                    value: hasValue ? props[key] : (spec.default !== undefined ? spec.default : ''),
                    description: spec.description || '',
                    default: spec.default !== undefined ? spec.default : '',
                    userTouched: hasValue,
                    multiline: MULTILINE_FIELDS.indexOf(key) !== -1
                };
                if (spec.type === 'enum' && spec.values) {
                    field.values = spec.values;
                }
                if (spec.type === 'array' && spec.items) {
                    field.items = spec.items;
                }
                fields.push(field);
            });
            // Props in JSON but not in schema (pass-through)
            Object.keys(props).forEach(function (key) {
                if (!(key in schemaDef)) {
                    fields.push({
                        name: key,
                        type: Array.isArray(props[key]) ? 'array' : 'string',
                        required: false,
                        value: props[key],
                        description: '',
                        default: '',
                        userTouched: true,
                        multiline: false
                    });
                }
            });
        } else {
            // Unknown component — raw props, no schema merge
            Object.keys(props).forEach(function (key) {
                fields.push({
                    name: key,
                    type: Array.isArray(props[key]) ? 'array' : 'string',
                    required: false,
                    value: props[key],
                    description: '',
                    default: '',
                    userTouched: true,
                    multiline: false
                });
            });
        }

        // Preserve top-level keys other than component/props (e.g. style)
        var extraKeys = {};
        Object.keys(item).forEach(function (k) {
            if (k !== 'component' && k !== 'props') {
                extraKeys[k] = item[k];
            }
        });

        result.components.push({
            name: compName,
            fields: fields,
            props: props,
            extraKeys: extraKeys
        });
    });

    return result;
}

/**
 * Serialize accordion data back to a JSON composition string.
 * Only includes user-touched props (fields the user interacted with or that
 * were present in the original JSON). Schema defaults that were never touched
 * are omitted to keep the JSON clean.
 *
 * @param {Array<{ name: string, fields: Array }>} components
 * @returns {string} Pretty-printed JSON string
 */
function serializeAccordionData(components) {
    var arr = components.map(function (comp) {
        var props = {};
        comp.fields.forEach(function (field) {
            if (!field.userTouched) return;
            props[field.name] = field.value;
        });
        var entry = { component: comp.name, props: props };
        // Re-emit extra top-level keys (e.g. style) preserved by buildAccordionData
        if (comp.extraKeys) {
            Object.keys(comp.extraKeys).forEach(function (k) {
                entry[k] = comp.extraKeys[k];
            });
        }
        return entry;
    });
    return JSON.stringify(arr, null, 2);
}

/**
 * Detect when DOM-read array items would lose content compared to originals.
 * Returns true if all new items are empty objects but at least one original
 * had content — indicating a sync bug, not a legitimate user edit.
 *
 * @param {Array<Object>} newItems  - Items read from the DOM
 * @param {*}             origItems - Original field value (from CodeMirror JSON)
 * @returns {boolean}
 */
function wouldLoseArrayData(newItems, origItems) {
    return newItems.length > 0 &&
        Array.isArray(origItems) && origItems.length > 0 &&
        newItems.every(function (item) { return Object.keys(item).length === 0; }) &&
        origItems.some(function (orig) { return orig && Object.keys(orig).length > 0; });
}

/**
 * Recursively compare two values and return an array of differences.
 *
 * @param {*} a       First value
 * @param {*} b       Second value
 * @param {string} path  Dot-separated path prefix (start with '')
 * @returns {Array<{path: string, before: *, after: *, changeType: string}>}
 */
function deepDiff(a, b, path) {
    var diffs = [];
    if (path === undefined) path = '';

    // Both null/undefined and equal primitives
    if (a === b) return diffs;

    // Handle null
    if (a === null || b === null) {
        if (a !== b) {
            diffs.push({ path: path, before: a, after: b, changeType: 'changed' });
        }
        return diffs;
    }

    // Type mismatch
    var typeA = Array.isArray(a) ? 'array' : typeof a;
    var typeB = Array.isArray(b) ? 'array' : typeof b;
    if (typeA !== typeB) {
        diffs.push({ path: path, before: a, after: b, changeType: 'type_mismatch' });
        return diffs;
    }

    // Primitives
    if (typeA !== 'object' && typeA !== 'array') {
        if (a !== b) {
            diffs.push({ path: path, before: a, after: b, changeType: 'changed' });
        }
        return diffs;
    }

    // Arrays
    if (typeA === 'array') {
        var maxLen = Math.max(a.length, b.length);
        for (var i = 0; i < maxLen; i++) {
            var itemPath = path ? path + '[' + i + ']' : '[' + i + ']';
            if (i >= a.length) {
                diffs.push({ path: itemPath, before: undefined, after: b[i], changeType: 'added' });
            } else if (i >= b.length) {
                diffs.push({ path: itemPath, before: a[i], after: undefined, changeType: 'removed' });
            } else {
                diffs = diffs.concat(deepDiff(a[i], b[i], itemPath));
            }
        }
        return diffs;
    }

    // Objects
    var allKeys = {};
    Object.keys(a).forEach(function (k) { allKeys[k] = true; });
    Object.keys(b).forEach(function (k) { allKeys[k] = true; });

    Object.keys(allKeys).forEach(function (key) {
        var subPath = path ? path + '.' + key : key;
        if (!(key in a)) {
            diffs.push({ path: subPath, before: undefined, after: b[key], changeType: 'added' });
        } else if (!(key in b)) {
            diffs.push({ path: subPath, before: a[key], after: undefined, changeType: 'removed' });
        } else {
            diffs = diffs.concat(deepDiff(a[key], b[key], subPath));
        }
    });

    return diffs;
}

/**
 * Check whether the serialization round-trip preserves a composition exactly.
 *
 * @param {string} jsonString         Raw JSON from CodeMirror
 * @param {Array}  componentRegistry  Registered component definitions
 * @returns {{safe: boolean, diffs?: Array, original?: *, roundTripped?: *}}
 */
function checkSerializationInvariant(jsonString, componentRegistry) {
    // Empty or whitespace-only compositions have no structure to drift —
    // the accordion renders an empty component list, which is safe.
    if (!jsonString || !jsonString.trim()) {
        return { safe: true };
    }

    var original;
    try {
        original = JSON.parse(jsonString);
    } catch (e) {
        return { safe: false, diffs: [{ path: '', before: jsonString, after: null, changeType: 'changed' }], error: e.message };
    }

    var data = buildAccordionData(jsonString, componentRegistry);
    if (data.errors.length) {
        return { safe: false, diffs: [{ path: '', before: null, after: null, changeType: 'changed' }], error: data.errors.join('; ') };
    }

    var serialized = serializeAccordionData(data.components);
    var roundTripped;
    try {
        roundTripped = JSON.parse(serialized);
    } catch (e) {
        return { safe: false, diffs: [{ path: '', before: original, after: serialized, changeType: 'changed' }], error: e.message };
    }

    var diffs = deepDiff(original, roundTripped, '');
    if (diffs.length === 0) {
        return { safe: true };
    }
    return { safe: false, diffs: diffs, original: original, roundTripped: roundTripped };
}

/**
 * Format serialization diffs as a GitHub issue markdown report.
 *
 * @param {Array}  diffs      Array of diff objects from deepDiff
 * @param {string} pageTitle  Page title for the issue
 * @param {number} postId     WordPress post ID
 * @returns {string} Markdown-formatted issue body
 */
function formatDiffsForIssue(diffs, pageTitle, postId) {
    var title = 'Accordion serialization drift on "' + pageTitle + '"';
    var lines = [
        '## ' + title,
        '',
        '**Page:** ' + pageTitle + ' (Post ID: ' + postId + ')',
        '',
        '### Structural diffs',
        '',
        '| Component | Path | Before | After | Change |',
        '|-----------|------|--------|-------|--------|'
    ];

    diffs.forEach(function (d) {
        var compMatch = d.path.match(/^\[(\d+)\]/);
        var compLabel = compMatch ? 'Component ' + compMatch[1] : '—';
        var before = d.before === undefined ? '_(absent)_' : ('`' + JSON.stringify(d.before) + '`');
        var after = d.after === undefined ? '_(absent)_' : ('`' + JSON.stringify(d.after) + '`');
        // Escape pipe characters in values
        before = before.replace(/\|/g, '\\|');
        after = after.replace(/\|/g, '\\|');
        lines.push('| ' + compLabel + ' | `' + d.path + '` | ' + before + ' | ' + after + ' | ' + d.changeType + ' |');
    });

    lines.push('');
    lines.push('### Expected behavior');
    lines.push('');
    lines.push('The accordion round-trip (`buildAccordionData → serializeAccordionData`) should preserve the composition exactly. Any structural difference means the accordion editor would silently alter the composition on save.');
    lines.push('');
    lines.push('**Labels:** `bug`, `editor`, `serialization`');

    return lines.join('\n');
}

/**
 * Picks the label shown after the component type in a collapsed accordion
 * row, e.g. `grid — Our WordPress Services`.
 *
 * Prefers a field literally named `title` when it has a value, regardless of
 * whether the component's schema marks it required — most components (grid,
 * faq, section, stats, table, logos, embed) declare `title` as optional
 * ("omit if context makes it clear"), but it's still the best available
 * label whenever it's actually set (#76). Only `cta` and `hero` mark `title`
 * required, which is why hero already worked before this fix — falling back
 * to the first required string field (e.g. cta's/hero's own required title,
 * or a component with no title field at all) preserves that exact behavior.
 *
 * @param {{ fields: Array<{name: string, type: string, required: boolean, value: *}> }} compData
 * @returns {string}  Truncated preview text, or '' if nothing usable.
 */
function getCollapsedRowPreview(compData) {
    if (!compData || !Array.isArray(compData.fields)) return '';

    var TRUNCATE_AT = 40;
    var truncate = function (v) {
        var s = String(v);
        return s.length > TRUNCATE_AT ? s.slice(0, TRUNCATE_AT) + '\u2026' : s;
    };

    for (var i = 0; i < compData.fields.length; i++) {
        var titleField = compData.fields[i];
        if (titleField.name === 'title' && titleField.type === 'string' && titleField.value) {
            return truncate(titleField.value);
        }
    }
    for (var j = 0; j < compData.fields.length; j++) {
        var f = compData.fields[j];
        if (f.required && f.type === 'string' && f.value) {
            return truncate(f.value);
        }
    }
    return '';
}

// ── Exports ───────────────────────────────────────────────────────────────────

var _logic = {
    getJsonContextFromText:         getJsonContextFromText,
    validateCompositionData:        validateCompositionData,
    getInsertPosition:              getInsertPosition,
    buildAccordionData:             buildAccordionData,
    serializeAccordionData:         serializeAccordionData,
    wouldLoseArrayData:             wouldLoseArrayData,
    deepDiff:                       deepDiff,
    checkSerializationInvariant:    checkSerializationInvariant,
    formatDiffsForIssue:            formatDiffsForIssue,
    getCollapsedRowPreview:         getCollapsedRowPreview,
};

/* istanbul ignore next */
// Node / Vitest (CommonJS) — detect by process.versions.node, not by `module`,
// because some WP plugins define window.module and would steal the exports branch.
if (typeof process !== 'undefined' && process.versions && process.versions.node) {
    module.exports = _logic;
}
// Browser — always set window.PPEditorLogic so wp_enqueue dependencies work
// regardless of whether another plugin has defined window.module.
if (typeof window !== 'undefined') {
    window.PPEditorLogic = _logic;
}

}());
