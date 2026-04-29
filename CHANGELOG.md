# Changelog

All notable changes to PromptingPress are documented here.

---

## [v0.2.2] — 2026-04-29 — AI Settings UX + error clarity

### Structured settings replace free-text fields

The AI Settings page now uses dropdowns for Provider and Model instead of four raw text inputs. GitHub Models users see three fields (Provider, API Key, Model). Custom/Manual users see four (+ Base URL). Switching providers swaps the model field type instantly without save+reload.

### Added

- Provider dropdown: GitHub Models (default) and Custom / Manual
- Curated model dropdown for GitHub Models (GPT-5 Chat, GPT-5, GPT-4.1) with "Custom model ID..." escape hatch
- Server-side Base URL derivation — you never see or set the endpoint URL for GitHub Models; PHP handles it
- Automatic migration from older settings format (no manual steps needed on upgrade)
- API Key helper text adapts per provider ("GitHub PAT with models:read" vs "Bearer token")
- Test Connection tells you to save first and disables itself until you do
- `pp_ai_get_providers()` single source of truth for provider config
- 13 new tests for migration, provider data, and sanitize callback

### Fixed

- **#17** Test Connection now works with GPT-5 models (was sending a parameter the model rejected)
- When the AI can't reach your provider, the chat now shows a clickable link to AI Settings instead of a raw error
- Clearer error messages for bad API key, wrong model, and rejected requests
- Base URL row hides correctly when GitHub Models is selected (JS selector fix)

### Changed

- Default model updated from `openai/gpt-4o` to `openai/gpt-5-chat`
- Default provider constant from `'GitHub Models'` to `'github_models'`
- Settings section description simplified

### Tests

- 256 tests, 785 assertions (was 243 tests, 730 assertions)

---

## [v0.2.1] — 2026-04-27 — Media-aware page editing

### AI can see and use your images

The AI chat now sees your WordPress media library. When you ask it to build a page or add a component, it picks real images from your uploads instead of hallucinating URLs. The system prompt includes every image's filename, dimensions, alt text, and exact URL, with rules for which components use images as backgrounds vs foreground elements.

### Added

- Media library inventory wired into AI system prompt with per-image filename, dimensions, alt text, and URL
- Image selection rules in system prompt: foreground vs background rendering, alt text requirements, component-specific prop mapping
- Component index in page context so the AI can unambiguously target components by index (`[0] hero | variant: cover`)
- Media URL validation on action execution — rejects hallucinated upload URLs before they hit the database
- Composition normalization (`pp_normalize_composition`) — accepts `"type"` as alias for `"component"` in composition arrays, canonicalizes on input
- Page-existence validation (`_pp_validate_page_exists`) on all 7 page-scoped actions
- Truncation detection (server-side + client-side) — shows informational message when AI response is cut short before proposal JSON
- Component summary helper (`_pp_summarize_component`) for the page context index

### Fixed

- AI-generated pages using `{"type": "hero"}` instead of `{"component": "hero"}` now work (normalization catches the alias)
- Actions against nonexistent page IDs now return clear error messages instead of cryptic failures

### Tests

- 23 new unit tests: 10 for page-existence validation, 8 for composition normalization, 5 for media library context and component summaries

---

## [v0.2.0] — 2026-04-26 — In-admin AI chat

### Talk to your site, change it from the conversation

You can now open **PromptingPress → AI Chat** in the WordPress admin, ask your site questions ("What pages do I have?", "What are my design tokens?"), and request changes ("Add a hero section to the About page", "Change the accent color to orange"). The AI reads your real site state, proposes structured mutations with preview cards, and executes them through the existing action/apply layer when you click Apply.

### Streaming chat with proposal cards

Responses stream token-by-token via SSE. When the AI proposes a change, you see a card with the action name, description, and Apply/Cancel buttons. Multi-step proposals show numbered steps with "Apply All". After applying, the AI knows about its own mutations and can build on them in the same conversation.

### BYOK provider configuration

**PromptingPress → AI Settings** lets you configure any OpenAI-compatible provider. Pre-filled defaults for GitHub Models (`openai/gpt-4o`). Fields: provider name, base URL, API key (server-side only, never sent to browser), model ID. Test Connection button verifies your setup.

### Conversation persistence

Messages persist in localStorage across page reloads. "New Chat" clears the conversation. Internal apply-confirmation messages are stored for AI context but hidden in the display.

### Page lifecycle actions

Three new typed actions: `trash_page` (move to trash, reversible), `restore_page` (restore from trash), `unpublish_page` (revert to draft). All support validate, preview, and execute. Available via WP-CLI, AJAX, and AI chat.

### Security

- API key stored server-side in `wp_options`, never exposed to browser
- Nonce separation: `pp_ai_stream` (read) vs `pp_ai_execute` (mutate)
- Role whitelist: only `user` and `assistant` roles accepted from client conversation
- XSS prevention: all chat rendering uses `textContent`, never `innerHTML`
- Provider error messages sanitized with `wp_strip_all_tags()`
- Capability gates: `manage_options` for settings, `edit_posts` for chat/execute

### 211 unit tests, 684 assertions

69 new tests covering AI context assembly, provider error paths, proposal parsing/validation, page lifecycle actions, nonce separation, and system prompt consistency.

### Known deferrals

- #15 — Markdown rendering in chat messages (content correct, just unformatted)
- #16 — Unit test coverage gaps (pp_ai_coerce_params, AJAX fallback, capability-denial paths)
- #14 — JS/frontend test coverage for chat UI

---

## [v0.1.7] — 2026-04-19 — Bounded design token mutation

### Programmatic write path for the design system

The AI interface can now change how the site looks, not just its content. `pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309'])` changes the accent color and the site visibly reflects it. Backup, verification, and restore are automatic.

