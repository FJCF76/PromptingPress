# 📦 Reference — the `wp pp apply` command family

`wp pp apply` is the WP-CLI surface an AI agent (or an operator) uses to change PromptingPress **design tokens** safely: preview a change, freeze a rollback point, apply it, and roll it back per-run if needed. Every mutating subcommand is gated by a **run token** and a completed **preflight**, so a token write can't land without a recorded way to undo it.

This page is the complete, factual surface: every subcommand, its flags, its JSON output shape, its exit codes, and the exact error messages you'll see. For *why* the gate is built this way, read the [operating-loop safety explanation](operating-loop-safety.md). For the step-by-step apply→rollback walkthrough, read [How to apply a token change and roll it back](howto-apply-and-rollback.md).

Command registration: `WP_CLI::add_command('pp apply', 'PP_Apply_Command')` (`lib/cli.php`).

---

## The run token (read this first)

Every mutating subcommand (`execute`, `restore`, `reset`, `preflight`) requires `--run-id=<uuid>`. You get one from:

```bash
wp pp operate inspect
```

`inspect` returns the site's operating picture as JSON with a `run_id` field appended. That `run_id` is:

- **A UUID v4.** `--run-id` is rejected with `--run-id must be a valid UUID v4. Got: "<value>"` if it isn't (`pp_operate_valid_run_id`).
- **Install-scoped and time-limited.** The run state is stored per-install and auto-expires **2 hours** after creation (`PP_OPERATE_RUN_TTL = 7200`, `lib/operate.php`). An expired, swept, corrupt, or wrong-install token fails closed on the commands that need it.
- **The carrier of run state:** which steps completed (`PREFLIGHT`, `APPLY`), the pre-apply token snapshot, and the touched-token trail that `restore` replays.

Pass the same `run_id` to `preflight`, then to `execute`/`reset`, then to `restore`.

---

## `wp pp operate inspect` — the INSPECT output

`inspect` is the read-only INSPECT step of the operating loop: one call returns the whole operating picture and mints the run token. It never mutates the site (it does write a run-state row, the same as any `inspect` — see the run token above).

```bash
wp pp operate inspect
wp pp operate inspect --post_id=42
```

**Options**

- `--post_id=<id>` — include page-specific composition smells (and a page-level composition integrity check) for this post. Without it, the page-scoped fields (`smells`, `composition_decode_error`) stay at their empty/`null` defaults.

**Output** — the operating picture as pretty JSON. Every top-level field `pp_inspect_site()` returns (`lib/operate.php`), plus the `run_id` the CLI appends (`PP_Operate_Command::inspect`, `lib/cli.php`):

