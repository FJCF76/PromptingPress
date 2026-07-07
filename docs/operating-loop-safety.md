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
post exist and have a composition" check). The documented loop even encoded the
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

`wp pp operate inspect` mints a **run token** (a UUID v4) and writes a small JSON
state file in the system temp dir. Every mutating command takes that token via
`--run-id` and checks the recorded state before doing anything. INSPECT must come
first; mutations come later. Out-of-order CLI calls are refused. The token is
bound to a site identity (site URL + database + blog id) and expires after two
hours, so a token from one install or an old session can't drive a mutation
somewhere else.

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
re-preflight. (Rejecting a concurrent write that lands in the instant between the
freshness check and the write itself is a separate, tighter guarantee tracked as #13.)

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

## 📚 Related

- 📦 Every `wp pp apply` command, flag, output shape, and error: [reference-apply-cli.md](reference-apply-cli.md)
- 🧭 The safe apply→rollback walkthrough: [howto-apply-and-rollback.md](howto-apply-and-rollback.md)
- 🔁 The operating contract and command reference: [`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
- 🤖 How to prompt an agent to run your site safely: [running-an-ai-agent.md](running-an-ai-agent.md)
- 🛡️ Why direct theme-file edits are blocked (the sibling file-safety system): [upgrade-safety.md](upgrade-safety.md)
