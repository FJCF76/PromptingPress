# Component: hero

Full-width hero section with headline, optional subheading, optional CTA button, and optional image.

## Props

| Prop            | Type   | Required | Default      | Description |
|-----------------|--------|----------|--------------|-------------|
| `id`            | string | No       | `''`         | HTML id for anchor linking |
| `title`         | string | Yes      | —            | Primary headline text |
| `title_accent`  | string | No       | `''`         | Exact substring of `title` to render in an accent color |
| `eyebrow`       | string | No       | `''`         | Short kicker/label rendered as a pill above the title |
| `subheading`      | string | No       | `''`         | Supporting subheadline |
| `button_text`      | string | No       | `''`         | Primary CTA button label (both buttons hidden if empty) |
| `button_url`       | string | No       | `'#'`        | Primary CTA button URL |
| `button2_text`     | string | No       | `''`         | Secondary CTA button label (only shown when `button_text` is set) |
| `button2_url`      | string | No       | `'#'`        | Secondary CTA button URL |
| `button_variant`   | enum   | No       | `'primary'`  | Primary button style: `primary` / `secondary` / `outline` / `ghost` |
| `button2_variant`  | enum   | No       | `'outline'`  | Secondary button style: `primary` / `secondary` / `outline` / `ghost` |
| `layout`       | enum   | No       | `'centered'` | Layout: `left`, `centered`, `split`, or `cover` |
| `image_url`     | string | No       | `''`         | Image URL — inline image in `split` layout, background image in `cover` layout |
| `image_id`      | int    | No       | `0`          | Media Library attachment ID for the `split` layout image. When set and it resolves, renders responsively (`srcset`/`sizes`) via `wp_get_attachment_image()`; falls back to `image_url` otherwise |
| `image_alt`     | string | No       | `''`         | Alt text for the `split` layout image |
| `spacing`       | enum   | No       | `'default'`  | Vertical padding: `default` / `compact` / `spacious` |
| `width`         | enum   | No       | `'default'`  | Content width: `default` / `narrow` (the `--measure-centered` token, 56rem by default, so a retuned centered measure moves this too) / `full`. `width` and `spacing` are hero-only props — no other component emits `data-pp-width` / `data-pp-spacing`, and the CSS is scoped to `.hero` |
| `split_ratio`   | enum   | No       | `'50-50'`    | Column ratio for `split` layout: `50-50` / `60-40` / `40-60` |
| `vertical_align`| enum   | No       | `'center'`   | Vertical content alignment for `cover`/`split` layouts: `top` / `center` / `bottom` / `stretch`. `stretch` (split only) makes the media column fill the content column's height — one asset balances any headline length; on `cover` it renders like `center` |
| `proof`         | string | No       | `''`         | HTML string for trust signals (logos, ratings), rendered after the CTA group |

## Layouts

