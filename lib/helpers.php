<?php
/**
 * lib/helpers.php — PromptingPress Utility Functions
 *
 * Small helpers used across templates and components.
 * All functions prefixed pp_ to avoid collisions.
 */

/**
 * Echoes a value with esc_attr() applied.
 *
 * @param mixed $value  Value to escape and echo.
 */
function pp_esc_attr_e($value): void {
    echo esc_attr($value);
}

/**
 * Joins an array of CSS class names into a single space-separated string.
 * Empty strings, null values, and false values are filtered out.
 *
 * @param array $classes  Array of class name strings.
 * @return string
 */
function pp_classes(array $classes): string {
    return implode(' ', array_filter($classes, function ($class) {
        return !empty($class);
    }));
}
