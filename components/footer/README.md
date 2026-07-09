# Component: footer

Site footer. Renders a WP nav menu (by theme location) and a copyright line using the site title.

> **Site chrome — not composable.** `templates/base.php` renders `footer` on every page.
> Putting it in a page's `_pp_composition` renders the footer twice, so the write is
> rejected with the error code `template_owned_component` (issue #223).
>
> The props below are set **by the template**, not by a page.

## Props (template-supplied)

`templates/base.php` calls this component with `['location' => 'footer']` and nothing else,
so in practice only `location` is ever set.

| Prop        | Type   | Required | Default    | Description |
|-------------|--------|----------|------------|-------------|
| `location`  | string | No       | `'footer'` | WP theme location slug |
| `show_logo` | bool   | No       | `false`    | Whether to render the site logo in the footer. **No supported surface sets this today** — see below |
| `logo_text` | string | No       | —          | Logo text (falls back to site title). Not reachable from a page |
| `logo_id`   | int    | No       | —          | Media Library attachment ID for an image logo (takes priority over `logo_text`). Must be an image attachment. Not reachable from a page; use the `pp_logo_id` option |
| `logo_alt`  | string | No       | —          | Alt text for the image logo. Not reachable from a page |

### The footer logo is currently unreachable

`show_logo` defaults to `false`, and the only way to pass `true` was to compose a `footer`
component — which is now rejected. So the footer logo cannot be turned on through any
supported surface. `pp_logo_id` sets the **header** logo; the footer keeps its copyright
line. Tracked in #234. Do not work around it by composing a `footer`.

## Usage

```php
// Called automatically from templates/base.php
pp_get_component('footer', ['location' => 'footer']);
```

## Configuring the footer

| Goal | Surface |
|------|---------|
| Build the footer menu | The menu actions: `create_menu` / `set_menu` / `add_menu_item` |
| Attach a menu to the footer | `assign_menu_location` with location `footer` |

`wp pp apply preflight` reports a `nav_readiness` warning when the `footer` location has no
menu assigned, or its menu is empty.

## Setting up the footer menu

In WP Admin: Appearance → Menus → create a menu and assign it to the "Footer Navigation" location.

If no menu is assigned to the location, the nav area is empty but the footer still renders correctly (copyright line always shows).

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: footer === */`.

Background: `--color-surface`. Border top: `1px solid --color-border`.
