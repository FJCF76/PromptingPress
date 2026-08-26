<?php
/**
 * lib/operate.php — Agent Operating Framework
 *
 * Defines the 8-step operating loop, site inspection, preflight hardening,
 * drift detection, playbook checklists, and loop run validation.
 *
 * @since 0.3.0
 */

// ── Loop Definition ────────────────────────────────────────────────────────

/**
 * Returns the 8-step operating loop definition.
 *
 * Each step has: phase, required_inputs, required_outputs.
 * The loop is validated post-hoc by pp_validate_loop_run().
 *
 * @return array[]
 */
function pp_operate_loop_steps(): array {
    return [
        'INSPECT' => [
            'phase'            => 'strategist',
            'required_inputs'  => [],
            'required_outputs' => ['site_state'],
        ],
        'PLAN' => [
            'phase'            => 'strategist',
            'required_inputs'  => ['site_state'],
            'required_outputs' => ['mutation_plan'],
        ],
        // PREFLIGHT precedes EDIT (#96): the safety gate runs before any DB-backed
        // mutation, not after it. EDIT and APPLY both require preflight_result.
        'PREFLIGHT' => [
            'phase'            => 'operator',
            'required_inputs'  => ['mutation_plan'],
            'required_outputs' => ['preflight_result'],
        ],
        // EDIT's preflight_result input governs an AUTHORED LOOP MANIFEST, which is what
        // pp_validate_loop_run() checks — not what any single command enforces. Worth
        // saying since #767: a corrupt-page repair is deliberately a preflight-free
        // `wp pp action execute`, so a run consisting of one is not a loop and this row is
        // not the gate it bypasses (the gates it lifts are the CLI's coverage (#96) and
        // freshness (#113) checks, enumerated on pp_corrupt_page_repair_carve_out()).
        // Do not "reconcile" the two by relaxing this row.
        'EDIT' => [
            'phase'            => 'implementer',
            'required_inputs'  => ['preflight_result'],
            'required_outputs' => ['edit_result'],
        ],
        'APPLY' => [
            'phase'            => 'operator',
            'required_inputs'  => ['preflight_result'],
            'required_outputs' => ['apply_result'],
        ],
        'SCREENSHOT' => [
            'phase'            => 'reviewer',
            'required_inputs'  => ['apply_result'],
            'required_outputs' => ['screenshot_result'],
        ],
        'REVIEW' => [
            'phase'            => 'reviewer',
            'required_inputs'  => ['screenshot_result'],
            'required_outputs' => ['review_result'],
        ],
        'HANDOFF' => [
            'phase'            => 'operator',
            'required_inputs'  => ['review_result'],
            'required_outputs' => ['handoff_report'],
        ],
    ];
}

// ── Drift Detection ────────────────────────────────────────────────────────

/**
 * Checks for drift between live theme files and the deployment manifest.
 *
 * READ-ONLY: does NOT auto-create a baseline manifest when none exists.
 * If no manifest is found, returns no-drift. Establishing a baseline is the
 * job of the explicit `wp pp sync check --save-manifest` or
 * `wp pp readiness rebaseline` commands (#522) — never a side effect of a
 * read.
 *
 * @return array{has_drift: bool, modified: string[], added: string[], deleted: string[]}
 */
function pp_check_drift(): array {
    $no_drift = [
        'has_drift' => false,
        'modified'  => [],
        'added'     => [],
        'deleted'   => [],
    ];

    $target = pp_get_target();
    $theme_path = $target['theme_path'];

    if ($theme_path === null || !is_dir($theme_path)) {
        return $no_drift;
    }

    // Guard: unreadable theme directory
    if (!is_readable($theme_path)) {
        return [
            'has_drift' => false,
            'modified'  => [],
            'added'     => [],
            'deleted'   => [],
            'error'     => 'Theme directory is not readable: ' . $theme_path,
        ];
    }

    $manifest = _pp_load_deployment_manifest();
    if ($manifest === null) {
        // No manifest = no baseline to compare against. Read-only: do not create one.
        return $no_drift;
    }

    $current_hashes = _pp_hash_theme_files($theme_path);
    $manifest_hashes = $manifest['file_hashes'];

    $modified = [];
    $added    = [];
    $deleted  = [];

    foreach ($current_hashes as $file => $hash) {
        if (!isset($manifest_hashes[$file])) {
            $added[] = $file;
        } elseif ($manifest_hashes[$file] !== $hash) {
            $modified[] = $file;
        }
    }

    foreach ($manifest_hashes as $file => $hash) {
        if (!isset($current_hashes[$file])) {
            $deleted[] = $file;
        }
    }

    $has_drift = !empty($modified) || !empty($added) || !empty($deleted);

    return [
        'has_drift' => $has_drift,
        'modified'  => $modified,
        'added'     => $added,
        'deleted'   => $deleted,
        // The release the baseline was captured against (#496), so a drift finding
        // can say "changed since <release>". Null on manifests written before #496.
        'release_version' => $manifest['release_version'] ?? null,
    ];
}

// ── Finding classification (#496) ──────────────────────────────────────────
//
// Readiness/preflight warnings are undifferentiated by default: theme-file
// drift, site-configuration gaps, and missing environment tools all surface as
// look-alike warning rows that persist run after run. An operator who cannot
// tell "changed since the installed release" from "the footer has no menu" from
// "no browser installed" learns to ignore ALL warnings — which masks the one
// that matters (#302 reported-state trust class).
//
// Every finding therefore carries a CLASS and a sanctioned NEXT ACTION:
//   - integrity     : theme file drift vs the recorded deployment baseline.
//                     Resolved by re-baselining (`wp pp readiness rebaseline`).
//   - configuration : site-state gaps resolvable through their existing safe
//                     surfaces (e.g. an unassigned menu location). ALSO
//                     acknowledgeable — an operator can record "intentional",
//                     after which the finding reports as acknowledged, not a
//                     warning, reversibly.
//   - capability    : environment tools missing (e.g. a screenshot browser,
//                     #497). Resolved by installing/configuring the tool.
//
// Only CLASSED rows are findings. Passing/healthy rows (no drift, a ready menu
// location, a ready capture) carry no class — there is nothing to group or act
// on. Preconditions/gates (target, capability-to-mutate, surface, …) are not
// findings either: they either pass silently or hard-block the whole operation
// with their own message.
//
// Only CONFIGURATION findings are acknowledgeable. Integrity drift is resolved
// by re-baselining and capability gaps by installing the tool — neither is a
// deliberate site-state choice, so neither can be "marked intentional".

/**
 * The stored map of acknowledged findings (#496).
 *
 * Shape: finding_key => ['acknowledged_at' => ISO8601, 'note' => string].
 * READ-ONLY accessor — never writes. The explicit `wp pp readiness acknowledge`
 * / `unacknowledge` commands are the only writers, honoring the standing
 * read-only-status rule (status/preflight never mutate).
 *
 * @return array
 */
function pp_acknowledged_findings(): array {
    $stored = get_option('pp_acknowledged_findings', []);
    return is_array($stored) ? $stored : [];
}

/**
 * Stamps `acknowledged` (+ note/timestamp) onto configuration findings whose
 * finding_key is recorded in pp_acknowledged_findings() (#496).
 *
 * Central, single-pass enrichment so acknowledgement logic lives in ONE place
 * rather than being scattered across each finding source. Pure w.r.t. the
 * option store (reads once); returns a new checks array.
 *
 * @param array $checks  Preflight/readiness check rows.
 * @return array         The same rows, configuration findings stamped.
 */
function pp_apply_finding_acknowledgements(array $checks): array {
    $acks = pp_acknowledged_findings();
    foreach ($checks as &$check) {
        // Only configuration findings are acknowledgeable, and only rows that
        // actually carry a finding_key (the actionable, pass=false ones).
        if (($check['class'] ?? '') !== 'configuration') {
            continue;
        }
        $key = (string) ($check['finding_key'] ?? '');
        if ($key !== '' && isset($acks[$key]) && is_array($acks[$key])) {
            $check['acknowledged']      = true;
            $check['acknowledged_note'] = (string) ($acks[$key]['note'] ?? '');
            $check['acknowledged_at']   = (string) ($acks[$key]['acknowledged_at'] ?? '');
        }
    }
    unset($check);
    return $checks;
}

/**
 * Groups classed findings by class and summarizes the trust surface (#496).
 *
 * A CLASSED row is a finding; an ACTIVE finding is a classed row that is not
 * acknowledged. Every active finding carries a `next_action`, so "zero
 * unexplained warnings" on a completed operation means active_warnings rows that
 * are all actionable-now, plus any acknowledged (intentional) findings.
 *
 * Pure — no I/O. Feed it acknowledgement-enriched checks.
 *
 * @param array $checks  Preflight/readiness check rows (post-enrichment).
 * @return array{by_class: array<string, array>, active_warnings: int, acknowledged: int}
 */
function pp_classify_findings(array $checks): array {
    $by_class = ['integrity' => [], 'configuration' => [], 'capability' => []];

    foreach ($checks as $check) {
        $class = $check['class'] ?? null;
        if ($class === null || !isset($by_class[$class])) {
            continue;
        }
        $by_class[$class][] = [
            'check'            => $check['check'] ?? '',
            'pass'             => (bool) ($check['pass'] ?? false),
            // Tri-state capability sub-state (#497), e.g. screenshot readiness
            // 'unavailable' vs 'broken'. Null for findings that carry no sub-state.
            'state'            => $check['state'] ?? null,
            'acknowledgeable'  => (bool) ($check['acknowledgeable'] ?? false),
            'acknowledged'     => (bool) ($check['acknowledged'] ?? false),
            // Surface the operator's recorded rationale in the read surface — it
            // is captured at acknowledge time and would otherwise be write-only.
            'acknowledged_note' => $check['acknowledged_note'] ?? '',
            'acknowledged_at'   => $check['acknowledged_at'] ?? '',
            'finding_key'      => $check['finding_key'] ?? null,
            'next_action'      => $check['next_action'] ?? null,
            'message'          => $check['message'] ?? '',
        ];
    }

    $active = 0;
    $acknowledged = 0;
    foreach ($by_class as $rows) {
        foreach ($rows as $row) {
            if ($row['acknowledged']) {
                $acknowledged++;
            } else {
                $active++;
            }
        }
    }

    return [
        'by_class'        => $by_class,
        'active_warnings' => $active,
        'acknowledged'    => $acknowledged,
    ];
}

/**
 * The configuration finding_keys that are CURRENTLY present (#496).
 *
 * `wp pp readiness acknowledge` validates against this so an operator can only
 * acknowledge a finding that actually exists — preventing typo'd keys and
 * unbounded growth of the acknowledgement store.
 *
 * SINGLE SOURCE OF TRUTH: derived from the SAME classified path `wp pp readiness
 * status` prints (pp_preflight → findings.by_class.configuration), so the set an
 * operator can acknowledge can never diverge from the set they see. Any future
 * configuration producer wired into preflight is automatically acknowledgeable
 * with no second list to update. Stays generic (keys come from the producers,
 * no site specifics baked in).
 *
 * @return string[]  Distinct configuration finding_keys currently present.
 */
function pp_current_configuration_finding_keys(): array {
    $configuration = pp_preflight([])['findings']['by_class']['configuration'] ?? [];
    $keys = [];
    foreach ($configuration as $row) {
        if (!empty($row['finding_key'])) {
            $keys[(string) $row['finding_key']] = true;
        }
    }
    return array_keys($keys);
}

// ── Site Inspection ────────────────────────────────────────────────────────

/**
 * Returns the full operating picture for the INSPECT step.
 *
 * Single call that gives the agent everything it needs before touching anything.
 * The drift result is computed once and shared with preflight to avoid
 * redundant file hashing.
 *
 * @param int|null $post_id  Optional post ID for page-specific smells.
 * @return array
 */
function pp_inspect_site(?int $post_id = null): array {
    $drift = pp_check_drift();

    $smells = [];
    $composition_decode_error = null;
    if ($post_id !== null) {
        // Read through the classifying accessor: normal storage is a JSON string
        // (pp_update_composition), so a raw get_post_meta + is_array() check
        // always reports empty for real pages. See lib/operate.php preflight
        // check 6 for the same pattern. A corrupt/undecodable row is surfaced as
        // composition_decode_error rather than masquerading as a clean, blank
        // page (issue #144) — an agent relying on INSPECT before a mutation must
        // be warned about data corruption instead of seeing smells: [].
        $result = pp_get_composition_result($post_id);
        if (!$result['ok']) {
            $composition_decode_error = $result['error'];
        } elseif (!empty($result['composition'])) {
            $smells = pp_validate_composition_smells($result['composition']);
        }
    }

    return [
        'target'                   => pp_get_target(),
        'pages'                    => pp_composition_pages(),
        'drift'                    => $drift,
        'preflight'                => pp_preflight([], $drift),
        'tokens'                   => pp_design_tokens(),
        'conflicts'                => pp_check_custom_css_conflicts(),
        'smells'                   => $smells,
        // #386: base/derived token incoherence — a derived-family override that
        // diverges from what its base currently derives, so a base-token change
        // won't be visible where the override applies. Caught here at INSPECT, not
        // only at APPLY. Empty on a coherently themed site.
        'token_smells'             => pp_detect_masked_derived_smells(),
        'composition_decode_error' => $composition_decode_error,
    ];
}

// ── Preflight ──────────────────────────────────────────────────────────────

/**
 * Composition-presence precondition for a page/section action (#358).
 *
 * Preflight's target_page check (Check 6) accepts any EXISTING page as a valid
 * target, because preflight runs once per (run, post_id) and cannot know which
 * action a run will execute against that coverage. This is the other half: the
 * per-action gate that DOES know the action, so it can distinguish:
 *
 *   - Component-level actions (add_component, remove_component, reorder_components,
 *     update_component, style_component) act ON existing components, so they REQUIRE
 *     a non-empty composition. Gated here → fail-closed on a composition-less page.
 *   - Populate / lifecycle / metadata actions (update_composition, trash_page,
 *     publish_page, restore_composition, update_page_title, ...) need only the PAGE
 *     to exist. Gating them on a non-empty composition strands a page created empty
 *     by create_page — it could be neither populated NOR deleted through the
 *     operate surface. These opt out via 'requires_composition' => false.
 *
 * DECLARATIVE + FAIL-CLOSED: the requirement is read from the action's
 * 'requires_composition' flag, which pp_register_action() defaults to TRUE. An
 * un-annotated action stays gated; an action opts out only by explicitly setting
 * the flag false. The inline default here (treat a missing key as "requires")
 * mirrors that default so a hand-built action array (e.g. a test fixture) is also
 * fail-closed.
 *
 * Site-scoped actions carry no post_id and are not composition-targeted; this is a
 * no-op for them ($post_id === null → true).
 *
 * A CORRUPT PAGE IS NOT AN EMPTY ONE (#748). This gate used to read through
 * pp_get_composition(), which degrades an unreadable stored row to `[]` (lib/wp.php),
 * so all SIX surfaces it guards — add_component, remove_component, reorder_components,
 * update_component, style_component (via pp_validate_action, lib/actions.php) and
 * `wp pp operate patch` (via pp_patch_composition() below) — told the caller the page
 * "has none yet. Populate it first with update_composition". False for a page whose
 * `_pp_composition` decodes to a JSON object or does not decode at all: it HAS a
 * composition, and an agent that believes the page is blank authors over the
 * recoverable bytes. Exactly the wrong conclusion #725 removed from the read path,
 * reached through a different door.
 *
 * It now reads through pp_get_composition_result(), the single decode+classify owner,
 * and says which of the two states it found, in the vocabulary every other surface
 * already uses (#650/#652: one state, one noun set):
 *
 *   stored value                     gate    message
 *   ───────────────────────────────  ──────  ────────────────────────────────────────
 *   non-empty valid list             OPEN    —
 *   absent / blank / `[]`            CLOSED  "has none yet. Populate it first ..."
 *   object, scalar, undecodable      CLOSED  the shared integrity sentence, naming
 *                                            `unexpected_shape` / `decode_error`
 *
 * WHICH INPUTS OPEN THE GATE IS UNCHANGED, by construction rather than by care:
 * pp_get_composition() IS `pp_get_composition_result($post_id)['composition']`
 * (lib/wp.php), and a `!ok` classification always carries `composition => []`, so the
 * old `!empty(pp_get_composition(...))` test and the new one are the same predicate on
 * every input. This issue changes what the refusal SAYS, never when it fires — the
 * fail-closed contract of #358/#387 is untouched and pinned as such.
 *
 * The error CODE stays `composition_required` on both closed branches. The ruling on
 * #748 is message-level; `error_code` is documented machine-facing surface for these six
 * actions (AI_CONTEXT.md), and a caller keying on it must not have to relearn the gate to
 * read a better sentence. The classification travels in the message, where the shared
 * builder owns its spelling. (Deliberate divergence from #749's batch refusal, which
 * returns the classification AS its code — that surface had no prior code to preserve.)
 *
 * THE MESSAGE NOW PRESCRIBES A REPAIR ROUTE, and the flip is deliberate (#767). It named
 * none between #748 and #767 because the only route it could have named was circular on
 * the CLI: `apply preflight` failed closed on a corrupt page while `action execute
 * update_composition` refused for want of preflight coverage, so the two instructions
 * pointed at each other. Naming a route that does not run is worse than naming none.
 * Maintainer ruling D-1 (#767) closed that loop — see pp_corrupt_page_repair_carve_out()
 * below — so the route exists and this gate names it. The precondition for the silence is
 * gone, so the silence goes too: an operator refused HERE is, by construction, on a page
 * the carve-out was written for.
 *
 * AND THE BREADCRUMB PROMISES ONLY WHAT IT DELIVERS. On this state `wp pp check page`
 * prints THIS SAME SENTENCE and returns (lib/cli.php) — there is no fuller report to send
 * anyone to, because no surface can list the bands of a composition that will not parse.
 * So the tail says the command "reports the same classification against the stored page",
 * which is what an operator gets: independent confirmation against stored bytes, not more
 * detail. An earlier draft said "for the full report" and was a tautology dressed as a
 * next step.
 *
 * DIVERGENCE RESOLVED (#767). `pp_inspect_composition()` (#725, below in this file) ended
 * its report on the same state with "Repair the page with a full update_composition write
 * ... or restore_composition" while this gate named no route at all — the two surfaces
 * agreed on what the state IS (both take the diagnosis from
 * pp_composition_integrity_message(), the vocabulary #650/#652 cares about) and disagreed
 * on what to DO. Both now name the same route, and it is one that runs.
 *
 * @param array    $action  The registered action definition.
 * @param int|null $post_id The target post ID (null for site-scoped actions).
 * @return true|WP_Error  WP_Error('composition_required', ...) when the gate is closed.
 */
