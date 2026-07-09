# Validate Site — CLI Checks + Rendered Review

## Mandatory pre-check

Before modifying any component styling or composition, always run conflict detection first:

```bash
wp pp check conflicts
```

If conflicts are found, resolve them before proceeding. The admin edit screen also shows a dismissible warning on composition pages when conflicts exist, and `WP_DEBUG` mode renders an HTML comment in the page source.

## Automated checks (CLI)

Run the full validation battery:

```bash
wp pp validate site
```

This checks:
1. **Custom CSS conflicts** — selectors in WordPress Custom CSS that target PP component classes (also surfaced via admin notice on composition edit screens)
2. **Composition styling** — duplicate component types without stable IDs (ambiguous targeting)
3. **Composition data integrity** — a page whose stored composition is corrupt (undecodable JSON) or not a valid composition list is flagged as a data-integrity error and fails validation, instead of being silently treated as a blank page (issue 144). `wp pp check page` reports the same corruption distinctly from "no composition".

Individual checks:

```bash
wp pp check conflicts              # Custom CSS conflicts only
wp pp check page --post_id=42      # Composition styling for one page (raw composition data)
wp pp validate page --post_id=42   # Rendered-HTML validation for one page (see below)
```

## Rendered-HTML validation (per page)

`wp pp check page` inspects raw composition data; `wp pp validate page` inspects
the **actual rendered output** — it runs the exact same `pp_post_apply_validate()`
service that gates the AI chat's success message after an apply (issue 77):

```bash
wp pp validate page --post_id=42                      # whole page
wp pp validate page --post_id=42 --component-index=1  # scope to one component
```

It flags render failures, broken `<img>` sources, background-image and link URLs
pointing at missing local media, empty content, a component render count that
doesn't match the composition, and any template-owned chrome (`nav`/`footer`) found
in the composition. Exits non-zero on failure, so it can gate a deployment workflow
the same way it gates the chat. Use it as the automated half of the rendered review
checklist below.

## What the checks catch

| Check | What it flags | What to do |
|---|---|---|
| Custom CSS conflict | `.hero { ... }` in Additional CSS | Run `clear_custom_css` action, move styling to tokens or components.css |
| Ambiguous targeting | Two `section` components without IDs | Save the composition (IDs auto-assign) or set explicit IDs |
| `template_owned_component` | A `nav` or `footer` in the composition — the template already renders both, so the page shows the chrome twice (#223) | Remove them with `remove_component`, highest index first. Configure the logo via `pp_logo_id` and the menus via `set_menu` / `assign_menu_location` |

## Site chrome readiness (v0.12.0, rescoped in #223)

`pp_check_nav_readiness()` diagnoses the site chrome the page template renders on
every page: the `primary` and `footer` menu locations, and the site logo. It runs
automatically -- you do not call it directly. The rows appear:

- in **preflight** output before any mutation (`wp pp apply preflight`), and
- in the **post-apply validation** report after a composition changes.

Every row is **warning-grade** (`severity: warning`): it surfaces the problem but
never blocks the mutation. Find the rows by their `check` field:

```
{ "check": "nav_readiness", "pass": false, "severity": "warning",
  "message": "Site chrome location \"primary\" has no menu assigned. Use the set_menu action (or Appearance -> Menus) to create one and assign it (issue 132)." }
```

It is scoped to the locations the template actually renders, not to anything a
page composition declares -- chrome is not composable (see `composition.md` ->
Site chrome). Because chrome renders on every page, these rows appear on every
preflight, including a site-scoped one with no `--post_id`. A registered location
the template never renders (say, one a plugin adds) stays silent -- no false alarms.

| It flags | Meaning | What to do |
|---|---|---|
| `no menu assigned` | The location has no WP menu attached | Assign one via the `assign_menu_location` action, or build menu + items + location in one call with `set_menu` (rendered locations: `primary`, `footer`) |
| `menu ... is empty` | A menu is attached but has zero items | Add items via the `add_menu_item` action (or replace declaratively with `set_menu`) |
| `not registered` | The template renders a location nothing registered | Register it in `functions.php` |
| `pp_logo_id ... is not an image` | The site logo option points at a non-image attachment, so the chrome silently falls back to a text wordmark | Set `pp_logo_id` to an image attachment ID via `update_site_option`, or clear it to use the wordmark deliberately |

This is the diagnostic for a "broken" or missing mobile menu: if the hamburger
opens to nothing, preflight will already be telling you the menu is empty or
unassigned.

## Rendered review checklist

After automated checks pass, verify rendered output:

### Desktop (1280px+)

- [ ] All pages load without console errors
- [ ] Component IDs visible in DOM inspector (search for `pp-`)
- [ ] `data-pp-component` attributes present on all component root elements
- [ ] Design tokens applied (no raw hex in computed styles)
- [ ] Grid components show 3-column layout
- [ ] Hero CTA buttons styled correctly (primary solid, secondary outline)

### Mobile (375px)

- [ ] Hero CTA visible without scrolling
- [ ] Grid cards stack to single column
- [ ] Nav collapses to hamburger menu
- [ ] Tables scroll horizontally (no content clipping)
- [ ] No horizontal overflow on any page
- [ ] Text readable without zooming

## Clean site criteria

A site passes validation when:
1. `wp pp validate site` returns success (exit code 0)
2. No Custom CSS exists
3. All composition components have stable IDs in the DOM
4. Desktop and mobile rendered review passes
