<?php
/**
 * tests/bootstrap.php — PHPUnit Bootstrap for PromptingPress
 *
 * Sets up Brain\Monkey for WP function mocking and defines constants.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Define WP_DEBUG for tests that exercise debug-mode branches.
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

// Stub get_template_directory() so component loader can resolve paths
// without a real WordPress install. Returns the theme root.
if (!function_exists('get_template_directory')) {
    function get_template_directory(): string {
        return dirname(__DIR__);
    }
}

// Minimal WP stubs needed by lib/wp.php and lib/helpers.php.
// Brain\Monkey provides a Mockery-based approach, but for simple
// file-level tests these global stubs keep the test surface thin.

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string {
        $data = [
            'name'        => 'Test Site',
            'description' => 'Test Description',
            'charset'     => 'UTF-8',
        ];
        return $data[$show] ?? '';
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://example.com' . $path;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(int $post = 0): string {
        return 'Test Post Title';
    }
}

if (!function_exists('get_the_content')) {
    function get_the_content(): string {
        return '<p>Test content.</p>';
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value) {
        return $value;
    }
}

if (!function_exists('get_the_excerpt')) {
    function get_the_excerpt(): string {
        return 'Test excerpt text for unit testing purposes.';
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words(string $text, int $num_words = 55): string {
        $words = explode(' ', $text);
        return implode(' ', array_slice($words, 0, $num_words));
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(): string {
        return 'https://example.com/test-post/';
    }
}

if (!function_exists('get_the_post_thumbnail_url')) {
    function get_the_post_thumbnail_url($post = null, string $size = 'thumbnail'): string {
        return 'https://example.com/image.jpg';
    }
}

if (!function_exists('get_body_class')) {
    function get_body_class(): array {
        return ['home', 'page'];
    }
}

if (!function_exists('is_front_page')) {
    function is_front_page(): bool {
        return false;
    }
}

if (!function_exists('wp_nav_menu')) {
    function wp_nav_menu(array $args = []): void {
        echo '<ul><li><a href="#">Test Link</a></li></ul>';
    }
}

if (!function_exists('wp_reset_postdata')) {
    function wp_reset_postdata(): void {}
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string {
        return $content;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string {
        return strip_tags($text);
    }
}

// Load the theme library files.
require_once dirname(__DIR__) . '/lib/wp.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/components.php';
