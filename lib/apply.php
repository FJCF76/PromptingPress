<?php
/**
 * lib/apply.php — PromptingPress Apply Layer
 *
 * Adjacent execution contract for mutations (file-based or option-based).
 * Same architectural DNA as the action model (lib/actions.php).
 *
 * Apply definition contract:
 *   name        => string (unique, snake_case)
 *   domain      => 'design'|'media' (future: other domains)
 *   target      => ['type' => 'file'|'option'|'media', ...type-specific keys]
 *                   file:   ['type' => 'file', 'path' => string]  (relative to theme root)
 *                   option: ['type' => 'option', 'key' => string] (wp_options key)
 *                   media:  ['type' => 'media']                  (new media library attachment)
 *   description => string (one sentence, caller-facing)
 *   params      => [param_name => ['type' => string, 'required' => bool], ...]
 *   validate    => callable(array $params): true|WP_Error
 *   preview     => callable(array $params): array (diff, never writes)
 *   apply       => callable(array $params): array (canonical result shape)
 *
 * Canonical result shape (apply):
 *   ['ok' => bool, 'apply' => string, 'domain' => string,
 *    'target' => array, 'changes' => array, 'error' => string|null]
 *
 * Preview result shape (same + before/after):
 *   ['ok' => true, 'apply' => string, 'domain' => string,
 *    'target' => array, 'before' => array, 'after' => array,
 *    'changes' => array, 'error' => null]
 */

// ── Registry ────────────────────────────────────────────────────────────────

function pp_register_apply(string $name, array $definition): void {
    global $_pp_applies;
    if (!isset($_pp_applies)) {
        $_pp_applies = [];
    }
    $definition['name'] = $name;
    $_pp_applies[$name] = $definition;
}

function pp_get_registered_applies(): array {
    global $_pp_applies;
    return $_pp_applies ?? [];
}

function pp_get_apply(string $name): ?array {
    global $_pp_applies;
    return $_pp_applies[$name] ?? null;
}

// ── Validation ──────────────────────────────────────────────────────────────

/**
 * Validates apply params: structural checks (required, types) then
 * the apply's own semantic validate callable.
 *
 * @return true|WP_Error
 */
function pp_validate_apply(string $name, array $params) {
    $apply = pp_get_apply($name);
    if (!$apply) {
        return new WP_Error('unknown_apply', sprintf('Apply "%s" is not registered.', $name));
    }

    foreach ($apply['params'] as $param_name => $param_def) {
        if (!empty($param_def['required']) && !array_key_exists($param_name, $params)) {
            return new WP_Error(
                'missing_param',
                sprintf('Apply "%s" requires param "%s".', $name, $param_name)
            );
        }
        if (array_key_exists($param_name, $params) && $params[$param_name] !== null) {
            $expected_type = $param_def['type'] ?? 'string';
            $actual_type   = gettype($params[$param_name]);
            $type_map      = [
                'int'    => 'integer',
                'string' => 'string',
                'array'  => 'array',
                'bool'   => 'boolean',
            ];
            $expected_php = $type_map[$expected_type] ?? $expected_type;
            if ($actual_type !== $expected_php) {
                return new WP_Error(
                    'invalid_param_type',
                    sprintf('Param "%s" must be %s, got %s.', $param_name, $expected_type, $actual_type)
                );
            }
        }
    }

    return call_user_func($apply['validate'], $params);
}

/**
 * Previews an apply: validates, computes before/after diff, never writes.
 *
 * @return array|WP_Error
 */
function pp_preview_apply(string $name, array $params) {
    $validation = pp_validate_apply($name, $params);
    if (is_wp_error($validation)) {
        return $validation;
    }

    $apply = pp_get_apply($name);
    return call_user_func($apply['preview'], $params);
}

/**
 * Executes an apply: validates first, then applies.
 * Returns the canonical result shape.
 */
function pp_execute_apply(string $name, array $params): array {
    $validation = pp_validate_apply($name, $params);
    if (is_wp_error($validation)) {
        $apply = pp_get_apply($name);
        return [
            'ok'      => false,
            'apply'   => $name,
            'domain'  => $apply['domain'] ?? 'unknown',
            'target'  => $apply['target'] ?? [],
            'changes' => [],
            'error'   => $validation->get_error_message(),
        ];
    }

    $apply = pp_get_apply($name);
    return call_user_func($apply['apply'], $params);
}

// ── Helper: build result arrays ─────────────────────────────────────────────

function _pp_apply_result(string $name, string $domain, array $target, array $changes): array {
    return [
        'ok'      => true,
        'apply'   => $name,
        'domain'  => $domain,
        'target'  => $target,
        'changes' => $changes,
        'error'   => null,
    ];
}

function _pp_apply_error(string $name, string $domain, array $target, string $error): array {
    return [
        'ok'      => false,
        'apply'   => $name,
        'domain'  => $domain,
        'target'  => $target,
        'changes' => [],
        'error'   => $error,
    ];
}

function _pp_apply_preview(string $name, string $domain, array $target, array $before, array $after, array $changes): array {
    return [
        'ok'      => true,
        'apply'   => $name,
        'domain'  => $domain,
        'target'  => $target,
        'before'  => $before,
        'after'   => $after,
        'changes' => $changes,
        'error'   => null,
    ];
}

// ── Target Discovery ───────────────────────────────────────────────────────

/**
 * Returns the canonical target: site URL, WP root, theme path, environment.
 * Auto-populated from current WordPress state.
 *
 * @return array{site_url: ?string, wp_root: ?string, theme_path: ?string, environment: ?string}
 */
function pp_get_target(): array {
    $site_url = function_exists('get_option') ? get_option('siteurl', null) : null;
    $wp_root = defined('ABSPATH') ? ABSPATH : null;
    $theme_path = function_exists('get_template_directory') ? get_template_directory() : null;

    // Environment label cascade:
    // 1. Explicit WP_ENVIRONMENT_TYPE constant → authoritative (validated by wp_get_environment_type)
    // 2. WP_DEBUG true without explicit env type → 'development' (heuristic)
    // 3. wp_get_environment_type() with no constant → returns 'production' default, accepted
    // 4. None of the above → null
    $environment = null;
    if (defined('WP_ENVIRONMENT_TYPE')) {
        $environment = function_exists('wp_get_environment_type')
            ? wp_get_environment_type()
            : WP_ENVIRONMENT_TYPE;
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        $environment = 'development';
    } elseif (function_exists('wp_get_environment_type')) {
        $environment = wp_get_environment_type();
    }

    return [
        'site_url'    => $site_url ?: null,
        'wp_root'     => $wp_root ?: null,
        'theme_path'  => $theme_path ?: null,
        'environment' => $environment,
    ];
}

// ── Token Validation ────────────────────────────────────────────────────────

/**
 * Parses a single bare design-token reference: var(--token) with NOTHING
 * else inside — no fallback, no nesting, no whitespace, no trailing newline
 * (\z, not $, so "var(--x)\n" does not slip through). This strict shape IS
 * the security boundary for token references (#230): a var(--x, url(evil))
 * fallback-smuggling value cannot match. Shared by the color validator and
 * the reference-cycle walk so the acceptance grammar and the chain-following
 * grammar can never drift apart.
 *
 * @return string|null  The referenced token name (e.g. "--color-accent"), or null.
 */
