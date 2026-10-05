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
 * Auth: WordPress cookie + nonce pp_ai_stream + edit_posts capability, plus edit_post on
 *       the page_id when one is given. Each refusal is a 403 marked `X-PP-Refusal: 1`,
 *       so the chat client can tell it from a 403 sent by a proxy in front of WordPress.
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
    header('X-PP-Refusal: 1');
    echo 'Invalid nonce.';
    exit;
}

// Check capability
if (!current_user_can('edit_posts')) {
    http_response_code(403);
    header('X-PP-Refusal: 1');
    echo 'Insufficient permissions.';
    exit;
}

// Per-page permission: the page this turn names goes into the model context, so the user
// must be able to edit it (pp_ai_page_context_permitted(), lib/ai-context.php). Checked
// before anything else is read or assembled, with the same refusal as the check above; a
// page that does not exist gets the same answer.
$page_id = isset($input['page_id']) ? (int) $input['page_id'] : null;
if (!pp_ai_page_context_permitted($page_id)) {
    http_response_code(403);
    header('X-PP-Refusal: 1');
    echo 'Insufficient permissions.';
    exit;
}

// Check AI configuration
if (!pp_ai_is_configured()) {
    http_response_code(400);
    echo 'AI provider not configured. Check Settings > Connectors.';
    exit;
}

// ── Extract Parameters ─────────────────────────────────────────────────────

$conversation = $input['messages'] ?? [];

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

// Composition CAS baseline (#404): capture the page's version right after the context the
// model reads is assembled, so the browser can store it as the conversation's per-page
// baseline and thread it back on write. Captured here — not at execute time — so the CAS
// covers the whole gap between the model reading the page and the user applying, which is
// where lost updates happen. Only when a page is in scope and still exists (a page the user
// may not edit was refused above, before anything was read).
$page_baseline = null;
if ($page_id && get_post($page_id)) {
    $page_baseline = [
        'post_id' => $page_id,
        'version' => pp_get_composition_marker($page_id)['version'],
    ];
}

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
// Ship the CAS baseline for the page the model just read (#404) so the chat UI stores it
// as this conversation's per-page baseline and threads it back on the next write.
if ($page_baseline !== null) {
    $done_data['page_baseline'] = $page_baseline;
}
echo "data: " . wp_json_encode($done_data) . "\n\n";
echo "data: [DONE]\n\n";
flush();

exit;
