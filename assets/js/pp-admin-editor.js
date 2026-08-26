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
    // Optimistic-locking baseline (#13): the composition version this editor loaded. Sent
    // as expected_version on every save/publish so an interleaved write (the AI chat, a
    // CLI action, another tab) is rejected with composition_conflict instead of silently
    // clobbered. Refreshed from each successful save's response so a second save in the
    // same session doesn't false-conflict against the editor's own prior write.
    var currentVersion = (typeof ppAdminEditor.compositionVersion === 'number')
        ? ppAdminEditor.compositionVersion
        : parseInt(ppAdminEditor.compositionVersion, 10) || 0;
    var cm           = null;
    var lastCursor   = null;  // preserved across focus loss
    var isSyncingFromAccordion = false;  // guard flag to prevent sync loops
    var currentView  = 'accordion';      // 'accordion' or 'json'
    var invariantBlocked = false;
    var lastInvariantResult = null;
    // Stored-composition classification (#750), decided in PHP by the one classifier and
    // shipped here: null on a readable page, else {error, message, repair}. The client
    // renders it and never re-derives it — a second implementation of "is this corrupt?"
    // would be the second spelling #650/#652 exists to prevent.
    var storedIntegrity = (ppAdminEditor.compositionIntegrity && ppAdminEditor.compositionIntegrity.error)
        ? ppAdminEditor.compositionIntegrity
        : null;
    var corruptionBlocked = false;

    // ── Helpers ───────────────────────────────────────────────────────────────

    // Debounced call with a `flush` handle, so a caller that is about to read the
    // state the debounced function WRITES can settle it first.
    //
    //   trailing edge (normal)   ...call, call, call ──300ms──> fn()
    //   flush()                  ...call, call, flush ─────────> fn() immediately
    //
    // `scheduled` tracks pendency explicitly rather than testing the timer handle:
    // setTimeout returns a number in browsers and an object in Node, and a falsy
    // handle would silently make flush a no-op.
    //
    // flush() clears the timer BEFORE invoking, so the trailing edge cannot also
    // fire afterwards — a second run would land after the caller re-rendered and
    // read a DOM that no longer matches what it captured. Args are dropped once
    // invoked, so a later flush can never replay a stale call.
    function debounce(fn, ms) {
        var timer = null, pendingArgs = null, scheduled = false;

        function invoke() {
            var args = pendingArgs;
            scheduled = false; timer = null; pendingArgs = null;
            fn.apply(null, args);
        }

        function debounced() {
            pendingArgs = arguments;
            if (scheduled) clearTimeout(timer);
            timer = setTimeout(invoke, ms);
            scheduled = true;
        }

        // No-op when nothing is pending: every caller of flushPendingFieldEdits
        // (the six structural handlers, save, publish, the view toggle) calls it
        // unconditionally, and an operation with no pending edit must not
        // rewrite JSON.
        debounced.flush = function () {
            if (!scheduled) return;
            clearTimeout(timer);
            invoke();
        };

        return debounced;
    }

    // Attribute-safe escaping for the markup this file builds by concatenation.
    // Delegates to the shared implementation in pp-editor-logic.js so the escaper
    // that SHIPS is the escaper the tests exercise: this file is an IIFE with no
    // exports, so anything defined here could only ever be pinned by a copy of
    // itself, and a copy stays green while the original regresses.
    function esc(text) {
        return logic.escapeHtml(text);
    }

    function getComponentByName(name) {
        for (var i = 0; i < components.length; i++) {
            if (components[i].name === name) return components[i];
        }
        return null;
    }

    // Autocomplete offers only composable components. Site chrome (nav/footer)
    // stays in `components` because the preview renders it, but suggesting it
    // would lead the author straight into a save rejection (#223).
    function componentNames() {
        return components
            .filter(function (c) { return !c.templateOwned; })
            .map(function (c) { return c.name; });
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

    // `compIdx` / `itemIdx` are always loop indices (numbers) from renderAccordion
    // and buildArrayFieldHtml, so they carry no escaping obligation.
    //
    // `id` is defence in depth. buildArrayFieldHtml passes `field.name + '.' + sk`
    // as fieldIdx, which looks like it puts a composition-supplied name into the id,
    // but that branch is reachable only for SCHEMA-declared array props: `field.items`
    // is assigned in one place (the `spec.type === 'array' && spec.items` branch of
    // buildAccordionData), so a
    // pass-through array prop has no `items`, `subKeys` is empty, and the loop that
    // composes that fieldIdx never runs. Escaping it costs one call and keeps that
    // from being a premise the schema shape could quietly stop satisfying.
    //
    // `field.name` IS caller-supplied. Escape both once here and reuse everywhere.
    // ONE id scheme, two builders. buildDisplayOnlyFieldHtml composes the same shape
    // for its own control, and a scheme that drifted between them would show up only
    // as a <label for> pointing at nothing — invisible to every assertion.
    function fieldElementId(compIdx, fieldIdx, itemIdx) {
        return 'pp-field-' + compIdx + '-' + fieldIdx + (itemIdx !== undefined ? '-' + itemIdx : '');
    }

    function buildFieldHtml(field, compIdx, fieldIdx, itemIdx) {
        var id = fieldElementId(compIdx, fieldIdx, itemIdx);
        var idAttr   = esc(id);
        var nameAttr = esc(field.name);
        var reqClass = field.required ? ' pp-accordion-field--required' : '';
        var h = '<div class="pp-accordion-field' + reqClass + '">';
        h += '<label for="' + idAttr + '">' + nameAttr + '</label>';

        // The two value-bearing branches below (textarea, text input) hand the
        // value straight to esc(), rather than through String(value || '').
        // The `|| ''` in that idiom is falsy-based, so it does not mean "default
        // when absent" — it also swallows the values 0 and false, which then
        // render as empty and are read back off the DOM as the empty string by
        // the next sync. esc() coerces only null/undefined to '' (see escapeHtml
        // in pp-editor-logic.js), which is the rule this wants: absent renders
        // empty, present renders its own text. Strings are unaffected either way.
        //
        // Since #745 a 0 or false under a prop the schema declares `type: "string"`
        // no longer reaches this branch at all: nonStringValueDiffs reports it as
        // drift and the composition is routed to JSON-only mode before anything is
        // rendered. The rule above still governs every value that DOES arrive here
        // — an undeclared pass-through prop is the case that still carries one —
        // and it stays because a falsy-based default would be wrong for those too.
        if (field.type === 'enum' && field.values) {
            h += '<select id="' + idAttr + '" data-comp="' + compIdx + '" data-field="' + nameAttr + '">';
            // The <select> is built from the advertised `values` and nothing else
            // (#605). Every shipped enum is `strict: true` (#579), so the only way to
            // hold an unadvertised value is stale storage — and tolerating stale data
            // is an explicit non-goal. A stored value outside `values` therefore
            // matches no option and the browser selects the FIRST advertised one.
            //
            // Precisely, because it is easy to overstate: that is `values[0]`, which is
            // not necessarily the prop's schema `default` (hero.layout advertises
            // `left` first but defaults to `centered`). So the fallback shown here is
            // "the first advertised value", not "what the renderer would have done".
            field.values.forEach(function (v) {
                var sel = v === field.value ? ' selected' : '';
                h += '<option value="' + esc(v) + '"' + sel + '>' + esc(v) + '</option>';
            });
            h += '</select>';
        } else if (field.multiline) {
            h += '<textarea id="' + idAttr + '" rows="4" data-comp="' + compIdx + '" data-field="' + nameAttr + '"';
            h += ' placeholder="' + esc(field.description || '') + '"';
            h += '>' + esc(field.value) + '</textarea>';
        } else if (field.type === 'string') {
            h += '<input type="text" id="' + idAttr + '" data-comp="' + compIdx + '" data-field="' + nameAttr + '"';
            h += ' value="' + esc(field.value) + '"';
            h += ' placeholder="' + esc(field.description || '') + '"';
            h += ' />';
        }

        h += '</div>';
        return h;
    }

    // A row sub-key whose declared type a text control cannot round-trip: a list or a
    // map (#805). It is SHOWN — as the JSON the author actually stored, not as
    // `one,two` or `[object Object]` — and it is not editable here, and it says so.
    //
    // IT CARRIES NO data-comp / data-field, which is what makes it safe rather than
    // merely read-only: findByCompField matches on those two attributes, so the sync
    // can never resolve this control and can never write its text into the buffer.
    // `readonly` alone would not do it — a readonly (or disabled) control is still
    // matched by find('input, textarea, select') and still answers .val().
    //
    // The value is stringified with JSON.stringify rather than esc(String(value)) so
    // the display is the real value and can be copied straight into the JSON view.
    //
    // TWO THINGS IT DELIBERATELY DOES NOT DO, because both would cost more screen than
    // they carry information:
    //
    //   AN UNSET VALUE RENDERS NO CONTROL AT ALL. `section.panel_items` declares a
    //   `style` on every row and almost no row has one, so a control per row would put
    //   an empty box and a caption on eight rows to display nothing — taller than the
    //   two short inputs this replaced, which is a regression dressed as honesty. The
    //   field is still named, once, by the note below.
    //
    //   THE NOTE IS PER FIELD, NOT PER ROW. "bullets is a list" is a fact about the
    //   SCHEMA, so repeating it under every card says nothing new the second time and
    //   a screen reader would read it once per row. One note per array field, and
    //   every row's control points aria-describedby at that one id — several elements
    //   sharing one describedby target is the intended ARIA shape for exactly this.
    var DISPLAY_ONLY_TYPE_WORDS = { array: 'list', object: 'object' };

    /** The id of the one note shared by every display-only control in an array field. */
    function displayOnlyNoteId(compIdx, fieldName) {
        return 'pp-note-' + compIdx + '-' + fieldName;
    }

    /**
     * The single sentence naming every sub-key of this field the accordion cannot edit.
     *
     * Names the sub-keys AND their kinds, so the author learns what exists and why it
     * is not here without opening the schema: `bullets (list) and style (object) are
     * edited in the JSON view.`
     */
    function displayOnlyNoteText(subKeys) {
        var parts = subKeys.map(function (entry) {
            return entry.key + ' (' + (DISPLAY_ONLY_TYPE_WORDS[entry.type] || 'object') + ')';
        });
        var subject = parts.length === 1
            ? parts[0]
            : parts.slice(0, -1).join(', ') + ' and ' + parts[parts.length - 1];
        return subject + (parts.length === 1 ? ' is' : ' are') + ' edited in the JSON view.';
    }

    // JSON.stringify rather than String(): it is the honest display, it round-trips
    // into the JSON view by copy-paste, and it is also the SAFER call. String() coerces
    // through ToPrimitive, so a parsed-JSON object carrying a non-callable own
    // `toString` key throws TypeError (see textForm's docblock in pp-editor-logic.js) —
    // at boot, on exactly the page this control exists to make repairable.
    // JSON.stringify does not consult toString at all.
    //
    // The catch and the undefined test are belt-and-braces on a value that came from
    // JSON.parse: the remaining throw sources (circular references, BigInt, a callable
    // throwing toJSON) and the remaining undefined-returning inputs (function, symbol)
    // are all unreachable from parsed JSON. They stay because the alternative failure
    // is the whole accordion refusing to render with no notice, which is the outcome
    // this control is meant to prevent.
    function displayOnlyText(value) {
        if (value === null || value === undefined || value === '') return '';
        try {
            var json = JSON.stringify(value);
            return json === undefined ? '' : json;
        } catch (e) {
            return '';
        }
    }

    /**
     * Rows tall enough to show the value without asking for a drag.
     *
     * A textarea's resize grip is MOUSE ONLY, so a fixed two rows leaves a keyboard
     * author arrowing through a two-line window over a value they cannot edit. Sizing
     * to the content puts the ordinary case (a four-slot style map is about 120
     * characters) fully on screen, and the cap keeps a pathological value from taking
     * the whole card — past the cap the grip is still there for a mouse.
     *
     * The 60 is a rough characters-per-row for a 12px monospace control in a typical
     * pane, not a measurement: the pane is resizable, so no constant is right for every
     * width. Being approximately right beats being exactly two.
     */
    function displayOnlyRows(text) {
        return Math.min(8, Math.max(2, Math.ceil(text.length / 60)));
    }

    function buildDisplayOnlyFieldHtml(subKey, subDef, value, compIdx, fieldName, itemIdx) {
        var text = displayOnlyText(value);
        // Nothing stored: the field is named once by the field-level note, and a box
        // showing nothing is not worth the height. See the block comment above.
        if (text === '') return '';

        var idAttr   = esc(fieldElementId(compIdx, fieldName + '.' + subKey, itemIdx));
        var noteId   = esc(displayOnlyNoteId(compIdx, fieldName));
        // Unreachable today — subFieldIsDisplayOnly has already confirmed a truthy
        // subDef — and kept because its failure would be a silently missing marker.
        var reqClass = (subDef && subDef.required) ? ' pp-accordion-field--required' : '';

        var h = '<div class="pp-accordion-field pp-accordion-field--display-only' + reqClass + '">';
        h += '<label for="' + idAttr + '">' + esc(subKey) + '</label>';
        h += '<textarea id="' + idAttr + '" rows="' + displayOnlyRows(text) + '" readonly';
        h += ' aria-describedby="' + noteId + '">';
        h += esc(text);
        h += '</textarea>';
        h += '</div>';
        return h;
    }

    function buildArrayFieldHtml(field, compIdx, fieldIdx) {
        var items = Array.isArray(field.value) ? field.value : [];
        var subSchema = field.items || {};
        var subKeys = Object.keys(subSchema);
        var nameAttr = esc(field.name);
        var h = '<div class="pp-accordion-field pp-accordion-field--required">';
        h += '<label>' + nameAttr + '</label>';
        h += '</div>';

        // One note for the whole field, naming every sub-key the accordion cannot edit
        // and what kind each is. Emitted whether or not any row currently HOLDS one of
        // those values, because the fact is about the schema: it is how an author
        // learns `bullets` exists at all now that an unset one renders no control.
        var displayOnly = subKeys.filter(function (sk) {
            return logic.subFieldIsDisplayOnly(subSchema[sk]);
        }).map(function (sk) {
            return { key: sk, type: subSchema[sk].type };
        });
        if (displayOnly.length) {
            h += '<p class="pp-accordion-field__note pp-accordion-array-note" id="' +
                esc(displayOnlyNoteId(compIdx, field.name)) + '">' +
                esc(displayOnlyNoteText(displayOnly)) + '</p>';
        }

        h += '<div class="pp-accordion-array" data-comp="' + compIdx + '" data-field="' + nameAttr + '">';

        items.forEach(function (item, itemIdx) {
            h += '<div class="pp-accordion-array-item" data-item="' + itemIdx + '">';
            h += '<div class="pp-accordion-array-item-header">';
            h += '<span>Item ' + (itemIdx + 1) + '</span>';
            h += '<button class="pp-array-remove-btn" data-comp="' + compIdx + '" data-field="' + nameAttr + '" data-item="' + itemIdx + '" aria-label="Remove item ' + (itemIdx + 1) + '">&times;</button>';
            h += '</div>';
            subKeys.forEach(function (sk) {
                // A sub-key declaring a container gets the read-only display above
                // instead of a text control. Every other declaration keeps the text
                // control: a string or enum round-trips through it faithfully, and a
                // number is SHOWN faithfully and settled on read by
                // reconcileSubFieldTypes rather than by parsing the text back.
                if (logic.subFieldIsDisplayOnly(subSchema[sk])) {
                    h += buildDisplayOnlyFieldHtml(sk, subSchema[sk], item[sk], compIdx, field.name, itemIdx);
                    return;
                }
                var subField = {
                    name: sk,
                    type: 'string',
                    required: !!(subSchema[sk] && subSchema[sk].required),
                    // Same rule as the scalar branch of buildFieldHtml: coercing
                    // here with `|| ''` would blank a falsy stored value before the
                    // renderer ever sees it, so fixing only that branch would still
                    // leave row values of those types rendering empty. esc() applies
                    // the null/undefined rule once, at the point of render.
                    //
                    // Since #745 the surviving cases here are narrower than they look.
                    // A sub-key the schema declares `type: "string"` holding a 0 or
                    // false is drift and never reaches this code — the composition is
                    // routed to JSON-only mode first. What still arrives is a falsy
                    // STRING, and a falsy value under a declaration this branch still
                    // shows as text: since #805 `array` and `object` sub-keys are gone
                    // to the read-only display above, but a `number` sub-key
                    // (items[].image_id) still renders here, so a stored 0 must render
                    // as `0` rather than as empty. What it must not do is come BACK as
                    // the string "0" on an unrelated edit, and that is settled on the
                    // read by reconcileSubFieldTypes, not here.
                    value: item[sk],
                    description: (subSchema[sk] && subSchema[sk].description) || '',
                    multiline: ['body', 'content', 'answer'].indexOf(sk) !== -1
                };
                h += buildFieldHtml(subField, compIdx, field.name + '.' + sk, itemIdx);
            });
            h += '</div>';
        });

        h += '<button class="pp-accordion-add-btn pp-array-add-btn" data-comp="' + compIdx + '" data-field="' + nameAttr + '">+ Add item</button>';
        h += '</div>';
        return h;
    }

    /**
     * @param {Object} [expandedMap] Map of index→boolean for which cards are expanded.
     *                               Defaults to {0: true} (first card open) when omitted.
     */
    function renderAccordion(expandedMap) {
        if (!cm) return;
        // Corruption is checked FIRST and separately from the invariant (#750): both end in
        // JSON-only mode, but they are different states with different notices, and the
        // stored row's classification outranks a round-trip prediction about it.
        if (corruptionBlocked) {
            showCorruptionNotice(storedIntegrity);
            return;
        }
        if (invariantBlocked) {
            showSerializationNotice(lastInvariantResult);
            return;
        }
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
            var preview = logic.getCollapsedRowPreview(comp);
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

    // Resolve the element(s) carrying a given component index and field name.
    //
    // The match is made against the live attribute values rather than by
    // interpolating `fieldName` into a selector string. Field names are raw
    // composition keys: buildAccordionData passes `Object.keys(props)` straight
    // through as `field.name` in buildAccordionData's pass-through branch (a prop
    // the schema does not declare) and in its unknown-component branch, so a name
    // may legitimately contain characters that are STRUCTURAL in a selector —
    // quotes, brackets, backslashes, dots. Compared as strings those are just
    // characters; interpolated into a selector they are syntax, and the lookup
    // then resolves to the wrong element set or none at all.
    //
    //   $scope --find(baseSelector)--> candidates --filter(exact attrs)--> result
    //
    // `compIdx` is stringified because it arrives as a loop index (a number)
    // while an attribute always reads back as a string. `fieldName` needs no
    // such conversion: every caller sources it from Object.keys(), which yields
    // strings only.
    function findByCompField($scope, compIdx, fieldName, baseSelector) {
        var comp = String(compIdx);
        return $scope.find(baseSelector).filter(function () {
            // Raw attribute reads: `getAttribute` returns null when the attribute
            // is absent, which fails the strict comparison exactly as jQuery's
            // undefined did. This runs once per candidate inside the component x
            // field loops below, so it stays off jQuery's accessor pipeline.
            return this.getAttribute('data-comp') === comp
                && this.getAttribute('data-field') === fieldName;
        });
    }

    // The value-reading lookups below finish with .val(), so they resolve form
    // controls only: buildArrayFieldHtml also stamps data-comp/data-field onto the
    // array container and its add/remove buttons, and reading one of those for a
    // value would serialize an array prop as a string.
    //
    // Narrowing to controls does NOT make (data-comp, data-field) a unique key.
    // An array row's sub-field control carries the same data-comp and a data-field
    // equal to its SUB-KEY, which lives in a different namespace from the prop
    // names and can equal one (grid ships a top-level `title` and an `items[].title`).
    var FIELD_CONTROLS = 'input, textarea, select';

    // A top-level scalar prop resolves to the card's OWN control, never to a
    // control inside one of the card's array rows.
    //
    //   .pp-accordion-card [data-comp-idx=0]
    //     ├── <input data-comp=0 data-field="title">      ← the scalar. Wins.
    //     └── .pp-accordion-array [data-field="items"]
    //           └── .pp-accordion-array-item
    //                 └── <input data-comp=0 data-field="title">   ← a row sub-key
    //
    // Sub-keys and prop names are different namespaces that can collide, and the
    // shipped grid schema does collide (`title` and `items[].title`). Resolution
    // used to fall out of document order — .val() returns the first match — which
    // happens to pick the scalar only because grid declares `title` above `items`.
    // Ordering is a property of the schema file, not a rule the sync enforced, so
    // the same collision under a different declaration order resolved a top-level
    // prop to a ROW's value. Excluding array descendants makes the winner explicit
    // and order-independent; on every shipped schema it selects the same element
    // document order already did, so the resolved set does not move.
    //
    // A prop whose scalar control is absent now resolves to nothing and takes the
    // "no control resolved" branch, which leaves the stored value alone — the
    // deliberate choice over adopting an unrelated row's value.
    //
    // Native closest() rather than jQuery's: this runs once per candidate inside
    // the component x field loops, the same reason findByCompField reads raw
    // attributes. Only array rows nest controls (buildArrayFieldHtml renders row
    // sub-fields through buildFieldHtml, which never emits a nested array
    // container), so one ancestor test is the whole rule.
    function findScalarControl($scope, compIdx, fieldName) {
        return findByCompField($scope, compIdx, fieldName, FIELD_CONTROLS)
            .filter(function () { return !this.closest('.pp-accordion-array'); });
    }

    var syncAccordionToJson = debounce(function () {
        if (!cm) return;
        var data = logic.buildAccordionData(cm.getValue(), components);
        if (data.errors.length) return;

        var $container = $('#pp-accordion-view');

        data.components.forEach(function (comp, compIdx) {
            // Scope every lookup for this component to its own card. The card index
            // is a loop counter, never a composition key, so it is the one value in
            // this function that is safe to interpolate. The predicate already
            // required data-comp === compIdx and a card contains exactly the
            // controls carrying that value, so this narrows the candidate set
            // without changing which elements match: without it, each scalar lookup
            // rescans every control in the whole accordion, and array rows make that
            // grow with total row count rather than with the component's own fields.
            var $card = $container.find('.pp-accordion-card[data-comp-idx="' + compIdx + '"]');
            var $scope = $card.length ? $card : $container;

            comp.fields.forEach(function (field) {
                if (field.type === 'array') {
                    // Rebuild array value from DOM
                    var items = [];
                    var $arrayContainer = findByCompField($scope, compIdx, field.name, '.pp-accordion-array');
                    // An unresolved container and a genuinely empty array are the
                    // same `items` value further down, and only one of them should
                    // be written back. Distinguish them here, while the difference
                    // is still visible, and leave the field as it was.
                    if (!$arrayContainer.length) {
                        console.warn('pp-editor: no array container resolved for "' + field.name +
                            '" — sync skipped for this field.');
                        return;
                    }
                    var $arrayItems = $arrayContainer.find('.pp-accordion-array-item');
                    var subSchema = field.items || {};
                    var subKeys = Object.keys(subSchema);
                    $arrayItems.each(function () {
                        var $item = $(this);
                        var item = {};
                        subKeys.forEach(function (sk) {
                            var $input = findByCompField($item, compIdx, sk, FIELD_CONTROLS);
                            if ($input.length) item[sk] = $input.val();
                        });
                        items.push(item);
                    });
                    if (logic.wouldLoseArrayData(items, field.value)) {
                        // Deliberately describes the read rather than naming which
                        // of the guard's branches fired: the branches all mean the
                        // same thing to the reader, and a per-branch message would
                        // have to be kept in step with a rule that lives elsewhere.
                        console.warn('pp-editor: data-loss guard fired for "' + field.name +
                            '" — the DOM read (' + items.length + ' item(s)) does not represent the ' +
                            'stored value. Sync skipped for this field.');
                        return;
                    }
                    // Put back the sub-fields no control could carry, BEFORE the
                    // rows are settled (#805). Order is load-bearing in both
                    // directions: after the whole-field veto above, so that veto
                    // still judges the raw read; before reconcileArrayItems, whose
                    // per-row test asks whether every key the stored row had was on
                    // screen to be cleared — a read-only container sub-key is not in
                    // the read, so running this second would make that test restore
                    // the WHOLE row and silently discard a legitimate clearing.
                    //
                    // No console.warn here, unlike the two guards either side. Those
                    // report something exceptional; this is the designed steady state
                    // of every page holding a list or a per-item style, it would fire
                    // on every keystroke of a debounced sync, and the reason is
                    // already on screen next to the control. Warning here would drown
                    // the two warnings that do mean something. `restored` is still
                    // returned — the tests read it.
                    var typed = logic.reconcileSubFieldTypes(items, field.value, subSchema);

                    // The read can be right about some rows and wrong about
                    // others, so the rows are settled one at a time rather than
                    // the field being taken or refused whole. A row the author
                    // edited always wins; a row whose read carries nothing its
                    // stored item could have produced keeps what was stored.
                    var reconciled = logic.reconcileArrayItems(
                        typed.items, field.value, logic.displayOnlySubKeys(subSchema));
                    reconciled.restored.forEach(function (itemIdx) {
                        console.warn('pp-editor: data-loss guard fired for "' + field.name +
                            '" item ' + itemIdx + ' — the DOM read carried nothing the stored item ' +
                            'could have produced, so that item kept its stored value. The rest of ' +
                            'the field synced.');
                    });
                    field.value = reconciled.items;
                    field.userTouched = true;
                } else {
                    var $input = findScalarControl($scope, compIdx, field.name);
                    if ($input.length) {
                        field.value = $input.val();
                        field.userTouched = true;
                    } else {
                        // Matches the array branch's reporting: a field the sync
                        // could not resolve is left alone, and says so.
                        console.warn('pp-editor: no control resolved for "' + field.name +
                            '" — sync skipped for this field.');
                    }
                }
            });
        });

        var json = logic.serializeAccordionData(data.components);
        isSyncingFromAccordion = true;
        try { cm.setValue(json); }
        finally { isSyncingFromAccordion = false; }
        // The change handler skips validation/preview while syncing from the
        // accordion (isSyncingFromAccordion guard), so drive them here once.
        runValidation();
        runPreview();
    }, 300);

    // Settle any pending field edit before reading the buffer.
    //
    // A field edit reaches the buffer only through syncAccordionToJson, which is
    // debounced — so an edit made inside the 300ms window is still sitting in the
    // DOM, unwritten, when anything else reads cm.getValue():
    //
    //   type ──> [sync pending, 300ms] ──> read the buffer
    //                                        │
    //                          sees the PRE-edit value ──> acts on it
    //
    // Every reader that acts on the buffer from a user gesture calls this first.
    // The six structural handlers (insert, move up/down, delete, array-row
    // add/remove) re-render from what they read, which replaces the control and
    // leaves the trailing edge nothing to recover. Save and publish POST what
    // they read. The view toggle shows what it reads. Settling rather than
    // discarding is the point — the edit is the user's, so it has to land, not
    // be dropped.
    //
    // The debounced readers are exempt, deliberately. runValidation and
    // runPreview also read the buffer, but the sync re-drives both on its own
    // trailing edge, so they see the settled value a tick later without anyone
    // flushing for them; the boot-time invariant check reads it before an edit
    // can exist. Flushing from those would put a buffer write on a timer, which
    // is the thing this function exists to keep explicit.
    //
    // The throw is contained deliberately. Before the flush existed, the sync ran
    // from a timer, so a failure inside it could not reach the click that happened
    // to precede it — a delete still deleted. Calling it inline puts it on the
    // caller's own stack, where an exception would abort the operation and read to
    // the user as a button that does nothing. A failed sync should cost the
    // pending edit, not the operation. On that path save posts the buffer as it
    // stands, which is what it did before this function was called at all — so
    // containment is never worse than the old behavior, and the console carries
    // the reason.
    function flushPendingFieldEdits() {
        try {
            syncAccordionToJson.flush();
        } catch (e) {
            if (window.console) {
                console.error('pp-editor: could not settle pending edits before this operation.', e);
            }
        }
    }

    function initViewToggle() {
        $(document).on('click', '#pp-view-toggle', function () {
            var $btn = $(this);
            var $accordion = $('#pp-accordion-view');
            var $json = $('#pp-json-view');

            if (currentView === 'accordion') {
                // Leaving the accordion: the JSON view is about to render this
                // buffer, so a pending edit has to land in it first or the author
                // is shown their own composition minus what they just typed.
                //
                // Deliberately not flushed on the way back. In that direction the
                // buffer is what the author has been hand-editing and the
                // accordion is hidden, so settling a sync there would write a read
                // of the hidden form over their JSON. Today a flush there would be
                // a no-op — only an input event on the accordion schedules a sync,
                // and it cannot fire while the accordion is hidden — but the
                // direction that is SAFE to flush is the one where the form is the
                // authority, and that is only this one. Relying on the no-op would
                // make the safety a property of what happens to be scheduled
                // rather than of which pane the author is editing.
                flushPendingFieldEdits();
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

    /**
     * The buffer as a component list, or null when the insert must be refused (#750).
     *
     *   blank / whitespace-only   ->  []         a genuinely empty page; the insert proceeds
     *   a JSON list               ->  that list  the insert proceeds
     *   anything else             ->  null       refused, and the author is told what is there
     *
     * The five SIBLING structural handlers (move up, move down, delete, style, field edit)
     * all guard with a plain `if (!Array.isArray(parsed)) return;`, and this one used to
     * COERCE instead — `catch { parsed = []; }` then `if (!Array.isArray(parsed)) parsed = [];`
     * — so one use of the dropdown replaced whatever the pane held with a single-band list,
     * and the save that followed landed ok:true because a list is a list.
     *
     * It could not simply copy the siblings' guard, and that is why this function exists.
     * The siblings address an EXISTING band by index, so "not an array" and "nothing to do"
     * are the same answer for them. Insert is the one operation that is legitimate on a page
     * with nothing in it: `JSON.parse('')` throws, and the `[]` fallback is how the first
     * component is added to a new page. Merging those two cases is what made the coercion
     * look reasonable. Splitting them is ruling R-C in one function: "empty" means empty, and
     * everything else has to say what it actually is.
     *
     * Since #745 (and #750's boot gate above it) a corrupt buffer normally cannot reach here
     * at all — the accordion, and therefore the dropdown, is never rendered in JSON-only
     * mode. This stays because "unreachable" is a property of the current call graph, not of
     * this handler, and the cost of being wrong about that is the pane's only copy of the
     * author's work.
     */
    function bufferAsComponentList() {
        var text = cm.getValue();
        if (!text || !text.trim()) return [];

        // Both channels carry the whole refusal, deliberately. The error bar is the visible
        // one but it is not durable: the standing 300ms validation writes its own message
        // over it on the next keystroke, and that message ("Composition must be a JSON
        // array.") describes the same fault while saying nothing about what the dropdown
        // just did. The live region is where "nothing was added" survives.
        var parsed;
        try {
            parsed = JSON.parse(text);
        } catch (e) {
            var invalid = 'Nothing was added: the editor pane is not valid JSON, so there is no'
                + ' component list to add to. Fix the JSON below, then try again.';
            showErrors([invalid]);
            announce(invalid);
            return null;
        }

        if (!Array.isArray(parsed)) {
            var wrongShape = 'Nothing was added: the editor pane holds a ' + describeJsonValue(parsed)
                + ', not a composition list. A composition is a JSON array of components —'
                + ' fix the JSON below, then try again.';
            showErrors([wrongShape]);
            announce(wrongShape);
            return null;
        }

        return parsed;
    }

    /** A short, honest name for what a decoded non-list value is, for the message above. */
    function describeJsonValue(value) {
        if (value === null) return 'JSON null';
        if (Array.isArray(value)) return 'JSON array';
        return 'JSON ' + typeof value;
    }

    function initAccordionEvents() {
        var $container = $('#pp-accordion-view');

        // The six structural handlers below (insert, move up, move down, delete,
        // array-row add, array-row remove) rebuild the composition from
        // cm.getValue() and then re-render the accordion from it, so each one
        // settles pending edits first — see flushPendingFieldEdits above.

        // Expand/collapse
        $container.on('click', '.pp-accordion-toggle', function () {
            var $header = $(this);
            var expanded = $header.attr('aria-expanded') === 'true';
            // getElementById takes a raw id string, so nothing here is parsed as
            // a selector. The id is written by renderAccordion as
            // 'pp-card-body-<loop index>' and so is already selector-safe; going
            // through the DOM API keeps that from being a premise this lookup
            // silently depends on.
            var $body = $(document.getElementById($header.attr('aria-controls')));

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

        // Field change.
        //
        // The insert dropdown is excluded because it is chrome, not a field: it
        // carries no data-comp/data-field, holds no composition value, and its
        // own handler rebuilds the buffer from scratch. Letting a `change` on it
        // schedule a sync meant choosing a component to add always left one
        // pending, so the flush below could never be the no-op it is meant to be
        // and an insert rewrote the whole composition — marking every resolved
        // control touched, which writes schema defaults into bands the author
        // never opened. Selecting from it now schedules nothing.
        $container.on('input change', 'input, textarea, select', function () {
            if ($(this).hasClass('pp-accordion-insert')) return;
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
            flushPendingFieldEdits();
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

            var parsed = bufferAsComponentList();
            if (parsed === null) return;
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
            flushPendingFieldEdits();
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
            // Safe to interpolate: `idx` is parseInt'd and bounds-checked above, so
            // the value reaching the selector is always a JS number, which
            // stringifies to digits (or NaN) and never to selector syntax.
            var $header = $container.find('.pp-accordion-card[data-comp-idx="' + (idx - 1) + '"] .pp-accordion-toggle');
            if ($header.length) $header.focus();
        });

        // Move down
        $container.on('click', '.pp-move-down', function () {
            flushPendingFieldEdits();
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
            // Numeric for the same reason as the move-up handler above.
            var $header = $container.find('.pp-accordion-card[data-comp-idx="' + (idx + 1) + '"] .pp-accordion-toggle');
            if ($header.length) $header.focus();
        });

        // Delete
        $container.on('click', '.pp-delete-btn', function () {
            flushPendingFieldEdits();
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
            flushPendingFieldEdits();
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
            flushPendingFieldEdits();
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
            if (isSyncingFromAccordion) return;
            runValidation();
            runPreview();
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

    // Maps a wp_send_json_error payload to a user-facing message (#13). The handlers send a
    // structured object {message, code}; older/other errors send a bare string. A
    // composition_conflict means another writer changed the page since this editor loaded —
    // the local edit can't be saved without clobbering it, so prompt a reload.
    function saveErrorMessage(data, fallback) {
        var msg = (data && typeof data === 'object') ? (data.message || fallback) : (data || fallback);
        var code = (data && typeof data === 'object') ? data.code : '';
        if (code === 'composition_conflict') {
            return 'This page was changed elsewhere (the AI chat, another tab, or a CLI edit) '
                + 'since you opened it. Reload to get the latest version before saving — '
                + 'saving now would overwrite that change.';
        }
        if (msg === 'Invalid nonce.') {
            return 'Session expired. Please reload the page.';
        }
        return msg;
    }

    function doSaveDraft() {
        if (!cm) return;
        // Settle first: what gets validated and POSTed has to be what the user
        // last typed, not the buffer as it stood before the pending sync. Nothing
        // below this line changes — expected_version still carries the baseline
        // this editor is holding (#13), so the flush moves only the composition
        // bytes, never the optimistic-locking comparison.
        flushPendingFieldEdits();
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
            action:           'pp_save_composition',
            post_id:          postId,
            composition:      value,
            nonce:            nonce,
            expected_version: currentVersion,
        })
        .done(function (res) {
            if (res.success) {
                // Advance the optimistic-locking baseline (#13) to the version just written,
                // so a follow-up save in the same session compares against it, not the stale
                // load-time version.
                if (res.data && typeof res.data.version !== 'undefined') {
                    currentVersion = parseInt(res.data.version, 10) || currentVersion;
                }
                // Refresh CM from server-returned normalized composition
                if (res.data && res.data.composition) {
                    isSyncingFromAccordion = true;
                    try { cm.setValue(JSON.stringify(res.data.composition, null, 2)); }
                    finally { isSyncingFromAccordion = false; }
                }

                // `true`: the draft handler refuses an empty composition outright ('Invalid
                // JSON.'), so a success here always means a composition was written.
                setSaveStatus('is-saved', resettleBlockedStateAfterSave(true) || 'Draft saved');
                setTimeout(function () { setSaveStatus('', ''); }, 3000);
            } else {
                setSaveStatus('is-error', saveErrorMessage(res.data, 'Save failed.'));
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
        // Same rule as doSaveDraft: settle the pending edit before the read, and
        // leave expected_version handling exactly as it was.
        flushPendingFieldEdits();
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
            action:           'pp_publish_page',
            post_id:          postId,
            composition:      value,
            nonce:            nonce,
            expected_version: currentVersion,
        })
        .done(function (res) {
            if (res.success) {
                postStatus  = res.data.status;
                postLink    = res.data.post_link;
                previewLink = res.data.preview_link;

                // Advance the optimistic-locking baseline (#13) to the just-written version.
                if (typeof res.data.version !== 'undefined') {
                    currentVersion = parseInt(res.data.version, 10) || currentVersion;
                }

                // Refresh CM from server-returned normalized composition
                if (res.data.composition) {
                    isSyncingFromAccordion = true;
                    try { cm.setValue(JSON.stringify(res.data.composition, null, 2)); }
                    finally { isSyncingFromAccordion = false; }
                }

                $btn.text('Update').data('status', 'publish');

                $('#pp-view-link')
                    .text('View \u2197')
                    .attr('href', postLink);

                $('#pp-status-badge').remove();

                // `value !== ''`: the publish handler skips the composition write on an
                // empty pane and publishes anyway, so success alone does not mean the
                // stored composition was replaced.
                setSaveStatus(
                    'is-saved',
                    resettleBlockedStateAfterSave(value !== '') || (wasPublished ? 'Updated' : 'Published')
                );
                setTimeout(function () { setSaveStatus('', ''); }, 3000);
            } else {
                setSaveStatus('is-error', saveErrorMessage(res.data, wasPublished ? 'Update failed.' : 'Publish failed.'));
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
                var $serErr = $('.pp-serialization-error');
                if (newLeft < 300) {
                    $accordion.addClass('pp-accordion--narrow');
                } else {
                    $accordion.removeClass('pp-accordion--narrow');
                }
                if (newLeft < 400) {
                    $serErr.addClass('pp-serialization-error--narrow');
                } else {
                    $serErr.removeClass('pp-serialization-error--narrow');
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

    // ── Serialization invariant notice ──────────────────────────────────────

    /**
     * JSON-only mode: the accordion is gone and the raw pane is the only editor.
     *
     * Shared by the two states that need it (#750). The serialization invariant reaches it
     * when opening the accordion WOULD change the composition; the corruption notice reaches
     * it because there is no composition to open. Same mechanism, and it stayed one function
     * so a future third caller cannot half-apply it — a hidden accordion with a visible
     * toggle is a route straight back into the form this mode exists to keep closed.
     */
    function forceJsonOnlyMode() {
        $('#pp-accordion-view').hide();
        $('#pp-json-view').show();
        currentView = 'json';
        $('#pp-view-toggle').hide();
        if (cm) cm.refresh();
    }

    /** Widths below this get the notice's stacked layout instead of its diff table. */
    var NARROW_PANE_PX = 400;

    /**
     * Put a pane-level notice on screen, replacing whatever notice is already there (#750).
     *
     * Shared by both notices for the reason forceJsonOnlyMode() is shared: the mount is a
     * three-part rule — replace-or-insert-after-the-header, then re-query, then apply the
     * narrow-pane class — and a second copy of it drifts one part at a time. There is only
     * ever ONE notice mounted: the two states are mutually exclusive (corruption is checked
     * first and wins), and replaceWith is what keeps that true when one supersedes the other.
     *
     * @param  {string} html  The notice markup, already escaped by its builder.
     * @return {?Object} The mounted notice, or null when there is no editor pane.
     */
    function mountNotice(html) {
        var $pane = $('.pp-pane--editor');
        if (!$pane.length) return null;

        var $existing = $pane.find('.pp-serialization-error');
        if ($existing.length) {
            $existing.replaceWith(html);
        } else {
            $pane.find('.pp-pane-header').after(html);
        }

        var $notice = $pane.find('.pp-serialization-error');
        if ($pane[0].offsetWidth < NARROW_PANE_PX) {
            $notice.addClass('pp-serialization-error--narrow');
        } else {
            $notice.removeClass('pp-serialization-error--narrow');
        }
        return $notice;
    }

    /**
     * The notice for a page whose STORED composition could not be read (#750).
     *
     * Not a variant of the serialization notice below, and the difference is the point.
     * That one reports a predicted round-trip mismatch: it lists diff paths, and its "Copy as
     * GitHub Issue" button is right, because a composition the accordion would mangle is a
     * defect in this editor. This one reports a stored row that is not a composition at all.
     * There is no diff to show (the invariant's own answer for this input is a single row of
     * nulls), and the honest next step is a repair, not a bug report — so it renders the
     * classification and the route, and no button.
     *
     * Every interpolation goes through esc(). Today's inputs are a fixed classification word
     * and sentences built from an int post id, so nothing here is author-controlled; the
     * escaping is uniformity, so that no injection point in this file is the one a reader has
     * to reason about individually.
     */
    function showCorruptionNotice(integrity) {
        if (!integrity) return;

        forceJsonOnlyMode();

        mountNotice(
            '<div class="pp-serialization-error" data-pp-corrupt="' + esc(integrity.error) + '">' +
            '<div class="pp-serialization-error__header">\u26A0 The stored composition on this page is corrupted (' + esc(integrity.error) + ')</div>' +
            '<div class="pp-serialization-error__subtext">' + esc(integrity.message) + '</div>' +
            '<div class="pp-serialization-error__subtext">' + esc(integrity.repair) + '</div>' +
            '</div>'
        );
    }

    function showSerializationNotice(invariant) {
        console.error('Serialization invariant failed:', invariant.diffs);

        forceJsonOnlyMode();

        // Build diff table rows + card markup
        var diffs = invariant.diffs || [];
        var tableRows = '';
        var cards = '';
        diffs.forEach(function (d) {
            var compMatch = d.path.match(/^\[(\d+)\]/);
            var compLabel = compMatch ? 'Component ' + compMatch[1] : '\u2014';
            var before = d.before === undefined ? '<em>(absent)</em>' : '<code>' + esc(JSON.stringify(d.before)) + '</code>';
            var after = d.after === undefined ? '<em>(absent)</em>' : '<code>' + esc(JSON.stringify(d.after)) + '</code>';
            // changeType is a fixed internal vocabulary (added/removed/changed/
            // type_mismatch — see deepDiff in pp-editor-logic.js), so this escape is
            // uniformity rather than necessity: no interpolation in this file is left
            // as the one a reader has to reason about individually.
            var badge = '<span class="pp-diff-badge pp-diff-badge--' + esc(d.changeType) + '">' + esc(d.changeType) + '</span>';

            tableRows += '<tr><td>' + esc(compLabel) + '</td><td><code>' + esc(d.path) + '</code></td><td>' + before + '</td><td>' + after + '</td><td>' + badge + '</td></tr>';
            cards += '<div class="pp-diff-card"><div class="pp-diff-card__component">' + esc(compLabel) + '</div><div class="pp-diff-card__path">' + esc(d.path) + '</div><div class="pp-diff-card__values">' + before + ' <span class="pp-diff-card__arrow">\u2192</span> ' + after + ' ' + badge + '</div></div>';
        });

        var html = '<div class="pp-serialization-error">' +
            '<div class="pp-serialization-error__header">\u26A0 Accordion unavailable for this composition</div>' +
            '<div class="pp-serialization-error__subtext">Opening this composition in the accordion editor would change its structure. Edit JSON directly below, then save to re-check.</div>' +
            '<details open><summary>Structural drift details (' + diffs.length + ' diff' + (diffs.length !== 1 ? 's' : '') + ')</summary>' +
            '<table><thead><tr><th>Component</th><th>Path</th><th>Before</th><th>After</th><th>Change</th></tr></thead><tbody>' + tableRows + '</tbody></table>' +
            cards +
            '</details>' +
            '<button type="button" class="pp-toolbar-btn pp-copy-issue-btn">Copy as GitHub Issue</button>' +
            '</div>';

        var $notice = mountNotice(html);
        if (!$notice) return;

        // Copy as GitHub Issue button
        $notice.find('.pp-copy-issue-btn').off('click').on('click', function () {
            var $btn = $(this);
            var pageTitle = $('#pp-page-title').val() || 'Untitled';
            var md = logic.formatDiffsForIssue(diffs, pageTitle, postId);

            function onSuccess() {
                $btn.after('<span class="pp-copy-success">\u2713 Copied!</span>');
                setTimeout(function () { $btn.siblings('.pp-copy-success').remove(); }, 2000);
            }
            function onFail() {
                $btn.after('<span class="pp-copy-success" style="color:#fca5a5">Copy failed</span>');
                setTimeout(function () { $btn.siblings('.pp-copy-success').remove(); }, 2000);
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(md).then(onSuccess, function () {
                    // Fallback for clipboard API denial
                    try {
                        var ta = document.createElement('textarea');
                        ta.value = md;
                        ta.style.position = 'fixed';
                        ta.style.left = '-9999px';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                        onSuccess();
                    } catch (e) { onFail(); }
                });
            } else {
                // Fallback for non-HTTPS (no clipboard API)
                try {
                    var ta = document.createElement('textarea');
                    ta.value = md;
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    onSuccess();
                } catch (e) { onFail(); }
            }
        });
    }

    /**
     * After a save that landed: is the editor still blocked, and by what? (#750)
     *
     * One question, two callers (Save draft, Publish/Update), and since #750 two blocked
     * states that both end in JSON-only mode. When a composition was actually WRITTEN,
     * corruption is over: the write went through pp_execute_action('update_composition'),
     * which validates the whole replacement and refuses a non-list container outright
     * (#724), so whatever is stored now IS a composition. Whether the ACCORDION can safely
     * open it is a different question, and the only one still worth asking — so both states
     * converge on the same invariant re-check, and a page that was corrupt and saves into a
     * drift-unsafe composition swaps notices instead of keeping the stale one.
     *
     *   was blocked, now safe    -> notice cleared, accordion back, status names what settled
     *   was blocked, still unsafe-> the DRIFT notice (never the corruption one), status normal
     *   was not blocked          -> nothing touched
     *
     * `compositionWritten` IS LOAD-BEARING, and "the request succeeded" is not a substitute
     * for it. `wp_ajax_pp_publish_page` (lib/admin.php) SKIPS the composition write when the
     * posted composition is empty — `if ($raw !== '')` — and then publishes and returns
     * success. An author who clears the pane on a corrupt page and hits Publish therefore
     * gets ok back while the corrupt row is untouched, and clearing on that would drop them
     * into an empty accordion sitting on the exact bytes the notice was about: the
     * pristine-blank lie, restored by the one control that says the work is done. So the
     * corruption block is only lifted by evidence that a composition replaced it.
     *
     * @param {boolean} compositionWritten  Did this request actually carry a composition?
     * @returns {?string} A status label when the block cleared, else null (caller's own).
     */
    function resettleBlockedStateAfterSave(compositionWritten) {
        if (!invariantBlocked && !corruptionBlocked) return null;
        if (corruptionBlocked && !compositionWritten) return null;

        var wasCorrupt = corruptionBlocked;
        corruptionBlocked = false;
        storedIntegrity = null;

        var inv = logic.checkSerializationInvariant(cm.getValue(), components);
        if (inv.safe) {
            clearSerializationNotice();
            return wasCorrupt ? 'Composition repaired' : 'Drift resolved';
        }

        invariantBlocked = true;
        lastInvariantResult = inv;
        showSerializationNotice(inv);
        return null;
    }

    function clearSerializationNotice() {
        $('.pp-serialization-error').remove();
        invariantBlocked = false;
        lastInvariantResult = null;
        // The corruption state is NOT cleared here: resettleBlockedStateAfterSave() owns that
        // transition, because only it knows whether a composition was actually written (#750).
        $('#pp-json-view').hide();
        $('#pp-accordion-view').show();
        currentView = 'accordion';
        $('#pp-view-toggle').text('JSON').show();
        renderAccordion();
        if (cm) cm.refresh();
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

        // Corruption check, then invariant check, then the accordion (#750).
        //
        //   stored row corrupt?  --yes--> corruption notice   (classification + repair route)
        //          |no
        //   round-trip unsafe?   --yes--> serialization notice (diff paths + issue button)
        //          |no
        //   render the accordion
        //
        // Order is load-bearing. The invariant ALSO refuses a corrupt buffer — a non-list
        // fails buildAccordionData, an undecodable string fails JSON.parse — so without this
        // branch first, a corrupt page lands in JSON-only mode wearing the wrong story: told
        // its structure would drift, not that its stored composition is unreadable, and
        // pointed at a bug report instead of a repair. Both gates close; only one of them
        // tells the truth about THIS state.
        //
        // The corruption branch sits OUTSIDE the `if (cm)` guard on purpose. An author who
        // turned off the syntax-highlighting editor in their WordPress profile
        // (`cmDisabled`) gets a null `cm`, and everything below needs one — but the
        // corruption notice does not: it is rendered from a payload PHP already computed,
        // and forceJsonOnlyMode() null-guards its one cm call. Gating it on `cm` would have
        // left exactly that configuration looking at a blank editor for a corrupt page,
        // which is the presentation this whole issue removes.
        if (storedIntegrity) {
            corruptionBlocked = true;
            showCorruptionNotice(storedIntegrity);
        } else if (cm) {
            var invariant = logic.checkSerializationInvariant(cm.getValue(), components);
            if (!invariant.safe) {
                invariantBlocked = true;
                lastInvariantResult = invariant;
                showSerializationNotice(invariant);
            } else {
                renderAccordion();
            }
        }
    });

})(jQuery);
