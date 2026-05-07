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
