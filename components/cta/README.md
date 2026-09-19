# Component: cta

A closing call-to-action band: an optional eyebrow, heading and supporting line above one or
two buttons. Two layouts — `full-width` (a centred block) and `inline` (a flex row from
768px, text left and buttons right).

**REBUILT ON THE UNIVERSAL DESIGN CONTRACT (v2).** It declares no style slots. Every
designable value — colour, type, spacing, border, shadow, size, motion — is set through the
`udc` map on the band, per role. See
[docs/v2/BUILD-SPEC-sprint0.md](../../docs/v2/BUILD-SPEC-sprint0.md) §3 for the contract and
[docs/tutorial-style-a-band-on-the-design-contract.md](../../docs/tutorial-style-a-band-on-the-design-contract.md)
if you have not written a `udc` map before.

## Props

| Prop | Type | Required | Default | Notes |
|---|---|---|---|---|
| `id` | string | No | `''` | HTML id for anchor linking. |
| `title` | string | No | `''` | The heading. Omit it (and `body`) for the standalone-button pattern. |
| `title_accent` | string | No | `''` | Exact substring of `title` to render in the accent colour. |
| `eyebrow` | string | No | `''` | Kicker label above the heading; renders as a pill. |
| `body` | string | No | `''` | Supporting line. Inline HTML only: `a`, `strong`, `em`, `br`. |
| `button_text` | string | Yes | — | The primary button's label. |
| `button_url` | string | Yes | — | The primary button's destination (`link_url` format). |
| `button2_text` | string | No | `''` | The second button's label; the button renders only when this is set. |
| `button2_url` | string | No | `'#'` | The second button's destination (`link_url` format). |
| `layout` | enum | No | `full-width` | `full-width` or `inline`. Geometry only — see below. |

### Four props that used to exist

| Retired | Write this instead |
|---|---|
| `theme` | `_band` → `background.fill` for the surface, plus `typography.color` on `heading`, `heading-accent`, `body` and `body-link`. There is no single dark/light switch — that is the point, and the contrast is now yours. |
| `background_image` | `_band` → `background.image`, **an attachment id, not a URL** (`import_media` returns one), with `background.overlay` for the scrim and `background.position` / `size` / `repeat` for placement. |
| `button_variant` | the `button` role's `udc` map, or `"_preset": "button"` / `"button-secondary"`. |
| `button2_variant` | the `button-secondary` role's map, same presets. Its v1 default (`outline`) is that role's own defaults, so an unauthored pair still reads as one filled action beside one outlined one. |

A write naming any of them is refused with the route above, not with a list of live prop
names. To clear a stored one, send it as `null` through `update_component`.

**Two things `theme` and `background_image` took with them, stated because they were
automatic and are now explicit.** A `background_image` band used to get `background-size:
cover` and `background-repeat: no-repeat` for free; write `background.size` / `repeat`. And
both classes carried seven AA corrections — on-inverted and on-overlay ink for the
HEADING, the accented heading substring, the body, its links at rest and on hover, and the
`outline`/`ghost` buttons, plus a separation ring on the filled button over a scrim. Those are gone: v2 has no variant-scoped role defaults and does not guess, exactly as
#986 ruled for `.hero--cover`. **A band with a dark fill or a scrim owns its own contrast.**

The one affordance that stayed automatic is the **focus ring** over a scrim, because #986
gave it an engine-emitted trigger instead of a class: the engine sets `data-pp-band-overlay`
when a band paints an image AND an overlay, and the stylesheet keys the on-overlay outline
to that. You cannot forget to switch it on. A band you merely darken with a fill gets the
ordinary accent ring — set the `button` role's own values if that needs to change.

### Why one prop survived

`layout` selects flex GEOMETRY and the UDC taxonomy carries no layout group, so retiring it
would delete a capability rather than move it — the same reasoning that kept hero's
`split_ratio` and section's `body_items_align`.

**It no longer carries colour.** v1's `full-width` painted a surface fill and two 1px rules;
`inline` painted neither. v2 has no layout-scoped role defaults, so one value serves both,
and the `_band` defaults are what v1's `full-width` RENDERED — because `full-width` is this
prop's own default, which makes the band an agent gets when it specifies nothing
byte-identical to v1. **An `inline` cta now paints them too.** For the v1 inline look:

```json
"_band": {"background": {"fill": "transparent"},
          "border": {"width-top": "0", "width-bottom": "0"}}
```

## Roles

