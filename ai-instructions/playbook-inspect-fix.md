# Playbook: Inspect and Fix

Diagnose and fix a reported issue. INSPECT includes a screenshot of the current broken state. PLAN diagnoses root cause. REVIEW confirms the fix with no regressions.

## Preconditions

- A reported issue (visual bug, broken layout, wrong content, etc.)
- The page where the issue appears
- Agent has read `operating-loop.md`

## Loop

### 1. INSPECT
Run `wp pp operate inspect --post_id=<page_id>`. Review:
- Current composition (look for structural issues)
- Composition smells (may identify the root cause)
- Design tokens (token issues cause visual bugs)
- CSS conflicts (custom CSS may be overriding components)

Capture a **broken-state screenshot**: `wp pp screenshot capture --post_id=<page_id> --playbook=inspect-fix`

Correlate the reported issue with the inspect data. Note what you see in the screenshot vs. what was reported.

### 2. PLAN
Diagnose the root cause. Common categories:
- **Composition issue**: Wrong props, missing component, wrong variant
- **Token issue**: Incorrect design token value (color, spacing, font)
- **CSS conflict**: Custom CSS overriding component styles
- **Content issue**: Wrong text, missing image URL, broken link

Declare the fix:
- Which component/prop/token to change
- Why this is the root cause (not just a symptom)
- What the fixed state should look like

### 3. PREFLIGHT
Run `wp pp apply preflight --run-id=<uuid>`, adding `--post_id=<page_id>` when the fix mutates a page's composition (and planned_files for file mutations). This records the PREFLIGHT covering the target and unlocks the fix in EDIT.

### 4. EDIT
Execute the minimal fix. Do not refactor adjacent code. Do not "improve" unrelated sections. A composition fix is gated: it requires the page-covering PREFLIGHT from step 3.

### 5. APPLY
Execute any token/font applies the fix requires (`wp pp apply execute <name> --run-id=<uuid> --params='...'` — needs a site-scoped preflight).

### 6. SCREENSHOT
Run `wp pp screenshot capture --post_id=<page_id> --playbook=inspect-fix`

Compare with the broken-state screenshot from INSPECT.

### 7. REVIEW
Get checklist: `wp pp operate checklist --playbook=inspect-fix`

| ID | Description | Gate | Viewport |
|---|---|---|---|
| issue_resolved | The reported issue is no longer visible | hard | desktop |
| no_regression | No new visual issues introduced by the fix | hard | desktop |
| mobile_no_regression | Fix does not break mobile layout | hard | mobile |
| root_cause_addressed | Fix addresses root cause, not just symptom | soft | any |

If `issue_resolved` fails: loop back to PLAN with updated diagnosis. The first diagnosis was wrong.

### 8. HANDOFF
Report:
- Status
- Root cause diagnosis
- Fix applied (actions/applies)
- Before/after screenshot paths
- Checklist results
- Whether the fix is minimal (no scope creep)
- Drift state at handoff

## Common Failure Modes

- **Wrong diagnosis**: Agent fixes a symptom, not the root cause. Issue reappears in another form.
- **Overcorrection**: Fix solves the issue but breaks something else
- **Scope creep**: Agent "improves" unrelated sections while fixing the bug
- **CSS conflict missed**: Issue is caused by custom CSS, but agent modifies the composition instead
