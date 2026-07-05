# Component: footer

Site footer. Renders a WP nav menu (by theme location) and a copyright line using the site title.

## Props

| Prop        | Type   | Required | Default    | Description |
|-------------|--------|----------|------------|-------------|
| `location`  | string | No       | `'footer'` | WP theme location slug |
| `show_logo` | bool   | No       | `false`    | Whether to render the site logo in the footer (opt-in) |
| `logo_text` | string | No       | —          | Logo text (falls back to site title) |
| `logo_id`   | int    | No       | —          | Media Library attachment ID for an image logo (takes priority over `logo_text`) |
| `logo_alt`  | string | No       | —          | Alt text for the image logo |

## Usage

```php
// Called automatically from templates/base.php
pp_get_component('footer', ['location' => 'footer']);
```

## Setting up the footer menu

In WP Admin: Appearance → Menus → create a menu and assign it to the "Footer Navigation" location.

If no menu is assigned to the location, the nav area is empty but the footer still renders correctly (copyright line always shows).

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: footer === */`.

Background: `--color-surface`. Border top: `1px solid --color-border`.
