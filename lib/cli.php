<?php
/**
 * lib/cli.php — PromptingPress WP-CLI Commands
 *
 * Loaded conditionally in functions.php when WP_CLI is active.
 * Provides `wp pp action` subcommands: list, preview, execute.
 */

if (!class_exists('WP_CLI') || !class_exists('WP_CLI_Command')) {
    return;
}

/**
 * Reports a preflight whose checks passed but whose state could not be
 * recorded (#227). Every post-check failure exit in `apply preflight` goes
 * through here so the single emit path keeps the JSON contract fail-closed:
 * stdout — the machine-readable channel — gets {"ok": false, "error": ...}
 * (with the computed checks for diagnosis), and the human-readable detail
 * goes to STDERR via WP_CLI::error, which exits 1. Never printing
 * {"ok": true} for a preflight that did not complete is the invariant.
 *
 * @param array  $result  The pp_preflight() result whose checks passed.
 * @param string $message The failure detail (also used as the JSON "error").
 */
function _pp_cli_preflight_record_failed(array $result, string $message): void {
    $result['ok']    = false;
    $result['error'] = $message;
    WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    WP_CLI::error($message);
}

/**
 * Pure decision for the --run-id gate (#390): returns the fail-closed error
 * message, or null when the arg is present and a valid UUID v4. Split out of
 * _pp_cli_require_run_id() so the two rejection branches (missing, invalid) are
 * unit-testable without going through WP_CLI::error()'s process exit. The
 * wrapper below owns the emit; this owns the decision and the message text.
 *
 * @param array $assoc_args The CLI assoc args (expects a 'run-id' key).
 * @return string|null  The user-facing error, or null to accept.
 */
function _pp_cli_run_id_error(array $assoc_args): ?string {
    if (empty($assoc_args['run-id'])) {
        return '--run-id is required. Run `wp pp operate inspect` first to get a run token.';
    }
    if (!pp_operate_valid_run_id($assoc_args['run-id'])) {
        return '--run-id must be a valid UUID v4. Got: "' . $assoc_args['run-id'] . '"';
    }
    return null;
}

/**
 * Validates and returns the --run-id from CLI args.
 * Halts with WP_CLI::error if missing or not a valid UUID v4.
 */
function _pp_cli_require_run_id(array $assoc_args): string {
    $error = _pp_cli_run_id_error($assoc_args);
    if ($error !== null) {
        WP_CLI::error($error);
    }
    return $assoc_args['run-id'];
}

/**
 * Preflight-before-mutation gate (#96). Halts with an actionable WP_CLI::error
 * unless the run has a completed PREFLIGHT covering the intended target: a
 * specific $post_id for page/section mutations, or the site grain when $post_id
 * is null. Shared by `action execute` and `operate patch`.
 */
/**
 * Pure decision for the preflight-coverage gate (#96/#390): returns the
 * fail-closed error message when the run has no completed PREFLIGHT covering the
 * intended target, or null when coverage exists. Split out of
 * _pp_cli_require_preflight_covers() so the uncovered branch and its actionable
 * message are unit-testable without WP_CLI::error()'s exit.
 *
 * @param string   $run_id  The run token UUID.
 * @param int|null $post_id The mutation target post, or null for site-scoped.
 * @return string|null  The user-facing error, or null to accept.
 */
function _pp_cli_preflight_coverage_error(string $run_id, ?int $post_id): ?string {
    if (pp_operate_preflight_covers($run_id, $post_id)) {
        return null;
    }
    $target = $post_id !== null ? 'post ' . $post_id : 'site-scoped changes';
    $hint   = 'wp pp apply preflight --run-id=' . $run_id
            . ($post_id !== null ? ' --post_id=' . $post_id : '');
    return 'Run token "' . $run_id . '" has no completed PREFLIGHT covering ' . $target
        . '. Mutating actions require a successful preflight first. Run `' . $hint . '`.';
}

function _pp_cli_require_preflight_covers(string $run_id, ?int $post_id): void {
    $error = _pp_cli_preflight_coverage_error($run_id, $post_id);
    if ($error !== null) {
        WP_CLI::error($error);
    }
}

/**
 * Applies the preflight gate for a named registered action. Resolves the target
 * post from $params (page/section actions carry a required post_id; site actions
 * carry none), asserts that the action's declared scope is consistent with that
 * presence so a misdeclared action can't be mis-gated, then enforces coverage.
 */
/**
 * Pure decision for the scope-consistency guardrail (#390) that resolves an
 * action's preflight target from its declared scope and the presence of a
 * post_id. Returns the fail-closed error message for the three misdeclaration
 * branches, or null when scope and post_id are mutually consistent:
 *   - unrecognized scope (not page/section/site);
 *   - a page/section action with no post_id;
 *   - a site action carrying a post_id.
 * Split out of _pp_cli_require_preflight_for_action() so those branches are
 * unit-testable without WP_CLI::error()'s exit. It does NOT enforce coverage or
 * the #358 composition precondition — those stay in the wrapper below.
 *
 * @param array    $action  The registered action (expects 'scope', 'name').
 * @param int|null $post_id The resolved target post, or null for site scope.
 * @return string|null  The user-facing error, or null to accept.
 */
function _pp_cli_preflight_target_error(array $action, ?int $post_id): ?string {
    $scope = $action['scope'] ?? 'unknown';

    // Fail closed on an unrecognized scope. The page/site assertions below only
    // hold for the known scopes; a missing or mistyped scope would otherwise fall
    // through to post_id-presence keying, letting a misdeclared page action be
    // unlocked by a site-grain preflight. Refuse rather than guess the target.
    if (!in_array($scope, ['page', 'section', 'site'], true)) {
        return 'Action "' . ($action['name'] ?? '?') . '" has an unrecognized scope "'
            . $scope . '"; refusing to resolve a preflight target. This is an action-registration bug.';
    }

    // Scope-consistency guardrail: page/section MUST carry post_id; site MUST NOT.
    $is_page_scope = in_array($scope, ['page', 'section'], true);
    if ($is_page_scope && $post_id === null) {
        return 'Action "' . ($action['name'] ?? '?') . '" is ' . $scope
            . '-scoped but no post_id was provided; cannot resolve a preflight target.';
    }
    if ($scope === 'site' && $post_id !== null) {
        return 'Action "' . ($action['name'] ?? '?') . '" is site-scoped but a post_id '
            . 'was provided; site actions are not page-targeted.';
    }

    return null;
}

function _pp_cli_require_preflight_for_action(string $run_id, array $action, array $params): void {
    $post_id = isset($params['post_id']) ? (int) $params['post_id'] : null;

    $target_error = _pp_cli_preflight_target_error($action, $post_id);
    if ($target_error !== null) {
        WP_CLI::error($target_error);
    }

    _pp_cli_require_preflight_covers($run_id, $post_id);

    // Composition-presence precondition (#358). Coverage proves a preflight ran for
    // this target, but preflight is action-agnostic — it accepts any existing page.
    // This is where the action IS known, so enforce the per-action requirement:
    // component-level actions (requires_composition defaults TRUE) are blocked on a
    // composition-less page, while populate/lifecycle/metadata actions
    // (requires_composition => false) pass. Fail-closed and declarative; see
    // pp_action_composition_precondition() in lib/operate.php.
    $precondition = pp_action_composition_precondition($action, $post_id);
    if (is_wp_error($precondition)) {
        WP_CLI::error($precondition->get_error_message());
    }
}

/**
 * Preflight-freshness gate (#113). For a composition-mutating action, halts with an
 * actionable WP_CLI::error unless the target composition is UNCHANGED since the freshness
 * marker recorded at preflight (or refreshed by this run's own last mutation). Ordering
 * (#96 coverage) proves a preflight ran for the target; this proves the target still
 * matches what that preflight validated — closing the TOCTOU gap where the composition
 * changed through another path between preflight and execute.
 *
 * No-op for actions that don't mutate the composition (title/slug/seo/publish) and for
 * site-scoped actions (no post_id). Fail-closed: a missing recorded baseline blocks.
 * Call AFTER the coverage gate so the two errors stay distinct.
 *
 * Returns the validated baseline version so `execute` can thread it into the action as
 * `expected_version` for an atomic write-time compare-and-swap (#13) — closing the TOCTOU
 * window between this pre-check and the actual write. Returns null for the no-op cases
 * (non-mutating / site-scoped), where no CAS baseline applies.
 *
 * @return int|null  The baseline version to use as expected_version, or null (no CAS).
 */
