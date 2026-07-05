<?php
/**
 * lib/guardrails.php — PromptingPress Substrate Guardrails
 *
 * Split-authority detection (Custom CSS vs theme CSS) and composition
 * validation helpers. Report-only — these functions surface warnings,
 * they never block or auto-fix.
 */

/**
 * CSS class prefixes emitted by PP components.
 * Used for word-boundary matching against Custom CSS selectors.
 *
 * @return string[] Root class names for each component.
 */
function pp_component_classes(): array {
    return [
        'hero',
        'section',
        'cta',
        'grid',
        'faq',
        'table-section',
        'stats',
        'logos',
        'embed',
        'nav',
        'site-header',
        'site-footer',
    ];
}

/**
 * Checks WordPress Custom CSS for selectors that target PP component classes.
 *
 * Minimal selector extractor: splits CSS on `{`, extracts the selector part,
 * then word-boundary matches against known PP component classes. No full CSS
 * parser — this is intentional. Report-only.
 *
 * @return array[] Each entry: ['selector' => string, 'component' => string]
 */
function pp_check_custom_css_conflicts(): array {
    $css = wp_get_custom_css();
    if (!$css || !trim($css)) {
        return [];
    }

    // Strip CSS comments.
    $css = preg_replace('/\/\*.*?\*\//s', '', $css);

    // Split on { to get selector blocks.
    $parts     = explode('{', $css);
    $selectors = [];
    foreach ($parts as $i => $part) {
        if ($i === count($parts) - 1) {
            break; // Last chunk is after the final {, skip.
        }
        // The selector is everything after the last } in this chunk.
        $after_brace = strrpos($part, '}');
        $selector    = $after_brace !== false ? substr($part, $after_brace + 1) : $part;
        $selector    = trim($selector);
        if ($selector !== '') {
            $selectors[] = $selector;
        }
    }

    $classes   = pp_component_classes();
    $conflicts = [];

    foreach ($selectors as $selector) {
        foreach ($classes as $class) {
            // Match .classname or classname at a boundary.
            // Must NOT match .hero-banner when looking for .hero.
            // CSS identifiers can contain [a-zA-Z0-9_-], so we check that the
            // match is not followed or preceded by those characters.
            $pattern = '/(?<![a-zA-Z0-9_-])' . preg_quote($class, '/') . '(?![a-zA-Z0-9_-])/';
            if (preg_match($pattern, $selector)) {
                $conflicts[] = [
                    'selector'  => $selector,
                    'component' => $class,
                ];
                break; // One match per selector is enough.
            }
        }
    }

    return $conflicts;
}

/**
 * Renders a dismissible admin notice when Custom CSS conflicts with PP components.
 * Scoped to composition page edit screens only.
 *
 * Hooked via add_action('admin_notices', ...) in functions.php.
 */
function pp_admin_notice_css_conflicts(): void {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'page') {
        return;
    }

    // Only show on composition pages.
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if (!$post_id) {
        return;
    }
    $template = get_page_template_slug($post_id);
    if ($template !== 'composition.php') {
        return;
    }

    $conflicts = pp_check_custom_css_conflicts();
    if (empty($conflicts)) {
        return;
    }

    $selectors = array_map(fn($c) => '<code>' . esc_html($c['selector']) . '</code>', $conflicts);
    $list = implode(', ', $selectors);

    echo '<div class="notice notice-warning is-dismissible">';
    echo '<p><strong>PromptingPress:</strong> Custom CSS conflicts detected. ';
    echo 'The following selectors target PP component classes: ' . $list . '. ';
    echo 'This may override theme styling. ';
    echo 'Run <code>wp pp check conflicts</code> for details.</p>';
    echo '</div>';
}

/**
 * Classifies a path as safe, extension, or core.
 *
 * - safe: database operations (composition data, token overrides, font URLs)
 * - extension: components/, templates/, assets/css/ (theme extension files)
 * - core: lib/, functions.php, style.css, AI_RULES.md (theme internals)
 *
 * Paths are normalized relative to the theme root. Absolute paths under the
 * theme directory are converted to relative paths automatically.
 *
 * @param  string $path  File path (relative to theme root, or absolute).
 * @return array{classification: string, guidance: string}
 */
