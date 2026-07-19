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
| `logo_text` | string | No       | —          | Logo text (falls back to site title). Not reachable from a page |
| `logo_id`   | int    | No       | —          | Media Library attachment ID for an image logo (takes priority over `logo_text`). Must be an image attachment. Not reachable from a page; use the `pp_footer_logo_id` option (footer override; falls back to `pp_logo_id`) |
| `logo_alt`  | string | No       | —          | Alt text for the image logo. Not reachable from a page |
| `bg`         | string | No | — | Footer background. Set via the `pp_footer_bg` site option → `--footer-bg`. A CSS color **or** a bounded `linear-gradient()`/`radial-gradient()` (the shared `gradient` slot type, #333) |
| `text`       | string | No | — | Footer text color (blurb/contact/copyright). Set via `pp_footer_text` → `--footer-text` |
| `link_color` | string | No | — | Footer nav-link color. Set via `pp_footer_link_color` → `--footer-link-color` |
| `blurb`      | string | No | — | Brand/description line under the logo. Set via `pp_footer_blurb` |
| `contact`    | string | No | — | Contact/secondary text block. Set via `pp_footer_contact`. Rendered inside an `<address>`; email addresses become `mailto:` links and international phone numbers (leading `+`) become `tel:` links (#427). Stays free text — non-matching text passes through unchanged |
| `copyright`  | string | No | — | Copyright line. Set via `pp_footer_copyright`; empty = the default `© <year> <site title>. All rights reserved.` |
| `menu_label`    | string | No | — | Optional heading above the footer nav menu (#335). Set via `pp_footer_menu_label`; empty = unlabelled |
| `contact_label` | string | No | — | Optional heading above the contact block (#335). Set via `pp_footer_contact_label`; only rendered when `contact` is set |
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
| Show/hide the footer logo | `update_site_option` with key `pp_footer_show_logo` (boolean) |
| Footer logo override (light variant for a dark footer) | `update_site_option` with key `pp_footer_logo_id` (image attachment ID; unset falls back to `pp_logo_id`) |
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
menu assigned, or its menu is empty.

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

The brand · nav · contact columns live in a `.site-footer__columns` grid. On desktop
(`min-width: 1024px`) it is `grid-auto-flow: column` with `grid-auto-columns: minmax(0, 1fr)`,
so it makes exactly one equal top-aligned column per **present** column and a sparse footer
degrades cleanly (no empty tracks). On mobile it collapses to a single-column stack in DOM
order (brand → nav → contact). The copyright line (or, when `pp_footer_note` is set, the
delimited bottom bar of #335) sits on its own row below the columns.

The footer menu is a real `<nav aria-label="Footer navigation">` landmark, distinct from the
header's `Main navigation`. Column headings use one consistent level (`h2.site-footer__heading`);
an unset label leaves a headless-but-styled column rather than injecting default text, keeping
the option contract byte-identical.

`.site-footer__social` is a reserved, styled landing slot for the social-icon row (#382). No
markup emits it yet — #382 adds the row and its option into this designed home.
