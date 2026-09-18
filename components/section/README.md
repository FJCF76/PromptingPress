# Component: section

Generic content band: a heading block plus rich-text body, with an optional image
column, an optional inline-items strip, or an optional right-hand content panel. Use it
for "what is this", "how it works", and any narrative content block.

**section is on the Universal Design Contract (v2).** It declares **no style slots** and
**no named recipes**. Every designable value — colour, type, spacing, border, shadow,
size, crop, motion — is set through the `udc` map on the band, per role. See
`docs/v2/BUILD-SPEC-sprint0.md` §3, and `ai-instructions/style-component.md` for the
authoring shape.

## Props

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `id` | string | No | `''` | HTML id for anchor linking. The author's anchor name, NOT the band id that scopes styling — the engine mints that one. |
| `title` | string | No | `''` | Section heading (`<h2>`). Plain text; HTML is escaped except the accent span. |
| `title_accent` | string | No | `''` | Exact substring of `title` to wrap in the accent span. Colour it through the `heading-accent` role. |
| `subheading` | string | No | `''` | Supporting line below the title. |
| `eyebrow` | string | No | `''` | Short kicker above the title. Its pill treatment — background, radius, border, casing — is the `eyebrow` role's design. |
| `body` | string | No¹ | `''` | The band's main prose surface. Rich HTML (sanitized via `wp_kses_post`): block markup, lists, headings and links are allowed. Optional since #488 — omit it for a `body_items`-only strip or a panel-only band. Links inside it are the `body-link` role. |
| `body_items` | array | No | `[]` | Row of short plain-text items after the body (the "trust strip"). At most 8 items, each at most 80 characters; an over-bound or non-string entry is rejected at write time. The row is the `inline-items` role. |
| `body_items_align` | enum | No | `start` | How the row packs its lines: `start` (left-packed, line-leading separators clipped) or `center` (centred, separator trailing as a line-end dot). Scaffolding, not styling — see "Why two props survived" below. |
| `body_marker` | enum | No | `disc` | List marker for top-level `<ul>` lists in `body`: `disc` / `check` / `dash` / `arrow`. Chooses WHICH glyph; its colour is the site-wide `--pp-list-marker-color` token — see "What narrowed". |
| `layout` | enum | No | `text-only` | Structural layout: `text-only` / `image-left` / `image-right` / `centered` / `text-panel`. See Variants. |
| `image_url` | string | No | `''` | The image column's source on `image-left` / `image-right`. This is the band's CONTENT image; a band BACKGROUND is `_band` `background.image` instead. |
| `image_alt` | string | No | `''` | Alt text for that image. Empty only if it is purely decorative. |
| `image_id` | number | No | `0` | Media Library attachment id for that image. When it resolves, the image renders responsively (`srcset`/`sizes`); falls back to `image_url`. |
| `panel_heading` | string | No | `''` | `text-panel` only: the panel's heading (`<h3>`). Plain text. |
| `panel_body` | string | No | `''` | `text-panel` only: an intro paragraph inside the panel, above the list. Plain text. |
| `panel_items` | array | No | `[]` | `text-panel` only: panel list entries. Each entry is EITHER a plain string (a bullet) OR a paired row `{ label, value }`. See "Content panel". |
| `panel_items_marker` | enum | No | `disc` | `text-panel` only: marker for the string entries of `panel_items`: `disc` / `check` / `dash` / `arrow`. Paired rows never show a marker. |
| `panel_cta_text` | string | No | `''` | `text-panel` only: the panel CTA's label. The button renders only when both this and `panel_cta_url` are set. Its look is the `panel-cta` role. |
| `panel_cta_url` | string | No | `''` | `text-panel` only: the panel CTA's destination. Absolute URL, site-relative `/path`, `#anchor`, `mailto:` or `tel:`; a disallowed protocol (`javascript:`, `data:`) is rejected at write time. |

