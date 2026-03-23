# Component: section

Generic text + optional image section. Use this for "what is this", "how it works", and any narrative content block.

## Props

| Prop        | Type   | Required | Default       | Description |
|-------------|--------|----------|---------------|-------------|
| `title`     | string | No       | `''`          | Section heading (h2) |
| `body`      | string | Yes      | —             | HTML body content (wp_kses_post filtered) |
| `image_url` | string | No       | `''`          | Image URL (required for image-left / image-right) |
| `image_alt` | string | No       | `''`          | Image alt text |
| `layout`    | enum   | No       | `'text-only'` | Layout variant |

## Variants

- **text-only** — Full-width text column. Used for articles and prose content.
- **image-left** — Two-column at md+: image on left, text on right.
- **image-right** — Two-column at md+: text on left, image on right.

If `image_url` is empty, the layout always falls back to `text-only`.

## Usage

```php
// Basic prose section
pp_get_component('section', [
    'title' => 'About This Theme',
    'body'  => '<p>PromptingPress is designed for AI comprehension.</p>',
]);

// Section with image on the right
pp_get_component('section', [
    'title'     => 'The WP Abstraction Layer',
    'body'      => '<p>Only lib/wp.php calls WordPress functions directly.</p>',
    'image_url' => 'https://example.com/diagram.png',
    'image_alt' => 'Architecture diagram',
    'layout'    => 'image-right',
]);

// Page content (from WP editor)
pp_get_component('section', [
    'body'   => pp_page_content(),
    'layout' => 'text-only',
]);
```

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: section === */`.

## What NOT to change

- Do not call WordPress functions in `section.php`. Use pp_* wrappers.
- Do not add raw hex colors. Use CSS variables from `base.css`.
