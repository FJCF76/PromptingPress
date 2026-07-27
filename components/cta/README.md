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

On a dark band (`theme: "inverted"` or a `background_image`) the transparent-fill
variants paint their ink and ring straight onto the band, where the light-surface
accent fails WCAG AA. Both buttons' `outline`/`ghost` defaults therefore fall back to
the band's accent role (`--color-accent-on-inverted` / `--color-accent-on-overlay`)
instead, so they are readable without setting anything; `--cta-button-color` /
`--cta-button-border` (and the `--cta-button2-*` pair) still win when set. Resting
state only — on hover each variant paints its own contrasting fill. On a
`background_image` band every filled button — the primary and `button2` alike —
DEFAULTS its border to that role so its shape stays visible against the band and an
unstyled `primary` + `primary` pair reads as a matched pair. The role is the last link
in the chain: `--cta-button2-border`, the global `--btn-border-color`, and the fill
chain (`--cta-button2-bg` → `--cta-accent` → `--btn-bg`) all still win ahead of it, so a
ring you author keeps the colour you gave it. The solid `inverted` band does not get
this ring (its fill already clears the 3:1 non-text bar); a band carrying both classes
does, because the overlay role is the safe one over an arbitrary image.

The focus ring follows the same routing, for EVERY variant including the filled one:
it is drawn outside the button, so it lands on the band, where the bare accent measured
3.23:1 (inverted) and 1.17:1 (over the worst-case scrim) against the 3:1 that WCAG
1.4.11 requires. Keyboard focus is the case that motivated it, but the routing attaches
to `:focus`, so it recolours whatever ring the button already paints — on a composed
button that includes the one a pointer click paints. Only the COLOUR changes: width,
style and offset are untouched, and a light-band cta's focus ring is unchanged.

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

Hover is a separate surface, isolated the same way (issue 530): `--cta-button-hover-bg`
addresses the primary only and `--cta-button2-hover-bg` the second only, and on a filled
button each replaces the shared premium hover gradient with a flat fill. A resting slot
governs the resting state only, so pair each with its hover counterpart when a button
should stay on-brand through the hover; left unset, hover keeps the premium gradient.

On the filled SECOND button the hover BORDER follows that hover fill when neither
`--cta-button2-hover-border` nor `--cta-accent-hover` is set, so a hover-fill-only recolor
keeps a matching ring (issue 538). Either of those two slots still wins where set — note
the hover chain resolves `--cta-accent-hover` BEFORE the fill, while the resting chain
resolves `--cta-button2-bg` before `--cta-accent`, so a CTA with accent knobs set can match
its fill at rest and return to the accent on hover. The `outline`, `ghost` and `secondary`
variants do not follow the hover fill; give those `--cta-button2-hover-border` explicitly.

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