function pp_action_composition_precondition(array $action, ?int $post_id) {
    if ($post_id === null) {
        return true; // site-scoped: no composition target to require
    }
    // Fail-closed: a missing flag means "requires a composition".
    $requires = !array_key_exists('requires_composition', $action)
        || $action['requires_composition'] !== false;
    if (!$requires) {
        return true;
    }
    $stored = pp_get_composition_result($post_id);
    if (!$stored['ok']) {
        // Unreadable, not absent. Diagnosis from the single owner (#725), tail local
        // because only the tail is specific to "the action you asked for was refused".
        return new WP_Error(
            'composition_required',
            pp_composition_integrity_message($post_id, $stored['error'])
            . sprintf(
                ' Action "%s" operates on an existing composition, so it was refused and'
                . ' the stored bytes were left untouched. `wp pp check page --post_id=%d`'
                . ' reports the same classification against the stored page. ',
                $action['name'] ?? '?',
                $post_id
            )
            . pp_corrupt_repair_route_message($post_id)
        );
    }
    if (!empty($stored['composition'])) {
        return true;
    }
    return new WP_Error(
        'composition_required',
        sprintf(
            'Action "%s" operates on an existing composition, but post %d has none yet. Populate it first with update_composition (or restore it), then retry.',
            $action['name'] ?? '?',
            $post_id
        )
    );
}

/**
 * The corrupt-page repair carve-out (#767 — maintainer ruling D-1, 2026-08-26).
 *
 * THE LOOP IT BREAKS. On a page whose stored `_pp_composition` is classified corrupt, the
 * WP-CLI repair route was circular. `wp pp apply preflight --post_id=N` fails closed on
 * exactly this state ("the stored composition is <classification> ... Repair the post's
 * composition before re-running"), while `wp pp action execute update_composition
 * --post_id=N` refuses for want of preflight coverage ("no completed PREFLIGHT covering
 * post N. ... Run `wp pp apply preflight`"). Each instruction pointed at the other, so the
 * only pages the gates could not preflight were also the only pages the CLI could not
 * repair — stranding every SSH/chat-first operator on the dashboard editor.
 *
 * THE RULING, quoted, because its narrowness is the whole design:
 *
 *   "APPROVED: the narrow corrupt-page repair carve-out. Scope, verbatim-tight: (1) applies
 *    ONLY when the stored composition is ALREADY classified corrupt (the unexpected_shape/
 *    decode_error read-path classification); (2) ONLY for the two whole-composition verbs
 *    `update_composition` / `restore_composition`; (3) the incoming replacement STILL
 *    passes full validation before write. This is NOT a general preflight bypass — it is
 *    an escape hatch for the exact class of pages the current gates cannot preflight."
 *
 * Conditions (1) and (2) are THIS predicate. Condition (3) is not, and must not be: it is
 * satisfied by gates that were already there and that this carve-out deliberately does not
 * touch. pp_validate_action() runs the action's own validate callable BEFORE the CLI reaches
 * any preflight gate, so nothing about what a verb accepts changes here.
 *
 * READ CONDITION (3) AS "STILL", NOT AS "NEWLY", and the distinction is load-bearing because
 * the two verbs do not validate alike:
 *
 *   update_composition   full pp_validate_composition() of the incoming array, unchanged.
 *   restore_composition  the #233 contract, unchanged: it replays a snapshot VERBATIM and
 *                        REPORTS rule violations in `findings` rather than blocking. Its one
 *                        precondition is #818's `history_entry_not_restorable` on a
 *                        preserved-bytes ring slot, which is not a validation veto either.
 *
 * A literal reading of condition (3) — "make restore validate its snapshot on this path" —
 * would break the invariant #233 exists for: restore is never blocked by current validation
 * rules, because undo is wired to it and a restore that current rules refuse would fail
 * exactly when a user most needs it. The ruling names `restore_composition` in condition (2)
 * knowing that, so condition (3) is read as "the carve-out changes nothing about what any
 * verb validates" — which is what it does. Nothing here can write a composition that the
 * same verb would not have written on a preflighted page; the ONLY thing removed is the
 * preflight requirement.
 *
 * SHARED, NOT CLI-LOCAL, on purpose: #756 admits the same carve-out at the chat batch
 * refusal, and one classification predicate is the only way the two surfaces cannot drift
 * on which pages are repairable.
 *
 * WHAT THIS PREDICATE DOES NOT KNOW, and the caller therefore still owns. It answers one
 * question — "is this the corrupt-page repair case?" — and nothing about the REQUEST. Run
 * tokens, the INSPECT-first ordering, capabilities, nonces, request provenance: every
 * caller keeps enforcing its own, and a `true` here is never permission to skip them. The
 * CLI's admission (lib/cli.php) lifts the preflight COVERAGE (#96) and FRESHNESS (#113)
 * gates and nothing else.
 *
 * THE READ IS AUTHORITATIVE, not cached, and that is a security property rather than a
 * detail. This predicate's answer OPENS a gate, so a stale value must not be able to open
 * it — see pp_get_composition_result_authoritative() (lib/wp.php) for the mechanism and
 * the cache-warming path (#823) that makes staleness reachable rather than theoretical.
 *
 * IT IS A POINT-IN-TIME ANSWER. Between this check and the write, another writer can
 * repair the page; the carve-out caller closes that window by threading the pre-write
 * composition version as `expected_version`, so pp_update_composition()'s in-lock
 * compare-and-swap rejects the write if the page stopped being corrupt underneath it.
 * A carve-out admission is not a licence to overwrite a healthy composition.
 *
 * @param array    $action  The REGISTERED action definition (its 'name' is the registry's
 *                          own key, never a raw user string — pp_get_action() is an exact
 *                          lookup with no alias or normalization surface, #603/#604/#606).
 * @param int|null $post_id The target post ID, or null for a site-scoped action.
 * @return string|null  The classification ('unexpected_shape' | 'decode_error') when the
 *                      carve-out applies; null when it does not.
 */
function pp_corrupt_page_repair_carve_out(array $action, ?int $post_id): ?string {
    if ($post_id === null) {
        return null; // site-scoped: no stored composition to be corrupt
    }

    // Condition (2), checked before the read so the non-carve-out path costs no query.
    // A CLOSED allowlist of the two whole-composition verbs: every other action either
    // edits bands inside a composition the reader cannot produce, or does not touch the
    // composition at all. Neither can repair the page, so neither gets the hatch.
    $name = $action['name'] ?? '';
    if (!in_array($name, ['update_composition', 'restore_composition'], true)) {
        return null;
    }

    // FAIL CLOSED WHEN THE READ CANNOT BE AUTHORITATIVE. pp_get_composition_result_
    // authoritative() degrades to the cached read with no usable $wpdb handle, which is the
    // right answer for a READER and the wrong one for a GATE: the cached value is precisely
    // the thing that might be stale, and trusting it here would reintroduce the hazard the
    // authoritative read exists to remove. No database to ask means no carve-out. In
    // production every clause of pp_composition_db_handle() always holds, so this branch is
    // a unit-context and misconfiguration guard, not an operator-facing one.
    if (pp_composition_db_handle() === null) {
        return null;
    }

    // Condition (1). Blank is NOT corrupt: an absent or empty `_pp_composition` classifies
    // ok=true with composition=[], so a never-populated page keeps needing a preflight like
    // any other healthy page.
    $stored = pp_get_composition_result_authoritative($post_id);
    if ($stored['ok']) {
        return null;
    }

    // ALLOWLIST, NOT `!ok`, and the difference is the whole fail-closed posture. The ruling
    // names two classifications; today they are the only two the classifier can produce, so
    // `return $stored['error']` would behave identically — and would silently promote any
    // classification added LATER into a preflight bypass nobody ruled on. A gate that widens
    // itself when someone else extends an enum is not a narrow carve-out. Adding a
    // classification here must be a deliberate act.
    if (!in_array($stored['error'], ['unexpected_shape', 'decode_error'], true)) {
        return null;
    }

    return $stored['error'];
}

/**
 * Runs all preflight checks before an apply mutation.
 *
 * @param array      $context  Playbook context: 'planned_files', 'post_id', 'apply_name'.
 * @param array|null $drift    Pre-computed pp_check_drift() result to avoid redundant hashing.
 * @return array{ok: bool, checks: array[]}
 */
