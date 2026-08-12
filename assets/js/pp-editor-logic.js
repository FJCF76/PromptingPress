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
        // Site chrome stays in the registry so the preview can render it, but a
        // composition may not declare it (#223). Mirrors pp_validate_composition().
        // The wording lives once, in PHP, and ships as ownedMessage. The terse
        // fallback covers a registry entry that predates that field; it is not a
        // second copy of the sentence.
        if (comp.templateOwned) {
            errors.push(comp.ownedMessage ||
                ('"' + item.component + '" is site chrome and cannot be placed in a page composition.'));
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
 * Escape a value for interpolation into the accordion builder's generated markup.
 *
 * The builder assembles HTML by string concatenation, so every interpolated value
 * lands in one of two contexts:
 *
 *   text node   <label>VALUE</label>
 *   attribute   <input data-field="VALUE" value="VALUE">
 *
 *                     & < >   " '
 *   text node          ✓      not required
 *   attribute          ✓      ✓            ← strict superset
 *
 * This escapes for the superset and is used in BOTH contexts throughout the
 * accordion builder. One escaper there is deliberate: with a text-only and an
 * attribute-only variant, every future interpolation site becomes a fresh chance to
 * reach for the wrong one, which is exactly the failure mode a single escaper
 * removes. (pp-ai-chat.js keeps its own text-only escaper for the markdown
 * pipeline, where the output is only ever a text node.) Escaping the quote
 * characters in a text node costs nothing: `&quot;` and `&#39;` render as `"` and
 * `'`, and the accordion reads values back with jQuery's `.val()`, which hands back
 * the DECODED character — so a value survives render → read → serializeAccordionData
 * byte-identically.
 *
 * Field names are not a constrained vocabulary: buildAccordionData() derives them
 * from Object.keys(props) on the pass-through and unknown-component branches, so
 * any JSON object key in stored composition reaches this function.
 *
 * One pass over the string via a lookup map, so there is no ordering hazard: a
 * sequential-replace form has to run `&` first or it re-escapes the ampersands its
 * own later replacements introduce (`<` → `&lt;` → `&amp;lt;`).
 *
 * @param  {*} value  Any value; null/undefined coerce to the empty string.
 * @return {string}   Safe to interpolate into a text node or a quoted attribute.
 */
var HTML_ESCAPES = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
};

