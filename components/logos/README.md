# Component: logos

A flex-wrap image grid. Works for client logo strips (no labels) and icon-category tiles
(with labels). Items are always image-based; labels are optional and per item.

**On the Universal Design Contract since #1066.** It declares **8 roles and zero style
slots**; its eight `--logos-*` slots and its `theme` prop are retired.

## Props

| Prop | Type | Required | Notes |
|---|---|---|---|
| `id` | string | no | Stable band id. The engine mints one on write if absent. |
| `title` | string | no | Band heading. Plain text — HTML is escaped. |
| `items` | array | yes | `[{image_url, image_alt, image_id?, label?}]`. An item with no `image_url` renders nothing. |

`theme` is **retired** — see "Retired props" below.

## Roles

| Role | Selector | What it is |
|---|---|---|
| `_band` | *(the band root)* | The `<section>`. Padding, background, and the ink the heading follows. |
| `heading` | `.logos__heading` | The `<h2>`. **Not centred** — see below. |
| `list` | `.logos__list` | The strip. Its gap only. |
| `item` | `.logos__item` | One logo cell. Declares nothing by default. |
| `item-labeled` | `.logos__item--labeled` | A cell carrying a caption under its image. |
| `image` | `.logos__image` | The logo in an **unlabelled** cell. |
| `image-labeled` | `.logos__item--labeled .logos__image` | The logo in a **labelled** tile. |
| `label` | `.logos__label` | The caption under a labelled logo. |

