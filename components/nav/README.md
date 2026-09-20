# Component: nav

Site header with logo, primary navigation menu, a hamburger toggle for mobile, and one-level dropdown submenus. Reads a WP registered nav menu by theme location slug.

> **Site chrome — not composable.** `templates/base.php` renders `nav` on every page.
> Putting it in a page's `_pp_composition` renders the header twice, so the write is
> rejected with the error code `template_owned_component` (issue #223).
>
> The props below are set **by the template**, not by a page. To change what the header
> shows, use the surfaces in [Configuring the header](#configuring-the-header).

## Props (template-supplied)

`templates/base.php` calls this component with `location`,
and `logo_alt` (from `pp_logo_alt`, #582), so those are the props ever set in practice.
`logo_text` and `logo_id` exist because `pp_resolve_logo()` accepts them, but no supported
surface passes them — a page cannot, because a page cannot compose `nav` at all. Set the
logo through the `pp_logo_id` option instead.

| Prop         | Type   | Required | Default     | Description |
|--------------|--------|----------|-------------|-------------|
| `location`   | string | No       | `'primary'` | WP theme location slug |
| `logo_text`  | string | No       | —           | Logo text (falls back to site title). Not reachable from any surface; change the site title instead |
| `logo_id`    | int    | No       | —           | Media Library attachment ID for an image logo (takes priority over `logo_text`). Not reachable as a prop — the image-attachment rule is enforced on the `pp_logo_id` **site option**, which is the surface to use |
| `logo_alt`   | string | No       | —           | Alt text for the image logo. Template-supplied from the `pp_logo_alt` site option (#582); not page-authored. Defaults to the attachment's own alt, then the site title, and is never empty |

## Styling: the UDC roles

The header is styled through the **`nav` entry of the `pp_site_udc` site option**, in
exactly the shape a band's `udc` map takes — same engine, same grammar, same groups,
same `@token` references, same breakpoint and `:hover` / `:focus-visible` / `:active`
state maps, same presets, and the same `"_css"` raw-declaration map beside a role's
groups for a property no group owns (#1079). Chrome is validated by the band validator
itself, not a chrome-flavoured copy of it, so it gets all of this for free.

This replaced three colour options (`pp_header_bg`, `pp_header_text`,
`pp_header_link_color`), which are gone. They were the header's only styling surface
when chrome had no other one, and their limits are why the roles below exist: three
knobs reaching eight surfaces, each with its own undocumented fallback, and no hover,
no breakpoint, no state, no spacing, no typography.

| Role | Selector | What it is |
|------|----------|------------|
| _band | the `<header>` itself | the sticky header bar |
| container | `.nav__container` | the header ROW — its height (`sizing.min-height`) and the space between logo, toggle and menu (`spacing.gap`) |
| logo | `.nav__logo` | the logo link / wordmark |
| logo-image | `.nav__logo-image` | the logo `<img>`, when one resolves |
| menu | `.nav__menu` | the menu container — on phones, the disclosure panel |
| menu-list | `.nav__menu ul` | the menu LIST — the space between items (`spacing.gap`), in the mobile stack and the desktop row alike |
| submenu | `.nav__menu .sub-menu` | the desktop dropdown panel |
| submenu-toggle | `.nav__submenu-toggle` | the dropdown chevron beside a parent item |
| link | `.nav__menu ul li a` | every nav link at rest |
| link-current | `.nav__menu ul li.current-menu-item > a` | the link for the page you are on |
| toggle | `.nav__toggle` | the hamburger button (phones only) |

A role's `background` takes a colour, a bounded gradient, or a Media Library **attachment ID** via `background.image` (never a URL) with `background.overlay` for a scrim over it — so a photographic header band is expressible. Pair image and overlay whenever text sits on the picture.

`menu` and `submenu` are deliberately SEPARATE roles. One option used to paint both,
with two different fallbacks, which was impossible to reason about from the name.

Hover, focus and the active state are ordinary value dimensions now: put a `:hover`
map inside the `link` role's `typography` group. The global `--color-accent` token is
still the default for all three, so an unstyled header is unchanged.

### Every role carries the header's resting appearance (#994)

The header used to be painted by `assets/css/components.css` while its roles shipped
EMPTY defaults. That is over: the whole resting appearance is role defaults now, and
the stylesheet keeps only structure — layout, wrapper geometry, and the accessibility
affordances (`cursor`, the `transition` property list, the open chevron's rotation).

Two things follow, and both are improvements you can feel:

- **Setting a colour no longer erases the states that go with it.** Before, an
  authored value was the only unlayered declaration on the element, so it outranked
  the stylesheet's `:hover` and current-page rules and silently cancelled them
  (#992). Those treatments are role defaults now, in the same tier, so styling
  `link` at rest leaves its hover accent and the you-are-here marker intact. You can
  still override either — that is what setting `link`'s `:hover` or `link-current`
  is for — but you no longer lose them by accident.
- **A preset fills in only where a default is silent.** Role defaults out-rank
  presets (site tokens → presets → role defaults → your `udc` map), and chrome now
  has defaults where it had none. So `{"_preset": "button"}` on `link` still applies,
  but only for the parameters `link` does not default; set a value explicitly in your
  own map when you want it to win.

`submenu-toggle` is the role to reach for whenever you set `link`. The chevron is a
SIBLING of the link, not a child, so it cannot follow the link's colour on its own —
style a header dark without it and the chevron stays on the default ink against your
new background, which is invisible (#995).

### Setting a colour at rest keeps its hover (fixed, #992/#994)

There used to be a gap here worth knowing about, because you may have written around
it. An **unstyled** header kept the accent on hover and on the current page; a
**partly styled** one lost both. Chrome shipped no role defaults, so the header's
resting appearance came from `assets/css/components.css` — which sits in
`@layer pp-v1`, while your authored block is unlayered, and unlayered wins at any
specificity in EVERY state. A colour you set at rest therefore outranked the
stylesheet's `:hover` and current-page rules and silently cancelled them.

#994 fixed it structurally. Those treatments are role defaults now, so they sit in
the same unlayered tier your values do and win or lose on specificity like anything
else. Setting `link.typography.color` alone leaves the accent hover and the
current-page marker working.

Writes that paired the states anyway are still correct and still recommended — an
explicit hover is a design decision, and now it overrides a working default instead
of rescuing a broken one:

```json
{"nav": {"link":           {"typography": {"color": "#f7f8fa", ":hover": {"color": "@color-accent"}}},
         "link-current":   {"typography": {"color": "@color-accent"}},
         "logo":           {"typography": {"color": "#f7f8fa", ":hover": {"color": "@color-accent"}}},
         "toggle":         {"typography": {"color": "#f7f8fa", ":hover": {"color": "@color-accent"}}},
         "submenu-toggle": {"typography": {"color": "#f7f8fa"}}}}
```

`submenu-toggle` is the one that still needs you: the chevron is a sibling of the
link, so no default can make it follow `link`'s colour. The example above sets it —
copy that shape.

`link-current` reaches every current item on its own: WordPress adds `current-menu-item`
to everything it marks current, and only ever adds `current_page_item` or
`aria-current="page"` alongside it — so the one selector is a superset of all three.
That relationship is pinned by a test, so a future WordPress change breaks loudly
rather than silently dropping the current-page treatment.

### Stated defaults

`.nav__menu .sub-menu { min-width: 12rem }` (at 768px and up, where the dropdown exists) is
the dropdown panel's floor width. A floating panel cannot size to its own content without
jitter: rename one child item and the panel's width would jump under the cursor mid-hover.
`12rem` is the width at which a typical menu label does not wrap, so the panel holds still
while the menu changes.

It stays in the stylesheet because it is wrapper GEOMETRY — the box the panel lays out in,
not how the panel looks — which is the structural half of the §2 boundary. That is a
different reason from the one this paragraph used to give ("chrome declares zero style
slots, so a literal is the only disposition available"), and the difference matters: the
panel's whole surface is the `submenu` role now, and `submenu` permits `sizing`, so an
authored `sizing.min-width` overrides this line. Nothing here is out of reach any more.

**Which roles carry `layout` (#1084):** `container`, `logo`, `menu-list`. Exposure is a box fact — a role carries the group when its own structural CSS makes the box a flex or grid container — and this list is checked against a derivation from the stylesheet (tests/js/css-lint.test.js), in both directions, so it cannot drift from the schema or the CSS. `align-self` is NOT in this group: a box placing ITSELF is `sizing.align-self`, available on any role with `sizing`.

`menu` and `toggle` are deliberately NOT on that list. Their `display` is a visibility switch — `.nav__menu[hidden]` against the JS that opens and closes the mobile menu, and a hamburger that is `display: none` from 768px — and an authored `columns` emits a `display: grid` companion that would outrank the `hidden` attribute and pin an open menu open. The exclusion is derived from the stylesheet, not kept by hand (tests/js/css-lint.test.js).

## Configuring the header

The header is template-owned, so it is configured through site options and never by
composing a nav. Styling is `pp_site_udc` (above); the options below are content and
configuration.

| Goal | Surface |
|------|---------|
| Set the site logo | `update_site_option` with key `pp_logo_id` (a Media Library **image** attachment ID, not a URL) |
| Override the logo's alt text | `update_site_option` with key `pp_logo_alt` (text, #582). Only needed when the alt should differ from the attachment's own alt metadata; unset uses the attachment alt, then the site title. The same option also feeds the footer logo. Empty **or whitespace-only** counts as unprovided and falls through the chain; a real value renders verbatim |
| Build the header menu | The menu actions: `create_menu` / `set_menu` / `add_menu_item` |
| Attach a menu to the header | `assign_menu_location` with location `primary` |
| Dark or gradient header, text and link colours, spacing, typography | `update_site_option` with key `pp_site_udc`, setting the roles above |

The active/current link is the `link-current` role, which carries both its colour and
its bold weight. Style the header to match the SITE's real header, not the hero: a dark hero
is not a reason to make the header dark. Layout, sticky behavior and menu structure are
still not configurable — the UDC is a design surface, not a header builder.

`wp pp apply preflight` reports a `nav_readiness` warning when the `primary` location has
no menu, its menu is empty, or `pp_logo_id` points at something that is not an image.

## Behavior

- **Mobile** (`< 768px`): Hamburger button shown. Menu hidden (`hidden` attribute). JS in `main.js` toggles `aria-expanded` and `hidden`.
- **Desktop** (`≥ 768px`): Hamburger hidden via CSS. Menu always visible.
- **Keyboard**: `Escape` closes the menu and returns focus to the toggle button.
- **Dropdown submenus** (#381): a nav item with children (authored via `set_menu`'s `children` array — one level deep) renders as an accessible dropdown. The theme's nav walker renders a `.nav__submenu-toggle` button beside each parent link and `main.js` wires it into a WAI-ARIA **disclosure** (`aria-expanded`, never a menubar — no `role="menu"`). The button is server-rendered since #994, which is what lets the `submenu-toggle` role style it; without JavaScript it stays hidden, so there is never a control that does nothing. Desktop: hover reveals the dropdown (mouse); the button opens it for keyboard. Mobile: the group expands in place. Keyboard: the toggle opens on `Enter`/`Space`, `ArrowDown` opens it and moves focus to the first child, `Escape` closes it and returns focus to the toggle. Without JS, the submenu stays visible (expanded on mobile, hover on desktop).
- **Active link**: WordPress marks the current item server-side (`current-menu-item` on the `<li>`, `aria-current="page"` on the `<a>`); no JS. Its colour AND its bold weight are the `link-current` role, defaulting to `@color-accent` and `700`. The role targets the current item's own link as a direct child, so a current page that HAS a dropdown does not hand the treatment to every link inside it.

## Usage

```php
// Called automatically from templates/base.php
pp_get_component('nav', ['location' => 'primary']);

// With custom logo text
pp_get_component('nav', [
    'location'  => 'primary',
    'logo_text' => 'My Brand',
]);
```

## Setting up the menu

In WP Admin: Appearance → Menus → create a menu and assign it to the "Primary Navigation" location. WordPress's own "sub item" nesting (drag an item slightly right) renders as a dropdown too. Via the AI surface, nest with `set_menu`'s per-item `children` array (one level deep).

## CSS

Structural styles in `assets/css/components.css` under `/* === COMPONENT: nav === */` —
layout, wrapper geometry and accessibility affordances only. Everything designable (colour,
type, size, spacing, border, shadow) is a role `default` in `components/nav/schema.json`
since #994.

At `md` breakpoint (768px): `.nav__toggle { display: none }` and `.nav__menu { display: block }` (always visible, `hidden` attribute overridden by CSS).
