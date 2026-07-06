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

Capture a **before-screenshot**: `wp pp screenshot capture --post_id=<page_id> --playbook=revise-section`

### 2. PLAN
- Identify the exact component index to modify
- Declare which props will change
- Note which other sections should remain unchanged (regression check)
- If file mutations are needed, list planned_files

### 3. PREFLIGHT
Run `wp pp apply preflight --run-id=<uuid> --post_id=<page_id>` (add planned_files if file mutations are needed). This records a PREFLIGHT covering the page and unlocks the typed mutation in EDIT. Without it, the edit refuses to run.

### 4. EDIT
Use `update_component` (patch semantics — only the props you pass change) targeted by stable `component_id` or `component_index`, or `wp pp operate patch` with a semantic selector for a single field. Do not rewrite the entire composition via `update_composition` — modify only the target section. Both paths are gated: they require the page-covering PREFLIGHT from step 3 and a `--run-id`.

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
- **Mobile breakage**: Desktop-focused revision breaks the mobile layout
