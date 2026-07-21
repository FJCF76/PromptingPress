<?php
/**
 * templates/base.php — HTML Shell
 *
 * Call pp_base_template(callable $content) from every page template.
 * The callable receives no arguments; it is responsible for echoing
 * the page body (component calls).
 *
 * Usage:
 *   pp_base_template(function () {
 *       pp_get_component('hero', [...]);
 *       pp_get_component('section', [...]);
 *   });
 */

if (!function_exists('pp_base_template')) {
    /**
     * Outputs the full HTML shell and calls $content() in the <main> region.
     *
     * @param callable $content  A function that outputs the page body.
     */
    function pp_base_template(callable $content): void {
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body class="<?php echo esc_attr(pp_body_classes()); ?>">
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e('Skip to content', 'promptingpress'); ?></a>

<?php pp_get_component('nav', [
    'location'   => 'primary',
    // Header chrome is template-owned (issue 223) and set through whitelisted site
    // options, never by composing a nav. The pp_header_* bg/text/link colors are the
    // header's only styling surface (issue 333) — the sibling of the pp_footer_*
    // surface below. Unset options pass '' and emit nothing.
    'bg'         => (string) get_option('pp_header_bg', ''),
    'text'       => (string) get_option('pp_header_text', ''),
    'link_color' => (string) get_option('pp_header_link_color', ''),
]); ?>

<main id="main">
    <?php $content(); ?>
</main>

<?php if (defined('WP_DEBUG') && WP_DEBUG) :
    $pp_conflicts = pp_check_custom_css_conflicts();
    if (!empty($pp_conflicts)) :
        $pp_conflict_selectors = array_map(fn($c) => $c['selector'], $pp_conflicts);
?>
<!-- PP WARNING: Custom CSS conflicts detected: <?php echo esc_html(implode(', ', $pp_conflict_selectors)); ?> -->
<?php endif; endif; ?>

<?php pp_get_component('footer', [
    'location'   => 'footer',
    // Footer chrome is template-owned (issue 223) and set through whitelisted
    // site options, never by composing a footer. show_logo is the pp_footer_show_logo
    // surface (issue 234); the dark-marketing-footer bg/text/link colors and the
    // blurb/contact/copyright content are the pp_footer_* surfaces (issue 300).
    'show_logo'  => get_option('pp_footer_show_logo', '') === '1',
    'bg'         => (string) get_option('pp_footer_bg', ''),
    'text'       => (string) get_option('pp_footer_text', ''),
    'link_color' => (string) get_option('pp_footer_link_color', ''),
    'blurb'      => (string) get_option('pp_footer_blurb', ''),
    'contact'    => (string) get_option('pp_footer_contact', ''),
    'copyright'  => (string) get_option('pp_footer_copyright', ''),
    // Footer STRUCTURE (issue 335): optional column headings, the bottom-bar
    // secondary note, and the footer logo override. logo_id is passed as the
    // footer's logo_id prop; pp_resolve_logo already falls back to the pp_logo_id
    // site option when it is empty, so an unset override keeps today's behavior.
    'menu_label'    => (string) get_option('pp_footer_menu_label', ''),
    'contact_label' => (string) get_option('pp_footer_contact_label', ''),
    'note'          => (string) get_option('pp_footer_note', ''),
    'logo_id'       => (string) get_option('pp_footer_logo_id', ''),
    // Second footer menu column (issue 469). secondary_location is a FIXED theme
    // location slug, not 'location' — the NavReadinessTest drift guard matches only
    // the FIRST 'location' => key of this call, so this extra key does not register
    // footer_secondary as a template-owned "renders on every page" location (it
    // renders only when a menu is assigned). secondary_label is its optional heading.
    'secondary_location' => 'footer_secondary',
    'secondary_label'    => (string) get_option('pp_footer_secondary_label', ''),
]); ?>

<?php wp_footer(); ?>
</body>
</html>
        <?php
    }
}
