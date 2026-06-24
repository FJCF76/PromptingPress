# Running an autonomous AI agent safely on a PromptingPress site

This walks you through pointing an autonomous coding agent (Claude Code, or any
LLM with shell + WP-CLI access) at a PromptingPress site and having it change the
site through a structured, ordered, evidence-producing loop instead of editing
files by hand. By the end you'll have run the operating loop end to end against a
real install and seen where it stops the agent and hands control back to you.

**Read this first, because the honest framing matters:** PromptingPress gives an
agent a *safe path* and a set of *tripwires*. It does not sandbox the shell. An
agent with SSH access can still run raw `wp eval`, edit a file in vim, or drop a
table with `wp db query` — the loop cannot prevent any of that. What it does is
make the structured path the path of least resistance, refuse out-of-order `wp pp`
commands, classify which files are off-limits, and detect after the fact when
someone edited a theme file directly. This is **safety-gated autonomous operation,
not permissionless self-modification**. Treat it that way.

---

## What you'll need

- A WordPress site running the PromptingPress theme, on a VPS you can reach over SSH.
- WP-CLI installed and working as the web user (`wp core version` returns a number).
- An agent process running in that SSH session with permission to run `wp` commands.
- **Strongly recommended: a dev/staging install, not production.** Point the agent
  there first. PromptingPress itself is built dev-first for this reason. Promote to
  production only after a human reads the agent's HANDOFF report. The loop reduces
  risk; it does not remove the need for a human gate before a live site changes.

Confirm the agent can see the site:

```bash
wp pp operate inspect
```

If that returns a JSON operating picture with a `run_id`, you're ready. If `wp pp`
is unknown, the theme isn't active — activate PromptingPress first.

---

## Why editing files directly is dangerous

The obvious thing an agent does on a WordPress site is open a theme file and edit
it. On PromptingPress that is the wrong move, for four concrete reasons:

1. **It gets erased.** `templates/`, `components/`, and `assets/` are release
   artifacts. A theme update replaces the whole directory, so a hand-edit is
   overwritten or deleted. As of v0.11.0 an update is even **blocked** when it
   detects such drift (see [upgrade-safety.md](upgrade-safety.md)).
2. **Nothing validates it.** A raw edit can produce a broken composition, an
   invalid token, or a missing image and no one notices until a visitor does.
3. **There's no evidence.** No screenshot, no checklist, no record of what changed
   or whether it rendered correctly.
4. **There's no undo.** A typed apply writes a backup and can be rolled back; a
   text-editor save cannot.

PromptingPress moves every site change off the files and into a validated mutation
layer: **typed actions and applies, compositions stored in post meta, and design
tokens stored in the `pp_token_overrides` database option.** All of it survives
theme updates, all of it is validated, and all of it runs through one ordered loop.

---

## The operating loop, in operator terms

Eight steps, four roles. The agent plays each role in turn:

```
Strategist   1. INSPECT     read the site before touching it      (gets the run token)
             2. PLAN        declare what will change, in writing
Implementer  3. EDIT        make composition changes via typed actions
Operator     4. PREFLIGHT   safety gate: can this be applied here?
             5. APPLY       commit the mutation, with a backup
Reviewer     6. SCREENSHOT  capture the rendered result at real viewports
             7. REVIEW      compare the screenshot to the brief / checklist
Operator     8. HANDOFF     report what changed, what's verified, what's unresolved
```

