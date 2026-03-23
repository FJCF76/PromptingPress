# Component: footer

Site footer. Renders a WP nav menu (by theme location) and a copyright line using the site title.

## Props

| Prop       | Type   | Required | Default    | Description |
|------------|--------|----------|------------|-------------|
| `location` | string | No       | `'footer'` | WP theme location slug |

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
