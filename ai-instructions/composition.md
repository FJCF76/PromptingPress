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
  { "component": "hero",    "props": { "title": "Welcome", "layout": "centered" }, "style": { "--hero-bg": "#0d1117", "--hero-text": "#f0f0f0" } },
  { "component": "section", "props": { "body": "<p>Content.</p>" } },
  { "component": "faq",     "props": { "items": [{ "question": "Q?", "answer": "A." }] } },
  { "component": "cta",     "props": { "title": "Go", "button_text": "Click", "button_url": "/" } }
]
```

- `component` — must match a registered component name (a folder in `components/`)
- `props` — must satisfy required props from that component's `schema.json`
- `style` — (optional) per-instance CSS custom property overrides, validated against the component's `schema.json` → `styling.style_slots`. Only declared slots are accepted. Use `style_component` action to set these; see `ai-instructions/style-component.md`
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
| hero    | title                                   | title_accent, eyebrow, subtitle, cta_text, cta_url, cta2_text, cta2_url, cta_variant, cta2_variant, layout, image_url, image_id, image_alt, spacing, width, split_ratio, vertical_align, proof |
| section | body                                    | title, title_accent, eyebrow, subheading, heading_align, layout, theme, image_url, image_id, image_alt, background_image |
| faq     | items[] {question, answer}              | title, title_accent, eyebrow, theme, id                 |
| grid    | items[] {title, text, ...}              | title, title_accent, eyebrow, subheading, heading_align, layout, theme |
| table   | headers[], rows[][]                     | title, caption                                          |
| cta     | button_text, button_url                 | title, title_accent, eyebrow, text, layout, theme, background_image, button_variant |
| stats   | items[] {number, label}                 | title, title_accent, theme, background_image            |
| logos   | items[] {image_url, image_alt, image_id?, label?} | title, theme                                  |
| embed   | content                                 | title, theme                                            |
| testimonials | items[] {quote}                    | title, title_accent, eyebrow, subheading, heading_align, layout, theme |

### cta: standalone button (heading-less)

`cta.title` is optional. Omit `title` (and `text`) to render just the button row with no heading element — the sanctioned way to place a standalone button, e.g. a centered "closing" button after a steps or feature section. `button_text` and `button_url` are still required, and `id`, `layout`, `theme`, and all style slots keep working.

```json
{ "component": "cta", "props": { "button_text": "Get started free →", "button_url": "/signup" } }
```

### section.theme

Controls per-section background color/tone for visual rhythm on marketing pages. (Independent of `section.layout`.)

| Value      | Effect                                                      |
|------------|-------------------------------------------------------------|
| `default`  | Page background (`--color-bg`). No class added. Default.   |
| `dark`     | Surface background (`--color-surface`). Subtle differentiation. |
| `inverted` | Inverted background (`--color-bg-inverted`). Strong contrast. |

Example — alternating section rhythm:
```json
{ "component": "section", "props": { "body": "<p>...</p>", "theme": "dark" } },
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
| `panel_items` | Array of plain-text strings rendered as a bulleted list. |
| `panel_cta_text` + `panel_cta_url` | The panel's CTA button. Both are required for the button to render. |
| `panel_cta_variant` | Button style: `primary` (default) / `secondary` / `outline` / `ghost`. |

The panel falls back to a plain `text-only` section when it has no content (no heading, no body, no list items, and no complete CTA). Style the panel per-instance with the `--section-panel-*` slots (`-bg`, `-border-color`, `-border-width`, `-radius`, `-padding`, `-text`) via `style_component` — set `--section-panel-bg` to a dark color and `--section-panel-text` to a light color for a dark panel. The panel CTA color follows the standard button (site accent); pick `panel_cta_variant` to change its style.

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

### grid items[].bullets

Renders a checklist below the card's `text`, each line prefixed with a check mark — use for scannable feature/benefit lists instead of a dense paragraph. Plain text lines only, no HTML/markdown.

### grid items[].text_role

Optional typography role for a card's `text`: `mono` (code), `meta` (captions), `label`, or `kicker` (eyebrow styling). Adds a `.text-<role>` class; invalid or absent values fall back to default body text. Set via `update_component` like any other item field.

```json
{ "component": "grid", "props": { "title": "Security", "items": [
  { "title": "Perimeter security", "bullets": ["HTTP security headers", "SSL/TLS validity", "Clickjacking protection"] }
]}}
```

### grid items[].style — per-card style overrides

