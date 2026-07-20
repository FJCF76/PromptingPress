# Component: cta

Call-to-action block. Place at the bottom of a page or between sections to drive a conversion action.

## Props

| Prop          | Type   | Required | Default        | Description |
|---------------|--------|----------|----------------|-------------|
| `id`          | string | No       | `''`           | HTML id for anchor linking |
| `title`       | string | Yes      | —              | CTA headline |
| `title_accent`| string | No       | `''`           | Exact substring of `title` to render in an accent color |
| `eyebrow`     | string | No       | `''`           | Short kicker/label rendered as a pill above the title |
| `text`        | string | No       | `''`           | Supporting body text |
| `button_text` | string | Yes      | —              | Button label |
| `button_url`  | string | Yes      | —              | Button URL |
| `layout`     | enum   | No       | `'full-width'` | Structural layout: `full-width` / `inline` |
| `theme`       | enum   | No       | `'default'`    | Background color: `default` / `muted` (light tinted surface band) / `inverted` (genuinely dark band) (independent of `layout`) |
| `background_image` | string | No | `''`           | Optional background image URL with dark overlay for text readability |
| `button_variant` | enum | No      | `'primary'`    | Button style: `primary` / `secondary` / `outline` / `ghost` |

## Layouts

- **full-width** — Centered block with `--color-surface` background. Used at section breaks.
- **inline** — Flex row: text on left, button on right. Used for inline nudges (e.g. "back to archive").

## Button variants (`button_variant`)

Selects the shared button style. Set as a prop via `update_component`.

- **primary** — Filled accent button (the bare `.btn`).
- **secondary** — Muted surface fill for lower-emphasis actions.
- **outline** — Accent border, transparent fill.
- **ghost** — Borderless text-style button for tertiary actions.

Per-instance button color is still set through the `--cta-accent` style slot.

## Usage

```php
// End-of-page conversion block
pp_get_component('cta', [
    'title'       => 'Ready to build your AI-ready site?',
    'text'        => 'Start with the theme, fill in AI_CONTEXT.md, and let your AI tool do the rest.',
    'button_text' => 'Get Started on GitHub',
    'button_url'  => 'https://github.com/FJCF76/PromptingPress',
    'layout'     => 'full-width',
]);

// Inline back link on single post
pp_get_component('cta', [
    'title'       => 'More from the blog',
    'button_text' => '← Back to all posts',
    'button_url'  => pp_site_url('/blog'),
    'layout'     => 'inline',
]);
```

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: cta === */`.
