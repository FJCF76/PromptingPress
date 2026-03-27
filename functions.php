<?php
/**
 * PromptingPress — functions.php
 *
 * Minimal WP bootstrap. Only registration and enqueueing here.
 * No hooks or filters anywhere else.
 */

// ── Theme version (single source of truth — keep in sync with style.css) ──
define('PP_VERSION', '1.0.0');

// ── Load lib files ─────────────────────────────────────────────────────────
require_once get_template_directory() . '/lib/wp.php';
require_once get_template_directory() . '/lib/components.php';
require_once get_template_directory() . '/lib/helpers.php';
require_once get_template_directory() . '/lib/admin.php';

// ── Theme setup ────────────────────────────────────────────────────────────
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', [
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ]);

    register_nav_menus([
        'primary' => __('Primary Navigation', 'promptingpress'),
        'footer'  => __('Footer Navigation', 'promptingpress'),
    ]);
});

// ── Enqueue assets ─────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', function () {
    $dir = get_template_directory_uri();
    $ver = PP_VERSION;

    wp_enqueue_style(
        'pp-base',
        $dir . '/assets/css/base.css',
        [],
        $ver
    );

    wp_enqueue_style(
        'pp-components',
        $dir . '/assets/css/components.css',
        ['pp-base'],
        $ver
    );

    wp_enqueue_style(
        'pp-utilities',
        $dir . '/assets/css/utilities.css',
        ['pp-base'],
        $ver
    );

    wp_enqueue_script(
        'pp-main',
        $dir . '/assets/js/main.js',
        [],
        $ver,
        true   // load in footer
    );
});
