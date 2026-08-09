# Component: footer

Site footer. Renders a WP nav menu (by theme location) and a copyright line using the site title.

> **Site chrome — not composable.** `templates/base.php` renders `footer` on every page.
> Putting it in a page's `_pp_composition` renders the footer twice, so the write is
> rejected with the error code `template_owned_component` (issue #223).
>
> The props below are set **by the template**, not by a page.

## Props (template-supplied)

`templates/base.php` calls this component with `['location' => 'footer']` and nothing else,
so in practice only `location` is ever set.

| Prop        | Type   | Required | Default    | Description |
|-------------|--------|----------|------------|-------------|
| `location`  | string | No       | `'footer'` | WP theme location slug |
| `show_logo` | bool   | No       | `false`    | Whether to render the site logo in the footer. Not set by composing a `footer` (rejected since #223) — set the `pp_footer_show_logo` site option instead; the base template passes it in |
| `logo_text` | string | No       | —          | Logo text (falls back to site title). Not reachable from any surface; change the site title instead |
| `logo_id`   | int    | No       | —          | Media Library attachment ID for an image logo (takes priority over `logo_text`). Set via the `pp_footer_logo_id` option (footer override; falls back to `pp_logo_id`) |
| `logo_alt`  | string | No       | —          | Alt text for the image logo. Template-supplied from the `pp_logo_alt` site option (#582) — the **same** option the header uses; not page-authored, and never empty |
| `bg`         | string | No | — | Footer background. Set via the `pp_footer_bg` site option → `--footer-bg`. A CSS color **or** a bounded `linear-gradient()`/`radial-gradient()` (the shared `gradient` slot type, #333) |
| `text`       | string | No | — | Footer text color. Set via `pp_footer_text` → `--footer-text`. Reaches **every non-link text surface**: blurb, contact, copyright, column headings, bottom-bar note — see below |
| `link_color` | string | No | — | Footer link color. Set via `pp_footer_link_color` → `--footer-link-color`. Reaches **every link surface**: both menu columns, the social row, and the contact block's mailto:/tel: links — see below |

### What the footer custom properties actually reach (#582)

| Property | Surfaces it paints | Fallback when unset |
|----------|--------------------|---------------------|
| `--footer-bg` | the footer band | `--color-surface` |
| `--footer-text` | the footer body; the brand blurb; the contact block; the copyright line; the column headings (`.site-footer__heading`); the bottom-bar note (`.site-footer__note`) | `inherit`, or `--color-muted` on the copyright and note |
| `--footer-link-color` | the footer menu links (both columns); the social-icon row; the `mailto:`/`tel:` links inside the contact `<address>` | `--color-muted` |

Hover is **not** reachable from any of them: all three link surfaces hover to the global
`--color-accent` design token. Change it with `update_design_token` if it is illegible on a
dark footer — there is no per-footer hover option, and adding one would mean giving chrome a
style slot, which the chrome contract (#223) rules out.

`.site-footer__blurb` is capped at `32ch`, the footer's only measure cap. The footer is a
tight dark-marketing-footer surface, not a general footer builder, so a brand blurb stays a
short descriptor; the `ch` unit keeps it short at any type size. It is a literal, not a
slot, for the same chrome-contract reason.
| `blurb`      | string | No | — | Brand/description line under the logo. Set via `pp_footer_blurb` |
| `contact`    | string | No | — | Contact/secondary text block. Set via `pp_footer_contact`. Rendered inside an `<address>`; email addresses become `mailto:` links and international phone numbers (leading `+`) become `tel:` links (#427). Stays free text — non-matching text passes through unchanged |
| `copyright`  | string | No | — | Copyright line. Set via `pp_footer_copyright`; empty = the default `© <year> <site title>. All rights reserved.` |
| `menu_label`    | string | No | — | Optional heading above the footer nav menu (#335). Set via `pp_footer_menu_label`; empty = unlabelled |
| `contact_label` | string | No | — | Optional heading above the contact block (#335). Set via `pp_footer_contact_label`; only rendered when `contact` is set |
| `secondary_location` | string | No | — | Theme location slug for an optional SECOND footer menu column (#469). base.php sets it to `footer_secondary`. The column renders ONLY when a menu is assigned to this location; unassigned = footer unchanged. A real `<nav>` with aria-label `Footer secondary navigation` |
| `secondary_label`    | string | No | — | Optional heading above the second footer menu column (#469). Set via `pp_footer_secondary_label`; empty = a headless second column. Only rendered when a menu is assigned to `secondary_location` |
| `note`          | string | No | — | Optional secondary line (#335). Set via `pp_footer_note`; when set, the copyright moves into a delimited bottom bar and this note renders opposite it |

### Turning the footer logo on

`show_logo` defaults to `false`. Composing a `footer` to pass `true` is rejected since #223
(the base template already renders the footer as site chrome). The supported surface is the
`pp_footer_show_logo` site option (a boolean, set via `update_site_option`); `templates/base.php`
reads it and passes `show_logo` into the footer. When on, the footer resolves its logo as
`pp_footer_logo_id` → `pp_logo_id` → `custom_logo` theme-mod → text wordmark. The
`pp_footer_logo_id` override (#335) exists because `pp_logo_id` feeds both the light header and the
dark footer, so a dark brand mark is invisible on a dark footer; set a light variant with
`pp_footer_logo_id` (an image attachment ID, never a URL) while `pp_logo_id` stays the **header**
logo. Unset, the footer falls back to `pp_logo_id`.

## Usage

```php
// Called automatically from templates/base.php
pp_get_component('footer', ['location' => 'footer']);
```

## Configuring the footer

| Goal | Surface |
|------|---------|
| Build the footer menu | The menu actions: `create_menu` / `set_menu` / `add_menu_item` |
| Attach a menu to the footer | `assign_menu_location` with location `footer` |
| Add a second footer menu column (e.g. a Legal column) | `assign_menu_location` / `set_menu` with location `footer_secondary`; optional heading via `update_site_option` key `pp_footer_secondary_label` |
| Show/hide the footer logo | `update_site_option` with key `pp_footer_show_logo` (boolean) |
| Footer logo override (light variant for a dark footer) | `update_site_option` with key `pp_footer_logo_id` (image attachment ID; unset falls back to `pp_logo_id`) |
| Logo alt text | `update_site_option` with key `pp_logo_alt` (text, #582) — site-wide, shared with the header. When set it wins over the footer attachment's own alt metadata too. Empty **or whitespace-only** counts as unprovided and falls through the chain |
| Dark marketing footer (background) | `update_site_option` with key `pp_footer_bg` (a CSS color **or** a bounded gradient) |
| Footer text / link colors | `update_site_option` with keys `pp_footer_text` / `pp_footer_link_color` (CSS colors) |
| Brand blurb under the logo | `update_site_option` with key `pp_footer_blurb` (text) |
| Contact block (address/email) | `update_site_option` with key `pp_footer_contact` (text) |
| Custom copyright line | `update_site_option` with key `pp_footer_copyright` (text; empty = default line) |
| Column headings (menu / contact) | `update_site_option` with keys `pp_footer_menu_label` / `pp_footer_contact_label` (text; empty = unlabelled) |
| Delimited bottom bar with a secondary note | `update_site_option` with key `pp_footer_note` (text; when set, moves the copyright into its own band with the note opposite it) |

The color options accept the same values as any style-slot color (hex, `rgb()`/`hsl()`,
`transparent`, `currentColor`, or a known color-token reference like `var(--color-accent)`);
they are validated by the shared color engine. They render as inline `--footer-*` custom
properties, so an unset footer looks exactly as before. This is a tight dark-marketing-footer
surface (issue #300), not a general footer builder.

`wp pp apply preflight` reports a `nav_readiness` warning when the `footer` location has no
menu assigned, or its menu is empty. The optional `footer_secondary` location is diagnosed
under an inverted rule (#582): leaving it unassigned is the intended default and reports
nothing, and a healthy assigned menu reports nothing either — the one state that warns is a
menu assigned to `footer_secondary` that is **empty**, because the column then renders
nothing and nothing else would tell you.

## Setting up the footer menu

In WP Admin: Appearance → Menus → create a menu and assign it to the "Footer Navigation" location.

If no menu is assigned to the location, the nav area is empty but the footer still renders correctly (copyright line always shows).

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: footer === */`.

Background: `var(--footer-bg, --color-surface)`. Text: `var(--footer-text, inherit)`. Nav-link
color: `var(--footer-link-color, --color-muted)`. Border top: `1px solid --color-border`. The
`--footer-*` custom properties are emitted inline by the template from the `pp_footer_*` site
options; unset, every rule falls back to its original value.

### Layout and semantics (#427)

The brand · nav · secondary-nav · contact columns live in a `.site-footer__columns` grid. On
desktop (`min-width: 1024px`) it is `grid-auto-flow: column` with `grid-auto-columns: minmax(0, 1fr)`,
so it makes exactly one equal top-aligned column per **present** column and a sparse footer
degrades cleanly (no empty tracks) — the optional second menu column (#469) is just another
present child, so 3-column and 4-column footers both lay out without any CSS change. On mobile
it collapses to a single-column stack in DOM order (brand → nav → secondary nav → contact). The
copyright line (or, when `pp_footer_note` is set, the delimited bottom bar of #335) sits on its
own row below the columns.

The footer menu is a real `<nav aria-label="Footer navigation">` landmark, distinct from the
header's `Main navigation`; the optional second menu column (#469) is a sibling
`<nav aria-label="Footer secondary navigation">`. Column headings use one consistent level (`h2.site-footer__heading`);
an unset label leaves a headless-but-styled column rather than injecting default text, keeping
the option contract byte-identical.

`.site-footer__social` is the social-icon row (#382): a horizontal, wrapping row of accessible
inline-SVG icon links under the brand blurb. Set it with the `pp_footer_social` site option, a
JSON list of `{network, url}` from a closed set of known networks (x, linkedin, facebook,
instagram, youtube, github, tiktok, mastodon) whose glyphs ship inline (no icon font, no external
requests). Each link carries an `aria-label` (the network name) and a decorative `aria-hidden`
SVG; color follows `pp_footer_link_color`. Unknown networks or non-http(s) URLs are rejected at
validation; empty/unset leaves the footer byte-identical.
