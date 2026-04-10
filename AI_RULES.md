# PromptingPress — AI Rules

Read `AI_CONTEXT.md` first. It maps the full site structure.

## AI-first principle

When WordPress convention and AI operability conflict, AI operability wins. Specifically: keep documentation centered on verifiable system state and explicit contracts, not human-centric setup checklists or procedural WordPress habits. If a documentation decision would make the project feel more like a conventional WordPress theme, it is the wrong decision.

## Dev environment

The dev site at `dev.promptingpress.com` is a separate copy of the repo, not a symlink. Changes made on the server are not automatically in the repo. Commit and push explicitly after every change.

## Invariants — never violate these

- Templates call components. Components do not call components.
- No WordPress functions in /templates/ or /components/. Only /lib/wp.php calls WP.
- All lib/wp.php functions are prefixed pp_. Use pp_field(), pp_site_title(), etc. — not get_field(), get_bloginfo(), etc.
- No hooks (add_action, add_filter) in view files. Only in functions.php.
- Every component has schema.json before it ships.
- No raw hex in components.css — only CSS variables from base.css.

## Design system

To restyle the site, read `ai-instructions/retheme.md`.
Design tokens live in `assets/css/base.css` — 18 CSS variables control the entire visual system.

## Anti-slop rules

When building or editing components:
- No 3-column icon grids (icon-in-circle + title + 2-line description = template slop)
- No decorative blobs, wavy dividers, or floating shapes
- No centered-everything (hero centered is OK; everything centered is not)
- Cards in grid are for real content objects, not feature decoration
- No raw hex values in component CSS

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

## File responsibilities

| File/Folder              | Purpose                         | Safe to edit?                    |
|--------------------------|---------------------------------|----------------------------------|
| /templates/              | Page layouts                    | Yes                              |
| /components/             | Reusable sections               | Yes                              |
| /assets/css/base.css     | Design tokens                   | Yes — tokens only                |
| /assets/css/components.css | Component styles              | Yes                              |
| /assets/js/pp-editor-logic.js | Pure JS logic (testable)   | Yes — run npm test after         |
| /assets/js/main.js       | Nav toggle, active link         | Yes                              |
| /lib/wp.php              | WP function wrappers            | Only to add pp_ functions        |
| /lib/components.php      | Component loader                | No                               |
| functions.php            | WP registration                 | Only to add                      |
| style.css                | Theme header (WP requirement)   | No                               |
