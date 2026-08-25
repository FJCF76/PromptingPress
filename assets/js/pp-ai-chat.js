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

// ── Composition CAS baselines (#404) ─────────────────────────────────────────
// The chat UI carries a per-page baseline (the composition version the model
// reasoned against) and threads it back on every write so a stale overwrite is
// rejected server-side (composition_conflict) instead of silently clobbering a
// concurrent editor/CLI/chat change. These helpers are pure so tests can pin
// the map arithmetic and the conflict detection without a DOM.

/**
 * Builds the batch baseline map {post_id → version} to send with a batch: for
 * every step that targets a page, include that page's stored baseline when
 * known. A superset of the mutating steps is fine — the server ignores
 * baselines for non-mutating steps and enforces coverage only on mutating ones,
 * so this never needs the server's mutating-action list mirrored in the browser.
 * A page with no stored baseline is omitted (the server then fails the write
 * closed rather than writing without CAS).
 */
function ppChatBuildBatchBaselines(steps, pageBaselines) {
    var map = {};
    if (!steps || !pageBaselines) return map;
    steps.forEach(function (s) {
        var pid = s && s.params && s.params.post_id;
        if (pid === undefined || pid === null || pid === '') return;
        var key = Number(pid);
        if (isNaN(key)) return;
        if (Object.prototype.hasOwnProperty.call(pageBaselines, key)) {
            map[key] = pageBaselines[key];
        }
    });
    return map;
}

/**
 * Merges a server-returned {post_id → version} map (single-execute
 * composition_version, or a batch's versions map) into the stored baselines,
 * mutating and returning the same object. Ignores non-numeric/negative values so
 * a malformed response can't poison a baseline. This is how a successful write
 * refreshes the baseline without a second read.
 */
function ppChatApplyVersionMap(pageBaselines, versions) {
    if (!pageBaselines || !versions || typeof versions !== 'object') return pageBaselines;
    Object.keys(versions).forEach(function (pid) {
        var v = versions[pid];
        var key = Number(pid);
        if (!isNaN(key) && typeof v === 'number' && v >= 0) {
            pageBaselines[key] = v;
        }
    });
    return pageBaselines;
}

/**
 * True when a server error payload is the structured composition_conflict
 * envelope (#404 req.7). Drives the Re-read & re-preview conflict state instead
 * of a generic error line.
 */
function ppChatIsCompositionConflict(errData) {
    return !!(errData && typeof errData === 'object' && errData.error_code === 'composition_conflict');
}

/**
 * True when a completed batch failed on a composition_conflict at its failed
 * step (the batch response is a success envelope carrying a per-step failure).
 */
/**
 * True when a failed batch has NO failing step to report (#749).
 *
 * The executor refuses a whole proposal before step 1 when a page it names has a
 * stored composition it cannot read — a rollback would have to write a degraded
 * stand-in over the only recoverable copy of those bytes. That refusal returns
 * `failed_at: null` with `steps: []`, the one ok:false shape with no step index,
 * and it carries the explanation on the batch itself as `error`.
 *
 * The failure renderer indexes `steps[failed_at]`, so without this guard that
 * shape throws a TypeError and the user sees a stack-shaped string instead of
 * the reason. The chat handler normally answers this case on the !resp.success
 * branch, so reaching here means the page went unreadable between that gate and
 * the executor's — narrow, but a null index is not the way to find out.
 */
function ppChatBatchWasRefusedUpFront(batch) {
    if (!batch || batch.ok) return false;
    return batch.failed_at === null || batch.failed_at === undefined;
}

function ppChatBatchHitConflict(batch) {
    if (!batch || batch.ok || batch.failed_at === null || batch.failed_at === undefined) return false;
    var failed = batch.steps && batch.steps[batch.failed_at];
    return !!(failed && failed.error_code === 'composition_conflict');
}

/**
 * The single user-facing conflict message. One message, one affordance
 * (Re-read & re-preview) — never a blind retry that re-sends the stale write.
 */
