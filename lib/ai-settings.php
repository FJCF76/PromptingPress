<?php
/**
 * lib/ai-settings.php — PromptingPress AI Settings Page
 *
 * Structured BYOK configuration for AI chat. Stores provider config in wp_options.
 * GitHub Models is the first-class supported path; Custom / Manual allows any
 * OpenAI-compatible endpoint.
 *
 * Capability gate: manage_options (admin only).
 * Loaded only when is_admin() is true (gated in functions.php).
 */

// Option keys and defaults are defined in ai-provider.php (loaded unconditionally)
// so that ai-stream.php can access them outside admin context.

// ── Provider Data ─────────────────────────────────────────────────────────

/**
 * Returns the provider configuration array.
 *
 * Single source of truth for provider labels, canonical base URLs, and
 * curated model lists. Used by the render callback, the sanitize callback,
 * and the Test Connection AJAX handler.
 */
function pp_ai_get_providers(): array {
    return [
        'github_models' => [
            'label'    => 'GitHub Models',
            'base_url' => 'https://models.github.ai/inference/chat/completions',
            'models'   => [
                'openai/gpt-5-chat' => 'GPT-5 Chat',
                'openai/gpt-5'      => 'GPT-5',
                'openai/gpt-4.1'    => 'GPT-4.1',
            ],
        ],
        'custom' => [
            'label'    => 'Custom / Manual',
            'base_url' => '',
            'models'   => [],
        ],
    ];
}

// ── Migration ─────────────────────────────────────────────────────────────

/**
 * One-time write-time migration from v0.2.1 provider strings to provider keys.
 *
 * Called once on first settings page load after upgrade. Maps the literal
 * string "GitHub Models" to the key "github_models". Any other non-empty
 * string maps to "custom", preserving existing base_url and model values.
 */
