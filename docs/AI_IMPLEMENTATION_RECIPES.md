# AI Implementation Recipes

Repeatable patterns for the most common change types in this backlog, plus the load-bearing invariants every change must preserve. If an issue says "add a style slot / add an action / add a smell," follow the matching recipe rather than reverse-engineering it.

> Read this alongside `AI_CONTEXT.md`, `AI_RULES.md`, and the pinned queue **[#141](https://github.com/FJCF76/PromptingPress/issues/141)** (single source of truth). Work issues in the queue order; run the full suite green between issues.

---

## Load-bearing invariants (do not break these)

1. **Only `lib/wp.php` calls WordPress functions directly.** Templates and components call `pp_*` wrappers. If you need a new WP capability in a component/template, add a `pp_*` wrapper in `lib/wp.php` and call that.
2. **Components auto-load by convention:** `components/{name}/{name}.php` + `components/{name}/schema.json`. No registration. Do not edit `lib/components.php` (the loader contract).
2b. **Registered ⊋ composable (#223).** Auto-loading makes a component *renderable*, not *composable*. A component the page template renders itself is site chrome and must be declared in `pp_template_owned_components()` (`lib/admin.php`); `pp_validate_composition()` then rejects it from `_pp_composition` on every write-time path. Skip that step and the component is both rendered by the template and placeable in a page, so the page renders it twice while every validator passes. `tests/NavReadinessTest.php` fails if `templates/base.php` renders a component the list does not declare. The one deliberate exception is `restore_composition` — see invariant 2c.

2c. **Restore reports, never blocks (#233).** `restore_composition` replays a stored history snapshot, so it is the one mutation surface that may legally write a composition current validators reject: a snapshot captured before a rule existed still restores. Undo is wired to it (`assets/js/pp-ai-chat.js`), so a restore that current rules refuse would make undo fail exactly when a user most needs it. The rule is therefore *report, don't block*: restore canonicalizes legacy shape through `pp_normalize_composition()`, preserves the snapshot's content verbatim (chrome included — **never strip content from history**), and returns a `findings` array describing what current rules say. Findings come from the two shared engines, `pp_validate_composition_errors()` and `pp_validate_composition_smells()` — never a restore-specific validator. Any new validation rule you add to those engines is inherited by restore's report for free; that is the point. `pp_update_composition()` (`lib/wp.php`) stays a non-validating writer: the action layer owns validation.
3. **Actions/applies follow the contract:** `name`, `scope`/`domain`, `description`, `params`, and `validate` / `preview` (never writes) / `execute`|`apply` callables returning the canonical result shape. See `lib/actions.php` / `lib/apply.php`.
4. **Style-slot & token values are validated at write AND at render:** the write-time engine `_pp_validate_token_value` (`lib/apply.php`) is authoritative, and `pp_render_style_vars` (`lib/wp.php`) re-validates each stored value at the render boundary through that same shared engine before emitting it inline (defense-in-depth for values that reach storage without passing write-time validation, e.g. a restored snapshot per #233 or a raw DB write). A value the boundary rejects is dropped from output only; the page and any restore still succeed. Never render a raw slot value.
5. **Escaping at output:** `esc_html` for text, `esc_url` for URLs, `esc_attr` for attributes, `wp_kses_post` for rich HTML fields. Do not widen `esc_url` protocols globally.
6. **Composition is stored as a JSON string** in `_pp_composition` post meta. Read via `pp_get_composition($post_id)` (it decodes and tolerates array fixtures) — never `get_post_meta(...)` + `is_array()` (that was bug #119).
7. **Capabilities:** mutations must check the right capability for their scope. The AJAX preview/execute path resolves per-action/apply capabilities through `_pp_required_caps_for()` (`lib/ai-chat.php`, shipped for #131) and fails closed (`manage_options`) for unknown names, unknown scopes, or an unresolvable `post_id` — a new action must be covered by that resolver, not bolted on with its own check.
8. **Preserve the file-vs-composition authority model.** Presentation changes go through tokens / style slots / composition, not ad hoc CSS in core files.

---

## Recipe A — Add a per-instance style slot to a component

Used by: #99, #100, #108, #111, #61 (and the #99 scaffold issue defines the reusable path).

1. **Schema:** in `components/{name}/schema.json`, add under `styling.style_slots`:
   ```json
   "--{name}-{slot}": { "type": "color|length|number|duration|font-family|shadow|gradient", "default": "<css>", "description": "<what it controls>" }
   ```
2. **Validation is automatic** for known types via `_pp_validate_token_value()` (`lib/apply.php`) — but if you introduce a *new* type (e.g. `gradient`, #99), add its validator there and to the `switch` in `_pp_validate_token_value`, keeping the `{};<>` guard and a positive-pattern grammar (see `_pp_validate_length` clamp/calc handling as the model). Reject `var()`/`url()`/`env()` unless explicitly allowlisted.
3. **Render:** the slot is emitted by `pp_render_style_vars($props['__pp_style'] ?? [], '{name}')` in `components/{name}/{name}.php` (already wired for hero/section/grid/cta/faq/stats/testimonials; add the call for components that lack it). Reference the CSS var in `assets/css/components.css` with a fallback: `color: var(--{name}-{slot}, var(--color-text));`
4. **Do not** hardcode a color that beats the slot (that is bug #61 for `.faq__heading`). If a hardcoded rule exists, relax it so the slot wins.
5. **Test:** `tests/ComponentPropsTest.php` — assert the schema declares the slot with `type`+`default`; a render test asserts setting the slot emits the inline `--{name}-{slot}: <value>`; a negative test asserts an injection value (`}<script>`) is dropped.
6. **Verify:** `composer test`.

---

## Recipe B — Add an action or apply

Used by: #132 (menus), #134 (slug), #105 (import media), #122, #133, #62 (front-end redirects — DB option, `template_redirect` resolver).

1. In `lib/actions.php` (DB/WP mutations) or `lib/apply.php` (file/option mutations), call `pp_register_action('name', [...])` / `pp_register_apply(...)` with:
   - `scope` (`site`|`page`|`section`) or `domain`; `description`; `params` (`['type'=>'int|string|array|bool','required'=>bool]`);
   - `validate(array $params): true|WP_Error` — semantic checks only (structural type checks are automatic);
   - `preview(array $params): array` — compute before/after via `_pp_action_preview(...)`, **never write**;
   - `execute`/`apply(array $params): array` — do the write, return `_pp_action_result(...)` / `_pp_action_error(...)`.
2. **All WP calls go through a `pp_*` wrapper in `lib/wp.php`** (add one if missing) — do not call `wp_*` directly from the action closure except through those wrappers already used there.
3. **Capability:** if this is reachable from the AJAX chat path, ensure `_pp_required_caps_for()` (`lib/ai-chat.php`) covers it. Defaults: page/section → `edit_post` on the post (fail-closed to `manage_options` without a resolved `post_id`); site → `manage_options`; all applies → `manage_options`. Named overrides: publish/unpublish → `edit_post` + `publish_pages`; trash/restore → `delete_post`; `create_page` → `publish_pages`; menu actions → `edit_theme_options`. A new action with different needs gets an explicit case there, plus a denial test.
4. **Register editable fields** (only if the action edits component props addressable by selector) in `lib/operate.php` `pp_register_component_fields()` — and **every field name must exist in that component's `schema.json`** (drift is bug #120/#85). Add the assertion test.
5. **Surface in the AI prompt:** actions/applies auto-appear in `pp_ai_system_prompt()` (`lib/ai-context.php`) via the registry — no extra step, but verify the `description` is accurate.
6. **CLI:** if operators need it, add a subcommand in `lib/cli.php`. Method name = subcommand name; add `@subcommand hyphen-form` if the docs use hyphens (that mismatch is #95).
7. **Test:** `tests/ActionsTest.php` / `tests/ApplyTest.php` — validate/preview/execute happy path + a rejection path. Preview must not mutate.
8. **Verify:** `composer test`.

---

## Recipe C — Add a composition smell / guardrail

Used by: #51, #87.

1. In `lib/guardrails.php` `pp_validate_composition_smells(array $composition): array`, append a check returning `['type'=>..., 'index'=>$i, 'message'=>...]` (warning-grade; **never auto-remove content**). Guard malformed entries — this function runs over compositions that never passed write-time validation (raw meta writes, and every history-ring snapshot via `restore_composition`'s findings, #233). The loop already skips non-array `$item`, non-array `props`, and non-scalar `component`; do not assume any deeper prop is well-typed either. A check that declares `string $component` and receives an array is a fatal, not a warning.
2. It reaches the INSPECT surface automatically (`pp_inspect_site` reads composition via `pp_get_composition`, not raw meta — fixed in #119).
3. `wp pp check page` (`lib/cli.php`) already calls the smell checker; confirm your new smell appears there.
4. **Test:** `tests/GuardrailsTest.php` — smelly composition → warning with the right `type`/`index`; clean composition → none.
5. **Verify:** `composer test`.

---

## Recipe D — Add / change a component

Used by: #1 (testimonial), #102, #103, #56.

1. Create `components/{name}/{name}.php`, `schema.json`, `README.md`. It auto-loads.
2. Renderer: read `$props`, validate enums against an allowlist (see hero/section for the pattern), escape all output, and call `pp_render_style_vars(..., '{name}')` if it has style slots.
3. `schema.json`: `props` (with `required`), and `styling.style_slots` sized to the component's real visual jobs (Recipe A).
4. If it should be editable by semantic selector, register fields (Recipe B step 4).
5. **Decide composable vs chrome (#223).** If a page places it, it is composable — give it at least one required prop, or a bare `{"component":"x"}` validates while the accordion round-trip cannot preserve it (`SchemaValidationTest::testEveryComposableComponentDeclaresARequiredProp()` pins this). If instead `templates/base.php` renders it on every page, it is chrome: add it to `pp_template_owned_components()` (`lib/admin.php`) so `pp_validate_composition()` rejects it from a composition, and to `pp_template_owned_menu_locations()` (`lib/wp.php`) if it reads a nav-menu location. The drift guards in `tests/NavReadinessTest.php` read `templates/base.php` back and fail if the two disagree.
6. **Test:** `tests/ComponentLoaderTest.php` picks it up; `tests/ComponentPropsTest.php` for required props; a render test for output shape and escaping.
7. **Verify:** `composer test`.

---

## Recipe E — Chat UI / JS change

Used by: #123, #125, #136, #137, #139, #140, #14.

1. Pure logic goes in `assets/js/pp-editor-logic.js` (browser + Node/Vitest) or is extracted so it can be unit-tested; DOM wiring in `pp-admin-editor.js` / `pp-ai-chat.js`.
2. Assistant text is rendered via `renderMarkdown()` (escapes first) — keep that; user text stays `textContent`.
3. **Test:** `tests/js/*.test.js` (vitest). For flows, `tests/e2e/*.spec.ts` (playwright) with a deterministic mock SSE (#14).
4. **Verify:** `npm test` (and `npm run test:e2e` where applicable).

---

## Between every issue
- `composer test` (PHPUnit) and `npm test` (vitest) must be green before starting the next queue item. A regression at step N silently breaks later steps in a sequential chain.
- Do not start a `status:blocked` / `needs-decision` / `investigation` / `discussion` issue, or a `needs-design` issue whose design-gate comment is unresolved. See the `[Backlog metadata]` header at the top of each issue and the queue in #141.
