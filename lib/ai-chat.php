<?php
/**
 * lib/ai-chat.php — PromptingPress AI Chat Admin Page
 *
 * Admin page registration, page render, and AJAX handlers for
 * action preview, execution, and non-streaming chat fallback.
 *
 * Loaded only when is_admin() is true (gated in functions.php).
 */

// ── Menu Registration ──────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_menu_page(
        'AI Chat',
        'PromptingPress',
        'edit_posts',
        'pp-ai-chat',
        'pp_ai_chat_page',
        'dashicons-format-chat',
        3
    );

    // Override the auto-generated first submenu label from "PromptingPress" to "AI Chat"
    add_submenu_page(
        'pp-ai-chat',
        'AI Chat',
        'AI Chat',
        'edit_posts',
        'pp-ai-chat',
        'pp_ai_chat_page'
    );
}, 9);

// ── Full-Width Body Class ──────────────────────────────────────────────────

add_filter('admin_body_class', function (string $classes): string {
    if (isset($_GET['page']) && $_GET['page'] === 'pp-ai-chat') {
        $classes .= ' pp-ai-chat-page';
    }
    return $classes;
});

// ── Admin Assets ───────────────────────────────────────────────────────────

add_action('admin_enqueue_scripts', function (string $hook) {
    if (!isset($_GET['page']) || $_GET['page'] !== 'pp-ai-chat') {
        return;
    }

    $dir_uri = get_template_directory_uri();

    wp_enqueue_style(
        'pp-ai-chat',
        $dir_uri . '/assets/css/pp-ai-chat.css',
        [],
        PP_VERSION
    );

    wp_enqueue_script(
        'pp-ai-chat',
        $dir_uri . '/assets/js/pp-ai-chat.js',
        [],
        PP_VERSION,
        true
    );

    // Pass config to JS
    $pages = pp_composition_pages();

    $ai_config  = pp_ai_get_config();
    $configured = pp_ai_get_configured_connectors();
    $providers_map = pp_ai_connector_providers();

    // Build providers array with model lists for JS
    $providers_js = [];
    foreach ($configured as $pid => $pdata) {
        $models = pp_ai_get_provider_models($pid);
        $providers_js[] = [
            'id'     => $pid,
            'name'   => $pdata['name'],
            'models' => $models,
        ];
    }

    wp_localize_script('pp-ai-chat', 'ppAiChat', [
        'streamUrl'        => get_template_directory_uri() . '/ai-stream.php',
        'ajaxUrl'          => admin_url('admin-ajax.php'),
        'streamNonce'      => wp_create_nonce('pp_ai_stream'),
        'executeNonce'     => wp_create_nonce('pp_ai_execute'),
        'configured'       => pp_ai_is_configured(),
        'connectorsUrl'    => admin_url('options-general.php?page=connectors'),
        'siteUrl'          => site_url(),
        'pages'            => $pages,
        'providers'        => $providers_js,
        'selectedProvider' => $ai_config['provider'],
        'selectedModel'    => $ai_config['model'],
    ]);
});

// ── Chat Page Render ───────────────────────────────────────────────────────

