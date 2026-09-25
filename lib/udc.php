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
 *
 * ALSO THE FINDINGS CAP. pp_udc_composition_findings() bounds each of its multiplying
 * `udc_*` arms at this value across one composition (the `_css` pair and the token pair
 * share one budget each; overlay, preset-skip, preset-shadow, item-shadow,
 * overlay-accent-off-scrim and role-ink-over-own-surface have their own), and
 * ai-instructions/operating-loop.md tells the model the number. Changing it changes both.
 */
const PP_UDC_MAX_EMIT_DROPS = 200;

/**
 * The on-the-page check's markup bounds (#1125, /ship security specialist): the most rendered
 * bytes one band may have to be parsed, and the most one findings call parses in total. Past
 * either, presence is unknown and the finding says the size budget was the reason. Parsing
 * costs in proportion to bytes and a string prop counts 0 cards, so the card budget alone did
 * not bound it (25 bands just under the band bound were 12.5 MB).
 */
const PP_UDC_PRESENCE_MARKUP_BAND = 524288;
const PP_UDC_PRESENCE_MARKUP_CALL = 1048576;
/** The check's other two per-call budgets (ruling E1-A: bands rendered; ruling R2-A: rendered cards). */
const PP_UDC_PRESENCE_RENDERS = 25;
const PP_UDC_PRESENCE_CARDS   = 500;

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
 * The key an `items[]` entry carries its own design map under (Addendum B1).
 *
 * DELIBERATELY THE SAME WORD AS THE BAND'S, and not `_udc` or `item_udc`. B1's
 * claim is that an item map is "identical in internal shape to a band's `udc`
 * map, validated by the same engine, the same grammar, the same group taxonomy
 * and the same refusal codes" — an author who has learned one has learned the
 * other, and a second spelling would imply a second thing to learn.
 *
 * It carries NO underscore prefix, unlike `_tokens`, `_band` and `_preset`,
 * because those three are engine-owned keys sitting INSIDE a map while this is
 * the map itself, sitting beside `title` and `text` in an entry the author
 * wrote. The prefix marks "the engine owns this name"; here the name is the
 * author's entry point.
 */
const PP_UDC_ITEM_MAP_KEY = 'udc';