function _pp_parse_token_reference(string $value): ?string {
    if (preg_match('/^var\((--[a-z0-9-]+)\)\z/', $value, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Validates a CSS color value.
 * Accepts: 3/4/6/8-digit hex, rgb(), rgba(), hsl(), hsla(), the CSS color
 * keywords `transparent` and `currentColor` (case-insensitive), and a single
 * bare design-token reference `var(--token)` whose token is registered in
 * pp_design_tokens() AND is itself color-typed (#230).
 * Rejects: named colors, any var() carrying more than the bare reference
 * (fallback, nesting, url() — see _pp_parse_token_reference()), references
 * to unregistered tokens (no dangling references), and references to
 * non-color tokens — a color slot resolving to "0.25rem" is guaranteed-
 * invalid CSS the browser silently drops, the same class #129 rejects for
 * lengths.
 */
function _pp_validate_color(string $value): bool {
    // Hex: #fff, #ffff, #ffffff, #ffffffff
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
        return true;
    }
    // rgb()/rgba(): rgb(255, 0, 0) or rgba(0, 0, 0, 0.55)
    if (preg_match('/^rgba?\(\s*[\d.]+(%?\s*,\s*[\d.]+%?){2,3}\s*\)$/', $value)) {
        return true;
    }
    // hsl()/hsla(): hsl(120, 50%, 50%) or hsla(120, 50%, 50%, 0.5)
    if (preg_match('/^hsla?\(\s*[\d.]+\s*,\s*[\d.]+%\s*,\s*[\d.]+%\s*(,\s*[\d.]+)?\s*\)$/', $value)) {
        return true;
    }
    // CSS color keywords: injection-free, and commonly needed
    // (transparent backgrounds, borders that follow the text color) (#230).
    if (in_array(strtolower($value), ['transparent', 'currentcolor'], true)) {
        return true;
    }
    // Single bare design-token reference. The referenced token must exist
    // in the design-token registry (never dangles) and be color-typed
    // (never resolves to a length/font/duration). Lets a slot/token follow
    // another token ("this follows the brand accent") instead of
    // duplicating literal hex everywhere (#230).
    $ref = _pp_parse_token_reference($value);
    if ($ref !== null) {
        $registry = pp_design_tokens();
        return isset($registry[$ref]) && ($registry[$ref]['type'] ?? null) === 'color';
    }
    return false;
}

/**
 * Rejects a design-token write whose var() reference chain leads back to
 * the token being written (#230). A cycle — direct var(--itself), or
 * indirect via defaults/overrides that are themselves var() references —
 * is guaranteed-invalid CSS: the browser resolves every token in the cycle
 * to invalid at computed-value time. Same "don't persist broken CSS"
 * discipline as the bare-unit rejection in _pp_validate_length() (#129).
 *
 * Walks EFFECTIVE values (pp_design_tokens() merges DB overrides, so the
 * walk sees real stored state), bounded by registry size. If the walk
 * exhausts the registry without terminating at a concrete value, the
 * stored state already contains a foreign cycle (reachable via a scoped
 * revert or a hand-edited option row) — pointing another token into it
 * would resolve invalid too, so that fails closed as well.
 *
 * @param string $token  The token being written (post-write owner of $value).
 * @param string $value  The value about to be written.
 * @return true|WP_Error
 */
function _pp_check_token_reference_cycle(string $token, string $value) {
    $next = _pp_parse_token_reference($value);
    if ($next === null) {
        return true; // not a reference — nothing to walk
    }
    $tokens = pp_design_tokens();
    $steps  = count($tokens);
    while ($steps-- > 0) {
        if ($next === $token) {
            return new WP_Error('token_reference_cycle', sprintf(
                'Value creates a design-token reference cycle back to "%s". Reference a token that does not resolve through "%s".',
                $token, $token
            ));
        }
        if (!isset($tokens[$next])) {
            return true; // dangling — the type validator already rejects this
        }
        $follow = _pp_parse_token_reference(trim($tokens[$next]['value']));
        if ($follow === null) {
            return true; // chain terminates at a concrete value
        }
        $next = $follow;
    }
    return new WP_Error('token_reference_cycle', sprintf(
        'Value resolves through an existing design-token reference cycle. Repair the cycle before referencing "%s".',
        _pp_parse_token_reference($value)
    ));
}

/**
 * Validates a CSS length value.
 * Accepts: numeric value with unit (rem, px, em, %, vw, vh) including a single
 * leading minus for negative lengths (letter-spacing/margins/text-indent go
 * negative), unitless 0, clamp() expressions, and calc() expressions.
 *
 * The simple-length number body is a single well-formed number: at least one
 * digit, at most one dot, optional leading minus (#467), and the unit directly
 * attached (no whitespace). This rejects `.em` (no digit), `1.2.3rem` (multiple
 * dots), and `1.2 rem` (space before the unit) — malformed shapes the older
 * loose body accepted and persisted as broken CSS (#151).
 *
 * clamp/calc use positive-pattern matching: only numeric literals, units,
 * percentage, comma, parentheses, and arithmetic operators are allowed.
 * var() references inside clamp/calc are rejected to prevent injection
 * bypass — an alphabetic word that isn't an allowed unit (rem/px/em/vw/vh)
 * is rejected outright. Separately, every unit word that IS allowed must be
 * directly attached to a real numeric operand (immediately preceded by a
 * digit or decimal point) — this is a correctness check, not a security
 * boundary: it rejects structurally nonsensical values like calc(px) or
 * calc((rem) + 1px) that would otherwise validate and persist as broken CSS
 * the browser silently drops (#129). Three more cheap correctness checks
 * (#151, Option C + Option 4): the extraction allows legal newline whitespace
 * inside the body (`calc(\n1rem + 2rem)`); an unbalanced-paren body (`calc(1))`)
 * is rejected; and a top-level comma inside calc() (`calc(1,2,3)`) is rejected
 * while clamp()'s legitimate 3-argument comma form is left intact.
 *
 * Explicitly accepted as residual (documented, won't-fix — each only degrades to
 * a browser-dropped declaration, never injection, thanks to the {};<> guard plus
 * the char-class whitelist): bare unitless operands (`calc(1)`, `clamp(1,2,3)`),
 * doubled operators (`calc(1++2rem)`), and two-operand-no-operator (`calc(1 1)`).
 * A full CSS calc()/clamp() grammar parser is deliberately not built (#151).
 */
function _pp_validate_length(string $value): bool {
    // Unitless zero.
    if ($value === '0') {
        return true;
    }
    // Simple length: optional leading minus, a single well-formed number, then a
    // unit directly attached. Negative lengths are valid CSS <length> (letter-spacing,
    // margins, text-indent go negative), so the grammar accepts a single leading '-'
    // (#467); the injection guards and the calc/clamp positive-pattern below are
    // unchanged. Semantically-inert cases (negative radius, padding) simply drop per
    // CSS — this is a grammar guard, not a per-property check.
    //
    // The number body is `\d+(?:\.\d*)?|\.\d+`: at least one digit, at most one dot,
    // and a leading-dot form (`.5rem`, `-.5rem`). This rejects malformed shapes the
    // old loose `[\d.]+\s*` body accepted and persisted as broken CSS the browser
    // drops (#151): a unit with no digit (`.em`), multiple dots (`1.2.3rem`), and
    // whitespace between the number and the unit (`1.2 rem` — CSS forbids it).
    // Unitless zero is handled above; `0` with a unit still validates via the number
    // body. The second digit run sits behind a mandatory `.` (`(?:\.\d*)?`, never an
    // adjacent `\d+\.?\d*`) so an all-digit input with a bad unit backtracks linearly,
    // not quadratically — no catastrophic-backtracking surface on an uncapped value.
    if (preg_match('/^-?(\d+(?:\.\d*)?|\.\d+)(rem|px|em|%|vw|vh)$/', $value)) {
        return true;
    }
    // clamp() or calc(): positive-pattern — only allow safe characters inside.
    // Safe: digits, dots, units (a-z), %, comma, spaces, parentheses, +, -, *, /
    // Reject anything else (including var, url, env, or any function calls).
    if (preg_match('/^(clamp|calc)\(/', $value)) {
        // Extract contents between outer parens. The /s (dotall) modifier lets
        // legal newline whitespace inside the expression (`calc(\n1rem + 2rem)`)
        // extract instead of failing the greedy `.+` outright (#151, Option 4).
        if (!preg_match('/^(clamp|calc)\((.+)\)$/s', $value, $m)) {
            return false;
        }
        $fn       = strtolower($m[1]);
        $contents = $m[2];
        // Paren nesting: verify the body's parens are PROPERLY nested — never a
        // closing paren before its matching opener, and balanced at the end. The
        // greedy outer extraction checks neither, so `calc(1))` leaves a stray ')'
        // and `calc()1,2()` leaves an improperly-nested `)1,2(` (count-balanced,
        // but a ')' precedes its '('). A count-only check (substr_count) passes the
        // latter and would let the top-level-comma guard below be bypassed, since a
        // leading ')' drives the split walker to negative depth and masks the comma.
        // A single left-to-right depth walk rejects both — every non-nested body is
        // structurally broken CSS the browser drops (#151, Option C).
        $depth = 0;
        $len   = strlen($contents);
        for ($i = 0; $i < $len; $i++) {
            if ($contents[$i] === '(') {
                $depth++;
            } elseif ($contents[$i] === ')' && --$depth < 0) {
                return false;
            }
        }
        if ($depth !== 0) {
            return false;
        }
        // calc() takes a single arithmetic expression, never comma-separated
        // arguments, so any top-level comma inside calc(...) is malformed
        // (`calc(1,2,3)`) — reject it (#151, Option C). clamp()'s legitimate
        // 3-argument comma form is left intact; its arity is not further checked
        // (bare-number args like `clamp(1,2,3)` remain an accepted residual, per
        // the recorded decision — they only degrade to a dropped declaration).
        if ($fn === 'calc' && count(_pp_split_top_level_commas($contents)) > 1) {
            return false;
        }
        // Positive pattern: only numeric, dot, units, %, comma, whitespace, parens, arithmetic.
        // Every alphabetic sequence must be an allowed unit word exactly
        // (rem, px, em, vw, vh) — this blocks var, env, url, and any other
        // function/keyword, not a length-based rule.
        $alpha_sequences = [];
        preg_match_all('/[a-zA-Z]+/', $contents, $alpha_sequences, PREG_OFFSET_CAPTURE);
        $allowed_units = ['rem', 'px', 'em', 'vw', 'vh'];
        foreach ($alpha_sequences[0] as [$word, $offset]) {
            // Reject if any alpha sequence is NOT a known unit. This is the
            // key security boundary: var(--anything) contains "var" which
            // is not a unit.
            if (!in_array(strtolower($word), $allowed_units, true)) {
                return false;
            }
            // A real unit is always directly adjacent to the number it
            // qualifies — CSS doesn't allow a space between "1" and "rem",
            // and a unit can't stand alone. Reject a "bare" unit word not
            // immediately preceded by a digit or a decimal point, e.g.
            // calc(px), calc((rem) + 1px), calc(-rem + 1px), or
            // clamp((rem), 1px, 2px) — every one of these has an allowed
            // unit word but no real numeric operand behind it, and would
            // otherwise validate and persist as broken CSS the browser
            // silently drops.
            $prev_char = $offset > 0 ? $contents[$offset - 1] : '';
            if ($prev_char === '' || !preg_match('/[\d.]/', $prev_char)) {
                return false;
            }
        }
        // Must only contain: digits, dots, units(a-z), %, comma, whitespace, parens, +, -, *, /
        if (!preg_match('/^[\d\s.,+\-*\/()%a-zA-Z]+$/', $contents)) {
            return false;
        }
        return true;
    }
    return false;
}

/**
 * Validates a CSS font-family value.
 * Accepts: comma-separated font names.
 */
function _pp_validate_font_family(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    // Must contain at least one non-whitespace font name
    $fonts = array_filter(array_map('trim', explode(',', $value)));
    return count($fonts) > 0;
}

/**
 * Best-effort extraction of the CSS font-family name from a Google/Bunny
 * Fonts stylesheet URL's `family` query parameter (issue 135), e.g.
 * `family=Roboto:wght@400;700` -> "Roboto", `family=Open+Sans` -> "Open Sans".
 * Only the first family is used when the URL requests several. Returns ''
 * when there is no `family` param or it doesn't parse as one.
 *
 * @param  string $url  Font stylesheet URL.
 * @return string       Derived family name, or ''.
 */
function _pp_derive_font_family_from_url(string $url): string {
    $query = parse_url($url, PHP_URL_QUERY);
    if (!$query) {
        return '';
    }
    parse_str($query, $query_params);
    $family = $query_params['family'] ?? '';
    if ($family === '') {
        return '';
    }
    // Google's legacy API allows multiple families separated by '|'; the
    // CSS2 API allows a weight/style axis suffix after ':'. Only the first
    // family name (before either separator) is meaningful here.
    $family = explode('|', $family)[0];
    $family = explode(':', $family)[0];
    $family = str_replace('+', ' ', $family);
    return trim($family);
}

/**
 * Maps an enqueue_font `apply_to` value to the design token(s) it targets
 * (issue 135). `--font-heading`/`--font-body` are the theme's real token
 * names (assets/css/base.css) — not the `--font-family-*` naming an AI
 * might guess.
 *
 * @param  string $apply_to  'heading' | 'body' | 'both'.
 * @return string[]          Token names, or [] for an unrecognized value.
 */
function _pp_font_apply_to_tokens(string $apply_to): array {
    switch ($apply_to) {
        case 'heading':
            return ['--font-heading'];
        case 'body':
            return ['--font-body'];
        case 'both':
            return ['--font-heading', '--font-body'];
        default:
            return [];
    }
}

/**
 * Validates a CSS duration value.
 * Accepts: numeric value with time unit (ms, s).
 */
function _pp_validate_duration(string $value): bool {
    return (bool) preg_match('/^[\d.]+\s*(ms|s)$/', $value);
}

/**
 * Validates a unitless CSS number value.
 * Accepts: positive integers or decimals (e.g. 650, 1.6, 0.85).
 * Used for font-weight, line-height, and other unitless numeric tokens.
 */
function _pp_validate_number(string $value): bool {
    return (bool) preg_match('/^\d+(\.\d+)?$/', $value);
}

/**
 * Validates a CSS box-shadow value for the bounded `shadow` slot type.
 *
 * Accepts ONE of:
 *  - A preset reference from the exact allowlist: var(--shadow-none|sm|md|lg),
 *    or the bare keyword `none`.
 *  - A single-layer box-shadow: 2-4 length values (offset-x offset-y [blur]
 *    [spread]) followed by a color. Offsets may be negative; blur and spread
 *    must be non-negative. Lengths are unitless 0 or px/rem. The color must
 *    match hex / rgb(a) / hsl(a) form (the anchored regex below) AND pass
 *    _pp_validate_color() — the keywords/var() forms #230 added to the color
 *    validator never reach here because the regex pre-filters them out.
 *
 * Rejects: `inset`, multi-layer shadows (comma-separated layers), url(), and any
 * var() outside the preset allowlist. The {};<> injection guard runs upstream in
 * _pp_validate_token_value().
 */
function _pp_validate_shadow(string $value): bool {
    $value = trim($value);

    // Preset allowlist + the `none` keyword.
    $presets = ['none', 'var(--shadow-none)', 'var(--shadow-sm)', 'var(--shadow-md)', 'var(--shadow-lg)'];
    if (in_array($value, $presets, true)) {
        return true;
    }

    // No inset, no url(), and no var() other than the presets handled above.
    if (preg_match('/\binset\b/i', $value) || stripos($value, 'url(') !== false || stripos($value, 'var(') !== false) {
        return false;
    }

    // Single layer only: <lengths> <color>, color anchored at the end.
    if (!preg_match('/^(.+?)\s+(#[0-9a-fA-F]{3,8}|rgba?\([^()]*\)|hsla?\([^()]*\))$/', $value, $m)) {
        return false;
    }
    if (!_pp_validate_color($m[2])) {
        return false;
    }

    $lengths = preg_split('/\s+/', trim($m[1]));
    $count   = count($lengths);
    if ($count < 2 || $count > 4) {
        return false;
    }
    foreach ($lengths as $i => $len) {
        // Offsets (positions 0,1) may be negative; blur/spread (2,3) must not.
        $pattern = $i < 2 ? '/^-?(0|[\d.]+(px|rem))$/' : '/^(0|[\d.]+(px|rem))$/';
        if (!preg_match($pattern, $len)) {
            return false;
        }
    }
    return true;
}

/**
 * Splits a string on commas that are NOT nested inside parentheses.
 * Used to separate gradient arguments/stops without breaking apart commas
 * that belong to a nested color function, e.g. "rgba(0, 0, 0, 0.5) 40%".
 *
 * @return array<string>  Trimmed segments, in order.
 */
function _pp_split_top_level_commas(string $value): array {
    $parts = [];
    $depth = 0;
    $current = '';
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $char = $value[$i];
        if ($char === '(') {
            $depth++;
        } elseif ($char === ')') {
            $depth--;
        }
        if ($char === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';
        } else {
            $current .= $char;
        }
    }
    $parts[] = trim($current);
    return $parts;
}

/**
 * Validates a single gradient color-stop: a color per _pp_validate_color()
 * (which since #230 covers the `transparent`/`currentColor` keywords, so
 * the former transparent special case here is gone) with an optional single
 * length/percentage stop-position. var() never reaches this check — the
 * whole gradient value rejects any var() upstream in _pp_validate_gradient().
 * Two-position "hard stop" pairs are not supported; one position per stop
 * keeps the grammar simple and covers the common case.
 */
function _pp_validate_gradient_color_stop(string $stop): bool {
    $stop = trim($stop);
    if ($stop === '') {
        return false;
    }

    if (strpos($stop, '(') !== false && preg_match('/^(.*?\))\s*(.*)$/s', $stop, $m)) {
        // Function-form color (rgb/rgba/hsl/hsla) — split at its closing paren.
        $color    = $m[1];
        $position = trim($m[2]);
    } else {
        // Keyword (transparent/currentColor) or hex color — split at the first space.
        $parts    = preg_split('/\s+/', $stop, 2);
        $color    = $parts[0];
        $position = isset($parts[1]) ? trim($parts[1]) : '';
    }

    if (!_pp_validate_color($color)) {
        return false;
    }

    if ($position === '') {
        return true;
    }

    // Stop position: a single non-negative percentage or length.
    return (bool) preg_match('/^(0|\d+(\.\d+)?%|\d+(\.\d+)?(px|rem|em|vw|vh))$/', $position);
}

/**
 * Validates a bounded CSS gradient value for the `gradient` slot type
 * (itself a color-OR-gradient union — see the 'gradient' case in
 * _pp_validate_token_value()).
 *
 * Accepts ONLY:
 *   linear-gradient([<angle>|to <side-or-corner>,]? <stop>, <stop>, ...)
 *   radial-gradient([<shape-position>,]? <stop>, <stop>, ...)
 *
 * The leading direction/shape-position argument is OPTIONAL on both
 * functions, matching real CSS (`linear-gradient(red, blue)` is valid and
 * common) — disambiguated from the first color-stop by strict grammar: a
 * direction/shape argument never looks like a color (it's a bare
 * angle+unit, a "to ..." phrase, or a radial shape/`at <position>` clause
 * built from keyword/percentage tokens), so if the first
 * top-level-comma-separated segment doesn't match
 * one of those forms exactly, it's treated as the first color-stop instead
 * — never ambiguous (cross-model review: an earlier draft required the
 * direction argument specifically to avoid this, which turned out to be
 * unnecessary once the disambiguation grammar is this strict).
 *
 * Rejected outright, anywhere in the value: conic-gradient and
 * repeating-{linear,radial}-gradient (a narrower bounded grammar than full
 * CSS, not requested by the issue this shipped for — #99); var()/url()/env()
 * (this validates a value an operator/AI SUBMITS through the safe apply
 * surface, not a CSS file's own hardcoded fallback value — allowing an
 * arbitrary var() reference here would let one override value pull in
 * another token's value indirectly, the same injection-shaped bypass
 * _pp_validate_length() already rejects for calc()/clamp()).
 *
 * Bounded for defense-in-depth: full-string anchoring, a max value length,
 * and a max stop count, all via positive-pattern matching with no nested
 * quantifiers (no catastrophic-backtracking surface).
 */
function _pp_validate_gradient(string $value): bool {
    $value = trim($value);

    if ($value === '' || strlen($value) > 500) {
        return false;
    }

    // Reject excluded gradient functions and any injection-shaped reference
    // before doing any real parsing.
    if (preg_match('/\b(conic-gradient|repeating-linear-gradient|repeating-radial-gradient|var|url|env)\s*\(/i', $value)) {
        return false;
    }

    if (preg_match('/^linear-gradient\((.*)\)$/is', $value, $m)) {
        $kind = 'linear';
    } elseif (preg_match('/^radial-gradient\((.*)\)$/is', $value, $m)) {
        $kind = 'radial';
    } else {
        return false;
    }

    $args = _pp_split_top_level_commas($m[1]);
    if (count($args) < 2) {
        return false;
    }

    $first = $args[0];
    if ($kind === 'linear') {
        $is_direction = (bool) preg_match(
            '/^(\d+(\.\d+)?(deg|grad|rad|turn)|to\s+(top|bottom|left|right)(\s+(top|bottom|left|right))?)$/i',
            $first
        );
    } else {
        // Radial shape-position: an optional shape keyword (circle|ellipse)
        // and/or an optional `at <position>` clause, at least one present.
        // <position> is 1-2 tokens, each a placement keyword or a
        // non-negative percentage (#301). Lengths (`at 10px`), radial size
        // keywords (`closest-side`), and any function/var()/injection token
        // are deliberately excluded — narrower than full CSS, matching the
        // bounded-grammar posture of the rest of this validator. Anchored,
        // no nested quantifiers (no catastrophic-backtracking surface).
        $pos = '(?:center|top|bottom|left|right|\d+(?:\.\d+)?%)';
        $is_direction = (bool) preg_match(
            '/^(?:(?:circle|ellipse)(?:\s+at\s+' . $pos . '(?:\s+' . $pos . ')?)?|at\s+' . $pos . '(?:\s+' . $pos . ')?)$/i',
            $first
        );
    }

    $stops = $is_direction ? array_slice($args, 1) : $args;

    if (count($stops) < 2 || count($stops) > 20) {
        return false;
    }

    foreach ($stops as $stop) {
        if (!_pp_validate_gradient_color_stop($stop)) {
            return false;
        }
    }

    return true;
}

/**
 * Validates a CSS background-position/object-position value for the bounded
 * `position` slot type (#108 — image focal point).
 *
 * Accepts 1-2 whitespace-separated tokens, each either a known keyword
 * (center, top, bottom, left, right) or a length/percentage (0, 20%, 10px,
 * -5rem). Positive-pattern matching, same discipline as _pp_validate_length():
 * no functions, no var(), nothing but keyword/number+unit tokens allowed.
 */
function _pp_validate_position(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $tokens = preg_split('/\s+/', $value);
    if (count($tokens) < 1 || count($tokens) > 2) {
        return false;
    }
    $keywords = ['center', 'top', 'bottom', 'left', 'right'];
    foreach ($tokens as $token) {
        if (in_array(strtolower($token), $keywords, true)) {
            continue;
        }
        if ($token === '0' || preg_match('/^-?[\d.]+(%|px|rem|em)$/', $token)) {
            continue;
        }
        return false;
    }
    return true;
}

/**
 * Validates a CSS aspect-ratio value for the bounded `ratio` slot type
 * (#108 — image aspect ratio).
 *
 * Accepts the `auto` keyword (the slot's own default — preserves the
 * image's natural proportions, same "own preset is explicitly settable"
 * pattern as _pp_validate_shadow()'s `none`), a single positive number
 * ("1", "1.6"), or two positive numbers separated by a slash ("16/9",
 * "4 / 3"). Zero or negative values are rejected (a zero denominator
 * produces an invalid/inert aspect-ratio the browser silently drops,
 * mirroring the non-negative discipline in _pp_validate_shadow() for
 * blur/spread).
 */
function _pp_validate_ratio(string $value): bool {
    $value = trim($value);
    if (strtolower($value) === 'auto') {
        return true;
    }
    if (preg_match('/^(\d+(?:\.\d+)?)$/', $value, $m)) {
        return (float) $m[1] > 0;
    }
    if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $value, $m)) {
        return (float) $m[1] > 0 && (float) $m[2] > 0;
    }
    return false;
}

