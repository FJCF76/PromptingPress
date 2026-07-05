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

/**
 * Returns the composition array for the current page from _pp_composition post meta.
 * Returns an empty array when the meta is absent, empty, or contains invalid JSON.
 *
 * @return array  Array of component objects: [['component' => string, 'props' => array], ...]
 */
function pp_composition(): array {
    $raw = get_post_meta(get_the_ID(), '_pp_composition', true);
    if (!$raw) {
        return [];
    }
    $items = json_decode($raw, true);
    return is_array($items) ? $items : [];
}

// ── Site-state read functions (action-layer support) ─────────────────────────

/**
 * Returns the composition array for a specific page by post ID.
 * Unlike pp_composition(), this works outside the loop.
 *
 * @param int $post_id  WordPress post ID.
 * @return array  Array of component objects, or [] if absent/invalid.
 */
function pp_get_composition(int $post_id): array {
    $raw = get_post_meta($post_id, '_pp_composition', true);
    if (!$raw) {
        return [];
    }
    // Normal storage is a JSON string (pp_update_composition). Be defensive about
    // callers/fixtures that persist an already-decoded array.
    if (is_array($raw)) {
        return $raw;
    }
    $items = json_decode($raw, true);
    return is_array($items) ? $items : [];
}

/**
 * Diagnoses navigation readiness for the locations a composition actually uses.
 *
 * Scoped to locations REFERENCED by `nav` components in the composition (a nav
 * component defaults to the 'primary' location). Registered-but-unused locations
 * are intentionally NOT flagged — that avoids false failures on, e.g., a site
 * that registers a footer menu it never renders.
 *
 * For each referenced location it flags, in order:
 *   - reference to an UNREGISTERED location (a real config error),
 *   - a registered location with NO menu assigned,
 *   - an assigned menu that resolves to ZERO items.
 * A ready location reports a passing row. Every row is severity=warning: nav
 * readiness is an operator-facing diagnostic, never a gate on content mutations.
 *
 * @param array $composition  Component objects (e.g. from pp_get_composition()).
 * @return array[]  Rows: ['check'=>'nav_readiness','pass'=>bool,'severity'=>'warning','message'=>string].
 */
function pp_check_nav_readiness(array $composition): array {
    // Collect locations referenced by nav components (nav defaults to 'primary').
    // Defensive: a malformed composition that bypassed validation may carry
    // non-array items or props, so guard both before indexing.
    $referenced = [];
    foreach ($composition as $item) {
        if (!is_array($item) || ($item['component'] ?? '') !== 'nav') {
            continue;
        }
        $props = is_array($item['props'] ?? null) ? $item['props'] : [];
        $referenced[$props['location'] ?? 'primary'] = true;
    }
    if (empty($referenced)) {
        return []; // No nav rendered — nothing to diagnose.
    }

    $registered = array_keys(get_registered_nav_menus());
    $locations  = get_nav_menu_locations(); // location => menu_id
    $checks     = [];

    foreach (array_keys($referenced) as $loc) {
        // $loc is operator/AI-controlled composition data — escape it for display
        // (messages may be rendered in the admin UI). Raw $loc is used for lookups.
        $safe_loc = esc_html((string) $loc);

        if (!in_array($loc, $registered, true)) {
            $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
                'message' => 'Navigation references unregistered location "' . $safe_loc . '". Register it in functions.php or fix the nav component\'s location.'];
            continue;
        }
        if (!has_nav_menu($loc)) {
            $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
                'message' => 'Navigation location "' . $safe_loc . '" has no menu assigned. Assign one under Appearance → Menus.'];
            continue;
        }
        $menu_id = $locations[$loc] ?? 0;
        $items   = $menu_id ? wp_get_nav_menu_items($menu_id) : false;
        if (empty($items)) {
            $checks[] = ['check' => 'nav_readiness', 'pass' => false, 'severity' => 'warning',
                'message' => 'Navigation menu assigned to "' . $safe_loc . '" is empty. Add items under Appearance → Menus.'];
            continue;
        }
        $checks[] = ['check' => 'nav_readiness', 'pass' => true, 'severity' => 'warning',
            'message' => 'Navigation location "' . $safe_loc . '" is ready (' . count($items) . ' item(s)).'];
    }

    return $checks;
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
 * Renders style slot overrides as a CSS custom property string.
 *
 * Validates each property against the component's declared style slots.
 * Unknown properties and the __recipe tracking key are silently skipped.
 * Values containing injection characters ({, }, ;, <, >) are skipped.
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
        // Injection guard: reject { } ; < > (same guard as _pp_validate_token_value).
        if (preg_match('/[{};<>]/', $value)) {
            continue;
        }
        $properties[] = esc_attr($name) . ': ' . esc_attr($value);
    }

    return implode('; ', $properties);
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
 * Runs $mutator inside a serialized critical section for pp_token_overrides.
 *
 * Acquires the advisory lock with a bounded timeout, runs the mutator, and releases
 * in `finally` so normal AND exception unwinding both free it. This is not an absolute
 * guarantee — a hard fatal/SIGKILL or a persistent connection can still strand a lock —
 * so the bounded acquire timeout and MySQL's connection-close auto-release are the
 * backstops. On acquisition failure the mutator does NOT run and $fail_value is returned
 * (explicit failure, never a silent partial write). Degrades to running the mutator
 * directly when no $wpdb is present (unit context); production always has $wpdb.
 *
 * @param callable $mutator     Performs the cache-authoritative read/modify/write.
 * @param mixed    $fail_value  Returned if the lock cannot be acquired.
 * @return mixed
 */
