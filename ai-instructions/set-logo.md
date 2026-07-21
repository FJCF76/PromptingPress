# Set the Site Logo

Use the `update_site_option` action to set the site-wide logo through a safe surface. The logo is a **Media Library attachment ID** (`pp_logo_id`), never a raw URL. It renders in the nav automatically. The footer keeps its copyright line — see [Footer logo](#footer-logo) below.

---

## Step 1 -- Find the logo image's attachment ID

The logo must already be an **image** in the Media Library. Its attachment ID is what you set — not a URL.

- From the operating picture: `wp pp operate inspect` surfaces the Media Library inventory (filenames + attachment IDs). Pick the image you want.
- Or list images directly:

```bash
wp post list --post_type=attachment --post_mime_type=image --fields=ID,post_title
```

If the image isn't in the Media Library yet, upload it there first — this action does not import files.

---

## Step 2 -- Preview the change (no write, no run token)

Preview validates the value and shows the diff without mutating anything:

```bash
wp pp action preview update_site_option --params='{"key":"pp_logo_id","value":"109"}'
```

The value must resolve to an image attachment. A non-image attachment, a bogus ID, or a URL is rejected here with a clear message — fix it before executing.

---

## Step 3 -- Preflight (site-scoped)

Setting a site option is a site-scoped mutation, so it needs a completed INSPECT plus a covering PREFLIGHT for the run. Use the run token from `wp pp operate inspect`:

```bash
wp pp apply preflight --run-id=<uuid>
```

A site-scoped preflight (no `--post_id`) covers site actions like `update_site_option`.

---

## Step 4 -- Execute

```bash
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_logo_id","value":"109"}'
```

Optionally set explicit alt text (defaults to the attachment's own alt metadata, then the site title):

```bash
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_logo_alt","value":"Acme brand mark"}'
```

---

## Step 5 -- Verify

Load the homepage and confirm the nav renders an `<img class="nav__logo-image">` pointing at the attachment. `wp pp validate site` and post-apply output also confirm the change landed.

---

## How the logo resolves

The nav and footer share one resolver. It picks the first source that yields an image, then falls back to text:

1. an explicit `logo_id` prop on the component (the base template never passes one),
2. the `pp_logo_id` site option (what you set above),
3. WordPress' native `custom_logo` theme-mod (Appearance → Customize → Logo),
4. the text wordmark (`logo_text`, defaulting to the site title).

So if you never set `pp_logo_id`, a logo set through the WordPress Customizer still shows. If nothing resolves to an image, the nav shows the site name as text.

`wp pp apply preflight` warns (`nav_readiness`) when `pp_logo_id` is set to an attachment that is not an image — the resolver silently falls through to the wordmark, so without the warning you would see no logo and no explanation.

---

## Footer logo

The footer logo is **off by default**. Turn it on with the `pp_footer_show_logo` site option (`update_site_option`), the same safe surface used for `pp_logo_id`:

```bash
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_show_logo","value":"true"}'
```

The value is a boolean — `1`, `0`, `true`, or `false`. When on, the footer resolves the same logo as the header (`pp_logo_id` → `custom_logo` theme-mod → text wordmark). When off, the footer omits the logo but still renders its menu, copyright line, and any blurb/contact you set (see the dark marketing footer section below).

`footer.show_logo` is a prop of the template-owned `footer` component; since #223 you cannot pass it by composing a `footer` (the write is rejected with `template_owned_component`, and every validator flags the page). The site option is the only supported surface. `pp_logo_id` still sets the **header** logo independently.

---

## Dark marketing footer (background, text, blurb, contact, copyright)

The footer is template-owned (#223) with no composition style slots, so a dark marketing footer is built through site options too (#300), the same `update_site_option` safe surface. All are optional; unset, the footer looks exactly as before.

```bash
# Dark band with light text and a brand blurb
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_bg","value":"#1a1a2e"}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_text","value":"#e8e8f0"}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_link_color","value":"#c8c8e0"}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_blurb","value":"Ship credible sites in an afternoon."}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_contact","value":"hello@example.com\nSan Francisco, CA"}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_copyright","value":"© 2026 Example Inc. Beta."}'
```

`pp_footer_text` and `pp_footer_link_color` accept the same values as any style-slot color: hex, `rgb()`/`hsl()`, `transparent`, `currentColor`, or a single known color-token reference like `var(--color-accent)`. `pp_footer_bg` accepts all of those **and** a gradient (see below). They are validated by the shared engines (the same ones style slots use) and rendered as inline `--footer-*` custom properties. `pp_footer_text` colors the blurb, contact block, and copyright line. `pp_footer_copyright` replaces the default `© <year> <site title>. All rights reserved.` line verbatim, so include the year yourself; leave it empty to keep the default. This is a tight dark-footer surface, not a general footer builder.

---

## Footer structure (column headings, bottom bar, logo override)

The dark footer above is a run of blurb → menu → contact → copyright. To organise it — labelled columns and a delimited bottom bar — set these optional structure options (#335), still on the same `update_site_option` surface, still not a footer builder. All optional; every one empty leaves the footer exactly as the dark-footer section produces it.

```bash
# Label the menu and contact columns
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_menu_label","value":"Legal"}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_contact_label","value":"Contact"}'
# Move the copyright into a delimited bottom bar with a secondary note opposite it
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_note","value":"Made with care."}'
# Use a light logo variant on the dark footer (header logo stays pp_logo_id)
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_logo_id","value":"57"}'
```

- `pp_footer_menu_label` / `pp_footer_contact_label` render a heading above the footer nav menu and the contact block. Empty = no heading (the unlabelled columns of #300). The contact heading only shows when `pp_footer_contact` is also set.
- `pp_footer_note` is the bottom-bar trigger: when NON-EMPTY, the copyright moves out of the main flow into its own delimited band (a top border) and the note renders opposite it. Empty leaves the copyright inline exactly as #300 did. Newlines become line breaks.
- A SECOND footer menu column (#469) renders when you assign a menu to the `footer_secondary` theme location (`assign_menu_location` / `set_menu`, e.g. a distinct Legal column of Aviso legal / Privacidad / Cookies links). `pp_footer_secondary_label` is its optional heading (empty = a headless column, same rule as `pp_footer_menu_label`). With no menu assigned to `footer_secondary`, the footer is byte-identical to the single-menu layout.
- `pp_footer_logo_id` overrides the footer logo only (an image attachment ID, never a URL — same rule as `pp_logo_id`). Because `pp_logo_id` feeds both the light header and the dark footer, a dark brand mark is invisible on a dark footer; set a light variant here while `pp_logo_id` stays the header logo. Unset falls back to `pp_logo_id`. Requires `pp_footer_show_logo` on to appear.

---

## Dark / gradient header (background, text, link color)

The header is template-owned (#223) exactly like the footer, so it has no composition style slots either — and before #333 it had no styling surface at all. Its background, text, and link colors are now set through the same `update_site_option` surface. All are optional; unset, the header looks exactly as before.

```bash
# Dark header with a subtle gradient and light links
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_header_bg","value":"linear-gradient(135deg, #1a1a2e, #16121f)"}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_header_text","value":"#e8e8f0"}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_header_link_color","value":"#c8c8e0"}'
```

`pp_header_text` colors the logo wordmark and the mobile hamburger toggle; `pp_header_link_color` colors the nav links, including the active/current link (#355 — it follows `pp_header_link_color` and only falls back to `--color-accent` when you leave the link color unset; the current item keeps its bold weight either way). Hover keeps `--color-accent` — that is a global design token, so change it with `update_design_token` if the accent needs to suit a dark header. Style the header to match the SITE's real header, not the hero: a dark hero is not a reason to make the header dark. Layout, sticky behavior, and menu structure are not configurable here: this is a color surface, not a header builder.

## Gradients on the background options

`pp_header_bg` and `pp_footer_bg` are the only two chrome options that accept a **gradient** as well as a plain color. Both go through the shared `gradient` slot type:

- Accepted: any CSS color (hex, `rgb()`/`rgba()`, `hsl()`/`hsla()`, `transparent`, `currentColor`, a bare `var(--token)` reference to a registered color token), **or** a bounded `linear-gradient()` / `radial-gradient()` with 2 or more color stops — e.g. `linear-gradient(135deg, #1a1a2e, #16121f)`, `radial-gradient(circle at top left, #2a2a4e, #16121f)`.
- Rejected: `conic-gradient()`, `repeating-linear-gradient()`, `repeating-radial-gradient()`, and any `var()` / `url()` / `env()` **inside** a gradient function.
- The four text/link options (`pp_header_text`, `pp_header_link_color`, `pp_footer_text`, `pp_footer_link_color`) take a plain color only — a gradient on those is rejected.

---

## Favicon / app icon (`site_icon`)

The browser-tab favicon and app/OS icon are set through the same `update_site_option` safe surface, using WordPress core's own `site_icon` option (#414). Like `pp_logo_id`, the value is a **Media Library image attachment ID**, never a URL, and it is validated by the same image-attachment rule. Once set, WordPress core's `wp_site_icon()` hook emits the `<link rel="icon">` and apple-touch-icon tags in `wp_head` automatically — there is no favicon slot to compose and no template edit to make.

```bash
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"site_icon","value":"142"}'
```

Setting it through this action renders the attachment as-is: the Customizer's square-crop step does not run on a direct option write, so pass a roughly **square source, ideally >=512px**, for a clean icon across the tab, home-screen, and app-icon sizes. Any image is accepted (no hard square/size rejection); a non-image attachment, a URL, or a bogus ID is rejected at preview/execute exactly like `pp_logo_id`. `site_icon` is independent of `pp_logo_id`: the favicon and the header/footer logo are separate assets set by separate keys.

---

## Whitelisted logo + header + footer options

| Key | Value | Notes |
|-----|-------|-------|
| `pp_logo_id` | Media Library attachment ID (integer) | Must be an image. Never a URL. |
| `pp_logo_alt` | string | Optional. Defaults to the attachment's alt metadata, then the site title. |
| `site_icon` | Media Library attachment ID (integer) | Optional (#414). Must be an image. Never a URL. WP core favicon / app icon; rendered as-is on a direct write (no auto-crop), so supply a square source (ideally >=512px). Renders via `wp_site_icon` in `wp_head`. |
| `pp_header_bg` | CSS color **or** gradient | Optional. Header background (`--header-bg`). The primary dark/gradient-header control. |
| `pp_header_text` | CSS color | Optional. Header text color (`--header-text`) — logo wordmark and mobile toggle. |
| `pp_header_link_color` | CSS color | Optional. Header nav-link color (`--header-link-color`). |
| `pp_footer_show_logo` | boolean (`1`/`0`/`true`/`false`) | Optional, default off. Turns the footer logo on/off. Uses the same resolved logo as the header. |
| `pp_footer_bg` | CSS color **or** gradient | Optional. Footer background (`--footer-bg`). The primary dark-footer control. |
| `pp_footer_text` | CSS color | Optional. Footer text color (`--footer-text`) — blurb, contact, copyright. |
| `pp_footer_link_color` | CSS color | Optional. Footer nav-link color (`--footer-link-color`). |
| `pp_footer_blurb` | string | Optional. Brand/description line under the footer logo. |
| `pp_footer_contact` | string | Optional. Contact/secondary text block (newlines become line breaks). |
| `pp_footer_copyright` | string | Optional. Replaces the default copyright line; empty keeps the default. |
| `pp_footer_menu_label` | string | Optional (#335). Heading above the footer nav menu. Empty = unlabelled. |
| `pp_footer_contact_label` | string | Optional (#335). Heading above the contact block (only when `pp_footer_contact` is set). |
| `pp_footer_note` | string | Optional (#335). Secondary line; when set, moves the copyright into a delimited bottom bar and renders opposite it. |
| `pp_footer_secondary_label` | string | Optional (#469). Heading above the SECOND footer menu column. Empty = a headless column. Only rendered when a menu is assigned to the `footer_secondary` theme location. |
| `pp_footer_logo_id` | Media Library attachment ID (integer) | Optional (#335). Must be an image. Never a URL. Footer logo override; unset falls back to `pp_logo_id`. |

---

## Troubleshooting

- **"requires a Media Library image attachment ID"** — the value isn't an image attachment. Confirm the ID with Step 1; a PDF/video attachment or a plain number that isn't an attachment is rejected. Never pass a URL.
- **Logo shows as text, not an image** — no source resolved to an image. Check that `pp_logo_id` is set (or a `custom_logo` theme-mod exists) and that the attachment still exists.
- **Action refused with a preflight error** — you skipped the run token flow. Run `wp pp operate inspect` for a `run_id`, then `wp pp apply preflight --run-id=<uuid>` before executing.
- **Footer logo not appearing** — it is off by default. Turn it on by setting the `pp_footer_show_logo` site option to `true` via `update_site_option`, and make sure a logo resolves (a `pp_logo_id` image or a `custom_logo` theme-mod). Do not compose a `footer` component to set `show_logo`; that write is rejected (#223).
