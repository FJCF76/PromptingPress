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
 * either. `:hover`, `:focus-visible` and `:active` are STATE scopes inside a
 * group, not breakpoints — a state's values may themselves be breakpoint-keyed.
 *
 * A PRESET is a named `udc` fragment the site shares: referenced by name through
 * a `_preset` key at role grain or group grain, and always overridable by the
 * band that referenced it. See pp_udc_presets().
 *
 * ── Emission ────────────────────────────────────────────────────────────────
 *
 *   [data-pp-band="pp-3f9a1c2e"]            { --pp-quote-typography-size-d: 19px; … }
 *   [data-pp-band="pp-3f9a1c2e"] .t__quote  { font-style: italic; font-size: var(…-d); }
 *   @media (max-width: 767px)  { … narrow-first … }
 *   @media (min-width: 768px) and (max-width: 1023px) { … }
 *   [data-pp-band="pp-3f9a1c2e"] .t__quote:hover { color: #000; }
 *
 * THREE SCOPES, one shape. A band's authored values scope to its id; a component's
 * role DEFAULTS scope to `[data-pp-component="<name>"]` and emit once per page; and
 * SITE CHROME — the nav and footer, which are rendered by the theme on every page
 * and never composed — scopes to `[data-pp-chrome="<name>"]` and is stored in the
 * `pp_site_udc` option rather than in a composition:
 *
 *   [data-pp-chrome="nav"]                    { background: #101828; }
 *   [data-pp-chrome="nav"] .nav__menu ul li a { color: #f7f8fa; }
 *
 * Every rule is exactly one of those three scopes plus the role's selector, so
 * specificity is flat BY CONSTRUCTION *within this engine's own emission*, and
 * `!important` is never needed or used. No v2 component emits an inline style
 * attribute.
 *
 * AND THAT FLATNESS IS NOW GLOBAL, not merely internal (#986, ruling D5 revised).
 * It is worth saying how, because the mechanism is not specificity at all.
 *
 * A band block is [0,2,0] — one attribute plus one class. The v1 stylesheet that still
 * ships carries rules well above that on elements a role can select: the premium button
 * family reaches [0,5,1], and a hero CTA authored through the `button` preset painted
 * the stylesheet's gradient instead of the author's fill. Printing the authored layer
 * after the stylesheet only settles ties, so the write was accepted, reported applied,
 * and overruled — the I35 class.
 *
 * The fix is a CASCADE LAYER, not per-rule surgery. The v1 stylesheet lives in
 * `@layer pp-v1` (base.css in `pp-reset` below it), and this engine's authored blocks
 * and in-band element defaults are UNLAYERED. Unlayered beats layered at any
 * specificity, so no v1 rule can outrank an authored value however many classes it
 * carries — and no enumeration has to stay complete for that to hold.
 *
 * The first attempt DID try per-rule surgery, and the reason it was abandoned is worth
 * keeping: wrapping four premium rules in `:where()` zeroed them against a band block
 * as intended and against `.btn` [0,1,0] as well, silently regressing every composed
 * primary button on the components not yet rebuilt. A layer moves the whole sheet at
 * once and cannot single out a rule by accident.
 *
 * TWO BOUNDED EXCEPTIONS, named rather than implied. The band-ROOT defaults tier sits
 * in `pp-zero`, BELOW the v1 sheet on purpose, so an unauthored band still obeys the
 * shared rhythm (#430/#431) — that tier is the one thing here designed to lose. And an
 * unlayered third party still outranks the v1 sheet: WP core's injected
 * `border-style: solid` now beats the issue-332 immunity baseline's `border-style:
 * none`, which is why that baseline declares `border-width: 0` as well. #989 tracks the
 * remaining v1 rules as a verification list rather than a surgery list.
 *
 *   site tokens ─▶ presets ─▶ role defaults (schema data) ─▶ band udc ─▶ breakpoint ─▶ state
 *        │            │               │                        │            │           │
 *        └────────────┴───────────────┴──── all resolve here ──┴────────────┴───────────┘
 *                                       │
 *                        pp_udc_compile_band() — carries PROVENANCE, so a value
 *                        that cannot take effect is disclosed rather than
 *                        silently dropped (invariant I35). The preset tier is
 *                        its OWN named source (`preset:<name>`), because I35/I36
 *                        need a reader to see WHICH preset a value came from and
 *                        what overrode it, not merely that some preset did.
 *
 * NOTE the ruled consequence of presets ranking UNDER role defaults: a preset
 * contributes only where the target role is silent. Applying `button` to a role
 * that already defaults its own background and border gives you the button's
 * type, padding and motion, and NOT its fill — the component's own declaration
 * wins. That is the cascade behaving as ruled, and it is why presets pay off most
 * on lightly-defaulted roles.
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

/**
 * Most emit-time drops one compile will RECORD (#981, D3).
 *
 * Bounds the ledger's ALLOCATION, which slicing the advisory's output cannot: a
 * single stored band can declare thousands of invalid parameters, and preflight
 * builds this before every mutation. Stated absolutely rather than as a ratio to
 * the advisory's row budget, because that budget lives in another file and a
 * restated relationship goes stale silently: 200 entries is far past any report
 * that could be rendered, so a composition that reaches this cap is pathological
 * rather than merely untidy.
 */
const PP_UDC_MAX_EMIT_DROPS = 200;

/**
 * Most reference locators one delete scan will COLLECT (#1016).
 *
 * The same rule, for the same reason, as the emit-drop cap above: the one consumer
 * renders a fixed-size list, so collecting every occurrence on a large site spends
 * megabytes of heap to print it. Comfortably more than either render cap (twenty
 * reference locators, ten unreadable pages), so the collection cap is never the
 * thing an operator notices, and an exact total is carried separately so a refusal
 * can still say how many there really are.
 */
const PP_UDC_MAX_PRESET_REFERENCES = 200;

/**
 * Renders a bounded list into a message, with a tail that states the TRUE total.
 *
 * ONE CONTRACT, ONE IMPLEMENTATION. Three hand-rolled copies of this shape had
 * grown across the preset work — each a slice, a comparison against the same
 * literal written twice, and a sprintf tail — and they had already drifted apart
 * on their separator (`, and` against `; and`). The repo owns the contract in
 * _pp_render_undeclared_prop_keys() (lib/admin.php), whose docblock states the
 * part that matters: "The count in the 'and N more' tail is the TRUE total, so a
 * truncated list never reads as a complete one."
 *
 * `$total` is passed rather than derived, because the callers that matter collect
 * a CAPPED sample and count separately — deriving it from the sample would report
 * the cap as the answer, which is the one thing the tail exists to prevent.
 *
 * The per-site prose comments stay where they are: they explain WHY each list is
 * bounded, which a shared helper cannot.
 */
function pp_udc_bounded_list(array $items, int $shown, int $total, string $separator = ', '): string {
    $rendered = implode($separator, array_slice($items, 0, $shown));
    if ($total > $shown) {
        $rendered .= sprintf('%sand %d more', $separator, $total - $shown);
    }
    return $rendered;
}

/**
 * Longest run of stored data any emit-drop reason reflects (#981).
 *
 * DECLARED HERE RATHER THAN REUSED FROM lib/admin.php. The obvious constant for
 * this is PP_REFLECTED_VALUE_MAX_LENGTH, and it is the wrong one to reach for:
 * it lives in lib/admin.php, which loads AFTER this file, and _pp_udc_place() is
 * the hottest loop on every front-end request. An always-loaded engine file must
 * not depend on a later one — that layering rule is why the shared cleaner itself
 * was moved down into lib/wp.php rather than called across the boundary. The
 * NUMBER is deliberately the same 100, because this is the same kind of bound on
 * the same kind of data; only its home differs.
 */
const PP_UDC_REFLECTED_MAX = 100;

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

/**
 * The STATE dimension, as data (Addendum A, ruling A3).
 *
 * A state is not a viewport. It nests inside a group, as a sibling of that
 * group's params, and its own values may themselves be breakpoint-keyed:
 *
 *   "card": {"background": {"fill": "#fff",
 *                           ":hover": {"fill": {"d": "#eee", "p": "#f6f6f6"}}}}
 *
 * `mint` is the segment this state contributes to a minted token name; it is
 * deliberately the pseudo-class without its colon, because a mint name becomes a
 * CSS custom-property name and `:` is not in that charset. NOTE that
 * `focus-visible` mints TWO hyphen segments — every reader of a mint name has to
 * pop by segment COUNT, never by popping one (see _pp_udc_state_from_mint()).
 *
 * `emit_order` is the cascade order the three states print in: hover, then
 * focus-visible, then active. All three are the same specificity, so the order
 * IS the ranking — a pressed control shows its pressed treatment rather than its
 * hover treatment because `:active` prints last. This mirrors the LVHA ordering
 * every CSS style guide has recommended since links had five states.
 *
 * WHAT IS DELIBERATELY ABSENT: `:disabled`, pseudo-ELEMENTS (`::before`), and
 * ancestor states (`:hover` on a parent changing a child). Each is its own
 * future ruling, and each is a different KIND of thing — a disabled control is a
 * semantic state the markup must also carry, a pseudo-element is a new box
 * rather than a new value for an existing one, and an ancestor state needs a
 * selector shape the flat `[data-pp-band] <role>` contract does not have. An
 * unknown state key is refused at write naming the three that exist, rather than
 * being stored and silently never emitted.
 */
function pp_udc_states(): array {
    static $states = null;
    if ($states !== null) {
        return $states;
    }
    $states = [
        ':hover'         => ['mint' => 'hover',         'emit_order' => 0],
        ':focus-visible' => ['mint' => 'focus-visible', 'emit_order' => 1],
        ':active'        => ['mint' => 'active',        'emit_order' => 2],
    ];
    return $states;
}

/** The state keys in emission order, base state (`''`) first. */
function pp_udc_states_in_emit_order(): array {
    static $ordered = null;
    if ($ordered === null) {
        $states = pp_udc_states();
        uasort($states, static fn($a, $b) => $a['emit_order'] <=> $b['emit_order']);
        $ordered = array_merge([''], array_keys($states));
    }
    return $ordered;
}

/**
 * The state a minted name's trailing segments encode, or `''` for the base state.
 *
 * Returns `[$state, $remaining_parts]`. Pops by SEGMENT COUNT, which is the whole
 * point of this function existing: `focus-visible` is two segments and the
 * single-`array_pop()` idiom that served one state named `hover` reads it as a
 * param called `…-focus` in a state called `visible`. Getting that wrong is not a
 * cosmetic bug — pp_validate_composition* runs over STORED compositions, so a
 * guard that fails to recognise the engine's OWN minted names turns every band
 * already holding one into a permanent false refusal.
 *
 * @param array $parts Name segments, breakpoint already popped.
 * @return array{0: string, 1: array}
 */
/**
 * The registered state mints as segment lists, LONGEST FIRST.
 *
 * Longest-first is what makes decoding independent of the order the registry
 * happens to declare states in. Today no mint is a suffix of another, so the two
 * orders coincide and the sort changes nothing — which is precisely why it is
 * worth having and worth testing separately: add a state whose mint ends in
 * `visible` and an order-dependent matcher strips `visible` off `focus-visible`
 * and hands back a state nobody wrote. That is not a cosmetic failure. This
 * decoding decides whether a stored token name is the engine's own, and getting
 * it wrong turns every band holding one into a permanent refusal.
 *
 * $states is an injection point for THAT test and nothing else: a review
 * specialist showed by mutation that a test using the real registry cannot
 * detect the sort's absence, because the real registry is already in longest
 * order. Only a registry whose declaration order DISAGREES with length order can
 * tell the two apart, and there is no other way to build one.
 *
 * @param array|null $states Defaults to pp_udc_states(); pass a map to test the ordering itself.
 * @return array<string, array<int, string>>
 */
function _pp_udc_state_mints_longest_first(?array $states = null): array {
    static $cached = null;
    if ($states === null && $cached !== null) {
        return $cached;
    }

    $by_length = [];
    foreach ($states ?? pp_udc_states() as $key => $meta) {
        $by_length[$key] = explode('-', (string) ($meta['mint'] ?? ''));
    }
    uasort($by_length, static fn(array $a, array $b): int => count($b) <=> count($a));

    if ($states === null) {
        $cached = $by_length;
    }
    return $by_length;
}

function _pp_udc_state_from_mint(array $parts): array {
    foreach (_pp_udc_state_mints_longest_first() as $key => $segments) {
        $count = count($segments);
        if (count($parts) > $count && array_slice($parts, -$count) === $segments) {
            array_splice($parts, -$count);
            return [$key, $parts];
        }
    }
    return ['', $parts];
}

/**
 * Keys of the `udc` map that are engine-owned rather than ROLE names.
 *
 * `_tokens` sits BESIDE the roles, at the top of the map. `_preset` is
 * engine-owned too but is NOT one of these: it lives inside a role map and
 * inside a group map, and is handled at each of those grains (see
 * PP_UDC_PRESET_KEY). Adding it here would make a role literally named
 * `_preset` skip validation, which is the opposite of what it needs.
 */
function pp_udc_reserved_keys(): array {
    return ['_tokens'];
}

/**
 * The key that names a PRESET, valid at ROLE grain and at GROUP grain.
 *
 * A BARE NAME, not an `@reference`, and the asymmetry is deliberate. `@name` has
 * exactly one meaning in this grammar — resolve against the band's `_tokens`,
 * then the site design tokens (pp_udc_resolve_reference()). Pointing the same
 * sigil at a second, unrelated namespace would make a token called `button` and a
 * preset called `button` indistinguishable in the source an author reads, which
 * is precisely the hidden aliasing invariant I36 forbids. The `_` prefix instead
 * joins the engine-owned family that already exists: `_tokens`, `_band`.
 */
const PP_UDC_PRESET_KEY = '_preset';

/**
 * A preset name is stable, not CSS: the charset matches a band token's name.
 *
 * ANCHORED WITH `\z`, NOT `$`, AND THE DIFFERENCE IS THE POINT. PCRE's `$` matches
 * before a trailing newline, so `$` admits one byte the charset does not name — a
 * gate that does not mean exactly what it says.
 *
 * It matters more here than it did in Sprint 1. Then this was a lookup-key check
 * and a name outside the charset simply failed to resolve. It is now the write gate
 * for a site-writable namespace AND the reader that admits stored keys into the
 * registry, and the names it admits are listed back in operator-facing refusals and
 * in the runtime authoring context. A gate in that position has to be exact.
 *
 * Every sibling charset gate in this file is anchored the same way rather than left
 * as the next instance — including the band id, which is interpolated into a CSS
 * selector, and the reference name, which becomes a custom property.
 *
 * STATED NARROWING: a stored name whose only defect is a trailing newline stops
 * resolving. Nothing the engine mints can have one, and no supported write path
 * produced one on purpose.
 */
function pp_udc_valid_preset_name(string $name): bool {
    return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $name);
}

/**
 * Preset names rendered for a MESSAGE or for the model's prompt, cleaned.
 *
 * The charset gate above should make this unnecessary, and that is exactly why it
 * exists. These names are listed into operator-facing refusals and into the runtime
 * authoring context, and the gate that admits them was one character away from
 * accepting a byte it does not name. A sink that depends on an upstream gate being
 * perfect fails the moment it is not — so this one cleans regardless, the same
 * posture pp_udc_preset_references() already takes with chrome names, page titles
 * and role keys.
 *
 * Reads the KEYS, so it is safe on a registry assembled from a stored row.
 */
function pp_udc_preset_names_for_message(array $presets): string {
    $names = array_map(
        static function ($name): string {
            return _pp_udc_reflect((string) $name);
        },
        array_keys($presets)
    );
    return implode(', ', $names);
}

/**
 * The internal carrier the `background.overlay` param resolves onto.
 *
 * NOT A CSS PROPERTY, and deliberately not one. An overlay scrim and a background
 * image are the SAME CSS property — `background-image` takes a comma-separated
 * layer list — so two params that both emitted `background-image` would collide in
 * `$resolved[$state][$bp][$property]` and the second would simply overwrite the
 * first. Giving the overlay its own carrier lets both resolve independently, carry
 * their own provenance, and survive the preset/defaults ranking;
 * _pp_udc_compose_background_layers() then folds the pair into the one declaration
 * CSS actually accepts. The carrier never reaches a stylesheet.
 *
 * The leading `-pp-` cannot collide with a registry property: every real entry in
 * pp_udc_groups() is a plain CSS property name.
 */
/**
 * The cascade-layer order statement, as one string (#986).
 *
 * THE ORDER IS ESTABLISHED BY WHICHEVER COPY THE BROWSER SEES FIRST, and a second copy
 * declaring the same order is a no-op — that is what makes it safe to emit more than
 * once, and it is why the editor preview emits it ahead of its stylesheet links rather
 * than trusting the copy at the top of base.css to arrive.
 *
 * The preview links its stylesheets without a cache-busting query, so a browser holding a
 * pre-#986 base.css would get a document where the statement never arrives: base.css
 * would be unlayered and therefore the STRONGEST sheet there, `pp-zero` would be created
 * implicitly at first use by the inline defaults block and sort after it, and an
 * unauthored band's root defaults would drop below the reset — the exact inversion that
 * made an unauthored hero compute `padding-top: 0px`. Silently, and in the preview only,
 * which is the surface whose whole promise is that it shows what the page will do.
 *
 * base.css keeps the literal because it is a static file; PreviewCascadeParityTest pins
 * that the two agree, so the order cannot drift between them.
 */
function pp_css_layer_order(): string {
    return '@layer pp-reset, pp-zero, pp-v1;';
}

const PP_UDC_BACKGROUND_OVERLAY_CARRIER = '-pp-background-overlay';

/**
 * What an authored band background image gets when the author says nothing else
 * (#986). These are v1's `.hero--cover` values, which are also what anyone means
 * by "put this photograph behind the band". See
 * _pp_udc_background_image_companions().
 */
const PP_UDC_BACKGROUND_IMAGE_COMPANIONS = [
    'background-size'     => 'cover',
    'background-repeat'   => 'no-repeat',
    'background-position' => 'center',
];

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
 *   single_valued
 *               opts OUT of the two dimensions every other param gets for free.
 *               Responsiveness and states are not opt-in anywhere else here — a
 *               breakpoint map is accepted for any param and a group map accepts a
 *               state sub-map — so a param that must stay one value has to say so,
 *               and BOTH gates enforce it: _pp_udc_validate_param() refuses at write
 *               and _pp_udc_place() drops at emit, because stored data does not only
 *               arrive through the write gate. Carried today by `background.image`,
 *               where ruling A2 places per-breakpoint art direction and per-state
 *               image swapping out of scope and requires them REFUSED, not ignored.
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
        // BACKGROUND. `fill` MUST stay first: it emits the `background`
        // shorthand, which resets every longhand below it, and
        // _pp_udc_property_rank() derives emission order from this list.
        'background' => ['params' => [
            // `background` (the shorthand) legitimately takes either a color or
            // a gradient image, which is exactly what the `gradient` type means
            // and exactly the shape v1's proven `--<component>-bg` slot had.
            'fill'     => $typed('background', 'gradient'),
            'position' => $typed('background-position', 'position'),
            'size'     => $typed('background-size', 'background-size'),
            'repeat'   => $typed('background-repeat', 'background-repeat'),
            // IMAGE (Addendum A, ruling A2). The author writes an attachment ID
            // and NOTHING ELSE — the engine resolves it through WordPress,
            // verifies the attachment exists and is an image, and builds the
            // `url()` itself. Author-written `url()` stays banned by
            // _pp_forbidden_css_construct(); the digits an author sends here
            // clear that gate trivially and the gate is not weakened.
            //
            // `single_valued` is the ruling's exclusions made enforceable rather
            // than hoped for. Every other param in this taxonomy becomes
            // responsive and state-able for free — _pp_udc_validate_param()
            // accepts a breakpoint map for anything, and a group map accepts a
            // state sub-map — so WITHOUT this flag, adding `image` would have
            // shipped per-breakpoint art direction and hover-swapped imagery on
            // day one, both of which ruling A2 places OUT of scope and requires
            // to be REFUSED rather than silently ignored.
            'image'    => [
                'property'      => 'background-image',
                'type'          => 'attachment_id',
                'signed'        => false,
                'max_values'    => 1,
                'keywords'      => [],
                'single_valued' => true,
            ],
            // OVERLAY. A scrim laid OVER the image in the same `background-image`
            // layer list, which is how you tint an image without an extra element
            // — and the UDC has no extra element to give, because a v2 component
            // emits no markup of the engine's choosing. Typed `gradient` (the
            // shared colour-OR-gradient union), so it accepts the theme's own
            // `@overlay-bg` token value, a literal `rgba()`, or a real gradient.
            //
            // Resolves onto a carrier rather than onto `background-image`; see
            // PP_UDC_BACKGROUND_OVERLAY_CARRIER. An overlay with no image emits
            // NOTHING: a scrim over nothing is a value that validates green and
            // paints nothing, which is the I19 class.
            'overlay'  => $typed(PP_UDC_BACKGROUND_OVERLAY_CARRIER, 'gradient'),
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
            // ASPECT-RATIO (ruling D1, #986). The one property hero's rebuild found
            // with NO home in either v2 system: no group emitted it, and the
            // structural-CSS lint is fail-closed on unlisted properties
            // (tests/js/css-lint.test.js, designOffencesIn()), so the moment a
            // component declares roles its `aspect-ratio` could be neither authored
            // here nor kept in the stylesheet. That is a capability DELETION, which
            // is the #901 class the UDC exists to end — so the param joins the
            // group rather than the capability being dropped.
            //
            // NO NEW GRAMMAR. `ratio` is the v1 slot type (#108), already owned by
            // _pp_validate_ratio() in lib/apply.php and already dispatched by
            // _pp_validate_token_value()'s `case 'ratio'`. Wiring it here is the
            // whole change; a second validator would be the forked-grammar the repo
            // architecture forbids.
            //
            // THE SLASH IS GRAMMAR HERE, NOT A DELIMITER. `16/9` carries a `/`, which
            // the shared reject set deliberately does NOT ban (only the COMMENT
            // delimiters `/*` and `*/`), so `16/9` clears _pp_forbidden_css_construct()
            // while `16/*9*/` does not. The ratio grammar itself is what rejects
            // `1/2/3`, `16//9`, `16 9` and `calc(16/9)`; zero and negative values are
            // refused on both sides of the slash, because a zero denominator paints an
            // inert declaration the browser silently drops (the I19 class).
            'aspect-ratio' => ['property' => 'aspect-ratio', 'type' => 'ratio', 'signed' => false, 'max_values' => 1, 'keywords' => []],
            // OBJECT-POSITION (#1023). The same shape as `aspect-ratio` above, found by
            // section's rebuild for the same reason: `--section-image-position` set
            // `object-position` on the v1 `.section__image` rule, no group
            // emitted that property, and the structural-CSS lint is fail-closed on
            // unlisted properties — so the moment section declares roles, the value could
            // be neither authored here nor kept in the stylesheet. That is the capability
            // DELETION the #901 class names, so the param joins the group exactly as
            // `aspect-ratio` did rather than the capability being dropped.
            //
            // NO NEW GRAMMAR, which is what makes this a repricing rather than a widening.
            // `position` is the v1 slot type already owned by _pp_validate_position()
            // (`_pp_validate_position()`) and already dispatched by _pp_validate_token_value()'s
            // `case 'position'` in that dispatcher — it is the same type
            // `background.position` has carried since Sprint 0. Only the property string
            // differs, and that is looked up from this table, never from author input.
            //
            // It sits in `sizing` rather than `background` deliberately: this property
            // positions a REPLACED ELEMENT's own content inside its box (an <img>), where
            // `background-position` places a painted layer behind any element. A role that
            // permits one does not thereby want the other, and the two are emitted on
            // different boxes.
            // `signed` is INERT on a non-length type and is `false` here only to match
            // `aspect-ratio`'s precedent above. `type: position` dispatches to
            // `_pp_validate_position()`, which sets `signed => true` for its own token
            // grammar, so NEGATIVE offsets ARE accepted (`-10px 50%`, `-5% -5%`) — which is
            // correct: they are valid CSS, they pull the crop, and the v1
            // `--section-image-position` slot accepted them too. Verified by probe rather
            // than read off the flag. Do not infer a constraint from this key on a
            // non-length param; the type's validator owns the grammar.
            'object-position' => ['property' => 'object-position', 'type' => 'position', 'signed' => false, 'max_values' => 1, 'keywords' => []],
        ]],
        // MOTION (Addendum A, ruling A3). Exactly two params, by the ruling.
        //
        // `transition-property` is NOT one of them and does not need to be: CSS's
        // initial value for it is `all`, so a duration alone animates every
        // animatable property that changes — which is what an author asking for
        // "ease this band's hover" means. A role whose STRUCTURAL css already sets
        // a `transition` shorthand has its property LIST preserved and only the
        // duration overridden, because the band block prints later.
        //
        // `timing-function` is named without the `transition-` prefix the CSS
        // property carries, matching how every other group here names params after
        // the DESIGN idea rather than the property string (`border.width`, not
        // `border-width`). The property text is looked up from this table, never
        // taken from author input.
        'motion' => ['params' => [
            'transition-duration' => ['property' => 'transition-duration',        'type' => 'duration',        'signed' => false, 'max_values' => 1, 'keywords' => []],
            'timing-function'     => ['property' => 'transition-timing-function', 'type' => 'timing-function', 'signed' => false, 'max_values' => 1, 'keywords' => []],
        ]],
    ];
    return $groups;
}

// ── LAYER 2: raw declarations (`_css`) ──────────────────────────────────────
//
// See docs/v2/LAYER-2-CONTRACT.md §2′ and §6.0. The one-line frame: Layer 2 is a
// STANDING FREEDOM GUARANTEE — raw-CSS parity with Divi's custom-CSS box — so the
// admission rule is "every property except a named few", not "a curated list".
//
// THE SECURITY CHANGE THIS MAKES, STATED WHERE IT LIVES. Everywhere else in this
// engine the CSS property text is looked up from pp_udc_groups() and an author
// "can no more influence the property text than they can invent a role". Here the
// author WRITES it, and it is interpolated into CSS source text inside a <style>
// block. v1 did exactly that with slot keys behind nothing but an isset() check,
// and the registry's docblock above records it as the mistake the registry exists
// to prevent. So the charset gate below is not a tidiness rule: it is the whole
// boundary, and it is an ALLOWLIST OF CHARACTERS, so no construct has to be
// enumerated as forbidden to be excluded.

/** The key that carries a role's raw declaration list, beside its groups. */
const PP_UDC_CSS_KEY = '_css';

/**
 * The §6.0 exclusion set: property => why it is excluded, for the refusal message.
 *
 * Broad-by-default means these five carry the whole burden of justification, so each
 * is here because it disables a mechanism rather than because it looked risky.
 *
 * WHAT IS DELIBERATELY ABSENT: every `url()`-bearing property (`background-image`,
 * `cursor`, `mask`, `filter`, `border-image`, …). They need no exclusion because
 * `url(` is refused in every VALUE by _pp_forbidden_css_construct(), which runs ahead
 * of everything. Excluding the properties too would suggest the property was the risk
 * when the value always was — and would cost an author `mask` for nothing.
 *
 * Custom properties (`--x`) are NOT listed either, and that is not an omission: the
 * charset in pp_udc_valid_css_property() cannot match them, so the exclusion is
 * structural rather than a list entry someone could forget to check.
 */
function pp_udc_css_excluded_properties(): array {
    return [
        'all' => 'it resets every other declaration in the same block — including this '
            . 'component\'s own role defaults and this band\'s other values — which makes '
            . 'emission order load-bearing in a way no disclosure could describe honestly',
        'content' => 'it puts author text into the page as rendered content through a '
            . 'styling channel, bypassing wp_kses_post() and the shared reflected-text '
            . 'cleaner every other author string passes through',
        'behavior' => 'it is a historical script-execution vector (Internet Explorer HTC behaviours)',
        '-moz-binding' => 'it is a historical script-execution vector (Gecko XBL bindings)',
        PP_UDC_BACKGROUND_OVERLAY_CARRIER => 'it is not a CSS property: it is this engine\'s '
            . 'internal carrier for the background.overlay parameter, folded into '
            . 'background-image before emission',
    ];
}

/**
 * THE PROPERTY-NAME GATE (contract §2′.1). The security centrepiece of this layer.
 *
 *     ^-?[a-z][a-z0-9-]{0,63}\z
 *
 * Every piece of that is load-bearing:
 *
 * `\z`, NOT `$`. PCRE's `$` matches before a trailing newline, so `$` would admit one
 * byte the charset does not name — on a string that is interpolated into CSS source
 * text next to a `:`. Every sibling gate in this file is anchored the same way
 * (pp_udc_valid_preset_name(), pp_udc_valid_band_id(), the `_tokens` name check).
 *
 * LOWERCASE ONLY. CSS property names are case-INsensitive, so `Color` and `color` are
 * one property with two spellings — and two spellings defeat the collision detection
 * in _pp_udc_css_param_for_property(), which is keyed on the string. Refused rather
 * than lower-cased, because I34 is reject-never-coerce, and the refusal names the
 * lowercase form so the fix is one keystroke.
 *
 * ONE OPTIONAL LEADING HYPHEN. This admits vendor prefixes (`-webkit-line-clamp`,
 * which is exactly the kind of thing a freedom guarantee has to allow) while making
 * `--custom-property` unmatchable: after the optional `-` the pattern requires a
 * LETTER, and `--x` offers another hyphen. §6.0's first exclusion is therefore
 * enforced by the charset itself rather than by a list someone could forget.
 *
 * BOUNDED AT 64, the same bound the token-name and preset-name gates carry.
 */
function pp_udc_valid_css_property(string $name): bool {
    return (bool) preg_match('/^-?[a-z][a-z0-9-]{0,63}\z/', $name);
}

/**
 * The registry parameter that already emits a given CSS property, or null.
 *
 * THE ONE PREDICATE both gates share (contract §2.1). The write gate uses it to decide
 * whether a `_css` value gets typed validation; the compiler uses it to decide what to
 * place and whether an author's group value was overridden. Two copies of this lookup
 * would let a write say "typed" and the emitter emit untyped, which is the
 * write-accept/emit-drop divergence the #570 convergence rule forbids.
 *
 * First declaration wins, matching _pp_udc_property_rank()'s own rule for the same
 * reason: no two params share a property today, and if one ever did, the earlier
 * declaration is the one the registry order was reasoned about.
 *
 * @return array|null The param definition, carrying `_group` and `_param` for messages.
 */
function _pp_udc_css_param_for_property(string $property): ?array {
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (pp_udc_groups() as $group => $definition) {
            foreach ($definition['params'] as $param_name => $param) {
                if (!isset($index[$param['property']])) {
                    $index[$param['property']] = $param + [
                        '_group' => (string) $group,
                        '_param' => (string) $param_name,
                    ];
                }
            }
        }
    }
    return $index[$property] ?? null;
}

