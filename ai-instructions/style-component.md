# Style a Component Instance

Use the `style_component` action to change the visual appearance of a specific component instance without editing CSS files. Style overrides are stored in the composition alongside props and survive theme updates.

---

## Step 1 -- Inspect available style slots

```bash
wp pp operate inspect-composition <page_id_or_slug>
```

The output shows each component's available `style_slots` (name, type, default, current value) and `available_recipes` (named shorthand).

---

## Step 2 -- Apply style slots

**Direct slot values:**
```bash
wp pp action execute style_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "style": {
    "--hero-bg": "#1a1a2e",
    "--hero-text": "#f0f0f0",
    "--hero-padding-top": "8rem"
  }
}'
```

**Using a recipe:**
```bash
wp pp action execute style_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "recipe": "dark-spacious"
}'
```

**Recipe + overrides (recipe expands first, then explicit values win):**
```bash
wp pp action execute style_component --run-id=<uuid> --params='{
  "post_id": 19,
  "component_id": "pp-a1b2c3d4",
  "recipe": "dark-spacious",
  "style": {
    "--hero-title-size": "clamp(3rem, 6vw, 5rem)"
  }
}'
```

---

## Step 3 -- Verify

```bash
wp pp operate inspect-composition <page_id_or_slug>
```

Check that `current` values reflect your changes and the `active_recipe` shows correctly.

---

## Semantics

- **PATCH merge:** Style slots merge with existing values. Unspecified slots are unchanged.
- **Remove a slot:** Set its value to `null` to remove the override and revert to the global token default.
- **Clear all style:** Pass `"style": {}` to remove all overrides.
- **Validation:** Only schema-declared slots are accepted. Invalid slot names or values are rejected with descriptive errors.

---

## Slot types

| Type | Examples | Validator |
|------|----------|-----------|
| `color` | `#1a1a2e`, `rgb(26, 26, 46)`, `var(--color-bg)` | `_pp_validate_color()` |
| `length` | `8rem`, `50%`, `clamp(3rem, 6vw, 5rem)`, `calc(100% - 2rem)`, `0` | `_pp_validate_length()` |
| `number` | `700`, `1.5` | `_pp_validate_number()` |
| `shadow` | `var(--shadow-sm)`, `var(--shadow-md)`, `var(--shadow-lg)`, `none`, `0 4px 12px rgba(0,0,0,0.1)` | `_pp_validate_shadow()` |

The `shadow` type is bounded: a preset (`var(--shadow-none\|sm\|md\|lg)` or `none`)
or a single-layer `box-shadow` (2-4 px/rem lengths plus an rgb/rgba/hsl/hsla color).
`inset`, multi-layer shadows, and `url()` are rejected. The hero, section, grid (card),
and cta components each expose namespaced `*-border-color`, `*-border-width`, `*-radius`,
and `*-shadow` slots.

> **Button and text styling are PROPS, not style slots.** A CTA's button style
> (`button_variant`: primary/secondary/outline/ghost) and a grid item's typography
> role (`text_role`: mono/meta/label/kicker) are set with `update_component` (props),
> not `style_component`. `style_component` only accepts schema-declared style slots.

---

## Available recipes (v1)

| Component | Recipe | Description |
|-----------|--------|-------------|
| hero | `dark-spacious` | Dark background with generous padding |
| hero | `compact` | Reduced padding for tighter layouts |
| hero | `bold-headline` | Oversized title with dark bg |
| section | `accent-panel` | Accent-tinted background with border |
| section | `spacious-editorial` | Wide body with generous padding |
| grid | `dark-showcase` | Dark background with light cards |
| grid | `dense-cards` | Compact card layout with tight spacing |
| cta | `dark-bold` | Dark background with large title |
| cta | `accent-framed` | Accent border with rounded corners |

---

## What NOT to do

- Do not edit `assets/css/components.css` to change per-instance appearance -- use style slots
- Do not add inline styles in component PHP files -- the style system handles this
- Do not set style slots that aren't declared in the component's schema.json
- Do not use `var()` inside `clamp()` or `calc()` expressions -- this is blocked for security
