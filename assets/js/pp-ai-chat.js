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
 * True when a failed batch has NO failing step to report (#749).
 *
 * The executor refuses a whole proposal before step 1 when a page it names has a
 * stored composition it cannot read — a rollback would have to write a degraded
 * stand-in over the only recoverable copy of those bytes. That refusal returns
 * `failed_at: null` with `steps: []`, the one ok:false shape with no step index,
 * and it carries the explanation on the batch itself as `error`.
 *
 * ONE PROPOSAL SHAPE IS EXEMPT (#756, ruling D-1): a proposal whose ONLY step is
 * `update_composition` or `restore_composition` on a page already classified
 * corrupt is the repair the refusal itself prescribes, so it runs. Nothing here
 * changes for it — it returns an ordinary one-step envelope on both branches.
 *
 * The failure renderer indexes `steps[failed_at]`, so without this guard that
 * shape used to throw a TypeError and the user saw a stack-shaped string instead
 * of the reason. SINCE #853 THAT READ IS NULL-SAFE, WHICH MAKES DELETING THIS
 * GUARD WORSE RATHER THAN SAFER: no throw is left to make the mistake visible, so
 * the failure exit would quietly index `steps[null]`, find nothing, and print a
 * fabricated "Error on step 1: Unknown error" (`null + 1` is 1) over a refusal
 * that names its own reason perfectly well. A crash at least got reported.
 * The chat handler normally answers this case on the !resp.success
 * branch, so reaching here means the two gates disagreed across a concurrent
 * write: the page went unreadable after the handler's check, or — since #756 —
 * a competing repair made it READABLE after the handler admitted the carve-out,
 * closing the exemption before the executor re-checked it. Narrow either way,
 * and a null index is not how anyone should find out.
 */
function ppChatBatchWasRefusedUpFront(batch) {
    if (!batch || batch.ok) return false;
    return batch.failed_at === null || batch.failed_at === undefined;
}

/**
 * True when a completed batch failed on a composition_conflict at its failed
 * step (the batch response is a success envelope carrying a per-step failure).
 *
 * DELIBERATELY THE WEAKEST READ OF `steps` IN THIS FILE, and it must stay that way (#853).
 * It asks one index a yes/no question about the CAUSE; it never walks the list and never
 * makes a per-step claim, so it does not need — and must not acquire —
 * ppChatBatchStepsReadable(). Handing it that predicate would make an object-shaped `steps`
 * classify as "not a conflict", which sends a conflicting batch to the executed-failure exit
 * and re-masks the very card #853 unmasked. That is the one "tidy-up" this cluster cannot
 * survive, so it is written down rather than left to be rediscovered.
 */
function ppChatBatchHitConflict(batch) {
    if (!batch || batch.ok || batch.failed_at === null || batch.failed_at === undefined) return false;
    var failed = batch.steps && batch.steps[batch.failed_at];
    return !!(failed && failed.error_code === 'composition_conflict');
}

/**
 * Whether a batch envelope carries PER-STEP TRUTH that can be walked (#853).
 *
 * ONE PREDICATE FOR EVERY READER THAT MAKES A PER-STEP CLAIM, which is #667's landed rule
 * applied to `steps`: a renderer that re-derives a weaker test than the classifier beside it
 * is how one card comes to say two opposite things. `ppChatConflictOutcome()` already asked
 * exactly this question inline; executeProposal() did not ask it at all. Both now call this.
 *
 * THE THIRD READER IS EXEMPT ON PURPOSE, and the exemption is the interesting part.
 * `ppChatBatchHitConflict()` keeps its weaker `batch.steps && batch.steps[batch.failed_at]`
 * because it asks about the CAUSE, not about the steps: one index, one `error_code`, no
 * walk. Give it this predicate and an object-shaped `steps` stops classifying as a conflict,
 * which routes a conflicting batch away from the card #853 exists to make reachable. So the
 * rule is not "every read of `steps` goes through here" — it is "every CLAIM about the steps
 * does", and a classifier that only needs one member is allowed the narrower question.
 *
 * `Array.isArray` is the whole test, deliberately the same one `ppChatRollbackErrorReport()`
 * applies to the channel beside it. `$results` in pp_ai_execute_batch() (lib/actions.php) is
 * a JSON list only because it is built with `$results[] =`; any key-preserving edit upstream
 * — an `array_filter`, an `array_unique`, an `unset` — makes wp_json_encode emit a JSON
 * OBJECT instead, and nothing on either side asserts list-ness. That is the same one-edit-away
 * hazard #755 documented for `rollback_errors`, sitting on the field that decides which
 * failure exit runs.
 *
 * WHAT AN UNREADABLE `steps` COSTS, stated so callers do not over-read a `false`. It is not
 * a verdict that the batch failed, and it is not a verdict that nothing ran. It says only
 * that NO PER-STEP CLAIM CAN BE MADE: not "this step succeeded", not "these never ran", not
 * "here is the failing step's reason". Everything an envelope says OUTSIDE `steps` —
 * `error`, `failed_at`, `rolled_back`, `rollback_errors` — is untouched by this and stays
 * readable, which is why the failure exits still render on an envelope this rejects.
 *
 * The null guard is not decoration: a caller reaching this with an absent `resp.data` gets
 * `false` rather than a TypeError, which is the whole posture of the guard it feeds.
 *
 * OWN PROPERTY, for the reason ppChatConflictOutcome() already spells out one screen down:
 * wp-admin loads third-party JS in this realm, so `Object.prototype.steps` is reachable, and
 * an inherited one is not evidence about this envelope. Without the check, an envelope with
 * NO `steps` key inherits a planted list, tests as readable, and walks rows the server never
 * sent — straight past the refusal below, into a success card and an `[Applied changes: ...]`
 * turn written to the model. That is the exact false assertion this guard exists to prevent,
 * reached by going around it. Nothing produces a step-less success envelope today (every
 * return in pp_ai_execute_batch() sets `steps`), so this is defence in depth against the same
 * upstream drift the rest of this docblock is about, in the same idiom the file already uses.
 *
 * @param  {object|null} batch  The batch envelope.
 * @return {boolean}            True when `steps` is the envelope's own, and a real list.
 */
function ppChatBatchStepsReadable(batch) {
    return !!batch
        && Object.prototype.hasOwnProperty.call(batch, 'steps')
        && Array.isArray(batch.steps);
}

/**
 * Every step row given the one state that claims nothing about which step did what (#853).
 *
 * Extracted rather than copied a third and fourth time: this exact three-line block already
 * stood at the two exits that answer "we cannot tell what happened" — the !resp.success
 * branch and the promise chain's catch — and #853 adds two more sites with the same need.
 * Four literal copies of a row-state rule is how one of them gets a class renamed alone.
 *
 * WHY `pp-ai-step-failed` AND NOT A NEW STATE. The rows have to leave `pp-ai-step-executing`
 * or the card spins forever under an error line, which is the defect ppChatBatchWasRefusedUpFront()'s
 * exit already carries a comment about. Of the four states this file paints, `skipped` claims
 * the step never ran and `done` claims it succeeded; both are claims this caller cannot
 * support. `failed` is what the catch has always painted when the outcome is unknown, and
 * reusing it coins no new vocabulary for a distinction the chat cannot yet convey anyway
 * (#664).
 *
 * @param {Array} stepElements  The card's rendered step rows.
 */
function ppChatMarkStepsFailed(stepElements) {
    stepElements.forEach(function (el) {
        el.classList.remove('pp-ai-step-executing');
        el.classList.add('pp-ai-step-failed');
    });
}

/**
 * The rows the envelope never accounted for, finished rather than repainted (#853).
 *
 * TERMINALIZE, DO NOT REPAINT, which is the rule the preview chain's catch already states
 * for the same problem: `pp-ai-step-executing` is precisely the set that did not get an
 * answer, so it is the set to finish, and a row that DID get one keeps it. That is the whole
 * difference from ppChatMarkStepsFailed() above, which overwrites every row because its
 * callers know nothing about any of them.
 *
 * TWO WAYS A ROW ENDS UP UNANSWERED at the executed-failure exit, and the second is why this
 * exists as a sweep rather than an `if`:
 *
 *   `steps` unreadable          nothing was painted at all, so every row is unanswered.
 *   `steps` readable but SHORT  the loop painted `steps.length` rows and the skip pass starts
 *                               at `failed_at + 1`, so a list shorter than `failed_at` leaves
 *                               the rows BETWEEN them untouched. `steps: [{ok:true}]` with
 *                               `failed_at: 1` is the smallest case: row 0 done, row 1
 *                               spinning forever under "Error on step 2".
 *
 * The short-list case is not hypothetical bookkeeping — it is what the pre-#853 code got
 * right BY ACCIDENT. `steps[failed_at]` was undefined there, `.error` threw, and the chain's
 * catch swept every row on its way past. Making that read null-safe removed the crash and
 * the cleanup with it, so the sweep has to be asked for on purpose now.
 *
 * @param {Array} stepElements  The card's rendered step rows.
 */
function ppChatFinishSpinningSteps(stepElements) {
    stepElements.forEach(function (el) {
        if (!el.classList.contains('pp-ai-step-executing')) return;
        el.classList.remove('pp-ai-step-executing');
        el.classList.add('pp-ai-step-failed');
    });
}

/**
 * The half of the conflict message that is true on every path that reaches it (#797).
 *
 * Byte-identical to the opening of the sentence this file has always shown. Split out
 * because the CAUSE is one fact and what the batch LEFT BEHIND is another, and only the
 * second one depends on evidence.
 */
var PP_CHAT_CONFLICT_CAUSE = 'This page changed while the proposal was pending (another tab, agent, or editor).';

/**
 * The two claims the conflict card may close with, one owner each (#797).
 *
 * Named rather than returned inline because ppChatConflictOutcome() reaches the clean claim
 * down two different arms — nothing ran, and everything ran and came back — and two literal
 * copies of the strongest sentence in this file is how one of them gets reworded alone.
 * The clean claim's text is byte-identical to the one this card has always shown.
 *
 * The other is the sentence form of ppChatRollbackSentence()'s clause (#755), deliberately
 * the same words: the transcript line and this card describe one fact, and a second dialect
 * for it is how a reader learns to distrust both. It stands alone as a sentence because the
 * entries it refers to render in a separate element below, not after a colon.
 */
var PP_CHAT_CONFLICT_NOTHING_APPLIED = ' Nothing was applied.';
var PP_CHAT_CONFLICT_NOT_ALL_REVERTED = ' Some changes could not be reverted.';

/**
 * What a conflicting batch left behind, as the clause the conflict card ends with (#797).
 *
 * "Nothing was applied." IS THE STRONGEST CLAIM THIS FILE MAKES, and it used to be
 * unconditional. Two different failures route here (executeProposal), and only one of them
 * had earned it:
 *
 *   !resp.success, error_code composition_conflict | missing_expected_version
 *       The CAS gate refused the batch before step 1. Nothing ran. The claim is true, and
 *       the payload is an error envelope with no `steps` (_pp_ai_execute_error_payload and
 *       the baseline mandate, lib/ai-chat.php).
 *
 *   ppChatBatchHitConflict(batch)
 *       A step FAILED on a conflict, so earlier steps in that batch executed and were then
 *       rolled back. The executor does not special-case a conflicting step — it reaches the
 *       same failure return, runs the same _pp_restore_batch_snapshot(), and ships the same
 *       `rollback_errors` (pp_ai_execute_batch, lib/actions.php). When that channel carries
 *       entries, a page is still holding mid-batch state and "Nothing was applied." is the
 *       #755 lie in stronger words.
 *
 * SAME RULE AS #755, APPLIED TO THIS EXIT. The clean claim needs the report to be
 * explicitly clean — present, a list, and empty — plus a rollback that actually happened.
 * An absent or non-list channel is an UNKNOWN and says nothing rather than saying "clean":
 * `$errors` in _pp_restore_batch_snapshot() is a JSON list only because it is built with
 * `$errors[] =` and `array_merge`, and any key-preserving edit upstream makes
 * wp_json_encode emit an OBJECT. Failing open there is how the fixed bug walks back in.
 *
 * HOW STRONG THE CLEAN CLAIM ACTUALLY IS, stated so the gating above is not read as more
 * than it is. `rollback_errors` reports what the ROLLBACK could not restore, so the claim
 * is only ever as strong as the rollback's own COVERAGE — and that used to be the weaker
 * half by a wide margin. `_pp_snapshot_batch_targets()` captured options only for
 * `update_site_option` (lib/actions.php) and deliberately skipped `import_media`'s
 * attachment, so a step writing site state outside that capture — a redirect, an imported
 * attachment — was neither rolled back nor reported. An empty channel was silent about it,
 * which made "Nothing was applied." exactly as strong as the snapshot's coverage and no
 * stronger: an unverified claim wearing the gating above as if it were verified.
 *
 * SINCE #854 THE COVERAGE MATCHES THE CLAIM. The rollback deletes what its own batch
 * created — the redirect row, the imported attachment — restores what the batch merely
 * overwrote, and where it cannot do either it NAMES the survivor on this channel, which
 * reaches the operator through the arm above without a single change here. So an empty
 * report now means nothing survived, rather than meaning nothing was looked at. Since #857
 * it also means nothing FAILED silently: every write that rollback performs checks its own
 * return, where they were all fire-and-forget before, so a restore that could not put a
 * value back now names it here instead of reporting clean. What this function guarantees is
 * unchanged and was always worth having on its own: the claim is never made OVER a report
 * that contradicts it.
 *
 * WHY `rolled_back` IS ALSO REQUIRED, and it is an extra condition rather than a softening:
 * `rollback_errors: []` says the rollback reported no errors; it does not say a rollback
 * HAPPENED. Steps that ran and were never reverted are the one shape where "Nothing was
 * applied." would be false with a clean report. Today's executor sets rolled_back: true on
 * every failure return that carries steps, so the pair always agrees and this costs
 * nothing. This mirrors _pp_ai_rollback_clause() (lib/ai-chat.php), which answers the same
 * question for the model, so the two participants cannot be told different stories.
 *
 * A REPORTED ENTRY OUTRANKS EVERY OTHER ARM, which is #755's ordering applied here rather
 * than a new one. The obvious spelling asks "did anything run?" first and only consults the
 * channel inside the executed arm — and that re-opens the bug for the payload that matters
 * most, because "did anything run?" is answered by a field that can be malformed while the
 * channel is intact. A payload carrying entries is evidence that something ran and did not
 * come back, whatever the rest of it says, so it can never reach the clean claim.
 *
 * THE NOTHING-RAN ARM ASKS THE SERVER'S OWN QUESTION, and asks it in three parts because
 * `steps` is exactly as forgeable as the channel beside it. `empty($batch['steps'])` is how
 * lib/ai-chat.php discriminates a refusal from an executed failure, twice, so the shape is
 * borrowed rather than coined — but PRESENT-AND-UNRECOGNIZABLE is a third state that PHP's
 * `empty()` folds into the refusal and a client must not:
 *
 *   no `steps` key at all   ->  a pre-execution refusal. Every envelope
 *                               pp_ai_execute_batch() returns sets `steps`; the two error
 *                               payloads routed here (composition_conflict,
 *                               missing_expected_version — lib/ai-chat.php) carry none.
 *                               Nothing ran, and the claim is true.
 *   present, not a list     ->  UNKNOWN, and no claim. `$results` is a JSON list only
 *                               because it is built with `$results[] =` (lib/actions.php);
 *                               one key-preserving edit upstream makes wp_json_encode emit
 *                               an OBJECT, and reading that as "no steps" would hand the
 *                               STRONGEST sentence to a batch that ran. Same hazard the
 *                               channel beside it already fails closed on, same answer.
 *                               LIVE SINCE #853, and worth saying because this arm shipped
 *                               unreachable: executeProposal() used to run
 *                               `batch.steps.forEach` before it ever asked whether the batch
 *                               conflicted, so a non-list `steps` threw THERE and the
 *                               operator got a stack-shaped string instead of this card.
 *                               The guard that landed there does not iterate what it cannot
 *                               read, so a conflicting batch with an object-shaped `steps`
 *                               now reaches this card and ends on the cause with no claim.
 *   present, empty list     ->  the #749 refusal's own spelling of "no step ran".
 *
 * FAIL-CLOSED ON A MISSING ARGUMENT, which is the whole reason the payload is threaded in
 * rather than defaulted. A caller that forgets it passes `undefined`, which is not an
 * object, so it gets the cause and no claim — the operator loses a true sentence instead of
 * being handed a false one. Both call sites are pinned; the guard is what makes a third one
 * safe.
 *
 * @param  {object|null} payload  The batch envelope, or the pre-execution error payload.
 * @param  {object}      report   ppChatRollbackErrorReport()'s answer for that payload.
 * @return {string}               '' or a leading-space clause completing the message.
 */
function ppChatConflictOutcome(payload, report) {
    if (!payload || typeof payload !== 'object') return '';

    // Evidence first. `reported` is only ever above zero when the channel was a readable
    // list, so this arm already implies everything the readability test below asks.
    if (report && report.reported > 0) {
        return PP_CHAT_CONFLICT_NOT_ALL_REVERTED;
    }

    // OWN PROPERTY, not `in`: wp-admin loads third-party JS in this realm, and a polluted
    // `Object.prototype.steps` would make every pre-execution refusal answer this test yes
    // and lose its true claim. The direction is safe, but silently disabling an arm is not
    // how it should be found. Same idiom the rest of this file uses.
    if (!Object.prototype.hasOwnProperty.call(payload, 'steps')) return PP_CHAT_CONFLICT_NOTHING_APPLIED;
    // The shared predicate, not a second literal copy of it (#853/#667): executeProposal()'s
    // guard and this arm must answer the same question about the same field, or the card can
    // be reached by an envelope this function then reads differently.
    if (!ppChatBatchStepsReadable(payload)) return '';
    if (payload.steps.length === 0) return PP_CHAT_CONFLICT_NOTHING_APPLIED;

    if (!report || !report.readable) return '';

    // `rolledBack` is the report's already-coerced answer (#755), not a second read of the
    // envelope: asking `batch.rolled_back` again here would be the two-answers-one-question
    // shape the one-report rule exists to prevent.
    return report.rolledBack ? PP_CHAT_CONFLICT_NOTHING_APPLIED : '';
}

/**
 * The single user-facing conflict message. One message, one affordance
 * (Re-read & re-preview) — never a blind retry that re-sends the stale write.
 *
 * The affordance is unchanged by #797 on purpose: naming what stayed dirty is additive
 * truth, not a redesign of what the operator may do next. Whether Re-read & re-preview is
 * the right offer when a page could not be rolled back is a repair-route question, and it
 * travels with #756 / #767. Note what that leaves open, since this card is now the only
 * place the report exists: spending the affordance removes the card and the report with it
 * (#856).
 *
 * THE TWO ARGUMENTS ARE ONE FACT, NOT TWO, and passing a mismatched pair is the one misuse
 * that fails quietly: `report` must be ppChatRollbackErrorReport(payload) for the SAME
 * payload. That is the one-report rule showConflictState() enforces by computing it there
 * and handing it to both surfaces. Omitting it (`ppChatConflictMessage(batch)`) is not an
 * error either — it withholds the closing claim, so the card ends after the cause with
 * nothing anywhere saying why. Fail-closed by design, silent by consequence; the call site
 * is pinned so the silence is not how anyone finds out.
 *
 * @param  {object|null} payload  The batch envelope, or the pre-execution error payload.
 *                                Never optional: `null` is the VALUE meaning "no evidence",
 *                                and an omitted argument reads the same way on purpose.
 * @param  {object|null} report   ppChatRollbackErrorReport()'s answer for THAT payload.
 * @return {string}               The whole message: cause, plus a closing claim when the
 *                                evidence supports one.
 */
function ppChatConflictMessage(payload, report) {
    return PP_CHAT_CONFLICT_CAUSE + ppChatConflictOutcome(payload, report);
}

/**
 * Most rollback_errors entries this card will DRAW (#755).
 *
 * Chosen to match the value PP_WRITE_FINDINGS_BUDGET carries today (lib/actions.php) —
 * the number the server already treats as "a report a person can still read" — so this
 * surface does not invent a second answer to a question the repo has already answered.
 * That is a borrowed rationale, NOT a synchronized constant: nothing enforces the two
 * staying equal, and retuning the server budget has no automatic effect here.
 *
 * It lives on the client because `rollback_errors` is the one report channel with NO
 * server cap: the menu layer appends one entry per item it could not recreate
 * (_pp_restore_batch_snapshot -> _pp_restore_menu_state, lib/actions.php), so a
 * catastrophic menu restore produces one string per menu item and nothing upstream trims
 * it.
 *
 * It bounds the DOM, not the count: the heading names how many the server reported
 * whenever it draws fewer, so an operator is told the list is partial instead of quietly
 * shown a prefix. Be honest about what that costs, because this differs from the bounded
 * report it is modelled on. A truncated FINDINGS report ends with a tail naming
 * `wp pp check page --post_id=N`, a real route to the rest. This envelope is
 * request-scoped and returned exactly once, and the server persists nothing — so entries
 * past the budget are gone for good, not merely collapsed. Accepted, because a
 * 20,000-row card is not a readable report either; the count is what keeps it honest.
 */
var PP_CHAT_ROLLBACK_ERRORS_MAX = 100;

/**
 * The one rollback kind this file draws differently (#855).
 *
 * MATCHES `PP_ROLLBACK_ERROR_WITHHELD` (lib/actions.php), and the pairing is prose rather
 * than a shared constant because the two files do not share a module system. That is why
 * the DEFAULT sits on the other side: everything this token does not match — the `failed`
 * kind, a kind a newer server invented, an absent list, a list of the wrong length — draws
 * the way the card has always drawn. A typo here degrades to "no withhold was recognized",
 * never to "a failure was recognized as a withhold", which is the direction that would
 * quietly soften a real failed revert.
 */
var PP_CHAT_ROLLBACK_KIND_WITHHELD = 'withheld';

/**
 * The row class for one adapted rollback entry (#855).
 *
 * DELIBERATELY NOT ppChatFindingClass(), which is defined about a thousand lines below,
 * beside its own consumer, and answers the opposite way. That function serves the restore
 * findings (#622), where an item with no recognizable severity is advisory, so it degrades
 * to the WARNING class. Here the unrecognized case is an unknown kind — an older envelope,
 * a newer server, a misaligned list — and Ruling T2 says an unknown kind renders as it does
 * today, which is the FAILURE class. Same shape, inverted default, and merging them would
 * make one of the two wrong. The reciprocal warning lives on ppChatFindingClass().
 */
function ppChatRollbackRowClass(item) {
    return (item && item.severity === 'warning') ? 'pp-ai-step-warning' : 'pp-ai-step-failed';
}

/**
 * What a failed batch's rollback reported: `{ shown, total, reported, readable, rolledBack }`
 * (#755).
 *
 * `rollback_errors` is documented on pp_ai_execute_batch()'s envelope (lib/actions.php)
 * as: non-empty when the rollback itself could not fully restore something, and "a
 * consumer must not treat rolled_back: true as clean without checking it". Until this
 * function existed no consumer in assets/js/ checked it at all, so the chat told the
 * operator every change had been reverted while a page sat there still carrying
 * mid-batch state.
 *
 * ONE PASS, AND ONE ANSWER FOR BOTH SURFACES. The transcript sentence and the card
 * section are two renderings of the same fact, so they read one computed report rather
 * than each deriving their own. That is what makes "the card cannot contradict the
 * sentence" a property of the code instead of a promise in a comment — and it means the
 * channel is walked once per failed batch, not once per surface.
 *
 * THREE NUMBERS, AND THE DIFFERENCE BETWEEN THEM IS LOAD-BEARING:
 *
 *   reported  how many members the server sent, renderable or not. The SENTENCE keys on
 *             this, so "the rollback reported something" and "we can draw something" are
 *             never confused.
 *   total     how many of those are renderable strings. The heading's count.
 *   shown     the first PP_CHAT_ROLLBACK_ERRORS_MAX of them. The rows.
 *
 * AND SINCE #855, TWO THINGS ABOUT WHAT THE ENTRIES MEAN — never about how many there are:
 *
 *   kinds     one token per `shown` entry, at the same index, read from the server's
 *             index-aligned `rollback_error_kinds`. '' where the server said nothing this
 *             file recognizes. Drives the ROW treatment.
 *   withheld  how many of the REPORTED entries are renderable and carry the withheld kind.
 *             Drives the HEADING treatment, and nothing else.
 *
 * NEITHER TOUCHES THE COUNTS, and that separation is the whole compatibility argument.
 * `reported`, `total` and `shown` are computed exactly as before, so ppChatRollbackSentence,
 * ppChatConflictOutcome and the #856 survival branch cannot change their answers no matter
 * what the kinds say. A withheld entry still costs the clean claim: the rollback was not
 * clean — bytes were left somewhere the operator must know about — and the kind only says
 * whether leaving them was the deliberate, protective thing to do.
 *
 * WHY `reported` AND NOT JUST `total`. Key the sentence on what is renderable and a
 * channel whose members are all unrenderable — `[{...}]`, `['']` — collapses to "nothing
 * was reported" and draws the byte-exact CLEAN sentence. That is precisely the #755 lie,
 * re-entered through the filter meant to make rendering safe: the server said something
 * went wrong and the operator is told everything reverted. Today's producers only ever
 * append strings (lib/actions.php), so this is unreachable in practice — the same
 * standing as the falsy-`rolled_back` case below, and it gets the same answer rather than
 * the opposite one. An unrenderable report still costs the clean claim; it just cannot
 * fill the card.
 *
 * DEFENSIVE BY DESIGN, and the reason is structural rather than paranoid. The caller runs
 * inside executeProposal()'s promise chain, whose catch renders `err.message` straight
 * into the transcript — so a throw here would replace the honest report with a
 * stack-shaped string, i.e. lose exactly the information this exists to deliver. A
 * non-array (an older payload, a key that arrived as a string) degrades to "nothing to
 * report" rather than to an exception, and non-string members are dropped rather than
 * String()-ed into '[object Object]'.
 */
function ppChatRollbackErrorReport(batch) {
    var report = {
        shown: [],
        kinds: [],
        withheld: 0,
        total: 0,
        reported: 0,
        readable: false,
        rolledBack: !!(batch && batch.rolled_back)
    };

    var raw = batch && batch.rollback_errors;
    if (!Array.isArray(raw)) return report;

    // THE KINDS ARE READ AS DEFENSIVELY AS THE MESSAGES, and for the same structural
    // reason: this runs inside executeProposal()'s promise chain, whose catch renders
    // `err.message` into the transcript, so a throw here would replace the honest report
    // with a stack-shaped string. A missing key, a string, an object — anything that is not
    // an array — becomes the empty list, and every kind lookup below then answers undefined,
    // which is the unknown case, which is today's rendering.
    //
    // AND THE LENGTHS MUST MATCH, OR THE WHOLE LIST IS DISCARDED. This is the strictest
    // reading of "index-aligned" and it is deliberate. A list of the wrong length means the
    // server's alignment contract is broken; the entries it DOES cover are then covered on
    // an assumption nothing has verified, and the cost of being wrong points one way — a
    // real failed revert drawn as a harmless protection. Today's executor cannot produce a
    // mismatch (both keys are projections of one traversal, lib/actions.php), so failing
    // closed costs nothing against real data and removes the whole class.
    var rawKinds = (batch && Array.isArray(batch.rollback_error_kinds)
        && batch.rollback_error_kinds.length === raw.length)
        ? batch.rollback_error_kinds
        : [];

    report.readable = true;
    report.reported = raw.length;

    for (var i = 0; i < raw.length; i++) {
        if (!ppChatIsNonEmptyString(raw[i])) continue;
        report.total++;
        // PAIRED AT THE ORIGINAL INDEX, BEFORE ANY FILTERING OR SLICING. The server aligns
        // kind i with message i; `shown` drops unrenderable members and stops at the budget,
        // so a kind list built by walking `shown` afterwards would be shifted by every drop —
        // and a shifted kind does not fail loudly, it relabels a failed revert as a withhold.
        // Read here, while `i` still means what the server meant by it.
        var kind = ppChatIsNonEmptyString(rawKinds[i]) ? rawKinds[i] : '';
        // COUNTED OVER EVERY REPORTED ENTRY, NOT OVER THE DRAWN ONES, because its only
        // consumer asks a question about the whole report: is there anything here that is
        // NOT a known withhold? A count taken over `shown` would answer that from the first
        // hundred rows and miss a genuine failure sitting at row 101, painting the heading
        // amber over a report that contains one. Unrenderable members never reach this line,
        // so they can never be counted as withholds — which is what makes the strict
        // `withheld === reported` test below fail closed on them too.
        if (kind === PP_CHAT_ROLLBACK_KIND_WITHHELD) {
            report.withheld++;
        }
        if (report.shown.length < PP_CHAT_ROLLBACK_ERRORS_MAX) {
            report.shown.push(raw[i]);
            report.kinds.push(kind);
        }
    }

    return report;
}

/**
 * The clause the batch-failure line ends with, after "Error on step N: <reason>" (#755).
 *
 * Three answers, and which one you get is decided by the rollback REPORT, not by the
 * rollback FLAG:
 *
 *   channel unreadable  ->  ''
 *   reported > 0        ->  ' — some changes could not be reverted.'
 *   else rolledBack     ->  ' — all changes in this proposal have been reverted.'
 *   else                ->  ''
 *
 * THE REPORT OUTRANKS THE FLAG ON PURPOSE. The obvious spelling — test `rolled_back`
 * first and only branch inside it — reads more natural and re-opens the bug for the one
 * payload that matters most: a shape carrying entries with a falsy `rolled_back` would
 * print nothing about them at all. Today's executor cannot produce that pair (the only
 * return that fills rollback_errors also sets rolled_back: true; the other two hardcode
 * an empty list — lib/actions.php), so the ordering costs nothing against real data and
 * makes the clean sentence UNREACHABLE whenever the channel says otherwise. That
 * unreachability is the property worth having, and it is pinned.
 *
 * AN UNREADABLE CHANNEL SAYS NOTHING, RATHER THAN SAYING "CLEAN". `Array.isArray` is the
 * whole test for readability, and failing it used to fall through to the flag — so an
 * unrecognized shape did not degrade to "nothing to report", it degraded to an
 * affirmative all-clear, which is the precise class of false reassurance this issue
 * exists to remove. It is one upstream line from being live: `$errors` in
 * `_pp_restore_batch_snapshot()` is a LIST only because it is built with `$errors[] =`
 * and `array_merge` (lib/actions.php); any key-preserving edit there — `array_filter`,
 * `array_unique`, an `unset` — makes `wp_json_encode` emit a JSON OBJECT instead, and
 * nothing on either side asserts list-ness. So the clean sentence now requires an
 * explicitly EMPTY ARRAY: present, readable, and empty. Anything else is either a report
 * or an unknown, and neither may be narrated as a clean revert.
 *
 * The clean sentence is byte-identical to the one this file has always shown. The failing
 * case is deliberately a complete sentence rather than a colon introducing the entries:
 * the entries render in the proposal card, a different element from the transcript line
 * this clause belongs to, and a line ending in ':' with nothing after it inside its own
 * container reads as truncated. Both halves therefore stand alone.
 *
 * SCOPE, so the next reader does not over-read this. This clause belongs to the
 * executed-failure exit only. The other two answer the same question in their own words and
 * are now held to the same rule (#797): `showConflictState()` ends its message with
 * ppChatConflictOutcome(), which withholds "Nothing was applied." unless the report is
 * explicitly clean; the #749 up-front refusal asserts no revert at all, because no step ran.
 * No exit reaches an affirmative all-clear without the channel saying so.
 */
function ppChatRollbackSentence(report) {
    if (!report || !report.readable) return '';

    if (report.reported > 0) {
        return ' — some changes could not be reverted.';
    }

    return report.rolledBack ? ' — all changes in this proposal have been reverted.' : '';
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
 * True when a change's `from` side is the server's unreadable-composition marker (#836).
 *
 * The server sends this instead of `[]` for the before side of a whole-composition write
 * on a page whose stored composition will not decode (`_pp_composition_before_state()`,
 * lib/actions.php). The distinction it protects is the one the whole #725/#748/#750 family
 * exists for: a genuinely blank page still sends `[]`, because `[]` is its truth, and this
 * predicate must never claim one is the other.
 *
 * WHAT IT CHECKS, and each clause earns its place:
 *
 *   an object          — the marker is one; a list never is
 *   NOT an array       — load-bearing, not pedantry. `typeof [] === 'object'` in JS, and a
 *                        JSON payload can carry an array with named properties. Without
 *                        this a list wearing an `unreadable` property would be routed to
 *                        the corruption branch and the operator would lose a real diff.
 *   unreadable === true — strict, so the string "true" or 1 does not qualify
 *   a non-empty string `message` — the renderers PRINT this field. A marker without it
 *                        would paint an empty line where the diagnosis belongs, which is a
 *                        quieter version of the bug being fixed, so a payload that cannot
 *                        say what is wrong is not treated as a marker at all and takes the
 *                        ordinary path instead.
 *   a non-empty string `classification` — the same argument, one field over.
 *                        ppChatRenderDiffLine() prints this one into its short label, and
 *                        an absent key there paints the literal text "unreadable
 *                        (undefined)" on the approval gate.
 *
 * REQUIRING a classification is NOT the same as ENUMERATING one, and the distinction is
 * the whole reason the clause is written this way. Which classifications exist is
 * `pp_classify_composition_value()`'s to say (lib/wp.php) and it is the single owner of
 * that decision (#144/#767); listing today's two nouns here would put a second copy of the
 * classifier's vocabulary in a file that cannot be edited in the same breath as the
 * classifier, so a third noun added there would render as a raw JSON blob instead of a
 * corruption notice. Demanding that SOME noun is present costs none of that.
 *
 * BOTH DIRECTIONS MATTER, and only one of them is about lists. "The marker is never
 * mistaken for a composition" is free — an `ok` classification is always a JSON list
 * (pp_classify_composition_value only returns ok when pp_is_list holds), and this refuses
 * arrays outright. The converse — "nothing else is ever mistaken for the marker" — is NOT
 * a property of this predicate and must not be asked of it: `changes[].from` carries
 * author- and model-controlled data on other paths (`_pp_diff_props` / `_pp_diff_style`
 * build it straight out of stored prop and style values, which are free-form), so a stored
 * prop shaped like this marker would satisfy every clause above. That is why the CALLERS
 * gate on `change.path === 'composition'` — the only path the server ever sends a marker
 * on — rather than trusting shape alone. Widening a caller without that gate would let a
 * planted prop value hide a real before-state behind a fake corruption notice, which is
 * this bug wearing a disguise.
 */
function ppChatIsUnreadableComposition(val) {
    return !!val
        && typeof val === 'object'
        && !Array.isArray(val)
        && val.unreadable === true
        && typeof val.message === 'string'
        && val.message !== ''
        && typeof val.classification === 'string'
        && val.classification !== '';
}

/**
 * Builds a human-readable summary of a composition replacement.
 * Compares from/to arrays by component type to identify adds, removes,
 * reorders, and content changes.
 *
 * Returns an object: { lines: string[], notice: string|null, fromCount: number|null,
 * toCount: number }
 *
 * THE UNREADABLE BEFORE SIDE (#836) IS NOT A DIFF, so it does not get diffed. When the
 * server sends the unreadable marker instead of a list, this reports the corruption and
 * the count it will be replaced WITH, and stops. Every other line below \u2014 added, removed,
 * reordered, content changes \u2014 answers "what is different", a question with no answer when
 * the before side could not be read; computing them against the `[]` this used to coerce
 * the marker into is exactly how the card came to say "0 \u2192 N components" with an
 * empty removed list on a page full of bytes.
 *
 * `notice` IS SEPARATE FROM `lines`, and that is a hierarchy decision rather than a
 * structural one. The card's whole job on this state is to stop an operator overwriting
 * bytes they cannot see, and a sentence pushed into `lines` renders as one more unstyled
 * row of the summary \u2014 typographically identical to "+ Added: hero" on a routine diff.
 * Measured against the card's own family, that inverts the hierarchy: a mistyped style-slot
 * name paints an amber `pp-ai-step-warning` step, while "this page's stored contents cannot
 * be read" painted plain grey, because a corrupt-before preview SUCCEEDS and so carries
 * none of the failed/impossible/fixable classes. Handing the notice back on its own key
 * lets ppChatRenderCompositionDiff() give it that existing amber treatment without any new
 * CSS and without touching the sentence.
 *
 * `fromCount` is null in that case, not 0. Nothing in the shipped card reads it (only
 * `toCount` is, for the raw-JSON disclosure label), but 0 is the count of a blank page and
 * this state is not one \u2014 an unknown reported as a number is the same lie one layer down.
 *
 * The corruption sentence is the SERVER'S, printed verbatim from `from.message`. It is
 * built by pp_composition_integrity_message() (lib/wp.php), the single owner of how this
 * state is said out loud; a sentence composed here would be the second spelling #650/#652
 * exist to prevent.
 */
function ppChatBuildCompositionSummary(from, to) {
    var toArr = Array.isArray(to) ? to : [];
    var toTypes = toArr.map(function (c) { return (c && c.component) || '(unknown)'; });
    var headline = 'Full composition replacement: ';
    var componentList = 'Components: ' + toTypes.join(' \u2192 ');

    if (ppChatIsUnreadableComposition(from)) {
        return {
            lines: [
                headline + 'unreadable \u2192 ' + toArr.length + ' components',
                '',
                componentList
            ],
            notice: from.message,
            fromCount: null,
            toCount: toArr.length
        };
    }

    var fromArr = Array.isArray(from) ? from : [];
    var lines = [];

    lines.push(headline + fromArr.length + ' \u2192 ' + toArr.length + ' components');

    // Build type lists
    var fromTypes = fromArr.map(function (c) { return (c && c.component) || '(unknown)'; });

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
    lines.push(componentList);

    return { lines: lines, notice: null, fromCount: fromArr.length, toCount: toArr.length };
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
 *
 * IT HANDLES THE UNREADABLE MARKER TOO (#836), and that is not defensive coding — it is
 * the only renderer `restore_composition` ever reaches. The composition-summary branch in
 * ppChatRenderPreviewResult() is scoped to `update_composition`, so a restore proposal —
 * the OTHER verb ruling D-1 admits on a corrupt page — draws its card here. Left alone,
 * ppChatFormatDiffValue() would JSON-stringify the marker and truncate it at 80
 * characters, which buries the diagnosis mid-object and can cut the sentence in half.
 *
 * THE `path` CLAUSE IS A SECURITY GATE, not a tidiness check, and it must not be dropped
 * as redundant with the predicate. `changes[].from` carries author- and model-controlled
 * data on other paths — `_pp_diff_props()` / `_pp_diff_style()` build it straight out of
 * stored prop and style values, and a prop value is free-form (the meta sanitize callback
 * only requires a decodable JSON array; no rule constrains a prop's inner shape). So a
 * stored prop or style value shaped like the marker satisfies every clause of
 * ppChatIsUnreadableComposition() on its own. Without this clause, planting one would
 * replace a real before-value with a fake corruption notice carrying attacker-chosen text
 * — hiding the before-state on the surface whose entire job is telling the truth about it,
 * which is this bug wearing a disguise. `composition` is the only path the server ever
 * sends a marker on, and the only other producer of that path sends a plain string
 * (`add_component`'s "N components"), so the clause costs nothing.
 *
 * THE VISUAL SPLIT: a SHORT label on the from side, the sentence on its own line beneath,
 * and the amber `pp-ai-step-warning` on the sentence rather than on the label. Three
 * reasons, all of them rendered and looked at rather than reasoned about:
 *   - `.pp-ai-step-diff-from` is styled `text-decoration: line-through`
 *     (assets/css/pp-ai-chat.css), an idiom meaning "this value was replaced". Struck
 *     through is the wrong claim to make about a diagnosis, so the marker's label does
 *     NOT take that class — and a ~140-character struck-through paragraph would have been
 *     unreadable regardless, which is why the label is short and the sentence is not in it.
 *   - when #836 landed, dropping the class also dropped a second arrow the class painted
 *     via `::after`, leaving this line with the ONE the text node supplies while every
 *     other diff line rendered two. #852 has since removed that `::after` instead, so the
 *     arrow now comes from the text node on EVERY row and the class no longer has anything
 *     to do with it — this clause is history, not a live asymmetry.
 *   - `pp-ai-step-warning` is this card family's existing in-step notice class, already
 *     used on bare divs elsewhere in this file, so the weight costs no new CSS and moves
 *     no rendered pin.
 * The label reuses the two nouns already in play — `unreadable` and the server's own
 * classification — and composes no new sentence; the diagnosis is the server's, verbatim.
 */
function ppChatRenderDiffLine(change) {
    var div = document.createElement('div');
    var label = document.createTextNode(change.path + ': ');
    div.appendChild(label);

    var unreadable = change.path === 'composition'
        && ppChatIsUnreadableComposition(change.from);

    var fromSpan = document.createElement('span');
    if (!unreadable) {
        fromSpan.className = 'pp-ai-step-diff-from';
    }
    fromSpan.textContent = unreadable
        ? 'unreadable (' + change.from.classification + ')'
        : ppChatFormatDiffValue(change.from);
    div.appendChild(fromSpan);

    // THE ARROW, ONCE, FOR EVERY ROW (#852). This is the row's ONLY separator and the only
    // thing that draws one: `.pp-ai-step-diff-from` used to carry an `::after` painting a
    // second, so every generic row rendered `#111 \u2192  \u2192 #222` while the marker row \u2014 which
    // deliberately does not take that class \u2014 rendered one. The CSS rule is gone; see
    // assets/css/pp-ai-chat.css for why that side lost rather than this one.
    //
    // Keep it a DOM TEXT NODE and keep it unconditional. Pseudo-element `content` is not in
    // the DOM, so it is absent from `innerText` and from anything copied out of the card \u2014
    // the two values fused into `OldNew` \u2014 and it could not space itself either: Chromium
    // computes `content: ' \2192 '` as `" \u2192"` and `display: inline-block` strips the rest,
    // rendering a cramped `Old\u2192New`. A text node is read, copied and spaced like the values
    // it separates, and it treats both branches identically.
    //
    // It sits OUTSIDE fromSpan deliberately: that span is `text-decoration: line-through`,
    // and a struck-through arrow reads as part of the replaced value rather than as the
    // separator between two of them.
    div.appendChild(document.createTextNode(' \u2192 '));

    var toSpan = document.createElement('span');
    toSpan.className = 'pp-ai-step-diff-to';
    toSpan.textContent = ppChatFormatDiffValue(change.to);
    div.appendChild(toSpan);

    if (unreadable) {
        // textContent, never innerHTML: the sentence carries a page id and a
        // classification derived from stored data, and this card is not a markup surface.
        var note = document.createElement('div');
        note.className = 'pp-ai-step-warning';
        note.textContent = change.from.message;
        div.appendChild(note);
    }

    return div;
}

/**
 * Renders an update_composition diff: a prose summary plus the raw JSON in a disclosure.
 *
 * Module scope for the same reason as ppChatRenderDiffLine above.
 *
 * THE NOTICE IS DRAWN FIRST AND CARRIES WEIGHT (#836). When the before side could not be
 * read, ppChatBuildCompositionSummary() hands the diagnosis back on its own `notice` key
 * instead of as another row of `lines`, and it lands here as an amber
 * `pp-ai-step-warning` div ABOVE the summary. Both halves of that are deliberate on a
 * surface whose only job is stopping an operator overwriting bytes they cannot see: the
 * alarm should come before the mechanics, and it should not look like "+ Added: hero".
 * Nothing about the sentence changes — `pp-ai-step-warning` is an existing class used on
 * bare divs elsewhere in this file, so this needs no CSS at all.
 */
function ppChatRenderCompositionDiff(diffArea, change) {
    var summary = ppChatBuildCompositionSummary(change.from, change.to);

    if (summary.notice) {
        var notice = document.createElement('div');
        notice.className = 'pp-ai-step-warning';
        // textContent, never innerHTML — the sentence is built from stored data.
        notice.textContent = summary.notice;
        diffArea.appendChild(notice);
    }

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
 * renders `raw_error` at whatever length the server sent (bounded there, by
 * PP_REFLECTED_ERROR_MAX) and `alternatives` at whatever length it sent them (collapsed
 * there, but deliberately NOT length-bounded — see _pp_no_hint_slot_message(),
 * lib/ai-chat.php), and a successful
 * update_composition preview still renders its full JSON in a disclosure. Bounding the one
 * string this file invents is not a claim that the card is bounded everywhere; the sites
 * that render SERVER error text carry their own ceiling since #793
 * (PP_CHAT_REFLECTED_ERROR_MAX), and this budget stays separate from it because this
 * sentence is not reflected text — it is this file describing its own failure.
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
                // The `from` clause admits the unreadable marker alongside a list (#836):
                // the summary renderer is the surface that says "Full composition
                // replacement", which is the claim an operator approves, so a corrupt
                // before side has to reach it rather than fall through to the generic
                // line. Every OTHER payload takes exactly the branch it took before —
                // `to` must still be a list, and a `from` that is neither a list nor the
                // marker still goes to ppChatRenderDiffLine().
                if (step.name === 'update_composition' && change.path === 'composition' &&
                    Array.isArray(change.to) &&
                    (Array.isArray(change.from) || ppChatIsUnreadableComposition(change.from))) {
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
 *
 * IT HAS A NEAR-IDENTICAL TWIN WITH THE OPPOSITE DEFAULT, and neither may be folded into
 * the other. ppChatRollbackRowClass() (#855) picks the same two classes from the same
 * field, but its unrecognized case is an unknown rollback KIND — an older envelope, a
 * newer server — which Ruling T2 says must render as it did before, i.e. the FAILURE
 * class. De-escalating there would draw a real failed revert as a harmless protection.
 * Two functions, two defaults, on purpose.
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
 * The bound this card puts on a refused undo's message, in characters (#822).
 *
 * THE SERVER'S OWN NUMBER FOR THIS SPECIES. `PP_REFLECTED_ERROR_MAX` (lib/ai-chat.php) is
 * 4096, and it is what `_pp_clean_reflected_text()` allows a reflected WP_Error message.
 * When #822 wrote this, the EXECUTE path the card reads did NOT pass through that helper —
 * `_pp_ai_execute_error_payload()` reflected `$result['error']` verbatim — so this was the
 * only bound the undo refusal ever met. Since #647 that payload goes through the helper too
 * (lib/ai-chat.php), so this is now the bound the CARD RENDERS UNDER rather than the only
 * bound in the system. Spelling it with the server's number keeps one answer to "how long
 * may a reflected error be" instead of coining a second, which is what
 * tests/ChatUndoBoundTrait.php asserts. The general client-side ceiling for server error
 * text this file renders is PP_CHAT_REFLECTED_ERROR_MAX (#793), below.
 *
 * IT HAS TO OUTRUN THE MESSAGES, or the bound defeats the fix it belongs to. Both refusals
 * this exists for put their ACTIONABLE half LAST: `history_entry_not_restorable` ends with
 * `wp pp operate composition-history --post_id=N` and "select an earlier entry", and
 * `history_target_shifted` ends with that same command plus the steps_back/history_index
 * advice. A cut anywhere before the end therefore removes exactly the sentence the operator
 * needs. Measured at ~370 and ~690 characters, so this is roughly six times the longest
 * thing the path can deliver — and the PHP side ASSERTS they fit rather than leaving that
 * headroom to be re-measured by hand (CompositionHistoryRawPreservationTest and
 * CompositionRestoreSelectorLockTest both read this constant out of this file).
 */
var PP_CHAT_UNDO_ERROR_MAX = 4096;

/**
 * The undo link's failed label, and the row's opening words (#822).
 *
 * ONE OWNER, BECAUSE THE TWO HAVE TO AGREE OUT LOUD. Three places now say this: the link on
 * the refusal branch, the link in the transport `.catch`, and the announced row, which leads
 * with it because the SERVER'S SENTENCE DOES NOT SAY WHETHER THE UNDO HAPPENED. The refusal
 * opens "History entry 0 (steps_back 1) holds stored bytes that did not decode to a
 * composition…" — the outcome is four clauses in, and for a screen-reader operator the label
 * on the link is not announced at all (a plain anchor in `.pp-ai-post-apply-links` is inside
 * no live region). Leading with the outcome is also this card's own convention: every other
 * status block on it opens with one ("✓ All changes applied successfully.", "⚠ N changes
 * could not be reverted:").
 *
 * It reuses the words the link has always shown rather than coining a failed-state
 * vocabulary; whether these states deserve their own words is #664's question, not this one's.
 */
var PP_CHAT_UNDO_FAILED_LABEL = 'Undo failed';

/**
 * The refusal row's identity, so the renderer can find the one it already drew (#822).
 *
 * A HOOK, NOT A STYLE — no rule in assets/css/pp-ai-chat.css selects it, and none should:
 * the row's appearance comes from `pp-ai-step-failed`, which it wears alongside this. It
 * exists because `.pp-ai-step-failed` is not a usable handle (the rollback report draws its
 * heading and every row with that class on this same card), so "is my row already here?"
 * needs a name only this renderer answers to.
 */
var PP_CHAT_UNDO_FAILURE_CLASS = 'pp-ai-undo-failure';

/**
 * The server's reason for refusing an undo, bounded, or null when it sent nothing usable
 * (#822).
 *
 * TWO SHAPES ARRIVE HERE, and the order is the wire's, not a preference. `wp_ajax_pp_ai_execute`
 * hands `_pp_ai_execute_response()`'s payload straight to `wp_send_json_error()`, and
 * `_pp_ai_execute_error_payload()` (lib/ai-chat.php) returns a STRUCTURED array for exactly one
 * code — `composition_conflict`, which the caller's conflict branch has already taken — and
 * `$result['error']` as a bare STRING for every other refusal. So:
 *
 *   history_entry_not_restorable  (#818) ─┐
 *   history_target_shifted        (#829) ─┤
 *   no_history / history_out_of_bounds   ─┼─► a STRING          ─► rendered
 *   composition_lock_failed              ─┤
 *   'Permission denied.' and friends     ─┘
 *   missing_expected_version      (#404) ──► { error, error_code } ─► `.error` rendered
 *   composition_conflict          (#404) ──► never reaches here (ppChatIsCompositionConflict)
 *
 * THERE IS NO PER-CODE SWITCH TO WRITE, and that is a property of the wire rather than a
 * shortcut. The string arm carries no `error_code` at all, and the messages already
 * self-identify where the code matters — `history_target_shifted` ends by naming itself in
 * brackets (lib/actions.php). Re-deriving a classification from text this file did not build
 * would be a second opinion about a failure the server already classified once, which is the
 * hazard ppChatModelNote() is written against.
 *
 * "USABLE" IS THE FILE'S EXISTING QUESTION, ASKED ONCE. ppChatIsNonEmptyString() is the one
 * answer to "is this text this card can print", and it deliberately accepts whitespace —
 * trimming here would give the same question a second answer and move a contract #775 owns.
 * Nothing reachable sends whitespace anyway: every message on this path is a server literal.
 *
 * NOT FOLDED IN WITH THE TRANSCRIPT LINE that reads the same field for a failed BATCH
 * (`'Error: ' + ((resp.data && resp.data.error) || resp.data || 'Unknown error')`,
 * executeProposal). They ask one question and give two different answers to the unusable
 * case: that line prints 'Unknown error' as a status message, this returns null so the card
 * draws NOTHING rather than an empty red bar under a link that already says "Undo failed".
 * Sharing a reader would have to pick one of those, and picking this one would change what
 * an unreadable batch payload prints on a path #749 owns. Two readers, stated, beats one
 * reader that silently moves someone else's surface.
 *
 * The truncation idiom is the file's (ppChatPreviewRenderErrorText, ppChatFormatDiffValue,
 * and _pp_clean_reflected_text on the PHP side): cut to max - 3 and mark it, so the result
 * never exceeds the stated budget.
 *
 * @param  {*}             data  resp.data from a failed pp_ai_execute.
 * @return {string|null}         The message to draw, or null for "say nothing".
 */
function ppChatUndoFailureText(data) {
    var raw = null;

    if (ppChatIsNonEmptyString(data)) {
        raw = data;
    } else if (ppChatIsPlainObject(data) && ppChatIsNonEmptyString(data.error)) {
        raw = data.error;
    }

    if (raw === null) {
        return null;
    }

    if (raw.length > PP_CHAT_UNDO_ERROR_MAX) {
        raw = raw.substring(0, PP_CHAT_UNDO_ERROR_MAX - 3) + '...';
    }

    return raw;
}

/**
 * Renders WHY an undo was refused, beneath the link that says it was (#822).
 *
 * The link keeps saying "Undo failed" and this says what the server said. Splitting it that
 * way is not decoration: the two messages that make this issue worth fixing are ~370 and
 * ~690 characters and each ends in a shell command, and the link is a two-to-four word status
 * affordance sitting in a row beside "View Page →". Putting a paragraph in the link text
 * would destroy the row; putting the paragraph nowhere is the bug.
 *
 * WHAT THE PRESERVED-BYTES MESSAGE IS. After a chat-driven repair of a corrupt page, the
 * newest ring slot holds the page's ORIGINAL undecodable bytes rather than a composition
 * (#818), so the undo link's `steps_back: 1` selects it and the restore is refused. That
 * refusal is the ONLY place a chat-only operator is ever told the bytes survived the repair
 * at all, and it names `wp pp operate composition-history --post_id=N` as the way to read
 * them. Discarding it left the operator believing their prior state was destroyed by the
 * fix that preserved it.
 *
 * PLACEMENT AND CLASS ARE INHERITED, NOT INVENTED. ppChatAppendUndoFindings() above appends
 * a `role="status"` section to this same card on the SUCCESS path (and
 * `.pp-ai-proposal-card [role="status"]` already carries its spacing), and
 * ppChatAppendRollbackErrors() renders failure rows as bare `pp-ai-step-failed` divs. So
 * this reuses both and adds no CSS. The row was rendered at 375 and 1280 against the real
 * stylesheet before it was written: the prose wraps at its spaces inside the card's
 * `max-width: 90%`, nothing overflows, and the class's lack of padding as a bare div is a
 * property it already has on every rollback row beside it — pre-existing, shared, and not
 * silently fixed here (filed separately).
 *
 * IT DOES NOT GO THROUGH THE SHARED ROW BUILDER, deliberately. ppChatValidationItemRow()
 * prefixes `[type] index N: ` parsed off the item, which is the locator surface the forgery
 * concern is filed against — raised in #793 and, since #793 closed on its LENGTH half only,
 * still open as #867. A refusal is not a finding and owns no band, so it renders as one `textContent`
 * assignment with no prefix — there is nothing here for a message to forge, because no
 * locator is drawn. `textContent` is also the only write: the payload is a server string and
 * never becomes markup.
 *
 * IT LEADS WITH THE OUTCOME, because the server's sentence does not contain it. The refusal
 * opens "History entry 0 (steps_back 1) holds stored bytes that did not decode to a
 * composition…"; whether the undo HAPPENED is four clauses in, and to a screen-reader
 * operator it is nowhere at all — `undoLink.textContent = 'Undo failed'` mutates a plain
 * anchor in `.pp-ai-post-apply-links`, which is inside no live region and announces nothing.
 * So this row is the entire non-visual reading of the outcome, and every other status block
 * on this card already opens with one. The prefix is PP_CHAT_UNDO_FAILED_LABEL, the words the
 * link itself shows, so nothing new is coined.
 *
 * ONE ROW PER CARD — AND SINCE #861 THIS BRANCH IS DEFENSIVE, NOT LOAD-BEARING. Read the
 * history, because the reason changed and the code did not. This row-rewrite existed
 * because `undoLink.style.pointerEvents = 'none'` LOOKED like a single-shot latch and was
 * not one: `pointer-events: none` removes an anchor as a MOUSE target while leaving it
 * focusable, so a keyboard operator pressing Enter still dispatched `click` (measured in
 * Chromium, not assumed) and stacked a second ~370-to-690-character row on the card — on
 * precisely the operator who reached the link by keyboard. #861 closed that: the link is
 * now bound through ppChatOneShotLink(), which latches before it runs, so no production
 * path can reach this function twice for one card (buildPostApplyCard() builds one undo
 * link, and its handler is the only caller).
 *
 * The branch is KEPT rather than deleted, and the distinction matters to whoever edits it
 * next: it is now defense-in-depth plus the contract this function offers its direct
 * callers, including tests/js/pp-ai-chat-undo-failure.test.js, which calls it repeatedly on
 * one card by design. Rewriting the existing row is still the better shape than appending —
 * the row then reflects the LATEST refusal, and because it mutates a region already in the
 * DOM it announces again — but nothing on the card depends on that any more.
 *
 * INSERTED EMPTY, THEN FILLED — AND THAT ORDER IS THE ANNOUNCEMENT. A live region that
 * enters the DOM already holding its text is the classic case assistive technology does not
 * read: screen readers register the region on insertion and announce subsequent MUTATIONS,
 * so building the node, setting `textContent`, and appending last would leave the row silent
 * for exactly the operator who most needs it read aloud. The two sibling appenders above
 * build-then-append, and they get away with it because their card still says the outcome in
 * text the user can find; here the row IS the payload. Do not "tidy" these statements back
 * into declaration order.
 *
 * SILENT WHEN THERE IS NOTHING TO SAY. A payload this file cannot read draws no section at
 * all, which is the same choice ppChatRenderPreviewError() makes about fragments it cannot
 * narrate: there is no vocabulary here for "the server sent something I could not read", and
 * inventing one is #664's question. The link's own "Undo failed" still tells the operator the
 * undo did not happen.
 *
 * @param  {Element} card  The post-apply proposal card.
 * @param  {*}       data  resp.data from the failed pp_ai_execute.
 * @return {Element|null}  The row, existing or new, or null when nothing was drawn.
 */
function ppChatAppendUndoFailure(card, data) {
    var text = ppChatUndoFailureText(data);

    if (!card || text === null) {
        return null;
    }

    var line = PP_CHAT_UNDO_FAILED_LABEL + ': ' + text;
    var row  = card.querySelector('.' + PP_CHAT_UNDO_FAILURE_CLASS);

    if (row) {
        row.textContent = line;
        return row;
    }

    row = document.createElement('div');
    row.className = 'pp-ai-step-failed ' + PP_CHAT_UNDO_FAILURE_CLASS;
    row.setAttribute('role', 'status');
    row.setAttribute('aria-live', 'polite');
    card.appendChild(row);
    row.textContent = line;

    return row;
}

/**
 * The attribute a spent one-shot link carries, and the latch ppChatOneShotLink() reads.
 *
 * Named rather than spelled inline because it is read in one place and written in another,
 * and a typo in either would silently restore the bug this exists to close.
 */
var PP_CHAT_LINK_SPENT_ATTR = 'aria-disabled';

/**
 * Binds a link that may be activated exactly ONCE (#861).
 *
 * WHAT WAS WRONG, MEASURED RATHER THAN REASONED ABOUT. Both post-apply links used to
 * guard a second activation with `link.style.pointerEvents = 'none'`, set before the
 * fetch and restored by no branch anywhere — so single-shot was plainly the intent. It
 * is not one. `pointer-events: none` removes an element as a MOUSE target; it leaves an
 * anchor focusable, and pressing Enter on a focused anchor still runs the activation
 * behavior and dispatches `click`. In headless Chromium, on exactly this idiom: a mouse
 * click gives 1 handler invocation, and two subsequent Enter presses give 3. The same
 * measurement against this helper gives 1 across a mouse click, two Enters, a Space, a
 * programmatic `.click()` and a synthetic dispatched MouseEvent.
 *
 * That mattered rather than being cosmetic because both links WRITE. `undoLink` POSTs
 * `restore_composition` carrying a CAS baseline that the first activation already made
 * stale, so a keyboard operator pressing Enter twice got a second restore attempt whose
 * refusal blamed a conflict on nothing — the operator's own first press. `resetLink`
 * POSTs `reset_design_token`, which is idempotent, so the cost there was a duplicate
 * request rather than a wrong answer.
 *
 * THE LATCH IS THE GUARD; THE OTHER TWO ARE NOT. The early return on the closure's own
 * `spent` flag is the whole of what makes a second activation impossible, and it is
 * checked FIRST, before anything is mutated or sent.
 *
 * THE FLAG IS PRIVATE, AND THAT IS THE POINT. An earlier draft read the latch back out of
 * the `aria-disabled` attribute, on the reasoning that one fact should have one home. That
 * puts CONTROL state in an ACCESSIBILITY attribute, where it is writable by anything with
 * a handle on the node and, more likely, where a future pass that normalises ARIA — or
 * re-renders the link's attributes — would silently re-arm a write path. Nothing in this
 * file does that today; the flag is private anyway, because the cost is one variable and
 * the failure it prevents is a duplicate composition restore. `aria-disabled` is still
 * SET, because a screen reader has to be told the link is spent; it is simply not the
 * thing the guard trusts. The two are written together and never read apart.
 *
 * `pointerEvents` is kept because it is the spent link's existing MOUSE affordance and
 * dropping it would leave a dead link looking live — it is not load-bearing for the
 * guard, and it was never a disable.
 *
 * WHAT "SPENT" OWNS, precisely, because the word is easy to overclaim: activation, and
 * nothing else. The callers' own `.then` / `.catch` branches still rewrite `textContent`
 * and `className` afterwards to report the outcome, exactly as they did before, and a
 * link stays spent through every one of those branches including the failures. That is
 * today's behavior — no branch ever restored `pointerEvents` either — and it is
 * preserved rather than revisited here.
 *
 * `run()` is invoked LAST, after the link is latched and relabelled, so a throw inside
 * it cannot leave a half-spent link that a second Enter could re-enter. A throw there
 * still leaves the link permanently spent with no request sent; that is byte-identical
 * to what the pre-#861 handlers did with the same throw, and widening it is not this
 * issue's question.
 *
 * @param {Element}  link        The anchor to bind. Mutated when activated.
 * @param {string}   spentLabel  The label shown the moment it is activated.
 * @param {Function} run         The work. Called once, ever, with no arguments.
 */
function ppChatOneShotLink(link, spentLabel, run) {
    var spent = false;

    link.addEventListener('click', function (e) {
        e.preventDefault();

        // FIRST, before any mutation or request. Every activation path lands here —
        // mouse, Enter, Space, `.click()`, a dispatched event — because none of them
        // consults `pointer-events` and all of them dispatch `click`.
        if (spent) {
            return;
        }
        spent = true;

        link.textContent = spentLabel;
        link.setAttribute(PP_CHAT_LINK_SPENT_ATTR, 'true');
        link.style.pointerEvents = 'none';

        run();
    });
}

/**
 * Names the pages and menus a failed batch's rollback did NOT restore (#755).
 *
 * The companion to ppChatRollbackSentence(): that clause tells the transcript the
 * revert was not clean, and this puts WHICH things stayed dirty, and why, in the proposal
 * card the operator is already looking at. Report-only — nothing here blocks or retries.
 *
 * TWO CALLERS SINCE #797, and the sentence beside it differs while these rows do not. The
 * executed-failure exit pairs it with ppChatRollbackSentence(); showConflictState()
 * rebuilds its card and pairs it with the conflict message's own closing clause. Both hand
 * over a report computed once from the same channel, so the two cards disagree about
 * wording only, never about what the rollback reported. Placement is why the conflict card
 * appends its action row first: the insert below targets `.pp-ai-proposal-actions`.
 *
 * WHY THE CARD AND NOT THE TRANSCRIPT LINE. `.pp-ai-status` is `text-align: center` with
 * no width clamp (assets/css/pp-ai-chat.css), which is right for a one-line notice and
 * wrong for a list of long strings. `.pp-ai-proposal-card` carries `max-width: 90%`, and
 * the wrap comment on `.pp-ai-preview-error-detail > div` states that clamp is what makes
 * `overflow-wrap: break-word` hold at all — render the disclosure outside the card and
 * that wrap silently stops working. The card also already styles `[role="status"]`
 * sections, because ppChatAppendUndoFindings above puts one there.
 *
 * Note precisely what that inherited wrap does and does not cover: the rule is scoped to
 * `.pp-ai-preview-error-detail > div`, so it reaches the DISCLOSURE rows only. The five
 * INLINE rows carry `pp-ai-step-failed`, which declares no wrap, and one of the producers
 * interpolates a user-authored menu title. Ordinary prose wraps at its spaces; an
 * unbroken token would not. That gap is pre-existing and shared with every other finding
 * row on this card — filed as #798, not worked around here.
 *
 *   batch.rollback_errors      ──▶ report {shown, total, reported, readable,
 *   batch.rollback_error_kinds ──▶         kinds, withheld}
 *          │                     │
 *          │                     ├─▶ nothing renderable ──▶ no section, no empty card
 *          │                     │
 *          │                     └─▶ N ──▶ heading (reported count, + "showing the first")
 *          │                                 │        amber iff withheld === reported
 *          │                                 └─▶ 5 rows inline ──▶ <details> for the rest
 *          │                                       amber per withheld row, red otherwise
 *          │
 *          ├──▶ (same report) ──▶ ppChatRollbackSentence()   ──▶ transcript line   [exit 3]
 *          └──▶ (same report) ──▶ ppChatConflictOutcome()    ──▶ card message      [exit 1]
 *
 * TWO TREATMENTS SINCE #855, ONE VOCABULARY. `rollback_errors` carried two meanings on one
 * opaque channel — a restore the rollback DECLINED to make (the #756/#749/#833 composition
 * withholds and the two attachment refusals: nothing attempted, nothing broken) and a
 * restore it OWED and did not land — and this card drew both in the failure colour, so a
 * withhold that cost nothing read as a rollback failure. The server now tags each entry and
 * this function draws the two apart: `pp-ai-step-warning` for a withhold,
 * `pp-ai-step-failed` for everything else, INCLUDING every kind it does not recognize and
 * every report that carries no kinds at all. The words are untouched — the withhold
 * sentences already say "was NOT rolled back, and that is the safe outcome" — because T2
 * leaves the wording half on #664.
 *
 * THE ROWS GO THROUGH ppChatAppendValidationItems ON PURPOSE, and the adapter is one
 * line: each string becomes `{ message: <string>, severity: 'error'|'warning' }`. That
 * object carries no `index` and no `type`, so ppChatFindingBand() answers null and
 * ppChatFindingLocator() answers '' —
 * every entry lands in the "unlocated" group, which is never pooled, so the existing
 * selection yields five inline rows and the remainder behind "Show N more errors". Five
 * inline, the disclosure, and the #667 rule that it never opens on an empty set are all
 * inherited rather than re-implemented.
 *
 * WHAT THE PER-ITEM CLASS FORM COSTS, stated because it is the one visible knock-on. Passing
 * a function rather than a fixed class makes ppChatAppendValidationItems derive the
 * disclosure summary's noun from the hidden items' severities, so an all-withheld overflow
 * now reads "Show N more warnings" and a mixed one "Show N more issues". Those are that
 * helper's own three nouns (#622), not new vocabulary, and the all-failure case — which is
 * every pre-#855 report and every old envelope — still reads "errors", byte for byte.
 *
 * That reuse is also a COUPLING, so it is stated rather than left to be discovered: these
 * strings are not validation findings, and this is the second consumer of a helper whose
 * band-aware selection was written for the first. What the helper is relied on for here
 * is only its unlocated path (5 + disclosure + the empty gate); a future change to how
 * LOCATED findings are grouped cannot reshape these rows, because none of them is located.
 *
 * THE HEADING COUNTS WHAT THE SERVER REPORTED, NOT WHAT SURVIVED THE FILTER. `reported`
 * rather than `total`, so a channel carrying members this file cannot draw still states
 * the real size and the "(showing the first N)" clause then covers BOTH reasons a row can
 * be missing — the display budget, and a member that was not a renderable string. Using
 * the filtered count would understate the server's own report with nothing on screen
 * saying anything had been dropped.
 *
 * The truncation clause is spelled exactly as ppChatAppendUndoFindings spells it, because
 * both headings can appear on the same card and two dialects of one notice is how a
 * reader learns to distrust both.
 */
function ppChatAppendRollbackErrors(card, report) {
    if (!card || !report || !report.shown.length) return;

    var shown = report.shown;
    var kinds = report.kinds || [];
    // EVERY REPORTED ENTRY IS A KNOWN WITHHOLD, or this is a failure report. Strict
    // equality against `reported` — not "no failures among the drawn rows" — so the amber
    // treatment needs the whole channel to agree: one failed entry, one unrenderable
    // member, one kind from a newer server, one absent kinds list, and the heading is red
    // exactly as it has always been.
    var allWithheld = report.reported > 0 && report.withheld === report.reported;

    var section = document.createElement('div');
    section.setAttribute('role', 'status');
    section.setAttribute('aria-live', 'polite');

    var heading = document.createElement('div');
    heading.className = allWithheld ? 'pp-ai-step-warning' : 'pp-ai-step-failed';
    // THE SENTENCE IS UNTOUCHED, AND THAT IS THE RULING, not an omission. T2 resolves #855
    // by tagging the entries and drawing the kinds differently; it keeps the WORDING half
    // pooled on #664 and adds no operator vocabulary. So a report of nothing but withholds
    // still heads "N changes could not be reverted" — it counts, because the rollback was
    // not clean — and what changed is that the heading and its rows now carry the warning
    // treatment instead of the failure one, over sentences the server already writes as
    // "was NOT rolled back, and that is the safe outcome".
    heading.textContent = '⚠ ' + report.reported + ' change'
        + (report.reported === 1 ? '' : 's')
        + ' could not be reverted'
        + (shown.length < report.reported ? ' (showing the first ' + shown.length + ')' : '')
        + ':';
    section.appendChild(heading);

    // THE ADAPTER CARRIES A SEVERITY, NOT THE KIND, and it is doing two jobs with one field.
    // ppChatRollbackRowClass() reads it for the row's class, and ppChatAppendValidationItems
    // reads it for the disclosure summary's noun — which is why the failed and unknown cases
    // must both spell 'error' rather than leaving the field off. Omitting it would draw the
    // right classes and then call an all-failure overflow "warnings", and the pre-#855 card
    // said "errors" there. Kinds this file does not know land on 'error' with everything
    // else, so an old envelope's disclosure is byte-identical too.
    ppChatAppendValidationItems(section, shown.map(function (message, index) {
        return {
            message: message,
            severity: kinds[index] === PP_CHAT_ROLLBACK_KIND_WITHHELD ? 'warning' : 'error'
        };
    }), ppChatRollbackRowClass);

    // ABOVE THE ACTION ROW, NOT AFTER IT. renderProposal() appends `.pp-ai-proposal-actions`
    // last, so a plain appendChild would drop this disclosure underneath a pair of
    // now-disabled buttons, visually detached from the steps it explains.
    var actions = card.querySelector('.pp-ai-proposal-actions');
    if (actions) {
        card.insertBefore(section, actions);
    } else {
        card.appendChild(section);
    }
}

/**
 * The server's model-facing note for a refused proposal, or null (#704).
 *
 * PRESENCE DECIDES WHEREVER THIS IS ASKED, and this function interprets nothing. Whether a
 * rejection is the model's to answer is a question about the CLASS of the failure — a bad
 * prop key is the model's, a page that moved under a correct proposal is not — and
 * lib/ai-chat.php answers it once, at each refusal site, where the reason is actually
 * known. Re-deriving it here (by reading error_code, or by picking which failure branches
 * "look like" validation) is how two answers drift apart.
 *
 * ONE HONEST EXCEPTION, and it predates this: the CONFLICT branches never get here at all.
 * executeProposal() routes a composition_conflict and a missing baseline to
 * showConflictState() before any note is read (#404), so the client still owns THAT
 * routing. The server agrees — it writes no note for either class — but the agreement is
 * two decisions that match, not one decision asked once. If the server ever decided a
 * conflict IS the model's to answer, this file would have to be changed to let the note
 * through; nothing here would notice on its own.
 *
 * Read defensively for the same reason every other envelope read in this file is: the
 * payload is JSON off the wire, and a `.substring` on a non-string would throw inside the
 * fetch handler and take the rest of the failure rendering down with it (#663's lesson).
 */
function ppChatModelNote(payload) {
    if (!payload || typeof payload.model_note !== 'string') return null;

    return payload.model_note === '' ? null : payload.model_note;
}

/** Per-card counter for the repair note's id, so aria-describedby always resolves (#704). */
var ppChatRepairNoteSeq = 0;

/**
 * The visible half of the operator-gated repair loop (#704, ruling D-2).
 *
 * Two elements, and each answers a different half of the ruling.
 *
 *   ┌ card ─────────────────────────────────────────────────┐
 *   │ step rows (failed / skipped)                          │
 *   │ rollback disclosure, when there is one (#755)          │
 *   │ ┌ .pp-ai-proposal-actions ──────────────────────────┐ │
 *   │ │ [ Apply All ] [ Cancel ]   both disabled, spent   │ │
 *   │ └───────────────────────────────────────────────────┘ │
 *   │ ┌ .pp-ai-repair-actions ────────────────────────────┐ │
 *   │ │ "The AI can see this error..."   ← the model       │ │
 *   │ │                                    KNOWS           │ │
 *   │ │ [ Ask the AI to fix it ]         ← the operator    │ │
 *   │ └──────────────────────────────────────DECIDES──────┘ │
 *   └───────────────────────────────────────────────────────┘
 *
 * APPENDED LAST, after the Apply/Cancel row rather than in place of it. The spent row stays
 * because it is the record of what the operator authorized, and ppChatAppendRollbackErrors()
 * inserts its disclosure before the FIRST `.pp-ai-proposal-actions` — so growing the card
 * from the end keeps that insertion point where it was.
 *
 * The SENTENCE exists because the append is otherwise invisible. The note is a hidden
 * conversation turn; without a line saying so, an operator who reads the error has no way
 * to know whether the correction reached the model or is still theirs to retype, which is
 * the whole complaint #704 opens with. It is deliberately a statement of fact and not an
 * instruction: an operator who would rather type their own next message has already been
 * told the context is there.
 *
 * The BUTTON is the gate. D-2 rules the retry PROPOSED, never automatic, so the affordance
 * is the proposal and the click is the approval — nothing here sends anything, and the
 * handler this is given is the only thing in the file that can. It disables on use because
 * the card is history the moment the request is in flight; a second click would send a
 * second identical request against a conversation that already contains the first.
 *
 * DISABLED FIRST, THEN GIVEN BACK IF NOTHING WENT. Disabling before the handler runs is
 * what makes "a throwing handler leaves no live button" true of the code rather than of
 * the handlers we happen to pass. But a REFUSED send is not a spent one: sendMessage()
 * declines while a stream is in flight, and an operator who clicks this while their own
 * message is still streaming would otherwise be left with a permanently dead button and
 * nothing on screen explaining it. So a handler that answers `false` gets its button back.
 * A handler that throws does not, which is the conservative half staying conservative.
 *
 * MUST BE CALLED BEFORE addStatusMessage(), like every other thing that grows this card:
 * addStatusMessage() ends by pinning the transcript to its own bottom and the card is an
 * earlier sibling in that scroller, so growing the card afterwards pushes the alert line
 * back off the fold (#755's ordering contract).
 *
 * The button wears `.button.button-primary`, the same WordPress-admin pair
 * showConflictState() dresses its single affordance in, so a repair offer looks like a
 * repair offer wherever the card puts one. The ROW is its own class rather than
 * `.pp-ai-proposal-actions` — see the CSS, which explains why a flex row built for a pair
 * of buttons is the wrong container for a sentence and a button.
 *
 * @param {HTMLElement} card     The proposal card to grow.
 * @param {Function}    onRetry  Invoked on click. The ONLY path from this card to a request.
 * @return {HTMLElement|null}    The button, or null when there was no card to grow.
 */
function ppChatAppendRepairAffordance(card, onRetry) {
    if (!card) return null;

    var actions = document.createElement('div');
    actions.className = 'pp-ai-repair-actions';

    var note = document.createElement('div');
    note.className = 'pp-ai-repair-note';
    // Unique per card: a failure card is appended to a transcript that may already hold
    // earlier ones, and two elements sharing an id would point every aria-describedby at
    // whichever the parser saw first.
    note.id = 'pp-ai-repair-note-' + (++ppChatRepairNoteSeq);
    note.textContent = 'The AI can see this error — it is part of the conversation now.';
    actions.appendChild(note);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'button button-primary';
    btn.textContent = 'Ask the AI to fix it';
    // DESCRIBED, NOT ANNOUNCED. The sibling rollback disclosure is a live region
    // (role="status") and the failure line below the card is an alert, so a third
    // announcement on one failure would talk over the two that carry the actual news.
    // Tying the sentence to the control instead means it is read exactly when it is
    // actionable — as the button takes focus, which is where the line below sends it.
    btn.setAttribute('aria-describedby', note.id);
    btn.addEventListener('click', function () {
        // Disable FIRST: onRetry runs the send synchronously, and a handler that throws
        // after a half-sent request must not leave a live button behind it.
        btn.disabled = true;
        if (onRetry() === false) {
            // Nothing was sent, so nothing was spent. Explicit `=== false`, not falsy: a
            // handler that returns undefined is one that made no claim either way, and the
            // conservative reading of no claim is that the request went.
            btn.disabled = false;
        }
    });
    actions.appendChild(btn);

    card.appendChild(actions);

    // FOCUS HAS TO BE PUT SOMEWHERE, because the failure already took it away: executeProposal()
    // disables the Apply button the operator just clicked, and disabling the focused element
    // drops focus to <body>. A keyboard user would then Tab from the top of the whole WP admin
    // document to reach a button that appeared at the bottom of a card in direct response to
    // their own click. Moving it is the conventional answer for exactly that case — a primary
    // action created BY the user's activation — and this file already places focus deliberately
    // on the neighbouring outcomes (Cancel and a successful apply both end at the input).
    btn.focus();

    return btn;
}

/**
 * The ceiling this file puts on the server ERROR spans it renders, in UTF-16 code units (#793).
 *
 * Not a claim about every server-supplied span on screen, and the superlative is avoided
 * deliberately: `alternatives` is server-supplied, is rendered by this file, and is bounded
 * on NEITHER side — _pp_no_hint_slot_message() (lib/ai-chat.php) records that it is merely
 * COLLAPSED and still ships every declared name at full length. This covers the five error
 * sites listed below.
 *
 * THE SERVER'S OWN NUMBER FOR THIS SPECIES, for the same reason PP_CHAT_UNDO_ERROR_MAX
 * above is: `PP_REFLECTED_ERROR_MAX` (lib/ai-chat.php) is what `_pp_clean_reflected_text()`
 * allows a reflected validator message, so spelling it here keeps ONE answer to "how long
 * may a reflected error be" instead of coining a second. tests/ChatReflectedTextBoundTest.php
 * asserts the two are equal, so the copy cannot drift from its source.
 *
 * WHY A SECOND CONSTANT RATHER THAN REUSING THE UNDO ONE. They hold the same number and
 * anchor to the same server constant, but they carry different contracts.
 * PP_CHAT_UNDO_ERROR_MAX's docblock argues headroom over two specific refusals (~370 and
 * ~690 characters) and two PHP test classes assert those messages fit it with room to
 * spare. That is a promise about the undo card's own messages. This one makes no such
 * promise: its sites carry validator text whose length nobody can bound in advance, which
 * is precisely why they need a ceiling.
 *
 * WHY THE CLIENT BOUNDS AT ALL, when #647 moved reflected-text cleaning to the server.
 * Character-class cleaning IS server-owned and this file must never coin a second
 * definition of it (v1.17.8 recorded that decision explicitly). LENGTH is a different
 * question, and of the five sites this helper serves the server answers it for exactly one
 * — the up-front batch refusal, whose message _pp_batch_target_state_message()
 * (lib/actions.php) composes from string literals and an integer post id, so it is bounded
 * BY CONSTRUCTION rather than by a rule, and nothing pins that property. For the other four
 * there is no answer at all:
 *
 *   - `_pp_action_error()` (lib/actions.php) stores `'error' => $error` verbatim, so the
 *     batch envelope's error and every `steps[i].error` reflect validator messages —
 *     including `Unknown component: "%s"` with a stored component name — uncapped;
 *   - `_pp_bounded_findings()` (lib/actions.php) is a COUNT bound and says so in its own
 *     docblock ("THIS IS A COUNT BOUND, AND ONLY A COUNT BOUND"), so `findings[].message`
 *     has no length ceiling either — `duplicate_component_id` alone enumerates every
 *     colliding index in ONE entry, so that entry grows with the band count;
 *   - `pp_ai_parse_error_response()` (lib/ai-provider.php) returns a third party's error
 *     body, tag-stripped and otherwise whole.
 *
 * Closing those on the server is #864, deferred pending a ruling on where the cleaning
 * owner should live. Until then this is the only ceiling those strings meet, and even
 * after #864 lands it stays as the backstop for a payload that never came from this theme.
 * It bounds what is RENDERED; it does not make the response smaller on the wire.
 *
 * UNITS, AND THE DIRECTION OF THE ERROR. `String.length` counts UTF-16 code units; the
 * server counts characters (`mb_strlen`). Every character is one or two code units, so
 * `.length >= mb_strlen` and this bound is STRICTER than the server's, not looser: a
 * message of 4096 astral-plane characters is 8192 code units and gets cut here even though
 * the server passed it. That is the trade this takes, deliberately and in both directions:
 * code units are what the existing PP_CHAT_UNDO_ERROR_MAX comparison uses, counting code
 * points would mean walking a hostile multi-megabyte string to decide whether to shorten
 * it, and a validator message would have to be more than half non-BMP text to reach the
 * gap at all. A defensive ceiling that errs toward cutting is the right side to err on.
 */
var PP_CHAT_REFLECTED_ERROR_MAX = 4096;

/**
 * Bounds one server-supplied span to PP_CHAT_REFLECTED_ERROR_MAX (#793).
 *
 * The truncation idiom is this file's and the server's alike (ppChatUndoFailureText,
 * ppChatPreviewRenderErrorText, ppChatFormatDiffValue, and _pp_clean_reflected_text on the
 * PHP side): cut to `max - 3` and mark it, so the result never exceeds the stated budget.
 * No new marker is invented.
 *
 * IT DOES NOT SANITIZE, and that is the point rather than an omission. #647 ruled that
 * control- and format-character stripping is single-owned by the server
 * (`_pp_clean_reflected_text`), because a second definition of "clean" in JavaScript could
 * only drift from it and because the client cannot repair invalid UTF-8. This adds the one
 * thing the server does not do for these payloads. tests/ChatReflectedTextBoundTest.php
 * pins the absence of a JS strip so the twin cannot be reintroduced by a later good idea.
 *
 * IT MEASURES NON-STRINGS TOO, and returning them untouched would have been a hole rather
 * than a scoping decision. Every call site coerces on its own — `'Error: ' + x`,
 * `textContent = x` — so a payload of `{ error: [ ...a million strings... ] }` would pass
 * a `typeof` guard, be handed back whole, and then be stringified into the DOM by the call
 * site at full length. The ceiling has to sit where the LENGTH is decided, which is after
 * coercion, not before it.
 *
 * WHAT IT DOES NOT CHANGE, and this is the load-bearing half: a value that FITS is returned
 * AS ITSELF, not as its string form. So an object still renders `[object Object]`, a number
 * still concatenates as a number, and `textContent = null` still renders empty rather than
 * the word "null". Only a value whose rendered form EXCEEDS the budget comes back as a
 * string, because at that point there is nothing to preserve. What a non-string element
 * MEANS in a chat payload stays #775's contract question; this answers only how long it may
 * be. And a value whose own `toString` throws is handed straight back, so the call site
 * throws exactly where it always threw instead of throwing one frame earlier here.
 *
 * THE SURROGATE GUARD is not decoration. `substring` cuts by code unit, so a cut can land
 * between a high and a low surrogate and leave a lone high surrogate as the last unit,
 * which renders as U+FFFD immediately before the ellipsis — a bound that produces
 * malformed display text on exactly the non-ASCII input it exists to contain. Dropping a
 * dangling high surrogate only shortens the result, so the budget still holds. The older
 * bounds in this file share the unguarded idiom; they are not changed here (#866).
 *
 * IT UNDOES A SPLIT; IT DOES NOT CLEAN THE INPUT, and the single `if` rather than a loop is
 * that distinction, not an oversight. If the text ALREADY contained lone surrogates, a cut
 * landing just after one still ends on one — and that character was malformed before this
 * function saw it. Walking backwards until the tail is well-formed would mean deleting
 * input characters the cut did not orphan, which is sanitizing, which #647 placed on the
 * server and forbade here. So the guarantee is exactly: this never SPLITS a well-formed
 * pair. Repairing malformed input is `_pp_clean_reflected_text()`'s job, upstream.
 *
 * @param  {*} text  A span the server supplied.
 * @return {*}       The value unchanged when its rendered form fits (or cannot be taken);
 *                   otherwise a string of at most PP_CHAT_REFLECTED_ERROR_MAX code units.
 */
function ppChatBoundReflectedText(text) {
    if (text === null || text === undefined) {
        return text;
    }

    var rendered;

    try {
        rendered = (typeof text === 'string') ? text : String(text);
    } catch (e) {
        // A throwing toString/valueOf, or a Symbol. The call site's own coercion throws on
        // these too; handing the value back keeps that failure where it already was.
        return text;
    }

    if (rendered.length <= PP_CHAT_REFLECTED_ERROR_MAX) {
        return text;
    }

    var cut  = rendered.substring(0, PP_CHAT_REFLECTED_ERROR_MAX - 3);
    var last = cut.charCodeAt(cut.length - 1);

    if (last >= 0xD800 && last <= 0xDBFF) {
        cut = cut.substring(0, cut.length - 1);
    }

    return cut + '...';
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
 *
 * BOTH HALVES ARE BOUNDED, SEPARATELY (#793), because both are server-supplied and
 * neither may starve the other. `message` has no server ceiling at all (see
 * PP_CHAT_REFLECTED_ERROR_MAX). The locator looks safer and is only half so: its `index`
 * is gated to a finite non-negative integer by ppChatFindingBand(), but its `type` is
 * tested only for being a NON-EMPTY string. Every one of the ~45 emitters in lib/admin.php
 * and lib/guardrails.php passes a string literal, which is what keeps a `type` short in
 * practice, and nothing on this side enforces it.
 *
 * SEPARATELY, NOT AS ONE COMPOSED SPAN, and the difference is display honesty rather than
 * arithmetic. Bounding `locator + message` as a single span would let an oversized `type`
 * consume the whole budget and cut the validator message away entirely — the row would
 * show a plausible-looking locator and hide the actionable half, which is the failure #793
 * exists to prevent wearing a different hat. Two spans mean a hostile `type` costs the
 * reader the locator and nothing else. The row is then at most twice the budget, which is
 * bounded, which is the property that matters.
 *
 * WHERE THIS FILE'S OWN PUNCTUATION LANDS, since the status sites answer it the other way.
 * There, `'Error: '` is theme prose sitting OUTSIDE the budget. Here the locator's glue —
 * `[`, `]`, ` index `, `: `, about two dozen characters — is INSIDE it, because it is not a
 * separable prefix: it is interleaved with `type`, and the span that has to be bounded is
 * whatever ppChatFindingLocator() returns. Two dozen characters against 4096 do not earn a
 * second budget to separate them.
 *
 * WHAT IS NOT FIXED HERE, so the #793 reference above is not read as a claim: this bounds
 * the locator's LENGTH and does nothing about the FORGED-locator half of the same issue — a
 * stored `message` containing the literal text `[invalid_prop_value] index 99:` still
 * renders as a convincing second locator mid-row. That half needs a rendering decision
 * (marking the card-generated locator as card-generated), not a bound, and is #867.
 */
function ppChatValidationItemRow(item, className) {
    var div = document.createElement('div');
    div.className = className;
    div.textContent = ppChatBoundReflectedText(ppChatFindingLocator(item))
        + ppChatBoundReflectedText(item.message);

    return div;
}

/**
 * Appends validation items (errors or warnings) to a container.
 * Shows up to 5 inline; collapses the rest in a <details> disclosure (D6).
 *
 * `className` is either a fixed class string (the post-apply validation paths, whose
 * items are already split into an errors list and a warnings list) or a function
 * (item) -> class for a list that carries its own per-item severity — the restore
 * findings (#622) and, since #855, the batch rollback report, whose adapted items carry a
 * severity derived from the server's `kind`. The rollback report used to be a fixed-class
 * caller with items shaped `{ message }` and all of them errors; #855 is what moved it,
 * because a withheld entry has to draw differently from a failed one. In the per-item form the disclosure
 * summary's noun is derived from the hidden items' own severities ("errors", "warnings",
 * or "issues" when they are mixed), never from the class string: calling a set that
 * contains errors "warnings" is the same misreport one level up.
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
 *
 * Since #755 the batch rollback report is a SECOND dependent on that never-pooled
 * behavior: its items are adapted strings carrying no `index` by construction, so every
 * one of them is unlocated and the five inline rows are five different entries. Pooling
 * the unlocated group would silently collapse a whole rollback report into one row.
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
                ppChatMarkStepsFailed(stepElements);
                // A missing-baseline mandate rejection (#404), a pre-exec conflict,
                // or an unreadable-composition refusal (#749) all arrive as a
                // structured payload — show the conflict affordance rather than
                // "[object Object]". The #749 refusal deliberately takes the generic
                // error-message path instead: re-reading the page cannot fix bytes
                // nobody can decode, so offering "Re-read & re-preview" would send
                // the operator round a loop that ends where it started.
                if (ppChatIsCompositionConflict(resp.data)
                    || (resp.data && resp.data.error_code === 'missing_expected_version')) {
                    // The payload travels so the card can say whether anything ran (#797).
                    // Both refusals here are pre-execution and carry no `steps`, which is
                    // exactly the evidence "Nothing was applied." rests on — passing them
                    // is what keeps that true sentence, rather than a default that would
                    // also hand it to an executed batch.
                    showConflictState(card, steps, pageId, resp.data);
                } else {
                    // The model's copy of the refusal, and the operator's way to spend it
                    // (#704). Both are gated on the server having written a note, which is
                    // how 'Permission denied.' and the malformed-request refusals get
                    // neither — see ppChatModelNote(). Card first, alert second (#755).
                    offerRepair(card, ppChatModelNote(resp.data));
                    // The 'Error: ' prefix is this file's prose and is not counted against
                    // the budget — the same rule PP_CHAT_RENDER_ERROR_MAX states for its
                    // own prefix. Only the server's span is bounded (#793).
                    addStatusMessage('Error: ' + ppChatBoundReflectedText((resp.data && resp.data.error) || resp.data || 'Unknown error'), true);
                }
                return;
            }

            var batch = resp.data; // { ok, steps: [...], failed_at, rolled_back, rollback_errors, rollback_error_kinds, versions }
            var applied = [];

            /*
             * EVIDENCE BEFORE ITERATION (#853). This function used to open by walking
             * `batch.steps`, which put a raw `.forEach` ahead of every exit below:
             *
             *   BEFORE                                AFTER
             *   ──────                                ─────
             *   batch.steps.forEach ──┐               stepsReadable?
             *                         │                 ├─ yes ─▶ forEach ──┐
             *   (throws on a          │                 └─ no  ─▶ (skip) ───┤
             *    non-list, and the    │                                     ▼
             *    chain's catch        │               absent, or unreadable+ok? ─▶ REFUSE
             *    renders err.message) │               ok?          ─▶ success
             *                         │               conflict?    ─▶ conflict card   [#797]
             *                         ▼               refused?     ─▶ up-front card   [#749]
             *              "Error: batch.steps         else        ─▶ failure + rollback report
             *               .forEach is not                                           [#755/#797]
             *               a function"
             *
             * A non-list `steps` (`{}`, `7`, `'a string'`, `null`) threw HERE, and the throw
             * lands in the chain's catch, which renders `err.message` straight into the
             * transcript. So every exit below — including the rollback report whose own
             * docblock exists to stop "a stack-shaped string" replacing it — was masked by
             * the one line that read the field deciding which of them runs.
             *
             * THE GUARD DOES NOT SHORT-CIRCUIT THE FAILURE EXITS, and that is the point. It
             * declines to make PER-STEP claims it has no evidence for; everything the envelope
             * says outside `steps` still routes normally, so a conflicting batch still gets
             * the conflict card, a #749 refusal still gets its own, and a rolled-back failure
             * still gets its rollback report — that report reads `rollback_errors`, not
             * `steps`, so it survives an unreadable envelope intact.
             *
             * THE SUCCESS EXIT IS THE ONE PLACE THAT MUST REFUSE. `applied` is filled by the
             * loop below, so an unreadable envelope would reach finalizeProposalSuccess() with
             * an EMPTY list and assert, in three places at once, an outcome nobody could read:
             * a post-apply card, an `[Applied changes: ]` turn written into the model's
             * context, and "Changes applied successfully." A refusal loses a true sentence
             * only if the envelope was honest; asserting success over bytes we cannot parse
             * is the failure this whole file is being hardened against.
             *
             * Same one-edit-away hazard as #755's channel, on the field one level up — see
             * ppChatBatchStepsReadable().
             */
            var stepsReadable = ppChatBatchStepsReadable(batch);

            if (stepsReadable) {
                batch.steps.forEach(function (stepResult, i) {
                    // The row and `steps[i]` are the same index into two lists the card built
                    // together, so an envelope carrying MORE results than the proposal had
                    // steps has neither a row to paint nor a step to attach a validation to.
                    // Indexing past either used to throw on this line (#853).
                    var stepEl = stepElements[i];
                    if (!stepEl) return;

                    stepEl.classList.remove('pp-ai-step-executing');
                    if (stepResult && stepResult.ok) {
                        stepEl.classList.add('pp-ai-step-done');
                        var step = steps[i];
                        step._validation = stepResult.validation || null;
                        step._staleWarnings = stepResult.stale_warnings || null;
                        applied.push(step);
                    } else {
                        stepEl.classList.add('pp-ai-step-failed');
                    }
                });
            }

            // TWO INDEPENDENT REASONS TO REFUSE, spelled as two arms so each one maps to its
            // own sentence (#853). There is no envelope to read at all; or there is one, and
            // it claims success over steps we cannot walk, which cannot be narrated as an
            // apply. Both leave the rows in the state the chain's catch has always given an
            // unknown outcome. Written envelope-first so neither arm leans on the predicate's
            // internal null guard to be correct.
            //
            // NOT-AN-OBJECT BELONGS IN THE FIRST ARM, NOT THE SECOND, and the reason is the
            // #749 exemption below. That exit is allowed to say "no step ran" on an unreadable
            // `steps` because `failed_at === null` is a fact the ENVELOPE states about itself.
            // A `resp.data` of `7` or `'boom'` states nothing: its `failed_at` is undefined,
            // which ppChatBatchWasRefusedUpFront() reads as the refusal shape, so a non-object
            // would walk out of here wearing #749's claim with nothing underneath it. Same
            // `typeof` test ppChatIsCompositionConflict() already applies to its own payload.
            if (!batch || typeof batch !== 'object' || (!stepsReadable && batch.ok)) {
                ppChatMarkStepsFailed(stepElements);
                // Same spelling the two other exits use for a failure whose reason the
                // envelope does not carry, bounded by the same helper (#793).
                addStatusMessage('Error: ' + ppChatBoundReflectedText((batch && batch.error) || 'Unknown error'), true);
                return;
            }

            // Steps after the failure point never ran at all — mark them
            // distinctly from a step that actually failed. Gated on a readable `steps`
            // for the same reason the loop above is: "these never ran" is a per-step
            // claim, and this envelope carries no per-step truth to support it (#853).
            if (stepsReadable && !batch.ok && batch.failed_at !== null) {
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
            // a blind retry (#404). The envelope goes with it: earlier steps in this
            // batch DID run, so whether the rollback got them all back is a question
            // only `rollback_errors` answers, and this card used to assert the answer
            // without asking (#797).
            if (ppChatBatchHitConflict(batch)) {
                showConflictState(card, steps, pageId, batch);
                return;
            }

            // A non-conflict rollback (e.g. a validation error at a later step) still
            // rewrites every snapshotted page's composition, which bumps its version —
            // leaving our stored baselines stale. Re-read the baseline for each touched
            // page so the next apply doesn't false-conflict against that churn (#404).
            refreshTouchedBaselines(steps);

            if (ppChatBatchWasRefusedUpFront(batch)) {
                // No step ran, so every step is skipped, not failed. The loop above painted
                // nothing either way — it walked an empty list, or #853 skipped it as
                // unreadable — and the skip pass is suppressed on both a null failed_at and
                // an unreadable `steps`, so this is the only thing that takes the rows out of
                // pp-ai-step-executing. Without it the card is left with every row still
                // spinning under an error line.
                //
                // AND IT KEEPS THAT CLAIM ON AN UNREADABLE ENVELOPE, which is the one place
                // this file paints "never ran" without per-step evidence — so the exemption is
                // stated rather than assumed (#853). The skip pass above is gated because
                // "the steps AFTER index N never ran" is only meaningful if the ones up to N
                // did, which is per-step reasoning. This claim rests on `failed_at === null`,
                // a fact the ENVELOPE states about itself, and ppChatBatchStepsReadable()
                // deliberately says nothing about the fields beside `steps`. Same evidence the
                // server discriminates on; no per-step truth borrowed.
                stepElements.forEach(function (el) {
                    el.classList.remove('pp-ai-step-executing');
                    el.classList.add('pp-ai-step-skipped');
                });
                offerRepair(card, ppChatModelNote(batch));
                addStatusMessage('Error: ' + ppChatBoundReflectedText(batch.error || 'Unknown error'), true);
                return;
            }

            // The revert claim is the ROLLBACK REPORT's to make, not this branch's (#755).
            // `rolled_back: true` was read here as "clean" for as long as this code has
            // existed, while the envelope's own docblock says it is not clean until
            // rollback_errors is checked — so a page whose restore was withheld, or a menu
            // item the restore could not recreate, was reported to the operator as fully
            // reverted. Both halves of the honest answer come from that one channel: the
            // sentence below, and the card section naming what stayed dirty.
            // ONE REPORT, BOTH SURFACES, AND THE CARD IS GROWN FIRST. addStatusMessage()
            // ends by pinning the transcript to its own bottom; the card is an EARLIER
            // sibling in that scroller, so growing it afterwards would push the alert
            // line back off the fold — the bigger the rollback failure, the further
            // off-screen its own warning. Append, then announce.
            var rollback = ppChatRollbackErrorReport(batch);

            // WITHOUT PER-STEP TRUTH THIS EXIT KEEPS ITS REPORT AND LOSES ONLY THE QUOTE
            // (#853). The rollback report above reads `rollback_errors`, never `steps`, so
            // the one thing this exit exists to deliver survives an unreadable envelope
            // whole; what cannot survive is the failing step's own words, because there is
            // no readable step to take them from.
            //
            // The rows are a separate question, and a broader one than readability: this is
            // the only failure exit that neither wipes the card nor paints every row, so it
            // is where a row the envelope did not account for would sit spinning. Sweeping
            // the unanswered ones covers both an unreadable `steps` and a readable list
            // shorter than `failed_at` — see ppChatFinishSpinningSteps().
            ppChatFinishSpinningSteps(stepElements);

            var failedResult = stepsReadable ? batch.steps[batch.failed_at] : null;
            var message = 'Error on step ' + (batch.failed_at + 1) + ': ' + ppChatBoundReflectedText((failedResult && failedResult.error) || 'Unknown error');
            message += ppChatRollbackSentence(rollback);
            ppChatAppendRollbackErrors(card, rollback);
            offerRepair(card, ppChatModelNote(batch));
            addStatusMessage(message, true);
        })
        .catch(function (err) {
            ppChatMarkStepsFailed(stepElements);
            addStatusMessage('Error: ' + err.message, true);
        });
    }

    /**
     * What the operator sends when they spend the repair affordance (#704).
     *
     * A REAL message, rendered in the transcript like any other, because the operator is
     * approving a request made in their name and has to be able to read it. It does not
     * restate the error: the note the server wrote is already the adjacent turn, carrying
     * the code, the blocking band and the reason at their authoritative length, and a
     * second, shorter retelling assembled here is exactly the drifting duplicate this
     * change exists to avoid.
     */
    var REPAIR_REQUEST = 'That proposal was rejected. Please correct the problem and propose the change again.';

    /**
     * Puts a refusal back in front of the model, and the retry in front of the OPERATOR
     * (#704, ruling D-2).
     *
     * The whole mechanism is these few lines, and the shape is the ruling:
     *
     *   note ──▶ conversation (hidden `user` turn)   the model KNOWS      ← mechanical
     *   note ──▶ card affordance                     the operator DECIDES ← gated
     *
     * ONE CALL SITE PER FAILURE EXIT, AND NO SITE MAY SEND. The append and the affordance
     * are deliberately not separable: an appended note with no affordance is context the
     * operator was never told about, and an affordance with no note is a button that asks
     * the model to fix something it cannot see. Both are gated on the same server-written
     * note, so a failure class the server judged not-the-model's gets neither, silently
     * and by construction.
     *
     * NOTHING BELOW SENDS ANYTHING. sendMessage() is reached only from the click handler,
     * which is the single line in this change that can reach the provider — that is what
     * makes "the retry is proposed, never automatic" a property of the code rather than a
     * claim in a comment. Reusing sendMessage() rather than a private send path is the
     * other half of it: the retry inherits the page-selection gate, the streaming lock and
     * the transcript render that every operator message already goes through.
     *
     * The turn is a `user` one on purpose, and the two reasons are independent.
     * PROVENANCE: this is the environment reporting an outcome, not the model speaking —
     * the sibling on the success path spells the same thing `[Applied changes: ...]`, and
     * an `assistant` turn would put a server verdict in the model's own mouth. DISPLAY:
     * restoreConversation() hides a user turn whose content starts with '[' and hides an
     * assistant turn on `internal: true`; the note is bracketed by its builder
     * (_pp_ai_rejection_note, lib/ai-chat.php) so the first rule catches it. `internal` is
     * set beside it as the structural statement of what this turn is — it never leaves the
     * browser (pp_ai_format_messages strips unknown keys), so it costs nothing and it is
     * what a future flag-based user-turn filter would read instead of the '[' convention.
     *
     * @param {HTMLElement}   card  The failed proposal's card.
     * @param {string|null}   note  ppChatModelNote()'s answer for this failure.
     */
    function offerRepair(card, note) {
        // BOTH guards up front, so the pairing above is a property of the code. The card
        // guard used to live inside ppChatAppendRepairAffordance(), which meant a cardless
        // call would push the note, persist it, and then quietly decline to tell the
        // operator about it — the exact half-state the docblock says cannot happen. Every
        // caller passes executeProposal()'s own `card`, so this is unreachable today; an
        // invariant worth stating is worth holding.
        if (!note || !card) return;

        conversation.push({ role: 'user', content: note, internal: true });
        saveState();

        // The return value is the affordance's, not ours to swallow: sendMessage() declines
        // while a stream is in flight, and the button gives itself back when it does.
        ppChatAppendRepairAffordance(card, function () {
            return sendMessage(REPAIR_REQUEST);
        });
    }

    /**
     * Composition-conflict state (#404): the page changed while the proposal was
     * pending. Renders one message and one affordance, Re-read & re-preview —
     * re-fetch the page's fresh baseline, then re-render the proposal so it
     * previews against current state and the user confirms again. Never a blind
     * retry that re-sends the stale write with a bumped version (that would turn
     * the CAS into an auto-overwrite button).
     *
     * WHAT THE ROLLBACK LEFT BEHIND IS PART OF THE STATE (#797). This renderer wipes the
     * card, so it is the one place a producible envelope's `rollback_errors` could reach a
     * surface and be discarded — and the sentence it drew in their place was the flat
     * "Nothing was applied." Both halves of the honest answer now come from the same
     * channel #755 wired into the executed-failure exit: the message's closing clause, and
     * the section naming which pages did not roll back and why.
     *
     * AND SPENDING THE AFFORDANCE NO LONGER DESTROYS THEM (#856). Both halves live on this
     * card and nowhere else — this exit writes no transcript line, the server writes no
     * model-facing note for the conflict class (_pp_ai_batch_rejection_note, lib/ai-chat.php
     * returns null for it), and no proposal card is persisted or replayed on reload
     * (restoreConversation() replays messages only). So `card.remove()` in the click handler
     * below was the single click that deleted the only copy of the report, and the card's
     * own button was what invited it. The handler now SPENDS the affordance instead of
     * deleting the card: the action row goes, the card stays where it happened, and the
     * fresh proposal renders beneath it — the transcript reading, top to bottom, as what it
     * is. Nothing else about the card changed. What it says and what it offers is a separate,
     * unruled question (#859), and this fix deliberately does not answer it.
     *
     * THE SURVIVAL RULE IS THE CLAIM RULE, one key for both. The card is kept exactly when
     * `report.reported > 0` — the same test ppChatConflictOutcome() uses to withhold
     * "Nothing was applied." and ppChatRollbackSentence() uses for its dirty clause. So the
     * card outlives its affordance exactly as long as it says something about a dirty
     * rollback, and no longer. Deliberately NOT `shown.length`, and the difference is the
     * arm worth naming: a report whose members are none of them renderable strings draws no
     * rows, but it still costs the clean claim (see ppChatRollbackErrorReport's `reported`
     * note), and that sentence is then the entire evidence there is. Keying on the rows
     * would delete the card in precisely the case where the message is all that survived.
     *
     * ONE MORE THING THE KEY IS NOT: the entries. `rollback_errors` is opaque here — counted
     * and filtered, never read — and since #855 those entries carry a `kind` beside them
     * (`rollback_error_kinds`). A new decider that peeked at their text, their order or their
     * kind is how that stops being additive here, the way tagging them stayed additive on the
     * server. Pinned as a source tripwire.
     *
     * An empty report earns none of this. A conflict that really did leave nothing behind has
     * nothing to preserve, and a spent card with no evidence on it would be residue sitting
     * above a proposal it has nothing to say about — so those keep today's behaviour exactly:
     * the card goes.
     *
     * ONE REPORT, BOTH SURFACES, inherited rather than re-derived. The channel is walked
     * once per render and the same answer feeds the message and the section, which is what
     * makes "the card cannot contradict the sentence" a property of the code. Reusing
     * ppChatAppendRollbackErrors() also means this exit invents no second vocabulary for
     * the same fact: same heading, same budget, same five-inline-plus-disclosure selection.
     *
     * Final DOM order, with the order each part is BUILT in — the two differ, and the
     * difference is the contract:
     *
     *   ┌ card (rebuilt) ───────────────────────────────────┐
     *   │ message   cause + outcome clause          built 1 │
     *   │ rollback disclosure, when there is one    built 3 │
     *   │ ┌ .pp-ai-proposal-actions ────────────────────┐   │
     *   │ │ Re-read & re-preview                        │   │  built 2
     *   │ └─────────────────────────────────────────────┘   │
     *   └───────────────────────────────────────────────────┘
     *
     * THE ACTION ROW IS BUILT FIRST SO THE DISCLOSURE CAN LAND ABOVE IT. That is why build
     * order 2 sits below DOM position 3: ppChatAppendRollbackErrors() places itself by
     * looking for `.pp-ai-proposal-actions` and inserting BEFORE it, falling back to
     * appendChild when it finds none. Append the two in reading order and the fallback
     * fires, dropping the explanation underneath the button it explains.
     *
     * And what the affordance leaves behind, once it has been spent (#856):
     *
     *   reported > 0                          reported === 0 (clean, or unknown)
     *   ┌ card (kept, spent) ───────────┐     (the card is removed, as it always was)
     *   │ message  cause + dirty clause │
     *   │ rollback disclosure, if drawn │
     *   └───────────────────────────────┘
     *   ┌ card (fresh proposal) ────────┐     ┌ card (fresh proposal) ────────┐
     *   │ steps, previews, Apply        │     │ steps, previews, Apply        │
     *   └───────────────────────────────┘     └───────────────────────────────┘
     *
     * @param {HTMLElement}   card     The proposal's card.
     * @param {Array}         steps    The proposal's steps, for the re-preview.
     * @param {number|string} pageId   The page to re-read.
     * @param {object|null}   payload  The batch envelope for an executed conflict, or the
     *                                 pre-execution error payload. Never optional: `null`
     *                                 is the VALUE meaning "no evidence". See
     *                                 ppChatConflictOutcome() for why an omitted one
     *                                 withholds the claim rather than asserting it.
     */
    function showConflictState(card, steps, pageId, payload) {
        card.innerHTML = '';
        card.classList.add('pp-ai-proposal-conflict');

        var rollback = ppChatRollbackErrorReport(payload);

        var msg = document.createElement('div');
        msg.className = 'pp-ai-status pp-ai-status-error';
        msg.setAttribute('role', 'alert');
        msg.textContent = ppChatConflictMessage(payload, rollback);
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
                // Re-render the proposal fresh: previews every step against current
                // state and shows Apply again, now backed by the refreshed baseline.
                //
                // FIRST, AND THE ORDER IS THE CONTRACT (#856). This is the half that can
                // throw — it builds a card, reads config, and fires a preview per step —
                // and the catch below answers a throw by handing the button back and
                // telling the operator to try again. Spend the affordance before it and
                // that instruction is addressed to a button that is no longer on the page.
                // (The executed-failure exit orders its own two surfaces deliberately too,
                // for a different reason: scroll position, "append, then announce". Same
                // care, not the same rule.)
                //
                // BE PRECISE ABOUT WHAT THIS DOES AND DOES NOT BUY, because the reachable
                // failure is the other one. A preview that comes back unsuccessful is
                // handled INSIDE renderProposal — it paints the failed step and returns
                // without an Apply row, never reaching this chain's catch — so the fresh
                // card can be unusable while this code sees success and spends the
                // affordance anyway. That dead end predates this change (the old order
                // reached it too, having already deleted the card) and is filed rather
                // than widened here.
                renderProposal({ proposal: true, steps: freshSteps }, pageId);

                // Then spend it. The card is the only place this exit's rollback report
                // ever exists, so it outlives its own affordance whenever the report said
                // something — see this function's docblock for why the key is the reported
                // COUNT and not the drawn rows.
                var reportedSomething = rollback.reported > 0;
                if (reportedSomething) {
                    actions.remove();
                } else {
                    card.remove();
                }
            }).catch(function () {
                rereadBtn.disabled = false;
                rereadBtn.textContent = 'Re-read & re-preview';
                addStatusMessage('Could not re-read the page. Try again.', true);
            });
        });
        actions.appendChild(rereadBtn);
        card.appendChild(actions);
        // After the action row exists, so the section lands above it rather than under the
        // button (#797). Draws nothing when the report is clean or unreadable.
        ppChatAppendRollbackErrors(card, rollback);
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
            // One activation, ever \u2014 the latch, not `pointer-events` (#861).
            ppChatOneShotLink(resetLink, 'Resetting\u2026', function () {
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
            // One activation, ever (#861). The stale-CAS double restore this closes is the
            // reason the helper exists — see its docblock for the measurement.
            ppChatOneShotLink(undoLink, 'Undoing…', function () {
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
                        // DELIBERATELY UNCHANGED, AND THE ASYMMETRY IS RECORDED RATHER THAN
                        // ACCIDENTAL (#822). This payload also carries an `error` sentence
                        // ("…Re-read the current composition and re-apply your change.
                        // [composition_conflict]") that this arm still discards. #822 is
                        // scoped to the branch that had NO sentence at all, and its own
                        // Expected names this arm as the register to match — so widening it
                        // here would be re-deciding a branch the issue treats as settled.
                        // The conflict class is also the one the batch surface answers with a
                        // whole affordance (showConflictState), not a line of prose, so what
                        // this card should offer is a design question rather than a rendering
                        // one. Filed, not folded in.
                        undoLink.textContent = 'Page changed — undo not applied';
                        undoLink.className = 'pp-ai-link-error';
                    } else {
                        // The label says the undo did not happen; the row beneath says why
                        // (#822). Every refusal that reaches here carries the server's
                        // sentence — including #818's, which is the only place an operator
                        // learns a repaired page's original bytes were PRESERVED and how to
                        // read them back. Discarding it here was discarding most of what
                        // #818 added.
                        undoLink.textContent = PP_CHAT_UNDO_FAILED_LABEL;
                        undoLink.className = 'pp-ai-link-error';
                        ppChatAppendUndoFailure(card, resp.data);
                    }
                })
                .catch(function () {
                    // NO ROW HERE, AND THAT IS THE POINT OF THE SPLIT. This arm is a
                    // transport failure — the request never came back with a payload, so
                    // there is no server message to render and the generic sentence is the
                    // whole truth available. Reaching for `resp` would be reaching for a
                    // variable that does not exist on this path. Pinned by the transport
                    // case in tests/e2e/ai-chat.spec.ts, which aborts the request and asserts
                    // this card draws NO refusal row, so the split cannot be "tidied" away.
                    undoLink.textContent = PP_CHAT_UNDO_FAILED_LABEL;
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

    /**
     * @return {boolean} True when a request was actually dispatched.
     *
     * REPORTS ITS OWN REFUSALS (#704). Every early return below is a legitimate refusal —
     * a stream already in flight, an empty message, no page selected — and each used to be
     * silent because the only caller was a button that stays available for another try.
     * The repair affordance is not that kind of caller: it disables itself on click, so a
     * silent refusal would leave the operator staring at a dead button with no way to ask
     * again and nothing on screen saying why. Answering the question the caller has to ask
     * ("did that go?") here keeps the guards single-owned; the alternative was re-testing
     * `isStreaming` at the call site, which is how two copies of one rule drift apart.
     * Existing callers ignore the value, which is exactly right for them.
     */
    function sendMessage(text) {
        if (isStreaming || !text.trim()) return false;

        var trimmed = text.trim();
        var pages = config.pages || [];
        var detectedPageId = ppChatDetectPageId(trimmed.toLowerCase(), pages);

        // The active page is explicit and user-controlled (issue 136) —
        // detection is a suggestion only, never an authority. Without an
        // explicit selection, block sending rather than proposing changes
        // against page_id: null (or silently reusing a stale prior target).
        if (!activePageId) {
            showPageSelectionPrompt(detectedPageId, pages);
            return false;
        }

        maybeShowPageSwitchSuggestion(detectedPageId, pages);

        setStreamingUiState(true);

        conversation.push({ role: 'user', content: trimmed });
        addMessage('user', trimmed);
        inputEl.value = '';
        saveState();

        var myRequestId = ++currentRequestId;
        streamChat(conversation, myRequestId);

        return true;
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

        // BOUNDED FOR DISPLAY ONLY (#793). The classification below reads `errorText`, not
        // this, and the order is load-bearing: a provider can return a multi-megabyte error
        // body (pp_ai_parse_error_response() tag-strips it and bounds nothing), and if the
        // phrase that earns the Connectors link sits past the cut, testing the truncated
        // copy would silently drop the one affordance that fixes the problem being reported.
        // Bound what is shown; classify on what arrived.
        msgBody.textContent = ppChatBoundReflectedText(errorText);

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
        undoFailureText: ppChatUndoFailureText,
        appendUndoFailure: ppChatAppendUndoFailure,
        oneShotLink: ppChatOneShotLink,
        LINK_SPENT_ATTR: PP_CHAT_LINK_SPENT_ATTR,
        boundReflectedText: ppChatBoundReflectedText,
        REFLECTED_ERROR_MAX: PP_CHAT_REFLECTED_ERROR_MAX,
        UNDO_ERROR_MAX: PP_CHAT_UNDO_ERROR_MAX,
        UNDO_FAILED_LABEL: PP_CHAT_UNDO_FAILED_LABEL,
        UNDO_FAILURE_CLASS: PP_CHAT_UNDO_FAILURE_CLASS,
        findingClass: ppChatFindingClass,
        findingBand: ppChatFindingBand,
        findingLocator: ppChatFindingLocator,
        undoFindingsTail: ppChatUndoFindingsTail,
        buildCompositionSummary: ppChatBuildCompositionSummary,
        isUnreadableComposition: ppChatIsUnreadableComposition,
        detectPageId: ppChatDetectPageId,
        findPageById: ppChatFindPageById,
        shouldSuggestPageSwitch: ppChatShouldSuggestPageSwitch,
        buildBatchBaselines: ppChatBuildBatchBaselines,
        applyVersionMap: ppChatApplyVersionMap,
        isCompositionConflict: ppChatIsCompositionConflict,
        batchHitConflict: ppChatBatchHitConflict,
        batchWasRefusedUpFront: ppChatBatchWasRefusedUpFront,
        batchStepsReadable: ppChatBatchStepsReadable,
        markStepsFailed: ppChatMarkStepsFailed,
        conflictMessage: ppChatConflictMessage,
        conflictOutcome: ppChatConflictOutcome,
        rollbackErrorReport: ppChatRollbackErrorReport,
        rollbackSentence: ppChatRollbackSentence,
        appendRollbackErrors: ppChatAppendRollbackErrors,
        rollbackRowClass: ppChatRollbackRowClass,
        ROLLBACK_KIND_WITHHELD: PP_CHAT_ROLLBACK_KIND_WITHHELD,
        modelNote: ppChatModelNote,
        appendRepairAffordance: ppChatAppendRepairAffordance,
        ROLLBACK_ERRORS_MAX: PP_CHAT_ROLLBACK_ERRORS_MAX
    };
}
