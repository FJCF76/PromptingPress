/**
 * pp-admin-editor.js — PromptingPress Composition Workspace
 *
 * Full-screen three-pane editor for page compositions.
 * All server communication uses wp_ajax_ handlers (cookie auth).
 */

/* global ppAdminEditor, wp, jQuery */
(function ($) {
    'use strict';

    if (typeof ppAdminEditor === 'undefined') return;

    var components = ppAdminEditor.components || [];
    var ajaxUrl    = ppAdminEditor.ajaxUrl || '';
    var nonce      = ppAdminEditor.nonce || '';
    var postId     = ppAdminEditor.postId || 0;
    var cm         = null;
    var lastCursor = null;  // preserved across focus loss

    // ── Helpers ───────────────────────────────────────────────────────────────

    function debounce(fn, ms) {
        var timer;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(null, args); }, ms);
        };
    }

    function esc(text) {
        return $('<span>').text(text).html();
    }

    function getComponentByName(name) {
        for (var i = 0; i < components.length; i++) {
            if (components[i].name === name) return components[i];
        }
        return null;
    }

    function componentNames() {
        return components.map(function (c) { return c.name; });
    }

    // ── Validation (300ms debounce) ───────────────────────────────────────────

    function validateComposition(value) {
        var errors = [];
        if (!value.trim()) return errors;

        var parsed;
        try { parsed = JSON.parse(value); }
        catch (e) { errors.push('JSON syntax error: ' + e.message); return errors; }

        if (!Array.isArray(parsed)) {
            errors.push('Composition must be a JSON array.');
            return errors;
        }

        var nameMap = {};
        components.forEach(function (c) { nameMap[c.name] = c; });

        parsed.forEach(function (item, i) {
            if (!item || typeof item !== 'object') {
                errors.push('Item ' + i + ' is not an object.'); return;
            }
            if (!item.component) {
                errors.push('Item ' + i + ' missing "component" key.'); return;
            }
            var comp = nameMap[item.component];
            if (!comp) {
                errors.push('Unknown component: "' + item.component + '".'); return;
            }
            var props = (comp.schema || {}).props || {};
            Object.keys(props).forEach(function (k) {
                if (props[k].required && (!item.props || !(k in item.props))) {
                    errors.push('"' + item.component + '" missing required prop "' + k + '".');
                }
            });
        });

        return errors;
    }

    function showErrors(errors) {
        var $bar = $('#pp-error-bar');
        if (!errors.length) { $bar.empty(); return; }
        var html = '<ul>' + errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>';
        $bar.html(html);
    }

    var runValidation = debounce(function () {
        if (!cm) return;
        showErrors(validateComposition(cm.getValue()));
    }, 300);

    // ── Preview (500ms debounce, uses wp_ajax) ────────────────────────────────

    function setPreviewStatus(msg) {
        $('#pp-preview-status').text(msg || '');
    }

    var runPreview = debounce(function () {
        if (!cm) return;
        var value = cm.getValue().trim();

        if (!value) {
            document.getElementById('pp-preview-frame').srcdoc = '';
            setPreviewStatus('');
            return;
        }

        var parsed;
        try { parsed = JSON.parse(value); } catch (e) {
            setPreviewStatus('Fix JSON to preview.');
            return;
        }
        if (!Array.isArray(parsed)) {
            setPreviewStatus('Must be array.');
            return;
        }

        setPreviewStatus('Loading\u2026');

        $.post(ajaxUrl, {
            action:      'pp_preview_composition',
            post_id:     postId,
            composition: JSON.stringify(parsed),
            nonce:       nonce,
        })
        .done(function (res) {
            if (res.success && res.data && res.data.html) {
                var frame = document.getElementById('pp-preview-frame');
                var hasBody = false;
                var scrollY = 0;
                try {
                    hasBody = !!(frame.contentDocument && frame.contentDocument.body && frame.contentDocument.body.innerHTML);
                    scrollY = frame.contentWindow.pageYOffset || 0;
                } catch (e) {}

                if (hasBody) {
                    // Subsequent update: swap body only to preserve scroll
                    var parsed = (new DOMParser()).parseFromString(res.data.html, 'text/html');
                    frame.contentDocument.body.innerHTML = parsed.body.innerHTML;
                    frame.contentWindow.scrollTo(0, scrollY);
                } else {
                    // First load: set full document
                    frame.srcdoc = res.data.html;
                }
                setPreviewStatus('');
            } else {
                setPreviewStatus(res.data || 'Preview error.');
            }
        })
        .fail(function (xhr) {
            setPreviewStatus('Preview failed (' + xhr.status + ').');
        });
    }, 500);

    // ── Schema tab (150ms debounce) ───────────────────────────────────────────

    function getNearestComponentName() {
        if (!cm) return null;
        var text = cm.getRange({ line: 0, ch: 0 }, cm.getCursor());
        var re = /"component"\s*:\s*"([^"]+)"/g;
        var m, last = null;
        while ((m = re.exec(text)) !== null) last = m[1];
        return last;
    }

    var updateSchemaTab = debounce(function () {
        var name = getNearestComponentName();
        var $el  = $('#pp-schema-display');

        if (!name) {
            $el.html('<p class="pp-schema-placeholder">Place cursor inside a component to see its schema.</p>');
            return;
        }

        var comp = getComponentByName(name);
        if (!comp) {
            $el.html('<p class="pp-schema-placeholder">Unknown: "' + esc(name) + '"</p>');
            return;
        }

        var schema = comp.schema || {};
        var props  = schema.props || {};
        var h = '<p class="pp-schema-name">' + esc(name) + '</p>';

        if (schema.description) {
            h += '<p class="pp-schema-desc">' + esc(schema.description) + '</p>';
        }

        var keys = Object.keys(props);
        if (keys.length) {
            h += '<table class="pp-schema-table"><thead><tr><th>Prop</th><th>Type</th><th></th></tr></thead><tbody>';
            keys.forEach(function (k) {
                var d = props[k];
                var type = d.type || 'string';
                if (type === 'enum' && d.values) type = d.values.map(function (v) { return '"' + v + '"'; }).join(' | ');
                var badge = d.required ? '<span class="pp-req-badge">req</span>' : '<span class="pp-opt-badge">opt</span>';
                h += '<tr><td>' + esc(k) + '</td><td>' + esc(type) + '</td><td>' + badge + '</td></tr>';
            });
            h += '</tbody></table>';
        }

        $el.html(h);
    }, 150);

    // ── Autocomplete ──────────────────────────────────────────────────────────

    function getJsonContext() {
        if (!cm) return null;
        var text = cm.getRange({ line: 0, ch: 0 }, cm.getCursor());

        if (/"component"\s*:\s*"[^"]*$/.test(text)) {
            return { type: 'component-value' };
        }

        var name = getNearestComponentName();
        if (!name) return null;

        var idx = text.lastIndexOf('"component"');
        var after = text.slice(idx);
        var pi = after.indexOf('"props"');
        if (pi === -1) return null;

        var ap = after.slice(pi + 7);
        var bi = ap.indexOf('{');
        if (bi === -1) return null;

        var inside = ap.slice(bi + 1);
        var depth = 1, inStr = false, esc2 = false;
        for (var i = 0; i < inside.length; i++) {
            var c = inside[i];
            if (esc2) { esc2 = false; continue; }
            if (c === '\\' && inStr) { esc2 = true; continue; }
            if (c === '"') { inStr = !inStr; continue; }
            if (!inStr) { if (c === '{') depth++; if (c === '}') depth--; }
        }
        if (depth > 0 && !inStr) return { type: 'props-key', componentName: name };
        return null;
    }

    if (typeof wp !== 'undefined' && wp.CodeMirror) {
        wp.CodeMirror.registerHelper('hint', 'pp-json', function (editor) {
            var ctx = getJsonContext();
            if (!ctx) return null;

            var cursor = editor.getCursor();
            var token  = editor.getTokenAt(cursor);
            var ts     = token.string || '';
            var list   = [];

            if (ctx.type === 'component-value') {
                var p = ts.replace(/^"|"$/g, '');
                list = componentNames().filter(function (n) { return n.indexOf(p) === 0; });
            } else if (ctx.type === 'props-key') {
                var comp = getComponentByName(ctx.componentName);
                if (comp && comp.schema && comp.schema.props) {
                    var p2 = ts.replace(/^"|"$/g, '');
                    list = Object.keys(comp.schema.props).filter(function (k) { return k.indexOf(p2) === 0; });
                }
            }

            if (!list.length) return null;
            var from = token.start + (ts[0] === '"' ? 1 : 0);
            var to   = token.end   - (ts[ts.length - 1] === '"' ? 1 : 0);
            return { list: list, from: wp.CodeMirror.Pos(cursor.line, from), to: wp.CodeMirror.Pos(cursor.line, to) };
        });
    }

    // ── Editor init ───────────────────────────────────────────────────────────

    function initEditor() {
        var textarea = document.getElementById('pp-composition-editor');
        if (!textarea) return;

        // CodeMirror disabled in user profile — show raw textarea instead
        if (ppAdminEditor.cmDisabled || !wp || !wp.CodeMirror) {
            textarea.style.display = '';
            textarea.style.width = '100%';
            textarea.style.height = '100%';
            textarea.style.fontFamily = '"JetBrains Mono", Consolas, monospace';
            textarea.style.fontSize = '13px';
            textarea.style.background = '#1e1e1e';
            textarea.style.color = '#d4d4d4';
            textarea.style.border = 'none';
            textarea.style.padding = '12px';
            textarea.style.resize = 'none';
            return;
        }

        var cmSettings = $.extend(true, {}, ppAdminEditor.codeEditorSettings.codemirror || {}, {
            mode:         { name: 'javascript', json: true },
            lineNumbers:  true,
            lineWrapping: false,
            indentUnit:   2,
            tabSize:      2,
            extraKeys: {
                Tab:          'indentMore',
                'Ctrl-Space': function (ed) { wp.CodeMirror.showHint(ed, wp.CodeMirror.hint['pp-json'], { completeSingle: false }); },
                'Ctrl-S':     function () { doSave(); },
                'Cmd-S':      function () { doSave(); },
            },
        });

        cm = wp.CodeMirror.fromTextArea(textarea, cmSettings);

        // Fill full pane height
        function sizeEditor() {
            var $body = $('.pp-pane--editor .pp-pane-body');
            if ($body.length) cm.setSize(null, $body[0].offsetHeight);
        }
        sizeEditor();
        $(window).on('resize', debounce(sizeEditor, 100));

        cm.on('change', function () { runValidation(); runPreview(); });
        cm.on('cursorActivity', function () {
            lastCursor = cm.getCursor();
            updateSchemaTab();
        });
        cm.on('inputRead', function (ed, ch) {
            if (ch.text && ch.text[0] === '"') {
                wp.CodeMirror.showHint(ed, wp.CodeMirror.hint['pp-json'], { completeSingle: false });
            }
        });

        runValidation();
        runPreview();
    }

    // ── Sidebar ───────────────────────────────────────────────────────────────

    function initSidebar() {
        $(document).on('click', '.pp-tab-btn', function () {
            var tab = $(this).data('tab');
            $('.pp-tab-btn').removeClass('pp-tab-btn--active');
            $(this).addClass('pp-tab-btn--active');
            $('.pp-tab-panel').hide();
            $('#pp-tab-' + tab).show();
        });

        $(document).on('click', '.pp-component-insert', function () {
            if (!cm) return;
            var name = $(this).data('name');
            var comp = getComponentByName(name);
            var schema = comp && comp.schema ? comp.schema : {};
            var props  = schema.props || {};

            var starter = {};
            Object.keys(props).forEach(function (k) {
                if (!props[k].required) return;
                var t = props[k].type || 'string';
                if (t === 'array')   starter[k] = [];
                else if (t === 'enum') starter[k] = props[k].values ? props[k].values[0] : '';
                else                 starter[k] = '';
            });

            var newEntry = { component: name, props: starter };
            var current = cm.getValue().trim();

            if (!current) {
                // Empty editor: create fresh array
                cm.setValue(JSON.stringify([newEntry], null, 2));
                cm.setCursor({ line: 1, ch: 0 });
                cm.focus();
                return;
            }

            var parsed;
            try { parsed = JSON.parse(current); } catch (e) { parsed = null; }

            if (!Array.isArray(parsed)) {
                // Not valid array: insert at cursor as raw text
                cm.replaceSelection(JSON.stringify(newEntry, null, 2));
                cm.focus();
                return;
            }

            // Find end positions (the closing `}`) of each top-level array item
            var text = cm.getValue();
            var itemEnds = [];     // char offsets of each top-level `}`
            var depth = 0, inStr = false, esc = false;
            var bracketPos = text.indexOf('[');
            for (var i = bracketPos + 1; i < text.length; i++) {
                var c = text[i];
                if (esc) { esc = false; continue; }
                if (c === '\\' && inStr) { esc = true; continue; }
                if (c === '"') { inStr = !inStr; continue; }
                if (inStr) continue;
                if (c === '{') depth++;
                else if (c === '}') {
                    depth--;
                    if (depth === 0) itemEnds.push(i);
                }
                if (c === ']' && depth === 0) break;
            }

            // Determine which item the cursor is in/after
            var cursorOff = cm.indexFromPos(lastCursor || cm.getCursor());
            var afterIdx = -1; // insert before all items by default
            for (var k = 0; k < itemEnds.length; k++) {
                if (cursorOff > itemEnds[k]) {
                    afterIdx = k;
                }
            }
            // If cursor is within an item (past its start but before its end), insert after that item
            if (afterIdx === -1 && itemEnds.length > 0 && cursorOff > bracketPos) {
                afterIdx = 0;
                for (var m = 0; m < itemEnds.length; m++) {
                    if (cursorOff <= itemEnds[m]) { afterIdx = m; break; }
                }
            }

            var snippet = JSON.stringify(newEntry, null, 2);
            // Indent the snippet to match array indentation (2 spaces)
            var indented = snippet.replace(/\n/g, '\n  ');
            var insertPos, insertText;

            if (afterIdx === -1 || itemEnds.length === 0) {
                // Insert as first item in the array — right after `[`
                var afterBracket = cm.posFromIndex(bracketPos + 1);
                insertText = '\n  ' + indented + (itemEnds.length ? ',' : '');
                insertPos = afterBracket;
            } else if (afterIdx === itemEnds.length - 1) {
                // Insert after the last item
                var lastEnd = cm.posFromIndex(itemEnds[afterIdx]);
                insertText = ',\n  ' + indented;
                insertPos = { line: lastEnd.line, ch: lastEnd.ch + 1 };
            } else {
                // Insert between two items
                var prevEnd = cm.posFromIndex(itemEnds[afterIdx]);
                insertText = ',\n  ' + indented;
                insertPos = { line: prevEnd.line, ch: prevEnd.ch + 1 };
            }

            cm.replaceRange(insertText, insertPos, insertPos);

            // Place cursor at the inserted component and scroll into view
            var insertedLine = insertPos.line + 1;
            cm.setCursor({ line: insertedLine, ch: 0 });
            cm.scrollIntoView(null, cm.getScrollInfo().clientHeight / 3);
            cm.focus();
        });
    }

    // ── Save (AJAX) ───────────────────────────────────────────────────────────

    function setSaveStatus(state, msg) {
        var $s = $('#pp-save-status');
        $s.removeClass('is-saving is-saved is-error').text(msg || '');
        if (state) $s.addClass(state);
    }

    function doSave() {
        if (!cm) return;
        var value = cm.getValue().trim();
        var errors = validateComposition(value);
        if (errors.length) {
            showErrors(errors);
            setSaveStatus('is-error', 'Fix errors first.');
            return;
        }

        $('#pp-save-btn').prop('disabled', true);
        setSaveStatus('is-saving', 'Saving\u2026');

        $.post(ajaxUrl, {
            action:      'pp_save_composition',
            post_id:     postId,
            composition: value,
            nonce:       nonce,
        })
        .done(function (res) {
            if (res.success) {
                setSaveStatus('is-saved', 'Saved');
                setTimeout(function () { setSaveStatus('', ''); }, 3000);
            } else {
                var msg = res.data || 'Save failed.';
                if (msg === 'Invalid nonce.') {
                    msg = 'Session expired. Please reload the page.';
                }
                setSaveStatus('is-error', msg);
            }
        })
        .fail(function () { setSaveStatus('is-error', 'Network error.'); })
        .always(function () { $('#pp-save-btn').prop('disabled', false); });
    }

    // ── Resizable panes ────────────────────────────────────────────────────────

    function initResize() {
        var $panes = $('#pp-workspace .pp-panes');
        if (!$panes.length) return;

        var paneEditor    = $panes.find('.pp-pane--editor')[0];
        var paneReference = $panes.find('.pp-pane--reference')[0];
        var panePreview   = $panes.find('.pp-pane--preview')[0];
        if (!paneEditor || !paneReference || !panePreview) return;

        // Set initial widths (50% / 22% / 28% minus handle space)
        function setInitialWidths() {
            var total = $panes[0].offsetWidth - 10; // 2 handles × 5px
            paneEditor.style.width    = Math.round(total * 0.50) + 'px';
            paneReference.style.width = Math.round(total * 0.22) + 'px';
            panePreview.style.width   = Math.round(total * 0.28) + 'px';
        }
        setInitialWidths();

        var handles = $panes.find('.pp-resize-handle');
        var MIN_PANE = 150;

        handles.each(function () {
            var handle = this;
            var leftName  = handle.getAttribute('data-left');
            var rightName = handle.getAttribute('data-right');

            var leftPane  = $panes.find('.pp-pane--' + leftName)[0];
            var rightPane = $panes.find('.pp-pane--' + rightName)[0];

            $(handle).on('mousedown', function (e) {
                e.preventDefault();
                var startX     = e.clientX;
                var startLeft  = leftPane.offsetWidth;
                var startRight = rightPane.offsetWidth;

                $(handle).addClass('is-dragging');
                $('body').css({ cursor: 'col-resize', userSelect: 'none' });
                // Prevent iframe from stealing mouse events during drag
                $('#pp-preview-frame').css('pointer-events', 'none');

                function onMove(e2) {
                    var dx = e2.clientX - startX;
                    var newLeft  = startLeft + dx;
                    var newRight = startRight - dx;

                    if (newLeft < MIN_PANE) { newLeft = MIN_PANE; newRight = startLeft + startRight - MIN_PANE; }
                    if (newRight < MIN_PANE) { newRight = MIN_PANE; newLeft = startLeft + startRight - MIN_PANE; }

                    leftPane.style.width  = newLeft + 'px';
                    rightPane.style.width = newRight + 'px';

                    if (cm) cm.refresh();
                }

                function onUp() {
                    $(document).off('mousemove', onMove).off('mouseup', onUp);
                    $(handle).removeClass('is-dragging');
                    $('body').css({ cursor: '', userSelect: '' });
                    $('#pp-preview-frame').css('pointer-events', '');
                    if (cm) cm.refresh();
                }

                $(document).on('mousemove', onMove).on('mouseup', onUp);
            });
        });

        $(window).on('resize', debounce(function () {
            var total = $panes[0].offsetWidth - 10;
            var curTotal = paneEditor.offsetWidth + paneReference.offsetWidth + panePreview.offsetWidth;
            if (curTotal < 10) { setInitialWidths(); return; }
            var ratio = total / curTotal;
            paneEditor.style.width    = Math.round(paneEditor.offsetWidth * ratio) + 'px';
            paneReference.style.width = Math.round(paneReference.offsetWidth * ratio) + 'px';
            panePreview.style.width   = Math.round(panePreview.offsetWidth * ratio) + 'px';
            if (cm) cm.refresh();
        }, 100));
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    $(function () {
        initResize();
        initEditor();
        initSidebar();
        $('#pp-save-btn').on('click', doSave);
    });

})(jQuery);