/**
 * The parameter definition `_css` uses for one property: TYPED WHERE KNOWN (R1′.2),
 * untyped otherwise (R1′.4).
 *
 * The untyped shape is what makes "security gates only, then emit verbatim" fall out
 * of the existing engine with no new validation code at all: pp_udc_validate_value()
 * runs _pp_forbidden_css_construct(), _pp_udc_delimiters_balanced() and the empty
 * check unconditionally, and then `type => null` reaches _pp_validate_token_value()'s
 * documented "No type metadata, generic validation only" arm. `max_values => 1` keeps
 * the whole value as ONE token, so a compound like `1px solid red` is passed through
 * intact rather than split and judged word by word.
 *
 * A TYPED PROPERTY KEEPS ITS WHOLE CONTRACT, including the ones that surprise: naming
 * `background-image` here routes to the `background.image` param, so the value must be
 * a Media Library attachment id (ruling A2) and not a URL — which is the right answer
 * rather than an accident, since author-written `url()` is refused everywhere anyway.
 */
function pp_udc_css_param(string $property): array {
    $typed = _pp_udc_css_param_for_property($property);
    if ($typed !== null) {
        return $typed;
    }
    return [
        'property'   => $property,
        'type'       => null,
        'signed'     => true,
        'max_values' => 1,
        'keywords'   => [],
        'untyped'    => true,
    ];
}

/** The motion properties the engine guards under `prefers-reduced-motion`. */
function _pp_udc_motion_properties(): array {
    static $properties = null;
    if ($properties === null) {
        $properties = [];
        foreach (pp_udc_groups()['motion']['params'] as $param) {
            $properties[$param['property']] = true;
        }
    }
    return $properties;
}

/**
 * The theme's motion defaults, derived from `--transition`.
 *
 * `assets/css/base.css` declares `--transition: 150ms ease` and lib/wp.php calls
 * it the theme's ONLY `raw`-typed token. That is exactly why these two values are
 * literals rather than `@transition` references: a raw `150ms ease` satisfies
 * NEITHER the `duration` grammar nor the `timing-function` grammar, so no single
 * param can reference it and still validate.
 *
 * Literals copied out of a token are a drift hazard — retune `--transition` and
 * these silently stop matching it, which is the sort of quiet divergence I36
 * exists to prevent. So the derivation is PINNED:
 * UdcPresetStateMotionTest::testTheMotionDefaultsStillEqualTheThemesTransitionToken
 * parses
 * `--transition` out of base.css and fails if these two stop equalling its halves.
 * Splitting `--transition` into two typed site tokens would remove the copy
 * entirely; that is a token-registry change and therefore its own ruling.
 */
function pp_udc_motion_defaults(): array {
    return ['transition-duration' => '150ms', 'timing-function' => 'ease'];
}

/**
 * The SYSTEM presets — named `udc` fragments the theme ships (ruling A3).
 *
 * Sprint 1 shipped the resolution MECHANISM plus these three, and predicted that
 * Sprint 2 would merge its site-stored rows in without moving anything else about
 * the contract. That prediction held exactly (#1016): the merge is one `+` in
 * pp_udc_presets(), and the grain declaration, the name charset and the lookup
 * seam all took a custom preset unchanged. What the prediction did NOT cover, and
 * what the merge cost in the end, was the container: two live paths rebuilt the
 * site row from chrome names alone and would have deleted the preset store on
 * every chrome write. The contract did not move; the storage layer under it did.
 *
 * These three are the UN-DELETABLE half of the registry. They ship in the theme,
 * so no site write can remove one, and a custom preset may not take one of their
 * names.
 *
 * NOTE FOR WHOEVER SHIPS A FOURTH: the runtime prompt derives this list, but the
 * two preset action DESCRIPTIONS in lib/actions.php restate the three names by
 * hand — they are built at file load, before this file is loaded, so they cannot
 * interpolate. Update them in the same change.
 *
 * ── Where the values come from ──────────────────────────────────────────────
 *
 * Nothing here is invented. Every value is read off the theme's existing button
 * and link styling, and `@`-references are used wherever the source used a token,
 * so a preset FOLLOWS a retheme instead of freezing today's hexes:
 *
 *   button            assets/css/components.css:34-49  (.btn)
 *                     assets/css/components.css:62-66  (.btn:hover)
 *   button-secondary  the same, plus :84-95            (.btn--secondary)
 *   link              assets/css/base.css:381-392      (a, a:hover)
 *
 * ── Two honest omissions ────────────────────────────────────────────────────
 *
 * 1. `.btn` also sets `display:inline-block` and `cursor:pointer`. Those are
 *    STRUCTURAL, not designable, and §2 keeps structure in assets/css. So this
 *    preset carries a button's LOOK, not its layout behaviour — applying it to a
 *    <p> paints a button and does not make it act like one.
 *
 * 2. `.btn:focus-visible` is an `outline` ring, and §2 names focus rings as an
 *    accessibility affordance that lives in assets/css. The UDC has no outline
 *    group and this is not the ruling that adds one, so these presets carry
 *    `:hover` (which the theme defines) and NOT `:focus-visible` or `:active`
 *    (which it does not). Inventing state treatments here to look complete is
 *    exactly the "nothing visually invents itself" line. The state MECHANISM is
 *    proven on authored band values, which is where states are meant to be used.
 *
 * ── The padding references, and the substitution that used to be here ───────
 *
 * `.btn` reaches its padding through `--btn-padding-y` / `--btn-padding-x`, and
 * these presets now reference exactly those two tokens — so retuning a button
 * knob moves `.btn` and every preset-styled role together, which is the whole
 * point of routing a value through a token instead of copying it.
 *
 * It did not always work. Both tokens hold `var(--space-sm)` / `var(--space-lg)`
 * — a CHAIN, not a literal — and a reference used to be validated by re-parsing
 * the token's value text against the referencing param's grammar. The `length`
 * grammar is literal-only on purpose (a `var()` in a length was an
 * injection-bypass surface in v1), so `@btn-padding-y` resolved to something a
 * length parameter correctly refused, and these presets referenced `@space-sm` /
 * `@space-lg` instead — the tokens the button knobs alias. That painted
 * identically but broke the indirection: retuning `--btn-padding-x` alone moved
 * `.btn` and did NOT move a preset-styled role.
 *
 * #972 (ruling D3) removed the reason for the substitution: a reference is now
 * judged by the type the registry DECLARES for the token, so a `length`-typed
 * token is referenceable whatever its value text happens to say. Nothing is
 * inlined and no chain is followed — emission was always `var(--name)`, and the
 * browser resolves the rest. See _pp_udc_reference_check().
 */
function pp_udc_system_presets(): array {
    static $presets = null;
    if ($presets !== null) {
        return $presets;
    }

    $motion = pp_udc_motion_defaults();

    $presets = [
        'button' => [
            'grain'       => 'role',
            'description' => "The theme's primary button: accent fill, accent border, inverted ink.",
            'udc'         => [
                'typography' => [
                    'size'        => '1rem',
                    'weight'      => '600',
                    'line-height' => '1.4',
                    'decoration'  => 'none',
                    'color'       => '@color-bg',
                    ':hover'      => ['color' => '@color-bg'],
                ],
                'spacing' => [
                    'padding-top'    => '@btn-padding-y',
                    'padding-bottom' => '@btn-padding-y',
                    'padding-left'   => '@btn-padding-x',
                    'padding-right'  => '@btn-padding-x',
                ],
                'background' => [
                    'fill'   => '@color-accent',
                    ':hover' => ['fill' => '@color-accent-hover'],
                ],
                'border' => [
                    'width'  => '2px',
                    'style'  => 'solid',
                    'color'  => '@color-accent',
                    'radius' => '@btn-radius',
                    ':hover' => ['color' => '@color-accent-hover'],
                ],
                // NO `shadow` GROUP, deliberately. `.btn` sets
                // `box-shadow: var(--btn-shadow, none)`, so the shipped button has
                // no shadow and a `shadow: {box: none}` entry would paint nothing.
                // It is dropped because it is not free: `shadow` is the narrowest
                // group in the taxonomy (three of testimonials' twelve roles
                // permit it), so carrying an inert entry would make the
                // skipped-groups disclosure fire on nine roles to report that a
                // no-op was not applied. A preset declares what it means to set,
                // not what it means to leave alone.
                //
                // The 44px WCAG touch-target minimum `.btn` carries a comment
                // about. A design value here, not a structural one.
                'sizing' => ['min-height' => '44px'],
                'motion' => $motion,
            ],
        ],

        'button-secondary' => [
            'grain'       => 'role',
            'description' => 'The lower-emphasis button: muted surface fill, body ink, border-coloured edge.',
            'udc'         => [
                'typography' => [
                    'size'        => '1rem',
                    'weight'      => '600',
                    'line-height' => '1.4',
                    'decoration'  => 'none',
                    'color'       => '@color-text',
                    ':hover'      => ['color' => '@color-text'],
                ],
                'spacing' => [
                    'padding-top'    => '@btn-padding-y',
                    'padding-bottom' => '@btn-padding-y',
                    'padding-left'   => '@btn-padding-x',
                    'padding-right'  => '@btn-padding-x',
                ],
                'background' => [
                    'fill'   => '@color-surface',
                    ':hover' => ['fill' => '@color-border'],
                ],
                'border' => [
                    'width'  => '2px',
                    'style'  => 'solid',
                    'color'  => '@color-border',
                    'radius' => '@btn-radius',
                    ':hover' => ['color' => '@color-border'],
                ],
                // No `shadow` group; see the note on `button` above.
                'sizing' => ['min-height' => '44px'],
                'motion' => $motion,
            ],
        ],

        'link' => [
            'grain'       => 'role',
            'description' => "The theme's inline link: accent ink, underlined, accent-hover on hover.",
            'udc'         => [
                'typography' => [
                    'color'      => '@color-accent',
                    'decoration' => 'underline',
                    ':hover'     => ['color' => '@color-accent-hover'],
                ],
                'motion' => $motion,
            ],
        ],
    ];

    return $presets;
}

/**
 * EVERY preset this site can reference: the theme's, plus the site's own (#1016).
 *
 * THE SEAM SPRINT 1 PROMISED, built where it said it would be. Until this existed
 * the registry was a hardcoded `static` with no injection point, which left three
 * branches of the preset path unreachable from any test — the nested-preset
 * refusals, the group-grain DEFINITION branch, and, the one that mattered, the
 * WRITE GATE's half of the T2 intersect split. #974 proved that last one by
 * mutation: giving the gate a deliberately narrower second copy of the split left
 * all 5012 tests green, because no preset existed that could tell the two apart.
 * A store the tests can seed is what makes those branches provable, and it is the
 * same store custom presets needed anyway. One seam, both jobs.
 *
 * SYSTEM WINS A COLLISION, and the collision is not supposed to happen. A custom
 * preset may not TAKE a system name (the create verb refuses it, invariant I36 —
 * two different bundles behind one bare name is exactly the hidden aliasing that
 * rule forbids). The reachable case is the other direction: a theme release ships
 * a system preset whose name a site already used. Resolving that silently in
 * either direction changes what the site paints on upgrade, so the ranking is
 * deterministic AND the shadowed row is reported — see pp_udc_shadowed_presets().
 *
 * CACHED ON THE STORED BYTES, not on "have I run yet". The sibling reader
 * pp_udc_site_map() states the reason and this inherits it: a write during the
 * same request changes the bytes and the cache misses, so this can never serve a
 * stale registry back to the code that just wrote one. That is not hypothetical
 * here — a save-then-read inside one action is the ordinary path.
 *
 * THE MEMO IS THIS FUNCTION'S OWN, and it used to only LOOK like it was. Delegating
 * to pp_udc_site_map() cached the json_decode and nothing else: the get_option()
 * underneath it and the array union above it were redone on every call — 600 of
 * them per render on a 50-band page with TWELVE preset-referencing roles per band,
 * measured at 0.97-1.53 ms of avoidable work against that page's ~45 ms build. (The
 * other 50-band figures in this file are taken on different scenarios; each says
 * which, because they are not readings of the same thing.) The union
 * also costs more the more presets a site has (0.30 us at one, 1.24 us at the
 * 64-preset ceiling), so the cost grew with exactly the thing this change lets an
 * author add. Keyed on the same raw bytes, so the write-during-request property is
 * unchanged; a preset-free site still does no array work at all.
 */
function pp_udc_presets(): array {
    static $memo_raw = null;
    static $memo     = null;

    $raw = function_exists('get_option') ? get_option(PP_SITE_UDC_OPTION, '') : '';
    if (!is_string($raw)) {
        $raw = '';
    }
    if ($memo_raw === $raw && $memo !== null) {
        return $memo;
    }

    $system = pp_udc_system_presets();
    $custom = pp_udc_custom_presets();
    // `+` keeps the LEFT operand's key on a collision, so this is "system wins",
    // and it also orders the theme's three first, which is the order the refusal
    // messages and the runtime prompt list them in.
    $memo_raw = $raw;
    $memo     = $custom === [] ? $system : $system + $custom;
    return $memo;
}

/**
 * The site's own presets, read fail-closed from the site container (#1016).
 *
 * The option guard is not ceremony. pp_udc_presets() is reached from places
 * pp_udc_site_map() never was — a refusal message listing the available names,
 * the runtime prompt's preset line — and this file is loaded by tooling that has
 * the engine without the option store. No store means no custom presets, which is
 * the same answer an empty store gives and is never mistaken for one: both are
 * "the theme's three", and neither is a failed read mapped onto a valid answer.
 */
function pp_udc_custom_presets(): array {
    if (!function_exists('get_option')) {
        return [];
    }
    $site = pp_udc_site_map();
    return isset($site['presets']) && is_array($site['presets']) ? $site['presets'] : [];
}

/**
 * Custom preset names a theme-shipped preset is currently outranking (#1016).
 *
 * A SILENT RENDERING CHANGE IS THE THING THIS EXISTS TO PREVENT. The create verb
 * refuses a system name, so the only way into this state is a theme upgrade that
 * ships a name a site already used — and on that upgrade every band referencing
 * the name starts painting the theme's bundle instead of the author's, with
 * nothing anywhere saying so. Deterministic is not the same as disclosed.
 *
 * @return array<int, string>
 */
function pp_udc_shadowed_presets(): array {
    $custom = pp_udc_custom_presets();
    if ($custom === []) {
        return [];
    }
    return array_values(array_intersect(array_keys($custom), array_keys(pp_udc_system_presets())));
}

/**
 * Validates a custom preset definition, through the engine a band map uses (#1016).
 *
 * "One engine, no fork" is the ruled contract, and the only thing a preset needs
 * that a band map does not is a different SUBJECT in the message — a preset is
 * authored against no component and no role, so the band wording would name
 * things the author never wrote. _pp_udc_validate_group_map() takes that subject
 * as a parameter; everything else here is the same walk, the same grammar, the
 * same refusals.
 *
 * WHAT A PRESET IS VALIDATED AGAINST, since it has no target role. Every group in
 * the taxonomy is permitted at DEFINITION time, and applicability is decided at
 * REFERENCE time by the ruled intersect: the groups the target role permits
 * apply, the rest are skipped and disclosed, and an empty intersection refuses.
 * Refusing a wide preset here instead would be a second, stricter gate the T2
 * sub-ruling does not have — and it would make a shared bundle unusable for the
 * thing bundles are for, which is spanning roles that differ in shape.
 *
 * BAND TOKENS ARE NOT IN SCOPE, and that falls out rather than being imposed: a
 * preset belongs to the site, not to a band, so `[]` is the honest token scope and
 * an `@name` that only resolves inside some band is refused here as dangling. Site
 * tokens resolve normally, which is how the theme's own presets follow a retheme.
 *
 * THE REFUSALS HERE SPEAK THE PARAM VOCABULARY, not the prop one. Everything this
 * function rejects arrived as an ACTION PARAM (`name`, `grain`, `udc`), so it emits
 * `invalid_param_value` — the code the sibling verb already used for the same facts,
 * and the code a caller branches on to say "fix what you sent". It used to emit
 * `invalid_prop_value`, which is the COMPONENT-PROP vocabulary: the same fact
 * reported under two different codes depending on which verb you called, and one of
 * them borrowed from a different surface entirely.
 *
 * The value-grammar refusals underneath keep whatever the shared engine emits
 * (`invalid_prop_value`, `unknown_udc_group`), and that is deliberate: a bad value
 * inside the fragment is the SAME fact a band write reports, and forking its codes
 * here would be a second vocabulary for one engine.
 *
 * @param mixed $preset The stored shape: ['grain' => …, 'udc' => …, 'description' => …].
 */
function pp_udc_validate_preset_definition(string $name, $preset): ?WP_Error {
    if (!pp_udc_valid_preset_name($name)) {
        return new WP_Error('invalid_param_value', sprintf(
            'A preset name must be 1-64 characters of letters, digits, hyphen or underscore; got %s. '
            . 'The charset is the design-token charset, because a preset name is stable text, not CSS.',
            _pp_schema_value_for_message($name)
        ));
    }
    // A CUSTOM PRESET MAY NOT TAKE A THEME PRESET'S NAME (invariant I36).
    //
    // Shadowing would put two different bundles behind one bare name, and the
    // reference site cannot show which one it got. That is the hidden aliasing
    // I36 forbids, and it is the same reasoning that made `_preset` take a BARE
    // name rather than an `@` sigil: one namespace, one meaning, visible in the
    // source an author reads.
    if (isset(pp_udc_system_presets()[$name])) {
        return new WP_Error('invalid_param_value', sprintf(
            'The preset "%s" is shipped by the theme and cannot be replaced. Theme presets are: %s. '
            . 'Pick another name — anything you set on a band beside a preset already overrides it, '
            . 'so a variant does not need to shadow the original.',
            $name,
            pp_udc_preset_names_for_message(pp_udc_system_presets())
        ));
    }
    if (!is_array($preset)) {
        return new WP_Error('invalid_param_value', sprintf(
            'Preset "%s" must be an object with "grain" and "udc"; got %s.',
            $name,
            _pp_schema_value_for_message($preset)
        ));
    }
    foreach (array_keys($preset) as $key) {
        if (!in_array((string) $key, ['grain', 'udc', 'description'], true)) {
            return new WP_Error('invalid_param_value', sprintf(
                'Preset "%s" has no field %s. A preset carries "grain" (either "role" or one group '
                . 'name), "udc" (the fragment), and an optional "description".',
                $name,
                _pp_render_undeclared_prop_keys([(string) $key])
            ));
        }
    }
    if (isset($preset['description']) && !is_string($preset['description'])) {
        return new WP_Error('invalid_param_value', sprintf(
            'Preset "%s" description must be text; got %s.',
            $name,
            _pp_schema_value_for_message($preset['description'])
        ));
    }

    $groups = pp_udc_groups();
    $grain  = isset($preset['grain']) && is_string($preset['grain']) ? $preset['grain'] : '';
    if ($grain !== 'role' && !isset($groups[$grain])) {
        return new WP_Error('invalid_param_value', sprintf(
            'Preset "%s" grain must be "role" (a bundle of groups, applied beside a role\'s own) or '
            . 'one group name (applied beside that group\'s parameters); got %s. Groups: %s',
            $name,
            _pp_schema_value_for_message($preset['grain'] ?? null),
            implode(', ', array_keys($groups))
        ));
    }
    if (!isset($preset['udc']) || !is_array($preset['udc']) || $preset['udc'] === []) {
        return new WP_Error('invalid_param_value', sprintf(
            'Preset "%s" declares nothing. A preset that contributes no value is a reference that '
            . 'paints nothing, which the engine refuses wherever it can see it.',
            $name
        ));
    }
    if (isset($preset['udc'][PP_UDC_PRESET_KEY])) {
        return _pp_udc_nested_preset_error(sprintf('Preset "%s"', $name), null);
    }

    // A PRESET MAY NOT CARRY RAW DECLARATIONS (contract §6.13/§6.13a), and this has to
    // be refused HERE, before dispatch, rather than left to the group walk below.
    //
    // Today the `$permitted` list is `array_keys($groups)`, so `_css` would fail the
    // membership test by accident. That accident is exactly what cannot be relied on:
    // §1.5 requires `_pp_udc_validate_group_map()` to stop applying that test to `_css`
    // (every role carries the valve, and `_css` is in no role's `groups` list), and the
    // moment anyone implements that exemption inside the shared validator, the preset
    // path silently gains raw declarations. The band path routes around that validator
    // entirely for `_css`, which keeps the two separable — and this check is what makes
    // the separation a decision instead of a coincidence.
    //
    // WHY PRESETS ARE EXCLUDED AT ALL: a preset is a site-wide named bundle applied by
    // reference. Raw declarations inside one would be an escape nobody can attribute to
    // the band that used it — the ladder's whole point is that an escape is visible
    // where it is taken.
    if (is_array($preset['udc']) && isset($preset['udc'][PP_UDC_CSS_KEY])) {
        return new WP_Error('invalid_param_value', sprintf(
            'Preset "%s" may not carry "%s". A preset is a named bundle of DESIGN GROUP values '
            . 'shared across the site; raw CSS declarations belong on the band that needs them, '
            . 'where they stay attributable. Write "%s" in the band\'s own udc map instead.',
            $name,
            PP_UDC_CSS_KEY,
            PP_UDC_CSS_KEY
        ));
    }
    // The GRAIN half needs no check of its own: the grain gate above already refuses
    // anything that is not "role" or a registry group name, and `_css` is neither — its
    // refusal lists the grains that exist, which is the more useful message anyway. Said
    // here rather than left silent, because "presets may not carry _css at EITHER grain"
    // is the rule, and half of it being enforced somewhere else is worth one sentence.

    $subject   = sprintf('Preset "%s"', $name);
    $permitted = array_keys($groups);

    if ($grain === 'role') {
        foreach ($preset['udc'] as $group_name => $group_map) {
            $error = _pp_udc_validate_group_map(
                '', '', (string) $group_name, $group_map, $permitted, [], '', false, $subject
            );
            if ($error !== null) {
                return $error;
            }
        }
    } else {
        $error = _pp_udc_validate_group_map(
            '', '', $grain, $preset['udc'], $permitted, [], '', false, $subject
        );
        if ($error !== null) {
            return $error;
        }
    }

    // THE PER-PRESET CEILING, and it is not the same fact as the store's ceiling.
    //
    // The container's 64 KB is shared with chrome, so one enormous preset can make
    // a chrome write fail for a reason the chrome author cannot see. Two bounds,
    // two messages: this one says "this preset is too big", the store's says
    // "there are too many". Reporting a byte exhaustion as a count would send an
    // author deleting rows when the fix is to shrink one.
    $encoded = wp_json_encode($preset);
    if (!is_string($encoded)) {
        return new WP_Error('invalid_param_value', sprintf(
            'Preset "%s" could not be encoded for storage; nothing was written.',
            $name
        ));
    }
    if (strlen($encoded) > PP_SITE_PRESET_MAX_BYTES) {
        return new WP_Error('invalid_param_value', sprintf(
            'Preset "%s" is %d bytes and the limit for one preset is %d. The preset store shares a '
            . 'row with your chrome styling, so a single outsized preset would start refusing chrome '
            . 'writes. Split it into two presets, or drop the values a band can set for itself.',
            $name,
            strlen($encoded),
            PP_SITE_PRESET_MAX_BYTES
        ));
    }

    return null;
}

/**
 * Every place a preset NAME is referenced from, across the whole site (#1016).
 *
 * THE REVERSE OF THE DANGLING-REFERENCE REFUSAL. The write gate already refuses a
 * band that references a preset which does not exist; without this, DELETING a
 * preset would create exactly that state in bulk and in silence — every band
 * holding the name keeps it, the emitter drops the declarations, and nothing
 * anywhere says why the page changed.
 *
 * FAILS CLOSED, and the caller must treat it that way. A page whose composition
 * cannot be read contributes an `unreadable` entry rather than being skipped:
 * "no references found" and "I could not look" are different answers, and only
 * one of them makes a delete safe. That is invariant I9 at the scan level.
 *
 * Bounded to the delete verb. It reads one option and one meta row per
 * composition page, which is a site-sized walk on a verb an author runs rarely —
 * never on a render path, never in preflight.
 *
 * BOUNDED AT THE SOURCE, not by its reader — the rule _pp_udc_place()'s drop ledger
 * states in this same file ("Bounding here is the only place that bounds the
 * ALLOCATION"), and the one collector that had not applied it. Its one consumer,
 * delete_preset's validate arm, renders at most twenty reference locators and ten
 * unreadable pages, but BOTH arrays grew one entry per occurrence: 60,000 entries
 * and 48 MB of heap on a thousand-page site with a widely-used preset, to print
 * twenty of them. No malice needed — a popular preset on a large site is the
 * ordinary case. Both are capped here now, and both carry an exact count.
 *
 * The COUNT stays exact while the list caps, because the refusal says how many
 * places reference the preset and that number is the honest part.
 *
 * @return array{references: array<int, string>, references_total: int,
 *               unreadable: array<int, string>, unreadable_total: int}
 */
function pp_udc_preset_references(string $name): array {
    $out = [
        'references' => [], 'references_total' => 0,
        'unreadable' => [], 'unreadable_total' => 0,
    ];

    // EVERY FRAGMENT OF A LOCATOR IS CLEANED AND BOUNDED, for the reason the drop
    // ledger states one function over: these strings are built from STORED site
    // data — a page title an author typed, a role key a raw `wp option update`
    // wrote — and they ride an operator-facing refusal. A title carrying an escape
    // sequence or five thousand characters would otherwise reach a terminal
    // through a delete that was refused for an unrelated reason.
    $site = pp_udc_site_map();
    foreach (($site['chrome'] ?? []) as $chrome_name => $map) {
        foreach (_pp_udc_map_references_preset(is_array($map) ? $map : [], $name) as $where) {
            $out['references_total']++;
            if (count($out['references']) < PP_UDC_MAX_PRESET_REFERENCES) {
                $out['references'][] = sprintf(
                    'site chrome "%s" %s',
                    _pp_udc_reflect((string) $chrome_name),
                    $where
                );
            }
        }
    }

    if (!function_exists('pp_composition_pages')) {
        return $out;
    }
    // FRESH, AND INCLUDING THE TRASH. pp_composition_pages() memoizes for the
    // request and excludes trashed pages — both right for the listings that call
    // it, both wrong for a gate. A page missing from a list cached earlier in this
    // request is a reference this scan would not see; and a TRASHED page's
    // references are dormant rather than gone, because `restore_page` is a shipped
    // verb. Either would certify a delete that a later untrash turns into a page
    // full of dangling references.
    foreach (pp_composition_pages_for_reference_gate() as $page) {
        $id = (int) ($page['id'] ?? 0);
        // THE AUTHORITATIVE READ, because this is a GATE and not a report.
        // pp_composition_db_handle()'s docblock draws the line: readers may
        // degrade to the cached value, gates may not be OPENED by one. A delete
        // that cleared on a stale cached composition would miss a reference a
        // concurrent write had just added and certify the removal anyway.
        $result = function_exists('pp_get_composition_result_authoritative')
            ? pp_get_composition_result_authoritative($id)
            : (function_exists('pp_get_composition_result') ? pp_get_composition_result($id) : null);
        // HOISTED OUT OF THE PER-REFERENCE LOOP. The title is fixed for the whole
        // page, and _pp_udc_reflect() runs a Unicode regex and an mb-aware
        // truncation — recomputing it per reference was 240 identical passes over
        // one string on a page with 240 of them, and hoisting it took 25% off the
        // scan's PHP time on a 30,000-reference measurement.
        $title = _pp_udc_reflect((string) ($page['title'] ?? ''));
        if (!is_array($result) || empty($result['ok'])) {
            // UNREADABLE IS NOT EMPTY. A page whose bytes nobody could decode may
            // hold the reference, and "I could not look" must never be reported as
            // "there is nothing there" (invariant I9) — the caller refuses on it.
            $out['unreadable_total']++;
            if (count($out['unreadable']) < PP_UDC_MAX_PRESET_REFERENCES) {
                $out['unreadable'][] = sprintf('page %d ("%s")', $id, $title);
            }
            continue;
        }
        foreach ((array) ($result['composition'] ?? []) as $i => $item) {
            if (!is_array($item) || !isset($item['udc']) || !is_array($item['udc'])) {
                continue;
            }
            $band = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : ('index ' . $i);
            foreach (_pp_udc_map_references_preset($item['udc'], $name) as $where) {
                $out['references_total']++;
                if (count($out['references']) < PP_UDC_MAX_PRESET_REFERENCES) {
                    $out['references'][] = sprintf(
                        'page %d ("%s") band %s %s',
                        $id,
                        $title,
                        _pp_udc_reflect($band),
                        $where
                    );
                }
            }
        }
    }
    return $out;
}

