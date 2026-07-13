<?php
/**
 * components/footer/footer.php
 *
 * Site footer with optional WP nav menu and copyright line. Template-owned
 * chrome (issue 223): rendered once by templates/base.php, not composed, so its
 * inputs come from site options mapped to props by base.php — never from a
 * composition. See schema.json for the prop contract and the site-option keys.
 *
 * Props: see schema.json
 *
 * @var array $props
 */

$id        = $props['id']        ?? '';
$location  = $props['location']  ?? 'footer';
$show_logo = !empty($props['show_logo']); // default OFF — opt-in only
$logo      = $show_logo ? pp_resolve_logo($props) : null; // {type, url, alt, text}
$blurb     = trim((string) ($props['blurb']     ?? ''));
$contact   = trim((string) ($props['contact']   ?? ''));
$copyright = trim((string) ($props['copyright'] ?? ''));
$year      = wp_date('Y');

// Dark-marketing-footer color slots (issue 300). Each maps a validated site
// option to an inline CSS custom property the footer CSS reads via
// var(--footer-*, <literal>). Values are validated at write time by the shared
// _pp_validate_color() engine; esc_attr here is defense-in-depth on output.
$style_vars = [
    '--footer-bg'         => (string) ($props['bg']         ?? ''),
    '--footer-text'       => (string) ($props['text']       ?? ''),
    '--footer-link-color' => (string) ($props['link_color'] ?? ''),
];
$style_decls = [];
foreach ($style_vars as $var => $val) {
    if ($val === '') {
        continue;
    }
    // Render boundary (#330): re-validate the stored color value before emitting
    // it inline. These are color-typed site options, so delegate to the shared
    // engine; a value that never passed write-time validation (out-of-band write)
    // is dropped from output without blocking the rest of the footer.
    if (!pp_render_style_value_allowed($val, 'color')) {
        continue;
    }
    $style_decls[] = $var . ': ' . $val;
}
$style_attr = $style_decls ? ' style="' . esc_attr(implode('; ', $style_decls)) . '"' : '';
?>
<footer<?php echo $id ? ' id="' . esc_attr($id) . '"' : ''; ?> class="site-footer" data-pp-component="footer"<?php echo $style_attr; ?>>
    <div class="container site-footer__inner">

        <?php if ($logo || $blurb !== '') : ?>
            <div class="site-footer__brand">
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

                <?php if ($blurb !== '') : ?>
                    <p class="site-footer__blurb"><?php echo nl2br(esc_html($blurb)); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="site-footer__nav">
            <?php pp_nav_menu($location); ?>
        </div>

        <?php if ($contact !== '') : ?>
            <div class="site-footer__contact"><?php echo nl2br(esc_html($contact)); ?></div>
        <?php endif; ?>

        <p class="site-footer__copyright">
            <?php
            if ($copyright !== '') {
                echo esc_html($copyright);
            } else {
                echo '&copy; ' . esc_html($year) . ' ' . esc_html(pp_site_title()) . '. All rights reserved.';
            }
            ?>
        </p>

    </div>
</footer>
