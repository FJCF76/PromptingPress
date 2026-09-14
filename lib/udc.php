<?php
/**
 * lib/udc.php — the Universal Design Contract engine (v2, BUILD-SPEC §3).
 *
 * ONE shared engine. Components contribute DATA (a `roles` block in their
 * schema); they contribute no styling code. The taxonomy below is the contract
 * every component rebuilt in a later sprint will declare against, so nothing in
 * this file may name a component.
 *
 * ── What replaced what ──────────────────────────────────────────────────────
 *
 * v1 gave each component a private, flat map of CSS custom properties ("style
 * slots", 261 of them across 12 components), rendered into an inline `style`
 * attribute on the band's own <section>. Three consequences the UDC exists to
 * end: the reachable design surface was whatever slots someone had thought to
 * add (testimonials had 27, and none of them reached the quote's family, style,
 * size or alignment — #901); inline styles beat every stylesheet, so slot
 * precedence was a cliff rather than a cascade; and nothing was expressible per
 * breakpoint or per state at all.
 *
 * ── The shape ───────────────────────────────────────────────────────────────
 *
 *   {"component":"testimonials", "id":"pp-3f9a1c2e", "props":{...},
 *    "udc":{
 *      "_tokens": {"quote-typography-size-d":"19px"},     <- band-local tokens
 *      "_band":  {"spacing":{"padding":{"top":"18px"}}},  <- the implicit root role
 *      "quote":  {"typography":{"family":"@font-serif",
 *                               "style":"italic",
 *                               "size":{"d":"19px","p":"17px"},
 *                               ":hover":{"color":"#000"}}}
 *    }}
 *
 * A ROLE is a named sub-element of the component mapped to a stable selector.
 * A GROUP is a family of related CSS properties. A PARAM is one property inside
 * a group. A value is a literal, an `@reference`, or a breakpoint-keyed map of
 * either. `:hover` is a STATE scope inside a group, not a breakpoint — hover
 * values may themselves be breakpoint-keyed.
 *
 * ── Emission ────────────────────────────────────────────────────────────────
 *
 *   [data-pp-band="pp-3f9a1c2e"]            { --pp-quote-typography-size-d: 19px; … }
 *   [data-pp-band="pp-3f9a1c2e"] .t__quote  { font-style: italic; font-size: var(…-d); }
 *   @media (max-width: 767px)  { … narrow-first … }
 *   @media (min-width: 768px) and (max-width: 1023px) { … }
 *   [data-pp-band="pp-3f9a1c2e"] .t__quote:hover { color: #000; }
 *
 * Every rule is exactly `[data-pp-band="<id>"]` plus the role's selector, so
 * specificity is flat BY CONSTRUCTION and `!important` is never needed or used.
 * No v2 component emits an inline style attribute.
 *
 *   site tokens ──▶ role defaults (schema data) ──▶ band udc ──▶ breakpoint ──▶ hover
 *        │                  │                          │             │           │
 *        └──────────────────┴──── all resolve here ────┴─────────────┴───────────┘
 *                                       │
 *                        pp_udc_resolve() — carries PROVENANCE, so a value that
 *                        cannot take effect is disclosed rather than silently
 *                        dropped (invariant I35).
 *
 * ── Why the breakpoints do not overlap ──────────────────────────────────────
 *
 * Desktop is the base, so overrides are max-width. Overlapping max-width blocks
 * would let a tablet value silently defeat a phone value at phone widths — a
 * declared authoring input cancelled by another mechanism, which is exactly what
 * I35 forbids. Non-overlapping ranges make that structurally impossible: every
 * declared breakpoint value has a viewport range where it is the winner. The
 * integers match the theme's own (assets/css uses min-width:768px, min-width:
 * 1024px and max-width:767px); introducing a second breakpoint semantics inside
 * one page would be the hidden divergence I36 forbids.
 */

// ── Registry: breakpoints ───────────────────────────────────────────────────

/**
 * The breakpoint dimension, as data.
 *
 * `d` is the base and carries NO media query, so it applies everywhere unless a
 * narrower block overrides it. `emit_order` is narrow-first per §3.4; because
 * the ranges are mutually exclusive the order cannot change which value wins,
 * which is the point.
 */
function pp_udc_breakpoints(): array {
    static $breakpoints = null;
    if ($breakpoints !== null) {
        return $breakpoints;
    }
    $breakpoints = [
        'd' => ['media' => null,                                          'label' => 'desktop', 'emit_order' => 2],
        't' => ['media' => '(min-width: 768px) and (max-width: 1023px)',  'label' => 'tablet',  'emit_order' => 1],
        'p' => ['media' => '(max-width: 767px)',                          'label' => 'phone',   'emit_order' => 0],
    ];
    return $breakpoints;
}

/** The breakpoints in emission order (narrow-first), sorted once per request. */
function pp_udc_breakpoints_in_emit_order(): array {
    static $ordered = null;
    if ($ordered === null) {
        $ordered = pp_udc_breakpoints();
        uasort($ordered, static fn($a, $b) => $a['emit_order'] <=> $b['emit_order']);
    }
    return $ordered;
}

/** The state scope key. A state is not a viewport; it nests inside a group. */
const PP_UDC_HOVER = ':hover';

/** Keys inside a `udc` map that are engine-owned rather than role names. */
function pp_udc_reserved_keys(): array {
    return ['_tokens'];
}

// ── Registry: groups and parameters ─────────────────────────────────────────

/**
 * The GROUP/PARAM taxonomy — the shared contract, as data.
 *
 * Each param declares:
 *   property    the CSS property it emits. Looked up HERE, never taken from
 *               author input, so an author can no more influence the property
 *               text than they can invent a role. (v1 emitted the author's own
 *               slot key as the property name, gated only by an isset() check.)
 *   type        the grammar, dispatched through the shared engine in lib/apply.php.
 *   signed      whether a negative length is accepted — §3.3's "signed where the
 *               property allows". A negative padding is inert CSS; a negative
 *               letter-spacing is ordinary typography.
 *   max_values  shorthand arity. Applied ONLY to the four families §3.3 names
 *               (padding, margin, border-width, border-radius) plus gap, because
 *               those genuinely are N-of-one-type. Nothing else gets a generic
 *               arity check: real CSS shorthands carry per-property ordering,
 *               slash syntax and mixed token types that an arity count models
 *               either too weakly or too strictly.
 *   keywords    extra bare keywords this param accepts beyond its type.
 */
function pp_udc_groups(): array {
    static $groups = null;
    if ($groups !== null) {
        return $groups;
    }

    $len  = static fn(string $prop, bool $signed = false, int $max = 1, array $kw = []): array =>
        ['property' => $prop, 'type' => 'length', 'signed' => $signed, 'max_values' => $max, 'keywords' => $kw];
    $typed = static fn(string $prop, string $type, int $max = 1, array $kw = []): array =>
        ['property' => $prop, 'type' => $type, 'signed' => true, 'max_values' => $max, 'keywords' => $kw];

    $groups = [
        'typography' => ['params' => [
            'family'         => $typed('font-family', 'font-family'),
            'size'           => $len('font-size'),
            'weight'         => $typed('font-weight', 'font-weight'),
            'style'          => $typed('font-style', 'font-style'),
            'line-height'    => $typed('line-height', 'line-height'),
            'letter-spacing' => $len('letter-spacing', true),
            'align'          => $typed('text-align', 'align'),
            'transform'      => $typed('text-transform', 'text-transform'),
            'decoration'     => $typed('text-decoration-line', 'text-decoration-line'),
            'wrap'           => $typed('text-wrap', 'text-wrap'),
            'color'          => $typed('color', 'color'),
        ]],
        'spacing' => ['params' => [
            'padding'        => $len('padding', false, 4),
            'padding-top'    => $len('padding-top'),
            'padding-right'  => $len('padding-right'),
            'padding-bottom' => $len('padding-bottom'),
            'padding-left'   => $len('padding-left'),
            'margin'         => $len('margin', true, 4, ['auto']),
            'margin-top'     => $len('margin-top', true, 1, ['auto']),
            'margin-right'   => $len('margin-right', true, 1, ['auto']),
            'margin-bottom'  => $len('margin-bottom', true, 1, ['auto']),
            'margin-left'    => $len('margin-left', true, 1, ['auto']),
            'gap'            => $len('gap', false, 2),
            'row-gap'        => $len('row-gap'),
            'column-gap'     => $len('column-gap'),
        ]],
        'border' => ['params' => [
            'width'               => $len('border-width', false, 4),
            'width-top'           => $len('border-top-width'),
            'width-right'         => $len('border-right-width'),
            'width-bottom'        => $len('border-bottom-width'),
            'width-left'          => $len('border-left-width'),
            'style'               => $typed('border-style', 'border-style', 4),
            'style-top'           => $typed('border-top-style', 'border-style'),
            'style-right'         => $typed('border-right-style', 'border-style'),
            'style-bottom'        => $typed('border-bottom-style', 'border-style'),
            'style-left'          => $typed('border-left-style', 'border-style'),
            'color'               => $typed('border-color', 'color'),
            'color-top'           => $typed('border-top-color', 'color'),
            'color-right'         => $typed('border-right-color', 'color'),
            'color-bottom'        => $typed('border-bottom-color', 'color'),
            'color-left'          => $typed('border-left-color', 'color'),
            // Lengths only: the elliptical `10px / 20px` form is NOT accepted in
            // Sprint 0, and the AI-facing docs say so rather than leaving the
            // model to discover it from a refusal.
            'radius'              => $len('border-radius', false, 4),
            'radius-top-left'     => $len('border-top-left-radius'),
            'radius-top-right'    => $len('border-top-right-radius'),
            'radius-bottom-right' => $len('border-bottom-right-radius'),
            'radius-bottom-left'  => $len('border-bottom-left-radius'),
        ]],
        'shadow' => ['params' => [
            'box' => $typed('box-shadow', 'shadow'),
        ]],
        'background' => ['params' => [
            // `background` (the shorthand) legitimately takes either a color or
            // a gradient image, which is exactly what the `gradient` type means
            // and exactly the shape v1's proven `--<component>-bg` slot had.
            'fill'     => $typed('background', 'gradient'),
            'position' => $typed('background-position', 'position'),
            'size'     => $typed('background-size', 'background-size'),
            'repeat'   => $typed('background-repeat', 'background-repeat'),
        ]],
        'sizing' => ['params' => [
            'width'      => $len('width'),
            'height'     => $len('height'),
            'min-width'  => $len('min-width'),
            'min-height' => $len('min-height'),
            // The width caps carry `length-or-none`, the one length family where
            // `none` is a real input and the declared default (#579, A-30).
            'max-width'  => ['property' => 'max-width',  'type' => 'length-or-none', 'signed' => false, 'max_values' => 1, 'keywords' => []],
            'max-height' => ['property' => 'max-height', 'type' => 'length-or-none', 'signed' => false, 'max_values' => 1, 'keywords' => []],
        ]],
    ];
    return $groups;
}

