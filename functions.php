<?php
/**
 * PromptingPress — functions.php
 *
 * Minimal WP bootstrap. Only registration and enqueueing here.
 * No hooks or filters anywhere else.
 */

// ── Theme version (single source of truth — keep in sync with style.css) ──
define('PP_VERSION', '0.16.110');

// ── Load lib files ─────────────────────────────────────────────────────────
require_once get_template_directory() . '/lib/wp.php';
require_once get_template_directory() . '/lib/components.php';
require_once get_template_directory() . '/lib/helpers.php';
require_once get_template_directory() . '/lib/actions.php';
require_once get_template_directory() . '/lib/apply.php';
require_once get_template_directory() . '/lib/ai-context.php';
require_once get_template_directory() . '/lib/ai-provider.php';
require_once get_template_directory() . '/lib/admin.php';
require_once get_template_directory() . '/lib/guardrails.php';
require_once get_template_directory() . '/lib/operate.php';
require_once get_template_directory() . '/lib/screenshot.php';
require_once get_template_directory() . '/lib/setup.php';
require_once get_template_directory() . '/lib/post-apply-validate.php';

if (is_admin()) {
    require_once get_template_directory() . '/lib/ai-chat.php';
}

if (defined('WP_CLI') && WP_CLI) {
    require_once get_template_directory() . '/lib/cli.php';
}

// ── Admin notices ─────────────────────────────────────────────────────────
add_action('admin_notices', 'pp_admin_notice_css_conflicts');
add_action('admin_notices', 'pp_admin_notice_theme_integrity');
add_action('admin_notices', 'pp_admin_notice_last_blocked_update');

// ── Theme setup ────────────────────────────────────────────────────────────
add_action('after_setup_theme', function () {
    add_theme_support('automatic-feed-links');
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

    $base_css_path = get_template_directory() . '/assets/css/base.css';
    $base_ver = $ver . '.' . (file_exists($base_css_path) ? filemtime($base_css_path) : '0');

    // Append override hash to version string for cache busting.
    $overrides = pp_get_token_overrides();
    if ($overrides) {
        $base_ver .= '.' . substr(md5(serialize($overrides)), 0, 8);
    }

    // Custom font URLs from database (enqueued before pp-base so fonts load early).
    $font_urls = pp_get_font_urls();
    foreach ($font_urls as $i => $font_url) {
        wp_enqueue_style(
            'pp-font-' . $i,
            $font_url,
            [],
            null
        );
    }

    wp_enqueue_style(
        'pp-base',
        $dir . '/assets/css/base.css',
        $font_urls ? ['pp-font-0'] : [],
        $base_ver
    );

    // Output overridden tokens as inline CSS after pp-base.
    if ($overrides) {
        $lines = [];
        foreach ($overrides as $token => $value) {
            $lines[] = '  ' . $token . ': ' . $value . ';';
        }
        wp_add_inline_style('pp-base', ":root {\n" . implode("\n", $lines) . "\n}");
    }

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

// ── Page-specific SEO metadata (#41) ────────────────────────────────────────
add_action('wp_head', 'pp_seo_meta_description_tag', 1);
add_filter('pre_get_document_title', 'pp_seo_document_title_override');
add_filter('get_canonical_url', 'pp_seo_canonical_url_override', 10, 2);

// ── Front-end redirects (#62) ────────────────────────────────────────────────
// Rescues renamed/moved URLs: on an otherwise-404 request, a matching
// pp_redirects entry 301s to its canonical target instead of the 404 template.
add_action('template_redirect', 'pp_redirect_template_hook');
