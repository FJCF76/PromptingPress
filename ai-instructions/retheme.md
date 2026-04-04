# Retheme PromptingPress

Follow these steps to change the visual design of the site. You can change the entire color scheme, fonts, shape language, and spacing scale by editing a single file.

---

## Step 1 — Edit the 8 color tokens in assets/css/base.css

Open `/assets/css/base.css`. Find the `:root` block and change these 8 properties:

```css
--color-bg:           #ffffff;  /* Page background */
--color-surface:      #f9fafb;  /* Card / component backgrounds */
--color-text:         #1a1a1a;  /* Primary text */
--color-muted:        #6b7280;  /* Secondary text, captions */
--color-border:       #e5e7eb;  /* Dividers, outlines */
--color-accent:       #0055cc;  /* Primary action color */
--color-accent-hover: #0044aa;  /* Hover / active state */
--color-bg-inverted:  #1a1a1a;  /* Section variant: inverted bg (semantic opposite of --color-bg) */
```

**WCAG AA requirement:** `--color-accent` on `--color-bg` must have contrast ratio ≥ 4.5:1.
Check at https://webaim.org/resources/contrastchecker/

Example retheme — warm neutral:
```css
--color-bg:           #fefefe;
--color-surface:      #f5f0eb;
--color-text:         #1c1917;
--color-muted:        #78716c;
--color-border:       #e7e0d8;
--color-accent:       #b45309;
--color-accent-hover: #92400e;
--color-bg-inverted:  #1c1917;
```

---

## Step 2 — Replace the font tokens

Still in `assets/css/base.css`, change:

```css
--font-body:    system-ui, sans-serif;
--font-heading: system-ui, sans-serif;
```

Replace `system-ui, sans-serif` with your chosen web font name, e.g.:

```css
--font-body:    'Inter', system-ui, sans-serif;
--font-heading: 'Playfair Display', Georgia, serif;
```

---

## Step 3 — Enqueue the font in functions.php

Open `functions.php`. Inside the `wp_enqueue_scripts` action, add a `wp_enqueue_style` call:

**Google Fonts example:**
```php
wp_enqueue_style(
    'pp-google-fonts',
    'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Playfair+Display:wght@700&display=swap',
    [],
    null
);
```

**Bunny Fonts (GDPR-friendly) example:**
```php
wp_enqueue_style(
    'pp-bunny-fonts',
    'https://fonts.bunny.net/css?family=inter:400,600,700|playfair-display:700',
    [],
    null
);
```

Add this before the `pp-base` enqueue so the font loads first.

---

## Step 4 — Adjust --radius for shape language

In `assets/css/base.css`:

```css
--radius: 0.375rem;  /* Current: subtle rounding */
```

| Value      | Effect |
|------------|--------|
| `0`        | Sharp, geometric corners |
| `0.375rem` | Subtle rounding (default) |
| `0.75rem`  | Noticeable rounding |
| `1rem`     | Rounded cards |
| `9999px`   | Pill-shaped buttons (use only on .btn, not cards) |

---

## Step 5 — Verify no raw hex remains in components.css

Run this command to check that no hex colors were accidentally introduced:

```bash
grep -P '#[0-9a-fA-F]{3,6}(?![0-9a-fA-F])' assets/css/components.css
```

The output should be empty. If it returns matches, replace each with the corresponding CSS variable from `base.css`.

---

## What NOT to touch

| File                     | Reason |
|--------------------------|--------|
| components/*.php         | Changing colors/fonts in PHP would bypass the token system |
| lib/*.php                | WP abstraction — not styling |
| templates/*.php          | Page layout — not styling |
| schema.json files        | Machine-readable contracts — not styling |
| functions.php            | Only add the font enqueue, nothing else |

The entire visual output of the site flows through the 18 CSS variables. Editing anything outside `assets/css/base.css` is unnecessary for a retheme.
