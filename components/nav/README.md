# Component: nav

Site header with logo, primary navigation menu, and a hamburger toggle for mobile. Reads a WP registered nav menu by theme location slug.

## Props

| Prop        | Type   | Required | Default     | Description |
|-------------|--------|----------|-------------|-------------|
| `location`  | string | No       | `'primary'` | WP theme location slug |
| `logo_text` | string | No       | —           | Logo text (falls back to site title) |

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
