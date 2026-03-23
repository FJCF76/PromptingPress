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

$location  = $props['location']  ?? 'primary';
$logo_text = $props['logo_text'] ?? pp_site_title();
?>
<header class="site-header">
    <nav class="nav" aria-label="Main navigation">
        <div class="container nav__container">

            <a class="nav__logo" href="<?php echo esc_url(pp_site_url()); ?>">
                <?php echo esc_html($logo_text); ?>
            </a>

            <button
                class="nav__toggle"
                aria-expanded="false"
                aria-controls="pp-nav-menu"
                type="button"
            >
                <span class="nav__toggle-icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <line x1="3" y1="6"  x2="21" y2="6"  stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="3" y1="12" x2="21" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="3" y1="18" x2="21" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
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
