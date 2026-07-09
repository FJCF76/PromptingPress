# Component: nav

Site header with logo, primary navigation menu, and a hamburger toggle for mobile. Reads a WP registered nav menu by theme location slug.

> **Site chrome — not composable.** `templates/base.php` renders `nav` on every page.
> Putting it in a page's `_pp_composition` renders the header twice, so the write is
> rejected with the error code `template_owned_component` (issue #223).
>
> The props below are set **by the template**, not by a page. To change what the header
> shows, use the surfaces in [Configuring the header](#configuring-the-header).

## Props (template-supplied)

`templates/base.php` calls this component with `['location' => 'primary']` and nothing
else, so in practice only `location` is ever set. The logo props exist because
`pp_resolve_logo()` accepts them, but no supported surface passes them — a page cannot,
because a page cannot compose `nav` at all. Set the logo through the `pp_logo_id` option
instead.

| Prop        | Type   | Required | Default     | Description |
|-------------|--------|----------|-------------|-------------|
| `location`  | string | No       | `'primary'` | WP theme location slug |
| `logo_text` | string | No       | —           | Logo text (falls back to site title). Not reachable from a page; see below |
| `logo_id`   | int    | No       | —           | Media Library attachment ID for an image logo (takes priority over `logo_text`). Must be an image attachment. Not reachable from a page; use the `pp_logo_id` option |
| `logo_alt`  | string | No       | —           | Alt text for the image logo. Not reachable from a page |

## Configuring the header

| Goal | Surface |
|------|---------|
| Set the site logo | `update_site_option` with key `pp_logo_id` (a Media Library **image** attachment ID, not a URL) |
| Build the header menu | The menu actions: `create_menu` / `set_menu` / `add_menu_item` |
| Attach a menu to the header | `assign_menu_location` with location `primary` |

`wp pp apply preflight` reports a `nav_readiness` warning when the `primary` location has
no menu, its menu is empty, or `pp_logo_id` points at something that is not an image.

## Behavior

- **Mobile** (`< 768px`): Hamburger button shown. Menu hidden (`hidden` attribute). JS in `main.js` toggles `aria-expanded` and `hidden`.
- **Desktop** (`≥ 768px`): Hamburger hidden via CSS. Menu always visible.
- **Keyboard**: `Escape` closes the menu and returns focus to the toggle button.
- **Active link**: `main.js` sets `aria-current="page"` on the matching nav link.

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

In WP Admin: Appearance → Menus → create a menu and assign it to the "Primary Navigation" location.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: nav === */`.

At `md` breakpoint (768px): `.nav__toggle { display: none }` and `.nav__menu { display: block }` (always visible, `hidden` attribute overridden by CSS).
