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
  { "component": "hero",    "props": { "title": "Welcome", "layout": "centered" }, "style": { "--hero-bg": "#0d1117", "--hero-heading-color": "#f0f0f0" } },
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

---

## Valid component names

See `AI_CONTEXT.md` → Component index for the current list. As of last update:

> `nav` and `footer` are **not** in this table. They are site chrome, rendered on
> every page by `pp_base_template`. Putting either in a composition renders the
> header or footer twice, and the write is rejected with `template_owned_component`.
> See "Site chrome" below for the surfaces that do configure them.

| Name    | Required props                          | Optional props (selection)                              |
|---------|-----------------------------------------|---------------------------------------------------------|
| hero    | title                                   | title_accent, eyebrow, subheading, button_text, button_url, button2_text, button2_url, button_variant, button2_variant, layout, image_url, image_id, image_alt, spacing, width, split_ratio, vertical_align, proof |
| section | one of: body / body_items / panel content | body, title, title_accent, eyebrow, subheading, title_align, layout, theme, image_url, image_id, image_alt, background_image, body_marker, body_items |
| faq     | items[] {question, answer}              | title, title_accent, eyebrow, theme, id                 |
| grid    | items[] {title, text, ...}              | title, title_accent, eyebrow, subheading, title_align, layout, card_emphasis, theme, columns, image_treatment |
| table   | headers[], rows[][]                     | title, caption                                          |
| cta     | button_text, button_url                 | title, title_accent, eyebrow, body, button2_text, button2_url, button2_variant, layout, theme, background_image, button_variant |
| stats   | items[] {number, label}                 | title, title_accent, theme, background_image            |
| logos   | items[] {image_url, image_alt, image_id?, label?} | title, theme                                  |
| embed   | content                                 | title, theme                                            |
| testimonials | items[] {quote}                    | title, title_accent, eyebrow, subheading, title_align, layout, theme |

## Text content model: which props accept HTML

