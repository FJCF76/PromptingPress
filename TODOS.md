# TODOS

## Component Primitive Gaps

### Section background variant (2026-03-30) ✓
Shipped in commit bfbef07. `variant: "default" | "dark" | "inverted"` on `section`. New `--color-bg-inverted` token. CSS classes `pp-section--dark`, `pp-section--inverted`. Verified on POC.

### Grid step variant (2026-03-30) ✓
Shipped in commit bfbef07. `variant: "steps"` on `grid`. Per-item `number` field with auto-index fallback. `pp-step-number` styled with `--color-accent`. Verified on POC.

---

## Admin Composition Editor


### Meta box template scoping (ISSUE-003)
**Priority:** P3
**What:** "Page Composition" meta box appears in the Block Editor sidebar on ALL pages regardless of template. Original design intent was to only show it on Composition template pages.
**Why:** Deferred from /qa 2026-03-28. Low severity. May be intentional to allow users to switch templates. Verify intent before fixing.
**Fix direction:** In `lib/admin.php` `add_meta_box()` call, add a `$screen` condition or `add_action('add_meta_boxes_{post_type}', ...)` callback that checks the page template before registering the box.
**Depends on:** Clarify whether always-visible is intentional.

---

### getJsonContextFromText positional walk refactor
**Priority:** P3
**What:** Replace the two-step regex+lastIndexOf approach in `getJsonContextFromText` with a single position-aware forward walk. Currently, if a prop string value contains the text `"component": "evil", "props": {`, the regex picks up "evil" as the component name and autocomplete shows an empty list (no XSS, no data corruption — wrong suggestions only).
**Why:** Low-severity product quality issue. Only hits a contrived editing state where the user deliberately types a JSON structure fragment inside a string value.
**Fix direction:** Track component names positionally during the walk, discard any match that is inside a string. ~30 lines. Write tests with injected text before implementing.
**Depends on:** Nothing.

---

### E2E Test Infrastructure
**Priority:** P2
**What:** Set up Playwright (or wp-browser + Codeception) with a live WordPress test instance to cover the full admin composition editor flow end-to-end.
**Why:** PHP unit tests cover server-side logic and JS unit tests cover individual JS functions, but the full round-trip — open page edit screen → CodeMirror visible → type JSON → preview iframe updates → save → admin notice on failure / page saves on success → front-end renders correctly — can only be verified with a real browser against a real WP instance. This is the highest-risk integration path and the most likely place for subtle bugs to hide.
**Context:** Key flows to cover: (1) meta box only visible when composition.php selected (template dropdown switch), (2) preview updates with valid JSON, (3) save rejected with admin notice when composition invalid, (4) autosave skipped with invalid JSON, (5) front-end renders components in correct order after valid save. A live WordPress test DB fixture with one page set to composition.php template is required. Consider `wp-env` (Docker-based) as the test environment.
**Depends on:** Initial admin editor feature ship (this feature branch). JS test infra (above) can be done in parallel.

---

## AI-First Documentation

### Composition vs template decision rule (ISSUE-005)
**Priority:** P3
**What:** `AI_CONTEXT.md` never states when to use a template file vs the Composition system. An agent infers the rule from context and may get it wrong.
**Fix direction:** Add two sentences to `AI_CONTEXT.md` under an "Authoring model" heading: "Use composition for all content-driven pages. Use template files only for structural or dynamic pages (archives, single posts, search results, 404)."
**Depends on:** Nothing.

---

### Doc hierarchy declaration in AI_CONTEXT.md (ISSUE-007)
**Priority:** P3
**What:** Multiple files cover overlapping territory (`AI_CONTEXT.md`, `CLAUDE.md`, `ai-instructions/*.md`, `components/{name}/schema.json`). An AI treats all Markdown as equally authoritative; without an explicit hierarchy, conflicting guidance requires the agent to resolve conflicts itself.
**Fix direction:** Add a four-line authority table near the top of `AI_CONTEXT.md`:
- Top-level map: `AI_CONTEXT.md` — orientation, file responsibilities, component index
- Hard invariants: `CLAUDE.md` — rules that override everything else
- Executable workflows: `ai-instructions/*.md` — task-specific procedures
- Schema source of truth: `components/{name}/schema.json` — prop contracts; supersedes prose descriptions in any other file
**Depends on:** Nothing.

---

## Completed

### Save Composition — no success notification (ISSUE-004) — **Completed: v0.1.3 (2026-04-01)**
Superseded by the action model rework. The composition editor now uses AJAX-only saves with inline status messages ("Draft saved", "Published", "Updated") — no page reload, no admin notice needed. The UX gap is gone.

### Bootstrap state contract — ai-instructions/bootstrap.md (ISSUE-006) (2026-03-30)
Created `ai-instructions/bootstrap.md` from the ISSUE-006 spec. Format: required object →
state predicate → WP-CLI verification command. Covers theme, WP options, homepage page,
composition data contract, nav/footer menus, and known WP-CLI behavior on this server
(proc_open restriction, post meta creation workaround). Validated during poc.promptingpress.com
provisioning sprint.

### webfiable.com Wedge Validation Sprint (2026-03-30)
**Result: PASS on AI authoring + token retheme. PARTIAL on visual fidelity.**

Provisioned poc.promptingpress.com from zero: nginx vhost, SSL (certbot --expand),
MySQL, WordPress 6.9.4 (es_ES), PromptingPress theme, 9-component homepage composition
(hero, 4×section, cta, grid, faq, section) via WP-CLI with no admin UI.

Retheme pass applied webfiable.com's palette (dark navy `#1a1a2e`, orange-red `#EA3900`)
via 7 CSS token changes — entire site visual system shifted in one edit.

**What passed:** AI can provision a real WordPress stack from zero and compose a real
homepage using only JSON + WP-CLI. The token retheme proposition works end-to-end.
Component coverage ~80% native; 2 HTML-body workarounds rendered cleanly.

**What was partial:** Visual fidelity fell short of webfiable.com due to two missing
composition primitives — not CSS gaps. POC renders as a flat dark site; webfiable has
section rhythm (alternating tones) and numbered step sequences. Both gaps are captured
as P2 TODOs above: `section.variant` and `grid.variant: "steps"`.

**Sprint closed.** No further polishing — gaps are documented and scoped.

### JS Test Infrastructure (2026-03-28)
Extracted three pure functions from `pp-admin-editor.js` into `assets/js/pp-editor-logic.js`:
`getJsonContextFromText`, `validateCompositionData`, `getInsertPosition`. Set up Vitest 3.x (no
bundler). 31 unit tests in `tests/js/pp-editor-logic.test.js` covering all edge cases including the
`afterColon` bug in the original props-key context walker. Run: `npm test`.

### Admin Composition Editor — core implementation (2026-03-26)
Built and deployed the full in-admin JSON editor for composing pages from registered components.
Ships: `composition.php`, `templates/composition.php`, `lib/admin.php`, `pp-admin-editor.js`,
`pp-admin-editor.css`, `ai-instructions/composition.md`, `AI_CONTEXT.md` update, `pp_composition()` in `lib/wp.php`.
