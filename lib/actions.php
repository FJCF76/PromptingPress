<?php
/**
 * lib/actions.php — PromptingPress Typed Action Model
 *
 * Durable execution contract for AJAX, WP-CLI, and future AI surfaces.
 * Every mutation goes through this layer. Registry enforces structural
 * validation; individual actions handle semantic validation only.
 *
 * Action definition contract:
 *   name        => string (unique, snake_case)
 *   scope       => 'site' | 'page' | 'section'
 *   description => string (one sentence, caller-facing)
 *   semantics   => string (patch|replace, null behavior, validation timing)
 *   params      => [param_name => ['type' => string, 'required' => bool], ...]
 *   validate    => callable(array $params): true|WP_Error
 *   preview     => callable(array $params): array (diff, never writes)
 *   execute     => callable(array $params): array (canonical result shape)
 *
 * Canonical result shape (execute):
 *   ['ok' => bool, 'action' => string, 'scope' => string,
 *    'target' => array, 'changes' => array, 'error' => string|null]
 *
 * Preview result shape (same + before/after):
 *   ['ok' => true, 'action' => string, 'scope' => string,
 *    'target' => array, 'before' => mixed, 'after' => mixed,
 *    'changes' => array, 'error' => null]
 */

// ── Registry ────────────────────────────────────────────────────────────────

/**
 * Registers an action.
 */
function pp_register_action(string $name, array $definition): void {
    global $_pp_actions;
    if (!isset($_pp_actions)) {
        $_pp_actions = [];
    }
    $definition['name'] = $name;
    // Composition precondition (#358), DECLARATIVE + FAIL-CLOSED. Every action
    // defaults to requiring a non-empty composition on its target page; an action
    // opts out ONLY by explicitly setting 'requires_composition' => false. So a
    // newly registered component-level action that forgets the flag is gated by
    // default (default-deny) rather than silently mutable on a composition-less
    // page. pp_action_composition_precondition() (lib/operate.php) reads this flag;
    // it is a no-op for site-scoped actions (no post_id). See #358.
    if (!array_key_exists('requires_composition', $definition)) {
        $definition['requires_composition'] = true;
    }
    $_pp_actions[$name] = $definition;
}

/**
 * Returns all registered actions.
 */
function pp_get_registered_actions(): array {
    global $_pp_actions;
    return $_pp_actions ?? [];
}

/**
 * Returns a single action definition, or null if not registered.
 */
function pp_get_action(string $name): ?array {
    global $_pp_actions;
    return $_pp_actions[$name] ?? null;
}

/**
 * Validates action params: structural checks (required, types) then
 * the action's own semantic validate callable.
 *
 * @return true|WP_Error
 */
function pp_validate_action(string $name, array $params) {
    $action = pp_get_action($name);
    if (!$action) {
        return new WP_Error('unknown_action', sprintf('Action "%s" is not registered.', $name));
    }

    // Structural validation: required params and type checks
    foreach ($action['params'] as $param_name => $param_def) {
        if (!empty($param_def['required']) && !array_key_exists($param_name, $params)) {
            return new WP_Error(
                'missing_param',
                sprintf('Action "%s" requires param "%s".', $name, $param_name)
            );
        }
        if (array_key_exists($param_name, $params) && $params[$param_name] !== null) {
            $expected_type = $param_def['type'] ?? 'string';
            $actual_type   = gettype($params[$param_name]);
            $type_map      = [
                'int'    => 'integer',
                'string' => 'string',
                'array'  => 'array',
                'bool'   => 'boolean',
            ];
            $expected_php = $type_map[$expected_type] ?? $expected_type;
            if ($actual_type !== $expected_php) {
                return new WP_Error(
                    'invalid_param_type',
                    sprintf('Param "%s" must be %s, got %s.', $param_name, $expected_type, $actual_type)
                );
            }
        }
    }

    // Media-library URL/image-type validation (#124). Runs here — not in a
    // per-caller AJAX handler — so every caller of pp_validate_action()
    // (AJAX, WP-CLI, pp_patch_composition()) is covered by the same guard.
    $url_error = _pp_validate_media_urls_in_params($params);
    if (is_wp_error($url_error)) {
        return $url_error;
    }

    // Component-prop logo_id is an attachment ID (not a URL), so it bypasses the
    // media-URL walker above. Validate it with the same fail-closed rigor as the
    // pp_logo_id site option and #124's URL props, so a non-image/non-existent
    // logo_id is rejected at the action boundary instead of silently rendering a
    // text wordmark downstream (#155).
    $logo_error = _pp_validate_logo_ids_in_params($params);
    if (is_wp_error($logo_error)) {
        return $logo_error;
    }

    // Composition-presence precondition (#358, #387). Runs HERE — in the shared
    // validator, not in a per-entry-point gate — so every caller of
    // pp_validate_action() (AJAX via pp_execute_action(), WP-CLI, the batch
    // executor, and pp_patch_composition() via update_component) is covered by
    // the same guard. Before #387 this lived only in the WP-CLI gate
    // (_pp_cli_require_preflight_for_action), so the in-admin chat AJAX — which
    // calls pp_execute_action() directly — could add the first component to a
    // composition-less page, bypassing the gate. Component-level actions
    // (requires_composition defaults TRUE) fail closed on a composition-less
    // page with error_code 'composition_required'; populate/lifecycle/metadata
    // actions opt out via requires_composition => false; site-scoped actions
    // (no post_id) are a no-op. Declarative + fail-closed; the single predicate
    // lives in pp_action_composition_precondition() (lib/operate.php). Since #748
    // that predicate distinguishes the two states behind one code: a genuinely
    // blank page keeps "has none yet", while an UNREADABLE one is reported with
    // the shared integrity sentence naming unexpected_shape / decode_error —
    // "empty" is reserved for a page that really is.
    //
    // Gated on the page EXISTING: a nonexistent (or non-page) post is the
    // action's own not_found / not_a_page case, emitted by the semantic validate
    // below — "populate it first" would misdirect for a page that isn't there.
    // For an existing page we enforce BEFORE semantic validation so EVERY
    // requires_composition action reports the uniform composition_required rather
    // than a per-action index_out_of_bounds on a composition-less page.
    //
    // Only page/section-scoped actions carry a composition target. Site-scoped
    // actions (create_page, update_site_option, menu/CSS actions) inherit
    // requires_composition=TRUE by default but are NOT composition-targeted, so a
    // STRAY post_id in their params must not trip this guard — key on the declared
    // scope, not just the raw param. This mirrors pp_action_composition_precondition()'s
    // own "site-scoped is a no-op" contract and keeps acceptance #2 (site-scoped
    // actions unaffected) true even when a caller passes an undeclared post_id.
    $post_id = isset($params['post_id']) ? (int) $params['post_id'] : null;
    $is_site_scope = ($action['scope'] ?? '') === 'site';
    if (!$is_site_scope && $post_id !== null && _pp_validate_page_exists($post_id) === true) {
        $composition_error = pp_action_composition_precondition($action, $post_id);
        if (is_wp_error($composition_error)) {
            return $composition_error;
        }
    }

    // Semantic validation: action's own checks
    return call_user_func($action['validate'], $params);
}

/**
 * Validates that any URL in action params matching the site's uploads directory
 * corresponds to an actual media library attachment, AND that the attachment
 * is an image (#124 — the AI media inventory only lists images, but nothing
 * stops a proposed action from pointing at a non-image attachment it saw
 * elsewhere, or from hallucinating one that happens to resolve). URLs not
 * matching the uploads pattern are passed through (external URLs are allowed).
 *
 * Checks: props (flat string values), props.items[].image_url/image_alt style,
 * and composition arrays.
 *
 * @return true|WP_Error
 */
function _pp_validate_media_urls_in_params(array $params) {
    $upload_dir  = wp_get_upload_dir();
    $upload_base = is_array($upload_dir) ? (string) ($upload_dir['baseurl'] ?? '') : '';
    $upload_base = rtrim($upload_base, '/');

    $urls = _pp_extract_urls_from_params($params);
    if (empty($urls)) {
        return true;
    }

    foreach ($urls as $url) {
        $error = _pp_validate_single_media_url($url, $upload_base);
        if (is_wp_error($error)) {
            return $error;
        }
    }

    return true;
}

/**
 * Validates a single candidate URL against the media library (#124 image gate,
 * #153 same-site matching + fail-open fix).
 *
 * A URL is classified as "same-site" — a media-library reference in any shape —
 * when it is (a) an absolute URL sharing the uploads baseurl's origin (host+port,
 * any scheme) and sitting under its path, (b) protocol-relative to that origin, or
 * (c) a site-relative uploads path. Same-site URLs are canonicalized to the stored
 * absolute form so attachment_url_to_postid() can resolve non-canonical shapes
 * (relative, protocol-relative, http/https-mismatched). Different-host (CDN/
 * offloaded) URLs are looked up as-is — WordPress core and offload plugins hook
 * attachment_url_to_postid() to unrewrite them.
 *
 *   resolves to an attachment → must be an image, else reject          (#124)
 *   unresolved + same-site    → reject "does not match any file"        (#153)
 *   unresolved + external     → allow (out of scope; validated elsewhere)
 *
 * The image-type check is gated on RESOLUTION, not classification: any URL that
 * maps to an attachment is image-checked regardless of shape or encoding, so a
 * crafted path cannot smuggle a non-image through. Classification only decides how
 * strict we are about UNRESOLVED URLs (the fail-closed direction). An empty/filtered
 * baseurl no longer disables validation (#153 fail-open): a same-site-shaped
 * relative path is still resolved and rejected when it doesn't map.
 *
 * @return true|WP_Error
 */
function _pp_validate_single_media_url(string $url, string $upload_base) {
    $canonical = ($upload_base !== '')
        ? _pp_canonicalize_same_site_url($url, $upload_base)
        : null;

    // The relative-uploads fallback only matters when baseurl is empty (canonical
    // is null there); with a real baseurl, canonicalization already covered it.
    $same_site = ($canonical !== null) || _pp_url_is_relative_uploads_path($url);
    $lookup    = $canonical ?? $url;

    $attachment_id = _pp_resolve_attachment_id_by_url($lookup);

    if ($attachment_id > 0) {
        // Defense in depth: pp_ai_media_inventory() only lists image attachments,
        // but nothing stops the model from pointing at a non-image attachment it
        // saw elsewhere (or hallucinating one that resolves). Reject it (#124).
        if (!wp_attachment_is_image($attachment_id)) {
            return new WP_Error(
                'invalid_media_url',
                sprintf('URL does not point to an image file: %s', basename($url))
            );
        }
        return true;
    }

    if ($same_site) {
        return new WP_Error(
            'invalid_media_url',
            sprintf('Image URL does not match any file in the media library: %s', basename($url))
        );
    }

    // Genuinely external, unresolvable URL — allowed. Narrowing this (e.g. forcing
    // external images through import_media, #105) is a separate product decision.
    return true;
}

/**
 * If $url references the same origin as the uploads baseurl and sits under its
 * path — in any scheme/shape, or as a site-relative uploads path — returns the
 * canonical absolute uploads URL (baseurl's scheme+host[:port] + path, query and
 * fragment dropped to match how _wp_attached_file is stored) so that
 * attachment_url_to_postid() can resolve it. Returns null for a different-origin
 * URL (host or port differs → handled by a raw lookup so offload filters can
 * unrewrite it) or anything not under the uploads path. Scheme is ignored for
 * classification (http/https/protocol-relative all normalize to baseurl's scheme).
 *
 *   /wp-content/uploads/x.jpg                   → https://site/wp-content/uploads/x.jpg
 *   //site/wp-content/uploads/x.jpg             → https://site/wp-content/uploads/x.jpg
 *   http://site/wp-content/uploads/x.jpg        → https://site/wp-content/uploads/x.jpg
 *   https://cdn.other/wp-content/uploads/x.jpg  → null (raw lookup)
 *   /wp-content/uploads-evil/x.jpg              → null (segment-boundary miss)
 */
function _pp_canonicalize_same_site_url(string $url, string $upload_base): ?string {
    $base = parse_url($upload_base);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
        return null;
    }
    $base_scheme = strtolower($base['scheme']);
    $base_authority = _pp_url_authority((string) $base['host'], $base['port'] ?? null);
    $base_origin = $base_scheme . '://' . $base_authority;
    $base_path = isset($base['path']) ? rtrim($base['path'], '/') : '';
    if ($base_path === '') {
        return null;
    }

    $url = trim($url);
    if ($url === '') {
        return null;
    }

    // Site-relative path (/wp-content/uploads/…), but NOT protocol-relative (//host).
    if ($url[0] === '/' && (!isset($url[1]) || $url[1] !== '/')) {
        $path = parse_url($url, PHP_URL_PATH);
        // Decode before the boundary test so an encoded uploads segment
        // (/wp-content/%75ploads/…) can't dodge classification and slip through as
        // "external"; the canonical lookup keeps the original path so it still
        // matches how the attachment URL is stored (#153 codex hardening).
        if (is_string($path) && _pp_path_is_under(rawurldecode($path), $base_path)) {
            return $base_origin . $path;
        }
        return null;
    }

    // Protocol-relative (//host/…): borrow baseurl's scheme so parse_url yields a host.
    if (strncmp($url, '//', 2) === 0) {
        $url = $base_scheme . ':' . $url;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    // Compare origin by authority with standard web ports normalized, so
    // https://site:443/… (or http://site:443/…) is recognized as same-site rather
    // than skipped as external. Scheme is intentionally not part of the authority.
    $authority = _pp_url_authority((string) $parts['host'], $parts['port'] ?? null);
    if ($authority !== $base_authority) {
        return null; // different origin (host or non-default port) — CDN/external, raw lookup
    }
    $path = $parts['path'] ?? '';
    if (!_pp_path_is_under(rawurldecode($path), $base_path)) {
        return null;
    }
    return $base_origin . $path;
}

/**
 * Normalized origin authority: lowercased host with the standard web ports (80 and
 * 443) dropped. http and https on the same host are treated as one origin for media
 * classification (scheme is normalized to the uploads baseurl's), so both default
 * ports collapse to "no port" regardless of scheme — otherwise a same-site uploads
 * URL carrying an explicit :80/:443 would be misclassified as external and skip the
 * image check (#153). A genuinely non-standard port (e.g. :8443) is kept, so it
 * stays a distinct origin.
 *
 * @param int|string|null $port
 */
function _pp_url_authority(string $host, $port): string {
    $host = strtolower($host);
    if ($port === null || $port === '') {
        return $host;
    }
    $port = (int) $port;
    if ($port === 80 || $port === 443) {
        return $host;
    }
    return $host . ':' . $port;
}

/**
 * True when $path is $base_path itself or a descendant at a segment boundary, so
 * "/wp-content/uploads" matches "/wp-content/uploads/x" but NOT
 * "/wp-content/uploads-evil/x". Callers pass a percent-decoded path (a traversal
 * like /wp-content/uploads/../x that survives still resolves to nothing and is
 * rejected, since classification only gates the reject-vs-allow of UNRESOLVED URLs).
 */
function _pp_path_is_under(string $path, string $base_path): bool {
    return $path === $base_path
        || strncmp($path, $base_path . '/', strlen($base_path) + 1) === 0;
}

/**
 * Conventional site-relative uploads path (/wp-content/uploads/…). Used ONLY when
 * the uploads baseurl is empty/filtered (#153 fail-open fix), where the real
 * uploads path cannot be derived — so a same-site-shaped relative path is still
 * treated as validate-able and rejected when it doesn't resolve, rather than
 * silently skipped. (Custom/multisite upload paths under an empty baseurl fall
 * through to "external → allowed", the pre-existing behavior.)
 */
function _pp_url_is_relative_uploads_path(string $url): bool {
    // Decode first so an encoded uploads segment can't dodge the fail-open check.
    return strncmp(rawurldecode(trim($url)), '/wp-content/uploads/', 20) === 0;
}

/**
 * Validates that every component `logo_id` prop in action params references a
 * real Media Library image attachment (#155). Mirrors the traversal of
 * _pp_extract_urls_from_params() exactly — flat props, composition[].props, and
 * items[] — so the two walkers cannot drift over the shapes they cover. An
 * absent or cleared logo_id (empty per PHP `empty()`, matching pp_resolve_logo's
 * `!empty()` gate) passes: it just means "no explicit logo".
 *
 * @return true|WP_Error
 */
function _pp_validate_logo_ids_in_params(array $params) {
    $logo_ids = _pp_extract_logo_ids_from_params($params);
    foreach ($logo_ids as $value) {
        $error = _pp_validate_single_logo_id($value);
        if (is_wp_error($error)) {
            return $error;
        }
    }
    return true;
}

/**
 * Validates a single logo_id value. Strict about shape before casting: a
 * non-empty logo_id must be an int or an all-digits string, so malformed input
 * (arrays, floats, "12abc", negatives) is rejected rather than silently coerced
 * by (int) into a valid-looking ID (#155, codex review).
 *
 * @param mixed $value
 * @return true|WP_Error
 */
function _pp_validate_single_logo_id($value) {
    // Absent/cleared logo — nothing to validate. Mirrors pp_resolve_logo()'s
    // `!empty($props['logo_id'])` gate so '0'/0/''/null are treated as "no logo".
    if (empty($value)) {
        return true;
    }

    if (is_int($value)) {
        $id = $value;
    } elseif (is_string($value) && ctype_digit($value)) {
        $id = (int) $value;
    } else {
        return new WP_Error('invalid_logo_id', sprintf(
            'Component "logo_id" must be a Media Library image attachment ID, got "%s".',
            is_scalar($value) ? (string) $value : gettype($value)
        ));
    }

    if (!pp_is_image_attachment($id)) {
        return new WP_Error('invalid_logo_id', sprintf(
            'Component "logo_id" must reference a Media Library image attachment, got ID %d.',
            $id
        ));
    }

    return true;
}

/**
 * Collects all `logo_id` values from action params. Traverses the same
 * locations as _pp_extract_urls_from_params(): flat props, composition[].props,
 * and items[] — keeping the two extractors' coverage identical.
 */
function _pp_extract_logo_ids_from_params(array $params): array {
    $logo_ids = [];

    // Direct props (flat) — update_component / add_component.
    if (isset($params['props']) && is_array($params['props'])) {
        _pp_collect_logo_ids_from_props($params['props'], $logo_ids);
    }

    // Composition array — update_composition / create_page.
    if (isset($params['composition']) && is_array($params['composition'])) {
        foreach ($params['composition'] as $component) {
            if (isset($component['props']) && is_array($component['props'])) {
                _pp_collect_logo_ids_from_props($component['props'], $logo_ids);
            }
        }
    }

    return $logo_ids;
}

/**
 * Collects logo_id values from a props array, including nested items arrays.
 * Structural mirror of _pp_collect_urls_from_props().
 */
function _pp_collect_logo_ids_from_props(array $props, array &$logo_ids): void {
    if (array_key_exists('logo_id', $props)) {
        $logo_ids[] = $props['logo_id'];
    }
    if (isset($props['items']) && is_array($props['items'])) {
        foreach ($props['items'] as $item) {
            if (is_array($item) && array_key_exists('logo_id', $item)) {
                $logo_ids[] = $item['logo_id'];
            }
        }
    }
}

/**
 * The set of prop names, across every registered component schema, that hold a
 * media-library image URL — i.e. carry `"format": "image_url"` in their schema
 * definition. Derived from the schemas (#154) instead of a hand-maintained
 * `['image_url','background_image']` array, so a new image-bearing prop is
 * media-validated the moment its schema declares the format — no second
 * allowlist in this file to drift out of sync with the component schema.json files.
 *
 * Both top-level props and one level of nested `items[]` item-props are scanned;
 * the returned set is applied at both depths by _pp_collect_urls_from_props(),
 * preserving the historical uniform traversal (the old hardcoded list was also
 * applied identically at the flat and item levels). The registry it walks is
 * statically cached (pp_get_registered_components()), so this stays cheap even
 * though it recomputes per validation call.
 *
 * NOTE ON DEPTH: image props today live at top-level or exactly one `items[]`
 * level; no schema nests them deeper, and the params walker below mirrors that
 * single-level shape. A structural test pins this invariant, so a future schema
 * that nests an image prop deeper fails loudly rather than silently bypassing
 * validation — the same "no silent coverage gap" guarantee #154 adds on the
 * prop-name axis, extended to the nesting-depth axis.
 *
 * @return string[] Unique, sorted image-URL prop names.
 */
function _pp_schema_image_url_props(): array {
    $names = [];
    foreach (pp_get_registered_components() as $schema) {
        if (!is_array($schema) || !isset($schema['props']) || !is_array($schema['props'])) {
            continue;
        }
        foreach ($schema['props'] as $prop_name => $prop_def) {
            if (_pp_prop_def_is_image_url($prop_def)) {
                $names[$prop_name] = true;
            }
            // Nested array-of-object item props live under props.<prop>.items,
            // a map of item-prop-name => item-prop-def (logos/grid/testimonials).
            if (is_array($prop_def) && isset($prop_def['items']) && is_array($prop_def['items'])) {
                foreach ($prop_def['items'] as $item_prop_name => $item_prop_def) {
                    if (_pp_prop_def_is_image_url($item_prop_def)) {
                        $names[$item_prop_name] = true;
                    }
                }
            }
        }
    }
    $names = array_keys($names);
    sort($names);
    return $names;
}

/**
 * True when a schema prop definition declares `"format": "image_url"` — i.e. its
 * value is a media-library image URL subject to the #124/#153 existence +
 * image-type checks.
 *
 * @param mixed $prop_def
 */
function _pp_prop_def_is_image_url($prop_def): bool {
    return is_array($prop_def)
        && isset($prop_def['format'])
        && $prop_def['format'] === 'image_url';
}

/**
 * Extracts all URL-like string values from action params.
 * Walks props, composition arrays, and items arrays.
 */
function _pp_extract_urls_from_params(array $params): array {
    $urls = [];
    // Schema-driven (#154): the image-URL prop set comes from every registered
    // component's `format: image_url` declarations, not a hand-maintained array.
    // logo_id is an attachment ID (no image_url format), so it stays excluded.
    //
    // FAIL-CLOSED FLOOR (#154, orchestrator decision 2026-07-22): the media gate
    // (#124/#153) must never depend on registry availability. If
    // pp_get_registered_components() is transiently empty (missing components/,
    // or a static cache poisoned by a wrong get_template_directory()),
    // _pp_schema_image_url_props() would return [] and _pp_validate_media_urls_in_params()
    // would fail OPEN via its `empty($urls) → true` early-out — the opposite of the
    // maintainer's standing fail-closed rule (#153 precedent). The two historically
    // covered prop names are merged as an un-droppable MINIMUM so coverage can never
    // regress below the pre-#154 baseline. This floor is a safety net, NOT a
    // maintained allowlist: new image props still flow from the schema walk, and
    // _pp_schema_image_url_props() itself stays purely schema-derived so the
    // drift-catcher baseline pin stays honest.
    $url_props = array_values(array_unique(array_merge(
        ['image_url', 'background_image'],
        _pp_schema_image_url_props()
    )));

    // Direct props (flat)
    if (isset($params['props']) && is_array($params['props'])) {
        _pp_collect_urls_from_props($params['props'], $url_props, $urls);
    }

    // Composition array (update_composition / create_page)
    if (isset($params['composition']) && is_array($params['composition'])) {
        foreach ($params['composition'] as $component) {
            if (isset($component['props']) && is_array($component['props'])) {
                _pp_collect_urls_from_props($component['props'], $url_props, $urls);
            }
        }
    }

    return $urls;
}

/**
 * Collects URLs from a props array, including nested items arrays.
 */
function _pp_collect_urls_from_props(array $props, array $url_props, array &$urls): void {
    foreach ($url_props as $prop) {
        if (isset($props[$prop]) && is_string($props[$prop]) && $props[$prop] !== '') {
            $urls[] = $props[$prop];
        }
    }
    // Items arrays (grid, logos)
    if (isset($props['items']) && is_array($props['items'])) {
        foreach ($props['items'] as $item) {
            if (is_array($item)) {
                foreach ($url_props as $prop) {
                    if (isset($item[$prop]) && is_string($item[$prop]) && $item[$prop] !== '') {
                        $urls[] = $item[$prop];
                    }
                }
            }
        }
    }
}

/**
 * Resolves a URL to its media library attachment ID, or 0 if none matches.
 */
function _pp_resolve_attachment_id_by_url(string $url): int {
    // Try attachment_url_to_postid (handles scaled/resized URLs too)
    $attachment_id = attachment_url_to_postid($url);
    if ($attachment_id > 0) {
        return (int) $attachment_id;
    }

    // Fallback: check by guid (handles edge cases where the above misses).
    // No $wpdb (unit test context, mirroring _pp_with_token_lock's check) —
    // treat as no match rather than fatal on a missing global.
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
        return 0;
    }
    // ORDER BY + LIMIT 1: the old COUNT(*)-only query didn't care which row
    // matched, but this one returns a specific ID that feeds
    // wp_attachment_is_image() — a duplicate guid must resolve deterministically.
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s ORDER BY ID ASC LIMIT 1",
        $url
    ));
    return (int) $id;
}

/**
 * Previews an action: validates, computes before/after diff, never writes.
 *
 * @return array|WP_Error
 */
function pp_preview_action(string $name, array $params) {
    $validation = pp_validate_action($name, $params);
    if (is_wp_error($validation)) {
        return $validation;
    }

    $action = pp_get_action($name);
    return call_user_func($action['preview'], $params);
}

/**
 * Builds the canonical ok:false envelope for a validate-stage rejection from a
 * WP_Error already in hand — WITHOUT re-running pp_validate_action. Shared by
 * pp_execute_action() and the WP-CLI `action execute` early-validation gate
 * (lib/cli.php, #385) so both surface the identical envelope shape and neither
 * re-validates: re-validating against mutable DB state (page existence, media
 * attachments, composition precondition) could flip a rejection to a pass and
 * then execute a mutation OUTSIDE the preflight (#96) and freshness/CAS (#113)
 * gates. Emitting from the WP_Error keeps the error path fail-closed and pure.
 *
 * @param string   $name       The action name (may be unregistered).
 * @param WP_Error $validation  The rejection to render.
 * @return array   Canonical ok:false envelope: ['ok', 'action', 'scope', 'target',
 *                 'changes', 'error', 'error_code', 'index'].
 */
function _pp_action_validation_error_envelope(string $name, WP_Error $validation): array {
    $action = pp_get_action($name);
    return [
        'ok'         => false,
        'action'     => $name,
        'scope'      => $action['scope'] ?? 'unknown',
        'target'     => [],
        'changes'    => [],
        'error'      => $validation->get_error_message(),
        // Propagate the WP_Error code so validate-stage rejections carry the same
        // machine-readable error_code as execute-stage rejections built by
        // _pp_action_error() (#13 uniform shape). Without this, clients can only
        // string-match the message for template_owned_component / duplicate_component_id
        // / invalid_composition (missing-required) / unknown_prop (#312).
        'error_code' => $validation->get_error_code(),
        // Which BAND blocked the write (#642). Every composition-mutating action
        // validates the WHOLE composition, so the blocking band is routinely one the
        // caller never named — without this an agent re-submits a payload it already
        // "fixed" and gets the identical string back. Integer composition offset, or
        // null when no single band owns the rejection: a cross-item rule, a param-shape
        // error, a precondition, or a rejection on a band the CALLER named itself
        // (style_component's own validator, index_out_of_bounds — the offset is the
        // caller's own param there, not something it has to be told).
        //
        // THIS FIELD IS THE AUTHORITATIVE LOCATOR. The message names the same band in
        // words and both are rendered from the one offset the validator stamped, so
        // they cannot drift apart — but a message also REFLECTS author-supplied bytes
        // (a component name, a prop key), and an author who writes `Component 9 ("x")`
        // into one of those can put a second band-shaped phrase in the sentence. Read
        // this field, not the prose, when the answer has to be machine-trustworthy.
        // (Bounding what a message reflects is #647/#649's axis, not this field's.)
        'index'      => pp_composition_error_index($validation),
    ];
}

/**
 * Executes an action: validates first, then executes.
 * Returns the canonical result shape.
 *
 * The canonical keys are the MINIMUM every action returns, not an exhaustive list: an
 * action may add its own. restore_composition adds its own `findings` (#233). Read the
 * shape as "at least these keys", and key on what you need rather than the exact key set.
 *
 * @return array  Canonical result: ['ok', 'action', 'scope', 'target', 'changes', 'error',
 *                'error_code'], plus any action-specific keys. A REJECTED envelope also
 *                carries 'index' (#642): the composition offset of the band that blocked
 *                the write, or null when no single band owns the rejection. An ACCEPTED
 *                envelope from a composition-mutating action or create_page additionally
 *                carries 'composition_version' (#404) and 'findings' (#687) — what current
 *                rules say about the composition that was just stored, advisories
 *                (inert_slot) included, bounded at PP_WRITE_FINDINGS_BUDGET and report-only.
 */
