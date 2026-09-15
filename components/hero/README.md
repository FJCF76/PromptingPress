# Component: hero

The opening band of a page: an optional eyebrow, an `<h1>`, an optional supporting
line, up to two CTAs, and optional media or trust-signal markup.

**hero is on the Universal Design Contract (v2).** It declares **no style slots** and
**no named recipes**. Every designable value — colour, type, spacing, border, shadow,
size, crop — is set through the `udc` map on the band, per role. See
`docs/v2/BUILD-SPEC-sprint0.md` §3, and `ai-instructions/style-component.md` for the
authoring shape.

## Props

| Prop            | Type   | Required | Default      | Description |
|-----------------|--------|----------|--------------|-------------|
| `id`            | string | No       | `''`         | HTML id for anchor linking. The author's anchor name, NOT the band id that scopes styling — the engine mints that one. |
| `title`         | string | Yes      | —            | The headline. Plain text; HTML is escaped except the accent span. |
| `title_accent`  | string | No       | `''`         | Exact substring of `title` to wrap in the accent span. Colour it through the `title-accent` role. |
| `eyebrow`       | string | No       | `''`         | Short kicker above the headline. Its pill treatment is the `eyebrow` role's design. |
| `subheading`    | string | No       | `''`         | Supporting line under the headline. |
| `button_text`   | string | No       | `''`         | The primary CTA's label. Its look is the `cta` role. |
| `button_url`    | string | No       | `'#'`        | The primary CTA's href. |
| `button2_text`  | string | No       | `''`         | The second CTA's label; the button renders only when this is set. Its look is the `cta-secondary` role. |
| `button2_url`   | string | No       | `'#'`        | The second CTA's href. |
| `layout`        | enum   | No       | `centered`   | `left`, `centered`, `split`, `cover`. Structural — see Layout below. |
| `split_ratio`   | enum   | No       | `50-50`      | `50-50`, `60-40`, `40-60`. The split row's grid tracks. Structural. |
| `vertical_align`| enum   | No       | `center`     | `top`, `center`, `bottom`, `stretch`. Cross-axis alignment on split/cover. Structural. |
| `image_url`     | string | No       | `''`         | The SPLIT layout's media column. NOT a band background — see Layout. |
| `image_alt`     | string | No       | `''`         | Alt text for that image. |
| `image_id`      | number | No       | `0`          | Media Library attachment id for that image, for responsive srcset. |
| `proof`         | string | No       | `''`         | Rich HTML (kses-sanitized) for trust signals. Renders as the `proof` row, or as the `surface` panel on a split layout. |

### Four props that used to exist

- **`spacing`** (`compact` / `default` / `spacious`) — REMOVED. It was a three-step
  bundle of vertical padding. Set `_band` `spacing.padding-top` and
  `spacing.padding-bottom` directly, per breakpoint if you want — which the prop could
  never do.
- **`width`** (`narrow` / `default` / `full`) — REMOVED. It was a bundle of content
  measure, and one of the six interacting width mechanisms #908 reported. Set the
  `content` role's `sizing.max-width`.
- **`button_variant`** / **`button2_variant`** (`primary` / `secondary` / `outline` /
  `ghost`) — REMOVED. A variant is a bundle of button colours, which is exactly what a
  preset is: put `"_preset": "button"` (or `"button-secondary"`) on the `cta` /
  `cta-secondary` role and override anything you like beside it. THE DEFAULT PAIR STILL
  LOOKS LIKE A PAIR: `cta-secondary` carries the v1 outline treatment as its role
  default, so an unauthored hero renders a filled primary beside a muted, bordered
  secondary exactly as it did on v1 — the prop went, the default did not.

`layout`, `split_ratio` and `vertical_align` STAY, because the UDC taxonomy has no
layout group: removing them would delete the capability rather than move it.

## Roles

| Role | Selector | What it owns |
|---|---|---|
| `_band` | the `<section>` | band padding, background (including `image` + `overlay`), border, radius, shadow |
| `inner` | `.hero__inner` | the gap between the text column and the media column |
| `content` | `.hero__content` | the text column's gap and its measure |
| `eyebrow` | `.hero__eyebrow` | the kicker pill: background, border, radius, casing, ink |
| `title` | `.hero__title` | the `<h1>`: size, ink, leading, measure, rhythm |
| `title-accent` | `.hero__title-accent` | the accented substring's ink |
| `subtitle` | `.hero__subtitle` | the supporting line: size, ink, measure |
| `cta-group` | `.hero__cta-group` | the button row's gap |
| `cta` | `.hero__cta--primary` | the primary button |
| `cta-secondary` | `.hero__cta--secondary` | the second button — the only role with a colour-bearing default (see below) |
| `proof` | `.hero__proof` | the trust-signal row |
| `surface` | `.hero__surface` | the split layout's proof panel |
| `media` | `.hero__image` | the split layout's image: radius, and its crop ratio |