function pp_ai_maybe_migrate_provider(): void {
    $provider = get_option(PP_AI_OPT_PROVIDER, '');

    if ($provider === 'GitHub Models') {
        update_option(PP_AI_OPT_PROVIDER, 'github_models');
        return;
    }

    $providers = pp_ai_get_providers();
    if ($provider !== '' && !isset($providers[$provider])) {
        update_option(PP_AI_OPT_PROVIDER, 'custom');
    }
}

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
        'sanitize_callback' => 'pp_ai_sanitize_base_url',
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
            echo '<p>Configure your AI provider for chat. GitHub Models is the recommended default.</p>';
        },
        'pp-ai-settings'
    );

    $providers     = pp_ai_get_providers();
    $cur_provider  = get_option(PP_AI_OPT_PROVIDER, PP_AI_DEFAULT_PROVIDER);
    $cur_model     = get_option(PP_AI_OPT_MODEL, PP_AI_DEFAULT_MODEL);
    $cur_base_url  = get_option(PP_AI_OPT_BASE_URL, PP_AI_DEFAULT_BASE_URL);
    $is_github     = ($cur_provider === 'github_models');

    // 1. Provider dropdown
    add_settings_field('pp_ai_provider', 'Provider', function () use ($providers, $cur_provider) {
        echo '<select name="' . PP_AI_OPT_PROVIDER . '" id="pp-ai-provider">';
        foreach ($providers as $key => $p) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($cur_provider, $key, false),
                esc_html($p['label'])
            );
        }
        echo '</select>';
        echo '<p class="description">Select your AI provider.</p>';
    }, 'pp-ai-settings', 'pp_ai_provider_section');

    // 2. Base URL (hidden for GitHub Models, visible for Custom)
    add_settings_field('pp_ai_base_url', 'Base URL', function () use ($cur_base_url, $is_github) {
        printf(
            '<input type="url" name="%s" value="%s" class="regular-text" placeholder="https://your-provider.com/v1/chat/completions" />',
            PP_AI_OPT_BASE_URL,
            esc_attr($cur_base_url)
        );
        echo '<p class="description">Full chat completions endpoint URL.</p>';
    }, 'pp-ai-settings', 'pp_ai_provider_section');

    // 3. API Key
    add_settings_field('pp_ai_api_key', 'API Key', function () use ($is_github) {
        $value = get_option(PP_AI_OPT_API_KEY, '');
        printf(
            '<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" />',
            PP_AI_OPT_API_KEY,
            esc_attr($value)
        );
        $helper = $is_github
            ? 'GitHub Personal Access Token with <code>models:read</code> scope.'
            : 'Bearer token for your provider.';
        echo '<p class="description pp-ai-key-helper">' . $helper . '</p>';
    }, 'pp-ai-settings', 'pp_ai_provider_section');

    // 4. Model — both field types always rendered; JS shows the correct one.
    add_settings_field('pp_ai_model', 'Model', function () use ($providers, $cur_provider, $cur_model, $is_github) {
        $models = $providers['github_models']['models'];
        $is_custom_model = !isset($models[$cur_model]);

        // GitHub Models mode: curated dropdown + custom model text input
        $gh_display = $is_github ? '' : 'display:none;';
        echo '<div id="pp-ai-model-github" style="' . $gh_display . '">';
        echo '<select id="pp-ai-model-select">';
        foreach ($models as $id => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($id),
                selected($cur_model, $id, false),
                esc_html($label . ' (' . $id . ')')
            );
        }
        printf(
            '<option value="__custom__"%s>Custom model ID…</option>',
            ($is_github && $is_custom_model) ? ' selected' : ''
        );
        echo '</select>';
        printf(
            '<input type="text" id="pp-ai-model-custom" value="%s" class="regular-text" placeholder="e.g. openai/gpt-4o" style="margin-top:6px;%s" />',
            esc_attr(($is_github && $is_custom_model) ? $cur_model : ''),
            ($is_github && $is_custom_model) ? '' : 'display:none;'
        );
        echo '</div>';

        // Custom mode: free-text input
        $custom_display = $is_github ? 'display:none;' : '';
        printf(
            '<div id="pp-ai-model-freetext" style="%s"><input type="text" id="pp-ai-model-text" value="%s" class="regular-text" placeholder="openai/gpt-4o" /></div>',
            $custom_display,
            esc_attr(!$is_github ? $cur_model : '')
        );

        // Hidden input carries the actual submitted value. JS keeps it in sync.
        printf(
            '<input type="hidden" name="%s" id="pp-ai-model-value" value="%s" />',
            PP_AI_OPT_MODEL,
            esc_attr($cur_model)
        );

        echo '<p class="description">AI model for chat conversations.</p>';
    }, 'pp-ai-settings', 'pp_ai_provider_section');
});

// ── Server-Side Base URL Derivation ───────────────────────────────────────

/**
 * Sanitize callback for pp_ai_base_url.
 *
 * For known providers with a canonical base URL, the submitted value is
 * overridden with the canonical URL from pp_ai_get_providers(). PHP is the
 * source of truth — JS controls field visibility only.
 */
function pp_ai_sanitize_base_url($value) {
    $provider  = isset($_POST[PP_AI_OPT_PROVIDER]) ? sanitize_text_field($_POST[PP_AI_OPT_PROVIDER]) : '';
    $providers = pp_ai_get_providers();

    if (isset($providers[$provider]['base_url']) && $providers[$provider]['base_url'] !== '') {
        return $providers[$provider]['base_url'];
    }

    return esc_url_raw($value);
}

// ── Settings Page Render ───────────────────────────────────────────────────

