# Component: section

Generic text + optional image section. Use this for "what is this", "how it works", and any narrative content block.

## Props

| Prop               | Type   | Required | Default       | Description |
|--------------------|--------|----------|---------------|-------------|
| `id`               | string | No       | `''`          | HTML id for anchor linking |
| `title`            | string | No       | `''`          | Section heading (h2) |
| `title_accent`     | string | No       | `''`          | Exact substring of `title` to render in an accent color |
| `eyebrow`          | string | No       | `''`          | Short kicker/label rendered as a pill above the title |
| `subheading`       | string | No       | `''`          | Supporting line below the title |
| `title_align`    | enum   | No       | `'start'`     | Header block alignment: `start` / `center` |
| `body`             | string | No¹      | `''`          | HTML body content (wp_kses_post filtered). Optional since #488 — omit for a `body_items`-only or panel-only band |
| `image_url`        | string | No       | `''`          | Image URL (required for image-left / image-right) |
| `image_id`         | int    | No       | `0`           | Media Library attachment ID for the image. When set and it resolves, renders responsively (`srcset`/`sizes`) via `wp_get_attachment_image()`; falls back to `image_url` otherwise |
| `image_alt`        | string | No       | `''`          | Image alt text |
| `layout`           | enum   | No       | `'text-only'` | Structural layout: `text-only` / `image-left` / `image-right` / `centered` / `text-panel` |
| `theme`            | enum   | No       | `'default'`   | Background color/tone: `default` / `muted` (light tinted surface band) / `inverted` (genuinely dark band) |
| `background_image` | string | No       | `''`          | Optional background image URL with dark overlay for text readability |
| `body_marker`        | enum   | No       | `'disc'`      | List marker for top-level `<ul>` lists in `body`: `disc` / `check` / `dash` / `arrow`. Colour via the `--section-body-marker-color` slot |
| `body_items`         | array  | No       | `[]`          | Optional centered row of short plain-text items rendered below the body with a CSS-generated middot separator between each. Max 8 items, each ≤80 chars (over-bound or non-string rejected at write time). Inherits the body type slots; colour the separator via `--section-separator-color`. When no `body` copy precedes it (a body-less strip), the row's top margin drops to `0` so the band's own symmetric padding centres it |
| `panel_heading`      | string | No       | `''`          | `text-panel` layout only: heading of the right-hand content panel (rendered as an `<h3>`). Ignored by other layouts |
| `panel_body`         | string | No       | `''`          | `text-panel` layout only: optional plain-text intro paragraph inside the panel, above the list. Escaped as text (no HTML) |
| `panel_items`        | array  | No       | `[]`          | `text-panel` layout only: panel list entries. Each entry is EITHER a plain-text string (bulleted list item) OR a paired-row object `{ label, value }` — see below |
| `panel_cta_text`     | string | No       | `''`          | `text-panel` layout only: label of the panel's CTA button. The button renders only when both `panel_cta_text` and `panel_cta_url` are set |
| `panel_cta_url`      | string | No       | `''`          | `text-panel` layout only: destination URL of the panel CTA. Absolute URL, site-relative `/path`, `#anchor`, `mailto:` or `tel:`; a disallowed protocol (`javascript:`, `data:`) is rejected at write time |
| `panel_cta_variant`  | enum   | No       | `'primary'`   | `text-panel` layout only: panel CTA button style: `primary` / `secondary` / `outline` / `ghost` |
| `panel_items_marker` | enum   | No       | `'disc'`      | text-panel layout: list marker for the string entries of `panel_items`: `disc` / `check` / `dash` / `arrow`. Colour via the `--section-panel-marker-color` slot. Paired rows never show a marker |