### The two CTA roles are deliberately asymmetric

Role defaults outrank presets. A default on `cta` would suppress exactly the part of a
`button` preset that makes it a button — you would get the type, padding and motion and
not the fill. So **`cta` declares no design defaults at all**, and a preset lands whole:

```json
"udc": {
  "cta":           { "_preset": "button" },
  "cta-secondary": { "_preset": "button-secondary", "border": { "radius": "0" } }
}
```

Anything you set beside a preset wins over it.

**`cta-secondary` DOES declare defaults, and that is the deliberate part.** Both buttons
render as a bare `.btn` now that `button2_variant` is gone, so with nothing declared an
unauthored hero showed two identical filled buttons side by side. v1 defaulted the second
to `outline`. Losing that is a default-experience regression, not a narrowing worth
documenting, so the role carries the v1 outline treatment — muted surface, body ink,
border-coloured 2px edge — with every value an `@token` reference, so a retheme moves it
and no colour is baked into schema data.

The cost, stated rather than discovered: those are the `button-secondary` preset's own
values, duplicated. Because role defaults outrank presets, applying a DIFFERENT preset to
`cta-secondary` is partly suppressed by them. That duplication is temporary — #974 lets a
role name its own preset as its default, and retires this block when it lands.

### Worked example — a dark hero with a background image

```json
"udc": {
  "_band": {
    "background": { "image": 42, "overlay": "rgba(10,10,18,0.62)" },
    "spacing":    { "padding-top": "7rem", "padding-bottom": "6rem" }
  },
  "title":        { "typography": { "color": "#F2EEE5", "align": "center" } },
  "title-accent": { "typography": { "color": "#FF5C2E" } },
  "subtitle":     { "typography": { "color": "#E8E2D4", "align": "center" } },
  "cta":          { "_preset": "button" }
}
```

`image` is a Media Library **attachment id**, never a URL: the engine resolves it,
verifies it is a real image on this site, and builds the `url()` itself.

### YOU own the contrast

v2 has no variant-scoped defaults and does not guess. The v1 `cover` variant re-coloured
the title, the accent and the subtitle on the assumption that a cover band is dark; it no
longer does. A band carrying a dark background or a background image owns its own
contrast — set `typography.color` on every text role over it, and check each against the
background for WCAG AA.

## Layout

`layout` selects geometry, not colour:

- **`left`** / **`centered`** — one column, aligned to the start or centred.
- **`split`** — text left, media right from 1024px. If a split hero has neither media
  (`image_url` / `image_id`) nor `proof`, there is nothing for the second column, so it
  degrades to `left` at RENDER time; the stored prop is not rewritten.
- **`cover`** — a tall (`min-height: 70vh`), centre-aligned band.

**A layout that says "centered" centres the TEXT too.** `centered` and `cover` centre
the content box AND the text inside it, from the stylesheet, keyed to the layout class.

That is a correction of an earlier v2 cut, and the reasoning is worth keeping. The first
pass moved text alignment out to the roles on the principle that a layout aligns boxes
and an author aligns text. It read well and rendered wrong: `centered` is the DEFAULT
layout, no role ships a `typography.align` default, and every box here carries a measure
cap — so a wrapping headline sat ragged-left inside a centred box while this file and the
schema both said "centered centers all content". Single-line text hid it.

Alignment driven by a layout MODIFIER is the modifier's geometry, the same category as
the `align-items` beside it. An authored `typography.align` on a role still overrides it,
and now does so structurally rather than by specificity luck: authored band blocks are
unlayered and the stylesheet lives in `@layer pp-v1`.

**`cover` no longer paints `image_url` as a background.** A band background image is
`_band` `background.image`, which means any layout can carry one — not just this one.
`image_url` / `image_id` now mean the split layout's `<img>` and nothing else.

**And the engine supplies what a background image needs.** Set `background.image` and you
get `background-size: cover`, `background-repeat: no-repeat` and `background-position:
center` unless you set them yourself — v1's `.hero--cover` values, which are also what
anyone means by putting a photograph behind a band. Without them an authored image
painted at its intrinsic size, tiled, anchored top-left. Set `background.size` (or
`.repeat`, or `.position`) and yours wins.

**A scrim gets the accessible focus ring automatically.** When a band carries both an
image and an `overlay`, the engine marks it and the focus ring switches to
`--color-accent-on-overlay`. v1 keyed that to `.hero--cover`, which stopped following the
thing it described once any layout could carry an image: a bare accent ring is 1.17:1
over a dark scrim, a WCAG 1.4.11 failure. Structural, emitted, not something you can
forget to switch on — the same posture as the reduced-motion guard.

## Structural CSS

