# Component: faq

FAQ accordion using native HTML `<details>`/`<summary>` elements. No JavaScript required. Fully accessible: keyboard-navigable and screen-reader friendly out of the box.

## Props

| Prop           | Type   | Required | Default                          | Description |
|----------------|--------|----------|----------------------------------|-------------|
| `id`           | string | No       | `''`                             | HTML id for anchor linking; also becomes the stable component id |
| `title`        | string | No       | `'Frequently Asked Questions'`   | Section heading |
| `title_accent` | string | No       | `''`                             | Exact substring of `title` to render in an accent color |
| `eyebrow`      | string | No       | `''`                             | Short kicker/label rendered as a pill above the title |
| `theme`        | enum   | No       | `'default'`                      | Background tone: `default`, `dark`, or `inverted` |
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

Always emits a `FAQPage` JSON-LD `<script>` block immediately after the component's own markup — zero-config, no toggle. `question`/`answer` are stripped of any HTML before encoding (Google's FAQPage schema expects plain text). Items missing a question or answer are skipped; nothing is emitted if no complete items exist. See `pp_render_faq_schema()` in `lib/wp.php`.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: faq === */`.

The `details[open] .faq__question` selector applies the open-state accent color.

## What NOT to change

- Do not replace details/summary with a JavaScript accordion. The native element is more accessible and requires no JS maintenance.
- Do not add raw hex colors. Use CSS variables.
