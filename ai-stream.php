<?php
/**
 * ai-stream.php — PromptingPress SSE Streaming Transport
 *
 * Standalone entrypoint for AI chat streaming. Thin transport layer only.
 * Loads WordPress, checks auth, delegates to the provider proxy.
 *
 * POST body: { messages: [{role, content}], page_id?: int, nonce: string }
 * Response: text/event-stream with data: {json}\n\n chunks
 * Final: data: [DONE]\n\n
 *
 * Auth: WordPress cookie + nonce pp_ai_stream + edit_posts capability.
 */

// ── Bootstrap WordPress ────────────────────────────────────────────────────
// Walk up from theme directory to find wp-load.php.
$wp_load = dirname(__DIR__, 3) . '/wp-load.php';
if (!file_exists($wp_load)) {
    // Fallback: try ABSPATH if available
    $wp_load = (defined('ABSPATH') ? ABSPATH : '') . 'wp-load.php';
}
if (!file_exists($wp_load)) {
    http_response_code(500);
    echo 'WordPress not found.';
    exit;
}
require_once $wp_load;

// ── Request Validation ─────────────────────────────────────────────────────

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Read and parse POST body
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo 'Invalid request body.';
    exit;
}

// Verify nonce
$nonce = $input['nonce'] ?? '';
if (!wp_verify_nonce($nonce, 'pp_ai_stream')) {
    http_response_code(403);
    echo 'Invalid nonce.';
    exit;
}

// Check capability
if (!current_user_can('edit_posts')) {
    http_response_code(403);
    echo 'Insufficient permissions.';
    exit;
}

// Check AI configuration
if (!pp_ai_is_configured()) {
    http_response_code(400);
    echo 'AI provider not configured. Check AI Settings.';
    exit;
}

// ── Extract Parameters ─────────────────────────────────────────────────────

$conversation = $input['messages'] ?? [];
$page_id      = isset($input['page_id']) ? (int) $input['page_id'] : null;

if (empty($conversation)) {
    http_response_code(400);
    echo 'No messages provided.';
    exit;
}

// ── Set Up SSE ─────────────────────────────────────────────────────────────

// Prevent PHP from timing out during streaming
set_time_limit(0);
ignore_user_abort(true);

// Clear all output buffers (WordPress may have added some)
while (ob_get_level() > 0) {
    ob_end_clean();
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // nginx

// ── Assemble Messages ──────────────────────────────────────────────────────

$system_prompt = pp_ai_system_prompt();
$messages = pp_ai_format_messages($system_prompt, $conversation, $page_id);

// ── Stream Response ────────────────────────────────────────────────────────

$keepalive_interval = 12; // seconds
$last_chunk_time = time();
$first_token_received = false;

// Send initial keepalive
echo ": keepalive\n\n";
flush();

$result = pp_ai_stream_completion($messages, function (string $delta) use (&$last_chunk_time, &$first_token_received) {
    $first_token_received = true;
    $last_chunk_time = time();

    $event_data = wp_json_encode(['content' => $delta]);
    echo "data: {$event_data}\n\n";
    flush();
});

// If streaming failed, send error as SSE event
if (!$result['ok']) {
    $error_data = wp_json_encode(['error' => $result['error']]);
    echo "data: {$error_data}\n\n";
    flush();
}

// Parse for action proposals in the full response
$proposal = null;
if ($result['ok'] && $result['full_response']) {
    $proposal = pp_ai_parse_proposal($result['full_response']);
}

// Detect possible truncation: response has proposal-indicating language but
// no parseable proposal JSON. This helps the client show an informational message.
$truncated = false;
if ($result['ok'] && !$proposal && $result['full_response']) {
    $text = $result['full_response'];
    if (preg_match('/here.s (?:the |my |what I )?propos|proposed (?:changes|update|step)|I.ll propose|proposal.*:/i', $text)) {
        // Has proposal language but no valid proposal was parsed
        $truncated = true;
    }
}

// Send final event with proposal if found
$done_data = ['done' => true];
if ($proposal) {
    $done_data['proposal'] = $proposal;
}
if ($truncated) {
    $done_data['truncated'] = true;
}
echo "data: " . wp_json_encode($done_data) . "\n\n";
echo "data: [DONE]\n\n";
flush();

exit;
