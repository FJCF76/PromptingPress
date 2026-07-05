<?php
/**
 * templates/home.php — Blog Posts Index Template
 *
 * Renders the site's posts index (is_home): a grid of recent posts with
 * pagination. Without this template, WordPress's home.php → index.php
 * template hierarchy fell through to templates/page.php, which renders
 * pp_page_title()/pp_page_content() against the global $post — on the
 * posts index that global is the FIRST post in the main query, so the
 * blog index rendered that one post's title as a hero and its full
 * content as the body instead of a listing (#126).
 *
 * No WordPress functions are called here — only pp_* wrappers from lib/wp.php.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {

    pp_get_component('hero', [
        'title'   => 'Blog',
        'variant' => 'left',
    ]);

    // Iterate WordPress's own main query for this route (#126) — already
    // correctly built and paginated for the posts index.
    $items = [];
    pp_the_loop(pp_main_query(), function () use (&$items) {
        $items[] = [
            'title'     => pp_page_title(),
            'text'      => pp_excerpt(25),
            'image_url' => pp_thumbnail_url('medium'),
            'link_url'  => pp_permalink(),
            'link_text' => 'Read post',
        ];
    });

    if (!empty($items)) {
        pp_get_component('grid', [
            'items' => $items,
        ]);
        $pagination = pp_pagination();
        if ($pagination !== '') {
            echo '<div class="container">' . $pagination . '</div>';
        }
    } else {
        pp_get_component('section', [
            'body'   => '<p>No posts found.</p>',
            'layout' => 'text-only',
        ]);
    }

});
