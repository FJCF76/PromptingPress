# How to migrate a `grid` band to the design contract

`grid` moved to the Universal Design Contract at #1101. Its **thirty-eight** style slots,
its three **recipes** and **six** props are retired; every designable value is now a role in
the band's `udc` map.

> **⚠ CLEAR THE STORED STYLE MAP FIRST, IF THERE IS ONE.** A band still holding its v1
> `style` map cannot be edited at all since #1101 — not restyled, *edited*: a props-only
> change meets `invalid_style_slot` naming a key you never mentioned. That refusal blocks
> every step below. It takes one command to clear:
> **`docs/howto-clear-a-stored-v1-style-map.md`**. Do that, then come back here.

**This is the last one.** `grid` was the final component on the slot system, so when this
migration is done nothing in the theme reads a style slot and `style_component` refuses every
component with `no_style_slots`.

**It is also the only component where a SINGLE CARD can differ from its siblings.** That is
BUILD-SPEC Addendum B, and Step 6 is the one part of this guide that has no equivalent in the
other seven how-tos. If you have a band where one card is dark, read Step 6 first.

If your band sets no slots, no `theme`, no `card_emphasis`, no `title_align`, no
`image_treatment` and no per-card `style`, you have nothing to do and it renders identically.

Read `docs/tutorial-style-a-band-on-the-design-contract.md` first if you have not written a
`udc` map before.

## Prerequisites

- The band needs a stable `id`. The engine mints one on write; a band that reached storage
  without one (raw meta, or a restore) renders structurally and takes no design until it has
  one.
- `wp pp check page <id>` tells you what the page currently stores.
- `wp pp schema grid` lists the eighteen roles, and — new at #1101 — an `item_roles` block
  naming the ten a single card may set.

## Step 1: Find out what is actually broken

