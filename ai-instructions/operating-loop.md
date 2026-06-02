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

Call PromptingPress typed actions (`wp pp action execute --action=<name> --params='...'`) to make the planned mutations. Do not deviate from the plan. If you discover the plan is insufficient, loop back to PLAN — do not improvise at EDIT.

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

**If PREFLIGHT fails**: STOP. Do not proceed to APPLY. Report the failure in HANDOFF.

**If drift overlaps with planned files**: STOP and escalate to the human. Do not proceed.

**If drift exists in non-overlapping files**: Proceed, but record the drift in your HANDOFF report.

**Required output**: `preflight_result` — the full preflight result.

### 5. APPLY
**Role**: Operator. Commit the mutation.

Run: `wp pp apply execute --apply=<name> --params='...'`

Apply creates a backup automatically. If apply fails, a restore point exists.

**Required output**: `apply_result` — apply result including backup path.

### 6. SCREENSHOT
**Role**: Reviewer. Capture visual evidence.

Run: `wp pp screenshot capture --post_id=<id> --playbook=<name>`

This captures screenshots at both viewports (1280px desktop + 375px mobile) unless the playbook declares otherwise.

**Three status paths**:
- **Browser configured and capture succeeds**: Continue to REVIEW with screenshots.
- **Browser not configured** (PP_BROWSER_CMD not set): Skip to HANDOFF with status `NEEDS_VISUAL_VERIFICATION`.
- **Browser configured but capture fails**: Retry once per the failure subcategory rules. If still fails, proceed to HANDOFF with status `SCREENSHOT_FAILED`.

**Required output**: `screenshot_result` — paths to captured files, or error details.

### 7. REVIEW
**Role**: Reviewer. Compare to brief/intent.

Get the playbook checklist: `wp pp operate checklist --playbook=<name>`

Evaluate each checklist item against the screenshots:
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

1. **Inspect before editing.** Never modify state without reading it first.
2. **Preflight before applying.** Never write to files without checking safety.
3. **Screenshot before reviewing.** Visual verification is evidence, not assumption.
4. **Hard gate failure loops to PLAN, not EDIT.** Rethink the approach, don't just retry.
5. **Never claim VERIFIED without screenshots and a fully evaluated checklist.**
6. **Never claim VERIFIED with partial viewport coverage** unless the playbook declared only those viewports.
7. **Escalate drift conflicts.** If drifted files overlap with your planned mutations, stop and ask the human.
8. **Record everything.** The handoff report is the contract with the human.

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

| Command | Step | Purpose |
|---|---|---|
| `wp pp operate inspect` | INSPECT | Full site operating picture |
| `wp pp operate inspect --post_id=<id>` | INSPECT | Include page-specific smells |
| `wp pp action execute --action=<name> --params='...'` | EDIT | Execute a typed action |
| `wp pp apply preflight` | PREFLIGHT | Run safety checks |
| `wp pp apply preflight --planned-files='[...]'` | PREFLIGHT | With drift overlap detection |
| `wp pp apply execute --apply=<name> --params='...'` | APPLY | Commit file mutation |
| `wp pp screenshot capture --post_id=<id> --playbook=<name>` | SCREENSHOT | Capture both viewports |
| `wp pp screenshot capture --capture-url=<url> --width=<px>` | SCREENSHOT | Capture single URL |
| `wp pp operate checklist --playbook=<name>` | REVIEW | Get playbook checklist |
| `wp pp operate validate --run='...'` | HANDOFF | Validate loop run completeness |