function pp_execute_action(string $name, array $params): array {
    $validation = pp_validate_action($name, $params);
    if (is_wp_error($validation)) {
        return _pp_action_validation_error_envelope($name, $validation);
    }

    $action = pp_get_action($name);
    $result = call_user_func($action['execute'], $params);

    // Promote an 'auto-draft' page to a real 'draft' on its first meaningful
    // mutation (#121), here rather than in a per-caller AJAX handler so
    // WP-CLI and pp_patch_composition() are covered too — not just the admin
    // AJAX save handlers. This matters beyond visibility: WordPress's own
    // auto-draft GC (wp_delete_auto_drafts(), ~7 days) PERMANENTLY deletes
    // 'auto-draft' posts regardless of content, so a CLI/agent-driven action
    // that writes real composition data into a still-auto-draft page would
    // otherwise risk that content being hard-deleted before anyone ever
    // opens it in the editor to save normally.
    //
    // update_page_title is excluded when the title is empty — the title
    // field autosaves on blur even with no typed input, and promoting on
    // that no-op recreates the exact "(no title)" permanent-draft bug this
    // fix closes, just via update_page_title instead of post-new.php.
    if (($result['ok'] ?? false) && isset($params['post_id'])) {
        $is_noop_title_save = $name === 'update_page_title' && ($params['title'] ?? '') === '';
        if (!$is_noop_title_save) {
            pp_promote_auto_draft((int) $params['post_id']);
        }
    }

    // Post-write composition version AND composition findings on the success envelope
    // (#404, #687). Both are attached for the same set — composition-mutating actions plus
    // create_page (whose new page needs a baseline to join the CAS map) — but they do NOT
    // resolve the page the same way. See the note below the two candidate ids.
    if (($result['ok'] ?? false)
        && (pp_action_is_composition_mutating($name) || $name === 'create_page')) {
        $param_post_id = (isset($params['post_id']) && is_numeric($params['post_id']))
            ? (int) $params['post_id']
            : null;
        $target_post_id = (isset($result['target']['post_id']) && is_numeric($result['target']['post_id']))
            ? (int) $result['target']['post_id']
            : null;

        // THE TWO KEYS RESOLVE THE PAGE DIFFERENTLY, deliberately, and the difference is
        // only visible on create_page. The seven composition-mutating actions DECLARE
        // post_id as a required param and echo that same id into their target, so both
        // orders agree for them. create_page does not take a post_id at all — the page it
        // wrote is the one it just created, which only its TARGET knows.
        //
        // `composition_version` keeps its original params-first order (#404), untouched.
        // It is wrong on a create_page call carrying a stray, undeclared `post_id`:
        // pp_validate_action() does not reject undeclared params, so the version reported
        // is the OTHER page's. That is a pre-existing #404 defect with its own issue; it is
        // not this change's to alter, and silently flipping it here would move a CAS
        // baseline as a side effect of a findings change.
        $version_post_id = $param_post_id ?? $target_post_id;
        if ($version_post_id !== null) {
            // The chat UI refreshes its per-page CAS baseline from this instead of a
            // second read, and the batch executor chains it into the next mutating step
            // on the same page.
            $result['composition_version'] = pp_get_composition_marker($version_post_id)['version'];
        }

        // `findings` resolves TARGET FIRST, because it must describe the page THIS WRITE
        // LANDED ON or it is worse than absent. Under the params-first order a create_page
        // call with a stray post_id returned a clean new page's envelope carrying the
        // diagnostics of an entirely different page — an envelope naming the wrong page is
        // the exact failure class #687 exists to close, so this key does not inherit it.
        $written_post_id = $target_post_id ?? $param_post_id;
        if ($written_post_id !== null) {
            // ACCEPTED WRITES REPORT WHAT THEY WROTE (#687, D1 clause 4). A composition
            // write could validate, store, return ok:true and paint nothing: the trap that
            // motivated this is `--hero-overlay-bg` on a `split`-layout hero, a slot that
            // renders only under `layout: "cover"`. The #580 inert_slot advisory has always
            // named it, but only on surfaces an agent had to opt into (`wp pp check page`,
            // INSPECT, restore's findings) — so an agent that did not run one of those
            // truthfully reported success on a no-op. Now every accepted composition write
            // carries the same report in the same envelope, on the same command.
            //
            // REPORT-ONLY, and that is the whole contract. Findings never block, never
            // alter, and never re-order an accepted write: this runs AFTER the write has
            // landed, reads what was stored, and only appends a key. Rejections are
            // untouched — they never reach this branch, and the write-rejection path keeps
            // #621's budget of 1. Stored bytes are byte-identical to before this change.
            //
            // OVER THE STORED COMPOSITION, not the array the action assembled. Re-reading
            // is what makes the report describe the page as it now exists (including the
            // props.id pp_update_composition() injects — every composable component's
            // schema declares `id`, pinned by
            // SchemaValidationTest::testEveryComposableComponentDeclaresIdSoInjectedIdNeverFalseRejects,
            // so the round-trip adds no finding of its own). It costs one object-cached
            // meta read plus a decode; the write immediately above primed that cache.
            //
            // AND IT CANNOT TAKE THE WRITE DOWN (D1 Addendum #2). _pp_write_findings_for()
            // gates on the stored size BEFORE invoking either engine, so a composition too
            // large to diagnose within memory_limit yields one findings_skipped entry
            // instead of an OOM fatal on a write that has already landed.
            //
            // ERRORS INCLUDED, not just advisories. The item-scoped actions
            // (style_component, add_component) validate only what they touch, so they
            // legitimately accept a write onto a page whose OTHER bands current rules
            // reject. Reporting only the advisories there would hide the louder problem.
            //
            // NOT AN OVERWRITE, AND NOT A SECOND BOUNDING. restore_composition sets its own
            // `findings` (#233) before returning, and since #654 that report is ALREADY
            // bounded — by the same helper and the same constant, applied once at the
            // action. Leaving an existing key untouched is therefore what keeps the two
            // mechanisms distinct (restore has no size gate; this path does) AND what stops
            // a report from being wrapped twice, which would count the first
            // findings_truncated tail as an ordinary finding and append a second one
            // contradicting it.
            //
            // This is a key test, not a truthiness test: an action that reports a clean
            // composition sets an empty array, and that must not be re-derived.
            if (!array_key_exists('findings', $result)) {
                $result['findings'] = _pp_write_findings_for($written_post_id);
            }
        }
    }

    return $result;
}

// ── Batch execution (issue 137) ─────────────────────────────────────────────

/**
 * Snapshots every post/option a batch's steps could touch, before any step
 * runs. Read-only — never writes anything itself.
 *
 * Covers: any post_id referenced in a step's params (composition, title,
 * slug, status), pp_token_overrides + pp_font_urls when any step is an
 * apply (every apply mutates one of those two options), a step's own
 * update_site_option key, Custom CSS when a step is clear_custom_css, and
 * the full nav-menu state (menus, their items, location assignments) when
 * any step is a menu action (issue 132's create_menu / add_menu_item /
 * assign_menu_location / set_menu — added after this snapshot layer, so
 * without this they'd survive a rollback that claims rolled_back: true).
 *
 * Deliberately does NOT snapshot import_media's new attachment — leaving an
 * uploaded file in the Media Library is additive and non-destructive (unlike
 * overwriting composition/token state), so a later step failing doesn't
 * warrant deleting it.
 *
 * Also classifies each named page's stored composition and records the ones that
 * CANNOT be read under 'unreadable' (#749). Two consumers, and they are opposite
 * ends of the same guarantee: pp_ai_execute_batch() refuses the whole batch when it
 * is non-empty (except for the one carve-out batch, #756), and since that carve-out
 * _pp_restore_batch_snapshot() reads it too — a page listed here has no snapshot worth
 * writing back, so its composition never is.
 *
 * THE LISTING AND THE CAPTURE COME FROM DIFFERENT READS SINCE #833, and the invariant
 * only runs one way now. A page NOT listed here was read clean by both the cached and the
 * authoritative reader, so its captured composition is a real one — that direction is
 * load-bearing and is why the gate keeps its cached check. A page that IS listed may still
 * have a real composition captured beside it: the entry can come from the ROW while the
 * capture came from a healthy cached copy the row has moved past. Neither shape is a
 * rollback baseline, which is why the restorer withholds on the listing rather than on
 * what the captured value looks like.
 *
 * @param  array $steps  Each: ['type' => 'action'|'apply', 'name' => string, 'params' => array]
 * @return array          Snapshot bundle. The state keys go to
 *                         _pp_restore_batch_snapshot(); 'unreadable' goes to
 *                         pp_ai_execute_batch()'s refusal gate AND to
 *                         _pp_restore_batch_snapshot()'s withhold branch.
 */
function _pp_snapshot_batch_targets(array $steps): array {
    $posts      = [];
    $unreadable = [];
    $site_options = [];
    $custom_css = null;
    $token_overrides = null;
    $font_urls = null;
    $menus = null;

    foreach ($steps as $step) {
        $type   = $step['type']   ?? '';
        $name   = $step['name']   ?? '';
        $params = $step['params'] ?? [];

        $post_id = _pp_batch_step_post_id($step);
        if ($post_id !== null) {
            if (!isset($posts[$post_id]) && get_post($post_id)) {
                $post = get_post($post_id);
                // READ THROUGH THE CLASSIFIER, NEVER THE DEGRADING ACCESSOR (#749).
                // pp_get_composition() returns [] for a corrupt row, and
                // _pp_restore_batch_snapshot() writes the snapshot back
                // unconditionally — so snapshotting through it turned a rollback
                // into an eraser: a page that was "corrupt but recoverable" came
                // out of a rolled-back batch genuinely empty, behind a clean
                // rolled_back: true. Same class #241 closed for the run-scoped
                // restore, and the same posture #506 took on the homepage seed.
                // THE CAPTURE IS READABLE EXCEPT ON ONE PATH, and the exception is
                // why the bundle must never be trusted blind. An unreadable target
                // is recorded in $unreadable, which refuses the whole batch before
                // any step runs (pp_ai_execute_batch) for every batch EXCEPT the
                // one-step corrupt-page repair ruling D-1 admits (#756). On that
                // path the value stored below is worth less than it looks: usually
                // it is the degrading accessor's `[]`, a stand-in for bytes nobody
                // could decode, and since #833 it can instead be a composition read
                // from a CACHED copy the database row has already moved past. Both
                // reach _pp_restore_batch_snapshot(), which refuses to write back
                // the composition of any page named in $unreadable — so neither is
                // ever written anywhere. Capture honestly, record what the capture
                // is worth, and let the restorer decide.
                //
                // ONE READ DECIDES THE CAPTURE AND HALF THE VERDICT, deliberately.
                // Reading the stored value in a separate pass from classifying it
                // would leave a window — however small — in which the row flips
                // corrupt between "is it readable?" and "capture it", and the
                // capture would then be a degraded `[]` that the map says is fine:
                // the original bug, rebuilt out of two honest reads. The value
                // stored below and the CACHED half of the verdict beside it come
                // from the same pp_get_composition_result() call.
                //
                // THE OTHER HALF IS THE ROW (#833), and the capture stays CACHED on
                // purpose. Since #833 a target is only "readable" when the database
                // row classifies clean too — the gate fails closed rather than trust
                // a cached copy a concurrent write may have made stale. What is NOT
                // read from the row is the captured VALUE: the rollback baseline has
                // to be the state this batch's own steps execute against, and every
                // step reads through the cache. Because a healthy verdict still
                // requires the cached read to be healthy, the captured value can
                // never be the degrading accessor's `[]` while the map says fine.
                $stored = pp_get_composition_result($post_id);
                $reason = _pp_batch_target_refusal_reason($post_id, $stored);
                if ($reason !== null) {
                    $unreadable[$post_id] = $reason;
                }
                $posts[$post_id] = [
                    'title'       => $post->post_title,
                    'slug'        => $post->post_name,
                    'status'      => $post->post_status,
                    'composition' => $stored['composition'],
                    'seo_meta'    => pp_get_seo_meta($post_id),
                ];
            }
        }

        if ($type === 'apply' && $token_overrides === null) {
            $token_overrides = pp_get_token_overrides();
        }
        if ($type === 'apply' && $font_urls === null) {
            $font_urls = pp_get_font_urls();
        }

        if ($name === 'update_site_option' && isset($params['key'])) {
            $key = (string) $params['key'];
            if (!array_key_exists($key, $site_options)) {
                // Capture PRESENCE and VALUE separately (#291): an option that was
                // absent (no DB row) and one that held an explicit '' both used to
                // collapse to the same captured '' (pp_site_option => (string)
                // get_option($key, '')), so a rollback could not tell "delete the row"
                // from "write ''". The per-key ['exists' => bool, 'value' => string]
                // shape keeps them distinct for _pp_restore_batch_snapshot().
                //
                // Capture stays WHITELIST-SCOPED, exactly as pp_site_option() gated it:
                // only OUR options are ever read into the snapshot. A non-whitelisted
                // key can still appear here (the snapshotter records every
                // update_site_option step's key up front, before execute rejects an
                // unauthorized one), so it is recorded as absent-shaped — never read.
                // This preserves the read boundary (we never pull an unrelated core
                // option, e.g. a secret, into the bundle) and avoids (string)-casting
                // a non-scalar core option like active_plugins (an array). Every
                // whitelisted option stores a scalar string, so (string) is safe here.
                if (isset(pp_allowed_site_options()[$key])) {
                    // Object sentinel: get_option() returns it verbatim only when the
                    // row is absent, so identity inequality is a reliable presence test
                    // (no real stored value can equal a fresh object).
                    $absent = new \stdClass();
                    $raw    = get_option($key, $absent);
                    $exists = $raw !== $absent;
                    $site_options[$key] = [
                        'exists' => $exists,
                        'value'  => $exists ? (string) $raw : '',
                    ];
                } else {
                    $site_options[$key] = ['exists' => false, 'value' => ''];
                }
            }
        }

        if ($name === 'clear_custom_css' && $custom_css === null) {
            $custom_css = wp_get_custom_css();
        }

        if ($menus === null && _pp_is_menu_action($name)) {
            $menus = _pp_snapshot_menu_state();
        }
    }

    return [
        'posts'           => $posts,
        'created_posts'   => [], // filled in as create_page steps succeed
        // {post_id => reason} from _pp_batch_target_refusal_reason(), for every named
        // page that blocks the batch (#749): a classification ('unexpected_shape' /
        // 'decode_error') when the stored composition could not be read, or
        // PP_BATCH_TARGET_UNVERIFIABLE when the row could not be reached to check (#833,
        // NOT a statement about the page). Non-empty means the batch is refused before
        // step 1; see pp_ai_execute_batch().
        'unreadable'      => $unreadable,
        'site_options'    => $site_options,
        'custom_css'      => $custom_css,
        'token_overrides' => $token_overrides,
        'font_urls'       => $font_urls,
        'menus'           => $menus,
    ];
}

/**
 * The page one batch step names, or null when it names none usably (#749/#756).
 *
 * THE SINGLE OWNER OF ONE COERCION, and it is a gate-integrity concern rather than a
 * tidiness one. Three callers ask "which page is this step about?" and they must get the
 * same answer for the same step: the SNAPSHOTTER decides which page gets captured (and so
 * which page a rollback could write over), the DETECTOR decides which pages the #749
 * refusal is about, and the ADMISSION (#756) decides which page is exempt from that
 * refusal. A step the admission resolves to page A while the detector resolves it to page
 * B is a batch where the gate opens on a page nobody classified.
 *
 * That identity used to be asserted in three docblocks instead of being a fact about the
 * code. A comment claiming byte-identity is the weakest available enforcement of
 * byte-identity — nothing fails when only two of three copies are updated — and #756
 * raised the stake from "two detectors disagree" to "a gate opens", so the convention
 * became a function.
 *
 * IT DELIBERATELY DOES NOT CHECK THAT THE POST EXISTS. That question belongs to the two
 * callers that ask it, and they ask it for different reasons (the snapshotter will not
 * capture a page it cannot load; the detector will not report one). Folding it in here
 * would silently give the admission a third condition nobody wrote down.
 *
 * `is_numeric` rather than `is_int`, because the executor is public and takes params that
 * never passed through pp_ai_coerce_params() — a caller handing it the string "42" means
 * post 42, and every one of these three callers must agree that it does.
 *
 * @param  array $step  ['type' => ..., 'name' => ..., 'params' => array]
 * @return int|null     The step's target post ID, or null when it carries no usable one.
 */
function _pp_batch_step_post_id(array $step): ?int {
    $params = $step['params'] ?? [];
    if (!isset($params['post_id']) || !is_numeric($params['post_id'])) {
        return null;
    }
    return (int) $params['post_id'];
}

/**
 * The reason a batch target BLOCKS the batch, and it is not always a classification (#833).
 *
 * Two of the three values this returns are stored-value classifications — `unexpected_shape`
 * / `decode_error`, the same vocabulary every other surface prints. The third is not: it says
 * the read itself could not be trusted. Keep them distinguishable at every consumer; a
 * "we could not check" rendered as "your page is corrupt" sends an operator to repair bytes
 * that were never shown to be broken.
 */
const PP_BATCH_TARGET_UNVERIFIABLE = 'composition_unverifiable';

/**
 * Why this batch target blocks the batch, or null when it may proceed (#833).
 *
 * THE SINGLE OWNER OF THE #749 GATE'S CLASSIFICATION SOURCE, and it exists because the
 * three sites that ask the question — the chat entry point's detector, the snapshotter's
 * capture, and the rollback's live re-classify — used to ask it of the OBJECT CACHE while
 * the carve-out beside them read the database row. That split is what #833 records: a
 * request that warms `_pp_composition` and then loses a race to a concurrent write holds a
 * healthy CACHED copy over a corrupt ROW, and every cached classifier reported "readable"
 * for a page whose stored bytes nobody could decode. The refusal never fired, the rollback
 * baseline was captured from the stale copy, and a later step's failure wrote it back.
 *
 * MAINTAINER RULING (#833, 2026-08-27): the refusal is a GATE, and gates classify through
 * the AUTHORITATIVE read, failing CLOSED when no authoritative read is possible — the
 * asymmetry #767 recorded on pp_composition_db_handle() (degrading is right for a reader,
 * refusing is right for a gate). The carve-out already reads the row; this is the other half
 * of the same gate learning to read it too.
 *
 * THE CACHED CHECK STAYS, AND IT IS LOAD-BEARING RATHER THAN LEFTOVER. What this function
 * adds is a REQUIREMENT that the row read clean; it removes nothing. The refusal set is a
 * strict superset of what it was, which matters for two reasons:
 *
 *   1. #756 pins the cache-corrupt/row-healthy direction as a refusal, and that pin is
 *      about the batch, not only about the carve-out. Dropping it would be a behaviour
 *      change the ruling did not ask for.
 *   2. It is what keeps _pp_snapshot_batch_targets()'s stored-value invariant true. That
 *      function captures the composition from the CACHED read (unchanged here — the
 *      rollback baseline must stay the state the batch's own steps execute against, and
 *      every step reads through the cache). If a healthy verdict could be reached while
 *      the cached read was corrupt, the captured value would be the degrading accessor's
 *      `[]` under a map that says "fine", and the rollback would write that `[]` over a
 *      readable row. That is #749's data loss, rebuilt out of a mixed-source gate.
 *
 * FAIL CLOSED WHENEVER THE ROW CANNOT BE READ, which is two cases and not one. With no
 * usable $wpdb (pp_composition_db_handle) there is nothing to ask — a unit-context and
 * exotic-host path, since production WordPress always has a handle. With a handle whose
 * QUERY failed there is an answer that cannot be trusted, and that case is reachable in
 * production under exactly the load that produces the race this gate exists for. Both
 * block, and neither is spelled as corruption: PP_BATCH_TARGET_UNVERIFIABLE says the read
 * failed, not the page.
 *
 * ONE CACHED READ, PASSED IN. The caller supplies the cached result it already holds
 * instead of this re-reading it, so the snapshotter's verdict and the value it stores still
 * come from the SAME call. Splitting them would reopen the window that function's own
 * comment describes: a row flipping corrupt between "is it readable?" and "capture it".
 *
 * ORDER IS DELIBERATE: the cached verdict first, so a target that already refused today
 * refuses with exactly the code it did before, and the extra row read is spent only on the
 * common path where the cache says healthy — one indexed point-SELECT per named page per
 * gate evaluation.
 *
 * DO NOT MEMOIZE THE ROW READ, and the temptation is real because the gate is evaluated
 * twice per chat proposal. The only reason this stopped trusting pp_get_composition_result()
 * is that a request-scoped copy warmed early goes stale under a concurrent write; a
 * request-scoped cache of this answer would rebuild that hole with a different cache. The
 * per-post dedup the callers already do WITHIN one evaluation is the safe kind and the only
 * kind. What is being spent is a handful of indexed point reads per proposal, in a request
 * that already paid for a model round trip.
 *
 * @param  int   $post_id  The named page.
 * @param  array $cached   The caller's own pp_get_composition_result() result for it.
 * @return string|null     'unexpected_shape' | 'decode_error' | PP_BATCH_TARGET_UNVERIFIABLE,
 *                          or null when the target may proceed.
 */
function _pp_batch_target_refusal_reason(int $post_id, array $cached): ?string {
    // READ THE ENVELOPE DEFENSIVELY, because this is a gate and the alternative default is
    // "proceed". Every caller passes pp_get_composition_result()'s own return, which always
    // carries both keys — but a shape this function cannot read is a state it cannot
    // classify, and a missing `error` beside a false `ok` must not fall out of the bottom as
    // null (which every caller reads as "this target may proceed").
    if (empty($cached['ok'])) {
        $cached_reason = $cached['error'] ?? null;
        return is_string($cached_reason) && $cached_reason !== ''
            ? $cached_reason
            : PP_BATCH_TARGET_UNVERIFIABLE;
    }

    $handle = pp_composition_db_handle();
    if ($handle === null) {
        return PP_BATCH_TARGET_UNVERIFIABLE;
    }

    $row = pp_get_composition_result_authoritative($post_id);

    // A FAILED READ IS NOT A BLANK PAGE (#212 posture, applied at this gate). $wpdb->get_var()
    // returns null for "no row" AND for a query that errored — a killed query, a lock-wait
    // timeout, a gone-away connection — and the authoritative reader maps null to '', which
    // classifies as a genuinely blank page: ok, proceed. That is the right degradation for a
    // READER and a fail-OPEN here, reachable under exactly the contention that produces the
    // race this gate exists for. `last_error` is flushed at the start of every query, so a
    // non-empty one belongs to the read just issued. Same disambiguation the three sibling
    // authoritative readers make (_pp_read_composition_history_locked,
    // _pp_read_composition_hash_locked, _pp_read_token_overrides_locked_strict), and the
    // reason it is made HERE rather than
    // inside the shared reader: the reader's other consumers want a value, and this one wants
    // a verdict — an unanswerable question is a block, not a blank page.
    if (!empty($handle->last_error)) {
        return PP_BATCH_TARGET_UNVERIFIABLE;
    }

    if (!$row['ok']) {
        return $row['error'];
    }

    return null;
}

/**
 * The shared first sentence for a blocked batch target (#833).
 *
 * pp_composition_integrity_message() is the one owner of "this page's stored composition is
 * corrupt", and it says so in those words — "treat as corrupted, not empty". True for the
 * two classifications, and a lie for PP_BATCH_TARGET_UNVERIFIABLE, which is a fact about
 * the READ. This is the fork, single-owned so the three surfaces that render a blocked
 * target (the two refusals and the rollback's withhold notice) cannot spell it three ways.
 *
 * A FOURTH CLASSIFICATION LANDS IN THE `else` BY DEFAULT, and that is stated rather than
 * guarded so the next person meets the decision instead of inheriting it: anything
 * pp_classify_composition_value() starts returning arrives here and is narrated as
 * "treat as corrupted, not empty", with a whole-composition repair prescribed beside it on
 * the refusal surface. That is right for a classification about STORED BYTES and wrong for
 * anything else, so adding one is a deliberate act that has to revisit this fork.
 *
 * @param  int    $post_id  The blocked page.
 * @param  string $reason   From _pp_batch_target_refusal_reason().
 * @return string
 */
function _pp_batch_target_state_message(int $post_id, string $reason): string {
    if ($reason === PP_BATCH_TARGET_UNVERIFIABLE) {
        return "Page {$post_id}: this page's stored composition could not be VERIFIED"
            . ' (no authoritative database read was available), so it is treated as'
            . ' unreadable. This is a statement about the read, not a finding about the'
            . ' page — nothing here says the stored composition is corrupt.';
    }

    return pp_composition_integrity_message($post_id, $reason);
}

/**
 * The post targets of a batch whose stored composition CANNOT be read (#749).
 *
 * For callers that need the verdict WITHOUT building a snapshot bundle — today
 * exactly one, the chat entry point (lib/ai-chat.php), which refuses before it
 * ever reaches the executor. _pp_snapshot_batch_targets() does NOT call this: it
 * classifies from its own capture read so the stored value and the verdict come
 * from one read (see the comment there). Both derive the answer from
 * _pp_batch_target_refusal_reason(), the single owner of the gate's classification
 * SOURCE since #833 — authoritative, failing closed — and both render it through
 * _pp_batch_unreadable_target_error(), the single wording owner, so the two
 * refusals cannot spell one state two ways.
 *
 * Read-only. The post gate is character-for-character the snapshotter's own —
 * any step carrying a top-level numeric post_id for a post that exists — and
 * that identity is the load-bearing property, not the breadth: it makes this set
 * exactly the set the snapshotter captures a composition for, and therefore
 * exactly the set a rollback could write over. Widen or narrow one and you must
 * do the same to the other. Deliberately NOT narrowed to composition-mutating
 * steps: `mutates_composition` is a capability predicate, not a snapshot-
 * completeness one, and repurposing it here would silently drop the rollback
 * baseline for any writer it does not list. The cost of the wider gate is
 * disclosed and accepted: a batch that merely NAMES a corrupt page (publish_page,
 * update_page_title) is refused too.
 *
 * WHERE THE REPAIR ACTUALLY WORKS, stated precisely because the refusal message
 * points at it: the SINGLE-step execute path takes no rollback snapshot, so this
 * gate never refuses it (it can still fail on its own terms — validation, the
 * page lock, capabilities). Since #767 that path is exactly TWO surfaces, and the
 * list is shorter than this paragraph used to claim: `wp pp action execute
 * update_composition` / `restore_composition`, which ruling D-1 admits without a
 * covering preflight on this classification, and the dashboard editor.
 * pp_patch_composition() / `wp pp operate patch` reaches the same executor but is
 * NOT a repair route — it is refused on a corrupt page by the composition_required
 * precondition (#748), and a field selector cannot reshape a container anyway.
 *
 * AND SINCE #756 THE CHAT IS A REPAIR ROUTE TOO, which is a change to what this
 * paragraph used to say rather than an addition to it. The chat client routes every
 * proposal, one step or many, through the batch endpoint, so the repair this refusal
 * prescribes used to come back refused by the very code it was meant to clear. Ruling
 * D-1's carve-out admits it: a proposal of EXACTLY ONE update_composition /
 * restore_composition step on a page already classified corrupt proceeds, with the
 * step's own validation untouched. It is admitted at the refusal, not at the
 * snapshot — this detector still reports the page, and the rollback still refuses to
 * write that report's page back. See _pp_batch_corrupt_repair_admitted().
 *
 * Insertion order is STEP order, and the refusal reports the first entry — so
 * which page a multi-corrupt batch names is deterministic, not incidental.
 *
 * @param  array $steps  Each: ['type' => ..., 'name' => ..., 'params' => array]
 * @return array         {post_id => reason}, each reason from
 *                        _pp_batch_target_refusal_reason(); [] when every target may proceed.
 */
function _pp_batch_unreadable_targets(array $steps): array {
    $unreadable = [];
    $seen       = [];

    foreach ($steps as $step) {
        $post_id = _pp_batch_step_post_id($step);
        if ($post_id === null) {
            continue;
        }
        if (isset($seen[$post_id])) {
            continue;
        }
        $seen[$post_id] = true;
        if (!get_post($post_id)) {
            continue; // never snapshotted, so never restored over
        }
        $reason = _pp_batch_target_refusal_reason($post_id, pp_get_composition_result($post_id));
        if ($reason !== null) {
            $unreadable[$post_id] = $reason;
        }
    }

    return $unreadable;
}

/**
 * Renders the refusal an unreadable batch target earns (#749), or null when there is none.
 *
 * Single owner of the refusal's wording AND its code, for the same reason
 * _pp_batch_unreadable_targets() is the single owner of the detection: the
 * executor and the chat entry point both refuse, and two hand-rolled messages
 * would be two spellings of one state.
 *
 * WORDING ONLY — THIS IS NOT THE GATE, and since #756 the distinction matters.
 * "Does this batch refuse?" is _pp_batch_unreadable_refusal()'s question, and
 * that is what both surfaces call; this renders the answer once the decision is
 * made. Calling this directly as a gate skips the corrupt-page repair carve-out
 * and would refuse a batch the executor admits — the exact drift single ownership
 * exists to prevent, one layer in. Tests may call it to assert wording; nothing
 * else should call it at all.
 *
 * The CODE is usually the classification itself — `unexpected_shape` /
 * `decode_error` — matching pp_inspect_composition() (#725), `operate inspect`,
 * `check page`, `validate site` and the write path (#724). Since #833 there is one
 * more, PP_BATCH_TARGET_UNVERIFIABLE, and it is NOT a classification: it says the
 * gate could not reach the row to check. It earns its own branch below rather than
 * a shared sentence, because every word of the corrupt-page advice is wrong for a
 * page that was never shown to be corrupt. The MESSAGE is the shared state sentence
 * (_pp_batch_target_state_message) plus this surface's own next action; the
 * diagnosis is single-owned so a new spelling of one state cannot appear here, and
 * only the tail is local because only it is specific to "your multi-step proposal
 * was refused".
 *
 * @param  array $unreadable  {post_id => reason} from _pp_batch_unreadable_targets().
 * @return array|null         ['error' => string, 'error_code' => string], or null when readable.
 */
