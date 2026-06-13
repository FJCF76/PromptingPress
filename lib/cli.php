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

        _pp_cli_require_apply_cap();

        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        $result = pp_execute_apply($name, $params);

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($result['ok']) {
            pp_operate_record_step($run_id, 'APPLY');
            WP_CLI::success('Apply "' . $name . '" executed.');
        } else {
            WP_CLI::halt(1);
        }
    }

    /**
     * Resets design tokens to product defaults.
     *
     * Design token overrides are stored in the database. Use reset_design_token
     * or reset_all_design_tokens applies to revert to product defaults.
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

        // Record PREFLIGHT step only on success.
        if (!pp_operate_record_step($run_id, 'PREFLIGHT')) {
            WP_CLI::error('Could not record PREFLIGHT step for run token "' . $run_id . '". State file may be missing or expired. Re-run `wp pp operate inspect`.');
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
     * : Show the diff without writing.
     *
     * ## EXAMPLES
     *
     *     wp pp operate patch 19 --target=hero.subtitle --value="New Subtitle" --preview
     *     wp pp operate patch 19 --target=hero.subtitle --value="New Subtitle"
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
