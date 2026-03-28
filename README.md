# PromptingPress

An AI-first WordPress theme — a clean component framework designed so any AI can understand, build, and edit the entire site directly.

## What makes this different

Most WordPress themes are optimized for human developers who accumulate WordPress knowledge over time. AI can't accumulate — it re-infers everything from the code on every session.

PromptingPress flips this: the structure itself is the documentation. An AI can load `AI_CONTEXT.md`, map the entire site in seconds, and make confident edits without knowing WordPress internals.

## Features

**Composition editor** — build and edit pages from the WordPress admin without touching a file. Any page using the Composition template gets a full-screen JSON editor with:
- Real-time validation and live preview as you type
- Component name autocomplete (Ctrl+Space)
- Component reference sidebar with props, types, and required/optional status
- Save blocked on invalid compositions — the database always holds the last valid value

**Component system** — 8 registered components, each with a `schema.json` that documents props, types, and required fields. Components are isolated PHP partials. No component calls another component. The auto-loader picks up any new component at `/components/{name}/{name}.php` — no registration needed.

**WP abstraction layer** — `lib/wp.php` is the only file that calls WordPress functions. Templates and components use `pp_*` wrappers only. This means AI can edit templates without knowing WordPress internals, and templates are testable without bootstrapping WP.

**Design token system** — 16 CSS custom properties in `assets/css/base.css` control the entire visual system. To retheme: edit those 16 variables and nothing else.

**AI context map** — `AI_CONTEXT.md` is a machine-readable site map: file responsibilities, component index, WP abstraction API, composition format, design tokens. Read it once and you know the whole site.

## Architecture

```
/components/{name}/        Component partials + schema.json
/templates/                Page layout files
/lib/wp.php                WP abstraction layer (pp_* functions only)
/lib/admin.php             Composition editor: AJAX handlers, validation, meta box
/lib/components.php        Component auto-loader (don't edit)
/assets/css/base.css       Design tokens — 16 CSS variables
/assets/css/components.css Component styles (CSS variables only, no raw hex)
/assets/js/pp-editor-logic.js  Pure JS logic: JSON context parser, validator, insert position
/assets/js/pp-admin-editor.js  Composition editor frontend (CodeMirror + preview)
AI_CONTEXT.md              AI site map — start here for any AI session
CLAUDE.md                  Claude Code instructions and invariants
```

## Components

| Component | Description | Key required props |
|-----------|-------------|-------------------|
| hero | Full-width headline + optional CTA and image | `title` |
| section | Text + optional image, 3 layout variants | `body` |
| faq | Native `details/summary` accordion, zero JS | `items[]` |
| grid | Responsive card grid for real content objects | `items[]` |
| table | Data/comparison table, horizontal scroll on mobile | `headers[]`, `rows[][]` |
| cta | Call-to-action block, two variants | `title`, `button_text`, `button_url` |
| nav | Site header with hamburger mobile nav | — |
| footer | Site footer with nav menu and copyright | — |

## Composition format

Pages using the Composition template store their layout in `_pp_composition` post meta as a JSON array:

```json
[
  { "component": "hero", "props": { "title": "Welcome", "variant": "centered" } },
  { "component": "section", "props": { "body": "<p>Content.</p>", "layout": "text-only" } },
  { "component": "faq", "props": { "items": [{ "question": "Q?", "answer": "A." }] } },
  { "component": "cta", "props": { "title": "Ready?", "button_text": "Go", "button_url": "/" } }
]
```

AI can write compositions directly via WP CLI:

```bash
wp post meta update <post_id> _pp_composition '[{"component":"hero","props":{"title":"Hello"}}]'
```

## Installation

1. Clone this repo into `wp-content/themes/promptingpress/`
2. Activate the theme in WP Admin (Appearance → Themes)
3. To use the composition editor on a page: set its template to **Composition** (Page Attributes → Template)

No build step required for the site itself. Vanilla PHP, CSS, and JS. npm is used only for running JS unit tests (`npm test`).

## Rules (enforced by CLAUDE.md)

- Templates call components. Components do not call components.
- No WordPress functions in `/templates/` or `/components/`. Only `lib/wp.php` calls WP.
- No hooks (`add_action`, `add_filter`) in view files. Only in `functions.php`.
- No raw hex values in `components.css` — CSS variables from `base.css` only.
- Every component ships with a `schema.json`.

## Tests

**PHP tests** (component loader, WP abstraction layer, invariant rules, schema validation):

```bash
composer install
composer test
```

**JS tests** (JSON context parser, composition validator, insert-position walker — 38 tests):

```bash
npm install
npm test
```

JS tests use [Vitest](https://vitest.dev/) with no bundler. Run `npm run test:watch` for watch mode.

## Status

Active development. See [CHANGELOG.md](CHANGELOG.md) for what's shipped.
