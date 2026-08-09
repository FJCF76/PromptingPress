# Component: nav

Site header with logo, primary navigation menu, a hamburger toggle for mobile, and one-level dropdown submenus. Reads a WP registered nav menu by theme location slug.

> **Site chrome — not composable.** `templates/base.php` renders `nav` on every page.
> Putting it in a page's `_pp_composition` renders the header twice, so the write is
> rejected with the error code `template_owned_component` (issue #223).
>
> The props below are set **by the template**, not by a page. To change what the header
> shows, use the surfaces in [Configuring the header](#configuring-the-header).

## Props (template-supplied)

`templates/base.php` calls this component with `location`, the `pp_header_*` color options,
and `logo_alt` (from `pp_logo_alt`, #582), so those are the props ever set in practice.
`logo_text` and `logo_id` exist because `pp_resolve_logo()` accepts them, but no supported
surface passes them — a page cannot, because a page cannot compose `nav` at all. Set the
logo through the `pp_logo_id` option instead.

| Prop         | Type   | Required | Default     | Description |
|--------------|--------|----------|-------------|-------------|
| `location`   | string | No       | `'primary'` | WP theme location slug |
| `logo_text`  | string | No       | —           | Logo text (falls back to site title). Not reachable from any surface; change the site title instead |
| `logo_id`    | int    | No       | —           | Media Library attachment ID for an image logo (takes priority over `logo_text`). Not reachable as a prop — the image-attachment rule is enforced on the `pp_logo_id` **site option**, which is the surface to use |
| `logo_alt`   | string | No       | —           | Alt text for the image logo. Template-supplied from the `pp_logo_alt` site option (#582); not page-authored. Defaults to the attachment's own alt, then the site title, and is never empty |
| `bg`         | string | No       | —           | Header background. Set via the `pp_header_bg` site option → `--header-bg`. A CSS color **or** a bounded `linear-gradient()`/`radial-gradient()` (the shared `gradient` slot type, #333). Paints the header bar **and both menu panels** — see below |
| `text`       | string | No       | —           | Header text color (logo wordmark + mobile toggle). Set via `pp_header_text` → `--header-text`. CSS color only. Resting fallback `--color-text`; both surfaces hover to `--color-accent` |
| `link_color` | string | No       | —           | Header nav-link color, including the active/current link (#355). Set via `pp_header_link_color` → `--header-link-color`. CSS color only. Two fallbacks when unset: the resting link falls back to `--color-text`, the active link to `--color-accent` |

### What the header custom properties actually reach (#582)

The three `--header-*` properties do more than their names suggest. Documented here
because chrome declares no style slots, so this table is the only place to learn it.

| Property | Surfaces it paints | Fallback(s) when unset |
|----------|--------------------|------------------------|
| `--header-bg` | the sticky header bar; the **mobile menu disclosure panel** (`<768px`); the **desktop dropdown submenu panel** | header bar and mobile panel → `--color-bg`; dropdown panel → `--color-surface` (deliberately different, visible only when unset) |
| `--header-text` | the logo wordmark; the mobile hamburger toggle | `--color-text` |
| `--header-link-color` | resting nav links; the active/current link (#355) | resting → `--color-text`; active → `--color-accent` |

Hover is **not** reachable from any of them: the nav logo, the hamburger toggle, and nav
links all hover to the global `--color-accent` design token. If the accent is illegible on
a themed header, change the token with `update_design_token` — there is no per-header hover
option, and adding one would mean giving chrome a style slot, which the chrome contract
(#223) rules out.

## Configuring the header

The header is template-owned, so these site options are its **only** styling surface —
there are no composition style slots for it. Unset, the header renders exactly as it
always has.

| Goal | Surface |
|------|---------|
| Set the site logo | `update_site_option` with key `pp_logo_id` (a Media Library **image** attachment ID, not a URL) |
| Override the logo's alt text | `update_site_option` with key `pp_logo_alt` (text, #582). Only needed when the alt should differ from the attachment's own alt metadata; unset uses the attachment alt, then the site title. The same option also feeds the footer logo. Empty **or whitespace-only** counts as unprovided and falls through the chain; a real value renders verbatim |
| Build the header menu | The menu actions: `create_menu` / `set_menu` / `add_menu_item` |
| Attach a menu to the header | `assign_menu_location` with location `primary` |
| Dark or gradient header (background) | `update_site_option` with key `pp_header_bg` (a CSS color **or** a bounded gradient) |
| Header text / link colors | `update_site_option` with keys `pp_header_text` / `pp_header_link_color` (CSS colors) |

The active/current link follows `pp_header_link_color` too (#355), falling back to
`--color-accent` only when the link color is unset; the current item keeps its bold weight.
Hover keeps `--color-accent`, a global design token — change it with `update_design_token`
if the accent needs to suit a dark header. Style the header to match the SITE's real header,
not the hero: a dark hero is not a reason to make the header dark. Layout, sticky behavior,
and menu structure are not configurable: this is a color surface, not a header builder.

`wp pp apply preflight` reports a `nav_readiness` warning when the `primary` location has
no menu, its menu is empty, or `pp_logo_id` points at something that is not an image.

## Behavior

- **Mobile** (`< 768px`): Hamburger button shown. Menu hidden (`hidden` attribute). JS in `main.js` toggles `aria-expanded` and `hidden`.
- **Desktop** (`≥ 768px`): Hamburger hidden via CSS. Menu always visible.
- **Keyboard**: `Escape` closes the menu and returns focus to the toggle button.
- **Dropdown submenus** (#381): a nav item with children (authored via `set_menu`'s `children` array — one level deep) renders as an accessible dropdown. `main.js` enhances each parent into a WAI-ARIA **disclosure** (an injected `.nav__submenu-toggle` button with `aria-expanded`, never a menubar — no `role="menu"`). Desktop: hover reveals the dropdown (mouse); the button opens it for keyboard. Mobile: the group expands in place. Keyboard: the toggle opens on `Enter`/`Space`, `ArrowDown` opens it and moves focus to the first child, `Escape` closes it and returns focus to the toggle. Without JS, the submenu stays visible (expanded on mobile, hover on desktop).
- **Active link**: WordPress marks the current item server-side (`current-menu-item` on the `<li>`, `aria-current="page"` on the `<a>`); no JS. Its color follows `--header-link-color` (falling back to `--color-accent`) and it renders in bold.

## Usage

```php
// Called automatically from templates/base.php
pp_get_component('nav', ['location' => 'primary']);

// With custom logo text
pp_get_component('nav', [
    'location'  => 'primary',
    'logo_text' => 'My Brand',
]);
```

## Setting up the menu

In WP Admin: Appearance → Menus → create a menu and assign it to the "Primary Navigation" location. WordPress's own "sub item" nesting (drag an item slightly right) renders as a dropdown too. Via the AI surface, nest with `set_menu`'s per-item `children` array (one level deep).

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: nav === */`.

At `md` breakpoint (768px): `.nav__toggle { display: none }` and `.nav__menu { display: block }` (always visible, `hidden` attribute overridden by CSS).
