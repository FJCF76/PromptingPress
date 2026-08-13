# PromptingPress — AI Context

## Quick orientation (read this first)

This is a WordPress site using the PromptingPress theme. WordPress handles the backend
(admin, database, media, plugins). This theme handles the frontend rendering only.

**Site customization vs. product development — read this first.** The parent-theme
directories `templates/`, `components/`, and `assets/` are **release artifacts**: a
theme update replaces the whole directory, so local edits are overwritten or deleted.
For **site customization**, treat them as **inspect-only** — change a site through
applies + the database (design tokens via `update_design_token`, fonts via
`enqueue_font`, content/layout via compositions), which survive updates. Editing
those files is **product/release development** (changing the theme itself). If a
site-specific change can only be done by editing one of those files, **STOP and
escalate** rather than editing it. See `AI_RULES.md` → "Parent-theme files are
inspect-only for site customization."

The page/component/template workflows below are **product-development procedures**
(authoring or improving the theme), not site-customization steps.

**To add a page (product dev):** Create or edit a file in `/templates/`. Call
`pp_get_component()` for each section. Register the template in WP Admin (Pages →
Edit → Page Attributes → Template).

**To edit a component (product dev):** Open `/components/{name}/{name}.php`. Props are
documented in `schema.json` in the same folder. CSS is in `/assets/css/components.css`.

**To add a page:** Follow the steps in `ai-instructions/add-page.md`.

**To build a landing page:** See `ai-instructions/build-landing-page.md` for a complete template with copy guidance.

**To add a component:** Follow the steps in `ai-instructions/add-component.md`. The
auto-loader picks up any component at `/components/{name}/{name}.php` — no registration needed.

**To retheme:** Read `ai-instructions/retheme.md`. Update design tokens via `update_design_token` apply (overrides stored in database, defaults in `assets/css/base.css`).

**To style a specific component instance:** Read `ai-instructions/style-component.md`. Use the `style_component` action to set per-instance CSS custom properties (style slots) without editing CSS files.

**To set the site logo:** Read `ai-instructions/set-logo.md`. Use the `update_site_option` action with key `pp_logo_id` (a Media Library image attachment ID, not a URL) to set the nav/footer logo.

**To set the site favicon / app icon:** Use the `update_site_option` action with key `site_icon` (a Media Library image attachment ID, not a URL). This is WordPress core's `site_icon` option, so once set the browser-tab favicon and app/OS icon (`<link rel="icon">` / apple-touch-icon) render automatically in `wp_head` — no page composition needed. Setting it here renders the attachment as-is (the Customizer's square-crop step is not run on a direct option write), so pass a roughly square source (ideally >=512px); any image is accepted. Same validation as `pp_logo_id` (must be an image attachment).

