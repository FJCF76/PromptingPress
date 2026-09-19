# How to migrate a `table` band to the design contract

`table` moved to the Universal Design Contract at #1066. Its six style slots are retired; every
designable value is now a role in the band's `udc` map.

**This is the shortest migration in the program and the one most likely to surprise you.** table
retires no prop at all — it never had a `theme` — so there is no tone bundle to translate and
nothing to un-learn about variants. What it does have is the opposite problem: v1 gave it six
slots for a component with eleven designable elements, so most of what you can now set had **no
authoring surface at all** before. The migration is six renames; the interesting part is
everything that was never a slot.

If your band sets none of the six, you have nothing to do and the band renders identically.

Read `docs/tutorial-style-a-band-on-the-design-contract.md` first if you have not written a `udc`
map before.

## Prerequisites

- The band needs a stable `id`. The engine mints one on write; a band that reached storage
  without one (raw meta, or a restore) renders structurally and takes no design until it has one.
- `wp pp check page <id>` tells you what the page currently stores.

## Step 1: Find out what is actually broken

Nothing is *broken*. A stored `--table-*` slot is simply not read any more: the band renders as
though it were never set, and no read surface says so (#1050). That is the whole reason to
migrate rather than leave it.

```
wp pp check page 42
```

If the band stores a `style` map with `--table-*` keys, those are the six below.

## Step 2: The mapping, slot by slot

All six, and five are plain renames:

| v1 slot | v2 address |
|---|---|
| `--table-padding-top` | `_band` → `spacing.padding-top` |
| `--table-padding-bottom` | `_band` → `spacing.padding-bottom` |
| `--table-heading-size` | `heading` → `typography.size` |
| `--table-heading-measure` | `heading` → `sizing.max-width` |
| `--table-heading-margin-bottom` | `heading` → `spacing.margin-bottom` |
| `--table-heading-color` | `heading` → `typography.color` — **read Step 3 first** |

```json
{
  "udc": {
    "_band":   { "spacing": { "padding-top": "5rem", "padding-bottom": "5rem" } },
    "heading": {
      "typography": { "size": "2.4rem" },
      "spacing":    { "margin-bottom": "2rem" },
      "sizing":     { "max-width": "36rem" }
    }
  }
}
```

## Step 3: The heading's default changed, and that is deliberate

`--table-heading-color` fell back to a pinned `var(--color-text)`. The `heading` role defaults to
`currentColor` instead.

On every band v1 could render, those are **the same colour**. v1 could not make a dark table band
at all — no `theme` prop, no variant classes — so `@color-text` was the only thing this heading
ever showed. They diverge only on a band you darken yourself, which v2 makes trivial and which
the pinned literal would have stranded at **1.006:1**: invisible.

So if your band set `--table-heading-color` to a specific colour, port it as written and it keeps
working. If it did not, do nothing: the heading now follows the band's ink, which is what you
want.

## Step 4: The table paints its own light surface, and that is the whole of a dark band

This is the step that catches people, so here is the stack:

```
.table-section              the BAND — transparent by default; you darken THIS
  .table-section__heading   on the BAND fill      -> follows, via currentColor
  .table-wrap               the frame
    .table                  fill @color-bg        LIGHT, stays light
      caption               OUTSIDE the table's background box -> on the BAND fill
      thead .table__head    fill @color-surface   LIGHT, stays light
        th .table__header   on the LIGHT head fill  -> ink PINS
      tbody tr .table__row
        td .table__cell     on the LIGHT table fill -> ink PINS
  .table-section__empty     on the BAND fill
```

Darkening `_band` leaves the table itself light — by design, the same way faq keeps its accordion
items light on a dark band. So:

- **Do not re-ink `header` or `cell`.** They sit on the table's own light fill. Lightening them
  puts light text on a light table.
- **Do re-ink `caption` and `empty`.** Both sit on the *band* fill and both pin `@color-muted`,
  which measures about **3.1:1** on `@color-bg-inverted` — under the 4.5:1 floor.

`caption` is the one nobody expects, and the reason is structural: a `<caption>` box renders
**outside** the table's background box (CSS 2.1 §17.4), so `table` → `background.fill` never
paints behind it.

> **The fill and the ink are two writes, and setting only the fill is the common mistake.**
> `currentColor` on the `heading` role follows the band's `typography.color`, not its
> `background.fill`. Give a band a dark fill and no ink and the heading keeps the inherited
> `@color-text`, rendering at about **1.04:1**. Write both together.

**A dark table band is three writes:**

```json
{
  "udc": {
    "_band":   { "background": { "fill": "@color-bg-inverted" }, "typography": { "color": "@color-bg" } },
    "caption": { "typography": { "color": "@color-bg" } },
    "empty":   { "typography": { "color": "@color-muted-on-overlay" } }
  }
}
```

If you *also* darken `table` → `background.fill` or `head` → `background.fill`, then you owe
`cell`, `header` **and** `cell-link` ink in the same write — and `caption` is not part of that
group, because it was never on those surfaces.

## Step 5: Links in cells need their own write

A cell takes rich HTML, so links are ordinary content. `cell` → `typography.color` does **not**
reach them: it lands on the `<td>` and an `<a>` gets its colour from `base.css`'s own `a` rule,
which is a direct declaration on the element — an inherited value can never beat one.

The `cell-link` role is the address. It ships with **no defaults**, deliberately, so it costs
nothing until you use it:

```json
{ "udc": { "cell-link": { "typography": { "color": "@color-accent-on-inverted" } } } }
```

> **This reaches an `<a class="btn">` in a cell too.** The write emits unlayered at
> `.table__cell a`, which matches a composed button as well as a prose link, in both states — v1
> had no equivalent, because its class rules lost to `main .btn:not(...)`. Measured, an
> on-inverted ink on the premium gradient is **2.58:1** against the button's own 5.37:1 label. The
> selector cannot be narrowed to `a:not(.btn)` (the role-selector charset admits neither `:` nor
> `(`), so keep composed buttons out of cells whose links you recolour, or accept the shared ink.

## Step 6: Borders collapse, and that is new

The row separator moved from the bottom edge to the **top** edge. Under
`border-collapse: collapse` adjacent edges merge and the wider wins, so the render is identical —
but three things are now possible that were not:

- **A width and a colour with no `style` paint nothing.** `border-style`'s initial value is
  `none`, the lowest priority in the collapse model. Always set `border.style-top` alongside
  `border.width-top`.
- **`row`'s top rule and `header`'s bottom rule share one edge.** `header`'s 2px wins today. Set
  `header` → `border.width-bottom: "1px"` and you create a tie, broken by *element* rank — the
  `<th>` wins and your `row` → `border.color-top` silently does nothing at that edge.
- **`row` → `border.style-top: "hidden"` erases the header's rule entirely.** `hidden` beats every
  width.

## Clearing the stored slot map — send every key in ONE call

A retired slot is refused one at a time, so clearing them key by key takes six round trips and
the refusal will not tell you that (#1064). Send them all together, each as `null`, through
`update_component`:

```json
{
  "action": "update_component",
  "post_id": 42,
  "component_index": 3,
  "style": {
    "--table-padding-top": null,
    "--table-padding-bottom": null,
    "--table-heading-size": null,
    "--table-heading-color": null,
    "--table-heading-measure": null,
    "--table-heading-margin-bottom": null
  }
}
```

## Step 7: Verify

```
wp pp check page 42
```

The band should report no findings and no stored `style` keys. Then look at it: a table is one of
the few bands where a wrong value is invisible until you scroll it sideways or open it on a
phone.

## What you cannot express, and what to do instead

- **`:last-child` and other structural pseudo-classes are not role selectors**, and they are not
  one of the three states (`:hover`, `:focus-visible`, `:active`). The row separator's top-edge
  move is how table keeps its last-row behaviour without one.
- **Zebra striping is not expressible.** `:nth-child` is in neither set. A per-row tint would need
  a ruling, not a workaround.
- **`caption-side` is structural.** Where the caption sits is not a value you retune.

## Related

- `components/table/README.md` — the full role table and every stated default
- `docs/explanation-cascade-layers.md` — why a role default beats the stylesheet
- `docs/tutorial-style-a-band-on-the-design-contract.md` — writing a `udc` map from scratch
- `docs/howto-migrate-a-section-band-to-v2.md` — the mechanics in full, if this is your first
