# PromptingPress — Claude Code Instructions

Read `AI_CONTEXT.md` first. It maps the full site structure.

## Invariants — never violate these

- Templates call components. Components do not call components.
- No WordPress functions in /templates/ or /components/. Only /lib/wp.php calls WP.
- All lib/wp.php functions are prefixed pp_. Use pp_field(), pp_site_title(), etc. — not get_field(), get_bloginfo(), etc.
- No hooks (add_action, add_filter) in view files. Only in functions.php.
- Every component has schema.json before it ships.
- No raw hex in components.css — only CSS variables from base.css.

## Design system

To restyle the site, read `ai-instructions/retheme.md`.
Design tokens live in `assets/css/base.css` — 16 CSS variables control the entire visual system.

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

## File responsibilities

| File/Folder              | Purpose                         | Safe to edit?                    |
|--------------------------|---------------------------------|----------------------------------|
| /templates/              | Page layouts                    | Yes                              |
| /components/             | Reusable sections               | Yes                              |
| /assets/css/base.css     | Design tokens                   | Yes — tokens only                |
| /assets/css/components.css | Component styles              | Yes                              |
| /assets/js/main.js       | Nav toggle, active link         | Yes                              |
| /lib/wp.php              | WP function wrappers            | Only to add pp_ functions        |
| /lib/components.php      | Component loader                | No                               |
| functions.php            | WP registration                 | Only to add                      |
| style.css                | Theme header (WP requirement)   | No                               |