function pp_ai_chat_page(): void {
    if (!current_user_can('edit_posts')) {
        wp_die('Permission denied.');
    }
    ?>
    <div class="wrap pp-ai-chat-wrap">
        <div id="pp-ai-chat-app">
            <?php if (!pp_ai_is_configured()): ?>
                <div class="pp-ai-chat-unconfigured">
                    <span class="dashicons dashicons-admin-generic pp-ai-chat-unconfigured-icon"></span>
                    <h2>Connect an AI Provider</h2>
                    <p>PromptingPress uses WordPress Connectors to securely manage AI provider credentials. Configure Anthropic, OpenAI, or Google in your WordPress settings.</p>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=connectors')); ?>" class="button button-primary">
                        Configure AI Provider
                    </a>
                </div>
            <?php else: ?>
                <?php
                $ai_config          = pp_ai_get_config();
                $configured_connectors = pp_ai_get_configured_connectors();
                $providers_map      = pp_ai_connector_providers();
                $is_multi_provider  = count($configured_connectors) > 1;
                ?>
                <div class="pp-ai-chat-header">
                    <h2>AI Chat</h2>
                    <?php if ($is_multi_provider): ?>
                        <select id="pp-ai-provider-select" class="pp-ai-chat-selector">
                            <?php foreach ($configured_connectors as $pid => $pdata): ?>
                                <option value="<?php echo esc_attr($pid); ?>" <?php selected($pid, $ai_config['provider']); ?>>
                                    <?php echo esc_html($pdata['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="pp-ai-chat-selector pp-ai-chat-selector--static">
                            <?php echo esc_html(reset($configured_connectors)['name']); ?>
                        </span>
                    <?php endif; ?>
                    <select id="pp-ai-model-select" class="pp-ai-chat-selector">
                        <option value="<?php echo esc_attr($ai_config['model']); ?>" selected>
                            <?php echo esc_html($ai_config['model']); ?>
                        </option>
                    </select>
                    <button id="pp-ai-new-chat" class="button pp-ai-new-chat" title="Start a new conversation">New Chat</button>
                </div>
                <div id="pp-ai-messages" class="pp-ai-chat-messages"></div>
                <div class="pp-ai-chat-input-area">
                    <textarea id="pp-ai-input" placeholder="Ask about your site or request a change..." rows="2"></textarea>
                    <button id="pp-ai-send" class="button button-primary">Send</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ── Provider/Model Switch AJAX ────────────────────────────────────────────

add_action('wp_ajax_pp_ai_switch_provider', function () {
    check_ajax_referer('pp_ai_execute');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied.', 403);
        return;
    }

    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
    $model    = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : '';

    $configured = pp_ai_get_configured_connectors();
    $providers  = pp_ai_connector_providers();

    if (!empty($provider) && isset($configured[$provider])) {
        update_option('pp_ai_selected_provider', $provider);

        // If no model specified, use default for this provider
        if (empty($model)) {
            $model = $providers[$provider]['default_model'] ?? '';
        }
    }

    if (!empty($model)) {
        // Validate model ID against the provider's available models
        $valid_provider = !empty($provider) && isset($configured[$provider]) ? $provider : get_option('pp_ai_selected_provider', '');
        if (!empty($valid_provider)) {
            $available = pp_ai_get_provider_models($valid_provider);
            $model_ids = array_column($available, 'id');
            if (!empty($model_ids) && !in_array($model, $model_ids, true)) {
                $model = $model_ids[0] ?? $model;
            }
        }
        update_option('pp_ai_selected_model', $model);
    }

    // Return the model list for the selected provider
    $selected  = get_option('pp_ai_selected_provider', '');
    $models    = !empty($selected) ? pp_ai_get_provider_models($selected) : [];

    wp_send_json_success([
        'provider' => $selected,
        'model'    => get_option('pp_ai_selected_model', ''),
        'models'   => $models,
    ]);
});

// ── Param Coercion ────────────────────────────────────────────────────────
// FormData sends all values as strings. The action/apply layer does strict
// type checking via gettype(). Coerce params to match declared types before
// passing them through.

function pp_ai_coerce_params(string $type, string $name, array $params): array {
    if ($type === 'action') {
        $def = pp_get_action($name);
    } else {
        $applies = pp_get_registered_applies();
        $def = $applies[$name] ?? null;
    }

    if (!$def || empty($def['params'])) {
        return $params;
    }

    foreach ($def['params'] as $param_name => $param_def) {
        if (!array_key_exists($param_name, $params)) {
            continue;
        }
        $expected = $param_def['type'] ?? 'string';
        $val = $params[$param_name];

        if ($expected === 'int' && is_string($val) && is_numeric($val)) {
            $params[$param_name] = (int) $val;
        } elseif ($expected === 'bool' && is_string($val)) {
            $params[$param_name] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
        } elseif ($expected === 'array' && is_string($val)) {
            $decoded = json_decode(wp_unslash($val), true);
            if (is_array($decoded)) {
                $params[$param_name] = $decoded;
            }
        }
    }

    return $params;
}

/**
 * Validates that any URL in action params matching the site's uploads directory
 * corresponds to an actual media library attachment. URLs not matching the
 * uploads pattern are passed through (external URLs are allowed).
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
        if (!_pp_attachment_exists_by_url($url)) {
            $filename = basename($url);
            return new WP_Error(
                'invalid_media_url',
                sprintf('Image URL does not match any file in the media library: %s', $filename)
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
    $url_props = ['image_url', 'background_image', 'logo_url'];

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
 * Checks if a URL corresponds to an existing media library attachment.
 */
function _pp_attachment_exists_by_url(string $url): bool {
    // Try attachment_url_to_postid (handles scaled/resized URLs too)
    $attachment_id = attachment_url_to_postid($url);
    if ($attachment_id > 0) {
        return true;
    }

    // Fallback: check by guid (handles edge cases where the above misses)
    global $wpdb;
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s",
        $url
    ));
    return (int) $count > 0;
}