| Role | Selector | What it owns |
|---|---|---|
| `_band` | the `<section>` | band padding, background (including `image` + `overlay`), border, radius, shadow |
| `inner` | `.cta__inner` | the gap between the text block and the buttons |
| `text` | `.cta__text` | the text block's measure |
| `eyebrow` | `.cta__eyebrow` | the kicker pill: background, border, radius, casing, ink |
| `heading` | `.cta__title` | the `<h2>`: size, measure, rhythm |
| `heading-accent` | `.cta__title-accent` | the accented substring's ink |
| `body` | `.cta__body` | the supporting line: ink, size, weight, leading |
| `body-link` | `.cta__body a` | links inside the body — **declares nothing** (see below) |
| `buttons` | `.cta__buttons` | the pair row's gap |
| `button` | `.cta__button--primary` | the primary button, and only it — **declares nothing** (see below) |
| `button-secondary` | `.cta__button--secondary` | the second button — the only role here with colour-bearing defaults |

### Two roles declare nothing, and that is the deliberate part

**`button`.** Measured in Chromium, every resting and hover value the primary renders — the
gradient fill, the inverted ink, the 1px accent ring, the bevel, the padding, the 44px-plus
target, the three-property transition — comes from the shared `.btn` and premium
`main .btn:not(...)` rules in `@layer pp-v1`. Restating a global value as a role default
changes its CASCADE POSITION even at an identical value: a role block is emitted unlayered,
so it would beat the premium `:hover` for the same property and strand the button in its
resting paint. And role defaults outrank presets, so a default here would suppress exactly
the part of a `button` preset that makes it a button. Apply the preset and it lands whole:

```json
"udc": {
  "button":           { "_preset": "button" },
  "button-secondary": { "_preset": "button-secondary", "border": { "radius": "0" } }
}
```

Anything you set beside a preset wins over it.

**`body-link`.** A body link renders `@color-accent` underlined with an `@color-accent-hover`
hover — byte-identical to what `base.css` already gives every anchor. Identical in VALUE,
different in CASCADE POSITION: restating those values here would outrank the shared premium
button rule, and an author-written `<a class="btn">` inside the body is an anchor too. That
is the #545 nested-author-button defect, reintroduced through a role selector, and #1023 shipped
it once before withdrawing it. An author who SETS a colour here still outranks `base.css`,
which is what a dark band needs.

### `button-secondary` does declare defaults

Both buttons render as a bare `.btn` now that `button2_variant` is gone, so with nothing
declared an unauthored cta would show two identical filled buttons side by side. v1
defaulted the second to `outline`. Losing that is a default-experience regression, not a
narrowing worth documenting, so the role carries v1's outline treatment as MEASURED —
transparent fill, accent ink, a 2px accent edge at `@btn-radius` — with every value an
`@token` reference, so a retune moves it and no colour is baked into schema data.

**Its hover ships with it, by rule.** An unlayered resting value outranks the `pp-v1` hover
rules in every state, so a resting colour without its state map is a button that never
responds to a pointer. `shadow.box: none` is load-bearing and easy to miss: a bare `.btn`
matches the premium filled family, and while `background.fill` emits the `background`
SHORTHAND (which clears the gradient), nothing clears the premium BEVEL.

Two costs, stated rather than discovered: these are the `button-secondary` preset's own
values duplicated, so applying a DIFFERENT preset here is partly suppressed — temporary,
until #1018 makes a role able to name its own preset as its default — and the button's transition
narrows from five properties to three, because a bare `.btn` picks up the #540 snap list and
`motion` carries only duration and timing-function by ruling A3.

### Worked example — the dark closing band

```json
"udc": {
  "_band": {
    "background": { "fill": "#0A0A12" },
    "spacing":    { "padding-top": "5.5rem", "padding-bottom": "5.5rem" },
    "border":     { "width": "0", "radius": "0" }
  },
  "heading":        { "typography": { "color": "#F2EEE5" }, "sizing": { "max-width": "48rem" } },
  "text":           { "sizing": { "max-width": "48rem" } },
  "heading-accent": { "typography": { "color": "#FF5C2E" } },
  "body":           { "typography": { "color": "#E8E2D4" } },
  "button": {
    "typography": { "color": "#0A0A12", ":hover": { "color": "#F2EEE5" } },
    "background": { "fill": "#FF5C2E", ":hover": { "fill": "#C73310" } },
    "border":     { "width": "2px", "style": "solid", "color": "#FF5C2E",
                    ":hover": { "color": "#C73310" } },
    "shadow":     { "box": "none" }
  }
}
```

