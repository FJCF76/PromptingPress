# TODOS

## Admin Composition Editor

### Save Composition — no success notification (ISSUE-004)
**Priority:** P3
**What:** After a successful "Save Composition", the page reloads silently with no visible confirmation. User has no feedback that the save worked.
**Why:** Deferred from /qa 2026-03-28. Low severity UX polish. DB save is confirmed correct; just missing the notice.
**Fix direction:** In `lib/admin.php` save handler, `wp_redirect(add_query_arg('saved', '1', ...))` and display an admin notice when `$_GET['saved']` is present.
**Depends on:** Nothing.

---

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

## Completed

### JS Test Infrastructure (2026-03-28)
Extracted three pure functions from `pp-admin-editor.js` into `assets/js/pp-editor-logic.js`:
`getJsonContextFromText`, `validateCompositionData`, `getInsertPosition`. Set up Vitest 3.x (no
bundler). 31 unit tests in `tests/js/pp-editor-logic.test.js` covering all edge cases including the
`afterColon` bug in the original props-key context walker. Run: `npm test`.

### Admin Composition Editor — core implementation (2026-03-26)
Built and deployed the full in-admin JSON editor for composing pages from registered components.
Ships: `composition.php`, `templates/composition.php`, `lib/admin.php`, `pp-admin-editor.js`,
`pp-admin-editor.css`, `ai-instructions/composition.md`, `AI_CONTEXT.md` update, `pp_composition()` in `lib/wp.php`.
