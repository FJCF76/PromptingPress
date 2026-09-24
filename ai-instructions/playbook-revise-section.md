# Playbook: Revise Existing Section

Targeted revision of a specific section on an existing page. INSPECT reads current state + captures a before-screenshot. EDIT targets only the specific component. SCREENSHOT captures before/after.

## Preconditions

- An existing page with a composition
- A clear description of what to change and which section
- Agent has read `operating-loop.md`

## Loop

### 1. INSPECT
Run `wp pp operate inspect --post_id=<page_id>`. Review:
- Current composition (identify the target section by index)
- Composition smells (may reveal existing issues)
- Design tokens (ensure revision is consistent)
- **The `run_id`.** `wp pp operate inspect` appends a `run_id` field (a UUID v4) to its JSON output. Capture it — this is your **run token**, passed via `--run-id` to every mutating command below (PREFLIGHT, EDIT, APPLY). A self-generated UUID passes format validation but then fails PREFLIGHT/EDIT because only the token minted by `inspect` records run state. It is install-scoped and expires 2 hours after `inspect`; re-run `inspect` if it expires. Full contract: `docs/reference-apply-cli.md`.

Capture a **before-screenshot**: `wp pp screenshot capture --post_id=<page_id> --playbook=revise-section`

### 2. PLAN
- Identify the exact component index to modify
- **Decide which KIND of revision this is, because the two take different actions:**
  - a **content** revision (text, images, URLs, `layout`) changes `props`
  - a **styling** revision (type, colour, spacing, border, radius, shadow, tone) changes
    the band's `udc` map, and on the ten v2 components that is the ONLY way to restyle
    a band — no component has style slots or a `theme` prop since #1101
- Declare which props and/or which roles, groups and parameters will change
- Note which other sections should remain unchanged (regression check)
- If file mutations are needed, list planned_files

### 3. PREFLIGHT
Run `wp pp apply preflight --run-id=<uuid> --post_id=<page_id>` (add planned_files if file mutations are needed). `<uuid>` is the `run_id` you captured from INSPECT, not a freshly generated UUID. This records a PREFLIGHT covering the page and unlocks the typed mutation in EDIT. Without it, the edit refuses to run.

### 4. EDIT
**A content revision** uses `update_component` (patch semantics — only the props you pass change) targeted by `component_id` (prefer an authored `id` prop — auto-generated `pp-<hex8>` ids are stable across this in-place path but not across a full `update_composition` re-apply) or `component_index`, or `wp pp operate patch` with a semantic selector for a single field. Modify only the target section.

**A styling revision also uses `update_component`, with a `udc` param (#1088).** It is merged into the target band's stored map **by role**: each role you send replaces that role's map whole, `null` removes a role, and every role you do not send stays exactly as stored. It is the same rule `props` follows. Send `props`, `udc` or both in one call; a call carrying neither (and no `style`) is refused with `missing_component_update`. So a restyle touches only the target band, and a concurrent edit to another band is not a conflict:

```bash
wp pp action execute update_component --run-id=<uuid> --params='{"post_id":42,"component_id":"pp-a1b2c3d4","udc":{"heading":{"typography":{"color":"#ffffff"}}},"expected_version":7}'
# 42 = the page, pp-a1b2c3d4 = the band's id, 7 = the version you read before the bytes
```

Two things to get right:

1. **Send the whole ROLE you are changing.** The role is the unit that is replaced, so a role you send with only `color` loses the `weight` it had. Read the band's current map first — `wp post meta get <page_id> _pp_composition` (read the VERSION first, `wp post meta get <page_id> _pp_composition_version`; `inspect-composition` carries no `udc` at all) — and send the role with every value you want to keep.
2. **Pass `expected_version`** on the CLI. It is honoured there since #1094 (the in-admin chat supplies its own per-conversation baseline instead), so an edit landed since you read is refused with `composition_conflict` instead of overwritten. On a conflict: re-read, re-apply, retry.

Values the engine minted for a responsive literal (`@heading-typography-size-d` and the band `_tokens` entry behind it) are its own: when you replace or remove the role that used them, the engine drops the tokens nothing references any more. A `_tokens` key you send yourself replaces the band's token map whole and is yours to keep consistent.

`update_composition` still carries `udc` too. Use it when you are rewriting the page, not to restyle one band. The regression check is the same either way: step 2's "which other sections should remain unchanged", confirmed by the after-screenshot in step 6.

Both paths are gated: they require the page-covering PREFLIGHT from step 3 and a `--run-id`.

### 5. APPLY
Execute any token/font applies needed for the revision (`wp pp apply execute <name> --run-id=<uuid> --params='...'` — needs a site-scoped preflight).

### 6. SCREENSHOT
Run `wp pp screenshot capture --post_id=<page_id> --playbook=revise-section`

This captures the after-state. Compare with the before-screenshot from INSPECT.

### 7. REVIEW
Get checklist: `wp pp operate checklist --playbook=revise-section`

| ID | Description | Gate | Viewport |
|---|---|---|---|
| target_section_changed | The target section reflects the requested changes | hard | desktop |
| no_regression | Other sections unchanged from before-screenshot | hard | desktop |
| mobile_readable | Revised section is readable at 375px | hard | mobile |
| no_empty_sections | No sections render as empty/blank | hard | desktop |
| brand_tokens_applied | Brand colors and typography consistent after revision | soft | desktop |

If a **hard gate** fails: loop back to PLAN. Maximum 2 retries.

### 8. HANDOFF
Report:
- Status
- Before/after screenshot paths
- Which component was modified (index, type, changed props)
- Checklist results
- Drift state at handoff

## Common Failure Modes

- **Wrong section modified**: Agent targets the wrong component index
- **Regression in adjacent section**: Rewriting composition clobbers unrelated sections
- **Revision too broad**: Agent rewrites the entire page instead of the target section
- **Styling through a retired surface.** A `theme` prop or a `style` map sent to
  `update_component` is refused by name (`retired_prop` / `no_style_slots`); the design goes in
  `update_component`'s `udc` param (section 4)
- **A partial role in `udc`.** The role you send REPLACES that role's stored map whole, so a role
  sent with only `color` drops the `weight` it had — with `ok: true`, because that is the
  documented merge. Read the band first and send each role you touch with every value it should
  keep; after the write, re-read the band and confirm the map is what you meant
- **Stale-array clobber**: Agent reuses a composition it read earlier in the session for the
  read-modify-write, discarding a change made in between
- **Mobile breakage**: Desktop-focused revision breaks the mobile layout
