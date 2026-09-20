# Component: stats

A row of large-number metrics with labels. Use for quantified social proof or at-a-glance
credential statements ("+30 years", "1,200 matters resolved").

**On the Universal Design Contract since #1066.** It declares **7 roles and zero style
slots**; its seventeen `--stats-*` slots and both styling props are retired. Every
designable value is a role parameter in the band's `udc` map, per breakpoint and per state.

## Props

| Prop | Type | Required | Notes |
|---|---|---|---|
| `id` | string | no | Stable band id. The engine mints one on write if absent. |
| `title` | string | no | Band heading. Plain text — HTML is escaped. |
| `title_accent` | string | no | A substring of `title` to wrap in the accent span. |
| `items` | array | yes | `[{number, label}]`. Both fields plain text. |

`theme` and `background_image` are **retired** — see "Retired props" below.

## Roles

| Role | Selector | What it is |
|---|---|---|
| `_band` | *(the band root)* | The `<section>`. Padding, background, the contained-card cap and radius, and the ink the heading follows. |
| `heading` | `.stats__heading` | The `<h2>`. The one heading in the theme whose **box** is centred, not just its text. |
| `heading-accent` | `.stats__heading-accent` | The accented substring `title_accent` produces. |
| `list` | `.stats__list` | The metrics row. Its two gaps only. |
| `item` | `.stats__item` | One metric — the `<li>` stacking a figure over its caption. |
| `number` | `.stats__number` | The large figure. |
| `label` | `.stats__label` | The caption under a figure. |

