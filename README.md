# PromptingPress

**A structured composition layer for WordPress, designed for AI agents.**

Most WordPress themes were built for humans clicking around visual editors. Their hidden abstractions, mixed content/layout logic, and unpredictable markup make AI-generated edits fragile and hard to maintain.

PromptingPress separates design-system logic from page content and composition, so AI agents can work with predictable files and structured page data — while humans still manage content in WordPress.

This is not a generic AI website builder. It is not another visual page builder. It is a structured way to make WordPress workable for AI-led page creation and maintenance.

---

## Who this is for

- **WordPress implementers and solo consultants** experimenting with AI-assisted site delivery
- **Developers** who want AI-editable WordPress output without turning the site into fragile builder soup
- **Anyone** who cares about maintainability, clear structure, and human-editable content inside WordPress — and wants AI to work within that structure, not around it

## Why PromptingPress exists

WordPress themes and page builders were designed for humans who accumulate knowledge over time — where to click, which settings override which, what the markup actually looks like after three layers of builder output.

AI agents don't accumulate. They re-infer everything from the code on every session. They need:

- **Predictable file structure** — one file per component, one schema per component, no magic
- **Explicit data contracts** — page content as structured JSON, not serialized blocks or visual-builder state
- **A single write path** — every mutation (CLI, AJAX, AI chat) goes through the same typed action layer with validation, preview, and rollback
- **Machine-readable orientation** — one file (`AI_CONTEXT.md`) maps the entire site, so any AI session starts oriented in seconds

PromptingPress builds all of this into the theme layer. WordPress stays WordPress — the database, the admin, media uploads, plugins, users. PromptingPress handles how pages are composed, rendered, and edited.

## How it works

### Pages are compositions

Every page is a JSON array of components stored in post meta:

```json
[
  { "component": "hero", "props": { "title": "Welcome", "variant": "centered" } },
  { "component": "section", "props": { "body": "<p>Content here.</p>" } },
  { "component": "grid", "props": { "items": [
    { "title": "Fast", "text": "Lightning speed." },
    { "title": "Safe", "text": "Enterprise security." }
  ] } }
]
```

No blocks. No shortcodes. No visual-builder serialization. Just components, props, and optional per-instance style overrides — all validated against `schema.json` contracts.

### AI can read and write compositions

Through WP-CLI:

```bash
# Inspect what's editable on a page
wp pp operate inspect-composition 74

# Patch a single field by semantic selector
wp pp operate patch 74 --target=hero.subtitle --value="New Headline" --preview

# Replace an entire composition
wp pp action execute update_composition \
  --params='{"post_id":74,"composition":[{"component":"hero","props":{"title":"Hello"}}]}'
```

Through the in-admin AI chat:

> "Add a hero section to the About page with a dark background and dual CTAs."

The AI reads your site state, proposes structured mutations with preview cards, and executes them through the typed action layer when you approve.

### The editing surface is bounded

Every mutation goes through a typed action/apply layer that validates inputs, supports dry-run preview, and returns structured results. There are 14 typed actions (create pages, update compositions, add/remove/reorder components, update titles, publish, style) and a separate apply layer for design token and file mutations with automatic backup and rollback.

An AI agent can't accidentally write to the wrong file or produce invalid state — the system rejects it before anything changes.

## What's in the box

### Component system

11 registered components, each isolated in its own directory with a `schema.json` that documents props, types, and required fields:

| Component | What it does | Key props |
|-----------|-------------|-----------|
| hero | Full-width headline + optional CTA and image | `title` |
| section | Text + optional image, 3 layout variants | `body` |
| grid | Responsive card grid for content objects | `items[]` |
| faq | Native `details/summary` accordion, zero JS | `items[]` |
| cta | Call-to-action block with layout + color + bg-image | `title`, `button_text`, `button_url` |
| stats | Large-number metrics with labels | `items[]` |
| logos | Flex-wrap image grid for logo strips | `items[]` |
| table | Data/comparison table, horizontal scroll on mobile | `headers[]`, `rows[][]` |
| embed | WP shortcode / plugin content wrapper | `content` |
| nav | Site header with hamburger mobile nav | -- |
| footer | Site footer with nav menu and copyright | -- |

The auto-loader picks up any new component at `/components/{name}/{name}.php` — no registration code needed.

### Design token system

33 CSS custom properties in `assets/css/base.css` control the entire visual system — colors, typography, spacing, borders, measures. Product defaults live in the file; site-specific overrides are stored in the database and survive theme updates.

58 per-instance style slots let AI agents make one hero dark and spacious while another is tight and accent-bordered — all through composition data, no CSS file edits.

### WP abstraction layer

`lib/wp.php` is the only file that calls WordPress functions directly. Templates and components use `pp_*` wrappers. This means:

- AI can edit templates without knowing WordPress internals
- Templates are testable without bootstrapping WP
- The WordPress dependency surface is explicit and contained

### Composition editor

A two-pane workspace in the WordPress admin:

- **Accordion view** (default) — each component as a collapsible card with typed form fields, insert/reorder/delete controls, full WAI-ARIA accessibility
- **JSON view** (toggle) — CodeMirror with real-time validation, autocomplete, and live preview
- Edits in either view sync to the other. JSON is the single source of truth
- Save blocked on invalid compositions — the database always holds the last valid value

