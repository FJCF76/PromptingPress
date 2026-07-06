# 🧭 How to apply a token change and roll it back

This guide walks the safe cycle for changing a design token with `wp pp apply`: get a run token, preflight (which freezes a rollback point), apply the change, verify it, and roll it back if you don't want to keep it. By the end you'll have changed a token and reverted it exactly, touching nothing else.

If you want the full flag-by-flag surface, see the [apply CLI reference](reference-apply-cli.md). If you want to understand *why* the preflight gate exists, see [operating-loop safety](operating-loop-safety.md).

## Prerequisites

- WP-CLI available for this install (`wp --info` works).
- PromptingPress active as the theme.
- Shell access to run `wp pp ...` commands.
- A token you want to change. This guide uses `--color-accent`. List what exists with `wp pp apply list`.

## Steps

### 1. Get a run token

Every mutating command needs a run token. Mint one:

```bash
wp pp operate inspect
```

This prints the site's operating picture as JSON with a `run_id` at the end, for example:

```json
{ "...": "...", "run_id": "3f2a9c14-7b6e-4d21-9f0a-1c2b3d4e5f60" }
```

Copy that UUID. It's valid for **2 hours** and is scoped to this install. Use it for every command below. (Shell tip: `RUN=$(wp pp operate inspect | wp eval 'echo json_decode(file_get_contents("php://stdin"))->run_id;')` or just paste it.)

### 2. Preview the change (optional, no run token needed)

See the diff without writing anything:

```bash
wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'
```

You'll get `{"ok": true, ...}` with a `changes` array. If `ok` is `false`, read `error` and fix your params before continuing.

### 3. Preflight — this freezes your rollback point

```bash
wp pp apply preflight --run-id=$RUN
```

Preflight validates the surface (target, capability, drift, and more) **and** records the pre-apply token snapshot that a later restore rolls back to. Look for `"ok": true` in the output.

Expected result (abridged):

```json
{ "ok": true, "checks": [ { "check": "target", "pass": true, "message": "..." }, ... ] }
```

You must see `"ok": true` here. If not, jump to [Troubleshooting](#troubleshooting) — do not proceed to `execute`, it will refuse.

> Editing a specific page instead of site-wide tokens? Add `--post_id=<id>` so the preflight covers that page.

### 4. Apply the change

```bash
wp pp apply execute update_design_token --run-id=$RUN --params='{"token":"--color-accent","value":"#b45309"}'
```

On success you'll see the result JSON followed by:

```
Success: Apply "update_design_token" executed.
```

The command also records which tokens it touched, so the next step can revert exactly this change.

### 5. Verify it landed

```bash
wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'
```

The `before`/`after` in the execute output already show the change; previewing the same value again is a quick confirm. To see it on the site, reload a page that uses `--color-accent` (or capture a screenshot if you have `PP_BROWSER_CMD` set — see [screenshot setup](screenshot-setup.md)).

### 6. Roll it back (if you don't want to keep it)

Undo exactly what this run changed, leaving every other override alone:

```bash
wp pp apply restore --run-id=$RUN
```

Expected:

```
Success: Restored 1 token(s) to the pre-run snapshot.
```

To revert just one token from a multi-token run, scope it:

```bash
wp pp apply restore --run-id=$RUN --token=--color-accent
```

## Verification

- `execute` returned `Success: Apply "..." executed.` and its `after` shows your new value.
- After `restore`, re-run step 5's preview: the token is back to its pre-run value (or gone, if the run created it).
- Unrelated overrides are unchanged — `restore` only touches this run's footprint.

## Restore is not reset

- **`restore`** undoes *this run* back to its preflight snapshot. Use it to reverse a change you just made.
- **`reset`** clears overrides back to product defaults (`base.css`), ignoring run history. Use it to wipe a token (or all tokens) to shipped defaults:

  ```bash
  wp pp apply reset --run-id=$RUN --token=--color-accent   # one token to default
  wp pp apply reset --run-id=$RUN                           # all tokens to default
  ```

  `reset` records its touched tokens too, so a reset inside a run is itself restorable.

## Troubleshooting

**`--run-id is required` / `--run-id must be a valid UUID v4`**
You didn't pass `--run-id`, or the value isn't a UUID. Re-run `wp pp operate inspect` and copy the `run_id`.

**`Run token "..." has no completed PREFLIGHT step`**
You skipped step 3, or the run expired. Run `wp pp apply preflight --run-id=$RUN` (add `--post_id=<id>` for page work). If it still fails, the 2-hour token likely expired — mint a fresh one with `wp pp operate inspect`.

**Preflight errors: `Could not read an atomic pre-apply token baseline ... the token lock is contended`**
Another process is writing tokens right now. Preflight fails closed on purpose rather than freezing a stale rollback point (issue #200). Wait a moment and re-run the same `preflight` command; it succeeds once the contention clears.

**`Refusing to apply: run "..." has no usable rollback snapshot`**
The run has no snapshot to undo to, so `execute`/`reset` won't mutate. Re-run `wp pp operate inspect` then `wp pp apply preflight --run-id=$RUN` to establish a fresh, reversible baseline.

**`Run "..." has no usable pre-apply snapshot; cannot roll back`**
`restore` fails closed when the snapshot or touched-key list is missing, expired, corrupt, swept, or from a different site. Nothing was changed. If the run is still within its 2-hour window and wasn't corrupted, re-check you're using the right `run_id`; otherwise the change from that run can't be auto-reverted through the tooling — reset the specific tokens to defaults or set them explicitly.

**`Apply "..." persisted, but recording its touched tokens ... FAILED`**
The change landed but its rollback trail didn't record, so `restore` may not cover it. Stop making further changes, run `wp pp operate inspect`, and verify run state before continuing.

## What's next

- Full flag and output reference: [reference-apply-cli.md](reference-apply-cli.md)
- Why the gate is built this way: [operating-loop-safety.md](operating-loop-safety.md)
- Handing this whole loop to an AI agent: [running-an-ai-agent.md](running-an-ai-agent.md)