/**
 * The places inside ONE `udc` map that name a preset, at either grain.
 *
 * Both grains, because both are references and a delete that only looked at one
 * would leave the other dangling — which is the failure this scan exists to
 * prevent, rebuilt out of a half-done walk.
 *
 * @return array<int, string>
 */
function _pp_udc_map_references_preset(array $udc, string $name): array {
    $found = [];
    foreach ($udc as $role_name => $role_map) {
        if (in_array((string) $role_name, pp_udc_reserved_keys(), true) || !is_array($role_map)) {
            continue;
        }
        if (($role_map[PP_UDC_PRESET_KEY] ?? null) === $name) {
            $found[] = sprintf('role "%s"', _pp_udc_reflect((string) $role_name));
        }
        foreach ($role_map as $group_name => $group_map) {
            if (is_array($group_map) && ($group_map[PP_UDC_PRESET_KEY] ?? null) === $name) {
                $found[] = sprintf(
                    'role "%s" group "%s"',
                    _pp_udc_reflect((string) $role_name),
                    _pp_udc_reflect((string) $group_name)
                );
            }
        }
    }
    return $found;
}

/**
 * Resolves a preset name to its fragment, or null when nothing carries that name.
 *
 * THE ONE LOOKUP POINT, and the reason it exists as its own function rather than
 * an array read at the call sites: site-stored custom presets merge in
 * pp_udc_presets() and nowhere else, so every consumer of a preset — the write
 * gate, the emitter, the disclosure — sees the same registry without any of them
 * learning that a second source exists. Returning null rather than an empty
 * fragment is the same discipline pp_udc_resolve_reference() keeps — a failed
 * read is never mapped to a valid answer (invariant I9), so the caller refuses
 * instead of silently applying nothing.
 *
 * @return array{grain: string, udc: array}|null
 */
function pp_udc_resolve_preset(string $name): ?array {
    $presets = pp_udc_presets();
    return isset($presets[$name]) ? $presets[$name] : null;
}

/**
 * The fragment a `_preset` reference contributes at one grain.
 *
 * At ROLE grain the fragment is a map of groups, used whole.
 *
 * At GROUP grain the fragment is a map of params for ONE group. A group-grain
 * preset supplies it directly; a ROLE-grain preset is PROJECTED onto the group
 * being referenced — "give this role the button preset's typography, nothing
 * else" — which is what makes group-grain reachable with the three system
 * presets Sprint 1 ships, while leaving Sprint 2's own group-grain presets a
 * seam that needs no reshaping.
 *
 * @param string $grain 'role' or a group name.
 * @return array|null   The fragment, or null when this preset says nothing here.
 */
function _pp_udc_preset_fragment(array $preset, string $grain): ?array {
    $udc = isset($preset['udc']) && is_array($preset['udc']) ? $preset['udc'] : [];
    if ($grain === 'role') {
        return ($preset['grain'] ?? '') === 'role' ? $udc : null;
    }
    if (($preset['grain'] ?? '') === 'role') {
        return isset($udc[$grain]) && is_array($udc[$grain]) ? $udc[$grain] : null;
    }
    return $udc; // Already a group-grain fragment.
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
            // The registry's DECLARED type (#972, ruling D3). Carried so a caller
            // can ask what the token IS rather than re-parsing what it currently
            // SAYS — the difference that decides whether a token holding one level
            // of `var()` indirection is referenceable. Only the site registry
            // declares types; band `_tokens` and the shared base.css properties are
            // untyped, so they carry null and keep value-parsing.
            'type'  => isset($site[$key]['type']) ? (string) $site[$key]['type'] : null,
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

/**
 * Which PARAM types a token of a given DECLARED type satisfies (#972, ruling D3).
 *
 * The registry declares exactly six types — `color`, `length`, `font-family`,
 * `number`, `shadow`, `raw` — so this table is small and closed by construction.
 * `raw` is deliberately ABSENT rather than mapped to nothing: absence is what
 * _pp_udc_reference_check() turns into the stated refusal below, and an empty
 * list would read as "satisfies nothing yet" rather than "declares no usable
 * grammar".
 *
 * THIS TABLE GOVERNS ONLY CHAIN-HOLDING TOKENS. _pp_udc_reference_check() sends
 * every literal-valued token to the value parser exactly as before, so a row here
 * can only ever widen the five chain-holders' reach — never narrow anything that
 * worked before. Rows are therefore written to match what CSS genuinely accepts,
 * not to mirror the value parser's quirks: `length-or-none` is `length` plus the
 * keyword `none`, `gradient` is the colour-OR-gradient union (`background.fill`
 * takes a plain colour today), and `line-height` and `background-position` both
 * take a length as readily as their own native forms.
 */
function pp_udc_reference_type_table(): array {
    // Built once, like every other table in this file (pp_udc_groups(),
    // pp_udc_system_presets(), pp_udc_breakpoints(), …). NOT pp_udc_presets(),
    // which is memoised on the stored option bytes and rebuilds when they change. _pp_udc_reference_check() runs
    // per reference per breakpoint inside the emitter's placement loop, so a
    // rebuilt literal here is pure allocation on a hot path.
    static $table = null;
    if ($table !== null) {
        return $table;
    }
    $table = [
        'color'       => ['color', 'gradient'],
        'length'      => ['length', 'length-or-none', 'line-height', 'position'],
        'font-family' => ['font-family'],
        'number'      => ['font-weight', 'line-height', 'ratio'],
        'shadow'      => ['shadow'],
    ];
    return $table;
}

/**
 * THE ONE PREDICATE for "may this reference stand in for this parameter?".
 *
 * ── Why a declared type and not the value's text (#972, ruling D3) ──────────
 *
 * A reference is NEVER value-inlined: `@space-sm` emits `var(--space-sm)` and
 * `@color-accent` emits `var(--color-accent)`. The token's stored text was only
 * ever used to ANSWER A QUESTION about it, and re-parsing that text asks the
 * wrong one the moment a token holds one level of indirection.
 *
 * Five shipped tokens do: `--btn-padding-y`/`-x` hold `var(--space-sm)`/
 * `var(--space-lg)`, and `--btn-text`/`--text-meta-color`/`--text-kicker-color`
 * hold `var(--color-bg)`/`var(--color-muted)`/`var(--color-accent)`. Under
 * value-parsing the three colours were ACCEPTED (the colour grammar happens to
 * take a bare registered `var()`) and the two lengths were REFUSED (the length
 * grammar is literal-only, deliberately — a `var()` in a length was an
 * injection-bypass surface in v1). Same shape of token, opposite answers,
 * decided by a grammar quirk rather than by a rule: the hidden divergence I36
 * forbids. The registry already knows `--btn-padding-y` IS a `length`.
 *
 * ── What this does NOT change ───────────────────────────────────────────────
 *
 * Nothing is inlined and no chain is followed: emission is still `var(--name)`,
 * so deterministic minting (§3.1) and the provenance the cascade carries (I35)
 * are untouched. The author-facing `var()` ban does not move an inch — this is
 * about a REFERENCE to a registered token, never about author-written text,
 * which still meets `_pp_forbidden_css_construct()` and the literal-only length
 * grammar exactly as before. And a type MISMATCH still refuses: `@color-accent`
 * from a length parameter is as dead as it ever was.
 *
 * ── raw ─────────────────────────────────────────────────────────────────────
 *
 * `--transition` is the registry's only `raw` token (`150ms ease`, a duration
 * plus an easing in one string). It satisfies neither `duration` nor
 * `timing-function`, and under the table above it declares no usable type at
 * all. It is refused with a STATED REASON rather than by a grammar accident, so
 * an author reads why instead of inferring it from a parse failure. Splitting it
 * into two typed tokens would make it referenceable and is its own token-registry
 * ruling (#972 option C), deliberately not folded in here.
 *
 * @param array $resolved A pp_udc_resolve_reference() result.
 * @param array $param    The parameter definition being validated against.
 * @return true|WP_Error
 */
function _pp_udc_reference_check(array $resolved, array $param) {
    $declared = (string) ($resolved['type'] ?? '');
    $value    = $resolved['value'];

    // `raw` FIRST, so its refusal is a STATED REASON rather than a grammar
    // accident. `--transition` (`150ms ease`) would fail the duration parse below
    // anyway, but the author would then be reading a message about time units for
    // a token that is simply not referenceable by a typed parameter at all.
    if ($declared === 'raw') {
        return new WP_Error('invalid_udc_value', sprintf(
            'it is a "raw"-typed design token, which declares no single CSS grammar '
            . '(its value "%s" is a compound), so it cannot be referenced by a typed '
            . 'parameter. Set this parameter to a literal value instead.',
            // BOUNDED AND CLEANED like every other value this engine reflects
            // (_pp_udc_place() does the same with the stored value). A registry
            // token's text has no length limit of its own and a `raw` token is
            // injection-checked only, so it can still carry invisible formatting.
            _pp_udc_reflect($value)
        ));
    }

    // THE DECLARED TYPE RESCUES A CHAIN, AND NOTHING ELSE. Everything whose value
    // is a literal keeps being judged exactly as before — so the ~50 literal-valued
    // tokens are bit-for-bit unaffected and only the five chain-holders move.
    //
    // This ordering is deliberate and was corrected after the sweep test caught the
    // first cut widening things it should not have. Judging by declared type FIRST
    // would have accepted five `initial`-valued button sentinels (`--btn-bg`,
    // `--btn-shadow`, …) that declare `color`/`shadow` but hold the CSS-wide
    // keyword `initial`, which their own grammars refuse. Those refusals are
    // correct and predate this ruling; a type-first gate would have quietly turned
    // them into acceptances, which is an extension of the accepted surface this
    // ruling did not ask for.
    if (strpos($value, 'var(') === false) {
        return pp_udc_validate_value($value, $param);
    }

    // Built only now: the two returns above never read it, and the literal-value
    // one is the overwhelmingly common path.
    $table = pp_udc_reference_type_table();

    // UNTYPED chain-holder (a band `_token`, or a shared base.css property). No
    // declaration to trust, so the value is still the only evidence.
    if ($declared === '' || !isset($table[$declared])) {
        return pp_udc_validate_value($value, $param);
    }

    $wanted = (string) ($param['type'] ?? '');
    if (in_array($wanted, $table[$declared], true)) {
        return true;
    }

    return new WP_Error('invalid_udc_value', sprintf(
        'it is a "%s"-typed design token and this parameter takes a "%s" value.',
        $declared,
        $wanted
    ));
}

// ── Background images ───────────────────────────────────────────────────────

/**
 * THE ONE PREDICATE for "does this background image paint, and as what URL?".
 *
 * Returns the CSS-ready, escaped URL for an attachment that exists on this
 * install and is a displayable image, or null. Null means EXACTLY ONE thing at
 * every caller: this ID does not resolve to a paintable image right now.
 *
 * THREE CALLERS, ONE ANSWER, AND THAT IS THE POINT — the same reason
 * pp_style_declaration_renders() (lib/wp.php) was extracted and the same reason
 * _pp_udc_split_preset_by_permitted() has both of its callers go through it:
 *
 *   1. the WRITE gate      -> refuses a dangling ID, naming it;
 *   2. the EMITTER         -> drops the declaration when the attachment is gone;
 *   3. the PREFLIGHT advisory -> tells the operator which stored ID stopped painting.
 *
 * Three hand-rolled copies would be three grammars, and the one that drifted
 * would either drop an image that was fine or stay silent about one that was not.
 *
 * EXISTENCE IS pp_is_image_attachment(), NOT A SECOND RULE. That predicate
 * (lib/wp.php) is already the repo's canonical attachment check — positive int,
 * post type `attachment`, and wp_attachment_is_image() — and it is what
 * pp_logo_id, site_icon, pp_footer_logo_id and pp_og_image are all validated
 * against. A background image is the same question, so it gets the same answer.
 *
 * WHY THE URL IS NOT CHECKED AGAINST home_url(). The ruling says the engine
 * builds the url() "from the escaped same-install URL", and same-install is
 * satisfied HERE, by construction: the ID was just proved to be an attachment on
 * this install, and the URL is WordPress's own answer for it. A host-equality
 * test on top of that would add no safety — the string is never author input —
 * while breaking every legitimate setup where WordPress resolves media to
 * another host: a CDN-backed uploads dir, a domain-mapped multisite, an offloaded
 * media plugin. Refusing those would be a product defect dressed as a guard.
 *
 * ESCAPING IS pp_esc_image_src(), the repo's one CSS-url()-context escaper: it is
 * esc_url() PLUS the `)` -> `%29` step that exists precisely because esc_url()
 * permits a literal `)` that would close a url() token early. The result is
 * emitted QUOTED by the caller, which is the narrower sink again.
 *
 * @param  mixed  $id  Whatever was stored. Only a positive-integer-shaped scalar
 *                     can resolve; everything else is null, never a coercion.
 * @return string|null Escaped URL, or null when nothing paints.
 */
function pp_udc_background_image_url($id): ?string {
    // REJECT, NEVER COERCE (invariant I34), and the cast is the trap this avoids:
    // `(int) ['attachment_id' => 42]` and `(int) true` both evaluate to 1, so a
    // stored array or boolean would silently become "attachment 1". The shape is
    // checked before the cast, not by it.
    if (!is_scalar($id) || is_bool($id)) {
        return null;
    }
    $raw = trim((string) $id);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return null;
    }
    $attachment_id = (int) $raw;
    if (!function_exists('pp_is_image_attachment') || !pp_is_image_attachment($attachment_id)) {
        return null;
    }
    $url = wp_get_attachment_url($attachment_id);
    // A live attachment whose URL cannot be built is still "does not paint".
    // wp_get_attachment_url() returns false when the upload dir is unreachable.
    if (!is_string($url) || $url === '') {
        return null;
    }
    $escaped = pp_esc_image_src($url);
    return $escaped === '' ? null : $escaped;
}

/**
 * Folds the resolved `image` and `overlay` entries into the one CSS declaration
 * that can express them, and removes the carrier.
 *
 * CSS gives an element ONE `background-image`, holding a comma-separated layer
 * list painted FIRST-LAYER-ON-TOP. So a scrim over a photograph is
 * `background-image: <scrim>, url(<photo>)` — the overlay first, the image
 * second. Verified in real Chromium before this was written (a prototype at
 * three viewports, per the pipeline's mechanism-prototype rule): the scrim
 * composites over the image, and a single `background-size: cover` applies to
 * both layers, so the overlay tracks the image exactly with no second knob.
 *
 * THE COLOUR CASE NEEDS WRAPPING AND THE GRADIENT CASE DOES NOT. A background
 * layer must be an <image>; a bare colour is not one. `rgba(0,0,0,.55)` therefore
 * becomes `linear-gradient(rgba(0,0,0,.55), rgba(0,0,0,.55))` — the standard
 * flat-scrim idiom — while an overlay the author already wrote as a gradient IS
 * a layer and is used as-is. Wrapping a gradient in a gradient would be invalid.
 *
 * AN OVERLAY WITH NO IMAGE EMITS NOTHING. It is not an error — a band can carry
 * an overlay whose image was dropped at emit because the attachment was deleted,
 * and that band should keep painting its `fill`, not grow a mystery scrim over
 * it. Emitting the scrim alone would be a declaration the author never asked for.
 */
function _pp_udc_compose_background_layers(array $declarations): array {
    if (!array_key_exists(PP_UDC_BACKGROUND_OVERLAY_CARRIER, $declarations)) {
        return $declarations;
    }
    $overlay = $declarations[PP_UDC_BACKGROUND_OVERLAY_CARRIER];
    unset($declarations[PP_UDC_BACKGROUND_OVERLAY_CARRIER]);

    if (!isset($declarations['background-image'])) {
        return $declarations; // Scrim over nothing: drop it.
    }

    // DECIDE FROM THE LITERAL, EMIT THE CSS. The two are different strings whenever
    // the overlay came through a token, and choosing on the wrong one is a silent
    // page-breaking bug rather than a cosmetic slip.
    //
    // `css` is what reaches the stylesheet; for a token it is `var(--pp-name)` or
    // `var(--color-x)`. `literal` is the value that token RESOLVES to. Asking
    // _pp_validate_color() about the css text answers a different question: a BAND
    // token's `var(--pp-name)` is never in pp_design_tokens(), so the colour check
    // says no, the wrapper is skipped, and a bare custom property is spliced into a
    // layer list — `background-image: var(--pp-scrim), url(...)`. A custom property
    // holding a colour is not an <image>, so the WHOLE list is invalid and the
    // browser drops the declaration: the scrim and the photograph both disappear.
    //
    // That is reachable on a fully supported path, not an exotic one. `overlay` is
    // deliberately breakpoint-keyable, and ANY responsive value is minted into a band
    // token by the normalizer — so `{"overlay": {"d": "...", "p": "..."}}` beside an
    // image hit it every time. The site-token case (`@overlay-bg`) happened to work,
    // which is exactly why it survived the first round of tests.
    //
    // Deciding on the literal wraps correctly and still emits the reference, so the
    // value keeps following its token: `linear-gradient(var(--pp-scrim),var(--pp-scrim))`.
    $css     = (string) $overlay['css'];
    $literal = (string) ($overlay['literal'] ?? $css);
    $layer   = (function_exists('_pp_validate_color') && _pp_validate_color($literal))
        ? 'linear-gradient(' . $css . ',' . $css . ')'
        : $css;

    $declarations['background-image']['css'] =
        $layer . ',' . $declarations['background-image']['css'];
    return $declarations;
}

/**
 * The three companions an authored background image needs to behave like one.
 *
 * WHY THE ENGINE SUPPLIES THEM (#986). v1's `.hero--cover` carried
 * `background-size: cover; background-repeat: no-repeat; background-position: center`
 * in structural CSS. On v2 a band background image is `_band.background.image`, which
 * ANY layout may carry — so the rule keyed to `.hero--cover` could not follow it, and
 * without it an authored image painted at its intrinsic size, tiled, anchored
 * top-left. That is not a narrowing an author would choose; it is a trap. CSS's
 * initial values are the wrong default for a band background, and v1's behaviour is
 * the recorded baseline.
 *
 * DEFAULTS, NOT OVERRIDES. Each companion is added ONLY when the author did not set
 * it, so `{"image": 42, "size": "contain"}` still means contain. The params already
 * exist and stay fully authorable (`background.size`, `.repeat`, `.position`).
 *
 * APPENDED LAST, and that is load-bearing rather than tidy: a `background` shorthand
 * from `background.fill` RESETS these longhands to their initial values, so a
 * companion emitted before it would be silently erased — the documented ordering trap
 * that `_pp_udc_sort_declarations()` exists to manage. Printing after the shorthand is
 * what makes them stick.
 *
 * They carry their own `source`, so the write path can disclose that the engine
 * supplied them rather than leaving an author to infer it from the rendered page.
 */
function _pp_udc_background_image_companions(array $declarations): array {
    if (!isset($declarations['background-image'])) {
        return $declarations;
    }
    foreach (PP_UDC_BACKGROUND_IMAGE_COMPANIONS as $property => $value) {
        if (isset($declarations[$property])) {
            continue;
        }
        $declarations[$property] = [
            'css'     => $value,
            'literal' => $value,
            'source'  => 'engine-companion',
        ];
    }
    return $declarations;
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

    // ATTACHMENT IDS ARE NOT CSS, AND MUST NOT REACH THE CSS TYPE SWITCH.
    //
    // This branch is ahead of the dispatch below for a concrete reason, not for
    // tidiness: _pp_validate_token_value() has no `attachment_id` case, and its
    // default arm is a PERMISSIVE PASS: it has no case for the non-CSS types
    // ('string', 'bool', 'attachment_id'), so an unrecognised type falls through
    // accepting anything. Routing `background.image` through it would accept any
    // string at all, which is the opposite of a referential check.
    //
    // The refusal NAMES THE ID, per ruling A2. An author who wrote 41 for 42
    // needs to see 41 in the message; "invalid value" would send them to the
    // wrong question.
    if (($param['type'] ?? '') === 'attachment_id') {
        $raw = trim($value);
        if (!preg_match('/^[0-9]+$/', $raw) || (int) $raw <= 0) {
            return new WP_Error('invalid_udc_value', sprintf(
                'Value must be a Media Library attachment ID (a positive whole number), got "%s". '
                . 'Pass the numeric id `import_media` returned, never a URL or a file path — '
                . 'the engine builds the CSS url() itself.',
                $raw
            ));
        }
        if (pp_udc_background_image_url($raw) === null) {
            return new WP_Error('invalid_udc_value', sprintf(
                'Attachment %d is not a Media Library image on this site, so it cannot be used as a '
                . 'background. Import the image first (`import_media` returns its attachment_id), '
                . 'then set that id.',
                (int) $raw
            ));
        }
        return true;
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
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]{1,64}\z/', (string) $name)) {
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
                    . '(<role>-<group>-<param>[-<state>]-<breakpoint>, where <state> is one of %s). '
                    . 'Pick another name: a collision would have the engine overwrite the value you declared.',
                    $component,
                    $name,
                    implode(', ', array_column(pp_udc_states(), 'mint'))
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

        // ROLE-GRAIN preset. Validated BEFORE the role's own groups, because a
        // dangling reference is a fact about the map that should be reported
        // ahead of anything downstream of it.
        if (array_key_exists(PP_UDC_PRESET_KEY, $role_map)) {
            $error = _pp_udc_validate_preset_reference(
                $component, $role_name, null, $role_map[PP_UDC_PRESET_KEY], 'role', $permitted
            );
            if ($error !== null) {
                return $error;
            }
        }

        foreach ($role_map as $group_name => $group_map) {
            if ($group_name === PP_UDC_PRESET_KEY) {
                continue; // Already validated above.
            }
            // LAYER 2. Not a group, and deliberately not routed through the group
            // validator: that function is shared with presets and chrome, and its
            // `$permitted` check is exactly what `_css` must NOT be subject to
            // (§1.5 — every role carries the valve). Threading an exemption through
            // it would have made the preset path accept `_css` as a side effect,
            // which §6.13a shows is a silent hole.
            if ((string) $group_name === PP_UDC_CSS_KEY) {
                $error = _pp_udc_validate_css_map(
                    $component, $role_name, $group_map, $band_tokens, ''
                );
                if ($error !== null) {
                    return $error;
                }
                continue;
            }
            $error = _pp_udc_validate_group_map(
                $component, $role_name, (string) $group_name, $group_map, $permitted, $band_tokens, '', true
            );
            if ($error !== null) {
                return $error;
            }
        }
    }

    return null;
}

/**
 * Splits a ROLE-GRAIN preset fragment against what the target role permits.
 *
 * THE ONE PREDICATE FOR INTERSECT SEMANTICS (orchestrator ruling, T2). A preset
 * is a bundle; a role declares which groups it accepts. When the bundle is wider
 * than the role, the reference applies the groups that fit and skips the rest
 * rather than being refused whole. That is what makes a shared preset usable
 * across roles that differ in shape, and it is the same "fill in where you can"
 * semantic the tier already has against role defaults.
 *
 * BOTH CALLERS GO THROUGH HERE, and that is the point. The write gate uses it to
 * decide what to validate and what to disclose; the compiler uses it to decide
 * what to emit. Two copies of this split would let a write say "shadow skipped"
 * and the emitter paint a shadow anyway — a divergence between what the author
 * was told and what the page does, which is the exact class the write-path
 * honesty invariants exist to close.
 *
 * @return array{applied: array<string, mixed>, skipped: array<int, string>}
 */
function _pp_udc_split_preset_by_permitted(array $fragment, array $permitted): array {
    $applied = [];
    $skipped = [];
    foreach ($fragment as $group => $map) {
        $group = (string) $group;
        if ($group === PP_UDC_PRESET_KEY) {
            continue; // Nested presets are refused separately; never applied.
        }
        if (in_array($group, $permitted, true)) {
            $applied[$group] = $map;
            continue;
        }
        $skipped[] = $group;
    }
    return ['applied' => $applied, 'skipped' => $skipped];
}

/**
 * The one sentence the "presets resolve one level only" rule produces.
 *
 * It used to be three refusals in two wordings, so the same author mistake read
 * differently depending on which grain it was written at. One rule, one message.
 */
function _pp_udc_nested_preset_error(string $where, ?string $name): WP_Error {
    return new WP_Error('invalid_prop_value', $name === null
        ? $where . ' may not reference a preset here. Presets resolve one level only.'
        : sprintf(
            '%s references the preset "%s", which itself references another preset. Presets resolve one level only.',
            $where,
            $name
        ));
}

/**
 * Validates one `_preset` reference, at role grain or group grain.
 *
 * THE REFUSAL NAMES THE REFERENCE. That is the whole point of validating here
 * rather than letting the emitter quietly find nothing: ruling A3 calls this "the
 * @ref discipline one level up", and one level down (pp_udc_resolve_reference())
 * a dangling `@name` is already a refusal that prints the name. A preset that
 * does not exist is the same failure with a value MAP on the other end instead of
 * a scalar, so it gets the same treatment.
 *
 * AT ROLE GRAIN THE FRAGMENT IS INTERSECTED WITH WHAT THE ROLE PERMITS
 * (orchestrator ruling, T2 — pending maintainer review). A preset is a bundle and
 * a role declares which groups it accepts; when the bundle is wider, the groups
 * that fit apply and the rest are skipped rather than the whole reference being
 * refused. Refusing whole made the shipped `button` preset writable on two of
 * testimonials' twelve roles, which is not a contract anyone can build on.
 *
 * Two rules keep that honest, and neither is optional:
 *
 *   - THE SKIP IS DISCLOSED. pp_udc_composition_findings() emits
 *     `udc_preset_groups_skipped` on the write envelope naming the role, the
 *     preset, what was skipped and what was applied. A partial apply is fine; a
 *     silent one is the reported-success-without-effect class I35 forbids.
 *   - AN EMPTY INTERSECTION REFUSES, right here, naming both sides. Otherwise the
 *     semantics degrade into a fully silent no-op in exactly the case where the
 *     author is most wrong about what they asked for.
 *
 * Whatever survives the intersection is then validated with the same param names
 * and the same value grammar an inline map gets — so a preset is never a WIDER
 * door than writing the same map by hand. It is deliberately narrower in one
 * place: `@` references resolve against SITE tokens only, because a preset belongs
 * to the site and not to any band, so a band-local token accepted inline is
 * refused through a preset. The COMPILER intersects through
 * the same predicate (see _pp_udc_split_preset_by_permitted), so what the envelope
 * says was skipped is what the page actually omits.
 *
 * At GROUP grain there is nothing to intersect: the author named one group, and
 * if the role does not permit it that is a refusal like any other.
 *
 * NO `$band_tokens` PARAMETER, deliberately. It used to take one and thread it
 * into the group validator; both hand-offs now pass `[]`, because a preset sees
 * SITE tokens only — the same scope its definition gate and the emitter use.
 * Keeping a parameter the body no longer reads would state the opposite rule to
 * the next person who opens this signature.
 *
 * @param string|null $group      Group name for group-grain, null for role-grain.
 * @param string      $grain      'role', or the group name being projected onto.
 * @return WP_Error|null
 */
function _pp_udc_validate_preset_reference(
    string $component,
    string $role,
    ?string $group,
    $value,
    string $grain,
    array $permitted
): ?WP_Error {
    $where = $group === null
        ? sprintf('Component "%s" role "%s" "%s"', $component, $role, PP_UDC_PRESET_KEY)
        : sprintf('Component "%s" role "%s" group "%s" "%s"', $component, $role, $group, PP_UDC_PRESET_KEY);

    if (!is_string($value) || !pp_udc_valid_preset_name($value)) {
        return new WP_Error('invalid_prop_value', sprintf(
            '%s must be a preset name of 1-64 characters of letters, digits, hyphen or underscore; got %s.',
            $where,
            _pp_schema_value_for_message($value)
        ));
    }

    $preset = pp_udc_resolve_preset($value);
    if ($preset === null) {
        return new WP_Error('invalid_prop_value', sprintf(
            '%s references the preset "%s", which does not exist. Available presets: %s',
            $where,
            $value,
            pp_udc_preset_names_for_message(pp_udc_presets()) ?: '(none)'
        ));
    }

    $fragment = _pp_udc_preset_fragment($preset, $grain);
    if ($fragment === null || $fragment === []) {
        return new WP_Error('invalid_prop_value', sprintf(
            '%s references the preset "%s", which declares nothing for %s.',
            $where,
            $value,
            $grain === 'role' ? 'a whole role' : 'the group "' . $grain . '"'
        ));
    }

    // A preset may not reference a preset. One level, no cycles to detect and
    // nothing to unwind — the identical discipline a band token already keeps
    // (see the `_tokens` loop above).
    if ($grain === 'role') {
        if (isset($fragment[PP_UDC_PRESET_KEY])) {
            return _pp_udc_nested_preset_error($where, $value);
        }

        $split = _pp_udc_split_preset_by_permitted($fragment, $permitted);

        // THE EMPTY INTERSECTION REFUSES. Intersect semantics must never degrade
        // into a fully silent no-op: a reference that contributes nothing is an
        // authoring input with no effect, which is precisely what I35 forbids
        // accepting quietly. Same posture as a dangling reference — refuse, and
        // name both sides so the author can see why.
        if ($split['applied'] === []) {
            return new WP_Error('invalid_prop_value', sprintf(
                '%s references the preset "%s", which declares no group role "%s" permits. '
                . 'The preset declares: %s. The role permits: %s.',
                $where,
                $value,
                $role,
                implode(', ', $split['skipped']) ?: '(none)',
                implode(', ', $permitted) ?: '(none)'
            ));
        }

        // Only the groups that fit are validated — the rest are skipped, and the
        // skip is DISCLOSED on the write envelope by pp_udc_composition_findings().
        // THE BAND GATE ASKS THE SAME QUESTION THE EMITTER AND THE DEFINITION GATE
        // ASK: a preset sees SITE tokens only.
        //
        // Passing the band's `_tokens` here made this gate a superset of the
        // emitter. Safe in direction — nothing refused reaches CSS — but it
        // ACCEPTED a band whose preset resolves only against that band's tokens,
        // reported ok:true, and then dropped every such declaration at render. A
        // preset belongs to the site and not to any band; all three gates now say
        // so, so the same reference is refused at the band write with a stated
        // reason instead of accepted and silently dropped.
        foreach ($split['applied'] as $fragment_group => $fragment_map) {
            $error = _pp_udc_validate_group_map(
                $component, $role, (string) $fragment_group, $fragment_map, $permitted, [],
                sprintf(' (via preset "%s")', $value)
            );
            if ($error !== null) {
                return $error;
            }
        }
        return null;
    }

    // Group grain, same scope rule as role grain above.
    return _pp_udc_validate_group_map(
        $component, $role, $grain, $fragment, $permitted, [],
        sprintf(' (via preset "%s")', $value)
    );
}