| Field | Shape | What it is |
|---|---|---|
| `target` | `{site_url, wp_root, theme_path, environment}` | The canonical mutation target, auto-resolved from WordPress state (`pp_get_target`). A field is `null` when it can't be resolved. |
| `pages` | array of `{id, title, status, url}` | Every page using the Composition template (`composition.php`), any status, title-sorted (`pp_composition_pages`). |
| `drift` | `{has_drift, modified, added, deleted, release_version}` (`error` added when the theme dir is unreadable) | Theme-file drift vs the deployment manifest (`pp_check_drift`). `release_version` is the installed release the baseline was captured against (#496; `null` on manifests written before that, or no baseline). No manifest baseline ⇒ `has_drift:false` with empty arrays (it never creates one). |
| `preflight` | `{ok, checks[], findings}` | A **site-grain** preflight snapshot computed with no planned files and no post (`pp_preflight`). Advisory situational awareness — the gate that actually unlocks a mutation is `wp pp apply preflight --run-id=…`, not this. `findings` groups the warning-grade rows by class (#496 — see `wp pp readiness` below). |
| `tokens` | map of `--token` ⇒ `{value, type}` | Design tokens parsed from `base.css :root {}`, with the type from each token's structured comment (`pp_design_tokens`). |
| `conflicts` | array of `{selector, component}` | WordPress Custom CSS selectors that target PP component classes (`pp_check_custom_css_conflicts`). Report-only; `[]` when there are none. |
| `smells` | array of `{type, message, index}` | Page composition smells for `--post_id` (`pp_validate_composition_smells`): hero/layout/wall-of-text advisories, `empty_section` (a band whose configured content renders nothing — covers every band component since #579, not just the five structured-content ones), `transparent_fill` (a `role: "fill"` slot set to `transparent`/`currentColor`, i.e. an invisible-but-clickable button; warn-only, `transparent` stays an accepted value), `inert_slot` (a slot whose declared `applies_when` condition is unmet on this component, so the stored value renders nothing — the message names the slot and every unmet clause; warn-only, the write is never rejected and the value is stored as authored), plus `template_owned_component` / `duplicate_component_id` on a page whose stored composition predates those rules. `[]` when no `--post_id` is given, the page's composition is empty, or the page is corrupt (a corrupt page is reported via `composition_decode_error`, not here). |
| `token_smells` | array of `{type, base_token, token, current, expected, message}` | Masked derived-family overrides (#386, `pp_detect_masked_derived_smells`): a derived override (e.g. `--color-accent-strong`) that diverges from what its base (`--color-accent`) currently derives, so a base change won't show where the override applies. Always computed (site-scoped, independent of `--post_id`); `[]` on a coherently themed site. |
| `composition_decode_error` | `null` \| `"decode_error"` \| `"unexpected_shape"` | Page composition **integrity** for `--post_id` (#144). Always present in the output; only ever non-`null` when `--post_id` names a page whose stored `_pp_composition` is corrupt rather than genuinely empty (see below). |
| `run_id` | UUID v4 string | The run token this `inspect` minted, appended by the CLI. Pass it as `--run-id` to every mutating subcommand. |

### `composition_decode_error` in detail (#144)

A page with no composition and a page with a *corrupted* composition both look empty to a naive reader — the same `smells: []`. `composition_decode_error` tells them apart so an agent relying on INSPECT before a mutation is warned about data corruption instead of treating a broken row as a clean, blank page. It is set from the state-classifying decoder `pp_get_composition_result()` (`lib/wp.php`), the single owner of composition decode + classification:

| Value | Meaning | When |
|---|---|---|
| `null` | No integrity problem. | No `--post_id`; or the page's `_pp_composition` is absent/blank (a genuinely empty page); or it decodes to a valid JSON list (a real composition). |
| `"decode_error"` | The stored `_pp_composition` is present but **not decodable JSON** (truncated write, encoding bug, malformed UTF-8). | `--post_id` given and the raw row fails `json_decode`. |
| `"unexpected_shape"` | The stored value **decodes but is not a list** — a JSON object or scalar, a non-string scalar meta, or an already-decoded non-list array. | `--post_id` given and the decoded value isn't a sequential-keyed list. |

When it is non-`null`, `smells` is `[]` (the corrupt row can't be walked for smells) — read the integrity field, not the empty smell list, to decide the page is broken. The rendering paths are unaffected: `pp_get_composition()` still degrades any corrupt or non-list row to `[]`, so templates never fatal on a bad row; only these read/validate surfaces surface the distinction. `wp pp check page`, `wp pp validate site`, and `wp pp validate page` report the same integrity error rather than "no composition."

---

## Subcommand summary

| Subcommand | Mutates? | `--run-id` | Needs prior PREFLIGHT | Purpose |
|---|---|---|---|---|
| `list` | no | no | no | List registered applies |
| `preview` | no | no | no | Validate + show the diff, never writes |
| `preflight` | no (records run state) | **yes** | — | Validate the execution surface; freeze the rollback snapshot |
| `execute` | **yes** | **yes** | yes | Apply a named change |
| `reset` | **yes** | **yes** | yes | Clear overrides back to product defaults |
| `restore` | **yes** | **yes** | yes | Roll this run's token changes back to its preflight snapshot |
| `restore-composition` | **yes** | **yes** | yes | Roll this run's page-composition changes back to their pre-run state (#133) |

> **Tokens vs compositions.** `execute` / `reset` / `restore` operate on **design tokens**. Page **compositions** (the component arrays that make up a page) are mutated through the `wp pp action` family (`update_composition`, `add_component`, `remove_component`, …) and rolled back with `restore-composition` (run-scoped) or the `restore_composition` action (single page). Both surfaces share one run token and the same preflight discipline.

---

## `wp pp apply list`

Lists every registered apply with its domain, target, description, and parameters.

```bash
wp pp apply list
```

Read-only. No run token. Prints a table (`name`, `domain`, `target`, `description`, `params`); a `*` after a param marks it required. Prints `No applies registered.` (warning) if the registry is empty.

The token-domain applies you'll use with this family:

| Apply name | Params | Effect |
|---|---|---|
| `update_design_token` | `token` (required), `value` (required) | Set one design-token override |
| `reset_design_token` | `token` (required) | Clear one token override → product default |
| `reset_all_design_tokens` | — | Clear all token overrides → product defaults |

(The registry also carries font and media applies — `enqueue_font`, `remove_font`, `reset_fonts`, `import_media` — registered in `lib/apply.php`. This page focuses on the token surface that `execute`/`reset`/`restore` operate on. `import_media` takes EITHER a remote `url` OR a server-local absolute `file` — exactly one; the `file` source, #490, lets brand-kit assets that live on the operator machine join the same journalled surface without raw `wp media import`.)

---

## `wp pp apply preview`

Validates an apply and shows the diff it *would* make. **Never writes.** No run token required.

```bash
wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'
```

**Options**

- `<name>` — the apply name (positional, required).
- `--params=<json>` — JSON object of apply parameters.

**Requires** the apply capability (`_pp_cli_require_apply_cap`).

**Output** — the apply result as pretty JSON:

```json
{
  "ok": true,
  "apply": "update_design_token",
  "domain": "design",
  "target": { ... },
  "changes": [ { "token": "--color-accent", ... } ],
  "error": null
}
```

**Exit codes** — `0` on success; on a validation error it prints `{"ok":false,"error":"<message>"}` and exits **1** (`WP_CLI::halt(1)`).

---

## `wp pp apply preflight`

Validates the whole execution surface **before** any mutation and, on success, records the `PREFLIGHT` step plus the pre-apply **token snapshot** that `restore` rolls back to. This is the gate that every mutating subcommand checks.

```bash
wp pp apply preflight --run-id=<uuid>
wp pp apply preflight --run-id=<uuid> --planned-files='["assets/css/base.css"]'
wp pp apply preflight --run-id=<uuid> --apply=update_design_token
wp pp apply preflight --run-id=<uuid> --post_id=42
```

**Options**

- `--run-id=<uuid>` — **required.** Run token from `wp pp operate inspect`.
- `--planned-files=<json>` — JSON array of file paths the agent intends to modify. Enables drift-overlap detection. Without it, drift is a warning only.
- `--post_id=<id>` — target page post ID. Adds the `target_page` check and scopes coverage to that post.
- `--apply=<name>` — named apply. Auto-populates `planned_files` from a file-based apply's target; a media-target apply (`import_media`) enables the `uploads_writable` check (#229). A name that matches no registered apply **fails preflight closed** via the `apply_known` check (issue 245) — a typo is never treated as "no apply planned."

**Coverage grain.** A preflight with `--post_id=N` covers mutations on post N; a preflight with no post covers **site-grain** changes. They don't substitute for each other — a page mutation needs a page preflight, a site mutation needs a site preflight. This is what `execute`/`action execute`/`operate patch` check.

**Composition freshness (#113).** A `--post_id=N` preflight also records the page's composition **freshness marker** — a `{version, hash}` pair bumped on every composition write (`_pp_composition_version` / `_pp_composition_hash`). A composition-mutating `action execute` (`update_composition`, `add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`) or `operate patch` re-reads the live marker and **rejects** if the composition changed since your preflight — coverage proves a preflight *ran* for the target, freshness proves the target is *unchanged since*:

> `Stale preflight for post N: the composition changed since preflight (preflight version X, live version Y). Another path (a CLI action, the dashboard editor, or publish flow) modified it. Re-inspect and re-run 'wp pp apply preflight --run-id=<uuid> --post_id=N' before executing. [composition_conflict]`

Your own run's sequential composition mutations are fine — the baseline refreshes to the new marker after each successful write. Only a change from *another* path (another run, the dashboard editor, publish flow) trips the gate. When it fires, re-inspect the page and re-preflight, then re-issue the action. `preview` never consumes or requires freshness state.

**Write-time compare-and-swap (#13).** The freshness gate above is a pre-check: it can't cover a write that lands in the narrow window *between* the check and the actual write. To close that, `action execute` and `operate patch` also thread the validated baseline into the write itself as an **`expected_version`**, and the single composition-write choke point (`pp_update_composition`) performs an atomic compare-and-swap **under the per-post advisory lock** — it re-reads the version fresh from the DB and, if it no longer equals `expected_version`, rejects with a `composition_conflict` `WP_Error` and writes nothing (neither the composition nor either marker moves). From the CLI the pre-check usually fires first with the `Stale preflight` message above; the CAS is the atomic backstop for an interleaved write that slips past it, and returns:

> `The composition for post N changed since you last read it (expected version X, current version Y). Another writer (a CLI action, the dashboard editor, or the AI chat) modified it. Re-read the current composition and re-apply your change. [composition_conflict]`

`expected_version` is an **optional** param on every composition-mutating action (`update_composition`, `add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`). Omit it and the write proceeds unconditionally (back-compat: new-page creation, the homepage seed, and legacy direct callers all skip the CAS). Supply it — the CLI agent path, the dashboard composition editor, and the AI chat path all do — and a concurrent write is rejected instead of silently clobbered. The **AI chat path supplies it mandatorily and fail-closed** (#404): the chat backend captures the page's `composition_version` when the model reads the page, and both `wp_ajax_pp_ai_execute` and `wp_ajax_pp_ai_execute_batch` REJECT a composition-mutating write with no baseline (`missing_expected_version`) before executing — the batch rejecting the whole batch before any step runs. A batch threads a per-`post_id` baseline map and chains each write's post-write version into the next mutating step on that page, so it never false-conflicts against its own writes. On `composition_conflict` the handler returns the structured envelope (code + `expected_version`/`current_version`) and the chat UI offers **Re-read & re-preview**, never a blind retry. The dashboard editor keys on the same structured `composition_conflict` code to prompt a reload; the version it sends is refreshed from each successful save so a run's own sequential edits never false-conflict.

**Template-owned chrome (#223).** `nav` and `footer` are rendered on every page by `templates/base.php`. They are registered, renderable components, but they are **not composable** — a composition containing either would render the site header or footer twice. `pp_validate_composition()` rejects them, so `create_page`, `update_composition`, `add_component`, `update_component`, and the dashboard editor's save all fail with:

> `"nav" is site chrome rendered by the page template; it cannot be placed in a page composition. Set the site logo via the "pp_logo_id" site option, and the navigation menu via the menu actions (create_menu / assign_menu_location). [template_owned_component]`

The code is distinct from `invalid_composition` so a caller can tell "that name is chrome" apart from "that name doesn't exist." A page whose stored composition already contains chrome (written before this rule, or through a non-action path) is not silently accepted: `wp pp check page` and `wp pp validate site` report a `template_owned_component` composition smell, and `wp pp validate page` fails with a `template_owned_component` error. Remove the offending items with `remove_component` — each removal shifts later indices down, so remove the highest index first.

**Duplicate component IDs (#238).** A component's `props.id` is how `component_id` targeting picks one component out of a composition. Two components sharing the same non-empty `id` would make that targeting ambiguous, so `pp_validate_composition()` rejects the collision at write time — `create_page`, `update_composition`, `add_component`, `update_component`, and the dashboard editor's save all fail with:

> `Duplicate component id "pricing" on items 0, 2. Component ids must be unique within a composition so update/remove/style can target one component. [duplicate_component_id]`

A page whose stored composition already carries a collision (written before this rule, or through a non-action path) is not silently accepted: `wp pp check page` and `wp pp validate site` report a `duplicate_component_id` composition smell. And the resolver is defensive as a backstop — if a duplicate ever reaches a targeting action, `update_component` / `remove_component` / `style_component` fail closed with a `component_ambiguous` error (listing the colliding indexes) rather than silently mutating the first match. Give each component a unique authored `id`.

**Unknown component props (#147).** Each component declares its full prop contract in `components/<name>/schema.json` under `props`. A composition whose component carries a prop key not in that contract is rejected at write time by `pp_validate_composition()`, so `create_page`, `update_composition`, `add_component`, `update_component`, and the dashboard editor's save all fail with:

> `Component "cta" has no prop "not_a_real_prop". Available props: id, title, title_accent, eyebrow, text, button_text, button_url, button2_text, button2_url, button2_variant, layout, theme, background_image, button_variant [unknown_prop]`

The source of truth is the component's full schema `props`, not the narrower schema-derived scalar-patch set (`pp_get_component_fields()`, which exposes only scalar props for `operate patch`), so real props like `cta.theme` and `cta.background_image` are accepted while a misspelled or invented key is not — closing the "phantom field" hole where an unknown key would persist behind an `ok:true` while the renderer silently ignored it. Unlike template-owned chrome and duplicate ids, this rule has no composition-smell counterpart: a stored composition that already carries an unknown prop (from a legacy write or a raw non-action path) is surfaced by `restore_composition`, which never blocks undo and instead reports the `unknown_prop` finding (#233), rather than by `wp pp check page`.

**Schema-typed prop values (#507).** Beyond the *key* being known, each prop's *value* is checked against its declared schema `type`, so an accepted write renders as authored instead of the renderer emitting `Array` (with a PHP warning) behind an `ok:true`. A prop declared `type: "string"` rejects a non-scalar (array/object) value; `type: "number"` rejects a non-numeric; `type: "array"` rejects a scalar; and an object-item array (one declaring `item_type: "object"`, e.g. `grid.items`) rejects any entry that is not an object. Each check has a per-type "unset" sentinel (`null`, `""`, and — for arrays — `[]`) that preserves the prop's default, so an omitted value is never a rejection. The rule is generic and schema-driven: a new prop is enforced the moment its schema declares a `type`, with no per-component code. It layers under the bounded families (numeric `min`/`max` #379, strict enum #380/#579, string-array bounds #475, array-item arrays #579), which keep their own precise messages for the props that declare them. Rejections carry `invalid_prop_value`:

> `Component "cta" prop "title" must be a string; got array. [invalid_prop_value]`

An array declaring `item_type: "array"` (today `table.rows`) rejects any entry that is not itself an array, so a scalar row can no longer be cast by the renderer into a silent one-cell row.

**Strict enums (#380, universal for top-level props since #579).** Every *top-level* enum prop declares `"strict": true`, so a value outside the prop's declared `values` is rejected at write instead of being accepted and coerced to the default at render. Nested `items[]` enums (today only `grid.items[].text_role`) are not covered and remain accept-and-coerce. Nothing rendered changed when this became universal — the renderer already coerced — but a write that reported `ok:true` and produced the default no longer happens:

> `Component "cta" prop "button2_variant" must be one of: primary, secondary, outline, ghost; got "neon". [invalid_prop_value]`

A prop may also declare `aliases`: legacy values **accepted at write and never advertised** in `values`. **No shipped prop declares any** — the last one, `theme`'s legacy `dark`, was removed in #605, so every advertised value set is now the whole accepted set. A `theme: "dark"` write is rejected like any other unadvertised value, and a page that still stores it renders the `default` band. The `unset` sentinel (key absent, `null`, `""`) always preserves the prop's default.

**Nested item-field contracts (#579).** A `required: true` declared on an `items[]` field is enforced, not decoration — `logos.items[].image_url` / `image_alt`, `stats.items[].number` / `label`, `testimonials.items[].quote`, `faq.items[].question` / `answer`. The case it closes: a logos entry carrying a `label` and no `image_url` used to validate, persist, return `ok:true` and render nothing at all:

> `Component "logos" prop "items" item 1 is missing required field "image_url". [invalid_composition]`

A nested array field declaring `item_type: "string"` (`grid.items[].bullets`) likewise type-checks its entries. As at the top level, an absent key is the violation; a present-but-empty value is not.

**Link-URL format (#507).** A prop that declares `format: "link_url"` (today `cta.button_url` / `cta.button2_url`, `hero.button_url` / `button2_url`, `section.panel_cta_url`, and `grid.items[].link_url`) is validated so the write cannot report `ok:true` for a value that `esc_url()` would silently neuter into an empty `href` — a dead button. The bar is "what survives `esc_url()` renders as authored": a site-relative path (`/pricing`), an anchor (`#booking`), a protocol-relative URL (`//cdn.example.com/x`), `mailto:`, `tel:`, and any other `wp_allowed_protocols()` scheme are accepted; a value carrying a disallowed protocol (`javascript:`, `data:`, `vbscript:`, ...) is rejected:

> `Component "cta" prop "button_url" is not a usable link URL: "javascript:alert(1)" uses a disallowed protocol and would render as a dead link. Use an absolute URL (https://...), a site-relative path (/path), an anchor (#id), mailto:, or tel:. [invalid_prop_value]`

Like every rule here, both checks run in the shared `pp_validate_composition()` and are reported (never blocked) by `restore_composition` (#233).

**Output** — the preflight result:

```json
{
  "ok": true,
  "checks": [
    { "check": "target", "pass": true, "message": "Target resolved: https://example.com" },
    { "check": "capability", "pass": true, "message": "WP-CLI context: capability gate bypassed." },
    { "check": "drift", "pass": true, "message": "No drift detected." },
    { "check": "theme_writable", "pass": true, "message": "Skipped: planned applies are database-backed (no filesystem writes)." },
    { "check": "screenshot_readiness", "pass": true, "severity": "warning", "message": "..." }
  ]
}
```

The checks that can run (`pp_preflight`, `lib/operate.php`):

| Check | When | Blocks? |
|---|---|---|
| `target` | always | yes |
| `capability` | always | yes |
| `apply_known` | `--apply=<name>` given and unregistered | yes |
| `drift` | always | only if drifted files overlap `planned_files` |
| `theme_writable` | file-targeting applies only | yes (skipped for DB-backed token applies and for media applies, whose writes are covered by `uploads_writable`) |
| `uploads_writable` | media-target applies only (`--apply=import_media`, #229) | yes |
| `target_page` | `--post_id` given | yes |
| `surface` | `planned_files` given | yes if a `core` file is planned |
| `nav_readiness` | always (site chrome is not page-scoped, #223) | no (`severity: warning`) |
| `screenshot_readiness` | always | no (`severity: warning`) |

`ok` is `true` only when no **error-grade** check failed. Rows with `severity: warning` (nav readiness, screenshot readiness, non-overlapping drift) surface a problem without blocking.

**Screenshot readiness tri-state (#497).** The `screenshot_readiness` row carries a `state`:
`available` (healthy, no finding), `unavailable` (`PP_BROWSER_CMD` not configured), or
`broken` (configured but the binary is missing from `$PATH`). `unavailable` and `broken`
are distinct capability-class findings, each with `next_action: wp pp screenshot doctor` —
never a single ambient warning. Preflight does the cheap **non-exec** check only (it never
launches a browser); run `wp pp screenshot doctor` to capture-verify `available` and to turn
a probe-time failure into `broken`. See `docs/screenshot-setup.md`.

**Uploads writability (#229).** `import_media` sideloads a file into `wp-content/uploads/YYYY/MM/`, so a preflight with `--apply=import_media` runs `uploads_writable` instead of asserting "no filesystem writes." The check mirrors execute-time `wp_mkdir_p()` semantics: it walks from the dated path to the **deepest existing ancestor** and requires it to be a writable directory — so a fresh site whose `uploads/` doesn't exist yet passes (WordPress creates it), while an unwritable intermediate directory (`uploads/2026` rsync'd `0555`) or a regular file occupying a path segment fails closed even when `uploads/` itself is writable. The `theme_writable` row still passes for media applies (the theme directory is untouched) with a message pointing at `uploads_writable`.

**Unknown apply name (issue 245).** The `--apply` flag exists so preflight can verify the named apply's preconditions, so a name that matches no registered apply is a hard error, not a no-op. Without the guard, `--apply=import_medai` (a typo) would resolve to "no apply planned," skip the apply-routed filesystem checks, pass clean, and record a `PREFLIGHT` state asserting "no filesystem writes" the operator never earned — the same false-pass class as #227/#229, one level up. Any **provided** non-empty apply value is validated (including the falsy literal `--apply=0`, which is never a registered name); an empty `--apply=` is treated as "no apply planned," same as omitting the flag. The `apply_known` check is error-grade, so an unknown name makes `ok` false and no `PREFLIGHT` is recorded:

```json
{ "check": "apply_known", "pass": false, "message": "Unknown apply: import_medai. Preflight cannot verify preconditions for an unregistered apply; check the name against the apply registry." }
```

**Exit codes and errors**

**stdout is the machine-readable channel.** Parse stdout, not combined output — WP-CLI's human-readable `Error:` lines go to stderr. The success JSON is emitted **only after** every recording step has succeeded (#227): `ok: true` means the preflight completed *including* recording its state. If any recording step fails after the checks passed, stdout carries `{"ok": false, "error": "<message>", "checks": [...]}` and the command exits **1** — a preflight that could not record itself never reports success.

- If any error-grade check fails: prints the JSON (`ok: false`), then exits **1** (`WP_CLI::halt(1)`). Nothing is recorded.
- **Unreadable baseline (#200 lock contention, #207 corrupt row, #212 read failure):** the snapshot is read under the token advisory lock for an atomic baseline. It comes back `null` — and preflight **records nothing** and errors — in three cases: the lock is contended (another writer is racing, #200), the stored `pp_token_overrides` row is corrupt/truncated/hand-edited into a non-array (#207), or the option read itself fails at the database (a query error on the `SELECT`, detected via a non-empty `$wpdb->last_error`, #212). Any of the three:
  > `Could not read an atomic pre-apply token baseline for run token "<uuid>": the token lock is contended, or the pp_token_overrides row is corrupt/unreadable. PREFLIGHT was not recorded. Re-run 'wp pp apply preflight' once the contention clears; if it persists, inspect and repair the pp_token_overrides option.`

  This is deliberate fail-closed behavior. Recording a stale baseline (contention) would let a later `restore` revert to a state that never existed; recording an empty `[]` baseline for a corrupt row or a failed read is worse — `restore` reverts every touched token off an empty snapshot by **deleting** it, so a row silently coerced to `[]` would turn a restore into token loss. A database read failure is distinguished from a genuinely absent row (which is a valid empty `[]` baseline) by `$wpdb->last_error`: a failed read fails closed as `null`, an absent row records `[]` normally. Retry once contention clears; if the error persists, the row itself is unreadable or the database is erroring — inspect and repair `pp_token_overrides`.
- If the run state can't be recorded, the failure is reported with the precise cause (#409) rather than the old ambiguous "missing or expired" — stdout gets the `{"ok": false, "error": ...}` payload and stderr carries the matching message:
  - **Not found** (the run token was never minted on this install, or was cleaned up — most often `inspect` and this command ran in different environments, e.g. separate ephemeral CLI containers): `No run state found for run token "<uuid>". ... Re-run 'wp pp operate inspect' here to start a fresh run.`
  - **Expired** (older than the 2-hour TTL): `Run token "<uuid>" has expired (older than the 2-hour run TTL). Re-run 'wp pp operate inspect' to start a fresh run.`
  - **Foreign** (the token belongs to a different site/install): `Run token "<uuid>" belongs to a different site or install and cannot be used here. ...`
  - **Corrupt** (the stored state is unreadable): `Run state for run token "<uuid>" is unreadable (corrupt). ...`
  - **Write failed** (the run exists but the options-table write did not land): `Could not persist PREFLIGHT state for run token "<uuid>": the run exists but the options-table write did not complete ... Retry 'wp pp apply preflight'.`
- **Corrupt page composition (#241):** for a `--post_id=N` preflight the pre-run composition content snapshot (#133, the baseline `restore-composition` reverts to) is read with the same fail-closed decoder as the renderer (`pp_get_composition_result`). If the stored `_pp_composition` row is corrupt/undecodable or the wrong shape, preflight **records nothing** and errors — recording an empty `[]` baseline would let a later `restore-composition` **blank the page** (the composition analogue of the token-loss hazard above). A genuinely empty page still records a valid `[]` baseline; only a corrupt row fails closed. Note (#604): the decoder resolves no key names at all, so the recorded baseline is the pre-run composition's **literal stored bytes** — a restore returns exactly what was there, retired key names included. (Until #604 the baseline was a canonicalized view, with `variant` and legacy prop keys already migrated; that rewriting is gone, so baseline and storage can no longer disagree.)
  > `Could not read a valid pre-apply composition baseline for run token "<uuid>" (post N): the stored composition is <error>. PREFLIGHT was not recorded, so both the action gate and the restore baseline stay fail-closed. Repair the post's composition before re-running 'wp pp apply preflight'.`
- **Atomic unlock + restore baseline (#241):** for a page-scoped preflight the PREFLIGHT coverage (what unlocks mutating gates), the freshness marker, the token snapshot, and the pre-run composition content snapshot are all committed in **one** state write. The gate can never unlock without its restore baseline, and a preflight re-run never freezes a post-mutation baseline (the content snapshot is first-write-wins). Any `ok: false` still means "preflight did not complete" — re-run `wp pp apply preflight` before mutating; the re-run is safe.

An empty override set is a **valid** baseline: on a fresh install with no overrides the snapshot is `[]` (recorded normally), never `null`. `null` means "could not read an atomic baseline" — lock contention, an unreadable/corrupt row, or a database read failure, never a legitimately empty one.

---

## `wp pp apply execute`

Applies a named change. Validates first, then writes, then records the touched tokens so `restore` can revert exactly this run's footprint.

```bash
wp pp apply execute update_design_token --run-id=<uuid> --params='{"token":"--color-accent","value":"#b45309"}'
```

**Options**

- `<name>` — the apply name (positional, required).
- `--params=<json>` — JSON object of apply parameters.
- `--run-id=<uuid>` — **required.**

**Gates, in order** (each halts with an actionable error if unmet):

1. Valid run token (`_pp_cli_require_run_id`).
2. A completed `PREFLIGHT` step: `Run token "<uuid>" has no completed PREFLIGHT step. Run 'wp pp apply preflight --run-id=<uuid>' first.`
3. A usable rollback snapshot (`pp_operate_run_rollbackable`): `Refusing to apply: run "<uuid>" has no usable rollback snapshot, so this change could not be undone. Re-run 'wp pp operate inspect' and 'wp pp apply preflight'.`
4. The apply capability.

**Output** — the apply result JSON (includes `before`/`after` for token applies plus `changes[]`), then `Success: Apply "<name>" executed.`

**Exit codes and errors**

- `0` on success.
- If the apply itself fails (`result.ok == false`): prints the JSON, exits **1**.
- If the mutation persisted but the touched-token trail could **not** be recorded, it errors loudly rather than reporting clean success:
  > `Apply "<name>" persisted, but recording its touched tokens for run "<uuid>" FAILED. 'wp pp apply restore' may not be able to revert this change. Run state may be missing or corrupt; re-run 'wp pp operate inspect' before making further changes.`

  Token mutation and touched-key recording write to separate options rows (the `pp_token_overrides` option and the per-run run-state option) and can't be one transaction, so this failure is surfaced at the exact point it happens.

On success the run advances to the `APPLY` step.

---

## `wp pp apply reset`

Resets design tokens to **product defaults** by clearing overrides. This is **not** a per-run rollback — use `restore` for that. Reset records its touched tokens the same way `execute` does, so a reset within a run is itself restorable.

```bash
wp pp apply reset --run-id=<uuid> --token=--color-accent
wp pp apply reset --run-id=<uuid>
```

**Options**

- `--run-id=<uuid>` — **required.**
- `--token=<name>` — reset a single token. Omit to reset all (runs the `reset_all_design_tokens` apply, the most destructive in the registry).

**Gates** — identical to `execute`: valid run token → completed `PREFLIGHT` → rollbackable → capability.

**Output** — the apply result JSON, then `Success: Reset <n> token(s) to product defaults.` (or `No overrides to reset.`).

**Exit codes and errors** — `0` on success; exits **1** if the reset apply fails; the same loud touched-token recording error as `execute` if the trail can't be recorded. Advances the run to `APPLY`.

---

## `wp pp apply restore`

Rolls **this run's** token changes back to the snapshot frozen at its preflight. Tokens the run wrote (primary + auto-derived) revert to their pre-run values; tokens the run *created* are removed; tokens the run never touched are left alone, so unrelated overrides (including later runs' work) survive. It never falls back to a product-default reset and never partially mutates.

```bash
wp pp apply restore --run-id=<uuid>
wp pp apply restore --run-id=<uuid> --token=--color-accent
```

**Options**

- `--run-id=<uuid>` — **required.**
- `--token=<name>` — restore a single token and its derived family from the snapshot, intersected with what the run actually touched. Omit to restore everything the run touched.

**Gates** — valid run token → completed `PREFLIGHT` → capability.

**Fail-closed reads.** Both the frozen snapshot and the touched-key list must be usable for **this** install. `null` from either (missing / expired / corrupt / swept / identity mismatch) stops the restore with no change:

> `Run "<uuid>" has no usable pre-apply snapshot; cannot roll back. The run state may be missing, expired, corrupt, or from a different site. Nothing was changed.`

**Output** — one of:

- `Success: Restored <n> token(s) to the pre-run snapshot.`
- `Success: Tokens already matched the pre-run snapshot; nothing to restore.`
- `Success: Token "<name>" was not changed by run "<uuid>"; nothing to restore.` (with `--token` outside the run's footprint)
- `Success: Run "<uuid>" changed no tokens; nothing to restore.`

**Errors** — the fail-closed message above, or, if the atomic revert can't proceed:
> `Could not roll back run "<uuid>": the token lock was unavailable or the snapshot held invalid values. Nothing was changed.`

`restore` writes through `pp_revert_tokens`, which validates every scoped snapshot value against the live token registry **before** any write and aborts the whole revert on a single invalid entry — never a partial restore.

---

## Restore vs reset at a glance

| | `restore` | `reset` |
|---|---|---|
| Goes back to | this run's pre-apply snapshot | product defaults (base.css) |
| Scope | only tokens this run touched | one token, or all |
| Preserves unrelated overrides | yes | no |
| Undoes another run's work | no | (clears everything) |
| Reversible afterward | it *is* the reversal | yes (records touched tokens) |

---

## Composition history & restore (#133)

Design-token writes have always been reversible (snapshot at preflight, `restore`). Composition writes — the page content itself — now match that parity. Every composition write (`update_composition`, `add_component`, `remove_component`, `reorder_components`, `update_component`, and `restore_composition` itself) pushes the **prior** composition onto a bounded per-post history ring (last 10 entries) before overwriting. Restore reads that ring.

There are two restore surfaces, both conflict-checked writes that land their own history entry (so a restore is itself reversible):

### `restore_composition` action (single page)

Registered in the `wp pp action` family. Rewrites one page's composition to a prior history entry.

```bash
# Preview the diff (read-only, no run token)
wp pp action preview restore_composition --params='{"post_id":42,"steps_back":1}'

# Execute (needs a run token + PREFLIGHT covering post 42, like any composition mutation)
wp pp action execute restore_composition --run-id=<uuid> --params='{"post_id":42,"steps_back":1}'
```

Target selectors (params):

- `steps_back` (int, default `1`) — `1` = the most recent prior state (the last write's before-image), `2` = the one before it, … up to the number of retained entries.
- `history_index` (int) — absolute 0-based index into the ring (oldest = 0). Takes precedence over `steps_back`.
- `expected_version` (int, optional) — optimistic-locking baseline (#13); the restore is rejected with `composition_conflict` if the page moved since.

Errors: `no_history` (the page has no recorded prior state), `history_out_of_bounds` (selector past the ring), `composition_conflict` (stale `expected_version`).

#### Restore reports, it does not block (#233)

A snapshot captured before a validation rule existed still restores. Current rules never veto a restore — undo is wired to this action, so a restore that today's rules refuse would make undo fail exactly when you most need it. Instead the restore succeeds and tells you what is wrong with what it just wrote.

Two things follow from that, and both are visible in the result:

**The snapshot is restored verbatim (#604).** It passes through `pp_normalize_composition()`, which now only strips empty `style` arrays — it no longer rewrites `type` → `component` or the pre-#69 `variant` → `layout`/`theme`, and the legacy prop-key alias map it used to apply is gone. No component is added, removed, or reordered, and no key is renamed. Chrome (`nav`/`footer`), retired slot names, retired prop names and a stored `variant` are all preserved exactly as snapshotted and reported in `findings` below — **content is never stripped from history**, and it is never silently rewritten either.

**The result carries a `findings` array**, on both `preview` and `execute`. It is `[]` when the snapshot is clean under current rules:

```jsonc
{
  "ok": true,
  "action": "restore_composition",
  "findings": [
    {
      "type": "template_owned_component",
      "severity": "error",
      "message": "\"nav\" is site chrome rendered by the page template …",
      "index": null
    },
    {
      "type": "template_owned_component",
      "severity": "warning",
      "message": "\"nav\" at index 0 is site chrome … Remove it with the remove_component action.",
      "index": 0
    }
  ]
}
```

- `severity: "error"` — a rule that would reject this composition on a normal write. Produced by `pp_validate_composition_errors()`, which reports **every** violation, not just the first. `index` is `null`; the message names the offending item.
- `severity: "warning"` — advisory. Produced by `pp_validate_composition_smells()`. `index` is the composition offset.

A single problem can surface as both, from the two different engines. That is not duplication to filter out: the error tells you a subsequent normal write will be rejected, the warning tells you what the rendered page does wrong and how to fix it.

`preview` computes the identical findings and writes nothing, so an agent sees the required remediation before executing rather than discovering it in the next validator run. Its `after` field is the normalized composition — what `execute` would actually persist.

`restore_composition` is the only action that returns `findings`. The canonical result keys are a minimum, not an exhaustive set.

### `wp pp apply restore-composition` (run-scoped)

The composition counterpart of `wp pp apply restore`. Reverts **every page the run changed** back to the content frozen at the run's PREFLIGHT. Scoped strictly to this run's touched posts — a page a different run mutated is never touched.

```bash
wp pp apply restore-composition --run-id=<uuid>
```

Fail-closed: if the run's touched-post record is missing / expired / corrupt / from another install, nothing is changed and it errors (exit **1**). Per-post snapshot-missing or write failures are reported under `skipped` in the JSON output while the reverts that can proceed still proceed.

**Exit codes** — `0` only when **every** touched post was reverted (a full restore). When any post lands in `skipped`, the JSON report is still printed (so you can see which posts were restored vs skipped) but the command **errors and exits 1**: a partial restore is incomplete, not successful, so a machine consumer branching on the exit code never reads it as a full one.

When the restore is complete (empty `skipped`):

- `Success: Reverted N composition(s) to the pre-run state (of M touched).`
- `Success: Touched compositions already matched the pre-run state; nothing to revert.`

When the restore is incomplete (non-empty `skipped`):

- `Error: Restore INCOMPLETE: reverted N of M touched post(s); K could not be reverted (missing snapshot or write failure). See the report above for which posts were restored vs skipped.` (exit 1)

**Each reverted post carries a `findings` array (#236).** Like the `restore_composition` action, the run-scoped restore never blocks a rollback just because a rule that landed *after* the snapshot would reject it — but it must not report a clean success when a restored composition violates current rules. Every entry under `reverted` therefore includes `findings` (`[]` when the restored composition is clean under current rules; otherwise the shared `pp_validate_composition_*` errors and smells). When any reverted post reports findings, the command prints a `Warning:` naming how many, alongside the JSON report. The findings are advisory: the revert still succeeds and the exit code is unaffected (a partial restore still exits 1 per the completeness rule above; findings alone never change the exit code). Skipped posts were never rewritten and carry no `findings` key.

### `wp pp operate composition-history <page>` (read-only)

Lists a page's history ring so you know which `steps_back` / `history_index` to restore. Needs no run token.

```bash
wp pp operate composition-history 42
wp pp operate composition-history about-us
```

Output carries `count`, the ring `max` (10), and per-entry `{history_index, steps_back, version, timestamp, components}`, newest first.

### In the AI chat

After a proposal that changes a page's composition applies, the chat renders an **"Undo these changes"** link (parity with the token "Reset to default" link). It calls `restore_composition` with `steps_back` equal to the number of composition mutations in the proposal, walking the ring back to the state before the proposal. It appears only when the proposal's composition mutations all target a single page.

---

## `wp pp readiness` — classified findings (#496)

Readiness/preflight warnings carry a **class** and a sanctioned **next action**, so an operator can group them and always know what to do — resolve, re-baseline, or acknowledge. This command family is the operator surface for that model. The same classified block appears as `findings` in `wp pp operate inspect` and `wp pp apply preflight` output.

**Classes:**

| Class | Meaning | Sanctioned resolution |
|---|---|---|
| `integrity` | Theme file drift vs the recorded release baseline | `wp pp readiness rebaseline` |
| `configuration` | Site-state gap resolvable through a safe surface (e.g. an unassigned menu location) | Fix through the surface (e.g. `set_menu`), **or** acknowledge as intentional |
| `capability` | An environment tool is missing or misconfigured (e.g. a screenshot browser, #497 — the finding's `state` is `unavailable` or `broken`) | Run the finding's next action (e.g. `wp pp screenshot doctor`) |

Only **findings** carry a class; passing/healthy rows and hard preconditions do not. Only **configuration** findings are acknowledgeable.

### `wp pp readiness status` (read-only)

Prints current findings grouped by class, each with its `next_action`, plus `active_warnings` and `acknowledged` counts. Never mutates.

```bash
wp pp readiness status
```

### `wp pp readiness rebaseline`

Re-snapshots the deployment manifest against the currently-installed theme files and records the installed release version. The sanctioned reconciliation for integrity drift: afterward drift always means "changed since this release", never "stale baseline". (`wp pp sync check --save-manifest` also records the version.)

```bash
wp pp readiness rebaseline
```

### `wp pp readiness acknowledge <finding-key> [--note=<text>]`

Records a configuration finding as intentional (e.g. a deliberately menu-less footer). It then reports as `acknowledged` instead of an active warning, and drops out of the post-apply warning list. Rejects any key that is not a currently-present configuration finding (run `status` to see valid keys).

```bash
wp pp readiness acknowledge nav_readiness:footer:no_menu --note="footer is deliberately menu-less"
```

### `wp pp readiness unacknowledge <finding-key>`

Reverses an acknowledgement. If the underlying condition still holds, the finding returns as an active warning.

```bash
wp pp readiness unacknowledge nav_readiness:footer:no_menu
```

---

## Related

- 🔒 Why a mutation can't land before the gate: [operating-loop-safety.md](operating-loop-safety.md) (explanation)
- 🧭 The safe apply→rollback walkthrough: [howto-apply-and-rollback.md](howto-apply-and-rollback.md) (how-to)
- 🤖 Letting an AI agent run your site: [running-an-ai-agent.md](running-an-ai-agent.md)
- 🔁 The full operating contract and command reference: [`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
