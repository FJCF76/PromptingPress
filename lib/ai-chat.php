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

    // Destructive-action warnings, server-driven from the action + apply
    // registries (single source of truth). Any action/apply that declares an
    // 'impact_warning' string surfaces in the chat proposal UI — no hardcoded
    // JS list to drift when a new destructive capability is registered.
    $impact_warnings = [];
    foreach (pp_get_registered_actions() as $pp_act_name => $pp_act_def) {
        if (!empty($pp_act_def['impact_warning'])) {
            $impact_warnings[$pp_act_name] = $pp_act_def['impact_warning'];
        }
    }
    foreach (pp_get_registered_applies() as $pp_apply_name => $pp_apply_def) {
        if (!empty($pp_apply_def['impact_warning'])) {
            $impact_warnings[$pp_apply_name] = $pp_apply_def['impact_warning'];
        }
    }

    wp_localize_script('pp-ai-chat', 'ppAiChat', [
        'streamUrl'        => get_template_directory_uri() . '/ai-stream.php',
        'ajaxUrl'          => admin_url('admin-ajax.php'),
        'streamNonce'      => wp_create_nonce('pp_ai_stream'),
        'executeNonce'     => wp_create_nonce('pp_ai_execute'),
        'configured'       => pp_ai_is_configured(),
        'impact_warnings'  => $impact_warnings,
        'connectorsUrl'    => admin_url('options-connectors.php'),
        'siteUrl'          => site_url(),
        // Scopes the browser-local chat history to this WP user so two admins
        // sharing an OS/browser profile can't read each other's conversation
        // (#157). wp_localize_script casts scalars to strings, so JS receives
        // this as e.g. "5"; pp-ai-chat.js validates it as a decimal string and
        // fails closed (in-memory only) if it's absent/invalid.
        'currentUserId'    => get_current_user_id(),
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
                    <a href="<?php echo esc_url(admin_url('options-connectors.php')); ?>" class="button button-primary">
                        Configure AI Provider
                    </a>
                </div>
            <?php else: ?>
                <?php
                $ai_config          = pp_ai_get_config();
                $configured_connectors = pp_ai_get_configured_connectors();
                $providers_map      = pp_ai_connector_providers();
                $is_multi_provider  = count($configured_connectors) > 1;

                // Resolve friendly model name for display
                $model_display = $ai_config['model'];
                $current_models = pp_ai_get_provider_models($ai_config['provider']);
                foreach ($current_models as $m) {
                    if ($m['id'] === $ai_config['model']) {
                        $model_display = $m['name'];
                        break;
                    }
                }
                ?>
                <?php $pp_ai_chat_pages = pp_composition_pages(); ?>
                <div class="pp-ai-chat-header">
                    <h2>AI Chat</h2>
                    <label for="pp-ai-page-select" class="screen-reader-text"><?php esc_html_e('Target Page', 'promptingpress'); ?></label>
                    <select id="pp-ai-page-select" class="pp-ai-chat-selector" title="<?php esc_attr_e('Which page this conversation edits', 'promptingpress'); ?>">
                        <option value=""><?php esc_html_e('— Select a page —', 'promptingpress'); ?></option>
                        <?php foreach ($pp_ai_chat_pages as $pp_ai_chat_page_item): ?>
                            <option value="<?php echo esc_attr($pp_ai_chat_page_item['id']); ?>">
                                <?php echo esc_html($pp_ai_chat_page_item['title'] !== '' ? $pp_ai_chat_page_item['title'] : '(untitled)'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($is_multi_provider): ?>
                        <label for="pp-ai-provider-select" class="screen-reader-text"><?php esc_html_e('AI Provider', 'promptingpress'); ?></label>
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
                    <label for="pp-ai-model-select" class="screen-reader-text"><?php esc_html_e('AI Model', 'promptingpress'); ?></label>
                    <select id="pp-ai-model-select" class="pp-ai-chat-selector">
                        <option value="<?php echo esc_attr($ai_config['model']); ?>" selected>
                            <?php echo esc_html($model_display); ?>
                        </option>
                    </select>
                    <button id="pp-ai-new-chat" class="button pp-ai-new-chat" title="Start a new conversation">New Chat</button>
                </div>
                <div id="pp-ai-messages" class="pp-ai-chat-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e('Chat messages', 'promptingpress'); ?>"></div>
                <div class="pp-ai-chat-input-area">
                    <label for="pp-ai-input" class="screen-reader-text"><?php esc_html_e('Chat message', 'promptingpress'); ?></label>
                    <textarea id="pp-ai-input" placeholder="Ask about your site or request a change..." rows="2"></textarea>
                    <button id="pp-ai-send" class="button button-primary">Send</button>
                    <button id="pp-ai-stop" class="button pp-ai-stop" style="display:none;" title="<?php esc_attr_e('Stop the current response', 'promptingpress'); ?>">Stop</button>
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
//
// $params must already be wp_unslash()'d by the caller (both AJAX handlers
// that call this do so once, on the whole array) — do not unslash again
// here, or a value containing real backslashes/quotes gets double-stripped.

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
            // $val is already unslashed (see the note above) — do not
            // wp_unslash() it again here.
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                $params[$param_name] = $decoded;
            }
        }
    }

    return $params;
}

// ── Component Index Resolver (chat-side error helpers) ────────────────────

