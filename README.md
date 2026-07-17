<div align="center">

# PromptingPress

### WordPress pages that AI agents can understand — and fast sites that skip the builder bloat.

**A lightweight composition layer for WordPress. Pages are typed components + structured JSON. Design lives in tokens. The frontend ships ~97 KB of CSS and 3.6 KB of vanilla JS — no framework, no bundler, no builder runtime. AI can inspect, edit, and maintain pages through predictable interfaces instead of reverse-engineering theme clutter.**

---

[![WordPress 7.0+](https://img.shields.io/badge/WordPress-7.0+-21759B?style=flat-square&logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![JavaScript](https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?style=flat-square&logo=javascript&logoColor=black)](https://developer.mozilla.org/en-US/docs/Web/JavaScript)
[![Vitest](https://img.shields.io/badge/Vitest-Tests-6E9F18?style=flat-square&logo=vitest&logoColor=white)](https://vitest.dev)
[![Tests](https://img.shields.io/badge/Tests-1936+_passing-22C55E?style=flat-square)](tests/)
[![Version](https://img.shields.io/badge/version-1.0.2-6366F1?style=flat-square)](CHANGELOG.md)
[![License](https://img.shields.io/badge/License-GPL--2.0-blue?style=flat-square)](LICENSE)

</div>

---

## ⚡ Fast, structured WordPress without builder bloat

Most visual builders solve human editing by adding hidden state, heavy abstractions, serialized layouts, and frontend overhead. Pages get harder for AI to reason about — and slower than they need to be.

PromptingPress goes the other direction:

- **Explicit composition data** — pages are JSON arrays, not serialized builder state
- **Typed components with schemas** — AI knows what's editable and what values are valid
- **File-owned design rules** — design tokens in one CSS file, no visual overrides to hunt down
- **Minimal frontend assumptions** — no framework, no bundler, no builder runtime on the visitor-facing site
- **Fast, maintainable WordPress output** — the architecture is designed to stay lean

> **The same design that makes pages AI-editable also makes them fast. Structured data and isolated components have no runtime overhead to carry.**

---

## 🔄 The old way vs. PromptingPress

| | Traditional WordPress theme | PromptingPress |
|---|---|---|
| 🧠 **AI readability** | AI reads the codebase and guesses what's editable | AI reads `AI_CONTEXT.md` + component schemas and knows exactly what's editable and how |
| 🧩 **Page structure** | Layout scattered across blocks, builders, shortcodes, and theme options | Page layout is one JSON array in post meta — inspectable, diffable, version-controllable |
| 🛡️ **Edit safety** | Changes via file edits, block editor, or plugin-specific APIs | Every change goes through one typed action layer — validate, preview, execute, rollback |
| 🪶 **Frontend weight** | Builder runtime, serialized markup, framework dependencies | ~97 KB CSS + 3.6 KB vanilla JS. No framework. No bundler. No builder runtime. |
| 🎨 **Design control** | Colors and spacing set through visual overrides or inline CSS | Design tokens in one CSS file; site overrides in the database, survive theme updates |
| 📄 **Component contracts** | Ad hoc theme files, no contracts on what a component accepts | Every component has `schema.json` with typed props, required fields, and validation |

> **When page structure is explicit and every edit path is validated, AI stops guessing and starts operating — and the frontend stays lean.**

---

## 🔧 How AI works with PromptingPress

```mermaid
flowchart TD
    subgraph Entry["🔧 AI entry points"]
        CLI["WP-CLI\nwp pp action · wp pp operate"]
        Chat["In-admin AI Chat\nstreaming proposals + approval"]
        Editor["Composition Editor\naccordion UI + JSON toggle"]
    end

    subgraph Orient["🧭 Orientation"]
        CTX["AI_CONTEXT.md\nfile map · component index · API reference"]
        Schema["schema.json per component\ntyped props · required fields · enums"]
    end

    subgraph Mutation["🛡️ Validated mutation layer"]
        Action["Typed action layer\n20 actions · validate · preview · execute"]
        Apply["Apply layer\ndesign tokens · file mutations · backup + rollback"]
        Guard["Guardrails\nsurface classification · CSS conflict detection · data-loss guard"]
    end

    subgraph Storage["Data"]
        Comp["_pp_composition post meta\nJSON array of components + props + style"]
        Tokens["Design token overrides\nwp_options · survives theme updates"]
    end

    subgraph Render["⚡ Output"]
        Templates["Templates\npp_* wrappers only"]
        Components["Component partials\nisolated PHP · CSS variables only"]
        Frontend["WordPress frontend\n~97 KB CSS · 3.6 KB JS · zero frameworks"]
    end

    CLI --> Action
    Chat --> Action
    Editor --> Action
    CTX -.->|"AI reads first"| CLI
    CTX -.->|"AI reads first"| Chat
    Schema -.->|"validates against"| Action
    Action --> Comp
    Apply --> Tokens
    Guard -.->|"blocks invalid state"| Action
    Comp --> Templates
    Tokens --> Components
    Templates --> Components
    Components --> Frontend
```

**Every path from AI intent to rendered page goes through the same validated layer.** CLI, chat, and editor are different interfaces into one mutation system. Nothing bypasses validation. The output is plain WordPress — fast, cacheable, no builder runtime.

---

## 🧩 The operating model

### 🧠 Compositions — pages as structured data

Every page using the Composition template stores its layout in `_pp_composition` post meta:

```json
[
  { "component": "hero", "props": { "title": "Welcome", "layout": "centered" } },
  { "component": "section", "props": { "body": "<p>Content here.</p>" } },
  { "component": "grid", "props": { "items": [
    { "title": "Fast", "text": "Lightning speed." },
    { "title": "Safe", "text": "Enterprise security." }
  ] } }
]
```

No blocks. No shortcodes. No visual-builder serialization. AI can read, write, diff, and version-control compositions like any other structured data. Semantic selectors let AI target specific fields by name (`hero.subtitle`, `grid[title="Features"].items[title="Speed"].text`) instead of fragile array indices.

**Why this matters:** An AI agent can inspect a page, understand exactly what's editable, propose a change with preview, and apply it — in one session, without prior WordPress knowledge.

---

### 🧩 Typed components — contracts, not conventions

12 components, each isolated in its own directory with a `schema.json`. Ten of them are composable — a page places them:

| Component | Purpose | Key props |
|-----------|---------|-----------|
| hero | Full-width headline with optional CTA, image, overlay | `title` |
| section | Text + optional image; 4 `layout`s (text-only, image-left, image-right, centered) + `theme` (default, dark, inverted) | `body` |
| grid | Responsive card grid; `layout` (cards, steps) + `theme` (default, dark, inverted) + `card_emphasis` (featured, uniform) | `items[]` |
| faq | Native `details/summary` accordion, zero JavaScript | `items[]` |
| cta | Call-to-action block with layout, color axis, and background image; `title` optional (omit for a standalone button row) | `button_text`, `button_url` |
| stats | Large-number metrics with labels and optional background image | `items[]` |
| logos | Flex-wrap image strip for partner/client logos | `items[]` |
| table | Data/comparison table, horizontal scroll on mobile | `headers[]`, `rows[][]` |
| embed | WordPress shortcode / plugin content wrapper | `content` |
| testimonials | Customer quotes with attribution — card grid or single-column stack | `items[]` |

Two more components ship with the theme but are **not composable**: `nav` and `footer` are site chrome, rendered on every page by the base template. Putting either into a page composition renders the header or footer twice, so the write is rejected with `template_owned_component`. Set the site logo through the `pp_logo_id` option and the menus through the menu actions. The footer's dark-marketing chrome (background/text/link colors, brand blurb, contact block, custom copyright) is set through the `pp_footer_*` site options, and the header's (background/text/link colors) through the `pp_header_*` options. The footer can also be organised through the same surface: `pp_footer_menu_label` / `pp_footer_contact_label` label its columns, `pp_footer_note` moves the copyright into a delimited bottom bar with a secondary line, and `pp_footer_logo_id` gives it a light logo variant that overrides `pp_logo_id` on the footer only. The two background options — `pp_header_bg` and `pp_footer_bg` — accept a bounded CSS gradient as well as a plain color, so a dark or gradient marketing header/footer is expressible without touching the site's global tokens.

The auto-loader picks up any new component at `/components/{name}/{name}.php` — drop a file, add a schema, it works. No registration code.

**Why this matters:** AI doesn't have to guess what props a component accepts, which are required, or what values are valid. The schema is the contract. Invalid compositions are rejected before they reach the database.

---

### 🪶 Lightweight rendering — no builder runtime on the frontend

Components are plain PHP partials that render semantic HTML with CSS custom properties. No React. No Vue. No jQuery. No builder framework. No shortcode parser. The visitor-facing site loads:

| Asset | Size | What it does |
|-------|------|-------------|
| `base.css` | 9 KB | Design tokens — CSS custom properties |
| `components.css` | 84 KB | All 12 component styles, CSS variables only |
| `utilities.css` | 3 KB | Layout helpers |
| `main.js` | 3.6 KB | Hamburger nav toggle + sticky-header height measurement — one IIFE, zero dependencies |

No build step. No transpilation. No bundler. What you write is what ships.

**Why this matters:** The same architecture that makes pages easy for AI to understand also keeps the frontend lean. Structured data and isolated PHP components don't carry runtime overhead. Pages load fast because there's nothing unnecessary to load.

---

### 🎨 Design tokens — visual system without file edits

A single layer of CSS custom properties controls the entire visual system: colors, typography (including mono/meta/label/kicker roles), spacing, borders, shadows, measures. Product defaults live in `assets/css/base.css`. Site-specific overrides are stored in the database and **survive theme updates** — no file to lose when the theme ZIP gets replaced.

194 per-instance style slots let AI make this page's hero dark and spacious while that page's hero is tight, accent-bordered, and lifted with a drop shadow — all through composition data, no CSS edits. 11 named recipes (like `dark-spacious` or `compact`) expand to multiple slot values at once.

```bash
# Preview a token change without applying
wp pp apply preview update_design_token \
  --params='{"token":"--color-accent","value":"#b45309"}'

# Apply with automatic backup
wp pp apply execute update_design_token \
  --params='{"token":"--color-accent","value":"#b45309"}'
```

**Why this matters:** An AI agent can retheme an entire site by updating design tokens — with preview, backup, and rollback on every change. No CSS files to parse, no specificity wars, no visual editor toggles to find.

---

### 🛡️ Validated action/apply layer — one write path

Every mutation — from CLI, from AI chat, from the editor — goes through the same typed action system:

```bash
# See all 20 available actions
wp pp action list

# Preview what a change would do (dry run, never writes)
wp pp action preview add_component \
  --params='{"post_id":74,"component":"section","props":{"body":"<p>New.</p>"}}'

# Execute with validation
wp pp action execute add_component \
  --params='{"post_id":74,"component":"section","props":{"body":"<p>New.</p>"}}'
```

20 typed actions cover page lifecycle, composition edits, component operations, styling, navigation menus, SEO metadata, and site options. The apply layer handles design token and file mutations with automatic backup (keeps last 5), post-write contract verification, and auto-restore on failure.

**Why this matters:** AI agents can't accidentally produce invalid state. The system validates inputs, shows a preview diff, and rolls back on failure — all through the same interface regardless of how the edit was initiated.

---

### 🧭 AI_CONTEXT.md — orientation in seconds

`AI_CONTEXT.md` is a machine-readable site map: file responsibilities, component index, WP abstraction API, composition format, design tokens, mutation surfaces, and coding rules. An AI agent reads one file and knows the entire system — what's editable, how to edit it, and what constraints apply.

`lib/wp.php` is the only file that calls WordPress functions directly. Every template and component uses `pp_*` wrappers:

| Instead of | AI writes |
|---|---|
| `get_the_title()` | `pp_title()` |
| `get_post_meta($id, '_pp_composition', true)` | `pp_get_composition($id)` |
| `wp_nav_menu(...)` | `pp_nav_menu($location)` |
| `the_content()` | `pp_content()` |

**Why this matters:** AI can edit templates without learning WordPress's function signature jungle. The abstraction layer also means templates are testable without bootstrapping WordPress.

---

### 💬 AI chat — structured proposals, not raw text

An in-admin chat at PromptingPress > AI Chat. The AI reads your real site state — pages, compositions, media library, design tokens — and proposes changes as structured mutation cards with Apply/Cancel buttons.

Multi-step proposals show numbered steps with "Apply All." After applying, the AI knows about its own changes and can build on them in the same conversation. Streaming via SSE. Supports Anthropic, Google, and OpenAI through WordPress 7.0 Connectors.

**Why this matters:** The AI doesn't generate raw code and hope it works. It proposes typed actions through the same validated layer that CLI and the editor use. Every proposal is previewable and reversible.

---

### 🔒 Agent operating framework — safety-gated autonomous work

The intended operator is an autonomous coding agent (Claude Code or similar) with
shell + WP-CLI access, typically over SSH to a VPS. It changes the site through an
8-step loop instead of editing files:

**INSPECT** → **PLAN** → **PREFLIGHT** → **EDIT** → **APPLY** → **SCREENSHOT** → **REVIEW** → **HANDOFF**

Run tokens (UUID v4 state files) enforce ordering on the `wp pp` surface: `action execute` refuses to run before INSPECT, and every DB-backed mutation (`action execute`, `operate patch`, `apply execute`) refuses to run before a PREFLIGHT covering its target — a specific `post_id` for page edits, or a site-scoped preflight for site/token changes. Every mutating command fails without a `--run-id`. Preflight classifies off-limits (core) files and blocks the apply; screenshots plus a checklist produce visual evidence; HANDOFF is the report you review. Three playbooks ship: create-page, revise-section, inspect-fix.

**What this is and isn't:** safety-gated autonomous operation, not permissionless
self-modification. The loop makes the structured path the easy one and catches the
"I'll just write the file directly" shortcut (preflight surface classification plus
integrity drift detection). It does **not** sandbox the shell — a raw `wp eval` or a
text-editor file edit still runs. The safe posture: point the agent at a dev
install, require it to work through `wp pp`, and gate production on a human reading
the HANDOFF report.

→ How to prompt your agent to use it safely: [docs/running-an-ai-agent.md](docs/running-an-ai-agent.md)
→ Why a DB write can't land before the safety gate (the design): [docs/operating-loop-safety.md](docs/operating-loop-safety.md)
→ Every `wp pp apply` command, flag, and error (reference): [docs/reference-apply-cli.md](docs/reference-apply-cli.md)
→ Apply a token change and roll it back, step by step (how-to): [docs/howto-apply-and-rollback.md](docs/howto-apply-and-rollback.md)

---

## 📋 Example workflow

**Scenario:** An AI agent adds a features section to an existing landing page.

```
1. Agent reads AI_CONTEXT.md
   → learns: 12 components, schema contracts, action layer, composition format

2. Agent inspects the page
   $ wp pp operate inspect-composition 74
   → sees: hero at [0], section at [1], cta at [2] — with every editable field listed

3. Agent chooses the grid component (from schema: items[] with title, text, image_url)
   and builds the composition patch

4. Agent previews the change
   $ wp pp action preview add_component --params='{...}'
   → sees: diff showing grid inserted at position 2, cta shifted to [3]

5. Agent executes
   $ wp pp action execute add_component --params='{...}'
   → validated, written to _pp_composition, confirmed

6. Page renders through WordPress with the new grid section
   → plain HTML + CSS custom properties, no builder runtime
```

No theme files were edited. No WordPress internals were called. No visual builder state was generated. The composition changed; the page reflects it — fast.

---

## 🏗️ Architecture

```
/components/{name}/        Component partials + schema.json
/templates/                Page layout files (pp_* wrappers only)
/lib/wp.php                WP abstraction layer
/lib/actions.php           Typed action model (20 actions)
/lib/apply.php             Apply layer (file + option mutations, backup/rollback)
/lib/operate.php           Operating loop: inspect, preflight, run tokens
/lib/cli.php               WP-CLI commands (wp pp action · apply · operate · check · validate · integrity · screenshot · target · sync)
/lib/admin.php             Composition editor (accordion + CodeMirror + preview)
/lib/ai-chat.php           AI chat admin page + AJAX handlers
/lib/ai-context.php        AI system prompt assembly (site state, media, tokens)
/lib/ai-provider.php       LLM provider proxy (streaming + non-streaming)
/lib/post-apply-validate.php Post-apply DOM validation (images, content, component count)
/lib/guardrails.php        Surface classification, CSS conflicts, integrity checks
/lib/setup.php             Theme activation, homepage provisioning, integrity lifecycle (daily cron + pre-update block)
/lib/components.php        Component auto-loader (stable contract — don't edit)
/assets/css/base.css       Design token defaults
/assets/css/components.css Component styles (CSS variables only, no raw hex)
/ai-instructions/          Task-specific AI workflow guides
AI_CONTEXT.md              Machine-readable site map — AI starts here
AI_RULES.md                Hard coding invariants
```

---

## 🚀 Quick start

### Requirements

- WordPress 7.0+
- PHP 8.0+
- No build step — vanilla PHP, CSS, and JS

### Installation

1. Download the latest release ZIP from [GitHub Releases](https://github.com/FJCF76/PromptingPress/releases/latest)
2. In WordPress admin: **Appearance → Themes → Add New → Upload Theme**
3. Upload the ZIP file and activate PromptingPress

On activation, PromptingPress creates a Home page with the Composition template and assigns it as the static front page.

<details>
<summary><strong>Developer install (clone the repo)</strong></summary>

```bash
cd wp-content/themes/
git clone https://github.com/FJCF76/PromptingPress.git promptingpress
```

This gives you the full repo with tests, dev tooling, and git history. Useful for contributing, inspecting source, or running the test suite locally.

</details>

### AI chat setup

Configure an LLM provider in Settings > Connectors (WordPress 7.0 Connectors API — Anthropic, Google, or OpenAI). Then open PromptingPress > AI Chat.

### 🔧 First CLI session

```bash
# See what's available
wp pp action list

# Create a new page
wp pp action execute create_page --params='{"title":"About Us"}'

# Inspect what's editable
wp pp operate inspect-composition 74

# Patch a single field by name
wp pp operate patch 74 --target=hero.subtitle --value="New Headline" --preview

# Retheme — preview a color change
wp pp apply preview update_design_token \
  --params='{"token":"--color-accent","value":"#b45309"}'

# Check theme file integrity after deployment
wp pp integrity check
```

---

## 📏 Architectural rules

Enforced by `AI_RULES.md` and verified by automated tests:

- Templates call components. Components do not call components.
- No WordPress functions in `/templates/` or `/components/`. Only `lib/wp.php` calls WP.
- No hooks (`add_action`, `add_filter`) in view files. Only in `functions.php`.
- No raw hex values in `components.css` — CSS variables from `base.css` only.
- Every component ships with a `schema.json`.

---

## ✅ Tests

**PHP unit tests** — component loader, WP abstraction, schema validation, 20 typed actions, apply layer, batch atomicity/rollback, token family derivation, AI context, proposal parsing, capability model, style slots, cross-component hints, surface classification, font management, media import, SEO metadata, navigation menus, integrity, upgrade-safety guardrails, operating loop, server-driven destructive-action warnings:

```bash
composer install && composer test
```

**JS unit tests** — JSON context, composition validator, accordion data, insert position, data-loss guard, DOM selector alignment, serialization invariant (deep diff + round-trip gate), CSS lint, docs lint, packaging, proposal card, guided error card, page targeting, post-apply validation, shared PHP/JS validation contract, server-driven warning lookup:

```bash
npm install && npm test
```

**E2E specs** — composition editor round-trip (including the serialization gate), post-apply validation, the action-layer CLI, AI chat streaming/apply, concurrent token-override writes serialized behind a real MySQL advisory lock, and rendered-layout proof (style slots and component geometry measured in the browser, where the full cascade decides the outcome), run with Playwright against a live **WordPress 7.0** instance (requires Docker):

```bash
npm run env:start   # boot wp-env container (WordPress 7.0)
npm run test:e2e    # Playwright against http://localhost:8889
npm run env:stop
```

**Continuous integration** — `composer test` + `npm test` run on every push to `main` and gate every release before the ZIP is built, so a failing test can't reach a published theme. End-to-end runs in CI too: a `@smoke` subset on every pull request and on push to `main` (non-blocking until branch protection marks it a required check), plus a nightly full suite on WordPress 7.0.

---

## 📌 Project status

PromptingPress is in active development by a single developer. It is not yet packaged for broad distribution. The current focus is making the AI-agent workflow reliable and the composition model complete.

See [CHANGELOG.md](CHANGELOG.md) for a detailed release history from v0.0.1 through v1.0.1.

**What exists today (v1.0.1):**
- 12 components with schema contracts and 194 per-instance style slots, plus named recipes
- A contract-test suite that enforces the style-slot contract: every declared slot must be consumed by the CSS, and literal re-declarations that would defeat a slot fail the build — including cross-stylesheet clobbers, where an automatic-match rule in `base.css`/`utilities.css` outranks a component slot (issue [#342](https://github.com/FJCF76/PromptingPress/issues/342)). Known exceptions live in shrink-only ledgers (issues [#309](https://github.com/FJCF76/PromptingPress/issues/309), [#342](https://github.com/FJCF76/PromptingPress/issues/342)); the static guards account for every clobber candidate, and the rendered computed-style checks own the true cascade proof
- Typed action/apply layer with validation, preview, and rollback
- Bounded presentation controls — button variants, typography roles, shadow/border/radius slots
- Token family derivation — changing one color updates related tokens automatically
- Concurrency-safe design-token writes — applies serialize behind advisory locking so parallel edits never lose data
- Post-apply validation — DOM inspection verifies rendered page after mutations
- Screenshot-readiness diagnostics (`wp pp screenshot doctor`) for the verify-with-evidence loop, with honest VERIFIED / NEEDS_VISUAL_VERIFICATION / SCREENSHOT_FAILED status
- Semantic composition patching — target fields by name, not index
- In-admin AI chat with structured mutation proposals and guided error recovery
- Agent operating framework with step enforcement and drift detection
- WP-CLI interface for all operations
- 1590+ automated tests across PHP, JS, and E2E, enforced by CI on every push and release
- Theme integrity enforcement — a build manifest of file hashes detects local drift; a daily check keeps the warning current, and a theme update is blocked before it can overwrite or delete modified files (override with the `pp_allow_unsafe_theme_update` filter). See [docs/upgrade-safety.md](docs/upgrade-safety.md)
- ~97 KB frontend CSS, 3.6 KB JS — no framework, no bundler

See [open issues](https://github.com/FJCF76/PromptingPress/issues) for planned work.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

<div align="center">

**PromptingPress** — Structured WordPress for AI agents. Lightweight by design.

*Built with [WordPress](https://wordpress.org) · Vanilla PHP + CSS + JS · [Vitest](https://vitest.dev) · [Playwright](https://playwright.dev)*

</div>