function pp_classify_surface(string $path): array {
    // Normalize: strip theme directory prefix if absolute.
    $theme_dir = get_template_directory();
    if (str_starts_with($path, $theme_dir)) {
        $path = ltrim(substr($path, strlen($theme_dir)), '/');
    }

    // Normalize leading slashes and resolve . / ..
    $path = ltrim($path, '/');

    // Remove any ../ traversal attempts for safety.
    $path = str_replace('../', '', $path);

    // Database-backed surfaces are "safe" — but they don't have file paths.
    // This function classifies file paths, so "safe" means extension files
    // that are explicitly designed for customization.

    // Core files: lib/*, functions.php, style.css, AI_RULES.md, AI_CONTEXT.md
    $core_patterns = [
        '#^lib/#',
        '#^functions\.php$#',
        '#^style\.css$#',
        '#^AI_RULES\.md$#',
        '#^AI_CONTEXT\.md$#',
        '#^phpunit\.xml$#',
        '#^composer\.json$#',
        '#^package\.json$#',
    ];

    foreach ($core_patterns as $pattern) {
        if (preg_match($pattern, $path)) {
            return [
                'classification' => 'core',
                'guidance'       => _pp_surface_guidance($path),
            ];
        }
    }

    // Extension files: components/*, templates/*, assets/*
    $extension_patterns = [
        '#^components/#',
        '#^templates/#',
        '#^assets/#',
    ];

    foreach ($extension_patterns as $pattern) {
        if (preg_match($pattern, $path)) {
            return [
                'classification' => 'extension',
                'guidance'       => 'Extension file. Editable but may be overwritten by theme updates. Prefer database-backed surfaces (style_component, update_design_token) when possible.',
            ];
        }
    }

    // Everything else defaults to core (conservative).
    return [
        'classification' => 'core',
        'guidance'       => _pp_surface_guidance($path),
    ];
}

/**
 * Returns routing guidance for a core file, pointing toward the correct approved surface.
 *
 * @param  string $path  Relative path within the theme.
 * @return string        Human-readable guidance message.
 */
function _pp_surface_guidance(string $path): string {
    // Route toward specific approved surfaces based on what the file controls.
    if (str_starts_with($path, 'lib/')) {
        return "Blocked: {$path} is a core theme file. To change spacing/colors, use style_component action on the target component instance. To change the color palette, use update_design_token apply.";
    }
    if ($path === 'functions.php') {
        return "Blocked: functions.php is a core theme file. To add fonts, use enqueue_font apply. To change tokens, use update_design_token apply.";
    }
    if ($path === 'style.css') {
        return "Blocked: style.css contains theme metadata only. To change design tokens, use update_design_token apply.";
    }

    return "Blocked: {$path} is a core theme file. Use approved database-backed surfaces (actions, applies) instead of direct file edits.";
}

/**
 * Validates composition styling for ambiguous targeting.
 *
 * Flags duplicate component types that lack stable IDs — these cannot be
 * targeted individually by CSS and are prone to nth-of-type fragility.
 *
 * @param  array $composition  Composition array (component + props).
 * @return array[]             Each entry: ['component' => string, 'indices' => int[]]
 */
function pp_validate_composition_styling(array $composition): array {
    $type_map = [];

    foreach ($composition as $i => $item) {
        // Defensive: a malformed composition that bypassed validation may
        // carry non-array items or props, so guard both before indexing.
        if (!is_array($item)) {
            continue;
        }
        $component = $item['component'] ?? '';
        $props     = is_array($item['props'] ?? null) ? $item['props'] : [];
        $has_id    = !empty($props['id']);

        if (!$has_id) {
            $type_map[$component][] = $i;
        }
    }

    $warnings = [];
    foreach ($type_map as $component => $indices) {
        if (count($indices) > 1) {
            $warnings[] = [
                'component' => $component,
                'indices'   => $indices,
            ];
        }
    }

    return $warnings;
}

/**
 * Validates composition for layout smell patterns.
 *
 * Detects composition patterns that produce visually weak desktop output:
 * left-aligned heroes without balancing images, runs of text-only sections,
 * and runs of narrow-width or compact-spacing components (issue 51) that
 * over-constrain page rhythm even though each component is individually valid.
 *
 * @param  array $composition  Composition array (component + props).
 * @return array[]             Each entry: ['type' => string, 'message' => string, 'index' => int]
 */