function pp_preflight(array $context = [], ?array $drift = null): array {
    $checks = [];

    // Check 1: Target resolved
    $target = pp_get_target();
    $missing = array_keys(array_filter($target, fn($v) => $v === null));
    if (empty($missing)) {
        $checks[] = ['check' => 'target', 'pass' => true, 'message' => 'Target resolved: ' . $target['site_url']];
    } else {
        $checks[] = ['check' => 'target', 'pass' => false, 'message' => 'Target not fully resolved. Missing: ' . implode(', ', $missing)];
    }

    // Check 2: Capability
    if (defined('WP_CLI') && WP_CLI) {
        $checks[] = ['check' => 'capability', 'pass' => true, 'message' => 'WP-CLI context: capability gate bypassed.'];
    } elseif (current_user_can('manage_options')) {
        $checks[] = ['check' => 'capability', 'pass' => true, 'message' => 'User has manage_options capability.'];
    } else {
        $checks[] = ['check' => 'capability', 'pass' => false, 'message' => 'Missing manage_options capability.'];
    }

    // Check 3: Drift (backup writability check removed — token storage is database-backed)
    if ($drift === null) {
        $drift = pp_check_drift();
    }

    // Resolve the planned apply's target type once — it routes the
    // planned_files auto-population and the filesystem checks below. Normalize the
    // supplied name to a string and treat "provided" as any non-empty string, NOT
    // empty() — PHP's empty('0') is true, so an !empty() gate would silently let the
    // literal apply name "0" (and a bare --apply that WP-CLI coerces to bool true)
    // slip past the apply_known guard below as "no apply planned." No registered
    // apply is named "0", so any provided value that fails the registry lookup must
    // fail closed (issue 245).
    $apply_name        = isset($context['apply_name']) ? (string) $context['apply_name'] : '';
    $apply_def         = $apply_name !== '' ? pp_get_apply($apply_name) : null;
    $apply_target_type = $apply_def !== null && isset($apply_def['target']['type']) ? $apply_def['target']['type'] : null;

    // Apply name known (issue 245): a provided --apply that names no registered
    // apply must FAIL preflight closed. Left unguarded, an unknown/typo'd name
    // (e.g. import_medai) resolves $apply_def to null and is treated exactly like
    // "no apply planned": the apply-routed filesystem checks are skipped, preflight
    // passes, and PREFLIGHT is recorded — a green gate asserting a precondition
    // ("no filesystem writes") the operator never earned. This is the same
    // false-pass class as the fail-closed preflight trilogy (#200/#207/#212) and
    // #227/#229, one level up. Error-grade (no 'severity') so ok=false and the CLI
    // reports the checks and halts without recording PREFLIGHT. Mirrors action
    // execute's unknown-name rejection in lib/cli.php.
    if ($apply_name !== '' && $apply_def === null) {
        $checks[] = [
            'check'   => 'apply_known',
            'pass'    => false,
            'message' => 'Unknown apply: ' . $apply_name . '. Preflight cannot verify preconditions for an unregistered apply; check the name against the apply registry.',
        ];
    }

    // Auto-populate planned_files from apply definition if apply_name given.
    // Option-based targets don't produce planned_files (no file drift concern).
    $planned_files = $context['planned_files'] ?? [];
    if (empty($planned_files) && $apply_target_type === 'file' && isset($apply_def['target']['path'])) {
        $planned_files = [$apply_def['target']['path']];
    }

    if (!$drift['has_drift']) {
        // Healthy state: not a finding, so no class (nothing to group or act on).
        $checks[] = ['check' => 'drift', 'pass' => true, 'message' => 'No drift detected.'];
    } else {
        // Integrity-class finding (#496): the live theme differs from the recorded
        // release baseline. Phrase it as "changed since <release>" so drift always
        // reads as a genuine change, never "stale baseline of unknown vintage".
        $rel   = $drift['release_version'] ?? null;
        $since = $rel !== null
            ? 'since the installed release (' . $rel . ')'
            : 'since the recorded baseline (which predates release-version tracking — run `wp pp readiness rebaseline` to record it)';

        // Check overlap between drifted files and planned mutations
        $drifted_files = array_merge($drift['modified'], $drift['added'], $drift['deleted']);
        $overlap = array_intersect($drifted_files, $planned_files);

        if (!empty($overlap)) {
            // Error-grade (no severity): overlapping drift blocks the apply.
            $checks[] = [
                'check'       => 'drift',
                'pass'        => false,
                'class'       => 'integrity',
                'next_action' => 'Escalate to human; once the intended state is confirmed, re-baseline with `wp pp readiness rebaseline`.',
                'message'     => 'Drift overlaps with planned mutations (' . implode(', ', $overlap) . '), changed ' . $since . '. Escalate to human before proceeding.',
            ];
        } else {
            // Non-overlapping drift is advisory (pass=true, this operation cannot
            // touch these files), but still a classified integrity finding with a
            // sanctioned next action so it is never an unexplained warning.
            $checks[] = [
                'check'       => 'drift',
                'pass'        => true,
                'class'       => 'integrity',
                'next_action' => 'Re-baseline with `wp pp readiness rebaseline` after confirming these are an intended release; otherwise note in HANDOFF.',
                'message'     => count($drifted_files) . ' theme file(s) changed ' . $since . ', none overlapping this operation\'s planned writes (advisory): ' .
                    implode(', ', $drifted_files) . '.',
            ];
        }
    }

    // Check 5: Theme writable (only required when planned applies target files)
    // Media-target applies (import_media) write to wp-content/uploads, not the
    // theme dir — they are routed to the uploads_writable check below (#229)
    // instead of being lumped in with database-backed applies.
    $needs_filesystem = !empty($planned_files) || $apply_target_type === 'file';
    $needs_uploads    = $apply_target_type === 'media';

    $theme_path = $target['theme_path'];
    if (!$needs_filesystem) {
        $checks[] = [
            'check'   => 'theme_writable',
            'pass'    => true,
            'message' => $needs_uploads
                ? 'Skipped: planned applies do not write to the theme directory (uploads writes covered by uploads_writable).'
                : 'Skipped: planned applies are database-backed (no filesystem writes).',
        ];
    } elseif ($theme_path !== null && is_dir($theme_path) && is_writable($theme_path)) {
        $checks[] = ['check' => 'theme_writable', 'pass' => true, 'message' => 'Theme directory is writable.'];
    } elseif ($theme_path === null) {
        $checks[] = ['check' => 'theme_writable', 'pass' => false, 'message' => 'Cannot resolve theme path.'];
    } else {
        $checks[] = ['check' => 'theme_writable', 'pass' => false, 'message' => 'Theme directory is not writable: ' . $theme_path];
    }

    // Check 5b: Uploads writable (only when a media-target apply is planned, #229).
    // import_media sideloads into wp-content/uploads; preflight must verify that
    // write can succeed instead of asserting "no filesystem writes" and letting
    // execute fail with a raw WP error. Execute-time wp_mkdir_p() creates any
    // missing segments of the dated YYYY/MM path, so the write succeeds exactly
    // when the DEEPEST EXISTING ancestor of the target path is writable — a
    // writable basedir is neither necessary (fresh site: uploads/ itself doesn't
    // exist yet but wp-content is writable) nor sufficient (uploads/2026 rsync'd
    // 0555 blocks creation of 2026/07 while uploads/ stays writable).
    if ($needs_uploads) {
        $uploads       = wp_get_upload_dir();
        $uploads_error = is_array($uploads) ? ($uploads['error'] ?? false) : false;
        $dated_path    = is_array($uploads) ? (string) ($uploads['path'] ?? '') : '';
        $basedir       = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';
        $target_dir    = $dated_path !== '' ? $dated_path : $basedir;

        if (!empty($uploads_error)) {
            $checks[] = ['check' => 'uploads_writable', 'pass' => false, 'message' => 'Uploads directory cannot be resolved: ' . $uploads_error];
        } elseif ($target_dir === '') {
            $checks[] = ['check' => 'uploads_writable', 'pass' => false, 'message' => 'Uploads directory cannot be resolved: (empty path)'];
        } else {
            $probe    = $target_dir;
            $blocking = null; // an existing NON-directory occupying a path segment
            while (!is_dir($probe)) {
                if (file_exists($probe)) {
                    // e.g. a regular file at uploads/2026 — wp_mkdir_p() can
                    // never create children under it, and a writable ancestor
                    // above it would be a deterministic false pass.
                    $blocking = $probe;
                    break;
                }
                $parent = dirname($probe);
                if ($parent === $probe) {
                    break; // filesystem root
                }
                $probe = $parent;
            }

            if ($blocking !== null) {
                $checks[] = ['check' => 'uploads_writable', 'pass' => false, 'message' => 'Uploads path is blocked by a non-directory: ' . $blocking];
            } elseif (!is_dir($probe) || !is_writable($probe)) {
                $checks[] = ['check' => 'uploads_writable', 'pass' => false, 'message' => 'Uploads directory is not writable: ' . $probe . ($probe !== $target_dir ? ' (deepest existing ancestor of ' . $target_dir . ')' : '')];
            } elseif ($probe === $target_dir) {
                $checks[] = ['check' => 'uploads_writable', 'pass' => true, 'message' => 'Uploads directory is writable: ' . $target_dir];
            } else {
                $checks[] = ['check' => 'uploads_writable', 'pass' => true, 'message' => 'Uploads directory ' . $target_dir . ' does not exist yet; its deepest existing ancestor is writable: ' . $probe . ' (WordPress creates the rest).'];
            }
        }
    }

    // Check 6: Target page (only for page operations)
    if (isset($context['post_id'])) {
        $post = get_post($context['post_id']);
        if ($post === null) {
            $checks[] = ['check' => 'target_page', 'pass' => false, 'message' => 'Post ID ' . $context['post_id'] . ' does not exist.'];
        } else {
            // Read through the canonical accessor: normal storage is a JSON string
            // (pp_update_composition), so a raw get_post_meta + is_array() check
            // would wrongly report "no composition" for every real page and, now
            // that preflight gates mutations (#96), make page-scoped actions
            // impossible. pp_get_composition decodes the string and still handles
            // array fixtures.
            // An existing page is a VALID preflight target regardless of whether its
            // composition is empty (#358). The old gate rejected a composition-less
            // page here, but preflight runs ONCE per (run, post_id) and is
            // action-agnostic — its coverage then unlocks every page-scoped action on
            // that post — so rejecting on empty composition stranded pages created
            // empty by create_page: update_composition (which POPULATES) and
            // trash_page (which DELETES) could never earn coverage. The per-action
            // composition precondition (pp_action_composition_precondition(), enforced
            // at action execute where the action IS known) is what keeps
            // component-level actions closed on a composition-less page; preflight
            // cannot make that call because it does not know which action will run.
            $composition = pp_get_composition($context['post_id']);
            $checks[] = [
                'check'   => 'target_page',
                'pass'    => true,
                'message' => !empty($composition)
                    ? 'Target page exists with composition: ' . $post->post_title
                    : 'Target page exists (composition is empty — populate with update_composition, or trash it; component-level edits still require content): ' . $post->post_title,
            ];
        }
    }

    // Check 7: Surface classification (when planned_files provided)
    if (!empty($planned_files)) {
        $core_blocked = [];
        foreach ($planned_files as $file) {
            $surface = pp_classify_surface($file);
            if ($surface['classification'] === 'core') {
                $core_blocked[] = ['path' => $file, 'guidance' => $surface['guidance']];
            }
        }

        if (!empty($core_blocked)) {
            $blocked_paths = array_map(fn($b) => $b['path'], $core_blocked);
            $guidance = $core_blocked[0]['guidance']; // Show first guidance as representative.
            $checks[] = [
                'check'   => 'surface',
                'pass'    => false,
                'message' => 'Core file(s) in planned mutations: ' . implode(', ', $blocked_paths) . '. ' . $guidance,
            ];
        } else {
            $checks[] = ['check' => 'surface', 'pass' => true, 'message' => 'All planned files are safe or extension surfaces.'];
        }
    }

    // Check 8: Site chrome readiness (warning-grade, advisory — never blocks a mutation).
    // Unconditional: chrome is template-owned (#223), so it renders on every page
    // regardless of which page (if any) this mutation targets. A site-scoped action
    // such as update_site_option on pp_logo_id has no post_id yet changes the chrome.
    foreach (pp_check_nav_readiness() as $nav_check) {
        $checks[] = $nav_check;
    }

    // Check 9: Screenshot readiness (warning-grade, advisory — never blocks a mutation).
    // The operating loop forbids native VERIFIED without screenshots, so surface capture
    // readiness BEFORE mutation. A missing browser is a capability warning, not a gate:
    // typed mutations may still proceed; the run just cannot claim native VERIFIED.
    // Read-only capability check: no probe here (preflight must not launch a browser).
    // The tri-state `state` (#497) still renders distinctly: `unavailable` (not
    // configured) and `broken` (configured but the binary is missing) are separate
    // capability findings, while `available` is healthy. `wp pp screenshot doctor`
    // upgrades `available` to a capture-verified verdict and turns a probe failure into
    // `broken` — this surface reports the cheap, non-exec verdict.
    $shot = pp_screenshot_readiness();
    if ($shot['state'] === 'available') {
        // Healthy: not a finding, no class.
        $checks[] = [
            'check'    => 'screenshot_readiness',
            'pass'     => true,
            'severity' => 'warning',
            'state'    => 'available',
            'message'  => 'Native screenshot capture is ready (' . $shot['message'] . ').',
        ];
    } else {
        // Capability-class finding (#496): an environment tool is missing/misconfigured
        // (#497). `unavailable` and `broken` render distinctly via the `state` field.
        $checks[] = [
            'check'       => 'screenshot_readiness',
            'pass'        => false,
            'severity'    => 'warning',
            'class'       => 'capability',
            'state'       => $shot['state'],
            'next_action' => 'wp pp screenshot doctor',
            'message'     => $shot['message'] . ' Typed mutations may still proceed; native VERIFIED requires a '
              . 'working capture — run `wp pp screenshot doctor` to diagnose.',
        ];
    }

    // ok ignores severity=warning rows: warnings surface problems (pass=false) without
    // blocking the apply. Checks without a severity are treated as errors (legacy behavior).
    $all_pass = empty(array_filter(
        $checks,
        fn($c) => !$c['pass'] && (($c['severity'] ?? 'error') !== 'warning')
    ));

    // Stamp acknowledgement state onto configuration findings, then attach the
    // by-class grouping so consumers get "output groups by class, per-finding
    // next action" (#496). Enrichment reads the acknowledgement option only —
    // pp_preflight() stays read-only.
    $checks   = pp_apply_finding_acknowledgements($checks);
    $findings = pp_classify_findings($checks);

    return [
        'ok'       => $all_pass,
        'checks'   => $checks,
        'findings' => $findings,
    ];
}

// ── Playbook Checklists ────────────────────────────────────────────────────

/**
 * Returns structured checklists for each playbook.
 *
 * Hard cap: 10 items per checklist, max 5 hard gates.
 * Every item traces to a real observed failure.
 *
 * @return array  Keyed by playbook name.
 */
function pp_operate_checklists(): array {
    return [
        'create-page' => [
            ['id' => 'sections_present',     'description' => 'All sections from the brief are present in the composition', 'gate' => 'hard', 'viewport' => 'any'],
            ['id' => 'no_empty_sections',    'description' => 'No sections render as empty/blank at 1280px',               'gate' => 'hard', 'viewport' => 'desktop'],
            ['id' => 'mobile_readable',      'description' => 'All text is readable and no horizontal overflow at 375px',  'gate' => 'hard', 'viewport' => 'mobile'],
            ['id' => 'no_antislop',          'description' => 'No generic placeholder text, lorem ipsum, or AI slop',      'gate' => 'hard', 'viewport' => 'desktop'],
            ['id' => 'hero_has_cta',         'description' => 'Hero section has a visible call-to-action',                 'gate' => 'hard', 'viewport' => 'desktop'],
            ['id' => 'brand_tokens_applied', 'description' => 'Brand colors and typography match design tokens',           'gate' => 'soft', 'viewport' => 'desktop'],
            ['id' => 'images_loaded',        'description' => 'All referenced images load without broken placeholders',    'gate' => 'soft', 'viewport' => 'any'],
            ['id' => 'nav_footer_present',   'description' => 'The template renders the site nav and footer exactly once (never add them to the composition)', 'gate' => 'soft', 'viewport' => 'desktop'],
        ],
        'revise-section' => [
            ['id' => 'target_section_changed', 'description' => 'The target section reflects the requested changes',       'gate' => 'hard', 'viewport' => 'desktop'],
            ['id' => 'no_regression',          'description' => 'Other sections unchanged from before-screenshot',         'gate' => 'hard', 'viewport' => 'desktop'],
            ['id' => 'mobile_readable',        'description' => 'Revised section is readable at 375px',                    'gate' => 'hard', 'viewport' => 'mobile'],
            ['id' => 'no_empty_sections',      'description' => 'No sections render as empty/blank',                       'gate' => 'hard', 'viewport' => 'desktop'],
            ['id' => 'brand_tokens_applied',   'description' => 'Brand colors and typography consistent after revision',   'gate' => 'soft', 'viewport' => 'desktop'],
        ],
        'inspect-fix' => [
            ['id' => 'issue_resolved',        'description' => 'The reported issue is no longer visible',                  'gate' => 'hard', 'viewport' => 'desktop'],
            ['id' => 'no_regression',         'description' => 'No new visual issues introduced by the fix',               'gate' => 'hard', 'viewport' => 'desktop'],
            ['id' => 'mobile_no_regression',  'description' => 'Fix does not break mobile layout',                         'gate' => 'hard', 'viewport' => 'mobile'],
            ['id' => 'root_cause_addressed',  'description' => 'Fix addresses root cause, not just symptom',               'gate' => 'soft', 'viewport' => 'any'],
        ],
    ];
}

// ── Loop Run Validation ────────────────────────────────────────────────────

/**
 * Validates a loop run manifest against the operating contract.
 *
 * A run manifest is an array keyed by step name, each containing the
 * step's output. This function checks completeness, not correctness.
 *
 * @param array $run  Loop run manifest keyed by step name.
 * @return array{valid: bool, errors: string[]}
 */
function pp_validate_loop_run(array $run): array {
    $errors = [];
    $steps = pp_operate_loop_steps();

    foreach ($steps as $step_name => $step_def) {
        if (!isset($run[$step_name])) {
            $errors[] = 'Missing step: ' . $step_name;
            continue;
        }

        // Check required outputs are present
        foreach ($step_def['required_outputs'] as $output) {
            if (!isset($run[$step_name][$output])) {
                $errors[] = $step_name . ': missing required output "' . $output . '"';
            }
        }
    }

    // Specific validations
    if (isset($run['PREFLIGHT']['preflight_result'])) {
        $pf = $run['PREFLIGHT']['preflight_result'];
        if (isset($pf['ok']) && $pf['ok'] === false) {
            $errors[] = 'PREFLIGHT failed — loop should not have continued past PREFLIGHT.';
        }
    }

    if (isset($run['HANDOFF']['handoff_report'])) {
        $report = $run['HANDOFF']['handoff_report'];
        if (!isset($report['status'])) {
            $errors[] = 'HANDOFF: handoff_report missing "status" field.';
        } elseif (!in_array($report['status'], ['VERIFIED', 'NEEDS_VISUAL_VERIFICATION', 'SCREENSHOT_FAILED'], true)) {
            $errors[] = 'HANDOFF: invalid status "' . $report['status'] . '".';
        }
    }

    // Retry count validation: max 2 retries allowed.
    if (isset($run['retry_count']) && (int) $run['retry_count'] > PP_OPERATE_MAX_RETRIES) {
        $errors[] = 'Retry count ' . $run['retry_count'] . ' exceeds maximum of ' . PP_OPERATE_MAX_RETRIES . '. Escalate to human.';
    }

    // Viewport coverage + checklist completeness: resolve checklist once for both checks.
    if (isset($run['playbook'])) {
        $checklists = pp_operate_checklists();
        if (isset($checklists[$run['playbook']])) {
            $checklist = $checklists[$run['playbook']];

            // Viewport coverage: check screenshot viewports.
            if (isset($run['SCREENSHOT']['screenshot_result'])) {
                $needs_mobile  = false;
                $needs_desktop = false;
                foreach ($checklist as $item) {
                    $vp = $item['viewport'] ?? 'any';
                    if ($vp === 'mobile') {
                        $needs_mobile = true;
                    } elseif ($vp === 'desktop') {
                        $needs_desktop = true;
                    } elseif ($vp === 'any') {
                        $needs_mobile  = true;
                        $needs_desktop = true;
                    }
                }

                $screenshots   = $run['SCREENSHOT']['screenshot_result'];
                $has_mobile    = ! empty($screenshots['mobile']);
                $has_desktop   = ! empty($screenshots['desktop']);

                if ($needs_mobile && ! $has_mobile) {
                    $errors[] = 'Playbook "' . $run['playbook'] . '" requires mobile viewport but no mobile screenshot was captured.';
                }
                if ($needs_desktop && ! $has_desktop) {
                    $errors[] = 'Playbook "' . $run['playbook'] . '" requires desktop viewport but no desktop screenshot was captured.';
                }
            }

            // Checklist completeness: all hard-gate items must be evaluated.
            if (isset($run['REVIEW']['review_result'])) {
                $review    = $run['REVIEW']['review_result'];
                $evaluated = array_column($review, 'id');

                foreach ($checklist as $item) {
                    if ($item['gate'] === 'hard' && ! in_array($item['id'], $evaluated, true)) {
                        $errors[] = 'REVIEW: hard-gate checklist item "' . $item['id'] . '" was not evaluated.';
                    }
                }
            }
        }
    }

    return [
        'valid'  => empty($errors),
        'errors' => $errors,
    ];
}

// ── Run Token State Files ─────────────────────────────────────────────────

/** Maximum retry count before escalation. */
define( 'PP_OPERATE_MAX_RETRIES', 2 );

/** Run token TTL in seconds (2 hours). */
define( 'PP_OPERATE_RUN_TTL', 7200 );

/** UUID v4 validation pattern for run tokens. */
define( 'PP_OPERATE_UUID_PATTERN', '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/' );

/**
 * Option name for a run token's durable state (#409).
 *
 * Run state lives in a per-run, NON-AUTOLOADED wp_options row rather than a file in
 * sys_get_temp_dir(). The options table is the one store guaranteed shared across
 * every WP-CLI process that can operate the install at all. The old temp-dir file
 * broke the operating loop whenever each `wp` call was an isolated process with its
 * own /tmp (the standard one-ephemeral-container-per-invocation `wordpress:cli`
 * pattern): `inspect` wrote the file in container A's /tmp and it was gone before
 * `preflight` ran in container B. 'pp_operate_run_' + 36-char UUID = 51 chars, well
 * under the wp_options.option_name 191-char limit.
 *
 * @param string $run_id The run token UUID.
 * @return string
 */
