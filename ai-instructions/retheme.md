# Retheme PromptingPress

Follow these steps to change the visual design of the site. You can change the entire color scheme, fonts, shape language, and spacing scale by editing a single file.

---

## Step 1 — Edit the 8 base color tokens in assets/css/base.css

Open `/assets/css/base.css`. Find the `:root` block and change these 8 properties. When using the programmatic path (`update_design_token`), changing `--color-accent` auto-derives the four accent variants and changing `--color-text` auto-derives `--color-text-secondary`:

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

## Step 3 — Enqueue the font via apply (no file edits needed)

Use the `enqueue_font` apply to add fonts without editing functions.php. Font URLs are stored in the database and survive theme updates.

**Google Fonts example:**
```bash
wp pp apply execute enqueue_font --run-id=<uuid> --params='{"url":"https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Playfair+Display:wght@700&display=swap"}'
```

**Bunny Fonts (GDPR-friendly) example:**
```bash
wp pp apply execute enqueue_font --run-id=<uuid> --params='{"url":"https://fonts.bunny.net/css?family=inter:400,600,700|playfair-display:700"}'
```

Fonts are enqueued before `pp-base` so they load first. Max 5 font URLs. HTTPS only. To remove: `remove_font`. To clear all: `reset_fonts`.

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
| functions.php            | Use `enqueue_font` apply instead of editing directly |

The entire visual output of the site flows through the 33 CSS variables and the apply/action model. Editing files directly is unnecessary for a retheme — use `update_design_token` for global tokens, `enqueue_font` for fonts, and `style_component` for per-instance visual overrides.

---

## Programmatic alternative

Tokens can also be changed via the apply layer without manually editing base.css. Overrides are stored in the database (`pp_token_overrides` option) and survive theme updates:

```bash
wp pp apply execute update_design_token --params='{"token":"--color-accent","value":"#b45309"}'
wp pp apply preview update_design_token --params='{"token":"--color-accent","value":"#b45309"}'  # diff without writing
wp pp apply restore --run-id=<uuid> --token=--color-accent                                       # reset single token to default
wp pp apply restore --run-id=<uuid>                                                               # reset all tokens to defaults
```

Or from PHP: `pp_execute_apply('update_design_token', ['token' => '--color-accent', 'value' => '#b45309'])`.

The apply layer validates token names, enforces type-specific value constraints, and verifies the write via database read-back. Use this path when rethemeing programmatically (e.g. from an AI interface).

**Token family derivation:** When you change `--color-accent`, four derived tokens (`--color-accent-hover`, `--color-accent-strong`, `--color-border-accent`, `--color-surface-accent`) are auto-filled if they have no existing override. Changing `--color-text` auto-derives `--color-text-secondary`. Existing overrides are preserved. If a preserved override's hue drifts significantly from the new base, the apply returns a stale warning so the caller can decide whether to update it.