**To validate a site (CSS conflicts, navigation readiness, rendered review):** Read `ai-instructions/validate-site.md`. Run `wp pp validate site`; navigation readiness (empty/unassigned menus) surfaces automatically in preflight and post-apply output. Readiness findings are classified (#496 — integrity / configuration / capability) with a per-finding `next_action`; a deliberate configuration gap can be recorded as intentional with `wp pp readiness acknowledge <finding-key>` (see `wp pp readiness status`).

**To provision a new WordPress site:** Read `ai-instructions/bootstrap.md` for the full state contract and WP-CLI verification commands.

**Composition vs templates:** Use composition for all content-driven pages. Use template files only for structural or dynamic pages (archives, single posts, search results, 404).

**Document authority:**

| Source | Scope | Precedence |
|---|---|---|
| `AI_CONTEXT.md` | Orientation, file map, component index | Start here |
| `AI_RULES.md` | Hard invariants, coding rules | Overrides everything else |
| `ai-instructions/*.md` | Task-specific workflows | Executable procedures |
| `components/{name}/schema.json` | Prop contracts (types, required) | Supersedes prose in any other file |

**Never:**
- Add hooks or filters to template or component files (only in `functions.php`)
- Call WordPress functions directly in templates or components (use `pp_*` wrappers from `lib/wp.php`)
- Edit `lib/components.php` (it is the stable loader contract)
- Add raw hex values to `components.css` (use CSS variables only)

---

## File responsibility

"Safe to edit?" means safe for **release/product development**. For **site
customization**, the parent-theme rows (`templates/`, `components/`, `assets/`) are
**inspect-only** — use applies/DB or escalate (see AI_RULES.md). This includes the
`safe_to_edit` fields in each `schema.json`: those describe product-dev scope, not
site-customization permission.

| File/Folder              | Purpose                         | Safe to edit?                    |
|--------------------------|---------------------------------|----------------------------------|
| /templates/              | Page layouts                    | Release-level only — inspect for site work |
| /components/             | Reusable sections               | Release-level only — inspect for site work |
| /assets/css/base.css     | Design token defaults | Release-level only — site tokens via update_design_token |
| /assets/css/components.css | Component styles              | Release-level only — inspect for site work |
| /assets/css/utilities.css | Spacing / text utilities       | Release-level only — inspect for site work |
| /assets/js/pp-editor-logic.js | Pure JS logic (testable)   | Release-level only — run npm test after |
| /assets/js/main.js       | Nav toggle, active link         | Release-level only — inspect for site work |
| /tests/js/               | Vitest unit tests               | Yes — add tests for logic changes |
| /tests/e2e/              | Playwright E2E tests            | Yes — requires Docker (wp-env)   |
| .wp-env.json             | wp-env Docker config            | Yes — test environment only      |
| /lib/wp.php              | WP function wrappers (read + write) | Only to add pp_* functions   |
| /lib/actions.php         | Typed action model (20 actions) | Add actions following the contract |
| /lib/apply.php           | Apply layer (file + option mutations) | Add applies following the contract |
| /lib/cli.php             | WP-CLI `wp pp action` + `wp pp apply` + `wp pp operate` + `wp pp check` + `wp pp validate` + `wp pp integrity` + `wp pp screenshot` + `wp pp target` + `wp pp sync` | Yes |
| /lib/guardrails.php      | CSS conflict detection, surface classification, theme integrity | Extend for new checks |
| /lib/operate.php         | Operating loop: inspect, preflight, run tokens | Extend for new checks |
| /lib/setup.php           | Theme activation bootstrap, homepage provisioning, integrity hooks | Only to add idempotent setup |
| /lib/components.php      | Component loader                | No                               |
| /lib/helpers.php         | Utility functions               | Yes — only to add                |
| functions.php            | WP registration                 | Only to add                      |
| style.css                | Theme header (WP requirement)   | No                               |
| AI_RULES.md              | AI coding rules and invariants  | Only to update invariants        |
| /lib/ai-context.php      | AI site context layer             | Extend for new context sources     |
| /lib/ai-provider.php     | LLM provider proxy (streaming)    | Extend for new providers           |
| /lib/screenshot.php      | Screenshot capture (browser integration) | Extend for new capture modes   |
| /lib/post-apply-validate.php | Post-apply DOM validation       | Extend for new checks              |
| /lib/ai-chat.php         | AI chat page + AJAX handlers      | Yes                                |
| /ai-stream.php           | SSE streaming endpoint            | Thin transport only                |
| /assets/js/pp-ai-chat.js | AI chat UI (streaming, proposals) | Yes                                |
| /assets/css/pp-ai-chat.css | AI chat styles                  | Yes                                |
| AI_CONTEXT.md            | This file — AI site map         | Keep current when structure changes |

---

## Component index

| Component | File                           | Description                                      | Key props                                          |
|-----------|--------------------------------|--------------------------------------------------|----------------------------------------------------|
| hero      | components/hero/hero.php       | Full-width headline + optional CTA and image     | title (req), title_accent, eyebrow, subheading, button_text, button_url, button2_text, button2_url, button_variant, button2_variant, layout, image_url, image_id, image_alt, spacing, width, split_ratio, vertical_align, proof, id |
| section   | components/section/section.php | Text + optional image or content panel. 5 structural layouts. Needs one of body / body_items / panel content (#488) | body, body_items, title, title_accent, eyebrow, subheading, title_align, image_url, image_id, image_alt, layout, theme, background_image, panel_heading, panel_body, panel_items, panel_items_marker, panel_cta_text, panel_cta_url, panel_cta_variant, body_marker, id |
| faq       | components/faq/faq.php         | Native details/summary accordion. Zero JS. Auto-emits FAQPage JSON-LD. | items[] (req) {question, answer}, title, title_accent, eyebrow, theme, id |
| grid      | components/grid/grid.php       | Responsive card grid for real content objects    | items[] (req) {number, title, text, text_role, bullets[], image_url, image_alt, image_id, link_url, link_text, style (per-card style overrides — card-scoped grid slots, set in composition not style_component)}, title, title_accent, eyebrow, subheading, title_align, layout, card_emphasis, theme, columns, image_treatment, id |
| table     | components/table/table.php     | Data/comparison table, horizontal scroll at any viewport | headers[] (req), rows[][] (req), title, caption |
| cta       | components/cta/cta.php         | Call-to-action block. Layout + color + bg-image  | title, title_accent, eyebrow, button_text (req), button_url (req), button_variant, button2_text, button2_url, button2_variant, body, layout, theme, background_image, id |
| stats     | components/stats/stats.php     | Horizontal row of large-number metrics + labels  | items[] (req) {number, label}, title, title_accent, theme, background_image, id |
| logos     | components/logos/logos.php     | Flex-wrap image grid — logo strips or icon tiles | items[] (req) {image_url, image_alt, image_id?, label?}, title, theme, id |
| embed     | components/embed/embed.php     | WP shortcode / plugin content wrapper            | content (req), title, theme, id                    |
| testimonials | components/testimonials/testimonials.php | Customer quotes with attribution — card grid or single-column stack | items[] (req) {quote (req), author, role, company, image_url, image_alt, image_id}, title, title_accent, eyebrow, subheading, title_align, layout, theme, id |

### Site chrome — rendered by the template, NOT composable (#223)

`pp_base_template` renders these on every page. Putting either in `_pp_composition`
renders the header or footer twice, and the write is rejected with the error code
`template_owned_component`. Their props are set by the template, not by a page.

| Component | File                         | Description                             | Configure it via |
|-----------|------------------------------|-----------------------------------------|------------------|
| nav       | components/nav/nav.php       | Site header, logo, hamburger mobile nav, one-level dropdown submenus | Logo: `pp_logo_id` site option. Menu: `set_menu` / `assign_menu_location` (location `primary`); nest links under a top-level item with `set_menu`'s per-item `children` array to get an accessible dropdown (desktop) / expand-in-place group (mobile). Dark/gradient header: `pp_header_bg` (CSS color **or** gradient) + `pp_header_text` / `pp_header_link_color` (CSS colors) site options |
| footer    | components/footer/footer.php | Site footer with nav menu and copyright | Logo: `pp_logo_id` site option (footer override: `pp_footer_logo_id`). Menu: `set_menu` / `assign_menu_location` (location `footer`, or `footer_secondary` for a second menu column). Dark footer + blurb/contact/copyright: `pp_footer_bg` (CSS color **or** gradient) / `pp_footer_text` / `pp_footer_link_color` (CSS colors) + `pp_footer_blurb` / `pp_footer_contact` / `pp_footer_copyright` (text) site options. Structure (#335): `pp_footer_menu_label` / `pp_footer_contact_label` (column headings) + `pp_footer_note` (moves copyright into a delimited bottom bar). Second menu column (#469): assign a menu to `footer_secondary`; `pp_footer_secondary_label` is its optional heading (unassigned = footer unchanged). Social-icon row (#382): `pp_footer_social`, a JSON list of `{network, url}` from a closed network set (x, linkedin, facebook, instagram, youtube, github, tiktok, mastodon), rendered as accessible inline-SVG icon links under the brand blurb; color follows `pp_footer_link_color` |

### Component capabilities reference

**Two consistent axes — `layout` (structure) and `theme` (color/tone).** There is no `variant` prop anywhere; the name is retired. A write carrying `props.variant` is rejected as `unknown_prop` on every write path (`create_page`, `update_composition`, `add_component`, `update_component`) — v1 accepts no alias, only `layout`/`theme` (#388). Nothing decodes a stored `variant` on any path either: the read-path migration was removed (#604), so a pre-rename snapshot restores and renders with `variant` intact and simply unread. Structure is always `layout`, color/tone is always `theme`, and they never overload one key:

- **`layout`** (structural, changes DOM/rendering) is used by the components that have more than one structure: `hero` (`left`, `centered`, `split`, `cover`), `section` (`text-only`, `image-left`, `image-right`, `centered`, `text-panel`), `grid` (`cards`, `steps`), `cta` (`full-width`, `inline`), `testimonials` (`grid`, `stack`).
- **`theme`** (color/tone preset, `default` | `muted` | `inverted`) is used by the section-level components that carry a background tone: `section`, `stats`, `logos`, `embed`, `grid`, `cta`, `testimonials`, `faq`. `muted` is a light tinted surface band (`--color-surface`) with framing borders; for a genuinely **dark** band use `inverted`. The three values above are the WHOLE accepted set: anything else is rejected at write with `invalid_prop_value`, and a value stored before the vocabulary freeze renders as `default` (#605). Note that a `muted` band still emits the legacy `<root>--dark` CSS class — that is an output name, not a value you can write.
- `hero` has no `theme` prop — its color comes entirely from style slots (`--hero-bg`, etc.).
- Components with a single structure (`stats`, `logos`, `embed`) expose only `theme`, not `layout`.
- `style_component` / recipes / style slots remain the final visual authority over any `theme` or default CSS — **when the slot applies**. Many slots only render in a particular configuration (a hero's `--hero-surface-*` needs `layout: "split"` plus `proof`; testimonials' card slots need `layout: "grid"`), and the schema declares that condition in `applies_when`. The runtime component catalog appends `applies when ...` to those slots; setting one whose condition is unmet is accepted and stored but paints nothing, and `wp pp check page` reports it as a non-blocking `inert_slot` smell. Check the condition before styling — see `ai-instructions/style-component.md`.

If a future component needs both structure and color control, give it both `layout` and `theme` — never reuse one key for two meanings.

**Background images:** hero (via `cover` layout + `image_url`), section (`background_image` prop), cta (`background_image` prop), and stats (`background_image` prop) support CSS background-image with a dark overlay and light text. All four use the same implementation pattern:
- `background-image` inline style on the root `<section>` element
- A child `div.{component}__overlay` (e.g. `.hero__overlay`) whose scrim routes a per-instance slot: `background: var(--{component}-overlay-bg, var(--overlay-bg))`. All four carry that slot (stats was the last, #577), so an author can darken or lighten one band's scrim without touching the global token.
- Container gets `position: relative; z-index: 1` to sit above the overlay
- Text colors switch to `var(--color-bg)` for contrast, EXCEPT deliberately de-emphasised ink (the cta `body`, the stats `label`), which routes `var(--color-muted-on-overlay)`. Never express de-emphasis on these bands with `opacity`: the overlay-over-white worst case caps contrast at 4.74:1, so any alpha below ~0.97 drops normal-size text under the 4.5:1 AA bar (#577).

If adding background-image support to another component, follow this exact pattern.

**Responsive images (hero, section, logos, grid, testimonials):** `image_url` (hero's `split` layout, section's `image-left`/`image-right` layouts, each logos item, each grid card, each testimonials avatar) has a companion `image_id` — a Media Library attachment ID, not a URL. When `image_id` resolves to a real attachment, the `<img>` renders responsively via `wp_get_attachment_image()` (real `srcset`/`sizes`); when unset or unresolvable, the plain `image_url` renders exactly as before. Get an attachment ID via the `import_media` apply — it returns `{attachment_id, url, action}`, so pass its `attachment_id`, never the whole object (a non-numeric `image_id` is rejected at write since #614, at both the top level and inside `items[]`). Not used for `background_image`/`cover` (CSS `background-image`, not an `<img>` tag). `image_id` is a COMPANION to `image_url`, never a replacement: an item carrying only an id renders no image at all, so always set `image_url` too.

**Anchor IDs:** All 10 section-level components (hero, section, stats, grid, logos, cta, embed, testimonials, table, faq) accept an `id` prop that renders as the HTML `id` attribute on the root `<section>` element. Use for anchor navigation. All ten also carry `scroll-margin-top`, so an anchor jump clears the sticky header. The two template-owned chrome components (nav, footer) do NOT accept `id`: they are rendered once by the theme, never composed, and declare no `id` prop.

**Hero:** `layout` = `left`, `centered`, `split` (inline image), `cover` (fullscreen background-image with overlay). A `split` hero with neither an image (`image_url`/`image_id`) nor `proof` has nothing for the second column and degrades to the single-column `left` layout (text at full content width); add an image or proof to get the two-column split. Supports dual CTA buttons (`button_text` + `button2_text`), each with an independent `button_variant`/`button2_variant` (`primary`/`secondary`/`outline`/`ghost`; secondary defaults to `outline` — these are button-style props, unrelated to the layout `layout`). Composition props: `spacing` (compact/default/spacious), `width` (narrow/default/full), `split_ratio` (50-50/60-40/40-60, split layout only), `vertical_align` (top/center/bottom/stretch, cover and split only; `stretch` is split-only — the media column fills the content column's height so one asset balances any headline length, and renders like `center` on cover), `proof` (HTML string for trust signals like logos/ratings, rendered after CTA group). Hero content uses `--measure-centered` (56rem) as default max-width.

**Nav/Footer:** Supports image logos. The `logo_id` / `logo_alt` / `logo_text` **props are not reachable** (#582) — nav and footer are template-owned chrome, so a composition naming either is rejected with `template_owned_component` and the prop-level checks never run. Set the logo through the **site options**: `pp_logo_id` (a Media Library **image** attachment ID, not a URL; a non-image or non-existent ID is rejected when `update_site_option` is validated) and `pp_logo_alt` for alt text. Resolution: `pp_logo_id` site option (or `pp_footer_logo_id` in the footer) → WP `custom_logo` theme-mod → text wordmark, which is always the site title. Alt resolution: `pp_logo_alt` → the attachment's own alt metadata → the site title; the alt is **never empty**, so `pp_logo_alt` is an override for when the alt should differ from the attachment's own, not a requirement. It is one site-wide value shared by the header and footer logos (there is no `pp_footer_logo_alt`), so when a footer runs a different image via `pp_footer_logo_id`, a set `pp_logo_alt` wins over that attachment's alt too. A value that is empty **or whitespace-only** counts as unprovided and falls through to the rest of the chain rather than rendering (a blank alt announces nothing and would suppress the attachment's own alt); a real value renders verbatim, surrounding spaces included. Footer logo is off by default; turn it on with the `pp_footer_show_logo` site option (a boolean `1`/`0`/`true`/`false`) via `update_site_option` — composing a `footer` to pass `show_logo` is rejected (#223). Set the site-wide logo through the `update_site_option` action with key `pp_logo_id`; the footer can override it with `pp_footer_logo_id` (#335) — an image attachment ID (not a URL, same rule as `pp_logo_id`) so a light logo variant can serve a dark footer while `pp_logo_id` stays the header logo (unset falls back to `pp_logo_id`). **Dark marketing footer (#300):** the footer is template-owned (no composition style slots), so its look is set through site options — `pp_footer_bg` / `pp_footer_text` / `pp_footer_link_color` (rendered as inline `--footer-*` custom properties) for a dark band, plus `pp_footer_blurb` (brand line under the logo), `pp_footer_contact` (contact/secondary text), and `pp_footer_copyright` (replaces the default copyright line; empty keeps `© <year> <site title>. All rights reserved.`). **Footer structure (#335):** `pp_footer_menu_label` and `pp_footer_contact_label` add optional column headings above the footer menu and contact block; `pp_footer_note`, when set, moves the copyright into its own delimited bottom bar and renders a secondary line opposite it (empty keeps the copyright inline). **Second footer menu column (#469):** assign a menu to the `footer_secondary` theme location (`assign_menu_location` / `set_menu`) to render a second footer menu column (e.g. a distinct Legal column) alongside the primary footer menu; `pp_footer_secondary_label` is its optional heading (empty = a headless column). With no menu assigned to `footer_secondary`, the footer is byte-identical to the single-menu layout. **Social-icon row (#382):** `pp_footer_social` surfaces the near-universal footer social row. It is a JSON string, an ordered list of `{network, url}` objects (e.g. `[{"network":"x","url":"https://x.com/acme"},{"network":"linkedin","url":"https://linkedin.com/company/acme"}]`), where `network` is one of a CLOSED set of known networks (`x`, `linkedin`, `facebook`, `instagram`, `youtube`, `github`, `tiktok`, `mastodon`) and `url` is an http(s) profile URL. It renders under the brand blurb as accessible inline-SVG icon links (one `aria-label` per link, decorative `aria-hidden` SVG), color following `pp_footer_link_color`. The network glyphs ship inline with the theme (no icon font, no external requests, no arbitrary icon URLs). Unknown networks, non-URL / non-http(s) values, and malformed JSON are rejected with the standard envelope; empty/unset renders no row and leaves the footer byte-identical. Unset, the footer renders exactly as before.

**Header chrome (#333):** the header is template-owned too, so — exactly like the footer — it has no composition style slots and its ONLY styling surface is site options: `pp_header_bg` / `pp_header_text` / `pp_header_link_color`, rendered as inline `--header-*` custom properties. `pp_header_text` colors the logo wordmark and the mobile toggle; `pp_header_link_color` colors the nav links, including the active/current link (#355 — it no longer forces `--color-accent` over the operator's chosen color; the bold weight still marks the current item). Hover keeps `--color-accent` (a global design token), and an unset header link color falls back to `--color-accent` for the active link, so an unstyled header renders exactly as before. Style the header to match the SITE's real header, not the hero — a dark hero does not mean the header should be dark. Do NOT tell the user the header cannot be styled, and do NOT try to style it by inverting the site's global tokens — set these options.

**Backgrounds accept gradients (#333).** `pp_header_bg` and `pp_footer_bg` are `gradient`-typed: they accept everything a CSS color does (hex, `rgb()`/`hsl()`, `transparent`, `currentColor`, a bare `var(--token)` reference to a registered color token) **and** a bounded `linear-gradient()` / `radial-gradient()` with 2+ color stops — e.g. `linear-gradient(135deg, #1a1a2e, #16121f)`. That is how a gradient marketing header or footer is built. The four text/link options (`pp_header_text`, `pp_header_link_color`, `pp_footer_text`, `pp_footer_link_color`) take a plain CSS color only — a gradient on those is rejected. `conic-gradient()`, `repeating-*-gradient()`, and `var()`/`url()`/`env()` inside a gradient are not accepted.

**Grid:** `layout` = `cards` (card grid), `steps` (numbered process cards — filled circular number badge, subtle connector line between badges at desktop). `theme` controls background color independently of `layout`. `card_emphasis` = `featured` (default — the first card gets an accent bar, tinted fill, larger title, extra top-padding, and a dark-theme lift, drawing the eye to a lead item) or `uniform` (every card renders identically). Use `uniform` for a symmetric/peer card row where the cards are equal and the featured first card would mislead or misalign them — e.g. specification/comparison cards whose checklists must line up, or an equal-weight feature/plan row (the featured card's extra top-padding otherwise pushes its content below its neighbors). Keep `featured` when one card is genuinely the lead. It is a structural prop (set via `create_page`/`update_component`), not a style slot, and unlike the slot-level `uniform-cards` recipe it drops the whole featured treatment including the first-card padding and dark lift. `columns` (integer 1-4) forces the desktop (>=768px) column count instead of the default auto-derivation from item count (2->2-up, 3->3-across, 4->2x2, other->2) — e.g. `columns: 3` lays a 6-item grid out 3-across x 2-rows; unset keeps the auto grain (byte-identical), out-of-range/non-integer values are rejected (not clamped), and it is ignored on `steps`. `image_treatment` = `banner` (default — each card's `image_url` renders as a full-width 16:9 cover banner above the body) or `icon` (renders the image at a small fixed icon size, default 48px via the item-eligible `--grid-item-icon-size` slot, un-cropped `object-fit: contain`, above the title) — use `icon` for icon+title+text feature cards where a ~45px logo/glyph would otherwise be blown up into a cropped banner. Structural prop (`create_page`/`update_component`); unset keeps `banner` (byte-identical), invalid values are rejected with `invalid_prop_value` (closed set `banner`|`icon`, not coerced), and it is ignored on `steps` (which renders no item images). Card items accept `bullets` — a checklist of plain-text lines rendered below `text`, each prefixed with a check mark. Card alignment is authorable via the `align`-typed `--grid-item-text-align` slot (`left` default / `center` / `right` / `start` / `end` / `justify`) — item-eligible, so settable grid-wide or per-card in `items[].style` (e.g. centered emoji/label contact cards). It aligns the text (title/text/bullets), the `Read more` link/button (#361), AND — under `image_treatment: icon` — the icon itself (#380), which all follow the same one slot, so a centered card (including a centered icon+title+text card) is fully centered.

**FAQ structured data (#3):** The FAQ component always emits a `<script type="application/ld+json">` FAQPage schema block inside its own `<section>` (as the section's last child, so it does not interrupt the adjacent-sibling band rhythm of a following component — #432), derived from `items` — zero-config, no toggle prop. `question`/`answer` are stripped of HTML (`wp_strip_all_tags()`) before encoding, since Google's FAQPage schema expects plain text; items missing a question or answer are skipped. Nothing is emitted if there are no complete items.

**Section headers (hero, section, faq, grid, cta, testimonials):** `eyebrow` renders a short kicker label as a pill above the title on all six. The pill is styled through per-component eyebrow style slots — color, bg, radius, border, and casing: the `text-transform`-typed `--<component>-eyebrow-text-transform` slot defaults to `uppercase`; set it to `none` for a sentence-case kicker (or `lowercase`/`capitalize`). `subheading` (section, grid, testimonials only) renders a supporting line below the title — hero uses `subheading` and cta uses `body` for the same concept, so neither has a `subheading` prop. `title_align` (`start` default, or `center`; section, grid, testimonials only) centers the eyebrow/title/subheading block, independent of the component's own `layout`.

**title_accent (hero, section, grid, cta, faq, stats, testimonials):** All seven heading-bearing components accept `title_accent` — an exact, case-sensitive substring of `title` rendered in a per-component accent color slot (e.g. `--hero-heading-accent-color`). It is a structured plain-text mechanism, not an HTML allowlist: if `title_accent` isn't a literal substring of `title`, it is silently ignored and `title` renders in full.

**CSS invariant:** Component CSS in `components.css` must use only CSS variables from `base.css` — never raw hex values. Color decisions belong to the design tokens, not to individual components.

---

## Mutation surfaces

Every visual change maps to one surface. Writing to the wrong surface creates split authority.

| Change | Surface | Method |
|---|---|---|
| Page layout / content | `_pp_composition` post meta | Actions: `update_composition`, `add_component`, `update_component` |
| Per-instance visual styling | `_pp_composition` post meta (`style` key) | Action: `style_component` (patch style slots on a single component instance) |
| Site-wide colors, spacing, fonts | `pp_token_overrides` option (defaults in `base.css`) | Apply: `update_design_token`, `reset_design_token`, `reset_all_design_tokens` |
| Custom font loading | `pp_font_urls` option | Apply: `enqueue_font`, `remove_font`, `reset_fonts` |
| Component-specific CSS | `assets/css/components.css` | Direct file edit (BEM, tokens only) |
| Site name / tagline | WordPress options | Action: `update_site_option` |
| Favicon / app icon | WordPress `site_icon` option (Media Library attachment) | Action: `update_site_option` (key `site_icon`, an image attachment ID; core's `wp_site_icon` renders the `<link rel="icon">` tags automatically) |
| Navigation menus | WP nav menus + `nav_menu_locations` theme mod | Actions: `set_menu` (declarative replace; each item may carry a `children` array for a one-level dropdown submenu), `create_menu`, `add_menu_item`, `assign_menu_location` |
| Page SEO metadata | `_pp_seo_meta` post meta | Action: `update_seo_meta` (patch semantics; keys `meta_description`, `seo_title`, `canonical_url`, and the per-page social-title overrides `og_title` / `twitter_title`) |
| Open Graph / Twitter social-share meta | `pp_og_*` + `pp_twitter_card` options (site-wide) + `og_title` / `twitter_title` on `_pp_seo_meta` (per page) | Actions: `update_site_option` (keys `pp_og_image` = image attachment ID for og:image/twitter:image, `pp_og_site_name`, `pp_og_default_description` ≤320 chars, `pp_twitter_card` = `summary`\|`summary_large_image`) + `update_seo_meta` (`og_title` / `twitter_title`). Rendered as og:\*/twitter:\* tags in `wp_head`; a tag is emitted only when its resolved value is non-empty |
| Front-end redirects | `pp_redirects` option | Actions: `create_redirect` (old path → same-site target, 301/302), `remove_redirect`, `list_redirects`. Resolves on a 404 only — pair with `update_page_slug` so a renamed page's old URL keeps working |
| Images into the Media Library (remote URL or local brand-kit file) | Media Library | Apply: `import_media` (sideload a remote `url` OR a server-local absolute `file`, #490; returns attachment id + local URL) |

**Never use:** WordPress Custom CSS (Appearance > Additional CSS). Use `clear_custom_css` action if conflicts exist.

**Component IDs:** Every composition component gets a persisted ID on save (auto-generated `pp-<hex8>` when no `id` prop is authored). Use ids for CSS targeting, never positional selectors. Only authored `id` props survive a full `update_composition` re-apply — author an explicit `id` for anything targeted later (#232). IDs must be unique within a composition: two components sharing a non-empty `id` are rejected at write time with `duplicate_component_id`, and if a duplicate reaches state through a raw write the resolver fails closed with `component_ambiguous` rather than targeting the first match (#238).

**Component props:** Only props declared in a component's `schema.json` `props` are accepted. A composition whose component carries an undeclared prop key is rejected at write time with `unknown_prop` — the full prop contract is the source of truth (not the narrower schema-derived `pp_get_component_fields()` scalar-patch set, which is scalar-props-only). This covers every write path (`add_component`, `update_component`, `update_composition`, `create_page`), so an unknown key never persists behind an `ok:true` while the renderer silently ignores it (issue 147). Symmetric to the style-slot rule below: declared-only, arbitrary-rejected. `restore_composition` never blocks on this rule — it restores the snapshot verbatim and reports `unknown_prop` as a finding (#233) — and since #622 `wp pp check page` / `wp pp validate site` report a stored `unknown_prop` as an error-severity finding too, so a stale page does not read as clean on the surfaces you query first.

**Guardrails:** `lib/guardrails.php` provides `pp_check_custom_css_conflicts()`, `pp_validate_composition_styling()`, `pp_classify_surface()`, `pp_check_theme_integrity()`, `pp_validate_composition_smells()`, the #232 id-durability pair `pp_is_generated_component_id()` / `pp_find_generated_component_ids()` (auto-generated `pp-<hex8>` ids do not survive a full `update_composition` re-apply; `wp pp check page` warns per affected component), and `pp_find_duplicate_component_ids()` (#238; backs both the write-time `duplicate_component_id` rejection and the matching smell). CLI: `wp pp check conflicts`, `wp pp check page --post_id=N`, `wp pp check surface <path>`, `wp pp validate site`. The two composition diagnostics (`check page`, `validate site`) read `_pp_cli_page_diagnostics()` (`lib/cli.php`), which reads the shared findings engine `_pp_composition_findings()` — so they report ERROR-severity findings (what a normal write would reject) alongside styling warnings and advisory smells, in one `[type] index N: message` format (#622). `validate site` exits non-zero on either kind; `check page` reports and returns. Surface classification integrated into preflight — core files are blocked with routing guidance toward approved database-backed surfaces.

**Composition smells:** `pp_validate_composition_smells()` flags structurally valid but visually weak page patterns, surfaced by `wp pp check page`: `hero_left_no_image` (left-aligned hero with no balancing image), `hero_split_no_media` (a `split` hero with no image and no `proof` — it has no second column and degrades to the single-column `left` layout; issue 440), `consecutive_text_sections` (3+ consecutive text-only sections), `consecutive_narrow_width`/`consecutive_compact_spacing` (3+ consecutive components using `width: narrow` or `spacing: compact` — issue 51), `empty_section` (a component whose configured content produces no useful frontend output, e.g. `items: []`, a faq item with no `question`, a testimonials band whose items all lack a `quote`, an `embed` with no `content`, a `section` carrying none of its `content_requirement.any_of` props, a `cta` whose button label is blanked with no eyebrow/title/body, or a `hero` with no title/eyebrow/subheading/button_text/image_url/image_id/proof — issue 87, widened from five components to ten in #579; only `nav` and `footer` are exempt, being template-owned chrome; non-destructive: it only warns, never auto-removes content), and `transparent_fill` (any style slot declaring `role: "fill"` in its schema — today the hero and cta button fills plus `--section-panel-cta-bg`, and the hover twins of the hero/cta fills — set to `transparent` or `currentColor`. On a resting fill that leaves a clickable button with no visible surface, and the advisory points at the `outline` button variant; on a hover fill it only flattens the pointer state, and the advisory says so instead. Read the `role` marker rather than a slot-name pattern — #579, warn-only: `transparent` stays an accepted value). These are warnings, not blockers; see `ai-instructions/website-building.md` for guidance on fixing the underlying presentation issue instead of suppressing the smell.

**Design-token smells (INSPECT):** separate from composition smells, `pp_inspect_site()` returns a `token_smells` array via `pp_detect_masked_derived_smells()` (#386). It flags `masked_derived_override`: a derived-family override (e.g. `--color-accent-strong`) that diverges from the value its base token (`--color-accent`) currently derives. Because `update_design_token` preserves existing derived overrides, such an override keeps winning in the rendered CSS, so a base-token change succeeds yet is not visible where the override applies. The same divergence is returned inline on the `update_design_token` apply result as `stale_warnings`; the INSPECT smell surfaces it before a change is made. Non-destructive advisory — no token value is ever changed by the detector.

**Theme integrity:** `pp_check_theme_integrity()` compares live theme files against the shipped `integrity-manifest.json` (generated at build time). Stores result in `pp_theme_integrity` option. `pp_admin_notice_theme_integrity()` shows a persistent red admin notice when files have been modified, or a yellow notice when the manifest is invalid. CLI: `wp pp integrity check` (exit 0=safe, 1=unsafe, 2=invalid manifest, 3=no manifest), `wp pp integrity status` (read-only). Checks run automatically on theme activation and after theme updates.

---

## Calling a component

```php
pp_get_component('hero', [
    'title'    => pp_field('hero_title') ?: 'Welcome',
    'subheading' => pp_field('hero_subtitle'),
    'button_text' => pp_field('hero_cta_text') ?: 'Get Started',
    'button_url'  => pp_field('hero_cta_url')  ?: '#',
    'layout'   => 'centered',
]);
```

---

## WP abstraction layer (lib/wp.php)

All functions are prefixed `pp_`. Templates and components use only these wrappers.

| Function                      | Returns                                         |
|-------------------------------|-------------------------------------------------|
| `pp_site_title()`             | Site name (get_bloginfo)                        |
| `pp_site_description()`       | Site tagline                                    |
| `pp_site_url($path)`          | Home URL with optional path                     |
| `pp_page_title()`             | Current post/page title                         |
| `pp_page_content()`           | Current post content with WP filters applied    |
| `pp_field($name, $id)`        | ACF field value, or null if ACF not installed   |
| `pp_nav_menu($location)`      | Renders WP nav menu (no output if unassigned)   |
| `pp_posts($args)`             | Returns WP_Query object                         |
| `pp_the_loop($query, $cb)`    | Iterates query, calls $cb() per post            |
| `pp_main_query()`             | Returns the request's main WP_Query — already correctly filtered for the current route (archive, is_home). Prefer over a fresh `pp_posts()` query when rendering "the listing for this route" (#126) |
| `pp_pagination()`             | Returns pagination `<nav>` HTML for the main query, or `''` if there's only one page (#126) |
| `pp_search_query()`           | Returns the raw search query string for the current search request (issue 138) |
| `pp_result_count()`           | Returns `found_posts` from the main query — the full match count across all pages (issue 138) |
| `pp_is_front_page()`          | bool — true on front page                       |
| `pp_body_classes()`           | Space-separated body class string               |
| `pp_excerpt($length)`         | Trimmed excerpt (default 55 words)              |
| `pp_permalink()`              | Current post permalink                          |
| `pp_thumbnail_url($size)`     | Post thumbnail URL (default 'large')            |
| `pp_comments_template()`      | Renders the comments template for the current post (single.php) |
| `pp_esc_image_src($url)`      | Escapes an image source for `<img src>` or inline CSS `url(...)` — the only sanctioned escaper for image props; validates `data:image/*` URIs (incl. SVG content checks), returns `''` for anything unsafe |
| `pp_render_heading_with_accent($title, $accent, $accent_class)` | Splits `$title` on the exact `$accent` substring and wraps it in `<span class="$accent_class">`; both fragments esc_html'd. Returns the escaped title unchanged when `$accent` is empty or not a substring |
| `pp_render_responsive_image($url, $alt, $class, $loading, $attachment_id)` | Renders `wp_get_attachment_image()` (real srcset/sizes) when `$attachment_id` resolves to an image attachment; plain escaped `<img src="$url">` otherwise |
| `pp_render_faq_schema($items)` | Returns the FAQPage JSON-LD `<script>` block for faq items (plain-text-stripped), or `''` when no complete items |
| `pp_default_homepage_composition()` | Curated branded multi-band starter composition (split hero, audience section, mechanism text-panel, speed/trust grid, proof section, closing CTA), styled via validated per-component style slots — single source of truth for activation seeding and blank-page fallback |
| `pp_get_composition($post_id)` | Composition array for any page by ID (returns [] if absent, corrupt, or non-list). No name is resolved on read (#603, #604): the retired `variant` prop, retired prop keys and undeclared style-slot names are all handed back exactly as stored. What you read IS the stored bytes — reads and storage can never disagree |
| `pp_get_composition_result($post_id)` | State-classifying read: `['ok'=>bool,'composition'=>array,'error'=>?string,'raw'=>?string]`. Distinguishes absent vs empty `[]` vs `decode_error` (undecodable JSON) vs `unexpected_shape` (valid JSON that isn't a list, e.g. an object). Use this (not `pp_get_composition`) when a check must tell a corrupted page apart from a blank one; `wp pp check page`/`validate site`/`validate page` and `pp_inspect_site()` all surface its error (issue 144) |
| `pp_style_declaration_renders($name, $value, $slots)` | Whether one stored style declaration will actually be emitted: the slot is declared AND the value clears the #330 render boundary. The single predicate behind "does this paint?" — call it rather than re-deriving either gate |
| `pp_composition_pages()`       | All composition pages: [{id, title, status, url}, ...] (static cached) |
| `pp_get_menus()`               | All navigation menus: [{id, name, location (registered theme location or null), items: [{title, url}, ...]}, ...] (issue 132) |
| `pp_create_nav_menu($name)`    | Creates a menu. Returns menu (term) ID\|WP_Error |
| `pp_add_nav_menu_item($menu_id, $item)` | Adds one item — `['page_id' => int]` (page link) or `['url' => ..., 'label' => ...]` (custom link), optional `'position'`. Returns item ID\|WP_Error |
| `pp_clear_nav_menu_items($menu_id)` | Removes every item from a menu (menu itself stays); used by `set_menu`'s replace semantics |
| `pp_assign_menu_location($menu_id, $location)` | Assigns a menu to a registered theme location (`primary`, `footer`, `footer_secondary`) |
| `pp_design_tokens()`           | CSS custom properties merged from base.css defaults + database overrides. Returns `['--token' => ['value' => string, 'type' => string\|null]]`. Static cached. |
| `pp_invalidate_design_tokens_cache()` | Resets the pp_design_tokens() static cache. Call after modifying token overrides. |
| `pp_get_token_overrides()`     | Returns database-stored token overrides as `['--token' => 'value']`. |
| `pp_set_token_override($token, $value)` | Writes a single token override to the database. |
| `pp_clear_token_override($token)` | Removes a single token override (reverts to default). |
| `pp_clear_all_token_overrides()` | Removes all overrides (reverts site to shipped defaults). |
| `pp_site_option($key)`         | Whitelisted option value (blogname, blogdescription, pp_logo_id, pp_logo_alt, site_icon, pp_footer_show_logo, pp_footer_bg, pp_footer_text, pp_footer_link_color, pp_footer_blurb, pp_footer_contact, pp_footer_copyright, pp_footer_menu_label, pp_footer_contact_label, pp_footer_secondary_label, pp_footer_note, pp_footer_logo_id, pp_footer_social, pp_header_bg, pp_header_text, pp_header_link_color, pp_og_image, pp_og_site_name, pp_og_default_description, pp_twitter_card) or WP_Error |
| `pp_update_composition($post_id, $composition, $expected_version = null)` | Writes composition array to post meta (handles JSON serialization) and bumps the freshness marker under a per-post lock. Optional `$expected_version` (#13) does a write-time compare-and-swap: if the current version differs it returns a `composition_conflict` WP_Error and writes nothing. Null skips the CAS. Returns true\|WP_Error |
| `pp_update_page_title($post_id, $title)` | Updates page title. Returns true\|WP_Error |
| `pp_update_page_slug($post_id, $slug)` | Updates page slug/permalink (#134). Sanitizes via sanitize_title(); WordPress de-duplicates on collision. Returns the actual resulting slug\|WP_Error |
| `pp_get_seo_meta($post_id)`   | Returns `{meta_description, seo_title, canonical_url, og_title, twitter_title}` for a page (empty strings if unset) |
| `pp_update_seo_meta($post_id, $meta)` | Shallow-merges page-specific SEO metadata (#41, #468). Validates canonical_url as a URL, length-caps meta_description (320) and seo_title/og_title/twitter_title (200). Returns true\|WP_Error |
| `pp_create_page($title, $status, $slug)` | Creates page with Composition template. Optional `$slug` (#134) sets the route up front. Returns post ID\|WP_Error |
| `pp_publish_page($post_id)`    | Sets post_status to 'publish'. Returns true\|WP_Error |
| `pp_update_site_option($key, $value)` | Updates whitelisted option. Returns true\|WP_Error |
| `pp_get_style_slots($component_name)` | Returns style_slots from component's schema.json. Returns `[]` for unknown components |
| `pp_get_style_recipes($component_name)` | Returns recipes from component's schema.json. Returns `[]` for unknown components |
| `pp_render_style_vars($style, $component_name)` | Validates style slots against schema, returns CSS custom property string for inline style attribute |
| `pp_token_families()` | Returns token family definitions: base token to derived tokens with mix ratios |
| `pp_derive_family_tokens($base_token, $value)` | Derives related tokens from a base token value (e.g., accent to hover/strong/border/surface) |
| `pp_masked_derived_overrides($base_token, $value)` | Shared #386 divergence engine: returns `stale_warnings` entries for existing derived-family overrides that diverge from the value the base would derive (masking the base change). Coherent overrides (equal to the derivable value) are not reported; mere presence is not staleness. Reused by the `update_design_token` apply result and the INSPECT smell |
| `pp_detect_masked_derived_smells()` | Walks every token family over its current base value and returns `masked_derived_override` INSPECT smells for divergent derived overrides — the same condition as the apply warning, surfaced as `token_smells` in `pp_inspect_site()` |
| `_pp_colors_equivalent($a, $b)` | True when two color values resolve to the same RGB (normalizes hex shorthand/case); backs divergence detection so a re-derived override compares equal to its stored form |
| `pp_post_apply_validate($post_id, $target)` | Validates rendered page after apply: DOM inspection for broken images, empty content, missing components. Also reachable outside the chat via `wp pp validate page --post_id=N` (issue 77) |
| `pp_get_font_urls()` | Returns array of custom font URLs from `pp_font_urls` option |
| `pp_set_font_urls($urls)` | Writes font URL array to `pp_font_urls` option |

### Apply layer (lib/apply.php)

| Function | Description |
|----------|-------------|
| `pp_register_apply($name, $def)` | Registers a mutation (file-based or option-based). |
| `pp_get_registered_applies()` | Returns all registered applies. |
| `pp_get_apply($name)` | Returns a single apply definition, or null. |
| `pp_validate_apply($name, $params)` | Validates params (structural + semantic). Returns true\|WP_Error. |
| `pp_preview_apply($name, $params)` | Validates and returns before/after diff without writing. |
| `pp_execute_apply($name, $params)` | Validates and applies mutation. |

**Registered applies:**

| Name | Domain | Target | Params |
|------|--------|--------|--------|
| `update_design_token` | design | option: `pp_token_overrides` | token (string, required), value (string, required) |
| `reset_design_token` | design | option: `pp_token_overrides` | token (string, required) |
| `reset_all_design_tokens` | design | option: `pp_token_overrides` | (none) |
| `enqueue_font` | design | option: `pp_font_urls` (+ `pp_token_overrides` when `apply_to` given) | url (string, required, HTTPS only, max 5 fonts), family (string, optional), apply_to (string, optional: `heading`\|`body`\|`both`) |
| `remove_font` | design | option: `pp_font_urls` | url (string, required) |
| `reset_fonts` | design | option: `pp_font_urls` | (none) |
| `import_media` | media | media library (new attachment) | EITHER url (string, HTTPS + image extension only) OR file (string, server-local absolute path) — exactly one; alt (string, optional) |

**Media import (`import_media`):** Brings an image into the media library as a locally-owned asset — the only sanctioned way, since image props otherwise only accept a raw URL string. Source is EITHER a remote `url` OR a local `file` (exactly one; mutually exclusive). Both are restricted beyond WordPress's default upload mime allowlist to images only (jpg/png/gif/webp) with a 10MB size cap, and both return `{attachment_id, url, action}` — feed the returned `url` (or `attachment_id`) to `image_url`/`background_image`/`logo_url`/`image_id`, or the id to attachment-ID-only site options (`pp_logo_id`, `site_icon`, `pp_og_image`).

- **`url`** — sideloads a remote HTTPS image. SSRF safety comes from WordPress core's `wp_safe_remote_get()` (used internally by `download_url()`), which validates the URL and every redirect hop against private/reserved IP ranges — not reimplemented here. `action` is `"import"` for a new attachment or `"reused"` when the same source URL was already imported (dedupe by exact source URL, #298), so retries don't accrete duplicates.
- **`file`** (#490) — reads a **server-local absolute path** (by design: the operator CLI already runs with admin rights) so brand-kit assets that live on the operator machine, not a public URL, still flow through the typed, journalled action layer instead of raw `wp media import`. The path is resolved with `realpath()` (symlinks followed) before validation; the file must be a **genuine image** whose bytes, WordPress filetype detection, and extension all agree (a non-image named `.png`, or a JPEG wearing a `.png` extension, is rejected). The source file is **copied** and the copy sideloaded, so the operator's original is never moved or deleted. `action` is always `"import"` (no dedupe). Like the URL path, the attachment is additive — a batch rollback keeps it.

**Enqueuing a font alone does not change any visible typography (issue 135).** The theme's real typography levers are the `--font-heading`/`--font-body` design tokens (not `--font-family-*`) — loading a `<link>` for a webfont stylesheet has no effect until something points a token at the family it defines. Pass `family` (the CSS font-family name the stylesheet defines) with `apply_to` to `enqueue_font` and it sets the matching token(s) to `"{family}, system-ui, sans-serif"` in the same call — "use Poppins for headings" is one `enqueue_font` call, not `enqueue_font` + a separate `update_design_token`. Omit `family` and the result still returns a best-effort `family`/`family_source: "derived"` suggestion parsed from the URL's `family=` query param (Google/Bunny Fonts convention), without writing any token — useful for confirming the guess before a follow-up call. `apply_to` without any resolvable family (explicit or derivable) is a validation error (`missing_family`), not a silent no-op.

**CLI:** `wp pp apply list\|preview\|execute\|restore\|reset` (requires manage_options capability). `restore` rolls a run's token changes back to its pre-apply snapshot; `reset` clears overrides to product defaults.

---

## Page templates

| Template file              | Root loader         | WP Admin template name | Composition-aware? |
|----------------------------|---------------------|------------------------|--------------------|
| templates/front-page.php   | front-page.php      | (set as front page)    | ✅ Yes             |
| templates/composition.php  | composition.php     | Composition            | ✅ Yes             |
| templates/page.php         | page.php            | Default Template       | No                 |
| templates/single.php       | single.php          | (automatic for posts)  | No                 |
| templates/archive.php      | archive.php         | (automatic for category/tag/date/author archives) | No |
| templates/home.php         | home.php            | (automatic for the posts index, is_home) | No   |
| templates/search.php       | search.php          | (automatic for search requests, `?s=`) | No     |

`home.php`/`archive.php`/`search.php` iterate `pp_main_query()` (the request's real main query, already correctly filtered for whichever archive/index/search this is) via `pp_the_loop()`, and render `pp_pagination()` under the grid when there's more than one page (#126). `search.php` additionally uses `pp_search_query()`/`pp_result_count()` for its heading and empty state (issue 138).

Both `front-page.php` and `composition.php` read `_pp_composition` post meta and render
components via `pp_composition()`. No page using these templates has hardcoded component structure.

The homepage has no special editing paradigm — it uses the same JSON composition system
as any other page. Its initial composition is seeded in `_pp_composition` (post ID 4).

---

## WordPress fields (ACF)

`pp_field()` is available for use in templates and components as an ACF wrapper.
No core templates currently use it — the front page content is stored in `_pp_composition`,
not ACF fields. `pp_field()` returns null when ACF is not installed.

---

## Design tokens

A single layer of CSS custom properties controls the entire visual system. Product defaults live in `assets/css/base.css`. Site-specific overrides are stored in the `pp_token_overrides` database option and output as inline CSS after the base stylesheet (CSS cascade resolves precedence automatically).

To change a token: use `pp_execute_apply('update_design_token', ['token' => '...', 'value' => '...'])`. To revert: use `reset_design_token` or `reset_all_design_tokens`.

```
Colors:     --color-bg, --color-surface, --color-text, --color-muted,
            --color-border, --color-accent, --color-accent-hover, --color-bg-inverted
Derived:    --color-text-secondary, --color-accent-strong, --color-border-accent, --color-surface-accent,
            --color-accent-on-inverted, --color-accent-on-inverted-hover,
            --color-accent-on-overlay, --color-accent-on-overlay-hover
Roles:      --color-muted-on-overlay  (de-emphasised body ink on a background_image band)
Spacing:    --space-xs, --space-sm, --space-md, --space-lg, --space-xl, --space-2xl, --space-3xl
Typography: --font-body, --font-heading, --font-weight-heading, --line-height-body, --line-height-heading,
            --letter-spacing-heading, --font-mono
Button:     --btn-padding-y, --btn-padding-x, --btn-radius,
            --btn-bg, --btn-text, --btn-border-color, --btn-shadow,
            --btn-hover-bg, --btn-hover-border-color
Shape:      --radius, --max-width, --measure-body, --measure-body-wide, --measure-centered,
            --measure-heading, --transition, --overlay-bg
Elevation:  --shadow-none, --shadow-sm, --shadow-md, --shadow-lg
Text roles: --text-kicker-color, --text-kicker-size, --text-kicker-weight, --text-kicker-spacing,
            --text-label-size, --text-label-weight, --text-label-spacing,
            --text-meta-size, --text-meta-color
```

> Source of truth: the `:root` block in `assets/css/base.css`, parsed by `pp_design_tokens()`.
> Every token above is overridable via `update_design_token`. Regenerate this list from `base.css` rather than editing it by hand — `--breakpoint-*` live in a comment and are **not** overridable.

**Token families:** Changing a base token (`--color-accent` or `--color-text`) auto-derives related tokens (hover, strong, border-accent, surface-accent, on-inverted + on-inverted-hover, on-overlay + on-overlay-hover, text-secondary) when they have no existing override. Existing overrides are always preserved — a base change never touches them (#386). Because a preserved override keeps winning in the rendered CSS, a base change can succeed (`ok:true`) yet not be visible where that override applies. To surface this, the apply returns a `stale_warnings` entry for any preserved derived override that DIVERGES from the value the new base would derive (equal-to-derivable overrides are coherent and not flagged), and the same condition is reported at INSPECT as a `masked_derived_override` token smell. `pp_masked_derived_overrides()` is the one shared engine behind both surfaces.

Token overrides survive theme updates — `base.css` is overwritten on update, but overrides persist in the database. See `ai-instructions/retheme.md` for the full retheme workflow.

---

## Style slots (per-instance styling)

Style slots allow per-instance visual customization of components without CSS edits. Each component declares allowed CSS custom properties in its `schema.json` under `styling.style_slots`. Only declared slots are accepted — arbitrary CSS is rejected.

**261 style slots** across 10 components: hero (49), section (47), cta (40), grid (38), testimonials (27), faq (21), stats (17), embed (8), logos (8), table (6).

**How it works:**
1. Composition entries gain an optional `style` key alongside `props`
2. The `style_component` action patches style slots on a specific component instance
3. Component PHP outputs style overrides as CSS custom properties on the wrapper element
4. `components.css` uses fallback pattern: `var(--hero-padding-top, var(--space-xl))` — no override = global token fires

**Example composition entry with style:**
```json
{
  "component": "hero",
  "props": { "id": "pp-a1b2c3d4", "title": "Welcome" },
  "style": { "--hero-padding-top": "8rem", "--hero-bg": "#1a1a2e", "--hero-heading-color": "#f0f0f0" }
}
```

**Style recipes:** Named shorthand stored in `schema.json` under `styling.recipes`. Recipes expand into explicit slot values. Use via `style_component` action with `recipe` param. Explicit `style` overrides recipe values. Recipes are inspectable: `inspect-composition` shows active recipe + overrides.

**CLI:**
```bash
# Apply style slots to a component
wp pp action execute style_component --run-id=<uuid> --params='{"post_id":19,"component_id":"pp-a1b2c3d4","style":{"--hero-bg":"#1a1a2e","--hero-padding-top":"8rem"}}'

# Apply a recipe + override
wp pp action execute style_component --run-id=<uuid> --params='{"post_id":19,"component_id":"pp-a1b2c3d4","recipe":"dark-spacious","style":{"--hero-heading-size":"clamp(3rem, 6vw, 5rem)"}}'

# Inspect available slots and recipes per component
wp pp operate inspect-composition 19
```

**Helpers in lib/wp.php:**
- `pp_get_style_slots(string $component_name): array` — reads style_slots from schema
- `pp_get_style_recipes(string $component_name): array` — reads recipes from schema
- `pp_render_style_vars(array $style, string $component_name): string` — validates + renders CSS custom property string

---

## Composition model

Pages using the **Composition** template store their layout in `_pp_composition` post meta.

**Format:** JSON array of component objects.

```json
[
  { "component": "hero", "props": { "id": "top", "title": "Welcome", "layout": "cover", "image_url": "/path/to/bg.jpg" }, "style": { "--hero-padding-top": "8rem", "--hero-bg": "#1a1a2e" } },
  { "component": "section", "props": { "id": "about", "body": "<p>Content here.</p>", "layout": "text-only" } },
  { "component": "stats", "props": { "theme": "muted", "items": [{ "number": "50+", "label": "Clients" }] } },
  { "component": "cta", "props": { "title": "Go", "button_text": "Click", "button_url": "/", "theme": "inverted" } }
]
```

**Note:** The `style` key is optional. Components without it render using global token defaults. Style overrides are per-instance and stored alongside props in the composition.

**Rules:**
- `component` must match a registered component name (a folder in `components/`)
- `props` must satisfy required props from the component's `schema.json`
- Invalid compositions are rejected on save — the DB retains the last valid value
- AI can write `_pp_composition` directly (via WP CLI or REST) — same format

**To read the composition in PHP:** use `pp_composition()` (no args — reads the current loop post) or `pp_get_composition($post_id)` (any post by ID) from `lib/wp.php`. Both return `[]` when meta is absent or invalid JSON. Off the main loop, always pass an explicit `$post_id` via `pp_get_composition()`. To tell an *absent* page apart from a *corrupted* one (undecodable JSON or a non-list shape), read through `pp_get_composition_result($post_id)` instead — the render path stays defensive (degrades to empty, never fatal), but inspect/check/validate act on its `error` (issue 144).

**To write a composition as AI (preferred):**
```bash
# Mutating actions require --run-id and a PREFLIGHT covering the target post first:
#   wp pp operate inspect  →  wp pp apply preflight --run-id=<uuid> --post_id=4  →  the action below
wp pp action execute update_composition --run-id=<uuid> --params='{"post_id":4,"composition":[{"component":"hero","props":{"title":"Hello"}}]}'
```

**Direct meta write (legacy, bypasses validation):**
```bash
wp post meta update <post_id> _pp_composition '[{"component":"hero","props":{"title":"Hello"}}]'
```

**Admin editor:** Pages with the Composition template open a full-screen workspace in WP Admin. The default view is an accordion, where each component renders as a collapsible card with typed form fields (string inputs, textareas, enum dropdowns, repeatable array sub-forms). A toolbar toggle switches to a CodeMirror JSON view with autocomplete and live preview. Both views sync to the same canonical JSON. **Serialization safety gate:** if opening a composition in the accordion would change its structure on a round-trip (parse → `buildAccordionData` → `serializeAccordionData`), the accordion is blocked and the editor stays in JSON-only mode, showing a per-component diff and a one-click "Copy as GitHub Issue" report. Saving or publishing re-checks against the server-normalized composition and restores the accordion once the round-trip is clean. The toolbar adapts to page state: draft pages show **Save Draft** and **Publish**; published pages show only **Update**. Ctrl+S is contextual — saves draft on draft pages, triggers Update on published pages.

**AJAX preview:** `wp_ajax_pp_preview_composition` (cookie auth, WP nonce)
- POST params: `post_id`, `composition` (JSON string), `nonce`
- Returns: `{ "success": true, "data": { "html": "<full-page-html>" } }` or error

**File map:**
| File                           | Purpose                                          |
|--------------------------------|--------------------------------------------------|
| `composition.php`              | WP template header (root) — do not edit          |
| `templates/composition.php`    | Composition template logic                       |
| `lib/admin.php`                | Meta box, AJAX preview, validation, component registry |
| `assets/js/pp-admin-editor.js` | Editor JS (accordion, CodeMirror, autocomplete, preview) |
| `assets/css/pp-admin-editor.css` | Editor layout and styles                       |

---

## Action model (lib/actions.php)

All mutations go through typed actions. AJAX handlers, WP-CLI, and future AI callers all use the same layer.

**Every action returns the same canonical result shape:**
```php
['ok' => bool, 'action' => string, 'scope' => string, 'target' => array, 'changes' => array, 'error' => string|null, 'error_code' => string]
```

These are the keys every action returns, not the complete set for every action. An action may add its own: `restore_composition` adds `findings` (#233). Key on what you need rather than on the exact key set.

**Execute always validates first.** Callers never need to pre-validate.

**`pp_validate_action()` also rejects bad media URLs.** Any `image_url`/`background_image` value (flat or inside `items[]`) that references the site's own Media Library must resolve to an actual attachment AND be an image — a URL to a PDF/video/audio attachment, or to no attachment at all, fails validation with `invalid_media_url` before anything is written. A same-site media reference is caught in any URL shape, not just the canonical absolute one: a site-relative `/wp-content/uploads/…` path, a protocol-relative `//host/…` URL, an `http`/`https` or default-port (`:80`/`:443`) variant, and a CDN/offloaded URL that still resolves to an attachment all get the same existence + image-type check (#153). Genuinely external URLs (a different host that resolves to no attachment) are passed through unchecked. A missing/misconfigured uploads base URL no longer disables the check — same-site-shaped paths are still validated (fail-closed). This applies uniformly to every caller — AJAX, WP-CLI, `pp_patch_composition()` — not just the AI chat surface. Because both `pp_preview_action()` and `pp_execute_action()` call `pp_validate_action()`, a proposal preview rejects an invalid media URL with the identical error `pp_ai_execute` would give — the chat never shows a clean preview diff for a step guaranteed to fail (issue 130).

**`pp_validate_action()` also enforces the composition-presence precondition (#358/#387).** Component-level actions (`add_component`, `remove_component`, `reorder_components`, `update_component`, `style_component`) operate on existing components, so they require a non-empty composition on the target page. Called on a composition-less page, they fail with `error_code: composition_required` — before any write, and via the same shared validator no matter the caller (AI chat, WP-CLI, the batch executor, `pp_patch_composition()`). Populate/lifecycle/metadata actions (`update_composition`, `trash_page`, `publish_page`, restore) opt out (`requires_composition => false`), so a page created empty by `create_page` can still be populated or deleted; site-scoped actions are exempt by scope. The check is gated on the page existing, so a nonexistent page still reports `not_found` rather than the composition error.

**No name is canonicalized on any path — what you write is what is stored, read and rendered (#603, #604).** There is no alias surface left anywhere in the composition pipeline: not for style-SLOT names (#603), not for the 13 retired PROP keys (`cta_text`, `subtitle`, `heading_align`, ...) that `pp_legacy_prop_aliases()` used to map (#604), not for the retired `variant` prop, whose read-path migration to `layout`/`theme` is also gone (#604), and not for prop VALUES — the last legacy value went in #605 and the `aliases` schema field that declared them was retired outright in #606, so a prop accepts exactly the values it advertises. Since #600 that holds for `items[]` enum fields too: an out-of-set nested enum value (today `grid.items[].text_role`) is rejected at write with `invalid_prop_value` naming the item index and the field, instead of persisting behind an `ok:true` and being coerced away at render. No declared enum in the grammar has a carve-out left. A composition read returns the stored bytes; a write stores exactly what you sent. **Consequence, and it is intended:** a composition stored before a vocabulary rename keeps the old name, that declaration is never read by a renderer (so the prop falls back to its schema default), and the actions that validate the WHOLE composition now fail on it by name with `unknown_prop` — or with a missing-required-prop error where the retired name was the only thing satisfying it. Those actions are exactly three: `create_page`, `update_composition` and `update_component`. The blast radius is deliberately uneven and worth knowing: `add_component` validates only the item it adds, and `remove_component`, `reorder_components` and `style_component` validate no props at all, so all four still succeed on a stale page and write the stale bands back verbatim. So on a page with one retired prop name, four of seven edits work and three are blocked by a band you never named. Author the canonical name. `restore_composition` is the deliberate exception and its contract is unchanged (#233): it restores the snapshot **verbatim** and **reports** violations in `findings` rather than blocking, so undo never fails and you are told which declarations are dead — each finding carrying the composition `index` of the band that owns it, so two same-type bands are distinguishable (#622). You do not have to attempt an edit to discover any of this: `wp pp check page --post_id=N` and `wp pp validate site` report the same error-severity findings on the stored page, and `validate site` exits non-zero for them (#622). A missing-required-prop error also names the undeclared prop keys the item is carrying, so the value sitting under a retired name is visible; the rename target comes from the component's schema, never from a retired-name lookup — no such lookup exists and none may be re-added. Note the reporting granularity: findings are EXHAUSTIVE per band since #621 — a band with a retired prop name AND a dead style slot AND a dead card link reports all three in one pass, so one repair pass fixes the band instead of one problem per restore. The unit is the authored location a message can name (a prop, an `items[]` entry, a nested field), so two rules judging one value report it once, and a rule whose message names only the prop (`body_items` "items must be strings") reports once for that prop rather than once per bad entry. Two limits, both deliberate: a band whose IDENTITY is unusable — no `component` key, an unknown component, site chrome — still reports that one problem and nothing else, because there is no schema to judge the rest of it against; and a `style` map reports its first dead slot only. The WRITE path is unchanged and still first-error-wins: a rejected `create_page` / `update_composition` / `update_component` returns exactly one message, which is why a missing-required-prop error also names the undeclared keys the item carries.

**Registry functions:**
- `pp_get_registered_actions()` — all 20 actions
- `pp_get_action($name)` — single action definition or null
- `pp_validate_action($name, $params)` — structural + semantic validation, returns true|WP_Error
- `pp_preview_action($name, $params)` — validates, computes diff, never writes
- `pp_execute_action($name, $params)` — validates then executes, returns canonical result
- `pp_ai_execute_batch($steps)` — executes an array of `['type','name','params']` steps atomically: snapshots every target first, rolls all of them back if any step fails (issue 137). Returns `['ok', 'steps', 'failed_at', 'rolled_back', 'rollback_errors']` — `rollback_errors` (string[]) names anything the rollback itself could not fully restore, so never treat `rolled_back: true` as a clean restore without checking it

### Actions

| Action | Scope | Params | Semantics |
|---|---|---|---|
| `create_page` | site | title (req), composition, status, slug | Create. Defaults to draft with empty composition. Optional `slug` sets the canonical route up front — omit to let WordPress derive one from the title |
| `update_site_option` | site | key (req), value (req) | Replace. Whitelisted: blogname, blogdescription, pp_logo_id / pp_footer_logo_id / site_icon (image attachment ID; site_icon = WP core favicon/app icon, renders via wp_site_icon), pp_logo_alt, pp_footer_show_logo (bool), pp_header_bg / pp_footer_bg (CSS color **or** gradient), pp_header_text / pp_header_link_color / pp_footer_text / pp_footer_link_color (CSS color), pp_footer_blurb / pp_footer_contact / pp_footer_copyright / pp_footer_menu_label / pp_footer_contact_label / pp_footer_secondary_label / pp_footer_note (text), pp_footer_social (JSON list of {network, url} from a closed network set; renders inline-SVG icon links) |
| `update_page_title` | page | post_id (req), title (req) | Replace |
| `update_page_slug` | page | post_id (req), slug (req) | Replace. Sanitized via `sanitize_title()`; WordPress de-duplicates on collision (suffixing `-2`, `-3`, ...) — `changes` always reports the actual resulting slug and permalink, which may differ from what was requested |
| `update_seo_meta` | page | post_id (req), meta (req) | **Patch.** `meta` is a map of `meta_description`/`seo_title`/`canonical_url` → value, shallow-merged into existing SEO metadata. `seo_title` overrides the rendered `<title>` tag; `canonical_url` overrides the `<link rel="canonical">` tag. Set a key to `""` to clear it |
| `update_composition` | page | post_id (req), composition (req) | Replace entire array |
| `publish_page` | page | post_id (req) | Sets status to publish. Idempotent |
| `add_component` | page | post_id (req), component (req), props (req), style, position | Append, or insert at position (0-based). Optional `style` sets per-instance style slots on the new component in the same call (item-scoped map, same rules and shared validator as composition `items[].style` — only schema-declared style slots accepted). Add a styled component in one call instead of a follow-up `style_component` |
| `remove_component` | page | post_id (req), component_index or component_id | Remove by index or id. component_id takes precedence. Auto-generated `pp-<hex8>` ids do not survive a full `update_composition` re-apply — prefer authored `id` props for durable targeting (#232) |
| `reorder_components` | page | post_id (req), order (req, int[]) | Permutation of 0..N-1. No duplicates, no gaps |
| `update_component` | section | post_id (req), component_index or component_id, props (req), style | **Patch** (not replace). Shallow merge. Unspecified props unchanged. `null` removes a prop. Optional `style` shallow-merges per-instance style slots in the same call (same slots and shared validator as `style_component` / `items[].style`). Target by `component_id` (authored `id` prop, or auto-generated `pp-<hex8>` — the latter is regenerated by a full `update_composition` re-apply, #232) or 0-based `component_index`. Validates merged result |
| `trash_page` | page | post_id (req) | Moves page to trash (reversible). Rejects already-trashed pages |
| `restore_page` | page | post_id (req) | Restores page from trash. Only works on trashed pages |
| `unpublish_page` | page | post_id (req) | Sets status back to draft. Only works on published pages |
| `clear_custom_css` | site | (none) | Removes all Custom CSS from WordPress Customizer |
| `style_component` | section | post_id (req), component_id or component_index, style, recipe | **Patch** style slots on a component instance. `style` is a map of slot name → value (or null to remove). Optional `recipe` expands named shorthand into slot values before merging explicit overrides. Only schema-declared style slots are accepted |
| `create_menu` | site | name (req) | Create. Fails if a menu with this name already exists |
| `add_menu_item` | site | menu_id (req), page_id, url, label, position | Append. Exactly one of page_id or (url + label) — a page link or a custom link. `page_id`, not `post_id`: a top-level `post_id` param signals "this action mutates that post" to the preflight gate, but here it's just data inside a site-scoped mutation. Omit position to append at the end |
| `assign_menu_location` | site | menu_id (req), location (req) | Replace. `location` must be a registered theme location (`primary`, `footer`, `footer_secondary`) |
| `set_menu` | site | name (req), items (req), location | **Declarative replace** — mirrors `update_composition`. Creates the menu by name if it doesn't exist; clears and replaces ALL its items with the given ordered list (each `{page_id}` or `{url, label}`); optionally assigns a location, all in one call |

**Auto-draft status gate (#160).** `publish_page`, `trash_page`, `update_page_slug`, and `update_seo_meta` reject a target whose `post_status` is still `auto-draft` (WordPress's hidden, self-GC'd "Add New Page" placeholder, which `pp_composition_pages()` already omits from the inventory) with `error_code: auto_draft` — save the page first. `update_composition` and `update_page_title` are exempt: they are the first-save path that promotes an auto-draft to a real `draft` (#121). `restore_page` is unaffected (trash-gated).

### WP-CLI

```bash
wp pp action list                                    # all actions with scope and params
wp pp action preview <name> --params='{"key":"val"}'  # validate + diff, never writes (no run-id)
wp pp action execute <name> --run-id=<uuid> --params='{"key":"val"}'  # mutates: needs INSPECT + a covering PREFLIGHT
wp pp check conflicts                                 # Custom CSS conflict detection
wp pp check page --post_id=42                         # composition validity + styling + smells (raw composition data, not rendered HTML)
wp pp check surface lib/wp.php                        # surface classification (safe/extension/core)
wp pp validate site                                   # full site validation battery
wp pp validate page --post_id=42                      # rendered-HTML validation (issue 77) — same service that gates the AI chat's success message; optional --component-index=N; exits non-zero on failure

# Semantic composition operator
wp pp operate inspect-composition <page>              # editable targets with selectors and current values
wp pp operate patch <page> --target=hero.subheading --value="New" --preview  # field-level diff, no write (no run-id)
wp pp operate patch <page> --target=hero.subheading --value="New" --run-id=<uuid>  # mutates: needs INSPECT + a covering PREFLIGHT

# Component ID targeting (alternative to index)
wp pp action execute update_component --run-id=<uuid> --params='{"post_id":19,"component_id":"pp-a1b2c3d4","props":{"subheading":"Via ID"}}'

# Theme integrity
wp pp integrity check                                 # compare live files against shipped manifest (exit 0/1/2/3)
wp pp integrity status                                # read stored result without file I/O
```

### Semantic selectors

The `inspect-composition` and `patch` commands use semantic selectors to target composition fields:

| Pattern | Example | Target |
|---|---|---|
| `type.field` | `hero.subheading` | Top-level field on the only component of that type |
| `type[match="val"].field` | `section[title="About"].body` | Field on a matched component |
| `type[id="..."].field` | `hero[id="home-hero"].subheading` | Field on component by id (authored ids are durable; auto-generated `pp-<hex8>` ids are regenerated on full re-apply) |
| `type[match="val"].items[match="val"].field` | `grid[title="Features"].items[title="Speed"].text` | Nested item field |

**Flow:** `inspect-composition` → identify target → `patch --preview` → `patch` (apply) → `validate`

### AJAX handler delegation

The 3 mutation AJAX handlers (`pp_save_composition`, `pp_save_title`, `pp_publish_page`) are thin HTTP adapters. They handle nonce verification, capability checks, and JSON parsing, then delegate to `pp_execute_action()`. The publish handler uses a short-circuit pattern: save composition first, publish only if save succeeds. Zero JS changes.

---

## AI Chat (lib/ai-chat.php, lib/ai-context.php, lib/ai-provider.php)

An in-admin AI chat that can read site state, answer questions, and propose/execute mutations through the action/apply contracts.

### Admin pages

| Page | Menu location | Capability | Purpose |
|------|---------------|------------|---------|
| AI Chat | PromptingPress → AI Chat | `edit_posts` | Chat interface for conversational site editing; the provider/model picker lives in-page |

There is no separate "AI Settings" page (the old `lib/ai-settings.php` was removed). LLM credentials are configured in **Settings → Connectors** (WordPress 7.0 core); the theme reads them via `wp_get_connectors()` and never stores an API key of its own.

### Provider configuration (WordPress 7.0 Connectors)

The theme consumes WP 7.0 connectors rather than holding its own keys. `pp_ai_get_configured_connectors()` reads `wp_get_connectors()`, keeps the providers PP supports, and pulls each key from the connector's own `authentication.setting_name` option.

PP recognizes three connector providers via `pp_ai_connector_providers()` (a base-URL / default-model map, since connectors don't expose endpoints):

| Provider key | Base URL | Default model |
|---|---|---|
| `anthropic` | `https://api.anthropic.com/v1/messages` | `claude-sonnet-4-6` |
| `openai` | `https://api.openai.com/v1/chat/completions` | `gpt-4o` |
| `google` | `https://generativelanguage.googleapis.com/v1beta/openai/chat/completions` | `gemini-2.5-flash` |

The user's active choice is stored in two `wp_options`:
- `pp_ai_selected_provider` — one of the configured connector keys (falls back to the first configured connector when unset/invalid)
- `pp_ai_selected_model` — model id (falls back to the provider's default; auto-corrected if it isn't in the provider's available list)

The in-page picker switches these via the `pp_ai_switch_provider` AJAX handler (nonce `pp_ai_execute`, capability `edit_posts`). Available models come from WP 7.0's `ProviderRegistry` (`pp_ai_get_connector_models()`), falling back to the hardcoded default when the registry returns nothing. `pp_ai_is_configured()` is true when at least one recognized connector has a key.

### Streaming architecture

The chat uses POST-based SSE streaming (nonce in request body, never in URL):

1. Chat JS sends `POST /wp-content/themes/promptingpress/ai-stream.php` with `{messages, nonce, page_id}`
2. `ai-stream.php` loads WordPress, verifies nonce + capability, assembles system prompt via `pp_ai_system_prompt()`
3. `pp_ai_stream_completion()` streams from the LLM via raw curl + `CURLOPT_WRITEFUNCTION`
4. Response chunks forwarded as SSE events: `data: {"content":"..."}\n\n`
5. Final event includes parsed proposal if the response contains one: `data: {"done":true,"proposal":{...}}\n\n`

**AJAX fallback:** If SSE fails, chat JS retries via `wp_ajax_pp_ai_chat` which returns the complete response as JSON. Two distinct triggers (issue 139): a rejected `fetch` (network failure), or a **first-token watchdog** — if no `data:` line arrives within 15s of the request starting, the client aborts the SSE attempt and falls back automatically, since a proxy/CDN that buffers the whole response returns HTTP 200 with no usable stream, which does not reject the fetch on its own. A "Streaming unavailable — using compatibility mode." note marks the switch.

**Stop button:** Sending creates an `AbortController` for the request; a Stop button (swapped in for Send while streaming) calls `abort()` and finalizes whatever partial text has arrived, leaving it in place with input re-enabled — an intentional stop is never treated as a failure and never triggers the AJAX fallback. A module-level `currentRequestId` counter guards every async callback (fetch `.then`/`.catch`, the fallback's response handlers) against firing into a conversation that's since been abandoned via "New Chat".

### Nonce separation

| Nonce | Scope | Used by |
|-------|-------|---------|
| `pp_ai_stream` | Read/stream | SSE endpoint, AJAX chat fallback |
| `pp_ai_execute` | Mutate | Action/apply execution from chat |

### Page targeting (issue 136)

Which page a chat conversation edits is explicit and user-controlled, never inferred silently. A `<select id="pp-ai-page-select">` in the chat header (populated from `pp_composition_pages()`) sets `activePageId` in the JS, persisted in the same localStorage entry as the conversation. `activePageId` is sent as `page_id` with every request — the AI's proposed `post_id` action params drive the actual writes, so an unresolved or wrong target here is a real correctness risk, not cosmetic.

Sending a message with no page selected is blocked client-side (no `page_id: null` request is ever sent); the chat shows an inline prompt directing the user to the selector. `ppChatDetectPageId(text, pages)` (longest-title-substring match, same heuristic as before this issue) still runs on every message, but purely as a suggestion: if it finds a candidate different from the current selection, a non-blocking "Switch to it for next message?" chip appears — clicking it updates the selector, but the message that was just sent always used whatever was explicitly selected at send time. Detection never mutates `activePageId` on its own. Every proposal card names its target page ("Target page: <Title>"), captured at request time so it can't drift if the selector changes while a response is still streaming.

### System prompt contents

Assembled by `pp_ai_system_prompt()`:
- Site identity (name, tagline, URL)
- Page inventory (titles, statuses, IDs)
- Component catalog (names + prop schemas with enum values, style slots, and recipes)
- Action signatures (names, scopes, param types)
- Apply signatures (names, domains, param types)
- Design token inventory (current effective values and types for every token)
- Style slot value rules (type-specific guidance for the LLM)
- Pre-proposal verification checklist (target correct component, confirm slot exists, confirm value is representable)
- Response format instructions (conversational vs structured proposal)

When page context is included, each component's summary shows: active recipe, overridden style slots with current values, and editable field names per component type. After the component index, an adjacency hint (#378) lists any consecutive component pair whose **resolved** background matches (per-instance `--{component}-bg` override, else the `theme` bucket — `inverted` or `muted`; image-backed and default/inherited bands are skipped), so the "two touching same-color bands" case is a structural fact instead of an inference. It points at the #377 band-fusing heuristic (zero the facing paddings/margins to close the seam); it is context-only and changes no action, prop, or validator.

### Proposal flow

When the AI proposes a mutation, it outputs structured JSON:

```json
{"proposal": true, "steps": [{"type": "action", "name": "add_component", "params": {"post_id": 4, "component": "faq", "props": {"items": []}}, "description": "Add FAQ section"}]}
```

The chat UI renders this as a proposal card. Before showing Apply, each step fetches a preview via `pp_ai_preview` — displaying before/after diffs inline. High-impact actions (`update_composition`, `reset_all_design_tokens`, `clear_custom_css`, `remove_component`) show amber warnings. Multi-step proposals (3+ steps) show a card-level warning. If preview fails for any step, Apply is disabled and the error card shows guided recovery: a plain-language explanation, cross-component hints when a slot exists on a different component, and expandable technical details. Error steps are visually classified as impossible (grey) or fixable (amber).

On Apply, all of a proposal's steps execute in a single request to `wp_ajax_pp_ai_execute_batch`, which delegates to `pp_ai_execute_batch()` (issue 137) — atomic: it snapshots every target any step could touch before running anything — posts, site options, Custom CSS, token overrides, font URLs, and (when any step is a menu action) the full nav-menu state including items and location assignments — then rolls every snapshot back if any step fails partway through, so a failure never leaves the page half-mutated the way independent per-step calls could. The one deliberate exception: an attachment `import_media` created is kept (additive, non-destructive), never deleted by a rollback. It deliberately does not pre-validate later steps against the projected effect of earlier ones (many real proposals are intentionally interdependent, e.g. "add a component, then style it," and a step's semantic validation legitimately depends on state an earlier step in the same batch will create) — each step is still fully validated against the state that actually exists at the moment it runs, exactly as `pp_execute_action()`/`pp_execute_apply()` already do. `pp_post_apply_validate()` runs after each successful post-scoped step (inspecting the rendered page via DOMDocument: images, background-image URLs, link hrefs, component render count), matching the pre-batch per-step behavior. The single-step `wp_ajax_pp_ai_execute`/`pp_execute_action()`/`pp_execute_apply()` path still exists and is used directly by WP-CLI, `pp_patch_composition()`, and the post-apply card's "Reset to default" shortcut.

**Per-action/apply capability model.** `wp_ajax_pp_ai_preview`, `wp_ajax_pp_ai_execute`, and `wp_ajax_pp_ai_execute_batch` all gate on `edit_posts` first (the menu-level bar), then on the real requirement for the specific action/apply being invoked, resolved by `_pp_required_caps_for()`: all applies require `manage_options`; site-scoped actions (`update_site_option`, `clear_custom_css`) require `manage_options`; the menu actions (`create_menu`/`add_menu_item`/`assign_menu_location`/`set_menu`) require `edit_theme_options`, mirroring Appearance → Menus; `create_page` requires `publish_pages` (not `manage_options`, so Editors can still build pages via chat); `publish_page`/`unpublish_page` require `edit_post` on the target post *and* `publish_pages`; `trash_page`/`restore_page` require `delete_post` on the target post; every other page/section-scoped action requires `edit_post` on the target post. For a batch, every step's capability is checked up front, before any step executes — a Contributor-level permission gap on step 3 blocks the whole batch, not just that step (capability requirements never depend on an earlier step's effect, so this can't false-positive-reject a legitimately interdependent step). Composition writes also carry a mandatory, fail-closed CAS baseline (#404): the chat captures each page's `composition_version` when the model reads it, and `wp_ajax_pp_ai_execute` / `wp_ajax_pp_ai_execute_batch` reject a composition-mutating write (or the whole batch, before any step runs) that lacks a baseline (`missing_expected_version`); a batch threads a per-`post_id` baseline map and chains each write's post-write version into the next mutating step on that page, so it never false-conflicts against its own writes. On `composition_conflict` the handler returns the structured envelope and the UI offers **Re-read & re-preview**, never a blind retry. A Contributor (has `edit_posts` but no page capabilities) reaches the chat but every mutating step returns `{"success":false,"data":"Permission denied."}` and mutates nothing. If an action's target post can't be resolved (missing/malformed `post_id`) or the action/scope isn't recognized, the check fails closed to `manage_options` rather than skipping. Validation results appear in the card as green (passed), amber (warnings), or red (errors). The card also shows per-step confirmation, a "View Page" link to the affected page, and (for single-step `update_design_token`) a "Reset to default" shortcut. Design token applies show `stale_warnings` (#386) when an existing derived-family override diverges from the value the new base would derive and therefore masks the base change. Applied changes are injected back into the conversation context so the AI knows about its own mutations.

### Context functions

| Function | Purpose |
|----------|---------|
| `pp_ai_system_prompt()` | Assembles complete system prompt |
| `pp_ai_page_context($post_id)` | Returns composition + metadata for a specific page |
| `pp_ai_media_inventory($limit)` | Returns recent media attachments (id, filename, url, alt, mime, dimensions) |
| `pp_ai_site_context()` | Bundles all site context into a single array |
| `pp_ai_format_messages($system, $conversation, $page_id)` | Formats for OpenAI chat completions API |
| `pp_ai_condense_schema($schema)` | Condenses component schema to compact string |
| `pp_ai_format_params($params)` | Formats action/apply params to compact string |
| `pp_ai_stream_completion($messages, $on_chunk)` | Streams chat completion from configured provider |
| `pp_ai_completion($messages)` | Non-streaming completion (AJAX fallback) |
| `pp_ai_parse_proposal($response)` | Parses response for action proposals |
| `pp_ai_validate_proposal($proposal)` | Validates proposal against registered capabilities |
| `pp_ai_is_configured()` | Returns true if at least one recognized WP 7.0 connector has an API key |
| `pp_ai_get_config()` | Returns active config array (provider, base_url, api_key, model) resolved from connectors + selection options |
| `pp_ai_connector_providers()` | Hardcoded provider→(base_url, default_model, default_name) map for the supported connector providers |
| `pp_ai_get_configured_connectors()` | Filters `wp_get_connectors()` to supported providers that have an API key set |
| `pp_ai_get_connector_models($provider_id)` | Queries WP 7.0 `ProviderRegistry` for text-generation models of a provider |
| `pp_ai_get_provider_models($provider_id)` | Models for a provider, falling back to the hardcoded default when the registry is empty |
| `pp_ai_parse_error_response($code, $body)` | Parses an HTTP error into a user-facing message with a "Settings → Connectors" hint |
| `_pp_build_friendly_error(WP_Error $error, array $params)` | Structured error builder returning `{error_code, user_message, alternatives, cross_component_hints, raw_error}`, plus `unknown_slots_unscanned` (int) on the `invalid_style_slot` path when the cross-component scan hit its bound. Reflected names and `raw_error` are length-bounded (`PP_REFLECTED_NAME_MAX` / `PP_REFLECTED_ERROR_MAX`) and stripped of control and format characters |
| (cross-component slot search) | Inline logic in `_pp_build_friendly_error()` that searches all registered components for slots matching invalid names. Produces `cross_component_hints` in the error response. Examines at most `PP_CROSS_COMPONENT_HINT_MAX` unknown slot keys; when more arrive, the remainder is left unexamined and counted in `unknown_slots_unscanned` |
| `_pp_suggest_alternative_value(string $type, string $description, string $default)` | CSS keyword detection with contextual alternative suggestions. Returns suggestion string or null |
| `_pp_required_caps_for(string $type, string $name, array $params)` | Resolves the WP capability requirement(s) for an action/apply invocation (see "Per-action/apply capability model" above). Returns `[['cap' => string, 'post_id' => ?int], ...]` |
| `_pp_user_meets_required_caps(array $required)` | Checks the current user against every requirement from `_pp_required_caps_for()` (AND semantics) |

### Conversation persistence

Messages persist in localStorage keyed per site **and per WP user** (`pp_ai_chat_{siteUrl}_{currentUserId}`), so two admins sharing an OS/browser profile never read each other's history (issue 157). `currentUserId` comes from the `ppAiChat` `wp_localize_script` payload; `wp_localize_script` casts it to a string, so the JS validates it as a positive decimal (`/^[1-9]\d*$/` + `Number.isSafeInteger`). If it's missing or invalid — a broken localized-config contract on the `edit_posts`-gated page — persistence **fails closed**: `saveState`/`loadState`/`clearState` become no-ops (conversation stays in memory for that page load only) and a `console.warn` fires, rather than falling back to a shared bucket. The legacy unscoped `pp_ai_chat_{siteUrl}` key is removed on load (one-time, so prior single-key history is intentionally discarded). Survives page reload. "New Chat" button clears state. Internal messages (apply confirmations) are stored in the conversation for AI context but hidden in the display. Concurrent chat tabs for the same site+user share one key and can overwrite each other's persisted context (single-active-tab assumption; multi-tab reconciliation deferred to issue 205).
