# Component: testimonials

Customer quotes with attribution, for social-proof sections. Use this instead of embedding `<blockquote>` HTML inside a `section` body — it gives each quote its own structured attribution (author, role, company, avatar) and lets AI reorder or restyle individual testimonials.

## Props

| Prop            | Type   | Required | Default | Description |
|-----------------|--------|----------|---------|-------------|
| `id`            | string | No       | `''`    | HTML id for anchor linking |
| `title`         | string | No       | `''`    | Section heading |
| `title_accent`  | string | No       | `''`    | Exact substring of `title` to render in an accent color |
| `eyebrow`       | string | No       | `''`    | Short kicker/label above the title |
| `subheading`    | string | No       | `''`    | Supporting line below the title |
| `heading_align` | enum   | No       | `start` | `start` or `center` |
| `layout`       | enum   | No       | `grid`  | Layout: `grid` (card grid) or `stack` (single centered column) |
| `theme`         | enum   | No       | `default` | Background color: `default` / `dark` / `inverted` |
| `items`         | array  | Yes      | —       | Array of testimonial objects |

Each item in `items`:

| Key         | Type   | Required | Default | Description |
|-------------|--------|----------|---------|-------------|
| `quote`     | string | Yes      | —       | The testimonial text. Plain text — escaped, no HTML. |
| `author`    | string | No       | `''`    | Name of the person quoted |
| `role`      | string | No       | `''`    | The author's job title |
| `company`   | string | No       | `''`    | The author's company or organization |
| `image_url` | string | No       | `''`    | Optional avatar image URL |
| `image_alt` | string | No       | `''`    | Alt text for the avatar |

`layout` and `theme` are independent axes, same pattern as `grid` and `cta`: `layout` controls structure, `theme` controls background color.

## Usage

```php
pp_get_component('testimonials', [
    'title' => 'What our clients say',
    'items' => [
        [
            'quote'   => 'PromptingPress cut our page-build time in half.',
            'author'  => 'Jane Doe',
            'role'    => 'CEO',
            'company' => 'Acme Inc.',
        ],
        [
            'quote'  => 'The AI never touches raw HTML — it just works.',
            'author' => 'John Smith',
        ],
    ],
]);

// Single large pull-quote
pp_get_component('testimonials', [
    'layout' => 'stack',
    'items' => [
        ['quote' => 'The best WordPress theme for AI-first sites.', 'author' => 'Ada Lovelace'],
    ],
]);
```

## CSS

Styles in `assets/css/components.css` under the `COMPONENT: testimonials` block. Base `blockquote` styling (border-left accent, italic, muted color) lives in `base.css` since `blockquote` is a standard HTML element other components can pass through `wp_kses_post()`; the component overrides it for card presentation.