/**
 * Resolves the target component index for chat-side error-analysis helpers
 * (_pp_attempt_style_repair, _pp_build_friendly_error). These run on the raw
 * AI-submitted $params — pp_validate_action()'s own component_id resolution
 * (_pp_resolve_id_param() in lib/actions.php) mutates a local copy of $params
 * inside the validate call, which never propagates back to the caller here,
 * so an id-targeted proposal still has no component_index in $params by the
 * time an error needs analyzing. Delegates to the same
 * _pp_resolve_component_id_to_index() (lib/actions.php) that
 * _pp_resolve_id_param() uses, so the two never drift on precedence
 * (component_id > component_index) (#123).
 *
 * @return int  Resolved index, or -1 if it can't be resolved.
 */
function _pp_resolve_component_index_for_error(array $params): int {
    if (isset($params['component_id']) && $params['component_id'] !== '') {
        $post_id = (int) ($params['post_id'] ?? 0);
        $index   = _pp_resolve_component_id_to_index($post_id, $params['component_id']);
        return is_wp_error($index) ? -1 : $index;
    }
    // Defense in depth: pp_validate_action() already type-checks
    // component_index as a real int on every path that reaches these helpers
    // today, but blindly (int)-casting here would silently coerce a garbage
    // value (e.g. a non-numeric string) to 0 — a real component — for any
    // future direct caller that skips that validation. Preserve the old
    // "wrong type → no match" behavior instead (#123 adversarial review).
    if (isset($params['component_index']) && is_int($params['component_index'])) {
        return $params['component_index'];
    }
    return -1;
}

/**
 * True when a component_id was explicitly provided but couldn't be resolved
 * — distinct from "no target info at all" or "target has zero slots/recipes"
 * (both of which also produce an empty availability list). Without this
 * distinction, a typo'd component_id silently looks identical to "this
 * component genuinely has nothing configurable," misleading the calling
 * agent into the wrong repair attempt (#123 adversarial review).
 */
function _pp_component_target_not_found(array $params, int $resolved_index): bool {
    return isset($params['component_id']) && $params['component_id'] !== '' && $resolved_index < 0;
}

// ── Style Repair Helper ───────────────────────────────────────────────────
// When the LLM proposes an invalid style slot name, attempt to find the
// closest match via Levenshtein distance. Returns repaired params or null.

function _pp_attempt_style_repair(string $error_code, array $params): ?array {
    if ($error_code !== 'invalid_style_slot') {
        return null;
    }

    $style = $params['style'] ?? [];
    if (empty($style) || !is_array($style)) {
        return null;
    }

    $post_id         = $params['post_id'] ?? 0;
    $component_index = _pp_resolve_component_index_for_error($params);
    $composition     = pp_get_composition($post_id);
    $component_name  = $composition[$component_index]['component'] ?? '';
    $available_slots = pp_get_style_slots($component_name);

    if (empty($available_slots)) {
        return null;
    }

    $available_names = array_keys($available_slots);
    $repaired        = [];
    $did_repair      = false;

    foreach ($style as $slot_name => $slot_value) {
        if ($slot_name === '__recipe' || isset($available_slots[$slot_name])) {
            $repaired[$slot_name] = $slot_value;
            continue;
        }

        // Find closest match by Levenshtein distance.
        $best_match    = null;
        $best_distance = PHP_INT_MAX;
        $tie_count     = 0;
        foreach ($available_names as $candidate) {
            $dist = levenshtein($slot_name, $candidate);
            if ($dist < $best_distance) {
                $best_distance = $dist;
                $best_match    = $candidate;
                $tie_count     = 1;
            } elseif ($dist === $best_distance) {
                $tie_count++;
            }
        }

        // Accept repair only if distance is reasonable (≤ 40% of slot name length)
        // AND the match is unambiguous (no tie with another slot at the same distance).
        $threshold = max(3, (int) ceil(strlen($slot_name) * 0.4));
        if ($best_match !== null && $best_distance <= $threshold && $tie_count === 1) {
            $repaired[$best_match] = $slot_value;
            $did_repair = true;
        } else {
            // No close match, or ambiguous tie — repair fails.
            return null;
        }
    }

    if (!$did_repair) {
        return null;
    }

    $repaired_params          = $params;
    $repaired_params['style'] = $repaired;
    return $repaired_params;
}

/**
 * Builds a structured, user-friendly error response for preview failures.
 * Returns an associative array with error_code, user_message, and alternatives.
 */
