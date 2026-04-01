<?php
/**
 * templates/front-page.php — Homepage Template
 *
 * Composition-aware: reads _pp_composition post meta and renders components
 * in order, identical to templates/composition.php. The homepage has no
 * hardcoded component structure — it is fully editable through the composition
 * editor (WP Admin → Pages → Home → edit).
 *
 * Fallback behaviour:
 *   post_id > 0, no composition  — blank-page safeguard: seeds the default
 *                                   composition into meta and renders it so a
 *                                   newly created page is never blank.
 *   post_id = 0                  — no static front page is configured; renders
 *                                   a diagnostic state rather than hiding the
 *                                   misconfiguration behind default content.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {
    $composition = pp_composition();
    $post_id     = get_the_ID();

    if (empty($composition)) {
        if (!$post_id) {
            // No static front page is configured. Do not render default content
            // here — that would make a broken setup look healthy. Surface the
            // problem so it is visible and diagnosable.
            if (is_user_logged_in() && current_user_can('manage_options')) {
                echo '<div style="max-width:600px;margin:4rem auto;padding:0 1rem;font-family:sans-serif;">'
                   . '<p><strong>Homepage not configured.</strong> '
                   . 'No static front page is set. '
                   . 'Visit <a href="' . esc_url(admin_url('options-reading.php')) . '">'
                   . 'Settings &rarr; Reading</a> and choose a static page, '
                   . 'or re-activate the theme to auto-create one.</p>'
                   . '</div>';
            }
            return;
        }

        // Real page exists but has no composition data yet (e.g. manually
        // created without content). Seed the defaults and render so the page
        // is never blank and is immediately editable.
        $composition = pp_default_homepage_composition();
        update_post_meta(
            $post_id,
            '_pp_composition',
            wp_slash(wp_json_encode($composition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
    }

    foreach ($composition as $item) {
        if (!isset($item['component'])) {
            continue;
        }
        $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
        pp_get_component((string) $item['component'], $props);
    }
});
