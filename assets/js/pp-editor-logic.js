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
 *   ROWS MISSING. A read holding fewer rows than were stored lost some; the
 *   no-rows case is just its extreme. Rows are rendered from the stored value,
 *   so the two agree unless the read failed.
 *
 * A FOURTH shape is deliberately NOT here. A row that reads back empty while its
 * stored item held something the controls never showed is also a failed read,
 * but it is a failure of ONE ROW, and refusing the whole field to protect it
 * would discard whatever the author legitimately edited in the other rows. That
 * case belongs to `reconcileArrayItems`, which settles it index by index. This
 * function keeps only the questions whose answer really is about the whole
 * field.
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

    // Rows went missing. Rows are rendered from the stored value, so a read
    // holding fewer than were stored lost some — the extreme of which is the
    // no-rows case above. Removing a row does not arrive here: that handler
    // rewrites the buffer and re-renders, so the next read matches again.
    return Array.isArray(origItems) && newItems.length < origItems.length;
}

/**
 * Merge a DOM read of an array field with the value it was rendered from, index
 * by index, so that one unreadable row costs only that row.
 *
 * `wouldLoseArrayData` answers a whole-field question and can only refuse the
 * whole field. That is the right answer when the read tells us nothing, but not
 * when it tells us something about SOME rows: a stored `["plain", {title:'A'}]`
 * renders one row the controls cannot represent and one they can, and vetoing
 * the field to protect the first would silently discard an edit the author made
 * to the second.
 *
 *   read   [ {title:'', body:''} , {title:'A2'} ]
 *   stored [ "plain"             , {title:'A' } ]
 *            └ unrepresentable     └ a real edit
 *              keep the stored       keep the read
 *
 * The rule per index is the one question again, asked of one row: could the
 * author have produced this read by editing? Content in the read is always an
 * edit and always wins — this never fabricates a value, it only declines to
 * replace one. An all-empty read is an edit only if every key the stored item
 * had was on screen to be cleared:
 *
 *   stored {title:'a'}   read {title:'', body:''}  -> the author cleared it, take the read
 *   stored {foo:'bar'}   read {title:'', body:''}  -> `foo` had no control, keep the stored
 *   stored "plain" / 1   read {title:'', body:''}  -> nothing had a control, keep the stored
 *   stored {}            read {title:'', body:''}  -> nothing to lose, take the read
 *
 * The `{foo:'bar'}` row is why the test is the stored item's own KEYS and not
 * its type. A row can be a perfectly ordinary object and still hold keys the
 * schema does not declare — the accordion renders a control per DECLARED
 * sub-key, so those keys are never on screen, read back as absent, and would be
 * dropped by a rule that only asked whether the original was an object.
 *
 * @param {Array<Object>} newItems  - Items read from the DOM
 * @param {*}             origItems - Original field value (from CodeMirror JSON)
 * @returns {{items: Array, restored: number[]}} `items` is what to write;
 *          `restored` lists the indices that kept their stored value.
 */
function reconcileArrayItems(newItems, origItems) {
    if (!Array.isArray(origItems)) return { items: newItems, restored: [] };

    var restored = [];
    var items = newItems.map(function (item, i) {
        if (i < origItems.length && readCannotRepresent(item, origItems[i])) {
            restored.push(i);
            return origItems[i];
        }
        return item;
    });
    return { items: items, restored: restored };
}