Style ONE card differently from its siblings. A grid item may carry an optional `style` object that accepts only the **card-scoped grid style slots** — the ones consumed on the `.grid__item` and its contents — no arbitrary CSS. It is validated by the same shared engine: unknown slot names and invalid values are rejected exactly like grid-level slots. The card's slots override the grid-level values for that card only (cascade proximity), so the rest of the row is untouched.

Set it in the composition (`create_page` / `update_composition` / `update_component`), NOT via `style_component` — `style_component` targets a whole component instance, not one item. Use it for the standard "one distinct card in a row" patterns: a dark CTA panel beside light checklist cards, or a green-on-dark terminal/code card (pair with `text_role: "mono"`).

The **card-scoped** slots accepted here: `--grid-card-bg`, `--grid-card-border`, `--grid-card-border-width`, `--grid-card-radius`, `--grid-card-shadow`, `--grid-card-bar-color`, `--grid-card-bar-height`, `--grid-featured-texture-color`, `--grid-featured-shadow`, `--grid-card-padding`, `--grid-card-gap`, `--grid-item-title-size`, `--grid-item-title-color`, `--grid-item-text-color`, `--grid-bullet-color`, `--grid-link-color`, `--grid-step-color`. Container/heading slots (`--grid-bg`, `--grid-gap`, `--grid-heading-*`, `--grid-eyebrow-*`, `--grid-subheading-*`, `--grid-padding-*`) are read on the section/list/header, not the card, so a per-card override would render nothing — they are **rejected** here with `invalid_style_slot` naming the card. Put those on the grid-level `style` instead.

```json
{ "component": "grid", "props": { "items": [
  { "title": "Checklist", "bullets": ["Fast", "Honest"] },
  { "title": "Get started", "text": "Empezá hoy",
    "style": { "--grid-card-bg": "#0f172a", "--grid-item-title-color": "#f8fafc", "--grid-item-text-color": "#cbd5e1" } },
  { "text": "$ deploy --now", "text_role": "mono",
    "style": { "--grid-card-bg": "#0b0f0a", "--grid-item-text-color": "#22c55e" } }
]}}
```

### title_accent (hero, section, grid, cta, faq, stats, testimonials)

All seven heading-bearing components accept `title_accent`: an exact, case-sensitive substring of `title` to render in an accent color. It must match `title` literally or it is silently ignored (no accent rendered, `title` still shows in full).

```json
{ "component": "hero", "props": { "title": "Fast and Safe WordPress", "title_accent": "Fast" } }
```

### eyebrow / subheading / heading_align (hero, section, grid, cta, testimonials)

`eyebrow` renders a short kicker label as a pill above the title (e.g. `"NEW"`) on all five. `subheading` renders a supporting line below the title on section, grid, and testimonials only — hero uses `subtitle` and cta uses `text` for the same concept, so neither has a `subheading` prop. `heading_align` (`start` default, or `center`; section, grid, testimonials only) centers the eyebrow/title/subheading header block — independent of the component's overall layout.

### image_id (hero, section, logos items) — responsive images (#107)

Every `image_url` field on hero, section, and logos items has a companion `image_id` — a Media Library attachment ID, not a URL. When `image_id` resolves to a real attachment, the image renders responsively via `wp_get_attachment_image()` (real `srcset`/`sizes`, WordPress-generated). When `image_id` is unset or doesn't resolve, the plain `image_url` renders exactly as before — always set `image_url` too, even when you have an `image_id`, as the fallback.

Get an attachment id (and its canonical local URL) via the `import_media` apply — sideloads an external image URL into the media library. Re-importing a source URL that was already imported reuses the existing attachment (result `action: "reused"`) instead of creating a duplicate, so retries and re-runs are safe:

```bash
# Like every mutating apply, needs a run token + site-scoped preflight first.
# Pass --apply=import_media so preflight verifies the uploads directory is
# writable (#229) instead of assuming a database-only apply:
# (wp pp operate inspect → wp pp apply preflight --run-id=<uuid> --apply=import_media):
wp pp apply execute import_media --run-id=<uuid> --params='{"url":"https://example.com/logo.png","alt":"Client logo"}'
# => {"attachment_id": 123, "url": "https://yoursite.com/wp-content/uploads/2026/07/logo.png", "action": "import"}
# A second call with the same url returns the same attachment with "action": "reused".
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
      "subtitle": "A theme designed for AI-first editing.",
      "cta_text": "Get Started",
      "cta_url": "/docs",
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
| Attach a menu to the header or footer | `assign_menu_location` with location `primary` or `footer` |

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