### Apply layer (file-based mutations)

New adjacent execution contract in `lib/apply.php` for file-based mutations. Same architectural DNA as the action model, but for files instead of database. Validates params, creates backup, writes the file, verifies the full contract (target changed AND every non-target unchanged), and auto-restores on any violation.

### Safety model

- Backup to `wp-content/pp-backups/` before every write (keeps last 5)
- Verified backup before proceeding
- Full contract verification after write
- Auto-restore from backup on any failure
- Injection prevention: rejects `{`, `}`, `;` in values
- No-op detection: setting a token to its current value returns success with empty changes

### Token type metadata

All 18 design tokens now carry machine-readable type annotations in their CSS comments: `color`, `length`, `font-family`, `duration`, `raw`. Type-specific validation enforces correct CSS values (hex, rgb, rem, font stacks, etc.).

### WP-CLI

```bash
wp pp apply list                                                              # see registered applies
wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'  # diff without writing
wp pp apply execute update_design_token --params='{"token":"--color-accent","value":"#b45309"}'  # apply + verify
wp pp apply restore                                                           # undo last change
wp pp apply restore --point=2                                                 # restore specific point
wp pp apply restore --list                                                    # show available points
```

### Richer `pp_design_tokens()` return shape

Returns `['--token' => ['value' => string, 'type' => string|null]]` instead of flat key-value. Only 1 real caller existed (the new apply layer), so zero breakage.

### Browser cache busting

`base.css` enqueue now uses `PP_VERSION.filemtime()` suffix, so token changes are immediately visible without hard refresh.

### 142 unit tests, 509 assertions

57 new tests covering registry, validation (structural + type-specific), injection prevention, preview, execute, contract verification, backup pruning, cache invalidation, restore, and return shape.

---

## [v0.1.6] — 2026-04-18 — Typed action model, WP-CLI, and AJAX refactor

### One write path for everything

All mutations now go through typed actions. The composition editor, WP-CLI, and future AI callers all use the same `pp_execute_action()` layer. Every action validates before writing, returns the same structured result shape, and supports preview (see the diff without writing).

### 9 actions

You can now create pages, update compositions, add/remove/reorder components, update titles, publish pages, and change site options, all through one consistent interface. Each action declares its params, validates inputs, and returns a canonical `{ok, action, scope, target, changes, error}` result.

### WP-CLI interface

```bash
wp pp action list                                    # see all 9 actions
wp pp action preview update_component --params='{}'  # see the diff, never writes
wp pp action execute create_page --params='{"title":"New Page"}'
```

### AJAX handlers are now thin adapters

The 3 mutation AJAX handlers (`pp_save_composition`, `pp_save_title`, `pp_publish_page`) delegate to the action layer. Same POST params, same JSON response shape, zero JS changes. The editor works exactly as before, backed by a canonical architecture.

### Site-state read layer

New `pp_*` functions for querying site state: `pp_get_composition($post_id)` (composition for any page by ID), `pp_composition_pages()` (all composition pages), `pp_design_tokens()` (CSS custom properties from base.css), `pp_site_option($key)` (whitelisted options).

### 85 unit tests, 367 assertions

Full coverage of all 9 actions across validate, preview, and execute paths, plus edge cases (reorder permutation validation, OOB rejection, null-removes-prop, partial merge).

---

## [v0.1.5] — 2026-04-10 — Accordion editor for structured composition editing

### Accordion replaces the reference pane

The three-pane editor layout (JSON | Reference | Preview) is now two panes (Accordion | Preview). The reference pane, which showed static schema info, is gone. In its place, the editor pane defaults to an accordion view that renders each composition component as a collapsible card with typed form fields.

The accordion is a structured lens over the canonical JSON, not a replacement for it. A toolbar toggle switches between accordion and CodeMirror views. Edits in either view sync to the other. JSON remains the single source of truth.

### What the accordion does

- **Collapsible cards** for each component. Header shows component name + first prop value preview (truncated at 40 chars). All cards start collapsed on load.
- **Typed fields**: string inputs, multi-line textareas (for `body`, `content`, `answer`), enum dropdowns, and repeatable array sub-forms with add/remove item buttons.
- **Required field indicators**: red asterisk on labels, red border on blur when empty.
- **Component operations**: insert (dropdown at top and bottom, all 11 components), move up/down, delete. Each operation preserves expand/collapse state across re-renders.
- **JSON toggle round-trip**: accordion to JSON to edit to accordion, no data loss. Invalid JSON keeps you in JSON view with validation errors.

### Accessibility

Full WAI-ARIA accordion pattern: `aria-expanded`, `aria-controls`, `role="region"`, `aria-labelledby` on every card. Screen reader announcements via `aria-live="polite"` region on insert, reorder, and delete. Move/delete buttons have descriptive `aria-label` attributes. ARIA live region uses `.sr-only` clip pattern, invisible to sighted users.

### Pure logic extraction

`buildAccordionData()` and `serializeAccordionData()` added to `pp-editor-logic.js` as pure, testable functions with no DOM dependencies. 56 unit tests pass including round-trip, unknown component, and array field coverage.

### Removed

- Reference pane (`.pp-pane--reference`), component list, schema tab, second resize handle
- `initSidebar()`, `updateSchemaTab()`, `getNearestComponentName()` functions
- ~80 lines of reference pane CSS

---

## [v0.1.4] — 2026-04-04 — Phase 2 component capabilities + design token consistency

### 7 component capabilities added

This release closes the component capability gaps identified during the benchmark sprint. Every change is a reusable first-class addition to the component system, not benchmark-specific polish.

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
- `retheme.md` and `AI_RULES.md` updated to reflect 18 design tokens

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