function _pp_batch_unreadable_target_error(array $unreadable): ?array {
    // Head of the map, said as head-of-map. The keys are in step order, so "first"
    // is the page the operator's own proposal named first — deterministic, which is
    // the property the docblock above is claiming.
    $post_id = array_key_first($unreadable);
    if ($post_id === null) {
        return null;
    }
    $error = $unreadable[$post_id];

    // THE UNVERIFIABLE REASON GETS ITS OWN TAIL (#833), because the shared repair route
    // below is advice for a page whose bytes are known to be broken. Here nothing is known
    // to be broken: the gate could not reach the database to check. Prescribing a
    // whole-composition rewrite would tell an operator to overwrite a page that may be
    // perfectly healthy — the opposite of what a fail-closed refusal is for.
    if ($error === PP_BATCH_TARGET_UNVERIFIABLE) {
        return [
            'error'      => _pp_batch_target_state_message($post_id, $error)
                . ' This proposal was refused before any step ran, so nothing was changed:'
                . ' a batch is rolled back by writing its snapshot over the stored'
                . ' composition, and a snapshot taken from a state nobody could confirm is'
                . ' not safe to write anywhere. Try again; if it keeps happening, the site'
                . ' cannot reach its database the way this gate needs to, which is a site'
                . ' problem rather than a problem with this page or this proposal.',
            'error_code' => $error,
        ];
    }

    return [
        'error'      => _pp_batch_target_state_message($post_id, $error)
            . ' This proposal was refused before any step ran, so nothing was changed:'
            . ' rolling it back would have to write over those bytes. Repair the page'
            . ' FIRST, in a proposal of its OWN — a single step, nothing alongside it'
            . ' (#756) — or from a surface that takes no rollback snapshot. '
            . pp_corrupt_repair_route_message($post_id)
            . ' Then run this proposal again.',
        'error_code' => $error,
    ];
}

/**
 * The page a batch is allowed to repair despite the #749 refusal, or null (#756).
 *
 * THE DEADLOCK THIS BREAKS. #749's refusal message prescribes the repair — "one full
 * `update_composition`, or `restore_composition`" — and on the chat surface that
 * instruction could not be followed. The chat client posts EVERY proposal, one step or
 * many, to the batch endpoint (executeProposal, assets/js/pp-ai-chat.js), so the
 * prescribed repair carried the same post_id into the same gate and came back refused
 * with the very code it was meant to clear. The operator was told to do X, did X, and
 * was told again to do X.
 *
 * Maintainer ruling D-1 (#767) approved the narrow carve-out that closes it, and the
 * ruling's three conditions are quoted in full on pp_corrupt_page_repair_carve_out()
 * (lib/operate.php), the SHARED predicate this delegates to. Conditions (1) corrupt
 * classification and (2) the two whole-composition verbs are that predicate's; sharing
 * it with the CLI's admission is the only way the two surfaces cannot drift on which
 * pages are repairable. Condition (3) — full validation of the incoming replacement —
 * is untouched here by construction: this function gates a PREFLIGHT, and every step
 * still runs through pp_execute_action()/pp_validate_action() exactly as before. An
 * admission moves nothing about what a verb accepts.
 *
 * SINGLE-STEP ONLY, and the alternative was measured rather than reasoned about. A
 * first-step exemption — "let step 1 repair the page, then carry on" — reopens #749's
 * data loss by a longer road: the snapshot of a corrupt page is the degraded `[]`
 * stand-in, so once step 1 REPAIRS the page a later step's failure rolls back over a
 * page that now reads fine, and _pp_restore_batch_snapshot() writes that `[]` straight
 * over the operator's repair. #818 did not close this: it preserves the corrupt bytes
 * on the history ring, which is a different rescue — the repair is still erased, and
 * the page afterwards reads ok/empty rather than corrupt, so no surface flags it at all.
 *
 * With exactly one step there is no such path. A succeeded single step means the batch
 * SUCCEEDED, and pp_ai_execute_batch() rolls back only on a failure; a FAILED single
 * step wrote nothing, so the page is still corrupt when the rollback re-classifies it
 * and the composition write is withheld. The window that does survive at one step is a
 * concurrent repair by another writer, and that one is closed in the rollback rather
 * than here — see _pp_restore_batch_snapshot(), which never writes back a composition
 * captured from an already-unreadable page.
 *
 * IT KNOWS NOTHING ABOUT THE REQUEST, inheriting that boundary from the shared
 * predicate: capabilities, nonces and the #404 baseline mandate are the chat entry
 * point's (lib/ai-chat.php), and they all still run. An admission here lifts the #749
 * preflight and nothing else.
 *
 * THE post_id COERCION IS THE DETECTOR'S AND THE SNAPSHOTTER'S, because all three now
 * call _pp_batch_step_post_id(). That identity is load-bearing rather than stylistic: the
 * detector decides which page the refusal is ABOUT, this decides which page is exempt,
 * and a batch where the two disagree is one where the gate opens on a page nobody
 * classified. It is a shared function rather than three matching copies for exactly that
 * reason — see the helper's own docblock.
 *
 * @param  array $steps  Each: ['type' => ..., 'name' => ..., 'params' => array]
 * @return int|null      The post the carve-out exempts, or null when it does not apply.
 */
function _pp_batch_corrupt_repair_admitted(array $steps): ?int {
    if (count($steps) !== 1) {
        return null; // the whole point: a repair travels alone or not at all
    }

    $step = $steps[array_key_first($steps)];
    if (!is_array($step)) {
        return null; // a step that is not a step names nothing
    }

    // ACTIONS ONLY, checked explicitly rather than by `!== 'apply'`. The executor's
    // dispatcher treats every non-'apply' type as an action, which is the right posture
    // for a WRITE path (a misspelled type must not skip a guard) and the wrong one for a
    // GATE: an exemption is not something a malformed step should be able to fall into.
    if (($step['type'] ?? '') !== 'action') {
        return null;
    }

    $post_id = _pp_batch_step_post_id($step);
    if ($post_id === null) {
        return null;
    }

    // is_string BEFORE the lookup, not a cast. `name` is attacker-shaped JSON, and
    // casting an array to string is a PHP warning plus the literal "Array" — a
    // diagnostic-noise path into a gate, for a value that could never have named an
    // action anyway. pp_get_action() wants a string; anything else is simply not a name.
    $name = $step['name'] ?? null;
    if (!is_string($name)) {
        return null;
    }

    $action = pp_get_action($name);
    if ($action === null) {
        return null; // unregistered name: pp_execute_action would reject it anyway
    }

    // Conditions (1) and (2), from the shared owner. It fails closed with no usable
    // $wpdb handle, so a context that cannot read the classification authoritatively
    // never opens the gate.
    if (pp_corrupt_page_repair_carve_out($action, $post_id) === null) {
        return null;
    }

    return $post_id;
}

/**
 * The refusal a batch earns under #749, honouring the #756 carve-out — or null (#756).
 *
 * THE SINGLE OWNER OF "DOES THIS BATCH REFUSE", and it exists because there are two
 * gates. The executor (pp_ai_execute_batch) and the chat entry point
 * (_pp_ai_execute_batch_response) each refuse on their own, for reasons documented at
 * both sites, and #749 already single-owned the DETECTION and the WORDING so the two
 * could not spell one state two ways. The carve-out adds a third thing they must agree
 * on — which batches are EXEMPT — and a surface that admitted a repair the other
 * refused would be the deadlock again, one layer down.
 *
 * THE EXEMPT SET MUST BE EXACTLY THE ADMITTED PAGE. With one step
 * _pp_batch_unreadable_targets() can only ever report that step's own post_id, so
 * today this is belt and braces; it is written anyway because the alternative is a gate
 * that would silently widen if either detector ever learned to read a second post_id
 * out of one step (a nested target, a param alias). A carve-out that grows when
 * somebody else extends an unrelated parser is not a narrow carve-out.
 *
 * @param  array $steps       The batch's steps, for the carve-out test.
 * @param  array $unreadable  {post_id => reason} from the caller's detector, each reason
 *                             from _pp_batch_target_refusal_reason().
 * @return array|null         ['error' => string, 'error_code' => string], or null when
 *                             the batch may proceed.
 */
function _pp_batch_unreadable_refusal(array $steps, array $unreadable): ?array {
    $admitted = _pp_batch_corrupt_repair_admitted($steps);
    if ($admitted !== null && array_keys($unreadable) === [$admitted]) {
        return null;
    }

    return _pp_batch_unreadable_target_error($unreadable);
}

/**
 * True when the named action mutates nav-menu state. The single source of
 * truth for "is this a menu action" — the batch snapshot gate and the
 * capability resolver (lib/ai-chat.php) both use it, so a future menu action
 * added to one can't silently miss the other.
 */
function _pp_is_menu_action(string $name): bool {
    return in_array($name, ['create_menu', 'add_menu_item', 'assign_menu_location', 'set_menu'], true);
}

/**
 * Captures nav-menu state: every menu (term id + name — the name is
 * diagnostic only; no action renames menus, so restore never writes it),
 * its raw items as returned by wp_get_nav_menu_items() (publish-status
 * items only, consistent with every other consumer in this layer), and the
 * theme's location assignments. Read-only.
 *
 * @return array ['menus' => [term_id => ['name' => string, 'items' => object[]]],
 *                'locations' => array]
 */
function _pp_snapshot_menu_state(): array {
    $state = [
        'menus'     => [],
        'locations' => get_theme_mod('nav_menu_locations', []),
    ];
    foreach (wp_get_nav_menus() as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        $state['menus'][(int) $menu->term_id] = [
            'name'  => $menu->name,
            'items' => is_array($items) ? $items : [],
        ];
    }
    return $state;
}

/**
 * Restores nav-menu state captured by _pp_snapshot_menu_state(): deletes any
 * menu created since the snapshot, rebuilds the item list of every menu that
 * existed (pre-existing menus keep their term ids, so restored location
 * assignments stay valid), and restores the location map.
 *
 * @param array $state  Bundle from _pp_snapshot_menu_state().
 * @return string[]     Human-readable descriptions of anything that could
 *                       NOT be restored — empty when the restore was clean.
 */
function _pp_restore_menu_state(array $state): array {
    $errors = [];

    $menus = wp_get_nav_menus();
    if (!is_array($menus)) {
        // get_terms() can return WP_Error — never fatal inside the rollback
        // path; report instead of aborting the rest of the restore half-done.
        return ['menu list unavailable during rollback (wp_get_nav_menus failed)'];
    }

    foreach ($menus as $menu) {
        $menu_id = (int) $menu->term_id;

        if (!isset($state['menus'][$menu_id])) {
            if (!wp_delete_nav_menu($menu_id)) { // created during the failed batch
                $errors[] = sprintf('could not delete menu %d ("%s") created during the batch', $menu_id, (string) $menu->name);
            }
            continue;
        }

        // A rebuild rewrites every item's post id, so never touch a menu the
        // batch didn't change — compare current items to the snapshot and
        // skip when identical.
        $snapshot_items = array_values($state['menus'][$menu_id]['items']);
        $current_items  = wp_get_nav_menu_items($menu_id);
        $current_items  = is_array($current_items) ? array_values($current_items) : [];
        if (_pp_menu_items_signature($current_items) === _pp_menu_items_signature($snapshot_items)) {
            continue;
        }

        pp_clear_nav_menu_items($menu_id);
        foreach (_pp_rebuild_menu_items($menu_id, $snapshot_items) as $rebuild_error) {
            $errors[] = sprintf('menu %d ("%s"): %s', $menu_id, (string) $menu->name, $rebuild_error);
        }
    }

    set_theme_mod('nav_menu_locations', $state['locations']);

    return $errors;
}

/**
 * Normalized fingerprint of a menu's item list. ID and menu_item_parent are
 * included as touched-at-all detectors (any mutation churns item ids), not
 * as fields the restore preserves; the rest are the fields
 * _pp_recreate_menu_item() carries. Equal signatures mean the batch never
 * touched the menu, so restoring over it would only churn item ids.
 */
function _pp_menu_items_signature(array $items): string {
    $sig = array_map(function (object $item): array {
        return [
            (int) ($item->ID ?? 0),
            (int) ($item->menu_item_parent ?? 0),
            (string) ($item->post_title ?? ($item->title ?? '')),
            (string) ($item->type ?? ''),
            (string) ($item->object ?? ''),
            (int) ($item->object_id ?? 0),
            (string) ($item->url ?? ''),
            (int) ($item->menu_order ?? 0),
            (string) ($item->target ?? ''),
            implode(' ', (array) ($item->classes ?? [])),
            (string) ($item->xfn ?? ''),
            (string) ($item->attr_title ?? ''),
            (string) ($item->description ?? ''),
        ];
    }, array_values($items));
    // serialize(), not json_encode(): json_encode returns false on invalid
    // UTF-8 (possible in legacy DB titles), which would make BOTH sides ''
    // and false-equal — skipping the restore of a menu the batch really
    // mutated. serialize never fails, so the gate fails closed.
    return serialize($sig);
}

/**
 * Recreates a snapshotted item list on a menu, parents-first: each pass
 * creates every item whose parent is top-level, already recreated, or absent
 * from the snapshot (dangling — restored as top-level). A pass with no
 * progress means a parent cycle, which real menus can't have — flush the
 * remainder as top-level rather than loop forever. Shared by the batch
 * rollback and set_menu's own mid-loop failure restore.
 *
 * @param object[] $items  Raw items as returned by wp_get_nav_menu_items().
 * @return string[]         One entry per item that could NOT be recreated —
 *                           a rollback consumer must surface these, never
 *                           report a clean restore over them.
 */
function _pp_rebuild_menu_items(int $menu_id, array $items): array {
    $errors  = [];
    $pending = array_values($items);
    $id_map  = []; // old item id => new item id
    while ($pending) {
        $next     = [];
        $progress = false;
        foreach ($pending as $item) {
            $old_parent = (int) ($item->menu_item_parent ?? 0);
            if ($old_parent !== 0 && !isset($id_map[$old_parent])
                && _pp_menu_item_in_list($old_parent, $pending)) {
                $next[] = $item; // parent not recreated yet — next pass
                continue;
            }
            $new_id = _pp_recreate_menu_item($menu_id, $item, $id_map[$old_parent] ?? 0);
            if ($new_id !== null) {
                $id_map[(int) ($item->ID ?? 0)] = $new_id;
            } else {
                $errors[] = sprintf('could not recreate menu item "%s"', (string) ($item->title ?? ($item->ID ?? '?')));
            }
            $progress = true;
        }
        if (!$progress) {
            foreach ($next as $item) {
                if (_pp_recreate_menu_item($menu_id, $item, 0) === null) {
                    $errors[] = sprintf('could not recreate menu item "%s"', (string) ($item->title ?? ($item->ID ?? '?')));
                }
            }
            break;
        }
        $pending = $next;
    }
    return $errors;
}

/**
 * True when an item with the given id is still in the pending list.
 */
function _pp_menu_item_in_list(int $item_id, array $items): bool {
    foreach ($items as $item) {
        if ((int) ($item->ID ?? 0) === $item_id) {
            return true;
        }
    }
    return false;
}

/**
 * Recreates one snapshotted menu item on a menu. Field access is defensive
 * because snapshot items are whatever wp_get_nav_menu_items() returned —
 * decorated nav_menu_item posts in production, simpler objects in the test
 * store.
 *
 * @return int|null  The new item id, or null when creation failed.
 */
function _pp_recreate_menu_item(int $menu_id, object $item, int $parent_id): ?int {
    $new_id = wp_update_nav_menu_item($menu_id, 0, [
        // Raw post_title, not the decorated ->title: for post_type items an
        // empty stored title means "inherit the linked page's title", and
        // writing the resolved ->title back would freeze it permanently.
        'menu-item-title'       => (string) ($item->post_title ?? ($item->title ?? '')),
        'menu-item-type'        => (string) ($item->type ?? 'custom'),
        'menu-item-object'      => (string) ($item->object ?? ''),
        'menu-item-object-id'   => (int) ($item->object_id ?? 0),
        'menu-item-url'         => (string) ($item->url ?? ''),
        'menu-item-position'    => (int) ($item->menu_order ?? 0),
        'menu-item-parent-id'   => $parent_id,
        'menu-item-target'      => (string) ($item->target ?? ''),
        'menu-item-classes'     => implode(' ', (array) ($item->classes ?? [])),
        'menu-item-xfn'         => (string) ($item->xfn ?? ''),
        'menu-item-attr-title'  => (string) ($item->attr_title ?? ''),
        'menu-item-description' => (string) ($item->description ?? ''),
        'menu-item-status'      => 'publish',
    ]);
    return is_wp_error($new_id) ? null : (int) $new_id;
}

/**
 * Restores every snapshotted post/option to its pre-batch state, and
 * permanently deletes any page a create_page step created during this same
 * batch (it didn't exist before the batch started, so "restore" means it
 * shouldn't exist after a rollback either).
 *
 * @param array $snapshot  Bundle from _pp_snapshot_batch_targets(), with
 *                          'created_posts' populated as steps succeeded.
 * @return string[]         Anything that could NOT be restored — the menu layer, and
 *                           any page whose composition restore was withheld: because
 *                           the live stored value became unreadable mid-batch (#749),
 *                           because the row could not be read authoritatively at all
 *                           (#833, fail closed), or because it was ALREADY unreadable
 *                           when this batch snapshotted it, which since #756 is
 *                           reachable through the corrupt-page repair carve-out.
 *                           Empty when clean.
 */
function _pp_restore_batch_snapshot(array $snapshot): array {
    $errors = [];

    foreach ($snapshot['created_posts'] as $created_post_id) {
        wp_delete_post($created_post_id, true);
    }

    foreach ($snapshot['posts'] as $post_id => $state) {
        if (in_array($post_id, $snapshot['created_posts'], true)) {
            continue; // already deleted above — nothing to restore it to
        }
        // A SNAPSHOT TAKEN FROM UNREADABLE BYTES IS NEVER RESTORABLE (#756), and this
        // is checked FIRST because it is a fact about the CAPTURE, which no later read
        // can change. #749 made the whole batch refuse in this state, so the only way a
        // page reaches the rollback with an entry in 'unreadable' is the corrupt-page
        // repair carve-out — and there the captured value is never a composition this
        // page is KNOWN to hold: usually the degrading accessor's `[]`, a stand-in for
        // bytes nobody could decode, and since #833 possibly a cached copy the database
        // row has already moved past (the map can now name a page the cached read called
        // healthy). Neither is a rollback baseline, which is why the notice below stops
        // at what the batch established rather than describing the bytes.
        //
        // The live re-classify below cannot cover this case, and the difference is the
        // reason both guards exist. It asks whether the TARGET is readable NOW, and on
        // the one path that matters most it answers YES: the repair landed, the step
        // that failed was a LATER one (or, at one step, a concurrent writer's repair
        // landed and this batch's write lost the compare-and-swap). Either way the page
        // reads fine, the guard waves the write through, and `[]` goes over a real
        // composition — measured, and silently, with rollback_errors empty. The repair
        // the operator was told to make is erased by the rollback of the proposal that
        // made it, and the page afterwards reads ok-and-empty rather than corrupt, so
        // nothing flags it again.
        //
        // Withholding costs nothing that was ever owed. The batch found this page
        // unreadable, so there is no pre-batch composition to return it to: leaving the
        // stored bytes alone IS the honest restore, whether they are still the corrupt
        // ones or a repair that outlived the batch. Every other field still rolls back.
        if (isset($snapshot['unreadable'][$post_id])) {
            $errors[] = _pp_batch_target_state_message($post_id, $snapshot['unreadable'][$post_id])
                . ' Its composition was NOT rolled back, and that is the safe outcome:'
                . ' the stored bytes could not be read when this batch snapshotted them,'
                . ' so the snapshot is not a composition this page is known to hold.'
                . ' Writing it back would destroy whatever is there now —'
                . ' the unreadable bytes, or a repair that landed during the batch.'
                . ' Re-read the page to see which. Every other field was rolled back.';
        //
        // RE-CLASSIFY BEFORE WRITING (#749), the second guard and a different question.
        // The snapshot and the rollback are two reads separated by every step the batch
        // ran: an external raw meta write, an import, or a hand-edited row can corrupt a
        // page that WAS readable at capture inside that window, and the honest snapshot
        // this function holds would then be written straight over the newly recoverable
        // bytes. Restoring the OTHER fields is still right — they were captured honestly
        // and the batch did change them — so only the composition write is withheld, and
        // the caller is told which page and why through rollback_errors, the channel the
        // batch envelope already documents as "rolled_back: true is not clean until you
        // check this".
        //
        // NOT a #233 exception. #233's rule — a restore is never blocked by current
        // VALIDATION rules — is untouched: nothing here inspects the snapshot or asks
        // whether today's rules like it. What is checked is the TARGET: whether the
        // bytes about to be overwritten can still be read. A restore that current
        // rules dislike still goes through; only a write onto unreadable bytes waits.
        //
        // THE RE-CLASSIFY IS THE GATE'S, SOURCE AND ALL (#833). It asks the same question
        // the refusal asks, so it reads the same way: the row, failing closed with no
        // handle. Asking the object cache here would have re-opened the hole one step
        // later — within a request the cache it reads is the one already warmed at
        // snapshot time, so on installs without a persistent object cache it echoed the
        // snapshot-time verdict rather than live state, and a row that went corrupt
        // mid-batch was written straight over.
        } elseif (($reason = _pp_batch_target_refusal_reason($post_id, pp_get_composition_result($post_id))) === null) {
            pp_update_composition($post_id, $state['composition']);
        } elseif ($reason === PP_BATCH_TARGET_UNVERIFIABLE) {
            // ITS OWN SENTENCE (#833), for the same reason the refusal has one: the
            // notice below states a FACT about the stored bytes ("changed to an
            // unreadable state"), and here nothing about the bytes is known. Only the
            // outcome is shared — the composition write is withheld either way.
            $errors[] = _pp_batch_target_state_message($post_id, $reason)
                . ' Its composition was NOT rolled back: writing the snapshot over a stored'
                . ' state nobody could confirm risks destroying a copy this batch never read.'
                . ' Every other field on this page was rolled back.';
        } else {
            $errors[] = _pp_batch_target_state_message($post_id, $reason)
                . ' Its composition was NOT rolled back: the stored bytes changed to an'
                . ' unreadable state during this batch, and restoring the snapshot over them'
                . ' would destroy the only recoverable copy. Every other field on this page'
                . ' was rolled back.';
        }
        pp_update_page_title($post_id, $state['title']);
        pp_update_page_slug($post_id, $state['slug']);
        wp_update_post(['ID' => $post_id, 'post_status' => $state['status']], true);
        pp_update_seo_meta($post_id, $state['seo_meta']);
    }

    foreach ($snapshot['site_options'] as $key => $baseline) {
        // Only whitelisted options are ours to touch. The batch snapshotter records
        // every update_site_option step's key up front (before execute rejects an
        // unauthorized one), so a non-whitelisted key can appear here — recorded as
        // absent-shaped. Restoring it would delete_option() an unrelated core WP option,
        // so the whitelist guard stays and runs BEFORE shape normalization. Only VALUE
        // validation is bypassed.
        if (!isset(pp_allowed_site_options()[$key])) {
            continue;
        }
        // Normalize the captured baseline to (exists, value). Two shapes:
        //   - current (#291): ['exists' => bool, 'value' => string], capturing
        //     presence separately from value.
        //   - legacy value-only string: pre-#291 snapshots stored just the value,
        //     collapsing absent and explicit-'' to ''. The snapshot bundle is
        //     request-scoped (built at the top of pp_ai_execute_batch, consumed in the
        //     same request, never persisted), so a legacy-shape bundle cannot actually
        //     reach here across a version boundary — this branch is defensive, and it
        //     degrades to the #281 rule (empty => delete, else => write raw) rather
        //     than erroring.
        if (is_array($baseline)) {
            $exists = array_key_exists('exists', $baseline) && $baseline['exists'] === true;
            $value  = (string) ($baseline['value'] ?? '');
        } else {
            $value  = (string) $baseline;
            $exists = $value !== '';
        }
        // Restore the captured baseline faithfully, bypassing pp_update_site_option's
        // create-time validator. That validator can reject a legitimate captured
        // baseline and silently drop the write (issue 281): a '' fails the
        // attachment_id/bool rules, and a once-valid value a newer rule now rejects
        // (e.g. a pp_logo_id whose attachment was later deleted) would fail
        // re-validation — either would leave the applied value in place instead of
        // rolling back. This is the same class as restore_composition (issue 233):
        // a restore is never blocked by current validation rules. A captured baseline
        // is trusted pre-run state (its keys are already whitelisted), so it restores
        // verbatim: an ABSENT baseline is restored by deleting the row, an EXPLICIT ''
        // baseline is written as '' (distinct outcomes, #291), and every other value
        // is written raw.
        if (!$exists) {
            delete_option($key);
        } else {
            update_option($key, $value);
        }
    }

    if ($snapshot['custom_css'] !== null) {
        // Mirrors clear_custom_css's own write mechanism exactly (there is
        // no wp_update_custom_css_post() call anywhere else in this codebase
        // to stay consistent with) — the Custom CSS post's content IS the
        // Custom CSS.
        $css_post = wp_get_custom_css_post();
        if ($css_post) {
            wp_update_post(['ID' => $css_post->ID, 'post_content' => $snapshot['custom_css']]);
        }
    }

    if ($snapshot['token_overrides'] !== null) {
        update_option('pp_token_overrides', $snapshot['token_overrides'], true);
        pp_invalidate_design_tokens_cache();
    }

    if ($snapshot['font_urls'] !== null) {
        pp_set_font_urls($snapshot['font_urls']);
    }

    if (($snapshot['menus'] ?? null) !== null) {
        // MERGED, not replaced (#749): the menu layer used to be the only producer
        // of rollback errors and returned its list directly. A withheld composition
        // restore is a second producer, and dropping it here would hide exactly the
        // condition it exists to report.
        return array_merge($errors, _pp_restore_menu_state($snapshot['menus']));
    }

    return $errors;
}

/**
 * Executes a batch of proposal steps atomically (issue 137): snapshots
 * every post/option any step could touch, runs each step in order via the
 * existing pp_execute_action()/pp_execute_apply(), and rolls every
 * snapshotted target back if any step fails partway through — leaving the
 * site exactly as it was before the batch started, rather than a half-
 * applied multi-step proposal.
 *
 * Deliberately does NOT pre-validate every step against the projected
 * effect of earlier steps in the same batch before executing any of them.
 * Many real multi-step proposals are intentionally interdependent (e.g.
 * "add a component, then style it") — a step's semantic validate()
 * legitimately depends on state an earlier step in this same batch will
 * create, so validating step 3 against pre-batch state would false-
 * positive-reject it. Each step is still fully validated against the state
 * that actually exists at the moment it runs, exactly as
 * pp_execute_action()/pp_execute_apply() already do — a genuinely invalid
 * step is caught at its own turn and the whole batch rolls back cleanly.
 *
 * Composition CAS baselines (#404): $baselines is a per-post map {post_id => version} the
 * browser captured when the model read each page. A composition-mutating step's baseline is
 * threaded into its params['expected_version'] before execution — from the browser map for
 * the first write to a page, then from the SERVER-DERIVED post-write version for every
 * subsequent write to that same page (in-request chaining). That chaining is why a batch
 * can repeatedly mutate one page without false-conflicting against its own earlier writes:
 * the first write catches anything that moved since the model read, later writes always
 * carry the fresh version. The mandate that every mutating step is covered lives in the
 * chat entry point (lib/ai-chat.php) — reached only after that gate passes.
 *
 * Refuses the whole batch, before step 1, when any page it names has a stored
 * composition that cannot be READ (#749) — see the fail-closed block below. Since #833
 * the same envelope also carries `composition_unverifiable`, which is not a finding about
 * any page: it means the gate could not reach the database row to check one, so it blocked
 * rather than classify from a copy a concurrent write may have left stale. That
 * refusal is the one ok:false envelope with no failing step, and the only return
 * carrying 'error' / 'error_code' at the batch level. Exactly one batch shape is
 * exempt (#756, ruling D-1): a SINGLE update_composition / restore_composition step
 * on a page already classified corrupt, which is the repair the refusal itself
 * prescribes. Nothing else about the refusal moves, and the exemption buys no
 * weakening of the rollback — _pp_restore_batch_snapshot() never writes back a
 * composition captured from an unreadable page.
 *
 * DISCRIMINATE ON steps === [] OR error_code, NEVER ON failed_at ALONE: a
 * SUCCESSFUL batch also returns 'failed_at' => null. The pair (ok === false,
 * failed_at === null) is what identifies the refusal.
 *
 * A FAILED step's band locator is dropped when an earlier step in the same batch wrote
 * that page's composition (#712): the rollback discards the composition the offset was
 * computed against, so it is nulled rather than shipped pointing at a band that moved.
 * See _pp_batch_forget_discarded_locator() for why null and not a re-anchored offset,
 * and for what deliberately stays mid-batch (the message text; the succeeded steps'
 * reports).
 *
 * @param  array $steps      Each: ['type' => 'action'|'apply', 'name' => string, 'params' => array]
 * @param  array $baselines  {post_id => version} CAS baselines per page (#404); [] = none.
 * @return array          ['ok', 'steps' (per-step results), 'failed_at' (?int —
 *                          the failing step index; null on a SUCCESSFUL batch, and
 *                          null on the #749 pre-execution refusal where no step ran;
 *                          an integer index for every executed failure),
 *                          'rolled_back' (bool), 'rollback_errors' (string[] —
 *                          non-empty when the rollback itself could not fully
 *                          restore something; a consumer must not treat
 *                          rolled_back: true as clean without checking it),
 *                          'versions' ({post_id => composition_version} for every page a
 *                          composition-mutating or create_page step wrote — the chat UI
 *                          refreshes its per-page baselines from this (#404, A3))]
 */
