# Website Building — Mutation Surface Map

## Where to make changes

Every visual change maps to exactly one mutation surface. Writing to the wrong surface creates split authority and makes the site harder to maintain.

| What you want to change | Mutation surface | How |
|---|---|---|
| Page layout (which components, what order) | `_pp_composition` post meta | `update_composition` or `add_component` action |
| Component content (text, images, URLs) | `_pp_composition` post meta | `update_component` action |
| Site-wide colors, spacing, fonts | `pp_token_overrides` option (defaults in `base.css`) | `update_design_token` apply |
| Component structure + tone (steps layout, muted/inverted theme — `muted` = light tinted band, `inverted` = dark band) | `_pp_composition` post meta | `update_component` action (set `layout` or `theme` prop) |
| Component-specific CSS (spacing, layout) | `assets/css/components.css` | Direct file edit (BEM classes, token values only) |
| Site name, tagline | WordPress options | `update_site_option` action |
| Site logo (header) | WordPress option (Media Library attachment) | `update_site_option` action (key `pp_logo_id`, an attachment ID) |
| Favicon / app icon (browser tab, OS/app icon) | WordPress `site_icon` option (Media Library attachment) | `update_site_option` action (key `site_icon`, an image attachment ID; WP core's `wp_site_icon` then emits the `<link rel="icon">` / apple-touch-icon tags automatically. Rendered as-is on a direct write (no auto-crop), so supply a roughly square source, ideally >=512px) |
| Footer logo (on/off) | WordPress option (boolean) | `update_site_option` action (key `pp_footer_show_logo`, `1`/`0`/`true`/`false`; uses the same resolved logo as the header) |
| Footer logo override (light variant for a dark footer) | WordPress option (Media Library attachment) | `update_site_option` action (key `pp_footer_logo_id`, an image attachment ID; unset falls back to `pp_logo_id`, so `pp_logo_id` stays the header logo) |
| Dark marketing footer + blurb/contact/copyright | WordPress options | `update_site_option` action (background: `pp_footer_bg`, a CSS color **or** a bounded gradient; colors: `pp_footer_text` / `pp_footer_link_color`, any CSS color the style-slot validator accepts; text: `pp_footer_blurb` / `pp_footer_contact` / `pp_footer_copyright`, empty `pp_footer_copyright` keeps the default line) |
| Footer structure (column headings + delimited bottom bar) | WordPress options | `update_site_option` action (headings: `pp_footer_menu_label` / `pp_footer_contact_label` above the menu and contact columns; bottom bar: `pp_footer_note`, when set moves the copyright into its own delimited band with the note opposite it) |
| Dark or gradient marketing header | WordPress options | `update_site_option` action (background: `pp_header_bg`, a CSS color **or** a bounded `linear-gradient()`/`radial-gradient()`; colors: `pp_header_text` for the logo wordmark + mobile toggle, `pp_header_link_color` for the nav links). The header is template-owned, so this is its ONLY styling surface — never invert the site's global tokens to fake a dark header |
| Bringing an external image onto the site as a locally-owned asset | Media Library (new attachment, or reused if that source URL was already imported) | `import_media` apply (returns `{attachment_id, url, action}` where `action` is `import` or `reused` — re-importing the same source URL dedupes to the existing attachment; pass `url` to `image_url`/`background_image`/`logo_url`; on hero/section/logos also pass `attachment_id` as `image_id` for responsive srcset output) |
| Page-specific SEO metadata (meta description, `<title>` override, canonical URL) | `_pp_seo_meta` post meta | `update_seo_meta` action (patch; set a key to `""` to clear it) |
| Page slug / URL (post_name) | WordPress core (`post_name`) | `update_page_slug` action (post_id + slug), or the `slug` param on `create_page` to set the route up front. Always check the URL in the page inventory above before proposing a change — never guess or construct one |
| Redirect an old/renamed URL to its new location | `pp_redirects` option | `create_redirect` action (`from` path → same-site `to` target, 301 default / 302 optional). Use right after `update_page_slug` so the old URL 301s instead of 404ing. `remove_redirect` / `list_redirects` to manage them. `to` must be same-site (path or absolute same-host URL) |
| Custom/webfont loading and typography ("use Poppins for headings") | `pp_font_urls` option + `pp_token_overrides` option | `enqueue_font` apply with `url` + `family` + `apply_to` (`heading`\|`body`\|`both`) — one call both loads the stylesheet and points `--font-heading`/`--font-body` at it. `enqueue_font` with only `url` loads the font but changes nothing visible; it is not a substitute for `apply_to` |
| Navigation menus (create, add links, assign to a location, dropdown submenus) | WordPress core (nav_menu terms + nav_menu_item posts + `nav_menu_locations` theme mod) | `set_menu` action (declarative — name + full ordered `items` list + optional `location`, replace semantics, mirrors `update_composition`) for the common case; or `create_menu` + `add_menu_item` (×N) + `assign_menu_location` as separate steps. For a dropdown, give a top-level `items[]` entry a `children` array of the same `{page_id}` or `{url, label}` shape — exactly one level deep (a child with its own `children` is rejected); the theme renders it as an accessible dropdown on desktop and an expand-in-place group on mobile. Check the Navigation section in the page inventory above for existing menus/locations before proposing a change |

## What NOT to use

**WordPress Custom CSS (Appearance > Additional CSS)** creates split visual authority. All styling must go through design tokens or components.css. If Custom CSS exists and conflicts with theme classes, the system prompt will flag it. Use the `clear_custom_css` action to remove it.

## Component IDs: authored vs auto-generated

Every component in a composition has a persisted ID on save. There are two kinds, with different durability:

- **Authored IDs** — an explicit `id` prop you set (e.g. `"id": "pricing"`). These are stable: they live in your composition JSON, survive reordering, insertion, and deletion of other components, survive full `update_composition` re-applies, and double as HTML anchor targets.
- **Auto-generated IDs** — components written without an `id` prop get one in the reserved `pp-<hex8>` format (e.g. `pp-0a38d49e`). These persist across in-place actions (`update_component`, `add_component`, `remove_component`, reordering), but a **full-composition re-apply** (`update_composition` / `create_page` from a source JSON that has no `id` for that component) regenerates them. A `component_id` you recorded earlier then stops resolving (`component_not_found`).

Both kinds appear as HTML `id` attributes in the rendered DOM and are the only safe way to target a specific component instance in CSS.

**If you plan to target a component later — by `component_id` in actions, by anchor link, or in CSS — give it an explicit `id` in your source JSON.** To make an existing page durable, read the composition back (`wp pp operate inspect-composition <post_id>`) and set explicit `id`s for the components you care about; `wp pp check page` warns about components that only have auto-generated ids. The `pp-<hex8>` shape is reserved for generated ids — do not author ids in that format.

**Component ids must be unique within a composition.** Two components sharing the same `id` are rejected at write time (`create_page` / `update_composition` fail with `duplicate_component_id`), because `component_id` targeting would otherwise resolve silently to the first match and mutate the wrong component. If a duplicate ever reaches state through a raw write, `update_component` / `remove_component` / `style_component` fail closed with `component_ambiguous` rather than guessing.

Never use positional selectors (`nth-of-type`, `nth-child`) to target components. They break on reorder.

## Concurrent edits

Composition writes carry a version baseline captured when you read the page. If the page
changed since — another tab, the dashboard editor, a CLI run, or another chat write — your
write is rejected with `composition_conflict` and nothing is applied. This is protection,
not a failure to route around: re-read the page for its current state, then re-propose
against it. Never retry the same write hoping it lands.

## Escalation triggers

Stop and ask the user before proceeding when:
- Two components of the same type exist without IDs (ambiguous targeting)
- Custom CSS conflicts are detected in the system prompt
- A composition write is rejected with `composition_conflict` (the page changed under you — re-read and re-propose)
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
- Prefer a `hero`/`section` `layout` or `theme` change, an image, or a different component (grid, stats) to break up rhythm — not a narrower/tighter version of the same layout.
- A `hero` with `layout: left` needs a balancing image; without one, use `centered`, or `split` **with** an image or `proof` (a `split` with no image and no proof has no second column and degrades back to the single-column `left` layout, so it does not fix the imbalance on its own).
- Three or more consecutive components with `width: narrow`, or three or more with `spacing: compact`, will surface as a `consecutive_narrow_width`/`consecutive_compact_spacing` composition smell in `wp pp check page` — treat that as a signal to change the component or its content, not to suppress the warning by varying the count.
