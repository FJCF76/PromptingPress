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

    wp_localize_script('pp-ai-chat', 'ppAiChat', [
        'streamUrl'    => get_template_directory_uri() . '/ai-stream.php',
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'streamNonce'  => wp_create_nonce('pp_ai_stream'),
        'executeNonce' => wp_create_nonce('pp_ai_execute'),
        'configured'   => pp_ai_is_configured(),
        'settingsUrl'  => admin_url('admin.php?page=pp-ai-settings'),
        'siteUrl'      => site_url(),
        'pages'        => $pages,
        'model'        => get_option(PP_AI_OPT_MODEL, PP_AI_DEFAULT_MODEL),
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
                    <h2>AI Chat</h2>
                    <p>Configure your AI provider to get started.</p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pp-ai-settings')); ?>" class="button button-primary">
                        AI Settings
                    </a>
                </div>
            <?php else: ?>
                <div class="pp-ai-chat-header">
                    <h2>AI Chat</h2>
                    <span class="pp-ai-chat-model"><?php echo esc_html(get_option(PP_AI_OPT_MODEL, PP_AI_DEFAULT_MODEL)); ?></span>
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
        wp_send_json_error('AI provider not configured.');
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
