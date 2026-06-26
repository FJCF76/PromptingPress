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

Individual checks:

```bash
wp pp check conflicts              # Custom CSS conflicts only
wp pp check page --post_id=42      # Composition styling for one page
```

## What the checks catch

| Check | What it flags | What to do |
|---|---|---|
| Custom CSS conflict | `.hero { ... }` in Additional CSS | Run `clear_custom_css` action, move styling to tokens or components.css |
| Ambiguous targeting | Two `section` components without IDs | Save the composition (IDs auto-assign) or set explicit IDs |

## Navigation readiness (v0.12.0)

`pp_check_nav_readiness()` diagnoses empty or incomplete navigation for the nav
locations a page actually uses. It runs automatically -- you do not call it
directly. The rows appear:

- in **preflight** output before any mutation (`wp pp apply preflight`), and
- in the **post-apply validation** report after a composition changes.

Every row is **warning-grade** (`severity: warning`): it surfaces the problem but
never blocks the mutation. Find the rows by their `check` field:

```
{ "check": "nav_readiness", "pass": false, "severity": "warning",
  "message": "Navigation location \"primary\" has no menu assigned. Assign one under Appearance -> Menus." }
```

It is scoped to the locations a `nav` component in the composition references (a
nav component defaults to the `primary` location). A registered location that no
page renders stays silent -- no false alarms.

| It flags | Meaning | What to do |
|---|---|---|
| `no menu assigned` | The location has no WP menu attached | Assign a menu under Appearance -> Menus (registered locations: `primary`, `footer`) |
| `menu ... is empty` | A menu is attached but has zero items | Add menu items under Appearance -> Menus |
| `references unregistered location` | A nav component's `location` prop is not a registered location | Fix the nav component's `location`, or register it in `functions.php` |

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
