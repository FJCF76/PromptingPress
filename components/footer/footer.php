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

// Footer structure (issue 335). All optional; with every one empty the footer
// renders unchanged from issue 300 (no heading elements, copyright inline, no
// bottom bar) — the unset-output property FooterChromeTest pins.
$menu_label    = trim((string) ($props['menu_label']    ?? ''));
$contact_label = trim((string) ($props['contact_label'] ?? ''));
$note          = trim((string) ($props['note']          ?? ''));

// A non-empty note is the trigger for the delimited bottom bar: the copyright
// moves out of the main flow into its own band and renders opposite the note.
// Empty note = copyright stays inline exactly where issue 300 put it.
$has_bottom_bar = $note !== '';

// Build the copyright line ONCE so the inline and bottom-bar placements can't
// drift. esc_* is applied here; both echo sites emit it without re-escaping.
$copyright_html = $copyright !== ''
    ? esc_html($copyright)
    : '&copy; ' . esc_html($year) . ' ' . esc_html(pp_site_title()) . '. All rights reserved.';

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

        <?php // The three columns (brand · nav · contact) live in one grid track
              // container so the copyright/bottom bar can sit on its own row below
              // (issue 427). Every DIRECT child of .site-footer__columns must be a
              // real column: the desktop layout is grid-auto-flow:column, so it
              // makes exactly one equal track PER present child — a stray non-column
              // child would become a phantom column. The nav column always renders,
              // so this container is never empty. ?>
        <div class="site-footer__columns">

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

            <?php // A real <nav> landmark carrying an aria-label distinguishes the
                  // footer nav from the header's "Main navigation" for AT users
                  // (issue 427). The class is unchanged, so the footer-link CSS
                  // (.site-footer__nav ul li a) still applies. ?>
            <nav class="site-footer__nav" aria-label="Footer navigation">
                <?php if ($menu_label !== '') : ?>
                    <h2 class="site-footer__heading"><?php echo esc_html($menu_label); ?></h2>
                <?php endif; ?>
                <?php pp_nav_menu($location); ?>
            </nav>

            <?php if ($contact !== '') : ?>
                <div class="site-footer__contact">
                    <?php if ($contact_label !== '') : ?>
                        <h2 class="site-footer__heading"><?php echo esc_html($contact_label); ?></h2>
                    <?php endif; ?>
                    <?php // The contact block is real contact info, so it lives in an
                          // <address> and its email/phone become actionable links
                          // (issue 427; see pp_footer_linkify_contact for the contract). ?>
                    <address class="site-footer__address"><?php echo pp_footer_linkify_contact($contact); ?></address>
                </div>
            <?php endif; ?>

        </div>

        <?php if (!$has_bottom_bar) : ?>
        <p class="site-footer__copyright">
            <?php echo $copyright_html; ?>
        </p>
        <?php endif; ?>

    </div>

    <?php if ($has_bottom_bar) : ?>
    <div class="site-footer__bottom">
        <div class="container site-footer__bottom-inner">
            <p class="site-footer__copyright"><?php echo $copyright_html; ?></p>
            <p class="site-footer__note"><?php echo nl2br(esc_html($note)); ?></p>
        </div>
    </div>
    <?php endif; ?>
</footer>
