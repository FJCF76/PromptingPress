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

// STYLING: the site chrome UDC container, not props and not an inline style.
//
// The header used to carry three inline custom properties built from the
// pp_header_bg / pp_header_text / pp_header_link_color site options. Those options
// are GONE (v2, BUILD-SPEC Addendum A ruling A1 with the §3.1 "one styling system"
// directive): chrome is now styled through the `nav` entry of the pp_site_udc
// option, which runs through the same engine, grammar and cascade as a band's
// `udc` map and reaches every role this schema declares — not only three colours.
//
// So this template emits NO style attribute at all. The CSS arrives as a scoped
// block on the `pp-base` / `pp-utilities` handles (see functions.php), keyed to
// the data-pp-chrome attribute below, which is why that attribute is a template
// CONSTANT and not a read of anything: it is the selector's other half.
// Deliberately NOT an `id` — see the note above; an id read here would be the
// first step toward a composable header, which the chrome contract still rules out.
?>
<header class="site-header" data-pp-component="nav" data-pp-chrome="nav">
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