function pp_ai_execute_batch(array $steps, array $baselines = []): array {
    $snapshot = _pp_snapshot_batch_targets($steps);

    // FAIL CLOSED ON AN UNREADABLE TARGET (#749). A batch is atomic, and atomicity
    // here is bought with a snapshot that gets written back on failure. If a named
    // page's stored composition cannot be READ, no honest snapshot of it exists —
    // rolling back would mean writing a degraded stand-in over the only recoverable
    // copy of those bytes. So the batch is refused before step 1 instead: nothing
    // ran, so there is nothing to roll back, which is the strongest form of the
    // atomicity promise rather than a weaker one. Same posture as #241 (run-scoped
    // restore fails the preflight closed) and #506 (a corrupt homepage is never
    // seeded over), and the same up-front shape as the #404 baseline mandate.
    //
    // failed_at is NULL here and steps is [] — the ONLY ok:false envelope this
    // function returns without a failing step, because no step ever ran. The chat
    // surface never sees it: _pp_ai_execute_batch_response() (lib/ai-chat.php) runs
    // the same refusal first and returns it through wp_send_json_error, so the
    // client renders it on the error branch it already has for #404. This branch is
    // the fail-closed backstop for every OTHER caller of the executor.
    // ONE EXCEPTION, AND IT IS THE REPAIR ITSELF (#756). A batch of exactly ONE
    // update_composition/restore_composition step on an already-corrupt page is the
    // write this refusal's own message prescribes, so it proceeds — ruling D-1, shared
    // with the CLI admission and single-owned for both gates by
    // _pp_batch_unreadable_refusal(). Everything else about the refusal is unchanged,
    // and the atomicity promise is kept from the other end: the rollback below never
    // writes a composition captured from an unreadable page.
    $unreadable_error = _pp_batch_unreadable_refusal($steps, $snapshot['unreadable']);
    if ($unreadable_error !== null) {
        return [
            'ok'              => false,
            'steps'           => [],
            'failed_at'       => null,
            'rolled_back'     => false,
            'rollback_errors' => [],
            'versions'        => [],
            'error'           => $unreadable_error['error'],
            'error_code'      => $unreadable_error['error_code'],
        ];
    }

    $results = [];

    // Working per-page version map: seeded from the browser baselines, then advanced to the
    // server-derived post-write version after each successful write so the next mutating
    // step on that page chains off what THIS batch just wrote, never a stale value (#404).
    $versions = [];
    foreach ($baselines as $bp => $bv) {
        $normalized_bv = _pp_normalize_version_baseline($bv);
        if (is_numeric($bp) && $normalized_bv !== null) {
            $versions[(int) $bp] = $normalized_bv;
        }
    }
    $mutated_versions = []; // {post_id => post-write version}, returned to the client.

    // Pages an EARLIER, SUCCEEDED step already composition-wrote in this batch (#712).
    // Consumed only by _pp_batch_forget_discarded_locator() at the failure return.
    $composition_written = [];

    foreach ($steps as $i => $step) {
        $type   = $step['type']   ?? '';
        $name   = $step['name']   ?? '';
        $params = $step['params'] ?? [];

        // Thread the CAS baseline into a composition-mutating step (#404). Use the chained
        // post-write version if this batch already wrote the page, else the browser
        // baseline. When neither is present, expected_version is left unset, which the
        // writer treats as null → an unconditional write (NOT a CAS against version 0;
        // only a supplied 0 compares against a legacy page's version 0). Through the real
        // chat handler this unset path is unreachable: _pp_ai_batch_baselines_cover_mutations()
        // rejects a mutating step whose page has no baseline before the batch runs. It can
        // only occur for a direct executor caller — the mandate lives in the entry point,
        // not here, so the executor stays usable without one.
        if ($type === 'action' && pp_action_is_composition_mutating($name)
            && isset($params['post_id']) && is_numeric($params['post_id'])) {
            $step_pid = (int) $params['post_id'];
            if (array_key_exists($step_pid, $versions)) {
                $params['expected_version'] = $versions[$step_pid];
            }
        }

        $result = ($type === 'apply')
            ? pp_execute_apply($name, $params)
            : pp_execute_action($name, $params);

        if (is_wp_error($result)) {
            $result = [
                'ok'      => false,
                'action'  => $name,
                'scope'   => 'unknown',
                'target'  => [],
                'changes' => [],
                'error'   => $result->get_error_message(),
                // A rejected envelope carries the band locator (#642) wherever it is
                // built. Null here: a step that returns a bare WP_Error carries no
                // composition offset to report.
                'index'   => null,
            ];
        }

        if ($name === 'create_page' && !empty($result['ok']) && isset($result['target']['post_id'])) {
            $snapshot['created_posts'][] = (int) $result['target']['post_id'];
        }

        // Advance the per-page version map from the server-derived post-write version
        // (pp_execute_action attaches composition_version for mutating actions + create_page)
        // so the next mutating step on this page chains off it, and record it for the
        // response so the chat UI can refresh its baseline (#404, A2/A3). A page created
        // mid-batch joins the map here at its version-0-derived version with no browser
        // baseline required.
        if (!empty($result['ok']) && isset($result['composition_version'])) {
            $written_pid = null;
            if (isset($params['post_id']) && is_numeric($params['post_id'])) {
                $written_pid = (int) $params['post_id'];
            } elseif (isset($result['target']['post_id']) && is_numeric($result['target']['post_id'])) {
                $written_pid = (int) $result['target']['post_id'];
            }
            if ($written_pid !== null) {
                $versions[$written_pid]         = (int) $result['composition_version'];
                $mutated_versions[$written_pid] = (int) $result['composition_version'];
            }
        }

        // Post-apply DOM validation per step, matching the existing
        // single-step wp_ajax_pp_ai_execute behavior exactly (same
        // try/catch — a validation crash must never mask a successful
        // apply). Runs even for a step later rolled back: it reflects the
        // real state after just that step, which is what the client's
        // per-step "last-step-wins" card logic already expects.
        if (!empty($result['ok']) && isset($params['post_id'])) {
            try {
                $result['validation'] = pp_post_apply_validate((int) $params['post_id']);
            } catch (\Throwable $e) {
                $result['validation'] = [
                    'ok'       => false,
                    'warnings' => [],
                    'errors'   => [[
                        'check'   => 'validation_error',
                        'message' => 'Validation failed: ' . $e->getMessage(),
                    ]],
                ];
            }
        }

        // A LOCATOR THE ROLLBACK IS ABOUT TO INVALIDATE IS DROPPED, NOT SHIPPED (#712).
        // Runs before the envelope joins $results, so nothing downstream ever sees the
        // stale offset. See the helper for why null rather than a re-anchored offset.
        if (empty($result['ok'])) {
            $result = _pp_batch_forget_discarded_locator($result, $params, $composition_written);
        }

        $results[] = $result;

        // Record the page this step wrote, AFTER the failure branch above has read the
        // set — a step's own write cannot be what invalidates its own locator, and a
        // failed step wrote nothing anyway.
        //
        // ACTIONS ONLY, and that is a fact about lib/apply.php rather than an assumption:
        // the seven registered applies are tokens, fonts and media, none declares
        // `mutates_composition`, none writes `_pp_composition`, and an apply envelope
        // carries no `index` key at all — so an apply can neither invalidate a locator nor
        // own one. Pinned by ApplyTest so the day an apply gains a composition write, this
        // predicate is what fails rather than a locator quietly going stale.
        //
        // SPELLED AS `!== 'apply'` TO MIRROR THE DISPATCHER ABOVE, not as `=== 'action'`.
        // The dispatcher routes every type that is not exactly 'apply' to
        // pp_execute_action(), so a step with a missing, empty or misspelled `type` WRITES
        // like an action. Asking the narrower question here would leave such a step
        // unrecorded while its write still moved the bands — and the next failed step on
        // that page would then ship the stale locator this whole guard exists to drop.
        // Unreachable through the chat (lib/ai-chat.php rejects any type outside the pair
        // before the batch runs), but this executor is public and its own contract says
        // the mandates live in the entry point, so it must not assume one ran.
        if (!empty($result['ok']) && $type !== 'apply'
            && (pp_action_is_composition_mutating($name) || $name === 'create_page')) {
            $written_target = _pp_batch_step_composition_target($params, $result);
            if ($written_target !== null) {
                $composition_written[$written_target] = true;
            }
        }

        if (empty($result['ok'])) {
            $rollback_errors = _pp_restore_batch_snapshot($snapshot);
            return [
                'ok'              => false,
                'steps'           => $results,
                'failed_at'       => $i,
                'rolled_back'     => true,
                'rollback_errors' => $rollback_errors,
                // Everything rolled back, so no page's version survived this batch — the
                // client must re-read context for a fresh baseline, not trust a partial map.
                'versions'        => [],
            ];
        }
    }

    return [
        'ok'              => true,
        'steps'           => $results,
        'failed_at'       => null,
        'rolled_back'     => false,
        'rollback_errors' => [],
        'versions'        => $mutated_versions,
    ];
}

/**
 * The page a succeeded composition-writing step wrote, or null (#712).
 *
 * THE THIRD ANSWER TO "WHICH PAGE DID THIS STEP WRITE?" in this file, and the divergence
 * is deliberate, so here is the map. pp_execute_action() resolves `findings` TARGET-first
 * (a report naming the wrong page is worse than absent) and `composition_version`
 * PARAMS-first (a #404 defect it is not that change's to move); the batch's version map
 * above inherits the params-first order from `composition_version`. This one follows
 * `findings` for the same reason `findings` chose it — it has to name the page whose bands
 * actually moved.
 *
 * TARGET FIRST, for the same reason `findings` resolves that way in pp_execute_action():
 * `create_page` takes no `post_id` at all and only its target knows the page it just
 * created, and a create_page call carrying a stray undeclared `post_id` (which
 * pp_validate_action() does not reject) wrote the NEW page, not the named one. The seven
 * composition-mutating actions declare `post_id` required and echo it into their target,
 * so for them both orders agree.
 *
 * @param  array $params  The step's params.
 * @param  array $result  Its (successful) envelope.
 * @return int|null       Post id, or null when neither side names one.
 */
function _pp_batch_step_composition_target(array $params, array $result): ?int {
    if (isset($result['target']['post_id']) && is_numeric($result['target']['post_id'])) {
        return (int) $result['target']['post_id'];
    }
    if (isset($params['post_id']) && is_numeric($params['post_id'])) {
        return (int) $params['post_id'];
    }

    return null;
}

/**
 * Drops a failed batch step's band locator when the rollback is about to invalidate it
 * (#712 — the structural answer #642 deferred).
 *
 * THE PROBLEM. A batch is atomic: when a step fails, `_pp_restore_batch_snapshot()`
 * puts every target back to its pre-batch state. The failed step's envelope is returned
 * as-is, and its `index` was computed against the composition as THAT step saw it —
 * mid-batch. On a page `[healthyA, healthyB, badBand]` the batch
 * `remove_component(0)` then `update_component(0)` reports `index: 1`; after the
 * mandatory rollback band 1 is `healthyB` and the real offender is band 2. #642 made
 * `index` first-class precisely so an agent stops repairing the wrong band, and
 * AI_CONTEXT.md tells it to trust the field — so on this path the field is honest about
 * a composition that no longer exists, which to its only consumer is a fabricated
 * locator.
 *
 *   step 0  remove_component(0)   ok      composition_written[42] = true
 *   step 1  update_component(0)   FAIL    index 1, computed against [healthyB, badBand]
 *           └─ rollback ─────────────────► [healthyA, healthyB, badBand]  (index 1 = healthyB)
 *                                          ▲ the offset now addresses a healthy band
 *
 * WHY NULL AND NOT A RE-ANCHORED OFFSET. Re-anchoring needs an inverse map from
 * mid-batch offsets back to restored ones, and no such inverse exists in general:
 * `update_composition` and `restore_composition` replace the list wholesale, so a
 * mid-batch band can have NO counterpart in the restored composition; `add_component`
 * can create a band that exists only mid-batch; and a page an earlier `create_page`
 * created is DELETED outright by the rollback, so there is no composition left to anchor
 * against. Even the one invertible case — a single `remove_component` — would mean
 * replaying the batch's mutations backwards through state the executor does not retain
 * past the rollback. A locator that cannot be PROVEN to address the offending band is
 * the thing #642 exists to prevent, so this returns the vocabulary's existing "no single
 * band owns this" value rather than a plausible number. `null` has always meant no
 * fabricated locator, never band 0.
 *
 * WHY THE OTHER CASE IS KEPT. When no earlier step wrote this page, the batch did not
 * move its bands: the composition the failed step validated is the one
 * `_pp_restore_batch_snapshot()` writes back, so the offset still addresses the same
 * band after the rollback. That is the guarantee — "this BATCH did not move them", not
 * "nothing in the universe did"; an out-of-band writer in the same window is what the
 * #404 CAS baseline answers, not this. Nulling every failed step's locator instead would
 * throw away a correct answer on the common single-page batch to fix the multi-step one.
 *
 * THE RULE IS WHOLE-STEP AND DELIBERATELY CONSERVATIVE. It asks only "did this batch
 * write this page?", not "was this particular locator computed against stored state?".
 * Those differ for `update_composition`, which validates the array the CALLER submitted:
 * its offset addresses that payload, which the rollback cannot touch, so a batch of
 * `remove_component` then a failing `update_composition` on the same page drops an offset
 * that was provably still correct. That is the accepted cost of one rule instead of a
 * per-action carve-out. It errs toward the documented "no locator" value rather than
 * toward a locator whose validity depends on which action happened to reject — and a
 * reader who has just been told the whole batch rolled back has no way to know which
 * kind they were handed. `null` costs a re-read; a wrong offset costs a wrong repair.
 *
 * WHAT THIS DOES NOT TOUCH, deliberately:
 *  - The failed step's `error` MESSAGE, which still quotes mid-batch band prose
 *    (`Component 1 ("logos") ...`). That text comes from the producing validator; having
 *    the batch layer rewrite another layer's sentence would be a second fabrication, and
 *    the message half of this predates #642 (see the documented caveat).
 *  - The SUCCEEDED steps' `findings` (and the `index` values inside them), which describe
 *    compositions the rollback also discarded. Those are accepted-write reports, a wider
 *    class than the rejection locator, pinned as-is by WriteEnvelopeFindingsTest.
 * Both remain covered by the "re-read the page after a rolled-back batch" caveat in
 * AI_CONTEXT.md and docs/reference-apply-cli.md, which now states the split.
 *
 * @param  array $result               The failing step's envelope.
 * @param  array $params               That step's params (its own post_id, if any).
 * @param  array $composition_written  {post_id => true} for pages EARLIER steps wrote.
 * @return array                       The envelope, with `index` nulled where it no
 *                                     longer addresses anything.
 */
function _pp_batch_forget_discarded_locator(array $result, array $params, array $composition_written): array {
    if (($result['index'] ?? null) === null) {
        return $result; // nothing to drop — a locator is real or absent, never faked.
    }

    // ONE QUESTION, ONE ANSWER. "Which page is this step about?" is asked twice per batch —
    // here, and by the recorder that fills $composition_written — so both ask it through
    // the same helper. Asking it two ways is how a set keyed on one id gets read with
    // another and silently keeps every stale locator. Today the two orders agree on every
    // reachable step (the seven mutating actions all declare post_id required), so this
    // costs nothing and closes the fail-open a future action that resolves its target from
    // the envelope would otherwise open.
    $page = _pp_batch_step_composition_target($params, $result);
    if ($page === null || !isset($composition_written[$page])) {
        return $result;
    }

    $result['index'] = null;

    return $result;
}

// ── Helper: build result arrays ─────────────────────────────────────────────

function _pp_action_result(string $name, string $scope, array $target, array $changes): array {
    return [
        'ok'         => true,
        'action'     => $name,
        'scope'      => $scope,
        'target'     => $target,
        'changes'    => $changes,
        'error'      => null,
        'error_code' => '', // uniform shape with _pp_action_error (#13); no error on success.
        // NO `index` here, deliberately (#642). The locator answers "which band blocked
        // this write", so it exists only where a write WAS blocked; the ratified
        // vocabulary scopes it to rejected envelopes. #13's uniform shape still holds
        // for the keys #13 defined — read `index` only after checking ok === false.
        //
        // NO `findings` here either, and for a different reason: this builder is shared
        // with the token/menu/page-lifecycle actions, where a composition report means
        // nothing. pp_execute_action() attaches it after the write, for the composition
        // surface only (#687).
    ];
}

function _pp_action_error(string $name, string $scope, string $error, string $error_code = ''): array {
    return [
        'ok'         => false,
        'action'     => $name,
        'scope'      => $scope,
        'target'     => [],
        'changes'    => [],
        'error'      => $error,
        // Structured machine-detectable code (#13). Callers (AJAX/JS, the AI chat) key on
        // this rather than parsing the human message — e.g. 'composition_conflict' so the
        // editor can prompt a reload instead of surfacing a generic failure. Empty when the
        // error has no structured code (most validation failures carry only a message).
        'error_code' => $error_code,
        // Composition offset of the band that owns the rejection (#642), beside
        // error_code so a rejected envelope has one locator shape wherever it is built.
        // ALWAYS null here: this builder renders EXECUTE-stage failures, and every
        // execute-stage failure is a writer error — pp_update_composition() is a
        // non-validating writer, so what fails here is the CAS baseline, the lock, or
        // the post row, none of which belongs to a band. Composition rules run at the
        // validate stage, where _pp_action_validation_error_envelope() reports the real
        // offset. If a future execute-stage rejection ever DOES belong to one band, this
        // must carry that offset rather than keep claiming none.
        'index'      => null,
    ];
}

/**
 * The BEFORE side of a whole-composition write, told truthfully (#836).
 *
 * WHAT THIS REPLACES AND WHY IT IS THE LAST INSTANCE OF THAT BUG. The four sites below
 * built `changes[].from` (and preview's `before`) with `pp_get_composition()`, the legacy
 * array-only accessor. That accessor IS `pp_get_composition_result($post_id)['composition']`
 * (lib/wp.php), and a `!ok` classification always carries `composition => []` — so a page
 * whose stored bytes will not decode was described to the operator as a page containing
 * nothing. Same class as #725/#748/#750, ruling R-C: a corrupt page is never described as
 * empty.
 *
 * WHY IT REACHES A HUMAN DECISION rather than only a log line. `update_composition` and
 * `restore_composition` are the only two composition actions declaring
 * `requires_composition => false` (they POPULATE, so requiring a composition would strand
 * the page), which is exactly why the #748 precondition gate — the thing that refuses every
 * OTHER composition action on a corrupt page before it can build a `from` — never fires
 * here. And since #750 the chat's corruption block instructs the model to propose exactly
 * one of these two verbs on such a page, and #756's carve-out admits it. So the false
 * before-state does not sit in a diagnostic: it sits on the APPROVAL GATE, the last human
 * checkpoint before a whole-composition replacement lands over recoverable bytes.
 *
 *   THE FOUR SITES, all of which now read through here:
 *     update_composition   preview   `before` + changes[0].from
 *     update_composition   execute   changes[0].from
 *     restore_composition  preview   `before` + changes[0].from
 *     restore_composition  execute   changes[0].from
 *
 *   THE TWO SURFACES that render what they carry:
 *     the chat proposal card   assets/js/pp-ai-chat.js (the approval gate)
 *     `wp pp action preview|execute`   lib/cli.php — prints the envelope through
 *                                      _pp_cli_emit_json() verbatim, so once the VALUE is
 *                                      honest that surface is honest with no change of
 *                                      its own. The v1.17.0 smoke saw `from: []` there as
 *                                      well as on the card, which is the evidence that
 *                                      this root was shared rather than chat-only.
 *
 * BLANK IS NOT CORRUPT, AND `[]` IS STILL THE RIGHT ANSWER FOR ONE OF THEM. A page with
 * absent meta, and a page storing the literal `"[]"`, both classify `ok` with an empty
 * list — `[]` is their TRUTH, not a degradation, and they keep it. Only `!ok` produces the
 * marker. On every `ok` input this returns exactly what `pp_get_composition()` returned, so
 * the healthy and blank envelopes are byte-identical to their pre-#836 form.
 *
 * THE MARKER SHAPE, and why it lives IN `from` rather than beside it.
 *
 *   ['unreadable' => true, 'classification' => <the classifier's error noun>,
 *    'message' => pp_composition_integrity_message(...)]
 *
 * The classification is passed through from pp_classify_composition_value() (lib/wp.php)
 * rather than matched against a list, so lib/wp.php stays the only place the nouns are
 * enumerated — today `decode_error` and `unexpected_shape`, named here as examples and not
 * as a closed set. The chat predicate takes the same posture for the same reason: it
 * REQUIRES a classification and never checks WHICH one.
 *
 * NAME COLLISION WORTH KNOWING BEFORE YOU GREP: `unreadable` also appears in this file as
 * a batch-snapshot key (_pp_batch_target_refusal_reason's neighbourhood), where it is a
 * MAP of post_id => classification string rather than a boolean. The two are unrelated
 * shapes in unrelated envelopes; neither is being renamed, because both are shipped.
 *
 * `from => null` was the obvious alternative and is the wrong one: null already MEANS
 * "there was no prior value" on shipped actions (`create_page`, `create_redirect`'s
 * execute, the derived-token changes in lib/apply.php), so it would re-enact this very bug
 * one type over — a real state degraded to a benign-looking one. A sibling key
 * (`from_error` beside an emptied `from`) fails the same way for a different reader: a
 * consumer that reads `from` and not the sibling still gets the lie, which is precisely
 * today's fail-open. An object in `from` is fail-SAFE — it cannot be mistaken for a list by
 * anything, including a consumer that never heard of this change.
 *
 * ENVELOPE COMPATIBILITY, stated rather than assumed. `changes[].from` was never "a
 * composition array" envelope-wide; it is per-action shaped and always has been — `null`
 * (create_page), a status string (publish_page), a token value (lib/apply.php), and an
 * ASSOCIATIVE OBJECT on both redirect actions, which carry `['to' => ..., 'code' => ...]`.
 * A consumer assuming list-ness of an arbitrary `from` is already wrong on shipped
 * actions. The audited consumers: `array_column($result['changes'], 'token')` twice in
 * lib/cli.php (token actions only, never a composition envelope), `count()` on the same
 * array, `_pp_cli_emit_json()` (a JSON sink — an object encodes and prints), and the chat
 * card, which ALREADY gated its composition renderer on `Array.isArray(change.from)` and
 * so degrades rather than breaks. The envelope `before` field has no production reader in
 * PHP or JS at all.
 *
 * NO SECOND SPELLING. The classification comes from pp_classify_composition_value() via
 * the cached reader, and the sentence from pp_composition_integrity_message() — the two
 * single owners #650/#652 exist to protect. This function invents no vocabulary; the JS
 * that renders the marker prints `message` verbatim rather than composing prose of its own.
 *
 * IT DOES NOT NAME THE REPAIR ROUTE, deliberately, and the reason is in that function's
 * own text rather than in a general rule. pp_corrupt_repair_route_message() (lib/wp.php)
 * owns the sentence, and its #756 paragraph explicitly declines to cover the third route
 * ruling D-1 opened — a chat proposal whose only step is one of these two verbs — because
 * a route sentence has to be true on every surface that prints it. An operator reading
 * THIS marker is looking at that very proposal, so reciting the CLI commands for it would
 * be advice about a surface they are not on. The claim that IS single-owned there ("a
 * whole-composition write repairs this page, and here is what it costs") is left where it
 * lives; nothing is re-spelled here.
 *
 * A CACHED READ, and that is the recorded posture rather than an oversight. This is a
 * CONTEXT/DISPLAY read (the #750 precedent: a reader, not a gate), so it goes through
 * pp_get_composition_result() and does NOT call pp_composition_db_handle() — it is not a
 * sixth consumer of that capability census. No MACHINE gate consumes this value: it
 * reaches `before` and `changes[].from` and nothing else reads either, so no
 * authorization, enforcement or write decision turns on it, and the write itself is still
 * governed by the `expected_version` CAS. The one ordering that matters is already right:
 * execute reads this BEFORE pp_update_composition() runs, so no post-write read exists to
 * go stale.
 *
 * BE PRECISE ABOUT WHAT THAT DOES NOT SAY, because the card IS a gate — a human one. A
 * stale object-cache entry could paint this marker over a page whose stored bytes are
 * healthy, and the operator's Apply click is fed by that. It is not a regression (the
 * pre-#836 read used the same cache and answered "0 components", which is strictly worse)
 * and the CAS still refuses the write if the page moved underneath, but "display" is a
 * claim about which MACHINE decisions consume the value, not a claim that nothing acts on
 * it. Tightening the human gate is a concurrency question, tracked as #845, and switching
 * this read to the uncached path is exactly the wrong shape of fix for it — that would
 * make a display read the sixth consumer of a census built for gate-opening decisions.
 *
 * @param  int $post_id  The page whose stored composition is being replaced.
 * @return array  The stored list, or the unreadable marker.
 */
function _pp_composition_before_state(int $post_id): array {
    $stored = pp_get_composition_result($post_id);

    if ($stored['ok']) {
        return $stored['composition'];
    }

    $error = (string) $stored['error'];

    return [
        'unreadable'     => true,
        'classification' => $error,
        'message'        => pp_composition_integrity_message($post_id, $error),
    ];
}

/**
 * Extracts the optional optimistic-locking baseline (#13) from an action's params.
 *
 * Returns the `expected_version` the caller based its edit on as an int, or null when the
 * param is absent — null tells pp_update_composition() to skip the compare-and-swap
 * (documented back-compat). pp_validate_action() has already type-checked the param as an
 * int when present, so the cast is defensive, not load-bearing.
 *
 * @param array $params  Action params.
 * @return int|null
 */
function _pp_action_expected_version(array $params): ?int {
    return isset($params['expected_version']) ? (int) $params['expected_version'] : null;
}

/**
 * The optional `expected_version` param registered on every composition-mutating action
 * (#13). Spread into the action's `params` map. Optional so direct/legacy callers keep
 * writing unconditionally; the AI/AJAX/CLI execute paths always supply it.
 */
function _pp_expected_version_param(): array {
    return ['type' => 'int', 'required' => false];
}

/**
 * True when the named action mutates a page's composition and therefore participates in
 * the write-time CAS (#13). The marker is the SAME declarative `mutates_composition` flag
 * the CLI baseline gate reads (lib/cli.php:207/245/430) — the single source of truth for
 * "this action threads a composition CAS baseline," so the chat mandate (#404) and the CLI
 * freshness gate can never drift on which actions need one. Every such action also declares
 * the optional `expected_version` param and passes it to pp_update_composition().
 *
 * create_page is deliberately NOT in this set: it creates a page whose composition starts
 * at the writer's version-0 semantics, so it needs no browser-supplied baseline.
 *
 * @param string $name  Action name.
 * @return bool
 */
function pp_action_is_composition_mutating(string $name): bool {
    $action = pp_get_action($name);
    return $action !== null && !empty($action['mutates_composition']);
}

/**
 * Normalizes an untrusted composition-version baseline to a clean non-negative int or null.
 *
 * The version counter is always a non-negative integer. Anything that is not a plain
 * non-negative integer (a float like 1.9, a mixed string like "12abc", an array, a bool)
 * is a malformed/hostile client value — normalized to null (ABSENT) rather than coerced
 * into a wrong baseline that would either spuriously conflict or silently match the wrong
 * version. A legitimate 0 is preserved (a legacy/never-written page reads as version 0 and
 * initializes to version 1). Backs the editor's request baseline
 * (_pp_expected_version_from_request, lib/admin.php) and the chat batch baseline-map parser
 * (#404), so those two surfaces reject the same hostile shapes. The chat SINGLE-execute
 * mandate does not route through here: its baseline arrives as an action param already
 * int-coerced by pp_ai_coerce_params() and then int-type-checked by pp_validate_action(),
 * so a non-integer expected_version is rejected there instead (invalid_param_type).
 *
 * @param mixed $raw  Untrusted value.
 * @return int|null
 */
function _pp_normalize_version_baseline($raw): ?int {
    // A real int is accepted when non-negative. Otherwise ONLY a plain digit string counts
    // — this deliberately rejects bools (true casts to "1"), floats (1.9), signed/mixed
    // strings ("-1", "12abc"), and arrays, none of which are a legitimate version baseline.
    if (is_int($raw)) {
        return $raw >= 0 ? $raw : null;
    }
    return (is_string($raw) && ctype_digit($raw)) ? (int) $raw : null;
}

/**
 * Extracts the optimistic-locking baseline (#13) from an untrusted request array ($_POST),
 * returning a clean non-negative integer version or null.
 *
 * The version counter is always a non-negative integer. Anything that is not a plain
 * non-negative integer string (a float like "1.9", a mixed value like "12abc", an array
 * from `expected_version[]=`, a bool) is a malformed/hostile client value — treated as
 * ABSENT (null) rather than `(int)`-coerced into a wrong baseline that would either
 * spuriously conflict or, worse, silently match the wrong version. Absent → the write
 * skips the CAS (documented back-compat), the same as any caller that omits it.
 *
 * @param array $src  An untrusted request array (typically $_POST).
 * @return int|null
 */