/**
 * Validates a CSS text-align value for the bounded `align` slot type
 * (#357 — authorable content alignment on grid cards and any future
 * component that opts in).
 *
 * Accepts exactly the closed set of `text-align` placement keywords
 * {left, right, center, start, end, justify}, matched case-insensitively
 * (mirroring _pp_validate_position(), which lowercases before comparing).
 * Everything else is rejected — including the `position` keywords `top`/
 * `bottom`, lengths/percentages, and the CSS-wide `unset`/`initial`/`inherit`
 * keywords — so the type stays a tight closed vocabulary rather than a raw
 * keyword passthrough. This is the shared engine's ONLY alignment validator;
 * grid does not add a second one (repo invariant: validation lives in the
 * shared engines). A typed `align` slot is honored at the #330 render
 * boundary for free, because pp_render_style_value_allowed() delegates to
 * _pp_validate_token_value().
 */
function _pp_validate_align(string $value): bool {
    $keywords = ['left', 'right', 'center', 'start', 'end', 'justify'];
    return in_array(strtolower(trim($value)), $keywords, true);
}

/**
 * Validates a CSS text-transform value for the bounded `text-transform`
 * slot type (#370 — authorable letter-casing on the eyebrow/kicker pill and
 * any future component that opts in).
 *
 * Accepts exactly the closed set of general-purpose `text-transform` keywords
 * {none, uppercase, lowercase, capitalize}, matched case-insensitively
 * (mirroring _pp_validate_align()). Everything else is rejected — including
 * the CJK-typography values `full-width`/`full-size-kana` (form conversion,
 * not case control, so out of scope for a case slot the same way `align`
 * omits `match-parent`/`justify-all`), `math-auto`, and the CSS-wide
 * `unset`/`initial`/`inherit`/`revert` keywords — so the type stays a tight
 * closed vocabulary rather than a raw keyword passthrough. This is the shared
 * engine's ONLY text-transform validator; components do not add a second one
 * (repo invariant: validation lives in the shared engines). A typed
 * `text-transform` slot is honored at the #330 render boundary for free,
 * because pp_render_style_value_allowed() delegates to
 * _pp_validate_token_value().
 */
