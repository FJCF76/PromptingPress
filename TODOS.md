# TODOS

## Admin Composition Editor

### JS Test Infrastructure
**Priority:** P2
**What:** Set up a JS unit test runner (Vitest or Jest) for the admin meta box JavaScript — the inline CodeMirror validation logic, the hand-rolled JSON context/token walker, and the debounced preview update handler.
**Why:** The JS validation logic (context parser that walks tokens to find nearest `"component"` key, prop suggestion filtering, debounce behavior) is non-trivial and currently has no automated coverage. Manual QA can verify the happy path but won't catch regressions in the token walker edge cases (cursor at end of document, nested objects, malformed JSON mid-type).
**Context:** The project has no JS build pipeline (vanilla JS, no npm, no bundler). Adding Vitest with a standalone config that doesn't require bundling is the path of least resistance. The token walker and validation logic can be extracted as pure functions and tested in isolation. Recommend starting with: (1) context parser unit tests, (2) debounce timer tests, (3) autocomplete suggestion filter tests. See `lib/admin.php` JS localization for the registry data shape.
**Depends on:** Initial admin editor feature ship (this feature branch).

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
