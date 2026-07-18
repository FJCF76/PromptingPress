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
    // lives in pp_action_composition_precondition() (lib/operate.php).
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
 * Extracts all URL-like string values from action params.
 * Walks props, composition arrays, and items arrays.
 */
function _pp_extract_urls_from_params(array $params): array {
    $urls = [];
    // logo_id is an attachment ID, not a URL — excluded from URL extraction.
    $url_props = ['image_url', 'background_image'];

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
 * Executes an action: validates first, then executes.
 * Returns the canonical result shape.
 *
 * The canonical keys are the MINIMUM every action returns, not an exhaustive list: an
 * action may add its own. restore_composition adds `findings` (#233). Read the shape as
 * "at least these keys", and key on what you need rather than the exact key set.
 *
 * @return array  Canonical result: ['ok', 'action', 'scope', 'target', 'changes', 'error',
 *                'error_code'], plus any action-specific keys.
 */
function pp_execute_action(string $name, array $params): array {
    $validation = pp_validate_action($name, $params);
    if (is_wp_error($validation)) {
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
        ];
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

    // Post-write composition version on the success envelope (#404). The chat UI refreshes
    // its per-page CAS baseline from this instead of a second read, and the batch executor
    // chains it into the next mutating step on the same page. Attached for composition-
    // mutating actions and create_page (whose new page needs a baseline to join the map);
    // resolve the post_id from params, falling back to a created page's result target.
    if (($result['ok'] ?? false)
        && (pp_action_is_composition_mutating($name) || $name === 'create_page')) {
        $version_post_id = null;
        if (isset($params['post_id']) && is_numeric($params['post_id'])) {
            $version_post_id = (int) $params['post_id'];
        } elseif (isset($result['target']['post_id']) && is_numeric($result['target']['post_id'])) {
            $version_post_id = (int) $result['target']['post_id'];
        }
        if ($version_post_id !== null) {
            $result['composition_version'] = pp_get_composition_marker($version_post_id)['version'];
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
 * @param  array $steps  Each: ['type' => 'action'|'apply', 'name' => string, 'params' => array]
 * @return array          Snapshot bundle passed to _pp_restore_batch_snapshot().
 */
function _pp_snapshot_batch_targets(array $steps): array {
    $posts = [];
    $site_options = [];
    $custom_css = null;
    $token_overrides = null;
    $font_urls = null;
    $menus = null;

    foreach ($steps as $step) {
        $type   = $step['type']   ?? '';
        $name   = $step['name']   ?? '';
        $params = $step['params'] ?? [];

        if (isset($params['post_id']) && is_numeric($params['post_id'])) {
            $post_id = (int) $params['post_id'];
            if (!isset($posts[$post_id]) && get_post($post_id)) {
                $post = get_post($post_id);
                $posts[$post_id] = [
                    'title'       => $post->post_title,
                    'slug'        => $post->post_name,
                    'status'      => $post->post_status,
                    'composition' => pp_get_composition($post_id),
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
                $current = pp_site_option($key);
                $site_options[$key] = is_wp_error($current) ? '' : $current;
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
        'site_options'    => $site_options,
        'custom_css'      => $custom_css,
        'token_overrides' => $token_overrides,
        'font_urls'       => $font_urls,
        'menus'           => $menus,
    ];
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
 * @return string[]         Anything that could NOT be restored (currently
 *                           the menu layer reports these) — empty when the
 *                           rollback was clean.
 */
function _pp_restore_batch_snapshot(array $snapshot): array {
    foreach ($snapshot['created_posts'] as $created_post_id) {
        wp_delete_post($created_post_id, true);
    }

    foreach ($snapshot['posts'] as $post_id => $state) {
        if (in_array($post_id, $snapshot['created_posts'], true)) {
            continue; // already deleted above — nothing to restore it to
        }
        pp_update_composition($post_id, $state['composition']);
        pp_update_page_title($post_id, $state['title']);
        pp_update_page_slug($post_id, $state['slug']);
        wp_update_post(['ID' => $post_id, 'post_status' => $state['status']], true);
        pp_update_seo_meta($post_id, $state['seo_meta']);
    }

    foreach ($snapshot['site_options'] as $key => $value) {
        // Only whitelisted options are ours to touch. The batch snapshotter records
        // every update_site_option step's key up front (before execute rejects an
        // unauthorized one), so a non-whitelisted key can appear here captured as ''
        // (pp_site_option returns WP_Error for it). Restoring raw would delete_option()
        // an unrelated core WP option — pp_update_site_option used to block that via its
        // whitelist check, so the guard has to stay. Only VALUE validation is bypassed.
        if (!isset(pp_allowed_site_options()[$key])) {
            continue;
        }
        // Restore the captured baseline faithfully, bypassing pp_update_site_option's
        // create-time validator. That validator can reject a legitimate captured
        // baseline and silently drop the write (issue 281): an unset/empty baseline is
        // captured as '' (pp_site_option => (string) get_option($key, '')), and ''
        // fails the attachment_id/bool rules; likewise a once-valid value a newer rule
        // now rejects (e.g. a pp_logo_id whose attachment was later deleted) would fail
        // re-validation. Either case would leave the applied value in place instead of
        // rolling back. This is the same class as restore_composition (issue 233):
        // a restore is never blocked by current validation rules. A captured baseline
        // is trusted pre-run state (its keys are already whitelisted — only
        // update_site_option steps populate this map), so it restores verbatim without
        // re-validation: an empty capture means "was unset/empty" and is restored by
        // deleting the option (observably identical to '' via pp_site_option); every
        // other value is written raw.
        if ((string) $value === '') {
            delete_option($key);
        } else {
            update_option($key, (string) $value);
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
        return _pp_restore_menu_state($snapshot['menus']);
    }

    return [];
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
 * @param  array $steps      Each: ['type' => 'action'|'apply', 'name' => string, 'params' => array]
 * @param  array $baselines  {post_id => version} CAS baselines per page (#404); [] = none.
 * @return array          ['ok', 'steps' (per-step results), 'failed_at' (?int),
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

        $results[] = $result;

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
    'description' => 'Creates a new page with the Composition template. Each composition item is {"component": "name", "props": {...}}.',
    'semantics'   => 'Create. Title is required. Composition defaults to empty array. Status defaults to "draft". Composition items use the same {"component", "props"} shape as elsewhere. Optional slug sets the canonical route up front (#134) — omit to let WordPress derive one from the title. A page created with no composition is NOT stranded: it can be populated later with update_composition or deleted with trash_page through the operate surface (#358); only component-level edits (add/remove/reorder/update/style_component) require an existing composition first.',
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
            pp_update_composition($post_id, $params['composition']);
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
    'description' => 'Updates a whitelisted WordPress site option (blogname, blogdescription, pp_logo_id, pp_logo_alt, pp_footer_show_logo, pp_footer_bg, pp_footer_text, pp_footer_link_color, pp_footer_blurb, pp_footer_contact, pp_footer_copyright, pp_footer_menu_label, pp_footer_contact_label, pp_footer_note, pp_footer_logo_id, pp_header_bg, pp_header_text, pp_header_link_color). pp_logo_id takes a Media Library attachment ID (not a URL) to set the site logo. pp_footer_show_logo is a boolean (1/0/true/false) that turns the footer logo on/off. The header and footer are template-owned chrome: these site options are the ONLY way to style them (they cannot be composed). pp_header_bg / pp_footer_bg set the header and footer BACKGROUND and each accept a CSS color OR a gradient (hex, rgb()/hsl(), transparent, currentColor, a known color-token reference, or a bounded linear-gradient()/radial-gradient() with 2+ color stops) — this is how you build a dark or gradient marketing header/footer. pp_header_text / pp_footer_text set text color, and pp_header_link_color / pp_footer_link_color set nav-link color (pp_header_link_color also colors the active/current header link, which keeps its bold weight and falls back to the global accent only when the option is unset); those four take a CSS color only (no gradient). pp_footer_blurb, pp_footer_contact, and pp_footer_copyright are text (empty pp_footer_copyright keeps the default copyright line). Footer STRUCTURE: pp_footer_menu_label and pp_footer_contact_label are optional column headings (text) above the footer menu and contact block; pp_footer_note is an optional secondary line (text) that, when set, moves the copyright into a delimited bottom bar and renders opposite it (empty keeps the copyright inline). pp_footer_logo_id is an optional footer logo override (Media Library attachment ID, not a URL) so a light logo variant can serve a dark footer while pp_logo_id stays the header logo; unset falls back to pp_logo_id.',
    'semantics'   => 'Replace. Key must be whitelisted. Value replaces entirely and is validated against the key type (pp_logo_id and pp_footer_logo_id must be an image attachment ID; pp_footer_show_logo must be a boolean; pp_header_bg/pp_footer_bg must be a CSS color OR a bounded gradient; pp_header_text/pp_header_link_color/pp_footer_text/pp_footer_link_color must be a CSS color; the other pp_footer_* keys — blurb, contact, copyright, menu_label, contact_label, note — are free text).',
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
    'semantics'   => 'Replace. Slug is sanitized via sanitize_title() and, on a naming collision with another post, de-duplicated by WordPress core — never silently. The resulting slug is always reported in changes.',
    'params'      => [
        'post_id' => ['type' => 'int',    'required' => true],
        'slug'    => ['type' => 'string', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
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
    'description' => 'Sets page-specific SEO metadata: meta_description, seo_title (overrides the rendered <title> tag), and canonical_url. The first-class, safe-surface alternative to hand-patching theme PHP for per-page metadata (#41).',
    'semantics'   => 'Patch. meta is shallow-merged into existing SEO metadata; unspecified keys are left unchanged. Set a key to "" to clear it.',
    'params'      => [
        'post_id' => ['type' => 'int',   'required' => true],
        'meta'    => ['type' => 'array', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
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
    'semantics'   => 'Replace. The full composition array is replaced. Pass the complete array, not a partial update. Items use {"component", "props"} shape.',
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
        $current = pp_get_composition($params['post_id']);
        return _pp_action_preview('update_composition', 'page', ['post_id' => $params['post_id']], $current, $params['composition'], [
            ['path' => 'composition', 'from' => $current, 'to' => $params['composition']],
        ]);
    },
    'execute' => function (array $params): array {
        $params['composition'] = pp_normalize_composition($params['composition']);
        $current = pp_get_composition($params['post_id']);
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
    'semantics'   => 'Sets post_status to "publish". Idempotent on already-published pages.',
    'params'      => [
        'post_id' => ['type' => 'int', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
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
        // Validate the single new component
        $valid = pp_validate_composition([$new_item]);
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
 * For mutation surfaces that legitimately write a composition the action layer's write-time
 * validation would reject. Today that is restore_composition alone: undo is wired to it
 * (assets/js/pp-ai-chat.js), so a restore that current rules refuse would make undo fail
 * exactly when a user most needs it. Instead the write proceeds and the caller is told what
 * is wrong with what it just restored.
 *
 * Reads the two shared engines rather than deriving a third view of the rules — a second
 * surface with its own idea of what is legal is the root cause of #223. Every future rule
 * (#147 prop-key allowlist, #151 calc/clamp, #154 image-prop allowlist, #230 color validator)
 * lands in those engines and is reported here for free.
 *
 *   pp_validate_composition_errors()  -> severity 'error'    (collect-all; would block a write)
 *   pp_validate_composition_smells()  -> severity 'warning'  (advisory; never blocks a write)
 *
 * `index` is the composition offset for smells, null for errors (whose messages already name
 * the offending item or component).
 *
 * `severity` separates "a write-time rule rejects this" from "advisory". It is payload, not
 * styling: the chat renders every finding as a warning, because by the time a caller reads
 * them the restore has already succeeded. The distinction is for CLI/agent consumers reading
 * the JSON result, which need to know which findings block a subsequent normal write.
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
            'index'    => null,
        ];
    }

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
 * Resolves the target history-ring index for a restore_composition call (#133).
 *
 * The history ring (pp_get_composition_history) is oldest-first, so the most recent
 * prior state is the LAST entry. Two selectors:
 *   - history_index: absolute 0-based index into the ring (takes precedence).
 *   - steps_back:    1 = most recent prior state (last entry), 2 = the one before it,
 *                    … N = the oldest retained entry. Defaults to 1.
 * Returns the resolved absolute index, or a WP_Error when the ring is empty or the
 * selector is out of range.
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
        return $idx;
    }
    $steps = isset($params['steps_back']) ? (int) $params['steps_back'] : 1;
    if ($steps < 1 || $steps > $count) {
        return new WP_Error('history_out_of_bounds', sprintf('steps_back %d is out of range (1..%d).', $steps, $count));
    }
    return $count - $steps;
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
    'description' => 'Restores a page composition to a prior version recorded in its history ring. Select the target with steps_back (1 = most recent prior state, the default) or history_index (absolute 0-based). history_index takes precedence.',
    'semantics'   => 'Rewrite. The composition is replaced with a prior snapshot captured before an earlier write. Restore is itself a conflict-checked write (records its own history entry), so it can be undone in turn.',
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
        $current = pp_get_composition($params['post_id']);
        $history = pp_get_composition_history($params['post_id']);
        $idx     = _pp_resolve_history_target($history, $params);
        // validate() already gated this; guard defensively so preview never indexes null.
        // Normalize so `after` is what execute would actually write, and so the findings
        // below describe the restored composition rather than its legacy encoding (#233).
        // migrate_legacy_variant_keys is applied EXPLICITLY here because this is a read/
        // restore path — pp_normalize_composition() no longer migrates on the write path
        // (#388), so a pre-#69 `variant` snapshot must be decoded here to render.
        $target  = is_wp_error($idx)
            ? []
            : pp_migrate_legacy_variant_keys(pp_normalize_composition($history[$idx]['composition']));
        $preview = _pp_action_preview('restore_composition', 'page', ['post_id' => $params['post_id']], $current, $target, [
            ['path' => 'composition', 'from' => $current, 'to' => $target],
        ]);
        $preview['findings'] = _pp_composition_findings($target);
        return $preview;
    },
    'execute' => function (array $params): array {
        $current = pp_get_composition($params['post_id']);
        $history = pp_get_composition_history($params['post_id']);
        $idx     = _pp_resolve_history_target($history, $params);
        if (is_wp_error($idx)) {
            return _pp_action_error('restore_composition', 'page', $idx->get_error_message(), $idx->get_error_code());
        }
        // Canonicalize legacy shape on the way in (type -> component, variant -> layout/theme).
        // This is decoding, not a rewrite of intent: no component is added, removed, or
        // reordered. Nothing else about the snapshot is touched — chrome and every other
        // rule violation is preserved verbatim and reported below (#233). The variant
        // migration is applied EXPLICITLY (not via pp_normalize_composition, which stopped
        // migrating on the write path in #388) because restore is a read/decode path.
        $target = pp_migrate_legacy_variant_keys(pp_normalize_composition($history[$idx]['composition']));
        $result = pp_update_composition($params['post_id'], $target, _pp_action_expected_version($params));
        if (is_wp_error($result)) {
            return _pp_action_error('restore_composition', 'page', $result->get_error_message(), $result->get_error_code());
        }
        // Restore is never blocked by current validation rules, so it must never report a
        // bare ok:true for a composition those rules reject. `findings` is deliberately a
        // restore-only key: the shared _pp_action_result() envelope stays as-is (findings
        // are meaningless for the token actions that share it). It is NOT named `validation`
        // — the AJAX handler (lib/ai-chat.php) already occupies that key with
        // pp_post_apply_validate() output.
        //
        // Findings describe $target, the array this call wrote. pp_update_composition() takes
        // it by value and injects props.id into its own copy, so $target stays id-free; no
        // validator or smell reads props.id, so the report matches the stored bytes either way.
        $result = _pp_action_result('restore_composition', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'composition', 'from' => $current, 'to' => $target],
        ]);
        $result['findings'] = _pp_composition_findings($target);
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
        $after_props  = _pp_merge_component_props($before_props, $params['props']);

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
    'semantics'   => 'Moves post_status to "trash". Reversible via restore_page.',
    'params'      => [
        'post_id' => ['type' => 'int', 'required' => true],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
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
    'description' => 'Updates a component instance\'s per-instance style overrides via shallow merge. Optionally accepts a recipe name that expands into slot values (explicit style overrides recipe slots). Use wp pp operate inspect-composition to see available slots and recipes.',
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
        foreach ($merged as $slot_name => $slot_value) {
            if ($slot_name === '__recipe') {
                continue;
            }
            if ($slot_value === null) {
                continue;
            }
            if (!isset($available_slots[$slot_name])) {
                $available = implode(', ', array_keys($available_slots));
                return new WP_Error('invalid_style_slot', sprintf(
                    'Component "%s" has no style slot "%s". Available: %s',
                    $component_name, $slot_name, $available
                ));
            }
            $slot_type = $available_slots[$slot_name]['type'] ?? null;
            $validation = _pp_validate_token_value((string) $slot_value, $slot_type);
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

pp_register_action('set_menu', [
    'scope'       => 'site',
    'description' => 'Declaratively sets a menu\'s full item list and (optionally) its location in one call. Creates the menu if a menu with this name does not already exist; replaces all its existing items with the given list.',
    'semantics'   => 'Replace. Each item in items is either {"page_id": int} or {"url": string, "label": string}, in the order given. Existing items on the (possibly pre-existing) menu are removed first.',
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
            if (!is_array($item)) {
                return new WP_Error('invalid_item', sprintf('items[%d] must be an object.', $i));
            }
            $has_page = !empty($item['page_id']);
            $has_url  = !empty($item['url']);
            if ($has_page && $has_url) {
                return new WP_Error('ambiguous_item', sprintf('items[%d]: provide either page_id or url + label, not both.', $i));
            }
            if (!$has_page && !$has_url) {
                return new WP_Error('missing_item_link', sprintf('items[%d]: provide either page_id or url + label.', $i));
            }
            if ($has_page) {
                $exists = _pp_validate_page_exists($item['page_id']);
                if (is_wp_error($exists)) {
                    return $exists;
                }
            } elseif (empty($item['label'])) {
                return new WP_Error('missing_item_label', sprintf('items[%d]: label is required for a custom link.', $i));
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
        $titles = array_map(function ($item) {
            return !empty($item['page_id']) ? get_the_title($item['page_id']) : ($item['label'] ?? '');
        }, $params['items']);
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

        $titles = [];
        foreach ($params['items'] as $item) {
            $link = !empty($item['page_id'])
                ? ['page_id' => (int) $item['page_id']]
                : ['url' => $item['url'], 'label' => $item['label']];
            $item_id = pp_add_nav_menu_item($menu_id, $link);
            if (is_wp_error($item_id)) {
                if ($existing) {
                    // Inside a failed batch this restore is redundant (the
                    // batch snapshot layer rebuilds the menu again — its id
                    // churn defeats the signature skip). Accepted: the
                    // failed-batch path is rare and the final state stays
                    // correct at every entry point.
                    pp_clear_nav_menu_items($menu_id);
                    $restore_errors = _pp_rebuild_menu_items($menu_id, $previous_items);
                    if ($restore_errors !== []) {
                        return _pp_action_error('set_menu', 'site', $item_id->get_error_message()
                            . ' Restoring the previous menu items was also incomplete: '
                            . implode('; ', $restore_errors));
                    }
                } else {
                    // set_menu created this menu itself — a half-populated
                    // leftover would break the atomicity contract just as
                    // much as a gutted pre-existing menu.
                    wp_delete_nav_menu($menu_id);
                }
                return _pp_action_error('set_menu', 'site', $item_id->get_error_message());
            }
            $titles[] = !empty($item['page_id']) ? get_the_title($item['page_id']) : $item['label'];
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
 * Resolves a component_id to its composition index.
 *
 * Shared by _pp_resolve_id_param() below (mutates $params for action
 * validate callables) and _pp_resolve_component_index_for_error() in
 * lib/ai-chat.php (the chat-side error/repair helpers, which run on raw
 * AI-submitted params and never see the mutation the former makes) — one
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
