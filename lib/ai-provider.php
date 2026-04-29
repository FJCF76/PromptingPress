<?php
/**
 * lib/ai-provider.php — PromptingPress LLM Provider Proxy
 *
 * Streams chat completions from an OpenAI-compatible API.
 * Uses raw curl with CURLOPT_WRITEFUNCTION for chunk-by-chunk streaming.
 * WordPress's wp_remote_post() does not support streaming responses.
 *
 * Loaded unconditionally (not gated behind is_admin()) because
 * ai-stream.php needs it and runs outside admin context.
 */

// ── Option Keys & Defaults ────────────────────────────────────────────────
// Defined here (not ai-settings.php) because ai-stream.php needs them
// outside admin context. ai-settings.php is admin-only (UI + Settings API).

if (!defined('PP_AI_OPT_PROVIDER')) {
    define('PP_AI_OPT_PROVIDER', 'pp_ai_provider');
    define('PP_AI_OPT_BASE_URL', 'pp_ai_base_url');
    define('PP_AI_OPT_API_KEY',  'pp_ai_api_key');
    define('PP_AI_OPT_MODEL',    'pp_ai_model');

    define('PP_AI_DEFAULT_PROVIDER', 'github_models');
    define('PP_AI_DEFAULT_BASE_URL', 'https://models.github.ai/inference/chat/completions');
    define('PP_AI_DEFAULT_MODEL',    'openai/gpt-5-chat');
}

// ── Config Helpers ────────────────────────────────────────────────────────

/**
 * Returns true if the AI provider has a saved API key.
 */
function pp_ai_is_configured(): bool {
    return !empty(get_option(PP_AI_OPT_API_KEY, ''));
}

/**
 * Returns the AI provider configuration.
 */
function pp_ai_get_config(): array {
    return [
        'provider' => get_option(PP_AI_OPT_PROVIDER, PP_AI_DEFAULT_PROVIDER),
        'base_url' => get_option(PP_AI_OPT_BASE_URL, PP_AI_DEFAULT_BASE_URL),
        'api_key'  => get_option(PP_AI_OPT_API_KEY, ''),
        'model'    => get_option(PP_AI_OPT_MODEL, PP_AI_DEFAULT_MODEL),
    ];
}

// ── Streaming Completion ───────────────────────────────────────────────────

/**
 * Streams a chat completion from the configured provider.
 *
 * @param array    $messages   OpenAI-compatible messages array.
 * @param callable $on_chunk   Called with each text delta: $on_chunk(string $text).
 * @return array   ['ok' => bool, 'error' => string|null, 'full_response' => string]
 */
function pp_ai_stream_completion(array $messages, callable $on_chunk): array {
    $config = pp_ai_get_config();

    if (empty($config['api_key'])) {
        return ['ok' => false, 'error' => 'API key not configured.', 'full_response' => ''];
    }

    if (empty($config['base_url'])) {
        return ['ok' => false, 'error' => 'Base URL not configured.', 'full_response' => ''];
    }

    $body = wp_json_encode([
        'model'    => $config['model'],
        'messages' => $messages,
        'stream'   => true,
    ]);

    $full_response = '';
    $http_code = 0;
    $error_body = '';
    $buffer = '';

    $ch = curl_init($config['base_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['api_key'],
            'Accept: text/event-stream',
        ],
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$http_code) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
                $http_code = (int) $m[1];
            }
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$full_response, &$on_chunk, &$http_code, &$error_body, &$buffer) {
            // If non-2xx, accumulate for error parsing
            if ($http_code >= 400) {
                $error_body .= $data;
                return strlen($data);
            }

            // Append to buffer for line-based parsing
            $buffer .= $data;

            // Process complete lines
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if ($line === '' || $line === 'data: [DONE]') {
                    continue;
                }

                if (strpos($line, 'data: ') !== 0) {
                    continue;
                }

                $json_str = substr($line, 6);
                $chunk = json_decode($json_str, true);

                if (!is_array($chunk)) {
                    continue;
                }

                $delta = $chunk['choices'][0]['delta']['content'] ?? null;
                if ($delta !== null && $delta !== '') {
                    $full_response .= $delta;
                    $on_chunk($delta);
                }
            }

            // Check if client disconnected
            if (connection_aborted()) {
                return 0; // signal curl to stop
            }

            return strlen($data);
        },
    ]);

    curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    // Handle curl-level errors
    if ($curl_errno && $curl_errno !== CURLE_WRITE_ERROR) {
        return [
            'ok'            => false,
            'error'         => 'Connection failed: ' . $curl_error,
            'full_response' => $full_response,
        ];
    }

    // Handle HTTP errors
    if ($http_code >= 400) {
        $error_msg = pp_ai_parse_error_response($http_code, $error_body);
        return [
            'ok'            => false,
            'error'         => $error_msg,
            'full_response' => $full_response,
        ];
    }

    return [
        'ok'            => true,
        'error'         => null,
        'full_response' => $full_response,
    ];
}

// ── Non-Streaming Completion (AJAX fallback) ───────────────────────────────

