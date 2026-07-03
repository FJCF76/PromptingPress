<?php
/**
 * components/footer/footer.php
 *
 * Site footer with optional WP nav menu and copyright line.
 * Props: see schema.json
 *
 * @var array $props
 */

$id        = $props['id']        ?? '';
$location  = $props['location']  ?? 'footer';
$show_logo = !empty($props['show_logo']); // default OFF — opt-in only
$logo      = $show_logo ? pp_resolve_logo($props) : null; // {type, url, alt, text}
$year      = wp_date('Y');
?>
<footer<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="site-footer" data-pp-component="footer">
    <div class="container site-footer__inner">

        <?php if ($logo) : ?>
            <a class="site-footer__logo" href="<?php echo esc_url(pp_site_url()); ?>">
                <?php if ($logo['type'] === 'image') : ?>
                    <img
                        src="<?php echo esc_url($logo['url']); ?>"
                        alt="<?php echo esc_attr($logo['alt']); ?>"
                        class="site-footer__logo-image"
                    >
                <?php else : ?>
                    <?php echo esc_html($logo['text']); ?>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <div class="site-footer__nav">
            <?php pp_nav_menu($location); ?>
        </div>

        <p class="site-footer__copyright text-muted">
            &copy; <?php echo esc_html($year); ?> <?php echo esc_html(pp_site_title()); ?>. All rights reserved.
        </p>

    </div>
</footer>
