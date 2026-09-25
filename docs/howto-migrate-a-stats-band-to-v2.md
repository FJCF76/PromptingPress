# How to migrate a `stats` band to the design contract

`stats` moved to the Universal Design Contract at #1066. Its **seventeen** style slots and
**both** styling props are retired; every designable value is now a role in the band's `udc`
map.

> **⚠ CLEAR THE STORED STYLE MAP FIRST, IF THERE IS ONE.** A band still holding its v1
> `style` map cannot be edited at all since #1101 — not restyled, *edited*: a props-only
> change meets `invalid_style_slot` naming a key you never mentioned. That refusal blocks
> every step below. It takes one command to clear:
> **`docs/howto-clear-a-stored-v1-style-map.md`**. Do that, then come back here.

**This is the largest slot map any component ever declared, and the migration is still mostly
renames.** The parts that are not renames are Steps 4, 5 and 6, and they are where a band can
silently get worse rather than merely different. Read those even if you skim the rest.

If your band sets no slots, no `theme` and no `background_image`, you have nothing to do and
it renders identically.

Read `docs/tutorial-style-a-band-on-the-design-contract.md` first if you have not written a
`udc` map before.

## Prerequisites

- The band needs a stable `id`. The engine mints one on write; a band that reached storage
  without one (raw meta, or a restore) renders structurally and takes no design until it has
  one.
- `wp pp check page <id>` tells you what the page currently stores.

## Step 1: Find out what is actually broken

