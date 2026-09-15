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
            // Dangling. The type validator rejects this for `color`, which is the
            // type this walk was written for; `font-family` also accepts a bare
            // var() but checks only its SHAPE, so a dangling font reference is
            // accepted there and paints nothing (see _pp_validate_font_family()).
            // Either way there is no cycle to report, which is all this owns.
            return true;
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
 * ── THE UNIFIED DIMENSION GRAMMAR (v2, BUILD-SPEC §3.3) ─────────────────────
 *
 * ONE owner for every dimension-bearing value in the program. Before v2 there
 * were SIX mutually-inconsistent unit lists in this file, each grown on its own
 * and none derived from the others:
 *
 *     _pp_validate_length            rem px em % vw vh    hardened number body
 *     …its calc()/clamp() inner list rem px em vw vh      (no %, admitted by char class)
 *     _pp_validate_shadow            px rem ONLY          LOOSE [\d.]+ body
 *     _pp_validate_position          % px rem em          LOOSE [\d.]+ body, no vw/vh
 *     _pp_validate_gradient_color_stop  % px rem em vw vh  NO negatives
 *     radial-gradient `at <position>`   percentages ONLY   no length units at all
 *
 * They disagreed in ways no author could predict: `1.2.3px` was refused as a
 * `length` and ACCEPTED as a `position` token and as a shadow offset; `5vw` was
 * a legal length and a legal gradient stop but an illegal position; `at 10px`
 * was refused outright while `at 10%` was fine. The owner's v2 directive is that
 * these quirks DIE, with no compatibility shim — so all six now derive from the
 * two primitives below, and the differences that REMAIN are per-property facts
 * of CSS itself (box-shadow takes no percentage; blur and spread take no
 * negative), expressed as explicit options rather than as divergent regexes.
 *
 * WHAT IS DELIBERATELY *NOT* WIDENED: the calc()/clamp() capability stays where
 * it already was (the `length` family). Consolidating six unit lists is what the
 * spec directs; handing shadow and position a function grammar they never had
 * would be inventing capability under cover of a cleanup.
 *
 * The two survivors of the old code are the shared core, unchanged:
 * `_pp_forbidden_css_construct()` (the injection gate, which runs ahead of every
 * type) and the hardened number body (#151), which now governs all six instead
 * of two.
 *
 * @return string[] The ONE accepted unit set, longest-first so the alternation
 *                  reads unambiguously even where it is embedded unanchored.
 */
function pp_css_length_units(): array {
    return ['vmin', 'vmax', 'rlh', 'rem', 'ch', 'em', 'ex', 'lh', 'px', 'vh', 'vw'];
}

/**
 * The hardened number body (#151), now the single body for every dimension.
 *
 * `\d+(?:\.\d*)?|\.\d+`: at least one digit, at most one dot, plus the
 * leading-dot form (`.5rem`). Rejects a unit with no digit (`.em`), multiple
 * dots (`1.2.3rem`), and whitespace between number and unit (`1.2 rem` — CSS
 * forbids it). The second digit run sits behind a mandatory `.` so an all-digit
 * input with a bad unit backtracks linearly, not quadratically: no catastrophic
 * -backtracking surface on an uncapped value.
 */
function _pp_css_number_body(): string {
    return '(?:\d+(?:\.\d*)?|\.\d+)';
}

/**
 * An UNANCHORED regex fragment matching one <length> or <length-percentage>
 * token, for embedding in a larger grammar (the radial `at <position>` clause).
 * Callers that validate a whole value should use _pp_css_length() instead.
 *
 * @param bool $signed  Allow a single leading minus.
 * @param bool $percent Allow the `%` unit (a <length-percentage> rather than a
 *                      bare <length>). box-shadow's lengths are <length> only.
 */
function _pp_css_length_fragment(bool $signed = true, bool $percent = true): string {
    $units = pp_css_length_units();
    if ($percent) {
        $units[] = '%';
    }
    return '(?:0|' . ($signed ? '-?' : '') . _pp_css_number_body()
        . '(?:' . implode('|', array_map(static fn($u) => preg_quote($u, '/'), $units)) . '))';
}

/**
 * THE dimension validator. Every length-, percentage- and position-bearing value
 * in the program resolves here.
 *
 * Deliberately does NOT trim: `_pp_validate_length()` never did, and a value with
 * surrounding whitespace is a different value. Callers that split on whitespace
 * (shadow, position) hand over already-bare tokens.
 *
 * @param string $value The candidate token.
 * @param array  $opts  signed    — allow a leading minus (default true). CSS
 *                                  lengths go negative on letter-spacing,
 *                                  margins and text-indent; blur and spread
 *                                  do not.
 *                      percent   — allow `%` (default true). false makes it a
 *                                  bare <length>, which is what box-shadow takes.
 *                      functions — allow calc()/clamp() (default true). Only the
 *                                  `length` family has ever had these.
 */
function _pp_css_length(string $value, array $opts = []): bool {
    $signed    = $opts['signed']    ?? true;
    $percent   = $opts['percent']   ?? true;
    $functions = $opts['functions'] ?? true;

    // Unitless zero, and a well-formed number with an attached unit.
    if (preg_match('/^' . _pp_css_length_fragment($signed, $percent) . '$/', $value)) {
        return true;
    }

    if (!$functions) {
        return false;
    }

    // clamp() or calc(): positive-pattern matching — only digits, dots, unit
    // words, %, comma, whitespace, parens and arithmetic operators. Every
    // alphabetic run must be an allowed unit word EXACTLY, which is what blocks
    // var(), env(), url() and every other function: "var" is not a unit.
    if (!preg_match('/^(clamp|calc)\(/', $value)) {
        return false;
    }
    // The /s modifier lets legal newline whitespace inside the expression
    // (`calc(\n1rem + 2rem)`) extract instead of failing the greedy `.+` (#151).
    if (!preg_match('/^(clamp|calc)\((.+)\)$/s', $value, $m)) {
        return false;
    }
    $fn       = strtolower($m[1]);
    $contents = $m[2];

    // Parens must be PROPERLY nested, not merely count-balanced. The greedy
    // outer extraction checks neither, so `calc(1))` leaves a stray ')' and
    // `calc()1,2()` leaves an improperly-nested `)1,2(`. A count-only check
    // passes the latter and would let the top-level-comma guard below be
    // bypassed, since a leading ')' drives a split walker to negative depth and
    // masks the comma. One left-to-right depth walk rejects both (#151).
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
    // calc() takes ONE arithmetic expression, never comma-separated arguments,
    // so a top-level comma inside calc(...) is malformed (#151). clamp()'s
    // legitimate 3-argument form is left intact; its arity is not further
    // checked (bare-number args stay an accepted residual per the decision —
    // they only degrade to a dropped declaration).
    if ($fn === 'calc' && count(_pp_split_top_level_commas($contents)) > 1) {
        return false;
    }

    $allowed_units = pp_css_length_units();
    preg_match_all('/[a-zA-Z]+/', $contents, $alpha_sequences, PREG_OFFSET_CAPTURE);
    foreach ($alpha_sequences[0] as [$word, $offset]) {
        // The security boundary: any alpha run that is not a unit word is out.
        if (!in_array(strtolower($word), $allowed_units, true)) {
            return false;
        }
        // A real unit is always directly adjacent to the number it qualifies —
        // CSS allows no space between "1" and "rem", and a unit cannot stand
        // alone. This rejects calc(px), calc((rem) + 1px), calc(-rem + 1px) and
        // clamp((rem), 1px, 2px): each has an allowed unit word with no numeric
        // operand behind it, and would otherwise persist as broken CSS the
        // browser silently drops (#129). A correctness check, not a boundary.
        $prev_char = $offset > 0 ? $contents[$offset - 1] : '';
        if ($prev_char === '' || !preg_match('/[\d.]/', $prev_char)) {
            return false;
        }
    }
    // Only digits, dots, unit letters, %, comma, whitespace, parens, + - * /.
    return (bool) preg_match('/^[\d\s.,+\-*\/()%a-zA-Z]+$/', $contents);
}

/**
 * The accepted unit set as prose, for the ONE place each AI-facing surface says
 * it. v1 stated this set in four hand-maintained copies (two error strings, the
 * chat hint at lib/ai-chat.php, and the runtime system prompt) with no test
 * pinning them to the validator — and the runtime prompt never stated it at all,
 * so the model was told what was forbidden and never which units were legal.
 * Every caller now derives from here.
 */
function pp_css_grammar_summary(): string {
    $units = pp_css_length_units();
    sort($units);
    return implode(', ', $units) . ', %';
}

/**
 * Validates a CSS length value.
 * Accepts: numeric value with any unit in pp_css_length_units() (plus `%`),
 * including a single leading minus for negative lengths (letter-spacing/margins/
 * text-indent go negative), unitless 0, clamp() expressions, and calc()
 * expressions — plus, when and only when $allow_none is set, the keyword `none`
 * (issue #579, A-30).
 *
 * @param string $value      The candidate value.
 * @param bool   $allow_none Accept the keyword `none`. Set ONLY by the
 *                           `length-or-none` slot type — the width caps whose DECLARED
 *                           DEFAULT is `none`: the band-geometry cap --stats-max-width
 *                           (#579) plus the four measures that ship uncapped (#578,
 *                           --hero-heading-measure, --section-heading-measure,
 *                           --cta-body-measure, --faq-body-measure) — never by plain `length`:
 *                           `none` on a padding, radius or font-size is a value the
 *                           browser drops, which is the accepted-but-dead class this
 *                           whole engine exists to reject.
 *
 * The grammar itself lives in _pp_css_length() — this function is the
 * `length` / `length-or-none` family's entry point into it, and exists only to
 * add the `none` widening. Number body, unit set, calc()/clamp() handling and
 * every documented residual are described there.
 *
 * Explicitly accepted as residual (documented, won't-fix — each only degrades to
 * a browser-dropped declaration, never injection, thanks to the {};<> guard plus
 * the char-class whitelist): bare unitless operands (`calc(1)`, `clamp(1,2,3)`),
 * doubled operators (`calc(1++2rem)`), and two-operand-no-operator (`calc(1 1)`).
 * A full CSS calc()/clamp() grammar parser is deliberately not built (#151).
 */
function _pp_validate_length(string $value, bool $allow_none = false): bool {
    // `none` — accepted ONLY when the caller's slot declares the band-geometry
    // grammar `length-or-none` (issue #579, A-30). It is not a global widening:
    // `none` on a padding, font-size, radius or letter-spacing slot is a value CSS
    // drops, so those slots keep the plain `length` grammar and keep rejecting it.
    // The defect this closes: --stats-max-width DECLARED `default: "none"` while
    // the grammar could not express it, so the documented workaround was to write
    // `100%` — a declared default no author could author, i.e. a third state.
    if ($allow_none && strtolower(trim($value)) === 'none') {
        return true;
    }
    // Signed, percentage-bearing, function-bearing: the widest of the six
    // families. Negative lengths are valid CSS <length> (letter-spacing, margins
    // and text-indent go negative), so a single leading '-' is accepted (#467);
    // semantically-inert cases (a negative radius or padding) simply drop per
    // CSS — this is a grammar guard, not a per-property check.
    return _pp_css_length($value, ['signed' => true, 'percent' => true, 'functions' => true]);
}

/**
 * The one refusal message for a font-family value — every caller says it.
 *
 * It states the ACCEPTED grammar rather than restating the type's name. The old
 * message ("must be a comma-separated list of font names") described a check
 * that accepted everything, so it could never tell an author what was wrong.
 */
function _pp_font_family_message(string $subject = 'Value'): string {
    return $subject . ' must be a comma-separated list of font names, each one either an '
        . 'unquoted name of letters, digits, spaces, hyphens or underscores (e.g. Helvetica, '
        . '-apple-system, sans-serif), a fully quoted name (e.g. "Helvetica Neue" — quote any '
        . 'name carrying other characters), or a single token reference such as '
        . 'var(--font-heading), with no fallback and no nesting.';
}

/**
 * Validates a CSS font-family value: a comma-separated list of font names.
 *
 * THIS USED TO BE A LENGTH CHECK WEARING A GRAMMAR'S NAME. It split on commas
 * and returned true when anything was left, so every string was a font family —
 * `rgb(`, `Foo"Bar`, `x;}</style>` included. That is load-bearing in two places
 * whose sink is CSS SOURCE TEXT, not the escaped `style` attribute the shared
 * reject set was written for:
 *
 *   - the v2 UDC `typography.family` parameter, emitted into a band's block;
 *   - the `--font-body` / `--font-heading` / `--font-mono` design tokens, which
 *     functions.php emits as a `:root { … }` inline stylesheet.
 *
 * In both, CSS tokenization treats an unbalanced `(` or an unclosed quote as
 * still open and consumes the terminating `;`, the closing `}`, and every rule
 * after it. An allowlist is the only shape that closes that, so each
 * comma-separated name must now be one of exactly three things:
 *
 *   1. a bare `var(--token)` reference, through _pp_parse_token_reference() —
 *      the SAME parser `color` uses, so the no-fallback/no-nesting/no-space
 *      rule has one owner. Required: lib/ai-context.php tells the authoring
 *      model that `font-family` takes a font token, two component schemas
 *      instruct `var(--font-heading)` by name, and a test pins it.
 *      ONLY THE SHAPE, NOT THE TARGET — and the difference is worth naming
 *      because `color` does more here. _pp_validate_color() additionally
 *      requires the referenced token to EXIST in pp_design_tokens() and to be
 *      colour-typed, so a dangling or wrong-typed colour reference is refused
 *      (#230). font-family has never checked either, and this change did not
 *      add it: `var(--font-headingg)` and `var(--space-lg)` validate here and
 *      then paint nothing. That is a live gap in the no-dangling-references
 *      guarantee rather than a security one — the shape itself cannot carry a
 *      delimiter — and closing it would narrow an accepted input, so it is
 *      recorded rather than taken. The refusal messages and the AI-facing docs
 *      were corrected to stop calling it a checked "font token";
 *   2. a fully-quoted name whose quote character does not recur inside it, so
 *      the string cannot terminate early and leave the declaration open;
 *   3. an unquoted name of letters, digits, spaces, `-` and `_` — which covers
 *      every generic family, every hyphenated system font (`-apple-system`,
 *      `ui-monospace`) and every ASCII face name.
 *
 * DELIBERATE NARROWINGS, all fail-closed and all with a legal rewrite: an
 * unquoted non-ASCII family name (quote it), an empty name from a doubled or
 * trailing comma (remove it), and a name carrying punctuation outside quotes
 * (quote it). None of them appear in any schema default, design token or
 * shipped fixture — the accepted set was swept before this landed.
 */
function _pp_validate_font_family(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    // THE SHARED REJECT SET, HERE AND NOT ONLY IN THE CALLERS.
    //
    // Two of this function's three callers run it first — _pp_validate_token_value()
    // for design tokens and style slots, pp_udc_validate_value() for UDC values —
    // and the third does not: `enqueue_font`'s validate arm calls this directly on
    // the caller's `family`, and on `apply_to` that string is concatenated into a
    // `--font-body`/`--font-heading` override that functions.php emits as a
    // `:root { … }` inline stylesheet. Without this line the quoted branch below
    // accepts any interior byte that is not its own quote character, `{ } ; < >`
    // included, so a "font name" could close the rule and the style element.
    // Relying on callers to gate first is how the delimiter guard came to be wired
    // to one of its two doors; a validator whose output reaches CSS source text
    // has to be safe when called alone. Cheap, and deliberately the SAME set
    // rather than a second list of characters.
    if (_pp_forbidden_css_construct($value) !== null) {
        return false;
    }
    foreach (explode(',', $value) as $name) {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        if (_pp_parse_token_reference($name) !== null) {
            continue;
        }
        $quote = $name[0];
        if ($quote === '"' || $quote === "'") {
            $len = strlen($name);
            // Opens and closes with the same quote, and does not carry that
            // quote in between — `"Foo"Bar"` would close at character five and
            // leave `Bar"` as loose source text.
            if ($len < 2
                || $name[$len - 1] !== $quote
                || strpos(substr($name, 1, $len - 2), $quote) !== false) {
                return false;
            }
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9 _-]+$/', $name)) {
            return false;
        }
    }
    return true;
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
    $family = _pp_first_query_value($query, 'family');
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
 * The FIRST value for `$key` in a query string (#968).
 *
 * EXISTS BECAUSE `parse_str()` CANNOT EXPRESS "FIRST". It builds an array, so a
 * repeated key keeps the LAST occurrence — and the CSS2 font API requests several
 * families exactly that way (`?family=Inter&family=Playfair+Display`). The caller
 * above documented "only the first family is used" and, through parse_str(), did
 * the opposite.
 *
 * WHY FIRST IS THE RULING (#968, ruling D2) rather than the docblock being
 * corrected to match: the function already took the FIRST family of a pipe-joined
 * legacy list (`family=inter|playfair-display`), so the two multi-family syntaxes
 * disagreed with each other — and `ai-instructions/retheme.md` ships both of them
 * side by side as equivalent ways to request the same pair. One function answering
 * "which family" two ways depending on URL syntax is the hidden divergence I36
 * forbids. Since #965 the derived family decides whether the whole `enqueue_font`
 * call is REFUSED, so it is load-bearing rather than cosmetic.
 *
 * DECODING MATCHES parse_str() FOR FLAT `key=value` PAIRS, key and value alike: it
 * urldecodes both, so `fam%69ly=X` reached the old code as `family`, and `+` and `%20`
 * both become a space in either. Anything narrower there would silently change which
 * URLs resolve at all, which is a second behaviour change riding along with the ruled one.
 *
 * IT IS NOT parse_str() IN FULL, and the claim is narrowed to what is true (#986 review).
 * parse_str() does two further things to KEYS that this loop deliberately does not:
 *
 *   - Bracket syntax builds an array. `?family[]=Inter` bound `$parsed['family']` to an
 *     ARRAY, which the caller then handed to explode() — a TypeError on PHP 8, i.e. a
 *     fatal reachable from a URL. Here `urldecode('family[]') === 'family'` is false, so
 *     the key is simply not found and the caller refuses the enqueue. That is a NARROWING
 *     toward refusal, and it closes a fatal rather than opening anything.
 *   - `.` and leading spaces in a key are rewritten to `_`. A key that only becomes
 *     `family` after that rewrite is not found here either.
 *
 * Both shapes are absent from every font URL any provider emits, and both fail SAFE.
 * They are stated rather than implied because "matches parse_str()" read as a promise
 * that the two agree everywhere, and they do not.
 *
 * @return string The decoded first value, or '' when the key is absent.
 */
function _pp_first_query_value(string $query, string $key): string {
    foreach (explode('&', $query) as $pair) {
        if ($pair === '') {
            continue;
        }
        $eq = strpos($pair, '=');
        if ($eq === false) {
            // A bare `family` with no `=` is a present key with an empty value,
            // which is what parse_str() makes of it too.
            if (urldecode($pair) === $key) {
                return '';
            }
            continue;
        }
        if (urldecode(substr($pair, 0, $eq)) === $key) {
            return urldecode(substr($pair, $eq + 1));
        }
    }
    return '';
}

/**
 * Resolves the font family `enqueue_font` will use, and says where it came from.
 *
 * THE ONE OWNER OF "WHICH FAMILY IS THIS CALL ABOUT". All three arms of
 * `enqueue_font` need the answer and all three used to spell it out for
 * themselves — the validate arm at one line, the preview and apply arms at four
 * each. Three copies of a rule is three rules, and the one that mattered was the
 * one the validate arm did NOT have: it called the deriver only to compare the
 * result against `''`, then let the apply arm derive again and write the result
 * into a `--font-heading`/`--font-body` override without anyone checking its
 * grammar. An explicit `family` was validated; the derived twin of the same
 * value was not, because the two arms were separate transcriptions of the same
 * sentence and only one of them carried the check.
 *
 * So the resolution moves here and the arms call it. Validate-time and
 * apply-time now cannot disagree about WHICH string is at stake, which is the
 * precondition for them agreeing about whether it is allowed.
 *
 * `source` is what the arms actually branch on, and it is deliberately a
 * three-state rather than a boolean: 'explicit' (the caller passed `family`),
 * 'derived' (we read it out of the URL's `family=` parameter), or null (there is
 * no family at all — no `family` param and nothing derivable, so no token is
 * written and none needs checking). The apply arm already reported exactly this
 * distinction back to the caller as `family_source`; it is now computed once
 * instead of reconstructed there.
 *
 * @param  array $params  The `enqueue_font` params (`url`, optional `family`).
 * @return array          ['family' => string, 'source' => 'explicit'|'derived'|null].
 */
function _pp_enqueue_font_family(array $params): array {
    $family = (string) ($params['family'] ?? '');
    if ($family !== '') {
        return ['family' => $family, 'source' => 'explicit'];
    }

    $family = _pp_derive_font_family_from_url((string) ($params['url'] ?? ''));

    return ['family' => $family, 'source' => $family !== '' ? 'derived' : null];
}

/**
 * The refusal message for a derived family that fails the font-family grammar.
 *
 * Names the DERIVED value, because the caller never typed it: they passed a URL
 * and the engine read a family name out of its `family=` parameter. A message
 * that only said "invalid font family" would be describing a string the operator
 * cannot see in their own request. The value goes through the reflected-text
 * owner (`_pp_clean_reflected_text`, lib/wp.php) at PP_REFLECTED_NAME_MAX, on the
 * same grounds as every other caller-derived name this engine echoes: it is
 * attacker-influenced text, `parse_str()` URL-decodes it, and a control or
 * bidi-formatting character in it would otherwise reach whatever renders the
 * refusal — including the model reading its own tool output.
 *
 * @param  string $derived  The family name derived from the URL.
 * @return string
 */
function _pp_derived_font_family_message(string $derived): string {
    return sprintf(
        'No family was passed, so one was derived from the URL: "%s". %s'
        . ' To enqueue this URL, pass family explicitly (quoted if the name carries'
        . ' punctuation or non-ASCII, e.g. "Suisse Int\'l").',
        _pp_clean_reflected_text($derived, PP_REFLECTED_NAME_MAX),
        _pp_font_family_message('A derived family')
    );
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
 * Accepts: a non-negative number with a time unit (ms, s).
 *
 * v2: the number body is now the shared hardened one (_pp_css_number_body()),
 * so `1.2.3s` is refused here exactly as it is everywhere else, and the unit
 * must be DIRECTLY attached. The old body was `[\d.]+\s*(ms|s)`, which accepted
 * both malformed shapes — including `1.5 s`, the very whitespace form
 * `_pp_validate_length()` has rejected since #151. Time units are their own
 * closed set (a duration is not a length), but the NUMBER is the same number.
 *
 * REACH OF THE NARROWING, enumerated before it shipped: no component schema
 * declares a `duration`-typed style slot, no design token is `duration`-typed,
 * and no shipped value anywhere carries a space before a time unit. The theme's
 * `--transition: 150ms ease` is type `raw` and never reaches this validator.
 * Zero shipped values are newly rejected.
 */
function _pp_validate_duration(string $value): bool {
    return (bool) preg_match('/^' . _pp_css_number_body() . '(?:ms|s)$/', $value);
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
 * Validates a CSS `transition-timing-function` value (v2 UDC motion group).
 *
 * Three forms, and nothing else:
 *
 *   keyword        linear | ease | ease-in | ease-out | ease-in-out
 *                  | step-start | step-end
 *   cubic-bezier   four numbers. x1 and x2 are constrained to [0,1] BECAUSE CSS
 *                  constrains them — a control point outside that range makes the
 *                  curve non-monotonic in time and the whole declaration invalid,
 *                  so accepting it would store a value that paints nothing. y1
 *                  and y2 are deliberately UNBOUNDED and SIGNED: that is what
 *                  gives the overshoot and anticipation easings people actually
 *                  reach for (`cubic-bezier(.34,1.56,.64,1)`), and the shared
 *                  number body is unsigned, so the sign is added here rather than
 *                  quietly losing half the useful curve space.
 *   steps          a positive integer, optionally a jump keyword. All six CSS
 *                  keywords are accepted; `jump-none` additionally requires two
 *                  or more steps, which is CSS's own rule (with one step there is
 *                  no interior jump to omit).
 *
 * `linear()` with a stop list, and the `steps()` alias `step-start`/`step-end`
 * written as `steps(1, start)` are NOT special-cased: the first is a newer
 * function this ruling does not cover, the second already parses as a plain
 * `steps()`.
 */
function _pp_validate_timing_function(string $value): bool {
    $value = trim($value);

    // CASE-SENSITIVE, deliberately. CSS itself is case-insensitive here, but
    // every grammar in this file is case-sensitive — including `duration`, this
    // param's own sibling in the motion group, where `150MS` is refused. Two
    // params written side by side in one group must not disagree about whether
    // the value is folded, and the fix that keeps the file coherent is to match
    // the file rather than to widen one param.
    static $keywords = [
        'linear', 'ease', 'ease-in', 'ease-out', 'ease-in-out', 'step-start', 'step-end',
    ];
    if (in_array($value, $keywords, true)) {
        return true;
    }

    // BOUND THE TEXT, NOT JUST THE VALUE. Every number here is emitted VERBATIM
    // into an inline <style> block — the author's digits, not the number we
    // parsed — and nothing downstream trims it. A numeric range test does not
    // bound text: `cubic-bezier(0.<2000 zeros>,0,0,0)` is numerically 0.0 and
    // sails through a [0,1] check while carrying two kilobytes into every render
    // of the page. Same trap one line down for `steps()`, where `(int)` discards
    // leading zeros before any bound can see them. So the LENGTH is capped in the
    // pattern itself, which is the only place that sees what will actually be
    // printed. Four integer digits and six fractional ones are far past any real
    // easing curve.
    $number = '-?(?:\d{1,4}(?:\.\d{1,6})?|\.\d{1,6})';
    $ws     = '\s*';

    $bezier = '/^cubic-bezier\(' . $ws
        . '(' . $number . ')' . $ws . ',' . $ws
        . '(' . $number . ')' . $ws . ',' . $ws
        . '(' . $number . ')' . $ws . ',' . $ws
        . '(' . $number . ')' . $ws . '\)$/';
    if (preg_match($bezier, $value, $m)) {
        foreach ([1, 3] as $x) {
            $point = (float) $m[$x];
            if ($point < 0.0 || $point > 1.0) {
                return false;
            }
        }
        return true;
    }

    // `\d{1,4}` rather than `\d+`: the count is emitted verbatim too, and a
    // numeric bound alone does not stop `steps(0000…0005)` — the cast throws the
    // leading zeros away before any range test can see them, so a two-kilobyte
    // literal reaches the stylesheet as a perfectly legal `5`.
    $steps = '/^steps\(' . $ws . '(\d{1,4})' . $ws
        . '(?:,' . $ws . '(jump-start|jump-end|jump-none|jump-both|start|end)' . $ws . ')?\)$/';
    if (preg_match($steps, $value, $m)) {
        $count = (int) $m[1];
        if ($count < 1) {
            return false;
        }
        // 1000 is far past any real step animation (sprite sheets live in the
        // tens); the pattern already bounds the TEXT, this bounds the meaning.
        if ($count > 1000) {
            return false;
        }
        if ($count < 2 && isset($m[2]) && $m[2] === 'jump-none') {
            return false;
        }
        return true;
    }

    return false;
}

/**
 * Validates a CSS box-shadow value for the bounded `shadow` slot type.
 *
 * Accepts ONE of:
 *  - A preset reference from the exact allowlist: var(--shadow-none|sm|md|lg),
 *    or the bare keyword `none`.
 *  - A single-layer box-shadow: 2-4 length values (offset-x offset-y [blur]
 *    [spread]) followed by a color. Offsets may be negative; blur and spread
 *    must be non-negative. Lengths are unitless 0 or a number with any unit in
 *    pp_css_length_units() (no percentages — box-shadow takes <length>). The color must
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
        // v2: the unit set is now the shared one, not this validator's private
        // `px|rem` pair, and the number body is the shared hardened one — so
        // `1.2.3px` is refused here exactly as it is as a `length` (it used to
        // be ACCEPTED, through the loose `[\d.]+` body this replaces).
        //
        // The two options that stay FALSE are per-property facts of CSS, not
        // leftovers of the old divergence: box-shadow's lengths are <length>,
        // never <length-percentage> (`box-shadow: 0 50% ...` is invalid CSS), and
        // this grammar has never admitted calc()/clamp() — consolidating six unit
        // lists is the directive; handing shadow a function grammar it never had
        // would be inventing capability under cover of a cleanup.
        //
        // Offsets (positions 0,1) may be negative; blur and spread (2,3) must not
        // — a negative blur is invalid CSS, the same non-negative discipline the
        // ratio validator applies to its denominator.
        if (!_pp_css_length($len, [
            'signed'    => $i < 2,
            'percent'   => false,
            'functions' => false,
        ])) {
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

    // Stop position: a single <length-percentage>.
    //
    // v2: NEGATIVE STOPS ARE NOW ACCEPTED. The old pattern had no `-?` — a
    // private quirk, not a CSS fact: `linear-gradient(red -20%, blue)` is valid
    // CSS and a standard way to push a stop off the painted box so the visible
    // ramp starts mid-transition. It was refused here while the very same value
    // was accepted as a `length`, which is exactly the unpredictable divergence
    // the consolidation exists to end. The unit set and the number body are now
    // the shared ones too, so `5vmin` and `3ch` work here as they do everywhere.
    // calc()/clamp() stay out, as they always have been for this family.
    return _pp_css_length($position, [
        'signed'    => true,
        'percent'   => true,
        'functions' => false,
    ]);
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
        // <length-percentage> (#301).
        //
        // v2: LENGTHS ARE NOW ACCEPTED HERE. This clause used to take
        // percentages and nothing else, so `radial-gradient(at 10px 20px, ...)`
        // was refused while `background-position: 10px 20px` — the same
        // placement concept, one validator away — was fine. That was the
        // narrowest of the six divergent lists and the least defensible; it is
        // now the shared position-token grammar, embedded as one fragment so
        // there is no second copy to drift. Radial SIZE keywords
        // (`closest-side`) stay out: that is a bounded-grammar choice about
        // which CSS features this validator covers, not a unit-list quirk.
        // Anchored, no nested quantifiers (no catastrophic-backtracking surface).
        $pos = '(?:center|top|bottom|left|right|' . _pp_css_length_fragment(true, true) . ')';
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
        // v2: the unit set is now the shared one — this validator used to be the
        // only length-bearing grammar in the file WITHOUT `vw`/`vh`, so
        // `--hero-image-position: 20vw 30vh` was refused while every sibling
        // accepted viewport units. The number body is the shared hardened one
        // too, so `1.2.3px` is refused here (the loose `[\d.]+` body used to
        // ACCEPT it and persist it as broken CSS). calc()/clamp() stay out, as
        // they always have been for this family.
        if (_pp_css_length($token, [
            'signed'    => true,
            'percent'   => true,
            'functions' => false,
        ])) {
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
 * ── TYPED KEYWORD SETS (v2, BUILD-SPEC §3.3) ────────────────────────────────
 *
 * §3.3 asks for "keywords per-property (typed keyword sets)". Each set below is
 * a closed vocabulary, matched case-insensitively, in the same shape as the
 * `align` and `text-transform` validators that preceded them — never a raw
 * keyword passthrough, so a value the browser would drop cannot persist.
 *
 * They exist because the UDC reaches properties v1's slot catalogue never did.
 * `font-style` is the plainest example: #901 could not express a serif-ITALIC
 * pull-quote at all, because no slot anywhere in the theme reached font-style.
 *
 * Every one of these lives HERE, beside its siblings, and is dispatched by the
 * ONE type switch below — the repo's standing rule that validation lives in the
 * shared engines and no surface adds a second validator.
 */
function _pp_validate_font_style(string $value): bool {
    return in_array(strtolower(trim($value)), ['normal', 'italic', 'oblique'], true);
}

/**
 * font-weight: the four general-purpose keywords, or a numeric weight.
 *
 * CSS accepts any number 1-1000, but the closed 100..900 ladder is the whole
 * usable range for real font families and keeps the value set predictable for an
 * authoring model. `normal`/`bold` are kept because they are what an author
 * writes when they mean "put it back".
 */
function _pp_validate_font_weight(string $value): bool {
    $value = strtolower(trim($value));
    if (in_array($value, ['normal', 'bold', 'lighter', 'bolder'], true)) {
        return true;
    }
    return in_array($value, ['100', '200', '300', '400', '500', '600', '700', '800', '900'], true);
}

/**
 * line-height: the keyword `normal`, a unitless ratio, or a length/percentage.
 *
 * The unitless form is the one that inherits correctly through nested type, so
 * it is the form the docs steer to — but a length is legal CSS and refusing it
 * would be this engine inventing a constraint CSS does not have.
 */
function _pp_validate_line_height(string $value): bool {
    $value = trim($value);
    if (strtolower($value) === 'normal') {
        return true;
    }
    if (_pp_validate_number($value)) {
        return true;
    }
    return _pp_css_length($value, ['signed' => false, 'percent' => true, 'functions' => true]);
}

/** text-wrap: the closed set of wrapping behaviours. */
function _pp_validate_text_wrap(string $value): bool {
    return in_array(strtolower(trim($value)), ['wrap', 'nowrap', 'balance', 'pretty', 'stable'], true);
}

/**
 * text-decoration-line: ONE keyword.
 *
 * CSS allows combinations (`underline overline`); Sprint 0 accepts a single
 * keyword and the AI-facing docs SAY SO, rather than leaving the model to
 * discover the limit from a refusal. Widening later costs nothing; a silently
 * half-supported combination grammar would cost trust.
 */
function _pp_validate_text_decoration_line(string $value): bool {
    return in_array(strtolower(trim($value)), ['none', 'underline', 'overline', 'line-through'], true);
}

/** border-style: the closed CSS line-style set. */
function _pp_validate_border_style(string $value): bool {
    return in_array(strtolower(trim($value)), [
        'none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset',
    ], true);
}

/**
 * background-size: `cover`, `contain`, or 1-2 length/percentage/`auto` tokens.
 */
function _pp_validate_background_size(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    if (in_array(strtolower($value), ['cover', 'contain', 'auto'], true)) {
        return true;
    }
    $tokens = preg_split('/\s+/', $value);
    if (count($tokens) > 2) {
        return false;
    }
    foreach ($tokens as $token) {
        if (strtolower($token) === 'auto') {
            continue;
        }
        if (!_pp_css_length($token, ['signed' => false, 'percent' => true, 'functions' => false])) {
            return false;
        }
    }
    return true;
}

/** background-repeat: the closed single-keyword set. */
function _pp_validate_background_repeat(string $value): bool {
    return in_array(strtolower(trim($value)), [
        'repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'space', 'round',
    ], true);
}

/**
 * The ONE reject set for a CSS value, shared by the write engine and the render
 * boundary (issue #579, A-33).
 *
 * Before this existed there were TWO sets and they disagreed. The write engine
 * rejected `{};<>`; the #330 render boundary (pp_render_style_value_allowed,
 * lib/wp.php) additionally rejected a backslash, control characters, `url(`,
 * `expression(` and `@import`. Anything in the gap VALIDATED at write, PERSISTED,
 * and was then DROPPED at render — the reported-success-without-effect class, with
 * a sibling-destroying twist: `--stats-number-font: "serif /*"` cleared write
 * validation and then commented out the rest of the inline style attribute, so the
 * band silently lost its number colour AND its background image while
 * `.stats--has-bg-image` still painted the scrim over nothing.
 *
 * The convergence rule (ruling 6 of the #570 decision record): anywhere the
 * write-accept set and the render-reject set diverge, the value is REJECTED at
 * write. So the write engine adopts the render boundary's class verbatim, and BOTH
 * additionally reject the CSS comment delimiters `/ *` and `* /` — the construct
 * that made the stats defect destructive rather than merely inert.
 *
 * Returns the REASON (so the write engine can name it in an error message) or null
 * when the value carries none of these constructs. Two callers, one set:
 *
 *     _pp_validate_token_value()          write path  -> named WP_Error
 *     pp_render_style_value_allowed()     render path -> drop the declaration
 *
 * TOKEN-SURFACE REACH, enumerated before this widened (#579 acceptance criterion):
 * this runs ahead of the type switch, so it also governs DESIGN TOKENS, whose six
 * reachable types are color, length, font-family, number, shadow and raw. Five of
 * the six already reject every newly-added construct through their own grammar
 * (color/length/number are closed literal grammars, `shadow` documents "no url()",
 * and `length`'s calc/clamp body rejects any alpha run that is not a unit word).
 * The two surfaces the widening actually reaches are `font-family` — the hole this
 * closes, whose validator accepts any comma-separated text — and `raw`, whose only
 * shipped token is `--transition: 150ms ease`, a duration-plus-easing grammar with
 * no legitimate use for a backslash, a comment delimiter, url(), expression() or
 * @import. No shipped token value and no shipped slot default is newly rejected.
 *
 * `*` alone stays legal: `calc(4rem * 2 / 3)` never produces the `* /` adjacency,
 * which only appears in CSS that is already malformed.
 *
 * @param  string      $value  The candidate CSS value.
 * @return string|null         Reason fragment for an error message, or null when clean.
 */
function _pp_forbidden_css_construct(string $value): ?string {
    // Char class: { } ; < >, a literal backslash, and control chars 0x00-0x1F/0x7F.
    //
    // THE `;` REJECT IS LOAD-BEARING BEYOND DECLARATION-SPLITTING, and the coupling
    // is easy to break by accident, so it is recorded here. The sink for an accepted
    // value is esc_attr() (pp_render_style_vars, lib/wp.php), i.e. htmlspecialchars()
    // with double_encode=false — so an HTML entity ALREADY PRESENT in a stored value
    // is passed through verbatim and decoded by the browser inside the style
    // attribute. `&#47;&#42;` would decode to `/*` after the raw-byte comment check
    // below has already looked and found nothing. It is rejected anyway, because a
    // numeric entity that esc_attr declines to re-encode must terminate in `;` — and
    // `;` is in this class. The entity forms WITHOUT the terminator (`&#47&#42`) get
    // re-encoded by esc_attr into inert `&amp;#47&amp;#42`, so both halves are
    // covered, but only jointly.
    //
    // Consequence: do not drop `;` from this class, and do not move an accepted value
    // to a sink that decodes entities, without replacing this coverage.
    // WriteRenderGrammarTest::testEntityEncodedCommentDelimitersAreRejected pins it.
    if (preg_match('/[{};<>\\\\\x00-\x1f\x7f]/', $value)) {
        return 'must not contain {, }, ;, <, >, a backslash, or control characters';
    }
    // CSS comment delimiters — an open comment swallows every sibling declaration
    // in the same inline style attribute.
    if (strpos($value, '/*') !== false || strpos($value, '*/') !== false) {
        return 'must not contain a CSS comment delimiter (/* or */)';
    }
    // Function/at-rule primitives: url(...), expression(...), @import.
    if (preg_match('/url\s*\(|expression\s*\(|@import/i', $value)) {
        return 'must not contain url(), expression(), or @import';
    }
    return null;
}

/**
 * Validates a token value based on its type.
 *
 * @return true|WP_Error
 */
function _pp_validate_token_value(string $value, ?string $type, ?array $allowed = null) {
    // Injection / dead-value check: the SHARED reject set, identical to the one the
    // #330 render boundary applies (issue #579, A-33 — one set, two callers).
    $forbidden = _pp_forbidden_css_construct($value);
    if ($forbidden !== null) {
        return new WP_Error('injection', 'Value ' . $forbidden . '.');
    }

    if ($value === '') {
        return new WP_Error('empty_value', 'Value must not be empty.');
    }

    if ($type === null) {
        return true; // No type metadata, generic validation only
    }

    switch ($type) {
        case 'enum':
            // Bounded keyword set (e.g. --section-inline-items-align: start|center).
            // The allowed values live on the slot definition, so a write-time caller
            // (composition validation, update_style) passes them here for strict
            // membership. The #330 render boundary calls WITHOUT $allowed: the value
            // is a plain keyword that already cleared the injection guard above and
            // was enforced against the set at write time, so it passes through (a
            // never-matched keyword renders as an invalid CSS value and is inert).
            if ($allowed !== null && !in_array($value, $allowed, true)) {
                return new WP_Error(
                    'invalid_enum',
                    sprintf('Value must be one of: %s.', implode(', ', $allowed))
                );
            }
            break;
        case 'color':
            if (!_pp_validate_color($value)) {
                return new WP_Error('invalid_color', 'Value must be a valid CSS color (hex, rgb(), rgba(), hsl(), hsla()), the keyword "transparent" or "currentColor", or a single reference to a registered color token, e.g. var(--color-accent) — no fallback or nesting. Named colors are not accepted.');
            }
            break;
        case 'length':
            if (!_pp_validate_length($value)) {
                return new WP_Error('invalid_length', sprintf('Value must be a number with a CSS unit (%s), unitless 0, or a clamp()/calc() expression.', pp_css_grammar_summary()));
            }
            break;
        case 'length-or-none':
            // The band-geometry width cap, for a slot whose DECLARED DEFAULT is the
            // keyword `none` — the third state the plain `length` grammar could not
            // express (issue #579, A-30). --stats-max-width was the first; #578 added
            // the four measure slots that are uncapped by default and must therefore be
            // restorable to that default: --hero-heading-measure, --section-heading-measure,
            // --cta-body-measure and --faq-body-measure.
            //
            // The RULE is "the declared default must be authorable", not "measure slots
            // get `none`". Every measure slot with a real length default
            // (--cta-heading-measure, --grid-heading-measure, --section-body-measure,
            // --embed-body-measure and the other routed heading measures) deliberately
            // stays plain `length`: they have no third state, and the shipped
            // friendly-error path steers "remove this cap" to `100%` for them. Do not
            // widen this type to a padding, radius or font-size slot without a decision —
            // lib/ai-context.php and ai-instructions/style-component.md both state
            // to the authoring AI that those types reject `none`.
            //
            // The `length` grammar plus the keyword `none`, which is what "remove
            // the cap" means in CSS and what this slot already declares as its
            // built-in default. Deliberately a distinct TYPE
            // rather than a per-slot flag: the type IS the grammar everywhere else
            // in this engine, it needs no new signature and no new definition key,
            // it is honored at the #330 render boundary for free (that boundary
            // delegates here), and the AI catalog advertises it without a second
            // source of truth.
            if (!_pp_validate_length($value, true)) {
                return new WP_Error('invalid_length', sprintf('Value must be the keyword "none" (no cap), a number with a CSS unit (%s), unitless 0, or a clamp()/calc() expression.', pp_css_grammar_summary()));
            }
            break;
        case 'font-family':
            if (!_pp_validate_font_family($value)) {
                return new WP_Error('invalid_font_family', _pp_font_family_message());
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
                return new WP_Error('invalid_shadow', sprintf('Value must be a shadow preset (var(--shadow-none|sm|md|lg) or none) or a single-layer box-shadow: 2-4 lengths (any CSS unit: %s, no percentages; blur/spread non-negative) followed by a color. No inset, multi-layer, or url().', implode(', ', pp_css_length_units())));
            }
            break;
        case 'gradient':
            if (!_pp_validate_color($value) && !_pp_validate_gradient($value)) {
                return new WP_Error('invalid_gradient', 'Value must be a valid CSS color (hex, rgb(), rgba(), hsl(), hsla(), "transparent"/"currentColor", or a single var(--token) reference to a registered color token) or a bounded linear-gradient()/radial-gradient() with 2+ color stops (var()/url()/env() inside a gradient and conic/repeating gradients are not accepted).');
            }
            break;
        case 'position':
            if (!_pp_validate_position($value)) {
                return new WP_Error('invalid_position', sprintf('Value must be 1-2 tokens: keywords (center, top, bottom, left, right) or lengths/percentages with any CSS unit (%s), e.g. 0, 20%%, 10px, -5rem. No functions or var().', pp_css_grammar_summary()));
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
        // ── v2 UDC typed keyword sets (BUILD-SPEC §3.3) ─────────────────────
        // Reached through the UDC engine's param definitions (lib/udc.php).
        // Declared here, on the ONE dispatcher, so the render boundary honours
        // them for free exactly as it does every type above — that boundary
        // delegates to this function and has no grammar of its own.
        case 'font-style':
            if (!_pp_validate_font_style($value)) {
                return new WP_Error('invalid_font_style', 'Value must be a font-style keyword: normal, italic, or oblique.');
            }
            break;
        case 'font-weight':
            if (!_pp_validate_font_weight($value)) {
                return new WP_Error('invalid_font_weight', 'Value must be a font-weight keyword (normal, bold, lighter, bolder) or a numeric weight from 100 to 900 in hundreds.');
            }
            break;
        case 'line-height':
            if (!_pp_validate_line_height($value)) {
                return new WP_Error('invalid_line_height', sprintf('Value must be the keyword "normal", a unitless ratio (e.g. 1.5 — the form that inherits correctly through nested type), or a length with a CSS unit (%s).', pp_css_grammar_summary()));
            }
            break;
        case 'text-wrap':
            if (!_pp_validate_text_wrap($value)) {
                return new WP_Error('invalid_text_wrap', 'Value must be a text-wrap keyword: wrap, nowrap, balance, pretty, or stable.');
            }
            break;
        case 'text-decoration-line':
            if (!_pp_validate_text_decoration_line($value)) {
                return new WP_Error('invalid_text_decoration_line', 'Value must be ONE text-decoration-line keyword: none, underline, overline, or line-through. Combinations like "underline overline" are not accepted.');
            }
            break;
        case 'border-style':
            if (!_pp_validate_border_style($value)) {
                return new WP_Error('invalid_border_style', 'Value must be a border-style keyword: none, hidden, solid, dashed, dotted, double, groove, ridge, inset, or outset.');
            }
            break;
        case 'background-size':
            if (!_pp_validate_background_size($value)) {
                return new WP_Error('invalid_background_size', sprintf('Value must be "cover", "contain", or 1-2 tokens of "auto" or a length/percentage with a CSS unit (%s).', pp_css_grammar_summary()));
            }
            break;
        case 'background-repeat':
            if (!_pp_validate_background_repeat($value)) {
                return new WP_Error('invalid_background_repeat', 'Value must be a background-repeat keyword: repeat, no-repeat, repeat-x, repeat-y, space, or round.');
            }
            break;
        case 'timing-function':
            if (!_pp_validate_timing_function($value)) {
                return new WP_Error('invalid_timing_function', 'Value must be a transition timing function: a keyword (linear, ease, ease-in, ease-out, ease-in-out, step-start, step-end), cubic-bezier() with four numbers whose 1st and 3rd are between 0 and 1 (the 2nd and 4th may be any number, including negative, which is what produces overshoot), or steps() with a positive integer (at most 1000) and an optional jump keyword (jump-start, jump-end, jump-none, jump-both, start, end); jump-none additionally needs two or more steps. Values are case-sensitive: write ease, not EASE.');
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
    'description' => 'Adds a web font URL (e.g. Google Fonts, Bunny Fonts) to the site. Max 5 fonts. Loading the stylesheet alone changes nothing visible — pass family (the CSS font-family name the stylesheet defines) with apply_to ("heading" | "body" | "both") to also point the matching --font-heading/--font-body design token(s) at it in the same call. Omit family and the result returns a best-effort family derived from the URL as a suggestion, without changing any token. A derived family must satisfy the same font-family grammar as an explicit one; when it does not, the call is refused as invalid_font_family naming the derived value, and the fix is to pass family explicitly (quoted if it carries punctuation or non-ASCII).',
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

        // THE DERIVED FAMILY GETS THE SAME GRAMMAR AS AN EXPLICIT ONE.
        //
        // Both branches call _pp_validate_font_family() on the SAME resolved
        // string, so there is no shape that is legal as a derived family and
        // illegal as an explicit one. That symmetry is the whole point: the apply
        // arm concatenates this value into `<family>, system-ui, sans-serif` and
        // writes it to a --font-heading/--font-body override, which functions.php
        // emits as CSS SOURCE TEXT in a `:root { … }` inline stylesheet. A
        // grammar that only guards the parameter the caller typed is not guarding
        // the sink; it is guarding one of the two doors into it.
        //
        // `parse_str()` URL-DECODES, which is why FILTER_VALIDATE_URL above is not
        // already this check: percent-encoded bytes are a perfectly valid URL and
        // arrive here decoded.
        //
        // NARROWING, disclosed: a URL whose `family=` parameter does not parse as a
        // font family name is now refused even when `apply_to` is omitted and no
        // token would have been written. One rule beats two — a check that fired
        // only under `apply_to` would put validate-time and apply-time back on
        // separate rules, which is the shape this change exists to remove. Every
        // Google Fonts and Bunny Fonts URL shape in this repo's code, tests and
        // docs still passes (css and css2, multi-family, ital/wght axes,
        // `+`-joined names); what refuses is a family name carrying punctuation or
        // non-ASCII outside quotes, and its legal rewrite is to pass `family`
        // explicitly, quoted.
        $resolved      = _pp_enqueue_font_family($params);
        $family        = $resolved['family'];
        $family_source = $resolved['source'];

        // ONE call site, not one per source. `$family !== ''` is exactly
        // `$family_source !== null` by _pp_enqueue_font_family()'s contract, so
        // this covers both doors and adds no case. Written as a single call
        // deliberately: two parallel branches would make the symmetry above a
        // property of two lines staying in step, which is the shape this change
        // exists to remove. Only the MESSAGE differs, because only the message
        // depends on whether the caller typed the value or the engine read it.
        if ($family !== '' && !_pp_validate_font_family($family)) {
            return new WP_Error(
                'invalid_font_family',
                $family_source === 'derived'
                    ? _pp_derived_font_family_message($family)
                    : _pp_font_family_message('family')
            );
        }

        $apply_to = $params['apply_to'] ?? '';
        if ($apply_to !== '') {
            if (!in_array($apply_to, ['heading', 'body', 'both'], true)) {
                return new WP_Error('invalid_apply_to', 'apply_to must be "heading", "body", or "both".');
            }
            // `$family === ''` is exactly the old two-part test (no `family` param
            // AND nothing derivable), read off the resolver instead of deriving a
            // second time here.
            if ($family === '') {
                return new WP_Error('missing_family', 'apply_to requires family — no family name could be derived from this URL, so pass one explicitly.');
            }
        }

        return true;
    },

    'preview' => function (array $params) {
        $current = pp_get_font_urls();
        $after = array_merge($current, [$params['url']]);
        $changes = [['action' => 'add', 'url' => $params['url']]];

        $family   = _pp_enqueue_font_family($params)['family'];
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

        $resolved      = _pp_enqueue_font_family($params);
        $family        = $resolved['family'];
        $family_source = $resolved['source'];

        $apply_to = $params['apply_to'] ?? '';
        // LAST-DITCH RE-CHECK, IMMEDIATELY BEFORE THE WRITE.
        //
        // Unreachable through pp_execute_apply(), which runs the validate arm
        // first (lib/apply.php, pp_execute_apply) — and that is the point. This
        // guard is for a FUTURE caller that reaches this closure by some other
        // route, and it sits here rather than anywhere earlier because the line it
        // is protecting is the next one: pp_set_token_override() is where a
        // font-family string stops being a parameter and becomes stored CSS.
        //
        // It SKIPS the token write rather than refusing the action, because by
        // this point pp_set_font_urls() above has already committed the URL. A
        // refusal here would report failure for a write that happened. Degrading
        // to "the font is enqueued, no token was pointed at it" is the honest
        // outcome. The validate arm remains the owner of REFUSING; this one only
        // declines to widen the damage.
        //
        // CLEARING `$family` REACHES THE ENVELOPE, deliberately. `$family` is read
        // twice below: by the token-write guard, and by the result assembly, which
        // omits BOTH `family` and `family_source` when it is empty. So the result
        // does not name a family at all rather than naming one it declined to use.
        // Stated here because it is a second effect of one assignment, and a later
        // edit that reorders these blocks would change what the caller is told.
        // `$apply_to` is deliberately NOT cleared: its only remaining read is the
        // guard on the next line, which an empty `$family` already closes.
        if ($apply_to !== '' && $family !== '' && !_pp_validate_font_family($family)) {
            $family        = '';
            $family_source = null;
        }

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
 * @return array|null  ['timestamp' => string, 'release_version' => ?string,
 *                       'theme_path' => string, 'file_hashes' => [relative => md5]]
 *                      release_version is absent on manifests written before #496.
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
        // Record the installed release the baseline was captured against (#496).
        // Drift then always means "changed since this release", never "stale
        // baseline of unknown vintage". Null only when PP_VERSION is somehow
        // undefined (never in a real WP-CLI runtime); the drift finding degrades
        // gracefully to a "predates version tracking" message in that case.
        'release_version' => defined('PP_VERSION') ? PP_VERSION : null,
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