/**
 * A component's declared roles, from `schema.roles`.
 *
 * `_band` is the implicit root role: every component that declares any role has
 * it, its selector is the band element itself, and it permits every group the
 * component permits anywhere. A component with no `roles` block is a LEGACY
 * component and this returns [] — which is how the two styling systems coexist
 * until each component's own rebuild sprint.
 */
function pp_udc_component_roles(string $component): array {
    $registered = pp_get_registered_components();
    $roles      = $registered[$component]['roles'] ?? [];
    return is_array($roles) ? $roles : [];
}

/** True when a component has been rebuilt onto the UDC. */
function pp_udc_is_v2_component(string $component): bool {
    return pp_udc_component_roles($component) !== [];
}

// ── References ──────────────────────────────────────────────────────────────

/**
 * Parses an `@name` reference. Returns the bare name, or null for a literal.
 *
 * NOTE the deliberate asymmetry with v1: an author never writes CSS function
 * syntax here. v1 made `length` literal-only precisely because `var()` in a
 * length was an injection-bypass surface; `@name` is not CSS at all — the engine
 * resolves it, checks that the target exists, checks that the TARGET'S value
 * satisfies this param's grammar, and only then emits a `var()` of its own
 * construction. So a length may now FOLLOW a token, which v1 could not express,
 * without any author text reaching the stylesheet.
 */
function pp_udc_parse_reference($value): ?string {
    if (!is_string($value) || $value === '' || $value[0] !== '@') {
        return null;
    }
    return substr($value, 1);
}

/**
 * Every custom property declared on a `:root` in the theme's base stylesheet.
 *
 * WIDER THAN pp_design_tokens() ON PURPOSE. That registry reads the FIRST
 * `:root` block only — the authorable design tokens — because those are what
 * `update_design_token` may write. The shared design-system properties live in a
 * second block that base.css explicitly keeps out of it ("not authored via
 * update_design_token"): `--pp-band-padding`, `--pp-band-padding-adjacent-top`
 * and `--pp-band-heading-size`, the site-level rhythm and heading scale that
 * EVERY band shares and that #431/#430/#436 ruled must stay one definition.
 *
 * A v2 component still has to participate in those shared contracts, and it can
 * only do that by REFERENCING them — a copied literal would stop tracking the
 * token the moment someone retunes it, which is the drift those rulings exist to
 * prevent. So role defaults resolve against this wider set; author values do not
 * (see pp_udc_resolve_reference()'s $allow_shared argument), which keeps the
 * authorable surface exactly where it was.
 */
function pp_udc_shared_site_properties(): array {
    static $props = null;
    if ($props !== null) {
        return $props;
    }
    $props = [];
    $file  = get_template_directory() . '/assets/css/base.css';
    if (!file_exists($file)) {
        return $props;
    }
    $css = file_get_contents($file);
    if (preg_match_all('/:root\s*\{([^}]*)\}/s', $css, $blocks)) {
        foreach ($blocks[1] as $block) {
            preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $block, $decls, PREG_SET_ORDER);
            foreach ($decls as $decl) {
                $name = trim($decl[1]);
                if (!isset($props[$name])) {
                    $props[$name] = trim($decl[2]);
                }
            }
        }
    }
    return $props;
}

/**
 * Resolves an `@name` reference to `['value' => …, 'css' => …]`, or null.
 *
 * Precedence is band `_tokens` first, then site design tokens (§3.1). A name
 * that resolves to neither returns null and the caller REFUSES — it is never
 * mapped to an empty value or to the literal text (invariant I9: a failed read
 * is never mapped to a valid answer).
 */
function pp_udc_resolve_reference(string $name, array $band_tokens, bool $allow_shared = false): ?array {
    if (isset($band_tokens[$name]) && is_scalar($band_tokens[$name])) {
        return [
            'value' => (string) $band_tokens[$name],
            'css'   => 'var(--pp-' . $name . ')',
            'scope' => 'band',
        ];
    }
    $site = pp_design_tokens();
    $key  = '--' . $name;
    if (isset($site[$key]['value'])) {
        return [
            'value' => (string) $site[$key]['value'],
            'css'   => 'var(' . $key . ')',
            'scope' => 'site',
        ];
    }
    // Schema defaults only: the shared design-system properties (band rhythm,
    // heading scale) that base.css deliberately keeps out of the authorable
    // registry. A component must be able to REFERENCE them to stay in the shared
    // contracts; an author still cannot, so nothing about the authorable surface
    // moves.
    if ($allow_shared) {
        $shared = pp_udc_shared_site_properties();
        if (isset($shared[$key])) {
            return [
                'value' => (string) $shared[$key],
                'css'   => 'var(' . $key . ')',
                'scope' => 'shared',
            ];
        }
    }
    return null;
}

// ── Value validation ────────────────────────────────────────────────────────

/**
 * Validates ONE resolved value against a param definition.
 *
 * Runs the shared injection gate first, exactly as _pp_validate_token_value()
 * does, then dispatches: length-family params go to the shared dimension core
 * with this param's own `signed` option, everything else to the shared type
 * switch. There is no second grammar here — only the shared engine, called with
 * the per-property options §3.3 asks for.
 *
 * @return true|WP_Error
 */
function pp_udc_validate_value(string $value, array $param) {
    $forbidden = _pp_forbidden_css_construct($value);
    if ($forbidden !== null) {
        return new WP_Error('injection', 'Value ' . $forbidden . '.');
    }
    // THE DELIMITER GATE BELONGS HERE, NOT ONLY ON `_tokens`.
    //
    // It used to be called from exactly one place — the `_tokens` loop in
    // pp_udc_validate_map() — which left every AUTHORED value outside it. That
    // was not a gap in one branch; it was the gate wired to the narrower of its
    // two inputs. `rgb(` submitted as a `typography.family` cleared the reject
    // set (no `{ } ; < >` in it) and cleared the family grammar (which accepted
    // any non-empty string), reached storage, and emitted as CSS source text —
    // where an unbalanced `(` is OPEN and swallows the terminating `;`, the
    // band's closing `}`, and every rule after it to the end of the layer. The
    // worked example in pp_udc_compile_band() describes exactly that attack and
    // asserts the parameter's grammar closes it; for `font-family` it did not.
    //
    // One call here covers all three doors, because they are all this function:
    // the write path (via _pp_udc_validate_scalar), the emit-time
    // re-validation of stored author data (_pp_udc_place), and the emit-time
    // check of a band token against its referencing parameter's grammar.
    //
    // The `_tokens` loop KEEPS its own call. An UNREFERENCED token is never
    // type-checked through any parameter — §3.1 makes it a lint warning, not a
    // refusal — so no grammar, and therefore no gate, reaches it here.
    if (!_pp_udc_delimiters_balanced($value)) {
        return new WP_Error('unbalanced_delimiters', _pp_udc_unbalanced_message());
    }
    if (trim($value) === '') {
        return new WP_Error('empty_value', 'Value must not be empty.');
    }

    $max      = $param['max_values'] ?? 1;
    $keywords = $param['keywords'] ?? [];
    $tokens   = $max > 1 ? preg_split('/\s+/', trim($value)) : [trim($value)];

    if (count($tokens) > $max) {
        return new WP_Error('invalid_udc_value', sprintf(
            'Value takes at most %d space-separated values; got %d.',
            $max,
            count($tokens)
        ));
    }

    foreach ($tokens as $token) {
        if ($keywords !== [] && in_array(strtolower($token), $keywords, true)) {
            continue;
        }
        $type = $param['type'];
        if ($type === 'length' || $type === 'length-or-none') {
            if ($type === 'length-or-none' && strtolower($token) === 'none') {
                continue;
            }
            if (_pp_css_length($token, [
                'signed'    => $param['signed'] ?? false,
                'percent'   => true,
                'functions' => true,
            ])) {
                continue;
            }
            $hint = ($param['signed'] ?? false)
                ? ''
                : ' Negative values are not accepted for this property.';
            return new WP_Error('invalid_length', sprintf(
                'Value must be a number with a CSS unit (%s), unitless 0, or a clamp()/calc() expression.%s',
                pp_css_grammar_summary(),
                $hint
            ));
        }
        $result = _pp_validate_token_value($token, $type);
        if ($result !== true) {
            return $result;
        }
    }
    return true;
}