/**
 * Validates one group map — its params and its state sub-maps — against the
 * role's permitted groups and the shared grammar.
 *
 * Extracted so a PRESET-supplied group map passes through exactly the checks an
 * inline one does. Two copies of this walk would be two places for a preset to
 * drift into accepting something an author cannot write.
 *
 * @return WP_Error|null
 */
function _pp_udc_validate_group_map(
    string $component,
    string $role,
    string $group_name,
    $group_map,
    array $permitted,
    array $band_tokens,
    string $origin = '',
    bool $allow_preset = false,
    ?string $subject = null
): ?WP_Error {
    // WHO THIS MAP BELONGS TO, said once (#1016).
    //
    // Every message below used to open `Component "x" role "y"`, which is the
    // truth for a band and for a chrome entry and a LIE for a preset definition:
    // a preset is authored against no component and no role, and telling an
    // author their preset is wrong on a role they never named would send them
    // looking at the wrong thing. A preset validates through this same function —
    // "one engine, no fork" is the ruled contract — so the engine takes the
    // subject as a parameter rather than growing a second copy with a different
    // preamble. Null keeps the band/chrome wording byte-identical.
    $who = $subject ?? sprintf('Component "%s" role "%s"', $component, $role);

    $groups = pp_udc_groups();
    if (!isset($groups[$group_name])) {
        return new WP_Error('unknown_udc_group', sprintf(
            '%s%s names the UDC group %s, which does not exist. Available groups: %s',
            $who,
            $origin,
            _pp_render_undeclared_prop_keys([$group_name]),
            implode(', ', array_keys($groups))
        ));
    }
    if (!in_array($group_name, $permitted, true)) {
        return new WP_Error('unknown_udc_group', sprintf(
            '%s%s does not permit the UDC group "%s". Permitted groups: %s',
            $who,
            $origin,
            $group_name,
            implode(', ', $permitted) ?: '(none)'
        ));
    }
    if (!is_array($group_map)) {
        return new WP_Error('invalid_prop_value', sprintf(
            '%s group "%s"%s must be an object of parameters; got %s.',
            $who,
            $group_name,
            $origin,
            _pp_schema_value_for_message($group_map)
        ));
    }

    $states = pp_udc_states();
    $params = $groups[$group_name]['params'];
    foreach ($group_map as $param_name => $param_value) {
        $param_name = (string) $param_name;

        // GROUP-GRAIN preset. Never inside a preset fragment: a preset resolves
        // one level only, and the caller has already refused a nested one.
        if ($param_name === PP_UDC_PRESET_KEY) {
            if (!$allow_preset) {
                return _pp_udc_nested_preset_error(
                    sprintf('%s group "%s"%s', $who, $group_name, $origin),
                    null
                );
            }
            $error = _pp_udc_validate_preset_reference(
                $component, $role, $group_name, $param_value, $group_name, $permitted
            );
            if ($error !== null) {
                return $error;
            }
            continue;
        }

        if (isset($states[$param_name])) {
            if (!is_array($param_value)) {
                return new WP_Error('invalid_prop_value', sprintf(
                    '%s group "%s"%s "%s" must be an object of parameters; got %s.',
                    $who, $group_name, $origin, $param_name,
                    _pp_schema_value_for_message($param_value)
                ));
            }
            foreach ($param_value as $state_param => $state_value) {
                // A state never nests inside a state. Without this the key falls
                // through to the param check and is reported as a missing
                // PARAMETER, sending the author hunting for a parameter named
                // ":hover".
                if (isset($states[(string) $state_param])) {
                    return new WP_Error('invalid_prop_value', sprintf(
                        '%s group "%s"%s "%s" may not contain the state "%s". '
                        . 'States do not nest; declare each state directly on the group.',
                        $who, $group_name, $origin, $param_name, (string) $state_param
                    ));
                }
                $error = _pp_udc_validate_param(
                    $component, $role, $group_name, (string) $state_param,
                    $state_value, $params, $band_tokens, $param_name, $origin, $who
                );
                if ($error !== null) {
                    return $error;
                }
            }
            continue;
        }

        // A KEY THAT LOOKS LIKE A STATE BUT IS NOT ONE gets its own refusal.
        // `:disabled`, `:focus` and `::before` are all things an author or a
        // model will reasonably try, and answering "no parameter named
        // :disabled. Available parameters: fill, position, …" sends them hunting
        // through the wrong list entirely. Ruling A3 defers each of those to its
        // own decision, so the honest message names the three states that exist
        // and says plainly that the rest are not supported yet.
        if ($param_name !== '' && $param_name[0] === ':') {
            return new WP_Error('invalid_prop_value', sprintf(
                '%s group "%s"%s names the state %s, which does not exist. '
                . 'Available states: %s. Pseudo-elements (::before), disabled and ancestor states are not supported.',
                $who, $group_name, $origin,
                _pp_render_undeclared_prop_keys([$param_name]),
                implode(', ', array_keys($states))
            ));
        }

        $error = _pp_udc_validate_param(
            $component, $role, $group_name, $param_name,
            $param_value, $params, $band_tokens, '', $origin, $who
        );
        if ($error !== null) {
            return $error;
        }
    }
    return null;
}

/**
 * Validates one role's `_css` map: the Layer-2 write gate (contract §2′).
 *
 * Mirrors _pp_udc_validate_group_map()'s SHAPE — the same state nesting, the same
 * delegation to _pp_udc_validate_param() for the breakpoint dimension — while
 * differing in the three places R2′ requires: the keys are CSS PROPERTIES rather than
 * declared parameters, there is no `$permitted` membership test, and the parameter
 * definition is resolved per property by pp_udc_css_param() instead of read from a
 * fixed table.
 *
 * @param string $state The state this map sits in, or `''` for the base state.
 * @return WP_Error|null
 */
function _pp_udc_validate_css_map(
    string $component,
    string $role,
    $css_map,
    array $band_tokens,
    string $state
): ?WP_Error {
    // THE LOCATOR IS BUILT FOR THE MESSAGE IT ENDS UP INSIDE. _pp_udc_validate_param()
    // appends `group "<g>" parameter "<p>"` to whatever subject it is handed, so passing
    // a subject that already said `_css` produced `role "answer" "_css" group "_css"
    // parameter "opacity"` — the key named twice and the word "group" applied to
    // something that is not one. This file's own refusals are the locator an operator
    // reads, so the subject stays plain here and the `_css`-shaped refusals below say it
    // once, themselves.
    $who = sprintf('Component "%s" role "%s"', $component, $role);
    $mine = sprintf('%s "%s"', $who, PP_UDC_CSS_KEY);

    if (!is_array($css_map)) {
        return new WP_Error('invalid_prop_value', sprintf(
            '%s must be an object of CSS property => value; got %s.',
            $mine,
            _pp_schema_value_for_message($css_map)
        ));
    }

    $states   = pp_udc_states();
    $excluded = pp_udc_css_excluded_properties();

    foreach ($css_map as $key => $value) {
        $key = (string) $key;

        // A STATE NESTS HERE EXACTLY AS IT DOES IN A GROUP, so `:hover` reaches raw
        // declarations too. Without this the key would fall through to the property
        // branch and be refused as a bad property name, sending an author who wrote
        // a perfectly ordinary hover map to read about the property charset.
        if (isset($states[$key])) {
            if ($state !== '') {
                return new WP_Error('invalid_prop_value', sprintf(
                    '%s may not contain the state "%s" inside the state "%s". States do not nest.',
                    $mine, $key, $state
                ));
            }
            $error = _pp_udc_validate_css_map($component, $role, $value, $band_tokens, $key);
            if ($error !== null) {
                return $error;
            }
            continue;
        }

        // A state-SHAPED key that is not one of the three gets the group map's own
        // wording, for the same reason it has it there: `:disabled` and `::before`
        // are things an author will reasonably try, and answering "that is not a
        // valid CSS property name" sends them to entirely the wrong question.
        if ($key !== '' && $key[0] === ':') {
            return new WP_Error('invalid_prop_value', sprintf(
                '%s names the state %s, which does not exist. Available states: %s. '
                . 'Pseudo-elements (::before), disabled and ancestor states are not supported.',
                $mine,
                _pp_render_undeclared_prop_keys([$key]),
                implode(', ', array_keys($states))
            ));
        }

        // ── THE SECURITY BOUNDARY (§2′.1) ───────────────────────────────────
        if (!pp_udc_valid_css_property($key)) {
            $hint = ($key !== '' && strtolower($key) !== $key && pp_udc_valid_css_property(strtolower($key)))
                ? sprintf(' CSS property names are case-insensitive, so write "%s".', strtolower($key))
                : '';
            return new WP_Error('unknown_udc_css_property', sprintf(
                '%s property %s is not a valid CSS property name. Write 1-64 characters of '
                . 'lowercase letters, digits and hyphens, optionally starting with one hyphen '
                . 'for a vendor prefix (for example "opacity" or "-webkit-line-clamp").%s '
                . 'Custom properties ("--name") are not written here: band tokens live in "_tokens".',
                $mine,
                _pp_render_undeclared_prop_keys([$key]),
                $hint
            ));
        }

        if (isset($excluded[$key])) {
            return new WP_Error('unknown_udc_css_property', sprintf(
                '%s property "%s" is not available: %s.',
                $mine,
                $key,
                $excluded[$key]
            ));
        }

        $param = pp_udc_css_param($key);

        // R1′.3 — AN `@reference` REQUIRES A DECLARED TYPE, and this is the clause
        // that survived the Q1 reversal verbatim. _pp_udc_reference_check() judges a
        // resolved token against the parameter's grammar; with no grammar there is
        // nothing to judge, so a colour token on a length-ish property would resolve
        // to nonsense the browser drops — the accepted-but-dead class refused since
        // #230. A literal is accepted here; a reference is not.
        if (!empty($param['untyped'])) {
            foreach (is_array($value) ? $value : [$value] as $candidate) {
                if (!is_scalar($candidate)) {
                    continue; // Shape errors are reported by the param validator below.
                }
                $ref = pp_udc_parse_reference((string) $candidate);
                if ($ref !== null) {
                    return new WP_Error('invalid_prop_value', sprintf(
                        '%s property "%s" cannot take the reference "@%s". This property has no '
                        . 'declared grammar in the design vocabulary, so the engine cannot check '
                        . 'that a token\'s value is usable here. Write the literal value instead.',
                        $mine,
                        $key,
                        $ref
                    ));
                }
            }
        }

        // The PLAIN subject goes down: _pp_udc_validate_param() renders the `_css`
        // vocabulary itself when the group is this key, so passing an already-`_css`-
        // shaped subject would name it twice.
        $error = _pp_udc_validate_param(
            $component, $role, PP_UDC_CSS_KEY, $key, $value, [$key => $param], $band_tokens, $state, '', $who
        );
        if ($error !== null) {
            return $error;
        }
    }

    return null;
}

/**
 * Validates one param entry — the value may be a scalar, an `@reference`, or a
 * breakpoint-keyed map of either.
 *
 * @param string $state The state this param sits in, or `''` for the base state.
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
    string $state,
    string $origin = '',
    ?string $subject = null
): ?WP_Error {
    // THE ORIGIN HAS TO REACH THE VALUE-LEVEL MESSAGE, and for a while it did not.
    //
    // Every STRUCTURAL refusal in _pp_udc_validate_group_map() already carried
    // ` (via preset "name")`, but the refusal an author actually meets — a value
    // that fails its parameter's grammar — did not, because this function never
    // took the suffix. With three well-formed theme presets that was invisible.
    // With site-stored presets it is the ordinary case, and the message it
    // produced pointed at a role, a group and a parameter in the author's OWN
    // band that hold no such value: they would go hunting for something they
    // never wrote. Invariant I24 asks for a stated reason AND a route back, and
    // the route back here is the preset's name.
    $who   = $subject ?? sprintf('Component "%s" role "%s"', $component, $role);
    // LAYER 2 IS NOT A GROUP AND ITS KEYS ARE NOT PARAMETERS, so it gets the same
    // locator in its own vocabulary rather than a second locator builder. Spelled the
    // group way it read `role "answer" "_css" group "_css" parameter "opacity"` — the
    // key named twice and the word "group" applied to something that is not one. One
    // builder, two vocabularies: a diagnostic that names the wrong kind of thing sends
    // an operator to the wrong surface, which is the whole point of I27's one-canonical-
    // vocabulary rule.
    $where = $group === PP_UDC_CSS_KEY
        ? sprintf(
            '%s "%s"%s%s property "%s"',
            $who,
            PP_UDC_CSS_KEY,
            $origin,
            $state !== '' ? ' ' . $state : '',
            $param_name
        )
        : sprintf(
            '%s group "%s"%s%s parameter "%s"',
            $who,
            $group,
            $origin,
            $state !== '' ? ' ' . $state : '',
            $param_name
        );

    if (!isset($params[$param_name])) {
        return new WP_Error('invalid_prop_value', sprintf(
            '%s group "%s"%s%s has no parameter %s. Available parameters: %s',
            $who,
            $group,
            $origin,
            $state !== '' ? ' ' . $state : '',
            _pp_render_undeclared_prop_keys([$param_name]),
            implode(', ', array_keys($params))
        ));
    }
    $param = $params[$param_name];

    // SINGLE-VALUED PARAMS REFUSE THE TWO DIMENSIONS EVERYTHING ELSE GETS FREE.
    //
    // Responsiveness and states are not opt-in anywhere else in this taxonomy:
    // the branch below accepts a breakpoint map for ANY param, and
    // _pp_udc_validate_group_map() routes a state sub-map into this function for
    // any param too. That is right for a colour or a length and wrong for
    // `background.image`, because ruling A2 places per-breakpoint art direction
    // OUT of scope — and its exclusions are to be REFUSED, not silently ignored.
    // Without these two refusals the capability would simply exist, undeclared
    // and untested, the day the param was added.
    //
    // Both refusals name the dimension and say what IS available, so the message
    // routes an author to the thing that works rather than to a dead end.
    if (!empty($param['single_valued'])) {
        if ($state !== '') {
            return new WP_Error('invalid_prop_value', sprintf(
                '%s cannot be set per state: one %s applies in every state. '
                . 'Set it once outside "%s"; `background.overlay`, `fill` and the other background '
                . 'parameters do vary per state if you need the treatment to change.',
                $where,
                $param_name,
                $state
            ));
        }
        if (is_array($value)) {
            return new WP_Error('invalid_prop_value', sprintf(
                '%s cannot be set per breakpoint: one %s applies at every width. '
                . 'Set a single value; `background.position`, `size` and `repeat` are '
                . 'breakpoint-keyable and are how you adapt one image to a narrow screen.',
                $where,
                $param_name
            ));
        }
    }

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
            // THE MESSAGE HAS TO NAME THE SCOPE THE CALLER ACTUALLY HAS. A band map
            // can reach its own `_tokens`; a PRESET DEFINITION cannot, because a
            // preset belongs to the site and not to any band — it is validated with
            // no band tokens for exactly that reason. Telling a preset author their
            // reference "is not defined in this band's _tokens" points them at a
            // place they do not have and cannot create, which is the same class of
            // wrong-subject message the preset origin suffix exists to prevent.
            // `$band_tokens` being empty is the honest discriminator: it is the
            // scope, not a guess about the caller.
            return new WP_Error('invalid_prop_value', $band_tokens === []
                ? sprintf(
                    '%s references "@%s", which is not a registered design token. '
                    . 'Only site design tokens resolve here.',
                    $where,
                    $ref
                )
                : sprintf(
                    '%s references "@%s", which is not defined in this band\'s "_tokens" and is not a registered design token.',
                    $where,
                    $ref
                ));
        }
        // The REFERENCE must be usable for this param. A reference to a colour
        // token from a length parameter resolves to "0.25rem"-class nonsense the
        // browser drops, which is the same accepted-but-dead class the colour
        // validator has rejected since #230.
        //
        // Routed through the ONE predicate (#972, ruling D3) so the write gate and
        // the emitter cannot drift: a typed token is judged by what the registry
        // DECLARES it to be, an untyped one by parsing its value. The message keeps
        // naming the token and its value either way, because the author wrote a
        // name and needs to see what it resolved to.
        $check = _pp_udc_reference_check($resolved, $param);
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
 * `<role>-<group>-<param>[-<state>]-<bp>`, where `<state>` is a pp_udc_states()
 * mint segment and MAY ITSELF CONTAIN A HYPHEN (`focus-visible`). That is why
 * every decoder pops by segment COUNT rather than popping one — see
 * _pp_udc_state_from_mint(). §3.1 states the rule for the base state; ruling A3
 * widened it to three states.
 * (§3.1's illustrative snippet elides the group — `quote-size-d` — but the RULE
 * is what has to be collision-free, and two groups can carry the same param
 * name, so the group segment stays.) Deterministic, never sequential and never
 * random: pp_composition_content_hash() would otherwise see a different value on
 * every write and make the composition false-conflict against itself, which is
 * the defect that put the props.id strip in the hash in the first place.
 */
