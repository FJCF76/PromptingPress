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
        'composition_decode_error' => $composition_decode_error,
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

    // Check 3: Drift (backup writability check removed — token storage is database-backed)
    if ($drift === null) {
        $drift = pp_check_drift();
    }

    // Resolve the planned apply's target type once — it routes the
    // planned_files auto-population and the filesystem checks below.
    $apply_def         = !empty($context['apply_name']) ? pp_get_apply($context['apply_name']) : null;
    $apply_target_type = $apply_def !== null && isset($apply_def['target']['type']) ? $apply_def['target']['type'] : null;

    // Auto-populate planned_files from apply definition if apply_name given.
    // Option-based targets don't produce planned_files (no file drift concern).
    $planned_files = $context['planned_files'] ?? [];
    if (empty($planned_files) && $apply_target_type === 'file' && isset($apply_def['target']['path'])) {
        $planned_files = [$apply_def['target']['path']];
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
            $composition = pp_get_composition($context['post_id']);
            if (!empty($composition)) {
                $checks[] = ['check' => 'target_page', 'pass' => true, 'message' => 'Target page exists with composition: ' . $post->post_title];
            } else {
                $checks[] = ['check' => 'target_page', 'pass' => false, 'message' => 'Post ID ' . $context['post_id'] . ' exists but has no composition.'];
            }
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
    $shot = pp_screenshot_readiness();
    $checks[] = [
        'check'    => 'screenshot_readiness',
        'pass'     => $shot['ready'],
        'severity' => 'warning',
        'message'  => $shot['ready']
            ? 'Native screenshot capture is ready (' . $shot['message'] . ').'
            : $shot['message'] . ' Typed mutations may still proceed; native VERIFIED requires a '
              . 'working capture — run `wp pp screenshot doctor` to diagnose.',
    ];

    // ok ignores severity=warning rows: warnings surface problems (pass=false) without
    // blocking the apply. Checks without a severity are treated as errors (legacy behavior).
    $all_pass = empty(array_filter(
        $checks,
        fn($c) => !$c['pass'] && (($c['severity'] ?? 'error') !== 'warning')
    ));

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
        'site_id'         => pp_operate_site_id(),
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
 * Computes a stable identity for the current WordPress install.
 *
 * Run-state files live in the shared system temp dir, so two installs on the same
 * host (e.g. dev + prod) share that directory. Binding each run to a site identity
 * lets restore refuse to replay one install's snapshot against another. Uses the
 * same inputs as the token advisory lock (site URL + DB name + blog id) so the two
 * notions of "this install" stay consistent.
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
 * Reads and validates a run-state file, returning the decoded state or null.
 *
 * Single source of truth for "is this run usable right now": returns null on an
 * invalid run-id, a missing/corrupt/structurally-invalid file, an expired TTL
 * (the stale file is unlinked), or a site-identity mismatch. A run file written by
 * an older build (no site_id) is treated as a mismatch — fail-closed, drains within
 * the TTL. Corrupt JSON returns null WITHOUT truncating or recreating the file.
 *
 * @param string $run_id  The run token UUID.
 * @return array|null  The decoded state array, or null if the run is unusable.
 */
function pp_operate_read_state( string $run_id ): ?array {
    if ( ! pp_operate_valid_run_id( $run_id ) ) {
        return null;
    }

    $path = pp_operate_run_path( $run_id );
    if ( ! file_exists( $path ) ) {
        return null;
    }

    $data = json_decode( (string) file_get_contents( $path ), true );
    if ( ! is_array( $data ) || ! isset( $data['steps_completed'] ) || ! isset( $data['created_at'] ) ) {
        return null;
    }

    // Auto-expire after TTL. Clean up the stale file.
    if ( ( time() - (int) $data['created_at'] ) > PP_OPERATE_RUN_TTL ) {
        @unlink( $path );
        return null;
    }

    // Site identity: a run from another install (shared temp dir) is not usable here.
    if ( ! isset( $data['site_id'] ) || ! hash_equals( pp_operate_site_id(), (string) $data['site_id'] ) ) {
        return null;
    }

    return $data;
}

/**
 * Locked read-modify-write of a run-state file. Single critical-section helper behind
 * pp_operate_record_step / record_token_snapshot / record_touched_tokens so the
 * fopen+flock+validate+TTL+identity guards live in one place.
 *
 * The $mutator receives the decoded state array and returns the new array to persist,
 * or false to abort the write (the caller then sees a false return). Returns false on
 * invalid run-id, unopenable/unlockable file, structurally-invalid or expired state,
 * site-identity mismatch, or mutator abort.
 *
 * @param string   $run_id
 * @param callable $mutator  fn(array $data): array|false
 * @return bool  True only on a confirmed write.
 */
function pp_operate_mutate_state( string $run_id, callable $mutator ): bool {
    if ( ! pp_operate_valid_run_id( $run_id ) ) {
        return false;
    }

    $path = pp_operate_run_path( $run_id );
    $fh   = @fopen( $path, 'r+' );
    if ( ! $fh ) {
        return false;
    }

    if ( ! flock( $fh, LOCK_EX ) ) {
        fclose( $fh );
        return false;
    }

    $data = json_decode( stream_get_contents( $fh ), true );

    $abort = static function ( $fh ) {
        flock( $fh, LOCK_UN );
        fclose( $fh );
        return false;
    };

    if ( ! is_array( $data ) || ! isset( $data['steps_completed'] ) || ! isset( $data['created_at'] ) ) {
        return $abort( $fh );
    }
    if ( ( time() - (int) $data['created_at'] ) > PP_OPERATE_RUN_TTL ) {
        flock( $fh, LOCK_UN );
        fclose( $fh );
        @unlink( $path );
        return false;
    }
    if ( ! isset( $data['site_id'] ) || ! hash_equals( pp_operate_site_id(), (string) $data['site_id'] ) ) {
        return $abort( $fh );
    }

    $new = $mutator( $data );
    if ( $new === false || ! is_array( $new ) ) {
        return $abort( $fh );
    }

    ftruncate( $fh, 0 );
    rewind( $fh );
    fwrite( $fh, wp_json_encode( $new ) );
    fflush( $fh );
    flock( $fh, LOCK_UN );
    fclose( $fh );

    return true;
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
 * post is given), and the pre-apply token snapshot.
 *
 * Atomicity is load-bearing. `wp pp action execute` / `operate patch` unlock on
 * the recorded coverage alone (pp_operate_preflight_covers), unlike `apply
 * execute` which also checks the rollback snapshot. If coverage were written in a
 * separate call from the snapshot and the snapshot write failed, a later mutating
 * action could pass its gate even though the preflight command errored. Writing
 * step + coverage + snapshot inside one pp_operate_mutate_state critical section
 * means the run gains the complete post-preflight state or none of it, so any
 * failure leaves BOTH the action gate and the apply gate fail-closed.
 *
 * Idempotent: the step and each covered post_id de-dupe; the token snapshot is
 * first-write-wins, so re-running preflight never moves the rollback baseline.
 *
 * @param string     $run_id              The run token UUID.
 * @param int|null   $post_id             Target post for page/section preflight, or null for site grain.
 * @param array      $token_overrides     Current pp_token_overrides, read under the token lock.
 * @param array|null $composition_marker  The target's {version, hash} freshness marker (#113),
 *                                        for a page/section preflight. Recorded so `action
 *                                        execute` can reject a composition changed since this
 *                                        preflight. Null (or a null $post_id) records no marker.
 * @return bool    True on a confirmed write; false if the run state is
 *                 missing/expired/corrupt or identity-mismatched.
 */
function pp_operate_record_preflight( string $run_id, ?int $post_id, array $token_overrides, ?array $composition_marker = null ): bool {
    return pp_operate_mutate_state( $run_id, static function ( array $data ) use ( $post_id, $token_overrides, $composition_marker ) {
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
 * The composition analogue of the token_snapshot: captured at PREFLIGHT, this is the
 * full composition array a run-scoped restore reverts each touched post to. Distinct
 * from #113's composition_snapshot, which stores only the freshness MARKER (version +
 * hash) for the TOCTOU gate — the marker can't rebuild content. First-write-wins per
 * post so re-running preflight in the same run keeps the true pre-run baseline stable.
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
        $reverted[] = [ 'post_id' => $post_id, 'changed' => ( $before !== $after ) ];
    }

    return [ 'ok' => true, 'error' => null, 'reverted' => $reverted, 'skipped' => $skipped ];
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
        foreach ($composition as $index => $item) {
            if (isset($item['props']['id']) && $item['props']['id'] === $id) {
                return ['index' => $index, 'component' => $item];
            }
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
 *   hero.subtitle                              → simple type + field
 *   section[title="About Us"].body             → type + match + field
 *   grid[title="Features"].items[title="X"].text → nested item targeting
 *   hero[id="pp-a1b2c3d4"].subtitle            → ID-based targeting
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

// ── Component Field Editability Map ──────────────────────────────────────

/**
 * Registers editable fields for a component type.
 *
 * @param string $component_type  Component type name (e.g. 'hero', 'section').
 * @param array  $fields          Array of field definitions: ['name' => string, 'type' => string].
 */
function pp_register_component_fields(string $component_type, array $fields): void {
    global $_pp_component_fields;
    if (!isset($_pp_component_fields)) {
        $_pp_component_fields = [];
    }
    $_pp_component_fields[$component_type] = $fields;
}

/**
 * Retrieves the editable fields for a component type.
 *
 * @param string $component_type  Component type name.
 * @return array  Array of field definitions, or empty array if type is not registered.
 */
function pp_get_component_fields(string $component_type): array {
    global $_pp_component_fields;
    return $_pp_component_fields[$component_type] ?? [];
}

/**
 * Returns the full component-fields registry: component_type => field definitions.
 *
 * @return array<string, array>
 */
function pp_get_registered_component_fields(): array {
    global $_pp_component_fields;
    return $_pp_component_fields ?? [];
}

// ── Register default component fields ────────────────────────────────────

pp_register_component_fields('hero', [
    ['name' => 'title',    'type' => 'string'],
    ['name' => 'subtitle', 'type' => 'string'],
    ['name' => 'eyebrow',  'type' => 'string'],
    ['name' => 'cta_text', 'type' => 'string'],
    ['name' => 'cta_url',  'type' => 'url'],
]);

pp_register_component_fields('section', [
    ['name' => 'title',      'type' => 'string'],
    ['name' => 'eyebrow',    'type' => 'string'],
    ['name' => 'subheading', 'type' => 'string'],
    ['name' => 'body',       'type' => 'html'],
]);

pp_register_component_fields('grid', [
    ['name' => 'eyebrow',           'type' => 'string'],
    ['name' => 'subheading',        'type' => 'string'],
    ['name' => 'items[].title',     'type' => 'string'],
    ['name' => 'items[].text',      'type' => 'string'],
    ['name' => 'items[].link_url',  'type' => 'url'],
    ['name' => 'items[].link_text', 'type' => 'string'],
]);

pp_register_component_fields('faq', [
    ['name' => 'items[].question', 'type' => 'string'],
    ['name' => 'items[].answer',   'type' => 'html'],
]);

pp_register_component_fields('cta', [
    ['name' => 'title',       'type' => 'string'],
    ['name' => 'eyebrow',     'type' => 'string'],
    ['name' => 'text',        'type' => 'string'],
    ['name' => 'button_text', 'type' => 'string'],
    ['name' => 'button_url',  'type' => 'url'],
]);

pp_register_component_fields('testimonials', [
    ['name' => 'title',            'type' => 'string'],
    ['name' => 'eyebrow',          'type' => 'string'],
    ['name' => 'subheading',       'type' => 'string'],
    ['name' => 'items[].quote',    'type' => 'string'],
    ['name' => 'items[].author',   'type' => 'string'],
    ['name' => 'items[].role',     'type' => 'string'],
    ['name' => 'items[].company',  'type' => 'string'],
]);

// ── Inspect Composition ──────────────────────────────────────────────────

/**
 * Inspects a page's composition and returns editable targets with selectors.
 *
 * For each component, looks up the field editability map and builds semantic
 * selector strings for each editable field along with the current value.
 * Components not in the field map are included with an empty fields array.
 *
 * @param int $post_id  The WordPress post ID.
 * @return array|WP_Error  Array of component targets or WP_Error.
 */
function pp_inspect_composition(int $post_id): array|WP_Error {
    $composition = pp_get_composition($post_id);
    if (is_wp_error($composition)) {
        return $composition;
    }

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
 * @param string $component_type  The component type.
 * @return string|null  The match field name, or null if none available.
 */
function _pp_pick_nested_match_field(string $component_type): ?string {
    $match_map = [
        'grid'         => 'title',
        'faq'          => 'question',
        'testimonials' => 'quote',
    ];
    return $match_map[$component_type] ?? null;
}

// ── Patch Composition ────────────────────────────────────────────────────

/**
 * Patches a composition field by semantic selector.
 *
 * Parses the selector, resolves the target component, checks field editability,
 * and routes through the update_component action for preview or apply.
 *
 * @param int      $post_id          The WordPress post ID.
 * @param string   $selector_string  Semantic selector (e.g. "hero.subtitle").
 * @param string   $value            The new value for the targeted field.
 * @param bool     $preview          If true, return diff without writing.
 * @param int|null $expected_version Optimistic-locking baseline (#13) threaded into the
 *                                   update_component action so the apply is an atomic
 *                                   compare-and-swap. Null skips the CAS (preview / callers
 *                                   without a run baseline).
 * @return array|WP_Error  Preview diff or action result, or WP_Error.
 */
function pp_patch_composition(int $post_id, string $selector_string, string $value, bool $preview = false, ?int $expected_version = null) {
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
