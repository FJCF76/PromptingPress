<?php
/**
 * components/footer/footer.php
 *
 * Site footer with optional WP nav menu and copyright line.
 * Props: see schema.json
 *
 * @var array $props
 */

$location = $props['location'] ?? 'footer';
$year     = wp_date('Y');
?>
<footer class="site-footer">
    <div class="container site-footer__inner">

        <div class="site-footer__nav">
            <?php pp_nav_menu($location); ?>
        </div>

        <p class="site-footer__copyright text-muted">
            &copy; <?php echo esc_html($year); ?> <?php echo esc_html(pp_site_title()); ?>. All rights reserved.
        </p>

    </div>
</footer>
