# Add a New Component

Follow these steps to add a new reusable component to PromptingPress.
The auto-loader picks up any component at `/components/{name}/{name}.php` — no registration needed.

---

## Step 1 — Create the component directory

```bash
mkdir components/mycomponent
```

Replace `mycomponent` with your component name (lowercase, no hyphens — use underscores if needed).

---

## Step 2 — Create the component PHP file

Create `/components/mycomponent/mycomponent.php`:

```php
<?php
/**
 * components/mycomponent/mycomponent.php
 *
 * Brief description of what this component renders.
 * Props: see schema.json
 *
 * @var array $props
 */

// Declare all props at the top with defaults.
$title = $props['title'] ?? 'Default Title';
$text  = $props['text']  ?? '';
$link  = $props['link']  ?? '';
?>
<section class="mycomponent">
    <div class="container">
        <?php if ($title) : ?>
            <h2 class="mycomponent__title"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <?php if ($text) : ?>
            <div class="mycomponent__body">
                <?php echo wp_kses_post($text); ?>
            </div>
        <?php endif; ?>

        <?php if ($link) : ?>
            <a href="<?php echo esc_url($link); ?>" class="mycomponent__link btn">
                Learn more
            </a>
        <?php endif; ?>
    </div>
</section>
```

**Rules:**
- Declare all `$props` variables at the top with `??` defaults
- Use `esc_html()` for plain-text output (titles, labels, button text)
- Use `esc_url()` for all URLs
- Use `esc_attr()` for all HTML attributes
- Use `wp_kses_post()` for rich HTML content (the main prose surface: body/answer)
- Use `pp_kses_inline()` for supporting-text props that allow a link + light emphasis (a, strong, em, br) but no block elements (#439)
- Do NOT call WordPress functions directly — use `pp_*` wrappers from `lib/wp.php`
- Do NOT call other components from within a component

---

## Step 3 — Create schema.json

Create `/components/mycomponent/schema.json`:

```json
{
  "component": "mycomponent",
  "description": "One-sentence description of what this component does.",
  "props": {
    "title": {
      "type": "string",
      "required": false,
      "default": "Default Title",
      "description": "Main heading."
    },
    "text": {
      "type": "string",
      "required": false,
      "default": "",
      "description": "Body HTML content."
    },
    "link": {
      "type": "string",
      "required": false,
      "default": "",
      "description": "URL for the link button."
    }
  },
  "safe_to_edit": ["mycomponent.php", "../../assets/css/components.css (COMPONENT: mycomponent section)"],
  "do_not_touch": ["schema.json without updating README.md"]
}
```

**Required keys:** `component`, `description`, `props`.

---

## Step 4 — Create README.md

Create `/components/mycomponent/README.md`:

```markdown
# Component: mycomponent

One-sentence description. When to use it.

## Props

| Prop    | Type   | Required | Default           | Description |
|---------|--------|----------|-------------------|-------------|
| `title` | string | No       | `'Default Title'` | Main heading |
| `text`  | string | No       | `''`              | Body HTML |
| `link`  | string | No       | `''`              | Link URL |

## Usage

...example call...

## CSS

Styles in `assets/css/components.css` under `/* === COMPONENT: mycomponent === */`.
```

---

## Step 5 — Add CSS

Open `/assets/css/components.css` and add a labeled section at the bottom:

```css
/* === COMPONENT: mycomponent === */

.mycomponent {
  /* A band-level (full-width section) component shares ONE rhythm definition
     with the others (section, grid, cta, stats, faq, testimonials). Route its
     vertical padding through the component's own slot, falling back to the
     shared --pp-band-padding, so it agrees with every other band by default and
     a site-wide rhythm retune (--pp-band-padding) moves it too — never paste a
     bare rhythm literal (issue 431). */
  padding-top: var(--mycomponent-padding-top, var(--pp-band-padding));
  padding-bottom: var(--mycomponent-padding-bottom, var(--pp-band-padding));
}

.mycomponent__title {
  /* A band-level title shares ONE responsive heading scale with every other band
     title (issue 436). Route its font-size through the component's own size slot,
     falling back to the shared --pp-band-heading-size, so it never collapses to
     body size on mobile and reads as a peer of adjacent band titles. Never fall
     back to `inherit` (that silently discards the scale) or a bare literal. */
  font-size: var(--mycomponent-heading-size, var(--pp-band-heading-size));
  margin-bottom: var(--space-md);
}

.mycomponent__body {
  color: var(--color-muted);
}
```

**Rule:** No raw hex values and no raw rhythm literals — use CSS variables:
each component's own authorable slots (`--mycomponent-padding-*`) falling back to
`base.css` tokens. Band-level components take their default vertical rhythm from
`--pp-band-padding` (never a per-component literal), so all sections share one
spacing model. Band titles do the same on the typography axis: their `font-size`
falls back to `--pp-band-heading-size` (never `inherit`, never a per-component
literal), so every band heading shares one responsive scale and never collapses
to body size on mobile.

---

## Step 6 — Call it from a template

In any template file (e.g. `templates/front-page.php`):

```php
pp_get_component('mycomponent', [
    'title' => pp_field('mycomponent_title') ?: 'My Section',
    'text'  => pp_field('mycomponent_text')  ?: '<p>Default content.</p>',
]);
```

> **If you call it from `templates/base.php`, it is site chrome, and you are not done.**
> `base.php` runs on every page, so a component rendered there is *also* placeable in a page
> composition unless you say otherwise — the page would then render it twice while every
> validator reports success. That was issue #223.
>
> Declare it: add the name to `pp_template_owned_components()` in `lib/admin.php`, and its menu
> location to `pp_template_owned_menu_locations()` in `lib/wp.php` if it reads one.
> `pp_validate_composition()` will then reject it from `_pp_composition` with
> `template_owned_component` on every write-time path, and it will be dropped from the catalog
> the AI reads. `restore_composition` is the one deliberate exception (#233): it replays stored
> history, so it writes the chrome and reports it as a finding rather than refusing the restore.
>
> The drift guards in `tests/NavReadinessTest.php` read `base.php` back and fail if you forget.
> Calling it from any other template (`front-page.php`, `single.php`, …) needs none of this.

---

## Step 7 — Update AI_CONTEXT.md

Add a row to the Component index table in `AI_CONTEXT.md`:

```
| mycomponent | components/mycomponent/mycomponent.php | Description | key_props |
```

---

## Verification checklist

- [ ] `components/mycomponent/mycomponent.php` exists
- [ ] `components/mycomponent/schema.json` exists and is valid JSON
- [ ] `components/mycomponent/README.md` exists
- [ ] CSS section added to `assets/css/components.css`
- [ ] No raw hex values in the new CSS section
- [ ] No direct WordPress function calls in the PHP file
- [ ] All text output uses `esc_html()` (plain), `pp_kses_inline()` (inline subset), or `wp_kses_post()` (rich), per the prop's documented contract
- [ ] `AI_CONTEXT.md` component index updated