**Which roles carry `layout` (#1084):** `list`, `item`. Exposure is a box fact — a role carries the group when its own structural CSS makes the box a flex or grid container — and this list is checked against a derivation from the stylesheet (tests/js/css-lint.test.js), in both directions, so it cannot drift from the schema or the CSS. `align-self` is NOT in this group: a box placing ITSELF is `sizing.align-self`, available on any role with `sizing`.

## Stated defaults (and what would reopen them)

Measured in Chromium at 375/768/1280 before the slots were retired.

- **`_band` paints nothing.** Measured `rgba(0, 0, 0, 0)` with no border on any edge. Both
  belonged to `.logos--dark` / `.logos--inverted`, the retired `theme` variants.
- **Band padding is the fluid `@pp-band-padding` clamp** (measured 53.6 / 68 / 76.8px).
- **`heading` is NOT centred, and stats' is.** Measured `text-align: start` with
  `margin-left: 0` at every tier, so this role declares neither an `align` nor auto side
  margins. The two components look like twins and differ here; the asymmetry is declared
  rather than quietly flattened. An author who wants the stats treatment writes
  `typography.align: "center"` plus both `spacing.margin-*: "auto"` and gets exactly it.
- **`heading` is `currentColor`.** v1 pinned `@color-text` and re-pointed to `@color-bg`
  from `.logos--inverted`; both retire with the `theme` prop, and `currentColor` reproduces
  both — measured rgb(16, 24, 40) on an unauthored band, byte-identical.
- **`heading` declares no weight and no leading** (measured 650 / 46.08px, both from
  base.css's `h2` rule, not logos' own block).
- **`item` declares nothing.** Its v1 rule is `display: flex; align-items: center;
  justify-content: center` — three structural properties and no design value at all. The
  role exists so a cell is reachable (a border, a padded tile, a hover) without the schema
  pretending it ships a look it does not.

## The two image caps are two roles, and that is a capability change

v1 routed **one** slot (`--logos-image-size`) at **both** cap sites with different
fallbacks — 3rem unlabelled, 2.5rem labelled — so setting it collapsed the label-driven
switch deliberately. The schema called two knobs for one visual job "a family this gate is
completing, not extending."

A role carries exactly one default, and `max-height` is a sizing value the fail-closed
structural-CSS lint will not let stay in the stylesheet. So preserving the measured switch
**requires** two roles, and it now survives by **specificity** rather than by fallback:
`.logos__item--labeled .logos__image` is (0,2,0) against `.logos__image`'s (0,1,0).

- Rendered default is **byte-identical** to v1 (measured 48px plain / 40px labelled).
- What changed: the caps are independently authorable, so "make the logos bigger" is two
  writes rather than one, and setting `image` no longer touches labelled tiles.

### The ratified literals, and what would reopen each

- **`item-labeled` → `sizing.min-width: 6rem`** (measured 96px). Keeps a labelled tile from
  collapsing narrower than its caption. **What would reopen it:** a strip whose labels are
  long enough that 6rem still wraps them mid-word at 375px.
- **`item-labeled` → `spacing.gap: @space-sm`** (measured 8px). The image-to-label nudge,
  deliberately NOT the strip's rhythm — that is `list` → `spacing.gap`. **What would reopen
  it:** a tile design where the caption reads as a separate element rather than as part of
  the tile.
- **The `muted` framing is `1px solid var(--color-border)` top and bottom**, which is what
  the retired `theme: "muted"` variant painted and what the retired-prop route names. It is
  a literal rather than a token pair because only two components ever drew it. **What would
  reopen it:** a third component needing the same framing, at which point it earns a token.
- **`label` → `typography.size: 0.8125rem`** (13px), one step below the stats caption
  because a logo label is a category name rather than a sentence. **What would reopen it:**
  a measured legibility complaint, or labels being used as running copy.

## A dark logos band is THREE writes

`_band` → `typography.color` reaches the heading through `currentColor` and **not** the
labels: `label` pins `@color-muted` as a direct declaration on the element, and a direct
declaration always beats an inherited value.

```json
{
  "udc": {
    "_band": { "background": { "fill": "@color-bg-inverted" }, "typography": { "color": "@color-bg" } },
    "label": { "typography": { "color": "rgb(192, 195, 201)" } }
  }
}
```

**Why that colour.** v1's `.logos--inverted .logos__label` set `@color-bg` at
`opacity: 0.75`. `opacity` is in none of the seven UDC groups, and since #1079 a role's
`"_css"` map reaches it anyway — so this is a rule you keep, not one the engine keeps for
you. **Do not port the alpha.** The de-emphasis ports as
the **pixel-measured composite rgb(192, 195, 201)** (measured 10.11:1; the composite is 192.75/195.5/201.75 and Chromium floors each channel). `@color-muted` itself
measures about **3.1:1** on `@color-bg-inverted` — under the 4.5:1 floor at this 13px size —
so a dark band genuinely owes this role a write.

**The logo images are not one of the writes.** Nothing in the theme ever re-inked them, and
nothing does now: a dark strip of dark logos was as much the author's problem on v1 as it is
here. That is a content decision, not a styling one.

> **The fill and the ink are two writes.** A dark fill with no `typography.color` leaves the
> heading on the inherited `@color-text`, at about **1.04:1**.

## Retired props

| Retired | Write this instead |
|---|---|
| `theme` | `_band` → `background.fill`, plus `typography.color`, plus `border.width-top` / `width-bottom` at 1px solid `@color-border` for the `muted` framing. And `label` ink — the band write does not reach it. |

Refused with `retired_prop` and the route. Clear a stored one by sending it as `null`
through `update_component`.

## The deferred band-background gate

`--logos-bg` **does not exist**, and this component's own retired `muted` variant painted
`--color-surface` directly. Since #1066 that gate is about **logos alone** — `--embed-bg`
and `--table-bg` never existed either, but both of those components are on the contract now,
where a band tone is `_band` → `background.fill`, a real capability rather than a withheld
one.

Its **entry criterion**, unchanged: if `--logos-bg` is ever shipped, the framing borders
must route a slot in the same change. An author who paints the band a new colour and gets
framing borders they cannot retune is worse off than one who cannot paint it at all.

## Retired style slots

All eight. The migration is in `docs/howto-migrate-a-logos-band-to-v2.md`; each slot's
replacement is recorded in `SLOT_RENAME_MIGRATION_NOTES`.

## CSS

`assets/css/components.css` keeps **four** structural rules: `.logos__list`, `.logos__item`,
`.logos__item--labeled` (its `flex-direction: column` — what makes it a tile rather than a
cell) and `.logos__image` (`width: auto`, `object-fit: contain`). The zeroed list reset
stays because it undoes UA chrome.

`object-fit: contain` is why logos exposes **no** focal point and **no** aspect ratio: it is
a **fit** model, so a client logo is shown whole. That is the deliberate contrast with the
testimonials avatar, which is a **crop** model.

## What NOT to change

- Do not fold `image` and `image-labeled` back into one role. The measured caps differ.
- Do not give `heading` an `align` or auto margins to "match stats". Measured, it is
  start-aligned; the difference is the record.
- Do not add a `background.fill` default to `_band`. v1 measured transparent.
- Do not re-introduce an `opacity` literal for the dark-band label. Write the composite.
