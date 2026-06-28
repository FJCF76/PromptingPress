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
 * Validates and returns the --run-id from CLI args.
 * Halts with WP_CLI::error if missing or not a valid UUID v4.
 */
function _pp_cli_require_run_id(array $assoc_args): string {
    if (empty($assoc_args['run-id'])) {
        WP_CLI::error('--run-id is required. Run `wp pp operate inspect` first to get a run token.');
    }
    $run_id = $assoc_args['run-id'];
    if (!pp_operate_valid_run_id($run_id)) {
        WP_CLI::error('--run-id must be a valid UUID v4. Got: "' . $run_id . '"');
    }
    return $run_id;
}

/**
 * Preflight-before-mutation gate (#96). Halts with an actionable WP_CLI::error
 * unless the run has a completed PREFLIGHT covering the intended target: a
 * specific $post_id for page/section mutations, or the site grain when $post_id
 * is null. Shared by `action execute` and `operate patch`.
 */
function _pp_cli_require_preflight_covers(string $run_id, ?int $post_id): void {
    if (pp_operate_preflight_covers($run_id, $post_id)) {
        return;
    }
    $target = $post_id !== null ? 'post ' . $post_id : 'site-scoped changes';
    $hint   = 'wp pp apply preflight --run-id=' . $run_id
            . ($post_id !== null ? ' --post_id=' . $post_id : '');
    WP_CLI::error('Run token "' . $run_id . '" has no completed PREFLIGHT covering ' . $target
        . '. Mutating actions require a successful preflight first. Run `' . $hint . '`.');
}

/**
 * Applies the preflight gate for a named registered action. Resolves the target
 * post from $params (page/section actions carry a required post_id; site actions
 * carry none), asserts that the action's declared scope is consistent with that
 * presence so a misdeclared action can't be mis-gated, then enforces coverage.
 */