function pp_operate_run_option_name( string $run_id ): string {
    return 'pp_operate_run_' . $run_id;
}

/**
 * Per-run advisory-lock name serializing the run-state read-modify-write (#409).
 *
 * Replaces the old flock(LOCK_EX) on the temp file. Reuses the shared MySQL GET_LOCK
 * engine (_pp_with_advisory_lock, lib/wp.php) that already backs the token-override
 * and per-post composition locks — one bounded-wait acquire / release-in-finally /
 * degrade-without-$wpdb implementation, differing only in the lock NAME. Includes DB
 * name + blog id (writers on the SAME store serialize; unrelated installs never
 * collide) plus the run id (different runs never serialize against each other). MySQL
 * caps lock names at 64 chars; 'pp_oprun_' + 32-char md5 slice = 41 chars.
 *
 * @param string $run_id The run token UUID.
 * @return string
 */
function pp_operate_run_lock_name( string $run_id ): string {
    global $wpdb;
    $db   = defined( 'DB_NAME' ) ? DB_NAME : ( isset( $wpdb->dbname ) ? $wpdb->dbname : 'db' );
    $blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
    return 'pp_oprun_' . substr( md5( $db . '|' . $blog . '|' . $run_id ), 0, 32 );
}

/**
 * Runs $mutator inside the per-run advisory lock. Thin wrapper over the shared
 * _pp_with_advisory_lock() engine; on lock-acquire failure the mutator does NOT run
 * and $fail_value is returned (fail-closed, never a silent unlocked write). Degrades
 * to running the mutator directly only in unit context (no $wpdb); production CLI
 * always has $wpdb, so the DB-backed store is always serialized there.
 *
 * @param string   $run_id
 * @param callable $mutator     fn($wpdb): mixed
 * @param mixed    $fail_value
 * @return mixed
 */
function pp_operate_with_run_lock( string $run_id, callable $mutator, $fail_value ) {
    return _pp_with_advisory_lock( pp_operate_run_lock_name( $run_id ), $mutator, $fail_value, 'operate run ' . $run_id );
}

/**
 * Persists a run's state array to its non-autoloaded option.
 *
 * Returns true on a confirmed write OR when the stored value already equals $state:
 * update_option() returns false on a no-op write, but the state is already durable so
 * that is success, not failure (the old file store always rewrote and returned true).
 * The no-op comparison is only safe because $current was read authoritatively inside
 * the lock (pp_operate_mutate_state), so equality genuinely means nothing to persist.
 *
 * @param string     $run_id
 * @param array      $state
 * @param array|null $current  Authoritative current state (read inside the lock) for
 *                             no-op detection. Null forces the write.
 * @return bool
 */
function pp_operate_persist_state( string $run_id, array $state, ?array $current = null ): bool {
    if ( $current !== null && $current === $state ) {
        return true;
    }
    // Non-autoloaded ($autoload = false): run state must never join the alloptions
    // autoload cache — it is per-request-irrelevant and would bloat every page load.
    return (bool) update_option( pp_operate_run_option_name( $run_id ), $state, false );
}

/**
 * Deletes a run's state option. No-op if the run-id is invalid or the row is absent.
 *
 * @param string $run_id
 * @return void
 */
function pp_operate_delete_state( string $run_id ): void {
    if ( ! pp_operate_valid_run_id( $run_id ) ) {
        return;
    }
    delete_option( pp_operate_run_option_name( $run_id ) );
}

/**
 * Classifies a run token's stored state WITHOUT side effects.
 *
 * Single source of truth for "why is this run (un)usable". Reads via get_option(); in a
 * fresh CLI process (the operating-loop norm) that reads the DB on a cold cache, so a
 * value another process committed is visible. Callers that need an authoritative read
 * against a warm process cache (pp_operate_mutate_state, inside the lock) bust the
 * options cache first. get_option() unserializes the row for us.
 *
 * @param string $run_id
 * @return array  ['status' => one of invalid|not_found|corrupt|expired|foreign|ok,
 *                 'data' => ?array]  ('data' is set only when status is 'ok'.)
 */
function pp_operate_classify_state( string $run_id ): array {
    if ( ! pp_operate_valid_run_id( $run_id ) ) {
        return [ 'status' => 'invalid', 'data' => null ];
    }

    $data = get_option( pp_operate_run_option_name( $run_id ), null );
    if ( $data === null ) {
        return [ 'status' => 'not_found', 'data' => null ];
    }
    if ( ! is_array( $data ) || ! isset( $data['steps_completed'] ) || ! isset( $data['created_at'] ) ) {
        return [ 'status' => 'corrupt', 'data' => null ];
    }
    // Auto-expire after TTL, enforced from the stored timestamp (same 2h semantics as
    // the old file store). The row is NOT deleted here — classification is side-effect
    // free; cleanup is owned by pp_operate_read_state (auto-expire), pp_operate_run_status
    // (diagnosis endpoint), and pp_operate_gc_expired_runs (abandoned-row sweep).
    if ( ( time() - (int) $data['created_at'] ) > PP_OPERATE_RUN_TTL ) {
        return [ 'status' => 'expired', 'data' => null ];
    }
    // Site identity: a run minted on another install (defense in depth beyond the
    // per-blog option scoping) is not usable here. A run written by an older build
    // with no site_id is treated as a mismatch — fail-closed, drains within the TTL.
    if ( ! isset( $data['site_id'] ) || ! hash_equals( pp_operate_site_id(), (string) $data['site_id'] ) ) {
        return [ 'status' => 'foreign', 'data' => null ];
    }
    return [ 'status' => 'ok', 'data' => $data ];
}

/**
 * Sweeps expired and corrupt run-state options so abandoned runs cannot accumulate
 * unbounded rows (#409). The old temp-dir files were reaped by the OS; the options
 * table has no such sweep, so a run that is minted and never completed (nor followed
 * by a read that auto-expires it) would otherwise linger until something referenced
 * its exact UUID again — which never happens. Called opportunistically at
 * pp_operate_create_run(), so every loop start bounds the store to runs minted within
 * one TTL window. Enumerates via $wpdb with esc_like() + a bounded LIMIT; no-ops when
 * no $wpdb is present (unit context).
 *
 * @return int  Number of rows deleted.
 */
function pp_operate_gc_expired_runs(): int {
    global $wpdb;
    if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
        return 0;
    }

    $like  = $wpdb->esc_like( 'pp_operate_run_' ) . '%';
    // ORDER BY option_id ASC sweeps the OLDEST rows first (option_id is the insertion-
    // order PK), so the rows most likely to be expired are reached within the LIMIT even
    // when many run rows exist — the sweep converges on dead rows instead of starving on
    // an arbitrary unordered slice.
    $names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 1000",
            $like
        )
    );
    if ( empty( $names ) ) {
        return 0;
    }

    $now     = time();
    $deleted = 0;
    foreach ( $names as $name ) {
        $data = get_option( $name, null );
        $dead = ! is_array( $data )
            || ! isset( $data['created_at'] )
            || ( $now - (int) $data['created_at'] ) > PP_OPERATE_RUN_TTL;
        if ( $dead ) {
            delete_option( $name );
            $deleted++;
        }
    }
    return $deleted;
}

/**
 * Validates that a run-id is a well-formed UUID v4.
 *
 * Prevents path traversal via crafted --run-id values.
 *
 * @param string $run_id The value to validate.
 * @return bool
 */
function pp_operate_valid_run_id( string $run_id ): bool {
    return (bool) preg_match( PP_OPERATE_UUID_PATTERN, $run_id );
}

/**
 * Creates a new run token for step enforcement.
 *
 * Generates a UUID and writes a per-run, non-autoloaded wp_options row with INSPECT
 * recorded (#409). The row tracks which steps have been completed for this run and is
 * shared across every CLI process operating this install — unlike the old temp-dir
 * file, which was invisible to a separate ephemeral CLI container.
 *
 * Run token state enforces step ordering in real-time (CLI calls).
 * pp_validate_loop_run() validates completeness post-hoc (finished run manifest).
 * These are complementary layers, not alternatives.
 *
 * Sweeps expired/corrupt run rows first so the store stays bounded (no unbounded
 * option rows from abandoned runs).
 *
 * @return string|WP_Error  UUID string on success, WP_Error on failure.
 */
function pp_operate_create_run() {
    // Bound the store: reap abandoned/expired run rows at the start of every loop.
    pp_operate_gc_expired_runs();

    $run_id = wp_generate_uuid4();
    $state  = [
        'steps_completed' => [ 'INSPECT' ],
        'created_at'      => time(),
        'site_id'         => pp_operate_site_id(),
    ];

    if ( ! pp_operate_persist_state( $run_id, $state ) ) {
        return new WP_Error(
            'state_write_failed',
            'Cannot persist run state for token "' . $run_id . '". The options-table write failed; check the database and wp_options permissions.'
        );
    }

    return $run_id;
}

/**
 * Computes a stable identity for the current WordPress install.
 *
 * Run state now lives in each install's own wp_options table (#409), so it is already
 * install-scoped by the store. This identity is defense in depth: it lets a run refuse
 * to replay one install's snapshot against another even if state is ever surfaced to a
 * different install (and drains legacy pre-site_id rows fail-closed). Uses the same
 * inputs as the token advisory lock (site URL + DB name + blog id) so the two notions
 * of "this install" stay consistent.
 *
 * @return string  An opaque, install-scoped identity hash.
 */
function pp_operate_site_id(): string {
    $siteurl = function_exists( 'get_option' ) ? (string) get_option( 'siteurl', '' ) : '';
    $db      = defined( 'DB_NAME' ) ? DB_NAME : 'db';
    $blog    = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
    return substr( hash( 'sha256', $siteurl . '|' . $db . '|' . $blog ), 0, 32 );
}

/**
 * Reads and validates a run's state, returning the decoded state or null.
 *
 * Single source of truth for "is this run usable right now": returns null on an
 * invalid run-id, an absent/corrupt/structurally-invalid row, an expired TTL (the
 * stale row is deleted), or a site-identity mismatch. A row written by an older build
 * (no site_id) is treated as a mismatch — fail-closed, drains within the TTL.
 *
 * No shared read lock is needed (the old flock LOCK_SH is gone): the state is a single
 * wp_options row, and a row UPDATE is atomic, so a reader can never observe the
 * half-written state the file store's non-atomic truncate+write could produce. All
 * mutations still serialize through pp_operate_mutate_state's advisory lock.
 *
 * @param string $run_id  The run token UUID.
 * @return array|null  The decoded state array, or null if the run is unusable.
 */
function pp_operate_read_state( string $run_id ): ?array {
    $c = pp_operate_classify_state( $run_id );

    // Auto-expire cleanup: an expired run's row is dropped on read (preserves the old
    // file store's unlink-on-expire behavior). A corrupt row is left in place (as the
    // old code returned null WITHOUT recreating a corrupt file); GC and run_status reap it.
    if ( $c['status'] === 'expired' ) {
        pp_operate_delete_state( $run_id );
        return null;
    }

    return $c['status'] === 'ok' ? $c['data'] : null;
}

/**
 * Diagnoses a run token for precise operator-facing errors, and reaps terminal rows.
 *
 * Returns one of: 'ok', 'invalid', 'not_found', 'expired', 'corrupt', 'foreign'. As the
 * explicit diagnosis endpoint it OWNS cleanup of provably-dead rows: an 'expired' or
 * 'corrupt' row is deleted before returning (the caller has already captured the status
 * for its message), so the split not-found-vs-expired errors (#409) stay precise while
 * dead rows never accumulate. 'ok'/'foreign' rows are left intact.
 *
 * pp_operate_mutate_state deliberately does NOT delete an expired row, so a failed
 * record can still be classified 'expired' here rather than degrading to 'not_found'.
 *
 * @param string $run_id
 * @return string
 */
function pp_operate_run_status( string $run_id ): string {
    $status = pp_operate_classify_state( $run_id )['status'];
    if ( $status === 'expired' || $status === 'corrupt' ) {
        pp_operate_delete_state( $run_id );
    }
    return $status;
}

/**
 * Locked read-modify-write of a run's state option (#409). Single critical-section
 * helper behind pp_operate_record_step / record_token_snapshot / record_touched_tokens
 * so the lock + validate + TTL + identity guards live in one place.
 *
 * The $mutator receives the decoded state array and returns the new array to persist,
 * or false to abort the write (the caller then sees a false return). Returns false on
 * an invalid run-id, a lock-acquire failure, absent/corrupt/expired/foreign state, a
 * mutator abort, or a failed persist.
 *
 * Concurrency: the whole read-modify-write runs inside the per-run advisory lock (the
 * shared GET_LOCK engine that replaced the file's flock LOCK_EX), so concurrent CLI
 * processes serialize and cannot lose an update. Inside the lock the current state is
 * read authoritatively — the options cache for this row is dropped first, so a value a
 * concurrent process just committed is seen instead of a stale process-local cache.
 *
 * Fail-closed persistence is simpler than the file store's: a wp_options row UPDATE is
 * atomic, so there is no torn-write / trailing-bytes / partial-flush failure mode to
 * guard against. update_option() either commits the whole new value or it does not; on
 * a false return (write failed) the prior row is untouched and this returns false.
 *
 * @param string   $run_id
 * @param callable $mutator  fn(array $data): array|false
 * @return bool  True only on a confirmed write (or a genuine no-op).
 */
function pp_operate_mutate_state( string $run_id, callable $mutator ): bool {
    if ( ! pp_operate_valid_run_id( $run_id ) ) {
        return false;
    }

    return pp_operate_with_run_lock( $run_id, static function ( $wpdb ) use ( $run_id, $mutator ) {
        // Authoritative read inside the lock: drop any process-local cache of this
        // option so a concurrent writer's just-committed state is visible, then classify.
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( pp_operate_run_option_name( $run_id ), 'options' );
        }

        $c = pp_operate_classify_state( $run_id );
        // Fail closed on anything but a live run. Do NOT delete an expired/corrupt row
        // here: the CLI calls pp_operate_run_status() next to pick the precise operator
        // message (not-found vs expired), and that endpoint owns terminal-row cleanup.
        if ( $c['status'] !== 'ok' ) {
            return false;
        }

        $data = $c['data'];
        $new  = $mutator( $data );
        if ( $new === false || ! is_array( $new ) ) {
            return false;
        }

        // Persist. A no-op mutation (new === prior) returns true without a write —
        // update_option() reports false on a no-op, but the base was read
        // authoritatively above, so equality means the desired state is already durable.
        return pp_operate_persist_state( $run_id, $new, $data );
    }, false );
}

/**
 * Checks whether a required step has been completed for a run.
 *
 * Returns false if the run-id is invalid, the state file is missing, expired
 * (>2 hours), corrupt, from a different install (site-identity mismatch), or does
 * not include the required step. Expired files are cleaned up automatically.
 *
 * @param string $run_id        The run token UUID.
 * @param string $required_step The step name to check for (e.g. 'INSPECT', 'PREFLIGHT').
 * @return bool
 */
function pp_operate_check_step( string $run_id, string $required_step ): bool {
    $data = pp_operate_read_state( $run_id );
    if ( $data === null ) {
        return false;
    }
    return in_array( $required_step, $data['steps_completed'], true );
}

/**
 * Records a step as completed in the run state file.
 *
 * Uses exclusive file locking across the entire read-modify-write cycle
 * to prevent TOCTOU races from concurrent CLI calls.
 *
 * Idempotent: recording the same step twice is a no-op.
 *
 * @param string $run_id The run token UUID.
 * @param string $step   The step name to record (e.g. 'PREFLIGHT').
 * @return bool  True on success, false if state file missing/expired/corrupt/invalid run-id.
 */
function pp_operate_record_step( string $run_id, string $step ): bool {
    return pp_operate_mutate_state( $run_id, static function ( array $data ) use ( $step ) {
        // Idempotent: don't duplicate.
        if ( ! in_array( $step, $data['steps_completed'], true ) ) {
            $data['steps_completed'][] = $step;
        }
        return $data;
    } );
}

/**
 * Freezes the pre-apply token-override snapshot for a run.
 *
 * Captured at the run's first PREFLIGHT; the snapshot is the source of prior values
 * for restore. Idempotent and first-write-wins: once a token_snapshot is recorded it
 * is never overwritten, so re-running preflight cannot move the rollback baseline.
 * Pass the overrides read under the token lock so the baseline is atomic against
 * concurrent applies.
 *
 * @param string $run_id     The run token UUID.
 * @param array  $overrides  token => value map (the current pp_token_overrides).
 * @return bool  True on a confirmed write (or no-op when already present); false if
 *               the run state is missing/expired/corrupt or identity-mismatched.
 */