function _pp_validate_text_transform(string $value): bool {
    $keywords = ['none', 'uppercase', 'lowercase', 'capitalize'];
    return in_array(strtolower(trim($value)), $keywords, true);
}

/**
 * Validates a token value based on its type.
 *
 * @return true|WP_Error
 */
function _pp_validate_token_value(string $value, ?string $type) {
    // Injection check: reject { } ; < > (prevents CSS injection and style-tag breakout)
    if (preg_match('/[{};<>]/', $value)) {
        return new WP_Error('injection', 'Value must not contain {, }, ;, <, or > characters.');
    }

    if ($value === '') {
        return new WP_Error('empty_value', 'Value must not be empty.');
    }

    if ($type === null) {
        return true; // No type metadata, generic validation only
    }

    switch ($type) {
        case 'color':
            if (!_pp_validate_color($value)) {
                return new WP_Error('invalid_color', 'Value must be a valid CSS color (hex, rgb(), rgba(), hsl(), hsla()), the keyword "transparent" or "currentColor", or a single reference to a registered color token, e.g. var(--color-accent) — no fallback or nesting. Named colors are not accepted.');
            }
            break;
        case 'length':
            if (!_pp_validate_length($value)) {
                return new WP_Error('invalid_length', 'Value must be a number with a CSS unit (rem, px, em, %, vw, vh), unitless 0, or a clamp()/calc() expression.');
            }
            break;
        case 'font-family':
            if (!_pp_validate_font_family($value)) {
                return new WP_Error('invalid_font_family', 'Value must be a comma-separated list of font names.');
            }
            break;
        case 'duration':
            if (!_pp_validate_duration($value)) {
                return new WP_Error('invalid_duration', 'Value must be a number with a time unit (ms, s).');
            }
            break;
        case 'number':
            if (!_pp_validate_number($value)) {
                return new WP_Error('invalid_number', 'Value must be a unitless number (e.g. 650, 1.6).');
            }
            break;
        case 'shadow':
            if (!_pp_validate_shadow($value)) {
                return new WP_Error('invalid_shadow', 'Value must be a shadow preset (var(--shadow-none|sm|md|lg) or none) or a single-layer box-shadow: 2-4 lengths (px/rem, blur/spread non-negative) followed by a color. No inset, multi-layer, or url().');
            }
            break;
        case 'gradient':
            if (!_pp_validate_color($value) && !_pp_validate_gradient($value)) {
                return new WP_Error('invalid_gradient', 'Value must be a valid CSS color (hex, rgb(), rgba(), hsl(), hsla(), "transparent"/"currentColor", or a single var(--token) reference to a registered color token) or a bounded linear-gradient()/radial-gradient() with 2+ color stops (var()/url()/env() inside a gradient and conic/repeating gradients are not accepted).');
            }
            break;
        case 'position':
            if (!_pp_validate_position($value)) {
                return new WP_Error('invalid_position', 'Value must be 1-2 tokens: keywords (center, top, bottom, left, right) or lengths (0, 20%, 10px, -5rem). No functions or var().');
            }
            break;
        case 'ratio':
            if (!_pp_validate_ratio($value)) {
                return new WP_Error('invalid_ratio', 'Value must be "auto", a positive number (e.g. 1, 1.6), or two positive numbers separated by a slash (e.g. 16/9).');
            }
            break;
        case 'align':
            if (!_pp_validate_align($value)) {
                return new WP_Error('invalid_align', 'Value must be a text-align keyword: left, right, center, start, end, or justify.');
            }
            break;
        case 'text-transform':
            if (!_pp_validate_text_transform($value)) {
                return new WP_Error('invalid_text_transform', 'Value must be a text-transform keyword: none, uppercase, lowercase, or capitalize.');
            }
            break;
        case 'raw':
            break; // Injection check only, already done above
    }

    return true;
}

// ── File Operations ─────────────────────────────────────────────────────────

/**
 * Reads tokens directly from a CSS file, bypassing pp_design_tokens() cache.
 * Used for post-write verification.
 *
 * @return array  Token map: ['--name' => 'value', ...]
 */
function _pp_read_tokens_from_file(string $file_path): array {
    if (!file_exists($file_path)) {
        return [];
    }

    $css = file_get_contents($file_path);
    $tokens = [];

    if (preg_match('/:root\s*\{([^}]+)\}/s', $css, $root_match)) {
        preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $root_match[1], $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $tokens[trim($m[1])] = trim($m[2]);
        }
    }

    return $tokens;
}

// ── Apply: update_design_token ──────────────────────────────────────────────
// Domain: design | Target: wp_options pp_token_overrides

