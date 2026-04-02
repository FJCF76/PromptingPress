# Component: stats

A horizontal row of large-number metrics with labels. Use for quantified social proof or credential statements on marketing pages.

## Props

| Prop    | Type   | Required | Default | Description |
|---------|--------|----------|---------|-------------|
| `title` | string | No       | `''`    | Optional heading above the stats row |
| `items` | array  | Yes      | —       | Array of `{ number, label }` objects |

Each item:

| Key      | Type   | Required | Description |
|----------|--------|----------|-------------|
| `number` | string | Yes      | The metric value, e.g. `'+30'` or `'100+'` |
| `label`  | string | Yes      | The metric label, e.g. `'Years of experience'` |

## Usage

```php
pp_get_component('stats', [
    'items' => [
        ['number' => '+30', 'label' => 'Years of experience'],
        ['number' => '100+', 'label' => 'Satisfied clients'],
        ['number' => '15',   'label' => 'Countries'],
        ['number' => '500+', 'label' => 'Candidates interviewed'],
    ],
]);
```

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: stats === */`.
