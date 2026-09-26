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
 * THE POSTS PAGE AS A COMPOSITION (#1181). When the page chosen as the posts page
 * stores a non-empty composition, that composition renders instead, through the same
 * band loop as every other composed page. Its listing is a grid band with
 * `items_source: "posts"`, whose cards are this route's main query. With nothing
 * stored, a corrupt row, or a posts page this visitor may not see (draft, pending,
 * private, or password-protected without the password), the bands below render exactly
 * as before — byte for byte.
 * pp_posts_page_composition() is also what the head's emitter reads, so the CSS and
 * the markup resolve the route the same way.
 *
 * No WordPress functions are called here — only pp_* wrappers from lib/wp.php.
 */

require_once get_template_directory() . '/templates/base.php';

$pp_posts_page = pp_posts_page_composition();
if ($pp_posts_page !== []) {
    pp_base_template(function () use ($pp_posts_page) {
        // The posts page is the current post while its bands render (on this route core's
        // current post is a blog post of the listing).
        pp_render_composition_bands($pp_posts_page, pp_posts_page_id());
    });
    return;
}

pp_base_template(function () {

    pp_get_component('hero', [
        'title'   => 'Blog',
        'layout' => 'left',
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

}, ['hero', 'grid', 'section']);