// ── Map validation (the write gate) ─────────────────────────────────────────

/**
 * Validates a band's whole `udc` map.
 *
 * Returns the FIRST problem as a WP_Error whose message opens `Component "<n>"`
 * so lib/admin.php's band-locator rewriting can insert the band number, matching
 * every other composition refusal. Returns null when the map is clean.
 *
 * THIS GATE IS LOAD-BEARING IN A WAY THAT IS EASY TO MISS: before v2, a
 * composition item carrying an unrecognised top-level key was accepted, stored
 * and ignored — nothing in the validator ever iterated the item's own keys. A
 * `udc` map with a misspelled role would therefore have returned ok:true and
 * rendered nothing, which is the reported-success-without-effect class the #147
 * and #643 gates exist to close one level down.
 *
 * @return WP_Error|null
 */
function pp_udc_validate_map($udc, string $component): ?WP_Error {
    if (!is_array($udc)) {
        return new WP_Error('invalid_prop_value', sprintf(
            'Component "%s" udc must be an object of roles; got %s.',
            $component,
            _pp_schema_value_for_message($udc)
        ));
    }

    $roles  = pp_udc_component_roles($component);
    $groups = pp_udc_groups();

    if ($roles === []) {
        return new WP_Error('unknown_udc_role', sprintf(
            'Component "%s" is not on the UDC styling system, so it accepts no "udc" map. '
            . 'Style it through its declared style slots instead.',
            $component
        ));
    }

    $band_tokens = [];
    if (array_key_exists('_tokens', $udc)) {
        if (!is_array($udc['_tokens'])) {
            return new WP_Error('invalid_prop_value', sprintf(
                'Component "%s" udc "_tokens" must be an object of name => value; got %s.',
                $component,
                _pp_schema_value_for_message($udc['_tokens'])
            ));
        }
        foreach ($udc['_tokens'] as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $name)) {
                return new WP_Error('invalid_prop_value', sprintf(
                    'Component "%s" udc "_tokens" name %s must be 1-64 characters of letters, digits, hyphen or underscore.',
                    $component,
                    _pp_render_undeclared_prop_keys([(string) $name])
                ));
            }
            if (!is_scalar($value)) {
                return new WP_Error('invalid_prop_value', sprintf(
                    'Component "%s" udc token "%s" must be a scalar value; got %s.',
                    $component,
                    $name,
                    _pp_schema_value_for_message($value)
                ));
            }
            // A band token may not itself be a reference. One level, no cycles
            // to detect, nothing to unwind — and the site-token chain keeps its
            // existing single owner (_pp_token_reference_cycle).
            if (pp_udc_parse_reference((string) $value) !== null) {
                return new WP_Error('invalid_prop_value', sprintf(
                    'Component "%s" udc token "%s" must be a literal value, not a reference to another token.',
                    $component,
                    $name
                ));
            }
            // Gated here whether or not anything references it. A token is only
            // type-checked through the parameter that uses it, so an UNREFERENCED
            // token — or one whose only reference a later item-scoped edit removes
            // — would otherwise be stored with an arbitrary value and the storage
            // layer would sit one gate short of what the emitter assumes.
            $forbidden = _pp_forbidden_css_construct((string) $value);
            if ($forbidden !== null) {
                return new WP_Error('invalid_prop_value', sprintf(
                    'Component "%s" udc token "%s": value %s.',
                    $component,
                    $name,
                    $forbidden
                ));
            }
            if (_pp_udc_is_mint_shaped_name((string) $name)
                && !_pp_udc_name_is_the_engines_own_mint((string) $name, $udc)) {
                return new WP_Error('invalid_prop_value', sprintf(
                    'Component "%s" udc token "%s" uses a name the engine mints for itself '
                    . '(<role>-<group>-<param>[-hover]-<breakpoint>). Pick another name: a '
                    . 'collision would have the engine overwrite the value you declared.',
                    $component,
                    $name
                ));
            }
            if (!_pp_udc_delimiters_balanced((string) $value)) {
                return new WP_Error('invalid_prop_value', sprintf(
                    'Component "%s" udc token "%s" %s',
                    $component,
                    $name,
                    _pp_udc_unbalanced_message('value')
                ));
            }
            $band_tokens[$name] = (string) $value;
        }
    }

    foreach ($udc as $role_name => $role_map) {
        if (in_array($role_name, pp_udc_reserved_keys(), true)) {
            continue;
        }
        if (!isset($roles[$role_name])) {
            return new WP_Error('unknown_udc_role', sprintf(
                'Component "%s" has no UDC role %s. Available roles: %s',
                $component,
                _pp_render_undeclared_prop_keys([(string) $role_name]),
                implode(', ', array_keys($roles)) ?: '(none)'
            ));
        }
        if (!is_array($role_map)) {
            return new WP_Error('invalid_prop_value', sprintf(
                'Component "%s" udc role "%s" must be an object of groups; got %s.',
                $component,
                $role_name,
                _pp_schema_value_for_message($role_map)
            ));
        }

        $permitted = $roles[$role_name]['groups'] ?? [];
        foreach ($role_map as $group_name => $group_map) {
            if (!isset($groups[$group_name])) {
                return new WP_Error('unknown_udc_group', sprintf(
                    'Component "%s" role "%s" names the UDC group %s, which does not exist. Available groups: %s',
                    $component,
                    $role_name,
                    _pp_render_undeclared_prop_keys([(string) $group_name]),
                    implode(', ', array_keys($groups))
                ));
            }
            if (!in_array($group_name, $permitted, true)) {
                return new WP_Error('unknown_udc_group', sprintf(
                    'Component "%s" role "%s" does not permit the UDC group "%s". Permitted groups: %s',
                    $component,
                    $role_name,
                    $group_name,
                    implode(', ', $permitted) ?: '(none)'
                ));
            }
            if (!is_array($group_map)) {
                return new WP_Error('invalid_prop_value', sprintf(
                    'Component "%s" role "%s" group "%s" must be an object of parameters; got %s.',
                    $component,
                    $role_name,
                    $group_name,
                    _pp_schema_value_for_message($group_map)
                ));
            }

            foreach ($group_map as $param_name => $param_value) {
                if ($param_name === PP_UDC_HOVER) {
                    if (!is_array($param_value)) {
                        return new WP_Error('invalid_prop_value', sprintf(
                            'Component "%s" role "%s" group "%s" ":hover" must be an object of parameters; got %s.',
                            $component,
                            $role_name,
                            $group_name,
                            _pp_schema_value_for_message($param_value)
                        ));
                    }
                    foreach ($param_value as $hover_param => $hover_value) {
                        $error = _pp_udc_validate_param(
                            $component, $role_name, $group_name, (string) $hover_param,
                            $hover_value, $groups[$group_name]['params'], $band_tokens, true
                        );
                        if ($error !== null) {
                            return $error;
                        }
                    }
                    continue;
                }
                $error = _pp_udc_validate_param(
                    $component, $role_name, $group_name, (string) $param_name,
                    $param_value, $groups[$group_name]['params'], $band_tokens, false
                );
                if ($error !== null) {
                    return $error;
                }
            }
        }
    }

    return null;
}

/**
 * Validates one param entry — the value may be a scalar, an `@reference`, or a
 * breakpoint-keyed map of either.
 *
 * @return WP_Error|null
 */
function _pp_udc_validate_param(
    string $component,
    string $role,
    string $group,
    string $param_name,
    $value,
    array $params,
    array $band_tokens,
    bool $in_hover
): ?WP_Error {
    $where = sprintf(
        'Component "%s" role "%s" group "%s"%s parameter "%s"',
        $component,
        $role,
        $group,
        $in_hover ? ' :hover' : '',
        $param_name
    );

    if (!isset($params[$param_name])) {
        return new WP_Error('invalid_prop_value', sprintf(
            'Component "%s" role "%s" group "%s"%s has no parameter %s. Available parameters: %s',
            $component,
            $role,
            $group,
            $in_hover ? ' :hover' : '',
            _pp_render_undeclared_prop_keys([$param_name]),
            implode(', ', array_keys($params))
        ));
    }
    $param = $params[$param_name];

    if (is_array($value)) {
        if ($value === []) {
            return new WP_Error('invalid_prop_value', $where . ' must carry at least one breakpoint value.');
        }
        $breakpoints = pp_udc_breakpoints();
        foreach ($value as $bp => $bp_value) {
            if (!isset($breakpoints[$bp])) {
                return new WP_Error('invalid_prop_value', sprintf(
                    '%s has no breakpoint %s. Available breakpoints: %s.',
                    $where,
                    _pp_render_undeclared_prop_keys([(string) $bp]),
                    implode(', ', array_keys($breakpoints))
                ));
            }
            $error = _pp_udc_validate_scalar($where, $bp_value, $param, $band_tokens);
            if ($error !== null) {
                return $error;
            }
        }
        return null;
    }

    return _pp_udc_validate_scalar($where, $value, $param, $band_tokens);
}

