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

**To retheme:** Read `ai-instructions/retheme.md`. Edit the 18 CSS tokens in `assets/css/base.css`.

**To provision a new WordPress site:** Read `ai-instructions/bootstrap.md` for the full state contract and WP-CLI verification commands.

**Composition vs templates:** Use composition for all content-driven pages. Use template files only for structural or dynamic pages (archives, single posts, search results, 404).

**Document authority:**

| Source | Scope | Precedence |
|---|---|---|
| `AI_CONTEXT.md` | Orientation, file map, component index | Start here |
| `AI_RULES.md` | Hard invariants, coding rules | Overrides everything else |
| `ai-instructions/*.md` | Task-specific workflows | Executable procedures |
| `components/{name}/schema.json` | Prop contracts (types, required) | Supersedes prose in any other file |

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
| /assets/css/base.css     | Design tokens (18 CSS vars)     | Yes — tokens only                |
| /assets/css/components.css | Component styles              | Yes                              |
| /assets/css/utilities.css | Spacing / text utilities       | Yes                              |
| /assets/js/pp-editor-logic.js | Pure JS logic (testable)   | Yes — run npm test after         |
| /assets/js/main.js       | Nav toggle, active link         | Yes                              |
| /tests/js/               | Vitest unit tests               | Yes — add tests for logic changes |
| /tests/e2e/              | Playwright E2E tests            | Yes — requires Docker (wp-env)   |
| .wp-env.json             | wp-env Docker config            | Yes — test environment only      |
| /lib/wp.php              | WP function wrappers (read + write) | Only to add pp_* functions   |
| /lib/actions.php         | Typed action model (12 actions) | Add actions following the contract |
| /lib/cli.php             | WP-CLI `wp pp action` commands  | Yes                              |
| /lib/setup.php           | Theme activation bootstrap      | Only to add idempotent setup     |
| /lib/components.php      | Component loader                | No                               |
| /lib/helpers.php         | Utility functions               | Yes — only to add                |
| functions.php            | WP registration                 | Only to add                      |
| style.css                | Theme header (WP requirement)   | No                               |
| AI_RULES.md              | AI coding rules and invariants  | Only to update invariants        |
| /lib/ai-context.php      | AI site context layer             | Extend for new context sources     |
| /lib/ai-provider.php     | LLM provider proxy (streaming)    | Extend for new providers           |
| /lib/ai-settings.php     | AI settings page (admin only)     | Yes                                |
| /lib/ai-chat.php         | AI chat page + AJAX handlers      | Yes                                |
| /ai-stream.php           | SSE streaming endpoint            | Thin transport only                |
| /assets/js/pp-ai-chat.js | AI chat UI (streaming, proposals) | Yes                                |
| /assets/css/pp-ai-chat.css | AI chat styles                  | Yes                                |
| AI_CONTEXT.md            | This file — AI site map         | Keep current when structure changes |

---

## Component index

| Component | File                           | Description                                      | Key props                                          |
|-----------|--------------------------------|--------------------------------------------------|----------------------------------------------------|
| hero      | components/hero/hero.php       | Full-width headline + optional CTA and image     | title (req), subtitle, cta_text, cta_url, cta2_text, cta2_url, variant, image_url, image_alt, id |
| section   | components/section/section.php | Text + optional image. 3 layout variants         | body (req), title, image_url, image_alt, layout, variant, background_image, id |
| faq       | components/faq/faq.php         | Native details/summary accordion. Zero JS.       | items[] (req) {question, answer}, title            |
| grid      | components/grid/grid.php       | Responsive card grid for real content objects    | items[] (req) {title, text, image_url, link_url, link_text}, title, variant, theme, id |
| table     | components/table/table.php     | Data/comparison table, horizontal scroll mobile  | headers[] (req), rows[][] (req), title, caption    |
| cta       | components/cta/cta.php         | Call-to-action block. Layout + color + bg-image  | title (req), button_text (req), button_url (req), text, variant, theme, background_image, id |
| nav       | components/nav/nav.php         | Site header, logo, hamburger mobile nav          | location, logo_text, logo_url, logo_alt            |
| footer    | components/footer/footer.php   | Site footer with nav menu and copyright          | location                                           |
| stats     | components/stats/stats.php     | Horizontal row of large-number metrics + labels  | items[] (req) {number, label}, title, variant, background_image, id |
| logos     | components/logos/logos.php     | Flex-wrap image grid — logo strips or icon tiles | items[] (req) {image_url, image_alt, label?}, title, variant, id |
| embed     | components/embed/embed.php     | WP shortcode / plugin content wrapper            | content (req), title, variant, id                  |

### Component capabilities reference

**Variants (color themes):** Most section-level components support `variant` with values `default`, `dark`, `inverted`. CTA and grid are exceptions — see below.