function _pp_build_friendly_error(WP_Error $error, array $params): array {
    $code    = $error->get_error_code();
    $raw_msg = $error->get_error_message();

    switch ($code) {
        case 'invalid_style_slot':
            $component_name = '';
            $available      = [];
            $available_slots = [];
            $composition    = pp_get_composition($params['post_id'] ?? 0);
            $idx            = _pp_resolve_component_index_for_error($params);
            if (_pp_component_target_not_found($params, $idx)) {
                return [
                    'error_code'            => $code,
                    'user_message'          => 'I couldn\'t find that component on the page — it may have been removed or the id is wrong.',
                    'alternatives'          => [],
                    'cross_component_hints' => (object) [],
                    'raw_error'             => $raw_msg,
                ];
            }
            if (isset($composition[$idx])) {
                $component_name  = $composition[$idx]['component'] ?? '';
                $available_slots = pp_get_style_slots($component_name);
                $available       = array_keys($available_slots);
            }

            // Cross-component hint: does this slot exist on a different component?
            $cross_hints  = (object) [];
            $style        = $params['style'] ?? [];
            $invalid_slots = array_diff(array_keys($style), $available);
            $all_components = pp_get_registered_components();
            foreach ($invalid_slots as $invalid_slot) {
                $suffix = preg_replace('/^--[a-z]+-/', '--*-', $invalid_slot);
                foreach ($all_components as $other_name => $other_schema) {
                    if ($other_name === $component_name) continue;
                    $other_slots = pp_get_style_slots($other_name);
                    // Exact match
                    if (isset($other_slots[$invalid_slot])) {
                        $cross_hints->{$invalid_slot} = [
                            'component' => $other_name,
                            'slot'      => $invalid_slot,
                            'match'     => 'exact',
                        ];
                        break;
                    }
                    // Suffix match: strip component prefix, compare
                    foreach ($other_slots as $other_slot_name => $other_slot_def) {
                        $other_suffix = preg_replace('/^--[a-z]+-/', '--*-', $other_slot_name);
                        if ($suffix === $other_suffix) {
                            $cross_hints->{$invalid_slot} = [
                                'component' => $other_name,
                                'slot'      => $other_slot_name,
                                'match'     => 'suffix',
                            ];
                            break 2;
                        }
                    }
                }
            }

            // Build user-facing message with slot descriptions instead of raw names.
            $descriptions = [];
            foreach ($available_slots as $slot_name => $slot_def) {
                $desc = $slot_def['description'] ?? $slot_name;
                $descriptions[] = $desc;
            }

            $hints_array = (array) $cross_hints;
            $has_hints = $hints_array !== [];
            if ($has_hints) {
                $first_hint = reset($hints_array);
                $user_message = sprintf(
                    'I tried to change a setting on the %s component, but it isn\'t available there. It does exist on the %s component. You could ask me to change it there instead.',
                    $component_name ?: 'selected',
                    $first_hint['component']
                );
            } else {
                $user_message = sprintf(
                    'I tried to change a style setting that the %s component doesn\'t support. Available settings: %s.',
                    $component_name ?: 'selected',
                    $descriptions ? implode(', ', $descriptions) : '(none)'
                );
            }

            return [
                'error_code'            => $code,
                'user_message'          => $user_message,
                'alternatives'          => $available,
                'cross_component_hints' => $cross_hints,
                'raw_error'             => $raw_msg,
            ];

        case 'invalid_style_value':
            // Extract the slot name, type, and description from schema.
            $slot_name   = '';
            $type_hint   = '';
            $slot_desc   = '';
            $slot_default = '';
            if (preg_match('/^Style slot "([^"]+)"/', $raw_msg, $m)) {
                $slot_name = $m[1];
                $composition = pp_get_composition($params['post_id'] ?? 0);
                $idx         = _pp_resolve_component_index_for_error($params);
                $comp_name   = $composition[$idx]['component'] ?? '';
                $slots       = pp_get_style_slots($comp_name);
                $type_hint    = $slots[$slot_name]['type'] ?? '';
                $slot_desc    = $slots[$slot_name]['description'] ?? '';
                $slot_default = $slots[$slot_name]['default'] ?? '';
            }

            // Detect CSS keyword removal attempts (none, unset, initial, auto, inherit).
            $attempted_value = '';
            $style = $params['style'] ?? [];
            if ($slot_name && isset($style[$slot_name])) {
                $attempted_value = strtolower(trim((string) $style[$slot_name]));
            }
            $css_keywords = ['none', 'unset', 'initial', 'auto', 'inherit', 'revert'];

            if ($attempted_value && in_array($attempted_value, $css_keywords, true)) {
                // User tried to remove/disable a constraint via CSS keyword.
                $suggestion = _pp_suggest_alternative_value($type_hint, $slot_desc, $slot_default);
                if ($suggestion) {
                    return [
                        'error_code'            => $code,
                        'user_message'          => sprintf(
                            'The value "%s" can\'t be used for style settings. %s',
                            $attempted_value,
                            $suggestion
                        ),
                        'alternatives'          => [],
                        'cross_component_hints' => (object) [],
                        'raw_error'             => $raw_msg,
                    ];
                }
            }

            $format_hints = [
                'color'       => 'Use hex (#1a1a2e), rgb(), rgba(), hsl(), or hsla() format.',
                'length'      => 'Use a number with a unit like rem, px, em, %, vw, or vh (e.g. 4rem, 200px).',
                'number'      => 'Use a plain number without units (e.g. 650, 1.6).',
                'duration'    => 'Use a number with ms or s (e.g. 300ms, 0.3s).',
                'font-family' => 'Use a comma-separated list of font names.',
                'shadow'      => 'Use a preset ("var(--shadow-sm)", "var(--shadow-md)", "var(--shadow-lg)", or "none") or a single-layer box-shadow like "0 4px 12px rgba(0,0,0,0.1)".',
                'gradient'    => 'Use a color (hex, rgb(), rgba(), hsl(), hsla()) or a gradient like "linear-gradient(135deg, #1a1a2e, #16121f)" or "radial-gradient(#1a1a2e, #16121f)".',
            ];
            return [
                'error_code'            => $code,
                'user_message'          => sprintf(
                    'The value for %s isn\'t in the right format. %s',
                    $slot_name ? '"' . $slot_name . '"' : 'the style slot',
                    $format_hints[$type_hint] ?? 'Check the expected format and try again.'
                ),
                'alternatives'          => [],
                'cross_component_hints' => (object) [],
                'raw_error'             => $raw_msg,
            ];

        case 'no_style_slots':
            return [
                'error_code'            => $code,
                'user_message'          => 'This change can\'t be made with the current component settings. This component doesn\'t support style customization. Try editing its content properties instead.',
                'alternatives'          => [],
                'cross_component_hints' => (object) [],
                'raw_error'             => $raw_msg,
            ];

        case 'invalid_recipe':
            $available_recipes = [];
            $composition = pp_get_composition($params['post_id'] ?? 0);
            $idx         = _pp_resolve_component_index_for_error($params);
            if (_pp_component_target_not_found($params, $idx)) {
                return [
                    'error_code'            => $code,
                    'user_message'          => 'I couldn\'t find that component on the page — it may have been removed or the id is wrong.',
                    'alternatives'          => [],
                    'cross_component_hints' => (object) [],
                    'raw_error'             => $raw_msg,
                ];
            }
            if (isset($composition[$idx])) {
                $comp_name = $composition[$idx]['component'] ?? '';
                $recipes   = pp_get_style_recipes($comp_name);
                $available_recipes = array_keys($recipes);
            }
            return [
                'error_code'            => $code,
                'user_message'          => sprintf(
                    'That recipe doesn\'t exist. Available recipes: %s',
                    $available_recipes ? implode(', ', $available_recipes) : '(none)'
                ),
                'alternatives'          => $available_recipes,
                'cross_component_hints' => (object) [],
                'raw_error'             => $raw_msg,
            ];

        default:
            return [
                'error_code'            => $code,
                'user_message'          => $raw_msg,
                'alternatives'          => [],
                'cross_component_hints' => (object) [],
                'raw_error'             => $raw_msg,
            ];
    }
}

