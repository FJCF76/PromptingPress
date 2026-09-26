<?php
/**
 * PromptingPress — functions.php
 *
 * Minimal WP bootstrap. Only registration and enqueueing here.
 * No hooks or filters anywhere else.
 */

// ── Theme version (single source of truth — keep in sync with style.css) ──
define('PP_VERSION', '2.0.0-alpha.2');

// ── Load lib files ─────────────────────────────────────────────────────────
require_once get_template_directory() . '/lib/wp.php';
require_once get_template_directory() . '/lib/components.php';
require_once get_template_directory() . '/lib/helpers.php';
require_once get_template_directory() . '/lib/actions.php';
require_once get_template_directory() . '/lib/apply.php';
require_once get_template_directory() . '/lib/udc.php';
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
        'primary'          => __('Primary Navigation', 'promptingpress'),
        'footer'           => __('Footer Navigation', 'promptingpress'),
        // Optional second footer menu column (issue 469). Renders only when a
        // menu is assigned here; an unassigned location leaves the footer
        // byte-identical to the single-menu layout. Named generically
        // (footer_secondary) — a "Legal" column is one use, not the capability.
        'footer_secondary' => __('Footer Secondary Navigation', 'promptingpress'),
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
    //
    // Every row is re-validated against the type base.css declares for it before
    // it is allowed into the block (pp_token_override_renders(), lib/wp.php — the
    // #330 render boundary applied to this surface). A row that does not pass is
    // dropped and the rest still emit: the block shares the `pp-base` handle with
    // the UDC defaults tier below, and WordPress concatenates a handle's inline
    // styles into ONE <style> element, so keeping this block well-formed is what
    // keeps that tier painting. Dropped names are logged and reported by
    // `wp pp readiness status` through the same predicate.
    if ($overrides) {
        $pp_token_css = pp_token_overrides_inline_css($overrides, pp_design_tokens());
        if ($pp_token_css !== '') {
            wp_add_inline_style('pp-base', $pp_token_css);
        }
    }

    wp_enqueue_style(
        'pp-components',
        $dir . '/assets/css/components.css',
        ['pp-base'],
        $ver
    );

    // DEPENDS ON pp-components, AND THAT IS THE CASCADE, NOT HOUSEKEEPING (B2).
    //
    // The authored UDC tier and the authored chrome tier both ride this handle
    // precisely because it prints AFTER components.css — position is the entire
    // ranking mechanism (see the two-layer note below; both layers are zero- or
    // low-specificity by construction, so nothing else expresses the order).
    //
    // Declaring only ['pp-base'] made pp-utilities a SIBLING of pp-components in
    // WordPress's dependency graph, which left their relative print order decided
    // by nothing but which of these two calls runs first in this closure. Reorder
    // them, split the closure, or let a plugin dequeue and re-enqueue
    // pp-components (which moves it to the tail of the queue) and every authored
    // band value and every authored chrome value silently drops BENEATH the
    // stylesheet it exists to override — with no error, and with the whole suite
    // still green, because the inline-style attachments would be unchanged.
    //
    // Naming the dependency hands that ordering to WordPress's resolver instead.
    // It is a no-op on a clean request — these calls are already in this order —
    // which is what makes it safe to state.
    //
    // It closes the ACCIDENT, not the adversary: anything printed after this
    // handle (Customizer Additional CSS, a child theme, a plugin's late enqueue)
    // still outranks the authored tier at equal specificity — and since #986 it
    // outranks the v1 STYLESHEET at any specificity, because that sheet is in
    // `@layer pp-v1` and a site's own CSS is not. The authored tier is unlayered
    // too, so this dependency is now belt-and-braces rather than the whole
    // ranking mechanism: it still fixes the order of the two UNLAYERED tiers
    // against each other, which nothing else expresses. §3.4 still forbids
    // `!important`. See docs/explanation-cascade-layers.md.
    wp_enqueue_style(
        'pp-utilities',
        $dir . '/assets/css/utilities.css',
        ['pp-base', 'pp-components'],
        $ver
    );

    // v2 UDC CSS (BUILD-SPEC §3.5), emitted as TWO layers attached to two
    // different handles, because the cascade tier each belongs to IS its source
    // position and nothing else expresses that:
    //
    // NOTE (#986): the sentence below describes what was true before cascade
    // layers, and half of it no longer is. Only the BAND-ROOT half of the defaults
    // tier is `:where()`-wrapped and ranked by print position, and it is now ranked
    // by `@layer pp-zero` instead. The ELEMENT half emits at
    // `[data-pp-component="x"] .role` (0,2,0) and is UNLAYERED, so it beats
    // components.css by construction wherever it prints. Kept because the handle
    // split it describes is still real and still load-bearing for the authored tier.
    //
    //   defaults -> `pp-base`, so they print BEFORE components.css. Role defaults
    //     carry only their own selector's weight (the scope is :where()), and the
    //     `_band` role has no selector, so its defaults sit at zero — the same
    //     zero as the shared adjacent-band rhythm rule. Printing them first is
    //     what makes the shared rule win, keeping an unauthored v2 band inside
    //     the #430/#431 rhythm rulings.
    //
    //   authored -> `pp-utilities`, so they print AFTER all three stylesheets and
    //     outrank both the defaults layer and the shared design-system rules,
    //     which is the ranking §3.4 requires.
    //
    // Both attach to handles this same callback just enqueued, so each handle is
    // registered by construction rather than by assumption.
    //
    // The emitter reads the composition itself: this callback runs inside
    // wp_head(), which fires BEFORE the <main> loop paints the bands, so there is
    // no render pass to collect from. It resolves the page exactly the way the
    // templates do — see pp_udc_current_composition().
    //
    // THE DEFAULTS TIER ALSO COVERS WHAT A TEMPLATE RENDERS ITSELF (#1171). The
    // posts page, a single post, archives, search, the 404 and a page on the default
    // template render no composition (a default-template page may still STORE one,
    // whose CSS then paints nothing), so the composition alone left their components
    // with no defaults and they rendered as bare markup. Each of those templates
    // declares its components through pp_base_template(), which records them before
    // wp_head(); pp_udc_request_defaults_items() appends them after the composition's.
    // The AUTHORED tier stays composition-only: a template band has no band id.
    // Known gap outside this: a "latest posts" FRONT page renders through
    // front-page.php while this resolver answers [] for it (#1173).
    $pp_udc_composition = pp_udc_current_composition();

    $pp_udc_defaults = pp_udc_page_defaults_css(pp_udc_request_defaults_items($pp_udc_composition));
    if ($pp_udc_defaults !== '') {
        wp_add_inline_style('pp-base', $pp_udc_defaults);
    }

    $pp_udc_authored = pp_udc_page_authored_css($pp_udc_composition);
    if ($pp_udc_authored !== '') {
        wp_add_inline_style('pp-utilities', $pp_udc_authored);
    }

    // CHROME (BUILD-SPEC Addendum A, ruling A1) — the same two tiers, on the same
    // two handles, so chrome and bands share one cascade contract rather than two.
    //
    // NOT GATED ON THE COMPOSITION, and that is the difference that matters. The
    // band layers above are driven by pp_udc_current_composition(), which returns
    // [] for anything that is not a singular page with a readable composition —
    // 404, search, archives, a corrupt row, the no-front-page arm (on the template
    // routes among those, only what the template DECLARES reaches the defaults tier,
    // and a corrupt row or the no-front-page arm declares nothing). Chrome renders
    // on ALL of those, so gating its CSS the same way would leave a styled site
    // with a stock-coloured header on exactly the pages a visitor reaches when
    // something has already gone wrong.
    //
    // CHROME DEFAULTS EMIT ON EVERY SITE, styled or not (#994). This used to note that
    // a site with no chrome styling stored returned before the component registry was
    // touched; that short-circuit is gone, because the header's and footer's whole
    // resting appearance is role defaults now and skipping them would leave an
    // unstyled site with unpainted chrome. See pp_udc_chrome_defaults_css() for what
    // the gate was buying and why the cost is acceptable.
    $pp_chrome_defaults = pp_udc_chrome_defaults_css();
    if ($pp_chrome_defaults !== '') {
        wp_add_inline_style('pp-base', $pp_chrome_defaults);
    }

    $pp_chrome_authored = pp_udc_chrome_authored_css();
    if ($pp_chrome_authored !== '') {
        wp_add_inline_style('pp-utilities', $pp_chrome_authored);
    }

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

// ── Open Graph / Twitter social-share meta (#468) ───────────────────────────
add_action('wp_head', 'pp_social_meta_tags', 2);

// ── Front-end redirects (#62) ────────────────────────────────────────────────
// Rescues renamed/moved URLs: on an otherwise-404 request, a matching
// pp_redirects entry 301s to its canonical target instead of the 404 template.
add_action('template_redirect', 'pp_redirect_template_hook');
