# Changelog

All notable changes to PromptingPress are documented here.

---

## [v0.1.4] — 2026-04-04 — Phase 2 component capabilities + design token consistency

### 7 component capabilities added

This release closes the component capability gaps identified during the Velaochaga benchmark sprint (Phase 2). Every change is a reusable first-class addition to the component system, not benchmark-specific polish.

- **Hero dual CTA** — `cta2_text` + `cta2_url` props render a secondary outline button alongside the primary CTA. On `cover` variant, the outline button gets white border/text for visibility over the dark overlay.
- **Nav image logo** — `logo_url` + `logo_alt` props. When `logo_url` is set, renders an `<img>` instead of text. Falls back to `logo_text` when empty.
- **Grid background themes** — `theme` prop (`default`, `dark`, `inverted`) controls background color independently of `variant` (which controls layout). Follows the same dual-axis pattern established by CTA.
- **Grid steps connectors** — `steps` variant now renders `→` arrow pseudo-elements between cards at desktop (≥1024px). Connectors use `--color-muted` and suppress on mobile.
- **Stats background image** — `background_image` prop with the standard overlay pattern (inline style + `.stats__overlay` div + `var(--overlay-bg)`).
- **Logos variants** — `variant` prop (`default`, `dark`, `inverted`) for background control on logo strip sections.

### Design token: `--overlay-bg`

All 4 components with background-image support (hero, section, cta, stats) now reference `var(--overlay-bg)` instead of hardcoded `rgba()` values. This is the 18th design token in `base.css`. A site-builder AI can now control overlay darkness from one place during retheme.

### AI instructions: multilingual orthography verification

New Step 5 in `build-landing-page.md` for verifying diacritics, accent marks, and language-specific punctuation when generating non-English composition content. Cross-referenced from `composition.md`.

### Documentation

- `AI_CONTEXT.md` updated with all new props, dual-axis pattern for grid, background-image recipe for 4 components, and 18-token count
- `composition.md` component reference table updated with all 11 components and correct props
- `retheme.md` and `CLAUDE.md` updated to reflect 18 design tokens

---

## [v0.1.3] — 2026-04-01 — Composition-first page editing + homepage bootstrap

### Composition editor as the page editing experience

PromptingPress treats the composition editor as the page editing experience, not a mode
you opt into per page. This release makes that clearer through the editor's action model.

Draft pages show **Publish** as the primary action and **Save Draft** as secondary.
Published pages show only **Update**. After you publish a draft, the editor switches into
the published state immediately — no page reload.

### Fresh installs get a real Home page

When no valid static front page exists, activating the theme now creates one: a published
page titled "Home", assigned the Composition template, set as the site front page in
Reading Settings. The page appears in the Pages list and is immediately editable through
the composition editor.

Previously, a site with no real front page silently appeared healthy from the front end.
Now, if no static front page is configured, the condition is visible — admins see a
message with a link to fix it.

### Fix: Pages → Add New was restricted to administrators only

The handler that creates a new draft and opens the composition editor was checking
`create_pages`, which is not a real WordPress capability. In practice this restricted the
flow to administrators only. Now correctly checks `edit_pages`.

---

## [v0.1.2] — 2026-03-30 — Section and grid composition primitives

### Added: `section.variant` — per-section background control

Sections can now carry their own background tone, enabling visual rhythm on multi-section pages without touching CSS. Set via `variant` prop in composition JSON:

- `default` — page background (`--color-bg`). No class added. Backward-compatible default.
- `dark` — surface background (`--color-surface`) with a 1px border above and below. Subtle differentiation.
- `inverted` — inverted background (`--color-bg-inverted`). Strong contrast. Full text/heading color override included.

```json
{ "component": "section", "props": { "body": "<p>...</p>", "variant": "dark" } }
```

New design token: `--color-bg-inverted` (8th color token in `base.css`). Set this alongside the other 7 color tokens when rethemeing.

### Added: `grid.variant: "steps"` — numbered process cards

Grid now renders as a numbered step sequence when `variant: "steps"` is set. Use for How-It-Works flows, onboarding sequences, or any ordered process.

- Step number rendered per item (`number` field, or auto-indexed from 1)
- Images suppressed in steps mode — title + text only
- Number styled with `--color-accent` for visual anchor

```json
{ "component": "grid", "props": { "variant": "steps", "items": [
  { "number": "1", "title": "Sign up", "text": "Create your account." }
] } }
```

### Fixed: `pp-section--dark` invisible on light theme

On the default light palette, `--color-surface` (#f9fafb) and `--color-bg` (#ffffff) are nearly identical (1.04:1 contrast). Added 1px `--color-border` top/bottom borders to `.pp-section--dark` so the boundary reads on any palette.

### Added: Bootstrap state contract

`ai-instructions/bootstrap.md` — a machine-readable state contract with WP-CLI verification commands for every required site state (theme, options, homepage, composition data, menus). Lets any AI provision a fresh PromptingPress site from zero without guesswork.

---

## [v0.1.1] — 2026-03-28 — JS test infrastructure + bug fixes

### New: JS unit test suite (Vitest, 38 tests)

Pure-function logic extracted from `pp-admin-editor.js` into `assets/js/pp-editor-logic.js`:
`getJsonContextFromText`, `validateCompositionData`, `getInsertPosition`. All three are
covered by 38 unit tests in `tests/js/pp-editor-logic.test.js` using Vitest 3.x — no bundler,
no build step.

```
npm install
npm test
```

### Fix: Global namespace pollution (ISSUE-002)

The three extracted functions were leaking into `window` scope as bare globals
(`window.getJsonContextFromText` etc.) because they were top-level `function` declarations
in a plain `<script>` tag. Wrapped in an IIFE — functions are now scoped and only
`window.PPEditorLogic` is exported to the browser. Node/CJS path for Vitest is unaffected.

### Fix: afterColon bug in props-key context walker

The original props-key context walker treated every position after a `:` as a value slot,
even after a `,` reset. Cursor placed immediately after a comma (at the start of a new key)
was returning `null` instead of `{ type: 'props-key', componentName }`. Fixed and covered
by tests.

### Fix: Null/false/"" treated as absent for required props

`validateCompositionData` now rejects required props whose value is `null`, `false`, or `""`
in addition to missing keys. This matches the PHP-layer validation contract documented in
`ai-instructions/composition.md`.

### Fix: Array.isArray guard for prop values

`validateCompositionData` now rejects array-typed required prop values that are `[]` (empty).

### Fix: window.module collision

The Node/CJS export guard now checks `process.versions.node` instead of `typeof module`,
preventing WP plugins that define `window.module` from stealing the exports branch.

### Fix: bracketPos guard in getInsertPosition

`getInsertPosition` returns early with `bracketPos: -1` when no `[` is found, rather than
returning `afterIdx: -1` with an empty `itemEnds` that could confuse callers.

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