¹ **Content requirement (#488):** `body` is optional, but a band must carry at least one
of `body`, `body_items`, or panel content (`panel_heading` / `panel_body` /
`panel_items` / a panel CTA). A fully-empty band is rejected at write time
(`invalid_composition`). A `title` alone does not satisfy the requirement.

**The image and panel props are REFUSED where they paint nothing.** `image_url` /
`image_alt` / `image_id` on `text-only`, `centered` or `text-panel`, and the six panel
props on any layout but `text-panel`, are rejected at write with `inert_prop` and a
message naming the layout to set instead. This is `refuse_props_when` in `schema.json`:
a value that would be stored, reported applied, and paint nothing is refused rather than
accepted quietly.

### Four props that used to exist

- **`theme`** (`default` / `muted` / `inverted`) — REMOVED. A tone preset is a bundle of
  designable values, which the UDC expresses directly: set `_band` `background.fill` and
  the text roles' `typography.color`. Identical reasoning to testimonials' `theme` in
  #958 and hero's variants in #986.
- **`title_align`** (`start` / `center`) — REMOVED. Its effect was `text-align` plus auto
  inline margins. Use the `heading` / `eyebrow` / `subheading` roles'
  `typography.align`, and their `spacing.margin-left` / `margin-right` set to `auto` to
  centre the block — per breakpoint if you want, which the prop could never do.
- **`background_image`** (string) — REMOVED in favour of `_band` `background.image`, with
  its overlay on `background.overlay` and its focal point on `background.position`:
  three values the one prop used to imply. **NARROWING:** the prop took a URL STRING;
  `background.image` takes a Media Library **attachment id**, which is what lets the
  theme resolve responsive sources and the attachment's own alt text. Import the file
  first (`import_media` returns `{attachment_id, …}`) and pass that id.
- **`panel_cta_variant`** (`primary` / `secondary` / `outline` / `ghost`) — REMOVED. A
  variant is a bundle of button colours, which is exactly what a preset is: put
  `"_preset": "button"` (or `"button-secondary"`) on the `panel-cta` role and override
  anything you like beside it.

### Why two props survived

`layout` and `body_items_align` STAY, because the UDC taxonomy has **no layout group**:
removing them would delete the capability rather than move it.

`body_items_align` is the subtler of the two, and it is worth being exact about why it is
a prop. It does not set a value — it selects a **wrap technique**: a `justify-content`
value *plus* the separator mechanism that technique requires. `start` clips the
line-leading separator so none ever dangles at the start of a wrapped line (the #489
hanging-separator fix); `center` moves the separator to a trailing position where it
stays visible as a line-end dot. Those are two different mechanisms, not two values of
one, so there is nothing for a role parameter to hold.

## Roles

| Role | Selector | What it owns |
|---|---|---|
| `_band` | the `<section>` | band padding, background (`fill`, `image`, `overlay`, `position`), border, radius, shadow |
| `header` | `.section__header` | the header block's own spacing and alignment |
| `eyebrow` | `.section__eyebrow` | the kicker pill: background, border, radius, casing, ink |
| `heading` | `.section__title` | the `<h2>`'s type, colour, measure and the rhythm below it |
| `heading-accent` | `.section__title-accent` | the accent span inside the heading |
| `subheading` | `.section__subheading` | the supporting line's type, colour and rhythm |
| `body` | `.section__content` | the prose surface's type, colour and measure |
| `body-link` | `.section__content a` | links inside the prose, with their own `:hover` |
| `columns` | `.section__columns` | the gap between the text column and the image column |
| `inline-items` | `.section__inline-items` | the trust-strip row's type, colour and gaps |
| `media` | `.section__image` | the content image's radius, crop ratio and focal point |
| `panel` | `.section__panel` | the panel's surface: background, border, radius, padding, shadow, base type |
| `panel-heading` | `.section__panel-heading` | the panel's `<h3>` |
| `panel-body` | `.section__panel-body` | the panel's intro paragraph |
| `panel-list` | `.section__panel-list` | the panel list's indent and rhythm |
| `panel-row` | `.section__panel-row` | a paired row's gap and alignment |
| `panel-row-label` | `.section__panel-row-label` | the left-hand label's type |
| `panel-row-value` | `.section__panel-row-value` | the right-hand value's type |
| `panel-cta` | `.section__panel-cta` | the panel button, usually via `"_preset": "button"` |

**Nineteen roles is deliberate, and it was argued down and back up again.** A mid-sprint
draft collapsed the five `panel-*` text roles into the `panel` role on the grounds that
one panel needs one design. That is wrong for the thing the panel is actually used for: a
spec sheet, price summary or config readout wants the LABEL small and tracked and the
VALUE carrying the weight, and collapsing them makes that composition inexpressible
without per-item styling — which v2 does not have (see `panel_items` below). A role
exists for each visual job the component really performs, and the panel performs six.

### Worked example — a dark band with a background image

```json
{
  "component": "section",
  "props": { "title": "How it works", "body": "<p>Three steps.</p>" },
  "udc": {
    "_band": {
      "background": { "image": 412, "overlay": "rgba(9, 12, 20, 0.72)", "position": "center" },
      "spacing": { "padding-top": "@space-2xl", "padding-bottom": "@space-2xl" }
    },
    "heading": { "typography": { "color": "#ffffff" } },
    "body": { "typography": { "color": "rgba(255, 255, 255, 0.82)" } },
    "body-link": {
      "typography": { "color": "#9ec5ff", ":hover": { "color": "#ffffff" } }
    }
  }
}
```

### YOU own the contrast

A band background is one value and the text roles are others. Setting `_band`
`background.fill` or `background.image` does **not** recolour the text: put a colour on
every text role that sits over your new background — `heading`, `subheading`, `body`,
`inline-items`, and `body-link` (which needs its `:hover` too, or the link colour you set
at rest will still hover to the theme accent). The old `theme: "inverted"` did this for
you as a bundle; the trade is that you can now build a band the bundle could not express.

## Variants

Layout (`layout`):

- **text-only** — a single full-width text column. Articles and prose.
- **image-left** — two columns at 768px+: image left, text right.
- **image-right** — two columns at 768px+: text left, image right.
- **centered** — a single narrower centred column. Short intros and taglines, not
  multi-paragraph content.
- **text-panel** — text column beside a content panel at 768px+, stacked text-then-panel
  below that. See "Content panel".

If `image_url` is empty, `image-left` / `image-right` fall back to `text-only`. A
`text-panel` with no panel content falls back to `text-only` the same way.

There is no tone axis any more: a `muted` or `inverted` band is `_band`
`background.fill` plus the text roles' colours.

### Content panel (`text-panel` layout)

`panel_items` entries are EITHER plain strings (bulleted list items) OR paired-row
objects `{ label, value }`, rendered as a two-part row — label left, value right at
768px+; below that the pair stacks label-above-value on one shared left edge, the label
smaller and tracked, the value carrying the weight, so a long value gets the panel's full
measure and the pair still reads as label-then-fact. String and paired-row entries mix in
one list. Use it for a spec sheet, pricing summary, stat readout or config list.

A monospace data panel is a composition of generic parts — `panel`
`typography.family: "@font-mono"`, paired rows, a dark `panel` `background.fill`, an
accented `panel-row-value` — not a named mode. Full grammar and worked examples:
`ai-instructions/composition.md`.

**Per-row styling is retired (#1023).** On 1.x a paired row could carry its own `style`
map to emphasise one row. The v2 engine addresses **roles, not individual items**, so
there is no per-item replacement today: the `panel-row` / `panel-row-label` /
`panel-row-value` family is styled once for every row. Per-item addressing is the subject
of **#1024**; until it lands, a single emphasised row is not expressible and the way to
draw the eye is the row's own content.

## Structural CSS

`assets/css/components.css` keeps only layout scaffolding, wrapper geometry and
accessibility affordances for this component: the column tracks and their stacking
breakpoint, the panel's flex skeleton, the row's label/value axis switch, the image box's
`object-fit`, and the inline-items wrap plumbing. No colour, type, size, spacing, border,
shadow, aspect-ratio or object-position value may be added there —
`tests/js/css-lint.test.js` fails CI on one.

Section's block went from 40,852 bytes to 6,969. Four families were deleted rather than
moved, and each was genuinely dead or genuinely retired:

- the `theme` variant rules and the background-image variant with its `.section__overlay`
  element — retired surface, replaced by `_band` `background`;
- the four `main > .section` "premium typography" rules — **dead code**: they live in the
  `pp-v1` cascade layer, and a role's block is emitted *unlayered*, so it wins
  regardless. They had stopped painting the moment the roles took over;
- the four band-padding rules — `_band` `spacing` owns them;
- the `.section--text-only` / `--centered` measure caps — the `body` role's
  `sizing.max-width` owns them.

The authored-prose mechanisms — list markers and indent restored after `base.css`'s
reset, the `p + p` rhythm, the panel list's item rhythm, the mobile inter-pair margin and
the inline-items separator — moved OUT of section's banner into a shared
`/* ===== SHARED: GLYPH AND PROSE MECHANISMS ===== */` block. They are shared by every
component that carries rich text, and the css-lint slicer judges a component's block as
that component's stylesheet, so rules that are not one component's cannot live there.
The prose-list rule is written `.section__content :is(ul, ol)` rather than as a comma
list on purpose: a scoping prefix added later cannot leave half the list unscoped.

## Stated defaults (and what would reopen them)

These values are deliberate product defaults, not oversights. Each names the condition
that would reopen the decision. Adding a control needs a **named incident** — a real
composition that could not be built — not a hypothesis.

| Default | Why it is a default | What would reopen it |
|---|---|---|
| `body` measure: **`max-width: 49rem`** | This is the measure that actually rendered on v1, and it is not the number the old stylesheet read first. Four rules capped `.section__content`, and the desktop `main > .section--text-only` override at `49rem` was the winner; the `42rem` base merely came first in source. The role default carries the value that shipped, not the value that was easiest to read off the file. | A brand specification that needs a different measure — which is one `body` `sizing.max-width` away and needs nothing here to change. |
| `heading` measure: **`none`** | The section title has never carried a cap, and `section` is the most-used band, so it stays uncapped rather than inheriting the shared `--measure-heading` token. | A composition whose long titles need a cap — again one role value away. |
| The `body_items` separator **glyph** is fixed to a middot (`content: "\00b7" / ""`) | The separator is CSS-generated, never a content character, so it stays out of the accessibility tree (the `/ ""` alternative text empties its a11y name). Fixing the glyph is what lets the box be exactly `var(--space-sm)` wide, which is why the left-pull is an exact token value independent of any glyph's advance width. | A composition needs a non-middot separator. Cheap when it comes: the box is deliberately glyph-independent, so a swap would not move the layout. |
| `.section__content p + p { margin-top: 1.05rem }` | A typographic value tuned against the composed-page body scale (`1.065rem` at `line-height: 1.76` on desktop), deliberately ~5% larger than `--space-md` so paragraph separation still reads against that loose leading. Snapping it to `--space-md` for tidiness would be a real 5% regression, not a cleanup. | A site retunes the `body` role's size or leading far from those values and paragraph rhythm stops reading. |

## What narrowed

Two capabilities are smaller after the rebuild. Both are recorded as narrowings rather
than moves, and neither is an oversight.

**1. Glyph colour is site-wide, not per-band.** `--section-separator-color`,
`--section-body-marker-color` and `--section-panel-marker-color` were three authorable
per-band colours. Every one of those marks is drawn with `content` on a `::before` or
`::after`, and **ruling A3 defers pseudo-elements to their own ruling**, so no role can
express them at any value — they are mechanism by construction, not by classification.
Their colour now flows from the site-wide `--pp-list-marker-color` design token, whose
fallback is `var(--color-accent)`: the exact value all three slots defaulted to, so
nothing moves visually. What is lost is per-band control. Set the token to recolour every
glyph in the site at once.

**2. The body-less strip no longer flushes its own top margin.** On v1 the template
inferred `$has_body_copy` and emitted a `--flush-top` modifier that zeroed the
inline-items row's top margin, so a strip with no body above it sat centred in the band's
own symmetric padding. A v2 role default is per COMPONENT, not per content shape, and
inferring design intent from whether a prop is empty is exactly the class of hidden rule
the UDC exists to remove. **To get the old behaviour, set it:**

```json
"inline-items": { "spacing": { "margin-top": "0" } }
```

The visible effect if you do not: a body-less trust strip carries `@space-md` above it,
so it sits slightly below the band's optical centre.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: section === */`, plus the
shared `/* ===== SHARED: GLYPH AND PROSE MECHANISMS ===== */` block described above.

## Retired style slots

All **47** are gone, not renamed and not deprecated. Every one is recorded slot by slot
in `SLOT_RENAME_MIGRATION_NOTES` in `tests/SchemaValidationTest.php`, each naming the
role and parameter that owns the value today — including the four that are not plain
moves (`--section-image-position`, which is why the engine grew `sizing.object-position`,
and the three glyph colours above). Read a role's real parameters from `schema.json`, or
with `wp pp schema section`.

## What NOT to change

- Do not call WordPress functions in `section.php`. Use `pp_*` wrappers.
- Do not add raw hex colours, or any other designable value, to `components.css`. Colour
  belongs in a role.
- Do not reintroduce a `theme` prop or a variant class for tone. One styling system.
