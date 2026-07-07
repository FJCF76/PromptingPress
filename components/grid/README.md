# Component: grid

Responsive card grid for discrete content objects. Use this for blog post listings, team members, or real features with substantive descriptions. Do NOT use this as a decorative icon grid.

## Props

| Prop            | Type   | Required | Default   | Description |
|-----------------|--------|----------|-----------|-------------|
| `id`            | string | No       | `''`      | HTML id for anchor linking |
| `title`         | string | No       | `''`      | Section heading above the grid |
| `title_accent`  | string | No       | `''`      | Exact substring of `title` to render in an accent color |
| `eyebrow`       | string | No       | `''`      | Short kicker/label rendered as a pill above the title |
| `subheading`    | string | No       | `''`      | Supporting line below the title |
| `heading_align` | enum   | No       | `'start'` | Header block alignment: `start` / `center` |
| `layout`        | enum   | No       | `'cards'`   | Structural layout: `cards` (card grid) / `steps` (numbered process cards) |
| `theme`         | enum   | No       | `'default'` | Background color: `default` / `dark` / `inverted` (independent of `layout`) |
| `items`         | array  | Yes      | —         | Array of card objects |

Each item in `items`:

| Key         | Type   | Required | Default        | Description |
|-------------|--------|----------|----------------|-------------|
| `number`    | string | No*      | —              | Step number label, e.g. `'1'`. *Required when `layout` is `'steps'`. |
| `title`     | string | No       | `''`           | Card heading (h3) |
| `text`      | string | No       | `''`           | Card body text |
| `bullets`   | array  | No       | —              | Checklist lines rendered below `text`, each prefixed with a check mark. Plain text only. |
| `text_role` | enum   | No       | —              | Typography role for the card text: `mono` / `meta` / `label` / `kicker` |
| `image_url` | string | No       | `''`           | Card image URL |
| `image_alt` | string | No       | `''`           | Alt text for the card image |
| `link_url`  | string | No       | `''`           | Card link URL (shown only if set) |
| `link_text` | string | No       | `'Read more'`  | Card link label |

## Responsive behavior

| Breakpoint | Columns |
|------------|---------|
| Mobile     | 1       |
| Tablet (md 768px+) | 2  |
| Desktop (lg 1024px+) | 3 |

## Steps layout

Set `layout: 'steps'` for a numbered process/how-it-works layout (the default `layout` is `cards`). Cards get a filled circular number badge and a subtle connector line between badges at desktop (1024px+). `theme` still controls background color independently of the steps layout.

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

// Feature card with a checklist instead of a paragraph
pp_get_component('grid', [
    'title' => 'Security',
    'items' => [
        [
            'title'   => 'Perimeter security',
            'bullets' => ['HTTP security headers', 'SSL/TLS validity', 'Clickjacking protection'],
        ],
        // ...
    ],
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

Card hover state: `translateY(-2px)` — subtle lift. No shadow by default; set a
`--grid-card-shadow` style slot (e.g. `var(--shadow-md)`) for elevated cards.