/** The key an `items[]` entry carries its minted styling handle under. */
const PP_UDC_ITEM_ID_KEY = 'id';

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
            // ALIGN-SELF (#1084). How this box places itself in the row or column
            // its parent lays out — the approved coverage table's own placement
            // ("Sizing | width, max/min-width, height, min-height, ALIGNMENT"),
            // and the honest one.
            //
            // IT IS NOT IN `layout`, AND THE REASON IS THE EXPOSURE GRAIN RATHER
            // THAN TASTE. `groups` is a group-grain list: a role that declares
            // `layout` gets every one of its params. `layout` is what a CONTAINER
            // does to its children, so on a role whose box is a child but not a
            // container — `section.panel`, `hero.content` — four of its five
            // params would paint nothing at any value, which is the #1006/#1048
            // inert class this group exists to avoid creating. `sizing` is
            // already declared on every geometry-bearing role and is about the
            // box ITSELF, which is exactly what this property is.
            //
            // THE ASYMMETRY THAT BUYS, STATED RATHER THAN LEFT TO BE NOTICED.
            // `layout` exposure is a derived box fact, asserted in both directions
            // against the stylesheet; `sizing` is declared on almost every role, so
            // this parameter lands on headings, labels and icons too, and on a child
            // of a BLOCK container it paints nothing at any value. That is the same
            // inert class the paragraph above refuses for the container params — the
            // difference is degree, and it is the honest trade: `align-self` is live
            // on any child of a flex or grid box (most role boxes in these
            // components are), whereas a container param on a non-container is dead
            // in every arrangement. Constraining it further would mean deriving each
            // role's PARENT from the stylesheet, which the selector text does not
            // reliably give. Recorded so the next reader meets a decision rather
            // than an oversight.
            'align-self' => ['property' => 'align-self', 'type' => 'align-self', 'signed' => false, 'max_values' => 1, 'keywords' => []],
        ]],
        // ── LAYOUT (#1084) ──────────────────────────────────────────────────
        //
        // The approved coverage table's 1st-cut group that was never built, and the
        // one the program's own code kept routing around in writing: ten shipped
        // sites said "the UDC taxonomy carries no layout group" and three issues
        // (#658, #905, #588) asked for it. The full contract is
        // docs/v2/LAYOUT-GROUP-CONTRACT.md; three things belong here, where the
        // data lives.
        //
        // 1. IT IS AN OVERLAY, NOT A MIGRATION. These five properties stay in the
        //    css-lint STRUCTURAL set and stay in components.css. ~35 of the shipped
        //    declarations are variant- or tier-scoped mechanism (`.cta--inline
        //    .cta__inner`, `.hero--split[data-pp-split-ratio="60-40"]`) that a flat
        //    role address cannot express at any value, and a role DEFAULT — which
        //    emits unlayered — would not replace those rules but OUTRANK them,
        //    killing the variant. So the group emits AUTHORED values only, no role
        //    may declare a layout default, and a registry-derived schema test
        //    enforces that rather than a convention.
        //
        // 2. WHAT MAKES THE DUAL HOME LEGAL. The one-home rule
        //    (`aspect-ratio`/`object-position`) guarantees REACHABILITY: a value in
        //    CSS that no role owns is one no author can reach. A structural rule for
        //    a property the registry owns is reachable by definition, because an
        //    authored band block is unlayered and beats it. The exception carries
        //    its own condition so it cannot be copied for convenience: the registry
        //    must own the property AND its stylesheet occurrences must be
        //    variant-/tier-conditioned. An unconditioned value on a bare role
        //    selector still has to move.
        //
        // 3. WHY THERE IS NO `gap` HERE, though the coverage table lists one: the
        //    `spacing` group has carried `gap`/`row-gap`/`column-gap` since Sprint 0.
        //    That is the one-home rule working, not a missing parameter.
        'layout' => ['params' => [
            // COLUMNS. A count (`4`) or a track list; the count becomes
            // `repeat(4, minmax(0, 1fr))` at emit, and `display: grid` rides with
            // it as an engine companion — see _pp_udc_grid_columns_companion().
            // `companion` is the registry fact behind the `display: grid` the engine
            // emits beside an authored count (_pp_udc_grid_columns_companion). It
            // lives HERE rather than as a parameter-name check in the emitter, so
            // both halves of this parameter's behaviour — the synthesis and the
            // companion — come from the one table. A second track-list parameter,
            // or a rename of `columns`, then changes nothing by accident.
            'columns'     => ['property' => 'grid-template-columns', 'type' => 'track-list', 'signed' => false, 'max_values' => 1, 'keywords' => [], 'companion' => 'grid'],
            'orientation' => ['property' => 'flex-direction',  'type' => 'flex-direction',  'signed' => false, 'max_values' => 1, 'keywords' => []],
            'wrap'        => ['property' => 'flex-wrap',       'type' => 'flex-wrap',       'signed' => false, 'max_values' => 1, 'keywords' => []],
            'justify'     => ['property' => 'justify-content', 'type' => 'justify-content', 'signed' => false, 'max_values' => 1, 'keywords' => []],
            'align'       => ['property' => 'align-items',     'type' => 'align-items',     'signed' => false, 'max_values' => 1, 'keywords' => []],
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
 * WHAT IS DELIBERATELY ABSENT: every resource-bearing property (`background-image`,
 * `cursor`, `mask`, `filter`, `border-image`, …). They need no exclusion because NO
 * VALUE MAY NAME AN EXTERNAL RESOURCE — _pp_forbidden_css_construct() refuses `url()`,
 * `image-set()`, `image()` and `src()` alike, ahead of everything.
 *
 * THAT PREMISE WAS ONCE FALSE AND THE COMMENT SAID IT ANYWAY, which is why it is
 * spelled out now. The gate banned the token `url(` and this docblock cited it as
 * covering the whole class — but `image-set()` takes a bare STRING as its image, so a
 * value could name a host while containing no `url(` at all, and every untyped
 * image-accepting property inherited the hole. Found by the pre-landing security pass
 * and closed at the VALUE level, where the rule already lived. Excluding the properties
 * instead would suggest the property was the risk when the value always was.
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
 * The longhands a CSS shorthand resets, for the collision disclosure (#1079).
 *
 * WHY THIS TABLE EXISTS AT ALL, since the contract deleted the rule that needed it. The
 * pre-ruling design forbade `_css` from naming any property in a registry shorthand
 * FAMILY, which made this collision impossible; R2′ deleted that rule in favour of
 * ranking and disclosure. The ranking shipped and the disclosure did not follow the
 * property into its family, so a raw `border` silently killed three authored values and
 * reported only that `border` was unchecked.
 *
 * SCOPED TO WHAT THE REGISTRY ACTUALLY EMITS. This is not a general CSS shorthand map and
 * must not grow into one: every entry here exists because a group parameter owns the
 * longhand, so a shorthand over it is a collision an author can act on. A shorthand whose
 * longhands no group emits collides with nothing and belongs nowhere near this list.
 */
function _pp_udc_css_shorthand_longhands(string $property): array {
    static $families = null;
    if ($families === null) {
        $families = [
            'border'      => ['border-width', 'border-style', 'border-color'],
            'border-top'  => ['border-top-width', 'border-top-style', 'border-top-color'],
            'border-right' => ['border-right-width', 'border-right-style', 'border-right-color'],
            'border-bottom' => ['border-bottom-width', 'border-bottom-style', 'border-bottom-color'],
            'border-left' => ['border-left-width', 'border-left-style', 'border-left-color'],
            'font'        => ['font-family', 'font-size', 'font-style', 'font-weight', 'line-height'],
            'background'  => ['background-image', 'background-position', 'background-size', 'background-repeat'],
            'gap'         => ['row-gap', 'column-gap'],
            'padding'     => ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'],
            'margin'      => ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'],
            'border-radius' => [
                'border-top-left-radius', 'border-top-right-radius',
                'border-bottom-right-radius', 'border-bottom-left-radius',
            ],
            'transition'  => ['transition-duration', 'transition-timing-function'],
            'inset'       => [],
        ];
    }
    return $families[$property] ?? [];
}

/**
 * Is this property writable through `_css` at all? (contract §2′.2, §6.0.)
 *
 * ONE NAME FOR ONE DECISION. The compiler and the disclosure walk had this as a
 * copy-pasted pair, and they MUST agree byte for byte: a property the emitter drops but
 * the disclosures describe as "emitted exactly as written" reports on the wrong subject,
 * and a property the emitter paints but the disclosures skip is an undisclosed escape.
 * That is the write-accept/emit-drop divergence the #570 convergence rule forbids, arriving
 * between two functions that were keeping the same rule in their own words.
 *
 * The WRITE gate deliberately keeps the two halves apart — a bad charset and an excluded
 * property get different refusals, because the author needs different advice — so it is not
 * a third caller of this. That split is a decision, recorded here rather than looking like
 * an oversight.
 */
function pp_udc_css_property_admissible(string $property): bool {
    return pp_udc_valid_css_property($property)
        && _pp_udc_css_exclusion_reason($property) === null;
}

/**
 * Why this property is excluded, or null when it is not — ONE lookup, two callers.
 *
 * AN EXCLUSION SURVIVES A VENDOR PREFIX (#1079). The charset deliberately admits one
 * leading `-<vendor>-` so a real prefixed property is writable, which made the exclusion
 * set — an exact-string lookup — trivially sidesteppable: `all` was refused while
 * `-webkit-all` was accepted and EMITTED, though `all`'s stated reason is that it resets
 * every other declaration in the block. `-ms-behavior` and `-webkit-content` passed the
 * same way. Found by the adversarial pass.
 *
 * The unprefixed form carries the reason, so the prefix is stripped and the same lookup
 * asked again, and the REASON comes back rather than a boolean — the write gate quotes it
 * so the refusal says why, which is the whole point of a short, argued exclusion set. That
 * is also why the write gate cannot simply call `pp_udc_css_property_admissible()`: a
 * boolean cannot tell an author whether they hit the charset or an exclusion, and those
 * want different advice.
 *
 * Costs nothing: no shipped value uses a prefixed form of any excluded property, and a
 * prefixed property that is not excluded is untouched.
 */
function _pp_udc_css_exclusion_reason(string $property): ?string {
    $excluded = pp_udc_css_excluded_properties();
    if (isset($excluded[$property])) {
        return $excluded[$property];
    }
    if (preg_match('/^-[a-z]+-(.+)\z/', $property, $m) && isset($excluded[$m[1]])) {
        return $excluded[$m[1]];
    }
    return null;
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
    // `signed` and `keywords` are deliberately ABSENT (#1079's approved cleanup).
    // The untyped path never reaches pp_udc_validate_value()'s numeric or keyword
    // arms — `type => null` lands in the shared validator's generic arm — so both
    // keys were read by nothing. A key that nothing reads reads as a contract.
    return [
        'property'   => $property,
        'type'       => null,
        'max_values' => 1,
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
        // THE GUARD FOLLOWS THE PROPERTY, NOT THE REGISTRY (#1079).
        //
        // Derived from the motion GROUP's params, this set covered every way motion could
        // be authored — until `_css` let an author write `transition`, `animation` or their
        // longhands directly. Measured: `motion.transition-duration` emitted its
        // `prefers-reduced-motion` neutralisation and `_css: {"transition": "2s all"}`
        // emitted none, so a sanctioned channel defeated an accessibility guarantee the
        // engine states, disclosed only as "a property the vocabulary does not know".
        //
        // The shorthands and longhands below are the CSS properties that animate; they are
        // listed rather than derived because the registry does not own them — that is the
        // whole point. `animation-*` names that only shape an animation (name, fill-mode,
        // direction) are omitted: neutralising duration and iteration-count is what
        // base.css's global kill switch already does, and what stops motion.
        foreach ([
            'transition', 'transition-duration', 'transition-delay',
            'animation', 'animation-duration', 'animation-delay', 'animation-iteration-count',
            'scroll-behavior', 'view-transition-name',
        ] as $property) {
            $properties[$property] = true;
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
 * Walks the band map AND every item (card) map of each band (#1115), naming a card
 * reference as `item "<id>"`.
 *
 * Bounded to the delete verb. It reads one option (or scans the site map its caller
 * passes as $site — the under-lock writer's row, read past the cache) and one meta row
 * per composition page, which is a site-sized walk on a verb an author runs rarely —
 * never on a render path, never in preflight.
 *
 * BOUNDED AT THE SOURCE, not by its reader — the rule _pp_udc_place()'s drop ledger
 * states in this same file ("Bounding here is the only place that bounds the
 * ALLOCATION"), and the one collector that had not applied it. Its one consumer,
 * pp_udc_preset_delete_reference_refusal() (run by delete_preset's validate arm AND
 * again under the writer's lock, #1115), renders at most twenty reference locators and ten
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
function pp_udc_preset_references(string $name, ?array $site = null): array {
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
    // THE CALLER MAY HAND IN THE ROW IT HOLDS (#1115). The under-lock writer passes the
    // site map it just read past the cache, because pp_udc_site_map() answers from the
    // request's option cache — right for rendering, and exactly the staleness a backstop
    // cannot have: a chrome reference committed after this request loaded its options
    // would otherwise be missed under the very lock that exists to catch it.
    $site = $site ?? pp_udc_site_map();
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
            if (!is_array($item)) {
                continue;
            }
            $band = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : ('index ' . $i);
            // BOTH GRAINS (#1115). This walked only `$item['udc']`, so a preset a CARD
            // referenced was invisible here: delete_preset answered ok:true, findings:[],
            // and the card silently stopped painting. The two sources are the same list
            // the findings walk builds for `udc_preset_groups_skipped` (#1101) — the band
            // map with no locator, then each item map keyed by its id — so the gate and
            // the disclosure agree about where a reference can live.
            $maps = [];
            if (isset($item['udc']) && is_array($item['udc'])) {
                $maps[] = ['', $item['udc']];
            }
            foreach (pp_udc_item_maps($item) as $item_id => $item_map) {
                $maps[] = [(string) $item_id, $item_map];
            }
            foreach ($maps as [$locator, $map]) {
                foreach (_pp_udc_map_references_preset($map, $name) as $where) {
                    $out['references_total']++;
                    if (count($out['references']) < PP_UDC_MAX_PRESET_REFERENCES) {
                        $out['references'][] = sprintf(
                            'page %d ("%s") band %s%s %s',
                            $id,
                            $title,
                            _pp_udc_reflect($band),
                            $locator === '' ? '' : sprintf(' item "%s"', _pp_udc_reflect($locator)),
                            $where
                        );
                    }
                }
            }
        }
    }
    return $out;
}

/**
 * The reverse dangling-reference refusal for delete_preset (#1016), or null when nothing
 * blocks the delete (#1115: shared by the action's validate arm and the under-lock writer).
 *
 * Refuses when any band, CARD or chrome role still references the preset, and when a page
 * that might reference it cannot be read ("I could not look" is never "there is nothing
 * there", invariant I9). The caller decides the shadowed-row exemption first: a site row
 * a theme preset shadows can always be deleted, because every reference resolves to the
 * theme's bundle before and after.
 *
 * @param array|null $site The site map to scan for chrome references; the under-lock
 *                         writer passes the row it read past the cache. Null reads the
 *                         cached option, which is right for validate and preview.
 */
function pp_udc_preset_delete_reference_refusal(string $name, ?array $site = null): ?WP_Error {
    $scan = pp_udc_preset_references($name, $site);
    if ($scan['unreadable'] !== []) {
        $unreadable_total = (int) $scan['unreadable_total'];
        return new WP_Error('preset_scan_unreadable', sprintf(
            'Whether "%s" is still in use cannot be determined: the stored composition of %s could '
            . 'not be read, and a preset may not be deleted while a page that might reference it is '
            . 'unreadable. Repair %s first (wp pp operate composition-history --post_id=<id>), then '
            . 'delete again.',
            $name,
            // BOUNDED LIKE ITS SIBLING ELEVEN LINES DOWN. Each fragment is
            // cleaned by _pp_udc_reflect(), but the LIST was not, and its
            // length is linear in the number of unreadable composition pages —
            // so a site with many of them turned every refusal into a
            // tens-of-KB message on the terminal, the chat envelope and the
            // model's context. The count is stated separately, so nothing
            // diagnostic is lost by showing ten names instead of all of them.
            pp_udc_bounded_list($scan['unreadable'], 10, $unreadable_total),
            $unreadable_total === 1 ? 'it' : 'those pages'
        ));
    }
    if ($scan['references'] !== []) {
        // THE COUNT IS THE TOTAL, THE LIST IS THE SAMPLE. The collector caps
        // what it keeps, so `references` is at most PP_UDC_MAX_PRESET_REFERENCES
        // while `references_total` is exact — and it is the total an operator
        // needs to know, not how many the collector chose to hold.
        $total = (int) $scan['references_total'];
        return new WP_Error('preset_in_use', sprintf(
            'The preset "%s" is still referenced by %s, so it was not deleted: %s. Change or remove '
            . 'those references first — deleting now would leave each of them pointing at a preset '
            . 'that does not exist, and those declarations would stop painting with nothing to say why.',
            $name,
            $total === 1 ? '1 place' : $total . ' places',
            pp_udc_bounded_list($scan['references'], 20, $total, '; ')
        ));
    }
    return null;
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

// ── Item grain (BUILD-SPEC Addendum B) ──────────────────────────────────────

/**
 * A component's ITEM-GRAIN declaration, from `schema.item_roles`, or null.
 *
 * ADDENDUM B5 IN ONE FUNCTION: item-grain addressing is DECLARED, and the
 * engine holds no list of component names. A component that declares nothing
 * here behaves exactly as it did before this tier existed — which is what makes
 * the tier additive rather than a migration.
 *
 * The declaration names three things and no more:
 *   prop   the repeater prop that holds the entries
 *   root   the role an entry's own `data-pp-item` attribute is rendered on
 *   roles  which of the component's roles an entry's map may address
 *
 * FAILS CLOSED ON A MALFORMED DECLARATION, and that is the #1090 lesson applied
 * before it can bite: a declaration this cannot read returns null, so the
 * component simply has no item grain rather than half of one. A schema that
 * reaches that branch is a repo bug, and GridItemUdcTest's declaration test
 * fails on it in CI
 * rather than leaving it to be discovered as missing CSS.
 *
 * EVERY NAMED ROLE MUST EXIST, and `root` must be among them. A role named here
 * that the component does not declare would validate an author's map against a
 * role the emitter then skips — stored, reported ok, painting nothing, which is
 * the I35 class this contract exists to close.
 *
 * @return array{prop: string, root: string, roles: array<int, string>}|null
 */
function pp_udc_item_roles(string $component): ?array {
    $registered  = pp_get_registered_components();
    $declaration = $registered[$component]['item_roles'] ?? null;
    if (!is_array($declaration)) {
        return null;
    }

    $prop = $declaration['prop'] ?? null;
    $root = $declaration['root'] ?? null;
    $list = $declaration['roles'] ?? null;
    if (!is_string($prop) || $prop === '' || !is_string($root) || $root === ''
        || !is_array($list) || $list === [] || !pp_is_list($list)) {
        return null;
    }

    $roles = pp_udc_component_roles($component);
    if (!isset($roles[$root])) {
        return null;
    }
    foreach ($list as $name) {
        if (!is_string($name) || !isset($roles[$name])) {
            return null;
        }
    }
    if (!in_array($root, $list, true)) {
        return null;
    }
    // The repeater must be a declared prop, or nothing ever reaches this tier.
    $props = $registered[$component]['props'] ?? [];
    if (!is_array($props) || !isset($props[$prop])) {
        return null;
    }

    return ['prop' => $prop, 'root' => $root, 'roles' => array_values($list)];
}

/**
 * The shape of an item's styling handle (Addendum B2).
 *
 * STRICTER THAN pp_udc_valid_band_id() ON PURPOSE, and the asymmetry is the
 * whole safety argument for widening the token namespace. A band id is
 * permissive because v2 inherited authored ids; an item id is minted by this
 * engine and by nothing else, so it can be pinned to exactly the shape the
 * engine produces: the literal prefix, then eight lowercase hex digits.
 *
 * WHAT THE PREFIX BUYS, beyond readability. `it-` cannot begin a band id that
 * this engine mints (`pp-`), so the two can never be confused in a selector, a
 * message or a test — B2 asks for that in as many words. It also gives the mint
 * classifier an unambiguous first segment to split on, which is what lets a
 * token name carrying an item segment be resolved back to the ONE map that
 * could have produced it instead of searched for across all of them.
 *
 * ANCHORED WITH `\z`, NOT `$`. PCRE's `$` matches before a final newline unless
 * the `D` modifier is set, so `/^…$/` accepts "it-deadbeef\n" — and this value
 * is interpolated into a CSS attribute selector. Every sibling string-to-CSS
 * gate in this file anchors the same way; widen the CLASS if a ruling says so,
 * never the ANCHORING.
 */
function pp_udc_valid_item_id(string $id): bool {
    return (bool) preg_match('/^it-[0-9a-f]{8}\z/', $id);
}

/**
 * The reserved keys an ITEM map may not carry, each with the reason it is
 * refused rather than ignored (Addendum B6 exclusions 2, 7, and the `_tokens`
 * boundary that falls out of B4).
 *
 * REFUSED, NEVER IGNORED. A key accepted and dropped is the accepted-stored-
 * ignored shape invariant I35 forbids, and it is worse here than at band grain:
 * an author who writes `_band` inside a card is expressing an intention the
 * contract has decided against, and silence would let them believe it landed.
 *
 * `_tokens` is not an exclusion the owner ruled — it falls out of B4. Minted
 * item values lift their literals into the BAND's `_tokens` map, because the
 * tokens are emitted as custom properties on the band root and that is the only
 * element both tiers share. A second token map inside an item would have no
 * element to declare itself on.
 *
 * @return array<string, string> key => the sentence the refusal uses
 */
function pp_udc_item_reserved_keys(): array {
    return [
        '_band' => 'an item cannot restyle the band that contains it — `_band` is the band\'s own root '
            . 'by definition, so set it on the band\'s own map',
        PP_UDC_CSS_KEY => 'raw CSS is not available at item grain: every disclosure that makes it safe '
            . 'on a band reports with a band locator and cannot yet name a single item',
        '_tokens' => 'tokens are declared once per band, on the band\'s own map — they emit as custom '
            . 'properties on the band root, which is the only element the band and its items share',
    ];
}

/**
 * The `udc` maps a composition item carries at ITEM grain, keyed by item id.
 *
 * ONE READER FOR A SHAPE FOUR CALLERS NEED. The write gate, the compiler, the
 * findings walk and the mint classifier all have to agree about which entries
 * carry a map and what id each one answers to; four hand-rolled walks of
 * `props[<prop>]` would be four chances to disagree, and a disagreement between
 * the gate and the emitter is the I29 write/render split this engine exists to
 * prevent.
 *
 * SKIPS WHAT CANNOT BE ADDRESSED, silently and deliberately: an entry with no
 * usable id has no selector to emit under, and an entry with no map has nothing
 * to emit. A MALFORMED id is not an error HERE — the write gate refuses that,
 * and reporting it twice from two layers would give an operator two findings for
 * one fact.
 *
 * THE OTHER HALF IS NARROWER THAN IT LOOKS, stated so the silence is not read as
 * a guarantee: an entry carrying a map with NO id is refused NOWHERE.
 * pp_udc_assign_band_ids() mints one on the write path, so the shape can only
 * arrive off it — a raw meta write, a composition written before this tier,
 * restore_composition (#233) — and there this skips it with no drop-ledger entry
 * and no finding. That is the accepted-stored-ignored shape the item `_css` arm a
 * few hundred lines down deliberately ledgers instead.
 *
 * @return array<string, array> item id => that item's `udc` map
 */
function pp_udc_item_maps(array $item): array {
    $component = isset($item['component']) && is_scalar($item['component'])
        ? (string) $item['component']
        : '';
    if ($component === '') {
        return [];
    }
    $declaration = pp_udc_item_roles($component);
    if ($declaration === null) {
        return [];
    }
    $entries = $item['props'][$declaration['prop']] ?? null;
    if (!is_array($entries)) {
        return [];
    }

    $maps = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = isset($entry[PP_UDC_ITEM_ID_KEY]) && is_scalar($entry[PP_UDC_ITEM_ID_KEY])
            ? (string) $entry[PP_UDC_ITEM_ID_KEY]
            : '';
        if ($id === '' || !pp_udc_valid_item_id($id)) {
            continue;
        }
        $map = $entry[PP_UDC_ITEM_MAP_KEY] ?? null;
        if (!is_array($map) || $map === []) {
            continue;
        }
        // LAST ONE WINS IS NOT A CHOICE HERE — a duplicate id inside one band is
        // refused at write (Addendum B2), so reaching this line with two entries
        // claiming one id means stored data the gate never saw. Keeping the first
        // is the conservative read: it is the one whose selector an already-
        // rendered page was built against.
        if (!isset($maps[$id])) {
            $maps[$id] = $map;
        }
    }
    return $maps;
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
 * Whether a `udc` map could make the emitter drop an overlay (#1117): true when it names
 * an `overlay` key anywhere, directly or inside the bundle of a `_preset` it references.
 * A cheap necessary condition that lets the findings walk skip compiling bands that
 * cannot write that row; it never decides the case itself. Deeper than any real map is
 * answered true, so a shape it cannot see through is compiled rather than skipped.
 *
 * READ THROUGH THE PRESET, not merely at it: `button` and `link` sit on most bands, and
 * counting every `_preset` as a candidate compiled nearly every band on every write.
 * A preset cannot reference another preset, so one level of resolution is the whole
 * answer; a dangling name is refused at write and has nothing to compile.
 */
function _pp_udc_map_may_carry_overlay($map, int $depth = 0, bool $in_preset = false, ?array &$memo = null): bool {
    if (!is_array($map)) {
        return false;
    }
    if ($depth > 8) {
        return true;
    }
    foreach ($map as $key => $value) {
        if ($key === 'overlay') {
            return true;
        }
        if ($key === PP_UDC_PRESET_KEY) {
            // ONE LEVEL, ENFORCED rather than assumed. No write path can store a preset that
            // references another, but the row can be written raw and is not re-validated on
            // read — and following nested references let a stored chain of wide bundles cost
            // O(width^depth) on every findings call. A reference found INSIDE a bundle is
            // answered "compile", which costs one compile of this band and cannot be wrong.
            if ($in_preset) {
                return true;
            }
            if (!is_string($value)) {
                continue;
            }
            // ONCE PER PRESET PER CALL, when the caller keeps a memo: the answer depends on
            // the bundle alone, and a bundle may be as large as the row allows while cards
            // may reference it without limit — references x bundle size otherwise.
            if ($memo === null || !array_key_exists($value, $memo)) {
                $preset = pp_udc_resolve_preset($value);
                $answer = $preset !== null
                    && _pp_udc_map_may_carry_overlay($preset['udc'] ?? [], $depth + 1, true);
                if ($memo === null) {
                    if ($answer) {
                        return true;
                    }
                    continue;
                }
                $memo[$value] = $answer;
            }
            if ($memo[$value]) {
                return true;
            }
            continue;
        }
        if (is_array($value) && _pp_udc_map_may_carry_overlay($value, $depth + 1, $in_preset, $memo)) {
            return true;
        }
    }
    return false;
}

/**
 * The background image a role map resolves to, in the emitter's precedence: the map's own
 * `background.image`, else a group-grain `background._preset`'s, else a role-grain
 * `_preset`'s (a preset never references another). Null when there is none. Used so the
 * cross-grain overlay reason sees a preset-supplied card image as the image it is (#1117).
 *
 * @return mixed The stored image value, or null.
 */
function _pp_udc_role_map_background_image(array $role_map) {
    $background = isset($role_map['background']) && is_array($role_map['background']) ? $role_map['background'] : [];
    // The map's own image wins — while it RESOLVES. One whose attachment was deleted is
    // dropped at place time, and the emitter then paints the preset's image beneath it.
    if (array_key_exists('image', $background) && pp_udc_background_image_url($background['image']) !== null) {
        return $background['image'];
    }
    foreach ([[$background[PP_UDC_PRESET_KEY] ?? null, 'background'], [$role_map[PP_UDC_PRESET_KEY] ?? null, 'role']] as [$name, $grain]) {
        $preset = is_string($name) ? pp_udc_resolve_preset($name) : null;
        $fragment = $preset === null ? null : _pp_udc_preset_fragment($preset, $grain);
        if (!is_array($fragment)) {
            continue;
        }
        $group = $grain === 'role' ? ($fragment['background'] ?? null) : $fragment;
        // Every rung is resolve-checked, like the map's own image: a deleted attachment
        // is dropped at place time and the next rung paints beneath it.
        if (is_array($group) && array_key_exists('image', $group)
            && pp_udc_background_image_url($group['image']) !== null) {
            return $group['image'];
        }
    }
    return null;
}

/**
 * The breakpoint tiers a value occupies: a single value is the base tier only, a
 * breakpoint map is its keys. The emitter ranks per (state, tier, property), so this is
 * where a preset and a role default actually meet.
 *
 * @return array<int, string>
 */
function _pp_udc_value_tiers($value): array {
    return is_array($value) ? array_map('strval', array_keys($value)) : ['d'];
}

/**
 * The shadow entry for one preset value against one role default, per tier (#1116): null
 * when the default covers none of the preset's tiers (the preset paints there), otherwise
 * [label, lost tiers, preset tiers] — worded by _pp_udc_shadow_entry_label().
 */
function _pp_udc_shadow_entry_for_tiers(string $label, $preset_value, $default_value): ?array {
    $preset_tiers = _pp_udc_value_tiers($preset_value);
    $lost = array_values(array_intersect($preset_tiers, _pp_udc_value_tiers($default_value)));
    return $lost === [] ? null : [$label, $lost, $preset_tiers];
}

/**
 * Words one shadow entry: the plain label when every preset tier is lost (the value is
 * not applied anywhere), otherwise the label naming the lost tiers.
 */
function _pp_udc_shadow_entry_label(array $entry): string {
    [$label, $lost, $preset_tiers] = $entry;
    if (count($lost) === count($preset_tiers)) {
        return $label;
    }
    return $label . ' at breakpoint ' . implode('/', array_map('_pp_udc_reflect', $lost));
}

/**
 * The tiers an authored value covers against a role default: `true` (all) when it paints
 * at the base tier, otherwise the narrower tiers it names.
 *
 * @return array<int, string>|true
 */
function _pp_udc_authored_tiers($value) {
    return _pp_udc_value_covers_base_tier($value) ? true : _pp_udc_value_tiers($value);
}

/**
 * Whether an authored value paints at the base (desktop) tier: a single value does, and a
 * breakpoint map does only when it carries `d`. A value written only for narrower tiers
 * leaves the base tier to whatever outranks the preset there, so it cannot stand in for
 * the preset's value in the shadow finding (#1116).
 */
function _pp_udc_value_covers_base_tier($value): bool {
    return !is_array($value) || array_key_exists('d', $value);
}

/**
 * The `group.param` / `group.param (state)` labels a role map sets itself, in the shape
 * of the base label of each _pp_udc_preset_values_shadowed_entries() entry (the label
 * without any "at breakpoint …" suffix), so the shadow finding can
 * leave out what the author already wrote (their value outranks preset and default).
 *
 * Keyed by label; each value is the tiers the author's value covers, where a value
 * covering the base tier covers them all (it is band-scoped and prints after the
 * component-scoped defaults, so it wins at every width the default takes).
 *
 * @return array<string, array<int, string>|true>  true = every tier
 */
function _pp_udc_authored_value_labels(array $role_map): array {
    $states = pp_udc_states();
    $labels = [];
    foreach ($role_map as $group => $group_map) {
        if (!is_array($group_map) || str_starts_with((string) $group, '_')) {
            continue;
        }
        foreach ($group_map as $key => $value) {
            $key = (string) $key;
            if ($key === PP_UDC_PRESET_KEY) {
                continue;
            }
            if (isset($states[$key])) {
                foreach (is_array($value) ? $value : [] as $param => $state_value) {
                    $labels[$group . '.' . (string) $param . ' (' . $key . ')'] = _pp_udc_authored_tiers($state_value);
                }
                continue;
            }
            $labels[$group . '.' . $key] = _pp_udc_authored_tiers($value);
        }
    }
    return $labels;
}

/**
 * The ledger locator for a dropped overlay (#1117): the card when there is one, the role,
 * and the state / breakpoint bucket it was dropped from — each fragment cleaned, because
 * these are stored keys riding an operator-facing row (the rule _pp_udc_place() states).
 */
function _pp_udc_overlay_drop_where(string $item_id, string $role, string $state, string $bp): string {
    return trim(
        ($item_id !== '' ? sprintf('item "%s" ', _pp_udc_reflect($item_id)) : '')
        . sprintf('role "%s"', _pp_udc_reflect($role))
        . ($state !== '' ? sprintf(' (%s)', _pp_udc_reflect($state)) : '')
        . ($bp !== '' && $bp !== 'd' ? sprintf(' at breakpoint %s', _pp_udc_reflect($bp)) : '')
    );
}

/**
 * A RAW `background` SHORTHAND WINS ITS COORDINATE (#1141, ruling D1 = A; contract §2'.3).
 *
 * `_css` outranks a group value at the same (state, breakpoint), and a shorthand resets every
 * longhand it owns. The emitter sorted the raw `background` BEFORE the group's composed
 * `background-image` (and its scrim and companions), so the image painted over the raw value
 * while `udc_css_overrides_group_value` told the author it did not. Printing the raw shorthand
 * last and dropping what it resets paint the same thing; dropping is what this does, so the
 * compiled band (which the overlay marker and the findings read) says what the page shows: in a
 * bucket whose `background` is raw, every non-raw `background-*` declaration is removed. Raw
 * longhands the author also wrote stay (they print after the shorthand). The scrim carrier is
 * LEFT for _pp_udc_compose_background_layers(), which drops a scrim with no image under it and
 * says so (`overlay_without_image`, naming the raw background), so no scrim goes silently.
 *
 * Per bucket and before a narrower tier borrows the base image, so a scrim declared only at a
 * narrower width has nothing left to lie over and is dropped with its ledger row.
 *
 * @param array<string, array<string, array>> $by_bp One state's buckets, bp => declarations.
 * @return array<string, array<string, array>>
 */
function _pp_udc_raw_background_wins(array $by_bp): array {
    $desktop_image_removed = false;
    foreach ($by_bp as $bp => $declarations) {
        if (empty($declarations['background']['raw'])) {
            continue;
        }
        foreach ($declarations as $property => $entry) {
            if (strncmp((string) $property, 'background-', 11) === 0 && empty($entry['raw'])) {
                if ($property === 'background-image' && (string) $bp === 'd') {
                    $desktop_image_removed = true;
                }
                unset($by_bp[$bp][$property]);
            }
        }
        // The scrim left here is dropped by the compose stage; this tells it WHY, so its ledger row names the raw
        // background rather than asking for an image the author did set (PR-2 review, api-contract).
        if (is_array($by_bp[$bp][PP_UDC_BACKGROUND_OVERLAY_CARRIER] ?? null)) {
            $by_bp[$bp][PP_UDC_BACKGROUND_OVERLAY_CARRIER]['raw_background_won'] = true;
        }
    }
    // A raw desktop background that removed the image leaves a scrim set only at a narrower width with no image to
    // borrow, so its scrim is dropped for the same reason and says so, not "Set background.image" to an author who
    // set one (red team RT2; a narrower fill included, review cycle 2 design).
    if ($desktop_image_removed) {
        foreach ($by_bp as $bp => $declarations) {
            // A fill of its own at that width does not bring the image back: the cause is still the raw background.
            if ($bp !== 'd' && !isset($declarations['background-image'])
                && is_array($declarations[PP_UDC_BACKGROUND_OVERLAY_CARRIER] ?? null)) {
                $by_bp[$bp][PP_UDC_BACKGROUND_OVERLAY_CARRIER]['raw_background_won'] = true;
            }
        }
    }
    return $by_bp;
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
 * AN OVERLAY WITH NO IMAGE EMITS NOTHING — and, since #1117, is not SILENT. The
 * render is unchanged: a band can carry an overlay whose image was dropped at emit
 * because the attachment was deleted, and that band should keep painting its
 * `fill`, not grow a mystery scrim over it; emitting the scrim alone would be a
 * declaration the author never asked for. What changed is that the discard writes
 * a drop-ledger row (code `overlay_without_image`), which the write-envelope
 * findings walk surfaces as `udc_overlay_without_image`. Its reason never claims
 * the author set no image, because a deleted attachment reaches this branch too.
 *
 * @param array       $declarations One bucket's resolved declarations.
 * @param array|null  $drops        The compile's drop ledger; null on render paths.
 * @param string      $where        The ledger locator (card, role, state, breakpoint).
 * @param bool        $in_state     True for a state bucket (`:hover`), whose scrim
 *                                  can never have an image of its own.
 * @param bool        $in_item      True at item (card) grain, whose compile does not
 *                                  combine the band map's image for the same role.
 * @param bool        $cards_set_image True at band grain when some card's map resolves
 *                                  a usable background image for this item role (its
 *                                  own background.image, or one a preset supplies):
 *                                  that card's image replaces its whole background, so
 *                                  the band scrim reaches no such card.
 */
function _pp_udc_compose_background_layers(array $declarations, ?array &$drops = null, string $where = '', bool $in_state = false, bool $in_item = false, bool $cards_set_image = false): array {
    if (!array_key_exists(PP_UDC_BACKGROUND_OVERLAY_CARRIER, $declarations)) {
        return $declarations;
    }
    $overlay = $declarations[PP_UDC_BACKGROUND_OVERLAY_CARRIER];
    unset($declarations[PP_UDC_BACKGROUND_OVERLAY_CARRIER]);

    if (!isset($declarations['background-image'])) {
        // Scrim over nothing: dropped — and, since #1117, SAID so. This was the one
        // parameter the write gate accepts and the emitter discards with no ledger row
        // and no finding; an exhaustive item-grain sweep found it to be the only
        // accepted-and-silent cell in the whole grammar. The render behaviour stays as it
        // was (a scrim needs an image); the silence was the defect. The row carries a
        // `code` so the write-time findings walk can surface THIS discard and no other,
        // and it names the `fill` pairing because that is the mistake an author will
        // actually make: a scrim over a colour is a reasonable thing to expect.
        if ($drops !== null && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
            $drops[] = [
                // THE LOCATOR SAYS WHEN THE OVERLAY CAME FROM A PRESET (#1016), exactly as
                // _pp_udc_place() says it for every other preset-sourced drop: otherwise the
                // row points at a role in the author's own map that holds no overlay.
                'where'  => trim($where . ' background.overlay'
                    . (is_array($overlay) && is_string($overlay['source'] ?? null)
                        && strncmp($overlay['source'], 'preset:', 7) === 0
                        ? sprintf(' (via preset "%s")', _pp_udc_reflect(substr($overlay['source'], 7)))
                        : '')),
                // A STATE's scrim has its own reason. `background.image` is refused
                // inside a state, so a `:hover` bucket never holds an image of its
                // own and its overlay is dropped even when the role HAS a base image
                // (states do not borrow it the way narrower breakpoints do). Saying
                // "declares no background.image" there would be false.
                'reason' => $in_state
                    ? 'an overlay inside a state paints only over an image in that same state, and '
                      . 'background.image cannot be set inside a state, so the scrim was dropped even if the '
                      . 'role has a base image. Move the overlay out of the state, or remove it'
                    // A CARD's scrim composes only with the card's own image: the item
                    // compile does not combine the band map's image for the same role, so
                    // "set background.image" would be wrong advice to an author who did.
                    : ($in_item
                    ? 'an overlay on a card paints only over a background.image in that card\'s own map (an '
                      . 'image set for this role on the band\'s map is not combined with it), and this card has '
                      . 'no usable one (none is set, or its attachment was deleted), so the scrim was dropped. '
                      . 'Set background.image on this card, or remove the overlay'
                    // The reverse: a BAND scrim over images the cards set for this role. A
                    // card's own image replaces that card's whole background layer list, so
                    // the band's scrim reaches none of them.
                    : ($cards_set_image
                    ? 'an overlay on the band\'s map is layered only over a background.image on the band\'s '
                      . 'map, and this role has no usable one there (none is set, or the attachment it names was '
                      . 'deleted); the image a card sets for this role replaces that card\'s whole background, so '
                      . 'this scrim reaches no card. Put the overlay on each card\'s own map'
                      // Not "set background.image on the band's map": a band image would still be
                      // replaced on exactly these cards, silently (#1133).
                    // A raw `_css` background WON this coordinate (#1141): the author may well have set the image,
                    // and setting it again changes nothing, so the reason names the raw background (PR-2 review).
                    : (!empty($overlay['raw_background_won'])
                    ? (is_array($overlay['band_scrim_at'] ?? null) && $overlay['band_scrim_at'] !== []
                    // The band stays marked from the widths that still paint a scrim (PR-2 review cycle 2, design).
                    ? sprintf('the raw background in _css resets the background here, so background.image and this scrim do '
                      . 'not paint at this width. The scrim still paints at the %s, so the band stays marked and the accent '
                      . 'roles it re-lights stay near-white on this background (the off-scrim finding names them). Remove '
                      . 'the raw background at this width (put a colour in background.fill beside the image instead), or, '
                      . 'if the raw background is what you want here, set the accents\' typography.color for this width: '
                      . 'a raw background cannot carry an image', _pp_udc_widths_phrase($overlay['band_scrim_at']))
                    : 'the raw background in _css resets the background here, so background.image and this scrim do not '
                      . 'paint at this width'
                      . (isset($overlay['band_scrim_at'])
                          ? ', and with no scrim the band is not marked, so the accent roles it re-lit go back to their own colours'
                          : '')
                      . '. Remove the raw background (put a colour in background.fill beside the image '
                      . 'instead), or remove background.image and the overlay if the raw background is what you want: a raw '
                      . 'background cannot carry an image. On a dark background, set the accents\' typography.color')
                    // `background` is also where a raw `_css` shorthand lands, so this names
                    // both rather than claiming a background.fill the author may never have written.
                    : (isset($declarations['background'])
                    ? 'an overlay is layered only over background.image, and this role declares a background '
                      . '(background.fill, one supplied by a preset, or a raw background in _css) but no usable '
                      . 'background.image, so '
                      . 'the scrim was dropped. Set background.image (an attachment id), or put the tint in '
                      . 'that background itself'
                    // "NO USABLE", not "declares no": an image whose attachment was deleted after
                    // the write is dropped at place time (check 8c owns that drop), so this
                    // branch cannot tell the two causes apart and must not blame the author.
                    : 'an overlay paints only over an image, and this role has no usable background.image '
                      . '(none is set, or the attachment it names was deleted), so the scrim was dropped. Set '
                      . 'background.image (an attachment id), or remove the overlay')))),
                'code'   => 'overlay_without_image',
            ];
        }
        return $declarations;
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

/**
 * The `display: grid` an authored column count needs to mean anything (#1084).
 *
 * WHY THE ENGINE SUPPLIES IT, stated the way its background sibling above states
 * its own case. `grid-template-columns` does nothing to a flex container, and the
 * components' structural CSS is full of boxes that are a flex column at one tier
 * and a grid at another: `.section__grid` is `display: flex` at base and
 * `display: grid` only from 768px. So an authored `layout.columns` would paint
 * nothing below 768px — and PHP cannot read the stylesheet, so _pp_udc_place()'s
 * drop ledger could not even report it. A value that validates, stores, and
 * paints nothing on no channel is the I19/I35 class; building one deliberately
 * was not an option, so the companion makes "N columns" true at every tier the
 * author wrote it for.
 *
 * WHAT THE AUTHOR IS TOLD, because this is a mechanism switch rather than a pure
 * retune: on a box the stylesheet lays out with flexbox, setting `columns` makes
 * it a grid, so `orientation` and `wrap` stop applying to it. The schema
 * descriptions, the how-to and the AI-facing context all say so.
 *
 * SOURCE-AGNOSTIC SUPPRESSION. The companion is skipped whenever `display` is
 * already in this bucket, whatever put it there — today only a `_css`
 * declaration can, but the check does not depend on that staying true.
 *
 * ROLES WHOSE `display` IS A VISIBILITY SWITCH DO NOT REACH THIS THROUGH THE
 * GROUP, and the claim is scoped to that door on purpose — the first draft of this
 * docblock said "never reach this", which was wider than the code and is exactly
 * the kind of invariant a later change gets built on.
 *
 * `nav.menu` and `nav.toggle` use `display` as behaviour — `.nav__menu[hidden]`
 * against the JS that adds and removes `hidden`, and a hamburger that is
 * `display: none` from 768px. The `hidden` attribute is honoured only by the UA
 * stylesheet, which ANY author declaration outranks, so an unlayered `display:
 * grid` there pins an open mobile menu open: a styling write defeating a keyboard
 * and screen-reader affordance. Three doors, and what closes each:
 *
 *   - the `layout` GROUP — those roles do not declare it (the exposure roster),
 *     and the emitter enforces the roster too, not just the write gate;
 *   - a raw `_css` TRACK LIST — the companion rides the `layout.columns`
 *     parameter, so a raw declaration brings no `display` with it;
 *   - a raw `_css` `display` ITSELF — NOT closed here, and not this change's to
 *     close: `display` is absent from pp_udc_css_excluded_properties(), so
 *     `{"menu": {"_css": {"display": "grid"}}}` defeats `[hidden]` on this branch
 *     and on main alike. Filed rather than fixed in passing, because the exclusion
 *     set is the Layer-2 contract's (#1079 §6.0) and widening it is that
 *     contract's ruling to make.
 */
/**
 * The properties any registry parameter marks as companion-bearing.
 *
 * Derived once per request from pp_udc_groups(), so the emitter's hot loop can ask
 * "can this property carry a companion at all?" with one isset() instead of reading
 * four levels into the resolution table on every declaration it places. The
 * pre-landing performance pass measured that unguarded read at 0.23 ms of a 0.47 ms
 * regression on a 50-band page WITH NO LAYOUT VALUES — a page paying for a feature
 * it does not use, which is the cost this file's other hot-loop notes exist to
 * refuse.
 *
 * @return array<string, true>
 */
function _pp_udc_companion_properties(): array {
    static $properties = null;
    if ($properties === null) {
        $properties = [];
        foreach (pp_udc_groups() as $definition) {
            foreach ($definition['params'] as $param) {
                if (!empty($param['companion'])) {
                    $properties[$param['property']] = true;
                }
            }
        }
    }
    return $properties;
}

function _pp_udc_grid_columns_companion(array $declarations, bool $base_tier_has_display = false): array {
    // `companion` is set by _pp_udc_place() only for the `layout.columns`
    // PARAMETER. Keying on the property instead let a raw `_css` declaration pull
    // the companion onto any role at all, including the two the exposure roster
    // excludes precisely because their `display` is a visibility switch — see the
    // note at the marker for the probe that found it.
    if (empty($declarations['grid-template-columns']['companion']) || isset($declarations['display'])) {
        return $declarations;
    }
    // A NARROWER TIER BORROWS THE BASE TIER'S COMPANION instead of repeating it.
    //
    // The `d` bucket emits unlayered with no media query, so its `display: grid`
    // already applies at every width — a copy inside `@media (max-width: 767px)`
    // changes nothing and costs bytes. Measured by the pre-landing performance
    // pass: a responsive `{"d":3,"t":2,"p":1}` emitted it three times, and on a
    // layout-heavy 50-band page the repeats were 5.2% of the emitted CSS, against a
    // file where emitted size is already a live concern (#1062/#1054).
    //
    // The author who sets columns ONLY at a narrow tier still gets it there, which
    // is the case that makes the companion necessary at all.
    if ($base_tier_has_display) {
        return $declarations;
    }
    // THE VALUE COMES FROM THE REGISTRY, so the flag means what its shape promises.
    // It read `'companion' => 'grid'` while this function hardcoded `grid` and
    // every consumer tested it with `!empty()` — a table advertising configuration
    // it did not have, which the pre-landing simplification pass called out.
    $value = (string) $declarations['grid-template-columns']['companion'];
    $declarations['display'] = [
        'css'     => $value,
        'literal' => $value,
        'source'  => 'engine-companion',
    ];
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

    // `!important` IS NEVER AUTHORABLE (contract §6.14), and Layer 2 was the first way in.
    //
    // Every typed grammar rejects it as a side effect of being a grammar, so this never
    // needed saying — until an UNTYPED `_css` value started reaching the sheet verbatim.
    // Measured: `{"_css": {"opacity": "0.5 !important"}}` was accepted and emitted, and
    // that is not a cosmetic breach. This engine's whole cascade story is that specificity
    // is flat by construction and `!important` never appears, so one authored `!important`
    // makes a band value unbeatable by the role defaults, by a later band, and by the
    // author's own next write — the cliff v1's inline styles were, rebuilt by hand.
    //
    // GATED HERE rather than in the `_css` validator because BOTH doors call this function:
    // the write gate through _pp_udc_validate_scalar(), and _pp_udc_place() when it
    // re-validates stored data. One predicate, so the gates cannot disagree.
    if (!empty($param['untyped']) && preg_match('/!\s*important/i', $value)) {
        return new WP_Error('invalid_udc_value',
            'Value must not carry "!important". This engine keeps specificity flat by '
            . 'construction — a band\'s values win on cascade position, never on weight — '
            . 'so an "!important" here would be unbeatable by the component\'s own defaults '
            . 'and by your own later writes. Remove it; the declaration already wins.');
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
function pp_udc_validate_map($udc, string $component, array $item_maps = []): ?WP_Error {
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
            // AN ORPHANED ITEM MINT CANNOT COLLIDE, SO IT IS NOT SQUATTING (#1101).
            //
            // This gate exists for one reason, and its own message says it: "a collision
            // would have the engine overwrite the value you declared". An ITEM-shaped
            // name whose id names no entry in this band has no coordinate left to
            // collide with — the engine will never mint it again, because no card
            // carries that id.
            //
            // WITHOUT THIS CARVE-OUT YOU COULD NOT DELETE A CARD YOU STYLED. Minting
            // lifts an item's responsive literals into the BAND's `_tokens`; deleting the
            // card removes the map but not the token, and the next write was refused
            // permanently — on a name the author never typed, by a message telling them
            // to "pick another name" for it, from a surface (`update_component`) that
            // then carried no `udc` param (it has one since #1088) and so could not reach
            // `_tokens` at all. The
            // documented clear-it route, an explicit `{"udc": {}}`, hit the same wall.
            //
            // THE SQUAT CASE IS STILL REFUSED, and the sequence that looks like a hole
            // is closed by re-evaluation rather than by this gate: an author may now
            // store `it-deadbeef-…` while no card carries that id, but the moment a card
            // DOES, this gate runs again with the id live and refuses unless the name is
            // genuinely the engine's own. And if it somehow got past, `_pp_udc_mint_value()`'s
            // collision guard is the backstop it has always been — a coordinate whose
            // name is already held by a different literal is left unminted, so both
            // values survive and both paint.
            //
            // The orphan is not silent either: it is exactly what `udc_unused_band_token`
            // reports, which is the right channel for debris — a warning, not a wall.
            // pp_udc_normalize_band() reaps these on the next write, so they do not
            // accumulate; this carve-out is what lets that write happen at all.
            $orphan_item_mint = false;
            $item_mint_split  = _pp_udc_split_item_mint((string) $name);
            if ($item_mint_split !== null && !array_key_exists($item_mint_split[0], $item_maps)) {
                $orphan_item_mint = true;
            }
            if (!$orphan_item_mint
                && _pp_udc_is_mint_shaped_name((string) $name)
                && !_pp_udc_name_is_the_engines_own_mint((string) $name, $udc, $item_maps)) {
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
            // `_css` AT THE TOP LEVEL IS THE ONE WRONG PLACE WORTH NAMING (#1079).
            //
            // It is the mistake the shape invites — raw declarations feel band-wide, and
            // `_tokens` really does sit at this level — and the generic message answers a
            // question the author did not ask ("no role called _css; available roles are
            // …"), sending them to look for a role rather than to move a key one level in.
            // I24 asks for a stated reason AND a route back; this is the route back.
            if ((string) $role_name === PP_UDC_CSS_KEY) {
                return new WP_Error('unknown_udc_role', sprintf(
                    'Component "%s": "%s" is not a role — it sits INSIDE one, beside that role\'s '
                    . 'groups. For the band itself write {"_band": {"%s": {…}}}; for a part of the '
                    . 'component write {"<role>": {"%s": {…}}}. Available roles: %s',
                    $component,
                    PP_UDC_CSS_KEY,
                    PP_UDC_CSS_KEY,
                    PP_UDC_CSS_KEY,
                    implode(', ', array_keys($roles)) ?: '(none)'
                ));
            }
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
                    $component, $role_name, $group_map, $band_tokens, '', $udc
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
 * Validates ONE `items[]` entry's `udc` map (Addendum B1, B6).
 *
 * NOT A SECOND ENGINE, and the delegation below is the proof rather than the
 * claim. Everything that decides whether a VALUE is acceptable —
 * `_pp_udc_validate_preset_reference()` for a preset, `_pp_udc_validate_group_map()`
 * for a group — is the same function the band gate calls, reached with the same
 * arguments. B1 promises "the same engine, the same grammar, the same group
 * taxonomy, and the same refusal codes"; what this function adds is only the
 * two things that are genuinely different at item grain, and nothing else:
 *
 *   1. THE RESERVED KEYS ARE REFUSED RATHER THAN SKIPPED. At band grain
 *      `_band`, `_tokens` and `_css` are legal members that the role loop steps
 *      over. At item grain each is an excluded capability (B6 exclusions 2 and
 *      7, plus the `_tokens` boundary that falls out of B4), and an excluded
 *      capability that is silently stepped over is the accepted-stored-ignored
 *      shape I35 forbids. Each refusal carries its own reason, from
 *      pp_udc_item_reserved_keys(), because "not a role" would send an author
 *      looking for a typo when the answer is "that is not available here".
 *
 *   2. THE ROLE SET IS THE DECLARED ONE. B5 says the component names which of
 *      its roles are item-addressable; a role the component declares but does
 *      NOT list here is refused, and the message distinguishes that case from a
 *      role that does not exist at all — the two have different fixes.
 *
 * TOKENS ARE THE BAND'S, passed in rather than parsed out. An item may
 * REFERENCE any band token; it may not define one (see the `_tokens` refusal).
 * Handing the band's map down is what makes `@name` resolve identically on
 * both tiers, which is the property that keeps a minted item value readable.
 *
 * @param mixed  $udc         The entry's map.
 * @param string $component   The band's component.
 * @param array  $declaration pp_udc_item_roles() output for that component.
 * @param array  $band_tokens The band's own `_tokens`, name => literal.
 * @param string $where       A locator naming the entry, e.g. `items[1]`.
 */
function pp_udc_validate_item_map(
    $udc,
    string $component,
    array $declaration,
    array $band_tokens,
    string $where
): ?WP_Error {
    if (!is_array($udc)) {
        return new WP_Error('invalid_prop_value', sprintf(
            'Component "%s" %s "%s" must be an object of roles; got %s.',
            $component,
            $where,
            PP_UDC_ITEM_MAP_KEY,
            _pp_schema_value_for_message($udc)
        ));
    }

    $roles    = pp_udc_component_roles($component);
    $reserved = pp_udc_item_reserved_keys();
    $allowed  = $declaration['roles'];

    foreach ($udc as $role_name => $role_map) {
        $role_name = (string) $role_name;

        if (isset($reserved[$role_name])) {
            return new WP_Error('unknown_udc_role', sprintf(
                'Component "%s" %s: "%s" is not available on a single item — %s.',
                $component,
                $where,
                $role_name,
                $reserved[$role_name]
            ));
        }

        if (!in_array($role_name, $allowed, true)) {
            // TWO DIFFERENT MISTAKES, TWO DIFFERENT SENTENCES. A role that does
            // not exist is a typo; a role that exists but is not item-addressable
            // is a real role the author reached for at the wrong grain, and the
            // fix is to set it on the band instead. One message for both would
            // send half the authors to the wrong repair.
            if (isset($roles[$role_name])) {
                return new WP_Error('unknown_udc_role', sprintf(
                    'Component "%s" %s: role "%s" exists but is not settable per item — set it on the '
                    . 'band\'s own "%s" map, where it applies to every item. Item-settable roles: %s',
                    $component,
                    $where,
                    $role_name,
                    PP_UDC_ITEM_MAP_KEY,
                    implode(', ', $allowed)
                ));
            }
            return new WP_Error('unknown_udc_role', sprintf(
                'Component "%s" %s has no UDC role %s. Item-settable roles: %s',
                $component,
                $where,
                _pp_render_undeclared_prop_keys([$role_name]),
                implode(', ', $allowed)
            ));
        }

        if (!is_array($role_map)) {
            return new WP_Error('invalid_prop_value', sprintf(
                'Component "%s" %s role "%s" must be an object of groups; got %s.',
                $component,
                $where,
                $role_name,
                _pp_schema_value_for_message($role_map)
            ));
        }

        $permitted = $roles[$role_name]['groups'] ?? [];

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
                continue;
            }
            // `_css` is caught by the reserved-key gate at ROLE level above only
            // when an author writes it as a role. Written as a GROUP inside a
            // permitted role it arrives here, and exclusion 7 refuses it in the
            // same words rather than letting the group validator call it an
            // unknown group — the author asked for a capability the contract
            // withholds, which is a different answer from "no such group".
            if ((string) $group_name === PP_UDC_CSS_KEY) {
                return new WP_Error('unknown_udc_group', sprintf(
                    'Component "%s" %s role "%s": "%s" is not available on a single item — %s.',
                    $component,
                    $where,
                    $role_name,
                    PP_UDC_CSS_KEY,
                    $reserved[PP_UDC_CSS_KEY]
                ));
            }
            // THE ITEM LOCATOR RIDES THE `$subject` PARAMETER, which already exists for
            // exactly this reason (#1016 added it so a PRESET could stop claiming a
            // component and a role it was never authored against).
            //
            // Without it the delegated group validator opened every message with its
            // default `Component "%s" role "%s"` — so a bad token inside ONE card
            // produced a message BYTE-IDENTICAL to the same mistake on the band:
            //
            //   Component "grid" role "card" group "background" parameter "fill"
            //   references "@nope", which is not a registered design token.
            //
            // Measured on a three-card band: an operator could not tell whether the
            // problem was the band's map or a card's, let alone WHICH card. B4 requires
            // findings to carry the item locator beside the band index, and the arms
            // this function writes ITSELF already did — only the delegated ones dropped
            // it, which is the worst half to lose because it is the common case.
            $error = _pp_udc_validate_group_map(
                $component,
                $role_name,
                (string) $group_name,
                $group_map,
                $permitted,
                $band_tokens,
                '',
                true,
                sprintf('Component "%s" %s role "%s"', $component, $where, $role_name)
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
        // `_css` IS IN THE LISTING ON THE BAND AND CHROME PATHS (contract §2.7, C1).
        //
        // It is not a registry group, so `array_keys($groups)` omits it — and an author
        // who types `css`, `_cs` or `_CSS` was told the correct key does not exist. The
        // contract called this out as a required change and the pin never shipped; found
        // by the pre-landing maintainability pass.
        //
        // NOT on the preset path, where `$allow_preset` is false: a preset genuinely
        // cannot carry `_css` (§6.13a), so listing it there would advertise a key that is
        // refused two lines later.
        return new WP_Error('unknown_udc_group', sprintf(
            '%s%s names the UDC group %s, which does not exist. Available groups: %s',
            $who,
            $origin,
            _pp_render_undeclared_prop_keys([$group_name]),
            implode(', ', $allow_preset
                ? array_merge(array_keys($groups), [PP_UDC_CSS_KEY])
                : array_keys($groups))
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
    string $state,
    array $udc = []
): ?WP_Error {
    // THE LOCATOR IS BUILT FOR THE MESSAGE IT ENDS UP INSIDE. _pp_udc_validate_param()
    // appends `group "<g>" parameter "<p>"` to whatever subject it is handed, so passing
    // a subject that already said `_css` produced `role "answer" "_css" group "_css"
    // parameter "opacity"` — the key named twice and the word "group" applied to
    // something that is not one. This file's own refusals are the locator an operator
    // reads, so the subject stays plain here and the `_css`-shaped refusals below say it
    // once, themselves.
    $who = sprintf('Component "%s" role "%s"', $component, $role);
    // THE STATE BELONGS IN THE LOCATOR, and this string is built once per recursion level
    // so it has to carry it here. Without it every refusal raised INSIDE a state pointed at
    // `_css` rather than at `_css ":hover"`, and the docblock above claims these refusals
    // are the locator an operator reads. The sibling group validator carries the state in
    // the same position.
    $mine = sprintf('%s "%s"%s', $who, PP_UDC_CSS_KEY, $state !== '' ? ' ' . $state : '');

    if (!is_array($css_map)) {
        return new WP_Error('invalid_prop_value', sprintf(
            '%s must be an object of CSS property => value; got %s.',
            $mine,
            _pp_schema_value_for_message($css_map)
        ));
    }

    $states = pp_udc_states();

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
            $error = _pp_udc_validate_css_map($component, $role, $value, $band_tokens, $key, $udc);
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

        $exclusion = _pp_udc_css_exclusion_reason($key);
        if ($exclusion !== null) {
            return new WP_Error('unknown_udc_css_property', sprintf(
                '%s property "%s" is not available: %s.',
                $mine,
                $key,
                $exclusion
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
                // THE ENGINE'S OWN MINT IS NOT AN AUTHOR'S REFERENCE, and missing this
                // turned R1′.3 into a permanent false refusal on its own output.
                //
                // A responsive value is rewritten at write-time normalization into
                // `@<role>-_css-<property>-<bp>` — so the moment an author wrote
                // `{"opacity": {"d": "1", "p": "0.6"}}` on an UNTYPED property, the engine
                // minted a reference and then refused it, on every later read: the
                // post-write envelope, `wp pp check page`, and restore all re-validate
                // STORED compositions. Caught by the regression pin, not by reading.
                //
                // The refusal's own reason is what shows the carve-out is right rather than
                // convenient: R1′.3 exists because the engine cannot check that a TOKEN's
                // value fits a property it has no grammar for. An engine mint is not a
                // token someone else defined — it holds the author's own literal, for this
                // exact property and breakpoint, already validated as that literal on the
                // way in. There is nothing left to check.
                if ($ref !== null && _pp_udc_name_is_the_engines_own_mint($ref, $udc)) {
                    continue;
                }
                if ($ref !== null) {
                    // BOUNDED AND CLEANED AT THE SINK (I37). `pp_udc_parse_reference()`
                    // applies NO charset — it returns everything after the `@` — so this
                    // name is arbitrary author bytes of arbitrary length. Probed: a
                    // 227-byte hostile name produced a 481-byte refusal carrying it
                    // verbatim. Every other value this engine reflects goes through this
                    // sink; a refusal is no exception just because it is a refusal.
                    return new WP_Error('invalid_prop_value', sprintf(
                        '%s property "%s" cannot take the reference "@%s". This property has no '
                        . 'declared grammar in the design vocabulary, so the engine cannot check '
                        . 'that a token\'s value is usable here. Write the literal value instead.',
                        $mine,
                        $key,
                        _pp_udc_reflect($ref)
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
    // One sprintf, two vocabularies: the only difference between the arms was the
    // two label words, and a duplicated format string is where a later edit
    // changes one arm and not the other (#1079's approved cleanup).
    $where = sprintf(
        '%s %s%s%s %s "%s"',
        $who,
        $group === PP_UDC_CSS_KEY ? '"' . PP_UDC_CSS_KEY . '"' : 'group "' . $group . '"',
        $origin,
        $state !== '' ? ' ' . $state : '',
        $group === PP_UDC_CSS_KEY ? 'property' : 'parameter',
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
 *
 * @param string $item The minting item's id, or '' for a band-grain value.
 *                     Addendum B4 widens the name to
 *                     `<item>-<role>-<group>-<param>[-<state>]-<bp>` so two
 *                     items styling the same role at the same breakpoint
 *                     cannot collide on one token name.
 */
function pp_udc_mint_name(string $role, string $group, string $param, string $state, string $bp, string $item = ''): string {
    $states  = pp_udc_states();
    $segment = ($state !== '' && isset($states[$state])) ? '-' . $states[$state]['mint'] : '';
    // The item segment is a MINTED id, never author text — pp_udc_valid_item_id()
    // bounds it to `it-` plus eight hex digits, so it can introduce no character
    // the name grammar does not already admit and no second reading of the
    // segments after it.
    $prefix = $item !== '' ? $item . '-' : '';
    return $prefix . $role . '-' . $group . '-' . $param . $segment . '-' . $bp;
}

/**
 * Splits an item-minted token name into its item id and the rest, or null.
 *
 * ONE READING, GUARANTEED, which is the property the whole widening rests on.
 * A mint name joins its segments with `-` and escapes nothing, so a new leading
 * segment is only safe if it cannot be confused with the segments after it.
 * `it-<hex8>` is fixed-width and charset-bounded, so the split is positional
 * rather than a search: eleven characters, then a hyphen, then the band-grain
 * name the classifier already knows how to read. There is no second way to
 * read it, so this cannot open the double-decode hazard _pp_udc_mint_readings()
 * exists to close one level down.
 *
 * NOT preg_quote()-BASED, AND NOT ACCIDENTALLY SO: lib/udc.php may not contain
 * the substring this file's own engine-neutrality invariant forbids, which
 * rules that helper out. A positional read is cheaper anyway.
 *
 * @return array{0: string, 1: string}|null  [item id, remaining name]
 */
function _pp_udc_split_item_mint(string $name): ?array {
    if (strlen($name) < 13 || strncmp($name, 'it-', 3) !== 0 || $name[11] !== '-') {
        return null;
    }
    $id = substr($name, 0, 11);
    if (!pp_udc_valid_item_id($id)) {
        return null;
    }
    return [$id, substr($name, 12)];
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
    $component = isset($item['component']) && is_scalar($item['component'])
        ? (string) $item['component']
        : '';
    // A BAND WITH NO MAP OF ITS OWN STILL HAS ITEMS TO NORMALIZE. This used to
    // return early on an absent `udc`, which was correct while normalization
    // had one subject; with the item tier it would skip every responsive value
    // on every card of a band whose own map happens to be empty — an entirely
    // ordinary shape, since the owner's live design styles cards and leaves the
    // band alone. The early exit now asks whether there is ANY subject.
    $has_band_map = isset($item['udc']) && is_array($item['udc']);
    if (!$has_band_map && ($component === '' || pp_udc_item_roles($component) === null)) {
        return $item;
    }
    $udc    = $has_band_map ? $item['udc'] : [];
    $tokens = isset($udc['_tokens']) && is_array($udc['_tokens']) ? $udc['_tokens'] : [];
    // HOISTED, because the item pass below needs it too and this loop may not
    // run at all. Left inside the loop it was a function-scoped local that
    // happened to exist only when the band declared at least one role.
    $states = pp_udc_states();

    foreach ($udc as $role => $role_map) {
        if (in_array($role, pp_udc_reserved_keys(), true) || !is_array($role_map)) {
            continue;
        }
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

    // ── ITEM VALUES MINT INTO THE BAND'S TOKENS (Addendum B4) ───────────────
    //
    // INTO THE BAND'S MAP, NOT THE ITEM'S, and that is forced rather than
    // chosen: a minted literal is emitted as a custom property declaration on
    // the BAND ROOT (`--pp-<name>:<value>` in _pp_udc_render_blocks()), and the
    // band root is the only element the band and its items share. An item has
    // no element of its own to hang a token declaration on, which is why
    // pp_udc_item_reserved_keys() refuses `_tokens` inside an item map.
    //
    // THE NAME CARRIES THE ITEM ID so two items styling the same role at the
    // same breakpoint cannot collide on one token name — B4 says so, and
    // without it the collision guard in _pp_udc_mint_value() would silently
    // leave the second item's value unminted, which paints correctly but for a
    // reason no one could explain.
    //
    // MINTING IS STILL A CONVENIENCE, NOT A REQUIREMENT. Everything the band
    // tier's minting docblock says holds here: a literal breakpoint map emits
    // perfectly well unminted, so a coordinate whose name is already taken is
    // left alone rather than renamed or refused.
    $component_declaration = $component === '' ? null : pp_udc_item_roles($component);
    if ($component_declaration !== null) {
        $prop    = $component_declaration['prop'];
        $entries = $item['props'][$prop] ?? null;
        if (is_array($entries)) {
            foreach ($entries as $k => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $item_id = isset($entry[PP_UDC_ITEM_ID_KEY]) && is_scalar($entry[PP_UDC_ITEM_ID_KEY])
                    ? (string) $entry[PP_UDC_ITEM_ID_KEY]
                    : '';
                $item_map = $entry[PP_UDC_ITEM_MAP_KEY] ?? null;
                // NO ID MEANS NO MINT, not a mint under a blank prefix. An
                // un-minted entry emits nothing at all (it has no selector), so
                // lifting its literals into band tokens would strand them: named
                // after nothing, referenced by nothing, and reported unused.
                if ($item_id === '' || !pp_udc_valid_item_id($item_id) || !is_array($item_map)) {
                    continue;
                }
                foreach ($item_map as $role => $role_map) {
                    if (!is_array($role_map)) {
                        continue;
                    }
                    foreach ($role_map as $group => $group_map) {
                        if (!is_array($group_map)) {
                            continue;
                        }
                        foreach ($group_map as $param => $value) {
                            if (isset($states[$param]) && is_array($value)) {
                                foreach ($value as $state_param => $state_value) {
                                    $minted = _pp_udc_mint_value(
                                        $state_value, (string) $role, (string) $group,
                                        (string) $state_param, (string) $param, $tokens, $item_id
                                    );
                                    if ($minted !== null) {
                                        $item['props'][$prop][$k][PP_UDC_ITEM_MAP_KEY][$role][$group][$param][$state_param] = $minted;
                                    }
                                }
                                continue;
                            }
                            $minted = _pp_udc_mint_value(
                                $value, (string) $role, (string) $group, (string) $param,
                                '', $tokens, $item_id
                            );
                            if ($minted !== null) {
                                $item['props'][$prop][$k][PP_UDC_ITEM_MAP_KEY][$role][$group][$param] = $minted;
                            }
                        }
                    }
                }
            }
        }
    }

    // ── REAP THE MINTS OF CARDS THAT ARE GONE (#1101) ──────────────────────
    //
    // WITHOUT THIS YOU CANNOT DELETE A CARD YOU STYLED. Measured through the real
    // action surface before the fix: style one card with a responsive value (the band
    // gains `it-<hex8>-card-title-typography-size-d`), then re-send `items` without
    // that card —
    //
    //   ok: false, invalid_prop_value: udc token "it-387bb4bc-…-d" uses a name the
    //   engine mints for itself … Pick another name
    //
    // The band is refused permanently, on a token the author never wrote, by a message
    // naming a repair they could not perform — `update_component` carried no `udc` param
    // before #1088, so the `_tokens` map was unreachable from the surface that refused them.
    // The documented escape hatch is refused identically: `_pp_preserve_item_design()`
    // promises that an explicit `{"udc": {}}` clears a design "because there is
    // otherwise no way to remove an item's design once minted", and it hit the same
    // wall. Reaching the state from storage (restore_composition #233, a raw meta
    // write) makes the error permanent on an otherwise-clean page.
    //
    // SAFE BY CONSTRUCTION, and that is the whole argument for reaping rather than
    // relaxing the gate. An item mint is named after the id of the ONE map that could
    // have produced it, so a name whose id names no surviving entry cannot be anything
    // an author wrote — the write gate refuses an authored `_tokens` key of that shape,
    // which is what the gate this unblocks is for. Removing it destroys no author data.
    //
    // ITEM MINTS ONLY. The band-grain twin has the same shape and predates this tier;
    // it is reachable only through a read-modify-write of a band's own `udc`, and
    // widening this to band mints would mean deciding what "orphaned" means for a name
    // whose coordinate still exists. Left alone deliberately.
    if ($component_declaration !== null && $tokens !== []) {
        $live_item_ids = [];
        foreach (pp_udc_item_maps($item) as $live_id => $ignored_map) {
            $live_item_ids[(string) $live_id] = true;
        }
        foreach ($tokens as $token_name => $ignored_literal) {
            $split = _pp_udc_split_item_mint((string) $token_name);
            if ($split !== null && !isset($live_item_ids[$split[0]])) {
                unset($tokens[$token_name]);
            }
        }
    }

    if ($tokens !== []) {
        $udc['_tokens'] = $tokens;
    } else {
        // The last mint went with the last styled card: drop the empty carrier rather
        // than storing `{"_tokens": {}}`, which no reader wants and which would make the
        // no-coercion promise visibly false on a round trip.
        unset($udc['_tokens']);
    }
    // A BAND THAT HAD NO MAP AND MINTED NOTHING KEEPS HAVING NO MAP. Writing
    // back an empty `udc` would change the stored shape of every item-styled
    // band for no reader, and would make the no-coercion promise visibly false
    // on a round trip.
    if ($has_band_map || $udc !== []) {
        $item['udc'] = $udc;
    }
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
    array &$tokens,
    string $item = ''
): ?array {
    if (!is_array($value) || $value === []) {
        return null; // Not responsive — nothing to normalize.
    }
    $breakpoints = pp_udc_breakpoints();
    $rewritten   = [];
    $changed     = false;
    // STAGED, NOT WRITTEN — because the bail below is mid-loop and `$tokens` is by
    // reference (#1079).
    //
    // The collision guard returns null from inside the breakpoint loop, so any breakpoint
    // that already minted had ALREADY written into the caller's `$tokens`. The caller then
    // discards `$rewritten` and keeps the literal map, and the half-written tokens stay
    // behind referenced by nothing.
    //
    // Measured on one accepted write, `{"x-hover": {"p": …}, ":hover": {"x": {"d": …, "p": …}}}`:
    // `answer-_css-x-hover-d` was minted, appeared in no declaration, and — the part that
    // matters — made the STORED band fail `pp_udc_validate_map()` with "uses a name the
    // engine mints for itself". An accepted write produced a composition that errors on
    // every post-write envelope, `wp pp check page` and restore, for the same reason the
    // decoder regression above exists: validation runs over stored output, and the output
    // was the engine's own. All-or-nothing is the only shape that cannot half-apply.
    $staged = [];

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
        $name           = pp_udc_mint_name($role, $group, $param, $state, (string) $bp, $item);
        // A MINT NAME MUST NOT LAND ON A NAME ALREADY HOLDING A DIFFERENT VALUE.
        //
        // Mint names join their segments with `-` and escape nothing, so two distinct
        // coordinates can produce one name as soon as a segment can itself contain `-`.
        // That was unreachable while every segment came from the registry: no declared
        // parameter ends in `-hover`, `-active` or `-focus-visible`. Layer 2 makes the
        // parameter segment AUTHOR-CHOSEN, and an unknown property name is the advertised
        // capability — so `{"x-hover": {…}, ":hover": {"x": {…}}}` mints
        // `<role>-_css-x-hover-<bp>` TWICE, and the second write destroyed the first.
        //
        // Measured before this guard, on one ordinary accepted write: the author's first
        // value existed nowhere in storage afterwards, the base declaration painted the
        // hover value, the stored form re-validated clean, and the envelope reported
        // `udc_token_minted` naming only the survivor while `udc_css_unchecked_property`
        // said the property "is emitted exactly as written". Silent destruction of author
        // data with two disclosures saying otherwise — the I35 class the disclosure layer
        // exists to prevent. Found by the adversarial pass.
        //
        // THE COLLIDING COORDINATE IS LEFT UNMINTED RATHER THAN REFUSED OR RENAMED.
        // Minting is a normalization convenience, never a requirement: a literal
        // breakpoint map emits perfectly well without it (that is exactly what this
        // function produces when a value is not responsive). So the second coordinate
        // keeps its literals, both values survive, and both paint. Renaming was rejected
        // because mint names are deterministic on purpose — a generated suffix would make
        // pp_composition_content_hash() see a different value on every write, which is the
        // defect that put the props.id strip in the hash. Refusing was rejected because
        // nothing is wrong with what the author wrote; only the engine's own shortcut
        // cannot be applied to both.
        $held = $staged[$name] ?? ($tokens[$name] ?? null);
        if ($held !== null && (string) $held !== $literal) {
            return null;
        }
        $staged[$name]  = $literal;
        $rewritten[$bp] = '@' . $name;
        $changed        = true;
    }
    if (!$changed) {
        return null;
    }
    // Commit only now, once the whole value is known to have minted.
    foreach ($staged as $name => $literal) {
        $tokens[$name] = $literal;
    }
    return $rewritten;
}

/**
 * Normalizes every band in a composition. Called from the write path so an
 * author's literals are lifted exactly once, at the moment they are stored.
 */
function pp_udc_normalize_composition(array $items): array {
    foreach ($items as $i => $item) {
        // The `isset($item['udc'])` fence this used to carry is gone for the
        // reason pp_udc_normalize_band()'s own early exit changed: a band with
        // no map of its own can still carry items that need minting, and that
        // is the ordinary shape of an item-styled band rather than an edge
        // case. The callee decides whether it has a subject.
        if (is_array($item)) {
            $items[$i] = pp_udc_normalize_band($item);
        }
    }
    return $items;
}

// ── Compilation: the cascade, with provenance ───────────────────────────────

/**
 * May this role selector be emitted into a stylesheet at all?
 *
 * ONE GATE, TWO TIERS. The band tier and the item tier emit the SAME role
 * selectors under different scopes, so a check that lived in only one of them
 * would leave the other emitting what this file has already decided is unsafe.
 * The reasoning below was written for the band loop and is unchanged by the
 * move; what changed is that there is now exactly one copy of it.
 *
 * Returns false for a selector the emitter must skip. The caller decides what
 * skipping means — the band loop continues to the next role, and neither
 * caller ledgers the skip, for the reason the charset gate states below.
 */
function _pp_udc_selector_is_emittable(string $selector): bool {
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
    //
    if ($selector !== '' && !_pp_udc_delimiters_balanced($selector)) {
        return false;
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
            return false;
        }
    }
    if ($selector !== '' && !preg_match('/^[A-Za-z0-9_ .>\[\]\-]{1,120}\z/', $selector)) {
        // DELIBERATELY NOT LEDGERED. A role selector comes only from a
        // repo-owned, integrity-checked component schema, never from an author
        // — the same reason a role DEFAULT's discard is filtered out of the
        // ledger. Surfacing it would hand the operator a configuration-class
        // finding whose next_action ("re-set that value") is unactionable for a
        // theme bug they cannot reach.
        return false;
    }

    return true;
}

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
 *
 * @param array      $item   One composition item, or a chrome entry shaped like one.
 * @param string     $layer  'defaults' | 'authored' — which tier to compile.
 * @param array|null $drops  Pass an array to collect what this compile DISCARDED.
 *                           Filled with ['where' => string, 'reason' => string]
 *                           entries, bounded at PP_UDC_MAX_EMIT_DROPS, both fields
 *                           already cleaned for reflection. A row MAY also carry a
 *                           `code`: the dropped-overlay row carries
 *                           'overlay_without_image', and pp_udc_composition_findings()
 *                           selects exactly those rows by it (#1117). Left untouched
 *                           at null, which is what every render path passes.
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
        // A band styled only at ITEM grain is styled too (#1117): its cards' designs are
        // lost to the same missing id. Only asked when a ledger is being kept, so the
        // render path still never touches the registry here.
        if ($drops !== null && count($drops) < PP_UDC_MAX_EMIT_DROPS
            && ((isset($item['udc']) && is_array($item['udc']) && $item['udc'] !== [])
                || pp_udc_item_maps($item) !== [])) {
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
        // THE THREE GATES THIS USED TO SPELL INLINE now live in
        // _pp_udc_selector_is_emittable(), because the item tier applies exactly the
        // same three to exactly the same selectors. Two copies of a security gate is
        // one copy that gets fixed.
        if (!_pp_udc_selector_is_emittable($selector)) {
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
                // MEASURED COST OF THIS BRANCH AND THE REORDER ABOVE, on a page that uses
                // NO `_css` at all — the overwhelmingly common page, and the one that must
                // not pay for a feature it does not use. A realistic 50-band fixture went
                // 11.69 → 12.04 ms (+0.34 ms, +2.9%), CSS byte-identical, across 8
                // interleaved process pairs with a duplicate-of-before control measuring a
                // 0.04 ms noise floor. Attributed by ablation: this group arm 0.22 ms
                // (evaluated once per role per source per group), the reorder 0.11 ms. The
                // `!important` gate added to pp_udc_validate_value() costs nothing
                // measurable — its `!empty($param['untyped'])` short-circuits first.
                //
                // ACCEPTED RATHER THAN OPTIMISED. The lever, if it is ever wanted: compute
                // `array_key_exists(PP_UDC_CSS_KEY, $declared)` once per role before the
                // `$sources` loop and skip both checks when it is false. Left alone because
                // half a millisecond does not justify another conditional across the
                // hottest loop in the engine, and because the number is recorded here so
                // the next reader can disagree with evidence rather than re-measure.
                //
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
                        &$resolved, &$referenced, &$drops, $source, $css_tokens, $breakpoints, $css_where, $udc
                    ): void {
                        if (!is_array($map)) {
                            return;
                        }
                        foreach ($map as $property => $value) {
                            $property = (string) $property;
                            if (!pp_udc_css_property_admissible($property)) {
                                if ($drops !== null && $source !== 'defaults'
                                    && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                                    $drops[] = [
                                        'where'  => $css_where . ' ' . _pp_udc_reflect($property),
                                        'reason' => 'it is not an available CSS property here',
                                    ];
                                }
                                continue;
                            }
                            $param = pp_udc_css_param($property);
                            // R1′.3 IS DOUBLED HERE, because every other emit-time rule in
                            // this file is. The write gate refuses an `@reference` on a
                            // property the vocabulary cannot type — there is no grammar to
                            // judge the token's value against — but the emitter reaches
                            // stored data the write gate never saw (a raw meta write, a
                            // composition written before this layer, restore_composition
                            // #233). Without this the one class of value R1′.3 calls
                            // uncheckable was refused at write and PAINTED from storage,
                            // with no ledger entry: the two gates disagreeing, which is the
                            // convergence the #570 rule forbids. Found by the pre-landing
                            // security pass.
                            if (!empty($param['untyped'])) {
                                $unresolvable = false;
                                foreach (is_array($value) ? $value : [$value] as $leaf) {
                                    if (!is_scalar($leaf)) {
                                        continue;
                                    }
                                    $leaf_ref = pp_udc_parse_reference((string) $leaf);
                                    // THE ENGINE'S OWN MINT IS NOT AN AUTHOR'S REFERENCE — the
                                    // same carve-out the write gate carries, and leaving it out
                                    // here broke the feature's ordinary documented case.
                                    //
                                    // A responsive value on an untyped property is REWRITTEN at
                                    // write-time normalization into `@<role>-_css-<property>-<bp>`.
                                    // So `{"_css": {"opacity": {"d": "1", "p": "0.6"}}}` minted,
                                    // validated, and then emitted NOTHING. Caught by the
                                    // adversarial pass; my own regression test had checked that
                                    // the minted map VALIDATES and never that it PAINTS.
                                    //
                                    // This guard was added one round earlier to close the
                                    // opposite divergence (refused at write, painted from
                                    // storage) — and opened this one in the same stroke.
                                    if ($leaf_ref !== null
                                        && !_pp_udc_name_is_the_engines_own_mint($leaf_ref, $udc)) {
                                        $unresolvable = true;
                                        break;
                                    }
                                }
                                if ($unresolvable) {
                                    if ($drops !== null && $source !== 'defaults'
                                        && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                                        $drops[] = [
                                            'where'  => $css_where . ' ' . _pp_udc_reflect($property),
                                            'reason' => 'a raw declaration on a property the design vocabulary '
                                                . 'does not know cannot take an @reference',
                                        ];
                                    }
                                    continue;
                                }
                            }
                            _pp_udc_place(
                                $resolved, $st, [$property => $param], $property,
                                $value, $source, $css_tokens, $breakpoints, $referenced, $drops, $css_where, true
                            );
                        }
                    };
                    foreach ($group_map as $key => $value) {
                        if (isset($states[(string) $key])) {
                            // A KNOWN STATE KEY IS A STATE, WHATEVER ITS VALUE. Gating on
                            // `is_array($value)` too meant `{":hover": "red"}` fell through
                            // to the property branch and was ledgered as "not an available
                            // CSS property" — while the write gate calls the same bytes a
                            // state whose value must be an object. Two names for one defect
                            // sends an operator reading a restore report to the wrong
                            // question. Found by the adversarial pass.
                            if (is_array($value)) {
                                $css_place((string) $key, $value);
                            } elseif ($drops !== null && $source !== 'defaults'
                                && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                                $drops[] = [
                                    'where'  => $css_where . ' ' . _pp_udc_reflect((string) $key),
                                    'reason' => 'a state must hold a map of properties',
                                ];
                            }
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
                // THE ROLE'S OWN ROSTER, ENFORCED AT EMIT AS WELL AS AT WRITE
                // (#1084, found by the pre-landing security pass with a probe).
                //
                // This arm checked that the GROUP exists in the registry and never
                // that THIS ROLE permits it — `$permitted` was computed above and
                // applied to presets alone. So stored bytes the write gate refuses
                // painted anyway: `{"menu": {"layout": {"columns": "2"}}}` on nav is
                // refused at write ("role menu does not permit the UDC group
                // layout") and, before this, emitted an unlayered `display: grid` on
                // `.nav__menu` — which outranks the UA stylesheet's `[hidden]` rule
                // and PINS AN OPEN MOBILE MENU OPEN. Probed on the branch: emitted,
                // with an EMPTY drop ledger, so no channel said a word.
                //
                // The write gate is not the only way data arrives here, and this
                // file says so a few hundred lines up: a raw meta write, a
                // composition written before a rule existed, and
                // restore_composition (which reports findings without blocking,
                // #233) all reach this line directly. A gate that runs only at write
                // is a gate the emitter disagrees with, which is the I29
                // write/render disagreement the engine exists to prevent.
                //
                // GENERAL, NOT LAYOUT-SHAPED. The hazard was found through `layout`
                // because that group is the first whose value can defeat an
                // accessibility affordance, but the hole was never layout's: every
                // group had it. Gating the group here is the same predicate presets
                // already take (_pp_udc_split_preset_by_permitted), applied to the
                // band's own map, so the two tiers stop disagreeing.
                //
                // ZERO IMPACT ON SHIPPED DATA, verified rather than assumed: no role
                // in any shipped schema defaults a group its own `groups` list omits,
                // and every write that passed the gate satisfies this by definition.
                // What changes is stored data the gate would refuse — which now
                // reports instead of painting.
                if (!in_array((string) $group_name, $permitted, true)) {
                    if ($drops !== null && $source !== 'defaults'
                        && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                        $drops[] = [
                            'where'  => sprintf(
                                'role "%s" group "%s"',
                                _pp_udc_reflect((string) $role_name),
                                _pp_udc_reflect((string) $group_name)
                            ),
                            'reason' => 'this role does not permit that group, so the write gate '
                                . 'refuses it and the page does not paint it',
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
            // A RAW BACKGROUND WINS ITS COORDINATE FIRST (#1141), before a narrower tier borrows an image the
            // raw shorthand has cancelled.
            $by_bp = _pp_udc_raw_background_wins($by_bp);
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
                    // NOT INTO A BUCKET WHOSE `background` IS RAW (#1141, PR-2 review): the raw shorthand has
                    // won that coordinate, and a borrowed image would paint over it; the scrim there is then
                    // dropped by the compose stage with its ledger row.
                    if ($bp !== 'd'
                        && isset($declarations[PP_UDC_BACKGROUND_OVERLAY_CARRIER])
                        && !isset($declarations['background-image'])
                        && empty($declarations['background']['raw'])) {
                        $by_bp[$bp]['background-image'] = $base_image;
                    }
                }
            }
            // WHETHER THE BAND STAYS MARKED, READ FROM THE COMPOSE INPUTS (PR-2 review cycle 2, design). A scrim a raw
            // background dropped says the band goes unmarked only when no width paints a scrim; otherwise it names
            // the widths that still do. Rest state of `_band` only: the marker reads nothing else.
            if ((string) $role_name === '_band' && (string) $state === '') {
                $composes = static fn (array $d): bool => is_array($d[PP_UDC_BACKGROUND_OVERLAY_CARRIER] ?? null)
                    && empty($d[PP_UDC_BACKGROUND_OVERLAY_CARRIER]['raw_background_won']) && isset($d['background-image']);
                $scrim_at = [];
                foreach (array_keys(pp_udc_breakpoints()) as $bp) {
                    $bucket = $by_bp[$bp] ?? [];
                    // A width with no carrier and no background of its own inherits the desktop layers (the cascade).
                    $paints = ($bp === 'd' || isset($bucket[PP_UDC_BACKGROUND_OVERLAY_CARRIER]) || isset($bucket['background']) || isset($bucket['background-image']))
                        ? $composes($bucket) : $composes($by_bp['d'] ?? []);
                    if ($paints) {
                        $scrim_at[] = (string) $bp;
                    }
                }
                foreach ($by_bp as $bp => $declarations) {
                    if (!empty($declarations[PP_UDC_BACKGROUND_OVERLAY_CARRIER]['raw_background_won'])) {
                        $by_bp[$bp][PP_UDC_BACKGROUND_OVERLAY_CARRIER]['band_scrim_at'] = $scrim_at;
                    }
                }
            }

            // WHETHER THE BASE TIER LEAVES A GRID BEHIND for narrower tiers to
            // inherit. Computed once per state rather than per bucket — and it asks
            // two questions the first cut got wrong, both found by the pre-landing
            // adversarial pass.
            //
            // WHICH display, not whether one. Any `d` display used to suppress the
            // narrower tiers' companion, so an authored `_css` `display: flex` at
            // `d` beside `layout.columns` at `p` emitted tracks onto a FLEX box:
            // the phone tier had a track list and no grid, which paints nothing.
            // Only a grid display is inheritable.
            //
            // AFTER the defaults drop, not before. The authored layer filters out
            // declarations whose winner is a role default, so a `d` display that is
            // about to be dropped must not suppress anything — the flag is computed
            // from the bucket as it will actually be emitted.
            $base_bucket = $by_bp['d'] ?? [];
            if ($defaults_rank_only) {
                $base_bucket = array_filter(
                    $base_bucket,
                    static fn(array $entry): bool => $entry['source'] !== 'defaults'
                );
            }
            $base_display = isset($base_bucket['display'])
                ? strtolower(trim((string) $base_bucket['display']['css']))
                : '';
            $base_tier_has_display = in_array($base_display, ['grid', 'inline-grid'], true)
                || (!empty($base_bucket['grid-template-columns']['companion']) && $base_display === '');

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
                // Which roles a card's own map gives an image — asked once per compile, and
                // only when a ledger is kept (render paths never pay for it).
                if ($drops !== null && !isset($card_image_roles)) {
                    $card_image_roles = [];
                    // Item roles only: any other role on a card is dropped whole at item
                    // grain, so its image never paints and cannot be what hides the scrim.
                    $card_item_roles = (array) (pp_udc_item_roles((string) ($item['component'] ?? ''))['roles'] ?? []);
                    foreach (pp_udc_item_maps($item) as $card_map) {
                        foreach ($card_map as $card_role => $card_role_map) {
                            // A card image counts only if it RESOLVES: an attachment deleted
                            // since the write paints nothing and hides no scrim.
                            if (is_array($card_role_map) && in_array((string) $card_role, $card_item_roles, true)
                                && pp_udc_background_image_url(_pp_udc_role_map_background_image($card_role_map)) !== null) {
                                $card_image_roles[(string) $card_role] = true;
                            }
                        }
                    }
                }
                $declarations = _pp_udc_compose_background_layers(
                    $declarations,
                    $drops,
                    $drops === null ? '' : _pp_udc_overlay_drop_where('', (string) $role_name, (string) $state, (string) $bp),
                    (string) $state !== '',
                    false,
                    $drops !== null && isset($card_image_roles[(string) $role_name])
                );
                // AFTER the compose, so the overlay has already been folded into
                // background-image and the companions see the final layer list.
                $declarations = _pp_udc_background_image_companions($declarations);
                // The layout companion joins the same stage, after the sort, for
                // the same reason the background trio is appended rather than
                // sorted in: a companion is the engine's addition to a finished
                // set, and `display` has no shorthand relationship with anything
                // here, so its position cannot erase a sibling.
                $declarations = _pp_udc_grid_columns_companion(
                    $declarations,
                    $bp !== 'd' && $base_tier_has_display
                );
                if ($declarations === []) {
                    continue;
                }
                $out['blocks'][] = [
                    'role'     => $role_name,
                    'selector' => $selector,
                    'state'    => $state,
                    'bp'       => $bp,
                    'decls'    => $declarations,
                    'item'     => '',
                ];
            }
        }
    }

    // ── THE ITEM TIER (BUILD-SPEC Addendum B) ───────────────────────────────
    //
    // ONE MORE SOURCE ROW, NOT A SECOND ENGINE. An item's map resolves through
    // _pp_udc_place() against the same params table, the same band tokens and
    // the same breakpoints the band tier just used; all that differs is the
    // SOURCE name it carries into provenance and the selector it emits under.
    //
    // AUTHORED LAYER ONLY, and this is a fact about the contract rather than an
    // optimisation: items have no defaults tier. A role default is a
    // COMPONENT-level constant, identical for every band and therefore for every
    // item, and it already emits once per page under `[data-pp-component]`.
    // There is no such thing as an item default to compile.
    //
    // PRINTS AFTER EVERY BAND BLOCK, which is load-bearing for exactly one case.
    // For a non-root role the item selector carries one more attribute than the
    // band's and wins on specificity whatever the order. For the ROOT role the
    // two are both (0,2,0) — `[data-pp-band] [data-pp-item]` against
    // `[data-pp-band] .some-class` — so the tie breaks on source order and
    // nothing else. Appending here, after the role loop has finished, is what
    // makes B3's "the item tier prints last" true; `_pp_udc_render_blocks()`
    // buckets by (state, breakpoint) preserving insertion order, so the property
    // survives the bucketing.
    if ($layer !== 'defaults') {
        $declaration = pp_udc_item_roles($component);
        if ($declaration !== null) {
            // TWO CARDS CLAIMING ONE ID IS LEDGERED, NOT SWALLOWED (#1101).
            //
            // The write gate refuses this as `duplicate_component_id`, in those words:
            // "an item id scopes that item's styling rules, so sharing one would paint
            // each design on both". But the write gate is not the only way data arrives,
            // and from storage the outcome was silent and worse than the message
            // describes. Measured on a two-entry band both claiming `it-aaaaaaaa`:
            //
            //   emitted  [data-pp-band="pp-…"] [data-pp-item="it-aaaaaaaa"]{background:#111111;}
            //   rendered BOTH cards carry data-pp-item="it-aaaaaaaa"
            //   drops    []      findings  []
            //
            // So the second card's stored design is DISCARDED and the first card's is
            // painted on both — a whole card's design lost, on no channel at all. The
            // sibling case immediately below (a top-level key this tier cannot address)
            // was given a ledger row in this same change under the same "the write gate
            // is not the only way data arrives" argument; the half left silent was the
            // one that loses more.
            //
            // pp_udc_item_maps() keeps the FIRST map deliberately (it is the one an
            // already-rendered page was built against), so this reports rather than
            // changes what paints.
            if ($drops !== null) {
                $seen_ids = [];
                $entries_for_dupes = $item['props'][$declaration['prop']] ?? null;
                if (is_array($entries_for_dupes)) {
                    foreach ($entries_for_dupes as $dupe_entry) {
                        if (!is_array($dupe_entry)) {
                            continue;
                        }
                        $dupe_id = isset($dupe_entry[PP_UDC_ITEM_ID_KEY])
                            && is_scalar($dupe_entry[PP_UDC_ITEM_ID_KEY])
                            ? (string) $dupe_entry[PP_UDC_ITEM_ID_KEY]
                            : '';
                        if ($dupe_id === '' || !pp_udc_valid_item_id($dupe_id)) {
                            continue;
                        }
                        if (isset($seen_ids[$dupe_id])) {
                            if (count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                                $drops[] = [
                                    'where'  => sprintf('item "%s"', _pp_udc_reflect($dupe_id)),
                                    'reason' => 'two cards in this band claim that id, so only the first '
                                        . "card's design is painted — and it is painted on both",
                                ];
                            }
                            continue;
                        }
                        $seen_ids[$dupe_id] = true;
                    }
                }
            }

            foreach (pp_udc_item_maps($item) as $item_id => $item_map) {
                // A TOP-LEVEL KEY THIS TIER CANNOT ADDRESS IS LEDGERED, NOT STEPPED OVER.
                //
                // The loop below walks the DECLARED roles, so anything else in a stored
                // item map — `_css`, `_band`, `_tokens`, a typo, a band-only role — is
                // simply never visited. At write that is fine: each one is refused by
                // name. But the write gate is not the only way data arrives, and on a raw
                // meta write or restore_composition (#233) the key was accepted by
                // nothing, refused by nothing and reported by nothing.
                //
                // That is the accepted-stored-ignored shape pp_udc_item_reserved_keys()'
                // own docblock says must be refused rather than silently dropped, and the
                // `_css` GROUP arm a few lines down already ledgers its half — so the two
                // depths of the same exclusion disagreed about whether to say anything.
                if ($drops !== null) {
                    foreach ($item_map as $stored_key => $ignored_value) {
                        $stored_key = (string) $stored_key;
                        if (in_array($stored_key, $declaration['roles'], true)
                            || count($drops) >= PP_UDC_MAX_EMIT_DROPS) {
                            continue;
                        }
                        $drops[] = [
                            'where'  => sprintf(
                                'item "%s" role "%s"',
                                _pp_udc_reflect((string) $item_id),
                                _pp_udc_reflect($stored_key)
                            ),
                            'reason' => isset(pp_udc_item_reserved_keys()[$stored_key])
                                ? pp_udc_item_reserved_keys()[$stored_key]
                                : 'this component does not make that role settable on a single item',
                        ];
                    }
                }
                foreach ($declaration['roles'] as $role_name) {
                    $role_def = $roles[$role_name] ?? null;
                    if (!is_array($role_def)) {
                        continue;
                    }
                    $item_declared = isset($item_map[$role_name]) && is_array($item_map[$role_name])
                        ? $item_map[$role_name]
                        : [];
                    if ($item_declared === []) {
                        continue;
                    }
                    $selector = (string) ($role_def['selector'] ?? '');
                    if (!_pp_udc_selector_is_emittable($selector)) {
                        continue;
                    }
                    // THE ROOT ROLE EMITS UNDER THE ATTRIBUTE ITSELF, not under the
                    // attribute plus its own class. The component renders
                    // `data-pp-item` ON the element the root role maps to, so
                    // `[data-pp-band] [data-pp-item="…"] .that-class` would look for
                    // the class INSIDE the element that already carries it and match
                    // nothing. Blanking the selector here routes it through the same
                    // `$is_root` arm the band's `_band` role uses.
                    if ($role_name === $declaration['root']) {
                        $selector = '';
                    }

                    $permitted = isset($role_def['groups']) && is_array($role_def['groups'])
                        ? $role_def['groups']
                        : [];
                    $defaults = isset($role_def['defaults']) && is_array($role_def['defaults'])
                        ? $role_def['defaults']
                        : [];

                    $resolved    = [];
                    $sources     = [];
                    $has_presets = false;
                    foreach (_pp_udc_preset_sources($item_declared, $permitted) as $preset_source) {
                        $sources[]   = $preset_source;
                        $has_presets = true;
                    }
                    // Role defaults join ONLY to rank a preset under them, exactly as
                    // the band tier does and for the same reason: with no preset in
                    // play every entry they place is either overwritten by the item's
                    // own value or dropped again, which is pure work for an identical
                    // result.
                    $defaults_rank_only = $has_presets;
                    if ($defaults_rank_only) {
                        $sources[] = ['defaults', $defaults];
                    }
                    $sources[] = ['item', $item_declared];

                    foreach ($sources as [$source, $map]) {
                        if (!is_array($map)) {
                            continue;
                        }
                        foreach ($map as $group_name => $group_map) {
                            if ((string) $group_name === PP_UDC_PRESET_KEY) {
                                continue;
                            }
                            // `_css` is refused at write (exclusion 7) and refused
                            // again here, because the write gate is not the only way
                            // data arrives: a raw meta write, a composition written
                            // before this tier, and restore_composition (#233) all
                            // reach this line directly. A gate that runs only at write
                            // is a gate the emitter disagrees with.
                            if ((string) $group_name === PP_UDC_CSS_KEY) {
                                if ($drops !== null && $source !== 'defaults'
                                    && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                                    $drops[] = [
                                        'where'  => sprintf(
                                            'item "%s" role "%s"',
                                            _pp_udc_reflect((string) $item_id),
                                            _pp_udc_reflect((string) $role_name)
                                        ),
                                        'reason' => 'raw CSS is not available on a single item',
                                    ];
                                }
                                continue;
                            }
                            if (!isset($groups[$group_name]) || !is_array($group_map)
                                || !in_array((string) $group_name, $permitted, true)) {
                                if ($drops !== null && $source !== 'defaults'
                                    && count($drops) < PP_UDC_MAX_EMIT_DROPS) {
                                    $drops[] = [
                                        'where'  => sprintf(
                                            'item "%s" role "%s" group "%s"',
                                            _pp_udc_reflect((string) $item_id),
                                            _pp_udc_reflect((string) $role_name),
                                            _pp_udc_reflect((string) $group_name)
                                        ),
                                        'reason' => !isset($groups[$group_name])
                                            ? 'there is no such group in the design vocabulary'
                                            : (!is_array($group_map)
                                                ? 'the group is not a map of parameters'
                                                : 'this role does not permit that group, so the write gate '
                                                    . 'refuses it and the page does not paint it'),
                                    ];
                                }
                                continue;
                            }
                            $params = $groups[$group_name]['params'];
                            $where  = $drops === null ? '' : sprintf(
                                'item "%s" role "%s" group "%s"',
                                _pp_udc_reflect((string) $item_id),
                                _pp_udc_reflect((string) $role_name),
                                _pp_udc_reflect((string) $group_name)
                            );
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
                        $base_bucket = $by_bp['d'] ?? [];
                        if ($defaults_rank_only) {
                            $base_bucket = array_filter(
                                $base_bucket,
                                static fn(array $entry): bool => $entry['source'] !== 'defaults'
                            );
                        }
                        $base_display = isset($base_bucket['display'])
                            ? strtolower(trim((string) $base_bucket['display']['css']))
                            : '';
                        $base_tier_has_display = in_array($base_display, ['grid', 'inline-grid'], true)
                            || (!empty($base_bucket['grid-template-columns']['companion']) && $base_display === '');

                        foreach ($by_bp as $bp => $declarations) {
                            if ($defaults_rank_only) {
                                $declarations = array_filter(
                                    $declarations,
                                    static fn(array $entry): bool => $entry['source'] !== 'defaults'
                                );
                            }
                            $declarations = _pp_udc_sort_declarations($declarations);
                            $declarations = _pp_udc_compose_background_layers(
                                $declarations,
                                $drops,
                                $drops === null ? '' : _pp_udc_overlay_drop_where((string) $item_id, (string) $role_name, (string) $state, (string) $bp),
                                (string) $state !== '',
                                true
                            );
                            $declarations = _pp_udc_background_image_companions($declarations);
                            $declarations = _pp_udc_grid_columns_companion(
                                $declarations,
                                $bp !== 'd' && $base_tier_has_display
                            );
                            if ($declarations === []) {
                                continue;
                            }
                            $out['blocks'][] = [
                                'role'     => $role_name,
                                'selector' => $selector,
                                'state'    => $state,
                                'bp'       => $bp,
                                'decls'    => $declarations,
                                'item'     => (string) $item_id,
                            ];
                        }
                    }
                }
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
    string $where = '',
    bool $is_raw = false
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

    // PARAM FACTS, HOISTED. None of these depend on the breakpoint, and this is the
    // hottest loop in the engine — they were being recomputed per bucket.
    //
    // THE REGISTRY IS THE DISCRIMINATOR for the companion, not the parameter's name.
    // A group parameter carries `companion` in pp_udc_groups(); the `_css` route
    // builds its param from pp_udc_css_param(), which COPIES the registry entry for
    // a claimed property — so the flag would come with it, and `_group` (the key
    // pp_udc_css_param() adds and a registry param never has) is what tells the two
    // routes apart honestly.
    $definition         = $params[$param_name];
    $companion          = !empty($definition['companion']) && !isset($definition['_group']);
    $is_track_list      = ($definition['type'] ?? '') === 'track-list';
    $may_carry_companion = isset(_pp_udc_companion_properties()[$property]);

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
            // Nor where the value came through `_css` (#1141): 8c walks a role's `background.image`,
            // never its raw map, so a stored raw `background-image` the grammar refuses was dropped
            // on no channel at all.
            $owned_by_8c = ($params[$param_name]['type'] ?? '') === 'attachment_id'
                && strncmp($source, 'preset:', 7) !== 0 && !$is_raw;
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

        // A COLUMN COUNT BECOMES A TRACK LIST HERE, downstream of both gates, for
        // the same reason the url() above is built here: what an author wrote and
        // what reaches the stylesheet are different strings, and the engine owns
        // the second one.
        //
        // DECIDE FROM THE LITERAL, EMIT THE CSS — the rule
        // _pp_udc_compose_background_layers() records, and the reason is identical:
        // the two differ whenever the value came through a token, and a RESPONSIVE
        // value always does, because write-time normalization mints
        // `{"columns": {"d": 3, "p": 1}}` into band tokens and rewrites it as
        // references. Testing $css for digits would therefore see `var(--pp-…)` and
        // silently skip the synthesis on exactly the maps most likely to use it.
        //
        // The emitted form is then `repeat(var(--pp-…), minmax(0, 1fr))`, which is
        // valid: custom properties substitute before the property's grammar is
        // checked at computed-value time. Verified in Chromium at 375/768/1280
        // before this was built (rule 14.3), not inferred from the spec.
        //
        // `minmax(0, …)` rather than a bare `1fr` is this repo's own grid lesson: a
        // `1fr` track has an `auto` minimum, so one long unbroken token widens the
        // track and scrolls the page sideways (#1043/#1067).
        // The count SHAPE is owned by the grammar (_pp_css_grid_count), so the
        // validator and this synthesis cannot drift apart. They were two copies of
        // one regex; a literal the grammar accepted and this line declined to
        // synthesise would emit a bare `grid-template-columns: 100`, which the
        // browser drops while keeping the companion below — the dead-value class
        // the companion exists to prevent.
        if ($is_track_list && _pp_css_grid_count($literal) !== null) {
            $css = 'repeat(' . $css . ', minmax(0, 1fr))';
        }

        // THE COMPANION RIDES THE PARAMETER, NOT THE PROPERTY (#1084, found by the
        // pre-landing testing pass).
        //
        // It was keyed on `grid-template-columns` appearing in the bucket, and
        // `_css` can put it there on ANY role — so the exposure roster, which gates
        // the `layout` GROUP, gated nothing. Probed: `{"menu": {"_css":
        // {"grid-template-columns": "2"}}}` on nav emitted an unlayered
        // `display: grid` on `.nav__menu`, which outranks the UA stylesheet's
        // `[hidden]` rule and PINS AN OPEN MOBILE MENU OPEN — the exact
        // keyboard/screen-reader break the roster's visibility-switch clause exists
        // to make unreachable, reachable through the next door along.
        //
        // The fix is the honest rule rather than a second exclusion list: the
        // companion belongs to `layout.columns`, which is a designed parameter with
        // a documented behaviour, and NOT to the raw valve, whose whole posture is
        // that it checks a value's safety and not its meaning. A raw track list
        // emits a track list; if the box is not already a grid, that is the same
        // inertness any raw declaration can have on an element it does not suit.
        //
        $entry = [
            'css'     => $css,
            'source'  => $source,
            'literal' => $literal,
        ];
        // WHICH PLACEMENTS ARE RAW (#1141): the `_css` valve's declarations outrank the group's at the
        // same coordinate (contract §2'.3), and the background shorthand can only do that if the stage
        // that composes the image layers knows it is raw. Written only when true.
        if ($is_raw) {
            $entry['raw'] = true;
        }
        // THE MARKER IS WRITTEN ONLY WHEN IT IS TRUE, and the inheritance read runs
        // only for a property that can carry one. Both were unconditional, and the
        // pre-landing performance pass measured the four-level read at 0.23 ms of a
        // 0.47 ms regression ON A PAGE WITH NO LAYOUT VALUES AT ALL — the `||` never
        // short-circuits, because `$companion` is false for 66 of the 67 parameters.
        // A page must not pay for a feature it does not use.
        //
        // A LATER WRITER AT THE SAME COORDINATE KEEPS THE MARKER. `_css` places
        // after the groups by rank, so a band carrying BOTH `layout.columns` and a
        // raw `grid-template-columns` would otherwise lose the companion the group
        // value earned — the author would set two values and watch the box stop
        // being a grid. The raw value still wins the property (and the envelope
        // still discloses that with `udc_css_overrides_group_value`); it just does
        // not un-declare the display the group value implied.
        if ($companion) {
            $entry['companion'] = $definition['companion'];
        } elseif ($may_carry_companion && !empty($resolved[$state][$bp][$property]['companion'])) {
            $entry['companion'] = $resolved[$state][$bp][$property]['companion'];
        }
        $resolved[$state][$bp][$property] = $entry;
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
 * The three STATES sit at identical specificity, so among them ORDER IS THE
 * RANKING — `:active` beats `:hover` because it prints after it, and the guard
 * neutralizes the motion above it for the same reason. A state suffix does add
 * specificity over the resting rule, and an item scope adds it over the band's
 * (pp_udc_role_paint() ranks by both). Every selector is the band's scope from
 * _pp_udc_emission_scopes() plus the role's own selector; `!important` never
 * appears.
 */
function pp_udc_band_css(array $item): string {
    $compiled = pp_udc_compile_band($item, 'authored');
    if ($compiled['id'] === '') {
        return '';
    }
    // A COMPOSITION ROW, whatever its component name (#1125 /ship coverage audit): the band id.
    [$scope, $root_scope] = _pp_udc_emission_scopes((string) ($item['component'] ?? ''), $compiled['id'], true)['authored'];
    return _pp_udc_render_blocks($compiled, $scope, $root_scope);
}

/**
 * THE SELECTOR SCOPES EACH EMITTED TIER PRINTS UNDER (#1125): [scope, root scope] per tier.
 *
 * ONE OWNER for the four render functions (pp_udc_component_defaults_css,
 * _pp_udc_overlay_tier_css, pp_udc_band_css, pp_udc_chrome_css) and for pp_udc_role_paint(),
 * which ranks declarations by the specificity of the selectors the page actually carries. A
 * second spelling of a scope in the reader would be the hand-written copy of renderer
 * resolution the #1125 descope ruled out.
 *
 * Chrome renders under its own attribute in both layers; a composable band's defaults render
 * under the component scope and its authored blocks under the band id. The defaults tier's
 * ROOT rules print under `:where()` (and in the `pp-zero` cascade layer), see
 * pp_udc_component_defaults_css().
 *
 * $composition_row = true is the three COMPOSITION emitters (pp_udc_component_defaults_css,
 * _pp_udc_overlay_tier_css, pp_udc_band_css): a composition row is a band for EVERY component
 * name, so it prints under the component scope and its band id. A stored row named after a
 * chrome component is refused at write, but raw meta, a legacy import or a restore still
 * carry it to the page render; under the chrome scope it would restyle the live site header
 * (#1125 /ship coverage audit; main scoped it this way, and a refactor here had not). Chrome
 * itself is pp_udc_chrome_css(); pp_udc_role_paint() reads the surface as it paints.
 *
 * @return array{defaults: array{0: string, 1: string}, overlay: array{0: string, 1: string}, authored: array{0: string, 1: string}}
 */
function _pp_udc_emission_scopes(string $component, string $id, bool $composition_row = false): array {
    $chrome   = !$composition_row && pp_udc_is_chrome($component);
    $base     = $chrome
        ? '[data-pp-chrome="' . $component . '"]'
        : '[data-pp-component="' . $component . '"]';
    $overlay  = ':where(' . $base . ')[data-pp-band-overlay]';
    $authored = $chrome ? $base : '[data-pp-band="' . $id . '"]';
    return [
        'defaults' => [$base, ':where(' . $base . ')'],
        'overlay'  => [$overlay, $overlay],
        'authored' => [$authored, $authored],
    ];
}

/**
 * WHAT PAINTS AT TIER X: the band's effective background, as the renderer resolves it
 * (#1010 review, the one-owner ruling). The single answer to "is this band scrimmed at this
 * width, or in this state", read off a compiled band (pp_udc_compile_band(), 'authored'):
 * the emitter has already merged author and preset tiers, resolved the image (a deleted
 * attachment paints nothing), dropped an overlay with no image, and chosen each
 * reference's token scope. This function adds only what the CASCADE does with the result:
 *
 *   - a tier whose `_band` block declares a background-image carrying url() paints that
 *     image, with the gradient layers before it as its scrim ('' when none);
 *   - a tier whose block declares the `background` shorthand with no image after it, or a
 *     background-image with no url(), REPLACES the image at that width (a per-breakpoint
 *     fill, a `_css` background): no image, no scrim. Within a block the later
 *     declaration wins, so a fill printed BEFORE the image leaves the image painting;
 *   - a tier with no block inherits the base tier (`d`), the cascade order of the
 *     breakpoint media blocks;
 *   - a `_band` STATE block that declares either property repaints the band in that state.
 *
 * The overlay marker and the off-scrim finding both read this, so they cannot disagree.
 *
 * Each tier also carries `size` and `partial` (#1142 item 1): `background-size` and `background-repeat` are read
 * from the resting `_band` blocks, each inheriting from `d` on its own, and a scrimmed tier is `partial` when
 * _pp_udc_scrim_leaves_part_uncovered() says an axis is neither covered nor tiled.
 *
 * A tier whose image a background REPLACED also carries `raw`: true when that background came from `_css` (#1141),
 * false when the group set it. It is absent on every other tier; readers use !empty().
 *
 * @return array{tiers: array<string, array{image: bool, scrim: string, source: string, size: string, partial: bool, raw?: bool}>, states: array<int, string>}
 */
function pp_udc_band_effective_background(array $compiled): array {
    $declared = [];
    $states   = [];
    $sizing   = []; // bp => ['size' => css, 'repeat' => css] as declared at that tier (#1142 item 1)
    foreach ((array) ($compiled['blocks'] ?? []) as $block) {
        if (($block['role'] ?? '') !== '_band' || ($block['item'] ?? '') !== '') {
            continue;
        }
        $decls = is_array($block['decls'] ?? null) ? $block['decls'] : [];
        if (($block['state'] ?? '') === '') {
            foreach (['size' => 'background-size', 'repeat' => 'background-repeat'] as $key => $property) {
                if (is_string($decls[$property]['css'] ?? null)) {
                    $sizing[(string) ($block['bp'] ?? 'd')][$key] = strtolower(trim($decls[$property]['css']));
                }
            }
        }
        $image = $decls['background-image'] ?? null;
        $short = $decls['background'] ?? null;
        if (!is_array($image) && !is_array($short)) {
            continue;
        }
        if (($block['state'] ?? '') !== '') {
            $states[] = (string) $block['state'];
            continue;
        }
        $bp = (string) ($block['bp'] ?? 'd');
        // Within one block the LATER declaration wins: the emitter prints a fill's
        // `background` shorthand before `background-image`, so a band with a fill AND an
        // image paints the image; only a shorthand printed after it would erase it.
        $order        = array_keys($decls);
        $short_erases = is_array($short) && (!is_array($image)
            || array_search('background', $order, true) > array_search('background-image', $order, true));
        if (is_array($image) && is_string($image['css'] ?? null) && stripos($image['css'], 'url(') !== false && !$short_erases) {
            $declared[$bp] = [
                'image'  => true,
                'scrim'  => trim((string) preg_replace('/,?\s*url\(\s*"[^"]*"\s*\)|,?\s*url\([^)]*\)/i', '', $image['css']), " ,"),
                'source' => (string) ($image['source'] ?? ''),
            ];
        } else {
            $replaced = is_array($short) ? $short : $image;
            // `raw`: the replacing background is the `_css` valve's (#1141), so a message can say what replaced the image.
            $declared[$bp] = ['image' => false, 'scrim' => '', 'source' => (string) ($replaced['source'] ?? ''), 'raw' => !empty($replaced['raw'])];
        }
    }
    $tiers = [];
    foreach (array_keys(pp_udc_breakpoints()) as $bp) {
        $tiers[$bp] = $declared[$bp] ?? ($declared['d'] ?? ['image' => false, 'scrim' => '', 'source' => '']);
        // A SCRIM THAT COVERS ONLY PART OF THE BOX (#1142 item 1). `background-size` applies to every layer,
        // so an image sized without tiling paints its scrim on part of the band and the rest shows the band's
        // own background. Each property inherits from the base tier on its own, as the cascade does.
        $size   = $sizing[$bp]['size'] ?? ($sizing['d']['size'] ?? '');
        $repeat = $sizing[$bp]['repeat'] ?? ($sizing['d']['repeat'] ?? '');
        $tiers[$bp]['size']    = $size;
        $tiers[$bp]['partial'] = !empty($tiers[$bp]['image']) && ($tiers[$bp]['scrim'] ?? '') !== ''
            && _pp_udc_scrim_leaves_part_uncovered(_pp_udc_compiled_value($size, $compiled), _pp_udc_compiled_value($repeat, $compiled));
    }
    return ['tiers' => $tiers, 'states' => array_values(array_unique($states))];
}

/**
 * WHETHER A SCRIM SIZED THIS WAY LEAVES PART OF THE BOX UNCOVERED (#1142 item 1, ruling A in the PR-2 review), decided
 * PER AXIS from the scrim layer itself. `background-size` applies to every layer, and a gradient has no natural size,
 * so under `contain`, `cover`, `auto` or a percentage of 100 or more it fills the box on that axis (Chromium, corner
 * pixel: scrim). A length, or a percentage under 100, leaves the rest of that axis unscrimmed unless the axis tiles
 * (`repeat` / `round`; `space` leaves gaps, `no-repeat` none). A value the engine cannot read is not claimed to cover.
 */
function _pp_udc_scrim_leaves_part_uncovered(string $size, string $repeat): bool {
    $size = strtolower(trim($size));
    if ($size === '' || $size === 'contain' || $size === 'cover') {
        return false;
    }
    $axes = preg_split('/\s+/', $size);
    $axes = [$axes[0], $axes[1] ?? 'auto'];
    $rep  = preg_split('/\s+/', strtolower(trim($repeat)));
    if ($rep === [''] ) {
        $tiles = [true, true]; // the CSS initial value, `repeat`
    } elseif (count($rep) === 1) {
        $tiles = $rep[0] === 'repeat-x' ? [true, false] : ($rep[0] === 'repeat-y' ? [false, true]
            : [in_array($rep[0], ['repeat', 'round'], true), in_array($rep[0], ['repeat', 'round'], true)]);
    } else {
        $tiles = [in_array($rep[0], ['repeat', 'round'], true), in_array($rep[1], ['repeat', 'round'], true)];
    }
    foreach ($axes as $i => $axis) {
        $covers = $axis === 'auto' || (preg_match('/^(\d+(?:\.\d+)?|\.\d+)%\z/', $axis, $m) && (float) $m[1] >= 100.0);
        if (!$covers && !$tiles[$i]) {
            return true;
        }
    }
    return false;
}

/** Whether a compiled band paints a scrim over its image at any width: the overlay marker's predicate. */
function pp_udc_band_paints_scrim(array $compiled): bool {
    return _pp_udc_effective_paints_scrim(pp_udc_band_effective_background($compiled));
}

/** The marker's predicate over an already-read effective background (one read, one answer). */
function _pp_udc_effective_paints_scrim(array $effective): bool {
    foreach ((array) ($effective['tiers'] ?? []) as $tier) {
        if (!empty($tier['image']) && ($tier['scrim'] ?? '') !== '') {
            return true;
        }
    }
    return false;
}

/**
 * WHAT PAINTS ON A ROLE (#1125, landed on the compiled band): for each element a role
 * renders on (one per card that carries its own map, and one for everything else), per state
 * and per breakpoint, the declaration that wins `color`, `background-color` and
 * `background-image`, with the tier it came from.
 *
 * WHY THIS EXISTS. A role's element is painted by up to four emitted tiers, not one compile:
 *
 *   defaults   pp_udc_component_defaults_css() / chrome defaults   printed first
 *   overlay    _pp_udc_overlay_tier_css(), only on a marked band   printed second
 *   band       pp_udc_band_css() / chrome authored, band blocks    printed third, sharing one
 *   item       pp_udc_band_css(), item blocks                      stylesheet: by state, then
 *                                                                  breakpoint, then block
 *
 * (so a band `:hover` rule prints AFTER an item's resting rule; an item rule usually wins on
 * specificity, 0,3,0 over 0,2,0, not by printing last)
 *
 * and the authored compile DROPS every declaration a role default wins (the rung order is
 * site tokens, presets, role defaults, the author's map), so "the surface under this ink"
 * cannot be read off any single compile. The withdrawn first cut of #1125 guessed instead: it
 * counted a preset fill as painting when the role's default outranked it. This reads what the
 * renderer emits and ranks it the way the browser does:
 *
 *   1. specificity, computed from the selector the renderer prints for the block
 *      (_pp_udc_emission_scopes() + _pp_udc_emitted_selector() + the state suffix), so a
 *      state beats rest and an item rule beats a band rule exactly when the page's own
 *      selectors say so;
 *   2. source order: tier, then state, then base before `@media` (narrow-first), then block,
 *      then declaration order. Breakpoint tiers are disjoint ranges, so at a width only the
 *      base (`d`) blocks and that width's blocks apply.
 *
 * A `background` shorthand is read as both longhands: a colour sets `background-color` and
 * resets `background-image` to `none`; a gradient or `url()` sets the image and resets the
 * colour to `transparent`. The painted surface is the image when it paints, else the colour
 * when it paints (_pp_udc_paints_surface()).
 *
 * NO CASCADE-LAYER TERM, deliberately: the only layered engine rules are the defaults tier's
 * ROOT rules (`pp-zero`), and the only root-selector role is `_band`, which this does not
 * answer for (pp_udc_band_effective_background() does). A planted-defect run proved a layer
 * term here unreachable, so it is not carried as dead weight.
 *
 * WHAT IT DOES NOT SEE, stated so nobody reads more into it: rules in the layered v1
 * stylesheet (`@layer pp-v1`) rank under every tier here and paint only where no tier
 * declares; they are not read. Nor is containment (a role inside another role's surface,
 * #1140): each element is answered for itself.
 *
 * @param array $item          The band (or a chrome entry shaped like one).
 * @param array $authored      pp_udc_compile_band($item, 'authored'): the compile the findings
 *                             walk already shares.
 * @param array $defaults      pp_udc_compile_band(['component' => X], 'defaults'): passed in so
 *                             the caller can reuse it across bands of one component.
 * @param bool  $overlay_marked Whether the renderer marks this band `data-pp-band-overlay`.
 * @param array|null $only_roles role => true: answer only these roles (the findings arm asks for the roles
 *                             whose element renders author text; /ship performance: answering every role
 *                             and discarding most doubled the cost at 4,000 cards). Null answers every role.
 * @return array<int, array{item: string, role: string, paint: array<string, array<string, array{
 *     color: ?array{key: array, css: string, literal: string, tier: string},
 *     surface: ?array{property: string, key: array, css: string, literal: string, tier: string},
 *     default_color: ?string,
 *     default_surface: ?array{property: string, css: string}}>>}>
 *   `default_color` / `default_surface`: what the defaults and overlay tiers ALONE put in that cell, so a
 *   restated author value can be read as the default it restates (the ink rule, cycle 2; the surface rule,
 *   ruling E = A).
 */
function pp_udc_role_paint(array $item, array $authored, array $defaults, bool $overlay_marked, ?array $only_roles = null): array {
    $component = isset($item['component']) && is_scalar($item['component']) ? (string) $item['component'] : '';
    $id        = (string) ($authored['id'] ?? '');
    $roles     = pp_udc_component_roles($component);
    if ($roles === [] || $id === '' || !pp_udc_valid_band_id($id)) {
        return [];
    }
    $scopes = _pp_udc_emission_scopes($component, $id);
    $tiers  = [['defaults', $defaults, $scopes['defaults']]];
    if ($overlay_marked) {
        $overlay = _pp_udc_overlay_tier_compile($component);
        if ($overlay !== null) {
            $tiers[] = ['overlay', $overlay, $scopes['overlay']];
        }
    }
    $tiers[] = ['authored', $authored, $scopes['authored']];

    $state_rank = array_flip(pp_udc_states_in_emit_order());
    $paint_properties = ['color' => true, 'background' => true, 'background-color' => true, 'background-image' => true];
    $bp_meta    = pp_udc_breakpoints();

    // Every declaration that can reach a role, as [role, item, state, bp, rank key, longhands].
    // Specificity is parsed once per distinct emitted selector (a role's blocks share one across
    // widths): measured, parsing it per declaration was most of this function's cost.
    $by_role      = [];
    $spec_memo    = [];
    $surface_memo = []; // the colour reader, once per distinct value (a default repeats per width)
    // The surface a contest's winners paint: the image when it paints, else the colour when it paints.
    $painted = static function (array $contest) use (&$surface_memo): ?array {
        foreach (['background-image', 'background-color'] as $longhand) {
            if (!isset($contest[$longhand])) {
                continue;
            }
            $memo_key = $longhand . "\0" . $contest[$longhand]['literal'];
            $surface_memo[$memo_key] = $surface_memo[$memo_key] ?? _pp_udc_paints_surface($contest[$longhand]['literal'], $longhand);
            if ($surface_memo[$memo_key]) {
                return ['property' => $longhand] + $contest[$longhand];
            }
        }
        return null;
    };
    foreach ($tiers as $tier_index => [$tier_name, $compiled, [$scope, $root_scope]]) {
        foreach ((array) ($compiled['blocks'] ?? []) as $block_index => $block) {
            $role = (string) ($block['role'] ?? '');
            if ($role === '_band' || !isset($roles[$role]) || ($only_roles !== null && !isset($only_roles[$role])) || !is_array($block['decls'] ?? null)
                || array_intersect_key($block['decls'], $paint_properties) === []) {
                continue; // Most default blocks carry only type and spacing: nothing to rank.
            }
            $block_item = (string) ($block['item'] ?? '');
            $state      = (string) ($block['state'] ?? '');
            $bp         = (string) ($block['bp'] ?? 'd');
            $selector   = (string) ($block['selector'] ?? '');
            $emitted    = _pp_udc_emitted_selector($scope, $root_scope, $selector, $block_item) . $state;
            $spec_memo[$emitted] = $spec_memo[$emitted] ?? _pp_udc_selector_specificity($emitted);
            $bp_rank    = ($bp_meta[$bp]['media'] ?? null) === null ? 0 : 1 + (int) ($bp_meta[$bp]['emit_order'] ?? 0);
            $tier       = $tier_name === 'authored' ? ($block_item === '' ? 'band' : 'item') : $tier_name;
            $decl_index = 0;
            foreach ($block['decls'] as $property => $decl) {
                if (!is_array($decl) || !is_string($decl['css'] ?? null)) {
                    continue;
                }
                $longhands = _pp_udc_paint_longhands((string) $property, $decl);
                if ($longhands === []) {
                    continue;
                }
                $by_role[$role][] = [
                    'item'  => $block_item,
                    'state' => $state,
                    'bp'    => $bp,
                    'key'   => array_merge($spec_memo[$emitted],
                        [$tier_index, $state_rank[$state] ?? 0, $bp_rank, $block_index, $decl_index++]),
                    'tier'  => $tier,
                    'longhands' => $longhands,
                ];
            }
        }
    }

    // The elements a role renders on: one per card whose own rules reach THIS role (item roles),
    // and one band-level element for every other card of the role — a card with no map, a card
    // whose map says nothing about this role, an id the emitter cannot use — or for the role when
    // there is no card at all. A card with no rules of its own for the role IS that band-level
    // element: answering (and reporting) it once per card repeated one fact per card and could
    // spend the shared findings budget on a single band (performance pass, cycle 1).
    $item_declaration = pp_udc_item_roles($component);
    $item_roles       = (array) ($item_declaration['roles'] ?? []);
    $entries          = $item_declaration !== null ? ($item['props'][$item_declaration['prop']] ?? []) : [];
    $entry_ids        = [];
    foreach (is_array($entries) ? $entries : [] as $entry) {
        $entry_ids[] = is_array($entry) && is_scalar($entry[PP_UDC_ITEM_ID_KEY] ?? null) ? (string) $entry[PP_UDC_ITEM_ID_KEY] : '';
    }

    $out = [];
    foreach ($roles as $role => $unused_definition) {
        $role = (string) $role;
        if ($role === '_band' || !isset($by_role[$role])) { // (the role filter already kept others out of $by_role)
            continue;
        }
        // INDEXED BY CARD, so an element ranks only the band-level rows and ITS OWN card's rows.
        // Walking every card's rows for every card was quadratic in the card count, and `items`
        // declares no maximum: the security pass measured 15.6 s at 2,400 styled cards on a
        // findings path (restore, check page) that has no size gate in front of it.
        $rows_by_item = [];
        foreach ($by_role[$role] as $row) {
            $rows_by_item[$row['item']][] = $row;
        }
        $locators = [''];
        if (in_array($role, $item_roles, true)) {
            $own      = array_diff_key($rows_by_item, ['' => true]); // cards with rules for this role
            $locators = array_map('strval', array_keys($own));
            $generic  = $entry_ids === [];
            foreach ($entry_ids as $entry_id) {
                if (!isset($own[$entry_id])) { // a set lookup: this runs once per card
                    $generic = true;
                    break;
                }
            }
            if ($generic) {
                array_unshift($locators, '');
            }
        }
        foreach ($locators as $locator) {
            $rows = $rows_by_item[''] ?? [];
            if ($locator !== '' && isset($rows_by_item[$locator])) {
                $rows = array_merge($rows, $rows_by_item[$locator]);
            }
            $states = [''];
            foreach ($rows as $row) {
                if ($row['state'] !== '' && !in_array($row['state'], $states, true)) {
                    $states[] = $row['state'];
                }
            }
            // STATES COMBINE IN THE BROWSER (design pass, cycle 1): a mouse press is `:active` AND
            // `:hover`, a focused control under the pointer `:focus-visible` AND `:hover`. So those
            // two pairs get a cell of their own (keyed ':hover+:active'), where the rows of BOTH
            // states compete and the rank key decides, exactly as the page's own rules do; an author
            // `:active` ink on a DEFAULT `:hover` fill was otherwise never seen.
            if (in_array(':hover', $states, true)) {
                foreach ([':active', ':focus-visible'] as $paired) {
                    if (in_array($paired, $states, true)) {
                        $states[] = ':hover+' . $paired;
                    }
                }
            }
            $paint = [];
            foreach ($states as $state) {
                $active_states = $state === '' ? [] : array_flip(explode('+', $state));
                foreach (array_keys($bp_meta) as $bp) {
                    $winners       = [];
                    $default_wins  = []; // the same contest among the defaults and overlay rows only
                    foreach ($rows as $row) {
                        if (($row['state'] !== '' && !isset($active_states[$row['state']]))
                            || ($row['bp'] !== 'd' && $row['bp'] !== $bp)) {
                            continue;
                        }
                        foreach ($row['longhands'] as $longhand => [$css, $literal]) {
                            if (!isset($winners[$longhand]) || ($row['key'] <=> $winners[$longhand]['key']) > 0) {
                                $winners[$longhand] = ['key' => $row['key'], 'css' => $css, 'literal' => $literal, 'tier' => $row['tier']];
                            }
                            if (in_array($row['tier'], ['defaults', 'overlay'], true)
                                && (!isset($default_wins[$longhand]) || ($row['key'] <=> $default_wins[$longhand]['key']) > 0)) {
                                $default_wins[$longhand] = ['key' => $row['key'], 'css' => $css, 'literal' => $literal];
                            }
                        }
                    }
                    $surface         = $painted($winners);
                    $default_surface = $painted($default_wins);
                    $paint[$state][$bp] = ['color' => $winners['color'] ?? null, 'surface' => $surface,
                        'default_color' => $default_wins['color']['css'] ?? null,
                        'default_surface' => $default_surface === null ? null : ['property' => $default_surface['property'], 'css' => $default_surface['css']]];
                }
            }
            $out[] = ['item' => $locator, 'role' => $role, 'paint' => $paint];
        }
    }
    return $out;
}

/**
 * The paint longhands one compiled declaration sets (#1125): [longhand => [css, literal]].
 * A `background` shorthand sets BOTH (CSS resets every longhand a shorthand omits): a colour
 * alone resets the image to `none`; a value carrying a gradient or `url()` is read IMAGE-FIRST,
 * with the colour taken as `transparent`. That is exact for an image-only shorthand; for a
 * mixed one (`#fff url(...)`) the colour component is not tracked, which cannot change the
 * surface answer because the image longhand paints (no shipped default is mixed, evidence-t2).
 */
function _pp_udc_paint_longhands(string $property, array $decl): array {
    $css     = (string) $decl['css'];
    $literal = is_scalar($decl['literal'] ?? null) ? (string) $decl['literal'] : $css;
    switch ($property) {
        case 'color':
        case 'background-color':
        case 'background-image':
            return [$property => [$css, $literal]];
        case 'background':
            if (preg_match('/gradient\(|url\(/i', $literal)) {
                return ['background-image' => [$css, $literal], 'background-color' => ['transparent', 'transparent']];
            }
            return ['background-color' => [$css, $literal], 'background-image' => ['none', 'none']];
    }
    return [];
}

/**
 * Whether a background longhand's value paints a surface (#1125): `transparent`, `none`, `initial`, `unset` and a fully transparent colour do
 * not (for `background-color` all of them resolve to a transparent box; none of these is
 * inherited). Everything else does, INCLUDING a value the engine cannot read: `currentColor`
 * paints the ink's own colour behind the ink, and `inherit` takes the parent's background,
 * which may be light. Unknown is a surface, never a silence.
 */
function _pp_udc_paints_surface(string $value, string $longhand): bool {
    $value = strtolower(trim($value));
    if (in_array($value, ['', 'transparent', 'none', 'initial', 'unset'], true)) {
        return false;
    }
    if ($longhand === 'background-color' && !preg_match('/gradient\(|url\(/', $value)) {
        $colours = _pp_udc_value_colours($value, []);
        if (count($colours) === 1 && (float) $colours[0][3] <= 0.0) {
            return false;
        }
    }
    return true;
}

/**
 * Whether two compiled surface values paint the same thing (#1125, rulings D and E): the same compiled
 * css, or two values the colour reader resolves to the same colours (a literal copied from a token's
 * value). Conservative in both callers: D does not gate on it, E does not let it clear the finding.
 */
function _pp_udc_same_surface(string $a, string $b): bool {
    if (strcasecmp(trim($a), trim($b)) === 0) {
        return true;
    }
    // Memoised (/ship cycle-2 performance): one pair repeats on every card of a band; bounded so a long
    // CLI run cannot grow it without limit.
    static $memo = [];
    $key = $a . "\0" . $b;
    if (!isset($memo[$key])) {
        if (count($memo) >= 4096) {
            $memo = [];
        }
        $ca         = _pp_udc_value_colours($a, []);
        $memo[$key] = $ca !== [] && $ca === _pp_udc_value_colours($b, []);
    }
    return $memo[$key];
}

/** "tablet and phone widths": breakpoint keys as labels in breakpoint order (both band findings say it). */
function _pp_udc_widths_phrase(array $bps): string {
    $labels = [];
    foreach (pp_udc_breakpoints() as $key => $meta) {
        if (in_array((string) $key, $bps, true)) {
            $labels[] = $meta['label'];
        }
    }
    return implode(' and ', $labels) . (count($labels) === 1 ? ' width' : ' widths');
}

/**
 * THE OWN-FILL NOTE on "set it on those roles" (#1125; both shadowing siblings, /ship red team RT3). Of the
 * listed roles:
 *   - a role whose default fill paints AT REST also ships the text colour the band value lost to, so it keeps
 *     that designed pair; recolouring it means setting its fill too, or udc_role_ink_over_own_surface names it
 *     (/ship design: the note used to say "set each one's background.fill with the colour", which walked
 *     authors into repainting pairs that read);
 *   - a role that fills only in a STATE (cta `button-secondary`, :hover) has its text on the author's band at
 *     rest, so it is told to set its colour there (cycle 2, design);
 *   - a role whose ink the author set through `_css`, at rest OR in any state, is left out: its pair is no
 *     longer the designed one in every state. CONSERVATIVE BY CHOICE (orchestrator, cycle 3): widening this to
 *     keep a role inked only in a state is a decision, not a cleanup. The listing itself counts group values
 *     only, #1149.
 *
 * @param string[] $names The listed roles.
 * @param array    $roles The component's role definitions (their `defaults`).
 * @param array    $udc   The band's `udc` map, or the item's for the item sibling: read for `_css` colours.
 * @return string '' when no listed role qualifies.
 */
function _pp_udc_own_fill_note(array $names, array $roles, array $udc): string {
    $paints = static function ($fill): bool {
        foreach (is_array($fill) ? $fill : [$fill] as $tier_fill) {
            if (is_string($tier_fill) && $tier_fill !== '' && strcasecmp(trim($tier_fill), 'transparent') !== 0) {
                return true;
            }
        }
        return false;
    };
    $pair = [];  // a resting fill: the designed pair is intact
    $state = []; // a fill only in a state: at rest the text sits on the band
    foreach ($names as $name) {
        // A role whose ink the author set through `_css` has no designed pair left to keep (/ship cycle-2 design;
        // the listing itself counts only group values, #1149).
        $raw = is_array($udc[$name][PP_UDC_CSS_KEY] ?? null) ? $udc[$name][PP_UDC_CSS_KEY] : [];
        $raw_ink = isset($raw['color']);
        foreach (pp_udc_states() as $state_key => $unused_state) {
            $raw_ink = $raw_ink || isset($raw[$state_key]['color']);
        }
        if ($raw_ink) {
            continue;
        }
        $bg = (array) ($roles[$name]['defaults']['background'] ?? []);
        if ($paints($bg['fill'] ?? null)) {
            $pair[] = (string) $name;
            continue;
        }
        foreach (pp_udc_states() as $state_key => $unused_state) {
            if (is_array($bg[$state_key] ?? null) && $paints($bg[$state_key]['fill'] ?? null)) {
                $state[] = (string) $name;
                break;
            }
        }
    }
    $parts = [];
    if ($pair !== []) {
        $parts[] = count($pair) === 1
            ? sprintf('%s ships its own fill and text colour, so it keeps that designed pair; to recolour it, set its background.fill as well', $pair[0])
            : sprintf('%s ship their own fill and text colour, so they keep that designed pair; to recolour one, set its background.fill as well', implode(', ', $pair));
    }
    if ($state !== []) {
        // cta `button-secondary` fills only on :hover: at rest its accent text sits on the author's band.
        $parts[] = count($state) === 1
            ? sprintf('%s fills only in a state, so at rest its text sits on your band: set its colour there', $state[0])
            : sprintf('%s fill only in a state, so at rest their text sits on your band: set their colour there', implode(', ', $state));
    }
    return $parts === [] ? '' : ' (' . implode('; ', $parts) . ')';
}

/**
 * WHICH ROLES THE BAND RENDERS WITH THESE PROPS (#1125, ruling E1-A): role => bool, read off the
 * component's OWN template rendered in-process, or null when that cannot be answered (no DOM
 * extension, a template that failed), in which case callers keep their unfiltered answer:
 * unknown is never silence.
 *
 * WHY. The accessor answers what the band's compiled CSS paints on each role's element; it does not
 * know whether the element exists for these props. Hero `surface` renders only in the split layout's
 * second column, the eyebrow only with an `eyebrow` prop, a card role only when there is a card. A
 * finding about an element the page does not have is advice about nothing. A per-role hand-written
 * render predicate would be a copy of template logic, the drift class the accessor exists to remove,
 * so the template itself is asked.
 *
 * SIDE-EFFECT FREE, and pinned so: the render goes into an output buffer that is always closed; the
 * loader's WP_DEBUG missing-prop notices are swallowed by a handler that is always restored; and the
 * shortcode registry is emptied for the render and restored after, because `embed` runs its content
 * through do_shortcode() and a real shortcode (`[embed]`) makes an HTTP fetch and writes an oEmbed
 * cache post (cycle 2, red team). Presence needs the markup the template writes, not what a shortcode
 * expands to. Findings paths only; the caller asks at most once per band, and only when a finding is
 * about to be emitted.
 *
 * BOUNDED BY MARKUP (/ship security specialist): a band over PP_UDC_PRESENCE_MARKUP_BAND bytes is not
 * parsed, and every parsed band is charged to `$markup_left`, the caller's per-call budget; a band that
 * would overdraw it is not parsed either: null with `$why` = 'size' (its own bound) or 'budget' (the call's).
 *
 * @param array       $item        The band (composable components only; chrome is not rendered here).
 * @param string[]    $roles       role => selector, the roles to answer for.
 * @param int         $markup_left The caller's remaining markup budget in bytes, charged here.
 * @param string|null $why         Set on null: 'size' (the band's own markup bound), 'budget' (the call's) or 'unrenderable'.
 * @return array<string, array{band: bool, items: array<string, bool>}>|null
 */
function _pp_udc_rendered_roles(array $item, array $roles, int &$markup_left = PHP_INT_MAX, ?string &$why = null): ?array {
    $why       = 'unrenderable';
    $component = isset($item['component']) && is_scalar($item['component']) ? (string) $item['component'] : '';
    $id        = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : '';
    if ($component === '' || pp_udc_is_chrome($component) || !pp_udc_valid_band_id($id) || !class_exists('DOMDocument')) {
        return null;
    }
    $props = pp_udc_promote_band_identity($item, isset($item['props']) && is_array($item['props']) ? $item['props'] : []);
    global $shortcode_tags;
    $saved_shortcodes = $shortcode_tags ?? null;
    $shortcode_tags   = [];
    $level  = ob_get_level();
    $caught = false;
    ob_start();
    set_error_handler(static fn (): bool => true, E_USER_WARNING | E_USER_NOTICE | E_WARNING | E_NOTICE);
    try {
        pp_get_component($component, $props);
        $html = (string) ob_get_contents();
    } catch (\Throwable $e) {
        $html   = null;
        $caught = true;
    } finally {
        // #730 (components/section/section.php): wp_pre_kses_block_attributes() unhooks itself from
        // `pre_kses`, filters, and re-hooks; a throw caught in the middle would leave block-attribute KSES
        // off for the rest of the request. Templates guard their kses input, so this is belt and braces;
        // re-hooking is idempotent (/ship red team, RT4).
        if ($caught && function_exists('wp_pre_kses_block_attributes')) {
            add_filter('pre_kses', 'wp_pre_kses_block_attributes', 10, 3);
        }
        restore_error_handler();
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        $shortcode_tags = $saved_shortcodes;
    }
    if ($html === null || trim($html) === '') {
        return null;
    }
    // A band whose markup is past the per-band bound, or past what is left of the call's budget, is not
    // parsed: unknown (the caller keeps the finding and says it was not checked, naming size).
    if (strlen($html) > PP_UDC_PRESENCE_MARKUP_BAND || strlen($html) > $markup_left) {
        // Its OWN bound, or what the bands before it left of the call's (cycle 3, api-contract: the note
        // must not blame a small band for the budget its neighbours used).
        $why = strlen($html) > PP_UDC_PRESENCE_MARKUP_BAND ? 'size' : 'budget';
        return null;
    }
    $markup_left -= strlen($html);
    $dom      = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded   = $dom->loadHTML('<?xml encoding="utf-8"?><div id="pp-presence-root">' . $html . '</div>');
    // A PARSE THAT GAVE UP IS UNKNOWN (adversarial pass): libxml stops at 256 nesting levels (author
    // HTML can nest that deep) and still returns true, which read every later role as absent and dropped
    // a real clash silently. Any fatal parse error answers null instead.
    $fatal = false;
    foreach (libxml_get_errors() as $parse_error) {
        if ($parse_error->level === LIBXML_ERR_FATAL) {
            $fatal = true;
            break;
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || $fatal) {
        return null;
    }
    $xpath = new \DOMXPath($dom);
    $band  = $xpath->query('//*[@data-pp-band="' . $id . '"]');
    if ($band === false || $band->length === 0) {
        return null;
    }
    $root = $band->item(0);
    // ONE WALK, ONE INDEX (cycle 2, red team): every element under the band by class and by tag, so each
    // role is matched against its candidates instead of a whole-subtree scan per role (the per-role
    // XPath scans made a 2,400-card render cost 260-346 ms).
    $by_class = [];
    $by_tag   = [];
    foreach ($root->getElementsByTagName('*') as $el) {
        $by_tag[strtolower($el->nodeName)][] = $el;
        foreach (preg_split('/\s+/', trim($el->getAttribute('class'))) as $class) {
            if ($class !== '') {
                $by_class[$class][] = $el;
            }
        }
    }
    $out       = [];
    $item_memo = [];
    foreach ($roles as $role => $selector) {
        $steps = _pp_udc_selector_steps((string) $selector);
        if ($steps === null) {
            return null; // A selector outside the small grammar: do not guess.
        }
        $last       = $steps[count($steps) - 1];
        $candidates = $last['classes'] !== [] ? ($by_class[$last['classes'][0]] ?? []) : ($by_tag[$last['tag']] ?? []);
        $found      = [];
        $memo       = [];
        foreach ($candidates as $candidate) {
            if (_pp_udc_steps_match($candidate, $steps, count($steps) - 1, $root, $memo)) {
                $found[] = $candidate;
            }
        }
        $items = [];
        foreach ($found as $node) {
            $card = _pp_udc_enclosing_item($node, $root, $item_memo);
            if ($card !== '') {
                $items[$card] = true;
            }
        }
        $out[(string) $role] = ['band' => $found !== [], 'items' => $items];
    }
    return $out;
}

/**
 * A role selector as matching steps (#1125, E1-A): [['axis' => 'descendant'|'child', 'tag' => '*'|name,
 * 'classes' => [...], 'attrs' => [...]], ...]. Role selectors are schema-owned and charset-gated
 * (_pp_udc_selector_is_emittable(): `[A-Za-z0-9_ .>[]-]`, presence-only attribute terms), so the grammar
 * is compounds of an optional tag, `.class` and `[attr]` terms, joined by descendant spaces or `>`.
 * Anything else returns null (callers then do not guess).
 *
 * @return array<int, array{axis: string, tag: string, classes: string[], attrs: string[]}>|null
 */
function _pp_udc_selector_steps(string $selector): ?array {
    $selector = trim((string) preg_replace('/\s*>\s*/', ' > ', $selector));
    if ($selector === '') {
        return null;
    }
    $steps = [];
    $axis  = 'descendant';
    foreach (preg_split('/\s+/', $selector) as $token) {
        if ($token === '>') {
            if ($steps === []) {
                return null;
            }
            $axis = 'child';
            continue;
        }
        if (!preg_match('/^([A-Za-z][A-Za-z0-9]*)?((?:\.[A-Za-z0-9_-]+|\[[A-Za-z][A-Za-z0-9_-]*\])*)\z/', $token, $m) || $token === '') {
            return null;
        }
        preg_match_all('/\.([A-Za-z0-9_-]+)|\[([A-Za-z][A-Za-z0-9_-]*)\]/', $m[2], $terms, PREG_SET_ORDER);
        $classes = [];
        $attrs   = [];
        foreach ($terms as $term) {
            if (($term[1] ?? '') !== '') {
                $classes[] = $term[1];
            } else {
                $attrs[] = $term[2];
            }
        }
        $steps[] = ['axis' => $axis, 'tag' => $m[1] !== '' ? strtolower($m[1]) : '*', 'classes' => $classes, 'attrs' => $attrs];
        $axis    = 'descendant';
    }
    return $steps;
}

/** Whether a DOM element matches one compound step (tag, every class, every attribute). */
function _pp_udc_step_matches(\DOMElement $el, array $step): bool {
    if ($step['tag'] !== '*' && strtolower($el->nodeName) !== $step['tag']) {
        return false;
    }
    if ($step['classes'] !== []) {
        $have = array_flip(preg_split('/\s+/', trim($el->getAttribute('class'))));
        foreach ($step['classes'] as $class) {
            if (!isset($have[$class])) {
                return false;
            }
        }
    }
    foreach ($step['attrs'] as $attr) {
        if (!$el->hasAttribute($attr)) {
            return false;
        }
    }
    return true;
}

/**
 * Whether `$el` matches steps[0..$i], matching right to left inside `$root` (the band, which is the scope
 * the renderer prefixes: `[data-pp-band] .selector`), as CSS does.
 *
 * LINEAR IN NESTING DEPTH (/ship security specialist). A descendant step used to climb every ancestor of
 * every candidate, so author HTML nested 240 deep (libxml's limit is 256) around many links cost one
 * 480 KB band 8.3 s. "Some ancestor matches steps[0..$i]" is memoised up the chain
 * (_pp_udc_ancestor_matches()), which is what makes a pass O(elements x steps) whatever the depth (a
 * planted-defect run proved it); the per-(element, step) answer is memoised too, a constant-factor cache. A memo entry holds its node, so the wrapper cannot be freed and its object id reused by
 * another node inside the pass.
 */
function _pp_udc_steps_match(\DOMElement $el, array $steps, int $i, \DOMElement $root, array &$memo = []): bool {
    $key = 'm' . $i . ':' . spl_object_id($el);
    if (isset($memo[$key])) {
        return $memo[$key][1];
    }
    if (!_pp_udc_step_matches($el, $steps[$i])) {
        $match = false;
    } elseif ($i === 0) {
        $match = $el !== $root;
    } elseif ($steps[$i]['axis'] === 'child') {
        $parent = $el->parentNode;
        $match  = $parent instanceof \DOMElement && $parent !== $root && _pp_udc_steps_match($parent, $steps, $i - 1, $root, $memo);
    } else {
        $match = _pp_udc_ancestor_matches($el, $steps, $i - 1, $root, $memo);
    }
    $memo[$key] = [$el, $match];
    return $match;
}

/**
 * The `data-pp-item` of the nearest element at or above `$el` inside `$root`, '' for none: the card a
 * matched element belongs to. Memoised per element across the whole read (linear in depth, like the
 * matcher: a per-node climb cost O(matches x depth) on deep author HTML).
 */
function _pp_udc_enclosing_item(\DOMElement $el, \DOMElement $root, array &$memo): string {
    $key = spl_object_id($el);
    if (isset($memo[$key])) {
        return $memo[$key][1];
    }
    if ($el === $root) {
        $card = '';
    } elseif ($el->hasAttribute('data-pp-item')) {
        $card = $el->getAttribute('data-pp-item');
    } else {
        $parent = $el->parentNode;
        $card   = $parent instanceof \DOMElement ? _pp_udc_enclosing_item($parent, $root, $memo) : '';
    }
    $memo[$key] = [$el, $card];
    return $card;
}

/** Whether some ancestor of `$el` strictly inside `$root` matches steps[0..$i] (memoised with the matcher). */
function _pp_udc_ancestor_matches(\DOMElement $el, array $steps, int $i, \DOMElement $root, array &$memo): bool {
    $key = 'a' . $i . ':' . spl_object_id($el);
    if (isset($memo[$key])) {
        return $memo[$key][1];
    }
    $parent = $el->parentNode;
    $match  = $parent instanceof \DOMElement && $parent !== $root
        && (_pp_udc_steps_match($parent, $steps, $i, $root, $memo) || _pp_udc_ancestor_matches($parent, $steps, $i, $root, $memo));
    $memo[$key] = [$el, $match];
    return $match;
}

/**
 * The specificity of a selector the renderer prints, as [ids, classes/attributes/pseudo-
 * classes, types] (#1125). The selectors come from _pp_udc_emitted_selector() over schema-owned
 * role selectors (charset-gated by _pp_udc_selector_is_emittable()) and engine-owned scopes, so
 * the grammar is small: `:where(...)` contributes nothing; attributes, classes and pseudo-
 * classes count in the middle column; element names in the last.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function _pp_udc_selector_specificity(string $selector): array {
    $rest    = (string) preg_replace('/:where\((?:[^()]|\([^()]*\))*\)/', ' ', $selector);
    $middle  = 0;
    $rest    = (string) preg_replace_callback('/\[[^\]]*\]/', static function () use (&$middle): string {
        $middle++;
        return ' ';
    }, $rest);
    $ids     = preg_match_all('/#[A-Za-z0-9_-]+/', $rest);
    $rest    = (string) preg_replace('/#[A-Za-z0-9_-]+/', ' ', $rest);
    $middle += preg_match_all('/\.[A-Za-z0-9_-]+/', $rest);
    $rest    = (string) preg_replace('/\.[A-Za-z0-9_-]+/', ' ', $rest);
    $types   = preg_match_all('/::[A-Za-z-]+/', $rest);
    $rest    = (string) preg_replace('/::[A-Za-z-]+/', ' ', $rest);
    $middle += preg_match_all('/:[A-Za-z-]+/', $rest);
    $rest    = (string) preg_replace('/:[A-Za-z-]+/', ' ', $rest);
    $types  += preg_match_all('/(?:^|[\s>+~])[A-Za-z][A-Za-z0-9-]*/', $rest);
    return [(int) $ids, $middle, (int) $types];
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
    [$scope, $root_scope] = _pp_udc_emission_scopes($component, '', true)['defaults'];

    return _pp_udc_render_blocks($compiled, $scope, $root_scope, 'pp-zero')
        . _pp_udc_overlay_tier_css($component);
}

/**
 * The roles the overlay tier re-lights, as prompt prose (#1010 review): "hero `title-accent`,
 * cta `heading-accent`, ...". DERIVED from the schemas' `overlay_defaults`, the same data
 * _pp_udc_overlay_tier_css() compiles, so the runtime prompt cannot name a role the engine
 * does not re-light or miss one it does. Same posture as pp_udc_chrome_own_ink_summary().
 */
function pp_udc_overlay_tier_summary(): string {
    $parts = [];
    foreach (array_keys(pp_composable_components()) as $component) {
        $component = (string) $component;
        foreach (pp_udc_component_roles($component) as $role_name => $definition) {
            if (isset($definition['overlay_defaults']) && is_array($definition['overlay_defaults'])
                && $definition['overlay_defaults'] !== []) {
                $parts[] = $component . ' `' . $role_name . '`';
            }
        }
    }
    return implode(', ', $parts);
}

/**
 * The OVERLAY TIER of a component's role defaults (#1010, ruling D4 = B).
 *
 * A band that paints an image under a scrim is marked `data-pp-band-overlay` by the engine
 * (#986), and the focus ring already re-lights off that marker. A role may declare
 * `overlay_defaults` in its schema — values that replace its `defaults` on such a band. The
 * shipped use is the accent inks: `--color-accent` measured 1.05:1 over a dark scrim, and
 * `--color-accent-on-overlay` is the theme's ink tuned for exactly that surface.
 *
 * RANKED BY WEIGHT AND ORDER, prototyped in Chromium before it was built:
 *
 *   element default   [data-pp-component="X"] .role                           (0,2,0)
 *   overlay tier      :where([data-pp-component="X"])[data-pp-band-overlay] .role (0,2,0), later
 *   authored          [data-pp-band="b"] .role                                (0,2,0), later still
 *
 * so the tier beats the default it re-lights on source order, and anything the author wrote
 * for that role still wins. It is compiled through the AUTHORED layer of the one engine — the
 * same grammar, reference resolution and state/breakpoint handling a band's own map gets —
 * under a placeholder id the render never prints, and rendered under the overlay scope. The
 * engine names no component: which roles re-light is data, in the schema.
 */
function _pp_udc_overlay_tier_css(string $component): string {
    $compiled = _pp_udc_overlay_tier_compile($component);
    if ($compiled === null) {
        return '';
    }
    [$overlay_scope] = _pp_udc_emission_scopes($component, '', true)['overlay'];
    return _pp_udc_render_blocks($compiled, $overlay_scope);
}

/**
 * The overlay tier of a component's role defaults, COMPILED (#1125): the one compile both the
 * overlay-tier CSS above and pp_udc_role_paint() read, or null when no role declares
 * `overlay_defaults`.
 */
function _pp_udc_overlay_tier_compile(string $component): ?array {
    $overlay_map = [];
    foreach (pp_udc_component_roles($component) as $role_name => $definition) {
        if (isset($definition['overlay_defaults']) && is_array($definition['overlay_defaults'])
            && $definition['overlay_defaults'] !== []) {
            $overlay_map[(string) $role_name] = $definition['overlay_defaults'];
        }
    }
    if ($overlay_map === []) {
        return null;
    }
    $compiled = pp_udc_compile_band(
        ['component' => $component, 'id' => 'pp-00000000', 'udc' => $overlay_map],
        'authored'
    );
    return $compiled['id'] === '' ? null : $compiled;
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
/**
 * The selector one compiled block emits under (Addendum B3).
 *
 * ONE BUILDER, TWO CALLERS, AND THAT IS THE WHOLE POINT. The rule emitter and
 * the reduced-motion guard must produce IDENTICAL selector text for the same
 * block, because the guard neutralizes by printing later at equal weight. Two
 * hand-rolled concatenations is how they drift, and the drift is silent: the
 * page looks right and the accessibility affordance is simply gone. That is
 * not hypothetical — the guard already lost this way once on the STATE axis,
 * and the comment at its call site records it.
 *
 * FOUR SHAPES, and each earns its line:
 *
 *   band root   [data-pp-band="b"]                              (0,1,0)
 *   band role   [data-pp-band="b"] .role                        (0,2,0)
 *   item root   [data-pp-band="b"] [data-pp-item="i"]           (0,2,0)
 *   item role   [data-pp-band="b"] [data-pp-item="i"] .role     (0,3,0)
 *
 * The item ROOT tying the band ROLE at (0,2,0) is the case B3 settles by source
 * order, and it is why the item tier is appended after the role loop rather
 * than interleaved with it.
 *
 * THE ID IS RE-GATED HERE, not trusted from the compiler. This text goes
 * straight into a CSS attribute selector, and the compiler is not the only way
 * a block reaches this function in future. pp_udc_valid_item_id() bounds it to
 * `it-` plus eight hex digits, so nothing that reaches the stylesheet can close
 * the attribute or the rule. A malformed id emits NO item scope at all rather
 * than a partial one — an empty `[data-pp-item=""]` would match every id-less
 * card on the page and cross-apply one card's design to all of them, which is
 * the same cross-apply hazard the band id's own empty case guards.
 */
function _pp_udc_emitted_selector(
    string $scope,
    string $root_scope,
    string $role_selector,
    string $item = ''
): string {
    if ($item === '' || !pp_udc_valid_item_id($item)) {
        return $role_selector === '' ? $root_scope : $scope . ' ' . $role_selector;
    }
    $item_scope = $scope . ' [data-pp-item="' . $item . '"]';
    return $role_selector === '' ? $item_scope : $item_scope . ' ' . $role_selector;
}

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
                    $block_item = (string) ($block['item'] ?? '');
                    foreach ($block['decls'] as $property => $entry) {
                        $decls .= $property . ':' . $entry['css'] . ';';
                        // KEYED BY SELECTOR, STATE AND ITEM. The state axis came first and its history is the
                        // half that is easy to drop. A guard emitted without it
                        // lands at `[data-pp-band] .role` [0,2,0] while the rule
                        // it must neutralize is `[data-pp-band] .role:hover`
                        // [0,3,0] — so the guard LOSES on specificity and a
                        // reduced-motion user still gets the full transition on
                        // hover. Matching the state puts both at equal weight,
                        // where printing later is enough.
                        // KEYED BY SELECTOR, STATE **AND ITEM**, and the item is the
                        // third half that is easy to drop for exactly the reason the
                        // state was. A guard emitted without it lands at
                        // `[data-pp-band] .role` [0,2,0] while the rule it must
                        // neutralize is `[data-pp-band] [data-pp-item="…"] .role`
                        // [0,3,0] — so the guard LOSES on specificity and a
                        // reduced-motion user still gets the item's transition. The
                        // fix is the same one the state axis took: match the emitted
                        // selector exactly, where printing later is enough.
                        if (isset($motion_properties[$property])) {
                            $motion_selectors[$block_item . "\0" . $block['selector'] . "\0" . $state]
                                = [$block['selector'], $state, $block_item];
                        }
                    }
                    if ($decls === '') {
                        continue;
                    }
                    $is_root  = $block['selector'] === '';
                    $selector = _pp_udc_emitted_selector(
                        $scope, $root_scope, $block['selector'], $block_item
                    ) . $state;
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
    foreach ($motion_selectors as $entry) {
        [$selector, $state] = $entry;
        $item    = (string) ($entry[2] ?? '');
        // AN ITEM-TIER RULE IS AN ELEMENT RULE even when its role selector is
        // empty. An empty selector means "the band root" for the band tier, but
        // for the item tier it means "the card", which is an element INSIDE the
        // band — so routing it to the root bucket would put the guard in the
        // wrong cascade layer, where it cannot reach the declarations it exists
        // to neutralize.
        $is_root = $selector === '' && $item === '';
        if (($want === 'root' && !$is_root) || ($want === 'element' && $is_root)) {
            continue;
        }
        $selectors[] = _pp_udc_emitted_selector($scope, $root_scope, $selector, $item) . $state;
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
            // `id` as the readiness probe wraps it (pp_check_udc_emit_drops): chrome renders
            // under its own selector, so the findings walk must not read it as an id-less band.
            $items[] = ['component' => $name, 'id' => $name, 'udc' => $site['chrome'][$name]];
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
    $scopes = _pp_udc_emission_scopes($name, $name);
    // Chrome's defaults tier splits exactly like a band's and for the same
    // reason (#986, ruling D5 revised): its zeroed root tier was ranked under the
    // shared header/footer rules by printing first, and layering the v1
    // stylesheet would have inverted that. `pp-zero` keeps it underneath
    // structurally.
    return $layer === 'defaults'
        ? _pp_udc_render_blocks($compiled, $scopes['defaults'][0], $scopes['defaults'][1], 'pp-zero')
        : _pp_udc_render_blocks($compiled, $scopes['authored'][0], $scopes['authored'][1]);
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

    // ── ITEM IDS (Addendum B2) — the band rule, one level down ──────────────
    //
    // Run as its own pass, after every band id is settled, because the two
    // lifecycles are independent: an item id is scoped to its band, so it does
    // not care which id its band ended up with, and interleaving them would
    // make that independence hard to see.
    foreach ($incoming as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        $component = isset($item['component']) && is_scalar($item['component'])
            ? (string) $item['component']
            : '';
        if ($component === '') {
            continue;
        }
        $declaration = pp_udc_item_roles($component);
        if ($declaration === null) {
            continue; // Declares no item grain: stored shape untouched, per B2.
        }
        $prop    = $declaration['prop'];
        $entries = $item['props'][$prop] ?? null;
        if (!is_array($entries)) {
            continue;
        }

        // UNIQUENESS IS WITHIN THE BAND, NOT GLOBAL, and that is B2's own
        // ruling rather than a shortcut: the emitted selector is always
        // band-scoped (`[data-pp-band] [data-pp-item]`), so two bands may each
        // hold an item called `it-7b2c91d4` without either one reaching the
        // other. Scoping the claim set per band is what makes that true in the
        // minter as well as in the emitter.
        $claimed_items = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry[PP_UDC_ITEM_ID_KEY])
                && is_scalar($entry[PP_UDC_ITEM_ID_KEY])) {
                $id = (string) $entry[PP_UDC_ITEM_ID_KEY];
                if (pp_udc_valid_item_id($id)) {
                    $claimed_items[$id] = true;
                }
            }
        }

        $stored_entries = [];
        if (isset($stored[$i]) && is_array($stored[$i])
            && isset($stored[$i]['component']) && is_scalar($stored[$i]['component'])
            && (string) $stored[$i]['component'] === $component
            && isset($stored[$i]['props'][$prop]) && is_array($stored[$i]['props'][$prop])) {
            $stored_entries = $stored[$i]['props'][$prop];
        }

        foreach ($entries as $k => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $map     = $entry[PP_UDC_ITEM_MAP_KEY] ?? null;
            $has_map = is_array($map) && $map !== [];

            // AN ITEM WITH NO MAP CARRIES NO ID, AND THE CLEARING HALF IS NOT
            // OPTIONAL. B2 says items with no `udc` map get no id and no
            // attribute; honouring that only at MINT time would let an id
            // outlive the map that justified it, so a card whose design was
            // deleted would keep emitting a `data-pp-item` attribute with no
            // rules behind it — a handle to nothing, and a contradiction of the
            // clause in the same paragraph that states it.
            if (!$has_map) {
                if (isset($entry[PP_UDC_ITEM_ID_KEY])) {
                    unset($incoming[$i]['props'][$prop][$k][PP_UDC_ITEM_ID_KEY]);
                }
                continue;
            }

            $current = isset($entry[PP_UDC_ITEM_ID_KEY]) && is_scalar($entry[PP_UDC_ITEM_ID_KEY])
                ? (string) $entry[PP_UDC_ITEM_ID_KEY]
                : '';
            if ($current !== '' && pp_udc_valid_item_id($current)) {
                continue; // Honoured, never overwritten.
            }

            // Carried forward by INDEX, the band rule one level down. The
            // component match the band rule also demands is already satisfied:
            // this loop only runs when the stored band at this index is the
            // same component.
            $carried_item = '';
            if (isset($stored_entries[$k]) && is_array($stored_entries[$k])
                && isset($stored_entries[$k][PP_UDC_ITEM_ID_KEY])
                && is_scalar($stored_entries[$k][PP_UDC_ITEM_ID_KEY])) {
                $candidate = (string) $stored_entries[$k][PP_UDC_ITEM_ID_KEY];
                if (pp_udc_valid_item_id($candidate) && !isset($claimed_items[$candidate])) {
                    $carried_item = $candidate;
                }
            }

            $assigned = $carried_item !== '' ? $carried_item : pp_generate_item_id();
            while (isset($claimed_items[$assigned])) {
                $assigned = pp_generate_item_id();
            }
            $claimed_items[$assigned] = true;
            $incoming[$i]['props'][$prop][$k][PP_UDC_ITEM_ID_KEY] = $assigned;
        }
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

/**
 * May this role's schema bytes be composed onto a model-facing surface? (#1087)
 *
 * ONE GATE FOR EVERY COMPOSER, and it exists because the first cut had two composers and
 * gated one. The security review probed it: a nav role carrying an unknown definition key
 * was correctly suppressed from the obligation roster and STILL appeared by name in the
 * chrome-ink roster, in the same prompt build. That is the write/render-disagreement shape
 * this repo has already recorded once — in an engine where stored bytes reach the emitter by
 * several paths, every gate must exist on all of them or they disagree.
 *
 * TWO CHECKS, because the review showed the definition gate alone guards the wrong field.
 * The NAME is composed onto the same line as the values and was bounded nowhere; `with` —
 * the same identifier from the other end — was bounded. So the name is checked here, and the
 * definition is delegated to the validator that owns definition shape.
 *
 * EVERY admin.php symbol THIS function touches is probed, and only those. It reaches exactly
 * two — PP_ROLE_NAME_PATTERN and pp_schema_definition_errors() — so those two are guarded and
 * nothing else is. The first cut also probed pp_udc_is_single_line(), which this function
 * never calls; a load probe for a symbol on no code path here is noise that reads as rigour.
 */
function _pp_udc_role_is_composable(string $component, string $role, $definition): bool {
    if (!is_array($definition)) {
        return false;
    }
    if (!defined('PP_ROLE_NAME_PATTERN')) {
        return false;
    }
    if (!preg_match(PP_ROLE_NAME_PATTERN, $role)) {
        return false;
    }
    return !function_exists('pp_schema_definition_errors')
        || pp_schema_definition_errors($definition, 'role', "{$component} role {$role}") === [];
}

/**
 * The obligation records ONE role contributes, or [] when it contributes none (#1087).
 *
 * A SEAM, extracted so the fail-safe path is reachable from a test. The shapes this has to
 * survive — a malformed record, an unknown kind, a dangling partner, a non-list container —
 * exist on a HAND-EDITED install, not in the shipped schemas, so a test that could only go
 * through the real registry could never reach them without swapping the theme root. The
 * behaviour being pinned is "renders nothing, warns nothing, fatals nothing", and an
 * untested fail-safe is not one.
 *
 * `$siblings` is passed in rather than re-read so the caller's single registry walk is the
 * only one: re-reading pp_udc_component_roles() per entry would turn one pass into N. It is a
 * MAP keyed by role name, not a list, so the partner check is a hash hit rather than a scan.
 *
 * @param array<string, true> $siblings  Role names this component declares, as keys.
 * @return array<int, array{kind: string, with: string, why: string, pair: string}>
 */
function _pp_udc_role_obligation_records(
    string $component,
    string $role,
    array $definition,
    array $siblings
): array {
    // THE SKIP IS THE POINT, and it is a DELEGATION rather than a re-derivation — the
    // pattern pp_ai_format_applies_when_clause() was corrected into after its first draft
    // re-derived the grammar, accepted shapes the validator rejects, and emitted a PHP
    // warning into the prompt buffer while its docblock promised it never guessed. A role
    // whose definition does not validate contributes NOTHING rather than contributing
    // garbage to a prompt. function_exists because a partial include must degrade to
    // rendering nothing, not fatal.
    if (!_pp_udc_role_is_composable($component, $role, $definition)) {
        return [];
    }

    $kinds = function_exists('pp_udc_obligation_kinds') ? pp_udc_obligation_kinds() : [];
    if ($kinds === []) {
        return [];
    }

    $obligations = $definition['obligations'] ?? [];
    if (!is_array($obligations)) {
        return [];
    }

    $records = [];
    foreach ($obligations as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $kind = $entry['kind'] ?? null;
        $with = $entry['with'] ?? null;
        $why  = $entry['why']  ?? null;
        if (!is_string($kind) || !is_string($with) || !is_string($why)
            || !in_array($kind, $kinds, true)) {
            continue;
        }
        // A partner the component does not declare would compose a prompt sentence about a
        // role the model cannot write to — worse than silence, because it would try and be
        // refused with `unknown_udc_role`. The schema walk fails CI on this; here is the
        // runtime half, for the hand-edited install the CI walk never sees.
        if (!isset($siblings[$with])) {
            continue;
        }
        $records[] = [
            'kind' => $kind,
            'with' => $with,
            'why'  => $why,
            // The display form the prompt roster composes. `with` travels beside it so no
            // consumer has to split this string back apart to recover the partner.
            'pair' => "{$component}.{$role} -> {$with}",
        ];
    }
    return $records;
}

/**
 * Every declared role obligation, grouped for the runtime prompt (#1087).
 *
 * THE CHANNEL THIS EXISTS FOR. A role's schema `description` is never injected into the
 * system prompt — #1059 is the measured proof of what that costs, a 3.21:1 contrast
 * failure with the warning sitting in a field nobody reads. The in-admin chat AI has no
 * tools, no function calling and no way to fetch a schema mid-turn, so an obligation that
 * is not in the prompt is an obligation that model does not have. This is how the
 * obligations reach it.
 *
 * DERIVED, never restated, for the reason pp_udc_group_summary()'s docblock gives above.
 * The paragraph in lib/ai-context.php keeps its hand-written ARGUMENT — why the cascade
 * behaves this way is prose a reader needs — and takes its ROSTER from here, so a pair
 * added by a later rebuild reaches the authoring model on the day it lands rather than
 * whenever someone remembers to edit a sentence. Five hand-maintained rosters in this
 * repo's model-facing docs were stale at the time this was written; every pinned one was
 * correct. That is the whole argument for deriving it.
 *
 * WALKS THE FULL REGISTRY, not pp_composable_components(), and this is deliberate rather
 * than careless: EIGHT of the sixteen shipped records — half of them — are on `nav` and
 * `footer`, which the prompt's component catalog deliberately EXCLUDES (chrome is not composable, and listing
 * it there is what led an agent to compose duplicate chrome in #223). A summary built
 * inside that catalog loop would silently omit exactly the chrome pairs a dark-header
 * author most needs. The registry read is memoised per theme root
 * (pp_get_registered_components), so this is one in-memory pass.
 *
 * GROUPED BY IDENTICAL `why`, because the six rich-text container/link pairs share one
 * instruction word for word. Emitting it six times would spend ~800 bytes of every
 * conversation turn restating a sentence the model already read; grouping states the pairs
 * once and the instruction once.
 *
 * FAIL-SAFE BY DELEGATION, the pattern pp_ai_format_applies_when_clause() was corrected
 * into after its first draft emitted a PHP "Array to string conversion" warning into the
 * prompt buffer. A malformed record is SKIPPED, never rendered and never fataled, because
 * pp_schema_definition_errors() is a repo-CI invariant and not a runtime gate
 * (lib/admin.php's own docblock says so) — a hand-edited schema on a live install reaches
 * this function unvalidated. Guarded with function_exists so a partial include degrades to
 * rendering nothing rather than fataling.
 *
 * Assembled from _pp_udc_role_obligation_records(), which owns the per-role fail-safe;
 * this function owns only the walk and the grouping.
 *
 * @return array<string, array<int, array{pairs: string[], why: string}>>
 *         kind => list of {pairs, why} groups. Empty when nothing is declared.
 */
function pp_udc_obligation_groups(): array {
    $out = [];
    foreach (array_keys(pp_get_registered_components()) as $component) {
        $roles = pp_udc_component_roles($component);
        // HOISTED, and the sibling set is a MAP rather than a list. Rebuilding array_keys()
        // inside the role loop and then scanning it with in_array() per record are two
        // O(roles^2) terms on what is now a per-chat-turn path. Invisible at the shipped
        // maximum of 19 roles, and measured superlinear the moment roles-per-component grows
        // (2.2-2.7x per doubling, flattened to ~1.9x by this change). This repo's recorded
        // quadratic-validator incident is the same shape: harmless until something put it on
        // a per-request path.
        $siblings = array_fill_keys(array_keys($roles), true);
        foreach ($roles as $role => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            foreach (_pp_udc_role_obligation_records($component, $role, $definition, $siblings) as $record) {
                $out[$record['kind']][$record['why']][] = $record['pair'];
            }
        }
    }

    $grouped = [];
    foreach ($out as $kind => $by_why) {
        foreach ($by_why as $why => $pairs) {
            $grouped[$kind][] = ['pairs' => $pairs, 'why' => (string) $why];
        }
    }
    return $grouped;
}

/**
 * Is `$inner_def`'s selector a descendant of `$outer_selector` that an authored value
 * cannot reach? (#1087)
 *
 * The predicate behind the net documented on pp_udc_derived_descendant_pairs() below.
 * Extracted so its NEAR-MISSES are testable: a guard tested only on what it should catch is
 * a guard whose false-positive rate is unmeasured, and this repo has already shipped one
 * whose trigger matched ordinary prose.
 *
 * @param string $outer_selector The containing role's selector.
 * @param array  $inner_def      The candidate inner role's full definition.
 */
function _pp_udc_is_derivable_descendant(string $outer_selector, array $inner_def): bool {
    $inner_selector = trim((string) ($inner_def['selector'] ?? ''));
    if ($inner_selector === '' || $outer_selector === '') {
        return false;
    }

    // CONTAINMENT: the inner selector continues the outer one ACROSS A COMBINATOR.
    //
    // Deliberately NOT a regex. Escaping the outer selector into one would need PHP's
    // pattern-escaping helper, whose NAME carries a substring this file is forbidden to
    // contain — UdcEngineTest asserts the engine names no component or role, and it tests
    // that by substring over comment-stripped source. A plain prefix test is also cheaper
    // and says the rule more directly.
    //
    // The boundary character is the whole point: it is what stops `.faq__heading` being read
    // as containing `.faq__heading-accent`. Those select DIFFERENT elements, and a bare
    // substring test reports them as a pair — the false-positive class this repo has already
    // paid for once, in a guard whose trigger matched ordinary prose and passed 46% of its
    // subjects by accident.
    if (!str_starts_with($inner_selector, $outer_selector)) {
        return false;
    }
    $boundary = substr($inner_selector, strlen($outer_selector), 1);
    if ($boundary === '' || !in_array($boundary, [' ', "\t", '>', '+', '~'], true)) {
        return false;
    }

    // Arm 1 — the inner role declares its own typography, so a value inherited from the
    // outer role loses to it.
    if (!empty($inner_def['defaults']['typography']) && is_array($inner_def['defaults']['typography'])) {
        return true;
    }

    // Arm 2 — the inner role targets an ANCHOR, which base.css gives a direct colour rule
    // whatever the role declares. WITHOUT THIS ARM THE NET SEES 6 OF 13 PAIRS, all chrome,
    // and misses every pair #1069 was filed about. The `(?:^|[\s>+~])` prefix is what keeps
    // it from reading a class that merely ends in the letter `a` (`.media`) as an anchor.
    return (bool) preg_match('/(?:^|[\s>+~])a$/', $inner_selector);
}

/**
 * The descendant pairs a declaration SHOULD exist for — a one-directional net (#1087).
 *
 * WHAT IT IS AND WHAT IT IS NOT. This is a safety net over the `obligations` declarations,
 * not a source of them. It answers "is there a pair here that obviously needs a
 * declaration and has none?" and it CANNOT answer the reverse, because the declarations
 * cover cases no selector analysis can see. Treating its output as the complete roster
 * would be the drift this gate exists to end, arriving through a derivation instead of
 * through prose.
 *
 * WHY ONE-DIRECTIONAL, stated with the evidence rather than as a caveat. One shipped
 * obligation is INVISIBLE here, and it was found by reading MARKUP, not selectors:
 *
 *   footer.social -> social-link   The markup (components/footer/footer.php:148-152) nests
 *                                  `<a class="site-footer__social-link">` inside
 *                                  `<ul class="site-footer__social">`, but the two
 *                                  SELECTORS express no containment at all. Nothing
 *                                  derivable from selectors can know the anchors are in
 *                                  there, and `social-link` does declare its own muted
 *                                  colour and accent hover.
 * A SECOND BLIND SPOT, not currently exercised: `.faq__item[open] > .faq__question` does
 * not begin with `.faq__item` followed by a combinator, so this predicate cannot see that
 * containment either. `faq.item` declares no obligation today — the faq pairing that ships
 * is `question -> question-open`, which is the OTHER kind and outside this net entirely —
 * so nothing is missing right now. It is recorded because the shape is real and the next
 * component to use an attribute-qualified ancestor will land in it.
 *
 * THE ANCHOR ARM IS NOT OPTIONAL, and this is the correction that matters most. A net keyed
 * only on "the descendant declares a typography default" finds SIX of the thirteen shipped
 * descendant pairs — all six of them chrome — and misses every pair #1069 was actually filed
 * about. The six composable `*-link` roles declare NO defaults, deliberately, so that an
 * unauthored link keeps the site's normal anchor treatment; their obligation comes from
 * base.css giving every `<a>` a DIRECT colour rule, which beats an inherited value whatever
 * the layer. So a descendant whose last compound is `a` counts whether or not it declares a
 * default.
 *
 * NOT ON ANY RUNTIME PATH, and it must stay that way without someone re-measuring first.
 * Its only caller is the schema walk in the test suite. It compares every ORDERED PAIR of
 * roles per component, which the performance specialist measured as cleanly quadratic — 4x
 * per doubling of roles-per-component, 137ms at 3072 roles, against 15ms for the roster walk
 * that IS composed into the prompt. Wiring it into pp_ai_system_prompt() would put a
 * quadratic growth law on every chat turn with nothing bounding roles-per-component. If that
 * is ever wanted, index roles by selector prefix instead of comparing all pairs.
 *
 * @return array<int, array{component: string, role: string, with: string}>
 */
function pp_udc_derived_descendant_pairs(): array {
    $pairs = [];
    foreach (array_keys(pp_get_registered_components()) as $component) {
        $roles = pp_udc_component_roles($component);
        foreach ($roles as $outer => $outer_def) {
            $outer_selector = is_array($outer_def) ? (string) ($outer_def['selector'] ?? '') : '';
            if ($outer_selector === '' || $outer === '_band') {
                continue;
            }
            foreach ($roles as $inner => $inner_def) {
                if ($inner === $outer || !is_array($inner_def)) {
                    continue;
                }
                // The predicate reads and empty-checks the inner selector itself, on the
                // TRIMMED value — a second check here was the weaker of the two.
                if (!_pp_udc_is_derivable_descendant($outer_selector, $inner_def)) {
                    continue;
                }
                $pairs[] = ['component' => $component, 'role' => $outer, 'with' => $inner];
            }
        }
    }
    return $pairs;
}

/**
 * The chrome roles that declare their OWN ink, grouped by component (#1087).
 *
 * THE CLAIM THIS REPLACES WAS FALSE, and measurably so. The chrome paragraph said "THE ONE
 * PAIRING THAT IS STILL MANDATORY" and named a single role — while eleven chrome roles
 * declare their own `typography.color`, so a background change reaches NONE of them. On the
 * prompt's own worked example fill (#101828) the footer's five muted-ink roles measure
 * 3.08:1, under the 4.5:1 AA floor. A count whose roster names one member is the #1045
 * shape exactly, and it was in the runtime prompt.
 *
 * DERIVED, so the answer is a fact about the schemas. A rebuild that adds a text role with
 * a default colour adds it here on the day it lands.
 *
 * SCOPED TO CHROME on purpose: a band's text roles are covered by the dark-band paragraph,
 * which already tells an author to colour every text role. Chrome is the surface where the
 * prompt asserted the opposite.
 */
function pp_udc_chrome_own_ink_summary(): string {
    $lines = [];
    foreach (pp_udc_chrome_names() as $component) {
        $named = [];
        foreach (pp_udc_component_roles($component) as $role => $definition) {
            // THE SAME GATE THE OBLIGATION COMPOSER USES. This path had none, so a role the
            // other roster correctly suppressed still reached the prompt by name here.
            if (!_pp_udc_role_is_composable($component, (string) $role, $definition)) {
                continue;
            }
            $typography = $definition['defaults']['typography'] ?? [];
            if (!is_array($typography)) {
                continue;
            }
            // A resting colour OR a state colour: either one is ink the role owns and a
            // background change will not move.
            $has_rest  = array_key_exists('color', $typography);
            $has_state = false;
            foreach ([':hover', ':focus-visible', ':active'] as $state) {
                if (isset($typography[$state]['color'])) {
                    $has_state = true;
                }
            }
            if ($has_rest || $has_state) {
                $named[] = $role;
            }
        }
        if ($named !== []) {
            $lines[] = $component . ': ' . implode(', ', $named);
        }
    }
    return implode('; ', $lines);
}

/**
 * One obligation kind rendered as prompt prose, or '' when nothing is declared (#1087).
 *
 * THE EMPTY ANSWER IS A REAL ANSWER. Returning '' lets the caller suppress the roster
 * sentence entirely rather than emit a paragraph that asserts instances exist and then
 * names none — which is what the hand-written stopgap this replaces did on its way to
 * going stale ("THE INSTANCE THAT SHIPS TODAY IS faq", true when written).
 */
function pp_udc_obligation_summary(string $kind): string {
    // A TEST-FACING CONVENIENCE, not the prompt path. Kept because three tests read one kind
    // in isolation and the two-call expression adds nothing there; named here so nobody reads
    // its existence as evidence that production composes rosters one kind at a time.
    return pp_udc_format_obligation_groups(pp_udc_obligation_groups()[$kind] ?? []);
}

/**
 * One kind's group list rendered as prompt prose, or '' when it is empty (#1087).
 *
 * SPLIT OUT SO THE WALK RUNS ONCE. pp_udc_obligation_summary() below builds the whole
 * both-kinds map and indexes one kind out of it, so calling it once per kind — which the
 * prompt DID, before this split — walked all 125 roles TWICE and threw half the work away:
 * 250 record extractions and 278 validator calls where 125 and 153 suffice. Measured by the
 * pre-landing performance specialist at 0.185ms of a 0.650ms warm build, 28%, and 44% of
 * everything this gate added to a cold build. The prompt now calls pp_udc_obligation_groups()
 * once and this formatter per kind; the summary wrapper remains for tests only.
 *
 * A FORMATTER RATHER THAN A CACHE, deliberately. A `static` memo would have been fewer lines
 * and would have needed the theme-root keying and invalidate handshake
 * pp_get_registered_components() carries — and this repo has already paid for a stale
 * per-root cache leaking across test classes. Computing once at the call site has no
 * invalidation to get wrong.
 */
function pp_udc_format_obligation_groups(array $groups): string {
    if ($groups === []) {
        return '';
    }
    $parts = [];
    foreach ($groups as $group) {
        $parts[] = implode(', ', $group['pairs']) . ' — ' . $group['why'];
    }
    return implode(' ', $parts);
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
 * Returns human-readable labels: a label view over
 * _pp_udc_preset_values_shadowed_entries(), compared per state AND per breakpoint tier
 * (a partial loss is labelled "at breakpoint …"). The findings walk uses the entries
 * directly, so it can subtract what the author covers per tier before wording them.
 *
 * @return array<int, string>  e.g. ['typography.color', 'typography.color (:hover)']
 */
function _pp_udc_preset_values_shadowed_by_role_defaults(array $fragment, array $role_def): array {
    return array_map(
        '_pp_udc_shadow_entry_label',
        _pp_udc_preset_values_shadowed_entries($fragment, $role_def)
    );
}

/**
 * The shadow entries behind the labels above: [base label, lost tiers, preset tiers], so a
 * caller can subtract what the author covers PER TIER before wording the label (#1116).
 *
 * @return array<int, array{0: string, 1: array<int, string>, 2: array<int, string>}>
 */
function _pp_udc_preset_values_shadowed_entries(array $fragment, array $role_def): array {
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
                foreach ($value as $param => $state_value) {
                    if (isset($state_defaults[(string) $param])) {
                        $entry = _pp_udc_shadow_entry_for_tiers(
                            $group . '.' . (string) $param . ' (' . $key . ')',
                            $state_value,
                            $state_defaults[(string) $param]
                        );
                        if ($entry !== null) {
                            $shadowed[] = $entry;
                        }
                    }
                }
                continue;
            }

            if (isset($group_defaults[$key])) {
                $entry = _pp_udc_shadow_entry_for_tiers($group . '.' . $key, $value, $group_defaults[$key]);
                if ($entry !== null) {
                    $shadowed[] = $entry;
                }
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
    // BOUNDED ACROSS THE WHOLE COMPOSITION, not per band — the precedent this cap cites
    // is the emit-drop ledger, and `$drops` is shared across every band for exactly this
    // reason. Scoped per item it bounded nothing that matters: measured at 400 bands ×
    // 400 unknown properties, 80,000 findings and +56 MB in one call, on the
    // `wp pp check page` and restore paths its own comment names as having no size gate
    // in front of them. Found by the adversarial pass.
    $css_disclosed = 0;
    // HOISTED FOR THE SAME REASON, and the first cut of the item arm got this wrong
    // while quoting the paragraph above: scoped per BAND, a bound of 200 delivers 200
    // PER BAND. Measured at 400 cards on each of 50 bands: 10,000 findings and +8 MB
    // from a counter whose own comment claimed 200.
    $item_disclosed   = 0;
    $tokens_disclosed = 0;
    // THE SAME BOUND FOR EVERY ARM THAT WALKS ITEM MAPS (#1116, #1117). Each multiplies by
    // the card count, and `items` declares no maximum: measured at 50 bands x 20 cards,
    // 3,000 overlay findings and 1,000 each of the two preset disclosures before these.
    $skipped_disclosed = 0;
    $shadow_disclosed  = 0;
    $overlay_disclosed = 0;
    // PER-CALL MEMOS for the two arms whose per-reference work depends only on the preset
    // (and the role): without them, one large preset referenced from every card is walked
    // once per card. Per call, not static, so a preset saved mid-request is never stale.
    $overlay_preset_memo = [];
    $shadow_memo         = [];
    $relit_disclosed     = 0;
    // #1125: its own budget, and the component defaults compile the role-paint accessor reads,
    // memoised per call (per component: a band-independent constant of this request).
    $ink_disclosed       = 0;
    $role_paint_defaults = [];
    // THE RENDER BUDGET (ruling E1-A cost condition): the on-the-page check renders a band through its
    // template only when a finding is about to fire, at most this many bands per call. Measured: +3.2 ms
    // on a realistic 12-band write with two firing bands (one a 30-card grid); one render of a 200-card
    // grid is 4.8 ms. The bound is Check 8e's named lever class (25 bands). Past it, presence is unknown
    // and the finding keeps its unfiltered answer: unknown is never silence (evidence-t2/compile-cost).
    $presence_renders_left = PP_UDC_PRESENCE_RENDERS;
    // AND BY SIZE (ruling R2-A): a render costs in proportion to its cards (a 2,400-card band measured
    // 260-346 ms), so the budget also counts rendered cards per call. A band past either budget is not
    // rendered: its findings stay unfiltered and SAY so (a named note), never silence.
    $presence_cards_left   = PP_UDC_PRESENCE_CARDS;
    // AND BY MARKUP (/ship security specialist): parsing costs in proportion to the rendered bytes, and a
    // string prop counts 0 cards, so 25 bands just under the per-band bound were 12.5 MB of parsing. The
    // call parses at most this much in total; see _pp_udc_rendered_roles().
    $presence_markup_left  = PP_UDC_PRESENCE_MARKUP_CALL;

    foreach ($items as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        $component = isset($item['component']) && is_scalar($item['component'])
            ? (string) $item['component']
            : '';
        if ($component === '' || !pp_udc_is_v2_component($component)) {
            continue;
        }

        // A BAND WITH NO MAP OF ITS OWN STILL HAS ITEMS TO DISCLOSE (#1101,
        // Addendum B4). This used to `continue` on an absent `udc`, which was
        // correct while the walk had one subject and is the SAME early exit
        // pp_udc_normalize_band() had to correct for the same reason: the owner's
        // live design styles CARDS and leaves the band alone, so the shape this
        // skipped is not an edge case, it is the headline one.
        //
        // MEASURED BEFORE THE FIX, through the real write path, on identical item
        // data: a band carrying one unrelated band-level value disclosed
        // `udc_item_value_shadowed_by_role_default`; the same three cards on a band
        // with no map of its own disclosed NOTHING. The five-write trap — darken a
        // card, forget its title ink — went unreported in exactly the arrangement an
        // author reaches it through.
        //
        // NORMALISED RATHER THAN BRANCHED, because every band-tier arm below either
        // iterates this map or asks whether it is an array; an empty map makes all of
        // them no-ops without a second condition on each one. Nothing is written back
        // — `$item` is a by-value copy of one composition entry — so this changes what
        // is REPORTED and never what is stored.
        // RESOLVED ONCE PER BAND. Four arms below need this and each rebuilt it, so
        // pp_udc_item_maps() — which walks the whole repeater and re-validates the
        // `item_roles` declaration on every call — ran two to four times per band.
        $band_item_maps = pp_udc_item_maps($item);

        if (!isset($item['udc']) || !is_array($item['udc'])) {
            if ($band_item_maps === []) {
                continue; // No band map and no item map: genuinely nothing to say.
            }
            $item['udc'] = [];
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
        //
        // WALKED AT BOTH GRAINS (#1101, Addendum B4: "`udc_preset_groups_skipped`
        // applies at item grain unchanged"). The first cut walked the band map
        // alone, so a preset referenced from inside an items[] entry partially
        // applied in SILENCE — measured on byte-identical data, `card-media` ->
        // `_preset: "button"` disclosed the skipped `typography` on a band map and
        // disclosed NOTHING on an item map. The grain an author writes at must not
        // decide whether they are told what landed.
        //
        // ONE PREDICATE, TWO GRAINS, which is A3 clause 4 rather than a style
        // preference: `_pp_udc_split_preset_by_permitted()` is the same call the
        // COMPILER makes, so a second copy of this split here would let a write say
        // "typography skipped" while the emitter painted it.
        $roles = pp_udc_component_roles($component);
        $preset_maps = [['', $item['udc']]];
        foreach ($band_item_maps as $preset_item_id => $preset_item_map) {
            $preset_maps[] = [(string) $preset_item_id, $preset_item_map];
        }
        // A CARD MAP SPEAKS ONLY FOR THE COMPONENT'S ITEM ROLES. The emitter drops any other
        // role at item grain whole ("not settable on a single item"), so the preset arms
        // below must not advise writing a value there — one predicate with the compiler,
        // which walks exactly this list.
        $preset_item_roles = (array) (pp_udc_item_roles($component)['roles'] ?? []);
        foreach ($preset_maps as [$locator, $map]) {
            if ($skipped_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                break; // Capped: stop the WORK too, not only the output.
            }
            foreach ($map as $role_name => $role_map) {
                if (!is_array($role_map) || !isset($role_map[PP_UDC_PRESET_KEY])
                    || !is_string($role_map[PP_UDC_PRESET_KEY]) || !isset($roles[(string) $role_name])
                    || ($locator !== '' && !in_array((string) $role_name, $preset_item_roles, true))) {
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
                if ($skipped_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                    break 2;
                }
                $skipped_disclosed++;
                $findings[] = [
                    'type'    => 'udc_preset_groups_skipped',
                    'message' => sprintf(
                        'Component "%s"%s role "%s": the preset "%s" also declares %s, which this role does '
                        . 'not permit, so %s not applied. Applied: %s.',
                        $component,
                        $locator === '' ? '' : sprintf(' item "%s"', _pp_udc_reflect($locator)),
                        (string) $role_name,
                        $role_map[PP_UDC_PRESET_KEY],
                        implode(', ', $split['skipped']),
                        count($split['skipped']) === 1 ? 'it was' : 'they were',
                        implode(', ', array_keys($split['applied']))
                    ),
                    'index'   => is_int($i) ? $i : null,
                ];
            }
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
        //
        // BOTH GRAINS, FROM THE SAME LIST (#1116). This walked `$item['udc']` alone while
        // its sibling above was widened at #1101, so a card-level `_preset` whose values the
        // role also defaults was accepted, stored, reported ok:true with findings:[], and
        // painted nothing — the exact sentence that sibling's comment says must not happen.
        // It now reads `$preset_maps`, the band map plus every item map, and names the card.
        //
        // AND BOTH PRESET GRAINS. A `_preset` inside a group (`"typography": {"_preset": …}`)
        // ranks under the role's defaults exactly like a role-grain one, so it loses the
        // same parameters; its fragment is resolved at that group and checked as a one-group
        // map, and the message names the group.
        foreach ($preset_maps as [$locator, $map]) {
            if ($shadow_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                break; // Capped: stop the WORK too, not only the output.
            }
            foreach ($map as $role_name => $role_map) {
                if (!is_array($role_map) || !isset($roles[(string) $role_name])
                    || ($locator !== '' && !in_array((string) $role_name, $preset_item_roles, true))) {
                    continue;
                }
                // [preset name, role-shaped fragment, group or '' for role grain]
                $preset_refs = [];
                if (isset($role_map[PP_UDC_PRESET_KEY]) && is_string($role_map[PP_UDC_PRESET_KEY])) {
                    $preset   = pp_udc_resolve_preset($role_map[PP_UDC_PRESET_KEY]);
                    $fragment = $preset === null ? null : _pp_udc_preset_fragment($preset, 'role');
                    if (is_array($fragment) && $fragment !== []) {
                        $preset_refs[] = [$role_map[PP_UDC_PRESET_KEY], $fragment, ''];
                    }
                }
                // Only a group this role PERMITS can be shadowed (the helper skips the rest),
                // so a stored map's other keys are not resolved at all — a raw row can carry
                // thousands of them.
                $permitted_groups = (array) ($roles[(string) $role_name]['groups'] ?? []);
                foreach ($role_map as $group_name => $group_map) {
                    if (!is_array($group_map) || !isset($group_map[PP_UDC_PRESET_KEY])
                        || !is_string($group_map[PP_UDC_PRESET_KEY])
                        || !in_array((string) $group_name, $permitted_groups, true)) {
                        continue;
                    }
                    $preset   = pp_udc_resolve_preset($group_map[PP_UDC_PRESET_KEY]);
                    $fragment = $preset === null ? null : _pp_udc_preset_fragment($preset, (string) $group_name);
                    if (is_array($fragment) && $fragment !== []) {
                        $preset_refs[] = [$group_map[PP_UDC_PRESET_KEY], [(string) $group_name => $fragment], (string) $group_name];
                    }
                }
                // The author's own labels, once per role map (not once per reference).
                $authored_labels = $preset_refs === [] ? [] : _pp_udc_authored_value_labels($role_map);
                foreach ($preset_refs as [$preset_name, $fragment, $preset_group]) {
                    $shadow_key = $component . "\0" . $role_name . "\0" . $preset_group . "\0" . $preset_name;
                    if (!array_key_exists($shadow_key, $shadow_memo)) {
                        $shadow_memo[$shadow_key] = _pp_udc_preset_values_shadowed_entries(
                            $fragment,
                            $roles[(string) $role_name]
                        );
                    }
                    // A value THIS map already sets paints — the author's own value outranks
                    // both the preset and the default — so it is not "not applied".
                    // PER TIER: an authored value covers the lost tiers it names (all of them
                    // when it paints at the base tier); what is left is still lost.
                    $shadowed = [];
                    foreach ($shadow_memo[$shadow_key] as [$entry_label, $entry_lost, $entry_tiers]) {
                        $covered = $authored_labels[$entry_label] ?? [];
                        if ($covered === true) {
                            continue;
                        }
                        $still_lost = array_values(array_diff($entry_lost, $covered));
                        if ($still_lost !== []) {
                            $shadowed[] = _pp_udc_shadow_entry_label([$entry_label, $still_lost, $entry_tiers]);
                        }
                    }
                    if ($shadowed === []) {
                        continue;
                    }
                    if ($shadow_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                        break 3;
                    }
                    $shadow_disclosed++;
                    $total = count($shadowed);
                    $findings[] = [
                        'type'    => 'udc_preset_value_shadowed_by_role_default',
                        'message' => sprintf(
                            'Component "%s"%s role "%s"%s: the preset "%s" sets %s, but this role\'s own default '
                            . 'for %s outranks a preset, so %s not applied. Write the value in your own map '
                            . 'for this role, where it out-ranks both.',
                            $component,
                            $locator === '' ? '' : sprintf(' item "%s"', _pp_udc_reflect($locator)),
                            (string) $role_name,
                            $preset_group === '' ? '' : sprintf(' group "%s"', _pp_udc_reflect($preset_group)),
                            _pp_udc_reflect($preset_name),
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
            }
        }

        // THE OVERLAY TIER'S RESIDUAL, DISCLOSED (#1010 review, 7A ruling 1 = A). On a band the
        // engine marks overlaid, the accent inks re-light to the near-white on-overlay ink on
        // the premise that they sit on a dark scrim. Authored shapes that break the premise
        // are named rather than outguessed: a scrim that leaves some widths unscrimmed; a
        // scrim that is light, partly transparent, or unreadable; and a light (or unreadable)
        // surface, at rest or in a state, on the accent itself or on a role that ENCLOSES it
        // (schema data, `within`), so a light button beside the heading is not named.
        //
        // READ OFF THE COMPILED BAND, NEVER RE-DERIVED (design ruling, the #1117 doctrine).
        // Four review cycles each found a place where a hand-written resolver drifted from the
        // emitter (its responsive mint names, its site-token scope for presets, its
        // per-breakpoint merge of author and preset tiers, its state buckets). This arm now
        // compiles the band once through pp_udc_compile_band() and reads the declarations the
        // page will carry: the copy cannot drift because there is no copy. The same compile
        // serves the dropped-overlay disclosure below. Authored wins: an accent whose resting
        // ink the compiled band declares at the base tier is not re-lit and not named.
        $band_compiled = null;
        $band_drops    = [];
        $raw_probe_failed = false; // the collision arm's compile threw once for this band: do not retry it per collision
        $tier_roles    = [];
        foreach ($roles as $role_name => $definition) {
            if (isset($definition['overlay_defaults']) && is_array($definition['overlay_defaults']) && $definition['overlay_defaults'] !== []) {
                $tier_roles[] = (string) $role_name;
            }
        }
        $band_has_id = isset($item['id']) && is_scalar($item['id']) && pp_udc_valid_band_id((string) $item['id']);
        if ($tier_roles !== [] && $band_has_id && $relit_disclosed < PP_UDC_MAX_EMIT_DROPS
            && is_array($item['udc']['_band'] ?? null) && _pp_udc_map_may_carry_overlay($item['udc']['_band'], 0, false, $overlay_preset_memo)) {
            try {
                $band_compiled = pp_udc_compile_band($item, 'authored', $band_drops);
            } catch (\Throwable $e) {
                error_log('PromptingPress: off-scrim findings probe failed for band ' . (string) $item['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
                $band_compiled = null;
                $band_drops    = [];
            }
        }
        // The marker's own predicate, from the one accessor both read: some width paints a
        // scrim. An image-only band (an overlay the emitter dropped, e.g. one set only inside
        // `:hover`) is not marked, so nothing re-lights and there is nothing to disclose.
        $effective = $band_compiled === null ? null : pp_udc_band_effective_background($band_compiled);
        if ($effective !== null && _pp_udc_effective_paints_scrim($effective)) {
            $blocks = (array) ($band_compiled['blocks'] ?? []);
            $rest_inked = [];
            foreach ($blocks as $block) {
                if (($block['item'] ?? '') === '' && ($block['state'] ?? '') === '' && ($block['bp'] ?? '') === 'd'
                    && isset($block['decls']['color'])) {
                    $rest_inked[(string) $block['role']] = true;
                }
            }
            $relit = array_values(array_filter($tier_roles, static fn (string $r): bool => !isset($rest_inked[$r])));
            if ($relit !== []) {
                $conditions  = []; // [condition text, the re-lit roles it concerns]
                $breakpoints = pp_udc_breakpoints();
                $covered     = [];
                foreach ($effective['tiers'] as $bp => $tier) {
                    if ($tier['image'] && $tier['scrim'] !== '') {
                        $covered[] = (string) $bp;
                    }
                }
                $missing = array_values(array_diff(array_keys($breakpoints), $covered));
                // ONE LIGHTNESS RULE, ONE HELPER (PR-2 review, design ruling A). The re-lit accent exists to read on
                // dark. The three conditions that put it on a background other than the scrim (a width where a
                // background replaced the image, a scrim sized over part of the band, and a surface on or around the
                // accent further down) are named only where that background is light or unreadable, read with
                // _pp_udc_value_is_light(). A readably dark background is the design working: firing there is the
                // refused false-alarm class. A new condition of this kind goes through this gate, not a rule of its
                // own. (The unscrimmed-image width is not gated: the engine cannot read an image; see #1152.)
                // NO COLOUR IS NOT DARK (red team RT1, ruling A): a background that paints no surface, or whose colours all
                // sit under the scrim's minimum alpha, shows whatever is behind the band, so it counts as unreadable here.
                // Only a readable, opaque-enough dark colour silences a condition. The alpha floor covers both halves:
                // everything _pp_udc_paints_surface() calls no surface (transparent, none, initial, unset, a zero-alpha
                // colour) carries no colour at or over it, so a separate surface check here would be dead code.
                $accent_may_not_read = static function ($bp) use ($band_compiled): bool {
                    $own = _pp_udc_band_own_background($band_compiled, (string) $bp);
                    if ($own === null) {
                        return true;
                    }
                    $opaque = array_filter(_pp_udc_value_colours($own, []), static fn (array $c): bool => $c[3] >= PP_UDC_SCRIM_MIN_ALPHA);
                    return $opaque === [] || _pp_udc_value_is_light($own, []) !== false;
                };
                if ($covered !== [] && $missing !== []) {
                    // Where the uncovered width has no image (a raw background won there, #1141), the accent sits on
                    // the band's own background, not on an image (PR-2 review, security).
                    // BY CAUSE (PR-2 review, design): "the scrim is set only at ..." is true only for a width with an
                    // image and no scrim. Where the image itself is gone, the author may well have set the scrim; what
                    // replaced it is a background set at that width, raw (`_css`, #1141) or through the group.
                    // Where the image is gone, the accent sits on the background that replaced it, so those widths are
                    // named only where that background is light or unreadable (the lightness gate, below).
                    $imaged   = array_values(array_filter($missing, static fn ($k): bool => !empty($effective['tiers'][$k]['image'])));
                    $replaced = array_values(array_filter(array_diff($missing, $imaged), $accent_may_not_read));
                    $raw_won  = array_values(array_filter($replaced, static fn ($k): bool => !empty($effective['tiers'][$k]['raw'])));
                    $filled   = array_values(array_diff($replaced, $raw_won));
                    $parts    = [];
                    if ($imaged !== []) {
                        $parts[] = sprintf('the scrim is set only at the %s, so at the %s the accent sits on the unscrimmed image',
                            _pp_udc_widths_phrase($covered), _pp_udc_widths_phrase($imaged));
                    }
                    if ($raw_won !== []) {
                        $parts[] = sprintf('at the %s the raw background in _css replaces the image and its scrim, so the accent sits on that background',
                            _pp_udc_widths_phrase($raw_won));
                    }
                    if ($filled !== []) {
                        $parts[] = sprintf('at the %s the background you set there replaces the image and its scrim, so the accent sits on that background',
                            _pp_udc_widths_phrase($filled));
                    }
                    if ($parts !== []) {
                        $conditions[] = [implode(', and ', $parts), $relit];
                    }
                }
                foreach ($effective['tiers'] as $tier) {
                    [$layers, $source] = [$tier['scrim'], $tier['source']];
                    if ($layers === '') {
                        continue;
                    }
                    $scrim       = _pp_udc_compiled_value($layers, $band_compiled);
                    $scrim_label = 'the scrim' . ($source === '' || strncmp($source, 'preset:', 7) !== 0 ? ' you set' : _pp_udc_source_phrase($source));
                    $colours     = _pp_udc_value_colours($scrim, []);
                    $shown       = _pp_udc_reflect(_pp_udc_compiled_display($layers, $band_compiled));
                    if ($colours === []) {
                        $conditions[] = [sprintf('the engine cannot read %s (%s), so it cannot tell whether it is dark', $scrim_label, $shown), $relit];
                        break;
                    }
                    if (_pp_udc_value_is_light($scrim, []) === true) {
                        $conditions[] = [sprintf('%s is light (%s)', $scrim_label, $shown), $relit];
                        break;
                    }
                    if (array_filter($colours, static fn (array $c): bool => $c[3] < PP_UDC_SCRIM_MIN_ALPHA) !== []) {
                        $conditions[] = [sprintf('%s (%s) is transparent in part, so part of the band shows the unscrimmed image', $scrim_label, $shown), $relit];
                        break;
                    }
                }
                // A SCRIM ON PART OF THE BAND (#1142 item 1): sized without tiling, so the rest of the band
                // shows its own background under the re-lit accent. The marker stays (R4): disclosed, not unset.
                // Each size is named in the author's terms (band tokens put back, as the conditions beside
                // it show them) with the widths it holds at (PR-2 review, maintainability).
                $partial_by_size = []; // shown size => [bp, ...], in breakpoint order
                foreach ($effective['tiers'] as $bp => $tier) {
                    if (!empty($tier['partial']) && $accent_may_not_read($bp)) {
                        $partial_by_size[_pp_udc_reflect(_pp_udc_compiled_display((string) $tier['size'], $band_compiled))][] = (string) $bp;
                    }
                }
                if ($partial_by_size !== []) {
                    $sized = [];
                    foreach ($partial_by_size as $shown_size => $size_bps) {
                        $sized[] = $shown_size . ' without tiling' . (count($size_bps) === count($breakpoints) ? '' : ' at the ' . _pp_udc_widths_phrase($size_bps));
                    }
                    $conditions[] = [sprintf('the image and its scrim are sized %s, so part of the band shows its own background instead of the scrim (size the image cover, or let it tile, and the scrim covers the band)',
                        implode(' and ', $sized)), $relit];
                }
                // A `_band` state that repaints the background (`background[":hover"].fill`)
                // covers the scrimmed image in that state while the tier keeps re-lighting.
                foreach ($effective['states'] as $state) {
                    $conditions[] = [sprintf('in the %s state the band\'s own background replaces the scrimmed image', _pp_udc_reflect($state)), $relit];
                    break;
                }
                // A surface on the accent itself or on a role that encloses it, as compiled.
                $enclosing = [];
                foreach ($relit as $accent) {
                    $enclosing[$accent][] = $accent; // a highlighter behind the accent word
                    foreach ((array) ($roles[$accent]['within'] ?? []) as $outer) {
                        if (is_string($outer) && $outer !== '' && $outer !== $accent) {
                            $enclosing[$outer][] = $accent;
                        }
                    }
                }
                $named_surface = [];
                foreach ($blocks as $block) {
                    $role_name = (string) ($block['role'] ?? '');
                    $locator   = (string) ($block['item'] ?? '');
                    if (!isset($enclosing[$role_name]) || isset($named_surface[$locator . '|' . $role_name])) {
                        continue;
                    }
                    $where = ($locator === '' ? '' : sprintf('item "%s" ', _pp_udc_reflect($locator))) . sprintf('role "%s"', $role_name)
                        . ', ' . (in_array($role_name, $relit, true) ? 'the accent itself' : 'which encloses it');
                    foreach (['background', 'background-color', 'background-image'] as $property) {
                        $decl = $block['decls'][$property] ?? null;
                        if (!is_array($decl) || !is_string($decl['css'] ?? null)) {
                            continue;
                        }
                        $css    = $decl['css'];
                        $origin = strncmp((string) ($decl['source'] ?? ''), 'preset:', 7) === 0
                            ? ltrim(_pp_udc_source_phrase((string) $decl['source'])) : 'you set';
                        if (stripos($css, 'url(') !== false) {
                            $conditions[] = [sprintf('%s, has a background image %s, whose lightness the engine cannot read', $where, $origin), $enclosing[$role_name]];
                            $named_surface[$locator . '|' . $role_name] = true;
                            break;
                        }
                        // ONE SURFACE CLASSIFIER WITH #1125 (#1142 item 3): transparent, none, initial, unset and a
                        // zero-alpha colour paint no surface. currentColor and inherit DO paint one, so they stay
                        // named, each with words that are true of it (premise correction in #1142's body).
                        $resolved_css = _pp_udc_compiled_value($css, $band_compiled);
                        if (!_pp_udc_paints_surface($resolved_css, $property === 'background-image' ? 'background-image' : 'background-color')) {
                            continue;
                        }
                        $keyword = strtolower(trim($resolved_css));
                        if ($keyword === 'currentcolor' || $keyword === 'inherit') {
                            $conditions[] = [sprintf('%s, has a background %s of %s, %s', $where, $origin, $keyword === 'inherit' ? 'inherit' : 'currentColor',
                                $keyword === 'inherit' ? 'which takes its parent\'s background, and the engine cannot read that'
                                    : 'which paints its own text colour behind the text'), $enclosing[$role_name]];
                            $named_surface[$locator . '|' . $role_name] = true;
                            break;
                        }
                        if (_pp_udc_value_is_light($resolved_css, []) !== false) {
                            $conditions[] = [sprintf('%s, has a background %s (%s) that is light or that the engine cannot read',
                                $where, $origin, _pp_udc_reflect(_pp_udc_compiled_display($css, $band_compiled))), $enclosing[$role_name]];
                            $named_surface[$locator . '|' . $role_name] = true;
                            break;
                        }
                    }
                }
                foreach ($conditions as [$condition, $concerned]) {
                    if ($relit_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                        break;
                    }
                    $relit_disclosed++;
                    $findings[] = [
                        'type'    => 'udc_overlay_accent_off_scrim',
                        'message' => sprintf(
                            'Component "%s": this band paints a scrim over its image, so %s %s to the near-white '
                            . '@color-accent-on-overlay, but %s. Where the accent sits on that surface, set its '
                            . 'typography.color yourself: your value wins.',
                            $component,
                            count($concerned) === 1 ? 'role' : 'roles',
                            implode(', ', array_map(static fn ($r) => '"' . $r . '"', $concerned))
                                . (count($concerned) === 1 ? ' re-lights' : ' re-light'),
                            $condition
                        ),
                        'index'   => is_int($i) ? $i : null,
                    ];
                }
            }
        }

        // A ROLE'S OWN SURFACE UNDER A NEW INK (#1125, ruling D5 = B; landed on the compiled band,
        // Sprint 3 T2). Darken a band, recolour a role's text, and a role that ships its OWN
        // background keeps that surface under the new ink: the eyebrow pill measured 1.76:1 on a
        // write reporting `findings: []`. Named, not measured: no contrast maths.
        //
        // READ, NEVER RE-DERIVED. The first cut read the author's map and preset fragments and was
        // withdrawn (a90a93c): a role DEFAULT outranks a preset, so it called a shadowed preset fill
        // "covering" (missing the exact trap) and a shadowed preset ink "painting" (contradicting
        // the shadowed-preset disclosure on the same write). This arm asks pp_udc_role_paint(), the
        // renderer-owned answer to which ink and which surface paint on each element, per card,
        // state and width. It fires where the ink that paints is the AUTHOR's (the band or item
        // tier: the map, `_css`, or a preset the author applied that actually won) and the surface
        // that paints under it is the ROLE DEFAULT's (the defaults or overlay tier).
        //
        // GATED ON AN AUTHORED BAND SURFACE (D5 = B), read off the same compile: a `_band` block
        // declaring a background whose source is the author's map or a preset the author applied
        // (presets a role DEFAULT names are inert, see pp_udc_compile_band()). A light band keeping
        // its light roles is the design working, not a trap.
        //
        // The band is compiled at most once for every arm here: this reuses the off-scrim compile
        // and, when it has to compile itself, keeps the drop ledger the #1117 arm below reads.
        $band_udc_map = is_array($item['udc']['_band'] ?? null) ? $item['udc']['_band'] : [];
        $raw_band_css = is_array($band_udc_map[PP_UDC_CSS_KEY] ?? null) ? $band_udc_map[PP_UDC_CSS_KEY] : [];
        // A NECESSARY CONDITION, never the decider (the compiled check below decides). It reads a
        // raw `_css` state map too: `{"_css": {":hover": {"background": ...}}}` emits exactly what the
        // group's state spelling emits, and two spellings of one page must get one answer (red team,
        // cycle 1).
        $raw_css_keys = array_map('strval', array_keys($raw_band_css));
        foreach (pp_udc_states() as $raw_state => $unused_state) {
            if (is_array($raw_band_css[$raw_state] ?? null)) {
                $raw_css_keys = array_merge($raw_css_keys, array_map('strval', array_keys($raw_band_css[$raw_state])));
            }
        }
        $may_author_band_surface = isset($band_udc_map['background']) || isset($band_udc_map[PP_UDC_PRESET_KEY])
            || array_intersect($raw_css_keys, ['background', 'background-color', 'background-image']) !== [];
        if ($band_has_id && $may_author_band_surface && $ink_disclosed < PP_UDC_MAX_EMIT_DROPS) {
            try {
                if ($band_compiled === null) {
                    $band_drops    = [];
                    $band_compiled = pp_udc_compile_band($item, 'authored', $band_drops);
                }
                if (!isset($role_paint_defaults[$component])) {
                    $role_paint_defaults[$component] = pp_udc_compile_band(['component' => $component], 'defaults');
                }
                // THE BAND SURFACE MUST PAINT AND DIFFER FROM THE DEFAULT (ruling D = A, /ship red team). A
                // `transparent` section band or a hero band set to its own @color-bg darkens nothing, and naming
                // the pill there advised a repaint on a correct light design. The authored `_band` longhand opens
                // the gate only when it paints (_pp_udc_paints_surface()) and is not the value the defaults tier
                // puts in the same state and width: the restated-default rule, from ink to surface. Its mirror
                // is below (ruling E): a restated ROLE fill does not clear the finding.
                $default_band = []; // state => bp => longhand => css
                foreach ((array) ($role_paint_defaults[$component]['blocks'] ?? []) as $block) {
                    if (($block['role'] ?? '') === '_band' && ($block['item'] ?? '') === '') {
                        foreach ((array) ($block['decls'] ?? []) as $property => $decl) {
                            if (is_array($decl) && is_string($decl['css'] ?? null)) {
                                foreach (_pp_udc_paint_longhands((string) $property, $decl) as $longhand => [$css]) {
                                    $default_band[(string) ($block['state'] ?? '')][(string) ($block['bp'] ?? 'd')][$longhand] = $css;
                                }
                            }
                        }
                    }
                }
                $band_surface_authored = false;
                foreach ((array) ($band_compiled['blocks'] ?? []) as $block) {
                    if (($block['role'] ?? '') !== '_band' || ($block['item'] ?? '') !== '') {
                        continue;
                    }
                    $b_state = (string) ($block['state'] ?? '');
                    $b_bp    = (string) ($block['bp'] ?? 'd');
                    foreach (['background', 'background-color', 'background-image'] as $property) {
                        $decl   = $block['decls'][$property] ?? null;
                        $source = (string) ($decl['source'] ?? '');
                        if (!is_array($decl) || !is_string($decl['css'] ?? null) || ($source !== 'udc' && strncmp($source, 'preset:', 7) !== 0)) {
                            continue;
                        }
                        foreach (_pp_udc_paint_longhands($property, $decl) as $longhand => [$css, $literal]) {
                            if (!_pp_udc_paints_surface($literal, $longhand)) {
                                continue;
                            }
                            $default_css = $default_band[$b_state][$b_bp][$longhand] ?? $default_band[$b_state]['d'][$longhand]
                                ?? $default_band[''][$b_bp][$longhand] ?? $default_band['']['d'][$longhand] ?? null;
                            if ($default_css === null || !_pp_udc_same_surface(_pp_udc_compiled_value($css, $band_compiled), $default_css)) {
                                $band_surface_authored = true;
                                break 3;
                            }
                        }
                    }
                }
                if ($band_surface_authored) {
                    $marked = !pp_udc_is_chrome($component) && pp_udc_band_paints_scrim($band_compiled);
                    $breakpoints_meta = pp_udc_breakpoints();
                    // AN INK THE ROLE INHERITS FROM THE BAND (ruling D3 = A). A role that declares no
                    // colour of its own takes the band's by inheritance; hero `surface` rendered white on
                    // its own light fill (1.07:1) with the write silent. ONE PREDICATE WITH THE SIBLING:
                    // _pp_udc_inherited_values_cancelled_by_role_defaults() over what the band EMITS
                    // (_pp_udc_band_inherited_emitted(), R1-A: any spelling, per state and width), so the band
                    // colour reaches a role exactly when udc_band_value_shadowed_by_role_default does not list
                    // it and the two findings on one write agree by construction. The sibling reads the REST
                    // cells of the same map (ruling 1 = A); the state cells are this arm's band-state cells
                    // (A1 = A). A shipped-schema sweep in Chromium found no enclosing role that intercepts that
                    // inheritance (evidence-t2).
                    $band_emitted     = _pp_udc_band_inherited_emitted($band_compiled);
                    $band_ink_cells   = $band_emitted['color'] ?? [];
                    $band_ink_reaches = [];
                    if ($band_ink_cells !== []) {
                        $band_cancelled = _pp_udc_inherited_values_cancelled_by_role_defaults($item['udc'], $component, '_band', null, $band_emitted)['color'] ?? [];
                        foreach (array_keys($roles) as $reach_role) {
                            if ((string) $reach_role !== '_band' && !in_array((string) $reach_role, $band_cancelled, true)) {
                                $band_ink_reaches[(string) $reach_role] = true;
                            }
                        }
                    }
                    // ONLY WHERE THE INK CAN SHOW (ruling A): a role whose own element renders author text, schema
                    // data set by a Chromium measurement (`text_content`). A container whose text roles all set their
                    // own colour (faq `item`, grid `card`, table `head`) takes the ink on its element and shows it on
                    // no glyph: naming it was a false alarm whose advice (darken the container) put dark default text
                    // on a dark fill. The accessor answers only these roles (/ship performance).
                    $text_roles = [];
                    foreach ($roles as $text_role => $text_def) {
                        if (($text_def['text_content'] ?? false) === true) {
                            $text_roles[(string) $text_role] = true;
                        }
                    }
                    $paint_elements = pp_udc_role_paint($item, $band_compiled, $role_paint_defaults[$component], $marked, $text_roles);
                    // A SURFACE THAT RESTATES THE DEFAULT IS THE DEFAULT (ruling E = A, /ship red team): a role fill
                    // the author copied from the schema's default paints the same light pill, so it must not clear
                    // the finding. "Cleared but not fixed" is the dishonesty D and E close from both directions.
                    // EVERY SPELLING, ONE ANSWER (ruling F = A): a breakpoint-map fill is minted into a band token on
                    // write and a `_tokens` fill is one, so the band's tokens are put back before comparing, as the
                    // off-scrim arm does (_pp_udc_compiled_value()).
                    // Returns the DEFAULT surface that paints in this cell (restated or not), or null.
                    $default_surface_in = static function (array $cell) use ($band_compiled): ?array {
                        $surface = $cell['surface'] ?? null;
                        if ($surface === null) {
                            return null;
                        }
                        if (in_array($surface['tier'], ['defaults', 'overlay'], true)) {
                            return ['css' => (string) $surface['css'], 'restated' => false];
                        }
                        $default = $cell['default_surface'] ?? null;
                        return $default !== null && _pp_udc_same_surface(_pp_udc_compiled_value((string) $surface['css'], $band_compiled), (string) $default['css'])
                            ? ['css' => (string) $default['css'], 'restated' => true] : null;
                    };
                    // A CARD PART UNDER AN INKED CARD ROOT takes the card's ink, not the band's. No shipped
                    // schema has such a subject: band-ink subjects are pinned by
                    // RoleInkOverOwnSurfaceTest::testNoShippedItemPartYetTakesTheBandInkThroughItsCard to
                    // hero `surface`. When that pin fails because a card part became a subject, add the
                    // root-ink interception here WITH a test that exercises it (cycle 2, simplification:
                    // an untested branch behind a guard is the green-over-unreachable shape).
                    // ON THE PAGE (ruling E1-A): asked of the component's own template, once per band and
                    // only when a finding is about to be emitted; null means unknown and filters nothing.
                    $presence        = null;
                    $presence_asked  = false;
                    $presence_reason = '';
                    foreach ($paint_elements as $element) {
                        $fired = []; // state => [bp, ...]
                        $shown = null;
                        $kinds = []; // 'own' | 'band' => true
                        foreach ($element['paint'] as $state => $by_bp) {
                            foreach ($by_bp as $bp => $cell) {
                                $ink     = $cell['color'];
                                $surface = $default_surface_in($cell);
                                if ($surface === null) {
                                    continue;
                                }
                                if ($ink !== null && in_array($ink['tier'], ['band', 'item'], true)
                                    && strcasecmp(trim((string) $ink['literal']), 'currentColor') !== 0) {
                                    // A RESTATED DEFAULT (ruling, cycle 2 api-contract): the author's ink compiles
                                    // to the very value the role's default ink puts in this cell, so the designed
                                    // pair is unchanged and there is nothing to name. Read off the compiled tiers,
                                    // with the band's tokens put back (ruling A, cycle 3: F's mechanism on the ink
                                    // side, so a `_tokens` value restating the default is the default too); a literal
                                    // that merely equals a token's value still fires (write the token).
                                    if (($cell['default_color'] ?? null) !== null
                                        && trim(_pp_udc_compiled_value((string) $ink['css'], $band_compiled)) === trim((string) $cell['default_color'])) {
                                        continue;
                                    }
                                    $kind = 'own';
                                } elseif (isset($band_ink_reaches[$element['role']])
                                    && ($ink === null || strcasecmp(trim((string) $ink['literal']), 'currentColor') === 0)
                                    && _pp_udc_band_cells_cover($band_ink_cells, (string) $state, (string) $bp)) {
                                    $kind = 'band';
                                } else {
                                    continue;
                                }
                                $kinds[$kind] = true;
                                $fired[(string) $state][] = (string) $bp;
                                $shown = $shown ?? $surface;
                            }
                        }
                        // A BAND STATE IS A CELL FOR WHAT INHERITS FROM IT (ruling A1 = A). A colour the band
                        // sets only in a state (`[data-pp-band]:hover{color}`) applies whenever the band is in
                        // that state, so an element with no colour of its own shows it AT REST, on its own
                        // resting surface. The band root encloses every role, so no containment model is
                        // needed. Keyed 'band:<state>'. DECIDED BY THE RESTING CELL ONLY (ruling G = A, /ship
                        // cycle-2 red team): `[data-pp-band]:hover` and `.role:hover` are different events, and
                        // with the band hovered and the element not, the element is at rest whatever its own
                        // :hover says. A skip on the element's own state ink let following the element-hover
                        // advice hide this clash (1.07:1 still painting). Both clashes, one finding.
                        if (isset($band_ink_reaches[$element['role']])) {
                            foreach ($band_ink_cells as $band_cell_key => $unused_band_cell) {
                                [$band_state, $band_bp] = explode('|', (string) $band_cell_key, 2) + ['', 'd'];
                                if ($band_state === '') {
                                    continue;
                                }
                                foreach (array_keys($breakpoints_meta) as $bp) {
                                    if ($band_bp !== 'd' && $band_bp !== (string) $bp) {
                                        continue;
                                    }
                                    $rest         = $element['paint'][''][$bp] ?? null;
                                    $rest_surface = $rest === null ? null : $default_surface_in($rest);
                                    if ($rest_surface === null
                                        || ($rest['color'] !== null && strcasecmp(trim((string) $rest['color']['literal']), 'currentColor') !== 0)) {
                                        continue;
                                    }
                                    $band_key = 'band:' . $band_state;
                                    if (!in_array((string) $bp, $fired[$band_key] ?? [], true)) {
                                        $fired[$band_key][] = (string) $bp;
                                    }
                                    $kinds['band'] = true;
                                    $shown = $shown ?? $rest_surface;
                                }
                            }
                        }
                        if ($fired === []) {
                            continue;
                        }
                        if ($ink_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                            break; // Capped: stop before the render too, not only the output.
                        }
                        if (!$presence_asked) {
                            $presence_asked = true;
                            $asked = [];
                            foreach (array_keys($text_roles) as $asked_role) {
                                $asked[$asked_role] = (string) ($roles[$asked_role]['selector'] ?? '');
                            }
                            // The band's SIZE is every list prop's entries (cards, testimonials, rows...),
                            // not only the item-role prop: a render costs in proportion to all of them.
                            $band_cards = 0;
                            foreach ((array) ($item['props'] ?? []) as $prop_value) {
                                if (is_array($prop_value)) {
                                    $band_cards += count($prop_value);
                                }
                            }
                            if (pp_udc_is_chrome($component)) {
                                $presence_reason = 'chrome';
                            } elseif ($presence_renders_left <= 0) {
                                $presence_reason = 'renders';
                            } elseif ($band_cards > $presence_cards_left) {
                                $presence_reason = $band_cards > PP_UDC_PRESENCE_CARDS ? 'size' : 'budget';
                            } else {
                                $presence = _pp_udc_rendered_roles($item, $asked, $presence_markup_left, $presence_why);
                                $presence_renders_left--;
                                $presence_cards_left -= $band_cards;
                                $presence_reason = $presence === null ? (string) $presence_why : '';
                            }
                        }
                        if ($presence !== null) {
                            $on_page = $presence[$element['role']] ?? ['band' => false, 'items' => []];
                            if ($element['item'] === '' ? !$on_page['band'] : !isset($on_page['items'][$element['item']])) {
                                continue; // The element is not rendered with these props: nothing to name.
                            }
                        }
                        $all_widths = count($breakpoints_meta);
                        $phrases    = [];
                        $band_state_labels = [':hover' => 'while the pointer is over the band',
                            ':focus-visible' => 'while the band has keyboard focus', ':active' => 'while the band is pressed'];
                        foreach ($fired as $state => $bps) {
                            $where = strncmp((string) $state, 'band:', 5) === 0
                                ? ($band_state_labels[substr((string) $state, 5)] ?? sprintf('while the band is %s', _pp_udc_reflect(substr((string) $state, 5))))
                                : ($state === '' ? 'at rest' : (str_contains($state, '+')
                                ? sprintf('in the %s states together', implode(' and ', array_map('_pp_udc_reflect', explode('+', $state))))
                                : sprintf('in the %s state', _pp_udc_reflect($state))));
                            $phrases[] = count($bps) === $all_widths ? $where : $where . ' at the ' . _pp_udc_widths_phrase($bps);
                        }
                        // "At rest" is said whenever the element HAS another state cell that did not fire
                        // (design pass, cycle 1): an author :hover fill covering the hover state must not
                        // read as a clash in every state.
                        $has_other_states = count($element['paint']) > 1;
                        if (count($phrases) === 1 && isset($fired['']) && count($fired['']) === $all_widths && !$has_other_states) {
                            $qualifier = '';
                        } elseif (count($phrases) === 1 && isset($fired['']) && !$has_other_states) {
                            $qualifier = ' at the ' . _pp_udc_widths_phrase($fired['']);
                        } else {
                            $qualifier = ' ' . implode(', and ', $phrases);
                        }
                        // THE ADVICE SAYS WHERE THE FILL GOES (api-contract pass, cycle 1). A resting fill
                        // cannot cover a default :hover fill (0,2,0 against 0,3,0), and a base value does
                        // not reach a width the default fills, so "set background.fill" alone loops a
                        // model that follows it. Name the state map and the breakpoint keys that fired.
                        $fill_where = [];
                        // A combined cell's fill goes on the author's OWN state (the later one: an
                        // `:active` or `:focus-visible` fill prints after the default `:hover` fill).
                        // A band-state cell ('band:<state>') is the element AT REST: its fix is the resting fill.
                        $named_states = array_values(array_unique(array_map(static function (string $s): string {
                            $parts = explode('+', $s);
                            return (string) end($parts);
                        }, array_values(array_filter(array_map('strval', array_keys($fired)), static fn (string $s): bool => $s !== '' && strncmp($s, 'band:', 5) !== 0)))));
                        $rest_fired = isset($fired['']) || array_filter(array_keys($fired), static fn ($s): bool => strncmp((string) $s, 'band:', 5) === 0) !== [];
                        if ($named_states !== []) {
                            // Rest fired TOO: the resting fill is needed as well, or following the advice
                            // leaves the resting clash (cycle 2, testing).
                            $fill_where[] = $rest_fired
                                ? sprintf('at rest and inside %s (background: {"fill": ..., "%s": {"fill": ...}})',
                                    implode(' and ', array_map('_pp_udc_reflect', $named_states)), _pp_udc_reflect($named_states[0]))
                                : sprintf('inside %s (background: {"%s": {"fill": ...}})',
                                    implode(' and ', array_map('_pp_udc_reflect', $named_states)), _pp_udc_reflect($named_states[0]));
                        }
                        $partial_bps = [];
                        foreach ($fired as $bps) {
                            if (count($bps) < $all_widths) {
                                $partial_bps = array_values(array_unique(array_merge($partial_bps, $bps)));
                            }
                        }
                        if ($partial_bps !== []) {
                            $fill_where[] = sprintf('at %s (a breakpoint map, e.g. {%s})', count($partial_bps) === 1 ? 'that width' : 'those widths',
                                implode(', ', array_map(static fn (string $bp): string => '"' . $bp . '": ...', $partial_bps)));
                        }
                        $ink_disclosed++;
                        // THE ADVICE ORDER FOLLOWS THE SURFACE (ruling C1 = A, /ship design pass). On a DARK default
                        // surface (grid step-number ships the accent: white reads 5.53:1 on it) repaint-first advice
                        // told authors to replace a deliberate accent badge; lead with the check there. A lightness
                        // reading (_pp_udc_value_is_light()), not contrast maths, so D5 holds: the finding fires the
                        // same either way. The fill it asks for stands apart from the band: the band's own colour
                        // clears the finding and erases the pill (design pass).
                        $where_fill  = $fill_where === [] ? '' : ' ' . implode(' and ', $fill_where);
                        // The fill must READ UNDER THE INK as well as stand apart from the band (/ship cycle-2 design:
                        // a white pill under white ink stands apart from a dark band and is invisible text).
                        $aa          = '(AA: 4.5:1 for body text, 3:1 for large text)';
                        // A clash that is ONLY band-state cells asks for a resting fill that sits under two inks: the
                        // band's resting text colour and its state colour (cycle 3, design).
                        $band_state_only = array_filter(array_keys($fired), static fn ($k): bool => strncmp((string) $k, 'band:', 5) !== 0) === [];
                        $reads_on    = $band_state_only
                            ? 'both the band\'s resting text colour and the colour it sets in that state read on'
                            : 'your text colour reads on';
                        $advice      = _pp_udc_value_is_light(_pp_udc_compiled_value((string) $shown['css'], $band_compiled), []) === false
                            ? sprintf('Check that the pair reads %s; if it does not, set background.fill for this role%s, choosing a fill %s '
                                . 'that also stands apart from the band so the shape still shows.', $aa, $where_fill, $reads_on)
                            : sprintf('Set background.fill for this role%s as well, choosing a fill %s %s that also stands '
                                . 'apart from the band so the shape still shows; or check that the pair reads as it is.', $where_fill, $reads_on, $aa);
                        $findings[] = [
                            'type'    => 'udc_role_ink_over_own_surface',
                            'message' => sprintf(
                                'Component "%s"%s role "%s": %s paints this role\'s text on its own default background (%s)%s%s, '
                                . 'and the band background you set does not replace that background. %s Headings, links and text '
                                // Nested pairs are not measured (#1140, #1146); following this advice once left a nested
                                // <h3> at 1.21:1 under the new fill with the write silent (cycle 3, design).
                                . 'roles inside this one keep their own colour (a heading or link takes it from the theme stylesheet), '
                                . 'so check them on the new fill and set their typography.color too.',
                                $component,
                                $element['item'] === '' ? '' : sprintf(' item "%s"', _pp_udc_reflect($element['item'])),
                                $element['role'],
                                // WHICH OF THE AUTHOR'S MOVES supplied the ink (D3 condition 3).
                                isset($kinds['own']) && isset($kinds['band'])
                                    ? 'the text colour you set for this role (typography.color, a preset you applied, or _css), '
                                      . 'and where it sets none the one you set on the whole band (_band typography.color, a preset you applied to _band, or _band _css color),'
                                    : (isset($kinds['band'])
                                        ? 'the text colour you set on the whole band (_band typography.color, a preset you applied to _band, or _band _css color) reaches this role '
                                          . 'because it declares no colour of its own, and'
                                        : 'the text colour you set for this role (typography.color, a preset you applied, or _css)'),
                                // Always the DEFAULT surface (a restated fill shows as the default it restates).
                                _pp_udc_reflect(_pp_udc_compiled_display((string) $shown['css'], $band_compiled)),
                                $shown['restated'] ? ' (the background.fill you set for this role restates that default)' : '',
                                $qualifier,
                                $advice
                            ) . ($presence === null
                                ? ' (Not checked against the rendered page: ' . ([
                                    'chrome'  => 'the header and footer are not rendered by this check',
                                    // Not "this write": check page, inspect and restore run this too (cycle 2).
                                    'renders' => sprintf('this check already rendered its limit of %d bands', PP_UDC_PRESENCE_RENDERS),
                                    'size'    => 'this band is past the check\'s size budget',
                                    'budget'  => 'this check already used its size budget on the bands before this one',
                                ][$presence_reason] ?? 'this band could not be rendered or read here')
                                  . ', so this role may not be rendered with these props.)'
                                : ''),
                            'index'   => is_int($i) ? $i : null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                error_log('PromptingPress: own-surface findings probe failed for band ' . (string) $item['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        // THE DROPPED-OVERLAY DISCLOSURE (#1117, invariant I35). `background.overlay` with no
        // `background.image` is accepted by the write gate and discarded by the emitter.
        //
        // ONE PREDICATE WITH THE EMITTER, literally: this compiles the band through
        // pp_udc_compile_band() with a drop ledger and reports the rows that emitter wrote
        // for this discard, rather than re-deriving "is there an image under this overlay"
        // here. A second derivation would miss what the emitter actually does — a narrower
        // breakpoint borrowing the base image, a preset's overlay, a state bucket — and the
        // two would disagree (the I29 class). Only rows carrying this code surface: every
        // other ledger row stays on the readiness channel it always had.
        //
        // A BAND WITH NO USABLE ID IS NOT PROBED. The emitter renders nothing for it (its id
        // gate), so there is no overlay drop to report — the whole band is the drop, and the
        // readiness ledger says so. Compiling it under an invented id would describe a
        // render that never happens (I29). Every authoring path runs this walk after ids are
        // minted, so this only ever meets a stored band from raw meta or restore (#233).
        // What it costs is one compile per band that passes the pre-filter below, on the
        // findings paths only, and none once the cap is reached; when the off-scrim arm
        // above already compiled the band, its compile and ledger are reused here.
        $overlay_probe = $item;
        $overlay_probe_has_id = isset($overlay_probe['id']) && is_scalar($overlay_probe['id'])
            && pp_udc_valid_band_id((string) $overlay_probe['id']);
        // Once the cap is reached the compile is skipped too: bounding the findings but not
        // the work would leave the write path paying for disclosures nobody will see.
        //
        // A NECESSARY CONDITION FIRST, NOT A SECOND PREDICATE. Only a map that names an
        // `overlay`, or a `_preset` whose bundle might, can make the emitter write this
        // row, so every other band skips the compile; the emitter still decides every band
        // that reaches it. Without this the common case, no overlay anywhere, compiled
        // every band on every write: measured 3.3 -> 40 ms at 50 bands x 20 cards.
        $overlay_drops = [];
        $overlay_candidate = _pp_udc_map_may_carry_overlay($item['udc'], 0, false, $overlay_preset_memo);
        foreach ($band_item_maps as $candidate_map) {
            if ($overlay_candidate) {
                break;
            }
            $overlay_candidate = _pp_udc_map_may_carry_overlay($candidate_map, 0, false, $overlay_preset_memo);
        }
        if ($band_compiled !== null) {
            // The off-scrim arm above already compiled this band with a drop ledger: reuse
            // it rather than compiling twice (the same item, the same layer, the same call).
            $overlay_drops = $band_drops;
        } elseif ($overlay_probe_has_id && $overlay_candidate && $overlay_disclosed < PP_UDC_MAX_EMIT_DROPS) {
            try {
                pp_udc_compile_band($overlay_probe, 'authored', $overlay_drops);
            } catch (\Throwable $e) {
                // The readiness channel owns compile failures, but it walks a bounded window
                // of bands, so a failure past it would vanish without this line (I29) — the
                // same developer-facing log its sibling probe writes.
                error_log(
                    'PromptingPress: overlay findings probe failed for band ' . (string) $overlay_probe['id']
                    . ': ' . get_class($e) . ': ' . $e->getMessage()
                );
                $overlay_drops = [];
            }
        }
        foreach ($overlay_drops as $drop) {
            if (($drop['code'] ?? '') !== 'overlay_without_image') {
                continue;
            }
            if ($overlay_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                break;
            }
            $overlay_disclosed++;
            $findings[] = [
                'type'    => 'udc_overlay_without_image',
                'message' => sprintf(
                    'Component "%s" %s: %s.',
                    $component,
                    (string) ($drop['where'] ?? ''),
                    (string) ($drop['reason'] ?? '')
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
        // R1-A: WHAT THE BAND EMITS, not what the raw map spells. A `typography._preset` and a width-only
        // value paint an inherited value the raw read missed, so both this disclosure and the own-surface
        // arm (same predicate) were silent on a 1.07:1 clash. Read off the compile the arms above share,
        // compiled here only for a `_band` map that can carry an inherited value at all. A band with no
        // usable id emits nothing; its raw map keeps the old read.
        $band_map_here = is_array($item['udc']['_band'] ?? null) ? $item['udc']['_band'] : [];
        $band_emitted_here = null;
        if ($band_has_id && (isset($band_map_here['typography']) || isset($band_map_here[PP_UDC_CSS_KEY]) || isset($band_map_here[PP_UDC_PRESET_KEY]))) {
            try {
                if ($band_compiled === null) {
                    $band_drops    = [];
                    $band_compiled = pp_udc_compile_band($item, 'authored', $band_drops);
                }
                $band_emitted_here = _pp_udc_band_inherited_emitted($band_compiled);
            } catch (\Throwable $e) {
                error_log('PromptingPress: band-shadow findings probe failed for band ' . (string) $item['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
                $band_emitted_here = null;
            }
        }
        // A REST-STATE FINDING (ruling 1 = A, /ship api-contract). A colour the band sets only in a state is
        // not what a role default cancels AT REST; naming it with this resting advice told a model to put
        // light ink at rest on every listed role for an author who wanted a hover change (main excluded it
        // on purpose). The hover reach is the own-surface finding's band-state cells (A1 = A), where the
        // state is named. Do not make this arm state-aware without a design look.
        $band_rest_here = $band_emitted_here === null ? null : array_filter(array_map(
            static fn (array $cells): array => array_filter($cells, static fn ($key): bool => strncmp((string) $key, '|', 1) === 0, ARRAY_FILTER_USE_KEY),
            $band_emitted_here
        ));
        $band_cancelled_map = $band_rest_here === null
            ? _pp_udc_band_values_cancelled_by_role_defaults($item['udc'], $component)
            : _pp_udc_inherited_values_cancelled_by_role_defaults($item['udc'], $component, '_band', null, $band_rest_here);
        foreach ($band_cancelled_map as $property => $names) {
            // A value set only at some widths (at rest) is named with them.
            $band_width_phrase = '';
            if ($band_rest_here !== null && !isset($band_rest_here[$property]['|d'])) {
                $width_keys = [];
                foreach (array_keys(pp_udc_breakpoints()) as $bp_key) {
                    if (isset($band_rest_here[$property]['|' . $bp_key])) {
                        $width_keys[] = (string) $bp_key;
                    }
                }
                $band_width_phrase = $width_keys === [] ? '' : ' at the ' . _pp_udc_widths_phrase($width_keys);
            }
            $findings[] = [
                'type'    => 'udc_band_value_shadowed_by_role_default',
                'message' => sprintf(
                    'Component "%s": the "%s" you set on the whole band%s does not reach %s, because %s '
                    . 'own default for it wins over inheritance. Set it on %s directly%s.',
                    $component,
                    (string) $property,
                    $band_width_phrase,
                    implode(', ', $names),
                    count($names) === 1 ? 'that role\'s' : 'those roles\'',
                    count($names) === 1 ? 'that role' : 'those roles',
                    (string) $property === 'color' ? _pp_udc_own_fill_note($names, $roles, (array) $item['udc']) : ''
                ),
                'index'   => is_int($i) ? $i : null,
            ];
        }

        // ── THE TWO ITEM-GRAIN SHADOWING DISCLOSURES (Addendum B4) ──────────
        //
        // The clause asks for both directions: an ITEM value cancelled by a role
        // default, and a BAND value cancelled by an ITEM value.
        $item_declaration = pp_udc_item_roles($component);
        if ($item_declaration !== null) {
            $all_item_maps = $band_item_maps;

            // (a) AN ITEM'S INHERITED VALUE, CANCELLED BY A PART'S OWN DEFAULT.
            //
            // The `_band` disclosure one level down, and the same mechanism
            // exactly: the item's ROOT role is a container, so a typography
            // value on it reaches the card's parts by INHERITANCE, and a part
            // that declares the property directly beats inheritance at any
            // specificity and in any source order. Darkening one card's fill
            // and setting its text colour on the card ROLE is the obvious
            // authoring move and the one that silently half-works — which is
            // precisely the #1059 shape that shipped a 3.21:1 row on faq.
            //
            // Scoped to the roles the item can actually address: telling an
            // author to "set it on that role directly" is only actionable if
            // they are allowed to.
            // BOUNDED AT THE SOURCE, for the reason the `_css` arm below states and
            // measures: slicing the reader's output does not bound the ALLOCATION.
            // That arm's multiplier is the property count; this one's is the ITEM
            // count, and `items` declares no `max_items`, so the shape is the same
            // with a different axis. Measured here at 400 styled cards: 400 findings
            // before the bound, on a function `wp pp check page`, restore_composition
            // and every post-write envelope all reach.
            // `$item_disclosed` is declared OUTSIDE the band loop, beside
            // `$css_disclosed` — a per-band counter bounds nothing that matters.
            foreach ($all_item_maps as $item_id => $item_map) {
                if ($item_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                    break;
                }
                $cancelled = _pp_udc_inherited_values_cancelled_by_role_defaults(
                    $item_map, $component, $item_declaration['root'], $item_declaration['roles']
                );
                foreach ($cancelled as $property => $names) {
                    if ($item_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                        break;
                    }
                    $item_disclosed++;
                    $findings[] = [
                        'type'    => 'udc_item_value_shadowed_by_role_default',
                        'message' => sprintf(
                            'Component "%s" item "%s": the "%s" you set on "%s" does not reach %s, because %s '
                            . 'own default for it wins over inheritance. Set it on %s for this item too%s.',
                            $component,
                            _pp_udc_reflect((string) $item_id),
                            (string) $property,
                            $item_declaration['root'],
                            implode(', ', $names),
                            count($names) === 1 ? 'that role\'s' : 'those roles\'',
                            count($names) === 1 ? 'that role' : 'those roles',
                            // The same loop the band sibling's note closes (/ship red team, RT3).
                            (string) $property === 'color' ? _pp_udc_own_fill_note($names, $roles, is_array($item_map) ? $item_map : []) : ''
                        ),
                        'index'   => is_int($i) ? $i : null,
                    ];
                }
            }

            // (b) A BAND VALUE EVERY ITEM OVERRIDES, so it paints nowhere.
            //
            // THE LITERAL READING OF B4 WOULD BE NOISE THAT READS AS A LIE, and
            // the measurement is what settles it. B4 says "a BAND value shadowed
            // by an ITEM value" is disclosed; taken literally that fires whenever
            // any item overrides anything — which is the headline capability
            // working, and is exactly what the owner's live design does on 10 of
            // 11 production bands (set the card fill on the band, override it on
            // one card). A finding on every correct write trains an operator to
            // stop reading findings, which costs more than the disclosure buys.
            //
            // The honest subject is the one the band tier's own disclosures share:
            // a declared value that cannot take effect ANYWHERE. A band value is
            // that only when EVERY entry overrides the same role and parameter —
            // then the author has written something no pixel will ever show, and
            // saying so is actionable. With even one entry not overriding, the
            // band value paints there and the cascade is doing its job.
            $entries = $item['props'][$item_declaration['prop']] ?? null;
            $entry_count = is_array($entries) ? count($entries) : 0;
            if ($entry_count > 0 && is_array($item['udc'])) {
                $groups_registry = pp_udc_groups();
                foreach ($item['udc'] as $role_name => $role_map) {
                    $role_name = (string) $role_name;
                    if (!is_array($role_map)
                        || !in_array($role_name, $item_declaration['roles'], true)) {
                        continue;
                    }
                    foreach ($role_map as $group_name => $group_map) {
                        $group_name = (string) $group_name;
                        if ($group_name === PP_UDC_PRESET_KEY || !is_array($group_map)
                            || !isset($groups_registry[$group_name]['params'])) {
                            continue;
                        }
                        foreach (array_keys($group_map) as $param_name) {
                            $param_name = (string) $param_name;
                            if (!isset($groups_registry[$group_name]['params'][$param_name])) {
                                continue;
                            }
                            $overriding = 0;
                            foreach ($all_item_maps as $item_map) {
                                if (isset($item_map[$role_name][$group_name][$param_name])) {
                                    $overriding++;
                                }
                            }
                            if ($overriding < $entry_count) {
                                continue;
                            }
                            $findings[] = [
                                'type'    => 'udc_band_value_shadowed_by_item_value',
                                'message' => sprintf(
                                    'Component "%s": the "%s.%s" you set on "%s" for the whole band is '
                                    . 'overridden by every one of the %d items, so it paints nowhere. '
                                    . 'Change the items, or drop the band-level value.',
                                    $component,
                                    $group_name,
                                    $param_name,
                                    $role_name,
                                    $entry_count
                                ),
                                'index'   => is_int($i) ? $i : null,
                            ];
                        }
                    }
                }
            }
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
            // THE UNKNOWN-ROLE GUARD BOTH SIBLING LOOPS CARRY, and it is a truth rule
            // rather than a tidiness one: pp_udc_compile_band() walks only roles the
            // component DECLARES, so a stored map naming a role that does not exist paints
            // nothing at all — and a confident "this is emitted exactly as written" finding
            // about CSS that can never emit is the wrong-subject defect these disclosures
            // exist to prevent.
            if (!is_array($role_map) || !isset($role_map[PP_UDC_CSS_KEY])
                || !is_array($role_map[PP_UDC_CSS_KEY])
                || !isset($roles[(string) $role_name])) {
                continue;
            }
            $role_name = (string) $role_name;
            $states    = pp_udc_states();
            // WHAT THE BAND COMPILED, not which keys the author wrote (#1141, ruling D1 = A). A stored raw
            // value the grammar refuses is dropped at emit (and ledgered), so "the raw value is what paints"
            // was false for it; the collision message is chosen from whether the raw declaration compiled.
            // Compiled at most once per band, only when a collision is about to be reported (a compile that throws is
            // logged once and not retried). A band with no usable id emits nothing at all, so there is no compile to
            // read: the old wording stands there, as it does when the compile fails.
            $raw_compiled = static function (string $state_key, string $raw_property) use (&$band_compiled, &$band_drops, &$raw_probe_failed, $item, $band_has_id, $role_name): ?bool {
                if (!$band_has_id || $raw_probe_failed) {
                    return null;
                }
                if ($band_compiled === null) {
                    try {
                        $band_drops    = [];
                        $band_compiled = pp_udc_compile_band($item, 'authored', $band_drops);
                    } catch (\Throwable $e) {
                        error_log('PromptingPress: raw-collision findings probe failed for band ' . (string) $item['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
                        $raw_probe_failed = true;
                        $band_compiled    = null;
                        $band_drops       = [];
                        return null;
                    }
                }
                foreach ((array) ($band_compiled['blocks'] ?? []) as $block) {
                    if (($block['role'] ?? '') === $role_name && ($block['item'] ?? '') === '' && (string) ($block['state'] ?? '') === $state_key
                        && !empty($block['decls'][$raw_property]['raw'])) {
                        return true;
                    }
                }
                return false;
            };

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
                if (!pp_udc_css_property_admissible($property)) {
                    continue; // Refused at write; a stored one is the emitter's ledger.
                }
                // BOUNDED AT THE SOURCE, for the reason the drop ledger states about
                // itself: slicing the reader's output does not bound the ALLOCATION.
                // Every other finding producer here is bounded by the registry — a finite
                // group x param table — and `_css` is the first whose key space is the
                // author's. Measured at 20,000 distinct properties on one role: 20,000
                // findings and +13.8 MB from ~320 KB of input, while the emitter capped
                // itself at 200. `wp pp check page` and restore_composition reach this
                // function without the write path's 1 MB pre-engine gate in front of them.
                // THE COUNTER COUNTS WHAT ITS NAME SAYS. It was incremented for every
                // admissible declaration EXAMINED, including a typed property with no
                // collision — which emits no finding and then continues. The registry holds
                // 61 properties, so a band declaring all of them in `_css` burned 61 slots
                // producing nothing, and real disclosures could then be dropped with fewer
                // than 200 findings on the envelope. The allocation argument the cap rests
                // on is about the MESSAGE STRINGS, not the walk, so counting appends is
                // both the honest reading and the one the bound was argued for.
                if ($css_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                    continue;
                }
                $typed = _pp_udc_css_param_for_property($property);

                // (a) THE COLLISION. `_css` outranks a group value at the same
                // coordinate — the rank the freedom frame requires, since an escape
                // that loses to the thing it escapes cannot escape anything. Reported
                // only when the author actually wrote BOTH, because that is the only
                // case where something they declared did not paint.
                // A RAW SHORTHAND RESETS THE LONGHANDS IT OWNS, and reporting the
                // collision on exact property equality alone missed every instance.
                //
                // Untyped `_css` properties sort AFTER every registry property (they are
                // unranked, so `_pp_udc_sort_declarations()` puts them last), and CSS then
                // does what CSS does: `border: 1px solid red` written raw wipes an
                // authored `border.width`, `border.style` and `border.color` that emitted
                // three declarations earlier in the same block. Measured — all three
                // authored values dead, one `udc_css_unchecked_property` finding, and
                // nothing saying anything was overridden. `_pp_udc_property_rank()`'s own
                // docblock calls this shape "exactly what invariant I35 forbids".
                //
                // `border` and `font` are the two a model reaches for first, which is why
                // this is reported rather than left to the unchecked-property disclosure.
                foreach (_pp_udc_css_shorthand_longhands($property) as $longhand) {
                    $owner = _pp_udc_css_param_for_property($longhand);
                    if ($owner === null) {
                        continue;
                    }
                    $owner_map = $role_map[$owner['_group']] ?? null;
                    $owner_br  = is_array($owner_map) && $state !== ''
                        ? ($owner_map[$state] ?? null)
                        : $owner_map;
                    if (!is_array($owner_br) || !array_key_exists($owner['_param'], $owner_br)) {
                        continue;
                    }
                    if ($css_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                        break;
                    }
                    $css_disclosed++;
                    $findings[] = [
                        'type'    => 'udc_css_overrides_group_value',
                        'message' => $raw_compiled($state, $property) === false
                            ? sprintf(
                                'Component "%s" role "%s"%s: the raw declaration "%s" in "%s" is a shorthand that would reset '
                                . '%s, but the stored raw value cannot be emitted, so the %s.%s you also set is what paints. '
                                . 'Fix or remove the raw declaration.',
                                $component, _pp_udc_reflect($role_name), $state !== '' ? ' ' . $state : '',
                                _pp_udc_reflect($property), PP_UDC_CSS_KEY, $longhand, $owner['_group'], $owner['_param'])
                            : sprintf(
                            'Component "%s" role "%s"%s: the raw declaration "%s" in "%s" is a '
                            . 'shorthand that resets %s, so the %s.%s you also set does not '
                            . 'paint. Write the whole treatment in one place.',
                            $component,
                            _pp_udc_reflect($role_name),
                            $state !== '' ? ' ' . $state : '',
                            _pp_udc_reflect($property),
                            PP_UDC_CSS_KEY,
                            $longhand,
                            $owner['_group'],
                            $owner['_param']
                        ),
                        'index'   => is_int($i) ? $i : null,
                    ];
                }

                if ($typed !== null) {
                    $group_map = $role_map[$typed['_group']] ?? null;
                    $branch    = is_array($group_map) && $state !== ''
                        ? ($group_map[$state] ?? null)
                        : $group_map;
                    if (is_array($branch) && array_key_exists($typed['_param'], $branch)) {
                        $css_disclosed++;
                        $findings[] = [
                            'type'    => 'udc_css_overrides_group_value',
                            'message' => $raw_compiled($state, $property) === false
                                ? sprintf(
                                    'Component "%s" role "%s"%s: the raw declaration "%s" in "%s" would outrank the %s.%s you '
                                    . 'also set, but the stored raw value cannot be emitted, so the %s.%s you also set is what '
                                    . 'paints. Fix or remove the raw declaration.',
                                    $component, _pp_udc_reflect($role_name), $state !== '' ? ' ' . $state : '',
                                    _pp_udc_reflect($property), PP_UDC_CSS_KEY, $typed['_group'], $typed['_param'],
                                    $typed['_group'], $typed['_param'])
                                : sprintf(
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
                $css_disclosed++;
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
        // ITEM REFERENCES COUNT (Addendum B4). A token minted for an item is
        // referenced from inside `props.items[k].udc`, which the band map does
        // not contain — so without this every item-minted token would be
        // reported `udc_unused_band_token` on the very write that created it,
        // telling an author their own value "has no effect" while it paints.
        $referenced      = _pp_udc_referenced_token_names($item['udc']);
        $token_item_maps = $band_item_maps;
        foreach ($token_item_maps as $item_map) {
            foreach (_pp_udc_referenced_token_names($item_map) as $ref_name => $ignored_ref) {
                $referenced[$ref_name] = true;
            }
        }

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
            // BOUNDED ACROSS THE COMPOSITION, same discipline as the `_css` and
            // item-shadowing arms, and the item tier is what made this one matter: B4
            // mints one band token per responsive ITEM value, so the token count scales
            // with the card count. Measured at 40 bands x 50 cards x 24 responsive
            // params: 48,000 findings, 7.4 MB of message strings, +34 MB peak, 426 ms in
            // ONE call. The accepted-write path is protected by its stored-bytes gate;
            // `wp pp check page` and restore_composition carry the count budget and NOT
            // that gate, which is the exposure. Nothing downstream can display more than
            // PP_WRITE_FINDINGS_BUDGET of them anyway, so the cap costs no
            // operator-visible information.
            if ($tokens_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                break;
            }
            if (!is_scalar($literal) || !_pp_udc_is_mint_shaped_name((string) $name)) {
                continue;
            }
            // $token_item_maps is HOISTED (it is the same value `$referenced` was built
            // from above). Rebuilt inside this loop it was O(N^2) array construction per
            // band: pp_udc_item_maps() walks every entry of the repeater, and the item
            // tier is precisely what makes the token count grow with the item count —
            // B4 mints one band token per responsive item value, so T scales with N.
            if (!_pp_udc_name_is_the_engines_own_mint((string) $name, $item['udc'], $token_item_maps)) {
                continue;
            }
            $tokens_disclosed++;
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
            // THE SAME BUDGET AS ITS TWIN ABOVE, which walks this identical array. The
            // first cut capped `udc_token_minted` and left this one uncapped — measured
            // at 40 bands of unreferenced item-shaped tokens: 24,000 findings, 4.3 MB of
            // message text and +16 MB peak from one call. One budget across both
            // disclosures is what "bounded across the composition" has to mean when two
            // loops read one array.
            if ($tokens_disclosed >= PP_UDC_MAX_EMIT_DROPS) {
                break;
            }
            if (isset($referenced[(string) $name])) {
                continue;
            }
            $tokens_disclosed++;
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
 * THE RAW-MAP FALLBACK (#1125, R1-A). The disclosure reads what a band EMITS
 * (_pp_udc_band_inherited_emitted() over its compile, rest cells only), which covers
 * a role-grain `_preset` and width-only values; this raw read runs only for a band
 * with no usable id, which emits nothing. The `_preset` carve-out this docblock used
 * to name is therefore closed for every band that renders.
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
    return _pp_udc_inherited_values_cancelled_by_role_defaults($udc, $component, '_band', null);
}

/**
 * The inherited properties `$source_role` declares in the author's map (its groups, and for
 * `_band` its `_css`), as property => true: the RAW-MAP read, which
 * _pp_udc_inherited_values_cancelled_by_role_defaults() falls back to when no `$declared` map is passed
 * (a band with no usable id, and the item-root tier). Both band findings pass what the band EMITS
 * instead (_pp_udc_band_inherited_emitted(), R1-A), so they share one predicate over one map.
 *
 * @return array<string, true>
 */
function _pp_udc_inherited_values_declared(array $udc, string $source_role): array {
    if (!isset($udc[$source_role]) || !is_array($udc[$source_role])) {
        return [];
    }
    $groups    = pp_udc_groups();
    $inherited = _pp_udc_inherited_properties();

    // What the source role declares, as CSS properties.
    $declared = [];
    foreach ($udc[$source_role] as $group_name => $group_map) {
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

    // `_band._css` DECLARES INHERITED PROPERTIES TOO, and this walk was registry-only
    // (#1079). Byte-identical emitted CSS reported oppositely: `_band` -> `typography.color`
    // produced the shadow disclosure, `_band` -> `_css` -> `color` produced nothing at all,
    // while both emit `[data-pp-band=…]{color:…}` and both are cancelled by exactly the
    // same role defaults. Found by the adversarial pass.
    //
    // The grammar check the registry arm applies has no counterpart here by design: an
    // untyped raw value HAS no grammar, so there is nothing to be invalid against, and
    // refusing to disclose it would mean the least-checked values are also the least
    // reported. A typed one keeps its check through pp_udc_css_param().
    // BAND ONLY. An item map refuses `_css` outright (Addendum B6 exclusion 7),
    // so there is nothing to walk at item grain and walking anyway would imply
    // the key is reachable there.
    $band_css = $source_role === '_band' ? ($udc[$source_role][PP_UDC_CSS_KEY] ?? null) : null;
    if (is_array($band_css)) {
        $states = pp_udc_states();
        foreach ($band_css as $property => $value) {
            // Resting declarations only: a `:hover` value on the band is not what a role
            // default cancels at rest, and reporting it here would name the wrong contest.
            if (isset($states[(string) $property])) {
                continue;
            }
            $property = (string) $property;
            if (!pp_udc_css_property_admissible($property) || !isset($inherited[$property])) {
                continue;
            }
            $scalar = is_array($value) ? ($value['d'] ?? null) : $value;
            if (!is_scalar($scalar)) {
                continue;
            }
            $param = pp_udc_css_param($property);
            if (empty($param['untyped'])
                && pp_udc_validate_value((string) $scalar, $param) !== true) {
                continue;
            }
            $declared[$property] = true;
        }
    }
    return $declared;
}

/**
 * The inherited values `_band` really EMITS (#1125, ruling R1-A): property => ['state|bp' => true],
 * read off the compiled `_band` blocks, whatever spelling put them there: the author's map, `_css`, a
 * preset the author applied, a breakpoint map. _pp_udc_inherited_values_declared() reads the raw map
 * and missed a `typography._preset` and a width-only value (a 1.07:1 clash both findings stayed silent
 * on); this is what both the band-shadow disclosure and the own-surface arm read when the band compiles.
 *
 * @return array<string, array<string, true>>
 */
function _pp_udc_band_inherited_emitted(array $compiled): array {
    $inherited = _pp_udc_inherited_properties();
    $out       = [];
    foreach ((array) ($compiled['blocks'] ?? []) as $block) {
        if (($block['role'] ?? '') !== '_band' || ($block['item'] ?? '') !== '') {
            continue;
        }
        foreach ((array) ($block['decls'] ?? []) as $property => $decl) {
            $source = is_array($decl) ? (string) ($decl['source'] ?? '') : '';
            if (!isset($inherited[(string) $property]) || $source === '' || $source === 'defaults' || $source === 'engine-companion') {
                continue;
            }
            $out[(string) $property][(string) ($block['state'] ?? '') . '|' . (string) ($block['bp'] ?? 'd')] = true;
        }
    }
    return $out;
}

/**
 * Whether a band value emitted at these 'state|bp' cells applies in cell ($state, $bp) (#1125 R1-A): a
 * base-width value applies at every width, a state value only in that state (a combined cell such as
 * ':hover+:active' is in each of its states), and the resting value in every state.
 */
function _pp_udc_band_cells_cover(array $cells, string $state, string $bp): bool {
    foreach (array_merge([''], $state === '' ? [] : explode('+', $state)) as $s) {
        if (isset($cells[$s . '|d']) || isset($cells[$s . '|' . $bp])) {
            return true;
        }
    }
    return false;
}

/**
 * The generalized form: which roles' own defaults cancel an inherited value
 * declared on `$source_role` (invariant I35).
 *
 * TWO CALLERS, ONE RULE. `_band` is the band's root and the original subject;
 * an item's ROOT role is the same shape one level down — a container whose
 * inherited values reach its parts only by inheritance, and lose to any part
 * that declares the property directly. The mechanism is identical, so a second
 * implementation would be a second chance to get the `currentColor` carve-out
 * or the already-authored exemption wrong on only one of them.
 *
 * @param array       $udc         The map declaring the inherited values.
 * @param string      $component   The component whose role defaults are read.
 * @param string      $source_role The role those values sit on.
 * @param array|null  $limit_roles Candidate roles to consider cancelled, or
 *                                 null for every role the component declares.
 *                                 The item tier passes its addressable set,
 *                                 because a role an item cannot address cannot
 *                                 be the place it is told to set the value.
 * @param array|null  $declared    What the source role emits, property => anything truthy; null reads
 *                                 the raw map.
 * @return array<string, string[]>  property => the roles whose own default cancels it
 */
function _pp_udc_inherited_values_cancelled_by_role_defaults(
    array $udc,
    string $component,
    string $source_role,
    ?array $limit_roles,
    ?array $declared = null
): array {
    // The caller may pass what the band really emits (_pp_udc_band_inherited_emitted()); otherwise the
    // raw map is read.
    $declared = $declared ?? _pp_udc_inherited_values_declared($udc, $source_role);
    $groups   = pp_udc_groups();
    $roles    = pp_udc_component_roles($component);
    if ($declared === []) {
        return [];
    }

    // Which roles declare a DEFAULT for the same property.
    $cancelled = [];
    foreach ($roles as $role_name => $role_def) {
        if ((string) $role_name === $source_role) {
            continue;
        }
        if ($limit_roles !== null && !in_array((string) $role_name, $limit_roles, true)) {
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
 * Every (state, remaining-segments) reading a mint name's tail admits.
 *
 * A NAME CAN DECODE MORE THAN ONE WAY, and assuming it decodes once is a real defect
 * rather than a theoretical one. `<role>-_css-x-hover-d` is BOTH "parameter `x` in the
 * `:hover` state" AND "parameter `x-hover` at rest" — unreachable while every parameter
 * came from the registry (none ends in a state name), and ordinary the moment Layer 2 let
 * an author name the parameter. `_pp_udc_state_from_mint()` returns the greedy reading
 * only, so a token minted for the at-rest coordinate was judged against the hover one,
 * found absent, and refused as an author squatting the namespace — on the engine's own
 * output.
 *
 * Both readings are offered, state-stripped first (the greedy one, which is what the
 * decoder has always returned), so behaviour is unchanged for every name that decodes one
 * way and the ambiguous ones simply get their second chance.
 *
 * @param array $parts Name segments, breakpoint already popped.
 * @return array<int, array{0: string, 1: array}>
 */
function _pp_udc_mint_readings(array $parts): array {
    $readings          = [];
    [$state, $stripped] = _pp_udc_state_from_mint($parts);
    if ($state !== '') {
        $readings[] = [$state, $stripped];
    }
    $readings[] = ['', $parts];
    return $readings;
}

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
        // SHORT-CIRCUIT BEFORE THE IMPLODE, which is what both originals did and what the
        // first version of this shared walk lost. Extracting the duplication moved the
        // `implode(array_slice(…))` ABOVE the cheap hash lookup, so every split position
        // paid an O(N) string build whether or not its segment could possibly be a group —
        // turning an O(N) walk into O(N²) in segment count. Measured at the 64-character
        // token-name bound (32 segments): 1.51 µs before the extraction, 9.54 µs after,
        // 2.15 µs with this guard restored. Off the render path (this is reached only from
        // the write gate, `wp pp check page` and restore), but an adversarial composition
        // of 10,000 long token names moved findings 13.7 → 25.3 ms before the guard and
        // 16.3 ms after it. Found by the pre-landing performance pass.
        $registry = isset($groups[$group]);
        if (!$registry && $group !== PP_UDC_CSS_KEY) {
            continue;
        }
        $param = implode('-', array_slice($parts, $g + 1));
        if ($param === '') {
            continue;
        }
        // A REGISTRY GROUP names a declared parameter; the `_css` pseudo-group names a
        // CSS property, judged by the same charset gate the write path applies, so a
        // squatted name and an engine mint are separated by exactly the rule that
        // decided what could be written in the first place.
        // ADMISSIBLE, NOT MERELY WELL-FORMED. The charset gate alone let an excluded
        // property (`all`, `content`, the overlay carrier) look like an engine mint, so a
        // stored token named `x-_css-all-d` read as reserved in both decoders. `_css`
        // could never mint one — the write gate refuses those properties — so the
        // classifier uses the predicate that decides what `_css` can hold.
        $known = $registry
            ? isset($groups[$group]['params'][$param])
            : pp_udc_css_property_admissible($param);
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
 *
 * THE ITEM ARM IS NOT AN ENHANCEMENT — WITHOUT IT THE ENGINE REFUSES ITS OWN
 * OUTPUT. Measured on this tree before the arm existed: an item-minted token
 * lands in the BAND's `_tokens` (that is where tokens live, see
 * pp_udc_item_reserved_keys()) while its reference `@it-…-<role>-…` lands
 * inside `props.items[k].udc`. This function searched `$udc` alone, so it
 * answered false, and pp_udc_validate_map()'s reserved-name gate then refused
 * the band with "uses a name the engine mints for itself" — on every band
 * carrying a responsive item value, permanently, for a name the engine wrote.
 *
 * That is the exact failure class _pp_udc_mint_value()'s docblock records
 * ("an accepted write produced a composition that errors on every post-write
 * envelope, `wp pp check page` and restore"), reached from the other side. The
 * decoder and the minter have to agree about the whole namespace or neither is
 * trustworthy; a widening that taught the MINTER a new segment and left the
 * DECODER behind is the disagreement _pp_udc_mint_splits()' docblock warns
 * turns every already-written band into a permanent false refusal.
 *
 * RESOLVED, NOT SEARCHED. An item-minted name carries the id of the one map
 * that could have produced it, so this looks in that map and nowhere else. A
 * name whose id names no item on this band is NOT the engine's own — falling
 * back to a scan would let a stored name borrow another item's reference and
 * pass a gate it should fail.
 *
 * @param array $item_maps id => that item's `udc` map, for the item tier.
 */
function _pp_udc_name_is_the_engines_own_mint(string $name, array $udc, array $item_maps = []): bool {
    $split = _pp_udc_split_item_mint($name);
    if ($split !== null) {
        [$item_id, $rest] = $split;
        if (!isset($item_maps[$item_id]) || !is_array($item_maps[$item_id])) {
            return false;
        }
        // TWO NAMES, AND CONFLATING THEM IS A REAL BUG I SHIPPED INTO THIS
        // FUNCTION ONCE. The COORDINATE is decoded from the stripped name
        // (`card-title-typography-color-d` — role, group, param, breakpoint),
        // but the REFERENCE stored at that coordinate is the FULL minted name
        // including the item segment (`@it-…-card-title-typography-color-d`),
        // because that is what pp_udc_mint_name() wrote. Recursing with the
        // stripped name decoded the right coordinate and then compared against
        // a reference that has never existed, so every item mint answered
        // false — the exact defect this arm was added to fix, reintroduced one
        // layer in. Caught by re-running the red proof against the fix instead
        // of trusting it.
        return _pp_udc_mint_reference_matches($rest, $name, $item_maps[$item_id]);
    }
    return _pp_udc_mint_reference_matches($name, $name, $udc);
}

/**
 * Does `$map` hold `@$compare` at the coordinate `$decode` names?
 *
 * Split from its caller so the band tier and the item tier ask the question
 * with one implementation. They differ only in that an item's coordinate is
 * spelled without the item segment while its reference is spelled with it.
 *
 * @param string $decode  The name whose segments give role/group/param/breakpoint.
 * @param string $compare The name the stored `@reference` must equal.
 */
function _pp_udc_mint_reference_matches(string $decode, string $compare, array $udc): bool {
    $parts = explode('-', $decode);
    $bp    = array_pop($parts);
    // POP BY SEGMENT COUNT, never by one. `focus-visible` is two segments, and
    // the single-array_pop() idiom that served one state called `hover` reads
    // such a name as a param ending in `-focus` inside a state called `visible`.
    foreach (_pp_udc_mint_readings($parts) as [$state, $reading]) {
        foreach (_pp_udc_mint_splits($reading) as [$role, $group, $param]) {
            $branch = $udc[$role][$group] ?? null;
            if ($state !== '') {
                $branch = is_array($branch) ? ($branch[$state] ?? null) : null;
            }
            $value = is_array($branch) ? ($branch[$param] ?? null) : null;
            if (is_array($value) && isset($value[$bp]) && is_scalar($value[$bp])
                && (string) $value[$bp] === '@' . $compare) {
                return true;
            }
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
    // Walk every split of the remainder into <role...>-<group>-<param...>: group
    // and param names both contain hyphens, so the boundary is not positional — and
    // every READING of the tail, because a name can decode more than one way (see
    // _pp_udc_mint_readings()). These two decoders must agree or every band holding an
    // ambiguous name becomes a permanent false refusal; the shared helpers are what
    // makes that structural rather than a promise.
    foreach (_pp_udc_mint_readings($parts) as [, $reading]) {
        if (_pp_udc_mint_splits($reading) !== []) {
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
    // THE ENGINE-OWNED FLAGS ARE THE ENGINE'S, IN BOTH DIRECTIONS (#1073). Stored props reach
    // here by paths that validate nothing (a raw `_pp_composition` meta write; a restore, which
    // reports without blocking, #233), and every template reads the flag with !empty(), so a
    // stored "false", "0 " or "no" emitted `data-pp-band-overlay` on a band painting no scrim (the
    // near-white on-overlay focus ring on a light band), and a stored band id borrowed another
    // band's design. Whatever the props carry is discarded; only the engine's own verdict below
    // is promoted.
    unset($props['__pp_udc_overlay'], $props['__pp_udc_band']);
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
 * A cheap stored-map pre-check, then the verdict from the COMPILED band (so a raw `background` that cancels the
 * image, #1141, cancels the marker too); a compile failure is logged and the band left unmarked (#1142 item 4).
 *
 * Reads the STORED map first, rather than the emitted CSS, because the renderer runs
 * before emission and needs the answer for an attribute. Deliberately narrow: an
 * overlay only paints when there is an image under it (an overlay over nothing is
 * dropped by _pp_udc_compose_background_layers()), so both must be present for the
 * hook to appear — otherwise a band with a stray `overlay` key would claim a
 * contrast problem it does not have.
 *
 * Breakpoint maps count: an overlay declared only at one width still darkens the
 * band there, and a focus ring that is legible at some widths is not legible.
 *
 * THE MARKER MUST SAY WHAT THE EMITTER PAINTS (#1010 review). Since the overlay tier of
 * role defaults re-lights accent inks off this attribute, a marker with no scrim under it
 * is no longer a harmless focus-ring detail: it turns an accent near-white on a light
 * band. So the image is taken in the emitter's own precedence and resolve-checked
 * (_pp_udc_role_map_background_image(): the map's own image, then a group-grain, then a
 * role-grain preset's; a deleted attachment paints nothing), the overlay is taken from
 * the map or from those same presets, which the emitter merges into the map, and a band
 * without a usable id is never marked (the emitter writes no CSS for it).
 */
function pp_udc_band_has_overlay(array $item): bool {
    // No usable band id, no band CSS (pp_udc_band_css() returns ''): nothing paints, so
    // no marker, the same gate pp_udc_promote_band_identity() puts on `__pp_udc_band`.
    if (!isset($item['id']) || !is_scalar($item['id']) || !pp_udc_valid_band_id((string) $item['id'])) {
        return false;
    }
    // A cheap necessary condition first: only a map that names an overlay (directly or
    // through a preset) can paint one, so the common band never compiles here.
    $band_map = $item['udc']['_band'] ?? null;
    if (!is_array($band_map) || !_pp_udc_map_may_carry_overlay($band_map)) {
        return false;
    }
    try {
        return pp_udc_band_paints_scrim(pp_udc_compile_band($item, 'authored'));
    } catch (\Throwable $e) {
        error_log('PromptingPress: overlay marker compile failed for band ' . (string) $item['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * A compiled declaration's CSS with the band's own custom properties put back
 * (`var(--pp-NAME)` -> the compiled band token), so the colour reader sees the value the
 * page resolves; site-level `var(--token)` references are resolved by the reader.
 */
function _pp_udc_compiled_value(string $css, array $compiled): string {
    $tokens = is_array($compiled['tokens'] ?? null) ? $compiled['tokens'] : [];
    return (string) preg_replace_callback('/var\(\s*--pp-([a-z0-9_-]+)\s*\)/i', static function (array $m) use ($tokens): string {
        return isset($tokens[$m[1]]) && is_scalar($tokens[$m[1]]) ? (string) $tokens[$m[1]] : $m[0];
    }, $css);
}

/**
 * A compiled value as the author would write it, for a message: band tokens put back, a
 * site `var(--name)` shown as `@name`, and the emitter's `linear-gradient(c,c)` wrapping of
 * a plain scrim colour unwrapped to `c`.
 */
function _pp_udc_compiled_display(string $css, array $compiled): string {
    $value = (string) preg_replace('/var\(\s*--([a-z0-9][a-z0-9-]*)\s*\)/i', '@$1', _pp_udc_compiled_value($css, $compiled));
    if (preg_match('/^linear-gradient\((.+)\)$/s', $value, $m)) {
        $half = intdiv(strlen($m[1]) - 1, 2);
        if (strlen($m[1]) % 2 === 1 && $m[1][$half] === ',' && substr($m[1], 0, $half) === substr($m[1], $half + 1)) {
            return substr($m[1], 0, $half);
        }
    }
    return $value;
}

/** How a compiled declaration's source names its origin in a message: '' for the author's own map. */
function _pp_udc_source_phrase(string $source): string {
    return strncmp($source, 'preset:', 7) === 0 ? sprintf(' from preset "%s"', _pp_udc_reflect(substr($source, 7))) : '';
}

/**
 * The colours a CSS colour or gradient value paints, as [[r, g, b, alpha], ...] (#1010
 * review), after resolving `@token` references (band `_tokens`, then site tokens) and one
 * few levels of `var(--token)`; `@` references include the engine's own mint names
 * (`@_band-background-overlay-d`). Reads hex, rgb()/rgba() (numeric or percentage
 * channels), hsl()/hsla() and the keywords white/black/transparent, inside a plain colour
 * or a gradient. The read is ALL OR NOTHING: every colour-bearing part must be read, so a
 * gradient with one unread stop, keyword or unit is unread as a whole, never judged by the
 * stops that parsed. Anything else (another
 * colour function such as color-mix(), an unresolved reference, a value over
 * PP_UDC_COLOUR_READ_MAX_BYTES before or after expansion) is NOT read: the result is [],
 * which callers treat as unknown. The bound matters because this reads stored maps that
 * never passed the write gate (restore, raw meta), where `_tokens` can amplify.
 *
 * @return array<int, array{0: int, 1: int, 2: int, 3: float}>
 */
function _pp_udc_value_colours(string $value, array $band_tokens): array {
    if (strlen($value) > PP_UDC_COLOUR_READ_MAX_BYTES) {
        return [];
    }
    // The engine's own mint names begin with the role, and `_band` begins with an underscore
    // (`@_band-background-overlay-d`), so the reference grammar here admits it.
    $value = (string) preg_replace_callback('/@([a-z0-9_][a-z0-9_-]*)/i', static function (array $m) use ($band_tokens): string {
        $resolved = pp_udc_resolve_reference($m[1], $band_tokens);
        return $resolved === null ? $m[0] : substr($resolved['value'], 0, PP_UDC_COLOUR_READ_MAX_BYTES + 1);
    }, $value);
    if (strlen($value) > PP_UDC_COLOUR_READ_MAX_BYTES) {
        return [];
    }
    // A site token may hold another `var()` (a component token pointing at a colour token),
    // so references expand a few levels deep, still under the byte bound.
    for ($depth = 0; $depth < 8 && stripos($value, 'var(') !== false && strlen($value) <= PP_UDC_COLOUR_READ_MAX_BYTES; $depth++) {
        $value = (string) preg_replace_callback('/var\(\s*(--[a-z0-9-]+)\s*\)/i', static function (array $m): string {
            $site = pp_design_tokens();
            return isset($site[$m[1]]['value']) ? substr((string) $site[$m[1]]['value'], 0, PP_UDC_COLOUR_READ_MAX_BYTES + 1) : $m[0];
        }, $value);
    }
    if (strlen($value) > PP_UDC_COLOUR_READ_MAX_BYTES || str_contains($value, '@') || stripos($value, 'var(') !== false) {
        return [];
    }
    // Only colour and gradient functions are read; any other function changes what the
    // colours inside it mean (color-mix(white 50%, black) is grey), so the value is unread.
    preg_match_all('/([a-z-]+)\(/i', $value, $functions);
    $readable = ['rgb', 'rgba', 'hsl', 'hsla', 'linear-gradient', 'radial-gradient', 'conic-gradient',
        'repeating-linear-gradient', 'repeating-radial-gradient', 'repeating-conic-gradient'];
    if (array_diff(array_map('strtolower', $functions[1]), $readable) !== []) {
        return [];
    }
    // Every colour-bearing part must be read, or the value is unread: judging a gradient by
    // the stops that happened to parse would call `linear-gradient(#000, ivory)` dark.
    $leftover = (string) preg_replace('/#[0-9a-f]{3,8}\b|(?:rgba?|hsla?)\([^)]*\)|(?<![\w.-])(?:white|black|transparent)(?![\w.-])/i', ' ', $value);
    // Numbers and units come from the write gate's own dimension grammar (lib/apply.php),
    // so an angle or length the gate accepts is never mistaken for an unread stop.
    $units    = array_merge(pp_css_length_units(), ['%', 'deg', 'grad', 'rad', 'turn']); // letters and % only: no escaping needed
    $number   = '-?' . _pp_css_number_body() . '(?:' . implode('|', $units) . ')?';
    $leftover = (string) preg_replace('/(?:repeating-)?(?:linear|radial|conic)-gradient\(|' . $number
        . '|\b(?:to|at|from|in|left|right|top|bottom|center|circle|ellipse|closest-side|closest-corner|farthest-side|farthest-corner)\b|[\s,()\/]/i', '', $leftover);
    if ($leftover !== '') {
        return [];
    }
    $colours = [];
    $alpha_of = static fn (?string $a): float => $a === null ? 1.0 : (str_ends_with($a, '%') ? (float) $a / 100 : (float) $a);
    if (preg_match_all('/#([0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{4}|[0-9a-f]{3})\b|rgba?\(([^)]*)\)|hsla?\(([^)]*)\)|(?<![\w.-])(white|black|transparent)(?![\w.-])/i', $value, $found, PREG_SET_ORDER)) {
        foreach ($found as $m) {
            if (($m[1] ?? '') !== '') {
                $hex = strtolower($m[1]);
                if (strlen($hex) <= 4) {
                    $hex = implode('', array_map(static fn ($c) => $c . $c, str_split($hex)));
                }
                $colours[] = [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)),
                    strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0];
            } elseif (($m[2] ?? '') !== '') {
                $parts = preg_split('/[\s,\/]+/', trim($m[2]));
                // rgb(0%, 0%, 0%) is the gate-accepted percentage form of the same bytes.
                for ($c = 0; $c < 3 && $c < count($parts); $c++) {
                    if (str_ends_with($parts[$c], '%') && is_numeric(substr($parts[$c], 0, -1))) {
                        $parts[$c] = (string) round((float) substr($parts[$c], 0, -1) * 2.55);
                    }
                }
                if (!(count($parts) >= 3 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2]))) {
                    return []; // rgb(var(--x) ...), a missing channel: a stop this reader cannot place
                }
                $colours[] = [(int) $parts[0], (int) $parts[1], (int) $parts[2], $alpha_of($parts[3] ?? null)];
            } elseif (($m[3] ?? '') !== '') {
                $parts = preg_split('/[\s,\/]+/', trim($m[3]));
                $h = isset($parts[0]) ? rtrim(strtolower($parts[0]), 'deg') : '';
                $sat = isset($parts[1]) ? rtrim($parts[1], '%') : '';
                $lig = isset($parts[2]) ? rtrim($parts[2], '%') : '';
                if (!(is_numeric($h) && is_numeric($sat) && is_numeric($lig))) {
                    return []; // a turn/rad hue, a missing channel: unread, never a guess
                }
                [$r, $g, $b] = _pp_udc_hsl_to_rgb((float) $h, (float) $sat / 100, (float) $lig / 100);
                $colours[] = [$r, $g, $b, $alpha_of($parts[3] ?? null)];
            } else {
                $word = strtolower($m[4]);
                $colours[] = $word === 'white' ? [255, 255, 255, 1.0] : ($word === 'black' ? [0, 0, 0, 1.0] : [0, 0, 0, 0.0]);
            }
        }
    }
    return $colours;
}

/** Longest value the colour reader reads (#1010 review); no real colour or scrim is longer. */
const PP_UDC_COLOUR_READ_MAX_BYTES = 512;

/** Below this alpha a scrim or surface colour is a wash over the image, not a colour of its own (#1010 review). */
const PP_UDC_SCRIM_MIN_ALPHA = 0.3;

/** HSL (hue in degrees, saturation and lightness 0..1) to sRGB bytes. */
function _pp_udc_hsl_to_rgb(float $h, float $s, float $l): array {
    $h = fmod(fmod($h, 360) + 360, 360) / 360;
    $s = max(0.0, min(1.0, $s));
    $l = max(0.0, min(1.0, $l));
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $channel = static function (float $t) use ($p, $q): int {
        $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
        $v = $t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < 1 / 2 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p));
        return (int) round($v * 255);
    };
    return [$channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3)];
}

/**
 * The background the band itself paints at a width, read off the compiled band (PR-2 review, design ruling A): the
 * resting `_band` block's `background-color` or `background` at that width, else at the desktop width (the tablet
 * and phone tiers are disjoint and each inherits only from the base). Band tokens are put back. Null when the band
 * declares none there: the component's own background shows, which the engine does not read.
 */
function _pp_udc_band_own_background(array $band_compiled, string $bp): ?string {
    $found = [];
    foreach ((array) ($band_compiled['blocks'] ?? []) as $block) {
        if (($block['role'] ?? '') !== '_band' || ($block['item'] ?? '') !== '' || ($block['state'] ?? '') !== '') {
            continue;
        }
        foreach (['background-color', 'background'] as $property) {
            $css = $block['decls'][$property]['css'] ?? null;
            if (is_string($css) && !isset($found[(string) ($block['bp'] ?? '')])) {
                $found[(string) ($block['bp'] ?? '')] = _pp_udc_compiled_value($css, $band_compiled);
            }
        }
    }
    return $found[$bp] ?? $found['d'] ?? null;
}

/**
 * Whether a value paints a LIGHT surface (#1010 review): true, false, or null when the
 * engine cannot read it. Light means the theme's near-white on-overlay ink would fall
 * under 3:1 on it: relative luminance above 0.2867, at an alpha of at least 0.3 (a thinner
 * wash is the image, not the colour). A classification, not a contrast measurement.
 *
 * FALSE MEANS "NO LIGHT COLOUR FOUND", NOT "DARK". `transparent`, a zero-alpha colour and a thin wash all
 * answer false, yet they paint nothing readable. A caller that SILENCES something on a dark answer must pair
 * this with the PP_UDC_SCRIM_MIN_ALPHA floor (no colour at or over it = unreadable, which also covers every
 * value _pp_udc_paints_surface() calls no surface), as the off-scrim gate in pp_udc_composition_findings()
 * does (`$accent_may_not_read`, red team RT1).
 */
function _pp_udc_value_is_light(string $value, array $band_tokens): ?bool {
    $colours = _pp_udc_value_colours($value, $band_tokens);
    if ($colours === []) {
        return null;
    }
    foreach ($colours as [$r, $g, $b, $alpha]) {
        $lin = static function (int $c): float {
            $c = max(0, min(255, $c)) / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        if ($alpha >= PP_UDC_SCRIM_MIN_ALPHA && (0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b)) > 0.2867) {
            return true;
        }
    }
    return false;
}