function _pp_expected_version_from_request(array $src): ?int {
    if (!isset($src['expected_version'])) {
        return null;
    }
    return _pp_normalize_version_baseline($src['expected_version']);
}

function _pp_action_preview(string $name, string $scope, array $target, $before, $after, array $changes): array {
    return [
        'ok'      => true,
        'action'  => $name,
        'scope'   => $scope,
        'target'  => $target,
        'before'  => $before,
        'after'   => $after,
        'changes' => $changes,
        'error'   => null,
    ];
}

// ── Action: create_page ─────────────────────────────────────────────────────
// Scope: site | Semantics: creates new page with composition template
// Params: title (req, string), composition (opt, array), status (opt, string)

pp_register_action('create_page', [
    'scope'       => 'site',
    'description' => 'Creates a new page with the Composition template. Each composition item is {"component": "name", "props": {...}}. With a composition supplied the call is all-or-nothing: if the composition write is refused (the writer could not take the page lock, error_code composition_lock_failed), the page just created is removed and the call is REFUSED rather than reported as success over an empty page — retry the same call, since normally no page and no slug are left behind; if the message says a page WAS left, it names it (#719).',
    'semantics'   => 'Create. Title is required. Composition defaults to empty array and must be a JSON ARRAY (a list) of components, never an object keyed by position — an object is refused with unexpected_shape and no page is created (#724). Status defaults to "draft". Composition items use the same {"component", "props"} shape as elsewhere. Optional slug sets the canonical route up front (#134) — omit to let WordPress derive one from the title. A page created with no composition is NOT stranded: it can be populated later with update_composition or deleted with trash_page through the operate surface (#358); only component-level edits (add/remove/reorder/update/style_component) require an existing composition first. When a composition IS supplied, the call is all-or-nothing: if the composition write itself fails (the writer could not take the page lock), the page just created is deleted again and the call is REFUSED with the writer\'s error_code rather than reported as success over an empty page (#719).',
    'params'      => [
        'title'       => ['type' => 'string', 'required' => true],
        'composition' => ['type' => 'array',  'required' => false],
        'status'      => ['type' => 'string', 'required' => false],
        'slug'        => ['type' => 'string', 'required' => false],
    ],
    'validate' => function (array $params) {
        if (trim($params['title']) === '') {
            return new WP_Error('empty_title', 'Page title cannot be empty.');
        }
        if (isset($params['composition'])) {
            $params['composition'] = pp_normalize_composition($params['composition']);
            $valid = pp_validate_composition($params['composition']);
            if (is_wp_error($valid)) {
                return $valid;
            }
        }
        if (isset($params['slug']) && sanitize_title($params['slug']) === '') {
            return new WP_Error('invalid_slug', 'Slug must not be empty after sanitization.');
        }
        return true;
    },
    'preview' => function (array $params): array {
        $composition = isset($params['composition'])
            ? pp_normalize_composition($params['composition'])
            : [];
        return _pp_action_preview('create_page', 'site', [], null, [
            'title'       => $params['title'],
            'status'      => $params['status'] ?? 'draft',
            'composition' => $composition,
        ], [
            ['path' => 'page', 'from' => null, 'to' => $params['title']],
        ]);
    },
    'execute' => function (array $params): array {
        $status = $params['status'] ?? 'draft';
        $slug   = $params['slug'] ?? '';
        $post_id = pp_create_page($params['title'], $status, $slug);

        if (is_wp_error($post_id)) {
            return _pp_action_error('create_page', 'site', $post_id->get_error_message());
        }

        if (!empty($params['composition'])) {
            $params['composition'] = pp_normalize_composition($params['composition']);
            $written = pp_update_composition($post_id, $params['composition']);

            // THE WRITE'S VERDICT IS NOT OPTIONAL (#719). pp_update_composition() is a
            // non-validating writer that still refuses in one reachable case here: it could
            // not acquire the per-post advisory lock (GET_LOCK timed out or returned NULL),
            // so it logged and SKIPPED the write rather than risk a lost update. Its CAS
            // branch cannot fire on this path — create_page passes no expected_version — and
            // both refusals return BEFORE any meta write, so on arrival here THIS call has
            // provably stored nothing.
            //
            // Discarding this return let the action report ok:true over that empty page, and
            // since #687 the same envelope also carried findings: [] — the key AI_CONTEXT.md
            // and ai-instructions/operating-loop.md define as the positive confirmation the
            // write did what you asked. A confident all-clear over a page that lost its
            // content is worse than the bare ok:true it replaced, which is why the seven
            // composition-mutating siblings all convert this WP_Error into a rejection.
            if (is_wp_error($written)) {
                // ROLL THE PAGE BACK, then reject in the siblings' shape. The rejection
                // alone would not do: _pp_action_error() renders target => [], and
                // _pp_ai_execute_error_payload() (lib/ai-chat.php) collapses every failure
                // except composition_conflict to its message string, so a surviving page's
                // id has nowhere structural to go and the caller is left with a page it
                // cannot name. Deleting it makes the empty target TRUE rather than lossy:
                // the caller either gets the page it asked for WITH its composition, or it
                // gets nothing and retries the same call. "Nothing" is the normal outcome,
                // not a guaranteed one — the two branches below that leave the page standing
                // both say so in the message rather than letting the empty target imply it.
                //
                // wp_delete_post($id, true) is the same call, on the same rationale, as the
                // batch rollback's treatment of a create_page step (_pp_restore_batch_snapshot
                // above): the page did not exist before this call, so a refusal should not
                // leave it existing after. When the delete lands, that also keeps the batch
                // executor honest for free — it records created_posts only for steps that
                // returned ok:true, so a page left behind by a REJECTED step would otherwise
                // survive a `rolled_back: true` envelope. When the delete does NOT land, that
                // is exactly the state the batch cannot see, which is why the message below
                // names the surviving page: for a batch caller it is the only place the id
                // appears at all.
                //
                // ONLY IF NOTHING ELSE WROTE. The refusal proves THIS call stored nothing; it
                // does not prove the page is empty. wp_insert_post() above fires save_post,
                // and a listener on it could have stored a composition of its own — deleting
                // that would destroy content this action never wrote. So the delete is gated
                // on the raw meta still being absent. The read is not under the write lock, so
                // it is best-effort rather than a guarantee; what it guarantees is direction —
                // it can only ever PREVENT a destructive call, never cause one.
                //
                // Not a general-purpose undo, and the comment should not pretend otherwise:
                // wp_insert_post() and wp_delete_post() both fire hooks, so third-party side
                // effects of the creation are not unwound. What this action owns is the pair
                // it wrote — the post row and its composition — ending consistent. The delete
                // also cascades wider than the row: post meta, revisions, comments and child
                // attachments (files included) go with it. On a page seconds old whose
                // creation is being refused that is the intent, recorded here rather than
                // discovered later.
                //
                // CAPABILITY NOTE: create_page is gated on publish_pages while trash_page
                // requires delete_post (_pp_required_caps_for, lib/ai-chat.php), so this
                // delete runs without a delete_post check. It is not an escalation route —
                // the target is a page that did not exist before this call, and no caller can
                // name, influence or reuse the id — but the asymmetry is deliberate, not an
                // oversight, and a future reader of the capability table should see it here.
                //
                // wp_delete_post() reports failure as a falsy return (false/null), NEVER a
                // WP_Error, so this is a truthiness check by necessity, not by style.
                $pristine = get_post_meta($post_id, '_pp_composition', true) === '';
                $removed  = $pristine ? (bool) wp_delete_post($post_id, true) : false;

                // The writer's own message already closes with "Retry once contention clears.",
                // so this clause adds only what is new: what became of the page. A surviving
                // page is NAMED, because _pp_action_error() renders target => [] and the chat
                // collapses a failure to this string — the message is the only place its id
                // can reach the caller.
                if ($removed) {
                    $aftermath = ' The page created for this call was removed, so no page was left behind.';
                } elseif ($pristine) {
                    $aftermath = ' The page created for this call (post ' . $post_id . ') is still there and stores'
                        . ' no composition. Populate it with update_composition or remove it with trash_page.';
                } else {
                    $aftermath = ' The page created for this call (post ' . $post_id . ') is still there and is NOT'
                        . ' empty — something else wrote to it, so it was left alone. Inspect it before reusing it.';
                }

                return _pp_action_error(
                    'create_page',
                    'site',
                    $written->get_error_message() . $aftermath,
                    $written->get_error_code()
                );
            }
        }

        return _pp_action_result('create_page', 'site', ['post_id' => $post_id], [
            ['path' => 'page', 'from' => null, 'to' => $params['title']],
        ]);
    },
]);

// ── Action: update_site_option ──────────────────────────────────────────────
// Scope: site | Semantics: replace. Whitelist: pp_allowed_site_options()

pp_register_action('update_site_option', [
    'scope'       => 'site',
    'description' => 'Updates a whitelisted WordPress site option (blogname, blogdescription, pp_logo_id, pp_logo_alt, site_icon, pp_footer_show_logo, pp_footer_bg, pp_footer_text, pp_footer_link_color, pp_footer_blurb, pp_footer_contact, pp_footer_copyright, pp_footer_menu_label, pp_footer_contact_label, pp_footer_secondary_label, pp_footer_note, pp_footer_logo_id, pp_footer_social, pp_header_bg, pp_header_text, pp_header_link_color, pp_og_image, pp_og_site_name, pp_og_default_description, pp_twitter_card). pp_logo_id takes a Media Library attachment ID (not a URL) to set the site logo. site_icon takes a Media Library image attachment ID (not a URL) to set the browser-tab favicon and app/OS icon; this is WordPress core\'s site_icon option, so once set the favicon and apple-touch-icon tags render automatically (no page composition needed). Core renders the chosen attachment as-is on this path (the Customizer\'s square-crop step is not run), so pass a roughly square source (ideally >=512px) for a clean icon; any image is accepted. pp_footer_show_logo is a boolean (1/0/true/false) that turns the footer logo on/off. The header and footer are template-owned chrome: these site options are the ONLY way to style them (they cannot be composed). pp_header_bg / pp_footer_bg set the header and footer BACKGROUND and each accept a CSS color OR a gradient (hex, rgb()/hsl(), transparent, currentColor, a known color-token reference, or a bounded linear-gradient()/radial-gradient() with 2+ color stops) — this is how you build a dark or gradient marketing header/footer. pp_header_text / pp_footer_text set text color, and pp_header_link_color / pp_footer_link_color set nav-link color (pp_header_link_color also colors the active/current header link, which keeps its bold weight and falls back to the global accent only when the option is unset); those four take a CSS color only (no gradient). pp_footer_blurb, pp_footer_contact, and pp_footer_copyright are text (empty pp_footer_copyright keeps the default copyright line). Footer STRUCTURE: pp_footer_menu_label and pp_footer_contact_label are optional column headings (text) above the footer menu and contact block; pp_footer_note is an optional secondary line (text) that, when set, moves the copyright into a delimited bottom bar and renders opposite it (empty keeps the copyright inline). A SECOND footer menu column is available: assign a menu to the "footer_secondary" theme location (assign_menu_location / set_menu) and it renders as an extra footer column; pp_footer_secondary_label is its optional heading (text, empty = a headless second column). This is how to render a distinct footer menu such as a Legal column (Aviso legal / Privacidad / Cookies) alongside the primary footer menu; with no menu assigned to footer_secondary the footer is unchanged. pp_footer_logo_id is an optional footer logo override (Media Library attachment ID, not a URL) so a light logo variant can serve a dark footer while pp_logo_id stays the header logo; unset falls back to pp_logo_id. pp_footer_social renders the footer social-icon row: a JSON string holding an ordered list of {network, url} objects, where network is one of a CLOSED set (x, linkedin, facebook, instagram, youtube, github, tiktok, mastodon) and url is an http(s) profile URL. Unknown networks, non-http(s) values and malformed JSON are rejected; empty or unset renders no row. Open Graph / Twitter social-share defaults (#468): pp_og_image is the social-share image (a Media Library image attachment ID, not a URL, same rule as pp_logo_id) — it feeds og:image (+ width/height from the attachment metadata, alt from the attachment alt) and twitter:image; pp_og_site_name overrides the og:site_name (defaults to the site name); pp_og_default_description is the site-wide fallback social description used when a page has no meta_description (text, 320 chars or fewer, same cap as meta_description); pp_twitter_card is the Twitter card type, one of summary or summary_large_image (defaults to summary_large_image). These render as og:*/twitter:* tags in wp_head; per-page og_title/twitter_title overrides go through update_seo_meta.',
    'semantics'   => 'Replace. Key must be whitelisted. Value replaces entirely and is validated against the key type (pp_logo_id, pp_footer_logo_id, site_icon, and pp_og_image must be an image attachment ID; pp_footer_show_logo must be a boolean; pp_header_bg/pp_footer_bg must be a CSS color OR a bounded gradient; pp_header_text/pp_header_link_color/pp_footer_text/pp_footer_link_color must be a CSS color; pp_twitter_card must be summary or summary_large_image; pp_og_default_description is capped at 320 characters; pp_footer_social must be a JSON array of {network, url} objects with a known network and an http(s) URL; the other pp_footer_*/pp_og_site_name keys — blurb, contact, copyright, menu_label, contact_label, secondary_label, note, og_site_name — are free text).',
    'params'      => [
        'key'   => ['type' => 'string', 'required' => true],
        'value' => ['type' => 'string', 'required' => true],
    ],
    'validate' => function (array $params) {
        $allowed = pp_allowed_site_options();
        if (!isset($allowed[$params['key']])) {
            return new WP_Error('invalid_option', sprintf('Option "%s" is not whitelisted. Allowed: %s.', $params['key'], implode(', ', array_keys($allowed))));
        }
        return pp_validate_site_option_value($params['key'], (string) $params['value']);
    },
    'preview' => function (array $params): array {
        $current = pp_site_option($params['key']);
        if (is_wp_error($current)) {
            $current = '';
        }
        return _pp_action_preview('update_site_option', 'site', ['key' => $params['key']], $current, $params['value'], [
            ['path' => $params['key'], 'from' => $current, 'to' => $params['value']],
        ]);
    },
    'execute' => function (array $params): array {
        $current = pp_site_option($params['key']);
        if (is_wp_error($current)) {
            $current = '';
        }
        $result = pp_update_site_option($params['key'], $params['value']);
        if (is_wp_error($result)) {
            return _pp_action_error('update_site_option', 'site', $result->get_error_message());
        }
        return _pp_action_result('update_site_option', 'site', ['key' => $params['key']], [
            ['path' => $params['key'], 'from' => $current, 'to' => $params['value']],
        ]);
    },
]);

// ── Action: update_page_title ───────────────────────────────────────────────
// Scope: page | Semantics: replace

pp_register_action('update_page_title', [
    'scope'       => 'page',
    // Page metadata: needs only that the page EXISTS, not a populated composition (#358).
    'requires_composition' => false,
    'description' => 'Updates a page title.',
    'semantics'   => 'Replace. Title is fully replaced.',
    'params'      => [
        'post_id' => ['type' => 'int',    'required' => true],
        'title'   => ['type' => 'string', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        return true;
    },
    'preview' => function (array $params): array {
        $current = get_the_title($params['post_id']);
        return _pp_action_preview('update_page_title', 'page', ['post_id' => $params['post_id']], $current, $params['title'], [
            ['path' => 'title', 'from' => $current, 'to' => $params['title']],
        ]);
    },
    'execute' => function (array $params): array {
        $current = get_the_title($params['post_id']);
        $result = pp_update_page_title($params['post_id'], $params['title']);
        if (is_wp_error($result)) {
            return _pp_action_error('update_page_title', 'page', $result->get_error_message());
        }
        return _pp_action_result('update_page_title', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'title', 'from' => $current, 'to' => $params['title']],
        ]);
    },
]);

// ── Action: update_page_slug ─────────────────────────────────────────────────
// Scope: page | Semantics: replace

pp_register_action('update_page_slug', [
    'scope'       => 'page',
    // Page metadata: needs only that the page EXISTS, not a populated composition (#358).
    'requires_composition' => false,
    'description' => 'Updates a page slug (post_name) / permalink (#134). WordPress de-duplicates the slug internally on collision (suffixing -2, -3, ...) — the result reports the actual slug that was set, which may differ from the one requested.',
    'semantics'   => 'Replace. Slug is sanitized via sanitize_title() and, on a naming collision with another post, de-duplicated by WordPress core — never silently. The resulting slug is always reported in changes. Rejects an unsaved auto-draft target (auto_draft): save the page first.',
    'params'      => [
        'post_id' => ['type' => 'int',    'required' => true],
        'slug'    => ['type' => 'string', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $not_auto_draft = _pp_reject_auto_draft($params['post_id']);
        if (is_wp_error($not_auto_draft)) {
            return $not_auto_draft;
        }
        if (sanitize_title($params['slug']) === '') {
            return new WP_Error('invalid_slug', 'Slug must not be empty after sanitization.');
        }
        return true;
    },
    'preview' => function (array $params): array {
        $current_permalink = get_permalink($params['post_id']);
        $sanitized = sanitize_title($params['slug']);
        return _pp_action_preview('update_page_slug', 'page', ['post_id' => $params['post_id']], $current_permalink, $sanitized, [
            ['path' => 'slug', 'from' => $current_permalink, 'to' => "sanitized to '{$sanitized}' (WordPress may de-duplicate further on collision — not reflected in preview)"],
        ]);
    },
    'execute' => function (array $params): array {
        $current_permalink = get_permalink($params['post_id']);
        $result = pp_update_page_slug($params['post_id'], $params['slug']);
        if (is_wp_error($result)) {
            return _pp_action_error('update_page_slug', 'page', $result->get_error_message());
        }
        return _pp_action_result('update_page_slug', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'slug', 'from' => $current_permalink, 'to' => $result, 'permalink' => get_permalink($params['post_id'])],
        ]);
    },
]);

// ── Action: update_seo_meta ──────────────────────────────────────────────────
// Scope: page | Semantics: patch

pp_register_action('update_seo_meta', [
    'scope'       => 'page',
    // Page metadata: needs only that the page EXISTS, not a populated composition (#358).
    'requires_composition' => false,
    'description' => 'Sets page-specific SEO metadata: meta_description, seo_title (overrides the rendered <title> tag), canonical_url, and the per-page social-title overrides og_title and twitter_title (#468). og_title overrides the Open Graph title for this page (falls back to seo_title, then the post title); twitter_title overrides the Twitter-card title (falls back to the og_title chain). Both are optional and capped at 200 chars like seo_title. There is no per-page og_description (the page meta_description already fills og:description, falling back to the site-wide pp_og_default_description) or per-page og_image (set the site-wide pp_og_image via update_site_option). The first-class, safe-surface alternative to hand-patching theme PHP for per-page metadata (#41).',
    'semantics'   => 'Patch. meta is shallow-merged into existing SEO metadata; unspecified keys are left unchanged. Set a key to "" to clear it. Accepted keys: meta_description, seo_title, canonical_url, og_title, twitter_title. Rejects an unsaved auto-draft target (auto_draft): save the page first.',
    'params'      => [
        'post_id' => ['type' => 'int',   'required' => true],
        'meta'    => ['type' => 'array', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $not_auto_draft = _pp_reject_auto_draft($params['post_id']);
        if (is_wp_error($not_auto_draft)) {
            return $not_auto_draft;
        }
        return _pp_validate_seo_meta($params['meta']);
    },
    'preview' => function (array $params): array {
        $current = pp_get_seo_meta($params['post_id']);
        $after   = array_merge($current, $params['meta']);
        return _pp_action_preview('update_seo_meta', 'page', ['post_id' => $params['post_id']], $current, $after, [
            ['path' => 'seo_meta', 'from' => $current, 'to' => $after],
        ]);
    },
    'execute' => function (array $params): array {
        $current = pp_get_seo_meta($params['post_id']);
        $result = pp_update_seo_meta($params['post_id'], $params['meta']);
        if (is_wp_error($result)) {
            return _pp_action_error('update_seo_meta', 'page', $result->get_error_message());
        }
        $after = pp_get_seo_meta($params['post_id']);
        return _pp_action_result('update_seo_meta', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'seo_meta', 'from' => $current, 'to' => $after],
        ]);
    },
]);

// ── Action: create_redirect ──────────────────────────────────────────────────
// Scope: site | Semantics: create/replace a front-end redirect (#62)

pp_register_action('create_redirect', [
    'scope'       => 'site',
    'description' => 'Records a front-end redirect so a renamed/moved/deprecated path 301s (or 302s) to its canonical target instead of 404ing. Use after update_page_slug (#134) so the old URL keeps working. `to` must be same-site (a path like "/new-page" or an absolute URL on this site).',
    'semantics'   => 'Create/replace. `from` and `to` are normalized to paths (scheme/host/query/trailing-slash stripped) — a second create for the same `from` replaces it. Target is validated same-site only (external hosts, protocol-relative "//", and javascript:/data: are rejected). Rejects from == to and any chain that would loop. code defaults to 301; pass 302 for a temporary redirect. Redirects are DB-backed and survive theme updates.',
    'params'      => [
        'from' => ['type' => 'string', 'required' => true],
        'to'   => ['type' => 'string', 'required' => true],
        'code' => ['type' => 'int',    'required' => false],
    ],
    'validate' => function (array $params) {
        $from_norm = _pp_normalize_redirect_path($params['from']);
        if ($from_norm === '/') {
            return new WP_Error('invalid_redirect_source', 'Redirect source must be a non-root path.');
        }
        $code = isset($params['code']) ? (int) $params['code'] : 301;
        if (!in_array($code, [301, 302], true)) {
            return new WP_Error('invalid_redirect_code', 'Redirect status code must be 301 or 302.');
        }
        $target_valid = _pp_validate_redirect_target((string) $params['to']);
        if (is_wp_error($target_valid)) {
            return $target_valid;
        }
        if ($from_norm === _pp_normalize_redirect_path((string) $params['to'])) {
            return new WP_Error('redirect_loop', 'A redirect source and target must differ.');
        }
        if (_pp_redirect_would_loop($from_norm, (string) $params['to'], pp_get_redirects())) {
            return new WP_Error('redirect_loop', 'This redirect would create a loop.');
        }
        return true;
    },
    'preview' => function (array $params): array {
        $from_norm = _pp_normalize_redirect_path($params['from']);
        $code = isset($params['code']) ? (int) $params['code'] : 301;
        $existing = pp_get_redirects();
        $before = $existing[$from_norm] ?? null;
        $after = ['to' => trim((string) $params['to']), 'code' => $code];
        return _pp_action_preview('create_redirect', 'site', ['from' => $from_norm], $before, $after, [
            ['path' => $from_norm, 'from' => $before, 'to' => $after],
        ]);
    },
    'execute' => function (array $params): array {
        $code = isset($params['code']) ? (int) $params['code'] : 301;
        $result = pp_create_redirect((string) $params['from'], (string) $params['to'], $code);
        if (is_wp_error($result)) {
            return _pp_action_error('create_redirect', 'site', $result->get_error_message());
        }
        return _pp_action_result('create_redirect', 'site', ['from' => $result], [
            ['path' => $result, 'from' => null, 'to' => ['to' => trim((string) $params['to']), 'code' => $code]],
        ]);
    },
]);

// ── Action: remove_redirect ──────────────────────────────────────────────────
// Scope: site | Semantics: delete a front-end redirect (#62)

pp_register_action('remove_redirect', [
    'scope'       => 'site',
    'description' => 'Removes a front-end redirect by its source path, restoring prior behavior (the source 404s or resolves normally again).',
    'semantics'   => 'Delete. `from` is normalized the same way create_redirect stores it. A no-op (no redirect for that source) returns ok with removed=false.',
    'params'      => [
        'from' => ['type' => 'string', 'required' => true],
    ],
    'validate' => function (array $params) {
        if (_pp_normalize_redirect_path($params['from']) === '/') {
            return new WP_Error('invalid_redirect_source', 'Redirect source must be a non-root path.');
        }
        return true;
    },
    'preview' => function (array $params): array {
        $from_norm = _pp_normalize_redirect_path($params['from']);
        $existing = pp_get_redirects();
        $before = $existing[$from_norm] ?? null;
        return _pp_action_preview('remove_redirect', 'site', ['from' => $from_norm], $before, null, [
            ['path' => $from_norm, 'from' => $before, 'to' => null],
        ]);
    },
    'execute' => function (array $params): array {
        $from_norm = _pp_normalize_redirect_path($params['from']);
        $before = pp_get_redirects()[$from_norm] ?? null;
        $removed = pp_remove_redirect((string) $params['from']);
        return _pp_action_result('remove_redirect', 'site', ['from' => $from_norm], [
            ['path' => $from_norm, 'from' => $before, 'to' => null, 'removed' => $removed],
        ]);
    },
]);

// ── Action: list_redirects ───────────────────────────────────────────────────
// Scope: site | Semantics: read-only listing of front-end redirects (#62)

pp_register_action('list_redirects', [
    'scope'       => 'site',
    'description' => 'Lists all front-end redirects (source path → target + status code). Read-only.',
    'semantics'   => 'Read-only. Never mutates. Returns the current redirect map under changes.redirects.',
    'params'      => [],
    'validate' => function (array $params) {
        return true;
    },
    'preview' => function (array $params): array {
        $redirects = pp_get_redirects();
        return _pp_action_preview('list_redirects', 'site', [], $redirects, $redirects, [
            ['path' => 'redirects', 'from' => $redirects, 'to' => $redirects],
        ]);
    },
    'execute' => function (array $params): array {
        $redirects = pp_get_redirects();
        return _pp_action_result('list_redirects', 'site', [], [
            ['path' => 'redirects', 'redirects' => $redirects, 'count' => count($redirects)],
        ]);
    },
]);

// ── Action: update_composition ──────────────────────────────────────────────
// Scope: page | Semantics: replace entire composition array

pp_register_action('update_composition', [
    'scope'          => 'page',
    'mutates_composition' => true,
    // This action POPULATES the composition, so requiring a non-empty one as a
    // precondition would strand a page created empty by create_page — it could
    // never be filled through the operate surface (#358). It needs only the page
    // to exist (checked in validate via _pp_validate_page_exists).
    'requires_composition' => false,
    'impact_warning' => 'Replaces entire page composition',
    'description' => 'Replaces the entire composition array for a page. Populates a page created empty (its composition need not already exist). Each item is {"component": "name", "props": {...}}.',
    'semantics'   => 'Replace. The full composition array is replaced. Pass the complete array, not a partial update. Items use {"component", "props"} shape. The composition must be a JSON ARRAY (a list) of components, never an object keyed by position — {"1": {...}, "3": {...}} is refused with unexpected_shape (#724); order in the array is the render order.',
    'params'      => [
        'post_id'          => ['type' => 'int',   'required' => true],
        'composition'      => ['type' => 'array', 'required' => true],
        'expected_version' => _pp_expected_version_param(),
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $params['composition'] = pp_normalize_composition($params['composition']);
        return pp_validate_composition($params['composition']);
    },
    'preview' => function (array $params): array {
        $params['composition'] = pp_normalize_composition($params['composition']);
        // NOT pp_get_composition() (#836). This action is admitted on a page whose stored
        // composition will not decode, so the before side has to be able to SAY that
        // instead of degrading it to []. See _pp_composition_before_state().
        $current = _pp_composition_before_state($params['post_id']);
        return _pp_action_preview('update_composition', 'page', ['post_id' => $params['post_id']], $current, $params['composition'], [
            ['path' => 'composition', 'from' => $current, 'to' => $params['composition']],
        ]);
    },
    'execute' => function (array $params): array {
        $params['composition'] = pp_normalize_composition($params['composition']);
        // Read BEFORE the write below, so the receipt reports the state this call replaced
        // and no cached value can go stale under it (#836).
        $current = _pp_composition_before_state($params['post_id']);
        $result = pp_update_composition($params['post_id'], $params['composition'], _pp_action_expected_version($params));
        if (is_wp_error($result)) {
            return _pp_action_error('update_composition', 'page', $result->get_error_message(), $result->get_error_code());
        }
        return _pp_action_result('update_composition', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'composition', 'from' => $current, 'to' => $params['composition']],
        ]);
    },
]);

// ── Action: publish_page ────────────────────────────────────────────────────
// Scope: page | Semantics: sets post_status to 'publish'

pp_register_action('publish_page', [
    'scope'       => 'page',
    // Page lifecycle: acts on post_status, needs only that the page EXISTS (#358).
    'requires_composition' => false,
    'description' => 'Publishes a page (sets post_status to publish).',
    'semantics'   => 'Sets post_status to "publish". Idempotent on already-published pages. Rejects an unsaved auto-draft target (auto_draft): save the page first.',
    'params'      => [
        'post_id' => ['type' => 'int', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $not_auto_draft = _pp_reject_auto_draft($params['post_id']);
        if (is_wp_error($not_auto_draft)) {
            return $not_auto_draft;
        }
        return true;
    },
    'preview' => function (array $params): array {
        $post = get_post($params['post_id']);
        $from = $post ? $post->post_status : 'unknown';
        return _pp_action_preview('publish_page', 'page', ['post_id' => $params['post_id']], $from, 'publish', [
            ['path' => 'post_status', 'from' => $from, 'to' => 'publish'],
        ]);
    },
    'execute' => function (array $params): array {
        $post = get_post($params['post_id']);
        $from = $post ? $post->post_status : 'unknown';
        $result = pp_publish_page($params['post_id']);
        if (is_wp_error($result)) {
            return _pp_action_error('publish_page', 'page', $result->get_error_message());
        }
        return _pp_action_result('publish_page', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'post_status', 'from' => $from, 'to' => 'publish'],
        ]);
    },
]);

