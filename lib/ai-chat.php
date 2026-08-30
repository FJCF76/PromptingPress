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
 * Resolves the target component index for the chat-side error-analysis helper
 * (_pp_build_friendly_error). It runs on the raw
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
    // component_index as a real int on every path that reaches this helper
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
 * agent into retargeting a component that isn't there (#123 adversarial review).
 */
function _pp_component_target_not_found(array $params, int $resolved_index): bool {
    return isset($params['component_id']) && $params['component_id'] !== '' && $resolved_index < 0;
}

// ── Response bounds (chat-side error responses) ───────────────────────────
//
// A failed style_component proposal echoes caller-supplied text back to the
// author: the name that was rejected, and the message the validator wrote around
// it. Both are unbounded at their source, so the response they build is unbounded
// too. These four constants bound it.
//
// The FIRST THREE cap text that is echoed back, so whatever sits past the cap is
// discarded. Each of their values is therefore derived from a measurement of the
// shipped registry rather than picked, so that none of THEM can fire on a legitimate
// rejection — a cap that truncates real output is worse than no cap, because the
// thing it removes is exactly what the author needed to read.
//
// The FOURTH is a different KIND of bound and does not follow that rule, which is
// why it is stated separately rather than folded into the table. The slot-sample cap
// discards nothing: the full declared list ships in the same response as
// `alternatives`, and the card prints all of it inside the <details> disclosure. What
// it chooses is how much of that list the message says out loud, above the fold,
// where there is nothing to collapse (#661). So it is a chosen editorial size, not a
// measured ceiling, and it DOES fire on most legitimate rejections by design — 9 of
// the 12 shipped components declare more than five style slots.
//
//   measurement (shipped registry + starter composition)        value    constant
//   ─────────────────────────────────────────────────────────   ─────    ────────
//   longest DECLARED style slot name                              39  ┐
//                                                                    ├→  256
//   (6.5x headroom over the longest real name)                       ┘
//
//   longest LEGITIMATE raw_error, i.e. the validator's message
//   including its full "Available: <every declared slot>" list    1290  ┐
//                                                                       ├→ 4096
//   (3.2x headroom; capping this at the name budget instead would        ┘
//    truncate that list on 7 of the 10 components that declare
//    slots at all, and the list is the part of raw_error the
//    author is meant to learn from. footer and nav declare none,
//    so they report no_style_slots and never reach this branch)
//
//   most style slots any one component declares                     49  ┐
//   widest style map in the shipped starter composition             20  ├→   64
//   most slots any one shipped recipe contributes                    6  ┘
//   (so a style map applied wholesale to the wrong component —
//    the mistake cross-component hints exist to explain — is
//    reported complete, never truncated. Since #626 the keys counted
//    here are the validator's candidate set, so a recipe's slots are
//    among them: the widest legitimate case is one component's full
//    slot set plus one recipe, 55, still inside the bound)
//
//   (characters, not bytes, throughout — the cap that does the
//    work here truncates with mb_substr)
//   joined DESCRIPTIONS of every slot the widest component
//   declares — what the message used to say out loud (hero, 49)  11213  ┐
//   joined NAMES of the same 49 slots                             1111  ├→    5
//   longest DECLARED style slot name                                39  ┘
//   (the first two are what the cap is measured against, not a
//    headroom target: both are the size of a message the author
//    has to scroll past. Five names is the smallest sample that
//    spans more than one role group on the widest components —
//    hero's first five cover padding, background and heading —
//    so the reader learns the naming convention, which is the
//    thing a mistyped name actually needs. Five names of the
//    longest declared length join to 203 characters; measured
//    end to end on the shipped registry the whole message is
//    273 for hero, against 11309 before)

/** Longest caller-supplied name echoed back in a response. */
const PP_REFLECTED_NAME_MAX = 256;

/** Longest validator message echoed back as raw_error. */
const PP_REFLECTED_ERROR_MAX = 4096;

/** Most unknown style-slot keys examined for a cross-component hint. */
const PP_CROSS_COMPONENT_HINT_MAX = 64;

/** Most declared slot names the friendly message says out loud. */
const PP_FRIENDLY_SLOT_SAMPLE_MAX = 5;

/**
 * Normalizes a piece of caller-supplied text for inclusion in a response.
 *
 * Both callers pass caller-derived text: a style slot name, or the validator
 * message that quotes one. Two jobs.
 *
 * First, drop every character that carries no meaning in either but survives into
 * whatever renders the response. `\p{Cc}` is the C0 and C1 control ranges — tab and
 * newline included, because these messages are single-line. `\p{Cf}` is the format
 * characters: the zero-width set, the bidirectional-formatting set (including
 * U+061C, which the bidi controls are easy to enumerate without), the BOM, and the
 * U+E0000 tag block. Those are invisible, so two different names can present
 * identically to a reader deciding whether the name they typed is the name that was
 * rejected. Naming the two Unicode categories beats listing ranges by hand: the
 * category is the definition, an enumeration is a snapshot of it.
 *
 * Second, bound the length. Truncation follows the existing convention in
 * lib/ai-context.php: cut to `$max_length - 3` and mark it, so the result never
 * exceeds the stated budget.
 *
 * @param  string $text        Caller-supplied or caller-derived text.
 * @param  int    $max_length  Character budget, not byte budget.
 * @return string              Valid UTF-8, at most $max_length characters.
 */