function pp_validate_composition_smells(array $composition): array {
    $warnings = [];

    $consecutive_text_only = 0;
    $consecutive_narrow_width = 0;
    $consecutive_compact_spacing = 0;

    foreach ($composition as $i => $item) {
        // Defensive: a malformed composition that bypassed validation may
        // carry non-array items or props, so guard both before indexing.
        if (!is_array($item)) {
            continue;
        }
        $props = is_array($item['props'] ?? null) ? $item['props'] : [];
        $component = $item['component'] ?? '';
        $variant = $props['variant'] ?? 'centered';
        $image_url = $props['image_url'] ?? '';

        // Hero left without image
        if ($component === 'hero' && $variant === 'left' && empty($image_url)) {
            $warnings[] = [
                'type' => 'hero_left_no_image',
                'message' => 'Hero variant "left" without an image creates unbalanced dead space on desktop. Consider "centered" or "split" with an image.',
                'index' => $i,
            ];
        }

        // Track consecutive text-only sections (no image, no visual anchor)
        $layout = $props['layout'] ?? 'text-only';
        if ($component === 'section' && in_array($layout, ['text-only', 'centered'], true) && empty($image_url) && empty($props['background_image'] ?? '')) {
            $consecutive_text_only++;
        } else {
            $consecutive_text_only = 0;
        }

        if ($consecutive_text_only >= 3) {
            $warnings[] = [
                'type' => 'consecutive_text_sections',
                'message' => '3+ consecutive text-only sections without images or background variety. Consider adding an image layout, grid, or stats component to break the wall-of-text pattern.',
                'index' => $i,
            ];
            $consecutive_text_only = 0; // Reset to avoid repeated warnings.
        }

        // Track consecutive narrow-width components (page rhythm over-constrained)
        if (($props['width'] ?? 'default') === 'narrow') {
            $consecutive_narrow_width++;
        } else {
            $consecutive_narrow_width = 0;
        }

        if ($consecutive_narrow_width >= 3) {
            $warnings[] = [
                'type' => 'consecutive_narrow_width',
                'message' => '3+ consecutive components using width "narrow". Repeated width constraints often over-narrow the page rather than fixing the underlying presentation issue.',
                'index' => $i,
            ];
            $consecutive_narrow_width = 0; // Reset to avoid repeated warnings.
        }

        // Track consecutive compact-spacing components (page rhythm over-constrained)
        if (($props['spacing'] ?? 'default') === 'compact') {
            $consecutive_compact_spacing++;
        } else {
            $consecutive_compact_spacing = 0;
        }

        if ($consecutive_compact_spacing >= 3) {
            $warnings[] = [
                'type' => 'consecutive_compact_spacing',
                'message' => '3+ consecutive components using spacing "compact". Repeated compact spacing tends to cramp the page rather than improving it.',
                'index' => $i,
            ];
            $consecutive_compact_spacing = 0; // Reset to avoid repeated warnings.
        }
    }

    return $warnings;
}

// ── Theme Integrity ──────────────────────────────────────────────────────

/**
 * Checks shipped theme files against the integrity manifest.
 *
 * Loads integrity-manifest.json from the theme root, validates its JSON
 * and schema, hashes all current theme files, and compares. Stores the
 * result in the 'pp_theme_integrity' option.
 *
 * @return array|null  Result array with status/modified/missing/extra, or
 *                     null if no manifest exists (pre-integrity theme).
 */
function pp_check_theme_integrity(): ?array {
    $theme_path = get_template_directory();
    $manifest_path = $theme_path . '/integrity-manifest.json';

    // No manifest = pre-integrity theme version. No opinion.
    if (!file_exists($manifest_path)) {
        return null;
    }

    $now = gmdate('c');

    // Read and validate JSON.
    $raw = file_get_contents($manifest_path);
    $manifest = json_decode($raw, true);

    if (!is_array($manifest)) {
        $result = [
            'status'     => 'invalid_manifest',
            'checked_at' => $now,
            'version'    => PP_VERSION,
            'modified'   => [],
            'missing'    => [],
            'extra'      => [],
            'error'      => 'Invalid JSON: ' . (json_last_error_msg() ?: 'decode returned null'),
        ];
        update_option('pp_theme_integrity', $result, true);
        return $result;
    }

    // Schema validation: require version (string) and file_hashes (non-empty object).
    if (!isset($manifest['version']) || !is_string($manifest['version'])) {
        $result = [
            'status'     => 'invalid_manifest',
            'checked_at' => $now,
            'version'    => PP_VERSION,
            'modified'   => [],
            'missing'    => [],
            'extra'      => [],
            'error'      => 'Missing or invalid required key: version',
        ];
        update_option('pp_theme_integrity', $result, true);
        return $result;
    }

    if (!isset($manifest['file_hashes']) || !is_array($manifest['file_hashes']) || empty($manifest['file_hashes'])) {
        $result = [
            'status'     => 'invalid_manifest',
            'checked_at' => $now,
            'version'    => PP_VERSION,
            'modified'   => [],
            'missing'    => [],
            'extra'      => [],
            'error'      => 'Missing or empty required key: file_hashes',
        ];
        update_option('pp_theme_integrity', $result, true);
        return $result;
    }

    $manifest_hashes = $manifest['file_hashes'];

    // Hash current theme files.
    $current_hashes = _pp_hash_all_theme_files($theme_path);

    // Compare.
    $modified = [];
    $missing  = [];
    $extra    = [];

    foreach ($manifest_hashes as $path => $expected_hash) {
        if (!isset($current_hashes[$path])) {
            $missing[] = $path;
        } elseif ($current_hashes[$path] !== $expected_hash) {
            $modified[] = $path;
        }
    }

    foreach ($current_hashes as $path => $hash) {
        if (!isset($manifest_hashes[$path])) {
            $extra[] = $path;
        }
    }

    $status = (empty($modified) && empty($missing) && empty($extra)) ? 'safe' : 'unsafe';

    $result = [
        'status'     => $status,
        'checked_at' => $now,
        'version'    => $manifest['version'],
        'modified'   => $modified,
        'missing'    => $missing,
        'extra'      => $extra,
        'error'      => null,
    ];

    update_option('pp_theme_integrity', $result, true);

    // Drift is gone — clear any stale "last update blocked" record so the admin
    // notice self-heals (via the daily cron, activation, post-update, or CLI)
    // once the files are restored. Only the unsafe state is worth surfacing.
    if ($status === 'safe') {
        delete_option('pp_last_blocked_update');
    }

    return $result;
}

