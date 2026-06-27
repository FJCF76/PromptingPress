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

// PP_VERSION — normally defined in functions.php, which isn't loaded by tests.
if (!defined('PP_VERSION')) {
    define('PP_VERSION', '0.8.0');
}

// ── WP_Error stub ───────────────────────────────────────────────────────────
// Minimal stub for action layer tests. Supports get_error_message/code.
if (!class_exists('WP_Error')) {
    class WP_Error {
        protected string $code;
        protected string $message;

        public function __construct(string $code = '', string $message = '') {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }
}

// ── Stateful in-memory store for write-path stubs ───────────────────────────
// Action layer tests need get_post_meta to return what update_post_meta wrote.
// Clear $GLOBALS['_pp_test_store'] in setUp() for test isolation.
if (!isset($GLOBALS['_pp_test_store'])) {
    $GLOBALS['_pp_test_store'] = [
        'post_meta' => [],
        'posts'     => [],
        'options'   => [],
        'next_id'   => 100,
    ];
}

// Stub get_template_directory() so component loader can resolve paths
// without a real WordPress install. Returns the theme root.
// Apply tests can override via $GLOBALS['_pp_test_template_dir'].
if (!function_exists('get_template_directory')) {
    function get_template_directory(): string {
        return $GLOBALS['_pp_test_template_dir'] ?? dirname(__DIR__);
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
    function get_the_title($post = 0): string {
        $id = is_int($post) ? $post : 0;
        if ($id && isset($GLOBALS['_pp_test_store']['posts'][$id]['post_title'])) {
            return $GLOBALS['_pp_test_store']['posts'][$id]['post_title'];
        }
        return 'Test Post Title';
    }
}

if (!function_exists('get_the_content')) {
    function get_the_content(): string {
        return '<p>Test content.</p>';
    }
}

if (!function_exists('apply_filters')) {
    // Override-aware stub: tests can register a return value per filter tag via
    // $GLOBALS['_pp_test_store']['filters'][$tag]. Without an override, returns
    // the passed default (the normal "no callbacks attached" behavior).
    function apply_filters(string $tag, $value, ...$args) {
        $overrides = $GLOBALS['_pp_test_store']['filters'] ?? [];
        if (array_key_exists($tag, $overrides)) {
            $override = $overrides[$tag];
            return is_callable($override) ? $override($value, ...$args) : $override;
        }
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
    function get_permalink($post = 0): string {
        $id = is_int($post) ? $post : 0;
        if ($id) {
            return 'https://example.com/?page_id=' . $id;
        }
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

// ── Write-path stubs (stateful via $GLOBALS['_pp_test_store']) ───────────────

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false) {
        $store = $GLOBALS['_pp_test_store']['post_meta'];
        if ($key === '') {
            return $store[$post_id] ?? [];
        }
        $value = $store[$post_id][$key] ?? ($single ? '' : []);
        return $value;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $key, $value): bool {
        $GLOBALS['_pp_test_store']['post_meta'][$post_id][$key] = $value;
        return true;
    }
}

// ── Navigation stubs (stateful via $GLOBALS['_pp_test_store']) ───────────────
// Drive nav state per test:
//   ['registered_nav_menus'] => ['primary' => 'Primary', ...]  (default: primary + footer)
//   ['nav_menu_locations']   => ['primary' => 5]               (location => menu_id; empty = none assigned)
//   ['nav_menu_items']       => [5 => [ ...items ]]            (menu_id => items; [] = empty menu)
if (!function_exists('get_registered_nav_menus')) {
    function get_registered_nav_menus(): array {
        return $GLOBALS['_pp_test_store']['registered_nav_menus']
            ?? ['primary' => 'Primary Navigation', 'footer' => 'Footer Navigation'];
    }
}

if (!function_exists('get_nav_menu_locations')) {
    function get_nav_menu_locations(): array {
        return $GLOBALS['_pp_test_store']['nav_menu_locations'] ?? [];
    }
}

if (!function_exists('has_nav_menu')) {
    function has_nav_menu(string $location): bool {
        return isset($GLOBALS['_pp_test_store']['nav_menu_locations'][$location]);
    }
}

if (!function_exists('wp_get_nav_menu_items')) {
    function wp_get_nav_menu_items($menu) {
        return $GLOBALS['_pp_test_store']['nav_menu_items'][$menu] ?? false;
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array {
        $results = [];
        foreach ($GLOBALS['_pp_test_store']['posts'] as $id => $data) {
            if (isset($args['meta_key'], $args['meta_value'])) {
                $meta = $GLOBALS['_pp_test_store']['post_meta'][$id][$args['meta_key']] ?? null;
                if ($meta !== $args['meta_value']) {
                    continue;
                }
            }
            if (isset($args['post_status']) && is_array($args['post_status'])) {
                if (!in_array($data['post_status'] ?? 'draft', $args['post_status'], true)) {
                    continue;
                }
            }
            $post = (object) array_merge(['ID' => $id], $data);
            $results[] = $post;
        }
        return $results;
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $args, bool $wp_error = false) {
        $id = $GLOBALS['_pp_test_store']['next_id']++;
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'post_type'   => $args['post_type'] ?? 'post',
            'post_title'  => $args['post_title'] ?? '',
            'post_status' => $args['post_status'] ?? 'draft',
        ];
        return $id;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array $args, bool $wp_error = false) {
        $id = $args['ID'] ?? 0;
        if (!$id || !isset($GLOBALS['_pp_test_store']['posts'][$id])) {
            if ($wp_error) {
                return new WP_Error('invalid_post', 'Post not found.');
            }
            return 0;
        }
        foreach ($args as $key => $value) {
            if ($key !== 'ID') {
                $GLOBALS['_pp_test_store']['posts'][$id][$key] = $value;
            }
        }
        return $id;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false) {
        return $GLOBALS['_pp_test_store']['options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool {
        $GLOBALS['_pp_test_store']['options'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $key): bool {
        unset($GLOBALS['_pp_test_store']['options'][$key]);
        return true;
    }
}

if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data) {
        return (is_array($data) || is_object($data)) ? serialize($data) : $data;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data) {
        if (is_string($data)) {
            $trimmed = trim($data);
            if ($trimmed !== '') {
                $result = @unserialize($trimmed);
                if ($result !== false || $trimmed === serialize(false)) {
                    return $result;
                }
            }
        }
        return $data;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('wp_slash')) {
    // No-op: real WP adds slashes then update_post_meta strips them.
    // Our stubs don't strip, so wp_slash must be transparent.
    function wp_slash($value) {
        return $value;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return trim(strip_tags($str));
    }
}

if (!function_exists('get_the_ID')) {
    function get_the_ID(): int {
        return 0;
    }
}

if (!function_exists('get_preview_post_link')) {
    function get_preview_post_link(int $post_id = 0): string {
        return 'https://example.com/?preview=true&page_id=' . $post_id;
    }
}

// ── WordPress hook/registration stubs ──────────────────────────────────────
// Needed so lib/admin.php can be loaded without a real WP environment.

if (!function_exists('add_action')) {
    function add_action(string $tag, $callback, int $priority = 10, int $accepted_args = 1): void {}
}

if (!function_exists('add_filter')) {
    function add_filter(string $tag, $callback, int $priority = 10, int $accepted_args = 1): void {}
}

if (!function_exists('register_post_meta')) {
    function register_post_meta(string $post_type, string $meta_key, array $args): bool { return true; }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null): void {}
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, int $status = 200): void {}
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1): bool { return true; }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool { return true; }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true): bool { return true; }
}