/** @return WP_Error|null */
function _pp_udc_validate_scalar(string $where, $value, array $param, array $band_tokens): ?WP_Error {
    if (!is_scalar($value)) {
        return new WP_Error('invalid_prop_value', sprintf(
            '%s must be a scalar value or a breakpoint-keyed object; got %s.',
            $where,
            _pp_schema_value_for_message($value)
        ));
    }
    $value = (string) $value;

    $ref = pp_udc_parse_reference($value);
    if ($ref !== null) {
        $resolved = pp_udc_resolve_reference($ref, $band_tokens);
        if ($resolved === null) {
            return new WP_Error('invalid_prop_value', sprintf(
                '%s references "@%s", which is not defined in this band\'s "_tokens" and is not a registered design token.',
                $where,
                $ref
            ));
        }
        // The REFERENCED value must satisfy this param's grammar. A reference to
        // a colour token from a length parameter resolves to "0.25rem"-class
        // nonsense the browser drops, which is the same accepted-but-dead class
        // the colour validator has rejected since #230.
        $check = pp_udc_validate_value($resolved['value'], $param);
        if ($check !== true) {
            return new WP_Error('invalid_prop_value', sprintf(
                '%s references "@%s", whose value "%s" is not valid here: %s',
                $where,
                $ref,
                $resolved['value'],
                $check->get_error_message()
            ));
        }
        return null;
    }

    $check = pp_udc_validate_value($value, $param);
    if ($check !== true) {
        return new WP_Error('invalid_prop_value', $where . ': ' . $check->get_error_message());
    }
    return null;
}

// ── Minting (write-time normalization, §3.1) ────────────────────────────────

/**
 * The deterministic mint name for one responsive value.
 *
 * `<role>-<group>-<param>[-hover][-<bp>]`, exactly as §3.1 states the rule.
 * (§3.1's illustrative snippet elides the group — `quote-size-d` — but the RULE
 * is what has to be collision-free, and two groups can carry the same param
 * name, so the group segment stays.) Deterministic, never sequential and never
 * random: pp_composition_content_hash() would otherwise see a different value on
 * every write and make the composition false-conflict against itself, which is
 * the defect that put the props.id strip in the hash in the first place.
 */
function pp_udc_mint_name(string $role, string $group, string $param, bool $hover, string $bp): string {
    return $role . '-' . $group . '-' . $param . ($hover ? '-hover' : '') . '-' . $bp;
}

/**
 * Write-time normalization: a responsive value (one param, N breakpoints) has
 * its literals lifted into the band's own `_tokens` map and rewritten as
 * references.
 *
 * A NON-responsive value mints nothing — §3.1 makes minting "the ENGINE's
 * normalization choice only when a value is used responsively", so a plain
 * literal stays a plain literal and the emitted CSS stays minimal.
 *
 * THE NO-COERCION RULE IS WHAT MAKES THIS HONEST: the author wrote `19px` and
 * the envelope reports `19px`, with the minted reference disclosed beside it.
 * The normalization is visible, never silent — which is also what invariant I36
 * requires of anything that could otherwise behave as a hidden alias. That
 * disclosure is built by pp_udc_composition_findings() from the STORED result
 * rather than handed out of here: minting preserves the literal as the token's
 * value, so the two facts the operator needs are both still on disk, and a
 * disclosure derived there also reaches restore and `check page` — surfaces this
 * function never runs on.
 *
 * Band tokens live and die with their band, so there is no lifecycle to own and
 * nothing to garbage-collect: the map is inside the composition value, which
 * means CAS, history, undo and rollback already cover it.
 *
 * @param array $item One composition item.
 * @return array      The normalized item.
 */
function pp_udc_normalize_band(array $item): array {
    if (!isset($item['udc']) || !is_array($item['udc'])) {
        return $item;
    }
    $component = isset($item['component']) && is_scalar($item['component'])
        ? (string) $item['component']
        : '';
    $udc    = $item['udc'];
    $tokens = isset($udc['_tokens']) && is_array($udc['_tokens']) ? $udc['_tokens'] : [];

    foreach ($udc as $role => $role_map) {
        if (in_array($role, pp_udc_reserved_keys(), true) || !is_array($role_map)) {
            continue;
        }
        foreach ($role_map as $group => $group_map) {
            if (!is_array($group_map)) {
                continue;
            }
            foreach ($group_map as $param => $value) {
                if ($param === PP_UDC_HOVER && is_array($value)) {
                    foreach ($value as $hover_param => $hover_value) {
                        $minted = _pp_udc_mint_value(
                            $hover_value, (string) $role, (string) $group, (string) $hover_param,
                            true, $tokens
                        );
                        if ($minted !== null) {
                            $udc[$role][$group][$param][$hover_param] = $minted;
                        }
                    }
                    continue;
                }
                $minted = _pp_udc_mint_value(
                    $value, (string) $role, (string) $group, (string) $param,
                    false, $tokens
                );
                if ($minted !== null) {
                    $udc[$role][$group][$param] = $minted;
                }
            }
        }
    }

    if ($tokens !== []) {
        $udc['_tokens'] = $tokens;
    }
    $item['udc'] = $udc;
    return $item;
}

/**
 * Mints one param's breakpoint map, or returns null when there is nothing to do.
 *
 * @return array|null The rewritten value map.
 */
function _pp_udc_mint_value(
    $value,
    string $role,
    string $group,
    string $param,
    bool $hover,
    array &$tokens
): ?array {
    if (!is_array($value) || $value === []) {
        return null; // Not responsive — nothing to normalize.
    }
    $breakpoints = pp_udc_breakpoints();
    $rewritten   = [];
    $changed     = false;

    foreach ($value as $bp => $bp_value) {
        if (!isset($breakpoints[$bp]) || !is_scalar($bp_value)) {
            $rewritten[$bp] = $bp_value;
            continue;
        }
        $literal = (string) $bp_value;
        if (pp_udc_parse_reference($literal) !== null) {
            $rewritten[$bp] = $bp_value; // Already a reference; the author's own.
            continue;
        }
        $name           = pp_udc_mint_name($role, $group, $param, $hover, (string) $bp);
        $tokens[$name]  = $literal;
        $rewritten[$bp] = '@' . $name;
        $changed        = true;
    }
    return $changed ? $rewritten : null;
}

/**
 * Normalizes every band in a composition. Called from the write path so an
 * author's literals are lifted exactly once, at the moment they are stored.
 */
function pp_udc_normalize_composition(array $items): array {
    foreach ($items as $i => $item) {
        if (is_array($item) && isset($item['udc'])) {
            $items[$i] = pp_udc_normalize_band($item);
        }
    }
    return $items;
}

// ── Compilation: the cascade, with provenance ───────────────────────────────

/**
 * Resolves one band into the declarations it will emit, carrying PROVENANCE.
 *
 * Provenance is not decoration. Invariant I35 requires that no declared
 * authoring input is silently ignored or cancelled, and that the envelope
 * DISCLOSES when a submitted value cannot take effect. Carrying `source` per
 * declaration is what makes that disclosure possible at all — a resolver that
 * returns only the winning string can never report what lost.
 *
 * `$layer` is REQUIRED, and that is the point of it. It used to default to
 * `'all'` — both tiers resolved into one result — which no production caller
 * ever asked for and which is precisely the shape the cascade contract forbids
 * emitting: the two tiers rank by the position they print at, so a caller that
 * receives them merged has already lost the ranking. Production passes
 * `'defaults'` or `'authored'`; `'all'` survives as an explicit request, for
 * tests that inspect which layer won a declaration.
 *
 * @param string $layer  'defaults' | 'authored' | 'all'
 * @return array {
 *   id     string
 *   tokens array  name => literal, emitted as --pp-<name> on the band root
 *   blocks array  ordered emission units
 * }
 */