function pp_udc_mint_name(string $role, string $group, string $param, string $state, string $bp): string {
    $states  = pp_udc_states();
    $segment = ($state !== '' && isset($states[$state])) ? '-' . $states[$state]['mint'] : '';
    return $role . '-' . $group . '-' . $param . $segment . '-' . $bp;
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
        $states = pp_udc_states();
        foreach ($role_map as $group => $group_map) {
            if (!is_array($group_map)) {
                continue;
            }
            foreach ($group_map as $param => $value) {
                if (isset($states[$param]) && is_array($value)) {
                    foreach ($value as $state_param => $state_value) {
                        $minted = _pp_udc_mint_value(
                            $state_value, (string) $role, (string) $group, (string) $state_param,
                            (string) $param, $tokens
                        );
                        if ($minted !== null) {
                            $udc[$role][$group][$param][$state_param] = $minted;
                        }
                    }
                    continue;
                }
                $minted = _pp_udc_mint_value(
                    $value, (string) $role, (string) $group, (string) $param,
                    '', $tokens
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
    string $state,
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
        $name           = pp_udc_mint_name($role, $group, $param, $state, (string) $bp);
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
/**
 * @param array      $item   One composition item, or a chrome entry shaped like one.
 * @param string     $layer  'defaults' | 'authored' — which tier to compile.
 * @param array|null $drops  Pass an array to collect what this compile DISCARDED.
 *                           Filled with ['where' => string, 'reason' => string]
 *                           entries, bounded at PP_UDC_MAX_EMIT_DROPS, both fields
 *                           already cleaned for reflection. Left untouched at null,
 *                           which is what every render path passes.
 */
function pp_udc_compile_band(array $item, string $layer, ?array &$drops = null): array {
    $out = ['id' => '', 'tokens' => [], 'blocks' => []];

    // THE OPTIONAL DROP LEDGER (#981, D3). Pass an array to learn what this compile
    // DISCARDED and why; pass nothing — as every render path does — and the
    // recording is an identity check per discarded value. See _pp_udc_place() for
    // why the ledger lives inside the emitter rather than in a checker that
    // re-derives the same conditions.
    //
    // A role default's drop is filtered inside _pp_udc_place()'s ledger, which sees
    // the source; see the note there for why.

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
        if ($drops !== null && count($drops) < PP_UDC_MAX_EMIT_DROPS
            && isset($item['udc']) && is_array($item['udc']) && $item['udc'] !== []) {
            $drops[] = [
                'where'  => 'the whole band',
                'reason' => $id === ''
                    ? 'the band has no id, so none of its styling can be addressed'
                    : sprintf('the band id "%s" is not a usable CSS attribute value', _pp_udc_reflect($id)),
            ];
        }
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

    $states      = pp_udc_states();
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
        //
        // THE PERMITTED CHARSET, enumerated so the next reader knows what is
        // deliberate: letters, digits, `_`, `.`, `-`, the SPACE (descendant),
        // `>` (child), and the two BRACKETS. Everything else is still out, and the
        // exclusions matter as much as the inclusions — `,` would let one role own
        // an unrelated selector list, `:` a pseudo-class or (a colon being one
        // character from a semicolon in effect) a place to end the selector early,
        // and `=` plus the two quote characters an attribute VALUE match. None of
        // those can be spelled here.
        //
        // THE BRACKETS JOINED IN #1046, and the shape of that widening is the whole
        // of its security argument. Without `=` or a quote character, a bracketed
        // term can only be an attribute PRESENCE test — `[open]`, `[disabled]`,
        // `[aria-expanded]`. `[href="javascript:void(0)"]`, `[class*="btn"]` and
        // every other value-matching form stays unspellable, because each needs a
        // character this class still refuses. The existing refusal case in
        // UdcEngineTest (`.a[data-x="y"]`) survives the widening UNCHANGED for
        // exactly that reason, and it is the cheapest proof that the class grew by
        // presence selectors and nothing else.
        //
        // The measured reason, matching the `>` precedent below: faq's accordion
        // colours its open <summary> through `.faq__item[open] > .faq__question`,
        // an ancestor state. Ruling A3 defers ancestor states as a value DIMENSION,
        // so the open treatment is expressed the way nav's `link-current` expresses
        // the current page — as its own role with its own selector. Before this, a
        // role declaring that selector was SILENTLY SKIPPED here while
        // pp_udc_validate_map() accepted authored values on it: stored, reported
        // `ok: true`, painting nothing. That gap is wider than faq and is filed as
        // #1048; this widening removes faq from its reach rather than closing it.
        // An attribute presence term is inert as CSS source text, exactly as a child
        // combinator is: it cannot open a string, a comment or a declaration, and it
        // cannot escape the rule it sits in.
        //
        // WHAT THIS GATE DOES NOT CHECK, said plainly so the next reader does not
        // over-trust it: it bounds the CHARACTER SET, not the SHAPE. `> a`, `a >` and
        // `a >> b` all pass it and are all invalid CSS selectors — as `.` and `--` were
        // before `>` existed here, so this is a fragility the widening enlarges rather
        // than creates. It matters because _pp_udc_reduced_motion_guard() groups every
        // motion-carrying role selector into ONE comma-separated rule, and CSS discards
        // a whole grouped rule when any selector in the list is invalid: one malformed
        // role selector would silently drop the prefers-reduced-motion guard for every
        // role in that scope. The input is repo-controlled (schemas are on disk and
        // integrity-checked), so this is a theme-bug blast radius, not a reachable one —
        // and UdcEngineTest pins the shape of every shipped selector so a bad one fails
        // in CI rather than in someone's browser.
        //
        // `>` JOINED IN #994 (ruling D4) for one measured reason. nav's
        // current-page rules were `li.current-menu-item > a`; the `link-current`
        // role could only spell the DESCENDANT form, which also matches every link
        // in a current parent's dropdown. That cost nothing while chrome shipped no
        // defaults, and became a visible regression the moment the retirement made
        // those values a default — a current "Services" page would have turned its
        // whole submenu bold and accent-coloured. A child combinator is inert as
        // CSS source text: it cannot open a string, a comment or a declaration, and
        // it cannot escape the rule it sits in.
        //
        // THE ANCHORS ARE THE OTHER HALF OF THIS GATE, and `\z` is doing that work
        // rather than `$`. PCRE's `$` matches before a FINAL newline unless the `D`
        // modifier is set, so `/^…$/` accepts "a\n" — excluding `\n` from the class
        // does NOT close that, it is precisely what makes a trailing newline the one
        // character `$` forgives. `\z` matches only at the true end of the subject.
        //
        // The practical exposure was nil (a trailing newline in a selector emits inert
        // CSS, and nothing past it can follow — ".a\n.b" was refused either way), so
        // this is consistency and honesty rather than a fix: every sibling string-to-CSS
        // gate in this file already uses `\z` (pp_udc_valid_band_id, the `@reference`
        // name gates), and a maintainer widening this class again should inherit an
        // anchoring guarantee that is actually in force. Widen the CLASS if a ruling
        // says so; do not widen the ANCHORING.
        // THE BRACKETS ALSO HAVE TO BALANCE, AND THE CHARSET CANNOT SAY SO.
        //
        // A character class is a per-character test; "every `[` has its `]`" is a
        // property of the whole string. That distinction is not academic here, and the
        // repo has already paid for learning it once: `_pp_udc_delimiters_balanced()`
        // exists because CSS Syntax L3's "consume a simple block" treats `[` exactly as
        // it treats `(` — an unclosed one consumes across the terminating `;` and the
        // closing `}` TO EOF (#965). Its docblock is the reference.
        //
        // The blast radius is why this is checked here rather than trusted to the
        // schemas. A malformed `>` costs the one grouped rule it sits in. A malformed `[`
        // costs every rule that PRINTS AFTER IT in the SAME `<style>` element — and
        // WordPress core concatenates a handle's inline styles into one element
        // (WP_Styles::print_inline_style; functions.php:105 says so too). PER HANDLE,
        // measured against functions.php rather than assumed:
        //   defaults ride `pp-base` (:193)      -> the rest of this component's defaults,
        //                                          every LATER component's defaults, and
        //                                          the chrome defaults (:220)
        //   authored rides `pp-utilities` (:198) -> every later band block and the chrome
        //                                          authored block (:225)
        // Two handles, so a broken DEFAULTS selector cannot reach an authored band block
        // at all, and the token tier is safe in both directions because it is added to
        // `pp-base` FIRST (:112) and therefore prints ahead of any damage. An earlier
        // draft of this comment claimed the opposite on both counts.
        // The input is repo-owned either way; this bounds what a theme bug can do, which
        // is the same posture the charset itself takes.
        //
        // ONE OWNER, deliberately: this routes through the shared balance helper rather
        // than counting brackets locally, because a second implementation of "is this
        // delimiter-safe" is exactly the forked-grammar the architecture forbids.
        //
        // WHICH GATE DECIDES WHAT, measured rather than reasoned, because two earlier
        // drafts of this comment got it wrong in opposite directions. The helper balances
        // quotes and parens as well as brackets, but the charset below refuses `(`, `)`,
        // `'` and `"` outright — so for those characters the helper only ever decides the
        // UNBALANCED case, and a BALANCED one falls through to the charset:
        //
        //   `.a(b)`  balanced=true   charset=false  -> refused by the CHARSET
        //   `.a"b"`  balanced=true   charset=false  -> refused by the CHARSET
        //   `.a(b`   balanced=false  charset=false  -> refused HERE
        //   `.a[b`   balanced=false  charset=TRUE   -> refused HERE, and ONLY here
        //
        // That last row is why this CALL SITE exists (the helper itself is #965's, shared
        // with three older callers; #1046 only routes the selector path through it). Brackets are the one delimiter
        // class the charset ADMITS (widened at #1046 for `question-open`), so this is the
        // only thing standing between an unbalanced `[` and the emitter printing
        // `.faq__item[open > .faq__question{...}`, which swallows the following role to
        // end-of-rule. For every other delimiter the helper is a cheap early exit, not the
        // decider. Order therefore changes which gate reports, never what is admitted.
        if ($selector !== '' && !_pp_udc_delimiters_balanced($selector)) {
            continue;
        }

        // BALANCED IS NOT WELL FORMED, and the charset cannot tell the difference.
        // #1046's adversarial pass measured the gap it left: `.a[]`, `.a[[b]]` and `.a[b c]`
        // are all BALANCED and all pass the charset, so they reached the emitter as invalid
        // CSS — a shape no bracket could reach at all before #1046 widened the class. The
        // cost is not confined to the rule it sits in: _pp_udc_reduced_motion_guard() emits
        // ONE rule with a comma-joined selector list, and CSS discards an entire rule when
        // any selector in a plain list is invalid, so one bracket typo in one role selector
        // deletes the reduced-motion neutralisation for EVERY role of that band. Input is
        // schema-owned, so this is a maintainer trap rather than author-reachable, and
        // UdcEngineTest already sweeps the shipped schemas for this exact shape — but a gate
        // should refuse what the sweep forbids instead of relying on the sweep to notice.
        // Strip the well-formed presence terms; nothing bracket-like may survive.
        if ($selector !== '' && strpbrk($selector, '[]') !== false) {
            $bracket_stripped = preg_replace('/\[[A-Za-z][A-Za-z0-9_-]*\]/', '', $selector);
            if (strpbrk((string) $bracket_stripped, '[]') !== false) {
                continue;
            }
        }
        if ($selector !== '' && !preg_match('/^[A-Za-z0-9_ .>\[\]\-]{1,120}\z/', $selector)) {
            // DELIBERATELY NOT LEDGERED. A role selector comes only from a
            // repo-owned, integrity-checked component schema, never from an author
            // — the same reason a role DEFAULT's discard is filtered out of the
            // ledger. Surfacing it would hand the operator a configuration-class
            // finding whose next_action ("re-set that value") is unactionable for a
            // theme bug they cannot reach.
            continue;
        }

        $declared  = isset($udc[$role_name]) && is_array($udc[$role_name]) ? $udc[$role_name] : [];
        $defaults  = isset($role_def['defaults']) && is_array($role_def['defaults']) ? $role_def['defaults'] : [];
        $permitted = isset($role_def['groups']) && is_array($role_def['groups']) ? $role_def['groups'] : [];

        // state => bp => property => ['css'=>, 'source'=>, 'literal'=>]
        $resolved = [];

        // ── THE CASCADE RUNG (Addendum A, ruling A3) ────────────────────────
        //
        //   site tokens → presets → component role defaults → band `udc`
        //
        // Site tokens are not a source row: they are what an `@name` in ANY row
        // resolves through, which is what puts them under everything else.
        //
        // The other three rank by POSITION in this list, because _pp_udc_place()
        // lets a later source overwrite an earlier one at the same (state,
        // breakpoint, property) key.
        //
        // WHY PRESETS CANNOT SIMPLY EMIT BAND-SCOPED. Role defaults emit once per
        // COMPONENT, under `[data-pp-component]`, printed BEFORE the theme
        // stylesheets (see pp_udc_component_defaults_css). A preset reference is
        // per BAND. So a preset-sourced declaration emitted the obvious way —
        // band-scoped, in the authored layer — would outrank role defaults on
        // specificity AND on source order, inverting the ruled rung.
        //
        // The fix is to rank in THIS TABLE rather than by emission position: the
        // authored layer places the preset tier, then role defaults as a RANKING
        // PARTICIPANT ONLY, then the band's own map — and then drops every
        // declaration whose winner was `defaults`, because that one already
        // emits, at its designed weight, in the defaults layer.
        //
        // Two consequences worth stating because a future reader will want them:
        //   - a band that references NO preset compiles byte-identically to
        //     before this tier existed (defaults place, udc overwrites them all
        //     back, the drop removes what is left), which is a pinned regression;
        //   - a declaration is never emitted twice, so the ranking costs bytes
        //     only where a preset actually contributes something new.
        $sources     = [];
        $has_presets = false;
        if ($layer === 'defaults' || $layer === 'all') {
            foreach (_pp_udc_preset_sources($defaults, $permitted) as $preset_source) {
                $sources[]   = $preset_source;
                $has_presets = true;
            }
        }
        if ($layer === 'authored' || $layer === 'all') {
            // A band's own preset ranks ABOVE one named by a role default — same
            // tier, more specific statement — and still below role defaults.
            //
            // STILL NOT REACHABLE, AND DELIBERATELY SO AFTER #1016 LOOKED AT IT.
            //
            // A role default naming a preset is part of ruling A3 ("referenced by
            // name ... and from role defaults"), and no schema can express it:
            // UdcEngineTest::testEveryV2SchemaDefaultIsAValueTheEngineWouldAccept
            // walks a role's `defaults` as group names and fails on `_preset`,
            // which is not a group. The branch above stays because the capability
            // is ruled and the ordering it implements is the one custom presets
            // need — but it is inert, and the Sprint-2 task that could have opened
            // it decided not to. The reason is worth recording, because "we ran out
            // of time" and "it would contradict something" are different debts.
            //
            // Opening it needs three wirings, and the middle one contradicts a rule
            // this file states and implements twice. The two T2 honesty halves are
            // missing here: pp_udc_composition_findings() reads only `$item['udc']`,
            // so a skipped group in a DEFAULT-named preset is not disclosed, and
            // pp_udc_validate_map() never walks `defaults`, so an empty intersection
            // there refuses nothing. Supplying the disclosure means routing a
            // SCHEMA-owned skip onto the author-facing findings channel — and the
            // rule against that is not incidental: a role selector's charset
            // failure is deliberately not ledgered (see pp_udc_compile_band's
            // selector gate) and a role default's emit-drop is filtered out by
            // `$source !== 'defaults'` in _pp_udc_place(), both because an operator
            // cannot act on a theme bug reported as site misconfiguration.
            //
            // So a defaults-named preset's skipped groups need a CHANNEL that does
            // not exist yet, and choosing one is a ruling rather than an
            // implementation detail. Left inert, said out loud, filed as #1018.
            foreach (_pp_udc_preset_sources($declared, $permitted) as $preset_source) {
                $sources[]   = $preset_source;
                $has_presets = true;
            }
        }

        // ROLE DEFAULTS JOIN THE AUTHORED LAYER ONLY TO RANK A PRESET UNDER THEM,
        // AND ONLY WHEN THERE IS ONE. With no preset in play there is nothing for
        // them to outrank, so every entry they would place is either overwritten
        // by the band's own value or dropped again — pure work for an identical
        // result. Skipping it is not an optimisation detail; measured on a 50-band
        // page with ONE preset-referencing role per band, it is the difference
        // between 2.96 ms and 7.08 ms for this tier alone, and the overwhelmingly
        // common page has no preset on it at all.
        //
        // THAT LAST CLAUSE IS THE PART #1016 CHANGED, and the number above should
        // not be read as still describing a preset-using page. While presets were
        // three theme constants, the expensive branch was rare. A site-writable
        // preset store makes it ordinary for any site that adopts them. Re-measured
        // on 50 bands, merge-base against this branch:
        //
        //   preset-free page      12.0 ms -> 12.1 ms   (noise; CSS byte-identical)
        //   1 preset role / band  12.7 ms -> 17.4 ms   (+37%)
        //   12 preset roles/band  12.7 ms -> 44.0 ms   (+247%)
        //
        // and roughly 40% of that overhead is this tier: placing every role default
        // through the full resolve-and-assemble path and then discarding almost all
        // of it in the filter below. Scaling stays LINEAR in band count, so nothing
        // quadratic was introduced.
        //
        // THE LEVER, IF IT EVER MATTERS, stated so the next reader does not have to
        // re-derive it: the discarded defaults contribute only their (state,
        // breakpoint, property) KEYS to the ranking, never their CSS, so a key-only
        // placement pass would rank identically for a fraction of the work. It
        // cannot be memoised per (component, role), because pp_udc_resolve_reference()
        // consults the BAND's tokens first — a band token can shadow a name a
        // default references, which makes this tier band-dependent.
        // ONE FACT, ONE NAME. The placement below and the drop further down must
        // stay exact complements — defaults are dropped precisely when they were
        // placed for ranking only. Deriving both from this local keeps a later
        // edit (a fourth layer name, say) from silently double-emitting the
        // defaults tier band-scoped, or dropping the band's own declarations.
        $defaults_rank_only = ($layer === 'authored' && $has_presets);
        if ($layer !== 'authored' || $defaults_rank_only) {
            $sources[] = ['defaults', $defaults];
        }
        if ($layer === 'authored' || $layer === 'all') {
            $sources[] = ['udc', $declared];
        }

        foreach ($sources as [$source, $map]) {
            // `_css` PLACES LAST, ALWAYS — and this is a correctness fix, not a tidy-up.
            //
            // _pp_udc_place() lets a later placement overwrite an earlier one at the same
            // (state, breakpoint, property) key, and this loop walks the author's map in
            // the order their JSON happened to carry. Measured before the reorder: the
            // same band with the same two values painted `#ff0000` when `typography` was
            // written first and `#111111` when `_css` was — the winner decided by key
            // order, which no author can see and no disclosure could describe. §2′.3 rules
            // that `_css` outranks a group value (an escape that loses to the thing it
            // escapes cannot escape anything), and `udc_css_overrides_group_value` states
            // that unconditionally, so the emitter has to make it unconditionally true.
            if (is_array($map) && array_key_exists(PP_UDC_CSS_KEY, $map)) {
                $css_last = $map[PP_UDC_CSS_KEY];
                unset($map[PP_UDC_CSS_KEY]);
                $map[PP_UDC_CSS_KEY] = $css_last;
            }
            foreach ($map as $group_name => $group_map) {
                // A `_preset` KEY IS NOT A MISSING GROUP — IT IS THE PRESET
                // MECHANISM, AND IT PAINTS. Ledgering it would put a "stored but
                // not painted" warning on every band that uses a preset, which is
                // the headline authoring affordance, on a value that renders
                // correctly. The C2 helper skips this key for the same reason.
                if ((string) $group_name === PP_UDC_PRESET_KEY) {
                    continue;
                }
                // LAYER 2 (contract §2′). A synthesized params table keyed by the
                // author's own property names, resolved through the SAME predicate the
                // write gate used — so a property the gate typed is a property the
                // emitter types, and a property it accepted is a property the emitter
                // places or ledgers. That is the #570 convergence rule, which R1′ makes
                // the sharpest constraint in this layer: whatever the write gate
                // accepts, the emitter emits or discloses.
                if ((string) $group_name === PP_UDC_CSS_KEY) {
                    if (!is_array($group_map)) {
                        if ($drops !== null && $source !== 'defaults'
                            && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                            $drops[] = [
                                'where'  => sprintf('role "%s" "%s"', _pp_udc_reflect((string) $role_name), PP_UDC_CSS_KEY),
                                'reason' => 'the raw declaration list is not a map of properties',
                            ];
                        }
                        continue;
                    }
                    $css_where = $drops === null ? '' : sprintf(
                        'role "%s" "%s"', _pp_udc_reflect((string) $role_name), PP_UDC_CSS_KEY
                    );
                    $css_tokens = strncmp($source, 'preset:', 7) === 0 ? [] : $band_tokens;
                    // The state walk is the group loop's, so `:hover` reaches raw
                    // declarations on the same terms. Stored data is re-gated here and
                    // not trusted from the write path: a raw meta write, a composition
                    // written before this layer existed and restore_composition (#233)
                    // all arrive at this line directly.
                    $css_place = static function (string $st, $map) use (
                        &$resolved, &$referenced, &$drops, $source, $css_tokens, $breakpoints, $css_where
                    ): void {
                        if (!is_array($map)) {
                            return;
                        }
                        foreach ($map as $property => $value) {
                            $property = (string) $property;
                            if (!pp_udc_valid_css_property($property)
                                || isset(pp_udc_css_excluded_properties()[$property])) {
                                if ($drops !== null && $source !== 'defaults'
                                    && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                                    $drops[] = [
                                        'where'  => $css_where . ' ' . _pp_udc_reflect($property),
                                        'reason' => 'it is not an available CSS property here',
                                    ];
                                }
                                continue;
                            }
                            _pp_udc_place(
                                $resolved, $st, [$property => pp_udc_css_param($property)], $property,
                                $value, $source, $css_tokens, $breakpoints, $referenced, $drops, $css_where
                            );
                        }
                    };
                    foreach ($group_map as $key => $value) {
                        if (isset($states[(string) $key]) && is_array($value)) {
                            $css_place((string) $key, $value);
                            continue;
                        }
                        $css_place('', [$key => $value]);
                    }
                    continue;
                }
                if (!isset($groups[$group_name]) || !is_array($group_map)) {
                    if ($drops !== null && $source !== 'defaults'
                        && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                        $drops[] = [
                            'where'  => sprintf(
                                'role "%s" group "%s"',
                                _pp_udc_reflect((string) $role_name),
                                _pp_udc_reflect((string) $group_name)
                            ),
                            'reason' => !isset($groups[$group_name])
                                ? 'there is no such group in the design vocabulary'
                                : 'the group is not a map of parameters',
                        ];
                    }
                    continue;
                }
                $params = $groups[$group_name]['params'];
                // BUILT ONLY FOR A COLLECTOR. This runs per group, per role, per
                // band, per source on every front-end request, and its two
                // reflected-text cleans plus a sprintf are pure waste when no
                // ledger is being filled — which is every render.
                $where = $drops === null ? '' : sprintf(
                    'role "%s" group "%s"',
                    _pp_udc_reflect((string) $role_name),
                    _pp_udc_reflect((string) $group_name)
                );
                // A PRESET SEES SITE TOKENS ONLY, HERE AS AT ITS DEFINITION (#1016).
                //
                // A preset belongs to the site, not to any band, so
                // pp_udc_validate_preset_definition() validates it with no band
                // tokens and refuses an `@name` that only some band could resolve.
                // Handing the BAND's tokens to a preset-sourced value at emit would
                // undo that: a preset stored by a raw `wp option update` could carry
                // `@quote-size-d`, be refused by every gate, and paint anyway on any
                // band that happens to mint that name. The write gate and the
                // emitter would disagree about the same stored bytes, which is the
                // disagreement I29 forbids. Same scope on both sides, so a preset
                // either resolves everywhere or nowhere.
                $source_tokens = strncmp($source, 'preset:', 7) === 0 ? [] : $band_tokens;
                foreach ($group_map as $param_name => $value) {
                    if (isset($states[$param_name]) && is_array($value)) {
                        foreach ($value as $state_param => $state_value) {
                            _pp_udc_place($resolved, (string) $param_name, $params, (string) $state_param, $state_value, $source, $source_tokens, $breakpoints, $referenced, $drops, $where);
                        }
                        continue;
                    }
                    _pp_udc_place($resolved, '', $params, (string) $param_name, $value, $source, $source_tokens, $breakpoints, $referenced, $drops, $where);
                }
            }
        }

        foreach ($resolved as $state => $by_bp) {
            // THE IMAGE IS SINGLE-VALUED; THE OVERLAY IS NOT. So an author who sets
            // one image and a narrower scrim — `{"image": 42, "overlay": {"d": …,
            // "p": …}}` — resolves the image into the `d` bucket only, and the `p`
            // bucket holds an overlay with nothing to lie over. Composed bucket by
            // bucket in isolation, that phone scrim would be dropped as "a scrim over
            // nothing" and the author would get a desktop-only overlay with no
            // refusal and no finding: a declared authoring input silently cancelled,
            // which is the I35 class this engine keeps closing.
            //
            // The image applies at every width — that is what single-valued MEANS —
            // so a narrower bucket composing an overlay borrows it. The borrowed
            // entry is only a compose input; it is not emitted as its own
            // declaration, because the base bucket already emits it.
            $base_image = $by_bp['d']['background-image'] ?? null;
            if ($base_image !== null) {
                foreach ($by_bp as $bp => $declarations) {
                    if ($bp !== 'd'
                        && isset($declarations[PP_UDC_BACKGROUND_OVERLAY_CARRIER])
                        && !isset($declarations['background-image'])) {
                        $by_bp[$bp]['background-image'] = $base_image;
                    }
                }
            }

            foreach ($by_bp as $bp => $declarations) {
                // THE DROP. In the authored layer a declaration whose winner is
                // a role default is not the band's contribution — it is the
                // component's, and it has already been emitted once by
                // pp_udc_component_defaults_css(). Re-emitting it band-scoped
                // would say the same thing at a higher weight and quietly promote
                // the defaults tier above anything the design system aims at the
                // same element.
                if ($defaults_rank_only) {
                    $declarations = array_filter(
                        $declarations,
                        static fn(array $entry): bool => $entry['source'] !== 'defaults'
                    );
                }
                // ORDER, THEN COMPOSE — and the sequence matters. Sorting first
                // means the fold below sees the same input whatever order the
                // author wrote `image` and `overlay` in, so the composed layer
                // list is deterministic too.
                $declarations = _pp_udc_sort_declarations($declarations);
                $declarations = _pp_udc_compose_background_layers($declarations);
                // AFTER the compose, so the overlay has already been folded into
                // background-image and the companions see the final layer list.
                $declarations = _pp_udc_background_image_companions($declarations);
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
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}\z/', (string) $name)) {
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
 * The preset source rows one role map contributes, in tier order.
 *
 * Role grain places first and group grain second, so "give this role the button
 * preset, but take its typography from the link preset" resolves the way it
 * reads. Both sit inside the single preset tier, below role defaults.
 *
 * The `source` string carries the preset NAME, not a bare `'preset'`, and that is
 * load-bearing rather than cosmetic: invariants I35 and I36 require that an
 * author can see that a value came from preset X and was overridden by the band,
 * and a resolver that recorded only "some preset" could never report which.
 *
 * A dangling reference resolves to nothing HERE and contributes no row. The write
 * gate refuses it outright, so reaching this point means stored-before-the-rule
 * data — and the posture for that is already settled one level down: drop the
 * unresolvable piece and leave every sibling declaration painting, exactly as
 * _pp_udc_place()'s `@ref` branch does.
 *
 * BE PRECISE ABOUT WHAT IS AND IS NOT DISCLOSED: that drop is SILENT at render.
 * pp_udc_composition_findings() emits `udc_token_minted` and
 * `udc_unused_band_token` and nothing else, so there is no finding for an
 * unresolvable reference of either kind. The disclosure an operator actually
 * gets is the write-path refusal in _pp_udc_validate_preset_reference().
 *
 * @return array<int, array{0: string, 1: array}>
 */
function _pp_udc_preset_sources(array $map, array $permitted): array {
    if ($map === []) {
        return []; // The common case: a role this band declares nothing for.
    }
    $sources = [];

    if (isset($map[PP_UDC_PRESET_KEY]) && is_string($map[PP_UDC_PRESET_KEY])) {
        $preset = pp_udc_resolve_preset($map[PP_UDC_PRESET_KEY]);
        if ($preset !== null) {
            $fragment = _pp_udc_preset_fragment($preset, 'role');
            if (is_array($fragment) && $fragment !== []) {
                // INTERSECTED THROUGH THE SAME PREDICATE THE WRITE GATE USED.
                // The write path told the author which groups were skipped; if
                // the emitter applied them anyway, that disclosure would be a
                // lie and the page would carry design the author was told it
                // would not get.
                $split = _pp_udc_split_preset_by_permitted($fragment, $permitted);
                if ($split['applied'] !== []) {
                    $sources[] = ['preset:' . $map[PP_UDC_PRESET_KEY], $split['applied']];
                }
            }
        }
    }

    foreach ($map as $group_name => $group_map) {
        if (!is_array($group_map) || !isset($group_map[PP_UDC_PRESET_KEY])
            || !is_string($group_map[PP_UDC_PRESET_KEY])) {
            continue;
        }
        $preset = pp_udc_resolve_preset($group_map[PP_UDC_PRESET_KEY]);
        if ($preset === null) {
            continue;
        }
        $fragment = _pp_udc_preset_fragment($preset, (string) $group_name);
        if (is_array($fragment) && $fragment !== []) {
            $sources[] = [
                'preset:' . $group_map[PP_UDC_PRESET_KEY],
                [(string) $group_name => $fragment],
            ];
        }
    }

    return $sources;
}

/**
 * The emission rank of every CSS property the taxonomy can produce.
 *
 * WHY ORDER IS A CORRECTNESS PROPERTY AND NOT A STYLE PREFERENCE. Declarations
 * used to emit in the order the AUTHOR happened to write their keys, because
 * pp_udc_compile_band() iterates `$group_map` directly and PHP preserves
 * insertion order into `$resolved[$state][$bp][$property]`. Three groups carry a
 * SHORTHAND alongside its own longhands — `spacing` (`padding` / `padding-top`),
 * `border` (`width` / `width-top`, `style`, `color`, `radius`) and `background`
 * (`fill`, which emits the `background` shorthand, alongside `position`, `size`,
 * `repeat` and now `image`) — and a CSS shorthand RESETS every longhand in its
 * family. So a map whose shorthand key came second silently erased the longhand
 * the author had just written:
 *
 *     {"fill":"#fff","size":"cover"}  ->  background:#fff;background-size:cover;   (both apply)
 *     {"size":"cover","fill":"#fff"}  ->  background-size:cover;background:#fff;   (size ERASED)
 *
 * Same intent, same grammar, two different renderings, decided by key order —
 * a declared authoring input silently cancelled by another mechanism, which is
 * exactly what invariant I35 forbids. It was reachable on every band of every v2
 * component, and nothing on any surface reported it.
 *
 * Ranking by the REGISTRY's own declaration order fixes all three groups at once
 * and needs no per-group special case, because pp_udc_groups() already lists every
 * shorthand ahead of the longhands it resets. That ordering is therefore load-
 * bearing: a future param must be declared AFTER any shorthand that would reset
 * it. UdcDeclarationOrderTest::testEveryShorthandIsDeclaredBeforeItsLonghands
 * pins the registry itself so this cannot silently regress.
 *
 * The second property this buys is DETERMINISM: two maps that differ only in key
 * order now emit byte-identical CSS, which is what makes an emission diff mean
 * something.
 *
 * @return array<string,int> property => rank
 */
function _pp_udc_property_rank(): array {
    static $rank = null;
    if ($rank !== null) {
        return $rank;
    }
    $rank = [];
    $i    = 0;
    foreach (pp_udc_groups() as $group) {
        foreach ($group['params'] as $param) {
            // First declaration wins: no two params share a property today, and
            // if one ever did, the earlier declaration is the one the registry
            // order was reasoned about.
            if (!isset($rank[$param['property']])) {
                $rank[$param['property']] = $i++;
            }
        }
    }
    // The overlay's carrier is not a real CSS property and never reaches a
    // stylesheet (_pp_udc_compose_background_layers folds it away), but it has to
    // sort somewhere stable or the fold would depend on author key order again.
    $rank[PP_UDC_BACKGROUND_OVERLAY_CARRIER] = $i;
    return $rank;
}

/**
 * Sorts one (state, breakpoint) declaration set into registry order.
 *
 * Applied at the single point where a block is built, so every emission path —
 * band, chrome, component defaults, the editor preview — inherits it from one
 * place rather than four.
 */
function _pp_udc_sort_declarations(array $declarations): array {
    // A block of one cannot be out of order, and the overwhelmingly common block IS
    // one: a role usually sets a colour, or a padding, not eight properties. Skipping
    // the sort there skips a userland comparison closure per pair on every band of
    // every request, which is where the measurable cost of this fix lives.
    if (count($declarations) < 2) {
        return $declarations;
    }
    $rank = _pp_udc_property_rank();
    uksort($declarations, static function ($a, $b) use ($rank): int {
        // An unranked property (stored data naming something the registry no
        // longer declares) sorts last, keeping its relative order stable rather
        // than jumping ahead of a shorthand it might belong to.
        $ra = $rank[$a] ?? PHP_INT_MAX;
        $rb = $rank[$b] ?? PHP_INT_MAX;
        return $ra === $rb ? strcmp((string) $a, (string) $b) : $ra <=> $rb;
    });
    return $declarations;
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
/**
 * One stored fragment, bounded and cleaned for an operator-facing diagnostic.
 *
 * Delegates to the shared owner so this is not a second definition of "clean";
 * it only supplies the bound. `_pp_clean_reflected_text()` lives in lib/wp.php,
 * which loads before this file, so no guard is needed and none is written — a
 * function_exists() here would be checking a symbol that cannot be absent.
 */
function _pp_udc_reflect(string $text): string {
    return _pp_clean_reflected_text($text, PP_UDC_REFLECTED_MAX);
}

function _pp_udc_place(
    array &$resolved,
    string $state,
    array $params,
    string $param_name,
    $value,
    string $source,
    array $band_tokens,
    array $breakpoints,
    array &$referenced,
    ?array &$drops = null,
    string $where = ''
): void {
    // THE DROP LEDGER (#981, boundary-review item D3).
    //
    // Every `continue`/`return` below discards an authored value at EMIT time, and
    // until this collector existed every one of them EXCEPT the dangling-attachment
    // branch was silent on every channel — no envelope finding, no advisory, not
    // even an error_log. That one branch already had a consumer
    // (pp_check_udc_background_images) and deliberately takes no entry here, so the
    // two advisories cannot both report it. The write path
    // refuses most of these shapes, so reaching them means stored data the write
    // path never saw: a raw meta write, a composition written before a rule
    // existed, or restore_composition, which reports findings without blocking
    // (#233). The author's value is in storage, the page does not paint it, and
    // nothing says so — the reported-success-without-effect class I35 forbids.
    //
    // WHY IT LIVES HERE AND NOT IN A CHECKER THAT RE-DERIVES THE RULES. The same
    // rule the background-image advisory states (pp_check_udc_background_images,
    // lib/wp.php): ONE PREDICATE WITH THE EMITTER. A checker that re-ran these
    // conditions by hand would be a second definition of "what gets dropped" and
    // would drift from this one; diffing the emitted CSS against the stored map
    // would be worse still, because the compile folds background layers, filters
    // the defaults source and re-sorts every block, so "declared but absent" is
    // ambiguous by construction. Recording the drop AT the branch that makes it is
    // the only version that cannot disagree with itself, and it covers a drop site
    // added tomorrow for free.
    //
    // BUILT ONLY WHEN SOMEONE IS COLLECTING. This function is the hottest loop in
    // the engine — once per parameter, per role, per band, per source, on every
    // front-end request — and a closure constructed here would be an allocation on
    // every one of those calls to serve a diagnostic that is null in production
    // rendering. One null check instead.
    $note = null;
    if ($drops !== null && $source !== 'defaults') {
        // A ROLE DEFAULT'S DROP IS NOT THE AUTHOR'S PROBLEM. Defaults are repo-owned
        // schema constants, integrity-checked in CI by the schema suite; surfacing
        // one in an operator advisory would report a repo bug as site
        // misconfiguration and hand the operator a finding they cannot act on.
        // THE LOCATOR SAYS WHEN A VALUE CAME FROM A PRESET (#1016). Without it the
        // ledger points at a role and group in the author's own band that hold no
        // such value — they would go looking for something they never wrote, which
        // is the wrong-subject problem the preset origin suffix solves at the write
        // gate. Computed once per call rather than inside the closure: `$source` is
        // fixed for the whole call, and the closure runs per breakpoint.
        $where = strncmp($source, 'preset:', 7) === 0
            ? $where . sprintf(' (via preset "%s")', _pp_udc_reflect(substr($source, 7)))
            : $where;
        $note = static function (string $reason) use (&$drops, $where, $param_name, $state): void {
            // THE LEDGER IS BOUNDED AT THE SOURCE, not by its reader.
            //
            // The advisory that consumes this slices its ROWS, but slicing the output
            // does not bound the INPUT: one stored band carrying thousands of invalid
            // parameters would fill this array completely before the reader ever saw
            // it, and preflight builds it before every mutation. That is the same
            // shape as the report-that-kills-the-write-it-reports-on which
            // PP_WRITE_FINDINGS_MAX_STORED_BYTES exists to stop, one layer down.
            // Bounding here is the only place that bounds the ALLOCATION.
            if (count($drops) >= PP_UDC_MAX_EMIT_DROPS) {
                return;
            }
            // EVERY FRAGMENT HERE IS A STORED ARRAY KEY, so every one is bounded
            // and cleaned at this sink. The sibling producer states the rule and
            // the reason (pp_check_token_override_validity, lib/wp.php): these rows
            // ride the preflight envelope of EVERY mutation, and the readiness
            // `checks[]` channel is NOT inside the carve-out that lets
            // `findings[].message` copy validator text verbatim.
            $drops[] = [
                'where'  => $where . ' ' . _pp_udc_reflect($param_name)
                            . ($state !== '' ? ' (' . _pp_udc_reflect($state) . ')' : ''),
                'reason' => $reason,
            ];
        };
    }

    if (!isset($params[$param_name])) {
        // THE GROUP-GRAIN `_preset` TAKES THE SAME CARVE-OUT as the role-grain one
        // in pp_udc_compile_band(): it is not a parameter, it is the preset
        // mechanism, and it paints. Fixing only the group level would leave this
        // copy firing on `{"typography": {"_preset": "link", "size": "…"}}`.
        if ($param_name === PP_UDC_PRESET_KEY) {
            return;
        }
        // Not a parameter this group declares. The write gate refuses it; stored
        // data can still carry one, and a renamed param leaves every band holding
        // the old name in exactly this state.
        $note && $note('there is no such parameter in this group');
        return;
    }
    $property = $params[$param_name]['property'];

    // The emit-time twin of the write gate's single-valued refusal. The write
    // gate is not the only way data arrives here — a raw meta write, a
    // composition written before this rule existed, and restore_composition
    // (which reports findings without blocking, #233) all reach this line
    // directly — so the dimension the ruling excluded is closed on both sides
    // rather than on the side that happens to be polite.
    if (!empty($params[$param_name]['single_valued']) && ($state !== '' || is_array($value))) {
        $note && $note('this parameter takes one value only, so it accepts no state or breakpoint map');
        return;
    }

    $per_bp = is_array($value) ? $value : ['d' => $value];
    foreach ($per_bp as $bp => $raw) {
        if (!isset($breakpoints[$bp]) || !is_scalar($raw)) {
            $note && $note(!isset($breakpoints[$bp])
                ? sprintf('"%s" is not a breakpoint this engine knows', _pp_udc_reflect((string) $bp))
                : 'the value is not a single scalar');
            continue;
        }
        $literal       = (string) $raw;
        $css           = $literal;
        $band_ref_name = null;
        $ref_target    = null;

        $ref = pp_udc_parse_reference($literal);
        if ($ref !== null) {
            // The reference name is interpolated into `var(--pp-<name>)`, so it is
            // CSS source text too and gets the same charset gate the write path
            // applies to a token name. Stored data is the reason: the write path
            // cannot have been the only thing that ever looked at this.
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $ref)) {
                // UNBOUNDED BY CONSTRUCTION on this branch: it is reached precisely
                // BECAUSE the 64-character charset check just failed.
                $note && $note(sprintf('the reference "@%s" is not a usable token name', _pp_udc_reflect($ref)));
                continue;
            }
            $target = pp_udc_resolve_reference($ref, $band_tokens, $source === 'defaults');
            if ($target === null) {
                $note && $note(sprintf('it references "@%s", which resolves to no token', _pp_udc_reflect($ref)));
                // Unresolvable at render. The write gate refuses this, so
                // reaching here means stored-before-the-rule data: drop the one
                // declaration rather than emit `var()` of a token that does not
                // exist, and leave every sibling declaration painting.
                continue;
            }
            $css          = $target['css'];
            $literal       = $target['value'];
            $band_ref_name = $target['scope'] === 'band' ? $ref : null;
            // Carried to the re-validation below so the emitter asks the SAME
            // question the write gate asked (#972, ruling D3). Without this the two
            // gates disagree on exactly the five chain-holding tokens: a write of
            // `@btn-padding-y` would be accepted and its declaration then dropped at
            // emit, which is the write/render disagreement I29 forbids.
            $ref_target = $target;
        }

        // Emit-time re-validation — the same engine the write path used, not a
        // weaker subset of it. v1's render boundary (pp_render_style_value_allowed,
        // #330) re-runs the TYPED validator and not just the reject set, and v2
        // must not be the weaker of the two: a stored value that no longer
        // satisfies its parameter's grammar drops its own declaration and leaves
        // every sibling painting, exactly as a refused v1 slot did.
        if (_pp_forbidden_css_construct($css) !== null) {
            $note && $note('the stored value contains a construct that may not reach a stylesheet');
            continue;
        }
        // …on AUTHOR data. A role default is a repo-controlled schema constant,
        // integrity-hashed and already validated in CI by the schema suite; it is
        // not the thing a render boundary exists to guard. v1 drew the same line:
        // pp_render_style_value_allowed() re-validates the STORED style map, never
        // the CSS file's own fallbacks. Re-checking ~45 fixed constants on every
        // band of every request cost 2.6x the whole page's CSS build and bought
        // nothing an operator could ever have changed.
        //
        // A PRESET-SOURCED VALUE TAKES THE AUTHOR BRANCH, NOT THE DEFAULTS ONE.
        // Today every preset is theme-shipped and would survive either way, so
        // this costs a little and buys the Sprint-2 shape for free: custom
        // presets are SITE-STORED author data, and a tier that had been skipping
        // re-validation would silently become a hole the day they land. The gate
        // is cheap here because it runs only on bands that actually reference a
        // preset.
        // THE 8c CARVE-OUT HAS TO COME BEFORE THE GRAMMAR CHECK, not after it.
        // Placed only at the resolution branch below, a non-numeric
        // `background.image` never reached it: it failed the grammar first and was
        // ledgered here, while check 8c reported the same stored value with a
        // different reason and a different next action. One value, one classifier
        // (I25) — pp_check_udc_background_images owns every drop of this parameter,
        // well-shaped or not.
        // ONE PREDICATE, BOTH GATES (#972, ruling D3): a reference is judged by
        // _pp_udc_reference_check() here exactly as at the write gate, so what the
        // write accepts is what the page emits. A literal is value-parsed as before.
        $emit_check = $ref_target !== null
            ? _pp_udc_reference_check($ref_target, $params[$param_name])
            : pp_udc_validate_value($literal, $params[$param_name]);
        if ($source !== 'defaults' && $emit_check !== true) {
            // The type test lives INSIDE the ledger branch for the same reason the
            // closure and the locator do: it is per-breakpoint work that only a
            // collector ever reads.
            // THE 8c CARVE-OUT APPLIES ONLY WHERE 8c IS LOOKING (#1016).
            //
            // pp_check_udc_background_images() walks a stored map's OWN roles, so an
            // attachment id living inside a PRESET that a band merely references is
            // invisible to it. This carve-out exists purely on the premise that 8c
            // owns the parameter; custom presets made that premise false for
            // preset-sourced values, and a carve-out whose reason has lapsed is not
            // a carve-out — it is a drop on no channel at all. The save verb
            // verifies the attachment is live, so this is the deleted-afterwards
            // case. Extending 8c to resolve preset references is the fuller fix and
            // is filed as #1018 rather than done here.
            $owned_by_8c = ($params[$param_name]['type'] ?? '') === 'attachment_id'
                && strncmp($source, 'preset:', 7) !== 0;
            if ($note && !$owned_by_8c) {
                // THE STORED VALUE IS REFLECTED, SO IT IS BOUNDED AND CLEANED.
                // This message rides the preflight envelope of every later
                // mutation, and a stored value has no length limit of its own.
                $note && $note(sprintf(
                    'the stored value "%s" no longer satisfies this parameter\'s grammar',
                    _pp_udc_reflect($literal)
                ));
            }
            continue;
        }

        // THE ENGINE BUILDS THE url() HERE, DOWNSTREAM OF BOTH GATES.
        //
        // This ordering is the whole security argument for ruling A2, so it is
        // worth stating plainly. `_pp_forbidden_css_construct()` bans `url(` on
        // every CSS-value surface and that ban is NOT relaxed — what an author
        // sends for this parameter is a run of digits, which clears the ban
        // trivially. The `url()` is assembled only after the injection gate and
        // the typed grammar have both passed, from a string the author never
        // supplied: WordPress's own URL for an attachment that
        // pp_udc_background_image_url() has just proved is a live image on this
        // install, escaped for CSS-url() context. So the gate still refuses every
        // author-written url(), and the one url() that does reach a stylesheet
        // was built by the engine out of an id.
        //
        // QUOTED, deliberately: a quoted url token is the narrower sink, and the
        // prototype confirmed Chromium resolves it identically.
        //
        // A NULL HERE IS THE DELETED-ATTACHMENT DEGRADE. The write gate refused a
        // dangling id, so reaching this line with one means the attachment was
        // deleted AFTER a valid write. Drop this one declaration — no fatal
        // (I17), no `url()` of a dead id (I19) — and leave every sibling
        // declaration on the band painting, exactly as the unresolvable-@ref
        // branch above does. The operator is told through the preflight advisory
        // (pp_check_udc_background_images), not left to notice a blank band.
        if (($params[$param_name]['type'] ?? '') === 'attachment_id') {
            $url = pp_udc_background_image_url($literal);
            if ($url === null) {
                // NO $note HERE — check 8c (pp_check_udc_background_images) owns
                // this drop and reports it with a next_action this one cannot give
                // (re-import the image). A second entry would report it twice.
                continue;
            }
            $css = 'url("' . $url . '")';
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
    return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $id);
}

/**
 * Renders one band's scoped CSS block.
 *
 * Order: the band root's minted tokens; then, for each state in
 * pp_udc_states_in_emit_order() (base, `:hover`, `:focus-visible`, `:active`),
 * base declarations followed by `@media` blocks narrow-first; then the engine's
 * own `prefers-reduced-motion` guard, last.
 *
 * Every tier sits at identical specificity by construction, so ORDER IS THE
 * RANKING — `:active` beats `:hover` because it prints after it, and the guard
 * neutralizes the motion above it for the same reason. Every selector is
 * `[data-pp-band="<id>"]` plus the role's own selector, so specificity is flat
 * and `!important` never appears.
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
    //
    // THE ROOT TIER IS RANKED BY A CASCADE LAYER, NOT BY PRINTING FIRST (#986,
    // ruling D5 revised). It used to be ranked under the shared design-system
    // rules by source position alone: this block rides `pp-base`, components.css
    // loads after, and both sit at zero specificity, so later won. The moment the
    // v1 stylesheet went into `@layer pp-v1` that argument inverted — an
    // UNLAYERED rule beats a layered one at any specificity, so the zeroed root
    // tier would have started BEATING the band rhythm it is designed to yield to,
    // which is the Sprint-0 cascade hazard arriving from the other side.
    //
    // `pp-zero` is declared BEFORE `pp-v1` (see assets/css/base.css), so the
    // total order is exactly what the two rulings together require:
    //
    //   pp-zero (this root tier) < pp-v1 (base/components/utilities)
    //     < element defaults (unlayered, below) < authored blocks (unlayered)
    //
    // The root tier now yields to #430/#431 STRUCTURALLY rather than by load
    // order, so a plugin reordering the enqueues can no longer invert it either.
    $scope = '[data-pp-component="' . $component . '"]';

    return _pp_udc_render_blocks($compiled, $scope, ':where(' . $scope . ')', 'pp-zero');
}

/**
 * Renders a compiled result under one scope selector.
 *
 * `$root_layer` puts the BAND-ROOT rules into a named cascade layer while the
 * element rules stay where they were (#986, ruling D5 revised). Only the
 * defaults tier asks for it; see pp_udc_component_defaults_css() for why the
 * root tier has to rank under the v1 stylesheet and the element tier over it.
 *
 * When `$root_layer` is null NOTHING changes: root and element rules ride the
 * same buffer in the same order they always did, so the authored tier and the
 * chrome tier emit byte-identical CSS to before.
 *
 * THE TIER STRADDLES THE v1 STYLESHEET WHEN IT SPLITS, deliberately, and the token
 * block is on the far side from the rules that read it. A component's `_tokens`
 * defaults are root-scoped, so they go into `pp-zero` BELOW components.css, while the
 * element rules consuming them via `var(--pp-…)` emit unlayered ABOVE it. That is safe
 * because custom properties only contend on the SAME element: nothing in components.css
 * declares a `--pp-*` on a `[data-pp-component=…]` selector (the four that declare
 * `--pp-*` at all are legacy `--inverted` roots), so there is no rule positioned to beat
 * a token default. It is latent rather than broken, and it is written down here because
 * the split's tests assert the root/element RULE division and say nothing about which
 * side of v1 a token default lands on.
 */
function _pp_udc_render_blocks(
    array $compiled,
    string $scope,
    ?string $root_scope = null,
    ?string $root_layer = null
): string {
    $css      = '';
    $root_css = '';
    $split    = $root_layer !== null;
    // Rules aimed at the band root may need a different weight from rules aimed
    // at elements inside it; callers that do not care pass one scope for both.
    $root_scope = $root_scope ?? $scope;

    if ($compiled['tokens'] !== []) {
        $decls = '';
        foreach ($compiled['tokens'] as $name => $value) {
            $decls .= '--pp-' . $name . ':' . $value . ';';
        }
        if ($split) {
            $root_css .= $root_scope . '{' . $decls . '}';
        } else {
            $css .= $root_scope . '{' . $decls . '}';
        }
    }

    // Bucketed once. The emission order below is an 8-tier x 3-breakpoint
    // product, so filtering the block list inside it would walk every block
    // twenty-four times and discard almost all of them on twenty-three passes.
    $by_state_bp = [];
    foreach ($compiled['blocks'] as $block) {
        $by_state_bp[$block['state']][$block['bp']][] = $block;
    }

    // Per state, base rules then narrow-first media; states in the order
    // pp_udc_states() declares — base, :hover, :focus-visible, :active. All four
    // sit at identical specificity by construction, so the ORDER is the ranking:
    // a pressed control shows its `:active` treatment rather than the `:hover`
    // one it is also matching, because `:active` prints last.
    $motion_selectors  = [];
    $motion_properties = _pp_udc_motion_properties();

    $breakpoints_ordered = pp_udc_breakpoints_in_emit_order();
    foreach (pp_udc_states_in_emit_order() as $state) {
        // Widening to four states turned this into a 4x2x3 product, and the
        // overwhelmingly common band declares values in the base state only. Skip
        // a state with no buckets rather than walking six empty breakpoint passes
        // for it: the bucketing above already knows which states exist.
        if (!isset($by_state_bp[$state])) {
            continue;
        }
        foreach (['base', 'media'] as $tier) {
            foreach ($breakpoints_ordered as $bp => $meta) {
                $is_base = $meta['media'] === null;
                if (($tier === 'base') !== $is_base) {
                    continue;
                }
                $rules      = '';
                $root_rules = '';
                foreach (($by_state_bp[$state][$bp] ?? []) as $block) {
                    $decls = '';
                    foreach ($block['decls'] as $property => $entry) {
                        $decls .= $property . ':' . $entry['css'] . ';';
                        // KEYED BY SELECTOR **AND STATE**, and the state is the
                        // half that is easy to drop. A guard emitted without it
                        // lands at `[data-pp-band] .role` [0,2,0] while the rule
                        // it must neutralize is `[data-pp-band] .role:hover`
                        // [0,3,0] — so the guard LOSES on specificity and a
                        // reduced-motion user still gets the full transition on
                        // hover. Matching the state puts both at equal weight,
                        // where printing later is enough.
                        if (isset($motion_properties[$property])) {
                            $motion_selectors[$block['selector'] . "\0" . $state] = [$block['selector'], $state];
                        }
                    }
                    if ($decls === '') {
                        continue;
                    }
                    $is_root  = $block['selector'] === '';
                    $selector = ($is_root
                        ? $root_scope
                        : $scope . ' ' . $block['selector']) . $state;
                    if ($split && $is_root) {
                        $root_rules .= $selector . '{' . $decls . '}';
                    } else {
                        $rules .= $selector . '{' . $decls . '}';
                    }
                }
                if ($rules !== '') {
                    $css .= $is_base ? $rules : '@media ' . $meta['media'] . '{' . $rules . '}';
                }
                if ($root_rules !== '') {
                    $root_css .= $is_base
                        ? $root_rules
                        : '@media ' . $meta['media'] . '{' . $root_rules . '}';
                }
            }
        }
    }

    // THE GUARD FOLLOWS ITS DECLARATIONS INTO THE LAYER. A guard emitted outside
    // the layer that holds the motion it neutralizes would outrank it always
    // rather than by printing last, which is a different mechanism with a
    // different failure mode; and one emitted inside the WRONG layer would lose
    // outright. Split the same way the declarations were split.
    if ($split) {
        $root_css .= _pp_udc_reduced_motion_guard($motion_selectors, $scope, $root_scope, 'root');
        $css      .= _pp_udc_reduced_motion_guard($motion_selectors, $scope, $root_scope, 'element');
        return ($root_css !== '' ? '@layer ' . $root_layer . '{' . $root_css . '}' : '') . $css;
    }

    $css .= _pp_udc_reduced_motion_guard($motion_selectors, $scope, $root_scope);

    return $css;
}

/**
 * The `prefers-reduced-motion` guard the ENGINE emits for its own motion values.
 *
 * Ruling A3 makes reduced motion STRUCTURAL: not an authored value, not
 * authorable, emitted by the engine. This is that.
 *
 * WHAT IT IS AND IS NOT, stated plainly because the honest version is easy to
 * overstate. `assets/css/base.css` already carries a global
 * `*, *::before, *::after { transition-duration: 0.01ms !important }` under the
 * same query — §2 names reduced motion as an accessibility affordance that lives
 * in structural CSS, and that rule stays. While it is there, IT is what a
 * reduced-motion user's browser actually obeys, and this guard changes nothing
 * for them. What this guard buys is that a band block is self-consistent: the
 * motion an author declares here is neutralized by a rule emitted here, so it
 * does not depend on a shared structural rule that a later assets/css change
 * could narrow or move. A prototype confirmed the difference is real — with the
 * global rule absent, a guarded element drops to 0.01ms while an unguarded
 * sibling keeps its 900ms.
 *
 * It is emitted LAST, in the same scope, AND WITH THE SAME STATE SUFFIX as the
 * declarations it neutralizes, so it wins on source order. Carrying the state is
 * not a detail: motion declared inside `:hover` emits at `[data-pp-band] .role:hover`
 * [0,3,0], and a guard emitted at the bare `[data-pp-band] .role` [0,2,0] loses
 * on specificity — the transition would keep running for exactly the users the
 * guard exists to serve. No `!important`: §3.4 forbids the engine ever emitting
 * one, and at equal specificity printing later is all it takes.
 *
 * Emitted only for (selector, state) pairs that actually received a motion
 * property, so a band that declares no motion pays nothing.
 *
 * The REMEDY is one declaration while the TRIGGER is derived from the motion
 * registry, so a third motion param would widen detection without widening
 * neutralization. Zeroing the duration is sufficient for both params that exist
 * (a timing function over 0.01ms is unobservable); add `transition-delay` and
 * this needs a per-param remedy rather than a constant.
 *
 * @param array  $motion_selectors key => [selector, state]
 * @param string $want             'all', or 'root'/'element' to emit only the
 *                                 half that belongs in one cascade layer.
 */
function _pp_udc_reduced_motion_guard(
    array $motion_selectors,
    string $scope,
    string $root_scope,
    string $want = 'all'
): string {
    if ($motion_selectors === []) {
        return '';
    }
    $selectors = [];
    foreach ($motion_selectors as [$selector, $state]) {
        $is_root = $selector === '';
        if (($want === 'root' && !$is_root) || ($want === 'element' && $is_root)) {
            continue;
        }
        $selectors[] = ($is_root ? $root_scope : $scope . ' ' . $selector) . $state;
    }
    if ($selectors === []) {
        return '';
    }
    return '@media (prefers-reduced-motion: reduce){'
        . implode(',', $selectors)
        . '{transition-duration:0.01ms;}}';
}

/**
 * Both layers concatenated, in order. FOR TESTS — NOT FOR ANY SINK.
 *
 * NOTHING THAT EMITS CSS MAY CALL THIS, and the rule is enforced rather than
 * requested: PreviewCascadeParityTest fails if any file outside tests/ names it.
 *
 * The reason is the whole of §3.4, RESTATED for cascade layers (#986) because the
 * original argument is now only half true and a tripwire whose stated reason has
 * expired is one a future maintainer deletes as obsolete.
 *
 * What changed: the band-ROOT half of the defaults tier is protected by `@layer
 * pp-zero` wherever it prints, and the ELEMENT half is unlayered and already beats
 * the design-system rules from either position. So flattening no longer inverts
 * defaults-vs-stylesheet the way it did.
 *
 * What did NOT change, and is why this stays forbidden: the defaults tier and the
 * AUTHORED tier are both unlayered, so their order relative to each other is still
 * decided by nothing but source position — defaults first, authored after. A single
 * string holds one position. Paste both into one <style> and an authored value stops
 * reliably outranking a role default, which is the one ranking layers do not express
 * here. The editor preview did exactly that until the two-block fix.
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
 * Printed BEFORE the theme stylesheets (see functions.php), which still fixes this
 * tier's order against the AUTHORED tier — both are otherwise unlayered.
 *
 * WHAT KEEPS AN UNAUTHORED BAND INSIDE #430/#431 IS NO LONGER PRINT ORDER (#986).
 * It is `@layer pp-zero`: the band-root rules this emits sit in a layer strictly
 * below the v1 stylesheet, so the shared adjacent-band rhythm wins structurally
 * rather than by loading later. A component that needs its own rhythm opts out via a
 * `:not()` on the shared rule — hero's #577 opener rhythm is the worked example.
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

// ── Chrome: the site-level UDC container (Addendum A, ruling A1) ────────────
//
// Chrome is the nav and the footer: rendered once by templates/base.php on EVERY
// page, never composed (a composition naming them is refused outright with
// `template_owned_component`). So the one thing a band's `udc` map cannot supply
// is where chrome's would live — there is no band to hang it on.
//
// Ruling A1's answer is a SITE-level container with the identical internal shape:
//
//   pp_site_udc = {"_version": 7,
//                  "nav":    {"_tokens": {...}, "_band": {...}, "link": {...}},
//                  "footer": {"_tokens": {...}, "_band": {...}}}
//
// Each chrome entry is byte-for-byte the shape a band's `udc` is, and it goes
// through pp_udc_validate_map() — the SAME function, the same grammar, the same
// presets, states, motion, minting, provenance and refusal codes. There is no
// second validator and no second grammar anywhere in this section; that is the
// one-predicate rule (pp_style_declaration_renders(), lib/wp.php) applied to a
// new surface rather than quietly excepted from it.
//
// Emission is `[data-pp-chrome="<name>"]` instead of `[data-pp-band="<id>"]`, and
// rides the same two-layer cascade as every band: defaults on `pp-base` (before
// the theme stylesheets), authored on `pp-utilities` (after them).

/** The option holding every chrome component's `udc` map. */
const PP_SITE_UDC_OPTION = 'pp_site_udc';

/**
 * The key carrying the CAS baseline INSIDE the map.
 *
 * NOT a sibling option, and that is the whole point. Ruling A1 requires chrome
 * writes to be CAS-covered (invariant I8), and a version kept in a second option
 * row would need two writes that cannot be made atomic — precisely the torn-write
 * shape already recorded on pp_update_composition(), where a death between the
 * content write and the version write leaves the marker certifying content that
 * is not there and the next writer clears a CAS it should have failed. One row,
 * one update_option(), one atomic swap of content AND baseline together.
 *
 * SINCE #1016 THE ROW CARRIES TWO BASELINES, this one for chrome and
 * PP_SITE_PRESETS_VERSION_KEY for the preset store. The argument above is
 * unchanged and is in fact why both live here: two counters in ONE row still swap
 * atomically with the content they certify, where two ROWS could not. What the
 * second counter buys is that neither tenant's write makes the other's baseline
 * stale.
 *
 * It lives in the engine-owned `_`-prefixed namespace alongside `_tokens`,
 * `_band` and `_preset`, so it can never collide with a chrome component name
 * (those are registry-controlled and carry no underscore).
 */
const PP_SITE_UDC_VERSION_KEY = '_version';

/**
 * Bounds on the stored map, checked BEFORE validation walks it.
 *
 * Every other producer spliced into this engine is bounded by a theme constant —
 * the role list, the group registry, the breakpoint set. This one is bounded by
 * whatever an author sent, and it is autoloaded on every request, so it needs its
 * own ceiling in the same spirit as PP_FOOTER_SOCIAL_MAX. The depth cap matters
 * independently of the byte cap: json_decode() on deeply nested input can exhaust
 * the stack before any rule of ours runs.
 *
 * 64 KB is roughly thirty times the largest realistic chrome map; the cap is there
 * to stop abuse, not to ration design. Depth 8 is the deepest LEGAL shape plus
 * one: top -> chrome -> role -> group -> param -> state -> breakpoint is seven.
 */
const PP_SITE_UDC_MAX_BYTES = 65536;
const PP_SITE_UDC_MAX_DEPTH = 8;

/**
 * The custom-preset subtree of the site container, and its own CAS baseline (#1016).
 *
 * TWO SUBTREES, ONE ROW, TWO COUNTERS. Ruling A3 says custom presets are
 * site-stored; ruling A1 already built a site-scoped container with an advisory
 * lock, a baseline INSIDE the value, a cache-bypassing row read for the compare,
 * a byte ceiling and a fail-closed reader. A sibling option would be a second copy
 * of all of it, so presets move in here instead — under an engine-owned
 * `_`-prefixed key, which by construction cannot collide with a chrome component
 * name (those are registry-controlled and carry no underscore).
 *
 * The BASELINE is not shared, and that is deliberate rather than tidy. One counter
 * would mean a chrome write invalidates every preset baseline a caller is holding
 * and vice versa — a conflict refusal for a reason that has nothing to do with
 * what the caller read. A guarantee that refuses for unrelated reasons teaches
 * callers to stop sending baselines, which is how I8 gets lost in practice. Two
 * counters in ONE row keep both compares honest and both writes atomic.
 *
 * WHAT MAKES THAT SAFE IS THE PRESERVE-FOREIGN-SUBTREE RULE, not the counters:
 * every writer reads the current row inside the lock, patches only the subtree it
 * owns, and carries the other one forward. Both halves of that were BROKEN when
 * this key was designed — pp_udc_normalize_site_map() rebuilt the container from
 * chrome names alone, and the clear arm deleted the whole row — so the rule is
 * pinned in both directions rather than left as an intention.
 *
 *     {"_version": 4,            <- chrome's baseline
 *      "_presets_version": 2,    <- the preset store's baseline
 *      "_presets": {"brand-cta": {"grain": "role", "udc": {…}}},
 *      "nav": {…}, "footer": {…}}
 */
const PP_SITE_PRESETS_KEY         = '_presets';
const PP_SITE_PRESETS_VERSION_KEY = '_presets_version';

/**
 * Two bounds on the preset store, because one cannot describe both failures.
 *
 * The container's 64 KB ceiling is SHARED with chrome, so an unbounded preset
 * store can crowd chrome styling out of a row that still validates — a chrome
 * write would then be refused for a reason the author cannot see from the chrome
 * write. Capping the COUNT alone does not close that: one enormous preset reaches
 * the same place. So both are bounded, and the refusal names which bound it hit —
 * "too many presets" over a byte exhaustion would be a message that sends the
 * author to delete rows when the fix is to shrink one.
 *
 * 64 presets is far past any real design system's shared-bundle count; 8 KB is
 * roughly ten times the largest system preset this theme ships.
 */
const PP_SITE_PRESETS_MAX     = 64;
const PP_SITE_PRESET_MAX_BYTES = 8192;

/**
 * The chrome components, derived from the template-owned list rather than retyped.
 *
 * ONE LIST, TWO READERS. pp_template_owned_components() already decides which
 * components the composition validator REFUSES; this decides which ones the site
 * container ACCEPTS. Those two sets are the same set by definition — a component
 * is chrome exactly when it is rendered by the template and not composable — and
 * writing the names twice would let them drift into a component that is refused
 * by both surfaces, or accepted by both.
 */
function pp_udc_chrome_names(): array {
    if (!function_exists('pp_template_owned_components')) {
        return [];
    }
    $names = pp_template_owned_components();
    return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
}

/** True when `$name` is a chrome component the site container may carry. */
function pp_udc_is_chrome(string $name): bool {
    // DELEGATES RATHER THAN RE-ASKS. pp_is_template_owned_component() already owns
    // this membership test, and its docblock says why it was named: "so the rule
    // reads the same at each call site and there is one place to change if the list
    // ever stops being a flat array of names". A second in_array() here would be a
    // second place, and the two could already disagree — pp_udc_chrome_names()
    // filters non-strings out and a raw in_array() would not.
    return function_exists('pp_is_template_owned_component')
        && pp_is_template_owned_component($name);
}

/**
 * The stored chrome container, read FAIL-CLOSED.
 *
 * Returns the six-key shape pp_udc_parse_site_map() documents — `version`,
 * `chrome`, `corrupt`, `presets`, `presets_version`, `presets_unreadable` — on
 * every answer. A row that is absent, unparseable, or not an object resolves to
 * version 0 and NO chrome styling and NO presets — never to a partial map, and
 * never to a fatal.
 *
 * Both halves of that are invariants rather than taste. I9: a failed read is never
 * mapped to a valid answer, so a corrupt row must not read as "the author styled
 * nothing", which is indistinguishable from a clean empty site and would let the
 * next write clobber a map that was merely unreadable — the version it reports is
 * 0, which no real write can have produced, so a caller holding a real baseline
 * gets a conflict rather than a silent overwrite. I17: no surface fatals on stored
 * data, and this one is read on EVERY front-end request, so a TypeError here is a
 * white screen on every page of the site rather than a broken band.
 *
 * `json_decode` returning null is ambiguous between "invalid JSON" and "the string
 * was literally `null`"; both are corrupt for this option, so the ambiguity does
 * not need resolving — but the shape check is what decides, not the null.
 */
function pp_udc_site_map(): array {
    $raw = get_option(PP_SITE_UDC_OPTION, '');
    if (!is_string($raw)) {
        $raw = '';
    }

    // DECODE ONCE PER REQUEST, keyed on the stored bytes.
    //
    // Chrome CSS is built on every front-end request and the option is read by the
    // defaults tier, the authored tier and each chrome component's compile — four
    // reads of the same row, four json_decodes, for one page. Keying the cache on
    // the RAW STRING rather than on "have I run yet" is what keeps it honest: a
    // write during the same request changes the bytes and the cache misses, so this
    // can never serve a stale map back to the code that just wrote one.
    //
    // NOTE FOR THE WRITE PATH: this reads through get_option(), which serves an
    // autoloaded row from the request-local options cache. That is right for
    // rendering and WRONG for a compare-and-swap, which needs to see a concurrent
    // process's just-committed value. The CAS uses _pp_read_site_udc_locked()
    // instead; see its docblock.
    static $cached_raw = null;
    static $cached     = null;
    if ($cached_raw === $raw && $cached !== null) {
        return $cached;
    }
    $cached_raw = $raw;
    $cached     = pp_udc_parse_site_map($raw);
    return $cached;
}

/**
 * THE ONE GRAMMAR for "what is in this option row?", given its raw bytes.
 *
 * Extracted from pp_udc_site_map() when the CAS needed a cache-bypassing read
 * (_pp_read_site_udc_locked). Two readers, one parse: a second hand-rolled copy
 * would be a second set of rules about what counts as corrupt, and the one that
 * drifted would either refuse writes on a readable row or accept them on an
 * unreadable one.
 *
 * Returns `['version' => int, 'chrome' => [name => map], 'corrupt' => bool,
 * 'presets' => [name => preset], 'presets_version' => int,
 * 'presets_unreadable' => [name, …]]` — the same six keys on every return, healthy
 * or not. `presets_unreadable` names the members this parser had to drop, because
 * the WRITER rebuilds the subtree from `presets` and would otherwise delete them as
 * a side effect of an unrelated save.
 *
 * ABSENT AND CORRUPT ARE DIFFERENT ANSWERS, and conflating them is a data-loss bug
 * rather than a tidiness one. Both yield NO chrome styling — that part is the same
 * — but a caller holding a CAS baseline must be able to tell "nothing was ever
 * written here, version 0" from "something is written here and I could not read
 * it". Reporting the second as the first lets a write with `expected_version: 0`
 * pass the compare and overwrite bytes the operator would want back. That is
 * invariant I9's "a failed read is never mapped to a valid answer", and the valid
 * answer it was being mapped to was the empty site.
 */
function pp_udc_parse_site_map(string $raw): array {
    // ONE SHAPE ON EVERY RETURN, and it stopped being free the moment the
    // container grew a second subtree (#1016). Four early returns used to spell
    // the empty answer as a literal; a fifth key added to the populated answer
    // alone would make `$site['presets']` defined on a healthy row and undefined
    // on an absent one — a distinction no caller wants and every caller would
    // eventually trip over. Derived once, so a sixth key cannot reintroduce it.
    $empty = static function (bool $corrupt): array {
        return [
            'version'            => 0,
            'chrome'             => [],
            'corrupt'            => $corrupt,
            'presets'            => [],
            'presets_version'    => 0,
            'presets_unreadable' => [],
        ];
    };
    if (trim($raw) === '') {
        return $empty(false);
    }
    if (strlen($raw) > PP_SITE_UDC_MAX_BYTES) {
        return $empty(true);
    }

    $decoded = json_decode($raw, true, PP_SITE_UDC_MAX_DEPTH);
    // CORRUPT IS A STATEMENT ABOUT THE CONTAINER, NOT ITS MEMBERS, and the line is
    // drawn there on purpose. Unparseable, or parsed into something that is not a
    // JSON OBJECT (a list, a scalar, null) means the row is not the kind of thing
    // this option holds, so the `_version` in it — if any — cannot be trusted and no
    // baseline may be checked against it. A well-formed object whose individual
    // chrome entries are junk is a DIFFERENT case: the container read fine, the
    // version is real, and the junk member simply contributes no styling. Treating
    // that as corrupt would refuse baselined writes on a row the engine can read
    // perfectly well.
    if (!is_array($decoded)
        || json_last_error() !== JSON_ERROR_NONE
        || (function_exists('pp_is_list') && pp_is_list($decoded) && $decoded !== [])) {
        return $empty(true);
    }

    $out = $empty(false);
    if (isset($decoded[PP_SITE_UDC_VERSION_KEY]) && is_scalar($decoded[PP_SITE_UDC_VERSION_KEY])) {
        $version = (string) $decoded[PP_SITE_UDC_VERSION_KEY];
        // Reject, never coerce: a non-numeric version is a corrupt marker, and
        // reading it as 0 would hand a caller a baseline the store never issued.
        $out['version'] = preg_match('/^[0-9]+$/', $version) ? (int) $version : 0;
    }
    // THE PRESET BASELINE READS EXACTLY LIKE THE CHROME ONE (#1016), including the
    // parts that look like omissions. Absent means 0, which is also what a row
    // written before this key existed reports — that is the migration story, and it
    // needs no migration: the first preset write persists the key at 1, and until
    // then a caller holding baseline 0 is holding the truth. A malformed marker
    // also reads 0 WITHOUT flagging the container corrupt, because `corrupt` is a
    // statement about the container and not its members (see above); a junk
    // baseline on a readable row refuses every baselined preset write by
    // mismatching, which is the safe direction.
    if (isset($decoded[PP_SITE_PRESETS_VERSION_KEY]) && is_scalar($decoded[PP_SITE_PRESETS_VERSION_KEY])) {
        $presets_version = (string) $decoded[PP_SITE_PRESETS_VERSION_KEY];
        $out['presets_version'] = preg_match('/^[0-9]+$/', $presets_version) ? (int) $presets_version : 0;
    }
    if (isset($decoded[PP_SITE_PRESETS_KEY]) && is_array($decoded[PP_SITE_PRESETS_KEY])) {
        foreach ($decoded[PP_SITE_PRESETS_KEY] as $name => $preset) {
            // FAIL CLOSED PER MEMBER, exactly as a junk chrome entry does: a row
            // that decoded fine but holds one unusable preset contributes no
            // preset rather than a half-shaped one the resolver would hand to the
            // compiler. `json_decode` turns an all-digit key into an INTEGER array
            // key, so the cast is not cosmetic — pp_udc_valid_preset_name() takes a
            // string, and a preset named "7" is a name an author can legally pick.
            $name = (string) $name;
            if (!pp_udc_valid_preset_name($name) || !_pp_udc_is_preset_shaped($preset)) {
                // RECORDED, NOT JUST SKIPPED. Dropping it from the registry is right
                // — an unusable preset must not reach the compiler. Forgetting that
                // it existed is not: the WRITER rebuilds `_presets` from this array,
                // so a silent drop turns "save an unrelated preset" into "delete the
                // row nobody could parse". The writer refuses instead, and it needs
                // this list to say which row to look at.
                $out['presets_unreadable'][] = $name;
                continue;
            }
            $out['presets'][$name] = $preset;
        }
    }
    foreach (pp_udc_chrome_names() as $name) {
        if (isset($decoded[$name]) && is_array($decoded[$name])) {
            $out['chrome'][$name] = $decoded[$name];
        }
    }
    return $out;
}

/**
 * True when a stored value has the shape the resolver may hand to the compiler.
 *
 * The same two fields _pp_udc_preset_fragment() reads, checked before it reads
 * them. A stored preset comes off a row that a raw `wp option update` can write,
 * so "the write gate accepted it" is never a premise the READER may rely on — the
 * identical reason pp_udc_parse_site_map() fails closed on the container.
 */
function _pp_udc_is_preset_shaped($preset): bool {
    return is_array($preset)
        && isset($preset['grain']) && is_string($preset['grain']) && $preset['grain'] !== ''
        && isset($preset['udc']) && is_array($preset['udc']);
}

/**
 * Validates the whole chrome container, as submitted.
 *
 * Runs the shape rules this container owns — the ones about which KEYS may
 * appear — and then hands every chrome entry to pp_udc_validate_map(), which owns
 * everything about what is INSIDE one. That split is deliberate: the container is
 * new, the contents are not, and re-implementing "is this a legal udc map" here
 * would be the forked validator the one-predicate rule forbids.
 *
 * AN UNKNOWN TOP-LEVEL KEY IS REFUSED, NOT IGNORED, and that is what satisfies
 * ruling A1's out-of-scope clause. Per-page chrome overrides are a future ruling;
 * the shape someone would reach for is a page id or a `pages` block beside `nav`,
 * and if this accepted-and-dropped it, the author would be told the write
 * succeeded and get nothing — the reported-success-without-effect class I35
 * forbids. So anything that is not `_version` or a known chrome name refuses, and
 * the message says per-page chrome is not available rather than leaving the author
 * to guess whether they misspelled `footer`.
 *
 * @param  mixed $decoded The decoded container.
 * @return WP_Error|null
 */
function pp_udc_validate_site_map($decoded): ?WP_Error {
    if (!is_array($decoded) || $decoded === []) {
        return new WP_Error('invalid_option_value', sprintf(
            'Option "%s" must be a JSON object of chrome components (%s), each holding a udc map.',
            PP_SITE_UDC_OPTION,
            implode(', ', pp_udc_chrome_names()) ?: '(none registered)'
        ));
    }

    $names = pp_udc_chrome_names();
    foreach ($decoded as $key => $value) {
        $key = (string) $key;
        if ($key === PP_SITE_UDC_VERSION_KEY) {
            // ACCEPTED AND THEN IGNORED, deliberately, and the caller is told so.
            //
            // The natural way to edit chrome is read-modify-write: fetch the option,
            // change one role, send the whole object back. That round trip carries
            // the `_version` the engine wrote, so REFUSING it here would break the
            // obvious workflow to protect a field nobody sets by hand. It is
            // validated for shape and then replaced with current+1 on write.
            //
            // THE BASELINE IS THE `expected_version` PARAM, NOT THIS. A caller who
            // believes otherwise gets no concurrency protection at all while
            // thinking they have it, so the message says which one is load-bearing.
            if (!is_scalar($value) || !preg_match('/^[0-9]+$/', (string) $value)) {
                return new WP_Error('invalid_option_value', sprintf(
                    'Option "%s" key "%s" must be a whole number. The engine maintains it, and a value you '
                    . 'send here is ignored — pass the baseline as the action\'s `expected_version` param '
                    . 'if you want the write checked for conflicts.',
                    PP_SITE_UDC_OPTION,
                    PP_SITE_UDC_VERSION_KEY
                ));
            }
            continue;
        }
        // THE PRESET SUBTREE IS ENGINE-OWNED AND GETS A ROUTE, NOT A REJECTION
        // (#1016, invariant I24). It shares this row, so a caller who read the
        // stored bytes back — the action reports them as `to` — has both keys in
        // hand and will send them again on the next chrome write.
        //
        // REFUSED RATHER THAN ACCEPTED-AND-IGNORED, which is the treatment
        // `_version` gets four lines up, and the asymmetry is deliberate. An
        // ignored `_version` costs nothing: the engine rewrites it with the same
        // number the caller would have wanted. An ignored `_presets` is a map of
        // real design the caller believes they just wrote, and accepting it
        // silently would be the reported-success-without-effect class I35 forbids.
        // So it refuses, and says both halves of what to do: drop the key, and
        // where the verbs are.
        if ($key === PP_SITE_PRESETS_KEY || $key === PP_SITE_PRESETS_VERSION_KEY) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" key "%s" is the preset store, which this action does not write. '
                . 'Presets are created and removed with `save_preset` and `delete_preset`, one preset '
                . 'per call. Drop "%s" from this chrome write — the store is preserved automatically, '
                . 'so leaving it out never loses a preset.',
                PP_SITE_UDC_OPTION,
                $key,
                $key
            ));
        }
        if (!in_array($key, $names, true)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" has no chrome component "%s". Available: %s. '
                . 'Chrome styling is site-wide: there is no per-page chrome override, so a page id or '
                . 'slug is not a valid key here. Style a single page through its bands instead.',
                PP_SITE_UDC_OPTION,
                $key,
                implode(', ', $names) ?: '(none registered)'
            ));
        }
        if (!is_array($value)) {
            return new WP_Error('invalid_option_value', sprintf(
                'Option "%s" chrome component "%s" must be an object of roles; got %s.',
                PP_SITE_UDC_OPTION,
                $key,
                function_exists('_pp_schema_value_for_message') ? _pp_schema_value_for_message($value) : gettype($value)
            ));
        }
        // THE SAME ENGINE. Not a chrome-flavoured copy of it.
        $error = pp_udc_validate_map($value, $key);
        if ($error !== null) {
            // THE INNER CODE TRAVELS. Flattening every engine refusal into
            // `invalid_option_value` would mean the SAME authoring mistake reports
            // `unknown_udc_role` on a band and something else on chrome — on a
            // surface whose whole claim is that it is validated by the same engine
            // and the same grammar. The message already names the exact place; the
            // code is the half a caller can branch on.
            return new WP_Error(
                $error->get_error_code(),
                sprintf('Option "%s": %s', PP_SITE_UDC_OPTION, $error->get_error_message())
            );
        }
    }
    return null;
}

/**
 * Normalizes a submitted container for storage: mints responsive values, sets the
 * next chrome baseline, and CARRIES THE PRESET SUBTREE FORWARD.
 *
 * Minting goes through pp_udc_normalize_band() — the band normalizer, unchanged —
 * by wrapping each chrome entry in the item shape it expects. Chrome therefore
 * mints identical names to a band and the `udc_token_minted` disclosure means the
 * same thing on both surfaces.
 *
 * THE LAST TWO ARGUMENTS ARE THE PRESERVE-FOREIGN-SUBTREE RULE, and they are
 * required rather than optional because of how this function failed. It builds
 * `$out` from scratch and copies in the keys it knows about. That was complete
 * while chrome was the only tenant. The moment custom presets moved into this row
 * (#1016) it became a function that silently DELETES the preset store on every
 * chrome write — an author restyling their nav would lose every shared bundle on
 * the site, with an `ok: true` over it.
 *
 * Defaulting them would have left the same hole one careless call site away, so
 * there is no default: a caller must state what the row already holds. The caller
 * that knows is the one inside the advisory lock, which has just read the row
 * (_pp_update_site_udc). A chrome write never touches `_presets_version`, so a
 * preset baseline a caller is holding survives a chrome write — which is the
 * whole reason the two counters are separate.
 *
 * @param array $presets         The stored preset subtree, carried through untouched.
 * @param int   $presets_version The stored preset baseline, carried through untouched.
 */
function pp_udc_normalize_site_map(
    array $decoded,
    int $next_version,
    array $presets,
    int $presets_version
): array {
    $chrome = [];
    foreach (pp_udc_chrome_names() as $name) {
        if (!isset($decoded[$name]) || !is_array($decoded[$name])) {
            continue;
        }
        $item = pp_udc_normalize_band(['component' => $name, 'udc' => $decoded[$name]]);
        $chrome[$name] = isset($item['udc']) && is_array($item['udc']) ? $item['udc'] : $decoded[$name];
    }
    return pp_udc_site_container($chrome, $next_version, $presets, $presets_version);
}

/**
 * THE ONE PLACE THAT DECIDES WHAT A STORED SITE CONTAINER LOOKS LIKE (#1016).
 *
 * Separated from the normalizer because the two writers need the same SHAPE and
 * opposite treatment of the data. A chrome write normalizes the chrome it was
 * sent — minting responsive literals into band tokens — and carries the presets.
 * A preset write does the reverse, and MUST NOT re-normalize the chrome it is
 * carrying: that chrome was normalized when it was written, re-running the band
 * normalizer over it is work nobody asked for on a verb that is not about chrome,
 * and "carried forward untouched" stops being true the moment something touches
 * it. One builder keeps the key set and its ordering identical either way.
 *
 * The preset MAP is omitted when the store is empty. The preset BASELINE is
 * omitted only on a site that has NEVER had a preset — a counter that outlived its
 * map must keep outliving it, or deleting the last preset rewinds it. So a
 * pre-#1016 row still stores neither key and stays byte-identical, with no
 * migration, while a site that has used presets keeps its baseline even at zero
 * presets.
 */
function pp_udc_site_container(
    array $chrome,
    int $version,
    array $presets,
    int $presets_version
): array {
    $out = [PP_SITE_UDC_VERSION_KEY => $version];
    // THE BASELINE OUTLIVES THE STORE, and gating both keys on a non-empty map was
    // an ABA bug. Create a preset then delete it and the counter went back to
    // absent, which reads as 0 — so a caller still holding the baseline it earned
    // before either write passed the compare and overwrote whatever had happened
    // in between. A counter that can go backwards is not a counter. It is written
    // from the first preset write onward, and only a site that has never had one
    // stores neither key (which is what keeps every pre-#1016 row byte-identical).
    if ($presets !== [] || $presets_version > 0) {
        $out[PP_SITE_PRESETS_VERSION_KEY] = $presets_version;
    }
    if ($presets !== []) {
        $out[PP_SITE_PRESETS_KEY] = $presets;
    }
    foreach ($chrome as $name => $map) {
        $out[(string) $name] = $map;
    }
    return $out;
}

/**
 * The findings a chrome write's envelope carries (#993).
 *
 * THE POINT OF THIS FUNCTION IS THAT IT IS NOT A SECOND ENGINE. It reads the stored
 * container, wraps each chrome entry in the item shape the composition findings engine
 * already accepts — the SAME wrap pp_udc_normalize_site_map() performs to reuse the band
 * normalizer — and hands the list to pp_udc_composition_findings(). The T2 sub-ruling's
 * clause 4 ("the write gate and the emitter intersect through one predicate, so what the
 * envelope reports as skipped is what the page omits") is satisfied here by IDENTITY
 * rather than by discipline: there is no chrome copy of the walk that could drift.
 *
 * WHAT WAS BROKEN. Chrome writes produced no `findings` at all, because the engine
 * requires a list-shaped composition and nothing ever built one for chrome. Two of the
 * engine's four disclosures were therefore unreachable on a supported authoring path:
 *
 *     wp pp action execute update_site_option --params='{"key":"pp_site_udc",
 *       "value":"{\\"nav\\":{\\"link\\":{\\"typography\\":{\\"size\\":{\\"d\\":\\"19px\\",\\"p\\":\\"15px\\"}}}}}"}'
 *
 * returned ok:true with the two literals silently rewritten to minted band-token
 * references and no `udc_token_minted` anywhere — the §3.1 no-coercion promise the
 * runtime prompt makes to the model by name, unkept on this surface only. #993 filed the
 * PRESET-SKIP half, which stays latent until Sprint-2 custom presets; the minting half
 * was live at 2.0.0-alpha.1.
 *
 *     band item  ─┐
 *                 ├─► pp_udc_composition_findings() ─► udc_token_minted
 *     chrome entry┘        (one engine)                 udc_unused_band_token
 *       (wrapped here)                                  udc_preset_groups_skipped
 *                                                       udc_band_value_shadowed_...
 *
 * THE `index` IS DROPPED, and that is not cosmetic. The engine stamps the list offset,
 * which is a COMPOSITION offset; on chrome it names no band and would be exactly the
 * fabricated locator I26 forbids. `null` is the value the assembler already uses for a
 * finding no single band owns, and every message here already names its component.
 *
 * NO AVAILABILITY GATE, unlike _pp_write_findings_for(). That gate exists because a
 * composition is unbounded and the engines materialise every finding before anything
 * bounds them. This container cannot exceed PP_SITE_UDC_MAX_BYTES, enforced on both
 * write arms in lib/wp.php, so the walk is bounded by construction. Stated because the
 * absence would otherwise read as an oversight.
 *
 * `udc_band_value_shadowed_by_role_default` STARTED SPEAKING WITH #994, exactly as the
 * note here predicted it would. It used to yield nothing at all, because chrome shipped
 * EMPTY role defaults and there was no default for an authored value to be shadowed by.
 * Chrome's whole resting appearance is role defaults now, so an author whose value loses
 * to one is told — which is the disclosure doing its job rather than a change to it.
 *
 * @return array<int, array{type: string, message: string, index: null}>
 */
function pp_udc_site_findings(): array {
    try {
        return _pp_udc_site_findings_unguarded();
    } catch (\Throwable $e) {
        // REPORT-ONLY MUST NOT BE ABLE TO TAKE DOWN THE WRITE IT REPORTS ON.
        //
        // This runs AFTER the option row has been written, so a Throwable here would
        // turn a change that HAPPENED into a failed action envelope — and a client that
        // retries on failure would re-send a whole-container chrome write, against a
        // version that has already moved. Losing the disclosure is a bad outcome; losing
        // the disclosure AND provoking a clobbering retry is a much worse one.
        //
        // The same posture, and the same idiom, the emit-drop probe uses one file over
        // (pp_check_udc_emit_drops, lib/wp.php): a diagnostic must survive the corruption
        // it exists to report (I17), and must not fail silently while doing it (I29) —
        // hence the log, which is for the DEVELOPER, not the operator.
        //
        // Unreachable through the shipped readers as far as the tests can reach: the
        // container read is fail-closed and the engine is typed. That is the point of a
        // guard on a path where being wrong costs a landed write.
        error_log(
            'PromptingPress: chrome findings probe failed: '
            . get_class($e) . ': ' . $e->getMessage()
        );

        // A SKIP IS NOT A CLEAN BILL OF HEALTH (I29), and an empty array would read as
        // one. The composition path already treats this as a trap and has a species for
        // it — `findings_skipped`, "nothing was counted here" — so chrome uses the same
        // word rather than coining a second one for the same state. Without this the
        // envelope says `findings: []` whether the probe found nothing or could not run,
        // and the runtime prompt tells the model to read that array rather than assume.
        return [[
            'type'     => 'findings_skipped',
            'severity' => 'warning',
            // SUBJECT-NEUTRAL, because this row now rides preset envelopes too. Saying
            // "the chrome disclosure report" on a `save_preset` result names the wrong
            // subject — the same class the `$subject` parameter was threaded through
            // the validators to fix.
            'message'  => 'The disclosure report for this write could not be built, so this '
                          . 'envelope says nothing about what the engine normalized. The write itself '
                          . 'landed. Read the stored map with `wp pp operate inspect`.',
            'index'    => null,
        ]];
    }
}

/** The body of pp_udc_site_findings(), separated so the guard above reads as one line. */
function _pp_udc_site_findings_unguarded(): array {
    $site = pp_udc_site_map();
    if (!is_array($site) || !isset($site['chrome']) || !is_array($site['chrome'])) {
        return [];
    }

    // Built in the registry's order rather than the stored map's, so two installs
    // holding the same chrome report it in the same order.
    $items = [];
    foreach (pp_udc_chrome_names() as $name) {
        if (isset($site['chrome'][$name]) && is_array($site['chrome'][$name])) {
            $items[] = ['component' => $name, 'udc' => $site['chrome'][$name]];
        }
    }
    if ($items === []) {
        return [];
    }

    $findings = [];
    foreach (pp_udc_composition_findings($items) as $finding) {
        $findings[] = [
            'type'     => $finding['type'],
            // SEVERITY IS STAMPED HERE because this function is chrome's ASSEMBLER, the
            // counterpart of _pp_composition_findings() — which is where the composition
            // path stamps it for the very same disclosures. Every generic consumer
            // branches on this value (the CLI splits on `=== 'error'`, the chat picks a
            // row class from it), so a chrome row without it renders as neither.
            // `warning` matches what the composition path gives these four types.
            'severity' => 'warning',
            'message'  => $finding['message'],
            'index'    => null,
        ];
    }
    return $findings;
}

/**
 * One chrome component's CSS for one layer.
 *
 * Compiles through pp_udc_compile_band() — the same compiler, same cascade rung,
 * same provenance — with the chrome NAME standing where a band id would. That is
 * not a fabricated id: it is a registry constant, stable across every read, and it
 * satisfies pp_udc_valid_band_id()'s charset, which is what the compiler gates on.
 * The scope selector is the only thing that differs from a band.
 *
 * The defaults layer keeps the `:where()` root treatment bands get, so a chrome
 * role default can never outrank the structural stylesheet it sits under.
 */
function pp_udc_chrome_css(string $name, string $layer): string {
    if (!pp_udc_is_chrome($name)) {
        return '';
    }
    // Second-layer gate on a value that becomes CSS SOURCE TEXT, mirroring the one
    // pp_udc_compile_band() applies to a role selector and for the same stated
    // reason: the registry is repo-controlled and integrity-checked, but this string
    // is interpolated into a selector, and the cost of checking is a regex. The
    // charset is the band-id charset, which every chrome name satisfies.
    if (!pp_udc_valid_band_id($name)) {
        return '';
    }
    $udc = [];
    if ($layer !== 'defaults') {
        $site = pp_udc_site_map();
        $udc  = $site['chrome'][$name] ?? [];
        if ($udc === []) {
            return '';
        }
    }
    $compiled = pp_udc_compile_band(
        ['component' => $name, 'id' => $name, 'udc' => $udc],
        $layer
    );
    if ($compiled['id'] === '') {
        return '';
    }
    $scope = '[data-pp-chrome="' . $name . '"]';
    // Chrome's defaults tier splits exactly like a band's and for the same
    // reason (#986, ruling D5 revised): its zeroed root tier was ranked under the
    // shared header/footer rules by printing first, and layering the v1
    // stylesheet would have inverted that. `pp-zero` keeps it underneath
    // structurally.
    return $layer === 'defaults'
        ? _pp_udc_render_blocks($compiled, $scope, ':where(' . $scope . ')', 'pp-zero')
        : _pp_udc_render_blocks($compiled, $scope);
}

/**
 * Layer 1 for chrome: role defaults, emitted once per page.
 *
 * THE NO-STORED-ENTRY SHORT-CIRCUIT IS GONE (#994), and its own docblock is why.
 * This function used to return '' whenever `pp_site_udc` held no chrome entry —
 * a cost gate that was also, silently, a behavioural one: it emitted chrome role
 * defaults ONLY on a site that had already written chrome styling, while the band
 * path (pp_udc_page_defaults_css) emits a component's defaults unconditionally.
 * That was inert exactly as long as nav and footer declared ZERO defaults, and the
 * old comment said so: "the first person to add a chrome role default will find it
 * silently absent on every unstyled site, so this gate has to go at the same time."
 * #994 is that change. The header and footer's entire resting appearance is role
 * defaults now, so keeping the gate would have left every unstyled site — which is
 * most of them — with an unpainted header and footer.
 *
 * WHAT THE GATE WAS ACTUALLY BUYING, stated at its real size: chrome renders on
 * EVERY request, including 404 and search where no composition exists, so unlike the
 * band layers it cannot lean on a page lookup to stay off the hot path. The work is a
 * scandir of components/ plus a json_decode of EVERY component schema — twelve files,
 * ~180 KB today, not the two chrome ones (pp_get_registered_components() builds the
 * whole registry or none of it). That memo is per-PHP-process, so no cross-request
 * cache absorbs it; what does absorb it in practice is the band path, which warms the
 * same registry on any page carrying a composition.
 *
 * THE REAL FIGURES, because this note exists to be the one a capacity decision is made
 * from and two earlier drafts of it were wrong (first "the two chrome schemas' reads",
 * then "well under a millisecond"). Measured on PHP 8.3, one fresh process per sample:
 *
 *   an unstyled request  0.012 ms  ->  2.45 ms (p50), +719 KB transient
 *     registry warm   ~1.15 ms / 642 KB   (json_decode alone ~1.0 ms)
 *     compile         ~1.00 ms            (nav + footer, 28 roles)
 *     token parse     ~0.24 ms
 *     string build    ~0.055 ms
 *
 * On a page already carrying a v2 composition the band path has warmed the registry
 * first, so the marginal cost is ~0.55 ms. On 404, search and archives — the routes
 * this change was made for, where pp_udc_current_composition() returns [] and warms
 * nothing — the full 2.45 ms is paid, about 5% of a measured 43-45 ms 404 TTFB. No
 * database queries are added: it is file reads, json_decode and compile.
 *
 * Nothing here is superlinear (compile is flat at ~0.021 ms/role to 512 roles, and
 * _pp_udc_render_blocks() is ~0.00088 ms/block with no re-scan), so the cost scales
 * with how many roles chrome declares and nothing else. 47% of it is the registry
 * decoding the ten schemas the chrome path never reads; #1020 tracks making that decode
 * lazy, which is the available win and is deliberately not taken here — it is a change
 * to shared infrastructure every caller depends on, with the #576 root-keyed
 * invalidation handshake to get right.
 *
 * The alternative was an unpainted header and footer on every unstyled site.
 *
 * The names come from pp_udc_chrome_names() (the template-owned list) rather than from
 * the stored row, because the defaults are a property of the THEME, not of what a site
 * has written.
 */
function pp_udc_chrome_defaults_css(): string {
    $css = '';
    foreach (pp_udc_chrome_names() as $name) {
        $css .= pp_udc_chrome_css((string) $name, 'defaults');
    }
    return $css;
}

/** Layer 2 for chrome: the authored values, printed after the theme stylesheets. */
function pp_udc_chrome_authored_css(): string {
    $site = pp_udc_site_map();
    if ($site['chrome'] === []) {
        return '';
    }
    $css = '';
    foreach (array_keys($site['chrome']) as $name) {
        $css .= pp_udc_chrome_css((string) $name, 'authored');
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
/**
 * The preset parameters a role's own defaults suppress (#994, ruling D8).
 *
 * Presets rank under role defaults PER (group, param, state) TUPLE, which is the whole
 * subtlety: a preset's base colour can be suppressed while its `:hover` counterpart
 * survives, because the role declares one and not the other. Comparing at group grain
 * would over-report (claiming a whole bundle lost when one value did) and comparing at
 * param grain would under-report (missing a state the role defaults separately). So the
 * walk is per tuple, and the label carries the state when there is one.
 *
 * GROUPS THE ROLE DOES NOT PERMIT ARE SKIPPED, because `udc_preset_groups_skipped`
 * already owns them. Two findings for one cause is how an author learns to ignore both.
 *
 * Returns human-readable labels rather than structured tuples: the one consumer
 * interpolates them into a sentence, and the caller bounds the list.
 *
 * @return array<int, string>  e.g. ['typography.color', 'typography.color (:hover)']
 */
function _pp_udc_preset_values_shadowed_by_role_defaults(array $fragment, array $role_def): array {
    $defaults  = isset($role_def['defaults']) && is_array($role_def['defaults'])
        ? $role_def['defaults']
        : [];
    $permitted = isset($role_def['groups']) && is_array($role_def['groups'])
        ? $role_def['groups']
        : [];
    if ($defaults === []) {
        return [];
    }

    $states    = pp_udc_states();
    $shadowed  = [];

    foreach ($fragment as $group => $group_map) {
        $group = (string) $group;
        if (!is_array($group_map) || !in_array($group, $permitted, true)) {
            continue;
        }
        $group_defaults = isset($defaults[$group]) && is_array($defaults[$group])
            ? $defaults[$group]
            : [];
        if ($group_defaults === []) {
            continue;
        }

        foreach ($group_map as $key => $value) {
            $key = (string) $key;

            if (isset($states[$key])) {
                if (!is_array($value)) {
                    continue;
                }
                $state_defaults = isset($group_defaults[$key]) && is_array($group_defaults[$key])
                    ? $group_defaults[$key]
                    : [];
                foreach (array_keys($value) as $param) {
                    if (isset($state_defaults[(string) $param])) {
                        $shadowed[] = $group . '.' . (string) $param . ' (' . $key . ')';
                    }
                }
                continue;
            }

            if (isset($group_defaults[$key])) {
                $shadowed[] = $group . '.' . $key;
            }
        }
    }

    return $shadowed;
}

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

        // THE PARTIAL-APPLY DISCLOSURE (orchestrator ruling, T2).
        //
        // A role-grain preset applies the groups the role permits and skips the
        // rest. A partial apply is fine; a SILENT partial apply is not — the
        // author asked for a bundle and got part of one, and nothing else on any
        // surface would ever tell them which part. So it rides the write envelope,
        // the same channel the minting disclosure uses, rather than a log line
        // nobody reads.
        //
        // Derived from the reference, which minting never rewrites, so this
        // reconstructs identically from submitted and from stored data — the
        // property `wp pp check page` and restore both depend on.
        $roles = pp_udc_component_roles($component);
        foreach ($item['udc'] as $role_name => $role_map) {
            if (!is_array($role_map) || !isset($role_map[PP_UDC_PRESET_KEY])
                || !is_string($role_map[PP_UDC_PRESET_KEY]) || !isset($roles[(string) $role_name])) {
                continue;
            }
            $preset = pp_udc_resolve_preset($role_map[PP_UDC_PRESET_KEY]);
            if ($preset === null) {
                continue; // Dangling: refused at write, reported there.
            }
            $fragment = _pp_udc_preset_fragment($preset, 'role');
            if (!is_array($fragment) || $fragment === []) {
                continue;
            }
            $split = _pp_udc_split_preset_by_permitted(
                $fragment,
                $roles[(string) $role_name]['groups'] ?? []
            );
            if ($split['skipped'] === [] || $split['applied'] === []) {
                continue; // Nothing skipped, or refused outright at write.
            }
            $findings[] = [
                'type'    => 'udc_preset_groups_skipped',
                'message' => sprintf(
                    'Component "%s" role "%s": the preset "%s" also declares %s, which this role does not '
                    . 'permit, so %s not applied. Applied: %s.',
                    $component,
                    (string) $role_name,
                    $role_map[PP_UDC_PRESET_KEY],
                    implode(', ', $split['skipped']),
                    count($split['skipped']) === 1 ? 'it was' : 'they were',
                    implode(', ', array_keys($split['applied']))
                ),
                'index'   => is_int($i) ? $i : null,
            ];
        }

        // THE SHADOWED-PRESET DISCLOSURE (#994, ruling D8, invariant I35).
        //
        // Presets rank UNDER role defaults (site tokens -> presets -> role defaults ->
        // the authored map), per (group, param, state) tuple. So a preset value whose
        // tuple a role also defaults is accepted, stored, reported applied, and never
        // painted — the same sentence the `_band` disclosure below is about, one rung
        // over, and until #994 it could not happen on chrome because chrome declared no
        // defaults at all.
        //
        // WHAT MADE IT URGENT rather than tidy. Measured on the stored map
        // `{"nav":{"logo":{"_preset":"button"}}}`: before chrome had defaults the logo
        // emitted the button treatment entire, `color: var(--color-bg)` over
        // `background: var(--color-accent)` — inverted ink on an accent fill, which is
        // the whole point of that preset. After, `logo`'s typography and sizing defaults
        // suppress the colour, weight, size, decoration, hover colour and min-height,
        // and the logo renders `@color-text` ink on the same accent fill. A contrast
        // inversion, on a site whose stored map nobody edited, reported `ok: true` with
        // an empty findings array.
        //
        // THE PRECEDENCE IS NOT THE DEFECT — ruling D6 settled that, and thinning the
        // defaults to make room for presets would re-expose every chrome element to the
        // structural rules the defaults exist to beat. The SILENCE was the defect.
        //
        // RECONSTRUCTED FROM STORED DATA, like both its neighbours: the preset reference
        // survives minting unrewritten and the defaults are on disk, so this fires
        // identically on the post-write envelope, on `wp pp check page`, and on restore.
        // That last one is what reaches a map written BEFORE this change, which is the
        // only channel that can.
        foreach ($item['udc'] as $role_name => $role_map) {
            if (!is_array($role_map) || !isset($role_map[PP_UDC_PRESET_KEY])
                || !is_string($role_map[PP_UDC_PRESET_KEY]) || !isset($roles[(string) $role_name])) {
                continue;
            }
            $preset = pp_udc_resolve_preset($role_map[PP_UDC_PRESET_KEY]);
            if ($preset === null) {
                continue;
            }
            $fragment = _pp_udc_preset_fragment($preset, 'role');
            if (!is_array($fragment) || $fragment === []) {
                continue;
            }
            $shadowed = _pp_udc_preset_values_shadowed_by_role_defaults(
                $fragment,
                $roles[(string) $role_name]
            );
            if ($shadowed === []) {
                continue;
            }
            $total = count($shadowed);
            $findings[] = [
                'type'    => 'udc_preset_value_shadowed_by_role_default',
                'message' => sprintf(
                    'Component "%s" role "%s": the preset "%s" sets %s, but this role\'s own default '
                    . 'for %s outranks a preset, so %s not applied. Write the value in your own map '
                    . 'for this role, where it out-ranks both.',
                    $component,
                    (string) $role_name,
                    _pp_udc_reflect($role_map[PP_UDC_PRESET_KEY]),
                    // BOUNDED, through the repo's one list contract. This names
                    // PARAMETERS, and a role may permit every group in the taxonomy —
                    // so the list is capped and the tail carries the TRUE total, or the
                    // next preset with a wide fragment turns a diagnostic into an
                    // unbounded interpolation.
                    pp_udc_bounded_list($shadowed, 6, $total),
                    $total === 1 ? 'it' : 'them',
                    $total === 1 ? 'it was' : 'they were'
                ),
                'index'   => is_int($i) ? $i : null,
            ];
        }

        // THE INERT-`_band` DISCLOSURE (boundary-review item C2, invariant I35).
        //
        // `_band` has no selector, so an inherited value it sets reaches the text
        // inside the band by INHERITANCE — and a role default for the same property
        // is a direct declaration on the child, which beats inheritance at any
        // specificity and in any source order. There is no cascade position that
        // would change it, so the author's value is accepted, stored, reported
        // applied, and silently cancelled for every role that declares a default
        // for the same property.
        //
        // WHY DISCLOSURE RATHER THAN THINNING THE DEFAULTS, which the boundary
        // review offered as the alternative: those defaults are load-bearing.
        // testimonials' `quote` declares `color` and `font-style` precisely to beat
        // base.css's `blockquote { color: var(--color-muted); font-style: italic; }`.
        // Thinning them would re-expose every quote to the structural rule and trade
        // a disclosed cancellation for an undisclosed one.
        //
        // The docs already tell the model to set typography on every text role
        // rather than rely on inheritance. That helps the author who reads them; I35
        // is about the author who does not.
        foreach (_pp_udc_band_values_cancelled_by_role_defaults($item['udc'], $component) as $property => $names) {
            $findings[] = [
                'type'    => 'udc_band_value_shadowed_by_role_default',
                'message' => sprintf(
                    'Component "%s": the "%s" you set on the whole band does not reach %s, because %s '
                    . 'own default for it wins over inheritance. Set it on %s directly.',
                    $component,
                    (string) $property,
                    implode(', ', $names),
                    count($names) === 1 ? 'that role\'s' : 'those roles\'',
                    count($names) === 1 ? 'that role' : 'those roles'
                ),
                'index'   => is_int($i) ? $i : null,
            ];
        }

        // ── LAYER 2 DISCLOSURES (contract §2′.3, §2′.4) ─────────────────────
        //
        // R2′ made both of these real by removing the two rules that had made them
        // impossible. Disjointness used to guarantee a `_css` property could never
        // collide with a group value; typed-everything used to guarantee nothing was
        // unchecked. Both premises are gone by ruling, so the guarantees are replaced
        // by honest reporting — which is what the #570 convergence rule now demands
        // of this layer: whatever the write gate accepts, the emitter emits or
        // DISCLOSES.
        foreach ($item['udc'] as $role_name => $role_map) {
            if (!is_array($role_map) || !isset($role_map[PP_UDC_CSS_KEY])
                || !is_array($role_map[PP_UDC_CSS_KEY])) {
                continue;
            }
            $role_name = (string) $role_name;
            $states    = pp_udc_states();

            // Flatten `_css` to (state, property) pairs so a `:hover` declaration is
            // reported as precisely as a resting one. A state map is the only nesting
            // this key has, so one level is the whole walk.
            $declared = [];
            foreach ($role_map[PP_UDC_CSS_KEY] as $key => $value) {
                if (isset($states[(string) $key]) && is_array($value)) {
                    foreach ($value as $property => $ignored) {
                        $declared[] = [(string) $key, (string) $property];
                    }
                    continue;
                }
                $declared[] = ['', (string) $key];
            }

            foreach ($declared as [$state, $property]) {
                if (!pp_udc_valid_css_property($property)
                    || isset(pp_udc_css_excluded_properties()[$property])) {
                    continue; // Refused at write; a stored one is the emitter's ledger.
                }
                $typed = _pp_udc_css_param_for_property($property);

                // (a) THE COLLISION. `_css` outranks a group value at the same
                // coordinate — the rank the freedom frame requires, since an escape
                // that loses to the thing it escapes cannot escape anything. Reported
                // only when the author actually wrote BOTH, because that is the only
                // case where something they declared did not paint.
                if ($typed !== null) {
                    $group_map = $role_map[$typed['_group']] ?? null;
                    $branch    = is_array($group_map) && $state !== ''
                        ? ($group_map[$state] ?? null)
                        : $group_map;
                    if (is_array($branch) && array_key_exists($typed['_param'], $branch)) {
                        $findings[] = [
                            'type'    => 'udc_css_overrides_group_value',
                            'message' => sprintf(
                                'Component "%s" role "%s"%s: the raw declaration "%s" in "%s" outranks the '
                                . '%s.%s you also set, so the raw value is what paints. Remove one of the two '
                                . '— prefer %s.%s, which the engine can check.',
                                $component,
                                _pp_udc_reflect($role_name),
                                $state !== '' ? ' ' . $state : '',
                                _pp_udc_reflect($property),
                                PP_UDC_CSS_KEY,
                                $typed['_group'],
                                $typed['_param'],
                                $typed['_group'],
                                $typed['_param']
                            ),
                            'index'   => is_int($i) ? $i : null,
                        ];
                    }
                    continue; // A typed property is checked; nothing unchecked to report.
                }

                // (b) THE UNCHECKED SET. This is the design doc's
                // `custom_styling_conventions_only` in honest form, and the difference
                // is that it is TRUE on every band it fires on: R1 refused to ship that
                // code name because under typed-everything nothing would have been
                // conventions-only and the finding would have lied. It is also the
                // ladder's escape telemetry arriving as a by-product — a count of these
                // is a count of escapes — rather than as a mechanism of its own.
                $findings[] = [
                    'type'    => 'udc_css_unchecked_property',
                    'message' => sprintf(
                        'Component "%s" role "%s"%s: "%s" is not a property the design vocabulary '
                        . 'knows, so its value was checked for safety only and is emitted exactly as '
                        . 'written. Nothing verifies that the browser accepts it.',
                        $component,
                        _pp_udc_reflect($role_name),
                        $state !== '' ? ' ' . $state : '',
                        _pp_udc_reflect($property)
                    ),
                    'index'   => is_int($i) ? $i : null,
                ];
            }
        }

        // THE TOKEN SECTION BELOW EARLY-OUTS ON A BAND WITH NO `_tokens`, WHICH IS WHY
        // EVERY DISCLOSURE ABOVE HAS TO COME FIRST. Caught by probe, not by reading: the
        // Layer-2 findings were written after this `continue` and produced NOTHING for
        // the overwhelmingly common band — one that declares raw CSS and mints no token.
        // The guard is right for what it guards; it is just not the end of the item's
        // business any more.
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

/**
 * The CSS properties a `_band` value reaches its content through INHERITANCE.
 *
 * `_band` has no selector, so its declarations land on the band root and reach
 * the text inside it only because CSS inherits these properties down. A role
 * default for the same property is a DIRECT declaration on the child element, and
 * a direct declaration beats inheritance at any specificity and in any order —
 * there is no cascade position that would change it. So the author's `_band`
 * value is accepted, stored, reported applied, and cancelled for every role that
 * declares a default for the same property.
 *
 * ONLY INHERITED PROPERTIES BELONG HERE. `padding` on the band root is not
 * cancelled by a role's padding — they are different boxes, both paint. Listing a
 * non-inherited property would make the disclosure fire on values that work,
 * which is the way an advisory gets acknowledged into silence.
 *
 * Every entry is an inherited property per CSS. Most are `typography` parameters —
 * that group is the whole inherited surface the vocabulary exposes TODAY — and the
 * rest (`text-indent`, `word-spacing`, `white-space`, `visibility`, `cursor`, the
 * list-style family) are deliberately stocked ahead of it: they are inherited
 * properties no group declares yet, so they are unreachable lookups until one does.
 * Listing them now is cheap and means a future group cannot add an inherited
 * parameter and leave this disclosure quietly incomplete. What must NOT go here is
 * a non-inherited property: that would fire the finding on values that work.
 *
 * @return array<string,true>
 */
function _pp_udc_inherited_properties(): array {
    return [
        'font-family'          => true,
        'font-size'            => true,
        'font-weight'          => true,
        'font-style'           => true,
        'line-height'          => true,
        'letter-spacing'       => true,
        'text-align'           => true,
        'text-transform'       => true,
        'text-indent'          => true,
        'text-wrap'            => true,
        'color'                => true,
        'word-spacing'         => true,
        'white-space'          => true,
        'visibility'           => true,
        'cursor'               => true,
        'list-style'           => true,
        'list-style-type'      => true,
        'list-style-position'  => true,
    ];
}

/**
 * The roles whose own defaults cancel a `_band` value, per CSS property.
 *
 * Derived from the SCHEMA and the submitted `_band` map, both of which are on
 * disk, so this reconstructs identically from stored and from submitted data —
 * the property `wp pp check page` and restore both depend on.
 *
 * ONE CARVE-OUT, NAMED RATHER THAN DISCOVERED: a `_band` that takes its typography
 * from a role-grain preset (`"_band": {"_preset": "button"}`) is not checked. The
 * same cancellation happens there and is not disclosed. Resolving the preset
 * fragment into the declared set first would close it; that is a real gap and it is
 * written down rather than left for someone to trip over.
 *
 * CURRENT-SCHEMA DIAGNOSTIC, STATED BECAUSE IT IS NOT OBVIOUS. Role defaults are
 * not versioned, so this describes the defaults in force NOW, not the ones in
 * force when the band was authored. A schema change can therefore make a finding
 * appear over a band nobody touched. That is the honest behaviour for a
 * "what is painting today" disclosure, and the alternative — versioning every
 * component schema so a band could be diffed against the defaults of its own era
 * — is a contract far larger than the disclosure it would serve.
 *
 * @return array<string,string[]> property => role names that shadow it
 */
function _pp_udc_band_values_cancelled_by_role_defaults(array $udc, string $component): array {
    if (!isset($udc['_band']) || !is_array($udc['_band'])) {
        return [];
    }
    $groups    = pp_udc_groups();
    $roles     = pp_udc_component_roles($component);
    $inherited = _pp_udc_inherited_properties();

    // What `_band` declares, as CSS properties.
    $declared = [];
    foreach ($udc['_band'] as $group_name => $group_map) {
        if ($group_name === PP_UDC_PRESET_KEY || !is_array($group_map)
            || !isset($groups[(string) $group_name]['params'])) {
            continue;
        }
        foreach ($group_map as $param_name => $value) {
            $param = $groups[(string) $group_name]['params'][(string) $param_name] ?? null;
            if ($param === null || !isset($inherited[$param['property']])) {
                continue;
            }
            // ONE VALUE, ONE CLASSIFIER (I25). A `_band` value that does not
            // satisfy its own grammar is not painted ANYWHERE, so it is the
            // emit-drop advisory's to report — saying here that it "does not reach
            // these roles" implies it reaches the others, and "set it on those
            // roles directly" is advice that would not work either.
            $scalar = is_array($value) ? ($value['d'] ?? null) : $value;
            if (!is_scalar($scalar) || pp_udc_validate_value((string) $scalar, $param) !== true) {
                continue;
            }
            $declared[$param['property']] = true;
        }
    }
    if ($declared === []) {
        return [];
    }

    // Which roles declare a DEFAULT for the same property.
    $cancelled = [];
    foreach ($roles as $role_name => $role_def) {
        if ((string) $role_name === '_band') {
            continue;
        }
        // A ROLE THE AUTHOR ALREADY SET IS NOT CANCELLED. The authored value beats
        // the role default, so the band-level value being shadowed there is moot —
        // and telling someone to "set it on that role directly" when they already
        // have is an unactionable finding on correct data, which is how an advisory
        // gets acknowledged into silence.
        $authored_here = [];
        if (isset($udc[(string) $role_name]) && is_array($udc[(string) $role_name])) {
            foreach ($udc[(string) $role_name] as $g => $gm) {
                if ($g === PP_UDC_PRESET_KEY || !is_array($gm) || !isset($groups[(string) $g]['params'])) {
                    continue;
                }
                foreach ($gm as $pn => $unused) {
                    $pd = $groups[(string) $g]['params'][(string) $pn] ?? null;
                    if ($pd !== null) {
                        $authored_here[$pd['property']] = true;
                    }
                }
            }
        }
        foreach (($role_def['defaults'] ?? []) as $group_name => $group_map) {
            if (!is_array($group_map) || !isset($groups[(string) $group_name]['params'])) {
                continue;
            }
            foreach ($group_map as $param_name => $default_value) {
                $param = $groups[(string) $group_name]['params'][(string) $param_name] ?? null;
                if ($param === null || !isset($declared[$param['property']])
                    || isset($authored_here[$param['property']])) {
                    continue;
                }
                // A DEFAULT OF `currentColor` CANNOT CANCEL INHERITANCE — it IS
                // inheritance (#994). In the `color` property specifically, CSS defines
                // `currentcolor` as computing to the inherited value, which is exactly
                // why footer's `heading` role uses it: a heading follows the band's own
                // text colour, so an authored `_band` colour DOES reach it.
                //
                // Without this arm the documented dark-footer write named `heading`
                // among the roles a band colour "does not reach", which is false — and
                // false in the same commit that shipped AI-facing docs promising the
                // opposite. The docblock above scopes this disclosure to a DIRECT
                // declaration that beats inheritance; `color: currentcolor` is
                // definitionally not one. Reading the `d` tier of a breakpoint map
                // mirrors how the authored side resolves a scalar.
                $resolved = is_array($default_value)
                    ? ($default_value['d'] ?? null)
                    : $default_value;
                if ($param['property'] === 'color'
                    && is_string($resolved)
                    && strcasecmp(trim($resolved), 'currentColor') === 0) {
                    continue;
                }
                $cancelled[$param['property']][] = (string) $role_name;
            }
        }
    }
    foreach ($cancelled as $property => $names) {
        $cancelled[$property] = array_values(array_unique($names));
        sort($cancelled[$property]);
    }
    ksort($cancelled);
    return $cancelled;
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
    return $subject . ' has an unbalanced ( ), an unbalanced [ ], or an odd number of \' or " '
        . 'characters. CSS treats each of those as still open, so the value would swallow every '
        . 'declaration and rule after it. Close the pair — a value may carry ( ) or [ ] as long '
        . 'as both appear and they nest, so a grid track list such as "[full-start] 1fr '
        . '[full-end]" is accepted. A name containing a single apostrophe is not accepted in '
        . 'any form.';
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
 * CONSEQUENCE, STATED BECAUSE IT IS A REAL SPLIT, AND THE SPLIT MOVED (#965): this
 * gate is no longer v2-only. It now also runs at the v1 design-token RENDER
 * boundary — pp_token_override_renders() (lib/wp.php) — because the `:root { … }`
 * block functions.php emits is CSS source text, the same kind of sink v2 has, and
 * not the escaped `style` attribute the split was originally drawn around.
 *
 * What survives of the split is narrower and worth naming exactly. The v1 design
 * token WRITE path still does not run this: `_pp_validate_token_value()` has no
 * balance gate, so a name carrying a lone apostrophe is still ACCEPTED as a design
 * token and still REFUSED on a v2 `typography.family` parameter. The difference is
 * now only that the accepted v1 value does not paint — it is dropped at render and
 * reported by `wp pp readiness status` — rather than emitting broken CSS. The
 * AI-facing docs state the accepted set per surface.
 *
 * CALLED FROM THREE PLACES. `pp_udc_validate_map()`'s `_tokens` loop covers a token
 * that nothing references, since §3.1 makes an unreferenced token a warning
 * rather than a refusal and so no parameter grammar ever reaches it.
 * `pp_udc_validate_value()` covers everything else on the v2 side — the authored
 * write path, the emit-time re-validation, and a referenced token checked against
 * the grammar of the parameter that uses it. `pp_token_override_renders()`
 * (lib/wp.php) covers the v1 design-token render boundary.
 */
function _pp_udc_delimiters_balanced(string $value): bool {
    // `[` AND `]` ARE HERE FOR THE SAME REASON `(` AND `)` ARE, and leaving them
    // out was a hole rather than a scope line (#965). CSS Syntax L3 "consume a
    // simple block" treats `[` exactly as it treats `(`: an unclosed one consumes
    // across the terminating `;` and the closing `}` to EOF. The shared reject set
    // does not ban a bracket, and for the `raw` type nothing else looks at the
    // value either, so before this an unmatched `[` walked through every boundary
    // this function serves and took the following rules with it. A function whose
    // name is "delimiters balanced" has to balance the delimiters CSS has, not the
    // subset that happened to be written first.
    //
    // Balanced brackets stay legal, and that is not a theoretical allowance: a
    // grid track list names its lines with them — `[full-start] 1fr [full-end]` —
    // and a bracket inside a CSS string is inert either way.
    //
    // Nothing to balance. Exactly equivalent to the work below — with none of the
    // six characters present the counts are all zero and the walk never pushes —
    // and it is the case most values take, which matters because this runs per
    // declaration at EMIT as well as at write.
    //
    // MEASURED, and the numbers moved when the walk became string-aware (#965), so
    // they are restated rather than carried over. A value with no delimiter costs
    // 0.05-0.07 microseconds and never leaves this line; one that carries a
    // delimiter costs 2.1-2.6 (a clamp(), a quoted font stack, a grid track list
    // all land in that band). Over the shipped registry that is 10 of 61 values
    // taking the walk, and pp_partition_token_overrides() at N=61 measures 139
    // microseconds end to end — a fraction of a percent of a page render, against
    // the alternative of emitting a block that swallows the rest of the
    // stylesheet. Best of 5-7 runs of 200k/2k calls.
    if (strpbrk($value, '()[]"\'') === false) {
        return true;
    }
    if (substr_count($value, '"') % 2 !== 0 || substr_count($value, "'") % 2 !== 0) {
        return false;
    }
    // The counts below are a cheap pre-check, not the answer: equal counts do not
    // mean balanced (`)(` counts one each), so the walk still has to run. What the
    // counts DO settle early is the common unbalanced case, and they settle one
    // thing the walk deliberately cannot — a delimiter shielded inside a string,
    // which the walk skips over entirely. Keeping the counts is what preserves the
    // long-standing conservative treatment of `"Foo(Bar"`, and makes a bracket in
    // that position behave the same way (a pinned consistency, not an accident).
    //
    // The two pairs are counted separately, so `(]` cannot pass on a combined
    // total, and the depth walk then rejects any interleaving.
    $parens   = substr_count($value, '(');
    $brackets = substr_count($value, '[');
    if ($parens !== substr_count($value, ')') || $brackets !== substr_count($value, ']')) {
        return false;
    }
    // NO EARLY RETURN FOR "no brackets, so nothing to order". There used to be
    // one, and it was correct while the walk only checked bracket ORDERING: with
    // no brackets there was no ordering to get wrong. The walk now also pairs
    // STRINGS, and a value can reach this line with no bracket of either kind and
    // still be unbalanced — `"'"'` has an even count of both marks, yet CSS reads
    // `"'"` as one string and the trailing `'` opens a second that never closes.
    // Returning early there skipped the only check that could see it. Anything
    // past the fast path carries at least one of the six characters, so the walk
    // has work to do by construction.
    // THE WALK CONSUMES STRINGS WHOLE, because CSS does.
    //
    // A closer inside a string is string CONTENT, not a closer — CSS Syntax L3
    // 4.3.5 consumes the string before anything else looks at its bytes. A walk
    // that does not know that will discharge a real opener against a shielded
    // closer and call the value balanced: `(")"` counts one `(` and one `)`, has
    // even quote parity, and leaves the parenthesis OPEN at end of value. That is
    // the whole escape again, wearing a different delimiter.
    //
    // Note the asymmetry, because it is why only one direction is a hole: an
    // OPENER inside a string makes this stricter (the count pre-check may refuse a
    // value CSS would have accepted), which is conservative and harmless. A CLOSER
    // inside a string makes it laxer, which is the escape. Consuming the string
    // removes both readings by not looking inside at all.
    //
    // The parity pre-check above stays, and is not redundant with this: it is what
    // refuses a lone apostrophe in any position (`"Foo's Font"`), which
    // _pp_udc_unbalanced_message() promises and a test pins. A tokenizer-only walk
    // would accept that value, since CSS reads the apostrophe as string content.
    $stack = [];
    $len   = strlen($value);
    $i     = 0;
    while ($i < $len) {
        $char = $value[$i];

        if ($char === '"' || $char === "'") {
            // Skip to just past the partner. Parity guarantees one exists in a
            // value that got this far, but a value whose marks interleave
            // (`"'"'` — even counts of both, yet CSS opens a second string that
            // never closes) reaches here and is refused on the missing partner.
            $end = strpos($value, $char, $i + 1);
            if ($end === false) {
                return false;
            }
            $i = $end + 1;
            continue;
        }

        if ($char === '(' || $char === '[') {
            $stack[] = $char;
        } elseif ($char === ')' || $char === ']') {
            if (array_pop($stack) !== ($char === ')' ? '(' : '[')) {
                return false;
            }
        }

        $i++;
    }

    return $stack === [];
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
 * when it ends in `-<breakpoint>` (optionally `-<state>-<breakpoint>`, where
 * `<state>` is a pp_udc_states() mint segment) and the segments
 * before it name a real group and one of that group's parameters.
 */
/**
 * Every way a mint name's segments can split into <role>-<group>-<param>.
 *
 * ONE SPLITTER, TWO CALLERS, AND THAT IS THE POINT. _pp_udc_is_mint_shaped_name() and
 * _pp_udc_name_is_the_engines_own_mint() decide TOGETHER whether a stored token name is
 * the engine's own, and both of their docblocks record that a disagreement between them
 * turns every already-written band into a permanent false refusal. They had two copies of
 * this walk. Layer 2 adds a second kind of group — the `_css` pseudo-group, whose "params"
 * are CSS property names rather than registry entries — and teaching that to one copy and
 * not the other is precisely the disagreement those comments warn about, so the walk is
 * shared before it is widened.
 *
 * Role names, group names and property names all contain hyphens, so the boundary is not
 * positional and every split has to be offered.
 *
 * @param array $parts Name segments, breakpoint and state already popped.
 * @return array<int, array{0: string, 1: string, 2: string}> [role, group, param] triples.
 */
function _pp_udc_mint_splits(array $parts): array {
    $groups = pp_udc_groups();
    $splits = [];
    $count  = count($parts);
    for ($g = 1; $g < $count; $g++) {
        $group = $parts[$g];
        $param = implode('-', array_slice($parts, $g + 1));
        if ($param === '') {
            continue;
        }
        // A REGISTRY GROUP names a declared parameter; the `_css` pseudo-group names a
        // CSS property, judged by the same charset gate the write path applies, so a
        // squatted name and an engine mint are separated by exactly the rule that
        // decided what could be written in the first place.
        $known = isset($groups[$group])
            ? isset($groups[$group]['params'][$param])
            : ($group === PP_UDC_CSS_KEY && pp_udc_valid_css_property($param));
        if ($known) {
            $splits[] = [implode('-', array_slice($parts, 0, $g)), $group, $param];
        }
    }
    return $splits;
}

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
    // POP BY SEGMENT COUNT, never by one. `focus-visible` is two segments, and
    // the single-array_pop() idiom that served one state called `hover` reads
    // such a name as a param ending in `-focus` inside a state called `visible`.
    [$state, $parts] = _pp_udc_state_from_mint($parts);
    foreach (_pp_udc_mint_splits($parts) as [$role, $group, $param]) {
        $branch = $udc[$role][$group] ?? null;
        if ($state !== '') {
            $branch = is_array($branch) ? ($branch[$state] ?? null) : null;
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
    // Same segment-count rule as _pp_udc_name_is_the_engines_own_mint(): these
    // two functions decide together whether a stored token name is the engine's
    // own, and a disagreement between them is exactly the shape that turns every
    // already-written band into a permanent false refusal.
    [, $parts] = _pp_udc_state_from_mint($parts);
    // Walk every split of the remainder into <role...>-<group>-<param...>: group
    // and param names both contain hyphens, so the boundary is not positional.
    return _pp_udc_mint_splits($parts) !== [];
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
    // THE OVERLAY IS AN ACCESSIBILITY FACT, SO IT GETS A STRUCTURAL HOOK (#986).
    //
    // A focus ring over a scrim needs the on-overlay colour: `--color-accent` is
    // 1.17:1 against the worst-case scrim, a WCAG 1.4.11 failure. v1 routed that
    // through `.hero--cover .btn:focus`, which was sound while `cover` was the only
    // layout that could carry a background image. On v2 `_band.background.image` and
    // `.overlay` are authorable on EVERY layout, so a class keyed to one variant
    // stopped following the thing it describes.
    //
    // The engine is what knows an overlay is being emitted, so the engine says so.
    // Same posture as the reduced-motion guard under ruling A3: an accessibility
    // affordance is STRUCTURAL — emitted, not authored, and not something an author
    // can forget to switch on. The ring itself stays in the stylesheet, keyed to this
    // attribute instead of to a layout class.
    if (pp_udc_band_has_overlay($item)) {
        $props['__pp_udc_overlay'] = '1';
    }
    return $props;
}

/**
 * Does this band paint a scrim over a background image?
 *
 * Reads the STORED map rather than the emitted CSS because the renderer runs
 * before emission and needs the answer for an attribute. Deliberately narrow: an
 * overlay only paints when there is an image under it (an overlay over nothing is
 * dropped by _pp_udc_compose_background_layers()), so both must be present for the
 * hook to appear — otherwise a band with a stray `overlay` key would claim a
 * contrast problem it does not have.
 *
 * Breakpoint maps count: an overlay declared only at one width still darkens the
 * band there, and a focus ring that is legible at some widths is not legible.
 */
function pp_udc_band_has_overlay(array $item): bool {
    $band = $item['udc']['_band']['background'] ?? null;
    if (!is_array($band)) {
        return false;
    }
    $has = static function ($value): bool {
        // A scalar is the plain form; an array is a breakpoint map or a state map,
        // and any non-empty leaf in it still paints somewhere.
        if (is_scalar($value)) {
            return (string) $value !== '';
        }
        return is_array($value) && $value !== [];
    };
    return $has($band['image'] ?? null) && $has($band['overlay'] ?? null);
}
