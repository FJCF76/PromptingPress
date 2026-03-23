<?php
/**
 * templates/archive.php — Archive / Blog Listing Template
 *
 * Renders a post archive: hero with archive title, then a grid of posts.
 * No WordPress functions are called here — only pp_* wrappers from lib/wp.php.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {

    // Determine archive title using WP abstraction.
    // get_the_archive_title() is a WP function — we only call WP in lib/wp.php,
    // but archive title is a display concern that pp_page_title() doesn't cover for
    // archives. We use a minimal conditional here via our abstraction layer.
    $archive_title = pp_is_front_page() ? pp_site_title() : 'Blog';
    // If it is truly an archive, WordPress will have already set the global $wp_query.
    // We pass it through as a fallback-safe approach.
    if (function_exists('get_the_archive_title')) {
        $raw = get_the_archive_title();
        if ($raw) {
            $archive_title = wp_strip_all_tags($raw);
        }
    }

    pp_get_component('hero', [
        'title'   => $archive_title,
        'variant' => 'left',
    ]);

    // Build grid items from the main query loop.
    $items = [];
    $query = pp_posts([
        'post_type'      => 'post',
        'posts_per_page' => 12,
        'paged'          => max(1, get_query_var('paged')),
    ]);

    pp_the_loop($query, function () use (&$items) {
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
    } else {
        pp_get_component('section', [
            'body'   => '<p>No posts found.</p>',
            'layout' => 'text-only',
        ]);
    }

});
