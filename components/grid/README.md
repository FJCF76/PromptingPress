# Component: grid

Responsive card grid for discrete content objects. Use this for blog post listings, team members, or real features with substantive descriptions. Do NOT use this as a decorative icon grid.

## Props

| Prop    | Type   | Required | Default | Description |
|---------|--------|----------|---------|-------------|
| `title` | string | No       | `''`    | Section heading above the grid |
| `items` | array  | Yes      | —       | Array of card objects |

Each item in `items`:

| Key         | Type   | Required | Default        | Description |
|-------------|--------|----------|----------------|-------------|
| `title`     | string | No       | `''`           | Card heading (h3) |
| `text`      | string | No       | `''`           | Card body text |
| `image_url` | string | No       | `''`           | Card image URL |
| `link_url`  | string | No       | `''`           | Card link URL (shown only if set) |
| `link_text` | string | No       | `'Read more'`  | Card link label |

## Responsive behavior

| Breakpoint | Columns |
|------------|---------|
| Mobile     | 1       |
| Tablet (md 768px+) | 2  |
| Desktop (lg 1024px+) | 3 |

## Usage

```php
// Blog post archive
pp_get_component('grid', [
    'items' => array_map(function() {
        return [
            'title'     => pp_page_title(),
            'text'      => pp_excerpt(25),
            'image_url' => pp_thumbnail_url('medium'),
            'link_url'  => pp_permalink(),
            'link_text' => 'Read post',
        ];
    }, $posts),
]);

// Feature list (content cards, not decoration)
pp_get_component('grid', [
    'title' => 'How It Works',
    'items' => [
        [
            'title' => 'WP Abstraction Layer',
            'text'  => 'lib/wp.php is the only file that calls WordPress functions directly.',
        ],
        // ...
    ],
]);
```

## Anti-slop rule

Cards in this component must represent real content objects. If you're placing icons in circles with a two-line description, reconsider whether the grid is the right component.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: grid === */`.

Card hover state: `translateY(-2px)` — subtle lift. No shadow by default.