function _pp_clean_reflected_text(string $text, int $max_length): string {
    // Bound the INPUT before scanning it, not just the output. The rejected name is
    // interpolated into the validator's message verbatim
    // (_pp_invalid_style_slot_error(), lib/actions.php), so
    // a multi-megabyte name means preg_replace allocates a multi-megabyte copy to
    // produce a result that is thrown away down to $max_length. A byte-length test
    // is O(1), and 4 bytes is the widest UTF-8 encoding of one character, so
    // $max_length * 4 bytes always holds at least $max_length characters.
    //
    // Not a no-op in every case: text made mostly of characters the strip removes
    // could carry meaningful content past the byte cut and lose it. That only
    // happens for input already far outside the shape of a slot name or a validator
    // message, where a bounded response matters more than a faithful one.
    if (strlen($text) > $max_length * 4) {
        $text = substr($text, 0, $max_length * 4);
    }

    $clean = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $text);

    if ($clean === null) {
        // The /u pattern returns null on invalid UTF-8 — which the byte-wise cut
        // above can itself produce by landing mid-sequence. Repair the encoding and
        // re-run the SAME pattern rather than falling back to a weaker one: a
        // second definition of "clean" would quietly let the whole zero-width and
        // bidi set through on exactly the malformed input that most warrants it.
        //
        // The `?? ''` is reachable, not ceremony: this retry uses the same /u
        // pattern, so any PCRE failure that is not an encoding problem returns null
        // again, and this function's `: string` return type would make that a fatal.
        $clean = preg_replace('/[\p{Cc}\p{Cf}]/u', '', mb_convert_encoding($text, 'UTF-8', 'UTF-8')) ?? '';
    }

    if (mb_strlen($clean) > $max_length) {
        $clean = mb_substr($clean, 0, $max_length - 3) . '...';
    }

    return $clean;
}

/**
 * Writes the visible sentence for an invalid_style_slot rejection that has no
 * cross-component hint to offer (#661).
 *
 * This is the branch the author lands on when the name they used exists nowhere —
 * a typo on a real slot, or a setting the component genuinely doesn't have. There
 * is no other component to point at, so the message's whole job is orientation:
 * what was tried, and what the component actually has.
 *
 * WHY THIS IS NOT JUST A SENTENCE. It used to concatenate the DESCRIPTION of every
 * declared slot, and the descriptions are full sentences carrying multi-clause
 * caveats. On hero (49 slots) that measured 11,309 characters. `user_message` is
 * the one part of the payload THIS branch writes out unconditionally
 * (ppChatRenderPreviewError, assets/js/pp-ai-chat.js) — no disclosure, no clamp —
 * so at 375px a single failed step buried the Apply/Cancel row under many screens
 * of prose. What kept the rest of the response readable was not one property but
 * two, and only one of them is a bound: `raw_error` is genuinely capped at
 * PP_REFLECTED_ERROR_MAX, while `alternatives` is merely COLLAPSED — it still ships
 * every declared name at full length, so a component declaring enormous names moves
 * the wall behind a click rather than removing it. Bounding the payload itself is
 * the reflected-value axis (#647/#649), not this one; what #661 owns is the part
 * with nothing to collapse.
 *
 *   response                  rendered by the card (ppChatRenderPreviewError)
 *   ──────────────────────    ────────────────────────────────────────────────
 *   user_message      ──────→ .pp-ai-preview-error-message      ALWAYS OPEN ← here
 *   cross_component_
 *     hints           ──────→ .pp-ai-preview-error-hint         ALWAYS OPEN
 *                             (this branch has none, by definition)
 *   raw_error         ──────→ ┐ ONE <details>, summary "Show technical
 *   alternatives      ──────→ ┘ details" — both are LINES inside its
 *                               single content div, "Available slots: …"
 *                               being the alternatives line (all of them)
 *
 * So the fix is not to say less TRUE, it is to say less OUT LOUD. Nothing is
 * dropped from the response: the complete declared list still ships as
 * `alternatives`, one click away, and the sample below is the FIRST
 * PP_FRIENDLY_SLOT_SAMPLE_MAX entries of that same list in the same order — so a
 * reader who opens the disclosure finds the names they just read at the top of it,
 * rather than a second, differently-ordered list. The one case where the two texts
 * differ is a name long enough to be truncated by the clean below: it reads as
 * `…` above and appears whole in the disclosure. That is the right way round (a
 * 4000-character name does not belong above the fold) and the ellipsis is the
 * reader's signal not to type what they see.
 *
 * WHY NAMES AND NOT DESCRIPTIONS. The author who lands here got a NAME wrong. A
 * description ("Background color or gradient") does not tell them what to type;
 * `--hero-bg` does, and seeing three or four of them together teaches the
 * convention, which is what turns a near miss into a next attempt.
 *
 * The rejected name is stated when there is exactly one, which is the shape of
 * every near miss. With several unknown keys, naming one of them would read as a
 * claim about the whole set, so the message keeps the older, unattributed opening
 * and lets raw_error carry the specifics.
 *
 * EVERY name this interpolates goes through _pp_clean_reflected_text() at
 * PP_REFLECTED_NAME_MAX — the rejected one, the component name, and each sampled
 * slot name. Capping how MANY names are printed bounds the message only if each
 * name is bounded too, and none of the three is guaranteed short by anything
 * upstream: the rejected name is caller-supplied outright, the component name is
 * read from stored composition on the fallback path, and the sampled names come
 * from the rejection's own error data, which pp_rejected_slot_context() checks for
 * shape but never for size. Reusing the existing constant and helper keeps one
 * owner for the question of how long a reflected name may be.
 *
 * The hinted branch above is STILL left exactly as it was, and after #647 that is a
 * recorded outcome rather than a deferral. #647 inventoried the server's reflection
 * sinks and converted the ones that own their own sink — this file's two AJAX error
 * payloads among them. The hinted branch is not one of those: it interpolates
 * `$component_name` into composed prose, which is the composed-message cluster #864
 * carries, together with the editor save response's sites. Left for that ruling, not
 * for want of noticing.
 *
 * BOUND. At most 2 + PP_FRIENDLY_SLOT_SAMPLE_MAX cleaned names of
 * PP_REFLECTED_NAME_MAX each, plus fixed prose — arithmetic, not an assumption
 * about how the registry is written. Measured on the shipped registry, whose
 * longest declared name is 39 characters, the worst real case (hero, 49 slots) is
 * 273 characters, down from 11,309.
 *
 * @param  string $component_name  Component the rejection resolved to, or ''.
 * @param  string[] $available     Every declared slot name, declaration order.
 * @param  array  $invalid_slots   The rejected slot NAMES (values, not keys).
 * @return string
 */
