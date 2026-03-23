<?php
/**
 * templates/front-page.php — Homepage Template
 *
 * Default component order (editable — see AI_CONTEXT.md):
 *   nav → hero → section → grid → faq → cta → footer
 *
 * All data comes from pp_field() (ACF) with sensible fallback defaults.
 * No WordPress functions are called here — only pp_* wrappers from lib/wp.php.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {

    // ── Hero ──────────────────────────────────────────────────────────────
    pp_get_component('hero', [
        'title'    => pp_field('hero_title')    ?? 'Build AI-Ready WordPress Sites',
        'subtitle' => pp_field('hero_subtitle') ?? 'PromptingPress is a WordPress theme designed so any AI tool can read one file, understand your entire site, and edit it safely — without knowing WordPress internals.',
        'cta_text' => pp_field('hero_cta_text') ?? 'See How It Works',
        'cta_url'  => pp_field('hero_cta_url')  ?? pp_site_url('#how-it-works'),
        'variant'  => 'centered',
    ]);

    // ── What is this — text + image section ───────────────────────────────
    pp_get_component('section', [
        'title'     => pp_field('section_title')     ?? 'The AI Comprehension Problem',
        'body'      => pp_field('section_body')      ?? '<p>WordPress themes are designed for developers who accumulate knowledge over time. AI can\'t accumulate. Every session, it re-infers the same hidden logic from your code.</p><p>PromptingPress solves this by wrapping all WordPress calls in a thin abstraction layer, typing every component with a machine-readable schema, and providing a single <code>AI_CONTEXT.md</code> that maps the entire site structure in seconds.</p>',
        'image_url' => pp_field('section_image_url') ?? '',
        'image_alt' => pp_field('section_image_alt') ?? '',
        'layout'    => pp_field('section_layout')    ?? 'text-only',
    ]);

    // ── Feature grid ──────────────────────────────────────────────────────
    $grid_items = pp_field('grid_items') ?? [
        [
            'title'     => 'WP Abstraction Layer',
            'text'      => 'lib/wp.php is the only file that calls WordPress functions. Templates use pp_site_title(), pp_field(), pp_permalink() — stable contracts an AI can learn once.',
            'image_url' => '',
            'link_url'  => '',
            'link_text' => '',
        ],
        [
            'title'     => 'Typed Component Props',
            'text'      => 'Every component ships with schema.json. Props are validated in WP_DEBUG mode. Missing a required prop? You get a warning, not a white screen.',
            'image_url' => '',
            'link_url'  => '',
            'link_text' => '',
        ],
        [
            'title'     => 'AI_CONTEXT.md',
            'text'      => 'A single file maps the entire site: component index, file responsibilities, ACF fields, and current pages. AI reads it first and edits with confidence.',
            'image_url' => '',
            'link_url'  => '',
            'link_text' => '',
        ],
        [
            'title'     => 'CI Invariant Enforcement',
            'text'      => 'GitHub Actions checks that no raw WP functions appear in templates, every component has schema.json, and no raw hex values exist in components.css.',
            'image_url' => '',
            'link_url'  => '',
            'link_text' => '',
        ],
        [
            'title'     => 'Zero-JS FAQ Accordion',
            'text'      => 'The faq component uses native HTML details/summary — no JavaScript required. Accessible by default, keyboard-navigable, and screen-reader friendly.',
            'image_url' => '',
            'link_url'  => '',
            'link_text' => '',
        ],
        [
            'title'     => 'Retheme in One File',
            'text'      => '16 CSS custom properties in base.css control the entire visual system. Change the 7 color tokens to retheme the site. AI can do this without touching a single component.',
            'image_url' => '',
            'link_url'  => '',
            'link_text' => '',
        ],
    ];

    pp_get_component('grid', [
        'title' => pp_field('grid_title') ?? 'How It Works',
        'items' => $grid_items,
    ]);

    // ── FAQ ───────────────────────────────────────────────────────────────
    $faq_items = pp_field('faq_items') ?? [
        [
            'question' => 'Does this theme require ACF?',
            'answer'   => 'No. The pp_field() wrapper returns null if ACF is not installed. All components accept fallback values so the page renders without any plugin installed.',
        ],
        [
            'question' => 'Can I use this with Gutenberg or a page builder?',
            'answer'   => 'PromptingPress is intentionally incompatible with Gutenberg blocks and page builders. The theme is a frontend framework — WordPress handles the backend. Mixing both approaches undermines the AI comprehension guarantee.',
        ],
        [
            'question' => 'How does an AI actually edit this theme?',
            'answer'   => 'Claude Code reads CLAUDE.md automatically on startup. That file instructs it to read AI_CONTEXT.md next, which maps every template, component, and file responsibility. From there, the AI can add a component, create a page, or retheme the site in one session.',
        ],
        [
            'question' => 'What happens when I add a component?',
            'answer'   => 'Create a folder at components/myname/ with myname.php, README.md, and schema.json. The auto-loader picks it up — no registration needed. Call pp_get_component(\'myname\', [...]) from any template.',
        ],
        [
            'question' => 'Is this production-ready?',
            'answer'   => 'Yes. The theme uses standard WordPress enqueue hooks, registers nav menus, adds html5 theme support, and respects wp_head() and wp_footer() calls required by all plugins.',
        ],
    ];

    pp_get_component('faq', [
        'title' => pp_field('faq_title') ?? 'Frequently Asked Questions',
        'items' => $faq_items,
    ]);

    // ── CTA ───────────────────────────────────────────────────────────────
    pp_get_component('cta', [
        'title'       => pp_field('cta_title')       ?? 'Ready to build your AI-ready site?',
        'text'        => pp_field('cta_text')         ?? 'Start with the theme, fill in AI_CONTEXT.md, and let your AI tool do the rest.',
        'button_text' => pp_field('cta_button_text')  ?? 'Get Started on GitHub',
        'button_url'  => pp_field('cta_button_url')   ?? 'https://github.com/FJCF76/PromptingPress',
        'variant'     => 'full-width',
    ]);

});