pp_register_apply('update_design_token', [
    'domain'      => 'design',
    'target'      => ['type' => 'option', 'key' => 'pp_token_overrides'],
    'description' => 'Updates a single CSS design token override in the database.',
    'params'      => [
        'token' => ['type' => 'string', 'required' => true],
        'value' => ['type' => 'string', 'required' => true],
    ],

    'validate' => function (array $params) {
        $token = $params['token'];
        $value = $params['value'];

        // Token must exist in the current token set
        $tokens = pp_design_tokens();
        if (!array_key_exists($token, $tokens)) {
            $available = implode(', ', array_keys($tokens));
            return new WP_Error('unknown_token', sprintf('Token "%s" is not a registered design token. Available: %s', $token, $available));
        }

        // Type-specific validation
        $type = $tokens[$token]['type'];
        $valid = _pp_validate_token_value($value, $type);
        if ($valid !== true) {
            return $valid;
        }

        // A color value may reference another token (#230) — reject cycles.
        return _pp_check_token_reference_cycle($token, $value);
    },

    'preview' => function (array $params) {
        $token = $params['token'];
        $value = $params['value'];
        $tokens = pp_design_tokens();

        $before_values = [];
        $after_values = [];
        foreach ($tokens as $name => $info) {
            $before_values[$name] = $info['value'];
            $after_values[$name]  = ($name === $token) ? $value : $info['value'];
        }

        return _pp_apply_preview(
            'update_design_token',
            'design',
            ['type' => 'option', 'key' => 'pp_token_overrides'],
            $before_values,
            $after_values,
            [['token' => $token, 'from' => $tokens[$token]['value'], 'to' => $value]]
        );
    },

    'apply' => function (array $params) {
        $token = $params['token'];
        $value = $params['value'];
        $target = ['type' => 'option', 'key' => 'pp_token_overrides'];

        $tokens = pp_design_tokens();
        $old_value = $tokens[$token]['value'];

        // No-op: postcondition already satisfied
        if ($old_value === $value) {
            return _pp_apply_result('update_design_token', 'design', $target, []);
        }

        // Write override to database (pp_set_token_override handles cache invalidation)
        $result = pp_set_token_override($token, $value);
        if (!$result) {
            return _pp_apply_error('update_design_token', 'design', $target,
                sprintf('Failed to write token override "%s" to database.', $token));
        }

        // Verify: read back from database
        $overrides = pp_get_token_overrides();
        if (!isset($overrides[$token]) || $overrides[$token] !== $value) {
            return _pp_apply_error('update_design_token', 'design', $target,
                sprintf('Verification failed: token "%s" not set to expected value after write.', $token));
        }

        $changes = [['token' => $token, 'from' => $old_value, 'to' => $value]];

        // Fallback-only derivation: auto-derive family tokens only when they
        // have NO existing override in the DB.  Tokens with explicit overrides
        // are respected (the AI or user chose them intentionally).
        $existing_overrides = pp_get_token_overrides();
        $derived = pp_derive_family_tokens($token, $value);
        foreach ($derived as $derived_token => $derived_value) {
            if (isset($existing_overrides[$derived_token])) {
                continue; // respect existing override
            }
            pp_set_token_override($derived_token, $derived_value);
            $changes[] = ['token' => $derived_token, 'from' => null, 'to' => $derived_value];
        }

        // Warn about existing derived overrides that were preserved above but
        // DIVERGE from the new base — they keep winning in the rendered CSS, so
        // this ok:true change may not be visible where they apply (#386). Reuses
        // the same shared engine the INSPECT smell uses. Non-destructive: no token
        // value is changed here, the warning is advisory.
        $stale_warnings = pp_masked_derived_overrides($token, $value);

        $result = _pp_apply_result(
            'update_design_token',
            'design',
            $target,
            $changes
        );
        if (!empty($stale_warnings)) {
            $result['stale_warnings'] = $stale_warnings;
        }
        return $result;
    },
]);

// ── Apply: reset_design_token ──────────────────────────────────────────────
// Domain: design | Clears a single token override, reverting to product default

pp_register_apply('reset_design_token', [
    'domain'      => 'design',
    'target'      => ['type' => 'option', 'key' => 'pp_token_overrides'],
    'description' => 'Clears a single design token override, reverting it to the product default.',
    'params'      => [
        'token' => ['type' => 'string', 'required' => true],
    ],

    'validate' => function (array $params) {
        $token = $params['token'];
        $tokens = pp_design_tokens();
        if (!array_key_exists($token, $tokens)) {
            $available = implode(', ', array_keys($tokens));
            return new WP_Error('unknown_token', sprintf('Token "%s" is not a registered design token. Available: %s', $token, $available));
        }

        // #230: resetting restores the base.css default, which may itself be
        // a var() reference (e.g. --text-meta-color: var(--color-muted)).
        // If current overrides make that chain lead back to this token, the
        // reset would persist the exact guaranteed-invalid cycle
        // update_design_token rejects — reject it here too, symmetrically.
        $file_tokens   = _pp_read_tokens_from_file(get_template_directory() . '/assets/css/base.css');
        $default_value = $file_tokens[$token] ?? null;
        if (is_string($default_value)) {
            $cycle = _pp_check_token_reference_cycle($token, trim($default_value));
            if (is_wp_error($cycle)) {
                return $cycle;
            }
        }
        return true;
    },

    'preview' => function (array $params) {
        $token = $params['token'];
        $tokens = pp_design_tokens();
        $overrides = pp_get_token_overrides();

        if (!isset($overrides[$token])) {
            return _pp_apply_preview(
                'reset_design_token',
                'design',
                ['type' => 'option', 'key' => 'pp_token_overrides'],
                [$token => $tokens[$token]['value']],
                [$token => $tokens[$token]['value']],
                []
            );
        }

        // Read defaults from base.css directly (bypass merge)
        $file = get_template_directory() . '/assets/css/base.css';
        $file_tokens = _pp_read_tokens_from_file($file);
        $default_value = $file_tokens[$token] ?? $tokens[$token]['value'];

        return _pp_apply_preview(
            'reset_design_token',
            'design',
            ['type' => 'option', 'key' => 'pp_token_overrides'],
            [$token => $tokens[$token]['value']],
            [$token => $default_value],
            [['token' => $token, 'from' => $tokens[$token]['value'], 'to' => $default_value]]
        );
    },

    'apply' => function (array $params) {
        $token = $params['token'];
        $target = ['type' => 'option', 'key' => 'pp_token_overrides'];
        $tokens = pp_design_tokens();
        $old_value = $tokens[$token]['value'];

        $cleared = pp_clear_token_override($token);
        if (!$cleared) {
            // Token had no override — no-op
            return _pp_apply_result('reset_design_token', 'design', $target, []);
        }

        // Read the new effective value (product default)
        $new_tokens = pp_design_tokens();
        $new_value = $new_tokens[$token]['value'];

        return _pp_apply_result(
            'reset_design_token',
            'design',
            $target,
            [['token' => $token, 'from' => $old_value, 'to' => $new_value]]
        );
    },
]);

// ── Apply: reset_all_design_tokens ─────────────────────────────────────────
// Domain: design | Clears all token overrides, reverting to product defaults

pp_register_apply('reset_all_design_tokens', [
    'domain'         => 'design',
    'impact_warning' => 'Resets ALL token overrides to defaults',
    'target'      => ['type' => 'option', 'key' => 'pp_token_overrides'],
    'description' => 'Clears all design token overrides, reverting the entire site to product defaults.',
    'params'      => [],

    'validate' => function (array $params) {
        return true;
    },

    'preview' => function (array $params) {
        $tokens = pp_design_tokens();
        $overrides = pp_get_token_overrides();

        $before = [];
        $after = [];
        $file = get_template_directory() . '/assets/css/base.css';
        $file_tokens = _pp_read_tokens_from_file($file);

        foreach ($tokens as $name => $info) {
            $before[$name] = $info['value'];
            $after[$name] = $file_tokens[$name] ?? $info['value'];
        }

        $changes = [];
        foreach ($overrides as $name => $override_value) {
            $default_value = $file_tokens[$name] ?? null;
            if ($default_value !== null) {
                $changes[] = ['token' => $name, 'from' => $override_value, 'to' => $default_value];
            }
        }

        return _pp_apply_preview(
            'reset_all_design_tokens',
            'design',
            ['type' => 'option', 'key' => 'pp_token_overrides'],
            $before,
            $after,
            $changes
        );
    },

    'apply' => function (array $params) {
        $target = ['type' => 'option', 'key' => 'pp_token_overrides'];
        $overrides = pp_get_token_overrides();
        $count = pp_clear_all_token_overrides();

        if ($count === 0) {
            return _pp_apply_result('reset_all_design_tokens', 'design', $target, []);
        }

        $file = get_template_directory() . '/assets/css/base.css';
        $file_tokens = _pp_read_tokens_from_file($file);

        $changes = [];
        foreach ($overrides as $name => $override_value) {
            $default_value = $file_tokens[$name] ?? 'unknown';
            $changes[] = ['token' => $name, 'from' => $override_value, 'to' => $default_value];
        }

        return _pp_apply_result('reset_all_design_tokens', 'design', $target, $changes);
    },
]);

// ── Apply: enqueue_font ────────────────────────────────────────────────────
// Domain: design | Target: option pp_font_urls
// Adds a web font URL to the site's font queue.

