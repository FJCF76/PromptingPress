# PromptingPress — AI Context

## Quick orientation (read this first)

This is a WordPress site using the PromptingPress theme. WordPress handles the backend
(admin, database, media, plugins). This theme handles the frontend rendering only.

**To add a page:** Create or edit a file in `/templates/`. Call `pp_get_component()` for
each section. Register the template in WP Admin (Pages → Edit → Page Attributes → Template).

**To edit a component:** Open `/components/{name}/{name}.php`. Props are documented in
`schema.json` in the same folder. CSS is in `/assets/css/components.css`.

**To add a component:** Follow the steps in `ai-instructions/add-component.md`. The
auto-loader picks up any component at `/components/{name}/{name}.php` — no registration needed.

**To retheme:** Read `ai-instructions/retheme.md`. Edit the 16 CSS tokens in `assets/css/base.css`.

**Never:**
- Add hooks or filters to template or component files (only in `functions.php`)
- Call WordPress functions directly in templates or components (use `pp_*` wrappers from `lib/wp.php`)
- Edit `lib/components.php` (it is the stable loader contract)
- Add raw hex values to `components.css` (use CSS variables only)

---

## File responsibility

| File/Folder              | Purpose                         | Safe to edit?                    |
|--------------------------|---------------------------------|----------------------------------|
| /templates/              | Page layouts                    | Yes                              |
| /components/             | Reusable sections               | Yes                              |
| /assets/css/base.css     | Design tokens (16 CSS vars)     | Yes — tokens only                |
| /assets/css/components.css | Component styles              | Yes                              |
| /assets/css/utilities.css | Spacing / text utilities       | Yes                              |
| /assets/js/main.js       | Nav toggle, active link         | Yes                              |
| /lib/wp.php              | WP function wrappers            | Only to add pp_* functions       |
| /lib/components.php      | Component loader                | No                               |
| /lib/helpers.php         | Utility functions               | Yes — only to add                |
| functions.php            | WP registration                 | Only to add                      |
| style.css                | Theme header (WP requirement)   | No                               |
| CLAUDE.md                | Claude Code instructions        | Only to update invariants        |
| AI_CONTEXT.md            | This file — AI site map         | Keep current when structure changes |

---

## Component index

| Component | File                           | Description                                      | Key props                                          |
|-----------|--------------------------------|--------------------------------------------------|----------------------------------------------------|
| hero      | components/hero/hero.php       | Full-width headline + optional CTA and image     | title (req), subtitle, cta_text, cta_url, variant  |
| section   | components/section/section.php | Text + optional image. 3 layout variants         | body (req), title, image_url, image_alt, layout    |
| faq       | components/faq/faq.php         | Native details/summary accordion. Zero JS.       | items[] (req) {question, answer}, title            |
| grid      | components/grid/grid.php       | Responsive card grid for real content objects    | items[] (req) {title, text, image_url, link_url, link_text}, title |
| table     | components/table/table.php     | Data/comparison table, horizontal scroll mobile  | headers[] (req), rows[][] (req), title, caption    |
| cta       | components/cta/cta.php         | Call-to-action block. Two variants.              | title (req), button_text (req), button_url (req), text, variant |
| nav       | components/nav/nav.php         | Site header, logo, hamburger mobile nav          | location, logo_text                                |
| footer    | components/footer/footer.php   | Site footer with nav menu and copyright          | location                                           |

---

## Calling a component

```php
pp_get_component('hero', [
    'title'    => pp_field('hero_title') ?: 'Welcome',
    'subtitle' => pp_field('hero_subtitle'),
    'cta_text' => pp_field('hero_cta_text') ?: 'Get Started',
    'cta_url'  => pp_field('hero_cta_url')  ?: '#',
    'variant'  => 'centered',
]);
```

---

## WP abstraction layer (lib/wp.php)

All functions are prefixed `pp_`. Templates and components use only these wrappers.

| Function                      | Returns                                         |
|-------------------------------|-------------------------------------------------|
| `pp_site_title()`             | Site name (get_bloginfo)                        |
| `pp_site_description()`       | Site tagline                                    |
| `pp_site_url($path)`          | Home URL with optional path                     |
| `pp_page_title()`             | Current post/page title                         |
| `pp_page_content()`           | Current post content with WP filters applied    |
| `pp_field($name, $id)`        | ACF field value, or null if ACF not installed   |
| `pp_nav_menu($location)`      | Renders WP nav menu (no output if unassigned)   |
| `pp_posts($args)`             | Returns WP_Query object                         |
| `pp_the_loop($query, $cb)`    | Iterates query, calls $cb() per post            |
| `pp_is_front_page()`          | bool — true on front page                       |
| `pp_body_classes()`           | Space-separated body class string               |
| `pp_excerpt($length)`         | Trimmed excerpt (default 55 words)              |
| `pp_permalink()`              | Current post permalink                          |
| `pp_thumbnail_url($size)`     | Post thumbnail URL (default 'large')            |

---

## Page templates

| Template file         | Root loader       | WP Admin template name |
|-----------------------|-------------------|------------------------|
| templates/front-page.php | front-page.php | (set as front page in Settings → Reading) |
| templates/page.php    | page.php          | Default Template       |
| templates/single.php  | single.php        | (automatic for posts)  |
| templates/archive.php | archive.php       | (automatic for archives) |

---

## WordPress fields (ACF)

Fields used on the front page (templates/front-page.php):

| Field name         | Component | Fallback default |
|--------------------|-----------|-----------------|
| hero_title         | hero      | 'Build AI-Ready WordPress Sites' |
| hero_subtitle      | hero      | (see template) |
| hero_cta_text      | hero      | 'See How It Works' |
| hero_cta_url       | hero      | site URL + '#how-it-works' |
| section_title      | section   | 'The AI Comprehension Problem' |
| section_body       | section   | (see template) |
| section_image_url  | section   | '' (text-only) |
| section_image_alt  | section   | '' |
| section_layout     | section   | 'text-only' |
| grid_title         | grid      | 'How It Works' |
| grid_items         | grid      | 6 hardcoded feature items |
| faq_title          | faq       | 'Frequently Asked Questions' |
| faq_items          | faq       | 5 hardcoded FAQ items |
| cta_title          | cta       | 'Ready to build your AI-ready site?' |
| cta_text           | cta       | (see template) |
| cta_button_text    | cta       | 'Get Started on GitHub' |
| cta_button_url     | cta       | GitHub repo URL |

All pp_field() calls return null when ACF is not installed. Every template provides
fallback defaults so the site renders correctly without ACF.

---

## Design tokens (assets/css/base.css)

16 CSS custom properties control the entire visual system. To retheme, edit these only.

```
Colors:     --color-bg, --color-surface, --color-text, --color-muted,
            --color-border, --color-accent, --color-accent-hover
Spacing:    --space-xs, --space-sm, --space-md, --space-lg, --space-xl, --space-2xl
Typography: --font-body, --font-heading
Shape:      --radius, --max-width, --transition
```

See `ai-instructions/retheme.md` for the full retheme workflow.
