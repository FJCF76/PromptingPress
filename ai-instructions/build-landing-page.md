# Build a Complete Landing Page

End-to-end guide to creating a new landing page using PromptingPress components.

---

## Step 1 — Create the template file

Create `/templates/landing-page.php`:

```php
<?php
/**
 * Template Name: Landing Page
 *
 * templates/landing-page.php — Custom landing page with full component stack.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {

    // Hero — the first thing visitors see
    pp_get_component('hero', [
        'title'    => pp_field('lp_hero_title')    ?: 'Your Compelling Headline Here',
        'subtitle' => pp_field('lp_hero_subtitle') ?: 'One sentence that explains what you do and who it is for.',
        'cta_text' => pp_field('lp_cta_text')      ?: 'Get Started',
        'cta_url'  => pp_field('lp_cta_url')       ?: '#contact',
        'variant'  => 'centered',
    ]);

    // What is it — narrative section
    pp_get_component('section', [
        'title'     => pp_field('lp_section_title')     ?: 'What Makes This Different',
        'body'      => pp_field('lp_section_body')      ?: '<p>Describe the core problem and how your product solves it. Be specific. Avoid generic phrases like "all-in-one solution."</p>',
        'image_url' => pp_field('lp_section_image_url') ?: '',
        'image_alt' => pp_field('lp_section_image_alt') ?: '',
        'layout'    => pp_field('lp_section_layout')    ?: 'text-only',
    ]);

    // Feature grid — real content cards, not decoration
    $grid_items = pp_field('lp_features') ?: [
        [
            'title' => 'Feature One',
            'text'  => 'Describe this feature with enough specificity that a prospect understands what it actually does.',
        ],
        [
            'title' => 'Feature Two',
            'text'  => 'Another real feature. Avoid icons-in-circles with 2-line descriptions.',
        ],
        [
            'title' => 'Feature Three',
            'text'  => 'Third feature. Three is a good number for a landing page grid.',
        ],
    ];

    pp_get_component('grid', [
        'title' => pp_field('lp_features_title') ?: 'Key Features',
        'items' => $grid_items,
    ]);

    // FAQ — answer the questions that stop people from converting
    $faq_items = pp_field('lp_faq_items') ?: [
        [
            'question' => 'How does this work?',
            'answer'   => 'Explain the mechanism clearly.',
        ],
        [
            'question' => 'What does it cost?',
            'answer'   => 'Be direct. Vague pricing information reduces conversions.',
        ],
        [
            'question' => 'Do I need [common prerequisite]?',
            'answer'   => 'Address the most common blocker for your audience.',
        ],
    ];

    pp_get_component('faq', [
        'title' => pp_field('lp_faq_title') ?: 'Common Questions',
        'items' => $faq_items,
    ]);

    // Final CTA — make it easy to take the next step
    pp_get_component('cta', [
        'title'       => pp_field('lp_final_cta_title')  ?: 'Ready to get started?',
        'text'        => pp_field('lp_final_cta_text')   ?: 'One sentence reinforcing the value.',
        'button_text' => pp_field('lp_final_cta_button') ?: 'Start Now',
        'button_url'  => pp_field('lp_final_cta_url')    ?: '#contact',
        'variant'     => 'full-width',
    ]);

});
```

---

## Step 2 — Create the root loader

Create `/landing-page.php` at the theme root:

```php
<?php
/*
 * Template Name: Landing Page
 */
get_template_part('templates/landing-page');
```

---

## Step 3 — Assign the template in WP Admin

1. Go to **Pages → Add New**
2. Give the page a title (e.g. "Home" or "Landing")
3. In **Page Attributes → Template**, select **Landing Page**
4. Publish the page
5. To use it as the homepage: **Settings → Reading → A static page → Front page → [select your page]**

---

## Step 4 — Fill in ACF fields (if ACF is installed)

Create an ACF field group with these fields and assign it to this template:

| Field name           | Type      | Label |
|----------------------|-----------|-------|
| lp_hero_title        | Text      | Hero Title |
| lp_hero_subtitle     | Textarea  | Hero Subtitle |
| lp_cta_text          | Text      | CTA Button Text |
| lp_cta_url           | URL       | CTA Button URL |
| lp_section_title     | Text      | Section Title |
| lp_section_body      | WYSIWYG   | Section Body |
| lp_section_image_url | URL       | Section Image URL |
| lp_section_image_alt | Text      | Section Image Alt |
| lp_section_layout    | Select    | Layout (text-only / image-left / image-right) |
| lp_features_title    | Text      | Features Heading |
| lp_features          | Repeater  | Features (sub-fields: title, text, image_url, link_url, link_text) |
| lp_faq_title         | Text      | FAQ Heading |
| lp_faq_items         | Repeater  | FAQ Items (sub-fields: question, answer) |
| lp_final_cta_title   | Text      | Final CTA Title |
| lp_final_cta_text    | Text      | Final CTA Body |
| lp_final_cta_button  | Text      | Final CTA Button Label |
| lp_final_cta_url     | URL       | Final CTA Button URL |

**Without ACF:** The page renders with the hardcoded fallback defaults. No plugin required.

---

## Step 5 — Write real copy

Replace every fallback default in the template with specific, concrete content. Avoid:

- "Welcome to [Site]"
- "Your all-in-one solution for..."
- "Unlock the power of..."
- "Discover the difference"

Use: customer-specific language, concrete feature descriptions, and real pricing.

---

## Customization

- **Reorder components:** Rearrange the `pp_get_component()` calls inside `pp_base_template()`
- **Remove a component:** Delete that `pp_get_component()` call
- **Add a component:** Insert a new `pp_get_component()` call (see `ai-instructions/add-component.md`)
- **Restyle:** Edit `assets/css/base.css` tokens (see `ai-instructions/retheme.md`)
