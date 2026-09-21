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
    the band's `udc` map, and on the nine v2 components that is the ONLY way to restyle
    a band — they have no style slots and no `theme` prop
- Declare which props and/or which roles, groups and parameters will change
- Note which other sections should remain unchanged (regression check)
- If file mutations are needed, list planned_files

### 3. PREFLIGHT
Run `wp pp apply preflight --run-id=<uuid> --post_id=<page_id>` (add planned_files if file mutations are needed). `<uuid>` is the `run_id` you captured from INSPECT, not a freshly generated UUID. This records a PREFLIGHT covering the page and unlocks the typed mutation in EDIT. Without it, the edit refuses to run.

### 4. EDIT
**A content revision** uses `update_component` (patch semantics — only the props you pass change) targeted by `component_id` (prefer an authored `id` prop — auto-generated `pp-<hex8>` ids are stable across this in-place path but not across a full `update_composition` re-apply) or `component_index`, or `wp pp operate patch` with a semantic selector for a single field. Modify only the target section.

**A styling revision goes through `update_composition`, and that is not a violation of the rule above — it is the only route the engine offers.** Exactly two actions carry a `udc` map: `update_composition` and `create_page`. `update_component` declares `post_id`, `component_index`, `component_id`, `props`, `style` and `expected_version` — no `udc`; `add_component` and `style_component` likewise carry `style` and no `udc`. And `style` is not a substitute: it addresses style SLOTS, which only `grid` has, so on any of the nine v2 components those calls are refused with `no_style_slots`. That leaves the whole-composition write as the only way to restyle a v2 band. So a `udc` edit is necessarily a read-modify-write of the WHOLE composition:

1. `wp post meta get <page_id> _pp_composition_version`, then
   `wp post meta get <page_id> _pp_composition`. **Read the meta here, not
   `inspect-composition`** — that report returns per-field patch targets and carries no `udc` at
   all, so it cannot give you the map you are about to edit. Reading the meta is safe; writing it
   is what skips validation, band-id minting, versioning and history. **Version first, then the
   bytes** — the other order builds the race it is meant to close, and read the `expected_version`
   note in `style-component.md` before relying on the check to save you
2. edit the target band's `udc` map in place, leaving every other band's bytes untouched
3. send the whole array back with `update_composition`

The regression risk the "do not rewrite the composition" rule was guarding against is real and it is now YOUR job rather than the action's: step 2's "which other sections should remain unchanged" is the check, and the after-screenshot in step 6 is where you confirm it. Re-read the composition immediately before writing rather than reusing an array you read earlier in the session, so a concurrent edit is not silently clobbered.

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
- **Styling attempted through the wrong action, and NOT told so.** A `theme` prop or a `style`
  map sent to `update_component` is refused by name (`retired_prop` / `no_style_slots`). A
  **`udc` key is not**: it is an undeclared parameter, so the validator never examines it — the
  call returns `ok: true` with `findings: []`, the props land, and the styling is dropped with
  no code and no trace. An agent that reads an empty `findings` array as confirmation (which
  every other page here tells it to do) has no signal its styling never happened. Send `udc`
  through `update_composition`, and after any styling write re-read the composition and confirm
  the map is actually there
- **Stale-array clobber**: Agent reuses a composition it read earlier in the session for the
  read-modify-write, discarding a change made in between
- **Mobile breakage**: Desktop-focused revision breaks the mobile layout