/**
 * Suggests a valid alternative value when a CSS keyword was rejected.
 * Uses the slot's type and description to pick a practical suggestion.
 */
function _pp_suggest_alternative_value(string $type, string $description, string $default): ?string {
    $desc_lower = strtolower($description);

    if ($type === 'length') {
        // Max-width / width constraints: suggest 100% to "use all available space".
        if (strpos($desc_lower, 'max') !== false || strpos($desc_lower, 'width') !== false) {
            return 'Try setting it to "100%" to use all available horizontal space.';
        }
        // Padding / gap / spacing: suggest "0" to remove.
        if (strpos($desc_lower, 'padding') !== false || strpos($desc_lower, 'gap') !== false || strpos($desc_lower, 'spacing') !== false || strpos($desc_lower, 'margin') !== false) {
            return 'Try setting it to "0" to remove the spacing.';
        }
        // Radius: suggest "0" to remove.
        if (strpos($desc_lower, 'radius') !== false) {
            return 'Try setting it to "0" to remove the rounding.';
        }
        // Generic length: suggest a large value.
        return 'This slot requires a numeric value with a CSS unit (e.g. 100%, 9999px, 0).';
    }

    if ($type === 'color') {
        return 'Try "transparent" for an invisible color, or a hex/rgb value.';
    }

    if ($type === 'number') {
        return 'This slot requires a plain number (e.g. 0, 1, 650).';
    }

    if ($type === 'align') {
        return 'This slot requires a text-align keyword: "left", "right", "center", "start", "end", or "justify".';
    }

    if ($type === 'text-transform') {
        return 'This slot requires a text-transform keyword: "none" (sentence case as authored), "uppercase", "lowercase", or "capitalize".';
    }

    if ($type === 'duration') {
        return 'Try "0s" to disable the duration, or a value like "300ms".';
    }

    if ($type === 'shadow') {
        return 'Try a preset: "var(--shadow-sm)", "var(--shadow-md)", "var(--shadow-lg)", or "none". Or a single-layer box-shadow like "0 4px 12px rgba(0,0,0,0.1)".';
    }

    if ($type === 'gradient') {
        return 'Try "transparent" for an invisible background, a hex/rgb color, or a gradient like "linear-gradient(135deg, #1a1a2e, #16121f)".';
    }

    return null;
}

// ── Capability Resolver ─────────────────────────────────────────────────────

/**
 * Resolves the WordPress capabilities required to preview/execute a given
 * action or apply. Mirrors the model documented for _pp_cli_require_apply_cap()
 * (lib/cli.php) and the composition editor's own pp_publish_page AJAX handler
 * (lib/admin.php), which checks both a post-scoped meta cap and a raw
 * capability rather than relying on the coarse `edit_posts` gate alone.
 *
 * Default per action scope (see lib/actions.php registry):
 * - 'site'    → manage_options (site-wide mutation).
 * - 'page'/'section' → edit_post against the resolved post_id.
 * Explicit per-action overrides layer additional caps on top of that default.
 *
 * @param  string $type   'action' | 'apply'.
 * @param  string $name   Registered action/apply name.
 * @param  array  $params Params AFTER pp_ai_coerce_params() — post_id, if any,
 *                        must already be coerced to int by this point.
 * @return array[]        List of ['cap' => string, 'post_id' => ?int]. ALL must pass.
 */
