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

    return [
        'valid'  => empty($errors),
        'errors' => $errors,
    ];
}
