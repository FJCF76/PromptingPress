# Changelog

All notable changes to PromptingPress are documented here.

---

## [v0.1.0] — 2026-03-28 — Composition Editor beta

### New: In-admin JSON composition workspace

You can now build and edit pages directly from the WordPress admin without touching a file. Any page using the **Composition** template gets a full-screen three-pane editor:

- **Left:** CodeMirror JSON editor with syntax highlighting, real-time validation, and component name autocomplete (Ctrl+Space)
- **Center:** Component reference sidebar — shows all registered components, their props, required/optional status, and types
- **Right:** Live preview iframe — updates as you type (debounced, only on valid JSON)

The editor validates compositions before saving: unknown components, missing required props, and syntax errors are all caught with inline error messages. Invalid compositions are rejected — the database always holds the last valid value.

Keyboard shortcut: **Ctrl+S** saves from anywhere in the editor.

**Files shipped:** `lib/admin.php`, `assets/js/pp-admin-editor.js`, `assets/css/pp-admin-editor.css`, `composition.php`, `templates/composition.php`, `ai-instructions/composition.md`

### Polish: Design review pass on the workspace

Seven contrast, hit-target, and polish issues found and fixed:

- Pane headers, component descriptions, prop types, and schema placeholder text now meet WCAG AA contrast ratios against the dark editor background
- Resize handle hit area expanded from 4px to 20px (±8px pseudo-element) — much easier to grab
- CodeMirror line numbers lightened for better legibility

### Fix: Stale "Fix errors first." message after errors resolve

When a user fixed invalid JSON after a blocked save, the red "Fix errors first." status text stayed visible indefinitely — even with the error bar cleared. It now clears as soon as validation passes.

---

## [v0.0.1] — 2026-03-24 — Foundation

### New: Complete theme foundation

Full WordPress theme with a component system, WP abstraction layer, design token system, and AI context map.

- **Component system:** 8 registered components (hero, section, faq, grid, table, cta, nav, footer) — each with `schema.json`, typed props, and CSS variables only (no raw hex)
- **WP abstraction layer:** `lib/wp.php` with `pp_*` wrappers — templates never call WordPress directly
- **Design tokens:** 16 CSS custom properties in `base.css` control the entire visual system
- **AI_CONTEXT.md:** Machine-readable site map so any AI can orient in seconds

### Design polish

- Nav logo touch target raised to 44px for mobile
- Grid item titles scaled up for clearer hierarchy
- `text-wrap: balance` on all headings
- `prefers-reduced-motion` media query on all animations
- FAQ accordion entrance animation (CSS-only, fade + slide)
- Inner page hero padding reduced for better proportion

### Fix: 404 page with home CTA

Added `404.php` with a helpful error message and a link back home, replacing the bare WordPress default.