The full contract the agent follows lives in
[`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md); the
file map and component reference live in
[`AI_CONTEXT.md`](../AI_CONTEXT.md). Point your agent at both.

---

## How to prompt the agent

### The prompt is doing real work here

Because PromptingPress is not a shell sandbox, nothing forces the agent onto the
safe `wp pp` surface except (a) the OS limits you set up around it and (b) your
prompt. The prompt is what binds the agent to the operating loop. Once the agent
commits to working through `wp pp`, PromptingPress takes over inside that surface:
run tokens enforce ordering, preflight classifies off-limits files, and the
integrity guard catches a file edited outside the loop.

So the division of labor is: **your prompt keeps the agent on the rails; the
framework enforces the rails.** A vague "fix my homepage" to an agent with a shell
is an invitation to open a file and edit it. An explicit operating contract is what
keeps it on the validated path. The prompt is necessary, not sufficient — pair it
with the OS-level controls (run on dev, separate user owning the theme files,
scoped DB user); see [What this does and does not guarantee](#what-this-does-and-does-not-guarantee).

### Give the agent this operating contract

Paste this at the start of a Claude Code session (or any SSH/WP-CLI agent) before
giving it a task. Adjust the theme path and dev-site detail to your install:

```text
You are operating a PromptingPress WordPress site through WP-CLI on this server.
This is the dev install — do not touch production.

Before doing anything:
1. Read ai-instructions/operating-loop.md and AI_CONTEXT.md in the active theme
   directory. operating-loop.md is your operating contract; AI_CONTEXT.md is the
   site map and component/action reference. Follow the loop; do not reorder it.
2. Always start with `wp pp operate inspect` (use --post_id=<id> for a page). Take
   the run_id it returns and pass it as --run-id on every command that changes state.

Hard rules:
- Never edit theme files directly. No text editor, no `wp eval`, no shell
  redirection into templates/, components/, or assets/. Those are release artifacts
  and get overwritten on update.
- Make every change through PromptingPress typed actions and applies
  (`wp pp action execute <name> --run-id=<uuid> --params='...'`,
  `wp pp apply execute <name> --run-id=<uuid> --params='...'`), compositions, and
  design tokens. Never raw file writes.
- Run `wp pp apply preflight --run-id=<uuid>` before any apply. If preflight fails,
  STOP — do not work around it.
- Capture screenshots (`wp pp screenshot capture --post_id=<id> --playbook=<name>`)
  as evidence before you claim anything renders correctly.
- End every task with a HANDOFF report: status (VERIFIED / NEEDS_VISUAL_VERIFICATION
  / SCREENSHOT_FAILED), what changed (actions, applies, files), screenshot paths,
  checklist/review results, drift and preflight status, and any concerns.

Stop and ask me before continuing if:
- preflight fails,
- detected drift overlaps the files you planned to change,
- a visual review fails twice,
- the task needs anything outside PromptingPress' validated surface (installing
  plugins, editing wp-config.php, database changes, deploys, DNS).