A stored `--stats-*` slot is not read at RENDER: the band paints as though it were never set,
and no read surface says so (#1050). A band that stored `theme: "inverted"` renders **light**,
and one that stored a `background_image` renders with **no image**. **The stored SLOT map is
not ignored at WRITE, though** — since #1101 it refuses every edit to the band, a props-only
one included. Clear it first; see the note at the top of this guide.

```
wp pp check page 42
```

## Step 2: The slots, thirteen plain renames

| v1 slot | v2 address |
|---|---|
| `--stats-padding-top` | `_band` → `spacing.padding-top` |
| `--stats-padding-bottom` | `_band` → `spacing.padding-bottom` |
| `--stats-bg` | `_band` → `background.fill` |
| `--stats-radius` | `_band` → `border.radius` |
| `--stats-max-width` | `_band` → `sizing.max-width` — **read Step 6** |
| `--stats-heading-size` | `heading` → `typography.size` |
| `--stats-heading-measure` | `heading` → `sizing.max-width` |
| `--stats-heading-margin-bottom` | `heading` → `spacing.margin-bottom` |
| `--stats-heading-color` | `heading` → `typography.color` — **read Step 3** |
| `--stats-heading-accent-color` | `heading-accent` → `typography.color` |
| `--stats-number-size` | `number` → `typography.size` |
| `--stats-number-weight` | `number` → `typography.weight` — **read Step 7** |
| `--stats-number-color` | `number` → `typography.color` |
| `--stats-label-color` | `label` → `typography.color` |
| `--stats-number-font` | `number` → `typography.family` — **read Step 3** |
| `--stats-bg-position` | `_band` → `background.position` |
| `--stats-overlay-bg` | `_band` → `background.overlay` — **read Step 5** |

```json
{
  "udc": {
    "_band":   { "spacing": { "padding-top": "5rem", "padding-bottom": "5rem" } },
    "heading": { "typography": { "size": "2.4rem" }, "spacing": { "margin-bottom": "2rem" } },
    "number":  { "typography": { "size": "3rem" } }
  }
}
```

## Step 3: Two defaults changed, and both are deliberate

**`--stats-heading-color` fell back to a pinned `var(--color-text)`.** The `heading` role
defaults to `currentColor` instead. v1 needed *two* rules to do what one now does:
`.stats__heading` pinned the literal and `.stats--inverted .stats__heading` re-pointed it to
`@color-bg`. Both retire with the `theme` prop, and `currentColor` reproduces both — measured
rgb(16, 24, 40) on an unauthored band, byte-identical. If your band set an explicit colour,
port it as written.

**`--stats-number-font` has no replacement default, and that is not an omission.** v1
declared `font-family: var(--stats-number-font, inherit)`. An explicit `inherit` **is** a
declaration — but nothing in this theme declares `font-family` on a `<span>`, so the inherited
body face already lands and silence is byte-identical (measured `system-ui, sans-serif`
either way). The `number` role still declares `typography`, so port an explicit family to
`number` → `typography.family`.

## Step 4: A dark band is FOUR writes, not two

This is the step that catches people. Here is the stack:

```
.stats                    the BAND — transparent by default; you darken THIS
  .stats__heading         follows the band, via currentColor
  .stats__heading-accent  paints its OWN colour — does NOT follow the heading
  .stats__list
    .stats__item
      .stats__number      PINS @color-accent — a band ink write does not reach it
      .stats__label       PINS @color-muted  — a band ink write does not reach it
```

`_band` → `typography.color` reaches the heading and **nothing else you probably mean**,
because `number` and `label` are direct declarations on their elements — and a direct
declaration always beats an inherited value, whatever the cascade layer.

```json
{
  "udc": {
    "_band":  { "background": { "fill": "@color-bg-inverted" }, "typography": { "color": "@color-bg" } },
    "number": { "typography": { "color": "@color-accent-on-inverted" } },
    "label":  { "typography": { "color": "rgb(192, 195, 201)" } }
  }
}
```

**Why those two values.** v1 agreed with both and said so in CSS.
`.stats--inverted .stats__number` re-routed to `@color-accent-on-inverted` (8.33:1) because
the light-surface `@color-accent` measures only **3.23:1** there — passing the 3:1 large-text
bar and failing every smaller one. And `.stats--inverted .stats__label` set `@color-bg` at
`opacity: 0.75`.

**`opacity` is in none of the seven UDC groups**, so that de-emphasis ports as the
**pixel-measured composite `rgb(192, 195, 201)`** (10.11:1). That is the standing rule rather
than a workaround: this family's earlier `opacity: 0.85` was retired at #577 for measuring
3.87:1, replaced with `--color-muted-on-overlay`, and `base.css` records *"do NOT
re-introduce an opacity literal"* beside the token.

> **Why exactly `rgb(192, 195, 201)`, and the one case where a colour is not an opacity.**
> The composite of `#fcfdff` at 0.75 over `#0f172a` is (192.75, 195.5, 201.75), and Chromium
> **floors** each channel — which is why the measured pixel is 192/195/201 rather than the
> 193/196/202 that round-half-up predicts. It measures **10.11:1** on the inverted band.
>
> The substitution is exact for this label because it renders plain text and nothing else.
> It would not be exact in general: `opacity` dimmed the whole **box**, including any
> descendant element and any text decoration, while a colour reaches only the text it is set
> on. A caption that later carries a link or an icon needs that child re-inked too.

> **The fill and the ink are two writes, and setting only the fill is the common mistake.**
> `currentColor` follows the band's `typography.color`, not its `background.fill`. A dark fill
> with no ink leaves the heading on the inherited `@color-text`, at about **1.04:1**.

**`heading-accent` does not follow the heading.** It paints its own colour at (0,1,0), so
re-inking `heading` leaves the accent on the light-surface accent — 3.23:1 on a dark band.
Give it its own write if the accent must read at a smaller size.

## Step 5: The background image is an attachment id, the overlay div is gone, and nothing re-inks itself

`background_image` was a URL string. `_band` → `background.image` is a Media Library
**attachment id** (`import_media` returns one). The scrim is `background.overlay`, which
composes into the band's own background layer list — **no `.stats__overlay` element renders
any more**, exactly as hero's, section's and cta's stopped at their rebuilds.

**If the v1 band set `--stats-overlay-bg` but no background image**, do not carry the tint
to `background.overlay` on its own: v2 layers a scrim only over `background.image`, so an
overlay with nothing under it is dropped, and the write reports it as a
`udc_overlay_without_image` finding naming the role (#1117). Put that tint in
`background.fill` instead.

Two v1 behaviours that came free are explicit now: write `background.size: "cover"` and
`background.repeat: "no-repeat"`.

**And the three contrast corrections retire with the class.** v1 keyed three rules on
`.stats--has-bg-image` so an image automatically re-inked the band: `number` →
`@color-accent-on-overlay` (#461; bare `@color-accent` measures **1.16:1** over the worst-case
scrim), `heading-accent` → the same (#463), and `label` → `@color-muted-on-overlay` (#577).
v2 has no class-keyed remap — the engine cannot know an arbitrary image is dark. Since #1010
`number` and `heading-accent` do default to `@color-accent-on-overlay` on a band that paints
a scrim over its image; `heading` and `label` are yours. Writing all four in the same map, as
below, is still correct and pins them:

```json
{
  "udc": {
    "_band": {
      "background": { "image": 42, "overlay": "@overlay-bg", "size": "cover", "repeat": "no-repeat", "position": "center" },
      "typography": { "color": "@color-bg" }
    },
    "heading-accent": { "typography": { "color": "@color-accent-on-overlay" } },
    "number":         { "typography": { "color": "@color-accent-on-overlay" } },
    "label":          { "typography": { "color": "@color-muted-on-overlay" } }
  }
}
```

## Step 6: The contained card still works, and the auto margins are why

Issue 383's contained rounded metrics card was `--stats-max-width` plus `--stats-radius`. It
is `_band` → `sizing.max-width` plus `border.radius` now, and **neither is defaulted** — an
unset band stays full-bleed and square, byte-identically.

What *is* defaulted is the pair of auto side margins, and they are the half that is easy to
miss: a capped block only **centres** because of them. They are inert at `max-width: none`
(measured: `auto` computes to 0 on a full-bleed band), so they change nothing until you cap
the band — and without them every contained card would pin to the container's left edge.

```json
{ "udc": { "_band": { "sizing": { "max-width": "72rem" }, "border": { "radius": "12px" } } } }
```

## Step 7: `theme`, and one grammar that widened

| v1 `theme` | v2 |
|---|---|
| `default` | nothing to write — the `_band` defaults already paint nothing |
| `muted` | `_band` → `background.fill: "@color-surface"` **plus** `border.width-top` / `width-bottom` = `"1px"`, `style-top` / `style-bottom` = `"solid"`, `color` = `"@color-border"` |
| `inverted` | the four writes in Step 4 |

Use the per-edge `border.width-top` / `width-bottom`, never the `width` shorthand: the
shorthand emits all four edges, which on a full-bleed band draws hairlines down both viewport
edges.

**And `number` → `typography.weight` accepts more than the slot did.** `--stats-number-weight`
was a generically `number`-typed slot and refused `bold`; the role's parameter is a
purpose-built `font-weight` type and accepts the CSS keywords. `600px`, `heavy` and `-100` are
still refused. If you were working around the old refusal, you no longer need to.

## Clearing the stored slot map and the stored props — send every key in ONE call

The validator reports only the first problem per band, so clearing them one at a time takes
nineteen round trips (#1064). Send them together:

```json
{
  "action": "update_component",
  "post_id": 42,
  "component_index": 3,
  "props": { "theme": null, "background_image": null },
  "style": {
    "--stats-padding-top": null, "--stats-padding-bottom": null, "--stats-bg": null,
    "--stats-radius": null, "--stats-max-width": null, "--stats-heading-size": null,
    "--stats-heading-color": null, "--stats-heading-measure": null,
    "--stats-heading-margin-bottom": null, "--stats-heading-accent-color": null,
    "--stats-number-color": null, "--stats-number-size": null, "--stats-number-font": null,
    "--stats-number-weight": null, "--stats-label-color": null,
    "--stats-bg-position": null, "--stats-overlay-bg": null
  }
}
```

## Step 8: Verify

```
wp pp check page 42
```

The band should report no findings and no stored `style` keys. Then **look at it**, at 375px
especially: a long unbroken metric label can still push the row wider than the viewport
(#1067), which a role makes authorable but does not fix.

## What you cannot express, and what to do instead

- **`opacity` has no group — but since #1079 it is reachable through a role's `"_css"` map,
  and you should still not use it here.** Write the composite colour (Step 4). The engine
  will accept `{"label": {"_css": {"opacity": "0.75"}}}` and paint it; what it will not do
  is stop you dropping this label under the contrast floor. The ban is deliberate and it is
  now yours to keep — see the token note in `base.css`.
- **A background image cannot re-ink the band automatically.** That capability is gone on
  every v2 component, for the same reason. Write the inks.
- **Zebra striping and per-item tints are not expressible.** `:nth-child` is not a role
  selector and not one of the three states.

## Related

- `components/stats/README.md` — the full role table and every stated default
- `docs/howto-migrate-a-logos-band-to-v2.md` — its sibling, rebuilt in the same change
- `docs/explanation-cascade-layers.md` — why a role default beats the stylesheet
