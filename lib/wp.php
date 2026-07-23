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
 * Returns the main WP_Query for the current request (#126).
 *
 * WordPress already builds and filters this query correctly for the route —
 * category/tag/date/author archives, the posts index (is_home) — before any
 * template loads. Prefer this over pp_posts() with a fresh WP_Query when
 * rendering "the listing for this route": a fresh query can only approximate
 * the archive context (and easily gets it wrong, e.g. showing every post on
 * a category archive), where the main query already has it exactly right.
 *
 * @return \WP_Query
 */
function pp_main_query(): \WP_Query {
    global $wp_query;
    return $wp_query;
}

/**
 * Returns pagination markup for the current main query (#126), or '' when
 * there's only one page. Wraps paginate_links() — the only place in the
 * theme this is called, keeping the "only lib/wp.php touches WP" invariant.
 *
 * @return string  A <nav> element with page links, or ''.
 */
function pp_pagination(): string {
    global $wp_query;
    $total = (int) ($wp_query->max_num_pages ?? 1);
    if ($total <= 1) {
        return '';
    }

    $links = paginate_links([
        'total'     => $total,
        'current'   => max(1, (int) get_query_var('paged')),
        'prev_text' => '← Previous',
        'next_text' => 'Next →',
        'type'      => 'array',
    ]);

    if (empty($links)) {
        return '';
    }

    return '<nav class="pp-pagination" aria-label="Posts pagination"><ul class="pp-pagination__list"><li>'
        . implode('</li><li>', $links)
        . '</li></ul></nav>';
}

/**
 * Returns the raw search query string for the current search request (issue 138).
 *
 * @return string
 */
function pp_search_query(): string {
    return get_search_query();
}

/**
 * Returns the total number of matched posts for the current main query
 * (issue 138) — `found_posts` reflects the full result count across all
 * pages, unlike `pp_main_query()->posts`, which only holds the current page.
 *
 * @return int
 */
function pp_result_count(): int {
    global $wp_query;
    return (int) ($wp_query->found_posts ?? 0);
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

/**
 * Returns the composition array for the current page from _pp_composition post meta.
 * Returns an empty array when the meta is absent, empty, or contains invalid JSON.
 *
 * Runs the legacy `variant` read-path shim (pp_migrate_stored_composition) on the
 * decoded items so a stored pre-#69 composition renders with `variant` honored,
 * matching the MIGRATION behavior of the editor/restore/inspect read paths (#400).
 * It does not add their list-shape enforcement (that decode/classification gate is
 * pp_get_composition_result()'s job, #144, deliberately left untouched here). The
 * migration is a no-op for the modern layout/theme shape, so those compositions
 * render byte-identically.
 *
 * @return array  Array of component objects: [['component' => string, 'props' => array], ...]
 */
function pp_composition(): array {
    $raw = get_post_meta(get_the_ID(), '_pp_composition', true);
    if (!$raw) {
        return [];
    }
    $items = json_decode($raw, true);
    return is_array($items) ? pp_migrate_stored_composition($items) : [];
}

// ── Site-state read functions (action-layer support) ─────────────────────────

/**
 * Compatibility shim for array_is_list() (PHP 8.1+); the plugin floor is PHP 8.0.
 *
 * A list has sequential integer keys 0..n-1. The empty array is a list. Guard
 * the empty case first — range(0, count($a) - 1) is range(0, -1) on [], which
 * yields [0], not [].
 *
 * @param array $arr
 * @return bool
 */
function pp_is_list(array $arr): bool {
    if ($arr === []) {
        return true;
    }
    return array_keys($arr) === range(0, count($arr) - 1);
}

/**
 * Reads _pp_composition and classifies its state, so callers can tell a
 * genuinely blank page apart from a corrupted one (issue #144).
 *
 * Return shape: ['ok' => bool, 'composition' => array, 'error' => ?string, 'raw' => ?string].
 *
 * State classification, checked in this exact order:
 *
 *   absent / blank meta ('' / falsy)   ok=true  composition=[]     error=null               raw=null
 *   already-decoded list (fixture)     ok=true  composition=$raw   error=null               raw=null
 *   already-decoded NON-list array     ok=false composition=[]     error='unexpected_shape' raw=null
 *   non-string scalar meta (int/bool)  ok=false composition=[]     error='unexpected_shape' raw=null
 *   undecodable JSON string            ok=false composition=[]     error='decode_error'     raw=<raw>
 *   valid JSON, non-list (obj/scalar)  ok=false composition=[]     error='unexpected_shape' raw=<raw>
 *   valid JSON list ([] or [ ... ])    ok=true  composition=$items error=null               raw=<raw>
 *
 * A JSON object decodes to an associative PHP array that is_array() would accept,
 * so list-shape is enforced via pp_is_list() to keep objects out of compositions.
 *
 * This is the single owner of composition decode + state classification for
 * consuming callers (inspect / check / validate). Render paths (pp_composition())
 * keep their own defensive decode by design — issue #144 leaves rendering untouched.
 *
 * @param int $post_id  WordPress post ID.
 * @return array{ok: bool, composition: array, error: ?string, raw: ?string}
 */
function pp_get_composition_result(int $post_id): array {
    $raw = get_post_meta($post_id, '_pp_composition', true);

    // Absent meta: get_post_meta(single=true) returns '' when the key does not
    // exist. Match only genuine absence here — NOT every falsy value — so a
    // stored falsy-but-present payload (the JSON string "0", an int 0) still
    // reaches shape classification below instead of masquerading as a blank page.
    if ($raw === '' || $raw === null || $raw === false) {
        return ['ok' => true, 'composition' => [], 'error' => null, 'raw' => null];
    }

    // Defensive: a caller/fixture may have persisted an already-decoded array.
    // Enforce the same list-shape as the JSON path so a decoded object (an
    // associative array) is flagged, not silently accepted.
    if (is_array($raw)) {
        if (!pp_is_list($raw)) {
            return ['ok' => false, 'composition' => [], 'error' => 'unexpected_shape', 'raw' => null];
        }
        return ['ok' => true, 'composition' => pp_migrate_stored_composition($raw), 'error' => null, 'raw' => null];
    }

    // Normal storage is a JSON string. A truthy non-string scalar (int, float,
    // bool) is neither a composition nor a JSON payload we should decode.
    if (!is_string($raw)) {
        return ['ok' => false, 'composition' => [], 'error' => 'unexpected_shape', 'raw' => null];
    }

    $items = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Truncated write, encoding bug, malformed UTF-8: undecodable.
        return ['ok' => false, 'composition' => [], 'error' => 'decode_error', 'raw' => $raw];
    }
    if (!is_array($items) || !pp_is_list($items)) {
        // Valid JSON but the wrong shape: object, scalar, or literal null.
        return ['ok' => false, 'composition' => [], 'error' => 'unexpected_shape', 'raw' => $raw];
    }

    return ['ok' => true, 'composition' => pp_migrate_stored_composition($items), 'error' => null, 'raw' => $raw];
}

/**
 * READ-PATH compatibility shim (issue #69, permanent per #388).
 *
 * Action-layer reads (inspect, guardrails, the editor, restore's current-composition
 * fetch via pp_get_composition) decode stored compositions directly; they do not run
 * pp_normalize_composition, which only covers the apply/write path. A composition
 * persisted before the `variant` -> `layout`/`theme` split would otherwise lose its
 * structural/tone setting after the rename, so this migrates the decoded items on read.
 * Note (#388): the write path no longer migrates — new writes reject `variant` with
 * unknown_prop — so this read-path decode is the permanent counterpart that keeps
 * already-stored legacy content readable on those paths. As of #400 the public
 * front-end renderer (pp_composition()) also routes its decoded items through this
 * shim, so the render boundary is no longer the odd reader that bypasses migration.
 * Delegates to the single migration helper in lib/admin.php;
 * guarded so a partial include (some unit tests load lib/wp.php alone) degrades to
 * the raw items instead of fatally.
 *
 * @param  array $items  Decoded composition items.
 * @return array         Items with any legacy `variant` keys migrated.
 */
function pp_migrate_stored_composition(array $items): array {
    return function_exists('pp_migrate_legacy_variant_keys')
        ? pp_migrate_legacy_variant_keys($items)
        : $items;
}

/**
 * Returns the composition array for a specific page by post ID.
 * Unlike pp_composition(), this works outside the loop.
 *
 * Legacy array-only accessor: delegates to pp_get_composition_result() (the
 * single decode owner) and returns only the items. A corrupt or non-list row
 * degrades to [] here — callers that need to distinguish corruption from an
 * empty page use pp_get_composition_result() directly.
 *
 * @param int $post_id  WordPress post ID.
 * @return array  Array of component objects, or [] if absent/invalid.
 */
function pp_get_composition(int $post_id): array {
    return pp_get_composition_result($post_id)['composition'];
}

/**
 * The menu locations the base template renders on every page.
 *
 * Mirrors the pp_get_component() calls in templates/base.php:
 *
 *   templates/base.php:35   pp_get_component('nav',    ['location' => 'primary'])
 *   templates/base.php:49   pp_get_component('footer', ['location' => 'footer'])
 *
 * Chrome is template-owned, not composition-declared (#223), so this is the only
 * honest source for "which locations does this site actually render?".
 * NavReadinessTest::testTemplateOwnedLocationsMatchBaseTemplate() reads the
 * template back and fails if the two ever drift.
 *
 * @return string[]  Registered nav-menu location slugs the template renders.
 */
function pp_template_owned_menu_locations(): array {
    return ['primary', 'footer'];
}

/**
 * Diagnoses readiness of the site chrome the base template renders.
 *
 * Scoped to the TEMPLATE-OWNED locations (pp_template_owned_menu_locations()),
 * not to anything a page composition declares — chrome is not composable (#223).
 * Registered-but-unrendered locations (e.g. one a plugin adds) are intentionally
 * NOT flagged; that would be noise about markup this theme never emits.
 *
 * For each rendered location it flags, in order:
 *   - an UNREGISTERED location (the template renders a location nobody declared),
 *   - a registered location with NO menu assigned,
 *   - an assigned menu that resolves to ZERO items.
 * It additionally flags a site logo option that does not resolve to an image
 * attachment, which pp_resolve_logo() silently falls back to a text wordmark for.
 *
 * A ready location reports a passing row. Every row is severity=warning: chrome
 * readiness is an operator-facing diagnostic, never a gate on content mutations.
 *
 * @return array[]  Rows: ['check'=>'nav_readiness','pass'=>bool,'severity'=>'warning','message'=>string].
 */
function pp_check_nav_readiness(): array {
    $registered = array_keys(get_registered_nav_menus());
    $locations  = get_nav_menu_locations(); // location => menu_id
    $checks     = [];

    foreach (pp_template_owned_menu_locations() as $loc) {
        // Messages may be rendered in the admin UI. $loc is a theme constant now
        // rather than AI-controlled composition data, but escaping it costs
        // nothing and keeps the guarantee local. Raw $loc is used for lookups.
        $safe_loc = esc_html((string) $loc);

        if (!in_array($loc, $registered, true)) {
            $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
                'message' => 'The page template renders menu location "' . $safe_loc . '", which is not registered. Register it in functions.php.'];
            continue;
        }
        if (!has_nav_menu($loc)) {
            $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
                'message' => 'Site chrome location "' . $safe_loc . '" has no menu assigned. Use the set_menu action (or Appearance → Menus) to create one and assign it (issue 132).'];
            continue;
        }
        $menu_id = $locations[$loc] ?? 0;
        $items   = $menu_id ? wp_get_nav_menu_items($menu_id) : false;
        if (empty($items)) {
            $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
                'message' => 'The menu assigned to site chrome location "' . $safe_loc . '" is empty. Use the set_menu or add_menu_item action (or Appearance → Menus) to add links (issue 132).'];
            continue;
        }
        $checks[] = ['check' => 'nav_readiness', 'pass' => true, 'severity' => 'warning',
            'message' => 'Site chrome location "' . $safe_loc . '" is ready (' . count($items) . ' item(s)).'];
    }

    // Site logo: the only chrome surface reachable without the menu API. An id that
    // isn't an image attachment makes pp_resolve_logo() fall through to the text
    // wordmark silently (#155), so an operator who set the option sees no logo and
    // no explanation.
    //
    // Only a POSITIVE id means "the operator set a logo". '' / '0' / 0 / false all
    // mean cleared — 0 is WordPress's conventional cleared attachment id — and a
    // cleared logo is a deliberate text wordmark, not a finding. Testing `!== ''`
    // here would warn forever on a cleared option, and the message would read
    // "attachment 0, which is not an image". Report nothing rather than a passing
    // row: a site with no logo should not carry a standing chrome warning.
    $logo_option = (int) get_option('pp_logo_id', '');
    if ($logo_option > 0 && !pp_is_image_attachment($logo_option)) {
        $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
            'message' => 'The "pp_logo_id" site option is set to attachment ' . $logo_option
                . ', which is not an image. The site chrome falls back to a text wordmark. '
                . 'Set pp_logo_id to an image attachment id, or clear it to use the wordmark deliberately.'];
    }

    return $checks;
}

/**
 * Returns every navigation menu with its assigned theme location (if any)
 * and item summaries — the AI-visible surface for grounding menu proposals
 * against real state (issue 132), mirroring pp_composition_pages()'s role
 * for pages.
 *
 * @return array[]  Each: ['id'=>int, 'name'=>string, 'location'=>?string, 'items'=>[['title'=>string,'url'=>string], ...]]
 */
function pp_get_menus(): array {
    $menus = wp_get_nav_menus();
    $locations = array_flip(array_filter(get_nav_menu_locations())); // menu_id => location, unassigned (0) dropped

    $result = [];
    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        $result[] = [
            'id'       => $menu->term_id,
            'name'     => $menu->name,
            'location' => $locations[$menu->term_id] ?? null,
            'items'    => array_map(function ($item) {
                return ['title' => $item->title, 'url' => $item->url];
            }, $items ?: []),
        ];
    }
    return $result;
}

/**
 * Creates a navigation menu (issue 132).
 *
 * @param  string $name  Menu name.
 * @return int|WP_Error  New menu (term) ID, or the WP_Error wp_create_nav_menu()
 *                        itself returns (e.g. on a duplicate name).
 */
function pp_create_nav_menu(string $name) {
    return wp_create_nav_menu($name);
}

/**
 * Adds a single item to a navigation menu (issue 132) — a page link
 * (page_id) or a custom link (url + label).
 *
 * Named page_id, not post_id: the operate/preflight gate treats a
 * top-level `post_id` action param as "this action mutates that specific
 * post," requiring a PREFLIGHT covering it — but add_menu_item's linked
 * page is data referenced by a site-scoped mutation (the menu), not the
 * post being mutated, so it must not collide with that param name.
 *
 * @param  int   $menu_id  Menu (term) ID.
 * @param  array $item     ['page_id' => int] or ['url' => string, 'label' => string];
 *                         optional 'position' => int, optional 'parent_id' => int
 *                         (the item id of the parent menu item, for one-level
 *                         dropdown children — issue 381).
 * @return int|WP_Error    New menu item ID, or WP_Error on failure.
 */
function pp_add_nav_menu_item(int $menu_id, array $item) {
    $args = ['menu-item-status' => 'publish'];

    if (!empty($item['page_id'])) {
        $args['menu-item-type']      = 'post_type';
        $args['menu-item-object']    = 'page';
        $args['menu-item-object-id'] = (int) $item['page_id'];
    } else {
        $args['menu-item-type']  = 'custom';
        $args['menu-item-url']   = $item['url'] ?? '';
        $args['menu-item-title'] = $item['label'] ?? '';
    }

    if (isset($item['position'])) {
        $args['menu-item-position'] = (int) $item['position'];
    }

    // A non-zero parent id nests this item under an existing top-level item as
    // a dropdown child (issue 381). WordPress stores it as the item's
    // menu_item_parent, which the snapshot/rollback path already round-trips.
    if (!empty($item['parent_id'])) {
        $args['menu-item-parent-id'] = (int) $item['parent_id'];
    }

    return wp_update_nav_menu_item($menu_id, 0, $args);
}

/**
 * Removes every item from a menu, leaving the menu itself intact — used by
 * the set_menu action's replace semantics (issue 132).
 *
 * @param int $menu_id  Menu (term) ID.
 */
function pp_clear_nav_menu_items(int $menu_id): void {
    $items = wp_get_nav_menu_items($menu_id);
    foreach ($items ?: [] as $item) {
        wp_delete_post($item->ID, true);
    }
}

/**
 * Assigns a menu to a registered theme navigation location (issue 132).
 *
 * @param  int    $menu_id   Menu (term) ID.
 * @param  string $location  Registered theme location slug (e.g. 'primary').
 * @return bool
 */
function pp_assign_menu_location(int $menu_id, string $location): bool {
    $locations = get_theme_mod('nav_menu_locations', []);
    if (!is_array($locations)) {
        $locations = [];
    }
    $locations[$location] = $menu_id;
    return set_theme_mod('nav_menu_locations', $locations);
}

/**
 * Returns all pages using the Composition template.
 * Each entry: ['id' => int, 'title' => string, 'status' => string, 'url' => string].
 * URL is get_permalink() for all statuses (best available WP link, not guaranteed public for drafts).
 * Uses static cache — safe to call multiple times per request.
 *
 * @return array
 */
