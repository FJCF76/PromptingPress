# AI Workflow: Compose a Page Using _pp_composition

Use this when asked to build, edit, or populate a page that uses the **Composition** template.

---

## What the Composition template is

Pages set to the **Composition** template (`Page Attributes → Template → Composition`) render
their components from a JSON array stored in the `_pp_composition` post meta key.

The format is AI-native: the same JSON a human edits in the admin meta box is what you write directly.

---

## The format

```json
[
  { "component": "hero",    "props": { "title": "Welcome", "layout": "centered" }, "udc": { "_band": { "background": { "fill": "#0d1117" } }, "title": { "typography": { "color": "#f0f0f0" } } } },
  { "component": "section", "props": { "body": "<p>Content.</p>" } },
  { "component": "faq",     "props": { "items": [{ "question": "Q?", "answer": "A." }] } },
  { "component": "cta",     "props": { "title": "Go", "button_text": "Click", "button_url": "/" } }
]
```

- `component` — must match a registered component name (a folder in `components/`)
- `props` — must satisfy required props from that component's `schema.json`
- `udc` — (optional) **the styling key for every component on the design contract**, which since #1101 is all ten of them. A map of `role → group → parameter → value`, validated against the component's declared roles. Four actions carry it: `update_component` (its `udc` param, merged into ONE band BY ROLE — a sent role replaces that role's map, `null` removes it, unsent roles are kept), `add_component` (the new band's map), and `update_composition` / `create_page` (inside whole bands). See `ai-instructions/style-component.md`, and read a component's roles with `wp pp schema <component>` before writing one
- `style` — (optional) per-instance CSS custom property overrides, validated against the component's `schema.json` → `styling.style_slots`. Only declared slots are accepted. **`grid` is the only component that declares any**, so on anything else this key has nothing to address and `style_component` refuses it with `no_style_slots`. Set grid's via a composition write (`create_page` / `update_composition`), the `style_component` action, or by passing `style` to `add_component` (which writes it onto the new item in one call, validated by the same shared engine — no separate follow-up needed)
- `id` — (optional) an anchor id, which also becomes the band's stable identity. A band with no `id` is given one at write time, and only at write time
- Order in the array = render order on the page
- Any registered component can appear any number of times in any order
- **The composition is an ARRAY, never an object (#724).** Send `[{...}, {...}]`. A JSON object keyed by position — `{"1": {...}, "3": {...}}` — is refused with `unexpected_shape`, because it is not a composition: it is the shape `wp pp check page` classifies as corrupted. Nothing is reindexed for you. This used to be accepted and silently replaced the page with just those entries while reporting `ok:true`, so if you have a script that builds the composition as a keyed map, change it to build a list. Position is expressed by ORDER in the array, never by a key.

---

## Valid component names

See `AI_CONTEXT.md` → Component index for the current list. As of last update:

> `nav` and `footer` are **not** in this table. They are site chrome, rendered on
> every page by `pp_base_template`. Putting either in a composition renders the
> header or footer twice, and the write is rejected with `template_owned_component`.
> See "Site chrome" below for the surfaces that do configure them.

| Name    | Required props                          | Optional props (selection)                              |
|---------|-----------------------------------------|---------------------------------------------------------|
| hero    | title                                   | id, title_accent, eyebrow, subheading, button_text, button_url, button2_text, button2_url, layout, image_url, image_id, image_alt, split_ratio, vertical_align, proof — and NOT `button_variant`, `button2_variant`, `spacing` or `width`, which the v2 rebuild retired (the two button looks are the `cta` / `cta-secondary` roles, band padding is `_band` `spacing`, and the content width is `inner` `sizing.max-width`) |
| section | one of: body / body_items / panel content | id, body, title, title_accent, eyebrow, subheading, layout, image_url, image_id, image_alt, body_marker, body_items, body_items_align, panel_heading, panel_body, panel_items, panel_items_marker, panel_cta_text, panel_cta_url — and NOT `theme`, `title_align`, `background_image` or `panel_cta_variant`, which the v2 rebuild retired (see section.styling below) |
| faq     | items[] {question, answer}              | id, title, title_accent, eyebrow — and NOT `theme`, retired at #1046 — the last of the seven `theme` props the v2 rebuild retired. `grid` is the only component that still has a live one; on every other component the tone is the band's `udc` map |
| grid    | items[] (fields: number, title, text, text_role, bullets[], image_url, image_alt, image_id, link_url, link_text, style — none individually required) | id, title, title_accent, eyebrow, subheading, title_align, layout, card_emphasis, theme, columns, image_treatment |
| table   | headers[], rows[][]                     | title, caption, id — v2 since #1066, and it retired NOTHING (it never declared `theme`) |
| cta     | button_text, button_url                 | title, title_accent, eyebrow, body, button2_text, button2_url, layout, id — and NOT `theme`, `background_image`, `button_variant` or `button2_variant`, which the v2 rebuild retired (see cta.styling below) |
| stats   | items[] {number, label}                 | title, title_accent, id — and NOT `theme` or `background_image`, which the v2 rebuild retired (see stats.styling below) |
| logos   | items[] {image_url, image_alt, image_id?, label?} | title, id — and NOT `theme`, which the v2 rebuild retired (see logos.styling below) |
| embed   | content                                 | title, id — **no `theme`** (retired in #1066; say it in the `udc` map instead) |
| testimonials | items[] {quote (req); optional author, role, company, image_url, image_alt, image_id} | id, title, title_accent, eyebrow, subheading, layout — **and NO `theme` / `title_align`**: testimonials is on the v2 Universal Design Contract, so a tone or an alignment is set through the band's `udc` map (`_band` background, role `typography.align`), not through a prop. See `ai-instructions/style-component.md`. |

## Text content model: which props accept HTML

Every text prop has ONE of three markup contracts, and each schema `description`
states which. Read the description; do not generalize a link from one prop to the
next. The rule (#439):

| Contract | Props | What you may write |
|----------|-------|--------------------|
| **Rich HTML** (`wp_kses_post`) | `section.body`, `faq.items[].answer`, `table.rows[][]` cells, `embed.content`, `hero.proof` | Block markup: paragraphs, lists, headings, links, `strong`/`em`. `section.body` and `faq.items[].answer` are the main prose surfaces; a `table` **cell** takes the same contract (its `headers` and `caption` do not — those are plain text); `embed.content` is additionally passed through `do_shortcode()` after sanitizing, which is why shortcode brackets survive; `hero.proof` is a free-form trust-signal panel. |
| **Inline HTML** (`a, strong, em, br`) | `cta.body`, `grid.items[].text`, `testimonials.items[].quote` | Supporting copy with a link or light emphasis, e.g. `Read our <a href="/terms">terms</a>.` No block elements — `<p>`, `<ul>`, `<h2>` are stripped. |
| **Plain text** (escaped) | Titles, eyebrows, subheadings, `button_text`, `button2_text`, `stats.items[].label`, `stats.items[].number`, `grid.items[].title`, `grid.items[].bullets[]`, `testimonials.items[].author`, `faq.items[].question`, `table.headers[]`, `table.caption`, `logos.items[].label`, `section.body_items[]`, `section.panel_body`, `section.panel_items[]`, and all URLs | Text only. Any `<...>` renders as visible characters, not markup. Note `table` splits its contract: **cells** are rich, **headers and caption** are plain. |

Both HTML contracts are allowlist-sanitized: `script`, `style`, `iframe`, event
handlers (`onclick`), and `javascript:` URLs are always stripped, whoever authored
the content. A link in a supporting-text prop is normal marketing copy — write it
as real HTML (`<a href="...">`), not as escaped source, and never put a link in a
plain-text prop (it will show as literal `<a href=...>` text on the page).

### cta: standalone button (heading-less)

`cta.title` is optional. Omit `title` (and `body`) to render just the button row with no heading element — the sanctioned way to place a standalone button, e.g. a centered "closing" button after a steps or feature section. `button_text` and `button_url` are still required, and `id` and `layout` keep working. Styling is the band's `udc` map — cta has no `theme` and no style slots since the v2 rebuild (#1026).

```json
{ "component": "cta", "props": { "button_text": "Get started free →", "button_url": "/signup" } }
```

### cta: primary + secondary button pair

A closing CTA can offer two actions instead of forcing a choice between them. Set
`button2_text` (and `button2_url`) alongside the primary button props; `button2_variant` is RETIRED
on `cta` and refused with `retired_prop` — the secondary button's look is the
`button-secondary` role in the band's `udc` map now, and the `button-secondary` preset
is the one-line way to get it. This is the `cta` equivalent of the hero's `button2_text` / `button2_url`,
so a closing band does not have to become a `hero` just to offer a secondary action.

```json
{ "component": "cta", "props": {
  "title": "Listo para empezar",
  "button_text": "Ver planes",   "button_url": "/precios",
  "button2_text": "Hablar con nosotros", "button2_url": "/contacto"
} }
```

Omit `button2_text` and the CTA renders exactly as it always has — one button, no
wrapper element. At mobile widths the pair stacks one button per row.

**cta is a v2 component (#1026), so the two buttons are ROLES, not slot families.** The
primary is `button` and the second is `button-secondary`, each with its own block and its
own `":hover"` maps nested inside its groups. They are independent by construction rather
than by a re-pointing rule: nothing is emitted on the band root, so nothing inherits from
one button to the other. `button` declares NO defaults, so `"_preset": "button"` lands
whole; `button-secondary` carries v1's outline treatment, so an unauthored pair still reads
as one filled action beside one outlined one.

### section.styling — there is no `theme` prop

`section` is a **v2 component on the Universal Design Contract**: it declares no style
slots, and `theme` / `title_align` / `background_image` / `panel_cta_variant` are RETIRED.
Writing any of them is refused with `retired_prop` and a message naming the replacement.
Per-band tone is the `_band` role's `background` in the band's `udc` map.

| Old | Write this instead |
|---|---|
| `theme: "muted"` | `"_band": { "background": { "fill": "@color-surface" } }` |
| `theme: "inverted"` | `"_band": { "background": { "fill": "@color-bg-inverted" } }` **plus** a `typography.color` on every text role over it |
| `title_align: "center"` | `typography.align: "center"` on `heading` / `eyebrow` / `subheading`, with `spacing.margin-left` / `margin-right` set to `"auto"` to centre the block |
| `background_image: "<url>"` | `"_band": { "background": { "image": <attachment id>, "overlay": "rgba(…)", "position": "center" } }` — an **attachment id**, not a URL; `import_media` returns one |
| `panel_cta_variant: "outline"` | `"_preset": "button-secondary"` on the `panel-cta` role, overridden beside it |

**A background never recolours text.** The old `theme: "inverted"` did that as a bundle;
a `udc` map does not. Set `typography.color` on `heading`, `subheading`, `body`,
`inline-items` and `body-link` — and give `body-link` a `':hover'` too, or the link colour
you set at rest will still hover to the theme accent. The trade is that you can now build
a band the three-value bundle could not express.

Example — alternating section rhythm:
```json
{
  "component": "section",
  "props": { "body": "<p>...</p>" },
  "udc": { "_band": { "background": { "fill": "@color-surface" } } }
},
{
  "component": "section",
  "props": { "body": "<p>...</p>" },
  "udc": {
    "_band": { "background": { "fill": "@color-bg-inverted" } },
    "heading": { "typography": { "color": "#ffffff" } },
    "body": { "typography": { "color": "rgba(255, 255, 255, 0.82)" } }
  }
}
```

### section.layout

| Value         | Use when                                                     |
|---------------|--------------------------------------------------------------|
| `text-only`   | Default. Full-width text block with left-aligned text.       |
| `centered`    | Short-form text (taglines, intros) with center-aligned text. |
| `image-left`  | Narrative + supporting image, image on the left.             |
| `image-right` | Narrative + supporting image, image on the right.            |
| `text-panel`  | Text column beside a styleable content panel (heading + list + CTA) on the right. |

`text-only` fills the container width — titles and headings match adjacent components (grid, table, CTA). Prose text is constrained for readable line length. `centered` constrains the body block to a narrower column with center-aligned text — use for short intros and taglines, not for multi-paragraph marketing content.

#### section.layout: "text-panel"

An asymmetric two-column layout: the left column is the normal section (eyebrow/title/subheading + `body`), the right column is a self-contained, styleable **content panel** built from props (no nested components). Use it for "text + supporting card/CTA" marketing sections, e.g. a checklist beside a dark panel with its own heading, bullet list, and call-to-action button. Columns sit side by side at ≥768px and stack text-then-panel on mobile.

Panel props (all optional; ignored by other layouts):

| Prop | Renders |
|------|---------|
| `panel_heading` | Panel heading (`<h3>`). |
| `panel_body` | Optional plain-text intro paragraph above the list (text only, no HTML). |
| `panel_items` | Array of panel entries. Each entry is EITHER a plain-text string (a bullet) OR a paired-row object `{ "label": "...", "value": "..." }` rendered as a two-part row (label left, value right at >=768px; label stacked above value below that). Mix freely in one array. A paired-row entry may NOT carry a `"style"` map any more — see "Per-row styling is RETIRED" below. |
| `panel_items_marker` | List marker for the string entries of `panel_items`: `disc` (default) / `check` / `dash` / `arrow`. Not a distinct list type — just the glyph. Paired rows are never bullets, so they never show a marker. |
| `panel_cta_text` + `panel_cta_url` | The panel's CTA button. Both are required for the button to render. Its look is the `panel-cta` role. |

**The panel props are REFUSED on any layout but `text-panel`**, with `inert_prop` and a
message naming the layout to set. Same for the image props on the three layouts that
render no image column. A value that would be stored, reported applied, and paint nothing
is refused rather than accepted quietly.

The panel falls back to a plain `text-only` section when it has no content (no heading, no
body, no list items, and no complete CTA).

**Style the panel through roles, not slots. `section` is a v2 component: it has no style
slots.** The panel is eight roles, one per visual job — the seven in this table, plus the `panel-cta` role described just below it:

| Role | What to set on it |
|---|---|
| `panel` | the surface: `background.fill`, `border.color` / `.width` / `.radius`, `spacing.padding`, `shadow.box`, and the base `typography` |
| `panel-heading` | the `<h3>`'s type and colour |
| `panel-body` | the intro paragraph's type and colour |
| `panel-list` | the list's indent and rhythm (`spacing.padding-left`, `spacing.margin-bottom`) |
| `panel-row` | a paired row's `spacing.gap` and `typography.align` |
| `panel-row-label` / `panel-row-value` | the two halves of a paired row, independently |

For a dark panel set `panel` `background.fill` to a dark colour and put a light
`typography.color` on `panel-heading`, `panel-body`, `panel-row-label` and
`panel-row-value` — the fill does not recolour the text for you. For a monospace data
panel set `panel` `typography.family` to `"@font-mono"`.

The panel CTA is the `panel-cta` role. There is **no `panel_cta_variant` prop any more**:
a variant was a bundle of button colours, which is what a preset is. Put
`"_preset": "button"` (or `"button-secondary"`) on the role and override anything beside
it — `background.fill` for a flat brand fill, `typography.color` for the ink,
`shadow.box: "none"` to flatten the bevel, `border.color` for the ring, and a `':hover'`
nested inside any of those groups for the hover state. Unlike v1 there is no
primary-only restriction and no missing hover fill: whatever you set is what paints.

**Paired label/value rows.** When a panel summarises data — a pricing summary, spec
sheet, plan comparison, stat readout, or config/contact list — give `panel_items` entries
the object form `{ "label": "...", "value": "..." }` instead of strings. Each renders as a
two-part row: label on the left, value on the right, both plain text. String and
paired-row entries mix in one array (a string is still a bullet). **Below 768px the two
columns become two lines by default: the label stacks above its value, both on one shared
left edge, with the label set smaller and tracked and the value carrying the weight so
the pair still reads as label-then-fact without position to carry it, and a wider gap
between one pair and the next.** Do not try to keep two columns on mobile; there is not
room for them, and a long value would wrap to four lines in a ~170px box. It also means a
long `value` is safe: at narrow widths it gets the panel's full measure, so write values
in real words rather than trimming them to fit an imagined column. The stacking itself is
structural, but the label and value TYPE are the `panel-row-label` / `panel-row-value`
roles, and both take a `breakpoints` map, so a brand that wants different narrow-width
type says so there.

**Per-row styling is RETIRED (#1023).** On 1.x a paired row could carry its own `"style"`
map to emphasise one row against its siblings. The v2 engine addresses **roles, not
individual items**: `panel-row-value` is styled once for every row. A stored per-row
`style` key is now an undeclared field and is refused at write. Per-item addressing is
tracked as **#1024**; until it lands, a single emphasised row is not expressible, and the
way to draw the eye is the row's own content. A monospace data panel is still just a
composition of generic parts — `panel` `typography.family: "@font-mono"` + paired rows +
a dark `panel` `background.fill` + an accented `panel-row-value` — not a named mode.

To turn `panel_items` into a check-list (the common "benefits beside a panel" pattern),
set `panel_items_marker: "check"`. `dash` and `arrow` are the other values; `disc` is the
plain default. **On `check`/`dash`/`arrow` the marker's COLOUR is the `panel-list` role's
`marker.color`** (#1028): `"panel-list": {"marker": {"color": "#FF5C2E"}}` recolours the
glyphs. `update_component` replaces a role's whole map, so include the role's other groups in
the same write if it already has any. Unset, they render `var(--color-accent)`. **A `disc` list ignores it**:
disc is the browser's native marker and takes the text colour, so to colour the bullets pick
`check`, `dash` or `arrow` first. The glyph itself is a `::before` no role addresses
(ruling A3); the group sets a colour on the list's own box that the glyph inherits, so it
takes the same state and breakpoint maps as any colour. On a dark panel, set it together
with the panel's own `background.fill` and ink. The same marker capability is
available on `grid` card bullets (always a check) and on `section` body lists
(`body_marker`, below) — one shared treatment, so a check-list is reachable from any
list-rendering surface.

```json
{
  "component": "section",
  "props": {
    "eyebrow": "Honest tool",
    "title": "No fine print",
    "body": "<ul><li>Transparent pricing</li><li>Cancel anytime</li></ul>",
    "layout": "text-panel",
    "panel_heading": "Who is it for?",
    "panel_items": ["Freelancers", "Small agencies", "In-house teams"],
    "panel_cta_text": "Get started",
    "panel_cta_url": "/signup"
  },
  "udc": {
    "panel": { "background": { "fill": "#0f172a" } },
    "panel-heading": { "typography": { "color": "#f8fafc" } },
    "panel-body": { "typography": { "color": "#cbd5e1" } },
    "panel-cta": { "_preset": "button" }
  }
}
```

A monospace spec panel with paired label/value rows:

```json
{
  "component": "section",
  "props": {
    "title": "Environment",
    "body": "<p>Everything this deploy runs on.</p>",
    "layout": "text-panel",
    "panel_heading": "Runtime",
    "panel_items": [
      { "label": "WordPress", "value": "6.7.1" },
      { "label": "PHP", "value": "8.3" },
      { "label": "Uptime", "value": "99.9%" },
      "All checks passing"
    ],
    "panel_cta_text": "View status",
    "panel_cta_url": "/status"
  },
  "udc": {
    "panel": {
      "background": { "fill": "#0f172a" },
      "typography": { "family": "@font-mono", "color": "#f8fafc" }
    },
    "panel-heading": { "typography": { "color": "#f8fafc" } },
    "panel-row-label": { "typography": { "color": "#94a3b8" } },
    "panel-row-value": { "typography": { "color": "#22d3ee" } }
  }
}
```

Note what changed in that second example: v1 emphasised the one `Uptime` row with a
per-row `style`; v2 sets the accent on `panel-row-value`, so **every** value carries it.
That is the same trade named above — one role, every row.

#### section body list markers: `body_marker`

`body_marker` (`disc` default / `check` / `dash` / `arrow`) sets the marker on
**top-level** `<ul>` lists authored in a section's `body` — the same shared marker
treatment the panel and grid use. `disc` leaves body lists exactly as before;
`check`/`dash`/`arrow` apply to lists written as a direct child of the body (nested lists
keep their disc). On `check`/`dash`/`arrow` the marker's colour is the `body` role's
`marker.color` (#1028); unset it takes the accent. A `disc` list ignores it. Use
`body_marker` when a prose section needs a check-list
without moving the content into a grid or panel.

```json
{
  "component": "section",
  "props": {
    "title": "What you get",
    "body": "<ul><li>Transparent pricing</li><li>Cancel anytime</li></ul>",
    "body_marker": "check"
  }
}
```

#### section inline-items row: `body_items`

`body_items` renders a short "trust strip" — a row of brief plain-text items with a
CSS-generated middot (`·`) separator between each. Use it for the slim post-hero meta row
(e.g. "No credit card · Cancel anytime · 30-day guarantee"), not for multi-line content
(use `body` or `panel_items` for that). Each entry is a plain-text string (escaped, no
HTML): at most **8 items**, each at most **80 characters** — a write that exceeds either
bound, or passes a non-string entry, is rejected with `invalid_prop_value` (nothing
persists). The row renders only when non-empty; when both `body` and `body_items` are
set, the row renders after the body.

The row is the `inline-items` role. It carries the body's type as its own role default, so
a strip keeps the band's size and weight without you repeating them; override
`typography.size` / `.weight` / `.color` on the role for a slimmer or bolder strip. The
separator's glyph is a fixed middot, and its colour is the `inline-items` role's
`marker.color` (#1028), like the list markers above. **Unset, it resolves differently from
them on purpose:** the markers land on `var(--color-accent)`, which is what they always
defaulted to, while the separator lands on **`currentColor`**, so it follows whatever
`typography.color` you put on `inline-items`.
That is what the v1 muted default achieved through band-class remaps, which a v2 band has
no class for — so set the row's colour on a dark band and the separator follows it
automatically, with nothing else to set. Residual on a default light band: the middot is
`#101828` — the row is a SIBLING of `.section__content`, so it inherits `--color-text` and
not the `body` role's colour, which means the mark is the SAME ink as the item text beside
it, where v1 painted it one step lighter. To get the old
grey, grey the row: `"inline-items": {"typography": {"color": "@color-muted"}}` moves the
mark and the item text together. **A separator DIFFERENT in colour from its text** (an
accent middot over muted text) is the role's `marker.color`. `update_component` replaces a
role's whole map, so send the role's other authored groups in the same map (a
`typography.color`, the `spacing.margin-top: "0"` strip idiom, a `layout.justify`):
`"inline-items": {"typography": {"color": "@color-muted"}, "marker": {"color": "@color-accent"}}`.
On a row with nothing authored, `{"marker": {"color": "@color-accent"}}` is enough.

Per-line alignment when the strip wraps is the **`body_items_align` prop** (`start` |
`center`, default `start`) — a prop and not a role value, because it selects a wrap
TECHNIQUE rather than a value: a `justify-content` plus the separator mechanism that
technique requires. `start` packs each wrapped line from the left and clips the leading
separator, so no middot ever dangles at the start of a line. `center` centres each wrapped
line, moving the separator to a trailing middot after every item except the last. There is
a documented trade with `center`: because a centred line ends mid-box, its trailing middot
at a wrap point stays **visible** as a subtle line-end dot (an edge-clip cannot remove a
mid-box dot; there is no pure-CSS centred-and-artifact-free option). Choose `center` when
the brand's art direction wants a centred strip at every width and accepts the subtle
line-end dots; keep the default `start` for artifact-free left-packing. A single-line strip
reads centred in both modes, so this only matters where the row wraps (typically mobile).

`body` is optional (#488): a strip whose whole content is `body_items` — no heading, no
paragraph — is a first-class band, so author it with `body_items` alone and no `body` key.
A section must still carry SOME renderable content: at least one of `body`, `body_items`,
or panel content (`panel_heading` / `panel_body` / `panel_items` / a panel CTA). A
fully-empty section is rejected at write time with `invalid_composition`. (A `title` alone
does not satisfy this.)

**A body-less strip no longer flushes its own top margin (#1023).** On v1 the template
inferred that no body copy preceded the row and zeroed its top margin automatically, so a
strip sat centred in the band's own symmetric padding. A v2 role default is per COMPONENT,
not per content shape, and inferring design intent from whether a prop is empty is exactly
the kind of hidden rule the UDC removes. **Say it instead:**

```json
"inline-items": { "spacing": { "margin-top": "0" } }
```

Without it, a body-less strip carries `@space-md` above it and sits slightly below the
band's optical centre. Add it to any body-less strip you want optically centred.

```json
{
  "component": "section",
  "props": {
    "body": "<p>Everything you need to launch.</p>",
    "body_items": ["No credit card", "Cancel anytime", "30-day guarantee"]
  },
  "udc": {
    "inline-items": { "typography": { "size": "15px", "weight": "600" } }
  }
}
```

A body-less trust strip on a dark band, optically centred:

```json
{
  "component": "section",
  "props": {
    "body_items": ["SOC 2 Type II", "99.99% uptime", "GDPR compliant"]
  },
  "udc": {
    "_band": { "background": { "fill": "#0f172a" } },
    "inline-items": {
      "typography": { "size": "15px", "weight": "600", "color": "#e2e8f0" },
      "spacing": { "margin-top": "0" }
    }
  }
}
```

Note both v2 shapes: the dark band is `_band` `background.fill` (there is no `theme` prop
on section any more), and the strip's own colour is set explicitly, because a band
background never recolours text for you.

### grid.layout: "steps"

Renders numbered process cards. Use for How-It-Works or sequential flows. Each card carries a filled circular number badge above its title, and the numbering is what makes the row read as a sequence.

- Set `layout: "steps"` on the grid (the default `layout` is `cards`)
- Include a `number` field on each item (`"1"`, `"01"`, `"Step 1"`, etc.)
- Images are suppressed in the steps layout; use title + text only

```json
{
  "component": "grid",
  "props": {
    "title": "How it works",
    "layout": "steps",
    "items": [
      { "number": "1", "title": "Sign up", "text": "Create your account." },
      { "number": "2", "title": "Configure", "text": "Set your preferences." },
      { "number": "3", "title": "Launch", "text": "Go live." }
    ]
  }
}
```

### grid.card_emphasis — RETIRED (#1101)

**The `card_emphasis` prop is retired**, and it was retired rather than ported. Writing it is refused with `retired_prop`.

v1's `featured` value gave the FIRST card an accent border, a tinted fill, a louder top bar and a glow; `uniform` opted out of all of it. That treatment was `:first-child` — **ordinal** styling — and Addendum B rules that a card is addressed by the id the engine mints for it, so that reordering the list carries a card's design WITH it. Keeping an ordinal treatment beside an id-addressed one would leave two systems disagreeing about which card is special the moment someone reorders.

**Measured on the owner's production site before the prop went:** all 11 grid bands already rendered `grid--uniform` — the featured treatment was switched off everywhere it could have applied.

To make ONE card special now, give that entry its own `udc` map:

```json
{"title": "Lightweight by default", "text": "…",
 "udc": {"card": {"background": {"fill": "#14141F"}, "border": {"color": "#0A0A12"}},
         "card-title": {"typography": {"color": "#F2EEE5"}},
         "card-text": {"typography": {"color": "#E8E2D4"}}}}
```

Remember the pairing: `card-title`, `card-text`, `card-bullets` and `card-link` all pin their own colours, so darkening a card's fill without re-inking them leaves dark text on a dark panel. The schema declares that as an obligation and the write envelope reports it.

### grid.columns: 1 | 2 | 3 | 4

By default a `cards` grid derives its desktop column count from the number of items (2 items -> 2-up centered, 3 -> 3-across, 4 -> 2x2 centered, other counts -> 2-up). Set `columns` to force a specific desktop (768px+) count instead — an integer `1`–`4`. Use it when the auto grain is not what you want: `columns: 3` renders a 6-item grid as 3-across x 2-rows (instead of 2x3), and lets a 4-item grid be 4-across (instead of 2x2). The forced grid spans the container regardless of item count, and the single-column layout below 768px is unchanged.

Unset (omit the key) keeps the auto-by-count default — byte-identical. Values outside `1`–`4`, or non-integers (`0`, `5`, `2.5`, text), are **rejected** with `invalid_prop_value`; they are never silently clamped. `columns` is a `cards` concept and is ignored on the `steps` layout, which keeps its fixed process grain. Structural prop — set with `create_page` / `update_component`, not `style_component`.

```json
{
  "component": "grid",
  "props": {
    "title": "Integrations",
    "columns": 3,
    "items": [
      { "title": "One", "image_url": "..." },
      { "title": "Two", "image_url": "..." },
      { "title": "Three", "image_url": "..." },
      { "title": "Four", "image_url": "..." },
      { "title": "Five", "image_url": "..." },
      { "title": "Six", "image_url": "..." }
    ]
  }
}
```

### grid.image_treatment — RETIRED (#1101)

**The `image_treatment` prop and the `--grid-item-icon-size` slot are both retired.** Writing either is refused — the prop with `retired_prop` naming its route, the slot with `no_style_slots`.

Each card image renders as a full-width 16:9 cover banner, which is the `card-media` role's `sizing.aspect-ratio` default. For the **icon + title + text** feature card — where a ~45px logo would otherwise be blown up into a cropped banner — set the role's box directly in the band's `udc` map:

```json
{"udc": {"card-media": {"sizing": {"width": "48px", "height": "48px", "aspect-ratio": "auto"}}}}
```

**One stated narrowing.** The old `icon` value also applied `object-fit: contain`, and `object-fit` has no typed parameter in the Sizing group, so the un-cropped fit is reachable only through the raw-CSS valve: `"card-media": {"_css": {"object-fit": "contain"}}`. Everything else about the treatment is an ordinary role parameter.

Measured before the retirement, across all 11 grid bands on the owner's production site: **zero** used the icon treatment and zero card images were affected.


### grid items[].bullets

Renders a checklist below the card's `text`, each line prefixed with a check mark — use for scannable feature/benefit lists instead of a dense paragraph. Plain text lines only, no HTML/markdown.

### grid items[].text_role — RETIRED (#1101)

**The `text_role` item field is retired.** Writing it is refused, naming the route.

Its four values were `mono` / `meta` / `label` / `kicker`. **Measured, all four, at desktop:** `mono` and `meta` rendered byte-identically to the default body text — the premium typography tier's own rule out-ranked both presets — so two of the four had no rendered effect at all above 767px. Only `label` (letter-spacing 0.01em) and `kicker` (0.08em plus uppercase) changed anything.

Per-card typography is exactly what an item `udc` map expresses, so the capability is WIDER after the retirement than before it — any typography parameter, not four fixed bundles:

```json
{"text": "SINCE 2019",
 "udc": {"card-text": {"typography": {"letter-spacing": "0.08em", "transform": "uppercase"}}}}
```

For a bundle you reuse across cards, save it once with `save_preset` (#1016) and reference it with `_preset`.

### grid items[].udc — per-card design (Addendum B)

**The `items[].style` field is retired (#1101).** It accepted 21 card-scoped slot names and rendered them as inline custom properties on that card; BUILD-SPEC §3.4 forbids inline style emission outright.

Its replacement is the entry's own **`udc` map**, and it is the reason Addendum B exists: roles are BAND grain, so before it the v2 contract had no address for "this one card".

```json
{"component": "grid", "props": {"items": [
  {"title": "Card A", "text": "…"},
  {"title": "Card B", "text": "…",
   "udc": {"card": {"background": {"fill": "#14141F"}},
           "card-title": {"typography": {"color": "#F2EEE5"}},
           "card-text": {"typography": {"color": "#E8E2D4"}},
           "card-link": {"typography": {"color": "#F2EEE5"}}}},
  {"title": "Card C", "text": "…"}
]}}
```

It is the same shape a band's map takes, validated by the same engine, refused with the same codes, and it addresses the SAME roles the component declares — `card`, `card-bar`, `card-media`, `card-body`, `card-title`, `card-text`, `card-bullets`, `card-bullet`, `card-link` and `step-number` (the component's `item_roles` declaration is the authority).

**Things to know before you write one:**

- **The id is minted on write**, only for entries that carry a map, shape `it-<hex8>`. You never author it. It is carried across a full `items` re-apply by index and component match, which is what makes the design travel with the card rather than with position 2.
- **The band-level roles are not addressable per item.** `_band`, `header`, `eyebrow`, `heading`, `heading-accent`, `subheading`, `list` and `empty` exist once per band, so styling them "for one card" has no meaning.
- **Seven things are REFUSED rather than ignored:** per-item chrome; `_band` inside an item map; ordinal or structural selectors (`nth-child`, `first`, `last`, `even`/`odd`); nesting beyond one level; defining a new preset inside an item (referencing one with `_preset` is fine); pseudo-elements; and `_css` at item grain.
- **Darkening a card is several writes, not one** — see `card_emphasis` above for the pairing, and `components/grid/README.md` for the measured contrast numbers.

### title_accent (hero, section, grid, cta, faq, stats, testimonials)

All seven heading-bearing components accept `title_accent`: an exact, case-sensitive substring of `title` to render in an accent color. It must match `title` literally or it is silently ignored (no accent rendered, `title` still shows in full).

```json
{ "component": "hero", "props": { "title": "Fast and Safe WordPress", "title_accent": "Fast" } }
```

### eyebrow / subheading / title_align (hero, section, faq, grid, cta, testimonials)

> `hero`, `section`, `testimonials`, `cta` and `faq` are v2 components: all five keep `eyebrow` as a CONTENT prop (and `hero` / `section` / `testimonials` keep `subheading`) but have no `theme` — faq's was retired at #1046, the last of them — and no `title_align`; `cta` and `faq` never declared one. Their styling is the `udc` map. `grid` is the only one of the six still on style slots.

`eyebrow` renders a short kicker label as a pill above the title (e.g. `"NEW"`) on all six; on the one v1 component that still has the slots (grid) the pill defaults to uppercase, overridable via the `text-transform`-typed `--<component>-eyebrow-text-transform` style slot (`none` for sentence case, or `lowercase`/`capitalize`). `subheading` renders a supporting line below the title on section, grid, and testimonials only — hero uses `subheading` and cta uses `body` for the same concept, so neither has a `subheading` prop. `title_align` (`start` default, or `center`; **grid only**) centers the eyebrow/title/subheading header block — independent of the component's overall layout. **hero, section and testimonials no longer have it** (and cta never did): all three are v2 components, so header alignment is the header roles' `typography.align` in the band's `udc` map (hero's `centered` and `cover` layouts already centre their text structurally, and an authored `align` overrides that), with `spacing.margin-left`/`margin-right` set to `auto` to centre the block. The eyebrow pill's casing is likewise the `eyebrow` role's `typography.transform` there, not a style slot.

### image_id (hero, section, logos / grid / testimonials items) — responsive images (#107, #584)

Every `image_url` field on hero, section, and the logos, grid and testimonials items has a companion `image_id` — a Media Library attachment ID, not a URL. When `image_id` resolves to a real attachment, the image renders responsively via `wp_get_attachment_image()` (real `srcset`/`sizes`, WordPress-generated). When `image_id` is unset or doesn't resolve, the plain `image_url` renders exactly as before — always set `image_url` too, even when you have an `image_id`, as the fallback.

Get an attachment id (and its canonical local URL) via the `import_media` apply. It returns `{attachment_id, url, action}` — pass its **`attachment_id`** to `image_id` and its **`url`** to `image_url`, never the whole result object. Since #614 a non-numeric `image_id` is rejected at write with `invalid_prop_value`, on the nested `items[]` fields as well as the top-level ones. Give it EITHER a remote `url` OR a server-local `file` (exactly one). Re-importing the same `url` reuses the existing attachment (result `action: "reused"`) instead of creating a duplicate, so retries and re-runs are safe:

```bash
# Like every mutating apply, needs a run token + site-scoped preflight first.
# Pass --apply=import_media so preflight verifies the uploads directory is
# writable (#229) instead of assuming a database-only apply:
# (wp pp operate inspect → wp pp apply preflight --run-id=<uuid> --apply=import_media):
wp pp apply execute import_media --run-id=<uuid> --params='{"url":"https://example.com/logo.png","alt":"Client logo"}'
# => {"attachment_id": 123, "url": "https://yoursite.com/wp-content/uploads/2026/07/logo.png", "action": "import"}
# A second call with the same url returns the same attachment with "action": "reused".

# Or import a brand-kit asset that lives on the operator machine, not a public
# URL (logo, favicon, the 1200x630 OG card). `file` is a server-local ABSOLUTE
# path, read by design (the operator CLI runs with admin rights). The file must
# be a genuine image (bytes, WP filetype, and extension must agree); the source
# is copied, so your kit file is never moved or deleted:
wp pp apply execute import_media --run-id=<uuid> --params='{"file":"/srv/brand/webfiable-og.png","alt":"Social card"}'
# => {"attachment_id": 124, "url": "https://yoursite.com/wp-content/uploads/2026/07/webfiable-og.png", "action": "import"}
```

Then set both fields on the component:

```json
{ "component": "hero", "props": { "layout": "split", "image_url": "https://yoursite.com/wp-content/uploads/2026/07/logo.png", "image_id": 123, "image_alt": "Client logo" } }
```

Always verify against `components/{name}/schema.json` before writing — the source of truth. Without filesystem access to the theme, `wp pp schema {name}` reads the prop, style-slot, recipe AND ROLE declarations over the CLI — the `roles` block, each role's permitted `groups`, its `description` and its `obligations` (and, when the role declares them, its `overlay_defaults`, `within` and `text_content`), plus `udc_groups` and `udc_raw_css`. For a component on the design contract that block IS the styling contract (the rest of `styling` still needs the file); `wp pp schema` lists every registered component and whether it is composable.

---

## How to write a composition (WP CLI)

**Preferred: typed actions** (validates before writing, returns structured result):

```bash
# Every `action execute` needs a run token and a completed PREFLIGHT covering
# its target: wp pp operate inspect → wp pp apply preflight --run-id=<uuid>
# --post_id=42 for page work (or no --post_id for site-scoped actions).

# Update a composition on page ID 42
wp pp action execute update_composition --run-id=<uuid> --params='{"post_id":42,"composition":[
  {"component":"hero","props":{"title":"My Page","layout":"centered"}},
  {"component":"section","props":{"body":"<p>Content goes here.</p>","layout":"text-only"}}
]}'

# Add a single component to an existing page
wp pp action execute add_component --run-id=<uuid> --params='{"post_id":42,"component":"cta","props":{"title":"Go","button_text":"Click","button_url":"/"}}'

# Preview a change without writing (read-only — no run-id needed)
wp pp action preview update_component --params='{"post_id":42,"component_index":0,"props":{"title":"New Title"}}'

# Create a new page (site-scoped — covered by a site preflight, no --post_id)
wp pp action execute create_page --run-id=<uuid> --params='{"title":"About Us"}'
```

**Direct meta write — do not use this to author.** It exists, and you will meet pages written
this way, so it is documented rather than hidden:

```bash
wp post meta update 42 _pp_composition '[{"component":"hero","props":{"title":"Hello"}}]'
```

It stores the bytes and does nothing else. The only check it gets is the meta's
`sanitize_callback`, which asks one question — does this parse as JSON? — so any shape the write
path would refuse lands intact, and anything that does *not* parse is stored as the **empty
string**: the page's content is silently discarded and the page then reads back as a healthy
page with no composition yet. It skips the version counter and the history ring, so there is
nothing to roll back to and a concurrent edit is a lost update rather than a refusal. And it
skips band-id assignment — `pp_update_composition()` is the only place ids are minted, in its own words
*mint-on-write only, here and nowhere else* — so a band written this way has no id, and a `udc`
map scoped to that id styles nothing.

If you are repairing a page that was written this way, re-send the whole composition through
`update_composition`. That one write validates it, gives every band an id, and starts its history.

**Read operations:**

```bash
# Read the current composition on a page
wp post meta get 42 _pp_composition

# Verify the page uses the Composition template
wp post get 42 --field=page_template
# Should return: composition.php
```

---

## How to set the page template (WP CLI)

```bash
# Make page ID 42 use the Composition template
wp post meta update 42 _wp_page_template composition.php
```

---

## Validation rules

Before writing, verify:
1. Every `component` value exists as `components/{name}/{name}.php`
2. Every required prop from `components/{name}/schema.json` has its KEY present. Presence is what the required rule tests, so `null` and `""` satisfy it and leave the prop on its default. Two separate rules catch what that does not: a band with a **content requirement** (`section`) still needs real content in one of its content props, where `null`, `false`, `""` and `[]` do not count; and a `false` on a text prop is a TYPE rejection (see 4), not an absence — a different error code with a different repair
3. The JSON is a valid array (not an object, not null)
4. Prop types match the schema (`string`, `boolean`, `array`, `enum`). A `string` prop means a **quoted JSON string** and nothing else — since #707 a bare `42`, `3.14`, `true` or `false` is rejected with `invalid_prop_value` naming the prop, at both depths, instead of being stored raw behind an `ok:true`. Quote it (`"number": "99%"`, `"image_url": "/wp-content/uploads/logo.png"`), or leave the key out; `null` and `""` still satisfy the type rule and keep the prop's default, though they do not satisfy a band's content requirement — an empty `section` is still rejected for having nothing to render. Watch the two places the mistake is natural: a stats or steps `number` field, which is text so it can hold `99%` or `01`, and a `*_url` prop you might try to clear with `false` — use `""` or omit it. Since #744 the container types read the same way at both depths: a prop or field declared as a list takes a **JSON array**, so `"bullets": ["Fast", "Honest"]` — a scalar there is rejected with `invalid_prop_value` naming the prop, and one level down the item and the field. (The per-item `style` OBJECT this rule also used to cover is retired: `grid.items[].style` went at #1101 and `section.panel_items[].style` at #1023, so no shipped schema declares an object-typed field today. A card's design is its `udc` map, which the design engine validates instead.) That one used to be silent one level down: `"bullets": "Fast, honest"` returned `ok:true`, stored the string as written, and the card rendered with no checklist at all. `null`, `""`, `[]` and `{}` are all still accepted and leave the field on its default (which means they render nothing — they are not a way to express a value you want). Since #738 a declared list must also be a **JSON array specifically**: a keyed object where a list belongs (`"items": {"first": {...}, "second": {...}}`) is rejected with `invalid_prop_value` — `must be a list, but this one is a JSON object (N entries)` — at both depths. Order is the array order; there are no position keys and nothing reads a key as an ordinal. That shape used to return `ok:true`, persist as written, and then 500 the public page, so the refusal is the write path declining to store something the renderer cannot walk. Since #883 the mirror holds too: a declared **object** must be a JSON object specifically, so a POPULATED list where a map belongs is rejected with `invalid_prop_value` — `must be an object, but this one is a JSON list (N entries)` — at both depths. Send real keys, never a bare list. NO SHIPPED SCHEMA DECLARES AN OBJECT FIELD TODAY (the two that did were the per-item style maps, retired at #1101 and #1023), so the rule is live and waiting rather than illustrated with something you can write. `{}` and `[]` are indistinguishable once parsed and count as the empty container for both rules, so an empty value is still accepted; the flip side is that an object whose keys are exactly `0..n-1` parses as a list and is refused where an object is declared.
5. Every prop key is declared in the component's `schema.json` `props` — an undeclared key is rejected by the write path and does not persist. The CODE tells you which kind of mistake it was (#1007): a key the component declares in its `retired_props` block returns `retired_prop` and the message names the v2 surface that replaced it plus the `null` clear; anything else returns `unknown_prop`. Do not invent prop names; if a capability has no matching prop, it is not expressible.
6. Every field INSIDE an `items[]` entry is declared in that prop's `items` field map — an undeclared field is rejected by the write path with `unknown_prop` as well (#643), naming the item and the fields the entry accepts. The two depths answer alike: `imageId` is refused where `image_id` is declared, rather than persisting behind `ok:true` and rendering nothing.

Invalid compositions are rejected by the WRITE PATH — the `update_composition` / `create_page` actions. **There is no save handler guarding the meta, and the DB does not retain the last valid value**: the meta's `sanitize_callback` asks only whether the value parses as JSON, and stores the EMPTY STRING for anything that does not. Measured: a raw `wp post meta update <id> _pp_composition '{not json'` reports Success, the meta reads back empty, and `wp pp check page` then says "No composition found" — the page's content silently discarded. That is why the raw write is not an authoring path (see below).

---

## Example: build a full landing page

**Go through the actions, never through `wp post meta update`.** A raw meta write stores the bytes
and nothing else: `pp_update_composition()` is where band ids are assigned, and its own comment
says so — *mint-on-write only, here and nowhere else*. A composition written straight to post meta
therefore has no band ids, so a `udc` map on it has nothing to scope to and the band renders with
no authored styling. The same write also skips validation, the history ring, and the version
counter that makes a later concurrent edit safe.

Build the whole page in one `create_page` call:

```bash
wp pp action execute create_page --run-id=<uuid> --params='{
  "title": "Launch",
  "status": "draft",
  "composition": [
    {
      "component": "hero",
      "props": {
        "layout": "split",
        "title": "Ship faster",
        "subheading": "Everything you need, nothing you do not.",
        "button_text": "Start free",
        "button_url": "/signup"
      }
    },
    {
      "component": "section",
      "props": { "title": "How it works", "body": "<p>Three steps.</p>" },
      "udc": { "_band": { "background": { "fill": "@color-surface" } } }
    },
    {
      "component": "cta",
      "props": { "title": "Ready?", "button_text": "Get started", "button_url": "/signup" }
    }
  ]
}'
```

Then read the envelope. `ok: true` is not the whole result: an empty `findings` array is the
positive confirmation, and anything in it is the engine telling you a value did not land the way
you wrote it. `create_page` is all-or-nothing — if the composition write fails, the page it created
moments earlier is removed rather than left behind empty.

To RESTYLE one band afterwards, use `update_component` with a `udc` param (see `ai-instructions/style-component.md`) — it touches only that band. To rewrite several bands at once, read the composition back, edit it, and send the whole thing:

```bash
wp post meta get 42 _pp_composition_version   # READ THE VERSION FIRST (this is the 3 below)
wp post meta get 42 _pp_composition           # then the bytes, `udc` included; `inspect` has neither
wp pp action execute update_composition --run-id=<uuid> --params='{
  "post_id": 42,
  "expected_version": 3,
  "composition": [ ... the full array, with your edit applied ... ]
}'
```

Pass the `version` you read back as `expected_version`: if the page moved since you read it, the
write is refused with `composition_conflict` instead of overwriting the newer edit (#1094: the
CLI honours the value you send; its own run baseline is used only when you send none). On a
conflict, re-read, re-apply and retry. See the `expected_version` note in
`ai-instructions/style-component.md`. Re-send each band's
`id` as you read it, so the ids stay stable across the re-apply.

---

## Non-English content

If the composition content is in a non-English language, verify orthography (diacritics,
accent marks, language-specific punctuation) after generating the JSON and before applying it.
See `ai-instructions/build-landing-page.md` → Step 5 for the full verification checklist.

---

## What NOT to store in _pp_composition

- Arbitrary CSS in the `style` key — only schema-declared style slots are accepted there. This is NOT a prohibition on raw CSS as such: a role's `"_css"` map inside `udc` is a sanctioned channel, stored in the composition and covered by the same validation, versioning, undo and rollback as every other value. What does not belong here is CSS with no role to attach to
- Navigation or footer configuration (nav and footer are injected by `pp_base_template` automatically)
- ACF field data (use `pp_field()` in templates or component props for that)

### Site chrome

`nav` and `footer` are registered, renderable components, but they are **not
composable**. `pp_base_template` renders them itself on every page:

```
templates/base.php
  ├── nav      (location: primary)   ← chrome, always rendered
  ├── <main>   … your composition …  ← the only part a page controls
  └── footer   (location: footer)    ← chrome, always rendered
```

Adding either to `_pp_composition` is rejected at write time with the error code
`template_owned_component`. A page that already contains one is reported by
`wp pp check page` (as an error-severity finding plus the matching advisory smell,
without changing its exit code — it is the per-page inspector), and FAILS
`wp pp validate page` and `wp pp validate site`.

To configure the chrome, use these surfaces instead:

| Goal | Surface |
|------|---------|
| Set the site logo | The `pp_logo_id` site option (`update_site_option`). Must be an image attachment id. |
| Build a nav or footer menu | The menu actions: `create_menu` / `set_menu` / `add_menu_item` |
| Attach a menu to the header or footer | `assign_menu_location` with location `primary`, `footer`, or `footer_secondary` (an optional second footer menu column; heading = `pp_footer_secondary_label`) |

Run `wp pp apply preflight` to see chrome readiness warnings (`nav_readiness`):
unassigned locations, empty menus, and a `pp_logo_id` that isn't an image.

The database stores page data (composition + component content + per-instance style overrides).
Files store global visual defaults (tokens, component CSS).

---

## Checking if a page uses the Composition template

```bash
wp post meta get <post_id> _wp_page_template
# Returns: composition.php  ← Composition template is active
# Returns: (empty)          ← Default template
```

---

## Related files

| File                          | Purpose                                          |
|-------------------------------|--------------------------------------------------|
| `composition.php`             | WP template header (root) — do not edit         |
| `templates/composition.php`   | Reads meta, renders components                  |
| `lib/admin.php`               | Meta box, AJAX preview, PHP validation           |
| `assets/js/pp-editor-logic.js`| Pure JS: context parser, validator, insert walker |
| `assets/js/pp-admin-editor.js`| In-admin editor (accordion + JSON toggle + live preview) |
| `AI_CONTEXT.md`               | Full site map + composition model reference      |
