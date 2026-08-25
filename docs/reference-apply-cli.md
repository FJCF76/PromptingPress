# 📦 Reference — the `wp pp apply` command family

`wp pp apply` is the WP-CLI surface an AI agent (or an operator) uses to change PromptingPress **design tokens** safely: preview a change, freeze a rollback point, apply it, and roll it back per-run if needed. Every mutating subcommand is gated by a **run token** and a completed **preflight**, so a token write can't land without a recorded way to undo it.

This page is the complete, factual surface: every subcommand, its flags, its JSON output shape, its exit codes, and the exact error messages you'll see. For *why* the gate is built this way, read the [operating-loop safety explanation](operating-loop-safety.md). For the step-by-step apply→rollback walkthrough, read [How to apply a token change and roll it back](howto-apply-and-rollback.md).

Command registration: `WP_CLI::add_command('pp apply', 'PP_Apply_Command')` (`lib/cli.php`).

---

## The run token (read this first)

Every mutating subcommand (`execute`, `restore`, `reset`, `preflight`) requires `--run-id=<uuid>`. You get one from:

```bash
wp pp operate inspect
```

`inspect` returns the site's operating picture as JSON with a `run_id` field appended. That `run_id` is:

- **A UUID v4.** `--run-id` is rejected with `--run-id must be a valid UUID v4. Got: "<value>"` if it isn't (`pp_operate_valid_run_id`).
- **Install-scoped and time-limited.** The run state is stored per-install and auto-expires **2 hours** after creation (`PP_OPERATE_RUN_TTL = 7200`, `lib/operate.php`). An expired, swept, corrupt, or wrong-install token fails closed on the commands that need it.
- **The carrier of run state:** which steps completed (`PREFLIGHT`, `APPLY`), the pre-apply token snapshot, and the touched-token trail that `restore` replays.

Pass the same `run_id` to `preflight`, then to `execute`/`reset`, then to `restore`.

---

## How every `wp pp` command prints its JSON (#717)

One contract, all of them, because a parser should not need to know which command it is talking to.

**The document is always printed.** Before #717 each command called `json_encode()` inline and nothing checked the return. `json_encode()` answers `false` — not a string — on malformed UTF-8, on nesting past 512 levels, on recursion; WP-CLI renders that `false` as an **empty line**, and on `wp pp action execute` the `Success:` line still followed it. A write that had already landed reported nothing at all: no `ok`, no `error_code`, no `composition_version`, no `changes`, no `findings`. Now a bad byte is replaced with U+FFFD and the document survives intact.

**When it still cannot encode, you get a short document, never a blank line.** Depth and recursion are not repairable, so the command prints what it can: every top-level value that still encodes, plus two fields that say what happened.

```json
{ "ok": true, "action": "update_component", "composition_version": 7,
  "envelope_error": "This document could not be encoded as JSON (Maximum stack depth exceeded). ...",
  "omitted_keys": ["changes", "findings"] }
```

Read `omitted_keys` before you read anything else. **A key listed there is UNKNOWN, not empty** — `findings` is a container, so it is exactly the kind of field that gets dropped, and treating an absent `findings` as `[]` would read a clean bill of health for diagnostics that were never encoded. (Same trap the `findings_skipped` entry closes on the write path.) `ok` still tells you whether your write landed, in every case.

**Non-ASCII arrives `\u`-escaped.** `Café` prints as `"Caf\u00e9"`, an em dash as `\u2014`. This is the same JSON string — any parser decodes it back to the original bytes, nothing is lost — and it is what `wp pp operate patch`, `check surface`, `validate`, `sync check` and `integrity` already did. `wp pp action execute` and the `wp pp apply` family used to print those characters literally, which meant the two surfaces #687 documents together emitted **different bytes for identical content**, and meant a component or slot name raw-written into a composition could hand your terminal a live U+202E (RIGHT-TO-LEFT OVERRIDE) that reverses the rest of the line. Escaping settles both: one representation everywhere, and nothing the terminal acts on. Control characters were already escaped by JSON itself, with one exception — `DEL` (U+007F), which the sink now escapes explicitly — so the emitted bytes are printable ASCII.

