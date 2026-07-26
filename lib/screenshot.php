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
 * Extracts the executable token from a configured browser command. PP_BROWSER_CMD
 * is a command line (e.g. `node /path/shot.js`, `"/opt/my browser/pp-shot"`), so the
 * binary to check is its first shell token, quote-aware.
 *
 * @param string $cmd  The configured command line.
 * @return string  The first token (the binary), or '' when none.
 */
function pp_screenshot_command_binary(string $cmd): string {
    $cmd = trim($cmd);
    if ($cmd === '') {
        return '';
    }
    // Quote-aware first token: honor a leading '...' or "..." so a path with spaces
    // resolves as one binary rather than being split.
    $q = $cmd[0];
    if ($q === '"' || $q === "'") {
        $end = strpos($cmd, $q, 1);
        return $end !== false ? substr($cmd, 1, $end - 1) : substr($cmd, 1);
    }
    $parts = preg_split('/\s+/', $cmd, 2);
    return $parts[0] ?? '';
}

/**
 * Resolves a bare binary name against $PATH (or checks an explicit path directly).
 * Cheap and side-effect-free: stat-only, no process launch — safe for the read-only
 * preflight surface.
 *
 * @param string $binary  A binary name or path.
 * @return ?string  The resolved executable path, or null when not found/executable.
 */
