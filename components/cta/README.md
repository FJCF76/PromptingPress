# Component: cta

Call-to-action block. Place at the bottom of a page or between sections to drive a conversion action.

## Props

| Prop          | Type   | Required | Default        | Description |
|---------------|--------|----------|----------------|-------------|
| `title`       | string | Yes      | —              | CTA headline |
| `text`        | string | No       | `''`           | Supporting body text |
| `button_text` | string | Yes      | —              | Button label |
| `button_url`  | string | Yes      | —              | Button URL |
| `variant`     | enum   | No       | `'full-width'` | Layout variant |

## Variants

- **full-width** — Centered block with `--color-surface` background. Used at section breaks.
- **inline** — Flex row: text on left, button on right. Used for inline nudges (e.g. "back to archive").

## Usage

```php
// End-of-page conversion block
pp_get_component('cta', [
    'title'       => 'Ready to build your AI-ready site?',
    'text'        => 'Start with the theme, fill in AI_CONTEXT.md, and let your AI tool do the rest.',
    'button_text' => 'Get Started on GitHub',
    'button_url'  => 'https://github.com/FJCF76/PromptingPress',
    'variant'     => 'full-width',
]);

// Inline back link on single post
pp_get_component('cta', [
    'title'       => 'More from the blog',
    'button_text' => '← Back to all posts',
    'button_url'  => pp_site_url('/blog'),
    'variant'     => 'inline',
]);
```

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: cta === */`.
