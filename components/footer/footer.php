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

// Optional SECOND footer menu column (issue 469). It renders only when a menu is
// actually assigned to the secondary theme location — an unset location leaves the
// footer byte-identical to the single-menu layout. The heading follows the same
// headless-when-unset rule as $menu_label. Gating on has_nav_menu() (not just a
// non-empty location) is what keeps a stray non-column child out of the grid:
// .site-footer__columns is grid-auto-flow:column, so every present child becomes a
// track — an empty secondary <nav> would be a phantom column.
$secondary_location = trim((string) ($props['secondary_location'] ?? ''));
$secondary_label    = trim((string) ($props['secondary_label']    ?? ''));
$has_secondary      = $secondary_location !== '' && has_nav_menu($secondary_location);

// Optional social-icon row (issue 382). The value is the JSON pp_footer_social
// option (a validated list of {network, url}); decode it here into render-ready
// entries paired with their glyph from the CLOSED pp_footer_social_networks() map.
// Decoding is defensive (the option validator already guarantees the shape, but
// a hand-edited DB value must never emit broken markup): a non-list, or an entry
// whose network is not in the map, is skipped. Empty/unset = no row, which keeps
// the footer byte-identical to the pre-382 layout.
$social_networks = function_exists('pp_footer_social_networks') ? pp_footer_social_networks() : [];
$social_raw      = trim((string) ($props['social'] ?? ''));
$social_items    = [];
if ($social_raw !== '') {
    $social_decoded = json_decode($social_raw, true);
    if (is_array($social_decoded)) {
        foreach ($social_decoded as $social_entry) {
            if (!is_array($social_entry)) {
                continue;
            }
            $net = (string) ($social_entry['network'] ?? '');
            $url = trim((string) ($social_entry['url'] ?? ''));
            if ($url === '' || !isset($social_networks[$net])) {
                continue;
            }
            $social_items[] = [
                'label' => $social_networks[$net]['label'],
                'path'  => $social_networks[$net]['path'],
                'url'   => $url,
            ];
        }
    }
}
$has_social = $social_items !== [];

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

<?php
// The brand column now also hosts the social-icon row (issue 382): it renders
// when there is a logo, a blurb, OR a social row. The row's designed home is
// under the blurb (.site-footer__social, the reserved slot from #427). This
// comment sits at COLUMN 0 so it emits no extra leading whitespace, keeping the
// no-brand-column footer byte-identical to the pre-382 layout (#469 discipline).
?>
            <?php if ($logo || $blurb !== '' || $has_social) : ?>
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
<?php
                    // Social-icon row (issue 382). Each entry is an accessible link:
                    // the <svg> glyph is decorative (aria-hidden) so the link's name
                    // comes from its aria-label (the network's human label). href is
                    // esc_url'd and the link carries rel="noopener noreferrer" +
                    // target="_blank" for an external profile. The if/endif tags sit at
                    // COLUMN 0 (the #469 whitespace discipline): PHP swallows the newline
                    // after each close tag, so an absent row leaks ZERO template
                    // whitespace and the unset footer stays byte-identical.
?>
<?php if ($has_social) : ?>
                    <ul class="site-footer__social">
                        <?php foreach ($social_items as $social_item) : ?>
                            <li>
                                <a class="site-footer__social-link" href="<?php echo esc_url($social_item['url']); ?>" aria-label="<?php echo esc_attr($social_item['label']); ?>" target="_blank" rel="noopener noreferrer">
                                    <svg class="site-footer__social-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" fill-rule="evenodd" aria-hidden="true" focusable="false"><path d="<?php echo esc_attr($social_item['path']); ?>"></path></svg>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
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
<?php
            // The optional second menu column (issue 469). Same landmark treatment as the
            // primary footer nav, but a distinct aria-label so AT users can tell the two
            // footer menus apart. Renders ONLY when a menu is assigned to $secondary_location.
            // The if/endif tags below sit at COLUMN 0 on purpose: PHP swallows the single
            // newline after each PHP close tag, so an unrendered column emits ZERO extra
            // template whitespace and the unset footer stays byte-identical to the pre-469
            // single-menu layout (the #469 contract). Do not indent these tags.
?>
<?php if ($has_secondary) : ?>

            <nav class="site-footer__nav" aria-label="Footer secondary navigation">
                <?php if ($secondary_label !== '') : ?>
                    <h2 class="site-footer__heading"><?php echo esc_html($secondary_label); ?></h2>
                <?php endif; ?>
                <?php pp_nav_menu($secondary_location); ?>
            </nav>
<?php endif; ?>

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