/**
 * Renders a persistent admin notice when theme files have drifted from the
 * shipped baseline, or when the integrity manifest is invalid.
 *
 * Reads the stored option (no file I/O). Clears stale results when the
 * theme version has changed (the old status no longer applies).
 *
 * Hooked via add_action('admin_notices', ...) in functions.php.
 */
function pp_admin_notice_theme_integrity(): void {
    $option = get_option('pp_theme_integrity');
    if (!is_array($option) || empty($option['status'])) {
        return;
    }

    // Version mismatch = theme was updated. Old status is stale — clear it.
    if (($option['version'] ?? '') !== PP_VERSION) {
        delete_option('pp_theme_integrity');
        return;
    }

    $status = $option['status'];

    if ($status === 'unsafe') {
        $modified_count = count($option['modified'] ?? []);
        $missing_count  = count($option['missing'] ?? []);
        $extra_count    = count($option['extra'] ?? []);

        $parts = [];
        if ($modified_count) {
            $parts[] = $modified_count . ' modified';
        }
        if ($missing_count) {
            $parts[] = $missing_count . ' missing';
        }
        if ($extra_count) {
            $parts[] = $extra_count . ' extra';
        }

        echo '<div class="notice notice-error">';
        echo '<p><strong>PromptingPress:</strong> Theme files have been modified locally';
        if ($parts) {
            echo ' (' . implode(', ', $parts) . ')';
        }
        echo '. Updating the theme will overwrite these changes. ';
        echo 'Run <code>wp pp integrity check</code> for details.</p>';
        echo '</div>';
        return;
    }

    if ($status === 'invalid_manifest') {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>PromptingPress:</strong> Cannot verify theme file integrity &mdash; ';
        echo 'the shipped integrity manifest is invalid or unreadable (version ' . esc_html(PP_VERSION) . '). ';
        echo 'Restore <code>integrity-manifest.json</code> from the matching GitHub release, ';
        echo 'then run <code>wp pp integrity check</code>.</p>';
        echo '</div>';
        return;
    }
}

/**
 * Renders a persistent admin notice when a theme update was blocked because
 * local files had drifted from the shipped baseline.
 *
 * This is a separate notice from pp_admin_notice_theme_integrity() on purpose:
 * a blocked SILENT auto-update never surfaces a live WP_Error to a human, so
 * this is the only place the site owner learns when, why, and which files
 * caused the block. Reads the stored option (no file I/O).
 *
 * Hooked via add_action('admin_notices', ...) in functions.php.
 */
function pp_admin_notice_last_blocked_update(): void {
    $blocked = get_option('pp_last_blocked_update');
    if (!is_array($blocked) || empty($blocked['timestamp'])) {
        return;
    }

    if (($blocked['status'] ?? '') === 'invalid_manifest') {
        $reason = 'theme file integrity could not be verified';
    } else {
        $parts = [];
        if (!empty($blocked['modified'])) { $parts[] = count($blocked['modified']) . ' modified'; }
        if (!empty($blocked['missing']))  { $parts[] = count($blocked['missing'])  . ' missing'; }
        if (!empty($blocked['extra']))    { $parts[] = count($blocked['extra'])    . ' extra'; }
        $reason = 'local theme files had changed'
            . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
    }

    echo '<div class="notice notice-error">';
    echo '<p><strong>PromptingPress:</strong> A theme update was blocked on ';
    echo esc_html($blocked['timestamp']) . ' because ' . esc_html($reason) . '. ';
    echo 'Updating would have overwritten or deleted those changes. ';
    echo 'Run <code>wp pp integrity check</code> for the file list.</p>';
    echo '</div>';
}