function pp_composition_pages(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $posts = get_posts([
        'post_type'      => 'page',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'meta_key'       => '_wp_page_template',
        'meta_value'     => 'composition.php',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $cache = [];
    foreach ($posts as $post) {
        $cache[] = [
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'status' => $post->post_status,
            'url'    => (string) get_permalink($post->ID),
        ];
    }

    return $cache;
}

/**
 * Returns CSS custom properties from base.css :root {} with type metadata.
 * Each token is ['value' => string, 'type' => string|null].
 * Type is extracted from the structured comment convention: /* type: description *​/
 *
 * Returns e.g. ['--color-bg' => ['value' => '#ffffff', 'type' => 'color'], ...].
 * Static cached. Call pp_invalidate_design_tokens_cache() after writes.
 *
 * @return array  Associative array of CSS custom property name => ['value', 'type'].
 */
function pp_design_tokens(): array {
    static $cache = null;
    if (!empty($GLOBALS['_pp_design_tokens_invalidate'])) {
        $cache = null;
        unset($GLOBALS['_pp_design_tokens_invalidate']);
    }
    if ($cache !== null) {
        return $cache;
    }

    $file = get_template_directory() . '/assets/css/base.css';
    if (!file_exists($file)) {
        $cache = [];
        return $cache;
    }

    $css = file_get_contents($file);
    $cache = [];

    // Match :root { ... } block
    if (preg_match('/:root\s*\{([^}]+)\}/s', $css, $root_match)) {
        // Match each --property: value; with optional /* type: description */ comment
        preg_match_all(
            '/(--[\w-]+)\s*:\s*([^;]+);\s*(?:\/\*\s*(\w[\w-]*):\s*[^*]*\*\/)?/',
            $root_match[1],
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            $name  = trim($m[1]);
            $value = trim($m[2]);
            $type  = isset($m[3]) && $m[3] !== '' ? $m[3] : null;
            $cache[$name] = ['value' => $value, 'type' => $type];
        }
    }

    // Merge database overrides: override values replace defaults, types preserved.
    $overrides = pp_get_token_overrides();
    foreach ($overrides as $token => $override_value) {
        if (isset($cache[$token])) {
            $cache[$token]['value'] = $override_value;
        }
    }

    return $cache;
}

/**
 * Invalidates the pp_design_tokens() static cache.
 * Call after modifying token overrides so subsequent reads return fresh data.
 */
function pp_invalidate_design_tokens_cache(): void {
    // Static variables can only be reset by re-calling the function
    // with a flag. We use a global flag that pp_design_tokens() checks.
    $GLOBALS['_pp_design_tokens_invalidate'] = true;
}

/**
 * Returns the declared style slots for a component type.
 *
 * Reads from the cached schema registry (pp_get_registered_components).
 * Each slot has: type, default, description.
 *
 * @param string $component_name  Component name, e.g. 'hero'.
 * @return array  Associative array of slot name => definition, or empty array if component has no slots.
 */
function pp_get_style_slots(string $component_name): array {
    $components = pp_get_registered_components();
    if (!isset($components[$component_name])) {
        return [];
    }
    return $components[$component_name]['styling']['style_slots'] ?? [];
}

/**
 * Returns the declared style recipes for a component type.
 *
 * @param string $component_name  Component name, e.g. 'hero'.
 * @return array  Associative array of recipe name => definition, or empty array.
 */
function pp_get_style_recipes(string $component_name): array {
    $components = pp_get_registered_components();
    if (!isset($components[$component_name])) {
        return [];
    }
    return $components[$component_name]['styling']['recipes'] ?? [];
}

/**
 * Render-time validation gate for a stored style value (issue #330).
 *
 * The write-time engines strictly validate style values, but two paths can put
 * a value into storage that never passed (current) validation: snapshot restore
 * — which is intentionally never blocked by current rules (#233) — and
 * out-of-band DB writes (direct SQL, import tooling, another plugin). This gate
 * is the defense-in-depth check at the render boundary, immediately before a
 * value is emitted into an inline `style=""` attribute. It changes nothing about
 * write-time or restore semantics: a rejected value is simply dropped from the
 * rendered output; the page still renders and the restore still succeeds.
 *
 * Two layers, no second grammar:
 *   1. A conservative reject set applied to every value regardless of type —
 *      the existing `{ } ; < >` injection guard, plus `url(` / `expression(` /
 *      `@import` (CSS network-request, dynamic-eval, and import primitives),
 *      backslash escapes, and control characters. This is the sole line of
 *      defense for a (hypothetical) slot with no declared type.
 *   2. Where the slot type is known, delegation to the SAME shared engine used
 *      at write time (`_pp_validate_token_value`). Only its documented success
 *      shape (`=== true`) passes; any WP_Error drops the declaration.
 *
 * @param string      $value  The stored style value, cast to string.
 * @param string|null $type   The slot's declared type (color/length/gradient/…),
 *                            or null when no type context is available.
 * @return bool  True if the value is safe to emit; false to drop the declaration.
 */
function pp_render_style_value_allowed(string $value, ?string $type): bool {
    // Layer 1 — conservative reject set (defense-in-depth), every value.
    // Char class: { } ; < >, a literal backslash, and control chars 0x00-0x1F/0x7F.
    if (preg_match('/[{};<>\\\\\x00-\x1f\x7f]/', $value)) {
        return false;
    }
    // Function/at-rule primitives: url(...), expression(...), @import.
    if (preg_match('/url\s*\(|expression\s*\(|@import/i', $value)) {
        return false;
    }

    // Layer 2 — delegate to the shared write-time engine when type is known.
    if ($type !== null && _pp_validate_token_value($value, $type) !== true) {
        return false;
    }

    return true;
}

/**
 * Renders style slot overrides as a CSS custom property string.
 *
 * Validates each property against the component's declared style slots.
 * Unknown properties and the __recipe tracking key are silently skipped.
 * Each value is re-validated at the render boundary via
 * pp_render_style_value_allowed() (issue #330); a rejected value is dropped
 * from the output while its sibling declarations still render.
 *
 * @param array  $style           Style overrides, e.g. ['--hero-bg' => '#1a1a2e'].
 * @param string $component_name  Component name, e.g. 'hero'.
 * @return string  CSS custom property declarations, e.g. "--hero-bg: #1a1a2e; --hero-padding-top: 8rem"
 */
function pp_render_style_vars(array $style, string $component_name): string {
    if (empty($style)) {
        return '';
    }

    $slots      = pp_get_style_slots($component_name);
    $properties = [];

    foreach ($style as $name => $value) {
        // Skip __recipe tracking key — not a CSS property.
        if ($name === '__recipe') {
            continue;
        }
        // Only render declared style slots (defensive — validated at action layer).
        if (!isset($slots[$name])) {
            continue;
        }
        $value = (string) $value;
        // Render boundary (#330): re-validate the stored value against the slot's
        // declared type through the shared engine (plus the conservative reject
        // set). Drop only this declaration on rejection — the page and any
        // in-progress restore are never blocked (#233).
        $type = $slots[$name]['type'] ?? null;
        if (!pp_render_style_value_allowed($value, $type)) {
            continue;
        }
        $properties[] = esc_attr($name) . ': ' . esc_attr($value);
    }

    return implode('; ', $properties);
}

/**
 * Maps a grid card's --grid-item-text-align value to the align-self keyword its
 * link/button must follow, returned as the internal plumbing custom property
 * --pp-grid-link-align (issue 361). This is the second mechanism the text-align
 * slot cannot supply on its own: .grid__item-link is a content-width flex item
 * placed by align-self, and per the issue 338 flex trap text-align never moves a
 * flex item's box — so after issue 357 a centered card centered its text but left
 * the "Read more" link pinned left. The operator still sets ONE authorable slot
 * (--grid-item-text-align); this derives the link's cross-axis placement from the
 * SAME value so the text and the link align together (no second schema slot).
 *
 * A keyword map is required, not a pass-through: align-self accepts start/end/
 * center but NOT left/right/justify, so a bare `align-self: var(--grid-item-text-
 * align)` would silently drop `right` (invalid value) and render it left. Physical
 * mapping (LTR theme, no rtl.css — mirrors the text-align slot's own physical
 * default in issue 357):
 *     left | start | justify -> flex-start   (also the CSS default)
 *     center                 -> center
 *     right | end            -> flex-end
 *
 * A companion is emitted for every RECOGNIZED value INCLUDING left -> flex-start,
 * so a per-card override resets a grid-level companion the card inherits from the
 * section by cascade proximity (a card set back to `left` must re-pin its link
 * left even when the grid centers the rest). An UNSET or unrecognized value emits
 * NOTHING, so the CSS fallback (flex-start) keeps every existing card byte-
 * identical to today. The value passes the SAME render boundary the text-align
 * slot itself passes (#330/#233): a stored value the shared engine would reject
 * (out-of-band write, restore) derives no companion, exactly as it renders no slot.
 *
 * @param array $style  A grid style-slot map (grid-level __pp_style or a card's style).
 * @return string  '--pp-grid-link-align: <keyword>', or '' when nothing should render.
 */
function pp_grid_link_align_decl(array $style): string {
    $value = $style['--grid-item-text-align'] ?? null;
    if (!is_string($value) || $value === '') {
        return '';
    }
    // Same render-boundary gate the text-align slot itself passes through, so a
    // value the shared engine rejects derives no companion (parity with #330).
    if (!pp_render_style_value_allowed($value, 'align')) {
        return '';
    }
    // The align type validates to exactly these six keywords (_pp_validate_align);
    // map them to their physical align-self equivalent. Anything else emits nothing.
    $map = [
        'left'    => 'flex-start',
        'start'   => 'flex-start',
        'justify' => 'flex-start',
        'center'  => 'center',
        'right'   => 'flex-end',
        'end'     => 'flex-end',
    ];
    $keyword = $map[$value] ?? null;
    if ($keyword === null) {
        return '';
    }
    return '--pp-grid-link-align: ' . $keyword;
}

/**
 * Renders an inline ` style="..."` attribute of CSS custom properties for
 * TEMPLATE-OWNED chrome — the header and footer, whose styling surface is
 * whitelisted site options (pp_header_* / pp_footer_*) rather than component
 * style_slots, so pp_render_style_vars() (which reads a component's schema
 * slots) does not apply to them.
 *
 * Each entry maps a CSS custom-property name to its value plus the SITE-OPTION
 * KEY that declares its type. The type is read from pp_allowed_site_options()
 * — the single source of truth — never hand-copied into the caller. That is the
 * point: the drift that silently dropped gradients before #333 was a render-time
 * type ('color') hardcoded separately from the whitelist's declared type
 * ('gradient'). Deriving the type here makes that divergence impossible.
 *
 * Each value is re-validated at the render boundary through the shared engine
 * (#330); a rejected or empty value is dropped while its siblings still render.
 * An all-empty/all-dropped set yields '' (no attribute at all), so unset chrome
 * is byte-identical to markup that never had the surface.
 *
 * @param array<string,array{value:string,option:string}> $vars
 *        CSS var name => ['value' => stored value, 'option' => whitelisted option key].
 * @return string  A ready-to-echo ` style="..."` attribute, or '' when nothing renders.
 */
function pp_chrome_style_attr(array $vars): string {
    $allowed = pp_allowed_site_options();
    $decls   = [];
    foreach ($vars as $css_var => $slot) {
        $value = (string) ($slot['value'] ?? '');
        if ($value === '') {
            continue;
        }
        // The CSS property name is developer-supplied (callers hardcode it), never
        // user input — but this is a shared primitive, so keep it structurally safe:
        // a custom property is `--` followed by name chars. Anything else is a caller
        // bug; drop it rather than emit an odd token into the attribute.
        if (!preg_match('/^--[A-Za-z0-9_-]+$/', (string) $css_var)) {
            continue;
        }
        // Type comes from the whitelist, keyed by the option name — never a
        // second, hand-maintained copy (the #333 drift class).
        $type = $allowed[$slot['option']] ?? null;
        // Fail CLOSED to an explicit CSS-color allowlist. This helper only ever emits
        // background/text/link COLOR surfaces, so only 'color' and 'gradient' may reach
        // the render boundary. An unresolved key (null) OR a resolved-but-non-style type
        // — e.g. a caller that names 'blogname' (string) or 'pp_footer_show_logo' (bool)
        // by mistake — is dropped here. Without this, pp_render_style_value_allowed()
        // would validate the value under a non-CSS type: _pp_validate_token_value() has
        // no case for 'string'/'bool'/'attachment_id', so it falls through to a permissive
        // pass, leaving only the layer-1 injection reject set. Constraining the type is
        // strictly safer and keeps the drift-proofing above meaningful.
        if ($type !== 'color' && $type !== 'gradient') {
            continue;
        }
        if (!pp_render_style_value_allowed($value, $type)) {
            continue;
        }
        $decls[] = $css_var . ': ' . $value;
    }
    // esc_attr on the whole attribute value is defense-in-depth on output; the
    // render boundary above is the real gate.
    return $decls ? ' style="' . esc_attr(implode('; ', $decls)) . '"' : '';
}

/**
 * Renders a heading title with an optional accent-colored substring (#110).
 *
 * A structured, plain-text mechanism — NOT an HTML/markup allowlist. `$title`
 * and `$accent` are both ordinary escaped text; this only decides WHERE to
 * split them and wraps the matched segment in a `<span>`. There is no new
 * markup-parsing or sanitization surface: every fragment goes through
 * esc_html() exactly as a plain title always did.
 *
 * `$accent` must be a real, non-empty substring of `$title` (case-sensitive,
 * first occurrence). If it isn't — unset, empty, or not actually found in
 * the title — the accent is silently ignored and the title renders exactly
 * as it always has, matching this codebase's established "invalid override
 * falls back to the safe default" pattern for props.
 *
 * @param string $title        Full heading text.
 * @param string $accent       Substring of $title to wrap in an accent span. Empty/no-match = no accent.
 * @param string $accent_class CSS class for the accent <span>, e.g. "hero__title-accent".
 * @return string  Safe-to-echo HTML (already escaped) — do not pass through esc_html() again.
 */
function pp_render_heading_with_accent(string $title, string $accent, string $accent_class): string {
    if ($accent === '') {
        return esc_html($title);
    }
    $pos = strpos($title, $accent);
    if ($pos === false) {
        return esc_html($title);
    }
    $before = substr($title, 0, $pos);
    $after  = substr($title, $pos + strlen($accent));
    return esc_html($before)
        . '<span class="' . esc_attr($accent_class) . '">' . esc_html($accent) . '</span>'
        . esc_html($after);
}

/**
 * Renders a FAQPage JSON-LD <script> tag from FAQ items (#3), or '' if
 * there are no complete (question + answer) items to describe. Always-on,
 * zero-config — the FAQ component already has everything the schema needs.
 *
 * Google's FAQPage schema expects plain-text answers, not HTML — question
 * and answer are both passed through wp_strip_all_tags() before encoding.
 * This is also the primary defense against a </script> breakout: WordPress's
 * wp_strip_all_tags() strips <script>/<style> tags AND their content via a
 * regex pass before the general strip_tags() call, so no well-formed tag
 * markup survives into the JSON payload at all. wp_json_encode()'s default
 * forward-slash escaping (this does NOT pass JSON_UNESCAPED_SLASHES) is a
 * second, redundant layer: even if a "</script>"-shaped substring somehow
 * reached this point some other way, it would encode as "<\/script>",
 * which cannot close the surrounding <script> tag.
 *
 * @param array $items  FAQ items: [{question, answer}, ...].
 * @return string  A full <script type="application/ld+json">...</script> tag, or ''.
 */
function pp_render_faq_schema(array $items): string {
    $entities = [];
    foreach ($items as $item) {
        $question = wp_strip_all_tags((string) ($item['question'] ?? ''));
        $answer   = wp_strip_all_tags((string) ($item['answer']   ?? ''));
        if ($question === '' || $answer === '') {
            continue;
        }
        $entities[] = [
            '@type' => 'Question',
            'name'  => $question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $answer,
            ],
        ];
    }

    if (empty($entities)) {
        return '';
    }

    $schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entities,
    ];

    return '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
}

/**
 * Safely escapes an image source for output in <img src="..."> or a CSS
 * background-image:url(...) value embedded in an HTML style attribute.
 *
 * esc_url() rejects the 'data' URI scheme entirely (not in WordPress core's
 * default protocol whitelist), silently reducing any data:image/... value to
 * an empty string — the exact production bug this closes (#36): a
 * data:image/svg+xml,... hero image rendered with src="". Even if 'data'
 * were whitelisted, esc_url()'s general-purpose character-stripping isn't
 * safe for a raw (non-base64) SVG payload's XML markup (quotes, angle
 * brackets) — it would mangle the image or, worse, leave characters that
 * break out of the surrounding HTML attribute / unquoted CSS url() token.
 *
 * Non-data URLs are unaffected — this delegates straight to esc_url(),
 * identical to every call site's prior behavior.
 *
 * For data: URIs, this only accepts image/{png,jpeg,jpg,gif,webp,svg+xml} —
 * never data:text/html or other schemes/types.
 *
 * SECURITY NOTE (do not weaken without re-reading this): a browser does NOT
 * treat SVGs rendered as an image (via <img src> or CSS background-image) as
 * script-executing — but every <img> call site here gives an ordinary user a
 * standard browser feature ("Open image in new tab", copy-image-address,
 * drag-to-new-tab) that navigates to the data: URI as a TOP-LEVEL document,
 * where SVG script execution IS enabled. So the SVG content validation
 * below is the PRIMARY defense against stored XSS via an AI-chat-and-human-editable
 * image_url/background_image prop, not defense in depth on top of the
 * browser (adversarial review finding — an earlier version of this
 * docblock got that backwards). Getting the check-then-encode ordering
 * exactly right matters:
 *  - The safety check always runs against the FULLY, repeatedly
 *    percent-decoded payload (not the raw input) — otherwise an
 *    already-percent-encoded payload (single- or multiply-encoded) sails
 *    past a literal-substring blocklist and only becomes dangerous once the
 *    browser decodes it (cross-model review finding).
 *  - The final re-encoding step only ever REPLACES a character with its
 *    percent-encoding — it never DELETES one. Deleting a character can
 *    silently merge two adjacent, previously-separated fragments back into
 *    a blocked keyword (e.g. "&lt;scr" + [deleted newline] + "ipt&gt;"
 *    becomes "&lt;script&gt;") — the exact bypass a cross-model review
 *    caught in an earlier version of this function.
 *  - SVG content is validated by actually parsing it as XML (DOMDocument +
 *    DOMXPath) and checking resolved element/attribute local names — a
 *    literal-substring blocklist is defeated by XML namespace-prefix
 *    renaming (e.g. "<x:script>" with an xmlns:x bound to the SVG
 *    namespace) and by character references reconstructing a blocked
 *    string at parse time; a real parser resolves both correctly by
 *    construction. See _pp_svg_content_is_safe().
 *  - Base64 payloads are decoded and parsed with the exact same check —
 *    "the alphabet is inert" only protects the HTML/CSS transport, not the
 *    SVG document it decodes to.
 *
 * @param string $url    Candidate image source (http(s) URL, relative path, or data: URI).
 * @param int    $depth  Internal recursion guard — a nested data: URI found
 *                        inside an SVG's own href/xlink:href is validated by
 *                        calling this function again (see
 *                        _pp_svg_content_is_safe()); this bounds how deeply
 *                        data URIs can nest inside each other before being
 *                        rejected outright. Callers never need to pass this.
 * @return string  Safe-to-echo value, or '' if the input is empty or rejected.
 */
