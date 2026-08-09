<?php
/**
 * components/nav/nav.php
 *
 * Site navigation with logo, hamburger toggle on mobile, and WP menu.
 * Desktop (md+): hamburger hidden, menu always visible.
 * Mobile: hamburger toggles the menu via main.js (aria-expanded + hidden attribute).
 * Props: see schema.json
 *
 * @var array $props
 */

$location = $props['location'] ?? 'primary';
// No `id` read here on purpose (issue 581): the header is template-owned chrome
// (issue 223), nav/schema.json declares no `id` prop, and templates/base.php never
// passes one — so the read was dead. Do not re-add it; that would be the first step
// toward a composable header, which the chrome contract rules out.
$logo     = pp_resolve_logo($props); // {type, url, alt, text} — attachment-ID only

// Header chrome color slots (issue 333). The header is template-owned (issue 223)
// and never composed, so — exactly like the footer (issue 300) — its styling surface
// is the pp_header_* site options, which base.php maps onto these props. Each emits an
// inline CSS custom property the header CSS reads via var(--header-*, <literal>); with
// nothing set, no style attribute is emitted at all and the output is byte-identical to
// before. pp_chrome_style_attr() derives each value's type from pp_allowed_site_options()
// keyed by the option name (so --header-bg's 'gradient' can never drift from the
// whitelist) and re-validates at the render boundary (#330).
$style_attr = pp_chrome_style_attr([
    '--header-bg'         => ['value' => (string) ($props['bg']         ?? ''), 'option' => 'pp_header_bg'],
    '--header-text'       => ['value' => (string) ($props['text']       ?? ''), 'option' => 'pp_header_text'],
    '--header-link-color' => ['value' => (string) ($props['link_color'] ?? ''), 'option' => 'pp_header_link_color'],
]);
?>
<header class="site-header" data-pp-component="nav"<?php echo $style_attr; ?>>
    <nav class="nav" aria-label="Main navigation">
        <div class="container nav__container">

            <a class="nav__logo" href="<?php echo esc_url(pp_site_url()); ?>">
                <?php if ($logo['type'] === 'image') : ?>
                    <img
                        src="<?php echo esc_url($logo['url']); ?>"
                        alt="<?php echo esc_attr($logo['alt']); ?>"
                        class="nav__logo-image"
                    >
                <?php else : ?>
                    <?php echo esc_html($logo['text']); ?>
                <?php endif; ?>
            </a>

            <button
                class="nav__toggle"
                aria-expanded="false"
                aria-controls="pp-nav-menu"
                type="button"
            >
                <?php // Two icons, swapped purely by CSS on the button's aria-expanded
                      // state (components.css): hamburger when closed, ✕ when open —
                      // so the same button is the obvious close affordance (issue 426).
                      // JS never touches these; it owns aria-expanded, CSS owns the swap. ?>
                <span class="nav__toggle-icon nav__toggle-icon--open" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <line x1="3" y1="6"  x2="21" y2="6"  stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="3" y1="12" x2="21" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="3" y1="18" x2="21" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </span>
                <span class="nav__toggle-icon nav__toggle-icon--close" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <line x1="6" y1="6"  x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="18" y1="6" x2="6"  y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </span>
                <span class="sr-only">Menu</span>
            </button>

            <div id="pp-nav-menu" class="nav__menu">
                <?php pp_nav_menu($location); ?>
            </div>

        </div>
    </nav>
</header>
