# Component: testimonials

Customer quotes with attribution, for social-proof sections. Use this instead of embedding `<blockquote>` HTML inside a `section` body — it gives each quote its own structured attribution (author, role, company, avatar) and lets AI reorder or restyle individual testimonials.

## Props

| Prop            | Type   | Required | Default | Description |
|-----------------|--------|----------|---------|-------------|
| `id`            | string | No       | `''`    | HTML id for anchor linking |
| `title`         | string | No       | `''`    | Section heading |
| `title_accent`  | string | No       | `''`    | Exact substring of `title` to render in an accent color |
| `eyebrow`       | string | No       | `''`    | Short kicker/label above the title |
| `subheading`    | string | No       | `''`    | Supporting line below the title |
| `title_align` | enum   | No       | `start` | `start` or `center` |
| `layout`       | enum   | No       | `grid`  | Layout: `grid` (card grid) or `stack` (single centered column) |
| `theme`         | enum   | No       | `default` | Background color: `default` / `muted` (light tinted surface band) / `inverted` (genuinely dark band) |
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

`layout` and `theme` are independent axes, same pattern as `grid` and `cta`: `layout` controls structure, `theme` controls background color.

## Usage

```php
pp_get_component('testimonials', [
    'title' => 'What our clients say',
    'items' => [
        [
            'quote'   => 'PromptingPress cut our page-build time in half.',
            'author'  => 'Jane Doe',
            'role'    => 'CEO',
            'company' => 'Acme Inc.',
        ],
        [
            'quote'  => 'The AI never touches raw HTML — it just works.',
            'author' => 'John Smith',
        ],
    ],
]);

// Single large pull-quote
pp_get_component('testimonials', [
    'layout' => 'stack',
    'items' => [
        ['quote' => 'The best WordPress theme for AI-first sites.', 'author' => 'Ada Lovelace'],
    ],
]);
```

## Style slots

27 per-instance style slots, declared in `schema.json` under `styling.style_slots`
and set with the `style_component` action. This table is the map — read each slot's
`type`, effective `default`, `applies_when` condition and full description from the
schema itself, or with `wp pp operate inspect-composition --post_id=<id>`.

`◦` = conditional (`applies_when`): setting it outside that configuration is accepted
and stored but paints nothing, and `wp pp check page` reports a non-blocking
`inert_slot` smell. The card slots marked `◦` need `layout: "grid"`; `--testimonials-item-radius` carries no condition and applies to both layouts.

| Group | Slots |
|---|---|
| Band | `--testimonials-padding-top` · `--testimonials-padding-bottom` · `--testimonials-bg` · `--testimonials-gap` |
| Heading | `--testimonials-heading-size` ◦ · `--testimonials-heading-color` ◦ · `--testimonials-heading-measure` ◦ · `--testimonials-heading-accent-color` ◦ · `--testimonials-heading-margin-bottom` ◦ · `--testimonials-subheading-color` ◦ · `--testimonials-subheading-margin-bottom` ◦ |
| Eyebrow | `--testimonials-eyebrow-color` ◦ · `--testimonials-eyebrow-bg` ◦ · `--testimonials-eyebrow-radius` ◦ · `--testimonials-eyebrow-border-width` ◦ · `--testimonials-eyebrow-border-color` ◦ · `--testimonials-eyebrow-text-transform` ◦ |
| Card | `--testimonials-item-bg` ◦ · `--testimonials-item-border-color` ◦ · `--testimonials-item-border-width` ◦ · `--testimonials-item-radius` · `--testimonials-item-shadow` ◦ · `--testimonials-item-padding` ◦ |
| Quote and attribution | `--testimonials-quote-color` · `--testimonials-quote-mark-color` · `--testimonials-author-color` · `--testimonials-meta-color` |

**There is no `--testimonials-body-measure`.** The `--<component>-body-measure` family
covers `section`, `cta`, `faq` and `embed` **only** — testimonials is not among them, so
do not assume a measure retune reaches this component's text.

## Stated defaults (and what would reopen them)

These values are deliberate product defaults, not oversights, and are not authorable.
Each names the condition that would reopen the decision. Adding a control needs a
**named incident** — a real composition that could not be built — not a hypothesis.

| Default | Why it is a default | What would reopen it |
|---|---|---|
| Avatar size `2.75rem` (44px) | An attribution avatar is an **identifying thumbnail, not an image surface**. At 44px it sits level with the author/role/company text block without competing with the quote, which is the element the band exists to show. | **An operator supplies real author portraits and finds them illegible at 44px, or wants a photo-forward testimonial treatment.** The remedy is already shaped — a `--testimonials-avatar-size` slot mirroring `--grid-item-icon-size` and `--logos-image-size` — so only the incident is missing. |
| Avatar `border-radius: 50%` | 50% is a **shape identity, not a scale value** — circular is the avatar convention. **Deliberately NOT routed to `--radius`:** that token is the card-corner scale, so binding avatars to it would turn portraits into squircles the moment someone retunes card corners. | A squared-avatar treatment is requested. |
| Avatar `object-fit: cover` | An avatar is a **crop model by definition** — a face should fill the circle. This is the deliberate contrast with `logos`, whose `object-fit: contain` is a **fit** model, which is why logos has no focal-point or aspect-ratio controls and this does not need them either. | None foreseeable. |
| The opening quote mark: glyph fixed to `\201C` (a left double quotation mark), `font-size: 1.75em` | Decoration whose **colour is already authorable** (`--testimonials-quote-mark-color`), and whose size is expressed in `em`, so it scales with the quote's own type automatically — no slot is needed to keep it proportional. Note the deliberate mismatch: the gutter it sits in (`padding-left: 1.75rem`) is a `rem`, so the reserved space does **not** scale the way the glyph does. | The named-incident bar. A different glyph would be a glyph question, not a size one. |
| `layout: "stack"` quote `font-size: 1.375rem`, at every viewport including 375px | Testimonials type is **viewport-invariant by ratified intent** — the stack layout is a pull-quote, and shrinking it on mobile would make it read as body copy. Carried here so the record is complete, not to reopen that decision. | That intentional difference's own condition. |
| `layout: "stack"` list `max-width: 42rem` | `stack` is the one testimonials layout that is a **reading surface** rather than a card grid, and `42rem` is that reading measure. | An operator uses `stack` for a long-form testimonial and the measure is wrong for their type scale. The remedy would be a `--testimonials-body-measure` slot joining the four that exist — see the note above about testimonials being outside that family today. |

## CSS

Styles in `assets/css/components.css` under the `COMPONENT: testimonials` block. Base `blockquote` styling (border-left accent, italic, muted color) lives in `base.css` since `blockquote` is a standard HTML element other components can pass through `wp_kses_post()`; the component overrides it for card presentation.