function pp_esc_image_src(string $url, int $depth = 0): string {
    if ($url === '') {
        return '';
    }

    if ($depth > 3) {
        return '';
    }

    if (stripos($url, 'data:') !== 0) {
        // esc_url()'s allowed-character set includes '(' and ')' (it's
        // designed for generic href="" safety, not this CSS-unquoted-url()
        // context specifically) — an ordinary URL containing a literal ')'
        // can still close the surrounding CSS url() token early and inject
        // trailing declarations into the style attribute (adversarial
        // review finding; pre-existing behavior, not introduced by this
        // function, but this is now the one place all 6 call sites route
        // through). Percent-encoding it is transparent to every legitimate
        // consumer of the URL.
        return str_replace(')', '%29', esc_url($url));
    }

    // Sanity bound against pathologically large inline payloads.
    $max_data_uri_length = 1_000_000;
    if (strlen($url) > $max_data_uri_length) {
        return '';
    }

    if (!preg_match('/^data:image\/(png|jpe?g|gif|webp|svg\+xml)(;charset=([a-z0-9_-]+))?(;base64)?,(.*)$/isD', $url, $m)) {
        return '';
    }

    $mime      = strtolower($m[1]);
    $charset   = $m[3];
    $is_base64 = $m[4] !== '';
    $raw_data  = $m[5];

    // Only utf-8 (or unspecified, which defaults to utf-8 for text-ish
    // media types) is allowed. Other charsets — utf-7 above all — are a
    // known vector for a downstream parser to reconstruct blocked byte
    // sequences that a byte-level blocklist never sees (cross-model finding).
    if ($charset !== '' && strtolower($charset) !== 'utf-8') {
        return '';
    }
    $charset_segment = $charset !== '' ? ';charset=utf-8' : '';

    if ($is_base64) {
        // 'D' anchors $ to the true string end (without it, PCRE also
        // matches just before a single trailing "\n", which would let an
        // unescaped newline slip into an unquoted CSS url() token below).
        if ($raw_data === '' || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $raw_data)) {
            return '';
        }
        if ($mime === 'svg+xml') {
            $decoded = base64_decode($raw_data, true);
            if ($decoded === false || !_pp_svg_content_is_safe($decoded, $depth)) {
                return '';
            }
        }
        // Base64 alphabet is inert in both HTML-attribute and CSS-url()
        // contexts — the string is returned exactly as validated.
        return 'data:image/' . $mime . $charset_segment . ';base64,' . $raw_data;
    }

    // Raw/percent-encoded payload. Decode to a TRUE fixed point (handles
    // single- AND arbitrarily-multiply-percent-encoded input identically)
    // so the safety check runs against the same logical content the
    // browser will eventually parse. A capped-but-incomplete decode is not
    // safe here: this function's own re-encoding step below only adds new
    // %XX sequences for specific dangerous characters, it never touches a
    // pre-existing '%' — so any encoding layer left un-decoded by the
    // check survives into the output and gets removed by the browser's own
    // single decode pass at render time, revealing content the check never
    // saw (adversarial review finding: a 6-times-encoded payload survived
    // an earlier 5-round cap this way). If genuine convergence takes more
    // than $max_decode_rounds passes, that is itself not legitimate
    // content — reject outright rather than proceed on a partial decode.
    $max_decode_rounds = 20;
    $decoded = $raw_data;
    $converged = false;
    for ($i = 0; $i < $max_decode_rounds; $i++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            $converged = true;
            break;
        }
        $decoded = $next;
    }
    if (!$converged) {
        return '';
    }

    if ($mime === 'svg+xml' && !_pp_svg_content_is_safe($decoded, $depth)) {
        return '';
    }

    // Percent-ENCODE (never strip) every character that's unsafe once
    // embedded in an HTML attribute that also contains an unquoted CSS
    // url() token: quotes, angle brackets, parens, #, &, backslash, DEL
    // (\x7F), and all C0 control/whitespace characters (\x00-\x20, which
    // covers space, tab, newline, and CR in one pass). Backslash matters
    // even though it looks inert: CSS's own url()-token tokenizer resolves
    // "\XX" backslash escapes (independent of, and prior to,
    // percent-decoding) while extracting the token's value — content that
    // is completely inert to DOMDocument (e.g. a literal "\3c" is just
    // three harmless characters to an XML parser) can decode to "<" once
    // the browser's CSS tokenizer processes the surrounding
    // style="...url(...)..." attribute, at the 4 background-image call
    // sites (adversarial review finding). DEL is also non-printable per
    // the CSS Syntax spec's unquoted-url-token grammar (adversarial review
    // finding, low severity — worst case is a broken url() token, not a
    // context breakout, since every character a "bad url" recovery scan
    // could otherwise exploit is already excluded here).
    $encoded = preg_replace_callback(
        '/["\'<>()#&\\\\\x00-\x20\x7F]/',
        function (array $match): string {
            return '%' . strtoupper(bin2hex($match[0]));
        },
        $decoded
    );

    return 'data:image/' . $mime . $charset_segment . ',' . $encoded;
}

/**
 * Renders a component's <img> tag, responsively when possible.
 *
 * When $attachment_id resolves to a real, existing attachment,
 * renders via wp_get_attachment_image() so WordPress emits srcset/sizes
 * from its registered image sizes (#107). Falls back to a plain
 * <img src> using $url when no attachment id is set, or it doesn't
 * resolve to anything (deleted attachment, wrong id) — every composition
 * that only ever set a raw image_url (hotlinked or otherwise) keeps
 * rendering exactly as before this function existed.
 *
 * @param string $url            Fallback image URL.
 * @param string $alt            Alt text.
 * @param string $class          CSS class for the <img> tag.
 * @param string $loading        'lazy' or 'eager'.
 * @param int    $attachment_id  Optional Media Library attachment ID.
 * @return string  Full <img> HTML, already escaped.
 */
function pp_render_responsive_image(string $url, string $alt, string $class, string $loading, int $attachment_id = 0): string {
    if ($attachment_id > 0) {
        $html = wp_get_attachment_image($attachment_id, 'large', false, [
            'class'   => $class,
            'alt'     => $alt,
            'loading' => $loading,
        ]);
        if ($html !== '') {
            return $html;
        }
    }

    if ($url === '') {
        return '';
    }

    return sprintf(
        '<img src="%s" alt="%s" class="%s" loading="%s">',
        pp_esc_image_src($url),
        esc_attr($alt),
        esc_attr($class),
        esc_attr($loading)
    );
}

/**
 * True if decoded SVG markup contains no script-executing constructs.
 * See the security note on pp_esc_image_src() — this is the primary
 * defense, not defense in depth, because a data: URI is reachable as a
 * top-level navigation (e.g. "Open image in new tab") regardless of how
 * it's embedded in the page.
 *
 * Parses with DOMDocument/DOMXPath rather than string/regex matching. A
 * regex blocklist on raw text is defeated by XML namespace prefix renaming
 * — e.g. `<x:script>` with `xmlns:x="http://www.w3.org/2000/svg"` declared
 * elsewhere in the document is exactly equivalent to `<script>` per XML
 * namespace rules, but a literal "<script" substring search never sees it
 * (cross-model review finding). XPath's local-name() resolves the TRUE
 * element/attribute name regardless of prefix, closing that class of
 * bypass by construction instead of by an ever-growing list of special
 * cases. A real parser also resolves standard XML character references
 * (e.g. "&#x61;" -> "a") as a normal part of parsing, so by the time an
 * attribute value is inspected here it's already the same string a
 * rendering engine would see — no separate entity-obfuscation check needed.
 *
 * DOCTYPE is rejected outright (not "parsed carefully") — this closes the
 * entire external-entity/DTD attack surface without depending on getting a
 * specific combination of libxml flags exactly right.
 */
/**
 * Resolves CSS Syntax Level 3 escape sequences (a backslash followed by
 * 1-6 hex digits and an optional trailing whitespace terminator names a
 * codepoint; a backslash followed by any other character is that character
 * literally) anywhere in a string, including inside what would otherwise
 * read as a bare identifier or function name.
 *
 * This matters because a browser's CSS value parser resolves these escapes
 * BEFORE it recognizes tokens such as the "url" function name — an SVG
 * presentation attribute value like `fill="u\72l(https://evil.test/p.svg)"`
 * is not the literal substring "url(" and evades a plain substring/regex
 * scan for it, but Chromium resolves `\72` to "r" while parsing the value
 * and fetches the external resource exactly as if "url(" had been written
 * literally (sixth-round adversarial review finding, confirmed empirically
 * via Playwright in both raw and base64-encoded payload forms). Scanning
 * the CSS-unescaped form of every attribute value closes this by resolving
 * escapes the same way a real CSS parser would, rather than special-casing
 * "url" — the same escape trick could equally hide "javascript:" or
 * "data:" from the scheme checks below, so both scans run on the
 * unescaped form too.
 */
function _pp_css_unescape(string $value): string {
    $decoded = preg_replace_callback(
        '/\\\\(?:([0-9a-fA-F]{1,6})[ \t\n\r\f]?|(.))/su',
        function (array $m): string {
            if (($m[1] ?? '') !== '') {
                $code = hexdec($m[1]);
                if ($code === 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                    return "\u{FFFD}";
                }
                return mb_chr($code, 'UTF-8');
            }
            return $m[2] ?? '';
        },
        $value
    );

    return $decoded ?? $value;
}

function _pp_svg_content_is_safe(string $svg, int $depth = 0): bool {
    if (stripos($svg, '<!doctype') !== false) {
        return false;
    }

    $prev_setting = libxml_use_internal_errors(true);
    $doc = new \DOMDocument();
    // No LIBXML_DTDLOAD/LIBXML_DTDATTR — never fetch or apply an external
    // DTD (moot anyway since DOCTYPE is rejected above, but belt and
    // suspenders). LIBXML_NONET blocks any network access during parsing.
    $parsed = $doc->loadXML($svg, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev_setting);

    if (!$parsed) {
        return false;
    }

    $xpath = new \DOMXPath($doc);
    $lower_local_name = "translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";

    // Any processing instruction (e.g. <?xml-stylesheet href="javascript:...">
    // before the root element) is rejected outright rather than inspected —
    // PIs aren't attributes, so the attribute-value checks below never see
    // one, and xml-stylesheet's href has real (if legacy/browser-specific)
    // script/resource-loading-triggering history (adversarial review finding).
    $pis = $xpath->query('//processing-instruction()');
    if ($pis === false || $pis->length > 0) {
        return false;
    }

    // SMIL animation elements (animate/animateMotion/animateTransform/
    // animateColor/set) can retarget attributes like href/xlink:href to a
    // javascript: value over time via their values/to/from attributes —
    // a static per-attribute scan doesn't parse that semicolon-separated
    // mini-language, so these elements are rejected outright. <style> is
    // also rejected outright: unlike <script>, an SVG's own <style> element
    // IS applied during ordinary rendering (as an <img> or CSS
    // background-image, not just a top-level-navigation "open image in new
    // tab") — an `@import`/`url()` inside it fires on every page view, not
    // just for a user who takes an extra action (adversarial review finding).
    $dangerous_elements = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'animate', 'animatemotion', 'animatetransform', 'animatecolor', 'set', 'style'];
    foreach ($dangerous_elements as $name) {
        $matches = $xpath->query("//*[{$lower_local_name}='{$name}']");
        if ($matches === false || $matches->length > 0) {
            return false;
        }
    }

    // Event-handler attributes (onload, onclick, ...), any element/namespace.
    $on_attrs = $xpath->query("//@*[starts-with({$lower_local_name}, 'on')]");
    if ($on_attrs === false || $on_attrs->length > 0) {
        return false;
    }

    // xml:base (SVG/XML's equivalent of HTML's <base href>) is rejected
    // outright. Every check below treats a "#fragment" reference (and,
    // symmetrically, a value starting with "data:") as unconditionally safe
    // on the theory that a fragment-only reference always resolves within
    // the current document — but per RFC 3986 §5.3, resolving a
    // fragment-only reference against a base URL yields the BASE's
    // scheme+authority+path with only the fragment replaced. xml:base on
    // the root <svg> (or any ancestor) sets exactly that base, so
    // `fill="url(#leak)"` or `<use href="#leak">` would resolve against an
    // attacker-controlled origin instead of the document itself, turning
    // an allowed-by-design same-document reference into a cross-origin
    // fetch during ordinary rendering — undermining the "#... is always
    // safe" assumption both the url() scan and the href scan below rely on
    // (fifth-round Claude adversarial review finding). No legitimate
    // AI/human-authored image SVG has a reason to override its own base
    // URI, so the whole attribute is rejected rather than special-cased.
    $xml_base_attrs = $xpath->query("//@*[namespace-uri()='http://www.w3.org/XML/1998/namespace' and local-name()='base']");
    if ($xml_base_attrs === false || $xml_base_attrs->length > 0) {
        return false;
    }

    $all_attrs = $xpath->query('//@*');
    if ($all_attrs === false) {
        return false;
    }
    foreach ($all_attrs as $attr) {
        $value = (string) $attr->nodeValue;
        // Resolve CSS escape sequences before pattern-matching — see
        // _pp_css_unescape() docblock. A value with no escapes is unchanged.
        $unescaped = _pp_css_unescape($value);

        // SVG has many presentation attributes/CSS properties that reference
        // another resource via a CSS-style url(...) wrapper — fill, stroke,
        // filter, mask, clip-path, marker-start/mid/end, and cursor (usually
        // via a style="..." attribute) among them. All of these are applied
        // during ORDINARY rendering (fetched for every visitor, not gated
        // behind a click or top-level navigation) — confirmed empirically
        // against a real browser (adversarial review: style="filter:url(...)",
        // filter="url(...)", fill="url(...)", and style="cursor:url(...)"
        // all triggered real network requests to an external host when
        // rendered). Rather than enumerate every attribute name that can
        // carry a url() reference (an open-ended, easy-to-miss list), every
        // attribute value is scanned generically for the url(...) pattern
        // and any reference that isn't a same-document fragment or a
        // data: URI is rejected.
        if (preg_match_all('/url\(\s*[\'"]?([^\'")]*)[\'"]?\s*\)/i', $unescaped, $url_matches)) {
            foreach ($url_matches[1] as $ref) {
                $ref = trim($ref);
                if ($ref !== '' && $ref[0] !== '#' && stripos($ref, 'data:') !== 0) {
                    return false;
                }
            }
        }

        // Dangerous URI schemes in any attribute value (href, xlink:href,
        // etc.) — already-entity-resolved by the parser, so no separate
        // decoding needed. Tab/newline/CR are stripped before the prefix
        // check because browser URL parsing strips them from anywhere in a
        // URL string (a well-known normalization step, not unique to this
        // codebase) — "java&#x0A;script:" resolves to "javascript:" at
        // navigation time even though the DOM attribute value still has the
        // embedded newline (adversarial review finding). data:text/html (or
        // any non-image data: scheme) is rejected alongside javascript: — a
        // nested <a href> inside an already-opened SVG is a real, if
        // lower-likelihood, escalation path.
        $normalized = preg_replace('/[\t\n\r]/', '', $unescaped);
        if (preg_match('/^\s*javascript:/i', $normalized)) {
            return false;
        }
        if (preg_match('/^\s*data:(?!image\/)/i', $normalized)) {
            return false;
        }
    }

    // href/xlink:href are fetched during ordinary rendering on many more
    // elements than just <use>/<image> — <feImage> (an SVG filter
    // primitive whose entire purpose is fetching an image resource) has
    // the exact same semantics and is neither a "dangerous element" nor
    // named use/image; <pattern>, gradients, and <textPath> can carry the
    // same reference. Rather than enumerate elements (an open-ended,
    // easy-to-miss list — the same mistake already made once in this
    // function for <use>/<image> specifically), every href/xlink:href
    // attribute in the document is checked regardless of which element
    // it's on (adversarial review finding). local-name()='href' matches
    // both the modern unprefixed `href` and the legacy `xlink:href` — an
    // XML namespace prefix doesn't change the local name, only the
    // namespace it resolves to. Restrict to a same-document fragment (#id)
    // or a data: URI; reject any external reference.
    $href_attrs = $xpath->query("//@*[{$lower_local_name}='href']");
    if ($href_attrs === false) {
        return false;
    }
    foreach ($href_attrs as $href) {
        $value = trim((string) $href->nodeValue);
        if ($value === '' || $value[0] === '#') {
            continue;
        }
        if (stripos($value, 'data:') !== 0) {
            return false;
        }
        // A nested data: URI (e.g. a <use href="data:image/svg+xml,...">)
        // is not automatically safe just because it's a data: URI — it
        // must pass the exact same validation as the outer one, recursively.
        // Whether <use>'s clone-based reference model treats this content
        // as inert "image data" or a live, scriptable subtree wasn't
        // something static analysis could rule out with confidence
        // (adversarial review finding) — recursing removes the ambiguity
        // outright rather than shipping an unconfirmed-but-plausible gap in
        // what this function's own docblock calls the primary XSS defense.
        if (pp_esc_image_src($value, $depth + 1) === '') {
            return false;
        }
    }

    return true;
}

/**
 * Returns all design token overrides from the database.
 * These are site-specific values that override the product defaults in base.css.
 *
 * @return array  Associative array of token name => value, e.g. ['--color-accent' => '#b45309'].
 */
function pp_get_token_overrides(): array {
    $overrides = get_option('pp_token_overrides', []);
    if (!is_array($overrides)) {
        return [];
    }
    return $overrides;
}

// ── Token-override write serialization (#97) ────────────────────────────────
// All three writers below do a read-modify-write on the single `pp_token_overrides`
// option. Concurrent applies (agents are told to parallelize tool calls) otherwise
// last-writer-wins and silently lose updates. We serialize the critical section with
// a connection-scoped MySQL advisory lock (GET_LOCK).

/**
 * Bounded GET_LOCK wait (seconds) so a stuck holder cannot block an apply forever.
 * Override with the PP_TOKEN_LOCK_TIMEOUT constant.
 */
function _pp_token_lock_timeout(): int {
    return defined('PP_TOKEN_LOCK_TIMEOUT') ? (int) PP_TOKEN_LOCK_TIMEOUT : 5;
}

/**
 * Install-scoped advisory lock name for the pp_token_overrides option. Includes DB
 * name + blog id so writers on the SAME store serialize while unrelated sites/installs
 * never collide. MySQL caps lock names at 64 chars; the md5 slice keeps it bounded.
 */
function _pp_token_lock_name(): string {
    global $wpdb;
    $db   = defined('DB_NAME') ? DB_NAME : (isset($wpdb->dbname) ? $wpdb->dbname : 'db');
    $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    return 'pp_tokovr_' . substr(md5($db . '|' . $blog), 0, 32);
}

/**
 * Runs $mutator inside a serialized critical section guarded by a named MySQL advisory
 * lock. Shared engine behind both the token-override lock (_pp_with_token_lock, one
 * install-scoped name) and the per-post composition lock (_pp_with_composition_lock,
 * one name per post) — they differ only in the lock NAME, so the GET_LOCK acquire /
 * bounded-timeout / release-in-finally / degrade-without-$wpdb machinery lives here once.
 *
 * Acquires the lock with a bounded timeout, runs the mutator, and releases in `finally`
 * so normal AND exception unwinding both free it. This is not an absolute guarantee — a
 * hard fatal/SIGKILL or a persistent connection can still strand a lock — so the bounded
 * acquire timeout and MySQL's connection-close auto-release are the backstops. On
 * acquisition failure the mutator does NOT run and $fail_value is returned (explicit
 * failure, never a silent partial write). Degrades to running the mutator directly when
 * no $wpdb is present (unit context); production always has $wpdb.
 *
 * @param string   $lock_name   The raw advisory-lock name (bounded <= 64 chars by callers).
 * @param callable $mutator     Performs the cache-authoritative read/modify/write.
 * @param mixed    $fail_value  Returned if the lock cannot be acquired.
 * @param string   $context     Short label for the error_log line on acquisition failure.
 * @return mixed
 */
function _pp_with_advisory_lock(string $lock_name, callable $mutator, $fail_value, string $context = 'advisory lock') {
    global $wpdb;
    $has_db = isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var');
    if (!$has_db) {
        return $mutator(null);
    }

    $name = $wpdb->prepare('%s', $lock_name);
    // GET_LOCK: '1' acquired, '0' timed out (another writer holds it), NULL on error
    // (killed connection, OOM, privilege) or on a backend without GET_LOCK. WordPress
    // core requires MySQL/MariaDB, which always support GET_LOCK, so any non-'1' result
    // means we could not safely serialize. Skip the write and surface an explicit
    // failure for the caller to retry, rather than write unlocked and risk a lost
    // update — NULL most often means the DB is unhealthy, exactly when the race bites.
    $got = $wpdb->get_var("SELECT GET_LOCK($name, " . _pp_token_lock_timeout() . ")");
    if ($got !== '1' && $got !== 1) {
        $reason = ($got === '0' || $got === 0)
            ? 'lock busy (GET_LOCK timed out after ' . _pp_token_lock_timeout() . 's)'
            : 'lock unavailable (GET_LOCK returned ' . var_export($got, true) . ')';
        error_log('PromptingPress: ' . $context . ' ' . $reason
            . '; write skipped to avoid a lost update.');
        return $fail_value;
    }

    try {
        return $mutator($wpdb);
    } finally {
        $wpdb->query("SELECT RELEASE_LOCK($name)");
    }
}

/**
 * Runs $mutator inside a serialized critical section for pp_token_overrides.
 *
 * Thin wrapper over _pp_with_advisory_lock() keyed on the single install-scoped token
 * lock name. On acquisition failure the mutator does NOT run and $fail_value is returned.
 *
 * @param callable $mutator     Performs the cache-authoritative read/modify/write.
 * @param mixed    $fail_value  Returned if the lock cannot be acquired.
 * @return mixed
 */
function _pp_with_token_lock(callable $mutator, $fail_value) {
    return _pp_with_advisory_lock(_pp_token_lock_name(), $mutator, $fail_value, 'pp_token_overrides');
}

