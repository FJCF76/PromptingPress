# Component: logos

A flex-wrap image grid. Use for client logo strips (items without labels) or icon-category tiles (items with labels). Both use cases share the same component and CSS — the `label` field controls which layout renders.

## Props

| Prop    | Type   | Required | Default | Description |
|---------|--------|----------|---------|-------------|
| `id`    | string | No       | `''`    | HTML id for anchor linking |
| `title` | string | No       | `''`    | Optional heading above the grid |
| `theme`   | enum | No       | `default` | Background color/tone: `default` (page background), `muted` (light tinted surface band with borders), `inverted` (inverted dark background for strong contrast) |
| `items` | array  | Yes      | —       | Array of image items |

Each item:

| Key         | Type   | Required | Description |
|-------------|--------|----------|-------------|
| `image_url` | string | Yes      | Image or icon URL |
| `image_alt` | string | Yes      | Alt text — use the logo or category name |
| `image_id`  | int    | No       | Media Library attachment ID. When set and it resolves, renders responsively (`srcset`/`sizes`) via `wp_get_attachment_image()`; falls back to `image_url` otherwise |
| `label`     | string | No       | Text label below the image. Omit for logo-only rows. |

## Usage — logo strip (no labels)

```php
pp_get_component('logos', [
    'title' => 'Clients',
    'items' => [
        ['image_url' => '/path/to/3m.png',   'image_alt' => '3M'],
        ['image_url' => '/path/to/depsa.png', 'image_alt' => 'Depsa'],
    ],
]);
```

## Usage — icon + category tiles (with labels)

```php
pp_get_component('logos', [
    'title' => 'Sectors',
    'items' => [
        ['image_url' => '/icons/construction.svg', 'image_alt' => 'Construction', 'label' => 'Construction'],
        ['image_url' => '/icons/finance.svg',      'image_alt' => 'Finance',      'label' => 'Financial services'],
    ],
]);
```

## Style slots

8 per-instance style slots, declared in `schema.json` under `styling.style_slots`
and set with the `style_component` action. This table is the map — read each slot's
`type`, effective `default`, `applies_when` condition and full description from the
schema itself, or with `wp pp operate inspect-composition <page>`.

`◦` = conditional (`applies_when`): setting it outside that configuration is accepted
and stored but paints nothing, and `wp pp check page` reports a non-blocking
`inert_slot` smell.

| Group | Slots |
|---|---|
| Band | `--logos-padding-top` · `--logos-padding-bottom` · `--logos-gap` ◦ |
| Heading | `--logos-heading-size` ◦ · `--logos-heading-color` ◦ · `--logos-heading-measure` ◦ · `--logos-heading-margin-bottom` ◦ |
| Images | `--logos-image-size` ◦ |

`--logos-image-size` has two effective defaults, not one: `3rem` on a logo-only strip
and `2.5rem` on a labelled tile, because a tile carries a caption under the image.
Setting the slot replaces **both** branches with the single value you write.

**There is no `--logos-bg`** — see the deferred band-background gate below.

## Stated defaults (and what would reopen them)

| Default | Why it is a default | What would reopen it |
|---|---|---|
| Images are `object-fit: contain`, with no focal-point or aspect-ratio slots | `logos` is a **fit** model, not a crop model: a client logo or category glyph must be shown whole, so cropping it to a focal point would be a defect rather than a feature. This is the deliberate contrast with the testimonials avatar, whose `object-fit: cover` **is** a crop model. `--{hero,section}-image-position` and `--{hero,section}-image-aspect-ratio` are therefore not exposed here on purpose. | A layout that genuinely needs a cropped logo tile — which would be a different component's job, not a slot here. |
| `.logos--dark` framing borders — `1px solid var(--color-border)` top and bottom | The colour already routes `var(--color-border)`, so a site-wide rule retune reaches it with one `update_design_token` write; and the treatment is deliberately **consistent across every muted variant** — `embed` and `stats` use the same 1px pair, so the muted bands frame identically down a page. | **Already scheduled, not speculative:** bound to the deferred band-background gate below. |

### The deferred band-background gate

`--logos-bg` **does not exist**, and neither do `--embed-bg` or `--table-bg`. This
component's own `muted` variant paints `--color-surface` directly. (`table` is the odd one
out: it declares no `theme` prop and no variant classes at all, so it has no band tone to
paint in the first place.)

Its **entry criterion**, for the day the deferred gate opens: if `--logos-bg` is ever
shipped, these framing borders must route a slot **in the same change**. An author who paints the band a new colour and gets a
frame that no longer matches it is worse off than one who cannot paint it at all. The
borders do not stay independently open after that gate — they join it.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: logos === */`.
