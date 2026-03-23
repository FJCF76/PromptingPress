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
- Use `esc_html()` for all text output
- Use `esc_url()` for all URLs
- Use `esc_attr()` for all HTML attributes
- Use `wp_kses_post()` for HTML content
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
  padding-top: var(--space-xl);
  padding-bottom: var(--space-xl);
}

.mycomponent__title {
  margin-bottom: var(--space-md);
}

.mycomponent__body {
  color: var(--color-muted);
}
```

**Rule:** Only CSS variables from `base.css`. No raw hex values.

---

## Step 6 — Call it from a template

In any template file (e.g. `templates/front-page.php`):

```php
pp_get_component('mycomponent', [
    'title' => pp_field('mycomponent_title') ?: 'My Section',
    'text'  => pp_field('mycomponent_text')  ?: '<p>Default content.</p>',
]);
```

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
- [ ] All text output uses `esc_html()` or `wp_kses_post()`
- [ ] `AI_CONTEXT.md` component index updated