**One command is deliberately exempt:** `wp pp schema` still prints literal characters, because its whole job is handing an agent readable prose out of `schema.json` (see [`wp pp schema`](#wp-pp-schema--the-component-contract-688)). That report is built from the shipped component registry only — no stored bytes reach it.

---

## How every `wp pp` command addresses a page (#685, #726)

One contract, all seven of them, for the same reason as the JSON above: you should not have to learn which third of the CLI you are talking to.

**A page is addressed by `--post_id=<id>` and nothing else.** No positional argument, no slug, no URL. Seven commands are page-addressed:

| Command | `--post_id` |
|---|---|
| `wp pp check page` | required |
| `wp pp validate page` | required |
| `wp pp operate inspect-composition` | required |
| `wp pp operate patch` | required |
| `wp pp operate composition-history` | required |
| `wp pp apply preflight` | optional (absent = site-scoped) |
| `wp pp screenshot capture` | optional (`--capture-url=<url>` is the other door) |

**The value must be the canonical decimal form of a positive post ID.** ⚠️ **Breaking change in 1.15.13** on `check page`, `validate page`, `apply preflight` and `screenshot capture`, which previously took a loose `(int)` cast. `--post_id=00019`, `--post_id=19abc` and `--post_id=1.5` used to be silently read as 19, 19 and 1; a bare `--post_id` used to become `1`. They are now refused by name. The other three commands have enforced this since #685, so this closes the gap rather than opening one — the fix is to pass the ID exactly as `wp pp operate inspect`'s page map reports it.

**No refusal ever claims a supplied argument is missing.** Three branches, and which one you get says exactly what went wrong:

```
# never typed it
`wp pp check page`: --post_id is required. Pages are addressed by numeric WordPress post ID.
Use the flag form `wp pp check page --post_id=<id>` with a numeric page ID; run `wp pp operate inspect` for the page map...

# typed it with no usable value (`--post_id`, `--no-post_id`, `--post_id=`)
`wp pp check page`: --post_id was supplied without a value. ...

# typed it with a value that is not a post ID
Invalid --post_id "about-us" for `wp pp check page`. Pages are addressed by numeric WordPress post ID only
— slugs and URLs are not resolved. ...
```

Before 1.15.13 the second and third cases both answered `--post_id is required.` for a flag that was on the command line, which sent an agent looking for a flag it had already passed.

**A positional page argument is refused before dispatch, with the corrected command.** WP-CLI's own `Too many positional arguments: 234` never names the flag, so a `before_run_command` hook replaces it on all seven:

```
`wp pp check page` takes no positional page argument (got "234").
Address the page with the flag form: `wp pp check page --post_id=<id>`.
For this call, the page part is `wp pp check page --post_id=234`.
```

The composed line corrects the **addressing** only — it does not supply whatever else the command needs (`--run-id` on preflight, `--target`/`--value` on patch), and the refusals for those two commands say so.

**`wp pp operate inspect` is not in the table and is not page-addressed.** Its subject is the site; `--post_id` there is an enrichment filter that adds page-specific smells to a site report. It still takes the older loose cast — tracked as #760.

---

## `wp pp operate inspect` — the INSPECT output

`inspect` is the read-only INSPECT step of the operating loop: one call returns the whole operating picture and mints the run token. It never mutates the site (it does write a run-state row, the same as any `inspect` — see the run token above).

```bash
wp pp operate inspect
wp pp operate inspect --post_id=42
```

**Options**

- `--post_id=<id>` — include page-specific composition smells (and a page-level composition integrity check) for this post. Without it, the page-scoped fields (`smells`, `composition_decode_error`) stay at their empty/`null` defaults.

**Output** — the operating picture as pretty JSON. Every top-level field `pp_inspect_site()` returns (`lib/operate.php`), plus the `run_id` the CLI appends (`PP_Operate_Command::inspect`, `lib/cli.php`):

| Field | Shape | What it is |
|---|---|---|
| `target` | `{site_url, wp_root, theme_path, environment}` | The canonical mutation target, auto-resolved from WordPress state (`pp_get_target`). A field is `null` when it can't be resolved. |
| `pages` | array of `{id, title, status, url}` | Every page using the Composition template (`composition.php`), any status, title-sorted (`pp_composition_pages`). |
| `drift` | `{has_drift, modified, added, deleted, release_version}` (`error` added when the theme dir is unreadable) | Theme-file drift vs the deployment manifest (`pp_check_drift`). `release_version` is the installed release the baseline was captured against (#496; `null` on manifests written before that, or no baseline). No manifest baseline ⇒ `has_drift:false` with empty arrays (it never creates one). |
| `preflight` | `{ok, checks[], findings}` | A **site-grain** preflight snapshot computed with no planned files and no post (`pp_preflight`). Advisory situational awareness — the gate that actually unlocks a mutation is `wp pp apply preflight --run-id=…`, not this. `findings` groups the warning-grade rows by class (#496 — see `wp pp readiness` below). |
| `tokens` | map of `--token` ⇒ `{value, type}` | Design tokens parsed from `base.css :root {}`, with the type from each token's structured comment (`pp_design_tokens`). |
| `conflicts` | array of `{selector, component}` | WordPress Custom CSS selectors that target PP component classes (`pp_check_custom_css_conflicts`). Report-only; `[]` when there are none. |
| `smells` | array of `{type, message, index}` | Page composition smells for `--post_id` (`pp_validate_composition_smells`): hero/layout/wall-of-text advisories, `empty_section` (a band whose configured content renders nothing — covers every band component since #579, not just the five structured-content ones), `transparent_fill` (a `role: "fill"` slot set to `transparent`/`currentColor`, i.e. an invisible-but-clickable button; warn-only, `transparent` stays an accepted value), `inert_slot` (a slot whose declared `applies_when` condition is unmet on this component, so the stored value renders nothing — the message names the slot and every unmet clause; warn-only, the write is never rejected and the value is stored as authored), plus `template_owned_component` / `duplicate_component_id` on a page whose stored composition predates those rules. `[]` when no `--post_id` is given, the page's composition is empty, or the page is corrupt (a corrupt page is reported via `composition_decode_error`, not here). |
| `token_smells` | array of `{type, base_token, token, current, expected, message}` | Masked derived-family overrides (#386, `pp_detect_masked_derived_smells`): a derived override (e.g. `--color-accent-strong`) that diverges from what its base (`--color-accent`) currently derives, so a base change won't show where the override applies. Always computed (site-scoped, independent of `--post_id`); `[]` on a coherently themed site. |
| `composition_decode_error` | `null` \| `"decode_error"` \| `"unexpected_shape"` | Page composition **integrity** for `--post_id` (#144). Always present in the output; only ever non-`null` when `--post_id` names a page whose stored `_pp_composition` is corrupt rather than genuinely empty (see below). |
| `run_id` | UUID v4 string | The run token this `inspect` minted, appended by the CLI. Pass it as `--run-id` to every mutating subcommand. |

### `composition_decode_error` in detail (#144)

A page with no composition and a page with a *corrupted* composition both look empty to a naive reader — the same `smells: []`. `composition_decode_error` tells them apart so an agent relying on INSPECT before a mutation is warned about data corruption instead of treating a broken row as a clean, blank page. It is set from the state-classifying decoder `pp_get_composition_result()` (`lib/wp.php`), the single owner of composition decode + classification:

| Value | Meaning | When |
|---|---|---|
| `null` | No integrity problem. | No `--post_id`; or the page's `_pp_composition` is absent/blank (a genuinely empty page); or it decodes to a valid JSON list (a real composition). |
| `"decode_error"` | The stored `_pp_composition` is present but **not decodable JSON** (truncated write, encoding bug, malformed UTF-8). | `--post_id` given and the raw row fails `json_decode`. |
| `"unexpected_shape"` | The stored value **decodes but is not a list** — a JSON object or scalar, a non-string scalar meta, or an already-decoded non-list array. | `--post_id` given and the decoded value isn't a sequential-keyed list. |

When it is non-`null`, `smells` is `[]` (the corrupt row can't be walked for smells) — read the integrity field, not the empty smell list, to decide the page is broken. The rendering paths are unaffected: `pp_get_composition()` still degrades any corrupt or non-list row to `[]`, so templates never fatal on a bad row; only these read/validate surfaces surface the distinction. `wp pp check page`, `wp pp validate site`, and `wp pp validate page` report the same integrity error rather than "no composition."

---

## Subcommand summary

| Subcommand | Mutates? | `--run-id` | Needs prior PREFLIGHT | Purpose |
|---|---|---|---|---|
| `list` | no | no | no | List registered applies |
| `preview` | no | no | no | Validate + show the diff, never writes |
| `preflight` | no (records run state) | **yes** | — | Validate the execution surface; freeze the rollback snapshot |
| `execute` | **yes** | **yes** | yes | Apply a named change |
| `reset` | **yes** | **yes** | yes | Clear overrides back to product defaults |
| `restore` | **yes** | **yes** | yes | Roll this run's token changes back to its preflight snapshot |
| `restore-composition` | **yes** | **yes** | yes | Roll this run's page-composition changes back to their pre-run state (#133) |

> **Tokens vs compositions.** `execute` / `reset` / `restore` operate on **design tokens**. Page **compositions** (the component arrays that make up a page) are mutated through the `wp pp action` family (`update_composition`, `add_component`, `remove_component`, …) and rolled back with `restore-composition` (run-scoped) or the `restore_composition` action (single page). Both surfaces share one run token and the same preflight discipline.

---

## `wp pp apply list`

Lists every registered apply with its domain, target, description, and parameters.

```bash
wp pp apply list
```

Read-only. No run token. Prints a table (`name`, `domain`, `target`, `description`, `params`); a `*` after a param marks it required. Prints `No applies registered.` (warning) if the registry is empty.

The token-domain applies you'll use with this family:

| Apply name | Params | Effect |
|---|---|---|
| `update_design_token` | `token` (required), `value` (required) | Set one design-token override |
| `reset_design_token` | `token` (required) | Clear one token override → product default |
| `reset_all_design_tokens` | — | Clear all token overrides → product defaults |

(The registry also carries font and media applies — `enqueue_font`, `remove_font`, `reset_fonts`, `import_media` — registered in `lib/apply.php`. This page focuses on the token surface that `execute`/`reset`/`restore` operate on. `import_media` takes EITHER a remote `url` OR a server-local absolute `file` — exactly one; the `file` source, #490, lets brand-kit assets that live on the operator machine join the same journalled surface without raw `wp media import`.)

---

## `wp pp apply preview`

Validates an apply and shows the diff it *would* make. **Never writes.** No run token required.

```bash
wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'
```

**Options**

- `<name>` — the apply name (positional, required).
- `--params=<json>` — JSON object of apply parameters.

**Requires** the apply capability (`_pp_cli_require_apply_cap`).

**Output** — the apply result as pretty JSON:

```json
{
  "ok": true,
  "apply": "update_design_token",
  "domain": "design",
  "target": { ... },
  "changes": [ { "token": "--color-accent", ... } ],
  "error": null
}
```

**Exit codes** — `0` on success; on a validation error it prints `{"ok":false,"error":"<message>"}` and exits **1** (`WP_CLI::halt(1)`).

---

## `wp pp apply preflight`

Validates the whole execution surface **before** any mutation and, on success, records the `PREFLIGHT` step plus the pre-apply **token snapshot** that `restore` rolls back to. This is the gate that every mutating subcommand checks.

```bash
wp pp apply preflight --run-id=<uuid>
wp pp apply preflight --run-id=<uuid> --planned-files='["assets/css/base.css"]'
wp pp apply preflight --run-id=<uuid> --apply=update_design_token
wp pp apply preflight --run-id=<uuid> --post_id=42
```

**Options**

- `--run-id=<uuid>` — **required.** Run token from `wp pp operate inspect`.
- `--planned-files=<json>` — JSON array of file paths the agent intends to modify. Enables drift-overlap detection. Without it, drift is a warning only.
- `--post_id=<id>` — target page post ID. Adds the `target_page` check and scopes coverage to that post.
- `--apply=<name>` — named apply. Auto-populates `planned_files` from a file-based apply's target; a media-target apply (`import_media`) enables the `uploads_writable` check (#229). A name that matches no registered apply **fails preflight closed** via the `apply_known` check (issue 245) — a typo is never treated as "no apply planned."

**Coverage grain.** A preflight with `--post_id=N` covers mutations on post N; a preflight with no post covers **site-grain** changes. They don't substitute for each other — a page mutation needs a page preflight, a site mutation needs a site preflight. This is what `execute`/`action execute`/`operate patch` check.

**Composition freshness (#113).** A `--post_id=N` preflight also records the page's composition **freshness marker** — a `{version, hash}` pair bumped on every composition write (`_pp_composition_version` / `_pp_composition_hash`). A composition-mutating `action execute` (`update_composition`, `add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`) or `operate patch` re-reads the live marker and **rejects** if the composition changed since your preflight — coverage proves a preflight *ran* for the target, freshness proves the target is *unchanged since*:

> `Stale preflight for post N: the composition changed since preflight (preflight version X, live version Y). Another path (a CLI action, the dashboard editor, or publish flow) modified it. Re-inspect and re-run 'wp pp apply preflight --run-id=<uuid> --post_id=N' before executing. [composition_conflict]`

Your own run's sequential composition mutations are fine — the baseline refreshes to the new marker after each successful write. Only a change from *another* path (another run, the dashboard editor, publish flow) trips the gate. When it fires, re-inspect the page and re-preflight, then re-issue the action. `preview` never consumes or requires freshness state.

**Write-time compare-and-swap (#13).** The freshness gate above is a pre-check: it can't cover a write that lands in the narrow window *between* the check and the actual write. To close that, `action execute` and `operate patch` also thread the validated baseline into the write itself as an **`expected_version`**, and the single composition-write choke point (`pp_update_composition`) performs an atomic compare-and-swap **under the per-post advisory lock** — it re-reads the version fresh from the DB and, if it no longer equals `expected_version`, rejects with a `composition_conflict` `WP_Error` and writes nothing (neither the composition nor either marker moves). From the CLI the pre-check usually fires first with the `Stale preflight` message above; the CAS is the atomic backstop for an interleaved write that slips past it, and returns:

> `The composition for post N changed since you last read it (expected version X, current version Y). Another writer (a CLI action, the dashboard editor, or the AI chat) modified it. Re-read the current composition and re-apply your change. [composition_conflict]`

`expected_version` is an **optional** param on every composition-mutating action (`update_composition`, `add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`). Omit it and the write proceeds unconditionally (back-compat: new-page creation, the homepage seed, and legacy direct callers all skip the CAS). Supply it — the CLI agent path, the dashboard composition editor, and the AI chat path all do — and a concurrent write is rejected instead of silently clobbered. The **AI chat path supplies it mandatorily and fail-closed** (#404): the chat backend captures the page's `composition_version` when the model reads the page, and both `wp_ajax_pp_ai_execute` and `wp_ajax_pp_ai_execute_batch` REJECT a composition-mutating write with no baseline (`missing_expected_version`) before executing — the batch rejecting the whole batch before any step runs. A batch threads a per-`post_id` baseline map and chains each write's post-write version into the next mutating step on that page, so it never false-conflicts against its own writes. On `composition_conflict` the handler returns the structured envelope (code + `expected_version`/`current_version`) and the chat UI offers **Re-read & re-preview**, never a blind retry. The dashboard editor keys on the same structured `composition_conflict` code to prompt a reload; the version it sends is refreshed from each successful save so a run's own sequential edits never false-conflict.

**Which band blocked the write (#642).** Every composition-mutating action validates the WHOLE composition, so a rejection routinely comes from a band the caller never named — a stale value stored in another `logos` band refuses your edit to this one. A rejected envelope therefore carries `index` beside `error_code` (the composition offset of the owning band, or `null` when no single band owns it), and the message names the same band up front:

> `Component 1 ("logos") prop "items" item 0 field "image_id" must be a number; got array. [invalid_prop_value]`

Before this, two same-type bands produced byte-identical rejections, so an agent "fixed" its own payload, re-submitted, and got the same string back forever. Two rejection families already name the component in their own words — `Unknown component: "x".` and the site-chrome message below — so those are prefixed with the offset instead (`Component 1: Unknown component: "nope".`); the four structural messages already spell `Item N` and are left alone. `null` means no fabricated locator, not "band 0": it is what a cross-item rule (`duplicate_component_id`), a param-shape error, a failed precondition, a rejection on a band the caller named itself (`style_component`'s own validator, `index_out_of_bounds`), and `add_component` (which validates only the item it adds, so its offset is not a page offset) all report. The field is the authoritative locator — it and the message are rendered from the one stamped offset, so they cannot drift, but a message also reflects author-supplied bytes (a component name, a prop key) and a composition authored with `Component 9 ("x")` inside one of those puts a second band-shaped phrase in the sentence. `wp pp action execute` emits the whole envelope, as does each step of a chat batch. Three paths convey a rejection as its message alone, so there the band is in the words and not in a field: `wp pp action preview` (which renders a rejection as `{ok, error}`), the chat preview, and the chat single-execute error payload. One caveat on batches: a step's locator is relative to the composition that step saw, and a failed batch rolls every earlier step back — if an earlier step inserted, removed or reordered bands, the reported offset no longer addresses the same band in the restored composition. Re-read the page after a rolled-back batch instead of trusting the step's locator. **The rejection examples quoted in the rest of this section show the message from the component label onward, without that band prefix, so the rule each one illustrates stays legible; a real write rejection carries it.** The reporting surfaces are unaffected — `wp pp check page`, `validate site` and restore/rollback `findings` carry the offset as their own field beside the message (`[unknown_prop] index 1: ...`) and their message text is unchanged.

**`create_page` is all-or-nothing once you hand it a composition (#719).** `create_page` creates the page row first and stores the composition second, and the second step can refuse: `pp_update_composition()` skips the write and returns `composition_lock_failed` when it cannot take that page's advisory write lock. That return used to be discarded, so the call reported `ok: true` with a `target.post_id` over a page that was silently EMPTY — and, since #687, `findings: []` beside it, certifying the very page that had lost its content. The verdict is now honoured: the page just created is removed again and the call is REFUSED with the writer's own `error_code`, in the ordinary rejection envelope (`target: []`, `index: null`, and no `findings` / `composition_version`, as on every rejection). **Retry the same call** — a refusal normally leaves no page and no reserved slug behind, so the retry is clean rather than a duplicate stacked beside an empty first attempt. Two branches do leave the page standing and the message says which: a cleanup delete that was itself refused names the survivor (`post 231 ... is still there and stores no composition`), and a page something else wrote to first is left alone (`is NOT empty — something else wrote to it`). This narrows nothing — every composition valid before is still valid and the success path is byte-identical; it is a false success becoming an honest failure. `composition_conflict` is not reachable here: `create_page` threads no `expected_version`, so `composition_lock_failed` is the only code this path adds.

**What the accepted write wrote (#687).** The mirror of the paragraph above, for the writes that SUCCEED. A composition write could validate, store, return `ok: true` and paint nothing — set `--hero-overlay-bg` on a `split`-layout hero and the slot, which renders only under `layout: "cover"`, is stored, versioned, reported as applied and read by nothing. So every accepted envelope from a composition-mutating action, plus `create_page` and `operate patch`, carries `findings`: what current rules say about the composition that was just stored.

```json
{ "ok": true, "action": "style_component", "composition_version": 2,
  "findings": [
    { "type": "inert_slot", "severity": "warning", "index": 0,
      "message": "Style slot \"--hero-overlay-bg\" on this \"hero\" component has no effect as configured: it applies when layout = \"cover\". Either set that up, or drop the slot — the value is stored and reported as applied, but nothing on the page reads it." }
  ] }
```

`wp pp action execute` and `wp pp operate patch` print the whole envelope, so this arrives with no flag and no second command. Same shape as the reporting surfaces (`type` / `severity` / `message` / `index`) and the same two engines, so `wp pp check page --post_id=N` says the same things — the difference is that a write no longer stays quiet about them. `severity: error` findings ride along too, because the item-scoped actions validate only what they touch (`style_component` validates no props at all, `add_component` only the item it adds) and so legitimately accept a write onto a page whose other bands are stale; the report names those bands by `index` rather than leaving you to discover them on the next whole-composition edit. It is **report-only**: computed after the write lands, on success only, from the stored bytes — it never blocks, alters or reorders anything, and a REJECTED envelope carries no `findings` key at all and still returns exactly one message. Long reports are bounded at 100 findings plus one `findings_truncated` entry stating the true total and naming the page to run `wp pp check page --post_id=N` against for the rest; `restore_composition`'s own `findings` (#233) are deliberately left unbounded and untouched. Two limits worth knowing. The cap is a flat per-report count and findings arrive errors-then-advisories, so on a composition carrying more than 100 error-severity findings the advisories — `inert_slot` included — fall behind the tail; the tail says the report is incomplete, and a page in that state is telling you something louder than an advisory anyway. And the cap bounds the COUNT, not bytes. A message reflects stored names verbatim, and `duplicate_component_id` names every colliding index in a SINGLE entry, so its length grows with the band count and 100 entries is not 100 short strings. This is reachable without any raw meta write: `add_component` validates only the item it adds, so repeated calls carrying the same authored `props.id` are all accepted, and every later accepted write on that page then carries an O(N) duplicate-id message (plus its advisory twin). Give each component a unique authored `id` and the report stays small. Bounding what a message reflects is a separate axis (#647/#649).

**One case reports nothing, and says so (D1 Addendum #2).** Building the report costs roughly 28 MB of transient memory per MB of stored composition, and it runs *after* the write has landed — so on a large enough page it could exhaust `memory_limit` and kill the response to a change that already happened (no envelope, no touched-post record for `apply restore-composition`, no refreshed CAS baseline). Above **1,048,576 bytes** of stored composition JSON the engines are not run at all and the envelope carries exactly one entry instead:

```json
{ "type": "findings_skipped", "severity": "warning", "index": null,
  "message": "Composition diagnostics were skipped for this write: the page stores 1141031 bytes of composition JSON, over the 1048576-byte limit for reporting on a write. Nothing here says the composition is healthy. Run `wp pp check page --post_id=42` for the full report." }
```

Read it as "not measured", never as "clean" — an empty `findings` list means the write was diagnosed and found nothing, and this is the opposite claim. `wp pp check page` has no such limit and will report the page in full. The threshold is a fixed constant with no filter or option. A realistic six-band composition stores about 5 KB, so ordinary pages sit orders of magnitude under it.

The same rolled-back-batch caveat as `index` applies, for the same reason. A batch step's `findings` are built when that step succeeds, before a LATER step's failure rolls the whole batch back — so a failed batch returns per-step reports, and `index` values inside them, describing compositions that no longer exist. Re-read the page after a rolled-back batch rather than acting on them.

**The composition itself must be a list (#724).** ⚠️ **Breaking change in 1.15.9.** A composition is a JSON **array** of components. A JSON **object** — `{"1": {...}, "3": {...}}`, the shape a caller reaches for when it thinks the keys are band positions — decodes to an associative array, and until 1.15.9 it was **accepted**: `update_composition` returned `ok:true`, bumped `composition_version`, reported `findings: []`, and replaced a five-band page with two bands, while `wp pp check page` classified the resulting stored value `composition data integrity error (unexpected_shape)`. The write path could manufacture the exact state the diagnostics exist to detect. `pp_validate_composition()` now refuses the container before any per-band rule runs, so `create_page`, `update_composition` and the dashboard editor's save fail with:

> `The composition must be a list of components, but this one is a JSON object (2 entries). Send the components as an array ([{"component": "hero", "props": {...}}, ...]), not an object. [unexpected_shape]`

**What breaks, and the fix.** Any caller sending an object-shaped `composition` param is now rejected instead of silently destroying bands — send a JSON array. Nothing is coerced or reindexed on your behalf (ruling D-A: reject, never coerce), and nothing stored is migrated. The error `unexpected_shape` is deliberately the read path's own word for this state, so a refused write and `wp pp check page` / `wp pp operate inspect` / `inspect-composition` all name it identically. This rejection carries **no `index`** — the problem is the container, not any band, so no band locator is invented. Two shapes are indistinguishable after `json_decode` and remain accepted: `{"0": ..., "1": ...}` (the keys ARE `0..n-1`, so PHP hands back a list — and key and position agree, so nothing is dropped) and `{}` (identical to `[]`). A page that already stores the object shape is **not** migrated: `wp pp check page` and `wp pp operate inspect-composition` both report it as corrupt, and one full `update_composition` write with a JSON array repairs it. `restore_composition` is the deliberate exception as always (#233) — it replays an object-shaped history snapshot verbatim and reports the container in `findings` rather than blocking undo. **Send either repair as a SINGLE action, never as a chat proposal (#749)** — not even a one-step one. A batch is refused before step 1 when any page it names has an unreadable stored composition, and the chat client routes every proposal through the batch endpoint, so a repair sent that way comes back refused with the very code it was meant to clear. The gate runs before any step's own semantics, so `restore_composition` is refused there too despite #233. The single-step path (`wp pp action execute`, `pp_patch_composition()`, the dashboard editor) takes no rollback snapshot and so is never refused by that gate — repair from the CLI or the editor (#756).

**Template-owned chrome (#223).** `nav` and `footer` are rendered on every page by `templates/base.php`. They are registered, renderable components, but they are **not composable** — a composition containing either would render the site header or footer twice. `pp_validate_composition()` rejects them, so `create_page`, `update_composition`, `add_component`, `update_component`, and the dashboard editor's save all fail with:

> `"nav" is site chrome rendered by the page template; it cannot be placed in a page composition. Set the site logo via the "pp_logo_id" site option, and the navigation menu via the menu actions (create_menu / assign_menu_location). [template_owned_component]`

The code is distinct from `invalid_composition` so a caller can tell "that name is chrome" apart from "that name doesn't exist." A page whose stored composition already contains chrome (written before this rule, or through a non-action path) is not silently accepted: `wp pp check page` and `wp pp validate site` report it as an ERROR-severity `template_owned_component` finding (a normal write of this composition would be rejected) alongside the matching advisory smell — since #622 either one fails `validate site` — and `wp pp validate page` fails with a `template_owned_component` error. Remove the offending items with `remove_component` — each removal shifts later indices down, so remove the highest index first.

**Duplicate component IDs (#238).** A component's `props.id` is how `component_id` targeting picks one component out of a composition. Two components sharing the same non-empty `id` would make that targeting ambiguous, so `pp_validate_composition()` rejects the collision at write time — `create_page`, `update_composition`, `add_component`, `update_component`, and the dashboard editor's save all fail with:

> `Duplicate component id "pricing" on items 0, 2. Component ids must be unique within a composition so update/remove/style can target one component. [duplicate_component_id]`

A page whose stored composition already carries a collision (written before this rule, or through a non-action path) is not silently accepted: `wp pp check page` and `wp pp validate site` report it as an ERROR-severity `duplicate_component_id` finding as well as the advisory smell. It is the one error-severity finding with no `index` (#622): it spans two bands, so it belongs to neither, and names every colliding index in its message instead. And the resolver is defensive as a backstop — if a duplicate ever reaches a targeting action, `update_component` / `remove_component` / `style_component` fail closed with a `component_ambiguous` error (listing the colliding indexes) rather than silently mutating the first match. Give each component a unique authored `id`.

**Unknown component props (#147).** Each component declares its full prop contract in `components/<name>/schema.json` under `props`. A composition whose component carries a prop key not in that contract is rejected at write time by `pp_validate_composition()`, so `create_page`, `update_composition`, `add_component`, `update_component`, and the dashboard editor's save all fail with:

> `Component "cta" has no prop "not_a_real_prop". Available props: id, title, title_accent, eyebrow, text, button_text, button_url, button2_text, button2_url, button2_variant, layout, theme, background_image, button_variant [unknown_prop]`

The source of truth is the component's full schema `props`, not the narrower schema-derived scalar-patch set (`pp_get_component_fields()`, which exposes only scalar props for `operate patch`), so real props like `cta.theme` and `cta.background_image` are accepted while a misspelled or invented key is not — closing the "phantom field" hole where an unknown key would persist behind an `ok:true` while the renderer silently ignored it. Unlike template-owned chrome and duplicate ids, this rule has no composition-smell counterpart. A stored composition that already carries an unknown prop (from a legacy write or a raw non-action path) is still reported, as an ERROR-severity finding rather than an advisory smell: `restore_composition` returns it in `findings` (never blocking undo, #233), and since #622 `wp pp check page` and `wp pp validate site` report it too — a stale page no longer reads as clean on the surfaces you query first.

The same hint now exists one level down (#643). When a required ITEM FIELD is missing AND the entry carries fields the prop's field map does not declare, the rejection names both, with the same helper and the same grammar: `Component "logos" prop "items" item 0 is missing required field "image_url". This item also carries field(s) "items" entries do not declare: imageUrl, imageAlt. Available fields: image_url, image_alt, image_id, label.` Without it a renamed field cost two rounds — the first rejection named only the canonical field that was missing, so an agent added it, kept the misspelling, and was rejected again by the unknown-field rule. Note the asymmetry that made this worth closing: a misspelled OPTIONAL field surfaces immediately, so the gate was mute on exactly the required ones.

When a required prop is missing AND the item carries prop keys the schema does not declare, the rejection names both (#622): `Component "cta" is missing required prop "button_text". This item also carries prop key(s) "cta" does not declare: cta_text, cta_url. Available props: ...`. The required-prop check fires before the unknown-prop gate and ends the item, so without this the value sitting under an unrecognized key next door was never mentioned. The hint is derived from the component's current schema — declared keys versus present keys. There is no retired-name lookup and there must not be one: #603/#604/#605/#606 removed every alias map, and a "formerly known as" list would be that machinery again.

**Unknown `items[]` fields (#643).** The same gate now runs one level down. A prop whose schema declares an `items` FIELD MAP (`grid.items`, `logos.items`, `stats.items`, `faq.items`, `testimonials.items`, `section.panel_items`) rejects any field an entry carries that the map does not declare, with the same `unknown_prop` code:

> `Component "logos" prop "items" item 0 has no field "imageId". Available fields: image_url, image_alt, image_id, label [unknown_prop]`

This closes the last silent no-op at that depth. `imageId: 42` — camelCase, one keystroke from the declared `image_id` — used to validate, persist, report `ok:true` and render nothing, right beside the `image_id: {attachment_id: 42}` shape #614 already rejects. The message names the item by its real key (a list position, or an object key verbatim, #634) and lists the fields that component's entries accept. Two shapes are deliberately untouched. The first is an array prop with no field contract to measure an entry against: `section.body_items`, `table.headers` and `table.rows` declare no `items` at all, and one level deeper `grid.items[].bullets` declares a value grammar (`items: {"type": "string"}`) rather than a map of fields — nothing in any of them can be "unknown". The second is a JSON *list* entry, which is a shape defect owned by `item_type: "object"`; that rule reports it once rather than naming each position as an unknown field. As everywhere else in this validator, `restore_composition` reports it without blocking (#233) and `wp pp check page` / `wp pp validate site` report it on the stored page (#622).

**Schema-typed prop values (#507).** Beyond the *key* being known, each prop's *value* is checked against its declared schema `type`, so an accepted write renders as authored instead of the renderer emitting `Array` (with a PHP warning) behind an `ok:true`. A prop declared `type: "string"` rejects anything that is not a string — including a non-string SCALAR (`42`, `3.14`, `true`, `false`), narrowed in #707; `type: "number"` rejects a non-numeric; `type: "array"` rejects a scalar; and an object-item array (one declaring `item_type: "object"`, e.g. `grid.items`) rejects any entry that is not an object. Each check has a per-type "unset" sentinel (`null`, `""`, and — for arrays — `[]`) that preserves the prop's default, so an omitted value is never a rejection.

The `string` rule used to stop at non-scalars, on the reasoning that an int or bool coerces to text and renders as authored. It does not, reliably: `create_page` with `image_url: 42` returned `ok:true`, stored the integer raw, reported no finding, and painted `<img src="42">`, and a stored `section.panel_cta_url: false` rendered a button with an empty href. Since #707 the declared type is the accepted type. `number` is deliberately asymmetric here and still takes a numeric STRING, because a JSON/CLI write legitimately sends `"3"`. The rule is generic and schema-driven: a new prop is enforced the moment its schema declares a `type`, with no per-component code. It layers under the bounded families (numeric `min`/`max` #379, strict enum #380/#579, string-array bounds #475, array-item arrays #579), which keep their own precise messages for the props that declare them. Rejections carry `invalid_prop_value`:

> `Component "cta" prop "title" must be a string; got array. [invalid_prop_value]`

An array declaring `item_type: "array"` (today `table.rows`) rejects any entry that is not itself an array, so a scalar row can no longer be cast by the renderer into a silent one-cell row.

**Strict enums (#380, universal for top-level props since #579, extended to nested `items[]` fields by #600).** Every enum declares `"strict": true` — top-level props and `items[]` fields alike — so a value outside the declared `values` is rejected at write instead of being accepted and coerced to the default at render. Both depths run the same membership test through one shared predicate. "Both depths" is literal: the gate walks top-level props and ONE `items[]` level, which is every depth the shipped schemas declare — a hypothetical enum nested two levels down would not be reached. Nothing rendered changed when this became universal — the renderer already coerced — but a write that reported `ok:true` and produced the default no longer happens:

> `Component "cta" prop "button2_variant" must be one of: primary, secondary, outline, ghost; got "neon". [invalid_prop_value]`

**The advertised set is the accepted set (#606).** A prop could once declare `aliases` — legacy values accepted at write and never advertised in `values`. The last declaration (`theme`'s legacy `dark`) went in #605 and the field itself went in #606, so **no prop has an accepted-but-unadvertised tier**: a `theme: "dark"` write is rejected like any other unadvertised value, and a page that still stores it renders the `default` band. Since #600 that holds for `items[]` enum fields too — no declared enum in the shipped grammar accepts an unadvertised value. The `unset` sentinel (key absent, `null`, `""`) always preserves the declared default.

**Nested item-field contracts (#579).** A `required: true` declared on an `items[]` field is enforced, not decoration — `logos.items[].image_url` / `image_alt`, `stats.items[].number` / `label`, `testimonials.items[].quote`, `faq.items[].question` / `answer`. The case it closes: a logos entry carrying a `label` and no `image_url` used to validate, persist, return `ok:true` and render nothing at all:

> `Component "logos" prop "items" item 1 is missing required field "image_url". [invalid_composition]`

A nested array field declaring `item_type: "string"` (`grid.items[].bullets`) likewise type-checks its entries. As at the top level, an absent key is the violation; a present-but-empty value is not.

**Nested scalar field types (#614).** A nested field's own declared `type` is enforced too, through the same predicate as the top-level #507 pass, so `"42"` is a number at both depths: a field declared `type: "string"` rejects anything that is not a string (a non-string scalar included, since #707), and `type: "number"` rejects a non-numeric. Sharing the predicate is what made #707 a one-line narrowing rather than two rules to keep in step — `logos.items[].image_url: 42` is refused exactly where a top-level `hero.image_url: 42` is. The unset sentinels match the top level exactly (`null` for both, plus `""` for `number`), so an omitted value still preserves the field's default. The case it closes is `items[].image_id`: PHP's `(int)` cast is not a rejection — `(int) ['attachment_id' => 42]` and `(int) true` are both `1` — so passing the `import_media` result object straight into `image_id` used to persist behind an `ok:true` and render attachment ID 1, typically the site's first upload, discarding the author's `image_url` entirely.

> `Component "logos" prop "items" item 0 field "image_id" must be a number; got array. [invalid_prop_value]`

**Nested enum fields (#600).** A nested field declaring `type: "enum"` with `strict: true` (today `grid.items[].text_role`) is held to its declared `values` by the same membership test the top-level props get, sharing one predicate so the two depths cannot drift apart. This was the last accept-at-write / coerce-at-render surface in the grammar: an out-of-set role used to persist behind an `ok:true` and render as ordinary body text, so a card the author asked to mark as code, caption or eyebrow simply was not marked. The `unset` sentinel matches the top level (key absent, `null`, `""`).

> `Component "grid" prop "items" item 0 field "text_role" must be one of: mono, meta, label, kicker; got "terminal". [invalid_prop_value]`

The renderer's own allowlist is unchanged and still load-bearing: a value that reaches storage through a non-validating path (a raw database write, or a `restore_composition` of an old snapshot — which by rule never blocks) is still coerced to the default rather than emitted as a class name. What changed is only that the write path names the problem instead of reporting success. The cost is the one every write-path tightening here carries: a page that already stores an out-of-set role blocks edits to its *other* bands until that item is repaired through the ordinary authoring surface (set the field to a declared value, or drop the key). No alias, no migration, no coercion.

Two nested shapes are deliberately still uncovered, named here rather than left to be discovered. Nothing constrains an item `object` field's contents (`grid.items[].style`). And a nested `array` field handed a **scalar** is still accepted — the `item_type: "string"` rule above walks a bullets array's *entries*, never the field itself, so `bullets: "one, two"` persists behind an `ok:true` and renders nothing, where the same annotation on a top-level prop (`section.body_items`) is rejected.

**Link-URL format (#507).** A prop that declares `format: "link_url"` (today `cta.button_url` / `cta.button2_url`, `hero.button_url` / `button2_url`, `section.panel_cta_url`, and `grid.items[].link_url`) is validated so the write cannot report `ok:true` for a value that `esc_url()` would silently neuter into an empty `href` — a dead button. The bar is "what survives `esc_url()` renders as authored": a site-relative path (`/pricing`), an anchor (`#booking`), a protocol-relative URL (`//cdn.example.com/x`), `mailto:`, `tel:`, and any other `wp_allowed_protocols()` scheme are accepted; a value carrying a disallowed protocol (`javascript:`, `data:`, `vbscript:`, ...) is rejected:

> `Component "cta" prop "button_url" is not a usable link URL: "javascript:alert(1)" uses a disallowed protocol and would render as a dead link. Use an absolute URL (https://...), a site-relative path (/path), an anchor (#id), mailto:, or tel:. [invalid_prop_value]`

Like every rule here, both checks run in the shared `pp_validate_composition()` and are reported (never blocked) by `restore_composition` (#233).

**Reading the `item N` locator (#634, #652).** Every message in this nested family names the offending entry by its **stored `items` key**, not by a counted position. For the ordinary case they are the same thing: an `items` array authored as a JSON list gives `item 0`, `item 1`, `item 2`, exactly as the examples above show, and that spelling has never changed. They differ when `items` was stored as a JSON *object* rather than a list — a shape a raw `update_post_meta()` write, a history-ring snapshot, or an author sending `{"aa": {...}}` can produce, and one these read-only diagnostics exist to be pointed at. An object key says so:

> `Component "grid" prop "items" item key "aa" field "link_url" is not a usable link URL: ... [invalid_prop_value]`

Two of these locators used to cast the key to an integer, so a `"aa"`-keyed entry reported `item 0` and sent the operator to repair an element that does not exist. All nine now render the key through one shared helper, so every nested rule answers "which item?" the same way.

The `key "N"` form exists for the case that reads correctly and means the wrong thing. PHP folds a numeric *string* object key to an integer when the composition is decoded, so `{"1": ..., "0": ...}` hands the validator the same keys a two-element list would. The entry under key `"0"` is second in iteration order, so a bare `item 0` sent an operator to the **first** card — the healthy one — and a repair-and-resubmit loop returned the identical message forever. Naming it `item key "0"` is what distinguishes "the entry stored under key 0" from "the first entry":

> `Component "grid" prop "items" item key "0" field "link_url" is not a usable link URL: ... [invalid_prop_value]`

The discriminator is the **container**, not the key: a list renders `item N`, anything else renders `item key "N"`. One case is deliberately not distinguishable, and it is the harmless one — an *ordered* numeric object (`{"0": ..., "1": ...}`) decodes to a genuine PHP list, so nothing downstream can tell it from an authored list and it renders `item 0`. Key and position agree there, so both readings address the same element.

Repair the entry under the named key; if the whole `items` value is an object where a list belongs, rewrite it as a list.

**Reading the band locator (#650, and the #687 addendum).** The same rule one level up. A composition authored as a JSON list names its bands `Item 0` / `Component 1`, exactly as before — that is the shape every shipped example uses and none of it moved. A composition *stored* as an object names the key. All four band-level messages answer "which band?" the same way:

| | list-shaped composition | object-shaped composition |
|---|---|---|
| missing `component` key | `Item 1 is missing the "component" key.` | `Item key "1" is missing the "component" key.` |
| non-scalar `component` | `Item 1 has a non-scalar "component" key.` | `Item key "aa" has a non-scalar "component" key.` |
| write rejection prefix | `Component 1 ("logos") prop ...` | `Component key "1" ("logos") prop ...` |
| duplicate id | `... on items 0, 1.` | `... on items key "1", key "0".` |

The first two used to report `Item 0` for *every* string-keyed band, while the same rejection's `index` field honestly carried `null` — message and payload contradicted each other, and the message was the wrong one. The other two printed a bare number that read as a position: on `{"1": bad, "0": healthy}` the bad band is stored under key `1` and is the **first** one iterated, so `Component 1` sent you to the healthy band next door.

⚠️ **Since #724 the right-hand column is a STORED-STATE column only.** A write can no longer produce any of those four messages: an object-shaped composition is refused at the container with `unexpected_shape` before the per-band rules run, so a rejected `create_page` / `update_composition` never names a band by key. The key forms remain the honest answer where an object-shaped composition is still *read* rather than written — `restore_composition` and the run-scoped rollback report over raw history-ring snapshots, which admit that shape. The `items[]`-depth `item key "N"` forms above are unaffected and stay fully live on ordinary list-shaped writes: an `items` map inside a well-formed band is a different container from the composition itself.

A locator is real or absent, never fabricated. Two consequences worth knowing: `index` carries only an **integer** offset or `null`, so a string-keyed band puts the locator in the words and not in the field; and `index` is an array **key**, so address it as `composition[index]` rather than by counting bands. `duplicate_component_id` still carries no `index` at all — it belongs to no single band and names every colliding key in its message.

**Output** — the preflight result:

```json
{
  "ok": true,
  "checks": [
    { "check": "target", "pass": true, "message": "Target resolved: https://example.com" },
    { "check": "capability", "pass": true, "message": "WP-CLI context: capability gate bypassed." },
    { "check": "drift", "pass": true, "message": "No drift detected." },
    { "check": "theme_writable", "pass": true, "message": "Skipped: planned applies are database-backed (no filesystem writes)." },
    { "check": "screenshot_readiness", "pass": true, "severity": "warning", "message": "..." }
  ]
}
```

The checks that can run (`pp_preflight`, `lib/operate.php`):

| Check | When | Blocks? |
|---|---|---|
| `target` | always | yes |
| `capability` | always | yes |
| `apply_known` | `--apply=<name>` given and unregistered | yes |
| `drift` | always | only if drifted files overlap `planned_files` |
| `theme_writable` | file-targeting applies only | yes (skipped for DB-backed token applies and for media applies, whose writes are covered by `uploads_writable`) |
| `uploads_writable` | media-target applies only (`--apply=import_media`, #229) | yes |
| `target_page` | `--post_id` given | yes |
| `surface` | `planned_files` given | yes if a `core` file is planned |
| `nav_readiness` | always (site chrome is not page-scoped, #223) | no (`severity: warning`) |
| `screenshot_readiness` | always | no (`severity: warning`) |

`ok` is `true` only when no **error-grade** check failed. Rows with `severity: warning` (nav readiness, screenshot readiness, non-overlapping drift) surface a problem without blocking.

**Screenshot readiness tri-state (#497).** The `screenshot_readiness` row carries a `state`:
`available` (healthy, no finding), `unavailable` (`PP_BROWSER_CMD` not configured), or
`broken` (configured but the binary is missing from `$PATH`). `unavailable` and `broken`
are distinct capability-class findings, each with `next_action: wp pp screenshot doctor` —
never a single ambient warning. Preflight does the cheap **non-exec** check only (it never
launches a browser); run `wp pp screenshot doctor` to capture-verify `available` and to turn
a probe-time failure into `broken`. See `docs/screenshot-setup.md`.

**Uploads writability (#229).** `import_media` sideloads a file into `wp-content/uploads/YYYY/MM/`, so a preflight with `--apply=import_media` runs `uploads_writable` instead of asserting "no filesystem writes." The check mirrors execute-time `wp_mkdir_p()` semantics: it walks from the dated path to the **deepest existing ancestor** and requires it to be a writable directory — so a fresh site whose `uploads/` doesn't exist yet passes (WordPress creates it), while an unwritable intermediate directory (`uploads/2026` rsync'd `0555`) or a regular file occupying a path segment fails closed even when `uploads/` itself is writable. The `theme_writable` row still passes for media applies (the theme directory is untouched) with a message pointing at `uploads_writable`.

**Unknown apply name (issue 245).** The `--apply` flag exists so preflight can verify the named apply's preconditions, so a name that matches no registered apply is a hard error, not a no-op. Without the guard, `--apply=import_medai` (a typo) would resolve to "no apply planned," skip the apply-routed filesystem checks, pass clean, and record a `PREFLIGHT` state asserting "no filesystem writes" the operator never earned — the same false-pass class as #227/#229, one level up. Any **provided** non-empty apply value is validated (including the falsy literal `--apply=0`, which is never a registered name); an empty `--apply=` is treated as "no apply planned," same as omitting the flag. The `apply_known` check is error-grade, so an unknown name makes `ok` false and no `PREFLIGHT` is recorded:

```json
{ "check": "apply_known", "pass": false, "message": "Unknown apply: import_medai. Preflight cannot verify preconditions for an unregistered apply; check the name against the apply registry." }
```

**Exit codes and errors**

**stdout is the machine-readable channel.** Parse stdout, not combined output — WP-CLI's human-readable `Error:` lines go to stderr. The success JSON is emitted **only after** every recording step has succeeded (#227): `ok: true` means the preflight completed *including* recording its state. If any recording step fails after the checks passed, stdout carries `{"ok": false, "error": "<message>", "checks": [...]}` and the command exits **1** — a preflight that could not record itself never reports success.

- If any error-grade check fails: prints the JSON (`ok: false`), then exits **1** (`WP_CLI::halt(1)`). Nothing is recorded.
- **Unreadable baseline (#200 lock contention, #207 corrupt row, #212 read failure):** the snapshot is read under the token advisory lock for an atomic baseline. It comes back `null` — and preflight **records nothing** and errors — in three cases: the lock is contended (another writer is racing, #200), the stored `pp_token_overrides` row is corrupt/truncated/hand-edited into a non-array (#207), or the option read itself fails at the database (a query error on the `SELECT`, detected via a non-empty `$wpdb->last_error`, #212). Any of the three:
  > `Could not read an atomic pre-apply token baseline for run token "<uuid>": the token lock is contended, or the pp_token_overrides row is corrupt/unreadable. PREFLIGHT was not recorded. Re-run 'wp pp apply preflight' once the contention clears; if it persists, inspect and repair the pp_token_overrides option.`

  This is deliberate fail-closed behavior. Recording a stale baseline (contention) would let a later `restore` revert to a state that never existed; recording an empty `[]` baseline for a corrupt row or a failed read is worse — `restore` reverts every touched token off an empty snapshot by **deleting** it, so a row silently coerced to `[]` would turn a restore into token loss. A database read failure is distinguished from a genuinely absent row (which is a valid empty `[]` baseline) by `$wpdb->last_error`: a failed read fails closed as `null`, an absent row records `[]` normally. Retry once contention clears; if the error persists, the row itself is unreadable or the database is erroring — inspect and repair `pp_token_overrides`.
- If the run state can't be recorded, the failure is reported with the precise cause (#409) rather than the old ambiguous "missing or expired" — stdout gets the `{"ok": false, "error": ...}` payload and stderr carries the matching message:
  - **Not found** (the run token was never minted on this install, or was cleaned up — most often `inspect` and this command ran in different environments, e.g. separate ephemeral CLI containers): `No run state found for run token "<uuid>". ... Re-run 'wp pp operate inspect' here to start a fresh run.`
  - **Expired** (older than the 2-hour TTL): `Run token "<uuid>" has expired (older than the 2-hour run TTL). Re-run 'wp pp operate inspect' to start a fresh run.`
  - **Foreign** (the token belongs to a different site/install): `Run token "<uuid>" belongs to a different site or install and cannot be used here. ...`
  - **Corrupt** (the stored state is unreadable): `Run state for run token "<uuid>" is unreadable (corrupt). ...`
  - **Write failed** (the run exists but the options-table write did not land): `Could not persist PREFLIGHT state for run token "<uuid>": the run exists but the options-table write did not complete ... Retry 'wp pp apply preflight'.`
- **Corrupt page composition (#241):** for a `--post_id=N` preflight the pre-run composition content snapshot (#133, the baseline `restore-composition` reverts to) is read with the same fail-closed decoder as the renderer (`pp_get_composition_result`). If the stored `_pp_composition` row is corrupt/undecodable or the wrong shape, preflight **records nothing** and errors — recording an empty `[]` baseline would let a later `restore-composition` **blank the page** (the composition analogue of the token-loss hazard above). A genuinely empty page still records a valid `[]` baseline; only a corrupt row fails closed. Note (#604): the decoder resolves no key names at all, so the recorded baseline is the pre-run composition's **literal stored bytes** — a restore returns exactly what was there, retired key names included. (Until #604 the baseline was a canonicalized view, with `variant` and legacy prop keys already migrated; that rewriting is gone, so baseline and storage can no longer disagree.)
  > `Could not read a valid pre-apply composition baseline for run token "<uuid>" (post N): the stored composition is <error>. PREFLIGHT was not recorded, so both the action gate and the restore baseline stay fail-closed. Repair the post's composition before re-running 'wp pp apply preflight'.`
- **Atomic unlock + restore baseline (#241):** for a page-scoped preflight the PREFLIGHT coverage (what unlocks mutating gates), the freshness marker, the token snapshot, and the pre-run composition content snapshot are all committed in **one** state write. The gate can never unlock without its restore baseline, and a preflight re-run never freezes a post-mutation baseline (the content snapshot is first-write-wins). Any `ok: false` still means "preflight did not complete" — re-run `wp pp apply preflight` before mutating; the re-run is safe.

An empty override set is a **valid** baseline: on a fresh install with no overrides the snapshot is `[]` (recorded normally), never `null`. `null` means "could not read an atomic baseline" — lock contention, an unreadable/corrupt row, or a database read failure, never a legitimately empty one.

---

## `wp pp apply execute`

Applies a named change. Validates first, then writes, then records the touched tokens so `restore` can revert exactly this run's footprint.

```bash
wp pp apply execute update_design_token --run-id=<uuid> --params='{"token":"--color-accent","value":"#b45309"}'
```

**Options**

- `<name>` — the apply name (positional, required).
- `--params=<json>` — JSON object of apply parameters.
- `--run-id=<uuid>` — **required.**

**Gates, in order** (each halts with an actionable error if unmet):

1. Valid run token (`_pp_cli_require_run_id`).
2. A completed `PREFLIGHT` step: `Run token "<uuid>" has no completed PREFLIGHT step. Run 'wp pp apply preflight --run-id=<uuid>' first.`
3. A usable rollback snapshot (`pp_operate_run_rollbackable`): `Refusing to apply: run "<uuid>" has no usable rollback snapshot, so this change could not be undone. Re-run 'wp pp operate inspect' and 'wp pp apply preflight'.`
4. The apply capability.

**Output** — the apply result JSON (includes `before`/`after` for token applies plus `changes[]`), then `Success: Apply "<name>" executed.`

**Exit codes and errors**

- `0` on success.
- If the apply itself fails (`result.ok == false`): prints the JSON, exits **1**.
- If the mutation persisted but the touched-token trail could **not** be recorded, it errors loudly rather than reporting clean success:
  > `Apply "<name>" persisted, but recording its touched tokens for run "<uuid>" FAILED. 'wp pp apply restore' may not be able to revert this change. Run state may be missing or corrupt; re-run 'wp pp operate inspect' before making further changes.`

  Token mutation and touched-key recording write to separate options rows (the `pp_token_overrides` option and the per-run run-state option) and can't be one transaction, so this failure is surfaced at the exact point it happens.

On success the run advances to the `APPLY` step.

---

## `wp pp apply reset`

Resets design tokens to **product defaults** by clearing overrides. This is **not** a per-run rollback — use `restore` for that. Reset records its touched tokens the same way `execute` does, so a reset within a run is itself restorable.

```bash
wp pp apply reset --run-id=<uuid> --token=--color-accent
wp pp apply reset --run-id=<uuid>
```

**Options**

- `--run-id=<uuid>` — **required.**
- `--token=<name>` — reset a single token. Omit to reset all (runs the `reset_all_design_tokens` apply, the most destructive in the registry).

**Gates** — identical to `execute`: valid run token → completed `PREFLIGHT` → rollbackable → capability.

**Output** — the apply result JSON, then `Success: Reset <n> token(s) to product defaults.` (or `No overrides to reset.`).

**Exit codes and errors** — `0` on success; exits **1** if the reset apply fails; the same loud touched-token recording error as `execute` if the trail can't be recorded. Advances the run to `APPLY`.

---

## `wp pp apply restore`

Rolls **this run's** token changes back to the snapshot frozen at its preflight. Tokens the run wrote (primary + auto-derived) revert to their pre-run values; tokens the run *created* are removed; tokens the run never touched are left alone, so unrelated overrides (including later runs' work) survive. It never falls back to a product-default reset and never partially mutates.

```bash
wp pp apply restore --run-id=<uuid>
wp pp apply restore --run-id=<uuid> --token=--color-accent
```

**Options**

- `--run-id=<uuid>` — **required.**
- `--token=<name>` — restore a single token and its derived family from the snapshot, intersected with what the run actually touched. Omit to restore everything the run touched.

**Gates** — valid run token → completed `PREFLIGHT` → capability.

**Fail-closed reads.** Both the frozen snapshot and the touched-key list must be usable for **this** install. `null` from either (missing / expired / corrupt / swept / identity mismatch) stops the restore with no change:

> `Run "<uuid>" has no usable pre-apply snapshot; cannot roll back. The run state may be missing, expired, corrupt, or from a different site. Nothing was changed.`

**Output** — one of:

- `Success: Restored <n> token(s) to the pre-run snapshot.`
- `Success: Tokens already matched the pre-run snapshot; nothing to restore.`
- `Success: Token "<name>" was not changed by run "<uuid>"; nothing to restore.` (with `--token` outside the run's footprint)
- `Success: Run "<uuid>" changed no tokens; nothing to restore.`

**Errors** — the fail-closed message above, or, if the atomic revert can't proceed:
> `Could not roll back run "<uuid>": the token lock was unavailable or the snapshot held invalid values. Nothing was changed.`

`restore` writes through `pp_revert_tokens`, which validates every scoped snapshot value against the live token registry **before** any write and aborts the whole revert on a single invalid entry — never a partial restore.

---

## Restore vs reset at a glance

| | `restore` | `reset` |
|---|---|---|
| Goes back to | this run's pre-apply snapshot | product defaults (base.css) |
| Scope | only tokens this run touched | one token, or all |
| Preserves unrelated overrides | yes | no |
| Undoes another run's work | no | (clears everything) |
| Reversible afterward | it *is* the reversal | yes (records touched tokens) |

---

## Composition history & restore (#133)

Design-token writes have always been reversible (snapshot at preflight, `restore`). Composition writes — the page content itself — now match that parity. Every composition write (`update_composition`, `add_component`, `remove_component`, `reorder_components`, `update_component`, and `restore_composition` itself) pushes the **prior** composition onto a bounded per-post history ring (last 10 entries) before overwriting. Restore reads that ring.

There are two restore surfaces, both conflict-checked writes that land their own history entry (so a restore is itself reversible):

### `restore_composition` action (single page)

Registered in the `wp pp action` family. Rewrites one page's composition to a prior history entry.

```bash
# Preview the diff (read-only, no run token)
wp pp action preview restore_composition --params='{"post_id":42,"steps_back":1}'

# Execute (needs a run token + PREFLIGHT covering post 42, like any composition mutation)
wp pp action execute restore_composition --run-id=<uuid> --params='{"post_id":42,"steps_back":1}'
```

Target selectors (params):

- `steps_back` (int, default `1`) — `1` = the most recent prior state (the last write's before-image), `2` = the one before it, … up to the number of retained entries.
- `history_index` (int) — absolute 0-based index into the ring (oldest = 0). Takes precedence over `steps_back`.
- `expected_version` (int, optional) — optimistic-locking baseline (#13); the restore is rejected with `composition_conflict` if the page moved since.

Errors: `no_history` (the page has no recorded prior state), `history_out_of_bounds` (selector past the ring), `composition_conflict` (stale `expected_version`).

#### Restore reports, it does not block (#233)

A snapshot captured before a validation rule existed still restores. Current rules never veto a restore — undo is wired to this action, so a restore that today's rules refuse would make undo fail exactly when you most need it. Instead the restore succeeds and tells you what is wrong with what it just wrote.

Two things follow from that, and both are visible in the result:

**The snapshot is restored verbatim (#604).** It passes through `pp_normalize_composition()`, which now only strips empty `style` arrays — it no longer rewrites `type` → `component` or the pre-#69 `variant` → `layout`/`theme`, and the legacy prop-key alias map it used to apply is gone. No component is added, removed, or reordered, and no key is renamed. Chrome (`nav`/`footer`), retired slot names, retired prop names and a stored `variant` are all preserved exactly as snapshotted and reported in `findings` below — **content is never stripped from history**, and it is never silently rewritten either.

**The result carries a `findings` array**, on both `preview` and `execute`. It is `[]` when the snapshot is clean under current rules:

```jsonc
{
  "ok": true,
  "action": "restore_composition",
  "findings": [
    {
      "type": "template_owned_component",
      "severity": "error",
      "message": "\"nav\" is site chrome rendered by the page template …",
      "index": 0
    },
    {
      "type": "template_owned_component",
      "severity": "warning",
      "message": "\"nav\" at index 0 is site chrome … Remove it with the remove_component action.",
      "index": 0
    }
  ]
}
```

- `severity: "error"` — a rule that would reject this composition on a normal write. Produced by `pp_validate_composition_errors()`, which reports every violation it can locate, not just the first — and since #621 that holds *within* a band too: a band with a retired prop name, a dead style slot and a dead card link reports all three, so one repair pass usually fixes it. The unit is the authored location a message can name (prop, `items[]` entry, nested field), so one bad value is never reported twice by two rules. Two exceptions, both deliberate: a band whose identity is unusable (no `component` key, unknown component, site chrome) reports that one problem and nothing else, because nothing below it can be judged; and a `style` map reports its first dead slot only. `index` is the composition offset of the offending item (#622), the same locator smells carry. It is `null` only for a cross-item rule (`duplicate_component_id`), which belongs to no single band and names every colliding index in its message.
- `severity: "warning"` — advisory. Produced by `pp_validate_composition_smells()`. `index` is the composition offset.

The chat's undo card styles each finding from its `severity` (#622): an error renders as an error, a warning as an advisory. The card's heading stays a warning that says "Restored" — the restore itself succeeded and is never blocked (#233).

A single problem can surface as both, from the two different engines. That is not duplication to filter out: the error tells you a subsequent normal write will be rejected, the warning tells you what the rendered page does wrong and how to fix it.

`preview` computes the identical findings and writes nothing, so an agent sees the required remediation before executing rather than discovering it in the next validator run. Its `after` field is the normalized composition — what `execute` would actually persist.

`restore_composition` was the only action that returned `findings` until #687; since then every ACCEPTED composition-mutating write carries it too, plus `create_page` and `operate patch` (see above). A REJECTED envelope carries no `findings` key at all — including a `create_page` refused by the writer (#719). The canonical result keys are a minimum, not an exhaustive set.

### `wp pp apply restore-composition` (run-scoped)

The composition counterpart of `wp pp apply restore`. Reverts **every page the run changed** back to the content frozen at the run's PREFLIGHT. Scoped strictly to this run's touched posts — a page a different run mutated is never touched.

```bash
wp pp apply restore-composition --run-id=<uuid>
```

Fail-closed: if the run's touched-post record is missing / expired / corrupt / from another install, nothing is changed and it errors (exit **1**). Per-post snapshot-missing or write failures are reported under `skipped` in the JSON output while the reverts that can proceed still proceed.

**Exit codes** — `0` only when **every** touched post was reverted (a full restore). When any post lands in `skipped`, the JSON report is still printed (so you can see which posts were restored vs skipped) but the command **errors and exits 1**: a partial restore is incomplete, not successful, so a machine consumer branching on the exit code never reads it as a full one.

When the restore is complete (empty `skipped`):

- `Success: Reverted N composition(s) to the pre-run state (of M touched).`
- `Success: Touched compositions already matched the pre-run state; nothing to revert.`

When the restore is incomplete (non-empty `skipped`):

- `Error: Restore INCOMPLETE: reverted N of M touched post(s); K could not be reverted (missing snapshot or write failure). See the report above for which posts were restored vs skipped.` (exit 1)

**Each reverted post carries a `findings` array (#236).** Like the `restore_composition` action, the run-scoped restore never blocks a rollback just because a rule that landed *after* the snapshot would reject it — but it must not report a clean success when a restored composition violates current rules. Every entry under `reverted` therefore includes `findings` (`[]` when the restored composition is clean under current rules; otherwise the shared `pp_validate_composition_*` errors and smells). When any reverted post reports findings, the command prints a `Warning:` naming how many, alongside the JSON report. The findings are advisory: the revert still succeeds and the exit code is unaffected (a partial restore still exits 1 per the completeness rule above; findings alone never change the exit code). Skipped posts were never rewritten and carry no `findings` key.

### `wp pp operate composition-history --post_id=<id>` (read-only)

Lists a page's history ring so you know which `steps_back` / `history_index` to restore. Needs no run token.

```bash
wp pp operate composition-history --post_id=42
```

Output carries `count`, the ring `max` (10), and per-entry `{history_index, steps_back, version, timestamp, components}`, newest first.

### In the AI chat

After a proposal that changes a page's composition applies, the chat renders an **"Undo these changes"** link (parity with the token "Reset to default" link). It calls `restore_composition` with `steps_back` equal to the number of composition mutations in the proposal, walking the ring back to the state before the proposal. It appears only when the proposal's composition mutations all target a single page.

---

## `wp pp readiness` — classified findings (#496)

Readiness/preflight warnings carry a **class** and a sanctioned **next action**, so an operator can group them and always know what to do — resolve, re-baseline, or acknowledge. This command family is the operator surface for that model. The same classified block appears as `findings` in `wp pp operate inspect` and `wp pp apply preflight` output.

**Classes:**

| Class | Meaning | Sanctioned resolution |
|---|---|---|
| `integrity` | Theme file drift vs the recorded release baseline | `wp pp readiness rebaseline` |
| `configuration` | Site-state gap resolvable through a safe surface (e.g. an unassigned menu location) | Fix through the surface (e.g. `set_menu`), **or** acknowledge as intentional |
| `capability` | An environment tool is missing or misconfigured (e.g. a screenshot browser, #497 — the finding's `state` is `unavailable` or `broken`) | Run the finding's next action (e.g. `wp pp screenshot doctor`) |

Only **findings** carry a class; passing/healthy rows and hard preconditions do not. Only **configuration** findings are acknowledgeable.

### `wp pp readiness status` (read-only)

Prints current findings grouped by class, each with its `next_action`, plus `active_warnings` and `acknowledged` counts. Never mutates.

```bash
wp pp readiness status
```

### `wp pp readiness rebaseline`

Re-snapshots the deployment manifest against the currently-installed theme files and records the installed release version. The sanctioned reconciliation for integrity drift: afterward drift always means "changed since this release", never "stale baseline". (`wp pp sync check --save-manifest` also records the version.)

```bash
wp pp readiness rebaseline
```

### `wp pp readiness acknowledge <finding-key> [--note=<text>]`

Records a configuration finding as intentional (e.g. a deliberately menu-less footer). It then reports as `acknowledged` instead of an active warning, and drops out of the post-apply warning list. Rejects any key that is not a currently-present configuration finding (run `status` to see valid keys).

```bash
wp pp readiness acknowledge nav_readiness:footer:no_menu --note="footer is deliberately menu-less"
```

### `wp pp readiness unacknowledge <finding-key>`

Reverses an acknowledgement. If the underlying condition still holds, the finding returns as an active warning.

```bash
wp pp readiness unacknowledge nav_readiness:footer:no_menu
```

---

## `wp pp schema` — the component contract (#688)

The schema is the contract every composition write is judged against, and until #688 the only way to read it was to open `components/<name>/schema.json` off disk. An agent working over SSH, or through the chat, could not. This command family is that read surface.

Read-only. No `--run-id`, no `--post_id`, no capability beyond WP-CLI's. It touches no page and mints no run token, so it is safe at any point in a run. Registration: `WP_CLI::add_command('pp schema', 'PP_Schema_Command')` (`lib/cli.php`).

### `wp pp schema`

Lists every **registered** component and whether it is composable.

```bash
wp pp schema
```

```json
{
  "components": [
    { "component": "cta", "composable": true },
    { "component": "footer", "composable": false },
    { "component": "nav", "composable": false }
  ]
}
```

Twelve entries, in loader (`scandir`) order. `composable: false` marks site chrome the page template renders itself (`nav`, `footer`): readable here, but rejected at write time with `template_owned_component` if placed in a composition.

### `wp pp schema <component>`

Prints one component's declared contract.

```bash
wp pp schema hero
```

| Field | Type | Notes |
|---|---|---|
| `component` | string | The directory name, which is what addresses the component |
| `description` | string\|null | `null` when the schema declares none |
| `composable` | bool | False for `nav` and `footer` |
| `content_requirement` | object | **Present only when declared.** Today only `section` (#488) |
| `malformed` | bool | **Present only when** `schema.json` could not be decoded, so an empty report is never mistaken for an empty contract |
| `props` | array | One object per declared prop, in declaration order |
| `style_slots` | array | One object per declared style slot, in declaration order |
| `recipes` | array | One object per declared recipe, in declaration order |

Each entry carries **the schema's own keys and values, verbatim** — nothing injected, nothing dropped, order preserved — plus the map key promoted to `name` (props, recipes) or `slot` (style slots). So a prop object is exactly its `schema.json` declaration with `name` added:

```json
{
  "slot": "--hero-surface-bg",
  "type": "gradient",
  "default": "soft surface gradient",
  "description": "Background of the inner proof/artifact surface panel …",
  "applies_when": [
    { "prop": "layout", "equals": "split" },
    { "prop": "proof", "present": true }
  ],
  "applies_when_rendered": "layout = \"split\" AND proof is set"
}
```

**`applies_when_rendered` is the one derived field.** It is the whole condition, phrased by the runtime AI catalog's own formatter (`pp_ai_format_applies_when_clause()`), so the CLI and the chat catalog describe a slot in the same words. It conjoins the `applies_when` clauses **and** any `conditionality_note` with ` AND `, because a declaration carrying both means both. It is `null` — never a partial phrase — when nothing is declared, or when any declared clause is unreadable: a shorter condition that reads as complete would send an agent to style a slot that paints nothing.

Two more entry-level fields appear only on damaged input: `malformed: true` with a raw `declaration` when a definition is not an object, and `shadowed_keys` naming any declared key the promoted or derived fields displaced.

### Unknown component

Names are matched exactly and are never canonicalised (`Hero` is not `hero`), matching the rest of the pipeline (#603–#606). The refusal names every available component, so a miss is one line from a fix. Exits **1**, prints nothing to stdout.

```
Error: Unknown component "Hero". Available: cta, embed, faq, footer, grid, hero, logos, nav, section, stats, table, testimonials. Names are case-sensitive and are never canonicalised.
```

### Scope

`props`, `style_slots` and `recipes`. The remaining `styling` declarations — `root_class`, `variant_classes`, `tokens`, and the `chrome_custom_properties` that are `nav`'s and `footer`'s **entire** styling surface — are not emitted, and still need `schema.json` or [`ai-instructions/style-component.md`](../ai-instructions/style-component.md).

---

## Related

- 🔒 Why a mutation can't land before the gate: [operating-loop-safety.md](operating-loop-safety.md) (explanation)
- 🧭 The safe apply→rollback walkthrough: [howto-apply-and-rollback.md](howto-apply-and-rollback.md) (how-to)
- 🤖 Letting an AI agent run your site: [running-an-ai-agent.md](running-an-ai-agent.md)
- 🔁 The full operating contract and command reference: [`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
