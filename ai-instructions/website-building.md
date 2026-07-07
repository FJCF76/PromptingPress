# Website Building — Mutation Surface Map

## Where to make changes

Every visual change maps to exactly one mutation surface. Writing to the wrong surface creates split authority and makes the site harder to maintain.

| What you want to change | Mutation surface | How |
|---|---|---|
| Page layout (which components, what order) | `_pp_composition` post meta | `update_composition` or `add_component` action |
| Component content (text, images, URLs) | `_pp_composition` post meta | `update_component` action |
| Site-wide colors, spacing, fonts | `pp_token_overrides` option (defaults in `base.css`) | `update_design_token` apply |
| Component variants (dark, inverted, steps) | `_pp_composition` post meta | `update_component` action (set `variant` or `theme` prop) |
| Component-specific CSS (spacing, layout) | `assets/css/components.css` | Direct file edit (BEM classes, token values only) |
| Site name, tagline | WordPress options | `update_site_option` action |
| Site logo (nav, and footer via `show_logo`) | WordPress option (Media Library attachment) | `update_site_option` action (key `pp_logo_id`, an attachment ID) |
| Bringing an external image onto the site as a locally-owned asset | Media Library (new attachment) | `import_media` apply (returns `{attachment_id, url}` — pass `url` to `image_url`/`background_image`/`logo_url`; on hero/section/logos also pass `attachment_id` as `image_id` for responsive srcset output) |
| Page-specific SEO metadata (meta description, `<title>` override, canonical URL) | `_pp_seo_meta` post meta | `update_seo_meta` action (patch; set a key to `""` to clear it) |
| Page slug / URL (post_name) | WordPress core (`post_name`) | `update_page_slug` action (post_id + slug), or the `slug` param on `create_page` to set the route up front. Always check the URL in the page inventory above before proposing a change — never guess or construct one |
| Redirect an old/renamed URL to its new location | `pp_redirects` option | `create_redirect` action (`from` path → same-site `to` target, 301 default / 302 optional). Use right after `update_page_slug` so the old URL 301s instead of 404ing. `remove_redirect` / `list_redirects` to manage them. `to` must be same-site (path or absolute same-host URL) |
| Custom/webfont loading and typography ("use Poppins for headings") | `pp_font_urls` option + `pp_token_overrides` option | `enqueue_font` apply with `url` + `family` + `apply_to` (`heading`\|`body`\|`both`) — one call both loads the stylesheet and points `--font-heading`/`--font-body` at it. `enqueue_font` with only `url` loads the font but changes nothing visible; it is not a substitute for `apply_to` |
| Navigation menus (create, add links, assign to a location) | WordPress core (nav_menu terms + nav_menu_item posts + `nav_menu_locations` theme mod) | `set_menu` action (declarative — name + full ordered `items` list + optional `location`, replace semantics, mirrors `update_composition`) for the common case; or `create_menu` + `add_menu_item` (×N) + `assign_menu_location` as separate steps. Check the Navigation section in the page inventory above for existing menus/locations before proposing a change |

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
- The requested change would require a CSS feature not supported by the theme (`:has()`, `@container`, `backdrop-filter`, `mask-image` — note `color-mix(in srgb, ...)` IS allowed, for token-adaptive shadows and fades in components.css)

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

## Composition is not a presentation-polish tool

Hero's `width` and `spacing` props are structural knobs, not fixes for a page that feels cramped, memo-like, or visually weak (issue 51). Reaching for `width: narrow` or `spacing: compact` repeatedly across a page is a symptom, not a solution — it produces a composition that is structurally valid but visually worse. If a page feels wrong:
- Prefer a `hero`/`section` variant change, an image, or a different component (grid, stats) to break up rhythm — not a narrower/tighter version of the same layout.
- A `hero` with `variant: left` needs a balancing image; without one, use `centered` or `split`.
- Three or more consecutive components with `width: narrow`, or three or more with `spacing: compact`, will surface as a `consecutive_narrow_width`/`consecutive_compact_spacing` composition smell in `wp pp check page` — treat that as a signal to change the component or its content, not to suppress the warning by varying the count.
