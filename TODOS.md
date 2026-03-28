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

## AI-First Documentation

### Composition vs template decision rule (ISSUE-005)
**Priority:** P3
**What:** `AI_CONTEXT.md` never states when to use a template file vs the Composition system. An agent infers the rule from context and may get it wrong.
**Fix direction:** Add two sentences to `AI_CONTEXT.md` under an "Authoring model" heading: "Use composition for all content-driven pages. Use template files only for structural or dynamic pages (archives, single posts, search results, 404)."
**Depends on:** Nothing.

---

### Bootstrap state contract — ai-instructions/bootstrap.md (ISSUE-006)
**Priority:** P3
**What:** No document defines what a correctly provisioned PromptingPress install looks like as verifiable state. An agent setting up a fresh WordPress install must improvise the required database and option state, which creates conceptual drift toward procedural WordPress habits.
**Why:** A state contract (not a setup checklist) keeps the documentation centered on verifiable system targets — the AI-first direction. It also makes Option B (seed script automation) trivially derivable later: the script just enforces the contract instead of inventing it.
**Fix direction:** Create `ai-instructions/bootstrap.md`. Format throughout: required object → required state predicate → WP-CLI verification command. Contents must include the following minimum required state:

**Theme**
- Active theme is `promptingpress`
- Verify: `wp theme status promptingpress` → `Status: Active`

**WordPress options**
- `show_on_front = page` (static homepage, not latest posts)
- Verify: `wp option get show_on_front` → `page`
- `page_on_front` = post ID of the homepage page object
- Verify: `wp option get page_on_front` → numeric post ID
- `permalink_structure = /%postname%/` (pretty permalinks required)
- Verify: `wp option get permalink_structure` → `/%postname%/`

**Homepage page**
- A page exists whose post ID matches `page_on_front`
- `_wp_page_template = composition.php`
- Verify: `wp post meta get <id> _wp_page_template` → `composition.php`
- `_pp_composition` is present and is a valid non-empty JSON array of registered components
- Verify: `wp post meta get <id> _pp_composition` → valid JSON array

**General rule for all composition pages**
- Every page with `_wp_page_template = composition.php` must have a valid `_pp_composition` value: non-null, non-empty, valid JSON array. The PHP save handler rejects invalid values and retains the last valid one, but an absent value on a new page produces a blank render with no error. State contract must treat absence as a failure.
- Verify: `wp post meta get <id> _pp_composition`

**Primary navigation menu**
- A WordPress menu must be created and assigned to location `primary`
- WARNING: the nav component renders silently empty if no menu is assigned to `primary`. WordPress does not error. Menu assignment is mandatory contract state, not optional polish.
- Verify: `wp menu location list` → `primary` column shows a menu name (not blank)

**Footer navigation menu**
- A WordPress menu must be created and assigned to location `footer`
- WARNING: the footer component renders silently empty if no menu is assigned to `footer`. WordPress does not error. Menu assignment is mandatory contract state, not optional polish.
- Verify: `wp menu location list` → `footer` column shows a menu name (not blank)

A future agent can draft `ai-instructions/bootstrap.md` directly from this spec without reinterpreting intent.
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

### JS Test Infrastructure (2026-03-28)
Extracted three pure functions from `pp-admin-editor.js` into `assets/js/pp-editor-logic.js`:
`getJsonContextFromText`, `validateCompositionData`, `getInsertPosition`. Set up Vitest 3.x (no
bundler). 31 unit tests in `tests/js/pp-editor-logic.test.js` covering all edge cases including the
`afterColon` bug in the original props-key context walker. Run: `npm test`.

### Admin Composition Editor — core implementation (2026-03-26)
Built and deployed the full in-admin JSON editor for composing pages from registered components.
Ships: `composition.php`, `templates/composition.php`, `lib/admin.php`, `pp-admin-editor.js`,
`pp-admin-editor.css`, `ai-instructions/composition.md`, `AI_CONTEXT.md` update, `pp_composition()` in `lib/wp.php`.
