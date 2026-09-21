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
        // THE `items[].style` -> `__pp_style` PROMOTION STOOD HERE AND WENT AT #1101.
        // It lifted a band's stored v1 slot map into the props array so the component
        // template could render it. MEASURED DEAD before deleting: `__pp_style` has zero
        // READ sites in the tree — every component render file carries only a comment
        // where the read used to be — so this wrote a key nothing consumed. Removing it
        // from all four band loops changed no rendered byte and broke exactly one test,
        // a SOURCE SCAN asserting the promotion existed.
        //
        // An aged band's stored `style` map is not lost by this: it is still in the
        // composition, still refuses every edit to its band until cleared, and is still
        // named in the refusal (_pp_validate_style_slot_map, lib/admin.php).
        $props = pp_udc_promote_band_identity($item, $props);
        pp_get_component((string) $item['component'], $props);
    }
});
