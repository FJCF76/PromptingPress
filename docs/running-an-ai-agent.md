# 🤖 Let an AI agent run your site — without it freelancing through your theme files

You've got a WordPress site on a VPS, and you'd love an AI coding agent — Claude
Code, or anything with SSH and WP-CLI access — to handle the frontend changes. Here's
the catch: hand an agent a shell and it will cheerfully open a theme file and start
editing, which is exactly how good sites quietly break. This guide shows you how to
point that same agent at PromptingPress' safe commands instead, so it does real work
without going rogue on your files.

> 🔑 **PromptingPress isn't a sandbox — your prompt is the steering wheel.** It can't
> stop an agent that has a shell from doing whatever it wants. What keeps the agent on
> the rails is the prompt you give it. Once it agrees to work through the `wp pp`
> commands, PromptingPress takes over: it enforces the order of steps and catches the
> obvious mistakes. **You set the direction; the framework holds the line.**

This isn't a deploy pipeline, and it won't replace your hosting, backups, version
control, or your own eyes on the result. It does one thing well: it keeps an AI
agent's edits to your **content and styling** structured, validated, and reversible.

## ⚖️ Two ways to point an agent at your site

**❌ Raw shell, no guardrails.** "Fix my homepage" plus a shell means the agent edits
`header.php` by hand. Maybe it works. Maybe it breaks the layout and you find out when
a visitor does. No record of what changed, no screenshot, no undo.

**✅ The PromptingPress loop.** The agent reads the site first, changes pages through
typed commands that validate and back up automatically, screenshots the result, and
hands you a report. If anything looks unsafe, it stops and asks. Same agent, very
different blast radius.

## 🎯 What this solves

> "I have a WordPress site on a VPS and I want Claude Code to make frontend changes
> without freelancing through my theme files."

PromptingPress gives the agent a structured way to change pages and styling:

- 🧩 **Compositions** (page layout) and 🎨 **design tokens** (colors, fonts, spacing)
  live in the database and are edited through typed commands, not by hand-editing files.
- 🔁 An **operating loop** makes the agent inspect first, plan, check before applying,
  and produce evidence — in that order.
- 🛡️ Editing theme files directly is discouraged and, since v0.11.0, a **blocked**
  shortcut (see [upgrade-safety.md](upgrade-safety.md)).

The agent still has a shell. Your prompt is what tells it not to use the shell to go
around all of that.

## 🔁 The loop, at a glance

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
for you the moment the agent is on this path.

## 📋 Step 1 — Give the agent its operating contract

This is the part that does the work. Paste this at the start of a Claude Code session
(or any SSH/WP-CLI agent) before you give it a task:

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

That single block closes the "I'll just edit the file" gap at the instruction level.
The framework then closes it again inside the `wp pp` surface.

## ✍️ Step 2 — Give it a task

Keep the task itself short. The contract above carries the safety steps, so you just
describe what you want.

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

## 🛑 When the agent should stop

The loop tells the agent to hand control back to you when:

- 🚫 **preflight fails** — don't work around it,
- ⚠️ **drift overlaps the planned change** — a file it was about to touch was already
  modified, and that needs you,
- 🔁 **a visual review keeps failing** — after two tries, stop instead of thrashing,
- 🚧 **the task needs work outside PromptingPress** — installing plugins, editing
  wp-config, database changes, deployment, DNS. The loop covers content and styling,
  nothing else.

## ✅ Step 3 — What "done" should look like

Don't accept "done" — accept the **HANDOFF**. Before you treat a task as finished, the
agent should hand you:

- 📝 a **handoff summary** of what changed,
- ⌨️ the **commands it ran**,
- 🛡️ **preflight status** (passed, with no drift overlapping the change),
- 📸 **screenshots**, or an explicit `NEEDS_VISUAL_VERIFICATION` if no browser was set
  up — not a silent "looks good,"
- ⚠️ **remaining risks or concerns**.

If the status is `NEEDS_VISUAL_VERIFICATION` or `SCREENSHOT_FAILED`, you're the one who
looks at the page before it counts as done. PromptingPress validates the mechanics of
the change; it doesn't replace your eyes on the result.

## 🚀 Try it

Three steps: paste the contract, give it a real task, read the handoff. Start on a
page you can afford to redo, watch how the agent inspects and screenshots before it
claims anything, and you'll quickly see the difference between an agent that edits
files and an agent that operates a site.

## 📚 Go deeper

- 🔁 The full loop and command reference: [`ai-instructions/operating-loop.md`](../ai-instructions/operating-loop.md)
- 🧭 The site map and component/action list: [`AI_CONTEXT.md`](../AI_CONTEXT.md)
- 🛡️ Why direct theme-file edits are blocked: [upgrade-safety.md](upgrade-safety.md)