if (!function_exists('add_meta_box')) {
    function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $args = null): void {}
}

if (!function_exists('add_menu_page')) {
    function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null): string { return ''; }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null): string { return ''; }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string { return 'https://example.com/wp-admin/' . $path; }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all'): void {}
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false): void {}
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n): bool { return true; }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1): string { return 'test_nonce'; }
}

if (!function_exists('get_post')) {
    function get_post($post = null) {
        $id = is_object($post) ? $post->ID : (int) $post;
        if (!$id || !isset($GLOBALS['_pp_test_store']['posts'][$id])) {
            return null;
        }
        $data = $GLOBALS['_pp_test_store']['posts'][$id];
        $obj = new WP_Post();
        $obj->ID = $id;
        $obj->post_type = $data['post_type'] ?? 'page';
        $obj->post_title = $data['post_title'] ?? '';
        $obj->post_status = $data['post_status'] ?? 'draft';
        return $obj;
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type($post = null): string { return 'page'; }
}

if (!function_exists('get_page_template_slug')) {
    function get_page_template_slug($post = null): string {
        return $GLOBALS['_pp_test_store']['page_template_slug'] ?? 'composition.php';
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = 'page';
        public string $post_title = '';
        public string $post_status = 'draft';
    }
}

if (!function_exists('wp_trash_post')) {
    function wp_trash_post(int $post_id) {
        if (!isset($GLOBALS['_pp_test_store']['posts'][$post_id])) {
            return false;
        }
        $GLOBALS['_pp_test_store']['posts'][$post_id]['post_status'] = 'trash';
        return $GLOBALS['_pp_test_store']['posts'][$post_id];
    }
}

if (!function_exists('wp_untrash_post')) {
    function wp_untrash_post(int $post_id) {
        if (!isset($GLOBALS['_pp_test_store']['posts'][$post_id])) {
            return false;
        }
        // WordPress restores to the status before trashing (stored in _wp_trash_status_post_meta).
        // For tests, restore to 'draft' as the safe default.
        $GLOBALS['_pp_test_store']['posts'][$post_id]['post_status'] = 'draft';
        return true;
    }
}

// ── wp_get_upload_dir stub ────────────────────────────────────────────────
if (!function_exists('wp_get_upload_dir')) {
    function wp_get_upload_dir(): array {
        return [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => sys_get_temp_dir() . '/pp-test-uploads',
        ];
    }
}

// ── WP_Query stub (supports meta_query IN for attachment lookup) ──────────
if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $posts = [];

        public function __construct(array $args = []) {
            // Support meta_query IN comparison for _wp_attached_file lookups.
            if (
                isset($args['post_type']) && $args['post_type'] === 'attachment'
                && isset($args['meta_query'][0]['key'])
                && $args['meta_query'][0]['key'] === '_wp_attached_file'
                && isset($args['meta_query'][0]['compare'])
                && $args['meta_query'][0]['compare'] === 'IN'
            ) {
                $search_values = $args['meta_query'][0]['value'] ?? [];
                foreach ($GLOBALS['_pp_test_store']['post_meta'] as $post_id => $meta) {
                    if (isset($meta['_wp_attached_file']) && in_array($meta['_wp_attached_file'], $search_values, true)) {
                        $this->posts[] = $post_id;
                    }
                }
            }
        }
    }
}

