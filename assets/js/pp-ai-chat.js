/**
 * pp-ai-chat.js — PromptingPress AI Chat UI
 *
 * Uses fetch() + ReadableStream for POST-based SSE streaming.
 * Nonce sent in POST body, never in URL.
 * Falls back to standard AJAX if SSE streaming fails.
 * Conversation persists in localStorage across reloads.
 */

// ── Testable helpers (used by IIFE, exported for tests) ──────────────────────

// Destructive-action warnings are server-driven: the action + apply registries
// (lib/actions.php / lib/apply.php) declare 'impact_warning' strings, surfaced
// via wp_localize_script as window.ppAiChat.impact_warnings. No hardcoded list
// here, so a newly-registered destructive capability can't silently lose its
// warning. Returns null (no warning) for any name without a server entry.
function ppChatGetImpactWarning(name) {
    var warnings = (typeof window !== 'undefined' && window.ppAiChat && window.ppAiChat.impact_warnings) || {};
    return warnings[name] || null;
}

/**
 * Finds the page whose title is the longest substring match in the given
 * (already-lowercased) text. Pure and side-effect-free (issue 136) — the
 * caller decides what to do with the result; this function never sets the
 * active page itself. Skips untitled pages. Returns null for no match, no
 * text, or an empty pages list.
 */
function ppChatDetectPageId(lowerText, pages) {
    if (!pages || !pages.length || !lowerText) return null;

    var bestMatch = null;
    var bestLen = 0;

    for (var i = 0; i < pages.length; i++) {
        var page = pages[i];
        var title = (page.title || '').toLowerCase();
        if (!title) continue; // skip untitled pages
        if (lowerText.indexOf(title) !== -1 && title.length > bestLen) {
            bestMatch = page.id;
            bestLen = title.length;
        }
    }

    return bestMatch;
}

/**
 * Looks up a page object by id in the pages list. Returns null when pageId
 * is falsy or no page in the list has a matching id.
 *
 * Ids are compared numerically: config.pages ids arrive as ints from PHP,
 * but a pageId sourced from a <select> value or an older localStorage
 * state is a string — a strict === here silently never matched those,
 * which dropped the persisted page selection on reload and hid the
 * proposal card's "Target page:" label.
 */
function ppChatFindPageById(pageId, pages) {
    if (!pageId || !pages) return null;
    for (var i = 0; i < pages.length; i++) {
        if (Number(pages[i].id) === Number(pageId)) return pages[i];
    }
    return null;
}

/**
 * Decides whether a detected page should be surfaced as a "switch?"
 * suggestion (issue 136): only when detection found a page AND it differs
 * from the currently active selection. Detection must never be treated as
 * authoritative on its own — the explicit selection always wins, this only
 * flags a disagreement for the user to act on (or ignore).
 */
function ppChatShouldSuggestPageSwitch(activePageId, detectedPageId) {
    // Numeric comparison for the same reason as ppChatFindPageById: a string
    // id on either side must not make the already-selected page look like a
    // different one.
    return !!detectedPageId && Number(detectedPageId) !== Number(activePageId);
}

function ppChatFormatDiffValue(val) {
    if (val === null || val === undefined) return '(none)';
    if (typeof val === 'object') {
        var s = JSON.stringify(val);
        return s.length > 80 ? s.substring(0, 77) + '...' : s;
    }
    return String(val);
}

/**
 * Builds a human-readable summary of a composition replacement.
 * Compares from/to arrays by component type to identify adds, removes,
 * reorders, and content changes.
 *
 * Returns an object: { lines: string[], fromCount: number, toCount: number }
 */
function ppChatBuildCompositionSummary(from, to) {
    var fromArr = Array.isArray(from) ? from : [];
    var toArr = Array.isArray(to) ? to : [];
    var lines = [];

    lines.push('Full composition replacement: ' + fromArr.length + ' \u2192 ' + toArr.length + ' components');

    // Build type lists
    var fromTypes = fromArr.map(function (c) { return (c && c.component) || '(unknown)'; });
    var toTypes = toArr.map(function (c) { return (c && c.component) || '(unknown)'; });

    // Count occurrences
    var fromCounts = {};
    var toCounts = {};
    fromTypes.forEach(function (t) { fromCounts[t] = (fromCounts[t] || 0) + 1; });
    toTypes.forEach(function (t) { toCounts[t] = (toCounts[t] || 0) + 1; });

    // Identify added types (in to but not enough in from)
    var added = [];
    var removed = [];
    var allTypes = {};
    Object.keys(fromCounts).forEach(function (t) { allTypes[t] = true; });
    Object.keys(toCounts).forEach(function (t) { allTypes[t] = true; });

    Object.keys(allTypes).forEach(function (t) {
        var diff = (toCounts[t] || 0) - (fromCounts[t] || 0);
        if (diff > 0) {
            for (var i = 0; i < diff; i++) added.push(t);
        } else if (diff < 0) {
            for (var i = 0; i < -diff; i++) removed.push(t);
        }
    });

    if (added.length > 0) lines.push('+ Added: ' + added.join(', '));
    if (removed.length > 0) lines.push('\u2212 Removed: ' + removed.join(', '));

    // Detect reorder: same multiset of types but different sequence
    var fromSorted = fromTypes.slice().sort().join(',');
    var toSorted = toTypes.slice().sort().join(',');
    if (fromSorted === toSorted && fromTypes.join(',') !== toTypes.join(',')) {
        lines.push('\u21C5 Components reordered');
    }

    // Detect major content changes in shared components (by index, matching types)
    var contentChanges = 0;
    var maxCheck = Math.min(fromArr.length, toArr.length);
    for (var i = 0; i < maxCheck; i++) {
        if (fromTypes[i] === toTypes[i]) {
            var fromProps = (fromArr[i] && fromArr[i].props) || {};
            var toProps = (toArr[i] && toArr[i].props) || {};
            // Check key text fields for changes
            var textKeys = ['title', 'heading', 'text', 'content', 'description', 'subtitle', 'label'];
            for (var k = 0; k < textKeys.length; k++) {
                var key = textKeys[k];
                if (fromProps[key] !== toProps[key] && (fromProps[key] || toProps[key])) {
                    contentChanges++;
                    break;
                }
            }
        }
    }
    if (contentChanges > 0) {
        lines.push('\u270E Content changes in ' + contentChanges + ' component' + (contentChanges > 1 ? 's' : ''));
    }

    // Component list
    lines.push('');
    lines.push('Components: ' + toTypes.join(' \u2192 '));

    return { lines: lines, fromCount: fromArr.length, toCount: toArr.length };
}

function ppChatShouldShowMultiStepWarning(steps) {
    return steps && steps.length >= 3;
}

function ppChatIsRevertEligible(steps) {
    return steps && steps.length === 1 && steps[0].name === 'update_design_token';
}

/**
 * Renders a user-friendly error message in a preview diff area.
 * Handles structured errors (from _pp_build_friendly_error) and plain strings.
 */