/** Whether a row's read carries nothing the stored item could have produced. */
function readCannotRepresent(readItem, origItem) {
    // Anything typed is content, and content is always the author's.
    if (!readAllEmpty(readItem)) return false;
    // No control ever showed a non-object, so there was nothing to clear.
    if (!isPlainObject(origItem)) return true;
    // An object is clearable only through the keys that got a control.
    return Object.keys(origItem).some(function (k) { return !(k in readItem); });
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

    // DRIFT THE IN-MEMORY ROUND-TRIP CANNOT SEE. The check above compares
    // buildAccordionData -> serializeAccordionData, and both of those keep
    // `props[key]` VERBATIM — so at this point `field.value` still holds exactly
    // what was stored and the comparison finds nothing. The mutation is introduced
    // LATER, by syncAccordionToJson() reading values back off the DOM.
    //
    //   stored value ──render──> control ──.val()──> read ──serialize──> buffer
    //                   │                     │
    //     the round-trip above stops          the mutation happens HERE,
    //     BEFORE this arrow: it never         past where deepDiff can look
    //     builds a control, it compares
    //     field.value
    //
    // Every member of the class has the same shape (a control that cannot carry the
    // stored value reads back as something else) and the same answer: REFUSE, do not
    // tolerate. No coercion, no migration, no alias. Each one routes into the
    // existing JSON-only mode, where the author can see the real value and fix it.
    //
    // (The notice calls what it found "structural drift". For these two members that
    // word is an overload — the structure round-trips fine and a VALUE is what would
    // change — kept as-is because it is user-facing copy this check does not own.)
    //
    // Two members so far, one call each:
    //
    //   #605  an unadvertised stored ENUM value. A <select> built from the
    //         advertised `values` has no matching option, so .val() returns the
    //         FIRST option. Every shipped enum is `strict: true` (#579), so the only
    //         way to hold one is stale storage, and without this a single keystroke
    //         ANYWHERE rewrites every such band to `values[0]` — and the save then
    //         PASSES the strict-enum gate, laundering past the very validator meant
    //         to reject it.
    //
    //   #745  a stored value that is not a string under a prop the schema declares
    //         `type: "string"`. It renders through escapeHtml's String() and reads
    //         back as that text, so a stored `false` becomes the STRING "false" —
    //         which #707 accepts at write, turning (for a link prop) a dead button
    //         into a live link to /false on a band the author never opened.
    diffs = diffs.concat(unadvertisedEnumDiffs(jsonString, componentRegistry));
    diffs = diffs.concat(nonStringValueDiffs(jsonString, componentRegistry));

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
    forEachDeclaredProp(jsonString, componentRegistry, function (def, stored, path) {
        if (def.type !== 'enum' || !Array.isArray(def.values)) return;
        // Unset sentinel — keeps the default, not drift.
        if (stored === undefined || stored === null || stored === '') return;
        if (def.values.indexOf(stored) !== -1) return;

        out.push({
            path: path,
            before: stored,
            after: def.values[0],
            changeType: 'changed'
        });
    });
    return out;
}

/**
 * Whether a stored value satisfies a `type: "string"` declaration.
 *
 * The exact inverse of the write path's rule, which is the point: #707 narrowed
 * `_pp_schema_scalar_value_is_valid()`'s string arm to `$value === null ||
 * is_string($value)` (lib/admin.php), so a value this returns false for is a value
 * the write path REFUSES. Two predicates, one rule — writing a different test here
 * would give the editor its own private idea of what a string prop accepts, which
 * is exactly the drift #614 extracted the PHP predicate to prevent.
 *
 * `null` is not drift. It is the documented unset sentinel, it keeps its carve-out
 * at the write path, and the sibling enum guard treats it the same way. It DOES
 * render empty and read back as '' — see the note in nonStringValueDiffs.
 */
function satisfiesStringDeclaration(value) {
    return value === null || typeof value === 'string';
}

/**
 * The text a control would hand back for this value, without ever throwing.
 *
 * `String(value)` is the right answer and is what actually happens: escapeHtml()
 * emits String(value) escaped, and .val() hands back the DECODED character (see
 * the escapeHtml docblock), so the text that lands in the buffer is String(stored)
 * exactly — `[object Object]` for an object, `1,2` for an array.
 *
 * It can also THROW, which is why this is a function rather than an expression.
 * `String()` coerces via ToPrimitive, and a parsed-JSON object can carry an own
 * `toString` key that is not callable — `{"toString": "x"}` — so ToPrimitive falls
 * through to Object.prototype.valueOf, gets a non-primitive back, and raises
 * TypeError. That value is reachable: it is ordinary JSON, and the boot call in
 * pp-admin-editor.js is not wrapped, so an uncaught throw here would leave the
 * editor with neither an accordion NOR a notice — the worst outcome available, on
 * exactly the page this check exists to get repaired.
 *
 * The fallback reports the value's default shape tag. It is not a claim about what
 * the control would have shown: for this shape the render would throw too, so
 * there is no text to be right about. The entry exists to REFUSE the composition
 * and name the path; the text is the supporting detail, not the finding.
 *
 * SCOPE OF THIS HARDENING, so it is not read as more than it is: it closes the
 * hazard on THIS path only. escapeHtml() and getCollapsedRowPreview() call String()
 * on the same author-controlled values and are NOT wrapped, so the same stored value
 * under a prop this guard does not judge — an undeclared pass-through prop — still
 * reaches the renderer and still throws at boot. That is pre-existing and unchanged
 * here; it is not fixed in passing because escapeHtml is the shared escaper every
 * interpolation in the editor routes through.
 */
