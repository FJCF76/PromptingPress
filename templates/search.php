<?php
/**
 * templates/search.php — Search Results Template
 *
 * Renders a search results listing: a heading naming the query, a grid of
 * matching posts/pages with links and excerpts, pagination, and an explicit
 * empty state. Without this template, WordPress's search.php → index.php
 * template hierarchy fell through to templates/page.php, which renders
 * pp_page_title()/pp_page_content() against the global $post — on a search
 * request that global is the first matched result, so a search rendered one
 * result as a full standalone page instead of a results list (issue 138).
 *
 * No WordPress functions are called here — only pp_* wrappers from lib/wp.php.
 */

require_once get_template_directory() . '/templates/base.php';

pp_base_template(function () {

    $query = pp_search_query();
    $count = pp_result_count();

    pp_get_component('hero', [
        'title'   => $count > 0
            ? sprintf('Results for "%s"', $query)
            : sprintf('No results for "%s"', $query),
        'variant' => 'left',
    ]);

    $items = [];
    pp_the_loop(pp_main_query(), function () use (&$items) {
        $items[] = [
            'title'     => pp_page_title(),
            'text'      => pp_excerpt(25),
            'image_url' => pp_thumbnail_url('medium'),
            'link_url'  => pp_permalink(),
            'link_text' => 'View',
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
            'body'   => '<p>Nothing matched your search. Try a different term.</p>',
            'layout' => 'text-only',
        ]);
    }

});