function pp_udc_compile_band(array $item, string $layer): array {
    $out = ['id' => '', 'tokens' => [], 'blocks' => []];

    // MINT-ON-WRITE ONLY — READS NEVER MUTATE. A band that reached storage with
    // no id (raw meta, data written before the rule, or restore_composition,
    // which reports findings without blocking per #233) emits NOTHING and
    // renders structurally. It must never be given an id here: a fabricated id
    // would differ between two reads of the same row, and an EMPTY one would
    // make `[data-pp-band=""]` match every other id-less band on the page and
    // cross-apply one band's design to another.
    //
    // CHECKED FIRST, before the component registry is touched. Only a v2
    // component is ever minted an id, so this gate alone short-circuits every
    // band of the eleven legacy components — and on a page built entirely from
    // those, nothing else on the front end warms the registry, so asking for it
    // here would make every such request pay a scandir plus twelve schema reads
    // to produce no CSS at all.
    $id = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : '';
    if ($layer !== 'defaults' && !pp_udc_valid_band_id($id)) {
        return $out;
    }

    $component = isset($item['component']) && is_scalar($item['component'])
        ? (string) $item['component']
        : '';
    $roles = pp_udc_component_roles($component);
    if ($roles === []) {
        return $out; // A legacy component; the v1 styling path owns it.
    }
    $out['id'] = $id;
    if ($layer === 'defaults') {
        $out['id'] = $component;
    }

    $udc         = isset($item['udc']) && is_array($item['udc']) ? $item['udc'] : [];
    $band_tokens = isset($udc['_tokens']) && is_array($udc['_tokens']) ? $udc['_tokens'] : [];
    $groups      = pp_udc_groups();
    $breakpoints = pp_udc_breakpoints();
    $referenced  = [];

    // `_band` first, then the component's declared role order: later bands'
    // blocks emit after earlier ones, and within a band the root precedes its
    // parts, so a role can refine what the band sets without a specificity bump.
    $ordered = [];
    if (isset($roles['_band'])) {
        $ordered['_band'] = $roles['_band'];
    }
    foreach ($roles as $name => $def) {
        if ($name !== '_band') {
            $ordered[$name] = $def;
        }
    }

    foreach ($ordered as $role_name => $role_def) {
        $selector = (string) ($role_def['selector'] ?? '');
        // Second-layer gate, mirroring the render boundary's posture: schemas are
        // repo-controlled and integrity-checked, but a selector is emitted into
        // raw CSS and the cost of checking is a regex.
        if ($selector !== '' && !preg_match('/^[A-Za-z0-9_ .\-]{1,120}$/', $selector)) {
            continue;
        }

        $declared = isset($udc[$role_name]) && is_array($udc[$role_name]) ? $udc[$role_name] : [];
        $defaults = isset($role_def['defaults']) && is_array($role_def['defaults']) ? $role_def['defaults'] : [];

        // states: '' (base) and ':hover'.
        $resolved = []; // state => bp => property => ['css'=>, 'source'=>, 'literal'=>]

        $sources = $layer === 'defaults'
            ? [['defaults', $defaults]]
            : ($layer === 'authored' ? [['udc', $declared]] : [['defaults', $defaults], ['udc', $declared]]);
        foreach ($sources as [$source, $map]) {
            foreach ($map as $group_name => $group_map) {
                if (!isset($groups[$group_name]) || !is_array($group_map)) {
                    continue;
                }
                $params = $groups[$group_name]['params'];
                foreach ($group_map as $param_name => $value) {
                    if ($param_name === PP_UDC_HOVER && is_array($value)) {
                        foreach ($value as $hp => $hv) {
                            _pp_udc_place($resolved, PP_UDC_HOVER, $params, (string) $hp, $hv, $source, $band_tokens, $breakpoints, $referenced);
                        }
                        continue;
                    }
                    _pp_udc_place($resolved, '', $params, (string) $param_name, $value, $source, $band_tokens, $breakpoints, $referenced);
                }
            }
        }

        foreach ($resolved as $state => $by_bp) {
            foreach ($by_bp as $bp => $declarations) {
                if ($declarations === []) {
                    continue;
                }
                $out['blocks'][] = [
                    'role'     => $role_name,
                    'selector' => $selector,
                    'state'    => $state,
                    'bp'       => $bp,
                    'decls'    => $declarations,
                ];
            }
        }
    }

    // Only tokens something actually references are emitted. The unused-token
    // LINT lives on the write side (pp_udc_composition_findings), which is where
    // findings have a channel to an operator; computing it here would build a
    // warning message on every page view for a consumer that does not exist.
    foreach ($band_tokens as $name => $literal) {
        if (isset($referenced[$name])) {
            // THE SECOND LAYER, ON THE DEFINITION AS WELL AS THE REFERENCE.
            //
            // A band token is emitted as a CUSTOM PROPERTY DECLARATION on the band
            // root — `--pp-<name>:<value>;` — which makes both halves CSS source
            // text. The write gate checks both, but the write gate is not the only
            // way data arrives: a raw meta write, a composition written before this
            // rule existed, and restore_composition (which reports findings without
            // blocking, #233) all reach this line directly.
            //
            // Without this check a stored value of `red} body{display:none} .z{red`
            // closes the band's own rule and injects a rule of its own — the exact
            // style-block escape the shared reject set exists to stop, arriving
            // through the one path that was not consulting it. Same posture as the
            // #330 render boundary: two layers, one set, and the layers apply to
            // everything that becomes CSS, not just to the values that look like
            // values.
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $name)) {
                continue;
            }
            if (_pp_forbidden_css_construct((string) $literal) !== null) {
                continue;
            }
            // AND THE FULL GRAMMAR, not just the reject set.
            //
            // The reject set bans `{ } ; < >` but says nothing about an
            // unbalanced `(` or an unclosed `"`, and CSS tokenization treats both
            // as OPEN: `--pp-t:rgb(;` swallows the terminating semicolon, the
            // band's closing brace, and every rule after it — the band's own role
            // rules, every LATER band's block, and the remainder of the shared
            // inline stylesheet. Destruction across bands rather than script
            // execution (`<` is banned either way), but a single stored value
            // taking out a page's styling is not a degradation anyone would
            // choose.
            //
            // Validating against the grammar of the parameter that references it
            // is what closes it: `rgb(` is not a colour, so it never reaches the
            // stylesheet. This is the same engine the declaration itself passed
            // through, which is what "two layers, one set" has to mean if it
            // means anything.
            if (pp_udc_validate_value((string) $literal, $referenced[$name]) !== true) {
                continue;
            }
            $out['tokens'][$name] = (string) $literal;
        }
    }

    return $out;
}

/**
 * Places one param's value into the resolution table, recording where it came
 * from. A later source (udc) overwrites an earlier one (defaults) at the SAME
 * (state, breakpoint, property) key.
 *
 * The `source` and `literal` it records are what make a disclosure POSSIBLE — a
 * resolver that returned only the winning string could never report what lost.
 * The disclosures that exist today are built on the write side, from the same
 * data: see pp_udc_composition_findings().
 */
function _pp_udc_place(
    array &$resolved,
    string $state,
    array $params,
    string $param_name,
    $value,
    string $source,
    array $band_tokens,
    array $breakpoints,
    array &$referenced
): void {
    if (!isset($params[$param_name])) {
        return;
    }
    $property = $params[$param_name]['property'];

    $per_bp = is_array($value) ? $value : ['d' => $value];
    foreach ($per_bp as $bp => $raw) {
        if (!isset($breakpoints[$bp]) || !is_scalar($raw)) {
            continue;
        }
        $literal       = (string) $raw;
        $css           = $literal;
        $band_ref_name = null;

        $ref = pp_udc_parse_reference($literal);
        if ($ref !== null) {
            // The reference name is interpolated into `var(--pp-<name>)`, so it is
            // CSS source text too and gets the same charset gate the write path
            // applies to a token name. Stored data is the reason: the write path
            // cannot have been the only thing that ever looked at this.
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref)) {
                continue;
            }
            $target = pp_udc_resolve_reference($ref, $band_tokens, $source === 'defaults');
            if ($target === null) {
                // Unresolvable at render. The write gate refuses this, so
                // reaching here means stored-before-the-rule data: drop the one
                // declaration rather than emit `var()` of a token that does not
                // exist, and leave every sibling declaration painting.
                continue;
            }
            $css          = $target['css'];
            $literal       = $target['value'];
            $band_ref_name = $target['scope'] === 'band' ? $ref : null;
        }

        // Emit-time re-validation — the same engine the write path used, not a
        // weaker subset of it. v1's render boundary (pp_render_style_value_allowed,
        // #330) re-runs the TYPED validator and not just the reject set, and v2
        // must not be the weaker of the two: a stored value that no longer
        // satisfies its parameter's grammar drops its own declaration and leaves
        // every sibling painting, exactly as a refused v1 slot did.
        if (_pp_forbidden_css_construct($css) !== null) {
            continue;
        }
        // …on AUTHOR data. A role default is a repo-controlled schema constant,
        // integrity-hashed and already validated in CI by the schema suite; it is
        // not the thing a render boundary exists to guard. v1 drew the same line:
        // pp_render_style_value_allowed() re-validates the STORED style map, never
        // the CSS file's own fallbacks. Re-checking ~45 fixed constants on every
        // band of every request cost 2.6x the whole page's CSS build and bought
        // nothing an operator could ever have changed.
        if ($source === 'udc' && pp_udc_validate_value($literal, $params[$param_name]) !== true) {
            continue;
        }

        // A token counts as REFERENCED only once the declaration that references
        // it has cleared both emit gates. Marking it earlier would emit the token
        // definition for a declaration that was itself refused — a
        // custom property on the band root with nothing reading it, built from a
        // value the engine just decided it would not paint.
        if ($band_ref_name !== null) {
            $referenced[$band_ref_name] = $params[$param_name];
        }

        $resolved[$state][$bp][$property] = [
            'css'     => $css,
            'source'  => $source,
            'literal' => $literal,
        ];
    }
}

