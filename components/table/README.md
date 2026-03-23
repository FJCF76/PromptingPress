# Component: table

Data or comparison table. Wraps in a `div.table-wrap` for horizontal scroll on mobile. Column headers use `<th scope="col">` for accessibility.

## Props

| Prop      | Type   | Required | Default | Description |
|-----------|--------|----------|---------|-------------|
| `title`   | string | No       | `''`    | Section heading above the table |
| `headers` | array  | Yes      | —       | Array of column header strings |
| `rows`    | array  | Yes      | —       | Array of rows, each an array of cell values |
| `caption` | string | No       | `''`    | Accessible `<caption>` element (recommended) |

## Usage

```php
pp_get_component('table', [
    'title'   => 'Theme Comparison',
    'caption' => 'Comparing PromptingPress to standard starter themes',
    'headers' => ['Feature', 'Normal Theme', 'PromptingPress'],
    'rows'    => [
        ['AI comprehension speed', 'Slow', 'Fast'],
        ['WP functions in templates', 'Yes', 'No — wrapped in lib/wp.php'],
        ['Component schemas', 'None', 'schema.json per component'],
        ['CI invariant checking', 'No', 'GitHub Actions workflow'],
    ],
]);
```

## Accessibility

- Column headers use `scope="col"` attribute.
- Optional `caption` provides context for screen readers.
- Horizontal scroll wrapper keeps the table usable on narrow viewports.

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: table === */`.

Row hover background uses `--color-surface`.