- **centered** — All content centered horizontally. Best for homepage hero.
- **left** — Content aligned left. Best for interior page headers.
- **split** — Text on left, image on right (two-column at lg+). Best for feature introductions.
- **cover** — Fullscreen background image with a dark overlay and light text. Best for high-impact landing pages. Both CTAs sit on that overlay, so their `outline`/`ghost` defaults fall back to `--color-accent-on-overlay` rather than the light-surface accent, and every filled CTA — the primary and the second button (`button2_*`) alike — defaults its border to the same role so its shape stays visible against the image and an unstyled filled pair stays symmetric (the role is the last link, so `--hero-button-border`, `--hero-accent` and this band's own `--hero-button-bg` all still win ahead of it for the PRIMARY, and `--hero-button2-border`, `--hero-accent` and `--hero-button2-bg` for the second button, in that order — the site-wide button tokens are deliberately NOT in these chains: `--btn-border-color` / `--btn-hover-border-color` left in #564 and `--btn-bg` / `--btn-hover-bg` in #565, because the role carries a measured 4.59:1 contrast guarantee that a site-wide retheme was cancelling, the ring tokens directly and the fill tokens through the border-follows-fill link. Flattening this band with `--hero-button2-bg` still gives a matching ring; a site-wide `--btn-bg` does not reach it). `--hero-heading-color` (first CTA) and `--hero-button2-color` / `--hero-button2-border` (second) still win when set. The focus ring routes through the same role for every variant, filled included — it is drawn outside the button and so lands on the overlay, where the bare accent measured 1.17:1 against WCAG 1.4.11's 3:1. Only the colour changes; width, style and offset are unchanged. Keyboard focus is the motivating case, but the routing attaches to `:focus`, so it recolours whatever ring the button already paints, a pointer click included. This applies to any `cover` hero, image or not: the scrim is painted either way.

## Usage

```php
// Centered homepage hero
pp_get_component('hero', [
    'title'    => 'Build AI-Ready WordPress Sites',
    'subheading' => 'PromptingPress gives AI tools a clear map of your site.',
    'button_text' => 'Get Started',
    'button_url'  => '/get-started',
    'layout'  => 'centered',
]);

// Split hero with image
pp_get_component('hero', [
    'title'     => 'The Abstraction Layer',
    'subheading'  => 'lib/wp.php is the only file that calls WordPress.',
    'layout'   => 'split',
    'image_url' => get_template_directory_uri() . '/assets/images/diagram.png',
]);

// Interior page header (left-aligned, no CTA)
pp_get_component('hero', [
    'title'   => pp_page_title(),
    'layout' => 'left',
]);
```

## Style slots

49 per-instance style slots, declared in `schema.json` under `styling.style_slots`
and set with the `style_component` action. This table is the map — read each slot's
`type`, effective `default`, `applies_when` condition and full description from the
schema itself, or with `wp pp operate inspect-composition <page>`.

`◦` = conditional (`applies_when`): setting it outside that configuration is accepted
and stored but paints nothing, and `wp pp check page` reports a non-blocking
`inert_slot` smell.

| Group | Slots |
|---|---|
| Band | `--hero-padding-top` · `--hero-padding-bottom` · `--hero-bg` · `--hero-overlay-bg` ◦ · `--hero-bg-position` ◦ · `--hero-border-color` · `--hero-border-width` · `--hero-radius` · `--hero-shadow` |
| Heading | `--hero-heading-color` · `--hero-heading-accent-color` · `--hero-heading-size` · `--hero-heading-measure` · `--hero-heading-margin-bottom` · `--hero-heading-weight` · `--hero-subheading-size` ◦ · `--hero-subheading-color` ◦ |
| Eyebrow | `--hero-eyebrow-color` ◦ · `--hero-eyebrow-bg` ◦ · `--hero-eyebrow-radius` ◦ · `--hero-eyebrow-border-width` ◦ · `--hero-eyebrow-border-color` ◦ · `--hero-eyebrow-text-transform` ◦ |
| Accent | `--hero-accent` · `--hero-accent-hover` |
| Content | `--hero-proof-color` · `--hero-content-gap` · `--hero-content-width` |
| Image (`split`) | `--hero-image-radius` ◦ · `--hero-image-position` ◦ · `--hero-image-aspect-ratio` ◦ |
| Buttons | `--hero-button-bg` · `--hero-button-hover-bg` · `--hero-button-border` · `--hero-button-hover-border` · `--hero-button-color` · `--hero-button-shadow` · `--hero-button2-bg` ◦ · `--hero-button2-border` ◦ · `--hero-button2-color` ◦ · `--hero-button2-hover-bg` ◦ · `--hero-button2-hover-border` ◦ · `--hero-button2-hover-color` ◦ |
| Proof panel (`split` + `proof`) | `--hero-surface-bg` ◦ · `--hero-surface-padding` ◦ · `--hero-surface-border-color` ◦ · `--hero-surface-border-width` ◦ · `--hero-surface-radius` ◦ · `--hero-surface-shadow` ◦ |

`--hero-heading-measure` defaults to `none`, not to the shared `--measure-heading`
token: `.hero__content` is a flex item that shrink-wraps to its widest child, so a cap
here narrows the whole content column (title, subheading AND buttons), not just the
headline. The hero measure you almost always want is `--hero-content-width`. See
`ai-instructions/style-component.md` → "Text measures".

The six `--hero-surface-*` slots paint the **`proof` panel's frame** — its background,
padding, border, radius and shadow. They do not reach the panel's contents, which are
whatever HTML you pass in `proof`.

## Stated defaults (and what would reopen them)

These values are deliberate product defaults, not oversights, and are not authorable.
Each names the condition that would reopen the decision. Adding a control needs a
**named incident** — a real composition that could not be built — not a hypothesis.

| Default | Why it is a default | What would reopen it |
|---|---|---|
| `.hero__title { line-height: 1.03 }` | The hero title is the product's only display-scale heading (up to `clamp(3rem, 4.5vw, 4.5rem)` at 768px+), and display type needs tighter leading than the shared `--line-height-heading: 1.2`, which is tuned for band headings at ~2rem. The hero is already a ratified opt-out from the shared heading scale; this is that opt-out's typographic other half. | An operator sets `--font-heading` to a face whose ascender/descender metrics collide at `1.03` on a two-line title. |
| `.hero__subtitle { max-width: 40ch }` | A hero subtitle is a **lede**, and `ch` is the right unit because the cap tracks the subtitle's own type size automatically — no slot needed to keep it proportional. The hero is exempt from `--measure-heading`, and this is a **body** measure, not a heading one. | An operator writes a long hero subtitle and it strands mid-column. |
| `.hero--cover { min-height: 70vh }` | A cover hero's job is a full-bleed opening image. `70vh` is the "most of the fold, not all of it" choice: it deliberately leaves the next band's top edge visible as a scroll affordance, which a `100vh` hero destroys. | **An operator wants a full-viewport or a short-banner cover hero and has no path** — `--hero-padding-*` do not reach `min-height`. This is the strongest add-a-control candidate in the ratified set; the remedy would be a single `--hero-cover-min-height` slot. |
| The default split ratio `minmax(0, 1.08fr) minmax(0, 0.92fr)` (1024px and up, where `split` becomes a two-column grid at all) | It is **the unset default of a shipped authorable control**, not an unexamined literal: `split_ratio` supplies the other two ratios, so this is one of three reachable values — the one you get by not choosing. Note the enum value is named `50-50` but renders a slight text-column bias (1.08 / 0.92); `50-50` emits no `data-pp-split-ratio` attribute and falls to this base rule. The `minmax(0, …)` wrapper is the documented grid-overflow mechanism, not a taste value. | An operator needs a ratio outside the three shipped ones — a **prop-enum** question, not a style-slot one. |
| `3fr 2fr` (`60-40`) and `2fr 3fr` (`40-60`) | These **are** the `split_ratio` enum's rendered values, selected by `[data-pp-split-ratio]`. A literal that is the implementation of an enum is not a missing control. | As above. |
| `.hero__surface-label` (`0.75rem` / `700` / `0.18em`), `.hero__surface-key` (`0.8125rem` / `0.08em`) and `.hero__surface-value` (`1rem` / `600`) type | **The template never emits these hyphenated child classes** — see the note below. They render only if you hand-write the class names into `proof`, which is free-form HTML. Slotting or tokenising type for markup the product never generates would add controls to a surface with no authoring path. | `proof` gains a structured schema — at which point the whole panel needs a slot family, and these literals are the smallest part of it. |

**The `.hero__surface-*` class vocabulary — read this before styling the proof panel.**
`hero.php` emits exactly one of these classes: the wrapper `<div class="hero__surface">`,
and only on a `split` hero that has `proof`. That wrapper is what the six
`--hero-surface-*` slots paint. The hyphenated children — `.hero__surface-label`,
`.hero__surface-list`, `.hero__surface-item`, `.hero__surface-key`,
`.hero__surface-value` — are **styled in `components.css` but never generated**. They
exist so a `proof` string can opt into a key/value proof-panel look by using those class
names itself. Nothing emits them for you, and no validation checks them, so a `proof`
that does not use them simply renders as whatever HTML you passed, inside a styled frame.

## CSS

Styles live in `assets/css/components.css` under the `/* === COMPONENT: hero === */` section.

Layouts are applied via the BEM modifier class `hero--{layout}`.

## What NOT to change

- Do not add WordPress function calls to `hero.php`. Use pp_* wrappers from `lib/wp.php`.
- Do not add raw hex color values to component CSS. Use CSS variables from `base.css`.
- Do not modify `schema.json` without updating this README.
