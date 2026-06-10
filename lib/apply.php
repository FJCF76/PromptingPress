<?php
/**
 * lib/apply.php — PromptingPress Apply Layer
 *
 * Adjacent execution contract for mutations (file-based or option-based).
 * Same architectural DNA as the action model (lib/actions.php).
 *
 * Apply definition contract:
 *   name        => string (unique, snake_case)
 *   domain      => 'design' (future: other domains)
 *   target      => ['type' => 'file'|'option', ...type-specific keys]
 *                   file:   ['type' => 'file', 'path' => string]  (relative to theme root)
 *                   option: ['type' => 'option', 'key' => string] (wp_options key)
 *   description => string (one sentence, caller-facing)
 *   params      => [param_name => ['type' => string, 'required' => bool], ...]
 *   validate    => callable(array $params): true|WP_Error
 *   preview     => callable(array $params): array (diff, never writes)
 *   apply       => callable(array $params): array (canonical result shape)
 *
 * Canonical result shape (apply):
 *   ['ok' => bool, 'apply' => string, 'domain' => string,
 *    'target' => array, 'changes' => array, 'error' => string|null]
 *
 * Preview result shape (same + before/after):
 *   ['ok' => true, 'apply' => string, 'domain' => string,
 *    'target' => array, 'before' => array, 'after' => array,
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
            'ok'      => false,
            'apply'   => $name,
            'domain'  => $apply['domain'] ?? 'unknown',
            'target'  => $apply['target'] ?? [],
            'changes' => [],
            'error'   => $validation->get_error_message(),
        ];
    }

    $apply = pp_get_apply($name);
    return call_user_func($apply['apply'], $params);
}

// ── Helper: build result arrays ─────────────────────────────────────────────

function _pp_apply_result(string $name, string $domain, array $target, array $changes): array {
    return [
        'ok'      => true,
        'apply'   => $name,
        'domain'  => $domain,
        'target'  => $target,
        'changes' => $changes,
        'error'   => null,
    ];
}

function _pp_apply_error(string $name, string $domain, array $target, string $error): array {
    return [
        'ok'      => false,
        'apply'   => $name,
        'domain'  => $domain,
        'target'  => $target,
        'changes' => [],
        'error'   => $error,
    ];
}

function _pp_apply_preview(string $name, string $domain, array $target, array $before, array $after, array $changes): array {
    return [
        'ok'      => true,
        'apply'   => $name,
        'domain'  => $domain,
        'target'  => $target,
        'before'  => $before,
        'after'   => $after,
        'changes' => $changes,
        'error'   => null,
    ];
}

// ── Target Discovery ───────────────────────────────────────────────────────

/**
 * Returns the canonical target: site URL, WP root, theme path, environment.
 * Auto-populated from current WordPress state.
 *
 * @return array{site_url: ?string, wp_root: ?string, theme_path: ?string, environment: ?string}
 */