function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value).replace(/[&<>"']/g, function (ch) { return HTML_ESCAPES[ch]; });
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
 * Whether writing the DOM-read items back would replace stored content with a
 * read that never represented it. True means the sync leaves the field alone.
 *
 * The question is always the same one: could a user have produced this read by
 * editing? If yes, it is an edit and must land. If no, the controls did not
 * carry the stored value in the first place, and writing the read back would
 * substitute the form's idea of the field for the author's.
 *
 *   stored value ──renders──> row controls ──.val()──> read items
 *                                  │
 *              a value the controls cannot represent renders nothing to read,
 *              so the read comes back contentless no matter what is stored
 *
 * Three shapes of contentless read, none of them reachable by editing:
 *
 *   NO ROWS AT ALL. buildArrayFieldHtml renders rows from
 *   `Array.isArray(field.value) ? field.value : []`, so a non-array under an
 *   array-typed prop (a string, an object) renders an empty container and reads
 *   back as `[]`. It also fires when the read comes back empty while rows ARE
 *   stored, which is a failed read rather than an edit. The branch keys on the
 *   read being empty rather than on the stored value's type because both causes
 *   mean the same thing: nothing on screen ever carried the stored value, so
 *   there was nothing to clear. (Emptying an array through the UI does not
 *   arrive here — see the structural-changes note below.)
 *
 *   EVERY ROW `{}`. syncAccordionToJson assigns `item[sk]` for every sub-key
 *   whose control it resolves, and an emptied text field still yields `{sk: ''}`.
 *   So `{}` means no control resolved — the permanent state of a pass-through
 *   array, whose items have no schema `items` spec and render no sub-field
 *   controls at all.
 *
 *   A ROW OF EMPTY STRINGS OVER A NON-OBJECT ORIGINAL. Where the schema DOES
 *   declare an `items` spec, a stored scalar item renders a control per sub-key,
 *   each reading `item[sk]` off a scalar — `(1)['title']` is undefined — so every
 *   control shows '' and the row arrives as `{title:'', body:''}`. That is not
 *   empty by key count, which is why the first two shapes miss it. It is caught
 *   by asking what the ORIGINAL item was: only an object can be edited down to
 *   empty strings, so an all-empty read paired with a non-object original is a
 *   failed read, while the same read paired with an object original is a user who
 *   cleared the fields and must be written through. Typing into any one control
 *   of a scalar row also writes through — the read is no longer all-empty, and a
 *   row the author has actually filled in is content, not a substitution.
 *
 * "Something is stored" is deliberately not a truthiness test. `0` and `false`
 * are values an author can mean, for the same reason the renderer stops using
 * `|| ''` on them; only undefined, null, and an empty array are nothing.
 *
 * Structural row changes do not pass through here — add/remove rewrite the JSON
 * buffer directly — so a skipped sync never blocks one. In particular, emptying
 * an array by removing its last row is not the no-rows case above: the remove
 * handler splices the buffer and re-renders, so the next sync already compares
 * against a stored `[]` and the guard stands down.
 *
 * The cost of refusing is that the accordion cannot repair a value it could not
 * show — a prop left holding `"bad"` keeps holding it however many times the
 * form is synced. That is the intended trade: the JSON view is one toggle away
 * and edits the buffer directly, so the author has a way to fix the value, and
 * it is the only way that does not involve the form guessing what was meant.
 *
 * @param {Array<Object>} newItems  - Items read from the DOM
 * @param {*}             origItems - Original field value (from CodeMirror JSON)
 * @returns {boolean}
 */
function wouldLoseArrayData(newItems, origItems) {
    if (!hasStoredContent(origItems)) return false;

    // The read produced no rows, so it carries nothing of the stored value.
    if (newItems.length === 0) return true;

    // No control resolved on any row.
    if (newItems.every(function (item) { return Object.keys(item).length === 0; })) return true;

    // A row read back carrying only empty strings, over an original that no
    // control could have shown. Bounded to indices the original actually has:
    // beyond its length there is no stored item to protect.
    // Array-only because a non-array original always reads back as zero rows and
    // is caught above; keeping the test here also stops the predicate indexing a
    // value that cannot be indexed.
    return Array.isArray(origItems) && newItems.some(function (item, i) {
        return i < origItems.length && !isPlainObject(origItems[i]) && readAllEmpty(item);
    });
}

/**
 * Whether anything is stored that the read could stand in for. Not a count of
 * items: a stored `42` or `"bad"` has no items at all, and is exactly the kind of
 * value this asks about.
 */
function hasStoredContent(origItems) {
    if (origItems === undefined || origItems === null) return false;
    if (Array.isArray(origItems)) return origItems.length > 0;
    // A non-array under an array-typed prop: not representable as rows, so any
    // value at all is content the read cannot have come from.
    return true;
}

/**
 * A row whose every resolved control read back as the empty string. A row with
 * no keys at all is vacuously all-empty, which is the intended answer and not an
 * accident of `every`: a keyless row resolved no control, so it carries no more
 * of the stored value than a row of empty strings does. The all-`{}` rule above
 * reaches that case first today, so this only decides it for a MIXED read — some
 * rows keyless, some not — where the same reasoning applies row by row.
 */
function readAllEmpty(item) {
    return Object.keys(item).every(function (k) { return item[k] === ''; });
}

/** An object an author could have edited field by field — not an array, not null. */
function isPlainObject(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
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

    // UNADVERTISED STORED ENUM VALUES (#605). The round-trip above is IN-MEMORY, so
    // it cannot see this class of drift: `field.value` still holds the stored value
    // at this point. The mutation is introduced later, by syncAccordionToJson()
    // reading values back off the DOM — and a <select> built from the advertised
    // `values` has no option matching an unadvertised stored value, so .val()
    // returns the FIRST option instead.
    //
    // Why this belongs here rather than being tolerated: every shipped enum is
    // `strict: true` (#579), so the only way to hold an unadvertised value is stale
    // storage. Without this check, a single keystroke ANYWHERE in the accordion
    // rewrites every such band to its first advertised value and the save then
    // PASSES the strict-enum gate — silently laundering past the very validator that
    // is supposed to reject it, and rewriting bytes on bands the author never opened.
    //
    // The fix is to refuse, not to tolerate: no coercion, no migration, no alias.
    // This is exactly what the notice already says — "opening this composition in the
    // accordion editor would change its structure" — so it routes into the existing
    // JSON-only mode, where the author can see and fix the real value.
    diffs = diffs.concat(unadvertisedEnumDiffs(jsonString, componentRegistry));

    if (diffs.length === 0) {
        return { safe: true };
    }
    return { safe: false, diffs: diffs, original: original, roundTripped: roundTripped };
}

/**
 * Stored enum values a <select> built from the schema's advertised `values` cannot
 * represent, and would therefore silently rewrite on DOM readback (#605).
 *
 * Deliberately GENERIC — it knows nothing about any particular value. It asks one
 * question of every enum prop: is the stored value absent from `values`? The `unset`
 * sentinel (absent / null / empty string) is NOT drift: it is the documented way to
 * keep a prop's default, and the accordion has always rendered it as the first
 * option without claiming otherwise.
 *
 * @param {string} jsonString        Raw composition JSON.
 * @param {Array}  componentRegistry Component schemas.
 * @returns {Array} deepDiff-shaped entries, empty when nothing would drift.
 */
function unadvertisedEnumDiffs(jsonString, componentRegistry) {
    var out = [];
    var parsed;
    try { parsed = JSON.parse(jsonString); } catch (e) { return out; }
    if (!Array.isArray(parsed)) return out;

    var schemaMap = {};
    componentRegistry.forEach(function (c) { schemaMap[c.name] = c; });

    parsed.forEach(function (item, idx) {
        if (!item || typeof item !== 'object' || !item.component) return;
        var comp = schemaMap[item.component];
        if (!comp || !comp.schema || !comp.schema.props || !item.props) return;

        Object.keys(item.props).forEach(function (propName) {
            var def = comp.schema.props[propName];
            if (!def || def.type !== 'enum' || !Array.isArray(def.values)) return;

            var stored = item.props[propName];
            // Unset sentinel — keeps the default, not drift.
            if (stored === undefined || stored === null || stored === '') return;
            if (def.values.indexOf(stored) !== -1) return;

            out.push({
                path: '[' + idx + '].props.' + propName,
                before: stored,
                after: def.values[0],
                changeType: 'changed'
            });
        });
    });

    return out;
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
 * whether the component's schema marks it required — most components (cta,
 * grid, faq, section, stats, table, logos, embed) declare `title` as optional
 * ("omit if context makes it clear"), but it's still the best available
 * label whenever it's actually set (#76). Only `hero` marks `title` required
 * (cta.title became optional in #294), which is why hero already worked before
 * this fix — falling back to the first required string field (e.g. hero's own
 * required title, or a title-less cta's required `button_text`) preserves that
 * exact behavior.
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
    escapeHtml:                     escapeHtml,
    getJsonContextFromText:         getJsonContextFromText,
    validateCompositionData:        validateCompositionData,
    getInsertPosition:              getInsertPosition,
    buildAccordionData:             buildAccordionData,
    serializeAccordionData:         serializeAccordionData,
    wouldLoseArrayData:             wouldLoseArrayData,
    deepDiff:                       deepDiff,
    checkSerializationInvariant:    checkSerializationInvariant,
    unadvertisedEnumDiffs:          unadvertisedEnumDiffs,
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