// ── Action: add_component ───────────────────────────────────────────────────
// Scope: page | Semantics: append (or insert at position)

pp_register_action('add_component', [
    'scope'       => 'page',
    'mutates_composition' => true,
    'description' => 'Adds a component to a page composition. Optionally accepts style to set per-instance style slots on the new component in the same call (same item-scoped style map as composition items[].style; only schema-declared style slots are accepted, validated by the same shared engine).',
    'semantics'   => 'Append by default. If position is provided, insert at that index (0-based). Optional style is a map of slot name → value written onto the new item and validated via pp_validate_composition() (same rules as items[].style). Validates the resulting composition.',
    'params'      => [
        'post_id'          => ['type' => 'int',    'required' => true],
        'component'        => ['type' => 'string', 'required' => true],
        'props'            => ['type' => 'array',  'required' => true],
        'style'            => ['type' => 'array',  'required' => false],
        'position'         => ['type' => 'int',    'required' => false],
        'expected_version' => _pp_expected_version_param(),
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $new_item = ['component' => $params['component'], 'props' => $params['props']];
        // Optional per-instance style: written onto the new item so the SAME shared
        // engine that validates composition items[].style (item-scoped slots per
        // #306/#323) validates it here too — no surface-specific second validator.
        // Only set when non-empty, so an add_component WITHOUT style leaves the
        // stored item byte-identical to before (matches update_component's style).
        if (!empty($params['style'])) {
            $new_item['style'] = $params['style'];
        }
        // Validate the single new component. The _item() entry point is the one that
        // carries no band locator (#642): the item is not on the page yet, so no offset
        // in this call names a real band. Nothing is lost — this action judges only the
        // item it adds, so the rejection always belongs to the payload just submitted.
        $valid = pp_validate_composition_item($new_item);
        if (is_wp_error($valid)) {
            return $valid;
        }
        if (isset($params['position'])) {
            $composition = pp_get_composition($params['post_id']);
            $max = count($composition);
            if ($params['position'] < 0 || $params['position'] > $max) {
                return new WP_Error('invalid_position', sprintf('Position %d is out of bounds (0..%d).', $params['position'], $max));
            }
        }
        return true;
    },
    'preview' => function (array $params): array {
        $current   = pp_get_composition($params['post_id']);
        // Stored exactly as authored (#604): no prop-key rewriting on the way in,
        // so the preview shows the bytes execute will actually persist.
        $new_item  = ['component' => $params['component'], 'props' => $params['props']];
        if (!empty($params['style'])) {
            $new_item['style'] = $params['style'];
        }
        $after     = $current;
        if (isset($params['position'])) {
            array_splice($after, $params['position'], 0, [$new_item]);
        } else {
            $after[] = $new_item;
        }
        return _pp_action_preview('add_component', 'page', ['post_id' => $params['post_id']], $current, $after, [
            ['path' => 'composition', 'from' => count($current) . ' components', 'to' => count($after) . ' components'],
        ]);
    },
    'execute' => function (array $params): array {
        $current   = pp_get_composition($params['post_id']);
        // Stored exactly as authored (#604). A retired prop name is not healed here;
        // it is rejected upstream by the shared validator's unknown_prop gate, so the
        // authoring agent learns the canonical name instead of being silently repaired.
        $new_item  = ['component' => $params['component'], 'props' => $params['props']];
        if (!empty($params['style'])) {
            $new_item['style'] = $params['style'];
        }
        $after     = $current;
        if (isset($params['position'])) {
            array_splice($after, $params['position'], 0, [$new_item]);
        } else {
            $after[] = $new_item;
        }
        $result = pp_update_composition($params['post_id'], $after, _pp_action_expected_version($params));
        if (is_wp_error($result)) {
            return _pp_action_error('add_component', 'page', $result->get_error_message(), $result->get_error_code());
        }
        return _pp_action_result('add_component', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'composition', 'from' => count($current) . ' components', 'to' => count($after) . ' components'],
        ]);
    },
]);

// ── Action: remove_component ────────────────────────────────────────────────
// Scope: page | Semantics: remove by index, validates index in bounds

pp_register_action('remove_component', [
    'scope'          => 'page',
    'mutates_composition' => true,
    'impact_warning' => 'Removes component from page',
    'description' => 'Removes a component from a page composition. Accepts component_id (an authored id prop, or the auto-generated pp-<hex8> — note auto-generated ids do not survive a full update_composition re-apply) or component_index (0-based). component_id takes precedence when both are provided.',
    'semantics'   => 'Remove by component_id or 0-based index. Validates target is valid. Remaining components shift down.',
    'params'      => [
        'post_id'          => ['type' => 'int',    'required' => true],
        'component_index'  => ['type' => 'int',    'required' => false],
        'component_id'     => ['type' => 'string', 'required' => false],
        'expected_version' => _pp_expected_version_param(),
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }

        // Resolve component_id → component_index
        $resolved = _pp_resolve_id_param($params, $params['post_id']);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $composition = pp_get_composition($params['post_id']);
        $count = count($composition);
        if ($params['component_index'] < 0 || $params['component_index'] >= $count) {
            return new WP_Error('index_out_of_bounds', sprintf('Component index %d is out of bounds (0..%d).', $params['component_index'], $count - 1));
        }
        return true;
    },
    'preview' => function (array $params): array {
        _pp_resolve_id_param($params, $params['post_id']);
        $current = pp_get_composition($params['post_id']);
        $removed = $current[$params['component_index']];
        $after   = $current;
        array_splice($after, $params['component_index'], 1);
        return _pp_action_preview('remove_component', 'page', ['post_id' => $params['post_id']], $current, $after, [
            ['path' => 'composition[' . $params['component_index'] . ']', 'from' => $removed['component'], 'to' => null],
        ]);
    },
    'execute' => function (array $params): array {
        _pp_resolve_id_param($params, $params['post_id']);
        $current = pp_get_composition($params['post_id']);
        $removed = $current[$params['component_index']];
        $after   = $current;
        array_splice($after, $params['component_index'], 1);
        $result = pp_update_composition($params['post_id'], $after, _pp_action_expected_version($params));
        if (is_wp_error($result)) {
            return _pp_action_error('remove_component', 'page', $result->get_error_message(), $result->get_error_code());
        }
        return _pp_action_result('remove_component', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'composition[' . $params['component_index'] . ']', 'from' => $removed['component'], 'to' => null],
        ]);
    },
]);

// ── Action: restore_composition ─────────────────────────────────────────────
// Scope: page | Semantics: rewrite the composition to a prior history entry (#133)

/**
 * Reports what CURRENT validation rules say about a composition, without blocking it (#233).
 *
 * Built for the mutation surfaces that legitimately write a composition the action layer's
 * write-time validation would reject. The original one was restore_composition: undo is
 * wired to it (assets/js/pp-ai-chat.js), so a restore that current rules refuse would make
 * undo fail exactly when a user most needs it. Instead the write proceeds and the caller is
 * told what is wrong with what it just restored.
 *
 * FOUR CALLERS TODAY, and the reason has widened past "writes that would be rejected":
 *   restore_composition (#233)                    preview + execute, bounded (#654)
 *   the run-scoped rollback (#236, operate.php)   same contract, bounded PER POST (#654)
 *   the read-only CLI diagnostics (cli.php)       `check page` / `validate site` — the
 *                                                 ONE deliberately unbounded surface, by
 *                                                 ruling; it is the complete report every
 *                                                 truncation tail points at
 *   every ACCEPTED composition write (#687)       bounded, plus a 1 MiB availability gate
 * Three of the four bound what they CARRY through the one shared helper
 * (_pp_bounded_findings). This assembler stays unbounded itself — it reports what the
 * engines found, and each caller decides what it can hold. Putting the cap in here would
 * bind the surface that must not have one.
 * The last one is not a "would be rejected" surface. It exists because a write can be
 * fully legal and still paint nothing (a #580 inert_slot), and because the item-scoped
 * actions validate only what they touch, so they legitimately accept a write onto a page
 * whose OTHER bands current rules reject. Every rule that lands in the two engines below
 * is inherited by all four for free — that is the point of there being one of these.
 *
 * Reads the two shared engines rather than deriving a third view of the rules — a second
 * surface with its own idea of what is legal is the root cause of #223. Every future rule
 * (#147 prop-key allowlist, #151 calc/clamp, #154 image-prop allowlist, #230 color validator)
 * lands in those engines and is reported here for free.
 *
 *   pp_validate_composition_errors()  -> severity 'error'    (collect-all; would block a write)
 *   pp_validate_composition_smells()  -> severity 'warning'  (advisory; never blocks a write)
 *
 * `index` is the composition offset for BOTH kinds of finding (#622). Smells always carried
 * one; errors used to be hardcoded to null, which left the operator of a page with two `cta`
 * bands unable to tell which one was dead. Errors now read it from the WP_Error data
 * pp_validate_composition_errors() stamps. It stays null for a cross-item error
 * (duplicate_component_id), which belongs to no single band and names every colliding index
 * in its message — an honest "no single band owns this", not a fabricated 0.
 *
 * `severity` separates "a write-time rule rejects this" from "advisory". It is payload, and
 * every consumer renders it: the read-only CLI diagnostics (`wp pp check page`,
 * `wp pp validate site`) split on it, and the chat's undo card picks the per-item class from
 * it (#622). The restore itself is still never blocked (#233) — the card's header still says
 * the restore succeeded; only the per-finding styling tells errors from advisories.
 *
 * @param  array $items  Decoded composition array, already normalized.
 * @return array[]       Each: ['type' => string, 'severity' => string, 'message' => string,
 *                       'index' => int|null]. Empty when the composition is clean.
 */
function _pp_composition_findings(array $items): array {
    $findings = [];

    foreach (pp_validate_composition_errors($items) as $error) {
        $findings[] = [
            'type'     => $error->get_error_code(),
            'severity' => 'error',
            'message'  => $error->get_error_message(),
            'index'    => pp_composition_error_index($error),
        ];
    }

    // ADVISORIES NEED A COMPOSITION TO BE ADVISORY ABOUT (#724). No gate here on purpose:
    // all three engines answer the container question themselves — pp_validate_composition_errors()
    // returns one `unexpected_shape` finding and stops, pp_validate_composition_smells() and
    // pp_validate_composition_styling() return []. A guard at this call site would have been a
    // caller protecting a shared engine, leaving the next caller free to reopen the fabricated
    // `index %d` locator on an object-shaped container. This assembler stays a pure join.
    foreach (pp_validate_composition_smells($items) as $smell) {
        $findings[] = [
            'type'     => $smell['type'],
            'severity' => 'warning',
            'message'  => $smell['message'],
            'index'    => $smell['index'],
        ];
    }

    return $findings;
}

/**
 * Most findings an accepted-write envelope carries before truncation (#687, D1 clause 3).
 *
 * The ratified per-report budget. Deliberately far above any page an operator authors —
 * the realistic 6-band composition this gate measured produces 6 — so a truncated report
 * means the composition is pathological, not that the budget is tight.
 */
const PP_WRITE_FINDINGS_BUDGET = 100;

/**
 * Largest stored composition, in bytes of JSON, that an accepted write will diagnose
 * (#687, D1 Addendum #2).
 *
 * THIS IS AN AVAILABILITY GATE, NOT A BUDGET, and the two are unrelated mechanisms.
 * PP_WRITE_FINDINGS_BUDGET bounds a report that was already built. This bounds whether the
 * report is built AT ALL, and it has to, because the count budget cannot help here: both
 * engines run to completion and materialise every finding BEFORE _pp_bounded_findings()
 * ever sees the array.
 *
 * WHAT GOES WRONG WITHOUT IT. The engines are invoked AFTER pp_update_composition() has
 * written the meta, bumped the version and pushed the history ring. Measured on this repo,
 * the transient findings array costs roughly 28 MB per MB of stored composition (40,000
 * broken bands = 1.55 MB stored produced a 44 MB peak). On a page big enough that exhausts
 * memory_limit — and an OOM is a fatal, not an exception, so it cannot be caught and
 * degraded. The write has already landed at that point, so the caller gets no envelope for
 * a change that happened: WP-CLI never records the touched post (a run-scoped restore goes
 * blind) and the chat never refreshes its CAS baseline (every later write false-conflicts).
 * The page becomes uneditable through the action layer — the exact repair loop the
 * diagnostic exists to enable. Report-only must never be able to take down the write it is
 * reporting on.
 *
 * WHY 1 MiB. At the threshold the projected peak is ~28 MB, comfortably under a 128 MB
 * memory_limit with room for the rest of the request. Realistic pages are ~200x smaller:
 * the six-band composition this gate measured stores about 5 KB. So the gate is unreachable
 * for anything an operator authors, and a page that trips it is one an operator needs
 * `wp pp check page` for anyway.
 *
 * NO FILTER, NO OPTION, NO OVERRIDE. A tunable here would be a config surface for a safety
 * limit, and a site that tuned it up would reintroduce the fatal on the one path that must
 * not have one. The number moves by a ruling, not by configuration.
 */
const PP_WRITE_FINDINGS_MAX_STORED_BYTES = 1048576;

/**
 * Bounds a findings report, closing a truncated one with a single honest tail (#687).
 *
 * WHY A POST-HOC SLICE AND NOT AN ENGINE LIMIT. pp_validate_composition_errors() takes a
 * `$limit` that feeds _pp_claim_item_finding()'s budget, but that gate does NOT bound the
 * whole report: the four structural band rules (missing `component` key, non-scalar
 * `component`, unknown component, template-owned chrome, in lib/admin.php) emit
 * BEFORE any claim, the cross-item duplicate_component_id emits after the loop, and
 * pp_validate_composition_smells() has no budget at all. Passing a limit would therefore
 * cap only SOME rules while the engine kept emitting others, and — worse — the engine
 * would stop counting, so the "N more" tail could no longer state the TRUE total. Slicing
 * the assembled report is the only way to bound what the envelope CARRIES regardless of
 * which rule produced each finding, and to keep the total exact.
 *
 * THIS IS A COUNT BOUND, AND ONLY A COUNT BOUND. Say it precisely, because two other
 * things it does NOT bound are easy to assume:
 *
 *   NOT engine work. Both engines run to completion before this is called, so a
 *   pathological composition costs exactly what it did before to VALIDATE. What is bounded
 *   is everything DOWNSTREAM: the array retained on the envelope, the JSON the CLI prints,
 *   the payload the chat AJAX ships, and the per-finding rendering a consumer does.
 *   Bounding the engines themselves is a change to the engines, not to this helper — and
 *   #715 made exactly that change one layer down (pp_is_list(), lib/wp.php), which removed
 *   the O(N²) band-locator rescan this note used to name. The engines are now O(N); they
 *   still run to completion, so THE COUNT BUDGET STILL DOES NOT BOUND MEMORY. That is why
 *   the accepted-write path needs PP_WRITE_FINDINGS_MAX_STORED_BYTES as a separate gate,
 *   and why the restore/rollback surfaces (#654) — which report AFTER their write has
 *   landed and so can strand an envelope for a change that already happened — carry the
 *   count budget but not that gate. Extending it to them is a #233 posture change with its
 *   own issue; it is not this helper's to assume.
 *
 *   NOT bytes, and NOT only for raw-written data. A finding MESSAGE can reflect stored
 *   bytes uncapped (`Unknown component: "%s"`, the style-slot "has no style slot" family),
 *   and one message is worse than uncapped-per-value: `duplicate_component_id` enumerates
 *   EVERY colliding index in a SINGLE entry, so its length grows with the band count and
 *   no per-entry budget can contain it. 100 entries is therefore not 100 bounded strings.
 *
 *   Do not assume this needs a hostile raw meta write. It is reachable through plain,
 *   fully validated action-layer calls: `add_component` validates only the item it adds,
 *   so N add_component calls carrying the same authored `props.id` are ALL accepted and
 *   leave a page whose every later accepted write carries an O(N) duplicate-id message
 *   (measured: 120 add_component calls -> a 632-byte error message plus its 658-byte
 *   advisory twin in an 11.5 KB envelope; 20,000 bands -> ~129 KB in one entry).
 *   WriteEnvelopeFindingsTest pins that reachability so this note cannot rot back into
 *   the comfortable version.
 *
 *   Capping what a MESSAGE reflects is still not this helper's axis — it is the uniform
 *   bounding #647/#649 owns, it applies equally to `wp pp check page` and restore's
 *   report, and the #687 addendum records it as a separate ruling.
 *
 * The tail is the "and N more" contract _pp_render_undeclared_prop_keys() already uses
 * (lib/admin.php): the count it names is the TRUE total, so a truncated report never
 * reads as a complete one. It is severity `warning` because that is the honest severity
 * for an advisory about the REPORT rather than a rule any composition broke, and because
 * every generic findings consumer branches on that value — the CLI splits `=== 'error'`
 * from everything else (_pp_cli_page_diagnostics(), lib/cli.php) and the chat picks a
 * per-item class from it (ppChatFindingClass). A made-up severity would not FAIL closed,
 * it would drift: the CLI would file it under smells anyway while the chat styled it as
 * something nobody defined.
 *
 * `index` is null: the truncation belongs to no band, the same honest "no single band
 * owns this" the cross-item rules use (#622).
 *
 * ORDERING CONSEQUENCE, stated because it is a real limit and not a bug: findings arrive
 * errors-then-smells (see _pp_composition_findings), so a composition with more than
 * PP_WRITE_FINDINGS_BUDGET error-severity findings truncates before its advisories —
 * including inert_slot. The ratified budget is a flat per-report cap, not a per-severity
 * quota, and interleaving would change what the CLI diagnostics and restore already
 * render. A page in that state is telling the operator something louder than an advisory.
 *
 * The pointer at the complete report names the ACTUAL page when the caller knows it, so
 * the tail is a command an operator can paste rather than one they have to fill in.
 *
 * THAT POINTER IS LOAD-BEARING, AND IT IS WHY `wp pp check page` IS NOT BOUNDED. Every
 * production caller now names a page — the write path, restore preview, restore execute,
 * and the run rollback, which bounds PER POST precisely so it always has one to name
 * (lib/operate.php). `check page` is the surface this sentence sends them to, so it
 * reports completely; capping it would falsify this message everywhere at once and leave
 * the product with no complete report at all. See _pp_cli_page_diagnostics() for the full
 * carve-out. The $post_id parameter stays optional for direct/unit callers that genuinely
 * have no single page, not because any production caller lacks one.
 *
 * @param  array    $findings  A _pp_composition_findings() report.
 * @param  int|null $post_id   The page the report describes, when one page owns it.
 * @param  int      $budget    Maximum findings to carry before the truncation tail.
 *                             Clamped at 0: a negative budget would slice from the END of
 *                             the report and print a nonsense count.
 * @return array[]             At most $budget findings, plus one findings_truncated entry
 *                             when (and only when) the report was longer than $budget.
 */
function _pp_bounded_findings(array $findings, ?int $post_id = null, int $budget = PP_WRITE_FINDINGS_BUDGET): array {
    $budget = max(0, $budget);
    $total  = count($findings);
    if ($total <= $budget) {
        return $findings;
    }

    $bounded   = array_slice($findings, 0, $budget);
    $bounded[] = [
        'type'     => 'findings_truncated', // sibling species: see _pp_write_findings_for()
        'severity' => 'warning',
        'message'  => sprintf(
            'Showing %d of %d findings and %d more were omitted. Run `%s` for the complete report.',
            $budget,
            $total,
            $total - $budget,
            $post_id === null
                ? 'wp pp check page --post_id=<id>'
                : 'wp pp check page --post_id=' . $post_id
        ),
        'index'    => null,
        // THE TRUE TOTAL, STRUCTURALLY (#654). The message has always stated it in prose;
        // this states it in a field, because a consumer that RENDERS A COUNT cannot parse
        // prose and must not fall back to counting the array it was handed. The chat undo
        // card did exactly that (findings.length), so bounding restore turned "20,001
        // issues" into "101" — a report understating itself by two orders of magnitude on
        // the operator's only non-CLI view of what an undo brought back.
        //
        // Additive and severity-neutral: every existing consumer ignores the key, the
        // message text is unchanged and still byte-identical to #687's ratified wording,
        // and `total` is present ONLY on a truncation entry — so `total ?? count($findings)`
        // is the correct read everywhere, and an absent key honestly means "nothing was
        // omitted". Deliberately not added to findings_skipped: nothing was counted there,
        // and a zero would read as a clean bill of health.
        'total'    => $total,
    ];

    return $bounded;
}

/**
 * The `findings` report for one accepted composition write (#687, D1 Addendum #2).
 *
 * The whole write-path report in one place: read the stored composition once, decide
 * whether it is safe to diagnose, then either diagnose-and-bound it or return the single
 * honest entry saying why it was not diagnosed.
 *
 *   stored meta ──► size gate ──┬── over ──► [ findings_skipped ]   engines NEVER run
 *                               │
 *                               └── under ─► errors + smells ──► _pp_bounded_findings()
 *
 * THE GATE SITS BEFORE THE ENGINES, deliberately, and that ordering is the entire point —
 * see PP_WRITE_FINDINGS_MAX_STORED_BYTES. Gating after them would measure the allocation
 * that already blew up. Nothing between the gate and the engines may grow to depend on the
 * report having been built.
 *
 * The skip entry is the same species as `findings_truncated`: ONE entry, honest about what
 * it is not telling you, carrying the exact next command. It is a present finding rather
 * than an empty array on purpose — an empty array is documented (AI_CONTEXT.md,
 * ai-instructions/operating-loop.md) as "the write did what you asked", and a skipped
 * report knows nothing of the sort. A skip must never read as a clean bill of health.
 *
 * SIZE IS MEASURED ON THE STORED BYTES whenever the row holds JSON, which is the normal
 * case: one strlen on a string the write immediately above just put in the object cache.
 * The re-encode fallback covers only the defensive branch where a caller persisted an
 * already-decoded array (pp_get_composition_result(), lib/wp.php) — and a corrupt row that
 * decodes to nothing measures as the empty composition it reports as, which is correct.
 *
 * @param  int $post_id  The page this write landed on.
 * @return array[]       A bounded findings report, or exactly one findings_skipped entry.
 */
function _pp_write_findings_for(int $post_id): array {
    $stored      = pp_get_composition_result($post_id);
    $composition = $stored['composition'];
    $bytes       = is_string($stored['raw'])
        ? strlen($stored['raw'])
        : strlen((string) wp_json_encode($composition));

    if ($bytes > PP_WRITE_FINDINGS_MAX_STORED_BYTES) {
        return [[
            'type'     => 'findings_skipped',
            'severity' => 'warning',
            'message'  => sprintf(
                'Composition diagnostics were skipped for this write: the page stores %d bytes of composition JSON, over the %d-byte limit for reporting on a write. Nothing here says the composition is healthy. Run `wp pp check page --post_id=%d` for the full report.',
                $bytes,
                PP_WRITE_FINDINGS_MAX_STORED_BYTES,
                $post_id
            ),
            'index'    => null,
        ]];
    }

    return _pp_bounded_findings(_pp_composition_findings($composition), $post_id);
}

/**
 * Resolves the target history-ring index for a restore_composition call (#133).
 *
 * The history ring (pp_get_composition_history) is oldest-first, so the most recent
 * prior state is the LAST entry. Two selectors:
 *   - history_index: absolute 0-based index into the ring (takes precedence).
 *   - steps_back:    1 = most recent prior state (last entry), 2 = the one before it,
 *                    … N = the oldest retained entry. Defaults to 1.
 * Returns the resolved absolute index, or a WP_Error when the ring is empty, the
 * selector is out of range, or the entry it names is not replayable.
 *
 * THE ONE CHOKEPOINT for every resolution the restore contract performs — validate,
 * preview and execute each resolve through here, and since #829 so does execute's
 * confirming re-resolution inside the write lock — which is why the #818 raw-entry refusal
 * lives here rather than being spelled four times. A raw entry holds prior stored BYTES that
 * did not decode to a composition (see pp_get_composition_history), so there is nothing
 * to replay: the refusal is a PRECONDITION failure of the same species as `no_history`
 * and `history_out_of_bounds` below it.
 *
 * #233 IS UNTOUCHED BY THAT. Its contract is "restore is never blocked by CURRENT
 * VALIDATION RULES — it restores verbatim and REPORTS what came back". That is a
 * different axis: every entry that CARRIES a composition still restores verbatim and
 * still reports, however illegal today's rules find it. This refusal says the selected
 * slot holds no composition at all, and it says where to read the bytes instead — the
 * alternative was to keep destroying them, which is the bug #818 closes.
 *
 * The raw entry stays ADDRESSABLE on purpose: it occupies its ring slot, so
 * `history_index` and `steps_back` keep counting writes truthfully and an operator can
 * step straight past it to the last good composition.
 *
 * WHY THIS STAYS A ONE-LINE PREDICATE AND NOT A SHAPE CHECK (#841). The class that used to
 * slip past it — a prior that decoded to a JSON OBJECT — is answered where the entry FORM is
 * decided, in _pp_normalize_history_ring() (lib/wp.php), not here. Every stage below reaches
 * this resolver through pp_get_composition_history(), so by the time an entry arrives it has
 * already been classified once; re-deciding the form here would give the CLI listing and this
 * refusal two different opinions about the same row, which is exactly how the mis-filed entry
 * came to report `restorable: true` while fataling on selection.
 *
 * @param array $history  The history ring from pp_get_composition_history().
 * @param array $params   Action params (may carry history_index and/or steps_back).
 * @return int|WP_Error
 */
function _pp_resolve_history_target(array $history, array $params) {
    $count = count($history);
    if ($count === 0) {
        return new WP_Error('no_history', 'No composition history exists for this page; nothing to restore.');
    }
    if (isset($params['history_index'])) {
        $idx = (int) $params['history_index'];
        if ($idx < 0 || $idx >= $count) {
            return new WP_Error('history_out_of_bounds', sprintf('history_index %d is out of bounds (0..%d).', $idx, $count - 1));
        }
        return _pp_reject_unreplayable_history_entry($history, $idx, $params);
    }
    $steps = isset($params['steps_back']) ? (int) $params['steps_back'] : 1;
    if ($steps < 1 || $steps > $count) {
        return new WP_Error('history_out_of_bounds', sprintf('steps_back %d is out of range (1..%d).', $steps, $count));
    }
    return _pp_reject_unreplayable_history_entry($history, $count - $steps, $params);
}

/**
 * Passes a resolved ring index through, or refuses it when the entry holds preserved
 * BYTES rather than a composition (#818).
 *
 * The message has one job beyond refusing: say that the bytes still exist and where to
 * read them. A refusal that only said "cannot restore" would leave the operator exactly
 * where the destroyed-bytes bug left them — knowing something is unrecoverable, with no
 * route to it — which is the failure #818 exists to end.
 *
 * @param array $history  The ring from pp_get_composition_history().
 * @param int   $idx      An index already proven in-bounds by the caller.
 * @param array $params   Action params (for the post_id the message names).
 * @return int|WP_Error
 */
function _pp_reject_unreplayable_history_entry(array $history, int $idx, array $params) {
    if (!pp_history_entry_is_raw($history[$idx])) {
        return $idx;
    }
    return new WP_Error(
        'history_entry_not_restorable',
        sprintf(
            // CAUSE-NEUTRAL WORDING. Not "undecodable": a raw entry also covers both
            // sub-cases of unexpected_shape — a valid-JSON SCALAR, and since #841 a
            // valid-JSON OBJECT — which decode fine and are not compositions. An
            // operator whose page was classified unexpected_shape must not read a
            // message describing a state they are not in — the read path and the write
            // path have to name the same state the same way (#650/#652/#725).
            'History entry %d (steps_back %d) holds stored bytes that did not decode to a composition '
            . '(%d bytes as this ring holds them), so it cannot be replayed as one. The bytes were '
            . 'preserved rather than discarded: read them with '
            . '`wp pp operate composition-history --post_id=%d`, which states which byte views are '
            . 'exact. To roll the page back to a real composition, select an earlier entry.',
            $idx,
            count($history) - $idx,
            strlen($history[$idx]['raw']),
            (int) ($params['post_id'] ?? 0)
        )
    );
}

