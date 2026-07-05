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
    $upload_dir = wp_get_upload_dir();
    $upload_base = $upload_dir['baseurl'] ?? '';
    if (empty($upload_base)) {
        return true;
    }

    $urls = _pp_extract_urls_from_params($params);
    if (empty($urls)) {
        return true;
    }

    foreach ($urls as $url) {
        // Only validate URLs that look like they reference the site's uploads
        if (strpos($url, $upload_base) !== 0) {
            continue;
        }

        // Check if this URL matches any attachment in the media library
        $attachment_id = _pp_resolve_attachment_id_by_url($url);
        if ($attachment_id <= 0) {
            $filename = basename($url);
            return new WP_Error(
                'invalid_media_url',
                sprintf('Image URL does not match any file in the media library: %s', $filename)
            );
        }

        // Defense in depth: pp_ai_media_inventory() only lists image
        // attachments, but nothing stops the model from fabricating a URL to
        // a non-image attachment it saw elsewhere (or hallucinating one that
        // happens to resolve). Reject at execute time too (#124).
        if (!wp_attachment_is_image($attachment_id)) {
            $filename = basename($url);
            return new WP_Error(
                'invalid_media_url',
                sprintf('URL does not point to an image file: %s', $filename)
            );
        }
    }

    return true;
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
 * @return array  Canonical result: ['ok', 'action', 'scope', 'target', 'changes', 'error']
 */