pp_register_apply('enqueue_font', [
    'domain'      => 'design',
    'target'      => ['type' => 'option', 'key' => 'pp_font_urls'],
    'description' => 'Adds a web font URL (e.g. Google Fonts, Bunny Fonts) to the site. Max 5 fonts. Loading the stylesheet alone changes nothing visible — pass family (the CSS font-family name the stylesheet defines) with apply_to ("heading" | "body" | "both") to also point the matching --font-heading/--font-body design token(s) at it in the same call. Omit family and the result returns a best-effort family derived from the URL as a suggestion, without changing any token.',
    'params'      => [
        'url'      => ['type' => 'string', 'required' => true],
        'family'   => ['type' => 'string', 'required' => false],
        'apply_to' => ['type' => 'string', 'required' => false],
    ],

    'validate' => function (array $params) {
        $url = $params['url'] ?? '';
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Value must be a valid URL.');
        }
        if (!preg_match('/^https:\/\//', $url)) {
            return new WP_Error('invalid_url', 'Font URL must use HTTPS.');
        }
        $current = pp_get_font_urls();
        if (in_array($url, $current, true)) {
            return new WP_Error('duplicate_font', 'This font URL is already enqueued.');
        }
        if (count($current) >= 5) {
            return new WP_Error('font_limit', 'Maximum 5 font URLs allowed. Remove one first.');
        }

        $family = $params['family'] ?? '';
        if ($family !== '' && !_pp_validate_font_family($family)) {
            return new WP_Error('invalid_font_family', 'family must be a comma-separated list of font names.');
        }

        $apply_to = $params['apply_to'] ?? '';
        if ($apply_to !== '') {
            if (!in_array($apply_to, ['heading', 'body', 'both'], true)) {
                return new WP_Error('invalid_apply_to', 'apply_to must be "heading", "body", or "both".');
            }
            if ($family === '' && _pp_derive_font_family_from_url($url) === '') {
                return new WP_Error('missing_family', 'apply_to requires family — no family name could be derived from this URL, so pass one explicitly.');
            }
        }

        return true;
    },

    'preview' => function (array $params) {
        $current = pp_get_font_urls();
        $after = array_merge($current, [$params['url']]);
        $changes = [['action' => 'add', 'url' => $params['url']]];

        $family = $params['family'] ?? '';
        if ($family === '') {
            $family = _pp_derive_font_family_from_url($params['url']);
        }
        $apply_to = $params['apply_to'] ?? '';
        if ($apply_to !== '' && $family !== '') {
            $tokens = pp_design_tokens();
            $value = $family . ', system-ui, sans-serif';
            foreach (_pp_font_apply_to_tokens($apply_to) as $token) {
                $changes[] = ['token' => $token, 'from' => $tokens[$token]['value'] ?? null, 'to' => $value];
            }
        }

        return _pp_apply_preview(
            'enqueue_font', 'design',
            ['type' => 'option', 'key' => 'pp_font_urls'],
            $current, $after,
            $changes
        );
    },

    'apply' => function (array $params) {
        $current = pp_get_font_urls();
        $current[] = $params['url'];
        pp_set_font_urls($current);
        $changes = [['action' => 'add', 'url' => $params['url']]];

        $family = $params['family'] ?? '';
        $family_source = $family !== '' ? 'explicit' : null;
        if ($family === '') {
            $family = _pp_derive_font_family_from_url($params['url']);
            if ($family !== '') {
                $family_source = 'derived';
            }
        }

        $apply_to = $params['apply_to'] ?? '';
        if ($apply_to !== '' && $family !== '') {
            $tokens = pp_design_tokens();
            $value = $family . ', system-ui, sans-serif';
            foreach (_pp_font_apply_to_tokens($apply_to) as $token) {
                $old_value = $tokens[$token]['value'] ?? null;
                pp_set_token_override($token, $value);
                $changes[] = ['token' => $token, 'from' => $old_value, 'to' => $value];
            }
        }

        $result = _pp_apply_result(
            'enqueue_font', 'design',
            ['type' => 'option', 'key' => 'pp_font_urls'],
            $changes
        );
        if ($family !== '') {
            $result['family'] = $family;
            $result['family_source'] = $family_source;
        }
        return $result;
    },
]);

// ── Apply: remove_font ─────────────────────────────────────────────────────
// Domain: design | Target: option pp_font_urls
// Removes a font URL from the queue.

pp_register_apply('remove_font', [
    'domain'      => 'design',
    'target'      => ['type' => 'option', 'key' => 'pp_font_urls'],
    'description' => 'Removes a web font URL from the site.',
    'params'      => [
        'url' => ['type' => 'string', 'required' => true],
    ],

    'validate' => function (array $params) {
        $current = pp_get_font_urls();
        if (!in_array($params['url'], $current, true)) {
            return new WP_Error('font_not_found', 'This font URL is not enqueued.');
        }
        return true;
    },

    'preview' => function (array $params) {
        $current = pp_get_font_urls();
        $after = array_values(array_filter($current, fn($u) => $u !== $params['url']));
        return _pp_apply_preview(
            'remove_font', 'design',
            ['type' => 'option', 'key' => 'pp_font_urls'],
            $current, $after,
            [['action' => 'remove', 'url' => $params['url']]]
        );
    },

    'apply' => function (array $params) {
        $current = pp_get_font_urls();
        $after = array_values(array_filter($current, fn($u) => $u !== $params['url']));
        pp_set_font_urls($after);
        return _pp_apply_result(
            'remove_font', 'design',
            ['type' => 'option', 'key' => 'pp_font_urls'],
            [['action' => 'remove', 'url' => $params['url']]]
        );
    },
]);

// ── Apply: reset_fonts ─────────────────────────────────────────────────────
// Domain: design | Target: option pp_font_urls
// Clears all custom font URLs.

pp_register_apply('reset_fonts', [
    'domain'      => 'design',
    'target'      => ['type' => 'option', 'key' => 'pp_font_urls'],
    'description' => 'Removes all custom font URLs from the site.',
    'params'      => [],

    'validate' => function (array $params) {
        return true;
    },

    'preview' => function (array $params) {
        $current = pp_get_font_urls();
        return _pp_apply_preview(
            'reset_fonts', 'design',
            ['type' => 'option', 'key' => 'pp_font_urls'],
            $current, [],
            array_map(fn($u) => ['action' => 'remove', 'url' => $u], $current)
        );
    },

    'apply' => function (array $params) {
        $current = pp_get_font_urls();
        pp_set_font_urls([]);
        return _pp_apply_result(
            'reset_fonts', 'design',
            ['type' => 'option', 'key' => 'pp_font_urls'],
            array_map(fn($u) => ['action' => 'remove', 'url' => $u], $current)
        );
    },
]);

// ── Apply: import_media ──────────────────────────────────────────────────
// Domain: media | Target: media library (new attachment)
// Sideloads an external image URL into the media library. The only sanctioned
// path to bring an external image onto the site as a locally-owned asset —
// image props otherwise only accept a raw URL string (#105).
//
// SSRF safety is WordPress core's job, not reinvented here: download_url()
// fetches via wp_safe_remote_get(), which validates the URL AND every
// redirect hop against private/reserved IP ranges, non-http(s) schemes, and
// disallowed ports (see wp_http_validate_url() in wp-includes/http.php).
// This apply adds: HTTPS-only + plausible-extension pre-check (fast fail,
// no network use for obviously-wrong URLs), a real post-download mime check
// restricted to images (WordPress's default upload mime allowlist is much
// broader — PDFs, docs, zips — which this apply deliberately narrows), and
// a size cap that download_url() does not itself enforce.

