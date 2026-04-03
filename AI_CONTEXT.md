# PromptingPress — AI Context

## Quick orientation (read this first)

This is a WordPress site using the PromptingPress theme. WordPress handles the backend
(admin, database, media, plugins). This theme handles the frontend rendering only.

**To add a page:** Create or edit a file in `/templates/`. Call `pp_get_component()` for
each section. Register the template in WP Admin (Pages → Edit → Page Attributes → Template).

**To edit a component:** Open `/components/{name}/{name}.php`. Props are documented in
`schema.json` in the same folder. CSS is in `/assets/css/components.css`.

**To add a page:** Follow the steps in `ai-instructions/add-page.md`.

**To build a landing page:** See `ai-instructions/build-landing-page.md` for a complete template with copy guidance.

**To add a component:** Follow the steps in `ai-instructions/add-component.md`. The
auto-loader picks up any component at `/components/{name}/{name}.php` — no registration needed.

**To retheme:** Read `ai-instructions/retheme.md`. Edit the 17 CSS tokens in `assets/css/base.css`.

**To provision a new WordPress site:** Read `ai-instructions/bootstrap.md` for the full state contract and WP-CLI verification commands.

**Never:**
- Add hooks or filters to template or component files (only in `functions.php`)
- Call WordPress functions directly in templates or components (use `pp_*` wrappers from `lib/wp.php`)
- Edit `lib/components.php` (it is the stable loader contract)
- Add raw hex values to `components.css` (use CSS variables only)

---

## File responsibility

| File/Folder              | Purpose                         | Safe to edit?                    |
|--------------------------|---------------------------------|----------------------------------|
| /templates/              | Page layouts                    | Yes                              |
| /components/             | Reusable sections               | Yes                              |
| /assets/css/base.css     | Design tokens (17 CSS vars)     | Yes — tokens only                |
| /assets/css/components.css | Component styles              | Yes                              |
| /assets/css/utilities.css | Spacing / text utilities       | Yes                              |
| /assets/js/pp-editor-logic.js | Pure JS logic (testable)   | Yes — run npm test after         |
| /assets/js/main.js       | Nav toggle, active link         | Yes                              |
| /tests/js/               | Vitest unit tests               | Yes — add tests for logic changes |
| /lib/wp.php              | WP function wrappers            | Only to add pp_* functions       |
| /lib/setup.php           | Theme activation bootstrap      | Only to add idempotent setup     |
| /lib/components.php      | Component loader                | No                               |
| /lib/helpers.php         | Utility functions               | Yes — only to add                |
| functions.php            | WP registration                 | Only to add                      |
| style.css                | Theme header (WP requirement)   | No                               |
| CLAUDE.md                | Claude Code instructions        | Only to update invariants        |
| AI_CONTEXT.md            | This file — AI site map         | Keep current when structure changes |

---

## Component index

| Component | File                           | Description                                      | Key props                                          |
|-----------|--------------------------------|--------------------------------------------------|----------------------------------------------------|
| hero      | components/hero/hero.php       | Full-width headline + optional CTA and image     | title (req), subtitle, cta_text, cta_url, variant, image_url, image_alt, id |
| section   | components/section/section.php | Text + optional image. 3 layout variants         | body (req), title, image_url, image_alt, layout, variant, background_image, id |
| faq       | components/faq/faq.php         | Native details/summary accordion. Zero JS.       | items[] (req) {question, answer}, title            |
| grid      | components/grid/grid.php       | Responsive card grid for real content objects    | items[] (req) {title, text, image_url, link_url, link_text}, title, variant, id |
| table     | components/table/table.php     | Data/comparison table, horizontal scroll mobile  | headers[] (req), rows[][] (req), title, caption    |
| cta       | components/cta/cta.php         | Call-to-action block. Layout + color + bg-image  | title (req), button_text (req), button_url (req), text, variant, theme, background_image, id |
| nav       | components/nav/nav.php         | Site header, logo, hamburger mobile nav          | location, logo_text                                |
| footer    | components/footer/footer.php   | Site footer with nav menu and copyright          | location                                           |
| stats     | components/stats/stats.php     | Horizontal row of large-number metrics + labels  | items[] (req) {number, label}, title, variant, id  |
| logos     | components/logos/logos.php     | Flex-wrap image grid — logo strips or icon tiles | items[] (req) {image_url, image_alt, label?}, title, id |
| embed     | components/embed/embed.php     | WP shortcode / plugin content wrapper            | content (req), title, variant, id                  |

### Component capabilities reference

**Variants (color themes):** Most section-level components support `variant` with values `default`, `dark`, `inverted`. CTA uses `theme` instead (because `variant` controls layout: `full-width` or `inline`).

**Background images:** hero (via `cover` variant + `image_url`), section (`background_image` prop), and cta (`background_image` prop) support CSS background-image with a dark overlay and light text. The overlay is a separate div (`.hero__overlay`, `.section__overlay`, `.cta__overlay`).

**Anchor IDs:** All 7 section-level components (hero, section, stats, grid, logos, cta, embed) accept an `id` prop that renders as the HTML `id` attribute on the root `<section>` element. Use for anchor navigation.

**Hero variants:** `left`, `centered`, `split` (inline image), `cover` (fullscreen background-image with overlay).

**Grid variants:** `default` (card grid), `steps` (numbered process steps).

---

## Calling a component

