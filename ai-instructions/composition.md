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
  { "component": "hero",    "props": { "title": "Welcome", "variant": "centered" } },
  { "component": "section", "props": { "body": "<p>Content.</p>", "layout": "text-only" } },
  { "component": "faq",     "props": { "items": [{ "question": "Q?", "answer": "A." }] } },
  { "component": "cta",     "props": { "title": "Go", "button_text": "Click", "button_url": "/" } }
]
```

- `component` — must match a registered component name (a folder in `components/`)
- `props` — must satisfy required props from that component's `schema.json`
- Order in the array = render order on the page
- Any registered component can appear any number of times in any order

---

## Valid component names

See `AI_CONTEXT.md` → Component index for the current list. As of last update:

| Name    | Required props                          | Optional props (selection)                              |
|---------|-----------------------------------------|---------------------------------------------------------|
| hero    | title                                   | subtitle, cta_text, cta_url, cta2_text, cta2_url, variant |
| section | body                                    | title, layout, variant, background_image                |
| faq     | items[] {question, answer}              | title                                                   |
| grid    | items[] {title, text, ...}              | title, variant, theme                                   |
| table   | headers[], rows[][]                     | title, caption                                          |
| cta     | title, button_text, button_url          | text, variant, theme, background_image                  |
| nav     | (no required props)                     | logo_text, logo_url, logo_alt                           |
| footer  | (no required props)                     | location                                                |
| stats   | items[] {number, label}                 | title, variant, background_image                        |
| logos   | items[] {image_url, image_alt}          | title, variant                                          |
| embed   | content                                 | title, variant                                          |

### section.variant

Controls per-section background for visual rhythm on marketing pages.

| Value      | Effect                                                      |
|------------|-------------------------------------------------------------|
| `default`  | Page background (`--color-bg`). No class added. Default.   |
| `dark`     | Surface background (`--color-surface`). Subtle differentiation. |
| `inverted` | Inverted background (`--color-bg-inverted`). Strong contrast. |

Example — alternating section rhythm:
```json
{ "component": "section", "props": { "body": "<p>...</p>", "variant": "dark" } },
{ "component": "section", "props": { "body": "<p>...</p>", "variant": "inverted" } }
```

### grid.variant: "steps"

Renders numbered process cards. Use for How-It-Works or sequential flows.

- Set `variant: "steps"` on the grid
- Include a `number` field on each item (`"1"`, `"01"`, `"Step 1"`, etc.)
- Images are suppressed in steps variant; use title + text only

```json
{
  "component": "grid",
  "props": {
    "title": "How it works",
    "variant": "steps",
    "items": [
      { "number": "1", "title": "Sign up", "text": "Create your account." },
      { "number": "2", "title": "Configure", "text": "Set your preferences." },
      { "number": "3", "title": "Launch", "text": "Go live." }
    ]
  }
}
```

Always verify against `components/{name}/schema.json` before writing — the source of truth.

---

## How to write a composition (WP CLI)

```bash
# Set a composition on page ID 42
wp post meta update 42 _pp_composition '[
  {"component":"hero","props":{"title":"My Page","variant":"centered"}},
  {"component":"section","props":{"body":"<p>Content goes here.</p>","layout":"text-only"}}
]'

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
      "variant": "centered"
    }
  },
  {
    "component": "section",
    "props": {
      "title": "How It Works",
      "body": "<p>PromptingPress exposes every component as a typed, schema-validated unit. AI reads the schema and edits with confidence.</p>",
      "layout": "text-only"
    }
  },
  {
    "component": "cta",
    "props": {
      "title": "Ready to build?",
      "button_text": "View on GitHub",
      "button_url": "https://github.com/FJCF76/PromptingPress",
      "variant": "full-width"
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

- Layout, spacing, or visual decisions (those belong in CSS / design tokens)
- Navigation or footer configuration (nav and footer are injected by `pp_base_template` automatically)
- ACF field data (use `pp_field()` in templates or component props for that)

The database stores page data (composition + component content).
Files store everything visual.

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
| `assets/js/pp-admin-editor.js`| In-admin JSON editor with live preview           |
| `AI_CONTEXT.md`               | Full site map + composition model reference      |