¹ **Content requirement (#488):** `body` is optional, but a section must carry at least one of `body`, `body_items`, or panel content (`panel_heading` / `panel_body` / `panel_items` / a panel CTA). A fully-empty section is rejected at write time (`invalid_composition`). A `title` alone does not satisfy the requirement.

### Content panel (`text-panel` layout)

`panel_items` entries are EITHER plain strings (bulleted list items) OR paired-row objects `{ label, value }` rendered as a two-part row (label left, value right at >=768px; below that the pair stacks label-above-value on one shared left edge, label smaller and tracked, value carrying the weight, so a long value gets the panel's full measure and the pair still reads as label-then-fact) — for a spec sheet, pricing summary, stat readout, or config list. String and paired-row entries mix in one list. A paired row may carry a per-row `style` map setting the item_eligible `--section-panel-text` slot to emphasise/de-emphasise that one row. Set `--section-panel-font` to `var(--font-mono)` for a monospace panel. A monospace data panel (spec sheet, config readout, stat summary) is a composition of these generic parts (mono font + paired rows + dark `--section-panel-bg` + per-row accent), not a named mode. Full grammar and worked examples: `ai-instructions/composition.md`.

**Panel CTA fill (#536).** The panel's `primary` CTA ships the shared premium gradient, which is a background-IMAGE and therefore paints over any background colour. `--section-panel-cta-bg` is the per-instance slot that actually replaces it with a flat brand fill; `--section-panel-cta-color` sets the ink and `--section-panel-cta-shadow: none` flattens the bevel on rest and hover. An unset border follows the fill, so a fill-only recolour keeps a matching ring. They reach the `primary` variant only (outline/ghost/secondary stay transparent) and only when the panel renders a CTA. Resting state only for the FILL: a flat panel button hovers back to the premium gradient, and no per-instance hover fill slot exists. The RING is the exception (#584): `--section-panel-cta-border` and `--section-panel-cta-hover-border` are its own per-instance ring pair, so a panel CTA can hold a chosen ring through the hover instead of reverting to the theme accent. Both sit at the head of their chains; unset, the ring falls to the site-wide `--btn-border-color` / `--btn-hover-border-color` and then to the theme default, so an unset button is byte-identical to before.

## Variants

Layout (`layout`):
- **text-only** — Full-width text column. Used for articles and prose content.
- **image-left** — Two-column at md+: image on left, text on right.
- **image-right** — Two-column at md+: text on left, image on right.
- **centered** — Text constrained to a narrower centered column. Use for short intros and taglines, not multi-paragraph content.
- **text-panel** — Text column beside a styleable content panel (`panel_heading` / `panel_body` / `panel_items` + an optional panel CTA) at 768px+, stacked text-then-panel below that. See "Content panel" above.

If `image_url` is empty, image-left/image-right layouts fall back to `text-only`. A
`text-panel` layout with no panel content falls back to `text-only` the same way.

Background color/tone (`theme`), independent of layout:
- **default** — Page background.
- **muted** — Surface background with borders. (Renders under the legacy `--dark` CSS class name; `muted` is the value you write.)
- **inverted** — Inverted background for strong contrast.

## Usage

```php
// Basic prose section
pp_get_component('section', [
    'title' => 'About This Theme',
    'body'  => '<p>PromptingPress is designed for AI comprehension.</p>',
]);

// Section with image on the right
pp_get_component('section', [
    'title'     => 'The WP Abstraction Layer',
    'body'      => '<p>Only lib/wp.php calls WordPress functions directly.</p>',
    'image_url' => 'https://example.com/diagram.png',
    'image_alt' => 'Architecture diagram',
    'layout'    => 'image-right',
]);

// Page content (from WP editor)
pp_get_component('section', [
    'body'   => pp_page_content(),
    'layout' => 'text-only',
]);
```

## Style slots

47 per-instance style slots, declared in `schema.json` under `styling.style_slots`
and set with the `style_component` action. This table is the map — read each slot's
`type`, effective `default`, `applies_when` condition and full description from the
schema itself, or with `wp pp operate inspect-composition <page>`.

`▪` = item-eligible (also settable per row in `panel_items[].style`) · `◦` =
conditional (`applies_when`): setting it outside that configuration is accepted and
stored but paints nothing, and `wp pp check page` reports a non-blocking `inert_slot`
smell **for a component-level slot only** — the advisory does not read per-row
`panel_items[].style` maps, so check the condition yourself when you set one there.

| Group | Slots |
|---|---|
| Band | `--section-padding-top` · `--section-padding-bottom` · `--section-bg` · `--section-border-color` · `--section-border-width` · `--section-radius` · `--section-shadow` · `--section-bg-position` ◦ · `--section-overlay-bg` ◦ |
| Heading | `--section-heading-size` ◦ · `--section-heading-measure` ◦ · `--section-heading-color` · `--section-heading-accent-color` ◦ · `--section-subheading-color` ◦ · `--section-subheading-margin-bottom` ◦ · `--section-heading-margin-bottom` ◦ |
| Eyebrow | `--section-eyebrow-color` ◦ · `--section-eyebrow-bg` ◦ · `--section-eyebrow-radius` ◦ · `--section-eyebrow-border-width` ◦ · `--section-eyebrow-border-color` ◦ · `--section-eyebrow-text-transform` ◦ |
| Body | `--section-body-color` · `--section-body-size` · `--section-body-weight` · `--section-body-measure` · `--section-body-link-color` · `--section-body-link-hover-color` · `--section-body-marker-color` ◦ · `--section-separator-color` ◦ · `--section-inline-items-align` ◦ |
| Image | `--section-image-radius` ◦ · `--section-image-position` ◦ · `--section-image-aspect-ratio` ◦ |
| Panel (`text-panel`) | `--section-panel-bg` ◦ · `--section-panel-border-color` ◦ · `--section-panel-border-width` ◦ · `--section-panel-radius` ◦ · `--section-panel-padding` ◦ · `--section-panel-text` ▪ ◦ · `--section-panel-font` ◦ · `--section-panel-marker-color` ◦ · `--section-panel-cta-bg` ◦ · `--section-panel-cta-border` ◦ · `--section-panel-cta-hover-border` ◦ · `--section-panel-cta-color` ◦ · `--section-panel-cta-shadow` ◦ |

`--section-heading-measure` defaults to `none`, not to the shared `--measure-heading`
token: the section title has never carried a cap and `section` is the most-used band,
so it stays uncapped unless you say otherwise.

## Stated defaults (and what would reopen them)

These values are deliberate product defaults, not oversights, and are not authorable.
Each names the condition that would reopen the decision. Adding a control needs a
**named incident** — a real composition that could not be built — not a hypothesis.

| Default | Why it is a default | What would reopen it |
|---|---|---|
| The `body_items` separator **glyph** is fixed to a middot (`content: "\00b7" / ""`) | The separator is CSS-generated, not a content character, so it can be slot-coloured (`--section-separator-color`) and stays out of the accessibility tree — the `/ ""` alternative text empties its a11y name. Fixing the glyph is what lets the box be a fixed `var(--space-sm)` width, which is why the left-pull is an exact token value independent of any glyph's advance width. The **colour is authorable; only the glyph is not.** | A composition needs a non-middot separator. Cheap when it comes: the box is deliberately glyph-independent, so a swap would not move the layout. |
| `main > .section .section__content p + p { margin-top: 1.05rem }` | A typographic value tuned against the composed-page section body scale (`--section-body-size`, `1.065rem`, at `line-height: 1.76` on desktop), deliberately ~5% larger than `--space-md` so paragraph separation still reads against that loose leading. Snapping it to `--space-md` for tidiness would be a real 5% regression, not a cleanup. | A site retunes `--section-body-size` or the section body leading far from those values and paragraph rhythm stops reading. |

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: section === */`.

## What NOT to change

- Do not call WordPress functions in `section.php`. Use pp_* wrappers.
- Do not add raw hex colors. Use CSS variables from `base.css`.