/**
 * Pure decision for the preflight-freshness gate (#113/#390). Reads the recorded
 * and live composition markers and resolves the gate to one of:
 *   ['status' => 'ok',    'version' => int|null]  — accept; version is the CAS
 *        baseline to thread as expected_version, or null for the no-op cases
 *        (non-composition-mutating action, or site-scoped/no post_id);
 *   ['status' => 'error', 'message' => string]    — fail-closed, with the exact
 *        user-facing message for the missing-baseline or stale-marker branch.
 * Split out of _pp_cli_require_composition_fresh() so both rejection branches are
 * unit-testable without WP_CLI::error()'s exit. The single snapshot read here is
 * also reused for the returned version, so the wrapper below never re-reads.
 *
 * @param string   $run_id  The run token UUID.
 * @param array    $action  The registered action (checks 'mutates_composition').
 * @param int|null $post_id The mutation target post, or null.
 * @return array  A discriminated result: {status:'ok', version:int|null} or {status:'error', message:string}.
 */
function _pp_cli_composition_fresh_decision(string $run_id, array $action, ?int $post_id): array {
    if (empty($action['mutates_composition']) || $post_id === null) {
        return ['status' => 'ok', 'version' => null];
    }

    $recorded = pp_operate_get_composition_snapshot($run_id, $post_id);
    if ($recorded === null) {
        return ['status' => 'error', 'message' => 'Run token "' . $run_id . '" recorded no composition freshness baseline for post '
            . $post_id . '. Re-run `wp pp apply preflight --run-id=' . $run_id . ' --post_id=' . $post_id . '`.'];
    }

    $live = pp_get_composition_marker($post_id);
    if (!pp_composition_marker_matches($recorded, $live)) {
        return ['status' => 'error', 'message' => 'Stale preflight for post ' . $post_id . ': the composition changed since preflight '
            . '(preflight version ' . (int) $recorded['version'] . ', live version ' . (int) $live['version'] . '). '
            . 'Another path (a CLI action, the dashboard editor, or publish flow) modified it. '
            . 'Re-inspect and re-run `wp pp apply preflight --run-id=' . $run_id . ' --post_id=' . $post_id
            . '` before executing. [composition_conflict]'];
    }

    return ['status' => 'ok', 'version' => (int) $recorded['version']];
}

function _pp_cli_require_composition_fresh(string $run_id, array $action, ?int $post_id): ?int {
    $decision = _pp_cli_composition_fresh_decision($run_id, $action, $post_id);
    if ($decision['status'] === 'error') {
        WP_CLI::error($decision['message']);
    }
    return $decision['version'];
}

/**
 * Refreshes a run's composition freshness baseline after a successful in-run mutation
 * (#113). Re-reads the just-written live marker and records it, so the run's OWN next
 * mutation on the same post passes the freshness gate while an external interleaved write
 * still conflicts. Best-effort: a failed refresh is fail-closed — the run's next mutation
 * would just require a fresh preflight — so it warns rather than halting a completed write.
 */
function _pp_cli_refresh_composition_baseline(string $run_id, array $action, ?int $post_id): void {
    if (empty($action['mutates_composition']) || $post_id === null) {
        return;
    }
    if (!pp_operate_record_composition_snapshot($run_id, $post_id, pp_get_composition_marker($post_id))) {
        WP_CLI::warning('Could not refresh the composition freshness baseline for post ' . $post_id
            . ' on run token "' . $run_id . '"; a further mutation on this post in the same run will '
            . 'require a new `wp pp apply preflight`.');
    }
}

/**
 * Parses the --params JSON argument. Shared by action and apply CLI commands.
 */
function pp_cli_parse_params(array $assoc_args): array {
    $raw = $assoc_args['params'] ?? '{}';
    $params = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        WP_CLI::error('Invalid JSON in --params: ' . json_last_error_msg());
    }
    return $params;
}

/**
 * Capability gate for apply commands.
 * In WP-CLI context, capability check is bypassed because WP-CLI already
 * requires server-level access. This follows WP-CLI core conventions
 * (e.g. wp db export). In web/AJAX context, requires manage_options.
 */
function _pp_cli_require_apply_cap(): void {
    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::debug('Capability gate bypassed: WP-CLI context detected.');
        return;
    }
    if (!current_user_can('manage_options')) {
        WP_CLI::error('You need manage_options capability to use apply commands.');
    }
}

/**
 * Action commands intentionally have no CLI capability gate.
 * Actions mutate WordPress data through WP APIs (wp_update_post, update_option)
 * which enforce their own permission model. Apply commands write directly to
 * the filesystem, bypassing WordPress permissions entirely — hence the gate.
 * AJAX surfaces for actions gate on edit_posts separately (see lib/ai-chat.php).
 */
class PP_Action_Command extends WP_CLI_Command {

