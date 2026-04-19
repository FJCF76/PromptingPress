<?php
/**
 * lib/apply.php — PromptingPress Apply Layer (File-Based Mutations)
 *
 * Adjacent execution contract for file-based mutations.
 * Same architectural DNA as the action model (lib/actions.php) but for
 * file writes instead of database writes.
 *
 * Apply definition contract:
 *   name        => string (unique, snake_case)
 *   domain      => 'design' (future: other file-based domains)
 *   target_file => string (relative to theme root, e.g. 'assets/css/base.css')
 *   description => string (one sentence, caller-facing)
 *   params      => [param_name => ['type' => string, 'required' => bool], ...]
 *   validate    => callable(array $params): true|WP_Error
 *   preview     => callable(array $params): array (diff, never writes)
 *   apply       => callable(array $params): array (canonical result shape)
 *
 * Canonical result shape (apply):
 *   ['ok' => bool, 'apply' => string, 'domain' => string,
 *    'target_file' => string, 'restore_point' => int|null,
 *    'changes' => array, 'error' => string|null]
 *
 * Preview result shape (same + before/after):
 *   ['ok' => true, 'apply' => string, 'domain' => string,
 *    'target_file' => string, 'before' => array, 'after' => array,
 *    'changes' => array, 'error' => null]
 */

// ── Registry ────────────────────────────────────────────────────────────────

function pp_register_apply(string $name, array $definition): void {
    global $_pp_applies;
    if (!isset($_pp_applies)) {
        $_pp_applies = [];
    }
    $definition['name'] = $name;
    $_pp_applies[$name] = $definition;
}

function pp_get_registered_applies(): array {
    global $_pp_applies;
    return $_pp_applies ?? [];
}

function pp_get_apply(string $name): ?array {
    global $_pp_applies;
    return $_pp_applies[$name] ?? null;
}

// ── Validation ──────────────────────────────────────────────────────────────

/**
 * Validates apply params: structural checks (required, types) then
 * the apply's own semantic validate callable.
 *
 * @return true|WP_Error
 */
function pp_validate_apply(string $name, array $params) {
    $apply = pp_get_apply($name);
    if (!$apply) {
        return new WP_Error('unknown_apply', sprintf('Apply "%s" is not registered.', $name));
    }

    foreach ($apply['params'] as $param_name => $param_def) {
        if (!empty($param_def['required']) && !array_key_exists($param_name, $params)) {
            return new WP_Error(
                'missing_param',
                sprintf('Apply "%s" requires param "%s".', $name, $param_name)
            );
        }
        if (array_key_exists($param_name, $params) && $params[$param_name] !== null) {
            $expected_type = $param_def['type'] ?? 'string';
            $actual_type   = gettype($params[$param_name]);
            $type_map      = [
                'int'    => 'integer',
                'string' => 'string',
                'array'  => 'array',
                'bool'   => 'boolean',
            ];
            $expected_php = $type_map[$expected_type] ?? $expected_type;
            if ($actual_type !== $expected_php) {
                return new WP_Error(
                    'invalid_param_type',
                    sprintf('Param "%s" must be %s, got %s.', $param_name, $expected_type, $actual_type)
                );
            }
        }
    }

    return call_user_func($apply['validate'], $params);
}

/**
 * Previews an apply: validates, computes before/after diff, never writes.
 *
 * @return array|WP_Error
 */
function pp_preview_apply(string $name, array $params) {
    $validation = pp_validate_apply($name, $params);
    if (is_wp_error($validation)) {
        return $validation;
    }

    $apply = pp_get_apply($name);
    return call_user_func($apply['preview'], $params);
}

/**
 * Executes an apply: validates first, then applies.
 * Returns the canonical result shape.
 */
function pp_execute_apply(string $name, array $params): array {
    $validation = pp_validate_apply($name, $params);
    if (is_wp_error($validation)) {
        $apply = pp_get_apply($name);
        return [
            'ok'            => false,
            'apply'         => $name,
            'domain'        => $apply['domain'] ?? 'unknown',
            'target_file'   => $apply['target_file'] ?? '',
            'restore_point' => null,
            'changes'       => [],
            'error'         => $validation->get_error_message(),
        ];
    }

    $apply = pp_get_apply($name);
    return call_user_func($apply['apply'], $params);
}

// ── Helper: build result arrays ─────────────────────────────────────────────

function _pp_apply_result(string $name, string $domain, string $target_file, ?int $restore_point, array $changes): array {
    return [
        'ok'            => true,
        'apply'         => $name,
        'domain'        => $domain,
        'target_file'   => $target_file,
        'restore_point' => $restore_point,
        'changes'       => $changes,
        'error'         => null,
    ];
}

