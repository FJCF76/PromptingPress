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
| `heading_align`    | enum   | No       | `'start'`     | Header block alignment: `start` / `center` |
| `body`             | string | Yes      | —             | HTML body content (wp_kses_post filtered) |
| `image_url`        | string | No       | `''`          | Image URL (required for image-left / image-right) |
| `image_id`         | int    | No       | `0`           | Media Library attachment ID for the image. When set and it resolves, renders responsively (`srcset`/`sizes`) via `wp_get_attachment_image()`; falls back to `image_url` otherwise |
| `image_alt`        | string | No       | `''`          | Image alt text |
| `layout`           | enum   | No       | `'text-only'` | Structural layout: `text-only` / `image-left` / `image-right` / `centered` |
| `theme`            | enum   | No       | `'default'`   | Background color/tone: `default` / `muted` (light tinted surface band) / `inverted` (genuinely dark band) |
| `background_image` | string | No       | `''`          | Optional background image URL with dark overlay for text readability |
| `body_marker`        | enum   | No       | `'disc'`      | List marker for top-level `<ul>` lists in `body`: `disc` / `check` / `dash` / `arrow`. Colour via the `--section-body-marker-color` slot |
| `panel_items_marker` | enum   | No       | `'disc'`      | text-panel layout: list marker for the string entries of `panel_items`: `disc` / `check` / `dash` / `arrow`. Colour via the `--section-panel-marker-color` slot. Paired rows never show a marker |

### Content panel (`text-panel` layout)

`panel_items` entries are EITHER plain strings (bulleted list items) OR paired-row objects `{ label, value }` rendered as a two-part row (label left, value right) — for a spec sheet, pricing summary, stat readout, or config list. String and paired-row entries mix in one list. A paired row may carry a per-row `style` map setting the item_eligible `--section-panel-text` slot to emphasise/de-emphasise that one row. Set `--section-panel-font` to `var(--font-mono)` for a monospace panel. A monospace data panel (spec sheet, config readout, stat summary) is a composition of these generic parts (mono font + paired rows + dark `--section-panel-bg` + per-row accent), not a named mode. Full grammar and worked examples: `ai-instructions/composition.md`.

## Variants

Layout (`layout`):
- **text-only** — Full-width text column. Used for articles and prose content.
- **image-left** — Two-column at md+: image on left, text on right.
- **image-right** — Two-column at md+: text on left, image on right.
- **centered** — Text constrained to a narrower centered column. Use for short intros and taglines, not multi-paragraph content.

If `image_url` is empty, image-left/image-right layouts fall back to `text-only`.

Background color/tone (`theme`), independent of layout:
- **default** — Page background.
- **dark** — Surface background with borders.
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
