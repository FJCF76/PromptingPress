# Component: table

Data or comparison table. Wraps in a `div.table-wrap` that scrolls horizontally
whenever the table is wider than the band — at **any** viewport, not only on mobile.
Column headers use `<th scope="col">` for accessibility.

## Props

| Prop      | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| `id`      | string | No       | `''`    | HTML id for anchor linking (e.g. navigation anchors) |
| `title`   | string | No       | `''`    | Section heading above the table |
| `headers` | array  | Yes      | —       | Array of column header strings (plain text) |
| `rows`    | array  | Yes      | —       | Array of rows, each an array of cell values. Cells are a **rich HTML** surface (`wp_kses_post`) |
| `caption` | string | No       | `''`    | Accessible `<caption>` element (recommended). Plain text |

## Usage

```php
pp_get_component('table', [
    'title'   => 'Theme Comparison',
    'caption' => 'Comparing PromptingPress to standard starter themes',
    'headers' => ['Feature', 'Normal Theme', 'PromptingPress'],
    'rows'    => [
        ['AI comprehension speed', 'Slow', 'Fast'],
        ['WP functions in templates', 'Yes', 'No — wrapped in lib/wp.php'],
        ['Component schemas', 'None', 'schema.json per component'],
        ['CI invariant checking', 'No', 'GitHub Actions workflow'],
    ],
]);
```

## Accessibility

- Column headers use the `scope="col"` attribute.
- Optional `caption` provides context for screen readers.
- The horizontal-scroll wrapper keeps a wide table usable inside any band width.

## Horizontal scroll — the mechanism is viewport-independent

`.table-wrap` declares `overflow-x: auto` unconditionally, with **no media query**.
The table itself is `width: max-content; min-width: 100%`, so it grows to fit its
content and the wrapper scrolls whenever that exceeds the band. A wide table therefore
scrolls on a 1440px desktop exactly as it does on a 375px phone. Do not describe this
as a mobile behaviour and do not expect a breakpoint to switch it off.

`.table__header` carries `white-space: nowrap` and `.table__cell` deliberately does the
opposite (`white-space: normal; overflow-wrap: anywhere`). That asymmetry is the
design: headers set the column's minimum width, and cell prose wraps inside it.

## Style slots

6 per-instance style slots, declared in `schema.json` under `styling.style_slots`
and set with the `style_component` action. This table is the map — read each slot's
`type`, effective `default`, `applies_when` condition and full description from the
schema itself, or with `wp pp operate inspect-composition --post_id=<id>`.

`◦` = conditional (`applies_when`): setting it outside that configuration is accepted
and stored but paints nothing, and `wp pp check page` reports a non-blocking
`inert_slot` smell.

| Group | Slots |
|---|---|
| Band | `--table-padding-top` · `--table-padding-bottom` |
| Heading | `--table-heading-size` ◦ · `--table-heading-color` ◦ · `--table-heading-measure` ◦ · `--table-heading-margin-bottom` ◦ |

**There is no `--table-bg`, and the table's own text surface has no slots.** That is a
scheduled absence, not an oversight — see below.

## Stated defaults (and what would reopen them)

These values are deliberate product defaults, not oversights, and are not authorable.
Each names the condition that would reopen the decision. Adding a control needs a
**named incident** — a real composition that could not be built — not a hypothesis.

| Default | Why it is a default | What would reopen it |
|---|---|---|
| `.table { font-size: 0.9375rem }` | A data table is a **scanning surface, not a prose surface**, so its type sits one step below body — more columns fit before the horizontal scroll engages, which is the whole point of a comparison table. | Bound to the deferred band-background gate below, not independently open. |
| `.table__caption { font-size: 0.875rem }` — **size only** | Row above. The split is explicit because the two halves have different owners: the caption's **colour** (`var(--color-muted)`, measured 3.09:1) belongs to the deferred gate's ink-safety criterion, and only the **size** is settled here. | As above. |
| `.table__header { font-weight: 700 }` | The head's only distinction from the body is weight plus the `--color-surface` fill — there is no header background slot and no header ink slot, so **weight carries the entire hierarchy**. Lowering it would leave the head unmarked. | As above. |
| Rule widths — header `2px`, row `1px`, wrapper `1px` | All three route `var(--color-border)`, so a site-wide rule-colour retune already works with one `update_design_token` write. Only the **widths** are literal, and the 2px/1px pair is not decoration — it **is** the header/body distinction. The wrapper's `border-radius: var(--radius)` is token-routed for the same reason. | A borderless or heavily-ruled table style is requested. |
| Cell density `var(--space-sm) var(--space-md)` on header, cell and caption | All three resolve to `--space-*` tokens, so the whole table retunes site-wide with one write. | A compact/comfortable **density mode** is requested — which would be a prop enum, not a style slot. |
| `.table__header { white-space: nowrap }` | Already a ratified intentional difference: `table`'s body is a scrolling data table, not a measured prose surface, and this declaration is the mechanism behind the scroll. | That intentional difference's own condition. |

### The deferred band-background gate

`--table-bg` **does not exist**, and neither do `--embed-bg` or `--logos-bg`. `table`
declares no `theme` prop and no theme variant classes at all, so there is no band
background to paint and no inverted branch for its ink to route through.

This is one decision, deferred as a family. Its **entry criterion** is: if `table` ever
gains a `theme` prop or a band background, its whole text surface — body type, caption
ink, header fill and header ink — needs slots **in the same change**. Shipping the
typography half first would complete one member of a family and leave an author able to
paint a band they cannot make readable. That is why the type literals above are stated
defaults rather than candidates for a quick slot.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: table === */`.

Row hover background uses `--color-surface`.
