/**
 * pp-admin-editor.js — PromptingPress Composition Workspace
 *
 * Two-pane editor: accordion (default) | preview, with JSON toggle.
 * All server communication uses wp_ajax_ handlers (cookie auth).
 */

/* global ppAdminEditor, PPEditorLogic, wp, jQuery */
(function ($) {
    'use strict';

    if (typeof ppAdminEditor === 'undefined') return;

    var logic = window.PPEditorLogic;
    if (!logic) {
        if (window.console) console.error('[PromptingPress] pp-editor-logic.js failed to load — editor disabled.');
        return;
    }
    var components   = ppAdminEditor.components || [];
    var ajaxUrl      = ppAdminEditor.ajaxUrl || '';
    var nonce        = ppAdminEditor.nonce || '';
    var postId       = ppAdminEditor.postId || 0;
    var postStatus   = ppAdminEditor.postStatus || 'draft';
    var postLink     = ppAdminEditor.postLink || '';
    var previewLink  = ppAdminEditor.previewLink || '';
    var cm           = null;
    var lastCursor   = null;  // preserved across focus loss
    var isSyncingFromAccordion = false;  // guard flag to prevent sync loops
    var currentView  = 'accordion';      // 'accordion' or 'json'

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
        return logic.validateCompositionData(value, components);
    }

    function showErrors(errors) {
        var $bar = $('#pp-error-bar');
        if (!errors.length) {
            $bar.empty();
            // Clear stale "Fix errors first." if errors are now resolved
            var $s = $('#pp-save-status');
            if ($s.hasClass('is-error') && $s.text() === 'Fix errors first.') {
                setSaveStatus('', '');
            }
            return;
        }
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

    // ── Accordion ─────────────────────────────────────────────────────────────

    function announce(msg) {
        var $live = $('#pp-accordion-live');
        if ($live.length) $live.text(msg);
    }

    /** Snapshot which accordion cards are currently expanded (index → true). */
    function getExpandedMap() {
        var map = {};
        $('#pp-accordion-view .pp-accordion-toggle').each(function () {
            if ($(this).attr('aria-expanded') === 'true') {
                var card = $(this).closest('.pp-accordion-card');
                var idx = parseInt(card.data('comp-idx'), 10);
                if (!isNaN(idx)) map[idx] = true;
            }
        });
        return map;
    }

    function getFirstRequiredPropValue(compData) {
        for (var i = 0; i < compData.fields.length; i++) {
            var f = compData.fields[i];
            if (f.required && f.type === 'string' && f.value) {
                var v = String(f.value);
                return v.length > 40 ? v.slice(0, 40) + '\u2026' : v;
            }
        }
        return '';
    }

    function buildFieldHtml(field, compIdx, fieldIdx, itemIdx) {
        var id = 'pp-field-' + compIdx + '-' + fieldIdx + (itemIdx !== undefined ? '-' + itemIdx : '');
        var reqClass = field.required ? ' pp-accordion-field--required' : '';
        var h = '<div class="pp-accordion-field' + reqClass + '">';
        h += '<label for="' + id + '">' + esc(field.name) + '</label>';

        if (field.type === 'enum' && field.values) {
            h += '<select id="' + id + '" data-comp="' + compIdx + '" data-field="' + field.name + '">';
            field.values.forEach(function (v) {
                var sel = v === field.value ? ' selected' : '';
                h += '<option value="' + esc(v) + '"' + sel + '>' + esc(v) + '</option>';
            });
            h += '</select>';
        } else if (field.multiline) {
            h += '<textarea id="' + id + '" rows="4" data-comp="' + compIdx + '" data-field="' + field.name + '"';
            h += ' placeholder="' + esc(field.description || '') + '"';
            h += '>' + esc(String(field.value || '')) + '</textarea>';
        } else if (field.type === 'string') {
            h += '<input type="text" id="' + id + '" data-comp="' + compIdx + '" data-field="' + field.name + '"';
            h += ' value="' + esc(String(field.value || '')) + '"';
            h += ' placeholder="' + esc(field.description || '') + '"';
            h += ' />';
        }

        h += '</div>';
        return h;
    }

    function buildArrayFieldHtml(field, compIdx, fieldIdx) {
        var items = Array.isArray(field.value) ? field.value : [];
        var subSchema = field.items || {};
        var subKeys = Object.keys(subSchema);
        var h = '<div class="pp-accordion-field pp-accordion-field--required">';
        h += '<label>' + esc(field.name) + '</label>';
        h += '</div>';
        h += '<div class="pp-accordion-array" data-comp="' + compIdx + '" data-field="' + field.name + '">';

        items.forEach(function (item, itemIdx) {
            h += '<div class="pp-accordion-array-item" data-item="' + itemIdx + '">';
            h += '<div class="pp-accordion-array-item-header">';
            h += '<span>Item ' + (itemIdx + 1) + '</span>';
            h += '<button class="pp-array-remove-btn" data-comp="' + compIdx + '" data-field="' + field.name + '" data-item="' + itemIdx + '" aria-label="Remove item ' + (itemIdx + 1) + '">&times;</button>';
            h += '</div>';
            subKeys.forEach(function (sk) {
                var subField = {
                    name: sk,
                    type: 'string',
                    required: !!(subSchema[sk] && subSchema[sk].required),
                    value: item[sk] || '',
                    description: (subSchema[sk] && subSchema[sk].description) || '',
                    multiline: ['body', 'content', 'answer'].indexOf(sk) !== -1
                };
                h += buildFieldHtml(subField, compIdx, field.name + '.' + sk, itemIdx);
            });
            h += '</div>';
        });

        h += '<button class="pp-accordion-add-btn pp-array-add-btn" data-comp="' + compIdx + '" data-field="' + field.name + '">+ Add item</button>';
        h += '</div>';
        return h;
    }

    /**
     * @param {Object} [expandedMap] Map of index→boolean for which cards are expanded.
     *                               Defaults to {0: true} (first card open) when omitted.
     */
    function renderAccordion(expandedMap) {
        if (!cm) return;
        var $container = $('#pp-accordion-view');
        var data = logic.buildAccordionData(cm.getValue(), components);

        if (data.errors.length) return; // stay in current view

        if (!data.components.length) {
            $container.html(
                '<div class="pp-accordion-empty">No components yet</div>' +
                buildInsertDropdown()
            );
            return;
        }

        if (!expandedMap) expandedMap = {};

        var h = buildInsertDropdown();
        data.components.forEach(function (comp, idx) {
            var expanded = !!expandedMap[idx];
            var preview = getFirstRequiredPropValue(comp);
            var previewHtml = preview ? ' <span class="pp-card-preview">\u2014 "' + esc(preview) + '"</span>' : '';

            h += '<div class="pp-accordion-card" data-comp-idx="' + idx + '">';
            h += '<div class="pp-accordion-header" id="pp-card-header-' + idx + '">';
            h += '<button class="pp-accordion-toggle" aria-expanded="' + expanded + '" aria-controls="pp-card-body-' + idx + '">';
            h += (expanded ? '\u25BC' : '\u25B6') + ' <span class="pp-card-name">' + esc(comp.name) + '</span>' + previewHtml;
            h += '</button>';
            h += '<span class="pp-card-actions">';
            h += '<button class="pp-move-btn pp-move-up" data-idx="' + idx + '" aria-label="Move ' + esc(comp.name) + ' up"' + (idx === 0 ? ' disabled' : '') + '>\u2191</button>';
            h += '<button class="pp-move-btn pp-move-down" data-idx="' + idx + '" aria-label="Move ' + esc(comp.name) + ' down"' + (idx === data.components.length - 1 ? ' disabled' : '') + '>\u2193</button>';
            h += '<button class="pp-delete-btn" data-idx="' + idx + '" aria-label="Delete ' + esc(comp.name) + ' component">&times;</button>';
            h += '</span>';
            h += '</div>';

            h += '<div class="pp-accordion-body" id="pp-card-body-' + idx + '" role="region" aria-labelledby="pp-card-header-' + idx + '"';
            h += expanded ? '>' : ' aria-hidden="true" style="overflow:hidden;max-height:0;padding:0 12px;border-top:none;">';

            comp.fields.forEach(function (field, fIdx) {
                if (field.type === 'array') {
                    h += buildArrayFieldHtml(field, idx, fIdx);
                } else {
                    h += buildFieldHtml(field, idx, fIdx);
                }
            });

            h += '</div></div>';
        });
        h += buildInsertDropdown();

        $container.html(h);
    }

    function buildInsertDropdown() {
        var h = '<select class="pp-accordion-insert">';
        h += '<option value="" disabled selected>+ Add component\u2026</option>';
        components.forEach(function (c) {
            h += '<option value="' + esc(c.name) + '">' + esc(c.name) + '</option>';
        });
        h += '</select>';
        return h;
    }

    var syncAccordionToJson = debounce(function () {
        if (!cm) return;
        var data = logic.buildAccordionData(cm.getValue(), components);
        if (data.errors.length) return;

        var $container = $('#pp-accordion-view');

        data.components.forEach(function (comp, compIdx) {
            comp.fields.forEach(function (field) {
                if (field.type === 'array') {
                    // Rebuild array value from DOM
                    var items = [];
                    var $arrayItems = $container.find('.pp-accordion-array[data-comp="' + compIdx + '"][data-field="' + field.name + '"] .pp-accordion-array-item');
                    var subSchema = field.items || {};
                    var subKeys = Object.keys(subSchema);
                    $arrayItems.each(function (itemIdx) {
                        var item = {};
                        subKeys.forEach(function (sk) {
                            var $input = $(this).find('[data-field="' + field.name + '.' + sk + '"][data-comp="' + compIdx + '"]');
                            if ($input.length) item[sk] = $input.val();
                        }.bind(this));
                        items.push(item);
                    });
                    field.value = items;
                    field.userTouched = true;
                } else {
                    var $input = $container.find('[data-comp="' + compIdx + '"][data-field="' + field.name + '"]');
                    if ($input.length) {
                        field.value = $input.val();
                        field.userTouched = true;
                    }
                }
            });
        });

        var json = logic.serializeAccordionData(data.components);
        isSyncingFromAccordion = true;
        try { cm.setValue(json); }
        finally { isSyncingFromAccordion = false; }
    }, 300);

    function initViewToggle() {
        $(document).on('click', '#pp-view-toggle', function () {
            var $btn = $(this);
            var $accordion = $('#pp-accordion-view');
            var $json = $('#pp-json-view');

            if (currentView === 'accordion') {
                // Switch to JSON view
                $accordion.hide();
                $json.show();
                $btn.text('Accordion');
                currentView = 'json';
                if (cm) cm.refresh();
            } else {
                // Switch to accordion view — parse first
                if (cm) {
                    var errors = validateComposition(cm.getValue());
                    if (errors.length) {
                        showErrors(errors);
                        return; // stay in JSON view
                    }
                }
                $json.hide();
                $accordion.show();
                $btn.text('JSON');
                currentView = 'accordion';
                renderAccordion();
            }
        });
    }

    function initAccordionEvents() {
        var $container = $('#pp-accordion-view');

        // Expand/collapse
        $container.on('click', '.pp-accordion-toggle', function () {
            var $header = $(this);
            var expanded = $header.attr('aria-expanded') === 'true';
            var $body = $('#' + $header.attr('aria-controls'));

            if (expanded) {
                $header.attr('aria-expanded', 'false');
                $body.attr('aria-hidden', 'true').css({ overflow: 'hidden', maxHeight: '0', padding: '0 12px', borderTop: 'none' });
                $header.find('.pp-accordion-toggle').first().html($header.html().replace('\u25BC', '\u25B6'));
            } else {
                $header.attr('aria-expanded', 'true');
                $body.removeAttr('aria-hidden').css({ overflow: '', maxHeight: '', padding: '', borderTop: '' });
                $header.find('.pp-accordion-toggle').first().html($header.html().replace('\u25B6', '\u25BC'));
            }
            // Fix: update the button's own text since we're on the button itself
            var text = $(this).html();
            if (expanded) {
                $(this).html(text.replace('\u25BC', '\u25B6'));
            } else {
                $(this).html(text.replace('\u25B6', '\u25BC'));
            }
        });

        // Field change
        $container.on('input change', 'input, textarea, select', function () {
            syncAccordionToJson();
        });

        // Field validation on blur (required fields)
        $container.on('blur', 'input, textarea', function () {
            var $el = $(this);
            var compIdx = parseInt($el.data('comp'), 10);
            var fieldName = $el.data('field');
            // Check if this field is required
            var data = logic.buildAccordionData(cm.getValue(), components);
            if (!data.components[compIdx]) return;
            var field = null;
            for (var i = 0; i < data.components[compIdx].fields.length; i++) {
                if (data.components[compIdx].fields[i].name === fieldName) {
                    field = data.components[compIdx].fields[i];
                    break;
                }
            }
            if (field && field.required && !$el.val().trim()) {
                $el.addClass('pp-field-error');
            } else {
                $el.removeClass('pp-field-error');
            }
        });

        // Component insert
        $container.on('change', '.pp-accordion-insert', function () {
            var name = $(this).val();
            if (!name) return;
            $(this).val(''); // reset dropdown

            var comp = getComponentByName(name);
            var schema = comp && comp.schema ? comp.schema : {};
            var props = schema.props || {};
            var starter = {};
            Object.keys(props).forEach(function (k) {
                if (!props[k].required) return;
                var t = props[k].type || 'string';
                if (t === 'array') starter[k] = [];
                else if (t === 'enum') starter[k] = props[k].values ? props[k].values[0] : '';
                else starter[k] = '';
            });

            var parsed;
            try { parsed = JSON.parse(cm.getValue()); } catch (e) { parsed = []; }
            if (!Array.isArray(parsed)) parsed = [];
            parsed.push({ component: name, props: starter });

            isSyncingFromAccordion = true;
            try { cm.setValue(JSON.stringify(parsed, null, 2)); }
            finally { isSyncingFromAccordion = false; }

            renderAccordion();
            announce(name + ' component added');

            // Scroll to new card and expand it
            setTimeout(function () {
                var $cards = $container.find('.pp-accordion-card');
                var $last = $cards.last();
                if ($last.length) {
                    $last[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Expand the new card
                    var $toggle = $last.find('.pp-accordion-toggle');
                    if ($toggle.attr('aria-expanded') === 'false') $toggle.trigger('click');
                }
            }, 50);
        });

        // Move up
        $container.on('click', '.pp-move-up', function () {
            var idx = parseInt($(this).data('idx'), 10);
            if (idx <= 0) return;
            var parsed;
            try { parsed = JSON.parse(cm.getValue()); } catch (e) { return; }
            if (!Array.isArray(parsed) || idx >= parsed.length) return;
            // Snapshot expand state, then shift to follow the swap
            var oldMap = getExpandedMap();
            var newMap = {};
            Object.keys(oldMap).forEach(function (k) {
                var n = parseInt(k, 10);
                if (n === idx) newMap[n - 1] = true;
                else if (n === idx - 1) newMap[n + 1] = true;
                else newMap[n] = true;
            });
            var temp = parsed[idx];
            parsed[idx] = parsed[idx - 1];
            parsed[idx - 1] = temp;
            isSyncingFromAccordion = true;
            try { cm.setValue(JSON.stringify(parsed, null, 2)); }
            finally { isSyncingFromAccordion = false; }
            renderAccordion(newMap);
            announce(temp.component + ' moved up');
            var $header = $container.find('.pp-accordion-card[data-comp-idx="' + (idx - 1) + '"] .pp-accordion-toggle');
            if ($header.length) $header.focus();
        });

        // Move down
        $container.on('click', '.pp-move-down', function () {
            var idx = parseInt($(this).data('idx'), 10);
            var parsed;
            try { parsed = JSON.parse(cm.getValue()); } catch (e) { return; }
            if (!Array.isArray(parsed) || idx >= parsed.length - 1) return;
            var oldMap = getExpandedMap();
            var newMap = {};
            Object.keys(oldMap).forEach(function (k) {
                var n = parseInt(k, 10);
                if (n === idx) newMap[n + 1] = true;
                else if (n === idx + 1) newMap[n - 1] = true;
                else newMap[n] = true;
            });
            var temp = parsed[idx];
            parsed[idx] = parsed[idx + 1];
            parsed[idx + 1] = temp;
            isSyncingFromAccordion = true;
            try { cm.setValue(JSON.stringify(parsed, null, 2)); }
            finally { isSyncingFromAccordion = false; }
            renderAccordion(newMap);
            announce(temp.component + ' moved down');
            var $header = $container.find('.pp-accordion-card[data-comp-idx="' + (idx + 1) + '"] .pp-accordion-toggle');
            if ($header.length) $header.focus();
        });

        // Delete
        $container.on('click', '.pp-delete-btn', function () {
            var idx = parseInt($(this).data('idx'), 10);
            var parsed;
            try { parsed = JSON.parse(cm.getValue()); } catch (e) { return; }
            if (!Array.isArray(parsed) || idx >= parsed.length) return;
            var oldMap = getExpandedMap();
            var newMap = {};
            Object.keys(oldMap).forEach(function (k) {
                var n = parseInt(k, 10);
                if (n === idx) { /* deleted, drop it */ }
                else if (n > idx) newMap[n - 1] = true;
                else newMap[n] = true;
            });
            var removed = parsed.splice(idx, 1)[0];
            isSyncingFromAccordion = true;
            try { cm.setValue(JSON.stringify(parsed, null, 2)); }
            finally { isSyncingFromAccordion = false; }
            renderAccordion(newMap);
            announce(removed.component + ' component deleted');
            // Focus next card header, or previous, or add button
            var $cards = $container.find('.pp-accordion-card');
            if ($cards.length) {
                var focusIdx = idx < $cards.length ? idx : $cards.length - 1;
                $cards.eq(focusIdx).find('.pp-accordion-toggle').focus();
            } else {
                $container.find('.pp-accordion-insert').focus();
            }
        });

        // Array item add
        $container.on('click', '.pp-array-add-btn', function () {
            var compIdx = parseInt($(this).data('comp'), 10);
            var fieldName = $(this).data('field');
            var parsed;
            try { parsed = JSON.parse(cm.getValue()); } catch (e) { return; }
            if (!Array.isArray(parsed) || !parsed[compIdx]) return;

            var comp = getComponentByName(parsed[compIdx].component);
            var schema = comp && comp.schema ? comp.schema : {};
            var propDef = (schema.props || {})[fieldName];
            var subKeys = propDef && propDef.items ? Object.keys(propDef.items) : [];
            var newItem = {};
            subKeys.forEach(function (k) { newItem[k] = ''; });

            if (!parsed[compIdx].props) parsed[compIdx].props = {};
            if (!Array.isArray(parsed[compIdx].props[fieldName])) parsed[compIdx].props[fieldName] = [];
            parsed[compIdx].props[fieldName].push(newItem);

            isSyncingFromAccordion = true;
            try { cm.setValue(JSON.stringify(parsed, null, 2)); }
            finally { isSyncingFromAccordion = false; }
            renderAccordion();
        });

        // Array item remove
        $container.on('click', '.pp-array-remove-btn', function () {
            var compIdx = parseInt($(this).data('comp'), 10);
            var fieldName = $(this).data('field');
            var itemIdx = parseInt($(this).data('item'), 10);
            var parsed;
            try { parsed = JSON.parse(cm.getValue()); } catch (e) { return; }
            if (!Array.isArray(parsed) || !parsed[compIdx]) return;
            if (!parsed[compIdx].props || !Array.isArray(parsed[compIdx].props[fieldName])) return;
            parsed[compIdx].props[fieldName].splice(itemIdx, 1);

            isSyncingFromAccordion = true;
            try { cm.setValue(JSON.stringify(parsed, null, 2)); }
            finally { isSyncingFromAccordion = false; }
            renderAccordion();
        });
    }

    // ── Autocomplete ──────────────────────────────────────────────────────────

    function getJsonContext() {
        if (!cm) return null;
        var text = cm.getRange({ line: 0, ch: 0 }, cm.getCursor());
        return logic.getJsonContextFromText(text, componentNames());
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
                'Ctrl-S':     function () { doContextualSave(); },
                'Cmd-S':      function () { doContextualSave(); },
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

        cm.on('change', function () {
            runValidation();
            runPreview();
            // Do not re-render accordion when the change came from accordion sync
        });
        cm.on('cursorActivity', function () {
            lastCursor = cm.getCursor();
        });
        cm.on('inputRead', function (ed, ch) {
            if (ch.text && ch.text[0] === '"') {
                wp.CodeMirror.showHint(ed, wp.CodeMirror.hint['pp-json'], { completeSingle: false });
            }
        });

        runValidation();
        runPreview();
    }

    // (Sidebar removed — accordion replaces the reference pane)

    // ── Save (AJAX) ───────────────────────────────────────────────────────────

    function setSaveStatus(state, msg) {
        var $s = $('#pp-save-status');
        $s.removeClass('is-saving is-saved is-error').text(msg || '');
        if (state) $s.addClass(state);
    }

    function doSaveDraft() {
        if (!cm) return;
        var value = cm.getValue().trim();
        var errors = validateComposition(value);
        if (errors.length) {
            showErrors(errors);
            setSaveStatus('is-error', 'Fix errors first.');
            return;
        }

        $('#pp-save-btn').prop('disabled', true);
        setSaveStatus('is-saving', 'Saving draft\u2026');

        $.post(ajaxUrl, {
            action:      'pp_save_composition',
            post_id:     postId,
            composition: value,
            nonce:       nonce,
        })
        .done(function (res) {
            if (res.success) {
                setSaveStatus('is-saved', 'Draft saved');
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

    // ── Title editor ─────────────────────────────────────────────────────────

    function initTitleEditor() {
        var $input = $('#pp-page-title');
        if (!$input.length) return;

        var titleTimer;

        $input.on('input', function () {
            clearTimeout(titleTimer);
            titleTimer = setTimeout(doSaveTitle, 800);
        });

        $input.on('blur', function () {
            clearTimeout(titleTimer);
            doSaveTitle();
        });
    }

    function doSaveTitle() {
        var $input = $('#pp-page-title');
        var title = $input.val();
        $.post(ajaxUrl, {
            action:  'pp_save_title',
            post_id: postId,
            title:   title,
            nonce:   nonce,
        });
        document.title = (title.trim() || 'Untitled') + ' \u2014 Composition Editor';
    }

    // ── Publish / Update ─────────────────────────────────────────────────────

    function doPublishOrUpdate() {
        if (!cm) return;
        var value = cm.getValue().trim();
        var errors = validateComposition(value);
        if (errors.length) {
            showErrors(errors);
            setSaveStatus('is-error', 'Fix errors first.');
            return;
        }

        var wasPublished = (postStatus === 'publish');
        var $btn = $('#pp-publish-btn');
        $btn.prop('disabled', true);
        $('#pp-save-btn').prop('disabled', true);
        setSaveStatus('is-saving', wasPublished ? 'Updating\u2026' : 'Publishing\u2026');

        $.post(ajaxUrl, {
            action:      'pp_publish_page',
            post_id:     postId,
            composition: value,
            nonce:       nonce,
        })
        .done(function (res) {
            if (res.success) {
                postStatus  = res.data.status;
                postLink    = res.data.post_link;
                previewLink = res.data.preview_link;

                $btn.text('Update').data('status', 'publish');

                $('#pp-view-link')
                    .text('View \u2197')
                    .attr('href', postLink);

                $('#pp-status-badge').remove();

                setSaveStatus('is-saved', wasPublished ? 'Updated' : 'Published');
                setTimeout(function () { setSaveStatus('', ''); }, 3000);
            } else {
                var msg = res.data || (wasPublished ? 'Update failed.' : 'Publish failed.');
                if (msg === 'Invalid nonce.') msg = 'Session expired. Please reload the page.';
                setSaveStatus('is-error', msg);
            }
        })
        .fail(function () { setSaveStatus('is-error', 'Network error.'); })
        .always(function () {
            $btn.prop('disabled', false);
            // Once published, remove the draft-only action. Check postStatus (updated
            // in .done()) so this handles both the success path and any edge case where
            // .done() ran but the remove did not complete before .always() fired.
            if (postStatus === 'publish') {
                $('#pp-save-btn').remove();
            } else {
                $('#pp-save-btn').prop('disabled', false);
            }
        });
    }

    function doContextualSave() {
        if (postStatus === 'publish') {
            doPublishOrUpdate();
        } else {
            doSaveDraft();
        }
    }

    function initPublishButton() {
        $('#pp-publish-btn').on('click', doPublishOrUpdate);
    }

    // ── Resizable panes ────────────────────────────────────────────────────────

    function initResize() {
        var $panes = $('#pp-workspace .pp-panes');
        if (!$panes.length) return;

        var paneEditor  = $panes.find('.pp-pane--editor')[0];
        var panePreview = $panes.find('.pp-pane--preview')[0];
        if (!paneEditor || !panePreview) return;

        // Two panes: 45% editor, 55% preview (one handle)
        function setInitialWidths() {
            var total = $panes[0].offsetWidth - 5; // 1 handle × 5px
            paneEditor.style.width  = Math.round(total * 0.45) + 'px';
            panePreview.style.width = Math.round(total * 0.55) + 'px';
        }
        setInitialWidths();

        var $handle = $panes.find('.pp-resize-handle');
        var MIN_PANE = 150;

        $handle.on('mousedown', function (e) {
            e.preventDefault();
            var startX     = e.clientX;
            var startLeft  = paneEditor.offsetWidth;
            var startRight = panePreview.offsetWidth;

            $handle.addClass('is-dragging');
            $('body').css({ cursor: 'col-resize', userSelect: 'none' });
            $('#pp-preview-frame').css('pointer-events', 'none');

            function onMove(e2) {
                var dx = e2.clientX - startX;
                var newLeft  = startLeft + dx;
                var newRight = startRight - dx;

                if (newLeft < MIN_PANE) { newLeft = MIN_PANE; newRight = startLeft + startRight - MIN_PANE; }
                if (newRight < MIN_PANE) { newRight = MIN_PANE; newLeft = startLeft + startRight - MIN_PANE; }

                paneEditor.style.width  = newLeft + 'px';
                panePreview.style.width = newRight + 'px';

                // Narrow pane responsive class
                var $accordion = $('#pp-accordion-view');
                if (newLeft < 300) {
                    $accordion.addClass('pp-accordion--narrow');
                } else {
                    $accordion.removeClass('pp-accordion--narrow');
                }

                if (cm) cm.refresh();
            }

            function onUp() {
                $(document).off('mousemove', onMove).off('mouseup', onUp);
                $handle.removeClass('is-dragging');
                $('body').css({ cursor: '', userSelect: '' });
                $('#pp-preview-frame').css('pointer-events', '');
                if (cm) cm.refresh();
            }

            $(document).on('mousemove', onMove).on('mouseup', onUp);
        });

        $(window).on('resize', debounce(function () {
            var total = $panes[0].offsetWidth - 5;
            var curTotal = paneEditor.offsetWidth + panePreview.offsetWidth;
            if (curTotal < 10) { setInitialWidths(); return; }
            var ratio = total / curTotal;
            paneEditor.style.width  = Math.round(paneEditor.offsetWidth * ratio) + 'px';
            panePreview.style.width = Math.round(panePreview.offsetWidth * ratio) + 'px';
            if (cm) cm.refresh();
        }, 100));
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    $(function () {
        initResize();
        initEditor();
        initViewToggle();
        initAccordionEvents();
        initTitleEditor();
        initPublishButton();
        $('#pp-save-btn').on('click', doSaveDraft);
        // Render accordion on load (default view)
        renderAccordion();
    });

})(jQuery);