function _pp_apply_error(string $name, string $domain, string $target_file, string $error): array {
    return [
        'ok'            => false,
        'apply'         => $name,
        'domain'        => $domain,
        'target_file'   => $target_file,
        'restore_point' => null,
        'changes'       => [],
        'error'         => $error,
    ];
}

function _pp_apply_preview(string $name, string $domain, string $target_file, array $before, array $after, array $changes): array {
    return [
        'ok'          => true,
        'apply'       => $name,
        'domain'      => $domain,
        'target_file' => $target_file,
        'before'      => $before,
        'after'       => $after,
        'changes'     => $changes,
        'error'       => null,
    ];
}

// ── Backup & Restore ────────────────────────────────────────────────────────

/**
 * Returns the backup directory path. Creates it if needed.
 */
function _pp_backup_dir(): string {
    // Use WP_CONTENT_DIR if available, otherwise derive from theme directory.
    if (defined('WP_CONTENT_DIR')) {
        $dir = WP_CONTENT_DIR . '/pp-backups';
    } else {
        $dir = dirname(dirname(get_template_directory())) . '/pp-backups';
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

/**
 * Creates a backup of the target file.
 * Returns the backup file path, or false on failure.
 */
function _pp_create_backup(string $source_path): string|false {
    $dir = _pp_backup_dir();
    $basename = basename($source_path);
    $backup_path = $dir . '/' . $basename . '.backup.' . date('Ymd-His');

    $result = copy($source_path, $backup_path);
    if (!$result) {
        return false;
    }

    // Verify backup exists and is non-empty
    if (!file_exists($backup_path) || filesize($backup_path) === 0) {
        return false;
    }

    // Prune old backups: keep last 5
    _pp_prune_backups($basename, 5);

    return $backup_path;
}

/**
 * Prunes old backups, keeping only the N most recent.
 */
function _pp_prune_backups(string $basename, int $keep): void {
    $dir = _pp_backup_dir();
    $pattern = $dir . '/' . $basename . '.backup.*';
    $files = glob($pattern);
    if (!$files || count($files) <= $keep) {
        return;
    }

    // Sort newest first (filenames contain timestamps, so alphabetical = chronological)
    rsort($files);
    $to_delete = array_slice($files, $keep);
    foreach ($to_delete as $file) {
        unlink($file);
    }
}

/**
 * Returns available restore points for a given file basename.
 * Each restore point is ['index' => int, 'timestamp' => string, 'path' => string].
 * Index 1 = most recent.
 */
function pp_restore_points(string $basename = 'base.css'): array {
    $dir = _pp_backup_dir();
    $pattern = $dir . '/' . $basename . '.backup.*';
    $files = glob($pattern);
    if (!$files) {
        return [];
    }

    rsort($files); // newest first
    $points = [];
    foreach ($files as $i => $file) {
        // Extract timestamp from filename: base.css.backup.20260418-190000
        $parts = explode('.backup.', basename($file));
        $timestamp = $parts[1] ?? 'unknown';
        $points[] = [
            'index'     => $i + 1,
            'timestamp' => $timestamp,
            'path'      => $file,
        ];
    }

    return $points;
}

/**
 * Restores a file from a restore point.
 *
 * @param string   $target_path  The file to restore.
 * @param int|null $point_index  Restore point index (1 = most recent). Null = latest.
 * @return true|WP_Error
 */
function pp_restore(string $target_path, ?int $point_index = null) {
    $basename = basename($target_path);
    $points = pp_restore_points($basename);

    if (empty($points)) {
        return new WP_Error('no_backups', 'No restore points available.');
    }

    $index = $point_index ?? 1;
    $point = null;
    foreach ($points as $p) {
        if ($p['index'] === $index) {
            $point = $p;
            break;
        }
    }

    if (!$point) {
        return new WP_Error('invalid_point', sprintf('Restore point %d does not exist. Available: 1-%d.', $index, count($points)));
    }

    if (!file_exists($point['path']) || filesize($point['path']) === 0) {
        return new WP_Error('corrupt_backup', sprintf('Restore point %d is corrupted or empty.', $index));
    }

    $result = copy($point['path'], $target_path);
    if (!$result) {
        return new WP_Error('restore_failed', 'Failed to restore file from backup.');
    }

    // Invalidate token cache after restore
    pp_invalidate_design_tokens_cache();

    return true;
}

// ── Token Validation ────────────────────────────────────────────────────────

/**
 * Validates a CSS color value.
 * Accepts: 3/4/6/8-digit hex, rgb(), rgba(), hsl(), hsla().
 * Rejects: named colors.
 */
function _pp_validate_color(string $value): bool {
    // Hex: #fff, #ffff, #ffffff, #ffffffff
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
        return true;
    }
    // rgb()/rgba(): rgb(255, 0, 0) or rgba(0, 0, 0, 0.55)
    if (preg_match('/^rgba?\(\s*[\d.]+(%?\s*,\s*[\d.]+%?){2,3}\s*\)$/', $value)) {
        return true;
    }
    // hsl()/hsla(): hsl(120, 50%, 50%) or hsla(120, 50%, 50%, 0.5)
    if (preg_match('/^hsla?\(\s*[\d.]+\s*,\s*[\d.]+%\s*,\s*[\d.]+%\s*(,\s*[\d.]+)?\s*\)$/', $value)) {
        return true;
    }
    return false;
}

/**
 * Validates a CSS length value.
 * Accepts: numeric value with unit (rem, px, em, %, vw, vh).
 */
function _pp_validate_length(string $value): bool {
    return (bool) preg_match('/^[\d.]+\s*(rem|px|em|%|vw|vh)$/', $value);
}

/**
 * Validates a CSS font-family value.
 * Accepts: comma-separated font names.
 */
function _pp_validate_font_family(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    // Must contain at least one non-whitespace font name
    $fonts = array_filter(array_map('trim', explode(',', $value)));
    return count($fonts) > 0;
}

/**
 * Validates a CSS duration value.
 * Accepts: numeric value with time unit (ms, s).
 */
function _pp_validate_duration(string $value): bool {
    return (bool) preg_match('/^[\d.]+\s*(ms|s)$/', $value);
}

/**
 * Validates a token value based on its type.
 *
 * @return true|WP_Error
 */
function _pp_validate_token_value(string $value, ?string $type) {
    // Injection check: reject { } ;
    if (preg_match('/[{};]/', $value)) {
        return new WP_Error('injection', 'Value must not contain {, }, or ; characters.');
    }

    if ($value === '') {
        return new WP_Error('empty_value', 'Value must not be empty.');
    }

    if ($type === null) {
        return true; // No type metadata, generic validation only
    }

    switch ($type) {
        case 'color':
            if (!_pp_validate_color($value)) {
                return new WP_Error('invalid_color', 'Value must be a valid CSS color (hex, rgb(), rgba(), hsl(), hsla()). Named colors are not accepted.');
            }
            break;
        case 'length':
            if (!_pp_validate_length($value)) {
                return new WP_Error('invalid_length', 'Value must be a number with a CSS unit (rem, px, em, %, vw, vh).');
            }
            break;
        case 'font-family':
            if (!_pp_validate_font_family($value)) {
                return new WP_Error('invalid_font_family', 'Value must be a comma-separated list of font names.');
            }
            break;
        case 'duration':
            if (!_pp_validate_duration($value)) {
                return new WP_Error('invalid_duration', 'Value must be a number with a time unit (ms, s).');
            }
            break;
        case 'raw':
            break; // Injection check only, already done above
    }

    return true;
}

// ── File Operations ─────────────────────────────────────────────────────────

/**
 * Reads tokens directly from a CSS file, bypassing pp_design_tokens() cache.
 * Used for post-write verification.
 *
 * @return array  Token map: ['--name' => 'value', ...]
 */
function _pp_read_tokens_from_file(string $file_path): array {
    if (!file_exists($file_path)) {
        return [];
    }

    $css = file_get_contents($file_path);
    $tokens = [];

    if (preg_match('/:root\s*\{([^}]+)\}/s', $css, $root_match)) {
        preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $root_match[1], $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $tokens[trim($m[1])] = trim($m[2]);
        }
    }

    return $tokens;
}