    /**
     * Lists all registered actions.
     *
     * ## EXAMPLES
     *
     *     wp pp action list
     *
     * @subcommand list
     */
    public function list_actions($args, $assoc_args) {
        $actions = pp_get_registered_actions();
        if (empty($actions)) {
            WP_CLI::warning('No actions registered.');
            return;
        }

        $rows = [];
        foreach ($actions as $name => $def) {
            $params = [];
            foreach ($def['params'] as $pname => $pdef) {
                $label = $pname . ' (' . ($pdef['type'] ?? 'string') . ')';
                if (!empty($pdef['required'])) {
                    $label .= ' *';
                }
                $params[] = $label;
            }
            $rows[] = [
                'name'        => $name,
                'scope'       => $def['scope'],
                'description' => $def['description'] ?? '',
                'params'      => implode(', ', $params),
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['name', 'scope', 'description', 'params']);
    }

    /**
     * Previews an action (validates and shows diff, never writes).
     *
     * ## OPTIONS
     *
     * <name>
     * : The action name.
     *
     * --params=<json>
     * : JSON object of action parameters.
     *
     * ## EXAMPLES
     *
     *     wp pp action preview update_component --params='{"post_id":4,"component_index":0,"props":{"title":"New Title"}}'
     *
     */
    public function preview($args, $assoc_args) {
        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        $result = pp_preview_action($name, $params);

        if (is_wp_error($result)) {
            WP_CLI::line(json_encode(['ok' => false, 'error' => $result->get_error_message()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            WP_CLI::halt(1);
            return;
        }

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Executes an action (validates first, then applies).
     *
     * ## OPTIONS
     *
     * <name>
     * : The action name.
     *
     * --params=<json>
     * : JSON object of action parameters.
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * ## EXAMPLES
     *
     *     wp pp action execute create_page --run-id=<uuid> --params='{"title":"New Page"}'
     *     wp pp action execute add_component --run-id=<uuid> --params='{"post_id":4,"component":"hero","props":{"title":"Hello"}}'
     *
     */
    public function execute($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'INSPECT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed INSPECT step. Run `wp pp operate inspect` first.');
        }

        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        // Preflight-before-mutation gate (#96). Every `action execute` mutates
        // DB-backed state, so require a completed PREFLIGHT covering this target
        // before the write lands. Validate first so a malformed/nonexistent
        // target surfaces its real error instead of a confusing "preflight a
        // page that doesn't exist" message, and so the gate only demands a
        // preflight for an action that would actually run. Unknown action names
        // fall through to pp_execute_action's unknown_action error.
        $action = pp_get_action($name);
        if ($action !== null) {
            $validation = pp_validate_action($name, $params);
            if (is_wp_error($validation)) {
                WP_CLI::error($validation->get_error_message());
            }
            _pp_cli_require_preflight_for_action($run_id, $action, $params);
            // Freshness gate (#113): after coverage, reject a composition-mutating action
            // whose target changed since preflight. Its return is the validated baseline
            // version, which we thread into the action as expected_version so the write is
            // an atomic compare-and-swap (#13) — a live write landing between this gate and
            // pp_update_composition() is caught at write time, not silently clobbered.
            $baseline_version = _pp_cli_require_composition_fresh(
                $run_id, $action, isset($params['post_id']) ? (int) $params['post_id'] : null
            );
            if ($baseline_version !== null) {
                $params['expected_version'] = $baseline_version;
            }
        }

        $result = pp_execute_action($name, $params);

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($result['ok']) {
            // Refresh the freshness baseline (#113) so this run's own next mutation on the
            // same post flows; an external interleaved write still conflicts.
            if ($action !== null) {
                _pp_cli_refresh_composition_baseline($run_id, $action, isset($params['post_id']) ? (int) $params['post_id'] : null);
                // Touched-post tracking (#133): record this post so a run-scoped restore
                // can revert exactly the compositions this run changed. Only for
                // composition-mutating actions targeting a page. Fail loud on a recording
                // failure — a missing touched-post entry silently narrows what restore
                // can undo, the composition analogue of the touched-token contract.
                if (!empty($action['mutates_composition']) && isset($params['post_id'])) {
                    if (!pp_operate_record_touched_post_id($run_id, (int) $params['post_id'])) {
                        WP_CLI::error('Action "' . $name . '" executed, but recording its touched post for run "' . $run_id . '" FAILED. `wp pp apply restore-composition` may not be able to revert this change. Run state may be missing or corrupt; re-run `wp pp operate inspect` before making further changes.');
                    }
                }
            }
            WP_CLI::success('Action "' . $name . '" executed.');
        } else {
            WP_CLI::halt(1);
        }
    }

}

WP_CLI::add_command('pp action', 'PP_Action_Command');

// ── Target CLI ──────────────────────────────────────────────────────────────

class PP_Target_Command extends WP_CLI_Command {

    /**
     * Shows the canonical live target (site URL, WP root, theme path, environment).
     *
     * ## EXAMPLES
     *
     *     wp pp target show
     *
     */
    public function show($args, $assoc_args) {
        $target = pp_get_target();

        $warnings = [];
        foreach ($target as $key => $value) {
            if ($value === null) {
                $warnings[] = $key;
            }
        }

        WP_CLI::line(json_encode($target, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!empty($warnings)) {
            WP_CLI::warning('Could not resolve: ' . implode(', ', $warnings) . '. Verify WordPress is fully loaded.');
        }
    }
}

WP_CLI::add_command('pp target', 'PP_Target_Command');

// ── Apply CLI ───────────────────────────────────────────────────────────────

class PP_Apply_Command extends WP_CLI_Command {

    /**
     * Lists all registered applies.
     *
     * ## EXAMPLES
     *
     *     wp pp apply list
     *
     * @subcommand list
     */
    public function list_applies($args, $assoc_args) {
        $applies = pp_get_registered_applies();
        if (empty($applies)) {
            WP_CLI::warning('No applies registered.');
            return;
        }

        $rows = [];
        foreach ($applies as $name => $def) {
            $params = [];
            foreach ($def['params'] as $pname => $pdef) {
                $label = $pname . ' (' . ($pdef['type'] ?? 'string') . ')';
                if (!empty($pdef['required'])) {
                    $label .= ' *';
                }
                $params[] = $label;
            }
            $target_label = '';
            if (isset($def['target']['type'])) {
                if ($def['target']['type'] === 'file') {
                    $target_label = 'file:' . ($def['target']['path'] ?? '');
                } elseif ($def['target']['type'] === 'option') {
                    $target_label = 'option:' . ($def['target']['key'] ?? '');
                } elseif ($def['target']['type'] === 'media') {
                    $target_label = 'media library';
                }
            }
            $rows[] = [
                'name'        => $name,
                'domain'      => $def['domain'],
                'target'      => $target_label,
                'description' => $def['description'] ?? '',
                'params'      => implode(', ', $params),
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['name', 'domain', 'target', 'description', 'params']);
    }

    /**
     * Previews an apply (validates and shows diff, never writes).
     *
     * ## OPTIONS
     *
     * <name>
     * : The apply name.
     *
     * --params=<json>
     * : JSON object of apply parameters.
     *
     * ## EXAMPLES
     *
     *     wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'
     *
     */
    public function preview($args, $assoc_args) {
        _pp_cli_require_apply_cap();

        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        $result = pp_preview_apply($name, $params);

        if (is_wp_error($result)) {
            WP_CLI::line(json_encode(['ok' => false, 'error' => $result->get_error_message()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            WP_CLI::halt(1);
            return;
        }

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Executes an apply (validates first, then applies).
     *
     * ## OPTIONS
     *
     * <name>
     * : The apply name.
     *
     * --params=<json>
     * : JSON object of apply parameters.
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * ## EXAMPLES
     *
     *     wp pp apply execute update_design_token --run-id=<uuid> --params='{"token":"--color-accent","value":"#b45309"}'
     *
     */
    public function execute($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'PREFLIGHT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed PREFLIGHT step. Run `wp pp apply preflight --run-id=' . $run_id . '` first.');
        }

        // Rollback-safety pre-gate: refuse to mutate unless this run is reversible
        // (a usable pre-apply snapshot exists for THIS install). Reversibility metadata
        // is a precondition of changing tokens, never an afterthought.
        if (!pp_operate_run_rollbackable($run_id)) {
            WP_CLI::error('Refusing to apply: run "' . $run_id . '" has no usable rollback snapshot, so this change could not be undone. Re-run `wp pp operate inspect` and `wp pp apply preflight`.');
        }

        _pp_cli_require_apply_cap();

        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        $result = pp_execute_apply($name, $params);

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (!$result['ok']) {
            WP_CLI::halt(1);
        }

        // Record the tokens this apply actually wrote (primary + derived) so restore
        // reverts exactly this run's footprint. The mutation already persisted; if the
        // touched-key trail cannot be recorded, surface a loud error instead of a clean
        // success. Restore reads touched_tokens and fails-closed on null, so a missing
        // trail can never become a silent partial rollback later.
        $touched = array_column($result['changes'], 'token');
        if (!pp_operate_record_touched_tokens($run_id, $touched)) {
            WP_CLI::error('Apply "' . $name . '" persisted, but recording its touched tokens for run "' . $run_id . '" FAILED. `wp pp apply restore` may not be able to revert this change. Run state may be missing or corrupt; re-run `wp pp operate inspect` before making further changes.');
        }

        pp_operate_record_step($run_id, 'APPLY');
        WP_CLI::success('Apply "' . $name . '" executed.');
    }

    /**
     * Rolls a run's token changes back to the snapshot taken at its preflight.
     *
     * This is a true per-run rollback, NOT a reset to product defaults. The tokens this
     * run wrote (primary + auto-derived) are reverted to the values they held when the
     * run's preflight ran; tokens the run created are removed; tokens the run never
     * touched are left untouched, so unrelated overrides (including later runs' work)
     * are preserved. To reset tokens to product defaults instead, use `wp pp apply reset`.
     *
     * Fails closed: if the run's frozen snapshot or touched-key list is missing, expired,
     * corrupt, swept, or from a different install, restore reports an error and changes
     * nothing — it never falls back to a product-default reset and never partially mutates.
     *
     * Limitation: a run is fully reversible only if every `apply execute` recorded its
     * touched keys. Token mutation (DB) and touched-key recording (run-state file) are
     * separate stores and cannot be one transaction; if a touched-key write ever fails,
     * `execute` errors loudly at that point, but a later restore replays whatever touched
     * keys WERE recorded and cannot revert a change whose keys never landed. Re-run
     * `wp pp operate inspect` after any such error rather than trusting restore.
     *
     * `wp pp apply reset` records its touched tokens the same way `execute` does, so a
     * reset (single-token or reset-all) within the same run is restorable here too — not
     * just a one-way trip to product defaults.
     *
     * ## OPTIONS
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * [--token=<name>]
     * : Restore a single token and its derived family from the run snapshot. Omit to
     *   restore everything the run touched.
     *
     * ## EXAMPLES
     *
     *     wp pp apply restore --run-id=<uuid> --token=--color-accent
     *     wp pp apply restore --run-id=<uuid>
     *
     */
    public function restore($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'PREFLIGHT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed PREFLIGHT step. Run `wp pp apply preflight --run-id=' . $run_id . '` first.');
        }

        _pp_cli_require_apply_cap();

        // Fail closed: both the frozen snapshot and the touched-key list must be usable
        // for THIS install. Null from either covers missing/expired/corrupt/swept/identity
        // mismatch. Never fall back to a product-default reset.
        $snapshot = pp_operate_get_token_snapshot($run_id);
        $touched  = pp_operate_get_touched_tokens($run_id);
        if ($snapshot === null || $touched === null) {
            WP_CLI::error('Run "' . $run_id . '" has no usable pre-apply snapshot; cannot roll back. The run state may be missing, expired, corrupt, or from a different site. Nothing was changed.');
        }

        // Scope the revert. Default: everything the run touched. With --token: that token
        // plus its derived family, intersected with what the run actually touched.
        if (isset($assoc_args['token'])) {
            $token  = $assoc_args['token'];
            $family = array_keys(pp_token_families()[$token] ?? []);
            $wanted = array_merge([$token], $family);
            $scope  = array_values(array_intersect($touched, $wanted));
            if (empty($scope)) {
                WP_CLI::success('Token "' . $token . '" was not changed by run "' . $run_id . '"; nothing to restore.');
                return;
            }
        } else {
            $scope = $touched;
        }

        if (empty($scope)) {
            pp_operate_record_step($run_id, 'APPLY');
            WP_CLI::success('Run "' . $run_id . '" changed no tokens; nothing to restore.');
            return;
        }

        // Compute the effective change for reporting, then revert atomically.
        $before = pp_get_token_overrides();
        if (!pp_revert_tokens($snapshot, $scope)) {
            WP_CLI::error('Could not roll back run "' . $run_id . '": the token lock was unavailable or the snapshot held invalid values. Nothing was changed.');
        }
        $after = pp_get_token_overrides();

        $changed = 0;
        foreach ($scope as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed++;
            }
        }

        pp_operate_record_step($run_id, 'APPLY');
        WP_CLI::success($changed > 0
            ? "Restored $changed token(s) to the pre-run snapshot."
            : 'Tokens already matched the pre-run snapshot; nothing to restore.');
    }

    /**
     * Reverts every page composition a run changed back to its pre-run state (#133).
     *
     * The composition counterpart of `wp pp apply restore` (which reverts tokens). For
     * each post the run mutated (recorded as it ran), rewrites the composition to the
     * content frozen at the run's PREFLIGHT. Scoped strictly to THIS run's touched posts
     * — a page changed by a different run is never touched. Each revert is a real
     * pp_update_composition write (its own lock + marker bump + history entry), so the
     * revert is itself reversible.
     *
     * Fails closed: if the run's touched-post record is missing, expired, corrupt, or
     * from another install, nothing is changed. Per-post snapshot-missing or write
     * failures are reported under `skipped` while the rest proceed.
     *
     * ## OPTIONS
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * ## EXAMPLES
     *
     *     wp pp apply restore-composition --run-id=<uuid>
     *
     * @subcommand restore-composition
     */
    public function restore_composition($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'PREFLIGHT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed PREFLIGHT step. Run `wp pp apply preflight --run-id=' . $run_id . '` first.');
        }

        _pp_cli_require_apply_cap();

        $report = pp_operate_restore_run_compositions($run_id);
        if (!$report['ok']) {
            WP_CLI::error('Run "' . $run_id . '" has no usable touched-post record; cannot revert compositions. The run state may be missing, expired, corrupt, or from a different site. Nothing was changed.');
        }

        WP_CLI::line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $skipped  = count($report['skipped']);
        if ($skipped > 0) {
            WP_CLI::warning($skipped . ' post(s) skipped (missing snapshot or write failure); see the report above.');
        }

        // issue 236: a run-scoped restore is never blocked by validation rules that
        // landed after the snapshot, so it must not report a bare success when a
        // restored composition violates current rules. Warn (never fail) so the operator
        // sees the gap; the per-post `findings` in the JSON report above name the detail.
        // Emitted before the completeness gate below (which can exit) so it always shows,
        // and to STDERR (WP_CLI::warning) so the STDOUT JSON stays machine-clean.
        $with_findings = pp_operate_restore_run_finding_count($report);
        if ($with_findings > 0) {
            WP_CLI::warning($with_findings . ' reverted post(s) have composition findings under current validation rules (see "findings" in the report above). The rollback was applied as-is; a restore is never blocked by rules that landed after the snapshot.');
        }

        $reverted = count($report['reverted']);
        $changed  = count(array_filter($report['reverted'], static function ($r) { return !empty($r['changed']); }));

        pp_operate_record_step($run_id, 'APPLY');

        // issue 242: a partial restore (any skipped touched post) is INCOMPLETE, not
        // successful. Fail closed with a non-zero exit so a machine consumer branching
        // on the exit code never reads a partial restore as a full one; the JSON report
        // above already lists reverted vs skipped explicitly.
        if (!pp_operate_restore_run_complete($report)) {
            WP_CLI::error("Restore INCOMPLETE: reverted $reverted of " . ($reverted + $skipped)
                . " touched post(s); $skipped could not be reverted (missing snapshot or write failure). See the report above for which posts were restored vs skipped.");
        }

        WP_CLI::success($changed > 0
            ? "Reverted $changed composition(s) to the pre-run state (of $reverted touched)."
            : 'Touched compositions already matched the pre-run state; nothing to revert.');
    }

    /**
     * Resets design tokens to product defaults (NOT a per-run rollback).
     *
     * Clears token overrides so the site reverts to the values shipped in base.css.
     * Use `wp pp apply restore` to undo a specific run instead. Token overrides are
     * stored in the database; this calls the reset_design_token / reset_all_design_tokens
     * applies.
     *
     * ## OPTIONS
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * [--token=<name>]
     * : Reset a single token. Omit to reset all.
     *
     * ## EXAMPLES
     *
     *     wp pp apply reset --run-id=<uuid> --token=--color-accent
     *     wp pp apply reset --run-id=<uuid>
     *
     */
    public function reset($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);
        if (!pp_operate_check_step($run_id, 'PREFLIGHT')) {
            WP_CLI::error('Run token "' . $run_id . '" has no completed PREFLIGHT step. Run `wp pp apply preflight --run-id=' . $run_id . '` first.');
        }

        // Rollback-safety pre-gate: reset mutates the same token store execute()
        // does (and reset_all_design_tokens is the most destructive apply in the
        // registry), so it gets the identical precondition — refuse to mutate
        // unless this run is reversible.
        if (!pp_operate_run_rollbackable($run_id)) {
            WP_CLI::error('Refusing to reset: run "' . $run_id . '" has no usable rollback snapshot, so this change could not be undone. Re-run `wp pp operate inspect` and `wp pp apply preflight`.');
        }

        _pp_cli_require_apply_cap();

        if (isset($assoc_args['token'])) {
            $result = pp_execute_apply('reset_design_token', ['token' => $assoc_args['token']]);
        } else {
            $result = pp_execute_apply('reset_all_design_tokens', []);
        }

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (!$result['ok']) {
            WP_CLI::halt(1);
        }

        // Record the tokens this reset actually cleared so restore can revert
        // exactly this run's footprint — same contract as execute(). Without
        // this, restore's scope stays empty and a reset is unrecoverable
        // through the tooling even though the pre-apply snapshot holds every
        // prior value.
        $touched = array_column($result['changes'], 'token');
        if (!pp_operate_record_touched_tokens($run_id, $touched)) {
            WP_CLI::error('Reset persisted, but recording its touched tokens for run "' . $run_id . '" FAILED. `wp pp apply restore` may not be able to revert this change. Run state may be missing or corrupt; re-run `wp pp operate inspect` before making further changes.');
        }

        pp_operate_record_step($run_id, 'APPLY');
        $count = count($result['changes']);
        WP_CLI::success($count > 0 ? "Reset $count token(s) to product defaults." : 'No overrides to reset.');
    }

    /**
     * Validates the execution surface before any mutation.
     *
     * Checks: target resolved, capability OK, drift state, theme writability
     * (file-targeting applies), uploads writability (media-target applies),
     * target page (if applicable), and surface classification.
     * Records PREFLIGHT step in the run state file.
     *
     * ## OPTIONS
     *
     * --run-id=<uuid>
     * : Run token from `wp pp operate inspect`. Required.
     *
     * [--planned-files=<json>]
     * : JSON array of file paths the agent intends to modify.
     *   Enables drift overlap detection. Without this flag, drift is a warning only.
     *
     * [--post_id=<id>]
     * : Target page post ID. Enables target_page check.
     *
     * [--apply=<name>]
     * : Named apply definition. Auto-populates planned_files from the apply's target (file-based applies only).
     *   Media-target applies (import_media) enable the uploads_writable check.
     *
     * ## EXAMPLES
     *
     *     wp pp apply preflight --run-id=<uuid>
     *     wp pp apply preflight --run-id=<uuid> --planned-files='["assets/css/base.css"]'
     *     wp pp apply preflight --run-id=<uuid> --apply=update_design_token
     *     wp pp apply preflight --run-id=<uuid> --post_id=42
     *
     */
    public function preflight($args, $assoc_args) {
        $run_id = _pp_cli_require_run_id($assoc_args);

        $context = [];

        if (!empty($assoc_args['planned-files'])) {
            $decoded = json_decode($assoc_args['planned-files'], true);
            if (is_array($decoded)) {
                $context['planned_files'] = $decoded;
            }
        }

        if (isset($assoc_args['post_id'])) {
            $context['post_id'] = (int) $assoc_args['post_id'];
        }

        // Route any provided --apply value (non-empty string) into the context so an
        // unregistered name fails the apply_known preflight check (issue 245). Guard
        // on `!== ''` rather than !empty(): PHP's empty('0') is true, so an !empty()
        // gate would drop the literal apply name "0" here and let pp_preflight() treat
        // it as "no apply planned" — the exact false-pass the apply_known check closes.
        if (isset($assoc_args['apply']) && $assoc_args['apply'] !== '') {
            $context['apply_name'] = $assoc_args['apply'];
        }

        $result = pp_preflight($context);

        if (!$result['ok']) {
            // Error-grade check failed: report the checks and stop. Nothing is
            // recorded, so downstream gates stay closed.
            WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            WP_CLI::halt(1);
        }

        // The success JSON is emitted LAST, only after every recording step below
        // has succeeded (#227). Emitting it before recording made the gate fail-open
        // in its reported result: a consumer parsing stdout — the machine-readable
        // contract — saw {"ok": true} for a preflight whose state was never
        // recorded, then hit a contradictory error on the next command. Any
        // recording failure now reports {"ok": false, "error": ...} on stdout
        // (via _pp_cli_preflight_record_failed) so `ok` reflects whether the
        // preflight actually completed, including recording its state.

        // Record PREFLIGHT only on success, in ONE atomic write: the PREFLIGHT
        // step, the target this preflight covered (post_id for page/section work,
        // or the site grain when no post is given), the pre-apply token snapshot
        // that `apply restore` rolls back to, AND — for a page/section preflight —
        // the pre-apply composition content snapshot that `apply restore-composition`
        // reverts to. Committing them together is load-bearing: mutating gates
        // (action execute / operate patch) unlock on the recorded coverage alone,
        // so a partial write must never leave the run unlocked. Folding the
        // composition snapshot into this single write (issue 241) closes the prior
        // two-write gap where a snapshot-write failure left the run unlocked with no
        // restore baseline (and a re-run could freeze a post-mutation baseline).
        // First-write-wins inside the recorder keeps both rollback baselines stable
        // across re-runs.
        //
        // The snapshot is read under the token lock for an atomic baseline. It returns
        // null on any of three fail-closed conditions rather than a baseline that a
        // later `apply restore` would wrongly roll back to:
        //   - lock contended (#200): a stale, non-atomic read,
        //   - unreadable overrides row (#207): a corrupt/hand-edited pp_token_overrides
        //     row that would otherwise be recorded as an empty [] baseline, causing
        //     restore to DELETE the touched tokens instead of restoring them, or
        //   - database read failure (#212): a failed SELECT (non-empty $wpdb->last_error)
        //     that would otherwise be indistinguishable from a genuinely absent row.
        // Either way a null snapshot is a hard preflight failure: record nothing
        // (leaving both gates fail-closed) and surface the cause so the operator can act
        // (retry once contention clears; repair the corrupt row before re-running).
        $token_snapshot = pp_snapshot_token_overrides();
        if ($token_snapshot === null) {
            _pp_cli_preflight_record_failed($result, 'Could not read an atomic pre-apply token baseline for run token "' . $run_id . '": the token lock is contended, or the pp_token_overrides row is corrupt/unreadable. PREFLIGHT was not recorded. Re-run `wp pp apply preflight` once the contention clears; if it persists, inspect and repair the pp_token_overrides option.');
        }
        // Freshness baseline (#113) + run-scoped restore baseline (#133): for a
        // page-scoped preflight, capture the target's composition marker (so a later
        // `action execute` / `operate patch` can reject a composition changed since
        // this preflight) and its pre-apply composition CONTENT (so a run-scoped
        // restore can revert this post to its pre-run state). Both are null for a
        // site-grain preflight.
        $composition_marker  = null;
        $composition_content = null;
        if (isset($context['post_id'])) {
            $pid = (int) $context['post_id'];
            $composition_marker = pp_get_composition_marker($pid);
            // Read the restore baseline via the result-returning decoder so a corrupt
            // or undecodable _pp_composition row FAILS the preflight closed (issue 241)
            // instead of freezing [] as the baseline — which a later run-scoped restore
            // would replay to BLANK the page. Mirrors the token snapshot's fail-closed
            // treatment of a corrupt pp_token_overrides row (#207). pp_get_composition()
            // coerces corruption to [] and must not be used here.
            $composition_result = pp_get_composition_result($pid);
            if (!$composition_result['ok']) {
                _pp_cli_preflight_record_failed($result, 'Could not read a valid pre-apply composition baseline for run token "' . $run_id . '" (post ' . $pid . '): the stored composition is ' . $composition_result['error'] . '. PREFLIGHT was not recorded, so both the action gate and the restore baseline stay fail-closed. Repair the post\'s composition before re-running `wp pp apply preflight`.');
            }
            $composition_content = $composition_result['composition'];
        }
        // Single atomic write: PREFLIGHT step + coverage + token snapshot + (for a
        // page-scoped preflight) the composition marker and content baseline. Recording
        // them together means the run can never be left unlocked without its restore
        // baseline; any recording failure records nothing and reports {"ok": false}.
        if (!pp_operate_record_preflight($run_id, $context['post_id'] ?? null, $token_snapshot, $composition_marker, $composition_content)) {
            _pp_cli_preflight_record_failed($result, 'Could not record PREFLIGHT state for run token "' . $run_id . '". State file may be missing or expired. Re-run `wp pp operate inspect`.');
        }

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

WP_CLI::add_command('pp apply', 'PP_Apply_Command');

// ── Check / Validate CLI ──────────────────────────────────────────────────

class PP_Check_Command extends WP_CLI_Command {

    /**
     * Reports Custom CSS selectors that conflict with PP component classes.
     *
     * ## EXAMPLES
     *
     *     wp pp check conflicts
     *
     */
    public function conflicts($args, $assoc_args) {
        $conflicts = pp_check_custom_css_conflicts();

        if (empty($conflicts)) {
            WP_CLI::success('No Custom CSS conflicts detected.');
            return;
        }

        WP_CLI::warning(count($conflicts) . ' conflict(s) found:');
        WP_CLI\Utils\format_items('table', $conflicts, ['selector', 'component']);
    }

    /**
     * Validates composition styling for a specific page.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : WordPress page post ID.
     *
     * ## EXAMPLES
     *
     *     wp pp check page --post_id=42
     *
     */
    public function page($args, $assoc_args) {
        $post_id = (int) ($assoc_args['post_id'] ?? 0);
        if (!$post_id) {
            WP_CLI::error('--post_id is required.');
        }

        $result = pp_get_composition_result($post_id);
        if (!$result['ok']) {
            // Corrupt/undecodable _pp_composition — distinct from a blank page
            // so a data-integrity problem isn't reported as "no composition"
            // (issue #144).
            WP_CLI::warning("Page {$post_id}: composition data integrity error ({$result['error']}). The stored _pp_composition is not a valid composition list — treat as corrupted, not empty.");
            return;
        }
        $composition = $result['composition'];
        if (empty($composition)) {
            WP_CLI::warning('No composition found for page ' . $post_id . '.');
            return;
        }

        $warnings  = pp_validate_composition_styling($composition);
        $smells    = pp_validate_composition_smells($composition);
        $generated = pp_find_generated_component_ids($composition);

        if (empty($warnings) && empty($smells) && empty($generated)) {
            WP_CLI::success('Page ' . $post_id . ': all components have explicit stable IDs, no ambiguous targeting, no composition smells.');
            return;
        }

        if (!empty($generated)) {
            WP_CLI::warning(count($generated) . ' component(s) without a durable component_id:');
            foreach ($generated as $g) {
                $shown = $g['id'] !== '' ? $g['id'] : '(none)';
                WP_CLI::line("  - {$g['component']} at index {$g['index']} (id: {$shown}): auto-generated ids are regenerated by a full update_composition re-apply. Add an explicit `id` prop to target this component durably.");
            }
        }

        if (!empty($warnings)) {
            WP_CLI::warning(count($warnings) . ' ambiguous targeting warning(s):');
            $rows = [];
            foreach ($warnings as $w) {
                $rows[] = [
                    'component' => $w['component'],
                    'indices'   => implode(', ', $w['indices']),
                    'issue'     => 'Duplicate component type without authored IDs (ambiguous targeting)',
                ];
            }
            WP_CLI\Utils\format_items('table', $rows, ['component', 'indices', 'issue']);
        }

        if (!empty($smells)) {
            WP_CLI::warning(count($smells) . ' composition smell(s):');
            foreach ($smells as $s) {
                WP_CLI::line('  - [' . $s['type'] . '] index ' . $s['index'] . ': ' . $s['message']);
            }
        }
    }

    /**
     * Classifies a file path as safe, extension, or core.
     *
     * Reports the surface classification and routing guidance for a given
     * file path. Helps agents understand which files they can edit directly
     * vs. which require approved database-backed surfaces.
     *
     * ## OPTIONS
     *
     * <path>
     * : File path to classify (relative to theme root, or absolute).
     *
     * ## EXAMPLES
     *
     *     wp pp check surface lib/wp.php
     *     wp pp check surface components/hero/hero.php
     *     wp pp check surface assets/css/base.css
     *
     */
    public function surface($args, $assoc_args) {
        $path = $args[0] ?? '';
        if ($path === '') {
            WP_CLI::error('Path argument is required.');
        }

        $result = pp_classify_surface($path);

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($result['classification'] === 'core') {
            WP_CLI::warning('Core file — do not edit directly.');
        } elseif ($result['classification'] === 'extension') {
            WP_CLI::warning('Extension file — prefer database-backed surfaces when possible.');
        } else {
            WP_CLI::success('Safe surface.');
        }
    }
}

WP_CLI::add_command('pp check', 'PP_Check_Command');

class PP_Validate_Command extends WP_CLI_Command {

    /**
     * Runs full site validation battery.
     *
     * Checks: Custom CSS conflicts, composition styling for all pages,
     * components without IDs.
     *
     * ## EXAMPLES
     *
     *     wp pp validate site
     *
     */
    public function site($args, $assoc_args) {
        $pass = true;

        // 1. Custom CSS conflicts
        WP_CLI::line('--- Custom CSS conflicts ---');
        $conflicts = pp_check_custom_css_conflicts();
        if (!empty($conflicts)) {
            $pass = false;
            WP_CLI::warning(count($conflicts) . ' conflict(s):');
            WP_CLI\Utils\format_items('table', $conflicts, ['selector', 'component']);
        } else {
            WP_CLI::line('OK: No Custom CSS conflicts.');
        }

        // 2. Composition styling per page
        WP_CLI::line('');
        WP_CLI::line('--- Composition styling ---');
        $pages = pp_composition_pages();
        if (empty($pages)) {
            WP_CLI::line('No composition pages found.');
        } else {
            foreach ($pages as $page) {
                $post_id     = $page['id'];
                $title       = $page['title'] ?? '(untitled)';
                $result      = pp_get_composition_result($post_id);
                if (!$result['ok']) {
                    // Corrupt row must fail validation, not report clean (issue
                    // #144). This is the command CI runs, so a silent-clean here
                    // would hide data corruption from the pipeline.
                    $pass = false;
                    WP_CLI::warning("Page {$post_id} ({$title}): composition data integrity error ({$result['error']}) — stored _pp_composition is not a valid composition list.");
                    continue;
                }
                $composition = $result['composition'];
                $warnings    = pp_validate_composition_styling($composition);
                $smells      = pp_validate_composition_smells($composition);

                if (!empty($warnings) || !empty($smells)) {
                    $pass = false;
                    $issue_count = count($warnings) + count($smells);
                    WP_CLI::warning("Page {$post_id} ({$title}): {$issue_count} issue(s)");
                    foreach ($warnings as $w) {
                        WP_CLI::line("  - {$w['component']} at indices " . implode(', ', $w['indices']) . ' (no authored IDs — ambiguous targeting; add explicit `id` props)');
                    }
                    foreach ($smells as $s) {
                        WP_CLI::line("  - [{$s['type']}] index {$s['index']}: {$s['message']}");
                    }
                } else {
                    WP_CLI::line("OK: Page {$post_id} ({$title})");
                }
            }
        }

        // 3. Summary
        WP_CLI::line('');
        if ($pass) {
            WP_CLI::success('Site validation passed.');
        } else {
            WP_CLI::warning('Site validation found issues. See above.');
            WP_CLI::halt(1);
        }
    }

    /**
     * Runs the rendered-HTML post-apply validation used to gate the AI
     * chat's success message, outside the chat flow (issue 77).
     *
     * Re-renders the page's composition and inspects the HTML: render
     * failures, empty rendered output, broken/empty image sources, missing
     * local media references, invalid inline background-image URLs, empty
     * links, and component-count mismatches. Distinct from `wp pp check
     * page`, which validates composition styling/smells against the raw
     * composition data, not the rendered HTML.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : WordPress page post ID.
     *
     * [--component-index=<index>]
     * : Validate only this component (0-based index) instead of the whole page.
     *
     * ## EXAMPLES
     *
     *     wp pp validate page --post_id=42
     *     wp pp validate page --post_id=42 --component-index=2
     *
     */
    public function page($args, $assoc_args) {
        $post_id = (int) ($assoc_args['post_id'] ?? 0);
        if (!$post_id) {
            WP_CLI::error('--post_id is required.');
        }

        $target = null;
        if (isset($assoc_args['component-index'])) {
            $target = ['component_index' => (int) $assoc_args['component-index']];
        }

        $result = pp_post_apply_validate($post_id, $target);

        if (!empty($result['warnings'])) {
            WP_CLI::line(count($result['warnings']) . ' warning(s):');
            foreach ($result['warnings'] as $w) {
                WP_CLI::line('  - [' . $w['check'] . '] ' . $w['message']);
            }
        }

        if ($result['ok']) {
            WP_CLI::success("Page {$post_id}: rendered validation passed.");
            return;
        }

        WP_CLI::line(count($result['errors']) . ' error(s):');
        foreach ($result['errors'] as $e) {
            WP_CLI::line('  - [' . $e['check'] . '] ' . $e['message']);
        }
        WP_CLI::warning("Page {$post_id}: rendered validation failed.");
        WP_CLI::halt(1);
    }
}

WP_CLI::add_command('pp validate', 'PP_Validate_Command');

// ── Operate CLI ─────────────────────────────────────────────────────────────

class PP_Operate_Command extends WP_CLI_Command {

    /**
     * Returns the full site operating picture as JSON.
     *
     * Used by agents at the INSPECT step of the operating loop.
     * Always generates a run token. Pass the returned run_id to all
     * subsequent mutating CLI commands via --run-id.
     *
     * ## OPTIONS
     *
     * [--post_id=<id>]
     * : Include page-specific composition smells for this post.
     *
     * ## EXAMPLES
     *
     *     wp pp operate inspect
     *     wp pp operate inspect --post_id=42
     *
     */
    public function inspect($args, $assoc_args) {
        $post_id = isset($assoc_args['post_id']) ? (int) $assoc_args['post_id'] : null;
        $result = pp_inspect_site($post_id);

        $run_id = pp_operate_create_run();
        if (is_wp_error($run_id)) {
            WP_CLI::error('Cannot create run token: ' . $run_id->get_error_message());
        }

        $result['run_id'] = $run_id;
        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Outputs the structured checklist for a playbook.
     *
     * ## OPTIONS
     *
     * --playbook=<name>
     * : Playbook name: create-page, revise-section, or inspect-fix.
     *
     * ## EXAMPLES
     *
     *     wp pp operate checklist --playbook=create-page
     *
     */
    public function checklist($args, $assoc_args) {
        $playbook = $assoc_args['playbook'] ?? '';
        $checklists = pp_operate_checklists();

        if (!isset($checklists[$playbook])) {
            $available = implode(', ', array_keys($checklists));
            WP_CLI::error("Unknown playbook '{$playbook}'. Available: {$available}");
        }

        WP_CLI::line(json_encode($checklists[$playbook], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Validates a loop run manifest against the operating contract.
     *
     * ## OPTIONS
     *
     * --run=<json>
     * : JSON string of the loop run manifest.
     *
     * ## EXAMPLES
     *
     *     wp pp operate validate --run='{"INSPECT":{"site_state":{}},...}'
     *
     */
    public function validate($args, $assoc_args) {
        $raw = $assoc_args['run'] ?? '{}';
        $run = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            WP_CLI::error('Invalid JSON in --run: ' . json_last_error_msg());
        }

        $result = pp_validate_loop_run($run);
        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$result['valid']) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Returns editable composition targets for a page as JSON.
     *
     * Walks each component, looks up the field editability map, and builds
     * semantic selector strings with current values. Used by agents to
     * discover what can be patched on a page.
     *
     * ## OPTIONS
     *
     * <page>
     * : Post ID or slug of the page to inspect.
     *
     * ## EXAMPLES
     *
     *     wp pp operate inspect-composition 19
     *     wp pp operate inspect-composition about-us
     *
     * @subcommand inspect-composition
     */
    public function inspect_composition($args, $assoc_args) {
        $page = $args[0] ?? null;
        if (!$page) {
            WP_CLI::error('Page argument is required.');
        }

        $post_id = is_numeric($page) ? (int) $page : url_to_postid(home_url($page));
        if (!$post_id) {
            WP_CLI::error(sprintf('Could not resolve page "%s".', $page));
        }

        $result = pp_inspect_composition($post_id);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Patches a composition field by semantic selector.
     *
     * Parses the selector, resolves the target component and field, then
     * either previews the diff or applies the change through the
     * update_component action path.
     *
     * ## OPTIONS
     *
     * <page>
     * : Post ID or slug of the page to patch.
     *
     * --target=<selector>
     * : Semantic selector (e.g. hero.subtitle, section[title="About"].body).
     *
     * --value=<value>
     * : The new value for the targeted field.
     *
     * [--preview]
     * : Show the diff without writing. Read-only — needs no run token.
     *
     * [--run-id=<uuid>]
     * : Run token from `wp pp operate inspect`. Required for the mutating path (everything except --preview), which must sit behind a completed PREFLIGHT covering this page.
     *
     * ## EXAMPLES
     *
     *     wp pp operate patch 19 --target=hero.subtitle --value="New Subtitle" --preview
     *     wp pp operate patch 19 --target=hero.subtitle --value="New Subtitle" --run-id=<uuid>
     *
     */
    public function patch($args, $assoc_args) {
        // Docblock constraint: each OPTIONS description must stay on ONE
        // ": " line. WP-CLI folds continuation ": " lines into the generated
        // synopsis and warns "invalid synopsis part: <word>" on every run.
        $page = $args[0] ?? null;
        if (!$page) {
            WP_CLI::error('Page argument is required.');
        }

        $post_id = is_numeric($page) ? (int) $page : url_to_postid(home_url($page));
        if (!$post_id) {
            WP_CLI::error(sprintf('Could not resolve page "%s".', $page));
        }

        $selector = $assoc_args['target'] ?? '';
        $value    = $assoc_args['value'] ?? '';
        $preview  = isset($assoc_args['preview']);

        if ($selector === '') {
            WP_CLI::error('--target is required.');
        }

        // Preflight-before-mutation gate (#96/#391). The mutating path writes
        // _pp_composition through the update_component action, so it routes through
        // the SAME per-action gate stack as `action execute` (a valid run-id, a
        // completed INSPECT, and a PREFLIGHT covering this page) — against the REAL
        // update_component registration, not a synthetic partial action array.
        // Using the real action means the patch path also gets the scope-consistency
        // assertion and the #358 composition precondition for free: patching a
        // composition-less page now fails closed early with a clear composition_required
        // error instead of a late, confusing failure inside pp_patch_composition. The
        // --preview path stays read-only and ungated (it never touches the action
        // registry). update_component is composition-mutating, so the freshness gate
        // (#113) and baseline refresh apply on the mutating path.
        $expected_version = null;
        if (!$preview) {
            $run_id = _pp_cli_require_run_id($assoc_args);
            if (!pp_operate_check_step($run_id, 'INSPECT')) {
                WP_CLI::error('Run token "' . $run_id . '" has no completed INSPECT step. Run `wp pp operate inspect` first.');
            }
            // Resolve the REAL registered action the patch writes through, so the gate,
            // freshness, and refresh all key on the real registration rather than a
            // synthetic partial array (#391). Fail closed if it is somehow unregistered;
            // the name is hardcoded, so null is a theme bug, not a user error.
            $patch_action = pp_get_action('update_component');
            if ($patch_action === null) {
                WP_CLI::error('The "update_component" action is not registered; cannot gate the patch write. This is a theme bug.');
            }
            // Shared per-action gate: scope-consistency + preflight coverage (#96) +
            // composition precondition (#358), against the real registered action.
            _pp_cli_require_preflight_for_action($run_id, $patch_action, ['post_id' => $post_id]);
            // The freshness gate returns the validated baseline version; thread it into the
            // patch so the apply is an atomic compare-and-swap (#13), not check-then-write.
            $expected_version = _pp_cli_require_composition_fresh($run_id, $patch_action, $post_id);
        }

        $result = pp_patch_composition($post_id, $selector, $value, $preview, $expected_version);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        // Refresh the freshness baseline (#113) after a successful apply (not preview).
        if (!$preview && isset($run_id)) {
            _pp_cli_refresh_composition_baseline($run_id, $patch_action, $post_id);
            // Touched-post tracking (#133): patch writes _pp_composition through the
            // update_component action, so a run-scoped restore must be able to revert it
            // too. Same fail-loud contract as `action execute`.
            if (!pp_operate_record_touched_post_id($run_id, $post_id)) {
                WP_CLI::error('Patch applied, but recording its touched post for run "' . $run_id . '" FAILED. `wp pp apply restore-composition` may not be able to revert this change. Run state may be missing or corrupt; re-run `wp pp operate inspect`.');
            }
        }

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Lists the composition history ring for a page (#133).
     *
     * Shows the prior-composition snapshots recorded before each write, newest first,
     * with the index and steps_back selector to pass to the restore_composition action.
     * Read-only — needs no run token.
     *
     * ## OPTIONS
     *
     * <page>
     * : Post ID or slug of the page.
     *
     * ## EXAMPLES
     *
     *     wp pp operate composition-history 19
     *     wp pp operate composition-history about-us
     *
     * @subcommand composition-history
     */
    public function composition_history($args, $assoc_args) {
        $page = $args[0] ?? null;
        if (!$page) {
            WP_CLI::error('Page argument is required.');
        }

        $post_id = is_numeric($page) ? (int) $page : url_to_postid(home_url($page));
        if (!$post_id) {
            WP_CLI::error(sprintf('Could not resolve page "%s".', $page));
        }

        $history = pp_get_composition_history($post_id);
        $count   = count($history);

        // Render newest-first: the last ring entry is the most recent prior state,
        // reachable as steps_back=1. history_index stays the absolute ring position.
        $rows = [];
        foreach ($history as $index => $entry) {
            $rows[] = [
                'history_index' => $index,
                'steps_back'    => $count - $index,
                'version'       => $entry['version'],
                'timestamp'     => $entry['timestamp'],
                'components'    => count($entry['composition']),
            ];
        }
        $rows = array_reverse($rows);

        WP_CLI::line(json_encode([
            'post_id' => $post_id,
            'max'     => pp_composition_history_max(),
            'count'   => $count,
            'entries' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

WP_CLI::add_command('pp operate', 'PP_Operate_Command');

// ── Screenshot CLI ──────────────────────────────────────────────────────────

class PP_Screenshot_Command extends WP_CLI_Command {

    /**
     * Captures a screenshot via PP_BROWSER_CMD.
     *
     * ## OPTIONS
     *
     * [--capture-url=<url>]
     * : URL to capture. Required unless --post_id is given.
     *   Named --capture-url to avoid collision with WP-CLI's global --url flag.
     *
     * [--post_id=<id>]
     * : WordPress post ID. Resolves URL via get_permalink().
     *
     * [--width=<px>]
     * : Viewport width in pixels. Default: 1280.
     *
     * [--output=<path>]
     * : Output file path. Uses convention if omitted.
     *
     * [--playbook=<name>]
     * : Generate full spec with both viewports for this playbook.
     *
     * ## EXAMPLES
     *
     *     wp pp screenshot capture --capture-url=https://dev.promptingpress.com/ --width=1280
     *     wp pp screenshot capture --post_id=42 --playbook=create-page
     *
     */
    public function capture($args, $assoc_args) {
        $url     = $assoc_args['capture-url'] ?? '';
        $post_id = isset($assoc_args['post_id']) ? (int) $assoc_args['post_id'] : 0;
        $width   = (int) ($assoc_args['width'] ?? 1280);
        $output  = $assoc_args['output'] ?? '';
        $playbook = $assoc_args['playbook'] ?? '';

        // Playbook mode: capture both viewports
        if ($playbook && $post_id) {
            $specs = pp_screenshot_spec($post_id, $playbook);
            $results = [];
            foreach ($specs as $spec) {
                $results[] = pp_screenshot_capture($spec);
            }
            WP_CLI::line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $any_failed = !empty(array_filter($results, fn($r) => !$r['ok']));
            if ($any_failed) {
                WP_CLI::halt(1);
            }
            return;
        }

        // Single capture mode
        if (!$url && $post_id) {
            $url = get_permalink($post_id);
        }
        if (!$url) {
            WP_CLI::error('Either --capture-url or --post_id is required.');
        }

        if (!$output) {
            $base_dir = pp_screenshot_dir();
            $output = $base_dir . '/' . date('Ymd-His') . '-' . $width . 'px.png';
        }

        $spec = [
            'url'    => $url,
            'width'  => $width,
            'height' => 800,
            'output' => $output,
        ];

        $result = pp_screenshot_capture($spec);
        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$result['ok']) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Diagnoses screenshot-capture readiness for the current runtime context.
     *
     * Reports whether PP_BROWSER_CMD resolves, from where (constant vs env), and in which
     * context (CLI `wp` vs web PHP — they can resolve different config). With --probe it
     * attempts a real minimal capture to confirm the adapter actually launches and writes
     * a file. Read-only: never mutates the site. Exits 1 when not ready so scripts and the
     * operating loop can gate visual-proof steps on it.
     *
     * ## OPTIONS
     *
     * [--probe]
     * : Attempt a real minimal capture against the home URL to confirm the adapter runs.
     *
     * ## EXAMPLES
     *
     *     wp pp screenshot doctor
     *     wp pp screenshot doctor --probe
     *
     */
    public function doctor($args, $assoc_args) {
        $readiness = pp_screenshot_readiness(isset($assoc_args['probe']));
        WP_CLI::line(json_encode($readiness, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (!$readiness['ready']) {
            WP_CLI::halt(1);
        }
    }
}

WP_CLI::add_command('pp screenshot', 'PP_Screenshot_Command');

// ── Sync CLI ────────────────────────────────────────────────────────────────

class PP_Sync_Command extends WP_CLI_Command {

    /**
     * Checks for drift between the deployment manifest and live theme files.
     *
     * Reports modified, added (live-only), and deleted files.
     * Exit 0 if clean, exit 1 if drift detected.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Exit 0 regardless of drift (still prints summary).
     *
     * [--save-manifest]
     * : Save current state as the new deployment manifest.
     *
     * ## EXAMPLES
     *
     *     wp pp sync check
     *     wp pp sync check --force
     *     wp pp sync check --save-manifest
     *
     */
    public function check($args, $assoc_args) {
        $target = pp_get_target();
        if ($target['theme_path'] === null) {
            WP_CLI::error('Cannot resolve theme path. Run wp pp target show to diagnose.');
        }

        $theme_path = $target['theme_path'];
        if (!is_dir($theme_path)) {
            WP_CLI::error(sprintf('Theme path %s does not exist.', $theme_path));
        }

        // Save manifest mode
        if (isset($assoc_args['save-manifest'])) {
            $hashes = _pp_hash_theme_files($theme_path);
            $saved = _pp_save_deployment_manifest($theme_path, $hashes);
            if (!$saved) {
                WP_CLI::error('Failed to save deployment manifest. Check permissions on ' . dirname(_pp_deployment_manifest_path()));
            }
            WP_CLI::success(sprintf('Deployment manifest saved: %d files hashed.', count($hashes)));
            return;
        }

        // Load manifest
        $manifest = _pp_load_deployment_manifest();
        $current_hashes = _pp_hash_theme_files($theme_path);

        if ($manifest === null) {
            WP_CLI::warning('No previous deployment manifest found. Run wp pp sync check --save-manifest after your next sync to establish a baseline.');
            // Save current state as baseline
            $saved = _pp_save_deployment_manifest($theme_path, $current_hashes);
            if (!$saved) {
                WP_CLI::error('Failed to save baseline manifest. Check permissions on ' . dirname(_pp_deployment_manifest_path()));
            }
            WP_CLI::line(sprintf('Baseline manifest created: %d files.', count($current_hashes)));
            return;
        }

        $manifest_hashes = $manifest['file_hashes'];

        // Compute drift
        $modified = [];
        $added = [];    // live-only (not in manifest)
        $deleted = [];  // in manifest but not live

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

        // Report
        $report = [
            'drift'    => $has_drift,
            'modified' => $modified,
            'added'    => $added,
            'deleted'  => $deleted,
            'manifest_timestamp' => $manifest['timestamp'] ?? 'unknown',
        ];

        WP_CLI::line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($has_drift) {
            if (!empty($added)) {
                WP_CLI::warning(sprintf('%d live-only file(s) not in deployment manifest — a sync would NOT include these.', count($added)));
            }
            if (!empty($modified)) {
                WP_CLI::warning(sprintf('%d file(s) modified since last deployment.', count($modified)));
            }
            if (!empty($deleted)) {
                WP_CLI::warning(sprintf('%d file(s) in manifest no longer present on live.', count($deleted)));
            }

            if (isset($assoc_args['force'])) {
                WP_CLI::line('--force: proceeding despite drift.');
                return;
            }

            WP_CLI::halt(1);
        } else {
            WP_CLI::success('No drift detected. Live theme matches deployment manifest.');
        }
    }
}

WP_CLI::add_command('pp sync', 'PP_Sync_Command');

/**
 * Theme integrity commands — compare live files against the shipped baseline manifest.
 */
class PP_Integrity_Command {

    /**
     * Run a full integrity check against the shipped manifest.
     *
     * Hashes all current theme files, compares against integrity-manifest.json,
     * and stores the result in the pp_theme_integrity option.
     *
     * ## EXIT CODES
     *
     * 0 — safe (all files match)
     * 1 — unsafe (modified, missing, or extra files detected)
     * 2 — invalid manifest (JSON parse error or schema validation failure)
     * 3 — no manifest found (pre-integrity theme version)
     *
     * @subcommand check
     */
    public function check($args, $assoc_args): void {
        $result = pp_check_theme_integrity();

        if ($result === null) {
            WP_CLI::line('No integrity manifest found. This theme version predates integrity tracking.');
            WP_CLI::halt(3);
            return;
        }

        if ($result['status'] === 'invalid_manifest') {
            WP_CLI::error(sprintf(
                'Integrity manifest is invalid (theme version %s): %s',
                PP_VERSION,
                $result['error'] ?? 'Unknown error'
            ), false);
            WP_CLI::halt(2);
            return;
        }

        // Print the result as formatted JSON.
        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($result['status'] === 'safe') {
            WP_CLI::success('All theme files match the shipped manifest.');
            return;
        }

        // Status is 'unsafe'.
        if (!empty($result['modified'])) {
            WP_CLI::warning('Modified files (hash mismatch):');
            foreach ($result['modified'] as $path) {
                WP_CLI::line('  - ' . $path);
            }
        }

        if (!empty($result['missing'])) {
            WP_CLI::warning('Missing files (in manifest but not on disk):');
            foreach ($result['missing'] as $path) {
                WP_CLI::line('  - ' . $path);
            }
        }

        if (!empty($result['extra'])) {
            WP_CLI::warning('Extra files (on disk but not in manifest):');
            foreach ($result['extra'] as $path) {
                WP_CLI::line('  - ' . $path);
            }
            WP_CLI::line('These files are not part of the shipped theme. A theme update or reinstall');
            WP_CLI::line('replaces the entire theme directory and will delete them.');
            WP_CLI::line('Recommendation: move extra files to a child theme, plugin, or wp-content/.');
        }

        WP_CLI::halt(1);
    }

    /**
     * Print the stored integrity status (read-only, no file hashing).
     *
     * Reads the pp_theme_integrity option and prints it. Does NOT run a new
     * check or modify the stored option. If the stored version differs from
     * the current PP_VERSION, prints a staleness warning.
     *
     * @subcommand status
     */
    public function status($args, $assoc_args): void {
        $option = get_option('pp_theme_integrity');

        if (!is_array($option) || empty($option['status'])) {
            WP_CLI::line('No integrity check results stored. Run `wp pp integrity check` first.');
            return;
        }

        // Staleness warning — read-only, does NOT delete or update the option.
        $stored_version = $option['version'] ?? 'unknown';
        if ($stored_version !== PP_VERSION) {
            WP_CLI::warning(sprintf(
                'Results are from version %s, current theme is %s — run `wp pp integrity check` to refresh.',
                $stored_version,
                PP_VERSION
            ));
        }

        WP_CLI::line(json_encode($option, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

WP_CLI::add_command('pp integrity', 'PP_Integrity_Command');