function ppChatRenderPreviewError(diffArea, data) {
    // Structured error from style_component repair path.
    if (data && typeof data === 'object' && data.user_message) {
        var msgEl = document.createElement('div');
        msgEl.className = 'pp-ai-preview-error-message';
        msgEl.textContent = data.user_message;
        diffArea.appendChild(msgEl);

        // Cross-component hint.
        var hints = data.cross_component_hints;
        if (hints && typeof hints === 'object') {
            var hintKeys = Object.keys(hints);
            if (hintKeys.length > 0) {
                var hintEl = document.createElement('div');
                hintEl.className = 'pp-ai-preview-error-hint';
                var first = hints[hintKeys[0]];
                hintEl.textContent = 'This setting exists on the ' + first.component + ' component.';
                diffArea.appendChild(hintEl);
            }
        }

        // Technical details in a native <details> disclosure.
        if ((data.alternatives && data.alternatives.length > 0) || data.raw_error) {
            var details = document.createElement('details');
            details.className = 'pp-ai-preview-error-detail';
            var summary = document.createElement('summary');
            summary.textContent = 'Show technical details';
            details.appendChild(summary);

            var content = document.createElement('div');
            var lines = [];
            if (data.raw_error) {
                lines.push(data.raw_error);
            }
            if (hints && typeof hints === 'object') {
                var hKeys = Object.keys(hints);
                for (var h = 0; h < hKeys.length; h++) {
                    var hint = hints[hKeys[h]];
                    lines.push('Available on ' + hint.component + ': ' + hint.slot);
                }
            }
            if (data.alternatives && data.alternatives.length > 0) {
                lines.push('Available slots: ' + data.alternatives.join(', '));
            }
            content.textContent = lines.join('\n');
            details.appendChild(content);
            diffArea.appendChild(details);
        }
        return;
    }

    // Plain string error (non-style_component actions).
    diffArea.textContent = typeof data === 'string'
        ? data
        : (data && data.message) || 'Preview failed';
}

/**
 * Determines the CSS class for a failed step based on error type and cross-component hints.
 */
function ppChatGetErrorStepClass(data) {
    if (!data || typeof data !== 'object') return 'pp-ai-step-failed';
    var code = data.error_code || '';
    if (code === 'no_style_slots') return 'pp-ai-step-impossible';
    if (code === 'invalid_style_slot') {
        var hints = data.cross_component_hints;
        if (hints && typeof hints === 'object' && Object.keys(hints).length > 0) {
            return 'pp-ai-step-fixable';
        }
        return 'pp-ai-step-impossible';
    }
    if (code === 'invalid_style_value' || code === 'invalid_recipe') return 'pp-ai-step-fixable';
    return 'pp-ai-step-failed';
}

/**
 * Derives a contextual status bar message from the first failed step's error data.
 */
function ppChatGetStatusMessage(data) {
    if (!data || typeof data !== 'object') return 'Some changes couldn\'t be previewed. See details above.';
    var code = data.error_code || '';
    var hints = data.cross_component_hints;
    var hasHints = hints && typeof hints === 'object' && Object.keys(hints).length > 0;
    if (hasHints) return 'That setting lives on a different component. See details above.';
    if (code === 'no_style_slots' || code === 'invalid_style_slot') return 'This change isn\'t possible with the current component settings.';
    if (code === 'invalid_style_value') return 'The value format needs adjustment. See suggestions above.';
    return 'Some changes couldn\'t be previewed. See details above.';
}

/**
 * Appends validation items (errors or warnings) to a container.
 * Shows first 5 inline; collapses the rest in a <details> disclosure (D6).
 */
function ppChatAppendValidationItems(container, items, className) {
    if (!items || !items.length) return;

    var MAX_INLINE = 5;
    var shown = items.slice(0, MAX_INLINE);
    var overflow = items.slice(MAX_INLINE);

    shown.forEach(function (item) {
        var div = document.createElement('div');
        div.className = className;
        div.textContent = item.message;
        container.appendChild(div);
    });

    if (overflow.length > 0) {
        var details = document.createElement('details');
        details.className = 'pp-ai-preview-error-detail';
        var summary = document.createElement('summary');
        summary.textContent = 'Show ' + overflow.length + ' more ' + (className.indexOf('warning') !== -1 ? 'warning' : 'error') + (overflow.length === 1 ? '' : 's');
        details.appendChild(summary);

        overflow.forEach(function (item) {
            var div = document.createElement('div');
            div.className = className;
            div.textContent = item.message;
            details.appendChild(div);
        });

        container.appendChild(details);
    }
}