function pp_screenshot_which(string $binary): ?string {
    if ($binary === '') {
        return null;
    }
    // Explicit path (absolute or relative with a slash): check it directly. Require a
    // regular file, not just any x-bit inode — a directory carries the execute bit but is
    // not an adapter.
    if (strpos($binary, '/') !== false) {
        return (is_file($binary) && is_executable($binary)) ? $binary : null;
    }
    $path = (string) getenv('PATH');
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
        if ($dir === '') {
            continue;
        }
        $candidate = rtrim($dir, '/') . '/' . $binary;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Detects common browser/screenshot binaries on $PATH. These are DISCOVERY HINTS
 * only: PromptingPress blesses no specific tool — each candidate still has to be
 * wrapped to the adapter contract (`<url> --width --height --output`) before it can
 * back PP_BROWSER_CMD. Used by `wp pp screenshot doctor` to help an operator go from
 * unconfigured to configured without leaving the CLI.
 *
 * @return array[]  Each: ['name' => string, 'path' => string]. Empty when none found.
 */
function pp_screenshot_candidate_browsers(): array {
    // Ordered rough-to-precise: purpose-built shot wrappers first, then raw browsers.
    $names = [
        'pp-shot', 'shot-scraper', 'pageres', 'wkhtmltoimage', 'playwright',
        'chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable',
        'chrome', 'chrome-headless-shell', 'firefox',
    ];
    $found = [];
    foreach ($names as $name) {
        $resolved = pp_screenshot_which($name);
        if ($resolved !== null) {
            $found[] = ['name' => $name, 'path' => $resolved];
        }
    }
    return $found;
}

/**
 * Reports screenshot-capture readiness for the CURRENT runtime context (CLI `wp` and
 * web PHP can resolve different env/config, so the reported context matters) as an
 * explicit tri-state (#497):
 *
 *   - `available`   — PP_BROWSER_CMD resolves AND its binary is on $PATH. When $probe
 *                     is set, a real capture also ran and succeeded (definitive).
 *   - `unavailable` — PP_BROWSER_CMD is not configured for this context. Carries the
 *                     one-line setup pointer; this is a capability STATE, not a per-run
 *                     warning.
 *   - `broken`      — PP_BROWSER_CMD is configured but failing: the binary is missing
 *                     from $PATH (cheap, no-exec detection), or (with $probe) the real
 *                     capture failed. Carries the concrete failure.
 *
 * Without $probe this is a cheap, side-effect-free capability check (stat-only) safe for
 * the read-only preflight surface: it can definitively report `unavailable` and a
 * binary-missing `broken`, and reports `available` optimistically (resolves; not yet
 * capture-verified). `wp pp screenshot doctor` passes $probe=true to make `available`
 * vs `broken` definitive by attempting a real capture. Never mutates the site and never
 * blocks anything.
 *
 * `ready` is retained as a convenience alias: `ready === (state === 'available')`.
 *
 * @param bool $probe             When true, run a real minimal capture against the home URL.
 * @param bool $include_candidates When true, attach detected candidate browser binaries
 *                                 (a doctor-facing setup aid; off by default so preflight
 *                                 stays lean).
 * @return array{ready: bool, state: string, source: ?string, context: string,
 *               browser_cmd: ?string, probe: ?array, candidates?: array, message: string}
 */
function pp_screenshot_readiness(bool $probe = false, bool $include_candidates = false): array {
    $resolved = pp_screenshot_resolve_browser_cmd();
    $context  = (php_sapi_name() === 'cli') ? 'cli' : 'web';

    // ── unavailable: nothing configured ─────────────────────────────────────
    if ($resolved['cmd'] === null) {
        $result = [
            'ready'       => false,
            'state'       => 'unavailable',
            'source'      => null,
            'context'     => $context,
            'browser_cmd' => null,
            'probe'       => null,
            'message'     => 'PP_BROWSER_CMD is not configured for the ' . $context . ' context. '
                . 'Set it as an environment variable visible to this context, or define it in '
                . 'wp-config.php (PHP constant). Required adapter shape: '
                . '<url> --width=<px> --height=<px> --output=<path>. See docs/screenshot-setup.md.',
        ];
        if ($include_candidates) {
            $result['candidates'] = pp_screenshot_candidate_browsers();
        }
        return $result;
    }

    // ── configured: decide available vs broken ──────────────────────────────
    // Cheap, stat-only resolution of the command's binary. This is DEFINITIVE only for
    // the no-probe (preflight) surface: a bare token that doesn't resolve on $PATH is a
    // clearly-broken config. But PP_BROWSER_CMD is a shell command line — an env-var
    // prefix (`FOO=1 chromium ...`), `A && B`, a shell builtin, or a bare name present at
    // the adapter's runtime PATH but not this context's PATH are all things the token
    // parser cannot resolve yet still capture fine. So when we PROBE, the real capture is
    // the arbiter: the binary-missing early return only fires without a probe.
    $binary   = pp_screenshot_command_binary($resolved['cmd']);
    $bin_path = pp_screenshot_which($binary);

    if ($bin_path === null && !$probe) {
        $result = [
            'ready'       => false,
            'state'       => 'broken',
            'source'      => $resolved['source'],
            'context'     => $context,
            'browser_cmd' => $resolved['cmd'],
            'probe'       => null,
            'message'     => 'PP_BROWSER_CMD is set (' . $resolved['source'] . ') but its command "'
                . $binary . '" was not found on $PATH (or is not executable) in the ' . $context
                . ' context. Run `wp pp screenshot doctor` to capture-verify (it may still work via a '
                . 'shell form the check cannot resolve), or fix the path. See docs/screenshot-setup.md.',
        ];
        if ($include_candidates) {
            $result['candidates'] = pp_screenshot_candidate_browsers();
        }
        return $result;
    }

    // Optimistic baseline: resolves (or will be probe-arbitrated). The message is only
    // surfaced on the no-probe path (the probe block overwrites it), where $bin_path is
    // non-null, so guard the null case defensively.
    $result = [
        'ready'       => true,
        'state'       => 'available',
        'source'      => $resolved['source'],
        'context'     => $context,
        'browser_cmd' => $resolved['cmd'],
        'probe'       => null,
        'message'     => 'PP_BROWSER_CMD resolved from ' . $resolved['source']
            . ($bin_path !== null ? ' (' . $bin_path . ')' : '')
            . ' for the ' . $context . ' context. Run `wp pp screenshot doctor` to capture-verify.',
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
        $bytes = (file_exists($tmp) && ($capture['ok'] ?? false)) ? filesize($tmp) : 0;
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
        $result['probe'] = [
            'ok'      => $capture['ok'],
            'error'   => $capture['error'] ?? null,
            'message' => $capture['message'] ?? null,
            'bytes'   => $bytes,
        ];
        // A capture that "succeeded" but produced no bytes is not real evidence — treat it
        // as broken so a delete race can never masquerade as `available`.
        if (($capture['ok'] ?? false) && $bytes > 0) {
            $result['message'] = 'PP_BROWSER_CMD (' . $resolved['source'] . ') captured a real probe '
                . 'in the ' . $context . ' context: ' . $bytes . '-byte PNG. Native screenshot '
                . 'evidence is available.';
        } else {
            $result['ready']   = false;
            $result['state']   = 'broken';
            $result['message'] = 'PP_BROWSER_CMD is set (' . $resolved['source'] . ') but a probe '
                . 'capture in the ' . $context . ' context failed: '
                . ($capture['message'] ?? $capture['error'] ?? ($bytes === 0 ? 'empty capture' : 'unknown'))
                . '. Fix the adapter before relying on native screenshot evidence.';
        }
    }

    if ($include_candidates && $result['state'] !== 'available') {
        $result['candidates'] = pp_screenshot_candidate_browsers();
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
