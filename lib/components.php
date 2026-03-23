<?php
/**
 * lib/components.php — PromptingPress Component Loader
 *
 * DO NOT MODIFY this file. It is the core auto-loader contract.
 * Components are loaded by convention: /components/{name}/{name}.php
 * No explicit registration required.
 */

/**
 * Renders a component by name with the given props.
 *
 * Props are validated against schema.json in WP_DEBUG mode (non-fatal warning).
 * Scope is isolated via an anonymous static closure — props do NOT leak between calls.
 *
 * @param string $name   Component folder/file name (e.g. 'hero', 'grid').
 * @param array  $props  Associative array of props passed to the component.
 */
function pp_get_component(string $name, array $props = []): void {
    // Sanitize component name: only lowercase letters, digits, hyphens, underscores.
    // Prevents path traversal (e.g. '../../wp-config') from reaching require.
    $name = preg_replace('/[^a-z0-9_-]/i', '', $name);

    if ($name === '') {
        return;
    }

    $file = get_template_directory() . "/components/{$name}/{$name}.php";

    if (!file_exists($file)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            trigger_error(
                "PromptingPress: component '{$name}' not found at {$file}",
                E_USER_WARNING
            );
        }
        return;
    }

    // Validate required props against schema.json in debug mode.
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $schema_file = get_template_directory() . "/components/{$name}/schema.json";
        if (file_exists($schema_file)) {
            $schema = json_decode(file_get_contents($schema_file), true);
            if ($schema && isset($schema['props'])) {
                foreach ($schema['props'] as $prop_name => $prop_def) {
                    if (!empty($prop_def['required']) && !isset($props[$prop_name])) {
                        trigger_error(
                            "PromptingPress: component '{$name}' missing required prop '{$prop_name}'",
                            E_USER_WARNING
                        );
                    }
                }
            }
        }
    }

    // Isolated scope — $props does NOT leak between component calls.
    // The static closure captures nothing from the outer scope by reference.
    (static function (string $file, array $props): void {
        require $file;
    })($file, $props);
}

/**
 * Checks whether a component exists (has a .php file) without rendering it.
 *
 * @param string $name  Component name.
 */
function pp_component_exists(string $name): bool {
    $name = preg_replace('/[^a-z0-9_-]/i', '', $name);
    if ($name === '') {
        return false;
    }
    $file = get_template_directory() . "/components/{$name}/{$name}.php";
    return file_exists($file);
}