function pp_operate_record_token_snapshot( string $run_id, array $overrides ): bool {
    return pp_operate_mutate_state( $run_id, static function ( array $data ) use ( $overrides ) {
        if ( ! array_key_exists( 'token_snapshot', $data ) ) {
            $data['token_snapshot'] = $overrides;
        }
        return $data;
    } );
}

/**
 * Returns the frozen pre-apply snapshot for a run, or null.
 *
 * Null (NOT []) when the run is unusable (missing/expired/corrupt/identity-mismatch)
 * or no snapshot was recorded, or the stored snapshot is not an array. A valid run
 * that captured an empty override map returns []. This null-vs-[] distinction is
 * load-bearing: restore must fail-closed on null and clear-all on [].
 *
 * @param string $run_id  The run token UUID.
 * @return array|null
 */
function pp_operate_get_token_snapshot( string $run_id ): ?array {
    $data = pp_operate_read_state( $run_id );
    if ( $data === null || ! array_key_exists( 'token_snapshot', $data ) || ! is_array( $data['token_snapshot'] ) ) {
        return null;
    }
    return $data['token_snapshot'];
}

/**
 * Records a successful PREFLIGHT and everything that must be committed with it,
 * in a SINGLE atomic mutation: the PREFLIGHT step, the target the preflight
 * covered (a specific post_id for page/section work, or the site grain when no
 * post is given), the pre-apply token snapshot, and — for a page/section
 * preflight — the pre-apply composition content snapshot (the run-scoped
 * restore baseline, issue 133).
 *
 * Atomicity is load-bearing. `wp pp action execute` / `operate patch` unlock on
 * the recorded coverage alone (pp_operate_preflight_covers), unlike `apply
 * execute` which also checks the rollback snapshot. If coverage were written in a
 * separate call from the snapshot and the snapshot write failed, a later mutating
 * action could pass its gate even though the preflight command errored. Writing
 * step + coverage + token snapshot + composition content snapshot inside one
 * pp_operate_mutate_state critical section means the run gains the complete
 * post-preflight state or none of it, so any failure leaves BOTH the action gate
 * and the apply gate fail-closed WITH a rollback baseline — never unlocked
 * without one (issue 241: the composition content snapshot used to be a second,
 * separate write, so a snapshot-write failure left the run unlocked with no
 * restore baseline, and a re-run could freeze a post-mutation baseline).
 *
 * Idempotent: the step and each covered post_id de-dupe; the token snapshot and
 * the composition content snapshot are both first-write-wins, so re-running
 * preflight never moves either rollback baseline.
 *
 * @param string     $run_id              The run token UUID.
 * @param int|null   $post_id             Target post for page/section preflight, or null for site grain.
 * @param array      $token_overrides     Current pp_token_overrides, read under the token lock.
 * @param array|null $composition_marker  The target's {version, hash} freshness marker (#113),
 *                                        for a page/section preflight. Recorded so `action
 *                                        execute` can reject a composition changed since this
 *                                        preflight. Null (or a null $post_id) records no marker.
 * @param array|null $composition_content The target's pre-apply composition items (issue 133),
 *                                        for a page/section preflight. Recorded first-write-wins
 *                                        as the run-scoped restore baseline. The caller reads it
 *                                        via pp_get_composition_result() and fails the preflight
 *                                        closed on a corrupt row rather than passing []; null (or
 *                                        a null $post_id) records no content snapshot.
 * @return bool    True on a confirmed write; false if the run state is
 *                 missing/expired/corrupt or identity-mismatched.
 */
function pp_operate_record_preflight( string $run_id, ?int $post_id, array $token_overrides, ?array $composition_marker = null, ?array $composition_content = null ): bool {
    return pp_operate_mutate_state( $run_id, static function ( array $data ) use ( $post_id, $token_overrides, $composition_marker, $composition_content ) {
        // PREFLIGHT step (idempotent) — keeps apply execute's check_step gate working.
        if ( ! in_array( 'PREFLIGHT', $data['steps_completed'], true ) ) {
            $data['steps_completed'][] = 'PREFLIGHT';
        }

        // Contextual coverage: a page/section preflight covers a specific post;
        // a no-post preflight covers the site grain. Never let one stand in for
        // the other — that is the page-scoped false-pass this guard prevents.
        if ( $post_id !== null ) {
            if ( ! isset( $data['preflight_post_ids'] ) || ! is_array( $data['preflight_post_ids'] ) ) {
                $data['preflight_post_ids'] = [];
            }
            if ( ! in_array( $post_id, $data['preflight_post_ids'], true ) ) {
                $data['preflight_post_ids'][] = $post_id;
            }

            // Freshness baseline (#113): record the composition marker this preflight
            // validated against, keyed per post. LAST-write-wins (unlike the rollback
            // snapshot below) so re-running preflight after a legitimate change
            // re-acknowledges the current state as the new baseline.
            if ( $composition_marker !== null ) {
                if ( ! isset( $data['composition_snapshot'] ) || ! is_array( $data['composition_snapshot'] ) ) {
                    $data['composition_snapshot'] = [];
                }
                $data['composition_snapshot'][ (string) $post_id ] = $composition_marker;
            }

            // Run-scoped restore baseline (#133): freeze the pre-apply composition
            // content so `apply restore-composition` can revert this post to its
            // pre-run state. FIRST-write-wins (keyed per post), so a preflight re-run
            // in the same run never overwrites the true pre-run baseline with a
            // post-mutation one. Committed here, inside the SAME critical section as
            // the PREFLIGHT step/coverage, so the gate never unlocks without its
            // rollback baseline (issue 241). A null value (site grain, or a corrupt
            // composition the caller already failed the preflight on) records nothing.
            if ( $composition_content !== null ) {
                if ( ! isset( $data['composition_content_snapshot'] ) || ! is_array( $data['composition_content_snapshot'] ) ) {
                    $data['composition_content_snapshot'] = [];
                }
                $content_key = (string) $post_id;
                if ( ! array_key_exists( $content_key, $data['composition_content_snapshot'] ) ) {
                    $data['composition_content_snapshot'][ $content_key ] = $composition_content;
                }
            }
        } else {
            $data['preflight_site'] = true;
        }

        // Pre-apply rollback baseline (first-write-wins, same as the legacy recorder).
        if ( ! array_key_exists( 'token_snapshot', $data ) ) {
            $data['token_snapshot'] = $token_overrides;
        }

        return $data;
    } );
}

/**
 * Records the composition freshness marker for a post as the run's current baseline (#113).
 *
 * LAST-write-wins. Called after a successful in-run composition mutation to refresh the
 * baseline to the just-written {version, hash}, so a run's OWN sequential mutations on the
 * same post keep passing the freshness gate while an EXTERNAL interleaved write (dashboard,
 * another run) still mismatches and is rejected. Returns false (never silently) if the run
 * state is missing/expired/corrupt or identity-mismatched, so the caller can surface that
 * the run's freshness baseline may be stale.
 *
 * @param string $run_id  The run token UUID.
 * @param int    $post_id The mutated post.
 * @param array  $marker  The just-written {version, hash} marker.
 * @return bool  True only on a confirmed write.
 */
function pp_operate_record_composition_snapshot( string $run_id, int $post_id, array $marker ): bool {
    return pp_operate_mutate_state( $run_id, static function ( array $data ) use ( $post_id, $marker ) {
        if ( ! isset( $data['composition_snapshot'] ) || ! is_array( $data['composition_snapshot'] ) ) {
            $data['composition_snapshot'] = [];
        }
        $data['composition_snapshot'][ (string) $post_id ] = $marker;
        return $data;
    } );
}

/**
 * Returns the recorded composition freshness marker for a post in a run, or null (#113).
 *
 * Null when the run is unusable (missing/expired/corrupt/identity-mismatch), no marker was
 * recorded for this post, or the stored marker is not an array. Fail-closed: the execute
 * freshness gate treats null as "no baseline recorded", which blocks the mutation.
 *
 * @param string $run_id  The run token UUID.
 * @param int    $post_id The mutation target post.
 * @return array|null  The {version, hash} marker, or null.
 */
function pp_operate_get_composition_snapshot( string $run_id, int $post_id ): ?array {
    $data = pp_operate_read_state( $run_id );
    if ( $data === null || ! isset( $data['composition_snapshot'] ) || ! is_array( $data['composition_snapshot'] ) ) {
        return null;
    }
    $key = (string) $post_id;
    if ( ! isset( $data['composition_snapshot'][ $key ] ) || ! is_array( $data['composition_snapshot'][ $key ] ) ) {
        return null;
    }
    return $data['composition_snapshot'][ $key ];
}

/**
 * True iff two composition freshness markers match (#113): same version AND same hash.
 *
 * Pure comparator so the freshness decision is unit-testable without WP-CLI. Uses
 * hash_equals for the hash (both sides are strings by pp_get_composition_marker's cast)
 * and a strict int compare for the version.
 *
 * @param array $recorded  The marker recorded at preflight / last in-run write.
 * @param array $live      The marker re-read live at execute time.
 * @return bool
 */
function pp_composition_marker_matches( array $recorded, array $live ): bool {
    return (int) ( $recorded['version'] ?? -1 ) === (int) ( $live['version'] ?? -2 )
        && hash_equals( (string) ( $recorded['hash'] ?? '' ), (string) ( $live['hash'] ?? '' ) );
}

/**
 * Whether this run has a completed PREFLIGHT covering the intended mutation target.
 *
 * Fail-closed: a missing/expired/corrupt/identity-mismatched run returns false.
 * Strict and non-weakening:
 *   - a page/section mutation (post_id given) requires a preflight recorded for
 *     that exact post; a site-grain preflight does NOT cover it;
 *   - a site mutation (post_id null) requires a site-grain preflight; a
 *     page preflight does NOT cover it.
 *
 * @param string   $run_id  The run token UUID.
 * @param int|null $post_id The mutation target post, or null for a site-scoped mutation.
 * @return bool
 */
function pp_operate_preflight_covers( string $run_id, ?int $post_id ): bool {
    $data = pp_operate_read_state( $run_id );
    if ( $data === null ) {
        return false;
    }
    if ( $post_id !== null ) {
        $covered = $data['preflight_post_ids'] ?? [];
        return is_array( $covered ) && in_array( $post_id, $covered, true );
    }
    return ! empty( $data['preflight_site'] );
}

/**
 * Records the token keys an apply wrote (primary + derived), deduped, for a run.
 *
 * The touched-key set scopes what restore is allowed to revert. Returns false (never
 * silently) if the run state is missing/expired/corrupt or identity-mismatched, so the
 * caller can surface that the change may not be reversible.
 *
 * @param string $run_id  The run token UUID.
 * @param array  $keys    Token names written by the apply.
 * @return bool  True only on a confirmed write.
 */
function pp_operate_record_touched_tokens( string $run_id, array $keys ): bool {
    return pp_operate_mutate_state( $run_id, static function ( array $data ) use ( $keys ) {
        $existing = isset( $data['touched_tokens'] ) && is_array( $data['touched_tokens'] )
            ? $data['touched_tokens'] : [];
        foreach ( $keys as $key ) {
            if ( is_string( $key ) && ! in_array( $key, $existing, true ) ) {
                $existing[] = $key;
            }
        }
        $data['touched_tokens'] = array_values( $existing );
        return $data;
    } );
}

/**
 * Returns the touched-key set for a run, or null.
 *
 * Null when the run is unusable or no touched_tokens were recorded; a valid run that
 * recorded none returns []. Restore fails-closed on null.
 *
 * @param string $run_id  The run token UUID.
 * @return array|null
 */
function pp_operate_get_touched_tokens( string $run_id ): ?array {
    $data = pp_operate_read_state( $run_id );
    if ( $data === null || ! array_key_exists( 'touched_tokens', $data ) || ! is_array( $data['touched_tokens'] ) ) {
        return null;
    }
    return $data['touched_tokens'];
}

/**
 * Records a post whose composition an action wrote, deduped, for a run (#133).
 *
 * The composition analogue of pp_operate_record_touched_tokens: the touched-post set
 * scopes what a run-scoped composition restore is allowed to revert. Returns false
 * (never silently) if the run state is missing/expired/corrupt or identity-mismatched,
 * so the caller can surface that the change may not be reversible.
 *
 * @param string $run_id   The run token UUID.
 * @param int    $post_id  The post whose composition was written.
 * @return bool  True only on a confirmed write.
 */
function pp_operate_record_touched_post_id( string $run_id, int $post_id ): bool {
    return pp_operate_mutate_state( $run_id, static function ( array $data ) use ( $post_id ) {
        $existing = isset( $data['touched_post_ids'] ) && is_array( $data['touched_post_ids'] )
            ? $data['touched_post_ids'] : [];
        if ( ! in_array( $post_id, $existing, true ) ) {
            $existing[] = $post_id;
        }
        $data['touched_post_ids'] = array_values( $existing );
        return $data;
    } );
}

/**
 * Returns the touched-post set for a run, or null (#133).
 *
 * Null when the run is unusable or no touched_post_ids were recorded; a valid run that
 * touched none returns []. Run-scoped composition restore fails-closed on null.
 *
 * @param string $run_id  The run token UUID.
 * @return array|null
 */
function pp_operate_get_touched_post_ids( string $run_id ): ?array {
    $data = pp_operate_read_state( $run_id );
    if ( $data === null || ! array_key_exists( 'touched_post_ids', $data ) || ! is_array( $data['touched_post_ids'] ) ) {
        return null;
    }
    return array_map( 'intval', $data['touched_post_ids'] );
}

/**
 * Freezes the pre-apply composition CONTENT for a post in a run, first-write-wins (#133).
 *
 * The composition analogue of the token_snapshot: the full composition array a
 * run-scoped restore reverts each touched post to. Distinct from #113's
 * composition_snapshot, which stores only the freshness MARKER (version + hash) for the
 * TOCTOU gate — the marker can't rebuild content. First-write-wins per post so
 * re-running preflight in the same run keeps the true pre-run baseline stable.
 *
 * NOTE: as of issue 241 this standalone recorder has NO production caller. The
 * `apply preflight` command used to call it as a second write after
 * pp_operate_record_preflight(); that two-write gap could leave the run unlocked with
 * no restore baseline, so the content snapshot is now folded into
 * pp_operate_record_preflight()'s single critical section (which inlines the same
 * first-write-wins-by-post logic — it runs inside an already-held mutate_state lock and
 * cannot re-enter this function). This recorder is retained only as the unit-tested
 * reference for that first-write-wins shape; if the inlined copy and this one ever need
 * to change, change both. Any future caller that must record a content snapshot as its
 * own standalone write (not inside a preflight commit) can use it.
 *
 * @param string $run_id       The run token UUID.
 * @param int    $post_id      The post being snapshotted.
 * @param array  $composition  The pre-apply composition array.
 * @return bool
 */
function pp_operate_record_composition_content_snapshot( string $run_id, int $post_id, array $composition ): bool {
    return pp_operate_mutate_state( $run_id, static function ( array $data ) use ( $post_id, $composition ) {
        if ( ! isset( $data['composition_content_snapshot'] ) || ! is_array( $data['composition_content_snapshot'] ) ) {
            $data['composition_content_snapshot'] = [];
        }
        $key = (string) $post_id;
        if ( ! array_key_exists( $key, $data['composition_content_snapshot'] ) ) {
            $data['composition_content_snapshot'][ $key ] = $composition;
        }
        return $data;
    } );
}

/**
 * Returns the frozen pre-apply composition content for a post in a run, or null (#133).
 *
 * Null when the run is unusable or no content snapshot was recorded for this post. A
 * valid run that snapshotted an empty composition returns [] — load-bearing: a
 * run-scoped restore reverts to [] (an intentionally empty page), not fail-closed.
 *
 * @param string $run_id   The run token UUID.
 * @param int    $post_id  The post whose pre-run composition is wanted.
 * @return array|null
 */
function pp_operate_get_composition_content_snapshot( string $run_id, int $post_id ): ?array {
    $data = pp_operate_read_state( $run_id );
    if ( $data === null || ! isset( $data['composition_content_snapshot'] ) || ! is_array( $data['composition_content_snapshot'] ) ) {
        return null;
    }
    $key = (string) $post_id;
    if ( ! array_key_exists( $key, $data['composition_content_snapshot'] ) || ! is_array( $data['composition_content_snapshot'][ $key ] ) ) {
        return null;
    }
    return $data['composition_content_snapshot'][ $key ];
}

