# Component: grid

Responsive card grid for discrete content objects — posts, features, team members, process steps. Not for icon-in-circle decoration: every card should represent a real content object.

**This is a v2 component, and it was the LAST one to arrive (#1101).** It declares no style slots. Every designable value — colour, type, spacing, border, shadow, size, motion — is set through the `udc` map on the band, per role. See `docs/v2/BUILD-SPEC-sprint0.md` §3 and `docs/explanation-cascade-layers.md`.

It is also the first component to carry **item-grain** styling: a single card may hold its own `udc` map, addressed by a minted id, so one card in an otherwise uniform grid can be dark while its siblings stay light. That contract is BUILD-SPEC **Addendum B**, and the `item_roles` section below is how this component opts into it.

## Props

| Prop           | Type   | Required | Default    | Description |
|----------------|--------|----------|------------|-------------|
| `id`           | string | No       | `''`       | HTML id for anchor linking; also becomes the stable component id |
| `title`        | string | No       | `''`       | Section heading above the grid |
| `title_accent` | string | No       | `''`       | Exact substring of `title` to render in an accent color |
| `eyebrow`      | string | No       | `''`       | Short kicker/label rendered as a pill above the title |
| `subheading`   | string | No       | `''`       | Supporting line below the title |
| `layout`       | enum   | No       | `'cards'`  | `cards` or `steps`. STRUCTURE, not styling: `steps` renders a numbered badge on each card and no card images |
| `columns`      | number | No       | —          | Explicit desktop (≥768px) column count, an integer 1–4. Unset keeps the auto-derivation from item count. Ignored on `steps` |
| `items`        | array  | Yes      | —          | The cards |

Each entry in `items`:

| Key         | Type   | Required | Description |
|-------------|--------|----------|-------------|
| `number`    | string | No       | Step number label. Required in spirit when `layout` is `steps`; unset falls back to the 1-based position |
| `title`     | string | No       | Card heading (`<h3>`) |
| `text`      | string | No       | Card body. Inline HTML allowed: `a`, `strong`, `em`, `br` |
| `bullets`   | array  | No       | Checklist lines below the text, each prefixed with a check mark. Plain text only |
| `image_url` | string | No       | Card image URL. Not rendered on `steps` |
| `image_alt` | string | No       | Alt text for the card image |
| `image_id`  | number | No       | Media Library attachment id — renders responsively with srcset/sizes when it resolves |
| `link_url`  | string | No       | Card link destination |
| `link_text` | string | No       | Link label (default `Read more`) |

Two further keys on an entry are **engine-owned** and are not fields you author: `id` (minted on write, shape `it-<hex8>`) and `udc` (that card's own design map). See **Item-grain styling** below.

### Six props that used to exist

Every one was measured on a rendered page before it was retired, and four of them were measured against the owner's live site as well. The `retired_props` block in `schema.json` carries the full route for each; a refusal at write names the route too, so you never have to guess.

| v1 prop | what it did | v2 route |
|---|---|---|
| `theme` | `default` / `muted` / `inverted` band tone | `_band` → `background.fill`; the `muted` framing is `border.width-top`/`width-bottom` = `"1px"` with **`style-top`/`style-bottom`** = `"solid"` and `color` = `"@color-border"` — per-edge `style`, NOT a blanket one: `border.style` sets `border-style` on all four edges, and an edge with a style but no width falls back to CSS's initial `border-width: medium`, which paints a **3px** rule down both sides of the band. Measured at 375/768/1280: left/right went 0px → 3px and the card track lost 6px; the `inverted` ink is `_band` → `typography.color`, which `heading` follows through `currentColor` — **but `subheading` does not**, so a dark band is at least two writes |
| `title_align` | `start` / `center` for the header | `header` → `typography.align`. The eyebrow pill follows it (an inline-block is an inline-level box). v1 also centred the heading's and subheading's capped BOXES with auto margins — that half is `spacing.margin-left`/`margin-right` = `"auto"` on those two roles |
| `card_emphasis` | `featured` / `uniform` — a `:first-child` accent treatment | **Retired, not ported.** Ordinal styling contradicts Addendum B's rule that a card is addressed by its minted id so reordering carries its design with it. Measured first: all 11 of the owner's production grid bands already rendered `uniform`. One special card is an item `udc` map now |
| `image_treatment` | `banner` / `icon` card image box | `card-media` → `sizing.aspect-ratio` plus `sizing.width`/`height`. **Stated narrowing:** the `icon` value's `object-fit: contain` has no typed parameter, so the un-cropped fit is reachable only through the `_css` valve. Measured: zero production bands used it |
| `items[].text_role` | `mono` / `meta` / `label` / `kicker` preset on a card's text | the `card-text` role's `udc` map on that item, or a custom preset (#1016). Measured at 375/768/1280: **three of the four rendered.** `mono` set a monospace family at every tier (route: `typography.family`), `label` letter-spacing 0.01em and `kicker` letter-spacing 0.08em + uppercase (`typography.letter-spacing`, `typography.transform`). Only `meta` was inert. One narrowing: `kicker` also painted accent ink **at 375px only**, which the tracking route does not carry — add `typography.color` for it |
| `items[].style` | a per-card slot map rendered as inline custom properties | **the entry's own `udc` map — Addendum B.** §3.4 forbids inline style emission outright, and the replacement is wider: the same roles, the same engine, the same refusal codes |

Two slot values genuinely retired with no route, and both are named here rather than left to be discovered: `--grid-item-bullet-color` (a `::before` glyph — ruling A3 defers pseudo-elements, so the check mark takes the shared accent) and `--grid-featured-texture-color` (a second background layer the `fill` grammar refuses).

**The card top bar SURVIVED, and it is why it is a real element now.** v1 painted it as `.grid__item::before`, which no role can address. Ten of the owner's eleven production bands author it — the same purple→orange→teal 3px rule on every one — so the honest port was to give the thing its own selector: an empty `<span class="grid__item-bar">`, styled by the `card-bar` role through ordinary `background.fill` and `sizing.height`. No grammar changed.

### One geometry narrowing: a card that ends with its paragraph is 16px taller (#1102)

Measured in Chromium at 375/768/1280, v1 against the rebuild, on the same scenes:

| card shape | v1 `.grid__item-body` | v2 |
|---|---|---|
| title + text + bullets + link | 260.562px | **260.562px** — byte-identical |
| title + text, nothing after | 126.797px | **138.328px** |

`card-text` defaults `spacing.margin-bottom: @space-md`, and that value is correct — v1 rendered 16px between the paragraph and whatever followed it. But v1 **also** rendered 0px when nothing followed, because base.css's `p:last-child { margin-bottom: 0 }` reset caught it. A v2 element-tier role default emits **unlayered**, so nothing in `@layer pp-v1` can take it back: a structural `.grid__item-text:last-child { margin-bottom: 0 }` was prototyped and measured **inert**.

The condition is *"is this the last child"* — a structural fact about the markup — and **v2 roles have no conditionality concept at all**. That is [#1102](https://github.com/FJCF76/PromptingPress/issues/1102), and this is its first concrete case rather than a defect in these defaults.

**Kept rather than worked around, and the alternatives are why.** Dropping the default fixes the text-last card and collapses text→bullets and text→link from 24px to 8px on every ordinary card. Moving the 16px onto `card-bullets`/`card-link` as a `margin-top` keeps those two right and makes bullets→link 24px where v1 measured 8px. Each trades an uncommon shape's error for a common one's; this one errs toward *more* space rather than cramped.

**To remove it on a band where it shows**, set `card-text` → `spacing.margin-bottom` to `"0"` and put the rhythm on `card-body` → `spacing.gap` instead. An authored value emits unlayered above the default, so it wins.

## Roles

| Role | Selector | What it owns |
|---|---|---|
| `_band` | the `<section>` | band padding, background (including `image` + `overlay`), border, shadow, and the band ink `heading` follows |
| `header` | `.grid__header` | the eyebrow/title/subheading block — its text alignment, and nothing else |
| `eyebrow` | `.grid__eyebrow` | the kicker pill: background, border, radius, casing, ink |
| `heading` | `.grid__heading` | the `<h2>`: size, weight, leading, measure, rhythm, ink |
| `heading-accent` | `.grid__heading-accent` | the accented substring's ink |
| `subheading` | `.grid__subheading` | the supporting line: ink, measure, rhythm |
| `list` | `.grid__list` | the `<ul>`: the gap between cards, and `layout.columns` for a per-breakpoint track count |
| `card` | `.grid__item` | one `<li>` — fill, border, radius, shadow, motion. **THE ITEM ROOT:** this is the element that carries `data-pp-item` |
| `card-bar` | `.grid__item-bar` | the thin rule across the top of a card (cards layout only) |
| `card-media` | `.grid__item-image-wrap` | the card's image box: aspect ratio, width, height |
| `card-body` | `.grid__item-body` | the padded content area below the image — **it owns the card's padding, not `card`** |
| `card-title` | `.grid__item-title` | the card `<h3>`: ink, size, weight, leading, tracking |
| `card-text` | `.grid__item-text` | the card's supporting paragraph |
| `card-bullets` | `.grid__item-bullets` | the checklist `<ul>`: ink, type, gap |
| `card-bullet` | `.grid__item-bullet` | one checklist line — its left indent, and nothing else |
| `card-link` | `.grid__item-link` | the "Read more" link, at rest and on `:hover` |
| `step-number` | `.grid__step-number` | the filled circular badge on the `steps` layout |
| `empty` | `.grid__empty` | the "Nothing here yet." line — it sits on the BAND fill, not inside a card |

**Which roles carry `layout` (#1084):** `card`, `card-body`, `card-bullets`, `card-link`, `list`. A role carries the group when its own structural CSS makes the box a flex or grid container; the rule and its two clauses are in [the Layout contract](../../docs/v2/LAYOUT-GROUP-CONTRACT.md) §5, and a test checks this list against the stylesheet in both directions. `header` is deliberately NOT on it — `.grid__header` is an ordinary block, so a `justify-content` there would paint nothing at any value. `align-self` is NOT in this group either: a box placing ITSELF is `sizing.align-self`, available on any role with `sizing`.

### The pairs you have to write together

A card keeps its own light ink even on a dark band — measured, `card-title`, `card-text`, `card-bullets` and `card-link` rendered byte-identically on a v1 `default` and `inverted` band, because v1 deliberately kept cards light. That is still the default, and it is why those four roles pin their colours instead of following the band. The consequence is yours to carry, and the schema declares it as an obligation so the model-facing prompt carries it too:

- **Darkening a `card` fill is five writes, not one:** `card`, then `card-title`, `card-text`, `card-bullets` and `card-link`. `card-link` pins `@color-accent`, which measures **3.2:1 on a `#14141F` card** — under the 4.5:1 AA floor for its 0.9rem weight-600 text.
- **Darkening the `_band` does not reach `subheading` or `empty`:** both pin `@color-muted`, and `empty` sits on the band fill rather than inside a card. On `@color-bg-inverted` that measures **3.10:1**, under the 4.5:1 AA floor — v1's `theme: "inverted"` re-coloured the subheading automatically and the v2 route does not.
- **The step badge's fill and its numeral are a pair:** `step-number` defaults to `@color-accent` fill with `@color-bg` ink. Changing one without the other is how a light badge gets invisible numerals. On a band whose background you set, a new ink you give the badge (anything but its own `@color-bg`) over its default accent fill is named by the write (#1125), and because that default fill is dark the advice leads with checking the pair.

## Item-grain styling (Addendum B)

```jsonc
"item_roles": { "prop": "items", "root": "card",
                "roles": ["card", "card-bar", "card-media", "card-body", "card-title",
                          "card-text", "card-bullets", "card-bullet", "card-link",
                          "step-number"] }
```

An entry in `items` may carry its own `udc` map, in the same shape a band's takes, validated by the same engine, refused with the same codes:

```json
{
  "title": "AI-safe structure",
  "text": "The next agent pass should not have to guess through hidden state.",
  "udc": {
    "card":       { "background": { "fill": "#14141F" }, "border": { "color": "#0A0A12" } },
    "card-title": { "typography": { "color": "#F2EEE5" } },
    "card-text":  { "typography": { "color": "#E8E2D4" } }
  }
}
```

That emits a band-scoped rule keyed on a minted id, printed after the band's own block:

```css
[data-pp-band="pp-3f9a1c2e"] .grid__item                               { /* band tier */ }
[data-pp-band="pp-3f9a1c2e"] [data-pp-item="it-7b2c91d4"] .grid__item-title { /* item tier */ }
[data-pp-band="pp-3f9a1c2e"] [data-pp-item="it-7b2c91d4"]              { /* item root */ }
```

**Things worth knowing before you write one:**

- **The id is minted on WRITE, only for entries that carry a `udc` map.** You never author it. It is carried forward across a full `items` re-apply by index + component match, and minted fresh on ambiguity — which is what makes the design travel with the card rather than with position 2.
- **The band-level header roles are not addressable per item.** `_band`, `header`, `eyebrow`, `heading`, `heading-accent`, `subheading`, `list` and `empty` exist once per band, so styling them "for one card" has no meaning and they are absent from `item_roles`.
- **Seven things are REFUSED rather than ignored**, because accepted-stored-ignored is the failure class this engine exists to close: per-item chrome; `_band` inside an item map; ordinal or structural selectors (`nth-child`, `first`, `last`, `even`/`odd`); nesting beyond one level; defining a new preset inside an item (referencing one with `_preset` is fine); pseudo-elements; and `_css` at item grain.
- **The whole disclosure machinery follows the item tier.** `source` gains the value `item`; the shadowing, minting and skipped-preset-group findings all report with an item locator beside the band index.

## Structural CSS — what the stylesheet still owns

The `COMPONENT: grid` block in `assets/css/components.css` is structure only, and a test enforces that on exactly that slice. What lives there and why:

- **Track geometry:** the 1→2 column collapse, the `steps` 3-across, the `data-pp-count` auto-derivation and the `data-pp-columns` override. All of it is `grid-template-columns` plus the max-width/auto-margin pair that centres a short row. The GUTTER is `list` → `spacing.gap`.
- **`overflow: hidden` on the card**, which clips a banner image to the card's authored radius — and is why `card-link`'s focus ring is drawn INSET (`outline-offset: -3px`). That is #1056's fix, applied structurally so no authored padding can reopen it.
- **The hover lift** (`transform: translateY(-2px)`) and the `transition` property list, which have no role address at any value.
- **Two pseudo-elements:** the `::after` arrow on the card link, and the steps connector rule. Ruling A3 defers pseudo-elements, so neither has a role. Two narrowings fall out of that and are stated rather than discovered: the arrow lost its `font-weight: 700` and now renders at the link's own weight, and the connector rule paints a `currentColor` border edge rather than a pinned `@color-border` background.

### The connector rule paints nothing, and it is kept on purpose (#601, #670)

`.grid--steps .grid__item:not(:last-child)::after` is positioned at `left: 100%` — outside the card's padding box — while `.grid__item` declares `overflow: hidden`. The card clips it, at every viewport, so the rule has never put a pixel on the page. That was recorded at #601 and its reopening is #670; the v2 rebuild changes neither fact, because `overflow: hidden` is exactly as load-bearing for the banner-image crop as it was before.

It is ported rather than deleted for the same reason the record exists: deleting it would turn a known-dead rule with an issue number into a silently absent one, and #670 is where the decision to revive or remove it belongs. **Do not read its arithmetic as a promise** — the offset happens to be correct now (the card body's 2rem padding plus half a 44px badge is exactly the `calc(var(--space-lg) + 1.375rem)` the rule carries, which it only approximated under v1's outer padding), and that is a fact about the numbers, not about anything a reader will see.

## Cards layout

`cards` auto-derives its desktop track count from the item count — one card spans the container, two and three span it in equal tracks, four wrap to a centred 2×2 — and the `columns` prop overrides that at ≥768px. Below 768px every grid is a single column. For a per-breakpoint count, `list` → `layout.columns` is the UDC route and it beats both, because an authored value emits unlayered.

## Steps layout

`steps` keeps a fixed 3-across process grain at ≥1024px. It renders a numbered badge on each card and no card images, which is why it is a `layout` PROP and not a design value: it changes which elements exist.

**The badge moved INSIDE `.grid__item-body` at #1101.** The steps-only outer padding it used to sit in has no v2 home — a role's defaults carry a breakpoint dimension and a state dimension and NO variant dimension, so "padding, but only on steps" is unspellable (the conditionality gap, #1102). Left outside the body with that padding gone, the badge would sit flush against the card's border. The visible consequences are written down in `grid.php` at the move: a steps card's content inset goes from 48px to 32px at the sides, the badge's own inset from 16px to 32px, and the gap under the badge from 16px to 24px because `card-body`'s flex `gap` now applies between the badge and the title.

**The steps TYPE SCALE went with it, and that is the same gap reached through typography.** v1 carried a `.grid--steps`-scoped rule at >=768px that no v2 role can express, for the same reason the padding could not be: there is no variant dimension. Measured at 768 and 1280, a steps card's title went **1.04rem/weight 680/`max-width: 17rem`** to the cards default **1.14rem/weight 670/uncapped** (16.64px -> 18.24px, box 246x21 -> 278x23), and its paragraph **0.995rem/1.66** to **1.005rem/1.68**. So a steps card's heading is now slightly larger, slightly lighter and no longer capped at a 17rem measure. The cap is the one worth knowing about, and it is authorable today: set `card-title` -> `sizing.max-width` to `17rem` on a steps band to get it back.

The badge's fill and its numeral ink are a PAIR — see the `step-number` role's obligation above. Its size, radius and rhythm are that role's `sizing`, `border` and `spacing`.

## Files

- `grid.php` — the template
- `schema.json` — props, roles, `item_roles`, `retired_props`
- `../../assets/css/components.css` — the `COMPONENT: grid` structural block