// ── Composition-write serialization (#113) ──────────────────────────────────
// pp_update_composition() does a read-modify-write of the freshness marker
// (_pp_composition_version / _pp_composition_hash) alongside the composition
// itself. Concurrent writers to the SAME post otherwise interleave and lose a
// version bump. We serialize per post with a MySQL advisory lock — the #200
// lesson (a lock-acquire failure propagates, never a silent non-atomic write),
// applied at composition-write time.

/**
 * Per-post advisory lock name for a composition write. Includes DB name + blog id (so
 * writers on the SAME store serialize while unrelated sites/installs never collide) plus
 * the post id (so writes to DIFFERENT posts never serialize against each other). MySQL
 * caps lock names at 64 chars; the md5 slice keeps it bounded regardless of DB name.
 */
function _pp_composition_lock_name(int $post_id): string {
    global $wpdb;
    $db   = defined('DB_NAME') ? DB_NAME : (isset($wpdb->dbname) ? $wpdb->dbname : 'db');
    $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    return 'pp_comp_' . substr(md5($db . '|' . $blog . '|' . $post_id), 0, 32);
}

/**
 * Runs $mutator inside a serialized critical section for a single post's composition.
 * Thin wrapper over _pp_with_advisory_lock() keyed per post. On acquisition failure the
 * mutator does NOT run and $fail_value is returned.
 *
 * @param int      $post_id     The post whose composition write is being serialized.
 * @param callable $mutator     Performs the marker read/bump + composition write.
 * @param mixed    $fail_value  Returned if the lock cannot be acquired.
 * @return mixed
 */
function _pp_with_composition_lock(int $post_id, callable $mutator, $fail_value) {
    return _pp_with_advisory_lock(
        _pp_composition_lock_name($post_id),
        $mutator,
        $fail_value,
        'composition post ' . $post_id
    );
}

/**
 * Reads the current _pp_composition_version straight from the DB inside the composition
 * lock, bypassing the post-meta object cache (#113).
 *
 * The freshness check (pp_get_composition_marker) runs BEFORE the lock and warms the meta
 * cache with the pre-write version. Reading through get_post_meta() inside the lock would
 * then return that stale cached value, so two writers serialized by the lock could both
 * compute the same next version — a lost bump, the exact lost-update the lock exists to
 * prevent (the #200 lesson, mirrored from _pp_read_token_overrides_locked_strict). Reading
 * the one meta row directly keeps the counter monotonic per write. Degrades to the cached
 * read when no $wpdb is present (unit context), where the store isn't shared across
 * processes so staleness can't arise.
 *
 * @param object|null $wpdb     The DB handle inside the lock, or null in unit context.
 * @param int         $post_id  WordPress post ID.
 * @return int  The current version (0 if absent).
 */
function _pp_read_composition_version_locked($wpdb, int $post_id): int {
    if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $post_id,
                '_pp_composition_version'
            )
        );
        return (int) $raw; // absent row → null → 0
    }
    return (int) get_post_meta($post_id, '_pp_composition_version', true);
}

/**
 * Reads the current stored composition JSON straight from the DB inside the composition
 * lock, bypassing the post-meta object cache (#133).
 *
 * The composition history ring (see pp_update_composition) must capture the EXACT prior
 * stored payload — the bytes a later restore replays — so it reads `_pp_composition`
 * directly, the same lost-update reasoning as _pp_read_composition_version_locked: the
 * pre-lock freshness check may have warmed a stale meta cache. Returns the raw JSON
 * string, or null when the row is absent (a brand-new page with no prior state to
 * preserve). Degrades to the cached read with no $wpdb (unit context).
 *
 * @param object|null $wpdb     The DB handle inside the lock, or null in unit context.
 * @param int         $post_id  WordPress post ID.
 * @return string|null  The stored composition JSON, or null if absent.
 */
function _pp_read_composition_json_locked($wpdb, int $post_id): ?string {
    if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $post_id,
                '_pp_composition'
            )
        );
        return ($raw === null) ? null : (string) $raw;
    }
    $raw = get_post_meta($post_id, '_pp_composition', true);
    // get_post_meta(single=true) returns '' for an absent key; treat only genuine
    // absence as "no prior state", matching pp_get_composition_result's guard.
    return ($raw === '' || $raw === false || $raw === null) ? null : (string) $raw;
}

/**
 * Computes the freshness content-hash of a composition (#113).
 *
 * Hashes the CANONICAL pre-stable-id form: the auto-generated top-level props.id (the
 * only field pp_update_composition() injects) is stripped before hashing, so the hash is
 * stable across the id-injection round-trip — a composition written without ids hashes
 * the same as the same composition re-read WITH its injected ids. Without this, every
 * write would false-conflict against itself.
 *
 * The marker compares two hashes both produced HERE at write time (never a rebuilt one),
 * so byte-stable canonicalization beyond the id strip is not required for correctness.
 *
 * @param array $composition  Array of component objects.
 * @return string  A 64-char sha256 hex digest.
 */
function pp_composition_content_hash(array $composition): string {
    $canonical = array_map(function ($item) {
        if (is_array($item) && isset($item['props']) && is_array($item['props'])) {
            unset($item['props']['id']);
        }
        return $item;
    }, $composition);
    return hash('sha256', (string) wp_json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Returns the freshness marker for a post's composition (#113).
 *
 * The marker is the {version, hash} pair written by pp_update_composition() as sibling
 * post meta. An absent marker (a page never written through pp_update_composition, or a
 * legacy page) reads as version 0 / hash '' — the first write initializes version 1.
 * Strict casts at this boundary guarantee the caller always gets an int + a string, so a
 * downstream hash_equals() never sees a non-string.
 *
 * @param int $post_id  WordPress post ID.
 * @return array{version: int, hash: string}
 */
function pp_get_composition_marker(int $post_id): array {
    return [
        'version' => (int) get_post_meta($post_id, '_pp_composition_version', true),
        'hash'    => (string) get_post_meta($post_id, '_pp_composition_hash', true),
    ];
}

// ── Composition history ring (#133) ─────────────────────────────────────────
// Design-token writes earned a full snapshot/restore subsystem; composition
// writes (page content) had none — a replaced or component-removed composition
// was lost permanently. Every composition write now pushes the PRIOR state onto
// a bounded per-post history meta so an operator/AI can restore it. The push
// happens inside pp_update_composition's per-post advisory lock, alongside the
// #113 marker bump, so history stays consistent with the version counter.
//
//   pp_update_composition(post, C_new)
//     └─ [lock] read prior JSON J_prior  ──►  push {ts, version, hash, C_prior}
//                write C_new, bump marker      onto _pp_composition_history (ring, last N)
//
// restore_composition (lib/actions.php) reads this ring and re-writes a chosen
// entry's composition back through pp_update_composition — so a restore is
// itself a conflict-checked write that lands its own history entry.

/**
 * Maximum number of prior-composition snapshots retained per post (#133).
 *
 * A bounded ring: the Nth-oldest entry is evicted when a newer write pushes past N.
 * Fixed (not configurable) — 10 covers realistic undo depth without unbounded meta
 * growth on a hot page.
 *
 * @return int
 */
function pp_composition_history_max(): int {
    return 10;
}

/**
 * Returns the composition history ring for a post, newest-last (#133).
 *
 * Each entry is `{timestamp:int, version:int, hash:string, composition:array}` — the
 * composition is the state as it was BEFORE the write that pushed the entry. Defensive
 * like pp_get_composition_result: an absent, non-JSON, non-list, or shape-wrong meta row
 * degrades to [] (no history) rather than fataling, and malformed individual entries are
 * dropped. Callers get a clean list they can index or walk backwards.
 *
 * @param int $post_id  WordPress post ID.
 * @return array  List of history entries, oldest first, or [] if none/unreadable.
 */
function pp_get_composition_history(int $post_id): array {
    $raw = get_post_meta($post_id, '_pp_composition_history', true);
    if ($raw === '' || $raw === null || $raw === false) {
        return [];
    }
    $entries = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($entries) || !pp_is_list($entries)) {
        return [];
    }
    $clean = [];
    foreach ($entries as $entry) {
        if (is_array($entry) && isset($entry['composition']) && is_array($entry['composition'])) {
            $clean[] = [
                'timestamp'   => (int) ($entry['timestamp'] ?? 0),
                'version'     => (int) ($entry['version'] ?? 0),
                'hash'        => (string) ($entry['hash'] ?? ''),
                'composition' => $entry['composition'],
            ];
        }
    }
    return $clean;
}

/**
 * Reads pp_token_overrides authoritatively inside the lock, distinguishing an ABSENT
 * row from an UNREADABLE (corrupt/truncated/non-array) one. Reads the single option row
 * straight from the DB (bypassing the options cache) so a concurrent writer's
 * just-committed value is visible and a stale cached map can't overwrite a newer one
 * inside the critical section. Reads exactly the one row rather than busting the whole
 * `alloptions` autoload cache.
 *
 * Four distinct outcomes (#207 + #212):
 *   - read FAILED         → null  (DB error on the SELECT — #212, fail closed)
 *   - no row              → []    (legitimately no overrides)
 *   - row unserializes to an array → that array
 *   - row exists but does NOT unserialize to an array → null (UNREADABLE)
 *
 * #212: $wpdb->get_var() returns null in TWO distinct cases — the query ran and matched
 * no rows (a genuinely absent row → correct []), AND the query FAILED (DB error, killed
 * connection mid-statement). Treating a read failure as "no overrides exist" would record
 * [] as the run's rollback baseline, and a later `apply restore` would DELETE every touched
 * token (the unset() branch of pp_revert_tokens) — the exact silent loss #200/#207 close.
 * Disambiguate via $wpdb->last_error: wpdb::query() flushes it to '' at the start of every
 * query and sets it on error, so after the option SELECT a non-empty last_error means the
 * read failed → fail closed (null). This check must precede the ($raw === null) branch: a
 * failed read also returns null, and must not be mistaken for a genuinely absent row.
 *
 * Only the snapshot caller (pp_snapshot_token_overrides) needs the unreadable-vs-empty
 * distinction, so it reads through this strict variant. The writer paths read through
 * _pp_read_token_overrides_locked(), which coerces null to [] — see that wrapper.
 *
 * @param object|null $wpdb  The DB handle inside the lock, or null in unit context.
 * @return array|null  The overrides map, [] if absent, or null if the read failed or the
 *                     row is unreadable.
 */
function _pp_read_token_overrides_locked_strict($wpdb = null): ?array {
    if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'pp_token_overrides'
            )
        );
        // #212: a read failure (non-empty last_error) is NOT an absent row — fail closed.
        // Checked before ($raw === null) because a failed get_var() also yields null.
        if (! empty($wpdb->last_error)) {
            return null;
        }
        if ($raw === null) {
            return [];
        }
        $value = maybe_unserialize($raw);
        return is_array($value) ? $value : null;
    }
    // No DB handle (unit context): fall back to the cached/stubbed option.
    return pp_get_token_overrides();
}

/**
 * Reads pp_token_overrides authoritatively inside the lock for the WRITER paths, where
 * `[]`-means-"start fresh" is the correct handling of both an absent AND an unreadable
 * row. Thin wrapper over _pp_read_token_overrides_locked_strict() that coerces the
 * strict variant's null (unreadable row) back to []. Keeping this coercion here — not in
 * the strict read — is the Option A boundary from #207: only the rollback-baseline
 * snapshot treats an unreadable row as a hard failure; set/clear/clear-all/revert keep
 * their pre-#207 "start fresh on a missing/unreadable row" semantics unchanged.
 *
 * @param object|null $wpdb  The DB handle inside the lock, or null in unit context.
 */
function _pp_read_token_overrides_locked($wpdb = null): array {
    $value = _pp_read_token_overrides_locked_strict($wpdb);
    return $value === null ? [] : $value;
}

/**
 * Sets a single design token override in the database.
 *
 * @param string $token  CSS custom property name (e.g. '--color-accent').
 * @param string $value  The override value.
 * @return bool  True on success; false if the write failed or the lock was not acquired.
 */
function pp_set_token_override(string $token, string $value): bool {
    return _pp_with_token_lock(function ($wpdb) use ($token, $value) {
        $overrides = _pp_read_token_overrides_locked($wpdb);
        $overrides[$token] = $value;
        $result = update_option('pp_token_overrides', $overrides, true);
        pp_invalidate_design_tokens_cache();
        return $result;
    }, false);
}

/**
 * Clears a single design token override, reverting it to the product default.
 *
 * @param string $token  CSS custom property name.
 * @return bool  True if the token was present and removed; false if absent or unlocked.
 */
function pp_clear_token_override(string $token): bool {
    return _pp_with_token_lock(function ($wpdb) use ($token) {
        $overrides = _pp_read_token_overrides_locked($wpdb);
        if (!array_key_exists($token, $overrides)) {
            return false;
        }
        unset($overrides[$token]);
        if (empty($overrides)) {
            delete_option('pp_token_overrides');
        } else {
            update_option('pp_token_overrides', $overrides, true);
        }
        pp_invalidate_design_tokens_cache();
        return true;
    }, false);
}

/**
 * Clears all design token overrides, reverting the entire site to product defaults.
 *
 * @return int  Number of overrides that were cleared (0 if none or lock not acquired).
 */
function pp_clear_all_token_overrides(): int {
    return _pp_with_token_lock(function ($wpdb) {
        $overrides = _pp_read_token_overrides_locked($wpdb);
        $count = count($overrides);
        if ($count > 0) {
            delete_option('pp_token_overrides');
            pp_invalidate_design_tokens_cache();
        }
        return $count;
    }, 0);
}

/**
 * Reverts a scoped set of token overrides to the values held in a frozen snapshot.
 *
 * This is the rollback primitive behind `wp pp apply restore`. Unlike the whole-map
 * writers above, it touches ONLY the keys in $touched_keys, so unrelated overrides
 * (including ones written by later runs) are preserved:
 *   - key present in $snapshot  → set the override to the snapshot value
 *   - key absent from $snapshot → clear the override (the run created it)
 *   - key not in $touched_keys  → left exactly as-is
 *
 * Runs inside the same advisory-lock critical section as the other writers, doing one
 * read-modify-write of pp_token_overrides so the revert is atomic against concurrent
 * applies. Fail-closed: every scoped snapshot value is validated against the live token
 * registry BEFORE any write, and a single invalid entry (corrupt/hand-edited snapshot
 * file) aborts the entire revert with NO mutation — never a partial restore.
 *
 * @param array $snapshot      token => value map captured pre-apply (the prior state).
 * @param array $touched_keys  the keys this run wrote (primary + derived); the revert scope.
 * @return bool  True on a confirmed write; false if the lock was not acquired OR a scoped
 *               snapshot entry was invalid (in which case nothing is mutated).
 */
function pp_revert_tokens(array $snapshot, array $touched_keys): bool {
    return _pp_with_token_lock(function ($wpdb) use ($snapshot, $touched_keys) {
        $registry = pp_design_tokens();

        // Pre-validate the whole scope BEFORE touching anything. Any scoped key that is
        // present in the snapshot must be a registered token with a value valid for its
        // type. One bad entry aborts with no write — fail-closed, no partial mutation.
        foreach ($touched_keys as $key) {
            if (!is_string($key) || !array_key_exists($key, $snapshot)) {
                continue;
            }
            $value = $snapshot[$key];
            if (!is_string($value) || !array_key_exists($key, $registry)
                || _pp_validate_token_value($value, $registry[$key]['type'] ?? null) !== true) {
                return false;
            }
        }

        $overrides = _pp_read_token_overrides_locked($wpdb);
        foreach ($touched_keys as $key) {
            if (!is_string($key)) {
                continue;
            }
            if (array_key_exists($key, $snapshot)) {
                $overrides[$key] = $snapshot[$key];
            } else {
                // The run created this override; rolling back removes it.
                unset($overrides[$key]);
            }
        }

        if (empty($overrides)) {
            delete_option('pp_token_overrides');
        } else {
            update_option('pp_token_overrides', $overrides, true);
        }
        pp_invalidate_design_tokens_cache();
        return true;
    }, false);
}

/**
 * Reads the current token overrides under the advisory lock, for snapshotting.
 *
 * Used when freezing a run's pre-apply baseline: taking the read inside the lock means
 * a concurrent apply cannot interleave and produce a baseline that never existed
 * atomically. Fail-closed on TWO distinct failure modes, both surfaced as null:
 *   1. Lock not acquired (#200): the read would be non-atomic exactly when contention
 *      is happening — the one scenario the lock exists to protect against — so it
 *      returns null instead of silently degrading to a plain cached read.
 *   2. Unreadable row (#207): a corrupt/truncated/hand-edited pp_token_overrides row
 *      that does not unserialize to an array. Reading through the STRICT locked read
 *      surfaces this as null rather than coercing it to [] — recording [] as the
 *      baseline would let a later `apply restore` delete the touched tokens instead of
 *      restoring them (an [] baseline reverts every touched key via the unset() branch
 *      of pp_revert_tokens).
 * The caller must treat null as a hard failure (no baseline recorded) rather than
 * freezing a snapshot that a later `apply restore` would roll back to. An absent row
 * still yields [] (a valid, recordable empty baseline), never null.
 *
 * @return array|null  token => value map, [] if no overrides, or null if the lock could
 *                     not be acquired OR the overrides row is unreadable.
 */
function pp_snapshot_token_overrides(): ?array {
    return _pp_with_token_lock( function ( $wpdb ) {
        return _pp_read_token_overrides_locked_strict( $wpdb );
    }, null );
}

// ── Token Family Derivation ─────────────────────────────────────────────────
// When a base token changes, derived tokens must update to stay visually coherent.

/**
 * Parses a hex color string to [r, g, b] (0-255 each).
 *
 * @param string $hex  e.g. '#7a4f2e' or '7a4f2e'.
 * @return array{int, int, int}|null  [r, g, b] or null if unparseable.
 */
function _pp_hex_to_rgb(string $hex): ?array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return null;
    }
    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Converts [r, g, b] (0-255) to a hex color string.
 */
