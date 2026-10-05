<?php
/**
 * lib/css-layer3.php — the CSS value rules every Layer-3 CSS channel shares (#1242).
 *
 * Layer 3 has two CSS channels: the scoped sheet (3B, `udc._scoped`, lib/udc.php) and a
 * content `style` attribute (3A, Δ3). Both run Layer 2's security gates and then the same
 * Layer-3 additions: CSS functions DEFAULT-DENY, the fragment-only url() of P-13, the
 * text-bearing string rule, counter values that take names only (each once), no control
 * byte in a literal, and `content`'s closed grammar (§6.4, M-7). ONE OWNER for those
 * additions, so the two channels cannot drift apart; this file is that owner.
 *
 * Its own file rather than lib/udc.php because the engine there is component-agnostic and
 * pinned so (UdcEngineTest: it names no component or role), and these tables are CSS
 * vocabulary — `quotes`, `open-quote` — that only reads like a role name to a substring scan.
 *
 * Loaded by lib/udc.php.
 */

/**
 * The CSS functions a scoped value may call: DEFAULT-DENY (#1242's ruling for every Layer-3
 * CSS channel). The url() family is absent on purpose: a same-document `url(#id)` is
 * admitted by its own rule (P-13), and every other url(), image(), image-set(), src(),
 * element(), -moz-element(), paint(), attr() and symbols() is refused, as is any function
 * this list does not name, because an unknown function is an unreviewed way to fetch,
 * read or print something.
 *
 * ONE OWNER FOR LAYER 3. The content `style` gate (3A, lib/content.php) reads this function
 * through pp_content_css_functions(); the two channels cannot admit different functions
 * (pinned by UdcScopedSheetTest). Unified when T4 merged second after T3a (#1242).
 *
 * `counter()`/`counters()` are not here: they exist only inside `content`'s own closed
 * grammar (§6.4, M-7).
 *
 * @return array<string, true>
 */
function pp_layer3_css_functions(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = array_fill_keys([
        // colour
        'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color', 'color-mix', 'light-dark',
        // maths and custom properties
        'calc', 'min', 'max', 'clamp', 'round', 'mod', 'rem', 'abs', 'sign', 'sin', 'cos', 'tan', 'asin', 'acos',
        'atan', 'atan2', 'pow', 'sqrt', 'hypot', 'log', 'exp', 'var', 'env',
        // transforms
        'translate', 'translatex', 'translatey', 'translatez', 'translate3d', 'scale', 'scalex', 'scaley', 'scalez',
        'scale3d', 'rotate', 'rotatex', 'rotatey', 'rotatez', 'rotate3d', 'skew', 'skewx', 'skewy', 'matrix',
        'matrix3d', 'perspective',
        // gradients
        'linear-gradient', 'radial-gradient', 'conic-gradient', 'repeating-linear-gradient',
        'repeating-radial-gradient', 'repeating-conic-gradient',
        // filters
        'blur', 'brightness', 'contrast', 'drop-shadow', 'grayscale', 'hue-rotate', 'invert', 'opacity', 'saturate', 'sepia',
        // shapes, grid, timing
        'circle', 'ellipse', 'inset', 'polygon', 'rect', 'xywh', 'repeat', 'minmax', 'fit-content',
        'cubic-bezier', 'steps', 'linear',
        // path(): pure geometry data (clip-path, offset-path), references nothing (ruled 2026-10-05, T3a).
        'path',
        // font-variant-alternates: name font-internal features, fetch nothing (ruled 2026-10-05, T3a).
        'stylistic', 'styleset', 'character-variant', 'swash', 'ornaments', 'annotation',
        // NOT admitted, by ruling (2026-10-05): attr() (reads attributes into CSS, a text-to-style
        // channel) and the anchor-positioning family (anchor(), anchor-size(): cross-element
        // positioning, refused until ruled); and every function this list does not name.
    ], true);
}

/**
 * The properties that render a quoted string as TEXT (Δ3, applied to every Layer-3 CSS
 * channel): a string here is author text on the page outside 3A's checks, which is why
 * `content` is fenced. Their keyword forms stay admitted.
 *
 * @return string[]
 */
function pp_layer3_text_bearing_properties(): array {
    return ['quotes', 'list-style-type', 'list-style', 'text-emphasis-style', 'text-emphasis',
        'hyphenate-character', 'text-overflow'];
}

/** P-13's fragment-only url(): Δ1's regex, matched anywhere in a value and replaced for the gates. */
const PP_LAYER3_FRAGMENT_URL = '/url\(\s*(["\']?)#[A-Za-z_][A-Za-z0-9_.-]{0,63}\1\s*\)/i';

