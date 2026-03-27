<?php
/**
 * templates/composition.php — Composition-Aware Page Template
 *
 * Reads _pp_composition post meta (via pp_composition()) and renders each
 * registered component in order. Falls back to an empty page (no components)
 * when meta is absent or empty.
 *
 * Skips unregistered components with a debug warning (via pp_get_component).
 * Malformed JSON or non-array values are treated as empty composition.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {
    foreach (pp_composition() as $item) {
        if (!isset($item['component'])) {
            continue;
        }
        $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
        pp_get_component((string) $item['component'], $props);
    }
});
