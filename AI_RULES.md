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
- No modern CSS features: color-mix(), backdrop-filter, mask-image, :has(), @container.
- No writing to WordPress Custom CSS (Appearance > Additional CSS) for theme styling. All styling through tokens or components.css.
- Every composition component has a persisted stable ID. IDs are auto-assigned on save.

## Design system

To restyle the site, read `ai-instructions/retheme.md`.
Design tokens live in `assets/css/base.css` — 18 CSS variables control the entire visual system.
Each token has a type annotation in its comment (color, length, font-family, duration, raw).
To change a token programmatically, use `pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309'])`.
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

The logic under test is in `assets/js/pp-editor-logic.js`. When editing `getJsonContextFromText`, `validateCompositionData`, `getInsertPosition`, `buildAccordionData`, or `serializeAccordionData`, run tests before committing.

## E2E tests

Playwright tests in `tests/e2e/` run against a live WordPress instance via wp-env (Docker).

```
npm run env:start   # boot Docker WordPress on port 8889
npm run test:e2e    # 7 tests covering editor round-trip + CLI actions
npm run env:stop    # tear down
```

Requires Docker. Tests cover workspace init, preview updates, save rejection, autosave skip, front-end rendering, and accordion round-trip.

## File responsibilities

| File/Folder              | Purpose                         | Safe to edit?                    |
|--------------------------|---------------------------------|----------------------------------|
| /templates/              | Page layouts                    | Yes                              |
| /components/             | Reusable sections               | Yes                              |
| /assets/css/base.css     | Design tokens                   | Yes — tokens only                |
| /assets/css/components.css | Component styles              | Yes                              |
| /assets/js/pp-editor-logic.js | Pure JS logic (testable)   | Yes — run npm test after         |
| /assets/js/main.js       | Nav toggle, active link         | Yes                              |
| /lib/wp.php              | WP function wrappers (read + write) | Only to add pp_ functions   |
| /lib/actions.php         | Typed action model (13 actions) | Add actions following the contract |
| /lib/guardrails.php      | Conflict detection + composition validation | Extend for new checks |
| /lib/apply.php           | Apply layer (file-based mutations) | Add applies following the contract |
| /lib/cli.php             | WP-CLI `wp pp action` + `wp pp apply` commands | Yes               |
| /lib/components.php      | Component loader                | No                               |
| /lib/ai-context.php      | AI site context layer             | Extend for new context sources     |
| /lib/ai-provider.php     | LLM provider proxy                | Extend for new providers           |
| /lib/ai-settings.php     | AI settings page (admin only)     | Yes                                |
| /lib/ai-chat.php         | AI chat page + AJAX handlers      | Yes                                |
| /ai-stream.php           | SSE streaming endpoint            | Thin transport only                |
| functions.php            | WP registration                 | Only to add                      |
| style.css                | Theme header (WP requirement)   | No                               |
