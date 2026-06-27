<?php
/**
 * lib/screenshot.php — Screenshot Capture for Agent Operating Framework
 *
 * Delegates to PP_BROWSER_CMD for actual capture. PromptingPress owns
 * what to capture, when, and how to evaluate. The browser is pluggable.
 *
 * @since 0.3.0
 */

// ── Screenshot Directory ───────────────────────────────────────────────────

/**
 * Returns the screenshot storage directory. Creates it if needed.
 *
 * Configurable via PP_SCREENSHOT_DIR constant.
 *
 * @return string  Absolute path to screenshot directory.
 */
function pp_screenshot_dir(): string {
    if (defined('PP_SCREENSHOT_DIR') && PP_SCREENSHOT_DIR) {
        $dir = PP_SCREENSHOT_DIR;
    } elseif (defined('WP_CONTENT_DIR')) {
        $dir = WP_CONTENT_DIR . '/pp-screenshots';
    } else {
        $dir = dirname(dirname(get_template_directory())) . '/pp-screenshots';
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

// ── Screenshot Spec ────────────────────────────────────────────────────────

/**
 * Generates screenshot specs for a post across standard viewports.
 *
 * @param int    $post_id   WordPress post ID.
 * @param string $playbook  Playbook name (used in output path).
 * @return array[]  Array of spec arrays, one per viewport.
 */
function pp_screenshot_spec(int $post_id, string $playbook): array {
    $url = get_permalink($post_id);
    $base_dir = pp_screenshot_dir() . '/' . $playbook . '/' . $post_id;
    $timestamp = date('Ymd-His');

    return [
        [
            'url'    => $url,
            'width'  => 1280,
            'height' => 800,
            'output' => $base_dir . '/' . $timestamp . '-desktop.png',
        ],
        [
            'url'    => $url,
            'width'  => 375,
            'height' => 812,
            'output' => $base_dir . '/' . $timestamp . '-mobile.png',
        ],
    ];
}

// ── Screenshot Capture ─────────────────────────────────────────────────────

/**
 * Captures a screenshot by delegating to PP_BROWSER_CMD.
 *
 * @param array $spec  Screenshot spec: url, width, height, output.
 * @return array{ok: bool, path: ?string, error: ?string, failure_reason: ?string}
 */
/**
 * Resolves the configured browser command: PP_BROWSER_CMD constant first, then the
 * environment variable. Single source of truth shared by capture and doctor so the two
 * cannot drift.
 *
 * @return array{cmd: ?string, source: ?string}  source is 'constant' | 'env' | null.
 */
function pp_screenshot_resolve_browser_cmd(): array {
    if (defined('PP_BROWSER_CMD') && PP_BROWSER_CMD) {
        return ['cmd' => PP_BROWSER_CMD, 'source' => 'constant'];
    }
    $env = getenv('PP_BROWSER_CMD');
    if ($env) {
        return ['cmd' => $env, 'source' => 'env'];
    }
    return ['cmd' => null, 'source' => null];
}

/**
 * Reports screenshot-capture readiness for the CURRENT runtime context (CLI `wp` and
 * web PHP can resolve different env/config, so the reported context matters). Capability
 * detection only by default; pass $probe=true to actually attempt a tiny capture and
 * confirm the adapter launches, writes a file, and exits cleanly.
 *
 * Basis for both `wp pp screenshot doctor` and the non-blocking preflight readiness
 * warning. Never mutates the site and never blocks anything.
 *
 * @param bool $probe  When true, run a real minimal capture against the home URL.
 * @return array{ready: bool, source: ?string, context: string, browser_cmd: ?string,
 *               probe: ?array, message: string}
 */
function pp_screenshot_readiness(bool $probe = false): array {
    $resolved = pp_screenshot_resolve_browser_cmd();
    $context  = (php_sapi_name() === 'cli') ? 'cli' : 'web';

    if ($resolved['cmd'] === null) {
        return [
            'ready'       => false,
            'source'      => null,
            'context'     => $context,
            'browser_cmd' => null,
            'probe'       => null,
            'message'     => 'PP_BROWSER_CMD is not configured for the ' . $context . ' context. '
                . 'Set it as an environment variable visible to this context, or define it in '
                . 'wp-config.php (PHP constant). Required adapter shape: '
                . '<url> --width=<px> --height=<px> --output=<path>. See docs/screenshot-setup.md.',
        ];
    }

    $result = [
        'ready'       => true,
        'source'      => $resolved['source'],
        'context'     => $context,
        'browser_cmd' => $resolved['cmd'],
        'probe'       => null,
        'message'     => 'PP_BROWSER_CMD resolved from ' . $resolved['source'] . ' for the '
            . $context . ' context.',
    ];

    if ($probe) {
        // Unpredictable name so a pre-placed symlink at a guessable path can't redirect
        // the capture write (TOCTOU) in a shared screenshot directory.
        $suffix  = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : uniqid('', true);
        $tmp     = pp_screenshot_dir() . '/.doctor-probe-' . $suffix . '.png';
        $capture = pp_screenshot_capture([
            'url'    => function_exists('home_url') ? home_url('/') : '/',
            'width'  => 320,
            'height' => 240,
            'output' => $tmp,
        ]);
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
        $result['probe'] = [
            'ok'      => $capture['ok'],
            'error'   => $capture['error'] ?? null,
            'message' => $capture['message'] ?? null,
        ];
        $result['ready'] = $capture['ok'];
        if (!$capture['ok']) {
            $result['message'] = 'PP_BROWSER_CMD is set (' . $resolved['source'] . ') but a probe '
                . 'capture in the ' . $context . ' context failed: '
                . ($capture['message'] ?? $capture['error'] ?? 'unknown')
                . '. Fix the adapter before relying on native screenshot evidence.';
        }
    }

    return $result;
}

function pp_screenshot_capture(array $spec): array {
    $resolved    = pp_screenshot_resolve_browser_cmd();
    $browser_cmd = $resolved['cmd'];

    if ($browser_cmd === null) {
        // Per the operating model (ai-instructions/operating-loop.md): a browser that is
        // not configured means capture was never attempted -> NEEDS_VISUAL_VERIFICATION,
        // distinct from SCREENSHOT_FAILED (configured but the capture itself failed).
        return [
            'ok'             => false,
            'path'           => null,
            'error'          => 'no_browser',
            'failure_reason' => null,
            'status'         => 'NEEDS_VISUAL_VERIFICATION',
            'message'        => 'PP_BROWSER_CMD is not configured. Set it as an environment variable '
                . 'or define it in wp-config.php, then run `wp pp screenshot doctor` to verify. '
                . 'Required adapter shape: <url> --width=<px> --height=<px> --output=<path>.',
        ];
    }

    $url    = $spec['url'] ?? '';
    $width  = $spec['width'] ?? 1280;
    $height = $spec['height'] ?? 800;
    $output = $spec['output'] ?? '';

    if (empty($url) || empty($output)) {
        return [
            'ok'             => false,
            'path'           => null,
            'error'          => 'invalid_spec',
            'failure_reason' => 'browser_error',
            'status'         => 'SCREENSHOT_FAILED',
            'message'        => 'Screenshot spec requires url and output fields.',
        ];
    }

    // Ensure output directory exists and is writable
    $output_dir = dirname($output);
    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0755, true);
    }
    if (!is_writable($output_dir)) {
        return [
            'ok'             => false,
            'path'           => null,
            'error'          => 'dir_not_writable',
            'failure_reason' => 'empty_output',
            'status'         => 'SCREENSHOT_FAILED',
            'message'        => 'Screenshot output directory is not writable: ' . $output_dir,
        ];
    }

    // Build command: PP_BROWSER_CMD {url} --width={width} --height={height} --output={output}
    $cmd = sprintf(
        '%s %s --width=%d --height=%d --output=%s 2>&1',
        $browser_cmd,
        escapeshellarg($url),
        $width,
        $height,
        escapeshellarg($output)
    );

    $exec_output = [];
    $exit_code = -1;
    exec($cmd, $exec_output, $exit_code);

    if ($exit_code !== 0) {
        return [
            'ok'             => false,
            'path'           => null,
            'error'          => 'browser_error',
            'failure_reason' => 'browser_error',
            'status'         => 'SCREENSHOT_FAILED',
            'message'        => 'Browser exited with code ' . $exit_code . '. Output: ' . implode("\n", $exec_output),
        ];
    }

    // Validate output file
    if (!file_exists($output)) {
        return [
            'ok'             => false,
            'path'           => null,
            'error'          => 'empty_output',
            'failure_reason' => 'empty_output',
            'status'         => 'SCREENSHOT_FAILED',
            'message'        => 'Browser reported success but output file does not exist: ' . $output,
        ];
    }

    if (filesize($output) === 0) {
        return [
            'ok'             => false,
            'path'           => null,
            'error'          => 'empty_output',
            'failure_reason' => 'empty_output',
            'status'         => 'SCREENSHOT_FAILED',
            'message'        => 'Browser reported success but output file is zero bytes: ' . $output,
        ];
    }

    // Prune old screenshots in the same directory
    pp_screenshot_prune(dirname($output));

    return [
        'ok'             => true,
        'path'           => $output,
        'error'          => null,
        'failure_reason' => null,
        'status'         => 'CAPTURED',
    ];
}

// ── Screenshot Pruning ─────────────────────────────────────────────────────

/**
 * Prunes oldest screenshot files in a directory, keeping the most recent $keep.
 *
 * @param string $dir   Directory to prune.
 * @param int    $keep  Number of files to keep (default 10).
 * @return int  Number of files deleted.
 */
function pp_screenshot_prune(string $dir, int $keep = 10): int {
    if (!is_dir($dir)) {
        return 0;
    }

    $files = glob($dir . '/*.png');
    if ($files === false || count($files) <= $keep) {
        return 0;
    }

    // Sort by modification time, oldest first
    usort($files, fn($a, $b) => filemtime($a) - filemtime($b));

    $to_delete = array_slice($files, 0, count($files) - $keep);
    $deleted = 0;

    foreach ($to_delete as $file) {
        if (@unlink($file)) {
            $deleted++;
        }
    }

    return $deleted;
}
