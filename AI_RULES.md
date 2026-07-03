# PromptingPress — AI Rules

Read `AI_CONTEXT.md` first. It maps the full site structure.

## AI-first principle

When WordPress convention and AI operability conflict, AI operability wins. Specifically: keep documentation centered on verifiable system state and explicit contracts, not human-centric setup checklists or procedural WordPress habits. If a documentation decision would make the project feel more like a conventional WordPress theme, it is the wrong decision.

## Dev environment

The dev site at `dev.promptingpress.com` is a separate copy of the repo, not a symlink. Changes made on the server are not automatically in the repo. Commit and push explicitly after every change.

## Mandatory first step

Before modifying any component styling or composition: run `wp pp check conflicts` to verify no Custom CSS overrides exist. If conflicts are found, resolve them first (clear Custom CSS, move styling to tokens or components.css). Never layer new styling on top of unresolved conflicts.

## Invariants — never violate these

- Templates call components. Components do not call components.
- No WordPress functions in /templates/ or /components/. Only /lib/wp.php calls WP.
- All lib/wp.php functions are prefixed pp_. Use pp_field(), pp_site_title(), etc. — not get_field(), get_bloginfo(), etc.
- No hooks (add_action, add_filter) in view files. Only in functions.php.
- Every component has schema.json before it ships.
- No raw hex in components.css — only CSS variables from base.css.
- No positional selectors (nth-of-type, nth-child) for targeting components. Use stable IDs.
- No modern CSS features: backdrop-filter, mask-image, :has(), @container. Exception: `color-mix(in srgb, ...)` is allowed for token-adaptive shadows and fades in `components.css`.
- No writing to WordPress Custom CSS (Appearance > Additional CSS) for theme styling. All styling through tokens or components.css.
- Every composition component has a persisted stable ID. IDs are auto-assigned on save.

## Parent-theme files are inspect-only for site customization

The parent-theme directories `templates/`, `components/`, and `assets/` are
**release artifacts**. A theme update replaces the entire theme directory, so any
local edit to those files is silently overwritten or deleted on the next update.

- **Inspect freely, edit never (for a site).** Read `templates/`, `components/`,
  and `assets/` to understand how the site renders. Do not edit them to customize a
  specific site.
- **Site customization goes through applies + the database**, which survive theme
  updates: design tokens via `update_design_token` (`pp_token_overrides`), fonts via
  `enqueue_font`, and content/layout via compositions. That is the supported path.
- **Editing those paths is a release-level / product change** — developing
  PromptingPress itself, not customizing a site. It belongs in a theme release.
- **If a site-specific visual or rendering change cannot be expressed** through
  tokens, applies, or compositions, **STOP and escalate.** Do not edit the
  parent-theme file directly.

**Scope of `safe_to_edit` and the instruction guides.** The `safe_to_edit` fields
in each component's `schema.json`, and any "edit / open / create a file in
`templates/` | `components/` | `assets/`" step across `ai-instructions/*.md`,
describe **product/release-development scope** (authoring or improving the theme
itself). They are NOT permission to edit parent-theme files for site customization —
for site work those paths remain inspect-only.

## Design system

To restyle the site, read `ai-instructions/retheme.md`.
47 design tokens control the entire visual system. Product defaults live in `assets/css/base.css`; site-specific overrides are stored in the `pp_token_overrides` database option and output as inline CSS. Overrides survive theme updates.
Each token has a type annotation in its comment (color, length, font-family, number, duration, raw, shadow).
To change a token programmatically, use `pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309'])`.
To revert a token to its default, use `pp_execute_apply('reset_design_token', ['token' => '--color-accent'])`.
To revert all tokens, use `pp_execute_apply('reset_all_design_tokens', [])`.
CLI: `wp pp apply execute update_design_token --params='{"token":"--color-accent","value":"#b45309"}'`.

## Anti-slop rules

