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
| `social`    | string | No       | —          | Social-icon row under the brand blurb. Set via the `pp_footer_social` site option — a JSON string of `{network, url}` objects. Not page-authored (rejected since #223); see the `pp_footer_social` notes below |

## Styling: the UDC roles

The footer is styled through the **`footer` entry of the `pp_site_udc` site option**, in
exactly the shape a band's `udc` map takes — same engine, same grammar, same groups,
same `@token` references, same breakpoint and `:hover` / `:focus-visible` / `:active`
state maps, same presets.

This replaced three colour options (`pp_footer_bg`, `pp_footer_text`,
`pp_footer_link_color`), which are gone. Their problem was reach: `--footer-text` painted
the blurb, the contact block, the copyright, every column heading and the bottom-bar note,
with two different fallbacks among them, and `--footer-link-color` painted the menu links,
the social row and the contact block's `mailto:`/`tel:` links. Each of those is its own
role now.

| Role | Selector | What it is |
|------|----------|------------|
| _band | the `<footer>` itself | the footer band — set the dark or gradient fill here |
| inner | `.site-footer__inner` | the inner container: the footer's width and padding |
| columns | `.site-footer__columns` | the column row |
| brand | `.site-footer__brand` | the brand column (logo/wordmark plus blurb) |
| blurb | `.site-footer__blurb` | the short brand paragraph |
| heading | `.site-footer__heading` | every optional column heading |
| link | `.site-footer__nav ul li a` | footer menu links, both columns |
| social-link | `.site-footer__social-link` | the social icon links |
| address | `.site-footer__address` | the contact block |
| address-link | `.site-footer__address a` | the `mailto:` / `tel:` links inside it |
| copyright | `.site-footer__copyright` | the copyright line |
| bottom | `.site-footer__bottom` | the delimited bottom bar (only when a note is set) |
| note | `.site-footer__note` | the optional secondary line |

Hover, focus and the active state are ordinary value dimensions: put a `:hover` map
inside a role's group. The global `--color-accent` token is still the default, so an
unstyled footer is unchanged.

YOU OWN THE CONTRAST on a dark footer. Set a colour on every text role you put over the
new background — `blurb`, `heading`, `copyright`, `note`, `address`, plus the three link
roles — and check each against the fill for WCAG AA. A dark footer with one role left
un-recoloured renders dark ink on dark.

`.site-footer__blurb` is capped at `32ch`, the footer's only measure cap. The footer is a
tight dark-marketing-footer surface, not a general footer builder, so a brand blurb stays a
short descriptor; the `ch` unit keeps it short at any type size. It is a literal, not a
slot, for the same chrome-contract reason — chrome declares **zero style slots** by
ratified contract, so a stated reason is the only disposition available here, not a
second-best one. **Reopening condition:** the chrome model's own boundary (#223) moving.
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
| Dark marketing footer, text and link colours, spacing, typography | `update_site_option` with key `pp_site_udc`, setting the roles above |
| Brand blurb under the logo | `update_site_option` with key `pp_footer_blurb` (text) |
| Contact block (address/email) | `update_site_option` with key `pp_footer_contact` (text) |
| Custom copyright line | `update_site_option` with key `pp_footer_copyright` (text; empty = default line) |
| Column headings (menu / contact) | `update_site_option` with keys `pp_footer_menu_label` / `pp_footer_contact_label` (text; empty = unlabelled) |
| Delimited bottom bar with a secondary note | `update_site_option` with key `pp_footer_note` (text; when set, moves the copyright into its own band with the note opposite it) |

Colour values inside a `udc` map are hex, `rgb()`/`hsl()`, `transparent`, `currentColor`, or
an **`@token` reference** — `@color-muted`, not `var(--color-muted)`; the `--` prefix and the
`var()` wrapper are v1 style-slot syntax and are refused here. A role `background.fill` also
takes a bounded gradient, and `background.image` takes a Media Library **attachment ID** (never
a URL) with `background.overlay` for the scrim over it. Unset, the footer looks exactly as
before.

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

Background: `--color-surface`. Text: inherited. Nav-link colour: `--color-muted`.
Border top: `1px solid --color-border`. Every one of those is the resting value a
`pp_site_udc` role overrides — the footer emits no inline style attribute at all, so an
authored value wins on source order from the `pp-utilities` handle rather than by outranking
the cascade.

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
SVG; its colour is the `social-link` role. Unknown networks or non-http(s) URLs are rejected at
validation; empty/unset leaves the footer byte-identical.
