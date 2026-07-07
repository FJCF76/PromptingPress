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

    // Seed composition at creation time, not at first render. Route through
    // pp_update_composition() (the single composition writer) so the seed gets stable
    // ids and initializes the #113 freshness marker (version 1), instead of a direct
    // meta write that would leave the marker absent. Best-effort: a lock failure at
    // theme activation is effectively impossible (no concurrent writer exists yet), and
    // if it somehow fails the render path is defensive about an empty composition.
    pp_update_composition($post_id, pp_default_homepage_composition());

    update_option('show_on_front', 'page');
    update_option('page_on_front', $post_id);
}

add_action('after_switch_theme', 'pp_setup_homepage');

// ── Theme Integrity Lifecycle ────────────────────────────────────────────────

const PP_INTEGRITY_CRON_HOOK = 'pp_daily_integrity_check';

/**
 * True when $hook_extra describes an upgrader operation that targets THIS
 * active theme (PromptingPress as the active stylesheet).
 *
 * Matches on the theme SLUG, never on $hook_extra['type']. WordPress passes
 * different hook_extra shapes per update path:
 *   - single update  (Theme_Upgrader::upgrade):       ['theme'=>slug, 'type'=>'theme', 'action'=>'update']
 *   - bulk update    (Theme_Upgrader::bulk_upgrade):  ['theme'=>slug]   ← no 'type'/'action'
 *   - auto-update    (WP_Automatic_Updater→upgrade):  single shape (has 'type')
 *   - process_complete bulk:                          ['themes'=>[slug,...], 'type'=>'theme', 'action'=>'update']
 * Gating on type==='theme' would silently skip every bulk update. Only theme
 * operations carry a 'theme'/'themes' slug key (plugins use 'plugin'/'plugins',
 * core carries neither), so a pure slug match is both sufficient and safe.
 *
 * @param mixed $hook_extra  The upgrader hook_extra array (any non-array → false).
 */
function _pp_is_active_theme_update($hook_extra): bool {
    if (!is_array($hook_extra)) {
        return false;
    }

    $active = get_option('stylesheet');
    if (!$active) {
        return false;
    }

    $slugs = [];
    if (!empty($hook_extra['theme']) && is_string($hook_extra['theme'])) {
        $slugs[] = $hook_extra['theme'];
    }
    if (!empty($hook_extra['themes']) && is_array($hook_extra['themes'])) {
        $slugs = array_merge($slugs, $hook_extra['themes']);
    }

    return in_array($active, $slugs, true);
}

/**
 * Schedule the daily integrity check. Idempotent: safe to call on every
 * activation and admin load — only schedules if not already scheduled.
 */
function pp_schedule_integrity_cron(): void {
    if (!wp_next_scheduled(PP_INTEGRITY_CRON_HOOK)) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', PP_INTEGRITY_CRON_HOOK);
    }
}

/**
 * Unschedule the daily integrity check. Named (not an inline closure) so the
 * clear-on-switch path is unit-testable.
 */
function pp_unschedule_integrity_cron(): void {
    wp_clear_scheduled_hook(PP_INTEGRITY_CRON_HOOK);
}

/**
 * Tear down all integrity state when switching away from this theme: clears
 * the stored result, the last-blocked record, and the daily cron event.
 */
function pp_teardown_theme_integrity(): void {
    delete_option('pp_theme_integrity');
    delete_option('pp_last_blocked_update');
    pp_unschedule_integrity_cron();
}

/**
 * The daily cron runner. Refreshes the stored integrity status so the admin
 * notice reflects current drift without an activation, update, or CLI call.
 */
add_action(PP_INTEGRITY_CRON_HOOK, 'pp_check_theme_integrity');

/**
 * On activation: run an integrity check and schedule the daily cron.
 * Fires after pp_setup_homepage (priority 20 < this is fine; homepage is 10).
 */
add_action('after_switch_theme', function () {
    pp_check_theme_integrity();
    pp_schedule_integrity_cron();
}, 20);

/**
 * Run an integrity check after a theme update via the WP upgrader.
 * Clears stale results first, then checks against the new manifest.
 */
add_action('upgrader_process_complete', function ($upgrader, $hook_extra) {
    if (!_pp_is_active_theme_update($hook_extra)) {
        return;
    }

    delete_option('pp_theme_integrity');
    pp_check_theme_integrity();
}, 10, 2);

