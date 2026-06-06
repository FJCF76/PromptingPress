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
        'EDIT' => [
            'phase'            => 'implementer',
            'required_inputs'  => ['mutation_plan'],
            'required_outputs' => ['edit_result'],
        ],
        'PREFLIGHT' => [
            'phase'            => 'operator',
            'required_inputs'  => ['edit_result'],
            'required_outputs' => ['preflight_result'],
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
 * If no manifest is found, returns no-drift. An agent that wants to
 * establish a baseline must run `wp pp sync check` explicitly.
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
    ];
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
    if ($post_id !== null) {
        $composition = get_post_meta($post_id, '_pp_composition', true);
        if (is_array($composition) && !empty($composition)) {
            $smells = pp_validate_composition_smells($composition);
        }
    }

    return [
        'target'    => pp_get_target(),
        'pages'     => pp_composition_pages(),
        'drift'     => $drift,
        'preflight' => pp_preflight([], $drift),
        'tokens'    => pp_design_tokens(),
        'conflicts' => pp_check_custom_css_conflicts(),
        'smells'    => $smells,
    ];
}

// ── Preflight ──────────────────────────────────────────────────────────────

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

    // Check 3: Backup writability
    $writable = _pp_check_backup_writability();
    if ($writable === true) {
        $checks[] = ['check' => 'backup_writable', 'pass' => true, 'message' => 'Backup directory is writable.'];
    } else {
        $checks[] = ['check' => 'backup_writable', 'pass' => false, 'message' => $writable];
    }

    // Check 4: Drift
    if ($drift === null) {
        $drift = pp_check_drift();
    }

    // Auto-populate planned_files from apply definition if apply_name given
    $planned_files = $context['planned_files'] ?? [];
    if (empty($planned_files) && !empty($context['apply_name'])) {
        $apply_def = pp_get_apply($context['apply_name']);
        if ($apply_def !== null && isset($apply_def['target_file'])) {
            $planned_files = [$apply_def['target_file']];
        }
    }

    if (!$drift['has_drift']) {
        $checks[] = ['check' => 'drift', 'pass' => true, 'message' => 'No drift detected.'];
    } else {
        // Check overlap between drifted files and planned mutations
        $drifted_files = array_merge($drift['modified'], $drift['added'], $drift['deleted']);
        $overlap = array_intersect($drifted_files, $planned_files);

        if (!empty($overlap)) {
            $checks[] = [
                'check'   => 'drift',
                'pass'    => false,
                'message' => 'Drift overlaps with planned mutations: ' . implode(', ', $overlap) . '. Escalate to human before proceeding.',
            ];
        } else {
            $checks[] = [
                'check'   => 'drift',
                'pass'    => true,
                'message' => 'Drift detected in non-overlapping files (warning only): ' .
                    implode(', ', $drifted_files) . '. Note in HANDOFF report.',
            ];
        }
    }

    // Check 5: Theme writable
    $theme_path = $target['theme_path'];
    if ($theme_path !== null && is_dir($theme_path) && is_writable($theme_path)) {
        $checks[] = ['check' => 'theme_writable', 'pass' => true, 'message' => 'Theme directory is writable.'];
    } elseif ($theme_path === null) {
        $checks[] = ['check' => 'theme_writable', 'pass' => false, 'message' => 'Cannot resolve theme path.'];
    } else {
        $checks[] = ['check' => 'theme_writable', 'pass' => false, 'message' => 'Theme directory is not writable: ' . $theme_path];
    }

    // Check 6: Target page (only for page operations)
    if (isset($context['post_id'])) {
        $post = get_post($context['post_id']);
        if ($post === null) {
            $checks[] = ['check' => 'target_page', 'pass' => false, 'message' => 'Post ID ' . $context['post_id'] . ' does not exist.'];
        } else {
            $composition = get_post_meta($context['post_id'], '_pp_composition', true);
            if (is_array($composition) && !empty($composition)) {
                $checks[] = ['check' => 'target_page', 'pass' => true, 'message' => 'Target page exists with composition: ' . $post->post_title];
            } else {
                $checks[] = ['check' => 'target_page', 'pass' => false, 'message' => 'Post ID ' . $context['post_id'] . ' exists but has no composition.'];
            }
        }
    }

    $all_pass = empty(array_filter($checks, fn($c) => !$c['pass']));

    return [
        'ok'     => $all_pass,
        'checks' => $checks,
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
            ['id' => 'nav_footer_present',   'description' => 'Navigation and footer render correctly',                    'gate' => 'soft', 'viewport' => 'desktop'],
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
 * Returns the state file path for a run token.
 *
 * @param string $run_id The run token UUID.
 * @return string
 */
function pp_operate_run_path( string $run_id ): string {
    return sys_get_temp_dir() . '/pp-operate-run-' . $run_id . '.json';
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
 * Generates a UUID, writes a state file to /tmp with INSPECT recorded.
 * The state file tracks which steps have been completed for this run.
 *
 * Run token state files enforce step ordering in real-time (CLI calls).
 * pp_validate_loop_run() validates completeness post-hoc (finished run manifest).
 * These are complementary layers, not alternatives.
 *
 * @return string|WP_Error  UUID string on success, WP_Error on failure.
 */
function pp_operate_create_run() {
    $tmp_dir = sys_get_temp_dir();
    if ( ! is_writable( $tmp_dir ) ) {
        return new WP_Error(
            'tmp_not_writable',
            'Cannot create run token: ' . $tmp_dir . ' is not writable. Check server permissions.'
        );
    }

    $run_id = wp_generate_uuid4();
    $state  = [
        'steps_completed' => [ 'INSPECT' ],
        'created_at'      => time(),
    ];

    $path   = pp_operate_run_path( $run_id );
    $result = file_put_contents( $path, wp_json_encode( $state ), LOCK_EX );

    if ( $result === false ) {
        return new WP_Error(
            'state_write_failed',
            'Cannot write run state file: ' . $path . '. Check disk space and permissions.'
        );
    }

    return $run_id;
}

/**
 * Checks whether a required step has been completed for a run.
 *
 * Returns false if the run-id is invalid, state file is missing,
 * expired (>2 hours), contains invalid JSON, or does not include
 * the required step. Expired files are cleaned up automatically.
 *
 * @param string $run_id        The run token UUID.
 * @param string $required_step The step name to check for (e.g. 'INSPECT', 'PREFLIGHT').
 * @return bool
 */
function pp_operate_check_step( string $run_id, string $required_step ): bool {
    if ( ! pp_operate_valid_run_id( $run_id ) ) {
        return false;
    }

    $path = pp_operate_run_path( $run_id );

    if ( ! file_exists( $path ) ) {
        return false;
    }

    $raw  = file_get_contents( $path );
    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) || ! isset( $data['steps_completed'] ) || ! isset( $data['created_at'] ) ) {
        return false;
    }

    // Auto-expire after TTL. Clean up the stale file.
    if ( ( time() - (int) $data['created_at'] ) > PP_OPERATE_RUN_TTL ) {
        @unlink( $path );
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
    if ( ! pp_operate_valid_run_id( $run_id ) ) {
        return false;
    }

    $path = pp_operate_run_path( $run_id );

    $fh = @fopen( $path, 'r+' );
    if ( ! $fh ) {
        return false;
    }

    if ( ! flock( $fh, LOCK_EX ) ) {
        fclose( $fh );
        return false;
    }

    $raw  = stream_get_contents( $fh );
    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) || ! isset( $data['steps_completed'] ) || ! isset( $data['created_at'] ) ) {
        flock( $fh, LOCK_UN );
        fclose( $fh );
        return false;
    }

    // Auto-expire after TTL.
    if ( ( time() - (int) $data['created_at'] ) > PP_OPERATE_RUN_TTL ) {
        flock( $fh, LOCK_UN );
        fclose( $fh );
        @unlink( $path );
        return false;
    }

    // Idempotent: don't duplicate.
    if ( ! in_array( $step, $data['steps_completed'], true ) ) {
        $data['steps_completed'][] = $step;
    }

    ftruncate( $fh, 0 );
    rewind( $fh );
    fwrite( $fh, wp_json_encode( $data ) );
    fflush( $fh );
    flock( $fh, LOCK_UN );
    fclose( $fh );

    return true;
}

/**
 * Deletes the run state file. Called at HANDOFF.
 *
 * No-op if the file does not exist or run-id is invalid.
 *
 * @param string $run_id The run token UUID.
 */
function pp_operate_cleanup_run( string $run_id ): void {
    if ( ! pp_operate_valid_run_id( $run_id ) ) {
        return;
    }

    $path = pp_operate_run_path( $run_id );

    if ( file_exists( $path ) ) {
        @unlink( $path );
    }
}