// ABSPATH stub for target discovery.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

// WP_CONTENT_DIR stub for apply layer backup tests.
// Individual tests can override get_template_directory() behavior
// by setting $GLOBALS['_pp_test_template_dir'].
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pp-test-content-' . getmypid());
}

// ── WP 7.0 Connector stubs ───────────────────────────────────────────────────
// Tests control connector state via $GLOBALS['_pp_test_store']['connectors'].

if (!function_exists('wp_get_connectors')) {
    function wp_get_connectors(): array {
        return $GLOBALS['_pp_test_store']['connectors'] ?? [];
    }
}

if (!function_exists('wp_get_connector')) {
    function wp_get_connector(string $id): ?array {
        $connectors = wp_get_connectors();
        return $connectors[$id] ?? null;
    }
}

// ── Stubs for AI layer ────────────────────────────────────────────────────────

if (!function_exists('get_template_directory_uri')) {
    function get_template_directory_uri(): string {
        return 'https://example.com/wp-content/themes/promptingpress';
    }
}

if (!function_exists('get_attached_file')) {
    function get_attached_file(int $attachment_id): string {
        return '/var/www/wp-content/uploads/image-' . $attachment_id . '.jpg';
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachment_id): string {
        return 'https://example.com/wp-content/uploads/image-' . $attachment_id . '.jpg';
    }
}