function _pp_with_token_lock(callable $mutator, $fail_value) {
    global $wpdb;
    $has_db = isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var');
    if (!$has_db) {
        return $mutator(null);
    }

    $name = $wpdb->prepare('%s', _pp_token_lock_name());
    // GET_LOCK: 1 = acquired, 0 = timed out, NULL = error.
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
        error_log('PromptingPress: pp_token_overrides ' . $reason
            . '; token write skipped to avoid a lost update.');
        return $fail_value;
    }

    try {
        return $mutator($wpdb);
    } finally {
        $wpdb->query("SELECT RELEASE_LOCK($name)");
    }
}

/**
 * Reads pp_token_overrides authoritatively inside the lock. Reads the single option
 * row straight from the DB (bypassing the options cache) so a concurrent writer's
 * just-committed value is visible and a stale cached map can't overwrite a newer one
 * inside the critical section. Reads exactly the one row rather than busting the whole
 * `alloptions` autoload cache.
 *
 * @param object|null $wpdb  The DB handle inside the lock, or null in unit context.
 */
function _pp_read_token_overrides_locked($wpdb = null): array {
    if (is_object($wpdb) && method_exists($wpdb, 'get_var')) {
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'pp_token_overrides'
            )
        );
        if ($raw === null) {
            return [];
        }
        $value = maybe_unserialize($raw);
        return is_array($value) ? $value : [];
    }
    // No DB handle (unit context): fall back to the cached/stubbed option.
    return pp_get_token_overrides();
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
 * atomically. Degrades to the plain cached read if the lock cannot be acquired (a
 * slightly racy baseline is still far better than no snapshot).
 *
 * @return array  token => value map.
 */
