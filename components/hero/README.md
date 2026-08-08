# Component: hero

Full-width hero section with headline, optional subheading, optional CTA button, and optional image.

## Props

| Prop            | Type   | Required | Default      | Description |
|-----------------|--------|----------|--------------|-------------|
| `id`            | string | No       | `''`         | HTML id for anchor linking |
| `title`         | string | Yes      | —            | Primary headline text |
| `title_accent`  | string | No       | `''`         | Exact substring of `title` to render in an accent color |
| `eyebrow`       | string | No       | `''`         | Short kicker/label rendered as a pill above the title |
| `subheading`      | string | No       | `''`         | Supporting subheadline |
| `button_text`      | string | No       | `''`         | Primary CTA button label (both buttons hidden if empty) |
| `button_url`       | string | No       | `'#'`        | Primary CTA button URL |
| `button2_text`     | string | No       | `''`         | Secondary CTA button label (only shown when `button_text` is set) |
| `button2_url`      | string | No       | `'#'`        | Secondary CTA button URL |
| `button_variant`   | enum   | No       | `'primary'`  | Primary button style: `primary` / `secondary` / `outline` / `ghost` |
| `button2_variant`  | enum   | No       | `'outline'`  | Secondary button style: `primary` / `secondary` / `outline` / `ghost` |
| `layout`       | enum   | No       | `'centered'` | Layout: `left`, `centered`, `split`, or `cover` |
| `image_url`     | string | No       | `''`         | Image URL — inline image in `split` layout, background image in `cover` layout |
| `image_id`      | int    | No       | `0`          | Media Library attachment ID for the `split` layout image. When set and it resolves, renders responsively (`srcset`/`sizes`) via `wp_get_attachment_image()`; falls back to `image_url` otherwise |
| `image_alt`     | string | No       | `''`         | Alt text for the `split` layout image |
| `spacing`       | enum   | No       | `'default'`  | Vertical padding: `default` / `compact` / `spacious` |
| `width`         | enum   | No       | `'default'`  | Content width: `default` / `narrow` (the `--measure-centered` token, 56rem by default, so a retuned centered measure moves this too) / `full`. `width` and `spacing` are hero-only props — no other component emits `data-pp-width` / `data-pp-spacing`, and the CSS is scoped to `.hero` |
| `split_ratio`   | enum   | No       | `'50-50'`    | Column ratio for `split` layout: `50-50` / `60-40` / `40-60` |
| `vertical_align`| enum   | No       | `'center'`   | Vertical content alignment for `cover`/`split` layouts: `top` / `center` / `bottom` / `stretch`. `stretch` (split only) makes the media column fill the content column's height — one asset balances any headline length; on `cover` it renders like `center` |
| `proof`         | string | No       | `''`         | HTML string for trust signals (logos, ratings), rendered after the CTA group |

## Layouts

- **centered** — All content centered horizontally. Best for homepage hero.
- **left** — Content aligned left. Best for interior page headers.
- **split** — Text on left, image on right (two-column at lg+). Best for feature introductions.
- **cover** — Fullscreen background image with a dark overlay and light text. Best for high-impact landing pages. Both CTAs sit on that overlay, so their `outline`/`ghost` defaults fall back to `--color-accent-on-overlay` rather than the light-surface accent, and every filled CTA — the primary and the second button (`button2_*`) alike — defaults its border to the same role so its shape stays visible against the image and an unstyled filled pair stays symmetric (the role is the last link, so `--hero-button-border`, `--hero-accent` and this band's own `--hero-button-bg` all still win ahead of it for the PRIMARY, and `--hero-button2-border`, `--hero-accent` and `--hero-button2-bg` for the second button, in that order — the site-wide button tokens are deliberately NOT in these chains: `--btn-border-color` / `--btn-hover-border-color` left in #564 and `--btn-bg` / `--btn-hover-bg` in #565, because the role carries a measured 4.59:1 contrast guarantee that a site-wide retheme was cancelling, the ring tokens directly and the fill tokens through the border-follows-fill link. Flattening this band with `--hero-button2-bg` still gives a matching ring; a site-wide `--btn-bg` does not reach it). `--hero-heading-color` (first CTA) and `--hero-button2-color` / `--hero-button2-border` (second) still win when set. The focus ring routes through the same role for every variant, filled included — it is drawn outside the button and so lands on the overlay, where the bare accent measured 1.17:1 against WCAG 1.4.11's 3:1. Only the colour changes; width, style and offset are unchanged. Keyboard focus is the motivating case, but the routing attaches to `:focus`, so it recolours whatever ring the button already paints, a pointer click included. This applies to any `cover` hero, image or not: the scrim is painted either way.

## Usage

```php
// Centered homepage hero
pp_get_component('hero', [
    'title'    => 'Build AI-Ready WordPress Sites',
    'subheading' => 'PromptingPress gives AI tools a clear map of your site.',
    'button_text' => 'Get Started',
    'button_url'  => '/get-started',
    'layout'  => 'centered',
]);

// Split hero with image
pp_get_component('hero', [
    'title'     => 'The Abstraction Layer',
    'subheading'  => 'lib/wp.php is the only file that calls WordPress.',
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
