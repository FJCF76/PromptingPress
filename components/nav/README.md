# Component: nav

Site header with logo, primary navigation menu, a hamburger toggle for mobile, and one-level dropdown submenus. Reads a WP registered nav menu by theme location slug.

> **Site chrome — not composable.** `templates/base.php` renders `nav` on every page.
> Putting it in a page's `_pp_composition` renders the header twice, so the write is
> rejected with the error code `template_owned_component` (issue #223).
>
> The props below are set **by the template**, not by a page. To change what the header
> shows, use the surfaces in [Configuring the header](#configuring-the-header).

## Props (template-supplied)

`templates/base.php` calls this component with `location`,
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

## Styling: the UDC roles

The header is styled through the **`nav` entry of the `pp_site_udc` site option**, in
exactly the shape a band's `udc` map takes — same engine, same grammar, same groups,
same `@token` references, same breakpoint and `:hover` / `:focus-visible` / `:active`
state maps, same presets.

This replaced three colour options (`pp_header_bg`, `pp_header_text`,
`pp_header_link_color`), which are gone. They were the header's only styling surface
when chrome had no other one, and their limits are why the roles below exist: three
knobs reaching eight surfaces, each with its own undocumented fallback, and no hover,
no breakpoint, no state, no spacing, no typography.

| Role | Selector | What it is |
|------|----------|------------|
| _band | the `<header>` itself | the sticky header bar |
| logo | `.nav__logo` | the logo link / wordmark |
| logo-image | `.nav__logo-image` | the logo `<img>`, when one resolves |
| menu | `.nav__menu` | the menu container — on phones, the disclosure panel |
| submenu | `.nav__menu .sub-menu` | the desktop dropdown panel |
| link | `.nav__menu ul li a` | every nav link at rest |
| link-current | `.nav__menu ul li.current-menu-item a` | the link for the page you are on |
| toggle | `.nav__toggle` | the hamburger button (phones only) |

A role's `background` takes a colour, a bounded gradient, or a Media Library **attachment ID** via `background.image` (never a URL) with `background.overlay` for a scrim over it — so a photographic header band is expressible. Pair image and overlay whenever text sits on the picture.

`menu` and `submenu` are deliberately SEPARATE roles. One option used to paint both,
with two different fallbacks, which was impossible to reason about from the name.

Hover, focus and the active state are ordinary value dimensions now: put a `:hover`
map inside the `link` role's `typography` group. The global `--color-accent` token is
still the default for all three, so an unstyled header is unchanged.

### Stated defaults

`.nav__menu .sub-menu { min-width: 12rem }` (at 768px and up, where the dropdown exists) is the dropdown panel's floor width. A
floating panel cannot size to its own content without jitter: rename one child item and
the panel's width would jump under the cursor mid-hover. `12rem` is the width at which a
typical menu label does not wrap, so the panel holds still while the menu changes. It is
a literal rather than a slot for the same reason as everything else here — chrome
declares **zero style slots** by ratified contract, so a stated reason is the only
disposition available, not a second-best one. The same block **is** partly
token-reachable already: the panel's fill is the `submenu` UDC role and its corners
follow `--radius`. **Reopening condition:** the chrome model's own boundary (#223) moving.

## Configuring the header

The header is template-owned, so it is configured through site options and never by
composing a nav. Styling is `pp_site_udc` (above); the options below are content and
configuration.

| Goal | Surface |
|------|---------|
| Set the site logo | `update_site_option` with key `pp_logo_id` (a Media Library **image** attachment ID, not a URL) |
| Override the logo's alt text | `update_site_option` with key `pp_logo_alt` (text, #582). Only needed when the alt should differ from the attachment's own alt metadata; unset uses the attachment alt, then the site title. The same option also feeds the footer logo. Empty **or whitespace-only** counts as unprovided and falls through the chain; a real value renders verbatim |
| Build the header menu | The menu actions: `create_menu` / `set_menu` / `add_menu_item` |
| Attach a menu to the header | `assign_menu_location` with location `primary` |
| Dark or gradient header, text and link colours, spacing, typography | `update_site_option` with key `pp_site_udc`, setting the roles above |

The active/current link is the `link-current` role; it keeps its bold weight, which is
structural. Style the header to match the SITE's real header, not the hero: a dark hero
is not a reason to make the header dark. Layout, sticky behavior and menu structure are
still not configurable — the UDC is a design surface, not a header builder.

`wp pp apply preflight` reports a `nav_readiness` warning when the `primary` location has
no menu, its menu is empty, or `pp_logo_id` points at something that is not an image.

## Behavior

- **Mobile** (`< 768px`): Hamburger button shown. Menu hidden (`hidden` attribute). JS in `main.js` toggles `aria-expanded` and `hidden`.
- **Desktop** (`≥ 768px`): Hamburger hidden via CSS. Menu always visible.
- **Keyboard**: `Escape` closes the menu and returns focus to the toggle button.
- **Dropdown submenus** (#381): a nav item with children (authored via `set_menu`'s `children` array — one level deep) renders as an accessible dropdown. `main.js` enhances each parent into a WAI-ARIA **disclosure** (an injected `.nav__submenu-toggle` button with `aria-expanded`, never a menubar — no `role="menu"`). Desktop: hover reveals the dropdown (mouse); the button opens it for keyboard. Mobile: the group expands in place. Keyboard: the toggle opens on `Enter`/`Space`, `ArrowDown` opens it and moves focus to the first child, `Escape` closes it and returns focus to the toggle. Without JS, the submenu stays visible (expanded on mobile, hover on desktop).
- **Active link**: WordPress marks the current item server-side (`current-menu-item` on the `<li>`, `aria-current="page"` on the `<a>`); no JS. Its colour is the `link-current` role (defaulting to `--color-accent`) and it renders in bold.

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
