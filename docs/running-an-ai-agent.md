# Running an AI agent safely on a PromptingPress site

You have a WordPress site on a VPS, and you want an AI coding agent (Claude Code, or
another agent with SSH and WP-CLI access) to make frontend changes for you. The risk
is simple: give an agent a shell and it will happily open a theme file and edit it
directly, which is how sites get quietly broken. This guide shows how to tell the
agent to work through PromptingPress' safe commands instead.

The key thing to understand: **PromptingPress is not a sandbox.** It can't stop an
agent that has a shell from doing whatever it wants. What keeps the agent on the safe
path is **your prompt.** Once the agent agrees to work through the `wp pp` commands,
PromptingPress enforces the order of steps and catches the obvious mistakes inside
that surface. The prompt is the part you own, and it matters.

This is not a deployment system, and it doesn't replace your hosting, your backups,
your version control, or your own review of the result. It narrows what the agent
does to your site's content and styling, through commands that validate the change
and can roll it back.

## What this solves

> "I have a WordPress site on a VPS and I want Claude Code to make frontend changes
> without freelancing through my theme files."

PromptingPress gives the agent a structured way to change pages and styling:

- **Compositions** (page layout) and **design tokens** (colors, fonts, spacing) live
  in the database and are edited through typed commands, not by hand-editing files.
- An **operating loop** makes the agent inspect first, plan, check before applying,
  and produce evidence, in that order.
- Editing theme files directly is discouraged and, since v0.11.0, a blocked shortcut
  (see [upgrade-safety.md](upgrade-safety.md)).

The agent still has a shell. Your prompt is what tells it not to use the shell to go
around all of that.

## The loop, in one glance

The agent follows eight steps. It reads the full contract from
[`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md) — you
don't have to.

```
1. INSPECT     read the site, get a run token       wp pp operate inspect
2. PLAN        say what will change, before doing it
3. EDIT        change the page via typed commands    wp pp action execute ...
4. PREFLIGHT   check it's safe to apply              wp pp apply preflight ...
5. APPLY       commit the change, with a backup      wp pp apply execute ...
6. SCREENSHOT  capture the result                    wp pp screenshot capture ...
7. REVIEW      compare the screenshot to the goal
8. HANDOFF     report what changed and what's verified
```

The run token from step 1 (`--run-id`) is required on every command that changes
anything, and those commands refuse to run out of order. That ordering is enforced
for you once the agent is on this path.

## What to tell the agent

This is the part that matters. Paste this at the start of a Claude Code session (or
any SSH/WP-CLI agent) before you give it a task:

```text
You are changing a WordPress site through PromptingPress, using WP-CLI on this
server. Work only through PromptingPress commands. Do not use the shell to go
around them.

First:
1. Read ai-instructions/operating-loop.md and AI_CONTEXT.md in the active theme.
   operating-loop.md is the loop you must follow; AI_CONTEXT.md is the site map.
2. Run `wp pp operate inspect` (add --post_id=<id> for a page) before anything else.
   Use the run_id it returns as --run-id on every command that changes the site.

Rules:
- Never edit theme files directly. No text editor, no `wp eval`, no writing into
  templates/, components/, or assets/.
- Make every change through typed commands: `wp pp action execute <name>
  --run-id=<uuid> --params='...'` and `wp pp apply execute <name> --run-id=<uuid>
  --params='...'`, plus compositions and design tokens. No raw file writes.
- Run `wp pp apply preflight --run-id=<uuid>` before any apply. If it fails, stop.
- Take screenshots (`wp pp screenshot capture --post_id=<id> --playbook=<name>`)
  before claiming anything looks right.
- Finish with a HANDOFF report: status (VERIFIED / NEEDS_VISUAL_VERIFICATION /
  SCREENSHOT_FAILED), the commands you ran, what changed, screenshot paths,
  preflight and drift status, and any concerns.

Stop and ask me before continuing if:
- preflight fails,
- drift overlaps the files you planned to change,
- a visual review fails twice,
- the task needs anything outside PromptingPress (plugins, wp-config, the database,
  deployment, DNS).

Do not report VERIFIED without screenshots and a completed review.
```

## What to ask for in each task

Keep the task itself short. The contract above carries the safety steps.

**Revise a section:**

```text
On the homepage (post 74), rewrite the hero subtitle to "Structured WordPress for AI
agents" and cut the features grid to its three strongest items. Hand off with desktop
and mobile screenshots.
```

**Create a page:**

```text
Create a "Pricing" page: three tiers (Free, Pro, Team), a short intro, and a CTA to
the contact page. Use the create-page playbook, then hand off.
```

**Fix a visual issue:**

```text
The About page (post 31) looks broken on mobile — the CTA button overflows at 375px.
Use the inspect-fix playbook, fix it through tokens or the composition (not a CSS file
edit), re-screenshot mobile, and hand off. If the only fix is a core theme file, stop
and tell me.
```

## When the agent should stop

The loop tells the agent to stop and hand control back to you when:

- **preflight fails** — don't work around it,
- **drift overlaps the planned change** — a file it was about to touch was already
  modified, and that needs you,
- **a visual review keeps failing** — after two tries, stop instead of thrashing,
- **the task needs work outside PromptingPress** — installing plugins, editing
  wp-config, database changes, deployment, DNS. The loop covers content and styling,
  nothing else.

## What to require before you accept the result

Don't accept "done" — accept the HANDOFF. Before you treat a task as finished, the
agent should give you:

- a **handoff summary** of what changed,
- the **commands it ran**,
- **preflight status** (passed, with no drift overlapping the change),
- **screenshots**, or an explicit `NEEDS_VISUAL_VERIFICATION` if no browser was set
  up — not a silent "looks good,"
- **remaining risks or concerns**.

If the status is `NEEDS_VISUAL_VERIFICATION` or `SCREENSHOT_FAILED`, you are the one
who looks at the page before it counts as done. PromptingPress validates the
mechanics of the change; it does not replace your eyes on the result.

## More detail

- The full loop and command reference: [`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
- The site map and component/action list: [`AI_CONTEXT.md`](../AI_CONTEXT.md)
- Why direct theme-file edits are blocked: [upgrade-safety.md](upgrade-safety.md)