/**
 * Block a theme update/install that would overwrite or delete confirmed local
 * drift in the active PromptingPress theme.
 *
 *   WP upgrader (manual / bulk / auto-update)
 *        │  apply_filters('upgrader_pre_install', $response, $hook_extra)
 *        ▼
 *   $response already WP_Error? ──► return it (preserve prior failure)
 *        │ no
 *   active PromptingPress theme update? ──no──► return $response
 *        │ yes
 *   fresh pp_check_theme_integrity()
 *        ├── safe                       → return $response (allow)
 *        ├── null                       → return $response (no manifest, nothing to verify)
 *        └── invalid_manifest / unsafe  → pp_allow_unsafe_theme_update filter?
 *                                            true  → return $response (bypass)
 *                                            false → WP_Error (block; record last-blocked)
 *              (invalid_manifest = can't verify / blind spot; unsafe = confirmed drift)
 *
 * Returning a WP_Error from this filter aborts the install before any file is
 * written. The pp_allow_unsafe_theme_update filter is the deliberate override.
 *
 * @param mixed $response    Upgrader response (WP_Error short-circuits install).
 * @param array $hook_extra  Upgrader hook_extra.
 * @return mixed             $response to proceed, or WP_Error to block.
 */
function pp_block_unsafe_theme_update($response, $hook_extra) {
    // Preserve an upstream failure — never mask another error.
    if (is_wp_error($response)) {
        return $response;
    }

    if (!_pp_is_active_theme_update($hook_extra)) {
        return $response;
    }

    $result = pp_check_theme_integrity();

    // No manifest (pre-integrity theme) — nothing to compare, allow the update.
    if ($result === null) {
        return $response;
    }

    $status = $result['status'] ?? '';

    // Files match the shipped baseline — allow.
    if ($status === 'safe') {
        return $response;
    }

    // Blocking states: 'invalid_manifest' (can't verify = blind spot) and
    // 'unsafe' (confirmed drift). The pp_allow_unsafe_theme_update filter is the
    // single, deliberate escape hatch and applies UNIFORMLY to both — otherwise a
    // corrupt manifest would hard-block updates with no documented way out (a
    // permanent brick), even though the error message advertises the override.
    if ($status === 'invalid_manifest' || $status === 'unsafe') {
        $allow = apply_filters('pp_allow_unsafe_theme_update', false, $result, $hook_extra);
        if ($allow) {
            return $response;
        }

        _pp_record_blocked_update($result, $hook_extra);

        if ($status === 'invalid_manifest') {
            return new WP_Error(
                'pp_integrity_unverifiable',
                'PromptingPress update blocked: theme file integrity cannot be verified '
                . '(the shipped integrity manifest is invalid or unreadable). Updating now '
                . 'could overwrite local changes without warning. Restore '
                . 'integrity-manifest.json from the matching GitHub release, then retry. '
                . 'To override, add a pp_allow_unsafe_theme_update filter returning true.'
            );
        }

        $parts = [];
        if (!empty($result['modified'])) { $parts[] = count($result['modified']) . ' modified'; }
        if (!empty($result['missing']))  { $parts[] = count($result['missing'])  . ' missing'; }
        if (!empty($result['extra']))    { $parts[] = count($result['extra'])    . ' extra'; }
        $summary = $parts ? ' (' . implode(', ', $parts) . ')' : '';

        return new WP_Error(
            'pp_integrity_unsafe',
            'PromptingPress update blocked: local theme files have changed' . $summary
            . '. A theme update replaces the entire theme directory and would '
            . 'overwrite or delete these changes. Run `wp pp integrity check` for the '
            . 'file list. To keep the changes, move them into design tokens '
            . '(update_design_token), a composition, or content before updating. '
            . 'To override and update anyway, add a pp_allow_unsafe_theme_update '
            . 'filter returning true.'
        );
    }

    // Any other (unknown) status: do not block.
    return $response;
}
add_filter('upgrader_pre_install', 'pp_block_unsafe_theme_update', 10, 2);

/**
 * Persist a record of the most recent blocked update so the admin notice can
 * surface it later — the only visibility path when a silent auto-update is
 * blocked (no live WP_Error reaches a human in that flow).
 */
function _pp_record_blocked_update(array $result, $hook_extra): void {
    update_option('pp_last_blocked_update', [
        'timestamp' => gmdate('c'),
        'status'    => $result['status'] ?? 'unsafe',
        'modified'  => $result['modified'] ?? [],
        'missing'   => $result['missing'] ?? [],
        'extra'     => $result['extra'] ?? [],
        'trigger'   => (is_array($hook_extra) && !empty($hook_extra['action']))
            ? $hook_extra['action']
            : 'update',
    ], false);
}

/**
 * Clean up all integrity state when switching away from this theme.
 */
add_action('switch_theme', 'pp_teardown_theme_integrity');