// ── Emission ────────────────────────────────────────────────────────────────

/** A band id is a CSS attribute-selector value; the charset is what bounds it. */
function pp_udc_valid_band_id(string $id): bool {
    return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
}

/**
 * Renders one band's scoped CSS block.
 *
 * Order is §3.4's, exactly: the band root's minted tokens, then base
 * declarations, then `@media` blocks narrow-first, then hover rules (and hover
 * inside each media block). Every selector is `[data-pp-band="<id>"]` plus the
 * role's own selector, so specificity is flat and `!important` never appears.
 */
function pp_udc_band_css(array $item): string {
    $compiled = pp_udc_compile_band($item, 'authored');
    if ($compiled['id'] === '') {
        return '';
    }
    return _pp_udc_render_blocks($compiled, '[data-pp-band="' . $compiled['id'] . '"]');
}

/**
 * A component's ROLE DEFAULTS, emitted once per page under a component scope.
 *
 * THIS IS WHY IT IS NOT PER BAND. Defaults are component-level constants —
 * identical for every band of that component — so scoping them to a band id
 * bought nothing and cost two things that turned out to matter:
 *
 * 1. A band that reached storage without an id got NO defaults at all, because
 *    the whole block was gated on the id. Raw `_pp_composition` meta writes are
 *    exactly that case, which meant a component could silently fall out of the
 *    shared cross-band contracts (#431 band rhythm, #430 symmetry, #436 heading
 *    scale) while every band that HAD an id looked fine.
 * 2. A 50-band page repeated the same ~1.8 KB of constants fifty times.
 *
 * Emitted BEFORE the per-band blocks, so a band's authored value wins the tie at
 * equal specificity on source order.
 *
 * The shared adjacent-band rule no longer competes on SPECIFICITY at all: it is
 * `:where(main > [data-pp-component] + [data-pp-component])` in components.css
 * and contributes zero, which is what let an authored band value outrank it. So
 * a root-level default cannot be ranked under it by being "weaker" — there is
 * nothing weaker than zero. It is ranked under it by PRINTING FIRST instead:
 * this layer attaches to the `pp-base` handle and the shared rule lives in
 * components.css, which loads after. See the split in the function body.
 */
function pp_udc_component_defaults_css(string $component): string {
    $compiled = pp_udc_compile_band(['component' => $component], 'defaults');
    if ($compiled['id'] === '') {
        return '';
    }
    // Defaults that target the BAND ROOT carry no specificity; defaults that
    // target an element inside the band carry the normal scope weight.
    //
    // The split is not about roles, it is about what else is aiming at the same
    // element. The design system's contextual band rules (the adjacent-band
    // rhythm) target the band root, and an UNAUTHORED band must still obey them —
    // the #430/#431 rulings survive the pivot — so a root-level default has to be
    // the weakest statement in the cascade and yield to them. Nothing in the
    // design system aims at `.testimonials__quote`, so an element-level default
    // has no such rule to yield to; what it does have to beat is ordinary
    // structural CSS, including rules like base.css's `p:last-child` [0,1,1] that
    // once zeroed the subheading rhythm (#336). At [0,2,0] it clears them.
    //
    // Authored values outrank both tiers; see pp_udc_band_css().
    $scope = '[data-pp-component="' . $component . '"]';

    return _pp_udc_render_blocks($compiled, $scope, ':where(' . $scope . ')');
}

/** Renders a compiled result under one scope selector. */
function _pp_udc_render_blocks(array $compiled, string $scope, ?string $root_scope = null): string {
    $css   = '';
    // Rules aimed at the band root may need a different weight from rules aimed
    // at elements inside it; callers that do not care pass one scope for both.
    $root_scope = $root_scope ?? $scope;

    if ($compiled['tokens'] !== []) {
        $decls = '';
        foreach ($compiled['tokens'] as $name => $value) {
            $decls .= '--pp-' . $name . ':' . $value . ';';
        }
        $css .= $root_scope . '{' . $decls . '}';
    }

    // Bucketed once. The emission order below is a 4-tier x 3-breakpoint product,
    // so filtering the block list inside it would walk every block twelve times
    // and discard almost all of them on eleven of the passes.
    $by_state_bp = [];
    foreach ($compiled['blocks'] as $block) {
        $by_state_bp[$block['state']][$block['bp']][] = $block;
    }

    // 1. base, 2. media narrow-first, 3. hover, 4. hover in media.
    foreach ([['', 'base'], ['', 'media'], [PP_UDC_HOVER, 'base'], [PP_UDC_HOVER, 'media']] as [$state, $tier]) {
        foreach (pp_udc_breakpoints_in_emit_order() as $bp => $meta) {
            $is_base = $meta['media'] === null;
            if (($tier === 'base') !== $is_base) {
                continue;
            }
            $rules = '';
            foreach (($by_state_bp[$state][$bp] ?? []) as $block) {
                $decls = '';
                foreach ($block['decls'] as $property => $entry) {
                    $decls .= $property . ':' . $entry['css'] . ';';
                }
                if ($decls === '') {
                    continue;
                }
                $selector = ($block['selector'] !== ''
                    ? $scope . ' ' . $block['selector']
                    : $root_scope) . $state;
                $rules   .= $selector . '{' . $decls . '}';
            }
            if ($rules === '') {
                continue;
            }
            $css .= $is_base ? $rules : '@media ' . $meta['media'] . '{' . $rules . '}';
        }
    }

    return $css;
}

/**
 * Both layers concatenated, in order. FOR TESTS — NOT FOR ANY SINK.
 *
 * NOTHING THAT EMITS CSS MAY CALL THIS, and the rule is enforced rather than
 * requested: PreviewCascadeParityTest fails if any file outside tests/ names it.
 *
 * The reason is the whole of §3.4. The two layers do not rank by specificity —
 * both are zero-or-low by construction — they rank by the POSITION each prints
 * at, defaults before the theme stylesheets and authored after them. A single
 * string holds one position, so pasting this into one <style> block does not
 * emit the cascade, it flattens it: the defaults layer lands after the shared
 * design-system rules and starts beating them. That is not hypothetical. It is
 * what the editor preview did until the two-block fix, and it is why this
 * function is now a test convenience with a tripwire rather than an API.
 *
 * Tests use it to assert the two halves compose, which is a real property worth
 * pinning — it just is not an emission strategy.
 */
function pp_udc_page_css(array $items): string {
    return pp_udc_page_defaults_css($items) . pp_udc_page_authored_css($items);
}

/**
 * Layer 1: every v2 component's role defaults, once each.
 *
 * Printed BEFORE the theme stylesheets (see functions.php). Source order is
 * load-bearing, not incidental: `_band` defaults and the shared adjacent-band
 * rhythm rule both sit at zero specificity, so whichever prints later wins, and
 * the shared rhythm must. Printing this layer first is what keeps an unauthored
 * v2 band inside the #430/#431 rulings.
 */
function pp_udc_page_defaults_css(array $items): string {
    $css        = '';
    $components = [];
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['component']) || !is_scalar($item['component'])) {
            continue;
        }
        $name = (string) $item['component'];
        if (!isset($components[$name]) && pp_udc_is_v2_component($name)) {
            $components[$name] = true;
            $css .= pp_udc_component_defaults_css($name);
        }
    }
    return $css;
}

/**
 * Layer 2: each band's authored values, in composition order.
 *
 * Printed AFTER the theme stylesheets, scoped `[data-pp-band="<id>"]`, so an
 * authored value outranks both the defaults layer and the shared design-system
 * rules. Bands are scoped to their own id, so no two can ever contend.
 */
function pp_udc_page_authored_css(array $items): string {
    $css = '';
    foreach ($items as $item) {
        if (is_array($item)) {
            $css .= pp_udc_band_css($item);
        }
    }
    return $css;
}

/**
 * The composition a page's UDC CSS must be built from.
 *
 * The emitter runs at `wp_enqueue_scripts`, which fires BEFORE the <main> loop
 * that renders the bands — so it cannot collect from the render pass and has to
 * resolve the composition itself. It resolves it exactly the way the templates
 * do, through the same two functions, because an emitter that disagreed with the
 * template would ship CSS with no matching markup and nothing would notice.
 */
function pp_udc_current_composition(): array {
    if (!is_singular()) {
        return [];
    }
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return [];
    }
    if (function_exists('is_front_page') && is_front_page()) {
        // The front page has its own classification arm (no_front / corrupt),
        // and both of those render an admin notice INSTEAD of the bands. Emitting
        // CSS for bands the template is not going to paint would be CSS with no
        // markup, so the emitter honours the same three-way result.
        $render = pp_resolve_front_page_render((int) $post_id);
        if (($render['mode'] ?? '') !== 'render') {
            return [];
        }
        return is_array($render['composition'] ?? null) ? $render['composition'] : [];
    }
    // The shared reader, not a second json_decode: a corrupt row must resolve to
    // "no bands" here exactly as it does at render, never to a partial list.
    $result = pp_get_composition_result((int) $post_id);
    if (empty($result['ok']) || !is_array($result['composition'] ?? null)) {
        return [];
    }
    return $result['composition'];
}

// ── Band identity ───────────────────────────────────────────────────────────

