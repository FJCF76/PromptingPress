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

<?php pp_get_component('nav', ['location' => 'primary']); ?>

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

<?php pp_get_component('footer', ['location' => 'footer']); ?>

<?php wp_footer(); ?>
</body>
</html>
        <?php
    }
}