function _pp_rgb_to_hex(int $r, int $g, int $b): string {
    return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

/**
 * Mixes a color with another at a given ratio (0.0 = all $base, 1.0 = all $mix).
 *
 * @param array{int,int,int} $base  RGB of the base color.
 * @param array{int,int,int} $mix   RGB of the mix color.
 * @param float              $ratio 0.0–1.0 blend factor toward $mix.
 * @return string  Hex color.
 */
function _pp_color_mix(array $base, array $mix, float $ratio): string {
    $r = (int) round($base[0] + ($mix[0] - $base[0]) * $ratio);
    $g = (int) round($base[1] + ($mix[1] - $base[1]) * $ratio);
    $b = (int) round($base[2] + ($mix[2] - $base[2]) * $ratio);
    return _pp_rgb_to_hex($r, $g, $b);
}

/**
 * Token family definitions: base token → derived tokens with mix ratios.
 *
 * Each derived token is produced by mixing the base color with black or white.
 * Ratios are calibrated against the product defaults in base.css so the visual
 * relationships hold regardless of the chosen accent/text hue.
 *
 * @return array<string, array<string, array{mix: 'black'|'white', ratio: float}>>
 */
function pp_token_families(): array {
    return [
        '--color-accent' => [
            '--color-accent-hover'   => ['mix' => 'black', 'ratio' => 0.15],
            '--color-accent-strong'  => ['mix' => 'black', 'ratio' => 0.30],
            '--color-border-accent'  => ['mix' => 'white', 'ratio' => 0.55],
            '--color-surface-accent' => ['mix' => 'white', 'ratio' => 0.88],
            // On-inverted accent roles (#437): the light-surface accent fails AA on
            // the dark inverted band (3.23:1), so links there route through a
            // lightened accent tint instead. Derived by mixing accent toward white
            // so a retheme's new accent auto-produces a matching on-inverted tint;
            // registered here so a pinned override that DIVERGES surfaces in the
            // #386 stale-warning / masked-derived machinery like any other derived.
            '--color-accent-on-inverted'       => ['mix' => 'white', 'ratio' => 0.55],
            '--color-accent-on-inverted-hover' => ['mix' => 'white', 'ratio' => 0.70],
            // On-overlay accent roles (#461): links/numbers on a bg-image band sit on a
            // dark rgba(0,0,0,.55) overlay over an ARBITRARY image. The worst case is the
            // overlay over pure white (rgb(115,115,115)), where the contrast ceiling for
            // ANY foreground is 4.74:1 — so the default is mechanically near-white to clear
            // AA (4.5:1). Derived by mixing accent almost fully toward white so a retheme's
            // new accent still resolves to a near-white on-overlay tint; registered here so
            // a pinned override that DIVERGES surfaces in the #386 machinery like on-inverted.
            '--color-accent-on-overlay'        => ['mix' => 'white', 'ratio' => 0.976],
            '--color-accent-on-overlay-hover'  => ['mix' => 'white', 'ratio' => 1.0],
        ],
        '--color-text' => [
            '--color-text-secondary' => ['mix' => 'white', 'ratio' => 0.20],
        ],
    ];
}

/**
 * Derives related tokens from a base token value.
 *
 * @param string $base_token  e.g. '--color-accent'.
 * @param string $base_value  Hex color value for the base token.
 * @return array<string, string>  Derived token name => hex value. Empty if not a family base or not a valid hex.
 */
function pp_derive_family_tokens(string $base_token, string $base_value): array {
    $families = pp_token_families();
    if (!isset($families[$base_token])) {
        return [];
    }

    $rgb = _pp_hex_to_rgb($base_value);
    if ($rgb === null) {
        return [];
    }

    $black = [0, 0, 0];
    $white = [255, 255, 255];
    $derived = [];

    foreach ($families[$base_token] as $derived_token => $recipe) {
        $mix_color = $recipe['mix'] === 'black' ? $black : $white;
        $derived[$derived_token] = _pp_color_mix($rgb, $mix_color, $recipe['ratio']);
    }

    return $derived;
}

/**
 * Reports whether two color values resolve to the same RGB triplet.
 *
 * Normalizes hex shorthand/case (#FFF == #ffffff), so a derived override written
 * by a prior auto-derivation compares equal to the value recomputed now. Any value
 * that is not parseable hex (e.g. an rgba()/var() pin) is treated as NOT equivalent
 * to the always-hex derived value — an intentional non-hex pin diverges from the
 * base derivation and is therefore surfaced as masking.
 *
 * @param string $a
 * @param string $b
 * @return bool
 */
function _pp_colors_equivalent(string $a, string $b): bool {
    if ($a === $b) {
        return true;
    }
    $ra = _pp_hex_to_rgb($a);
    $rb = _pp_hex_to_rgb($b);
    if ($ra === null || $rb === null) {
        return false;
    }
    return $ra === $rb;
}

/**
 * Detects existing derived-family overrides that MASK a base token change (#386).
 *
 * `update_design_token` auto-derives family tokens that have no override but
 * PRESERVES existing overrides (deliberately pinned derived values must survive).
 * A preserved override that no longer matches what the current/new base would
 * derive keeps winning in the rendered CSS, so the base change "succeeds"
 * (ok:true) yet has no visible effect where that override applies. This is the
 * masking condition: a derived override EXISTS *and* DIVERGES from the value the
 * base would derive. A coherent override (equal to the derivable value) is not
 * masking and is not reported; mere presence is not staleness.
 *
 * One shared engine, two surfaces: the apply result (`stale_warnings`) and the
 * INSPECT smell (`pp_detect_masked_derived_smells()`) both call this.
 *
 * @param string $base_token  e.g. '--color-accent'.
 * @param string $base_value  Current/new value for the base token.
 * @return array<array{token: string, current: string, expected: string, message: string}>
 *         One entry per masking derived override. Empty when the token is not a
 *         family base, the base value is not a resolvable hex, or every existing
 *         derived override is coherent with the base.
 */
function pp_masked_derived_overrides(string $base_token, string $base_value): array {
    // pp_derive_family_tokens() returns [] for non-family bases and for base
    // values that aren't resolvable hex (var()/rgba()) — no derivation, so no
    // divergence can be computed and nothing is masked.
    $derived = pp_derive_family_tokens($base_token, $base_value);
    if (empty($derived)) {
        return [];
    }

    $overrides = pp_get_token_overrides();
    $masking = [];

    foreach ($derived as $derived_token => $derived_value) {
        // No override → the token is (or will be) auto-derived from the base, so
        // it can't mask the base change. A non-string override value can only come
        // from a corrupt pp_token_overrides option (the write path enforces string);
        // skip it rather than let this advisory detector fatal on bad stored data —
        // this runs on the read-only INSPECT surface, which must stay chaos-tolerant.
        if (!isset($overrides[$derived_token]) || !is_string($overrides[$derived_token])) {
            continue;
        }
        // Coherent override → matches what the base derives; not masking.
        if (_pp_colors_equivalent($overrides[$derived_token], $derived_value)) {
            continue;
        }

        $masking[] = [
            'token'    => $derived_token,
            'current'  => $overrides[$derived_token],
            'expected' => $derived_value,
            'message'  => sprintf(
                '%s (%s) is a derived override present and unchanged; it diverges from the %s (%s) derivation (%s), so the base change may not be visible where it applies.',
                $derived_token, $overrides[$derived_token], $base_token, $base_value, $derived_value
            ),
        ];
    }

    return $masking;
}

/**
 * Site-level INSPECT smell for masked derived-family overrides (#386).
 *
 * Walks every token family over its CURRENT effective base value and reports the
 * same masking condition as `pp_masked_derived_overrides()`, so an operator sees
 * the base/derived incoherence at INSPECT — before a base change, not only after
 * one at APPLY. Quiet on a coherently themed site (auto-derived families match
 * their base); fires on genuine incoherence (e.g. a blue accent base with an
 * orange accent-strong override left from a previous palette).
 *
 * @return array<array{type: string, base_token: string, token: string, current: string, expected: string, message: string}>
 */
function pp_detect_masked_derived_smells(): array {
    $tokens = pp_design_tokens();
    $smells = [];

    foreach (array_keys(pp_token_families()) as $base_token) {
        if (!isset($tokens[$base_token])) {
            continue;
        }
        // Guard against a corrupt token record whose value isn't a string before
        // handing it to pp_masked_derived_overrides()'s string parameter — INSPECT
        // must not fatal on bad stored option data.
        $base_value = $tokens[$base_token]['value'] ?? null;
        if (!is_string($base_value)) {
            continue;
        }
        foreach (pp_masked_derived_overrides($base_token, $base_value) as $m) {
            $smells[] = [
                'type'       => 'masked_derived_override',
                'base_token' => $base_token,
                'token'      => $m['token'],
                'current'    => $m['current'],
                'expected'   => $m['expected'],
                'message'    => $m['message'],
            ];
        }
    }

    return $smells;
}

/**
 * Returns custom font URLs from the database.
 *
 * @return array  Array of URL strings.
 */
function pp_get_font_urls(): array {
    $urls = get_option('pp_font_urls', []);
    return is_array($urls) ? $urls : [];
}

/**
 * Sets the custom font URLs in the database.
 *
 * @param array $urls  Array of URL strings.
 * @return bool  True on success.
 */
function pp_set_font_urls(array $urls): bool {
    return update_option('pp_font_urls', array_values($urls), true);
}

/**
 * Single source of truth for the site-option whitelist (a security boundary —
 * the only WP options the AI/CLI surface may read or write). Maps each allowed
 * key to its value type, used for server-side validation on write.
 *
 * Types: 'string' (free text) | 'attachment_id' (a positive int that resolves
 * to a Media Library attachment — never a raw URL) | 'bool' (a canonical
 * on/off flag: accepts 1/0/true/false, stored as '1' or '0') | 'color' (a CSS
 * color accepted by the shared _pp_validate_color() engine — hex/rgb/hsl,
 * transparent/currentColor, or a single known color-typed design-token
 * reference) | 'gradient' (the shared color-OR-gradient union: everything
 * 'color' accepts, PLUS a bounded linear-gradient()/radial-gradient() with 2+
 * color stops — used for the chrome BACKGROUND options, issue 333).
 * '0' (not '') is the canonical OFF form for bool so a stored
 * value always re-validates — the snapshot/rollback path re-applies it
 * through the validating writer.
 *
 * @return array<string,string>  key => type
 */
function pp_allowed_site_options(): array {
    return [
        'blogname'             => 'string',
        'blogdescription'      => 'string',
        'pp_logo_id'           => 'attachment_id',
        'pp_logo_alt'          => 'string',
        // Browser-tab favicon + app/OS icon (issue 414). This is WordPress
        // core's own `site_icon` option (the value the Customizer's Site Icon
        // control writes), not a pp_ option, so once it is set core's
        // wp_site_icon() hook emits the `<link rel="icon">` / apple-touch-icon
        // tags into wp_head() automatically — the theme adds no rendering code.
        // It is the SAME shape of change as pp_logo_id (a whitelisted option
        // pointing at a Media Library image attachment) and is validated by the
        // SAME pp_is_image_attachment rule, so the favicon is settable through
        // the same typed, validated path as the logo. No square/size constraint
        // is imposed here (a hard size reject would be a surface-specific rule the
        // logo keys don't have). Note: the Customizer's square-crop + subsize step
        // does NOT run on a direct option write, so core's wp_site_icon() renders
        // the chosen attachment as-is — a square source (ideally >=512px) is what
        // looks right across the icon sizes. Like every attachment_id key, empty/0
        // is rejected rather than treated as an unset.
        'site_icon'            => 'attachment_id',
        // Site-option surface for the footer logo. The footer is template-owned
        // chrome (issue 223), so it cannot be composed to pass show_logo; this
        // option is the only supported way to turn the footer logo on (issue 234).
        'pp_footer_show_logo'  => 'bool',
        // Dark-marketing-footer chrome (issue 300). The footer is template-owned
        // (issue 223) and not a composition component, so it has no style_slots;
        // these site options are the supported surface. Colors emit inline
        // --footer-* custom properties; strings render brand/contact/copyright.
        //
        // pp_footer_bg is 'gradient', not 'color' (issue 333). The 'gradient' type
        // is a color-OR-gradient UNION (see _pp_validate_token_value()), so it is a
        // strict superset of 'color': every value that validated before still does.
        // Issue 300 typed it 'color' on the belief that the color engine already
        // accepted gradients; it does not (_pp_validate_color() has no gradient
        // branch), so a gradient footer was silently inexpressible. Widened here
        // alongside the header rather than left as an asymmetry the AI would have
        // to memorize ("header takes a gradient, footer does not").
        'pp_footer_bg'         => 'gradient',
        'pp_footer_text'       => 'color',
        'pp_footer_link_color' => 'color',
        'pp_footer_blurb'      => 'string',
        'pp_footer_contact'    => 'string',
        'pp_footer_copyright'  => 'string',
        // Footer STRUCTURE (issue 335). Issue 300 gave the footer a dark tone
        // but no organisation; these add the generic layout affordances a
        // marketing footer needs — labelled columns and a delimited bottom bar
        // — as more of the SAME site-option surface (still no footer builder,
        // still template-owned chrome, issue 223). All optional; empty = today's
        // rendering, so an unset footer stays byte-identical.
        //   - menu/contact labels: optional column headings above the footer
        //     nav menu and the contact block (empty = unlabelled, as before).
        //   - note: optional secondary line. When NON-EMPTY it moves the
        //     copyright into a delimited bottom bar and renders alongside it;
        //     empty leaves the copyright inline exactly as issue 300 did.
        'pp_footer_menu_label'    => 'string',
        'pp_footer_contact_label' => 'string',
        // Optional heading above the SECOND footer menu column (issue 469).
        // Generic name — the "Legal" column is one use of the second location
        // (footer_secondary), not the capability itself. Empty = a headless
        // second column (the same headless-when-unset rule as pp_footer_menu_label).
        'pp_footer_secondary_label' => 'string',
        'pp_footer_note'          => 'string',
        // Footer logo OVERRIDE (issue 335). pp_logo_id feeds both the (light)
        // header and the (dark) footer, so a dark-on-transparent brand mark is
        // correct in the header and invisible on a dark footer. This optional
        // override lets the footer use a light logo variant while pp_logo_id
        // stays the header logo. Attachment ID (never a URL), validated by the
        // SAME pp_is_image_attachment rule as pp_logo_id. Unset falls back to
        // pp_logo_id via pp_resolve_logo's existing resolution chain.
        'pp_footer_logo_id'       => 'attachment_id',
        // Footer SOCIAL-ICON row (issue 382). A list-valued option: an ordered set
        // of {network, url} entries from a CLOSED set of known networks (see
        // pp_footer_social_networks()), rendered by the footer template as accessible
        // inline-SVG icon links in the reserved .site-footer__social slot (#427). The
        // value is a JSON string because a site option stores a single scalar; the
        // 'social' type is the only structured (non-scalar-semantics) option, so its
        // validator (below) is the one place that decodes + shape-checks it. Unset/''
        // = no row (byte-identical footer). External profile URLs, so URL validation
        // is http(s)-only (NOT the same-site redirect rule). No arbitrary icon URLs
        // or icon fonts: the network set is fixed and its glyphs ship inline.
        'pp_footer_social'        => 'social',
        // Header chrome (issue 333). The header/nav is template-owned (issue 223)
        // exactly like the footer, so it declares no style_slots and these site
        // options are its ONLY styling surface. Before this, the header was the one
        // above-the-fold element with no authorable surface at all: .site-header was
        // hard-bound to --color-bg. Colors emit inline --header-* custom properties.
        // pp_header_bg is 'gradient' (color OR gradient) so a gradient marketing
        // header is expressible; text/link stay 'color'.
        'pp_header_bg'         => 'gradient',
        'pp_header_text'       => 'color',
        'pp_header_link_color' => 'color',
        // Open Graph / Twitter social-share defaults (issue 468). The theme
        // emits NO og:*/twitter:* tags without these — sharing a page produced
        // no rich card. Site-level defaults for the whole install; per-page
        // overrides (og_title/twitter_title) live on _pp_seo_meta via
        // update_seo_meta. Rendered by pp_social_meta_tags() in wp_head.
        //   - pp_og_image: the social-share image. An image attachment ID (NOT
        //     a URL), validated by the SAME pp_is_image_attachment rule as
        //     pp_logo_id / site_icon — the established typed image surface. Feeds
        //     og:image (+ width/height from attachment metadata, alt from the
        //     attachment alt) and twitter:image. Unset = those tags are omitted.
        //   - pp_og_site_name: overrides the og:site_name default
        //     (get_bloginfo('name')). Free text, same as the other string
        //     options (no length cap — consistent with pp_footer_blurb et al.).
        //   - pp_og_default_description: the site-wide fallback social
        //     description used when a page has no meta_description. Capped at the
        //     SAME 320 chars as meta_description (see the key-scoped check in
        //     pp_validate_site_option_value) so the two description surfaces
        //     can't diverge on length.
        //   - pp_twitter_card: the Twitter card type. A CLOSED enum
        //     (summary | summary_large_image) — its own 'twitter_card' type so
        //     the accepted set lives in exactly one place (PP_TWITTER_CARD_VALUES),
        //     the same "dedicated validator for a structured/closed option" shape
        //     as pp_footer_social. Unset renders the summary_large_image default.
        'pp_og_image'               => 'attachment_id',
        'pp_og_site_name'           => 'string',
        'pp_og_default_description' => 'string',
        'pp_twitter_card'           => 'twitter_card',
    ];
}

/**
 * Upper bound on the length of pp_og_default_description, matching
 * meta_description's cap in _pp_validate_seo_meta() (issue 468). The site-wide
 * social description falls back INTO the same og:description slot a page's
 * meta_description fills, so a single shared cap keeps the two from diverging.
 */
const PP_OG_DESCRIPTION_MAX = 320;

/**
 * The CLOSED set of accepted pp_twitter_card values (issue 468), lower-cased.
 * `summary_large_image` is the default when the option is unset (see
 * pp_social_meta_tags()); `summary` is the compact card. A strict allowlist so a
 * typo is rejected rather than silently emitted into <head>.
 */
const PP_TWITTER_CARD_VALUES = ['summary', 'summary_large_image'];

/**
 * Canonical string forms accepted for a 'bool' site option, lower-cased.
 * A strict allowlist (not "any non-empty string is true") so a typo like
 * "flase" is rejected instead of silently coercing to on.
 */
const PP_BOOL_OPTION_TRUE  = ['1', 'true'];
const PP_BOOL_OPTION_FALSE = ['0', 'false'];

/**
 * Upper bound on the number of entries a pp_footer_social value may carry.
 * A footer social row is a short, curated set (a marketing footer shows a
 * handful of profiles), so a generous cap keeps the stored option and the
 * rendered row bounded without constraining any real use. Duplicates are
 * allowed (they simply render in order), so the cap is not tied to the size
 * of the network set.
 */
const PP_FOOTER_SOCIAL_MAX = 12;

/** Max accepted length for a single social profile URL (defensive bound). */
const PP_FOOTER_SOCIAL_URL_MAXLEN = 2048;

/**
 * The CLOSED set of social networks the footer can surface (issue 382), and the
 * single source of truth shared by BOTH the pp_footer_social validator (which
 * network keys are accepted) and the footer template (which glyph + accessible
 * label each renders). Keeping validation and rendering keyed off ONE map means
 * an accepted network can never be un-renderable, and adding a network later is
 * one additive entry here — no second list to keep in sync.
 *
 * Each glyph is a minimal, hand-authored single `<path>` on a 24x24 viewBox
 * (fill-rule:evenodd handles the marks that need a cut-out). No icon font and no
 * third-party icon library is vendored, and nothing is fetched at render time —
 * the SVG ships inline with the theme. `label` is the accessible name used for
 * the link's aria-label; `path` is the glyph's `d` attribute.
 *
 * @return array<string, array{label:string, path:string}>
 */
function pp_footer_social_networks(): array {
    return [
        'x' => [
            'label' => 'X',
            'path'  => 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.657l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z',
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'path'  => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'path'  => 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z',
        ],
        'instagram' => [
            'label' => 'Instagram',
            'path'  => 'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'path'  => 'M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
        ],
        'github' => [
            'label' => 'GitHub',
            'path'  => 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12z',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'path'  => 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z',
        ],
        'mastodon' => [
            'label' => 'Mastodon',
            'path'  => 'M23.268 5.313c-.35-2.578-2.617-4.61-5.304-5.004C17.51.242 15.792 0 11.813 0h-.03c-3.98 0-4.835.242-5.288.309C3.882.692 1.496 2.518.917 5.127.64 6.412.61 7.837.661 9.143c.074 1.874.088 3.745.26 5.611.118 1.24.325 2.47.62 3.68.55 2.237 2.777 4.098 4.96 4.857 2.336.792 4.849.923 7.256.38.265-.061.527-.132.786-.213.585-.184 1.27-.39 1.774-.753a.057.057 0 00.023-.043v-1.809a.052.052 0 00-.02-.041.053.053 0 00-.046-.01 20.282 20.282 0 01-4.709.545c-2.73 0-3.463-1.284-3.674-1.818a5.593 5.593 0 01-.319-1.433.053.053 0 01.066-.054c1.517.363 3.072.546 4.632.546.376 0 .75 0 1.125-.01 1.57-.044 3.224-.124 4.768-.422.038-.008.077-.015.11-.024 2.435-.464 4.753-1.92 4.989-5.604.008-.145.03-1.52.03-1.67.002-.512.167-3.63-.024-5.545zm-3.748 9.195h-2.561V8.29c0-1.309-.55-1.976-1.67-1.976-1.23 0-1.846.79-1.846 2.35v3.403h-2.546V8.663c0-1.56-.617-2.35-1.848-2.35-1.112 0-1.668.668-1.67 1.977v6.218H4.822V8.102c0-1.31.337-2.35 1.011-3.12.696-.77 1.608-1.164 2.74-1.164 1.311 0 2.302.5 2.962 1.498l.638 1.06.638-1.06c.66-.999 1.65-1.498 2.96-1.498 1.13 0 2.043.395 2.74 1.164.675.77 1.012 1.81 1.012 3.12z',
        ],
    ];
}

/**
 * Validates a pp_footer_social value (issue 382). The value is a JSON string:
 * an ordered, non-empty list of {network, url} objects. This is the single place
 * the structured shape is decoded and checked, so an accepted value is always
 * renderable by the footer template.
 *
 * Rules (all must hold, else a descriptive WP_Error):
 *   - decodes to a JSON array that is a sequential LIST (a JSON object, which
 *     json_decode turns into an associative PHP array, is rejected);
 *   - non-empty and at most PP_FOOTER_SOCIAL_MAX entries;
 *   - each entry is a JSON object (associative array) with STRING `network` and
 *     `url` members;
 *   - `network` is a key in the closed pp_footer_social_networks() set;
 *   - `url` (trimmed) is <= PP_FOOTER_SOCIAL_URL_MAXLEN, is a valid absolute URL
 *     (filter_var), and its scheme is http or https. These are EXTERNAL profile
 *     URLs, so the same-site redirect validator is deliberately NOT reused; the
 *     scheme allowlist blocks javascript:/data:/protocol-relative values.
 *
 * The empty string is handled by the caller (it means "clear"), not here.
 *
 * @param string $value  Raw JSON string.
 * @return true|WP_Error
 */
function _pp_validate_footer_social(string $value) {
    $err = static function (string $msg) {
        return new WP_Error('invalid_option_value', 'Option "pp_footer_social" ' . $msg);
    };

    $decoded = json_decode($value, true);
    if (!is_array($decoded) || !pp_is_list($decoded)) {
        return $err('must be a JSON array of {network, url} entries.');
    }
    if ($decoded === []) {
        return $err('must contain at least one {network, url} entry (use "" to clear).');
    }
    if (count($decoded) > PP_FOOTER_SOCIAL_MAX) {
        return $err(sprintf('may contain at most %d entries.', PP_FOOTER_SOCIAL_MAX));
    }

    $networks = pp_footer_social_networks();
    foreach ($decoded as $entry) {
        // A JSON object decodes to a non-list associative array. A list-shaped
        // child (e.g. ["x","https://..."]) is not the {network,url} object shape.
        if (!is_array($entry) || pp_is_list($entry)) {
            return $err('entries must each be a {network, url} object.');
        }
        if (!isset($entry['network'], $entry['url'])
            || !is_string($entry['network']) || !is_string($entry['url'])) {
            return $err('each entry needs a string "network" and a string "url".');
        }
        if (!isset($networks[$entry['network']])) {
            return $err(sprintf(
                'has unknown network "%s". Allowed: %s.',
                $entry['network'], implode(', ', array_keys($networks))
            ));
        }
        $url = trim($entry['url']);
        // Reject control characters and raw HTML/attribute-breaking characters up
        // front (a legitimate URL percent-encodes them). filter_var is lenient
        // about these, so this guard keeps the STORED value clean; esc_url is still
        // the production escaping boundary at render (defense in depth).
        if ($url === '' || strlen($url) > PP_FOOTER_SOCIAL_URL_MAXLEN
            || preg_match('/[\x00-\x20<>"\'`]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $err(sprintf('has an invalid URL for "%s".', $entry['network']));
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return $err(sprintf('URL for "%s" must be an http(s) URL.', $entry['network']));
        }
    }
    return true;
}

/**
 * Canonicalizes a validated pp_footer_social value for storage: decode, keep
 * ONLY {network, url} (url trimmed) per entry in original order, re-encode.
 * Drops any extra keys and normalizes whitespace so a stored value survives a
 * round-trip through the validating writer (the snapshot/rollback path). The
 * caller has already validated $value, so decoding cannot fail here.
 *
 * @param string $value  A value already accepted by _pp_validate_footer_social.
 * @return string        Canonical JSON, or '' if (defensively) re-encoding fails.
 */
function pp_normalize_footer_social(string $value): string {
    $decoded = json_decode($value, true);
    $canonical = [];
    foreach ((array) $decoded as $entry) {
        $canonical[] = [
            'network' => (string) $entry['network'],
            'url'     => trim((string) $entry['url']),
        ];
    }
    $json = wp_json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '';
}

/**
 * Single source of truth for "is $id a Media Library image attachment?".
 *
 * Every trust boundary that accepts an attachment ID meant to render as an
 * image (the `pp_logo_id` site option, component `logo_id` props, the logo
 * resolver) funnels through this predicate so the definition of "valid image
 * attachment" can never drift between them. A non-image, trashed, non-existent,
 * or non-attachment ID all return false. SVGs are excluded because WP core's
 * wp_attachment_is_image() rejects them (see AiContextTest).
 *
 * @param int $id  Attachment post ID.
 * @return bool    True only for a live attachment whose type is a displayable image.
 */
function pp_is_image_attachment(int $id): bool {
    return $id > 0
        && get_post_type($id) === 'attachment'
        && wp_attachment_is_image($id);
}

/**
 * Validates a site-option value against its declared type.
 *
 * @param string $key    Whitelisted option key (caller has already checked membership).
 * @param string $value  Proposed value.
 * @return true|WP_Error
 */
function pp_validate_site_option_value(string $key, string $value) {
    $type = pp_allowed_site_options()[$key] ?? null;
    if ($type === 'attachment_id') {
        if (!pp_is_image_attachment((int) $value)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" requires a Media Library image attachment ID, got "%s".',
                $key, $value
            ));
        }
    }
    if ($type === 'bool') {
        $norm = strtolower(trim($value));
        if (!in_array($norm, PP_BOOL_OPTION_TRUE, true) && !in_array($norm, PP_BOOL_OPTION_FALSE, true)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" requires a boolean (1/0/true/false), got "%s".',
                $key, $value
            ));
        }
    }
    if ($type === 'color') {
        // Delegate to the shared color engine (issue 230) — the SAME validator
        // every style-slot color goes through. No second, surface-specific color
        // rule (a repo invariant): a footer color and a component color slot can
        // never drift apart in what they accept.
        if (!_pp_validate_color($value)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" requires a CSS color (hex, rgb()/hsl(), transparent, '
                . 'currentColor, or a known color token reference), got "%s".',
                $key, $value
            ));
        }
    }
    if ($type === 'gradient') {
        // Delegate to the shared slot-type engine (issue 333) — the SAME validator
        // every `gradient`-typed style slot goes through, for the same reason the
        // 'color' branch above delegates: no second, surface-specific rule. The
        // 'gradient' type is a color-OR-gradient union, so a background option
        // accepts every plain color a 'color' option does, PLUS a bounded
        // linear-gradient()/radial-gradient(). Routed through
        // _pp_validate_token_value() (not a bare _pp_validate_gradient() call) so
        // the union stays defined in exactly one place.
        if (_pp_validate_token_value($value, 'gradient') !== true) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" requires a CSS color (hex, rgb()/hsl(), transparent, '
                . 'currentColor, or a known color token reference) or a bounded '
                . 'linear-gradient()/radial-gradient() with 2+ color stops, got "%s".',
                $key, $value
            ));
        }
    }
    if ($type === 'social') {
        // Footer social-icon row (issue 382). '' is a valid CLEAR (no row); any
        // other value is a JSON list of {network, url} entries decoded and
        // shape-checked by the dedicated validator (the only structured option
        // type — see _pp_validate_footer_social for the full contract).
        if (trim($value) === '') {
            return true;
        }
        return _pp_validate_footer_social($value);
    }
    if ($type === 'twitter_card') {
        // og/twitter card type (issue 468). '' is a valid CLEAR (renderer uses
        // the summary_large_image default); any other value must be in the
        // CLOSED PP_TWITTER_CARD_VALUES set so a typo can't reach <head>.
        if (trim($value) === '') {
            return true;
        }
        if (!in_array(strtolower(trim($value)), PP_TWITTER_CARD_VALUES, true)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" must be one of: %s (or empty to clear), got "%s".',
                $key, implode(', ', PP_TWITTER_CARD_VALUES), $value
            ));
        }
    }
    // Key-scoped length cap for the site-wide social description (issue 468).
    // Not a type rule: pp_og_default_description is a plain 'string' option (so
    // it round-trips like the other text options), but it feeds the SAME
    // og:description slot as a page's meta_description, so it shares that
    // surface's 320-char cap rather than inventing a second limit.
    if ($key === 'pp_og_default_description' && strlen($value) > PP_OG_DESCRIPTION_MAX) {
        return new WP_Error('invalid_option_value', sprintf(
            'Option "%s" must be %d characters or fewer.', $key, PP_OG_DESCRIPTION_MAX
        ));
    }
    return true;
}