function pp_snapshot_token_overrides(): array {
    return _pp_with_token_lock( function ( $wpdb ) {
        return _pp_read_token_overrides_locked( $wpdb );
    }, pp_get_token_overrides() );
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
 * Extracts the hue (0–360) from an RGB triplet.
 * Returns null for achromatic colors (saturation near zero).
 */
function _pp_rgb_to_hue(array $rgb): ?float {
    $r = $rgb[0] / 255;
    $g = $rgb[1] / 255;
    $b = $rgb[2] / 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $delta = $max - $min;

    if ($delta < 0.02) {
        return null; // achromatic
    }

    if ($max === $r) {
        $h = 60 * fmod(($g - $b) / $delta, 6);
    } elseif ($max === $g) {
        $h = 60 * (($b - $r) / $delta + 2);
    } else {
        $h = 60 * (($r - $g) / $delta + 4);
    }

    return $h < 0 ? $h + 360 : $h;
}

/**
 * Checks whether existing derived token overrides are coherent with a new base value.
 *
 * Returns warnings for tokens whose hue drifts more than 30° from the new base,
 * suggesting they may be stale from a previous palette. Only checks tokens that
 * already have an override in the database (unset tokens get auto-derived, so they
 * can't be stale).
 *
 * @param string $base_token  e.g. '--color-accent'.
 * @param string $base_value  New hex value for the base token.
 * @return array<array{token: string, current: string, expected: string, message: string}>
 */
function pp_check_token_coherence(string $base_token, string $base_value): array {
    $families = pp_token_families();
    if (!isset($families[$base_token])) {
        return [];
    }

    $base_rgb = _pp_hex_to_rgb($base_value);
    if ($base_rgb === null) {
        return [];
    }
    $base_hue = _pp_rgb_to_hue($base_rgb);

    $overrides = pp_get_token_overrides();
    $derived = pp_derive_family_tokens($base_token, $base_value);
    $warnings = [];

    foreach ($families[$base_token] as $derived_token => $_recipe) {
        // Only warn about tokens that already have overrides (those are the ones we skip)
        if (!isset($overrides[$derived_token])) {
            continue;
        }

        $current_rgb = _pp_hex_to_rgb($overrides[$derived_token]);
        if ($current_rgb === null) {
            continue;
        }
        $current_hue = _pp_rgb_to_hue($current_rgb);

        // Skip achromatic comparisons (grays have no meaningful hue)
        if ($base_hue === null || $current_hue === null) {
            continue;
        }

        // Circular hue distance
        $distance = abs($current_hue - $base_hue);
        if ($distance > 180) {
            $distance = 360 - $distance;
        }

        if ($distance > 30) {
            $warnings[] = [
                'token'    => $derived_token,
                'current'  => $overrides[$derived_token],
                'expected' => $derived[$derived_token] ?? $overrides[$derived_token],
                'message'  => sprintf(
                    '%s (%s) may be stale — hue differs %.0f° from %s (%s). Consider updating it.',
                    $derived_token, $overrides[$derived_token], $distance, $base_token, $base_value
                ),
            ];
        }
    }

    return $warnings;
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
 * to a Media Library attachment — never a raw URL).
 *
 * @return array<string,string>  key => type
 */
function pp_allowed_site_options(): array {
    return [
        'blogname'        => 'string',
        'blogdescription' => 'string',
        'pp_logo_id'      => 'attachment_id',
        'pp_logo_alt'     => 'string',
    ];
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
        $id = (int) $value;
        if ($id <= 0 || get_post_type($id) !== 'attachment' || !wp_attachment_is_image($id)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" requires a Media Library image attachment ID, got "%s".',
                $key, $value
            ));
        }
    }
    return true;
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

    if ($id > 0) {
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
 * Writes a composition array to post meta.
 * Thin persistence wrapper — handles JSON serialization internally.
 * Does NOT validate (the action layer owns validation).
 *
 * @param int   $post_id      WordPress post ID.
 * @param array $composition  Array of component objects.
 * @return true|WP_Error
 */
function pp_update_composition(int $post_id, array $composition) {
    // Auto-assign stable IDs to entries that don't have one.
    // Generated once at write time so IDs are persisted and never shift.
    foreach ($composition as &$item) {
        $props = $item['props'] ?? [];
        if (empty($props['id'])) {
            $item['props']['id'] = 'pp-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
    }
    unset($item);

    $json = wp_json_encode($composition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    update_post_meta($post_id, '_pp_composition', wp_slash($json));
    return true;
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
 * Creates a new page with the Composition template.
 *
 * @param string $title   Page title.
 * @param string $status  Post status (default 'draft').
 * @return int|WP_Error   New post ID, or WP_Error on failure.
 */
function pp_create_page(string $title, string $status = 'draft') {
    $post_id = wp_insert_post([
        'post_type'   => 'page',
        'post_title'  => $title,
        'post_status' => $status,
    ], true);

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
    // Normalize attachment IDs to a canonical integer string on store.
    if ((pp_allowed_site_options()[$key] ?? null) === 'attachment_id') {
        $value = (string) (int) $value;
    }
    update_option($key, $value);
    return true;
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
 * @return array  Component array ready for wp_json_encode or direct rendering.
 */
function pp_default_homepage_composition(): array {
    return [
        ['component' => 'hero', 'props' => [
            'id'       => 'home-hero',
            'title'    => 'AI-led WordPress pages that stay workable after the first draft.',
            'subtitle' => 'PromptingPress is built for real WordPress page workflows where AI can move fast on the first pass without turning revisions, handoff, and maintenance into cleanup debt.',
            'cta_text' => 'See how it works',
            'cta_url'  => '/how-promptingpress-works/',
            'variant'  => 'split',
            'split_ratio' => '60-40',
            'proof'    => '<p class="hero__surface-label">Product workflow surface</p><div class="hero__surface-list"><div class="hero__surface-item"><span class="hero__surface-key">Read</span><span class="hero__surface-value">Structured site context</span></div><div class="hero__surface-item"><span class="hero__surface-key">Edit</span><span class="hero__surface-value">Page composition, not builder clutter</span></div><div class="hero__surface-item"><span class="hero__surface-key">Validate</span><span class="hero__surface-value">Screenshot-backed changes before apply</span></div></div>',
        ]],
        ['component' => 'section', 'props' => [
            'title'  => 'The AI Comprehension Problem',
            'body'   => '<p>WordPress themes are designed for developers who accumulate knowledge over time. AI can\'t accumulate. Every session, it re-infers the same hidden logic from your code.</p><p>PromptingPress solves this with a thin abstraction layer, typed component schemas, and a single AI_CONTEXT.md that maps the entire site.</p>',
            'layout' => 'text-only',
        ]],
        ['component' => 'cta', 'props' => [
            'title'       => 'Ready to build your AI-ready site?',
            'text'        => 'Start with the theme, fill in AI_CONTEXT.md, and let your AI tool do the rest.',
            'button_text' => 'Get Started on GitHub',
            'button_url'  => 'https://github.com/FJCF76/PromptingPress',
            'variant'     => 'full-width',
        ]],
    ];
}