// ── AJAX: Preview Action/Apply ─────────────────────────────────────────────

add_action('wp_ajax_pp_ai_preview', function () {
    check_ajax_referer('pp_ai_execute', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied.');
    }

    $type   = sanitize_text_field($_POST['type'] ?? '');
    $name   = sanitize_text_field($_POST['name'] ?? '');
    $params = isset($_POST['params']) ? (array) $_POST['params'] : [];

    if (!in_array($type, ['action', 'apply'], true)) {
        wp_send_json_error('Invalid type. Must be "action" or "apply".');
    }

    if (empty($name)) {
        wp_send_json_error('Name is required.');
    }

    $params = pp_ai_coerce_params($type, $name, $params);

    if ($type === 'action') {
        $result = pp_preview_action($name, $params);
    } else {
        $result = pp_preview_apply($name, $params);
    }

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success($result);
});

// ── AJAX: Execute Action/Apply ─────────────────────────────────────────────

add_action('wp_ajax_pp_ai_execute', function () {
    check_ajax_referer('pp_ai_execute', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied.');
    }

    $type   = sanitize_text_field($_POST['type'] ?? '');
    $name   = sanitize_text_field($_POST['name'] ?? '');
    $params = isset($_POST['params']) ? (array) $_POST['params'] : [];

    if (!in_array($type, ['action', 'apply'], true)) {
        wp_send_json_error('Invalid type. Must be "action" or "apply".');
    }

    if (empty($name)) {
        wp_send_json_error('Name is required.');
    }

    $params = pp_ai_coerce_params($type, $name, $params);

    // Validate media-library URLs in props before execution
    if ($type === 'action') {
        $url_error = _pp_validate_media_urls_in_params($params);
        if (is_wp_error($url_error)) {
            wp_send_json_error($url_error->get_error_message());
        }
    }

    if ($type === 'action') {
        $result = pp_execute_action($name, $params);
    } else {
        $result = pp_execute_apply($name, $params);
    }

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    if (!$result['ok']) {
        wp_send_json_error($result['error'] ?? 'Execution failed.');
    }

    wp_send_json_success($result);
});

// ── AJAX: Non-Streaming Chat Fallback ──────────────────────────────────────

add_action('wp_ajax_pp_ai_chat', function () {
    check_ajax_referer('pp_ai_stream', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied.');
    }

    if (!pp_ai_is_configured()) {
        wp_send_json_error('AI provider not configured. Check Settings > Connectors.');
    }

    set_time_limit(0);

    $conversation = isset($_POST['messages']) ? (array) $_POST['messages'] : [];
    $page_id      = isset($_POST['page_id']) ? (int) $_POST['page_id'] : null;

    if (empty($conversation)) {
        wp_send_json_error('No messages provided.');
    }

    $system_prompt = pp_ai_system_prompt();
    $messages = pp_ai_format_messages($system_prompt, $conversation, $page_id);
    $result = pp_ai_completion($messages);

    if (!$result['ok']) {
        wp_send_json_error($result['error']);
    }

    $proposal = pp_ai_parse_proposal($result['full_response']);

    wp_send_json_success([
        'content'  => $result['full_response'],
        'proposal' => $proposal,
    ]);
});