When building or editing components:
- No 3-column icon grids (icon-in-circle + title + 2-line description = template slop)
- No decorative blobs, wavy dividers, or floating shapes
- Homepage hero should usually be centered -- it is the page's visual anchor.
  Left-aligned heroes require a balancing element (image in split, or cover with
  background). A left hero with no image creates dead space on desktop.
  Not everything else should be centered, but the hero earns it.
- Cards in grid are for real content objects, not feature decoration
- No raw hex values in component CSS

## Desktop width expectations

Hero width and spacing props serve variant-specific layout needs. All other
components use CSS defaults — no composition-level layout overrides.

A page built with all-default composition props should look credible on desktop.
If it doesn't, the fix belongs in design tokens (base.css) or component CSS
(components.css), not in composition overrides.

## Adding components

See `ai-instructions/add-component.md` for the exact steps.
The auto-loader picks up any component in /components/{name}/{name}.php — no registration needed.

## JS tests

Pure-function unit tests live in `tests/js/`. No bundler required — Vitest runs them directly.

```
npm test            # run once
npm run test:watch  # watch mode
```

The logic under test is in `assets/js/pp-editor-logic.js`. When editing `getJsonContextFromText`, `validateCompositionData`, `getInsertPosition`, `buildAccordionData`, `serializeAccordionData`, `deepDiff`, `checkSerializationInvariant`, or `formatDiffsForIssue`, run tests before committing.

## E2E tests

Playwright tests in `tests/e2e/` run against a live WordPress instance via wp-env (Docker).

```
npm run env:start   # boot Docker WordPress on port 8889
npm run test:e2e    # 15 tests: editor round-trip, serialization gate, CLI actions, post-apply validation
npm run env:stop    # tear down
```

Requires Docker. Tests cover workspace init, preview updates, save rejection, autosave skip, front-end rendering, accordion round-trip, and the serialization gate (blocked state, save/publish restore, copy-as-issue).

## File responsibilities

"Safe to edit?" below means safe for **release/product development** (changing the
theme itself). For **site customization**, the parent-theme rows (`templates/`,
`components/`, `assets/`) are **inspect-only** — use applies/DB or escalate.

| File/Folder              | Purpose                         | Safe to edit?                    |
|--------------------------|---------------------------------|----------------------------------|
| /templates/              | Page layouts                    | Release-level only — inspect for site work |
| /components/             | Reusable sections               | Release-level only — inspect for site work |
| /assets/css/base.css     | Design token defaults           | Release-level only — site tokens via update_design_token |
| /assets/css/components.css | Component styles              | Release-level only — inspect for site work |
| /assets/js/pp-editor-logic.js | Pure JS logic (testable)   | Release-level only — run npm test after |
| /assets/js/main.js       | Nav toggle, active link         | Release-level only — inspect for site work |
| /lib/wp.php              | WP function wrappers (read + write) | Only to add pp_ functions   |
| /lib/actions.php         | Typed action model (14 actions) | Add actions following the contract |
| /lib/guardrails.php      | CSS conflict detection, surface classification, theme integrity | Extend for new checks |
| /lib/operate.php         | Operating loop: inspect, preflight, run tokens | Extend for new checks |
| /lib/apply.php           | Apply layer (file + option mutations) | Add applies following the contract |
| /lib/cli.php             | WP-CLI `wp pp action` + `wp pp apply` + `wp pp check` + `wp pp integrity` | Yes        |
| /lib/components.php      | Component loader                | No                               |
| /lib/ai-context.php      | AI site context layer             | Extend for new context sources     |
| /lib/ai-provider.php     | LLM provider proxy                | Extend for new providers           |
| /lib/ai-chat.php         | AI chat page + AJAX handlers      | Yes                                |
| /ai-stream.php           | SSE streaming endpoint            | Thin transport only                |
| functions.php            | WP registration                 | Only to add                      |
| style.css                | Theme header (WP requirement)   | No                               |
