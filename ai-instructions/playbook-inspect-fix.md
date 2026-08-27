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
- `composition_decode_error` (if set, the stored composition is corrupt/undecodable or not a list — that data-integrity problem is likely the root cause; don't treat the page as blank). **`wp pp operate inspect-composition` now agrees with this signal instead of contradicting it (#725):** on such a page it exits non-zero and names the classification, where it used to print `[]` and read as "no components". Repair the page with ONE full `update_composition` write (a JSON array of components) or `restore_composition` before doing anything else — do not author into it band by band, and do not treat `[]` from any surface as permission to overwrite. **Since #836 the repair's own preview and receipt say so as well:** on a page in this state, `update_composition` and `restore_composition` report `changes[].from` (and preview's `before`) as `{"unreadable": true, "classification": "...", "message": "..."}` instead of `[]`, so the before side of the diff you are about to approve names the corruption rather than describing the page as empty. Branch on `unreadable` before treating `from` as a list; a genuinely blank page still reports `[]`, because that is its truth. **That repair write no longer destroys the unreadable bytes (#818):** it preserves them on the page's history ring, so after repairing you can still read what was there with `wp pp operate composition-history --post_id=<id>` — `raw_base64` is the exact copy and `raw_sha256` verifies it. (Exact for every slot written by 1.17.1 and later. A slot an older release mis-filed as an object-shaped snapshot is reclassified on read and hands back that object RE-ENCODED, not the page's own bytes — #841.) That slot is not restorable (it holds bytes, not a composition), so step past it to roll the page back to an earlier composition. **Send that repair as a proposal of its OWN — one step, nothing alongside it (#756).** A batch is refused before step 1 when any page it names has an unreadable composition, and the chat client routes every proposal through the batch endpoint; ruling D-1 carves out exactly one shape from that refusal, and it is the repair itself — a lone `update_composition` or `restore_composition` step on a page already classified `unexpected_shape`/`decode_error`. Add any second step and the whole proposal is refused again. The same repair also runs from a surface that takes no rollback snapshot: **`wp pp action execute update_composition` or `wp pp action execute restore_composition`** on the CLI, or the **dashboard composition editor**. Those two CLI verbs need **no preflight** on a page in this state — `wp pp apply preflight` refuses a corrupt page outright, so requiring one would make the repair unreachable; every other gate still applies (run token, `INSPECT` first, and full validation of the array you send). `pp_patch_composition()` / `wp pp operate patch` is **not** a repair route despite older wording: it is refused on a corrupt page by the same precondition as the band-level actions (#748), and a field selector cannot reshape a container in any case. Note the composition must be a JSON **array**; an object keyed by position is refused with `unexpected_shape` (#724).
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