**`border: {"width": "0"}` on `_band` is not redundant, and it is an IMPROVEMENT on the v1
seed rather than a way of matching it.** The role DEFAULTS to the 1px rule v1's full-width
layout drew in `@color-border`, a light grey. The v1 seed set `--cta-bg` to the same
near-black and set no border slot, so that fallback resolved and v1 DID draw two light-grey
hairlines across the dark band — an artefact nobody chose. (An earlier version of this note
claimed v1 "never had" them; #1026's review measured it and the opposite is true.) Zeroing
the border drops the artefact, and it is the migration step every dark band takes.

**`heading` and `text` both carry the measure.** v1's single `--cta-heading-measure` slot fed
two elements: the heading on every layout, and the full-width text block. Setting only
`heading` leaves the body wider than the title, which reads as a mistake rather than a design.

## Layouts

`layout` selects geometry, not colour:

- **`full-width`** — a centred block. Centres its TEXT as well as its boxes, from the
  stylesheet, keyed to the modifier: it is the default layout, no role ships a
  `typography.align` default, and a layout that does not do what its name says is a
  default-experience regression. An authored `typography.align` still wins.
- **`inline`** — a column under 768px, a `space-between` row above it.

## What no role reaches

- **List and prose rhythm inside `body`.** The body is `pp_kses_inline`, so it carries no
  lists; the shared prose mechanisms are not cta's design.
- **The scrim's own geometry beyond `position` / `size` / `repeat`.** Per-breakpoint art
  direction and video backgrounds are out of scope pending their own ruling (A2).
- **A band's darkness as a trigger.** The engine knows an overlay is painted and says so
  with `data-pp-band-overlay`; it cannot know that a fill you chose is dark.

## Structural CSS

`assets/css/components.css`, the `COMPONENT: cta` block. **Structural only** (BUILD-SPEC §2):
layout scaffolding and accessibility affordances. No colour, type, size, spacing, border or
shadow value may be added there — `tests/js/css-lint.test.js` enforces it on exactly that
block. The band root carries no rule of its own.

The pair row keeps `gap: var(--space-sm)` as the `buttons` role's default, not as a
stylesheet literal; the row's `flex-wrap` and packing stay structural.

## Stated defaults (and what would reopen them)

Every default below is what v1 RENDERED, read in Chromium at 375 / 768 / 1280 before it was
written into the schema — not what the stylesheet declared. The two differ whenever an
ancestor constrains a value, a media query scopes it, or a fallback is an inherited keyword,
and all three of those happened here.

- **The `_band` border is per-edge** — 1px top and bottom, zero left and right — because
  that is what v1's full-width band drew. **What would reopen it:** a band shape where the
  side edges are visible (a radius large enough to expose them, or an inset band), which
  would make the asymmetry a rendering defect rather than a faithful port.
- **`body` carries breakpoint maps for colour, size, weight and leading.** v1 had no
  unconditional rule for the weight or the leading at all: both lived only inside two media
  blocks, and the colour split between an unscoped rule and a desktop one. **What would
  reopen it:** a measured report that the phone tier and the wider tiers should now agree —
  which is a type-scale decision for the whole theme, not a cta one.
- **`heading` declares no `typography.color`.** v1's rule was
  `color: var(--cta-heading-color, inherit)` — the fallback was the inherited value, not a
  value — so the faithful port is no default, and the heading keeps following its band.
  **What would reopen it:** a reported incident where a heading inherits an unreadable
  colour from a band an author styled, often enough that a default is safer than inheritance.
- **`button` and `body-link` declare nothing at all.** **What would reopen either:** a
  measured case where the global rule they defer to stops reaching the element — not a
  preference for explicitness, because the cost of being wrong here is the #545 defect
  (an author's nested button repainted accent-on-accent) rather than a missing value.
- **`button-secondary` duplicates the `button-secondary` preset's values.** **What would
  reopen it:** #1018 landing, which would let a role name its own preset as its default and
  retires the duplication outright.

The bar for adding a control is a NAMED INCIDENT, not a hypothetical: a report where the
default produced a wrong render that no authored value could fix.

## Retired style slots

All 40 `--cta-*` slots are retired — not renamed and not deprecated. Every one is recorded
slot by slot in `SLOT_RENAME_MIGRATION_NOTES` in `tests/SchemaValidationTest.php`, each
naming the role and parameter that owns the value today. **Those per-slot routes are a test
fixture, not a runtime message:** `wp pp check page` reports a stored slot as
`invalid_style_slot` with the GENERIC v2 clause, which says cta declares no style slots and
lists its eleven roles, rather than the individual slot's route. Read a role's real
parameters from `schema.json`, or with `wp pp schema cta`. The mapping is mechanical: find the element the slot named, find the role whose
selector is that element, put the value in the group that owns the property.

`--cta-accent` and `--cta-accent-hover` are the two that do NOT map one-to-one: they were
band-level knobs that coloured both buttons' fill and ring at once. In v2 that is two role
values per button. A site-wide accent is still one `update_design_token` write on
`--color-accent`, which is what most uses of those slots actually wanted.

## What NOT to change

- `schema.json` without updating this README and the repo-root `AI_CONTEXT.md`.
- The structural block's boundary: adding a designable declaration there fails CI.