function ppChatConflictMessage() {
    return 'This page changed while the proposal was pending (another tab, agent, or editor). Nothing was applied.';
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
            var textKeys = ['title', 'heading', 'text', 'body', 'content', 'description', 'subheading', 'label'];
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

// Actions that overwrite a page's composition (#133). A proposal containing any of
// these leaves a restorable history entry per step, so it earns an "Undo these
// changes" affordance that calls restore_composition (parity with the token
// "Reset to default" link, which only covers a single update_design_token).
var PP_COMPOSITION_MUTATION_ACTIONS = {
    update_composition: true,
    add_component: true,
    remove_component: true,
    reorder_components: true,
    update_component: true,
    restore_composition: true
};

/**
 * Resolves the restore_composition target for an applied proposal's steps (#133).
 *
 * Returns { postId, stepsBack } when the proposal's composition mutations all target a
 * SINGLE page — stepsBack equals the count of those mutations, so restoring walks the
 * history ring back to the state just before the proposal's first composition write.
 * Returns null when there are no composition mutations, a mutation lacks a post target,
 * or the mutations span multiple pages (no single-page undo makes sense there).
 */
function ppChatCompositionUndoTarget(steps) {
    if (!steps || !steps.length) {
        return null;
    }
    var postId = null;
    var count = 0;
    for (var i = 0; i < steps.length; i++) {
        var s = steps[i];
        if (!s || !s.name || !PP_COMPOSITION_MUTATION_ACTIONS[s.name]) {
            continue;
        }
        var pid = s.params && s.params.post_id;
        if (pid === null || pid === undefined || pid === '') {
            return null; // composition mutation without a post target — can't scope an undo
        }
        if (postId === null) {
            postId = pid;
        } else if (String(pid) !== String(postId)) {
            return null; // spans multiple pages — no single-page undo
        }
        count++;
    }
    if (postId === null || count === 0) {
        return null;
    }
    return { postId: postId, stepsBack: count };
}

/**
 * True when a value is text this card can print as a name or a sentence.
 *
 * The one place the "is this readable?" question is answered, so the readers below and
 * the renderer cannot answer it differently. Deliberately as weak as the test #625 shipped
 * on `alternatives` entries: a non-empty string, whitespace included. Trimming would
 * reject `'   '` where ppChatHasSlotAlternatives() has always accepted it, which is a
 * change to what the step class claims, not a rendering fix — and which side owns that
 * contract is #775's question, not this one's.
 */
function ppChatIsNonEmptyString(value) {
    return typeof value === 'string' && value !== '';
}

/**
 * True when a value is a keyed map rather than a list or a null.
 *
 * The other question this card asks twice: `cross_component_hints` must be one, and so
 * must each entry in it. `typeof` calls an array an object and `Object.keys` counts its
 * indexes, so without the Array.isArray leg a list reads as a map that names nothing —
 * the rejection ppChatHasCrossComponentHint() has carried since #625, now shared with
 * the entry test so the two cannot drift apart.
 */
function ppChatIsPlainObject(value) {
    return !!value && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Renders a user-friendly error message in a preview diff area.
 * Handles structured errors (from _pp_build_friendly_error) and plain strings.
 *
 * WHAT IT AGREES TO PRINT (#667). Nothing below is drawn on a weaker test than the one
 * the step's CLASS and the status bar apply to the same field. `alternatives` is exactly
 * the list ppChatHasSlotAlternatives() classifies from; the hints are that classifier's
 * map filtered to the entries that can be drawn (stricter, see the residual below); the
 * two fields neither classifier reads are held to the same "is this text" question:
 *
 *   payload ──┬─ user_message   non-empty string ──► the message, else ──► plain branch
 *             ├─ cross_component_hints ─► renderable hints ─┬─► "…exists on the X…"
 *             │                                             └─► "Available on X: slot"
 *             ├─ alternatives  ─► the entries that are names ─► "Available slots: …"
 *             └─ raw_error      non-empty string ──────────► its own line
 *
 * Before this, each of the four was gated on a weaker test than the classifiers use —
 * truthiness, or a `length` — so `alternatives: [null, {a:1}]` printed
 * "Available slots: , [object Object]" under a step painted `pp-ai-step-impossible`,
 * whose status bar said the change wasn't possible. Two halves of one card, disagreeing
 * by construction. Not reachable through _pp_build_friendly_error() (lib/ai-chat.php),
 * the sole producer, which is the same threat model #625 hardened the classifiers for.
 *
 * A malformed fragment is DROPPED, not narrated: there is no vocabulary here for "the
 * server sent something I could not read", and inventing one is #664's question. The
 * consequence is worth stating plainly — the disclosure never opens holding nothing,
 * because the two things that open it are the two that write lines into it.
 *
 * One disagreement survives and is deliberate: ppChatHasCrossComponentHint() counts a
 * hint MAP with keys, whatever the entries are, so a map whose entries name nothing
 * still paints the step fixable and still gets the "lives on a different component"
 * sentence, while the card now shows no hint at all. Printing "exists on the undefined
 * component" instead would be a false claim, so this is the better end of the trade —
 * but tightening the classifier itself would move #625's landed behavior, which this
 * issue does not own. Filed as #789.
 */
function ppChatRenderPreviewError(diffArea, data) {
    // Structured error from _pp_build_friendly_error (style_component failures).
    if (data && typeof data === 'object' && ppChatIsNonEmptyString(data.user_message)) {
        var msgEl = document.createElement('div');
        msgEl.className = 'pp-ai-preview-error-message';
        msgEl.textContent = data.user_message;
        diffArea.appendChild(msgEl);

        // Cross-component hint: the first RENDERABLE one. On the shipped payload that is
        // the first entry, the same one _pp_build_friendly_error() picks with reset() to
        // build user_message, so the sentence and the line name the same component. The
        // two can only diverge on a map whose leading entries name nothing, which no
        // producer emits — and a card that skipped the sentence's subject still beats one
        // that prints "undefined" for it.
        var hints = ppChatRenderableCrossComponentHints(data);
        if (hints.length > 0) {
            var hintEl = document.createElement('div');
            hintEl.className = 'pp-ai-preview-error-hint';
            hintEl.textContent = 'This setting exists on the ' + hints[0].component + ' component.';
            diffArea.appendChild(hintEl);
        }

        var alternatives = ppChatSlotAlternatives(data);
        var rawError = ppChatIsNonEmptyString(data.raw_error) ? data.raw_error : '';

        // Technical details in a native <details> disclosure. Hints alone do not open
        // it — that predates this change and is pinned as-is, not endorsed.
        if (alternatives.length > 0 || rawError) {
            var details = document.createElement('details');
            details.className = 'pp-ai-preview-error-detail';
            var summary = document.createElement('summary');
            // This block is where the server's message sends the author for the rest of
            // a sampled slot list ("the full list is in the details below", #661), so it
            // must keep rendering BELOW .pp-ai-preview-error-message. The wording there
            // deliberately does not quote this label, so renaming the summary is safe;
            // moving the disclosure above the message is not.
            summary.textContent = 'Show technical details';
            details.appendChild(summary);

            var content = document.createElement('div');
            var lines = [];
            if (rawError) {
                lines.push(rawError);
            }
            for (var h = 0; h < hints.length; h++) {
                lines.push('Available on ' + hints[h].component + ': ' + hints[h].slot);
            }
            if (alternatives.length > 0) {
                lines.push('Available slots: ' + alternatives.join(', '));
            }
            content.textContent = lines.join('\n');
            details.appendChild(content);
            diffArea.appendChild(details);
        }
        return;
    }

    // Plain string error (non-style_component actions). This is also where every caught
    // render failure lands (#663), carrying a bounded string, so the string arm is the
    // one behavior in this function that must never move.
    //
    // `message` is guarded too, and the guard costs nothing real: this value arrives from
    // JSON.parse, which cannot produce a String wrapper or any other stringy object, so
    // the only inputs it turns into "Preview failed" are ones that used to print
    // "[object Object]" — a sentence about nothing either way.
    diffArea.textContent = typeof data === 'string'
        ? data
        : (ppChatIsNonEmptyString(data && data.message) ? data.message : 'Preview failed');
}

/**
 * True when a friendly error points at another component that does have the setting.
 *
 * `cross_component_hints` is a JSON object keyed by rejected slot name. A missing key, a
 * null, or anything that isn't such a map means "no hint" — including an ARRAY, which
 * `typeof` calls an object and `Object.keys` happily counts, so it would otherwise be
 * read as a hint that names nothing.
 *
 * The class painted on the step and the sentence written in the status bar both come
 * from here, so they cannot disagree about whether a hint exists. The renderable-hint
 * list below starts from this answer and then asks for more (an entry it can draw), so
 * it is this predicate's reader, not its equal.
 */
function ppChatHasCrossComponentHint(data) {
    var hints = data && data.cross_component_hints;
    if (!ppChatIsPlainObject(hints)) return false;
    return Object.keys(hints).length > 0;
}

/**
 * The hints in a friendly error that can actually be DRAWN, in payload order (#667).
 *
 * The predicate above answers "does this payload claim a hint", which is what the step
 * class and the status bar need. Drawing one needs more than a claim: a component to
 * name and a slot to print. `_pp_build_friendly_error()` (lib/ai-chat.php) writes both
 * on every entry it emits — a three-key literal of `component`, `slot` and `match` — so
 * on the shipped path this returns every entry and the card is unchanged.
 *
 * Named for the narrower question rather than as the predicate's list form, because it
 * is NOT its list form: ppChatHasSlotAlternatives() is true exactly when its list is
 * non-empty, and this one can be empty while the predicate above is true. That gap is
 * the deliberate residual — an entry that isn't a drawable map contributes nothing
 * rather than "This setting exists on the undefined component", so a map of nulls still
 * classifies fixable while the card shows no hint. Better than printing a name that
 * isn't there, and closing it the other way — teaching the classifier this same test (#789) —
 * would move what #625 landed on the step class and the status sentence.
 */
function ppChatRenderableCrossComponentHints(data) {
    if (!ppChatHasCrossComponentHint(data)) return [];
    var hints = data.cross_component_hints;
    var keys = Object.keys(hints);
    var renderable = [];
    for (var i = 0; i < keys.length; i++) {
        var hint = hints[keys[i]];
        if (ppChatIsPlainObject(hint) &&
            ppChatIsNonEmptyString(hint.component) && ppChatIsNonEmptyString(hint.slot)) {
            renderable.push(hint);
        }
    }
    return renderable;
}

/**
 * True when a friendly error names settings the author could use instead (#625).
 *
 * `alternatives` is always a JSON array of slot names on the wire — every producer
 * builds it with array_keys() (_pp_build_friendly_error(), lib/ai-chat.php), which
 * returns a list of non-empty strings. This is the boundary where a malformed payload
 * is decided against, so it asks for what it actually needs: at least one entry that is
 * a name. A list of nulls, objects, or empty strings has a length but names nothing,
 * and "there are settings you could use instead" would be a false claim about it.
 *
 * Since #667 it asks the list below rather than re-deriving the test, so the claim it
 * makes and the names the card prints are the same set by construction. Its answer is
 * unchanged for every input: "at least one entry is a name" is exactly "the list of
 * entries that are names is non-empty".
 */
function ppChatHasSlotAlternatives(data) {
    return ppChatSlotAlternatives(data).length > 0;
}

/**
 * The entries of `alternatives` that are names, in payload order (#667).
 *
 * The renderer prints this list, and the predicate above classifies from it, so no
 * payload can put a slot list in front of the author under a step that says there is
 * nothing to try. A non-array is no list at all — `alternatives: '--hero-bg'` has a
 * `length`, and joining it used to throw, which #663's catch then rendered as a generic
 * failure rather than the answer the server actually sent.
 *
 * What counts as a name is #625's test, unchanged: whether a numeric or list-shaped slot
 * map should widen it is #775's open question, and it is answered in ONE place now, so
 * whichever way that lands the card and the class move together.
 */
function ppChatSlotAlternatives(data) {
    var alternatives = data && data.alternatives;
    if (!Array.isArray(alternatives)) return [];
    var names = [];
    for (var i = 0; i < alternatives.length; i++) {
        if (ppChatIsNonEmptyString(alternatives[i])) names.push(alternatives[i]);
    }
    return names;
}

/**
 * Determines the CSS class for a failed step: does the error leave the author a move?
 *
 * `pp-ai-step-impossible` is a claim about CAPABILITY — "there is nothing to change
 * here" — and it is the end of the conversation for the author who reads it. It is
 * reserved for errors whose payload names no next action at all:
 *
 *   no_style_slots                          the component supports no style
 *                                           customization whatsoever
 *   invalid_style_slot, nothing named       neither a slot on this component nor one
 *                                           on another; nothing to point at
 *
 * An `invalid_style_slot` that names something is `pp-ai-step-fixable`, because the
 * answer is in the author's hands: `cross_component_hints` says the setting lives on
 * another component (retarget), and `alternatives` lists the settings this component
 * does declare (pick one). Before #625 only the hint counted, so a near-miss slot
 * name — `--hero-bgs` for `--hero-bg` — went grey and the status bar said the change
 * wasn't possible, with the correct slot sitting in `alternatives` in the very same
 * payload. Typing a name wrong is not a missing capability.
 *
 * Note what this reads and what it doesn't: the payload's own account of what the
 * author can do next, not the presence of any one field. A hint alone is not proof of
 * author-fixability — a recipe that drifts out of its component's declared slots also
 * produces one, and the author never typed that slot — but such a rejection carries
 * `alternatives` too, so it lands on the same fixable class by the same reasoning.
 *
 * Reachability of the impossible arm, so it isn't misread as live: with the SHIPPED
 * components neither route can fire. `no_style_slots` needs a placeable component that
 * declares no style slots, and the only two that declare none (nav, footer) are site
 * chrome the composition validator refuses to place — which is why the server-side pin
 * for it has to synthesize a fixture theme. And `invalid_style_slot` naming nothing
 * cannot occur, because the validator returns `no_style_slots` before comparing any slot
 * name, so `alternatives` is non-empty on every rejection it produces. The arm survives
 * for a component this theme doesn't ship — a child or third-party one that is placeable
 * and declares no slots — and for producers that build the error by hand.
 */
function ppChatGetErrorStepClass(data) {
    if (!data || typeof data !== 'object') return 'pp-ai-step-failed';
    var code = data.error_code || '';
    if (code === 'no_style_slots') return 'pp-ai-step-impossible';
    if (code === 'invalid_style_slot') {
        if (ppChatHasCrossComponentHint(data)) return 'pp-ai-step-fixable';
        if (ppChatHasSlotAlternatives(data)) return 'pp-ai-step-fixable';
        return 'pp-ai-step-impossible';
    }
    if (code === 'invalid_style_value' || code === 'invalid_recipe') return 'pp-ai-step-fixable';
    return 'pp-ai-step-failed';
}

/**
 * Derives a contextual status bar message from the first failed step's error data.
 *
 * Says the same thing the step's class says (ppChatGetErrorStepClass above), in words:
 * a rejection painted fixable must not be narrated as impossible. The two read the same
 * two helpers, and the hint branch carries the same `invalid_style_slot` gate the class
 * puts on it, so there is no payload for which one speaks and the other disagrees. That
 * gate changes nothing that ships — `_pp_build_friendly_error()` (lib/ai-chat.php) can
 * only produce a non-empty hint map from its invalid_style_slot case; every other branch
 * returns a hardcoded empty object — it just removes the one place the two could drift.
 *
 * The `invalid_style_slot` gate on the settings sentence is load-bearing, not defensive:
 * `invalid_recipe` also ships a non-empty `alternatives` (the component's recipe names),
 * and "setting name" is the wrong word for a recipe.
 *
 * Two things the sentence is careful about.
 *
 * It blames the NAME, not the component. For the case #625 is about, the author asked for
 * something the component can do and the slot name came back wrong; saying the setting
 * isn't available would deny a capability sitting in `alternatives` in the same payload.
 * Naming the name is true of the near miss and of a setting the component really doesn't
 * declare, which is the other thing that lands here.
 *
 * And it POINTS at the settings rather than claiming they are all up there. What renders
 * unconditionally above this bar is `user_message`, and since #661 the non-hint branch of
 * _pp_build_friendly_error() (lib/ai-chat.php) names at most PP_FRIENDLY_SLOT_SAMPLE_MAX
 * of them plus a total count, sending the reader to `alternatives` in the collapsed
 * <details> for the rest. So the settings above are real and are named — the sentence used
 * to avoid the word "names" because the branch printed DESCRIPTIONS, and that is no longer
 * what it prints — but on a component declaring dozens they are a sample, and "the
 * settings it has are listed above" would be a promise the card no longer keeps.
 */
function ppChatGetStatusMessage(data) {
    if (!data || typeof data !== 'object') return 'Some changes couldn\'t be previewed. See details above.';
    var code = data.error_code || '';
    if (code === 'invalid_style_slot' && ppChatHasCrossComponentHint(data)) {
        return 'That setting lives on a different component. See details above.';
    }
    if (code === 'invalid_style_slot' && ppChatHasSlotAlternatives(data)) {
        return 'I used a setting name this component doesn\'t have. See the settings it does have above.';
    }
    if (code === 'no_style_slots' || code === 'invalid_style_slot') return 'This change isn\'t possible with the current component settings.';
    if (code === 'invalid_style_value') return 'The value format needs adjustment. See suggestions above.';
    return 'Some changes couldn\'t be previewed. See details above.';
}

/**
 * Renders one non-composition change as `path: from -> to`.
 *
 * Module scope (not IIFE-local) because ppChatRenderPreviewResult() below has to reach
 * it and is itself unit-tested directly. Every name it reads — `document`,
 * ppChatFormatDiffValue — is already module-scope, so nothing IIFE-local was lost in
 * the move.
 */
function ppChatRenderDiffLine(change) {
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

/**
 * Renders an update_composition diff: a prose summary plus the raw JSON in a disclosure.
 *
 * Module scope for the same reason as ppChatRenderDiffLine above.
 */
function ppChatRenderCompositionDiff(diffArea, change) {
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

/**
 * Longest engine-supplied exception text echoed into a step card (#663).
 *
 * The prefix below is fixed prose and is not counted against it. Two hundred is not a
 * safety boundary — the text is written with textContent and never reaches a parser —
 * it is a LAYOUT boundary. The card that broke sits in a column with the N steps that
 * did render, and #663 exists so those N stay visible; letting one pathological message
 * grow that card without limit would push them off the screen and re-create the symptom
 * by another route. Every real engine's TypeError/ReferenceError text runs well under
 * this, so on a genuine renderer bug the operator reads the whole reason.
 *
 * The budget covers THIS sentence, not the whole card. A step's normal error path still
 * renders `raw_error` and `alternatives` at whatever length the server sent (bounded
 * there, by PP_REFLECTED_ERROR_MAX), and a successful update_composition preview still
 * renders its full JSON in a disclosure. Bounding the one string this file invents is
 * not a claim that the card is bounded everywhere.
 */
var PP_CHAT_RENDER_ERROR_MAX = 200;

/**
 * Turns a caught render exception into the sentence a step card shows.
 *
 * Truncation follows the convention used across this codebase (lib/ai-chat.php's
 * _pp_clean_reflected_text, ppChatFormatDiffValue above): cut to max - 3 and mark it,
 * so the result never exceeds the stated budget.
 *
 * The wording says DISPLAYED, not "failed": by the time this fires the server has
 * usually answered fine and it is this file that could not draw the answer. Telling the
 * operator their change was rejected would be a false claim about the server.
 *
 * READING `message` IS ITSELF GUARDED. Every caller is inside a catch, so a throw here
 * would escape that catch and take the remaining steps down with it — the exact failure
 * this whole change exists to stop. `err.message` is an ordinary property on everything
 * the engine throws, but it does not have to be: a getter or a `Symbol.toPrimitive` can
 * throw, and `throw` accepts any value. Nothing reachable today does that; the guard is
 * here so the claim "the catch cannot throw" is true of the code rather than of the
 * inputs we happen to expect.
 */
function ppChatPreviewRenderErrorText(err) {
    var raw;
    try {
        if (err && err.message) {
            raw = String(err.message);
        } else if (typeof err === 'string') {
            // `throw 'something'` is legal and carries no `.message`. Falling back to
            // 'Unknown error' here would lose the only reason the card has, which is the
            // loss this issue is about. Objects deliberately do NOT fall through to
            // String(err): '[object Object]' says less than 'Unknown error' does.
            raw = err;
        } else {
            raw = '';
        }
    } catch (e) {
        raw = '';
    }
    if (!raw) {
        raw = 'Unknown error';
    }
    if (raw.length > PP_CHAT_RENDER_ERROR_MAX) {
        raw = raw.substring(0, PP_CHAT_RENDER_ERROR_MAX - 3) + '...';
    }
    return 'Preview could not be displayed: ' + raw;
}

/**
 * Renders ONE preview result into its step, and does not propagate render failures (#663).
 *
 * Returns null when the step rendered a preview, or a `{ data: ... }` wrapper when the
 * step ended in an error state.
 *
 * WHY THIS IS A FUNCTION AT ALL. Its caller renders N of these in a loop. Before #663
 * that loop was a bare forEach inside a Promise.all().then() with no guard anywhere on
 * the chain, so a throw on step 2 of 5 abandoned steps 3, 4 and 5 mid-flight: they kept
 * the `pp-ai-step-executing` class and the 'Loading preview' placeholder their card was
 * built with, the status message never ran, and the Apply/Cancel row was never appended.
 * One renderer slip cost the whole card, silently and permanently.
 *
 *   result i ──▶ remove executing ──▶ clear placeholder ──┬─▶ success ─▶ diff lines ─▶ null
 *        │                                                │
 *        │                                                └─▶ failure ─▶ error card ─▶ {data}
 *        │                                                                    │
 *        └──────────────── throw, anywhere above ─────────────────────────────┘
 *                                     │
 *                                     ▼
 *                     drop partial DOM ─▶ plain-string error card
 *                     ─▶ pp-ai-step-failed ─▶ {data: text}
 *
 * Every arm ends in a terminal state, so step i cannot leave step i+1 unrendered.
 *
 * THE ERROR ARM RENDERS BEFORE IT CLASSIFIES, AND THE ORDER IS LOAD-BEARING. Painting
 * ppChatGetErrorStepClass()'s answer first would leave a half-drawn card wearing a
 * classified state if the render then threw, and the catch would have to REMOVE that
 * class before adding its own — which means enumerating the error-class set in a second
 * place. That enumeration drifting is exactly the hazard #662's typography tripwire
 * exists to catch (tests/js/pp-ai-chat-error-card-typography.test.js). Rendering first
 * costs nothing and leaves the catch a step with no state class to fight over. Do not
 * "tidy" the two statements back into declaration order.
 *
 * THE CATCH DELIBERATELY COLLAPSES TO THE GENERIC FAILED STATE. A payload the renderer
 * choked on is a payload this card cannot honestly narrate, so claiming its classified
 * state (fixable, impossible) would assert something the card visibly did not draw. The
 * status bar collapses with it: the wrapper carries a STRING, and ppChatGetStatusMessage()
 * answers any non-object with its generic sentence. So both halves land on "something
 * went wrong here" rather than one claiming a specific remedy the other cannot show —
 * which is the agreement #625 and #662 both turn on. Note what that is not: the card
 * names the reason and the status bar does not, so the agreement is that neither
 * OVERCLAIMS, not that they print the same words. Whether this generic state deserves
 * its own vocabulary is #664's question, not this one's; nothing here adds a fourth class.
 *
 * `_previewChanges` is dropped on entry and re-stashed only once the whole diff has
 * drawn, so the field is present exactly when a drawn preview justifies it. Setting it
 * up front would strand the payload on a step that then failed to draw; leaving a
 * previous run's value would do the same for a caller that re-renders the same step
 * object.
 *
 * Be precise about why that matters, because it is easy to overstate: NOTHING READS THIS
 * FIELD. executeProposal() re-serializes from `type`/`name`/`params` and never touches
 * it, and a repo-wide search finds no other reader. So this is hygiene on a write, not a
 * guard on a read — a step painted failed cannot hand a stale array to a future reader
 * that does not exist yet. Whether the field should be wired up or removed outright is
 * filed separately; it is not decided here.
 *
 * The catch repeats the error arm's three statements rather than sharing a helper with
 * it. They are alike only in shape: the arm narrates a server payload, the catch
 * narrates a bounded local string over a diff area it must first clear. Folding two
 * three-line sequences with different preconditions into one function would hide that
 * difference to save nothing.
 *
 * The catch itself cannot throw. ppChatPreviewRenderErrorText() guards its own read of
 * `message`, ppChatRenderPreviewError() with a string takes its plain-string branch (one
 * textContent assignment), and ppChatGetErrorStepClass() with a string returns
 * 'pp-ai-step-failed' off its first line. Passing something that is not an element for
 * `stepEl` or `diffArea` is a programming error, not a render failure, and is not what
 * this guard is for.
 */
function ppChatRenderPreviewResult(stepEl, diffArea, step, result) {
    try {
        stepEl.classList.remove('pp-ai-step-executing');
        diffArea.textContent = '';
        delete step._previewChanges;

        if (result && result.success && result.data && result.data.changes) {
            result.data.changes.forEach(function (change) {
                if (step.name === 'update_composition' && change.path === 'composition' &&
                    Array.isArray(change.from) && Array.isArray(change.to)) {
                    ppChatRenderCompositionDiff(diffArea, change);
                } else {
                    diffArea.appendChild(ppChatRenderDiffLine(change));
                }
            });
            if (result.data.changes.length === 0) {
                diffArea.textContent = '(no changes)';
            }
            step._previewChanges = result.data.changes;
            return null;
        }

        var data = result ? result.data : undefined;
        ppChatRenderPreviewError(diffArea, data);
        stepEl.classList.add(ppChatGetErrorStepClass(data));
        return { data: data };
    } catch (e) {
        var text = ppChatPreviewRenderErrorText(e);
        diffArea.textContent = '';
        ppChatRenderPreviewError(diffArea, text);
        stepEl.classList.add(ppChatGetErrorStepClass(text));
        return { data: text };
    }
}

/**
 * Maps a restore finding's severity to its display class (#622).
 *
 * `severity: 'error'` means a normal write of the restored composition would be
 * REJECTED by current rules; `severity: 'warning'` is advisory. Anything else (an
 * older payload, a missing key) degrades to the warning class rather than
 * over-escalating.
 */
function ppChatFindingClass(item) {
    return (item && item.severity === 'error') ? 'pp-ai-step-failed' : 'pp-ai-step-warning';
}

/**
 * The report's truncation tail, or null when the report is complete (#654, #655).
 *
 * ONE PREDICATE, TWO READERS. The heading reads this entry for the true total, and
 * ppChatAppendUndoFindings() lifts the same entry out of the findings list so its
 * message renders outside the disclosure. Asking the question in two places is how the
 * two start disagreeing — a hoisted entry the counter did not recognize would be
 * subtracted from neither number while occupying a line of its own.
 *
 * The test is deliberately the strict one #654 shipped: type AND a positive numeric
 * `total`. A malformed tail is therefore not a tail at all — it stays an ordinary
 * finding row and the count falls back to the array length, exactly as before.
 *
 * ONE SPECIES, ON PURPOSE. The other report-about-the-report entry, `findings_skipped`,
 * cannot reach this card: it comes from the accepted-write path's 1 MiB availability gate,
 * and restore deliberately does not inherit that gate (#233 — you are told what an old
 * snapshot brought back, always). If it ever did arrive it would match neither this
 * predicate nor its `total` requirement (it carries none, because nothing was counted), so
 * it would render as an ordinary finding row and be counted as a problem with the
 * composition — the stronger disclaimer of the two, buried. That routing is pinned by
 * CompositionFindingsBoundsTest::testRestoreNeverEmitsTheSkippedSpeciesTheUndoCardCannotHoist
 * rather than assumed here.
 */
function ppChatUndoFindingsTail(findings) {
    for (var i = 0; i < findings.length; i++) {
        var f = findings[i];
        if (f && f.type === 'findings_truncated' && typeof f.total === 'number' && f.total > 0) {
            return f;
        }
    }

    return null;
}

/**
 * The two numbers the heading needs: { total, shown, truncated }.
 *
 * `tail` is optional. Pass the entry ppChatUndoFindingsTail() already found — the caller
 * that hoists it needs it anyway — and this reads it instead of scanning for a second
 * copy of the same answer. Omit it and this finds its own. Only `undefined` means "not
 * supplied"; `null` is the real answer "this report is complete" and is respected.
 */
function ppChatUndoFindingsTotal(findings, tail) {
    if (tail === undefined) {
        tail = ppChatUndoFindingsTail(findings);
    }
    if (tail !== null) {
        // The tail is an advisory ABOUT the report, not an issue with the composition,
        // so it never counts toward either number.
        return { total: tail.total, shown: findings.length - 1, truncated: true };
    }

    return { total: findings.length, shown: findings.length, truncated: false };
}

/**
 * Renders restore_composition's findings on a SUCCESSFUL undo (#233).
 *
 * Restore is never blocked by current validation rules — a snapshot today's validators
 * reject still restores, because undo is the user's safety net and must not fail. The
 * findings therefore describe a write that happened, so the HEADING stays a warning and
 * still says "Restored": telling the user undo broke when it worked is its own trust bug.
 *
 * The ITEMS, though, are styled per severity (#622). Every finding used to render as a
 * warning regardless of `severity`. Before #604 the read path canonicalized legacy keys,
 * which suppressed error-severity findings on old snapshots and made that path close to
 * unreachable; now undoing any pre-vocabulary-freeze snapshot produces them, and an
 * error-severity finding ("a normal write of this would be rejected") shown in the same
 * grey as an advisory smell understates what the user has to fix.
 *
 * Renders `findings` only. The AJAX handler also injects `validation`
 * (pp_post_apply_validate), which flags template-owned chrome as well, so rendering both
 * would list the same component twice.
 *
 * THE COUNT IS THE SERVER'S, NOT THE ARRAY'S (#654). Since restore's report became
 * bounded, `findings.length` is the number of findings DELIVERED, not the number that
 * exist — on a pathological snapshot it is 101 when the truth is 20,001. The heading
 * therefore reads the true total out of the `findings_truncated` entry, and says plainly
 * that it is showing a subset. A diagnostic that quietly understates itself by two orders
 * of magnitude is worse than a long one; this card is the only place a non-CLI operator
 * sees what an undo brought back.
 *
 * THE LAYOUT, top to bottom (#655): the heading, then the truncation notice if the report
 * was cut, then the band-aware inline rows, then the disclosure holding the rest. The
 * notice sits between the heading whose count it qualifies and the rows it explains the
 * absence of, which is where a reader resolves "20,001 issues, so why five lines?".
 */
function ppChatAppendUndoFindings(card, findings) {
    if (!findings || !findings.length) return;

    var section = document.createElement('div');
    section.setAttribute('role', 'status');
    section.setAttribute('aria-live', 'polite');

    var tail    = ppChatUndoFindingsTail(findings);
    var counted = ppChatUndoFindingsTotal(findings, tail);

    var heading = document.createElement('div');
    heading.className = 'pp-ai-step-warning';
    heading.textContent = '⚠ Restored, but the previous version has '
        + counted.total + ' issue' + (counted.total === 1 ? '' : 's')
        + ' under current rules'
        + (counted.truncated ? ' (showing the first ' + counted.shown + ')' : '')
        + ':';
    section.appendChild(heading);

    // THE TRUNCATION NOTICE IS NOT A FINDING, SO IT DOES NOT LIVE WITH THEM (#655).
    // #654 gave the heading the server's true total but left this entry inside the
    // findings array, which on a truncated report means it is the LAST of 101 entries
    // and therefore always inside the collapsed disclosure. The one sentence naming
    // `wp pp check page --post_id=N` — the only route to the complete report the card is
    // admitting it cannot show — was buried inside the thing it exists to escape. It is
    // lifted here: rendered under the heading it qualifies, before the rows, in the open.
    //
    // Lifting it also keeps it out of the band-aware selection below, where an entry
    // that describes the REPORT (index: null by construction) would otherwise consume an
    // inline slot that belongs to an affected band.
    if (tail !== null && ppChatIsNonEmptyString(tail.message)) {
        var notice = document.createElement('div');
        notice.className = 'pp-ai-step-warning';
        notice.textContent = tail.message;
        section.appendChild(notice);
    }

    var items = (tail === null) ? findings : findings.filter(function (f) { return f !== tail; });

    ppChatAppendValidationItems(section, items, ppChatFindingClass);
    card.appendChild(section);
}

/**
 * The composition offset a finding owns, or null when it owns none (#622, #655).
 *
 * The one place "does this finding name a band?" is answered, so the selection below and
 * the locator renderer cannot answer it differently. `index` is documented as `int|null`
 * (`pp_composition_error_index()`, lib/admin.php — an `is_int` read), so anything else is
 * not a locator: a numeric string, a float, a NaN, a negative offset, or the key being
 * absent entirely (every post-apply validation item, which carries `check` and `message`
 * and no locator at all). Those all group as "unlocated" and draw no `index N`, rather
 * than putting a number on screen that no band answers to.
 */
function ppChatFindingBand(item) {
    if (!item || typeof item.index !== 'number' || !isFinite(item.index)) return null;
    if (item.index < 0 || Math.floor(item.index) !== item.index) return null;

    return item.index;
}

/**
 * A finding's locator, as the row prefix `[type] index N: ` (#655).
 *
 * The CLI's idiom, adapted: `_pp_cli_finding_line()` (lib/cli.php) renders
 * `  - [unknown_prop] index 0: message` and this renders the same string without the
 * list bullet, so an operator reads a card row and a `wp pp check page` line the same
 * way. ONE vocabulary for band locators — a fourth spelling is the #650/#652 lesson.
 *
 * No `index` → `[type]: `, the CLI's own choice to omit rather than fake a locator for
 * a cross-item rule. No `type` → no prefix at all, which is the post-apply validation
 * path: those items are not findings, carry no locator, and must render exactly as they
 * did before this change.
 */
function ppChatFindingLocator(item) {
    if (!item || !ppChatIsNonEmptyString(item.type)) return '';

    var band = ppChatFindingBand(item);

    return '[' + item.type + ']' + (band === null ? '' : ' index ' + band) + ': ';
}

/**
 * One rendered row: the locator, then the message (#655).
 *
 * Shared by the inline rows and the disclosure rows so a band cannot be identifiable
 * above the fold and anonymous below it — the disclosure is where the SECOND, THIRD and
 * fourth findings of an already-shown band land, which is exactly where "which band is
 * this?" is hardest to answer.
 */
function ppChatValidationItemRow(item, className) {
    var div = document.createElement('div');
    div.className = className;
    div.textContent = ppChatFindingLocator(item) + item.message;

    return div;
}

/**
 * Appends validation items (errors or warnings) to a container.
 * Shows up to 5 inline; collapses the rest in a <details> disclosure (D6).
 *
 * `className` is either a fixed class string (the post-apply validation paths, whose
 * items are already split into an errors list and a warnings list) or a function
 * (item) -> class for a list that carries its own per-item severity — today the restore
 * findings (#622). In the per-item form the disclosure summary's noun is derived from
 * the hidden items' own severities ("errors", "warnings", or "issues" when they are
 * mixed), never from the class string: calling a set that contains errors "warnings"
 * is the same misreport one level up.
 *
 * THE INLINE ROWS ARE CHOSEN BAND-AWARE (#655). The budget of 5 was calibrated when a
 * band could contribute at most ONE finding, so five rows meant five bands. #621 made a
 * band report every problem its rules can locate — a retired prop key, a dead style
 * slot, a dead card link, two missing required props — so a single broken band routinely
 * fills all five, and every OTHER affected band hides behind the disclosure. The
 * consumer that most needs the wide report showed the least of it. Selection is now
 * first-finding-per-distinct-`index`:
 *
 *   findings                     inline (≤5)                 disclosure
 *   ─────────────────────────    ─────────────────────────   ──────────────────────
 *   [0] index 0  unknown_prop ──► [0]  (band 0, first)
 *   [1] index 0  dead slot     ─────────────────────────────► [1]
 *   [2] index 0  dead link     ─────────────────────────────► [2]
 *   [3] index 2  unknown_prop ──► [3]  (band 2, first)
 *   [4] index 5  missing prop ──► [4]  (band 5, first)
 *
 * No backfill into the unused slots: an inline row is a DIFFERENT band, and filling the
 * remainder with more of band 0 would take that back. The cost is stated rather than
 * hidden — a one-band page with six findings now draws one row and "Show 5 more errors"
 * where it used to draw five rows. The locator on each row (ppChatFindingLocator) and the
 * disclosure are what carry the rest.
 *
 * FIRST-PER-BAND IS ALSO WORST-PER-BAND, and that is inherited rather than coded here.
 * _pp_composition_findings() (lib/actions.php) appends every ERROR the error engine found
 * and only then every advisory from the smell engine, so a band that has an error meets
 * this loop at that error first and the error is what takes the inline row. Picking the
 * FIRST occurrence therefore never promotes a band's advisory over its blocker — which
 * would undo #622's reason for styling items by severity at all. If that assembler ever
 * interleaves the two engines, this has to select by severity explicitly — which is why
 * the order is pinned on the PHP side (CompositionFindingsBoundsTest) as well as the
 * order-preserving selection here, rather than left as a comment on a cross-file
 * dependency.
 *
 * An UNLOCATED item is its own group, never pooled with the other unlocated ones: a
 * cross-item rule and a param error are different problems, and — the reason this matters
 * beyond findings — the post-apply validation paths pass items that carry no `index` at
 * all, so pooling them would collapse a five-error list into one row. Those lists select
 * exactly as they did before this change.
 */
function ppChatAppendValidationItems(container, items, className) {
    if (!items || !items.length) return;

    var perItem = (typeof className === 'function');
    var classFor = perItem ? className : function () { return className; };

    var MAX_INLINE = 5;
    var shown = [];
    var overflow = [];
    var seenBands = {};

    items.forEach(function (item) {
        var band = ppChatFindingBand(item);
        var key = (band === null) ? null : 'band:' + band;
        var isFirstOfBand = (key === null) || !Object.prototype.hasOwnProperty.call(seenBands, key);
        if (key !== null) {
            seenBands[key] = true;
        }

        if (isFirstOfBand && shown.length < MAX_INLINE) {
            shown.push(item);
        } else {
            overflow.push(item);
        }
    });

    var noun;
    if (!perItem) {
        noun = (className.indexOf('warning') !== -1) ? 'warning' : 'error';
    } else {
        var hasError = false;
        var hasOther = false;
        overflow.forEach(function (item) {
            if (item && item.severity === 'error') { hasError = true; } else { hasOther = true; }
        });
        noun = (hasError && hasOther) ? 'issue' : (hasError ? 'error' : 'warning');
    }

    shown.forEach(function (item) {
        container.appendChild(ppChatValidationItemRow(item, classFor(item)));
    });

    if (overflow.length > 0) {
        var details = document.createElement('details');
        details.className = 'pp-ai-preview-error-detail';
        var summary = document.createElement('summary');
        summary.textContent = 'Show ' + overflow.length + ' more ' + noun + (overflow.length === 1 ? '' : 's');
        details.appendChild(summary);

        overflow.forEach(function (item) {
            details.appendChild(ppChatValidationItemRow(item, classFor(item)));
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
                activePageId: activePageId,
                pageBaselines: pageBaselines
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

    // ── Composition CAS baselines (#404) ──────────────────────────────

    // Stores a {post_id, version} baseline from a context read (SSE done event
    // or AJAX fallback). Null/malformed payloads are ignored.
    function storePageBaseline(baseline) {
        if (!baseline || typeof baseline !== 'object') return;
        var pid = Number(baseline.post_id);
        var version = baseline.version;
        if (isNaN(pid) || typeof version !== 'number' || version < 0) return;
        pageBaselines[pid] = version;
        saveState();
    }

    // Re-reads a page's current composition version from the server and refreshes
    // the stored baseline. Backs the Re-read & re-preview conflict affordance:
    // resolves once the fresh baseline is stored, rejects on any failure.
    function refreshBaseline(pageId) {
        var d = new FormData();
        d.append('action', 'pp_ai_page_baseline');
        d.append('nonce', config.executeNonce);
        d.append('post_id', pageId);
        return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: d })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success && resp.data && typeof resp.data.version === 'number') {
                    storePageBaseline(resp.data);
                    return resp.data;
                }
                throw new Error('baseline read failed');
            });
    }

    // Re-reads the baseline for every distinct page a set of steps targets, best
    // effort. Used after a rolled-back batch, whose snapshot-restore bumps the
    // version of every touched page (#404): without this the stored baseline goes
    // stale and the next apply false-conflicts.
    function refreshTouchedBaselines(steps) {
        if (!steps) return;
        var seen = {};
        steps.forEach(function (s) {
            var pid = s && s.params && s.params.post_id;
            if (pid === undefined || pid === null || pid === '') return;
            var key = Number(pid);
            if (isNaN(key) || seen[key]) return;
            seen[key] = true;
            refreshBaseline(key).catch(function () { /* best effort */ });
        });
    }

    // ── State ─────────────────────────────────────────────────────────

    var conversation = [];
    var isStreaming = false;
    var activePageId = null;
    // Per-page composition CAS baselines {post_id → version} (#404): captured on
    // every context read, refreshed from every successful write, threaded back on
    // the next write. Persisted with the conversation so a reload keeps the
    // baseline the pending proposal was reasoned against.
    var pageBaselines = {};

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
            // Each result is rendered through a helper that cannot propagate a render
            // failure, so step i breaking cannot cost steps i+1..n their previews (#663).
            //
            // The wrapper the helper returns is what fixes the second half of #663: the
            // old code kept `if (!firstFailedData) firstFailedData = result.data`, which
            // asks whether the PAYLOAD is truthy, not whether a step failed. A response
            // that isn't the {success, data} envelope has no `data` at all — an expired
            // nonce makes check_ajax_referer() emit a bare -1, which parses to the number
            // -1 with `data === undefined` — so that step was painted as failed and then
            // skipped here, and the status bar described a LATER step's error. A wrapper
            // object is truthy whatever it carries, so the skew cannot come back.
            var firstFailure = null;

            results.forEach(function (result, i) {
                var failure = ppChatRenderPreviewResult(stepElements[i], diffAreas[i], steps[i], result);
                if (failure && !firstFailure) firstFailure = failure;
            });

            if (firstFailure) {
                addStatusMessage(ppChatGetStatusMessage(firstFailure.data), true);
                return;
            }

            // All previews succeeded — add Apply/Cancel buttons
            var actions = document.createElement('div');
            actions.className = 'pp-ai-proposal-actions';

            var applyBtn = document.createElement('button');
            applyBtn.className = 'button button-primary pp-ai-proposal-apply';
            applyBtn.textContent = steps.length > 1 ? 'Apply All' : 'Apply';
            applyBtn.addEventListener('click', function () {
                executeProposal(steps, stepElements, applyBtn, cancelBtn, card, pageId);
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
        }).catch(function (err) {
            // Backstop for a throw OUTSIDE the per-result helper — the status message,
            // the Apply/Cancel block, or anything a later edit adds to this `then`.
            // Parity with executeProposal()'s chain catch below, which this chain never
            // had (#663).
            //
            // THE STEPS ARE FINISHED BEFORE THE STATUS MESSAGE IS ATTEMPTED, and the
            // order is the whole point. addStatusMessage() is itself one of the things
            // that can throw INTO this catch; running it first would mean re-calling the
            // call that just failed, losing the rejection, and never reaching the loop —
            // leaving steps mid-flight, which is #663's symptom reproduced by the guard
            // meant to prevent it. The loop cannot fail the same way, because every paint
            // it performs goes through ppChatRenderPreviewResult, which absorbs its own
            // throws. So the half that always survives runs first.
            //
            // Steps are terminalized rather than repainted: by the time this fires the
            // loop has usually finished, and every step it rendered already carries the
            // right state. `pp-ai-step-executing` is precisely the set that did not get
            // one, so it is the set to finish.
            var text = ppChatPreviewRenderErrorText(err);
            stepElements.forEach(function (el, i) {
                if (el.classList.contains('pp-ai-step-executing')) {
                    ppChatRenderPreviewResult(el, diffAreas[i], steps[i], { success: false, data: text });
                }
            });
            addStatusMessage(text, true);
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

    function executeProposal(steps, stepElements, applyBtn, cancelBtn, card, pageId) {
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
        // Thread the per-page CAS baselines for every page this proposal touches
        // (#404). The server enforces coverage on the mutating steps and rejects
        // the whole batch fail-closed if a mutating page has no baseline.
        data.append('baselines', JSON.stringify(ppChatBuildBatchBaselines(steps, pageBaselines)));

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
                // A missing-baseline mandate rejection (#404), a pre-exec conflict,
                // or an unreadable-composition refusal (#749) all arrive as a
                // structured payload — show the conflict affordance rather than
                // "[object Object]". The #749 refusal deliberately takes the generic
                // error-message path instead: re-reading the page cannot fix bytes
                // nobody can decode, so offering "Re-read & re-preview" would send
                // the operator round a loop that ends where it started.
                if (ppChatIsCompositionConflict(resp.data)
                    || (resp.data && resp.data.error_code === 'missing_expected_version')) {
                    showConflictState(card, steps, pageId);
                } else {
                    addStatusMessage('Error: ' + ((resp.data && resp.data.error) || resp.data || 'Unknown error'), true);
                }
                return;
            }

            var batch = resp.data; // { ok, steps: [...], failed_at, rolled_back, versions }
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
                // Refresh per-page baselines from the post-write versions so the
                // next proposal chains off fresh state, not a stale read (#404).
                ppChatApplyVersionMap(pageBaselines, batch.versions);
                saveState();
                finalizeProposalSuccess(card, applied, steps);
                return;
            }

            // A conflict at the failed step means another writer moved the page
            // mid-proposal; the batch rolled back. Offer Re-read & re-preview, not
            // a blind retry (#404).
            if (ppChatBatchHitConflict(batch)) {
                showConflictState(card, steps, pageId);
                return;
            }

            // A non-conflict rollback (e.g. a validation error at a later step) still
            // rewrites every snapshotted page's composition, which bumps its version —
            // leaving our stored baselines stale. Re-read the baseline for each touched
            // page so the next apply doesn't false-conflict against that churn (#404).
            refreshTouchedBaselines(steps);

            if (ppChatBatchWasRefusedUpFront(batch)) {
                // No step ran, so every step is skipped, not failed. The forEach above
                // iterated an empty steps array and the skip loop below is gated on a
                // non-null failed_at, so without this the card is left with every row
                // still spinning under an error line.
                stepElements.forEach(function (el) {
                    el.classList.remove('pp-ai-step-executing');
                    el.classList.add('pp-ai-step-skipped');
                });
                addStatusMessage('Error: ' + (batch.error || 'Unknown error'), true);
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

    /**
     * Composition-conflict state (#404): the page changed while the proposal was
     * pending, so nothing was applied. Renders one message and one affordance,
     * Re-read & re-preview — re-fetch the page's fresh baseline, then re-render
     * the proposal so it previews against current state and the user confirms
     * again. Never a blind retry that re-sends the stale write with a bumped
     * version (that would turn the CAS into an auto-overwrite button).
     */
    function showConflictState(card, steps, pageId) {
        card.innerHTML = '';
        card.classList.add('pp-ai-proposal-conflict');

        var msg = document.createElement('div');
        msg.className = 'pp-ai-status pp-ai-status-error';
        msg.setAttribute('role', 'alert');
        msg.textContent = ppChatConflictMessage();
        card.appendChild(msg);

        var actions = document.createElement('div');
        actions.className = 'pp-ai-proposal-actions';

        var rereadBtn = document.createElement('button');
        rereadBtn.className = 'button button-primary';
        rereadBtn.textContent = 'Re-read & re-preview';
        rereadBtn.addEventListener('click', function () {
            rereadBtn.disabled = true;
            rereadBtn.textContent = 'Re-reading…';

            var freshSteps = steps.map(function (s) {
                return { type: s.type, name: s.name, params: s.params || {}, description: s.description };
            });

            var readTarget = Number(pageId);
            var reader = isNaN(readTarget)
                ? Promise.resolve()
                : refreshBaseline(readTarget);

            reader.then(function () {
                card.remove();
                // Re-render the proposal fresh: previews every step against current
                // state and shows Apply again, now backed by the refreshed baseline.
                renderProposal({ proposal: true, steps: freshSteps }, pageId);
            }).catch(function () {
                rereadBtn.disabled = false;
                rereadBtn.textContent = 'Re-read & re-preview';
                addStatusMessage('Could not re-read the page. Try again.', true);
            });
        });
        actions.appendChild(rereadBtn);
        card.appendChild(actions);
        messagesEl.scrollTop = messagesEl.scrollHeight;
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

        // Undo these changes link — composition mutations (#133). Parity with the token
        // "Reset to default" link: walks the page's history ring back to the state before
        // this proposal's composition writes via the restore_composition action.
        var undoTarget = ppChatCompositionUndoTarget(steps);
        if (undoTarget) {
            var undoLink = document.createElement('a');
            undoLink.href = '#';
            undoLink.textContent = 'Undo these changes';
            undoLink.style.marginLeft = hasLinks ? '16px' : '0';
            undoLink.addEventListener('click', function (e) {
                e.preventDefault();
                undoLink.textContent = 'Undoing…';
                undoLink.style.pointerEvents = 'none';

                var undoData = new FormData();
                undoData.append('action', 'pp_ai_execute');
                undoData.append('nonce', config.executeNonce);
                undoData.append('type', 'action');
                undoData.append('name', 'restore_composition');
                undoData.append('params[post_id]', undoTarget.postId);
                undoData.append('params[steps_back]', undoTarget.stepsBack);
                // restore_composition is composition-mutating, so the server now
                // requires a CAS baseline (#404). Thread the page's current stored
                // baseline; an external write since apply makes this conflict
                // rather than blindly clobber.
                var undoBaseline = pageBaselines[Number(undoTarget.postId)];
                if (typeof undoBaseline === 'number') {
                    undoData.append('params[expected_version]', undoBaseline);
                }

                fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: undoData
                })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (resp.success) {
                        undoLink.textContent = 'Changes undone ✓';
                        // Refresh the baseline from the post-write version (#404).
                        if (resp.data && typeof resp.data.composition_version === 'number') {
                            pageBaselines[Number(undoTarget.postId)] = resp.data.composition_version;
                            saveState();
                        }
                        ppChatAppendUndoFindings(card, (resp.data && resp.data.findings) || []);
                    } else if (ppChatIsCompositionConflict(resp.data)) {
                        undoLink.textContent = 'Page changed — undo not applied';
                        undoLink.className = 'pp-ai-link-error';
                    } else {
                        undoLink.textContent = 'Undo failed';
                        undoLink.className = 'pp-ai-link-error';
                    }
                })
                .catch(function () {
                    undoLink.textContent = 'Undo failed';
                    undoLink.className = 'pp-ai-link-error';
                });
            });
            linksDiv.appendChild(undoLink);
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

                            // Store the CAS baseline the server captured for the page the
                            // model read (#404) — this is the version any proposal from this
                            // turn was reasoned against, threaded back on write.
                            if (data.done) {
                                storePageBaseline(data.page_baseline);
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

                // Store the CAS baseline the fallback captured (#404), same as the SSE path.
                storePageBaseline(resp.data.page_baseline);

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

        // Restore per-page CAS baselines (#404). Coerce keys to Number so they
        // match the numeric activePageId invariant; drop anything non-numeric.
        if (state.pageBaselines && typeof state.pageBaselines === 'object') {
            pageBaselines = {};
            Object.keys(state.pageBaselines).forEach(function (pid) {
                var key = Number(pid);
                var v = state.pageBaselines[pid];
                if (!isNaN(key) && typeof v === 'number' && v >= 0) {
                    pageBaselines[key] = v;
                }
            });
        }

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
        pageBaselines = {};
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
        compositionUndoTarget: ppChatCompositionUndoTarget,
        renderPreviewError: ppChatRenderPreviewError,
        renderPreviewResult: ppChatRenderPreviewResult,
        previewRenderErrorText: ppChatPreviewRenderErrorText,
        getErrorStepClass: ppChatGetErrorStepClass,
        getStatusMessage: ppChatGetStatusMessage,
        appendValidationItems: ppChatAppendValidationItems,
        appendUndoFindings: ppChatAppendUndoFindings,
        findingClass: ppChatFindingClass,
        findingBand: ppChatFindingBand,
        findingLocator: ppChatFindingLocator,
        undoFindingsTail: ppChatUndoFindingsTail,
        buildCompositionSummary: ppChatBuildCompositionSummary,
        detectPageId: ppChatDetectPageId,
        findPageById: ppChatFindPageById,
        shouldSuggestPageSwitch: ppChatShouldSuggestPageSwitch,
        buildBatchBaselines: ppChatBuildBatchBaselines,
        applyVersionMap: ppChatApplyVersionMap,
        isCompositionConflict: ppChatIsCompositionConflict,
        batchHitConflict: ppChatBatchHitConflict,
        batchWasRefusedUpFront: ppChatBatchWasRefusedUpFront,
        conflictMessage: ppChatConflictMessage
    };
}
