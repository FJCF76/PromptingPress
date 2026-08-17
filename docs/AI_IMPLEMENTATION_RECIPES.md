# AI Implementation Recipes

Repeatable patterns for the most common change types in this backlog, plus the load-bearing invariants every change must preserve. If an issue says "add a style slot / add an action / add a smell," follow the matching recipe rather than reverse-engineering it.

> Read this alongside `AI_CONTEXT.md`, `AI_RULES.md`, and the pinned queue **[#141](https://github.com/FJCF76/PromptingPress/issues/141)** (single source of truth). Work issues in the queue order; run the full suite green between issues.

---

## Load-bearing invariants (do not break these)

1. **Only `lib/wp.php` calls WordPress functions directly.** Templates and components call `pp_*` wrappers. If you need a new WP capability in a component/template, add a `pp_*` wrapper in `lib/wp.php` and call that.
2. **Components auto-load by convention:** `components/{name}/{name}.php` + `components/{name}/schema.json`. No registration. Do not edit `lib/components.php` (the loader contract).
2b. **Registered ⊋ composable (#223).** Auto-loading makes a component *renderable*, not *composable*. A component the page template renders itself is site chrome and must be declared in `pp_template_owned_components()` (`lib/admin.php`); `pp_validate_composition()` then rejects it from `_pp_composition` on every write-time path. Skip that step and the component is both rendered by the template and placeable in a page, so the page renders it twice while every validator passes. `tests/NavReadinessTest.php` fails if `templates/base.php` renders a component the list does not declare. The one deliberate exception is `restore_composition` — see invariant 2c.

2c. **Restore reports, never blocks (#233).** `restore_composition` replays a stored history snapshot, so it is the one mutation surface that may legally write a composition current validators reject: a snapshot captured before a rule existed still restores. Undo is wired to it (`assets/js/pp-ai-chat.js`), so a restore that current rules refuse would make undo fail exactly when a user most needs it. The rule is therefore *report, don't block*: restore preserves the snapshot verbatim (chrome included — **never strip content from history**) and returns a `findings` array describing what current rules say. Since #604 "verbatim" is literal — no key is canonicalized on the way in, so a snapshot carrying a retired prop name or a pre-#69 `variant` restores with those names intact and is told about them in `findings`. Findings come from the two shared engines, `pp_validate_composition_errors()` and `pp_validate_composition_smells()` — never a restore-specific validator. Any new validation rule you add to those engines is inherited for free by restore's report AND by the read-only diagnostics `wp pp check page` / `wp pp validate site`, which read the same `_pp_composition_findings()` since #622; that is the point. **A rule you add to `pp_validate_composition_errors()` must report and CONTINUE, never end the item (#621).** Gate every finding on `_pp_claim_item_finding($sink, <role>, ...<locator>)` — using the same locator your message names, so the claim granularity matches the message granularity — then continue to the next prop / entry / field. Two rules that can judge the same value must not both report it, and a rule that skips a value another rule already owns asks `_pp_item_finding_claimed()` first. Only the four structural checks (no `component` key, non-scalar `component`, unknown component, template-owned chrome) end an item, because nothing below them can be judged. `pp_validate_composition()` still returns `errors[0]`, so the write path keeps its single actionable message; rule ORDER is therefore load-bearing (it decides which message a rejected write shows), and reordering rule blocks is a behavior change. `pp_update_composition()` (`lib/wp.php`) stays a non-validating writer: the action layer owns validation.
3. **Actions/applies follow the contract:** `name`, `scope`/`domain`, `description`, `params`, and `validate` / `preview` (never writes) / `execute`|`apply` callables returning the canonical result shape. See `lib/actions.php` / `lib/apply.php`.
4. **Style-slot & token values are validated at write AND at render:** the write-time engine `_pp_validate_token_value` (`lib/apply.php`) is authoritative, and `pp_render_style_vars` (`lib/wp.php`) re-validates each stored value at the render boundary through that same shared engine before emitting it inline (defense-in-depth for values that reach storage without passing write-time validation, e.g. a restored snapshot per #233 or a raw DB write). A value the boundary rejects is dropped from output only; the page and any restore still succeed. Never render a raw slot value. Both gates ("is the slot declared?" and "does the value pass?") live behind the single predicate `pp_style_declaration_renders()` (`lib/wp.php`) — call that rather than re-deriving either test.

5. **The schema definition object is a CLOSED key set (#575):** a slot or prop definition in `schema.json` may declare only the keys listed in `pp_slot_definition_keys()` / `pp_prop_definition_keys()` (`lib/admin.php`). `SchemaValidationTest` runs `pp_schema_definition_errors()` over every shipped schema, including nested `items` sub-definitions, and fails CI on an unknown key. The same engine also bounds the SHAPE of the declared values it knows about, because they are rendered into the AI catalog: `applies_when` clauses, `conditionality_note` (single-line, bounded prose), `role` (bounded set) and, since #630, `values` (a non-empty list of non-empty, single-line, quote-free strings). Adding a new definition-level field means adding it to those lists and to the AI catalog emitter (`pp_ai_definition_suffix()`, `lib/ai-context.php`) in the same change — a field an agent never sees is not in the baseline. That principle bit once and is now enforced structurally: #643 made an undeclared field inside an `items[]` entry a hard rejection, and the catalog had been rendering every array prop as bare `items: array`, so the agent was being refused for guessing names it was never shown. `pp_ai_condense_schema()` now emits each field map as `[entry fields: ...]` from the same is-a-field-map predicate the validator uses, which is the second emitter path to keep in mind: a nested field reaches the agent through the CONDENSER, not through `pp_ai_definition_suffix()`. See `ai-instructions/add-component.md` for the field-by-field contract.
5. **Escaping at output:** `esc_html` for plain-text fields, `esc_url` for URLs, `esc_attr` for attributes, `wp_kses_post` for rich HTML fields (the main prose surfaces: `section.body`, `faq.answer`), and `pp_kses_inline` (`lib/helpers.php`, #439) for supporting-text fields that allow a bounded inline subset (`a, strong, em, br`) but no block elements (`cta.body`, `grid.items[].text`, `testimonials.items[].quote`). Each text prop's schema `description` states its contract. Do not widen `esc_url` protocols globally, and do not widen the inline allowlist per-component.
7. **Composition is stored as a JSON string** in `_pp_composition` post meta. Read via `pp_get_composition($post_id)` (it decodes and tolerates array fixtures) — never `get_post_meta(...)` + `is_array()` (that was bug #119).
7. **Capabilities:** mutations must check the right capability for their scope. The AJAX preview/execute path resolves per-action/apply capabilities through `_pp_required_caps_for()` (`lib/ai-chat.php`, shipped for #131) and fails closed (`manage_options`) for unknown names, unknown scopes, or an unresolvable `post_id` — a new action must be covered by that resolver, not bolted on with its own check.
8. **Preserve the file-vs-composition authority model.** Presentation changes go through tokens / style slots / composition, not ad hoc CSS in core files.

---

## Recipe A — Add a per-instance style slot to a component

Used by: #99, #100, #108, #111, #61 (and the #99 scaffold issue defines the reusable path).

1. **Schema:** in `components/{name}/schema.json`, add under `styling.style_slots`:
   ```json
   "--{name}-{slot}": { "type": "color|length|length-or-none|number|duration|font-family|shadow|gradient|position|ratio|enum", "default": "<css>", "description": "<what it controls>" }
   ```
   The definition object is a CLOSED key set (invariant 5 above): `type`/`default`/`description`
   are required, and the optional keys — `values`, `item_eligible`, `applies_when`,
   `conditionality_note`, `role` — are enumerated in `pp_slot_definition_keys()` (`lib/admin.php`),
   with the field-by-field contract in `ai-instructions/add-component.md`. Use `length-or-none`
   **only** when the slot's declared default IS the keyword `none`, so the built-in uncapped state
   stays authorable (`--stats-max-width`, `--hero-heading-measure`, `--section-heading-measure`,
   `--cta-body-measure`, `--faq-body-measure`); a width cap with a real length default stays plain
   `length`. Declare `"role": "fill"` on a button/surface fill and `"role": "measure"` on a text
   measure — the roles are bounded by `pp_slot_roles()` and the runtime AI catalog emits them.
   If the slot only renders in a particular configuration, declare `applies_when` (the four
   ANDed clause forms) or, for a condition the grammar cannot express, `conditionality_note`,
   and add the row to `CONDITIONALITY_LEDGER` in `tests/SchemaValidationTest.php` in the SAME
   change. Both fields reach the agent through the runtime catalog line it reads before
   writing; only `applies_when` is machine-readable, so only `applies_when` drives the
   `inert_slot` advisory it gets after. Verify the condition against the renderer AND the CSS
   selector before declaring it — a wrong condition is advice an agent designs around.
2. **Validation is automatic** for known types via `_pp_validate_token_value()` (`lib/apply.php`) — but if you introduce a *new* type (e.g. `gradient`, #99), add its validator there and to the `switch` in `_pp_validate_token_value`, keeping the `{};<>` guard and a positive-pattern grammar (see `_pp_validate_length` clamp/calc handling as the model). Reject `var()`/`url()`/`env()` unless explicitly allowlisted.
3. **Render:** the slot is emitted by `pp_render_style_vars($props['__pp_style'] ?? [], '{name}')` in `components/{name}/{name}.php` (already wired for hero/section/grid/cta/faq/stats/testimonials; add the call for components that lack it). Reference the CSS var in `assets/css/components.css` with a fallback: `color: var(--{name}-{slot}, var(--color-text));`
   **Declare it inside that component's OWN block, and never name another component's slot (#578).** A shared rule that caps six bands' headings from one selector list reading `var(--cta-heading-measure, …)` is not a slot for five of them: they can neither SET it (the write path rejects a foreign slot with `invalid_style_slot`) nor have it RESOLVE (slot custom properties are emitted on the owning component's root, so they never reach a sibling band's subtree). It renders as a literal wearing a `var()` costume, and because such a rule usually lives in some *other* component's block it is invisible to every per-component audit. Give each component its own slot, and route the shared default through a design token (`var(--measure-heading)`) when the bands are meant to move together. `tests/MeasureSurfaceTest.php` pins the severance from both sides — each component's own slot reaches its own element, and no non-cta selector still reads a cta slot.
   **Prefer a design token to a duplicated literal.** A bare `56rem` that happens to equal `--measure-centered` silently opts that rule out of a site-wide retune; reference the token instead.
4. **Do not** hardcode a color that beats the slot (that is bug #61 for `.faq__heading`). If a hardcoded rule exists, relax it so the slot wins. This holds **across stylesheets, not just within `components.css`**: an automatic-match rule in `base.css`/`utilities.css` (a bare element/pseudo-class selector like `p:last-child` or `a:hover`) can outrank a bare component-class slot rule and silently kill the slot (bug #336). Out-specify it at header scope (`.{name}__header > .{name}__subheading`), not by deleting the legitimate global rule. `tests/StyleSlotContractTest.php` (check 8) fails the build when a new such cross-sheet rule appears on a slot-consumed property; the rendered pins in `tests/e2e/style-render.spec.ts` own the real-cascade proof.
5. **Slot NAMES are matched by foreign stylesheets (#332).** WP core's global styles ship attribute-substring selectors — `html :where([style*="border-width"]) { border-style: solid }`, the `border-color` twin, and their four per-side variants. Our slots render as inline *custom properties*, so the substring is present in the property NAME: any slot named `--{name}-border-{width,color}` (or a per-side variant) makes core's rule match the element that carries it, even when the value is `0` and the border it controls lives on a descendant. An element with no border declaration of its own then computes core's injected `solid` at the initial `medium` width — a 3px border nobody asked for.
   You do not need to avoid these names, and must not rename existing ones (they are public AI-facing surface). The immunity baseline at the top of `assets/css/components.css` (`[data-pp-component], .grid__item { border-style: none; border-width: 0 }`) neutralizes core's rule for every element that can carry inline slot properties. **What you must preserve:** emit slot custom properties only onto an element the baseline covers — a component root carrying `data-pp-component`, or `.grid__item`. Rendering them onto any other element re-opens #332 on that element. `tests/StyleSlotContractTest.php` (check 7) fails the build if you do, and the rendered pins in `tests/e2e/style-render.spec.ts` (`#332 …`) prove the immunity in a real browser.
6. **A per-instance BUTTON slot needs one more edit (#545).** Slots are emitted on the component ROOT and inherit to every descendant, so any button slot consumed by a selector that can match a `.btn` the component does not own (`main .btn:not(...)`, `.hero .btn:not(...)`, `.cta .btn:not(...)`) would also repaint a `.btn` an author hand-writes into a rich-text prop. Add the new slot to the `main .btn:not(.hero__cta):not(.cta__button):not(.section__panel-cta)` neutralisation rule in `assets/css/components.css`, which sets it to `initial` (the guaranteed-invalid value) on every button no renderer owns. The `#545` pin in `tests/js/css-lint.test.js` derives the requirement structurally — schema slots read by a leak-capable selector — so it fails the build if you skip this. Band-level accents that are *meant* to reach every accented element in the band go in that pin's `INTENTIONALLY_REACHING` list instead.
7. **A slot at the head of a chain can still be DEAD, and only a rendered check finds it (#584).**
   Steps 4 and 5 cover a *literal* clobbering a slot. The other failure is subtler and every
   source-text pin passes through it: another **slot-routed** rule, for a VARIANT of the same
   component, decides the same property and does not carry your new slot. Two shapes to check
   before you call the routing done:
   - **A variant rule that wins.** `--hero-button-border` was added at the head of
     `.hero .btn:not(...)` [0,5,0] and was dead on every `layout: "cover"` hero, because
     `.hero--cover .hero__cta:not(...)` is [0,5,0] too and comes LATER in source order, so it
     is the live border winner on that band — and it kept its old chain. The schema description
     promised the slot applied. Grep every `.{name}--{variant}` and `.{name}--has-bg-image`
     rule that declares your property, not just the base rule.
   - **A base rule that never wins.** `.section__panel-cta:not(...)` is [0,4,0] and the shared
     premium `main .btn:not(...)` is [0,4,1], so the section block's rule NEVER decides a
     composed panel CTA's border. That block is the slot-contract keystone
     (`StyleSlotContractTest` check 1 requires an in-block consumption), so the slot has to be
     routed in BOTH places: the keystone for the contract, the premium rule for the paint.
     Route only one and you get either a dead slot or a red build.
   Prove it in `tests/e2e/style-render.spec.ts`, not in a source pin: set the slot, read
   `getComputedStyle(...).borderColor`, and read it AGAIN with the site-wide knob
   (`--btn-border-color`) also set — that second read is what separates "declared" from "wins".

8. **Pin the new slot in the baseline, in the SAME change (#598).** Append it to
   `PINNED_SLOT_BASELINE` in `tests/SchemaValidationTest.php`, then update
   `SLOT_BASELINE_FLOOR` (the count) and `SLOT_BASELINE_FINGERPRINT` (run the suite; the
   failure prints what it should be). Skipping this fails the build, by design: an
   unpinned slot is one a later rename could retire with no documentation trail, because
   it was never in the baseline to go missing from. The same applies to a new **prop** and
   `PINNED_PROP_BASELINE` / `PROP_BASELINE_FLOOR` / `PROP_BASELINE_FINGERPRINT`.
   Retiring or renaming a slot is a different path — see "How a rename happens now" in
   `ai-instructions/add-component.md`: the name MOVES into `SLOT_RENAME_MIGRATION_NOTES`
   with a note citing the ruling issue. Deleting its baseline line is never the fix.
9. **Test:** `tests/ComponentPropsTest.php` — assert the schema declares the slot with `type`+`default`; a render test asserts setting the slot emits the inline `--{name}-{slot}: <value>`; a negative test asserts an injection value (`}<script>`) is dropped.
10. **Verify:** `composer test`.

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
4. **Editable (patchable) fields are schema-derived — nothing to register** (#509). `pp_get_component_fields()` in `lib/operate.php` derives the `operate patch` / `inspect-composition` field set straight from each component's `schema.json`: every scalar-typed prop (`type` of `string`/`number`/`enum`, plus any `format` such as `link_url`) is patchable by default, and scalar sub-props of the `items` array are patchable as `items[].<field>`. So a new scalar prop becomes patchable the moment its schema declares it — no hand-list edit (the retired `pp_register_component_fields()` registry was the drift source of #120/#85). There is **no per-prop opt-out**: declaring a scalar prop makes it patchable, full stop. The `"patchable": false` escape hatch #509 shipped (v1.8.2) was left off the closed prop definition key set when #575 introduced it (v1.12.4), so from that release declaring it failed CI while this recipe went on instructing it; #629 removed the mechanism rather than widen the key set. Note the key set is a repo-CI invariant, not a runtime gate, so a hand-edited schema on a live install that still carries the key simply loads with it unread — and, since #629, with that prop patchable. The drift-catcher tests in `tests/OperateTest.php` pin this.
5. **Surface in the AI prompt:** actions/applies auto-appear in `pp_ai_system_prompt()` (`lib/ai-context.php`) via the registry — no extra step, but verify the `description` is accurate.
6. **CLI:** if operators need it, add a subcommand in `lib/cli.php`. Method name = subcommand name; add `@subcommand hyphen-form` if the docs use hyphens (that mismatch is #95).
7. **Test:** `tests/ActionsTest.php` / `tests/ApplyTest.php` — validate/preview/execute happy path + a rejection path. Preview must not mutate.
9. **Verify:** `composer test`.

---

## Recipe C — Add a composition smell / guardrail

Used by: #51, #87.

1. In `lib/guardrails.php` `pp_validate_composition_smells(array $composition): array`, append a check returning `['type'=>..., 'index'=>$i, 'message'=>...]` (warning-grade; **never auto-remove content**). Guard malformed entries — this function runs over compositions that never passed write-time validation (raw meta writes, and every history-ring snapshot via `restore_composition`'s findings, #233). The loop already skips non-array `$item`, non-array `props`, and non-scalar `component`; do not assume any deeper prop is well-typed either. A check that declares `string $component` and receives an array is a fatal, not a warning.
2. It reaches the INSPECT surface automatically (`pp_inspect_site` reads composition via `pp_get_composition`, not raw meta — fixed in #119).
3. `wp pp check page` and `wp pp validate site` (`lib/cli.php`) both read `_pp_cli_page_diagnostics()`, which reads the shared findings engine (`_pp_composition_findings()` = errors + smells, #622); confirm your new smell appears there. Do not add a surface-specific validator — a new rule in either shared engine reaches every read-only diagnostic and `restore_composition`'s `findings` for free.
4. **Do not red a fresh install.** `wp pp validate site` sets `$pass = false` on ANY smell — and, since #622, on any error-severity finding too — then ends `WP_CLI::halt(1)` (`lib/cli.php`), so a rule that fires on `pp_default_homepage_composition()` — the seed theme activation writes — makes a fresh install exit 1 with nothing the operator can fix. Pin the shipped seed clean in the same change (see `AppliesWhenTest::testTheShippedStarterHomepageEmitsNoInertSlotSmell` and `DiagnosticReachTest::testTheShippedStarterHomepageHasNoErrorSeverityFindings`). This is what deferred #578's measure advisory to issue #610.
5. **Test:** `tests/GuardrailsTest.php` — smelly composition → warning with the right `type`/`index`; clean composition → none.
6. **Verify:** `composer test`.

**Rejecting a style slot from `style_component`.** Build the `WP_Error` with `_pp_invalid_style_slot_error($component, $slot, $available_slots, $candidate_slots)` (`lib/actions.php`), never a bare `new WP_Error('invalid_style_slot', ...)`. A bare one still reads correctly to a human, but it carries no context, so `pp_rejected_slot_context()` returns null and the chat's error builder falls back to reading the composition a second time — the drift #626 removed, where the response could describe a component that had rejected nothing and a slot set that excluded everything a recipe contributed.

**Adding an ERROR rule instead of a smell.** Build the `WP_Error` with `_pp_composition_item_error($i, $code, $message)` (`lib/admin.php`), never a bare `new WP_Error(...)`. A bare one compiles, passes, and silently reports `index: null`, which breaks the documented contract that only the cross-item `duplicate_component_id` lacks a locator (#622) — and a finding with no band is one the operator cannot act on. A genuinely cross-item rule skips the helper on purpose and names every colliding index in its message instead.

---

## Recipe D — Add / change a component

Used by: #1 (testimonial), #102, #103, #56.

1. Create `components/{name}/{name}.php`, `schema.json`, `README.md`. It auto-loads.
2. Renderer: read `$props`, validate enums against an allowlist (see hero/section for the pattern), escape all output, and call `pp_render_style_vars(..., '{name}')` if it has style slots.
3. `schema.json`: `props` (with `required`), and `styling.style_slots` sized to the component's real visual jobs (Recipe A).
4. Semantic-selector editability is automatic (#509): every scalar prop you declare in `schema.json` (`type` string/number/enum, with any `format`) is patchable via `operate patch` / `inspect-composition` by derivation — no registration, and no way to opt a prop out (#629 retired the `"patchable": false` key; see Recipe B step 4). Scalar sub-props of an `items` array are patchable as `items[].<field>`.
5. **Decide composable vs chrome (#223).** If a page places it, it is composable — give it at least one required prop, or a bare `{"component":"x"}` validates while the accordion round-trip cannot preserve it (`SchemaValidationTest::testEveryComposableComponentDeclaresARequiredProp()` pins this). If instead `templates/base.php` renders it on every page, it is chrome: add it to `pp_template_owned_components()` (`lib/admin.php`) so `pp_validate_composition()` rejects it from a composition, and to `pp_template_owned_menu_locations()` (`lib/wp.php`) if it reads a nav-menu location it renders on EVERY page. A location it renders only when a menu is assigned goes in `pp_conditionally_rendered_menu_locations()` instead (#582) — the two lists answer different questions, and a conditional location in the always-on list warns every site that never opted in. The drift guards in `tests/NavReadinessTest.php` read `templates/base.php` back and fail if either list disagrees with it.
6. **If it renders a `.btn`, add its owning element class to the `main .btn:not(...)` rule (#545)** in `assets/css/components.css`. That rule neutralises every per-instance button slot on buttons no renderer owns; a new button class missing from its `:not()` list gets its OWN component's slots neutralised. `tests/NestedButtonSlotIsolationTest.php` derives the owner set from the templates and fails until the lists agree.
7. **Test:** `tests/ComponentLoaderTest.php` picks it up; `tests/ComponentPropsTest.php` for required props; a render test for output shape and escaping.
9. **Verify:** `composer test`.

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
