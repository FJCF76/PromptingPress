# Website Building — Mutation Surface Map

## Where to make changes

Every visual change maps to exactly one mutation surface. Writing to the wrong surface creates split authority and makes the site harder to maintain.

| What you want to change | Mutation surface | How |
|---|---|---|
| Page layout (which components, what order) | `_pp_composition` post meta | `update_composition` or `add_component` action |
| Component content (text, images, URLs) | `_pp_composition` post meta | `update_component` action |
| Site-wide colors, spacing, fonts | `assets/css/base.css` design tokens | `update_design_token` apply |
| Component variants (dark, inverted, steps) | `_pp_composition` post meta | `update_component` action (set `variant` or `theme` prop) |
| Component-specific CSS (spacing, layout) | `assets/css/components.css` | Direct file edit (BEM classes, token values only) |
| Site name, tagline | WordPress options | `update_site_option` action |

## What NOT to use

**WordPress Custom CSS (Appearance > Additional CSS)** creates split visual authority. All styling must go through design tokens or components.css. If Custom CSS exists and conflicts with theme classes, the system prompt will flag it. Use the `clear_custom_css` action to remove it.

## Stable component IDs

Every component in a composition gets a persisted stable ID (e.g. `pp-a3f2b1`) on save. These IDs:
- Survive reordering, insertion, and deletion of other components
- Appear as HTML `id` attributes in the rendered DOM
- Are the only safe way to target a specific component instance in CSS

Never use positional selectors (`nth-of-type`, `nth-child`) to target components. They break on reorder.

## Escalation triggers

Stop and ask the user before proceeding when:
- Two components of the same type exist without IDs (ambiguous targeting)
- Custom CSS conflicts are detected in the system prompt
- A styling change requires writing to a surface not listed in the mutation map above
- The requested change would require a CSS feature not supported by the theme (color-mix, :has, @container, backdrop-filter, mask-image)

## Mobile expectations

The base breakpoint is mobile (375px). All components must be usable at this width:
- Hero CTA buttons visible without scrolling
- Grid cards stack to single column
- Tables scroll horizontally
- Nav collapses to hamburger

Verify at 375px viewport width before declaring a change complete.

## Desktop expectations

At 1280px+ viewport width, verify:
- Page content fills a credible horizontal space (no memo-column feel)
- No excessive unused space beside left-aligned heroes
- Section body text has comfortable reading measure (not too narrow, not edge-to-edge)
- Grid cards have enough internal padding to feel substantial, not sparse
- Homepage has a clear visual anchor (usually a centered hero)
