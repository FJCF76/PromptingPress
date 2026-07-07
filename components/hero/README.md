# Component: hero

Full-width hero section with headline, optional subtitle, optional CTA button, and optional image.

## Props

| Prop            | Type   | Required | Default      | Description |
|-----------------|--------|----------|--------------|-------------|
| `id`            | string | No       | `''`         | HTML id for anchor linking |
| `title`         | string | Yes      | —            | Primary headline text |
| `title_accent`  | string | No       | `''`         | Exact substring of `title` to render in an accent color |
| `eyebrow`       | string | No       | `''`         | Short kicker/label rendered as a pill above the title |
| `subtitle`      | string | No       | `''`         | Supporting subheadline |
| `cta_text`      | string | No       | `''`         | Primary CTA button label (both buttons hidden if empty) |
| `cta_url`       | string | No       | `'#'`        | Primary CTA button URL |
| `cta2_text`     | string | No       | `''`         | Secondary CTA button label (only shown when `cta_text` is set) |
| `cta2_url`      | string | No       | `'#'`        | Secondary CTA button URL |
| `cta_variant`   | enum   | No       | `'primary'`  | Primary button style: `primary` / `secondary` / `outline` / `ghost` |
| `cta2_variant`  | enum   | No       | `'outline'`  | Secondary button style: `primary` / `secondary` / `outline` / `ghost` |
| `layout`       | enum   | No       | `'centered'` | Layout: `left`, `centered`, `split`, or `cover` |
| `image_url`     | string | No       | `''`         | Image URL — inline image in `split` layout, background image in `cover` layout |
| `image_id`      | int    | No       | `0`          | Media Library attachment ID for the `split` layout image. When set and it resolves, renders responsively (`srcset`/`sizes`) via `wp_get_attachment_image()`; falls back to `image_url` otherwise |
| `image_alt`     | string | No       | `''`         | Alt text for the `split` layout image |
| `spacing`       | enum   | No       | `'default'`  | Vertical padding: `default` / `compact` / `spacious` |
| `width`         | enum   | No       | `'default'`  | Content width: `default` / `narrow` (56rem) / `full` |
| `split_ratio`   | enum   | No       | `'50-50'`    | Column ratio for `split` layout: `50-50` / `60-40` / `40-60` |
| `vertical_align`| enum   | No       | `'center'`   | Vertical content alignment for `cover`/`split` layouts: `top` / `center` / `bottom` |
| `proof`         | string | No       | `''`         | HTML string for trust signals (logos, ratings), rendered after the CTA group |

## Layouts

- **centered** — All content centered horizontally. Best for homepage hero.
- **left** — Content aligned left. Best for interior page headers.
- **split** — Text on left, image on right (two-column at lg+). Best for feature introductions.
- **cover** — Fullscreen background image with a dark overlay and light text. Best for high-impact landing pages.

## Usage

```php
// Centered homepage hero
pp_get_component('hero', [
    'title'    => 'Build AI-Ready WordPress Sites',
    'subtitle' => 'PromptingPress gives AI tools a clear map of your site.',
    'cta_text' => 'Get Started',
    'cta_url'  => '/get-started',
    'layout'  => 'centered',
]);

// Split hero with image
pp_get_component('hero', [
    'title'     => 'The Abstraction Layer',
    'subtitle'  => 'lib/wp.php is the only file that calls WordPress.',
    'layout'   => 'split',
    'image_url' => get_template_directory_uri() . '/assets/images/diagram.png',
]);

// Interior page header (left-aligned, no CTA)
pp_get_component('hero', [
    'title'   => pp_page_title(),
    'layout' => 'left',
]);
```

## CSS

Styles live in `assets/css/components.css` under the `/* === COMPONENT: hero === */` section.

Layouts are applied via the BEM modifier class `hero--{layout}`.

## What NOT to change

- Do not add WordPress function calls to `hero.php`. Use pp_* wrappers from `lib/wp.php`.
- Do not add raw hex color values to component CSS. Use CSS variables from `base.css`.
- Do not modify `schema.json` without updating this README.