function _pp_no_hint_slot_message(string $component_name, array $available, array $invalid_slots, bool $authoritative): string {
    $named     = _pp_clean_reflected_text($component_name, PP_REFLECTED_NAME_MAX);
    // Compared against '' rather than leaning on ?:, because "0" is a falsy string and
    // a component actually named 0 would otherwise be described as "the selected" one.
    $component = $named === '' ? 'selected' : $named;
    $total     = count($available);

    // Nothing resolved AND nothing declared. Every sentence below would be a claim
    // about a component that was never found, so make the only claim the evidence
    // supports. An out-of-range `component_index` lands here — the target-not-found
    // answer above fires only for a bad `component_id` — and "it has no style
    // settings" about a component that does not exist is exactly the confident
    // falsehood this branch is being rewritten to stop telling.
    if ($total === 0 && $named === '') {
        return 'I tried to change a style setting, but I couldn\'t tell which component on the page it was meant for.';
    }

    // Quote the rejected name ONLY when the rejection carried its own candidate set
    // (#626). On the fallback that set is re-derived from `$params['style']`, which is
    // NOT recipe-expanded — so a proposal mixing a recipe with one explicit unknown key
    // would let this quote the explicit key while the validator actually refused a slot
    // the recipe contributed. Naming a slot is a new, load-bearing attribution that the
    // old plural-only sentence never made; second-hand evidence gets the hedged form.
    //
    // Re-indexed rather than reset(): reading position 0 of a list says "the rejected
    // NAME" however the array is keyed. On a map, reset() returns the VALUE beside the
    // key — for a style map that is the colour the author typed, and quoting that back
    // as the name they got wrong would be a confident falsehood about their own input.
    $rejected = array_values($invalid_slots);

    $opening = ($authoritative && count($rejected) === 1)
        ? sprintf(
            'I tried to set "%s" on the %s component, but it doesn\'t support that style setting.',
            _pp_clean_reflected_text((string) $rejected[0], PP_REFLECTED_NAME_MAX),
            $component
        )
        : sprintf(
            'I tried to change a style setting that the %s component doesn\'t support.',
            $component
        );

    if ($total === 0) {
        // The component resolved and genuinely declares nothing, so the claim holds.
        // A real rejection from the validator reports no_style_slots before it looks at
        // any name, so this is the contextless fallback's case, not the validator's.
        return $opening . ' It has no style settings.';
    }

    // Clean the sampled names too, and clean them BEFORE they are joined. Capping the
    // COUNT of names bounds the message only if each name is itself bounded, and
    // nothing upstream guarantees that: `available_slots` arrives on the rejection's
    // error data, and pp_rejected_slot_context() (lib/actions.php) checks that map for
    // presence, type and emptiness but never for the size of its keys. Shipped
    // components declare nothing longer than 39 characters, so on the shipped registry
    // this is a no-op — but "the registry happens to be small" is an assumption about
    // theme content, not a bound, and #661 is a bug about a message nobody bounded.
    $sample = [];
    foreach (array_slice($available, 0, PP_FRIENDLY_SLOT_SAMPLE_MAX) as $name) {
        $clean = _pp_clean_reflected_text((string) $name, PP_REFLECTED_NAME_MAX);
        // A name made entirely of format characters cleans away to nothing. Printing it
        // would put an empty item in a list of settings ("are: , --hero-bg"), so drop it
        // — the complete list still ships in `alternatives` either way.
        if ($clean !== '') {
            $sample[] = $clean;
        }
    }

    // The completely-stated form is an EXHAUSTIVE claim, not a sample, so it may only be
    // made when the printed names really are all of them AND each survived the clean as
    // itself. Two names sharing their first 253 characters both truncate to the same
    // string, which would enumerate one setting twice and present that as the whole set;
    // a name that cleaned away leaves the set short. Either way the counted form below
    // is the honest answer, because "including" claims nothing about completeness.
    $intact = count($sample) === $total && count(array_unique($sample)) === $total;

    if ($total <= PP_FRIENDLY_SLOT_SAMPLE_MAX && $intact) {
        // Small enough to state completely, so state it completely and promise nothing
        // further — pointing at a disclosure holding the same few names would send the
        // author looking for something they have already read.
        return $opening . sprintf(
            $total === 1 ? ' Its one style setting is: %s.' : ' Its style settings are: %s.',
            implode(', ', $sample)
        );
    }

    // "the details below" rather than the disclosure's own label: the card can rename
    // its summary without turning this sentence into a wrong direction.
    if ($sample === []) {
        return $opening . sprintf(
            ' It has %d style settings. The full list is in the details below.',
            $total
        );
    }

    return $opening . sprintf(
        ' It has %d style settings, including %s. The full list is in the details below.',
        $total,
        implode(', ', $sample)
    );
}

/**
 * Builds a structured, user-friendly error response for preview failures.
 *
 * Returns `{error_code, user_message, alternatives, cross_component_hints,
 * raw_error}` on every path, plus `unknown_slots_unscanned` (int) on the
 * invalid_style_slot path only, and only when the cross-component scan hit
 * PP_CROSS_COMPONENT_HINT_MAX — so an ordinary rejection's shape is unchanged.
 *
 * Bounding raw_error here, at the single point where it is read, means every
 * return site below inherits it — including any added later, which a per-site
 * bound would silently miss.
 */
