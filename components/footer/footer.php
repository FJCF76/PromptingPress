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

// Dark-marketing-footer color slots (issue 300). Each maps a validated site option
// to an inline CSS custom property the footer CSS reads via var(--footer-*, <literal>).
// pp_chrome_style_attr() derives each value's type from pp_allowed_site_options() keyed
// by the option name — so --footer-bg's 'gradient' (the color-OR-gradient union, widened
// in #333) is single-sourced from the whitelist, not a hand-copied 'color' that would
// silently DROP every stored gradient at the render boundary — and re-validates through
// the shared engine (#330).
$style_attr = pp_chrome_style_attr([
    '--footer-bg'         => ['value' => (string) ($props['bg']         ?? ''), 'option' => 'pp_footer_bg'],
    '--footer-text'       => ['value' => (string) ($props['text']       ?? ''), 'option' => 'pp_footer_text'],
    '--footer-link-color' => ['value' => (string) ($props['link_color'] ?? ''), 'option' => 'pp_footer_link_color'],
]);
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
