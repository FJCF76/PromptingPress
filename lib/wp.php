<?php
/**
 * lib/wp.php — PromptingPress WP Abstraction Layer
 *
 * THE ONLY file that calls WordPress functions directly.
 * Templates and components call ONLY these pp_* wrappers.
 * This is the stable contract — AI edits templates freely using these functions.
 */

/**
 * Returns the site name.
 */
function pp_site_title(): string {
    return get_bloginfo('name');
}

/**
 * Returns the site tagline/description.
 */
function pp_site_description(): string {
    return get_bloginfo('description');
}

/**
 * Returns an absolute URL, optionally with a path appended.
 */
function pp_site_url(string $path = ''): string {
    return home_url($path);
}

/**
 * Returns the current page/post title.
 */
function pp_page_title(): string {
    return get_the_title();
}

/**
 * Returns the current page/post content with standard WP filters applied.
 */
function pp_page_content(): string {
    return apply_filters('the_content', get_the_content());
}

/**
 * Returns an ACF field value, or null if ACF is not installed.
 *
 * @param string          $name  ACF field name.
 * @param int|string|null $id    Optional post/option ID.
 */
function pp_field(string $name, $id = null) {
    if (function_exists('get_field')) {
        return get_field($name, $id);
    }
    return null;
}

/**
 * Renders a registered WP nav menu by theme location.
 * Outputs nothing when the location has no assigned menu (fallback_cb false).
 *
 * @param string $location  Theme location slug (e.g. 'primary', 'footer').
 */
function pp_nav_menu(string $location): void {
    wp_nav_menu([
        'theme_location' => $location,
        'container'      => false,
        'fallback_cb'    => false,
    ]);
}

/**
 * Returns a WP_Query object for the given args.
 *
 * @param array $args  WP_Query args.
 * @return \WP_Query
 */
function pp_posts(array $args = []): \WP_Query {
    return new \WP_Query($args);
}

/**
 * Iterates a WP_Query using a callback and resets post data after.
 *
 * @param \WP_Query $query
 * @param callable  $cb    Called once per post with the global post set.
 */
function pp_the_loop(\WP_Query $query, callable $cb): void {
    try {
        while ($query->have_posts()) {
            $query->the_post();
            $cb();
        }
    } finally {
        wp_reset_postdata();
    }
}

/**
 * Returns true when the current page is the configured front page.
 */
function pp_is_front_page(): bool {
    return is_front_page();
}

/**
 * Returns space-separated body classes for the current page.
 */
function pp_body_classes(): string {
    return implode(' ', get_body_class());
}

/**
 * Returns a trimmed excerpt for the current post.
 *
 * @param int $length  Word count (default 55).
 */
function pp_excerpt(int $length = 55): string {
    return wp_trim_words(get_the_excerpt(), $length);
}

/**
 * Returns the permalink for the current post.
 */
function pp_permalink(): string {
    return (string) get_permalink();
}

/**
 * Returns the post thumbnail URL for the current post.
 *
 * @param string $size  Image size name (default 'large').
 */
function pp_thumbnail_url(string $size = 'large'): string {
    return (string) get_the_post_thumbnail_url(null, $size);
}
