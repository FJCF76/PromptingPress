<?php
/**
 * templates/front-page.php — Homepage Template
 *
 * Composition-aware: reads _pp_composition post meta and renders components
 * in order, identical to templates/composition.php. The homepage has no
 * hardcoded component structure — it is fully editable through the same
 * JSON composition system as any other page.
 *
 * To edit the homepage composition:
 *   - WP Admin → Pages → (front page) → Page Composition meta box
 *   - WP CLI: wp post meta update <id> _pp_composition '[...]'
 *   - AI: see ai-instructions/composition.md
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {
    $composition = pp_composition();

    if (empty($composition)) {
        // Fallback: seed the default composition into post meta so the page
        // is never blank after a fresh install or theme switch.
        $default = [
            ['component' => 'hero', 'props' => [
                'title'    => 'Build AI-Ready WordPress Sites',
                'subtitle' => 'PromptingPress is a WordPress theme designed so any AI tool can read one file, understand your entire site, and edit it safely.',
                'cta_text' => 'See How It Works',
                'cta_url'  => '#how-it-works',
                'variant'  => 'centered',
            ]],
            ['component' => 'section', 'props' => [
                'title'  => 'The AI Comprehension Problem',
                'body'   => '<p>WordPress themes are designed for developers who accumulate knowledge over time. AI can\'t accumulate. Every session, it re-infers the same hidden logic from your code.</p><p>PromptingPress solves this with a thin abstraction layer, typed component schemas, and a single AI_CONTEXT.md that maps the entire site.</p>',
                'layout' => 'text-only',
            ]],
            ['component' => 'cta', 'props' => [
                'title'       => 'Ready to build your AI-ready site?',
                'text'        => 'Start with the theme, fill in AI_CONTEXT.md, and let your AI tool do the rest.',
                'button_text' => 'Get Started on GitHub',
                'button_url'  => 'https://github.com/FJCF76/PromptingPress',
                'variant'     => 'full-width',
            ]],
        ];

        $post_id = get_the_ID();
        if ($post_id) {
            update_post_meta(
                $post_id,
                '_pp_composition',
                wp_slash(wp_json_encode($default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            );
        }

        $composition = $default;
    }

    foreach ($composition as $item) {
        if (!isset($item['component'])) {
            continue;
        }
        $props = isset($item['props']) && is_array($item['props']) ? $item['props'] : [];
        pp_get_component((string) $item['component'], $props);
    }
});