/**
 * Performs full contract verification after a token write.
 * Verifies: target token has new value AND every non-target token unchanged.
 *
 * @param string $file_path    Path to the written file.
 * @param array  $before_map   Token values before the write (flat: name => value).
 * @param string $target_token The token that was changed.
 * @param string $new_value    The expected new value.
 * @return true|string         True if verified, error message string if violated.
 */
function _pp_verify_contract(string $file_path, array $before_map, string $target_token, string $new_value) {
    $after_map = _pp_read_tokens_from_file($file_path);

    // Check target token has the new value
    if (!isset($after_map[$target_token])) {
        return sprintf('Target token "%s" is missing after write.', $target_token);
    }
    if ($after_map[$target_token] !== $new_value) {
        return sprintf('Target token "%s" has value "%s", expected "%s".', $target_token, $after_map[$target_token], $new_value);
    }

    // Check every non-target token is unchanged
    foreach ($before_map as $name => $old_value) {
        if ($name === $target_token) {
            continue;
        }
        if (!isset($after_map[$name])) {
            return sprintf('Token "%s" is missing after write.', $name);
        }
        if ($after_map[$name] !== $old_value) {
            return sprintf('Token "%s" changed from "%s" to "%s" (should be unchanged).', $name, $old_value, $after_map[$name]);
        }
    }

    return true;
}

