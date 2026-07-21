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
// Minimal stub for action layer tests. Supports get_error_message/code/data
// (data mirrors core WP_Error's optional third constructor arg).
if (!class_exists('WP_Error')) {
    class WP_Error {
        protected string $code;
        protected string $message;
        protected $data;

        public function __construct(string $code = '', string $message = '', $data = '') {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data(string $code = '') {
            return $this->data;
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
            $slug = $GLOBALS['_pp_test_store']['posts'][$id]['post_name'] ?? '';
            if ($slug !== '') {
                return 'https://example.com/' . $slug . '/';
            }
            return 'https://example.com/?page_id=' . $id;
        }
        return 'https://example.com/test-post/';
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }
}

// #134 slug de-duplication: mirrors WP core's wp_unique_post_slug() division
// of responsibility -- wp_insert_post()/wp_update_post() own de-dup, callers
// (pp_update_page_slug()) just read back the resulting post_name.
if (!function_exists('_pp_test_unique_slug')) {
    function _pp_test_unique_slug(string $slug, ?int $exclude_id = null): string {
        $taken = [];
        foreach ($GLOBALS['_pp_test_store']['posts'] ?? [] as $id => $post) {
            if ($exclude_id !== null && $id === $exclude_id) {
                continue;
            }
            if (($post['post_name'] ?? '') !== '') {
                $taken[] = $post['post_name'];
            }
        }
        if (!in_array($slug, $taken, true)) {
            return $slug;
        }
        $i = 2;
        while (in_array($slug . '-' . $i, $taken, true)) {
            $i++;
        }
        return $slug . '-' . $i;
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

// #126 main-query / pagination stubs. Tests control the current "route"
// query via $GLOBALS['wp_query'] (a WP_Query-like object) directly, and
// the current page number via $GLOBALS['_pp_test_store']['query_vars'].
if (!function_exists('get_query_var')) {
    function get_query_var(string $var, $default = '') {
        return $GLOBALS['_pp_test_store']['query_vars'][$var] ?? $default;
    }
}

if (!function_exists('paginate_links')) {
    function paginate_links(array $args = []) {
        $total   = (int) ($args['total'] ?? 1);
        $current = (int) ($args['current'] ?? 1);
        $type    = $args['type'] ?? 'plain';
        if ($total <= 1) {
            return $type === 'array' ? [] : '';
        }
        $links = [];
        if ($current > 1) {
            $links[] = '<a class="prev page-numbers" href="#">' . ($args['prev_text'] ?? 'Previous') . '</a>';
        }
        for ($i = 1; $i <= $total; $i++) {
            $links[] = $i === $current
                ? '<span aria-current="page" class="page-numbers current">' . $i . '</span>'
                : '<a class="page-numbers" href="#">' . $i . '</a>';
        }
        if ($current < $total) {
            $links[] = '<a class="next page-numbers" href="#">' . ($args['next_text'] ?? 'Next') . '</a>';
        }
        return $type === 'array' ? $links : implode('', $links);
    }
}

// issue 138: search-results stub. Tests set the query string via
// $GLOBALS['_pp_test_store']['search_query'].
if (!function_exists('get_search_query')) {
    function get_search_query(bool $escaped = true): string {
        return $GLOBALS['_pp_test_store']['search_query'] ?? '';
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

/**
 * Behavioral wp_kses() stub for the allowlist-sanitization tests (#439).
 *
 * The real wp_kses is WordPress core and is the PRODUCTION security boundary; the
 * authoritative protocol/XSS proof for pp_kses_inline lives in the E2E suite that
 * renders on a real WordPress. This stub exists so the PHPUnit render tests can
 * exercise the allowlist SHAPE without WordPress: it honors the ($content,
 * $allowed_html) tag/attr map — allowed tags survive (keeping only allowed attrs),
 * every other tag is stripped (delimiters removed, inner text kept, matching core),
 * and href/src values on dangerous protocols (javascript:/vbscript:/data:) are
 * dropped. It deliberately does NOT normalize entities, so genuinely plain text
 * round-trips byte-identically for the "plain text unchanged" assertions. It is a
 * test aid, not a reimplementation of core's sanitizer.
 */
if (!function_exists('wp_kses')) {
    function wp_kses(string $content, $allowed_html, $allowed_protocols = []): string {
        if (!is_array($allowed_html)) {
            return '';
        }
        $allowed = [];
        foreach ($allowed_html as $tag => $attrs) {
            $allowed[strtolower($tag)] = is_array($attrs) ? array_change_key_case($attrs, CASE_LOWER) : [];
        }
        return preg_replace_callback(
            '/<(\/?)([a-zA-Z0-9]+)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)\/?>/',
            static function (array $m) use ($allowed): string {
                $isClose = $m[1] === '/';
                $tag     = strtolower($m[2]);
                if (!array_key_exists($tag, $allowed)) {
                    return ''; // disallowed tag: strip delimiters, keep surrounding text
                }
                if ($isClose) {
                    return '</' . $tag . '>';
                }
                if ($tag === 'br') {
                    return '<br />';
                }
                $out = '<' . $tag;
                if (preg_match_all(
                    '/([a-zA-Z0-9\-:]+)(?:\s*=\s*"([^"]*)"|\s*=\s*\'([^\']*)\')?/',
                    $m[3],
                    $attrMatches,
                    PREG_SET_ORDER
                )) {
                    foreach ($attrMatches as $a) {
                        $name = strtolower($a[1] ?? '');
                        if ($name === '' || empty($allowed[$tag][$name])) {
                            continue; // attr not on the allowlist for this tag
                        }
                        $hasVal = array_key_exists(2, $a) || array_key_exists(3, $a);
                        $val    = $a[2] ?? $a[3] ?? '';
                        if (in_array($name, ['href', 'src'], true) && $hasVal
                            && preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $val)) {
                            continue; // drop dangerous-protocol URL (attr removed entirely)
                        }
                        $out .= $hasVal ? ' ' . $name . '="' . $val . '"' : ' ' . $name;
                    }
                }
                return $out . '>';
            },
            $content
        ) ?? '';
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
        // Real WordPress unslashes the meta value before storing (it expects
        // SLASHED input from the historical magic-quotes contract). Model that
        // here so tests exercise the true store round-trip: code that forgets
        // to wp_slash() a wp_json_encode()'d payload loses its backslashes,
        // exactly as in production (#471). Paired with the wp_slash() stub
        // below, a correctly wp_slash()'d write is a net no-op.
        $GLOBALS['_pp_test_store']['post_meta'][$post_id][$key] = wp_unslash($value);
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
        $items = $GLOBALS['_pp_test_store']['nav_menu_items'][$menu] ?? false;
        if (!is_array($items)) {
            return $items;
        }
        // Real WP orders by menu_order; usort is stable (PHP 8+), so items
        // without a menu_order keep insertion order.
        usort($items, fn($a, $b) => (int) ($a->menu_order ?? 0) <=> (int) ($b->menu_order ?? 0));
        return $items;
    }
}

// issue 132: menu CRUD stubs. Menus live in ['nav_menus'][$id] = ['term_id',
// 'name']; items reuse the existing ['nav_menu_items'][$menu_id] bucket
// above as stdClass objects {ID, title, url}.
if (!function_exists('wp_get_nav_menus')) {
    function wp_get_nav_menus(): array {
        $menus = $GLOBALS['_pp_test_store']['nav_menus'] ?? [];
        return array_map(fn($m) => (object) $m, array_values($menus));
    }
}

if (!function_exists('wp_get_nav_menu_object')) {
    function wp_get_nav_menu_object($menu) {
        $menus = $GLOBALS['_pp_test_store']['nav_menus'] ?? [];
        if (is_numeric($menu) && isset($menus[(int) $menu])) {
            return (object) $menus[(int) $menu];
        }
        foreach ($menus as $m) {
            if ($m['name'] === $menu) {
                return (object) $m;
            }
        }
        return false;
    }
}

if (!function_exists('wp_create_nav_menu')) {
    function wp_create_nav_menu(string $menu_name) {
        foreach ($GLOBALS['_pp_test_store']['nav_menus'] ?? [] as $m) {
            if ($m['name'] === $menu_name) {
                return new WP_Error('menu_exists', sprintf('The menu name %s conflicts with another menu name. Please try another.', $menu_name));
            }
        }
        $id = $GLOBALS['_pp_test_store']['next_id']++;
        $GLOBALS['_pp_test_store']['nav_menus'][$id] = ['term_id' => $id, 'name' => $menu_name];
        return $id;
    }
}

if (!function_exists('wp_delete_nav_menu')) {
    function wp_delete_nav_menu($menu) {
        $menus = $GLOBALS['_pp_test_store']['nav_menus'] ?? [];
        if (!is_numeric($menu) || !isset($menus[(int) $menu])) {
            return false;
        }
        unset($GLOBALS['_pp_test_store']['nav_menus'][(int) $menu]);
        unset($GLOBALS['_pp_test_store']['nav_menu_items'][(int) $menu]);
        return true;
    }
}

if (!function_exists('wp_update_nav_menu_item')) {
    function wp_update_nav_menu_item(int $menu_id, int $menu_item_db_id = 0, array $menu_item_data = []) {
        if (!isset($GLOBALS['_pp_test_store']['nav_menus'][$menu_id])) {
            return new WP_Error('invalid_menu_id', 'Invalid menu ID.');
        }

        // Failure-injection knob: tests seed titles here to exercise the
        // WP_Error paths (item recreation failing mid-restore, set_menu
        // failing mid-loop) that are otherwise unreachable via the stubs.
        $raw_title = (string) ($menu_item_data['menu-item-title'] ?? '');
        if (in_array($raw_title, $GLOBALS['_pp_test_store']['fail_menu_item_titles'] ?? [], true)) {
            return new WP_Error('item_create_failed', 'Simulated menu item failure.');
        }

        if (($menu_item_data['menu-item-type'] ?? '') === 'post_type') {
            $post_id = (int) ($menu_item_data['menu-item-object-id'] ?? 0);
            $title   = $GLOBALS['_pp_test_store']['posts'][$post_id]['post_title'] ?? '';
            $url     = 'https://example.com/?p=' . $post_id;
        } else {
            $title = $raw_title;
            $url   = $menu_item_data['menu-item-url'] ?? '';
        }

        $item_id = $menu_item_db_id ?: $GLOBALS['_pp_test_store']['next_id']++;
        $item    = (object) [
            'ID'               => $item_id,
            'title'            => $title,
            'url'              => $url,
            // Recorded so tests can observe the batch-rollback restore's
            // parents-first id remapping (_pp_restore_menu_state()).
            'menu_item_parent' => (int) ($menu_item_data['menu-item-parent-id'] ?? 0),
            // Recorded so tests can observe position + decorated-field
            // preservation through clear/rebuild cycles.
            'menu_order'       => (int) ($menu_item_data['menu-item-position'] ?? 0),
            'target'           => (string) ($menu_item_data['menu-item-target'] ?? ''),
            'classes'          => array_values(array_filter(explode(' ', (string) ($menu_item_data['menu-item-classes'] ?? '')))),
            'xfn'              => (string) ($menu_item_data['menu-item-xfn'] ?? ''),
            'attr_title'       => (string) ($menu_item_data['menu-item-attr-title'] ?? ''),
            'description'      => (string) ($menu_item_data['menu-item-description'] ?? ''),
        ];

        $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id][] = $item;

        return $item_id;
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
            if (isset($args['post_type']) && is_string($args['post_type'])) {
                if (($data['post_type'] ?? '') !== $args['post_type']) {
                    continue;
                }
            }
            if (isset($args['post_mime_type']) && is_string($args['post_mime_type'])) {
                $mime = $data['post_mime_type'] ?? '';
                $wanted = $args['post_mime_type'];
                // WordPress matches a general type ("image") against the full
                // mime type ("image/jpeg") as well as an exact match.
                if ($mime !== $wanted && strpos($mime, $wanted . '/') !== 0) {
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
        if (isset($args['post_name']) && $args['post_name'] !== '') {
            $GLOBALS['_pp_test_store']['posts'][$id]['post_name'] = _pp_test_unique_slug($args['post_name'], $id);
        }
        return $id;
    }
}

if (!function_exists('wp_update_post')) {
    function wp_update_post(array $args, bool $wp_error = false) {
        $id = $args['ID'] ?? 0;
        // issue 137: ID 999 is the synthetic Custom CSS virtual post that
        // wp_get_custom_css_post() stubs below (not a real registered post,
        // mirroring WordPress's own hidden custom_css post type) — a write
        // to it updates the custom_css store directly instead of going
        // through the normal posts-registry existence check.
        if ($id === 999 && array_key_exists('post_content', $args)) {
            $GLOBALS['_pp_test_store']['custom_css'] = $args['post_content'];
            return $id;
        }
        if (!$id || !isset($GLOBALS['_pp_test_store']['posts'][$id])) {
            if ($wp_error) {
                return new WP_Error('invalid_post', 'Post not found.');
            }
            return 0;
        }
        if (isset($args['post_name']) && $args['post_name'] !== '') {
            $args['post_name'] = _pp_test_unique_slug($args['post_name'], $id);
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
        // Real WordPress recurses (stripslashes_deep()) — this stub must
        // too, since callers pass arrays of messages/params, not just
        // single strings.
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('wp_slash')) {
    // Real WP adds slashes (before ', ", \, NUL) and recurses into arrays.
    // Model it faithfully so it forms an exact inverse pair with the wp_unslash
    // stub: a wp_slash()'d value survives update_post_meta()'s unslash pass
    // byte-for-byte, while an unslashed wp_json_encode() payload does not (#471).
    function wp_slash($value) {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }
        return is_string($value) ? addslashes($value) : $value;
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
    // Test-controlled capability simulation: set $GLOBALS['_pp_test_user_caps']
    // to ['capability' => bool] to simulate a specific role for a test.
    // Unset (the default) or a capability absent from the map both mean
    // "can do everything" — the historical always-true behavior every other
    // test in this suite relies on. Clear the global in tearDown() for isolation.
    function current_user_can(string $capability, ...$args): bool {
        if (!isset($GLOBALS['_pp_test_user_caps'])) {
            return true;
        }
        return $GLOBALS['_pp_test_user_caps'][$capability] ?? true;
    }
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

// #41 SEO metadata hooks: current-page context, controlled via
// $GLOBALS['_pp_test_store']['is_singular'] (bool) and ['queried_object_id'] (int).
if (!function_exists('is_singular')) {
    function is_singular($post_types = ''): bool {
        return (bool) ($GLOBALS['_pp_test_store']['is_singular'] ?? false);
    }
}

if (!function_exists('get_queried_object_id')) {
    function get_queried_object_id(): int {
        return (int) ($GLOBALS['_pp_test_store']['queried_object_id'] ?? 0);
    }
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
        $obj->post_name = $data['post_name'] ?? '';
        return $obj;
    }
}

if (!function_exists('get_post_status')) {
    function get_post_status($post = null) {
        $id = is_object($post) ? $post->ID : (int) $post;
        return $GLOBALS['_pp_test_store']['posts'][$id]['post_status'] ?? false;
    }
}

if (!function_exists('get_post_type')) {
    // Store-aware: an ID present in the posts store returns its post_type
    // (e.g. 'attachment' for logo tests); unknown IDs default to 'page'.
    function get_post_type($post = null): string {
        if (is_int($post) || (is_string($post) && ctype_digit($post))) {
            $id = (int) $post;
            return $GLOBALS['_pp_test_store']['posts'][$id]['post_type'] ?? 'page';
        }
        return 'page';
    }
}

if (!function_exists('get_theme_mod')) {
    function get_theme_mod(string $name, $default = false) {
        return $GLOBALS['_pp_test_store']['theme_mods'][$name] ?? $default;
    }
}

if (!function_exists('set_theme_mod')) {
    function set_theme_mod(string $name, $value): bool {
        $GLOBALS['_pp_test_store']['theme_mods'][$name] = $value;
        // get_nav_menu_locations()'s stub reads a separate flat
        // ['nav_menu_locations'] key that predates this function (and that
        // several existing NavReadinessTest/PostApplyValidateTest fixtures
        // already set directly) — keep both in sync so
        // pp_assign_menu_location()'s real set_theme_mod() call is
        // observable through the existing stub without touching those tests.
        if ($name === 'nav_menu_locations') {
            $GLOBALS['_pp_test_store']['nav_menu_locations'] = $value;
        }
        return true;
    }
}

if (!function_exists('wp_get_attachment_image_url')) {
    function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail', $icon = false) {
        return $GLOBALS['_pp_test_store']['attachment_urls'][(int) $attachment_id] ?? false;
    }
}

// pp_render_responsive_image() (#107): resolves via the same attachment_urls
// map. An id with no entry returns '' -- matching real WP's behavior when an
// attachment doesn't exist -- so the caller's plain-<img> fallback kicks in.
if (!function_exists('wp_get_attachment_image')) {
    function wp_get_attachment_image($attachment_id, $size = 'thumbnail', $icon = false, $attr = ''): string {
        $url = $GLOBALS['_pp_test_store']['attachment_urls'][(int) $attachment_id] ?? false;
        if (!$url) {
            return '';
        }
        $attrs   = is_array($attr) ? $attr : [];
        $class   = $attrs['class'] ?? '';
        $alt     = $attrs['alt'] ?? '';
        $loading = $attrs['loading'] ?? '';
        return sprintf(
            '<img src="%s" srcset="%s 1x, %s 2x" sizes="(max-width: 600px) 100vw, 50vw" class="%s" alt="%s" loading="%s">',
            $url, $url, $url, $class, $alt, $loading
        );
    }
}

if (!function_exists('wp_attachment_is_image')) {
    function wp_attachment_is_image($attachment_id = null): bool {
        return !empty($GLOBALS['_pp_test_store']['attachment_is_image'][(int) $attachment_id]);
    }
}

if (!function_exists('attachment_url_to_postid')) {
    function attachment_url_to_postid(string $url): int {
        $map = $GLOBALS['_pp_test_store']['attachment_urls'] ?? [];
        $id = array_search($url, $map, true);
        return $id !== false ? (int) $id : 0;
    }
}

// Minimal $wpdb stub — only the guid-fallback lookup in
// _pp_resolve_attachment_id_by_url() touches $wpdb. NOT installed as a global
// by default: _pp_with_token_lock() (lib/wp.php) deliberately treats an
// absent/non-object $wpdb as "unit test context" and degrades to running
// its mutator unlocked, so a global $wpdb here would silently break every
// token-override test. Tests that need the guid-fallback path must set
// $GLOBALS['wpdb'] = new wpdb() themselves and unset it in tearDown.
if (!class_exists('wpdb')) {
    class wpdb {
        public string $posts = 'wp_posts';

        // Substitutes %s placeholders so get_var() below can inspect the
        // actual guid being queried, rather than returning a fixed value
        // regardless of what was asked for.
        public function prepare(string $query, ...$args): string {
            foreach ($args as $arg) {
                $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
            }
            return $query;
        }

        public function get_var(string $query) {
            if (preg_match("/guid = '([^']*)'/", $query, $m)) {
                return $GLOBALS['_pp_test_store']['wpdb_guid_map'][$m[1]] ?? null;
            }
            return null;
        }
    }
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
        public string $post_name = '';
        public string $post_content = '';
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

if (!function_exists('wp_delete_post')) {
    function wp_delete_post(int $post_id, bool $force_delete = false) {
        if (isset($GLOBALS['_pp_test_store']['posts'][$post_id])) {
            $post = $GLOBALS['_pp_test_store']['posts'][$post_id];
            unset($GLOBALS['_pp_test_store']['posts'][$post_id]);
            unset($GLOBALS['_pp_test_store']['post_meta'][$post_id]);
            return (object) $post;
        }
        // issue 132: nav menu items are modeled in a separate test-store
        // bucket (not registered as real posts) even though real WordPress
        // stores them as nav_menu_item posts — check there too, so
        // pp_clear_nav_menu_items()'s wp_delete_post() calls actually work.
        foreach ($GLOBALS['_pp_test_store']['nav_menu_items'] ?? [] as $menu_id => $items) {
            foreach ($items as $i => $item) {
                if (is_object($item) && $item->ID === $post_id) {
                    unset($GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id][$i]);
                    $GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id] = array_values($GLOBALS['_pp_test_store']['nav_menu_items'][$menu_id]);
                    return (object) ['ID' => $post_id];
                }
            }
        }
        return false;
    }
}

// ── wp_get_upload_dir stub ────────────────────────────────────────────────
if (!function_exists('wp_get_upload_dir')) {
    function wp_get_upload_dir(): array {
        // Overridable so tests can exercise a misconfigured/empty baseurl
        // (#153 fail-open fix). Defaults to the standard test uploads dir.
        if (isset($GLOBALS['_pp_test_store']['upload_dir']) && is_array($GLOBALS['_pp_test_store']['upload_dir'])) {
            return $GLOBALS['_pp_test_store']['upload_dir'];
        }
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
        public int $max_num_pages = 1; // #126: read by pp_pagination()
        public int $found_posts = 0; // issue 138: read by pp_result_count()

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
    function wp_get_attachment_url(int $attachment_id) {
        // Tests can simulate a missing/deleted file (WordPress returns false)
        // by listing the id in ['attachment_url_missing'] — exercises the
        // import_media dedupe fall-through when a cached asset is unusable.
        if (!empty($GLOBALS['_pp_test_store']['attachment_url_missing'][$attachment_id])) {
            return false;
        }
        return 'https://example.com/wp-content/uploads/image-' . $attachment_id . '.jpg';
    }
}

if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata(int $attachment_id): array {
        return ['width' => 1200, 'height' => 800];
    }
}

// ── import_media apply stubs (#105) ──────────────────────────────────────────
// Controlled via $GLOBALS['_pp_test_store']['download_url_result'|'download_url_size'
// |'filetype_result'|'media_sideload_result'|'safe_remote_head_result'] so tests
// can simulate SSRF rejection, redirect rejection, oversized files, and
// type-mismatch without any real network access or filesystem writes outside
// a real (tiny) temp file that download_url() itself creates.

if (!defined('MB_IN_BYTES')) {
    define('MB_IN_BYTES', 1048576);
}

if (!function_exists('download_url')) {
    function download_url(string $url, int $timeout = 300) {
        $GLOBALS['_pp_test_store']['download_url_calls'][] = ['url' => $url, 'timeout' => $timeout];
        $result = $GLOBALS['_pp_test_store']['download_url_result'] ?? null;
        if ($result instanceof WP_Error) {
            return $result;
        }
        $size = $GLOBALS['_pp_test_store']['download_url_size'] ?? 1024;
        $tmp = tempnam(sys_get_temp_dir(), 'pp_test_download_');
        file_put_contents($tmp, str_repeat('x', $size));
        return $tmp;
    }
}

if (!function_exists('wp_check_filetype_and_ext')) {
    function wp_check_filetype_and_ext(string $file, string $filename, $mimes = null): array {
        return $GLOBALS['_pp_test_store']['filetype_result']
            ?? ['ext' => 'jpg', 'type' => 'image/jpeg', 'proper_filename' => false];
    }
}

if (!function_exists('media_handle_sideload')) {
    function media_handle_sideload(array $file_array, int $post_id = 0, $desc = null, array $post_data = []) {
        $GLOBALS['_pp_test_store']['media_sideload_calls'][] = $file_array;
        $result = $GLOBALS['_pp_test_store']['media_sideload_result'] ?? null;
        if ($result instanceof WP_Error) {
            return $result;
        }
        if (is_int($result)) {
            return $result;
        }
        $id = $GLOBALS['_pp_test_store']['next_id']++;
        $GLOBALS['_pp_test_store']['attachment_urls'][$id] =
            'https://example.com/wp-content/uploads/' . basename($file_array['name']);
        // Register the sideloaded attachment as a real post so get_posts()-based
        // lookups (e.g. import_media source-URL dedupe, #298) can find it.
        $GLOBALS['_pp_test_store']['posts'][$id] = [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'post_title'  => basename($file_array['name']),
        ];
        return $id;
    }
}

if (!function_exists('wp_safe_remote_head')) {
    function wp_safe_remote_head(string $url, array $args = []) {
        $GLOBALS['_pp_test_store']['safe_remote_head_calls'][] = $url;
        return $GLOBALS['_pp_test_store']['safe_remote_head_result']
            ?? ['headers' => ['content-type' => 'image/jpeg']];
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, string $header) {
        if (is_wp_error($response) || !is_array($response)) {
            return '';
        }
        return $response['headers'][$header] ?? '';
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $filename): string {
        return preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) ?? $filename;
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
        // issue 137: once a test's fixture has ever set the custom_css key
        // (even to ''), the virtual post "exists" — mirrors real WordPress,
        // where the Custom CSS post persists even after its content is
        // cleared. Only a truly uninitialized store (no fixture touched
        // Custom CSS at all) returns null, matching "never created".
        if (!array_key_exists('custom_css', $GLOBALS['_pp_test_store'] ?? [])) {
            return null;
        }
        $post = new WP_Post();
        $post->ID = 999;
        $post->post_content = $GLOBALS['_pp_test_store']['custom_css'];
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
