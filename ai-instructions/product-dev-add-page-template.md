# Add a New Page Template (product development)

> **THIS IS NOT HOW YOU ADD A PAGE TO A SITE.**
>
> A site's pages are **compositions** — a JSON array of bands, created with the
> `create_page` action and styled through each band's `udc` map. Nothing on this page is
> involved, and you do not need a template file, a root loader, or ACF to add one.
> Start at `ai-instructions/build-landing-page.md`, with
> `ai-instructions/composition.md` for the format.
>
> This file documents a **product-development** task: adding a new PHP page template to
> the THEME itself, which is a release-level change to the product rather than a change
> to a site. You need it only if you are building a new kind of page the composition
> model cannot express — which, since every band is composable and every v2 band is
> styleable through `udc`, is rare. If you are about to reach for it to build a landing
> page, a marketing page or any ordinary site page, you are in the wrong file.

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
        'layout'  => 'centered',
    ]);

    pp_get_component('section', [
        'title' => 'Section Title',
        'body'  => pp_page_content(),
        'layout' => 'text-only',
    ]);

    // Add more components here, and name each one in the list below
    // pp_get_component('grid', [...]);
    // pp_get_component('cta', [...]);

}, ['hero', 'section']);
```

**The list after the callable is required.** It names every component the callable
renders, by the same names as its `pp_get_component()` calls. The v2 role defaults (a
component's padding, type scale, card fill and border) are printed in the page head,
before the callable runs, so the head learns what to print from this list. A component
missing from it renders as bare, unstyled markup. `tests/TemplateBandDefaultsTest.php`
fails when a template's list and its calls disagree, and when a call names its component
through a variable, a callable string, an include or a template part instead of a literal.

That test also pins the SET of templates it checks, so a new template file fails it until you
add the file's path to the subject list in
`testEveryTemplateDeclaresExactlyTheComponentsItRendersByLiteralName()` and raise its count of
templates that render by name. That is deliberate: a new template has to be looked at, not
silently scanned or skipped.

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
- Name every component you render in `pp_base_template()`'s second argument, and call each by a literal name
- Do not call WordPress functions directly. Use lib/wp.php wrappers.
- Do not add `add_action()` or `add_filter()` in template files.
- Provide fallback values for all `pp_field()` calls so the page renders without ACF.

---

## Components available

See `AI_CONTEXT.md` → Component index for the full list of available components and their props.