pp_register_apply('import_media', [
    'domain'      => 'media',
    'target'      => ['type' => 'media'],
    'description' => 'Brings an image into the media library and returns its attachment id + local URL. Source is EITHER url (a remote HTTPS image, sideloaded, deduped by source URL) OR file (a server-local absolute path to a brand-kit asset, copied then sideloaded) — provide exactly one. Result action is "import" (new) or, for a repeat url, "reused".',
    'params'      => [
        // Exactly one of url/file is required; mutual exclusion is enforced in
        // validate (the framework only checks types/individually-required here).
        'url'  => ['type' => 'string', 'required' => false],
        'file' => ['type' => 'string', 'required' => false],
        'alt'  => ['type' => 'string', 'required' => false],
    ],

    'validate' => function (array $params) {
        $url  = $params['url']  ?? '';
        $file = $params['file'] ?? '';
        $has_url  = is_string($url)  && $url  !== '';
        $has_file = is_string($file) && $file !== '';

        // url and file are two sources for the same result; requiring exactly
        // one keeps the envelope unambiguous and avoids "which won?" surprises.
        if ($has_url && $has_file) {
            return new WP_Error('invalid_params', 'Provide either a url or a file, not both.');
        }
        if (!$has_url && !$has_file) {
            return new WP_Error('invalid_params', 'Provide a url (remote image) or a file (local absolute path).');
        }

        // Local-file path (#490): the same run-token gating, preflight, and
        // journal treatment as the URL path (this is the same apply), with a
        // filesystem-shaped validation twin. Path resolution happens FIRST.
        if ($has_file) {
            return _pp_validate_import_media_file($file);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Value must be a valid URL.');
        }
        if (!preg_match('/^https:\/\//i', $url)) {
            return new WP_Error('invalid_url', 'Media URL must use HTTPS.');
        }
        if (!_pp_url_has_allowed_image_extension($url)) {
            return new WP_Error('unsupported_type', 'URL must end in a supported image extension: jpg, jpeg, png, gif, webp.');
        }
        return true;
    },

    'preview' => function (array $params) {
        $file = $params['file'] ?? '';
        // Local-file preview (#490): resolve symlinks, then verify the bytes are
        // a genuine image the same way apply() will -- the filesystem twin of the
        // URL preview's HEAD content-type probe. Only the basename is ever echoed
        // back; the operator's absolute path is never disclosed.
        if (is_string($file) && $file !== '') {
            $real = realpath($file);
            if ($real === false || !is_file($real) || !is_readable($real)) {
                return new WP_Error('invalid_file', 'File does not exist or is not readable.');
            }
            $verified_mime = _pp_import_media_verify_local_image($real, basename($real));
            if (is_wp_error($verified_mime)) {
                return $verified_mime;
            }
            return _pp_apply_preview(
                'import_media', 'media',
                ['type' => 'media'],
                [],
                ['file' => basename($real), 'alt' => $params['alt'] ?? '', 'content_type' => $verified_mime],
                [['action' => 'import', 'file' => basename($real)]]
            );
        }

        $url = $params['url'];
        // A HEAD request, not a download -- still routed through WordPress's
        // SSRF-safe fetch path (wp_safe_remote_head validates the URL and
        // every redirect hop the same way wp_safe_remote_get does).
        $response = wp_safe_remote_head($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return $response;
        }
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!is_string($content_type) || !str_starts_with($content_type, 'image/')) {
            return new WP_Error('unsupported_type', 'URL does not serve an image content type.');
        }
        return _pp_apply_preview(
            'import_media', 'media',
            ['type' => 'media'],
            [],
            ['url' => $url, 'alt' => $params['alt'] ?? '', 'content_type' => $content_type],
            [['action' => 'import', 'url' => $url]]
        );
    },

    'apply' => function (array $params) {
        $alt  = $params['alt'] ?? '';
        $file = $params['file'] ?? '';

        // Local-file path (#490): stage a COPY and sideload the copy so the
        // operator's source file is never consumed. Same attachment-id envelope,
        // same journalled surface (this is the same apply as the URL path).
        if (is_string($file) && $file !== '') {
            return _pp_import_media_apply_local_file($file, $alt);
        }

        $url = $params['url'];

        // Source-URL dedupe (#298). import_media is the sanctioned way an AI
        // operator brings an external image onto the site, and that loop retries
        // and re-runs — so importing the SAME remote URL must not silently
        // accrete a duplicate attachment on every call. If a prior import of
        // this exact source URL is still on the site, reuse it. The lookup is
        // read-only (never mutates state) and the reuse path writes NOTHING —
        // not the file, not the attachment, not alt (a differing alt on a repeat
        // call is deliberately ignored; dedupe reuses the existing asset as-is).
        // This runs after the framework's validate gate (pp_execute_apply), so
        // $url is already a valid HTTPS image URL here. Matching is on the exact
        // source URL recorded below; it only covers imports made by this
        // mechanism (a pre-#298 duplicate carries no marker and imports once
        // more, after which its marker makes it dedupe-eligible).
        $existing_id = _pp_find_attachment_by_source_url($url);
        if ($existing_id !== null) {
            $existing_url = wp_get_attachment_url($existing_id);
            // Only reuse an attachment that still resolves to a URL. If it no
            // longer does (deleted attachment, or a missing _wp_attached_file so
            // wp_get_attachment_url() returns false/empty), fall through and
            // re-import rather than hand the operator a broken URL. This does not
            // stat the physical file — a record that still resolves but whose
            // bytes were removed from disk/object storage is reused as-is. The
            // lookup returns the NEWEST match, so a fresh re-import becomes the
            // next reuse target instead of re-importing on every call.
            if (is_string($existing_url) && $existing_url !== '') {
                return _pp_apply_result(
                    'import_media', 'media',
                    ['type' => 'media'],
                    [[
                        'action'        => 'reused',
                        'attachment_id' => $existing_id,
                        'url'           => $existing_url,
                        'source_url'    => $url,
                    ]]
                );
            }
        }

        // Guarded like WP core's own admin-context checks: these files aren't
        // autoloaded outside wp-admin (CLI, AJAX, cron). media_handle_sideload()
        // is the outermost of the three real functions this apply calls, and
        // the PHPUnit test harness stubs it directly -- if it's already
        // defined (real WP admin context, or the test stub), none of these
        // three files need loading.
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // download_url() streams via wp_safe_remote_get() to a temp file --
        // SSRF protection (private IPs, redirect re-validation, protocol/port
        // restriction) is inherited from WordPress core, not reimplemented.
        $tmp_file = download_url($url, 30);
        if (is_wp_error($tmp_file)) {
            return _pp_apply_error('import_media', 'media', ['type' => 'media'], $tmp_file->get_error_message());
        }

        $max_bytes = 10 * MB_IN_BYTES;
        if (filesize($tmp_file) > $max_bytes) {
            @unlink($tmp_file);
            return _pp_apply_error('import_media', 'media', ['type' => 'media'], 'Image exceeds the 10MB size limit.');
        }

        $filename = sanitize_file_name(basename((string) parse_url($url, PHP_URL_PATH)));
        $filetype = wp_check_filetype_and_ext($tmp_file, $filename);
        $allowed_mimes = _pp_import_media_allowed_mimes();
        if (empty($filetype['type']) || !in_array($filetype['type'], $allowed_mimes, true)) {
            @unlink($tmp_file);
            return _pp_apply_error('import_media', 'media', ['type' => 'media'], 'The downloaded file is not a supported image type (jpg, png, gif, webp).');
        }

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp_file,
        ];

        $attachment_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            return _pp_apply_error('import_media', 'media', ['type' => 'media'], $attachment_id->get_error_message());
        }

        if ($alt !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        }

        // Record the source URL so a later import of the same remote file
        // dedupes to this attachment instead of creating a duplicate (#298).
        update_post_meta($attachment_id, '_pp_import_source_url', $url);

        return _pp_apply_result(
            'import_media', 'media',
            ['type' => 'media'],
            [[
                'action'        => 'import',
                'attachment_id' => $attachment_id,
                'url'           => wp_get_attachment_url($attachment_id),
                'source_url'    => $url,
            ]]
        );
    },
]);

/**
 * Checks whether a URL's path ends in a recognized, safe image extension.
 * Query strings are excluded (PHP_URL_PATH). Used as a fast pre-fetch
 * sanity check -- the real, authoritative type check happens post-download
 * via wp_check_filetype_and_ext() against the actual file bytes.
 */
function _pp_url_has_allowed_image_extension(string $url): bool {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

/**
 * The image MIME types import_media accepts, for BOTH the url and file paths.
 * Deliberately narrower than WordPress's default upload allowlist (which also
 * permits PDFs, docs, zips): import_media exists to bring IMAGES onto the site.
 * Single source of truth so the two source paths can never drift apart.
 */
function _pp_import_media_allowed_mimes(): array {
    return ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
}

/**
 * Checks whether a filesystem path ends in a recognized image extension. Fast
 * pre-check for import_media's file path — the authoritative content check is
 * _pp_import_media_verify_local_image() (getimagesize + WP filetype agreement).
 */
function _pp_file_has_allowed_image_extension(string $path): bool {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

/**
 * Validation for import_media's local-file source (#490). Resolves symlinks with
 * realpath() FIRST, then requires the resolved target to exist, be a regular
 * readable file, carry an allowed image extension, sit within the 10MB cap
 * (enforced pre-read via a local stat — the URL path enforces the same cap
 * post-download), and be a GENUINE image (getimagesize + WP filetype agreement,
 * per the recorded decision). Error messages never echo the operator's path.
 *
 * This reads a server-local path BY DESIGN: the operator CLI already runs with
 * admin rights, so there is no staging-directory ceremony — the caller names
 * the file and the action journals the media write the loop otherwise had no
 * record of.
 *
 * @return true|WP_Error
 */
function _pp_validate_import_media_file(string $file) {
    // Require an absolute path: a relative path would resolve against an
    // unpredictable process cwd (CLI vs cron vs web request), so the operator's
    // intent is only unambiguous when the path is absolute. Symlinks in the path
    // are followed by realpath() below — every later check sees the real target.
    if ($file === '' || $file[0] !== '/') {
        return new WP_Error('invalid_file', 'File must be an absolute path.');
    }
    $real = realpath($file);
    if ($real === false || !is_file($real)) {
        return new WP_Error('invalid_file', 'File does not exist or is not a regular file.');
    }
    if (!is_readable($real)) {
        return new WP_Error('invalid_file', 'File is not readable.');
    }
    if (!_pp_file_has_allowed_image_extension($real)) {
        return new WP_Error('unsupported_type', 'File must have a supported image extension: jpg, jpeg, png, gif, webp.');
    }
    if (filesize($real) > 10 * MB_IN_BYTES) {
        return new WP_Error('oversized', 'Image exceeds the 10MB size limit.');
    }
    // Genuine-image gate, per the recorded decision (validation includes "must
    // be a GENUINE image — WP filetype check AND getimagesize agreement"). The
    // file path can afford this at validate time because the bytes are local
    // (the URL path can only sniff content post-download, in apply).
    $verified = _pp_import_media_verify_local_image($real, basename($real));
    if (is_wp_error($verified)) {
        return $verified;
    }
    return true;
}

/**
 * Verifies that a local file is a GENUINE image whose actual bytes, WordPress's
 * filetype detection, and its filename extension all agree (#490). The
 * filesystem twin of the URL path's post-download wp_check_filetype_and_ext()
 * gate, hardened with a getimagesize() cross-check so a non-image — or a JPEG
 * wearing a .png extension — cannot slip through on a trusted-looking name.
 *
 * Rejects, in order: bytes that do not decode as an image at all (getimagesize
 * returns false), a type outside the jpg/png/gif/webp allowlist, a missing or
 * disallowed WordPress-detected type, or a WP type that disagrees with
 * getimagesize / a non-empty proper_filename (extension ≠ content). Returns the
 * agreed image MIME on success. Read-only: never mutates state, never moves the
 * file.
 *
 * @param  string $path      A readable local file (source, or the staged copy).
 * @param  string $filename  The name whose extension the content must match
 *                           (the intended image name — NOT a wp_tempnam() ".tmp"
 *                           staging name, which would fail the extension check).
 * @return string|WP_Error   The agreed image MIME on success.
 */
function _pp_import_media_verify_local_image(string $path, string $filename) {
    $allowed = _pp_import_media_allowed_mimes();

    $info = @getimagesize($path);
    if ($info === false || empty($info['mime'])) {
        return new WP_Error('unsupported_type', 'The file is not a supported image (jpg, png, gif, webp).');
    }
    $image_mime = strtolower((string) $info['mime']);
    if (!in_array($image_mime, $allowed, true)) {
        return new WP_Error('unsupported_type', 'The file is not a supported image (jpg, png, gif, webp).');
    }

    // WordPress's own extension-vs-content check. A non-empty proper_filename
    // means the filename's extension disagrees with the sniffed content (e.g. a
    // JPEG named logo.png) — reject that mismatch rather than let WP silently
    // rename it, per the recorded decision.
    $filetype = wp_check_filetype_and_ext($path, $filename);
    $wp_type  = is_array($filetype) ? (string) ($filetype['type'] ?? '') : '';
    if ($wp_type === '' || !in_array($wp_type, $allowed, true)) {
        return new WP_Error('unsupported_type', 'The file is not a supported image (jpg, png, gif, webp).');
    }
    if (strtolower($wp_type) !== $image_mime || !empty($filetype['proper_filename'])) {
        return new WP_Error('unsupported_type', 'The file extension does not match its actual image type.');
    }

    return $image_mime;
}

/**
 * import_media's local-file apply path (#490): stage a copy of a server-local
 * image and sideload THE COPY into the media library, returning the same
 * attachment-id envelope as the URL path so pp_logo_id / site_icon /
 * pp_og_image consume it identically.
 *
 * Why copy first: media_handle_sideload() hands its tmp_name to
 * wp_handle_sideload(), which MOVES (renames) that file into wp-content/uploads
 * and unlinks the original. Passing the operator's kit file directly would
 * therefore CONSUME it. We copy to a wp_tempnam() staging file and sideload the
 * copy, so the operator's source is never moved, renamed, or deleted.
 *
 * validate() already ran the structural gate, but apply() is reachable directly
 * (batch executor, direct callers), so the path is re-resolved and re-checked
 * here defensively rather than trusting an earlier gate.
 */
function _pp_import_media_apply_local_file(string $file, string $alt): array {
    $target = ['type' => 'media'];

    // Resolve symlinks BEFORE trusting anything about the path.
    $real = realpath($file);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        return _pp_apply_error('import_media', 'media', $target, 'File does not exist or is not readable.');
    }
    if (filesize($real) > 10 * MB_IN_BYTES) {
        return _pp_apply_error('import_media', 'media', $target, 'Image exceeds the 10MB size limit.');
    }

    // Guarded like the URL path: these admin-context files aren't autoloaded
    // outside wp-admin (CLI, AJAX, cron). wp_tempnam() lives in file.php,
    // media_handle_sideload() in media.php.
    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $filename = sanitize_file_name(basename($real));

    // Copy to a staging temp file so the sideload consumes the COPY, never the
    // operator's source (see the doc-comment above).
    $tmp = wp_tempnam($filename);
    if (!$tmp || !@copy($real, $tmp)) {
        if ($tmp) {
            @unlink($tmp);
        }
        return _pp_apply_error('import_media', 'media', $target, 'Could not stage the file for import.');
    }

    // Verify the EXACT bytes that will be imported (the staged copy), against
    // the intended filename. validate() already gated $real, but re-verifying
    // $tmp here closes any verify→import gap (a source swapped after validate)
    // and guarantees only a genuine image reaches media_handle_sideload — which
    // otherwise re-checks against WordPress's much broader default mime
    // allowlist (pdf/zip/docx/…), not this apply's images-only list.
    $verify = _pp_import_media_verify_local_image($tmp, $filename);
    if (is_wp_error($verify)) {
        @unlink($tmp);
        return _pp_apply_error('import_media', 'media', $target, $verify->get_error_message());
    }

    $file_array    = ['name' => $filename, 'tmp_name' => $tmp];
    $attachment_id = media_handle_sideload($file_array, 0);
    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return _pp_apply_error('import_media', 'media', $target, $attachment_id->get_error_message());
    }
    // media_handle_sideload moves tmp into uploads on success; if anything left
    // the staging file behind, clean it up (never leave a temp file around).
    if (file_exists($tmp)) {
        @unlink($tmp);
    }

    if ($alt !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    }

    // No source-URL dedupe here: a local path is not a stable remote identity,
    // and recording it would disclose the operator's filesystem layout in
    // post-meta. Each file import is a fresh, journalled attachment.
    return _pp_apply_result(
        'import_media', 'media', $target,
        [[
            'action'        => 'import',
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url($attachment_id),
        ]]
    );
}