/**
 * Reverts every composition a run touched back to its pre-run content snapshot (#133).
 *
 * The composition analogue of `wp pp apply restore`'s token revert: for each post in the
 * run's touched_post_ids, rewrite its composition to the pre-apply content frozen at
 * preflight. Scoped strictly to THIS run's touched posts — a page a DIFFERENT run
 * mutated is never touched. Each revert goes through pp_update_composition (its own lock
 * + marker bump + history entry), unconditional (no CAS): restoring the pre-run baseline
 * is the intent, mirroring the token restore's force-to-snapshot semantics.
 *
 * Fail-closed and per-post: a null touched set (unusable run) returns ok=false and
 * reverts nothing; a post missing its snapshot or whose write fails is recorded under
 * `skipped` while the rest proceed. Returns a structured report for the caller to render.
 *
 * @param string $run_id  The run token UUID.
 * @return array{ok:bool, error:?string, reverted:array, skipped:array}
 */
function pp_operate_restore_run_compositions( string $run_id ): array {
    $touched = pp_operate_get_touched_post_ids( $run_id );
    if ( $touched === null ) {
        return [ 'ok' => false, 'error' => 'no_touched_post_ids', 'reverted' => [], 'skipped' => [] ];
    }

    $reverted = [];
    $skipped  = [];
    foreach ( $touched as $post_id ) {
        $snapshot = pp_operate_get_composition_content_snapshot( $run_id, $post_id );
        if ( $snapshot === null ) {
            $skipped[] = [ 'post_id' => $post_id, 'reason' => 'no_snapshot' ];
            continue;
        }
        $before = pp_get_composition( $post_id );
        $result = pp_update_composition( $post_id, $snapshot );
        if ( is_wp_error( $result ) ) {
            $skipped[] = [ 'post_id' => $post_id, 'reason' => $result->get_error_code() ];
            continue;
        }
        $after      = pp_get_composition( $post_id );
        // issue 236: report current-rule findings on the restored composition without
        // ever blocking the rollback (parity with the restore_composition action, issue
        // 233 — a rollback must never be refused by a rule that landed after the
        // snapshot). Reuses the shared findings helper (pp_validate_composition_errors +
        // _smells); no second validator. Since #604 pp_get_composition() is a plain
        // decode — the read-path migration shim is gone — so $after is the snapshot's
        // literal stored bytes. A snapshot carrying a retired prop name or `variant`
        // therefore reports unknown_prop/invalid_composition here where it used to be
        // canonicalized away first. That is the 233/236 report-never-block contract
        // working on honest bytes, not a defect: the rollback still lands.
        //
        // BOUNDED PER POST, NOT PER REPORT (#654). This is the one findings consumer that
        // AGGREGATES — every touched post's report lands in one JSON document — so "a
        // per-report budget of 100" had to be resolved into a unit. Per post, for two
        // reasons that both point the same way:
        //
        //   the tail names a page. _pp_bounded_findings() closes a truncated report with
        //   `wp pp check page --post_id=N`. A budget spanning the whole run has no single
        //   page to name, so it would degrade to the `--post_id=<id>` placeholder exactly
        //   when the operator most needs to know WHICH page overflowed.
        //
        //   the CLI's seam counts POSTS. pp_operate_restore_run_finding_count() below
        //   counts reverted posts carrying any finding, and `wp pp apply
        //   restore-composition` warns on that count. A global budget would blank later
        //   posts' reports entirely, dropping them out of that count and reporting a
        //   cleaner rollback than actually happened — a bound that lies is worse than a
        //   long report.
        //
        // ACCEPTED RESIDUAL, stated so it is not discovered later: the CARRIED aggregate is
        // therefore 100 x touched posts, not 100. It is bounded by the run's own operations
        // (touched_post_ids grows one post per apply), not by input size, which is the
        // property the unbounded version lacked.
        //
        // AND THAT IS NOT THE PEAK. Say it precisely, because "bounded" invites the wrong
        // reading: _pp_composition_findings() below runs both engines to completion for
        // EACH post before the bound applies, so the transient allocation is still
        // input-sized per post (measured on #654's fixture: 22 MB for one page), and the
        // count budget only decides what survives it. Bounding that is the availability
        // gate's axis, not this one — it is #772, deliberately not a rider here.
        $reverted[] = [
            'post_id'  => $post_id,
            'changed'  => ( $before !== $after ),
            'findings' => _pp_bounded_findings( _pp_composition_findings( $after ), $post_id ),
        ];
    }

    return [ 'ok' => true, 'error' => null, 'reverted' => $reverted, 'skipped' => $skipped ];
}

/**
 * Whether a run-scoped composition restore fully reverted every touched post
 * (issue 242). A restore is INCOMPLETE when the run had no usable touched-post
 * record (`ok === false`) OR one or more touched posts could not be reverted
 * (non-empty `skipped` — missing snapshot or write failure). The CLI uses this
 * to fail closed: a machine consumer branching on the exit code must never read
 * a partial restore as a full one.
 *
 * @param array $report  A pp_operate_restore_run_compositions() result.
 * @return bool  True iff the restore reverted all touched posts and none was skipped.
 */
function pp_operate_restore_run_complete( array $report ): bool {
    return ! empty( $report['ok'] ) && empty( $report['skipped'] );
}

/**
 * Number of reverted posts whose restored composition carries current-rule
 * findings (issue 236). The run-scoped restore never blocks on newer validation
 * rules, so the CLI uses this to WARN (not fail) when a restored composition
 * would not pass current validation. Counts POSTS with a non-empty findings
 * array, not total findings, and only over `reverted` entries — `skipped` posts
 * were never rewritten and carry no findings key. The decision seam the CLI
 * branches on, mirroring pp_operate_restore_run_complete().
 *
 * @param array $report  A pp_operate_restore_run_compositions() result.
 * @return int  Count of reverted posts reporting at least one finding.
 */
function pp_operate_restore_run_finding_count( array $report ): int {
    $reverted = isset( $report['reverted'] ) && is_array( $report['reverted'] ) ? $report['reverted'] : [];
    $count = 0;
    foreach ( $reverted as $entry ) {
        if ( ! empty( $entry['findings'] ) ) {
            $count++;
        }
    }
    return $count;
}

/**
 * True iff the run is currently usable as a rollback source: the state is valid for
 * this install AND a frozen pre-apply snapshot exists. Used as execute()'s pre-mutation
 * gate so a change that could not be rolled back is never applied in the first place.
 *
 * @param string $run_id  The run token UUID.
 * @return bool
 */
function pp_operate_run_rollbackable( string $run_id ): bool {
    return pp_operate_get_token_snapshot( $run_id ) !== null;
}

/**
 * Deletes the run's state option. Called at HANDOFF (run completion).
 *
 * No-op if the row does not exist or the run-id is invalid. Completing a run removes
 * its row so successful runs never leave rows behind (#409).
 *
 * @param string $run_id The run token UUID.
 */
function pp_operate_cleanup_run( string $run_id ): void {
    pp_operate_delete_state( $run_id );
}

// ── Semantic Composition Operator ──────────────────────────────────────────

/**
 * Resolves a component target within a composition array.
 *
 * Accepts either a component_id (authored id, or auto-generated pp-<hex8> —
 * the latter is regenerated by a full update_composition re-apply, #232) or a
 * component_index (0-based array position). Returns the resolved index
 * and component data, or WP_Error on failure.
 *
 * When both component_id and component_index are provided, component_id
 * takes precedence.
 *
 * @param array $composition  The composition array to search.
 * @param array $target       Target descriptor: ['component_id' => string] or ['component_index' => int].
 * @return array|WP_Error     ['index' => int, 'component' => array] or WP_Error.
 */
function pp_resolve_component_target(array $composition, array $target) {
    $has_id    = isset($target['component_id']) && $target['component_id'] !== '';
    $has_index = isset($target['component_index']);

    if (!$has_id && !$has_index) {
        return new WP_Error('no_target', 'No component_id or component_index provided.');
    }

    // component_id takes precedence
    if ($has_id) {
        $id = $target['component_id'];
        // Collect every match rather than returning the first (issue 238). Write-time
        // validation now rejects duplicate ids, but state written through raw,
        // non-validating paths can still carry a collision — resolving it silently
        // to the first match is the wrong-targeting bug. Fail closed instead.
        $matches = [];
        foreach ($composition as $index => $item) {
            if (isset($item['props']['id']) && $item['props']['id'] === $id) {
                $matches[] = $index;
            }
        }
        if (count($matches) > 1) {
            return new WP_Error(
                'component_ambiguous',
                sprintf(
                    'Ambiguous target: %d components share id "%s" (indexes %s). Ids must be unique to target one.',
                    count($matches),
                    $id,
                    implode(', ', $matches)
                ),
                ['indexes' => $matches]
            );
        }
        if (count($matches) === 1) {
            return ['index' => $matches[0], 'component' => $composition[$matches[0]]];
        }
        return new WP_Error(
            'component_not_found',
            sprintf('No component with id "%s" found in composition.', $id)
        );
    }

    // component_index fallback
    $idx   = (int) $target['component_index'];
    $count = count($composition);
    if ($idx < 0 || $idx >= $count) {
        return new WP_Error(
            'index_out_of_bounds',
            sprintf('Component index %d is out of bounds (0..%d).', $idx, $count - 1)
        );
    }

    return ['index' => $idx, 'component' => $composition[$idx]];
}

/**
 * Parses a semantic composition selector string into a structured target.
 *
 * Supported patterns:
 *   hero.subheading                              → simple type + field
 *   section[title="About Us"].body             → type + match + field
 *   grid[title="Features"].items[title="X"].text → nested item targeting
 *   hero[id="pp-a1b2c3d4"].subheading            → ID-based targeting
 *
 * Escape rules: \" for literal quote inside match values, \\ for literal backslash.
 *
 * @param string $selector_string  The selector string to parse.
 * @return array|WP_Error  Structured target or WP_Error on invalid input.
 */
function pp_parse_composition_selector(string $selector_string) {
    $selector = trim($selector_string);
    if ($selector === '') {
        return new WP_Error('invalid_selector', 'Selector string is empty.');
    }

    // ── Parse component type ──
    // Consume leading word chars as the component type.
    if (!preg_match('/^([a-z][a-z0-9_]*)/', $selector, $m)) {
        return new WP_Error('invalid_selector', sprintf('Invalid selector: cannot parse component type from "%s".', $selector));
    }
    $component_type = $m[1];
    $rest = substr($selector, strlen($component_type));

    $result = ['component_type' => $component_type];

    // ── Parse optional bracket match on the component ──
    if (str_starts_with($rest, '[')) {
        $parsed = _pp_parse_bracket_match($rest);
        if (is_wp_error($parsed)) {
            return $parsed;
        }
        // Special case: id match sets component_id instead of match_field/match_value.
        if ($parsed['field'] === 'id') {
            $result['component_id'] = $parsed['value'];
        } else {
            $result['match_field'] = $parsed['field'];
            $result['match_value'] = $parsed['value'];
        }
        $rest = $parsed['rest'];
    }

    // ── Expect a dot separator ──
    if (!str_starts_with($rest, '.')) {
        return new WP_Error('invalid_selector', sprintf('Invalid selector: expected "." after component type/match in "%s".', $selector));
    }
    $rest = substr($rest, 1);

    // ── Check for nested items targeting: items[field="value"].field ──
    if (str_starts_with($rest, 'items')) {
        $rest = substr($rest, 5); // consume 'items'
        if (!str_starts_with($rest, '[')) {
            return new WP_Error('invalid_selector', sprintf('Invalid selector: expected bracket match after "items" in "%s".', $selector));
        }
        $parsed = _pp_parse_bracket_match($rest);
        if (is_wp_error($parsed)) {
            return $parsed;
        }
        $result['nested_match_field'] = $parsed['field'];
        $result['nested_match_value'] = $parsed['value'];
        $rest = $parsed['rest'];

        if (!str_starts_with($rest, '.')) {
            return new WP_Error('invalid_selector', sprintf('Invalid selector: expected "." after nested item match in "%s".', $selector));
        }
        $rest = substr($rest, 1);
    }

    // ── Parse target field ──
    if (!preg_match('/^([a-z][a-z0-9_]*)$/', $rest, $m)) {
        return new WP_Error('invalid_selector', sprintf('Invalid selector: cannot parse target field from "%s".', $selector));
    }
    $result['target_field'] = $m[1];

    return $result;
}

/**
 * Parses a bracket match expression like [field="value"] from the start of a string.
 *
 * Handles escaped quotes (\") and escaped backslashes (\\) inside the value.
 *
 * @param string $str  String starting with '['.
 * @return array|WP_Error  ['field' => string, 'value' => string, 'rest' => string] or WP_Error.
 */
function _pp_parse_bracket_match(string $str) {
    // Match: [field="...escaped content..."]
    // We need to manually parse to handle escapes properly.
    if (!str_starts_with($str, '[')) {
        return new WP_Error('invalid_selector', 'Expected "[" at start of bracket match.');
    }

    $pos = 1; // skip '['
    $len = strlen($str);

    // Parse field name
    $field_start = $pos;
    while ($pos < $len && $str[$pos] !== '=' && $str[$pos] !== ']') {
        $pos++;
    }
    $field = substr($str, $field_start, $pos - $field_start);
    if ($field === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
        return new WP_Error('invalid_selector', sprintf('Invalid match field: "%s".', $field));
    }

    // Expect ="
    if ($pos + 1 >= $len || $str[$pos] !== '=' || $str[$pos + 1] !== '"') {
        return new WP_Error('invalid_selector', 'Expected =" after match field name.');
    }
    $pos += 2; // skip ="

    // Parse value with escape handling
    $value = '';
    while ($pos < $len) {
        $ch = $str[$pos];
        if ($ch === '\\' && $pos + 1 < $len) {
            $next = $str[$pos + 1];
            if ($next === '"' || $next === '\\') {
                $value .= $next;
                $pos += 2;
                continue;
            }
        }
        if ($ch === '"') {
            $pos++; // skip closing quote
            break;
        }
        $value .= $ch;
        $pos++;
    }

    // Expect ]
    if ($pos >= $len || $str[$pos] !== ']') {
        return new WP_Error('invalid_selector', 'Expected "]" after match value.');
    }
    $pos++; // skip ]

    return [
        'field' => $field,
        'value' => $value,
        'rest'  => substr($str, $pos),
    ];
}

// ── Component Field Editability (schema-derived) ──────────────────────────
//
// The semantic patch surface (`operate patch`, `operate inspect-composition`)
// derives its patchable-field set DIRECTLY from each component's schema.json —
// there is no hand-maintained registry (issue #509 retired the old
// pp_register_component_fields() list, which covered only 6 of 10 components
// with partial fields and silently lagged every schema change). A prop is
// patchable when its schema `type` is a scalar (string / number / enum);
// array/object props are structural, not single-value edits.
// This inherits the shared validator's type + `format: "link_url"` enforcement
// (issues #506/#507): the patch routes through the update_component action, so
// the schema's declared type/format is what actually validates the written
// value. No parallel type vocabulary, no second validator (repo invariant).
//
//   schema.json props ──► pp_component_scalar_type() ──► derived field list
//                              (string|number|enum)          consumed by
//                                                            inspect + patch
//
// There is NO per-prop opt-out. #509 (v1.8.2) shipped a `"patchable": false`
// escape hatch here and documented it in AI_IMPLEMENTATION_RECIPES; it was a
// legal declaration then. #575 (v1.12.4) later closed the prop definition key
// set (pp_prop_definition_keys, lib/admin.php) WITHOUT it, so from that release
// a schema following those docs failed CI on an unknown definition key while
// the docs went on instructing it. No schema in this repo ever declared it, in
// either window. #629 resolved the contradiction by deleting the readers rather
// than widening the key set — the same CALL as the `aliases` retirement in #606,
// on different grounds (`aliases` was a declared key emptied by #603/#604/#605;
// this one was documented, legal for a while, and never used). A prop that must
// never be edited in isolation would need the key added to that set plus a
// bounded boolean check in pp_schema_definition_errors(); nothing needs that.

/**
 * The scalar prop `type` values that are patchable in isolation via the
 * semantic patch surface. A prop with one of these declared types is exposed
 * as an editable field; array/object props are structural and are not patched
 * as a single scalar value (their scalar item sub-props are patched via the
 * items[].field selector).
 *
 * @return string[]
 */
function pp_patchable_scalar_types(): array {
    return ['string', 'number', 'enum'];
}

/**
 * Returns the patchable scalar `type` of a schema prop definition, or null when
 * the prop is not a patchable scalar (array/object, unknown type, malformed def).
 * The declared `type` is the ONLY input: the `patchable: false` opt-out this
 * function used to honour is retired (#629), so a definition still carrying that
 * key derives from its type like any other.
 *
 * @param mixed $prop_def  A single prop definition from a component schema.
 * @return string|null  The scalar type ('string'|'number'|'enum'), or null.
 */