// ── Apply: update_design_token ──────────────────────────────────────────────
// Domain: design | Target: assets/css/base.css

pp_register_apply('update_design_token', [
    'domain'      => 'design',
    'target_file' => 'assets/css/base.css',
    'description' => 'Updates a single CSS design token value in base.css.',
    'params'      => [
        'token' => ['type' => 'string', 'required' => true],
        'value' => ['type' => 'string', 'required' => true],
    ],

    'validate' => function (array $params) {
        $token = $params['token'];
        $value = $params['value'];

        // Token must exist in the current token set
        $tokens = pp_design_tokens();
        if (!array_key_exists($token, $tokens)) {
            $available = implode(', ', array_keys($tokens));
            return new WP_Error('unknown_token', sprintf('Token "%s" is not a registered design token. Available: %s', $token, $available));
        }

        // Type-specific validation
        $type = $tokens[$token]['type'];
        return _pp_validate_token_value($value, $type);
    },

    'preview' => function (array $params) {
        $token = $params['token'];
        $value = $params['value'];
        $tokens = pp_design_tokens();

        $before_values = [];
        $after_values = [];
        foreach ($tokens as $name => $info) {
            $before_values[$name] = $info['value'];
            $after_values[$name]  = ($name === $token) ? $value : $info['value'];
        }

        return _pp_apply_preview(
            'update_design_token',
            'design',
            'assets/css/base.css',
            $before_values,
            $after_values,
            [['token' => $token, 'from' => $tokens[$token]['value'], 'to' => $value]]
        );
    },

    'apply' => function (array $params) {
        $token = $params['token'];
        $value = $params['value'];
        $file  = get_template_directory() . '/assets/css/base.css';

        // Snapshot before-state (flat map for contract verification)
        $tokens = pp_design_tokens();
        $before_map = [];
        foreach ($tokens as $name => $info) {
            $before_map[$name] = $info['value'];
        }

        $old_value = $before_map[$token];

        // No-op: postcondition already satisfied
        if ($old_value === $value) {
            return _pp_apply_result('update_design_token', 'design', 'assets/css/base.css', null, []);
        }

        // Create backup
        $backup_path = _pp_create_backup($file);
        if ($backup_path === false) {
            return _pp_apply_error('update_design_token', 'design', 'assets/css/base.css',
                'Failed to create backup. Write aborted for safety.');
        }

        // Read file and perform regex replacement
        $css = file_get_contents($file);
        $escaped_token = preg_quote($token, '/');
        $pattern = '/(' . $escaped_token . '\s*:\s*)([^;]+)(;)/';
        $replacement = '${1}' . str_replace('$', '\\$', $value) . '${3}';
        $new_css = preg_replace($pattern, $replacement, $css, 1);

        if ($new_css === null) {
            return _pp_apply_error('update_design_token', 'design', 'assets/css/base.css',
                sprintf('Regex replacement failed for token "%s".', $token));
        }

        // Write
        $write_result = file_put_contents($file, $new_css);
        if ($write_result === false) {
            // Attempt restore
            copy($backup_path, $file);
            return _pp_apply_error('update_design_token', 'design', 'assets/css/base.css',
                'Failed to write base.css. Restored from backup.');
        }

        // Full contract verification (bypass cache, read file directly)
        $verification = _pp_verify_contract($file, $before_map, $token, $value);
        if ($verification !== true) {
            // Auto-restore
            copy($backup_path, $file);
            pp_invalidate_design_tokens_cache();
            return _pp_apply_error('update_design_token', 'design', 'assets/css/base.css',
                'Contract verification failed: ' . $verification . ' Auto-restored from backup.');
        }

        // Invalidate cache so subsequent reads in same request return fresh data
        pp_invalidate_design_tokens_cache();

        // Determine restore point index (this backup is the most recent = index 1)
        return _pp_apply_result(
            'update_design_token',
            'design',
            'assets/css/base.css',
            1,
            [['token' => $token, 'from' => $old_value, 'to' => $value]]
        );
    },
]);
