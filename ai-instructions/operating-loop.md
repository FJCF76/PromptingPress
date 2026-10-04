# Agent Operating Loop

You are an AI agent operating a PromptingPress site. This document is your operating contract. Follow it step by step. Do not skip steps. Do not reorder steps.

## The Loop: 8 Steps, 4 Phases

```
Phase: Strategist
  1. INSPECT    — Read current state before touching anything
  2. PLAN       — Declare what will change before changing it

Phase: Operator (safety gate)
  3. PREFLIGHT  — Check the environment can safely mutate, BEFORE any design or content write
                (exception: repairing a corrupt page, ruling D-1 under EDIT)

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
**Role**: Strategist. Do not edit anything in this step.

Run: `wp pp operate inspect` (or `wp pp operate inspect --post_id=<id>` for page-specific smells).

`inspect` does not change the site's design (compositions, chrome, presets, tokens, options an operator owns). It does write run bookkeeping: it creates exactly one row, the run-state option `pp_operate_run_<uuid>` (autoload off), whose `<uuid>` is the `run_id` you pass to later steps, and it first deletes dead run-state rows (a value that is not an array, has no `created_at`, or is past the 2-hour TTL). Nothing else. Every call mints a NEW token, so re-running `inspect` mid-run starts a new run rather than refreshing yours; your old token is not revoked and stays usable until its own TTL runs out, so keep passing the one your PREFLIGHT covered. Full contract: `docs/reference-apply-cli.md`.

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

Use `wp pp readiness status` any time for a read-only, grouped view of current findings (`active_warnings` vs `acknowledged`). Status never writes anything. `inspect` and `apply preflight` never change the site's design, but each writes run bookkeeping: `inspect` creates the run-state row and deletes dead ones, and `apply preflight` records its step, what it covered, the composition freshness marker and its rollback baselines in that same row; if that recording fails because the row has expired or is corrupt, it deletes the row (see the INSPECT step above). Readiness state changes only through explicit commands: `readiness rebaseline` / `acknowledge` / `unacknowledge`, and `sync check --save-manifest`, which writes the same deployment manifest as `rebaseline`. A completed operation should show zero unexplained warnings: every finding is either actionable-now, acknowledged-intentional, or absent. Composition advisories that are judgment calls (an unmeasured ink pair, a raw `_css` property, a composition smell) have the same route since #1194: `wp pp check acknowledge --post_id=<id> --key=<key> --note=...`, with the key `wp pp check page` prints; see `validate-site.md`.

**Required output**: `preflight_result` — the full preflight result (including the `findings` block).

### 4. EDIT
**Role**: Implementer. Execute only what was planned.

Call PromptingPress typed actions (`wp pp action execute <name> --run-id=<uuid> --params='...'`, where `<name>` is positional) to make the planned mutations. Each mutating action (and `wp pp operate patch`) is gated: it refuses to run unless the run has a completed PREFLIGHT covering its target (the `post_id` for page/section work, or a site-scoped preflight for site actions). Do not deviate from the plan. If you discover the plan is insufficient, loop back to PLAN — do not improvise at EDIT.

**`ok: true` is not the whole result — read `findings` (#687, #993).** Every accepted composition write returns a `findings` list describing the composition it just stored, and since #993 so does a chrome write (`update_site_option` on `pp_site_udc`), which reports the same minting and preset-skip disclosures with `index: null` because a chrome entry has no band offset. Since #1016 the two preset verbs (`save_preset`, `delete_preset`) carry `findings` on that same channel, for the same reason: a preset is referenced FROM chrome, so editing one changes what chrome paints without any chrome write happening, and the write that caused the change is the one that reports it. Stated limit — that disclosure is derived from the chrome container only, so a preset write that changes what BANDS paint reports nothing. The list carries: `severity: info` notes that ask for nothing and never fail `wp pp validate site` (only `udc_token_minted`, #1194), `severity: warning` advisories (notably `udc_band_value_shadowed_by_role_default` — a value you set at rest on the whole band that every text role's own default cancels, so it is stored and reported as applied and paints nothing; this is the heir to `inert_slot`, which retired at #1101 with the style-slot engine) and `severity: error` findings on bands current rules reject. A write can succeed and paint nothing, so treat a non-empty `findings` on your own edit as part of the edit's result, not as background noise: fix the band it names in this same EDIT step, or record why you are leaving it. The exception is `severity: info`: those notes ask for nothing, so do not "fix" them, and on a page write every error and warning is delivered ahead of them, so only the `findings_truncated` entry that closes a cut list can follow a note (#1194; a header or footer write and a preset write are delivered in the same order, #1204). On a single write an empty list, or a list of notes alone, means the stored composition broke no rule and tripped no advisory. That is narrower than "the edit achieved what you wanted" — a valid value can still be the wrong value, and no rule will say so. It is the floor, not the verdict; the VERIFY step is still where intent is checked. That floor now holds on `create_page` too (#719): a `create_page` whose composition write was refused by the page lock used to return `ok: true` with `findings: []` over an EMPTY page, so the empty list was an all-clear on lost content. It is now a refusal that deletes the page it had just created, so `create_page` no longer hands you an empty page behind a clean report — and a refusal is safe to retry as-is, because no page was left behind (unless the message says otherwise, in which case it names the page it left). **In a BATCH it is not, if the batch rolled back:** each step's report was built when that step succeeded, before a later step's failure reverted everything, so a rolled-back batch returns reports — empty or not — describing compositions that no longer exist. After `rolled_back: true`, re-read the page; do not read any step's `findings` as the state of anything. **A rollback now also removes what the batch CREATED (#854), so ids and paths from earlier steps go stale with it:** a page from `create_page`, an attachment from `import_media`, and a redirect row from `create_redirect` are all gone, which means an `attachment_id` an earlier step handed you no longer resolves and must not be carried into the retry — import it again. Two things are deliberately NOT removed, because the batch did not create them: an `import_media` that answered `action: "reused"` (the attachment pre-dated your batch), and a `create_redirect` that replaced an existing row (the prior `to`/`code` is restored instead). **And `rollback_errors: []` now means what it says**, which took two changes. It used to be silent about redirect and media steps because the rollback never covered them (#854 covers every step class now), and it was silent about its OWN failures because every restore write discarded its return (#857 checks all of them). So an empty list means nothing survived, rather than meaning a step class was never looked at or a failed write was never noticed. A restore that could not put something back — a page this batch created and could not delete, a title or slug or status or SEO field, a site setting, the design tokens, the fonts, the Custom CSS — now names it here. A NON-empty list names what is still live — a redirect still 301ing, an attachment still in the Media Library, a page whose composition was left alone — and each entry tells you what to do about it. Read it before you retry. The one field that stays trustworthy there is the FAILED step's own `index` (#712): the executor nulls it when an earlier step in the same batch wrote that page, so a locator you are given after a rollback still addresses the band it names — while the step's message text, like the reports, may still quote mid-batch offsets. **A batch can also be REFUSED before step 1 (#749)**, and that is a different outcome from a rollback: if any page the batch NAMES has a stored composition that cannot be read, the whole proposal is rejected with no steps, `rolled_back: false`, and an `error_code` of `unexpected_shape` or `decode_error`. Nothing ran, so nothing changed — the refusal exists because rolling back would mean writing a stand-in over that page's unreadable bytes. (A rollback still would; what changed in #818 is the SINGLE-write repair path below, which now preserves those bytes on the history ring instead of discarding them. Read them back after a repair with `wp pp operate composition-history --post_id=<id>`.) Do not retry the batch as-is. **Repair it as a proposal of its OWN (#756)** — one step, nothing alongside it: a single `update_composition` (a JSON array of components) or a single `restore_composition` on that page. The chat client sends every proposal through the batch endpoint, so that one-step proposal reaches this same gate — and ruling D-1 admits it there, on a page already classified `unexpected_shape`/`decode_error`, for those two verbs only. Everything else about the gate is unchanged, so putting ANY second step beside the repair is refused again, and so is a repair aimed at a page that is not corrupt. Full validation of what you send still applies; the exemption is the batch preflight and nothing else. The same repair also runs from a surface that takes no rollback snapshot: `wp pp action execute update_composition` / `wp pp action execute restore_composition` on WP-CLI, or the dashboard composition editor. Those two CLI verbs need **no preflight** on a page in this state, because `wp pp apply preflight` fails closed on a corrupt composition and requiring one would make the repair unreachable; nothing else is waived (run token, `INSPECT` first, and full validation of what you send all still apply). `pp_patch_composition()` / `wp pp operate patch` is **not** a route here, despite older wording that listed it: it is refused on a corrupt page by the same precondition as the band-level actions (#748), and a field selector cannot reshape a container anyway. Then run the proposal again. A batch that only publishes or renames such a page is refused for the same reason.

**A `findings` list can be INCOMPLETE, and it says so (#687, #654).** Any report longer than 100 entries is cut to 100 and closed by one entry of `type: findings_truncated`, `severity: warning`, `index: null`, carrying the true count as a `total` integer and naming the command for the rest. Read that entry before you read the report: 100 findings is not "100 problems", it is "at least 100, and here is the real number". The same cap now applies to `restore_composition` (preview and execute) and to the run-scoped rollback, which bounds per reverted post — so an undo of a badly corrupt snapshot reports the first 100 problems per page, not all of them. Two entries mean two different things and neither is a clean bill of health: `findings_truncated` means "more exist than are listed", and `findings_skipped` (accepted writes only, above 1 MiB of stored composition) means "the engines never ran, so this says nothing at all". An EMPTY list, or one holding only `severity: info` notes, is the only clean report (#1194). **`wp pp check page --post_id=N` is never truncated** — it is the complete report for a page, and it is what both entries point you to on a page write. On a header or footer write (`pp_site_udc`) or a preset write, `findings_truncated` points you to `wp pp validate site` instead, whose header and footer section lists that report whole (#1204), and a `findings_skipped` there means the report could not be built at all (it points you to `wp pp operate inspect` for the stored map). The one exception is the `udc_*` disclosures that multiply by card or property count: each stops at 200 per page, and one pair shares one budget (`udc_css_overrides_group_value` + `udc_css_unchecked_property`). So 200 of one of these types, or of that pair together, means "at least 200", and a gating one that reached its limit adds one `udc_findings_capped` warning naming it, which fails `wp pp validate site` until you have fixed enough to see the rest (#1194). The informational `udc_token_minted` has its own budget and never adds one. Do not count the array to report a number; read `total` when it is there.

**A third entry means your change cannot be undone (#821).** `type: history_not_recorded`, `severity: warning`, `index: null`, and it is always the FIRST entry in the report. Every write records the state it replaces on the page's history ring so you can step back to it; this entry says that recording did not happen, so this particular write has no undo point. The write itself landed — treat it as done, not as failed — and the ring's earlier entries are untouched, so everything already in it is still restorable. Check with `wp pp operate composition-history --post_id=N` before you make the next change to that page, because that ring is now the only route back and each further write to the page evicts one more slot from it. It is not counted against the 100-entry budget (it is not a finding about the composition), so read `findings[0].type` for it rather than scanning, and keep reading `total` for the composition report's true size. It can arrive on any accepted composition write, `restore_composition` included — an undo that itself has no undo point is exactly the case worth slowing down for. **In a BATCH it is not, if the batch rolled back**, for the same reason the report above it is not: a step's entry was attached when that step succeeded, before a later step's failure reverted everything, so after `rolled_back: true` it describes a write that no longer exists. Do not go looking for an irreversible change on a page that was in fact restored — re-read the page instead. **And the chat does not show it outside the undo card:** the payload carries it, but only the undo card renders it, so a write losing its undo point during an ordinary chat edit is visible on the CLI envelope and in the server log and nowhere else.

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

1. **Pass the run token.** Every `wp pp operate inspect` returns a `run_id`. Pass it via `--run-id` to the commands that take it (see the `--run-id` column of the command table below); those commands fail without it.
2. **Inspect before editing.** Never modify state without reading it first.
3. **Preflight before mutating.** Never change the site's design or content without a completed PREFLIGHT covering the target; typed actions and `operate patch` are gated, not just file applies. The writes that need no PREFLIGHT are documented where they live: the run bookkeeping (the INSPECT step for `inspect`, the PREFLIGHT step for `apply preflight`), the corrupt-page repair carve-out (ruling D-1, in the EDIT step), and the command table's commands with no `--run-id`, each of which says what it writes.
4. **Screenshot before reviewing.** Visual verification is evidence, not assumption.
5. **Hard gate failure loops to PLAN, not EDIT.** Rethink the approach, don't just retry.
6. **Never claim VERIFIED without screenshots and a fully evaluated checklist.**
7. **Never claim VERIFIED with partial viewport coverage** unless the playbook declared only those viewports.
8. **Escalate drift conflicts.** If drifted files overlap with your planned mutations, stop and ask the human.
9. **Record everything.** The handoff report is the contract with the human.

## Enforcement Layers

Two complementary enforcement mechanisms protect the loop:

1. **Run tokens (real-time ordering):** `wp pp operate inspect` records run state (completed steps) in a per-run row in the install's options table. Mutating commands (`action execute`, `operate patch`, `apply preflight`, `apply execute`, `apply restore`, `apply restore-composition`, `apply reset`) require `--run-id` and check that recorded state before proceeding. This prevents out-of-order CLI calls, and because the state lives in the database it is shared across separate CLI invocations even when each runs in its own ephemeral container (#409).

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

**Page addressing (#726).** Every `wp pp` command that targets one page takes `--post_id=<id>` — a numeric post ID in canonical decimal form, never a slug, a URL, or a positional argument. Since 1.15.13 that is enforced identically on every such command (nine since #1194 added `check acknowledge` and `check unacknowledge`), so `--post_id=00019` or `--post_id=about-us` is refused by name instead of being read as a different page (or, on `apply preflight`, silently downgrading a page-scoped preflight to site scope). Get IDs from `wp pp operate inspect`'s page map. Full contract: `docs/reference-apply-cli.md`.
| `wp pp action execute <name> --run-id=<uuid> --params='...'` | EDIT | Required | Execute a typed action (needs a PREFLIGHT covering its target) |
| `wp pp operate patch --post_id=<id> --target=... --value=... --run-id=<uuid>` | EDIT | Required (mutation) | Patch a composition field (needs a PREFLIGHT covering the page; `--preview` is read-only and ungated) |
| `wp pp apply execute <name> --run-id=<uuid> --params='...'` | APPLY | Required | Commit a typed apply (DB-backed token/font override) |
| `wp pp apply restore --run-id=<uuid> [--token=<name>]` | APPLY | Required | Per-run rollback: reverts the tokens THIS run changed (primary + derived) to the snapshot frozen at the run's preflight; tokens the run never touched are preserved. `--token` restores that token and its derived family from the snapshot. Short-lived: only works within the run-token TTL; fails closed (changes nothing) if the snapshot is missing/expired/corrupt or from another install — it never falls back to product defaults. |
| `wp pp apply restore-composition --run-id=<uuid>` | APPLY | Required | Per-run composition rollback: rewrites every page THIS run changed back to the content frozen at its PREFLIGHT (#133); pages changed by another run are never touched |
| `wp pp apply reset --run-id=<uuid> [--token=<name>]` | APPLY | Required | Reset token overrides to product defaults — all, or one with `--token`. This is the deliberate "back to base.css" path, NOT a per-run undo. Use `apply restore` to undo a specific run. |
| `wp pp screenshot capture --post_id=<id> --playbook=<name>` | SCREENSHOT | — | Capture both viewports. Writes PNG files under the screenshot directory (`PP_SCREENSHOT_DIR`, else `pp-screenshots/` in the content directory). Each successful capture then deletes all but the 10 newest `*.png` files in the directory it wrote to, so older runs' captures there are removed |
| `wp pp screenshot capture --capture-url=<url> --width=<px>` | SCREENSHOT | — | Capture single URL. Writes a PNG file to the screenshot directory, or to `--output=<path>`. A successful capture then deletes all but the 10 newest `*.png` files in that file's directory, including a directory you chose with `--output` |
| `wp pp screenshot doctor [--no-probe]` | SCREENSHOT | — | Diagnose capture readiness — tri-state available/unavailable/broken (probes by default). The probe writes a temporary PNG in the screenshot directory (creating the directory if missing) and deletes it; a successful probe also deletes all but the 10 newest `*.png` files in that directory. `--no-probe` writes nothing |
| `wp pp schema` | any | — | Read-only: every registered component and whether it is composable (`nav`/`footer` are template-owned chrome) |
| `wp pp schema <component>` | any | — | Read-only: one component's declared props, its **`roles`** (each with its selector, permitted groups, description, `defaults` and obligations, and when declared its `overlay_defaults`, `within` and `text_content`), **`udc_groups`** and **`udc_raw_css`**, plus **`item_roles`** on a component that declares one (which roles a SINGLE entry of its repeater prop may set) — the schema contract without filesystem access (#688). **Read the `roles`: that is a component's entire styling surface, and since #1101 every one of them has zero style slots.** The `style_slots` and `recipes` halves are empty on every component and stay in the report so their emptiness is readable |
| `wp pp readiness status` | any | — | Read-only: current findings grouped by class (integrity/configuration/capability) with per-finding next actions (#496) |
| `wp pp readiness rebaseline` | any | — | Re-baseline the deployment manifest against the installed release (resolves integrity drift). Writes `pp-deployment-manifest.json` in the content directory (`WP_CONTENT_DIR`, normally `wp-content/`) |
| `wp pp readiness acknowledge <finding-key> [--note=<text>]` | any | — | Record a configuration finding as intentional (reversible). Writes the `pp_acknowledged_findings` option |
| `wp pp readiness unacknowledge <finding-key>` | any | — | Reverse an acknowledgement. Writes the `pp_acknowledged_findings` option |
| `wp pp check acknowledge --post_id=<id> --key=<key> --note=<text>` | any | — | Record a page's composition advisory as intentional (#1194; see `validate-site.md`). Writes the page's `_pp_acknowledged_advisories` post meta |
| `wp pp check unacknowledge --post_id=<id> --key=<key>` | any | — | Reverse a page acknowledgement. Writes the page's `_pp_acknowledged_advisories` post meta |
| `wp pp sync check [--save-manifest]` | any | — | Theme-file drift against the deployment manifest. Read-only, except `--save-manifest` writes the deployment manifest (the file `readiness rebaseline` writes) |
| `wp pp integrity check` | any | — | Theme files against the shipped `integrity-manifest.json`. Writes its result to the `pp_theme_integrity` option and, when the result is `safe`, deletes the `pp_last_blocked_update` option |
| `wp pp operate checklist --playbook=<name>` | REVIEW | — | Get playbook checklist |
| `wp pp operate validate --run='...'` | HANDOFF | — | Validate loop run completeness |