A stored `--grid-*` slot is not read at RENDER: the band paints as though it were never set,
which is why the page looks fine. **At WRITE it is anything but ignored** — it refuses every
edit to the band, a props-only one included (#1101). Clear it first; see the note at the top
of this guide. A stored **prop** is refused too, by name, with the route that replaced it:

```
wp pp check page 42
```

A band holding `theme`, `title_align`, `card_emphasis`, `image_treatment`, `items[].style` or
`items[].text_role` reports `retired_prop` and blocks edits to **that band** until it is
cleared. Other bands on the page are unaffected (#1007).

## Step 2: The header, four plain renames

| slot | role -> group.parameter |
|---|---|
| `--grid-heading-color` | `heading` -> `typography.color` |
| `--grid-heading-size` | `heading` -> `typography.size` |
| `--grid-heading-margin-bottom` | `heading` -> `spacing.margin-bottom` |
| `--grid-subheading-margin-bottom` | `subheading` -> `spacing.margin-bottom` |

```json
{
  "udc": {
    "heading": { "typography": { "color": "#0f172a", "size": "2.25rem" } },
    "subheading": { "spacing": { "margin-bottom": "2rem" } }
  }
}
```

The eyebrow pill is the `eyebrow` role — its ink, fill, radius, border and casing are that
role's `typography` / `background` / `border` groups, with `typography.transform` carrying
the `uppercase` default.

## Step 3: The card, and the five-write rule that will bite you

`card` owns the panel: fill, border, radius, shadow, motion. `card-body` owns the PADDING —
not `card` — because a banner image is full-bleed to the card's edges while the text is inset.

**Darkening a card is FIVE writes, not one.** v1 kept cards light even on an inverted band —
measured, `card-title`, `card-text`, `card-bullets` and `card-link` rendered byte-identically
on a `default` and an `inverted` band — so all four PIN their inks rather than following the
card. Set the fill alone and you get dark text on a dark panel.

```json
{
  "udc": {
    "card": { "background": { "fill": "#14141F" }, "border": { "color": "#0A0A12" } },
    "card-title": { "typography": { "color": "#F2EEE5" } },
    "card-text": { "typography": { "color": "#E8E2D4" } },
    "card-bullets": { "typography": { "color": "#C9C3B6" } },
    "card-link": { "typography": { "color": "#9FB4FF", ":hover": { "color": "#C2D1FF" } } }
  }
}
```

The engine tells you if you miss one: `udc_item_value_shadowed_by_role_default` names every
role your ink did not reach. **Read it** — `@color-accent` on a `#14141F` card measures
**3.2:1**, under the 4.5:1 AA floor for the link's 0.9rem weight-600 text.

**The check-mark colour** was `--grid-item-bullet-color`; it is `card-bullets` ->
`marker.color` (#1028). The check is a `::before` no role addresses, so the param sets a
colour on the list that each check inherits. Unset, it takes the accent. It works per card
too, in an item's own `udc`:

```json
{ "card-bullets": { "marker": { "color": "#FF5C2E" } } }
```

## Step 4: `theme`, and the border trap inside it

| old value | v2 write |
|---|---|
| `default` | nothing — it painted no background and no border |
| `muted` | a 1px rule top and bottom |
| `inverted` | a dark fill plus the band ink |

```json
{
  "udc": {
    "_band": {
      "border": {
        "width-top": "1px", "width-bottom": "1px",
        "style-top": "solid", "style-bottom": "solid",
        "color": "@color-border"
      }
    }
  }
}
```

**Use `style-top`/`style-bottom`, not a blanket `style`.** `border.style` sets `border-style`
on all four edges, and an edge with a style and no width falls back to CSS's initial
`border-width: medium` — a **3px** rule down both sides of the band. Measured: 0px to 3px at
every tier, and the card track loses 6px.

`inverted` is the dark band, and it is at least two writes:

```json
{
  "udc": {
    "_band": {
      "background": { "fill": "@color-bg-inverted" },
      "typography": { "color": "@color-bg" }
    },
    "subheading": { "typography": { "color": "@color-bg" } }
  }
}
```

`heading` follows the band through `currentColor`. **`subheading` does not** — it pins
`@color-muted`, which measures **3.10:1** on `@color-bg-inverted`. So does `empty`, which
sits on the band fill rather than inside a card. The cards stay light on purpose; if you want
them dark too, that is Step 3 on top of this.

## Step 5: The card bar survived, and it is a real element now

v1 painted the thin rule across the top of each card with `.grid__item::before`. Ruling A3
defers pseudo-elements for the whole of v2, so a literal port would have retired it. It is an
empty `<span class="grid__item-bar">` now, addressed by `card-bar`:

```json
{
  "udc": {
    "card-bar": {
      "background": { "fill": "linear-gradient(120deg,#7B5BFF 0%,#FF5C2E 50%,#3DDFC8 100%)" },
      "sizing": { "height": "3px" }
    }
  }
}
```

No grammar changed: `background.fill` already accepted a literal gradient and `sizing.height`
already accepted `3px`.

## Step 6: ONE card, different from its siblings (Addendum B)

This is the capability no other component has. An entry in `items` may carry its own `udc`
map, in the same shape a band's takes, validated by the same engine and refused with the same
codes:

```json
{
  "props": {
    "items": [
      { "title": "01 Audit", "text": "We read the site as an agent would." },
      {
        "title": "02 Structure",
        "text": "The next pass should not have to guess.",
        "udc": {
          "card": { "background": { "fill": "#14141F" }, "border": { "color": "#0A0A12" } },
          "card-title": { "typography": { "color": "#F2EEE5" } },
          "card-text": { "typography": { "color": "#E8E2D4" } }
        }
      },
      { "title": "03 Ship", "text": "And then it is somebody else's turn." }
    ]
  }
}
```

**Four things to know before you write one.**

1. **`update_component` reaches it.** An ITEM map rides inside `props`, so patching the
   `items` array is how you write it. (A BAND map is reachable too, since #1088: send it as
   `update_component`'s `udc` param, merged into the stored map by role.)
2. **Send every entry you want to keep.** The array is replaced, not merged.
3. **The `id` is the engine's.** It is minted on write, shaped `it-<hex8>`, only for entries
   that carry a map. Never author one.
4. **Re-send the ids when you reorder or delete.** Read them back first:

   ```
   wp pp operate inspect --post_id=42
   ```

   Without them the engine carries each design by POSITION, which is right if you did not
   reorder and wrong if you did. It tells you when it has done so —
   `udc_item_design_carried_by_position` — and a patch that changes the array LENGTH
   preserves nothing rather than moving a design onto the wrong card.

**Ten roles are item-settable**: `card`, `card-bar`, `card-media`, `card-body`, `card-title`,
`card-text`, `card-bullets`, `card-bullet`, `card-link`, `step-number`. The band-level roles
(`_band`, `header`, `eyebrow`, `heading`, `heading-accent`, `subheading`, `list`, `empty`)
exist once per band, so styling them "for one card" has no meaning and they are refused —
with a message telling you to set them on the band instead.

**Seven things are refused rather than ignored**: per-item chrome; `_band` inside an item;
ordinal selectors (`nth-child`, `first`, `last`, `even`/`odd`); nesting beyond one level;
defining a preset inside an item (referencing one with `_preset` is fine); pseudo-elements;
and `_css` at item grain.

## Step 7: `card_emphasis` is retired and NOT ported

v1's `featured` treatment styled the FIRST card — an accent bar, a tinted fill, a larger
title, extra padding and a dark-band lift. There is no v2 equivalent and that is deliberate:
ordinal styling contradicts Addendum B's rule that a card is addressed by its minted id so
that reordering carries the design **with the card**. Two systems disagreeing about which
card is special the moment a list is reordered is worse than one capability fewer.

Measured before retiring it: all 11 production grid bands already rendered `uniform`.

**To make one card the lead, give that card its own map** (Step 6).

## Step 8: `image_treatment`, and the one narrowing

`banner` is the default and needs no write. `icon` is `card-media` -> `sizing`:

```json
{ "udc": { "card-media": { "sizing": { "width": "48px", "height": "48px", "aspect-ratio": "auto" } } } }
```

**Stated narrowing:** the old value also applied `object-fit: contain`, which has no typed
parameter, so the un-cropped fit needs `card-media` -> `_css` -> `{"object-fit": "contain"}`.

## Step 9: `title_align` is two writes

`header` -> `typography.align` moves the text; the eyebrow pill follows it, because an
inline-block is an inline-level box. v1 ALSO centred the capped BOXES of the heading and
subheading with auto margins, and that half is separate:

```json
{
  "udc": {
    "header": { "typography": { "align": "center" } },
    "heading": { "spacing": { "margin-left": "auto", "margin-right": "auto" } },
    "subheading": { "spacing": { "margin-left": "auto", "margin-right": "auto" } }
  }
}
```

`text-align` alone leaves both boxes pinned to the container's left edge.

## Clearing the stored props — send every key in ONE call

The validator reports only the FIRST problem per band, so clearing one retired prop on a band
carrying four just surfaces the next. Send them together:

```
wp pp action update_component --post_id=42 --component_index=0 \
  --props='{"theme":null,"title_align":null,"card_emphasis":null,"image_treatment":null}'
```

`null` is the only way to remove a key the schema no longer declares. Item-level keys
(`items[].style`, `items[].text_role`) clear differently — re-send the `items` array without
them.

## Step 10: Verify

```
wp pp check page 42
```

The band should report no findings and no stored `style` keys. Then **look at it**, at 375px
especially: a card whose paragraph is its last element is 16px taller than it was under v1
(see below), and a wrapped card title is the shape most likely to look wrong.

## What you cannot express, and what to do instead

- **A second background layer.** `--grid-featured-texture-color` painted one; `background.fill`
  refuses a multi-layer value.
- **Anything scoped to `layout: "steps"`.** A role's defaults carry a breakpoint dimension and
  a state dimension and NO variant dimension (#1102), so v1's steps-only type scale is gone:
  a steps card's title went from 1.04rem/weight 680/`max-width: 17rem` to the cards default
  1.14rem/670/uncapped. Set `card-title` -> `sizing.max-width` on a steps band to get the cap
  back.
- **A trailing margin that collapses on the last element.** `card-text` defaults
  `margin-bottom: @space-md`, which v1's `p:last-child` reset zeroed when nothing followed.
  Same conditionality gap. A card that ends with its paragraph is 16px taller; set
  `card-text` -> `spacing.margin-bottom` to `"0"` on that band if it shows.
- **The card's subtle fill gradient.** v1 painted
  `linear-gradient(180deg, var(--color-bg) 0%, var(--color-surface) 100%)`. The grammar
  accepts a gradient of literals and a bare `@token`, but not a token INSIDE a gradient, so
  the default is a token-following flat `@color-bg`. Write the literal gradient if you want
  the tint and accept that it stops following a retheme.

## Related

- `components/grid/README.md` — the full role table, every stated default, and the item-grain contract
- `docs/v2/BUILD-SPEC-sprint0.md` — Addendum B, the item-grain ruling in full
- `docs/tutorial-style-a-band-on-the-design-contract.md` — start here if this is your first `udc` map
- `docs/explanation-cascade-layers.md` — why a role default beats the stylesheet
- `docs/howto-migrate-a-stats-band-to-v2.md` — the previous largest slot map