/**
 * Finds band ids claimed by more than one item.
 *
 * The twin of pp_find_duplicate_component_ids() (lib/guardrails.php), for the
 * OTHER id namespace. They are deliberately separate: `props.id` is the author's
 * HTML anchor and the handle update/remove/style target by name, while the
 * top-level `id` is the internal scope for this band's emitted CSS. Conflating
 * them would let renaming an anchor silently re-scope a band's design.
 *
 * @return array<int, array{id: string, indices: array}>
 */
function _pp_find_duplicate_band_ids(array $composition): array {
    $seen = [];
    foreach ($composition as $key => $item) {
        if (!is_array($item) || !isset($item['id']) || !is_scalar($item['id'])) {
            continue;
        }
        $id = (string) $item['id'];
        if ($id === '') {
            continue; // An absent id is minted at write; it is not a collision.
        }
        $seen[$id][] = $key;
    }
    $dupes = [];
    foreach ($seen as $id => $indices) {
        if (count($indices) > 1) {
            $dupes[] = ['id' => (string) $id, 'indices' => $indices];
        }
    }
    return $dupes;
}

/**
 * Mints and carries forward band ids across a whole-composition write.
 *
 * THE ALGORITHM, stated tightly because it has to be testable:
 *   for each incoming item with no usable top-level id, carry stored[i].id iff
 *     - stored[i] exists, AND
 *     - stored[i].component === incoming[i].component, AND
 *     - stored[i].id is a non-empty valid id, AND
 *     - no other incoming item already claims that id;
 *   otherwise mint a fresh pp-<hex8>.
 *
 * Insert, delete and reorder therefore CHURN ids, and that is the accepted
 * behaviour rather than a gap: a band id is an internal scoping handle, never an
 * author-meaningful anchor, so a churned id costs nothing but a different string
 * in the emitted selector. The author-meaningful identifier is `props.id`, which
 * this never touches.
 *
 * MINT-ON-WRITE ONLY. Nothing on a read path may call this — a read that minted
 * would hand two callers two different ids for the same stored row.
 *
 * Written structurally rather than by copying the props.id loop next door: that
 * loop is deliberately non-defensive (#946), and PHP splits scalars along a line
 * no operator can predict — `false` and `null` auto-vivify into an array and
 * fabricate a band, while a string, int, float or `true` throws. So the test
 * here is "is this an array", and absent/null is the unset sentinel.
 */
function pp_udc_assign_band_ids(array $incoming, array $stored = []): array {
    $claimed = [];
    foreach ($incoming as $item) {
        if (is_array($item) && isset($item['id']) && is_scalar($item['id'])) {
            $id = (string) $item['id'];
            if ($id !== '' && pp_udc_valid_band_id($id)) {
                $claimed[$id] = true;
            }
        }
    }

    foreach ($incoming as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        // ONLY components that are on the UDC get a band id.
        //
        // §3.1 requires an id on every v2 BAND, and a band is v2 when its
        // component has been rebuilt onto the contract — not when the codebase
        // is. Minting onto the eleven components still running the legacy
        // styling system would change their stored shape for no reader, which
        // is precisely the "other components untouched" boundary the sprint
        // plan draws. Each one gets its id in its own rebuild sprint, at the
        // same moment it gets roles.
        $component = isset($item['component']) && is_scalar($item['component'])
            ? (string) $item['component']
            : '';
        if ($component === '' || !pp_udc_is_v2_component($component)) {
            continue;
        }

        $current = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : '';
        if ($current !== '' && pp_udc_valid_band_id($current)) {
            continue; // An authored (or already-minted) id is honoured, never overwritten.
        }

        $carried = '';
        if (isset($stored[$i]) && is_array($stored[$i])
            && isset($stored[$i]['id'], $stored[$i]['component'], $item['component'])
            && is_scalar($stored[$i]['id'])
            && is_scalar($stored[$i]['component']) && is_scalar($item['component'])
            && (string) $stored[$i]['component'] === (string) $item['component']
        ) {
            $candidate = (string) $stored[$i]['id'];
            if ($candidate !== '' && pp_udc_valid_band_id($candidate) && !isset($claimed[$candidate])) {
                $carried = $candidate;
            }
        }

        $assigned = $carried !== '' ? $carried : pp_generate_component_id();
        while (isset($claimed[$assigned])) {
            $assigned = pp_generate_component_id();
        }
        $claimed[$assigned] = true;
        $incoming[$i]['id'] = $assigned;
    }

    return $incoming;
}

/**
 * The group/parameter vocabulary as one prose line, for the AI-facing surfaces.
 *
 * DERIVED, never restated. v1 kept four hand-maintained copies of its accepted
 * unit set and pinned none of them to the validator; this is the one place the
 * taxonomy is described, so a parameter added in a later sprint reaches the
 * authoring model on the day it lands rather than whenever someone remembers.
 */
function pp_udc_group_summary(): string {
    $lines = [];
    foreach (pp_udc_groups() as $group => $definition) {
        $lines[] = $group . ' (' . implode(', ', array_keys($definition['params'])) . ')';
    }
    return implode('; ', $lines);
}

// ── Write-side findings: the disclosure channel ─────────────────────────────

/**
 * The findings a `udc` map produces for the write envelope.
 *
 * THIS FUNCTION EXISTS BECAUSE THE PROMISE NEEDED A KEEPER. §3.1's no-coercion
 * rule says the envelope reports what the AUTHOR wrote, with the engine's
 * normalization disclosed beside it — and the runtime prompt tells the authoring
 * model exactly that. Minting rewrites a responsive literal into a band-token
 * reference at write, so without a disclosure reaching the envelope the literal
 * IS silently rewritten from the caller's point of view: the precise I35 class
 * ("no declared authoring input is silently ignored or cancelled ... the
 * envelope discloses") that the engine claims to close.
 *
 * Derived from the SUBMITTED composition, so it reports what the author sent
 * rather than what storage ended up holding. Joined into the envelope by
 * _pp_composition_findings(), the one assembler every composition write already
 * routes through, so it inherits the existing bounding and truncation.
 *
 * @return array<int, array{type: string, message: string, index: int|null}>
 */
function pp_udc_composition_findings(array $items): array {
    if (!pp_is_list($items)) {
        return [];
    }
    $findings = [];

    foreach ($items as $i => $item) {
        if (!is_array($item) || !isset($item['udc']) || !is_array($item['udc'])) {
            continue;
        }
        $component = isset($item['component']) && is_scalar($item['component'])
            ? (string) $item['component']
            : '';
        if ($component === '' || !pp_udc_is_v2_component($component)) {
            continue;
        }

        $tokens = isset($item['udc']['_tokens']) && is_array($item['udc']['_tokens'])
            ? $item['udc']['_tokens']
            : [];
        if ($tokens === []) {
            continue;
        }
        $referenced = _pp_udc_referenced_token_names($item['udc']);

        // THE NO-COERCION DISCLOSURE, derived from what is STORED.
        //
        // It has to be, because this runs over the stored composition — the
        // post-write envelope, restore_composition, `wp pp check page` — and by
        // then minting has already happened. Re-running the normalizer would
        // report nothing, since there is nothing left to normalise.
        //
        // Deriving it works because minting PRESERVES the author's literal: it
        // becomes the token's value. So `_tokens['quote-typography-size-d'] =
        // '19px'` is exactly "the author wrote 19px, stored as
        // --pp-quote-typography-size-d" — the §3.1 disclosure, reconstructed from
        // the only two facts that matter, both of which are still on disk.
        foreach ($tokens as $name => $literal) {
            if (!is_scalar($literal) || !_pp_udc_is_mint_shaped_name((string) $name)) {
                continue;
            }
            if (!_pp_udc_name_is_the_engines_own_mint((string) $name, $item['udc'])) {
                continue;
            }
            $findings[] = [
                'type'    => 'udc_token_minted',
                'message' => sprintf(
                    'Component "%s": you wrote "%s"; it is stored as the band token --pp-%s '
                    . 'because the value is set per breakpoint.',
                    $component,
                    (string) $literal,
                    (string) $name
                ),
                'index'   => is_int($i) ? $i : null,
            ];
        }
        foreach ($tokens as $name => $unused) {
            if (isset($referenced[(string) $name])) {
                continue;
            }
            $findings[] = [
                'type'    => 'udc_unused_band_token',
                'message' => sprintf(
                    'Component "%s" declares the band token "%s" but nothing references it (@%s), so it has no effect.',
                    $component,
                    (string) $name,
                    (string) $name
                ),
                'index'   => is_int($i) ? $i : null,
            ];
        }
    }

    return $findings;
}

/** Every `@name` a band's udc map references, as a lookup set. */
function _pp_udc_referenced_token_names(array $udc): array {
    $seen = [];
    $walk = static function ($value) use (&$walk, &$seen): void {
        if (is_array($value)) {
            foreach ($value as $inner) {
                $walk($inner);
            }
            return;
        }
        if (is_scalar($value)) {
            $ref = pp_udc_parse_reference((string) $value);
            if ($ref !== null) {
                $seen[$ref] = true;
            }
        }
    };
    foreach ($udc as $key => $value) {
        if ($key === '_tokens') {
            continue;
        }
        $walk($value);
    }
    return $seen;
}

