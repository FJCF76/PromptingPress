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
| `layout`           | enum   | No       | `'text-only'` | Structural layout: `text-only` / `image-left` / `image-right` / `centered` |
| `theme`            | enum   | No       | `'default'`   | Background color/tone: `default` / `muted` (light tinted surface band) / `inverted` (genuinely dark band) |
| `background_image` | string | No       | `''`          | Optional background image URL with dark overlay for text readability |
| `body_marker`        | enum   | No       | `'disc'`      | List marker for top-level `<ul>` lists in `body`: `disc` / `check` / `dash` / `arrow`. Colour via the `--section-body-marker-color` slot |
| `body_items`         | array  | No       | `[]`          | Optional centered row of short plain-text items rendered below the body with a CSS-generated middot separator between each. Max 8 items, each ≤80 chars (over-bound or non-string rejected at write time). Inherits the body type slots; colour the separator via `--section-separator-color`. When no `body` copy precedes it (a body-less strip), the row's top margin drops to `0` so the band's own symmetric padding centres it |
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

If `image_url` is empty, image-left/image-right layouts fall back to `text-only`.

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

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: section === */`.

## What NOT to change

- Do not call WordPress functions in `section.php`. Use pp_* wrappers.
- Do not add raw hex colors. Use CSS variables from `base.css`.
