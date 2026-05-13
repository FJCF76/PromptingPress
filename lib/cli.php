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
     * ## EXAMPLES
     *
     *     wp pp action execute create_page --params='{"title":"New Page"}'
     *     wp pp action execute add_component --params='{"post_id":4,"component":"hero","props":{"title":"Hello"}}'
     *
     */
    public function execute($args, $assoc_args) {
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
            $rows[] = [
                'name'        => $name,
                'domain'      => $def['domain'],
                'target_file' => $def['target_file'],
                'description' => $def['description'] ?? '',
                'params'      => implode(', ', $params),
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['name', 'domain', 'target_file', 'description', 'params']);
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
     * ## EXAMPLES
     *
     *     wp pp apply execute update_design_token --params='{"token":"--color-accent","value":"#b45309"}'
     *
     */
    public function execute($args, $assoc_args) {
        _pp_cli_require_apply_cap();

        list($name) = $args;
        $params = pp_cli_parse_params($assoc_args);

        $result = pp_execute_apply($name, $params);

        WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($result['ok']) {
            WP_CLI::success('Apply "' . $name . '" executed.');
        } else {
            WP_CLI::halt(1);
        }
    }

    /**
     * Restores base.css from a restore point.
     *
     * ## OPTIONS
     *
     * [--point=<index>]
     * : Restore point index (1 = most recent). Default: latest.
     *
     * [--list]
     * : List available restore points instead of restoring.
     *
     * ## EXAMPLES
     *
     *     wp pp apply restore
     *     wp pp apply restore --point=2
     *     wp pp apply restore --list
     *
     */
    public function restore($args, $assoc_args) {
        _pp_cli_require_apply_cap();

        // List mode
        if (isset($assoc_args['list'])) {
            $points = pp_restore_points('base.css');
            if (empty($points)) {
                WP_CLI::warning('No restore points available.');
                return;
            }

            $rows = [];
            foreach ($points as $point) {
                $rows[] = [
                    'index'     => $point['index'],
                    'timestamp' => $point['timestamp'],
                ];
            }
            WP_CLI\Utils\format_items('table', $rows, ['index', 'timestamp']);
            return;
        }

        // Restore mode
        $target = get_template_directory() . '/assets/css/base.css';
        $point_index = isset($assoc_args['point']) ? (int) $assoc_args['point'] : null;

        $result = pp_restore($target, $point_index);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success('Restored base.css from restore point ' . ($point_index ?? 1) . '.');
    }

    /**
     * Validates the execution surface before any mutation.
     *
     * Checks: target resolved, capability OK, backup directory writable.
     * Exit 0 if all pass, exit 1 if any fail.
     *
     * ## EXAMPLES
     *
     *     wp pp apply preflight
     *
     */
    public function preflight($args, $assoc_args) {
        $checks = [];

        // Check 1: Target resolved
        $target = pp_get_target();
        $missing = array_keys(array_filter($target, fn($v) => $v === null));
        if (empty($missing)) {
            $checks[] = ['check' => 'target', 'pass' => true, 'message' => 'Target resolved: ' . $target['site_url']];
        } else {
            $checks[] = ['check' => 'target', 'pass' => false, 'message' => 'Target not fully resolved. Missing: ' . implode(', ', $missing) . '. Run wp pp target show to inspect.'];
        }

        // Check 2: Capability
        if (defined('WP_CLI') && WP_CLI) {
            $checks[] = ['check' => 'capability', 'pass' => true, 'message' => 'WP-CLI context: capability gate bypassed.'];
        } elseif (current_user_can('manage_options')) {
            $checks[] = ['check' => 'capability', 'pass' => true, 'message' => 'User has manage_options capability.'];
        } else {
            $checks[] = ['check' => 'capability', 'pass' => false, 'message' => 'Missing manage_options capability. Run via WP-CLI or as an admin user.'];
        }

        // Check 3: Backup writability
        $writable = _pp_check_backup_writability();
        if ($writable === true) {
            $checks[] = ['check' => 'backup_writable', 'pass' => true, 'message' => 'Backup directory is writable.'];
        } else {
            $checks[] = ['check' => 'backup_writable', 'pass' => false, 'message' => $writable . ' Set PP_BACKUP_DIR in wp-config.php to override the backup directory path.'];
        }

        $all_pass = empty(array_filter($checks, fn($c) => !$c['pass']));

        WP_CLI::line(json_encode([
            'ok'     => $all_pass,
            'checks' => $checks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$all_pass) {
            WP_CLI::halt(1);
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
