<?php
/**
 * lib/ai-settings.php — PromptingPress AI Settings Page
 *
 * BYOK configuration for AI chat. Stores provider config in wp_options.
 * Only one provider implemented in v1 (GitHub Models API), but the UX
 * is generic enough for any OpenAI-compatible endpoint.
 *
 * Capability gate: manage_options (admin only).
 * Loaded only when is_admin() is true (gated in functions.php).
 */

// Option keys and defaults are defined in ai-provider.php (loaded unconditionally)
// so that ai-stream.php can access them outside admin context.

// ── Menu Registration ──────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_submenu_page(
        'pp-ai-chat',
        'AI Settings',
        'AI Settings',
        'manage_options',
        'pp-ai-settings',
        'pp_ai_settings_page'
    );
});

// ── Settings API Registration ──────────────────────────────────────────────

add_action('admin_init', function () {
    register_setting('pp_ai_settings', PP_AI_OPT_PROVIDER, [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => PP_AI_DEFAULT_PROVIDER,
    ]);
    register_setting('pp_ai_settings', PP_AI_OPT_BASE_URL, [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => PP_AI_DEFAULT_BASE_URL,
    ]);
    register_setting('pp_ai_settings', PP_AI_OPT_API_KEY, [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);
    register_setting('pp_ai_settings', PP_AI_OPT_MODEL, [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => PP_AI_DEFAULT_MODEL,
    ]);

    add_settings_section(
        'pp_ai_provider_section',
        'AI Provider Configuration',
        function () {
            echo '<p>Configure your AI provider. Any OpenAI-compatible endpoint works. GitHub Models is pre-filled as the default.</p>';
        },
        'pp-ai-settings'
    );

    add_settings_field('pp_ai_provider', 'Provider Name', function () {
        $value = get_option(PP_AI_OPT_PROVIDER, PP_AI_DEFAULT_PROVIDER);
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text" />',
            PP_AI_OPT_PROVIDER,
            esc_attr($value)
        );
        echo '<p class="description">Display label only. Does not affect API calls.</p>';
    }, 'pp-ai-settings', 'pp_ai_provider_section');

    add_settings_field('pp_ai_base_url', 'Base URL', function () {
        $value = get_option(PP_AI_OPT_BASE_URL, PP_AI_DEFAULT_BASE_URL);
        printf(
            '<input type="url" name="%s" value="%s" class="regular-text" />',
            PP_AI_OPT_BASE_URL,
            esc_attr($value)
        );
        echo '<p class="description">Full chat completions endpoint URL.</p>';
    }, 'pp-ai-settings', 'pp_ai_provider_section');

    add_settings_field('pp_ai_api_key', 'API Key', function () {
        $value = get_option(PP_AI_OPT_API_KEY, '');
        printf(
            '<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" />',
            PP_AI_OPT_API_KEY,
            esc_attr($value)
        );
        echo '<p class="description">For GitHub Models: a GitHub PAT with <code>models:read</code> scope.</p>';
    }, 'pp-ai-settings', 'pp_ai_provider_section');

    add_settings_field('pp_ai_model', 'Model ID', function () {
        $value = get_option(PP_AI_OPT_MODEL, PP_AI_DEFAULT_MODEL);
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text" />',
            PP_AI_OPT_MODEL,
            esc_attr($value)
        );
        echo '<p class="description">Model identifier sent to the provider.</p>';
    }, 'pp-ai-settings', 'pp_ai_provider_section');
});

// ── Settings Page Render ───────────────────────────────────────────────────

function pp_ai_settings_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>PromptingPress AI Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('pp_ai_settings');
            do_settings_sections('pp-ai-settings');
            submit_button('Save Settings');
            ?>
        </form>

        <hr />
        <h2>Test Connection</h2>
        <p>Send a minimal request to verify your API key and endpoint work.</p>
        <button type="button" id="pp-ai-test-connection" class="button button-secondary">Test Connection</button>
        <span id="pp-ai-test-result" style="margin-left: 10px;"></span>

        <script>
        document.getElementById('pp-ai-test-connection').addEventListener('click', function() {
            var btn = this;
            var result = document.getElementById('pp-ai-test-result');
            btn.disabled = true;
            result.textContent = 'Testing...';
            result.style.color = '#666';

            var data = new FormData();
            data.append('action', 'pp_ai_test_connection');
            data.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('pp_ai_test_connection')); ?>');

            fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    result.textContent = resp.data;
                    result.style.color = '#46b450';
                } else {
                    result.textContent = resp.data || 'Connection failed.';
                    result.style.color = '#dc3232';
                }
                btn.disabled = false;
            })
            .catch(function() {
                result.textContent = 'Request failed.';
                result.style.color = '#dc3232';
                btn.disabled = false;
            });
        });
        </script>
    </div>
    <?php
}

// ── Test Connection AJAX Handler ───────────────────────────────────────────

add_action('wp_ajax_pp_ai_test_connection', function () {
    check_ajax_referer('pp_ai_test_connection');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied.');
    }

    $api_key  = get_option(PP_AI_OPT_API_KEY, '');
    $base_url = get_option(PP_AI_OPT_BASE_URL, PP_AI_DEFAULT_BASE_URL);
    $model    = get_option(PP_AI_OPT_MODEL, PP_AI_DEFAULT_MODEL);

    if (empty($api_key)) {
        wp_send_json_error('API key is required. Save your settings first.');
    }

    if (empty($base_url)) {
        wp_send_json_error('Base URL is required.');
    }

    $body = wp_json_encode([
        'model'    => $model,
        'messages' => [
            ['role' => 'user', 'content' => 'Say "ok" and nothing else.'],
        ],
        'max_tokens' => 5,
    ]);

    $ch = curl_init($base_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        wp_send_json_error('Connection failed: ' . $curl_error);
    }

    if ($http_code === 401 || $http_code === 403) {
        wp_send_json_error('Invalid API key (HTTP ' . $http_code . ').');
    }

    if ($http_code === 429) {
        wp_send_json_error('Rate limited. Try again in a moment.');
    }

    if ($http_code < 200 || $http_code >= 300) {
        $decoded = json_decode($response, true);
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
        wp_send_json_error('Provider error: ' . $msg);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['choices'])) {
        wp_send_json_error('Unexpected response format from provider.');
    }

    wp_send_json_success('Connected to ' . get_option(PP_AI_OPT_MODEL, PP_AI_DEFAULT_MODEL) . ' ✓');
});

// pp_ai_is_configured() and pp_ai_get_config() are defined in ai-provider.php
// (loaded unconditionally) so ai-stream.php can access them outside admin context.