```php
pp_get_component('hero', [
    'title'    => pp_field('hero_title') ?: 'Welcome',
    'subtitle' => pp_field('hero_subtitle'),
    'cta_text' => pp_field('hero_cta_text') ?: 'Get Started',
    'cta_url'  => pp_field('hero_cta_url')  ?: '#',
    'variant'  => 'centered',
]);
```

---

## WP abstraction layer (lib/wp.php)

All functions are prefixed `pp_`. Templates and components use only these wrappers.

| Function                      | Returns                                         |
|-------------------------------|-------------------------------------------------|
| `pp_site_title()`             | Site name (get_bloginfo)                        |
| `pp_site_description()`       | Site tagline                                    |
| `pp_site_url($path)`          | Home URL with optional path                     |
| `pp_page_title()`             | Current post/page title                         |
| `pp_page_content()`           | Current post content with WP filters applied    |
| `pp_field($name, $id)`        | ACF field value, or null if ACF not installed   |
| `pp_nav_menu($location)`      | Renders WP nav menu (no output if unassigned)   |
| `pp_posts($args)`             | Returns WP_Query object                         |
| `pp_the_loop($query, $cb)`    | Iterates query, calls $cb() per post            |
| `pp_is_front_page()`          | bool — true on front page                       |
| `pp_body_classes()`           | Space-separated body class string               |
| `pp_excerpt($length)`         | Trimmed excerpt (default 55 words)              |
| `pp_permalink()`              | Current post permalink                          |
| `pp_thumbnail_url($size)`     | Post thumbnail URL (default 'large')            |
| `pp_default_homepage_composition()` | Default homepage component array (hero, section, cta) — single source of truth for activation seeding and blank-page fallback |

---

## Page templates

| Template file              | Root loader         | WP Admin template name | Composition-aware? |
|----------------------------|---------------------|------------------------|--------------------|
| templates/front-page.php   | front-page.php      | (set as front page)    | ✅ Yes             |
| templates/composition.php  | composition.php     | Composition            | ✅ Yes             |
| templates/page.php         | page.php            | Default Template       | No                 |
| templates/single.php       | single.php          | (automatic for posts)  | No                 |
| templates/archive.php      | archive.php         | (automatic for archives) | No               |

Both `front-page.php` and `composition.php` read `_pp_composition` post meta and render
components via `pp_composition()`. No page using these templates has hardcoded component structure.

The homepage has no special editing paradigm — it uses the same JSON composition system
as any other page. Its initial composition is seeded in `_pp_composition` (post ID 4).

---

## WordPress fields (ACF)

`pp_field()` is available for use in templates and components as an ACF wrapper.
No core templates currently use it — the front page content is stored in `_pp_composition`,
not ACF fields. `pp_field()` returns null when ACF is not installed.

---

## Design tokens (assets/css/base.css)

17 CSS custom properties control the entire visual system. To retheme, edit these only.

```
Colors:     --color-bg, --color-surface, --color-text, --color-muted,
            --color-border, --color-accent, --color-accent-hover, --color-bg-inverted
Spacing:    --space-xs, --space-sm, --space-md, --space-lg, --space-xl, --space-2xl
Typography: --font-body, --font-heading
Shape:      --radius, --max-width, --transition
```

See `ai-instructions/retheme.md` for the full retheme workflow.

---

## Composition model

Pages using the **Composition** template store their layout in `_pp_composition` post meta.

**Format:** JSON array of component objects.

```json
[
  { "component": "hero", "props": { "id": "top", "title": "Welcome", "variant": "cover", "image_url": "/path/to/bg.jpg" } },
  { "component": "section", "props": { "id": "about", "body": "<p>Content here.</p>", "layout": "text-only" } },
  { "component": "stats", "props": { "variant": "dark", "items": [{ "number": "50+", "label": "Clients" }] } },
  { "component": "cta", "props": { "title": "Go", "button_text": "Click", "button_url": "/", "theme": "inverted" } }
]
```

**Rules:**
- `component` must match a registered component name (a folder in `components/`)
- `props` must satisfy required props from the component's `schema.json`
- Invalid compositions are rejected on save — the DB retains the last valid value
- AI can write `_pp_composition` directly (via WP CLI or REST) — same format

**To read the composition in PHP:** use `pp_composition()` from `lib/wp.php`.
It returns `[]` when meta is absent or invalid JSON.

**To write a composition as AI:**
```bash
wp post meta update <post_id> _pp_composition '[{"component":"hero","props":{"title":"Hello"}}]'
```

**Admin editor:** Pages with the Composition template open a full-screen workspace in WP Admin with a CodeMirror JSON editor, live preview, and component reference sidebar. The toolbar adapts to page state: draft pages show **Save Draft** and **Publish**; published pages show only **Update**. Ctrl+S is contextual — saves draft on draft pages, triggers Update on published pages.

**AJAX preview:** `wp_ajax_pp_preview_composition` (cookie auth, WP nonce)
- POST params: `post_id`, `composition` (JSON string), `nonce`
- Returns: `{ "success": true, "data": { "html": "<full-page-html>" } }` or error

**File map:**
| File                           | Purpose                                          |
|--------------------------------|--------------------------------------------------|
| `composition.php`              | WP template header (root) — do not edit          |
| `templates/composition.php`    | Composition template logic                       |
| `lib/admin.php`                | Meta box, AJAX preview, validation, component registry |
| `assets/js/pp-admin-editor.js` | Editor JS (CodeMirror, autocomplete, preview)    |
| `assets/css/pp-admin-editor.css` | Editor layout and styles                       |