function pp_component_scalar_type($prop_def): ?string {
    if (!is_array($prop_def)) {
        return null;
    }
    $type = $prop_def['type'] ?? null;
    return in_array($type, pp_patchable_scalar_types(), true) ? $type : null;
}

/**
 * Derives the patchable fields for a component type from its schema (issue #509).
 *
 * Returns a list of field descriptors — the same shape the retired manual
 * registry produced, so pp_inspect_composition() and pp_patch_composition()
 * consume it unchanged: each is ['name' => string, 'type' => string] plus an
 * optional 'format' key when the schema declares one (e.g. 'link_url'). Nested
 * item sub-props of the `items` array are emitted as 'items[].<field>' — the
 * selector grammar (pp_parse_composition_selector) only parses a nested array
 * literally named `items`, so only that prop yields nested patch fields
 * (panel_items / headers / rows stay top-level-only, unreachable by the grammar
 * and therefore not emitted as nested fields).
 *
 * @param string $component_type  Component type name (e.g. 'hero', 'section').
 * @return array  List of field descriptors; empty when the type has no schema.
 */
function pp_get_component_fields(string $component_type): array {
    $components = function_exists('pp_get_registered_components') ? pp_get_registered_components() : [];
    $props = $components[$component_type]['props'] ?? null;
    if (!is_array($props)) {
        return [];
    }

    $fields = [];
    foreach ($props as $prop_name => $prop_def) {
        $scalar_type = pp_component_scalar_type($prop_def);
        if ($scalar_type !== null) {
            $field = ['name' => $prop_name, 'type' => $scalar_type];
            if (isset($prop_def['format']) && is_string($prop_def['format'])) {
                $field['format'] = $prop_def['format'];
            }
            $fields[] = $field;
            continue;
        }

        // Nested object-item array named `items`: expose its scalar sub-props as
        // items[].<field>. Gated on the prop name so it aligns with the selector
        // grammar; a named sub-schema (associative field defs) confirms it is an
        // object-item array (body_items / a bare string array carries no such map).
        // The `patchable: false` re-check that used to sit here — opting a whole
        // nested surface out — went with the retired opt-out (#629); an `items`
        // array expands its scalar sub-props on shape alone.
        if ($prop_name === 'items'
            && is_array($prop_def)
            && ($prop_def['type'] ?? null) === 'array'
            && !empty($prop_def['items'])
            && is_array($prop_def['items'])
        ) {
            foreach ($prop_def['items'] as $sub_name => $sub_def) {
                $sub_type = pp_component_scalar_type($sub_def);
                if ($sub_type === null) {
                    continue;
                }
                $sub_field = ['name' => 'items[].' . $sub_name, 'type' => $sub_type];
                if (isset($sub_def['format']) && is_string($sub_def['format'])) {
                    $sub_field['format'] = $sub_def['format'];
                }
                $fields[] = $sub_field;
            }
        }
    }

    return $fields;
}

/**
 * Returns the full derived field map: component_type => field descriptors,
 * for every component whose schema is registered (issue #509).
 *
 * @return array<string, array>
 */
function pp_get_registered_component_fields(): array {
    $components = function_exists('pp_get_registered_components') ? pp_get_registered_components() : [];
    $map = [];
    foreach (array_keys($components) as $type) {
        $fields = pp_get_component_fields($type);
        if (!empty($fields)) {
            $map[$type] = $fields;
        }
    }
    return $map;
}

// ── Component Schema Report (#688) ───────────────────────────────────────

/**
 * Renders one declaration's CONDITION into the catalog's phrasing (#688).
 *
 * ONE VOCABULARY, NOT TWO. The clause wording comes from
 * pp_ai_format_applies_when_clause() (lib/ai-context.php) — the same function the
 * runtime AI catalog and the guardrails advisory use — so an agent reading
 * `wp pp schema hero` and an agent reading the chat catalog see the SAME condition in
 * the SAME words. This helper adds no grammar of its own; it only assembles.
 *
 * ONE CONDITION, HOWEVER IT IS EXPRESSED. A declaration may carry `applies_when` (the
 * clauses the bounded grammar can express) and `conditionality_note` (the three classes
 * it deliberately cannot — disjunction, composed-page context, interaction state), and
 * when it carries both they are a CONJUNCTION. Nineteen shipped slots and nine props do
 * carry both. Rendering the clauses ALONE would emit a strictly LOOSER condition that
 * reads as complete — hero's `--hero-image-radius` would report `layout = "split"` while
 * the real condition also needs an image column — which is the same "shorter condition
 * that reads as complete" failure the all-or-nothing rule below exists to prevent. So the
 * note is conjoined here exactly as pp_ai_definition_suffix() (lib/ai-context.php)
 * conjoins it, trailing period trimmed, clauses first.
 *
 * That two-line composition is the ONE thing duplicated from the catalog emitter, and it
 * is duplicated on purpose rather than hidden behind a shared helper: the catalog emits
 * prose with `role:` bits interleaved, so there is nothing to reuse without parsing prose
 * back apart. It is held in place mechanically instead —
 * CliSchemaCommandTest::testAppliesWhenRenderedUsesTheRuntimeCatalogVocabulary asserts,
 * for EVERY declared condition in the shipped twelve, that pp_ai_definition_suffix()
 * contains the exact phrase this function produced. The two cannot drift silently.
 *
 * ALL-OR-NOTHING for the clauses, deliberately. Clauses in an `applies_when` array are
 * ANDed (pp_applies_when_clause_errors, lib/admin.php), so the condition holds only when
 * EVERY clause does. pp_ai_format_applies_when_clause() renders '' for a clause the
 * grammar engine rejects, and joining just the survivors would understate the condition,
 * so one unrenderable clause nulls the whole rendered string. The raw `applies_when`
 * array and `conditionality_note` still ship verbatim beside it, so nothing is hidden.
 *
 * (The shipped twelve cannot reach the null branch — SchemaValidationTest holds the
 * closed grammar over every declared clause in CI. It is reachable on a hand-edited
 * schema on a live install, which is exactly where an invented condition would do
 * the most damage.)
 *
 * Guarded with function_exists so a partial include (lib/operate.php without
 * lib/ai-context.php) degrades to "no rendering" rather than fataling — the same
 * defensive shape pp_ai_format_applies_when_clause() itself carries.
 *
 * @param  array $definition  A prop or style-slot definition object from schema.json.
 * @return string|null        The ANDed condition phrase, or null when nothing renders.
 */
function _pp_schema_report_applies_when(array $definition): ?string {
    $conditions = [];

    $clauses = array_key_exists('applies_when', $definition) ? $definition['applies_when'] : null;

    // A DECLARED but unreadable `applies_when` voids everything, including the note.
    // This is the same all-or-nothing rule as the unrenderable-clause branch below, and
    // it has to fire here or the fallthrough would render the note ALONE — publishing a
    // strictly looser condition that reads as complete, which is the one outcome this
    // whole function exists to prevent. A hand-edited `"applies_when": "layout = split"`
    // is exactly how that happens.
    if ($clauses !== null && !is_array($clauses)) {
        return null;
    }

    if (is_array($clauses) && $clauses !== []) {
        if (!function_exists('pp_ai_format_applies_when_clause')) {
            return null;
        }
        foreach ($clauses as $clause) {
            $phrase = pp_ai_format_applies_when_clause($clause);
            if ($phrase === '') {
                return null; // One unrenderable clause voids the conjunction.
            }
            $conditions[] = $phrase;
        }
    }

    $note = $definition['conditionality_note'] ?? null;
    if (is_string($note) && trim($note) !== '') {
        // rtrim of the trailing period mirrors pp_ai_definition_suffix(). A note that is
        // NOTHING BUT punctuation trims to '' and must not be appended: it would emit a
        // dangling "clause AND " (a truncated condition) or a bare "" (a third state the
        // contract does not define). Nothing declared, nothing rendered.
        $trimmed = rtrim(trim($note), '.');
        if ($trimmed !== '') {
            $conditions[] = $trimmed;
        }
    }

    return $conditions ? implode(' AND ', $conditions) : null;
}

/**
 * Promotes a keyed schema map into the list-of-objects the CLI envelope uses (#688).
 *
 * schema.json declares props, style slots and recipes as OBJECTS keyed by name.
 * `inspect-composition` (below) already established the CLI shape for the same data:
 * a LIST whose entries carry the key promoted to a named field. This does that promotion
 * and NOTHING else — the declaration's own keys and values pass through untouched, in
 * declaration order.
 *
 * "Untouched" is the whole contract. The report must not inject a default the schema
 * did not declare, must not drop a key it does not recognise, and must not reorder.
 * A projection that hand-picks keys is a SECOND VIEW of the schema, and a second view
 * is what drifts (#223's root-cause class). Because this copies whatever is declared,
 * a schema key added tomorrow reaches the CLI the day it is added, with no edit here.
 * CliSchemaCommandTest measures that BOTH ways: verbatim equality against the shipped
 * twelve, plus a fixture declaring a key that is in neither closed registry — because the
 * shipped twelve declare only registry keys, they alone cannot tell "copies the
 * declaration" apart from "copies the keys we happen to know".
 *
 * SCOPE OF THE VERBATIM PROMISE, stated so nobody reads more into it than is true: it
 * covers the ENTRY level — one prop, one slot, one recipe. The report's TOP level is a
 * curated envelope, bounded by what #688 asked for (props, style slots with their
 * conditions, recipes), and a new top-level or `styling.*` key needs an edit in
 * pp_component_schema_report() below. That is a deliberate scope line, not an oversight;
 * the excluded declarations are named there.
 *
 * @param  mixed  $map      Keyed definitions from schema.json (name => definition).
 *                          Non-array input (a hand-edited schema putting a scalar where
 *                          the map belongs) yields an empty list rather than a fatal.
 * @param  string $key_name The field the map key is promoted into ('name' or 'slot').
 * @param  bool   $render   Whether to append the derived `applies_when_rendered` field.
 * @return array[]          List of definition objects, declaration order preserved.
 */
function _pp_schema_report_entries($map, string $key_name, bool $render): array {
    // `props: "nope"` in a hand-edited schema.json reaches here as a string; an `array`
    // parameter type would make the whole report fatal on it, so it degrades to an empty
    // list instead.
    //
    // The equivalent shapes under `styling` are NOT defended here and cannot be: a scalar
    // `styling.style_slots` / `styling.recipes` throws inside pp_get_style_slots() /
    // pp_get_style_recipes() (lib/wp.php), whose `: array` return types reject the value
    // before it ever reaches this function. That is a pre-existing defect on the shared
    // accessors — the render path, the AI catalog and the guardrails all reach it too —
    // and #688 deliberately does not fix it here; widening one caller's guard would leave
    // the other four. Tracked separately.
    if (!is_array($map)) {
        return [];
    }

    $entries = [];

    foreach ($map as $name => $definition) {
        if (!is_array($definition)) {
            // A malformed declaration is REPORTED, not swallowed: the operator sees the
            // name and the raw value rather than a silently shorter list. Flagged and
            // shape-compatible, so a consumer branches on `malformed` rather than on a
            // missing key, and cannot confuse this with a declaration whose schema key
            // happens to be spelled `declaration`.
            $entry = [
                $key_name     => (string) $name,
                'malformed'   => true,
                'declaration' => $definition,
            ];
            if ($render) {
                $entry['applies_when_rendered'] = null;
            }
            $entries[] = $entry;
            continue;
        }

        // Union, not array_merge: the promoted identity key must survive, and `+` keeps
        // the LEFT operand on a key collision while appending the declaration in its own
        // order. CliSchemaCommandTest pins that the closed registries never claim these
        // names, so a SHIPPED schema cannot collide.
        //
        // A hand-edited one can, and silently losing a declared value on an agent-facing
        // contract is not acceptable even there — `props: [{"name": "title", …}]`, the
        // list-shaped mistake, lands here as key "0" with a declared `name` that the
        // union would swallow without trace. So the collision is DISCLOSED: the promoted
        // and derived fields still win (they are the only things that address and
        // describe the entry), and `shadowed_keys` names what they displaced. Absent when
        // nothing collides, which is every entry in the shipped twelve.
        $reserved = $render ? [$key_name, 'applies_when_rendered'] : [$key_name];
        $shadowed = array_keys(array_intersect_key($definition, array_flip($reserved)));

        $entry = [$key_name => (string) $name] + $definition;
        if ($render) {
            $entry['applies_when_rendered'] = _pp_schema_report_applies_when($definition);
        }
        if ($shadowed) {
            $entry['shadowed_keys'] = $shadowed;
        }
        $entries[] = $entry;
    }

    return $entries;
}

/**
 * The component index behind bare `wp pp schema` (#688).
 *
 * Lists every REGISTERED component — all twelve — because this command reports what a
 * component DECLARES, and `nav`/`footer` declare props like any other. What it does not
 * do is let that list read as an invitation: each entry carries `composable`, so the
 * registered ⊋ composable distinction (lib/admin.php) travels with the name instead of
 * being something an agent has to already know. Advertising chrome as composable is
 * literally how #223 happened; advertising it as chrome is how an agent learns why
 * `wp pp schema nav` is readable and `nav` in a composition is not.
 *
 * @return array[]  One entry per registered component, in loader (scandir) order.
 */
function pp_component_schema_index(): array {
    $index = [];

    foreach (array_keys(pp_get_registered_components()) as $name) {
        $index[] = [
            'component'  => $name,
            'composable' => !pp_is_template_owned_component($name),
        ];
    }

    return $index;
}

/**
 * The full schema report behind `wp pp schema <component>` (#688).
 *
 * WHY THIS EXISTS. The schema is the most-consulted contract in the system and, until
 * this command, the only one with no sanctioned read surface: an SSH-only or chat-context
 * agent had to open `components/<name>/schema.json` off disk to learn a slot name. It now
 * asks.
 *
 *     components/<name>/schema.json
 *              │
 *              ▼
 *     pp_get_registered_components()          ← THE loader. There is no second parser.
 *              │
 *              ├── ['props']                 ─┐
 *              ├── pp_get_style_slots()       ├─► _pp_schema_report_entries()   (verbatim)
 *              └── pp_get_style_recipes()    ─┘            │
 *                                                          └─► _pp_schema_report_applies_when()
 *     pp_is_template_owned_component()                              │
 *              │   (independent lookup, lib/admin.php)              └─► pp_ai_format_applies_when_clause()
 *              ▼
 *     { component, description, composable, [content_requirement],
 *       props[], style_slots[], recipes[] }
 *
 * The two accessors are raw passthroughs of `styling.style_slots` / `styling.recipes`
 * (lib/wp.php) — they normalise nothing — which is what lets this report promise that its
 * data IS the shipped schema's data, not a rendering of it. `applies_when_rendered` is the
 * single derived field, and it is derived by the catalog's own formatter.
 *
 * WHAT IT DELIBERATELY DOES NOT EMIT, named so the omission is a decision and not a
 * silence. #688 scoped this surface to props, style slots with their conditions, and
 * recipes. The other declarations in `styling` — `root_class`, `variant_classes`,
 * `tokens`, and `chrome_custom_properties` — plus the top-level `safe_to_edit` /
 * `do_not_touch` file-maintenance notes stay out. One consequence is worth knowing before
 * reading a report: `nav` and `footer` declare NO style slots and NO recipes at all, so
 * their reports show empty lists while their real styling surface is
 * `chrome_custom_properties` (`--header-bg`, `--header-text`, `--header-link-color`) —
 * still readable only in `schema.json` or ai-instructions/style-component.md. Widening
 * this envelope is a scope decision for a follow-up, not something to do in passing.
 *
 * READ-ONLY, NO RUN TOKEN. Same class as `inspect-composition`: it reads declarations off
 * disk, touches no post, mints nothing, and is safe to call at any point in a run.
 *
 * NAMES ARE NOT CANONICALISED, matching the rest of the pipeline (#603-#606): `Hero` is
 * not `hero`. The refusal names every available component, so a case or spelling miss is
 * one line from being fixed rather than a guessing game.
 *
 * @param  string $component  Component name, exactly as the directory is spelled.
 * @return array|WP_Error     The report, or WP_Error naming the available components.
 */