Every text prop has ONE of three markup contracts, and each schema `description`
states which. Read the description; do not generalize a link from one prop to the
next. The rule (#439):

| Contract | Props | What you may write |
|----------|-------|--------------------|
| **Rich HTML** (`wp_kses_post`) | `section.body`, `faq.items[].answer` | Block markup: paragraphs, lists, headings, links, `strong`/`em`. These are the main prose surfaces. |
| **Inline HTML** (`a, strong, em, br`) | `cta.body`, `grid.items[].text`, `testimonials.items[].quote` | Supporting copy with a link or light emphasis, e.g. `Read our <a href="/terms">terms</a>.` No block elements — `<p>`, `<ul>`, `<h2>` are stripped. |
| **Plain text** (escaped) | Titles, eyebrows, subheadings, `button_text`, `button2_text`, `stats.items[].label`, `stats.items[].number`, `grid.items[].title`, `testimonials.items[].author`, `faq.items[].question`, and all URLs | Text only. Any `<...>` renders as visible characters, not markup. |

Both HTML contracts are allowlist-sanitized: `script`, `style`, `iframe`, event
handlers (`onclick`), and `javascript:` URLs are always stripped, whoever authored
the content. A link in a supporting-text prop is normal marketing copy — write it
as real HTML (`<a href="...">`), not as escaped source, and never put a link in a
plain-text prop (it will show as literal `<a href=...>` text on the page).

### cta: standalone button (heading-less)

`cta.title` is optional. Omit `title` (and `body`) to render just the button row with no heading element — the sanctioned way to place a standalone button, e.g. a centered "closing" button after a steps or feature section. `button_text` and `button_url` are still required, and `id`, `layout`, `theme`, and all style slots keep working.

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
wrapper element. The two buttons take independent per-instance RESTING colors
(`--cta-button-bg` / `-color` / `-shadow` for the primary, `--cta-button2-*` for the
second); neither reaches the other. Hover is isolated the same way (`--cta-button-hover-bg`
for the primary, `--cta-button2-hover-bg` for the second), and a filled button keeps the
premium hover gradient until its own hover-fill slot is set. At mobile
widths the pair stacks one button per row.

### section.theme

Controls per-section background color/tone for visual rhythm on marketing pages. (Independent of `section.layout`.)

| Value      | Effect                                                      |
|------------|-------------------------------------------------------------|
| `default`  | Page background (`--color-bg`). No class added. Default.   |
| `muted`    | Light tinted surface band (`--color-surface`) with framing borders. Subtle differentiation. |
| `inverted` | Inverted **dark** background (`--color-bg-inverted`). Strong contrast — use this for a genuinely dark band. |

> `muted` is a LIGHT band, not a dark one. For a dark band use `inverted`.

Example — alternating section rhythm:
```json
{ "component": "section", "props": { "body": "<p>...</p>", "theme": "muted" } },
{ "component": "section", "props": { "body": "<p>...</p>", "theme": "inverted" } }
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
| `panel_items` | Array of panel entries. Each entry is EITHER a plain-text string (a bullet) OR a paired-row object `{ "label": "...", "value": "..." }` rendered as a two-part row (label left, value right at >=768px; label stacked above value below that). Mix freely in one array. A paired-row entry may carry an optional `"style"` map (see below). |
| `panel_items_marker` | List marker for the string entries of `panel_items`: `disc` (default) / `check` / `dash` / `arrow`. Not a distinct list type — just the glyph. Paired rows are never bullets, so they never show a marker. |
| `panel_cta_text` + `panel_cta_url` | The panel's CTA button. Both are required for the button to render. |
| `panel_cta_variant` | Button style: `primary` (default) / `secondary` / `outline` / `ghost`. The transparent/light variants read against the panel's own light surface on every band, including `inverted` and `background_image` ones. |

The panel falls back to a plain `text-only` section when it has no content (no heading, no body, no list items, and no complete CTA). Style the panel per-instance with the `--section-panel-*` slots (`-bg`, `-border-color`, `-border-width`, `-radius`, `-padding`, `-text`, `-font`) via `style_component` — set `--section-panel-bg` to a dark color and `--section-panel-text` to a light color for a dark panel. Set `--section-panel-font` to `var(--font-mono)` for a monospace panel (spec sheets, config or stat readouts). The panel CTA has its own fill slots (#536): on the default `primary` variant, `--section-panel-cta-bg` replaces the theme's premium gradient with a flat brand fill (a plain background colour cannot — the gradient is a background-image painted over it), `--section-panel-cta-color` sets the label ink, and `--section-panel-cta-shadow: none` flattens the bevel. An unset border follows the fill. They reach the `primary` variant only; pick `panel_cta_variant` for a transparent button instead.

**Paired label/value rows.** When a panel summarises data — a pricing summary, spec sheet, plan comparison, stat readout, or config/contact list — give `panel_items` entries the object form `{ "label": "...", "value": "..." }` instead of strings. Each renders as a two-part row: label on the left, value on the right, both plain text. String and paired-row entries mix in one array (a string is still a bullet). **Below 768px the two columns become two lines by default: the label stacks above its value, both on one shared left edge, with the label set smaller and tracked and the value carrying the weight so the pair still reads as label-then-fact without position to carry it, and a wider gap between one pair and the next.** That is a fixed responsive default with no slot or prop behind it — do not try to keep two columns on mobile; there is not room for them, and a long value would wrap to four lines in a ~170px box. It also means a long `value` is safe: at narrow widths it gets the panel's full measure, so write values in real words rather than trimming them to fit an imagined column. To emphasise or de-emphasise one row against its siblings, add a per-row `"style"` map that sets `--section-panel-text` (the panel's text-colour slot, the only per-row slot) — no new colour grammar, no status vocabulary. A monospace data panel (a spec sheet, config readout, or stat summary) is just a composition of these generic parts (`--section-panel-font: var(--font-mono)` + paired rows + a dark `--section-panel-bg` + a per-row accent), not a named mode. Set per-row `style` through the composition (`update_component` / `update_composition` / `create_page`), not `style_component`.

To turn `panel_items` into a check-list (the common "benefits beside a panel" pattern), set `panel_items_marker: "check"` and recolor the marker with the `--section-panel-marker-color` slot (defaults to the site accent; set a light value on a dark panel). `dash` and `arrow` are the other marker values; `disc` is the plain default. The same marker capability is available on `grid` card bullets (always a check) and on `section` body lists (`body_marker`, below) — one shared treatment, so a check-list is reachable from any list-rendering surface, not just the grid.

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
  "style": { "--section-panel-bg": "#0f172a", "--section-panel-text": "#f8fafc" }
}
```

A monospace spec panel with paired label/value rows, one row emphasised via a per-row `style`:

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
      { "label": "Uptime", "value": "99.9%", "style": { "--section-panel-text": "#22d3ee" } },
      "All checks passing"
    ],
    "panel_cta_text": "View status",
    "panel_cta_url": "/status"
  },
  "style": {
    "--section-panel-bg": "#0f172a",
    "--section-panel-text": "#f8fafc",
    "--section-panel-font": "var(--font-mono)"
  }
}
```

#### section body list markers: `body_marker`

`body_marker` (`disc` default / `check` / `dash` / `arrow`) sets the marker on **top-level** `<ul>` lists authored in a section's `body` — the same shared marker treatment the panel and grid use. `disc` leaves body lists exactly as before; `check`/`dash`/`arrow` apply to lists written as a direct child of the body (nested lists keep their disc). Recolor the marker with the `--section-body-marker-color` slot (defaults to the site accent). Use it when a prose section needs a check-list without moving the content into a grid or panel.

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

`body_items` renders a short "trust strip" — a centered row of brief plain-text items with a CSS-generated middot (`·`) separator between each (the separator never dangles at the start of a wrapped line; at narrow widths the row wraps and its lines pack from the left). Use it for the slim post-hero meta row (e.g. "No credit card · Cancel anytime · 30-day guarantee"), not for multi-line content (use `body` or `panel_items` for that). Each entry is a plain-text string (escaped, no HTML): at most **8 items**, each at most **80 characters** — a write that exceeds either bound, or passes a non-string entry, is rejected with `invalid_prop_value` (nothing persists). The row renders only when non-empty; when both `body` and `body_items` are set, the row renders after the body.

The items inherit the section body type, so the `--section-body-size` / `--section-body-weight` slots (a slim 15px/600 strip needs no extra typography slots). Colour the separator with the `--section-separator-color` style slot (defaults to the muted text colour; it follows the light on-inverted / on-background-image text colour like sibling text on dark bands). The glyph is a fixed middot.

Per-line alignment when the strip wraps is the `--section-inline-items-align` style slot (`start` | `center`, default `start`). `start` (the default) packs each wrapped line from the left and clips the leading separator, so no middot ever dangles at the start of a line. `center` centres each wrapped line, moving the separator to a trailing middot after every item except the last. There is a documented trade with `center`: because a centred line ends mid-box, its trailing middot at a wrap point stays **visible** as a subtle line-end dot (an edge-clip cannot remove a mid-box dot; there is no pure-CSS centred-and-artifact-free option). Choose `center` when the brand's art direction wants a centred strip at every width and accepts the subtle line-end dots; keep the default `start` for artifact-free left-packing. A single-line strip reads centred in both modes, so this only matters where the row wraps (typically mobile).

`body` is optional (#488): a strip whose whole content is `body_items` — no heading, no paragraph — is a first-class band, so author it with `body_items` alone and no `body` key. On a body-less strip the row sits vertically centred within the band's own padding (its body-relative top margin drops away automatically), so a symmetric strip needs no manual padding compensation. A section must still carry SOME renderable content: at least one of `body`, `body_items`, or panel content (`panel_heading` / `panel_body` / `panel_items` / a panel CTA). A fully-empty section — no body, no items, no panel — is rejected at write time with `invalid_composition`. (A `title` alone does not satisfy this: a heading needs a `body`, `body_items`, or panel beneath it.)

```json
{
  "component": "section",
  "props": {
    "body": "<p>Everything you need to launch.</p>",
    "body_items": ["No credit card", "Cancel anytime", "30-day guarantee"]
  },
  "style": { "--section-body-size": "15px", "--section-body-weight": "600", "--section-separator-color": "#84cc16" }
}
```

A body-less trust strip (no `body`, centred by the band padding):

```json
{
  "component": "section",
  "props": {
    "theme": "inverted",
    "body_items": ["SOC 2 Type II", "99.99% uptime", "GDPR compliant"]
  },
  "style": { "--section-body-size": "15px", "--section-body-weight": "600" }
}
```

### grid.layout: "steps"

Renders numbered process cards. Use for How-It-Works or sequential flows. Cards get a filled circular number badge and a subtle connector line between badges at desktop (1024px+).

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

Optional typography role for a card's `text`: `mono` (code), `meta` (captions), `label`, or `kicker` (eyebrow styling). Adds a `.text-<role>` class; invalid or absent values fall back to default body text. Set via `update_component` like any other item field.

`meta` and `kicker` also carry a preset text **color** (muted / accent), but the grid's own responsive text-color rules can take precedence when the slot is unset (for example, at the desktop breakpoint card text renders in the standard secondary color). To control card text color reliably, set `--grid-item-text-color` (grid-level or per-card `style`): it always wins over a role preset at **all breakpoints**. The role's other typography (size, weight, letter-spacing, transform) always applies regardless.

```json
{ "component": "grid", "props": { "title": "Security", "items": [
  { "title": "Perimeter security", "bullets": ["HTTP security headers", "SSL/TLS validity", "Clickjacking protection"] }
]}}
```

### grid items[].style — per-card style overrides

Style ONE card differently from its siblings. A grid item may carry an optional `style` object that accepts only the **card-scoped grid style slots** — the ones consumed on the `.grid__item` and its contents — no arbitrary CSS. It is validated by the same shared engine: unknown slot names and invalid values are rejected exactly like grid-level slots. The card's slots override the grid-level values for that card only (cascade proximity), so the rest of the row is untouched.

Set it in the composition (`create_page` / `update_composition` / `update_component`), NOT via `style_component` — `style_component` targets a whole component instance, not one item. Use it for the standard "one distinct card in a row" patterns: a dark CTA panel beside light checklist cards, or a green-on-dark terminal/code card (pair with `text_role: "mono"`).

The **card-scoped** slots accepted here: `--grid-item-bg`, `--grid-item-border-color`, `--grid-item-border-width`, `--grid-item-radius`, `--grid-item-shadow`, `--grid-item-bar-color`, `--grid-item-bar-height`, `--grid-featured-texture-color`, `--grid-featured-shadow`, `--grid-item-padding`, `--grid-item-gap`, `--grid-item-text-align`, `--grid-item-icon-size`, `--grid-item-title-size`, `--grid-item-title-color`, `--grid-item-text-color`, `--grid-item-bullet-color`, `--grid-item-link-color`, `--grid-item-link-hover-color`, `--grid-step-bg`, `--grid-step-text-color`. (`--grid-item-text-align` sets the card's alignment — a `text-align` keyword, e.g. `center` — and aligns the title/text/bullets AND the `Read more` link/button together, so a centered card is fully centered.) Container/heading slots (`--grid-bg`, `--grid-gap`, `--grid-heading-*`, `--grid-eyebrow-*`, `--grid-subheading-*`, `--grid-padding-*`) are read on the section/list/header, not the card, so a per-card override would render nothing — they are **rejected** here with `invalid_style_slot` naming the card. Put those on the grid-level `style` instead. Since #579 the **renderer** enforces the same narrowing, so a container-scoped slot that reached storage through a non-validating path (a raw database write, or a `restore_composition` of an old snapshot — which by rule never blocks) is dropped from the card's inline style instead of being emitted onto the `<li>`. The same holds for a section panel row's `style` (`props.panel_items[].style`).

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

`eyebrow` renders a short kicker label as a pill above the title (e.g. `"NEW"`) on all six; the pill defaults to uppercase, overridable per component via the `text-transform`-typed `--<component>-eyebrow-text-transform` style slot (`none` for sentence case, or `lowercase`/`capitalize`). `subheading` renders a supporting line below the title on section, grid, and testimonials only — hero uses `subheading` and cta uses `body` for the same concept, so neither has a `subheading` prop. `title_align` (`start` default, or `center`; section, grid, testimonials only) centers the eyebrow/title/subheading header block — independent of the component's overall layout.

### image_id (hero, section, logos / grid / testimonials items) — responsive images (#107, #584)

Every `image_url` field on hero, section, and the logos, grid and testimonials items has a companion `image_id` — a Media Library attachment ID, not a URL. When `image_id` resolves to a real attachment, the image renders responsively via `wp_get_attachment_image()` (real `srcset`/`sizes`, WordPress-generated). When `image_id` is unset or doesn't resolve, the plain `image_url` renders exactly as before — always set `image_url` too, even when you have an `image_id`, as the fallback.

Get an attachment id (and its canonical local URL) via the `import_media` apply. Give it EITHER a remote `url` OR a server-local `file` (exactly one). Re-importing the same `url` reuses the existing attachment (result `action: "reused"`) instead of creating a duplicate, so retries and re-runs are safe:

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

Always verify against `components/{name}/schema.json` before writing — the source of truth.

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
2. Every required prop from `components/{name}/schema.json` is present and non-empty — `null`, `false`, and `""` are treated as absent
3. The JSON is a valid array (not an object, not null)
4. Prop types match the schema (`string`, `boolean`, `array`, `enum`)
5. Every prop key is declared in the component's `schema.json` `props` — an undeclared key is rejected on save with `unknown_prop` (the write does not persist). Do not invent prop names; if a capability has no matching prop, it is not expressible.

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
`template_owned_component`, and `wp pp check page` / `wp pp validate page` /
`wp pp validate site` all fail on a page that already contains one.

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