/**
 * Sends a non-streaming chat completion request.
 * Used as AJAX fallback when SSE streaming fails.
 *
 * @param array $messages  OpenAI-compatible messages array.
 * @return array  ['ok' => bool, 'error' => string|null, 'full_response' => string]
 */
function pp_ai_completion(array $messages): array {
    $config = pp_ai_get_config();

    if (empty($config['api_key'])) {
        return ['ok' => false, 'error' => 'API key not configured.', 'full_response' => ''];
    }

    $body = wp_json_encode([
        'model'    => $config['model'],
        'messages' => $messages,
    ]);

    $ch = curl_init($config['base_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['api_key'],
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Connection failed: ' . $curl_error, 'full_response' => ''];
    }

    if ($http_code >= 400) {
        $error_msg = pp_ai_parse_error_response($http_code, $response);
        return ['ok' => false, 'error' => $error_msg, 'full_response' => ''];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
        return ['ok' => false, 'error' => 'Unexpected response format.', 'full_response' => ''];
    }

    return [
        'ok'            => true,
        'error'         => null,
        'full_response' => $decoded['choices'][0]['message']['content'],
    ];
}

// ── Error Parsing ──────────────────────────────────────────────────────────

/**
 * Parses an error response from the LLM provider into a user-friendly message.
 */
function pp_ai_parse_error_response(int $http_code, string $body): string {
    // COUPLED: JS handleStreamError() matches "Check AI Settings" to show settings link.
    if ($http_code === 400) {
        return 'The provider rejected the request. This may indicate an unsupported model or parameter. Check AI Settings.';
    }

    if ($http_code === 401 || $http_code === 403) {
        return 'AI provider rejected the API key. Check AI Settings.';
    }

    if ($http_code === 429) {
        return 'Rate limited. Try again in a moment.';
    }

    if ($http_code === 404) {
        return 'Model not found by the provider. Check AI Settings.';
    }

    $decoded = json_decode($body, true);
    if (is_array($decoded) && isset($decoded['error']['message'])) {
        return 'Provider error: ' . wp_strip_all_tags($decoded['error']['message']);
    }

    return 'Provider error (HTTP ' . $http_code . ').';
}

// ── Proposal Parsing ───────────────────────────────────────────────────────

/**
 * Parses a full model response for action proposals.
 * Handles both bare JSON and markdown-fenced JSON blocks.
 *
 * @param string $response  The complete model response text.
 * @return array|null  Parsed proposal array, or null if no valid proposal found.
 */
function pp_ai_parse_proposal(string $response): ?array {
    // Try markdown-fenced JSON first (```json ... ```)
    if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $response, $matches)) {
        $candidate = trim($matches[1]);
        $parsed = json_decode($candidate, true);
        if (is_array($parsed) && !empty($parsed['proposal']) && isset($parsed['steps'])) {
            return pp_ai_validate_proposal($parsed);
        }
    }

    // Try bare JSON (find { ... } with "proposal": true)
    if (preg_match('/\{[^{}]*"proposal"\s*:\s*true[^{}]*"steps"\s*:\s*\[.*?\]\s*\}/s', $response, $matches)) {
        $parsed = json_decode($matches[0], true);
        if (is_array($parsed) && !empty($parsed['proposal']) && isset($parsed['steps'])) {
            return pp_ai_validate_proposal($parsed);
        }
    }

    // Try finding any JSON object with "proposal" key
    $start = strpos($response, '{"proposal"');
    if ($start !== false) {
        $rest = substr($response, $start);
        $parsed = json_decode($rest, true);
        if (is_array($parsed) && !empty($parsed['proposal']) && isset($parsed['steps'])) {
            return pp_ai_validate_proposal($parsed);
        }
    }

    return null;
}

/**
 * Validates a parsed proposal structure.
 * Returns the proposal if valid, null if malformed.
 */
function pp_ai_validate_proposal(array $proposal): ?array {
    if (!isset($proposal['steps']) || !is_array($proposal['steps'])) {
        return null;
    }

    $valid_steps = [];
    $rejected_steps = [];
    $actions = pp_get_registered_actions();
    $applies = pp_get_registered_applies();

    foreach ($proposal['steps'] as $step) {
        if (!isset($step['type'], $step['name'])) {
            return null;
        }
        if (!in_array($step['type'], ['action', 'apply'], true)) {
            return null;
        }
        if (!isset($step['params']) || !is_array($step['params'])) {
            return null;
        }

        // Check if the capability is actually registered
        $registered = ($step['type'] === 'action')
            ? isset($actions[$step['name']])
            : isset($applies[$step['name']]);

        if ($registered) {
            $valid_steps[] = $step;
        } else {
            $rejected_steps[] = $step;
        }
    }

    if (empty($valid_steps) && empty($rejected_steps)) {
        return null;
    }

    $proposal['steps'] = $valid_steps;

    if (!empty($rejected_steps)) {
        $proposal['rejected'] = $rejected_steps;
    }

    return $proposal;
}