function pp_component_schema_report(string $component): array|WP_Error {
    $components = pp_get_registered_components();

    if (!isset($components[$component])) {
        $available = array_keys($components);
        return new WP_Error(
            'unknown_component',
            sprintf(
                'Unknown component "%s". Available: %s. Names are case-sensitive and are never canonicalised.',
                $component,
                $available ? implode(', ', $available) : '(none registered)'
            )
        );
    }

    $schema = $components[$component];

    // `component` comes from the LOADER KEY, not from `schema.component`. The key is the
    // directory the renderer resolves (`components/<key>/<key>.php`), so it is the name
    // that actually addresses this component — and it is still present when the schema
    // failed to decode and every declared field is gone.
    $report = [
        'component'   => $component,
        'description' => $schema['description'] ?? null,
        'composable'  => !pp_is_template_owned_component($component),
    ];

    // The loader answers [] for a schema.json it could not read or decode, and a report
    // built from [] is a perfectly valid-LOOKING document saying "no props, no slots, no
    // recipes". That is worse than an error: an agent would read a real component as
    // having no contract and act on it. Every registered component declares SOMETHING
    // (the directory only registers when `<name>.php` exists), so an empty decode is
    // always a broken file, and it is marked as one.
    if ($schema === []) {
        $report['malformed'] = true;
    }

    // Emitted only when declared, so its absence is not mistaken for "declared empty".
    // Today only `section` carries one (#488): the schema-level statement that a band
    // with every prop optional still needs SOME content.
    if (isset($schema['content_requirement'])) {
        $report['content_requirement'] = $schema['content_requirement'];
    }

    $report['props']       = _pp_schema_report_entries($schema['props'] ?? [], 'name', true);
    $report['style_slots'] = _pp_schema_report_entries(pp_get_style_slots($component), 'slot', true);
    $report['recipes']     = _pp_schema_report_entries(pp_get_style_recipes($component), 'name', false);

    return $report;
}

// ── Inspect Composition ──────────────────────────────────────────────────

/**
 * Inspects a page's composition and returns editable targets with selectors.
 *
 * For each component, looks up the field editability map and builds semantic
 * selector strings for each editable field along with the current value.
 * Components not in the field map are included with an empty fields array.
 *
 * A CORRUPT ROW IS NOT AN EMPTY PAGE (#725). This read used to go through
 * pp_get_composition(), the legacy array-only accessor, which degrades a corrupt or
 * wrong-shaped stored value to `[]` (lib/wp.php). So on a page whose `_pp_composition`
 * decodes to a JSON object, `wp pp operate inspect-composition` printed `[]` — no
 * fabricated locators, so the honest-locator contract held, but an agent reading that
 * output concludes "this page has no components" when the truth is "this page is
 * unreadable", and the next thing it does is author over recoverable bytes. `operate
 * inspect` has surfaced `composition_decode_error` for exactly this class since #144;
 * this was the sibling hole for the decodable-but-wrong-shape half.
 *
 * It now reads through pp_get_composition_result(), the single decode+classify owner, and
 * returns the classification itself as the WP_Error CODE — `unexpected_shape` /
 * `decode_error`, the same two words `operate inspect`, `check page`, `validate site` and
 * (since #724) the write path all use for this state. Both existing callers already
 * branch on is_wp_error, so nothing needed rewiring — but only ONE of them is actually
 * reached, and saying so is the point of this paragraph. The CLI turns the error into
 * `WP_CLI::error` (a non-zero exit and a readable line, instead of a reassuring `[]`);
 * that is the surface #725 fixes. The AI chat context builder's is_wp_error branch
 * (lib/ai-context.php) is DEAD: `pp_ai_page_context()` reads the composition through
 * `pp_get_composition()`, which degrades a corrupt row to `[]`, and the builder's
 * `!empty($page_ctx['composition'])` guard then skips the call to this function
 * entirely — so on a corrupt page the chat is still told the page is blank. That half is
 * NOT fixed here: it needs a decision about what the system prompt should say, which is
 * a chat-surface call rather than a read-path one. Filed rather than assumed fixed.
 *
 * The message is the shared integrity sentence plus this surface's own next action. The
 * diagnosis is single-owned (pp_composition_integrity_message) so a new spelling of
 * one state cannot appear here; the repair tail is local because it is the only part that
 * is specific to "you were trying to edit this page".
 *
 * @param int $post_id  The WordPress post ID.
 * @return array|WP_Error  Array of component targets, or WP_Error naming the corruption.
 */
function pp_inspect_composition(int $post_id): array|WP_Error {
    $stored = pp_get_composition_result($post_id);
    if (!$stored['ok']) {
        return new WP_Error(
            $stored['error'],
            pp_composition_integrity_message($post_id, $stored['error'])
            . ' No component targets can be read from it. '
            . pp_corrupt_repair_route_message($post_id)
        );
    }
    $composition = $stored['composition'];

    $targets = [];
    foreach ($composition as $index => $item) {
        $type = $item['component'] ?? 'unknown';
        $props = $item['props'] ?? [];
        $component_id = $props['id'] ?? null;
        $fields_def = pp_get_component_fields($type);

        $fields = [];
        foreach ($fields_def as $fdef) {
            $field_name = $fdef['name'];
            $field_type = $fdef['type'];
            // Schema-declared format (e.g. 'link_url', 'image_url'), surfaced so
            // inspect callers see the same type/format the shared validator
            // enforces on a patch (#506/#507/#509). Null when the prop has none.
            $field_format = $fdef['format'] ?? null;

            // Nested items field (e.g. items[].title)
            if (str_starts_with($field_name, 'items[].')) {
                $nested_field = substr($field_name, 8); // strip 'items[].'
                $items = $props['items'] ?? [];
                foreach ($items as $item_data) {
                    // Build a selector for each item using its identifying field.
                    // For nested items, we need a match field to identify the item.
                    // Use the first non-target field that has a string value as the match.
                    $match_field = _pp_pick_nested_match_field($type);
                    if ($match_field === null || !isset($item_data[$match_field])) {
                        continue;
                    }
                    $match_value = $item_data[$match_field];
                    $escaped_match = str_replace(['\\', '"'], ['\\\\', '\\"'], $match_value);
                    $escaped_comp_match = '';
                    if (isset($props['title'])) {
                        $escaped_comp_title = str_replace(['\\', '"'], ['\\\\', '\\"'], $props['title']);
                        $escaped_comp_match = sprintf('[title="%s"]', $escaped_comp_title);
                    }
                    $selector = sprintf(
                        '%s%s.items[%s="%s"].%s',
                        $type, $escaped_comp_match, $match_field, $escaped_match, $nested_field
                    );
                    $fields[] = [
                        'selector'      => $selector,
                        'field'         => $field_name,
                        'field_type'    => $field_type,
                        'field_format'  => $field_format,
                        'current_value' => $item_data[$nested_field] ?? null,
                    ];
                }
            } else {
                // Top-level field
                $selector = sprintf('%s.%s', $type, $field_name);
                // If there could be multiple components of the same type, qualify with match.
                if (isset($props['title']) && $field_name !== 'title') {
                    $escaped_title = str_replace(['\\', '"'], ['\\\\', '\\"'], $props['title']);
                    $selector = sprintf('%s[title="%s"].%s', $type, $escaped_title, $field_name);
                }
                $fields[] = [
                    'selector'      => $selector,
                    'field'         => $field_name,
                    'field_type'    => $field_type,
                    'field_format'  => $field_format,
                    'current_value' => $props[$field_name] ?? null,
                ];
            }
        }

        // Style slot information: available slots with current overrides and defaults.
        $available_slots = pp_get_style_slots($type);
        $current_style   = $item['style'] ?? [];
        $style_slots     = [];
        foreach ($available_slots as $slot_name => $slot_def) {
            $style_slots[] = [
                'slot'    => $slot_name,
                'type'    => $slot_def['type'],
                'default' => $slot_def['default'],
                'current' => $current_style[$slot_name] ?? null,
            ];
        }

        // Active recipe tracking.
        $active_recipe = $current_style['__recipe'] ?? null;

        // Available recipes.
        $available_recipes = [];
        $recipes = pp_get_style_recipes($type);
        foreach ($recipes as $recipe_name => $recipe_def) {
            $available_recipes[] = [
                'name'        => $recipe_name,
                'description' => $recipe_def['description'] ?? '',
                'slot_count'  => count($recipe_def['slots'] ?? []),
            ];
        }

        $targets[] = [
            'component_type'    => $type,
            'component_id'      => $component_id,
            'index'             => $index,
            'fields'            => $fields,
            'style_slots'       => $style_slots,
            'active_recipe'     => $active_recipe,
            'available_recipes' => $available_recipes,
        ];
    }

    return $targets;
}

/**
 * Picks the best match field for identifying a nested item within a component type.
 *
 * Derived from the component's `items` sub-schema (issue #509) so newly-covered
 * components (stats, logos) get a sensible default without a hand-maintained map.
 * Only builds the inspect-suggested selector; the patch parser accepts ANY
 * scalar item sub-field as a match, so this default never limits what an
 * operator can target. Prefers a human-readable identifying field (title,
 * question, quote, ...) to keep the pre-#509 selectors stable (grid→title,
 * faq→question, testimonials→quote), else falls back to the first scalar
 * sub-field.
 *
 * @param string $component_type  The component type.
 * @return string|null  The match field name, or null if none available.
 */
function _pp_pick_nested_match_field(string $component_type): ?string {
    $components = function_exists('pp_get_registered_components') ? pp_get_registered_components() : [];
    $item_schema = $components[$component_type]['props']['items']['items'] ?? null;
    if (!is_array($item_schema)) {
        return null;
    }

    // Preference order for a readable match handle (keeps historical selectors).
    foreach (['title', 'question', 'quote', 'label', 'name', 'number'] as $preferred) {
        if (isset($item_schema[$preferred]) && pp_component_scalar_type($item_schema[$preferred]) !== null) {
            return $preferred;
        }
    }

    // Fallback: first scalar sub-field in schema order.
    foreach ($item_schema as $sub_name => $sub_def) {
        if (pp_component_scalar_type($sub_def) !== null) {
            return $sub_name;
        }
    }

    return null;
}

// ── Patch Composition ────────────────────────────────────────────────────

/**
 * Patches a composition field by semantic selector.
 *
 * Parses the selector, resolves the target component, checks field editability,
 * and routes through the update_component action for preview or apply.
 *
 * @param int      $post_id          The WordPress post ID.
 * @param string   $selector_string  Semantic selector (e.g. "hero.subheading").
 * @param string   $value            The new value for the targeted field.
 * @param bool     $preview          If true, return diff without writing.
 * @param int|null $expected_version Optimistic-locking baseline (#13) threaded into the
 *                                   update_component action so the apply is an atomic
 *                                   compare-and-swap. Null skips the CAS (preview / callers
 *                                   without a run baseline).
 * @return array|WP_Error  Preview diff or action result, or WP_Error.
 */
function pp_patch_composition(int $post_id, string $selector_string, string $value, bool $preview = false, ?int $expected_version = null) {
    // 0. Page-existence gate (#399). Establishes not_found / not_a_page parity with
    // `wp pp action execute`, which guards its composition precondition on
    // _pp_validate_page_exists() inside pp_validate_action() (lib/actions.php) so a
    // nonexistent page fails with the action's own not_found. `operate patch` never
    // ran that check, so a numeric-but-nonexistent post id fell through to the step-2a
    // composition precondition below and surfaced the misleading 'composition_required'
    // ("post N has none yet") for a page that does not exist. Reuse the shared page
    // predicate here — no surface-specific second validator (repo invariant) — BEFORE
    // any composition access, so both the --preview and mutating patch paths report the
    // same error class as action execute. An existing composition-less page still gets
    // the clear 'composition_required' from step 2a.
    $page_exists = _pp_validate_page_exists($post_id);
    if (is_wp_error($page_exists)) {
        return $page_exists;
    }

    // 1. Parse selector
    $parsed = pp_parse_composition_selector($selector_string);
    if (is_wp_error($parsed)) {
        return $parsed;
    }

    $component_type = $parsed['component_type'];
    $target_field   = $parsed['target_field'];
    $is_nested      = isset($parsed['nested_match_field']);

    // 2. Read composition
    $composition = pp_get_composition($post_id);
    if (is_wp_error($composition)) {
        return $composition;
    }

    // 2a. Composition-presence precondition (#358, #387). This patch resolves the
    // target component (step 3) BEFORE it routes through pp_execute_action('update_component'),
    // where the shared validator's precondition would fire. On a composition-less
    // page component resolution returns the confusing component_not_found first, so
    // run the same shared predicate here to fail closed early with the clear
    // composition_required error. update_component defaults requires_composition=TRUE,
    // so this gate is closed on an empty page and open once content exists. Using the
    // one predicate (not a re-implemented check) keeps a single enforcement rule.
    // Guard the (theme-bug) case where update_component is unregistered: skip the
    // pre-check and let the downstream pp_execute_action('update_component') return
    // its graceful unknown_action error rather than fataling on a null action here.
    $patch_action = pp_get_action('update_component');
    if ($patch_action !== null) {
        $precondition = pp_action_composition_precondition($patch_action, $post_id);
        if (is_wp_error($precondition)) {
            return $precondition;
        }
    }

    // 3. Resolve component
    if (isset($parsed['component_id'])) {
        // ID-based targeting
        $resolved = pp_resolve_component_target($composition, ['component_id' => $parsed['component_id']]);
    } else {
        // Type-based targeting: find all components matching the type (and optional match_field)
        $matches = [];
        foreach ($composition as $idx => $item) {
            if (($item['component'] ?? '') !== $component_type) {
                continue;
            }
            if (isset($parsed['match_field'])) {
                $prop_value = $item['props'][$parsed['match_field']] ?? null;
                if ($prop_value !== $parsed['match_value']) {
                    continue;
                }
            }
            $matches[] = ['index' => $idx, 'component' => $item];
        }

        if (count($matches) === 0) {
            $detail = isset($parsed['match_field'])
                ? sprintf('No component of type "%s" matching %s="%s".', $component_type, $parsed['match_field'], $parsed['match_value'])
                : sprintf('No component of type "%s" found.', $component_type);
            return new WP_Error('component_not_found', $detail);
        }
        if (count($matches) > 1) {
            $ids = array_map(fn($m) => $m['component']['props']['id'] ?? '(no id)', $matches);
            return new WP_Error(
                'multiple_components',
                sprintf('Multiple components match. Use a more specific selector. Matching IDs: %s', implode(', ', $ids))
            );
        }

        $resolved = $matches[0];
    }

    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $component_index = $resolved['index'];
    $component       = $resolved['component'];
    $props           = $component['props'] ?? [];

    // 4. Check field editability
    $fields_def = pp_get_component_fields($component_type);
    if ($is_nested) {
        $field_key = 'items[].' . $target_field;
    } else {
        $field_key = $target_field;
    }
    $field_found = false;
    foreach ($fields_def as $fdef) {
        if ($fdef['name'] === $field_key) {
            $field_found = true;
            break;
        }
    }
    if (!$field_found) {
        $editable = array_column($fields_def, 'name');
        return new WP_Error(
            'field_not_editable',
            sprintf('Field "%s" is not editable on "%s". Editable fields: %s', $field_key, $component_type, implode(', ', $editable))
        );
    }

    // 5. Build update_component params
    if ($is_nested) {
        // Nested item targeting: reconstruct full items array
        $items = $props['items'] ?? [];
        $nested_match_field = $parsed['nested_match_field'];
        $nested_match_value = $parsed['nested_match_value'];

        $matched_indices = [];
        foreach ($items as $i => $item_data) {
            if (isset($item_data[$nested_match_field]) && $item_data[$nested_match_field] === $nested_match_value) {
                $matched_indices[] = $i;
            }
        }

        if (count($matched_indices) === 0) {
            return new WP_Error(
                'nested_item_not_found',
                sprintf('No item with %s="%s" found in items array.', $nested_match_field, $nested_match_value)
            );
        }
        if (count($matched_indices) > 1) {
            return new WP_Error(
                'nested_item_multi_match',
                sprintf('Multiple items match %s="%s". Matching item indices: %s', $nested_match_field, $nested_match_value, implode(', ', $matched_indices))
            );
        }

        // Replace the target field in the matched item
        $items[$matched_indices[0]][$target_field] = $value;

        $action_params = [
            'post_id'         => $post_id,
            'component_index' => $component_index,
            'props'           => ['items' => $items],
        ];
    } else {
        // Top-level field
        $action_params = [
            'post_id'         => $post_id,
            'component_index' => $component_index,
            'props'           => [$target_field => $value],
        ];
    }

    // 6. Preview or apply
    if ($preview) {
        return pp_preview_action('update_component', $action_params);
    }
    // Thread the optimistic-locking baseline (#13) into the apply so the write is an
    // atomic compare-and-swap. Null (preview or no run baseline) skips the CAS.
    if ($expected_version !== null) {
        $action_params['expected_version'] = $expected_version;
    }
    return pp_execute_action('update_component', $action_params);
}
