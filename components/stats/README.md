# Component: stats

A horizontal row of large-number metrics with labels. Use for quantified social proof or credential statements on marketing pages.

## Props

| Prop            | Type   | Required | Default | Description |
|-----------------|--------|----------|---------|-------------|
| `id`            | string | No       | `''`    | HTML id for anchor linking |
| `title`         | string | No       | `''`    | Optional heading above the stats row |
| `title_accent`  | string | No       | `''`    | Exact substring of `title` to render in an accent color |
| `theme`         | enum   | No       | `'default'` | Background color/tone: `default` / `muted` (light tinted surface band) / `inverted` (genuinely dark band) |
| `background_image` | string | No  | `''`    | Optional background image URL with dark overlay for text readability |
| `items`         | array  | Yes      | —       | Array of `{ number, label }` objects |

Each item:

| Key      | Type   | Required | Description |
|----------|--------|----------|-------------|
| `number` | string | Yes      | The metric value, e.g. `'+30'` or `'100+'` |
| `label`  | string | Yes      | The metric label, e.g. `'Years of experience'` |

## Usage

```php
pp_get_component('stats', [
    'items' => [
        ['number' => '+30', 'label' => 'Years of experience'],
        ['number' => '100+', 'label' => 'Satisfied clients'],
        ['number' => '15',   'label' => 'Countries'],
        ['number' => '500+', 'label' => 'Candidates interviewed'],
    ],
]);
```

## Style slots

17 per-instance style slots, declared in `schema.json` under `styling.style_slots`
and set with the `style_component` action. This table is the map — read each slot's
`type`, effective `default`, `applies_when` condition and full description from the
schema itself, or with `wp pp operate inspect-composition <page>`.

`◦` = conditional (`applies_when`): setting it outside that configuration is accepted
and stored but paints nothing, and `wp pp check page` reports a non-blocking
`inert_slot` smell.

| Group | Slots |
|---|---|
| Band | `--stats-padding-top` · `--stats-padding-bottom` · `--stats-bg` · `--stats-radius` · `--stats-max-width` · `--stats-bg-position` ◦ · `--stats-overlay-bg` ◦ |
| Heading | `--stats-heading-size` ◦ · `--stats-heading-color` ◦ · `--stats-heading-accent-color` ◦ · `--stats-heading-measure` ◦ · `--stats-heading-margin-bottom` ◦ |
| Number and label | `--stats-number-color` ◦ · `--stats-number-size` ◦ · `--stats-number-font` ◦ · `--stats-number-weight` ◦ · `--stats-label-color` ◦ |

`--stats-radius` and `--stats-max-width` are the contained-rounded-metrics-card pair —
set both together. `--stats-max-width` is `length-or-none` (default `none`), so the
built-in full-bleed band stays authorable; every other length slot here rejects `none`.
Stats deliberately exposes no `*-border-*` and no `*-shadow` slots.

## Stated defaults (and what would reopen them)

These values are deliberate product defaults, not oversights, and are not authorable.
Each names the condition that would reopen the decision. Adding a control needs a
**named incident** — a real composition that could not be built — not a hypothesis.

| Default | Why it is a default | What would reopen it |
|---|---|---|
| `.stats__item { min-width: 8rem }` | This is a **wrap mechanism, not a size**. It decides how many stats fit per row and is what stops a two-digit stat and a six-digit stat producing ragged columns. Note that `--stats-max-width` governs the **band**, not the item, so it does not reach this. | An operator with long stat labels gets a wrap point they did not want at a viewport they care about. |
| `.stats__label { font-size: 0.875rem }` | The number is the band's display element, which is why `--stats-number-*` carries four slots; the label is deliberately a fixed secondary caption beneath it, **and its ink is already authorable** via `--stats-label-color`. | **Concrete and likely:** an author sets `--stats-number-size` (which is authorable) and finds the label no longer proportionate to it. Authoring one half of a pair and being unable to move the other is exactly the shape of incident the bar accepts. |

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: stats === */`.
