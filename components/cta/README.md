# Component: cta

Call-to-action block. Place at the bottom of a page or between sections to drive a conversion action.

## Props

| Prop          | Type   | Required | Default        | Description |
|---------------|--------|----------|----------------|-------------|
| `id`          | string | No       | `''`           | HTML id for anchor linking |
| `title`       | string | Yes      | —              | CTA headline |
| `title_accent`| string | No       | `''`           | Exact substring of `title` to render in an accent color |
| `eyebrow`     | string | No       | `''`           | Short kicker/label rendered as a pill above the title |
| `text`        | string | No       | `''`           | Supporting body text. Inline HTML allowed: a, strong, em, br. |
| `button_text` | string | Yes      | —              | Button label |
| `button_url`  | string | Yes      | —              | Button URL |
| `button2_text` | string | No      | `''`           | Optional SECOND button label. Empty (default) renders no second button |
| `button2_url` | string | No       | `'#'`          | Second button URL |
| `button2_variant` | enum | No     | `'outline'`    | Second button style: `primary` / `secondary` / `outline` / `ghost` |
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

## Second button (`button2_*`)

A closing CTA can offer a primary + secondary pair — "Ver planes" *and* "Hablar con
nosotros" — instead of forcing a choice between them. Set `button2_text` (and
`button2_url`); `button2_variant` defaults to `outline` so the pair reads as one
filled action and one outlined action.

```php
pp_get_component('cta', [
    'title'        => 'Listo para empezar',
    'button_text'  => 'Ver planes',
    'button_url'   => '/precios',
    'button2_text' => 'Hablar con nosotros',
    'button2_url'  => '/contacto',
]);
```

Leave `button2_text` unset and the CTA renders exactly as a single-button CTA always
has — no wrapper element, no second anchor, no style change.

The two buttons are styled independently in their RESTING state: `--cta-button-bg`,
`--cta-button-color` and `--cta-button-shadow` address the primary only, and the
`--cta-button2-*` slots address the second only, so flattening the primary to a brand
color leaves the second button on the default premium treatment. `--cta-accent` still
reaches both by design.

Two hover caveats, both shared with the hero: a flat per-instance fill reverts to the
shared premium gradient on hover, and the primary's `--cta-button-hover-bg` is NOT
isolated, so setting it also clears the second button's hover gradient. Set
`--cta-button2-hover-bg` alongside it when the pair needs distinct hover fills.

At mobile widths the pair stacks one button per row.

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
