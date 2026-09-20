# Component: testimonials

Customer quotes with attribution, for social-proof sections. Use this instead of embedding `<blockquote>` HTML inside a `section` body — it gives each quote its own structured attribution (author, role, company, avatar) and lets AI reorder or restyle individual testimonials.

> **This is the first component on the Universal Design Contract (v2).** It declares **no style slots**. Everything you would once have set with `style_component` — colour, type, spacing, border, shadow, size — is now set through a `udc` map on the band, addressed by ROLE. See `docs/v2/BUILD-SPEC-sprint0.md` for the contract and `lib/udc.php` for the engine.

## Props

| Prop            | Type   | Required | Default | Description |
|-----------------|--------|----------|---------|-------------|
| `id`            | string | No       | `''`    | HTML id for anchor linking. This is the author's anchor name, NOT the band id that scopes this band's styling — that is the top-level `id` on the composition item, and the engine mints it. |
| `title`         | string | No       | `''`    | Section heading |
| `title_accent`  | string | No       | `''`    | Exact substring of `title` to render in an accent color |
| `eyebrow`       | string | No       | `''`    | Short kicker/label above the title |
| `subheading`    | string | No       | `''`    | Supporting line below the title |
| `layout`        | enum   | No       | `grid`  | Layout: `grid` (card grid) or `stack` (single centered column) |
| `items`         | array  | Yes      | —       | Array of testimonial objects |

Each item in `items`:

| Key         | Type   | Required | Default | Description |
|-------------|--------|----------|---------|-------------|
| `quote`     | string | Yes      | —       | The testimonial text. Inline HTML allowed: a, strong, em, br. |
| `author`    | string | No       | `''`    | Name of the person quoted |
| `role`      | string | No       | `''`    | The author's job title |
| `company`   | string | No       | `''`    | The author's company or organization |
| `image_url` | string | No       | `''`    | Optional avatar image URL |
| `image_alt` | string | No       | `''`    | Alt text for the avatar |
| `image_id`  | int    | No       | `0`     | Media Library attachment ID for the avatar. When set and it resolves, renders responsively (`srcset`/`sizes`) via `wp_get_attachment_image()`; falls back to `image_url` otherwise. A companion to `image_url`, not a replacement — an item with only an id renders no avatar. |

### Two props that used to exist

`theme` and `title_align` are **gone**, and for the same reason: their entire effect was value-styling — a background tone, a text alignment — which v2 keeps out of the stylesheet. A prop whose only effect the structural-CSS boundary removes would be accepted, stored, reported applied, and change nothing. Both are now said directly:

- a dark band is `_band` background plus the text roles' colours (see **Dark bands** below);
- a centred header is the `heading` / `eyebrow` / `subheading` roles' `typography.align`, with `spacing.margin-left` and `margin-right` set to `auto` to centre the block itself.

## Roles

A role is a named part of the component. Each one accepts the groups listed here, per breakpoint and in three states: `:hover`, `:focus-visible` and `:active`. Those three are the whole set, and they do not nest — `:disabled`, pseudo-elements like `::before`, and states on an ancestor are refused at write.

A role can also take a shared look by name through a `_preset` key. Write the name **bare**: `"_preset": "button"`, never `"@button"`, because an `@name` always means a design token.

