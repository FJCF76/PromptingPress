<?php
/**
 * templates/single.php — Single Post Template
 *
 * Renders a single blog post: hero with post title, post content, back link.
 * No WordPress functions are called here — only pp_* wrappers from lib/wp.php.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {

    pp_get_component('hero', [
        'title'     => pp_page_title(),
        'image_url' => pp_thumbnail_url('large'),
        'variant'   => 'left',
    ]);

    pp_get_component('section', [
        'body'   => pp_page_content(),
        'layout' => 'text-only',
    ]);

    pp_get_component('cta', [
        'title'       => 'More from the blog',
        'button_text' => '&larr; Back to all posts',
        'button_url'  => pp_site_url('/blog'),
        'variant'     => 'inline',
    ]);

});
