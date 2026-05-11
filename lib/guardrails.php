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
        $component = $item['component'] ?? '';
        $has_id    = !empty($item['props']['id']);

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
 * Detects repeated use of constraining props that produce visually weak
 * desktop output: consecutive narrow widths, consecutive compact spacing,
 * and left-aligned heroes without balancing images.
 *
 * @param  array $composition  Composition array (component + props).
 * @return array[]             Each entry: ['type' => string, 'message' => string, 'index' => int]
 */
function pp_validate_composition_smells(array $composition): array {
    $warnings = [];
    $consecutive_narrow = 0;
    $consecutive_compact = 0;

    foreach ($composition as $i => $item) {
        $props = $item['props'] ?? [];

        // Track consecutive width:narrow
        if (($props['width'] ?? 'default') === 'narrow') {
            $consecutive_narrow++;
        } else {
            $consecutive_narrow = 0;
        }
        if ($consecutive_narrow >= 3) {
            $warnings[] = [
                'type' => 'consecutive_narrow',
                'message' => '3+ consecutive components use width:narrow. This creates a memo-like page. Consider using default width.',
                'index' => $i,
            ];
        }

        // Track consecutive spacing:compact
        if (($props['spacing'] ?? 'default') === 'compact') {
            $consecutive_compact++;
        } else {
            $consecutive_compact = 0;
        }
        if ($consecutive_compact >= 3) {
            $warnings[] = [
                'type' => 'consecutive_compact',
                'message' => '3+ consecutive components use spacing:compact. This creates a cramped page rhythm.',
                'index' => $i,
            ];
        }

        // Hero left without image
        $component = $item['component'] ?? '';
        $variant = $props['variant'] ?? 'centered';
        $image_url = $props['image_url'] ?? '';
        if ($component === 'hero' && $variant === 'left' && empty($image_url)) {
            $warnings[] = [
                'type' => 'hero_left_no_image',
                'message' => 'Hero variant "left" without an image creates unbalanced dead space on desktop. Consider "centered" or "split" with an image.',
                'index' => $i,
            ];
        }
    }

    return $warnings;
}
