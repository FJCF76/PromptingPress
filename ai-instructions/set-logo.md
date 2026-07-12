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

The value is a boolean — `1`, `0`, `true`, or `false`. When on, the footer resolves the same logo as the header (`pp_logo_id` → `custom_logo` theme-mod → text wordmark). When off, the footer shows only its copyright line.

`footer.show_logo` is a prop of the template-owned `footer` component; since #223 you cannot pass it by composing a `footer` (the write is rejected with `template_owned_component`, and every validator flags the page). The site option is the only supported surface. `pp_logo_id` still sets the **header** logo independently.

---

## Whitelisted logo options

| Key | Value | Notes |
|-----|-------|-------|
| `pp_logo_id` | Media Library attachment ID (integer) | Must be an image. Never a URL. |
| `pp_logo_alt` | string | Optional. Defaults to the attachment's alt metadata, then the site title. |
| `pp_footer_show_logo` | boolean (`1`/`0`/`true`/`false`) | Optional, default off. Turns the footer logo on/off. Uses the same resolved logo as the header. |

---

## Troubleshooting

- **"requires a Media Library image attachment ID"** — the value isn't an image attachment. Confirm the ID with Step 1; a PDF/video attachment or a plain number that isn't an attachment is rejected. Never pass a URL.
- **Logo shows as text, not an image** — no source resolved to an image. Check that `pp_logo_id` is set (or a `custom_logo` theme-mod exists) and that the attachment still exists.
- **Action refused with a preflight error** — you skipped the run token flow. Run `wp pp operate inspect` for a `run_id`, then `wp pp apply preflight --run-id=<uuid>` before executing.
- **Footer logo not appearing** — it is off by default. Turn it on by setting the `pp_footer_show_logo` site option to `true` via `update_site_option`, and make sure a logo resolves (a `pp_logo_id` image or a `custom_logo` theme-mod). Do not compose a `footer` component to set `show_logo`; that write is rejected (#223).
