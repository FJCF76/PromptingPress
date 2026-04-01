<?php
/**
 * lib/setup.php — PromptingPress Theme Bootstrap
 *
 * Ensures required site state exists on theme activation.
 * This is not admin UI logic (lib/admin.php) and not a WP wrapper (lib/wp.php).
 * It answers: "is this WordPress install in a valid state to run this theme?"
 *
 * Current responsibilities:
 * - pp_setup_homepage()  create static front page on fresh installs
 */

// ── Homepage Provisioning ─────────────────────────────────────────────────────

/**
 * Ensures a composition-backed static front page exists.
 *
 * Fires on after_switch_theme. Idempotent: skips if a valid published page is
 * already configured as the static front page. Safe to re-activate the theme.
 *
 * Creates a page titled "Home", assigns composition.php as the page template,
 * seeds _pp_composition from pp_default_homepage_composition(), and sets
 * show_on_front = page in Reading Settings.
 */
function pp_setup_homepage(): void {
    // Idempotent guard: a valid static front page already exists, nothing to do.
    if (get_option('show_on_front') === 'page') {
        $existing = (int) get_option('page_on_front');
        if ($existing &&
            get_post_type($existing) === 'page' &&
            get_post_status($existing) === 'publish') {
            return;
        }
    }

    $post_id = wp_insert_post([
        'post_type'   => 'page',
        'post_title'  => 'Home',
        'post_name'   => 'home',
        'post_status' => 'publish',
    ]);

    if (!$post_id || is_wp_error($post_id)) {
        return;
    }

    // Assign template explicitly — does not depend on save_post_page hook
    // ordering during this synthetic insert.
    update_post_meta($post_id, '_wp_page_template', 'composition.php');

    // Seed composition at creation time, not at first render.
    update_post_meta(
        $post_id,
        '_pp_composition',
        wp_slash(wp_json_encode(
            pp_default_homepage_composition(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ))
    );

    update_option('show_on_front', 'page');
    update_option('page_on_front', $post_id);
}

add_action('after_switch_theme', 'pp_setup_homepage');