function pp_ai_settings_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Run migration before rendering so the form shows the correct provider key.
    pp_ai_maybe_migrate_provider();

    $providers     = pp_ai_get_providers();
    $configured    = pp_ai_is_configured();
    $providers_json = wp_json_encode($providers);
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
        <p>Send a minimal request to verify your settings. Tests saved settings — save changes first.</p>
        <button type="button" id="pp-ai-test-connection" class="button button-secondary"<?php echo $configured ? '' : ' disabled'; ?>>Test Connection</button>
        <?php if (!$configured): ?>
            <span class="description" style="margin-left: 10px;">Save settings with an API key first.</span>
        <?php endif; ?>
        <span id="pp-ai-test-result" style="margin-left: 10px;"></span>

        <script>
        (function() {
            var providers = <?php echo $providers_json; ?>;
            var providerSelect = document.getElementById('pp-ai-provider');
            var baseUrlRow = document.querySelector('input[name="<?php echo PP_AI_OPT_BASE_URL; ?>"]').closest('tr');
            var keyHelper = document.querySelector('.pp-ai-key-helper');

            // Model field elements
            var modelGithubWrap = document.getElementById('pp-ai-model-github');
            var modelFreetextWrap = document.getElementById('pp-ai-model-freetext');
            var modelSelect = document.getElementById('pp-ai-model-select');
            var modelCustomInput = document.getElementById('pp-ai-model-custom');
            var modelTextInput = document.getElementById('pp-ai-model-text');
            var modelHidden = document.getElementById('pp-ai-model-value');

            // Sync the hidden input from whichever model field is active.
            function syncModelValue() {
                var isGitHub = (providerSelect.value === 'github_models');
                if (isGitHub) {
                    if (modelSelect.value === '__custom__') {
                        modelHidden.value = modelCustomInput.value;
                    } else {
                        modelHidden.value = modelSelect.value;
                    }
                } else {
                    modelHidden.value = modelTextInput.value;
                }
            }

            function toggleFields() {
                var provider = providerSelect.value;
                var isGitHub = (provider === 'github_models');

                // Base URL: hidden for GitHub Models, visible for Custom.
                if (baseUrlRow) {
                    baseUrlRow.style.display = isGitHub ? 'none' : '';
                }

                // API Key helper text.
                if (keyHelper) {
                    keyHelper.innerHTML = isGitHub
                        ? 'GitHub Personal Access Token with <code>models:read</code> scope.'
                        : 'Bearer token for your provider.';
                }

                // Model field: show the correct container for the selected provider.
                modelGithubWrap.style.display = isGitHub ? '' : 'none';
                modelFreetextWrap.style.display = isGitHub ? 'none' : '';

                syncModelValue();
            }

            // GitHub Models dropdown: "Custom model ID..." reveals free-text input.
            modelSelect.addEventListener('change', function() {
                if (this.value === '__custom__') {
                    modelCustomInput.style.display = '';
                    modelCustomInput.focus();
                } else {
                    modelCustomInput.style.display = 'none';
                }
                syncModelValue();
            });

            // Keep hidden input in sync as the user types or selects.
            modelCustomInput.addEventListener('input', syncModelValue);
            modelTextInput.addEventListener('input', syncModelValue);

            providerSelect.addEventListener('change', toggleFields);

            // Init on page load.
            toggleFields();

            // Test Connection button.
            var testBtn = document.getElementById('pp-ai-test-connection');
            if (testBtn && !testBtn.disabled) {
                testBtn.addEventListener('click', function() {
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
            }
        })();
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

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Curl-level failure (no HTTP response at all).
    if ($response === false) {
        wp_send_json_error('Cannot reach the provider endpoint. Check the Base URL and your network.');
    }

    // HTTP error: delegate to the shared parser.
    if ($http_code < 200 || $http_code >= 300) {
        wp_send_json_error(pp_ai_parse_error_response($http_code, $response));
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['choices'])) {
        wp_send_json_error('Unexpected response format from provider.');
    }

    // Success: show model + provider label.
    $providers = pp_ai_get_providers();
    $provider_key = get_option(PP_AI_OPT_PROVIDER, PP_AI_DEFAULT_PROVIDER);
    $provider_label = $providers[$provider_key]['label'] ?? $provider_key;

    wp_send_json_success('Connected to ' . $model . ' via ' . $provider_label . ' ✓');
});

// pp_ai_is_configured() and pp_ai_get_config() are defined in ai-provider.php
// (loaded unconditionally) so ai-stream.php can access them outside admin context.
