# Agent Operating Loop

You are an AI agent operating a PromptingPress site. This document is your operating contract. Follow it step by step. Do not skip steps. Do not reorder steps.

## The Loop: 8 Steps, 4 Phases

```
Phase: Strategist
  1. INSPECT    — Read current state before touching anything
  2. PLAN       — Declare what will change before changing it

Phase: Operator (safety gate)
  3. PREFLIGHT  — Check the environment can safely mutate, BEFORE any write

Phase: Implementer
  4. EDIT       — Execute via typed actions (gated: needs a covering PREFLIGHT)

Phase: Operator
  5. APPLY      — Commit file/token mutation with backup

Phase: Reviewer
  6. SCREENSHOT — Capture rendered state at declared viewports
  7. REVIEW     — Compare to brief/intent, check anti-slop visually

Phase: Operator
  8. HANDOFF    — Report what was done, verified, and what concerns remain
```

PREFLIGHT runs before EDIT: every DB-backed mutation (typed actions and
`operate patch`) requires a completed PREFLIGHT covering its target first. The
old order let typed edits land before the safety gate; they no longer can.

## Step Details

### 1. INSPECT
**Role**: Strategist. Read-only. Do not edit anything.

Run: `wp pp operate inspect` (or `wp pp operate inspect --post_id=<id>` for page-specific smells).

This returns the full operating picture: target environment, composition pages, drift state, preflight status, design tokens, CSS conflicts, composition smells, and (with `--post_id`) a `composition_decode_error` signal that is set when the page's stored composition is corrupt or not a valid list rather than genuinely empty (issue 144).

**Required output**: `site_state` — the full inspect result. Store it; you'll reference it throughout the loop.

### 2. PLAN
**Role**: Strategist. Declare intent before executing.

Based on the brief/task and the inspect result, declare:
- Which components/sections will be created, modified, or removed
- Which actions (typed mutations) you will call
- Which files will be affected (for drift overlap checking)

**Required output**: `mutation_plan` — structured plan of intended changes.

### 3. PREFLIGHT
**Role**: Operator. Safety gate before ANY mutation.

Run: `wp pp apply preflight --run-id=<uuid>` for site/token work, or
`wp pp apply preflight --run-id=<uuid> --post_id=<id>` before mutating a specific
page (optionally with `--planned-files='["assets/css/base.css"]'` for drift
overlap detection). Pass `--post_id` for every page you intend to edit: the
recorded coverage is what unlocks the typed mutations in EDIT for that page.

Or call `pp_preflight()` programmatically with context.