/**
 * The properties on which a fragment url() REFERENCES AN ELEMENT, and so names nothing outside
 * the document (P-13's premise). The only properties a fragment url() is admitted on.
 *
 * EVIDENCE-CONFORMANCE, NOT REVERSAL (#1242 T4, Q6, ruled 2026-10-05). P-13 was ratified on
 * "a fragment names nothing outside the document and fetches nothing". That holds where the
 * url() points at an element (a gradient, a clip path, a mask, a marker). It fails where the
 * url() is a PAINT IMAGE: the fragment resolves against the document URL, and the browser
 * requests the page again. Measured in headless Chromium against a local server, one inline
 * style element (requests for the page URL; the control is 1):
 *
 *   background-image: url(#a)                          2  (the page is fetched again, query included)
 *   cursor: url(#b), auto                              2
 *   list-style: url(#e)                                2
 *   --x: url(#f) read by background-image: var(--x)   2
 *   background-image: url(#a) under :hover             2
 *   mask: url(#m) / border-image-source: url(#m)       1  (mask references an element)
 *   color: red (control)                               1
 *
 * So a paint-image property (background-image, cursor, list-style, list-style-image,
 * border-image, shape-outside, content, -webkit-box-reflect, ...) refuses a fragment url(),
 * and so does a custom property, which can carry the value into any of them through var().
 *
 * AN EXPLICIT LIST, NOT A FAMILY (#1242 T4, cycle-4 ruling). A `mask-*` prefix admitted
 * `-webkit-mask-box-image` and `-webkit-mask-box-image-source`, which are paint images and
 * re-fetched the page (2 requests against a control of 1), and the standard `mask-border*`
 * names are the same class. So only these exact names admit a fragment url(); every other
 * spelling, prefixed or not, refuses it. Cross-browser verification candidate: Chromium
 * measured `mask-image: url(#missing)` at 1 request; Firefox and WebKit were not measured.
 *
 * @return string[] exact property names
 */
function pp_layer3_fragment_url_properties(): array {
    return ['filter', 'clip-path', 'fill', 'stroke', 'marker', 'marker-start', 'marker-mid', 'marker-end',
        'mask', 'mask-image', '-webkit-mask', '-webkit-mask-image'];
}

/** Whether a fragment url() is admitted on this exact property name (Q6). */
function pp_layer3_fragment_url_admitted(string $property): bool {
    return in_array($property, pp_layer3_fragment_url_properties(), true);
}

/** Counter values: names only (no integer, function or var()), the shape the names-only rule admits. */
const PP_LAYER3_COUNTER_NAMES = '/\A *-?[A-Za-z_][A-Za-z0-9_-]*( +-?[A-Za-z_][A-Za-z0-9_-]*)* *\z/';

/**
 * Whether a `counter-reset`/`counter-set`/`counter-increment` value is names only, each name once
 * (#1242 T4, Q2 and its cycle-2 refinement). ONE OWNER for both questions: what a scoped value
 * may be, and whether a band's Layer-2 `_css` opens the counter channel (cycle-6 ruling).
 */
function pp_layer3_counter_value_is_names_only(string $literal): bool {
    if (!preg_match(PP_LAYER3_COUNTER_NAMES, $literal)) {
        return false;
    }
    $names = preg_split('/ +/', trim($literal, ' '));
    return count($names) === count(array_unique($names));
}

/**
 * `content` in a scoped rule (§6.4, M-7): the forms that create a box without putting
 * author TEXT on the page: `""`, `none`, `normal`. The glyph sub-question of M-7 is routed, not ruled, so any
 * non-empty string is refused (fail-closed) until it is.
 */
function pp_layer3_content_problem(string $value): ?string {
    $v = trim($value);
    if (in_array($v, ['""', "''", 'none', 'normal'], true)) {
        return null;
    }
    // THE QUOTE KEYWORDS ARE REFUSED (#1242 T4, Q5, ruled 2026-10-05): fail-closed, joining
    // M-7's open set. `open-quote` prints whatever `quotes` says, and Layer 2's `_css` still
    // takes a string `quotes` on the same band, so the pair is M-7's withdrawn text channel in
    // other syntax.
    if (in_array(strtolower($v), ['open-quote', 'close-quote', 'no-open-quote', 'no-close-quote'], true)) {
        return sprintf('content may not be %s: a quote keyword prints whatever "quotes" says, and the band\'s own '
            . '"_css" can set that to any text (M-7, still open)', $v);
    }
    // COUNTERS ARE REFUSED (#1242 T4, final-pass ruling, 2026-10-05): a named fail-closed
    // deviation from M-7's decimal-counter admission, BOUND TO #1254. Counters are not band-
    // scoped: an integer `counter-reset` in an EARLIER band's Layer-2 `_css`, or in the site
    // header's, reaches this band, and `content: counter(x)` printed it ("5551234", "8675309" in
    // Chromium). Layer 2 still takes integer counters (#1168/#1254); `counter()` and `counters()`
    // come back when that path closes, with whatever confinement #1254's fix designs.
    if (preg_match('/\Acounters?\(/i', $v)) {
        return 'content may not use counter() or counters() yet: a counter can carry a number set in another band or '
            . 'in the site header onto this one (M-7; bound to #1254)';
    }
    return 'content takes only "" (an empty string), none or normal: any text belongs in the band\'s content, '
        . 'where it is checked';
}