function textForm(value) {
    try { return String(value); }
    catch (e) { return Object.prototype.toString.call(value); }
}

/** A deepDiff-shaped entry for a value the controls would rewrite to its text form. */
function textFormDiff(path, stored) {
    return { path: path, before: stored, after: textForm(stored), changeType: 'changed' };
}

/**
 * Walk every STORED prop of every band together with its schema declaration.
 *
 * Extracted because two guards need exactly this walk and had it inline twice —
 * the parse, the array check, the schema map, the "is this a band we can judge"
 * guards, and the decision to iterate the keys that are STORED rather than the
 * ones that are DECLARED. That last one is the load-bearing part: an absent prop
 * has no value to drift, and it is what buildAccordionData itself keys on.
 *
 * Same reason #614 pulled _pp_schema_scalar_value_is_valid() out on the PHP side.
 * Two copies of a walk is how two guards start disagreeing about which bands they
 * even look at, and they would disagree silently, because each one's tests only
 * ever exercise its own copy.
 *
 * Callers get (def, storedValue, path) and contribute nothing but their own
 * predicate. A prop the schema does not declare never reaches the callback: it has
 * no declared type for either guard to judge it against.
 *
 * @param {string}   jsonString        Raw composition JSON.
 * @param {Array}    componentRegistry Component schemas.
 * @param {Function} callback          (def, storedValue, path) => void
 */
function forEachDeclaredProp(jsonString, componentRegistry, callback) {
    var parsed;
    try { parsed = JSON.parse(jsonString); } catch (e) { return; }
    if (!Array.isArray(parsed)) return;

    var schemaMap = {};
    componentRegistry.forEach(function (c) { schemaMap[c.name] = c; });

    parsed.forEach(function (item, idx) {
        if (!item || typeof item !== 'object' || !item.component) return;
        var comp = schemaMap[item.component];
        if (!comp || !comp.schema || !comp.schema.props || !item.props) return;

        Object.keys(item.props).forEach(function (propName) {
            var def = comp.schema.props[propName];
            if (!def) return;
            callback(def, item.props[propName], '[' + idx + '].props.' + propName);
        });
    });
}

