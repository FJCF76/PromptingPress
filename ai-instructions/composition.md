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
- `style` — (optional) per-instance CSS custom property overrides, validated against the component's `schema.json` → `styling.style_slots`. Only declared slots are accepted. Set these via a composition write (`create_page` / `update_composition`), the `style_component` action, or by passing `style` to `add_component` (which writes it onto the new item in one call, validated by the same shared engine — no separate follow-up `style_component` needed); see `ai-instructions/style-component.md`
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
| hero    | title                                   | title_accent, eyebrow, subheading, button_text, button_url, button2_text, button2_url, layout, image_url, image_id, image_alt, split_ratio, vertical_align, proof |
| section | one of: body / body_items / panel content | body, title, title_accent, eyebrow, subheading, layout, image_url, image_id, image_alt, body_marker, body_items, body_items_align, panel_heading, panel_body, panel_items, panel_items_marker, panel_cta_text, panel_cta_url — and NOT `theme`, `title_align`, `background_image` or `panel_cta_variant`, which the v2 rebuild retired (see section.styling below) |
| faq     | items[] {question, answer}              | title, title_accent, eyebrow, id                        |
| grid    | items[] (fields: number, title, text, text_role, bullets[], image_url, image_alt, image_id, link_url, link_text, style — none individually required) | title, title_accent, eyebrow, subheading, title_align, layout, card_emphasis, theme, columns, image_treatment |
| table   | headers[], rows[][]                     | title, caption, id                                      |
| cta     | button_text, button_url                 | title, title_accent, eyebrow, body, button2_text, button2_url, layout, id — and NOT `theme`, `background_image`, `button_variant` or `button2_variant`, which the v2 rebuild retired (see cta.styling below) |
| stats   | items[] {number, label}                 | title, title_accent, theme, background_image            |
| logos   | items[] {image_url, image_alt, image_id?, label?} | title, theme                                  |
| embed   | content                                 | title, theme                                            |
| testimonials | items[] {quote (req); optional author, role, company, image_url, image_alt, image_id} | title, title_accent, eyebrow, subheading, layout — **and NO `theme` / `title_align`**: testimonials is on the v2 Universal Design Contract, so a tone or an alignment is set through the band's `udc` map (`_band` background, role `typography.align`), not through a prop. See `ai-instructions/style-component.md`. |

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
`button2_text` (and `button2_url`) alongside the primary button props; `button2_variant`
defaults to `outline`, so the pair reads as one filled action and one outlined
action without setting it. This is the `cta` equivalent of the hero's `button2_text` / `button2_url`,
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
plain default. **The marker's COLOUR is not authorable — do not offer to change it.** The
glyph is drawn with `content` on a `::before` and ruling A3 defers pseudo-elements, so no
role can reach it, and there is no token for it either (#1028). It renders
`var(--color-accent)`. The only knob that moves it is `update_design_token` on
`--color-accent` itself, which recolours the accent everywhere on the site — say that
plainly rather than implying a marker-only setting exists. On a dark panel, reach for the
panel's own `background.fill` and ink instead. The same marker capability is
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
keep their disc). The marker's colour is not authorable, as above (#1028). Use
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
separator's glyph is a fixed middot, and like the list markers above its colour is not
directly authorable — same pseudo-element reason (#1028). **But it resolves differently
from them on purpose, and that difference is the knob:** the markers land on
`var(--color-accent)`, which is what they always defaulted to, while the separator lands on
**`currentColor`**, so it follows whatever `typography.color` you put on `inline-items`.
That is what the v1 muted default achieved through band-class remaps, which a v2 band has
no class for — so set the row's colour on a dark band and the separator follows it
automatically, with nothing else to set. Residual on a default light band: the middot is
`#101828` — the row is a SIBLING of `.section__content`, so it inherits `--color-text` and
not the `body` role's colour, which means the mark is the SAME ink as the item text beside
it, where v1 painted it one step lighter. To get the old
grey, grey the row: `"inline-items": {"typography": {"color": "@color-muted"}}` moves the
mark and the item text together. **A band that set the separator to a colour DIFFERENT
from its body copy** — an accent middot over muted text — does not reproduce, and there is
no setting that brings it back; `currentColor` is the whole mechanism. Say so rather than
proposing a substitute.

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

### grid.card_emphasis: "featured" | "uniform"

Controls whether the **first card** gets the emphasized "featured" treatment. Default `featured` (unchanged historical behavior) gives card 1 an accent top bar, a tinted fill, a larger title, extra body top-padding, and — on the `muted` theme — a slight lift, drawing the eye to a lead item.

Set `card_emphasis: "uniform"` to render **every card identically**. Use it for a symmetric/peer card row where the cards are equal and the featured emphasis would mislead or misalign them: specification/comparison cards whose checklists must line up across the row (the featured card's extra top-padding otherwise pushes its content down relative to its neighbors), or an equal-weight feature/plan row. Keep `featured` when one card is genuinely the lead. Cards-layout concept; ignored on `steps`. This is a grid prop (set with `create_page` / `update_component`), not a style slot, and it drops the *whole* featured treatment — more complete than the slot-level `uniform-cards` recipe, which cannot reach the first-card top-padding or the muted-band lift.

```json
{
  "component": "grid",
  "props": {
    "title": "Especificaciones",
    "card_emphasis": "uniform",
    "items": [
      { "title": "Método de análisis", "bullets": ["Estático", "Dinámico"] },
      { "title": "Datos y privacidad", "bullets": ["Cifrado", "Sin reventa"] },
      { "title": "Compatibilidad", "bullets": ["Web", "API"] }
    ]
  }
}
```

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

### grid.image_treatment: "banner" | "icon"

By default (`banner`) each card's `image_url` renders as a full-width 16:9 cover banner above the card body. Set `image_treatment: "icon"` to render the image at a small fixed icon size instead — un-cropped (`object-fit: contain`), sized by the `--grid-item-icon-size` slot (default 48px), above the title. Use it for the common **icon + title + text** feature card, where a ~45px logo or glyph would otherwise be blown up into a cropped banner.

Unset (omit the key) keeps `banner` — byte-identical. The value set is closed: anything other than `banner` or `icon` (e.g. `card`, `Icon`, `thumbnail`) is **rejected** with `invalid_prop_value`, never coerced. `image_treatment` is a `cards` concept and is ignored on the `steps` layout (which renders no item images). Structural prop — set with `create_page` / `update_component`, not `style_component`. To change the icon box size, set the `--grid-item-icon-size` style slot via `style_component` (grid-wide) or a per-card `items[].style`. The icon **follows the card's `--grid-item-text-align`** slot (like the text and the `Read more` link do): set `--grid-item-text-align: center` for a fully centered icon+title+text card, `right` to right-align the icon; unset/`left` keeps it left.

```json
{
  "component": "grid",
  "props": {
    "title": "Integrations",
    "image_treatment": "icon",
    "items": [
      { "title": "Slack", "text": "Post updates to any channel.", "image_url": "..." },
      { "title": "GitHub", "text": "Sync issues and PRs.", "image_url": "..." },
      { "title": "Linear", "text": "Two-way task mirroring.", "image_url": "..." }
    ]
  }
}
```

### grid items[].bullets

Renders a checklist below the card's `text`, each line prefixed with a check mark — use for scannable feature/benefit lists instead of a dense paragraph. Plain text lines only, no HTML/markdown.

### grid items[].text_role

Optional typography role for a card's `text`: `mono` (code), `meta` (captions), `label`, or `kicker` (eyebrow styling). Adds a `.text-<role>` class; an absent value falls back to default body text. Set via `update_component` like any other item field.

The four roles are the whole accepted set: a value outside it is **rejected at write** with `invalid_prop_value` naming the item and the field, not accepted and coerced away at render (#600). The locator names the entry's stored `items` key: `item 0` for a list-shaped `items` (the normal case), `item key "0"` when `items` was stored as a JSON object, so a numeric object key is never read as a position (#652). Pick one of the four, or leave the field unset — key absent, `null`, or `""` all keep default body text. There is no free-form role, and a near miss (`"Mono"`, `"mono "`, `"terminal"`) is a rejection, not a fallback.

`meta` and `kicker` also carry a preset text **color** (muted / accent), but the grid's own responsive text-color rules can take precedence when the slot is unset (for example, at the desktop breakpoint card text renders in the standard secondary color). To control card text color reliably, set `--grid-item-text-color` (grid-level or per-card `style`): it always wins over a role preset at **all breakpoints**. The role's other typography (size, weight, letter-spacing, transform) always applies regardless.

```json
{ "component": "grid", "props": { "title": "Security", "items": [
  { "title": "Perimeter security", "bullets": ["HTTP security headers", "SSL/TLS validity", "Clickjacking protection"] }
]}}
```

### grid items[].style — per-card style overrides

Style ONE card differently from its siblings. A grid item may carry an optional `style` object that accepts only the **card-scoped grid style slots** — the ones consumed on the `.grid__item` and its contents — no arbitrary CSS. It is validated by the same shared engine: unknown slot names and invalid values are rejected exactly like grid-level slots. The card's slots override the grid-level values for that card only (cascade proximity), so the rest of the row is untouched.

Set it in the composition (`create_page` / `update_composition` / `update_component`), NOT via `style_component` — `style_component` targets a whole component instance, not one item. Use it for the standard "one distinct card in a row" patterns: a dark CTA panel beside light checklist cards, or a green-on-dark terminal/code card (pair with `text_role: "mono"`).

The **card-scoped** slots accepted here: `--grid-item-bg`, `--grid-item-border-color`, `--grid-item-border-width`, `--grid-item-radius`, `--grid-item-shadow`, `--grid-item-bar-color`, `--grid-item-bar-height`, `--grid-featured-texture-color`, `--grid-featured-shadow`, `--grid-item-padding`, `--grid-item-gap`, `--grid-item-text-align`, `--grid-item-icon-size`, `--grid-item-title-size`, `--grid-item-title-color`, `--grid-item-text-color`, `--grid-item-bullet-color`, `--grid-item-link-color`, `--grid-item-link-hover-color`, `--grid-step-bg`, `--grid-step-text-color`. (`--grid-item-text-align` sets the card's alignment — a `text-align` keyword, e.g. `center` — and aligns the title/text/bullets AND the `Read more` link/button together, so a centered card is fully centered.) Container/heading slots (`--grid-bg`, `--grid-gap`, `--grid-heading-*`, `--grid-eyebrow-*`, `--grid-subheading-*`, `--grid-padding-*`) are read on the section/list/header, not the card, so a per-card override would render nothing — they are **rejected** here with `invalid_style_slot` naming the card. Put those on the grid-level `style` instead. Since #579 the **renderer** enforces the same narrowing, so a container-scoped slot that reached storage through a non-validating path (a raw database write, or a `restore_composition` of an old snapshot — which by rule never blocks) is dropped from the card's inline style instead of being emitted onto the `<li>`. (A section panel row's `style` used to work the same way; #1023 retired it, so a `panel_items` entry declares `label` and `value` and nothing else.)

```json
{ "component": "grid", "props": { "items": [
  { "title": "Checklist", "bullets": ["Fast", "Honest"] },
  { "title": "Get started", "text": "Empezá hoy",
    "style": { "--grid-item-bg": "#0f172a", "--grid-item-title-color": "#f8fafc", "--grid-item-text-color": "#cbd5e1" } },
  { "text": "$ deploy --now", "text_role": "mono",
    "style": { "--grid-item-bg": "#0b0f0a", "--grid-item-text-color": "#22c55e" } }
]}}
```

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

Always verify against `components/{name}/schema.json` before writing — the source of truth. Without filesystem access to the theme, `wp pp schema {name}` reads the prop, style-slot and recipe declarations over the CLI (the rest of `styling` still needs the file); `wp pp schema` lists every registered component and whether it is composable.

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

**Direct meta write** (legacy, bypasses validation):

```bash
wp post meta update 42 _pp_composition '[{"component":"hero","props":{"title":"Hello"}}]'
```

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
4. Prop types match the schema (`string`, `boolean`, `array`, `enum`). A `string` prop means a **quoted JSON string** and nothing else — since #707 a bare `42`, `3.14`, `true` or `false` is rejected with `invalid_prop_value` naming the prop, at both depths, instead of being stored raw behind an `ok:true`. Quote it (`"number": "99%"`, `"image_url": "/wp-content/uploads/logo.png"`), or leave the key out; `null` and `""` still satisfy the type rule and keep the prop's default, though they do not satisfy a band's content requirement — an empty `section` is still rejected for having nothing to render. Watch the two places the mistake is natural: a stats or steps `number` field, which is text so it can hold `99%` or `01`, and a `*_url` prop you might try to clear with `false` — use `""` or omit it. Since #744 the container types read the same way at both depths: a prop or field declared as a list takes a **JSON array** and a per-item `style` takes a **JSON object**, so `"bullets": ["Fast", "Honest"]` and `"style": {"--grid-item-bg": "#111111"}` — a scalar in either is rejected with `invalid_prop_value` naming the prop, and one level down the item and the field. That one used to be silent one level down: `"bullets": "Fast, honest"` returned `ok:true`, stored the string as written, and the card rendered with no checklist at all. `null`, `""`, `[]` and `{}` are all still accepted and leave the field on its default (which means they render nothing — they are not a way to express a value you want). Since #738 a declared list must also be a **JSON array specifically**: a keyed object where a list belongs (`"items": {"first": {...}, "second": {...}}`) is rejected with `invalid_prop_value` — `must be a list, but this one is a JSON object (N entries)` — at both depths. Order is the array order; there are no position keys and nothing reads a key as an ordinal. That shape used to return `ok:true`, persist as written, and then 500 the public page, so the refusal is the write path declining to store something the renderer cannot walk. Since #883 the mirror holds too: a declared **object** must be a JSON object specifically, so a POPULATED list where a map belongs (`"style": ["#fff"]`) is rejected with `invalid_prop_value` — `must be an object, but this one is a JSON list (N entries)` — at both depths. Send slot names as keys (`"style": {"--grid-item-bg": "#111111"}`). `{}` and `[]` are indistinguishable once parsed and count as the empty container for both rules, so an empty value is still accepted; the flip side is that an object whose keys are exactly `0..n-1` parses as a list and is refused where an object is declared.
5. Every prop key is declared in the component's `schema.json` `props` — an undeclared key is rejected on save and the write does not persist. The CODE tells you which kind of mistake it was (#1007): a key the component declares in its `retired_props` block returns `retired_prop` and the message names the v2 surface that replaced it plus the `null` clear; anything else returns `unknown_prop`. Do not invent prop names; if a capability has no matching prop, it is not expressible.
6. Every field INSIDE an `items[]` entry is declared in that prop's `items` field map — an undeclared field is rejected on save with `unknown_prop` as well (#643), naming the item and the fields the entry accepts. The two depths answer alike: `imageId` is refused where `image_id` is declared, rather than persisting behind `ok:true` and rendering nothing.

Invalid compositions are rejected on save by the PHP layer — the DB retains the last valid value.

---

## Example: build a full landing page

```bash
wp post meta update 42 _wp_page_template composition.php

wp post meta update 42 _pp_composition '[
  {
    "component": "hero",
    "props": {
      "title": "Build AI-Ready Sites",
      "subheading": "A theme designed for AI-first editing.",
      "button_text": "Get Started",
      "button_url": "/docs",
      "layout": "centered"
    }
  },
  {
    "component": "section",
    "props": {
      "title": "How It Works",
      "body": "<p>PromptingPress exposes every component as a typed, schema-validated unit. AI reads the schema and edits with confidence.</p>"
    }
  },
  {
    "component": "cta",
    "props": {
      "title": "Ready to build?",
      "button_text": "View on GitHub",
      "button_url": "https://github.com/FJCF76/PromptingPress",
      "layout": "full-width"
    }
  }
]'
```

---

## Non-English content

If the composition content is in a non-English language, verify orthography (diacritics,
accent marks, language-specific punctuation) after generating the JSON and before applying it.
See `ai-instructions/build-landing-page.md` → Step 5 for the full verification checklist.

---

## What NOT to store in _pp_composition

- Arbitrary CSS (only schema-declared style slots are allowed in the `style` key)
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
