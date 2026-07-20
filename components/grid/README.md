# Component: grid

Responsive card grid for discrete content objects. Use this for blog post listings, team members, or real features with substantive descriptions. Do NOT use this as a decorative icon grid.

## Props

| Prop            | Type   | Required | Default   | Description |
|-----------------|--------|----------|-----------|-------------|
| `id`            | string | No       | `''`      | HTML id for anchor linking |
| `title`         | string | No       | `''`      | Section heading above the grid |
| `title_accent`  | string | No       | `''`      | Exact substring of `title` to render in an accent color |
| `eyebrow`       | string | No       | `''`      | Short kicker/label rendered as a pill above the title |
| `subheading`    | string | No       | `''`      | Supporting line below the title |
| `heading_align` | enum   | No       | `'start'` | Header block alignment: `start` / `center` |
| `layout`        | enum   | No       | `'cards'`   | Structural layout: `cards` (card grid) / `steps` (numbered process cards) |
| `card_emphasis` | enum   | No       | `'featured'` | First-card emphasis: `featured` (default — first card gets an accent bar, tinted fill, larger title, extra top padding, muted-theme lift) / `uniform` (every card identical). Use `uniform` for a symmetric/peer card row (see below). Cards-layout concept; ignored on `steps`. |
| `theme`         | enum   | No       | `'default'` | Background color: `default` / `muted` (light tinted surface band) / `inverted` (genuinely dark band) (independent of `layout`) |
| `columns`       | number | No       | —         | Explicit desktop (768px+) column count, integer `1`–`4`. Unset = auto by item count (see below). Out-of-range/non-integer values are rejected, not clamped. Cards-layout concept; ignored on `steps`. |
| `image_treatment` | enum | No       | `'banner'` | How each card's `image_url` renders: `banner` (full-width 16:9 cover banner above the body) / `icon` (small fixed icon size, un-cropped, above the title — see below). Unset = `banner` (byte-identical). Values outside the set are rejected, not coerced. Cards-layout concept; ignored on `steps`. |
| `items`         | array  | Yes      | —         | Array of card objects |

Each item in `items`:

| Key         | Type   | Required | Default        | Description |
|-------------|--------|----------|----------------|-------------|
| `number`    | string | No*      | —              | Step number label, e.g. `'1'`. *Required when `layout` is `'steps'`. |
| `title`     | string | No       | `''`           | Card heading (h3) |
| `text`      | string | No       | `''`           | Card body text |
| `bullets`   | array  | No       | —              | Checklist lines rendered below `text`, each prefixed with a check mark. Plain text only. |
| `text_role` | enum   | No       | —              | Typography role for the card text: `mono` / `meta` / `label` / `kicker`. `meta`/`kicker` set a preset text color; an explicit `--grid-item-text-color` slot overrides it at all breakpoints. |
| `image_url` | string | No       | `''`           | Card image URL |
| `image_alt` | string | No       | `''`           | Alt text for the card image |
| `link_url`  | string | No       | `''`           | Card link URL (shown only if set) |
| `link_text` | string | No       | `'Read more'`  | Card link label |

## Responsive behavior

| Breakpoint | Columns |
|------------|---------|
| Mobile     | 1       |
| Tablet (md 768px+) | 2  |
| Desktop (lg 1024px+) | depends on item count — see below |

At desktop, a composed `cards` grid lays out by item count:

| Items | Desktop columns |
|-------|-----------------|
| 2     | 2, centered in a narrower row |
| 3     | 3 across, spanning the container |
| 4     | 2 x 2, centered in a narrower row |
| other | 2 |

The `steps` layout is 3 across at desktop, except for a 4-item steps grid, which lays out 2 x 2.

### Forcing the desktop column count (`columns`)

The table above is the default when `columns` is unset. Set `columns` to an integer `1`–`4` to force that many equal-width columns at desktop (768px+), overriding the item-count grain — e.g. `columns: 3` renders a 6-item `cards` grid as 3-across x 2-rows instead of the default 2 x 3, and lets a 4-item grid choose 4-across instead of the default 2 x 2. The forced grid spans the container (no narrowing/centering) regardless of item count, and the single-column collapse below 768px is unchanged. A forced count with a non-multiple item count simply wraps the remainder onto the last row (e.g. `columns: 3` with 4 items = a row of 3 then a single left-aligned card); tracks use `minmax(0, 1fr)` so cards never overflow.

`columns` is a structural prop (set with `create_page` / `update_component`, not `style_component`). Values outside `1`–`4`, or non-integers, are rejected with an error — they are never silently clamped. It is a `cards` concept and is ignored on the `steps` layout, which keeps its fixed process grain.

## Card image treatment (`image_treatment`)

By default (`image_treatment: 'banner'`) each card's `image_url` renders inside a full-width `16:9` cover wrap above the card body — good for post thumbnails and photography, but it blows a small logo or glyph up into a cropped banner. Set `image_treatment: 'icon'` to render the image at a **small fixed icon size** instead: the `--grid-item-icon-size` slot (default `48px`) sizes a square box above the title, the `16:9` crop is dropped, and the image is `object-fit: contain` so the whole glyph/logo shows. This is the shape for the common **icon + title + text** feature card.