Do not mark work VERIFIED without screenshots and a fully evaluated checklist. I
will read your HANDOFF before anything is promoted to production.
```

This is the layer that closes the "I'll just edit the file" gap at the instruction
level. The framework closes it again inside the `wp pp` surface, and the OS controls
close it a third time. Defense in depth, not one rule.

### Task prompts

Once the contract is set, keep task prompts short and concrete. Name the page, the
change, and let the contract carry the safety steps.

**Revise a homepage section:**

```text
On the dev homepage (post 74), rewrite the hero subtitle to "Structured WordPress
for AI agents" and cut the features grid to its three strongest items. Follow the
revise-section playbook. Hand off with desktop and mobile screenshots.
```

**Create a new page:**

```text
Create a "Pricing" page from this brief: three tiers (Free, Pro, Team), a short
intro, and a CTA to the contact page. Use the create-page playbook — inspect, plan
the composition, build it with typed actions, preflight, apply, screenshot, hand off.
```

**Inspect and fix a visual issue:**

```text
The About page (post 31) looks broken on mobile — the CTA button overflows its
container at 375px. Use the inspect-fix playbook: inspect for smells, propose a fix
through design tokens or the composition (not a CSS file edit), preflight, apply,
re-screenshot mobile, hand off. If the only fix is a core-file change, stop and tell
me instead of editing it.
```

### What to require before you accept the work

Treat the HANDOFF as the deliverable, not the chat summary. Before you call a task
done, the agent must give you:

- **A HANDOFF report.** No "done!" without one.
- **Screenshot paths, or an explicit `NEEDS_VISUAL_VERIFICATION` status.** If no
  browser was configured, the agent must say so — not silently claim it looks good.
- **The checklist/review result** — which hard gates passed, which soft gates were
  noted.
- **Drift and preflight status** — preflight passed; any drift recorded; nothing
  overlapping the change.
- **Unresolved concerns**, called out explicitly.

Reject any `VERIFIED` that arrives without screenshots and a fully evaluated
checklist — that status has specific requirements, and "looks fine" is not one of
them. If anything is `NEEDS_VISUAL_VERIFICATION` or `SCREENSHOT_FAILED`, you are the
visual reviewer before this reaches production.

---

## Step 1 — Inspect (and get the run token)

Always start here. Inspect is read-only and returns the whole operating picture:
target environment, composition pages, drift state, preflight status, design
tokens, CSS conflicts, and composition smells. It also mints a **run token** — a
UUID v4 written to a state file that every later mutating command must quote.

```bash
wp pp operate inspect --post_id=74
```

Grab the `run_id` from the output. Everything that mutates state from here on takes
`--run-id=<that-uuid>`. For a single page's editable fields and selectors:

```bash
wp pp operate inspect-composition 74
```

You now have a working result in one step: the agent can see exactly what's on the
page and what it's allowed to change.

## Step 2 — Plan

The agent writes down, before changing anything: which components/sections it will
create, modify, or remove; which typed actions or applies it will call; and which
files (if any) the change touches. This plan is what PREFLIGHT checks against for
drift overlap. No command here — it's a declaration, and it's required output.

## Step 3 — Edit (typed actions, not file writes)

Composition changes go through typed actions, never a text editor:

```bash
wp pp action execute add_component --run-id=<uuid> \
  --params='{"post_id":74,"component":"grid","props":{"title":"Features"}}'
```

`action execute` **fails unless the run token shows a completed INSPECT step** — the
agent literally cannot edit before it has inspected.

## Step 4 — Preflight (the safety gate)

Before anything is committed, preflight checks the environment can take the change:

```bash
wp pp apply preflight --run-id=<uuid> \
  --planned-files='["assets/css/components.css"]'
```

Preflight verifies: the target is resolved (site URL, theme path, environment);
the caller has capability; the backup directory is writable; the theme is writable;
the target page exists; and, when you pass `--planned-files`, it **classifies each
path as safe / extension / core**. It also checks drift overlap between the manifest
and your planned files.

## Step 5 — Apply (commit, with a backup)

Only after preflight passes:

```bash
wp pp apply execute update_design_token --run-id=<uuid> \
  --params='{"token":"--color-accent","value":"#b45309"}'
```

`apply execute` **fails unless the run token shows a completed PREFLIGHT step.** The
apply writes a backup; `wp pp apply restore --run-id=<uuid>` rolls back to it.

## Step 6 — Screenshot (evidence, not assumption)

```bash
wp pp screenshot capture --post_id=74 --playbook=revise-section
```

Captures both viewports (1280px desktop, 375px mobile). If no browser is configured
(`PP_BROWSER_CMD` unset), the agent does not pretend it verified anything — it
carries a `NEEDS_VISUAL_VERIFICATION` status into HANDOFF.

## Step 7 — Review

```bash
wp pp operate checklist --playbook=revise-section
```

The agent evaluates the screenshot against the checklist looking only at what's
visible, not at what it believes it changed. A failed **hard gate** loops back to
PLAN (step 2), not to a blind retry of EDIT.

## Step 8 — Handoff

The agent produces a structured report: status (`VERIFIED`,
`NEEDS_VISUAL_VERIFICATION`, or `SCREENSHOT_FAILED`), what changed, screenshot
paths, checklist results, drift state re-checked at handoff, and any concerns for
you. `VERIFIED` is only allowed when every declared viewport was captured and every
checklist item evaluated. Optionally validate the whole run's completeness:

```bash
wp pp operate validate --run='<the run manifest JSON>'
```

This is the contract. On a live site, **the HANDOFF report is your gate** — read it
before the change counts as done.

---

## How run tokens enforce ordering

The run token is the mechanism behind "you can't skip steps." `wp pp operate
inspect` writes a state file keyed by the UUID and records the INSPECT step. Each
mutating command checks that file before doing anything:

```
inspect ──► run_id + INSPECT recorded
   │
   ▼  --run-id required from here on (commands error without it)
