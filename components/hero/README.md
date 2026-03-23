# Component: hero

Full-width hero section with headline, optional subtitle, optional CTA button, and optional image.

## Props

| Prop        | Type   | Required | Default      | Description |
|-------------|--------|----------|--------------|-------------|
| `title`     | string | Yes      | —            | Primary headline text |
| `subtitle`  | string | No       | `''`         | Supporting subheadline |
| `cta_text`  | string | No       | `''`         | CTA button label (hidden if empty) |
| `cta_url`   | string | No       | `'#'`        | CTA button URL |
| `variant`   | enum   | No       | `'centered'` | Layout: `left`, `centered`, or `split` |
| `image_url` | string | No       | `''`         | Image URL (only used in `split` variant) |

## Variants

- **centered** — All content centered horizontally. Best for homepage hero.
- **left** — Content aligned left. Best for interior page headers.
- **split** — Text on left, image on right (two-column at lg+). Best for feature introductions.

## Usage

```php
// Centered homepage hero
pp_get_component('hero', [
    'title'    => 'Build AI-Ready WordPress Sites',
    'subtitle' => 'PromptingPress gives AI tools a clear map of your site.',
    'cta_text' => 'Get Started',
    'cta_url'  => '/get-started',
    'variant'  => 'centered',
]);

// Split hero with image
pp_get_component('hero', [
    'title'     => 'The Abstraction Layer',
    'subtitle'  => 'lib/wp.php is the only file that calls WordPress.',
    'variant'   => 'split',
    'image_url' => get_template_directory_uri() . '/assets/images/diagram.png',
]);

// Interior page header (left-aligned, no CTA)
pp_get_component('hero', [
    'title'   => pp_page_title(),
    'variant' => 'left',
]);
```

## CSS

Styles live in `assets/css/components.css` under the `/* === COMPONENT: hero === */` section.

Variants are applied via the BEM modifier class `hero--{variant}`.

## What NOT to change

- Do not add WordPress function calls to `hero.php`. Use pp_* wrappers from `lib/wp.php`.
- Do not add raw hex color values to component CSS. Use CSS variables from `base.css`.
- Do not modify `schema.json` without updating this README.
