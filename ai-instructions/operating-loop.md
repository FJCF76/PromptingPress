# Agent Operating Loop

You are an AI agent operating a PromptingPress site. This document is your operating contract. Follow it step by step. Do not skip steps. Do not reorder steps.

## The Loop: 8 Steps, 4 Phases

```
Phase: Strategist
  1. INSPECT    — Read current state before touching anything
  2. PLAN       — Declare what will change before changing it

Phase: Implementer
  3. EDIT       — Execute via typed actions

Phase: Operator
  4. PREFLIGHT  — Check environment can safely apply
  5. APPLY      — Commit mutation with backup

Phase: Reviewer
  6. SCREENSHOT — Capture rendered state at declared viewports
  7. REVIEW     — Compare to brief/intent, check anti-slop visually

Phase: Operator
  8. HANDOFF    — Report what was done, verified, and what concerns remain
```

## Step Details

### 1. INSPECT
**Role**: Strategist. Read-only. Do not edit anything.

Run: `wp pp operate inspect` (or `wp pp operate inspect --post_id=<id>` for page-specific smells).

This returns the full operating picture: target environment, composition pages, drift state, preflight status, design tokens, CSS conflicts, and composition smells.

**Required output**: `site_state` — the full inspect result. Store it; you'll reference it throughout the loop.

### 2. PLAN
**Role**: Strategist. Declare intent before executing.

Based on the brief/task and the inspect result, declare:
- Which components/sections will be created, modified, or removed
- Which actions (typed mutations) you will call
- Which files will be affected (for drift overlap checking)

**Required output**: `mutation_plan` — structured plan of intended changes.

### 3. EDIT
**Role**: Implementer. Execute only what was planned.

Call PromptingPress typed actions (`wp pp action execute <name> --run-id=<uuid> --params='...'`, where `<name>` is positional) to make the planned mutations. Do not deviate from the plan. If you discover the plan is insufficient, loop back to PLAN — do not improvise at EDIT.

**Required output**: `edit_result` — list of actions executed and their results.

### 4. PREFLIGHT
**Role**: Operator. Safety gate before apply.

Run: `wp pp apply preflight` (optionally with `--planned-files='["assets/css/base.css"]'` for drift overlap detection).

Or call `pp_preflight()` programmatically with context.

Preflight checks:
1. **Target resolved** — site_url, wp_root, theme_path, environment
2. **Capability** — manage_options or WP-CLI context
3. **Backup writable** — backup directory probe
4. **Drift** — no overlapping drift between manifest and your planned file mutations
5. **Theme writable** — theme directory is writable for file-based applies
6. **Target page** — (for page operations) post exists and has a composition
7. **Surface classification** — (when `planned_files` provided) classifies each path as safe/extension/core; core files fail preflight with routing guidance toward the correct approved surface

**If PREFLIGHT fails**: STOP. Do not proceed to APPLY. Report the failure in HANDOFF.

**If drift overlaps with planned files**: STOP and escalate to the human. Do not proceed.

**If drift exists in non-overlapping files**: Proceed, but record the drift in your HANDOFF report.

**Required output**: `preflight_result` — the full preflight result.

### 5. APPLY
**Role**: Operator. Commit the mutation.

Run: `wp pp apply execute <name> --run-id=<uuid> --params='...'` (where `<name>` is positional)

Token overrides are stored in the database. Use `reset_design_token` or `reset_all_design_tokens` to revert.

**Required output**: `apply_result` — apply result including changes array.

### 6. SCREENSHOT
**Role**: Reviewer. Capture visual evidence.

Run: `wp pp screenshot capture --post_id=<id> --playbook=<name>`

This captures screenshots at both viewports (1280px desktop + 375px mobile) unless the playbook declares otherwise.

To diagnose capture readiness BEFORE you reach this step, run `wp pp screenshot doctor` (add `--probe` to attempt a real capture). It reports whether `PP_BROWSER_CMD` resolves, from where, and in which context (CLI vs web), with remediation. `wp pp apply preflight` also surfaces a non-blocking screenshot-readiness warning — a missing browser never blocks a typed mutation, it only means you cannot reach native `VERIFIED`. Setup: `docs/screenshot-setup.md`.

**Three status paths** (the capture command returns the matching `status` in its result):
- **Browser configured and capture succeeds**: Continue to REVIEW with screenshots.
- **Browser not configured** (PP_BROWSER_CMD not set): Skip to HANDOFF with status `NEEDS_VISUAL_VERIFICATION`.
- **Browser configured but capture fails**: Retry once per the failure subcategory rules. If still fails, proceed to HANDOFF with status `SCREENSHOT_FAILED`.

**Required output**: `screenshot_result` — paths to captured files, or error details.

### 7. REVIEW
**Role**: Reviewer. Compare to brief/intent.

Get the playbook checklist: `wp pp operate checklist --playbook=<name>`

Evaluate screenshots against the checklist without referencing your own reasoning about what you changed. Look only at what is visible in the screenshot and whether it matches the checklist criteria.

- **Hard gates** must pass. If any hard gate fails, loop back to step 2 (PLAN), not step 3 (EDIT).
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
3. **Preflight before applying.** Never write to files without checking safety.
4. **Screenshot before reviewing.** Visual verification is evidence, not assumption.
5. **Hard gate failure loops to PLAN, not EDIT.** Rethink the approach, don't just retry.
6. **Never claim VERIFIED without screenshots and a fully evaluated checklist.**
7. **Never claim VERIFIED with partial viewport coverage** unless the playbook declared only those viewports.
8. **Escalate drift conflicts.** If drifted files overlap with your planned mutations, stop and ask the human.
9. **Record everything.** The handoff report is the contract with the human.

## Enforcement Layers

Two complementary enforcement mechanisms protect the loop:

1. **Run tokens (real-time ordering):** `wp pp operate inspect` creates a state file tracking completed steps. Mutating commands (`action execute`, `apply preflight`, `apply execute`, `apply restore`) require `--run-id` and check the state file before proceeding. This prevents out-of-order CLI calls.

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
| `wp pp action execute <name> --run-id=<uuid> --params='...'` | EDIT | Required | Execute a typed action |
| `wp pp apply preflight --run-id=<uuid>` | PREFLIGHT | Required | Run safety checks, record PREFLIGHT |
| `wp pp apply preflight --run-id=<uuid> --planned-files='[...]'` | PREFLIGHT | Required | With drift overlap detection |
| `wp pp apply execute <name> --run-id=<uuid> --params='...'` | APPLY | Required | Commit a typed apply (DB-backed token/font override) |
| `wp pp apply restore --run-id=<uuid> [--token=<name>]` | APPLY | Required | Reset token overrides to product defaults — all, or one with `--token`. NOT a per-change undo: it discards current overrides rather than reverting to a prior snapshot. |
| `wp pp screenshot capture --post_id=<id> --playbook=<name>` | SCREENSHOT | — | Capture both viewports |
| `wp pp screenshot capture --capture-url=<url> --width=<px>` | SCREENSHOT | — | Capture single URL |
| `wp pp screenshot doctor [--probe]` | SCREENSHOT | — | Diagnose capture readiness (PP_BROWSER_CMD + context) |
| `wp pp operate checklist --playbook=<name>` | REVIEW | — | Get playbook checklist |
| `wp pp operate validate --run='...'` | HANDOFF | — | Validate loop run completeness |