(function () {
    'use strict';

    var config = window.ppAiChat || {};
    if (!config.configured) return;

    var messagesEl = document.getElementById('pp-ai-messages');
    var inputEl    = document.getElementById('pp-ai-input');
    var sendBtn    = document.getElementById('pp-ai-send');
    var stopBtn    = document.getElementById('pp-ai-stop');
    var newChatBtn = document.getElementById('pp-ai-new-chat');

    if (!messagesEl || !inputEl || !sendBtn) return;

    // ── Provider/Model Selectors ──────────────────────────────────────

    var providerSelect = document.getElementById('pp-ai-provider-select');
    var modelSelect    = document.getElementById('pp-ai-model-select');
    var pageSelectEl   = document.getElementById('pp-ai-page-select');

    var switchRetryCount = 0;

    function switchProvider(providerId, modelId) {
        var body = new FormData();
        body.append('action', 'pp_ai_switch_provider');
        body.append('_ajax_nonce', config.executeNonce);
        body.append('provider', providerId || '');
        body.append('model', modelId || '');

        if (modelSelect) {
            modelSelect.classList.add('pp-ai-chat-selector--loading');
            modelSelect.innerHTML = '<option>\u2026</option>';
        }

        fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                switchRetryCount = 0;
                if (!json.success || !modelSelect) return;
                var models = json.data.models || [];
                modelSelect.innerHTML = '';
                models.forEach(function (m) {
                    var opt = document.createElement('option');
                    opt.value = m.id;
                    opt.textContent = m.name;
                    if (m.id === json.data.model) opt.selected = true;
                    modelSelect.appendChild(opt);
                });
                modelSelect.classList.remove('pp-ai-chat-selector--loading');
                modelSelect.classList.remove('pp-ai-chat-selector--error');
            })
            .catch(function () {
                if (!modelSelect) return;
                modelSelect.innerHTML = '<option>Failed</option>';
                modelSelect.classList.remove('pp-ai-chat-selector--loading');
                modelSelect.classList.add('pp-ai-chat-selector--error');
                if (switchRetryCount < 1) {
                    switchRetryCount++;
                    setTimeout(function () {
                        modelSelect.classList.remove('pp-ai-chat-selector--error');
                        switchProvider(config.selectedProvider, config.selectedModel);
                    }, 3000);
                }
            });
    }

    if (providerSelect) {
        providerSelect.addEventListener('change', function () {
            config.selectedProvider = this.value;
            switchProvider(this.value, '');
        });
    }

    if (modelSelect) {
        modelSelect.addEventListener('change', function () {
            config.selectedModel = this.value;
            switchProvider('', this.value);
        });
    }

    // Populate full model list on page load from config
    if (modelSelect && config.providers && config.providers.length > 0) {
        var currentProvider = config.providers.find(function (p) {
            return p.id === config.selectedProvider;
        });
        if (currentProvider && currentProvider.models && currentProvider.models.length > 1) {
            modelSelect.innerHTML = '';
            currentProvider.models.forEach(function (m) {
                var opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = m.name;
                if (m.id === config.selectedModel) opt.selected = true;
                modelSelect.appendChild(opt);
            });
        }
    }

    // ── Page Selector (issue 136) ───────────────────────────────────────
    //
    // The active page is explicit and user-controlled via this selector,
    // never inferred silently. detectPageId (below) only ever surfaces a
    // suggestion for the user to accept or ignore.

    if (pageSelectEl) {
        pageSelectEl.addEventListener('change', function () {
            // <select> values are strings; activePageId is canonically a
            // number (matching config.pages ids) everywhere else.
            activePageId = this.value ? Number(this.value) : null;
            saveState();
        });
    }

    /**
     * Reflects activePageId in the <select>. If activePageId points at a
     * page no longer in config.pages (e.g. trashed since it was selected),
     * resets to no selection rather than silently keeping a stale target.
     */
    function syncPageSelectValue() {
        if (!pageSelectEl) return;
        if (activePageId && !ppChatFindPageById(activePageId, config.pages || [])) {
            activePageId = null;
        }
        pageSelectEl.value = activePageId || '';
    }

    // ── Persistence ───────────────────────────────────────────────────

    // Persistence is scoped to site + WP user so two admins sharing an OS/
    // browser profile can't read each other's chat history (#157).
    //
    //   ppAiChat.currentUserId ──▶ valid decimal string (/^[1-9]\d*$/, safe int)?
    //     ├─ yes ─▶ STORAGE_KEY = pp_ai_chat_<siteUrl>_<userId>  (persist)
    //     └─ no  ─▶ STORAGE_KEY = null  ──▶ FAIL CLOSED (in-memory only)
    //
    // The edit_posts-gated chat page always runs for a logged-in user, so a
    // missing/invalid id is a broken localized-config contract, not a normal
    // state. We fail closed rather than fall back to a shared bucket: an
    // unscoped key would recreate the exact cross-user leak this fix closes.
    // wp_localize_script casts scalars to strings, so the id arrives as e.g.
    // "5" — validate the string, don't assume a JS number.
    var SITE_SEGMENT = config.siteUrl || 'default';
    var LEGACY_STORAGE_KEY = 'pp_ai_chat_' + SITE_SEGMENT;

    var STORAGE_KEY = (function () {
        var raw = config.currentUserId;
        var str = (raw === undefined || raw === null) ? '' : String(raw);
        if (/^[1-9]\d*$/.test(str) && Number.isSafeInteger(Number(str))) {
            return LEGACY_STORAGE_KEY + '_' + str;
        }
        // eslint-disable-next-line no-console
        console.warn('[pp-ai-chat] persistence disabled: missing/invalid currentUserId in ppAiChat config — chat history will not be saved for this session.');
        return null;
    })();

    // Drop the legacy unscoped key on load regardless of currentUserId validity
    // — that bucket may hold another user's conversation on a shared profile,
    // and the new scoped key (when valid) starts empty by design (no migration).
    // Wrapped in try/catch so a throwing localStorage (Safari private mode,
    // quota, security policy) can't break chat initialization.
    try {
        localStorage.removeItem(LEGACY_STORAGE_KEY);
    } catch (e) {
        // Ignore — cleanup is best-effort
    }

    function saveState() {
        if (!STORAGE_KEY) { return; }
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                conversation: conversation,
                activePageId: activePageId
            }));
        } catch (e) {
            // Storage full or unavailable — continue without persistence
        }
    }

    function loadState() {
        if (!STORAGE_KEY) { return null; }
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                var state = JSON.parse(raw);
                return state;
            }
        } catch (e) {
            // Corrupted data — start fresh
        }
        return null;
    }

    function clearState() {
        if (!STORAGE_KEY) { return; }
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // Ignore
        }
    }

    // ── State ─────────────────────────────────────────────────────────

    var conversation = [];
    var isStreaming = false;
    var activePageId = null;

    // issue 139: set to the current request's stop function while a stream
    // is in flight, so the Stop button (wired once at init) can reach
    // whichever streamChat() closure is currently active. null when idle.
    var activeStopHandler = null;

    // issue 139: bumped whenever a request is abandoned (New Chat mid-stream)
    // rather than intentionally stopped-and-finalized. A request's async
    // callbacks (fetch .then/.catch, ajaxFallback's response handlers) check
    // their captured id against this counter before touching shared state —
    // without it, an abandoned request's callback could still fire AFTER
    // resetChat() has already cleared the conversation, re-populating it
    // with the old request's partial text.
    var currentRequestId = 0;

    /**
     * Toggles Send/Stop/input for the duration of a request, in one place
     * so every entry/exit point (send, finish, error, fallback, reset) stays
     * consistent (issue 139 — a Stop button is only useful if it's never
     * left visible after the request it belongs to has already ended).
     */
    function setStreamingUiState(active) {
        isStreaming = active;
        sendBtn.disabled = active;
        inputEl.disabled = active;
        if (stopBtn) {
            stopBtn.style.display = active ? '' : 'none';
        }
    }

    if (stopBtn) {
        stopBtn.addEventListener('click', function () {
            if (activeStopHandler) activeStopHandler();
        });
    }

    // ── Markdown Rendering ────────────────────────────────────────────

    function renderMarkdown(text) {
        if (!text) return '';
        // Escape HTML first to prevent XSS
        var html = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // Code blocks (``` ... ```)
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, function (_, lang, code) {
            return '<pre><code>' + code.replace(/\n$/, '') + '</code></pre>';
        });

        // Inline code
        html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');

        // Bold + italic
        html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
        // Bold
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        // Italic
        html = html.replace(/(?<!\*)\*([^\s*][^*]*[^\s*])\*(?!\*)/g, '<em>$1</em>');
        html = html.replace(/(?<!\*)\*([^\s*])\*(?!\*)/g, '<em>$1</em>');

        // Split into lines for block-level processing
        var lines = html.split('\n');
        var result = [];
        var inList = false;
        var listType = '';
        var listItemCount = 0;

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];

            // Skip lines inside pre blocks (already handled)
            if (line.indexOf('<pre>') !== -1) {
                if (inList) {
                    // Look ahead past the code block for another list item
                    var preEnd = i;
                    var tempLine = line;
                    while (tempLine.indexOf('</pre>') === -1 && preEnd + 1 < lines.length) {
                        preEnd++;
                        tempLine = lines[preEnd];
                    }
                    // Check lines after the code block for list continuation
                    var nextAfterPre = preEnd + 1;
                    while (nextAfterPre < lines.length && lines[nextAfterPre].trim() === '') nextAfterPre++;
                    var listContinues = false;
                    if (nextAfterPre < lines.length) {
                        if (listType === 'ol' && lines[nextAfterPre].match(/^\d+\.\s/)) listContinues = true;
                        if (listType === 'ul' && lines[nextAfterPre].match(/^[-*]\s/)) listContinues = true;
                    }
                    result.push('</' + listType + '>');
                    inList = false;
                    if (!listContinues) listItemCount = 0;
                }
                // Collect until </pre>
                var preBlock = line;
                while (line.indexOf('</pre>') === -1 && i + 1 < lines.length) {
                    i++;
                    line = lines[i];
                    preBlock += '\n' + line;
                }
                result.push(preBlock);
                continue;
            }

            // Headings
            var headingMatch = line.match(/^(#{1,6})\s+(.+)$/);
            if (headingMatch) {
                if (inList) { result.push('</' + listType + '>'); inList = false; listItemCount = 0; }
                var level = headingMatch[1].length;
                result.push('<h' + (level + 2) + '>' + headingMatch[2] + '</h' + (level + 2) + '>');
                continue;
            }

            // Ordered list
            var olMatch = line.match(/^\d+\.\s+(.+)$/);
            if (olMatch) {
                if (!inList || listType !== 'ol') {
                    if (inList) result.push('</' + listType + '>');
                    if (listItemCount > 0) {
                        result.push('<ol start="' + (listItemCount + 1) + '">');
                    } else {
                        result.push('<ol>');
                    }
                    inList = true;
                    listType = 'ol';
                }
                // Collect continuation lines (indented or blank lines followed by indented)
                var liContent = olMatch[1];
                while (i + 1 < lines.length) {
                    var next = lines[i + 1];
                    if (next.match(/^\s{2,}/) && !next.match(/^\d+\.\s/) && !next.match(/^[-*]\s/)) {
                        liContent += '<br>' + next.trim();
                        i++;
                    } else {
                        break;
                    }
                }
                listItemCount++;
                result.push('<li>' + liContent + '</li>');
                continue;
            }

            // Unordered list
            var ulMatch = line.match(/^[-*]\s+(.+)$/);
            if (ulMatch) {
                if (!inList || listType !== 'ul') {
                    if (inList) result.push('</' + listType + '>');
                    result.push('<ul>');
                    inList = true;
                    listType = 'ul';
                }
                var uliContent = ulMatch[1];
                while (i + 1 < lines.length) {
                    var nextUl = lines[i + 1];
                    if (nextUl.match(/^\s{2,}/) && !nextUl.match(/^\d+\.\s/) && !nextUl.match(/^[-*]\s/)) {
                        uliContent += '<br>' + nextUl.trim();
                        i++;
                    } else {
                        break;
                    }
                }
                result.push('<li>' + uliContent + '</li>');
                continue;
            }

            // Empty line — check if list continues after it
            if (line.trim() === '') {
                if (inList) {
                    // Look ahead past blank lines for another list item of the same type
                    var ahead = i + 1;
                    while (ahead < lines.length && lines[ahead].trim() === '') ahead++;
                    var continues = false;
                    if (ahead < lines.length) {
                        if (listType === 'ol' && lines[ahead].match(/^\d+\.\s/)) continues = true;
                        if (listType === 'ul' && lines[ahead].match(/^[-*]\s/)) continues = true;
                    }
                    if (!continues) {
                        result.push('</' + listType + '>');
                        inList = false;
                        listItemCount = 0;
                    }
                }
                result.push('');
                continue;
            }

            // Close list if we hit a non-list, non-empty line
            if (inList) {
                result.push('</' + listType + '>');
                inList = false;
                listItemCount = 0;
            }

            // Regular text — wrap in paragraph
            result.push('<p>' + line + '</p>');
        }

        if (inList) result.push('</' + listType + '>');

        // Clean up: merge adjacent <p> tags separated by nothing
        return result.join('\n')
            .replace(/<\/p>\n<p>/g, '<br>')
            .replace(/\n{2,}/g, '\n');
    }

    function setMarkdownContent(el, text) {
        el.innerHTML = renderMarkdown(text);
    }

    // ── Message Rendering ──────────────────────────────────────────────

    function addMessage(role, content) {
        var div = document.createElement('div');
        div.className = 'pp-ai-msg pp-ai-msg-' + role;

        var label = document.createElement('div');
        label.className = 'pp-ai-msg-role';
        label.textContent = role === 'user' ? 'You' : 'Assistant';

        var body = document.createElement('div');
        body.className = 'pp-ai-msg-body';
        if (role === 'assistant') {
            setMarkdownContent(body, content);
        } else {
            body.textContent = content;
        }

        div.appendChild(label);
        div.appendChild(body);
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;

        return body;
    }

    function createStreamingMessage() {
        var div = document.createElement('div');
        div.className = 'pp-ai-msg pp-ai-msg-assistant';

        var label = document.createElement('div');
        label.className = 'pp-ai-msg-role';
        label.textContent = 'Assistant';

        var body = document.createElement('div');
        body.className = 'pp-ai-msg-body pp-ai-msg-streaming';

        div.appendChild(label);
        div.appendChild(body);
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;

        return body;
    }

    // ── Proposal Card Rendering ────────────────────────────────────────

    function renderDiffLine(change) {
        var div = document.createElement('div');
        var label = document.createTextNode(change.path + ': ');
        div.appendChild(label);

        var fromSpan = document.createElement('span');
        fromSpan.className = 'pp-ai-step-diff-from';
        fromSpan.textContent = ppChatFormatDiffValue(change.from);
        div.appendChild(fromSpan);

        div.appendChild(document.createTextNode(' \u2192 '));

        var toSpan = document.createElement('span');
        toSpan.className = 'pp-ai-step-diff-to';
        toSpan.textContent = ppChatFormatDiffValue(change.to);
        div.appendChild(toSpan);

        return div;
    }

    function renderCompositionDiff(diffArea, change) {
        var summary = ppChatBuildCompositionSummary(change.from, change.to);

        // Summary section
        var summaryDiv = document.createElement('div');
        summaryDiv.className = 'pp-ai-composition-summary';
        summary.lines.forEach(function (line) {
            if (line === '') {
                summaryDiv.appendChild(document.createElement('br'));
            } else {
                var p = document.createElement('div');
                p.textContent = line;
                summaryDiv.appendChild(p);
            }
        });
        diffArea.appendChild(summaryDiv);

        // Expandable raw JSON
        var details = document.createElement('details');
        details.className = 'pp-ai-composition-raw';

        var summaryEl = document.createElement('summary');
        var jsonStr = JSON.stringify(change.to, null, 2);
        summaryEl.textContent = 'View raw composition JSON (' + summary.toCount + ' components, ' +
            Math.round(jsonStr.length / 1024) + ' KB)';
        details.appendChild(summaryEl);

        var pre = document.createElement('pre');
        pre.className = 'pp-ai-composition-json';
        var code = document.createElement('code');
        code.textContent = jsonStr;
        pre.appendChild(code);
        details.appendChild(pre);

        diffArea.appendChild(details);
    }

    function fetchPreview(step) {
        var data = new FormData();
        data.append('action', 'pp_ai_preview');
        data.append('nonce', config.executeNonce);
        data.append('type', step.type);
        data.append('name', step.name);

        var params = step.params || {};
        Object.keys(params).forEach(function (key) {
            var val = params[key];
            if (typeof val === 'object') {
                data.append('params[' + key + ']', JSON.stringify(val));
            } else {
                data.append('params[' + key + ']', val);
            }
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(function (r) { return r.json(); });
    }

    function renderProposal(proposal, pageId) {
        var card = document.createElement('div');
        card.className = 'pp-ai-proposal-card';

        var title = document.createElement('div');
        title.className = 'pp-ai-proposal-title';
        title.textContent = 'Proposed Changes';
        card.appendChild(title);

        // Names the page this request targeted (issue 136) — the AI's
        // proposed post_id params drive the actual writes, so the user must
        // be able to see which page that is before approving, not just infer
        // it from the diff.
        var targetPage = ppChatFindPageById(pageId, config.pages || []);
        if (targetPage) {
            var pageLabel = document.createElement('div');
            pageLabel.className = 'pp-ai-proposal-target-page';
            pageLabel.textContent = 'Target page: ' + (targetPage.title || '(untitled)');
            card.appendChild(pageLabel);
        }

        var steps = proposal.steps || [];

        // Card-level multi-step warning (3+ steps)
        if (ppChatShouldShowMultiStepWarning(steps)) {
            var cardWarning = document.createElement('div');
            cardWarning.className = 'pp-ai-card-warning';
            cardWarning.textContent = '\u26A0 Multi-step edit \u2014 review each step';
            card.appendChild(cardWarning);
        }

        // Show rejected steps as unsupported
        var rejected = proposal.rejected || [];
        rejected.forEach(function (step) {
            var rejDiv = document.createElement('div');
            rejDiv.className = 'pp-ai-proposal-step pp-ai-step-rejected';

            var rejLabel = document.createElement('div');
            rejLabel.className = 'pp-ai-proposal-step-label';
            rejLabel.textContent = (step.description || step.name) + ' (unsupported)';
            rejDiv.appendChild(rejLabel);

            var rejMeta = document.createElement('div');
            rejMeta.className = 'pp-ai-proposal-step-meta';
            rejMeta.textContent = step.type + ' "' + step.name + '" is not a registered capability.';
            rejDiv.appendChild(rejMeta);

            card.appendChild(rejDiv);
        });

        var stepElements = [];
        var diffAreas = [];

        steps.forEach(function (step, i) {
            var stepDiv = document.createElement('div');
            stepDiv.className = 'pp-ai-proposal-step pp-ai-step-executing';

            var stepLabel = document.createElement('div');
            stepLabel.className = 'pp-ai-proposal-step-label';
            stepLabel.textContent = (i + 1) + '. ' + (step.description || step.name);
            stepDiv.appendChild(stepLabel);

            var stepMeta = document.createElement('div');
            stepMeta.className = 'pp-ai-proposal-step-meta';
            stepMeta.textContent = step.type + ': ' + step.name;
            stepDiv.appendChild(stepMeta);

            // Per-step impact warning (between meta and diff)
            var warning = ppChatGetImpactWarning(step.name);
            if (warning) {
                var warnDiv = document.createElement('div');
                warnDiv.className = 'pp-ai-step-warning';
                warnDiv.textContent = '\u26A0 ' + warning;
                stepDiv.appendChild(warnDiv);
            }

            // Diff area placeholder
            var diffArea = document.createElement('div');
            diffArea.className = 'pp-ai-step-diff';
            diffArea.textContent = 'Loading preview\u2026';
            stepDiv.appendChild(diffArea);

            card.appendChild(stepDiv);
            stepElements.push(stepDiv);
            diffAreas.push(diffArea);
        });

        messagesEl.appendChild(card);
        messagesEl.scrollTop = messagesEl.scrollHeight;

        // If no valid steps, nothing to preview or apply
        if (steps.length === 0) return;

        // Fetch previews in parallel
        var previewPromises = steps.map(function (step) {
            return fetchPreview(step).then(function (resp) {
                return { success: resp.success, data: resp.data };
            }).catch(function (err) {
                return { success: false, data: err.message || 'Preview request failed' };
            });
        });

        Promise.all(previewPromises).then(function (results) {
            var anyFailed = false;
            var firstFailedData = null;

            results.forEach(function (result, i) {
                stepElements[i].classList.remove('pp-ai-step-executing');
                diffAreas[i].textContent = '';

                if (result.success && result.data && result.data.changes) {
                    // Store preview data on the step for Apply to use
                    steps[i]._previewChanges = result.data.changes;

                    result.data.changes.forEach(function (change) {
                        if (steps[i].name === 'update_composition' && change.path === 'composition' &&
                            Array.isArray(change.from) && Array.isArray(change.to)) {
                            renderCompositionDiff(diffAreas[i], change);
                        } else {
                            diffAreas[i].appendChild(renderDiffLine(change));
                        }
                    });
                    if (result.data.changes.length === 0) {
                        diffAreas[i].textContent = '(no changes)';
                    }
                } else {
                    anyFailed = true;
                    var errorClass = ppChatGetErrorStepClass(result.data);
                    stepElements[i].classList.add(errorClass);
                    ppChatRenderPreviewError(diffAreas[i], result.data);
                    if (!firstFailedData) firstFailedData = result.data;
                }
            });

            if (anyFailed) {
                addStatusMessage(ppChatGetStatusMessage(firstFailedData), true);
                return;
            }

            // All previews succeeded — add Apply/Cancel buttons
            var actions = document.createElement('div');
            actions.className = 'pp-ai-proposal-actions';

            var applyBtn = document.createElement('button');
            applyBtn.className = 'button button-primary pp-ai-proposal-apply';
            applyBtn.textContent = steps.length > 1 ? 'Apply All' : 'Apply';
            applyBtn.addEventListener('click', function () {
                executeProposal(steps, stepElements, applyBtn, cancelBtn, card);
            });

            var cancelBtn = document.createElement('button');
            cancelBtn.className = 'button pp-ai-proposal-cancel';
            cancelBtn.textContent = 'Cancel';
            cancelBtn.addEventListener('click', function () {
                card.classList.add('pp-ai-proposal-cancelled');
                applyBtn.disabled = true;
                cancelBtn.disabled = true;
                addStatusMessage('Proposal cancelled.');
                inputEl.focus();
            });

            actions.appendChild(applyBtn);
            actions.appendChild(cancelBtn);
            card.appendChild(actions);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        });
    }

    function addStatusMessage(text, isError) {
        var div = document.createElement('div');
        div.className = 'pp-ai-status' + (isError ? ' pp-ai-status-error' : '');
        if (isError) div.setAttribute('role', 'alert');
        div.textContent = text;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    /**
     * Blocks sending: no page is selected. Directs the user to the selector
     * instead of proceeding with page_id: null (issue 136). If detection
     * found a candidate, names it as a hint — it is never auto-selected.
     */
    function showPageSelectionPrompt(detectedPageId, pages) {
        var detectedPage = ppChatFindPageById(detectedPageId, pages);
        var text = detectedPage
            ? 'Select a page before sending — did you mean "' + detectedPage.title + '"? Choose it from the page dropdown above.'
            : 'Select a page before sending, using the page dropdown above.';
        addStatusMessage(text, true);
        if (pageSelectEl) pageSelectEl.focus();
    }

    /**
     * Non-blocking: the message was sent using the current selection, but
     * detection disagrees with it. Surfaces a one-click way to switch the
     * selection for the NEXT message — never retargets silently, and never
     * blocks or alters the request that was just sent (issue 136).
     */
    function maybeShowPageSwitchSuggestion(detectedPageId, pages) {
        if (!ppChatShouldSuggestPageSwitch(activePageId, detectedPageId)) return;

        var detectedPage = ppChatFindPageById(detectedPageId, pages);
        if (!detectedPage) return;

        var div = document.createElement('div');
        div.className = 'pp-ai-status pp-ai-page-switch-suggestion';
        div.appendChild(document.createTextNode('This message mentioned "' + detectedPage.title + '". '));

        var switchBtn = document.createElement('button');
        switchBtn.type = 'button';
        switchBtn.className = 'button pp-ai-page-switch-btn';
        switchBtn.textContent = 'Switch to it for next message?';
        switchBtn.addEventListener('click', function () {
            // Number() upholds the numeric activePageId invariant even if
            // config.pages ever carries string ids.
            activePageId = Number(detectedPage.id);
            syncPageSelectValue();
            saveState();
            div.remove();
        });
        div.appendChild(switchBtn);

        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // ── Proposal Execution ─────────────────────────────────────────────

    function executeProposal(steps, stepElements, applyBtn, cancelBtn, card) {
        applyBtn.disabled = true;
        cancelBtn.disabled = true;

        stepElements.forEach(function (el) { el.classList.add('pp-ai-step-executing'); });

        // issue 137: one atomic batch request instead of N independent
        // pp_ai_execute calls — the server snapshots every step's target up
        // front and rolls everything back if any step fails, so a failure
        // never leaves the page half-mutated the way sequential per-step
        // calls could.
        var data = new FormData();
        data.append('action', 'pp_ai_execute_batch');
        data.append('nonce', config.executeNonce);
        data.append('steps', JSON.stringify(steps.map(function (s) {
            return { type: s.type, name: s.name, params: s.params || {} };
        })));

        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (!resp.success) {
                stepElements.forEach(function (el) {
                    el.classList.remove('pp-ai-step-executing');
                    el.classList.add('pp-ai-step-failed');
                });
                addStatusMessage('Error: ' + (resp.data || 'Unknown error'), true);
                return;
            }

            var batch = resp.data; // { ok, steps: [...], failed_at, rolled_back }
            var applied = [];

            batch.steps.forEach(function (stepResult, i) {
                stepElements[i].classList.remove('pp-ai-step-executing');
                if (stepResult.ok) {
                    stepElements[i].classList.add('pp-ai-step-done');
                    var step = steps[i];
                    step._validation = stepResult.validation || null;
                    step._staleWarnings = stepResult.stale_warnings || null;
                    applied.push(step);
                } else {
                    stepElements[i].classList.add('pp-ai-step-failed');
                }
            });

            // Steps after the failure point never ran at all — mark them
            // distinctly from a step that actually failed.
            if (!batch.ok && batch.failed_at !== null) {
                for (var j = batch.failed_at + 1; j < stepElements.length; j++) {
                    stepElements[j].classList.remove('pp-ai-step-executing');
                    stepElements[j].classList.add('pp-ai-step-skipped');
                }
            }

            if (batch.ok) {
                finalizeProposalSuccess(card, applied, steps);
                return;
            }

            var failedResult = batch.steps[batch.failed_at];
            var message = 'Error on step ' + (batch.failed_at + 1) + ': ' + (failedResult.error || 'Unknown error');
            if (batch.rolled_back) {
                message += ' — all changes in this proposal have been reverted.';
            }
            addStatusMessage(message, true);
        })
        .catch(function (err) {
            stepElements.forEach(function (el) {
                el.classList.remove('pp-ai-step-executing');
                el.classList.add('pp-ai-step-failed');
            });
            addStatusMessage('Error: ' + err.message, true);
        });
    }

    function buildPostApplyCard(card, applied, steps) {
        // Clear existing card content and build post-apply summary
        card.innerHTML = '';

        // Last-step-wins (D2): use the final step's validation for the card state.
        var lastValidation = null;
        for (var vi = applied.length - 1; vi >= 0; vi--) {
            if (applied[vi]._validation) {
                lastValidation = applied[vi]._validation;
                break;
            }
        }

        // Validation section (replaces the old unconditional success message).
        var validationSection = document.createElement('div');
        validationSection.setAttribute('role', 'status');
        validationSection.setAttribute('aria-live', 'polite');

        if (!lastValidation || lastValidation.ok) {
            // Passed (possibly with warnings).
            var hasWarnings = lastValidation && lastValidation.warnings && lastValidation.warnings.length > 0;

            var statusDiv = document.createElement('div');
            statusDiv.className = hasWarnings ? 'pp-ai-step-warning' : 'pp-ai-step-done';
            statusDiv.textContent = hasWarnings
                ? '\u2713 Changes applied with warnings.'
                : '\u2713 All changes applied successfully.';
            validationSection.appendChild(statusDiv);

            if (hasWarnings) {
                ppChatAppendValidationItems(validationSection, lastValidation.warnings, 'pp-ai-step-warning');
            }
        } else {
            // Failed.
            var errorDiv = document.createElement('div');
            errorDiv.className = 'pp-ai-step-failed';
            errorDiv.textContent = '\u2717 Changes applied but rendered page validation failed.';
            validationSection.appendChild(errorDiv);

            ppChatAppendValidationItems(validationSection, lastValidation.errors, 'pp-ai-step-failed');

            if (lastValidation.warnings && lastValidation.warnings.length > 0) {
                ppChatAppendValidationItems(validationSection, lastValidation.warnings, 'pp-ai-step-warning');
            }
        }

        card.appendChild(validationSection);

        // Stale token warnings — advisory only, never block the apply.
        // Collect all stale warnings, then filter out any that were explicitly
        // updated by a later step in the same proposal (the AI fixed them).
        var allStaleWarnings = [];
        var explicitlyUpdated = {};
        applied.forEach(function (step) {
            if (step.params && step.params.token) {
                explicitlyUpdated[step.params.token] = true;
            }
            if (step._staleWarnings) {
                step._staleWarnings.forEach(function (w) { allStaleWarnings.push(w); });
            }
        });
        var staleWarnings = allStaleWarnings.filter(function (w) {
            return w && w.token && !explicitlyUpdated[w.token];
        });
        if (staleWarnings.length === 0) staleWarnings = null;
        if (staleWarnings) {
            var staleItems = staleWarnings.map(function (w) {
                return { message: w.token + ' (' + w.current + ') may not match the new palette \u2014 review if unintended.' };
            });
            ppChatAppendValidationItems(validationSection, staleItems, 'pp-ai-step-warning');
        }

        applied.forEach(function (step) {
            var lineDiv = document.createElement('div');
            lineDiv.className = 'pp-ai-status';
            lineDiv.textContent = '\u2713 Applied: ' + (step.description || step.name);
            card.appendChild(lineDiv);
        });

        var linksDiv = document.createElement('div');
        linksDiv.className = 'pp-ai-post-apply-links';
        var hasLinks = false;

        // View Page link — find a post_id from any step
        var postId = null;
        for (var i = 0; i < applied.length; i++) {
            if (applied[i].params && applied[i].params.post_id) {
                postId = applied[i].params.post_id;
                break;
            }
        }
        if (postId && config.siteUrl) {
            var viewLink = document.createElement('a');
            viewLink.href = config.siteUrl + '?p=' + postId;
            viewLink.target = '_blank';
            viewLink.textContent = 'View Page \u2192';
            linksDiv.appendChild(viewLink);
            hasLinks = true;
        }

        // Reset to default link — single-step update_design_token only
        if (ppChatIsRevertEligible(steps)) {
            var originalStep = steps[0];
            var resetLink = document.createElement('a');
            resetLink.href = '#';
            resetLink.textContent = 'Reset to default';
            resetLink.style.marginLeft = hasLinks ? '16px' : '0';
            resetLink.addEventListener('click', function (e) {
                e.preventDefault();
                resetLink.textContent = 'Resetting\u2026';
                resetLink.style.pointerEvents = 'none';

                var resetData = new FormData();
                resetData.append('action', 'pp_ai_execute');
                resetData.append('nonce', config.executeNonce);
                resetData.append('type', 'apply');
                resetData.append('name', 'reset_design_token');
                resetData.append('params[token]', originalStep.params.token);

                fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: resetData
                })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        resetLink.textContent = 'Reset applied \u2713';
                    } else {
                        resetLink.textContent = 'Reset failed';
                        resetLink.className = 'pp-ai-link-error';
                    }
                })
                .catch(function () {
                    resetLink.textContent = 'Reset failed';
                    resetLink.className = 'pp-ai-link-error';
                });
            });
            linksDiv.appendChild(resetLink);
            hasLinks = true;
        }

        if (hasLinks) {
            card.appendChild(linksDiv);
        }
    }

    function finalizeProposalSuccess(card, applied, steps) {
        // Build post-apply summary inside the card
        buildPostApplyCard(card, applied, steps);

        // Inject confirmation into conversation so the AI knows mutations were applied.
        // Last-step-wins (D2): condition the assistant message on the final validation.
        var summary = applied.map(function (s) { return s.description || s.name; }).join('; ');
        conversation.push({ role: 'user', content: '[Applied changes: ' + summary + ']' });

        var lastVal = null;
        for (var lvi = applied.length - 1; lvi >= 0; lvi--) {
            if (applied[lvi]._validation) { lastVal = applied[lvi]._validation; break; }
        }

        // Collect stale token warnings for conversation context.
        // Filter out tokens the AI explicitly updated in this proposal.
        var convStale = [];
        var convExplicit = {};
        applied.forEach(function (s) {
            if (s.params && s.params.token) convExplicit[s.params.token] = true;
            if (s._staleWarnings) {
                s._staleWarnings.forEach(function (w) { convStale.push(w); });
            }
        });
        convStale = convStale.filter(function (w) { return w && w.token && !convExplicit[w.token]; });
        var staleSuffix = '';
        if (convStale.length > 0) {
            staleSuffix = ' Note: some existing token overrides may not match the new palette: ' +
                convStale.map(function (w) { return w.token; }).join(', ') +
                '. These were kept as-is — update them if the visual result looks inconsistent.';
        }

        // internal: true marks these as apply-confirmation context for the
        // model's next turn, not a real conversational reply — restoreConversation()
        // skips them structurally on reload instead of matching on English text
        // (pp_ai_format_messages() already strips unknown keys before the request
        // reaches the provider, so this flag never leaves the browser/our backend).
        if (!lastVal || (lastVal.ok && (!lastVal.warnings || lastVal.warnings.length === 0))) {
            conversation.push({ role: 'assistant', content: 'Changes applied successfully.' + staleSuffix, internal: true });
        } else if (lastVal.ok && lastVal.warnings && lastVal.warnings.length > 0) {
            var warnSummary = lastVal.warnings.map(function (w) { return w.message; }).join('; ');
            conversation.push({ role: 'assistant', content: 'Changes applied with warnings: ' + warnSummary, internal: true });
        } else {
            var errSummary = lastVal.errors.map(function (e) { return e.message; }).join('; ');
            conversation.push({ role: 'assistant', content: 'Changes applied but rendered page validation failed: ' + errSummary + '. The page may still have broken images or missing content.', internal: true });
        }
        saveState();
        inputEl.focus();
    }

    // ── SSE Streaming via fetch + ReadableStream ───────────────────────

    function sendMessage(text) {
        if (isStreaming || !text.trim()) return;

        var trimmed = text.trim();
        var pages = config.pages || [];
        var detectedPageId = ppChatDetectPageId(trimmed.toLowerCase(), pages);

        // The active page is explicit and user-controlled (issue 136) —
        // detection is a suggestion only, never an authority. Without an
        // explicit selection, block sending rather than proposing changes
        // against page_id: null (or silently reusing a stale prior target).
        if (!activePageId) {
            showPageSelectionPrompt(detectedPageId, pages);
            return;
        }

        maybeShowPageSwitchSuggestion(detectedPageId, pages);

        setStreamingUiState(true);

        conversation.push({ role: 'user', content: trimmed });
        addMessage('user', trimmed);
        inputEl.value = '';
        saveState();

        var myRequestId = ++currentRequestId;
        streamChat(conversation, myRequestId);
    }

    // First-token watchdog (issue 139): a proxy/CDN that buffers the whole
    // response, or middleware stripping text/event-stream, returns HTTP 200
    // with no usable stream — that does NOT reject the fetch, so this is the
    // only way to detect it and fall back to the non-streaming endpoint.
    var FIRST_TOKEN_WATCHDOG_MS = 15000;

    function streamChat(messages, myRequestId) {
        // Captured once per request: renderProposal() (in this closure's
        // async callbacks) must label the page actually targeted by THIS
        // request, not whatever activePageId happens to be by the time the
        // response arrives — the selector can change while streaming.
        var requestPageId = activePageId;

        var body = JSON.stringify({
            messages: messages,
            nonce: config.streamNonce,
            page_id: activePageId
        });

        var msgBody = createStreamingMessage();
        var fullText = '';
        var proposalReceived = false;

        var controller = new AbortController();
        var userStopped = false;
        var firstTokenReceived = false;

        activeStopHandler = function () {
            userStopped = true;
            controller.abort();
        };

        var watchdogTimer = setTimeout(function () {
            if (firstTokenReceived || myRequestId !== currentRequestId) return;
            activeStopHandler = null;
            controller.abort();
            ajaxFallback(messages, msgBody, requestPageId, myRequestId);
        }, FIRST_TOKEN_WATCHDOG_MS);

        fetch(config.streamUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: body,
            signal: controller.signal
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            function pump() {
                return reader.read().then(function (result) {
                    if (myRequestId !== currentRequestId) return; // abandoned (New Chat mid-stream)

                    if (result.done) {
                        clearTimeout(watchdogTimer);
                        activeStopHandler = null;
                        finishStream(msgBody, fullText, proposalReceived);
                        return;
                    }

                    buffer += decoder.decode(result.value, { stream: true });

                    // Process complete SSE lines
                    var lines = buffer.split('\n');
                    buffer = lines.pop(); // keep incomplete line in buffer

                    lines.forEach(function (line) {
                        line = line.trim();
                        if (!line || line.charAt(0) === ':') return; // keepalive or comment
                        if (line === 'data: [DONE]') return;
                        if (line.indexOf('data: ') !== 0) return;

                        var jsonStr = line.substring(6);
                        try {
                            var data = JSON.parse(jsonStr);
                            firstTokenReceived = true;
                            clearTimeout(watchdogTimer);

                            if (data.error) {
                                activeStopHandler = null;
                                handleStreamError(msgBody, data.error);
                                return;
                            }

                            if (data.content) {
                                fullText += data.content;
                                msgBody.textContent = fullText;
                                messagesEl.scrollTop = messagesEl.scrollHeight;
                            }

                            if (data.done && data.proposal) {
                                proposalReceived = true;
                                renderProposal(data.proposal, requestPageId);
                            }

                            if (data.done && data.truncated && !data.proposal) {
                                addStatusMessage(
                                    'The response was cut short before the proposal could be generated. Try sending your request again, or simplify it.',
                                    false
                                );
                            }
                        } catch (e) {
                            // Skip malformed JSON chunks
                        }
                    });

                    return pump();
                });
            }

            return pump();
        })
        .catch(function (err) {
            clearTimeout(watchdogTimer);
            activeStopHandler = null;

            if (myRequestId !== currentRequestId) return; // abandoned (New Chat mid-stream)

            if (userStopped) {
                // issue 139: user clicked Stop — finalize the partial
                // message in place; this was an intentional cancellation,
                // not a failure, so never fall back to the AJAX endpoint.
                finishStream(msgBody, fullText, proposalReceived);
                return;
            }

            if (err.name === 'AbortError') {
                // The watchdog already aborted and triggered ajaxFallback
                // itself, synchronously, before this rejection could fire —
                // nothing left to do here.
                return;
            }

            // Genuine SSE failure — try AJAX fallback.
            ajaxFallback(messages, msgBody, requestPageId, myRequestId);
        });
    }

    function stripProposalJson(text) {
        // Remove markdown-fenced JSON proposal blocks (```json ... ```)
        var stripped = text.replace(/```(?:json)?\s*\n[\s\S]*?"proposal"\s*:\s*true[\s\S]*?```/g, '');
        // Remove bare JSON proposal blocks
        stripped = stripped.replace(/\{"proposal"\s*:\s*true[\s\S]*?"steps"\s*:\s*\[[\s\S]*?\]\s*\}/g, '');
        return stripped.trim();
    }

    function finishStream(msgBody, fullText, proposalReceived) {
        msgBody.classList.remove('pp-ai-msg-streaming');
        if (fullText) {
            // Store full text in conversation for context, but display without raw JSON
            conversation.push({ role: 'assistant', content: fullText });
            var displayText = stripProposalJson(fullText);
            setMarkdownContent(msgBody, displayText);

            // Detect truncated responses: prose suggests a proposal was coming
            // but no proposal was received from the server
            if (!proposalReceived && looksLikeIncompleteProposal(fullText)) {
                addStatusMessage(
                    'The response may have been cut short before the proposal could be generated. Try sending your request again, or simplify it.',
                    false
                );
            }
        }
        saveState();
        setStreamingUiState(false);
        inputEl.focus();
    }

    function looksLikeIncompleteProposal(text) {
        // Check if the text contains language that typically precedes a proposal
        // but ends without one. These patterns indicate the AI started to propose
        // something but the response was truncated before the JSON was emitted.
        var proposalIndicators = [
            /here(?:'|')s (?:the |my |what I )?propos/i,
            /here(?:'|')s (?:the |my )?plan/i,
            /proposed (?:changes|update|step)/i,
            /I(?:'|')ll propose/i,
            /proposal.*:/i
        ];
        var hasIndicator = proposalIndicators.some(function (re) {
            return re.test(text);
        });
        if (!hasIndicator) {
            return false;
        }

        // The text has proposal language but no actual proposal JSON was parsed.
        // Check that the text doesn't end with a complete conversational response
        // (if it ends mid-sentence or with a colon, it's more likely truncated).
        var trimmed = text.trim();
        var lastChar = trimmed.charAt(trimmed.length - 1);
        // Ends with colon, incomplete sentence, or mid-word — likely truncated
        if (lastChar === ':' || lastChar === ',') {
            return true;
        }
        // If text has proposal indicators and is relatively short (the JSON
        // block that should follow was never emitted), flag it
        var afterLastIndicator = text.split(/propos|plan/i).pop();
        if (afterLastIndicator && afterLastIndicator.trim().length < 50) {
            return true;
        }
        return false;
    }

    function handleStreamError(msgBody, errorText) {
        msgBody.classList.remove('pp-ai-msg-streaming');
        msgBody.classList.add('pp-ai-msg-error');

        msgBody.textContent = errorText;

        // COUPLED: must match wording in pp_ai_parse_error_response() and "not configured" messages.
        // Skip for quota errors — those direct the user to switch providers above, not Connectors.
        if ((errorText.indexOf('API key') !== -1 ||
            errorText.indexOf('not configured') !== -1 ||
            errorText.indexOf('Settings > Connectors') !== -1) &&
            errorText.indexOf('no remaining credits') === -1) {
            var sep = document.createTextNode(' ');
            var link = document.createElement('a');
            link.href = config.connectorsUrl;
            link.textContent = 'Settings > Connectors';
            msgBody.appendChild(sep);
            msgBody.appendChild(link);
        }

        setStreamingUiState(false);
    }

    // ── AJAX Fallback ──────────────────────────────────────────────────

    function ajaxFallback(messages, msgBody, requestPageId, myRequestId) {
        // issue 139: a subtle note when the non-streaming fallback engages —
        // for supportability, so a slow/no-typing-effect response doesn't
        // look like a silent bug.
        addStatusMessage('Streaming unavailable — using compatibility mode.', false);

        var data = new FormData();
        data.append('action', 'pp_ai_chat');
        data.append('nonce', config.streamNonce);

        messages.forEach(function (msg, i) {
            data.append('messages[' + i + '][role]', msg.role);
            data.append('messages[' + i + '][content]', msg.content);
        });

        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (myRequestId !== currentRequestId) return; // abandoned (New Chat mid-stream)

            if (resp.success) {
                setMarkdownContent(msgBody, resp.data.content);
                msgBody.classList.remove('pp-ai-msg-streaming');
                conversation.push({ role: 'assistant', content: resp.data.content });

                if (resp.data.proposal) {
                    renderProposal(resp.data.proposal, requestPageId);
                } else if (looksLikeIncompleteProposal(resp.data.content)) {
                    addStatusMessage(
                        'The response may have been cut short before the proposal could be generated. Try sending your request again, or simplify it.',
                        false
                    );
                }
            } else {
                handleStreamError(msgBody, resp.data || 'Chat request failed.');
            }

            saveState();
            setStreamingUiState(false);
            inputEl.focus();
        })
        .catch(function () {
            if (myRequestId !== currentRequestId) return; // abandoned (New Chat mid-stream)
            handleStreamError(msgBody, 'Connection failed. Please try again.');
        });
    }

    // ── Restore Previous Conversation ─────────────────────────────────

    // localStorage conversations saved before #140 shipped have no `internal`
    // key at all on any of the four confirmation shapes. `internal === true`
    // alone would un-hide all of them for existing users on upgrade — the same
    // leak the fix closes for new conversations, reappearing for old ones
    // (cross-model review finding: Codex + Testing specialist + Claude
    // adversarial subagent all independently flagged this). These prefixes
    // are a temporary, content-based fallback ONLY for pre-flag data; every
    // message written going forward carries `internal: true` and never
    // touches this path.
    var LEGACY_INTERNAL_PREFIXES = [
        'Changes applied successfully.',
        'Changes applied with warnings: ',
        'Changes applied but rendered page validation failed: '
    ];

    function isLegacyInternalMessage(content) {
        return LEGACY_INTERNAL_PREFIXES.some(function (prefix) {
            return content.indexOf(prefix) === 0;
        });
    }

    function restoreConversation() {
        var state = loadState();
        if (!state) return;

        // Restore the page selection whenever saved state parses — a page
        // picked before the first message must survive reload even though
        // the conversation is still empty. Number() also migrates states
        // saved before activePageId was normalized, which stored the
        // <select>'s string value.
        activePageId = state.activePageId ? Number(state.activePageId) : null;

        if (!Array.isArray(state.conversation) || !state.conversation.length) return;

        conversation = state.conversation;

        // Re-render messages from conversation history
        conversation.forEach(function (msg) {
            if (!msg || typeof msg.content !== 'string') return;
            if (msg.role === 'user') {
                // Skip internal apply-confirmation messages in display
                if (msg.content.charAt(0) === '[') return;
                addMessage('user', msg.content);
            } else if (msg.role === 'assistant') {
                // Skip internal apply-confirmation messages in display —
                // structural flag first (never content-based for new data, so
                // a genuine reply that happens to start with "Changes
                // applied..." is never suppressed), legacy prefix match as a
                // fallback only for messages that predate the flag.
                if (msg.internal === true || isLegacyInternalMessage(msg.content)) return;
                var displayText = stripProposalJson(msg.content);
                if (displayText) {
                    addMessage('assistant', displayText);
                }
            }
        });
    }

    // ── New Chat ──────────────────────────────────────────────────────

    function resetChat() {
        // Starting a new chat mid-stream must not leave an orphaned request
        // running in the background, nor let its callbacks land in the
        // fresh conversation once they eventually fire (issue 139) — bumping
        // currentRequestId makes every one of that request's async callbacks
        // (fetch .then/.catch, ajaxFallback's handlers) a no-op once they
        // check their captured id against it.
        currentRequestId++;
        if (activeStopHandler) activeStopHandler();
        activeStopHandler = null;

        conversation = [];
        activePageId = null;
        clearState();
        messagesEl.innerHTML = '';
        setStreamingUiState(false);
        inputEl.value = '';
        inputEl.focus();
        syncPageSelectValue();
    }

    // ── Event Handlers ─────────────────────────────────────────────────

    sendBtn.addEventListener('click', function () {
        sendMessage(inputEl.value);
    });

    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(inputEl.value);
        }
    });

    if (newChatBtn) {
        newChatBtn.addEventListener('click', resetChat);
    }

    // ── Init ──────────────────────────────────────────────────────────

    try {
        restoreConversation();
    } catch (e) {
        // A corrupted/hand-edited localStorage entry must not abort the rest
        // of init (send/new-chat button wiring) — worst case is losing the
        // restored transcript, not a broken widget (#140 adversarial review).
        conversation = [];
    }
    syncPageSelectValue();
    inputEl.focus();

})();

// Module exports for tests
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        getImpactWarning: ppChatGetImpactWarning,
        formatDiffValue: ppChatFormatDiffValue,
        shouldShowMultiStepWarning: ppChatShouldShowMultiStepWarning,
        isRevertEligible: ppChatIsRevertEligible,
        renderPreviewError: ppChatRenderPreviewError,
        getErrorStepClass: ppChatGetErrorStepClass,
        getStatusMessage: ppChatGetStatusMessage,
        appendValidationItems: ppChatAppendValidationItems,
        buildCompositionSummary: ppChatBuildCompositionSummary,
        detectPageId: ppChatDetectPageId,
        findPageById: ppChatFindPageById,
        shouldSuggestPageSwitch: ppChatShouldSuggestPageSwitch
    };
}