/**
 * Confirms, from INSIDE the write lock, that the selector still names the entry this
 * restore resolved before the lock (#829).
 *
 * THE HOLE THIS CLOSES, and it is a SELECTION hole rather than a durability one. Execute
 * resolves `steps_back` / `history_index` against pp_get_composition_history() — the CACHED
 * reader — and only afterwards calls pp_update_composition(), which is where the per-post
 * advisory lock opens. A concurrent write landing in that window pushes a ring entry, and
 * the selector's meaning moves with it:
 *
 *     request B                    request A            `_pp_composition_history` row
 *     ────────────────────         ─────────────        ─────────────────────────────
 *     lists the ring, picks                             [ e1, e2 ]
 *       steps_back=1  (= e2)
 *     resolves against its
 *       warm cached copy [e1,e2]
 *                                  pushes e3            [ e1, e2, e3 ]
 *     writes                                            ← steps_back=1 now means e3
 *
 * B replayed e2 and reported `ok: true`. Both readings of that are bad: e2 is not what
 * `steps_back=1` means any more, and e3 is not what the operator chose. So the fix is not
 * "re-resolve inside the lock" on its own — re-resolving a RELATIVE selector against a moved
 * ring hands back e3, which is precisely "whichever snapshot the ring held after a
 * concurrent write". Both halves are required: resolve AUTHORITATIVELY (through
 * _pp_read_composition_history_locked(), the same in-lock reader #823 gave the rebuild), and
 * require that resolution to name the entry the operator actually addressed. Agreement makes
 * `ok: true` mean what it says. Disagreement is refused, and nothing is written.
 *
 * WHY EXECUTE ONLY, AND NOT ALL THREE STAGES. Validate and preview do not write, so opening
 * the write lock in them would serialize every preview of a hot page against its writers and
 * still guarantee nothing — the ring can move between a preview and its execute no matter how
 * the preview read it. What the operator needs is not a locked preview; it is that execute
 * writes the snapshot the preview named or refuses. That is what this is, and it makes the
 * preview/execute agreement ENFORCED rather than assumed.
 *
 * WHY CONTENT EQUALITY IS IDENTITY HERE. Both rings come from the one normalizer
 * _pp_normalize_history_ring(), which builds every entry as
 * ['timestamp','version','hash'] + ['composition'|'raw'] — deterministic key order,
 * deterministic types — so a strict `===` is a sound deep comparison and the strictest
 * honest one available. It cannot be fooled by a duplicate, either: `version` is the
 * writer's pre-write version counter (pp_update_composition), which is strictly monotonic
 * per post, so two entries minted by the writer can never carry the same one.
 *
 * ONE REFUSAL CODE, NOT FOUR. When the ring has moved, the in-lock re-resolution can fail in
 * three further ways — the ring emptied (`no_history`), it shrank past the selector
 * (`history_out_of_bounds`), or the slot the selector NOW names holds preserved bytes
 * (`history_entry_not_restorable`). Returning those codes here would name the wrong state:
 * the operator did not select a preserved-bytes slot, the ring moved under them, and the read
 * path and the write path have to name the same state the same way (#650/#652/#725). So every
 * disagreement is `history_target_shifted` and the authoritative reason travels in the
 * message. Those three codes keep their meaning at validate and preview, where they describe
 * the selector the operator actually sent against the ring they actually saw.
 *
 * PRECONDITION, NOT VALIDATION — so #233 is untouched. This says nothing about the contents
 * of any snapshot; it says the slot named is not the slot addressed. Same species as
 * `no_history` and `history_out_of_bounds`. Every entry carrying a composition still restores
 * verbatim and still reports whatever today's rules make of it.
 *
 * STATED LIMIT, inherited from the reader and honest about it: when there is no usable
 * database handle, or the authoritative read FAILS, _pp_read_composition_history_locked()
 * falls back to the cached ring (returning [] there would wipe up to ten slots — see its
 * docblock). The fallback then agrees with the caller's own cached read by construction, so
 * this gate passes and the restore behaves exactly as it did before #829. The window is
 * closed when the authoritative read succeeds, and not otherwise; a database blip degrades
 * to the old behavior rather than refusing every restore. The degraded read logs a line.
 *
 * THE RING ARRIVES AS A PARAMETER, read once by pp_update_composition() inside the lock and
 * shared with the ring rebuild that follows. Re-reading it here would decode the whole row a
 * second time inside the critical section — tens of milliseconds at the composition size
 * ceiling, paid by every concurrent writer to this post — and would leave the confirmation and
 * the rebuild looking at two reads rather than provably the same one.
 *
 * @param array $params     Action params (post_id, and the selector to re-resolve).
 * @param array $addressed  The normalized ring entry the pre-lock resolution selected.
 * @param array $locked     The authoritative in-lock ring, from pp_update_composition().
 * @return true|WP_Error
 */
function _pp_restore_target_still_addressed(array $params, array $addressed, array $locked) {
    $post_id = (int) $params['post_id'];
    $idx     = _pp_resolve_history_target($locked, $params);

    if (!is_wp_error($idx) && $locked[$idx] === $addressed) {
        return true;
    }

    // ONE PRESCRIPTION, WHICH IS WHY THE SIBLING'S CODE TRAVELS AND ITS PROSE DOES NOT.
    // _pp_resolve_history_target()'s refusals are written for an operator who SELECTED that
    // slot, so they close with their own advice ("select an earlier entry"). Splicing that in
    // whole would hand back two prescriptions, the first of which is the one this refusal
    // deliberately does not give — the operator did not select this slot, the ring moved onto
    // it. Carry the authoritative CODE plus a short neutral clause; the outer message owns the
    // instruction.
    $reason = is_wp_error($idx)
        ? sprintf('that selector no longer resolves at all (%s).', $idx->get_error_code())
        : sprintf('that selector now names a different entry (index %d of %d).', $idx, count($locked));

    return new WP_Error(
        'history_target_shifted',
        sprintf(
            'The composition history of page %1$d changed while this restore was being prepared, so '
            . 'the entry you selected is no longer the entry your selector names. Nothing was written. '
            . 'Against the ring as it now stands, %2$s Another writer (a CLI action, the dashboard '
            . 'editor, or the AI chat) recorded a state on this page in the meantime. Re-read the ring '
            . 'with `wp pp operate composition-history --post_id=%1$d` and select again: steps_back '
            . 'counts backwards from the newest entry, so it names a different snapshot after every '
            . 'concurrent write, while a history_index taken from the current listing stays stable '
            . 'until the ring evicts. [history_target_shifted]',
            $post_id,
            $reason
        )
    );
}

pp_register_action('restore_composition', [
    'scope'          => 'page',
    'mutates_composition' => true,
    // Restore is NEVER blocked by current validation (#233): its precondition is
    // "the history ring has a target", enforced by its own validate — NOT "the
    // CURRENT composition is non-empty". Gating it on a non-empty current
    // composition would block restoring a page that was populated then cleared,
    // exactly the #233 contract violation. So it opts out of the #358 gate.
    'requires_composition' => false,
    'impact_warning' => 'Rewrites the page composition to a prior version',
    // THE REFUSAL CLAUSE LIVES IN `description`, NOT ONLY IN `semantics`, and that is
    // load-bearing (the #719 rule, pinned by CreatePageWriteFailureTest::
    // testTheRefusalContractReachesTheChatAIsActionCatalog). pp_ai_system_prompt()
    // builds the chat AI's action catalog from `description` (lib/ai-context.php) and
    // `wp pp action list` emits the same field; NOTHING at runtime reads `semantics`.
    // The chat's "Undo these changes" link is the surface most likely to select a
    // preserved-bytes slot, so declaring the refusal only in the declarative record
    // would leave the one caller that hits it untaught.
    'description' => 'Restores a page composition to a prior version recorded in its history ring. Select the target with steps_back (1 = most recent prior state, the default) or history_index (absolute 0-based). history_index takes precedence. A ring slot may instead hold stored bytes that did not decode to a composition — a composition is a JSON ARRAY, so bytes that were unparseable, or valid JSON that is a scalar or a JSON OBJECT, are not one. Those are preserved so that repairing a corrupt page cannot destroy the only copy of what was there; selecting that slot is refused with history_entry_not_restorable — read the bytes with `wp pp operate composition-history --post_id=<id>` and select an earlier entry. The selector is also confirmed against the ring at write time: if another writer records a state on the page between your selection and the write, the entry your selector names is no longer the entry you chose, so the restore is refused with history_target_shifted and nothing is written — re-read the ring and select again, preferring a history_index from the fresh listing because steps_back is relative and moves with every concurrent write.',
    'semantics'   => 'Rewrite. The composition is replaced with a prior snapshot captured before an earlier write. Restore is itself a conflict-checked write (records its own history entry), so it can be undone in turn. A ring slot can instead hold stored bytes that did not decode to a composition (a decode_error page, or either sub-case of unexpected_shape — a valid-JSON scalar, or a valid-JSON object), preserved so that repairing a corrupt page cannot destroy the only copy of what was there; selecting that slot is refused with history_entry_not_restorable — read the bytes with `wp pp operate composition-history --post_id=<id>` and select an earlier entry to roll back. Selection is resolved against the ring the caller read and CONFIRMED against the authoritative ring inside the write lock (#829): a concurrent write that moves what the selector names is refused with history_target_shifted rather than replayed, so whenever that authoritative read succeeds ok:true means the snapshot that was addressed and never whichever snapshot the ring held afterwards. If the authoritative read is not possible or fails, it logs and degrades to the pre-confirmation behavior rather than refusing, so the guarantee is conditional on that read.',
    'params'      => [
        'post_id'          => ['type' => 'int', 'required' => true],
        'steps_back'       => ['type' => 'int', 'required' => false],
        'history_index'    => ['type' => 'int', 'required' => false],
        'expected_version' => _pp_expected_version_param(),
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $history = pp_get_composition_history($params['post_id']);
        $target  = _pp_resolve_history_target($history, $params);
        if (is_wp_error($target)) {
            return $target;
        }
        return true;
    },
    'preview' => function (array $params): array {
        // NOT pp_get_composition() (#836): restore opts out of the #358 gate too, so it is
        // reachable on a page whose stored composition will not decode, and the before side
        // must name that rather than show []. See _pp_composition_before_state().
        $current = _pp_composition_before_state($params['post_id']);
        $history = pp_get_composition_history($params['post_id']);
        $idx     = _pp_resolve_history_target($history, $params);
        // validate() already gated this; guard defensively so preview never indexes null.
        // pp_normalize_composition() only strips empty style arrays now, so `after` is
        // the snapshot's own bytes and the findings below describe exactly what execute
        // will write (#233).
        //
        // NO NAME DECODING AT ALL (#603, #604). The slot-NAME map went in #603; the
        // `variant` -> `layout`/`theme` migration and the prop-KEY alias map went in
        // #604. A snapshot carrying a retired slot name, prop name or `variant` key now
        // restores VERBATIM, those declarations do not paint, and `findings` says so.
        // That is the #233 contract working, not a regression: restore reports and never
        // blocks, so the operator learns the declaration is dead instead of it being
        // silently rewritten under them.
        //
        // GRANULARITY (#621): a band reports EVERY problem it has, not its first. A band
        // with both a retired prop name and a dead style slot reports both here, so the
        // operator repairs the band in one pass instead of restoring, fixing, restoring
        // again. The unit is the authored location a message can name; the two deliberate
        // limits are a band whose identity is unusable (unknown component, chrome — one
        // finding, nothing below it is judgeable) and a `style` map, which still reports
        // its first dead slot only. See pp_validate_composition_errors().
        $target  = is_wp_error($idx)
            ? []
            : pp_normalize_composition($history[$idx]['composition']);
        $preview = _pp_action_preview('restore_composition', 'page', ['post_id' => $params['post_id']], $current, $target, [
            ['path' => 'composition', 'from' => $current, 'to' => $target],
        ]);
        // BOUNDED SINCE #654, and bounded HERE rather than inside the engines: the
        // cap is on what the envelope CARRIES, so #621's exhaustive-per-authored-
        // location contract is untouched below it. Preview and execute must agree —
        // a preview that reported 10,000 findings for a restore whose execute
        // reported 100 would be the preview/execute asymmetry #711 already tracks.
        //
        // NOT _pp_write_findings_for(): $target is a HISTORY-RING SNAPSHOT that is
        // not stored anywhere yet, and that helper reads post meta. Preview reports
        // on the bytes execute WILL write, which is the whole point of a preview.
        $preview['findings'] = _pp_bounded_findings(
            _pp_composition_findings($target),
            $params['post_id']
        );
        return $preview;
    },
    'execute' => function (array $params): array {
        // Read BEFORE the write below, for the same two reasons as the preview above (#836).
        $current = _pp_composition_before_state($params['post_id']);
        $history = pp_get_composition_history($params['post_id']);
        $idx     = _pp_resolve_history_target($history, $params);
        if (is_wp_error($idx)) {
            return _pp_action_error('restore_composition', 'page', $idx->get_error_message(), $idx->get_error_code());
        }
        // RESTORE IS VERBATIM (#604). pp_normalize_composition() strips empty style
        // arrays and nothing else — it no longer rewrites `type` -> `component`, and the
        // `variant` -> `layout`/`theme` migration and prop-KEY alias map that used to run
        // here are gone. No component is added, removed or reordered, and no name is
        // rewritten: chrome, retired slot names, retired prop names and a stored
        // `variant` all restore exactly as snapshotted and are reported below (#233).
        // Restore never blocks; it tells the operator what is dead.
        //
        // $addressed IS THE ANSWER `ok: true` WILL BE CLAIMING (#829). The resolution above
        // ran against the CACHED ring, which is right for picking (it is the ring the
        // operator listed and the ring preview reported on) and worthless as a guarantee: the
        // per-post write lock does not open until pp_update_composition() below. So the entry
        // itself is carried into the lock and confirmed there against the authoritative ring.
        // pp_normalize_composition() is a pure function of that entry — it strips empty style
        // arrays and nothing else — so once the two entries are proven identical, the bytes
        // written here are the bytes the authoritative ring holds.
        $addressed = $history[$idx];
        $target    = pp_normalize_composition($addressed['composition']);
        $result    = pp_update_composition(
            $params['post_id'],
            $target,
            _pp_action_expected_version($params),
            static function (array $locked) use ($params, $addressed) {
                return _pp_restore_target_still_addressed($params, $addressed, $locked);
            }
        );
        if (is_wp_error($result)) {
            return _pp_action_error('restore_composition', 'page', $result->get_error_message(), $result->get_error_code());
        }
        // Restore is never blocked by current validation rules, so it must never report a
        // bare ok:true for a composition those rules reject. It is NOT named `validation` —
        // the AJAX handler (lib/ai-chat.php) already occupies that key with
        // pp_post_apply_validate() output.
        //
        // SINCE #687 `findings` IS NO LONGER RESTORE-ONLY: pp_execute_action() attaches it
        // to every accepted composition write. This line still matters, and setting it HERE
        // is what makes it matter — the dispatcher skips a result that already carries the
        // key, so restore owns its own report rather than inheriting the write path's.
        //
        // SINCE #654 THAT REPORT IS BOUNDED at the same PP_WRITE_FINDINGS_BUDGET, closed by
        // the same findings_truncated tail, from the same helper. One budget system, one
        // owner — a second cap with its own number is how two surfaces start disagreeing
        // about what "the whole report" means. What restore does NOT inherit is Addendum
        // #2's 1 MiB availability gate: that one stops the engines from running at all and
        // returns a single findings_skipped entry, which would change #233's premise from
        // "you are told exactly what an old snapshot brought back" to "sometimes you are
        // told nothing". That is a posture change needing its own ruling, filed as its own
        // issue — the post-write OOM it addresses is real and is NOT closed by this cap
        // (see _pp_bounded_findings(): both engines run to completion first).
        //
        // Findings describe $target, the array this call wrote. pp_update_composition() takes
        // it by value and injects props.id into its own copy, so $target stays id-free; no
        // validator or smell reads props.id, so the report matches the stored bytes either way.
        $result = _pp_action_result('restore_composition', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'composition', 'from' => $current, 'to' => $target],
        ]);
        $result['findings'] = _pp_bounded_findings(
            _pp_composition_findings($target),
            $params['post_id']
        );
        return $result;
    },
]);

// ── Action: reorder_components ──────────────────────────────────────────────
// Scope: page | Semantics: permutation, validated

pp_register_action('reorder_components', [
    'scope'       => 'page',
    'mutates_composition' => true,
    'description' => 'Reorders components in a page composition.',
    'semantics'   => 'Permutation. Order must be a valid permutation of 0..N-1 where N is the current composition length. No duplicates, no gaps, no out-of-bounds indices.',
    'params'      => [
        'post_id'          => ['type' => 'int',   'required' => true],
        'order'            => ['type' => 'array', 'required' => true],
        'expected_version' => _pp_expected_version_param(),
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $composition = pp_get_composition($params['post_id']);
        $count = count($composition);
        $order = $params['order'];

        if ($count === 0) {
            return new WP_Error('invalid_params', 'Cannot reorder: page has no components.');
        }

        if (count($order) !== $count) {
            return new WP_Error('invalid_order', sprintf('Order array has %d elements but composition has %d components.', count($order), $count));
        }

        // Check that order is a valid permutation of 0..N-1
        $sorted = $order;
        sort($sorted);
        $expected = range(0, $count - 1);
        if ($sorted !== $expected) {
            return new WP_Error('invalid_permutation', 'Order must be a permutation of 0..' . ($count - 1) . ' with no duplicates or gaps.');
        }

        return true;
    },
    'preview' => function (array $params): array {
        $current = pp_get_composition($params['post_id']);
        $after   = [];
        foreach ($params['order'] as $idx) {
            $after[] = $current[$idx];
        }
        return _pp_action_preview('reorder_components', 'page', ['post_id' => $params['post_id']], $current, $after, [
            ['path' => 'composition.order', 'from' => range(0, count($current) - 1), 'to' => $params['order']],
        ]);
    },
    'execute' => function (array $params): array {
        $current = pp_get_composition($params['post_id']);
        $after   = [];
        foreach ($params['order'] as $idx) {
            $after[] = $current[$idx];
        }
        $result = pp_update_composition($params['post_id'], $after, _pp_action_expected_version($params));
        if (is_wp_error($result)) {
            return _pp_action_error('reorder_components', 'page', $result->get_error_message(), $result->get_error_code());
        }
        return _pp_action_result('reorder_components', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'composition.order', 'from' => range(0, count($current) - 1), 'to' => $params['order']],
        ]);
    },
]);

// ── Action: update_component ────────────────────────────────────────────────
// Scope: section | Semantics: PATCH (not replace). Shallow merge. null removes a prop.

pp_register_action('update_component', [
    'scope'       => 'section',
    'mutates_composition' => true,
    'description' => 'Updates a single component\'s props via shallow merge (patch, not replace). Optionally accepts style to also update per-instance style slots in the same call. Accepts component_id (an authored id prop, or the auto-generated pp-<hex8> — note auto-generated ids do not survive a full update_composition re-apply) or component_index (0-based). component_id takes precedence when both are provided.',
    'semantics'   => 'Patch. Props are shallow-merged into existing props. Unspecified props unchanged. null removes a prop. Optional style param shallow-merges style slots (same as style_component). Validates the merged composition via pp_validate_composition(). Target component by component_id or component_index.',
    'params'      => [
        'post_id'          => ['type' => 'int',    'required' => true],
        'component_index'  => ['type' => 'int',    'required' => false],
        'component_id'     => ['type' => 'string', 'required' => false],
        'props'            => ['type' => 'array',  'required' => true],
        'style'            => ['type' => 'array',  'required' => false],
        'expected_version' => _pp_expected_version_param(),
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }

        // Resolve component_id → component_index
        $resolved = _pp_resolve_id_param($params, $params['post_id']);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $composition = pp_get_composition($params['post_id']);
        $count = count($composition);

        if ($params['component_index'] < 0 || $params['component_index'] >= $count) {
            return new WP_Error('index_out_of_bounds', sprintf('Component index %d is out of bounds (0..%d).', $params['component_index'], $count - 1));
        }

        // Merge and validate the result
        $merged = _pp_merge_component_props(
            $composition[$params['component_index']]['props'] ?? [],
            $params['props']
        );
        $test_composition = $composition;
        $test_composition[$params['component_index']]['props'] = $merged;

        // Validate optional style param.
        if (!empty($params['style'])) {
            $merged_style = _pp_merge_component_props(
                $composition[$params['component_index']]['style'] ?? [],
                $params['style']
            );
            $test_composition[$params['component_index']]['style'] = $merged_style;
        }

        return pp_validate_composition($test_composition);
    },
    'preview' => function (array $params): array {
        _pp_resolve_id_param($params, $params['post_id']);
        $composition = pp_get_composition($params['post_id']);
        $before_props = $composition[$params['component_index']]['props'] ?? [];
        // Mirror execute (#604): the incoming patch is merged verbatim. No prop-key
        // rewriting happens on either side, so the preview's reported "after" and
        // `changes` are the exact shape that will be stored.
        $after_props  = _pp_merge_component_props($before_props, $params['props']);

        $changes = _pp_diff_props($before_props, $after_props, $params['component_index']);

        if (!empty($params['style'])) {
            $before_style = $composition[$params['component_index']]['style'] ?? [];
            $after_style  = _pp_merge_component_props($before_style, $params['style']);
            $changes = array_merge($changes, _pp_diff_style($before_style, $after_style, $params['component_index']));
        }

        return _pp_action_preview('update_component', 'section',
            ['post_id' => $params['post_id'], 'component_index' => $params['component_index']],
            $before_props, $after_props, $changes
        );
    },
    'execute' => function (array $params): array {
        _pp_resolve_id_param($params, $params['post_id']);
        $composition  = pp_get_composition($params['post_id']);
        $before_props = $composition[$params['component_index']]['props'] ?? [];
        // Merge the patch verbatim (#604). No prop-key rewriting runs on the incoming
        // patch, on the stored props, or on the merged result: the composition arrives
        // from pp_get_composition() exactly as stored, and is written back exactly as
        // merged. A retired prop name in the patch is rejected upstream by the shared
        // validator's unknown_prop gate rather than being silently redirected onto its
        // canonical prop, so `changes` is a truthful record of the write and stored
        // bytes always match what the caller asked for.
        $after_props = _pp_merge_component_props($before_props, $params['props']);

        $composition[$params['component_index']]['props'] = $after_props;

        $changes = _pp_diff_props($before_props, $after_props, $params['component_index']);

        // Merge optional style.
        if (!empty($params['style'])) {
            $before_style = $composition[$params['component_index']]['style'] ?? [];
            $after_style  = _pp_merge_component_props($before_style, $params['style']);
            if (empty($after_style)) {
                unset($composition[$params['component_index']]['style']);
            } else {
                $composition[$params['component_index']]['style'] = $after_style;
            }
            $changes = array_merge($changes, _pp_diff_style($before_style, $after_style, $params['component_index']));
        }

        $result = pp_update_composition($params['post_id'], $composition, _pp_action_expected_version($params));
        if (is_wp_error($result)) {
            return _pp_action_error('update_component', 'section', $result->get_error_message(), $result->get_error_code());
        }

        return _pp_action_result('update_component', 'section',
            ['post_id' => $params['post_id'], 'component_index' => $params['component_index']],
            $changes
        );
    },
]);

// ── Action: trash_page ─────────────────────────────────────────────────────
// Scope: page | Semantics: moves page to trash (reversible)

pp_register_action('trash_page', [
    'scope'       => 'page',
    // Page lifecycle: DELETES the page, needs only that it EXISTS. Requiring a
    // populated composition would strand an empty page — undeletable AND
    // unpopulatable through the operate surface (#358).
    'requires_composition' => false,
    'description' => 'Moves a page to the trash (reversible, does not permanently delete). Works on a page created empty (no composition required).',
    'semantics'   => 'Moves post_status to "trash". Reversible via restore_page. Rejects an unsaved auto-draft target (auto_draft): an auto-draft is not a real page (WordPress GCs it on its own).',
    'params'      => [
        'post_id' => ['type' => 'int', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $not_auto_draft = _pp_reject_auto_draft($params['post_id']);
        if (is_wp_error($not_auto_draft)) {
            return $not_auto_draft;
        }
        $post = get_post($params['post_id']);
        if ($post->post_status === 'trash') {
            return new WP_Error('already_trashed', 'Page is already in the trash.');
        }
        return true;
    },
    'preview' => function (array $params): array {
        $post = get_post($params['post_id']);
        $from = $post ? $post->post_status : 'unknown';
        return _pp_action_preview('trash_page', 'page', ['post_id' => $params['post_id']], $from, 'trash', [
            ['path' => 'post_status', 'from' => $from, 'to' => 'trash'],
        ]);
    },
    'execute' => function (array $params): array {
        $post = get_post($params['post_id']);
        $from = $post ? $post->post_status : 'unknown';
        $result = wp_trash_post($params['post_id']);
        if (!$result) {
            return _pp_action_error('trash_page', 'page', 'Failed to trash page.');
        }
        return _pp_action_result('trash_page', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'post_status', 'from' => $from, 'to' => 'trash'],
        ]);
    },
]);

// ── Action: restore_page ──────────────────────────────────────────────────
// Scope: page | Semantics: restores a trashed page

pp_register_action('restore_page', [
    'scope'       => 'page',
    // Page lifecycle: acts on post_status, needs only that the page EXISTS (#358).
    'requires_composition' => false,
    'description' => 'Restores a page from the trash back to its previous status.',
    'semantics'   => 'Restores a trashed page. Only works on pages with post_status "trash".',
    'params'      => [
        'post_id' => ['type' => 'int', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $post = get_post($params['post_id']);
        if ($post->post_status !== 'trash') {
            return new WP_Error('not_trashed', 'Page is not in the trash.');
        }
        return true;
    },
    'preview' => function (array $params): array {
        return _pp_action_preview('restore_page', 'page', ['post_id' => $params['post_id']], 'trash', 'draft', [
            ['path' => 'post_status', 'from' => 'trash', 'to' => 'restored'],
        ]);
    },
    'execute' => function (array $params): array {
        $result = wp_untrash_post($params['post_id']);
        if (!$result) {
            return _pp_action_error('restore_page', 'page', 'Failed to restore page.');
        }
        $post = get_post($params['post_id']);
        $new_status = $post ? $post->post_status : 'draft';
        return _pp_action_result('restore_page', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'post_status', 'from' => 'trash', 'to' => $new_status],
        ]);
    },
]);

// ── Action: unpublish_page ────────────────────────────────────────────────
// Scope: page | Semantics: reverts a published page to draft

pp_register_action('unpublish_page', [
    'scope'       => 'page',
    // Page lifecycle: acts on post_status, needs only that the page EXISTS (#358).
    'requires_composition' => false,
    'description' => 'Reverts a published page back to draft status.',
    'semantics'   => 'Sets post_status from "publish" to "draft". Only works on published pages.',
    'params'      => [
        'post_id' => ['type' => 'int', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $post = get_post($params['post_id']);
        if ($post->post_status !== 'publish') {
            return new WP_Error('not_published', 'Page is not published (current status: ' . $post->post_status . ').');
        }
        return true;
    },
    'preview' => function (array $params): array {
        return _pp_action_preview('unpublish_page', 'page', ['post_id' => $params['post_id']], 'publish', 'draft', [
            ['path' => 'post_status', 'from' => 'publish', 'to' => 'draft'],
        ]);
    },
    'execute' => function (array $params): array {
        $result = wp_update_post(['ID' => $params['post_id'], 'post_status' => 'draft'], true);
        if (is_wp_error($result)) {
            return _pp_action_error('unpublish_page', 'page', $result->get_error_message());
        }
        return _pp_action_result('unpublish_page', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'post_status', 'from' => 'publish', 'to' => 'draft'],
        ]);
    },
]);

// ── Action: style_component ────────────────────────────────────────────────
// Scope: section | Semantics: patch (shallow merge, null removes)
// Updates per-instance style slot overrides via schema-validated CSS custom properties.

pp_register_action('style_component', [
    'scope'       => 'section',
    'mutates_composition' => true,
    'description' => 'Updates a component instance\'s per-instance style overrides via shallow merge. Optionally accepts a recipe name that expands into slot values (explicit style overrides recipe slots). Use wp pp operate inspect-composition --post_id=<id> to see available slots and recipes.',
    'semantics'   => 'Patch. Recipe expands first, then explicit style values override. null removes a slot. Validates against schema.json style_slots for the target component type.',
    'params'      => [
        'post_id'          => ['type' => 'int',    'required' => true],
        'component_id'     => ['type' => 'string', 'required' => false],
        'component_index'  => ['type' => 'int',    'required' => false],
        'style'            => ['type' => 'array',  'required' => false],
        'recipe'           => ['type' => 'string', 'required' => false],
        'expected_version' => _pp_expected_version_param(),
    ],
    'validate' => function (array $params) {
        if (empty($params['style']) && empty($params['recipe'])) {
            return new WP_Error('missing_style', 'Either style or recipe is required.');
        }

        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }

        $resolved = _pp_resolve_id_param($params, $params['post_id']);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $composition = pp_get_composition($params['post_id']);
        $count = count($composition);

        if ($params['component_index'] < 0 || $params['component_index'] >= $count) {
            return new WP_Error('index_out_of_bounds', sprintf('Component index %d is out of bounds (0..%d).', $params['component_index'], $count - 1));
        }

        $component_name = $composition[$params['component_index']]['component'] ?? '';
        $available_slots = pp_get_style_slots($component_name);

        if (empty($available_slots)) {
            return new WP_Error('no_style_slots', sprintf('Component "%s" has no declared style slots.', $component_name));
        }

        // Expand recipe if provided.
        if (!empty($params['recipe'])) {
            $recipes = pp_get_style_recipes($component_name);
            if (!isset($recipes[$params['recipe']])) {
                $available_recipes = implode(', ', array_keys($recipes));
                return new WP_Error('invalid_recipe', sprintf(
                    'Component "%s" has no recipe "%s". Available: %s',
                    $component_name, $params['recipe'], $available_recipes ?: '(none)'
                ));
            }
        }

        // Build merged style (recipe + explicit overrides) and validate all slots.
        $merged = _pp_expand_recipe_and_merge($params, $component_name);

        // The candidate set this pass draws from, filtered ONCE rather than inside the
        // loop: recipe slots ∪ explicit style, minus the `__recipe` tracking key (not
        // a CSS property) and minus null values (a removal, not a value to check).
        // Same skips as before, same order, same first-error-wins result — the loop
        // still returns at the FIRST undeclared slot, so keys after it are candidates
        // that were never reached, not keys this pass approved.
        //
        // Hoisting it out of the loop is what lets the rejection below report the set
        // it drew from (#626). The chat's friendly-error builder used to re-derive
        // that set from $params['style'] alone — pre-expansion, `__recipe` included —
        // so a recipe that drifted out of its component's declared slots produced a
        // rejection naming a slot the builder had never heard of, and the builder
        // answered by describing a different set than the one that failed.
        $candidates = [];
        foreach ($merged as $slot_name => $slot_value) {
            if ($slot_name === '__recipe' || $slot_value === null) {
                continue;
            }
            $candidates[$slot_name] = $slot_value;
        }

        foreach ($candidates as $slot_name => $slot_value) {
            if (!isset($available_slots[$slot_name])) {
                // Stamped with the context above, so the chat error builder can answer
                // from THIS pass instead of reading the composition a second time — a
                // write landing in between could otherwise make the response describe a
                // component that never rejected anything (#626).
                return _pp_invalid_style_slot_error(
                    $component_name,
                    (string) $slot_name,
                    $available_slots,
                    array_keys($candidates)
                );
            }
            $slot_type = $available_slots[$slot_name]['type'] ?? null;
            $slot_allowed = $available_slots[$slot_name]['values'] ?? null;
            $validation = _pp_validate_token_value((string) $slot_value, $slot_type, $slot_allowed);
            if (is_wp_error($validation)) {
                return new WP_Error('invalid_style_value', sprintf(
                    'Style slot "%s": %s', $slot_name, $validation->get_error_message()
                ));
            }
        }

        return true;
    },
    'preview' => function (array $params): array {
        _pp_resolve_id_param($params, $params['post_id']);
        $composition    = pp_get_composition($params['post_id']);
        $component_name = $composition[$params['component_index']]['component'] ?? '';
        $before_style   = $composition[$params['component_index']]['style'] ?? [];
        $merged_input   = _pp_expand_recipe_and_merge($params, $component_name);
        $after_style    = _pp_merge_component_props($before_style, $merged_input);

        return _pp_action_preview('style_component', 'section',
            ['post_id' => $params['post_id'], 'component_index' => $params['component_index']],
            $before_style, $after_style,
            _pp_diff_style($before_style, $after_style, $params['component_index'])
        );
    },
    'execute' => function (array $params): array {
        _pp_resolve_id_param($params, $params['post_id']);
        $composition    = pp_get_composition($params['post_id']);
        $component_name = $composition[$params['component_index']]['component'] ?? '';
        $before_style   = $composition[$params['component_index']]['style'] ?? [];
        $merged_input   = _pp_expand_recipe_and_merge($params, $component_name);
        $after_style    = _pp_merge_component_props($before_style, $merged_input);

        if (empty($after_style)) {
            unset($composition[$params['component_index']]['style']);
        } else {
            $composition[$params['component_index']]['style'] = $after_style;
        }

        $result = pp_update_composition($params['post_id'], $composition, _pp_action_expected_version($params));
        if (is_wp_error($result)) {
            return _pp_action_error('style_component', 'section', $result->get_error_message(), $result->get_error_code());
        }

        return _pp_action_result('style_component', 'section',
            ['post_id' => $params['post_id'], 'component_index' => $params['component_index']],
            _pp_diff_style($before_style, $after_style, $params['component_index'])
        );
    },
]);