`assets/css/components.css` keeps only layout scaffolding, wrapper geometry and
accessibility affordances for this component: the flex/grid skeleton, the split tracks,
`cover`'s `min-height`, the eyebrow's `align-self`, and the media box's `object-fit` /
`object-position`. No colour, type, size, spacing, border, shadow or aspect-ratio value
may be added there — `tests/js/css-lint.test.js` fails CI on one.

Two helper families were retired with the slot map and are **absent**: the
`.hero__surface-label` / `-list` / `-item` / `-key` / `-value` classes that used to style
author-written markup inside the proof panel. They were pure value-styling, and they are
not roles either, because hero does not render them — an author does. Write plain
semantic markup in `proof`; it inherits the `surface` role's typography.

## Stated defaults

These values are fixed by the theme's structural CSS or by a role default, and each one
is deliberate rather than unexamined.

- **Headline leading: `line-height: 1.03`.** A hero headline is display type, often two
  or three words per line at desktop, and body leading would leave it looking loosely
  set; 1.03 keeps the lines as one mass without clipping descenders at the clamp's
  largest step. It is a role default, so a band can override it.
- **Subtitle measure: `max-width: 40ch`.** The supporting line is read once, fast, and a
  measure near 40 characters keeps it to two or three lines beside a large headline
  instead of running the full content width. It is the `subtitle` role's
  `sizing.max-width`, so a band that wants a longer line sets one.
- **Split tracks: `minmax(0, 1.08fr)` and `minmax(0, 0.92fr)`.** The default split is
  deliberately NOT 50/50 by area: the text column carries the headline and both CTAs, so
  it takes the slightly larger share, and `minmax(0, …)` is what lets a long unbroken
  word shrink its track instead of overflowing the row. `split_ratio` selects the
  alternatives.
- **Split ratio `60-40` renders `3fr 2fr`** (and `40-60` renders `2fr 3fr`). Whole-number
  fractions rather than percentages, so the gap between the columns is subtracted from
  the row once by `grid` rather than compounding into each track's percentage.

**What would reopen it** (the reopening condition for any default above): a NAMED INCIDENT — a real brand whose
specification these values cannot express, or a measured legibility failure at a stated
viewport. Not a preference, and not a second opinion about taste: every one of these is
a role default or structural geometry, so a band that disagrees can already say so in its
`udc` map without anything here changing.

- `_band` padding is hero's own opener rhythm (`@space-2xl` desktop, `@space-xl` at
  tablet and phone), deliberately NOT the shared band tier — hero opts out of it, and on
  v2 that opt-out is one role default instead of a pair of stylesheet rules.
- `title` declares **no colour and no weight**. Both inherit: the colour from the band,
  the weight from `base.css`'s shared `h1`–`h6` rule. The weight is left there because
  the theme's `--font-weight-heading` is `650`, which CSS Fonts 4 allows and this
  engine's `font-weight` grammar does not yet accept — referencing it would ship a
  default the validator refuses.
- `title` size is responsive by default: `clamp(3rem, 4.5vw, 4.5rem)` on desktop,
  `clamp(2.5rem, 5vw, 4rem)` below. This is hero's documented exemption from the shared
  band heading scale — an opener is bigger.
- `surface` fills with `@color-surface`. The v1 default was a white-to-surface gradient;
  a gradient cannot carry `var()` colour stops through the value grammar, and a frozen
  literal would stop following a retheme, so the flat token-following fill is the
  default and a gradient is one authored value away.

### Two capabilities that narrowed

**1. The left/split opener rhythm.** v1 gave `left` and `split` heroes a compact opener
(`--space-xl` on both edges) while `centered` and `cover` took `--space-2xl` at desktop —
two stylesheet rules keyed on the variant class. v2 has no variant dimension: a role
default is per COMPONENT, and padding is a designable value the §2 boundary keeps out of
the stylesheet, so there is no legal place left to say "left heroes are tighter". Every
layout now shares one opener rhythm, and a band that wants the compact one sets its own
`_band` `spacing`. The visible effect: an adjacent left/split hero grows from 64px to
112px at desktop.

(Text alignment per variant was on this list in an earlier draft and is NOT a narrowing:
`centered` and `cover` centre their text structurally — see Layout above. It was listed
here while the first v2 cut had moved alignment entirely to the roles, which turned the
default layout into one that did not do what its name said.)

**2. The media focal point.** `object-position` on the media box is a fixed `center` in
the stylesheet now. It was the
v1 `--hero-image-position` slot; the v2 boundary classifies `object-position` as
structural, so it has a home — but it is no longer author-reachable. Recorded as a
narrowing rather than a move. Its sibling, the crop RATIO, **is** authorable: it is the
`media` role's `sizing.aspect-ratio`, a parameter the engine gained because of this
rebuild.