/**
 * The one refusal message for an unbalanced value — both callers say it.
 *
 * Takes its opening subject like its sibling `_pp_font_family_message()`, so the
 * standalone refusal from pp_udc_validate_value() reads as a sentence ("Value
 * has an unbalanced…", matching every other refusal that function returns) while
 * pp_udc_validate_map() can open with the band and token it is naming.
 *
 * SAY ONLY WHAT IS TRUE. An earlier draft told the author to "enclose the whole
 * name in the other one", which is advice this check then refuses: it counts
 * apostrophes across the WHOLE value with no idea that one of them sits inside a
 * double-enclosed name, so `"Foo's Font"` is rejected however it is written. The
 * parenthesis case genuinely does work once the pair is closed, so the message
 * distinguishes the two instead of promising a fix for both. A refusal that
 * recommends a step which also fails is worse than a bare refusal — it sends the
 * author round a loop before they conclude the value is unsupported.
 *
 * Deliberately worded without the word q-u-o-t-e: `quote` is a testimonials ROLE
 * name, and testTheEngineNamesNoComponentAndNoRole() reads this file's code with
 * its comments stripped to keep the shared engine free of any one component's
 * vocabulary. The guard caught this message on its first run.
 */
function _pp_udc_unbalanced_message(string $subject = 'Value'): string {
    return $subject . ' has an unbalanced ( ) or an odd number of \' or " characters. CSS treats '
        . 'each of those as still open, so the value would swallow every declaration and rule '
        . 'after it. Close the pair — a font name may carry ( ) as long as both appear. A name '
        . 'containing a single apostrophe is not accepted in any form.';
}

/**
 * True when a value's CSS delimiters all close.
 *
 * THE SHARED REJECT SET DOES NOT COVER THIS, AND IS RIGHT NOT TO. It was written
 * for v1's sink — an inline `style` attribute passed through esc_attr() — where
 * an unclosed quote becomes `&quot;` and an unbalanced paren is inert. v2's sink
 * is CSS SOURCE TEXT in a <style> block, where CSS tokenization treats `rgb(`
 * and `"abc` as OPEN and consumes the terminating `;`, the closing `}`, and every
 * rule that follows. Same bytes, different sink, different rule: widening the
 * shared set would impose a v2 sink's constraint on eleven components whose sink
 * still escapes.
 *
 * CONSEQUENCE, STATED BECAUSE IT IS A REAL SPLIT: this gate guards the v2 sink
 * only. `font-family` is the one type where the difference is visible — a name
 * carrying a lone apostrophe validates as a DESIGN TOKEN (v1's escaped sink,
 * `_pp_validate_token_value`, no gate) and is refused on a v2 `typography.family`
 * parameter, because only the latter runs this. The two surfaces accept different
 * sets on purpose; the AI-facing docs say so per surface.
 *
 * CALLED FROM TWO PLACES. `pp_udc_validate_map()`'s `_tokens` loop covers a token
 * that nothing references, since §3.1 makes an unreferenced token a warning
 * rather than a refusal and so no parameter grammar ever reaches it.
 * `pp_udc_validate_value()` covers everything else — the authored write path, the
 * emit-time re-validation, and a referenced token checked against the grammar of
 * the parameter that uses it.
 */
function _pp_udc_delimiters_balanced(string $value): bool {
    // Nothing to balance. Exactly equivalent to the work below — with none of the
    // four characters present, both counts are zero and the depth never leaves
    // zero — and it is the case almost every value takes, which matters because
    // this now runs per declaration at EMIT as well as at write. One scan instead
    // of three: measured over a realistic mix of emitted values (lengths, hex
    // colours, keywords, one quoted font stack, one clamp()), 0.46 -> 0.24
    // microseconds per call, best of five runs of 200k calls.
    if (strpbrk($value, '()"\'') === false) {
        return true;
    }
    if (substr_count($value, '"') % 2 !== 0 || substr_count($value, "'") % 2 !== 0) {
        return false;
    }
    // The per-byte loop below exists only to catch `)` BEFORE `(` — an ordering
    // question. When the counts differ the answer is already no, and when there
    // are no parentheses at all there is no ordering to check. Skipping it in
    // those two cases cannot change the result, and it is the case a quoted font
    // stack takes: measured 1.55 -> 0.18 microseconds per call on values that
    // carry a mark but no parenthesis.
    $open = substr_count($value, '(');
    if ($open !== substr_count($value, ')')) {
        return false;
    }
    if ($open === 0) {
        return true;
    }
    $depth = 0;
    $len   = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        if ($value[$i] === '(') {
            $depth++;
        } elseif ($value[$i] === ')' && --$depth < 0) {
            return false;
        }
    }
    return $depth === 0;
}

/**
 * True when a token name is one the engine would mint for itself.
 *
 * The mint namespace has to be reserved or it is not a namespace. `_pp_udc_mint_value()`
 * assigns into `_tokens` unconditionally, so an author who declared a token called
 * `quote-typography-size-d` — the exact name the engine produces for a responsive
 * quote size, and the name this file's own header example uses — would have their
 * declared value overwritten with no refusal and no disclosure. Refusing the name at
 * write is cheaper than detecting the collision later, and it is the only option
 * that keeps "no declared authoring input is silently cancelled" (I35) true.
 *
 * Derived from pp_udc_mint_name()'s own shape rather than restated: a name matches
 * when it ends in `-<breakpoint>` (optionally `-hover-<breakpoint>`) and the segments
 * before it name a real group and one of that group's parameters.
 */
/**
 * True when a mint-shaped token name is the ENGINE'S OWN, not an author squatting it.
 *
 * The reservation above cannot be a blanket refusal, and finding that out cost a
 * real regression: validation runs over STORED compositions too — the post-write
 * envelope, `restore_composition`, `wp pp check page` — and a stored composition
 * legitimately contains the names the engine minted into it. A blanket refusal
 * therefore reported an error on the engine's own output, forever, for every v2
 * band carrying a responsive value.
 *
 * The distinguishing fact is simple: an engine mint is always referenced by
 * exactly the parameter its name encodes (`quote-typography-size-d` is referenced
 * by `quote.typography.size.d`). A token in the normalized form is that; an author
 * squatting the name is not, and is the case worth refusing — their value would be
 * silently overwritten on the next write.
 */
function _pp_udc_name_is_the_engines_own_mint(string $name, array $udc): bool {
    $parts = explode('-', $name);
    $bp    = array_pop($parts);
    $hover = end($parts) === 'hover';
    if ($hover) {
        array_pop($parts);
    }
    $groups = pp_udc_groups();
    $count  = count($parts);
    for ($g = 1; $g < $count; $g++) {
        $group = $parts[$g];
        if (!isset($groups[$group])) {
            continue;
        }
        $param = implode('-', array_slice($parts, $g + 1));
        $role  = implode('-', array_slice($parts, 0, $g));
        if ($param === '' || !isset($groups[$group]['params'][$param])) {
            continue;
        }
        $branch = $udc[$role][$group] ?? null;
        if ($hover) {
            $branch = is_array($branch) ? ($branch[PP_UDC_HOVER] ?? null) : null;
        }
        $value = is_array($branch) ? ($branch[$param] ?? null) : null;
        if (is_array($value) && isset($value[$bp]) && is_scalar($value[$bp])
            && (string) $value[$bp] === '@' . $name) {
            return true;
        }
    }
    return false;
}

function _pp_udc_is_mint_shaped_name(string $name): bool {
    $parts = explode('-', $name);
    if (count($parts) < 4) {
        return false;
    }
    $bp = array_pop($parts);
    if (!isset(pp_udc_breakpoints()[$bp])) {
        return false;
    }
    if (end($parts) === 'hover') {
        array_pop($parts);
    }
    // Walk every split of the remainder into <role...>-<group>-<param...>: group
    // and param names both contain hyphens, so the boundary is not positional.
    $groups = pp_udc_groups();
    $count  = count($parts);
    for ($g = 1; $g < $count; $g++) {
        $group = $parts[$g];
        if (!isset($groups[$group])) {
            continue;
        }
        $param = implode('-', array_slice($parts, $g + 1));
        if ($param !== '' && isset($groups[$group]['params'][$param])) {
            return true;
        }
    }
    return false;
}

/**
 * Promotes a composition item's band identity into the `$props` array a template
 * will receive.
 *
 * ONE OWNER, called from all three band loops (templates/composition.php,
 * templates/front-page.php, and the editor preview in lib/admin.php). It was three
 * copies of the same guard and the same five-line explanation, which is three
 * places for the boundary to fall out of sync — and the preview copy had already
 * needed its own separate patch once.
 *
 * `pp_get_component()` hands a template nothing but `$props`, so this is the only
 * channel available; it is the same mechanism the `__pp_style` promotion beside it
 * has always used.
 *
 * A band with no usable id promotes NOTHING, so the component emits no
 * `data-pp-band` attribute and the emitter produces no block for it: structural
 * rendering, never a borrowed design.
 */
function pp_udc_promote_band_identity(array $item, array $props): array {
    if (isset($item['id']) && is_scalar($item['id']) && pp_udc_valid_band_id((string) $item['id'])) {
        $props['__pp_udc_band'] = (string) $item['id'];
    }
    return $props;
}