function pp_execute_action(string $name, array $params): array {
    $validation = pp_validate_action($name, $params);
    if (is_wp_error($validation)) {
        $action = pp_get_action($name);
        return [
            'ok'      => false,
            'action'  => $name,
            'scope'   => $action['scope'] ?? 'unknown',
            'target'  => [],
            'changes' => [],
            'error'   => $validation->get_error_message(),
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

    return $result;
}

// ── Helper: build result arrays ─────────────────────────────────────────────

function _pp_action_result(string $name, string $scope, array $target, array $changes): array {
    return [
        'ok'      => true,
        'action'  => $name,
        'scope'   => $scope,
        'target'  => $target,
        'changes' => $changes,
        'error'   => null,
    ];
}

function _pp_action_error(string $name, string $scope, string $error): array {
    return [
        'ok'      => false,
        'action'  => $name,
        'scope'   => $scope,
        'target'  => [],
        'changes' => [],
        'error'   => $error,
    ];
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
    'semantics'   => 'Create. Title is required. Composition defaults to empty array. Status defaults to "draft". Composition items use the same {"component", "props"} shape as elsewhere. Optional slug sets the canonical route up front (#134) — omit to let WordPress derive one from the title.',
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
    'description' => 'Updates a whitelisted WordPress site option (blogname, blogdescription, pp_logo_id, pp_logo_alt). pp_logo_id takes a Media Library attachment ID (not a URL) to set the site logo.',
    'semantics'   => 'Replace. Key must be whitelisted. Value replaces entirely and is validated against the key type (pp_logo_id must be an attachment ID).',
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

// ── Action: update_composition ──────────────────────────────────────────────
// Scope: page | Semantics: replace entire composition array

pp_register_action('update_composition', [
    'scope'          => 'page',
    'impact_warning' => 'Replaces entire page composition',
    'description' => 'Replaces the entire composition array for a page. Each item is {"component": "name", "props": {...}}.',
    'semantics'   => 'Replace. The full composition array is replaced. Pass the complete array, not a partial update. Items use {"component", "props"} shape.',
    'params'      => [
        'post_id'     => ['type' => 'int',   'required' => true],
        'composition' => ['type' => 'array', 'required' => true],
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
        $result = pp_update_composition($params['post_id'], $params['composition']);
        if (is_wp_error($result)) {
            return _pp_action_error('update_composition', 'page', $result->get_error_message());
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
    'description' => 'Adds a component to a page composition.',
    'semantics'   => 'Append by default. If position is provided, insert at that index (0-based). Validates the resulting composition.',
    'params'      => [
        'post_id'   => ['type' => 'int',    'required' => true],
        'component' => ['type' => 'string', 'required' => true],
        'props'     => ['type' => 'array',  'required' => true],
        'position'  => ['type' => 'int',    'required' => false],
    ],
    'validate' => function (array $params) {
        $exists = _pp_validate_page_exists($params['post_id']);
        if (is_wp_error($exists)) {
            return $exists;
        }
        $new_item = ['component' => $params['component'], 'props' => $params['props']];
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
        $after     = $current;
        if (isset($params['position'])) {
            array_splice($after, $params['position'], 0, [$new_item]);
        } else {
            $after[] = $new_item;
        }
        $result = pp_update_composition($params['post_id'], $after);
        if (is_wp_error($result)) {
            return _pp_action_error('add_component', 'page', $result->get_error_message());
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
    'impact_warning' => 'Removes component from page',
    'description' => 'Removes a component from a page composition. Accepts component_id (stable pp-<hex8>) or component_index (0-based). component_id takes precedence when both are provided.',
    'semantics'   => 'Remove by component_id or 0-based index. Validates target is valid. Remaining components shift down.',
    'params'      => [
        'post_id'         => ['type' => 'int',    'required' => true],
        'component_index' => ['type' => 'int',    'required' => false],
        'component_id'    => ['type' => 'string', 'required' => false],
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
        $result = pp_update_composition($params['post_id'], $after);
        if (is_wp_error($result)) {
            return _pp_action_error('remove_component', 'page', $result->get_error_message());
        }
        return _pp_action_result('remove_component', 'page', ['post_id' => $params['post_id']], [
            ['path' => 'composition[' . $params['component_index'] . ']', 'from' => $removed['component'], 'to' => null],
        ]);
    },
]);

// ── Action: reorder_components ──────────────────────────────────────────────
// Scope: page | Semantics: permutation, validated

pp_register_action('reorder_components', [
    'scope'       => 'page',
    'description' => 'Reorders components in a page composition.',
    'semantics'   => 'Permutation. Order must be a valid permutation of 0..N-1 where N is the current composition length. No duplicates, no gaps, no out-of-bounds indices.',
    'params'      => [
        'post_id' => ['type' => 'int',   'required' => true],
        'order'   => ['type' => 'array', 'required' => true],
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
        $result = pp_update_composition($params['post_id'], $after);
        if (is_wp_error($result)) {
            return _pp_action_error('reorder_components', 'page', $result->get_error_message());
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
    'description' => 'Updates a single component\'s props via shallow merge (patch, not replace). Optionally accepts style to also update per-instance style slots in the same call. Accepts component_id (stable pp-<hex8>) or component_index (0-based). component_id takes precedence when both are provided.',
    'semantics'   => 'Patch. Props are shallow-merged into existing props. Unspecified props unchanged. null removes a prop. Optional style param shallow-merges style slots (same as style_component). Validates the merged composition via pp_validate_composition(). Target component by component_id or component_index.',
    'params'      => [
        'post_id'         => ['type' => 'int',    'required' => true],
        'component_index' => ['type' => 'int',    'required' => false],
        'component_id'    => ['type' => 'string', 'required' => false],
        'props'           => ['type' => 'array',  'required' => true],
        'style'           => ['type' => 'array',  'required' => false],
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

        $result = pp_update_composition($params['post_id'], $composition);
        if (is_wp_error($result)) {
            return _pp_action_error('update_component', 'section', $result->get_error_message());
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
    'description' => 'Moves a page to the trash (reversible, does not permanently delete).',
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
    'description' => 'Updates a component instance\'s per-instance style overrides via shallow merge. Optionally accepts a recipe name that expands into slot values (explicit style overrides recipe slots). Use wp pp operate inspect-composition to see available slots and recipes.',
    'semantics'   => 'Patch. Recipe expands first, then explicit style values override. null removes a slot. Validates against schema.json style_slots for the target component type.',
    'params'      => [
        'post_id'         => ['type' => 'int',    'required' => true],
        'component_id'    => ['type' => 'string', 'required' => false],
        'component_index' => ['type' => 'int',    'required' => false],
        'style'           => ['type' => 'array',  'required' => false],
        'recipe'          => ['type' => 'string', 'required' => false],
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

        $result = pp_update_composition($params['post_id'], $composition);
        if (is_wp_error($result)) {
            return _pp_action_error('style_component', 'section', $result->get_error_message());
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
