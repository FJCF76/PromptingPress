# How to migrate a `logos` band to the design contract

`logos` moved to the Universal Design Contract at #1066. Its eight style slots and its
`theme` prop are retired; every designable value is now a role in the band's `udc` map.

> **⚠ CLEAR THE STORED STYLE MAP FIRST, IF THERE IS ONE.** A band still holding its v1
> `style` map cannot be edited at all since #1101 — not restyled, *edited*: a props-only
> change meets `invalid_style_slot` naming a key you never mentioned. That refusal blocks
> every step below. It takes one command to clear:
> **`docs/howto-clear-a-stored-v1-style-map.md`**. Do that, then come back here.

**Six of the eight are plain renames. The interesting one is Step 4**, because logos is the
component where one v1 knob became two v2 roles — the only capability in this rebuild that
changed shape rather than address.

If your band sets no slots and no `theme`, you have nothing to do and it renders identically.

Read `docs/tutorial-style-a-band-on-the-design-contract.md` first if you have not written a
`udc` map before.

## Prerequisites

- The band needs a stable `id`. The engine mints one on write; a band that reached storage
  without one renders structurally and takes no design until it has one.
- `wp pp check page <id>` tells you what the page currently stores.

## Step 1: Find out what is actually broken

A stored `--logos-*` slot or a stored `theme` is not read at RENDER: the band paints as
though neither were set, and no read surface says so (#1050). A band that stored
`theme: "inverted"` renders **light**. **The stored SLOT map is not ignored at WRITE, though**
— since #1101 it refuses every edit to the band, a props-only one included. Clear it first;
see the note at the top of this guide.

```
wp pp check page 42
```

## Step 2: The slots, six plain renames

| v1 slot | v2 address |
|---|---|
| `--logos-padding-top` | `_band` → `spacing.padding-top` |
| `--logos-padding-bottom` | `_band` → `spacing.padding-bottom` |
| `--logos-heading-size` | `heading` → `typography.size` |
| `--logos-heading-measure` | `heading` → `sizing.max-width` |
| `--logos-heading-margin-bottom` | `heading` → `spacing.margin-bottom` |
| `--logos-heading-color` | `heading` → `typography.color` — **read Step 3** |
| `--logos-gap` | `list` → `spacing.gap` |
| `--logos-image-size` | **two roles now** — read Step 4 |

```json
{
  "udc": {
    "_band":   { "spacing": { "padding-top": "5rem", "padding-bottom": "5rem" } },
    "heading": { "typography": { "size": "2.4rem" }, "spacing": { "margin-bottom": "2rem" } },
    "list":    { "spacing": { "gap": "2.5rem" } }
  }
}
```

## Step 3: The heading's default changed, and it is still not centred

`--logos-heading-color` fell back to a pinned `var(--color-text)`; the `heading` role defaults
to `currentColor`. v1 needed two rules — the base pin and `.logos--inverted .logos__heading`
re-pointing to `@color-bg` — and `currentColor` reproduces both now that the theme class is
gone. Measured rgb(16, 24, 40) on an unauthored band: byte-identical. Port an explicit colour
as written.

**And logos' heading is NOT centred, where stats' is.** Measured `text-align: start` with
`margin-left: 0` at every tier. The two components look like twins and differ here, so the
role declares neither an alignment nor auto margins. If you want the stats treatment:

```json
{ "udc": { "heading": {
  "typography": { "align": "center" },
  "spacing":    { "margin-left": "auto", "margin-right": "auto" }
} } }
```

Both margins, not one — a capped box centres only when **both** sides are auto.

## Step 4: One knob became two roles, and the switch survives differently

v1 routed **one** slot at **both** cap sites with different fallbacks:

```
.logos__image                          max-height: var(--logos-image-size, 3rem)     48px rendered
.logos__item--labeled .logos__image    max-height: var(--logos-image-size, 2.5rem)   40px rendered
```

Setting it collapsed both branches to your single value — deliberately. The schema called two
knobs for one visual job "a family this gate is completing, not extending."

**On v2 they are two roles**, because a role carries exactly one default and `max-height` is a
sizing value the structural-CSS boundary will not let stay in the stylesheet:

| | role | default |
|---|---|---|
| unlabelled cell | `image` | `3rem` |
| labelled tile | `image-labeled` | `2.5rem` |

The label-driven switch survives by **specificity** rather than by fallback:
`.logos__item--labeled .logos__image` is (0,2,0) against `.logos__image`'s (0,1,0). The
rendered default is byte-identical to v1 (measured 48px / 40px).

**What changed for you:** "make the logos bigger" is now two writes, and setting `image` no
longer touches labelled tiles.

```json
{ "udc": {
  "image":         { "sizing": { "max-height": "4rem" } },
  "image-labeled": { "sizing": { "max-height": "3.25rem" } }
} }
```

If your band set `--logos-image-size` to collapse the switch on purpose, write the **same
value to both roles** and you get exactly the old behaviour.

## Step 5: `theme` takes three groups, and a dark band is THREE writes

| v1 `theme` | v2 |
|---|---|
| `default` | nothing to write — the `_band` defaults already paint nothing |
| `muted` | `_band` → `background.fill: "@color-surface"` **plus** `border.width-top` / `width-bottom` = `"1px"`, `style-top` / `style-bottom` = `"solid"`, `color` = `"@color-border"` |
| `inverted` | `_band` fill + ink, **plus** `label` ink |

Use the per-edge `border.width-top` / `width-bottom`, never the `width` shorthand: the
shorthand emits all four edges, which on a full-bleed band draws hairlines down both viewport
edges.

```json
{
  "udc": {
    "_band": { "background": { "fill": "@color-bg-inverted" }, "typography": { "color": "@color-bg" } },
    "label": { "typography": { "color": "rgb(192, 195, 201)" } }
  }
}
```

**Why `label` needs its own write.** It pins `@color-muted` as a direct declaration on the
element, so a band ink write does not reach it — and `@color-muted` measures about **3.1:1**
on `@color-bg-inverted`, under the 4.5:1 floor at this 13px size.

**Why that colour.** v1's `.logos--inverted .logos__label` set `@color-bg` at `opacity: 0.75`.
`opacity` is in none of the seven UDC groups, so the de-emphasis ports as the
**pixel-measured composite `rgb(192, 195, 201)`** (10.11:1). See the token note in `base.css`:
re-introducing an opacity literal is explicitly ruled out.

> **Why exactly `rgb(192, 195, 201)`, and the one case where a colour is not an opacity.**
> The composite of `#fcfdff` at 0.75 over `#0f172a` is (192.75, 195.5, 201.75), and Chromium
> **floors** each channel — which is why the measured pixel is 192/195/201 rather than the
> 193/196/202 that round-half-up predicts. It measures **10.11:1** on the inverted band.
>
> The substitution is exact for this label because it renders plain text and nothing else.
> It would not be exact in general: `opacity` dimmed the whole **box**, including any
> descendant element and any text decoration, while a colour reaches only the text it is set
> on. A caption that later carries a link or an icon needs that child re-inked too.

> **The fill and the ink are two writes.** A dark fill with no `typography.color` leaves the
> heading on the inherited `@color-text`, at about **1.04:1**.

**The logo images are not one of the writes.** Nothing in the theme ever re-inked them, and
nothing does now — a dark strip of dark logos was as much your problem on v1 as it is here.
That is a content decision (supply light-on-dark marks), not a styling one.

## Clearing the stored slot map and the stored `theme` — send every key in ONE call

The validator reports only the first problem per band, so clearing them one at a time takes
nine round trips (#1064):

```json
{
  "action": "update_component",
  "post_id": 42,
  "component_index": 3,
  "props": { "theme": null },
  "style": {
    "--logos-padding-top": null, "--logos-padding-bottom": null,
    "--logos-heading-size": null, "--logos-heading-color": null,
    "--logos-heading-measure": null, "--logos-heading-margin-bottom": null,
    "--logos-image-size": null, "--logos-gap": null
  }
}
```

## Step 6: Verify

```
wp pp check page 42
```

The band should report no findings and no stored `style` keys. Then look at it — a mixed
strip (some tiles labelled, some not) is the shape most likely to reveal a half-done Step 4.

## What you cannot express, and what to do instead

- **No focal point and no aspect ratio.** logos is a **fit** model (`object-fit: contain`,
  structural), so a client logo is shown whole. That is the deliberate contrast with the
  testimonials avatar, which is a **crop** model. If you need a crop, you need a different
  component.
- **`opacity` has no group — but since #1079 a role's `"_css"` map reaches it, and you
  should still not use it here.** Write the composite colour (Step 5). The engine will take
  the alpha; it will not keep the label above the contrast floor for you.
- **The band cannot re-ink the logo images.** It never could.

## Related

- `components/logos/README.md` — the full role table and every stated default
- `docs/howto-migrate-a-stats-band-to-v2.md` — its sibling, rebuilt in the same change
- `docs/explanation-cascade-layers.md` — why a role default beats the stylesheet
