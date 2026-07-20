/**
 * Enum <select> rendering in the accordion editor must not silently drop a stored
 * value that is no longer advertised in the schema's `values`.
 *
 * This is the #442 migration hazard: the `theme` enum dropped the deprecated `dark`
 * value from `values` (schemas now advertise `default | muted | inverted`), but the
 * renderer still accepts `dark` as an alias of `muted`. If the editor built the
 * <select> only from `values`, opening an existing band that stored `theme: "dark"`
 * would leave no option selected — the browser would fall back to the first option
 * (`default`) and RE-SAVING would silently rewrite the stored value, a visual change
 * to an existing page. The fix (pp-admin-editor.js buildFieldHtml, ~line 187) prepends
 * the stored value as its own option when it is not in `values`, so it round-trips.
 *
 * These tests mirror that render snippet (same pattern as pp-editor-dom.test.js).
 *
 * @vitest-environment jsdom
 */

// Faithful to the editor's real esc(): `$('<span>').text(text).html()` escapes
// `<`, `>`, `&` but NOT the double-quote (text content serialization). The test
// must mirror that exactly, or it would mask the shipped escaping behavior — which
// is precisely why the injection is charset-guarded rather than escape-trusted.
function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Mirrors the enum branch of buildFieldHtml in pp-admin-editor.js (~lines 187-206),
 * including the #442 legacy-value injection.
 */
function buildEnumSelectHtml(field) {
    let h = '<select data-field="' + field.name + '">';
    if (field.type === 'enum' && field.values) {
        var storedVal = (field.value === undefined || field.value === null) ? '' : String(field.value);
        if (storedVal !== '' && field.values.indexOf(field.value) === -1 && /^[a-z0-9_-]+$/i.test(storedVal)) {
            h += '<option value="' + esc(storedVal) + '" selected>' + esc(storedVal) + ' (legacy)</option>';
        }
        field.values.forEach(function (v) {
            var sel = v === field.value ? ' selected' : '';
            h += '<option value="' + esc(v) + '"' + sel + '>' + esc(v) + '</option>';
        });
    }
    h += '</select>';
    return h;
}

function selectedValue(html) {
    document.body.innerHTML = html;
    const sel = document.querySelector('select');
    return sel.value; // reflects the `selected` option
}

const themeValues = ['default', 'muted', 'inverted'];

describe('accordion editor enum <select> — #442 legacy value round-trip', () => {
    it('preserves a stored legacy "dark" value that is no longer advertised', () => {
        const html = buildEnumSelectHtml({ name: 'theme', type: 'enum', values: themeValues, value: 'dark' });
        // The stored value survives as a selected option, so re-saving keeps "dark".
        expect(selectedValue(html)).toBe('dark');
        expect(html).toContain('>dark (legacy)</option>');
    });

    it('selects an advertised value normally without injecting an extra option', () => {
        const html = buildEnumSelectHtml({ name: 'theme', type: 'enum', values: themeValues, value: 'muted' });
        expect(selectedValue(html)).toBe('muted');
        expect(html).not.toContain('(legacy)');
        // Exactly the three advertised options, none injected.
        expect((html.match(/<option/g) || []).length).toBe(3);
    });

    it('does not inject a stray option when no value is stored', () => {
        const html = buildEnumSelectHtml({ name: 'theme', type: 'enum', values: themeValues, value: undefined });
        expect(html).not.toContain('(legacy)');
        expect((html.match(/<option/g) || []).length).toBe(3);
        // Browser defaults to the first option.
        expect(selectedValue(html)).toBe('default');
    });

    it('does not reflect a malformed stored value into the value attribute (charset guard)', () => {
        // A stored value with attribute-breaking characters must NOT be injected —
        // esc() is text-safe but does not escape the double-quote that closes the
        // attribute, so the charset guard is the real defense. The malformed value
        // simply falls back to the default option.
        const evil = 'x" onfocus=alert(1) autofocus="';
        const html = buildEnumSelectHtml({ name: 'theme', type: 'enum', values: themeValues, value: evil });
        expect(html).not.toContain('onfocus');
        expect(html).not.toContain('(legacy)');
        expect((html.match(/<option/g) || []).length).toBe(3);
        expect(selectedValue(html)).toBe('default');
    });

    it('does not double-count when the stored value is the default', () => {
        const html = buildEnumSelectHtml({ name: 'theme', type: 'enum', values: themeValues, value: 'default' });
        expect((html.match(/<option/g) || []).length).toBe(3);
        expect(selectedValue(html)).toBe('default');
    });
});