**Two components have dual-axis control.** CTA and grid both use `variant` for layout and `theme` for color, because they need independent control of both. CTA: `variant` = layout (`full-width`, `inline`), `theme` = color (`default`, `dark`, `inverted`). Grid: `variant` = layout (`default`, `steps`), `theme` = color (`default`, `dark`, `inverted`). Every other component uses `variant` for color because it has only one layout. If a future component needs both layout and color control, follow the same pattern.

**Background images:** hero (via `cover` variant + `image_url`), section (`background_image` prop), cta (`background_image` prop), and stats (`background_image` prop) support CSS background-image with a dark overlay and light text. All four use the same implementation pattern:
- `background-image` inline style on the root `<section>` element
- A child `div.{component}__overlay` (e.g. `.hero__overlay`) with `background: var(--overlay-bg)`
- Container gets `position: relative; z-index: 1` to sit above the overlay
- Text colors switch to `var(--color-bg)` for contrast

If adding background-image support to another component, follow this exact pattern.

**Anchor IDs:** All 7 section-level components (hero, section, stats, grid, logos, cta, embed) accept an `id` prop that renders as the HTML `id` attribute on the root `<section>` element. Use for anchor navigation.

**Hero:** Variants `left`, `centered`, `split` (inline image), `cover` (fullscreen background-image with overlay). Supports dual CTA buttons (`cta_text` + `cta2_text`); secondary renders as outline/ghost style.

**Nav:** Supports image logos via `logo_url` + `logo_alt`. Falls back to `logo_text` (text) when `logo_url` is empty.

**Grid:** Variants `default` (card grid), `steps` (numbered process steps with arrow connectors at desktop). `theme` controls background color independently of layout variant.

**CSS invariant:** Component CSS in `components.css` must use only CSS variables from `base.css` — never raw hex values. Color decisions belong to the design tokens, not to individual components.

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
| `pp_get_composition($post_id)` | Composition array for any page by ID (returns [] if absent) |
| `pp_composition_pages()`       | All composition pages: [{id, title, status, url}, ...] (static cached) |
| `pp_design_tokens()`           | CSS custom properties from base.css :root {} with type metadata. Returns `['--token' => ['value' => string, 'type' => string\|null]]`. Static cached. |
| `pp_invalidate_design_tokens_cache()` | Resets the pp_design_tokens() static cache. Call after writing to base.css. |
| `pp_site_option($key)`         | Whitelisted option value (blogname, blogdescription) or WP_Error |
| `pp_update_composition($post_id, $composition)` | Writes composition array to post meta (handles JSON serialization). Returns true\|WP_Error |
| `pp_update_page_title($post_id, $title)` | Updates page title. Returns true\|WP_Error |
| `pp_create_page($title, $status)` | Creates page with Composition template. Returns post ID\|WP_Error |
| `pp_publish_page($post_id)`    | Sets post_status to 'publish'. Returns true\|WP_Error |
| `pp_update_site_option($key, $value)` | Updates whitelisted option. Returns true\|WP_Error |

### Apply layer (file-based mutations — lib/apply.php)

| Function | Description |
|----------|-------------|
| `pp_register_apply($name, $def)` | Registers a file-based mutation (apply). |
| `pp_get_registered_applies()` | Returns all registered applies. |
| `pp_get_apply($name)` | Returns a single apply definition, or null. |
| `pp_validate_apply($name, $params)` | Validates params (structural + semantic). Returns true\|WP_Error. |
| `pp_preview_apply($name, $params)` | Validates and returns before/after diff without writing. |
| `pp_execute_apply($name, $params)` | Validates, backs up, applies mutation, verifies contract. |
| `pp_restore_points($basename)` | Returns available restore points for a file. |
| `pp_restore($target_path, $point)` | Restores a file from a restore point (null = latest). |

**Registered applies:**

| Name | Domain | Target | Params |
|------|--------|--------|--------|
| `update_design_token` | design | assets/css/base.css | token (string, required), value (string, required) |

**CLI:** `wp pp apply list\|preview\|execute\|restore` (requires manage_options capability).

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

18 CSS custom properties control the entire visual system. To retheme, edit these only.