Preflight checks:
1. **Target resolved** — site_url, wp_root, theme_path, environment
2. **Capability** — manage_options or WP-CLI context
3. **Drift** — no overlapping drift between manifest and your planned file mutations
4. **Theme writable** — theme directory is writable for file-based applies
5. **Target page** — (for page operations) the post exists. Whether it has a non-empty composition is NOT checked here: preflight runs once per target and is action-agnostic, so the "needs an existing composition" precondition is enforced per-action at execute time — component-level edits (`add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`) require one; populate/lifecycle/metadata actions (`update_composition`, `trash_page`, `publish_page`, …) do not, so a page created empty by `create_page` can still be populated or deleted (#358)
6. **Surface classification** — (when `planned_files` provided) classifies each path as safe/extension/core; core files fail preflight with routing guidance toward the correct approved surface

**If PREFLIGHT fails**: STOP. Do not proceed to EDIT or APPLY. Report the failure in HANDOFF.

**If drift overlaps with planned files**: STOP and escalate to the human. Do not proceed.

**If drift exists in non-overlapping files**: Proceed, but record the drift in your HANDOFF report.

**Classified findings (#496).** Preflight output carries a `findings` block grouping the warning-grade findings by class, each with a sanctioned `next_action`:
- **integrity** (theme file drift vs the recorded release baseline) — resolve with `wp pp readiness rebaseline`, which re-snapshots the manifest against the currently-installed release. After that, drift means "changed since this release", never "stale baseline".
- **configuration** (site-state gaps like an unassigned menu location) — resolve through the finding's safe surface (e.g. `set_menu`), OR, if the gap is deliberate (a purposely menu-less footer), record it as intentional with `wp pp readiness acknowledge <finding-key>`. Acknowledged findings report as acknowledged, not warnings, and are reversible with `wp pp readiness unacknowledge <finding-key>`.
- **capability** (an environment tool missing, e.g. a screenshot browser) — run the finding's next action (e.g. `wp pp screenshot doctor`).

Use `wp pp readiness status` any time for a read-only, grouped view of current findings (`active_warnings` vs `acknowledged`). Status, `inspect`, and `apply preflight` never mutate — only `rebaseline` / `acknowledge` / `unacknowledge` change state, and each is an explicit command. A completed operation should show zero unexplained warnings: every finding is either actionable-now, acknowledged-intentional, or absent.

**Required output**: `preflight_result` — the full preflight result (including the `findings` block).

### 4. EDIT
**Role**: Implementer. Execute only what was planned.

Call PromptingPress typed actions (`wp pp action execute <name> --run-id=<uuid> --params='...'`, where `<name>` is positional) to make the planned mutations. Each mutating action (and `wp pp operate patch`) is gated: it refuses to run unless the run has a completed PREFLIGHT covering its target (the `post_id` for page/section work, or a site-scoped preflight for site actions). Do not deviate from the plan. If you discover the plan is insufficient, loop back to PLAN — do not improvise at EDIT.

**`ok: true` is not the whole result — read `findings` (#687).** Every accepted composition write returns a `findings` list describing the composition it just stored: `severity: warning` advisories (notably `inert_slot`, "the value is stored and reported as applied, but nothing on the page reads it") and `severity: error` findings on bands current rules reject. A write can succeed and paint nothing, so treat a non-empty `findings` on your own edit as part of the edit's result, not as background noise: fix the band it names in this same EDIT step, or record why you are leaving it. On a single write an empty list means the stored composition broke no rule and tripped no advisory. That is narrower than "the edit achieved what you wanted" — a valid value can still be the wrong value, and no rule will say so. It is the floor, not the verdict; the VERIFY step is still where intent is checked. That floor now holds on `create_page` too (#719): a `create_page` whose composition write was refused by the page lock used to return `ok: true` with `findings: []` over an EMPTY page, so the empty list was an all-clear on lost content. It is now a refusal that deletes the page it had just created, so `create_page` no longer hands you an empty page behind a clean report — and a refusal is safe to retry as-is, because no page was left behind (unless the message says otherwise, in which case it names the page it left). **In a BATCH it is not, if the batch rolled back:** each step's report was built when that step succeeded, before a later step's failure reverted everything, so a rolled-back batch returns reports — empty or not — describing compositions that no longer exist. After `rolled_back: true`, re-read the page; do not read any step's `findings` as the state of anything. The one field that stays trustworthy there is the FAILED step's own `index` (#712): the executor nulls it when an earlier step in the same batch wrote that page, so a locator you are given after a rollback still addresses the band it names — while the step's message text, like the reports, may still quote mid-batch offsets. **A batch can also be REFUSED before step 1 (#749)**, and that is a different outcome from a rollback: if any page the batch NAMES has a stored composition that cannot be read, the whole proposal is rejected with no steps, `rolled_back: false`, and an `error_code` of `unexpected_shape` or `decode_error`. Nothing ran, so nothing changed — the refusal exists because rolling back would mean writing a stand-in over that page's unreadable bytes. (A rollback still would; what changed in #818 is the SINGLE-write repair path below, which now preserves those bytes on the history ring instead of discarding them. Read them back after a repair with `wp pp operate composition-history --post_id=<id>`.) Do not retry the batch as-is, and do not try to repair the page through a chat proposal at all — not even a one-step one. The chat client sends every proposal through the batch endpoint, and this gate runs BEFORE any step's own semantics, so a repairing `update_composition` or `restore_composition` sent that way is refused by the very code it was meant to clear (`restore_composition` included, even though #233 otherwise never blocks it). Repair from a surface that takes no rollback snapshot, and there are exactly two (#767): `wp pp action execute update_composition` / `wp pp action execute restore_composition` on WP-CLI, or the dashboard composition editor — one full `update_composition` write (a JSON array of components) or one `restore_composition`. Those two CLI verbs need **no preflight** on a page in this state, because `wp pp apply preflight` fails closed on a corrupt composition and requiring one would make the repair unreachable; nothing else is waived (run token, `INSPECT` first, and full validation of what you send all still apply). `pp_patch_composition()` / `wp pp operate patch` is **not** a route here, despite older wording that listed it: it is refused on a corrupt page by the same precondition as the band-level actions (#748), and a field selector cannot reshape a container anyway. Then run the proposal again. A batch that only publishes or renames such a page is refused for the same reason.

**A `findings` list can be INCOMPLETE, and it says so (#687, #654).** Any report longer than 100 entries is cut to 100 and closed by one entry of `type: findings_truncated`, `severity: warning`, `index: null`, carrying the true count as a `total` integer and naming the command for the rest. Read that entry before you read the report: 100 findings is not "100 problems", it is "at least 100, and here is the real number". The same cap now applies to `restore_composition` (preview and execute) and to the run-scoped rollback, which bounds per reverted post — so an undo of a badly corrupt snapshot reports the first 100 problems per page, not all of them. Two entries mean two different things and neither is a clean bill of health: `findings_truncated` means "more exist than are listed", and `findings_skipped` (accepted writes only, above 1 MiB of stored composition) means "the engines never ran, so this says nothing at all". An EMPTY list is the only clean report. **`wp pp check page --post_id=N` is never truncated** — it is the complete report, and it is what both entries point you to. Do not count the array to report a number; read `total` when it is there.

**Required output**: `edit_result` — list of actions executed and their results, including any `findings` each accepted write returned and what you did about them.

### 5. APPLY
**Role**: Operator. Commit the mutation.

Run: `wp pp apply execute <name> --run-id=<uuid> --params='...'` (where `<name>` is positional)

Token overrides are stored in the database. Use `reset_design_token` or `reset_all_design_tokens` to revert.

**Required output**: `apply_result` — apply result including changes array.

### 6. SCREENSHOT
**Role**: Reviewer. Capture visual evidence.

Run: `wp pp screenshot capture --post_id=<id> --playbook=<name>`

This captures screenshots at both viewports (1280px desktop + 375px mobile) unless the playbook declares otherwise.

To diagnose capture readiness BEFORE you reach this step, run `wp pp screenshot doctor`. It probes by default (attempts a real capture) and reports one definitive tri-state: `available` (configured and capture-verified), `unavailable` (`PP_BROWSER_CMD` not configured — lists candidate binaries + the setup step), or `broken` (configured but failing, with the error). Add `--no-probe` for a fast capability-only check. It also reports where `PP_BROWSER_CMD` resolves from and in which context (CLI vs web). `wp pp apply preflight` surfaces the same state as a non-blocking capability finding (`unavailable` and `broken` render distinctly) — a missing browser never blocks a typed mutation, it only means you cannot reach native `VERIFIED`. Setup: `docs/screenshot-setup.md`.

**Three status paths** (the capture command returns the matching `status` in its result):
- **Browser configured and capture succeeds**: Continue to REVIEW with screenshots.
- **Browser not configured** (PP_BROWSER_CMD not set): Skip to HANDOFF with status `NEEDS_VISUAL_VERIFICATION`.
- **Browser configured but capture fails**: Retry once per the failure subcategory rules. If still fails, proceed to HANDOFF with status `SCREENSHOT_FAILED`.

**Required output**: `screenshot_result` — paths to captured files, or error details.

### 7. REVIEW
**Role**: Reviewer. Compare to brief/intent.

Get the playbook checklist: `wp pp operate checklist --playbook=<name>`

Evaluate screenshots against the checklist without referencing your own reasoning about what you changed. Look only at what is visible in the screenshot and whether it matches the checklist criteria.

- **Hard gates** must pass. If any hard gate fails, loop back to step 2 (PLAN), not step 4 (EDIT).
- **Soft gates** are noted but do not block.

**Required output**: `review_result` — checklist evaluation with pass/fail per item.

### 8. HANDOFF
**Role**: Operator. Produce evidence.

Report:
- **Status**: `VERIFIED`, `NEEDS_VISUAL_VERIFICATION`, or `SCREENSHOT_FAILED`
- What was changed (actions, applies, files)
- Screenshot paths (if captured)
- Checklist results (if evaluated)
- Drift state at handoff (run `pp_check_drift()` again — detects concurrent changes)
- Any concerns or notes for the human

**VERIFIED** requires:
- All declared viewport screenshots captured
- All checklist items evaluated
- All hard gates passed

**NEEDS_VISUAL_VERIFICATION**: Browser not configured. Structural changes applied, no visual evidence.

**SCREENSHOT_FAILED**: Browser configured but capture failed. Include `failure_reason`.

**Required output**: `handoff_report` — structured report with status.

## Rules

1. **Pass the run token.** Every `wp pp operate inspect` returns a `run_id`. Pass it to all subsequent mutating CLI commands via `--run-id`. Commands fail without it.
2. **Inspect before editing.** Never modify state without reading it first.
3. **Preflight before mutating.** Never write to the database or files without a completed PREFLIGHT covering the target. Typed actions and `operate patch` are gated, not just file applies.
4. **Screenshot before reviewing.** Visual verification is evidence, not assumption.
5. **Hard gate failure loops to PLAN, not EDIT.** Rethink the approach, don't just retry.
6. **Never claim VERIFIED without screenshots and a fully evaluated checklist.**
7. **Never claim VERIFIED with partial viewport coverage** unless the playbook declared only those viewports.
8. **Escalate drift conflicts.** If drifted files overlap with your planned mutations, stop and ask the human.
9. **Record everything.** The handoff report is the contract with the human.

## Enforcement Layers

Two complementary enforcement mechanisms protect the loop:

1. **Run tokens (real-time ordering):** `wp pp operate inspect` records run state (completed steps) in a per-run row in the install's options table. Mutating commands (`action execute`, `apply preflight`, `apply execute`, `apply restore`, `apply reset`) require `--run-id` and check that recorded state before proceeding. This prevents out-of-order CLI calls, and because the state lives in the database it is shared across separate CLI invocations even when each runs in its own ephemeral container (#409).

2. **`wp pp operate validate` (post-hoc completeness):** Validates the finished run manifest — checks that all 8 steps ran, required outputs are present, viewports match the playbook, hard-gate checklist items were evaluated, and retry count is within bounds. This catches incomplete runs at HANDOFF.

## Escalation Rules

- **Preflight fails**: Stop. Report failure in HANDOFF. Do not attempt workarounds.
- **Drift conflicts**: Stop and escalate to the human.
- **Hard gate fails on REVIEW**: Loop back to PLAN. Maximum 2 retry loops before escalating.
- **Screenshot capture fails twice**: HANDOFF with SCREENSHOT_FAILED. Do not keep retrying.

## Playbooks

Three playbooks are available. Each one customizes the loop for a specific operation:

1. **create-page** — Full loop for creating a new page from a brief. See `playbook-create-page.md`.
2. **revise-section** — Targeted revision of an existing section. See `playbook-revise-section.md`.
3. **inspect-fix** — Diagnose and fix a reported issue. See `playbook-inspect-fix.md`.

## CLI Reference

| Command | Step | `--run-id` | Purpose |
|---|---|---|---|
| `wp pp operate inspect` | INSPECT | Returns it | Full site operating picture + run token |
| `wp pp operate inspect --post_id=<id>` | INSPECT | Returns it | Include page-specific smells |
| `wp pp apply preflight --run-id=<uuid>` | PREFLIGHT | Required | Run safety checks, record a site-scoped PREFLIGHT |
| `wp pp apply preflight --run-id=<uuid> --post_id=<id>` | PREFLIGHT | Required | Record a PREFLIGHT covering that page (unlocks its typed mutations) |
| `wp pp apply preflight --run-id=<uuid> --planned-files='[...]'` | PREFLIGHT | Required | With drift overlap detection |

**Page addressing (#726).** Every `wp pp` command that targets one page takes `--post_id=<id>` — a numeric post ID in canonical decimal form, never a slug, a URL, or a positional argument. Since 1.15.13 that is enforced identically on all seven such commands, so `--post_id=00019` or `--post_id=about-us` is refused by name instead of being read as a different page (or, on `apply preflight`, silently downgrading a page-scoped preflight to site scope). Get IDs from `wp pp operate inspect`'s page map. Full contract: `docs/reference-apply-cli.md`.
| `wp pp action execute <name> --run-id=<uuid> --params='...'` | EDIT | Required | Execute a typed action (needs a PREFLIGHT covering its target) |
| `wp pp operate patch --post_id=<id> --target=... --value=... --run-id=<uuid>` | EDIT | Required (mutation) | Patch a composition field (needs a PREFLIGHT covering the page; `--preview` is read-only and ungated) |
| `wp pp apply execute <name> --run-id=<uuid> --params='...'` | APPLY | Required | Commit a typed apply (DB-backed token/font override) |
| `wp pp apply restore --run-id=<uuid> [--token=<name>]` | APPLY | Required | Per-run rollback: reverts the tokens THIS run changed (primary + derived) to the snapshot frozen at the run's preflight; tokens the run never touched are preserved. `--token` restores that token and its derived family from the snapshot. Short-lived: only works within the run-token TTL; fails closed (changes nothing) if the snapshot is missing/expired/corrupt or from another install — it never falls back to product defaults. |
| `wp pp apply reset --run-id=<uuid> [--token=<name>]` | APPLY | Required | Reset token overrides to product defaults — all, or one with `--token`. This is the deliberate "back to base.css" path, NOT a per-run undo. Use `apply restore` to undo a specific run. |
| `wp pp screenshot capture --post_id=<id> --playbook=<name>` | SCREENSHOT | — | Capture both viewports |
| `wp pp screenshot capture --capture-url=<url> --width=<px>` | SCREENSHOT | — | Capture single URL |
| `wp pp screenshot doctor [--no-probe]` | SCREENSHOT | — | Diagnose capture readiness — tri-state available/unavailable/broken (probes by default) |
| `wp pp schema` | any | — | Read-only: every registered component and whether it is composable (`nav`/`footer` are template-owned chrome) |
| `wp pp schema <component>` | any | — | Read-only: one component's declared props, style slots (each with its `applies_when` and a rendered condition phrase) and recipes — the schema contract without filesystem access (#688) |
| `wp pp readiness status` | any | — | Read-only: current findings grouped by class (integrity/configuration/capability) with per-finding next actions (#496) |
| `wp pp readiness rebaseline` | any | — | Re-baseline the deployment manifest against the installed release (resolves integrity drift) |
| `wp pp readiness acknowledge <finding-key> [--note=<text>]` | any | — | Record a configuration finding as intentional (reversible) |
| `wp pp readiness unacknowledge <finding-key>` | any | — | Reverse an acknowledgement |
| `wp pp operate checklist --playbook=<name>` | REVIEW | — | Get playbook checklist |
| `wp pp operate validate --run='...'` | HANDOFF | — | Validate loop run completeness |
