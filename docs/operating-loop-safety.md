# 🔒 Operating-loop safety — why a DB write can't land before the safety gate

When an AI agent operates a PromptingPress site, every change it makes to a page
goes through a small set of typed commands. The danger is obvious once you say it
out loud: an agent that writes to the database *before* anyone checks whether the
write is safe can corrupt a live page, and you only find out after the fact.

PromptingPress closes that trap with two cooperating mechanisms: a **run token**
that enforces the order of operations, and a **preflight gate** that must pass,
for the specific thing you're about to change, before any mutation is allowed.
This is the design-rationale companion to the operator-facing
[`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
contract. It explains the *why*; the contract is the *what*.

> 💡 **The one rule:** a mutating command (`wp pp action execute`,
> `wp pp operate patch`, `wp pp apply execute`) refuses to run until the run has a
> completed **PREFLIGHT covering the exact target** it is about to change. Preview
> commands stay read-only and need no run token at all.

## 📖 The problem

The operating loop has steps that read state and steps that change it. The
mutating steps write DB-backed data: a page's composition (`_pp_composition`), its
title, its publish status, and site-level design tokens.

Before this gate existed, the loop let a typed action change a page's composition
*before* `wp pp apply preflight` ran its safety checks (target resolution, drift
detection, capability, surface classification, and the page-specific "does this
target post exist" check). The documented loop even encoded the
wrong order: it listed EDIT (the typed mutation) before PREFLIGHT (the gate). So a
production page could be mutated, and only then would the gate that was supposed to
protect it get a chance to object. By then the write had already landed.

A naive fix makes it worse. "Require *any* completed preflight" sounds safe but
isn't: if a preflight for post 4 (or a site-wide token preflight that names no
post at all) could unlock a composition write to post 7, the gate would hand out a
false pass for the exact case it exists to stop. Page edits need page-specific
proof, not a blanket "some preflight happened."

## 🛠️ The approach

### The run token enforces order

`wp pp operate inspect` mints a **run token** (a UUID v4) and writes its state to a
per-run, non-autoloaded row in the install's `wp_options` table. Every mutating
command takes that token via `--run-id` and checks the recorded state before doing
anything. INSPECT must come first; mutations come later. Out-of-order CLI calls are
refused. The token is bound to a site identity (site URL + database + blog id) and
expires after two hours, so a token from one install or an old session can't drive a
mutation somewhere else. Storing the state in the options table (rather than a file in
the system temp dir) means it is shared across every CLI process that can operate the
install — including setups where each `wp` call runs in a separate ephemeral container
with its own `/tmp`, which the old temp-dir file could not survive (#409).

### Preflight records the target it covered

When `wp pp apply preflight` passes, it doesn't just record "a preflight
happened." It records **what the preflight covered**:

- a specific `post_id` when you preflight a page (`--post_id=N`), or
- the **site grain** when you preflight with no post (site-level work like
  creating a page or changing a site option).

A mutating action then asks a precise question through
`pp_operate_preflight_covers()`: *is there a completed preflight covering my exact
target?* The match is strict and refuses to weaken:

- A page/section mutation on post N requires a preflight recorded for **post N**. A
  site-grain preflight does **not** cover it.
- A site mutation requires a **site-grain** preflight. A page preflight does not
  cover it.

That strictness is the whole point. A preflight for post 4 never unlocks a write
to post 7, and a site preflight never quietly unlocks a page edit.

### Coverage proves a preflight ran; freshness proves it's still valid (#113)

Coverage answers "did a preflight for this target run?" — but not "is the target
still what the preflight checked?" Between a covering preflight and the mutation (or
across two mutations after one preflight), the composition can change through another
path: another CLI run, the dashboard editor, the publish flow. The mutation would
then land on a state nobody preflighted.

So a page-scoped preflight also records a **freshness marker** for the composition — a
`{version, hash}` pair bumped on every write. A composition-mutating `action execute`
or `operate patch` re-reads the live marker and rejects a target that changed since
the preflight with a `composition_conflict` error. Your own run's sequential edits
still flow, because the run's baseline refreshes to the new marker after each write;
only a change from *another* path trips the gate. When it does, re-inspect and
re-preflight.

That preflight check is a pre-check, so it still leaves a hair-thin window: a
concurrent write could land in the instant between the check and the write itself.
The write-time **compare-and-swap** (#13) closes it. The run threads the validated
baseline version into the write, and the single composition-write choke point
(`pp_update_composition`) re-reads the version fresh from the DB **inside the per-post
advisory lock** and refuses the write if it moved, returning the same
`composition_conflict`. Because the compare and the write are both under the one lock,
there is no gap left for a lost update to slip through. The baseline is optional at the
choke point (a new page or a legacy direct write omits it and writes unconditionally),
so the guarantee is **opt-in per writer**. Three writers opt in today: the WP-CLI
operate loop (it threads the freshness-validated baseline through `action execute` /
`operate patch`), the dashboard editor's save/publish AJAX (it echoes the loaded
version back as `expected_version`), and the **chat AI path** (#404).

The chat path opts in **mandatorily and fail-closed**, not opportunistically. When the
model reads a page's context, the server captures that page's `composition_version` as the
conversation's per-page baseline. Both chat entry points then require it: a composition-
mutating `wp_ajax_pp_ai_execute` REJECTS the write with `missing_expected_version` if no
baseline is supplied, and `wp_ajax_pp_ai_execute_batch` REJECTS the whole batch — before
executing any step — if any composition-mutating step's target page lacks a baseline (so
nothing runs and there is nothing to roll back). A batch threads a per-`post_id` baseline
map and chains each write's server-derived post-write version into the next mutating step
on that page, so a multi-step proposal never false-conflicts against its own earlier
writes. Applies (token writes, [#393](https://github.com/FJCF76/PromptingPress/issues/393))
are outside the composition CAS. On `composition_conflict` the handler returns the
structured envelope (`error_code` + `expected_version`/`current_version`), and the chat UI
shows one affordance — **Re-read & re-preview** — which re-reads the page for a fresh
baseline and re-previews the proposal against current state before the user confirms again;
it is never a blind retry that re-sends the stale write with a bumped version. That count is
about THIS class, not about the card: since #704 a card refused by VALIDATION grows a
different affordance (**Ask the AI to fix it**, which sends an operator-approved repair
request to the model). A conflict deliberately gets neither that button nor the model-facing
note that accompanies it — the page moved under a proposal that was correct when written, so
re-reading is the repair and the model has nothing to fix. The writer's
own null-skips-CAS back-compat is untouched — the mandate lives in the chat entry points'
contracts. This shipped per the baseline-lifecycle design on
[#392](https://github.com/FJCF76/PromptingPress/issues/392) (v1.2.0 gate).

### The loop runs PREFLIGHT before EDIT

```
INSPECT  →  PLAN  →  PREFLIGHT  →  EDIT  →  APPLY  →  SCREENSHOT  →  REVIEW  →  HANDOFF
                     ^^^^^^^^^      ^^^^
                     gate first     mutation second
```

The safety gate now sits in front of the mutating steps, not behind them. The
typed action that changes the database only runs once a covering preflight is on
record.

### The unlock is recorded fail-closed, in one atomic write

There's a subtle trap in recording the unlock. A mutating action unlocks on the
recorded coverage alone, unlike `apply execute`, which also checks for a rollback
snapshot. If the coverage were written first and a later required write (the
rollback snapshot) failed, a run could end up *unlocked but unprotected*: the gate
would pass even though the preflight command errored.

So `pp_operate_record_preflight()` commits everything the successful preflight
produces — the PREFLIGHT step, the target coverage, and the pre-apply rollback
snapshot — inside a **single locked write**. The run gains the complete
post-preflight state or none of it. Any failure leaves both the action gate and
the apply gate fail-closed: no coverage recorded means no mutation unlocked.

The rollback snapshot itself is read **under the token lock** for an atomic
baseline, and it fails closed on three conditions rather than freezing a baseline a
later `apply restore` would wrongly revert to. First, if the lock is contended —
another writer is racing, exactly the case the baseline protects against — it
refuses to degrade to a stale, non-atomic read. Second, if the stored
`pp_token_overrides` row is unreadable — corrupt, truncated, or hand-edited into
something that isn't an array — it refuses to treat that as "no overrides." Third,
if the option read itself fails at the database (a query error on the `SELECT`,
detected via a non-empty `$wpdb->last_error`), it refuses to mistake that failed
read for a genuinely absent row. The last two cases matter because an empty
baseline is not harmless: `apply restore` reverts every touched token off an empty
snapshot by **deleting** it, so recording `[]` for a corrupt row or a failed read
would turn a restore into silent token loss. In any of these cases
`wp pp apply preflight` **errors and records nothing**. Re-run the preflight once
the contention clears; if the error persists, inspect and repair the
`pp_token_overrides` option, or check whether the database is erroring.

### Validate first, so errors point at the real problem

A mutating action validates its parameters *before* it checks the gate. If you
target a page that doesn't exist, you get "post N does not exist," not a confusing
"go preflight a page that isn't there." The gate only demands a preflight for an
action that would actually run.

## 🗂️ Two classes of gate (and where each one lives)

The gates above are not all the same kind of thing, and conflating them is how a
data-safety rule can quietly end up enforced in only one caller. Sort every gate
into one of two classes:

- **Loop-discipline gates** enforce *operator workflow order* — the run token,
  the INSPECT→PLAN→PREFLIGHT→EDIT→APPLY→HANDOFF sequence, "no mutation before a
  covering PREFLIGHT is on record." They exist to keep a human/agent operating a
  CLI run honestly. They can legitimately stay **WP-CLI/operate-loop-specific**,
  because they gate the *loop*, not the *write*: a caller that never joins a run
  (the dashboard editor, a chat AJAX action) is not skipping a data-safety
  invariant by not having a run token.
- **Data-safety invariants** protect the *bytes in the database* — that a write
  can't clobber a concurrent one, can't land on unvalidated content, and can't
  mutate something with no way back. These must sit at a **shared choke point**
  every writer passes through, or, where they don't yet, be **named here as a
  caller-specific gap** so nobody assumes a system-wide guarantee that isn't there.

| Gate | Class | Where it lives today |
|------|-------|----------------------|
| Run token / `--run-id`, 2-hour expiry, site binding | Loop-discipline | WP-CLI only (`lib/cli.php`, `lib/operate.php`) |
| INSPECT-first + PREFLIGHT-before-EDIT ordering | Loop-discipline | WP-CLI only |
| Preflight **coverage** match (post-N vs site-grain) | Loop-discipline (proves a covering preflight *ran*) | WP-CLI only (`pp_operate_preflight_covers`). **One carve-out since #767:** `update_composition` / `restore_composition` on a page whose stored composition is already classified corrupt, which `apply preflight` refuses to preflight — see the row below |
| Preflight **freshness** pre-check (#113) | Data-safety | WP-CLI only (`_pp_cli_require_composition_fresh`) — pre-check, not the guarantee. Lifts with coverage under the #767 carve-out (its baseline is recorded *by* the preflight that page cannot pass), which substitutes a **live** CAS baseline so the write-time gate below still holds |
| Write-time **compare-and-swap** (#13) | Data-safety | Shared choke point (`pp_update_composition`), **opt-in**: CLI ✅, editor AJAX ✅, chat AJAX ❌ (#392) |
| History-ring **authoritative read** (#823) | Data-safety | Shared choke point (`pp_update_composition`), inherited by every caller. The ring is rebuilt read-modify-write inside the per-post advisory lock, and the READ is now the database row rather than the object cache — a request that warmed the post's meta cache before waiting on the lock used to rebuild from its pre-lock copy and silently drop whatever the writer it waited for had pushed, which since #818 can be the only recoverable copy of a corrupt page's bytes. Two degradations, both falling back to the cached ring **with a log line** rather than to an empty one (an empty one would persist over the other nine slots): a failed read (#212), and a row that is present but does not decode to a list. **Selection is covered too since #829**, through the same reader: `restore_composition` still PICKS against the cached ring (the ring the operator listed and the preview reported on), and then CONFIRMS that pick inside the write lock against the authoritative row. A concurrent write that shifts what `steps_back` / `history_index` names is refused with `history_target_shifted` and writes nothing, instead of handing the operator a state they did not pick behind an `ok: true`. The residual gap is the same two degradations above, not selection: when the authoritative read falls back, it falls back to the very cached ring the caller already resolved against, so the confirmation agrees by construction and the restore behaves as it did pre-#829 |
| Pre-apply **rollback snapshot** | Data-safety | Recorded in the CLI preflight only; apply/token mutation outside a CLI run is not yet covered (#393) |
| `composition_required` precondition (#358) | Data-safety | Shared choke point (`pp_validate_action`) — every executor caller inherits it: CLI, chat AJAX, batch, `pp_patch_composition()` (#387). Since #748 the refusal distinguishes a blank page ("has none yet") from an unreadable one (the shared integrity sentence naming `unexpected_shape` / `decode_error`); the gate itself is unchanged |
| Unreadable-composition **batch refusal** (#749) | Data-safety | Batch executor only (`pp_ai_execute_batch`) + the chat batch entry point (`_pp_ai_execute_batch_response`), both asking one owner (`_pp_batch_unreadable_refusal`) so they cannot drift about WHICH batches refuse, and since #833 one owner for WHAT a target is classified from too (`_pp_batch_target_refusal_reason`, used by both detectors and by the rollback's re-classify). Single-step execute, editor AJAX and `pp_patch_composition()` are **not** covered — and deliberately so: they take no rollback snapshot, so they have no snapshot to write back over the corrupt bytes. Since #756 the refusal admits ONE batch shape, the repair it prescribes: a lone `update_composition`/`restore_composition` step on an already-corrupt page (see the row below). Single-step-only, because with a second step a later rollback writes the degraded `[]` snapshot over the repair that just landed. **Since #833 the refusal classifies from the database row, and fails closed.** It used to classify through the object cache while the carve-out beside it read the row, so a request holding a stale-healthy cached copy over a corrupt row reported nothing and the batch simply ran (the rollback baseline was then captured from that stale copy). A target now blocks unless BOTH reads classify it clean — the cached check survives as an added requirement, never as an alternative source, which is what keeps the cache-corrupt/row-healthy refusal #756 pinned and what lets the snapshot keep capturing its baseline from the cached read. The gate blocks with `composition_unverifiable` whenever the row cannot be read, which is TWO cases: no usable database handle (`pp_composition_db_handle`), a unit-context and exotic-host path since production WordPress always has one; and a row query that FAILED (`$wpdb->last_error` non-empty), which is reachable in production under exactly the contention that produces the race — `get_var()` reports a killed query, a lock-wait timeout or a gone-away connection the same way it reports an absent row, and the authoritative reader maps that to a blank READABLE page, so trusting it at a gate was a fail-open. Same `#212` disambiguation the two sibling authoritative readers make. Neither case says anything about the page and neither prescribes a repair. A batch naming no existing page classifies nothing and is never refused for a database it never needed |
| Rollback **withhold** on an unreadable capture (#749, widened #756) | Data-safety | `_pp_restore_batch_snapshot()`, the other half of the carve-out. Two branches, and the order matters: a page the snapshot recorded as unreadable never has its composition written back at all (its capture is either the degrading accessor's `[]` or, since #833, a cached copy the authoritative row contradicts — unconfirmable either way, which is why it is never written back), and a page that was readable at capture but is unreadable NOW is withheld by the live re-classify. The first is what stops the carve-out's own rollback erasing the repair it just admitted — a case the live check cannot catch, because a repaired page reads fine. Both report through `rollback_errors` (#755: `rolled_back: true` is not clean until you check it). The live re-classify reads the same way the refusal does since #833 — the database row, failing closed with no handle — because asking the object cache there echoed the snapshot-time verdict on installs without a persistent object cache, which is the same hole one step later |
| Corrupt-page repair **carve-out** (#767, ruling D-1) | Loop-discipline waiver, deliberately narrow — and it does **not** move a data-safety invariant into a CLI wrapper, which the takeaway below forbids. It lifts the freshness row's CLI-side *pre-check* only; that row's data-safety guarantee lives at the shared choke point (the write-time CAS), and the carve-out feeds that choke point a live baseline instead of a preflight-recorded one, so the guarantee is unchanged | Shared predicate (`pp_corrupt_page_repair_carve_out`, `lib/operate.php`), consumed by the WP-CLI preflight gates and, since #756, by the chat batch refusal (`_pp_batch_corrupt_repair_admitted`, which adds the batch-shape condition the CLI has no equivalent of: exactly one step). Admits `update_composition` / `restore_composition` **without a covering preflight** when — and only when — the stored composition is already classified `unexpected_shape` / `decode_error`. Reads the classification from the **database**, not the object cache, so a stale value cannot open it (#823 documents the warming path); since #833 the refusal it sits beside reads the row too, so the two halves of the gate no longer answer one question from two sources. It waives the two preflight gates above and nothing else: run token, INSPECT-first, `pp_validate_action()`'s full validation of the incoming replacement, the #233 restore contract, #818's `history_entry_not_restorable`, #829's `history_target_shifted` (a restore whose selector was moved by a concurrent write is refused, not replayed), and the write-time CAS all still apply, and the carve-out records no coverage, so it never unlocks a second action. The predicate deliberately knows nothing about the REQUEST (tokens, capabilities, nonces, provenance) — each consuming surface keeps its own |
| `pp_patch_composition()` as a corrupt-page repair route | — | **Does not exist**, despite pre-#767 wording in five docs that listed it. It is refused on a corrupt page by the `composition_required` row above (#748), and a field selector cannot reshape a container regardless. The routes are the two CLI verbs, the dashboard editor, and (since #756) a chat proposal whose only step is one of those two verbs |

The takeaway for future hardening: leave loop-discipline gates in the CLI, but do
not move a data-safety invariant into a CLI wrapper. When you add one, put it at
the shared validator/executor choke point so every caller inherits it — or, if you
can't yet, add a row here marking the caller that still lacks it.

### Scope of this release

**v1.0.1 is executor-level safety hardening, not a redesign of the operate loop.**
It classifies the existing gates (this document), adds fail-closed test coverage,
unifies the CLI patch path with the shared gate stack, and moves the
`composition_required` precondition into the shared executor so the chat path
inherits it ([#387](https://github.com/FJCF76/PromptingPress/issues/387)). It does
**not** rethread the remaining data-safety invariants through every caller. Two
caller-specific gaps are tracked and deliberately left for later: chat-path CAS
([#392](https://github.com/FJCF76/PromptingPress/issues/392), v1.0.2) and reversible
apply/token mutation outside CLI runs
([#393](https://github.com/FJCF76/PromptingPress/issues/393), unscheduled).

## ⚖️ Trade-offs (made on purpose)

- **Operators run an extra step per page.** You must preflight each page
  (`--post_id=N`) before editing it. That is friction, and it is the cost of the
  page-specific guarantee. A blanket "any preflight" would remove the friction and
  the protection together.
- **Creating a page is a two-phase flow.** A brand-new page has no `post_id` to
  preflight against, so page creation is site-scoped (covered by a site preflight).
  If you then set its composition as a separate page-scoped step, you preflight
  that new `post_id` first. Creating the page with its composition inline keeps it
  to one site-scoped mutation.
- **`operate patch` mutations now require a run token.** The mutating
  `wp pp operate patch` path was previously callable standalone. It now needs
  `--run-id` plus a covering preflight, the same as `action execute`. The
  `--preview` path stays standalone and read-only. This is a deliberate breaking
  change: the previously-ungated mutation was the hole.

## 🔭 Out of scope (on purpose)

- **Content freshness between preflight and mutation** ([#113](https://github.com/FJCF76/PromptingPress/issues/113)).
  The gate proves *ordering* (a covering preflight ran first), not that the
  composition still matches what preflight validated. Two actions after one
  preflight, or a concurrent edit, can move the state. Single-operator run tokens
  make this low-risk today; a content-hash freshness check is tracked separately.
- **Finer-grained site-target coverage** ([#114](https://github.com/FJCF76/PromptingPress/issues/114)).
  A no-post preflight covers all site-scoped actions for the run's lifetime. That
  matches what `pp_preflight()` actually checks today; per-option coverage is a
  future refinement.
- **Reversible apply/token mutation outside a CLI run** ([#393](https://github.com/FJCF76/PromptingPress/issues/393)).
  The pre-apply rollback snapshot is recorded by the CLI preflight only; an apply
  or token mutation driven from outside a run has no equivalent pre-mutation
  snapshot yet.

## 📚 Related

- 📦 Every `wp pp apply` command, flag, output shape, and error: [reference-apply-cli.md](reference-apply-cli.md)
- 🧭 The safe apply→rollback walkthrough: [howto-apply-and-rollback.md](howto-apply-and-rollback.md)
- 🔁 The operating contract and command reference: [`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
- 🤖 How to prompt an agent to run your site safely: [running-an-ai-agent.md](running-an-ai-agent.md)
- 🛡️ Why direct theme-file edits are blocked (the sibling file-safety system): [upgrade-safety.md](upgrade-safety.md)