```
Colors:     --color-bg, --color-surface, --color-text, --color-muted,
            --color-border, --color-accent, --color-accent-hover, --color-bg-inverted
Spacing:    --space-xs, --space-sm, --space-md, --space-lg, --space-xl, --space-2xl
Typography: --font-body, --font-heading
Shape:      --radius, --max-width, --transition, --overlay-bg
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

**To write a composition as AI (preferred):**
```bash
wp pp action execute update_composition --params='{"post_id":4,"composition":[{"component":"hero","props":{"title":"Hello"}}]}'
```

**Direct meta write (legacy, bypasses validation):**
```bash
wp post meta update <post_id> _pp_composition '[{"component":"hero","props":{"title":"Hello"}}]'
```

**Admin editor:** Pages with the Composition template open a full-screen workspace in WP Admin. The default view is an accordion, where each component renders as a collapsible card with typed form fields (string inputs, textareas, enum dropdowns, repeatable array sub-forms). A toolbar toggle switches to a CodeMirror JSON view with autocomplete and live preview. Both views sync to the same canonical JSON. The toolbar adapts to page state: draft pages show **Save Draft** and **Publish**; published pages show only **Update**. Ctrl+S is contextual — saves draft on draft pages, triggers Update on published pages.

**AJAX preview:** `wp_ajax_pp_preview_composition` (cookie auth, WP nonce)
- POST params: `post_id`, `composition` (JSON string), `nonce`
- Returns: `{ "success": true, "data": { "html": "<full-page-html>" } }` or error

**File map:**
| File                           | Purpose                                          |
|--------------------------------|--------------------------------------------------|
| `composition.php`              | WP template header (root) — do not edit          |
| `templates/composition.php`    | Composition template logic                       |
| `lib/admin.php`                | Meta box, AJAX preview, validation, component registry |
| `assets/js/pp-admin-editor.js` | Editor JS (accordion, CodeMirror, autocomplete, preview) |
| `assets/css/pp-admin-editor.css` | Editor layout and styles                       |

---

## Action model (lib/actions.php)

All mutations go through typed actions. AJAX handlers, WP-CLI, and future AI callers all use the same layer.

**Every action returns the same canonical result shape:**
```php
['ok' => bool, 'action' => string, 'scope' => string, 'target' => array, 'changes' => array, 'error' => string|null]
```

**Execute always validates first.** Callers never need to pre-validate.

**Registry functions:**
- `pp_get_registered_actions()` — all 12 actions
- `pp_get_action($name)` — single action definition or null
- `pp_validate_action($name, $params)` — structural + semantic validation, returns true|WP_Error
- `pp_preview_action($name, $params)` — validates, computes diff, never writes
- `pp_execute_action($name, $params)` — validates then executes, returns canonical result

### Actions

| Action | Scope | Params | Semantics |
|---|---|---|---|
| `create_page` | site | title (req), composition, status | Create. Defaults to draft with empty composition |
| `update_site_option` | site | key (req), value (req) | Replace. Whitelisted: blogname, blogdescription |
| `update_page_title` | page | post_id (req), title (req) | Replace |
| `update_composition` | page | post_id (req), composition (req) | Replace entire array |
| `publish_page` | page | post_id (req) | Sets status to publish. Idempotent |
| `add_component` | page | post_id (req), component (req), props (req), position | Append, or insert at position (0-based) |
| `remove_component` | page | post_id (req), component_index (req) | Remove by 0-based index. Rejects OOB |
| `reorder_components` | page | post_id (req), order (req, int[]) | Permutation of 0..N-1. No duplicates, no gaps |
| `update_component` | section | post_id (req), component_index (req), props (req) | **Patch** (not replace). Shallow merge. Unspecified props unchanged. `null` removes a prop. Validates merged result |
| `trash_page` | page | post_id (req) | Moves page to trash (reversible). Rejects already-trashed pages |
| `restore_page` | page | post_id (req) | Restores page from trash. Only works on trashed pages |
| `unpublish_page` | page | post_id (req) | Sets status back to draft. Only works on published pages |

### WP-CLI

```bash
wp pp action list                                    # all actions with scope and params
wp pp action preview <name> --params='{"key":"val"}'  # validate + diff, never writes
wp pp action execute <name> --params='{"key":"val"}'  # validate + execute
```

### AJAX handler delegation

The 3 mutation AJAX handlers (`pp_save_composition`, `pp_save_title`, `pp_publish_page`) are thin HTTP adapters. They handle nonce verification, capability checks, and JSON parsing, then delegate to `pp_execute_action()`. The publish handler uses a short-circuit pattern: save composition first, publish only if save succeeds. Zero JS changes.

---

## AI Chat (lib/ai-chat.php, lib/ai-context.php, lib/ai-provider.php, lib/ai-settings.php)

An in-admin AI chat that can read site state, answer questions, and propose/execute mutations through the action/apply contracts.

### Admin pages

| Page | Menu location | Capability | Purpose |
|------|---------------|------------|---------|
| AI Chat | PromptingPress → AI Chat | `edit_posts` | Chat interface for conversational site editing |
| AI Settings | PromptingPress → AI Settings | `manage_options` | BYOK provider config (provider dropdown, API key, model) |

### Provider configuration

Stored in `wp_options`:
- `pp_ai_provider` — provider key (default: `github_models`). Dropdown: GitHub Models or Custom / Manual
- `pp_ai_base_url` — full endpoint URL. Hidden for GitHub Models (derived server-side via `pp_ai_sanitize_base_url()`). Editable for Custom
- `pp_ai_api_key` — API key (server-side only, never sent to browser)
- `pp_ai_model` — model ID (default: `openai/gpt-5-chat`). Curated dropdown for GitHub Models (GPT-5 Chat, GPT-5, GPT-4.1 + custom escape hatch). Free-text for Custom

`pp_ai_get_providers()` is the single source of truth for provider labels, canonical base URLs, and curated model lists. The sanitize callback for `pp_ai_base_url` reads `$_POST['pp_ai_provider']` and overrides the submitted value with the canonical URL for known providers. JS controls field visibility; PHP controls values.

Works with any OpenAI-compatible provider. GitHub Models is the default with a curated experience.

### Streaming architecture

The chat uses POST-based SSE streaming (nonce in request body, never in URL):

1. Chat JS sends `POST /wp-content/themes/promptingpress/ai-stream.php` with `{messages, nonce, page_id}`
2. `ai-stream.php` loads WordPress, verifies nonce + capability, assembles system prompt via `pp_ai_system_prompt()`
3. `pp_ai_stream_completion()` streams from the LLM via raw curl + `CURLOPT_WRITEFUNCTION`
4. Response chunks forwarded as SSE events: `data: {"content":"..."}\n\n`
5. Final event includes parsed proposal if the response contains one: `data: {"done":true,"proposal":{...}}\n\n`

**AJAX fallback:** If SSE fails, chat JS retries via `wp_ajax_pp_ai_chat` which returns the complete response as JSON.

### Nonce separation

| Nonce | Scope | Used by |
|-------|-------|---------|
| `pp_ai_stream` | Read/stream | SSE endpoint, AJAX chat fallback |
| `pp_ai_execute` | Mutate | Action/apply execution from chat |

### System prompt contents

Assembled by `pp_ai_system_prompt()`:
- Site identity (name, tagline, URL)
- Page inventory (titles, statuses, IDs)
- Component catalog (names + prop schemas, condensed)
- Action signatures (names, scopes, param types)
- Apply signatures (names, domains, param types)
- Design token inventory (18 tokens with current values and types)
- Response format instructions (conversational vs structured proposal)

### Proposal flow

When the AI proposes a mutation, it outputs structured JSON:

```json
{"proposal": true, "steps": [{"type": "action", "name": "add_component", "params": {"post_id": 4, "component": "faq", "props": {"items": []}}, "description": "Add FAQ section"}]}
```

The chat UI renders this as a card with Apply/Cancel buttons. On Apply, each step executes via `wp_ajax_pp_ai_execute`, which delegates to `pp_execute_action()` or `pp_execute_apply()`. Applied changes are injected back into the conversation context so the AI knows about its own mutations.

### Context functions

| Function | Purpose |
|----------|---------|
| `pp_ai_system_prompt()` | Assembles complete system prompt |
| `pp_ai_page_context($post_id)` | Returns composition + metadata for a specific page |
| `pp_ai_media_inventory($limit)` | Returns recent media attachments (id, filename, url, alt, mime, dimensions) |
| `pp_ai_site_context()` | Bundles all site context into a single array |
| `pp_ai_format_messages($system, $conversation, $page_id)` | Formats for OpenAI chat completions API |
| `pp_ai_condense_schema($schema)` | Condenses component schema to compact string |
| `pp_ai_format_params($params)` | Formats action/apply params to compact string |
| `pp_ai_stream_completion($messages, $on_chunk)` | Streams chat completion from configured provider |
| `pp_ai_completion($messages)` | Non-streaming completion (AJAX fallback) |
| `pp_ai_parse_proposal($response)` | Parses response for action proposals |
| `pp_ai_validate_proposal($proposal)` | Validates proposal against registered capabilities |
| `pp_ai_is_configured()` | Returns true if API key is saved |
| `pp_ai_get_config()` | Returns provider configuration array (provider, base_url, api_key, model with defaults) |
| `pp_ai_get_providers()` | Returns provider registry: keys, labels, canonical base URLs, curated model lists |
| `pp_ai_sanitize_base_url($value)` | Sanitize callback: overrides base URL for known providers, passes through for Custom |
| `pp_ai_maybe_migrate_provider()` | One-time migration from legacy provider strings to provider keys |
| `pp_ai_parse_error_response($code, $body)` | Parses HTTP error into user-facing message with "Check AI Settings" phrase |

### Conversation persistence

Messages persist in localStorage keyed per site (`pp_ai_chat_{siteUrl}`). Survives page reload. "New Chat" button clears state. Internal messages (apply confirmations) are stored in the conversation for AI context but hidden in the display.
