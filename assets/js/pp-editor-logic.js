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

// ── Exports ───────────────────────────────────────────────────────────────────

var _logic = {
    getJsonContextFromText:  getJsonContextFromText,
    validateCompositionData: validateCompositionData,
    getInsertPosition:       getInsertPosition,
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