/**
 * Normalizes a validated 'bool' site-option value to its canonical stored form:
 * '1' (on) or '0' (off). Caller must have validated the value first. Both forms
 * are themselves valid bool inputs, so a stored value survives a round-trip
 * through pp_update_site_option (as the snapshot/rollback path requires).
 *
 * @param string $value  A value already accepted by pp_validate_site_option_value.
 * @return string        '1' or '0'.
 */
function pp_normalize_bool_option(string $value): string {
    return in_array(strtolower(trim($value)), PP_BOOL_OPTION_TRUE, true) ? '1' : '0';
}

/**
 * Returns a whitelisted WordPress option value.
 * Whitelist is the single source pp_allowed_site_options().
 *
 * @param string $key  Option name (must be whitelisted).
 * @return string|WP_Error  Option value, or WP_Error if key not whitelisted.
 */
function pp_site_option(string $key) {
    if (!isset(pp_allowed_site_options()[$key])) {
        return new WP_Error('invalid_option', sprintf('Option "%s" is not whitelisted.', $key));
    }
    return (string) get_option($key, '');
}

/**
 * Resolves the site logo for a nav/footer component into a render-ready shape.
 * Attachment-ID only — never accepts a raw URL as input. The returned `url` is
 * an OUTPUT, resolved from the attachment ID for the <img src>.
 *
 * Resolution order: explicit `logo_id` prop → `pp_logo_id` site option (the
 * AI/CLI safe surface) → WP `custom_logo` theme-mod → text wordmark.
 *
 * Alt resolution: explicit `logo_alt` → the attachment's own alt metadata →
 * the wordmark text. (In nav/footer the logo replaces the text wordmark, so a
 * meaningful alt is correct; an empty decorative alt would only apply if a
 * layout rendered both the image AND the visible site title together.)
 *
 * @param array $props  Component props (logo_id, logo_alt, logo_text).
 * @return array{type:string,url:string,alt:string,text:string}
 *         type is 'image' when an attachment resolved to a URL, else 'text'.
 */
function pp_resolve_logo(array $props): array {
    $text = $props['logo_text'] ?? pp_site_title();

    $id = 0;
    if (!empty($props['logo_id'])) {
        $id = (int) $props['logo_id'];
    } elseif (($opt = get_option('pp_logo_id', '')) !== '') {
        $id = (int) $opt;
    } elseif (($mod = get_theme_mod('custom_logo')) !== false && $mod) {
        $id = (int) $mod;
    }

    // Explicit image guard: only resolve an <img> for a real image attachment.
    // Previously this relied on wp_get_attachment_image_url() incidentally
    // returning false for non-images (#155). Making the check explicit means a
    // non-image ID (from any source above) deterministically falls through to
    // the text wordmark instead of depending on WP core internals.
    if (pp_is_image_attachment($id)) {
        $url = wp_get_attachment_image_url($id, 'full');
        if ($url) {
            $alt = $props['logo_alt'] ?? '';
            if ($alt === '') {
                $meta_alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
                $alt = $meta_alt !== '' ? $meta_alt : $text;
            }
            return ['type' => 'image', 'url' => $url, 'alt' => $alt, 'text' => $text];
        }
    }

    return ['type' => 'text', 'url' => '', 'alt' => $props['logo_alt'] ?? $text, 'text' => $text];
}

// ── Site-state write functions (persistence wrappers) ────────────────────────

/**
 * Generates a component id for entries written without an authored `id` prop.
 *
 * The `pp-<hex8>` shape is RESERVED for generated ids: pp_is_generated_component_id()
 * (lib/guardrails.php) pattern-matches this exact format to tell generated ids from
 * authored ones, and tests pin the two together — change one, change both. A new
 * random id is produced on every full-composition write for id-less entries (#232),
 * so these ids are NOT durable across a declarative re-apply.
 *
 * @return string  Generated id in the reserved `pp-<hex8>` format.
 */
function pp_generate_component_id(): string {
    return 'pp-' . bin2hex(random_bytes(4)); // 4 bytes → exactly 8 hex chars
}

/**
 * Writes a composition array to post meta, bumping the freshness marker (#113), with
 * optional write-time compare-and-swap on the version (#13).
 *
 * Thin persistence wrapper — handles JSON serialization internally.
 * Does NOT validate (the action layer owns validation).
 *
 * The composition write and the marker bump (_pp_composition_version +
 * _pp_composition_hash) happen under a per-post advisory lock so concurrent writers to
 * the same post can't interleave and lose a version bump. Lock-acquire failure returns a
 * WP_Error and writes NOTHING — never a silent non-atomic write (the #200 lesson). The
 * content hash is computed on the canonical PRE-id-injection form (see
 * pp_composition_content_hash) so the id injection below can't false-conflict the marker.
 *
 * Optimistic locking (#13): when $expected_version is non-null, the fresh in-lock version
 * read must equal it or the write is rejected with a `composition_conflict` WP_Error and
 * NOTHING is written — neither the composition nor either marker moves. The check happens
 * AFTER the advisory lock is held and against the same fresh-from-DB read that computes the
 * next version, so it is atomic: an interleaved external write that lands between a caller's
 * read and this write bumps the version and is caught here, not just at a pre-check (closing
 * the TOCTOU gap #113's preflight gate left open). The comparison is on the version integer,
 * which the stable-id injection below never changes, so an id round-trip can't false-conflict.
 * A null $expected_version skips the CAS entirely (documented back-compat: legacy callers,
 * new-page creation, and the homepage seed all write unconditionally). An absent marker reads
 * as version 0, so a legacy/never-written page accepts $expected_version === 0 and initializes
 * to version 1.
 *
 * @param int      $post_id           WordPress post ID.
 * @param array    $composition       Array of component objects.
 * @param int|null $expected_version  The version the caller based its edit on, or null to
 *                                    skip the compare-and-swap.
 * @return true|WP_Error  true on write; WP_Error('composition_conflict') on a version
 *                        mismatch; WP_Error('composition_lock_failed') on lock-acquire failure.
 */
