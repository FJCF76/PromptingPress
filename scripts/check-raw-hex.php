<?php
/**
 * scripts/check-raw-hex.php
 *
 * Single source of truth for the "no raw hex colors in components.css" guard.
 * Both the local test (tests/InvariantTest.php) and the CI `ai-ready` workflow
 * call THIS code, so a comment that passes locally cannot fail CI or vice versa
 * (issue #289, acceptance criterion 3).
 *
 * The guard is intentionally lexical, NOT a full CSS parser: it flags `#`
 * followed by 3-6 hex digits (and not another hex digit) anywhere OUTSIDE a
 * `/* ... *\/` comment. Comment context is the only exemption — that is what
 * lets an issue reference like `/* fixes overflow (#226) *\/` pass while a
 * genuine raw hex in a declaration (`color: #226;` or `color: #ff0000;`) still
 * fails. The regex is unchanged from the original guard on purpose; only the
 * comment handling is new.
 *
 * Usage (CLI):  php scripts/check-raw-hex.php [path]   (default: assets/css/components.css)
 *   exit 0 = clean, exit 1 = raw hex found, exit 2 = file missing/unreadable.
 */

declare(strict_types=1);

if (!function_exists('pp_strip_css_comments')) {
    /**
     * Blank out `/* ... *\/` comment spans while preserving newlines AND byte
     * offsets, so line numbers stay accurate and tokens on either side of a
     * comment are never fused. Blanking to newlines ALONE would collapse
     * `#22/* x *\/6` into `#226` and produce a false positive; replacing every
     * non-newline comment char with a space keeps `#22` and `6` apart.
     *
     * An unterminated `/*` (no closing `*\/`) is left untouched by design —
     * that is malformed CSS, and scanning it stays on the strict side.
     */
    function pp_strip_css_comments(string $content): string
    {
        return (string) preg_replace_callback(
            '/\/\*.*?\*\//s',
            static function (array $m): string {
                // Keep newlines (line numbers); blank everything else to a space.
                return (string) preg_replace('/[^\n]/', ' ', $m[0]);
            },
            $content
        );
    }
}

if (!function_exists('pp_find_raw_hex')) {
    /**
     * Find raw hex color literals in $content that are NOT inside a
     * `/* ... *\/` comment. Reports the first match on each offending line
     * (one entry per line, all lines scanned) — enough to fail the guard and
     * point at the line; it is not an exhaustive per-line match list.
     *
     * The comment stripping is intentionally lexical, not CSS-aware: a
     * `/* ... *\/` byte sequence inside a CSS string literal (e.g.
     * `content: "/* ... *\/"`) is also treated as a comment. That is an
     * accepted limitation of the "strip comments, keep the regex simple"
     * approach chosen for issue #289; such a hex is text, not a hardcoded
     * color value, so exempting it does not weaken the guard's real job.
     *
     * @return list<array{line:int,match:string,text:string}>
     */
    function pp_find_raw_hex(string $content): array
    {
        $stripped = pp_strip_css_comments($content);
        $scanLines = explode("\n", $stripped);
        $rawLines  = explode("\n", $content);

        $hits = [];
        foreach ($scanLines as $i => $line) {
            if (preg_match('/#[0-9a-fA-F]{3,6}(?![0-9a-fA-F])/', $line, $m)) {
                $hits[] = [
                    'line'  => $i + 1,
                    'match' => $m[0],
                    // Report the ORIGINAL line (comment intact) for a useful message.
                    'text'  => rtrim($rawLines[$i] ?? $line, "\r"),
                ];
            }
        }

        return $hits;
    }
}

// ── CLI entrypoint ────────────────────────────────────────────────────────
// Guarded so a `require`/`require_once` from PHPUnit (also PHP_SAPI 'cli')
// does NOT run the CLI: under PHPUnit $argv[0] is the phpunit binary, so the
// realpath comparison fails and only the function definitions above load.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $file = $argv[1] ?? 'assets/css/components.css';

    if (!is_file($file) || !is_readable($file)) {
        fwrite(STDERR, "check-raw-hex: cannot read file: {$file}\n");
        exit(2);
    }

    $hits = pp_find_raw_hex((string) file_get_contents($file));
    foreach ($hits as $hit) {
        fwrite(STDERR, sprintf(
            "ERROR: Raw hex color '%s' found in %s on line %d: %s\n",
            $hit['match'],
            $file,
            $hit['line'],
            trim($hit['text'])
        ));
    }

    if ($hits !== []) {
        fwrite(STDERR, "Use CSS variables from base.css instead.\n");
        exit(1);
    }

    echo "OK: no raw hex colors in {$file}\n";
    exit(0);
}