// ── Action: clear_custom_css ───────────────────────────────────────────────
// Scope: site | Semantics: removes all Custom CSS from the WordPress Customizer
// Params: none
// Rationale: Custom CSS creates split visual authority with theme CSS.
// After conflict detection flags issues, this action closes the loop.

pp_register_action('clear_custom_css', [
    'scope'          => 'site',
    'impact_warning' => 'Removes ALL Custom CSS',
    'description' => 'Removes all Custom CSS from the WordPress Customizer. Use after conflict detection flags split-authority issues.',
    'semantics'   => 'Destructive clear. No params. Removes wp_get_custom_css() content entirely.',
    'params'      => [],
    'validate' => function (array $params) {
        $css = wp_get_custom_css();
        if (!$css || !trim($css)) {
            return new WP_Error('no_custom_css', 'No Custom CSS to clear.');
        }
        return true;
    },
    'preview' => function (array $params): array {
        $current = wp_get_custom_css();
        return _pp_action_preview('clear_custom_css', 'site', [], $current, '', [
            ['path' => 'custom_css', 'from' => 'present', 'to' => 'empty'],
        ]);
    },
    'execute' => function (array $params): array {
        $post = wp_get_custom_css_post();
        if ($post) {
            wp_update_post([
                'ID'           => $post->ID,
                'post_content' => '',
            ]);
        }
        return _pp_action_result('clear_custom_css', 'site', [], [
            ['path' => 'custom_css', 'from' => 'present', 'to' => 'empty'],
        ]);
    },
]);

// ── Action: create_menu ─────────────────────────────────────────────────────
// Scope: site | Semantics: create. Issue 132.

pp_register_action('create_menu', [
    'scope'       => 'site',
    'description' => 'Creates a new navigation menu.',
    'semantics'   => 'Create. Fails if a menu with this name already exists (WordPress core menu-name uniqueness).',
    'params'      => [
        'name' => ['type' => 'string', 'required' => true],
    ],
    'validate' => function (array $params) {
        if (trim($params['name']) === '') {
            return new WP_Error('empty_name', 'Menu name cannot be empty.');
        }
        return true;
    },
    'preview' => function (array $params): array {
        return _pp_action_preview('create_menu', 'site', [], null, $params['name'], [
            ['path' => 'menu', 'from' => null, 'to' => $params['name']],
        ]);
    },
    'execute' => function (array $params): array {
        $menu_id = pp_create_nav_menu($params['name']);
        if (is_wp_error($menu_id)) {
            return _pp_action_error('create_menu', 'site', $menu_id->get_error_message());
        }
        return _pp_action_result('create_menu', 'site', ['menu_id' => $menu_id], [
            ['path' => 'menu', 'from' => null, 'to' => $params['name']],
        ]);
    },
]);

// ── Action: add_menu_item ───────────────────────────────────────────────────
// Scope: site | Semantics: append. Issue 132.

pp_register_action('add_menu_item', [
    'scope'       => 'site',
    'description' => 'Adds a link to a navigation menu — a page link (page_id) or a custom link (url + label).',
    'semantics'   => 'Append. Exactly one of page_id or (url + label) must be given, not both. Optional position sets menu order (1-based); omit to append at the end.',
    'params'      => [
        'menu_id'  => ['type' => 'int',    'required' => true],
        // Named page_id, not post_id: a top-level post_id param signals
        // "this action mutates that post" to the operate/preflight gate
        // (see OperateTest::testAllRegisteredActionsHaveConsistentScopeAndPostIdParam)
        // — here the linked page is data inside a site-scoped mutation
        // (the menu), not the post being mutated, so it must not collide.
        'page_id'  => ['type' => 'int',    'required' => false],
        'url'      => ['type' => 'string', 'required' => false],
        'label'    => ['type' => 'string', 'required' => false],
        'position' => ['type' => 'int',    'required' => false],
    ],
    'validate' => function (array $params) {
        if (!wp_get_nav_menu_object($params['menu_id'])) {
            return new WP_Error('invalid_menu', sprintf('Menu %d does not exist.', $params['menu_id']));
        }

        $has_page = !empty($params['page_id']);
        $has_url  = !empty($params['url']);

        if ($has_page && $has_url) {
            return new WP_Error('ambiguous_link', 'Provide either page_id or url + label, not both.');
        }
        if (!$has_page && !$has_url) {
            return new WP_Error('missing_link', 'Provide either page_id or url + label.');
        }

        if ($has_page) {
            $exists = _pp_validate_page_exists($params['page_id']);
            if (is_wp_error($exists)) {
                return $exists;
            }
        } else {
            if (!filter_var($params['url'], FILTER_VALIDATE_URL)) {
                return new WP_Error('invalid_url', 'url must be a valid URL.');
            }
            if (empty($params['label'])) {
                return new WP_Error('missing_label', 'label is required for a custom link.');
            }
        }

        return true;
    },
    'preview' => function (array $params): array {
        $label = !empty($params['page_id'])
            ? get_the_title($params['page_id'])
            : ($params['label'] ?? '');
        return _pp_action_preview('add_menu_item', 'site', ['menu_id' => $params['menu_id']], null, $label, [
            ['path' => 'menu_item', 'from' => null, 'to' => $label],
        ]);
    },
    'execute' => function (array $params): array {
        $item = !empty($params['page_id'])
            ? ['page_id' => (int) $params['page_id']]
            : ['url' => $params['url'], 'label' => $params['label']];
        if (isset($params['position'])) {
            $item['position'] = (int) $params['position'];
        }

        $item_id = pp_add_nav_menu_item((int) $params['menu_id'], $item);
        if (is_wp_error($item_id)) {
            return _pp_action_error('add_menu_item', 'site', $item_id->get_error_message());
        }

        $label = !empty($params['page_id']) ? get_the_title($params['page_id']) : $params['label'];
        return _pp_action_result('add_menu_item', 'site', ['menu_id' => $params['menu_id'], 'item_id' => $item_id], [
            ['path' => 'menu_item', 'from' => null, 'to' => $label],
        ]);
    },
]);

// ── Action: assign_menu_location ────────────────────────────────────────────
// Scope: site | Semantics: replace. Issue 132.

pp_register_action('assign_menu_location', [
    'scope'       => 'site',
    'description' => 'Assigns a menu to a registered theme navigation location (e.g. "primary", "footer").',
    'semantics'   => 'Replace. Whatever menu was previously assigned to this location is unassigned; the location must be a registered theme location.',
    'params'      => [
        'menu_id'  => ['type' => 'int',    'required' => true],
        'location' => ['type' => 'string', 'required' => true],
    ],
    'validate' => function (array $params) {
        if (!wp_get_nav_menu_object($params['menu_id'])) {
            return new WP_Error('invalid_menu', sprintf('Menu %d does not exist.', $params['menu_id']));
        }
        $registered = array_keys(get_registered_nav_menus());
        if (!in_array($params['location'], $registered, true)) {
            return new WP_Error('invalid_location', sprintf('"%s" is not a registered navigation location. Available: %s.', $params['location'], implode(', ', $registered)));
        }
        return true;
    },
    'preview' => function (array $params): array {
        $locations = get_nav_menu_locations();
        $current   = $locations[$params['location']] ?? null;
        return _pp_action_preview('assign_menu_location', 'site', ['location' => $params['location']], $current, $params['menu_id'], [
            ['path' => $params['location'], 'from' => $current, 'to' => $params['menu_id']],
        ]);
    },
    'execute' => function (array $params): array {
        $locations = get_nav_menu_locations();
        $current   = $locations[$params['location']] ?? null;

        $ok = pp_assign_menu_location((int) $params['menu_id'], $params['location']);
        if (!$ok) {
            return _pp_action_error('assign_menu_location', 'site', 'Failed to assign menu to location.');
        }
        return _pp_action_result('assign_menu_location', 'site', ['location' => $params['location']], [
            ['path' => $params['location'], 'from' => $current, 'to' => $params['menu_id']],
        ]);
    },
]);

// ── Action: set_menu ─────────────────────────────────────────────────────────
// Scope: site | Semantics: replace. Declarative, mirrors update_composition —
// the friendliest shape for an LLM to propose a whole menu in one step.

// Upper bound on dropdown children per top-level item (issue 381). This is an
// abuse guard, not a UX target — a usable dropdown is far smaller. Only the
// nested count is bounded; the flat top-level list stays uncapped (capping it
// would reject menus that are valid today).
if (!defined('PP_MENU_MAX_CHILDREN')) {
    define('PP_MENU_MAX_CHILDREN', 50);
}

pp_register_action('set_menu', [
    'scope'       => 'site',
    'description' => 'Declaratively sets a menu\'s full item list and (optionally) its location in one call. Creates the menu if a menu with this name does not already exist; replaces all its existing items with the given list. Each item may carry an optional "children" array of the same {page_id} or {url, label} shape to render as a one-level dropdown submenu (max one level of nesting; a child cannot itself have children).',
    'semantics'   => 'Replace. Each item in items is either {"page_id": int} or {"url": string, "label": string}, in the order given, and may include a "children" array of further items (same shape) to nest as a dropdown — exactly one level deep. Existing items on the (possibly pre-existing) menu are removed first.',
    'params'      => [
        'name'     => ['type' => 'string', 'required' => true],
        'items'    => ['type' => 'array',  'required' => true],
        'location' => ['type' => 'string', 'required' => false],
    ],
    'validate' => function (array $params) {
        if (trim($params['name']) === '') {
            return new WP_Error('empty_name', 'Menu name cannot be empty.');
        }
        if (empty($params['items'])) {
            return new WP_Error('empty_items', 'items cannot be empty.');
        }
        foreach ($params['items'] as $i => $item) {
            $item_error = _pp_validate_menu_item($item, sprintf('items[%d]', $i));
            if (is_wp_error($item_error)) {
                return $item_error;
            }
            // One level of nesting only (issue 381): a top-level item may carry
            // children, but a child may not (depth > 1 rejected loudly).
            if (isset($item['children'])) {
                if (!is_array($item['children'])) {
                    return new WP_Error('invalid_children', sprintf('items[%d]: children must be an array.', $i));
                }
                if (count($item['children']) > PP_MENU_MAX_CHILDREN) {
                    return new WP_Error('too_many_children', sprintf('items[%d]: a dropdown supports at most %d children (got %d).', $i, PP_MENU_MAX_CHILDREN, count($item['children'])));
                }
                foreach ($item['children'] as $j => $child) {
                    if (is_array($child) && isset($child['children'])) {
                        return new WP_Error('nesting_too_deep', sprintf('items[%d].children[%d]: menus support one level of nesting — a child cannot have its own children.', $i, $j));
                    }
                    $child_error = _pp_validate_menu_item($child, sprintf('items[%d].children[%d]', $i, $j));
                    if (is_wp_error($child_error)) {
                        return $child_error;
                    }
                }
            }
        }
        if (isset($params['location'])) {
            $registered = array_keys(get_registered_nav_menus());
            if (!in_array($params['location'], $registered, true)) {
                return new WP_Error('invalid_location', sprintf('"%s" is not a registered navigation location. Available: %s.', $params['location'], implode(', ', $registered)));
            }
        }
        return true;
    },
    'preview' => function (array $params): array {
        // Flatten parents + their children in render order for the preview
        // summary (issue 381).
        $titles = [];
        foreach ($params['items'] as $item) {
            $titles[] = _pp_menu_item_title($item);
            foreach (($item['children'] ?? []) as $child) {
                $titles[] = _pp_menu_item_title($child);
            }
        }
        return _pp_action_preview('set_menu', 'site', ['name' => $params['name']], null, $titles, [
            ['path' => 'menu', 'from' => null, 'to' => implode(', ', $titles)],
        ]);
    },
    'execute' => function (array $params): array {
        $existing = wp_get_nav_menu_object($params['name']);
        $menu_id  = $existing ? $existing->term_id : pp_create_nav_menu($params['name']);
        if (is_wp_error($menu_id)) {
            return _pp_action_error('set_menu', 'site', $menu_id->get_error_message());
        }

        // Replace semantics must be atomic at every entry point — not only
        // inside pp_ai_execute_batch()'s snapshot layer. Keep the previous
        // items so a mid-loop failure restores them instead of leaving the
        // menu gutted (single-step chat execute, wp pp action execute).
        $previous_items = [];
        if ($existing) {
            $previous_items = wp_get_nav_menu_items($menu_id);
            $previous_items = is_array($previous_items) ? array_values($previous_items) : [];
            pp_clear_nav_menu_items($menu_id);
        }

        // Shared failure handler for a parent OR child creation failure
        // (issue 381 added the child loop; both must roll back identically).
        // Inside a failed batch this restore is redundant (the batch snapshot
        // layer rebuilds the menu again — its id churn defeats the signature
        // skip). Accepted: the failed-batch path is rare and the final state
        // stays correct at every entry point.
        $fail = function (WP_Error $error) use ($existing, $menu_id, $previous_items) {
            if ($existing) {
                pp_clear_nav_menu_items($menu_id);
                $restore_errors = _pp_rebuild_menu_items($menu_id, $previous_items);
                if ($restore_errors !== []) {
                    return _pp_action_error('set_menu', 'site', $error->get_error_message()
                        . ' Restoring the previous menu items was also incomplete: '
                        . implode('; ', $restore_errors));
                }
            } else {
                // set_menu created this menu itself — a half-populated leftover
                // would break the atomicity contract just as much as a gutted
                // pre-existing menu.
                wp_delete_nav_menu($menu_id);
            }
            return _pp_action_error('set_menu', 'site', $error->get_error_message());
        };

        $titles = [];
        foreach ($params['items'] as $item) {
            $parent_item_id = pp_add_nav_menu_item($menu_id, _pp_menu_item_link($item));
            if (is_wp_error($parent_item_id)) {
                return $fail($parent_item_id);
            }
            $titles[] = _pp_menu_item_title($item);

            // One level of nesting (issue 381): children are created with their
            // parent's freshly-minted item id, so the dropdown structure and its
            // menu_item_parent links round-trip through snapshot/restore.
            foreach (($item['children'] ?? []) as $child) {
                $child_link = _pp_menu_item_link($child);
                $child_link['parent_id'] = (int) $parent_item_id;
                $child_item_id = pp_add_nav_menu_item($menu_id, $child_link);
                if (is_wp_error($child_item_id)) {
                    return $fail($child_item_id);
                }
                $titles[] = _pp_menu_item_title($child);
            }
        }

        if (!empty($params['location'])) {
            pp_assign_menu_location($menu_id, $params['location']);
        }

        return _pp_action_result('set_menu', 'site', ['menu_id' => $menu_id], [
            ['path' => 'menu', 'from' => null, 'to' => implode(', ', $titles)],
        ]);
    },
]);

// ── Internal helpers ────────────────────────────────────────────────────────

/**
 * Validates one set_menu item — a top-level item OR a dropdown child (issue
 * 132 / 381). $label is the human-readable path used in error messages
 * ("items[2]" or "items[2].children[0]") so the SAME link rules produce
 * correctly-scoped errors at either depth. Error codes match the original
 * inline top-level checks so existing callers/tests keep working.
 *
 * The depth-1 limit and the children[] bound are NOT enforced here; the
 * set_menu validator owns those (a child is validated by this helper as a
 * plain item).
 *
 * @param  mixed  $item   The item to validate (expected array).
 * @param  string $label  Error-message path prefix, e.g. "items[2]".
 * @return true|WP_Error
 */
function _pp_validate_menu_item($item, string $label) {
    if (!is_array($item)) {
        return new WP_Error('invalid_item', sprintf('%s must be an object.', $label));
    }
    $has_page = !empty($item['page_id']);
    $has_url  = !empty($item['url']);
    if ($has_page && $has_url) {
        return new WP_Error('ambiguous_item', sprintf('%s: provide either page_id or url + label, not both.', $label));
    }
    if (!$has_page && !$has_url) {
        return new WP_Error('missing_item_link', sprintf('%s: provide either page_id or url + label.', $label));
    }
    if ($has_page) {
        $exists = _pp_validate_page_exists($item['page_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
    } elseif (empty($item['label'])) {
        return new WP_Error('missing_item_label', sprintf('%s: label is required for a custom link.', $label));
    }
    return true;
}

/**
 * Normalizes a validated set_menu item into the link array pp_add_nav_menu_item
 * expects (issue 381). A validated item is exactly one of page_id or url+label.
 *
 * @param  array $item
 * @return array  ['page_id' => int] or ['url' => string, 'label' => string]
 */
function _pp_menu_item_link(array $item): array {
    return !empty($item['page_id'])
        ? ['page_id' => (int) $item['page_id']]
        : ['url' => $item['url'], 'label' => $item['label']];
}

/**
 * Display title for a validated set_menu item (issue 381) — the linked page's
 * title for a page item, or the custom link's label.
 *
 * @param  array $item
 * @return string
 */
function _pp_menu_item_title(array $item): string {
    return !empty($item['page_id']) ? get_the_title($item['page_id']) : ($item['label'] ?? '');
}

/**
 * Validates that a post_id refers to an existing page.
 * Used by all page-scoped and section-scoped action validators.
 *
 * @param int $post_id  WordPress post ID.
 * @return true|WP_Error
 */
function _pp_validate_page_exists(int $post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('not_found', sprintf('Page %d not found.', $post_id));
    }
    if ($post->post_type !== 'page') {
        return new WP_Error('not_a_page', sprintf('Post %d is not a page (type: %s).', $post_id, $post->post_type));
    }
    return true;
}

/**
 * Rejects a target whose post_status is still 'auto-draft' (#160).
 *
 * An 'auto-draft' is WordPress's hidden, ~7-day-GC'd placeholder for a page
 * that has never had a meaningful save — pp_composition_pages() (the AI chat's
 * "## Pages" inventory) excludes it by design, so it is NOT a "real" page an
 * action should assume exists. This guard is called by the mutating page
 * actions that assume a materialized page and must NOT create/promote one:
 * publish_page, trash_page, update_page_slug, update_seo_meta.
 *
 * Deliberately NOT folded into _pp_validate_page_exists(): update_composition
 * and update_page_title are the #121 promote-on-write path — a brand-new page's
 * first editor save legitimately targets its own auto-draft and is promoted to
 * 'draft' by pp_execute_action() after a successful write. Gating them here
 * would break "Add New Page" (see pp_composition_workspace_page / the AJAX save
 * handlers in lib/admin.php, and the promote pins in tests/ActionsTest.php).
 *
 * Callers run _pp_validate_page_exists() first, so a missing/non-page post is
 * already rejected before this runs; this guard only distinguishes auto-draft
 * from a real status. Defensive against a null/0 id: only rejects when an
 * actual post is found.
 *
 * @param int $post_id Post ID to check.
 * @return true|WP_Error  true if not an auto-draft, WP_Error('auto_draft') otherwise.
 */
function _pp_reject_auto_draft(int $post_id) {
    $post = $post_id ? get_post($post_id) : null;
    if ($post && $post->post_status === 'auto-draft') {
        return new WP_Error(
            'auto_draft',
            sprintf(
                'Page %d is an unsaved auto-draft, not a real page yet. Add a title or content to save it first.',
                $post_id
            )
        );
    }
    return true;
}

/**
 * Resolves a component_id to its composition index.
 *
 * Shared by _pp_resolve_id_param() below (mutates $params for action
 * validate callables) and _pp_resolve_component_index_for_error() in
 * lib/ai-chat.php (the chat-side error helper, which runs on raw
 * AI-submitted params and never sees the mutation the former makes) — one
 * source of truth for the id-to-index lookup so the two never drift (#123).
 *
 * @param int    $post_id      Post ID to read composition from.
 * @param string $component_id Component id to resolve.
 * @return int|WP_Error  Resolved index, or WP_Error if not found.
 */
function _pp_resolve_component_id_to_index(int $post_id, string $component_id) {
    $composition = pp_get_composition($post_id);
    $resolved    = pp_resolve_component_target($composition, ['component_id' => $component_id]);
    return is_wp_error($resolved) ? $resolved : $resolved['index'];
}

/**
 * Resolves component_id to component_index in action params.
 *
 * Call at the top of validate callables for actions that accept component_id.
 * Mutates $params in place: sets component_index from resolution result.
 *
 * Precedence: component_id > component_index. At least one must be provided.
 *
 * @param array &$params  Action params (mutated: component_index set on success).
 * @param int    $post_id Post ID to read composition from.
 * @return true|WP_Error  true on success, WP_Error on failure.
 */
function _pp_resolve_id_param(array &$params, int $post_id) {
    $has_id    = isset($params['component_id']) && $params['component_id'] !== '';
    $has_index = isset($params['component_index']);

    if (!$has_id && !$has_index) {
        return new WP_Error('missing_component_target', 'Either component_id or component_index is required.');
    }

    if ($has_id) {
        $index = _pp_resolve_component_id_to_index($post_id, $params['component_id']);
        if (is_wp_error($index)) {
            return $index;
        }
        $params['component_index'] = $index;
    }

    return true;
}

/**
 * Shallow-merges new props into existing props.
 * null values remove the key.
 */
function _pp_merge_component_props(array $existing, array $new): array {
    $merged = $existing;
    foreach ($new as $key => $value) {
        if ($value === null) {
            unset($merged[$key]);
        } else {
            $merged[$key] = $value;
        }
    }
    return $merged;
}

/**
 * Expands a recipe (if present) and merges with explicit style overrides.
 *
 * Recipe slots expand first, then explicit style values override.
 * Adds __recipe tracking key when a recipe is used.
 *
 * @param array  $params          Action params (may have 'recipe' and/or 'style').
 * @param string $component_name  Component name for recipe lookup.
 * @return array  Merged style array ready for shallow-merge into existing style.
 */
function _pp_expand_recipe_and_merge(array $params, string $component_name): array {
    $result = [];

    // Expand recipe slots first.
    if (!empty($params['recipe'])) {
        $recipes = pp_get_style_recipes($component_name);
        if (isset($recipes[$params['recipe']])) {
            $result = $recipes[$params['recipe']]['slots'] ?? [];
            $result['__recipe'] = $params['recipe'];
        }
    }

    // Explicit style overrides recipe slots.
    if (!empty($params['style'])) {
        foreach ($params['style'] as $key => $value) {
            $result[$key] = $value;
        }
    }

    return $result;
}

/**
 * Stamps an `invalid_style_slot` rejection with the context it was judged in (#626).
 *
 * The rejection's message says which slot was refused; the data says what it was
 * refused AGAINST — the component this validation pass resolved, the slots that
 * component declares, and the candidate keys the pass drew from (recipe-expanded,
 * `__recipe` and null removals already dropped). All four come from the ONE
 * composition read the validator already did.
 *
 * Read back with pp_rejected_slot_context() below. The two live together, as
 * _pp_composition_item_error() and pp_composition_error_index() do (lib/admin.php),
 * so the key names are written once: a consumer that re-derived this context from
 * its own second read is exactly the defect #626 fixed, and a consumer that
 * re-spelled the keys would be the same defect one layer down.
 *
 * @param  string $component_name   The component the pass resolved.
 * @param  string $slot_name        The slot it refused.
 * @param  array  $available_slots  That component's declared style_slots.
 * @param  array  $candidate_slots  Slot names the pass drew from, in order.
 * @return WP_Error
 */
function _pp_invalid_style_slot_error(
    string $component_name,
    string $slot_name,
    array $available_slots,
    array $candidate_slots
): WP_Error {
    return new WP_Error(
        'invalid_style_slot',
        sprintf(
            'Component "%s" has no style slot "%s". Available: %s',
            $component_name,
            $slot_name,
            implode(', ', array_keys($available_slots))
        ),
        [
            'component_name'  => $component_name,
            'available_slots' => $available_slots,
            'candidate_slots' => $candidate_slots,
        ]
    );
}

/**
 * Reads the context stamped by _pp_invalid_style_slot_error(), or null (#626).
 *
 * Null means "no authoritative answer in hand" — a rejection built by hand, or by a
 * producer of this error code that stamps nothing (the shared engine
 * _pp_validate_style_slot_map() in lib/admin.php, whose rejections travel through
 * composition validation rather than through the chat's style_component branch).
 * Consumers fall back to whatever they can derive themselves, which is best-effort
 * by definition: it describes the world as it reads NOW, not the world the rejection
 * was made in.
 *
 * Every field is checked for presence AND usable type, and the two that can render
 * as a claim about the component are also checked for emptiness. A payload that is
 * present but hollow is worse than an absent one: an empty `available_slots` would
 * render as "It has no style settings" on a component declaring dozens, and a
 * candidate list carrying a non-key value would fatal in the consumer's array
 * lookups rather than degrade. Both route to the fallback instead.
 *
 * @param  WP_Error   $error
 * @return array|null  ['component_name' => string, 'available_slots' => array,
 *                     'candidate_slots' => array], or null.
 */
function pp_rejected_slot_context(WP_Error $error): ?array {
    $data = $error->get_error_data();

    if (!is_array($data)
        || !isset($data['component_name']) || !is_string($data['component_name']) || $data['component_name'] === ''
        || !isset($data['available_slots']) || !is_array($data['available_slots']) || $data['available_slots'] === []
        || !isset($data['candidate_slots']) || !is_array($data['candidate_slots'])
    ) {
        return null;
    }

    // Array keys are int|string and nothing else, so a candidate list that holds
    // anything else did not come from array_keys() and is not a slot list.
    foreach ($data['candidate_slots'] as $candidate) {
        if (!is_string($candidate) && !is_int($candidate)) {
            return null;
        }
    }

    return [
        'component_name'  => $data['component_name'],
        'available_slots' => $data['available_slots'],
        'candidate_slots' => $data['candidate_slots'],
    ];
}

/**
 * Computes a style-level diff for the changes array.
 */
function _pp_diff_style(array $before, array $after, int $index): array {
    $changes = [];
    $all_keys = array_unique(array_merge(array_keys($before), array_keys($after)));
    foreach ($all_keys as $key) {
        $from = $before[$key] ?? null;
        $to   = $after[$key] ?? null;
        if ($from !== $to) {
            $changes[] = [
                'path' => 'composition[' . $index . '].style.' . $key,
                'from' => $from,
                'to'   => $to,
            ];
        }
    }
    return $changes;
}

/**
 * Computes a prop-level diff for the changes array.
 */
function _pp_diff_props(array $before, array $after, int $index): array {
    $changes = [];
    $all_keys = array_unique(array_merge(array_keys($before), array_keys($after)));
    foreach ($all_keys as $key) {
        $from = $before[$key] ?? null;
        $to   = $after[$key] ?? null;
        if ($from !== $to) {
            $changes[] = [
                'path' => 'composition[' . $index . '].props.' . $key,
                'from' => $from,
                'to'   => $to,
            ];
        }
    }
    return $changes;
}
