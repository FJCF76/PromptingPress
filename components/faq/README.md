# Component: faq

FAQ accordion using native HTML `<details>`/`<summary>` elements. No JavaScript required. Fully accessible: keyboard-navigable and screen-reader friendly out of the box.

## Props

| Prop           | Type   | Required | Default                          | Description |
|----------------|--------|----------|----------------------------------|-------------|
| `id`           | string | No       | `''`                             | HTML id for anchor linking; also becomes the stable component id |
| `title`        | string | No       | `'Frequently Asked Questions'`   | Section heading |
| `title_accent` | string | No       | `''`                             | Exact substring of `title` to render in an accent color |
| `eyebrow`      | string | No       | `''`                             | Short kicker/label rendered as a pill above the title |
| `theme`        | enum   | No       | `'default'`                      | Background tone: `default`, `muted` (light tinted surface band), or `inverted` (genuinely dark band) |
| `items`        | array  | Yes      | —                                | Array of `{ question, answer }` objects |

Each item in `items`:

| Key        | Type   | Required | Description |
|------------|--------|----------|-------------|
| `question` | string | Yes      | The question text shown in the summary/toggle |
| `answer`   | string | Yes      | The answer HTML shown when expanded |

## Usage

```php
pp_get_component('faq', [
    'title' => 'Common Questions',
    'items' => [
        [
            'question' => 'Does this require ACF?',
            'answer'   => 'No. pp_field() returns null when ACF is not installed.',
        ],
        [
            'question' => 'Can I use page builders?',
            'answer'   => '<p>PromptingPress is intentionally incompatible with page builders.</p>',
        ],
    ],
]);
```

## Accessibility

- Uses `<details>`/`<summary>` — browser-native accessibility. No ARIA attributes needed.
- Keyboard: `Enter` or `Space` toggles open/closed. `Tab` navigates between items.
- Empty state shows a friendly message rather than an empty section.

## Structured data

Emits a `FAQPage` JSON-LD `<script>` block as the **last child inside the
component's own `<section>`**, not after it — zero-config, no toggle. An item whose
question or answer is empty is skipped, and since #742 so is one whose value is
DAMAGED — a non-scalar question/answer, or an entry that is not an array — which
degrades to empty and is therefore skipped by that same rule rather than fataling the
page or reaching the payload as the literal word `Array`. When no item survives, no
`<script>` is emitted at all. A `<script>` is
metadata content, valid anywhere in the body flow, and Google reads `ld+json` from
anywhere in the DOM, so SEO is unaffected. The placement is load-bearing for layout,
not for SEO: emitted as a trailing *sibling* of `</section>` the script became the
previous element sibling of the next band, so the `main > [data-pp-component] + .band`
adjacency selector missed that band and it fell back to its own larger top padding
(#432). `question`/`answer` are stripped of any HTML before encoding (Google's FAQPage
schema expects plain text). Items missing a question or answer are skipped; nothing is
emitted if no complete items exist. See `pp_render_faq_schema()` in `lib/wp.php`.

## Style slots

21 per-instance style slots, declared in `schema.json` under `styling.style_slots`
and set with the `style_component` action. This table is the map — read each slot's
`type`, effective `default`, `applies_when` condition and full description from the
schema itself, or with `wp pp operate inspect-composition --post_id=<id>`.

`◦` = conditional (`applies_when`): setting it outside that configuration is accepted
and stored but paints nothing, and `wp pp check page` reports a non-blocking
`inert_slot` smell.

| Group | Slots |
|---|---|
| Band | `--faq-padding-top` · `--faq-padding-bottom` · `--faq-bg` |
| Heading | `--faq-heading-size` ◦ · `--faq-heading-color` ◦ · `--faq-heading-accent-color` ◦ · `--faq-heading-measure` ◦ · `--faq-heading-margin-bottom` ◦ |
| Eyebrow | `--faq-eyebrow-color` ◦ · `--faq-eyebrow-bg` ◦ · `--faq-eyebrow-radius` ◦ · `--faq-eyebrow-border-width` ◦ · `--faq-eyebrow-border-color` ◦ · `--faq-eyebrow-text-transform` ◦ |
| Accordion item | `--faq-item-bg` ◦ · `--faq-item-border-color` ◦ · `--faq-item-radius` ◦ |
| Question and answer | `--faq-question-color` ◦ · `--faq-question-open-color` ◦ · `--faq-answer-color` ◦ · `--faq-body-measure` ◦ |

## Stated defaults (and what would reopen them)

These values are deliberate product defaults, not oversights, and are not authorable.
Each names the condition that would reopen the decision. Adding a control needs a
**named incident** — a real composition that could not be built — not a hypothesis.

| Default | Why it is a default | What would reopen it |
|---|---|---|
| The disclosure chevron: `10px` box, `2px` stroke | The chevron is a **control affordance, not a style surface**. Its **ink is already authorable for free**: the borders are drawn in `currentColor`, so the chevron follows `--faq-question-color` at rest and `--faq-question-open-color` when open, with no slot of its own. Only the box and the stroke width are literal, and the box sits inside the ruled 44px touch target the question already reserves for WCAG 2.5.5. | An operator needs a different disclosure **glyph** (plus/minus, caret) — a glyph question, not a size one. |
| Question type `1rem` / `560` / `1.45` and answer type `1rem` / `430` / `1.68` — the composed-page (`main > .faq`) scale at 768px and up | **Ink is slotted, the scale is not, and that is the design.** `--faq-question-color` and `--faq-answer-color` are live slots; the type pair exists to distinguish question from answer **at identical size**, using weight (560 vs 430) and leading (1.45 vs 1.68) alone. That non-standard weight pair is the whole mechanism — a question that is bigger as well as bolder reads as a heading, which an accordion row is not. | An operator needs FAQ body type to diverge from the composed-page body scale. |
| Mobile question `0.98rem` / `1.42` at 767px and below | The roughly 2% trim is what keeps a long question to two lines inside the 44px touch target on a 375px screen. Note the whole composed-page FAQ type block is viewport-tiered — this row is the one place the QUESTION size itself moves. | As above. |
| The open animation `faq-open 150ms ease` | Duration is not authorable and there is no recorded incident asking for it to be. `base.css` already collapses animation and transition durations globally under `prefers-reduced-motion`, so the accessibility case is covered without a per-component control. | A named incident where one band's disclosure timing must differ. |

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: faq === */`.

The `.faq__item[open] > .faq__question` selector applies the open-state accent color.

## What NOT to change

- Do not replace details/summary with a JavaScript accordion. The native element is more accessible and requires no JS maintenance.
- Do not add raw hex colors. Use CSS variables.