function pp_update_composition(int $post_id, array $composition, ?int $expected_version = null) {
    // Hash the canonical PRE-stable-id form, before the id-injection loop below mutates
    // the array. (pp_composition_content_hash also strips ids defensively, so the two are
    // belt-and-suspenders — the hash is stable across the id round-trip either way.)
    $hash = pp_composition_content_hash($composition);

    // Auto-assign generated IDs to entries that don't have one. The id persists in
    // props, so it survives in-place actions (update/insert/remove/reorder) that
    // round-trip the stored array — but a full-array rewrite (update_composition /
    // create_page from a source JSON without ids) regenerates it (#232). Only an
    // explicit authored `id` is durable across declarative re-apply; validators
    // detect the generated shape via pp_is_generated_component_id().
    //
    // COUPLING (issue 147): because this injects props['id'] into EVERY component,
    // every composable component's schema.json MUST declare an `id` prop — otherwise
    // the injected id would be rejected as an unknown prop key on the next validated
    // write. The invariant is guarded by
    // SchemaValidationTest::testEveryComposableComponentDeclaresIdSoInjectedIdNeverFalseRejects.
    foreach ($composition as &$item) {
        $props = $item['props'] ?? [];
        if (empty($props['id'])) {
            $item['props']['id'] = pp_generate_component_id();
        }
    }
    unset($item);

    $json = wp_json_encode($composition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return _pp_with_composition_lock($post_id, function ($wpdb) use ($post_id, $json, $hash, $expected_version) {
        // Read the version fresh from the DB inside the lock (bypassing the meta cache the
        // pre-lock freshness check may have warmed). Absent → 0, so the first write is v1.
        $current_version = _pp_read_composition_version_locked($wpdb, $post_id);

        // Write-time compare-and-swap (#13). Only when the caller supplied a baseline: reject
        // if the target moved since. This runs under the same lock and against the same fresh
        // read as the bump below, so the check-and-set is atomic — no window for an external
        // write to slip between them. Nothing has been written yet, so a mismatch leaves the
        // composition and both markers exactly as they were.
        if ($expected_version !== null && $current_version !== $expected_version) {
            return new WP_Error(
                'composition_conflict',
                'The composition for post ' . $post_id . ' changed since you last read it '
                . '(expected version ' . $expected_version . ', current version ' . $current_version . '). '
                . 'Another writer (a CLI action, the dashboard editor, or the AI chat) modified it. '
                . 'Re-read the current composition and re-apply your change. [composition_conflict]'
            );
        }

        $next_version = $current_version + 1;

        // History ring (#133): push the PRIOR composition onto the bounded per-post
        // history meta BEFORE overwriting, so the state this write replaces stays
        // restorable. Runs inside this same advisory lock as the marker bump, so the
        // ring never interleaves with a concurrent write to the same post. Only when a
        // prior composition exists — a brand-new page's first write has nothing to
        // preserve (and pushing an empty baseline would waste a ring slot). Read the
        // prior JSON straight from the DB (bypassing a possibly-stale meta cache) so the
        // captured bytes are exactly what a later restore replays.
        $prior_json = _pp_read_composition_json_locked($wpdb, $post_id);
        if ($prior_json !== null) {
            $prior_items = json_decode($prior_json, true);
            if (is_array($prior_items)) {
                $history   = pp_get_composition_history($post_id);
                $history[] = [
                    'timestamp'   => time(),
                    'version'     => $current_version,
                    'hash'        => (string) get_post_meta($post_id, '_pp_composition_hash', true),
                    'composition' => $prior_items,
                ];
                $max = pp_composition_history_max();
                if (count($history) > $max) {
                    $history = array_slice($history, -$max);
                }
                update_post_meta(
                    $post_id,
                    '_pp_composition_history',
                    wp_slash(wp_json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                );
            }
        }

        // Write the composition first, then the hash, then the version LAST. A concurrent
        // marker reader (the freshness check, which is NOT under this lock) therefore
        // either sees the fully-updated marker or the pre-write version — a torn read
        // (new version, stale hash) can only make the freshness check MISMATCH, which
        // fails closed (rejects), never a silent false pass.
        update_post_meta($post_id, '_pp_composition', wp_slash($json));
        update_post_meta($post_id, '_pp_composition_hash', $hash);
        update_post_meta($post_id, '_pp_composition_version', $next_version);
        return true;
    }, new WP_Error(
        'composition_lock_failed',
        'Could not acquire the composition write lock for post ' . $post_id
        . '; the write was skipped to avoid a lost update. Retry once contention clears.'
    ));
}

/**
 * Updates a page title.
 *
 * @param int    $post_id  WordPress post ID.
 * @param string $title    New page title.
 * @return true|WP_Error
 */
function pp_update_page_title(int $post_id, string $title) {
    $result = wp_update_post(['ID' => $post_id, 'post_title' => $title], true);
    if (is_wp_error($result)) {
        return $result;
    }
    return true;
}

/**
 * Updates a page's slug (post_name) / permalink (#134).
 *
 * WordPress's own wp_update_post() de-duplicates post_name internally
 * (suffixing -2, -3, ... on collision, via wp_unique_post_slug()) — this
 * reads back the actual resulting slug afterward rather than assuming the
 * requested one stuck, so a caller always learns the real URL.
 *
 * @param int    $post_id  WordPress post ID.
 * @param string $slug     Desired slug. Sanitized via sanitize_title().
 * @return string|WP_Error  The resulting (possibly de-duplicated) slug, or WP_Error.
 */
function pp_update_page_slug(int $post_id, string $slug) {
    $sanitized = sanitize_title($slug);
    if ($sanitized === '') {
        return new WP_Error('invalid_slug', 'Slug must not be empty after sanitization.');
    }

    $result = wp_update_post(['ID' => $post_id, 'post_name' => $sanitized], true);
    if (is_wp_error($result)) {
        return $result;
    }

    $post = get_post($post_id);
    return ($post->post_name ?? '') !== '' ? $post->post_name : $sanitized;
}

// ── Page-specific SEO metadata (#41) ─────────────────────────────────────────
// Storage: a single post meta key (_pp_seo_meta, JSON-encoded), the same
// "one structured meta key" pattern as _pp_composition — not scattered flat
// meta keys, and not folded into the composition array itself (SEO metadata
// is page-level, not a layout/content concern).

/**
 * Returns page-specific SEO metadata for a post.
 *
 * @param int $post_id  WordPress post ID.
 * @return array{meta_description: string, seo_title: string, canonical_url: string, og_title: string, twitter_title: string}
 */
function pp_get_seo_meta(int $post_id): array {
    // og_title / twitter_title (issue 468) are per-page social-title overrides.
    // They live here (not in a separate meta key) so they round-trip through the
    // SAME #471-fixed store as seo_title/meta_description and are captured/
    // restored by the batch snapshot for free (it snapshots pp_get_seo_meta()).
    $defaults = [
        'meta_description' => '',
        'seo_title'        => '',
        'canonical_url'    => '',
        'og_title'         => '',
        'twitter_title'    => '',
    ];
    $raw = get_post_meta($post_id, '_pp_seo_meta', true);
    if (!is_string($raw) || $raw === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return array_merge($defaults, array_intersect_key($decoded, $defaults));
}

/**
 * Validates a (possibly partial) SEO meta patch. Shared by the update_seo_meta
 * action's validate step and pp_update_seo_meta() itself (defense in depth,
 * same pattern as other write paths in this file).
 *
 * @return true|WP_Error
 */
function _pp_validate_seo_meta(array $meta) {
    $allowed_keys = ['meta_description', 'seo_title', 'canonical_url', 'og_title', 'twitter_title'];
    $unknown = array_diff(array_keys($meta), $allowed_keys);
    if (!empty($unknown)) {
        return new WP_Error('invalid_key', 'Unknown SEO meta key(s): ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', $allowed_keys) . '.');
    }
    if (isset($meta['canonical_url']) && $meta['canonical_url'] !== '' && !filter_var($meta['canonical_url'], FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_canonical_url', 'canonical_url must be a valid URL, or an empty string to clear it.');
    }
    if (isset($meta['meta_description']) && strlen($meta['meta_description']) > 320) {
        return new WP_Error('meta_description_too_long', 'meta_description must be 320 characters or fewer.');
    }
    // og_title / twitter_title share seo_title's 200-char cap (issue 468) — they
    // are validated exactly like seo_title, the title surface they fall back to.
    foreach (['seo_title', 'og_title', 'twitter_title'] as $title_key) {
        if (isset($meta[$title_key]) && strlen($meta[$title_key]) > 200) {
            return new WP_Error($title_key . '_too_long', $title_key . ' must be 200 characters or fewer.');
        }
    }
    return true;
}

/**
 * Writes page-specific SEO metadata for a post. Shallow-merges into existing
 * values — unspecified keys are left unchanged (same patch semantics as
 * update_component's props). Pass an empty string to clear a field.
 *
 * @param int   $post_id  WordPress post ID.
 * @param array $meta     Subset of {meta_description, seo_title, canonical_url}.
 * @return true|WP_Error
 */
function pp_update_seo_meta(int $post_id, array $meta) {
    if (!get_post($post_id)) {
        return new WP_Error('invalid_post', 'Post not found.');
    }

    $valid = _pp_validate_seo_meta($meta);
    if (is_wp_error($valid)) {
        return $valid;
    }

    $updated = array_merge(pp_get_seo_meta($post_id), $meta);
    // JSON_UNESCAPED_UNICODE stores non-ASCII (accents, em-dash) as raw UTF-8
    // instead of \uXXXX escapes — without it, update_post_meta()'s unslash pass
    // stripped the backslash and turned "á" into a literal "u00e1" (#471).
    // wp_slash() then protects the escapes that are still present in any JSON
    // string (\" and \\) from that same unslash pass. Same store idiom as the
    // composition path (see the _pp_composition write in pp_apply_composition()).
    update_post_meta(
        $post_id,
        '_pp_seo_meta',
        wp_slash(wp_json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
    );
    return true;
}

/**
 * Outputs the page-specific meta description tag, if set for the current
 * page. Hooked to wp_head in functions.php.
 */
function pp_seo_meta_description_tag(): void {
    if (!is_singular()) {
        return;
    }
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return;
    }
    $meta_description = pp_get_seo_meta($post_id)['meta_description'];
    if ($meta_description === '') {
        return;
    }
    echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . "\n";
}

/**
 * Filters the assembled document <title> to the page-specific seo_title
 * override, if set. Hooked to the pre_get_document_title filter in
 * functions.php — returning a non-empty value here short-circuits
 * wp_get_document_title()'s own title-parts assembly entirely.
 */
function pp_seo_document_title_override(string $title): string {
    if (!is_singular()) {
        return $title;
    }
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return $title;
    }
    $seo_title = pp_get_seo_meta($post_id)['seo_title'];
    return $seo_title !== '' ? $seo_title : $title;
}

/**
 * Filters the canonical URL to the page-specific canonical_url override, if
 * set. Hooked to the get_canonical_url filter in functions.php. Returns the
 * raw (already-validated) URL — WP core's own rel_canonical() escapes it
 * with esc_url() at output time, so this must not pre-escape (would double-
 * encode).
 *
 * @param string       $canonical_url  WordPress's own computed canonical URL.
 * @param WP_Post|null $post           The post being rendered.
 * @return string
 */
function pp_seo_canonical_url_override(string $canonical_url, $post): string {
    if (!$post) {
        return $canonical_url;
    }
    $override = pp_get_seo_meta($post->ID)['canonical_url'];
    return $override !== '' ? $override : $canonical_url;
}

// ── Open Graph / Twitter social-share meta (#468) ────────────────────────────
// Theme-owned wp_head emitter, beside the seo_title/meta_description/canonical
// rendering above. Resolves the site-level defaults (pp_og_* options) and the
// per-page overrides (og_title/twitter_title on _pp_seo_meta) into og:* and
// twitter:* tags. Two hard rules the whole surface obeys:
//   1. Emit a tag ONLY when its resolved value is non-empty (no empty-content
//      tags in <head>).
//   2. Every value is escaped at the sink — esc_url for URLs, esc_attr for
//      everything else — because every input is operator-settable and reaches
//      raw <head> output. A value that escapes to '' (e.g. esc_url() rejecting a
//      malformed URL) is treated as empty and dropped by rule 1.

/**
 * Emits one social meta tag, escaped, or nothing when the value is empty.
 *
 * Single sink for every og and twitter tag so the escape + no-empty-tag rules
 * live in exactly one place (a value that can't be escaped to a non-empty
 * string is never emitted).
 *
 * @param string $rel    The attribute name: 'property' (og:*) or 'name' (twitter:*).
 * @param string $key    The tag key, e.g. 'og:title'. A code constant, never user input.
 * @param string $value  The raw (unescaped) value.
 * @param bool   $is_url When true, escape with esc_url() instead of esc_attr().
 */
function _pp_emit_social_meta(string $rel, string $key, string $value, bool $is_url = false): void {
    // A whitespace-only value is treated as empty (the no-empty-tag rule): a
    // `content="   "` tag is as useless as an empty one.
    if (trim($value) === '') {
        return;
    }
    $escaped = $is_url ? esc_url($value) : esc_attr($value);
    if ($escaped === '') {
        return;
    }
    echo '<meta ' . $rel . '="' . $key . '" content="' . $escaped . '">' . "\n";
}

/**
 * Outputs the Open Graph + Twitter Card meta tags for the current page (#468).
 * Hooked to wp_head in functions.php. Singular pages only — the fallback chains
 * are page-scoped (post title, permalink), so on archive/search/home/404 there
 * is no single post to describe and the whole block is skipped.
 *
 * Fallback chains (page → site → WP):
 *   - og:title       = og_title → seo_title → post title
 *   - twitter:title  = twitter_title → (og:title chain)
 *   - og/twitter:description = meta_description → pp_og_default_description → omit
 *   - og/twitter:image       = pp_og_image → omit
 *   - og:site_name   = pp_og_site_name → get_bloginfo('name')
 *   - og:url = permalink; og:type = website; og:locale = get_locale();
 *     twitter:card = pp_twitter_card → summary_large_image
 */
function pp_social_meta_tags(): void {
    if (!is_singular()) {
        return;
    }
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return;
    }
    $meta = pp_get_seo_meta($post_id);

    // Title chains. A whitespace-only value counts as unset so the chain still
    // falls through (and the no-empty-tag rule holds) rather than emitting blank.
    $og_title = $meta['og_title'];
    if (trim($og_title) === '') {
        $og_title = $meta['seo_title'];
    }
    if (trim($og_title) === '') {
        $og_title = (string) get_the_title($post_id);
    }
    $twitter_title = trim($meta['twitter_title']) !== '' ? $meta['twitter_title'] : $og_title;

    // Description chain: page meta_description → site default → omit.
    $description = $meta['meta_description'];
    if (trim($description) === '') {
        $description = (string) get_option('pp_og_default_description', '');
    }

    // og:site_name: option override → the WP site name.
    $site_name = (string) get_option('pp_og_site_name', '');
    if (trim($site_name) === '') {
        $site_name = (string) get_bloginfo('name');
    }

    // twitter:card: option → default. Re-validate the stored value against the
    // closed set (a value that predates a whitelist change can't leak).
    $card = strtolower((string) get_option('pp_twitter_card', ''));
    if (!in_array($card, PP_TWITTER_CARD_VALUES, true)) {
        $card = 'summary_large_image';
    }

    _pp_emit_social_meta('property', 'og:type', 'website');
    _pp_emit_social_meta('property', 'og:site_name', $site_name);
    _pp_emit_social_meta('property', 'og:locale', (string) get_locale());
    _pp_emit_social_meta('property', 'og:url', (string) get_permalink($post_id), true);
    _pp_emit_social_meta('property', 'og:title', $og_title);
    _pp_emit_social_meta('property', 'og:description', $description);

    // Image tags. Re-check the attachment at render time — an ID validated on
    // write can be deleted/trashed later, and a stale ID must omit the image
    // tags, not emit a broken URL.
    $image_id = (int) get_option('pp_og_image', '');
    if (pp_is_image_attachment($image_id)) {
        $image_url = wp_get_attachment_image_url($image_id, 'full');
        if ($image_url) {
            _pp_emit_social_meta('property', 'og:image', (string) $image_url, true);
            $image_meta = wp_get_attachment_metadata($image_id);
            if (is_array($image_meta)) {
                if (!empty($image_meta['width'])) {
                    _pp_emit_social_meta('property', 'og:image:width', (string) (int) $image_meta['width']);
                }
                if (!empty($image_meta['height'])) {
                    _pp_emit_social_meta('property', 'og:image:height', (string) (int) $image_meta['height']);
                }
            }
            // Attachment alt only — no title fallback (an empty alt omits the tag).
            $image_alt = (string) get_post_meta($image_id, '_wp_attachment_image_alt', true);
            _pp_emit_social_meta('property', 'og:image:alt', $image_alt);
            _pp_emit_social_meta('name', 'twitter:image', (string) $image_url, true);
        }
    }

    _pp_emit_social_meta('name', 'twitter:card', $card);
    _pp_emit_social_meta('name', 'twitter:title', $twitter_title);
    _pp_emit_social_meta('name', 'twitter:description', $description);
}

/**
 * Creates a new page with the Composition template.
 *
 * @param string $title   Page title.
 * @param string $status  Post status (default 'draft').
 * @param string $slug    Optional slug (#134). Sanitized via sanitize_title().
 *                        Omit to let WordPress derive one from the title, as before.
 * @return int|WP_Error   New post ID, or WP_Error on failure.
 */
function pp_create_page(string $title, string $status = 'draft', string $slug = '') {
    $args = [
        'post_type'   => 'page',
        'post_title'  => $title,
        'post_status' => $status,
    ];

    if ($slug !== '') {
        $sanitized = sanitize_title($slug);
        if ($sanitized === '') {
            return new WP_Error('invalid_slug', 'Slug must not be empty after sanitization.');
        }
        $args['post_name'] = $sanitized;
    }

    $post_id = wp_insert_post($args, true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, '_wp_page_template', 'composition.php');
    return $post_id;
}

/**
 * Publishes a page (sets post_status to 'publish').
 *
 * @param int $post_id  WordPress post ID.
 * @return true|WP_Error
 */
function pp_publish_page(int $post_id) {
    $result = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
    if (is_wp_error($result)) {
        return $result;
    }
    return true;
}

/**
 * Promotes an 'auto-draft' page to a real 'draft' on first meaningful save
 * (#121). The post-new.php intercept creates pages as 'auto-draft' so an
 * unsaved visit doesn't leave a permanent, visible junk page — this is the
 * other half: once the author actually saves something, the page needs to
 * behave like a normal draft (visible in Pages, not core-GC'd). No-op for
 * any other status.
 *
 * @param int $post_id  WordPress post ID.
 */
function pp_promote_auto_draft(int $post_id): void {
    if (get_post_status($post_id) === 'auto-draft') {
        $result = wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);
        // The composition/title save this follows has already succeeded and
        // written real data — failing the whole request over a status-only
        // follow-up write would be disproportionate. But silently swallowing
        // it would leave a page with real content hidden (and GC-eligible)
        // with zero signal, so log it (adversarial review finding).
        if (is_wp_error($result)) {
            error_log('PromptingPress: pp_promote_auto_draft failed for post ' . $post_id
                . ': ' . $result->get_error_message());
        }
    }
}

/**
 * Updates a whitelisted WordPress option.
 * Whitelist is the single source pp_allowed_site_options(); value is validated
 * against the key's declared type (e.g. pp_logo_id must be an attachment ID).
 *
 * @param string $key    Option name (must be whitelisted).
 * @param string $value  New option value.
 * @return true|WP_Error
 */
function pp_update_site_option(string $key, string $value) {
    if (!isset(pp_allowed_site_options()[$key])) {
        return new WP_Error('invalid_option', sprintf('Option "%s" is not whitelisted.', $key));
    }
    $valid = pp_validate_site_option_value($key, $value);
    if (is_wp_error($valid)) {
        return $valid;
    }
    // Normalize to each type's canonical stored form.
    $type = pp_allowed_site_options()[$key] ?? null;
    if ($type === 'attachment_id') {
        $value = (string) (int) $value;
    } elseif ($type === 'bool') {
        $value = pp_normalize_bool_option($value);
    } elseif ($type === 'social') {
        // '' clears the row; a non-empty value is canonicalized (strip extra
        // keys / whitespace) so it survives the snapshot/rollback round-trip.
        $value = trim($value) === '' ? '' : pp_normalize_footer_social($value);
    } elseif ($type === 'twitter_card') {
        // Store the lower-cased canonical form (or '' to clear) so a stored
        // value always re-validates through the snapshot/rollback path.
        $value = trim($value) === '' ? '' : strtolower(trim($value));
    }
    update_option($key, $value);
    return true;
}

// ── Front-end redirects (#62) ────────────────────────────────────────────────
// A generic, site-agnostic safe-surface redirect capability. Renamed/moved
// pages (see update_page_slug, #134) leave their old URL 404ing; a redirect
// records old-path → canonical-target so the old URL 301s instead. Storage is
// a single DB option (survives theme updates — never hardcoded in theme files).
//
// Store shape (option `pp_redirects`):
//   [ normalized_from_path => ['to' => string, 'code' => int(301|302)], ... ]
//
// Resolver runs only on an otherwise-unmatched (404) front-end request, so a
// redirect never shadows a live page and the lookup stays off the hot path for
// every normal hit. Open-redirect safety is layered: same-site validation at
// write time (_pp_validate_redirect_target) AND wp_safe_redirect()'s own host
// allowlist at resolve time.
//
//   create_redirect(from,to) ──▶ validate ──▶ pp_redirects option
//                                   │
//   GET /old  ─(404)─▶ template_redirect ─▶ pp_resolve_redirect('/old')
//                                   │                    │ match {to,code}
//                                   └────────────────────▶ wp_safe_redirect(to)

const PP_REDIRECTS_OPTION = 'pp_redirects';

/**
 * Normalizes a path or URL to the canonical form used as a redirect map key.
 * Drops scheme/host/query/fragment, forces a single leading slash, strips a
 * trailing slash (root stays "/"). Same normalizer runs on both write (the
 * stored `from`) and read (the incoming request path) so a match can never be
 * missed on a trailing-slash or query-string difference.
 *
 * @param string $path  A path ("/old") or full URL ("https://site/old?x=1").
 * @return string       Canonical path, always starting with "/".
 */
function _pp_normalize_redirect_path(string $path): string {
    $only_path = parse_url(trim($path), PHP_URL_PATH);
    if (!is_string($only_path) || $only_path === '') {
        return '/';
    }
    $only_path = '/' . ltrim($only_path, '/');
    $trimmed = rtrim($only_path, '/');
    return $trimmed === '' ? '/' : $trimmed;
}

/**
 * Validates a redirect target for open-redirect safety: same-site only.
 * Accepts a site-relative path ("/new") or an absolute URL whose host matches
 * the site's home host. Rejects external hosts, protocol-relative "//host",
 * and dangerous schemes (javascript:, data:, vbscript:). wp_safe_redirect() at
 * resolve time re-checks the host allowlist as a runtime backstop.
 *
 * @param string $to  Proposed target.
 * @return true|WP_Error
 */
function _pp_validate_redirect_target(string $to) {
    $to = trim($to);
    if ($to === '') {
        return new WP_Error('invalid_redirect_target', 'Redirect target must not be empty.');
    }
    if (preg_match('#^\s*(?:javascript|data|vbscript)\s*:#i', $to)) {
        return new WP_Error('invalid_redirect_target', 'Redirect target scheme is not allowed.');
    }
    // Protocol-relative "//host/path" points off-site — reject before the
    // leading-slash path check below would treat it as same-site.
    if (strpos($to, '//') === 0) {
        return new WP_Error('external_redirect_target', 'Protocol-relative redirect targets are not allowed; use a same-site path or absolute same-host URL.');
    }
    // Site-relative path.
    if ($to[0] === '/') {
        return true;
    }
    // Absolute URL: host must equal the site's home host.
    $host = parse_url($to, PHP_URL_HOST);
    $home_host = parse_url(home_url('/'), PHP_URL_HOST);
    if (!is_string($host) || $host === '' || !is_string($home_host) || strcasecmp($host, $home_host) !== 0) {
        return new WP_Error('external_redirect_target', 'Redirect target must be a same-site path or an absolute URL on this site.');
    }
    return true;
}

