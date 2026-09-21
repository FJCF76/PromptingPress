# How to migrate an `embed` band to the design contract

`embed` moved to the Universal Design Contract at #1066. Its eight style slots and its
`theme` prop are retired; every designable value is now a role in the band's `udc` map.

> **⚠ CLEAR THE STORED STYLE MAP FIRST, IF THERE IS ONE.** A band still holding its v1
> `style` map cannot be edited at all since #1101 — not restyled, *edited*: a props-only
> change meets `invalid_style_slot` naming a key you never mentioned. That refusal blocks
> every step below. It takes one command to clear:
> **`docs/howto-clear-a-stored-v1-style-map.md`**. Do that, then come back here.

**The mapping is short. The part worth reading twice is Step 4**, because `embed` is the one
component whose content is arbitrary author HTML, and a band-level ink write reaches less of it
than you would expect.

If your band sets no slots and no `theme`, you have nothing to do and it renders identically.

Read `docs/tutorial-style-a-band-on-the-design-contract.md` first if you have not written a `udc`
map before.

## Prerequisites

- The band needs a stable `id`. The engine mints one on write; a band that reached storage
  without one renders structurally and takes no design until it has one.
- `wp pp check page <id>` tells you what the page currently stores.

## Step 1: Find out what is actually broken

A stored `--embed-*` slot or a stored `theme` is not read at RENDER: the band paints as though
neither were set, and no read surface says so (#1050). A band that stored `theme: "inverted"`
renders **light**. **The stored SLOT map is not ignored at WRITE, though** — since #1101 it
refuses every edit to the band, a props-only one included. Clear it first; see the note at the
top of this guide.

```
wp pp check page 42
```

## Step 2: The slots, seven of eight plain renames

| v1 slot | v2 address |
|---|---|
| `--embed-padding-top` | `_band` → `spacing.padding-top` |
| `--embed-padding-bottom` | `_band` → `spacing.padding-bottom` |
| `--embed-heading-size` | `heading` → `typography.size` |
| `--embed-heading-measure` | `heading` → `sizing.max-width` |
| `--embed-heading-margin-bottom` | `heading` → `spacing.margin-bottom` |
| `--embed-body-measure` | `content` → `sizing.max-width` |
| `--embed-heading-color` | `heading` → `typography.color` — **read Step 3** |
| `--embed-body-color` | **nothing** — read Step 3 |

```json
{
  "udc": {
    "_band":   { "spacing": { "padding-top": "5rem", "padding-bottom": "5rem" } },
    "heading": {
      "typography": { "size": "2.4rem" },
      "spacing":    { "margin-bottom": "2rem" },
      "sizing":     { "max-width": "36rem" }
    },
    "content": { "sizing": { "max-width": "34rem" } }
  }
}
```

## Step 3: The two colour slots, which behave differently

**`--embed-heading-color`** ported, but its **default changed**: from a pinned `@color-text` to
`currentColor`. On every band v1 could render those are the same colour — the only dark embed
band v1 had was `theme: "inverted"`, which is retired — and they diverge only on a band you
darken yourself, where the pinned literal lands at **1.006:1**. If your band set an explicit
colour, port it as written. If it did not, do nothing: the heading now follows the band.

**`--embed-body-color` has no replacement at all, and that is deliberate.** v1 declared
`color: var(--embed-body-color, inherit)`. An explicit `inherit` **is** a declaration — but
nothing in this theme declares `color` on a bare `<div>`, so an inherited `_band` value already
lands and silence is byte-identical. The `content` role still declares `typography`, so if your
band set an explicit body colour, port it to `content` → `typography.color`.

## Step 4: A dark band reaches less of `content` than you expect

`content` is arbitrary author HTML sanitized by `wp_kses_post()`. **An inherited colour is used
only where nothing else declares one.**

A `_band` → `typography.color` write reaches:

- the heading (through `currentColor`)
- a bare `<p>`, a `<strong>`, plain text

and does **not** reach:

| element | what pins it | measured on `@color-bg-inverted` |
|---|---|---|
| an author-written `<h2>`–`<h6>` | `base.css` pins `@color-text` | **1.006:1** — invisible |
| a `<blockquote>` | `base.css` pins `@color-muted` | ~3.1:1 |
| a link | `base.css` pins `@color-accent` | **3.23:1** (its hover, `@color-accent-hover`, is 2.59:1) |

Each is a **direct declaration on the element**, and a direct declaration always beats an
inherited value regardless of cascade layer.

Links have an address — `content-link`. The other two do not, and have to be handled in the
embedded markup itself. If your shortcode renders headings on what you intend to be a dark band,
either give it a light surface or do not darken the band.

## Step 5: `theme` takes three groups, not one

| v1 `theme` | v2 |
|---|---|
| `default` | nothing to write — the `_band` defaults already paint nothing |
| `muted` | `_band` → `background.fill: "@color-surface"` **plus** `border.width-top` / `width-bottom` = `"1px"`, `style-top` / `style-bottom` = `"solid"`, `color` = `"@color-border"` |
| `inverted` | `_band` → `background.fill: "@color-bg-inverted"` + `typography.color: "@color-bg"`, **plus** `content-link` → `typography.color` **and its `:hover`** |

Use the per-edge `border.width-top` / `width-bottom`, never the `width` shorthand: the shorthand
emits all four edges, which on a full-bleed band draws hairlines down both viewport edges.

> **The fill and the ink are two writes, and setting only the fill is the common mistake.**
> `currentColor` on the `heading` role follows the band's `typography.color`, not its
> `background.fill`. Give a band a dark fill and no ink and the heading keeps the inherited
> `@color-text`, rendering at about **1.04:1**. Write both together.

A dark band, complete:

```json
{
  "udc": {
    "_band":        { "background": { "fill": "@color-bg-inverted" }, "typography": { "color": "@color-bg" } },
    "content-link": {
      "typography": {
        "color": "@color-accent-on-inverted",
        ":hover": { "color": "@color-accent-on-inverted-hover" }
      }
    }
  }
}
```

**Set the `:hover` in the same write, not as a follow-up**, and the reason is not the one you
would guess. v1 remapped both states together because one class did it. On v2, writing only the
resting colour does **not** leave the hover on `base.css`'s `a:hover` — the authored value is
emitted **unlayered**, and unlayered beats every layer, so it wins on hover too. Measured: the
link holds `@color-accent-on-inverted` in both states and **stops responding to hover entirely**.

So the cost is a lost affordance, not a contrast failure. (The contrast failure is a different
scene: a dark band with **no** `content-link` write at all leaves the link at `@color-accent`,
measured **3.23:1** resting.) A role's states move with its resting value — that is the rule,
and this is the surface where forgetting it is least visible, because nothing looks wrong until
someone tries to hover.

## Step 6: `content-link` replaces the automatic remap

v1 remapped a dark band's links **automatically**, through `.embed--inverted a` (#437). v2 has no
automatic remap — you write it — and the address is the `content-link` role.

It ships with **no defaults**, and that is the point rather than an omission: a default restating
`base.css`'s anchor values would be emitted unlayered and would outrank the premium button rules
for an author-written `<a class="btn">` in the content. With no default it costs nothing until
you use it.

> **What an AUTHORED value here reaches, which is more than v1's rule did.** Your write emits
> unlayered at `.embed__content a`, so it lands on an `<a class="btn">` in the content as well as
> on a prose link, in both states. v1's `.embed--inverted a` weighed `(0,1,1)` inside `pp-v1` and
> lost to `main .btn:not(...)` at `(0,4,1)`, so the automatic remap left buttons alone. Measured:
> the dark-band write above puts `@color-accent-on-inverted` on the gradient's `@color-accent` at
> **2.58:1**, where the button's own label is 5.37:1. The selector cannot be narrowed to
> `a:not(.btn)` — the role-selector charset admits neither `:` nor `(`. So either keep composed
> buttons out of a surface whose links you recolour, or accept the button taking the same ink.

**It carries states, and v1's automatic remap carried two.** `.embed--inverted a` set the resting
colour and `.embed--inverted a:hover` set the hover; both retire together. Write both:

```json
{ "udc": { "content-link": { "typography": {
  "color": "@color-accent-on-inverted",
  ":hover": { "color": "@color-accent-on-inverted-hover" }
} } } }
```

## Clearing the stored slot map and the stored `theme`

Send every stale key in ONE call, each as `null` — the validator reports only the first problem
per band, so clearing them one at a time takes nine round trips (#1064):

```json
{
  "action": "update_component",
  "post_id": 42,
  "component_index": 3,
  "props": { "theme": null },
  "style": {
    "--embed-padding-top": null,
    "--embed-padding-bottom": null,
    "--embed-heading-size": null,
    "--embed-heading-color": null,
    "--embed-heading-measure": null,
    "--embed-heading-margin-bottom": null,
    "--embed-body-measure": null,
    "--embed-body-color": null
  }
}
```

## Step 7: Verify

```
wp pp check page 42
```

Then **look at the band in a browser**, which matters more here than on any other component: the
theme cannot vouch for what a plugin renders inside `content`, so the only way to know a dark band
reads correctly is to open it.

## What you cannot express, and what to do instead

- **An author-written heading or blockquote inside `content` has no role.** Its colour comes from
  `base.css` and only the embedded markup can change it.
- **The band cannot style a plugin's own classes.** It never could; inheritance is the whole reach,
  and a shortcode that sets its own colours wins. That is expected — `embed` is the sanctioned
  escape hatch for plugin-rendered content, not a styling surface for it.
- **There is no aspect-ratio or responsive-video handling, and there never was.** This is worth
  stating rather than leaving as an absence, because `embed` is the band most likely to hold an
  iframe and a reader could reasonably assume the rebuild dropped something. It did not:
  #1066's plan (§5(b)) asked for v1 to be checked before any such parameter was invented, and the
  check was run — v1's entire `.embed` block was band padding, the heading's size/colour/rhythm/
  measure, and the content column's measure and ink. No `aspect-ratio`, no `object-fit`, no iframe
  wrapper, no percentage-padding ratio hack, nothing positioned. So the v2 roles carry none either,
  and **nothing was invented to fill the gap** — which is the rule a rebuild lives by. An embedded
  iframe sizes itself exactly as it did before. If you need a responsive video frame, it comes from
  the plugin or the embedded markup, as it always has.

## Related

- `components/embed/README.md` — the full role table and every stated default
- `docs/explanation-cascade-layers.md` — why a role default beats the stylesheet
- `docs/howto-migrate-a-table-band-to-v2.md` — the other half of #1066
- `docs/howto-migrate-a-section-band-to-v2.md` — the mechanics in full, if this is your first