function _pp_required_caps_for(string $type, string $name, array $params): array {
    if ($type === 'apply') {
        // All applies mutate site-wide design state directly — same bar as
        // _pp_cli_require_apply_cap().
        return [['cap' => 'manage_options']];
    }

    $action = pp_get_action($name);
    if ($action === null) {
        // Unknown action name — fail closed at the highest bar.
        return [['cap' => 'manage_options']];
    }

    $post_id = isset($params['post_id']) && is_numeric($params['post_id']) ? (int) $params['post_id'] : null;

    if (_pp_is_menu_action($name)) {
        // Menu structure is core Appearance territory in WordPress
        // (Appearance > Menus is gated on edit_theme_options there) —
        // mirror that instead of the stricter manage_options default
        // for other site-scoped actions (issue 132). _pp_is_menu_action()
        // (lib/actions.php) is the shared source of truth with the batch
        // snapshot gate, so a future menu action can't miss either layer.
        return [['cap' => 'edit_theme_options']];
    }

    switch ($name) {
        case 'publish_page':
        case 'unpublish_page':
            return _pp_caps_or_fail_closed($post_id, [['cap' => 'edit_post', 'post_id' => $post_id], ['cap' => 'publish_pages']]);
        case 'trash_page':
        case 'restore_page':
            // WordPress core gates trash/untrash on the same capability
            // (wp-admin's untrash-post AJAX action checks 'delete_post', not
            // 'edit_post') — mirror that rather than treating restore as a
            // plain edit.
            return _pp_caps_or_fail_closed($post_id, [['cap' => 'delete_post', 'post_id' => $post_id]]);
        case 'create_page':
            // Scope is 'site' (no existing post to check against), but page
            // creation is core Editor territory — gate on publish_pages, not
            // manage_options, or Editors lose the ability to build pages
            // through chat entirely.
            return [['cap' => 'publish_pages']];
    }

    $scope = $action['scope'] ?? 'site';
    if (!in_array($scope, ['page', 'section'], true)) {
        // Whitelist, not a blacklist of 'site': an unrecognized/future scope
        // value fails closed at the highest bar rather than silently
        // dropping to the weaker edit_post check.
        return [['cap' => 'manage_options']];
    }

    // page | section: needs a resolved post_id to check against; without one
    // we can't verify per-post ownership, so fail closed.
    return _pp_caps_or_fail_closed($post_id, [['cap' => 'edit_post', 'post_id' => $post_id]]);
}

/**
 * Returns $caps when $post_id resolved, otherwise the fail-closed default
 * (manage_options) — the target couldn't be identified, so no per-post cap
 * can be verified against it.
 */
function _pp_caps_or_fail_closed(?int $post_id, array $caps): array {
    return $post_id !== null ? $caps : [['cap' => 'manage_options']];
}

/**
 * Checks whether the current user satisfies every requirement returned by
 * _pp_required_caps_for(). AND semantics — all checks must pass.
 */
function _pp_user_meets_required_caps(array $required): bool {
    foreach ($required as $req) {
        if (array_key_exists('post_id', $req)) {
            if (!current_user_can($req['cap'], $req['post_id'])) {
                return false;
            }
        } elseif (!current_user_can($req['cap'])) {
            return false;
        }
    }
    return true;
}

/**
 * Returns $_POST['params'] unslashed once, as an array.
 *
 * WordPress magic-quotes every $_POST value (wp_magic_quotes()). Unslashing
 * the whole params array here — rather than per-param-type inside
 * pp_ai_coerce_params() — protects every plain string param, not just
 * array-type params destined for json_decode there.
 */
function _pp_ai_get_unslashed_post_params(): array {
    return isset($_POST['params']) ? wp_unslash((array) $_POST['params']) : [];
}

// ── AJAX: Preview Action/Apply ─────────────────────────────────────────────

