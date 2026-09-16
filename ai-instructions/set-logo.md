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

Optionally override the alt text. You rarely need to: the alt is never empty, because it defaults to the attachment's own alt metadata and then to the site title. Set `pp_logo_alt` only when the logo's alt should differ from the attachment's own alt. It is one site-wide value shared by the header and footer logos (#582):

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

The alt text on the resolved image follows its own chain, in the same order of specificity:

1. the `pp_logo_alt` site option (#582) — the base template passes it into both the nav and the footer, so it is the one alt surface you can write,
2. the attachment's own alt metadata (`_wp_attachment_image_alt`),
3. the text wordmark (the site title).

The alt is therefore **never empty**, which is why `pp_logo_alt` is an override rather than a requirement: set it only when the alt should say something different from the attachment's own alt. It is site-wide — the header and footer logos share it even when the footer runs a different image via `pp_footer_logo_id`.

A value that is empty **or whitespace-only** counts as unprovided and falls through to the next hop rather than rendering. Do not write `" "` to "clear" the alt: a blank alt announces nothing to a screen reader and would suppress the attachment's own alt, leaving the site worse off than not setting the option. A real value renders verbatim, surrounding spaces included.

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

## Dark marketing footer (styling, blurb, contact, copyright)

The footer is template-owned (#223), so it is never composed. Its **styling** is the `footer` entry of the `pp_site_udc` option — the same UDC map a band takes, reaching every role the footer declares. Its **content** stays on the individual `pp_footer_*` options. All optional; unset, the footer looks exactly as before.

```bash
# Dark band with light text, through the chrome UDC container
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_site_udc","value":"{\"footer\":{\"_band\":{\"background\":{\"fill\":\"#1a1a2e\"}},\"blurb\":{\"typography\":{\"color\":\"#e8e8f0\"}},\"heading\":{\"typography\":{\"color\":\"#e8e8f0\"}},\"copyright\":{\"typography\":{\"color\":\"#c8c8e0\"}},\"link\":{\"typography\":{\"color\":\"#c8c8e0\",\":hover\":{\"color\":\"@color-accent\"}}}}}"}'

# Content stays on its own keys
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_blurb","value":"Ship credible sites in an afternoon."}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_contact","value":"hello@example.com\nSan Francisco, CA"}'
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_footer_copyright","value":"© 2026 Example Inc. Beta."}'
```

**You own the contrast.** A dark `_band` fill does not re-light anything: set a colour on every text and link role you put over it — `blurb`, `heading`, `copyright`, `note`, `address`, `link`, `address-link`, `social-link` — and check each against the fill for WCAG AA. One role left un-recoloured renders dark ink on dark, which is the most common way this goes wrong. The footer's remaining roles are layout surfaces rather than ink: `inner` (the footer's content wrapper), `columns` (the column grid), `brand` (the logo/blurb block) and `bottom` (the delimited bottom bar when `pp_footer_note` is set). Read the footer's declared roles from `wp pp schema footer`.

`pp_footer_copyright` replaces the default `© <year> <site title>. All rights reserved.` line verbatim, so include the year yourself; leave it empty to keep the default.

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

The header is template-owned (#223) exactly like the footer, so it is never composed. Its styling is the `nav` entry of the same `pp_site_udc` option. All optional; unset, the header looks exactly as before.

```bash
# Dark header with a subtle gradient and light links
wp pp action execute update_site_option --run-id=<uuid> --params='{"key":"pp_site_udc","value":"{\"nav\":{\"_band\":{\"background\":{\"fill\":\"linear-gradient(135deg, #1a1a2e, #16121f)\"}},\"logo\":{\"typography\":{\"color\":\"#e8e8f0\",\":hover\":{\"color\":\"@color-accent\"}}},\"toggle\":{\"typography\":{\"color\":\"#e8e8f0\",\":hover\":{\"color\":\"@color-accent\"}}},\"link\":{\"typography\":{\"color\":\"#c8c8e0\",\":hover\":{\"color\":\"@color-accent\"}}},\"link-current\":{\"typography\":{\"color\":\"#ffffff\"}}}}"}'
```

The header's roles are `_band`, `container`, `logo`, `logo-image`, `menu`, `submenu`, `link`, `link-current` and `toggle`. `container` is the header ROW inside the bar: `sizing.min-height` sets the row's height and `spacing.gap` the space between logo, toggle and menu (`_band` is the bar itself — its background and border). `menu` is the mobile disclosure panel and `submenu` is the desktop dropdown — separate roles, because they are separate surfaces. `link-current` is the active/current link; it keeps its bold weight, which is structural. Hover, focus and active are ordinary states inside a role's group.

Style the header to match the SITE's real header, not the hero: a dark hero is not a reason to make the header dark. Layout, sticky behavior and menu structure are not configurable here — the UDC is a design surface, not a header builder.

## Writing the container safely

`pp_site_udc` holds BOTH chrome components, and a write REPLACES the whole CHROME subtree. So send `nav` and `footer` together when both are styled, or you will drop the one you left out. The option carries a `_version`; pass it back as `expected_version` and a write that would overwrite someone else's newer edit is refused (`site_option_conflict`) instead of clobbering it — re-read, re-apply, retry.

**The row has a second tenant, and it is not yours to send.** The site's custom presets live in the same option under the engine-owned `_presets` and `_presets_version` keys, written only by `save_preset` / `delete_preset`. They are preserved automatically across every chrome write and across a `""` clear, so leaving them out never loses one — and a chrome write that CARRIES either key is refused with `invalid_option_value`. If you read the stored bytes back before editing, strip those two keys and send the rest; `_version` is the one engine-owned key you may leave in place.

**Pair every colour you set at rest with its hover (#992).** Chrome roles carry no
defaults, so a value you set at REST outranks the theme stylesheet in EVERY state —
including the hover and current-page treatments that stylesheet provides. Setting
`nav.link.typography.color` on its own flattens the accent hover AND the current-page
accent onto your one colour; setting `nav.logo` or `nav.toggle` colour on its own leaves
those controls with no hover feedback at all.

**The rule is per-role, and it applies to the roles that HAVE a built-in hover.** Each
role loses its own: colour `logo` at rest and the logo's hover goes, colour `toggle` and
the toggle's goes. `link` loses two, because the current-page accent lives on a separate
role you did not set — which is why `link` is the one that also needs `link-current`.

The roles with a built-in hover to preserve are, on the header: `logo`, `toggle`, `link`.
On the footer: `link`, `address-link`, `social-link` — the same exposure, same fix.

Roles with no hover treatment need no `":hover"`: the footer's `blurb`, `heading`,
`copyright` and `note` are static text, and `link-current` deliberately holds its colour
under the pointer rather than flickering to the hover colour — so neither example below
gives it one, and that is correct rather than an omission.

Both examples above do this correctly. Copy that shape. (`link-current` reaches every current item by itself —
WordPress adds `current-menu-item` to everything it marks current, and only ever adds
`current_page_item` or `aria-current="page"` alongside it.)

Backgrounds accept a plain colour **or** a bounded `linear-gradient()` / `radial-gradient()` with 2+ stops. `conic-gradient()`, the `repeating-*` gradients, and any `var()` / `url()` / `env()` inside a gradient function are rejected. For a background IMAGE, set `background.image` to a Media Library attachment ID and pair it with `background.overlay` — never a URL.

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
| `pp_logo_alt` | string | Optional. Overrides the alt on BOTH chrome logos (header and footer — there is no `pp_footer_logo_alt`). Unset defaults to the attachment's own alt metadata, then the site title; the alt is never empty. Empty or whitespace-only counts as unprovided and falls through the chain. |
| `site_icon` | Media Library attachment ID (integer) | Optional (#414). Must be an image. Never a URL. WP core favicon / app icon; rendered as-is on a direct write (no auto-crop), so supply a square source (ideally >=512px). Renders via `wp_site_icon` in `wp_head`. |
| `pp_site_udc` | JSON object | Optional. **All** chrome styling: `{"nav": {<udc map>}, "footer": {<udc map>}}`, each in the same shape a band's `udc` takes. Replaces the whole CHROME subtree on write, not the whole option: the site's custom presets share the row under `_presets`/`_presets_version`, are preserved automatically, and are refused if you send them. Carries a `_version` you may pass back as `expected_version`. |
| `pp_footer_show_logo` | boolean (`1`/`0`/`true`/`false`) | Optional, default off. Turns the footer logo on/off. Uses the same resolved logo as the header. |
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
