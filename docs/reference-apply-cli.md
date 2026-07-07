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
| `restore` | **yes** | **yes** | yes | Roll this run's changes back to its preflight snapshot |

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

Your own run's sequential composition mutations are fine — the baseline refreshes to the new marker after each successful write. Only a change from *another* path (another run, the dashboard editor, publish flow) trips the gate. When it fires, re-inspect the page and re-preflight, then re-issue the action. `preview` never consumes or requires freshness state. (Write-time compare-and-swap — rejecting a concurrent write that lands between this check and the write itself — is tracked separately as #13.)

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
| `nav_readiness` | `--post_id` given | no (`severity: warning`) |
| `screenshot_readiness` | always | no (`severity: warning`) |

`ok` is `true` only when no **error-grade** check failed. Rows with `severity: warning` (nav readiness, screenshot readiness, non-overlapping drift) surface a problem without blocking.

**Exit codes and errors**

- If any error-grade check fails: prints the JSON, then exits **1** (`WP_CLI::halt(1)`). Nothing is recorded.
- **Unreadable baseline (#200 lock contention, #207 corrupt row, #212 read failure):** the snapshot is read under the token advisory lock for an atomic baseline. It comes back `null` — and preflight **records nothing** and errors — in three cases: the lock is contended (another writer is racing, #200), the stored `pp_token_overrides` row is corrupt/truncated/hand-edited into a non-array (#207), or the option read itself fails at the database (a query error on the `SELECT`, detected via a non-empty `$wpdb->last_error`, #212). Any of the three:
  > `Could not read an atomic pre-apply token baseline for run token "<uuid>": the token lock is contended, or the pp_token_overrides row is corrupt/unreadable. PREFLIGHT was not recorded. Re-run 'wp pp apply preflight' once the contention clears; if it persists, inspect and repair the pp_token_overrides option.`

  This is deliberate fail-closed behavior. Recording a stale baseline (contention) would let a later `restore` revert to a state that never existed; recording an empty `[]` baseline for a corrupt row or a failed read is worse — `restore` reverts every touched token off an empty snapshot by **deleting** it, so a row silently coerced to `[]` would turn a restore into token loss. A database read failure is distinguished from a genuinely absent row (which is a valid empty `[]` baseline) by `$wpdb->last_error`: a failed read fails closed as `null`, an absent row records `[]` normally. Retry once contention clears; if the error persists, the row itself is unreadable or the database is erroring — inspect and repair `pp_token_overrides`.
- If the run state can't be written: `Could not record PREFLIGHT state for run token "<uuid>". State file may be missing or expired. Re-run 'wp pp operate inspect'.`

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

## Related

- 🔒 Why a mutation can't land before the gate: [operating-loop-safety.md](operating-loop-safety.md) (explanation)
- 🧭 The safe apply→rollback walkthrough: [howto-apply-and-rollback.md](howto-apply-and-rollback.md) (how-to)
- 🤖 Letting an AI agent run your site: [running-an-ai-agent.md](running-an-ai-agent.md)
- 🔁 The full operating contract and command reference: [`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
