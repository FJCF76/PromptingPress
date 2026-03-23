# Add a New Page Template

Follow these steps to add a custom page template to PromptingPress.

---

## Step 1 — Create the template file

Create `/templates/my-page.php` (replace `my-page` with your template name):

```php
<?php
/**
 * templates/my-page.php — My Custom Page
 *
 * Describe what this page is for.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {

    pp_get_component('hero', [
        'title'   => pp_field('hero_title') ?: 'Page Title',
        'variant' => 'centered',
    ]);

    pp_get_component('section', [
        'title' => 'Section Title',
        'body'  => pp_page_content(),
        'layout' => 'text-only',
    ]);

    // Add more components here
    // pp_get_component('grid', [...]);
    // pp_get_component('cta', [...]);

});
```

---

## Step 2 — Create the root loader file

Create `/my-page.php` at the theme root (same name as the template file):

```php
<?php get_template_part('templates/my-page'); ?>
```

**Why:** WordPress loads root-level template files. The root file is a thin loader that delegates to the richer `templates/` file.

---

## Step 3 — Register the template as a WordPress page template (optional)

If this template should be selectable from the WP Admin page editor, add a comment header to the template file:

```php
<?php
/**
 * Template Name: My Custom Page
 *
 * templates/my-page.php — My Custom Page
 */
```

WordPress reads the `Template Name:` comment from the root loader file OR from the template file in some configurations. Add it to `/my-page.php` (the root loader) to be safe:

```php
<?php
/*
 * Template Name: My Custom Page
 */
get_template_part('templates/my-page');
```

---

## Step 4 — Assign the template in WP Admin

1. Go to **Pages → Add New** (or edit an existing page)
2. In the **Page Attributes** panel, find **Template**
3. Select **My Custom Page** from the dropdown
4. Save / Publish

---

## Rules to follow

- Only call `pp_get_component()` and `pp_*` functions inside `pp_base_template()`
- Do not call WordPress functions directly. Use lib/wp.php wrappers.
- Do not add `add_action()` or `add_filter()` in template files.
- Provide fallback values for all `pp_field()` calls so the page renders without ACF.

---

## Components available

See `AI_CONTEXT.md` → Component index for the full list of available components and their props.
