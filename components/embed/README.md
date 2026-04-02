# Component: embed

A generic content embed block. Passes the `content` prop through `do_shortcode()` and `wp_kses_post()`. Use for WP plugin shortcodes (contact forms, calendars, payment widgets) that belong to WP rather than to the PromptingPress composition model.

This is the only sanctioned way to introduce arbitrary plugin-rendered content into a composition. It is explicit and deliberate — not a workaround.

## Props

| Prop      | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| `title`   | string | No       | `''`    | Optional heading above the embedded content |
| `content` | string | Yes      | —       | WP shortcode or pre-rendered HTML |

## Usage

```php
pp_get_component('embed', [
    'title'   => 'Send your CV',
    'content' => '[contact-form-7 id="123" title="CV Form"]',
]);
```

## Notes

- `content` is passed through `wp_kses_post()` before `do_shortcode()`, so it strips disallowed HTML tags while preserving shortcode brackets.
- If the shortcode plugin is not active, the content renders as plain text or is silently empty.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: embed === */`.