/**
 * Stored values a text control cannot represent, under props the schema declares
 * `type: "string"` (#745).
 *
 * Sibling of unadvertisedEnumDiffs, same class and same contract: it knows nothing
 * about any particular prop, and asks one question of every declaration — is the
 * stored value something a `<input type="text">` could have carried unchanged?
 *
 *     stored                  renders as        reads back as     verdict
 *     ──────────────────────  ────────────────  ────────────────  ──────────────
 *     "https://x/"            https://x/        "https://x/"      fine
 *     ""                      (empty)           ""                fine
 *     null                    (empty)           ""                NOT drift (see below)
 *     false                   false             "false"           DRIFT
 *     0 / 42                  0 / 42            "0" / "42"        DRIFT
 *     [1,2]                   1,2               "1,2"             DRIFT
 *     {a:1}                   [object Object]   "[object Object]" DRIFT
 *
 * WHY `null` IS EXCLUDED even though it does not survive either. It renders empty
 * and reads back as '', so an untouched null becomes '' — a real rewrite. It is
 * still not reported, for the reason the write path carves it out: null and ''
 * are the SAME documented sentinel ("leave this prop on its default"), so the
 * rewrite is between two spellings of one meaning rather than between two values.
 * Reporting it would lock the accordion on every composition that spells the
 * sentinel the first way, to protect a distinction nothing downstream draws.
 *
 * SCOPE, and why each boundary is where it is rather than one rule wider:
 *
 *   ENUM props belong to unadvertisedEnumDiffs. Reporting them here as well would
 *   put one stored value in the notice twice.
 *
 *   NUMBER props are untouched. Not because 42 and "42" are interchangeable — a
 *   consumer that branches on type would disagree — but because the write path
 *   deliberately still accepts a numeric string there (#707 left the `number` arm
 *   alone so a JSON/CLI write of "3" keeps working), so the editor has no rule to
 *   launder past. Widening to cover it is a separate decision, not a tidy-up.
 *
 *   UNDECLARED props (the pass-through and unknown-component branches of
 *   buildAccordionData) are untouched: they have no declared type, the write path
 *   accepts them, and blocking a composition that saves fine today is a heavier
 *   call than this one. Tracked separately.
 *
 *   ANY OTHER DECLARED TYPE (`boolean`, `object`) is untouched, and this is the
 *   boundary that is true today rather than true by design. buildAccordionData maps
 *   every type that is not `enum` or `array` to a text control, so such a prop would
 *   launder exactly like a string one, and the write path's scalar predicate returns
 *   "not applicable" for it, so the laundered text would be accepted. It is out of
 *   scope because it is not currently reachable: the only `boolean` declaration in
 *   the shipped schemas is `footer.show_logo`, and footer is site chrome that a page
 *   composition may not declare (#223). A composable `boolean` prop would need this
 *   rule extended, not merely inherited.
 *
 * THE NESTED WALK descends only into a stored value that IS an array, and only
 * into elements that ARE plain objects. Both boundaries avoid double-reporting a
 * value another guard already settles: a non-array under an array-typed prop makes
 * wouldLoseArrayData fire (it renders no rows at all), and a non-object row makes
 * reconcileArrayItems restore that row. Reporting those here too would take a value
 * that is already safe and lock the whole page over it. Sub-fields declaring
 * something other than `string` are likewise out, and this is the boundary most
 * worth being precise about: buildArrayFieldHtml hardcodes `type: 'string'` for
 * every row sub-key whatever the schema declares, so a stored `bullets` array
 * renders as `a,b` and a stored `style` object as `[object Object]`, and both read
 * back as those strings. Neither row guard rescues them — the read carries CONTENT,
 * so reconcileArrayItems takes it. That is a wider defect than this rule describes
 * and it is tracked as its own issue (#805); reporting it HERE would lock the page
 * without fixing the control that cannot show the value. Nested `enum` sub-keys are
 * out for the same reason and are tracked in #646.
 *
 * @param {string} jsonString        Raw composition JSON.
 * @param {Array}  componentRegistry Component schemas.
 * @returns {Array} deepDiff-shaped entries, empty when nothing would drift.
 */
function nonStringValueDiffs(jsonString, componentRegistry) {
    var out = [];
    forEachDeclaredProp(jsonString, componentRegistry, function (def, stored, path) {
        if (def.type === 'string') {
            if (!satisfiesStringDeclaration(stored)) out.push(textFormDiff(path, stored));
            return;
        }

        if (def.type !== 'array' || !def.items || !Array.isArray(stored)) return;
        stored.forEach(function (row, rowIdx) {
            if (!isPlainObject(row)) return;
            Object.keys(row).forEach(function (sk) {
                var sub = def.items[sk];
                if (!sub || sub.type !== 'string') return;
                if (satisfiesStringDeclaration(row[sk])) return;
                out.push(textFormDiff(path + '[' + rowIdx + '].' + sk, row[sk]));
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
    reconcileArrayItems:            reconcileArrayItems,
    deepDiff:                       deepDiff,
    checkSerializationInvariant:    checkSerializationInvariant,
    unadvertisedEnumDiffs:          unadvertisedEnumDiffs,
    nonStringValueDiffs:            nonStringValueDiffs,
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
