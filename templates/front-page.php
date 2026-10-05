<?php
/**
 * templates/front-page.php — Homepage Template
 *
 * Composition-aware: reads _pp_composition post meta and renders components
 * in order, identical to templates/composition.php. The homepage has no
 * hardcoded component structure — it is fully editable through the composition
 * editor (WP Admin → Pages → Home → edit).
 *
 * Fallback behaviour (classification owned by pp_resolve_front_page_render(), #506):
 *   render    — a present (or freshly-seeded) composition; render it in order.
 *   no_front  — nothing is queried (post_id = 0). The root front-page.php sends a
 *               front page with no static page configured to templates/home.php, so
 *               this arm answers only a direct include of this template; it surfaces
 *               the misconfiguration to admins rather than hiding it behind defaults.
 *   corrupt   — stored meta is present but undecodable/wrong-shape. Render a
 *               NON-DESTRUCTIVE fallback: the stored bytes are left byte-identical
 *               for recovery, `inspect` keeps reporting the exact error, and the
 *               blank-page safeguard NEVER overwrites a corrupt composition.
 *
 * The genuinely-absent case (post exists, no meta) is the only one that seeds the
 * default composition — and that seed goes through the versioned writer, not a raw
 * meta write. See pp_resolve_front_page_render() in lib/wp.php.
 *
 * THE STATIC FRONT PAGE ONLY (#1173). The root front-page.php sends a "Your latest
 * posts" front page to templates/home.php. This template resolves the QUERIED page, the
 * reading the head's emitter makes too, never the current post (on a latest-posts front
 * page that was the newest blog post, which got the default homepage written onto it on
 * a visitor's GET). Only the page Settings -> Reading names is ever seeded.
 *
 * THE PASSWORD GATE COMES FIRST (#1173). A front page that still needs its password
 * renders its title and core's form, and nothing is resolved: resolving may seed an
 * absent composition, and a visitor without the password must cause no write. Same
 * predicate as the head (pp_composition_locked_page()), so head and body agree.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {
    $locked = pp_composition_locked_page();
    if ($locked !== null) {
        pp_render_locked_page($locked);
        return;
    }

    $render = pp_resolve_front_page_render((int) get_queried_object_id());

    // Admin-only diagnostic notice, mirroring the historical no-front-page pattern:
    // anonymous visitors never see chrome about a misconfiguration; operators get an
    // actionable pointer. Shared wrapper so both diagnostic states stay identical.
    $notice = function (string $inner): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }
        echo '<div style="max-width:600px;margin:4rem auto;padding:0 1rem;font-family:sans-serif;">'
           . $inner
           . '</div>';
    };

    switch ($render['mode']) {
        case 'no_front':
            // No static front page is configured. Do not render default content
            // here — that would make a broken setup look healthy.
            $notice(
                '<p><strong>Homepage not configured.</strong> '
                . 'No static front page is set. '
                . 'Visit <a href="' . esc_url(admin_url('options-reading.php')) . '">'
                . 'Settings &rarr; Reading</a> and choose a static page, '
                . 'or re-activate the theme to auto-create one.</p>'
            );
            return;

        case 'corrupt':
            // The stored composition is present but undecodable / wrong-shape. It was
            // left UNTOUCHED (#506) so it can be recovered — a render must never
            // "heal" corruption by overwriting it with defaults.
            $notice(
                '<p><strong>Homepage composition could not be read.</strong> '
                . 'The stored composition data is corrupted. It has been left '
                . 'untouched so it can be recovered — nothing was overwritten. '
                . 'Run <code>wp pp operate inspect</code> to see the exact error, '
                . 'then edit this page in the composition editor, or restore a prior '
                . 'version from its history if one is available.</p>'
            );
            return;

        case 'render':
        default:
            // The band loop lives in ONE place since #1181 (D1): pp_render_composition_bands().
            pp_render_composition_bands($render['composition']);
    }
});