function _pp_cli_require_preflight_for_action(string $run_id, array $action, array $params): void {
    $scope   = $action['scope'] ?? 'unknown';
    $post_id = isset($params['post_id']) ? (int) $params['post_id'] : null;

    // Fail closed on an unrecognized scope. The page/site assertions below only
    // hold for the known scopes; a missing or mistyped scope would otherwise fall
    // through to post_id-presence keying, letting a misdeclared page action be
    // unlocked by a site-grain preflight. Refuse rather than guess the target.
    if (!in_array($scope, ['page', 'section', 'site'], true)) {
        WP_CLI::error('Action "' . ($action['name'] ?? '?') . '" has an unrecognized scope "'
            . $scope . '"; refusing to resolve a preflight target. This is an action-registration bug.');
    }

    // Scope-consistency guardrail: page/section MUST carry post_id; site MUST NOT.
    $is_page_scope = in_array($scope, ['page', 'section'], true);
    if ($is_page_scope && $post_id === null) {
        WP_CLI::error('Action "' . ($action['name'] ?? '?') . '" is ' . $scope
            . '-scoped but no post_id was provided; cannot resolve a preflight target.');
    }
    if ($scope === 'site' && $post_id !== null) {
        WP_CLI::error('Action "' . ($action['name'] ?? '?') . '" is site-scoped but a post_id '
            . 'was provided; site actions are not page-targeted.');
    }

    _pp_cli_require_preflight_covers($run_id, $post_id);
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
        }

        $result = pp_execute_action($name, $params);

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($result['ok']) {
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

        _pp_cli_require_apply_cap();

        if (isset($assoc_args['token'])) {
            $result = pp_execute_apply('reset_design_token', ['token' => $assoc_args['token']]);
        } else {
            $result = pp_execute_apply('reset_all_design_tokens', []);
        }

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($result['ok']) {
            pp_operate_record_step($run_id, 'APPLY');
            $count = count($result['changes']);
            WP_CLI::success($count > 0 ? "Reset $count token(s) to product defaults." : 'No overrides to reset.');
        } else {
            WP_CLI::halt(1);
        }
    }

    /**
     * Validates the execution surface before any mutation.
     *
     * Checks: target resolved, capability OK, backup directory writable,
     * drift state, theme writability, and target page (if applicable).
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

        if (!empty($assoc_args['apply'])) {
            $context['apply_name'] = $assoc_args['apply'];
        }

        $result = pp_preflight($context);

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$result['ok']) {
            WP_CLI::halt(1);
        }

        // Record PREFLIGHT only on success, in ONE atomic write: the PREFLIGHT
        // step, the target this preflight covered (post_id for page/section work,
        // or the site grain when no post is given), and the pre-apply token
        // snapshot that `apply restore` rolls back to. Committing them together is
        // load-bearing: mutating gates (action execute / operate patch) unlock on
        // the recorded coverage alone, so a partial write must never leave the run
        // unlocked. First-write-wins inside the recorder keeps the rollback
        // baseline stable across re-runs; token overrides are read under the lock
        // for an atomic baseline. If this cannot be recorded the run has neither a
        // mutation unlock nor a rollback net, so fail here.
        if (!pp_operate_record_preflight($run_id, $context['post_id'] ?? null, pp_snapshot_token_overrides())) {
            WP_CLI::error('Could not record PREFLIGHT state for run token "' . $run_id . '". State file may be missing or expired. Re-run `wp pp operate inspect`.');
        }
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

        $composition = pp_get_composition($post_id);
        if (empty($composition)) {
            WP_CLI::warning('No composition found for page ' . $post_id . '.');
            return;
        }

        $warnings = pp_validate_composition_styling($composition);
        $smells   = pp_validate_composition_smells($composition);

        if (empty($warnings) && empty($smells)) {
            WP_CLI::success('Page ' . $post_id . ': all components have stable IDs, no ambiguous targeting, no composition smells.');
            return;
        }

        if (!empty($warnings)) {
            WP_CLI::warning(count($warnings) . ' ambiguous targeting warning(s):');
            $rows = [];
            foreach ($warnings as $w) {
                $rows[] = [
                    'component' => $w['component'],
                    'indices'   => implode(', ', $w['indices']),
                    'issue'     => 'Duplicate component type without stable IDs',
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
                $composition = pp_get_composition($post_id);
                $warnings    = pp_validate_composition_styling($composition);
                $smells      = pp_validate_composition_smells($composition);

                if (!empty($warnings) || !empty($smells)) {
                    $pass = false;
                    $issue_count = count($warnings) + count($smells);
                    WP_CLI::warning("Page {$post_id} ({$title}): {$issue_count} issue(s)");
                    foreach ($warnings as $w) {
                        WP_CLI::line("  - {$w['component']} at indices " . implode(', ', $w['indices']) . ' (no stable IDs)');
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
     * : Run token from `wp pp operate inspect`. Required for the mutating path
     * : (everything except --preview), which writes the composition and so must
     * : sit behind a completed PREFLIGHT covering this page.
     *
     * ## EXAMPLES
     *
     *     wp pp operate patch 19 --target=hero.subtitle --value="New Subtitle" --preview
     *     wp pp operate patch 19 --target=hero.subtitle --value="New Subtitle" --run-id=<uuid>
     *
     */
    public function patch($args, $assoc_args) {
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

        // Preflight-before-mutation gate (#96). The mutating path writes
        // _pp_composition through the update_component action, so it must sit
        // behind the same run-token discipline as `action execute`: a valid
        // run-id, a completed INSPECT, and a PREFLIGHT covering this page. The
        // --preview path stays read-only and ungated.
        if (!$preview) {
            $run_id = _pp_cli_require_run_id($assoc_args);
            if (!pp_operate_check_step($run_id, 'INSPECT')) {
                WP_CLI::error('Run token "' . $run_id . '" has no completed INSPECT step. Run `wp pp operate inspect` first.');
            }
            _pp_cli_require_preflight_covers($run_id, $post_id);
        }

        $result = pp_patch_composition($post_id, $selector, $value, $preview);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