add_action('wp_ajax_pp_ai_preview', function () {
    check_ajax_referer('pp_ai_execute', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied.');
    }

    $type   = sanitize_text_field($_POST['type'] ?? '');
    $name   = sanitize_text_field($_POST['name'] ?? '');
    $params = _pp_ai_get_unslashed_post_params();

    if (!in_array($type, ['action', 'apply'], true)) {
        wp_send_json_error('Invalid type. Must be "action" or "apply".');
    }

    if (empty($name)) {
        wp_send_json_error('Name is required.');
    }

    $params = pp_ai_coerce_params($type, $name, $params);

    if (!_pp_user_meets_required_caps(_pp_required_caps_for($type, $name, $params))) {
        wp_send_json_error('Permission denied.');
    }

    if ($type === 'action') {
        $result = pp_preview_action($name, $params);
    } else {
        $result = pp_preview_apply($name, $params);
    }

    if (is_wp_error($result)) {
        // For style_component errors, attempt repair before giving up.
        if ($name === 'style_component') {
            $repaired_params = _pp_attempt_style_repair($result->get_error_code(), $params);
            if ($repaired_params !== null) {
                $retry = $type === 'action'
                    ? pp_preview_action($name, $repaired_params)
                    : pp_preview_apply($name, $repaired_params);

                if (!is_wp_error($retry)) {
                    // Repair succeeded — return preview with a repair note.
                    $retry['repaired'] = true;
                    wp_send_json_success($retry);
                }
                // Repair attempt also failed — fall through to friendly error.
            }

            // Return structured error for style_component failures.
            wp_send_json_error(_pp_build_friendly_error($result, $params));
        }

        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success($result);
});

// ── AJAX: Execute Action/Apply ─────────────────────────────────────────────

/**
 * Core logic for the single-execute AJAX handler, extracted from the
 * wp_ajax_pp_ai_execute closure so it's directly unit-testable (add_action is a
 * no-op in the test bootstrap, so the closure body is unreachable from tests —
 * the #387 lesson: pin the real handler path, not a helper-only slice). Mirrors
 * the extraction already used for _pp_ai_chat_fallback_response(): the guard,
 * baseline-mandate, execute, and post-apply-validation logic live here; the AJAX
 * closure is a thin adapter that translates the result to
 * wp_send_json_success()/wp_send_json_error().
 *
 * Composition CAS baseline (#404): a composition-mutating ACTION must carry an
 * `expected_version` baseline in its params, or this handler rejects it fail-
 * closed with `missing_expected_version` BEFORE executing — chat writes never
 * reach the writer without CAS. Applies (token writes, #393) and non-mutating
 * actions are exempt. On `composition_conflict` the error payload is the
 * STRUCTURED envelope (error_code + expected/current versions, #312/#404 req.7),
 * not a collapsed string, so the UI can render Re-read & re-preview.
 *
 * @param  array $post  $_POST-shaped input: ['type', 'name', 'params'].
 * @return array        ['ok' => bool, 'data' => mixed] — 'data' is the success
 *                       result array (with 'validation' + 'composition_version')
 *                       when ok, else an error string or structured error array.
 */
function _pp_ai_execute_response(array $post): array {
    if (!current_user_can('edit_posts')) {
        return ['ok' => false, 'data' => 'Permission denied.'];
    }

    $type   = sanitize_text_field($post['type'] ?? '');
    $name   = sanitize_text_field($post['name'] ?? '');
    $params = isset($post['params']) ? wp_unslash((array) $post['params']) : [];

    if (!in_array($type, ['action', 'apply'], true)) {
        return ['ok' => false, 'data' => 'Invalid type. Must be "action" or "apply".'];
    }

    if ($name === '') {
        return ['ok' => false, 'data' => 'Name is required.'];
    }

    $params = pp_ai_coerce_params($type, $name, $params);

    if (!_pp_user_meets_required_caps(_pp_required_caps_for($type, $name, $params))) {
        return ['ok' => false, 'data' => 'Permission denied.'];
    }

    // Fail-closed CAS baseline mandate (#404): a composition-mutating action without a
    // baseline is rejected before it can write. Opt-in is how the chat gap survived to v1;
    // chat UI and server ship in the same plugin version, so there is no compat window.
    if ($type === 'action' && pp_action_is_composition_mutating($name)
        && _pp_action_expected_version($params) === null) {
        return ['ok' => false, 'data' => [
            'error'      => 'This change needs the page\'s current version as a baseline, '
                          . 'which is missing. Re-read the page and try again.',
            'error_code' => 'missing_expected_version',
        ]];
    }

    // Media-library URL/image-type validation (#124) now runs inside
    // pp_validate_action() itself (lib/actions.php), so every caller —
    // this AJAX handler, WP-CLI, and pp_patch_composition() — is covered
    // by the same guard instead of one ad-hoc check per entry point.

    if ($type === 'action') {
        $result = pp_execute_action($name, $params);
    } else {
        $result = pp_execute_apply($name, $params);
    }

    if (is_wp_error($result)) {
        return ['ok' => false, 'data' => $result->get_error_message()];
    }

    if (!$result['ok']) {
        return ['ok' => false, 'data' => _pp_ai_execute_error_payload($result, $params)];
    }

    // Post-apply validation — wrapped in try/catch so validation failure
    // never swallows the successful apply response (D1).
    $validation = null;
    if (isset($params['post_id'])) {
        try {
            $validation = pp_post_apply_validate((int) $params['post_id']);
        } catch (\Throwable $e) {
            $validation = [
                'ok'       => false,
                'warnings' => [],
                'errors'   => [[
                    'check'   => 'validation_error',
                    'message' => 'Validation failed: ' . $e->getMessage(),
                ]],
            ];
        }
    }

    $result['validation'] = $validation;
    return ['ok' => true, 'data' => $result];
}

/**
 * Builds the error payload for a failed single-execute result (#404). A
 * `composition_conflict` is returned as a STRUCTURED envelope carrying the
 * machine-readable code plus both versions (the baseline the caller sent and the
 * live version that beat it), so the chat UI can render the Re-read & re-preview
 * state instead of a generic failure string. The current version is read fresh
 * from the marker at conflict time — the writer is never touched. Every other
 * failure collapses to its message string (unchanged behavior).
 *
 * @param array $result  The failed action/apply result array.
 * @param array $params  The executed params (source of expected_version + post_id).
 * @return string|array
 */
function _pp_ai_execute_error_payload(array $result, array $params) {
    if (($result['error_code'] ?? '') === 'composition_conflict') {
        $current = null;
        if (isset($params['post_id']) && is_numeric($params['post_id'])) {
            $current = pp_get_composition_marker((int) $params['post_id'])['version'];
        }
        return [
            'error'            => $result['error'] ?? 'Execution failed.',
            'error_code'       => 'composition_conflict',
            'expected_version' => _pp_action_expected_version($params),
            'current_version'  => $current,
        ];
    }
    return $result['error'] ?? 'Execution failed.';
}

add_action('wp_ajax_pp_ai_execute', function () {
    check_ajax_referer('pp_ai_execute', 'nonce');

    $resp = _pp_ai_execute_response($_POST);

    if ($resp['ok']) {
        wp_send_json_success($resp['data']);
    } else {
        wp_send_json_error($resp['data']);
    }
});

// ── AJAX: Batch Execute Proposal Steps ──────────────────────────────────────
// issue 137: a multi-step proposal applies atomically in one request instead
// of N independent pp_ai_execute calls — pp_ai_execute_batch() snapshots
// every target up front and rolls everything back if any step fails, so a
// failure never leaves the page half-mutated.

/**
 * Parses the browser-supplied batch CAS baseline map (#404): a JSON object
 * {post_id => version} naming a baseline for every page any composition-mutating
 * step targets. Each entry is validated like a single write's expected_version
 * (via _pp_normalize_version_baseline) — a non-numeric key or a hostile/malformed
 * version is dropped, so a bad entry reads as ABSENT and trips the fail-closed
 * mandate rather than smuggling a wrong baseline into the writer. A legitimate 0
 * (legacy/never-written page) is preserved.
 *
 * @param mixed $raw  $_POST['baselines'] — a JSON string (magic-quoted) or array.
 * @return array      {int post_id => int version}
 */
function _pp_ai_parse_batch_baselines($raw): array {
    $decoded = is_array($raw) ? $raw : json_decode((string) wp_unslash((string) $raw), true);
    if (!is_array($decoded)) {
        return [];
    }
    $map = [];
    foreach ($decoded as $pid => $version) {
        if (!is_numeric($pid)) {
            continue;
        }
        $normalized = _pp_normalize_version_baseline($version);
        if ($normalized !== null) {
            $map[(int) $pid] = $normalized;
        }
    }
    return $map;
}

/**
 * Fail-closed batch baseline mandate (#404, A1): true only when every
 * composition-mutating step has a baseline in the map for its target page. A
 * mutating step with no resolvable post_id, or a target page absent from the
 * map, fails coverage — the batch is then rejected before any step runs, so
 * there is nothing to roll back. Non-mutating actions, applies, and create_page
 * (which starts a page at version-0 semantics) never need a baseline.
 *
 * @param array $steps      Normalized steps.
 * @param array $baselines  {post_id => version} from _pp_ai_parse_batch_baselines().
 * @return bool
 */
function _pp_ai_batch_baselines_cover_mutations(array $steps, array $baselines): bool {
    foreach ($steps as $step) {
        if (($step['type'] ?? '') !== 'action' || !pp_action_is_composition_mutating($step['name'] ?? '')) {
            continue;
        }
        $params = $step['params'] ?? [];
        if (!isset($params['post_id']) || !is_numeric($params['post_id'])) {
            return false; // mutating step with no target page — cannot verify, fail closed.
        }
        if (!array_key_exists((int) $params['post_id'], $baselines)) {
            return false;
        }
    }
    return true;
}

/**
 * Core logic for the batch-execute AJAX handler, extracted for the same reason
 * as _pp_ai_execute_response() (testable real-handler path, #387). Normalizes +
 * capability-checks every step up front, enforces the fail-closed baseline
 * mandate (A1), then threads the baseline map through pp_ai_execute_batch().
 *
 * @param  array $post  $_POST-shaped input: ['steps' (JSON), 'baselines' (JSON)].
 * @return array        ['ok' => bool, 'data' => mixed].
 */
function _pp_ai_execute_batch_response(array $post): array {
    if (!current_user_can('edit_posts')) {
        return ['ok' => false, 'data' => 'Permission denied.'];
    }

    $raw_steps = wp_unslash($post['steps'] ?? '');
    $steps = json_decode((string) $raw_steps, true);

    if (!is_array($steps) || empty($steps)) {
        return ['ok' => false, 'data' => 'steps must be a non-empty array.'];
    }

    $baselines = _pp_ai_parse_batch_baselines($post['baselines'] ?? '');

    // Every step's capability requirement is checked up front, before any
    // step executes — unlike semantic state validation, a capability
    // requirement never depends on an earlier step's effect, so this can't
    // false-positive-reject a legitimately interdependent step the way a
    // full state-projected pre-validation would.
    $normalized = [];
    foreach ($steps as $step) {
        $type   = sanitize_text_field($step['type'] ?? '');
        $name   = sanitize_text_field($step['name'] ?? '');
        $params = is_array($step['params'] ?? null) ? $step['params'] : [];

        if (!in_array($type, ['action', 'apply'], true)) {
            return ['ok' => false, 'data' => 'Invalid step type. Must be "action" or "apply".'];
        }
        if ($name === '') {
            return ['ok' => false, 'data' => 'Each step requires a name.'];
        }

        $params = pp_ai_coerce_params($type, $name, $params);

        if (!_pp_user_meets_required_caps(_pp_required_caps_for($type, $name, $params))) {
            return ['ok' => false, 'data' => 'Permission denied.'];
        }

        $normalized[] = ['type' => $type, 'name' => $name, 'params' => $params];
    }

    // Fail-closed baseline mandate (#404, A1): reject the whole batch before executing any
    // step if any composition-mutating step's target page lacks a baseline. Nothing runs,
    // so there is nothing to roll back — atomicity is preserved.
    if (!_pp_ai_batch_baselines_cover_mutations($normalized, $baselines)) {
        return ['ok' => false, 'data' => [
            'error'      => 'This proposal changes a page but is missing that page\'s current '
                          . 'version as a baseline. Re-read the page and try again.',
            'error_code' => 'missing_expected_version',
        ]];
    }

    return ['ok' => true, 'data' => pp_ai_execute_batch($normalized, $baselines)];
}

add_action('wp_ajax_pp_ai_execute_batch', function () {
    check_ajax_referer('pp_ai_execute', 'nonce');

    $resp = _pp_ai_execute_batch_response($_POST);

    if ($resp['ok']) {
        wp_send_json_success($resp['data']);
    } else {
        wp_send_json_error($resp['data']);
    }
});

// ── AJAX: Read a page's current composition CAS baseline ────────────────────
// Backs the chat UI's "Re-read & re-preview" conflict affordance (#404): a
// read-only lookup of a page's current composition version so the UI can refresh
// its stale baseline before re-previewing a proposal. Never mutates anything.

/**
 * Core logic for the page-baseline read handler (#404), extracted for testability.
 *
 * @param  array $post  $_POST-shaped input: ['post_id'].
 * @return array        ['ok' => bool, 'data' => mixed] — data is
 *                       ['post_id' => int, 'version' => int] when ok.
 */
function _pp_ai_page_baseline_response(array $post): array {
    if (!current_user_can('edit_posts')) {
        return ['ok' => false, 'data' => 'Permission denied.'];
    }
    $post_id = isset($post['post_id']) && is_numeric($post['post_id']) ? (int) $post['post_id'] : 0;
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['ok' => false, 'data' => 'Invalid page.'];
    }
    if (!current_user_can('edit_post', $post_id)) {
        return ['ok' => false, 'data' => 'Permission denied.'];
    }
    return ['ok' => true, 'data' => [
        'post_id' => $post_id,
        'version' => pp_get_composition_marker($post_id)['version'],
    ]];
}