function _pp_build_friendly_error(WP_Error $error, array $params): array {
    $code    = $error->get_error_code();
    $raw_msg = _pp_clean_reflected_text($error->get_error_message(), PP_REFLECTED_ERROR_MAX);

    switch ($code) {
        case 'invalid_style_slot':
            $component_name  = '';
            $available_slots = [];

            // Which keys could carry a cross-component hint? The ones the VALIDATOR
            // drew from — recipe-expanded, `__recipe` and removals already dropped —
            // never a set re-derived here. Deriving it twice is what let the two
            // disagree (#626): `array_keys($params['style'])` misses every slot a
            // recipe contributed, so a recipe drifting out of its component's
            // declared set produced a rejection this branch could not explain, and
            // it counted the `__recipe` tracking key as a phantom unknown slot.
            $context = pp_rejected_slot_context($error);
            if ($context !== null) {
                $component_name  = $context['component_name'];
                $available_slots = $context['available_slots'];
                $invalid_slots   = array_diff($context['candidate_slots'], array_keys($available_slots));
            } else {
                // No authoritative context, so no rejection to answer from: this is a
                // hand-built error, or one from a producer that stamps none. Best
                // effort from the composition as it reads NOW, which is what this
                // branch always did before #626 — and what the bare-WP_Error cases in
                // tests/ActionsTest.php pin. The component-not-found answer belongs to
                // this path only: when the validator did supply context it resolved
                // the component itself, so "I couldn't find that component" would
                // contradict the rejection in hand rather than explain it.
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
                    $component_name  = $composition[$idx]['component'] ?? '';
                    $available_slots = pp_get_style_slots($component_name);
                }
                $invalid_slots = array_diff(array_keys($params['style'] ?? []), array_keys($available_slots));
            }

            $available = array_keys($available_slots);

            // Cross-component hint: does this slot exist on a different component?
            //
            // Each unknown key costs a pass over every registered component and every
            // slot it declares, so the size of this list sets both the work done and
            // the size of the object emitted. Bound it here, once, before the scan.
            $cross_hints   = (object) [];
            $invalid_slots = array_values($invalid_slots);

            // The cap applies to the keys BEFORE any matching, because bounding the
            // scan is the point — deciding which keys are worth reporting would mean
            // doing the very work the cap exists to avoid. So when it fires, keys
            // that would have produced a hint can be among the ones dropped, and the
            // count below reports unexamined KEYS, not suppressed hints.
            $slots_unscanned = count($invalid_slots) - PP_CROSS_COMPONENT_HINT_MAX;
            if ($slots_unscanned > 0) {
                $invalid_slots = array_slice($invalid_slots, 0, PP_CROSS_COMPONENT_HINT_MAX);
            } else {
                $slots_unscanned = 0;
            }

            $all_components = pp_get_registered_components();
            foreach ($invalid_slots as $invalid_slot) {
                // Defence in depth rather than a live path, and the reason is an
                // invariant worth stating: a key reaches either assignment below only
                // by equalling a declared slot name (exact) or by sharing a normalized
                // suffix with one (suffix). Declared names are clean and at most 39
                // characters, and neither a name carrying stripped characters nor one
                // past the length budget can satisfy either test — the comparisons run
                // on the RAW key. So wherever $reflected is used below it is equal to
                // $invalid_slot, and the cleaning is a no-op that costs one call and
                // keeps this from being a premise the registry could stop satisfying.
                $reflected = _pp_clean_reflected_text((string) $invalid_slot, PP_REFLECTED_NAME_MAX);
                $suffix = preg_replace('/^--[a-z]+-/', '--*-', $invalid_slot);
                foreach ($all_components as $other_name => $other_schema) {
                    if ($other_name === $component_name) continue;
                    $other_slots = pp_get_style_slots($other_name);
                    // Exact match
                    if (isset($other_slots[$invalid_slot])) {
                        $cross_hints->{$reflected} = [
                            'component' => $other_name,
                            'slot'      => $reflected,
                            'match'     => 'exact',
                        ];
                        break;
                    }
                    // Suffix match: strip component prefix, compare
                    foreach ($other_slots as $other_slot_name => $other_slot_def) {
                        $other_suffix = preg_replace('/^--[a-z]+-/', '--*-', $other_slot_name);
                        if ($suffix === $other_suffix) {
                            $cross_hints->{$reflected} = [
                                'component' => $other_name,
                                'slot'      => $other_slot_name,
                                'match'     => 'suffix',
                            ];
                            break 2;
                        }
                    }
                }
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
                // $context !== null is exactly "the rejection carried its own candidate
                // set", which is what licenses quoting a single rejected name (#626).
                $user_message = _pp_no_hint_slot_message(
                    $component_name,
                    $available,
                    $invalid_slots,
                    $context !== null
                );
            }

            $response = [
                'error_code'            => $code,
                'user_message'          => $user_message,
                'alternatives'          => $available,
                'cross_component_hints' => $cross_hints,
                'raw_error'             => $raw_msg,
            ];
            // Present only when the bound actually applied, so the response shape on
            // every ordinary rejection is exactly what it was before. Named for what
            // it counts: keys that were never examined. Most unknown keys yield no
            // hint even when scanned, so calling it a count of omitted hints would
            // overstate what was lost.
            if ($slots_unscanned > 0) {
                $response['unknown_slots_unscanned'] = $slots_unscanned;
            }
            return $response;

        case 'invalid_style_value':
            // Extract the slot name, type, and description from schema.
            $slot_name   = '';
            $type_hint   = '';
            $slot_desc   = '';
            $slot_default = '';
            if (preg_match('/^Style slot "([^"]+)"/', $raw_msg, $m)) {
                // Cleaned once, then used for both the schema lookup and the message.
                // A name that this changes cannot match a declared slot anyway, and a
                // declared name (39 characters at the longest) passes through untouched.
                $slot_name = _pp_clean_reflected_text($m[1], PP_REFLECTED_NAME_MAX);
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

    if ($type === 'length-or-none') {
        // The width caps whose declared default is `none` — the band-geometry cap
        // (#579) and the four uncapped text measures (#578). This is the ONE length family whose
        // grammar can express "remove the cap", so the suggestion is the keyword
        // itself rather than the `100%` workaround a plain `length` slot needs.
        return 'Try "none" to remove the cap entirely, or a value with a CSS unit (e.g. 60rem, 100%).';
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
        // Scoped to style_component because that is the action whose validator
        // rejections carry a slot vocabulary worth structuring (available slots,
        // cross-component hints). Every other action reports its message as-is below.
        if ($name === 'style_component') {
            // A style_component failure reports the validator's own verdict, structured
            // for the chat UI (#607). There is deliberately no repair-and-retry hop: a
            // slot name the component doesn't declare is invalid_style_slot, the same
            // answer every other surface gives. The response carries the rejected name
            // (raw_error) and the declared names (alternatives) rather than a preview of
            // a slot the author never asked for. The explicit return keeps the two
            // wp_send_json_error calls mutually exclusive without relying on wp_die().
            wp_send_json_error(_pp_build_friendly_error($result, $params));
            return;
        }

        // THE PREVIEW PATH'S GENERAL BRANCH, cleaned like its execute twin (#647). The
        // branch above ships _pp_build_friendly_error(), which routes every name and
        // message it reflects through this same owner; this fallthrough shipped the
        // validator message RAW. Same endpoint family, same `data` field, same renderer
        // in assets/js/pp-ai-chat.js — so whether a stored ANSI or bidi sequence quoted
        // by a validator message reached the chat card was a detail of which rejection
        // branch the request happened to take.
        wp_send_json_error(_pp_clean_reflected_text($result->get_error_message(), PP_REFLECTED_ERROR_MAX));
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
        // The OTHER of this endpoint's two error sources, cleaned like the payload one
        // below (#647). Both land in the same `data` field and are rendered by the same
        // client code, so one of them being raw made the guarantee un-statable.
        return ['ok' => false, 'data' => _pp_clean_reflected_text($result->get_error_message(), PP_REFLECTED_ERROR_MAX)];
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
 * failure collapses to its message string.
 *
 * BOTH SHAPES CARRY A CLEANED MESSAGE (#647) — see the comment on the first statement.
 * The message's MEANING is unchanged; only bytes that were never legible are.
 *
 * @param array $result  The failed action/apply result array.
 * @param array $params  The executed params (source of expected_version + post_id).
 * @return string|array
 */
function _pp_ai_execute_error_payload(array $result, array $params) {
    // CLEANED ONCE, FOR BOTH ARMS (#647). The PREVIEW path runs every validator message it
    // echoes through _pp_clean_reflected_text() at PP_REFLECTED_ERROR_MAX; this, the EXECUTE
    // path, reflected `$result['error']` VERBATIM. Same endpoint, same `data` field, same
    // renderer in assets/js/pp-ai-chat.js — so a stored ANSI or bidi sequence quoted by a
    // validator message reached the chat card on one path and not the other, and which path
    // a step took was a detail of whether it had been previewed or applied.
    //
    // ON THE SERVER SIDE OF THE WIRE, deliberately. A second strip in JavaScript would be a
    // second definition of "clean" that could only drift from this one, and the client
    // cannot repair invalid UTF-8 the way this helper does. What the client still owns is
    // the LENGTH it renders under (#793) — and that bound is the server's own number, which
    // is what tests/ChatUndoBoundTrait.php pins.
    //
    // The fields AROUND it need nothing: an integer version and a literal code.
    $error = _pp_clean_reflected_text((string) ($result['error'] ?? 'Execution failed.'), PP_REFLECTED_ERROR_MAX);

    if (($result['error_code'] ?? '') === 'composition_conflict') {
        $current = null;
        if (isset($params['post_id']) && is_numeric($params['post_id'])) {
            $current = pp_get_composition_marker((int) $params['post_id'])['version'];
        }
        return [
            'error'            => $error,
            'error_code'       => 'composition_conflict',
            'expected_version' => _pp_action_expected_version($params),
            'current_version'  => $current,
        ];
    }
    return $error;
}

// ── Model-facing rejection note (#704) ──────────────────────────────────────
//
// A refused proposal used to reach exactly one participant. The error rendered to the
// OPERATOR, and the model that authored the bad step never learned why it was refused —
// so the correction, which already names the offending key and lists the alternatives,
// could only travel back by a human retyping it. Ruling D-2 closes the mechanical half:
// the rejection re-enters the model's conversation. It deliberately does NOT close the
// autonomy half — the retry is proposed to the operator, never sent automatically —
// which is why nothing in this file sends anything. It only WRITES A SENTENCE; the chat
// client appends it to its conversation and the next operator turn carries it.
//
//   pp_ai_execute_batch ──▶ ok:false envelope ──▶ _pp_ai_batch_rejection_note()
//   _pp_batch_unreadable_refusal ──▶ {error,code} ──▶ _pp_ai_refusal_note()
//                                          │
//                                          ▼            (assets/js/pp-ai-chat.js)
//                                    `model_note`  ──▶  conversation.push({role:'user'})
//                                                  ──▶  "Ask the AI to fix it" button
//
// WHY THE SERVER WRITES THE SENTENCE. The bound has to have one owner. The text quotes a
// validator message, and lib/actions.php:702 says out loud that this message is NOT
// bounded on the batch path ("Bounding what a message reflects is #647/#649's axis").
// The convention for reflected validator text lives in THIS file — PP_REFLECTED_ERROR_MAX
// and _pp_clean_reflected_text() — so writing the sentence here reuses it instead of
// inventing a second answer in JavaScript. PP_CHAT_RENDER_ERROR_MAX is not that answer
// and says so in its own docblock: it is a LAYOUT bound on a string the client invents.
//
// TRUST. Every byte of the note is server-built. What it REFLECTS is caller-derived, and
// the caller is usually the model itself: the rejected prop key or slot name is a value
// the model wrote into the proposal it sent, so this closes a loop the model holds both
// ends of. That cannot escalate anything — the model already authored those bytes into a
// message of its own — but unbounded it is a context-flooding vector and uncleaned it is
// a formatting-deception one (the zero-width and bidi sets render as nothing while
// changing what a reader, human or model, sees). _pp_clean_reflected_text() answers both,
// which is the whole reason to reuse it rather than concatenate. The message can also
// quote SITE content rather than model content (a component name read from the stored
// composition, on _pp_no_hint_slot_message()'s fallback path) — that crosses no new
// boundary, because pp_ai_format_messages() already ships the page's whole composition
// JSON to the same provider in the same request.
//
// WHAT DELIBERATELY GETS NO NOTE, and it is a question about CLASS, not about plumbing:
//   - a composition_conflict, and a missing baseline. The page moved under a proposal
//     that was correct when written. The model cannot repair that, and the repair the UI
//     already offers is Re-read & re-preview, which re-runs the SAME steps without asking
//     the model anything. A note there would be context the model is never given a turn
//     to act on, describing state that the re-read is about to replace.
//   - the transport failure (the client's fetch .catch). There is no server verdict at
//     all, so we do not know whether the write landed; telling the model "network error"
//     invites it to propose the same write twice.
//   - 'Permission denied.' and the malformed-request refusals, which return a bare string
//     rather than a payload. Nothing the model can write changes a capability check, and a
//     step naming an unregistered capability never reaches this endpoint — the proposal
//     card buckets it as `rejected` before Apply is ever offered.
// So `model_note` is present exactly when a rejection is the model's to answer, and the
// client's rule is presence, not interpretation.

/**
 * The clause describing what a failed batch left behind, said at the envelope's own
 * confidence (#704).
 *
 * Mirrors the rule ppChatRollbackSentence() enforces on the operator's side (#755):
 * `rolled_back: true` is not a clean revert until `rollback_errors` has been read, and a
 * channel that is absent or not a list is an UNKNOWN rather than a clean one. The model
 * gets the same three-state answer for the same reason the operator does — a confident
 * "everything was reverted" over a page whose restore was withheld is exactly the false
 * claim #755 removed, and it would be worse here, where the next proposal is written
 * against it.
 *
 * THE CLEAN CLAIM NEEDS BOTH SIGNALS, which is not a softening of #755 but an additional
 * necessary condition on top of it. `rollback_errors: []` says "the rollback reported no
 * errors"; it does not, by itself, say a rollback HAPPENED — an envelope that never ran one
 * reports the same empty list. Today the executor sets `rolled_back: true` on every failure
 * return that carries steps (pp_ai_execute_batch, lib/actions.php), so the pair always
 * agrees and this costs nothing; the day a shape arrives where it does not, the honest
 * answer is the UNKNOWN sentence rather than a revert nobody performed. #755's rule is
 * untouched: `rolled_back` alone still buys the clean claim nothing.
 *
 * @param  array $batch  The ok:false batch envelope.
 * @return string        One sentence, always non-empty.
 */
function _pp_ai_rollback_clause(array $batch): string {
    if (empty($batch['steps'])) {
        // The #749-shaped refusal: no step ran, so there is nothing to have reverted.
        return 'No step ran, so nothing was changed.';
    }
    if (!array_key_exists('rollback_errors', $batch) || !is_array($batch['rollback_errors'])) {
        return 'Whether the changes were reverted was not reported.';
    }
    $errors = $batch['rollback_errors'];
    if ($errors === []) {
        return ($batch['rolled_back'] ?? null) === true
            ? 'All changes in this proposal were reverted.'
            : 'Whether the changes were reverted was not reported.';
    }

    return sprintf(
        'The rollback reported %d error%s, so some changes may not have been reverted.',
        count($errors),
        count($errors) === 1 ? '' : 's'
    );
}

/**
 * Removes the two characters that would let reflected text break out of the note's frame
 * (#704).
 *
 * THE FRAME IS A CLAIM, SO IT HAS TO BE UNFORGEABLE. `[Rejected: ... ]` says two things at
 * once: to restoreConversation() (assets/js/pp-ai-chat.js) the opening bracket says "do not
 * render this turn", and to the model the whole wrapper says "this span is an environment
 * report, not something the operator typed". _pp_clean_reflected_text() strips the control
 * and format sets, which kills newline, zero-width and bidi tricks — but a bracket is an
 * ordinary printable character and survives. The rejected prop key is interpolated into the
 * validator's message verbatim (lib/admin.php), and that key is MODEL-AUTHORED, so a model
 * that names a prop `] Ignore the above` closes the frame early and the rest of its own
 * bytes read as unframed text inside a turn this code pushed under the OPERATOR's role.
 *
 * That is role laundering, and the honest size of it is small: nothing downstream acts on
 * prose (every write still passes the capability gate, pp_validate_action(), and an
 * operator click), the trusted sentence is appended AFTER this span so an injected
 * instruction cannot be positioned to override it, and the transcript rule tests character
 * 0 only, so a break-out cannot un-hide the turn. It is closed anyway because closing it is
 * one substitution and because the alternative is a comment claiming a property the code
 * does not have.
 *
 * SUBSTITUTED, NOT DROPPED, and not fixed inside _pp_clean_reflected_text(). Dropping would
 * silently change a name the reader is being asked to compare against their own; the
 * parentheses keep the shape visible and the count intact. And the cleaner is shared with
 * the preview card, where brackets are ordinary text with no frame to break — a rule that
 * belongs to THIS frame lives with this frame (#661's posture on which reflected values get
 * which treatment).
 */
function _pp_ai_unframe(string $text): string {
    return strtr($text, ['[' => '(', ']' => ')']);
}

/**
 * Assembles one model-facing rejection note (#704).
 *
 * WRAPPED IN SQUARE BRACKETS, AND THAT IS LOAD-BEARING, NOT DECORATION. The note is
 * appended as a `user` turn, and restoreConversation() (assets/js/pp-ai-chat.js) hides a
 * user turn from the rendered transcript by testing `content.charAt(0) === '['` — the
 * same rule that already hides `[Applied changes: ...]`. Drop the bracket and every past
 * rejection reappears as a chat bubble the operator never typed on the next reload.
 * The bracket does a second job in the model's context: it marks the turn as an
 * environment report rather than something the operator said, which is the provenance
 * `internal: true` cannot carry (pp_ai_format_messages() strips unknown keys before the
 * request leaves, by design — see its docblock).
 *
 * EVERY reflected part is cleaned, and each at the budget its own kind already has:
 * the validator message at PP_REFLECTED_ERROR_MAX, the code and the action name at
 * PP_REFLECTED_NAME_MAX. None of the three is guaranteed short by anything upstream —
 * codes and action names are our own literals TODAY, and "the registry happens to be
 * small" is an assumption about content, not a bound (#661's lesson, same helper).
 *
 * BOUND. Fixed prose, plus one cleaned code and one cleaned action name at
 * PP_REFLECTED_NAME_MAX each, plus one integer offset, plus one cleaned message at
 * PP_REFLECTED_ERROR_MAX — arithmetic, not an assumption about what the validators say.
 * `$lead` and `$state` are deliberately NOT re-clamped here: both are composed by this
 * file's own two callers out of fixed prose and values already cleaned on the way in, so a
 * second clamp would be a second owner for a question the callers already answer. That is
 * a claim about THOSE two callers, so a third one has to keep it — clean anything
 * caller-derived before it becomes a `$lead`, the way _pp_ai_batch_rejection_note() cleans
 * the action name.
 *
 * @param  string   $lead    What was refused, already fixed prose.
 * @param  string   $code    The rejection's machine code ('' when it carries none).
 * @param  string   $message The validator's message.
 * @param  int|null $index   Composition band that owns the rejection (#642), or null.
 * @param  string   $state   The rollback clause.
 * @return string
 */
function _pp_ai_rejection_note(string $lead, string $code, string $message, ?int $index, string $state): string {
    $note = '[Rejected: ' . $lead;

    $clean_code = _pp_ai_unframe(_pp_clean_reflected_text($code, PP_REFLECTED_NAME_MAX));
    if ($clean_code !== '') {
        $note .= ' error_code: ' . $clean_code . '.';
    }

    // The AUTHORITATIVE locator (#642), and the field this note exists to deliver: every
    // composition-mutating action validates the WHOLE composition, so the blocking band is
    // routinely one the proposal never named. Without it a model "fixes" the band it wrote
    // and gets the identical string back — the exact loop #704 is about.
    if ($index !== null) {
        $note .= ' Blocking composition band: index ' . $index . '.';
    }

    $clean_message = _pp_ai_unframe(_pp_clean_reflected_text($message, PP_REFLECTED_ERROR_MAX));
    if ($clean_message !== '') {
        $note .= ' Reason: ' . $clean_message;
        // The validator's messages are sentences and mostly end in one; do not double it.
        if (!in_array(substr($clean_message, -1), ['.', '!', '?'], true)) {
            $note .= '.';
        }
    }

    // Ruling D-2 in one sentence, said IN the context rather than only enforced around it:
    // the operator, not this note, decides whether a corrected proposal gets sent.
    return $note . ' ' . $state
        . ' The operator decides whether to retry — do not re-send this proposal unless asked.]';
}

/**
 * The note for a batch that RAN and had a step refused, or null when there is nothing
 * for the model to answer (#704).
 *
 * @param  array $batch  A pp_ai_execute_batch() envelope.
 * @return string|null
 */
function _pp_ai_batch_rejection_note(array $batch): ?string {
    if (!empty($batch['ok'])) {
        return null;
    }

    // THE STEP-LESS REFUSAL ARRIVES HERE TOO, and assuming it did not was a real gap.
    // _pp_ai_execute_batch_response() runs its own copy of the #749 gate and answers that
    // one through wp_send_json_error, so the common case never reaches this function. But
    // the two gates evaluate one rule at two moments against two reads, and the window
    // between them is documented at that call site: a repair landing in it makes the entry
    // point admit and the EXECUTOR refuse, and the executor's refusal comes back on the
    // success branch as this step-less envelope. Bailing on it left one path to the #749
    // refusal carrying a note and the other silent — same refusal, same message, same
    // repair, and the operator's card grew an affordance or did not depending on which
    // microsecond the repair landed in. The envelope already carries the two fields the
    // refusal note reads, so this delegates rather than re-deriving them.
    // Tested on the STEPS and the message, not on failed_at: this envelope and a successful
    // batch both carry failed_at: null, so that field cannot tell them apart. A step-less
    // envelope with no message is neither, and gets nothing.
    if (empty($batch['steps']) && (string) ($batch['error'] ?? '') !== '') {
        return _pp_ai_refusal_note($batch);
    }

    // DISCRIMINATE ON THE FAILING STEP, never on failed_at alone — a SUCCESSFUL batch also
    // returns failed_at: null, and so does the refusal handled just above.
    $failed_at = $batch['failed_at'] ?? null;
    if (!is_int($failed_at) || !isset($batch['steps'][$failed_at]) || !is_array($batch['steps'][$failed_at])) {
        return null;
    }

    $step = $batch['steps'][$failed_at];
    $code = (string) ($step['error_code'] ?? '');

    // The page moved; the proposal was not wrong. See the block comment above for why this
    // class gets nothing rather than a differently-worded note.
    if ($code === 'composition_conflict') {
        return null;
    }

    // Unframed like the other two reflected spans: the action name is echoed from the step
    // the MODEL wrote, so it is caller bytes wearing a familiar shape, not a literal.
    $action = _pp_ai_unframe(_pp_clean_reflected_text((string) ($step['action'] ?? ''), PP_REFLECTED_NAME_MAX));
    $lead   = sprintf(
        'step %d%s was refused, so this proposal was not applied.',
        $failed_at + 1,
        $action === '' ? '' : ' (' . $action . ')'
    );

    $index = isset($step['index']) && is_int($step['index']) ? $step['index'] : null;

    return _pp_ai_rejection_note(
        $lead,
        $code,
        (string) ($step['error'] ?? ''),
        $index,
        _pp_ai_rollback_clause($batch)
    );
}

/**
 * The note for the pre-execution refusal (#749): the batch named a page whose stored
 * composition cannot be read, so nothing ran (#704).
 *
 * This one IS the model's to answer. The refusal's own message prescribes the repair, and
 * since #756 the model has a route to it — a lone update_composition or
 * restore_composition — which the system prompt already teaches
 * (_pp_ai_page_context_corrupt_block, lib/ai-context.php). Before this, the model was told
 * to take a route and then never told when it had been turned back from it.
 *
 * EXCEPT WHEN THE REFUSAL IS ABOUT THE READ (#833), and this is the same CLASS question the
 * block comment above answers for composition_conflict, a missing baseline, a transport
 * failure and a capability denial: none of those is repaired by the model rewriting its
 * proposal, so none of them gets a note. A batch blocked because the gate could not reach
 * the database row is squarely that class — it is a site fault, and the branch that renders
 * it was written specifically NOT to prescribe a repair, because nothing has shown the page
 * to be damaged. Handing the model a note here would also hand the operator the repair
 * affordance the client attaches to any note (offerRepair, assets/js/pp-ai-chat.js), whose
 * payload teaches the lone whole-composition write: on a page that may be perfectly healthy,
 * and against a fault the write cannot fix.
 *
 * NOT A CODE LIST, which the call site's own comment rightly warns against. The test is the
 * single constant that OWNS "this is a statement about the read, not about the page", so
 * this asks the owner rather than re-deriving a set that a fourth classification would fall
 * out of silently.
 *
 * @param  array $refusal  ['error' => string, 'error_code' => string].
 * @return string|null     Null when the refusal is not the model's to answer.
 */
function _pp_ai_refusal_note(array $refusal): ?string {
    if (($refusal['error_code'] ?? '') === PP_BATCH_TARGET_UNVERIFIABLE) {
        return null;
    }

    return _pp_ai_rejection_note(
        'this proposal was refused before any step ran.',
        (string) ($refusal['error_code'] ?? ''),
        (string) ($refusal['error'] ?? ''),
        null,
        // Asked, not repeated. The empty-steps arm of the rollback clause IS this sentence,
        // and a second copy here is one reword away from the two notes disagreeing about
        // what a refusal left behind — in a change whose whole thesis is one owner per
        // sentence.
        _pp_ai_rollback_clause(['steps' => []])
    );
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
 * mandate (A1), refuses the batch when any named page's stored composition is
 * unreadable (#749) — unless it is the one-step corrupt-page repair ruling D-1
 * carves out (#756) — then threads the baseline map through pp_ai_execute_batch().
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

    // Fail-closed unreadable-target gate (#749), the same up-front shape as the baseline
    // mandate above. The invariant and its rationale live with the executor's own copy of
    // this gate (pp_ai_execute_batch, lib/actions.php) — that one is the backstop that
    // makes the data-loss fix hold for every caller.
    //
    // What is local, and the only reason this is repeated here: the CHAT surface has to
    // answer through wp_send_json_error with the structured {error, error_code} payload
    // the client already renders on its !resp.success branch (assets/js/pp-ai-chat.js).
    // Left to the executor, the refusal would arrive on the SUCCESS branch as a step-less
    // batch envelope, which is the one shape that renderer has no failing step to show.
    // Detection and wording are single-owned by lib/actions.php, so the gates cannot drift.
    //
    // The #756 carve-out travels with the gate, not beside it: both sites ask
    // _pp_batch_unreadable_refusal(), so neither can apply a different RULE about which
    // batches are admitted. That is why the exemption is not spelled out at either call
    // site — there is nothing to keep in sync.
    //
    // ONE RULE IS NOT ONE ANSWER, and the gap is a concurrent write rather than drift.
    // The two gates evaluate that shared rule at two moments, against two `unreadable`
    // maps built by two reads (this one's detector, the executor's own capture), and
    // since #833 BOTH sides of each — the refusal's classification and the carve-out's —
    // read the database row, so neither gate can be answered by a stale cached copy. Two
    // authoritative reads at two moments still see two moments: a repair landing between
    // them can make this gate admit and the executor refuse. Nothing
    // runs and nothing is written; the executor's step-less envelope arrives on the
    // SUCCESS branch, which is the shape ppChatBatchWasRefusedUpFront() exists to render
    // (assets/js/pp-ai-chat.js) — see its docblock, which names this window.
    $unreadable_error = _pp_batch_unreadable_refusal($normalized, _pp_batch_unreadable_targets($normalized));
    if ($unreadable_error !== null) {
        // The model's copy of this refusal (#704). Attached AT THE SITE rather than
        // re-derived from the code, and #833 is the argument arriving: the codes were
        // 'decode_error' / 'unexpected_shape', and are now also 'composition_unverifiable'
        // — which is not even a classification, but a statement that the gate could not
        // reach the row to make one. A note gated on a list of codes would have stopped
        // firing for it silently. The site knows what it refused; the list does not.
        //
        // WHICH REFUSALS EARN A NOTE IS THE NOTE OWNER'S CALL, not this site's: the read
        // failure is a site fault the model cannot repair, so _pp_ai_refusal_note() declines
        // it and the key stays ABSENT rather than null — the shape the client's "is there a
        // note?" rule already expects.
        $note = _pp_ai_refusal_note($unreadable_error);
        if ($note !== null) {
            $unreadable_error['model_note'] = $note;
        }
        return ['ok' => false, 'data' => $unreadable_error];
    }

    $batch = pp_ai_execute_batch($normalized, $baselines);

    // The model's copy of a step rejection (#704). Attached HERE and not inside
    // pp_ai_execute_batch(): the executor is shared with WP-CLI (`wp pp action execute`),
    // which has no conversation to append to, so a note in its envelope would be a field
    // that exists for one caller. The chat entry point is the surface that has a model.
    // Null for everything that is not the model's to answer — see the block comment on
    // _pp_ai_batch_rejection_note()'s neighbours — and the key is then ABSENT rather than
    // null, so the client's rule can stay "is there a note?" with no second state.
    $note = _pp_ai_batch_rejection_note($batch);
    if ($note !== null) {
        $batch['model_note'] = $note;
    }

    return ['ok' => true, 'data' => $batch];
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