It works with and without `bullets` on the same card, and at every breakpoint (the icon stays icon-sized on mobile; the `<768px` single-column collapse is unchanged). Resize the icon box with the `--grid-item-icon-size` style slot — grid-wide via `style_component`, or per-card via `items[].style`. The icon **follows the card's `--grid-item-text-align`**: a card (or grid) set to `center` centers its icon above the title, `right` right-aligns it, `left` (the default) keeps it left — the icon aligns with the text and the `Read more` link, which all derive from that one slot (#361). So a centered icon+title+text card is fully centered.

`image_treatment` is a structural prop (set with `create_page` / `update_component`, not `style_component`). Unset keeps `banner` (byte-identical to prior rendering). A value outside the closed set (`banner` / `icon`) is rejected with `invalid_prop_value`, never silently coerced. It is a `cards` concept and is ignored on the `steps` layout, which renders no item images.

## Card emphasis (featured vs uniform)

By default a `cards` grid gives its **first card** a "featured" treatment: an accent top bar, a tinted gradient fill, a larger title, extra body top-padding, and (on the `muted` theme, legacy alias `dark`) a slight upward lift. This draws the eye to a lead item and is the historical, unchanged default (`card_emphasis: 'featured'`).

Set `card_emphasis: 'uniform'` to render **every card identically** — no featured first card. Use it whenever the cards are peers of equal weight and the featured emphasis would mislead or misalign them:

- Symmetric specification/comparison rows whose checklists or bodies must line up across cards (the featured card's extra top-padding otherwise pushes its content down relative to its neighbors).
- Equal-weight feature, benefit, or plan rows where no single card should stand out.

Keep the default `featured` when one card really is the lead (a highlighted plan, a primary feature). This is a structural prop, not a style slot — set it with `create_page` / `update_component`, not `style_component`. It differs from styling one card individually (`items[].style`) or from the slot-level `uniform-cards` recipe: `uniform` cleanly drops the *entire* featured treatment (including the first-card top-padding and muted-theme lift that slot overrides cannot reach).

## Steps layout

Set `layout: 'steps'` for a numbered process/how-it-works layout (the default `layout` is `cards`). Cards get a filled circular number badge and a subtle connector line between badges at desktop (1024px+). `theme` still controls background color independently of the steps layout.

## Usage

```php
// Blog post archive
pp_get_component('grid', [
    'items' => array_map(function() {
        return [
            'title'     => pp_page_title(),
            'text'      => pp_excerpt(25),
            'image_url' => pp_thumbnail_url('medium'),
            'link_url'  => pp_permalink(),
            'link_text' => 'Read post',
        ];
    }, $posts),
]);

// Feature card with a checklist instead of a paragraph
pp_get_component('grid', [
    'title' => 'Security',
    'items' => [
        [
            'title'   => 'Perimeter security',
            'bullets' => ['HTTP security headers', 'SSL/TLS validity', 'Clickjacking protection'],
        ],
        // ...
    ],
]);

// Feature list (content cards, not decoration)
pp_get_component('grid', [
    'title' => 'How It Works',
    'items' => [
        [
            'title' => 'WP Abstraction Layer',
            'text'  => 'lib/wp.php is the only file that calls WordPress functions directly.',
        ],
        // ...
    ],
]);
```

## Anti-slop rule

Cards in this component must represent real content objects. If you're placing icons in circles with a two-line description, reconsider whether the grid is the right component.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: grid === */`.

Card hover state: `translateY(-2px)` — subtle lift. No shadow by default; set a
`--grid-card-shadow` style slot (e.g. `var(--shadow-md)`) for elevated cards.

## Card content alignment

`--grid-item-text-align` (an `align`-typed style slot, #357) controls the alignment
of a card's **content** within the card body. It accepts one `text-align` keyword:
`left` (default, unchanged historical rendering), `center`, `right`, `start`, `end`,
or `justify`. Because the card body is a flex column whose text-bearing children
stretch to full width, the value governs each item's inline content — so `center`
centers the text stack (the centered emoji/label contact-card pattern).

The `Read more` link/button follows the SAME alignment (#361). `.grid__item-link` is
a flex item placed by `align-self`, and per the #338 flex trap `text-align` cannot
move its box — so `grid.php` derives an internal `--pp-grid-link-align` companion from
the same slot value (`left`/`start`/`justify` → `flex-start`, `center` → `center`,
`right`/`end` → `flex-end`) and the CSS reads `align-self: var(--pp-grid-link-align,
flex-start)`. The operator sets ONE slot; text and link align together, so a centered
card is fully centered. An unset slot emits no companion, so the `flex-start` fallback
keeps the link byte-identically left-pinned.

The slot is `item_eligible`: set it grid-wide via the grid-level style to align every
card, or per-card in `items[].style` to align a single card. An unset slot emits no
inline custom property, so existing cards render byte-identically (left-aligned).