### AI chat

An in-admin chat interface at PromptingPress > AI Chat. Talk to an LLM about your site ("What pages do I have?", "What are my design tokens?"), request changes, and approve structured mutations with preview cards. Streaming via SSE. BYOK — any OpenAI-compatible provider or WordPress 7.0 Connectors (Anthropic, Google, OpenAI).

### Agent operating framework

For autonomous AI agents: an 8-step operating loop (INSPECT, PLAN, EDIT, PREFLIGHT, APPLY, SCREENSHOT, REVIEW, HANDOFF) with run tokens, step enforcement, drift detection, and preflight safety gates. Three playbooks ship for common operations. The framework ensures agents can't skip safety steps or apply mutations without inspection.

### Theme integrity

Every release ships with a file-integrity manifest. `wp pp integrity check` compares live theme files against the build baseline and surfaces modifications, missing files, or extra files before an update overwrites them.

## Architecture

```
/components/{name}/        Component partials + schema.json
/templates/                Page layout files
/lib/wp.php                WP abstraction layer (pp_* wrappers)
/lib/actions.php           Typed action model (14 actions)
/lib/apply.php             Apply layer (file + option mutations)
/lib/operate.php           Operating loop: inspect, preflight, run tokens
/lib/cli.php               WP-CLI commands
/lib/admin.php             Composition editor
/lib/ai-chat.php           AI chat admin page
/lib/ai-context.php        AI system prompt assembly
/lib/ai-provider.php       LLM provider proxy (streaming + non-streaming)
/lib/guardrails.php        Surface classification, CSS conflicts, integrity
/lib/setup.php             Theme activation, homepage provisioning
/lib/components.php        Component auto-loader (don't edit)
/assets/css/base.css       Design token defaults (33 CSS variables)
/assets/css/components.css Component styles (CSS variables only)
/ai-instructions/          Task-specific AI workflow guides
AI_CONTEXT.md              Machine-readable site map — start here for AI
AI_RULES.md                Hard coding invariants
```

## Quick start

### Requirements

- WordPress 7.0+
- PHP 8.0+
- No build step for the site itself — vanilla PHP, CSS, and JS

### Installation

```bash
cd wp-content/themes/
git clone https://github.com/FJCF76/PromptingPress.git promptingpress
```

Activate the theme in WP Admin (Appearance > Themes). On activation, PromptingPress creates a Home page with the Composition template and sets it as the static front page.

To use the composition editor on any page: set its template to **Composition** (Page Attributes > Template).

### AI chat setup

Configure an LLM provider in Settings > Connectors (WordPress 7.0 Connectors API). PromptingPress supports Anthropic, Google, and OpenAI out of the box. Then open PromptingPress > AI Chat in the admin.

### CLI quick tour

```bash
# See all typed actions
wp pp action list

# Create a page with a composition
wp pp action execute create_page --params='{"title":"About Us"}'

# Inspect editable fields on a page
wp pp operate inspect-composition 74

# Preview a design token change without applying
wp pp apply preview update_design_token \
  --params='{"token":"--color-accent","value":"#b45309"}'

# Check theme file integrity
wp pp integrity check
```

## Architectural rules

These are enforced by `AI_RULES.md` and verified by tests:

- Templates call components. Components do not call components.
- No WordPress functions in `/templates/` or `/components/`. Only `lib/wp.php` calls WP.
- No hooks (`add_action`, `add_filter`) in view files. Only in `functions.php`.
- No raw hex values in `components.css` — CSS variables from `base.css` only.
- Every component ships with a `schema.json`.

## Tests

**572 PHP tests, 2158 assertions** — component loader, WP abstraction layer, invariant rules, schema validation, 14 typed actions, apply layer with file I/O, AI context assembly, proposal parsing, style slots, surface classification, font management, theme integrity, operating loop:

```bash
composer install
composer test
```

**141 JS tests** — JSON context parser, composition validator, accordion data, insert-position walker, data-loss guard, DOM selector alignment, CSS lint, packaging:

```bash
npm install
npm test
```

**7 E2E tests** — full composition editor round-trip against a live WordPress instance (requires Docker):

```bash
npm run env:start   # boot wp-env container
npm run test:e2e    # Playwright against http://localhost:8889
npm run env:stop    # tear down
```

## Project status

PromptingPress is in active development by a single developer. It is not yet packaged for broad distribution. The current focus is making the AI-agent workflow reliable and the composition model complete.

See [CHANGELOG.md](CHANGELOG.md) for a detailed history of every release from v0.0.1 through v0.8.1.

### What exists today (v0.8.1)

- 11 components with schema contracts and per-instance style slots
- Typed action/apply layer with validation, preview, and rollback
- Semantic composition patching (target fields by name, not index)
- In-admin AI chat with structured mutation proposals
- Agent operating framework with step enforcement and drift detection
- WP-CLI interface for all operations
- 720+ automated tests across PHP, JS, and E2E
- Theme integrity checking with build manifests

### What's next

See [open issues](https://github.com/FJCF76/PromptingPress/issues) for planned work.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for the full text.