action execute   ──► refuses unless INSPECT is recorded
apply preflight  ──► records PREFLIGHT on success
apply execute    ──► refuses unless PREFLIGHT is recorded
apply restore    ──► refuses unless PREFLIGHT is recorded
```

So the ordering inspect → edit → preflight → apply is enforced for real on the
`wp pp` surface, not just suggested. Skip inspect and `action execute` errors out
with *"Run token has no completed INSPECT step."* This is real-time enforcement;
`wp pp operate validate` is the complementary post-hoc check that the finished run
actually hit all eight steps.

## When preflight blocks the work

Preflight is where the "I'll just edit the core file" shortcut dies. Ask to touch a
core theme file and it fails with routing guidance instead of letting the apply
through:

```bash
wp pp apply preflight --run-id=<uuid> --planned-files='["lib/wp.php"]'
# → preflight FAILS: lib/wp.php is a core file. Use a typed action/apply or escalate.
```

Other preflight stops: the backup directory isn't writable, the theme directory
isn't writable, the target page doesn't exist, or **planned files overlap with
detected drift**. The rule the agent follows: **preflight fails → STOP, do not
apply, report it in HANDOFF.** Drift that overlaps your planned files → **stop and
escalate to the human**, do not work around it.

## When the agent must stop and escalate to you

The loop is explicit about handing control back. The agent escalates — stops and
asks you — when:

- **Preflight fails.** No workarounds. Report and stop.
- **Drift overlaps the planned change.** Someone (or something) edited a file the
  agent was about to touch. Stop; this needs a human.
- **A hard gate keeps failing on REVIEW.** Maximum two PLAN→…→REVIEW loops, then
  escalate rather than thrash.
- **A theme update is blocked** by the integrity guard (drifted files). That's a
  human decision: restore, migrate the change into tokens/compositions, or override
  (see [upgrade-safety.md](upgrade-safety.md)).
- **Anything outside the validated surface** — installing plugins, editing
  `wp-config.php`, database surgery, DNS, deploys. The loop governs PromptingPress
  content and styling, nothing else.

---

## What this does and does not guarantee

Be clear-eyed about the boundary:

**It does** make the safe path easy, enforce step ordering on the `wp pp` surface,
classify off-limits files at preflight, produce visual evidence, give you a single
HANDOFF contract to review, and (via v0.11.0 integrity) catch a theme file that was
edited outside the loop.

**It does not** sandbox the shell. An agent over SSH still has the full power of
that shell — raw `wp eval`, file edits, `wp db query`, package installs. The
operating loop binds the agent only as far as the agent chooses to work through
`wp pp`. The integrity guard is a tripwire that catches file drift after the fact,
not a wall that prevents it.

So the safe operating posture is: **run on dev, give the agent the operating-loop
contract, require it to work through `wp pp`, and gate every promotion to
production on a human reading the HANDOFF.** Within that posture, an agent can do
real, repeatable, reviewable work on a site without the usual "it edited a file and
broke the homepage" failure mode. Outside it, you're trusting the agent, not the
framework.

---

## What you did

You ran the eight-step loop against a real PromptingPress install, watched run
tokens refuse an out-of-order command, saw preflight block a core-file edit, and
ended with a HANDOFF report you can actually review. You also saw the honest edges:
the loop is discipline plus tripwires on a structured mutation surface, not a
sandbox.

Next steps:

- The full agent contract and CLI reference: [`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
- The three playbooks: `ai-instructions/playbook-create-page.md`, `playbook-revise-section.md`, `playbook-inspect-fix.md`
- Why theme-file edits are blocked, and how to override: [upgrade-safety.md](upgrade-safety.md)
- The site map and component/action reference: [`AI_CONTEXT.md`](../AI_CONTEXT.md)