/**
 * Returns the stored redirect map, normalized to the documented shape.
 *
 * @return array<string,array{to:string,code:int}>
 */
function pp_get_redirects(): array {
    $raw = get_option(PP_REDIRECTS_OPTION, []);
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $from => $entry) {
        if (!is_string($from) || !is_array($entry) || !isset($entry['to'])) {
            continue;
        }
        $code = (int) ($entry['code'] ?? 301);
        $out[$from] = [
            'to'   => (string) $entry['to'],
            'code' => in_array($code, [301, 302], true) ? $code : 301,
        ];
    }
    return $out;
}

/**
 * Resolves an incoming path to its redirect entry, or null if none matches.
 *
 * @param string $path  Incoming request path or URL.
 * @return array{to:string,code:int}|null
 */
function pp_resolve_redirect(string $path): ?array {
    $redirects = pp_get_redirects();
    $norm = _pp_normalize_redirect_path($path);
    return $redirects[$norm] ?? null;
}

/**
 * Detects whether adding from → to would create a redirect loop. Rejects the
 * degenerate from == to case and any multi-hop chain that cycles back, by
 * walking targets (each `to` reduces to a same-site path) with a hop cap.
 *
 * @param string $from_norm  Normalized source path being added.
 * @param string $to         Proposed (validated same-site) target.
 * @param array  $existing   Current redirect map.
 * @return bool  True if the redirect would loop.
 */
function _pp_redirect_would_loop(string $from_norm, string $to, array $existing): bool {
    $visited = [$from_norm => true];
    $cursor = _pp_normalize_redirect_path($to);
    $hops = 0;
    while (true) {
        if (isset($visited[$cursor])) {
            return true;
        }
        if (++$hops > 20) {
            return true;
        }
        $visited[$cursor] = true;
        if (!isset($existing[$cursor]['to'])) {
            return false;
        }
        $cursor = _pp_normalize_redirect_path((string) $existing[$cursor]['to']);
    }
}

/**
 * Creates (or replaces) a redirect from a source path to a same-site target.
 * Validates the target for open-redirect safety and refuses from == to or a
 * chain that would loop.
 *
 * @param string $from  Source path (or URL) to redirect away from.
 * @param string $to    Same-site target path or absolute same-host URL.
 * @param int    $code  301 (default) or 302.
 * @return string|WP_Error  The normalized source path stored, or WP_Error.
 */
function pp_create_redirect(string $from, string $to, int $code = 301) {
    if (!in_array($code, [301, 302], true)) {
        return new WP_Error('invalid_redirect_code', 'Redirect status code must be 301 or 302.');
    }
    $from_norm = _pp_normalize_redirect_path($from);
    if ($from_norm === '/') {
        return new WP_Error('invalid_redirect_source', 'Refusing to redirect the site root.');
    }
    $target_valid = _pp_validate_redirect_target($to);
    if (is_wp_error($target_valid)) {
        return $target_valid;
    }
    $to = trim($to);
    if ($from_norm === _pp_normalize_redirect_path($to)) {
        return new WP_Error('redirect_loop', 'A redirect source and target must differ.');
    }
    $redirects = pp_get_redirects();
    if (_pp_redirect_would_loop($from_norm, $to, $redirects)) {
        return new WP_Error('redirect_loop', 'This redirect would create a loop.');
    }
    $redirects[$from_norm] = ['to' => $to, 'code' => $code];
    update_option(PP_REDIRECTS_OPTION, $redirects);
    return $from_norm;
}

/**
 * Removes a redirect by source path. Returns true if one was removed, false if
 * no redirect existed for that (normalized) source.
 *
 * @param string $from  Source path (or URL).
 * @return bool
 */
function pp_remove_redirect(string $from): bool {
    $from_norm = _pp_normalize_redirect_path($from);
    $redirects = pp_get_redirects();
    if (!isset($redirects[$from_norm])) {
        return false;
    }
    unset($redirects[$from_norm]);
    update_option(PP_REDIRECTS_OPTION, $redirects);
    return true;
}

/**
 * template_redirect resolver. Fires on every front-end request but acts only
 * when WordPress found nothing to render (is_404) — an unmatched request — so a
 * redirect can rescue a renamed/moved URL without ever shadowing a live page.
 * Registered in functions.php.
 */
function pp_redirect_template_hook(): void {
    if (is_admin() || !is_404()) {
        return;
    }
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    if ($request_uri === '') {
        return;
    }
    $match = pp_resolve_redirect($request_uri);
    if ($match === null) {
        return;
    }
    // wp_safe_redirect() re-validates the target host (open-redirect backstop)
    // and escapes the Location header.
    wp_safe_redirect($match['to'], $match['code']);
    exit;
}

// ── Template tags ──────────────────────────────────────────────────────────

/**
 * Loads the comments template (comments.php) for the current post.
 * Wrapper for comments_template() — maintains the invariant that
 * templates call only pp_* functions, never raw WP functions.
 */
function pp_comments_template(): void {
    if (comments_open() || get_comments_number()) {
        comments_template();
    }
}

// ── Default content ─────────────────────────────────────────────────────────

/**
 * Returns the default homepage composition used on fresh installs and as the
 * blank-page fallback. Single source of truth — called by lib/setup.php at
 * activation time and by templates/front-page.php as a render-time safeguard.
 *
 * A curated branded multi-band starter (issue #512): a dark split hero, an
 * audience/problem band, a files-vs-WordPress mechanism band (native text-panel
 * with a monospace spec panel — no inline-HTML), a speed/trust card grid, a
 * maintainability/proof band, and a closing CTA. The branded look (warm-cream
 * surfaces, ink darks, restrained orange accent) is expressed entirely through
 * VALIDATED per-component style slots (top-level `style` key) and native
 * component props — never homepage-only shared CSS (#72) and never render-time
 * mutation. The only HTML string is the hero proof surface, which uses the
 * theme's own `hero__surface-*` classes (theme tokens, no hardcoded colours)
 * rather than one-off inline styling. Every band is schema-valid; the whole
 * composition passes pp_validate_composition() (guarded by SchemaValidationTest).
 *
 * This is the FRESH-INSTALL starter seed only. Theme activation writes it once
 * (pp_setup_homepage), guarded to never overwrite an existing valid static
 * front page — so an already-configured live site keeps its own homepage.
 *
 * Palette (all values are literal, slot-valid):
 *   ink #0A0A12 / #14141F · cream #F2EEE5 / #FBF8F1 · border #E8E2D4
 *   accent #FF5C2E (hover #C73310)
 *
 * @return array  Component array ready for wp_json_encode or direct rendering.
 */
function pp_default_homepage_composition(): array {
    return [
        // 1 — Branded split hero (dark), proof surface via theme classes.
        ['component' => 'hero', 'props' => [
            'id'            => 'home-hero',
            'eyebrow'       => 'Open-source AI-first WordPress theme',
            'title'         => 'Turn AI-assisted site drafts into maintainable WordPress composition.',
            'title_accent'  => 'maintainable',
            'subtitle'      => 'PromptingPress gives small WordPress teams a bounded page-composition layer. AI drafts sections, humans inspect props and style slots, and every change can be validated and screenshotted before review.',
            'cta_text'      => 'View theme on GitHub',
            'cta_url'       => 'https://github.com/FJCF76/PromptingPress',
            // Both hero CTAs are outline: on the dark hero the filled premium
            // `.btn` bevel is a fixed blue gradient driven by the GLOBAL
            // --color-accent/--btn-bg tokens (a fresh install has no token
            // override), so --hero-accent alone cannot repaint it, and the ghost
            // variant's text is not reachable by --hero-cta2-color. Outline
            // clears that gradient and tracks --hero-text (cream), hover-filling
            // with --hero-accent (orange) — on-brand, restrained hero CTAs. The
            // one filled orange button stays the closing CTA (--cta-button-bg).
            'cta_variant'   => 'outline',
            'cta2_text'     => 'See how it works',
            'cta2_url'      => '#home-mechanism',
            'cta2_variant'  => 'outline',
            'layout'        => 'split',
            'split_ratio'   => '60-40',
            'vertical_align' => 'stretch',
            'proof'         => '<p class="hero__surface-label">Composition workflow</p><div class="hero__surface-list"><div class="hero__surface-item"><span class="hero__surface-key">Read</span><span class="hero__surface-value">Structured site context</span></div><div class="hero__surface-item"><span class="hero__surface-key">Edit</span><span class="hero__surface-value">Bounded props, not builder clutter</span></div><div class="hero__surface-item"><span class="hero__surface-key">Validate</span><span class="hero__surface-value">Screenshot-backed before review</span></div></div>',
        ], 'style' => [
            '--hero-bg'                  => 'radial-gradient(circle at 78% 24%, #3A1D1D 0%, #14141F 44%, #0A0A12 100%)',
            '--hero-text'               => '#F2EEE5',
            '--hero-subtitle-color'     => '#E8E2D4',
            '--hero-padding-top'        => '7rem',
            '--hero-padding-bottom'     => '6rem',
            '--hero-content-width'      => '64rem',
            '--hero-title-size'         => 'clamp(2.75rem, 5vw, 4.75rem)',
            '--hero-accent'             => '#FF5C2E',
            '--hero-accent-hover'       => '#C73310',
            '--hero-title-accent-color' => '#FF5C2E',
            '--hero-eyebrow-color'      => '#FF5C2E',
            '--hero-eyebrow-bg'         => '#14141F',
            '--hero-eyebrow-border-color' => 'rgba(255, 92, 46, 0.4)',
            '--hero-eyebrow-border-width' => '1px',
            '--hero-radius'             => '0',
            '--hero-surface-bg'         => '#F2EEE5',
            '--hero-surface-border-color' => '#E8E2D4',
            '--hero-surface-radius'     => '4px',
            '--hero-surface-shadow'     => '0 24px 60px rgba(0, 0, 0, 0.28)',
        ]],

        // 2 — Audience / problem band (warm cream), prose + meta strip.
        ['component' => 'section', 'props' => [
            'id'            => 'home-audience',
            'eyebrow'       => 'The cost after launch',
            'title'         => 'AI speeds up the first draft. Teams still own the maintenance.',
            'title_accent'  => 'own the maintenance',
            'subheading'    => 'Speed is not the hard part anymore. The next revision is.',
            'body'          => '<p>An AI page can look done in minutes. Then the team inherits unclear structure, scattered styling, and a page that is hard to inspect the next time a client asks for a change.</p><p>PromptingPress keeps that next edit reviewable: WordPress sections, bounded component props, validated style slots, and screenshots stay in the workflow before anything is treated as done.</p>',
            'body_items'    => ['First-draft speed', 'Revision debt', 'Handoff clarity', 'Safer edits'],
            'layout'        => 'text-only',
        ], 'style' => [
            '--section-bg'                => '#F2EEE5',
            '--section-border-color'      => '#E8E2D4',
            '--section-border-width'      => '1px',
            '--section-title-size'        => 'clamp(1.9rem, 3vw, 2.9rem)',
            '--section-title-accent-color' => '#FF5C2E',
            '--section-body-width'        => '46rem',
            '--section-eyebrow-color'     => '#FF5C2E',
            '--section-eyebrow-bg'        => '#FBF8F1',
            '--section-eyebrow-border-color' => '#E8E2D4',
            '--section-eyebrow-border-width' => '1px',
            '--section-separator-color'   => '#FF5C2E',
        ]],

        // 3 — Mechanism band (native text-panel, monospace dark spec panel).
        ['component' => 'section', 'props' => [
            'id'            => 'home-mechanism',
            'eyebrow'       => 'The composition boundary',
            'title'         => 'Files own the design. WordPress owns the content.',
            'title_accent'  => 'design',
            'subheading'    => 'A clear split is what keeps AI work maintainable after handoff.',
            'body'          => '<p>The repeatable system stays in the theme: templates, components, design tokens, and CSS. The page stays in WordPress as structured composition data an implementer or agent can inspect later.</p><p>Agents get a bounded map of what can change, so edits stay inside a reviewable path.</p>',
            'layout'        => 'text-panel',
            'panel_heading' => 'What lives where',
            'panel_items'   => [
                ['label' => 'Templates & components', 'value' => 'Theme files'],
                ['label' => 'Design tokens & CSS',    'value' => 'Theme files'],
                ['label' => 'Copy & section order',   'value' => 'WordPress'],
                ['label' => 'Component props & slots', 'value' => 'WordPress'],
                ['label' => 'Validation & screenshots', 'value' => 'Review path'],
            ],
        ], 'style' => [
            '--section-bg'                => '#FBF8F1',
            '--section-border-color'      => '#E8E2D4',
            '--section-border-width'      => '1px',
            '--section-title-size'        => 'clamp(1.9rem, 3vw, 2.9rem)',
            '--section-title-accent-color' => '#FF5C2E',
            '--section-eyebrow-color'     => '#FF5C2E',
            '--section-eyebrow-bg'        => '#F2EEE5',
            '--section-eyebrow-border-color' => '#E8E2D4',
            '--section-eyebrow-border-width' => '1px',
            '--section-panel-bg'          => '#0A0A12',
            '--section-panel-text'        => '#F2EEE5',
            '--section-panel-border-color' => '#14141F',
            '--section-panel-radius'      => '4px',
            '--section-panel-font'        => 'var(--font-mono)',
        ]],

        // 4 — Speed / trust card grid (dark band, uniform peer cards).
        ['component' => 'grid', 'props' => [
            'id'            => 'home-adoption',
            'eyebrow'       => 'Why teams adopt it',
            'title'         => 'Two reasons: speed and trust.',
            'title_accent'  => 'speed and trust',
            'subheading'    => 'Speed gets the first draft moving. Trust keeps the next revision safe to review.',
            'layout'        => 'cards',
            'card_emphasis' => 'uniform',
            'columns'       => 2,
            'items'         => [
                ['title' => 'Lightweight by default', 'text' => 'Client sites should not carry a heavy visual-builder runtime just because AI helped write the page.', 'bullets' => ['Plain WordPress, PHP, and CSS', 'No builder lock-in', 'Fast front-end']],
                ['title' => 'AI-safe structure', 'text' => 'The next agent pass should not have to guess through hidden state.', 'bullets' => ['Components, IDs, props, slots', 'Inspectable page data', 'No hidden logic']],
                ['title' => 'Readable handoff', 'text' => 'Teams can explain what was built and where the content lives.', 'bullets' => ['A clear section map', 'Content in WordPress', 'No opaque AI artifact']],
                ['title' => 'Safer revisions', 'text' => 'AI-assisted updates on live sites stay easy to trust.', 'bullets' => ['Preflight checks', 'Screenshots before apply', 'Rollback-aware actions']],
            ],
        ], 'style' => [
            '--grid-bg'                  => '#0A0A12',
            '--grid-heading-color'       => '#F2EEE5',
            '--grid-heading-accent-color' => '#FF5C2E',
            '--grid-heading-size'        => 'clamp(1.9rem, 3vw, 2.9rem)',
            '--grid-heading-max-width'   => '44rem',
            '--grid-subheading-color'    => '#E8E2D4',
            '--grid-eyebrow-color'       => '#FF5C2E',
            '--grid-eyebrow-bg'          => '#14141F',
            '--grid-eyebrow-border-color' => '#3A2A1E',
            '--grid-eyebrow-border-width' => '1px',
            '--grid-card-bg'             => '#F2EEE5',
            '--grid-card-border'         => '#E8E2D4',
            '--grid-card-border-width'   => '1px',
            '--grid-card-radius'         => '4px',
            '--grid-card-bar-color'      => '#FF5C2E',
            '--grid-card-bar-height'     => '3px',
            '--grid-card-shadow'         => '0 18px 38px rgba(0, 0, 0, 0.18)',
            '--grid-item-title-color'    => '#0A0A12',
            '--grid-item-text-color'     => '#3A3A44',
            '--grid-bullet-color'        => '#FF5C2E',
        ]],

        // 5 — Maintainability / proof band (warm cream), prose + workflow strip.
        ['component' => 'section', 'props' => [
            'id'            => 'home-proof',
            'eyebrow'       => 'The review path',
            'title'         => 'AI edits leave evidence, not mystery.',
            'title_accent'  => 'leave evidence',
            'subheading'    => 'The first draft only matters if the next change is still inspectable and safe to hand off.',
            'body'          => '<p>Most AI website workflows optimize for the first page. PromptingPress optimizes for the work after it: the next revision, the client change request, the page expansion, and the post-launch handoff all stay inside a reviewable WordPress composition path.</p><p>The agent can inspect the page, edit bounded props, validate the result, render screenshots, and leave a trail before work is treated as done.</p>',
            'body_items'    => ['Inspect', 'Edit bounded props', 'Validate', 'Screenshot', 'Roll back'],
            'layout'        => 'text-only',
        ], 'style' => [
            '--section-bg'                => '#F2EEE5',
            '--section-border-color'      => '#E8E2D4',
            '--section-border-width'      => '1px',
            '--section-title-size'        => 'clamp(1.9rem, 3vw, 2.9rem)',
            '--section-title-accent-color' => '#FF5C2E',
            '--section-body-width'        => '46rem',
            '--section-eyebrow-color'     => '#FF5C2E',
            '--section-eyebrow-bg'        => '#FBF8F1',
            '--section-eyebrow-border-color' => '#E8E2D4',
            '--section-eyebrow-border-width' => '1px',
            '--section-separator-color'   => '#FF5C2E',
        ]],

        // 6 — Closing CTA (dark), branded orange button.
        ['component' => 'cta', 'props' => [
            'id'            => 'home-cta',
            'eyebrow'       => 'Get started',
            'title'         => 'Get the open-source PromptingPress theme.',
            'title_accent'  => 'PromptingPress',
            'text'          => 'PromptingPress is on GitHub for WordPress teams that want an AI-first theme built around inspectable composition, bounded edits, validation, and review evidence.',
            'button_text'   => 'View theme on GitHub',
            'button_url'    => 'https://github.com/FJCF76/PromptingPress',
            'button_variant' => 'primary',
            'layout'        => 'full-width',
        ], 'style' => [
            '--cta-bg'                  => '#0A0A12',
            '--cta-text'                => '#F2EEE5',
            '--cta-body-color'          => '#E8E2D4',
            '--cta-padding-top'         => '5.5rem',
            '--cta-padding-bottom'      => '5.5rem',
            '--cta-content-width'       => '48rem',
            '--cta-title-size'          => 'clamp(2rem, 3vw, 3.1rem)',
            '--cta-title-accent-color'  => '#FF5C2E',
            '--cta-accent'              => '#FF5C2E',
            '--cta-accent-hover'        => '#C73310',
            '--cta-button-bg'           => '#FF5C2E',
            '--cta-button-color'        => '#0A0A12',
            '--cta-button-hover-bg'     => '#C73310',
            '--cta-button-hover-color'  => '#F2EEE5',
            '--cta-eyebrow-color'       => '#FF5C2E',
            '--cta-eyebrow-bg'          => '#14141F',
            '--cta-eyebrow-border-color' => '#3A2A1E',
            '--cta-eyebrow-border-width' => '1px',
        ]],
    ];
}
