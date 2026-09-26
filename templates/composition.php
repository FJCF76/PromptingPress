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
    // The band loop lives in ONE place since #1181 (D1): pp_render_composition_bands(),
    // shared with front-page.php and home.php (the posts page), so a band renders the
    // same way on every route.
    pp_render_composition_bands(pp_composition());
});