/**
 * Finds an existing attachment previously imported from the given source URL,
 * for import_media's source-URL dedupe (#298).
 *
 * Matches on the _pp_import_source_url post-meta that import_media records at
 * import time, scoped to real 'inherit'-status attachments (excludes trashed
 * and private records). Returns the NEWEST matching attachment id (most recent
 * import — the copy most likely to still have its file on disk), or null when
 * nothing matches. Read-only: never mutates state.
 */
function _pp_find_attachment_by_source_url(string $url): ?int {
    $matches = get_posts([
        'post_type'   => 'attachment',
        'post_status' => ['inherit'],
        'meta_key'    => '_pp_import_source_url',
        'meta_value'  => $url,
        'orderby'     => 'ID',
        'order'       => 'DESC',
        'numberposts' => 1,
    ]);
    if (empty($matches)) {
        return null;
    }
    return (int) $matches[0]->ID;
}

// ── Deployment Manifest (Sync Safeguard) ───────────────────────────────────

/**
 * Returns the path to the deployment manifest file.
 * Stored in wp-content/ (outside theme dir, survives theme sync).
 */
function _pp_deployment_manifest_path(): string {
    if (defined('WP_CONTENT_DIR')) {
        return WP_CONTENT_DIR . '/pp-deployment-manifest.json';
    }
    return dirname(dirname(get_template_directory())) . '/pp-deployment-manifest.json';
}

/**
 * Loads the deployment manifest. Returns null if missing or malformed.
 *
 * @return array|null  ['timestamp' => string, 'theme_path' => string, 'file_hashes' => [relative => md5]]
 */
function _pp_load_deployment_manifest(): ?array {
    $path = _pp_deployment_manifest_path();
    if (!file_exists($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data['file_hashes'])) {
        return null;
    }
    return $data;
}

/**
 * Saves a deployment manifest snapshot.
 *
 * @param string $theme_path  Absolute path to the theme directory.
 * @param array  $file_hashes Map of relative_path => md5 hash.
 * @return bool
 */
function _pp_save_deployment_manifest(string $theme_path, array $file_hashes): bool {
    $manifest = [
        'timestamp'   => date('c'),
        'theme_path'  => $theme_path,
        'file_hashes' => $file_hashes,
    ];
    $result = file_put_contents(
        _pp_deployment_manifest_path(),
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    return $result !== false;
}

/**
 * Converts an absolute file path within the theme directory into a
 * normalized, forward-slash-only relative path (issue 127).
 *
 * On Windows hosting (IIS/XAMPP — fully supported by WordPress),
 * RecursiveDirectoryIterator::getPathname() joins path segments with `\`.
 * A plain `ltrim(str_replace($theme_path, '', $pathname), '/')` never
 * strips a leading backslash, and nested paths keep `\` separators (e.g.
 * `\components\hero\hero.php`). Since integrity-manifest.json is built on
 * Linux CI with `/` paths, every nested file then mismatches on Windows —
 * reported as both `missing` and `extra` — which flips theme integrity to
 * "unsafe" and blocks every theme update. Normalizing both sides to `/`
 * before stripping the prefix fixes this regardless of which OS actually
 * wrote the file path.
 *
 * Pure function — no I/O — so it's testable on any OS regardless of which
 * platform actually runs the test suite.
 *
 * @param  string $theme_path  Absolute theme directory path (either separator).
 * @param  string $pathname    Absolute file path within the theme (either separator).
 * @return string              Relative path, forward slashes only, no leading separator.
 */
function _pp_relative_theme_path(string $theme_path, string $pathname): string {
    $base = str_replace('\\', '/', $theme_path);
    $path = str_replace('\\', '/', $pathname);
    return ltrim(str_replace($base, '', $path), '/');
}

/**
 * Hashes all theme files (php, css, js, json) for drift detection.
 * Skips .git/, node_modules/, vendor/, tests/ directories.
 *
 * @param string $theme_path  Absolute path to theme directory.
 * @return array  Map of relative_path => md5 hash.
 */
function _pp_hash_theme_files(string $theme_path): array {
    $hashes = [];
    $extensions = ['php', 'css', 'js', 'json'];
    $skip_dirs = ['.git', 'node_modules', 'vendor', 'tests'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($theme_path, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($current, $key, $iterator) use ($skip_dirs) {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), $skip_dirs, true);
                }
                return true;
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $extensions, true)) {
            continue;
        }
        $relative = _pp_relative_theme_path($theme_path, $file->getPathname());
        $hashes[$relative] = md5_file($file->getPathname());
    }

    ksort($hashes);
    return $hashes;
}

/**
 * Hashes ALL theme files for integrity checking (no extension filter).
 * Applies .distignore-equivalent exclusions so dev/repo installs produce
 * the same file set as the build-time manifest.
 *
 * @param string $theme_path  Absolute path to theme directory.
 * @return array  Map of relative_path => md5 hash (false if unreadable).
 */
function _pp_hash_all_theme_files(string $theme_path): array {
    $hashes = [];

    // Directories excluded from the package (mirrors .distignore).
    $skip_dirs = [
        '.git', 'node_modules', 'vendor', 'tests', 'scripts',
        'test-results', 'playwright-report', 'content',
        '.github', '.gstack', '.gstack-screenshots', '.context',
    ];

    // Individual files excluded from the package (mirrors .distignore).
    $skip_files = [
        'composer.json', 'composer.lock', 'composer.phar',
        'package.json', 'package-lock.json', 'phpunit.xml',
        'vitest.config.js', '.wp-env.json', '.distignore',
        'CLAUDE.md', 'TODOS.md', '.phpunit.result.cache',
        'integrity-manifest.json',
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($theme_path, RecursiveDirectoryIterator::SKIP_DOTS),
            function ($current, $key, $iterator) use ($skip_dirs) {
                if ($current->isDir()) {
                    $name = $current->getFilename();
                    // Skip directories matching the exclusion list or starting with a dot.
                    if (in_array($name, $skip_dirs, true) || str_starts_with($name, '.')) {
                        return false;
                    }
                    return true;
                }
                return true;
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $filename = $file->getFilename();

        // Skip dotfiles.
        if (str_starts_with($filename, '.')) {
            continue;
        }

        // Skip ZIP build artifacts.
        if (preg_match('/^promptingpress-.*\.zip$/', $filename)) {
            continue;
        }

        $relative = _pp_relative_theme_path($theme_path, $file->getPathname());

        // Skip individually excluded files.
        if (in_array($relative, $skip_files, true)) {
            continue;
        }

        $hash = md5_file($file->getPathname());
        $hashes[$relative] = $hash !== false ? $hash : false;
    }

    ksort($hashes);
    return $hashes;
}
