# Component: logos

A flex-wrap image grid. Use for client logo strips (items without labels) or icon-category tiles (items with labels). Both use cases share the same component and CSS — the `label` field controls the layout variant.

## Props

| Prop    | Type   | Required | Default | Description |
|---------|--------|----------|---------|-------------|
| `id`    | string | No       | `''`    | HTML id for anchor linking |
| `title` | string | No       | `''`    | Optional heading above the grid |
| `variant` | enum | No       | `default` | Background presentation: `default` (page background), `dark` (surface background with borders), `inverted` (inverted background for strong contrast) |
| `items` | array  | Yes      | —       | Array of image items |

Each item:

| Key         | Type   | Required | Description |
|-------------|--------|----------|-------------|
| `image_url` | string | Yes      | Image or icon URL |
| `image_alt` | string | Yes      | Alt text — use the logo or category name |
| `image_id`  | int    | No       | Media Library attachment ID. When set and it resolves, renders responsively (`srcset`/`sizes`) via `wp_get_attachment_image()`; falls back to `image_url` otherwise |
| `label`     | string | No       | Text label below the image. Omit for logo-only rows. |

## Usage — logo strip (no labels)

```php
pp_get_component('logos', [
    'title' => 'Clients',
    'items' => [
        ['image_url' => '/path/to/3m.png',   'image_alt' => '3M'],
        ['image_url' => '/path/to/depsa.png', 'image_alt' => 'Depsa'],
    ],
]);
```

## Usage — icon + category tiles (with labels)

```php
pp_get_component('logos', [
    'title' => 'Sectors',
    'items' => [
        ['image_url' => '/icons/construction.svg', 'image_alt' => 'Construction', 'label' => 'Construction'],
        ['image_url' => '/icons/finance.svg',      'image_alt' => 'Finance',      'label' => 'Financial services'],
    ],
]);
```

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: logos === */`.