if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata(int $attachment_id): array {
        return ['width' => 1200, 'height' => 800];
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, bool $echo = true): string {
        $result = ($selected == $current) ? ' selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('esc_js')) {
    function esc_js(string $text): string {
        return addslashes($text);
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string {
        return 'https://example.com' . $path;
    }
}

if (!function_exists('register_setting')) {
    function register_setting(string $option_group, string $option_name, array $args = []): void {}
}

if (!function_exists('add_settings_section')) {
    function add_settings_section(string $id, string $title, $callback, string $page, array $args = []): void {}
}

if (!function_exists('add_settings_field')) {
    function add_settings_field(string $id, string $title, $callback, string $page, string $section = 'default', array $args = []): void {}
}

if (!function_exists('settings_fields')) {
    function settings_fields(string $option_group): void {}
}

if (!function_exists('do_settings_sections')) {
    function do_settings_sections(string $page): void {}
}

if (!function_exists('submit_button')) {
    function submit_button(string $text = 'Save Changes'): void { echo "<button>{$text}</button>"; }
}

// ── Shortcode stub ──────────────────────────────────────────────────────

if (!function_exists('do_shortcode')) {
    function do_shortcode(string $content): string {
        return $content;
    }
}

// ── Admin screen stub ──────────────────────────────────────────────────────

if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        return $GLOBALS['_pp_test_store']['current_screen'] ?? null;
    }
}

// ── Custom CSS stubs ──────────────────────────────────────────────────────

if (!function_exists('wp_get_custom_css')) {
    function wp_get_custom_css(): string {
        return $GLOBALS['_pp_test_store']['custom_css'] ?? '';
    }
}

if (!function_exists('wp_get_custom_css_post')) {
    function wp_get_custom_css_post() {
        $css = $GLOBALS['_pp_test_store']['custom_css'] ?? '';
        if (!$css) {
            return null;
        }
        $post = new WP_Post();
        $post->ID = 999;
        $post->post_content = $css;
        return $post;
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format): string {
        return date($format);
    }
}

// ── WP-Cron stubs (stateful via $GLOBALS['_pp_test_store']['cron']) ──────────
// Mirror the options-store pattern so cron scheduling/unscheduling is testable.

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []) {
        return $GLOBALS['_pp_test_store']['cron'][$hook] ?? false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool {
        // Faithful to real WP: a second call DOES schedule again (WP does not
        // dedup). We count calls so idempotency tests prove the caller's
        // wp_next_scheduled guard rather than a stub that silently dedups.
        $GLOBALS['_pp_test_store']['cron_calls'][$hook] =
            ($GLOBALS['_pp_test_store']['cron_calls'][$hook] ?? 0) + 1;
        $GLOBALS['_pp_test_store']['cron'][$hook] = $timestamp;
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): int {
        $existed = isset($GLOBALS['_pp_test_store']['cron'][$hook]) ? 1 : 0;
        unset($GLOBALS['_pp_test_store']['cron'][$hook]);
        return $existed;
    }
}

// Load the theme library files.
require_once dirname(__DIR__) . '/lib/wp.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/components.php';
require_once dirname(__DIR__) . '/lib/admin.php';
require_once dirname(__DIR__) . '/lib/guardrails.php';
require_once dirname(__DIR__) . '/lib/actions.php';
require_once dirname(__DIR__) . '/lib/apply.php';
require_once dirname(__DIR__) . '/lib/operate.php';
require_once dirname(__DIR__) . '/lib/screenshot.php';
require_once dirname(__DIR__) . '/lib/ai-context.php';
require_once dirname(__DIR__) . '/lib/ai-provider.php';
require_once dirname(__DIR__) . '/lib/ai-chat.php';
require_once dirname(__DIR__) . '/lib/post-apply-validate.php';
require_once dirname(__DIR__) . '/lib/setup.php';
