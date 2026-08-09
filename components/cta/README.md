# Component: cta

Call-to-action block. Place at the bottom of a page or between sections to drive a conversion action.

## Props

| Prop          | Type   | Required | Default        | Description |
|---------------|--------|----------|----------------|-------------|
| `id`          | string | No       | `''`           | HTML id for anchor linking |
| `title`       | string | No       | `''`           | Optional CTA headline. Omit it (along with `body`) to render a standalone button row with no heading element — the sanctioned heading-less button pattern (#294). `id`/anchor and all style slots still apply |
| `title_accent`| string | No       | `''`           | Exact substring of `title` to render in an accent color |
| `eyebrow`     | string | No       | `''`           | Short kicker/label rendered as a pill above the title |
| `body`        | string | No       | `''`           | Supporting body text. Inline HTML allowed: a, strong, em, br. |
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
in the chain: `--cta-button2-border`, `--cta-accent`, and this band's own fill
(`--cta-button2-bg`) all still win ahead of it, so a ring you author keeps
the colour you gave it. The site-wide button tokens are deliberately NOT in these chains:
`--btn-border-color` / `--btn-hover-border-color` left in #564 and `--btn-bg` /
`--btn-hover-bg` in #565. The role carries a measured 4.59:1 contrast guarantee, and a
site-wide retheme sitting above it cancelled that guarantee — the ring tokens directly, the
fill tokens through the border-follows-fill link. So the matching-ring behaviour is scoped
to fills you aim at THIS band: flatten it with `--cta-button2-bg` and the ring matches;
set `--btn-bg` site-wide and this band keeps the measured role. Set the per-instance ring
slot when you want a specific colour here. The solid `inverted` band does not get
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
should stay on-brand through the hover; left unset, hover falls through to the global
`--btn-hover-bg` (issue 539) and then to the premium gradient. That global knob is the
site-wide hover twin of `--btn-bg`, so a theme-level button retheme now survives the
pointer on both of this component's buttons without per-instance slots; the per-instance
slots above still win wherever they are set.

On a filled button — primary or second — the hover BORDER follows that button's hover fill
when neither its own hover-border slot (`--cta-button-hover-border` /
`--cta-button2-hover-border`) nor `--cta-accent-hover` is set, so a hover-fill-only recolor
keeps a matching ring (issues 538, 548). Either of those slots still wins where set — as
does the site-wide `--btn-hover-border-color`, which sits between them (issue 539) — and
both buttons resolve their chains in the same ORDER. Same order is not the same color: each
button reads its own hover-border and hover-fill slots, so the pair matches wherever the
winning link is a shared knob (`--cta-accent-hover`, `--btn-hover-border-color`, the theme
default) and differs wherever it is a per-button one. Note
the hover chain resolves `--cta-accent-hover` BEFORE the fill, while the resting chain
resolves `--cta-button-bg` / `--cta-button2-bg` before `--cta-accent`, so a CTA with accent
knobs set can match its fill at rest and return to the accent on hover. The `outline`,
`ghost` and `secondary` variants do not follow the hover fill; give those an explicit
hover-border slot.

At mobile widths the pair stacks one button per row.

## Usage

```php
// End-of-page conversion block
pp_get_component('cta', [
    'title'       => 'Ready to build your AI-ready site?',
    'body'        => 'Start with the theme, fill in AI_CONTEXT.md, and let your AI tool do the rest.',
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

## Style slots

40 per-instance style slots, declared in `schema.json` under `styling.style_slots`
and set with the `style_component` action. This table is the map — read each slot's
`type`, effective `default`, `applies_when` condition and full description from the
schema itself, or with `wp pp operate inspect-composition <page>`.

`◦` = conditional (`applies_when`): setting it outside that configuration is accepted
and stored but paints nothing, and `wp pp check page` reports a non-blocking
`inert_slot` smell.

| Group | Slots |
|---|---|
| Band | `--cta-padding-top` · `--cta-padding-bottom` · `--cta-bg` · `--cta-border-color` · `--cta-border-width` · `--cta-radius` · `--cta-shadow` · `--cta-overlay-bg` ◦ · `--cta-bg-position` ◦ |
| Heading | `--cta-heading-color` ◦ · `--cta-heading-accent-color` ◦ · `--cta-heading-size` ◦ · `--cta-heading-measure` · `--cta-heading-margin-bottom` ◦ |
| Eyebrow | `--cta-eyebrow-color` ◦ · `--cta-eyebrow-bg` ◦ · `--cta-eyebrow-radius` ◦ · `--cta-eyebrow-border-width` ◦ · `--cta-eyebrow-border-color` ◦ · `--cta-eyebrow-text-transform` ◦ |
| Accent | `--cta-accent` · `--cta-accent-hover` |
| Body | `--cta-body-color` · `--cta-body-size` · `--cta-body-measure` · `--cta-inner-gap` |
| Primary button | `--cta-button-bg` · `--cta-button-border` · `--cta-button-color` · `--cta-button-shadow` · `--cta-button-hover-bg` · `--cta-button-hover-border` · `--cta-button-hover-color` |
| Second button (`button2_text`) | `--cta-button2-bg` ◦ · `--cta-button2-border` ◦ · `--cta-button2-color` ◦ · `--cta-button2-shadow` ◦ · `--cta-button2-hover-bg` ◦ · `--cta-button2-hover-border` ◦ · `--cta-button2-hover-color` ◦ |

## Stated defaults (and what would reopen them)

These values are deliberate product defaults, not oversights, and are not authorable.
Each names the condition that would reopen the decision. Adding a control needs a
**named incident** — a real composition that could not be built — not a hypothesis.

| Default | Why it is a default | What would reopen it |
|---|---|---|
| `.cta__buttons { gap: var(--space-sm) }` — the space BETWEEN the two buttons | It already resolves to a design token, so it is retunable site-wide with one `update_design_token` write on `--space-sm`. That is the same argument that ruled per-instance item gaps intentionally absent across the theme: a token-reachable value is not an unreachable one. | A recorded incident where one band's button gap must diverge from the site-wide spacing scale. |

**`--cta-inner-gap` does NOT govern the space between the two buttons.** It is the gap on
`.cta__inner`, whose flex children are the text block and the button row — so it sets the
space BETWEEN the copy and the buttons, and nothing else. The eyebrow, title and body are
nested inside the text wrapper and are spaced by their own margins, not by this gap. An
author who sets `--cta-inner-gap` expecting the two buttons to move apart gets nothing;
that gap is the token-routed default in the table above.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: cta === */`.

## What NOT to change

- Do not call WordPress functions in `cta.php`. Use the `pp_*` wrappers from `lib/wp.php`.
- Do not add raw hex colors to component CSS. Use CSS variables from `base.css`.
- Do not modify `schema.json` without updating this README and `AI_CONTEXT.md`.