/**
 * The Layer-3 additions to Layer 2's value gate for one literal, or null (§6.3, §6.7): the
 * CSS-function default-deny (which is also what refuses attr() everywhere), the text-bearing
 * string rule, (routed item 5 still OPEN, so fail-closed) no var() in a text-bearing property,
 * counter values that take names only, each once (#1242 Q2), and no control byte in the
 * literal. Returns the literal with P-13's fragment url()s replaced, so the caller can hand
 * THAT to Layer 2's security gates, which refuse every `url(`.
 *
 * THE LITERAL IS ALSO JUDGED, NOT ONLY THE PROBE. The probe is what Layer 2's gates read, and
 * the literal is what reaches the style element, so a byte the replacement removes would
 * reach the page unexamined. Control bytes are the case that matters: a newline written
 * inside `url(#a\n)` vanished with the url() from the probe, and in the page it ended a CSS
 * string early, opening one that ran to the end of the style element and swallowed every
 * later rule on the page (found by the pre-landing security pass).
 *
 * Quoted strings are skipped when looking for function calls: `"Plan (pro)"` calls nothing.
 *
 * @return array{0: ?string, 1: string} [problem, probe]
 */
function pp_layer3_value_problem(string $property, string $literal): array {
    if (preg_match('/[\x00-\x1F\x7F]/', $literal)) {
        return [sprintf('"%s" contains a control character (a tab, a line break or another byte below space)', $property), $literal];
    }
    // P-13's fragment url() only where it references an element (Q6); everywhere else the url(
    // stays in the probe and is refused below with every other url().
    $probe = pp_layer3_fragment_url_admitted($property)
        ? (string) preg_replace(PP_LAYER3_FRAGMENT_URL, 'none', $literal)
        : $literal;
    $unquoted = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $probe);
    if (preg_match_all('/([A-Za-z_-][A-Za-z0-9_-]*)\s*\(/', $unquoted, $calls)) {
        foreach ($calls[1] as $function) {
            $lower = strtolower($function);
            if ($lower === 'url') {
                return [sprintf('"%s" calls url(): only a same-document url(#id), and only on a property that '
                    . 'references an element (filter, clip-path, mask, marker, fill, stroke); a background image is an '
                    . 'attachment id ("background-image": 42), never a URL', $property), $probe];
            }
            if (!isset(pp_layer3_css_functions()[$lower])) {
                return [sprintf('"%s" calls %s(), which is not on the admitted CSS function list '
                    . '(unknown functions are refused)', $property, _pp_udc_reflect($lower)), $probe];
            }
        }
    }
    $base = preg_replace('/\A-[a-z]+-/', '', $property);
    // COUNTER VALUES TAKE NAMES ONLY (#1242 Q2, ruled; M-7's own reasoning). An explicit
    // integer is author text in disguise: `counter-set: list-item 8233101` under
    // `list-style-type: upper-alpha` spells a word in the list marker, and `counter-reset: x
    // 5551234` with `content: counter(x)` prints any number. A counter NAME alone keeps
    // ordinary numbering (reset to 0, increment by 1). Anything but names (an integer, a
    // function, var()) is refused.
    if (in_array($base, ['counter-reset', 'counter-set', 'counter-increment'], true)
        && !preg_match(PP_LAYER3_COUNTER_NAMES, $literal)) {
        return [sprintf('"%s" takes counter names only (for example "item" or "item other"): an explicit '
            . 'number can spell text through a list marker or counter(), and text belongs in the band\'s content', $property), $probe];
    }
    // ONCE EACH: a repeated name is applied once per occurrence, so `counter-increment: n n n n
    // n n n` adds 7 and spells "G" under an alphabetic marker, the same channel (cycle-2
    // security pass). Residual, disclosed: +1 per matched element, chosen by `:nth-child()`
    // over a band with many elements, can still build a count.
    if (in_array($base, ['counter-reset', 'counter-set', 'counter-increment'], true)) {
        if (!pp_layer3_counter_value_is_names_only($literal)) {
            return [sprintf('"%s" names each counter once: a repeated name is applied once per occurrence, '
                . 'which turns it back into a number', $property), $probe];
        }
    }
    if (in_array($base, pp_layer3_text_bearing_properties(), true)) {
        // NO CSS-WIDE KEYWORD (#1242 T4, cycle-6 ruling): `inherit`, `unset` and the rest pull a
        // value set elsewhere (a Layer-2 `text-overflow: var(--pp-tok)` holding a string) onto
        // any element. Chromium ignores a string `text-overflow` today; that is not a guarantee.
        if (preg_match('/\A *(inherit|initial|unset|revert|revert-layer) *\z/i', $probe)) {
            return [sprintf('"%s" may not take a CSS-wide keyword: it would pull a value set elsewhere, possibly text, '
                . 'onto this element; use one of its own keywords', $property), $probe];
        }
        if (preg_match('/["\']/', $probe)) {
            return [sprintf('"%s" with a quoted string renders that string as text on the page; use a keyword '
                . '(text belongs in the band\'s content)', $property), $probe];
        }
        if (preg_match('/\bvar\s*\(/i', $probe)) {
            return [sprintf('"%s" may not read a custom property through var(): the value could carry text '
                . 'onto the page (routed item 5, still open)', $property), $probe];
        }
    }
    return [null, $probe];
}