function pp_get_target(): array {
    $site_url = function_exists('get_option') ? get_option('siteurl', null) : null;
    $wp_root = defined('ABSPATH') ? ABSPATH : null;
    $theme_path = function_exists('get_template_directory') ? get_template_directory() : null;

    // Environment label cascade:
    // 1. Explicit WP_ENVIRONMENT_TYPE constant → authoritative (validated by wp_get_environment_type)
    // 2. WP_DEBUG true without explicit env type → 'development' (heuristic)
    // 3. wp_get_environment_type() with no constant → returns 'production' default, accepted
    // 4. None of the above → null
    $environment = null;
    if (defined('WP_ENVIRONMENT_TYPE')) {
        $environment = function_exists('wp_get_environment_type')
            ? wp_get_environment_type()
            : WP_ENVIRONMENT_TYPE;
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        $environment = 'development';
    } elseif (function_exists('wp_get_environment_type')) {
        $environment = wp_get_environment_type();
    }

    return [
        'site_url'    => $site_url ?: null,
        'wp_root'     => $wp_root ?: null,
        'theme_path'  => $theme_path ?: null,
        'environment' => $environment,
    ];
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
 * Validates a unitless CSS number value.
 * Accepts: positive integers or decimals (e.g. 650, 1.6, 0.85).
 * Used for font-weight, line-height, and other unitless numeric tokens.
 */
function _pp_validate_number(string $value): bool {
    return (bool) preg_match('/^\d+(\.\d+)?$/', $value);
}

/**
 * Validates a token value based on its type.
 *
 * @return true|WP_Error
 */
function _pp_validate_token_value(string $value, ?string $type) {
    // Injection check: reject { } ; < > (prevents CSS injection and style-tag breakout)
    if (preg_match('/[{};<>]/', $value)) {
        return new WP_Error('injection', 'Value must not contain {, }, ;, <, or > characters.');
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
        case 'number':
            if (!_pp_validate_number($value)) {
                return new WP_Error('invalid_number', 'Value must be a unitless number (e.g. 650, 1.6).');
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

// ── Apply: update_design_token ──────────────────────────────────────────────
// Domain: design | Target: wp_options pp_token_overrides

pp_register_apply('update_design_token', [
    'domain'      => 'design',
    'target'      => ['type' => 'option', 'key' => 'pp_token_overrides'],
    'description' => 'Updates a single CSS design token override in the database.',
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
            ['type' => 'option', 'key' => 'pp_token_overrides'],
            $before_values,
            $after_values,
            [['token' => $token, 'from' => $tokens[$token]['value'], 'to' => $value]]
        );
    },

    'apply' => function (array $params) {
        $token = $params['token'];
        $value = $params['value'];
        $target = ['type' => 'option', 'key' => 'pp_token_overrides'];

        $tokens = pp_design_tokens();
        $old_value = $tokens[$token]['value'];

        // No-op: postcondition already satisfied
        if ($old_value === $value) {
            return _pp_apply_result('update_design_token', 'design', $target, []);
        }

        // Write override to database (pp_set_token_override handles cache invalidation)
        $result = pp_set_token_override($token, $value);
        if (!$result) {
            return _pp_apply_error('update_design_token', 'design', $target,
                sprintf('Failed to write token override "%s" to database.', $token));
        }

        // Verify: read back from database
        $overrides = pp_get_token_overrides();
        if (!isset($overrides[$token]) || $overrides[$token] !== $value) {
            return _pp_apply_error('update_design_token', 'design', $target,
                sprintf('Verification failed: token "%s" not set to expected value after write.', $token));
        }

        return _pp_apply_result(
            'update_design_token',
            'design',
            $target,
            [['token' => $token, 'from' => $old_value, 'to' => $value]]
        );
    },
]);

// ── Apply: reset_design_token ──────────────────────────────────────────────
// Domain: design | Clears a single token override, reverting to product default

pp_register_apply('reset_design_token', [
    'domain'      => 'design',
    'target'      => ['type' => 'option', 'key' => 'pp_token_overrides'],
    'description' => 'Clears a single design token override, reverting it to the product default.',
    'params'      => [
        'token' => ['type' => 'string', 'required' => true],
    ],

    'validate' => function (array $params) {
        $token = $params['token'];
        $tokens = pp_design_tokens();
        if (!array_key_exists($token, $tokens)) {
            $available = implode(', ', array_keys($tokens));
            return new WP_Error('unknown_token', sprintf('Token "%s" is not a registered design token. Available: %s', $token, $available));
        }
        return true;
    },

    'preview' => function (array $params) {
        $token = $params['token'];
        $tokens = pp_design_tokens();
        $overrides = pp_get_token_overrides();

        if (!isset($overrides[$token])) {
            return _pp_apply_preview(
                'reset_design_token',
                'design',
                ['type' => 'option', 'key' => 'pp_token_overrides'],
                [$token => $tokens[$token]['value']],
                [$token => $tokens[$token]['value']],
                []
            );
        }

        // Read defaults from base.css directly (bypass merge)
        $file = get_template_directory() . '/assets/css/base.css';
        $file_tokens = _pp_read_tokens_from_file($file);
        $default_value = $file_tokens[$token] ?? $tokens[$token]['value'];

        return _pp_apply_preview(
            'reset_design_token',
            'design',
            ['type' => 'option', 'key' => 'pp_token_overrides'],
            [$token => $tokens[$token]['value']],
            [$token => $default_value],
            [['token' => $token, 'from' => $tokens[$token]['value'], 'to' => $default_value]]
        );
    },

    'apply' => function (array $params) {
        $token = $params['token'];
        $target = ['type' => 'option', 'key' => 'pp_token_overrides'];
        $tokens = pp_design_tokens();
        $old_value = $tokens[$token]['value'];

        $cleared = pp_clear_token_override($token);
        if (!$cleared) {
            // Token had no override — no-op
            return _pp_apply_result('reset_design_token', 'design', $target, []);
        }

        // Read the new effective value (product default)
        $new_tokens = pp_design_tokens();
        $new_value = $new_tokens[$token]['value'];

        return _pp_apply_result(
            'reset_design_token',
            'design',
            $target,
            [['token' => $token, 'from' => $old_value, 'to' => $new_value]]
        );
    },
]);

// ── Apply: reset_all_design_tokens ─────────────────────────────────────────
// Domain: design | Clears all token overrides, reverting to product defaults

pp_register_apply('reset_all_design_tokens', [
    'domain'      => 'design',
    'target'      => ['type' => 'option', 'key' => 'pp_token_overrides'],
    'description' => 'Clears all design token overrides, reverting the entire site to product defaults.',
    'params'      => [],

    'validate' => function (array $params) {
        return true;
    },

    'preview' => function (array $params) {
        $tokens = pp_design_tokens();
        $overrides = pp_get_token_overrides();

        $before = [];
        $after = [];
        $file = get_template_directory() . '/assets/css/base.css';
        $file_tokens = _pp_read_tokens_from_file($file);

        foreach ($tokens as $name => $info) {
            $before[$name] = $info['value'];
            $after[$name] = $file_tokens[$name] ?? $info['value'];
        }

        $changes = [];
        foreach ($overrides as $name => $override_value) {
            $default_value = $file_tokens[$name] ?? null;
            if ($default_value !== null) {
                $changes[] = ['token' => $name, 'from' => $override_value, 'to' => $default_value];
            }
        }

        return _pp_apply_preview(
            'reset_all_design_tokens',
            'design',
            ['type' => 'option', 'key' => 'pp_token_overrides'],
            $before,
            $after,
            $changes
        );
    },

    'apply' => function (array $params) {
        $target = ['type' => 'option', 'key' => 'pp_token_overrides'];
        $overrides = pp_get_token_overrides();
        $count = pp_clear_all_token_overrides();

        if ($count === 0) {
            return _pp_apply_result('reset_all_design_tokens', 'design', $target, []);
        }

        $file = get_template_directory() . '/assets/css/base.css';
        $file_tokens = _pp_read_tokens_from_file($file);

        $changes = [];
        foreach ($overrides as $name => $override_value) {
            $default_value = $file_tokens[$name] ?? 'unknown';
            $changes[] = ['token' => $name, 'from' => $override_value, 'to' => $default_value];
        }

        return _pp_apply_result('reset_all_design_tokens', 'design', $target, $changes);
    },
]);

// ── Deployment Manifest (Sync Safeguard) ───────────────────────────────────

/**
 * Returns the path to the deployment manifest file.
 * Stored in wp-content/ (outside theme dir, survives theme sync).
 */
function _pp_deployment_manifest_path(): string {
    if (defined('WP_CONTENT_DIR')) {
        return WP_CONTENT_DIR . '/pp-deployment-manifest.json';
    }
    return dirname(dirname(get_template_directory())) . '/pp-deployment-manifest.json';
}

/**
 * Loads the deployment manifest. Returns null if missing or malformed.
 *
 * @return array|null  ['timestamp' => string, 'theme_path' => string, 'file_hashes' => [relative => md5]]
 */
function _pp_load_deployment_manifest(): ?array {
    $path = _pp_deployment_manifest_path();
    if (!file_exists($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data['file_hashes'])) {
        return null;
    }
    return $data;
}

/**
 * Saves a deployment manifest snapshot.
 *
 * @param string $theme_path  Absolute path to the theme directory.
 * @param array  $file_hashes Map of relative_path => md5 hash.
 * @return bool
 */
function _pp_save_deployment_manifest(string $theme_path, array $file_hashes): bool {
    $manifest = [
        'timestamp'   => date('c'),
        'theme_path'  => $theme_path,
        'file_hashes' => $file_hashes,
    ];
    $result = file_put_contents(
        _pp_deployment_manifest_path(),
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    return $result !== false;
}

/**
 * Hashes all theme files (php, css, js, json) for drift detection.
 * Skips .git/, node_modules/, vendor/, tests/ directories.
 *
 * @param string $theme_path  Absolute path to theme directory.
 * @return array  Map of relative_path => md5 hash.
 */
function _pp_hash_theme_files(string $theme_path): array {
    $hashes = [];
    $extensions = ['php', 'css', 'js', 'json'];
    $skip_dirs = ['.git', 'node_modules', 'vendor', 'tests'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($theme_path, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($current, $key, $iterator) use ($skip_dirs) {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), $skip_dirs, true);
                }
                return true;
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $extensions, true)) {
            continue;
        }
        $relative = ltrim(str_replace($theme_path, '', $file->getPathname()), '/');
        $hashes[$relative] = md5_file($file->getPathname());
    }

    ksort($hashes);
    return $hashes;
}