add_action('wp_ajax_pp_ai_page_baseline', function () {
    check_ajax_referer('pp_ai_execute', 'nonce');

    $resp = _pp_ai_page_baseline_response($_POST);

    if ($resp['ok']) {
        wp_send_json_success($resp['data']);
    } else {
        wp_send_json_error($resp['data']);
    }
});

// ── AJAX: Non-Streaming Chat Fallback ──────────────────────────────────────

/**
 * Core logic for the non-streaming chat fallback, extracted from the
 * wp_ajax_pp_ai_chat closure so it's directly unit-testable (issue 16) —
 * add_action() is a no-op in the test bootstrap, so the closure body was
 * previously unreachable from any test. Mirrors the same extraction
 * pattern already used for _pp_required_caps_for()/pp_ai_coerce_params()
 * in this file: pull the guard/orchestration logic into a plain function,
 * leave the AJAX closure as a thin adapter that translates the result to
 * wp_send_json_success()/wp_send_json_error().
 *
 * @param  array $post  $_POST-shaped input: ['messages' => array, 'page_id' => mixed].
 * @return array        ['ok' => bool, 'data' => mixed] — 'data' is the error
 *                       string when !ok, or the success payload
 *                       (['content', 'proposal']) when ok.
 */
function _pp_ai_chat_fallback_response(array $post): array {
    if (!current_user_can('edit_posts')) {
        return ['ok' => false, 'data' => 'Permission denied.'];
    }

    if (!pp_ai_is_configured()) {
        return ['ok' => false, 'data' => 'AI provider not configured. Check Settings > Connectors.'];
    }

    // WordPress magic-quotes every $_POST value during bootstrap
    // (wp_magic_quotes()); the SSE path is immune (reads raw JSON from
    // php://input) but this fallback reads $_POST directly, so every
    // quote/backslash in the conversation must be unslashed before it
    // reaches the provider.
    $conversation = isset($post['messages']) ? wp_unslash((array) $post['messages']) : [];
    $page_id      = isset($post['page_id']) ? (int) $post['page_id'] : null;

    if (empty($conversation)) {
        return ['ok' => false, 'data' => 'No messages provided.'];
    }

    $system_prompt = pp_ai_system_prompt();
    $messages = pp_ai_format_messages($system_prompt, $conversation, $page_id);
    $result = pp_ai_completion($messages);

    if (!$result['ok']) {
        return ['ok' => false, 'data' => $result['error']];
    }

    $proposal = pp_ai_parse_proposal($result['full_response']);

    $data = [
        'content'  => $result['full_response'],
        'proposal' => $proposal,
    ];

    // Composition CAS baseline (#404): the SSE path ships this in its done event; the
    // fallback must too, or a proposal generated here would reach execute with no baseline
    // and be rejected by the fail-closed mandate. Captured at the same read the model saw.
    if ($page_id && get_post($page_id)) {
        $data['page_baseline'] = [
            'post_id' => $page_id,
            'version' => pp_get_composition_marker($page_id)['version'],
        ];
    }

    return ['ok' => true, 'data' => $data];
}

add_action('wp_ajax_pp_ai_chat', function () {
    check_ajax_referer('pp_ai_stream', 'nonce');
    set_time_limit(0);

    $result = _pp_ai_chat_fallback_response($_POST);

    if ($result['ok']) {
        wp_send_json_success($result['data']);
    } else {
        wp_send_json_error($result['data']);
    }
});
