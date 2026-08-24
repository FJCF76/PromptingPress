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
- `composition_decode_error` (if set, the stored composition is corrupt/undecodable or not a list — that data-integrity problem is likely the root cause; don't treat the page as blank). **`wp pp operate inspect-composition` now agrees with this signal instead of contradicting it (#725):** on such a page it exits non-zero and names the classification, where it used to print `[]` and read as "no components". Repair the page with ONE full `update_composition` write (a JSON array of components) or `restore_composition` before doing anything else — do not author into it band by band, and do not treat `[]` from any surface as permission to overwrite. **Issue that repair as its own single action, never as a step inside a multi-step proposal or batch (#749):** a batch is refused before step 1 when any page it names has an unreadable composition, so bundling the repair gets it refused with the same `unexpected_shape`/`decode_error` it was meant to clear. Note the composition must be a JSON **array**; an object keyed by position is refused with `unexpected_shape` (#724).
- Composition smells (may identify the root cause)
- Design tokens (token issues cause visual bugs)
- CSS conflicts (custom CSS may be overriding components)
- **The `run_id`.** `wp pp operate inspect` appends a `run_id` field (a UUID v4) to its JSON output. Capture it — this is your **run token**, passed via `--run-id` to every mutating command below (PREFLIGHT, EDIT, APPLY). A self-generated UUID passes format validation but then fails PREFLIGHT/EDIT because only the token minted by `inspect` records run state. It is install-scoped and expires 2 hours after `inspect`; re-run `inspect` if it expires. Full contract: `docs/reference-apply-cli.md`.

Capture a **broken-state screenshot**: `wp pp screenshot capture --post_id=<page_id> --playbook=inspect-fix`

Correlate the reported issue with the inspect data. Note what you see in the screenshot vs. what was reported.

### 2. PLAN
Diagnose the root cause. Common categories:
- **Composition issue**: Wrong props, missing component, wrong layout/theme
- **Token issue**: Incorrect design token value (color, spacing, font)
- **CSS conflict**: Custom CSS overriding component styles
- **Content issue**: Wrong text, missing image URL, broken link

Declare the fix:
- Which component/prop/token to change
- Why this is the root cause (not just a symptom)
- What the fixed state should look like

### 3. PREFLIGHT
Run `wp pp apply preflight --run-id=<uuid>`, adding `--post_id=<page_id>` when the fix mutates a page's composition (and planned_files for file mutations). `<uuid>` is the `run_id` you captured from INSPECT, not a freshly generated UUID. This records the PREFLIGHT covering the target and unlocks the fix in EDIT.

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