**The `Groups` column below is not the whole of what a role accepts (#1079).** Beside the groups, every role — and `_band` — takes a `"_css"` map of CSS property => value for a property no group owns (`"quote": {"_css": {"opacity": "0.75"}}`), on the same breakpoint maps, `@token` references and three states as everything else. Use the group when one exists: a property a group already emits keeps that parameter's grammar inside `_css` anyway, and writing it raw costs you the catalog entry and buys nothing. A preset may **not** carry `_css`.

| Role | What it is | Groups |
|---|---|---|
| `_band` | The band itself (the `<section>`) | typography, spacing, border, background, sizing, shadow, motion |
| `eyebrow` | The kicker pill above the heading | typography, spacing, border, background, sizing, motion |
| `heading` | The band heading (`<h2>`) | typography, spacing, border, background, sizing, motion |
| `heading-accent` | The accented substring inside the heading | typography, spacing, border, background, sizing, motion |
| `subheading` | The supporting line below the heading | typography, spacing, border, background, sizing, motion |
| `list` | The container the cards lay out in | typography, spacing, border, background, sizing, motion |
| `card` | One testimonial's surface | typography, spacing, border, background, sizing, shadow, motion |
| `quote` | The quotation itself | typography, spacing, border, background, sizing, motion |
| `attribution` | The attribution row (avatar + name + role/company) | typography, spacing, border, background, sizing, motion |
| `author` | The quoted person's name | typography, spacing, border, background, sizing, motion |
| `meta` | The role/company line under the name | typography, spacing, border, background, sizing, motion |
| `avatar` | The author's picture | spacing, border, background, sizing, shadow, motion |

**Which roles carry `layout` (#1084):** `list`, `card`, `attribution`. Exposure is a box fact — a role carries the group when its own structural CSS makes the box a flex or grid container — and the roster is derived from the stylesheet by a test rather than kept by hand. `align-self` is NOT in this group: a box placing ITSELF is `sizing.align-self`, available on any role with `sizing`.

`motion` carries `transition-duration` and `timing-function`, both defaulting to the theme's `--transition`. You never write a `prefers-reduced-motion` rule: the engine emits that guard for every motion value it emits, and there is no parameter for it.

`shadow` is deliberately absent from the text roles. A `box-shadow` on a run of text is a smell; the elevation you want belongs on the `card`.

A `_preset` applies the groups the target role permits and skips the rest, and the write envelope names which were skipped. That is why `avatar` takes the `button` preset's spacing, border, background, sizing and motion but not its typography: the role does not permit typography at all. If a preset declares nothing the role permits at all, the write is refused naming both the preset and the role, rather than accepting a reference that would do nothing.

### Worked example — the #901 brand card

> "Testimonial card: white, 1px border. Quote in serif italic 19px, a 1px rule, and name, role and sector in sans."

```json
{
  "component": "testimonials",
  "props": {"layout": "stack", "items": [{"quote": "They shipped in six weeks.", "author": "Ada Lovelace", "role": "CTO", "company": "Analytical"}]},
  "udc": {
    "card":  {"background": {"fill": "#ffffff"},
              "border": {"width": "1px", "style": "solid", "color": "#e6e6e6"},
              "shadow": {"box": "none"}},
    "quote": {"typography": {"family": "@font-heading", "style": "italic",
                             "size": {"d": "19px", "p": "17px"}}},
    "attribution": {"border": {"width-top": "1px", "style-top": "solid", "color-top": "#e6e6e6"},
                    "spacing": {"padding-top": "1rem"}}
  }
}
```

Three things worth noting. `"@font-heading"` FOLLOWS the design token rather than freezing a copy of its value — that works on every parameter, including lengths, which the v1 style slots could not do. `{"d": "19px", "p": "17px"}` sets the size per breakpoint; the engine stores those as band-scoped tokens and the approval diff shows your literal beside the name it was stored as. And the card is styled in the `stack` layout, which v1 could not do at all: its card slots were gated to `grid`.

### Dark bands

There is no `theme` prop. Set the band background, then recolour every text role that now sits on it:

```json
"udc": {
  "_band":  {"background": {"fill": "#101828"}},
  "quote":  {"typography": {"color": "#f7f8fa"}},
  "author": {"typography": {"color": "#f7f8fa"}},
  "meta":   {"typography": {"color": "#c8ccd4"}}
}
```

**You own the contrast.** Nothing re-lights text for you: colour decisions belong to the values an author chooses, never baked into component CSS. Set a colour on every text role on the new background (`quote`, `author`, `meta`, `heading`, `subheading`, `eyebrow`) and on any link colour, and check each against the background for WCAG AA — 4.5:1 for body text, 3:1 for large text. A dark band with one role left un-recoloured renders dark ink on dark.

## Layout

`grid` lays the cards out in an auto-fit track: one card spans its container, two share it, three share it three ways. That is a deliberate fix — the old rule hard-coded two columns at 768px and three at 1024px, so a band with a single testimonial (the normal starting state for a real client) rendered a narrow card in the left half of the track with a large dead space beside it.

`stack` is a single centred column, capped at a readable measure.

Both layouts render the **same card**. To get a frameless quote, say so: set the `card` role's `border.width` to `0` and its `background.fill` to `transparent`.

## Structural CSS

`assets/css/components.css` keeps only layout scaffolding, wrapper geometry and accessibility affordances for this component. It contains no colour, type, size, spacing, border or shadow value, and `tests/js/css-lint.test.js` fails CI if one is added. That rule carries a detection proof, so it cannot quietly stop working.

## Stated defaults

These values are fixed by the theme's structural CSS or by a role default, and each one is deliberate rather than unexamined.

- **Avatar box: `2.75rem` square.** Large enough to read a face at a glance, small enough that the attribution row stays one line beside the name. It is a role default (`avatar` → `sizing.width`/`height`), so a band that wants a different size sets one.
- **Avatar shape: `border-radius: 50%`.** The circular crop is the near-universal convention for a person's photograph in a testimonial, and it reads as a person rather than as an image. Role default on `avatar` → `border.radius`.
- **Avatar crop: `object-fit: cover`.** Structural. A portrait and a landscape source both have to fill the same box without distorting; `cover` is the only fit that does.
- **Stack quote type: `1.375rem`.** A pull-quote in a single centred column carries the band, so it sits above body size without competing with the heading. Role default on `quote` → `typography.size`.
- **Stack measure: `max-width: 42rem`.** Structural wrapper geometry: the column IS the layout, and 42rem keeps a long quote inside a readable line length.

**What would reopen it** (the reopening condition for any default above): a NAMED INCIDENT — a real brand whose specification these values cannot express even through the `udc` map, reported with the design that failed. Four of the five are already authorable per band, so the bar for changing the DEFAULT is that the default is wrong for most sites, not that one site wants something else.

### One default that was removed

The decorative opening-quote glyph (a `"` rendered via `::before`) is gone. It was a designable decoration that no slot could switch off, so a quote whose own text already carried typographic quotation marks rendered two opening quotes. The structural-CSS boundary has no room for a decoration, and Sprint 0's taxonomy has no generated-content group, so it was removed rather than reproduced. A quote now renders exactly the characters it was written with.
