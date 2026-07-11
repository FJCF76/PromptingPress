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

(The registry also carries font and media applies — `enqueue_font`, `remove_font`, `reset_fonts`, `import_media` — registered in `lib/apply.php`. This page focuses on the token surface that `execute`/`reset`/`restore` operate on.)

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
- `--apply=<name>` — named apply. Auto-populates `planned_files` from a file-based apply's target.

**Coverage grain.** A preflight with `--post_id=N` covers mutations on post N; a preflight with no post covers **site-grain** changes. They don't substitute for each other — a page mutation needs a page preflight, a site mutation needs a site preflight. This is what `execute`/`action execute`/`operate patch` check.

**Composition freshness (#113).** A `--post_id=N` preflight also records the page's composition **freshness marker** — a `{version, hash}` pair bumped on every composition write (`_pp_composition_version` / `_pp_composition_hash`). A composition-mutating `action execute` (`update_composition`, `add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`) or `operate patch` re-reads the live marker and **rejects** if the composition changed since your preflight — coverage proves a preflight *ran* for the target, freshness proves the target is *unchanged since*:

> `Stale preflight for post N: the composition changed since preflight (preflight version X, live version Y). Another path (a CLI action, the dashboard editor, or publish flow) modified it. Re-inspect and re-run 'wp pp apply preflight --run-id=<uuid> --post_id=N' before executing. [composition_conflict]`

Your own run's sequential composition mutations are fine — the baseline refreshes to the new marker after each successful write. Only a change from *another* path (another run, the dashboard editor, publish flow) trips the gate. When it fires, re-inspect the page and re-preflight, then re-issue the action. `preview` never consumes or requires freshness state.

**Write-time compare-and-swap (#13).** The freshness gate above is a pre-check: it can't cover a write that lands in the narrow window *between* the check and the actual write. To close that, `action execute` and `operate patch` also thread the validated baseline into the write itself as an **`expected_version`**, and the single composition-write choke point (`pp_update_composition`) performs an atomic compare-and-swap **under the per-post advisory lock** — it re-reads the version fresh from the DB and, if it no longer equals `expected_version`, rejects with a `composition_conflict` `WP_Error` and writes nothing (neither the composition nor either marker moves). From the CLI the pre-check usually fires first with the `Stale preflight` message above; the CAS is the atomic backstop for an interleaved write that slips past it, and returns:

> `The composition for post N changed since you last read it (expected version X, current version Y). Another writer (a CLI action, the dashboard editor, or the AI chat) modified it. Re-read the current composition and re-apply your change. [composition_conflict]`

`expected_version` is an **optional** param on every composition-mutating action (`update_composition`, `add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`). Omit it and the write proceeds unconditionally (back-compat: new-page creation, the homepage seed, and legacy direct callers all skip the CAS). Supply it — the CLI agent path, the AI chat, and the dashboard composition editor all do — and a concurrent write is rejected instead of silently clobbered. The dashboard editor keys on the structured `composition_conflict` code to prompt a reload; the version it sends is refreshed from each successful save so a run's own sequential edits never false-conflict.

**Template-owned chrome (#223).** `nav` and `footer` are rendered on every page by `templates/base.php`. They are registered, renderable components, but they are **not composable** — a composition containing either would render the site header or footer twice. `pp_validate_composition()` rejects them, so `create_page`, `update_composition`, `add_component`, `update_component`, and the dashboard editor's save all fail with:

> `"nav" is site chrome rendered by the page template; it cannot be placed in a page composition. Set the site logo via the "pp_logo_id" site option, and the navigation menu via the menu actions (create_menu / assign_menu_location). [template_owned_component]`

The code is distinct from `invalid_composition` so a caller can tell "that name is chrome" apart from "that name doesn't exist." A page whose stored composition already contains chrome (written before this rule, or through a non-action path) is not silently accepted: `wp pp check page` and `wp pp validate site` report a `template_owned_component` composition smell, and `wp pp validate page` fails with a `template_owned_component` error. Remove the offending items with `remove_component` — each removal shifts later indices down, so remove the highest index first.

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
| `drift` | always | only if drifted files overlap `planned_files` |
| `theme_writable` | file-targeting applies only | yes (skipped for DB-backed token applies) |
| `target_page` | `--post_id` given | yes |
| `surface` | `planned_files` given | yes if a `core` file is planned |
| `nav_readiness` | always (site chrome is not page-scoped, #223) | no (`severity: warning`) |
| `screenshot_readiness` | always | no (`severity: warning`) |

`ok` is `true` only when no **error-grade** check failed. Rows with `severity: warning` (nav readiness, screenshot readiness, non-overlapping drift) surface a problem without blocking.

**Exit codes and errors**

**stdout is the machine-readable channel.** Parse stdout, not combined output — WP-CLI's human-readable `Error:` lines go to stderr. The success JSON is emitted **only after** every recording step has succeeded (#227): `ok: true` means the preflight completed *including* recording its state. If any recording step fails after the checks passed, stdout carries `{"ok": false, "error": "<message>", "checks": [...]}` and the command exits **1** — a preflight that could not record itself never reports success.

- If any error-grade check fails: prints the JSON (`ok: false`), then exits **1** (`WP_CLI::halt(1)`). Nothing is recorded.
- **Unreadable baseline (#200 lock contention, #207 corrupt row, #212 read failure):** the snapshot is read under the token advisory lock for an atomic baseline. It comes back `null` — and preflight **records nothing** and errors — in three cases: the lock is contended (another writer is racing, #200), the stored `pp_token_overrides` row is corrupt/truncated/hand-edited into a non-array (#207), or the option read itself fails at the database (a query error on the `SELECT`, detected via a non-empty `$wpdb->last_error`, #212). Any of the three:
  > `Could not read an atomic pre-apply token baseline for run token "<uuid>": the token lock is contended, or the pp_token_overrides row is corrupt/unreadable. PREFLIGHT was not recorded. Re-run 'wp pp apply preflight' once the contention clears; if it persists, inspect and repair the pp_token_overrides option.`

  This is deliberate fail-closed behavior. Recording a stale baseline (contention) would let a later `restore` revert to a state that never existed; recording an empty `[]` baseline for a corrupt row or a failed read is worse — `restore` reverts every touched token off an empty snapshot by **deleting** it, so a row silently coerced to `[]` would turn a restore into token loss. A database read failure is distinguished from a genuinely absent row (which is a valid empty `[]` baseline) by `$wpdb->last_error`: a failed read fails closed as `null`, an absent row records `[]` normally. Retry once contention clears; if the error persists, the row itself is unreadable or the database is erroring — inspect and repair `pp_token_overrides`.
- If the run state can't be written (including a run token that was never minted by `wp pp operate inspect`): stdout gets the `{"ok": false, "error": ...}` payload and stderr says `Could not record PREFLIGHT state for run token "<uuid>". State file may be missing or expired. Re-run 'wp pp operate inspect'.`
- **Page-scoped edge case:** if the pre-run composition snapshot (#133) fails *after* the PREFLIGHT step itself was recorded, the command reports `ok: false` while the run's coverage is already recorded. Treat any `ok: false` as "preflight did not complete" and re-run `wp pp apply preflight` before mutating — the recorded baselines are first-write-wins, so a re-run is safe.

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

  Token mutation (DB) and touched-key recording (run-state file) are separate stores and can't be one transaction, so this failure is surfaced at the exact point it happens.

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

**Legacy shape is canonicalized on the way in.** The snapshot passes through `pp_normalize_composition()`, which rewrites `type` → `component` and the pre-#69 `variant` → `layout`/`theme`. This is decoding, not a rewrite of intent: no component is added, removed, or reordered. Nothing else about the snapshot is touched. Chrome (`nav`/`footer`) and every other rule violation are preserved verbatim — **content is never stripped from history**.

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

Fail-closed: if the run's touched-post record is missing / expired / corrupt / from another install, nothing is changed. Per-post snapshot-missing or write failures are reported under `skipped` in the JSON output while the rest proceed. On success:

- `Success: Reverted N composition(s) to the pre-run state (of M touched).`
- `Success: Touched compositions already matched the pre-run state; nothing to revert.`

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

## Related

- 🔒 Why a mutation can't land before the gate: [operating-loop-safety.md](operating-loop-safety.md) (explanation)
- 🧭 The safe apply→rollback walkthrough: [howto-apply-and-rollback.md](howto-apply-and-rollback.md) (how-to)
- 🤖 Letting an AI agent run your site: [running-an-ai-agent.md](running-an-ai-agent.md)
- 🔁 The full operating contract and command reference: [`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