**Which roles carry `layout` (#1084):** `list`, `item`. A role carries the group when its own structural CSS makes the box a flex or grid container; the rule and its two clauses are in [the Layout contract](../../docs/v2/LAYOUT-GROUP-CONTRACT.md) §5, and a test checks this list against the stylesheet in both directions. `align-self` is NOT in this group: a box placing ITSELF is `sizing.align-self`, available on any role with `sizing`.

## Stated defaults (and what would reopen them)

Every default below is what v1 **rendered**, measured in Chromium at 375/768/1280 before
the slots were retired — not what the stylesheet declared.

- **`_band` paints nothing.** Measured `rgba(0, 0, 0, 0)` with 0px/none on all four edges,
  so there is no `background.fill` and no border default. Both belonged to `.stats--dark` /
  `.stats--inverted`, the retired `theme` variants.
- **The band padding is the fluid `@pp-band-padding` clamp**, not a literal. Chromium
  measured three different values (53.6 / 68 / 76.8px) from one token, which is why no
  breakpoint map is needed and why a literal would have frozen the band at one tier.
- **`_band` defaults auto side margins**, and they are not decoration: issue 383's contained
  rounded card is `sizing.max-width` plus `border.radius` here, and a capped block only
  CENTRES because those margins are auto. They are inert at the default `max-width: none`
  (measured: auto computes to 0 on a full-bleed band), so declaring them changes nothing
  until you cap the band — and omitting them would pin every contained card to the left edge.
- **`heading` is `currentColor`, not a pinned literal.** v1 needed two rules to do what one
  now does: `.stats__heading` pinned `@color-text` and `.stats--inverted .stats__heading`
  re-pointed it to `@color-bg`. Both retire with the `theme` prop, and `currentColor`
  reproduces both — measured rgb(16, 24, 40) on an unauthored band, byte-identical.
- **`heading` declares no weight and no leading.** Measured 650 and 46.08px, both from
  base.css's `h2` rule rather than stats' own block. Restating a global value as a role
  default would move it to the unlayered tier, where it would beat that rule in every state.
- **`number` declares no `family`.** v1 declared `font-family: var(--stats-number-font,
  inherit)`, and an explicit `inherit` **is** a declaration — but nothing in this theme
  declares font-family on a `<span>`, so the inherited body face already lands and silence
  is byte-identical (measured `system-ui, sans-serif` either way). The `typography` group is
  still declared, so a distinct numeral face is still authorable.
- **`number` keeps 700, not `@font-weight-heading`.** That token is 650, and a site with a
  distinct heading face is precisely the site whose rendered figures would move.

### The ratified literals, and what would reopen each

These are values with no token behind them. The bar for turning one into a control is a
NAMED INCIDENT — a real band that needed a different value — not a preference.

- **`item` → `sizing.min-width: 8rem`.** The wrap floor: it keeps a short metric ("98%")
  from collapsing narrower than its neighbours and makes the row wrap in even columns.
  Measured 128px. **What would reopen it:** a band whose captions are long enough that
  8rem forces a ragged two-column wrap at 375px, or a single-metric band where the floor
  is visible as dead space. Note this floor is also the component's overflow hazard — a
  single unbroken token longer than the item pushes the flex row wider than the viewport
  (the #1043 class, filed for this component as #1067).
- **`label` → `typography.size: 0.875rem`**, which emits `font-size: 0.875rem` (14px). Small enough to sit under the figure
  without competing with it, large enough to clear the 4.5:1 floor at `@color-muted`.
  **What would reopen it:** a measured legibility complaint at 375px, or a band using the
  caption as primary content rather than as a caption.
- **`number` → `typography.size: 2.5rem` and `weight: 700`.** The display figure's whole
  job is to be read across a room. **What would reopen it:** a site whose heading scale
  makes 2.5rem smaller than its own `h3`, which would invert the intended hierarchy.

## A dark stats band is FOUR writes, not two

This is the thing to know before darkening one. `_band` → `typography.color` reaches the
heading through `currentColor` and **nothing else you probably mean**, because `number` and
`label` pin their own colours as direct declarations — and a direct declaration always beats
an inherited value, whatever the cascade layer.

```json
{
  "udc": {
    "_band":  { "background": { "fill": "@color-bg-inverted" }, "typography": { "color": "@color-bg" } },
    "number": { "typography": { "color": "@color-accent-on-inverted" } },
    "label":  { "typography": { "color": "rgb(192, 195, 201)" } }
  }
}
```

**Why those two values specifically.** v1 agreed with both and said so in CSS:
`.stats--inverted .stats__number` re-routed to `@color-accent-on-inverted` (8.33:1) because
the light-surface `@color-accent` measures only **3.23:1** on that band — passing for large
text and failing every smaller size. And `.stats--inverted .stats__label` set `@color-bg` at
`opacity: 0.75`; `opacity` is in none of the seven UDC groups, and since #1079 a role's
`"_css"` map reaches it anyway — so **do not port the alpha**, even though the engine will
now take it. That de-emphasis ports as
the **pixel-measured composite rgb(192, 195, 201)** (measured 10.11:1; the composite is 192.75/195.5/201.75 and Chromium floors each channel). That is the standing rule
rather than a workaround — this family's earlier `opacity: 0.85` was retired at #577 for
measuring 3.87:1, replaced by `--color-muted-on-overlay`, and base.css records *"do NOT
re-introduce an opacity literal"* beside the token.

> **The fill and the ink are two writes, and setting only the fill is the common mistake.**
> `currentColor` follows the band's `typography.color`, not its `background.fill`. A dark
> fill with no ink leaves the heading on the inherited `@color-text`, at about **1.04:1**.

**`heading-accent` does not follow the heading.** It paints its own colour at (0,1,0) and
does not inherit one set on the `<h2>` above it, so re-inking `heading` leaves the accent on
the light-surface accent. On an authored dark band that is 3.23:1 — fine at this size,
wrong at any smaller one.

## A background image no longer re-inks the band

v1 keyed three contrast corrections on `.stats--has-bg-image` so an image automatically
re-inked the band: `number` → `@color-accent-on-overlay` (#461; bare `@color-accent`
measures **1.16:1** over the worst-case scrim), `heading-accent` → the same (#463), and
`label` → `@color-muted-on-overlay` (#577).

**All three retire with the class.** v2 has no automatic remap — the same as hero, section
and cta — because the engine cannot know an arbitrary image is dark. Setting a background
does not recolour anything; you write the ink:

```json
{
  "udc": {
    "_band":          { "background": { "image": 42, "overlay": "@overlay-bg", "size": "cover", "repeat": "no-repeat" },
                        "typography": { "color": "@color-bg" } },
    "heading-accent": { "typography": { "color": "@color-accent-on-overlay" } },
    "number":         { "typography": { "color": "@color-accent-on-overlay" } },
    "label":          { "typography": { "color": "@color-muted-on-overlay" } }
  }
}
```

The image is a Media Library **attachment id**, not a URL. The overlay `<div>` is gone —
`background.overlay` composes into the band's own background layer list — and `size: cover`
/ `repeat: no-repeat` came free on v1 and are explicit now.

## Retired props

| Retired | Write this instead |
|---|---|
| `theme` | `_band` → `background.fill`, plus `typography.color`, plus `border.width-top` / `width-bottom` for the `muted` framing. And `number` / `label` ink — the band write does not reach them. |
| `background_image` | `_band` → `background.image` (attachment id) with `background.overlay` and `background.position`, plus the four ink writes above. |

A write naming either is refused with `retired_prop` and the route. To clear a stored one,
send it as `null` through `update_component`; send every stale key on that band in the same
call, because the validator reports only the first problem per band.

## Retired style slots

All seventeen. The migration is in `docs/howto-migrate-a-stats-band-to-v2.md`; each slot's
replacement is recorded in `SLOT_RENAME_MIGRATION_NOTES` and guarded in both directions.

## CSS

`assets/css/components.css` keeps **two** structural rules — `.stats__list` and
`.stats__item` — and nothing else. The band rule itself is gone: its padding, background,
max-width, auto margins and radius are all `_band` parameters now. The zeroed list reset
(`padding: 0; margin: 0; list-style: none`) stays because it undoes UA chrome rather than
expressing rhythm; the rhythm is `list` → `spacing.row-gap` / `column-gap`, measured as the
asymmetric pair v1 rendered (32px row, 64px column).

## What NOT to change

- Do not add a `background.fill` default to `_band`. v1 measured transparent; a default
  would paint a surface onto every stats band on every site that upgrades.
- Do not re-ink `number` or `label` from `_band` and expect it to land. They pin.
- Do not restate base.css's `h2` weight or leading on `heading`.
- Do not re-introduce an `opacity` literal for the dark-band label. Write the composite.
